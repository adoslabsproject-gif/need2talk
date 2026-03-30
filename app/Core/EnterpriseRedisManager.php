<?php

namespace Need2Talk\Core;

/**
 * Enterprise Redis Connection Manager
 *
 * Gestisce connessioni Redis L1 (Cache) e L3 (Logging) con:
 * - Circuit Breaker pattern per handling failures
 * - Connection pooling per 100k+ concurrent users
 * - Automatic fallback to database sessions
 * - Health monitoring e recovery automatico
 */
class EnterpriseRedisManager
{
    // Circuit Breaker Configuration - ENTERPRISE OPTIMIZED for faster recovery
    private const FAILURE_THRESHOLD = 5;     // Max failures before circuit opens
    private const TIMEOUT_DURATION = 15;     // Seconds circuit stays open (reduced from 30 for faster recovery)
    private const RETRY_TIMEOUT = 3;         // Seconds between retry attempts (reduced from 5)
    private const SUCCESS_THRESHOLD = 2;     // Successful calls to close circuit (reduced from 3)

    // Rate limiting for circuit breaker logging (prevent log spam under high load)
    private const LOG_RATE_LIMIT_SECONDS = 60;  // Log state changes max once per minute per type

    private static $lastLogTime = [];

    // Connection pools per tipo
    // DB Allocation: 0=L1_cache, 1=sessions, 2=async_email, 3=rate_limit, 4=logging, 5=overlay, 6=chat
    private const CONNECTION_POOLS = [
        'L1_cache' => ['db' => 0, 'max_connections' => 50],
        'L3_logging' => ['db' => 4, 'max_connections' => 20],
        'L3_async_email' => ['db' => 2, 'max_connections' => 40],
        'sessions' => ['db' => 1, 'max_connections' => 100],
        'rate_limit' => ['db' => 3, 'max_connections' => 30],
        // ENTERPRISE GALAXY V4 (2025-11-26): Overlay cache for write-behind reactions/views
        // Stores: overlay:{id}:reactions, overlay:{id}:views, personal:{userId}:rx:{postId}
        // Memory estimate: ~384MB → allocated 512MB for safety
        'overlay' => ['db' => 5, 'max_connections' => 60],
        // ENTERPRISE GALAXY CHAT (2025-12-02): Real-time chat ephemeral storage
        // Stores: chat:room:emotion:{id}:messages, chat:room:emotion:{id}:online,
        //         chat:typing:{room_id}:{uuid}, chat:dm:typing:{conv_id}:{uuid},
        //         chat:presence:{uuid}, chat:room:private:{id}:*
        // Memory estimate: ~200MB for 10k concurrent users
        // Max 100 messages per room (ZREMRANGEBYRANK), 1h TTL for ephemeral
        'chat' => ['db' => 6, 'max_connections' => 80],
        // ENTERPRISE GALAXY V11.6 (2025-12-11): Async Notification Queue
        // Stores: notification_queue:pending (ZSET), notification_queue:processing (HASH),
        //         notification_queue:failed (ZSET), notification_queue:workers (HASH),
        //         notification_queue:metrics (HASH), notification_queue:dedup:{user_id} (SET)
        // Memory estimate: ~100MB for 100k notifications/day
        // Enables batching, deduplication, and 1-4 scalable workers
        'notification_queue' => ['db' => 7, 'max_connections' => 40],
    ];

    private static $instance = null;

    private static $connections = [];

    private static $circuitBreakers = [];

    private static $healthStatus = [];

    public function __destruct()
    {
        $this->closeAllConnections();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::initializeCircuitBreakers();
        }

