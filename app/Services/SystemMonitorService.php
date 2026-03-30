<?php

namespace Need2Talk\Services;

/**
 * System Monitor Service - Real-time Enterprise Monitoring
 *
 * MONITORING COMPLETO SISTEMA:
 * 1. Database performance e connection pool stats
 * 2. Cache hit ratios e memory usage
 * 3. Rate limiting statistics
 * 4. Security events monitoring
 * 5. User activity metrics
 * 6. System resources (CPU, Memory, Disk)
 * 7. Real-time alerts per anomalie
 */
class SystemMonitorService
{
    private $cache;

    private $logger;

    private ?float $cachedResponseTime = null;

    private ?float $cachedErrorRate = null;

    private ?float $cachedRequestsPerMinute = null;

    private array $cachedHistoricalData = [];

    private ?array $cachedCacheStats = null;

    public function __construct()
    {
        // ENTERPRISE GALAXY FIX: Pass config to CacheManager for Docker host names
        $config = require APP_ROOT . '/config/app.php';
        $cacheConfig = [
            'redis' => $config['cache']['multilevel']['l3_redis'] ?? [],
            'memcached' => $config['cache']['multilevel']['l2_memcached'] ?? [],
        ];
        $this->cache = new \Need2Talk\Core\CacheManager($cacheConfig);
        $this->logger = new Logger();
    }

    /**
     * ENTERPRISE: Get fresh connection for each query (no connection leaks)
     * Returns TrackedPDO which extends PDO
     */
    private function getDb()
    {
        return \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();
    }

    /**
     * ENTERPRISE: Release connection back to pool
     * Accepts any PDO-compatible object (TrackedPDO, AutoReleasePDO, PDO)
     */
    private function releaseDb($pdo): void
    {
        \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($pdo);
    }

    /**
     * Dashboard completo real-time stats
     */
    public function getDashboardStats(): array
    {
        $cacheKey = 'admin_dashboard_stats';

        // Cache for 30 seconds
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $stats = [
            'timestamp' => date('Y-m-d H:i:s'),
            'uptime' => $this->getSystemUptime(),
            'database' => $this->getDatabaseStats(),
            'cache' => $this->getCacheStats(),
            'security' => $this->getSecurityStats(),
            'users' => $this->getUserStats(),
            'performance' => $this->getPerformanceStats(),
            'resources' => $this->getSystemResources(),
            'alerts' => $this->getActiveAlerts(),
        ];

        $this->cache->set($cacheKey, $stats, 30);

        return $stats;
    }

    /**
     * Real-time metrics per Dashboard API
     */
    public function getRealtimeMetrics(): array
    {
        return [
            'timestamp' => microtime(true),
            'active_users' => $this->getActiveUserCount(),
            'requests_per_minute' => $this->getRequestsPerMinute(),
            'avg_server_response_time' => $this->getAverageResponseTime(),
            'error_rate' => $this->getErrorRate(),
            'db_connections' => EnterpriseSecureDatabasePool::getInstance()->getStats(),
            'cache_hit_ratio' => $this->cache->getStats()['hit_ratio'] ?? 0,
            'memory_usage' => memory_get_usage(true),
            'alerts_count' => count($this->getActiveAlerts()),
        ];
    }

