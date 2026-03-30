<?php

declare(strict_types=1);

namespace Need2Talk\Contracts\Redis;

/**
 * Redis Adapter Interface - Enterprise Context-Aware Redis Access
 *
 * This interface abstracts Redis operations for both PHP-FPM and Swoole contexts.
 * Implementations handle connection pooling, coroutine safety, and circuit breakers.
 *
 * Database Allocation:
 * - DB 0: L1_cache (Ultra-fast local cache)
 * - DB 1: sessions (Session storage)
 * - DB 2: L3_async_email (Email queue)
 * - DB 3: rate_limit (Rate limiting counters)
 * - DB 4: L3_logging (WebSocket events)
 * - DB 5: overlay (Write-behind reactions/views)
 * - DB 6: chat (Real-time chat ephemeral storage)
 *
 * @package Need2Talk\Contracts\Redis
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
interface RedisAdapterInterface
{
    // ========================================================================
    // CONNECTION MANAGEMENT
    // ========================================================================

    /**
     * Get a Redis connection for the specified type
     *
     * In PHP-FPM: Returns pooled persistent connection
     * In Swoole: Returns per-coroutine connection (prevents socket contention)
     *
     * @param string $type Connection pool type (L1_cache, sessions, chat, etc.)
     * @return \Redis|null Redis instance or null if unavailable
     */
    public function getConnection(string $type = 'L1_cache'): ?\Redis;

    /**
     * Check if Redis is available for the specified type
     *
     * @param string $type Connection pool type
     * @return bool True if Redis is available and responding
     */
    public function isAvailable(string $type = 'L1_cache'): bool;

    /**
     * Release a connection back to the pool (ENTERPRISE V9.3)
     *
     * In Swoole: Returns the connection to the coroutine pool
     * In PHP-FPM: No-op (connections are per-request, not pooled)
     *
     * MUST be called after getConnection() when done using the connection,
     * otherwise pool exhaustion will occur in Swoole context.
     *
     * @param \Redis $redis The connection to release
     * @param string $type Connection pool type
     */
    public function releaseConnection(\Redis $redis, string $type = 'L1_cache'): void;

    // ========================================================================
    // STRING OPERATIONS
    // ========================================================================

    /**
     * Get a value from Redis
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return mixed Value or null if not found
     */
    public function get(string $key, string $type = 'L1_cache'): mixed;

    /**
     * Set a value in Redis
     *
     * @param string $key Redis key
     * @param mixed $value Value to store (non-strings will be JSON encoded)
     * @param string $type Connection pool type
     * @return bool True on success
     */
    public function set(string $key, mixed $value, string $type = 'L1_cache'): bool;

    /**
     * Set a value with expiration
     *
     * @param string $key Redis key
     * @param int $ttl Time to live in seconds
     * @param mixed $value Value to store
     * @param string $type Connection pool type
     * @return bool True on success
     */
    public function setex(string $key, int $ttl, mixed $value, string $type = 'L1_cache'): bool;

    /**
     * Delete a key
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return bool True on success
     */
    public function del(string $key, string $type = 'L1_cache'): bool;

    // ========================================================================
    // COUNTER OPERATIONS
    // ========================================================================

    /**
     * Increment a key by 1
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return int|false New value or false on error
     */
    public function incr(string $key, string $type = 'L1_cache'): int|false;

    /**
     * Increment a key by a specific amount
     *
     * @param string $key Redis key
     * @param int $amount Amount to increment
     * @param string $type Connection pool type
     * @return int|false New value or false on error
     */
    public function incrby(string $key, int $amount, string $type = 'L1_cache'): int|false;

    /**
     * Decrement a key by 1
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return int|false New value or false on error
     */
    public function decr(string $key, string $type = 'L1_cache'): int|false;

    // ========================================================================
    // EXPIRATION & EXISTENCE
    // ========================================================================

    /**
     * Set expiration time on a key
     *
     * @param string $key Redis key
     * @param int $seconds TTL in seconds
     * @param string $type Connection pool type
     * @return bool True on success
     */
    public function expire(string $key, int $seconds, string $type = 'L1_cache'): bool;

    /**
     * Get remaining TTL for a key
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return int TTL in seconds, -1 if no TTL, -2 if key doesn't exist
     */
    public function ttl(string $key, string $type = 'L1_cache'): int;

    /**
     * Check if a key exists
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return bool True if key exists
     */
    public function exists(string $key, string $type = 'L1_cache'): bool;

    // ========================================================================
    // SORTED SET OPERATIONS (for chat messages, overlay events)
    // ========================================================================

    /**
     * Add member to sorted set with score
     *
     * @param string $key Redis key
     * @param float $score Sort score (typically timestamp)
     * @param mixed $value Member value
     * @param string $type Connection pool type
     * @return int|false Number of elements added, or false on error
     */
    public function zadd(string $key, float $score, mixed $value, string $type = 'overlay'): int|false;

    /**
     * Get range of members from sorted set by index
     *
     * @param string $key Redis key
     * @param int $start Start index (0-based, negative from end)
     * @param int $stop Stop index (-1 for all)
     * @param string $type Connection pool type
     * @param bool $withScores Include scores in result
     * @return array Array of members (optionally with scores)
     */
    public function zrange(string $key, int $start, int $stop, string $type = 'overlay', bool $withScores = false): array;

    /**
     * Get range of members by score
     *
     * @param string $key Redis key
     * @param mixed $min Minimum score ('-inf' for no limit)
     * @param mixed $max Maximum score ('+inf' for no limit)
     * @param string $type Connection pool type
     * @return array Array of members
     */
    public function zrangebyscore(string $key, mixed $min, mixed $max, string $type = 'overlay'): array;

    /**
     * Remove member from sorted set
     *
     * @param string $key Redis key
     * @param mixed $member Member to remove
     * @param string $type Connection pool type
     * @return int|false Number of members removed, or false on error
     */
    public function zrem(string $key, mixed $member, string $type = 'overlay'): int|false;

    /**
     * Remove members by rank (index) range
     * Useful for keeping only last N items: zremrangebyrank($key, 0, -101) keeps 100
     *
     * @param string $key Redis key
     * @param int $start Start rank
     * @param int $stop Stop rank
     * @param string $type Connection pool type
     * @return int|false Number of members removed, or false on error
     */
    public function zremrangebyrank(string $key, int $start, int $stop, string $type = 'overlay'): int|false;

    /**
     * Get number of members in sorted set
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return int Number of members
     */
    public function zcard(string $key, string $type = 'overlay'): int;

    // ========================================================================
    // SET OPERATIONS (for room membership, online users)
    // ========================================================================

    /**
     * Add one or more members to a set
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @param mixed ...$members Members to add
     * @return int|false Number of elements added, or false on error
     */
    public function sadd(string $key, string $type, mixed ...$members): int|false;

    /**
     * Remove one or more members from a set
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @param mixed ...$members Members to remove
     * @return int|false Number of elements removed, or false on error
     */
    public function srem(string $key, string $type, mixed ...$members): int|false;

    /**
     * Get all members of a set
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return array Array of members
     */
    public function smembers(string $key, string $type = 'L1_cache'): array;

    /**
     * Get the number of members in a set
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return int Number of members
     */
    public function scard(string $key, string $type = 'L1_cache'): int;

    /**
     * Check if a member exists in a set (ENTERPRISE V10.4: DND notifications)
     *
     * @param string $key Redis key
     * @param string $member Member to check
     * @param string $type Connection pool type
     * @return bool True if member exists in set
     */
    public function sismember(string $key, string $member, string $type = 'L1_cache'): bool;

    // ========================================================================
    // PUB/SUB OPERATIONS
    // ========================================================================

    /**
     * Publish a message to a channel
     *
     * @param string $channel Channel name
     * @param string $message Message content (typically JSON)
     * @return int|false Number of subscribers that received the message
     */
    public function publish(string $channel, string $message): int|false;

    // ========================================================================
    // ITERATION
    // ========================================================================

    /**
     * Scan for keys matching a pattern (safer than KEYS in production)
     *
     * @param string $pattern Glob-style pattern (e.g., "chat:typing:*")
     * @param string $type Connection pool type
     * @param int $count Hint for number of keys per iteration
     * @return array Array of matching keys
     */
    public function scan(string $pattern, string $type = 'L1_cache', int $count = 100): array;

    // ========================================================================
    // HASH OPERATIONS (ENTERPRISE V10.56: Activity tracking)
    // ========================================================================

    /**
     * Set a hash field value
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @param string $field Hash field name
     * @param string $value Field value
     * @return int|false 1 if new field, 0 if updated, false on error
     */
    public function hset(string $key, string $type, string $field, string $value): int|false;

    /**
     * Get a hash field value
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @param string $field Hash field name
     * @return string|false Field value or false if not found
     */
    public function hget(string $key, string $type, string $field): string|false;

    /**
     * Get all hash fields and values
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @return array Associative array of field => value
     */
    public function hgetall(string $key, string $type = 'L1_cache'): array;

    /**
     * Delete one or more hash fields
     *
     * @param string $key Redis key
     * @param string $type Connection pool type
     * @param string ...$fields Fields to delete
     * @return int|false Number of fields deleted, or false on error
     */
    public function hdel(string $key, string $type, string ...$fields): int|false;

    // ========================================================================
    // HEALTH CHECK
    // ========================================================================

    /**
     * Ping Redis to check connection health
     *
     * @param string $type Connection pool type
     * @return bool True if Redis responds
     */
    public function ping(string $type = 'L1_cache'): bool;
}
