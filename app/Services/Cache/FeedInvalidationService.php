<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;
use Need2Talk\Models\Friendship;

/**
 * FeedInvalidationService - Enterprise Galaxy V8.0
 *
 * EVENT-DRIVEN FEED CACHE INVALIDATION (2025-12-01)
 *
 * Handles automatic feed invalidation when content changes.
 * Ensures pre-computed feeds stay fresh without manual intervention.
 *
 * EVENTS THAT TRIGGER INVALIDATION:
 * ┌───────────────────────────────────────────────────────────────────────────────┐
 * │ Event                  │ Invalidation Scope                                  │
 * ├───────────────────────────────────────────────────────────────────────────────┤
 * │ New post               │ All followers of author                             │
 * │ Post deleted           │ All followers of author                             │
 * │ Post visibility change │ All followers of author                             │
 * │ Friendship accepted    │ Both users                                          │
 * │ Friendship removed     │ Both users                                          │
 * │ User blocked           │ Both users                                          │
 * │ Privacy settings change│ User and all followers                              │
 * └───────────────────────────────────────────────────────────────────────────────┘
 *
 * RATE LIMITING:
 * - Max 100 invalidations/second per event type
 * - Batch processing for large follower lists
 * - Deduplication to prevent redundant invalidations
 *
 * @package Need2Talk\Services\Cache
 */
class FeedInvalidationService
{
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Maximum invalidations per second (rate limiting)
     */
    private const MAX_INVALIDATIONS_PER_SECOND = 100;

    /**
     * Batch size for follower invalidation
     */
    private const FOLLOWER_BATCH_SIZE = 500;

    /**
     * Redis key for invalidation deduplication
     */
    private const KEY_INVALIDATION_DEDUP = 'feed_invalidation_dedup';

    /**
     * Deduplication window in seconds
     */
    private const DEDUP_WINDOW = 60;

    /**
     * Redis key for invalidation metrics
     */
    private const KEY_METRICS = 'feed_invalidation_metrics';

    // =========================================================================
    // SINGLETON
    // =========================================================================

    private static ?self $instance = null;
    private ?\Redis $redis = null;
    private ?FeedPrecomputeService $feedService = null;
    private ?Friendship $friendshipModel = null;

