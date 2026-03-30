<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * PartitionLockManager - Enterprise Galaxy V8.0
 *
 * DISTRIBUTED LOCKING FOR PARALLEL FLUSH WORKERS (2025-12-01)
 *
 * Manages distributed locks for partition-based flush workers.
 * Prevents multiple workers from processing the same partition simultaneously.
 *
 * ARCHITECTURE:
 * ┌────────────────────────────────────────────────────────────────────────────┐
 * │ Worker 1 → acquireLock(reactions, p0) → GRANTED → flush → releaseLock    │
 * │ Worker 2 → acquireLock(reactions, p0) → DENIED (Worker 1 has lock)       │
 * │ Worker 2 → acquireLock(reactions, p1) → GRANTED → flush → releaseLock    │
 * ├────────────────────────────────────────────────────────────────────────────┤
 * │ LOCK TTL: 10 seconds (short to prevent deadlock on worker crash)          │
 * │ HEARTBEAT: 3 second refresh (extends lock while working)                  │
 * │ IMPLEMENTATION: Redis SETNX + Lua atomic check-and-delete                 │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * SAFETY GUARANTEES:
 * 1. At most ONE worker processes a partition at any time
 * 2. Crashed worker releases lock after TTL (10s max wait)
 * 3. Heartbeat extends lock for long-running flushes
 * 4. Atomic release prevents accidental unlock by wrong worker
 *
 * @package Need2Talk\Services\Cache
 */
class PartitionLockManager
{
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Lock TTL in seconds
     * Short TTL (10s) prevents deadlock if worker crashes
     * Heartbeat extends lock for long-running operations
     */
    private const LOCK_TTL = 10;

    /**
     * Heartbeat interval in seconds
     * Should be significantly less than LOCK_TTL
     */
    private const HEARTBEAT_INTERVAL = 3;

    /**
     * Lock key prefix
     */
    private const LOCK_PREFIX = 'overlay:lock';

    /**
     * Metrics key prefix for monitoring
     */
    private const METRICS_PREFIX = 'overlay:metrics:locks';

    // =========================================================================
    // LUA SCRIPTS (ATOMIC OPERATIONS)
    // =========================================================================

    /**
     * Lua script for atomic lock release
     * Only releases if the lock is held by the requesting worker
     * Returns: 1 if released, 0 if not held by this worker
     */
    private const LUA_RELEASE_LOCK = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA;

    /**
     * Lua script for atomic lock extension (heartbeat)
     * Only extends if the lock is held by the requesting worker
     * Returns: 1 if extended, 0 if not held by this worker
     */
    private const LUA_EXTEND_LOCK = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('expire', KEYS[1], ARGV[2])
end
return 0
LUA;

    /**
     * Lua script for atomic lock acquisition with metrics
     * Sets lock and increments acquisition counter
     * Returns: 1 if acquired, 0 if already locked
     */
    private const LUA_ACQUIRE_WITH_METRICS = <<<'LUA'
local acquired = redis.call('set', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2])
if acquired then
    redis.call('hincrby', KEYS[2], 'acquired', 1)
    return 1
else
    redis.call('hincrby', KEYS[2], 'contention', 1)
    return 0
