#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Distributed Overlay Flush Worker - Enterprise Galaxy V8.0
 *
 * SCALABILITY UPGRADE (2025-12-01)
 *
 * Distributed worker for partition-based overlay flush.
 * Multiple instances can run in parallel, each processing different partitions.
 *
 * ARCHITECTURE:
 * ┌───────────────────────────────────────────────────────────────────────────────┐
 * │ Worker 1 ──┬── acquires lock p0 ── flush p0 ── release p0                    │
 * │            └── acquires lock p4 ── flush p4 ── release p4                    │
 * │ Worker 2 ──┬── acquires lock p1 ── flush p1 ── release p1                    │
 * │            └── acquires lock p5 ── flush p5 ── release p5                    │
 * │ Worker 3 ──┬── acquires lock p2 ── flush p2 ── release p2                    │
 * │            └── acquires lock p6 ── flush p6 ── release p6                    │
 * │ Worker 4 ──┬── acquires lock p3 ── flush p3 ── release p3                    │
 * │            └── acquires lock p7 ── flush p7 ── release p7                    │
 * └───────────────────────────────────────────────────────────────────────────────┘
 *
 * FEATURES:
 * - Partition-based distribution (16 partitions = 16x throughput potential)
 * - Distributed locking with 10s TTL + 3s heartbeat refresh
 * - Metrics logging for Grafana observability
 * - Graceful shutdown with signal handlers
 * - Adaptive scheduling based on partition load
 * - Work stealing: if assigned partitions are empty, try others
 *
 * DEPLOYMENT:
 * - Docker: `docker-compose up -d --scale overlay_worker=4`
 * - Manual: `php scripts/distributed-overlay-flush-worker.php --worker-id=1`
 *
 * @package Need2Talk\Scripts
 */

// ENTERPRISE: Long-running process configuration
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '256M');
ignore_user_abort(true);

date_default_timezone_set('Europe/Rome');

// Ensure CLI only
if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

// Bootstrap
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\Logger;
use Need2Talk\Services\Cache\PartitionedWriteBehindBuffer;
use Need2Talk\Services\Cache\PartitionLockManager;
use Need2Talk\Services\Cache\OverlayFlushService;
use Need2Talk\Repositories\Audio\CommentRepository;
use Need2Talk\Core\EnterpriseRedisManager;

/**
 * Distributed Overlay Flush Worker
 */
class DistributedOverlayFlushWorker
{
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /** @var int Maximum runtime in seconds */
    private int $maxRuntime;

    /** @var int Flush interval in seconds (adaptive) */
    private int $flushInterval = 10;

    /** @var int Heartbeat interval in seconds */
    private int $heartbeatInterval;

    /** @var int Items per flush batch */
    private const BATCH_SIZE = 100;

    /** @var int Memory threshold in MB before graceful exit */
    private const MEMORY_THRESHOLD_MB = 200;

    /** @var int Max consecutive errors before exit */
    private const MAX_ERRORS = 10;

    // =========================================================================
    // STATE
    // =========================================================================

    private bool $running = true;
    private string $workerId;
    private float $startTime;

    // Metrics
    private int $flushCount = 0;
    private int $itemsFlushed = 0;
    private int $partitionsProcessed = 0;
    private int $lockAcquisitions = 0;
    private int $lockContentions = 0;
    private int $errorCount = 0;
    private float $totalFlushDuration = 0;
    private float $totalLockWaitDuration = 0;

    // Currently held locks (for cleanup on shutdown)
    private array $heldLocks = [];

    // Redis connection for heartbeat
    private ?\Redis $redis = null;
    private float $lastHeartbeatTime = 0;
    private const HEARTBEAT_KEY_TTL = 30; // Redis key TTL in seconds
    private const HEARTBEAT_REFRESH_INTERVAL = 10; // Refresh every 10s

    // Services
    private ?PartitionedWriteBehindBuffer $buffer = null;
    private ?PartitionLockManager $lockManager = null;
    private ?OverlayFlushService $flushService = null;
    private ?CommentRepository $commentRepository = null;

