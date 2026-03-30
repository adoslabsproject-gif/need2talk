<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Services\EnterpriseEnvironmentManager;
use Need2Talk\Services\Logger;

/**
 * Enterprise Logging Controller
 *
 * Raccoglie e analizza errori JavaScript in tempo reale
 * Progettato per migliaia di utenti concorrenti con performance elevate
 * PERFORMANCE OPTIMIZED: Uses connection pool instead of fresh connections
 */
class EnterpriseLoggingController extends BaseController
{
    private EnterpriseEnvironmentManager $envManager;

    public function __construct()
    {
        parent::__construct();
        $this->envManager = EnterpriseEnvironmentManager::getInstance();
    }

    /**
     * Handle enterprise logging requests
     */
    public function handleLogging(): void
    {
        // ENTERPRISE LOGGING: This endpoint should accept requests without CSRF for error logging
        // CORS headers for AJAX requests
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Enterprise-Monitor');

        if (EnterpriseGlobalsManager::getServer('REQUEST_METHOD') === 'OPTIONS') {
            $this->json(['success' => true], 200);

            return;
        }

        if (EnterpriseGlobalsManager::getServer('REQUEST_METHOD') !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);

            return;
        }

        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data) {
                $this->json(['error' => 'Invalid JSON'], 400);

                return;
            }

            // Process different types of logs
            switch ($data['type'] ?? 'unknown') {
                case 'error_report':
                    $this->handleErrorReport($data);
                    break;

                case 'performance_metrics':
                    $this->handlePerformanceMetrics($data);
                    break;

                default:
                    $this->handleGenericLog($data);
                    break;
            }

            $this->json(['status' => 'success', 'logged' => true]);

        } catch (\Exception $e) {
            Logger::error('Enterprise logging failed', [
                'error' => $e->getMessage(),
                'input' => $input ?? null,
            ]);

            $this->json(['error' => 'Logging failed'], 500);
        }
    }

    /**
     * Get recent errors from database (for test dashboard + admin panel)
     * ENTERPRISE GALAXY ULTIMATE: Fresh PDO bypassing ALL cache layers for real-time data
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    public function getRecentErrors(): void
    {
        // ENTERPRISE TIPS: Disable HTTP caching for real-time data
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        try {
            // Get pagination parameters from query string
            $limit = (int) ($_GET['limit'] ?? 50);
            $page = (int) ($_GET['page'] ?? 1);

            // Validate limit (25, 50, or 100)
            if (!in_array($limit, [25, 50, 100])) {
                $limit = 50;
            }

            // Validate page (min 1)
            if ($page < 1) {
                $page = 1;
            }

            // Calculate offset
            $offset = ($page - 1) * $limit;

            // ENTERPRISE NUCLEAR OPTION: Create completely fresh PDO connection bypassing ALL cache layers
            // This ensures we ALWAYS get real-time data from database, no stale cache
            // CRITICAL for test pages that need to show newly generated errors immediately
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,  // Force real prepared statements (no query cache)
            ]);

            // Get total count - Direct DB query, no cache
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM enterprise_js_errors');
            $countResult = $stmt->fetch(\PDO::FETCH_ASSOC);
            $total = (int) ($countResult['total'] ?? 0);

            // Calculate total pages
            $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

            // Ensure page doesn't exceed total pages
            if ($page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $limit;
            }

            // Get errors
            $stmt = $pdo->prepare('
                SELECT id, error_type, message, filename, line_number,
                       column_number, severity, created_at
                FROM enterprise_js_errors
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            $errors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            $this->json([
                'success' => true,
                'errors' => $errors,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get recent errors', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'message' => 'Could not retrieve errors',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get error statistics for enterprise dashboard
     * ENTERPRISE 2025: Cached queries for 100k+ users performance
     */
    public function getErrorStats(): array
    {
        try {
            // ENTERPRISE: Use Database class with multi-level caching (5min TTL - HOT data)

            // Error count by severity (last 24 hours) - CACHED, uses idx_created_severity
            $errorCounts = db()->query("
                SELECT severity, COUNT(*) as count
                FROM enterprise_js_errors
                WHERE created_at >= NOW() - INTERVAL '24 hours'
                GROUP BY severity
            ", [], ['cache_ttl' => 'short']);

            // Convert to key-pair for easier access
            $errorCountsMap = [];

            foreach ($errorCounts as $row) {
                $errorCountsMap[$row['severity']] = $row['count'];
            }

            // Most common errors (last 24 hours) - CACHED, uses idx_message_prefix
            $commonErrors = db()->query("
                SELECT message, COUNT(*) as count
                FROM enterprise_js_errors
                WHERE created_at >= NOW() - INTERVAL '24 hours'
                GROUP BY message
                ORDER BY count DESC
                LIMIT 10
            ", [], ['cache_ttl' => 'short']);

            // Performance averages (last 24 hours) - CACHED, uses idx_created_at
            $performanceAvg = db()->findOne("
                SELECT
                    AVG(page_load_time) as avg_page_load,
                    AVG(server_response_time) as avg_server_response,
                    COUNT(*) as total_measurements
                FROM enterprise_performance_metrics
                WHERE created_at >= NOW() - INTERVAL '24 hours'
            ", [], ['cache_ttl' => 'short']);

            return [
                'error_counts' => $errorCountsMap,
                'common_errors' => $commonErrors,
                'performance_averages' => $performanceAvg,
                'timestamp' => date('c'),
            ];

        } catch (\Exception $e) {
            return [
                'error' => 'Could not retrieve statistics',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle JavaScript error reports
     * ENTERPRISE GALAXY ULTIMATE: Pure PSR-3 system with configurable database filtering
     */
    private function handleErrorReport(array $data): void
    {
        $errorData = $data['data'] ?? [];
        $context = $data['context'] ?? [];

        $logContext = [
            'error_type' => $errorData['type'] ?? 'unknown',
            'message' => $errorData['message'] ?? '',
            'filename' => $errorData['filename'] ?? '',
            'line' => $errorData['lineno'] ?? 0,
            'column' => $errorData['colno'] ?? 0,
            'stack' => $errorData['stack'] ?? '',
            'url' => $context['url'] ?? '',
            'user_agent' => $context['userAgent'] ?? '',
            'viewport' => $context['viewport'] ?? [],
            'user_id' => $context['userId'] ?? null,
            'timestamp' => $errorData['timestamp'] ?? date('c'),
        ];

        // ENTERPRISE GALAXY: Check if explicit PSR-3 level is provided (for testing)
        // If provided, use it directly. Otherwise, use automatic severity categorization.
        if (isset($data['psr3_level']) && in_array($data['psr3_level'], ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'])) {
            // TEST MODE: Use explicit PSR-3 level from test suite
            $psr3Level = $data['psr3_level'];
        } else {
            // PRODUCTION MODE: Automatic PSR-3 severity categorization
            $psr3Level = $this->categorizeErrorSeverityPsr3($errorData);
        }

        // ENTERPRISE GALAXY ULTIMATE: Independent file and database filtering
        // - File logging: Respects js_errors channel level (dynamic admin configuration)
        // - Database storage: Respects db_filter config (admin_settings: js_errors_db_filter_config)
        // CRITICAL: These are COMPLETELY INDEPENDENT - one does NOT affect the other!

        $shouldLogToFile = should_log('js_errors', $psr3Level);
        $shouldLogToDatabase = !$this->shouldFilterFromDatabase($psr3Level);

        // ENTERPRISE TIPS: If NEITHER file NOR database want this log, skip completely
        if (!$shouldLogToFile && !$shouldLogToDatabase) {
            // Error is below BOTH thresholds - discard completely
            return;
        }

        // ENTERPRISE GALAXY: Log to FILE (if channel level allows)
        if ($shouldLogToFile) {
            $levelMessages = [
                'emergency' => 'EMERGENCY: System is unusable',
                'alert' => 'ALERT: Immediate action required',
                'critical' => 'Critical JavaScript error detected',
                'error' => 'JavaScript error',
                'warning' => 'JavaScript warning',
                'notice' => 'JavaScript notice',
                'info' => 'JavaScript info',
                'debug' => 'JavaScript debug',
            ];

            $message = $levelMessages[$psr3Level] ?? 'JavaScript log';
            Logger::jsError($psr3Level, $message, $logContext);

            // Alert for critical levels (emergency, alert, critical)
            if (in_array($psr3Level, ['emergency', 'alert', 'critical'])) {
                $this->alertCriticalError($logContext, $psr3Level);
            }
        }

        // ENTERPRISE GALAXY ULTIMATE: Store in DATABASE (if filter allows)
        if ($shouldLogToDatabase) {
            $this->storeErrorInDatabase($logContext, $psr3Level);
        }
    }

    /**
     * Handle performance metrics
     */
    private function handlePerformanceMetrics(array $data): void
    {
        $metrics = $data['data'] ?? [];
        $context = $data['context'] ?? [];

        $performanceData = [
            'page_load_time' => $metrics['pageLoadTime'] ?? 0,
            'dom_ready_time' => $metrics['domReadyTime'] ?? 0,
            'first_byte_time' => $metrics['firstByteTime'] ?? 0,
            'dns_lookup_time' => $metrics['dnsLookupTime'] ?? 0,
            'connect_time' => $metrics['connectTime'] ?? 0,
            'server_response_time' => $metrics['serverResponseTime'] ?? 0,
            'url' => $context['url'] ?? '',
            'user_agent' => $context['userAgent'] ?? '',
            'user_id' => $context['userId'] ?? null,
            'timestamp' => date('c'),
        ];

        // Performance alerts
        if ($metrics['pageLoadTime'] > 5000) {
            Logger::performance('warning', 'Slow page load detected', (float) $metrics['pageLoadTime'], $performanceData);
        }

        if ($metrics['serverResponseTime'] > 2000) {
            Logger::performance('warning', 'Slow server response detected', (float) $metrics['serverResponseTime'], $performanceData);
        }

        // Store performance data (logging disabled - too verbose)
        $this->storePerformanceMetrics($performanceData);
    }

    /**
     * Handle generic logs
     */
    private function handleGenericLog(array $data): void
    {
        // Generic logs disabled - too verbose
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Categorize error severity using PURE PSR-3 levels
     * Returns one of: emergency, alert, critical, error, warning, notice, info, debug
     */
    private function categorizeErrorSeverityPsr3(array $errorData): string
    {
        $type = $errorData['type'] ?? '';
        $message = strtolower($errorData['message'] ?? '');

        // EMERGENCY: System is unusable (complete failure)
        $emergencyPatterns = [
            'fatal error',
            'system crash',
            'out of memory',
            'maximum call stack',
        ];
        foreach ($emergencyPatterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $message)) {
                return 'emergency';
            }
        }

        // ALERT: Action must be taken immediately
        if ($type === 'security_violation' || strpos($message, 'security') !== false) {
            return 'alert';
        }

        // CRITICAL: Critical conditions (major errors)
        $criticalPatterns = [
            'uncaught',
            'reference.*not defined',
            'cannot read.*undefined',
            'cannot read properties',
            'is not defined',
            'is not a function',
        ];
        foreach ($criticalPatterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $message)) {
                return 'critical';
            }
        }

        // ERROR: Runtime errors (non-critical)
        if ($type === 'unhandled_promise_rejection' || $type === 'resource_error' || $type === 'error') {
            return 'error';
        }

        // WARNING: Warning conditions
        if (strpos($message, 'deprecated') !== false || strpos($message, 'warning') !== false) {
            return 'warning';
        }

        // NOTICE: Normal but significant conditions
        if ($type === 'console.warn' || $type === 'warn') {
            return 'notice';
        }

        // INFO: Informational messages
        if ($type === 'console.info' || $type === 'info' || $type === 'console.log' || $type === 'log') {
            return 'info';
        }

        // DEBUG: Debug-level messages (lowest priority)
        if ($type === 'console.debug' || $type === 'debug') {
            return 'debug';
        }

        // DEFAULT: Treat unknown errors as 'error' level (safe default)
        return 'error';
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Store error in database
     *
     * NOTE: Filter check is done in handleErrorReport() before calling this method.
     * This method assumes the error SHOULD be stored (filter already passed).
     *
     * @param array $errorData Error context
     * @param string $psr3Level PSR-3 level (debug, info, notice, warning, error, critical, alert, emergency)
     */
    private function storeErrorInDatabase(array $errorData, string $psr3Level): void
    {
        try {
            // ENTERPRISE: Use Database class for automatic cache invalidation
            db()->execute('
                INSERT INTO enterprise_js_errors
                (error_type, message, filename, line_number, column_number, stack_trace,
                 page_url, user_agent, user_id, severity, error_context, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ', [
                $errorData['error_type'],
                $errorData['message'],
                $errorData['filename'],
                $errorData['line'],
                $errorData['column'],
                $errorData['stack'],
                $errorData['url'],
                $errorData['user_agent'],
                $errorData['user_id'],
                $psr3Level, // Now storing PSR-3 level directly (compatible with ENUM)
                json_encode($errorData),
            ], [
                'invalidate_cache' => ['enterprise_js_errors_stats'], // Invalida cache stats
            ]);

        } catch (\Exception $e) {
            // ENTERPRISE GALAXY: Log JS error storage failures to js_errors channel
            Logger::jsError('error', 'Failed to store JavaScript error in database', [
                'error' => $e->getMessage(),
                'error_data' => $errorData,
                'psr3_level' => $psr3Level,
            ]);
        }
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Check if error should be filtered from database storage
     *
     * Configurable via admin_settings: js_errors_db_filter_config
     *
     * @param string $psr3Level PSR-3 severity level
     * @return bool True if should skip database storage
     */
    private function shouldFilterFromDatabase(string $psr3Level): bool
    {
        static $filterConfig = null;
        static $lastInvalidationCheck = 0;
        static $lastRedisCheckTime = 0;

        $now = microtime(true);

        // ENTERPRISE GALAXY ULTIMATE: Check Redis for cache invalidation timestamp
        // Check every 60 seconds to sync with admin panel changes
        // This allows instant config updates across ALL PHP processes without OPcache clearing
        if (($now - $lastRedisCheckTime) >= 60.0) {
            try {
                $redisManager = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
                $redis = $redisManager->getConnection('L1_cache');
                if ($redis) {
                    $invalidationTimestamp = $redis->get('js_errors:db_filter:invalidation_timestamp');

                    // If invalidation timestamp changed, clear static cache immediately
                    if ($invalidationTimestamp && $invalidationTimestamp > $lastInvalidationCheck) {
                        $filterConfig = null; // Force reload on next check
                        $lastInvalidationCheck = $invalidationTimestamp;
                    }
                }
                $lastRedisCheckTime = $now;
            } catch (\Throwable $e) {
                // Redis unavailable - continue with static cache
                $lastRedisCheckTime = $now;
            }
        }

        // Load filter config from admin_settings (cached in static var for performance)
        if ($filterConfig === null) {
            try {
                // ENTERPRISE GALAXY ULTIMATE: Bypass query cache with unique comment
                // This ensures we ALWAYS read fresh data from DB after admin panel updates
                // Same pattern as LoggingConfigService::loadConfigFromDatabase()
                $cacheBypass = '/* NOCACHE-' . microtime(true) . ' */';
                $setting = db()->findOne(
                    "SELECT {$cacheBypass} setting_value FROM admin_settings WHERE setting_key = 'js_errors_db_filter_config'",
                    [],
                    ['cache' => false] // Force no cache
                );

                if ($setting) {
                    $filterConfig = json_decode($setting['setting_value'], true);
                } else {
                    // DEFAULT: Filter enabled, min level = error (only error, critical, alert, emergency)
                    $filterConfig = [
                        'enabled' => true,
                        'min_level' => 'error',
                    ];
                }
            } catch (\Exception $e) {
                // Fallback: Enable filter with error level (safe default)
                $filterConfig = [
                    'enabled' => true,
                    'min_level' => 'error',
                ];
            }
        }

        // If filter disabled, store everything
        if (!($filterConfig['enabled'] ?? true)) {
            return false;
        }

        // PSR-3 level hierarchy (RFC 5424)
        // IMPORTANT: Lower number = HIGHER severity
        $levelPriority = [
            'debug' => 7,      // Least severe
            'info' => 6,
            'notice' => 5,
            'warning' => 4,
            'error' => 3,
            'critical' => 2,
            'alert' => 1,
            'emergency' => 0,  // Most severe
        ];

        $minLevel = $filterConfig['min_level'] ?? 'error';
        $minPriority = $levelPriority[$minLevel] ?? 3;
        $currentPriority = $levelPriority[$psr3Level] ?? 7;

        // Filter OUT if current level is HIGHER priority number (lower severity)
        // Example: if min=alert(1):
        //   - emergency(0) < alert(1) = MORE SEVERE = STORE (return false)
        //   - critical(2) > alert(1) = LESS SEVERE = FILTER (return true)
        return $currentPriority > $minPriority;
    }

    /**
     * Store performance metrics
     * ENTERPRISE 2025: Uses Database class with cache invalidation
     *
     * 🚀 ENTERPRISE GALAXY 2025: ADMIN PAGES FILTERING
     * Admin pages are NEVER stored in performance metrics to avoid:
     * - Data pollution (admin actions vs user experience)
     * - Database overload (admin panel generates many requests)
     * - Skewed analytics (admin sessions are not representative of user traffic)
     */
    private function storePerformanceMetrics(array $metrics): void
    {
        // 🚫 ENTERPRISE GALAXY: SKIP ADMIN PAGES (server-side protection)
        // This is a DOUBLE PROTECTION: client-side (JS) + server-side (PHP)
        // Client may be bypassed, server NEVER bypassed
        $url = $metrics['url'] ?? '';
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        // Skip admin routes: /admin, /admin_*, /x7f9k2m8q1 (Filament legacy)
        if (
            str_starts_with($path, '/admin') ||
            preg_match('/^\/[a-z0-9]{10}$/i', $path)
        ) {
            // Admin page detected - skip completely (0% tracking)
            return;
        }

        try {
            // ENTERPRISE: Use Database class for automatic cache invalidation
            db()->execute('
                INSERT INTO enterprise_performance_metrics
                (page_load_time, dom_ready_time, first_byte_time, dns_lookup_time,
                 connect_time, server_response_time, page_url, user_agent, user_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ', [
                $metrics['page_load_time'],
                $metrics['dom_ready_time'],
                $metrics['first_byte_time'],
                $metrics['dns_lookup_time'],
                $metrics['connect_time'],
                $metrics['server_response_time'],
                $metrics['url'],
                $metrics['user_agent'],
                $metrics['user_id'],
            ], [
                'invalidate_cache' => ['enterprise_performance_stats'], // Invalida cache stats
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to store performance metrics', [
                'error' => $e->getMessage(),
                'metrics' => $metrics,
            ]);
        }
    }

    /**
     * Alert for critical errors (emergency, alert, critical levels)
     */
    private function alertCriticalError(array $errorData, string $level = 'critical'): void
    {
        // In production, this would send alerts via email/Slack/etc
        $alertMessages = [
            'emergency' => 'EMERGENCY ERROR ALERT - System is unusable',
            'alert' => 'ALERT ERROR - Immediate action must be taken',
            'critical' => 'CRITICAL ERROR ALERT - Immediate attention required',
        ];

        Logger::jsError($level, $alertMessages[$level] ?? 'Critical error alert', [
            'message' => $alertMessages[$level] ?? 'Critical JavaScript error requires immediate attention',
            'error_data' => $errorData,
            'action_required' => true,
            'alert_level' => strtoupper($level),
        ]);
    }
}
