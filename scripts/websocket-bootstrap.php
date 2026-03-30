<?php
/**
 * WebSocket Bootstrap - Minimal bootstrap for WebSocket server
 *
 * RATIONALE: The main app/bootstrap.php initializes the full enterprise stack
 * (database, sessions, cache, etc.) which is NOT needed for WebSocket server.
 * This minimal bootstrap only loads what's strictly necessary for WebSocket.
 *
 * LOADED:
 * - Composer autoloader
 * - Environment variables (.env)
 * - Logger service (for WebSocket logging)
 * - JWTService (for token verification)
 * - Redis connection (for PubSub only, no full Enterprise stack)
 *
 * NOT LOADED:
 * - Database connection (not needed)
 * - Session management (not needed)
 * - Multi-level cache (not needed)
 * - HTTP routes (not needed)
 * - Middleware (not needed)
 */

// Define APP_ROOT
define('APP_ROOT', dirname(__DIR__));

// Load Composer autoloader
require_once APP_ROOT . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(APP_ROOT);
$dotenv->load();

// Set timezone
date_default_timezone_set('Europe/Rome');

// Define env() helper (REQUIRED for config/app.php)
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $default;
    }
}

// Load ONLY logging configuration from config/app.php
// The Logger service needs this configuration to work properly
$appConfig = require APP_ROOT . '/config/app.php';
$GLOBALS['app_config'] = $appConfig; // Logger uses global $app_config

// Initialize Logger service (REQUIRED before using Logger::websocket())
// This creates all Monolog loggers including 'websocket' logger
use Need2Talk\Services\Logger;
Logger::init();

// ============================================================================
// ENTERPRISE V9.0: Swoole-Safe Redis Connection Pool
// ============================================================================
// Swoole coroutines require one Redis connection per coroutine.
// This pool creates per-coroutine connections using Swoole\Coroutine::getCid().

use Swoole\Coroutine;

// ============================================================================
// ENTERPRISE V10.11: Connection Pool using Swoole\Coroutine\Channel
// ============================================================================
//
// CRITICAL FIX (2025-12-03):
// Previous approach of sharing ONE connection per worker caused:
// "Socket has already been bound to another coroutine" error
//
// Swoole does NOT allow sharing Redis sockets between coroutines!
// When coroutine A starts a Redis operation and yields, coroutine B
// cannot use the same socket - Swoole throws an error.
//
// CORRECT SOLUTION: Connection Pool with Channel
// - Pre-create N connections at workerStart (outside coroutines)
// - Store them in Swoole\Coroutine\Channel (coroutine-safe queue)
// - Each coroutine BORROWS a connection: $conn = $channel->pop()
// - After use, RETURNS it: $channel->push($conn)
// - If pool is empty, coroutine waits (or times out)
//
// This is the Netflix/Twitter pattern for 100k+ concurrent connections.
// ============================================================================

use Swoole\Coroutine\Channel;

// Connection pools: keyed by DB number, value is Swoole\Coroutine\Channel
$GLOBALS['ws_redis_channels'] = [];

// Legacy pool for fallback (task workers, non-coroutine context)
$GLOBALS['ws_redis_pool'] = [];

// Track if we're in Swoole context
define('IN_SWOOLE_CONTEXT', extension_loaded('swoole'));

// Pool size per database (tunable based on load)
define('REDIS_POOL_SIZE_PER_DB', 10);

/**
 * Initialize Redis connection pool for a database
 * Called from workerStart callback (outside coroutines)
 *
 * @param int $workerId Worker process ID
 * @param int $db Database number
 * @param int $poolSize Number of connections to pre-create
 */
function swoole_redis_init_pool(int $workerId, int $db, int $poolSize = REDIS_POOL_SIZE_PER_DB): void
{
    $host = $_ENV['REDIS_HOST'] ?? 'redis';
    $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
    $password = $_ENV['REDIS_PASSWORD'] ?? null;

    // Create channel (coroutine-safe queue)
    $channel = new Channel($poolSize);

    for ($i = 0; $i < $poolSize; $i++) {
        try {
            $redis = new Redis();
            $redis->connect($host, $port, 2.0);

            if ($password) {
                $redis->auth($password);
            }

            $redis->select($db);

            // Push connection to pool
            $channel->push($redis);

        } catch (\Throwable $e) {
            error_log("[Worker #{$workerId}] Failed to create Redis pool connection for DB {$db}: " . $e->getMessage());
        }
    }

    // Store channel in global
    $GLOBALS['ws_redis_channels'][$db] = $channel;

    $created = $channel->length();
    if (function_exists('ws_debug')) {
        ws_debug("Worker #{$workerId} created Redis pool for DB {$db}", ['size' => $created]);
    }
}

