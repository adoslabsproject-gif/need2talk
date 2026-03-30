<?php

declare(strict_types=1);

namespace Need2Talk\Services\Chat;

use Need2Talk\Contracts\Redis\RedisAdapterInterface;
use Need2Talk\Contracts\Publisher\EventPublisherInterface;
use Need2Talk\Services\Logger;

/**
 * PresenceService - Gestisce presenza e typing indicators
 *
 * Features:
 * - Online status (online/away/dnd/invisible)
 * - Typing indicators (Redis TTL 3s)
 * - Last seen tracking
 * - Room-specific presence
 *
 * ENTERPRISE DI: This service uses constructor injection for Redis and Publisher.
 * Use ChatServiceFactory::createPresenceService() to instantiate.
 *
 * @package Need2Talk\Services\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class PresenceService
{
    /**
     * Redis key prefixes
     */
    private const PREFIX_PRESENCE = 'chat:presence:';
    private const PREFIX_PREFERRED_STATUS = 'chat:preferred_status:';  // ENTERPRISE V10.1: Persists user's chosen status
    private const PREFIX_TYPING_ROOM = 'chat:typing:room:';
    private const PREFIX_TYPING_DM = 'chat:typing:dm:';
    private const PREFIX_LAST_SEEN = 'chat:lastseen:';
    private const PREFIX_DND_NOTIFIED = 'chat:dnd_notified:';  // ENTERPRISE V10.4: Tracks who already notified a DND user

    /**
     * TTL Configuration
     */
    private const TYPING_TTL_SECONDS = 3;      // Auto-expire typing after 3s
    private const PRESENCE_TTL_SECONDS = 300;  // 5 min presence TTL (refreshed on heartbeat)
    private const LAST_SEEN_TTL_SECONDS = 86400; // 24h last seen
    private const PREFERRED_STATUS_TTL_SECONDS = 86400; // 24h - user's chosen status persists across reconnects
    // ENTERPRISE V10.147: DND_NOTIFICATION_COOLDOWN_SECONDS no longer used
    // DND blocks persist until user leaves DND mode (no TTL)

    /**
     * Status types
     */
    public const STATUS_ONLINE = 'online';
    public const STATUS_AWAY = 'away';
    public const STATUS_DND = 'dnd';  // Do Not Disturb
    public const STATUS_INVISIBLE = 'invisible';
    public const STATUS_OFFLINE = 'offline';

    private RedisAdapterInterface $redis;
    private EventPublisherInterface $publisher;

    /**
     * Constructor with dependency injection
     *
     * @param RedisAdapterInterface $redis Redis adapter (context-aware)
     * @param EventPublisherInterface $publisher Event publisher for real-time updates
     */
    public function __construct(
        RedisAdapterInterface $redis,
        EventPublisherInterface $publisher
    ) {
        $this->redis = $redis;
        $this->publisher = $publisher;
    }

    /**
     * Set user's online status
     *
     * @param string $userUuid
     * @param string $status One of STATUS_* constants
     * @param array $meta Additional metadata (device, location, etc.)
     * @return bool
     */
    public function setStatus(string $userUuid, string $status = self::STATUS_ONLINE, array $meta = []): bool
    {
        try {
            $presenceKey = self::PREFIX_PRESENCE . $userUuid;
            $preferredKey = self::PREFIX_PREFERRED_STATUS . $userUuid;
            $lastSeenKey = self::PREFIX_LAST_SEEN . $userUuid;
            $now = time();

            // ENTERPRISE V10.4: Check if user is leaving DND mode
            // If so, clear the DND notification list (people who tried to contact them)
            $previousStatus = $this->redis->get($preferredKey, 'chat');
            if ($previousStatus === self::STATUS_DND && $status !== self::STATUS_DND) {
                $clearedCount = $this->clearDndNotifications($userUuid);
                if ($clearedCount > 0) {
                    Logger::info('User left DND mode, cleared pending notifications', [
                        'user_uuid' => $userUuid,
                        'senders_cleared' => $clearedCount,
                        'new_status' => $status,
                    ]);
                }
            }

            $presenceData = [
                'status' => $status,
                'updated_at' => $now,
                'device' => $meta['device'] ?? 'web',
            ];

            // Store presence as JSON (connection state)
            $this->redis->setex($presenceKey, self::PRESENCE_TTL_SECONDS, json_encode($presenceData), 'chat');

            // ENTERPRISE V10.1: Store user's CHOSEN status separately
            // This persists across page changes/reconnects (24h TTL)
            $this->redis->setex($preferredKey, self::PREFERRED_STATUS_TTL_SECONDS, $status, 'chat');

            // Update last seen (even for invisible users)
            $this->redis->setex($lastSeenKey, self::LAST_SEEN_TTL_SECONDS, (string) $now, 'chat');

            // Broadcast presence update to followers (except for invisible)
            if ($status !== self::STATUS_INVISIBLE) {
                $this->publisher->publishPresenceUpdate($userUuid, $status, $presenceData);
            }

            return true;

        } catch (\Exception $e) {
            Logger::warning('Failed to set presence status', [
                'user_uuid' => $userUuid,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Activate presence on WebSocket connect (ENTERPRISE V10.1)
     *
     * This method should be called when a user connects to WebSocket.
     * Unlike setStatus(), it PRESERVES the user's existing status (busy, dnd, away)
     * instead of forcing "online". Only sets "online" if user had no prior status.
     *
     * ENTERPRISE V10.1 ARCHITECTURE:
     * Uses dual-key system like Slack/Discord/Teams:
     * 1. PREFIX_PRESENCE = connection state (short TTL, deleted on disconnect)
     * 2. PREFIX_PREFERRED_STATUS = user's choice (long TTL, persists across reconnects)
     *
     * Flow on page change:
     * 1. Old page disconnects → PREFIX_PRESENCE deleted, PREFIX_PREFERRED_STATUS preserved
     * 2. New page connects → reads PREFIX_PREFERRED_STATUS → restores user's status
     *
     * Use cases:
     * - User refreshes page while "busy" → stays "busy" (not reset to online)
     * - User opens new tab while "dnd" → stays "dnd"
     * - User returns after being offline → becomes "online" (or their last preferred status)
     *
     * @param string $userUuid
     * @param array $meta Additional metadata (device, etc.)
     * @return bool
     */
    public function activatePresence(string $userUuid, array $meta = []): bool
    {
        try {
            $presenceKey = self::PREFIX_PRESENCE . $userUuid;
            $preferredKey = self::PREFIX_PREFERRED_STATUS . $userUuid;
            $lastSeenKey = self::PREFIX_LAST_SEEN . $userUuid;
            $now = time();

            // ENTERPRISE V10.149: Always start as ONLINE when connecting
            // Previous "preferred_status" logic removed because:
            // 1. "Occupato" only makes sense when actively connected
            // 2. Reconnecting means user is available (at least momentarily)
            // 3. If user wants DND, they set it manually after connecting
            // 4. Prevents confusing states where user reconnects but is blocked
            $currentStatus = self::STATUS_ONLINE;

            $presenceData = [
                'status' => $currentStatus,
                'updated_at' => $now,
                'device' => $meta['device'] ?? 'web',
            ];

            // Store presence with TTL (connection state)
            $this->redis->setex($presenceKey, self::PRESENCE_TTL_SECONDS, json_encode($presenceData), 'chat');

            // Update preferred_status to match (clear any old DND state)
            $this->redis->setex($preferredKey, self::PREFERRED_STATUS_TTL_SECONDS, $currentStatus, 'chat');

            // Update last seen
            $this->redis->setex($lastSeenKey, self::LAST_SEEN_TTL_SECONDS, (string) $now, 'chat');

            // Broadcast presence update
            $this->publisher->publishPresenceUpdate($userUuid, $currentStatus, $presenceData);

            return true;

        } catch (\Exception $e) {
            Logger::warning('Failed to activate presence', [
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get user's current status
     *
     * ENTERPRISE V10.5: Dual-key lookup for correct status during page transitions
     *
     * KEY ARCHITECTURE:
     * - PREFIX_PRESENCE: Connection state (5min TTL, deleted on disconnect)
     * - PREFIX_PREFERRED_STATUS: User's chosen status (24h TTL, persists across pages)
     *
     * LOOKUP ORDER:
     * 1. If presence key exists → user is actively connected, use presence status
     * 2. If only preferred_status exists → user is between page loads, use preferred status
     * 3. If neither exists → user is truly offline
     *
     * This fixes the bug where status reset to "online" during page navigation
     * because setOffline() deleted presence but not preferred_status.
     *
     * @param string $userUuid
     * @return array ['status' => string, 'updated_at' => int, 'last_seen' => int]
     */
    public function getStatus(string $userUuid): array
    {
        $defaultStatus = [
            'status' => self::STATUS_OFFLINE,
            'updated_at' => null,
            'last_seen' => null,
            'is_online' => false,
        ];

        try {
            $presenceKey = self::PREFIX_PRESENCE . $userUuid;
            $preferredKey = self::PREFIX_PREFERRED_STATUS . $userUuid;
            $lastSeenKey = self::PREFIX_LAST_SEEN . $userUuid;

            $presenceJson = $this->redis->get($presenceKey, 'chat');
            $preferredStatus = $this->redis->get($preferredKey, 'chat');
            $lastSeen = $this->redis->get($lastSeenKey, 'chat');

            // Case 1: User has active presence (connected to WebSocket or recent heartbeat)
            if ($presenceJson) {
                $presence = json_decode($presenceJson, true);
                return [
                    'status' => $presence['status'] ?? self::STATUS_ONLINE,
                    'updated_at' => $presence['updated_at'] ?? null,
                    'last_seen' => $lastSeen ? (int) $lastSeen : null,
                    'is_online' => true,
                    'device' => $presence['device'] ?? 'web',
                ];
            }

            // Case 2: No presence = user is OFFLINE
            // ENTERPRISE V10.148: When user has no active presence, they are OFFLINE
            // The preferred_status is preserved in Redis but NOT returned to other users
            // It will be restored when the user reconnects (via activatePresence)
            //
            // This fixes the bug where "Occupato" users appeared busy even after
            // closing the browser - now they correctly show as "offline"
            //
            // Note: preferred_status still exists in Redis for reconnection purposes
            return [
                'status' => self::STATUS_OFFLINE,
                'updated_at' => null,
                'last_seen' => $lastSeen ? (int) $lastSeen : null,
                'is_online' => false,
            ];

        } catch (\Exception $e) {
            Logger::warning('Failed to get presence status', [
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return $defaultStatus;
        }
    }

    /**
     * Batch get statuses for multiple users
     *
     * @param array $userUuids
     * @return array [uuid => status_data]
     */
    public function getStatuses(array $userUuids): array
    {
        $result = [];

        foreach ($userUuids as $uuid) {
            $result[$uuid] = $this->getStatus($uuid);
        }

        return $result;
    }

    /**
     * Set typing indicator for a room
     *
     * @param string $roomId Room ID (e.g., "emotion:joy" or room UUID)
     * @param string $userUuid
     * @return bool
     */
    public function setTypingRoom(string $roomId, string $userUuid): bool
    {
        try {
            $typingKey = self::PREFIX_TYPING_ROOM . $roomId . ':' . $userUuid;

            // Set with 3 second TTL (auto-expires)
            $this->redis->setex($typingKey, self::TYPING_TTL_SECONDS, '1', 'chat');

            // Publish typing event to room
            $this->publisher->publishTypingRoom($roomId, $userUuid, true);

            return true;

        } catch (\Exception $e) {
            Logger::warning('Failed to set typing indicator (room)', [
                'room_id' => $roomId,
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Clear typing indicator for a room
     *
     * @param string $roomId
     * @param string $userUuid
     * @return bool
     */
    public function clearTypingRoom(string $roomId, string $userUuid): bool
    {
        try {
            $typingKey = self::PREFIX_TYPING_ROOM . $roomId . ':' . $userUuid;
            $this->redis->del($typingKey, 'chat');

            // Publish stop typing event
            $this->publisher->publishTypingRoom($roomId, $userUuid, false);

            return true;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Set typing indicator for a DM conversation
     *
     * @param string $conversationUuid
     * @param string $senderUuid The user who is typing
     * @param string $recipientUuid The user who should receive the indicator
     * @return bool
     */
    public function setTypingDM(string $conversationUuid, string $senderUuid, string $recipientUuid): bool
    {
        try {
            $typingKey = self::PREFIX_TYPING_DM . $conversationUuid . ':' . $senderUuid;

            // Set with 3 second TTL (auto-expires)
            $this->redis->setex($typingKey, self::TYPING_TTL_SECONDS, '1', 'chat');

            // Publish typing event to recipient
            $this->publisher->publishTypingDM($conversationUuid, $senderUuid, $recipientUuid, true);

            return true;

        } catch (\Exception $e) {
            Logger::warning('Failed to set typing indicator (DM)', [
                'conversation_uuid' => $conversationUuid,
                'sender_uuid' => $senderUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Clear typing indicator for a DM conversation
     *
     * @param string $conversationUuid
     * @param string $senderUuid
     * @param string $recipientUuid
     * @return bool
     */
    public function clearTypingDM(string $conversationUuid, string $senderUuid, string $recipientUuid): bool
    {
        try {
            $typingKey = self::PREFIX_TYPING_DM . $conversationUuid . ':' . $senderUuid;
            $this->redis->del($typingKey, 'chat');

            // Publish stop typing event to recipient
            $this->publisher->publishTypingDM($conversationUuid, $senderUuid, $recipientUuid, false);

            return true;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all users currently typing in a room
     *
     * @param string $roomId
     * @return array List of user UUIDs
     */
    public function getTypingUsersInRoom(string $roomId): array
    {
        try {
            // SCAN for typing keys (avoid KEYS in production)
            $pattern = self::PREFIX_TYPING_ROOM . $roomId . ':*';
            $keys = $this->redis->scan($pattern, 'chat', 100);

            $typingUsers = [];
            foreach ($keys as $key) {
                // Extract UUID from key: chat:typing:room:{roomId}:{uuid}
                $parts = explode(':', $key);
                $uuid = end($parts);
                if ($uuid) {
                    $typingUsers[] = $uuid;
                }
            }

            return array_unique($typingUsers);

        } catch (\Exception $e) {
            Logger::warning('Failed to get typing users', [
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if a specific user is typing in a room
     *
     * @param string $roomId
     * @param string $userUuid
     * @return bool
     */
    public function isTypingInRoom(string $roomId, string $userUuid): bool
    {
        try {
            $typingKey = self::PREFIX_TYPING_ROOM . $roomId . ':' . $userUuid;
            return $this->redis->exists($typingKey, 'chat');

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a user is typing in a DM conversation
     *
     * @param string $conversationUuid
     * @param string $userUuid
     * @return bool
     */
    public function isTypingInDM(string $conversationUuid, string $userUuid): bool
    {
        try {
            $typingKey = self::PREFIX_TYPING_DM . $conversationUuid . ':' . $userUuid;
            return $this->redis->exists($typingKey, 'chat');

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Heartbeat - Refresh presence and update last seen
     *
     * @param string $userUuid
     * @param string|null $currentRoomId Room user is currently in
     * @param EmotionRoomService|null $emotionRoomService Injected service (optional)
     * @return bool
     */
    public function heartbeat(string $userUuid, ?string $currentRoomId = null, ?EmotionRoomService $emotionRoomService = null): bool
    {
        try {
            $presenceKey = self::PREFIX_PRESENCE . $userUuid;
            $preferredKey = self::PREFIX_PREFERRED_STATUS . $userUuid;
            $lastSeenKey = self::PREFIX_LAST_SEEN . $userUuid;
            $now = time();

            // ENTERPRISE V10.6: Dual-key status resolution
            // Priority: 1) Active presence, 2) Preferred status, 3) Default 'online'
            $currentPresence = $this->redis->get($presenceKey, 'chat');
            $preferredStatus = $this->redis->get($preferredKey, 'chat');
            $status = self::STATUS_ONLINE;

            if ($currentPresence) {
                // Case 1: Active presence exists - use its status
                $decoded = json_decode($currentPresence, true);
                $status = $decoded['status'] ?? self::STATUS_ONLINE;
            } elseif ($preferredStatus && in_array($preferredStatus, [
                self::STATUS_ONLINE,
                self::STATUS_AWAY,
                self::STATUS_DND,
                self::STATUS_INVISIBLE,
            ], true)) {
                // Case 2: No presence but preferred status exists (between page loads)
                // This is the key fix - preserve user's chosen status during navigation
                $status = $preferredStatus;
            }
            // Case 3: Neither exists - use default 'online' (new user)

            // Update presence with refreshed TTL
            $presenceData = [
                'status' => $status,
                'updated_at' => $now,
                'current_room' => $currentRoomId,
            ];

            $this->redis->setex($presenceKey, self::PRESENCE_TTL_SECONDS, json_encode($presenceData), 'chat');
            $this->redis->setex($lastSeenKey, self::LAST_SEEN_TTL_SECONDS, (string) $now, 'chat');

            // If in a room and emotion room service provided, refresh room presence
            if ($currentRoomId && $emotionRoomService) {
                if ($emotionRoomService->isEmotionRoom($currentRoomId)) {
                    $emotionRoomService->refreshPresence($currentRoomId, $userUuid);
                }
            }

            return true;

        } catch (\Exception $e) {
            Logger::warning('Heartbeat failed', [
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Mark user as offline (on disconnect)
     *
     * ENTERPRISE V10.1: Only deletes PREFIX_PRESENCE (connection state).
     * INTENTIONALLY preserves PREFIX_PREFERRED_STATUS so user's choice
     * (busy, dnd, away) survives page changes and reconnects.
     *
     * @param string $userUuid
     * @return bool
     */
    public function setOffline(string $userUuid): bool
    {
        try {
            $presenceKey = self::PREFIX_PRESENCE . $userUuid;
            $lastSeenKey = self::PREFIX_LAST_SEEN . $userUuid;
            // NOTE: PREFIX_PREFERRED_STATUS is intentionally NOT deleted here
            // This allows the user's chosen status to persist across reconnects

            // Remove presence (user is offline - connection state only)
            $this->redis->del($presenceKey, 'chat');

            // Update last seen
            $this->redis->setex($lastSeenKey, self::LAST_SEEN_TTL_SECONDS, (string) time(), 'chat');

            // Broadcast offline status
            $this->publisher->publishPresenceUpdate($userUuid, self::STATUS_OFFLINE, []);

            return true;

        } catch (\Exception $e) {
            Logger::warning('Failed to set offline', [
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get formatted last seen string
     *
     * @param string $userUuid
     * @return string Human readable last seen (e.g., "2 min fa", "ieri", "online")
     */
    public function getLastSeenFormatted(string $userUuid): string
    {
        $status = $this->getStatus($userUuid);

        if ($status['is_online']) {
            return 'online';
        }

        if (!$status['last_seen']) {
            return 'sconosciuto';
        }

        $diff = time() - $status['last_seen'];

        if ($diff < 60) {
            return 'poco fa';
        }

        if ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' min fa';
        }

        if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' or' . ($hours === 1 ? 'a' : 'e') . ' fa';
        }

        if ($diff < 172800) {
            return 'ieri';
        }

        $days = floor($diff / 86400);
        return $days . ' giorni fa';
    }

    // ============================================================================
    // ENTERPRISE V10.4: DND (Do Not Disturb) Notification System
    // ============================================================================
    // When a user is in DND mode and someone tries to contact them:
    // 1. Sender gets feedback "user is busy" (existing behavior)
    // 2. DND user gets ONE notification per sender (not every message)
    // 3. When DND user changes status, the notification list resets
    // ============================================================================

    /**
     * Check if sender has already notified a DND user (within cooldown period)
     *
     * ENTERPRISE V10.7: Per-sender cooldown using individual Redis keys
     * Key format: chat:dnd_notified:{dndUserUuid}:{senderUuid} with 10min TTL
     * This allows each sender to have their own independent cooldown
     *
     * @param string $dndUserUuid User who is in DND mode
     * @param string $senderUuid User trying to contact them
     * @return bool True if sender already notified within cooldown (don't send again)
     */
    public function hasDndNotificationBeenSent(string $dndUserUuid, string $senderUuid): bool
    {
        try {
            // Per-sender key with individual TTL
            $key = self::PREFIX_DND_NOTIFIED . $dndUserUuid . ':' . $senderUuid;
            $value = $this->redis->get($key, 'chat');
            return $value !== null && $value !== false;
        } catch (\Exception $e) {
            Logger::warning('Failed to check DND notification status', [
                'dnd_user' => $dndUserUuid,
                'sender' => $senderUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Mark that sender has already sent first message to DND user
     *
     * ENTERPRISE V10.147: NO TTL - Key persists until user leaves DND
     * The key is deleted ONLY when clearDndNotifications() is called
     * (which happens when user changes status from DND to anything else)
     *
     * This ensures:
     * - First message: allowed (key doesn't exist)
     * - Subsequent messages: BLOCKED (key exists) for entire DND duration
     * - When user leaves DND: all keys cleared, everyone can message again
     *
     * @param string $dndUserUuid User who is in DND mode
     * @param string $senderUuid User who tried to contact them
     * @return bool
     */
    public function markDndNotificationSent(string $dndUserUuid, string $senderUuid): bool
    {
        try {
            // Per-sender key: chat:dnd_notified:{dndUserUuid}:{senderUuid}
            $key = self::PREFIX_DND_NOTIFIED . $dndUserUuid . ':' . $senderUuid;
            // NO TTL - key persists until user leaves DND mode
            // clearDndNotifications() handles deletion when status changes
            $this->redis->set($key, '1', 'chat');
            return true;
        } catch (\Exception $e) {
            Logger::warning('Failed to mark DND notification', [
                'dnd_user' => $dndUserUuid,
                'sender' => $senderUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get list of users who tried to contact a DND user (within cooldown period)
     *
     * ENTERPRISE V10.7: Uses SCAN to find all notification keys for this user
     * Note: This is O(N) scan operation, use sparingly (e.g., when leaving DND)
     *
     * @param string $dndUserUuid User who was in DND mode
     * @return array List of sender UUIDs currently in cooldown
     */
    public function getDndNotificationSenders(string $dndUserUuid): array
    {
        try {
            // Scan for all keys matching chat:dnd_notified:{dndUserUuid}:*
            $pattern = self::PREFIX_DND_NOTIFIED . $dndUserUuid . ':*';
            $keys = $this->redis->scan($pattern, 'chat');

            // Extract sender UUIDs from key names
            $senders = [];
            $prefix = self::PREFIX_DND_NOTIFIED . $dndUserUuid . ':';
            foreach ($keys as $key) {
                $senderUuid = substr($key, strlen($prefix));
                if ($senderUuid) {
                    $senders[] = $senderUuid;
                }
            }
            return $senders;
        } catch (\Exception $e) {
            Logger::warning('Failed to get DND notification senders', [
                'dnd_user' => $dndUserUuid,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Clear DND notification list (called when user leaves DND mode)
     *
     * ENTERPRISE V10.7: Uses SCAN to find and delete all per-sender notification keys
     *
     * @param string $userUuid User leaving DND mode
     * @return int Number of senders that were cleared
     */
    public function clearDndNotifications(string $userUuid): int
    {
        try {
            // Scan for all keys matching chat:dnd_notified:{userUuid}:*
            $pattern = self::PREFIX_DND_NOTIFIED . $userUuid . ':*';
            $keys = $this->redis->scan($pattern, 'chat');
            $count = count($keys);

            // Delete each key individually
            foreach ($keys as $key) {
                $this->redis->del($key, 'chat');
            }

            if ($count > 0) {
                Logger::info('Cleared DND notifications', [
                    'user_uuid' => $userUuid,
                    'senders_cleared' => $count,
                ]);
            }

            return $count;
        } catch (\Exception $e) {
            Logger::warning('Failed to clear DND notifications', [
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Check if user is currently in DND status
     *
     * @param string $userUuid
     * @return bool
     */
    public function isInDndMode(string $userUuid): bool
    {
        $status = $this->getStatus($userUuid);
        return $status['status'] === self::STATUS_DND;
    }
}
