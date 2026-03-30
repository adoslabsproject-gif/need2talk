<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * HiddenPostsOverlayService - Enterprise Galaxy V4
 *
 * Overlay cache for hidden posts providing immediate visibility
 * without expensive table-level cache invalidation.
 *
 * ARCHITECTURE:
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ Layer          │ Purpose                │ TTL      │ Invalidation       │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ Overlay        │ Immediate hide/unhide  │ 10 min   │ Auto-expire        │
 * │ DB Cache       │ Persisted state        │ 5-30 min │ On flush           │
 * │ PostgreSQL     │ Source of truth        │ N/A      │ N/A                │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * REDIS KEY STRUCTURE (DB5):
 * - overlay:hidden_posts:{userId}         → SET of hidden post IDs
 * - overlay:hidden_posts_db_loaded:{userId} → flag for lazy-load
 *
 * PERFORMANCE:
 * - Hide check: <1ms (SISMEMBER)
 * - Get all hidden: <2ms (SMEMBERS)
 * - Hide/unhide: <1ms (SADD/SREM)
 *
 * @package Need2Talk\Services\Cache
 */
class HiddenPostsOverlayService
{
    private const OVERLAY_TTL = 600;        // 10 minutes

    private static ?self $instance = null;
    private ?\Redis $redis = null;

