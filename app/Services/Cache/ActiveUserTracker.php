<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * ActiveUserTracker - Enterprise Galaxy V8.0
 *
 * FEED PRE-COMPUTATION SYSTEM (2025-12-01)
 *
 * Tracks user activity to identify candidates for feed pre-computation.
 * Only the most active users get pre-computed feeds (selective optimization).
 *
 * ARCHITECTURE:
 * ┌───────────────────────────────────────────────────────────────────────────────┐
 * │ User Activity → ZINCRBY → Sorted Set (score = activity count)                │
 * │                                                                               │
 * │ Activity Types (weighted):                                                    │
 * │ - Feed view: 1 point (most common)                                           │
 * │ - Post creation: 5 points (high engagement)                                  │
 * │ - Comment: 3 points (medium engagement)                                      │
 * │ - Reaction: 2 points (light engagement)                                      │
 * │                                                                               │
 * │ Window: Rolling 1 hour (auto-expire old activity)                            │
 * │ Top N: Configurable (default 1000 users)                                     │
 * └───────────────────────────────────────────────────────────────────────────────┘
 *
 * STORAGE:
 * - Redis ZSET: `active_users:hourly` (score = weighted activity count)
 * - TTL: 1 hour (auto-cleanup)
 * - Memory: ~50KB for 10K users (very efficient)
 *
 * @package Need2Talk\Services\Cache
 */
class ActiveUserTracker
{
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Maximum number of users to track for pre-computation
     */
    public const TOP_USERS_COUNT = 1000;

    /**
     * Activity window in seconds (1 hour)
     */
    private const ACTIVITY_WINDOW = 3600;

    /**
     * Redis key for active users sorted set
     */
    private const KEY_ACTIVE_USERS = 'active_users:hourly';

    /**
     * Redis key for last feed access timestamps
     */
    private const KEY_FEED_ACCESS = 'feed_access:timestamps';

    /**
     * Activity weights by type
     */
    private const ACTIVITY_WEIGHTS = [
        'feed_view' => 1,      // Most common, lowest weight
        'post_create' => 5,    // High engagement
        'comment' => 3,        // Medium engagement
        'reaction' => 2,       // Light engagement
        'login' => 2,          // Session start
        'page_view' => 1,      // General activity
    ];

    /**
     * Minimum activity score to be considered "active"
     */
    private const MIN_ACTIVITY_SCORE = 3;

    // =========================================================================
    // SINGLETON
    // =========================================================================

    private static ?self $instance = null;
    private ?\Redis $redis = null;

