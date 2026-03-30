<?php

declare(strict_types=1);

namespace Need2Talk\Repositories\Audio;

use Need2Talk\Core\Database;
use Need2Talk\Services\Logger;

/**
 * Comment Repository - ENTERPRISE GALAXY ARCHITECTURE
 *
 * DATABASE LAYER: audio_comments + comment_likes tables
 * PERFORMANCE TARGET: 100,000+ concurrent users, <5ms queries
 *
 * ARCHITECTURE:
 * - audio_comments: Comment storage with parent_comment_id for 1-level replies
 * - comment_likes: User likes on comments (user_id, comment_id PK)
 *
 * COVERING INDEXES:
 * - idx_audio_comments_post_covering: audio_post_id, status, created_at DESC, user_id, like_count, reply_count
 * - idx_audio_comments_parent_active: parent_comment_id, status, created_at, user_id
 * - idx_comment_likes_comment: comment_id, created_at DESC
 *
 * OVERLAY INTEGRATION:
 * - Counters (like_count, reply_count) may have overlay deltas
 * - Tombstones for soft-deleted comments
 * - User likes tracked in personal overlay
 *
 * @package Need2Talk\Repositories\Audio
 */
class CommentRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * Create new comment
     *
     * @param array $data Comment data
     * @return int|false Insert ID or false on failure
     */
    public function create(array $data): int|false
    {
        try {
            $sql = "INSERT INTO audio_comments (
                uuid,
                audio_post_id,
                audio_file_id,
                user_id,
                user_uuid,
                parent_comment_id,
                comment_text,
                mentioned_users,
                status,
                created_at,
                updated_at
            ) VALUES (
                gen_random_uuid()::text,
                :audio_post_id,
                :audio_file_id,
                :user_id,
                :user_uuid,
                :parent_comment_id,
                :comment_text,
                :mentioned_users,
                'active',
                NOW(),
                NOW()
            )";

            $params = [
                'audio_post_id' => $data['audio_post_id'] ?? null,
                'audio_file_id' => $data['audio_file_id'] ?? null,
                'user_id' => $data['user_id'],
                'user_uuid' => $data['user_uuid'] ?? null,
                'parent_comment_id' => $data['parent_comment_id'] ?? null,
                'comment_text' => $data['comment_text'],
                'mentioned_users' => isset($data['mentioned_users'])
                    ? json_encode($data['mentioned_users'])
                    : null,
            ];

            // ENTERPRISE V4.4: Granular TaggedQueryCache invalidation
            // Only invalidates comments for THIS specific post, not all posts
            $postId = $data['audio_post_id'];
            $insertId = $this->db->execute($sql, $params, [
                'invalidate_cache' => [
                    "post_comments:{$postId}", // Granular: only this post's comments
                ],
                'return_id' => true,
            ]);

            if (!$insertId) {
                return false;
            }

            // ENTERPRISE V11 (2025-12-11): ABSOLUTE VALUE OVERLAY for comment count
            // Uses "overlay wins" pattern like reactions - no timing bugs!
            //
            // V6 Delta Bug: After flush, delta=0 but cached base stale → WRONG count
            // V11 Fix: Store ABSOLUTE count in overlay, delete on flush → correct!
            $parentCommentId = $data['parent_comment_id'] ?? null;
            $userId = $data['user_id'];

            // V11: Increment ABSOLUTE comment count in overlay (+1 for new comment)
            $overlayService = \Need2Talk\Services\Cache\OverlayService::getInstance();

            if ($overlayService->isAvailable()) {
                $overlayService->incrementCommentAbsolute($postId, 1);
            }

            // ENTERPRISE V10.9: Broadcast comment count update to post viewers (real-time)
            \Need2Talk\Services\Cache\CounterBroadcastService::getInstance()
                ->broadcastCommentCount($postId, 1);

            // ENTERPRISE V10.153 (2025-12-09): reply_count handled by WriteBehindBuffer
            // Buffer event already written by CommentService::bufferNewComment()
            // flushComments() will update reply_count asynchronously
            // This avoids direct DB writes during request → better for high concurrency

            return (int) $insertId;

        } catch (\Exception $e) {
            Logger::error('CommentRepository::create failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            return false;
        }
    }

    /**
     * Get comments for a post (top-level only, replies loaded separately)
     *
     * ENTERPRISE: Uses covering index for zero table lookups
     * Returns parent comments with pagination, replies loaded on demand
     *
     * ENTERPRISE V4.7 (2025-12-06): SOFT-HIDE BANNED/SUSPENDED USERS
     * - Comments from users with status != 'active' are hidden
     * - Reversible: reactivating user makes their comments visible again
     *
     * @param int $postId Audio post ID
     * @param int $limit Comments per page
     * @param int $offset Pagination offset
     * @return array Comments with user data
     */
    public function getPostComments(int $postId, int $limit = 20, int $offset = 0): array
    {
        // ENTERPRISE V4.7: Banned user filtering moved to BannedUsersOverlayService
        $sql = "SELECT
            c.id,
            c.uuid,
            c.user_id,
            c.user_uuid,
            c.comment_text,
            c.mentioned_users,
            c.like_count,
            c.reply_count,
            c.is_edited,
            c.created_at,
            c.updated_at,
            u.nickname,
            u.uuid AS author_uuid,
            u.avatar_url
        FROM audio_comments c
        INNER JOIN users u ON c.user_id = u.id
        WHERE c.audio_post_id = :post_id
          AND c.parent_comment_id IS NULL
          AND c.status = 'active'
          AND u.deleted_at IS NULL
        ORDER BY c.created_at DESC
        LIMIT :limit OFFSET :offset";

        // ENTERPRISE V4.4: Tagged cache for granular invalidation
        // cache_tags allows invalidating ONLY comments for this specific post
        // When post 3 gets a new comment, only post 3's comment queries are invalidated
        // ENTERPRISE V4.6: skip_l2 - Memcached cannot be invalidated by pattern
        return $this->db->query($sql, [
            'post_id' => $postId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'cache' => true,
            'cache_ttl' => 'short', // 5 minutes
            'cache_tags' => ["post_comments:{$postId}"], // Granular tag for per-post invalidation
            'skip_l2' => true, // CRITICAL: Skip Memcached - cannot invalidate by pattern
        ]);
    }

    /**
     * Get replies for a parent comment
     *
     * ENTERPRISE: 1-level replies only (no deep nesting)
     * Replies ordered by creation time (oldest first for conversation flow)
     *
     * ENTERPRISE V4.7 (2025-12-06): SOFT-HIDE BANNED/SUSPENDED USERS
     * - Replies from users with status != 'active' are hidden
     * - Reversible: reactivating user makes their replies visible again
     *
     * @param int $parentCommentId Parent comment ID
     * @param int $limit Max replies
     * @return array Replies with user data
     */
    public function getReplies(int $parentCommentId, int $limit = 50): array
    {
        // ENTERPRISE V4.7: Banned user filtering moved to BannedUsersOverlayService
        $sql = "SELECT
            c.id,
            c.uuid,
            c.user_id,
            c.user_uuid,
            c.comment_text,
            c.mentioned_users,
            c.like_count,
            c.is_edited,
            c.created_at,
            c.updated_at,
            u.nickname,
            u.uuid AS author_uuid,
            u.avatar_url
        FROM audio_comments c
        INNER JOIN users u ON c.user_id = u.id
        WHERE c.parent_comment_id = :parent_id
          AND c.status = 'active'
          AND u.deleted_at IS NULL
        ORDER BY c.created_at ASC
        LIMIT :limit";

        // ENTERPRISE V4.4: Tagged cache for granular invalidation
        // ENTERPRISE V4.6: skip_l2 - Memcached cannot be invalidated by pattern
        return $this->db->query($sql, [
            'parent_id' => $parentCommentId,
            'limit' => $limit,
        ], [
            'cache' => true,
            'cache_ttl' => 'short', // 5 minutes
            'cache_tags' => ["comment_replies:{$parentCommentId}"], // Granular tag per parent comment
            'skip_l2' => true, // CRITICAL: Skip Memcached - cannot invalidate by pattern
        ]);
    }

    /**
     * Get comment by ID
     *
     * @param int $commentId
     * @return array|null
     */
    public function findById(int $commentId): ?array
    {
        $sql = "SELECT
            c.*,
            u.nickname,
            u.uuid AS author_uuid,
            u.avatar_url
        FROM audio_comments c
        INNER JOIN users u ON c.user_id = u.id
        WHERE c.id = :id";

        return $this->db->findOne($sql, ['id' => $commentId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * Get comment by UUID
     *
     * @param string $uuid
     * @return array|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $sql = "SELECT
            c.*,
            u.nickname,
            u.uuid AS author_uuid,
            u.avatar_url
        FROM audio_comments c
        INNER JOIN users u ON c.user_id = u.id
        WHERE c.uuid = :uuid";

        return $this->db->findOne($sql, ['uuid' => $uuid], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * Count comments for a post (top-level only)
     *
     * @param int $postId
     * @return int
     */
    public function countPostComments(int $postId): int
    {
        $sql = "SELECT COUNT(*) as count
                FROM audio_comments
                WHERE audio_post_id = :post_id
                  AND parent_comment_id IS NULL
                  AND status = 'active'";

        return (int) ($this->db->findOne($sql, ['post_id' => $postId], [
            'cache' => true,
            'cache_ttl' => 'short',
            'cache_tags' => ["post_comments:{$postId}"], // ENTERPRISE V10.87: Tagged for invalidation
        ])['count'] ?? 0);
    }

    /**
     * Count all comments (including replies) for a post
     *
     * ENTERPRISE V10.87: Fixed cache invalidation
     * Added cache_tags to ensure count is invalidated when comments are added/deleted
     *
     * @param int $postId
     * @return int
     */
    public function countAllPostComments(int $postId): int
    {
        $sql = "SELECT COUNT(*) as count
                FROM audio_comments
                WHERE audio_post_id = :post_id
                  AND status = 'active'";

        return (int) ($this->db->findOne($sql, ['post_id' => $postId], [
            'cache' => true,
            'cache_ttl' => 'short',
            'cache_tags' => ["post_comments:{$postId}"], // ENTERPRISE V10.87: Tagged for invalidation
        ])['count'] ?? 0);
    }

    /**
     * Update comment text
     *
     * ENTERPRISE V4 (2025-11-28): Fixed cache invalidation
     * - Invalidates table-level cache to clear findById and getPostComments caches
     * - Returns old text for edit history tracking
     *
     * @param int $commentId
     * @param string $newText
     * @param int|null $postId Optional post ID for more targeted cache invalidation
     * @return array|bool ['success' => bool, 'old_text' => string] or false on failure
     */
    public function updateText(int $commentId, string $newText, ?int $postId = null): array|bool
    {
        // First get old text for history
        $oldComment = $this->db->findOne(
            "SELECT comment_text, audio_post_id FROM audio_comments WHERE id = :id",
            ['id' => $commentId],
            ['cache' => false] // No cache for pre-update read
        );

        if (!$oldComment) {
            return false;
        }

        $oldText = $oldComment['comment_text'];
        $actualPostId = $postId ?? $oldComment['audio_post_id'];

        $sql = "UPDATE audio_comments
                SET comment_text = :text,
                    is_edited = TRUE,
                    updated_at = NOW()
                WHERE id = :id
                  AND status = 'active'";

        // ENTERPRISE V4.4: Granular TaggedQueryCache invalidation
        $success = (bool) $this->db->execute($sql, [
            'id' => $commentId,
            'text' => $newText,
        ], [
            'invalidate_cache' => [
                "post_comments:{$actualPostId}", // Granular: only this post's comments
            ],
        ]);

        return $success ? ['success' => true, 'old_text' => $oldText] : false;
    }

    /**
     * Soft delete comment (set status to 'deleted')
     *
     * @param int $commentId
     * @return bool
     */
    public function delete(int $commentId, ?int $parentCommentId = null, ?int $postId = null): bool
    {
        $sql = "UPDATE audio_comments
                SET status = 'deleted',
                    updated_at = NOW()
                WHERE id = :id";

        // ENTERPRISE V4.4: Granular TaggedQueryCache invalidation
        $result = (bool) $this->db->execute($sql, ['id' => $commentId], [
            'invalidate_cache' => $postId ? ["post_comments:{$postId}"] : [],
        ]);

        // ENTERPRISE V11 (2025-12-11): Record comment deletion in overlay (-1)
        if ($result && $postId !== null) {
            // V11: Decrement ABSOLUTE comment count in overlay (-1 for deleted comment)
            $overlayService = \Need2Talk\Services\Cache\OverlayService::getInstance();
            if ($overlayService->isAvailable()) {
                $overlayService->decrementCommentAbsolute($postId, 1);

                Logger::overlay('debug', 'V11 Comment deletion recorded (absolute)', [
                    'post_id' => $postId,
                    'comment_id' => $commentId,
                ]);
            }

            // ENTERPRISE V10.9: Broadcast comment count update to post viewers (real-time)
            \Need2Talk\Services\Cache\CounterBroadcastService::getInstance()
                ->broadcastCommentCount($postId, -1);

            // ENTERPRISE V10.153 (2025-12-09): reply_count handled by WriteBehindBuffer
            // Buffer event written by CommentService::bufferDeleteComment()
            // flushComments() will update reply_count asynchronously
        }

        return $result;
    }

    /**
     * Check if user owns comment
     *
     * @param int $commentId
     * @param int $userId
     * @return bool
     */
    public function isOwner(int $commentId, int $userId): bool
    {
        $sql = "SELECT 1 FROM audio_comments
                WHERE id = :comment_id
                  AND user_id = :user_id
                  AND status = 'active'";

        return (bool) $this->db->findOne($sql, [
            'comment_id' => $commentId,
            'user_id' => $userId,
        ], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    // =========================================================================
    // COMMENT LIKES (DB Layer - Used by Flush Service)
    // =========================================================================

    /**
     * Add like to comment (DB write - called by flush service)
     *
     * @param int $commentId
     * @param int $userId
     * @return bool
     */
    public function addLike(int $commentId, int $userId): bool
    {
        try {
            $sql = "INSERT INTO comment_likes (user_id, comment_id, created_at)
                    VALUES (:user_id, :comment_id, NOW())
                    ON CONFLICT (user_id, comment_id) DO NOTHING";

            $this->db->execute($sql, [
                'user_id' => $userId,
                'comment_id' => $commentId,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('CommentRepository::addLike failed', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove like from comment (DB write - called by flush service)
     *
     * @param int $commentId
     * @param int $userId
     * @return bool
     */
    public function removeLike(int $commentId, int $userId): bool
    {
        try {
            $sql = "DELETE FROM comment_likes
                    WHERE user_id = :user_id
                      AND comment_id = :comment_id";

            $this->db->execute($sql, [
                'user_id' => $userId,
                'comment_id' => $commentId,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('CommentRepository::removeLike failed', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if user has liked comment (DB read - for overlay miss)
     *
     * @param int $commentId
     * @param int $userId
     * @return bool
     */
    public function hasUserLiked(int $commentId, int $userId): bool
    {
        $sql = "SELECT 1 FROM comment_likes
                WHERE user_id = :user_id
                  AND comment_id = :comment_id";

        return (bool) $this->db->findOne($sql, [
            'user_id' => $userId,
            'comment_id' => $commentId,
        ], [
            'cache' => false, // Real-time check
        ]);
    }

    /**
     * Get like count for comment (DB read - for overlay miss)
     *
     * @param int $commentId
     * @return int
     */
    public function getLikeCount(int $commentId): int
    {
        $sql = "SELECT like_count FROM audio_comments WHERE id = :id";

        return (int) ($this->db->findOne($sql, ['id' => $commentId], [
            'cache' => false,
        ])['like_count'] ?? 0);
    }

    /**
     * Update like count in database (called by flush service)
     *
     * @param int $commentId
     * @param int $delta Amount to add/subtract
     * @return bool
     */
    public function updateLikeCount(int $commentId, int $delta): bool
    {
        $sql = "UPDATE audio_comments
                SET like_count = GREATEST(0, like_count + :delta),
                    updated_at = NOW()
                WHERE id = :id";

        return (bool) $this->db->execute($sql, [
            'id' => $commentId,
            'delta' => $delta,
        ], [
            'invalidate_cache' => ["comment:{$commentId}"],
        ]);
    }

    /**
     * Update reply count in database (called by flush service)
     *
     * @param int $parentCommentId
     * @param int $delta
     * @return bool
     */
    public function updateReplyCount(int $parentCommentId, int $delta): bool
    {
        $sql = "UPDATE audio_comments
                SET reply_count = GREATEST(0, reply_count + :delta),
                    updated_at = NOW()
                WHERE id = :id";

        return (bool) $this->db->execute($sql, [
            'id' => $parentCommentId,
            'delta' => $delta,
        ], [
            'invalidate_cache' => ["comment:{$parentCommentId}"],
        ]);
    }

    /**
     * Get user's liked comment IDs for a batch of comments
     *
     * ENTERPRISE: Pipeline-friendly batch query
     *
     * @param int $userId
     * @param array $commentIds
     * @return array Comment IDs that user has liked
     */
    public function getUserLikedCommentIds(int $userId, array $commentIds): array
    {
        if (empty($commentIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
        $params = array_merge([$userId], $commentIds);

        $sql = "SELECT comment_id FROM comment_likes
                WHERE user_id = ?
                  AND comment_id IN ({$placeholders})";

        $rows = $this->db->query($sql, $params, [
            'cache' => false, // Real-time check
        ]);

        return array_column($rows, 'comment_id');
    }

    /**
     * Batch update comment counts (like_count + reply_count)
     *
     * ENTERPRISE: Used by flush service for efficient bulk updates
     *
     * @param array $updates Array of ['comment_id' => id, 'like_delta' => int, 'reply_delta' => int]
     * @return int Number of successful updates
     */
    public function batchUpdateCounts(array $updates): int
    {
        if (empty($updates)) {
            return 0;
        }

        $successful = 0;

        foreach ($updates as $update) {
            $sql = "UPDATE audio_comments
                    SET like_count = GREATEST(0, like_count + :like_delta),
                        reply_count = GREATEST(0, reply_count + :reply_delta),
                        updated_at = NOW()
                    WHERE id = :id";

            try {
                $this->db->execute($sql, [
                    'id' => $update['comment_id'],
                    'like_delta' => $update['like_delta'] ?? 0,
                    'reply_delta' => $update['reply_delta'] ?? 0,
                ]);
                $successful++;
            } catch (\Exception $e) {
                Logger::error('CommentRepository::batchUpdateCounts failed', [
                    'comment_id' => $update['comment_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $successful;
    }
}
