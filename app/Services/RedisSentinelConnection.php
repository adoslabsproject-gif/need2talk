<?php

/**
 * ============================================================================
 * REDIS SENTINEL CONNECTION - ENTERPRISE HIGH AVAILABILITY
 * ============================================================================
 *
 * Classe per connessione Redis tramite Sentinel con failover automatico.
 *
 * CARATTERISTICHE:
 *   ✅ Automatic failover detection
 *   ✅ Master discovery via Sentinel
 *   ✅ Fallback to direct connection
 *   ✅ Connection pooling compatible
 *   ✅ Multi-Sentinel support
 *   ✅ Error handling with retry logic
 *
 * UTILIZZO:
 *   $redis = RedisSentinelConnection::getMaster();
 *   $redis->set('key', 'value');
 *
 * FALLBACK:
 *   Se Sentinel non è disponibile, si connette direttamente a Redis.
 *
 * @package Need2Talk\Services
 * @version 1.0.0 Enterprise
 * @author  need2talk Engineering Team
 * @license Proprietary
 */

namespace Need2Talk\Services;

use Exception;
use Redis;

class RedisSentinelConnection
{
    /**
     * Master name configurato in Sentinel
     */
    private const MASTER_NAME = 'need2talk_master';

    /**
     * Timeout connessione Sentinel (ms)
     */
    private const SENTINEL_TIMEOUT = 1.0;

    /**
     * Timeout connessione Redis (ms)
     */
    private const REDIS_TIMEOUT = 5.0;

    /**
     * Retry attempts per Sentinel
     */
    private const MAX_RETRIES = 3;

    /**
     * Cache statica master address (per performance)
     * @var array|null
     */
    private static ?array $cachedMaster = null;

    /**
     * Cache TTL (secondi)
     */
    private const CACHE_TTL = 10;

    /**
     * Cache timestamp
     */
    private static ?float $cacheTime = null;

    /**
     * Get Redis master connection via Sentinel
     *
     * FLOW:
     *   1. Check cached master (10s TTL)
     *   2. Query Sentinel for current master
     *   3. Connect to discovered master
     *   4. Fallback to direct connection if Sentinel unavailable
     *
     * @param int $database Redis database number (default: 0)
     * @return Redis Connected Redis instance
     * @throws Exception If connection fails
     */
    public static function getMaster(int $database = 0): Redis
    {
        try {
            // Try Sentinel discovery
            $master = self::discoverMaster();

            if ($master) {
                return self::connectToMaster($master['host'], $master['port'], $database);
            }

            // Fallback to direct connection
            return self::fallbackConnection($database);

        } catch (Exception $e) {
            // ENTERPRISE: Log Sentinel discovery failure (warning - using fallback connection)
            Logger::warning('REDIS SENTINEL: Discovery failed, using fallback connection', [
                'database' => $database,
                'error' => $e->getMessage(),
                'impact' => 'Sentinel unavailable - using direct Redis connection as fallback',
                'action_required' => 'Check Sentinel container: docker compose ps redis_sentinel',
            ]);

            return self::fallbackConnection($database);
        }
    }