end
LUA;

    // =========================================================================
    // SINGLETON
    // =========================================================================

    private static ?self $instance = null;
    private ?\Redis $redis = null;

    // Cached Lua script SHAs
    private ?string $releaseScriptSha = null;
    private ?string $extendScriptSha = null;
    private ?string $acquireScriptSha = null;

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

    // =========================================================================
    // LOCK OPERATIONS
    // =========================================================================

    /**
     * Attempt to acquire a partition lock
     *
     * @param string $type Event type (reactions, plays, etc.)
     * @param int $partition Partition number (0-15)
     * @param string $workerId Unique worker identifier
     * @return bool True if lock acquired, false if already held by another worker
     */
    public function acquireLock(string $type, int $partition, string $workerId): bool
    {
        if (!$this->redis) {
            return false;
        }

        $lockKey = $this->getLockKey($type, $partition);
        $metricsKey = $this->getMetricsKey($type, $partition);
        $startTime = microtime(true);

        try {
            // Use Lua script for atomic acquisition with metrics
            if ($this->acquireScriptSha === null) {
                $this->acquireScriptSha = $this->redis->script('LOAD', self::LUA_ACQUIRE_WITH_METRICS);
            }

            // PHP Redis evalSha syntax: evalSha(sha, keys[], numKeys, [args...])
            // For PHP Redis 5.x+, use array for keys and args together
            $acquired = $this->redis->evalSha(
                $this->acquireScriptSha,
                [$lockKey, $metricsKey, $workerId, (string) self::LOCK_TTL],
                2  // Number of KEYS (lockKey, metricsKey)
            );

            $durationMs = (microtime(true) - $startTime) * 1000;

            if ($acquired) {
                Logger::overlay('debug', 'Lock acquired', [
                    'type' => $type,
                    'partition' => $partition,
                    'worker_id' => $workerId,
                    'lock_ttl' => self::LOCK_TTL,
                    'duration_ms' => round($durationMs, 2),
                ]);
                return true;
            }

            // Log contention (another worker has the lock)
            Logger::overlay('debug', 'Lock contention', [
                'type' => $type,
                'partition' => $partition,
                'worker_id' => $workerId,
                'current_holder' => $this->redis->get($lockKey),
                'duration_ms' => round($durationMs, 2),
            ]);

            return false;

        } catch (\Exception $e) {
            // Fallback to simple SETNX if Lua fails
            Logger::overlay('warning', 'Lock acquisition Lua fallback', [
                'type' => $type,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);

            try {
                return (bool) $this->redis->set($lockKey, $workerId, ['NX', 'EX' => self::LOCK_TTL]);
            } catch (\Exception $e2) {
                Logger::overlay('error', 'Lock acquisition failed completely', [
                    'type' => $type,
                    'partition' => $partition,
                    'error' => $e2->getMessage(),
                ]);
                return false;
            }
        }
    }

    /**
     * Release a partition lock
     *
     * Uses atomic Lua script to ensure only the lock holder can release.
     * Prevents accidental release by a different worker.
     *
     * @param string $type Event type
     * @param int $partition Partition number
     * @param string $workerId Worker that should hold the lock
     * @return bool True if released, false if not held by this worker
     */
    public function releaseLock(string $type, int $partition, string $workerId): bool
    {
        if (!$this->redis) {
            return false;
        }

        $lockKey = $this->getLockKey($type, $partition);
        $startTime = microtime(true);

        try {
            // Load script if not cached
            if ($this->releaseScriptSha === null) {
                $this->releaseScriptSha = $this->redis->script('LOAD', self::LUA_RELEASE_LOCK);
            }

            // PHP Redis evalSha: keys and args in single array, numKeys specifies split
            $released = $this->redis->evalSha(
                $this->releaseScriptSha,
                [$lockKey, $workerId],
                1  // Number of KEYS (just lockKey)
            );

            $durationMs = (microtime(true) - $startTime) * 1000;

            if ($released) {
                Logger::overlay('debug', 'Lock released', [
                    'type' => $type,
                    'partition' => $partition,
                    'worker_id' => $workerId,
                    'duration_ms' => round($durationMs, 2),
                ]);
                return true;
            }

            // Lock was not held by this worker
            Logger::overlay('warning', 'Lock release failed - not held by worker', [
                'type' => $type,
                'partition' => $partition,
                'worker_id' => $workerId,
                'current_holder' => $this->redis->get($lockKey),
            ]);

            return false;

        } catch (\Exception $e) {
            Logger::overlay('error', 'Lock release error', [
                'type' => $type,
                'partition' => $partition,
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Extend lock TTL (heartbeat)
     *
     * Call this periodically during long-running flush operations
     * to prevent lock expiration while still working.
     *
     * @param string $type Event type
     * @param int $partition Partition number
     * @param string $workerId Worker that holds the lock
     * @return bool True if extended, false if not held by this worker
     */
    public function extendLock(string $type, int $partition, string $workerId): bool
    {
        if (!$this->redis) {
            return false;
        }

        $lockKey = $this->getLockKey($type, $partition);

        try {
            // Load script if not cached
            if ($this->extendScriptSha === null) {
                $this->extendScriptSha = $this->redis->script('LOAD', self::LUA_EXTEND_LOCK);
            }

            // PHP Redis evalSha: keys and args in single array, numKeys specifies split
            $extended = $this->redis->evalSha(
                $this->extendScriptSha,
                [$lockKey, $workerId, (string) self::LOCK_TTL],
                1  // Number of KEYS (just lockKey)
            );

            if ($extended) {
                Logger::overlay('debug', 'Lock extended (heartbeat)', [
                    'type' => $type,
                    'partition' => $partition,
                    'worker_id' => $workerId,
                    'new_ttl' => self::LOCK_TTL,
                ]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Logger::overlay('error', 'Lock extension error', [
                'type' => $type,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if a partition is currently locked
     *
     * @param string $type Event type
     * @param int $partition Partition number
     * @return bool True if locked, false if available
     */
    public function isLocked(string $type, int $partition): bool
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $lockKey = $this->getLockKey($type, $partition);
            return (bool) $this->redis->exists($lockKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get current lock holder for a partition
     *
     * @param string $type Event type
     * @param int $partition Partition number
     * @return string|null Worker ID or null if not locked
     */
    public function getLockHolder(string $type, int $partition): ?string
    {
        if (!$this->redis) {
            return null;
        }

        try {
            $lockKey = $this->getLockKey($type, $partition);
            $holder = $this->redis->get($lockKey);
            return $holder ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get remaining TTL for a lock
     *
     * @param string $type Event type
     * @param int $partition Partition number
     * @return int TTL in seconds, -2 if key doesn't exist, -1 if no expiry
     */
    public function getLockTTL(string $type, int $partition): int
    {
        if (!$this->redis) {
            return -2;
        }

        try {
            $lockKey = $this->getLockKey($type, $partition);
            return $this->redis->ttl($lockKey);
        } catch (\Exception $e) {
            return -2;
        }
    }

    // =========================================================================
    // LOCK STATUS & MONITORING
    // =========================================================================

    /**
     * Get status of all partition locks for a type
     *
     * @param string $type Event type
     * @return array Lock status per partition
     */
    public function getLockStatus(string $type): array
    {
        if (!$this->redis) {
            return [];
        }

        $status = [
            'type' => $type,
            'partitions' => [],
            'locked_count' => 0,
            'available_count' => 0,
        ];

        for ($p = 0; $p < PartitionedWriteBehindBuffer::PARTITION_COUNT; $p++) {
            $lockKey = $this->getLockKey($type, $p);

            try {
                $holder = $this->redis->get($lockKey);
                $ttl = $this->redis->ttl($lockKey);

                if ($holder) {
                    $status['partitions'][$p] = [
                        'locked' => true,
                        'holder' => $holder,
                        'ttl' => $ttl,
                    ];
                    $status['locked_count']++;
                } else {
                    $status['partitions'][$p] = [
                        'locked' => false,
                    ];
                    $status['available_count']++;
                }
            } catch (\Exception $e) {
                $status['partitions'][$p] = [
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $status;
    }

    /**
     * Get lock metrics for monitoring
     *
     * @param string $type Event type
     * @return array Metrics per partition
     */
    public function getLockMetrics(string $type): array
    {
        if (!$this->redis) {
            return [];
        }

        $metrics = [
            'type' => $type,
            'partitions' => [],
            'totals' => [
                'acquired' => 0,
                'contention' => 0,
            ],
        ];

        for ($p = 0; $p < PartitionedWriteBehindBuffer::PARTITION_COUNT; $p++) {
            $metricsKey = $this->getMetricsKey($type, $p);

            try {
                $partitionMetrics = $this->redis->hGetAll($metricsKey);
                $metrics['partitions'][$p] = $partitionMetrics ?: [];

                $metrics['totals']['acquired'] += (int) ($partitionMetrics['acquired'] ?? 0);
                $metrics['totals']['contention'] += (int) ($partitionMetrics['contention'] ?? 0);
            } catch (\Exception $e) {
                $metrics['partitions'][$p] = ['error' => $e->getMessage()];
            }
        }

        // Calculate contention rate
        $total = $metrics['totals']['acquired'] + $metrics['totals']['contention'];
        $metrics['totals']['contention_rate'] = $total > 0
            ? round($metrics['totals']['contention'] / $total * 100, 2) . '%'
            : '0%';

        return $metrics;
    }

    /**
     * Reset lock metrics (for testing/maintenance)
     *
     * @param string $type Event type
     */
    public function resetMetrics(string $type): void
    {
        if (!$this->redis) {
            return;
        }

        for ($p = 0; $p < PartitionedWriteBehindBuffer::PARTITION_COUNT; $p++) {
            try {
                $metricsKey = $this->getMetricsKey($type, $p);
                $this->redis->del($metricsKey);
            } catch (\Exception $e) {
                // Continue with other partitions
            }
        }
    }

    /**
     * Get aggregated lock stats for all types
     *
     * @return array Stats for monitoring dashboard
     */
    public function getLockStats(): array
    {
        $types = ['reactions', 'views', 'plays', 'comments'];
        $stats = [
            'total_locked' => 0,
            'total_available' => 0,
            'by_type' => [],
        ];

        foreach ($types as $type) {
            $typeStats = $this->getLockStatus($type);
            $stats['by_type'][$type] = [
                'locked' => $typeStats['locked_count'] ?? 0,
                'available' => $typeStats['available_count'] ?? 0,
            ];
            $stats['total_locked'] += $typeStats['locked_count'] ?? 0;
            $stats['total_available'] += $typeStats['available_count'] ?? 0;
        }

        return $stats;
    }

    // =========================================================================
    // KEY GENERATION
    // =========================================================================

    /**
     * Get Redis key for partition lock
     */
    private function getLockKey(string $type, int $partition): string
    {
        return self::LOCK_PREFIX . ":{$type}:p{$partition}";
    }

    /**
     * Get Redis key for lock metrics
     */
    private function getMetricsKey(string $type, int $partition): string
    {
        return self::METRICS_PREFIX . ":{$type}:p{$partition}";
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================

    /**
     * Get heartbeat interval (for workers to know when to refresh)
     */
    public static function getHeartbeatInterval(): int
    {
        return self::HEARTBEAT_INTERVAL;
    }

    /**
     * Get lock TTL
     */
    public static function getLockTTLConfig(): int
    {
        return self::LOCK_TTL;
    }

    /**
     * Force release all locks for a type (DANGEROUS - use for recovery only)
     *
     * @param string $type Event type
     * @return int Number of locks released
     */
    public function forceReleaseAllLocks(string $type): int
    {
        if (!$this->redis) {
            return 0;
        }

        $released = 0;

        for ($p = 0; $p < PartitionedWriteBehindBuffer::PARTITION_COUNT; $p++) {
            try {
                $lockKey = $this->getLockKey($type, $p);
                if ($this->redis->del($lockKey)) {
                    $released++;
                }
            } catch (\Exception $e) {
                // Continue with other partitions
            }
        }

        Logger::overlay('warning', 'Force released all locks', [
            'type' => $type,
            'released_count' => $released,
        ]);

        return $released;
    }
}