    private function __construct()
    {
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection('overlay');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if overlay service is available
     */
    public function isAvailable(): bool
    {
        return $this->redis !== null;
    }

    // =========================================================================
    // HIDDEN POSTS OVERLAY
    // =========================================================================

    /**
     * Hide a post in overlay (immediate visibility)
     *
     * @param int $userId User hiding the post
     * @param int $postId Post being hidden
     */
    public function hidePost(int $userId, int $postId): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:hidden_posts:{$userId}";
            $this->redis->sAdd($key, (string)$postId);
            $this->redis->expire($key, self::OVERLAY_TTL);

            // Mark in dirty set for persistence
            $this->markDirty($userId, $postId, 'hide');

        } catch (\Exception $e) {
            Logger::overlay('error', 'HiddenPostsOverlayService::hidePost failed', [
                'user_id' => $userId,
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Unhide a post in overlay
     *
     * @param int $userId User unhiding the post
     * @param int $postId Post being unhidden
     */
    public function unhidePost(int $userId, int $postId): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:hidden_posts:{$userId}";
            $this->redis->sRem($key, (string)$postId);

            // Mark in dirty set for persistence
            $this->markDirty($userId, $postId, 'unhide');

        } catch (\Exception $e) {
            Logger::overlay('error', 'HiddenPostsOverlayService::unhidePost failed', [
                'user_id' => $userId,
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if post is hidden in overlay
     *
     * @param int $userId User
     * @param int $postId Post
     * @return bool|null true=hidden, false=not hidden, null=not in overlay
     */
    public function isHidden(int $userId, int $postId): ?bool
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:hidden_posts:{$userId}";

            // First check if overlay has been loaded
            $loadedKey = "overlay:hidden_posts_db_loaded:{$userId}";
            $isLoaded = $this->redis->get($loadedKey);

            if (!$isLoaded) {
                // Not loaded - trigger lazy load and check result
                $hiddenIds = $this->getHiddenPostIds($userId);
                return isset($hiddenIds[$postId]);
            }

            // Overlay loaded - check directly
            return $this->redis->sIsMember($key, (string)$postId);

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get all hidden post IDs for user (with lazy-load from DB)
     *
     * @param int $userId User ID
     * @return array [postId => true, ...] for O(1) lookup
     */
    public function getHiddenPostIds(int $userId): array
    {
        if (!$this->redis) return [];

        try {
            $key = "overlay:hidden_posts:{$userId}";
            $members = $this->redis->sMembers($key);

            // ENTERPRISE V4: Lazy-load from DB if overlay is empty (cache miss)
            if (empty($members)) {
                $loadedKey = "overlay:hidden_posts_db_loaded:{$userId}";
                $alreadyLoaded = $this->redis->get($loadedKey);

                if (!$alreadyLoaded) {
                    // Mark as loaded to prevent repeated DB queries
                    $this->redis->setex($loadedKey, self::OVERLAY_TTL, '1');

                    // Load from DB
                    $dbHidden = $this->loadHiddenFromDb($userId);

                    if (!empty($dbHidden)) {
                        foreach ($dbHidden as $postId) {
                            $this->redis->sAdd($key, (string)$postId);
                        }
                        $this->redis->expire($key, self::OVERLAY_TTL);

                        // Return loaded IDs
                        $hidden = [];
                        foreach ($dbHidden as $postId) {
                            $hidden[(int)$postId] = true;
                        }
                        return $hidden;
                    }

                    return [];
                }

                // Already loaded from DB but empty
                return [];
            }

            // Overlay has data - use it
            $hidden = [];
            foreach ($members as $postId) {
                $hidden[(int)$postId] = true;
            }

            return $hidden;

        } catch (\Exception $e) {
            Logger::overlay('error', 'HiddenPostsOverlayService::getHiddenPostIds failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Load hidden posts from database (lazy-load fallback)
     *
     * @param int $userId User ID
     * @return array List of hidden post IDs
     */
    private function loadHiddenFromDb(int $userId): array
    {
        try {
            $db = db();

            $rows = $db->query(
                "SELECT audio_post_id FROM hidden_posts WHERE user_id = ?",
                [$userId],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            return array_column($rows, 'audio_post_id');

        } catch (\Exception $e) {
            Logger::overlay('error', 'HiddenPostsOverlayService::loadHiddenFromDb failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // DIRTY SET FOR FLUSH
    // =========================================================================

    /**
     * Mark a change as dirty for later DB flush
     */
    private function markDirty(int $userId, int $postId, string $action): void
    {
        if (!$this->redis) return;

        try {
            $member = json_encode([
                'user_id' => $userId,
                'post_id' => $postId,
                'action' => $action,
                'ts' => microtime(true),
            ]);

            $this->redis->zAdd('overlay:dirty:hidden_posts', microtime(true), $member);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get pending hidden post changes for flush
     *
     * @param int $limit Max items to retrieve
     * @return array Pending changes
     */
    public function getPendingChanges(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $items = $this->redis->zRange('overlay:dirty:hidden_posts', 0, $limit - 1);
            $result = [];

            foreach ($items as $json) {
                $data = json_decode($json, true);
                if ($data) {
                    $data['_raw'] = $json;
                    $result[] = $data;
                }
            }

            return $result;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Remove flushed items from dirty set
     *
     * @param array $items Items with '_raw' key
     */
    public function removeFlushedItems(array $items): void
    {
        if (!$this->redis || empty($items)) return;

        try {
            foreach ($items as $item) {
                if (isset($item['_raw'])) {
                    $this->redis->zRem('overlay:dirty:hidden_posts', $item['_raw']);
                }
            }
        } catch (\Exception $e) {
            Logger::overlay('error', 'HiddenPostsOverlayService::removeFlushedItems failed', [
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist dirty changes to database
     *
     * Called by cron job or at strategic points to ensure durability
     */
    public function flushToDatabase(): int
    {
        $changes = $this->getPendingChanges(100);
        if (empty($changes)) {
            return 0;
        }

        $db = db();
        $flushed = 0;

        foreach ($changes as $change) {
            try {
                if ($change['action'] === 'hide') {
                    $db->query(
                        "INSERT INTO hidden_posts (user_id, audio_post_id, hidden_at)
                         VALUES (?, ?, NOW())
                         ON CONFLICT (user_id, audio_post_id) DO NOTHING",
                        [$change['user_id'], $change['post_id']]
                    );
                } elseif ($change['action'] === 'unhide') {
                    $db->query(
                        "DELETE FROM hidden_posts WHERE user_id = ? AND audio_post_id = ?",
                        [$change['user_id'], $change['post_id']]
                    );
                }
                $flushed++;
            } catch (\Exception $e) {
                Logger::overlay('error', 'HiddenPostsOverlayService::flushToDatabase item failed', [
                    'change' => $change,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Remove flushed items
        $this->removeFlushedItems($changes);

        return $flushed;
    }

    /**
     * Get buffer status for monitoring
     */
    public function getBufferStatus(): array
    {
        if (!$this->redis) {
            return ['available' => false];
        }

        try {
            return [
                'available' => true,
                'hidden_posts_pending' => $this->redis->zCard('overlay:dirty:hidden_posts'),
            ];
        } catch (\Exception $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }
}