    public function __construct(int $maxRuntime = 300, ?string $workerId = null)
    {
        $this->maxRuntime = $maxRuntime;
        $this->workerId = $workerId ?? 'worker_' . uniqid() . '_' . getmypid();
        $this->startTime = microtime(true);
        $this->heartbeatInterval = PartitionLockManager::getHeartbeatInterval();

        $this->setupSignalHandlers();

        enterprise_log("[DISTRIBUTED-FLUSH] 🚀 Worker starting: {$this->workerId}");
    }

    /**
     * Initialize services
     */
    private function initializeServices(): bool
    {
        try {
            $this->buffer = PartitionedWriteBehindBuffer::getInstance();
            $this->lockManager = PartitionLockManager::getInstance();
            $this->flushService = OverlayFlushService::getInstance();
            $this->commentRepository = new CommentRepository();

            // Initialize Redis for heartbeat registration
            $this->redis = EnterpriseRedisManager::getInstance()->getConnection('cache');

            return true;
        } catch (\Exception $e) {
            enterprise_log("[DISTRIBUTED-FLUSH] ❌ Service initialization failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Register worker heartbeat in Redis for monitoring dashboard
     */
    private function registerHeartbeat(): void
    {
        $now = microtime(true);

        // Only refresh if interval has passed
        if (($now - $this->lastHeartbeatTime) < self::HEARTBEAT_REFRESH_INTERVAL) {
            return;
        }

        try {
            if ($this->redis) {
                $heartbeatKey = "overlay_worker:heartbeat:{$this->workerId}";
                $heartbeatData = json_encode([
                    'worker_id' => $this->workerId,
                    'started_at' => date('Y-m-d H:i:s', (int) $this->startTime),
                    'last_heartbeat' => date('Y-m-d H:i:s'),
                    'uptime_seconds' => (int) ($now - $this->startTime),
                    'flushes' => $this->flushCount,
                    'items_flushed' => $this->itemsFlushed,
                    'partitions_processed' => $this->partitionsProcessed,
                    'errors' => $this->errorCount,
                    'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                ]);

                $this->redis->setex($heartbeatKey, self::HEARTBEAT_KEY_TTL, $heartbeatData);
                $this->lastHeartbeatTime = $now;
            }
        } catch (\Exception $e) {
            // Non-critical, don't fail on heartbeat errors
            enterprise_log("[DISTRIBUTED-FLUSH] ⚠️ Heartbeat registration failed: " . $e->getMessage());
        }
    }

    /**
     * Remove heartbeat on shutdown
     */
    private function removeHeartbeat(): void
    {
        try {
            if ($this->redis) {
                $heartbeatKey = "overlay_worker:heartbeat:{$this->workerId}";
                $this->redis->del($heartbeatKey);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Main entry point
     */
    public function run(): int
    {
        // Step 1: Initialize services
        if (!$this->initializeServices()) {
            return 1;
        }

        enterprise_log("[DISTRIBUTED-FLUSH] ⚡ Running with max_runtime={$this->maxRuntime}s, heartbeat={$this->heartbeatInterval}s");

        // Step 2: Enter main loop
        $this->mainLoop();

        // Step 3: Cleanup and log final stats
        $this->cleanup();
        $this->logFinalStats();

        return 0;
    }

    /**
     * Main processing loop
     */
    private function mainLoop(): void
    {
        $lastHeartbeat = microtime(true);

        while ($this->running && $this->shouldContinue()) {
            try {
                $cycleStart = microtime(true);
                $workDone = false;

                // Process each event type
                foreach (PartitionedWriteBehindBuffer::PARTITIONED_TYPES as $type) {
                    if (!$this->running) break;

                    // Try to process partitions for this type
                    $partitionWork = $this->processTypePartitions($type);
                    if ($partitionWork > 0) {
                        $workDone = true;
                    }
                }

                // =====================================================================
                // ENTERPRISE V10.155 (2025-12-11): V6 GENERATIONAL FLUSH
                // =====================================================================
                // Plays and Comments also use V6 generational system (gen:dirty:plays)
                // which is NOT partitioned. Call OverlayFlushService to flush these.
                // This fixes the bug where plays were never being flushed because:
                // - OverlayService::recordPlay() writes to gen:dirty:plays (V6)
                // - PartitionedWriteBehindBuffer looks for overlay:dirty:plays:p{N}
                // =====================================================================
                $v6Work = $this->flushV6GenerationalOverlays();
                if ($v6Work > 0) {
                    $workDone = true;
                    $this->itemsFlushed += $v6Work;
                    $this->flushCount++;
                }

                // Heartbeat: refresh held locks AND register worker heartbeat for monitoring
                if ((microtime(true) - $lastHeartbeat) >= $this->heartbeatInterval) {
                    $this->refreshHeldLocks();
                    $this->registerHeartbeat();
                    $lastHeartbeat = microtime(true);
                }

                // Adaptive sleep based on work done
                if (!$workDone) {
                    // No work available, longer sleep
                    $this->adaptiveSleep(true);
                } else {
                    // Work done, shorter sleep
                    $this->adaptiveSleep(false);
                }

                // Periodic status log
                $runtime = microtime(true) - $this->startTime;
                if ((int)$runtime % 60 === 0 && $runtime > 0) {
                    $this->logPeriodicStatus();
                }

            } catch (\Exception $e) {
                $this->errorCount++;
                enterprise_log("[DISTRIBUTED-FLUSH] ❌ Error: " . $e->getMessage());

                Logger::overlay('error', 'DISTRIBUTED_FLUSH_ERROR', [
                    'worker_id' => $this->workerId,
                    'error' => $e->getMessage(),
                    'error_count' => $this->errorCount,
                ]);

                // Backoff after error
                sleep(min($this->errorCount * 2, 30));
            }
        }
    }

    /**
     * Process partitions for a specific event type
     *
     * @param string $type Event type (reactions, plays, etc.)
     * @return int Number of items flushed
     */
    private function processTypePartitions(string $type): int
    {
        $totalFlushed = 0;

        // Get partitions that need flushing
        $partitionsNeedingFlush = $this->buffer->getPartitionsNeedingFlush($type);

        if (empty($partitionsNeedingFlush)) {
            return 0;
        }

        // Shuffle to avoid all workers targeting same partitions
        shuffle($partitionsNeedingFlush);

        foreach ($partitionsNeedingFlush as $partition) {
            if (!$this->running) break;

            // Try to acquire lock for this partition
            $lockStart = microtime(true);
            $lockAcquired = $this->lockManager->acquireLock($type, $partition, $this->workerId);
            $lockWaitMs = (microtime(true) - $lockStart) * 1000;
            $this->totalLockWaitDuration += $lockWaitMs;

            if (!$lockAcquired) {
                // Another worker has this partition, try next
                $this->lockContentions++;
                continue;
            }

            $this->lockAcquisitions++;
            $this->heldLocks[] = ['type' => $type, 'partition' => $partition];

            try {
                // Flush this partition
                $flushedCount = $this->flushPartition($type, $partition);
                $totalFlushed += $flushedCount;
                $this->partitionsProcessed++;

            } finally {
                // Always release lock
                $this->lockManager->releaseLock($type, $partition, $this->workerId);
                $this->removeHeldLock($type, $partition);
            }
        }

        return $totalFlushed;
    }

    /**
     * Flush a single partition
     *
     * @param string $type Event type
     * @param int $partition Partition number
     * @return int Number of items flushed
     */
    private function flushPartition(string $type, int $partition): int
    {
        $flushStart = microtime(true);
        $itemsFlushed = 0;
        $db = db();

        try {
            // Get pending events for this partition
            $items = $this->buffer->getPartitionEvents($type, $partition, self::BATCH_SIZE);

            if (empty($items)) {
                return 0;
            }

            // Process based on type
            switch ($type) {
                case 'reactions':
                    $itemsFlushed = $this->flushReactionPartition($items, $partition, $db);
                    break;

                case 'plays':
                    $itemsFlushed = $this->flushPlaysPartition($items, $partition, $db);
                    break;

                case 'comment_likes':
                    $itemsFlushed = $this->flushCommentLikesPartition($items, $partition, $db);
                    break;

                case 'comments':
                    $itemsFlushed = $this->flushCommentsPartition($items, $partition, $db);
                    break;
            }

            // Remove flushed items
            $this->buffer->removePartitionEvents($items, $type, $partition);

            // Update metrics
            $this->flushCount++;
            $this->itemsFlushed += $itemsFlushed;
            $durationMs = (microtime(true) - $flushStart) * 1000;
            $this->totalFlushDuration += $durationMs;

            // Log metrics for observability (Grafana-ready)
            $this->logFlushMetrics($type, $partition, $itemsFlushed, $durationMs);

            return $itemsFlushed;

        } catch (\Exception $e) {
            $this->errorCount++;
            Logger::overlay('error', 'Partition flush failed', [
                'type' => $type,
                'partition' => $partition,
                'worker_id' => $this->workerId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Flush reactions partition to DB
     */
    private function flushReactionPartition(array $items, int $partition, $db): int
    {
        $flushed = 0;

        // Deduplicate: keep only final state per user+post
        $deduped = [];
        foreach ($items as $item) {
            $key = ($item['user_id'] ?? 0) . ':' . ($item['post_id'] ?? 0);
            $deduped[$key] = $item;
        }

        foreach ($deduped as $item) {
            try {
                if (($item['action'] ?? '') === 'upsert') {
                    $db->execute(
                        "INSERT INTO audio_reactions (audio_post_id, user_id, emotion_id, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), NOW())
                         ON CONFLICT (user_id, audio_post_id) DO UPDATE SET
                             emotion_id = ?,
                             updated_at = NOW()",
                        [
                            $item['post_id'],
                            $item['user_id'],
                            $item['emotion_id'],
                            $item['emotion_id'],
                        ]
                    );
                    $flushed++;
                } elseif (($item['action'] ?? '') === 'delete') {
                    $db->execute(
                        "DELETE FROM audio_reactions WHERE audio_post_id = ? AND user_id = ?",
                        [$item['post_id'], $item['user_id']]
                    );
                    $flushed++;
                }
            } catch (\Exception $e) {
                Logger::overlay('error', 'Reaction item flush failed', [
                    'partition' => $partition,
                    'post_id' => $item['post_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $flushed;
    }

    /**
     * Flush plays partition to DB
     */
    private function flushPlaysPartition(array $items, int $partition, $db): int
    {
        $flushed = 0;

        // Aggregate plays per audio file
        $playsByFile = [];
        foreach ($items as $item) {
            $audioFileId = $item['audio_file_id'] ?? 0;
            if ($audioFileId > 0) {
                $playsByFile[$audioFileId] = ($playsByFile[$audioFileId] ?? 0) + 1;
            }
        }

        // Batch update DB
        foreach ($playsByFile as $audioFileId => $count) {
            try {
                $db->execute(
                    "UPDATE audio_files SET
                        play_count = COALESCE(play_count, 0) + ?,
                        updated_at = NOW()
                     WHERE id = ?",
                    [$count, $audioFileId],
                    ['invalidate_cache' => ['audio_files', "audio_file:{$audioFileId}"]]
                );
                $flushed += $count;
            } catch (\Exception $e) {
                Logger::overlay('error', 'Play flush failed', [
                    'partition' => $partition,
                    'audio_file_id' => $audioFileId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $flushed;
    }

    /**
     * Flush comment likes partition to DB
     */
    private function flushCommentLikesPartition(array $items, int $partition, $db): int
    {
        $flushed = 0;

        // Deduplicate
        $deduped = [];
        foreach ($items as $item) {
            $key = ($item['user_id'] ?? 0) . ':' . ($item['comment_id'] ?? 0);
            $deduped[$key] = $item;
        }

        $likeDeltaPerComment = [];

        foreach ($deduped as $item) {
            try {
                $commentId = $item['comment_id'] ?? 0;

                if (($item['action'] ?? '') === 'like') {
                    $this->commentRepository->addLike($commentId, $item['user_id']);
                    $likeDeltaPerComment[$commentId] = ($likeDeltaPerComment[$commentId] ?? 0) + 1;
                    $flushed++;
                } elseif (($item['action'] ?? '') === 'unlike') {
                    $this->commentRepository->removeLike($commentId, $item['user_id']);
                    $likeDeltaPerComment[$commentId] = ($likeDeltaPerComment[$commentId] ?? 0) - 1;
                    $flushed++;
                }
            } catch (\Exception $e) {
                Logger::overlay('error', 'Comment like flush failed', [
                    'partition' => $partition,
                    'comment_id' => $item['comment_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update denormalized like_count
        foreach ($likeDeltaPerComment as $commentId => $delta) {
            if ($delta !== 0) {
                try {
                    $this->commentRepository->updateLikeCount($commentId, $delta);
                } catch (\Exception $e) {
                    // Non-critical
                }
            }
        }

        return $flushed;
    }

    /**
     * Flush comments partition to DB
     */
    private function flushCommentsPartition(array $items, int $partition, $db): int
    {
        $flushed = 0;

        // Track deltas for denormalized counters
        $postCommentDelta = [];
        $parentReplyDelta = [];

        foreach ($items as $item) {
            $postId = $item['post_id'] ?? 0;
            $parentId = $item['parent_comment_id'] ?? null;
            $action = $item['action'] ?? '';

            if ($action === 'create') {
                if ($parentId) {
                    $parentReplyDelta[$parentId] = ($parentReplyDelta[$parentId] ?? 0) + 1;
                } else {
                    $postCommentDelta[$postId] = ($postCommentDelta[$postId] ?? 0) + 1;
                }
                $flushed++;
            } elseif ($action === 'delete') {
                if ($parentId) {
                    $parentReplyDelta[$parentId] = ($parentReplyDelta[$parentId] ?? 0) - 1;
                } else {
                    $postCommentDelta[$postId] = ($postCommentDelta[$postId] ?? 0) - 1;
                }
                $flushed++;
            }
        }

        // Update audio_posts.comment_count
        foreach ($postCommentDelta as $postId => $delta) {
            if ($delta !== 0) {
                try {
                    $db->execute(
                        "UPDATE audio_posts SET
                            comment_count = GREATEST(0, COALESCE(comment_count, 0) + ?),
                            updated_at = NOW()
                         WHERE id = ?",
                        [$delta, $postId]
                    );
                } catch (\Exception $e) {
                    Logger::overlay('error', 'Post comment_count update failed', [
                        'partition' => $partition,
                        'post_id' => $postId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Update parent reply_count
        foreach ($parentReplyDelta as $parentId => $delta) {
            if ($delta !== 0) {
                try {
                    $this->commentRepository->updateReplyCount($parentId, $delta);
                } catch (\Exception $e) {
                    // Non-critical
                }
            }
        }

        return $flushed;
    }

    /**
     * Flush V6 Generational Overlays (plays, comments)
     *
     * ENTERPRISE V10.155 (2025-12-11)
     *
     * Plays and Comments use V6 generational overlay system:
     * - OverlayService::recordPlay() writes to gen:dirty:plays
     * - CommentOverlayService::recordCommentEvent() writes to gen:dirty:comments
     *
     * These are NOT partitioned, so we call OverlayFlushService directly.
     *
     * @return int Number of items flushed
     */
    private function flushV6GenerationalOverlays(): int
    {
        $totalFlushed = 0;

        try {
            // Use reflection to call private methods, or use flush() and extract results
            // Simpler: call flush() which handles both V6 plays and comments
            // But flush() also does other things... let's be targeted.

            // Actually, OverlayFlushService has public method flush() that returns results
            // We'll call it and count only the V6 items
            $results = $this->flushService->flush();

            // Count V6 items flushed
            $playsFlushed = (int) ($results['plays_flushed'] ?? 0);
            $commentsV6Flushed = (int) ($results['comments_v6_flushed'] ?? 0);
            $totalFlushed = $playsFlushed + $commentsV6Flushed;

            if ($totalFlushed > 0) {
                Logger::overlay('info', 'V6_GENERATIONAL_FLUSH', [
                    'worker_id' => $this->workerId,
                    'plays_flushed' => $playsFlushed,
                    'comments_v6_flushed' => $commentsV6Flushed,
                    'total' => $totalFlushed,
                ]);

                enterprise_log("[DISTRIBUTED-FLUSH] ✅ V6 Generational: {$playsFlushed} plays, {$commentsV6Flushed} comments");
            }

        } catch (\Exception $e) {
            Logger::overlay('error', 'V6_GENERATIONAL_FLUSH_ERROR', [
                'worker_id' => $this->workerId,
                'error' => $e->getMessage(),
            ]);
        }

        return $totalFlushed;
    }

    /**
     * Refresh held locks (heartbeat)
     */
    private function refreshHeldLocks(): void
    {
        foreach ($this->heldLocks as $lock) {
            $this->lockManager->extendLock($lock['type'], $lock['partition'], $this->workerId);
        }
    }

    /**
     * Remove a lock from held locks array
     */
    private function removeHeldLock(string $type, int $partition): void
    {
        $this->heldLocks = array_filter($this->heldLocks, function ($lock) use ($type, $partition) {
            return !($lock['type'] === $type && $lock['partition'] === $partition);
        });
    }

    /**
     * Log flush metrics (Grafana-ready)
     */
    private function logFlushMetrics(string $type, int $partition, int $itemsFlushed, float $durationMs): void
    {
        $metrics = [
            'worker_id' => $this->workerId,
            'type' => $type,
            'partition' => $partition,
            'items_flushed' => $itemsFlushed,
            'duration_ms' => round($durationMs, 2),
            'lock_wait_ms' => round($this->totalLockWaitDuration / max(1, $this->lockAcquisitions), 2),
        ];

        Logger::overlay('info', 'PARTITION_FLUSH', $metrics);

        if ($itemsFlushed > 0) {
            enterprise_log("[DISTRIBUTED-FLUSH] ✅ {$type}:p{$partition} - {$itemsFlushed} items ({$durationMs}ms)");
        }
    }

    /**
     * Log periodic status
     */
    private function logPeriodicStatus(): void
    {
        $runtime = round(microtime(true) - $this->startTime, 0);
        $avgFlushMs = $this->flushCount > 0 ? round($this->totalFlushDuration / $this->flushCount, 2) : 0;

        enterprise_log("[DISTRIBUTED-FLUSH] 📊 Status: {$runtime}s runtime, " .
            "{$this->flushCount} flushes, {$this->itemsFlushed} items, " .
            "avg {$avgFlushMs}ms/flush, {$this->lockContentions} contentions");

        // V8.2: Check for orphaned items in legacy non-partitioned keys
        // This catches the bug where WriteBehindBuffer was used instead of PartitionedWriteBehindBuffer
        if ($this->buffer) {
            $orphanCheck = $this->buffer->checkOrphanedKeys();
            if (!$orphanCheck['healthy']) {
                enterprise_log("[DISTRIBUTED-FLUSH] ⚠️ ORPHANS DETECTED: {$orphanCheck['total_orphans']} items in legacy keys!");
                // Warning already logged by checkOrphanedKeys() via Logger::overlay()
            }
        }
    }

    /**
     * Adaptive sleep based on work availability
     */
    private function adaptiveSleep(bool $noWork): void
    {
        if ($noWork) {
            // No work available, sleep longer (but check for work periodically)
            sleep(min($this->flushInterval, 5));
        } else {
            // Work done, very short sleep to check for more
            usleep(100000); // 100ms
        }
    }

    /**
     * Check if worker should continue
     */
    private function shouldContinue(): bool
    {
        // Check runtime
        $runtime = microtime(true) - $this->startTime;
        if ($runtime >= $this->maxRuntime) {
            enterprise_log("[DISTRIBUTED-FLUSH] ⏰ Max runtime reached ({$this->maxRuntime}s)");
            return false;
        }

        // Check errors
        if ($this->errorCount >= self::MAX_ERRORS) {
            enterprise_log("[DISTRIBUTED-FLUSH] ❌ Max errors reached ({$this->errorCount})");
            return false;
        }

        // Check memory
        $memoryMB = memory_get_usage(true) / 1024 / 1024;
        if ($memoryMB > self::MEMORY_THRESHOLD_MB) {
            enterprise_log("[DISTRIBUTED-FLUSH] 💾 Memory threshold exceeded ({$memoryMB}MB)");
            return false;
        }

        return true;
    }

    /**
     * Cleanup: release all held locks and remove heartbeat
     */
    private function cleanup(): void
    {
        // Release all partition locks
        foreach ($this->heldLocks as $lock) {
            try {
                $this->lockManager->releaseLock($lock['type'], $lock['partition'], $this->workerId);
            } catch (\Exception $e) {
                // Best effort
            }
        }
        $this->heldLocks = [];

        // Remove worker heartbeat from Redis (so monitoring shows worker as offline)
        $this->removeHeartbeat();

        enterprise_log("[DISTRIBUTED-FLUSH] 🧹 Cleanup complete: locks released, heartbeat removed");
    }

    /**
     * Log final statistics
     */
    private function logFinalStats(): void
    {
        $runtime = round(microtime(true) - $this->startTime, 2);
        $avgFlushMs = $this->flushCount > 0 ? round($this->totalFlushDuration / $this->flushCount, 2) : 0;
        $avgLockWaitMs = $this->lockAcquisitions > 0 ? round($this->totalLockWaitDuration / $this->lockAcquisitions, 2) : 0;
        $contentionRate = ($this->lockAcquisitions + $this->lockContentions) > 0
            ? round($this->lockContentions / ($this->lockAcquisitions + $this->lockContentions) * 100, 1)
            : 0;

        enterprise_log("[DISTRIBUTED-FLUSH] 📊 Final stats: " .
            "{$this->flushCount} flushes, {$this->itemsFlushed} items, " .
            "{$this->partitionsProcessed} partitions, {$this->errorCount} errors, " .
            "{$runtime}s runtime");

        enterprise_log("[DISTRIBUTED-FLUSH] 🔒 Lock stats: " .
            "{$this->lockAcquisitions} acquired, {$this->lockContentions} contentions ({$contentionRate}%), " .
            "avg wait {$avgLockWaitMs}ms");

        Logger::overlay('info', 'DISTRIBUTED_FLUSH_COMPLETE', [
            'worker_id' => $this->workerId,
            'flush_count' => $this->flushCount,
            'items_flushed' => $this->itemsFlushed,
            'partitions_processed' => $this->partitionsProcessed,
            'lock_acquisitions' => $this->lockAcquisitions,
            'lock_contentions' => $this->lockContentions,
            'contention_rate' => $contentionRate . '%',
            'avg_flush_ms' => $avgFlushMs,
            'avg_lock_wait_ms' => $avgLockWaitMs,
            'error_count' => $this->errorCount,
            'runtime_seconds' => $runtime,
        ]);
    }

    /**
     * Setup signal handlers
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleShutdownSignal']);
        }
    }

    /**
     * Handle shutdown signals
     */
    public function handleShutdownSignal(int $signal): void
    {
        enterprise_log("[DISTRIBUTED-FLUSH] 🛑 Shutdown signal received (signal: {$signal})");
        $this->running = false;
    }
}

/**
 * Main execution
 */
function main(): int
{
    $options = getopt('', [
        'max-runtime:',
        'worker-id:',
        'help',
    ]);

    if (isset($options['help'])) {
        echo "Distributed Overlay Flush Worker - Enterprise Galaxy V8.0\n";
        echo "==========================================================\n\n";
        echo "Distributed worker for partition-based overlay flush.\n";
        echo "Multiple instances can run in parallel.\n\n";
        echo "Usage: php distributed-overlay-flush-worker.php [options]\n\n";
        echo "Options:\n";
        echo "  --max-runtime=N   Maximum runtime in seconds (default: 300)\n";
        echo "  --worker-id=ID    Custom worker ID (default: auto-generated)\n";
        echo "  --help            Show this help\n\n";
        echo "Deployment:\n";
        echo "  docker-compose up -d --scale overlay_worker=4\n\n";
        echo "Architecture:\n";
        echo "  - 16 partitions (entity_id % 16)\n";
        echo "  - Distributed locking (10s TTL + 3s heartbeat)\n";
        echo "  - Metrics logging for Grafana\n";
        echo "  - Graceful shutdown with signal handlers\n\n";
        exit(0);
    }

    $maxRuntime = isset($options['max-runtime']) ? max(30, (int)$options['max-runtime']) : 300;
    $workerId = $options['worker-id'] ?? null;

    try {
        $worker = new DistributedOverlayFlushWorker($maxRuntime, $workerId);
        return $worker->run();
    } catch (\Exception $e) {
        enterprise_log("[DISTRIBUTED-FLUSH] ❌ Worker failed: " . $e->getMessage());
        echo "ERROR: " . $e->getMessage() . "\n";
        return 1;
    }
}

exit(main());
