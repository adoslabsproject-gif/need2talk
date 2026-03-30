<?php

declare(strict_types=1);

namespace Need2Talk\Adapters\Redis;

use Need2Talk\Contracts\Redis\RedisAdapterInterface;
use Need2Talk\Core\EnterpriseRedisManager;

/**
 * PhpFpmRedisAdapter - Redis Adapter for PHP-FPM Context
 *
 * Wraps EnterpriseRedisManager, inheriting all its enterprise features:
 * - Persistent connection pooling
 * - Circuit breaker pattern (5 failures → 15s open)
 * - Multi-database support (DB 0-6)
 * - Connection health monitoring
 * - Automatic reconnection
 *
 * This adapter is used in HTTP request context (PHP-FPM).
 * For Swoole WebSocket context, use SwooleCoroutineRedisAdapter.
 *
 * @package Need2Talk\Adapters\Redis
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class PhpFpmRedisAdapter implements RedisAdapterInterface
{
    private EnterpriseRedisManager $manager;

    public function __construct()
    {
        $this->manager = EnterpriseRedisManager::getInstance();
    }

    // ========================================================================
    // CONNECTION MANAGEMENT
    // ========================================================================

    public function getConnection(string $type = 'L1_cache'): ?\Redis
    {
        return $this->manager->getConnection($type);
    }

    public function isAvailable(string $type = 'L1_cache'): bool
    {
        return $this->manager->isAvailable($type);
    }

    /**
     * Release a connection back to the pool (ENTERPRISE V9.3)
     *
     * In PHP-FPM context this is a NO-OP because:
     * - Connections are per-request, not pooled
     * - EnterpriseRedisManager uses persistent connections shared within the request
     * - Connection cleanup happens automatically at request end
     *
     * This method exists for interface compliance with SwooleCoroutineRedisAdapter,
     * which MUST release connections back to the coroutine pool.
     *
     * @param \Redis $redis The connection to release (ignored in PHP-FPM)
     * @param string $type Connection pool type (ignored in PHP-FPM)
     */
    public function releaseConnection(\Redis $redis, string $type = 'L1_cache'): void
    {
        // NO-OP in PHP-FPM context
        // Connections are per-request, managed by EnterpriseRedisManager
        // Cleanup occurs automatically at request termination
    }

    // ========================================================================
    // STRING OPERATIONS
    // ========================================================================

    public function get(string $key, string $type = 'L1_cache'): mixed
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return null;
        }

        try {
            $value = $redis->get($key);
            return $value !== false ? $value : null;
        } catch (\RedisException $e) {
            return null;
        }
    }

    public function set(string $key, mixed $value, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            $serialized = is_string($value) ? $value : json_encode($value);
            return (bool) $redis->set($key, $serialized);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function setex(string $key, int $ttl, mixed $value, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            $serialized = is_string($value) ? $value : json_encode($value);
            return (bool) $redis->setex($key, $ttl, $serialized);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function del(string $key, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->del($key) > 0;
        } catch (\RedisException $e) {
            return false;
        }
    }

    // ========================================================================
    // COUNTER OPERATIONS
    // ========================================================================

    public function incr(string $key, string $type = 'L1_cache'): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->incr($key);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function incrby(string $key, int $amount, string $type = 'L1_cache'): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->incrBy($key, $amount);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function decr(string $key, string $type = 'L1_cache'): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->decr($key);
        } catch (\RedisException $e) {
            return false;
        }
    }

    // ========================================================================
    // EXPIRATION & EXISTENCE
    // ========================================================================

    public function expire(string $key, int $seconds, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return (bool) $redis->expire($key, $seconds);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function ttl(string $key, string $type = 'L1_cache'): int
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return -2;
        }

        try {
            return (int) $redis->ttl($key);
        } catch (\RedisException $e) {
            return -2;
        }
    }

    public function exists(string $key, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return (bool) $redis->exists($key);
        } catch (\RedisException $e) {
            return false;
        }
    }

    // ========================================================================
    // SORTED SET OPERATIONS
    // ========================================================================

    public function zadd(string $key, float $score, mixed $value, string $type = 'overlay'): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            $serialized = is_string($value) ? $value : json_encode($value);
            return $redis->zAdd($key, $score, $serialized);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function zrange(string $key, int $start, int $stop, string $type = 'overlay', bool $withScores = false): array
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return [];
        }

        try {
            return $redis->zRange($key, $start, $stop, $withScores) ?: [];
        } catch (\RedisException $e) {
            return [];
        }
    }

    public function zrangebyscore(string $key, mixed $min, mixed $max, string $type = 'overlay'): array
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return [];
        }

        try {
            return $redis->zRangeByScore($key, (string) $min, (string) $max) ?: [];
        } catch (\RedisException $e) {
            return [];
        }
    }

    public function zrem(string $key, mixed $member, string $type = 'overlay'): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->zRem($key, $member);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function zremrangebyrank(string $key, int $start, int $stop, string $type = 'overlay'): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->zRemRangeByRank($key, $start, $stop);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function zcard(string $key, string $type = 'overlay'): int
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return 0;
        }

        try {
            return (int) $redis->zCard($key);
        } catch (\RedisException $e) {
            return 0;
        }
    }

    // ========================================================================
    // SET OPERATIONS
    // ========================================================================

    public function sadd(string $key, string $type, mixed ...$members): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->sAdd($key, ...$members);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function srem(string $key, string $type, mixed ...$members): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->sRem($key, ...$members);
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function smembers(string $key, string $type = 'L1_cache'): array
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return [];
        }

        try {
            return $redis->sMembers($key) ?: [];
        } catch (\RedisException $e) {
            return [];
        }
    }

    public function scard(string $key, string $type = 'L1_cache'): int
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return 0;
        }

        try {
            return (int) $redis->sCard($key);
        } catch (\RedisException $e) {
            return 0;
        }
    }

    /**
     * ENTERPRISE V10.4: Check if member exists in set (for DND notifications)
     */
    public function sismember(string $key, string $member, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return (bool) $redis->sIsMember($key, $member);
        } catch (\RedisException $e) {
            return false;
        }
    }

    // ========================================================================
    // PUB/SUB OPERATIONS
    // ========================================================================

    public function publish(string $channel, string $message): int|false
    {
        // Use L3_logging for PubSub (DB 4)
        $redis = $this->getConnection('L3_logging');
        if (!$redis) {
            return false;
        }

        try {
            return $redis->publish($channel, $message);
        } catch (\RedisException $e) {
            return false;
        }
    }

    // ========================================================================
    // ITERATION
    // ========================================================================

    public function scan(string $pattern, string $type = 'L1_cache', int $count = 100): array
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return [];
        }

        try {
            $keys = [];
            $iterator = null;

            while (($scanKeys = $redis->scan($iterator, $pattern, $count)) !== false) {
                $keys = array_merge($keys, $scanKeys);
            }

            return $keys;
        } catch (\RedisException $e) {
            return [];
        }
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
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->hSet($key, $field, $value);
        } catch (\RedisException $e) {
            return false;
        }
    }

    /**
     * Get a hash field value
     */
    public function hget(string $key, string $type, string $field): string|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->hGet($key, $field);
        } catch (\RedisException $e) {
            return false;
        }
    }

    /**
     * Get all hash fields and values
     */
    public function hgetall(string $key, string $type = 'L1_cache'): array
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return [];
        }

        try {
            return $redis->hGetAll($key) ?: [];
        } catch (\RedisException $e) {
            return [];
        }
    }

    /**
     * Delete hash fields
     */
    public function hdel(string $key, string $type, string ...$fields): int|false
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->hDel($key, ...$fields);
        } catch (\RedisException $e) {
            return false;
        }
    }

    // ========================================================================
    // HEALTH CHECK
    // ========================================================================

    public function ping(string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);
        if (!$redis) {
            return false;
        }

        try {
            return $redis->ping() === '+PONG' || $redis->ping() === true;
        } catch (\RedisException $e) {
            return false;
        }
    }
}