        return self::$instance;
    }

    /**
     * Get Redis connection with Circuit Breaker protection
     *
     * ENTERPRISE V9.0 (2025-12-02): Swoole Coroutine Support
     * In Swoole context, each coroutine needs its own Redis connection.
     * Uses swoole_redis() helper which creates per-coroutine connections.
     */
    public function getConnection(string $type = 'L1_cache'): ?\Redis
    {
        // SWOOLE CONTEXT: Use per-coroutine connections to avoid "Socket already bound" errors
        // Swoole coroutines run concurrently and cannot share Redis connections
        if (defined('IN_SWOOLE_CONTEXT') && IN_SWOOLE_CONTEXT && function_exists('swoole_redis')) {
            $dbConfig = self::CONNECTION_POOLS[$type] ?? self::CONNECTION_POOLS['L1_cache'];
            return swoole_redis($dbConfig['db']);
        }

        // Check circuit breaker state
        if (!$this->isCircuitClosed($type)) {
            // REDUCED LOGGING: Verbose "Circuit breaker OPEN" log disabled (too frequent)
            // Only critical state changes (OPENED/CLOSED) are logged below
            return null; // Trigger fallback mechanism
        }

        try {
            // Try to get pooled connection first
            if (isset(self::$connections[$type]) && $this->isConnectionHealthy($type)) {
                return self::$connections[$type];
            }

            // Create new connection
            $redis = new \Redis();

            // ENTERPRISE PERFORMANCE FIX: Use persistent connections to prevent socket exhaustion
            // With 200 concurrent users, connect() creates 200+ new TCP sockets → "Address not available"
            // pconnect() reuses same TCP socket across requests → zero socket overhead
            // persistent_id varies by type (L1_cache, session, etc.) to isolate connection pools
            $connected = $redis->pconnect(
                env('REDIS_HOST', 'redis'),
                (int) env('REDIS_PORT', 6379),
                5.0, // 5 second timeout
                $type // Persistent connection ID (unique per connection type)
            );

            if (!$connected) {
                throw new \Exception('Redis connection failed to ' . env('REDIS_HOST') . ':' . env('REDIS_PORT'));
            }

            // ENTERPRISE FIX: Authenticate with password if provided
            $password = env('REDIS_PASSWORD');
            if ($password) {
                $redis->auth($password);
            }


            // Select appropriate database
            $dbConfig = self::CONNECTION_POOLS[$type] ?? self::CONNECTION_POOLS['L1_cache'];
            $redis->select($dbConfig['db']);

            // Test connection with ping
            $redis->ping();

            // Store in pool
            self::$connections[$type] = $redis;
            self::$healthStatus[$type] = [
                'status' => 'healthy',
                'last_check' => time(),
                'consecutive_failures' => 0,
            ];

            // Record success in circuit breaker
            $this->recordSuccess($type);

            return $redis;

        } catch (\Exception $e) {
            // ENTERPRISE FIX: NO logging here - too verbose under high load
            // Circuit breaker will log state transitions (OPENED/CLOSED) which is enough
            // Individual connection failures are expected when Redis is down - no need to spam logs

            // Record failure in circuit breaker (will log only on state transition)
            $this->recordFailure($type);

            // Clean up failed connection
            if (isset(self::$connections[$type])) {
                unset(self::$connections[$type]);
            }

            return null;
        }
    }

    /**
     * Get circuit breaker status for monitoring
     */
    public function getCircuitBreakerStatus(): array
    {
        $status = [];

        foreach (self::$circuitBreakers as $type => $breaker) {
            $status[$type] = [
                'state' => $breaker['state'] ?? 'closed',
                'failure_count' => $breaker['failure_count'] ?? 0,
                'health' => self::$healthStatus[$type]['status'] ?? 'unknown',
            ];
        }

        return $status;
    }

    /**
     * Force reset circuit breaker (for maintenance)
     */
    public function resetCircuitBreaker(string $type): void
    {
        if (isset(self::$circuitBreakers[$type])) {
            self::$circuitBreakers[$type] = [
                'state' => 'closed',
                'failure_count' => 0,
                'success_count' => 0,
                'opened_at' => 0,
            ];
            error_log("[ENTERPRISE-REDIS] Circuit breaker for $type manually RESET");
        }
    }

    /**
     * Close all connections (cleanup)
     */
    public function closeAllConnections(): void
    {
        foreach (self::$connections as $type => $redis) {
            try {
                $redis->close();
            } catch (\Exception $e) {
                // ENTERPRISE: Log Redis connection close error (warning - cleanup issue)
                Logger::warning("REDIS: Failed to close connection for type '{$type}'", [
                    'type' => $type,
                    'error' => $e->getMessage(),
                    'impact' => 'Connection may not be properly closed - potential resource leak',
                ]);
            }
        }
        self::$connections = [];
    }

    /**
     * Verifica se Redis è disponibile
     */
    public function isAvailable(string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);

        return $redis !== null;
    }

    /**
     * Get value from Redis with fallback
     */
    public function get(string $key, string $type = 'L1_cache'): mixed
    {
        $redis = $this->getConnection($type);

        if ($redis === null) {
            return false;
        }

        try {
            return $redis->get($key);
        } catch (\Exception $e) {
            // ENTERPRISE: Log Redis GET operation failure (warning - cache miss)
            Logger::warning('REDIS: GET operation failed', [
                'key' => $key,
                'type' => $type,
                'error' => $e->getMessage(),
                'impact' => 'Cache miss - data will be fetched from source',
            ]);
            $this->recordFailure($type);

            return false;
        }
    }

    /**
     * Set value in Redis with expiration
     */
    public function setex(string $key, int $ttl, $value, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);

        if ($redis === null) {
            return false;
        }

        try {
            return $redis->setex($key, $ttl, $value);
        } catch (\Exception $e) {
            // ENTERPRISE: Log Redis SETEX operation failure (warning - cache write failed)
            Logger::warning('REDIS: SETEX operation failed', [
                'key' => $key,
                'ttl' => $ttl,
                'type' => $type,
                'error' => $e->getMessage(),
                'impact' => 'Cache write failed - performance degraded on next request',
            ]);
            $this->recordFailure($type);

            return false;
        }
    }

    /**
     * Set value in Redis without expiration
     */
    public function set(string $key, $value, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);

        if ($redis === null) {
            return false;
        }

        try {
            return $redis->set($key, $value);
        } catch (\Exception $e) {
            // ENTERPRISE: Log Redis SET operation failure (warning - cache write failed)
            Logger::warning('REDIS: SET operation failed', [
                'key' => $key,
                'type' => $type,
                'error' => $e->getMessage(),
                'impact' => 'Cache write failed - performance degraded on next request',
            ]);
            $this->recordFailure($type);

            return false;
        }
    }

    /**
     * Delete key from Redis
     */
    public function delete(string $key, string $type = 'L1_cache'): bool
    {
        $redis = $this->getConnection($type);

        if ($redis === null) {
            return false;
        }

        try {
            return $redis->del($key) > 0;
        } catch (\Exception $e) {
            // ENTERPRISE: Log Redis DEL operation failure (warning - cache invalidation failed)
            Logger::warning('REDIS: DEL operation failed', [
                'key' => $key,
                'type' => $type,
                'error' => $e->getMessage(),
                'impact' => 'Cache invalidation failed - stale data may persist',
            ]);
            $this->recordFailure($type);

            return false;
        }
    }

    /**
     * Ensure circuit breaker is initialized for a specific type
     */
    private function ensureCircuitBreakerInitialized(string $type): void
    {
        if (!isset(self::$circuitBreakers[$type])) {
            self::$circuitBreakers[$type] = [
                'state' => 'closed',
                'failure_count' => 0,
                'success_count' => 0,
                'opened_at' => 0,
            ];
        }
    }

    /**
     * Circuit Breaker: Check if circuit is closed (can attempt connection)
     */
    private function isCircuitClosed(string $type): bool
    {
        $breaker = self::$circuitBreakers[$type] ?? null;

        if (!$breaker) {
            return true;
        }

        $now = time();

        switch ($breaker['state']) {
            case 'closed':
                return true;

            case 'open':
                // Check if timeout period has passed
                if ($now >= $breaker['opened_at'] + self::TIMEOUT_DURATION) {
                    // Move to half-open state
                    self::$circuitBreakers[$type]['state'] = 'half_open';
                    self::$circuitBreakers[$type]['success_count'] = 0;
                    // REDUCED LOGGING: HALF-OPEN transition log disabled (verbose)
                    // error_log("[ENTERPRISE-REDIS] Circuit breaker for $type moved to HALF-OPEN");

                    return true;
                }

                return false;

            case 'half_open':
                return true;

            default:
                return true;
        }
    }

    /**
     * Record successful operation for circuit breaker
     */
    private function recordSuccess(string $type): void
    {
        $this->ensureCircuitBreakerInitialized($type);
        $breaker = &self::$circuitBreakers[$type];

        if ($breaker['state'] === 'half_open') {
            $breaker['success_count']++;

            if ($breaker['success_count'] >= self::SUCCESS_THRESHOLD) {
                // Close circuit - back to normal operation
                $breaker['state'] = 'closed';
                $breaker['failure_count'] = 0;

                // ENTERPRISE FIX: Rate-limited logging (max once per minute per type)
                $now = time();
                $logKey = $type . ':closed';
                if (!isset(self::$lastLogTime[$logKey]) || ($now - self::$lastLogTime[$logKey]) >= self::LOG_RATE_LIMIT_SECONDS) {
                    error_log("[ENTERPRISE-REDIS] Circuit breaker for $type CLOSED - normal operation restored");
                    self::$lastLogTime[$logKey] = $now;
                }
            }
        } elseif ($breaker['state'] === 'closed') {
            // Reset failure count on success
            $breaker['failure_count'] = 0;
        }
    }

    /**
     * Record failed operation for circuit breaker
     * ENTERPRISE FIX: Rate-limited logging to prevent log spam under high load
     */
    private function recordFailure(string $type): void
    {
        $this->ensureCircuitBreakerInitialized($type);
        $breaker = &self::$circuitBreakers[$type];

        // CRITICAL FIX: Don't count failures when circuit is already open
        // This prevents infinite counter growth and log spam (501, 502, 503...)
        if ($breaker['state'] === 'open') {
            return; // Circuit already open, no need to count more failures
        }

        $breaker['failure_count']++;

        if ($breaker['failure_count'] >= self::FAILURE_THRESHOLD) {
            // Open circuit - stop trying connections
            $breaker['state'] = 'open';
            $breaker['opened_at'] = time();

            // ENTERPRISE FIX: Rate-limited logging (max once per minute per type)
            // Log ONLY on initial transition to OPENED state (not every subsequent failure)
            $now = time();
            $logKey = $type . ':opened';
            if (!isset(self::$lastLogTime[$logKey]) || ($now - self::$lastLogTime[$logKey]) >= self::LOG_RATE_LIMIT_SECONDS) {
                error_log("[ENTERPRISE-REDIS] Circuit breaker for $type OPENED - too many failures (threshold: " . self::FAILURE_THRESHOLD . ")");
                self::$lastLogTime[$logKey] = $now;
            }
        }
    }

    /**
     * Initialize circuit breakers for all connection types
     */
    private static function initializeCircuitBreakers(): void
    {
        foreach (array_keys(self::CONNECTION_POOLS) as $type) {
            self::$circuitBreakers[$type] = [
                'state' => 'closed',
                'failure_count' => 0,
                'success_count' => 0,
                'opened_at' => 0,
            ];
        }
    }

    /**
     * Check if existing connection is still healthy
     */
    private function isConnectionHealthy(string $type): bool
    {
        if (!isset(self::$connections[$type])) {
            return false;
        }

        try {
            $redis = self::$connections[$type];
            $redis->ping();

            return true;
        } catch (\Exception $e) {
            // ENTERPRISE: Log Redis health check failure (error - connection unhealthy)
            Logger::error('REDIS: Health check failed', [
                'type' => $type,
                'error' => $e->getMessage(),
                'impact' => 'Redis connection unhealthy - removed from pool',
                'action_required' => 'Check Redis container: docker compose ps redis',
            ]);
            unset(self::$connections[$type]);

            return false;
        }
    }
}