    private function __construct()
    {
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection('cache');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // ACTIVITY TRACKING
    // =========================================================================

    /**
     * Record user activity
     *
     * Increments user's activity score based on activity type.
     * Called from various points in the application.
     *
     * @param int $userId User ID
     * @param string $activityType Activity type (feed_view, post_create, etc.)
     */
    public function recordActivity(int $userId, string $activityType = 'page_view'): void
    {
        if (!$this->redis || $userId <= 0) {
            return;
        }

        try {
            $weight = self::ACTIVITY_WEIGHTS[$activityType] ?? 1;

            // Increment activity score
            $this->redis->zIncrBy(self::KEY_ACTIVE_USERS, $weight, (string) $userId);

            // Set/refresh TTL on the key
            $this->redis->expire(self::KEY_ACTIVE_USERS, self::ACTIVITY_WINDOW);

        } catch (\Exception $e) {
            // Non-critical, silent fail
        }
    }

    /**
     * Record feed view (convenience method)
     *
     * @param int $userId
     */
    public function recordFeedView(int $userId): void
    {
        $this->recordActivity($userId, 'feed_view');

        // Also track last feed access time for staleness detection
        if ($this->redis) {
            try {
                $this->redis->hSet(self::KEY_FEED_ACCESS, (string) $userId, time());
            } catch (\Exception $e) {
                // Non-critical
            }
        }
    }

    /**
     * Record post creation activity
     *
     * @param int $userId
     */
    public function recordPostCreate(int $userId): void
    {
        $this->recordActivity($userId, 'post_create');
    }

    /**
     * Record comment activity
     *
     * @param int $userId
     */
    public function recordComment(int $userId): void
    {
        $this->recordActivity($userId, 'comment');
    }

    /**
     * Record reaction activity
     *
     * @param int $userId
     */
    public function recordReaction(int $userId): void
    {
        $this->recordActivity($userId, 'reaction');
    }

    /**
     * Record login activity
     *
     * @param int $userId
     */
    public function recordLogin(int $userId): void
    {
        $this->recordActivity($userId, 'login');
    }

    // =========================================================================
    // USER RETRIEVAL
    // =========================================================================

    /**
     * Get top N active users
     *
     * Returns users sorted by activity score (descending).
     * Used by feed pre-computation worker to select candidates.
     *
     * @param int $count Number of users to return (max TOP_USERS_COUNT)
     * @return array Array of user IDs (most active first)
     */
    public function getTopActiveUsers(int $count = self::TOP_USERS_COUNT): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            $count = min($count, self::TOP_USERS_COUNT);

            // Get top users by score (descending)
            $users = $this->redis->zRevRange(self::KEY_ACTIVE_USERS, 0, $count - 1);

            return array_map('intval', $users);

        } catch (\Exception $e) {
            Logger::error('ActiveUserTracker::getTopActiveUsers failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get top active users with scores
     *
     * @param int $count Number of users to return
     * @return array Array of [userId => score]
     */
    public function getTopActiveUsersWithScores(int $count = self::TOP_USERS_COUNT): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            $count = min($count, self::TOP_USERS_COUNT);

            // Get top users with scores (descending)
            $result = $this->redis->zRevRange(self::KEY_ACTIVE_USERS, 0, $count - 1, true);

            // Convert keys to integers
            $users = [];
            foreach ($result as $userId => $score) {
                $users[(int) $userId] = (float) $score;
            }

            return $users;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get users who need feed refresh (active but stale feed)
     *
     * Returns users who:
     * 1. Are in the active users list
     * 2. Have accessed their feed recently
     * 3. Their pre-computed feed is older than threshold
     *
     * @param int $staleThresholdSeconds Feed is "stale" if older than this
     * @param int $count Max users to return
     * @return array Array of user IDs needing refresh
     */
    public function getUsersNeedingFeedRefresh(int $staleThresholdSeconds = 300, int $count = 100): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            // Get active users
            $activeUsers = $this->getTopActiveUsers($count * 2); // Get more, we'll filter

            if (empty($activeUsers)) {
                return [];
            }

            $needsRefresh = [];
            $now = time();

            foreach ($activeUsers as $userId) {
                // Check last feed access
                $lastAccess = $this->redis->hGet(self::KEY_FEED_ACCESS, (string) $userId);
                if (!$lastAccess) {
                    continue; // No feed access recorded
                }

                // Check if feed was accessed recently (user is currently active)
                $timeSinceAccess = $now - (int) $lastAccess;
                if ($timeSinceAccess > $staleThresholdSeconds * 2) {
                    continue; // User hasn't accessed feed recently, skip
                }

                // Check if pre-computed feed is stale
                $feedTimestamp = $this->redis->hGet('feed_timestamps', (string) $userId);
                if (!$feedTimestamp || ($now - (int) $feedTimestamp) > $staleThresholdSeconds) {
                    $needsRefresh[] = $userId;

                    if (count($needsRefresh) >= $count) {
                        break;
                    }
                }
            }

            return $needsRefresh;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if a user is considered "active"
     *
     * @param int $userId
     * @return bool
     */
    public function isUserActive(int $userId): bool
    {
        if (!$this->redis || $userId <= 0) {
            return false;
        }

        try {
            $score = $this->redis->zScore(self::KEY_ACTIVE_USERS, (string) $userId);
            return $score !== false && $score >= self::MIN_ACTIVITY_SCORE;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get user's activity score
     *
     * @param int $userId
     * @return float|null Score or null if not tracked
     */
    public function getUserActivityScore(int $userId): ?float
    {
        if (!$this->redis || $userId <= 0) {
            return null;
        }

        try {
            $score = $this->redis->zScore(self::KEY_ACTIVE_USERS, (string) $userId);
            return $score !== false ? (float) $score : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get user's rank among active users
     *
     * @param int $userId
     * @return int|null Rank (0 = most active) or null if not ranked
     */
    public function getUserRank(int $userId): ?int
    {
        if (!$this->redis || $userId <= 0) {
            return null;
        }

        try {
            $rank = $this->redis->zRevRank(self::KEY_ACTIVE_USERS, (string) $userId);
            return $rank !== false ? (int) $rank : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // =========================================================================
    // STATISTICS & MONITORING
    // =========================================================================

    /**
     * Get activity tracking statistics
     *
     * @return array Statistics for monitoring
     */
    public function getStatistics(): array
    {
        if (!$this->redis) {
            return ['available' => false];
        }

        try {
            $totalUsers = $this->redis->zCard(self::KEY_ACTIVE_USERS);

            // Get score distribution
            $topScores = $this->redis->zRevRange(self::KEY_ACTIVE_USERS, 0, 9, true);
            $bottomScores = $this->redis->zRange(self::KEY_ACTIVE_USERS, 0, 9, true);

            // Count users above minimum threshold
            $activeCount = $this->redis->zCount(
                self::KEY_ACTIVE_USERS,
                (string) self::MIN_ACTIVITY_SCORE,
                '+inf'
            );

            // Get TTL
            $ttl = $this->redis->ttl(self::KEY_ACTIVE_USERS);

            return [
                'available' => true,
                'total_tracked_users' => $totalUsers,
                'active_users' => $activeCount,
                'min_activity_threshold' => self::MIN_ACTIVITY_SCORE,
                'top_users_limit' => self::TOP_USERS_COUNT,
                'activity_window_seconds' => self::ACTIVITY_WINDOW,
                'ttl_remaining' => $ttl,
                'top_10_scores' => $topScores,
                'bottom_10_scores' => $bottomScores,
            ];

        } catch (\Exception $e) {
            return [
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get activity breakdown by type (for debugging)
     *
     * Note: This is an approximation since we only store total scores
     *
     * @return array Activity type weights
     */
    public function getActivityWeights(): array
    {
        return self::ACTIVITY_WEIGHTS;
    }

    // =========================================================================
    // MAINTENANCE
    // =========================================================================

    /**
     * Cleanup old activity data
     *
     * Removes users with very low activity scores.
     * Called by maintenance worker periodically.
     *
     * @param float $minScore Minimum score to keep
     * @return int Number of users removed
     */
    public function cleanup(float $minScore = 1.0): int
    {
        if (!$this->redis) {
            return 0;
        }

        try {
            // Remove users with score below threshold
            $removed = $this->redis->zRemRangeByScore(
                self::KEY_ACTIVE_USERS,
                '-inf',
                (string) ($minScore - 0.001)
            );

            if ($removed > 0) {
                Logger::info('ActiveUserTracker cleanup', [
                    'removed_users' => $removed,
                    'min_score' => $minScore,
                ]);
            }

            return (int) $removed;

        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Reset all tracking data
     *
     * Use with caution - mainly for testing/maintenance
     */
    public function reset(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->del(self::KEY_ACTIVE_USERS);
            $this->redis->del(self::KEY_FEED_ACCESS);
        } catch (\Exception $e) {
            // Best effort
        }
    }
}