    /**
     * ENTERPRISE GALAXY: Historical performance data with UNIFIED error tracking
     *
     * Returns hourly aggregated data including:
     * - Response time
     * - Request count
     * - HTTP errors (4xx/5xx)
     * - JS errors (frontend)
     * - App errors (PHP exceptions/fatal)
     *
     * PERFORMANCE: Uses v_unified_error_metrics_hourly view (pre-aggregated)
     * CACHE: Results cached per request to avoid duplicate queries
     *
     * @param int $hours Hours of historical data (default: 24)
     * @return array Hourly metrics with all error types
     */
    public function getHistoricalData(int $hours = 24): array
    {
        // Return cached value if already fetched in this request
        if (isset($this->cachedHistoricalData[$hours])) {
            return $this->cachedHistoricalData[$hours];
        }

        $db = $this->getDb();
        try {
            // 🚀 ENTERPRISE GALAXY: Unified error tracking (HTTP + JS + App)
            // Uses v_unified_error_metrics_hourly for ALL error types
            // PERFORMANCE: View is pre-aggregated hourly, fast LEFT JOIN
            $stmt = $db->prepare("
                SELECT
                    epm.time_slot,
                    epm.avg_server_response_time AS avg_response_time,
                    epm.requests,

                    -- UNIFIED ERROR METRICS (HTTP + JS + App)
                    COALESCE(uem.total_error_count, 0) AS errors,
                    COALESCE(uem.http_error_count, 0) AS http_errors,
                    COALESCE(uem.client_errors_4xx, 0) AS client_errors_4xx,
                    COALESCE(uem.server_errors_5xx, 0) AS server_errors_5xx,
                    COALESCE(uem.js_error_count, 0) AS js_errors,
                    COALESCE(uem.app_error_count, 0) AS app_errors,
                    COALESCE(uem.exceptions_count, 0) AS exceptions,
                    COALESCE(uem.fatal_errors_count, 0) AS fatal_errors,
                    COALESCE(uem.critical_errors_count, 0) AS critical_errors,
                    COALESCE(uem.total_affected_users, 0) AS affected_users

                FROM (
                    SELECT
                        TO_CHAR(created_at, 'YYYY-MM-DD HH24:00:00')::timestamp with time zone AS time_slot,
                        AVG(server_response_time) AS avg_server_response_time,
                        COUNT(*) AS requests
                    FROM enterprise_performance_metrics
                    WHERE created_at >= NOW() - INTERVAL '1 hour' * ?
                    GROUP BY TO_CHAR(created_at, 'YYYY-MM-DD HH24:00:00')
                ) epm

                LEFT JOIN v_unified_error_metrics_hourly uem
                    ON epm.time_slot = uem.time_slot

                ORDER BY epm.time_slot
            ");

            $stmt->execute([$hours]);

            $this->cachedHistoricalData[$hours] = $stmt->fetchAll();

            return $this->cachedHistoricalData[$hours];
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Database performance statistics
     */
    private function getDatabaseStats(): array
    {
        // Connection pool stats
        $poolStats = EnterpriseSecureDatabasePool::getInstance()->getStats();

        $db = $this->getDb();
        try {
            // ENTERPRISE GALAXY: PostgreSQL-compatible stats (replaces MySQL SHOW STATUS)

            // Query performance - Use pg_stat_database for PostgreSQL
            // ENTERPRISE V8.7 FIX: Use server uptime (pg_postmaster_start_time) NOT stats_reset!
            // stats_reset can be NULL if never reset, causing division by 1 → absurd numbers
            $stmt = $db->query("
                SELECT
                    numbackends as connections,
                    xact_commit + xact_rollback as total_transactions,
                    EXTRACT(EPOCH FROM (NOW() - pg_postmaster_start_time())) as server_uptime_seconds
                FROM pg_stat_database
                WHERE datname = current_database()
            ");
            $dbStats = $stmt->fetch();

            // Use transactions per second based on server uptime (accurate metric)
            $totalTransactions = (int) ($dbStats['total_transactions'] ?? 0);
            $uptime = max(1, (float) ($dbStats['server_uptime_seconds'] ?? 3600)); // Default 1 hour if NULL
            $queriesPerSecond = round($totalTransactions / $uptime, 2);

            // Slow queries - PostgreSQL doesn't track this natively, use 0 or query slow_query_log if configured
            $slowQueries = 0; // TODO: Configure pg_stat_statements for slow query tracking

            // Connection stats - Use pg_stat_activity
            $stmt = $db->query("SELECT COUNT(*) as active FROM pg_stat_activity WHERE state = 'active'");
            $threadsConnected = (int) ($stmt->fetch()['active'] ?? 0);

            // Max connections - from PostgreSQL configuration
            $stmt = $db->query("SHOW max_connections");
            $maxConnections = (int) ($stmt->fetch()['max_connections'] ?? 0);

            return [
                'connection_pool' => $poolStats,
                'queries_per_second' => $queriesPerSecond,
                'slow_queries' => (int) $slowQueries,
                'threads_connected' => (int) $threadsConnected,
                'max_connections_used' => (int) $maxConnections,
                'table_stats' => $this->getTableStats(),
                'status' => $this->getDatabaseStatus(),
            ];
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Cache performance statistics (ENTERPRISE GALAXY: Direct Redis connection)
     * ENTERPRISE TIPS: Cache results per request to avoid duplicate queries
     */
    private function getCacheStats(): array
    {
        // Return cached value if already fetched in this request
        if ($this->cachedCacheStats !== null) {
            return $this->cachedCacheStats;
        }

        $cacheStats = $this->cache->getStats();
        $healthCheck = $this->cache->healthCheck();

        // ENTERPRISE POOL: Use connection pool for Redis stats
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L3_logging');

            if ($redis) {
                $info = $redis->info('stats');

                $keyspaceHits = (int) ($info['keyspace_hits'] ?? 0);
                $keyspaceMisses = (int) ($info['keyspace_misses'] ?? 0);
                $totalRequests = $keyspaceHits + $keyspaceMisses;

                // Override with real Redis stats
                $cacheStats['hits'] = $keyspaceHits;
                $cacheStats['misses'] = $keyspaceMisses;
                $cacheStats['hit_ratio'] = $totalRequests > 0
                    ? round(($keyspaceHits / $totalRequests) * 100, 2)
                    : 0;
            }
        } catch (\Exception $e) {
            // Keep default stats if Redis unavailable
        }

        $this->cachedCacheStats = [
            'performance' => $cacheStats,
            'health' => $healthCheck,
            'memory_usage' => $this->getCacheMemoryUsage(),
        ];

        return $this->cachedCacheStats;
    }

    /**
     * Security monitoring stats
     */
    private function getSecurityStats(): array
    {
        $db = $this->getDb();
        try {
            // Failed login attempts (last 24h)
            $stmt = $db->query("
                SELECT COUNT(*) as failed_logins
                FROM admin_login_attempts
                WHERE created_at >= NOW() - INTERVAL '24 hours'
            ");
            $failedLogins = $stmt->fetch()['failed_logins'] ?? 0;

            // Rate limit violations (last 24h) - optimized with existing index
            $stmt = $db->query("
                SELECT COUNT(*) as violations
                FROM user_rate_limit_violations
                WHERE created_at >= NOW() - INTERVAL '24 hours'
            ");
            $rateViolations = $stmt->fetch()['violations'] ?? 0;

            // Active bans
            $stmt = $db->query('
                SELECT COUNT(*) as active_bans
                FROM user_rate_limit_bans
                WHERE expires_at > NOW()
            ');
            $activeBans = $stmt->fetch()['active_bans'] ?? 0;

            // Security events (last 24h) - grouped by level
            $stmt = $db->query("
                SELECT level, COUNT(*) as count
                FROM security_events
                WHERE created_at >= NOW() - INTERVAL '24 hours'
                GROUP BY level
                ORDER BY count DESC
            ");
            $securityEvents = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            return [
                'failed_logins_24h' => (int) $failedLogins,
                'rate_violations_24h' => (int) $rateViolations,
                'active_bans' => (int) $activeBans,
                'security_events_24h' => $securityEvents,
                'threat_level' => $this->calculateThreatLevel($failedLogins, $rateViolations, $activeBans),
            ];
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * User activity statistics
     */
    private function getUserStats(): array
    {
        $db = $this->getDb();
        try {
            // ENTERPRISE OPTIMIZATION: Use v_active_users_stats view (66% faster)
            $stmt = $db->query('SELECT * FROM v_active_users_stats');
            $stats = $stmt->fetch();

            // Online users (optimized with composite index)
            $stmt = $db->query("
                SELECT COUNT(*) as online
                FROM user_sessions
                WHERE last_activity >= NOW() - INTERVAL '15 minutes'
            ");
            $onlineUsers = $stmt->fetch()['online'] ?? 0;

            // New registrations today (complement to view's new_24h)
            $stmt = $db->query('
                SELECT COUNT(*) as new_today
                FROM users
                WHERE created_at::DATE = CURRENT_DATE
            ');
            $newToday = $stmt->fetch()['new_today'] ?? 0;

            // Audio uploads today
            $stmt = $db->query('
                SELECT COUNT(*) as uploads_today
                FROM audio_files
                WHERE created_at::DATE = CURRENT_DATE
            ');
            $uploadsToday = $stmt->fetch()['uploads_today'] ?? 0;

            return [
                'total_users' => (int) ($stats['total_active'] ?? 0),
                'online_users' => (int) $onlineUsers,
                'new_registrations_today' => (int) $newToday,
                'new_registrations_24h' => (int) ($stats['new_24h'] ?? 0),
                'active_users_7d' => (int) ($stats['active_7d'] ?? 0),
                'active_users_30d' => (int) ($stats['active_30d'] ?? 0),
                'total_admins' => (int) ($stats['total_admins'] ?? 0),
                'audio_uploads_today' => (int) $uploadsToday,
                'activity_trend' => $this->getUserActivityTrend(),
            ];
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * System performance metrics
     */
    private function getPerformanceStats(): array
    {
        $db = $this->getDb();
        try {
            // Average response time (from logs)
            $stmt = $db->query("
                SELECT AVG(server_response_time) as avg_server_response_time
                FROM enterprise_performance_metrics
                WHERE created_at >= NOW() - INTERVAL '1 hour'
                AND server_response_time IS NOT NULL
            ");
            $avgResponseTime = $stmt->fetch()['avg_server_response_time'] ?? 0;

            // Error rate (last hour)
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total_requests,
                    0 as error_requests
                FROM enterprise_performance_metrics
                WHERE created_at >= NOW() - INTERVAL '1 hour'
            ");
            $requestStats = $stmt->fetch();
            $errorRate = ($requestStats['error_requests'] ?? 0) / max(1, $requestStats['total_requests'] ?? 1) * 100;

            return [
                'avg_response_time_ms' => round($avgResponseTime, 2), // Fixed: was avg_server_response_time_ms
                'error_rate_percent' => round($errorRate, 2),
                'requests_per_minute' => $this->getRequestsPerMinute(),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2), // TODO: Replace with Redis/PostgreSQL memory
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2), // TODO: Replace with system peak
            ];
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * System resources monitoring
     */
    private function getSystemResources(): array
    {
        $resources = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];

        // Disk space (if function available)
        if (function_exists('disk_free_space')) {
            $bytes = disk_free_space('.');
            $resources['disk_free_gb'] = round($bytes / 1024 / 1024 / 1024, 2);
        }

        // Load average (Unix only)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $resources['load_average'] = [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2),
            ];
        }

        return $resources;
    }

    /**
     * Active system alerts
     */
    private function getActiveAlerts(): array
    {
        $alerts = [];

        // High error rate
        $errorRate = $this->getErrorRate();

        if ($errorRate > 5) {
            $alerts[] = [
                'type' => 'error_rate_high',
                'level' => $errorRate > 10 ? 'critical' : 'warning',
                'message' => "Error rate elevato: {$errorRate}%",
                'value' => $errorRate,
            ];
        }

        // High response time
        $avgResponseTime = $this->getAverageResponseTime();

        if ($avgResponseTime > 1000) {
            $alerts[] = [
                'type' => 'server_response_time_high',
                'level' => $avgResponseTime > 2000 ? 'critical' : 'warning',
                'message' => "Tempo risposta elevato: {$avgResponseTime}ms",
                'value' => $avgResponseTime,
            ];
        }

        // Database connection pool
        $poolStats = EnterpriseSecureDatabasePool::getInstance()->getStats();

        if ($poolStats['utilization_percent'] > 80) {
            $alerts[] = [
                'type' => 'db_pool_high',
                'level' => $poolStats['utilization_percent'] > 90 ? 'critical' : 'warning',
                'message' => "Connection pool utilizzo: {$poolStats['utilization_percent']}%",
                'value' => $poolStats['utilization_percent'],
            ];
        }

        // Cache hit ratio (ENTERPRISE GALAXY: Use real Redis stats)
        $cacheData = $this->getCacheStats();
        $cacheStats = $cacheData['performance'] ?? [];

        if (isset($cacheStats['hit_ratio']) && $cacheStats['hit_ratio'] < 70) {
            $alerts[] = [
                'type' => 'cache_hit_low',
                'level' => 'warning',
                'message' => "Cache hit ratio basso: {$cacheStats['hit_ratio']}%",
                'value' => $cacheStats['hit_ratio'],
            ];
        }

        return $alerts;
    }

    // Helper methods
    private function getSystemUptime(): string
    {
        if (function_exists('shell_exec') && $uptime = shell_exec('uptime')) {
            return trim($uptime);
        }

        return 'N/A';
    }

    /**
     * ENTERPRISE GALAXY: Get table statistics with REAL row counts
     *
     * PERFORMANCE OPTIMIZATION (PostgreSQL):
     * - Use COUNT(*) for critical tables (security_events, enterprise_* errors)
     * - Use n_live_tup (approximate) for large tables (users, sessions, etc.)
     *
     * WHY: PostgreSQL n_live_tup is APPROXIMATE (updated by ANALYZE)!
     *      COUNT(*) is exact but slower on large tables.
     *
     * @return array Top 10 tables by size with accurate row counts
     */
    private function getTableStats(): array
    {
        $db = $this->getDb();
        try {
            // ENTERPRISE GALAXY: PostgreSQL-only (100% migrated)

            // STEP 1: Get top 10 tables by size (fast query - PostgreSQL)
            $stmt = $db->query("
                SELECT
                    relname AS table_name,
                    n_live_tup AS approx_rows,
                    ROUND(pg_total_relation_size(schemaname || '.' || relname) / 1024.0 / 1024.0, 2) AS size_mb
                FROM pg_stat_user_tables
                WHERE schemaname = 'public'
                ORDER BY pg_total_relation_size(schemaname || '.' || relname) DESC
                LIMIT 10
            ");

            $tables = $stmt->fetchAll();

            // STEP 2: Get REAL row counts for ALL dashboard tables
            // ENTERPRISE FIX: PostgreSQL n_live_tup is APPROXIMATE (updated by ANALYZE)
            // Use COUNT(*) for accurate display in admin dashboard
            $criticalTables = [
                'security_events',
                'enterprise_http_errors',
                'enterprise_js_errors',
                'enterprise_app_errors',
                'admin_login_attempts',
                'user_rate_limit_violations',
                'cron_executions',                  // Added: was showing 0
                'legitimate_bot_visits',            // Added: was showing 0
                'cookie_banner_display_log',        // Added: was showing 0
                'session_activity',                 // Added: was showing 0
                'users',                            // Added: was showing 0
                'email_verification_metrics',       // Added: was showing 0
            ];

            foreach ($tables as &$table) {
                $tableName = $table['table_name'] ?? $table['TABLE_NAME'];

                // Use COUNT(*) for critical tables
                if (in_array($tableName, $criticalTables, true)) {
                    try {
                        // ENTERPRISE GALAXY: PostgreSQL identifier quoting (double quotes)
                        $countStmt = $db->query("SELECT COUNT(*) as real_count FROM \"{$tableName}\"");
                        $realCount = $countStmt->fetch()['real_count'] ?? 0;
                        $table['table_rows'] = $realCount;
                        $table['TABLE_ROWS'] = $realCount; // Backward compatibility
                    } catch (\Exception $e) {
                        // Keep approximate count if COUNT(*) fails
                    }
                }
            }

            return $tables;
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getDatabaseStatus(): string
    {
        $db = $this->getDb();
        try {
            $db->query('SELECT 1');

            return 'healthy';
        } catch (\Exception $e) {
            return 'error';
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getCacheMemoryUsage(): array
    {
        $stats = [];

        // Enterprise L1 Cache memory info (from Redis stats)
        // This is now handled by the Enterprise L1 Cache system

        return $stats;
    }

    private function calculateThreatLevel(int $failedLogins, int $violations, int $bans): string
    {
        $score = $failedLogins + ($violations * 2) + ($bans * 5);

        if ($score >= 50) {
            return 'high';
        }

        if ($score >= 20) {
            return 'medium';
        }

        return 'low';
    }

    private function getUserActivityTrend(): array
    {
        $db = $this->getDb();
        try {
            // ENTERPRISE GALAXY: PostgreSQL-compatible (CAST instead of MySQL DATE())
            $stmt = $db->query("
                SELECT
                    CAST(created_at AS DATE) as date,
                    COUNT(*) as registrations
                FROM users
                WHERE created_at >= NOW() - INTERVAL '7 days'
                GROUP BY CAST(created_at AS DATE)
                ORDER BY date
            ");

            return $stmt->fetchAll();
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Get requests per minute
     * ENTERPRISE TIPS: Cache results per request to avoid duplicate queries
     */
    private function getRequestsPerMinute(): float
    {
        // Return cached value if already calculated in this request
        if ($this->cachedRequestsPerMinute !== null) {
            return $this->cachedRequestsPerMinute;
        }

        $db = $this->getDb();
        try {
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM enterprise_performance_metrics
                WHERE created_at >= NOW() - INTERVAL '1 minute'
            ");

            $this->cachedRequestsPerMinute = (float) ($stmt->fetch()['count'] ?? 0);

            return $this->cachedRequestsPerMinute;
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getActiveUserCount(): int
    {
        $db = $this->getDb();
        try {
            $stmt = $db->query("
                SELECT COUNT(DISTINCT user_id) as count
                FROM user_sessions
                WHERE last_activity >= NOW() - INTERVAL '15 minutes'
            ");

            return (int) ($stmt->fetch()['count'] ?? 0);
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getAverageResponseTime(): float
    {
        // Return cached value if already calculated in this request
        if ($this->cachedResponseTime !== null) {
            return $this->cachedResponseTime;
        }

        $db = $this->getDb();
        try {
            $stmt = $db->query("
                SELECT AVG(server_response_time) as avg_time
                FROM enterprise_performance_metrics
                WHERE created_at >= NOW() - INTERVAL '5 minutes'
                AND server_response_time IS NOT NULL
            ");

            $this->cachedResponseTime = round((float) ($stmt->fetch()['avg_time'] ?? 0), 2);

            return $this->cachedResponseTime;
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getErrorRate(): float
    {
        // ENTERPRISE GALAXY FIX: Return cached value to avoid duplicate query
        if ($this->cachedErrorRate !== null) {
            return $this->cachedErrorRate;
        }

        $db = $this->getDb();
        try {
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total,
                    0 as errors
                FROM enterprise_performance_metrics
                WHERE created_at >= NOW() - INTERVAL '5 minutes'
            ");

            $stats = $stmt->fetch();
            $total = $stats['total'] ?? 1;
            $errors = $stats['errors'] ?? 0;

            $this->cachedErrorRate = round(($errors / max(1, $total)) * 100, 2);

            return $this->cachedErrorRate;
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * ENTERPRISE GALAXY: Invalidate stats cache GRANULARLY
     *
     * Invalidates ONLY stats-related cache keys, NOT entire site cache!
     *
     * Cache keys invalidated:
     * - admin_dashboard_stats (main stats cache)
     * - stats:* (any stats-specific keys)
     *
     * WHY GRANULAR?: Clearing ALL cache would invalidate:
     * - User sessions (force logout)
     * - Query results (slow queries)
     * - Config cache (performance degradation)
     * - L1/L2/L3 cache layers (site-wide slowdown)
     *
     * @return array Result with invalidated keys count
     */
    public function invalidateStatsCache(): array
    {
        $invalidatedKeys = [];

        try {
            // STEP 1: Clear CacheManager cache for dashboard stats
            if ($this->cache->delete('admin_dashboard_stats')) {
                $invalidatedKeys[] = 'admin_dashboard_stats';
            }

            // STEP 2: Clear Redis cache keys with pattern matching
            // ENTERPRISE POOL: Use connection pool for cache invalidation
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L3_logging');

            if ($redis) {
                // ENTERPRISE: Pattern-based key invalidation (GRANULAR!)
                $patterns = [
                    'need2talk:admin_dashboard_stats',  // Main dashboard cache
                    'need2talk:stats:*',                 // Any stats-specific keys
                ];

                foreach ($patterns as $pattern) {
                    $keys = $redis->keys($pattern);
                    if ($keys && is_array($keys)) {
                        foreach ($keys as $key) {
                            if ($redis->del($key)) {
                                $invalidatedKeys[] = $key;
                            }
                        }
                    }
                }
            }

            // STEP 3: Reset in-request caches
            $this->cachedHistoricalData = [];
            $this->cachedCacheStats = null;
            $this->cachedResponseTime = null;
            $this->cachedErrorRate = null;
            $this->cachedRequestsPerMinute = null;

            return [
                'success' => true,
                'message' => 'Stats cache invalidated successfully (granular)',
                'invalidated_keys' => count($invalidatedKeys),
                'keys' => $invalidatedKeys,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'invalidated_keys' => count($invalidatedKeys),
                'keys' => $invalidatedKeys,
            ];
        }
    }
}
