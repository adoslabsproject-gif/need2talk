<?php

declare(strict_types=1);

namespace Need2Talk\Services\Chat;

use Need2Talk\Contracts\Redis\RedisAdapterInterface;
use Need2Talk\Contracts\Database\DatabaseAdapterInterface;
use Need2Talk\Contracts\Publisher\EventPublisherInterface;
use Need2Talk\Services\Logger;

/**
 * ChatRoomService - Enterprise-Grade Room Management
 *
 * Features:
 * - CRUD for user-created rooms
 * - Membership and role management
 * - Ephemeral messages in Redis
 * - Auto-close after 4h inactivity
 * - Private room invitations
 *
 * Performance:
 * - All queries use explicit columns (no SELECT *)
 * - Proper table aliases prevent ambiguity
 * - UUID type casting for cross-table compatibility
 * - Index-optimized WHERE clauses
 *
 * ENTERPRISE DI: This service uses constructor injection for all dependencies.
 * Use ChatServiceFactory::createChatRoomService() to instantiate.
 *
 * @package Need2Talk\Services\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class ChatRoomService
{
    /**
     * Redis key prefixes
     */
    private const PREFIX_MESSAGES = 'chat:room:private:';
    private const PREFIX_META = 'chat:room:private:';
    private const PREFIX_ONLINE = 'chat:room:private:';
    private const SUFFIX_MESSAGES = ':messages';
    private const SUFFIX_META = ':meta';
    private const SUFFIX_ONLINE = ':online';

    /**
     * Configuration
     */
    private const MAX_MESSAGES_PER_ROOM = 100;
    private const MESSAGE_TTL_SECONDS = 14400;  // 4 hours
    private const DEFAULT_MAX_MEMBERS = 50;
    private const MAX_MAX_MEMBERS = 500;

    /**
     * ENTERPRISE V10.56 (2025-12-07): Room capacity and activity limits
     * - Max 20 users can be ONLINE simultaneously in any room
     * - Users inactive for 1 hour get kicked automatically
     */
    private const MAX_ONLINE_PER_ROOM = 20;
    private const INACTIVITY_KICK_SECONDS = 3600;  // 1 hour
    private const SUFFIX_ACTIVITY = ':activity';   // HASH for last activity timestamps

    private RedisAdapterInterface $redis;
    private ?DatabaseAdapterInterface $database;
    private EmotionRoomService $emotionRoomService;
    private ChatModerationService $moderationService;
    private ?EventPublisherInterface $publisher;

    /**
     * Constructor with dependency injection
     *
     * @param RedisAdapterInterface $redis Redis adapter (context-aware)
     * @param DatabaseAdapterInterface|null $database Database adapter (optional in Swoole)
     * @param EmotionRoomService $emotionRoomService Injected emotion room service
     * @param ChatModerationService $moderationService Injected moderation service
     * @param EventPublisherInterface|null $publisher Event publisher for real-time updates
     */
    public function __construct(
        RedisAdapterInterface $redis,
        ?DatabaseAdapterInterface $database,
        EmotionRoomService $emotionRoomService,
        ChatModerationService $moderationService,
        ?EventPublisherInterface $publisher = null
    ) {
        $this->redis = $redis;
        $this->database = $database;
        $this->emotionRoomService = $emotionRoomService;
        $this->moderationService = $moderationService;
        $this->publisher = $publisher;
    }

    /**
     * Get database adapter (falls back to db() helper if not injected)
     */
    private function db(): DatabaseAdapterInterface|\Need2Talk\Core\EnterpriseSecureDatabasePool
    {
        return $this->database ?? db();
    }

    /**
     * Publish event (uses injected publisher or falls back to static)
     */
    private function publishToRoom(string $roomId, string $event, array $data): bool
    {
        if ($this->publisher) {
            return $this->publisher->publishToRoom($roomId, $event, $data);
        }

        // Fallback to static publisher for PHP-FPM context
        return \Need2Talk\Services\WebSocketPublisher::publishToRoom($roomId, $event, $data);
    }

    /**
     * Execute callback with Redis connection and guaranteed release
     *
     * ENTERPRISE V9.3: Prevents pool exhaustion in Swoole context by ensuring
     * connections are always released back to the pool, even on exception.
     *
     * In PHP-FPM context, releaseConnection() is a no-op but this pattern
     * ensures code works correctly in both contexts.
     *
     * @param string $type Redis connection type ('chat', 'L1_cache', etc.)
     * @param callable $callback Function receiving Redis connection: fn(\Redis $redis) => mixed
     * @param mixed $default Default value to return if Redis unavailable
     * @return mixed Callback result or $default if Redis unavailable
     */
    private function withRedisConnection(string $type, callable $callback, mixed $default = null): mixed
    {
        $redis = $this->redis->getConnection($type);
        if ($redis === null) {
            return $default;
        }

        try {
            return $callback($redis);
        } finally {
            $this->redis->releaseConnection($redis, $type);
        }
    }

    /**
     * Create a new user room
     * Enterprise-grade with transaction safety
     *
     * @param int $creatorId Creator user ID
     * @param string $creatorUuid Creator UUID
     * @param array $data Room data: name, description, room_type, max_members, emotion_id
     * @return array|null Room data on success, null on failure
     */
    public function createRoom(int $creatorId, string $creatorUuid, array $data): ?array
    {
        $db = $this->db();

        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $roomType = $data['room_type'] ?? 'user_created';
        $maxMembers = min((int) ($data['max_members'] ?? self::DEFAULT_MAX_MEMBERS), self::MAX_MAX_MEMBERS);
        $emotionId = isset($data['emotion_id']) ? (int) $data['emotion_id'] : null;

        if (strlen($name) < 3 || strlen($name) > 100) {
            Logger::warning('Room creation failed: invalid name length', ['name_length' => strlen($name)]);
            return null;
        }

        // Validate emotion_id (1-10) for user-created rooms
        if ($roomType === 'user_created' && ($emotionId === null || $emotionId < 1 || $emotionId > 10)) {
            Logger::warning('Room creation failed: invalid emotion_id', ['emotion_id' => $emotionId]);
            return null;
        }

        // ENTERPRISE V9.4: Check for duplicate room names (case-insensitive)
        // Only check active rooms (not deleted ones)
        $existingRoom = $db->findOne(
            "SELECT id, name FROM chat_rooms
             WHERE LOWER(name) = LOWER(?)
               AND status = 'active'
               AND deleted_at IS NULL
             LIMIT 1",
            [$name]
        );

        if ($existingRoom) {
            Logger::warning('Room creation failed: duplicate name', [
                'name' => $name,
                'existing_room_id' => $existingRoom['id'],
            ]);
            return null;
        }

        try {
            $db->beginTransaction();

            // Use PostgreSQL RETURNING to get the new room ID and UUID in a single query
            $insertResult = $db->findOne(
                "INSERT INTO chat_rooms
                 (name, description, room_type, status, creator_id, creator_uuid,
                  max_members, is_ephemeral, member_count, emotion_id, created_at, last_activity_at)
                 VALUES (?, ?, ?::chat_room_type, 'active', ?, ?::uuid, ?, TRUE, 1, ?, NOW(), NOW())
                 RETURNING id, uuid::text AS uuid",
                [$name, $description, $roomType, $creatorId, $creatorUuid, $maxMembers, $emotionId]
            );

            if (!$insertResult) {
                $db->rollback();
                return null;
            }

            $roomId = $insertResult['id'];

            // Get full room data with emotion info
            $room = $db->findOne(
                "SELECT
                    cr.id, cr.uuid::text AS uuid, cr.name, cr.description, cr.room_type, cr.status,
                    cr.creator_id, cr.creator_uuid::text AS creator_uuid,
                    cr.max_members, cr.member_count, cr.is_ephemeral, cr.emotion_id,
                    cr.last_activity_at, cr.created_at,
                    e.name_it AS emotion_name, e.icon_emoji AS emotion_icon, e.color_hex AS emotion_color
                 FROM chat_rooms cr
                 LEFT JOIN emotions e ON e.id = cr.emotion_id
                 WHERE cr.id = ?",
                [$roomId]
            );

            $db->execute(
                "INSERT INTO chat_room_members
                 (room_id, user_id, user_uuid, role, joined_at, created_at)
                 VALUES (?, ?, ?::uuid, 'admin', NOW(), NOW())",
                [$roomId, $creatorId, $creatorUuid]
            );

            $db->commit();

            $this->initializeRoomRedis($room['uuid'], $name, $creatorUuid);

            Logger::info('Chat room created', [
                'room_uuid' => $room['uuid'],
                'creator_uuid' => $creatorUuid,
                'room_type' => $roomType,
            ]);

            return $this->formatRoomOutput($room);

        } catch (\Exception $e) {
            $db->rollback();
            Logger::error('Failed to create chat room', [
                'error' => $e->getMessage(),
                'creator_uuid' => $creatorUuid,
            ]);
            return null;
        }
    }

    /**
     * Get room by UUID
     * Uses partial index on status for fast lookups
     */
    public function getRoom(string $roomUuid): ?array
    {
        if (str_starts_with($roomUuid, 'emotion:')) {
            return $this->emotionRoomService->getRoom($roomUuid);
        }

        $db = $this->db();

        $room = $db->findOne(
            "SELECT
                cr.id, cr.uuid::text AS uuid, cr.name, cr.description, cr.room_type, cr.status,
                cr.creator_id, cr.creator_uuid::text AS creator_uuid,
                cr.max_members, cr.member_count, cr.is_ephemeral, cr.emotion_id,
                cr.last_activity_at, cr.created_at,
                e.name_it AS emotion_name, e.icon_emoji AS emotion_icon, e.color_hex AS emotion_color
             FROM chat_rooms cr
             LEFT JOIN emotions e ON e.id = cr.emotion_id
             WHERE cr.uuid::text = ?
               AND cr.deleted_at IS NULL",
            [$roomUuid],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return $room ? $this->formatRoomOutput($room) : null;
    }

    /**
     * Get user's rooms (created or joined)
     * Optimized JOIN with proper index usage
     */
    public function getUserRooms(string $userUuid, int $limit = 20): array
    {
        $db = $this->db();

        $rooms = $db->query(
            "SELECT
                cr.id, cr.uuid::text AS uuid, cr.name, cr.description, cr.room_type, cr.status,
                cr.creator_id, cr.creator_uuid::text AS creator_uuid,
                cr.max_members, cr.member_count, cr.is_ephemeral, cr.emotion_id,
                cr.last_activity_at, cr.created_at,
                m.role, m.joined_at AS user_joined_at,
                e.name_it AS emotion_name, e.icon_emoji AS emotion_icon, e.color_hex AS emotion_color
             FROM chat_rooms cr
             INNER JOIN chat_room_members m ON m.room_id = cr.id
             LEFT JOIN emotions e ON e.id = cr.emotion_id
             WHERE m.user_uuid::text = ?
               AND m.is_banned = FALSE
               AND cr.status = 'active'
               AND cr.deleted_at IS NULL
             ORDER BY cr.last_activity_at DESC NULLS LAST
             LIMIT ?",
            [$userUuid, $limit],
            ['cache' => false]
        );

        return array_map(fn($r) => $this->formatRoomOutput($r), $rooms);
    }

    /**
     * Get public rooms list
     * Uses composite index on (room_type, last_activity_at)
     */
    public function getPublicRooms(int $limit = 20, int $offset = 0): array
    {
        $db = $this->db();

        $rooms = $db->query(
            "SELECT
                cr.id, cr.uuid::text AS uuid, cr.name, cr.description, cr.room_type, cr.status,
                cr.creator_id, cr.creator_uuid::text AS creator_uuid,
                cr.max_members, cr.member_count, cr.is_ephemeral, cr.emotion_id,
                cr.last_activity_at, cr.created_at,
                e.name_it AS emotion_name, e.icon_emoji AS emotion_icon, e.color_hex AS emotion_color
             FROM chat_rooms cr
             LEFT JOIN emotions e ON e.id = cr.emotion_id
             WHERE cr.room_type = 'user_created'
               AND cr.status = 'active'
               AND cr.deleted_at IS NULL
             ORDER BY cr.member_count DESC, cr.last_activity_at DESC NULLS LAST
             LIMIT ? OFFSET ?",
            [$limit, $offset],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return array_map(fn($r) => $this->formatRoomOutput($r), $rooms);
    }

    /**
     * List all user-created rooms (API endpoint)
     * Enterprise-grade with creator info JOIN
     *
     * ENTERPRISE ARCHITECTURE:
     * - Only returns rooms with online users (ephemeral rooms don't exist without users)
     * - Uses Redis SCARD to check online_count before returning
     * - This ensures empty rooms are never shown in the public list
     *
     * Query uses:
     * - idx_chat_rooms_type_activity (room_type, last_activity_at DESC)
     * - Explicit type casts for UUID compatibility
     *
     * @param int $limit Max rooms to return
     * @param int $offset Pagination offset
     * @param string $status Filter by status ('active', 'all')
     * @param bool $includeEmpty If true, include rooms with 0 online users (default: false)
     */
    public function listRooms(int $limit = 20, int $offset = 0, string $status = 'active', bool $includeEmpty = false): array
    {
        $db = $this->db();

        $statusCondition = $status === 'active' ? "AND cr.status = 'active'" : '';

        // Fetch more rooms than needed to account for filtering empty ones
        $fetchLimit = $includeEmpty ? $limit : $limit * 3;

        $sql = "
            SELECT
                cr.id,
                cr.uuid::text AS uuid,
                cr.name,
                cr.description,
                cr.room_type,
                cr.status,
                cr.creator_id,
                cr.creator_uuid::text AS creator_uuid,
                cr.max_members,
                cr.member_count,
                cr.is_ephemeral,
                cr.emotion_id,
                cr.last_activity_at,
                cr.created_at,
                u.nickname AS creator_name,
                u.avatar_url AS creator_avatar,
                e.name_it AS emotion_name,
                e.icon_emoji AS emotion_icon,
                e.color_hex AS emotion_color
            FROM chat_rooms cr
            LEFT JOIN users u ON u.uuid::text = cr.creator_uuid::text
            LEFT JOIN emotions e ON e.id = cr.emotion_id
            WHERE cr.room_type = 'user_created'
              AND cr.deleted_at IS NULL
              {$statusCondition}
            ORDER BY cr.last_activity_at DESC NULLS LAST, cr.member_count DESC
            LIMIT ? OFFSET ?
        ";

        // ENTERPRISE V10.52: DISABLED CACHE - Room list must reflect real-time state
        // Cache invalidation was not implemented for room creation/deletion, causing
        // other users to not see newly created rooms. Caching can be re-enabled
        // once proper invalidation is added via 'invalidate_cache' => ['table:chat_rooms']
        $rooms = $db->query($sql, [$fetchLimit, $offset], ['cache' => false]);

        // Format rooms and get online counts from Redis
        $formattedRooms = array_map(fn($r) => $this->formatRoomOutput($r), $rooms);

        // Filter out empty rooms unless explicitly requested
        if (!$includeEmpty) {
            $formattedRooms = array_filter($formattedRooms, fn($r) => ($r['online_count'] ?? 0) > 0);
            $formattedRooms = array_values($formattedRooms); // Re-index array
        }

        // Apply the original limit after filtering
        return array_slice($formattedRooms, 0, $limit);
    }

    /**
     * Join a room
     *
     * ENTERPRISE V9.0 (2025-12-06): WebSocket-compatible signature
     * Now accepts optional userId - if not provided, looks up from userUuid
     * This allows WebSocket server to call with just (roomUuid, userUuid)
     *
     * @param string $roomUuid Room UUID or emotion:* ID
     * @param string $userUuid User UUID (required)
     * @param int|null $userId Optional user DB ID (will be looked up if null)
     */
    public function joinRoom(string $roomUuid, string $userUuid, ?int $userId = null): array
    {
        if (str_starts_with($roomUuid, 'emotion:')) {
            // EmotionRoomService now returns array directly
            $result = $this->emotionRoomService->joinRoom($roomUuid, $userUuid);
            if ($result['success']) {
                return [
                    'success' => true,
                    'error' => null,
                    'room' => $result['room'] ?? null,
                    'online_count' => $result['online_count'] ?? 0,
                ];
            }
            return ['success' => false, 'error' => $result['error'] ?? 'join_failed', 'room' => null];
        }

        $db = $this->db();

        try {
            // If userId not provided, look it up from userUuid
            if ($userId === null) {
                $user = $db->findOne(
                    "SELECT id FROM users WHERE uuid::text = ?",
                    [$userUuid]
                );
                $userId = $user['id'] ?? null;
            }

            if (!$userId) {
                Logger::error('joinRoom: User not found', ['userUuid' => $userUuid]);
                return ['success' => false, 'error' => 'user_not_found', 'room' => null];
            }

            // ENTERPRISE V9.3: Include description and emotion data for UI display
            $room = $db->findOne(
                "SELECT
                    cr.id, cr.uuid::text AS uuid, cr.name, cr.description, cr.room_type, cr.status,
                    cr.max_members, cr.member_count, cr.creator_uuid::text AS creator_uuid,
                    cr.emotion_id, cr.is_ephemeral, cr.last_activity_at, cr.created_at,
                    e.name_it AS emotion_name, e.icon_emoji AS emotion_icon, e.color_hex AS emotion_color
                 FROM chat_rooms cr
                 LEFT JOIN emotions e ON e.id = cr.emotion_id
                 WHERE cr.uuid::text = ?
                   AND cr.status = 'active'
                   AND cr.deleted_at IS NULL",
                [$roomUuid]
            );

            if (!$room) {
                // ENTERPRISE V10.83: Check if room exists but is archived
                $archivedRoom = $db->findOne(
                    "SELECT uuid, status FROM chat_rooms WHERE uuid::text = ?",
                    [$roomUuid]
                );
                if ($archivedRoom && $archivedRoom['status'] === 'archived') {
                    return ['success' => false, 'error' => 'room_archived', 'room' => null];
                }
                return ['success' => false, 'error' => 'room_not_found', 'room' => null];
            }

            $existing = $db->findOne(
                "SELECT m.id, m.is_banned
                 FROM chat_room_members m
                 WHERE m.room_id = ? AND m.user_id = ?",
                [$room['id'], $userId]
            );

            if ($existing) {
                if ($existing['is_banned']) {
                    return ['success' => false, 'error' => 'banned', 'room' => null];
                }

                // ENTERPRISE V10.56: Check online capacity (max 20)
                if (!$this->addToRoomOnline($roomUuid, $userUuid)) {
                    return [
                        'success' => false,
                        'error' => 'room_full_online',
                        'message' => 'Stanza piena! Torna tra qualche minuto...',
                        'room' => null,
                    ];
                }

                // ENTERPRISE V9.3: Include online_count for API response
                $formattedRoom = $this->formatRoomOutput($room);
                return [
                    'success' => true,
                    'error' => null,
                    'room' => $formattedRoom,
                    'online_count' => $formattedRoom['online_count'] ?? 0,
                ];
            }

            if ($room['member_count'] >= $room['max_members']) {
                return ['success' => false, 'error' => 'room_full', 'room' => null];
            }

            if ($room['room_type'] === 'private') {
                $invitation = $db->findOne(
                    "SELECT m.id FROM chat_room_members m
                     WHERE m.room_id = ? AND m.user_id = ? AND m.invited_at IS NOT NULL",
                    [$room['id'], $userId]
                );
                if (!$invitation) {
                    return ['success' => false, 'error' => 'invitation_required', 'room' => null];
                }
            }

            // ENTERPRISE V10.56: Check online capacity BEFORE adding as member
            // This prevents adding member to DB if they can't actually enter the room
            if (!$this->addToRoomOnline($roomUuid, $userUuid)) {
                return [
                    'success' => false,
                    'error' => 'room_full_online',
                    'message' => 'Stanza piena! Torna tra qualche minuto...',
                    'room' => null,
                ];
            }

            $db->beginTransaction();

            $db->execute(
                "INSERT INTO chat_room_members
                 (room_id, user_id, user_uuid, role, joined_at, created_at)
                 VALUES (?, ?, ?::uuid, 'member', NOW(), NOW())
                 ON CONFLICT (room_id, user_id) DO UPDATE SET
                     joined_at = NOW(), is_banned = FALSE",
                [$room['id'], $userId, $userUuid]
            );

            $db->execute(
                "UPDATE chat_rooms
                 SET member_count = member_count + 1, last_activity_at = NOW()
                 WHERE id = ?",
                [$room['id']]
            );

            $db->commit();

            // Note: addToRoomOnline already called before transaction (capacity check)

            $this->publishToRoom($roomUuid, 'user_joined', [
                'room_uuid' => $roomUuid,
                'user_uuid' => $userUuid,
            ]);

            // ENTERPRISE V9.3: Include online_count for API response
            $formattedRoom = $this->formatRoomOutput($room);
            return [
                'success' => true,
                'error' => null,
                'room' => $formattedRoom,
                'online_count' => $formattedRoom['online_count'] ?? 0,
            ];

        } catch (\Exception $e) {
            $db->rollback();
            Logger::error('Failed to join room', [
                'room_uuid' => $roomUuid,
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => 'database_error', 'room' => null];
        }
    }

    /**
     * Leave a room
     *
     * ENTERPRISE V9.0 (2025-12-02): WebSocket-compatible signature
     * Now accepts optional userId - if not provided, looks up from userUuid
     * This allows WebSocket server to call with just (roomUuid, userUuid)
     *
     * @param string $roomUuid Room UUID or emotion:* ID
     * @param string $userUuid User UUID
     * @param int|null $userId Optional user DB ID (will be looked up if null)
     */
    public function leaveRoom(string $roomUuid, string $userUuid, ?int $userId = null): bool
    {
        if (str_starts_with($roomUuid, 'emotion:')) {
            return $this->emotionRoomService->leaveRoom($roomUuid, $userUuid);
        }

        $db = $this->db();

        try {
            $room = $db->findOne(
                "SELECT cr.id FROM chat_rooms cr WHERE cr.uuid::text = ?",
                [$roomUuid]
            );

            if (!$room) {
                return false;
            }

            // If userId not provided, look it up from userUuid
            if ($userId === null) {
                $user = $db->findOne(
                    "SELECT id FROM users WHERE uuid::text = ?",
                    [$userUuid]
                );
                $userId = $user['id'] ?? null;
            }

            if (!$userId) {
                return false;
            }

            $db->beginTransaction();

            $db->execute(
                "DELETE FROM chat_room_members WHERE room_id = ? AND user_id = ?",
                [$room['id'], $userId]
            );

            $db->execute(
                "UPDATE chat_rooms SET member_count = GREATEST(member_count - 1, 0) WHERE id = ?",
                [$room['id']]
            );

            $db->commit();

            $this->removeFromRoomOnline($roomUuid, $userUuid);

            // Note: user_left broadcast handled by WebSocket server with online_count

            return true;

        } catch (\Exception $e) {
            $db->rollback();
            Logger::error('Failed to leave room', [
                'room_uuid' => $roomUuid,
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send message to room (ephemeral, Redis only)
     * Includes content moderation
     *
     * ENTERPRISE V9.2 (2025-12-06): Standardized return format
     * Returns consistent structure like joinRoom/leaveRoom for API uniformity
     *
     * @return array ['success' => bool, 'message' => array|null, 'error' => string|null, 'blocked' => bool]
     */
    public function sendMessage(
        string $roomUuid,
        string $senderUuid,
        string $senderNickname,
        string $content,
        string $messageType = 'text',
        array $extra = []
    ): array {
        // Handle emotion rooms
        if (str_starts_with($roomUuid, 'emotion:')) {
            $modResult = $this->moderationService->checkContent($content, true);

            if ($modResult['is_blocked']) {
                return [
                    'success' => false,
                    'message' => null,
                    'error' => 'Content blocked by moderation',
                    'blocked' => true,
                ];
            }

            $filteredContent = $modResult['is_filtered'] ? $modResult['filtered'] : $content;

            $message = $this->emotionRoomService->addMessage(
                $roomUuid,
                $senderUuid,
                $senderNickname,
                $filteredContent,
                $messageType,
                $extra
            );

            if ($message) {
                $message['is_filtered'] = $modResult['is_filtered'];
                // ENTERPRISE V9.2: Wrap message for ChatManager compatibility
                // ChatManager #handleRoomMessage expects { room_id, message }
                $this->publishToRoom($roomUuid, 'room_message', [
                    'room_id' => $roomUuid,
                    'message' => $message,
                ]);

                return [
                    'success' => true,
                    'message' => $message,
                    'error' => null,
                    'blocked' => false,
                ];
            }

            return [
                'success' => false,
                'message' => null,
                'error' => 'Failed to store message in emotion room',
                'blocked' => false,
            ];
        }

        // Handle user-created rooms - check moderation first (before Redis)
        $modResult = $this->moderationService->checkContent($content, true);

        if ($modResult['is_blocked']) {
            return [
                'success' => false,
                'message' => null,
                'error' => 'Content blocked by moderation',
                'blocked' => true,
            ];
        }

        $filteredContent = $modResult['is_filtered'] ? $modResult['filtered'] : $content;

        // Prepare message data before Redis connection
        $messageId = $this->generateMessageId();
        $timestamp = microtime(true);

        $message = [
            'id' => $messageId,
            'room_uuid' => $roomUuid,
            'sender_uuid' => $senderUuid,
            'sender_nickname' => $senderNickname,
            'content' => $filteredContent,
            'type' => $messageType,
            'created_at' => $timestamp,
            'created_at_formatted' => date('H:i', (int) $timestamp),
            'is_filtered' => $modResult['is_filtered'],
        ];

        // ENTERPRISE V10.53: Add sender_avatar for frontend message rendering
        // Without this, MessageList.js falls back to first letter avatar
        // Matches EmotionRoomService::addMessage() behavior for consistency
        if (!empty($extra['sender_avatar'])) {
            $message['sender_avatar'] = $extra['sender_avatar'];
        }

        if ($messageType === 'audio' && !empty($extra)) {
            $message['audio_url'] = $extra['audio_url'] ?? null;
            $message['duration_seconds'] = $extra['duration_seconds'] ?? null;
        }

        // ENTERPRISE V9.3: Use withRedisConnection for guaranteed pool release
        $result = $this->withRedisConnection('chat', function (\Redis $redis) use ($roomUuid, $message, $timestamp) {
            try {
                $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE);
                $messagesKey = self::PREFIX_MESSAGES . $roomUuid . self::SUFFIX_MESSAGES;

                $redis->zAdd($messagesKey, $timestamp, $messageJson);
                $redis->zRemRangeByRank($messagesKey, 0, -(self::MAX_MESSAGES_PER_ROOM + 1));
                $redis->expire($messagesKey, self::MESSAGE_TTL_SECONDS);

                return ['success' => true, 'error' => null];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }, ['success' => false, 'error' => 'Redis connection unavailable']);

        if (!$result['success']) {
            Logger::error('Failed to send room message', [
                'room_uuid' => $roomUuid,
                'sender_uuid' => $senderUuid,
                'error' => $result['error'],
            ]);

            return [
                'success' => false,
                'message' => null,
                'error' => $result['error'],
                'blocked' => false,
            ];
        }

        $this->updateRoomActivity($roomUuid);

        // ENTERPRISE V9.2: Wrap message for ChatManager compatibility
        // ChatManager #handleRoomMessage expects { room_id, message }
        $this->publishToRoom($roomUuid, 'room_message', [
            'room_id' => $roomUuid,
            'message' => $message,
        ]);

        return [
            'success' => true,
            'message' => $message,
            'error' => null,
            'blocked' => false,
        ];
    }

    /**
     * Get messages from room
     *
     * ENTERPRISE V9.3: Uses withRedisConnection for guaranteed pool release
     * ENTERPRISE V12.2: Enriches messages with CURRENT avatars from database
     *                   (fixes stale avatar issue when user changes photo)
     */
    public function getMessages(string $roomUuid, int $limit = 50): array
    {
        if (str_starts_with($roomUuid, 'emotion:')) {
            return $this->emotionRoomService->getMessages($roomUuid, $limit);
        }

        $messages = $this->withRedisConnection('chat', function (\Redis $redis) use ($roomUuid, $limit) {
            try {
                $messagesKey = self::PREFIX_MESSAGES . $roomUuid . self::SUFFIX_MESSAGES;
                $rawMessages = $redis->zRevRange($messagesKey, 0, $limit - 1, true);

                if (empty($rawMessages)) {
                    return [];
                }

                $messages = [];
                foreach ($rawMessages as $messageJson => $score) {
                    $message = json_decode($messageJson, true);
                    if ($message) {
                        // ENTERPRISE V10.88: Censored messages - show but hide content
                        // Security: Original content is NEVER sent to client
                        if (isset($message['deleted']) && $message['deleted'] === true) {
                            $messages[] = [
                                'id' => $message['id'] ?? $message['uuid'] ?? null,
                                'uuid' => $message['uuid'] ?? $message['id'] ?? null,
                                'sender_uuid' => $message['sender_uuid'] ?? null,
                                'sender_nickname' => $message['sender_nickname'] ?? 'Utente',
                                'content' => null, // SECURITY: Never expose deleted content
                                'type' => 'text',
                                'deleted' => true,
                                'deleted_by' => $message['deleted_by'] ?? 'moderator',
                                'created_at' => $message['created_at'] ?? null,
                            ];
                        } else {
                            $messages[] = $message;
                        }
                    }
                }

                return $messages;
            } catch (\Exception $e) {
                Logger::warning('Failed to get room messages', [
                    'room_uuid' => $roomUuid,
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        }, []);

        // ENTERPRISE V12.2: Enrich messages with current avatars
        // This ensures users see updated avatars even for old messages
        return $this->enrichMessagesWithCurrentAvatars($messages);
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
            Logger::warning('Failed to fetch current avatars for chat messages', [
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
     * Get online users in room
     * ENTERPRISE V9.3: Uses withRedisConnection for guaranteed pool release
     */
    public function getOnlineUsers(string $roomUuid): array
    {
        if (str_starts_with($roomUuid, 'emotion:')) {
            return $this->emotionRoomService->getOnlineUsers($roomUuid);
        }

        return $this->withRedisConnection('chat', function (\Redis $redis) use ($roomUuid) {
            try {
                $onlineKey = self::PREFIX_ONLINE . $roomUuid . self::SUFFIX_ONLINE;
                return $redis->sMembers($onlineKey) ?: [];
            } catch (\Exception $e) {
                return [];
            }
        }, []);
    }

    /**
     * Get online count for room
     *
     * ENTERPRISE V9.3 (2025-12-06): Uses withRedisConnection for guaranteed pool release
     * Returns the count of online users in a room (Redis SCARD)
     *
     * @param string $roomUuid Room UUID or emotion:* ID
     * @return int Online user count
     */
    public function getOnlineCount(string $roomUuid): int
    {
        if (str_starts_with($roomUuid, 'emotion:')) {
            return $this->emotionRoomService->getOnlineCount($roomUuid);
        }

        return $this->withRedisConnection('chat', function (\Redis $redis) use ($roomUuid) {
            try {
                $onlineKey = self::PREFIX_ONLINE . $roomUuid . self::SUFFIX_ONLINE;
                return (int) ($redis->sCard($onlineKey) ?: 0);
            } catch (\Exception $e) {
                return 0;
            }
        }, 0);
    }

    /**
     * Delete room (creator only)
     */
    public function deleteRoom(string $roomUuid, string $adminUuid): bool
    {
        $db = $this->db();

        try {
            $room = $db->findOne(
                "SELECT cr.id, cr.creator_uuid::text AS creator_uuid
                 FROM chat_rooms cr
                 WHERE cr.uuid::text = ? AND cr.deleted_at IS NULL",
                [$roomUuid]
            );

            if (!$room || $room['creator_uuid'] !== $adminUuid) {
                return false;
            }

            $db->execute(
                "UPDATE chat_rooms SET status = 'deleted', deleted_at = NOW() WHERE id = ?",
                [$room['id']]
            );

            $this->cleanupRoomRedis($roomUuid);

            $this->publishToRoom($roomUuid, 'room_closed', [
                'room_uuid' => $roomUuid,
                'reason' => 'deleted',
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('Failed to delete room', [
                'room_uuid' => $roomUuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if user is member of room
     */
    public function isMember(string $roomUuid, string $userUuid): bool
    {
        if (str_starts_with($roomUuid, 'emotion:')) {
            return true;
        }

        $db = $this->db();

        $member = $db->findOne(
            "SELECT m.id
             FROM chat_room_members m
             INNER JOIN chat_rooms cr ON cr.id = m.room_id
             WHERE cr.uuid::text = ?
               AND m.user_uuid::text = ?
               AND m.is_banned = FALSE",
            [$roomUuid, $userUuid],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return $member !== null;
    }

    /**
     * Get user's role in room
     */
    public function getUserRole(string $roomUuid, string $userUuid): ?string
    {
        if (str_starts_with($roomUuid, 'emotion:')) {
            return 'member';
        }

        $db = $this->db();

        $member = $db->findOne(
            "SELECT m.role
             FROM chat_room_members m
             INNER JOIN chat_rooms cr ON cr.id = m.room_id
             WHERE cr.uuid::text = ?
               AND m.user_uuid::text = ?
               AND m.is_banned = FALSE",
            [$roomUuid, $userUuid]
        );

        return $member['role'] ?? null;
    }

    /**
     * Format room data for API output
     * ENTERPRISE V9.3: Uses withRedisConnection for guaranteed pool release
     */
    private function formatRoomOutput(array $room): array
    {
        $uuid = $room['uuid'] ?? '';

        // ENTERPRISE V9.3: Guaranteed connection release
        $onlineCount = $this->withRedisConnection('chat', function (\Redis $redis) use ($uuid) {
            try {
                $onlineKey = self::PREFIX_ONLINE . $uuid . self::SUFFIX_ONLINE;
                return (int) ($redis->sCard($onlineKey) ?: 0);
            } catch (\Exception $e) {
                return 0;
            }
        }, 0);

        return [
            'id' => $room['id'] ?? null,
            'uuid' => $room['uuid'] ?? null,
            'name' => $room['name'] ?? null,
            'description' => $room['description'] ?? null,
            'room_type' => $room['room_type'] ?? 'user_created',
            'status' => $room['status'] ?? 'active',
            'creator_uuid' => $room['creator_uuid'] ?? null,
            'creator_name' => $room['creator_name'] ?? null,
            'creator_avatar' => $room['creator_avatar'] ?? null,
            'max_members' => $room['max_members'] ?? self::DEFAULT_MAX_MEMBERS,
            'member_count' => $room['member_count'] ?? 0,
            'online_count' => $onlineCount,
            'is_ephemeral' => $room['is_ephemeral'] ?? true,
            'last_activity_at' => $room['last_activity_at'] ?? null,
            'created_at' => $room['created_at'] ?? null,
            'user_role' => $room['role'] ?? null,
            'type' => 'user_created',
            // Emotion data
            'emotion_id' => $room['emotion_id'] ?? null,
            'emotion_name' => $room['emotion_name'] ?? null,
            'emotion_icon' => $room['emotion_icon'] ?? null,
            'emotion_color' => $room['emotion_color'] ?? null,
        ];
    }

    /**
     * Initialize room metadata in Redis
     * ENTERPRISE V9.3: Uses withRedisConnection for guaranteed pool release
     */
    private function initializeRoomRedis(string $roomUuid, string $name, string $creatorUuid): void
    {
        $this->withRedisConnection('chat', function (\Redis $redis) use ($roomUuid, $name, $creatorUuid) {
            try {
                $metaKey = self::PREFIX_META . $roomUuid . self::SUFFIX_META;
                $redis->hMSet($metaKey, [
                    'name' => $name,
                    'creator_uuid' => $creatorUuid,
                    'created_at' => time(),
                ]);
                $redis->expire($metaKey, self::MESSAGE_TTL_SECONDS);
            } catch (\Exception $e) {
                Logger::warning('Failed to initialize room Redis', [
                    'room_uuid' => $roomUuid,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Add user to room online set
     *
     * ENTERPRISE V10.56 (2025-12-07): Added capacity check and activity tracking
     * - Returns false if room is at capacity (20 online users)
     * - Returns true if user was added or already in room
     * - Tracks last activity timestamp in Redis HASH for inactivity kicks
     *
     * @param string $roomUuid Room UUID
     * @param string $userUuid User UUID
     * @return bool True if user can join (added or already present), false if room full
     */
    private function addToRoomOnline(string $roomUuid, string $userUuid): bool
    {
        $result = false;

        $this->withRedisConnection('chat', function (\Redis $redis) use ($roomUuid, $userUuid, &$result) {
            try {
                $onlineKey = self::PREFIX_ONLINE . $roomUuid . self::SUFFIX_ONLINE;
                $activityKey = self::PREFIX_ONLINE . $roomUuid . self::SUFFIX_ACTIVITY;

                // Check if user already in room (always allow re-entry)
                if ($redis->sIsMember($onlineKey, $userUuid)) {
                    // Update activity timestamp
                    $redis->hSet($activityKey, $userUuid, (string) time());
                    $redis->expire($activityKey, self::INACTIVITY_KICK_SECONDS + 300); // 1h + 5min buffer
                    $result = true;
                    return;
                }

                // Check room capacity
                $currentCount = (int) $redis->sCard($onlineKey);
                if ($currentCount >= self::MAX_ONLINE_PER_ROOM) {
                    $result = false;
                    return;
                }

                // Add to online set
                $redis->sAdd($onlineKey, $userUuid);
                $redis->expire($onlineKey, 3600); // 1 hour TTL (matches inactivity kick)

                // Track activity timestamp
                $redis->hSet($activityKey, $userUuid, (string) time());
                $redis->expire($activityKey, self::INACTIVITY_KICK_SECONDS + 300);

                $result = true;
            } catch (\Exception $e) {
                Logger::warning('addToRoomOnline failed', [
                    'room_uuid' => $roomUuid,
                    'user_uuid' => $userUuid,
                    'error' => $e->getMessage(),
                ]);
                $result = false;
            }
        });

        return $result;
    }

    /**
     * Remove user from room online set
     * ENTERPRISE V9.3: Uses withRedisConnection for guaranteed pool release
     */
    private function removeFromRoomOnline(string $roomUuid, string $userUuid): void
    {
        $this->withRedisConnection('chat', function (\Redis $redis) use ($roomUuid, $userUuid) {
            try {
                $onlineKey = self::PREFIX_ONLINE . $roomUuid . self::SUFFIX_ONLINE;
                $redis->sRem($onlineKey, $userUuid);
            } catch (\Exception $e) {
                // Ignore - non-critical
            }
        });
    }

    private function updateRoomActivity(string $roomUuid): void
    {
        $db = $this->db();

        try {
            $db->execute(
                "UPDATE chat_rooms SET last_activity_at = NOW() WHERE uuid::text = ?",
                [$roomUuid],
                ['cache' => false]
            );
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Cleanup room data from Redis
     * ENTERPRISE V9.3: Uses withRedisConnection for guaranteed pool release
     */
    private function cleanupRoomRedis(string $roomUuid): void
    {
        $this->withRedisConnection('chat', function (\Redis $redis) use ($roomUuid) {
            try {
                $redis->del(self::PREFIX_MESSAGES . $roomUuid . self::SUFFIX_MESSAGES);
                $redis->del(self::PREFIX_META . $roomUuid . self::SUFFIX_META);
                $redis->del(self::PREFIX_ONLINE . $roomUuid . self::SUFFIX_ONLINE);
            } catch (\Exception $e) {
                Logger::warning('Failed to cleanup room Redis', [
                    'room_uuid' => $roomUuid,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function generateMessageId(): string
    {
        return sprintf(
            'msg_%s_%s',
            base_convert((string) hrtime(true), 10, 36),
            bin2hex(random_bytes(4))
        );
    }
}
