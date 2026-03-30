<?php
/**
 * WebSocket Publisher Service - Enterprise Galaxy
 *
 * Publish real-time events to WebSocket clients via Redis PubSub
 * Used throughout application to send notifications, cache invalidation signals, feed updates
 *
 * ARCHITECTURE:
 * - Static utility class (no instance needed)
 * - Redis PubSub (PUBLISH → PSUBSCRIBE) - V6.3 2025-11-30
 * - Graceful degradation (if Redis fails, app continues)
 * - Channel-based targeting (user:uuid, global, feed:followers:uuid)
 * - PubSub channel format: websocket:events:{uuid}
 * - PSR-3 logging integration
 *
 * USAGE EXAMPLES:
 *
 * // Send friend request notification to specific user
 * WebSocketPublisher::publishToUser($receiverUuid, 'friend_request_received', [
 *     'friendship_id' => 123,
 *     'from_user' => ['uuid' => $senderUuid, 'nickname' => $nickname, 'avatar_url' => $avatar]
 * ]);
 *
 * // Broadcast global announcement
 * WebSocketPublisher::publishGlobal('maintenance_mode', [
 *     'message' => 'Server maintenance in 10 minutes',
 *     'countdown' => 600
 * ]);
 *
 * // Notify followers of new audiopost
 * WebSocketPublisher::publishToFollowers($authorUuid, 'new_audiopost', [
 *     'post_id' => 456,
 *     'author' => ['uuid' => $authorUuid, 'nickname' => $nickname]
 * ]);
 *
 * PERFORMANCE:
 * - Redis RPUSH is O(1) = ~0.1ms latency
 * - Non-blocking (returns immediately)
 * - Timeout: 100ms max (fail fast if Redis down)
 *
 * SECURITY:
 * - Data validation (JSON encoding test)
 * - Payload size check (max 64KB recommended)
 * - No sensitive data in payload (use IDs, client fetches details)
 *
 * ERROR HANDLING:
 * - Graceful degradation: If Redis fails, log error + return false
 * - Application continues normally (WebSocket is "nice to have")
 * - PSR-3 logging for monitoring/debugging
 *
 * @package Need2Talk\Services
 * @author  need2talk Enterprise Team
 * @version 1.0.0
 */

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;

class WebSocketPublisher
{
    /**
     * Maximum payload size (64KB)
     * RATIONALE: WebSocket messages should be small for performance
     * Large data = client should fetch via HTTP API
     *
     * @var int
     */
    private const MAX_PAYLOAD_SIZE = 65536; // 64KB

    /**
     * Redis connection timeout (milliseconds)
     * RATIONALE: Fail fast if Redis is down (don't block application)
     *
     * @var float
     */
    private const REDIS_TIMEOUT = 0.1; // 100ms

