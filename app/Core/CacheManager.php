<?php

declare(strict_types=1);

namespace Need2Talk\Core;

// ENTERPRISE: Redis and Memcached extensions are always installed in Docker
// No stubs needed - production environment guaranteed

// Load Enterprise L1 Cache - Ultra-fast Redis-based L1 layer
require_once __DIR__ . '/EnterpriseL1Cache.php';

use Need2Talk\Services\Logger;

/**
 * Enterprise Cache Manager - Multi-Level Caching
 * L1: Enterprise Redis L1 (Ultra-Fast In-Memory) - Fastest
 * L2: Memcached (Shared Memory) - Fast
 * L3: Redis (Persistent Storage) - Reliable
 *
 * Supports hundreds of thousands of concurrent requests with enterprise-grade performance
 */
class CacheManager
{
    /** @var \Redis|null Redis instance for L3 cache */
    private $redis;

    /** @var \Memcached|null Memcached instance for L2 cache */
    private $memcached;

    /** @var EnterpriseL1Cache|null Enterprise L1 cache - ultra-fast Redis layer */
    private $enterpriseL1;

    /** @var array Cache configuration */
    private $config;

    private $stats = [
        'hits' => ['l1' => 0, 'l2' => 0, 'l3' => 0],
        'misses' => 0,
        'sets' => 0,
    ];

    // ENTERPRISE GALAXY: Circuit Breaker for cache layer fault tolerance
    private static $circuitBreakers = [];

    // Circuit Breaker thresholds (same as EnterpriseRedisManager)
    private const FAILURE_THRESHOLD = 10;     // failures before opening
    private const TIMEOUT_DURATION = 20;       // seconds in open state
    private const SUCCESS_THRESHOLD = 3;       // successes to close circuit
    private const LOG_RATE_LIMIT_SECONDS = 60; // 1 log per minute per layer

    private static $lastLogTime = [];

    /**
     * Initialize all cache layers
     */
    public function __construct($config = [])
    {
        // Store config for later use
        $this->config = array_merge([
            'prefix' => 'n2t:',
        ], $config);

        $this->initializeRedis($config['redis'] ?? []);
        $this->initializeMemcached($config['memcached'] ?? []);
        $this->initializeEnterpriseL1();

        // Log cache layers status
        $this->logCacheStatus();
    }

