<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;
use Need2Talk\Services\Audio\Social\AudioPostService;
use Need2Talk\Services\Cache\OverlayService;
use Need2Talk\Services\Cache\CommentOverlayService;

/**
 * FeedPrecomputeService - Enterprise Galaxy V8.0
 *
 * FEED PRE-COMPUTATION WITH CIRCUIT BREAKER (2025-12-01)
 *
 * Pre-computes feeds for active users to reduce response time from 50-100ms to 5-10ms.
 * Implements circuit breaker pattern to gracefully degrade under load.
 *
 * ARCHITECTURE:
 * ┌───────────────────────────────────────────────────────────────────────────────┐
 * │ Request Flow:                                                                 │
 * │                                                                               │
 * │ 1. User requests feed                                                         │
 * │ 2. Check pre-computed cache → HIT → Return (5ms)                             │
 * │ 3. Cache MISS → Calculate on-demand (50-100ms)                               │
 * │ 4. Background: Queue for pre-computation                                      │
 * │                                                                               │
 * │ Pre-computation Worker:                                                       │
 * │ 1. Get top N active users from ActiveUserTracker                             │
 * │ 2. For each user: compute feed → store in Redis                              │
 * │ 3. Feed TTL: 5 minutes (stale-while-revalidate)                              │
 * └───────────────────────────────────────────────────────────────────────────────┘
 *
 * CIRCUIT BREAKER:
 * ┌───────────────────────────────────────────────────────────────────────────────┐
 * │ Queue Size > 10,000 → OPEN → Skip pre-computation, serve on-demand           │
 * │ Queue Size < 5,000  → CLOSED → Normal pre-computation                        │
 * │                                                                               │
 * │ This prevents:                                                                │
 * │ - Runaway queue growth during traffic spikes                                 │
 * │ - Worker overload causing cascading failures                                 │
 * │ - Stale pre-computed feeds being served indefinitely                         │
 * └───────────────────────────────────────────────────────────────────────────────┘
 *
 * @package Need2Talk\Services\Cache
 */
class FeedPrecomputeService
{
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Feed cache TTL in seconds (5 minutes)
     */
    private const FEED_TTL = 300;

    /**
     * Number of posts to pre-compute per feed
     */
    private const FEED_SIZE = 50;

    /**
     * Circuit breaker: open threshold (stop pre-computation)
     */
    private const CIRCUIT_BREAKER_OPEN_THRESHOLD = 10000;

    /**
     * Circuit breaker: close threshold (resume pre-computation)
     */
    private const CIRCUIT_BREAKER_CLOSE_THRESHOLD = 5000;

    /**
     * Stale threshold: serve stale while revalidating
     */
    private const STALE_THRESHOLD = 180; // 3 minutes

    /**
     * Maximum queue refresh rate (per second)
     */
    private const MAX_REFRESH_RATE = 100;

    /**
     * Redis key prefixes
     */
    private const KEY_FEED_PREFIX = 'precomputed_feed:';
    private const KEY_TIMESTAMPS = 'feed_timestamps';
    private const KEY_REFRESH_QUEUE = 'feed_refresh_queue';
    private const KEY_CIRCUIT_STATE = 'feed_circuit_breaker_state';
    private const KEY_METRICS = 'feed_precompute_metrics';

    // =========================================================================
    // SINGLETON
    // =========================================================================

    private static ?self $instance = null;
    private ?\Redis $redis = null;
    private ?AudioPostService $audioPostService = null;
    private ?ActiveUserTracker $activeUserTracker = null;