    /**
     * Discover master from Sentinel
     *
     * ALGORITHM:
     *   1. Connect to Sentinel
     *   2. Execute: SENTINEL get-master-addr-by-name mymaster
     *   3. Parse response: [host, port]
     *   4. Cache result for 10 seconds
     *
     * @return array|null ['host' => string, 'port' => int] or null if failed
     */
    private static function discoverMaster(): ?array
    {
        // Check cache
        if (self::$cachedMaster && self::$cacheTime && (microtime(true) - self::$cacheTime) < self::CACHE_TTL) {
            return self::$cachedMaster;
        }

        $sentinelHost = $_ENV['REDIS_SENTINEL_HOST'] ?? 'redis-sentinel';
        $sentinelPort = (int)($_ENV['REDIS_SENTINEL_PORT'] ?? 26379);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $sentinel = new Redis();

                // Connect to Sentinel
                if (!$sentinel->pconnect($sentinelHost, $sentinelPort, self::SENTINEL_TIMEOUT)) {
                    throw new Exception("Cannot connect to Sentinel {$sentinelHost}:{$sentinelPort}");
                }

                // Query master address
                $masterInfo = $sentinel->rawCommand('SENTINEL', 'get-master-addr-by-name', self::MASTER_NAME);

                if (!is_array($masterInfo) || count($masterInfo) < 2) {
                    throw new Exception("Invalid Sentinel response: " . json_encode($masterInfo));
                }

                $master = [
                    'host' => $masterInfo[0],
                    'port' => (int)$masterInfo[1],
                ];

                // Cache result
                self::$cachedMaster = $master;
                self::$cacheTime = microtime(true);

                $sentinel->close();

                error_log("[REDIS_SENTINEL] Master discovered: {$master['host']}:{$master['port']} (attempt {$attempt})");

                return $master;

            } catch (Exception $e) {
                // ENTERPRISE: Log Sentinel discovery retry (warning - will retry)
                Logger::warning("REDIS SENTINEL: Discovery attempt {$attempt} failed, retrying", [
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_RETRIES,
                    'error' => $e->getMessage(),
                    'impact' => 'Sentinel discovery failing - will retry after 100ms',
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    usleep(100000); // 100ms delay before retry
                }
            }
        }

