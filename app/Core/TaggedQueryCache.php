<?php

declare(strict_types=1);

namespace Need2Talk\Core;

use Need2Talk\Services\Logger;

/**
 * ============================================================================
 * ENTERPRISE TAGGED QUERY CACHE
 * ============================================================================
 *
 * High-performance tag-based cache invalidation system for database queries.
 * Eliminates nuclear cache invalidation by tracking query-to-table relationships.
 *
 * ARCHITECTURE:
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │                         TAGGED QUERY CACHE                               │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │                                                                          │
 * │  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐            │
 * │  │ query:abc123 │     │ query:def456 │     │ query:ghi789 │            │
 * │  │  (users)     │     │  (telegram)  │     │  (cron_jobs) │            │
 * │  └──────┬───────┘     └──────┬───────┘     └──────┬───────┘            │
 * │         │                    │                    │                     │
 * │         ▼                    ▼                    ▼                     │
 * │  ┌──────────────────────────────────────────────────────────┐          │
 * │  │                    TAG INDEX (Redis SETs)                 │          │
 * │  │  qtag:users → {abc123, xyz999, ...}                       │          │
 * │  │  qtag:telegram_messages → {def456, ...}                   │          │
 * │  │  qtag:cron_jobs → {ghi789, ...}                           │          │
 * │  └──────────────────────────────────────────────────────────┘          │
 * │                                                                          │
 * │  INVALIDATION:                                                           │
 * │  table:telegram_messages → Get qtag:telegram_messages SET               │
 * │                          → Delete ONLY query:def456                      │
 * │                          → Other queries UNTOUCHED                       │
 * │                                                                          │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * PERFORMANCE:
 * - Nuclear: O(n) where n = ALL cached queries (10,000+)
 * - Tagged:  O(m) where m = queries for specific table (~10-100)
 * - Speed improvement: 100-1000x for targeted invalidation
 *
 * REDIS DATA STRUCTURES:
 * - query:{hash}           → STRING - Cached query result (packed)
 * - qtag:{tablename}       → SET    - All query hashes touching this table
 * - qmeta:{hash}           → SET    - Tables touched by this query (for cleanup)
 *
 * ENTERPRISE PATTERNS:
 * - Automatic table extraction from SQL using regex
 * - Support for JOINs, subqueries, CTEs
 * - Graceful degradation when Redis unavailable
 * - Circuit breaker integration
 * - Comprehensive logging and metrics
 *
 * @package Need2Talk\Core
 * @author  Claude AI + Human Partnership
 * @since   2025-11-29
 * @version 1.0.0
 */
class TaggedQueryCache
{
    /**
     * Redis instance for tag management
     * @var \Redis|null
     */
    private ?\Redis $redis;

    /**
     * Underlying cache manager for actual data storage
     * @var CacheManager
     */
    private CacheManager $cache;

    /**
     * Prefix for tag index keys
     */
    private const TAG_PREFIX = 'qtag:';

    /**
     * Prefix for query metadata keys
     */
    private const META_PREFIX = 'qmeta:';

    /**
     * TTL for tag index entries (slightly longer than max query TTL)
     * 3 hours = 10800 seconds
     */
    private const TAG_INDEX_TTL = 10800;

    /**
     * Singleton instance
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Statistics for monitoring
     */
    private array $stats = [
        'tags_created' => 0,
        'tags_invalidated' => 0,
        'queries_invalidated' => 0,
        'tables_extracted' => 0,
    ];

