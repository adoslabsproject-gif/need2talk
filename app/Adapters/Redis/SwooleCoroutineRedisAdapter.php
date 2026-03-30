<?php

declare(strict_types=1);

namespace Need2Talk\Adapters\Redis;

use Need2Talk\Contracts\Redis\RedisAdapterInterface;
use Swoole\Coroutine;

/**
 * SwooleCoroutineRedisAdapter - Coroutine-Safe Redis Adapter for Swoole WebSocket
 *
 * CRITICAL: This adapter prevents the DEADLOCK caused by socket sharing.
 *
 * Problem it solves:
 * - PHP-FPM: 1 request = 1 thread = 1 Redis socket = OK
 * - Swoole: N coroutines = shared Redis socket = PROTOCOL CORRUPTION → DEADLOCK
 *
 * Solution (ENTERPRISE v3.0):
 * - Uses standard phpredis \Redis with Runtime::enableCoroutine(SWOOLE_HOOK_ALL)
 * - Swoole hooks make \Redis non-blocking in coroutine context
 * - Each coroutine gets its own connection via Coroutine::getCid() pooling
 * - Connections are pooled per coroutine ID and database
 * - NO socket sharing = NO DEADLOCK possible
 *
 * WHY NOT Swoole\Coroutine\Redis:
 * - Swoole\Coroutine\Redis is NOT included by default in Swoole 6.x
 * - Requires separate compilation with --enable-coroutine-redis
 * - phpredis \Redis with hook is the recommended approach
 *
 * Database Allocation:
 * - DB 0: L1_cache (Ultra-fast local cache)
 * - DB 1: sessions (Session storage)
 * - DB 2: L3_async_email (Email queue)
 * - DB 3: rate_limit (Rate limiting counters)
 * - DB 4: L3_logging (WebSocket events/PubSub)
 * - DB 5: overlay (Write-behind reactions/views)
 * - DB 6: chat (Real-time chat ephemeral storage)
 *
 * @package Need2Talk\Adapters\Redis
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @updated 2025-12-03 v3.0 - Use phpredis \Redis with coroutine hook (Swoole 6.x compatible)
 */
class SwooleCoroutineRedisAdapter implements RedisAdapterInterface
{
    /**
     * Redis database mapping by type
     */
    private const DB_MAP = [
        'L1_cache' => 0,
        'sessions' => 1,
        'L3_async_email' => 2,
        'rate_limit' => 3,
        'L3_logging' => 4,
        'overlay' => 5,
        'chat' => 6,
    ];

    /**
     * Connection pool: keyed by "cid_{coroutineId}_db_{dbNumber}"
     * Each coroutine gets its own Redis connection to prevent socket contention
     * @var array<string, \Redis>
     */
    private array $connectionPool = [];

    /**
     * Failed connection attempts tracking (circuit breaker)
     * @var array<int, array{count: int, last_attempt: int}>
     */
    private array $failedAttempts = [];

    /**
     * Circuit breaker: max failures before opening circuit
     */
    private const CIRCUIT_BREAKER_THRESHOLD = 5;

    /**
     * Circuit breaker: seconds to wait before retry
     */
    private const CIRCUIT_BREAKER_TIMEOUT = 15;

    /**
     * Redis connection settings
     */
    private string $host;
    private int $port;
    private ?string $password;
    private float $timeout;

    public function __construct()
    {
        $this->host = $_ENV['REDIS_HOST'] ?? 'redis';
        $this->port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
        $this->password = $_ENV['REDIS_PASSWORD'] ?? null;
        $this->timeout = 2.0;
    }

    // ========================================================================
    // CONNECTION MANAGEMENT
    // ========================================================================