    private function __construct()
    {
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection('cache');
        $this->activeUserTracker = ActiveUserTracker::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Lazy load AudioPostService to avoid circular dependencies
     */
    private function getAudioPostService(): AudioPostService
    {
        if ($this->audioPostService === null) {
            $this->audioPostService = new AudioPostService();
        }
        return $this->audioPostService;
    }

    // =========================================================================
    // FEED RETRIEVAL (HOT PATH)
    // =========================================================================

    /**
     * Get feed for user (tries pre-computed first)
     *
     * This is the main entry point called by AudioPostService::getFeed()
     *
     * @param int $userId User ID
     * @param int $limit Max posts to return
     * @param int $offset Pagination offset
     * @return array|null Pre-computed feed or null if not available
     */
    public function getFeed(int $userId, int $limit = 20, int $offset = 0): ?array
    {
        if (!$this->redis || $userId <= 0) {
            return null;
        }

        // Only serve pre-computed for first page
        if ($offset > 0 || $limit > self::FEED_SIZE) {
            return null;
        }

        try {
            $startTime = microtime(true);

            // Check circuit breaker
            if ($this->isCircuitOpen()) {
                $this->incrementMetric('circuit_breaker_rejections');
                return null; // Fall back to on-demand
            }

            // Try to get pre-computed feed
            $key = self::KEY_FEED_PREFIX . $userId;
            $cached = $this->redis->get($key);

            if ($cached) {
                $feed = unserialize($cached);

                // Check if feed is valid
                if (is_array($feed) && !empty($feed)) {
                    // ENTERPRISE V8.1: Apply FRESH overlay deltas at serving time
                    // This ensures play counts are always up-to-date even if user
                    // listened to audio AFTER the feed was pre-computed
                    $feed = $this->applyFreshOverlayDeltas($feed);

                    // Record metrics
                    $durationMs = (microtime(true) - $startTime) * 1000;
                    $this->incrementMetric('cache_hits');
                    $this->incrementMetric('total_latency_ms', (int) $durationMs);

                    // Check if stale (trigger background refresh)
                    $this->checkStaleness($userId);

                    // Return sliced feed
                    return array_slice($feed, 0, $limit);
                }
            }

            // Cache miss - queue for pre-computation
            $this->incrementMetric('cache_misses');
            $this->queueFeedRefresh($userId);

            return null;

        } catch (\Exception $e) {
            Logger::error('FeedPrecomputeService::getFeed failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if feed is stale and queue refresh
     */
    private function checkStaleness(int $userId): void
    {
        try {
            $timestamp = $this->redis->hGet(self::KEY_TIMESTAMPS, (string) $userId);

            if ($timestamp && (time() - (int) $timestamp) > self::STALE_THRESHOLD) {
                // Feed is stale, queue background refresh
                $this->queueFeedRefresh($userId, 'stale_revalidate');
            }
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * ENTERPRISE V11 (2025-12-11): Apply fresh overlay at serving time - ABSOLUTE VALUE
     *
     * V11 FIX: Switched from DELTA to ABSOLUTE VALUE pattern (like reactions).
     *
     * THE V10 DELTA BUG:
     *   display = precomputed_base + overlay_delta
     *   After flush: delta=0, but precomputed base is stale → WRONG count!
     *
     * V11 ABSOLUTE VALUE SOLUTION:
     *   display = overlay ?? precomputed_value (overlay wins if present)
     *   After flush: overlay=NULL → use fresh DB value next time
     *
     * PATTERN:
     * - If overlay exists (not null) → use overlay value (it's ABSOLUTE)
     * - If overlay is null → keep precomputed/DB value (no overlay = no recent change)
     *
     * PERFORMANCE:
     * - Redis MGET for batch of ~20 posts = ~2-3ms
     * - Much faster than re-computing entire feed (50-100ms)
     *
     * @param array $feed Pre-computed feed posts
     * @return array Feed with fresh overlay values applied (plays + comments)
     */
    private function applyFreshOverlayDeltas(array $feed): array
    {
        if (empty($feed)) {
            return $feed;
        }

        try {
            // =========================================================================
            // STEP 1: Extract IDs for batch fetching
            // =========================================================================
            $audioFileIds = [];
            $postIds = [];

            foreach ($feed as $post) {
                $audioFileId = (int) ($post['audio_file_id'] ?? 0);
                $postId = (int) ($post['id'] ?? 0);

                if ($audioFileId > 0) {
                    $audioFileIds[] = $audioFileId;
                }
                if ($postId > 0) {
                    $postIds[] = $postId;
                }
            }

            // =========================================================================
            // STEP 2: Fetch V11 ABSOLUTE play counts from OverlayService
            // =========================================================================
            $playOverlays = [];
            $overlayService = OverlayService::getInstance();

            if ($overlayService->isAvailable() && !empty($audioFileIds)) {
                // V11: getBatchPlayAbsolutes returns [fileId => int|null]
                // null = no overlay, use precomputed value
                $playOverlays = $overlayService->getBatchPlayAbsolutes($audioFileIds);
            }

            // =========================================================================
            // STEP 3: Fetch V11 ABSOLUTE comment counts from OverlayService
            // =========================================================================
            $commentOverlays = [];

            if ($overlayService->isAvailable() && !empty($postIds)) {
                // V11: getBatchCommentAbsolutes returns [postId => int|null]
                // null = no overlay, use precomputed value
                $commentOverlays = $overlayService->getBatchCommentAbsolutes($postIds);
            }

            // =========================================================================
            // STEP 4: Apply "overlay wins" pattern to feed posts
            // =========================================================================
            foreach ($feed as &$post) {
                $audioFileId = (int) ($post['audio_file_id'] ?? 0);
                $postId = (int) ($post['id'] ?? 0);

                // --- V11: Apply PLAY count - "overlay wins" if present ---
                if ($audioFileId > 0 && isset($playOverlays[$audioFileId])) {
                    $overlayValue = $playOverlays[$audioFileId];

                    // V11: If overlay is not null, USE IT (it's the absolute truth)
                    // If null, keep precomputed value unchanged
                    if ($overlayValue !== null) {
                        $post['listen_count'] = $overlayValue;
                        $post['play_count'] = $overlayValue;
                        if (isset($post['stats']) && is_array($post['stats'])) {
                            $post['stats']['listens'] = $overlayValue;
                        }
                    }
                }

                // --- V11: Apply COMMENT count - "overlay wins" if present ---
                if ($postId > 0 && isset($commentOverlays[$postId])) {
                    $overlayValue = $commentOverlays[$postId];

                    // V11: If overlay is not null, USE IT (it's the absolute truth)
                    // If null, keep precomputed value unchanged
                    if ($overlayValue !== null) {
                        $post['comment_count'] = $overlayValue;
                        if (isset($post['stats']) && is_array($post['stats'])) {
                            $post['stats']['comments'] = $overlayValue;
                        }
                    }
                }
            }
            unset($post);

            return $feed;

        } catch (\Exception $e) {
            Logger::overlay('warning', 'applyFreshOverlayDeltas V11 failed', [
                'post_count' => count($feed),
                'error' => $e->getMessage(),
            ]);
            // Return feed unchanged on error (graceful degradation)
            return $feed;
        }
    }

    // =========================================================================
    // FEED PRE-COMPUTATION (WORKER PATH)
    // =========================================================================

    /**
     * Pre-compute feed for a user
     *
     * Called by feed-precompute-worker.php
     *
     * @param int $userId User ID
     * @return array The computed feed
     */
    public function precomputeFeed(int $userId): array
    {
        if (!$this->redis || $userId <= 0) {
            return [];
        }

        $startTime = microtime(true);

        try {
            // Check circuit breaker
            if ($this->isCircuitOpen()) {
                $this->incrementMetric('precompute_circuit_rejections');
                return [];
            }

            // Compute feed using AudioPostService
            $feed = $this->getAudioPostService()->getFeedInternal($userId, self::FEED_SIZE, 0);

            if (empty($feed)) {
                // Empty feed is valid, cache it to avoid repeated computation
                $feed = [];
            }

            // Store in Redis
            $key = self::KEY_FEED_PREFIX . $userId;
            $this->redis->setex($key, self::FEED_TTL, serialize($feed));

            // Store generation timestamp
            $this->redis->hSet(self::KEY_TIMESTAMPS, (string) $userId, time());

            // Record metrics
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->incrementMetric('precomputes');
            $this->incrementMetric('precompute_latency_ms', (int) $durationMs);

            Logger::overlay('debug', 'Feed precomputed', [
                'user_id' => $userId,
                'post_count' => count($feed),
                'duration_ms' => round($durationMs, 2),
            ]);

            return $feed;

        } catch (\Exception $e) {
            $this->incrementMetric('precompute_errors');
            Logger::error('FeedPrecomputeService::precomputeFeed failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Batch pre-compute feeds for multiple users
     *
     * More efficient than calling precomputeFeed() in a loop
     *
     * @param array $userIds Array of user IDs
     * @return array Results per user [userId => postCount]
     */
    public function batchPrecomputeFeeds(array $userIds): array
    {
        $results = [];

        foreach ($userIds as $userId) {
            if (!$this->isCircuitOpen()) {
                $feed = $this->precomputeFeed((int) $userId);
                $results[$userId] = count($feed);
            } else {
                $results[$userId] = -1; // Circuit open
            }
        }

        return $results;
    }

    // =========================================================================
    // REFRESH QUEUE
    // =========================================================================

    /**
     * Queue a user for feed refresh
     *
     * @param int $userId User ID
     * @param string $reason Reason for refresh (for debugging)
     */
    public function queueFeedRefresh(int $userId, string $reason = 'cache_miss'): void
    {
        if (!$this->redis || $userId <= 0) {
            return;
        }

        try {
            // Add to refresh queue (SET for deduplication)
            $this->redis->sAdd(self::KEY_REFRESH_QUEUE, (string) $userId);

            // Check queue size for circuit breaker
            $queueSize = $this->redis->sCard(self::KEY_REFRESH_QUEUE);
            $this->updateCircuitBreaker((int) $queueSize);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get users from refresh queue
     *
     * @param int $count Number of users to pop
     * @return array Array of user IDs
     */
    public function getRefreshQueue(int $count = 100): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            $users = [];

            // Pop multiple users from queue
            for ($i = 0; $i < $count; $i++) {
                $userId = $this->redis->sPop(self::KEY_REFRESH_QUEUE);
                if (!$userId) {
                    break;
                }
                $users[] = (int) $userId;
            }

            return $users;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get current refresh queue size
     *
     * @return int Queue size
     */
    public function getQueueSize(): int
    {
        if (!$this->redis) {
            return 0;
        }

        try {
            return $this->redis->sCard(self::KEY_REFRESH_QUEUE);
        } catch (\Exception $e) {
            return 0;
        }
    }

    // =========================================================================
    // CIRCUIT BREAKER
    // =========================================================================

    /**
     * Check if circuit breaker is open
     *
     * When open, skip pre-computation and serve on-demand
     *
     * @return bool True if circuit is open (skip pre-computation)
     */
    public function isCircuitOpen(): bool
    {
        if (!$this->redis) {
            return true; // Fail-safe: treat as open
        }

        try {
            $state = $this->redis->get(self::KEY_CIRCUIT_STATE);
            return $state === 'open';
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Update circuit breaker state based on queue size
     *
     * @param int $queueSize Current queue size
     */
    private function updateCircuitBreaker(int $queueSize): void
    {
        try {
            $currentState = $this->redis->get(self::KEY_CIRCUIT_STATE) ?? 'closed';

            if ($queueSize >= self::CIRCUIT_BREAKER_OPEN_THRESHOLD && $currentState !== 'open') {
                // Open circuit
                $this->redis->set(self::KEY_CIRCUIT_STATE, 'open');
                $this->incrementMetric('circuit_opened');

                Logger::overlay('warning', 'Feed circuit breaker OPENED', [
                    'queue_size' => $queueSize,
                    'threshold' => self::CIRCUIT_BREAKER_OPEN_THRESHOLD,
                ]);

            } elseif ($queueSize <= self::CIRCUIT_BREAKER_CLOSE_THRESHOLD && $currentState === 'open') {
                // Close circuit
                $this->redis->set(self::KEY_CIRCUIT_STATE, 'closed');
                $this->incrementMetric('circuit_closed');

                Logger::overlay('info', 'Feed circuit breaker CLOSED', [
                    'queue_size' => $queueSize,
                    'threshold' => self::CIRCUIT_BREAKER_CLOSE_THRESHOLD,
                ]);
            }
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Force circuit breaker state (for testing/recovery)
     *
     * @param bool $open True to open, false to close
     */
    public function setCircuitState(bool $open): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->set(self::KEY_CIRCUIT_STATE, $open ? 'open' : 'closed');
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    // =========================================================================
    // CACHE INVALIDATION
    // =========================================================================

    /**
     * Invalidate feed for a user
     *
     * Called when user's feed should be refreshed (new post, friendship change)
     *
     * @param int $userId User ID
     */
    public function invalidateFeed(int $userId): void
    {
        if (!$this->redis || $userId <= 0) {
            return;
        }

        try {
            $key = self::KEY_FEED_PREFIX . $userId;
            $this->redis->del($key);
            $this->redis->hDel(self::KEY_TIMESTAMPS, (string) $userId);

            // Queue for re-computation if user is active
            if ($this->activeUserTracker->isUserActive($userId)) {
                $this->queueFeedRefresh($userId, 'invalidation');
            }

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Invalidate feeds for multiple users
     *
     * @param array $userIds Array of user IDs
     */
    public function invalidateFeeds(array $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->invalidateFeed((int) $userId);
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
            $metrics = $this->redis->hGetAll(self::KEY_METRICS);

            // Calculate derived metrics
            $hits = (int) ($metrics['cache_hits'] ?? 0);
            $misses = (int) ($metrics['cache_misses'] ?? 0);
            $total = $hits + $misses;

            $metrics['hit_rate'] = $total > 0 ? round($hits / $total * 100, 2) . '%' : '0%';

            $precomputes = (int) ($metrics['precomputes'] ?? 0);
            $latency = (int) ($metrics['precompute_latency_ms'] ?? 0);
            $metrics['avg_precompute_ms'] = $precomputes > 0 ? round($latency / $precomputes, 2) : 0;

            // Add current state
            $metrics['circuit_state'] = $this->isCircuitOpen() ? 'open' : 'closed';
            $metrics['queue_size'] = $this->getQueueSize();

            return $metrics;

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
            'circuit_state' => $this->isCircuitOpen() ? 'open' : 'closed',
            'queue_size' => $this->getQueueSize(),
            'circuit_open_threshold' => self::CIRCUIT_BREAKER_OPEN_THRESHOLD,
            'circuit_close_threshold' => self::CIRCUIT_BREAKER_CLOSE_THRESHOLD,
            'feed_ttl_seconds' => self::FEED_TTL,
            'feed_size' => self::FEED_SIZE,
            'stale_threshold_seconds' => self::STALE_THRESHOLD,
            'metrics' => $this->getMetrics(),
        ];
    }
}
