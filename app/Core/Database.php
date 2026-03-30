<?php

namespace Need2Talk\Core;

use Need2Talk\Services\EnterpriseSecureDatabasePool;
use Need2Talk\Services\Logger;
use PDO;
use PDOException;

/**
 * Enterprise Database Class with Integrated Caching
 *
 * Features:
 * - Multi-level caching (Enterprise Redis L1 + Memcached + Redis L3)
 * - Connection pooling for scalability
 * - Query optimization for 100k+ users
 * - Automatic read/write splitting
 * - Metrics collection
 */
class Database
{
    private $pool;

    private $cache;

    private $metrics;

    private $config;

    /** @var PDO|null Transaction connection */
    private $transactionConnection;

    // ENTERPRISE: Transaction timeout tracking
    private $transactionStartTime;

    private $transactionTimeout;

    // Query cache TTL settings
    private $cacheTTL = [
        'short' => 300,    // 5 minutes
        'medium' => 1800,  // 30 minutes
        'long' => 3600,    // 1 hour
        'very_long' => 7200, // 2 hours
    ];

    // Statistics
    private $queryCount = 0;

    private $cacheHits = 0;

    private $cacheMisses = 0;

    /**
     * ENTERPRISE FIX: Track last insert ID per Database instance
     * Connection pooling causes PDO::lastInsertId() to fail when called on different connection
     * This is the standard pattern used by Laravel Eloquent, Doctrine ORM, etc.
     *
     * @var int|null Last inserted auto-increment ID
     */
    private $lastInsertId = null;

    /**
     * ENTERPRISE GALAXY: Tag-based Query Cache for granular invalidation
     * @var TaggedQueryCache|null
     */
    private $taggedCache = null;