/**
 * Get Redis connection from pool (Swoole coroutine-safe)
 *
 * ENTERPRISE V10.11: Uses Channel-based connection pool
 * - Borrows connection from pool (blocks if empty, with timeout)
 * - Caller MUST return connection using swoole_redis_release()
 *
 * @param int $db Redis database number (default: 6 for chat)
 * @param float $timeout Seconds to wait for connection (default: 1.0)
 * @return Redis|null Connection or null if pool empty/unavailable
 */
function swoole_redis(int $db = 6, float $timeout = 1.0): ?Redis
{
    // Check if pool exists for this DB
    $channel = $GLOBALS['ws_redis_channels'][$db] ?? null;

    if ($channel instanceof Channel) {
        // Pop connection from pool (blocks if empty, returns false on timeout)
        $redis = $channel->pop($timeout);

        if ($redis instanceof Redis) {
            // Verify connection is still alive
            try {
                $pong = $redis->ping();
                if ($pong === true || $pong === '+PONG') {
                    return $redis;
                }
            } catch (\Throwable $e) {
                // Connection dead, don't return it to pool
                // Pool will be short one connection until worker restart
                error_log("[Swoole-Redis] Dead connection in pool DB {$db}, discarding");
            }

            // Connection was bad, try to get another
            return swoole_redis($db, $timeout);
        }

        // Pool exhausted or timeout
        error_log("[Swoole-Redis] Pool exhausted for DB {$db}, timeout after {$timeout}s");
        return null;
    }

    // No pool for this DB - fallback for task workers or before init
    $cid = IN_SWOOLE_CONTEXT && class_exists('Swoole\Coroutine')
        ? Coroutine::getCid()
        : -1;

    // If in coroutine but no pool, we have a problem
    if ($cid > 0) {
        error_log("[Swoole-Redis] No pool for DB {$db} in coroutine context (cid={$cid})");
        return null;
    }

    // Outside coroutine - create direct connection (task workers, etc.)
    $poolKey = "fallback_db_{$db}";

    if (isset($GLOBALS['ws_redis_pool'][$poolKey])) {
        try {
            $redis = $GLOBALS['ws_redis_pool'][$poolKey];
            if ($redis->ping()) {
                return $redis;
            }
        } catch (\Throwable $e) {
            unset($GLOBALS['ws_redis_pool'][$poolKey]);
        }
    }

    // Create new fallback connection
    try {
        $redis = new Redis();
        $host = $_ENV['REDIS_HOST'] ?? 'redis';
        $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
        $password = $_ENV['REDIS_PASSWORD'] ?? null;

        $redis->connect($host, $port, 2.0);

        if ($password) {
            $redis->auth($password);
        }

        $redis->select($db);
        $GLOBALS['ws_redis_pool'][$poolKey] = $redis;

        return $redis;

    } catch (\Throwable $e) {
        error_log("[Swoole-Redis] Fallback connection failed for DB {$db}: " . $e->getMessage());
        return null;
    }
}

/**
 * Return Redis connection to pool
 * MUST be called after each swoole_redis() to prevent pool exhaustion
 *
 * @param Redis $redis Connection to return
 * @param int $db Database number
 */
function swoole_redis_release(Redis $redis, int $db = 6): void
{
    $channel = $GLOBALS['ws_redis_channels'][$db] ?? null;

    if ($channel instanceof Channel && !$channel->isFull()) {
        $channel->push($redis);
    }
    // If channel is full or doesn't exist, connection is discarded
}

/**
 * Clean up Redis connections for ended coroutine
 * Call this in Swoole's onClose or periodically
 */
function swoole_redis_cleanup(): void
{
    // Remove connections for coroutines that no longer exist
    foreach (array_keys($GLOBALS['ws_redis_pool']) as $key) {
        if (preg_match('/^cid_(\d+)_/', $key, $matches)) {
            $cid = (int)$matches[1];
            // Check if coroutine exists (getCid returns -1 or current, check via exists)
            if ($cid > 0 && IN_SWOOLE_CONTEXT && !Coroutine::exists($cid)) {
                $redis = $GLOBALS['ws_redis_pool'][$key];
                try {
                    $redis->close();
                } catch (Exception $e) {
                    // Ignore close errors
                }
                unset($GLOBALS['ws_redis_pool'][$key]);
            }
        }
    }
}

