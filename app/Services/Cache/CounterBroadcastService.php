<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;
use Need2Talk\Services\WebSocketPublisher;

/**
 * CounterBroadcastService - Enterprise Real-Time Counter Updates
 *
 * ENTERPRISE GALAXY V10.9 (2025-12-03)
 *
 * Broadcasts counter updates (comments, plays) to users viewing specific posts.
 * Integrates with WebSocket Post Viewer Subscription system for targeted delivery.
 *
 * ARCHITECTURE:
 * - Throttled broadcasts: Max 1 update per second per post (prevents viral post spam)
 * - Batched updates: Multiple rapid counter changes merged into single broadcast
 * - Redis-based state: Throttle + pending updates shared across PHP-FPM workers
 * - Targeted delivery: Only users currently viewing the post receive updates
 *
 * PERFORMANCE:
 * - Throttle check: O(1) Redis GET (~0.1ms)
 * - Broadcast: Redis PUBLISH (~0.1ms) + WebSocket delivery (~1ms per recipient)
 * - Memory: Minimal (2 keys per active post, 10s TTL on pending)
 *
 * SCALABILITY:
 * - 100k+ concurrent users: Each user subscribes to max 50 visible posts
 * - Viral posts (10k+ viewers): Throttle ensures max 1 broadcast/second
 * - Cross-worker: Redis PubSub delivers to all WebSocket workers
 *
 * USAGE:
 * // After creating comment
 * CounterBroadcastService::getInstance()->broadcastCommentCount($postId, 1);
 *
 * // After tracking play
 * CounterBroadcastService::getInstance()->broadcastPlayCount($postId, $audioFileId, 1);
 *
 * INTEGRATION:
 * - CommentRepository::create() → broadcastCommentCount()
 * - PlayTrackingService::trackPlay() → broadcastPlayCount()
 * - websocket-server.php → handles subscribe_posts, broadcasts to viewers
 *
 * @package Need2Talk\Services\Cache
 * @author  need2talk Enterprise Team
 * @version 1.0.0
 */
class CounterBroadcastService
{
    /**
     * Throttle window in seconds
     * ENTERPRISE: 1 second prevents broadcast spam on viral posts
     * With 1000 rapid comments, only ~1 broadcast/second is sent
     */
    private const THROTTLE_SECONDS = 1;

    /**
     * Redis key prefix for throttle state
     */
    private const THROTTLE_PREFIX = 'counter:throttle:';

    /**
     * Redis key prefix for pending updates (batching)
     */
    private const PENDING_PREFIX = 'counter:pending:';

    /**
     * TTL for pending updates (seconds)
     * If no broadcast happens within this time, pending updates are discarded
     */
    private const PENDING_TTL = 10;

    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    /**
     * Redis connection (overlay DB)
     */
    private ?\Redis $redis = null;

    /**
     * Service availability flag
     */
    private bool $available = false;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor (singleton pattern)
     */
    private function __construct()
    {
        $this->initRedis();
    }

    /**
     * Initialize Redis connection
     */
    private function initRedis(): void
    {
        try {
            // Use overlay Redis DB (same as overlay services)
            $this->redis = EnterpriseRedisManager::getInstance()
                ->getConnection('overlay');

            if ($this->redis !== null) {
                $this->available = true;
            }
        } catch (\Exception $e) {
            Logger::error('CounterBroadcastService: Redis init failed', [
                'error' => $e->getMessage(),
            ]);
            $this->available = false;
        }
    }

    /**
     * Check if service is available
     */
    public function isAvailable(): bool
    {
        return $this->available && $this->redis !== null;
    }

    /**
     * Broadcast comment count update to post viewers
     *
     * Call after CommentRepository::create() or CommentRepository::delete()
     *
     * @param int $postId Audio post ID
     * @param int $delta Change amount (+1 for new comment, -1 for deleted)
     * @return bool True if broadcast sent or queued, false on error
     */
    public function broadcastCommentCount(int $postId, int $delta = 1): bool
    {
        return $this->broadcastCounterUpdate($postId, 'comment_count', $delta);
    }

    /**
     * Broadcast play count update to post viewers
     *
     * ENTERPRISE GALAXY V10.2 (2025-12-10): Added actorUserUuid to prevent double-counting
     * Frontend ignores broadcasts where actorUserUuid matches current user (optimistic update already applied)
     * NOTE: Uses UUID (not numeric ID) to maintain security (prevent user enumeration)
     *
     * Call after PlayTrackingService::trackPlay() returns counted=true
     *
     * @param int $postId Audio post ID
     * @param int $audioFileId Audio file ID (for frontend tracking)
     * @param int $delta Change amount (always +1 for plays)
     * @param string|null $actorUserUuid UUID of user who triggered the action (to exclude from increment)
     * @return bool True if broadcast sent or queued, false on error
     */
    public function broadcastPlayCount(int $postId, int $audioFileId, int $delta = 1, ?string $actorUserUuid = null): bool
    {
        return $this->broadcastCounterUpdate($postId, 'play_count', $delta, [
            'audio_file_id' => $audioFileId,
            'actor_user_uuid' => $actorUserUuid,
        ]);
    }

