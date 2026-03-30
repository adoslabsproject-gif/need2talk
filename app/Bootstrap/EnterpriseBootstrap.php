<?php

namespace Need2Talk\Bootstrap;

use Need2Talk\Core\Database;
use Need2Talk\Core\EnterpriseCacheFactory;
use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Core\MetricsCollector;
use Need2Talk\Services\Logger;
use Need2Talk\Services\ScalableSessionStorage;

/**
 * Enterprise Bootstrap
 * Initializes all enterprise-grade components for 100k+ users
 *
 * PRESERVES ALL EXISTING FUNCTIONALITY
 * ADDS SCALABILITY LAYERS
 */
class EnterpriseBootstrap
{
    private static bool $initialized = false;

    /** @var array<string, object> Enterprise components registry */
    private static array $components = [];

    /** @var array Performance profiling timers (for debugging) */
    private static array $debugTimers = [];
    private static float $debugStartTime = 0;

    /**
     * Debug timer helper
     */
    private static function debugTimer(string $label): void
    {
        if (!self::$debugStartTime) {
            self::$debugStartTime = microtime(true);
        }
        $now = microtime(true);
        self::$debugTimers[] = [
            'label' => $label,
            'delta' => ($now - (end(self::$debugTimers)['time'] ?? self::$debugStartTime)) * 1000,
            'time' => $now,
        ];
    }

    /**
     * Get debug timers (for profiling)
     */
    public static function getDebugTimers(): array
    {
        return self::$debugTimers;
    }

    /**
     * Initialize enterprise stack
     */
    public static function initialize($config = [])
    {
        self::debugTimer('START initialize()');

        if (self::$initialized) {
            return;
        }

        // ENTERPRISE: This is called FROM bootstrap.php, not standalone
        // NO circular dependency - bootstrap.php must be already loaded

        // 1. Define enterprise constants
        if (!defined('ENTERPRISE_REDIRECT_GLOBALS')) {
            define('ENTERPRISE_REDIRECT_GLOBALS', false); // Default: false for stability
        }
        self::debugTimer('Constants defined');

        // 2. Load configuration with caching (ENTERPRISE OPTIMIZATION: -68ms)
        $configFile = __DIR__ . '/../../config/app.php';
        $configCacheFile = __DIR__ . '/../../storage/cache/config.php';

        $useCache = false;
        if (file_exists($configCacheFile) && file_exists($configFile)) {
            $configMtime = filemtime($configFile);
            $cacheMtime = filemtime($configCacheFile);
            $useCache = ($cacheMtime >= $configMtime);
        }

        if ($useCache) {
            // FAST PATH: Load from OPcache (~10ms)
            $appConfig = include $configCacheFile;
        } else {
            // SLOW PATH: Parse and cache (~78ms first time only)
            $appConfig = require $configFile;

            // Generate cache file
            $cacheContent = "<?php\n// Generated config cache - DO NOT EDIT\n";
            $cacheContent .= "// Generated at: " . date('Y-m-d H:i:s') . "\n";
            $cacheContent .= "return " . var_export($appConfig, true) . ";\n";

            @file_put_contents($configCacheFile, $cacheContent, LOCK_EX);
        }

        $config = array_merge($appConfig, $config);
        self::debugTimer('Config loaded (with cache)');

        // 3. Initialize Redis Session Handler
        self::initializeSessionHandler($config);
        self::debugTimer('initializeSessionHandler()');

        // 4. Initialize Cache Manager
        self::initializeCacheManager($config);
        self::debugTimer('initializeCacheManager()');

        // 4. Initialize Database with caching (SKIP for public routes - lazy loading)
        // Public pages like homepage don't need database - load only when needed
        if (!defined('BOOTSTRAP_MODE') || BOOTSTRAP_MODE !== 'public') {
            self::initializeDatabase($config);
            self::debugTimer('initializeDatabase()');
        } else {
            self::debugTimer('initializeDatabase() - SKIPPED (public mode - lazy loading)');
        }

        // 5. Initialize Metrics Collector (SKIP for public routes - code splitting)
        if (!defined('BOOTSTRAP_MODE') || BOOTSTRAP_MODE !== 'public') {
            self::initializeMetricsCollector($config);
            self::debugTimer('initializeMetricsCollector()');
        } else {
            self::debugTimer('initializeMetricsCollector() - SKIPPED (public mode)');
        }

        // 6. Setup error handlers
        self::setupErrorHandlers();
        self::debugTimer('setupErrorHandlers()');

        // 6. Register shutdown handlers
        self::registerShutdownHandlers();
        self::debugTimer('registerShutdownHandlers()');

        // 7. Setup health check endpoint
        self::setupHealthCheck();
        self::debugTimer('setupHealthCheck()');

        self::$initialized = true;
        // Enterprise Stack initialized successfully

        // Log system status
        self::logSystemStatus();
        self::debugTimer('logSystemStatus()');
        self::debugTimer('COMPLETE initialize()');

        // ============================================================================
        // ENTERPRISE PROFILING: DISABLED IN PRODUCTION
        // ============================================================================
        // Debug profiling completed - optimizations applied, logging disabled
        // To re-enable: uncomment the code below
        // ============================================================================
        /*
        $report = "\n=== ENTERPRISE BOOTSTRAP TIMERS ===\n";
        $prev = self::$debugStartTime;
        foreach (self::$debugTimers as $timer) {
            $delta = round($timer['delta'], 2);
            $total = round(($timer['time'] - self::$debugStartTime) * 1000, 2);
            $report .= sprintf("%-40s: +%6.2fms (total: %6.2fms)\n", $timer['label'], $delta, $total);
        }
        $totalTime = round((microtime(true) - self::$debugStartTime) * 1000, 2);
        $report .= "=== ENTERPRISE BOOTSTRAP TOTAL: {$totalTime}ms ===\n";
        error_log($report);
        */
    }