/**
 * Get Redis connection for WebSocket context (legacy compatibility)
 *
 * @deprecated Use swoole_redis() directly with try/finally and swoole_redis_release()
 * @return Redis|null
 */
function ws_get_redis(): ?Redis
{
    return swoole_redis(1); // DB 1 for sessions/L1 cache
}

/**
 * Get user data from Redis cache (Swoole-safe)
 * Falls back to null if user not cached (JWT should contain nickname anyway)
 *
 * ENTERPRISE V10.71 (2025-12-07): Fixed Redis pool leak
 * - Now uses try/finally to ALWAYS release connection back to pool
 * - Previous version never released, causing pool exhaustion with 2 users!
 *
 * @param string $uuid User UUID
 * @return array|null User data or null
 */
function ws_get_user(string $uuid): ?array
{
    $redis = swoole_redis(1); // DB 1 for sessions/L1 cache
    if (!$redis) {
        return null;
    }

    try {
        // Try to get user from Redis cache
        // PHP-FPM stores user data with key: need2talk:user:{uuid}
        $cacheKey = "need2talk:user:{$uuid}";
        $cached = $redis->get($cacheKey);

        if ($cached) {
            $userData = json_decode($cached, true);
            if ($userData && isset($userData['nickname'])) {
                return $userData;
            }
        }

        // User not in cache - return null (caller uses 'Anonymous' fallback)
        return null;

    } catch (RedisException $e) {
        error_log("[WS-Redis] Query failed: " . $e->getMessage());
        return null;
    } finally {
        // ENTERPRISE V10.71: CRITICAL - Always release connection to pool
        swoole_redis_release($redis, 1);
    }
}

/**
 * ENTERPRISE V10.66 (2025-12-07): Get user's friends UUIDs for presence fan-out
 *
 * MULTI-LAYER STRATEGY:
 * 1. Check Redis cache first (need2talk:friends:{uuid})
 * 2. On cache MISS, query PostgreSQL directly via SwooleDatabaseAdapter
 * 3. Populate cache for future lookups (24h TTL)
 *
 * WHY THIS FIX IS CRITICAL:
 * - Friends cache is populated only at LOGIN with 24h TTL
 * - If session lasts >24h, cache expires
 * - Without fallback, presence fan-out silently fails (no friends notified)
 * - This was causing "online status doesn't update" bug
 *
 * ENTERPRISE V10.71 (2025-12-07): Fixed Redis pool leak
 * - Refactored to use swoole_redis() directly with proper try/finally release
 * - Previous version used ws_get_redis() without release, causing pool exhaustion
 *
 * @param string $userUuid User's UUID
 * @return array Array of friend UUIDs (empty if no friends)
 */