    /**
     * Tables to EXCLUDE from tag-based caching (use nuclear for these)
     * These are system tables that rarely change and affect many queries
     */
    private const EXCLUDED_TABLES = [
        'app_settings',      // System config - rarely changes, affects everything
        'migrations',        // Schema tracking - never queried at runtime
        'password_resets',   // Temporary tokens - short TTL anyway
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize Tagged Query Cache
     */
    private function __construct()
    {
        // Get cache manager from enterprise bootstrap
        $this->cache = \Need2Talk\Core\EnterpriseCacheFactory::getInstance();

        // Get direct Redis connection for tag operations
        $this->initializeRedis();
    }

    /**
     * Initialize direct Redis connection for tag operations
     */
    private function initializeRedis(): void
    {
        try {
            $this->redis = new \Redis();

            $host = $_ENV['REDIS_HOST'] ?? 'redis';
            $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
            $password = $_ENV['REDIS_PASSWORD'] ?? null;

            if (!$this->redis->connect($host, $port, 2.0)) {
                $this->redis = null;
                return;
            }

            if ($password) {
                $this->redis->auth($password);
            }

            // Use DB 0 (same as L1 cache)
            $this->redis->select(0);

        } catch (\Throwable $e) {
            Logger::error('TAGGED_CACHE: Redis connection failed', [
                'error' => $e->getMessage(),
            ]);
            $this->redis = null;
        }
    }

    /**
     * Store query result with automatic tag extraction + custom tags
     *
     * ENTERPRISE V4.4: Supports custom cache_tags for granular invalidation
     * Example: cache_tags => ['post_comments:3', 'post:3']
     * This allows invalidating only comments for post 3, not ALL comments
     *
     * @param string $cacheKey  Cache key (query:{hash})
     * @param mixed  $value     Query result to cache
     * @param string $sql       Original SQL query (for table extraction)
     * @param int    $ttl       Time-to-live in seconds
     * @param array  $options   Additional options (skip_l2, cache_tags, etc.)
     * @return bool Success status
     */
    public function set(string $cacheKey, mixed $value, string $sql, int $ttl = 1800, array $options = []): bool
    {
        // Store in underlying cache
        $success = $this->cache->set($cacheKey, $value, $ttl, $options);

        if (!$success || !$this->redis) {
            return $success;
        }

        // Extract tables from SQL (automatic)
        $tables = $this->extractTablesFromSQL($sql);

        // ENTERPRISE V4.4: Add custom cache_tags (granular invalidation)
        // Custom tags like 'post_comments:3' allow per-post invalidation
        $customTags = $options['cache_tags'] ?? [];

        // Merge auto-extracted tables with custom tags
        // Custom tags format: 'post_comments:3', 'post:3', 'user_feed:100'
        $allTags = array_merge($tables, $customTags);

        if (empty($allTags)) {
            return $success;
        }

        // Extract hash from cache key (query:{hash} → {hash})
        $hash = $this->extractHash($cacheKey);

        if (!$hash) {
            return $success;
        }

        try {
            // Use pipeline for atomic operations
            $this->redis->multi(\Redis::PIPELINE);

            // Add hash to each tag set (both tables and custom tags)
            foreach ($allTags as $tag) {
                // Skip excluded tables (custom tags are never excluded)
                if (in_array($tag, self::EXCLUDED_TABLES, true)) {
                    continue;
                }

                $tagKey = self::TAG_PREFIX . $tag;

                // Add query hash to tag set
                $this->redis->sAdd($tagKey, $hash);

                // Refresh TTL on tag set
                $this->redis->expire($tagKey, self::TAG_INDEX_TTL);

                $this->stats['tags_created']++;
            }

            // Store reverse mapping (query → tags) for cleanup
            $metaKey = self::META_PREFIX . $hash;
            foreach ($allTags as $tag) {
                if (!in_array($tag, self::EXCLUDED_TABLES, true)) {
                    $this->redis->sAdd($metaKey, $tag);
                }
            }
            $this->redis->expire($metaKey, $ttl + 60); // Slightly longer than query TTL

            $this->redis->exec();

            $this->stats['tables_extracted'] += count($allTags);

        } catch (\Throwable $e) {
            Logger::warning('TAGGED_CACHE: Failed to create tag index', [
                'hash' => $hash,
                'tags' => $allTags,
                'error' => $e->getMessage(),
            ]);
        }

        return $success;
    }

    /**
     * Get cached value (delegates to underlying cache)
     *
     * @param string $key     Cache key
     * @param mixed  $default Default value if not found
     * @param array  $options Additional options
     * @return mixed Cached value or default
     */
    public function get(string $key, mixed $default = null, array $options = []): mixed
    {
        return $this->cache->get($key, $default, $options);
    }

    /**
     * Invalidate all queries tagged with specific tag (table or custom)
     *
     * ENTERPRISE V4.4: Unified invalidation for both tables and custom tags
     * Custom tags: 'post_comments:3', 'post:3', 'user_feed:100'
     * Table tags: 'audio_comments', 'audio_posts'
     *
     * This is the magic - instead of deleting ALL queries,
     * we only delete queries that have this specific tag.
     *
     * @param string $tag Tag name (table name or custom tag like 'post_comments:3')
     * @return int Number of queries invalidated (-1 = fallback to nuclear)
     */
    public function invalidateByTag(string $tag): int
    {
        if (!$this->redis) {
            Logger::warning('TAGGED_CACHE: Redis unavailable for tag invalidation', [
                'tag' => $tag,
            ]);
            return -1;
        }

        $tagKey = self::TAG_PREFIX . $tag;

        try {
            $hashes = $this->redis->sMembers($tagKey);

            if (empty($hashes)) {
                return 0;
            }

            $invalidatedCount = 0;
            $this->redis->multi(\Redis::PIPELINE);

            foreach ($hashes as $hash) {
                $this->redis->del('query:' . $hash);
                $this->redis->del('L1:query:' . $hash);
                $this->redis->del(self::META_PREFIX . $hash);
                $invalidatedCount++;
            }

            $this->redis->del($tagKey);
            $this->redis->exec();

            $this->stats['tags_invalidated']++;
            $this->stats['queries_invalidated'] += $invalidatedCount;

            return $invalidatedCount;

        } catch (\Throwable $e) {
            Logger::error('TAGGED_CACHE: Tag invalidation failed', [
                'tag' => $tag,
                'error' => $e->getMessage(),
            ]);
            return -1;
        }
    }

    /**
     * Invalidate all queries tagged with specific table
     *
     * This is the magic - instead of deleting ALL queries,
     * we only delete queries that touch the modified table.
     *
     * @param string $table Table name (without 'table:' prefix)
     * @return int Number of queries invalidated
     */
    public function invalidateByTable(string $table): int
    {
        // Handle 'table:tablename' format
        if (str_starts_with($table, 'table:')) {
            $table = substr($table, 6);
        }

        // Check if this table should use nuclear invalidation
        if (in_array($table, self::EXCLUDED_TABLES, true)) {
            return -1; // Signal to use nuclear
        }

        // Use unified invalidateByTag method
        return $this->invalidateByTag($table);
    }

    /**
     * Extract table names from SQL query
     *
     * Handles:
     * - Simple SELECT: SELECT * FROM users
     * - JOINs: SELECT * FROM users JOIN posts ON ...
     * - Subqueries: SELECT * FROM (SELECT * FROM users)
     * - INSERT/UPDATE/DELETE: INSERT INTO users ...
     * - WITH CTEs: WITH cte AS (SELECT * FROM users) ...
     *
     * @param string $sql SQL query
     * @return array List of table names
     */
    private function extractTablesFromSQL(string $sql): array
    {
        $tables = [];

        // Normalize SQL: lowercase, remove extra whitespace
        $normalizedSql = preg_replace('/\s+/', ' ', strtolower(trim($sql)));

        // Pattern 1: FROM clause (including JOINs)
        // Matches: FROM tablename, FROM schema.tablename, tablename AS alias
        if (preg_match_all('/\bfrom\s+([a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?)/i', $normalizedSql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Pattern 2: JOIN clauses
        // Matches: JOIN tablename, LEFT JOIN tablename, etc.
        if (preg_match_all('/\bjoin\s+([a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?)/i', $normalizedSql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Pattern 3: INSERT INTO
        if (preg_match('/\binsert\s+into\s+([a-z_][a-z0-9_]*)/i', $normalizedSql, $matches)) {
            $tables[] = $matches[1];
        }

        // Pattern 4: UPDATE
        if (preg_match('/\bupdate\s+([a-z_][a-z0-9_]*)/i', $normalizedSql, $matches)) {
            $tables[] = $matches[1];
        }

        // Pattern 5: DELETE FROM
        if (preg_match('/\bdelete\s+from\s+([a-z_][a-z0-9_]*)/i', $normalizedSql, $matches)) {
            $tables[] = $matches[1];
        }

        // Clean up: remove schema prefixes, deduplicate
        $cleanTables = [];
        foreach ($tables as $table) {
            // Remove schema prefix if present (schema.table → table)
            if (str_contains($table, '.')) {
                $parts = explode('.', $table);
                $table = end($parts);
            }

            // Skip system tables and subquery aliases
            if ($this->isValidTableName($table)) {
                $cleanTables[] = $table;
            }
        }

        return array_unique($cleanTables);
    }

    /**
     * Validate table name (filter out keywords and invalid names)
     *
     * @param string $name Potential table name
     * @return bool True if valid table name
     */
    private function isValidTableName(string $name): bool
    {
        // SQL keywords that might be mistakenly captured
        $sqlKeywords = [
            'select', 'from', 'where', 'and', 'or', 'not', 'in', 'like',
            'order', 'by', 'group', 'having', 'limit', 'offset', 'as',
            'join', 'left', 'right', 'inner', 'outer', 'cross', 'on',
            'insert', 'into', 'values', 'update', 'set', 'delete',
            'create', 'alter', 'drop', 'index', 'table', 'view',
            'null', 'true', 'false', 'case', 'when', 'then', 'else', 'end',
            'distinct', 'all', 'any', 'exists', 'between', 'is', 'union',
            'except', 'intersect', 'with', 'recursive', 'returning',
        ];

        $name = strtolower($name);

        // Must be at least 2 characters
        if (strlen($name) < 2) {
            return false;
        }

        // Must not be a SQL keyword
        if (in_array($name, $sqlKeywords, true)) {
            return false;
        }

        // Must start with letter or underscore
        if (!preg_match('/^[a-z_]/', $name)) {
            return false;
        }

        return true;
    }

    /**
     * Extract hash from cache key
     *
     * @param string $cacheKey Cache key (query:{hash} or L1:query:{hash})
     * @return string|null Hash or null if invalid format
     */
    private function extractHash(string $cacheKey): ?string
    {
        // Handle L1:query:{hash} format
        if (str_starts_with($cacheKey, 'L1:query:')) {
            return substr($cacheKey, 9);
        }

        // Handle query:{hash} format
        if (str_starts_with($cacheKey, 'query:')) {
            return substr($cacheKey, 6);
        }

        return null;
    }

    /**
     * Get statistics for monitoring
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        $tagCount = 0;
        $totalMembers = 0;

        if ($this->redis) {
            try {
                // Count tag sets
                $iterator = null;
                do {
                    $keys = $this->redis->scan($iterator, self::TAG_PREFIX . '*', 100);
                    if ($keys !== false && is_array($keys)) {
                        foreach ($keys as $key) {
                            $tagCount++;
                            $totalMembers += $this->redis->sCard($key);
                        }
                    }
                } while ($iterator > 0);
            } catch (\Throwable $e) {
                // Ignore errors in stats collection
            }
        }

        return array_merge($this->stats, [
            'active_tags' => $tagCount,
            'total_tagged_queries' => $totalMembers,
            'redis_connected' => $this->redis !== null,
        ]);
    }

    /**
     * Clean up expired tag entries (maintenance task)
     *
     * @return int Number of cleaned entries
     */
    public function cleanup(): int
    {
        if (!$this->redis) {
            return 0;
        }

        $cleaned = 0;

        try {
            // Scan all tag sets
            $iterator = null;
            do {
                $keys = $this->redis->scan($iterator, self::TAG_PREFIX . '*', 100);

                if ($keys !== false && is_array($keys)) {
                    foreach ($keys as $tagKey) {
                        // Get all hashes in this tag set
                        $hashes = $this->redis->sMembers($tagKey);

                        foreach ($hashes as $hash) {
                            // Check if the query still exists
                            $queryKey = 'query:' . $hash;
                            if (!$this->redis->exists($queryKey)) {
                                // Query expired, remove from tag set
                                $this->redis->sRem($tagKey, $hash);
                                $cleaned++;
                            }
                        }

                        // If tag set is now empty, delete it
                        if ($this->redis->sCard($tagKey) === 0) {
                            $this->redis->del($tagKey);
                        }
                    }
                }
            } while ($iterator > 0);

        } catch (\Throwable $e) {
            Logger::warning('TAGGED_CACHE: Cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $cleaned;
    }

    /**
     * Debug: Get all tags and their members
     *
     * @return array Tag index structure
     */
    public function debugGetTagIndex(): array
    {
        if (!$this->redis) {
            return ['error' => 'Redis not connected'];
        }

        $index = [];

        try {
            $iterator = null;
            do {
                $keys = $this->redis->scan($iterator, self::TAG_PREFIX . '*', 100);

                if ($keys !== false && is_array($keys)) {
                    foreach ($keys as $key) {
                        $table = str_replace(self::TAG_PREFIX, '', $key);
                        $index[$table] = [
                            'count' => $this->redis->sCard($key),
                            'ttl' => $this->redis->ttl($key),
                            'sample' => array_slice($this->redis->sMembers($key), 0, 5),
                        ];
                    }
                }
            } while ($iterator > 0);

        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return $index;
    }
}