    /**
     * Get component instance
     */
    public static function getComponent($name)
    {
        return self::$components[$name] ?? null;
    }

    /**
     * Get all components
     */
    public static function getComponents()
    {
        return self::$components;
    }

    /**
     * Get system stats
     */
    public static function getSystemStats()
    {
        $stats = [
            'uptime' => time() - EnterpriseGlobalsManager::getServer('REQUEST_TIME', time()),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'components' => [],
        ];

        // Add component stats with enterprise safety
        if (isset(self::$components['database']) && method_exists(self::$components['database'], 'getStats')) {
            $stats['components']['database'] = self::$components['database']->getStats();
        }

        if (isset(self::$components['cache']) && method_exists(self::$components['cache'], 'getStats')) {
            $stats['components']['cache'] = self::$components['cache']->getStats();
        }

        if (isset(self::$components['session_handler']) && method_exists(self::$components['session_handler'], 'getSessionStats')) {
            $stats['components']['sessions'] = self::$components['session_handler']->getSessionStats();
        }

        return $stats;
    }

    /**
     * Metrics endpoint for monitoring
     */
    public static function handleMetricsRequest()
    {
        if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/metrics') {
            header('Content-Type: text/plain');

            $stats = self::getSystemStats();

            // Prometheus format metrics
            echo "# HELP need2talk_memory_usage_bytes Memory usage in bytes\n";
            echo "# TYPE need2talk_memory_usage_bytes gauge\n";
            echo 'need2talk_memory_usage_bytes ' . $stats['memory_usage'] . "\n";

            echo "# HELP need2talk_memory_peak_bytes Peak memory usage in bytes\n";
            echo "# TYPE need2talk_memory_peak_bytes gauge\n";
            echo 'need2talk_memory_peak_bytes ' . $stats['memory_peak'] . "\n";

            if (isset($stats['components']['database'])) {
                $dbStats = $stats['components']['database'];

                echo "# HELP need2talk_db_queries_total Total database queries\n";
                echo "# TYPE need2talk_db_queries_total counter\n";
                echo 'need2talk_db_queries_total ' . ($dbStats['queries']['total'] ?? 0) . "\n";

                echo "# HELP need2talk_db_cache_hit_ratio Database cache hit ratio\n";
                echo "# TYPE need2talk_db_cache_hit_ratio gauge\n";
                echo 'need2talk_db_cache_hit_ratio ' . ($dbStats['queries']['cache_hit_ratio'] ?? 0) . "\n";
            }

            if (isset($stats['components']['cache'])) {
                $cacheStats = $stats['components']['cache'];

                echo "# HELP need2talk_cache_hit_ratio Overall cache hit ratio\n";
                echo "# TYPE need2talk_cache_hit_ratio gauge\n";
                echo 'need2talk_cache_hit_ratio ' . ($cacheStats['hit_ratio'] ?? 0) . "\n";
            }

            exit;
        }
    }

    /**
     * Initialize Enterprise session handler (ScalableSessionStorage with Redis + PostgreSQL)
     *
     * ENTERPRISE: Uses ScalableSessionStorage for:
     * - Redis primary storage (instant session lookup)
     * - PostgreSQL audit trail (GDPR compliance)
     * - Automatic failover to database sessions if Redis unavailable
     */
    private static function initializeSessionHandler($config)
    {
        // ENTERPRISE v6.5: Skip session handler in CLI mode
        // CLI scripts (cron, workers, training) don't need HTTP sessions
        // This prevents "Headers already sent" warnings and unnecessary Redis/DB calls
        if (PHP_SAPI === 'cli' || defined('SKIP_SESSION_INIT')) {
            return;
        }

        if ($config['session']['driver'] === 'redis') {
            try {
                // ENTERPRISE CRITICAL: Skip if headers already sent
                // This can happen if output was accidentally sent before session init
                if (headers_sent($file, $line)) {
                    Logger::database('warning', 'SESSION: Headers already sent, cannot set session handler', [
                        'file' => $file,
                        'line' => $line,
                        'status' => 'skipped',
                        'reason' => 'headers_already_sent',
                    ]);

                    return;
                }

                // ENTERPRISE CRITICAL: Skip handler setup if session already started
                // This prevents duplicate handler registration which causes write() to never be called
                if (session_status() !== PHP_SESSION_NONE) {
                    Logger::database('warning', 'SESSION: Session already active when EnterpriseBootstrap tried to register handler', [
                        'session_id' => substr(session_id(), 0, 16),
                        'status' => 'skipped',
                        'reason' => 'session_already_active',
                    ]);

                    return;
                }

                // ENTERPRISE: Use ScalableSessionStorage (Redis + PostgreSQL hybrid)
                $sessionHandler = new ScalableSessionStorage();

                // ENTERPRISE: Register session handler BEFORE session starts
                session_set_save_handler($sessionHandler, true);

                // Configure session settings (only if session not started yet)
                if (session_status() === PHP_SESSION_NONE) {
                    ini_set('session.gc_probability', 1);
                    ini_set('session.gc_divisor', 100);
                    ini_set('session.gc_maxlifetime', $config['session']['lifetime'] * 60);
                    ini_set('session.cookie_httponly', $config['session']['http_only']);
                    ini_set('session.cookie_secure', $config['session']['secure']);
                    ini_set('session.cookie_samesite', $config['session']['same_site'] ?? 'Lax');
                    ini_set('session.name', $config['session']['cookie_name']);
                }

                self::$components['session_handler'] = $sessionHandler;

            } catch (\Exception $e) {
                Logger::error('DEFAULT: ❌ ScalableSessionStorage failed', ['error' => $e->getMessage()]);
                Logger::warning('DEFAULT: ⚠️ Falling back to database sessions');

                // Fallback to database sessions
                try {
                    $databaseHandler = new \Need2Talk\Services\DatabaseSessionHandler();
                    session_set_save_handler($databaseHandler, true);
                } catch (\Exception $fallbackException) {
                    Logger::error('DEFAULT: ❌ Database session fallback also failed', [
                        'error' => $fallbackException->getMessage(),
                    ]);
                    // Final fallback: PHP file sessions (default)
                }
            }
        }
    }

    /**
     * Initialize cache manager
     */
    private static function initializeCacheManager($config)
    {
        if ($config['cache']['enabled']) {
            try {
                $cacheConfig = $config['cache']['multilevel'] ?? [];
                $cacheManager = EnterpriseCacheFactory::getInstance([
                    'redis' => $cacheConfig['l3_redis'] ?? [],
                    'memcached' => $cacheConfig['l2_memcached'] ?? [],
                ]);

                self::$components['cache'] = $cacheManager;

                // Make cache globally available (Enterprise approach)
                EnterpriseGlobalsManager::setGlobal('cache', $cacheManager);

                // Cache manager initialized

            } catch (\Exception $e) {
                Logger::error('DEFAULT: ❌ Cache Manager failed', ['error' => $e->getMessage()]);
                // Continue without caching - preserved functionality
            }
        }
    }

    /**
     * Initialize enterprise database
     */
    private static function initializeDatabase($config)
    {
        try {
            $dbConfig = [
                'cache_enabled' => $config['database']['query_cache_enabled'] ?? true,
                'metrics_enabled' => $config['monitoring']['enabled'] ?? true,
                'query_log_enabled' => $config['database']['slow_query_log'] ?? false,
            ];

            $database = new Database($dbConfig);
            self::$components['database'] = $database;

            // Make database globally available (preserve existing code compatibility - Enterprise approach)
            EnterpriseGlobalsManager::setGlobal('db', $database);

            // Enterprise Database initialized

        } catch (\Exception $e) {
            Logger::error('DEFAULT: ❌ Enterprise Database failed', ['error' => $e->getMessage()]);
            // Let existing database code continue to work
        }
    }

    /**
     * Initialize metrics collector for enterprise monitoring
     */
    private static function initializeMetricsCollector($config)
    {
        try {
            $metricsCollector = MetricsCollector::getInstance();
            self::$components['metrics'] = $metricsCollector;

            // Make metrics globally available (Enterprise approach)
            EnterpriseGlobalsManager::setGlobal('metrics', $metricsCollector);

            // Enterprise Metrics Collector initialized

        } catch (\Exception $e) {
            Logger::error('DEFAULT: ❌ Metrics Collector failed', ['error' => $e->getMessage()]);
            // Continue without metrics - graceful degradation
        }
    }

    /**
     * Setup enterprise error handlers
     *
     * NOTE: EnterpriseBootstrap NO LONGER sets exception handlers
     * ErrorHandler service is responsible for exception handling (with animated 500.php page)
     * EnterpriseBootstrap only handles metrics tracking
     */
    private static function setupErrorHandlers()
    {
        // REMOVED: Exception handler setup moved to ErrorHandler service
        // This allows ErrorHandler to show the animated 500.php page
        // EnterpriseBootstrap focuses on infrastructure (cache, db, session, metrics)
    }

    /**
     * Register shutdown handlers
     */
    private static function registerShutdownHandlers()
    {
        register_shutdown_function(function () {
            // Flush any remaining data
            if (isset(self::$components['cache'])) {
                // Cache cleanup is automatic
            }

            // Final error check
            $error = error_get_last();

            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::critical('DEFAULT: Fatal Error on shutdown', $error);
            }
        });
    }

    /**
     * Setup health check endpoint
     */
    private static function setupHealthCheck()
    {
        // If this is a health check request
        if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/health') {
            header('Content-Type: application/json');

            $health = [
                'status' => 'ok',
                'timestamp' => date('c'),
                'components' => [],
            ];

            // Check database with enterprise safety
            if (isset(self::$components['database']) && method_exists(self::$components['database'], 'healthCheck')) {
                $dbHealth = self::$components['database']->healthCheck();
                $health['components']['database'] = is_array($dbHealth) ? ($dbHealth['overall'] ?? false) : $dbHealth;

                if (!(is_array($dbHealth) ? ($dbHealth['overall'] ?? false) : $dbHealth)) {
                    $health['status'] = 'degraded';
                }
            }

            // Check cache with enterprise safety
            if (isset(self::$components['cache']) && method_exists(self::$components['cache'], 'healthCheck')) {
                $cacheHealth = self::$components['cache']->healthCheck();
                $health['components']['cache'] = is_array($cacheHealth) ? ($cacheHealth['overall'] ?? false) : $cacheHealth;
            }

            // Check session handler with enterprise safety
            if (isset(self::$components['session_handler']) && method_exists(self::$components['session_handler'], 'healthCheck')) {
                $health['components']['sessions'] = self::$components['session_handler']->healthCheck();
            }

            // Return appropriate HTTP status
            http_response_code($health['status'] === 'ok' ? 200 : 503);
            echo json_encode($health, JSON_PRETTY_PRINT);
            exit;
        }
    }

    /**
     * Log system status
     */
    private static function logSystemStatus()
    {
        $status = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'extensions' => [
                'redis' => extension_loaded('redis'),
                'memcached' => extension_loaded('memcached'),
                'pdo' => extension_loaded('pdo'),
                'pdo_pgsql' => extension_loaded('pdo_pgsql'),  // ENTERPRISE: PostgreSQL (migrated from MySQL)
            ],
            'components' => array_keys(self::$components),
        ];

        // System status tracked but not logged (too verbose)
    }
}
