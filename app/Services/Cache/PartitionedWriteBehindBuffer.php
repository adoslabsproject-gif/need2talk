<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * PartitionedWriteBehindBuffer - Enterprise Galaxy V8.0
 *
 * DISTRIBUTED SCALABILITY UPGRADE (2025-12-01)
 *
 * Partitions write-behind events by entity ID for parallel processing.
 * Enables N distributed workers to process different partitions concurrently.
 *
 * ARCHITECTURE:
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ Event → entity_id % 16 → Partition 0-15 → Worker N acquires lock        │
 * ├──────────────────────────────────────────────────────────────────────────┤
 * │ OLD: overlay:dirty:reactions                                            │
 * │ NEW: overlay:dirty:reactions:p0, p1, p2, ... p15                        │
 * ├──────────────────────────────────────────────────────────────────────────┤
 * │ Throughput: 1K items/sec → 16K items/sec (16x improvement)              │
 * │ Workers: 1 → N (horizontally scalable)                                  │
 * │ Lock TTL: 10s with heartbeat refresh (prevents deadlock on crash)       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * BACKWARD COMPATIBILITY:
 * - Old methods still work (delegate to partition 0 for legacy callers)
 * - New partitioned methods for distributed workers
 * - Metrics logging for observability
 *
 * @package Need2Talk\Services\Cache
 */
class PartitionedWriteBehindBuffer
{
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Number of partitions (must be power of 2 for efficient modulo)
     * 16 partitions = good balance between parallelism and overhead
     */
    public const PARTITION_COUNT = 16;

    /**
     * Flush threshold per partition (triggers flush when exceeded)
     */
    private const FLUSH_THRESHOLD = 100;

    /**
     * Max buffer age before forced flush (seconds)
     */
    private const MAX_BUFFER_AGE = 300;

    /**
     * Types of overlay events that are partitioned
     */
    public const PARTITIONED_TYPES = [
        'reactions',
        'plays',
        'comment_likes',
        'comments',
    ];

    /**
     * ENTERPRISE V8.1 (2025-12-05): Dirty Partition Set Pattern
     *
     * Instead of polling all 64 partition keys every cycle (causing ~1M cache misses/day),
     * we track which partitions have pending data in a SET.
     *
     * Before: 4 types × 16 partitions × ZCARD = 64 Redis calls/cycle (mostly misses)
     * After:  4 types × SMEMBERS = 4 Redis calls/cycle (always hits)
     *
     * Key: overlay:dirty_partitions:{type}
     * Value: SET of partition numbers (0-15) that have pending events
     */
    private const DIRTY_SET_PREFIX = 'overlay:dirty_partitions:';

    // =========================================================================
    // SINGLETON
    // =========================================================================

    private static ?self $instance = null;
    private ?\Redis $redis = null;

    // Delegate to existing overlay services for immediate visibility
    private ?OverlayService $overlay = null;
    private ?CommentOverlayService $commentOverlay = null;

