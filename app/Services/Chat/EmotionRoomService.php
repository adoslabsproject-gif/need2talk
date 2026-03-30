<?php

declare(strict_types=1);

namespace Need2Talk\Services\Chat;

use Need2Talk\Contracts\Redis\RedisAdapterInterface;
use Need2Talk\Services\Logger;

/**
 * EmotionRoomService - Gestisce le 10 Emotion Rooms permanenti
 *
 * Le Emotion Rooms sono stanze pubbliche basate sulle emozioni del sistema need2talk.
 * A differenza delle user-created rooms, queste sono:
 * - Permanenti (sempre attive)
 * - Ephemeral (messaggi solo in Redis, non persistiti in DB)
 * - Pubbliche (chiunque può entrare)
 *
 * ENTERPRISE DI: This service uses constructor injection for Redis.
 * Use ChatServiceFactory::createEmotionRoomService() to instantiate.
 *
 * @package Need2Talk\Services\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class EmotionRoomService
{
    /**
     * Le 10 Emotion Rooms predefinite basate sul sistema emotivo di need2talk
     * Mapping: emotion_id => room configuration
     */
    /**
     * ENTERPRISE GALAXY v4.7 (2025-12-24): Uniformate con tabella emotions
     * Le 10 Emotion Rooms ora corrispondono esattamente alla tabella emotions
     */
    public const EMOTION_ROOMS = [
        1 => [
            'id' => 'emotion:joy',
            'slug' => 'gioia',
            'emoji' => '😊',
            'name' => 'Gioia',
            'name_en' => 'Joy',
            'description' => 'Condividi i tuoi momenti di felicità',
            'color' => '#FFD700',  // Gold
            'category' => 'positive',
        ],
        2 => [
            'id' => 'emotion:wonder',
            'slug' => 'meraviglia',
            'emoji' => '✨',
            'name' => 'Meraviglia',
            'name_en' => 'Wonder',
            'description' => 'Condividi stupore e momenti magici',
            'color' => '#FF6B35',  // Orange (from emotions table)
            'category' => 'positive',
        ],
        3 => [
            'id' => 'emotion:love',
            'slug' => 'amore',
            'emoji' => '❤️',
            'name' => 'Amore',
            'name_en' => 'Love',
            'description' => 'Celebra l\'amore in tutte le sue forme',
            'color' => '#FF1493',  // Deep Pink (from emotions table)
            'category' => 'positive',
        ],
        4 => [
            'id' => 'emotion:gratitude',
            'slug' => 'gratitudine',
            'emoji' => '🙏',
            'name' => 'Gratitudine',
            'name_en' => 'Gratitude',
            'description' => 'Esprimi riconoscenza e apprezzamento',
            'color' => '#32CD32',  // Lime Green (from emotions table)
            'category' => 'positive',
        ],
        5 => [
            'id' => 'emotion:hope',
            'slug' => 'speranza',
            'emoji' => '🌟',
            'name' => 'Speranza',
            'name_en' => 'Hope',
            'description' => 'Condividi i tuoi sogni e aspirazioni',
            'color' => '#87CEEB',  // Sky Blue (from emotions table)
            'category' => 'positive',
        ],
        6 => [
            'id' => 'emotion:sadness',
            'slug' => 'tristezza',
            'emoji' => '😢',
            'name' => 'Tristezza',
            'name_en' => 'Sadness',
            'description' => 'Uno spazio sicuro per elaborare il dolore',
            'color' => '#4682B4',  // Steel Blue (from emotions table)
            'category' => 'negative',
        ],
        7 => [
            'id' => 'emotion:anger',
            'slug' => 'rabbia',
            'emoji' => '😠',
            'name' => 'Rabbia',
            'name_en' => 'Anger',
            'description' => 'Sfoga la frustrazione in modo costruttivo',
            'color' => '#DC143C',  // Crimson (from emotions table)
            'category' => 'negative',
        ],
        8 => [
            'id' => 'emotion:anxiety',
            'slug' => 'ansia',
            'emoji' => '😰',
            'name' => 'Ansia',
            'name_en' => 'Anxiety',
            'description' => 'Parla delle tue preoccupazioni con chi ti capisce',
            'color' => '#FF8C00',  // Dark Orange (from emotions table)
            'category' => 'negative',
        ],
        9 => [
            'id' => 'emotion:fear',
            'slug' => 'paura',
            'emoji' => '😨',
            'name' => 'Paura',
            'name_en' => 'Fear',
            'description' => 'Affronta le tue paure insieme ad altri',
            'color' => '#8B008B',  // Dark Magenta (from emotions table)
            'category' => 'negative',
        ],
        10 => [
            'id' => 'emotion:loneliness',
            'slug' => 'solitudine',
            'emoji' => '😔',
            'name' => 'Solitudine',
            'name_en' => 'Loneliness',
            'description' => 'Non sei solo, parliamo insieme',
            'color' => '#696969',  // Dim Gray (from emotions table)
            'category' => 'negative',
        ],
    ];

    /**
     * Redis key prefixes
     */
    private const REDIS_PREFIX_MESSAGES = 'chat:room:';
    private const REDIS_PREFIX_ONLINE = 'chat:room:';
    private const REDIS_SUFFIX_MESSAGES = ':messages';
    private const REDIS_SUFFIX_ONLINE = ':online';

    /**
     * Configuration
     */
    private const MAX_MESSAGES_PER_ROOM = 100;
    private const MESSAGE_TTL_SECONDS = 3600;  // 1 hour
    // ENTERPRISE FIX v10.17: Increased TTL from 90s to 300s (5 minutes)
    // Client heartbeat is 30s. Previously 90s caused disconnects when tabs were hidden.
    // Now 300s = 10 heartbeats buffer, matches Discord/Slack behavior.
    // Also removed stopHeartbeat() on visibility change to prevent disconnects.
    private const ONLINE_SET_TTL_SECONDS = 300;

    /**
     * ENTERPRISE V10.56 (2025-12-07): Room capacity and activity limits
     * Same limits apply to both emotion rooms and user-created rooms
     */
    private const MAX_ONLINE_PER_ROOM = 20;
    private const INACTIVITY_KICK_SECONDS = 3600;  // 1 hour
    private const REDIS_SUFFIX_ACTIVITY = ':activity';

    private RedisAdapterInterface $redis;

    /**
     * Constructor with dependency injection
     *
     * @param RedisAdapterInterface $redis Redis adapter (context-aware)
     */
    public function __construct(RedisAdapterInterface $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Get all emotion rooms with online counts
     *
     * @return array List of emotion rooms with current online user counts
     */
    public function getAllRooms(): array
    {
        $rooms = [];

        foreach (self::EMOTION_ROOMS as $emotionId => $room) {
            $onlineCount = 0;

            try {
                $onlineKey = self::REDIS_PREFIX_ONLINE . $room['id'] . self::REDIS_SUFFIX_ONLINE;
                $onlineCount = $this->redis->scard($onlineKey, 'chat');
            } catch (\Exception $e) {
                Logger::warning('Failed to get online count for emotion room', [
                    'room_id' => $room['id'],
                    'error' => $e->getMessage(),
                ]);
            }

            $rooms[] = [
                'emotion_id' => $emotionId,
                'id' => $room['id'],
                'slug' => $room['slug'],
                'emoji' => $room['emoji'],
                'name' => $room['name'],
                'name_en' => $room['name_en'],
                'description' => $room['description'],
                'color' => $room['color'],
                'category' => $room['category'],
                'online_count' => $onlineCount,
                'type' => 'emotion',
                'is_permanent' => true,
            ];
        }

        return $rooms;
    }

    /**
     * Get a single emotion room by ID
     *
     * @param string $roomId Format: "emotion:joy", "emotion:sadness", etc.
     * @return array|null Room data or null if not found
     */
    public function getRoom(string $roomId): ?array
    {
        foreach (self::EMOTION_ROOMS as $emotionId => $room) {
            if ($room['id'] === $roomId) {
                $onlineCount = 0;

                try {
                    $onlineKey = self::REDIS_PREFIX_ONLINE . $room['id'] . self::REDIS_SUFFIX_ONLINE;
                    $onlineCount = $this->redis->scard($onlineKey, 'chat');
                } catch (\Exception $e) {
                    Logger::warning('Failed to get online count', [
                        'room_id' => $roomId,
                        'error' => $e->getMessage(),
                    ]);
                }

                return [
                    'emotion_id' => $emotionId,
                    'id' => $room['id'],
                    'slug' => $room['slug'],
                    'emoji' => $room['emoji'],
                    'name' => $room['name'],
                    'name_en' => $room['name_en'],
                    'description' => $room['description'],
                    'color' => $room['color'],
                    'category' => $room['category'],
                    'online_count' => $onlineCount,
                    'type' => 'emotion',
                    'is_permanent' => true,
                ];
            }
        }

        return null;
    }

    /**
     * Get emotion room by slug
     *
     * @param string $slug e.g., "gioia", "tristezza"
     * @return array|null
     */
    public function getRoomBySlug(string $slug): ?array
    {
        foreach (self::EMOTION_ROOMS as $room) {
            if ($room['slug'] === $slug) {
                return $this->getRoom($room['id']);
            }
        }

        return null;
    }

    /**
     * Check if a room ID is a valid emotion room
     *
     * @param string $roomId
     * @return bool
     */
    public function isEmotionRoom(string $roomId): bool
    {
        foreach (self::EMOTION_ROOMS as $room) {
            if ($room['id'] === $roomId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get recent messages from an emotion room
     *
     * @param string $roomId
     * @param int $limit Max messages to retrieve (default 50)
     * @return array Messages sorted by timestamp (newest first)
     */
    public function getMessages(string $roomId, int $limit = 50): array
    {
        if (!$this->isEmotionRoom($roomId)) {
            return [];
        }

        try {
            $messagesKey = self::REDIS_PREFIX_MESSAGES . $roomId . self::REDIS_SUFFIX_MESSAGES;

            // Get messages from ZSET (sorted by score = timestamp)
            // zrange with 0, -1 gets all, then we reverse and limit
            $rawMessages = $this->redis->zrange($messagesKey, -$limit, -1, 'chat', true);

            if (empty($rawMessages)) {
                return [];
            }

            $messages = [];
            // Reverse to get newest first
            $rawMessages = array_reverse($rawMessages, true);

            foreach ($rawMessages as $messageJson => $score) {
                $message = json_decode($messageJson, true);
                if ($message) {
                    $messages[] = $message;
                }
            }

            // ENTERPRISE V12.2: Enrich messages with current avatars
            return $this->enrichMessagesWithCurrentAvatars($messages);

        } catch (\Exception $e) {
            Logger::warning('Failed to get messages from emotion room', [
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * ENTERPRISE V12.2: Enrich messages with current avatar URLs
     *
     * Messages stored in Redis have sender_avatar denormalized at send time.
     * When a user changes their avatar, old messages would show stale avatars.
     * This method fetches current avatars from database and updates messages.
     *
     * @param array $messages Array of message objects
     * @return array Messages with updated sender_avatar
     */
    private function enrichMessagesWithCurrentAvatars(array $messages): array
    {
        if (empty($messages)) {
            return $messages;
        }

        // Collect unique sender UUIDs
        $senderUuids = [];
        foreach ($messages as $msg) {
            if (!empty($msg['sender_uuid'])) {
                $senderUuids[] = $msg['sender_uuid'];
            }
        }

        if (empty($senderUuids)) {
            return $messages;
        }

        // Batch fetch current avatars from database
        try {
            $userModel = new \Need2Talk\Models\User();
            $avatarMap = $userModel->getAvatarsByUuids($senderUuids);
        } catch (\Exception $e) {
            Logger::warning('Failed to fetch current avatars for emotion room messages', [
                'error' => $e->getMessage(),
            ]);
            return $messages; // Return original messages on error
        }

        // Update sender_avatar in each message
        foreach ($messages as &$msg) {
            $uuid = $msg['sender_uuid'] ?? null;
            if ($uuid && isset($avatarMap[$uuid])) {
                $msg['sender_avatar'] = $avatarMap[$uuid];
            }
        }
        unset($msg); // Break reference

        return $messages;
    }

    /**
     * Add a message to an emotion room (ephemeral, Redis only)
     *
     * @param string $roomId
     * @param string $senderUuid
     * @param string $senderNickname
     * @param string $content
     * @param string $messageType 'text' or 'audio'
     * @param array $extra Extra data (audio_url, duration, sender_avatar, etc.)
     * @return array|null Created message or null on failure
     */
    public function addMessage(
        string $roomId,
        string $senderUuid,
        string $senderNickname,
        string $content,
        string $messageType = 'text',
        array $extra = []
    ): ?array {
        if (!$this->isEmotionRoom($roomId)) {
            return null;
        }

        try {
            $messageId = $this->generateMessageId();
            $timestamp = microtime(true);

            $message = [
                'id' => $messageId,
                'room_id' => $roomId,
                'sender_uuid' => $senderUuid,
                'sender_nickname' => $senderNickname,
                'content' => $content,
                'type' => $messageType,
                'created_at' => $timestamp,
                'created_at_formatted' => date('H:i', (int) $timestamp),
            ];

            // ENTERPRISE V9.4: Add sender avatar for message rendering
            if (!empty($extra['sender_avatar'])) {
                $message['sender_avatar'] = $extra['sender_avatar'];
            }

            // Add extra data for audio messages
            if ($messageType === 'audio' && !empty($extra)) {
                $message['audio_url'] = $extra['audio_url'] ?? null;
                $message['duration_seconds'] = $extra['duration_seconds'] ?? null;
            }

            $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE);
            $messagesKey = self::REDIS_PREFIX_MESSAGES . $roomId . self::REDIS_SUFFIX_MESSAGES;

            // Add to sorted set with timestamp as score
            $this->redis->zadd($messagesKey, $timestamp, $messageJson, 'chat');

            // Keep only last N messages (trim old ones)
            $this->redis->zremrangebyrank($messagesKey, 0, -(self::MAX_MESSAGES_PER_ROOM + 1), 'chat');

            // Refresh TTL
            $this->redis->expire($messagesKey, self::MESSAGE_TTL_SECONDS, 'chat');

            // ENTERPRISE V12.2: Update activity timestamp on message send
            // This fixes "online 13 min fa" showing old join time instead of last message time
            $activityKey = self::REDIS_PREFIX_ONLINE . $roomId . self::REDIS_SUFFIX_ACTIVITY;
            $this->redis->hset($activityKey, 'chat', $senderUuid, (string) time());

            return $message;

        } catch (\Exception $e) {
            Logger::error('Failed to add message to emotion room', [
                'room_id' => $roomId,
                'sender_uuid' => $senderUuid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Add user to room's online set
     *
     * @param string $roomId
     * @param string $userUuid
     * @return array{success: bool, error?: string, room?: array, online_count?: int}
     */
    public function joinRoom(string $roomId, string $userUuid): array
    {
        if (!$this->isEmotionRoom($roomId)) {
            return ['success' => false, 'error' => 'Invalid emotion room'];
        }

        try {
            $onlineKey = self::REDIS_PREFIX_ONLINE . $roomId . self::REDIS_SUFFIX_ONLINE;
            $activityKey = self::REDIS_PREFIX_ONLINE . $roomId . self::REDIS_SUFFIX_ACTIVITY;

            // ENTERPRISE V10.56: Check if user already in room (always allow re-entry)
            $isAlreadyMember = $this->redis->sismember($onlineKey, 'chat', $userUuid);

            if (!$isAlreadyMember) {
                // Check room capacity (max 20 online users)
                $currentCount = $this->redis->scard($onlineKey, 'chat');
                if ($currentCount >= self::MAX_ONLINE_PER_ROOM) {
                    return [
                        'success' => false,
                        'error' => 'room_full_online',
                        'message' => 'Stanza piena! Torna tra qualche minuto...',
                    ];
                }
            }

            // Add to online set
            $this->redis->sadd($onlineKey, 'chat', $userUuid);
            $this->redis->expire($onlineKey, self::ONLINE_SET_TTL_SECONDS, 'chat');

            // Track activity timestamp for inactivity kicks
            $this->redis->hset($activityKey, 'chat', $userUuid, (string) time());
            $this->redis->expire($activityKey, self::INACTIVITY_KICK_SECONDS + 300, 'chat');

            // Get room data and online count for response
            $room = $this->getRoom($roomId);
            $onlineCount = $this->redis->scard($onlineKey, 'chat');

            return [
                'success' => true,
                'room' => $room,
                'online_count' => $onlineCount,
            ];

        } catch (\Exception $e) {
            Logger::warning('Failed to join emotion room', [
                'room_id' => $roomId,
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => 'Failed to join room'];
        }
    }

    /**
     * Remove user from room's online set
     *
     * @param string $roomId
     * @param string $userUuid
     * @return bool
     */
    public function leaveRoom(string $roomId, string $userUuid): bool
    {
        if (!$this->isEmotionRoom($roomId)) {
            return false;
        }

        try {
            $onlineKey = self::REDIS_PREFIX_ONLINE . $roomId . self::REDIS_SUFFIX_ONLINE;
            $this->redis->srem($onlineKey, 'chat', $userUuid);

            return true;

        } catch (\Exception $e) {
            Logger::warning('Failed to leave emotion room', [
                'room_id' => $roomId,
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Refresh user's presence in room (heartbeat)
     *
     * ENTERPRISE V9.0 (2025-12-02): Fixed return type mismatch
     * joinRoom() returns array, but refreshPresence() declared bool return
     *
     * @param string $roomId
     * @param string $userUuid
     * @return bool
     */
    public function refreshPresence(string $roomId, string $userUuid): bool
    {
        // Re-join to refresh the SET membership TTL
        $result = $this->joinRoom($roomId, $userUuid);

        // joinRoom returns ['success' => bool, ...], extract the bool
        return is_array($result) ? ($result['success'] ?? false) : false;
    }

    /**
     * Get list of online users in a room
     *
     * @param string $roomId
     * @return array List of user UUIDs
     */
    public function getOnlineUsers(string $roomId): array
    {
        if (!$this->isEmotionRoom($roomId)) {
            return [];
        }

        try {
            $onlineKey = self::REDIS_PREFIX_ONLINE . $roomId . self::REDIS_SUFFIX_ONLINE;
            $userUuids = $this->redis->smembers($onlineKey, 'chat');

            return $userUuids ?: [];

        } catch (\Exception $e) {
            Logger::warning('Failed to get online users', [
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get online count for a room
     *
     * @param string $roomId
     * @return int
     */
    public function getOnlineCount(string $roomId): int
    {
        if (!$this->isEmotionRoom($roomId)) {
            return 0;
        }

        try {
            $onlineKey = self::REDIS_PREFIX_ONLINE . $roomId . self::REDIS_SUFFIX_ONLINE;
            return $this->redis->scard($onlineKey, 'chat');

        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Generate unique message ID
     *
     * @return string
     */
    private function generateMessageId(): string
    {
        return sprintf(
            'msg_%s_%s',
            base_convert((string) hrtime(true), 10, 36),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Get emotion room ID from emotion_id (1-10)
     *
     * @param int $emotionId
     * @return string|null
     */
    public function getRoomIdByEmotionId(int $emotionId): ?string
    {
        return self::EMOTION_ROOMS[$emotionId]['id'] ?? null;
    }

    /**
     * Clear all messages from a room (admin function)
     *
     * @param string $roomId
     * @return bool
     */
    public function clearMessages(string $roomId): bool
    {
        if (!$this->isEmotionRoom($roomId)) {
            return false;
        }

        try {
            $messagesKey = self::REDIS_PREFIX_MESSAGES . $roomId . self::REDIS_SUFFIX_MESSAGES;
            $this->redis->del($messagesKey, 'chat');

            Logger::info('Emotion room messages cleared', [
                'room_id' => $roomId,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('Failed to clear emotion room messages', [
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
