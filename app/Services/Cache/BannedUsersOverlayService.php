<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * BannedUsersOverlayService - ENTERPRISE GALAXY V4.7 (2025-12-06)
 *
 * REAL-TIME FEED FILTERING FOR BANNED USERS
 *
 * When an admin bans a user, their posts should disappear from ALL feeds
 * IMMEDIATELY, without waiting for cache expiration.
 *
 * ARCHITECTURE:
 * ┌─────────────────────────────────────────────────────────────────────────────┐
 * │                    BANNED USERS OVERLAY                                      │
 * ├─────────────────────────────────────────────────────────────────────────────┤
 * │  Redis SET: need2talk:overlay:banned_users                                  │
 * │  Members: user IDs of banned users                                          │
 * │  TTL: None (persistent until user unbanned)                                 │
 * │                                                                              │
 * │  On Ban:                                                                     │
 * │  1. SADD need2talk:overlay:banned_users {user_id}                           │
 * │  2. All subsequent feed loads filter out this user's posts                  │
 * │                                                                              │
 * │  On Unban:                                                                   │
 * │  1. SREM need2talk:overlay:banned_users {user_id}                           │
 * │  2. User's posts immediately visible again                                  │
 * │                                                                              │
 * │  Feed Merge:                                                                 │
 * │  - OverlayBatchLoader checks banned set during applyOverlays()              │
 * │  - Posts from banned users filtered (like tombstones)                       │
 * │  - Zero cache invalidation needed (instant effect)                          │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * PERFORMANCE:
 * - SISMEMBER is O(1) - constant time regardless of set size
 * - Single Redis call for batch check via pipeline
 * - No feed cache invalidation required
 *
 * @package Need2Talk\Services\Cache
 */
class BannedUsersOverlayService
{
    private const KEY_BANNED_USERS = 'need2talk:overlay:banned_users';

    private static ?self $instance = null;
    private ?\Redis $redis = null;