    private function __construct()
    {
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection('overlay');
        $this->overlay = OverlayService::getInstance();
        $this->commentOverlay = CommentOverlayService::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // PARTITION CALCULATION
    // =========================================================================

    /**
     * Calculate partition number for an entity
     *
     * Uses modulo to distribute evenly across partitions.
     * entity_id % 16 ensures consistent partition assignment.
     *
     * @param int $entityId Post ID, Comment ID, or Audio File ID
     * @return int Partition number (0-15)
     */
    public function getPartition(int $entityId): int
    {
        return abs($entityId) % self::PARTITION_COUNT;
    }

    /**
     * Get Redis key for a partitioned dirty set
     *
     * @param string $type Event type (reactions, plays, etc.)
     * @param int $partition Partition number (0-15)
     * @return string Redis key
     */
    public function getPartitionKey(string $type, int $partition): string
    {
        return "overlay:dirty:{$type}:p{$partition}";
    }

    // =========================================================================
    // ENTERPRISE V8.1: DIRTY PARTITION SET PATTERN
    // =========================================================================

    /**
     * Mark a partition as dirty (has pending events)
     *
     * Called when buffering any event. O(1) operation.
     *
     * @param string $type Event type
     * @param int $partition Partition number
     */
    private function markPartitionDirty(string $type, int $partition): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $dirtySetKey = self::DIRTY_SET_PREFIX . $type;
            $this->redis->sAdd($dirtySetKey, (string) $partition);
        } catch (\Exception $e) {
            // Non-critical, worker will fall back to full scan
        }
    }

    /**
     * Get list of dirty partitions for a type
     *
     * Returns only partitions that have pending data to flush.
     * O(N) where N = number of dirty partitions (0-16), not O(16) always.
     *
     * @param string $type Event type
     * @return array List of partition numbers that are dirty
     */
    public function getDirtyPartitions(string $type): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            $dirtySetKey = self::DIRTY_SET_PREFIX . $type;
            $members = $this->redis->sMembers($dirtySetKey);

            if (!$members) {
                return [];
            }

            // Convert to integers
            return array_map('intval', $members);

        } catch (\Exception $e) {
            Logger::overlay('warning', 'getDirtyPartitions failed, returning all partitions', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            // Fallback: return all partitions (safe but slow)
            return range(0, self::PARTITION_COUNT - 1);
        }
    }

    /**
     * Clear dirty flag for a partition after flush
     *
     * Only clears if partition is actually empty (prevents race condition).
     *
     * @param string $type Event type
     * @param int $partition Partition number
     */
    public function clearPartitionDirty(string $type, int $partition): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            // Only clear if partition is actually empty (race condition safety)
            $partitionKey = $this->getPartitionKey($type, $partition);
            $count = $this->redis->zCard($partitionKey);

            if ($count === 0) {
                $dirtySetKey = self::DIRTY_SET_PREFIX . $type;
                $this->redis->sRem($dirtySetKey, (string) $partition);
            }
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    // =========================================================================
    // PARTITIONED BUFFER OPERATIONS
    // =========================================================================

    /**
     * Buffer a reaction change (PARTITIONED)
     *
     * 1. Updates Redis overlay for immediate visibility
     * 2. Adds to partition-specific dirty set
     * 3. Logs metrics for observability
     *
     * @param int $postId
     * @param int $userId
     * @param int $emotionId New emotion ID
     * @param int|null $prevEmotionId Previous emotion ID (for change/remove)
     */
    public function bufferReaction(int $postId, int $userId, int $emotionId, ?int $prevEmotionId = null): void
    {
        if (!$this->redis) {
            return;
        }

        $startTime = microtime(true);
        $partition = $this->getPartition($postId);

        try {
            // 1. Update Redis overlay (immediate visibility) - delegate to existing service
            if ($this->overlay) {
                $this->overlay->incrementReaction($postId, $emotionId);
                if ($prevEmotionId && $prevEmotionId !== $emotionId) {
                    $this->overlay->decrementReaction($postId, $prevEmotionId);
                }
                $this->overlay->setUserReaction($userId, $postId, $emotionId);
            }

            // 2. Add to PARTITIONED dirty set
            $timestamp = microtime(true);
            $member = json_encode([
                'post_id' => $postId,
                'user_id' => $userId,
                'emotion_id' => $emotionId,
                'prev_emotion_id' => $prevEmotionId,
                'action' => 'upsert',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $key = $this->getPartitionKey('reactions', $partition);
            $this->redis->zAdd($key, $timestamp, $member);

            // 3. ENTERPRISE V8.1: Mark partition as dirty for efficient worker polling
            $this->markPartitionDirty('reactions', $partition);

            // 4. Check if flush needed for this partition
            $this->checkPartitionFlushTrigger('reactions', $partition);

            // 4. Log metrics for observability
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logMetrics('buffer_reaction', $partition, $durationMs, [
                'post_id' => $postId,
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'PartitionedWriteBehindBuffer::bufferReaction failed', [
                'post_id' => $postId,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buffer a reaction removal (PARTITIONED)
     */
    public function bufferReactionRemoval(int $postId, int $userId, int $emotionId): void
    {
        if (!$this->redis) {
            return;
        }

        $partition = $this->getPartition($postId);

        try {
            // Update overlay
            if ($this->overlay) {
                $this->overlay->decrementReaction($postId, $emotionId);
                $this->overlay->removeUserReaction($userId, $postId);
            }

            // Add to partitioned dirty set
            $timestamp = microtime(true);
            $member = json_encode([
                'post_id' => $postId,
                'user_id' => $userId,
                'emotion_id' => $emotionId,
                'action' => 'delete',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $key = $this->getPartitionKey('reactions', $partition);
            $this->redis->zAdd($key, $timestamp, $member);

            // ENTERPRISE V8.1: Mark partition dirty
            $this->markPartitionDirty('reactions', $partition);
            $this->checkPartitionFlushTrigger('reactions', $partition);

        } catch (\Exception $e) {
            Logger::overlay('error', 'PartitionedWriteBehindBuffer::bufferReactionRemoval failed', [
                'post_id' => $postId,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buffer a play event (PARTITIONED)
     *
     * @param int $audioFileId
     * @param int $userId
     */
    public function bufferPlay(int $audioFileId, int $userId): void
    {
        if (!$this->redis) {
            return;
        }

        $partition = $this->getPartition($audioFileId);

        try {
            // Update overlay for immediate visibility
            if ($this->overlay) {
                $this->overlay->incrementPlays($audioFileId);
            }

            // Add to partitioned dirty set
            $timestamp = microtime(true);
            $member = json_encode([
                'audio_file_id' => $audioFileId,
                'user_id' => $userId,
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $key = $this->getPartitionKey('plays', $partition);
            $this->redis->zAdd($key, $timestamp, $member);

            // ENTERPRISE V8.1: Mark partition dirty
            $this->markPartitionDirty('plays', $partition);
            $this->checkPartitionFlushTrigger('plays', $partition);

        } catch (\Exception $e) {
            // Plays are non-critical, silent fail
        }
    }

    /**
     * Buffer a comment like (PARTITIONED)
     */
    public function bufferCommentLike(int $commentId, int $userId): void
    {
        if (!$this->redis) {
            return;
        }

        $partition = $this->getPartition($commentId);

        try {
            if ($this->commentOverlay) {
                $this->commentOverlay->incrementLikes($commentId);
                $this->commentOverlay->setUserLike($userId, $commentId);
            }

            $timestamp = microtime(true);
            $member = json_encode([
                'comment_id' => $commentId,
                'user_id' => $userId,
                'action' => 'like',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $key = $this->getPartitionKey('comment_likes', $partition);
            $this->redis->zAdd($key, $timestamp, $member);

            // ENTERPRISE V8.1: Mark partition dirty
            $this->markPartitionDirty('comment_likes', $partition);
            $this->checkPartitionFlushTrigger('comment_likes', $partition);

        } catch (\Exception $e) {
            Logger::overlay('error', 'PartitionedWriteBehindBuffer::bufferCommentLike failed', [
                'comment_id' => $commentId,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buffer a comment unlike (PARTITIONED)
     */
    public function bufferCommentUnlike(int $commentId, int $userId): void
    {
        if (!$this->redis) {
            return;
        }

        $partition = $this->getPartition($commentId);

        try {
            if ($this->commentOverlay) {
                $this->commentOverlay->decrementLikes($commentId);
                $this->commentOverlay->removeUserLike($userId, $commentId);
            }

            $timestamp = microtime(true);
            $member = json_encode([
                'comment_id' => $commentId,
                'user_id' => $userId,
                'action' => 'unlike',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $key = $this->getPartitionKey('comment_likes', $partition);
            $this->redis->zAdd($key, $timestamp, $member);

            // ENTERPRISE V8.1: Mark partition dirty
            $this->markPartitionDirty('comment_likes', $partition);
            $this->checkPartitionFlushTrigger('comment_likes', $partition);

        } catch (\Exception $e) {
            Logger::overlay('error', 'PartitionedWriteBehindBuffer::bufferCommentUnlike failed', [
                'comment_id' => $commentId,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // PARTITION RETRIEVAL (FOR WORKERS)
    // =========================================================================

    /**
     * Get pending events for a specific partition
     *
     * Used by distributed workers to fetch work for their acquired partition.
     *
     * @param string $type Event type
     * @param int $partition Partition number (0-15)
     * @param int $limit Max items to retrieve
     * @return array Array of pending events
     */
    public function getPartitionEvents(string $type, int $partition, int $limit = 100): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            $key = $this->getPartitionKey($type, $partition);
            $items = $this->redis->zRange($key, 0, $limit - 1);
            $result = [];

            foreach ($items as $json) {
                $data = json_decode($json, true);
                if ($data) {
                    $data['_raw'] = $json;
                    $data['_partition'] = $partition;
                    $result[] = $data;
                }
            }

            return $result;

        } catch (\Exception $e) {
            Logger::overlay('error', 'PartitionedWriteBehindBuffer::getPartitionEvents failed', [
                'type' => $type,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get partition event count
     *
     * @param string $type Event type
     * @param int $partition Partition number
     * @return int Number of pending events
     */
    public function getPartitionCount(string $type, int $partition): int
    {
        if (!$this->redis) {
            return 0;
        }

        try {
            $key = $this->getPartitionKey($type, $partition);
            return $this->redis->zCard($key);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Remove flushed events from partition
     *
     * ENTERPRISE V8.1: Also clears dirty flag if partition becomes empty
     *
     * @param array $items Items with '_raw' key
     * @param string $type Event type
     * @param int $partition Partition number
     * @return int Number of items removed
     */
    public function removePartitionEvents(array $items, string $type, int $partition): int
    {
        if (!$this->redis || empty($items)) {
            return 0;
        }

        try {
            $key = $this->getPartitionKey($type, $partition);
            $removed = 0;

            foreach ($items as $item) {
                if (isset($item['_raw'])) {
                    $removed += $this->redis->zRem($key, $item['_raw']);
                }
            }

            // ENTERPRISE V8.1: Clear dirty flag if partition is now empty
            // This prevents future polling of this partition until new data arrives
            $this->clearPartitionDirty($type, $partition);

            return $removed;

        } catch (\Exception $e) {
            Logger::overlay('error', 'PartitionedWriteBehindBuffer::removePartitionEvents failed', [
                'type' => $type,
                'partition' => $partition,
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    // =========================================================================
    // PARTITION STATUS & MONITORING
    // =========================================================================

    /**
     * Get status of all partitions for a type
     *
     * Returns count per partition for monitoring dashboard.
     *
     * @param string $type Event type
     * @return array Partition => count mapping
     */
    public function getPartitionStatus(string $type): array
    {
        if (!$this->redis) {
            return [];
        }

        $status = [];
        $totalPending = 0;

        for ($p = 0; $p < self::PARTITION_COUNT; $p++) {
            try {
                $key = $this->getPartitionKey($type, $p);
                $count = $this->redis->zCard($key);
                $status[$p] = $count;
                $totalPending += $count;
            } catch (\Exception $e) {
                $status[$p] = -1; // Error indicator
            }
        }

        $status['total'] = $totalPending;
        return $status;
    }

    /**
     * Get comprehensive buffer status (all types, all partitions)
     *
     * @return array Complete status for monitoring
     */
    public function getBufferStatus(): array
    {
        $status = [
            'available' => $this->redis !== null,
            'partition_count' => self::PARTITION_COUNT,
            'types' => [],
            'totals' => [],
        ];

        if (!$this->redis) {
            return $status;
        }

        foreach (self::PARTITIONED_TYPES as $type) {
            $typeStatus = $this->getPartitionStatus($type);
            $status['types'][$type] = $typeStatus;
            $status['totals'][$type] = $typeStatus['total'] ?? 0;
        }

        $status['grand_total'] = array_sum($status['totals']);

        return $status;
    }

    /**
     * Get partitions that need flushing (count > threshold or age > max)
     *
     * ENTERPRISE V8.1 (2025-12-05): Uses Dirty Partition Set Pattern
     *
     * OLD: 16 ZCARD calls per type = 64 calls/cycle (mostly cache misses on empty partitions)
     * NEW: 1 SMEMBERS call per type = 4 calls/cycle + ZCARD only on dirty partitions
     *
     * Performance improvement: ~16x fewer Redis calls when partitions are mostly empty
     * Cache miss reduction: ~900K fewer misses/day in idle state
     *
     * @param string $type Event type
     * @return array List of partition numbers that need flush
     */
    public function getPartitionsNeedingFlush(string $type): array
    {
        if (!$this->redis) {
            return [];
        }

        // ENTERPRISE V8.1: First check which partitions are marked dirty
        // This is O(1) SMEMBERS call instead of O(16) ZCARD calls
        $dirtyPartitions = $this->getDirtyPartitions($type);

        // No dirty partitions = no work to do (fast path, no cache misses!)
        if (empty($dirtyPartitions)) {
            return [];
        }

        $needsFlush = [];

        // Only check partitions that are actually dirty
        foreach ($dirtyPartitions as $p) {
            try {
                $key = $this->getPartitionKey($type, $p);

                // Check count threshold
                $count = $this->redis->zCard($key);

                // Partition was marked dirty but is now empty - clean up the dirty flag
                if ($count === 0) {
                    $this->clearPartitionDirty($type, $p);
                    continue;
                }

                if ($count >= self::FLUSH_THRESHOLD) {
                    $needsFlush[] = $p;
                    continue;
                }

                // Check age threshold (oldest item)
                $oldest = $this->redis->zRange($key, 0, 0, true);
                if (!empty($oldest)) {
                    $oldestTs = reset($oldest);
                    if ((microtime(true) - $oldestTs) > self::MAX_BUFFER_AGE) {
                        $needsFlush[] = $p;
                    }
                }

            } catch (\Exception $e) {
                // Include partition on error to ensure it gets checked
                $needsFlush[] = $p;
            }
        }

        return $needsFlush;
    }

    // =========================================================================
    // FLUSH TRIGGER & METRICS
    // =========================================================================

    /**
     * Check if partition needs flush and set trigger
     */
    private function checkPartitionFlushTrigger(string $type, int $partition): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $key = $this->getPartitionKey($type, $partition);
            $bufferSize = $this->redis->zCard($key);

            if ($bufferSize >= self::FLUSH_THRESHOLD) {
                // Set partition-specific trigger
                $triggerKey = "overlay:flush:trigger:{$type}:p{$partition}";
                $this->redis->setex($triggerKey, 10, time());

                Logger::overlay('info', 'PartitionedWriteBehindBuffer: Flush threshold reached', [
                    'type' => $type,
                    'partition' => $partition,
                    'buffer_size' => $bufferSize,
                ]);
            }
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Log metrics for observability (Grafana-ready)
     *
     * @param string $operation Operation name
     * @param int $partition Partition number
     * @param float $durationMs Duration in milliseconds
     * @param array $context Additional context
     */
    private function logMetrics(string $operation, int $partition, float $durationMs, array $context = []): void
    {
        // Only log if duration is notable (>1ms) or explicitly requested
        if ($durationMs < 1 && empty($context['force_log'])) {
            return;
        }

        $metrics = [
            'operation' => $operation,
            'partition' => $partition,
            'duration_ms' => round($durationMs, 2),
        ];

        if (!empty($context)) {
            $metrics = array_merge($metrics, $context);
        }

        Logger::overlay('debug', 'PartitionedBuffer metrics', $metrics);
    }

    // =========================================================================
    // HEALTH CHECK & ORPHAN DETECTION (V8.2)
    // =========================================================================

    /**
     * ENTERPRISE V8.2: Health Check for Orphaned Non-Partitioned Keys
     *
     * Detects if there are items in legacy non-partitioned keys that will NEVER be flushed.
     * This catches the bug where WriteBehindBuffer was used instead of PartitionedWriteBehindBuffer.
     *
     * @return array Health status with orphan counts
     */
    public function checkOrphanedKeys(): array
    {
        if (!$this->redis) {
            return ['error' => 'Redis not available'];
        }

        $orphans = [];
        $totalOrphans = 0;

        foreach (self::PARTITIONED_TYPES as $type) {
            try {
                $legacyKey = "overlay:dirty:{$type}";
                $count = $this->redis->zCard($legacyKey);

                if ($count > 0) {
                    $orphans[$type] = $count;
                    $totalOrphans += $count;

                    // LOG WARNING sul canale overlay
                    Logger::overlay('warning', 'ORPHANED_BUFFER_DETECTED', [
                        'type' => $type,
                        'legacy_key' => $legacyKey,
                        'orphan_count' => $count,
                        'message' => 'Items in non-partitioned key will NEVER be flushed! WriteBehindBuffer used instead of PartitionedWriteBehindBuffer.',
                    ]);
                }
            } catch (\Exception $e) {
                $orphans[$type] = -1;
            }
        }

        return [
            'healthy' => $totalOrphans === 0,
            'total_orphans' => $totalOrphans,
            'orphans_by_type' => $orphans,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * ENTERPRISE V8.2: Migrate Orphaned Items to Partitioned Keys
     *
     * Emergency method to rescue orphaned items from legacy keys.
     *
     * @param string $type Event type to migrate
     * @param int $batchSize Items to migrate per call
     * @return array Migration results
     */
    public function migrateOrphanedItems(string $type, int $batchSize = 100): array
    {
        if (!$this->redis) {
            return ['error' => 'Redis not available'];
        }

        $legacyKey = "overlay:dirty:{$type}";
        $migrated = 0;
        $failed = 0;

        try {
            $items = $this->redis->zRange($legacyKey, 0, $batchSize - 1, true);

            foreach ($items as $member => $score) {
                $data = json_decode($member, true);
                if (!$data) {
                    $failed++;
                    continue;
                }

                $entityId = $data['post_id'] ?? $data['comment_id'] ?? $data['audio_file_id'] ?? 0;
                if ($entityId === 0) {
                    $failed++;
                    continue;
                }

                $partition = $this->getPartition($entityId);
                $partitionKey = $this->getPartitionKey($type, $partition);

                $this->redis->zAdd($partitionKey, $score, $member);
                $this->markPartitionDirty($type, $partition);
                $this->redis->zRem($legacyKey, $member);
                $migrated++;
            }

            Logger::overlay('info', 'ORPHAN_MIGRATION_COMPLETED', [
                'type' => $type,
                'migrated' => $migrated,
                'failed' => $failed,
            ]);

            return [
                'success' => true,
                'migrated' => $migrated,
                'failed' => $failed,
                'remaining' => $this->redis->zCard($legacyKey),
            ];

        } catch (\Exception $e) {
            Logger::overlay('error', 'ORPHAN_MIGRATION_FAILED', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'migrated' => $migrated,
                'failed' => $failed,
            ];
        }
    }

    // =========================================================================
    // BACKWARD COMPATIBILITY (Legacy callers)
    // =========================================================================

    /**
     * Get pending reactions (backward compatible - returns from ALL partitions)
     *
     * @deprecated Use getPartitionEvents() for distributed workers
     */
    public function getPendingReactions(int $limit = 100): array
    {
        $allItems = [];
        $perPartitionLimit = (int) ceil($limit / self::PARTITION_COUNT);

        for ($p = 0; $p < self::PARTITION_COUNT; $p++) {
            $items = $this->getPartitionEvents('reactions', $p, $perPartitionLimit);
            $allItems = array_merge($allItems, $items);

            if (count($allItems) >= $limit) {
                break;
            }
        }

        return array_slice($allItems, 0, $limit);
    }

    /**
     * Remove flushed items (backward compatible)
     *
     * @deprecated Use removePartitionEvents() for distributed workers
     */
    public function removeFlushedItems(array $items, string $type): void
    {
        // Group by partition
        $byPartition = [];
        foreach ($items as $item) {
            $partition = $item['_partition'] ?? 0;
            $byPartition[$partition][] = $item;
        }

        // Remove from each partition
        foreach ($byPartition as $partition => $partitionItems) {
            $this->removePartitionEvents($partitionItems, $type, $partition);
        }
    }
}