    /**
     * Core broadcast with throttling and batching
     *
     * ENTERPRISE THROTTLE ALGORITHM:
     * 1. Check if post was broadcast in last THROTTLE_SECONDS
     * 2. If yes: Queue delta for next broadcast window (batching)
     * 3. If no: Merge pending deltas + current delta, broadcast, set throttle
     *
     * BATCHING EXAMPLE:
     * - T+0.0s: Comment A → broadcast immediately (comment_count: +1)
     * - T+0.3s: Comment B → queued (pending: +1)
     * - T+0.6s: Comment C → queued (pending: +2)
     * - T+1.0s: Comment D → throttle expired, broadcast (comment_count: +3)
     *
     * Result: 4 comments in 1 second = 2 broadcasts (not 4)
     *
     * @param int $postId Post ID
     * @param string $counterType Counter name (comment_count, play_count)
     * @param int $delta Change amount
     * @param array $extra Additional payload data
     * @return bool Success
     */
    private function broadcastCounterUpdate(
        int $postId,
        string $counterType,
        int $delta,
        array $extra = []
    ): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            // ENTERPRISE: Throttle check - max 1 broadcast per second per post
            $throttleKey = self::THROTTLE_PREFIX . $postId;
            $lastBroadcast = $this->redis->get($throttleKey);

            if ($lastBroadcast !== false && (time() - (int)$lastBroadcast) < self::THROTTLE_SECONDS) {
                // Within throttle window - queue for next broadcast
                $this->queuePendingUpdate($postId, $counterType, $delta);
                return true; // Queued successfully
            }

            // ENTERPRISE: Merge pending updates with current delta
            $pending = $this->getPendingUpdates($postId);
            $pending[$counterType] = ($pending[$counterType] ?? 0) + $delta;

            // ENTERPRISE: Build payload for WebSocket broadcast
            $payload = [
                'post_id' => $postId,
                'counters' => $pending,
                'timestamp' => microtime(true),
            ];

            // Add extra data (e.g., audio_file_id for play tracking)
            if (!empty($extra)) {
                $payload = array_merge($payload, $extra);
            }

            // ENTERPRISE: Broadcast via WebSocketPublisher
            // Channel format: "post:{postId}" → WebSocket server routes to subscribers
            $result = WebSocketPublisher::publish(
                "post:{$postId}",
                'post_counter_update',
                $payload
            );

            // ENTERPRISE: Set throttle timestamp
            $this->redis->setex($throttleKey, self::THROTTLE_SECONDS + 1, (string)time());

            // ENTERPRISE: Clear pending updates (they've been broadcast)
            $this->clearPendingUpdates($postId);

            return true;

        } catch (\Exception $e) {
            Logger::error('CounterBroadcastService: Broadcast failed', [
                'post_id' => $postId,
                'counter' => $counterType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Queue pending update for batching
     *
     * Uses Redis HINCRBY for atomic increment (no race conditions)
     *
     * @param int $postId Post ID
     * @param string $type Counter type
     * @param int $delta Delta to queue
     */
    private function queuePendingUpdate(int $postId, string $type, int $delta): void
    {
        $key = self::PENDING_PREFIX . $postId;
        $this->redis->hincrby($key, $type, $delta);
        $this->redis->expire($key, self::PENDING_TTL);
    }

    /**
     * Get pending updates for batching
     *
     * @param int $postId Post ID
     * @return array Pending deltas [counter_type => delta]
     */
    private function getPendingUpdates(int $postId): array
    {
        $key = self::PENDING_PREFIX . $postId;
        $result = $this->redis->hgetall($key);

        // Cast string values to int
        if (is_array($result)) {
            return array_map('intval', $result);
        }

        return [];
    }

    /**
     * Clear pending updates after broadcast
     *
     * @param int $postId Post ID
     */
    private function clearPendingUpdates(int $postId): void
    {
        $this->redis->del(self::PENDING_PREFIX . $postId);
    }

    /**
     * Force broadcast pending updates (for testing/admin)
     *
     * @param int $postId Post ID
     * @return bool Success
     */
    public function flushPendingUpdates(int $postId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $pending = $this->getPendingUpdates($postId);

        if (empty($pending)) {
            return true; // Nothing to flush
        }

        // Clear throttle to allow immediate broadcast
        $this->redis->del(self::THROTTLE_PREFIX . $postId);

        // Broadcast first counter type to trigger flush
        $firstType = array_key_first($pending);
        return $this->broadcastCounterUpdate($postId, $firstType, 0);
    }
}
