<?php

declare(strict_types=1);

namespace Need2Talk\Services;

use Need2Talk\Services\WebSocketPublisher;
use Need2Talk\Services\AsyncNotificationQueue;

/**
 * NotificationService - Enterprise Notification System V11.6
 *
 * Handles user notifications with WebSocket real-time delivery.
 *
 * NOTIFICATION TYPES:
 * - new_comment: Someone commented on your post
 * - comment_reply: Someone replied to your comment
 * - new_reaction: Someone reacted to your post
 * - comment_liked: Someone liked your comment
 * - mentioned: You were mentioned in a comment/post
 * - friend_request: Someone sent you a friend request
 * - friend_accepted: Someone accepted your friend request
 *
 * ARCHITECTURE V11.6 (ASYNC MODE - DEFAULT):
 * 1. enqueue() → Redis ZADD (0.1ms) → Return immediately
 * 2. Worker → Batch process → DB INSERT batch → WebSocket batch
 * 3. Deduplication: 50 reactions → 1 aggregated notification
 *
 * ARCHITECTURE (SYNC MODE - FALLBACK):
 * 1. Create notification in DB (persistence)
 * 2. Push via WebSocket (real-time)
 * 3. Frontend receives and updates bell badge
 *
 * ASYNC BENEFITS:
 * - HTTP response time: ~15ms → ~0.5ms
 * - DB queries: N → 1 batch
 * - WebSocket: N → aggregated
 * - Scalability: Linear → Sublinear
 *
 * @package Need2Talk\Services
 */
class NotificationService
{
    private static ?self $instance = null;
    private static ?AsyncNotificationQueue $asyncQueue = null;

    // Async mode flag (can be changed at runtime or via env)
    private static bool $asyncEnabled = true;

    // Notification types
    public const TYPE_NEW_COMMENT = 'new_comment';
    public const TYPE_COMMENT_REPLY = 'comment_reply';
    public const TYPE_NEW_REACTION = 'new_reaction';
    public const TYPE_COMMENT_LIKED = 'comment_liked';
    public const TYPE_MENTIONED = 'mentioned';
    public const TYPE_FRIEND_REQUEST = 'friend_request';
    public const TYPE_FRIEND_ACCEPTED = 'friend_accepted';
    // ENTERPRISE GALAXY v9.5: DM notifications
    public const TYPE_DM_RECEIVED = 'dm_received';
    public const TYPE_DM_MI_HA_CERCATO = 'dm_mi_ha_cercato'; // "Someone tried to reach you"
    // ENTERPRISE V11.6: Room invite notification
    public const TYPE_ROOM_INVITE = 'room_invite';

    // Target types
    public const TARGET_POST = 'post';
    public const TARGET_COMMENT = 'comment';
    public const TARGET_DM = 'dm_conversation';

    // Types that should always be sync (critical, time-sensitive)
    private const SYNC_ONLY_TYPES = [
        self::TYPE_FRIEND_REQUEST,  // User expects immediate feedback
        self::TYPE_FRIEND_ACCEPTED, // User expects immediate feedback
    ];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();