    /**
     * Get cached value with multi-level fallback
     *
     * @param array $options Options: skip_l2 (bool) - Skip Memcached L2 layer for scalability
     * @return mixed Cached value or default
     */
    public function get($key, $default = null, array $options = []): mixed
    {
        // Normalize key
        $key = $this->normalizeKey($key);

        // ENTERPRISE: Extract options
        $skipL2 = $options['skip_l2'] ?? false;

        // L1: Enterprise Redis Cache (fastest - ultra-fast in-memory)
        if ($this->enterpriseL1Exists($key)) {
            $this->stats['hits']['l1']++;
            $data = $this->enterpriseL1Fetch($key);

            if ($data !== false) {
                return $this->unpack($data);
            }
        }

        // L2: Memcached (fast - shared memory)
        // ENTERPRISE: Skip L2 if requested (e.g., user queries that need granular invalidation)
        if (!$skipL2 && $this->memcached) {
            $data = $this->memcached->get($key);

            if ($data !== false && $this->memcached->getResultCode() === \Memcached::RES_SUCCESS) {
                $this->stats['hits']['l2']++;

                // Store in L1 for next access
                if ($this->enterpriseL1) {
                    $this->enterpriseL1Store($key, $this->pack($data), 300); // 5 minutes in Enterprise L1
                }

                return $data;
            }
        }

        // L3: Redis (persistent - disk)
        if ($this->redis && $this->isCircuitClosed('l3')) {
            try {
                $data = $this->redis->get($key);

                if ($data !== false) {
                    $this->stats['hits']['l3']++;
                    $data = $this->unpack($data);

                    // Store in upper layers
                    if ($this->memcached) {
                        $this->memcached->set($key, $data, 3600); // 1 hour in Memcached
                    }

                    if ($this->enterpriseL1) {
                        $this->enterpriseL1Store($key, $this->pack($data), 300); // 5 minutes in Enterprise L1
                    }

                    $this->recordSuccess('l3');

                    return $data;
                }
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis Get Error', [
                    'error' => $e->getMessage(),
                    'key' => $key,
                ]);
            }
        }

        $this->stats['misses']++;

        return $default;
    }

    /**
     * Check if key exists in cache (Enterprise Performance Optimized)
     *
     * Uses fastest available layer for existence check.
     * Optimized for hundreds of thousands concurrent users.
     *
     * @param  string  $key  Cache key
     * @return bool True if key exists, false otherwise
     */
    public function has(string $key): bool
    {
        // Normalize key
        $key = $this->normalizeKey($key);

        // L1: Enterprise Redis Cache (fastest check)
        if ($this->enterpriseL1 && $this->enterpriseL1->isAvailable() && $this->isCircuitClosed('l1')) {
            try {
                $exists = $this->enterpriseL1->exists($key);
                $this->recordSuccess('l1');

                return $exists;
            } catch (\Exception $e) {
                $this->recordFailure('l1');
                Logger::error('CACHE L1: Enterprise L1 exists check failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to L2
            }
        }

        // L2: Memcached (fast check)
        if ($this->memcached && $this->isCircuitClosed('l2')) {
            try {
                // Memcached get returns false for non-existent keys
                // Use a light operation to check existence
                $result = $this->memcached->get($key);
                $resultCode = method_exists($this->memcached, 'getResultCode') ? $this->memcached->getResultCode() : 0;
                $resNotFound = (class_exists('Memcached') && defined('Memcached::RES_NOTFOUND')) ? constant('Memcached::RES_NOTFOUND') : 16; // Standard Memcached value

                $exists = $result !== false || $resultCode !== $resNotFound;
                $this->recordSuccess('l2');

                return $exists;
            } catch (\Exception $e) {
                $this->recordFailure('l2');
                Logger::error('CACHE L2: Memcached exists check failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to L3
            }
        }

        // L3: Redis (reliable check)
        if ($this->redis && $this->isCircuitClosed('l3')) {
            try {
                $exists = (bool) $this->redis->exists($key);
                $this->recordSuccess('l3');

                return $exists;
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis exists check failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // No cache available - key doesn't exist
        return false;
    }

    /**
     * Set cached value in all available layers
     *
     * @param array $options Options: skip_l2 (bool) - Skip Memcached L2 layer for scalability
     * @return bool Success status
     */
    public function set($key, $value, $ttl = 3600, array $options = []): bool
    {
        $key = $this->normalizeKey($key);
        $this->stats['sets']++;
        $success = false;

        // ENTERPRISE: Extract options
        $skipL2 = $options['skip_l2'] ?? false;

        // L3: Redis (persistent storage)
        if ($this->redis && $this->isCircuitClosed('l3')) {
            try {
                $packed = $this->pack($value);

                if ($this->redis->setex($key, $ttl, $packed)) {
                    $success = true;
                    $this->recordSuccess('l3');
                }
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis Set Error', [
                    'error' => $e->getMessage(),
                    'key' => $key,
                ]);
            }
        }

        // L2: Memcached (shared memory)
        // ENTERPRISE: Skip L2 if requested (for queries that need granular invalidation)
        if (!$skipL2 && $this->memcached) {
            $this->memcached->set($key, $value, $ttl);
        }

        // L1: Enterprise Redis Cache (ultra-fast layer) - optimized TTL
        if ($this->enterpriseL1) {
            $l1Ttl = min($ttl, 300); // Max 5 minutes for L1 cache
            $this->enterpriseL1Store($key, $this->pack($value), $l1Ttl);
        }

        return $success;
    }

    /**
     * Delete from all cache layers
     *
     * @return bool Success status
     */
    public function delete($key): bool
    {
        $key = $this->normalizeKey($key);

        // Delete from all layers
        if ($this->redis && $this->isCircuitClosed('l3')) {
            try {
                $this->redis->del($key);
                $this->recordSuccess('l3');
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis Delete Error', [
                    'error' => $e->getMessage(),
                    'key' => $key,
                ]);
            }
        }

        if ($this->memcached) {
            $this->memcached->delete($key);
        }

        if ($this->enterpriseL1) {
            $this->enterpriseL1Delete($key);
        }

        return true;
    }

    /**
     * ENTERPRISE: Delete cache entries matching a pattern
     * Used for table-level cache invalidation (e.g., query:*admin_settings*)
     * NOTE: Pattern should include query: prefix but NOT L1: prefix
     *
     * @return int Number of deleted entries
     */
    public function deleteByPattern($pattern): int
    {
        $deletedCount = 0;

        // Redis pattern deletion using SCAN
        // ENTERPRISE FIX: Redis stores keys with BOTH patterns:
        // - L1:query:hash (with prefix) - from EnterpriseL1Cache
        // - query:hash (without prefix) - from direct Redis cache
        // Must delete BOTH to ensure complete invalidation
        if ($this->redis && $this->isCircuitClosed('l3')) {
            // ENTERPRISE: Delete keys with L1: prefix AND without prefix
            $patterns = [
                'L1:' . $pattern,  // L1:query:* (prefixed keys)
                $pattern,          // query:* (non-prefixed keys)
            ];

            try {
                foreach ($patterns as $fullPattern) {
                    $iterator = null;
                    $scanCount = 0;

                    do {
                        $keys = $this->redis->scan($iterator, $fullPattern, 100);

                        if ($keys !== false && is_array($keys)) {
                            foreach ($keys as $key) {
                                $this->redis->del($key);
                                $deletedCount++;
                            }
                        }

                        $scanCount++;

                        // Safety: prevent infinite loop
                        if ($scanCount > 1000) {
                            enterprise_log('[CACHE] Warning: Scan exceeded 1000 iterations for pattern: ' . $fullPattern);
                            break;
                        }

                    } while ($iterator > 0);
                }

                $this->recordSuccess('l3');

            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis Pattern Delete Error', [
                    'error' => $e->getMessage(),
                    'pattern' => $pattern,
                ]);
            }
        }

        // Enterprise L1 - also clear from internal L1 cache if available
        // L1 cache stores keys WITHOUT the L1: prefix internally
        if ($this->enterpriseL1 && $this->isCircuitClosed('l1')) {
            try {
                // Pattern: query:*admin_settings* -> need to match internal keys
                // L1 stores keys like: query:sha256hash (without L1: prefix)
                $regexPattern = str_replace(['*', ':'], ['.*', '\\:'], $pattern);
                $regexPattern = '/^' . $regexPattern . '/';

                // Get all keys from L1 storage
                $reflection = new \ReflectionClass($this->enterpriseL1);

                if ($reflection->hasProperty('storage')) {
                    $property = $reflection->getProperty('storage');
                    $property->setAccessible(true);
                    $storage = $property->getValue($this->enterpriseL1);

                    $l1Deleted = 0;

                    foreach (array_keys($storage) as $key) {
                        if (preg_match($regexPattern, $key)) {
                            $this->enterpriseL1Delete($key);
                            $l1Deleted++;
                        }
                    }

                    $deletedCount += $l1Deleted;
                    $this->recordSuccess('l1');
                }
            } catch (\Exception $e) {
                $this->recordFailure('l1');
                Logger::error('CACHE L1: L1 Pattern Delete Error', [
                    'error' => $e->getMessage(),
                    'pattern' => $pattern,
                ]);
            }
        }

        // ENTERPRISE NOTE: Memcached L2 invalidation removed for scalability
        // Critical queries (e.g., users table) now use skip_l2 option to bypass Memcached
        // This prevents global flush() operations that would impact 100k+ concurrent users
        // Only Redis L3 + Enterprise L1 are invalidated (both support granular pattern matching)

        return $deletedCount;
    }

    /**
     * Clear all cache layers
     *
     * @return array Flush results per layer
     */
    public function flush(): array
    {
        $results = [];

        // Flush Redis
        if ($this->redis && $this->isCircuitClosed('l3')) {
            try {
                $results['redis'] = $this->redis->flushDB();
                $this->recordSuccess('l3');
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                $results['redis'] = false;
                Logger::error('CACHE L3: Redis Flush Error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Flush Memcached
        if ($this->memcached) {
            $results['memcached'] = $this->memcached->flush();
        }

        // Flush Enterprise L1 Cache
        if ($this->enterpriseL1) {
            $results['enterprise_l1'] = $this->enterpriseL1ClearCache();
        }

        return $results;
    }

    /**
     * Get multiple values efficiently
     *
     * @return array Key-value pairs
     */
    public function getMultiple($keys, $default = null): array
    {
        $keys = array_map([$this, 'normalizeKey'], $keys);
        $results = [];
        $missing = [];

        // Check L1 (Enterprise Redis Cache) first
        if ($this->enterpriseL1) {
            foreach ($keys as $key) {
                if ($this->enterpriseL1Exists($key)) {
                    $data = $this->enterpriseL1Fetch($key);

                    if ($data !== false) {
                        $results[$key] = $this->unpack($data);
                    } else {
                        $missing[] = $key;
                    }
                } else {
                    $missing[] = $key;
                }
            }
        } else {
            $missing = $keys;
        }

        // Check L2 (Memcached) for missing keys
        if ($this->memcached && !empty($missing)) {
            $memcached_results = $this->memcached->getMulti($missing);

            if ($memcached_results) {
                foreach ($memcached_results as $key => $value) {
                    $results[$key] = $value;

                    // Store in L1
                    if ($this->enterpriseL1) {
                        $this->enterpriseL1Store($key, $this->pack($value), 300);
                    }
                }

                // Update missing keys
                $missing = array_diff($missing, array_keys($memcached_results));
            }
        }

        // Check L3 (Redis) for remaining missing keys
        if ($this->redis && !empty($missing) && $this->isCircuitClosed('l3')) {
            try {
                $redis_results = $this->redis->mget($missing);

                foreach ($missing as $index => $key) {
                    if (isset($redis_results[$index]) && $redis_results[$index] !== false) {
                        $value = $this->unpack($redis_results[$index]);
                        $results[$key] = $value;

                        // Store in upper layers
                        if ($this->memcached) {
                            $this->memcached->set($key, $value, 3600);
                        }

                        if ($this->enterpriseL1) {
                            $this->enterpriseL1Store($key, $this->pack($value), 300);
                        }
                    }
                }

                $this->recordSuccess('l3');
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis MGet Error', [
                    'error' => $e->getMessage(),
                    'keys_count' => count($missing),
                ]);
            }
        }

        // Fill missing with default values
        foreach ($keys as $key) {
            if (!isset($results[$key])) {
                $results[$key] = $default;
            }
        }

        return $results;
    }

    /**
     * Set multiple values efficiently
     *
     * @return bool Success status
     */
    public function setMultiple($values, $ttl = 3600): bool
    {
        $normalizedValues = [];

        foreach ($values as $key => $value) {
            $normalizedKey = $this->normalizeKey($key);
            $normalizedValues[$normalizedKey] = $value;
        }

        // Set in Redis (L3)
        if ($this->redis && !empty($normalizedValues) && $this->isCircuitClosed('l3')) {
            try {
                $pipeline = $this->redis->multi(\Redis::PIPELINE);

                foreach ($normalizedValues as $key => $value) {
                    $pipeline->setex($key, $ttl, $this->pack($value));
                }
                $pipeline->exec();

                $this->recordSuccess('l3');
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis SetMultiple Error', [
                    'error' => $e->getMessage(),
                    'keys_count' => count($normalizedValues),
                ]);
            }
        }

        // Set in Memcached (L2)
        if ($this->memcached && !empty($normalizedValues)) {
            $this->memcached->setMulti($normalizedValues, $ttl);
        }

        // Set in Enterprise L1 Cache
        if ($this->enterpriseL1) {
            $l1Ttl = min($ttl, 300);

            foreach ($normalizedValues as $key => $value) {
                $this->enterpriseL1Store($key, $this->pack($value), $l1Ttl);
            }
        }

        $this->stats['sets'] += count($normalizedValues);

        return true;
    }

    /**
     * Increment numeric value atomically
     *
     * @return int New value after increment
     */
    public function increment($key, $step = 1): int
    {
        $key = $this->normalizeKey($key);

        // Use Redis for atomic increments
        if ($this->redis && $this->isCircuitClosed('l3')) {
            try {
                $result = $this->redis->incrBy($key, $step);

                // Invalidate upper layers
                if ($this->memcached) {
                    $this->memcached->delete($key);
                }

                if ($this->enterpriseL1) {
                    $this->enterpriseL1Delete($key);
                }

                $this->recordSuccess('l3');

                return $result;
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis Increment Error', [
                    'error' => $e->getMessage(),
                    'key' => $key,
                    'step' => $step,
                ]);
            }
        }

        // Fallback to get/set
        $current = $this->get($key, 0);
        $new = $current + $step;
        $this->set($key, $new);

        return $new;
    }

    /**
     * Add value to cache ONLY if key doesn't exist (atomic operation)
     *
     * PERFORMANCE: Used for mutex locks (cache stampede prevention)
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if added, false if key exists
     */
    public function add($key, $value, $ttl = 3600): bool
    {
        // Try L3 Redis first (most reliable for atomic operations)
        if ($this->redis && $this->isCircuitClosed('l3')) {
            try {
                // SET NX (set if not exists) - atomic operation
                $result = $this->redis->set($key, serialize($value), ['NX', 'EX' => $ttl]);
                $success = $result !== false;

                if ($success) {
                    $this->recordSuccess('l3');
                }

                return $success;
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis add() failed', [
                    'error' => $e->getMessage(),
                    'key' => $key,
                ]);
            }
        }

        // Fallback: Try Memcached (also supports add)
        if ($this->memcached && $this->isCircuitClosed('l2')) {
            try {
                $success = $this->memcached->add($key, $value, $ttl);

                if ($success) {
                    $this->recordSuccess('l2');
                }

                return $success;
            } catch (\Exception $e) {
                $this->recordFailure('l2');
                Logger::error('CACHE L2: Memcached add() failed', [
                    'error' => $e->getMessage(),
                    'key' => $key,
                ]);
            }
        }

        // No cache available - return false (lock failed)
        return false;
    }

    /**
     * Get remaining TTL (time to live) for a key
     *
     * PERFORMANCE: Used for probabilistic early expiration
     *
     * @param string $key Cache key
     * @return int|false TTL in seconds, or false if key doesn't exist
     */
    public function ttl($key): int|false
    {
        // Try L3 Redis (only layer that supports TTL query)
        if ($this->redis && $this->isCircuitClosed('l3')) {
            try {
                $ttl = $this->redis->ttl($key);

                // Redis returns -2 if key doesn't exist, -1 if no expiration
                if ($ttl === -2) {
                    return false; // Key doesn't exist
                }
                if ($ttl === -1) {
                    return PHP_INT_MAX; // No expiration (infinite TTL)
                }

                $this->recordSuccess('l3');

                return $ttl;
            } catch (\Exception $e) {
                $this->recordFailure('l3');
                Logger::error('CACHE L3: Redis ttl() failed', [
                    'error' => $e->getMessage(),
                    'key' => $key,
                ]);
            }
        }

        // TTL not supported on other layers - return false
        return false;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $stats = $this->stats;
        $stats['hit_ratio'] = $this->calculateHitRatio();
        $stats['layers'] = [
            'l1_enterprise_redis' => $this->enterpriseL1 ? 'available' : 'unavailable',
            'l2_memcached' => $this->memcached ? 'available' : 'unavailable',
            'l3_redis' => $this->redis ? 'available' : 'unavailable',
        ];

        // Add Redis info if available
        if ($this->redis) {
            try {
                $info = $this->redis->info();
                $stats['redis'] = [
                    'used_memory_human' => $info['used_memory_human'] ?? 'N/A',
                    'connected_clients' => $info['connected_clients'] ?? 0,
                    'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                    'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                ];
            } catch (\Exception $e) {
                $stats['redis'] = ['error' => $e->getMessage()];
            }
        }

        return $stats;
    }

    /**
     * Health check for all cache layers
     */
    public function healthCheck(): array
    {
        $health = [
            'overall' => true,
            'layers' => [],
        ];

        // Check Redis
        if ($this->redis) {
            try {
                $result = $this->redis->ping();
                $health['layers']['redis'] = ($result === '+PONG' || $result === 'PONG');
            } catch (\Exception $e) {
                $health['layers']['redis'] = false;
                $health['overall'] = false;
            }
        } else {
            $health['layers']['redis'] = false;
        }

        // Check Memcached
        if ($this->memcached) {
            $stats = $this->memcached->getStats();
            $health['layers']['memcached'] = !empty($stats);

            if (!$health['layers']['memcached']) {
                $health['overall'] = false;
            }
        } else {
            $health['layers']['memcached'] = false;
        }

        // Check Enterprise Redis L1
        $health['layers']['enterprise_l1'] = $this->enterpriseL1 ? $this->enterpriseL1->healthCheck() : false;

        return $health;
    }

    /**
     * Initialize Redis (L3 - Persistent Cache)
     */
    private function initializeRedis($config)
    {
        try {
            // Check if Redis extension is available
            if (!extension_loaded('redis') || !class_exists('Redis')) {
                Logger::warning('DEFAULT: Redis extension not available for cache layer');
                $this->redis = null;

                return;
            }

            $this->redis = new \Redis();
            $host = $config['host'] ?? 'redis';
            $port = (int)($config['port'] ?? 6379); // CRITICAL FIX: Cast to int (Redis extension requires int)
            $database = (int)($config['database'] ?? 0); // CRITICAL FIX: Cast to int

            // ENTERPRISE PERFORMANCE FIX: Use persistent connections to prevent socket exhaustion
            // With 200 concurrent users, connect() creates 200+ new TCP sockets → "Address not available"
            // pconnect() reuses same TCP socket across requests → zero socket overhead
            if ($this->redis->pconnect($host, $port, 2.0, 'cache_l3')) {
                // ENTERPRISE FIX: Authenticate with password if provided
                $password = $config['password'] ?? null;
                if ($password) {
                    $this->redis->auth($password);
                }

                $this->redis->select($database);
                $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

                // Enable compression if available
                if (defined('\Redis::COMPRESSION_LZF')) {
                    $this->redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_LZF);
                }
            } else {
                $this->redis = null;
            }
        } catch (\Exception $e) {
            Logger::error('DEFAULT: Redis Cache Error', ['error' => $e->getMessage()]);
            $this->redis = null;
        }
    }

    /**
     * Initialize Memcached (L2 - Shared Memory Cache)
     */
    private function initializeMemcached($config)
    {
        try {
            if (!extension_loaded('memcached') || !class_exists('Memcached')) {
                $this->memcached = null;

                return;
            }

            $this->memcached = new \Memcached();
            $host = $config['host'] ?? 'redis';
            $port = (int)($config['port'] ?? 11211); // CRITICAL FIX: Cast to int for Memcached

            if (!$this->memcached->addServer($host, $port)) {
                $this->memcached = null;
            } else {
                // Configure Memcached options for high performance
                $this->memcached->setOptions([
                    \Memcached::OPT_BINARY_PROTOCOL => true,
                    \Memcached::OPT_TCP_NODELAY => true,
                    \Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
                    \Memcached::OPT_COMPRESSION => true,
                    \Memcached::OPT_SERIALIZER => \Memcached::SERIALIZER_PHP,
                ]);
            }
        } catch (\Exception $e) {
            Logger::error('DEFAULT: Memcached Cache Error', ['error' => $e->getMessage()]);
            $this->memcached = null;
        }
    }

    /**
     * Initialize Enterprise L1 Cache (Ultra-Fast Redis Layer)
     */
    private function initializeEnterpriseL1()
    {
        // Initialize Enterprise L1 Cache - Redis-based ultra-fast layer
        $this->enterpriseL1 = new EnterpriseL1Cache();

        if ($this->enterpriseL1->isAvailable()) {
            // Enterprise L1 Cache: Ultra-fast Redis L1 layer initialized successfully
        } else {
            Logger::error('DEFAULT: ❌ Enterprise L1 Cache: Failed to initialize Redis L1 layer');
            $this->enterpriseL1 = null;
        }
    }

    /**
     * Enterprise L1 Cache helper functions - Direct Redis operations
     */
    private function enterpriseL1Exists($key): bool
    {
        if ($this->enterpriseL1) {
            return $this->enterpriseL1->exists($key);
        }

        return false;
    }

    private function enterpriseL1Fetch($key): mixed
    {
        if ($this->enterpriseL1) {
            return $this->enterpriseL1->fetch($key);
        }

        return false;
    }

    private function enterpriseL1Store($key, $value, $ttl): bool
    {
        if ($this->enterpriseL1) {
            return $this->enterpriseL1->store($key, $value, $ttl);
        }

        return false;
    }

    private function enterpriseL1Delete($key): bool
    {
        if ($this->enterpriseL1) {
            return $this->enterpriseL1->delete($key);
        }

        return false;
    }

    private function enterpriseL1ClearCache(): bool
    {
        if ($this->enterpriseL1) {
            return $this->enterpriseL1->clear();
        }

        return false;
    }

    /**
     * Normalize cache key
     */
    private function normalizeKey($key)
    {
        // Remove invalid characters and limit length
        $key = preg_replace('/[^a-zA-Z0-9:_\-.]/', '_', $key);

        return substr($key, 0, 250); // Max key length for Memcached
    }

    /**
     * Pack data for storage
     */
    private function pack($data)
    {
        return serialize($data);
    }

    /**
     * Unpack data from storage
     */
    private function unpack($data)
    {
        return unserialize($data);
    }

    /**
     * Calculate hit ratio
     */
    private function calculateHitRatio()
    {
        $totalHits = array_sum($this->stats['hits']);
        $totalRequests = $totalHits + $this->stats['misses'];

        return $totalRequests > 0 ? round(($totalHits / $totalRequests) * 100, 2) : 0;
    }

    /**
     * Log cache status
     */
    private function logCacheStatus()
    {
        // Determine L1 cache status (Enterprise Redis only)
        $l1Status = $this->enterpriseL1 && $this->enterpriseL1->isAvailable() ?
                   '✅ (Enterprise Redis L1)' : '❌';

        $status = [
            'L1 Enterprise Cache' => $l1Status,
            'Memcached (L2)' => $this->memcached ? '✅' : '❌',
            'Redis (L3)' => $this->redis ? '✅' : '❌',
        ];

        // Cache layers initialized (logging disabled for performance)

        // ENTERPRISE GALAXY: Initialize circuit breakers for each cache layer
        $this->initializeCircuitBreakers();
    }

    // ========================================================================
    // ENTERPRISE GALAXY: CIRCUIT BREAKER PATTERN (NASA-GRADE)
    // ========================================================================

    /**
     * Initialize circuit breakers for all cache layers
     *
     * ENTERPRISE: Prevents cascading failures when cache layers fail
     *
     * @return void
     */
    private function initializeCircuitBreakers(): void
    {
        $layers = ['l1', 'l2', 'l3'];

        foreach ($layers as $layer) {
            if (!isset(self::$circuitBreakers[$layer])) {
                self::$circuitBreakers[$layer] = [
                    'state' => 'closed',
                    'failure_count' => 0,
                    'success_count' => 0,
                    'opened_at' => 0,
                ];
            }
        }
    }

    /**
     * Check if circuit breaker is closed for a layer
     *
     * ENTERPRISE: State machine (closed → open → half_open → closed)
     *
     * @param string $layer Cache layer (l1, l2, l3)
     * @return bool True if circuit is closed (can try operation)
     */
    private function isCircuitClosed(string $layer): bool
    {
        $breaker = self::$circuitBreakers[$layer] ?? null;

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
                    // Move to half-open state (testing recovery)
                    self::$circuitBreakers[$layer]['state'] = 'half_open';
                    self::$circuitBreakers[$layer]['success_count'] = 0;

                    // Log state transition (rate-limited)
                    $this->logCircuitBreaker($layer, 'HALF-OPEN', 'Testing recovery after timeout');

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
     *
     * ENTERPRISE: Closes circuit after SUCCESS_THRESHOLD successes in half_open
     *
     * @param string $layer Cache layer (l1, l2, l3)
     * @return void
     */
    private function recordSuccess(string $layer): void
    {
        if (!isset(self::$circuitBreakers[$layer])) {
            return;
        }

        $breaker = &self::$circuitBreakers[$layer];

        if ($breaker['state'] === 'half_open') {
            $breaker['success_count']++;

            if ($breaker['success_count'] >= self::SUCCESS_THRESHOLD) {
                // Close circuit - back to normal operation
                $breaker['state'] = 'closed';
                $breaker['failure_count'] = 0;

                // Log state transition (rate-limited)
                $this->logCircuitBreaker($layer, 'CLOSED', 'Normal operation restored');
            }
        } elseif ($breaker['state'] === 'closed') {
            // Reset failure count on success
            $breaker['failure_count'] = 0;
        }
    }

    /**
     * Record failed operation for circuit breaker
     *
     * ENTERPRISE: Opens circuit after FAILURE_THRESHOLD failures
     *
     * @param string $layer Cache layer (l1, l2, l3)
     * @return void
     */
    private function recordFailure(string $layer): void
    {
        if (!isset(self::$circuitBreakers[$layer])) {
            $this->initializeCircuitBreakers();
        }

        $breaker = &self::$circuitBreakers[$layer];

        // Don't count failures when circuit is already open
        if ($breaker['state'] === 'open') {
            return;
        }

        $breaker['failure_count']++;

        if ($breaker['failure_count'] >= self::FAILURE_THRESHOLD) {
            // Open circuit - stop trying operations
            $breaker['state'] = 'open';
            $breaker['opened_at'] = time();

            // Log state transition (rate-limited)
            $this->logCircuitBreaker(
                $layer,
                'OPEN',
                'Too many failures (threshold: ' . self::FAILURE_THRESHOLD . ')'
            );
        }
    }

    /**
     * Log circuit breaker state transition (rate-limited)
     *
     * ENTERPRISE: Max 1 log per minute per layer to prevent spam
     *
     * @param string $layer Cache layer
     * @param string $state New state
     * @param string $reason Reason for transition
     * @return void
     */
    private function logCircuitBreaker(string $layer, string $state, string $reason): void
    {
        $now = time();
        $logKey = $layer . ':' . $state;

        // Rate limiting: max 1 log per minute
        if (!isset(self::$lastLogTime[$logKey]) || ($now - self::$lastLogTime[$logKey]) >= self::LOG_RATE_LIMIT_SECONDS) {
            Logger::error("CACHE CIRCUIT BREAKER [$layer] → $state: $reason", [
                'layer' => $layer,
                'state' => $state,
                'reason' => $reason,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            self::$lastLogTime[$logKey] = $now;
        }
    }

    /**
     * Get circuit breaker status for monitoring
     *
     * ENTERPRISE: Admin dashboard can call this to monitor cache health
     *
     * @return array Circuit breaker status for all layers
     */
    public function getCircuitBreakerStatus(): array
    {
        $status = [];

        foreach (self::$circuitBreakers as $layer => $breaker) {
            $status[$layer] = [
                'state' => $breaker['state'] ?? 'closed',
                'failure_count' => $breaker['failure_count'] ?? 0,
                'success_count' => $breaker['success_count'] ?? 0,
                'opened_at' => $breaker['opened_at'] ?? 0,
            ];
        }

        return $status;
    }
}
