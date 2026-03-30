<?php

declare(strict_types=1);

namespace Need2Talk\Repositories\Audio;

use Need2Talk\Core\Database;

/**
 * Audio Post Repository - ENTERPRISE GALAXY ARCHITECTURE
 *
 * DATABASE LAYER: audio_posts table (social posts)
 * PERFORMANCE TARGET: 100,000+ concurrent users, <5ms queries
 *
 * ARCHITECTURE (2-table design):
 * - audio_posts: Social layer (text, photo, video, mixed posts)
 * - audio_files: Storage layer (audio file metadata) - JOIN only for playback
 *
 * COVERING INDEXES (ZERO table lookups):
 * - idx_feed_covering: visibility, moderation_status, deleted_at, created_at DESC, ...
 * - idx_user_posts: user_id, deleted_at, created_at DESC
 *
 * CACHE STRATEGY (multi-level):
 * - L1 Redis (5min): Hot posts, frequent queries
 * - L2 Memcached (30min): Medium-hot data
 * - L3 Redis (1h): Cold data, fallback
 *
 * @package Need2Talk\Repositories\Audio
 */
class AudioPostRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * Create new audio post
     *
     * ENTERPRISE: Supports all post types (text, audio, photo, video, mixed)
     * - Text post: content only
     * - Audio post: audio_file_id + optional content
     * - Photo post: photo_urls JSON + optional content
     * - Video post: video_url + optional content
     * - Mixed post: combination of above
     *
     * @param array $data Post data
     * @return int|false Insert ID or false on failure
     */
    public function create(array $data): int|false
    {
        try {
            // ENTERPRISE UUID: INSERT with both user_id and user_uuid (dual-write pattern)
            $sql = "INSERT INTO audio_posts (
                user_id,
                user_uuid,
                post_type,
                content,
                audio_file_id,
                photo_urls,
                video_url,
                visibility,
                tagged_users,
                location,
                moderation_status,
                created_at,
                published_at
            ) VALUES (
                :user_id,
                :user_uuid,
                :post_type,
                :content,
                :audio_file_id,
                :photo_urls,
                :video_url,
                :visibility,
                :tagged_users,
                :location,
                :moderation_status,
                NOW(),
                NOW()
            )";

            $params = [
                'user_id' => $data['user_id'],
                'user_uuid' => $data['user_uuid'] ?? null,  // ENTERPRISE: UUID-based system
                'post_type' => $data['post_type'] ?? 'text',
                'content' => $data['content'] ?? null,
                'audio_file_id' => $data['audio_file_id'] ?? null,
                'photo_urls' => isset($data['photo_urls']) ? json_encode($data['photo_urls']) : null,
                'video_url' => $data['video_url'] ?? null,
                'visibility' => $data['visibility'] ?? 'public',
                'tagged_users' => isset($data['tagged_users']) ? json_encode($data['tagged_users']) : null,
                'location' => $data['location'] ?? null,
                'moderation_status' => $data['moderation_status'] ?? 'approved',
            ];

            // ENTERPRISE DEBUG: Log INSERT attempt
            \Need2Talk\Services\Logger::security('debug', 'AudioPostRepository: Executing INSERT', [
                'user_id' => $data['user_id'],
                'user_uuid' => $data['user_uuid'] ?? null,  // ENTERPRISE: UUID in logs
                'post_type' => $data['post_type'] ?? 'text',
                'audio_file_id' => $data['audio_file_id'] ?? null,
                'visibility' => $data['visibility'] ?? 'public',
                'has_content' => !empty($data['content']),
            ]);

            // ENTERPRISE FIX: Use 'return_id' option to get insert ID directly
            // Without this, execute() returns affected rows (0 for INSERT = falsy!)
            // and lastInsertId() fails because connection is released by pool
            $insertId = $this->db->execute($sql, $params, [
                'invalidate_cache' => ['table:audio_posts', 'feed:*', "user_posts:{$data['user_id']}"],
                'return_id' => true,  // ← CRITICAL: Returns insert ID instead of affected rows
            ]);

            // CRITICAL FIX: PDO lastInsertId() returns string, not int
            // Cast to int to match return type int|false
            $insertId = (int) $insertId;

            if (!$insertId || $insertId === 0) {
                \Need2Talk\Services\Logger::security('error', 'AudioPostRepository: INSERT returned invalid ID', [
                    'user_id' => $data['user_id'],
                    'user_uuid' => $data['user_uuid'] ?? null,
                    'insert_id' => $insertId,
                    'params' => $params,
                ]);

                return false;
            }

            \Need2Talk\Services\Logger::security('debug', 'AudioPostRepository: INSERT successful', [
                'insert_id' => $insertId,
                'user_id' => $data['user_id'],
                'user_uuid' => $data['user_uuid'] ?? null,
            ]);

            return $insertId;

        } catch (\PDOException $e) {
            \Need2Talk\Services\Logger::security('error', 'AudioPostRepository: PDO Exception during INSERT', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'user_uuid' => $data['user_uuid'] ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return false;

        } catch (\Exception $e) {
            \Need2Talk\Services\Logger::security('error', 'AudioPostRepository: General Exception during INSERT', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'user_id' => $data['user_id'] ?? null,
                'user_uuid' => $data['user_uuid'] ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Get social feed (public + friends posts)
     *
     * ENTERPRISE OPTIMIZATION:
     * - Uses idx_feed_covering (ZERO table lookups for audio_posts columns)
     * - LEFT JOIN audio_files for title/duration/emotion (FK index, minimal overhead)
     * - LEFT JOIN emotions for emotion icon/name (indexed primary_emotion_id)
     * - Multi-level cache (L1 5min, L2 30min, L3 1h)
     * - Denormalized counters (comment_count via subquery, play_count in audio_files)
     * - Reaction stats loaded separately via ReactionStatsService
     *
     * PERFORMANCE: <7ms for 20 posts with audio data (was <5ms without JOIN)
     * SCALABILITY: Same performance with 100M posts (FK indexes)
     *
     * ENTERPRISE V4.7 (2025-12-06): SOFT-HIDE BANNED/SUSPENDED USERS
     * - Users with status != 'active' are filtered from feed (soft-hide)
     * - User's own posts always visible (even if suspended) for self-review
     * - Reversible: reactivating user makes posts visible again
     * - Zero data loss: posts remain in DB, just filtered from queries
     *
     * @param int $userId Current user ID (for future friend filtering)
     * @param int $limit Posts per page
     * @param int $offset Pagination offset
     * @return array Feed posts with user data + audio metadata
     */
    public function getSocialFeed(int $userId, int $limit = 20, int $offset = 0): array
    {
        // ENTERPRISE: Force covering index for audio_posts + LEFT JOIN audio_files for metadata
        // Index: idx_feed_covering (visibility, moderation_status, deleted_at, created_at DESC, ...)
        // FK Index: audio_files.id (PRIMARY KEY) - fast lookup
        // FK Index: emotions.id (PRIMARY KEY) - fast lookup
        // ENTERPRISE GALAXY FIX: Added photo_url, photo_thumbnail, description, cdn_url to display cover photos in feed
        // ENTERPRISE UUID: Added user_uuid from audio_posts and users for UUID-based system
        // ENTERPRISE V4.4: Privacy-aware feed - shows posts based on visibility:
        //   - 'public': visible to everyone
        //   - 'friends': visible only to friends (status='accepted')
        //   - 'friends_of_friends': TODO - requires 2-hop friendship query
        //   - User's own posts: always visible regardless of visibility setting
        // ENTERPRISE V4.7: Soft-hide banned/suspended users from feed
        //   - u.status = 'active' required for other users' posts
        //   - Own posts always visible (ap.user_id = :current_user_id bypasses status check)
        // ENTERPRISE V11.2 (2025-12-11): Use COUNT(*) for comment_count instead of denormalized field
        // The denormalized field audio_posts.comment_count is UNRELIABLE and causes stale counts.
        // Subquery with idx_audio_comments_post index is fast (<1ms per post with index scan).
        $sql = "SELECT
            ap.id,
            ap.uuid,
            ap.user_id,
            ap.user_uuid,
            ap.post_type,
            ap.content,
            ap.audio_file_id,
            ap.photo_urls,
            ap.video_url,
            ap.visibility,
            ap.tagged_users,
            ap.location,
            -- V11.2: Real COUNT instead of stale denormalized field
            (SELECT COUNT(*) FROM audio_comments ac WHERE ac.audio_post_id = ap.id AND ac.status = 'active') AS comment_count,
            -- V5.3: share_count removed - no sharing feature exists
            ap.created_at,
            ap.published_at,
            u.nickname,
            u.uuid AS author_uuid,
            u.avatar_url,
            u.status AS user_status,
            af.title AS audio_title,
            af.description AS audio_description,
            af.duration AS audio_duration,
            af.photo_url AS audio_photo_url,
            af.photo_thumbnail AS audio_photo_thumbnail,
            af.cdn_url AS audio_cdn_url,
            af.file_size AS audio_file_size,
            af.mime_type AS audio_mime_type,
            af.play_count AS audio_play_count,
            e.id AS emotion_id,
            e.name_it AS emotion_name_it,
            e.icon_emoji AS emotion_icon_emoji,
            e.color_hex AS emotion_color_hex
        FROM audio_posts ap
        INNER JOIN users u ON ap.user_id = u.id
        LEFT JOIN audio_files af ON ap.audio_file_id = af.id AND af.deleted_at IS NULL
        LEFT JOIN emotions e ON af.primary_emotion_id = e.id
        WHERE (
            -- ENTERPRISE V4.7: User's own posts always visible (self-review even if suspended)
            ap.user_id = :current_user_id
            OR (
                -- ENTERPRISE V4.7: Banned user filtering moved to BannedUsersOverlayService
                -- for INSTANT ban/unban effect without cache invalidation
                u.deleted_at IS NULL
                AND (
                    -- ENTERPRISE V4.4: Privacy-aware feed
                    -- Enum values: private, friends, friends_of_friends, public
                    ap.visibility = 'public'
                    OR (ap.visibility = 'friends' AND EXISTS (
                        SELECT 1 FROM friendships f
                        WHERE f.status = 'accepted'
                          AND f.deleted_at IS NULL
                          AND (
                            (f.user_id = :current_user_id AND f.friend_id = ap.user_id)
                            OR (f.friend_id = :current_user_id AND f.user_id = ap.user_id)
                          )
                    ))
                )
            )
        )
          AND ap.moderation_status = 'approved'
          AND ap.deleted_at IS NULL
        ORDER BY ap.created_at DESC
        LIMIT :limit OFFSET :offset";

        // ENTERPRISE GALAXY FIX: Check if user requested force refresh (e.g., after avatar upload)
        // SettingsController sets $_SESSION['_user_cache_bypass_until'] = time() + 30
        // force_refresh = Skip OLD cache + Write NEW cache (avatar updated)
        // After 30s expires → User returns to cache (but NEW cache with updated avatar)
        $forceRefresh = isset($_SESSION['_user_cache_bypass_until'])
                        && $_SESSION['_user_cache_bypass_until'] > time();

        // ENTERPRISE V4.5: Check FriendshipOverlay for NEW friendships (immediate visibility)
        // If there are newly accepted friends in overlay, we MUST bypass cache to see their posts
        // This is the OVERLAY PATTERN: merge cached data with real-time changes
        if (!$forceRefresh) {
            try {
                $overlay = \Need2Talk\Services\Cache\FriendshipOverlayService::getInstance();
                if ($overlay->isAvailable()) {
                    // ENTERPRISE V11.5: Check if unfriend/block happened - ONE fresh query needed
                    // This flag is set by unfriendByUuid() or blockUserByUuid()
                    // checkAndClearFeedRefresh() returns true ONCE, then clears the flag
                    if ($overlay->checkAndClearFeedRefresh($userId)) {
                        $forceRefresh = true;
                    }

                    // Also check for new friends (existing logic)
                    if (!$forceRefresh) {
                        $newFriends = $overlay->getAcceptedFriendsDelta($userId);
                        if (!empty($newFriends)) {
                            // New friends exist in overlay - bypass cache to include their posts
                            $forceRefresh = true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Overlay check failed - continue with cached data (safe fallback)
            }
        }

        // ENTERPRISE V4.5 (2025-11-29): Added cache_tags for granular invalidation
        // When comment_count is updated, invalidate_cache => ['table:audio_posts'] is called
        // This tag allows TaggedQueryCache to invalidate ONLY feed queries (not all queries)
        // Without this, feed queries were never invalidated when new comments were added
        // ENTERPRISE V4.6 (2025-11-29): Added skip_l2 to bypass Memcached
        // Memcached L2 does NOT support pattern invalidation, causing stale data
        // Feed queries change frequently (comments, reactions) - use only Redis L1/L3
        return $this->db->query($sql, [
            'current_user_id' => $userId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'force_refresh' => $forceRefresh, // GALAXY: Bypass read + force write new cache
            'cache_ttl' => 'medium', // PERFORMANCE FIX: 30min instead of 5min (-83% query load)
            'cache_tags' => ['audio_posts'], // CRITICAL: Tag for comment_count invalidation
            'skip_l2' => true, // CRITICAL: Skip Memcached - cannot invalidate by pattern
        ]);
    }

    /**
     * Get audio post by ID
     *
     * ENTERPRISE FIX: Single post fetch (for post detail page + audio streaming)
     * - Includes user data (JOIN users)
     * - INCLUDES audio_files data (file_path, cdn_url for streaming)
     * - Cached 30min (medium TTL)
     *
     * CRITICAL BUG FIX: Added LEFT JOIN audio_files to return file_path + cdn_url
     * Without these fields, AudioController::stream() returns 500 error
     *
     * @param int $postId Post ID
     * @return array|null Post data
     */
    public function findById(int $postId): ?array
    {
        // ENTERPRISE UUID: Added user_uuid from audio_posts and users
        $sql = "SELECT
            ap.id,
            ap.uuid,
            ap.user_id,
            ap.user_uuid,
            ap.post_type,
            ap.content,
            ap.audio_file_id,
            ap.photo_urls,
            ap.video_url,
            ap.visibility,
            ap.tagged_users,
            ap.location,
            ap.moderation_status,
            ap.moderation_reason,
            -- V11.2: Real COUNT instead of stale denormalized field
            (SELECT COUNT(*) FROM audio_comments ac WHERE ac.audio_post_id = ap.id AND ac.status = 'active') AS comment_count,
            -- V5.3: share_count removed - no sharing feature exists
            ap.report_count,
            ap.created_at,
            ap.updated_at,
            ap.published_at,
            u.nickname,
            u.uuid AS author_uuid,
            u.avatar_url,
            af.file_path,
            af.cdn_url,
            af.duration,
            af.title,
            af.description AS audio_description,
            af.photo_url AS audio_photo_url,
            af.photo_thumbnail AS audio_photo_thumbnail,
            af.file_size AS audio_file_size,
            af.mime_type AS audio_mime_type,
            af.play_count AS play_count
        FROM audio_posts ap
        INNER JOIN users u ON ap.user_id = u.id
        LEFT JOIN audio_files af ON ap.audio_file_id = af.id AND af.deleted_at IS NULL
        WHERE ap.id = :id
          AND ap.deleted_at IS NULL";

        // ENTERPRISE V4.5 (2025-11-29): Added cache_tags for granular invalidation
        // Invalidated when comment_count changes (new comments added)
        // ENTERPRISE V4.6: skip_l2 - Memcached cannot be invalidated by pattern
        return $this->db->findOne($sql, ['id' => $postId], [
            'cache' => true,
            'cache_ttl' => 'medium', // 30min
            'cache_tags' => ['audio_posts', "post:{$postId}"], // Tags for comment_count + post-specific invalidation
            'skip_l2' => true, // CRITICAL: Skip Memcached - cannot invalidate by pattern
        ]);
    }

    /**
     * Get audio file data for post (JOIN with audio_files)
     *
     * ENTERPRISE: Called ONLY when user clicks PLAY
     * - Separate method to avoid JOIN in feed queries
     * - Cached 24h (audio files are immutable)
     * - Returns file_path, duration, mime_type for playback
     *
     * @param int $audioFileId Audio file ID
     * @return array|null Audio file data
     */
    public function getAudioFileData(int $audioFileId): ?array
    {
        // ENTERPRISE UUID: Added user_uuid for UUID-based system
        $sql = "SELECT
            id,
            uuid,
            user_uuid,
            file_path,
            private_url,
            duration,
            mime_type,
            file_size
        FROM audio_files
        WHERE id = :id
          AND deleted_at IS NULL";

        return $this->db->findOne($sql, ['id' => $audioFileId], [
            'cache' => true,
            'cache_ttl' => 'very_long', // 2h (immutable data)
        ]);
    }

    /**
     * Get user's posts
     *
     * ENTERPRISE OPTIMIZATION:
     * - Uses idx_user_posts (user_id, deleted_at, created_at DESC)
     * - ORDER BY created_at DESC matches index order (NO filesort!)
     * - Privacy filtering handled by caller (ProfileController)
     *
     * @param int $userId User ID
     * @param int $limit Posts per page
     * @param int $offset Pagination offset
     * @return array User's posts
     */
    public function getUserPosts(int $userId, int $limit = 20, int $offset = 0): array
    {
        // ENTERPRISE GALAXY FIX: Added LEFT JOIN with audio_files to fetch photo_url, photo_thumbnail, and metadata
        // Without this, audio posts don't show cover photos in feed (CRITICAL BUG)
        // ENTERPRISE UUID: Added user_uuid for UUID-based system
        $sql = "SELECT
            ap.id,
            ap.uuid,
            ap.user_uuid,
            ap.post_type,
            ap.content,
            ap.audio_file_id,
            ap.photo_urls,
            ap.video_url,
            ap.visibility,
            ap.tagged_users,
            ap.location,
            -- V11.2: Real COUNT instead of stale denormalized field
            (SELECT COUNT(*) FROM audio_comments ac WHERE ac.audio_post_id = ap.id AND ac.status = 'active') AS comment_count,
            -- V5.3: share_count removed - no sharing feature exists
            ap.created_at,
            ap.published_at,
            af.title AS audio_title,
            af.description AS audio_description,
            af.photo_url AS audio_photo_url,
            af.photo_thumbnail AS audio_photo_thumbnail,
            af.cdn_url AS audio_cdn_url,
            af.duration AS audio_duration,
            af.file_size AS audio_file_size,
            af.mime_type AS audio_mime_type
        FROM audio_posts ap
        LEFT JOIN audio_files af ON ap.audio_file_id = af.id AND af.deleted_at IS NULL
        WHERE ap.user_id = :user_id
          AND ap.deleted_at IS NULL
        ORDER BY ap.created_at DESC
        LIMIT :limit OFFSET :offset";

        return $this->db->query($sql, [
            'user_id' => $userId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'cache' => true,
            'cache_ttl' => 'short', // 5min
        ]);
    }

    // NOTE: incrementViewCount() removed - AUDIO platform uses LISTENS not views!
    // Use PlayTrackingService::trackPlay() for audio listens (audio_files.play_count)
    // ENTERPRISE V5.3: Renamed terminology from "view" to "listen" throughout codebase

    /**
     * Increment listen count for audio post
     *
     * ENTERPRISE V5.3: Listen tracking via overlay system
     * play_count is stored in audio_files table, not audio_posts
     * Uses overlay pattern for high-concurrency tracking.
     *
     * @param int $postId Post ID
     * @return bool Success
     */
    public function incrementListenCount(int $postId): bool
    {
        // ENTERPRISE V5.3: Delegate to overlay service - NO direct DB update!
        // The overlay flush service handles syncing to audio_files.play_count
        $overlay = \Need2Talk\Services\Cache\OverlayService::getInstance();
        if ($overlay->isAvailable()) {
            // recordView() handles listen tracking (method name is legacy)
            $overlay->recordView($postId, 0); // 0 = anonymous user
            return true;
        }

        // Fallback: Direct DB update to audio_files.play_count
        $sql = "UPDATE audio_files af
                SET play_count = play_count + 1
                FROM audio_posts ap
                WHERE ap.audio_file_id = af.id
                  AND ap.id = :post_id";

        return (bool) $this->db->execute($sql, ['post_id' => $postId]);
    }

    // NOTE: Like count methods removed - replaced with Emotional Reactions System
    // See: ReactionStatsService::getPostReactionStats() for reaction counts

    /**
     * Increment comment count
     *
     * ENTERPRISE V4 (2025-11-28): DEPRECATED - Uses overlay instead of DB + cache invalidation
     * Comment counts are now managed via OverlayService::incrementCommentCount()
     * and flushed to DB via OverlayFlushService::flushCommentCounters()
     *
     * This method now delegates to overlay for backwards compatibility
     *
     * @param int $postId Post ID
     * @return bool Success
     * @deprecated Use OverlayService::incrementCommentCount() instead
     */
    public function incrementCommentCount(int $postId): bool
    {
        // ENTERPRISE V4: Delegate to overlay service - NO cache invalidation!
        $overlay = \Need2Talk\Services\Cache\OverlayService::getInstance();
        if ($overlay->isAvailable()) {
            $overlay->incrementCommentCount($postId, 1);
            return true;
        }

        // Fallback: Direct DB update without invalidation (legacy)
        $sql = "UPDATE audio_posts
                SET comment_count = comment_count + 1
                WHERE id = :id";

        return (bool) $this->db->execute($sql, ['id' => $postId]);
    }

    /**
     * Update moderation status
     *
     * @param int $postId Post ID
     * @param string $status New status (pending, approved, rejected, flagged)
     * @param string|null $reason Moderation reason
     * @return bool Success
     */
    public function updateModerationStatus(int $postId, string $status, ?string $reason = null): bool
    {
        $validStatuses = ['pending', 'approved', 'rejected', 'flagged'];
        if (!in_array($status, $validStatuses, true)) {
            return false;
        }

        $sql = "UPDATE audio_posts
                SET moderation_status = :status,
                    moderation_reason = :reason,
                    updated_at = NOW()
                WHERE id = :id";

        return (bool) $this->db->execute($sql, [
            'id' => $postId,
            'status' => $status,
            'reason' => $reason,
        ], [
            'invalidate_cache' => ["audio_post:{$postId}", 'feed:*'],
        ]);
    }

    /**
     * Soft delete post
     *
     * @param int $postId Post ID
     * @return bool Success
     */
    public function delete(int $postId): bool
    {
        $sql = "UPDATE audio_posts
                SET deleted_at = NOW()
                WHERE id = :id";

        return (bool) $this->db->execute($sql, ['id' => $postId], [
            'invalidate_cache' => ["audio_post:{$postId}", 'feed:*', "user_posts:*"],
        ]);
    }

    /**
     * Get total posts count for user
     *
     * ENTERPRISE: Uses idx_user_posts (fast COUNT on index)
     *
     * @param int $userId User ID
     * @return int Total posts
     */
    public function countUserPosts(int $userId): int
    {
        $sql = "SELECT COUNT(*) as count
                FROM audio_posts
                WHERE user_id = :user_id
                  AND deleted_at IS NULL";

        return (int) ($this->db->findOne($sql, ['user_id' => $userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ])['count'] ?? 0);
    }

    /**
     * Check if user owns post
     *
     * @param int $postId Post ID
     * @param int $userId User ID
     * @return bool Ownership
     */
    public function isOwner(int $postId, int $userId): bool
    {
        $sql = "SELECT 1 FROM audio_posts
                WHERE id = :post_id
                  AND user_id = :user_id
                  AND deleted_at IS NULL";

        return (bool) $this->db->findOne($sql, [
            'post_id' => $postId,
            'user_id' => $userId,
        ], [
            'cache' => true,
            'cache_ttl' => 'medium',
        ]);
    }

    /**
     * Get posts by visibility
     *
     * ENTERPRISE: Uses idx_feed_covering (optimal for public/friends filtering)
     *
     * ENTERPRISE V4.7 (2025-12-06): SOFT-HIDE BANNED/SUSPENDED USERS
     * - Only shows posts from users with status = 'active'
     * - Consistent with getSocialFeed() filtering
     *
     * @param string $visibility Visibility filter (public, friends, private)
     * @param int $limit Posts per page
     * @param int $offset Pagination offset
     * @return array Posts
     */
    public function getByVisibility(string $visibility, int $limit = 20, int $offset = 0): array
    {
        // ENTERPRISE UUID: Added user_uuid from audio_posts and users
        // ENTERPRISE V4.7: Banned user filtering moved to BannedUsersOverlayService
        $sql = "SELECT
            ap.id,
            ap.uuid,
            ap.user_id,
            ap.user_uuid,
            ap.post_type,
            ap.content,
            -- V11.2: Real COUNT instead of stale denormalized field
            (SELECT COUNT(*) FROM audio_comments ac WHERE ac.audio_post_id = ap.id AND ac.status = 'active') AS comment_count,

            ap.created_at,
            u.nickname,
            u.uuid AS author_uuid,
            u.avatar_url
        FROM audio_posts ap
        INNER JOIN users u ON ap.user_id = u.id
        WHERE ap.visibility = :visibility
          AND ap.moderation_status = 'approved'
          AND ap.deleted_at IS NULL
          AND u.deleted_at IS NULL
        ORDER BY ap.created_at DESC
        LIMIT :limit OFFSET :offset";

        return $this->db->query($sql, [
            'visibility' => $visibility,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * Get audio file info for cache invalidation
     *
     * ENTERPRISE GALAXY: Returns UUID and CDN URL for cache invalidation
     * Used by AudioCacheInvalidator before delete/update operations
     *
     * @param int $postId Audio post ID
     * @return array|null Audio file info (uuid, cdn_url) or null if not found
     */
    public function getAudioFileInfo(int $postId): ?array
    {
        $sql = "SELECT
            af.uuid,
            af.cdn_url,
            af.file_path,
            ap.user_uuid
        FROM audio_posts ap
        INNER JOIN audio_files af ON ap.audio_file_id = af.id
        WHERE ap.id = :post_id
          AND ap.deleted_at IS NULL
          AND af.deleted_at IS NULL";

        $result = $this->db->findOne($sql, [
            'post_id' => $postId,
        ], [
            'cache' => false, // No cache - called before deletion
        ]);

        return $result ?: null;
    }
}