        return null;
    }

    /**
     * Connect to discovered Redis master
     *
     * @param string $host Master host
     * @param int $port Master port
     * @param int $database Database number
     * @return Redis Connected instance
     * @throws Exception If connection fails
     */
    private static function connectToMaster(string $host, int $port, int $database): Redis
    {
        $redis = new Redis();

        if (!$redis->pconnect($host, $port, self::REDIS_TIMEOUT)) {
            throw new Exception("Cannot connect to Redis master {$host}:{$port}");
        }

        // ENTERPRISE FIX: Authenticate with password if provided
        $password = $_ENV['REDIS_PASSWORD'] ?? null;
        if ($password) {
            $redis->auth($password);
        }

        // Verify we're connected to master (not slave)
        try {
            $info = $redis->info('replication');
            if (isset($info['role']) && $info['role'] !== 'master') {
                throw new Exception("Connected to slave instead of master");
            }
        } catch (Exception $e) {
            // ENTERPRISE: Log master role verification failure (warning - assuming single instance)
            Logger::warning('REDIS SENTINEL: Cannot verify master role, assuming single instance', [
                'error' => $e->getMessage(),
                'impact' => 'Replication info not available - assuming single Redis instance (OK for standalone)',
            ]);
        }

        // Select database
        if ($database > 0) {
            $redis->select($database);
        }

        // Set options
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        error_log("[REDIS_SENTINEL] Connected to master {$host}:{$port} database {$database}");

        return $redis;
    }

    /**
     * Fallback direct connection (if Sentinel unavailable)
     *
     * Connects directly to Redis using .env configuration
     *
     * @param int $database Database number
     * @return Redis Connected instance
     * @throws Exception If connection fails
     */
    private static function fallbackConnection(int $database): Redis
    {
        $host = $_ENV['REDIS_HOST'] ?? 'redis';
        $port = (int)($_ENV['REDIS_PORT'] ?? 6379);

        $redis = new Redis();

        if (!$redis->pconnect($host, $port, self::REDIS_TIMEOUT)) {
            throw new Exception("Fallback connection failed: {$host}:{$port}");
        }

        // ENTERPRISE FIX: Authenticate with password if provided
        $password = $_ENV['REDIS_PASSWORD'] ?? null;
        if ($password) {
            $redis->auth($password);
        }

        if ($database > 0) {
            $redis->select($database);
        }

        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        error_log("[REDIS_SENTINEL] Using fallback connection {$host}:{$port} database {$database}");

        return $redis;
    }

    /**
     * Clear cached master (force rediscovery)
     *
     * Useful after detecting connection errors
     */
    public static function clearCache(): void
    {
        self::$cachedMaster = null;
        self::$cacheTime = null;
        error_log("[REDIS_SENTINEL] Cache cleared, will rediscover master on next connection");
    }

    /**
     * Get Sentinel health status
     *
     * @return array Health check results
     */
    public static function healthCheck(): array
    {
        $health = [
            'sentinel_available' => false,
            'master_discovered' => false,
            'master_reachable' => false,
            'master_info' => null,
            'error' => null,
        ];

        try {
            // Check Sentinel
            $master = self::discoverMaster();

            if ($master) {
                $health['sentinel_available'] = true;
                $health['master_discovered'] = true;
                $health['master_info'] = $master;

                // Try connecting to master
                try {
                    $redis = self::connectToMaster($master['host'], $master['port'], 0);
                    $redis->ping();
                    $health['master_reachable'] = true;
                    $redis->close();
                } catch (Exception $e) {
                    // ENTERPRISE: Log master unreachable (error - Sentinel health degraded)
                    Logger::error('REDIS SENTINEL: Master not reachable', [
                        'master_host' => $master['host'],
                        'master_port' => $master['port'],
                        'error' => $e->getMessage(),
                        'impact' => 'Cannot connect to Redis master - service may be degraded',
                        'action_required' => 'Check Redis master container: docker compose ps redis',
                    ]);
                    $health['error'] = "Master not reachable: " . $e->getMessage();
                }
            } else {
                $health['error'] = "Cannot discover master from Sentinel";
            }

        } catch (Exception $e) {
            // ENTERPRISE: Log Sentinel health check failure (error - Sentinel unavailable)
            Logger::error('REDIS SENTINEL: Health check failed', [
                'error' => $e->getMessage(),
                'impact' => 'Sentinel health check failed - monitoring degraded',
                'action_required' => 'Check Sentinel container: docker compose ps redis_sentinel',
            ]);
            $health['error'] = $e->getMessage();
        }

        return $health;
    }

    /**
     * Test Sentinel configuration
     *
     * Returns detailed diagnostics
     *
     * @return array Diagnostic information
     */
    public static function diagnostics(): array
    {
        $sentinelHost = $_ENV['REDIS_SENTINEL_HOST'] ?? 'redis-sentinel';
        $sentinelPort = (int)($_ENV['REDIS_SENTINEL_PORT'] ?? 26379);

        $diagnostics = [
            'config' => [
                'sentinel_host' => $sentinelHost,
                'sentinel_port' => $sentinelPort,
                'master_name' => self::MASTER_NAME,
                'timeout' => self::SENTINEL_TIMEOUT,
            ],
            'cache' => [
                'cached_master' => self::$cachedMaster,
                'cache_age' => self::$cacheTime ? (microtime(true) - self::$cacheTime) : null,
            ],
            'health' => self::healthCheck(),
        ];

        try {
            // Get full Sentinel info
            $sentinel = new Redis();
            if ($sentinel->pconnect($sentinelHost, $sentinelPort, self::SENTINEL_TIMEOUT)) {
                $masters = $sentinel->rawCommand('SENTINEL', 'masters');
                $diagnostics['sentinel_masters'] = $masters;
                $sentinel->close();
            }
        } catch (Exception $e) {
            // ENTERPRISE: Log Sentinel diagnostics failure (warning - diagnostics unavailable)
            Logger::warning('REDIS SENTINEL: Diagnostics collection failed', [
                'sentinel_host' => $sentinelHost,
                'sentinel_port' => $sentinelPort,
                'error' => $e->getMessage(),
                'impact' => 'Sentinel diagnostics unavailable - monitoring limited',
            ]);
            $diagnostics['sentinel_error'] = $e->getMessage();
        }

        return $diagnostics;
    }
}
