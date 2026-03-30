<?php

namespace Need2Talk\Core;

use Need2Talk\Services\Logger;
use Need2Talk\Traits\EnterpriseRedisSafety;
use Redis;

/**
 * Enterprise L1 Cache Implementation - Redis-Based Alternative to APCu
 *
 * Since APCu is not available in this MAMP PRO configuration,
 * this implements an enterprise-grade L1 cache using Redis with
 * ultra-fast performance characteristics similar to APCu.
 *
 * Performance characteristics:
 * - Memory access patterns optimized for L1-like behavior
 * - Connection pooling for minimal latency
 * - Serialization optimizations
 * - Namespace isolation for security
 */
class EnterpriseL1Cache
{
    use EnterpriseRedisSafety;

    private ?Redis $redis = null;

    private string $prefix = 'L1:';

    private int $defaultTtl = 7200; // 2 hours like APCu

    private bool $connected = false;

    // Enterprise performance settings
    private array $serializeOptions = [
        'igbinary' => false, // Will be auto-detected
        'compression' => false, // L1 cache prioritizes speed over space
    ];

    public function __construct()
    {
        $this->initializeConnection();
        $this->detectOptimalSerialization();
    }

    /**
     * Store value in L1 cache (APCu-compatible interface)
     */
    public function store(string $key, $value, ?int $ttl = null): bool
    {
        if (!$this->connected) {
            return false;
        }

        // ENTERPRISE: Normalize TTL before try block for consistent error logging
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            return $this->redis->setex($key, $ttl, $value);
        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Store operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
                'ttl' => $ttl,
                'operation' => 'store',
            ]);

            return false;
        }
    }

    /**
     * Fetch value from L1 cache (APCu-compatible interface)
     */
    public function fetch(string $key): mixed
    {
        if (!$this->connected) {
            return false;
        }

        try {
            return $this->redis->get($key);
        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Fetch operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
                'operation' => 'fetch',
            ]);

            return false;
        }
    }

    /**
     * Check if key exists in L1 cache (APCu-compatible interface)
     */
    public function exists(string $key): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            return $this->redis->exists($key) > 0;
        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Exists check failed', [
                'key' => $key,
                'error' => $e->getMessage(),
                'operation' => 'exists',
            ]);

            return false;
        }
    }

    /**
     * Delete key from L1 cache (APCu-compatible interface)
     */
    public function delete(string $key): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            return $this->redis->del($key) > 0;
        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Delete operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
                'operation' => 'delete',
            ]);

            return false;
        }
    }

    /**
     * Clear entire L1 cache (APCu-compatible interface)
     */
    public function clear(): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            // Only clear L1 prefixed keys
            $iterator = null;
            $deleted = 0;

            while (($keys = $this->redis->scan($iterator, $this->prefix . '*', 100)) !== false) {
                if (is_array($keys) && !empty($keys)) {
                    $deleted += $this->redis->del($keys);
                }

                if ($iterator === 0) {
                    break;
                }
            }

            return $deleted > 0;
        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Clear operation failed', [
                'prefix' => $this->prefix,
                'error' => $e->getMessage(),
                'operation' => 'clear',
            ]);

            return false;
        }
    }

    /**
     * Get L1 cache info (APCu-compatible interface)
     */
    public function getCacheInfo(): array
    {
        if (!$this->connected) {
            return [];
        }

        try {
            $info = $this->redis->info('memory');
            $keyCount = 0;

            // Count L1 keys
            $iterator = null;

            while (($keys = $this->redis->scan($iterator, $this->prefix . '*', 100)) !== false) {
                if (is_array($keys)) {
                    $keyCount += count($keys);
                }

                if ($iterator === 0) {
                    break;
                }
            }

            return [
                'mem_size' => $info['used_memory'] ?? 0,
                'num_entries' => $keyCount,
                'num_hits' => 0, // Redis doesn't track hits per prefix
                'num_misses' => 0,
                'start_time' => time(),
                'cache_type' => 'Redis L1 (APCu alternative)',
                'connected' => true,
            ];

        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Info retrieval failed', [
                'error' => $e->getMessage(),
                'operation' => 'getCacheInfo',
            ]);

            return ['connected' => false];
        }
    }

    /**
     * Increment value (APCu-compatible interface)
     */
    public function increment(string $key, int $step = 1, ?int $ttl = null): int|false
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $result = $this->redis->incrBy($key, $step);

            // Set TTL if specified and this is a new key
            if ($ttl !== null && $this->isRedisMethodAvailable($this->redis, 'ttl')) {
                $currentTtl = $this->safeRedisCall($this->redis, 'ttl', [$key]);

                if ($currentTtl === -1 && $this->isRedisMethodAvailable($this->redis, 'expire')) {
                    // ENTERPRISE: $ttl is guaranteed non-null by outer if condition
                    $this->safeRedisCall($this->redis, 'expire', [$key, $ttl]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Increment operation failed', [
                'key' => $key,
                'step' => $step,
                'ttl' => $ttl,
                'error' => $e->getMessage(),
                'operation' => 'increment',
            ]);

            return false;
        }
    }

    /**
     * Check if L1 cache is available and working
     */
    public function isAvailable(): bool
    {
        return $this->connected;
    }

    /**
     * Get connection status and performance metrics
     */
    public function getStatus(): array
    {
        return [
            'available' => $this->connected,
            'cache_type' => 'Redis-based L1 (Enterprise APCu alternative)',
            'prefix' => $this->prefix,
            'default_ttl' => $this->defaultTtl,
            'serialization' => $this->serializeOptions['igbinary'] ? 'igbinary' : 'php',
            'performance_mode' => 'optimized_for_speed',
            'connection_timeout' => '100ms (L1 optimized)',
        ];
    }

    /**
     * Health check for Enterprise L1 Cache
     */
    public function healthCheck(): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            // Test basic Redis functionality
            $testKey = 'health_check_' . time();
            $testValue = 'enterprise_l1_test';

            // Test set/get/delete operations
            $setResult = $this->store($testKey, $testValue, 60);

            if (!$setResult) {
                return false;
            }

            $getValue = $this->fetch($testKey);

            if ($getValue !== $testValue) {
                return false;
            }

            $deleteResult = $this->delete($testKey);

            if (!$deleteResult) {
                return false;
            }

            // Test Redis ping
            $pingResult = $this->redis->ping();

            if ($pingResult !== 'PONG') {
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Health check failed', [
                'error' => $e->getMessage(),
                'operation' => 'healthCheck',
                'connected' => $this->connected,
            ]);

            return false;
        }
    }

    /**
     * Initialize Redis connection optimized for L1 cache access
     */
    private function initializeConnection(): void
    {
        try {
            $this->redis = new Redis();

            // ENTERPRISE FIX: Use persistent connection (pconnect) for massive performance under load
            // Persistent connections are reused across requests in the same PHP-FPM worker
            // This eliminates connection overhead for L1 cache - CRITICAL for 300+ concurrent users!
            $redisHost = $_ENV['REDIS_HOST'] ?? 'redis';
            $redisPort = (int) ($_ENV['REDIS_PORT'] ?? 6379);

            if ($this->redis->pconnect($redisHost, $redisPort, 1.0, 'need2talk_l1cache')) { // 1s timeout for persistent connection
                // ENTERPRISE FIX: Authenticate with password if provided
                $password = $_ENV['REDIS_PASSWORD'] ?? null;
                if ($password) {
                    $this->redis->auth($password);
                }

                $this->redis->ping();
                $this->connected = true;

                // Optimize Redis for L1 cache behavior
                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
                $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);

                return;
            }

            // ENTERPRISE: Only use Redis on port 6379 - no fallback to avoid conflicts

            throw new \Exception('No Redis connection available');
        } catch (\Exception $e) {
            Logger::error('DEFAULT: CACHE_L1: Connection failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'connection_attempts' => 'redis_6379_only',
            ]);
            $this->connected = false;
        }
    }

    /**
     * Detect optimal serialization method
     */
    private function detectOptimalSerialization(): void
    {
        if (extension_loaded('igbinary')) {
            $this->serializeOptions['igbinary'] = true;

            if ($this->redis && $this->connected) {
                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
            }
        }
    }
}