function ws_get_friends(string $userUuid): array
{
    $redis = null;
    $friendUuids = [];

    try {
        // 1. Try Redis cache first (fast path)
        $redis = swoole_redis(1); // DB 1 for sessions/L1 cache
        if ($redis) {
            $cacheKey = "need2talk:friends:{$userUuid}";
            $cached = $redis->get($cacheKey);

            if ($cached) {
                $friends = json_decode($cached, true);
                if (is_array($friends) && !empty($friends)) {
                    if (function_exists('ws_debug')) {
                        ws_debug('ws_get_friends: Cache HIT', [
                            'uuid' => substr($userUuid, 0, 13) . '...',
                            'friends_count' => count($friends),
                        ]);
                    }
                    return $friends; // finally will release Redis
                }
            }
        }

        // 2. Cache MISS - Query PostgreSQL directly
        if (function_exists('ws_debug')) {
            ws_debug('ws_get_friends: Cache MISS, querying DB', [
                'uuid' => substr($userUuid, 0, 13) . '...',
            ]);
        }

        // Get database adapter from globals (initialized in workerStart)
        $dbAdapter = $GLOBALS['swoole_database_adapter'] ?? null;
        if (!$dbAdapter) {
            error_log("[WS] ws_get_friends: Database adapter not available");
            return []; // finally will release Redis
        }

        // Query friendships table for accepted friends
        // ENTERPRISE QUERY: Gets UUIDs of all accepted friends for the user
        $sql = "
            SELECT
                CASE
                    WHEN f.user_id = u.id THEN friend_user.uuid
                    ELSE requesting_user.uuid
                END as friend_uuid
            FROM friendships f
            INNER JOIN users u ON u.uuid = :user_uuid
            INNER JOIN users friend_user ON friend_user.id = f.friend_id
            INNER JOIN users requesting_user ON requesting_user.id = f.user_id
            WHERE f.status = 'accepted'
              AND (f.user_id = u.id OR f.friend_id = u.id)
        ";

        $results = $dbAdapter->query($sql, ['user_uuid' => $userUuid]);

        // Extract friend UUIDs
        foreach ($results as $row) {
            if (!empty($row['friend_uuid'])) {
                $friendUuids[] = $row['friend_uuid'];
            }
        }

        if (function_exists('ws_debug')) {
            ws_debug('ws_get_friends: DB query result', [
                'uuid' => substr($userUuid, 0, 13) . '...',
                'friends_found' => count($friendUuids),
            ]);
        }

        // 3. Populate Redis cache for future lookups
        if (!empty($friendUuids) && $redis) {
            $cacheKey = "need2talk:friends:{$userUuid}";
            // TTL: 24 hours (matches session/JWT lifetime)
            $redis->setex($cacheKey, 86400, json_encode($friendUuids));

            if (function_exists('ws_debug')) {
                ws_debug('ws_get_friends: Cache populated', [
                    'uuid' => substr($userUuid, 0, 13) . '...',
                    'friends_cached' => count($friendUuids),
                ]);
            }
        }

        return $friendUuids;

    } catch (\RedisException $e) {
        error_log("[WS] ws_get_friends: Redis error: " . $e->getMessage());
        return $friendUuids; // Return what we have (empty or from DB)
    } catch (\PDOException $e) {
        error_log("[WS] ws_get_friends: DB query failed: " . $e->getMessage());
        return [];
    } catch (\Throwable $e) {
        error_log("[WS] ws_get_friends: Unexpected error: " . $e->getMessage());
        return [];
    } finally {
        // ENTERPRISE V10.71: CRITICAL - Always release Redis connection to pool
        if ($redis) {
            swoole_redis_release($redis, 1);
        }
    }
}

// ============================================================================
// ENTERPRISE DI: Register Adapters for Swoole WebSocket Context
// ============================================================================
// This section registers Swoole-safe adapters in the ServiceContainer.
// Swoole uses per-coroutine Redis connections (no shared sockets).
// Database adapter is NOT registered - WebSocket doesn't access DB directly.
// ============================================================================

use Need2Talk\Core\ServiceContainer;
use Need2Talk\Adapters\Redis\SwooleCoroutineRedisAdapter;
use Need2Talk\Adapters\Publisher\SwoolePublisherAdapter;
use Need2Talk\Adapters\Database\SwooleDatabaseAdapter;

// Register Swoole-safe Redis adapter (per-coroutine connections)
// This is the CRITICAL adapter that fixes the WebSocket deadlock
$swooleRedisAdapter = new SwooleCoroutineRedisAdapter();
ServiceContainer::register('redis', $swooleRedisAdapter);

// Register lightweight publisher for Swoole context
// Uses the Redis adapter for PubSub messaging
ServiceContainer::register('publisher', new SwoolePublisherAdapter($swooleRedisAdapter));

// ENTERPRISE V9.1 (2025-12-06): Register Swoole-safe Database adapter
// ChatRoomService.joinRoom() needs DB access to lookup userId from userUuid
// SwooleDatabaseAdapter uses PDO connection pool with Swoole\Coroutine\Channel
// NOTE: initPool() MUST be called in workerStart callback to create connections
$swooleDatabaseAdapter = new SwooleDatabaseAdapter(poolSize: 10, borrowTimeout: 1.0);
ServiceContainer::register('database', $swooleDatabaseAdapter);

// Store adapter globally for workerStart initialization
$GLOBALS['swoole_database_adapter'] = $swooleDatabaseAdapter;

echo "WebSocket Bootstrap: Minimal bootstrap loaded successfully\n";
echo "WebSocket Bootstrap: ServiceContainer initialized with Swoole adapters\n";
flush();