    private function __construct()
    {
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection('cache');
        $this->feedService = FeedPrecomputeService::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Lazy load Friendship model
     */
    private function getFriendshipModel(): Friendship
    {
        if ($this->friendshipModel === null) {
            $this->friendshipModel = new Friendship();
        }
        return $this->friendshipModel;
    }

    // =========================================================================
    // POST EVENTS
    // =========================================================================

    /**
     * Handle new post creation
     *
     * Invalidates feeds of all followers of the post author.
     *
     * @param int $authorId Post author user ID
     * @param int $postId New post ID (for logging)
     */
    public function onNewPost(int $authorId, int $postId = 0): void
    {
        if ($authorId <= 0) {
            return;
        }

        $this->incrementMetric('new_post_events');

        // Invalidate author's own feed
        $this->invalidateUserFeed($authorId, 'new_post_self');

        // Invalidate all followers' feeds
        $this->invalidateFollowerFeeds($authorId, 'new_post');

        Logger::overlay('debug', 'Feed invalidation: new post', [
            'author_id' => $authorId,
            'post_id' => $postId,
        ]);
    }

    /**
     * Handle post deletion
     *
     * @param int $authorId Post author user ID
     * @param int $postId Deleted post ID
     */
    public function onPostDeleted(int $authorId, int $postId = 0): void
    {
        if ($authorId <= 0) {
            return;
        }

        $this->incrementMetric('post_deleted_events');

        // Invalidate author's own feed
        $this->invalidateUserFeed($authorId, 'post_deleted_self');

        // Invalidate all followers' feeds
        $this->invalidateFollowerFeeds($authorId, 'post_deleted');

        Logger::overlay('debug', 'Feed invalidation: post deleted', [
            'author_id' => $authorId,
            'post_id' => $postId,
        ]);
    }

    /**
     * Handle post visibility change
     *
     * @param int $authorId Post author user ID
     * @param int $postId Post ID
     * @param string $newVisibility New visibility setting
     */
    public function onPostVisibilityChanged(int $authorId, int $postId, string $newVisibility): void
    {
        if ($authorId <= 0) {
            return;
        }

        $this->incrementMetric('visibility_change_events');

        // Invalidate all followers' feeds (visibility affects who can see)
        $this->invalidateFollowerFeeds($authorId, 'visibility_changed');

        Logger::overlay('debug', 'Feed invalidation: visibility changed', [
            'author_id' => $authorId,
            'post_id' => $postId,
            'new_visibility' => $newVisibility,
        ]);
    }

    // =========================================================================
    // FRIENDSHIP EVENTS
    // =========================================================================

    /**
     * Handle friendship accepted
     *
     * Both users should see each other's posts in their feeds.
     *
     * @param int $userId1 First user ID
     * @param int $userId2 Second user ID
     */
    public function onFriendshipAccepted(int $userId1, int $userId2): void
    {
        if ($userId1 <= 0 || $userId2 <= 0) {
            return;
        }

        $this->incrementMetric('friendship_accepted_events');

        // Invalidate both users' feeds
        $this->invalidateUserFeed($userId1, 'friendship_accepted');
        $this->invalidateUserFeed($userId2, 'friendship_accepted');

        Logger::overlay('debug', 'Feed invalidation: friendship accepted', [
            'user_id_1' => $userId1,
            'user_id_2' => $userId2,
        ]);
    }

    /**
     * Handle friendship removed/unfriended
     *
     * @param int $userId1 First user ID
     * @param int $userId2 Second user ID
     */
    public function onFriendshipRemoved(int $userId1, int $userId2): void
    {
        if ($userId1 <= 0 || $userId2 <= 0) {
            return;
        }

        $this->incrementMetric('friendship_removed_events');

        // Invalidate both users' feeds
        $this->invalidateUserFeed($userId1, 'friendship_removed');
        $this->invalidateUserFeed($userId2, 'friendship_removed');

        Logger::overlay('debug', 'Feed invalidation: friendship removed', [
            'user_id_1' => $userId1,
            'user_id_2' => $userId2,
        ]);
    }

    /**
     * Handle user blocked
     *
     * @param int $blockerId User who blocked
     * @param int $blockedId User who was blocked
     */
    public function onUserBlocked(int $blockerId, int $blockedId): void
    {
        if ($blockerId <= 0 || $blockedId <= 0) {
            return;
        }

        $this->incrementMetric('user_blocked_events');

        // Invalidate both users' feeds
        $this->invalidateUserFeed($blockerId, 'user_blocked');
        $this->invalidateUserFeed($blockedId, 'blocked_by_user');

        Logger::overlay('debug', 'Feed invalidation: user blocked', [
            'blocker_id' => $blockerId,
            'blocked_id' => $blockedId,
        ]);
    }

    // =========================================================================
    // PRIVACY EVENTS
    // =========================================================================

    /**
     * Handle privacy settings change
     *
     * User's visibility to others may have changed.
     *
     * @param int $userId User who changed settings
     */
    public function onPrivacySettingsChanged(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $this->incrementMetric('privacy_change_events');

        // Invalidate user's own feed
        $this->invalidateUserFeed($userId, 'privacy_changed_self');

        // Invalidate all followers' feeds
        $this->invalidateFollowerFeeds($userId, 'privacy_changed');

        Logger::overlay('debug', 'Feed invalidation: privacy changed', [
            'user_id' => $userId,
        ]);
    }

    // =========================================================================
    // CORE INVALIDATION METHODS
    // =========================================================================

    /**
     * Invalidate a single user's feed
     *
     * @param int $userId User ID
     * @param string $reason Reason for invalidation (logging)
     * @return bool True if invalidated, false if deduplicated
     */
    private function invalidateUserFeed(int $userId, string $reason = ''): bool
    {
        if ($userId <= 0) {
            return false;
        }

        // Check deduplication
        if ($this->isDuplicate($userId, $reason)) {
            $this->incrementMetric('deduplicated');
            return false;
        }

        // Mark as invalidated (for deduplication)
        $this->markInvalidated($userId, $reason);

        // Invalidate via FeedPrecomputeService
        $this->feedService->invalidateFeed($userId);

        $this->incrementMetric('feeds_invalidated');
        return true;
    }

    /**
     * Invalidate feeds for all followers of a user
     *
     * @param int $userId User whose followers should have feeds invalidated
     * @param string $reason Reason for invalidation
     * @return int Number of feeds invalidated
     */
    private function invalidateFollowerFeeds(int $userId, string $reason = ''): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $invalidated = 0;

        try {
            // Get all followers in batches
            $offset = 0;
            $rateLimit = 0;
            $rateLimitStart = microtime(true);

            do {
                $followers = $this->getFollowers($userId, self::FOLLOWER_BATCH_SIZE, $offset);

                foreach ($followers as $followerId) {
                    // Rate limiting
                    $rateLimit++;
                    if ($rateLimit >= self::MAX_INVALIDATIONS_PER_SECOND) {
                        $elapsed = microtime(true) - $rateLimitStart;
                        if ($elapsed < 1.0) {
                            usleep((int)((1.0 - $elapsed) * 1000000));
                        }
                        $rateLimit = 0;
                        $rateLimitStart = microtime(true);
                    }

                    if ($this->invalidateUserFeed((int) $followerId, $reason . '_follower')) {
                        $invalidated++;
                    }
                }

                $offset += self::FOLLOWER_BATCH_SIZE;

            } while (count($followers) === self::FOLLOWER_BATCH_SIZE);

            $this->incrementMetric('follower_invalidations', $invalidated);

        } catch (\Exception $e) {
            Logger::error('FeedInvalidationService::invalidateFollowerFeeds failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return $invalidated;
    }

    /**
     * Get followers of a user
     *
     * @param int $userId User ID
     * @param int $limit Max followers to return
     * @param int $offset Pagination offset
     * @return array Array of follower user IDs
     */
    private function getFollowers(int $userId, int $limit = 500, int $offset = 0): array
    {
        try {
            $db = db();

            // Get accepted friendships where this user is the target
            // (in a symmetric friendship system, both directions)
            $result = $db->findMany(
                "SELECT
                    CASE
                        WHEN user_id = ? THEN friend_id
                        ELSE user_id
                    END as follower_id
                 FROM friendships
                 WHERE (user_id = ? OR friend_id = ?)
                   AND status = 'accepted'
                 LIMIT ? OFFSET ?",
                [$userId, $userId, $userId, $limit, $offset],
                ['cache' => false] // Don't cache this query
            );

            return array_column($result, 'follower_id');

        } catch (\Exception $e) {
            Logger::error('FeedInvalidationService::getFollowers failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // DEDUPLICATION
    // =========================================================================

    /**
     * Check if this invalidation is a duplicate (within dedup window)
     *
     * @param int $userId User ID
     * @param string $reason Reason (different reasons = different invalidations)
     * @return bool True if duplicate
     */
    private function isDuplicate(int $userId, string $reason): bool
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $key = self::KEY_INVALIDATION_DEDUP;
            $member = "{$userId}:{$reason}";

            // Check if already invalidated within window
            $score = $this->redis->zScore($key, $member);

            if ($score !== false && (time() - $score) < self::DEDUP_WINDOW) {
                return true;
            }

            return false;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Mark a user as invalidated (for deduplication)
     *
     * @param int $userId User ID
     * @param string $reason Reason
     */
    private function markInvalidated(int $userId, string $reason): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $key = self::KEY_INVALIDATION_DEDUP;
            $member = "{$userId}:{$reason}";

            // Add with current timestamp as score
            $this->redis->zAdd($key, time(), $member);

            // Cleanup old entries
            $this->redis->zRemRangeByScore($key, '-inf', (string)(time() - self::DEDUP_WINDOW * 2));

            // Set TTL on the key
            $this->redis->expire($key, self::DEDUP_WINDOW * 2);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    // =========================================================================
    // METRICS & MONITORING
    // =========================================================================

    /**
     * Increment a metric counter
     *
     * @param string $metric Metric name
     * @param int $value Value to add
     */
    private function incrementMetric(string $metric, int $value = 1): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->hIncrBy(self::KEY_METRICS, $metric, $value);
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get all metrics
     *
     * @return array Metrics for monitoring
     */
    public function getMetrics(): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            return $this->redis->hGetAll(self::KEY_METRICS) ?: [];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Reset metrics (for testing)
     */
    public function resetMetrics(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->del(self::KEY_METRICS);
            $this->redis->del(self::KEY_INVALIDATION_DEDUP);
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get comprehensive status
     *
     * @return array Status for admin dashboard
     */
    public function getStatus(): array
    {
        return [
            'available' => $this->redis !== null,
            'dedup_window_seconds' => self::DEDUP_WINDOW,
            'max_invalidations_per_second' => self::MAX_INVALIDATIONS_PER_SECOND,
            'follower_batch_size' => self::FOLLOWER_BATCH_SIZE,
            'metrics' => $this->getMetrics(),
        ];
    }
}