    /**
     * Execute an operation with a pooled connection (ENTERPRISE V10.11)
     *
     * This is the core method for all Redis operations. It:
     * 1. Borrows a connection from the pool
     * 2. Executes the callback with the connection
     * 3. Returns the connection to the pool (CRITICAL!)
     * 4. Returns the result
     *
     * @param string $type Database type (chat, overlay, etc.)
     * @param callable $callback Function that receives Redis connection
     * @param mixed $default Default value if connection unavailable
     * @return mixed Result from callback or default
     */
    private function executeWithConnection(string $type, callable $callback, mixed $default = null): mixed
    {
        $db = self::DB_MAP[$type] ?? 0;

        // Check circuit breaker
        if ($this->isCircuitOpen($db)) {
            return $default;
        }

        $redis = null;
        $fromPool = false;

        try {
            // ENTERPRISE V10.11: Borrow connection from pool
            if (function_exists('swoole_redis')) {
                $redis = swoole_redis($db);
                $fromPool = true;
            }

            if ($redis === null) {
                // Fallback for non-Swoole context (task workers, etc.)
                $redis = $this->createConnectionFallback($db);
                if ($redis === null) {
                    $this->recordFailure($db);
                    return $default;
                }
                $fromPool = false;
            }

            $this->resetCircuitBreaker($db);

            // Execute the operation
            return $callback($redis);

        } catch (\Exception $e) {
            $this->recordFailure($db);
            return $default;
        } finally {
            // CRITICAL: Always return pooled connection to pool
            if ($redis !== null && $fromPool && function_exists('swoole_redis_release')) {
                swoole_redis_release($redis, $db);
            }
        }
    }

    /**
     * Get Redis connection (DEPRECATED)
     *
     * @deprecated V10.11: Use executeWithConnection() instead
     * WARNING: This borrows from pool but doesn't return!
     * Direct callers MUST call swoole_redis_release() when done!
     *
     * @param string $type Database type (chat, overlay, etc.)
     * @return \Redis|null Redis connection or null on failure
     */
    public function getConnection(string $type = 'L1_cache'): ?\Redis
    {
        $db = self::DB_MAP[$type] ?? 0;

        if ($this->isCircuitOpen($db)) {
            return null;
        }

        if (function_exists('swoole_redis')) {
            try {
                $redis = swoole_redis($db);
                if ($redis !== null) {
                    $this->resetCircuitBreaker($db);
                }
                return $redis;
            } catch (\Exception $e) {
                $this->recordFailure($db);
                return null;
            }
        }

        return $this->createConnectionFallback($db);
    }

    /**
     * Fallback connection creation (should not be used in normal operation)
     *
     * @param int $db Database number (0-6)
     * @return \Redis|null Redis connection or null on failure
     */
    private function createConnectionFallback(int $db): ?\Redis
    {
        try {
            $redis = new \Redis();

            // Connect with timeout
            $connected = $redis->connect($this->host, $this->port, $this->timeout);

            if (!$connected) {
                $this->recordFailure($db);
                return null;
            }

            // Authenticate if password set
            if ($this->password !== null && $this->password !== '') {
                $authed = $redis->auth($this->password);
                if (!$authed) {
                    $this->recordFailure($db);
                    return null;
                }
            }

            // Select database
            $redis->select($db);

            // Set read timeout
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->timeout);

            return $redis;

        } catch (\Exception $e) {
            $this->recordFailure($db);
            error_log("[SwooleRedis] Connection failed to DB {$db}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Release a connection back to the pool
     *
     * ENTERPRISE V9.3: Must be called after getConnection() in Swoole context
     * In PHP-FPM context, this is a no-op (connections are per-request)
     *
     * @param \Redis $redis The connection to release
     * @param string $type Database type (chat, overlay, etc.)
     */
    public function releaseConnection(\Redis $redis, string $type = 'L1_cache'): void
    {
        if (function_exists('swoole_redis_release')) {
            $db = self::DB_MAP[$type] ?? 0;
            swoole_redis_release($redis, $db);
        }
        // In PHP-FPM, connections are per-request - no pool to return to
    }

    public function isAvailable(string $type = 'L1_cache'): bool
    {
        $db = self::DB_MAP[$type] ?? 0;

        if ($this->isCircuitOpen($db)) {
            return false;
        }

        $redis = $this->getConnection($type);
        if ($redis !== null) {
            $this->releaseConnection($redis, $type);
            return true;
        }
        return false;
    }

    // ========================================================================
    // CIRCUIT BREAKER
    // ========================================================================

    private function isCircuitOpen(int $db): bool
    {
        if (!isset($this->failedAttempts[$db])) {
            return false;
        }

        $attempts = $this->failedAttempts[$db];

        if ($attempts['count'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $elapsed = time() - $attempts['last_attempt'];
            if ($elapsed < self::CIRCUIT_BREAKER_TIMEOUT) {
                return true; // Circuit still open
            }
            // Timeout elapsed, allow retry (half-open state)
        }

        return false;
    }

    private function recordFailure(int $db): void
    {
        if (!isset($this->failedAttempts[$db])) {
            $this->failedAttempts[$db] = ['count' => 0, 'last_attempt' => 0];
        }

        $this->failedAttempts[$db]['count']++;
        $this->failedAttempts[$db]['last_attempt'] = time();
    }

    private function resetCircuitBreaker(int $db): void
    {
        unset($this->failedAttempts[$db]);
    }

    // ========================================================================
    // STRING OPERATIONS
    // ========================================================================

    public function get(string $key, string $type = 'L1_cache'): mixed
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            $value = $redis->get($key);
            return $value !== false ? $value : null;
        });
    }