    private function __construct()
    {
        try {
            $this->redis = EnterpriseRedisManager::getInstance()->getConnection('overlay');
        } catch (\Exception $e) {
            Logger::error('BannedUsersOverlayService: Failed to connect to Redis', [
                'error' => $e->getMessage(),
            ]);
            $this->redis = null;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if service is available
     */
    public function isAvailable(): bool
    {
        return $this->redis !== null;
    }

    // =========================================================================
    // BAN/UNBAN OPERATIONS (called by AdminController)
    // =========================================================================

    /**
     * Add user to banned set
     *
     * Called when admin bans a user. Instant effect on all feeds.
     *
     * @param int $userId User ID to ban
     * @return bool Success
     */
    public function banUser(int $userId): bool
    {
        if (!$this->redis || $userId <= 0) {
            return false;
        }

        try {
            $result = $this->redis->sAdd(self::KEY_BANNED_USERS, (string) $userId);

            Logger::security('warning', 'BannedUsersOverlay: User added to banned set', [
                'user_id' => $userId,
                'result' => $result,
            ]);

            return $result !== false;
        } catch (\Exception $e) {
            Logger::error('BannedUsersOverlay::banUser failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove user from banned set
     *
     * Called when admin unbans/reactivates a user. Instant visibility restoration.
     *
     * @param int $userId User ID to unban
     * @return bool Success
     */
    public function unbanUser(int $userId): bool
    {
        if (!$this->redis || $userId <= 0) {
            return false;
        }

        try {
            $result = $this->redis->sRem(self::KEY_BANNED_USERS, (string) $userId);

            Logger::security('info', 'BannedUsersOverlay: User removed from banned set', [
                'user_id' => $userId,
                'result' => $result,
            ]);

            return $result !== false;
        } catch (\Exception $e) {
            Logger::error('BannedUsersOverlay::unbanUser failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // CHECK OPERATIONS (called during feed loading)
    // =========================================================================

    /**
     * Check if a single user is banned
     *
     * O(1) operation - very fast
     *
     * @param int $userId User ID to check
     * @return bool True if banned
     */
    public function isUserBanned(int $userId): bool
    {
        if (!$this->redis || $userId <= 0) {
            return false;
        }

        try {
            return (bool) $this->redis->sIsMember(self::KEY_BANNED_USERS, (string) $userId);
        } catch (\Exception $e) {
            Logger::error('BannedUsersOverlay::isUserBanned failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Batch check which users are banned
     *
     * Uses pipeline for efficient batch checking.
     *
     * @param array $userIds Array of user IDs to check
     * @return array [userId => bool] Map of user ID to banned status
     */
    public function getBannedStatusBatch(array $userIds): array
    {
        $result = [];

        if (!$this->redis || empty($userIds)) {
            foreach ($userIds as $userId) {
                $result[$userId] = false;
            }
            return $result;
        }

        try {
            $pipe = $this->redis->pipeline();

            foreach ($userIds as $userId) {
                $pipe->sIsMember(self::KEY_BANNED_USERS, (string) $userId);
            }

            $pipeResults = $pipe->exec();

            foreach ($userIds as $index => $userId) {
                $result[$userId] = (bool) ($pipeResults[$index] ?? false);
            }

            return $result;
        } catch (\Exception $e) {
            Logger::error('BannedUsersOverlay::getBannedStatusBatch failed', [
                'user_count' => count($userIds),
                'error' => $e->getMessage(),
            ]);

            // Fallback: assume none banned (fail-open)
            foreach ($userIds as $userId) {
                $result[$userId] = false;
            }
            return $result;
        }
    }

    /**
     * Filter posts by removing those from banned users
     *
     * Called by OverlayBatchLoader::applyOverlays() or directly in feed service.
     *
     * @param array $posts Array of posts with 'user_id' field
     * @return array Filtered posts (banned users' posts removed)
     */
    public function filterBannedUsersPosts(array $posts): array
    {
        if (!$this->redis || empty($posts)) {
            return $posts;
        }

        // Extract unique user IDs from posts
        $userIds = array_unique(array_filter(array_map(function ($post) {
            return (int) ($post['user_id'] ?? 0);
        }, $posts)));

        if (empty($userIds)) {
            return $posts;
        }

        // Batch check banned status
        $bannedStatus = $this->getBannedStatusBatch($userIds);

        // Filter out posts from banned users
        $filtered = array_filter($posts, function ($post) use ($bannedStatus) {
            $userId = (int) ($post['user_id'] ?? 0);
            return !($bannedStatus[$userId] ?? false);
        });

        $removedCount = count($posts) - count($filtered);
        if ($removedCount > 0) {
            Logger::overlay('debug', 'BannedUsersOverlay: Filtered posts from banned users', [
                'original_count' => count($posts),
                'filtered_count' => count($filtered),
                'removed_count' => $removedCount,
            ]);
        }

        return array_values($filtered); // Re-index array
    }

    // =========================================================================
    // ADMIN/DEBUG OPERATIONS
    // =========================================================================

    /**
     * Get all banned user IDs
     *
     * For admin dashboard/debugging only.
     *
     * @return array Array of banned user IDs
     */
    public function getAllBannedUserIds(): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            $members = $this->redis->sMembers(self::KEY_BANNED_USERS);
            return array_map('intval', $members ?: []);
        } catch (\Exception $e) {
            Logger::error('BannedUsersOverlay::getAllBannedUserIds failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get count of banned users
     *
     * @return int Number of banned users in overlay
     */
    public function getBannedCount(): int
    {
        if (!$this->redis) {
            return 0;
        }

        try {
            return (int) $this->redis->sCard(self::KEY_BANNED_USERS);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Sync overlay with database
     *
     * Called on startup or after Redis flush to ensure consistency.
     * Loads all banned users from DB into Redis set.
     *
     * @return int Number of users synced
     */
    public function syncFromDatabase(): int
    {
        if (!$this->redis) {
            return 0;
        }

        try {
            $db = db();

            // Get all banned users from database
            $bannedUsers = $db->findMany(
                "SELECT id FROM users WHERE status = 'banned' AND deleted_at IS NULL",
                [],
                ['cache' => false]
            );

            if (empty($bannedUsers)) {
                // Clear the set if no banned users
                $this->redis->del(self::KEY_BANNED_USERS);
                return 0;
            }

            // Use pipeline to add all at once
            $pipe = $this->redis->pipeline();
            $pipe->del(self::KEY_BANNED_USERS); // Clear existing

            foreach ($bannedUsers as $user) {
                $pipe->sAdd(self::KEY_BANNED_USERS, (string) $user['id']);
            }

            $pipe->exec();

            $count = count($bannedUsers);

            Logger::info('BannedUsersOverlay: Synced from database', [
                'banned_count' => $count,
            ]);

            return $count;
        } catch (\Exception $e) {
            Logger::error('BannedUsersOverlay::syncFromDatabase failed', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get service status for admin dashboard
     *
     * @return array Status info
     */
    public function getStatus(): array
    {
        return [
            'available' => $this->isAvailable(),
            'banned_count' => $this->getBannedCount(),
            'redis_key' => self::KEY_BANNED_USERS,
        ];
    }
}