    /**
     * Publish event to WebSocket clients via Redis queue
     *
     * Message format:
     * {
     *   "channel": "user:uuid" | "global" | "feed:followers:uuid",
     *   "event": "friend_request_received" | "new_audiopost" | ...,
     *   "data": {...},
     *   "timestamp": 1234567890.123
     * }
     *
     * PERFORMANCE: O(1) Redis RPUSH, ~0.1ms latency
     * ERROR HANDLING: Returns false if Redis fails (graceful degradation)
     *
     * @param string $channel Target channel (user:uuid, global, feed:followers:uuid)
     * @param string $event Event name (friend_request_received, new_audiopost, etc)
     * @param array $data Event payload (should be SMALL, <10KB recommended)
     * @return bool Success status (false if Redis failed, true otherwise)
     */
    public static function publish(string $channel, string $event, array $data = []): bool
    {
        try {
            // ENTERPRISE: Validate inputs
            if (empty($channel) || empty($event)) {
                Logger::websocket('warning', 'Invalid publish parameters', [
                    'channel' => $channel,
                    'event' => $event
                ]);
                return false;
            }

            // ENTERPRISE: Build message payload
            $message = [
                'channel' => $channel,
                'event' => $event,
                'data' => $data,
                'timestamp' => microtime(true)
            ];

            // ENTERPRISE: Validate JSON encoding (fail early if data is invalid)
            $messageJson = json_encode($message, JSON_THROW_ON_ERROR);

            // ENTERPRISE: Check payload size (prevent memory issues)
            $payloadSize = strlen($messageJson);
            if ($payloadSize > self::MAX_PAYLOAD_SIZE) {
                Logger::websocket('warning', 'Payload too large', [
                    'channel' => $channel,
                    'event' => $event,
                    'size_bytes' => $payloadSize,
                    'max_size_bytes' => self::MAX_PAYLOAD_SIZE
                ]);
                return false;
            }

            // ENTERPRISE: Get Redis connection from pool (WebSocket queue in L3)
            $redis = EnterpriseRedisManager::getInstance()->getConnection('L3_logging');

            // ENTERPRISE: Set short timeout (fail fast if Redis is down)
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::REDIS_TIMEOUT);

            // Select the correct Redis DB for WebSocket events (must match websocket-server.php)
            // ENTERPRISE V6.5: Use $_ENV since Dotenv loads there, not getenv()
            $redis->select((int)($_ENV['REDIS_DB'] ?? 4));

            // ENTERPRISE V6.3 (2025-11-30): Use PUBLISH for real-time PubSub delivery
            // The websocket-server.php uses psubscribe('websocket:events:*')
            // so we need to PUBLISH to 'websocket:events:{uuid}' where uuid is extracted from channel
            //
            // Channel format: "user:{uuid}" → extract UUID for PubSub channel
            $pubsubChannel = $channel;
            if (strpos($channel, 'user:') === 0) {
                // Extract UUID from "user:{uuid}"
                $uuid = substr($channel, 5);
                $pubsubChannel = "websocket:events:{$uuid}";
            } elseif (strpos($channel, 'presence:') === 0) {
                // ENTERPRISE V9.1: Presence updates → route to user's events channel
                // "presence:{uuid}" → "websocket:events:{uuid}"
                $uuid = substr($channel, 9);
                $pubsubChannel = "websocket:events:{$uuid}";
            } elseif (strpos($channel, 'conversation:') === 0) {
                // ENTERPRISE V9.1: DM conversation events → broadcast to participants
                // "conversation:{convUuid}" → "websocket:events:{convUuid}"
                $convUuid = substr($channel, 13);
                $pubsubChannel = "websocket:events:conv:{$convUuid}";
            } elseif (strpos($channel, 'room:') === 0) {
                // ENTERPRISE V9.1: Room events → broadcast to room channel
                // "room:{roomId}" → "websocket:events:room:{roomId}"
                $roomId = substr($channel, 5);
                $pubsubChannel = "websocket:events:room:{$roomId}";
            } elseif ($channel === 'global') {
                $pubsubChannel = 'websocket:events:global';
            } elseif (strpos($channel, 'feed:followers:') === 0) {
                // Keep as-is for now (requires different handling)
                $pubsubChannel = 'websocket:events:' . substr($channel, 15);
            } elseif (strpos($channel, 'post:') === 0) {
                // ENTERPRISE V10.9 (2025-12-03): Post-specific channel for counter broadcasts
                // "post:{postId}" → "websocket:events:post:{postId}"
                // WebSocket server routes to users subscribed to this post
                $pubsubChannel = 'websocket:events:' . $channel;
            }

            // ENTERPRISE: Use PUBLISH for PubSub delivery (real-time, no queue)
            $result = $redis->publish($pubsubChannel, $messageJson);

            // PUBLISH returns number of subscribers that received the message
            // 0 means no subscribers (user offline) - this is OK, not an error
            if ($result === false) {
                throw new \Exception('Redis PUBLISH failed');
            }

            // DEBUG: Log subscriber count for room_invite troubleshooting
            if ($event === 'room_invite') {
                Logger::warning('WebSocket PUBLISH room_invite', [
                    'pubsub_channel' => $pubsubChannel,
                    'subscribers_received' => $result,
                    'payload_size' => $payloadSize,
                ]);
            }

            // ENTERPRISE: Log successful publish (debug level, low verbosity)
            Logger::websocket('debug', 'Event published', [
                'channel' => $channel,
                'pubsub_channel' => $pubsubChannel,
                'event' => $event,
                'payload_size' => $payloadSize,
                'subscribers' => $result // PUBLISH returns number of subscribers
            ]);

            return true;

        } catch (\JsonException $e) {
            // CRITICAL: JSON encoding failed (invalid data structure)
            Logger::websocket('error', 'JSON encoding failed', [
                'channel' => $channel,
                'event' => $event,
                'error' => $e->getMessage(),
                'data_keys' => array_keys($data)
            ]);
            return false;

        } catch (\RedisException $e) {
            // WARNING: Redis connection/operation failed (graceful degradation)
            Logger::websocket('warning', 'Redis operation failed', [
                'channel' => $channel,
                'event' => $event,
                'error' => $e->getMessage()
            ]);
            return false;

        } catch (\Exception $e) {
            // ERROR: Unexpected error (should not happen, but handle gracefully)
            Logger::websocket('error', 'Unexpected error', [
                'channel' => $channel,
                'event' => $event,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Publish event to specific user (by UUID)
     *
     * USAGE:
     * WebSocketPublisher::publishToUser($userUuid, 'friend_request_received', [
     *     'friendship_id' => 123,
     *     'from_user' => ['uuid' => '...', 'nickname' => 'John']
     * ]);
     *
     * CHANNEL: "user:{$userUuid}"
     * DELIVERY: All connections of this user (multiple tabs/devices supported)
     *
     * @param string $userUuid Target user UUID (36 chars)
     * @param string $event Event name
     * @param array $data Event payload
     * @return bool Success status
     */
    public static function publishToUser(string $userUuid, string $event, array $data = []): bool
    {
        // ENTERPRISE: Validate UUID format (basic check)
        if (strlen($userUuid) !== 36) {
            Logger::websocket('warning', 'Invalid user UUID format', [
                'user_uuid' => $userUuid,
                'length' => strlen($userUuid)
            ]);
            return false;
        }

        return self::publish("user:{$userUuid}", $event, $data);
    }

    /**
     * Publish global broadcast (to ALL connected users)
     *
     * USAGE:
     * WebSocketPublisher::publishGlobal('maintenance_mode', [
     *     'message' => 'Server maintenance starting',
     *     'countdown' => 600
     * ]);
     *
     * CHANNEL: "global"
     * DELIVERY: Broadcast to ALL connected WebSocket clients
     *
     * USE CASES:
     * - Maintenance announcements
     * - System-wide notifications
     * - Emergency alerts
     *
     * WARNING: Use sparingly! Broadcasting to 100k users = high load
     *
     * @param string $event Event name
     * @param array $data Event payload
     * @return bool Success status
     */
    public static function publishGlobal(string $event, array $data = []): bool
    {
        // ENTERPRISE: Log global broadcast (important for auditing)
        Logger::websocket('info', 'Global broadcast', [
            'event' => $event,
            'data' => $data
        ]);

        return self::publish('global', $event, $data);
    }

    /**
     * Publish event to all followers of a user
     *
     * USAGE:
     * WebSocketPublisher::publishToFollowers($authorUuid, 'new_audiopost', [
     *     'post_id' => 456,
     *     'author' => ['uuid' => '...', 'nickname' => 'John']
     * ]);
     *
     * CHANNEL: "feed:followers:{$authorUuid}"
     * DELIVERY: All followers of author (WebSocket server queries followers)
     *
     * USE CASES:
     * - New audiopost published
     * - Profile picture updated
     * - Status message changed
     *
     * TODO: WebSocketHandler must implement followers query
     *
     * @param string $authorUuid Author user UUID
     * @param string $event Event name
     * @param array $data Event payload
     * @return bool Success status
     */
    public static function publishToFollowers(string $authorUuid, string $event, array $data = []): bool
    {
        // ENTERPRISE: Validate UUID format
        if (strlen($authorUuid) !== 36) {
            Logger::websocket('warning', 'Invalid author UUID format', [
                'author_uuid' => $authorUuid,
                'length' => strlen($authorUuid)
            ]);
            return false;
        }

        return self::publish("feed:followers:{$authorUuid}", $event, $data);
    }

    /**
     * Publish cache invalidation signal
     *
     * USAGE:
     * WebSocketPublisher::publishCacheInvalidation($userUuid, [
     *     'invalidate' => ['pending_requests', 'friend_badge']
     * ]);
     *
     * CLIENT SIDE:
     * - Client receives event
     * - Invalidates local cache (localStorage, memory)
     * - Re-fetches data if needed (only if page is active)
     *
     * PERFORMANCE:
     * - Signal-only approach (no data sent)
     * - Client decides when to fetch
     * - Avoids unnecessary database queries
     *
     * @param string $userUuid Target user UUID
     * @param array $cacheKeys Cache keys to invalidate
     * @return bool Success status
     */
    public static function publishCacheInvalidation(string $userUuid, array $cacheKeys): bool
    {
        return self::publishToUser($userUuid, 'cache_invalidation', [
            'invalidate' => $cacheKeys,
            'timestamp' => microtime(true)
        ]);
    }

    /**
     * Publish badge count update
     *
     * USAGE:
     * WebSocketPublisher::publishBadgeUpdate($userUuid, [
     *     'pending_requests' => 5,
     *     'notifications' => 12,
     *     'messages' => 3
     * ]);
     *
     * CLIENT SIDE:
     * - Updates badge counts in UI (NO fetch needed!)
     * - Real-time badge sync across tabs/devices
     *
     * PERFORMANCE:
     * - Eliminates polling for badge counts
     * - Server calculates counts once, pushes to all devices
     * - Zero database queries on client side
     *
     * @param string $userUuid Target user UUID
     * @param array $badges Badge name → count mapping
     * @return bool Success status
     */
    public static function publishBadgeUpdate(string $userUuid, array $badges): bool
    {
        return self::publishToUser($userUuid, 'badge_update', [
            'badges' => $badges,
            'timestamp' => microtime(true)
        ]);
    }

    /**
     * Get current queue length (for monitoring)
     *
     * Returns number of pending events in Redis queue
     * Useful for monitoring WebSocket server health
     *
     * @return int Queue length (0 if Redis fails)
     */
    public static function getQueueLength(): int
    {
        try {
            $redis = EnterpriseRedisManager::getInstance()->getConnection('L3_logging');
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::REDIS_TIMEOUT);

            $length = $redis->lLen('websocket:events');
            return $length !== false ? $length : 0;

        } catch (\Exception $e) {
            Logger::websocket('warning', 'Failed to get queue length', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Clear event queue (for testing/maintenance)
     *
     * WARNING: Deletes all pending events!
     * Use only for testing or emergency cleanup
     *
     * @return bool Success status
     */
    public static function clearQueue(): bool
    {
        try {
            $redis = EnterpriseRedisManager::getInstance()->getConnection('L3_logging');
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::REDIS_TIMEOUT);

            $result = $redis->del('websocket:events');

            Logger::websocket('warning', 'Event queue cleared', [
                'keys_deleted' => $result
            ]);

            return $result !== false;

        } catch (\Exception $e) {
            Logger::websocket('error', 'Failed to clear queue', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    // ========================================================================
    // ENTERPRISE GALAXY CHAT: Real-time Chat Methods (2025-12-02)
    // ========================================================================

    /**
     * Publish event to all users in a chat room
     *
     * USAGE:
     * WebSocketPublisher::publishToRoom('emotion:joy', 'room_message', [
     *     'message' => [...],
     *     'sender_uuid' => '...'
     * ]);
     *
     * NOTE: For optimal performance, the WebSocket server handles room broadcasts
     * directly via Swoole\Table. This method is for server-side events that need
     * to reach room members (e.g., moderation actions, room announcements).
     *
     * @param string $roomId Room identifier
     * @param string $event Event name
     * @param array $data Event payload
     * @return bool Success status
     */
    public static function publishToRoom(string $roomId, string $event, array $data = []): bool
    {
        // Room broadcasts are handled by websocket-server.php's broadcastToRoom()
        // This method publishes to a special room channel for server-side events
        return self::publish("room:{$roomId}", $event, $data);
    }

    /**
     * Publish event to a DM conversation (both participants)
     *
     * USAGE:
     * WebSocketPublisher::publishToConversation($convUuid, 'dm_received', [
     *     'message' => [...],
     * ], $excludeUuid);
     *
     * @param string $conversationUuid Conversation UUID
     * @param string $event Event name
     * @param array $data Event payload
     * @param string|null $excludeUuid Optional UUID to exclude from broadcast
     * @return bool Success status
     */
    public static function publishToConversation(string $conversationUuid, string $event, array $data = [], ?string $excludeUuid = null): bool
    {
        // Add conversation context to data
        $data['conversation_uuid'] = $conversationUuid;
        $data['exclude_uuid'] = $excludeUuid;

        return self::publish("conversation:{$conversationUuid}", $event, $data);
    }

    /**
     * Publish typing indicator event
     *
     * USAGE:
     * WebSocketPublisher::publishTypingIndicator('emotion:joy', 'room', $userUuid, true);
     * WebSocketPublisher::publishTypingIndicator($convUuid, 'dm', $userUuid, false);
     *
     * @param string $targetId Room ID or Conversation UUID
     * @param string $targetType 'room' or 'dm'
     * @param string $userUuid User who is typing
     * @param bool $isTyping True if typing, false if stopped
     * @return bool Success status
     */
    public static function publishTypingIndicator(string $targetId, string $targetType, string $userUuid, bool $isTyping): bool
    {
        $event = 'typing_indicator';
        $data = [
            'target_id' => $targetId,
            'target_type' => $targetType,
            'user_uuid' => $userUuid,
            'is_typing' => $isTyping,
            'timestamp' => microtime(true),
        ];

        if ($targetType === 'room') {
            return self::publish("room:{$targetId}", $event, $data);
        }

        // DM typing - publish to conversation channel
        return self::publish("conversation:{$targetId}", $event, $data);
    }

    /**
     * Publish user presence update (online/away/dnd/offline)
     *
     * ENTERPRISE V9.0 (2025-12-02): Real-time fan-out to friends
     * When a user changes status, ALL their online friends receive the update immediately.
     * This enables real-time presence updates in friend lists.
     *
     * PERFORMANCE CONSIDERATIONS:
     * - Fan-out is O(n) where n = number of friends (typically <200)
     * - Uses Redis pipeline for batch publishing (reduces RTT)
     * - Friend list is cached (5min TTL) to avoid DB queries
     * - Only notifies friends if status is visible (not invisible)
     *
     * USAGE:
     * WebSocketPublisher::publishPresenceUpdate($userUuid, 'online', [
     *     'device' => 'web',
     *     'last_seen' => time(),
     * ]);
     *
     * @param string $userUuid User UUID
     * @param string $status Status (online, away, dnd, invisible, offline)
     * @param array $meta Additional metadata
     * @return bool Success status
     */
    public static function publishPresenceUpdate(string $userUuid, string $status, array $meta = []): bool
    {
        // ENTERPRISE V9.0: Fan-out to all friends for real-time updates
        // Skip fan-out for invisible status (privacy)
        if ($status !== 'invisible') {
            self::broadcastPresenceToFriends($userUuid, $status, $meta);
        }

        // Also publish to presence channel (for any direct subscribers)
        return self::publish("presence:{$userUuid}", 'presence_update', [
            'user_uuid' => $userUuid,
            'status' => $status,
            'meta' => $meta,
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * Broadcast presence change to all friends (fan-out on write)
     *
     * ENTERPRISE V9.1: Real-time presence updates (PHP-FPM only)
     *
     * This method notifies all friends when a user's status changes,
     * enabling instant updates in friend lists across all connected clients.
     *
     * ARCHITECTURE:
     * - PHP-FPM context (HTTP requests): Full fan-out to all friends via database query
     * - Swoole WebSocket context: SKIP fan-out (no database access)
     *
     * RATIONALE FOR SWOOLE SKIP:
     * 1. WebSocket server has no access to db() helper (minimal bootstrap)
     * 2. Creating PDO connections in Swoole = connection storm under load
     * 3. Presence updates in WebSocket are handled differently:
     *    - Client connects → status stored in Redis
     *    - Client subscribes to presence PubSub channel
     *    - Friends fetch statuses via HTTP API when loading chat page
     * 4. This is the CORRECT architecture for 10k+ concurrent users
     *
     * @param string $userUuid User whose status changed
     * @param string $status New status
     * @param array $meta Additional metadata
     * @return int Number of friends notified (0 in WebSocket context)
     */
    public static function broadcastPresenceToFriends(string $userUuid, string $status, array $meta = []): int
    {
        // ENTERPRISE V9.1: Only fan-out in PHP-FPM context (where \db() is available)
        // In Swoole WebSocket context, skip fan-out entirely - clients poll via HTTP API
        // NOTE: Use backslash prefix to reference GLOBAL namespace function
        if (!\function_exists('\db')) {
            // Swoole WebSocket context - presence is handled via Redis subscription
            // Clients receive updates by subscribing to presence:{uuid} channel
            return 0;
        }

        try {
            $db = \db();
            $friends = $db->query(
                "SELECT DISTINCT
                    CASE
                        WHEN f.user_uuid = :uuid THEN f.friend_uuid
                        ELSE f.user_uuid
                    END as friend_uuid
                 FROM friendships f
                 WHERE (f.user_uuid = :uuid OR f.friend_uuid = :uuid)
                   AND f.status = 'accepted'
                   AND f.deleted_at IS NULL",
                ['uuid' => $userUuid],
                ['cache' => true, 'cache_ttl' => 'medium']
            );

            if (empty($friends)) {
                return 0;
            }

            $notified = 0;
            $isOnline = in_array($status, ['online', 'away', 'dnd']);
            $payload = [
                'user_uuid' => $userUuid,
                'status' => $status,
                'is_online' => $isOnline,
                'meta' => $meta,
                'timestamp' => microtime(true),
                // ENTERPRISE V12.2: Include last_seen timestamp for offline status
                // Frontend uses this to display "poco fa", "2 min fa", etc.
                // Without this, frontend uses stale cached last_seen showing wrong time
                'last_seen' => !$isOnline ? 'poco fa' : null,
                'last_seen_timestamp' => !$isOnline ? time() : null,
            ];

            // Fan-out to each friend's personal channel
            foreach ($friends as $friend) {
                $friendUuid = $friend['friend_uuid'];
                if ($friendUuid && $friendUuid !== $userUuid) {
                    if (self::publishToUser($friendUuid, 'friend_presence_changed', $payload)) {
                        $notified++;
                    }
                }
            }

            return $notified;

        } catch (\Exception $e) {
            // ENTERPRISE: Use websocket channel for WebSocket-related errors
            Logger::websocket('warning', 'Failed to broadcast presence to friends', [
                'user_uuid' => $userUuid,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Publish read receipt for DM conversation
     *
     * USAGE:
     * WebSocketPublisher::publishReadReceipt($convUuid, $readerUuid, $lastMessageUuid);
     *
     * @param string $conversationUuid Conversation UUID
     * @param string $readerUuid UUID of user who read the messages
     * @param string $lastMessageUuid UUID of last read message
     * @return bool Success status
     */
    public static function publishReadReceipt(string $conversationUuid, string $readerUuid, string $lastMessageUuid): bool
    {
        return self::publish("conversation:{$conversationUuid}", 'read_receipt', [
            'conversation_uuid' => $conversationUuid,
            'reader_uuid' => $readerUuid,
            'last_message_uuid' => $lastMessageUuid,
            'read_at' => microtime(true),
        ]);
    }

    /**
     * Publish new message notification for DM (triggers unread badge)
     *
     * @param string $recipientUuid Recipient user UUID
     * @param string $conversationUuid Conversation UUID
     * @param array $messagePreview Preview data (truncated, no encrypted content)
     * @return bool Success status
     */
    public static function publishDMNotification(string $recipientUuid, string $conversationUuid, array $messagePreview): bool
    {
        return self::publishToUser($recipientUuid, 'dm_notification', [
            'conversation_uuid' => $conversationUuid,
            'preview' => $messagePreview,
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * Publish room announcement (system message to all room members)
     *
     * @param string $roomId Room identifier
     * @param string $message Announcement message
     * @param string $announcementType Type (info, warning, moderation)
     * @return bool Success status
     */
    public static function publishRoomAnnouncement(string $roomId, string $message, string $announcementType = 'info'): bool
    {
        return self::publishToRoom($roomId, 'room_announcement', [
            'room_id' => $roomId,
            'message' => $message,
            'announcement_type' => $announcementType,
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * Publish user kicked from room notification
     *
     * @param string $roomId Room identifier
     * @param string $kickedUuid UUID of kicked user
     * @param string $reason Kick reason
     * @return bool Success status
     */
    public static function publishUserKicked(string $roomId, string $kickedUuid, string $reason = ''): bool
    {
        // Notify the kicked user
        self::publishToUser($kickedUuid, 'kicked_from_room', [
            'room_id' => $roomId,
            'reason' => $reason,
            'timestamp' => microtime(true),
        ]);

        // Notify room members
        return self::publishToRoom($roomId, 'user_kicked', [
            'room_id' => $roomId,
            'kicked_uuid' => $kickedUuid,
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * Publish moderation action notification
     *
     * @param string $targetUuid User who received moderation action
     * @param string $action Action type (warn, mute, ban, message_deleted)
     * @param array $details Action details
     * @return bool Success status
     */
    public static function publishModerationAction(string $targetUuid, string $action, array $details = []): bool
    {
        return self::publishToUser($targetUuid, 'moderation_action', [
            'action' => $action,
            'details' => $details,
            'timestamp' => microtime(true),
        ]);
    }
}