    public function set(string $key, mixed $value, string $type = 'L1_cache'): bool
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $value) {
            $serialized = is_string($value) ? $value : json_encode($value);
            return (bool) $redis->set($key, $serialized);
        }, false);
    }

    public function setex(string $key, int $ttl, mixed $value, string $type = 'L1_cache'): bool
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $ttl, $value) {
            $serialized = is_string($value) ? $value : json_encode($value);
            return (bool) $redis->setex($key, $ttl, $serialized);
        }, false);
    }

    public function del(string $key, string $type = 'L1_cache'): bool
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return $redis->del($key) > 0;
        }, false);
    }

    // ========================================================================
    // COUNTER OPERATIONS
    // ========================================================================

    public function incr(string $key, string $type = 'L1_cache'): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return $redis->incr($key);
        }, false);
    }

    public function incrby(string $key, int $amount, string $type = 'L1_cache'): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $amount) {
            return $redis->incrBy($key, $amount);
        }, false);
    }

    public function decr(string $key, string $type = 'L1_cache'): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return $redis->decr($key);
        }, false);
    }

    // ========================================================================
    // EXPIRATION & EXISTENCE
    // ========================================================================

    public function expire(string $key, int $seconds, string $type = 'L1_cache'): bool
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $seconds) {
            return (bool) $redis->expire($key, $seconds);
        }, false);
    }

    public function ttl(string $key, string $type = 'L1_cache'): int
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return (int) $redis->ttl($key);
        }, -2);
    }

    public function exists(string $key, string $type = 'L1_cache'): bool
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return (bool) $redis->exists($key);
        }, false);
    }

    // ========================================================================
    // SORTED SET OPERATIONS
    // ========================================================================

    public function zadd(string $key, float $score, mixed $value, string $type = 'overlay'): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $score, $value) {
            $serialized = is_string($value) ? $value : json_encode($value);
            return $redis->zAdd($key, $score, $serialized);
        }, false);
    }

    public function zrange(string $key, int $start, int $stop, string $type = 'overlay', bool $withScores = false): array
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $start, $stop, $withScores) {
            return $redis->zRange($key, $start, $stop, $withScores) ?: [];
        }, []);
    }

    public function zrangebyscore(string $key, mixed $min, mixed $max, string $type = 'overlay'): array
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $min, $max) {
            return $redis->zRangeByScore($key, (string) $min, (string) $max) ?: [];
        }, []);
    }

    public function zrem(string $key, mixed $member, string $type = 'overlay'): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $member) {
            return $redis->zRem($key, $member);
        }, false);
    }

    public function zremrangebyrank(string $key, int $start, int $stop, string $type = 'overlay'): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $start, $stop) {
            return $redis->zRemRangeByRank($key, $start, $stop);
        }, false);
    }

    public function zcard(string $key, string $type = 'overlay'): int
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return (int) $redis->zCard($key);
        }, 0);
    }

    // ========================================================================
    // SET OPERATIONS
    // ========================================================================

    public function sadd(string $key, string $type, mixed ...$members): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $members) {
            return $redis->sAdd($key, ...$members);
        }, false);
    }

    public function srem(string $key, string $type, mixed ...$members): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $members) {
            return $redis->sRem($key, ...$members);
        }, false);
    }

    public function smembers(string $key, string $type = 'L1_cache'): array
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return $redis->sMembers($key) ?: [];
        }, []);
    }

    public function scard(string $key, string $type = 'L1_cache'): int
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return (int) $redis->sCard($key);
        }, 0);
    }

    /**
     * ENTERPRISE V10.4: Check if member exists in set (for DND notifications)
     */
    public function sismember(string $key, string $member, string $type = 'L1_cache'): bool
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $member) {
            return (bool) $redis->sIsMember($key, $member);
        }, false);
    }

    // ========================================================================
    // PUB/SUB OPERATIONS
    // ========================================================================

    public function publish(string $channel, string $message): int|false
    {
        // Use L3_logging for PubSub (DB 4)
        return $this->executeWithConnection('L3_logging', function ($redis) use ($channel, $message) {
            return $redis->publish($channel, $message);
        }, false);
    }

    // ========================================================================
    // ITERATION
    // ========================================================================

    public function scan(string $pattern, string $type = 'L1_cache', int $count = 100): array
    {
        return $this->executeWithConnection($type, function ($redis) use ($pattern, $count) {
            $keys = [];
            $iterator = null;

            // phpredis scan() uses reference for iterator
            while (($scanKeys = $redis->scan($iterator, $pattern, $count)) !== false) {
                $keys = array_merge($keys, $scanKeys);
            }

            return $keys;
        }, []);
    }

    // ========================================================================
    // HASH OPERATIONS (ENTERPRISE V10.56)
    // ========================================================================

    /**
     * Set a hash field value
     * ENTERPRISE V10.56: Added for activity tracking in chat rooms
     */
    public function hset(string $key, string $type, string $field, string $value): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $field, $value) {
            return $redis->hSet($key, $field, $value);
        }, false);
    }

    /**
     * Get a hash field value
     */
    public function hget(string $key, string $type, string $field): string|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $field) {
            return $redis->hGet($key, $field);
        }, false);
    }

    /**
     * Get all hash fields and values
     */
    public function hgetall(string $key, string $type = 'L1_cache'): array
    {
        return $this->executeWithConnection($type, function ($redis) use ($key) {
            return $redis->hGetAll($key) ?: [];
        }, []);
    }

    /**
     * Delete hash fields
     */
    public function hdel(string $key, string $type, string ...$fields): int|false
    {
        return $this->executeWithConnection($type, function ($redis) use ($key, $fields) {
            return $redis->hDel($key, ...$fields);
        }, false);
    }

    // ========================================================================
    // HEALTH CHECK
    // ========================================================================

    public function ping(string $type = 'L1_cache'): bool
    {
        return $this->executeWithConnection($type, function ($redis) {
            $pong = $redis->ping();
            return $pong === '+PONG' || $pong === true;
        }, false);
    }

    // ========================================================================
    // COROUTINE CONNECTION CLEANUP
    // ========================================================================

    /**
     * Cleanup connections for ended coroutines
     *
     * Call this periodically (e.g., every 60s) or when pool grows too large.
     * Swoole coroutines end without cleanup callbacks, so we need to check
     * if coroutines still exist and clean up orphaned connections.
     *
     * @return int Number of connections cleaned up
     */
    public function cleanup(): int
    {
        $cleaned = 0;

        foreach (array_keys($this->connectionPool) as $key) {
            if (preg_match('/^cid_(\d+)_/', $key, $matches)) {
                $cid = (int) $matches[1];

                // Check if coroutine still exists
                if ($cid > 0 && !Coroutine::exists($cid)) {
                    try {
                        $this->connectionPool[$key]->close();
                    } catch (\Exception $e) {
                        // Ignore close errors
                    }
                    unset($this->connectionPool[$key]);
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Get pool statistics for monitoring
     *
     * @return array{pool_size: int, coroutines: int, by_db: array<int, int>}
     */
    public function getPoolStats(): array
    {
        $stats = [
            'pool_size' => count($this->connectionPool),
            'coroutines' => 0,
            'by_db' => [],
        ];

        $seenCids = [];

        foreach (array_keys($this->connectionPool) as $key) {
            if (preg_match('/^cid_(\d+)_db_(\d+)$/', $key, $matches)) {
                $cid = (int) $matches[1];
                $db = (int) $matches[2];

                if (!isset($seenCids[$cid])) {
                    $seenCids[$cid] = true;
                    $stats['coroutines']++;
                }

                if (!isset($stats['by_db'][$db])) {
                    $stats['by_db'][$db] = 0;
                }
                $stats['by_db'][$db]++;
            }
        }

        return $stats;
    }

    /**
     * Close all connections (for graceful shutdown)
     */
    public function closeAll(): void
    {
        foreach ($this->connectionPool as $redis) {
            try {
                $redis->close();
            } catch (\Exception $e) {
                // Ignore
            }
        }

        $this->connectionPool = [];
        $this->failedAttempts = [];
    }
}