            // Initialize async mode from env (default: true)
            self::$asyncEnabled = (bool)($_ENV['NOTIFICATION_ASYNC_ENABLED'] ?? true);
        }
        return self::$instance;
    }

    /**
     * Enable async notification processing
     */
    public static function enableAsync(): void
    {
        self::$asyncEnabled = true;
    }

    /**
     * Disable async notification processing (fallback to sync)
     */
    public static function disableAsync(): void
    {
        self::$asyncEnabled = false;
    }

    /**
     * Check if async mode is enabled
     */
    public static function isAsyncEnabled(): bool
    {
        return self::$asyncEnabled;
    }

    /**
     * Get the async queue instance
     */
    private function getAsyncQueue(): AsyncNotificationQueue
    {
        if (self::$asyncQueue === null) {
            self::$asyncQueue = new AsyncNotificationQueue();
        }
        return self::$asyncQueue;
    }

    /**
     * Create a notification and push via WebSocket
     *
     * ENTERPRISE GALAXY V11.6: Async queue support with intelligent routing
     *
     * ROUTING LOGIC:
     * - SYNC_ONLY_TYPES (friend_request, friend_accepted) → Always sync
     * - Async enabled + non-critical type → Queue for batch processing
     * - Async disabled → Sync processing (legacy behavior)
     *
     * @param int $userId User to notify
     * @param string $type Notification type
     * @param int|null $actorId User who triggered the notification
     * @param string|null $targetType Target type (post, comment)
     * @param int|null $targetId Target ID
     * @param array $data Additional data
     * @param bool $forceSync Force synchronous processing
     * @return int|null Notification ID or null on failure (null for async = queued)
     */
    public function create(
        int $userId,
        string $type,
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $data = [],
        bool $forceSync = false
    ): ?int {
        // Don't notify yourself
        if ($actorId !== null && $userId === $actorId) {
            return null;
        }

        // ENTERPRISE GALAXY V11.6: Route to async or sync based on type and config
        $shouldUseAsync = self::$asyncEnabled
            && !$forceSync
            && !in_array($type, self::SYNC_ONLY_TYPES);

        if ($shouldUseAsync) {
            return $this->createAsync($userId, $type, $actorId, $targetType, $targetId, $data);
        }

        return $this->createSync($userId, $type, $actorId, $targetType, $targetId, $data);
    }

    /**
     * Create notification asynchronously via Redis queue
     *
     * PERFORMANCE: O(1) Redis ZADD, ~0.1ms latency
     * HTTP response returns immediately, worker processes in background
     *
     * @return int|null Returns 0 on success (queued), null on failure
     */
    private function createAsync(
        int $userId,
        string $type,
        ?int $actorId,
        ?string $targetType,
        ?int $targetId,
        array $data
    ): ?int {
        try {
            $queue = $this->getAsyncQueue();

            // Determine priority based on type
            $priority = match ($type) {
                self::TYPE_DM_RECEIVED,
                self::TYPE_DM_MI_HA_CERCATO => AsyncNotificationQueue::PRIORITY_HIGH,
                self::TYPE_NEW_COMMENT,
                self::TYPE_COMMENT_REPLY,
                self::TYPE_MENTIONED => AsyncNotificationQueue::PRIORITY_NORMAL,
                default => AsyncNotificationQueue::PRIORITY_LOW,
            };

            $jobId = $queue->enqueue([
                'user_id' => $userId,
                'type' => $type,
                'actor_id' => $actorId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'data' => $data,
                'priority' => $priority,
            ]);

            if ($jobId !== false) {
                // Return 0 to indicate "queued successfully"
                // Actual notification ID will be assigned by worker
                return 0;
            }

            // Queue failed, fallback to sync
            Logger::warning('NotificationService: Async queue failed, falling back to sync', [
                'user_id' => $userId,
                'type' => $type,
            ]);

            return $this->createSync($userId, $type, $actorId, $targetType, $targetId, $data);

        } catch (\Exception $e) {
            Logger::error('NotificationService::createAsync failed', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            // Fallback to sync on error
            return $this->createSync($userId, $type, $actorId, $targetType, $targetId, $data);
        }
    }

    /**
     * Create notification synchronously (original behavior)
     *
     * Used for:
     * - SYNC_ONLY_TYPES (friend_request, friend_accepted)
     * - When async is disabled
     * - Fallback when Redis queue fails
     */
    private function createSync(
        int $userId,
        string $type,
        ?int $actorId,
        ?string $targetType,
        ?int $targetId,
        array $data
    ): ?int {
        // ENTERPRISE GALAXY V11.8: Log sync mode usage
        // NOTE: SYNC_ONLY_TYPE is NORMAL for friend_request/friend_accepted (time-sensitive)
        // Only FALLBACK reason indicates a potential issue (workers down or Redis failing)
        $isSyncOnlyType = in_array($type, self::SYNC_ONLY_TYPES);
        $reason = $isSyncOnlyType ? 'SYNC_ONLY_TYPE' : (self::$asyncEnabled ? 'FALLBACK' : 'ASYNC_DISABLED');

        // Use notice for expected sync types, warning only for fallback (potential issue)
        $logMessage = $isSyncOnlyType
            ? "NotificationService: Sync delivery (expected for {$type})"
            : "NotificationService: Sync fallback (check workers)";

        if ($reason === 'FALLBACK') {
            Logger::warning($logMessage, [
                'reason' => $reason,
                'type' => $type,
                'user_id' => $userId,
                'actor_id' => $actorId,
            ]);
        } else {
            Logger::info($logMessage, [
                'reason' => $reason,
                'type' => $type,
                'user_id' => $userId,
            ]);
        }

        // ENTERPRISE GALAXY V4: Check user notification preferences
        if (!$this->shouldNotify($userId, $type)) {
            return null;
        }

        try {
            $db = db();

            // Insert notification
            $db->execute(
                "INSERT INTO notifications (user_id, type, actor_id, target_type, target_id, data)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $type,
                    $actorId,
                    $targetType,
                    $targetId,
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                ]
            );

            $notificationId = (int) $db->lastInsertId();

            // Get actor info for WebSocket push
            $actorData = null;
            if ($actorId) {
                $actorData = $db->findOne(
                    "SELECT uuid, nickname, avatar_url FROM users WHERE id = ?",
                    [$actorId]
                );
            }

            // Get user UUID for WebSocket
            $userUuid = $db->findOne(
                "SELECT uuid FROM users WHERE id = ?",
                [$userId]
            );

            if ($userUuid) {
                // Push notification via WebSocket
                // ENTERPRISE V10.51 (2025-12-05): Include user_uuid for frontend validation
                // Frontend WebSocketManager.handleNotification() validates user_uuid matches
                $this->pushNotification($userUuid['uuid'], [
                    'id' => $notificationId,
                    'notification_id' => $notificationId, // Alias for navbar compatibility
                    'type' => $type,
                    'user_uuid' => $userUuid['uuid'], // CRITICAL: Frontend validation requires this
                    'actor' => $actorData ? [
                        'uuid' => $actorData['uuid'],
                        'nickname' => $actorData['nickname'],
                        // ENTERPRISE V10.141: Normalize avatar URL
                        'avatar_url' => get_avatar_url($actorData['avatar_url'] ?? null),
                    ] : null,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'data' => $data,
                    'created_at' => date('c'),
                ]);
            }

            return $notificationId;

        } catch (\Exception $e) {
            Logger::error('NotificationService::createSync failed', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Push notification via WebSocket
     */
    private function pushNotification(string $userUuid, array $notification): void
    {
        WebSocketPublisher::publishToUser($userUuid, 'notification', $notification);
    }

    /**
     * Check if user wants to receive this type of notification
     *
     * ENTERPRISE GALAXY V4: Respects granular notification preferences
     *
     * @param int $userId User ID to check
     * @param string $type Notification type
     * @return bool True if notification should be sent
     */
    private function shouldNotify(int $userId, string $type): bool
    {
        try {
            $db = db();

            // Map notification type to settings column
            $typeToColumn = [
                self::TYPE_NEW_COMMENT => 'notify_comments',
                self::TYPE_COMMENT_REPLY => 'notify_replies',
                self::TYPE_NEW_REACTION => 'notify_reactions',
                self::TYPE_COMMENT_LIKED => 'notify_comment_likes',
                self::TYPE_MENTIONED => 'notify_mentions',
                self::TYPE_FRIEND_REQUEST => 'notify_friend_requests',
                self::TYPE_FRIEND_ACCEPTED => 'notify_friend_accepted',
                // ENTERPRISE GALAXY v9.5: DM notification preference
                self::TYPE_DM_RECEIVED => 'notify_dm_received',
                self::TYPE_DM_MI_HA_CERCATO => 'notify_dm_received', // Uses same preference
            ];

            $column = $typeToColumn[$type] ?? null;

            // If type not mapped, allow notification (default behavior)
            if ($column === null) {
                return true;
            }

            // Get user preference (cached query)
            $settings = $db->findOne(
                "SELECT {$column} FROM user_settings WHERE user_id = ?",
                [$userId],
                ['cache' => true, 'cache_ttl' => 'medium']
            );

            // If no settings found, default to enabled
            if ($settings === null) {
                return true;
            }

            // Return the boolean preference (default true if column is null)
            return (bool)($settings[$column] ?? true);

        } catch (\Exception $e) {
            // On error, allow notification (fail-open for UX)
            Logger::warning('NotificationService::shouldNotify check failed', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    // =========================================================================
    // CONVENIENCE METHODS FOR EACH NOTIFICATION TYPE
    // =========================================================================

    /**
     * Notify post author of new comment
     */
    public function notifyNewComment(int $postAuthorId, int $commenterId, int $postId, int $commentId, string $commentPreview): ?int
    {
        return $this->create(
            $postAuthorId,
            self::TYPE_NEW_COMMENT,
            $commenterId,
            self::TARGET_POST,
            $postId,
            [
                'comment_id' => $commentId,
                'preview' => mb_substr($commentPreview, 0, 100),
            ]
        );
    }

    /**
     * Notify comment author of reply
     */
    public function notifyCommentReply(int $parentCommentAuthorId, int $replierId, int $postId, int $replyId, string $replyPreview): ?int
    {
        return $this->create(
            $parentCommentAuthorId,
            self::TYPE_COMMENT_REPLY,
            $replierId,
            self::TARGET_COMMENT,
            $replyId,
            [
                'post_id' => $postId,
                'preview' => mb_substr($replyPreview, 0, 100),
            ]
        );
    }

    /**
     * Notify post author of new reaction
     */
    public function notifyNewReaction(int $postAuthorId, int $reactorId, int $postId, int $emotionId): ?int
    {
        return $this->create(
            $postAuthorId,
            self::TYPE_NEW_REACTION,
            $reactorId,
            self::TARGET_POST,
            $postId,
            [
                'emotion_id' => $emotionId,
            ]
        );
    }

    /**
     * Notify comment author of like
     *
     * ENTERPRISE V4.9 (2025-11-30): Added postId for navigation
     */
    public function notifyCommentLiked(int $commentAuthorId, int $likerId, int $commentId, ?int $postId = null): ?int
    {
        return $this->create(
            $commentAuthorId,
            self::TYPE_COMMENT_LIKED,
            $likerId,
            self::TARGET_COMMENT,
            $commentId,
            $postId ? ['post_id' => $postId] : []
        );
    }

    /**
     * Notify user of mention
     *
     * ENTERPRISE V4.14 (2025-11-30): Added postId for navigation from notifications
     *
     * @param int $mentionedUserId User being mentioned
     * @param int $mentionerId User doing the mentioning
     * @param string $targetType 'post' or 'comment'
     * @param int $targetId Post ID or Comment ID
     * @param string $contextPreview Text preview of the mention context
     * @param int|null $postId Post ID (required if targetType is 'comment' for navigation)
     */
    public function notifyMentioned(int $mentionedUserId, int $mentionerId, string $targetType, int $targetId, string $contextPreview, ?int $postId = null): ?int
    {
        $data = [
            'preview' => mb_substr($contextPreview, 0, 100),
        ];

        // ENTERPRISE V4.14: Include post_id for comment mentions (needed for navigation)
        if ($targetType === self::TARGET_COMMENT && $postId !== null) {
            $data['post_id'] = $postId;
        }

        return $this->create(
            $mentionedUserId,
            self::TYPE_MENTIONED,
            $mentionerId,
            $targetType,
            $targetId,
            $data
        );
    }

    /**
     * Notify user of friend request
     */
    public function notifyFriendRequest(int $receiverId, int $senderId): ?int
    {
        return $this->create(
            $receiverId,
            self::TYPE_FRIEND_REQUEST,
            $senderId,
            null,
            null,
            []
        );
    }

    /**
     * Notify user their friend request was accepted
     *
     * ENTERPRISE V6.9 (2025-11-30): Includes new_friend data for FriendsWidget
     * This replaces the duplicate 'friend_request_accepted' WebSocket event
     *
     * @param int $requesterId Original requester (receives notification)
     * @param int $accepterId User who accepted (actor)
     * @param array|null $newFriendData Optional: accepter's data for widget update
     * @return int|null Notification ID
     */
    public function notifyFriendAccepted(int $requesterId, int $accepterId, ?array $newFriendData = null): ?int
    {
        return $this->create(
            $requesterId,
            self::TYPE_FRIEND_ACCEPTED,
            $accepterId,
            null,
            null,
            $newFriendData ? ['new_friend' => $newFriendData] : []
        );
    }

    /**
     * Notify user of new DM received
     *
     * ENTERPRISE GALAXY v9.5: DM Notification System
     * Creates a bell notification when a DM is received.
     * WebSocket push is handled separately in sendMessage for real-time delivery.
     *
     * ENTERPRISE GALAXY v9.9: Rate Limiting (10 min per sender)
     * If A receives messages from B, A gets ONE notification per 10 minutes from B
     * even if B sends 100 messages. Each sender has independent rate limit.
     *
     * @param int $recipientId User receiving the DM (user ID)
     * @param int $senderId User who sent the DM (actor)
     * @param string $conversationUuid Conversation UUID for navigation
     * @param string $preview Message preview (truncated)
     * @return int|null Notification ID
     */
    public function notifyDmReceived(int $recipientId, int $senderId, string $conversationUuid, string $preview): ?int
    {
        // ENTERPRISE GALAXY v9.9: Per-sender rate limiting (10 min cooldown)
        // Key format: dm:notif:rate:{recipient}:{sender}
        if (!$this->checkDmNotificationRateLimit($recipientId, $senderId)) {
            return null; // Skip notification, but message was already delivered via WebSocket
        }

        return $this->create(
            $recipientId,
            self::TYPE_DM_RECEIVED,
            $senderId,
            self::TARGET_DM,
            null, // target_id is null, we use conversation_uuid in data
            [
                'conversation_uuid' => $conversationUuid,
                'preview' => mb_substr($preview, 0, 100),
            ]
        );
    }

    /**
     * Check and set DM notification rate limit
     *
     * ENTERPRISE GALAXY v9.9: Per-sender DM Notification Rate Limiting
     *
     * Each recipient-sender pair has a 10-minute cooldown:
     * - If A receives messages from B: 1 notification per 10 min
     * - If A receives messages from C: separate 10 min cooldown
     * - If A receives messages from D: separate 10 min cooldown
     *
     * This uses Redis SETNX pattern for atomic check-and-set.
     *
     * @param int $recipientId User receiving notification
     * @param int $senderId User who triggered notification
     * @return bool True if notification should be sent, false if rate limited
     */
    private function checkDmNotificationRateLimit(int $recipientId, int $senderId): bool
    {
        // Rate limit: 10 minutes = 600 seconds
        $rateLimitSeconds = 600;

        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $connection = $redis->getConnection('rate_limit');

            if (!$connection) {
                // Redis unavailable - allow notification (fail-open for UX)
                Logger::warning('DM notification rate limit: Redis unavailable');
                return true;
            }

            $key = "dm:notif:rate:{$recipientId}:{$senderId}";

            // Use SETNX + EXPIRE pattern for atomic rate limiting
            // SETNX returns true if key was set (not existing), false if already exists
            $wasSet = $connection->set($key, time(), ['NX', 'EX' => $rateLimitSeconds]);

            // Key was set = no existing rate limit = allow notification
            // Key exists = already sent notification in last 10 min = rate limited
            return (bool) $wasSet;

        } catch (\Exception $e) {
            Logger::warning('DM notification rate limit check failed', [
                'recipient' => $recipientId,
                'sender' => $senderId,
                'error' => $e->getMessage(),
            ]);
            // On error, allow notification (fail-open)
            return true;
        }
    }

    /**
     * Notify user that someone tried to reach them (offline notification)
     *
     * ENTERPRISE GALAXY v9.5: "Mi ha cercato" notification
     * Used when recipient is offline and cannot receive real-time WebSocket.
     * This notification persists and shows when user logs back in.
     *
     * ENTERPRISE GALAXY v9.9: Rate Limiting (10 min per sender)
     * Same rate limit as notifyDmReceived - prevents spam notifications
     *
     * @param int $recipientId User who was searched for (user ID)
     * @param int $senderId User who tried to reach them (actor)
     * @param string $conversationUuid Conversation UUID for navigation
     * @return int|null Notification ID
     */
    public function notifyDmMiHaCercato(int $recipientId, int $senderId, string $conversationUuid): ?int
    {
        // ENTERPRISE GALAXY v9.9: Use same rate limit as notifyDmReceived
        // This ensures consistency - 1 notification per sender per 10 min regardless of type
        if (!$this->checkDmNotificationRateLimit($recipientId, $senderId)) {
            return null;
        }

        return $this->create(
            $recipientId,
            self::TYPE_DM_MI_HA_CERCATO,
            $senderId,
            self::TARGET_DM,
            null,
            [
                'conversation_uuid' => $conversationUuid,
            ]
        );
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    /**
     * Get notifications for user
     *
     * ENTERPRISE V11 FIX: NO CACHING - read_at status must always be fresh!
     *
     * @param int $userId User ID
     * @param int $limit Max notifications
     * @param int $offset Offset for pagination
     * @param bool $unreadOnly Only unread notifications
     * @return array Notifications with actor info
     */
    public function getForUser(int $userId, int $limit = 20, int $offset = 0, bool $unreadOnly = false): array
    {
        try {
            $db = db();

            $whereClause = $unreadOnly ? "AND n.read_at IS NULL" : "";

            $notifications = $db->findMany(
                "SELECT
                    n.id,
                    n.type,
                    n.target_type,
                    n.target_id,
                    n.data,
                    n.read_at,
                    n.created_at,
                    u.uuid as actor_uuid,
                    u.nickname as actor_nickname,
                    u.avatar_url as actor_avatar
                 FROM notifications n
                 LEFT JOIN users u ON u.id = n.actor_id
                 WHERE n.user_id = ? {$whereClause}
                 ORDER BY n.created_at DESC
                 LIMIT ? OFFSET ?",
                [$userId, $limit, $offset],
                ['cache' => false] // CRITICAL: Never cache - read_at changes frequently
            );

            // Parse JSON data
            return array_map(function ($n) {
                $n['data'] = json_decode($n['data'] ?? '{}', true);
                return $n;
            }, $notifications);

        } catch (\Exception $e) {
            Logger::error('NotificationService::getForUser failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get unread count for user
     *
     * ENTERPRISE V11 FIX: NO CACHING - unread count must always be fresh!
     * This was causing stale badge counts after read_at was set.
     */
    public function getUnreadCount(int $userId): int
    {
        try {
            $db = db();
            $result = $db->findOne(
                "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND read_at IS NULL",
                [$userId],
                ['cache' => false] // CRITICAL: Never cache unread count!
            );
            return (int) ($result['count'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        try {
            $db = db();
            $db->execute(
                "UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?",
                [$notificationId, $userId]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Mark all notifications as read for user
     */
    public function markAllAsRead(int $userId): bool
    {
        try {
            $db = db();
            $db->execute(
                "UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL",
                [$userId]
            );

            // Push update via WebSocket
            $userUuid = $db->findOne("SELECT uuid FROM users WHERE id = ?", [$userId]);
            if ($userUuid) {
                WebSocketPublisher::publishToUser($userUuid['uuid'], 'notifications_read_all', []);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete notification
     */
    public function delete(int $notificationId, int $userId): bool
    {
        try {
            $db = db();
            $db->execute(
                "DELETE FROM notifications WHERE id = ? AND user_id = ?",
                [$notificationId, $userId]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete old notifications (cleanup job)
     *
     * @param int $daysOld Delete notifications older than X days
     * @return int Number of deleted notifications
     */
    public function deleteOld(int $daysOld = 30): int
    {
        try {
            $db = db();
            $result = $db->execute(
                "DELETE FROM notifications WHERE created_at < NOW() - INTERVAL '? days'",
                [$daysOld]
            );
            return $result;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