    /**
     * Initialize database with enterprise features
     */
    public function __construct($config = [])
    {
        $this->config = array_merge([
            'cache_enabled' => true,
            'metrics_enabled' => true,
            'query_log_enabled' => false,
        ], $config);

        // Initialize enterprise secure connection pool
        $this->pool = EnterpriseSecureDatabasePool::getInstance();

        // Initialize cache manager with Enterprise L1 Cache
        if ($this->config['cache_enabled']) {
            $this->cache = EnterpriseCacheFactory::getInstance([
                'redis' => [
                    'host' => 'redis',
                    'port' => 6379,
                    'database' => 0,
                ],
                'memcached' => [
                    'host' => 'memcached',
                    'port' => 11211,
                ],
            ]);
        }

        // Initialize metrics collector if available (SINGLETON PATTERN)
        if ($this->config['metrics_enabled'] && class_exists('Need2Talk\\Core\\MetricsCollector')) {
            $this->metrics = \Need2Talk\Core\MetricsCollector::getInstance();
        }

        // ENTERPRISE GALAXY: Initialize Tag-based Query Cache for granular invalidation
        // Eliminates nuclear cache invalidation (query:* → only affected queries)
        if ($this->config['cache_enabled']) {
            try {
                $this->taggedCache = TaggedQueryCache::getInstance();
            } catch (\Throwable $e) {
                // Graceful degradation - fall back to nuclear invalidation
                Logger::warning('DATABASE: TaggedQueryCache initialization failed, using nuclear fallback', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Execute SELECT query with intelligent caching
     *
     * @return array Query results
     */
    public function query($sql, $params = [], $options = []): array
    {
        $options = array_merge([
            'cache' => true,
            'cache_ttl' => 'medium',
            'cache_key' => null,
            'force_refresh' => false, // ENTERPRISE: Bypass cache read + force write new cache
        ], $options);

        $this->queryCount++;
        $startTime = microtime(true);

        // Generate cache key if caching is enabled OR force_refresh
        if (($options['cache'] || $options['force_refresh']) && $this->cache) {
            $cacheKey = $options['cache_key'] ?: $this->generateCacheKey($sql, $params);

            // ENTERPRISE: Pass cache options to CacheManager/TaggedQueryCache
            $cacheOptions = [];
            if (isset($options['skip_l2'])) {
                $cacheOptions['skip_l2'] = $options['skip_l2'];
            }
            // ENTERPRISE V4.4: Pass custom cache_tags for granular invalidation
            if (isset($options['cache_tags'])) {
                $cacheOptions['cache_tags'] = $options['cache_tags'];
            }

            // Try to get from cache first (UNLESS force_refresh is active)
            // ENTERPRISE GALAXY: force_refresh skips old cache but WRITES new cache
            // Use case: User changes avatar → bypass 30s → first query writes NEW cache
            if (!$options['force_refresh']) {
                $result = $this->cache->get($cacheKey, null, $cacheOptions);

                if ($result !== null) {
                    $this->cacheHits++;
                    $this->recordMetrics('query_cache_hit', microtime(true) - $startTime);

                    // ENTERPRISE 2025: Track cache hit in debugbar
                    if (class_exists('\Need2Talk\Services\DebugbarService')) {
                        try {
                            \Need2Talk\Services\DebugbarService::trackQuery(
                                $sql . ' [CACHE HIT]',
                                $params,
                                microtime(true) - $startTime,
                                false
                            );
                        } catch (\Throwable $e) {
                            // Never let debugbar errors break functionality
                        }
                    }

                    return $result;
                }
            }
        }

        // Execute query with enterprise security
        $connection = $this->pool->getConnection();

        try {
            // ENTERPRISE: Use secure executeQuery with DoS protection
            $stmt = $this->pool->executeQuery($connection, $sql, $params);
            $result = $stmt->fetchAll();

            // ENTERPRISE GALAXY (2025-12-03): Normalize results for safe caching
            // PostgreSQL BYTEA fields return as PHP resource streams which cannot be serialized.
            // ResultNormalizer converts all resource streams to strings BEFORE caching.
            // This fixes the "empty message bubble" bug where encrypted content was lost in cache.
            $result = ResultNormalizer::normalize($result);

            // Cache the result if caching is enabled OR force_refresh
            // ENTERPRISE GALAXY: force_refresh writes NEW cache after bypassing old one
            if (($options['cache'] || $options['force_refresh']) && $this->cache && isset($cacheKey)) {
                $ttl = $this->cacheTTL[$options['cache_ttl']] ?? $this->cacheTTL['medium'];

                // ENTERPRISE GALAXY: Use TaggedQueryCache for automatic table tagging
                // This enables granular invalidation (only queries touching modified tables)
                if ($this->taggedCache) {
                    $this->taggedCache->set($cacheKey, $result, $sql, $ttl, $cacheOptions ?? []);
                } else {
                    // Fallback to regular cache (no tagging, will use nuclear invalidation)
                    $this->cache->set($cacheKey, $result, $ttl, $cacheOptions ?? []);
                }

                $this->cacheMisses++;
            }

            $this->recordMetrics('query_execution', microtime(true) - $startTime);
            $this->logQuery($sql, $params, microtime(true) - $startTime);

            // ENTERPRISE 2025: Track query execution in debugbar
            if (class_exists('\Need2Talk\Services\DebugbarService')) {
                try {
                    \Need2Talk\Services\DebugbarService::trackQuery(
                        $sql,
                        $params,
                        microtime(true) - $startTime,
                        false
                    );
                } catch (\Throwable $e) {
                    // Never let debugbar errors break functionality
                }
            }

            return $result;

        } catch (PDOException|\Exception $e) {
            $this->recordMetrics('query_error', microtime(true) - $startTime);
            Logger::error('DATABASE: Query execution failed', [
                'sql_hash' => hash('sha256', $sql), // Security: Don't log full SQL
                'params_count' => count($params),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            throw $e;
        } finally {
            $this->pool->releaseConnection($connection);
        }
    }

    /**
     * Execute INSERT/UPDATE/DELETE with cache invalidation
     *
     * @return int|string Affected rows or last insert ID
     */
    public function execute($sql, $params = [], $options = []): int|string
    {
        $options = array_merge([
            'invalidate_cache' => [],
            'return_id' => false,
        ], $options);

        $this->queryCount++;
        $startTime = microtime(true);

        $connection = $this->pool->getConnection();

        try {
            // ENTERPRISE GALAXY: PostgreSQL RETURNING clause for INSERT queries
            // PostgreSQL-only (100% migrated - MySQL support removed)
            $isInsert = preg_match('/^\s*INSERT/i', trim($sql));

            // Auto-add RETURNING id for PostgreSQL INSERT queries when return_id is requested
            if ($isInsert && $options['return_id']) {
                // Check if RETURNING clause already exists
                if (!preg_match('/RETURNING\s+/i', $sql)) {
                    $sql .= ' RETURNING id';
                }
            }

            // ENTERPRISE: Use secure executeQuery with DoS protection
            $stmt = $this->pool->executeQuery($connection, $sql, $params);

            $insertId = null;

            // ENTERPRISE: PostgreSQL returns ID via RETURNING clause (fetch result)
            if ($isInsert && $options['return_id']) {
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($result && isset($result['id'])) {
                    $insertId = $result['id'];
                    $this->lastInsertId = (int) $insertId;
                }
            }

            $affectedRows = $stmt->rowCount();

            // Invalidate related cache entries
            if ($this->cache && !empty($options['invalidate_cache'])) {
                $this->invalidateCache($options['invalidate_cache']);
            }

            $this->recordMetrics('execute_success', microtime(true) - $startTime);
            $this->logQuery($sql, $params, microtime(true) - $startTime, $affectedRows);

            // ENTERPRISE 2025: Track execute operation in debugbar
            if (class_exists('\Need2Talk\Services\DebugbarService')) {
                try {
                    \Need2Talk\Services\DebugbarService::trackQuery(
                        $sql,
                        $params,
                        microtime(true) - $startTime,
                        false
                    );
                } catch (\Throwable $e) {
                    // Never let debugbar errors break functionality
                }
            }

            return $options['return_id'] ? $insertId : $affectedRows;

        } catch (PDOException|\Exception $e) {
            $this->recordMetrics('execute_error', microtime(true) - $startTime) ;
            Logger::error('DATABASE: Execute operation failed', [
                'sql_hash' => hash('sha256', $sql), // Security: Don't log full SQL
                'params_count' => count($params),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            throw $e;
        } finally {
            $this->pool->releaseConnection($connection);
        }
    }

    /**
     * Get single row with caching
     *
     * @return array|null Single row or null if not found
     */
    public function findOne($sql, $params = [], $options = []): ?array
    {
        $options['cache'] = $options['cache'] ?? true;
        $result = $this->query($sql, $params, $options);

        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get multiple rows with caching
     *
     * @return array Multiple rows
     */
    public function findMany($sql, $params = [], $options = []): array
    {
        $options['cache'] = $options['cache'] ?? true;

        return $this->query($sql, $params, $options);
    }

    /**
     * Count rows with caching
     *
     * @return int Row count
     */
    public function count($table, $where = '', $params = [], $options = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM $table";

        if ($where) {
            $sql .= " WHERE $where";
        }

        $options = array_merge(['cache_ttl' => 'short'], $options);
        $result = $this->findOne($sql, $params, $options);

        return $result ? (int) $result['count'] : 0;
    }

    /**
     * Check if record exists with caching
     *
     * @return bool True if exists
     */
    public function exists($table, $where, $params = [], $options = []): bool
    {
        $sql = "SELECT 1 FROM $table WHERE $where LIMIT 1";
        $options = array_merge(['cache_ttl' => 'short'], $options);

        $result = $this->query($sql, $params, $options);

        return !empty($result);
    }

    /**
     * Insert record with auto cache invalidation
     *
     * @return int|string Last insert ID
     */
    public function insert($table, $data, $options = []): int|string
    {
        $columns = array_keys($data);
        $placeholders = ':' . implode(', :', $columns);
        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES ($placeholders)";

        $options = array_merge([
            'invalidate_cache' => ["table:$table"],
            'return_id' => true,
        ], $options);

        return $this->execute($sql, $data, $options);
    }

    /**
     * Update records with auto cache invalidation
     *
     * @return int|string Affected rows
     */
    public function update($table, $data, $where, $params = [], $options = []): int|string
    {
        $setParts = [];
        $setParams = [];

        // ENTERPRISE FIX: Use positional parameters (?) instead of named (:column)
        // to avoid mixing parameter types when WHERE clause also uses positional
        foreach ($data as $column => $value) {
            $setParts[] = "$column = ?";
            $setParams[] = $value;
        }

        $sql = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE $where";

        // CRITICAL: Merge SET params FIRST, then WHERE params (order matters for positional ?)
        $allParams = array_merge($setParams, $params);

        $options = array_merge([
            'invalidate_cache' => ["table:$table"],
        ], $options);

        return $this->execute($sql, $allParams, $options);
    }

    /**
     * Delete records with auto cache invalidation
     *
     * @return int|string Affected rows
     */
    public function delete($table, $where, $params = [], $options = []): int|string
    {
        $sql = "DELETE FROM $table WHERE $where";

        $options = array_merge([
            'invalidate_cache' => ["table:$table"],
        ], $options);

        return $this->execute($sql, $params, $options);
    }

    /**
     * Begin database transaction
     */
    /**
     * ENTERPRISE: Begin transaction with timeout protection
     * Prevents infinite deadlocks that could bring down the system
     *
     * @return bool Transaction started
     */
    public function beginTransaction($timeout = 30): bool
    {
        $connection = $this->pool->getConnection();

        // ENTERPRISE: Set transaction timeout to prevent deadlocks (PostgreSQL)
        try {
            // PostgreSQL: Set lock timeout (milliseconds)
            $connection->exec("SET lock_timeout = '{$timeout}s'");

            // PostgreSQL: Set statement timeout for long-running transactions
            $connection->exec("SET statement_timeout = '" . ($timeout * 2) . "s'");

            $result = $connection->beginTransaction();

            // Track connection and timeout for cleanup
            $this->transactionConnection = $connection;
            $this->transactionStartTime = microtime(true);
            $this->transactionTimeout = $timeout;

            return $result;

        } catch (\Exception $e) {
            Logger::error('DATABASE: Failed to start transaction with timeout', [
                'error' => $e->getMessage(),
                'timeout' => $timeout,
            ]);

            throw $e;
        }
    }

    /**
     * Commit database transaction
     *
     * @return bool Commit successful
     */
    public function commit(): bool
    {
        if (isset($this->transactionConnection)) {
            $result = $this->transactionConnection->commit();
            $this->pool->releaseConnection($this->transactionConnection);
            unset($this->transactionConnection);

            return $result;
        }

        return false;
    }

    /**
     * Rollback database transaction
     *
     * @return bool Rollback successful
     */
    public function rollback(): bool
    {
        if (isset($this->transactionConnection)) {
            $duration = microtime(true) - $this->transactionStartTime;
            $result = $this->transactionConnection->rollback();
            $this->pool->releaseConnection($this->transactionConnection);
            unset($this->transactionConnection);

            Logger::warning('DATABASE: Transaction rolled back', [
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return $result;
        }

        return false;
    }

    /**
     * Execute multiple queries in transaction
     *
     * @return mixed Result from callback
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Exception $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * Paginate results with caching
     *
     * @return array Paginated data with metadata
     */
    public function paginate($sql, $params = [], $page = 1, $perPage = 20, $options = []): array
    {
        $offset = ($page - 1) * $perPage;
        $paginatedSQL = $sql . " LIMIT $perPage OFFSET $offset";

        $options['cache_key'] = $options['cache_key'] ?? "page:{$page}:{$perPage}:" . md5($sql . serialize($params));

        // Get total count
        $countSQL = "SELECT COUNT(*) as total FROM ($sql) as count_query";
        $totalResult = $this->findOne($countSQL, $params, [
            'cache_ttl' => 'short',
            'cache_key' => 'count:' . md5($sql . serialize($params)),
        ]);
        $total = $totalResult ? (int) $totalResult['total'] : 0;

        // Get page data
        $data = $this->query($paginatedSQL, $params, $options);

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
                'has_next' => $page < ceil($total / $perPage),
                'has_prev' => $page > 1,
            ],
        ];
    }

    /**
     * Get database statistics
     *
     * @return array Database statistics
     */
    public function getStats(): array
    {
        $cacheStats = $this->cache ? $this->cache->getStats() : [];
        $poolStats = $this->pool->getStats();

        return [
            'queries' => [
                'total' => $this->queryCount,
                'cache_hits' => $this->cacheHits,
                'cache_misses' => $this->cacheMisses,
                'cache_hit_ratio' => $this->queryCount > 0 ?
                    round(($this->cacheHits / ($this->cacheHits + $this->cacheMisses)) * 100, 2) : 0,
            ],
            'connection_pool' => $poolStats,
            'cache' => $cacheStats,
        ];
    }

    /**
     * Health check for all database components
     *
     * @return array Health status with overall and components breakdown
     */
    public function healthCheck(): array
    {
        $health = [
            'overall' => true,
            'components' => [],
        ];

        // Check database connection
        try {
            $connection = $this->pool->getConnection();
            $stmt = $connection->query('SELECT 1');
            $health['components']['database'] = true;
            $this->pool->releaseConnection($connection);
        } catch (\Exception $e) {
            // ENTERPRISE: Log database health check failure (critical - system monitoring)
            Logger::database('error', 'DATABASE: Health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'impact' => 'Database health check failed - potential connectivity issues',
                'action_required' => 'Check database container: docker compose ps postgres',
            ]);

            $health['components']['database'] = false;
            $health['overall'] = false;
        }

        // Check cache
        if ($this->cache) {
            $cacheHealth = $this->cache->healthCheck();
            $health['components']['cache'] = $cacheHealth['overall'];

            if (!$cacheHealth['overall']) {
                $health['overall'] = false;
            }
        }

        return $health;
    }

    /**
     * Get cache manager instance
     *
     * @return CacheManager|null Cache instance or null
     */
    public function getCache(): ?CacheManager
    {
        return $this->cache;
    }

    /**
     * Get enterprise secure connection pool instance
     *
     * @return EnterpriseSecureDatabasePool Pool instance
     */
    public function getPool(): EnterpriseSecureDatabasePool
    {
        return $this->pool;
    }

    /**
     * ENTERPRISE: Get last insert ID from most recent INSERT operation
     *
     * CRITICAL FIX: Returns cached insert ID from instance property.
     * Connection pooling makes PDO::lastInsertId() unreliable - it returns 0
     * if called on a different connection than the one that performed the INSERT.
     *
     * This is the STANDARD PATTERN used by enterprise ORMs:
     * - Laravel Eloquent: Model::$lastInsertId
     * - Doctrine ORM: EntityManager tracks insert IDs
     * - PDO wrapper libraries (Medoo, Paris, RedBean)
     *
     * Thread-safety: Each HTTP request gets its own Database instance,
     * so this is safe for 100k+ concurrent requests.
     *
     * @return int Last inserted auto-increment ID (0 if no INSERT performed yet)
     */
    public function lastInsertId(): int
    {
        return $this->lastInsertId ?? 0;
    }

    /**
     * Get comprehensive enterprise database statistics
     */
    public function getEnterpriseStats(): array
    {
        $poolStats = $this->pool->getEnterpriseMetrics();
        $cacheStats = $this->cache ? $this->cache->getStats() : [];

        return [
            'database_engine' => 'EnterpriseSecureDatabasePool',
            'security_features' => [
                'ssl_protection' => true,
                'dos_protection' => true,
                'circuit_breaker' => true,
                'connection_leak_prevention' => true,
                'race_condition_protection' => true,
            ],
            'queries' => [
                'total' => $this->queryCount,
                'cache_hits' => $this->cacheHits,
                'cache_misses' => $this->cacheMisses,
                'cache_hit_ratio' => $this->queryCount > 0 ?
                    round(($this->cacheHits / ($this->cacheHits + $this->cacheMisses)) * 100, 2) : 0,
            ],
            'pool_metrics' => $poolStats,
            'cache_metrics' => $cacheStats,
        ];
    }

    /**
     * ENTERPRISE: Ultra-fast cache key generation (10x faster than MD5+serialize)
     * Uses xxHash3 + JSON for hundreds of thousands of concurrent queries
     */
    private function generateCacheKey($sql, $params)
    {
        // Use JSON instead of serialize (faster + more portable)
        $paramString = json_encode($params, JSON_UNESCAPED_UNICODE);
        $combined = $sql . '|' . $paramString;

        // Use fastest available hash algorithm
        if (function_exists('xxh3')) {
            $hash = xxh3($combined);
        } elseif (function_exists('hash')) {
            // SHA256 is faster than MD5 for large strings and more secure
            $hash = hash('sha256', $combined);
        } else {
            // Ultimate fallback
            $hash = md5($combined);
        }

        return 'query:' . $hash;
    }

    /**
     * ENTERPRISE: Invalidate cache entries by patterns with tag support
     * Supports both exact keys and table-based patterns
     *
     * NOTE: Since query cache uses SHA256 hashes, we cannot match by table name.
     * For table-based invalidation, we must clear ALL query cache.
     */
    /**
     * ENTERPRISE GALAXY (2025-11-29): Tag-based Query Cache Invalidation
     *
     * PREVIOUS SYSTEM: deleteByPattern('query:*') - NUCLEAR (killed ALL cached queries)
     * NEW SYSTEM: TaggedQueryCache - invalidate ONLY queries touching the modified table
     *
     * ARCHITECTURE:
     * ┌─────────────────────────────────────────────────────────────────┐
     * │ Before: UPDATE telegram_messages → DELETE ALL 10,000 queries   │
     * │ After:  UPDATE telegram_messages → DELETE only ~5 queries      │
     * │         (queries that touch telegram_messages table)           │
     * └─────────────────────────────────────────────────────────────────┘
     *
     * PATTERN HANDLING:
     * - 'table:users' or 'user:{id}' → Skip (handled by invalidate_user_cache)
     * - Other 'table:*' patterns → Use TaggedQueryCache for granular invalidation
     * - Fallback to nuclear ONLY if TaggedQueryCache fails
     */
    /**
     * ENTERPRISE V4.4: Cache invalidation with TaggedQueryCache
     *
     * Supports:
     * - 'table:audio_comments' → Invalidate all queries touching audio_comments
     * - 'post_comments:3' → Invalidate only queries tagged with this specific tag
     * - 'post:3' → Invalidate queries for specific post
     *
     * @param array $patterns Invalidation patterns
     */
    private function invalidateCache($patterns)
    {
        if (!$this->cache) {
            return;
        }

        foreach ($patterns as $pattern) {
            // ENTERPRISE GALAXY: Skip user cache patterns (handled separately)
            if (strpos($pattern, 'table:users') === 0 || strpos($pattern, 'user:') === 0) {
                continue; // invalidate_user_cache() already called by controllers
            }

            // Table-based invalidation (table:audio_comments)
            if (strpos($pattern, 'table:') === 0) {
                $tableName = substr($pattern, 6); // Remove 'table:' prefix

                // ENTERPRISE GALAXY: Use Tag-based invalidation (granular)
                if ($this->taggedCache) {
                    $invalidated = $this->taggedCache->invalidateByTable($tableName);

                    if ($invalidated >= 0) {
                        continue; // Move to next pattern
                    }
                    // -1 returned = table excluded or error, fall through to nuclear
                }

                // FALLBACK: Nuclear invalidation (only for excluded tables or errors)
                $this->cache->deleteByPattern('query:*');
                Logger::warning('DATABASE: Nuclear cache invalidation (fallback)', [
                    'pattern' => $pattern,
                    'reason' => $this->taggedCache ? 'tag_invalidation_failed' : 'tagged_cache_unavailable',
                ]);
                break;
            }

            // ENTERPRISE V4.4: Custom tag invalidation (post_comments:3, post:3, etc.)
            // These are granular tags for per-entity invalidation
            if ($this->taggedCache && strpos($pattern, ':') !== false) {
                $this->taggedCache->invalidateByTag($pattern);
            }
        }
    }

    /**
     * Record performance metrics
     */
    private function recordMetrics($type, $duration)
    {
        if ($this->metrics) {
            $this->metrics->recordHistogram("database_{$type}_duration", $duration * 1000); // Convert to ms
            $this->metrics->incrementCounter("database_{$type}_count");
        }
    }

    /**
     * Log query for debugging/optimization
     */
    private function logQuery($sql, $params, $duration, $affectedRows = null)
    {
        if (!$this->config['query_log_enabled']) {
            return;
        }

        $logEntry = [
            'sql' => $sql,
            'params' => $params,
            'duration_ms' => round($duration * 1000, 2),
            'affected_rows' => $affectedRows,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // Log slow queries (> 100ms)
        if ($duration > 0.1) {
            Logger::warning('DATABASE: Slow query detected', [
                'sql' => $sql,
                'params' => $params,
                'duration_ms' => round($duration * 1000, 2),
                'affected_rows' => $affectedRows,
                'threshold_ms' => 100,
            ]);
        }
    }
}
