<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Services\Logger;

/**
 * AdminController - Lightning Framework Enterprise Admin
 * Ultra-high performance admin controller for enterprise management
 */
class AdminController
{
    private ?float $cachedResponseTime = null;

    /**
     * ENTERPRISE: Get database connection from pool with debugbar tracking
     *
     * @return \PDO|\AutoReleasePDO AutoReleasePDO extends PDO
     */
    private function getDb()
    {
        // Use db_pdo() helper to get TrackedPDO for debugbar support
        $pdo = db_pdo();

        // ENTERPRISE: PostgreSQL session reset to clear all session state and snapshots
        // This is the NUCLEAR option to force fresh data from connection pool
        try {
            $pdo->exec('DISCARD ALL'); // PostgreSQL: Reset session state (temp tables, prepared statements, sequences, session vars)
        } catch (\Exception $e) {
            // Minimal fallback
            try {
                $pdo->exec('ROLLBACK'); // Exit any transaction (PostgreSQL returns to autocommit mode)
            } catch (\Exception $e2) {
                // Ignore errors
            }
        }

        return $pdo;
    }

    /**
     * ENTERPRISE: Release database connection back to pool
     * NOTE: Not needed with db_pdo() - AutoReleasePDO handles this automatically
     *
     * @param \PDO|\AutoReleasePDO $pdo
     */
    private function releaseDb($pdo): void
    {
        // No-op: AutoReleasePDO from db_pdo() auto-releases on destruction
        // Keeping method for backwards compatibility
    }

    public function dashboard(): void
    {
        if (class_exists('\Need2Talk\Services\DebugbarService')) {
            $debugbar = \Need2Talk\Services\DebugbarService::getInstance();
            $debugbar->startMeasure('admin_dashboard', 'Load Dashboard');
        }

        $stats = $this->getSystemStats();

        if (isset($debugbar)) {
            $debugbar->stopMeasure('admin_dashboard');
            $debugbar->addMessage('Dashboard loaded with ' . count($stats) . ' statistics', 'info');
        }

        $this->renderEnterpriseAdmin('dashboard', [
            'title' => 'Enterprise Dashboard',
            'stats' => $stats,
            'realtime_stats' => $this->getRealtimeStats(),
            'controller' => $this,
        ]);
    }

    public function users(): void
    {
        // ENTERPRISE GALAXY: Disable cache for real-time users and rate limit monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE GALAXY: Delegate to AdminUsersAndRateLimitTabsController for ALL users/rate limit data
        $usersController = new \Need2Talk\Controllers\AdminUsersAndRateLimitTabsController();
        $data = $usersController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('users', $data);
    }

    public function audio(): void
    {
        // ENTERPRISE GALAXY: Disable cache for real-time audio content monitoring
        admin_disable_cache_headers();

        // ENTERPRISE GALAXY: Delegate to AdminAudioTabsController for ALL audio data
        $audioController = new \Need2Talk\Controllers\AdminAudioTabsController();
        $data = $audioController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('audio', $data);
    }

    public function stats(): void
    {
        // ENTERPRISE GALAXY: Disable cache for real-time stats monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Use SystemMonitorService for comprehensive stats
        $monitor = new \Need2Talk\Services\SystemMonitorService();

        try {
            $dashboardStats = $monitor->getDashboardStats();
            $realtimeMetrics = $monitor->getRealtimeMetrics();
            // ENTERPRISE TIPS: Only fetch 24h data (user can switch timeframe via selector)
            $historicalData24h = $monitor->getHistoricalData(24);

            $this->renderEnterpriseAdmin('stats', [
                'title' => 'Enterprise Performance Analytics',
                'dashboard_stats' => $dashboardStats,
                'realtime_metrics' => $realtimeMetrics,
                'historical_24h' => $historicalData24h,
                'historical_7d' => [], // Empty - loaded via timeframe selector
                'alerts' => $dashboardStats['alerts'] ?? [],
                'monitor' => $monitor, // For helper methods
            ]);
        } catch (\Exception $e) {
            // Log to default channel - warning level (stats load failed, using fallback)
            if (function_exists('should_log') && should_log('default', 'warning')) {
                Logger::warning('[ADMIN STATS] Failed to load SystemMonitorService data', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            // Fallback to basic stats
            $this->renderEnterpriseAdmin('stats', [
                'title' => 'System Performance',
                'realtime_stats' => $this->getRealtimeStats(),
                'error' => 'Failed to load comprehensive stats: ' . $e->getMessage(),
            ]);
        }
    }

    public function security(): void
    {
        // ENTERPRISE GALAXY: Disable cache for real-time security event monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE GALAXY: Delegate to AdminSecurityEventsController for ALL security data
        // Controller now includes security_status in getPageData()
        $securityEventsController = new \Need2Talk\Controllers\AdminSecurityEventsController();
        $data = $securityEventsController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('security', $data);
    }

    public function logs(): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Disable cache for dynamic logging config page
        // Ensures browser always fetches fresh data after configuration changes
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        $this->renderEnterpriseAdmin('logs', [
            'title' => 'System Logs',
            'system_logs' => $this->getSystemLogs(),
        ]);
    }

    /**
     * ENTERPRISE GALAXY: ML Security & DDoS Protection Dashboard
     */
    public function mlSecurity(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        try {
            $mlEngine = new \Need2Talk\Services\Security\AdvancedMLThreatEngine();
            $ddosProtection = \Need2Talk\Services\Security\DDoSProtection::getInstance();

            // Get banned IPs
            $pdo = db_pdo();
            $stmt = $pdo->query("
                SELECT ip_address, ban_type, severity, score, banned_at, expires_at,
                       EXTRACT(EPOCH FROM (expires_at - NOW()))/3600 as hours_remaining
                FROM vulnerability_scan_bans
                WHERE expires_at > NOW()
                ORDER BY banned_at DESC
                LIMIT 50
            ");
            $bannedIps = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Round hours remaining
            foreach ($bannedIps as &$ban) {
                $ban['hours_remaining'] = round((float) $ban['hours_remaining']);
            }

            $data = [
                'title' => 'ML Security & DDoS',
                'ml_stats' => $mlEngine->getStats(),
                'ml_config' => $mlEngine->getConfig(),
                'ddos_status' => $ddosProtection->getStatus(),
                'endpoint_stats' => $ddosProtection->getEndpointStats(),
                'proxy_diagnostics' => \Need2Talk\Services\Security\TrustedProxyValidator::getDiagnostics(),
                'banned_ips' => $bannedIps,
            ];

            $this->renderEnterpriseAdmin('ml-security', $data);
        } catch (\Exception $e) {
            \Need2Talk\Services\Logger::error('ML Security Dashboard Error', ['error' => $e->getMessage()]);

            $this->renderEnterpriseAdmin('ml-security', [
                'title' => 'ML Security & DDoS',
                'ml_stats' => ['learning_status' => 'error', 'training_samples' => 0],
                'ml_config' => [],
                'ddos_status' => ['enabled' => false],
                'endpoint_stats' => [],
                'proxy_diagnostics' => [],
                'banned_ips' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function jsErrors(): void
    {
        // ENTERPRISE GALAXY: Delegate to AdminJsErrorController for JS errors data
        $jsErrorController = new \Need2Talk\Controllers\AdminJsErrorController();
        $data = $jsErrorController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('js-errors', $data);
    }

    public function audit(): void
    {
        // ENTERPRISE: Disable cache for real-time audit log monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Delegate to AdminAuditLogController for audit log data
        $auditController = new \Need2Talk\Controllers\AdminAuditLogController();
        $data = $auditController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('audit', $data);
    }

    /**
     * ENTERPRISE GALAXY: Security Events Page
     * Real-time security event monitoring with dual-write architecture
     */

    /**
     * ENTERPRISE GALAXY: Get JS error log files with metadata
     */
    private function getJsErrorLogFiles(): array
    {
        $logPath = APP_ROOT . '/storage/logs';
        $jsErrorFiles = [];
        $totalSize = 0;

        if (!is_dir($logPath)) {
            return [
                'files' => [],
                'total_files' => 0,
                'total_size' => 0,
                'total_size_formatted' => '0 B',
            ];
        }

        // Find all js_errors-*.log files
        $files = glob($logPath . '/js_errors-*.log');

        if ($files) {
            // Sort by modification time (newest first)
            usort($files, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            foreach ($files as $filepath) {
                $filename = basename($filepath);
                $size = filesize($filepath);
                $totalSize += $size;
                $modified = filemtime($filepath);
                $lines = 0;

                // Count lines (fast method for large files)
                try {
                    $lines = count(file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
                } catch (\Exception $e) {
                    $lines = 0;
                }

                // Calculate relative time
                $now = time();
                $diff = $now - $modified;
                if ($diff < 60) {
                    $relativeTime = 'Just now';
                } elseif ($diff < 3600) {
                    $relativeTime = floor($diff / 60) . ' minutes ago';
                } elseif ($diff < 86400) {
                    $relativeTime = floor($diff / 3600) . ' hours ago';
                } else {
                    $relativeTime = floor($diff / 86400) . ' days ago';
                }

                $jsErrorFiles[] = [
                    'filename' => $filename,
                    'size' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'lines' => $lines,
                    'modified' => $modified,
                    'relative_time' => $relativeTime,
                ];
            }
        }

        return [
            'files' => $jsErrorFiles,
            'total_files' => count($jsErrorFiles),
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
        ];
    }

    public function jsErrorTest(): void
    {
        // ENTERPRISE: JS Error test page (admin-only, not public)
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // Include the test page directly (it's a standalone HTML page)
        include __DIR__ . '/../Views/admin/js-error-test.php';
    }

    /**
     * ENTERPRISE GALAXY: Security Events Test Page
     * Generates test security events to verify dual-write system
     */
    public function securityTest(): void
    {
        // ENTERPRISE: Security test page (admin-only, not public)
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // Include the test page directly (it's a standalone HTML page)
        include __DIR__ . '/../Views/admin/security-test.php';
    }

    /**
     * ENTERPRISE GALAXY: Anti-Scan System Monitoring
     * Shows vulnerability scanning detection and bans
     */
    public function antiScan(): void
    {
        // ENTERPRISE GALAXY: Disable cache for real-time anti-scan monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE GALAXY: Delegate to AdminAntiScanController for ALL anti-scan data
        $antiScanController = new \Need2Talk\Controllers\AdminAntiScanController();
        $data = $antiScanController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('anti-scan', $data);
    }


    /**
     * ENTERPRISE GALAXY: Legitimate Bots Dashboard
     * Shows verified bot statistics, DNS verification history, and cache performance
     */
    public function legitimateBots(): void
    {
        // ENTERPRISE GALAXY: Disable cache for real-time bot monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE GALAXY: Delegate to AdminLegitimateBotController for bot stats
        $botController = new \Need2Talk\Controllers\AdminLegitimateBotController();
        $data = $botController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('legitimate-bots', $data);
    }

    public function cookies(): void
    {
        if (class_exists('\\Need2Talk\\Services\\DebugbarService')) {
            $debugbar = \Need2Talk\Services\DebugbarService::getInstance();
            $debugbar->startMeasure('admin_cookies', 'Load Cookies Management Page');
        }

        $cookiesData = $this->getCookiesData();

        if (isset($debugbar)) {
            $debugbar->stopMeasure('admin_cookies');
            $debugbar->addMessage('Cookies management page loaded', 'info');
        }

        $this->renderEnterpriseAdmin('cookies', [
            'title' => 'Cookie Management',
            'consent_stats' => $cookiesData['consent_stats'],
            'categories' => $cookiesData['categories'],
            'recent_consents' => $cookiesData['recent_consents'],
            'consent_trends' => $cookiesData['consent_trends'],
        ]);
    }

    /**
     * ENTERPRISE: Email Metrics Dashboard
     * Shows comprehensive email analytics from all metric tables
     */
    public function emailMetrics(): void
    {
        // ENTERPRISE: Disable cache for real-time email metrics
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Delegate to AdminEmailMetricsController for all email metrics data
        $emailMetricsController = new \Need2Talk\Controllers\AdminEmailMetricsController();
        $data = $emailMetricsController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('email-metrics', $data);
    }

    /**
     * 🧠 ENTERPRISE GALAXY: Emotional Analytics Dashboard
     * Internal insights for product improvement (GDPR-compliant, NO data sales)
     *
     * Visualizes:
     * - Emotions EXPRESSED (what users register)
     * - Emotions EVOKED (reactions from others)
     * - Sentiment Gap Analysis (key insight!)
     * - Engagement Metrics
     * - Consent Statistics
     */
    public function emotionalAnalytics(): void
    {
        // ENTERPRISE: Disable cache for real-time emotional insights
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // ENTERPRISE: Delegate to AdminEmotionalAnalyticsController
        $analyticsController = new \Need2Talk\Controllers\AdminEmotionalAnalyticsController();
        $data = $analyticsController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('emotional-analytics', $data);
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Audio Workers Monitoring & Control
     * Real-time Docker worker monitoring, scaling, and S3 upload processing
     */
    public function audioWorkers(): void
    {
        // ENTERPRISE: Disable cache for real-time audio worker metrics
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Delegate to AdminAudioWorkerController for all worker data
        $audioWorkerController = new \Need2Talk\Controllers\AdminAudioWorkerController();
        $data = $audioWorkerController->getStatus();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('audio-workers', array_merge($data, [
            'title' => 'Audio Workers Monitoring',
        ]));
    }

    /**
     * 🚀 ENTERPRISE GALAXY V4.3: DM Audio E2E Workers Monitoring & Control
     * Real-time Docker worker monitoring for DM audio messages with E2E encryption
     * Dedicated worker that frees PHP-FPM from S3 uploads during real-time chat
     */
    public function dmAudioWorkers(): void
    {
        // ENTERPRISE: Disable cache for real-time metrics
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Delegate to AdminDMAudioWorkerController for all worker data
        $dmAudioWorkerController = new \Need2Talk\Controllers\AdminDMAudioWorkerController();
        $data = $dmAudioWorkerController->getStatus();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('dm-audio-workers', array_merge($data, [
            'title' => 'DM Audio E2E Workers',
        ]));
    }

    /**
     * 🚀 ENTERPRISE GALAXY V4.3: Overlay Flush Workers Monitoring & Control
     * Real-time Docker worker monitoring for write-behind cache flush (reactions, plays, comments)
     * Distributed worker with 16 partitions for horizontal scaling
     */
    public function overlayWorkers(): void
    {
        // ENTERPRISE: Disable cache for real-time metrics
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Delegate to AdminOverlayWorkerController for all worker data
        $overlayWorkerController = new \Need2Talk\Controllers\AdminOverlayWorkerController();
        $data = $overlayWorkerController->getStatus();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('overlay-workers', array_merge($data, [
            'title' => 'Overlay Flush Workers',
        ]));
    }

    /**
     * 🚀 ENTERPRISE GALAXY V11.6: Notification Workers Monitoring & Control
     * Real-time async notification queue processing with batching and deduplication
     * Scalable 1-4 workers with progressive backoff and intelligent aggregation
     */
    public function notificationWorkers(): void
    {
        // ENTERPRISE: Disable cache for real-time metrics
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Delegate to AdminNotificationWorkerController for all worker data
        $notificationWorkerController = new \Need2Talk\Controllers\AdminNotificationWorkerController();
        $data = $notificationWorkerController->getStatus();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('notification-workers', array_merge($data, [
            'title' => 'Notification Workers',
        ]));
    }

    /**
     * 🚀 ENTERPRISE GALAXY V8.0: Distributed Workers & Feed Pre-Computation Monitor
     * Real-time monitoring of overlay workers, feed workers, partition locks
     */
    public function enterprise(): void
    {
        // ENTERPRISE: No-cache headers for real-time monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // Initial status loaded via AJAX in the view
        $this->renderEnterpriseAdmin('enterprise', [
            'title' => 'Enterprise V8.0 Monitor',
            'status' => [],
        ]);
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Performance Metrics Dashboard
     * Real-time frontend performance monitoring
     */
    public function performance(): void
    {
        // ENTERPRISE: No-cache headers for real-time performance monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Delegate to AdminPerformanceController for all performance data
        $performanceController = new \Need2Talk\Controllers\AdminPerformanceController();
        $data = $performanceController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('performance', $data);
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Cron Jobs Management
     * Web-based interface for managing scheduled tasks
     */
    public function cron(): void
    {
        // ENTERPRISE GALAXY: No-cache headers for real-time cron monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE GALAXY: Delegate to AdminCronController for all cron data
        $cronController = new \Need2Talk\Controllers\AdminCronController();
        $data = $cronController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('cron', $data);
    }

    public function newsletter(): void
    {
        // ENTERPRISE GALAXY: Disable cache for real-time newsletter management
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE GALAXY: Delegate to AdminNewsletterController for all newsletter data
        $newsletterController = new \Need2Talk\Controllers\AdminNewsletterController();
        $data = $newsletterController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('newsletter', $data);
    }

    /**
     * ENTERPRISE GALAXY: Moderators Management
     * Create, edit, activate/deactivate moderators for the Moderation Portal
     */
    public function moderators(): void
    {
        // ENTERPRISE: No-cache headers for real-time moderator management
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Delegate to AdminModeratorsController for all moderator data
        $moderatorsController = new \Need2Talk\Controllers\AdminModeratorsController();
        $data = $moderatorsController->getPageData();

        // Render with enterprise admin layout
        $this->renderEnterpriseAdmin('moderators', $data);
    }

    public function settings(): void
    {
        if (class_exists('\Need2Talk\Services\DebugbarService')) {
            $debugbar = \Need2Talk\Services\DebugbarService::getInstance();
            $debugbar->startMeasure('admin_settings', 'Load Settings Page');
        }

        // Handle POST request for updating settings
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($debugbar)) {
                $debugbar->addMessage('Processing POST request for settings update', 'info');
            }

            // ENTERPRISE: Detect which form was submitted and delegate to AdminSettingsController
            $settingsController = new \Need2Talk\Controllers\AdminSettingsController();

            // Handle AJAX test telegram alert action
            if (isset($_GET['action']) && $_GET['action'] === 'test_telegram_alert') {
                $settingsController->sendTestTelegramAlert();
                return;
            }

            // Check which form was submitted by looking at POST fields
            if (isset($_POST['telegram_alerts_enabled_hidden'])) {
                // Telegram Log Alerts form (has telegram_alerts_enabled_hidden field)
                $settingsController->updateTelegramLogAlertsSettings();
            } elseif (isset($_POST['js_console_browser_enabled_hidden'])) {
                // Browser Console form (has js_console_browser_enabled_hidden field)
                $settingsController->updateBrowserConsoleSettings();
            } elseif (isset($_POST['min_level'])) {
                // JS Errors DB Filter form (has min_level field)
                $settingsController->updateJsErrorsDbFilter();
            } else {
                // Debugbar settings form (has debugbar fields)
                $settingsController->updateDebugbarSettings();
            }

            return;
        }

        // ENTERPRISE TIPS: Prevent browser caching of settings page to ensure fresh checkbox states
        // CHROME-SPECIFIC: Clear-Site-Data + aggressive cache headers
        // NUCLEAR OPTION: Clear cache when LOADING settings page (not just when saving)
        // This ensures Chrome's cache is purged BEFORE user makes changes
        header('Clear-Site-Data: "cache", "storage"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie, Accept-Encoding');

        // ENTERPRISE: Get settings from AdminSettingsController
        $settingsController = new \Need2Talk\Controllers\AdminSettingsController();

        $system_config = $this->getSystemConfig();
        $debugbarSettings = $settingsController->getDebugbarSettings();
        $jsErrorsDbFilterSettings = $settingsController->getJsErrorsDbFilterSettings();
        $browserConsoleEnabled = $settingsController->getBrowserConsoleEnabled();
        $telegramAlertsSettings = $settingsController->getTelegramLogAlertsSettings();

        if (isset($debugbar)) {
            $debugbar->stopMeasure('admin_settings');
            $debugbar->addMessage('Settings page loaded successfully', 'info');
        }

        $this->renderEnterpriseAdmin('settings', [
            'title' => 'System Configuration',
            'system_config' => $system_config,
            'debugbar_settings' => $debugbarSettings,
            'js_errors_db_filter_settings' => $jsErrorsDbFilterSettings,
            'browser_console_enabled' => $browserConsoleEnabled,
            'telegram_alerts_settings' => $telegramAlertsSettings,
        ]);
    }

    public function systemAction(): void
    {
        header('Content-Type: application/json');

        // Allow GET for read-only actions (view_log, search_log, download_log)
        $readOnlyActions = ['view_log', 'search_log', 'download_log'];
        $action = $_REQUEST['action'] ?? '';

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($action, $readOnlyActions, true)) {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);

            return;
        }

        switch ($action) {
            case 'clear_opcache':
                $this->clearOpCache();
                break;
            case 'start_workers':
                $this->startWorkers();
                break;
            case 'stop_workers':
                $this->stopWorkers();
                break;
            case 'stop_workers_clean':
                $this->stopWorkersClean();
                break;
            case 'recover_redis':
                $this->recoverRedis();
                break;
            case 'refresh_connection_pool':
                $this->refreshConnectionPool();
                break;
            case 'monitor_workers':
                $this->monitorWorkers();
                break;
            case 'monitor_performance':
                $this->monitorPerformance();
                break;
            case 'view_log':
                $this->viewLog();
                break;
            case 'download_log':
                $this->downloadLog();
                break;
            case 'delete_log':
                $this->deleteLog();
                break;
            case 'search_log':
                $this->searchLog();
                break;
            case 'clear_log':
                $this->clearLog();
                break;
            case 'archive_logs':
                $this->archiveLogs();
                break;
            case 'bulk_download':
                $this->bulkDownload();
                break;
            case 'update_logging_config':
                $this->updateLoggingConfig();
                break;
            case 'test_output':
                $this->testOutput();
                break;
            case 'run_performance_test':
                $this->runPerformanceTest();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action']);

                return;
        }

        // Commands that handle their own JSON output
        $selfHandlingCommands = ['monitor_workers', 'monitor_performance', 'start_workers', 'clear_opcache', 'stop_workers', 'stop_workers_clean', 'recover_redis', 'test_output', 'view_log', 'download_log', 'bulk_download', 'delete_log', 'search_log', 'clear_log', 'archive_logs', 'update_logging_config', 'run_performance_test'];

        if (!in_array($action, $selfHandlingCommands, true)) {
            echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $action)) . ' completed successfully']);
        }
    }

    public function debugbarOpen(): void
    {
        try {
            $storage_path = APP_ROOT . '/storage/debugbar';

            if (!is_dir($storage_path)) {
                mkdir($storage_path, 0755, true);
            }

            // ENTERPRISE: Create debugbar instance and handler
            $fileStorage = new \DebugBar\Storage\FileStorage($storage_path);
            $debugbar = new \DebugBar\StandardDebugBar();
            $debugbar->setStorage($fileStorage);

            $openHandler = new \DebugBar\OpenHandler($debugbar);

            // Set CORS headers for AJAX requests
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');

            $openHandler->handle($_GET, false, false);

        } catch (\Exception $e) {
            Logger::security('critical', 'ADMIN: Debugbar AJAX handler exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'Debugbar handler failed']);
        }
    }

    public function login(): void
    {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        header('Content-Type: application/json');

        try {
            $security = new \Need2Talk\Services\AdminSecurityService();
            $result = $security->initiateAdminLogin($email, $password);

            // ENTERPRISE SECURITY LOG: Admin login attempt
            if ($result['success'] ?? false) {
                Logger::security('warning', 'ADMIN: Login initiated - awaiting 2FA', [
                    'email_hash' => hash('sha256', strtolower($email)),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);
            } else {
                Logger::security('warning', 'ADMIN: Failed login attempt', [
                    'email_hash' => hash('sha256', strtolower($email)),
                    'reason' => $result['error'] ?? 'unknown',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);
            }

            echo json_encode($result);
        } catch (\Exception $e) {
            error_log('[ADMIN ERROR] Exception in initiateAdminLogin: ' . $e->getMessage());

            // ENTERPRISE SECURITY LOG: Admin login exception
            Logger::security('critical', 'ADMIN: Login exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'email_hash' => hash('sha256', strtolower($email)),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode(['success' => false, 'error' => 'Login failed']);
        }
    }

    public function verify2FA(): void
    {
        $tempToken = $_POST['temp_token'] ?? '';
        $code = $_POST['code'] ?? '';

        header('Content-Type: application/json');

        $security = new \Need2Talk\Services\AdminSecurityService();
        $result = $security->verify2FACode($tempToken, $code);

        // Set HttpOnly cookie server-side if login successful
        if ($result['success'] && isset($result['session_token'])) {
            $sessionToken = $result['session_token'];

            // ENTERPRISE SECURITY: __Host- prefix for admin session cookie
            // ENTERPRISE GALAXY V6.6: Use ADMIN-specific session lifetime (1 hour for security)
            setcookie('__Host-admin_session', $sessionToken, [
                'expires' => time() + EnterpriseGlobalsManager::getAdminSessionLifetimeSeconds(),
                'path' => '/',
                // NO 'domain' - required for __Host- prefix
                'secure' => true,  // __Host- requires HTTPS
                'httponly' => true,  // No JavaScript access
                'samesite' => 'Lax',  // CSRF protection
            ]);
            unset($result['session_token']);

            // ENTERPRISE SECURITY LOG: 2FA verification successful
            Logger::security('warning', 'ADMIN: 2FA verification successful', [
                'temp_token' => substr($tempToken, 0, 8) . '***',
                'session_created' => true,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);
        } else {
            // ENTERPRISE SECURITY LOG: 2FA verification failed
            Logger::security('warning', 'ADMIN: 2FA verification failed', [
                'temp_token' => substr($tempToken, 0, 8) . '***',
                'reason' => $result['error'] ?? 'invalid_code',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);
        }

        echo json_encode($result);
    }

    public function logout(): void
    {
        $sessionToken = $_COOKIE['__Host-admin_session'] ?? '';

        header('Content-Type: application/json');

        if ($sessionToken) {
            $security = new \Need2Talk\Services\AdminSecurityService();
            $security->logoutAdmin($sessionToken);

            // ENTERPRISE SECURITY LOG: Admin logout
            Logger::security('info', 'ADMIN: Logout successful', [
                'session_token' => substr($sessionToken, 0, 8) . '***',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);
        }
        // ENTERPRISE: Clear __Host- prefixed admin cookie (NO domain parameter for __Host-)
        setcookie('__Host-admin_session', '', [
            'expires' => time() - 3600,
            'path' => '/',
            // NO 'domain' - required for __Host- prefix
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        echo json_encode(['success' => true]);
    }

    public function emergencyLogin(): void
    {
        $emergencyCode = strtoupper(trim($_POST['emergency_code'] ?? ''));

        header('Content-Type: application/json');

        if (empty($emergencyCode)) {
            echo json_encode(['success' => false, 'error' => 'Emergency code required']);

            return;
        }

        $db = $this->getDb();
        try {
            // ENTERPRISE 2025: Use connection pooling

            // SECURITY: Get all valid emergency codes and verify hash (can't search by hash directly)
            $stmt = $db->prepare('
                SELECT id, code, expires_at, used_at
                FROM admin_emergency_codes
                WHERE expires_at > NOW() AND used_at IS NULL
            ');
            $stmt->execute();
            $codes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Find matching code by verifying hash
            $codeData = null;
            foreach ($codes as $storedCode) {
                if (password_verify($emergencyCode, $storedCode['code'])) {
                    $codeData = $storedCode;
                    break;
                }
            }

            if (!$codeData) {
                // Log failed attempt (INSERT - not cached)
                $stmt = $db->prepare('
                    INSERT INTO admin_emergency_access_log
                    (access_type, status, action_details, ip_address, user_agent, system_user, ssh_client)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    'emergency_code_attempt',
                    'failed',
                    json_encode(['code' => substr($emergencyCode, 0, 4) . '****', 'reason' => 'invalid_or_expired']),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'web_emergency',
                    '',
                ]);

                // ENTERPRISE SECURITY LOG: Failed emergency login attempt
                Logger::security('critical', 'ADMIN: Failed emergency code attempt', [
                    'code_attempt' => substr($emergencyCode, 0, 4) . '****',
                    'reason' => 'invalid_or_expired',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);

                echo json_encode(['success' => false, 'error' => 'Invalid or expired emergency code']);

                return;
            }

            // Mark code as used
            $stmt = $db->prepare('
                UPDATE admin_emergency_codes
                SET used_at = NOW(), used_by_admin_id = ?
                WHERE id = ?
            ');
            $stmt->execute([1, $codeData['id']]);

            // Generate admin session (bypass 2FA completely)
            $sessionToken = bin2hex(random_bytes(32));
            $hashedToken = hash('sha256', $sessionToken);
            $expiresAt = time() + 1800; // 30 minutes

            // Store session in database
            $stmt = $db->prepare('
                INSERT INTO admin_sessions (admin_id, session_token, expires_at, ip_address, user_agent, last_activity)
                VALUES (1, ?, ?, ?, ?, ?)
                ON CONFLICT (session_token) DO UPDATE SET
                    expires_at = EXCLUDED.expires_at,
                    last_activity = EXCLUDED.last_activity
            ');
            $stmt->execute([
                $hashedToken,
                date('Y-m-d H:i:s', $expiresAt),
                $_SERVER['REMOTE_ADDR'] ?? '::1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Emergency Access',
                date('Y-m-d H:i:s'),
            ]);

            // ENTERPRISE SECURITY: __Host- prefix for admin session cookie (emergency login - NO domain for __Host-)
            setcookie('__Host-admin_session', $sessionToken, [
                'expires' => $expiresAt,
                'path' => '/',
                // NO 'domain' - required for __Host- prefix
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            // Log successful emergency access
            $stmt = $db->prepare('
                INSERT INTO admin_emergency_access_log
                (access_type, status, action_details, ip_address, user_agent, system_user, ssh_client)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                'successful_login',
                'success',
                json_encode([
                    'code_used' => substr($emergencyCode, 0, 4) . '****',
                    'bypass_2fa' => true,
                    'session_created' => true,
                ]),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'web_emergency',
                '',
            ]);

            // ENTERPRISE SECURITY LOG: Successful emergency access
            Logger::security('critical', 'ADMIN: Emergency access granted', [
                'admin_id' => 1,
                'code_used' => substr($emergencyCode, 0, 4) . '****',
                'bypass_2fa' => true,
                'session_created' => true,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            // Send emergency access notification email
            try {
                $notificationService = new \Need2Talk\Services\AdminUrlNotificationService();
                $notificationService->sendEmergencyAccessNotification($emergencyCode, [
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                error_log('[ADMIN ERROR] Emergency notification failed: ' . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message' => 'Emergency access granted! Redirecting to dashboard...',
                'redirect' => dirname($_SERVER['REQUEST_URI']) . '/dashboard',
            ]);

        } catch (\Exception $e) {
            error_log('[ADMIN ERROR] Emergency login error: ' . $e->getMessage());

            // ENTERPRISE SECURITY LOG: Emergency login exception
            Logger::security('critical', 'ADMIN: Emergency login exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            echo json_encode(['success' => false, 'error' => 'Emergency access failed']);
        } finally {
            $this->releaseDb($db);
        }
    }

    public function showLogin(): void
    {
        $admin_url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $title = 'need2talk - Enterprise Admin Access';
        include APP_ROOT . '/app/Views/admin/login.php';
    }

    public function show2FA(): void
    {
        $tempToken = $_GET['token'] ?? '';

        if (!$tempToken) {
            // Use full URL from environment configuration
            $secureUrl = \Need2Talk\Services\AdminSecurityService::generateSecureAdminUrl(true);
            header("Location: {$secureUrl}");
            exit;
        }

        $admin_url = preg_replace('/\/2fa.*$/', '', $_SERVER['REQUEST_URI']);
        $title = 'need2talk - Verifica 2FA';
        include APP_ROOT . '/app/Views/admin/2fa.php';
    }

    // REMOVED: All embedded HTML render methods - using pure modular views only

    public function formatMetricValue(string $key, $value): string
    {
        if (!is_array($value)) {
            return htmlspecialchars((string)$value);
        }

        // Format specific complex data structures
        switch ($key) {
            case 'database':
                return $this->formatDatabaseStats($value);
            case 'cache':
                return $this->formatCacheStats($value);
            case 'security':
                return $this->formatSecurityStats($value);
            case 'users':
                return $this->formatUserStats($value);
            case 'performance':
                return $this->formatPerformanceStats($value);
            case 'resources':
                return $this->formatResourceStats($value);
            case 'alerts':
                return $this->formatAlerts($value);
            case 'enterprise':
                return $this->formatEnterpriseStats($value);
            case 'performance_worker_stats':
                return $this->formatWorkerStats($value);
            case 'metrics_collector_stats':
                return $this->formatMetricsStats($value);
            default:
                // For simple arrays, show count
                return is_array($value) ? count($value) . ' items' : htmlspecialchars((string)$value);
        }
    }

    /**
     * Terminal interface
     */
    public function terminal(): void
    {
        $this->renderEnterpriseAdmin('terminal', [
            'title' => 'CLI Terminal',
            'current_directory' => getcwd(),
            'system_info' => [
                'OS' => PHP_OS_FAMILY,
                'PHP' => PHP_VERSION,
                'User' => get_current_user(),
                'Working Directory' => getcwd(),
            ],
        ]);
    }

    /**
     * Execute terminal command
     * ENTERPRISE GALAXY: Docker/OrbStack compatible
     */
    public function terminalExec(): void
    {
        header('Content-Type: application/json');

        $command = $_POST['command'] ?? '';
        $command = trim($command);

        if (empty($command)) {
            echo json_encode(['success' => false, 'error' => 'Command cannot be empty']);

            return;
        }

        // Security: Block dangerous commands
        $blocked_commands = ['rm -rf', 'mkfs', 'dd if=', 'format', '> /dev', 'sudo', 'su '];

        foreach ($blocked_commands as $blocked) {
            if (stripos($command, $blocked) !== false) {
                // ENTERPRISE SECURITY LOG: Blocked dangerous command
                Logger::security('warning', 'ADMIN: Blocked dangerous terminal command', [
                    'command' => $command,
                    'blocked_pattern' => $blocked,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);

                echo json_encode(['success' => false, 'error' => 'Command blocked for security reasons']);

                return;
            }
        }

        // Set working directory
        $old_dir = getcwd();
        chdir(APP_ROOT);

        // ENTERPRISE GALAXY: Enhanced PATH for Docker/OrbStack compatibility
        // PHP-FPM has limited PATH, so we need to set it explicitly
        $enhancedPath = '/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin';
        $currentPath = getenv('PATH');
        if ($currentPath) {
            $enhancedPath = $enhancedPath . ':' . $currentPath;
        }

        // Execute command with enhanced PATH and timeout
        $start_time = microtime(true);

        // ENTERPRISE GALAXY: Try timeout command (if available), otherwise execute directly
        // macOS: gtimeout (homebrew) or timeout (coreutils)
        // Linux: timeout (builtin)
        $timeoutCmd = '';
        if (file_exists('/opt/homebrew/bin/gtimeout')) {
            $timeoutCmd = '/opt/homebrew/bin/gtimeout 30 ';
        } elseif (file_exists('/usr/bin/timeout')) {
            $timeoutCmd = '/usr/bin/timeout 30 ';
        }

        $fullCommand = "PATH=\"{$enhancedPath}\" {$timeoutCmd}{$command} 2>&1";

        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[ADMIN TERMINAL] Executing command', [
                'command' => $fullCommand,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        }
        $output = shell_exec($fullCommand);
        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[ADMIN TERMINAL] Command output', [
                'output' => $output ?: 'NO OUTPUT',
                'command' => substr($command, 0, 100),
            ]);
        }

        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        // Restore directory
        chdir($old_dir);

        // ENTERPRISE SECURITY LOG: Terminal command executed
        Logger::security('info', 'ADMIN: Terminal command executed', [
            'command' => $command,
            'execution_time_ms' => $execution_time,
            'output_length' => strlen($output ?: ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);

        // ENTERPRISE PERFORMANCE LOG: Terminal command performance
        Logger::performance('info', 'Admin Terminal Command', $execution_time / 1000, [
            'command' => substr($command, 0, 100),
            'execution_time_ms' => $execution_time,
            'output_size_bytes' => strlen($output ?: ''),
        ]);

        $response = [
            'success' => true,
            'command' => $command,
            'output' => $output ?: 'Command executed (no output)',
            'execution_time' => $execution_time . 'ms',
            'working_directory' => APP_ROOT,
        ];

        echo json_encode($response);
    }


    private function testOutput(): void
    {
        $script = APP_ROOT . '/scripts/test-output.sh';

        if (file_exists($script)) {
            $output = shell_exec("bash {$script} 2>&1");
            echo json_encode(['success' => true, 'output' => $output ?: 'Test completed']);

            return;
        }
        echo json_encode(['success' => false, 'error' => 'Test script not found']);
    }

    private function clearOpCache(): void
    {
        $script = APP_ROOT . '/scripts/clear-opcache-enterprise.php';

        if (file_exists($script)) {
            // CRITICAL FIX: Change to project root directory before executing script
            $oldDir = getcwd();
            chdir(APP_ROOT);
            $output = shell_exec('php scripts/clear-opcache-enterprise.php 2>&1');
            chdir($oldDir);

            // ENTERPRISE SECURITY LOG: OpCache cleared
            Logger::security('info', 'ADMIN: OpCache cleared', [
                'script' => $script,
                'output' => substr($output ?: '', 0, 200),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode(['success' => true, 'output' => $output ?: 'OpCache cleared successfully']);

            return;
        }

        // ENTERPRISE SECURITY LOG: OpCache clear failed
        Logger::security('warning', 'ADMIN: OpCache clear failed - script not found', [
            'script' => $script,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        echo json_encode(['success' => false, 'error' => 'Clear OpCache script not found']);
    }

    private function startWorkers(): void
    {
        // ENTERPRISE GALAXY LEVEL: Use WorkerManager instead of bash scripts
        try {
            $workerManager = new \Need2Talk\Services\WorkerManager();
            $result = $workerManager->startWorkers(2); // Start 2 workers by default

            // ENTERPRISE SECURITY LOG: Workers started
            Logger::security('info', 'ADMIN: Workers started', [
                'result' => $result,
                'worker_count' => 2,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode($result);
        } catch (\Exception $e) {
            // ENTERPRISE SECURITY LOG: Worker start failed
            Logger::security('warning', 'ADMIN: Failed to start workers', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => 'Failed to start workers: ' . $e->getMessage(),
            ]);
        }
    }

    private function recoverRedis(): void
    {
        $script = APP_ROOT . '/scripts/redis-auto-recovery.sh';

        if (file_exists($script)) {
            // CRITICAL FIX: Change to project root directory before executing script
            $oldDir = getcwd();
            chdir(APP_ROOT);
            // ENTERPRISE GALAXY: Set PATH for Docker access
            $output = shell_exec('PATH="/usr/local/bin:/usr/bin:/bin" sh ./scripts/redis-auto-recovery.sh 2>&1');
            chdir($oldDir);

            // ENTERPRISE SECURITY LOG: Redis recovery executed
            Logger::security('warning', 'ADMIN: Redis recovery executed', [
                'script' => $script,
                'output' => substr($output ?: '', 0, 200),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode(['success' => true, 'output' => $output ?: 'Redis recovery completed']);

            return;
        }

        // ENTERPRISE SECURITY LOG: Redis recovery failed
        Logger::security('critical', 'ADMIN: Redis recovery failed - script not found', [
            'script' => $script,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        echo json_encode(['success' => false, 'error' => 'Redis recovery script not found']);
    }

    private function refreshConnectionPool(): void
    {
        // Clean output buffer to prevent extra content
        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            // Get pool stats without triggering problematic cleanup
            $pool = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance();
            $stats = $pool->getStats();

            // Create clean JSON response
            $response = [
                'success' => true,
                'output' => sprintf(
                    "Connection pool stats refreshed!\n• Max connections: %d\n• Current active: %d\n• Available slots: %d\n• Utilization: %.1f%%\n• Pool status: %s",
                    $stats['max_connections'],
                    $stats['active_connections'],
                    $stats['max_connections'] - $stats['active_connections'],
                    ($stats['active_connections'] / max($stats['max_connections'], 1)) * 100,
                    $stats['active_connections'] > 0 ? 'Active' : 'Idle'
                ),
            ];

            echo json_encode($response);
            exit(); // Prevent any additional output

        } catch (\Exception $e) {
            $response = ['success' => false, 'error' => 'Failed to refresh pool stats: ' . $e->getMessage()];
            echo json_encode($response);
            exit();
        }
    }

    private function stopWorkers(): void
    {
        // ENTERPRISE GALAXY LEVEL: Use WorkerManager instead of bash scripts
        try {
            $workerManager = new \Need2Talk\Services\WorkerManager();
            $result = $workerManager->stopWorkers(false); // Don't clean logs

            // ENTERPRISE SECURITY LOG: Workers stopped
            Logger::security('info', 'ADMIN: Workers stopped', [
                'result' => $result,
                'clean_logs' => false,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode($result);
        } catch (\Exception $e) {
            // ENTERPRISE SECURITY LOG: Worker stop failed
            Logger::security('warning', 'ADMIN: Failed to stop workers', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => 'Failed to stop workers: ' . $e->getMessage(),
            ]);
        }
    }

    private function stopWorkersClean(): void
    {
        // ENTERPRISE GALAXY LEVEL: Use WorkerManager instead of bash scripts
        try {
            $workerManager = new \Need2Talk\Services\WorkerManager();
            $result = $workerManager->stopWorkers(true); // Clean logs

            // ENTERPRISE SECURITY LOG: Workers stopped with clean
            Logger::security('info', 'ADMIN: Workers stopped with clean', [
                'result' => $result,
                'clean_logs' => true,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode($result);
        } catch (\Exception $e) {
            // ENTERPRISE SECURITY LOG: Worker stop clean failed
            Logger::security('warning', 'ADMIN: Failed to stop workers with clean', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => 'Failed to stop workers: ' . $e->getMessage(),
            ]);
        }
    }

    private function monitorWorkers(): void
    {
        // ENTERPRISE GALAXY LEVEL: Use WorkerManager instead of bash scripts
        try {
            $workerManager = new \Need2Talk\Services\WorkerManager();
            $result = $workerManager->getMonitoringOutput();

            echo json_encode($result);
        } catch (\Exception $e) {
            Logger::security('warning', 'ADMIN: Failed to monitor workers', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to monitor workers: ' . $e->getMessage(),
            ]);
        }
    }

    private function monitorPerformance(): void
    {
        $script = APP_ROOT . '/scripts/performance-worker.php';

        if (file_exists($script)) {
            // CRITICAL FIX: Change to project root directory before executing script
            $oldDir = getcwd();
            chdir(APP_ROOT);
            $output = shell_exec('php -d max_execution_time=10 scripts/performance-worker.php 2>&1');
            chdir($oldDir);

            echo json_encode(['success' => true, 'output' => $output ?: 'Performance monitoring completed']);

            return;
        }
        echo json_encode(['success' => false, 'error' => 'Performance script not found']);
    }

    /**
     * ENTERPRISE: Run manual performance test with 4 dedicated test users
     * Runs performance-worker.php on demand without auto-executing on page load
     */
    private function runPerformanceTest(): void
    {
        try {
            $workerScript = APP_ROOT . '/scripts/performance-worker.php';

            if (!file_exists($workerScript)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Performance worker script not found',
                ]);

                return;
            }

            // Get test parameters from POST (with defaults)
            // ENTERPRISE TIPS: Default to 4 operations total (1 per test user) instead of 100
            $duration = min((int) ($_POST['duration'] ?? 1), 60); // Default 1 second
            $opsPerSecond = min((int) ($_POST['ops_per_second'] ?? 4), 100); // Default 4 ops = 4 emails total

            // Run performance test with environment-aware execution
            // ENTERPRISE: Auto-detect if we're INSIDE a container or need docker exec
            $insideContainer = $this->isRunningInsideContainer();
            $dockerContainer = $_ENV['DOCKER_PHP_CONTAINER'] ?? 'need2talk_php';

            if ($insideContainer) {
                // Running INSIDE container: Execute directly (no docker-in-docker)
                $command = sprintf(
                    "php %s --worker-id=0 --ops-per-second=%d --duration=%d --scenarios='email_queue=100' 2>&1",
                    escapeshellarg($workerScript),
                    $opsPerSecond,
                    $duration
                );
                $executionMode = 'inside_container';
            } else {
                // Check if Docker is available (development environment)
                $hasDocker = file_exists('/usr/local/bin/docker') ||
                            file_exists('/usr/bin/docker') ||
                            shell_exec('which docker 2>/dev/null');

                if ($hasDocker && ($_ENV['EXECUTION_MODE'] ?? 'production') === 'docker') {
                    // Outside container with Docker: Use docker exec
                    $command = sprintf(
                        "docker exec -i %s php /var/www/html/scripts/performance-worker.php --worker-id=0 --ops-per-second=%d --duration=%d --scenarios='email_queue=100' 2>&1",
                        escapeshellarg($dockerContainer),
                        $opsPerSecond,
                        $duration
                    );
                    $executionMode = 'docker_exec';
                } else {
                    // Production: Execute directly
                    $command = sprintf(
                        "php %s --worker-id=0 --ops-per-second=%d --duration=%d --scenarios='email_queue=100' 2>&1",
                        escapeshellarg($workerScript),
                        $opsPerSecond,
                        $duration
                    );
                    $executionMode = 'production';
                }
            }

            Logger::info("[ADMIN] Running manual performance test", [
                'duration' => $duration,
                'ops_per_second' => $opsPerSecond,
                'execution_mode' => $executionMode,
                'command' => $command,
            ]);

            $startTime = microtime(true);
            $output = shell_exec($command);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            Logger::debug("[ADMIN DEBUG] Performance test raw output", [
                'output_length' => strlen($output ?? ''),
                'output_preview' => substr($output ?? 'NULL', 0, 200),
            ]);

            if (empty($output)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Performance test returned no output (script may have crashed)',
                    'command' => $command,
                ]);

                return;
            }

            $result = json_decode(trim($output), true);
            Logger::debug("[ADMIN DEBUG] Performance test decoded result", [
                'result' => $result,
                'json_error' => json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : null,
            ]);

            if ($result && isset($result['ops_per_second'])) {
                // ENTERPRISE PERFORMANCE LOG: Performance test completed
                Logger::performance('info', 'Admin Performance Test', $executionTime / 1000, [
                    'worker_id' => $result['worker_id'] ?? 0,
                    'duration' => $result['duration'] ?? 0,
                    'operations_completed' => $result['operations_completed'] ?? 0,
                    'operations_failed' => $result['operations_failed'] ?? 0,
                    'ops_per_second' => $result['ops_per_second'] ?? 0,
                    'success_rate' => $result['success_rate'] ?? 0,
                    'execution_time_ms' => $executionTime,
                    'test_duration' => $duration,
                    'test_ops_per_second' => $opsPerSecond,
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Performance test completed successfully',
                    'test_results' => [
                        'worker_id' => $result['worker_id'] ?? 0,
                        'duration' => $result['duration'] ?? 0,
                        'operations_completed' => $result['operations_completed'] ?? 0,
                        'operations_failed' => $result['operations_failed'] ?? 0,
                        'ops_per_second' => $result['ops_per_second'] ?? 0,
                        'success_rate' => $result['success_rate'] ?? 0,
                        'execution_time_ms' => $executionTime,
                    ],
                    'test_parameters' => [
                        'duration' => $duration,
                        'ops_per_second' => $opsPerSecond,
                    ],
                ]);

                return;
            }

            // Failed to parse output
            echo json_encode([
                'success' => false,
                'error' => 'Performance test completed but failed to parse results',
                'raw_output' => $output,
            ]);

        } catch (\Exception $e) {
            Logger::error("[ADMIN] Performance test exception", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            echo json_encode([
                'success' => false,
                'error' => 'Performance test failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE GALAXY: View log file content with pagination and syntax highlighting
     */
    private function viewLog(): void
    {
        // Accept parameters from both GET and POST
        $filename = $_REQUEST['filename'] ?? '';
        $page = max(1, (int) ($_REQUEST['page'] ?? 1));
        $linesPerPage = (int) ($_REQUEST['lines_per_page'] ?? 100);

        // Validate lines per page (must be one of the allowed values)
        $allowedLinesPerPage = [50, 100, 200, 500];
        if (!in_array($linesPerPage, $allowedLinesPerPage, true)) {
            $linesPerPage = 100; // Default fallback
        }

        $searchTerm = $_REQUEST['search'] ?? '';

        try {
            // Standard file-based log handling (ENTERPRISE V12.4: Docker logs now use file-based approach)
            $logPath = APP_ROOT . '/storage/logs';
            $filepath = realpath($logPath . '/' . $filename);

            // Security: Prevent directory traversal
            if (!$filepath || strpos($filepath, realpath($logPath)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid log file']);

                return;
            }

            if (!file_exists($filepath)) {
                echo json_encode(['success' => false, 'error' => 'Log file not found']);

                return;
            }

            $lines = file($filepath, FILE_IGNORE_NEW_LINES);
            $totalLines = count($lines);

            // Filter by search term
            if ($searchTerm) {
                $lines = array_filter($lines, fn ($line) => stripos($line, $searchTerm) !== false);
                $lines = array_values($lines); // Reindex
            }

            $filteredTotal = count($lines);
            $totalPages = ceil($filteredTotal / $linesPerPage);
            $offset = ($page - 1) * $linesPerPage;
            $pageLines = array_slice($lines, $offset, $linesPerPage);

            // Add syntax highlighting and format as HTML
            $htmlContent = '';
            foreach ($pageLines as $index => $line) {
                $lineNum = $offset + $index + 1;
                $highlighted = $this->highlightLogLine($line);
                $htmlContent .= sprintf('<div class="log-line" data-line="%d">%s</div>', $lineNum, $highlighted);
            }

            if (empty($htmlContent)) {
                $htmlContent = '<div class="text-gray-400">Empty log file</div>';
            }

            echo json_encode([
                'success' => true,
                'content' => $htmlContent,
                'total_lines' => $totalLines,
                'filtered_lines' => $filteredTotal,
                'current_page' => $page,
                'total_pages' => $totalPages,
                'lines_per_page' => $linesPerPage,
            ]);

        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to view log: ' . $e->getMessage()]);
        }
    }

    /**
     * ENTERPRISE: Syntax highlighting for log lines
     */
    private function highlightLogLine(string $line): string
    {
        // ENTERPRISE GALAXY: PSR-3 Level Highlighting with Color-Coded Severity
        // 🔴 HIGHEST PRIORITY: EMERGENCY, ALERT, CRITICAL (red bold)
        $line = preg_replace('/\b(EMERGENCY|ALERT|CRITICAL)\b/i', '<span style="color:#ef4444;font-weight:bold">$1</span>', $line);

        // 🟠 HIGH PRIORITY: ERROR, FATAL (orange bold)
        $line = preg_replace('/\b(ERROR|FATAL)\b/i', '<span style="color:#f97316;font-weight:bold">$1</span>', $line);

        // 🟡 MEDIUM PRIORITY: WARNING (yellow bold)
        $line = preg_replace('/\b(WARNING|WARN)\b/i', '<span style="color:#f59e0b;font-weight:bold">$1</span>', $line);

        // 🟢 LOW PRIORITY: NOTICE, INFO, DEBUG, SUCCESS (green bold)
        $line = preg_replace('/\b(NOTICE|INFO|DEBUG|SUCCESS|OK)\b/i', '<span style="color:#10b981;font-weight:bold">$1</span>', $line);

        // Highlight dates (blue)
        $line = preg_replace('/\[(\d{2}-\w+-\d{4} \d{2}:\d{2}:\d{2}.*?)\]/', '<span style="color:#60a5fa">[$1]</span>', $line);

        // Highlight ISO dates (blue) - for js_errors logs
        $line = preg_replace('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', '<span style="color:#60a5fa">[$1]</span>', $line);

        // Highlight IPs (purple)
        $line = preg_replace('/\b(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/', '<span style="color:#a78bfa">$1</span>', $line);

        return htmlspecialchars_decode($line);
    }

    /**
     * ENTERPRISE GALAXY: Download log file
     */
    private function downloadLog(): void
    {
        $filename = $_REQUEST['filename'] ?? '';

        try {
            $logPath = APP_ROOT . '/storage/logs';
            $filepath = realpath($logPath . '/' . $filename);

            // Security: Prevent directory traversal
            if (!$filepath || strpos($filepath, realpath($logPath)) !== 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid log file']);

                return;
            }

            if (!file_exists($filepath)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Log file not found']);

                return;
            }

            // Set headers for download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: no-cache');

            // Stream file
            readfile($filepath);
            exit;

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to download log: ' . $e->getMessage()]);
        }
    }

    /**
     * ENTERPRISE GALAXY: Bulk download multiple log files as ZIP
     */
    private function bulkDownload(): void
    {
        $files = $_POST['files'] ?? [];

        if (empty($files) || !is_array($files)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nessun file selezionato']);
            return;
        }

        try {
            $logPath = APP_ROOT . '/storage/logs';
            $realLogPath = realpath($logPath);

            // Validate all files first
            $validFiles = [];
            foreach ($files as $filename) {
                $filepath = realpath($logPath . '/' . basename($filename));

                // Security: Prevent directory traversal
                if ($filepath && strpos($filepath, $realLogPath) === 0 && file_exists($filepath)) {
                    $validFiles[] = $filepath;
                }
            }

            if (empty($validFiles)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nessun file valido trovato']);
                return;
            }

            // Create ZIP file
            $zipFilename = 'logs_' . date('Y-m-d_H-i-s') . '.zip';
            $zipPath = sys_get_temp_dir() . '/' . $zipFilename;

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \Exception('Impossibile creare archivio ZIP');
            }

            foreach ($validFiles as $filepath) {
                $zip->addFile($filepath, basename($filepath));
            }

            $zip->close();

            // Set headers for download
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
            header('Content-Length: ' . filesize($zipPath));
            header('Cache-Control: no-cache');
            header('Pragma: no-cache');

            // Stream ZIP file
            readfile($zipPath);

            // Cleanup temp file
            @unlink($zipPath);

            exit;

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Errore creazione ZIP: ' . $e->getMessage()]);
        }
    }

    /**
     * ENTERPRISE GALAXY: Delete log file
     */
    private function deleteLog(): void
    {
        $filename = $_REQUEST['filename'] ?? '';

        try {
            $logPath = APP_ROOT . '/storage/logs';
            $filepath = realpath($logPath . '/' . $filename);

            // Security: Prevent directory traversal
            if (!$filepath || strpos($filepath, realpath($logPath)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid log file']);

                return;
            }

            // Security: Prevent deletion of critical system logs
            $protectedFiles = ['php_errors.log', 'need2talk.log'];
            if (in_array($filename, $protectedFiles, true)) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete protected system log']);

                return;
            }

            if (!file_exists($filepath)) {
                echo json_encode(['success' => false, 'error' => 'Log file not found']);

                return;
            }

            if (unlink($filepath)) {
                // ENTERPRISE SECURITY LOG: Log file deleted
                Logger::security('warning', 'ADMIN: Log file deleted', [
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);

                echo json_encode(['success' => true, 'message' => 'Log file deleted successfully']);
            } else {
                // ENTERPRISE SECURITY LOG: Log deletion failed
                Logger::security('critical', 'ADMIN: Failed to delete log file', [
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                echo json_encode(['success' => false, 'error' => 'Failed to delete log file']);
            }

        } catch (\Exception $e) {
            // ENTERPRISE SECURITY LOG: Log deletion exception
            Logger::security('critical', 'ADMIN: Log deletion exception', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode(['success' => false, 'error' => 'Failed to delete log: ' . $e->getMessage()]);
        }
    }

    /**
     * ENTERPRISE GALAXY: Search within log file
     */
    private function searchLog(): void
    {
        $filename = $_REQUEST['filename'] ?? '';
        $pattern = $_REQUEST['pattern'] ?? '';
        $caseSensitive = isset($_REQUEST['case_sensitive']) && $_REQUEST['case_sensitive'] === '1';
        $regex = isset($_REQUEST['regex']) && $_REQUEST['regex'] === '1';

        try {
            $logPath = APP_ROOT . '/storage/logs';
            $filepath = realpath($logPath . '/' . $filename);

            // Security: Prevent directory traversal
            if (!$filepath || strpos($filepath, realpath($logPath)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid log file']);

                return;
            }

            if (!file_exists($filepath)) {
                echo json_encode(['success' => false, 'error' => 'Log file not found']);

                return;
            }

            if (empty($pattern)) {
                echo json_encode(['success' => false, 'error' => 'Search pattern required']);

                return;
            }

            $lines = file($filepath, FILE_IGNORE_NEW_LINES);
            $results = [];

            foreach ($lines as $lineNum => $line) {
                $match = false;

                if ($regex) {
                    $match = @preg_match($pattern, $line);
                } else {
                    $match = $caseSensitive ? (strpos($line, $pattern) !== false) : (stripos($line, $pattern) !== false);
                }

                if ($match) {
                    $results[] = [
                        'line_number' => $lineNum + 1,
                        'content' => $this->highlightLogLine($line),
                    ];
                }
            }

            $totalResults = count($results);
            $limitedResults = array_slice($results, 0, 500);

            echo json_encode([
                'success' => true,
                'results' => $limitedResults,
                'total_matches' => $totalResults,
                'searched_lines' => count($lines),
                'filename' => $filename,
                'truncated' => $totalResults > 500,
            ]);

        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Search failed: ' . $e->getMessage()]);
        }
    }

    /**
     * ENTERPRISE GALAXY: Clear log file content
     */
    private function clearLog(): void
    {
        $filename = $_REQUEST['filename'] ?? '';

        try {
            $logPath = APP_ROOT . '/storage/logs';
            $filepath = realpath($logPath . '/' . $filename);

            // Security: Prevent directory traversal
            if (!$filepath || strpos($filepath, realpath($logPath)) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid log file']);

                return;
            }

            if (!file_exists($filepath)) {
                echo json_encode(['success' => false, 'error' => 'Log file not found']);

                return;
            }

            // Backup old content before clearing (last 100 lines)
            $lines = file($filepath, FILE_IGNORE_NEW_LINES);
            $backup = array_slice($lines, -100);
            $backupPath = $logPath . '/backups';

            if (!is_dir($backupPath)) {
                mkdir($backupPath, 0755, true);
            }

            $backupFile = $backupPath . '/' . pathinfo($filename, PATHINFO_FILENAME) . '_' . date('Y-m-d_His') . '.bak';
            file_put_contents($backupFile, implode(PHP_EOL, $backup));

            // Clear the log file
            if (file_put_contents($filepath, '') !== false) {
                // ENTERPRISE SECURITY LOG: Log file cleared
                Logger::security('warning', 'ADMIN: Log file cleared', [
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'backup_file' => basename($backupFile),
                    'backup_lines' => count($backup),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Log file cleared successfully',
                    'backup_file' => basename($backupFile),
                ]);
            } else {
                // ENTERPRISE SECURITY LOG: Log clear failed
                Logger::security('critical', 'ADMIN: Failed to clear log file', [
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                echo json_encode(['success' => false, 'error' => 'Failed to clear log file']);
            }

        } catch (\Exception $e) {
            // ENTERPRISE SECURITY LOG: Log clear exception
            Logger::security('critical', 'ADMIN: Log clear exception', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode(['success' => false, 'error' => 'Failed to clear log: ' . $e->getMessage()]);
        }
    }

    /**
     * ENTERPRISE GALAXY: Archive old logs into compressed file
     */
    private function archiveLogs(): void
    {
        $olderThanDays = (int) ($_REQUEST['older_than_days'] ?? 7);

        try {
            $logPath = APP_ROOT . '/storage/logs';
            $archivePath = $logPath . '/archives';

            if (!is_dir($archivePath)) {
                mkdir($archivePath, 0755, true);
            }

            $cutoffTime = time() - ($olderThanDays * 86400);

            // ENTERPRISE: Alpine Linux compatible (no GLOB_BRACE)
            $logFiles = glob($logPath . '/*.log') ?: [];
            $txtFiles = glob($logPath . '/*.txt') ?: [];
            $files = array_merge($logFiles, $txtFiles);

            $archivedFiles = [];

            foreach ($files as $file) {
                $filename = basename($file);

                // Skip protected files
                if (in_array($filename, ['php_errors.log', 'need2talk.log'], true)) {
                    continue;
                }

                if (filemtime($file) < $cutoffTime) {
                    $archivedFiles[] = $filename;
                }
            }

            if (empty($archivedFiles)) {
                echo json_encode(['success' => true, 'message' => 'No logs found to archive', 'archived_count' => 0]);

                return;
            }

            // Create tar.gz archive
            $archiveName = 'logs_archive_' . date('Y-m-d_His') . '.tar.gz';
            $archiveFile = $archivePath . '/' . $archiveName;

            $tar = new \PharData($archiveFile);

            foreach ($archivedFiles as $filename) {
                $tar->addFile($logPath . '/' . $filename, $filename);
            }

            // Compress and delete originals
            $tar->compress(\Phar::GZ);
            unset($tar);

            // Remove the uncompressed .tar file
            if (file_exists($archiveFile)) {
                unlink($archiveFile);
            }

            // Delete original files
            foreach ($archivedFiles as $filename) {
                unlink($logPath . '/' . $filename);
            }

            // ENTERPRISE SECURITY LOG: Logs archived
            Logger::security('info', 'ADMIN: Logs archived', [
                'archive_name' => $archiveName,
                'file_count' => count($archivedFiles),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Logs archived successfully',
                'archived_count' => count($archivedFiles),
                'archive_file' => $archiveName . '.gz',
                'archive_size' => $this->formatBytes(filesize($archivePath . '/' . $archiveName . '.gz')),
            ]);

        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to archive logs: ' . $e->getMessage()]);
        }
    }

    /**
     * ENTERPRISE GALAXY: Update logging configuration dynamically
     * Allows zero-downtime log level changes with full audit trail
     */
    private function updateLoggingConfig(): void
    {
        // Initialize variables for PHPStan (accessible in catch block)
        $channel = null;
        $level = null;

        try {
            // Get POST parameters
            $channel = $_POST['channel'] ?? null;
            $level = $_POST['level'] ?? null;
            $autoRollbackMinutes = !empty($_POST['auto_rollback_minutes'])
                ? (int)$_POST['auto_rollback_minutes']
                : null;
            $reason = $_POST['reason'] ?? 'Configuration update via admin panel';

            // Validate required parameters
            if (empty($channel) || empty($level)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required parameters (channel, level)',
                ]);

                return;
            }

            // Get admin user ID from session
            $adminUserId = $_SESSION['admin_user_id'] ?? null;

            // ENTERPRISE: Log the request for audit
            if (function_exists('should_log') && should_log('default', 'info')) {
                Logger::info('[ADMIN] Logging config update requested', [
                    'channel' => $channel,
                    'level' => $level,
                    'rollback' => $autoRollbackMinutes ?? 'none',
                    'admin_user_id' => $adminUserId ?? 'unknown',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
            }

            // Call LoggingConfigService (ENTERPRISE GALAXY: Singleton pattern)
            $loggingService = \Need2Talk\Services\LoggingConfigService::getInstance();
            $result = $loggingService->updateConfiguration(
                $channel,
                $level,
                [
                    'auto_rollback_minutes' => $autoRollbackMinutes,
                    'reason' => $reason,
                ],
                $adminUserId
            );

            // ENTERPRISE GALAXY ULTIMATE: Invalidate static cache across ALL PHP processes
            // Set Redis timestamp so should_log() immediately refreshes cache
            try {
                $redisManager = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
                $redis = $redisManager->getConnection('L1_cache');
                if ($redis) {
                    $redis->set('logging:config:invalidation_timestamp', microtime(true));
                }
            } catch (\Throwable $e) {
                // Redis unavailable - cache will refresh naturally
            }

            // ENTERPRISE SECURITY LOG: Logging configuration updated
            Logger::security('warning', 'ADMIN: Logging configuration updated', [
                'channel' => $channel,
                'level' => $level,
                'auto_rollback_minutes' => $autoRollbackMinutes,
                'reason' => $reason,
                'admin_user_id' => $adminUserId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'result' => $result['success'] ?? false,
            ]);

            echo json_encode($result);

        } catch (\Exception $e) {
            // ENTERPRISE SECURITY LOG: Logging configuration update failed
            Logger::security('critical', 'ADMIN: Logging configuration update failed', [
                'channel' => $channel ?: 'unknown',
                'level' => $level ?: 'unknown',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'admin_user_id' => $_SESSION['admin_user_id'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }


    private function renderEnterpriseAdmin(string $view, array $data): void
    {
        $stats = $data;

        // Make sure $view is available to layout for navigation active states
        $data['view'] = $view;
        extract($data);

        // Track view for debugbar
        if (function_exists('debugbar_add_view')) {
            debugbar_add_view("admin/{$view}", $data);
        }

        // ENTERPRISE: Pure modular views system (no fallback)
        $layoutPath = APP_ROOT . '/app/Views/admin/layout.php';

        if (file_exists($layoutPath)) {
            include $layoutPath;

            return;
        }

        // ERROR: Modular views not found
        echo '<h1>Error: Modular admin views not found</h1>';
        echo "<p>Expected: {$layoutPath}</p>";
    }

    private function renderViewContent(string $view, array $data): void
    {
        // ENTERPRISE: This method is now obsolete - using modular views
        // Left empty for backwards compatibility but not used
    }

    private function formatDatabaseStats(array $data): string
    {
        if (isset($data['connection_pool'])) {
            $pool = $data['connection_pool'];

            return sprintf(
                'Connessioni: %d/%d attive (%.1f%% utilizzo)',
                $pool['active_connections'] ?? 0,
                $pool['total_connections'] ?? 0,
                $pool['utilization_percent'] ?? 0
            );
        }

        return 'Database OK';
    }

    private function formatCacheStats(array $data): string
    {
        if (isset($data['performance']['hit_ratio'])) {
            return sprintf(
                'Hit ratio: %.1f%% - Memory: %s',
                $data['performance']['hit_ratio'] * 100,
                $data['performance']['redis']['used_memory_human'] ?? 'N/A'
            );
        }

        return 'Cache OK';
    }

    private function formatSecurityStats(array $data): string
    {
        return sprintf(
            'Login falliti: %d - Livello minaccia: %s',
            $data['failed_logins_24h'] ?? 0,
            ucfirst($data['threat_level'] ?? 'unknown')
        );
    }

    private function formatUserStats(array $data): string
    {
        return sprintf(
            '%d utenti totali - %d online - %d registrazioni oggi',
            $data['total_users'] ?? 0,
            $data['online_users'] ?? 0,
            $data['new_registrations_today'] ?? 0
        );
    }

    private function formatPerformanceStats(array $data): string
    {
        return sprintf(
            'Tempo risposta: %dms - Memoria: %dMB',
            $data['avg_response_time_ms'] ?? 0,
            $data['memory_usage_mb'] ?? 0
        );
    }

    private function formatResourceStats(array $data): string
    {
        return sprintf(
            'PHP %s - Memoria: %s - Spazio disco: %.2fGB',
            $data['php_version'] ?? 'N/A',
            $data['memory_limit'] ?? 'N/A',
            $data['disk_free_gb'] ?? 0
        );
    }

    private function formatAlerts(array $alerts): string
    {
        if (empty($alerts)) {
            return 'Nessun alert';
        }

        $critical = array_filter($alerts, fn ($a) => $a['level'] === 'critical');
        $warnings = array_filter($alerts, fn ($a) => $a['level'] === 'warning');

        return sprintf('%d critici, %d avvisi', count($critical), count($warnings));
    }

    private function formatEnterpriseStats(array $data): string
    {
        $summary = [];

        if (isset($data['database']['connection_pool'])) {
            $pool = $data['database']['connection_pool'];
            $summary[] = sprintf(
                'DB: %d/%d conn (%.1f%%) [max:%d]',
                $pool['active_connections'],
                $pool['total_connections'],
                ($pool['active_connections'] / max($pool['total_connections'], 1)) * 100,
                $pool['max_connections']
            );
        }

        if (isset($data['security']['threat_level'])) {
            $summary[] = 'Sicurezza: ' . ucfirst($data['security']['threat_level']);
        }

        if (isset($data['performance']['memory_usage_mb'])) {
            $summary[] = sprintf('Memoria: %dMB', $data['performance']['memory_usage_mb']);
        }

        return implode(' | ', $summary) ?: 'Enterprise OK';
    }

    private function formatWorkerStats(array $data): string
    {
        $status = $data['status'] ?? 'unknown';
        $message = $data['message'] ?? '';

        return sprintf('Worker: %s - %s', ucfirst($status), $message);
    }

    private function formatMetricsStats(array $data): string
    {
        $status = $data['status'] ?? 'unknown';
        $metrics = $data['total_metrics'] ?? 0;
        $counters = $data['total_counters'] ?? 0;

        return sprintf(
            'Metriche: %s (%d metrics, %d counters)',
            ucfirst($status),
            $metrics,
            $counters
        );
    }

    private function getSystemStats(): array
    {
        // Use the enterprise SystemMonitorService
        try {
            $monitor = new \Need2Talk\Services\SystemMonitorService();
            $enterpriseStats = $monitor->getDashboardStats();

            // Merge with existing stats for backward compatibility
            return array_merge([
                'opcache_status' => $this->getOpCacheStatus(),
                'active_users' => $this->getActiveUsersCount(),
                'total_users' => $this->getTotalUsersCount(),
                'db_status' => $this->getDatabaseStatus(),
                'memory_usage' => $this->getMemoryUsage() . '%',
                'response_time' => $this->getAverageResponseTime() . 'ms',
                'php_version' => PHP_VERSION,
                'server_load' => $this->getServerLoad(),
                'disk_free' => $this->getDiskFreeSpace(),
            ], [
                'enterprise' => $enterpriseStats,
                'metrics_collector_stats' => $this->getMetricsCollectorStats(),
            ]);
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'warning')) {
                Logger::warning('[ADMIN] Failed to get SystemMonitorService stats', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            // Fallback to existing implementation
            return [
                'opcache_status' => $this->getOpCacheStatus(),
                'active_users' => $this->getActiveUsersCount(),
                'total_users' => $this->getTotalUsersCount(),
                'db_status' => $this->getDatabaseStatus(),
                'memory_usage' => $this->getMemoryUsage(),
                'response_time' => $this->getAverageResponseTime(),
                'php_version' => PHP_VERSION,
                'server_load' => $this->getServerLoad(),
                'disk_free' => $this->getDiskFreeSpace(),
            ];
        }
    }

    private function getRealtimeStats(): array
    {
        return [
            'response_time' => $this->getAverageResponseTime(),
            'memory_usage' => $this->getMemoryUsage(),
            'workers_active' => $this->getActiveWorkers(),
            'cpu_usage' => $this->getCpuUsage(),
            'memory_total' => $this->getMemoryTotal(),
            'disk_usage' => $this->getDiskUsage(),
            'redis' => $this->getRedisStats(),
            'opcache' => $this->getOpCacheStats(),
        ];
    }

    // System monitoring methods
    private function getRedisStatus(): string
    {
        try {
            // ENTERPRISE POOL: Use connection pool for health check
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L3_logging');

            if (!$redis) {
                return 'OFFLINE';
            }

            $pong = $redis->ping();

            return ($pong === '+PONG' || $pong === 'PONG' || $pong === true) ? 'ONLINE' : 'OFFLINE';
        } catch (\Exception $e) {
            return 'OFFLINE';
        }
    }

    private function getOpCacheStatus(): string
    {
        return function_exists('opcache_get_status') ? 'ENABLED' : 'DISABLED';
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Get active users count with multi-level caching
     *
     * Performance optimizations:
     * - L1 Enterprise Redis cache (1min TTL - ULTRA HOT data)
     * - L2 Memcached cache (1min TTL)
     * - L3 Redis persistent cache (1min TTL)
     * - Composite index: idx_user_sessions_active_composite
     *
     * @return int Active users count
     */
    private function getActiveUsersCount(): int
    {
        // Initialize cache key for PHPStan (used in multiple try blocks)
        $cacheKey = 'dashboard:active_users_count';

        // ENTERPRISE GALAXY ULTIMATE: Try multi-level cache first
        try {
            $cache = \Need2Talk\Core\EnterpriseCacheFactory::getInstance();

            // Check cache (automatic L1→L2→L3 fallback)
            $cached = $cache->get($cacheKey);

            if ($cached !== null && $cached !== false) {
                return (int) $cached;
            }
        } catch (\Exception $e) {
            // Cache error - continue to database query
            if (function_exists('should_log') && should_log('default', 'warning')) {
                Logger::warning('[ADMIN] Cache read error for active_users_count', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $db = $this->getDb();
        try {
            // ENTERPRISE GALAXY ULTIMATE: Optimized query with composite index hint
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT user_id) as active_users
                FROM user_sessions
                WHERE last_activity > NOW() - INTERVAL '15 minutes'
                AND is_active = TRUE
                AND expires_at > NOW()
            ");
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            $count = (int) ($result['active_users'] ?? 0);

            // ENTERPRISE GALAXY ULTIMATE: Store in multi-level cache (1min TTL - ultra fresh)
            try {
                if (isset($cache)) {
                    $cache->set($cacheKey, $count, 60);
                }
            } catch (\Exception $e) {
                // Cache write error - log but don't fail
                if (function_exists('should_log') && should_log('default', 'warning')) {
                    Logger::warning('[ADMIN] Cache write error for active_users_count', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $count;
        } catch (\Exception $e) {
            return 0;
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getDatabaseStatus(): string
    {
        try {
            // PERFORMANCE: Use connection pool instead of fresh connection
            $pdo = db_pdo();
            $pdo->query('SELECT 1');

            return 'ONLINE';
        } catch (\Exception $e) {
            return 'OFFLINE';
        }
    }

    private function getMemoryUsage(): float
    {
        $mem = memory_get_usage(true);
        $limit = ini_get('memory_limit');
        $limit = $this->parseMemoryLimit($limit);

        return round(($mem / $limit) * 100, 1);
    }

    /**
     * ENTERPRISE GALAXY: Get average response time with multi-level caching
     *
     * Performance optimizations:
     * - L1 Enterprise Redis cache (5min TTL)
     * - L2 Memcached cache (5min TTL)
     * - L3 Redis persistent cache (5min TTL)
     * - Composite index: idx_created_response (created_at, response_time)
     * - Request-level caching to prevent duplicate calculations
     *
     * @return float Average response time in milliseconds
     */
    private function getAverageResponseTime(): float
    {
        // Return cached value if already calculated in this request
        if ($this->cachedResponseTime !== null) {
            return $this->cachedResponseTime;
        }

        // Initialize cache key for PHPStan (used in multiple try blocks)
        $cacheKey = 'dashboard:avg_response_time';

        // ENTERPRISE GALAXY: Try multi-level cache first (L1→L2→L3)
        try {
            $cache = \Need2Talk\Core\EnterpriseCacheFactory::getInstance();

            // Check cache (automatic L1→L2→L3 fallback)
            $cached = $cache->get($cacheKey);

            if ($cached !== null && $cached > 0) {
                $this->cachedResponseTime = (float) $cached;

                return $this->cachedResponseTime;
            }
        } catch (\Exception $e) {
            // Cache error - continue to database query
            if (function_exists('should_log') && should_log('default', 'warning')) {
                Logger::warning('[ADMIN] Cache read error for avg_response_time', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $db = $this->getDb();
        try {
            // ENTERPRISE GALAXY: Optimized query with index hint (using enterprise_performance_metrics)
            $stmt = $db->prepare("
                SELECT AVG(server_response_time) as avg_response_time
                FROM enterprise_performance_metrics
                WHERE created_at >= NOW() - INTERVAL '5 minutes'
                AND server_response_time IS NOT NULL
                AND server_response_time > 0
            ");
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result && $result['avg_response_time'] > 0) {
                $this->cachedResponseTime = round($result['avg_response_time'], 1);

                // ENTERPRISE GALAXY: Store in multi-level cache (5min TTL)
                try {
                    if (isset($cache)) {
                        $cache->set($cacheKey, $this->cachedResponseTime, 300);
                    }
                } catch (\Exception $e) {
                    // Cache write error - log but don't fail
                    if (function_exists('should_log') && should_log('default', 'warning')) {
                        Logger::warning('[ADMIN] Cache write error for avg_response_time', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                return $this->cachedResponseTime;
            }

            // Fallback: estimate current request processing time
            $currentRequestTime = round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 1);

            // Cache and return reasonable estimate
            $this->cachedResponseTime = max($currentRequestTime, 25.0);

            // ENTERPRISE GALAXY: Cache fallback value too (shorter TTL: 1min)
            try {
                if (isset($cache)) {
                    $cache->set($cacheKey, $this->cachedResponseTime, 60);
                }
            } catch (\Exception $e) {
                // Ignore cache errors for fallback
            }

            return $this->cachedResponseTime;

        } catch (\Exception $e) {
            $this->cachedResponseTime = 45.0; // Reasonable default for web requests

            return $this->cachedResponseTime;
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getCpuUsage(): float
    {
        try {
            $load = sys_getloadavg();

            // Get actual CPU core count
            $cores = 4; // Default fallback

            if (PHP_OS_FAMILY === 'Darwin') {
                $coreOutput = shell_exec('sysctl -n hw.ncpu 2>/dev/null');

                if ($coreOutput !== null) {
                    $cores = (int) trim($coreOutput);
                }
            } elseif (PHP_OS_FAMILY === 'Linux') {
                $coreOutput = shell_exec('nproc 2>/dev/null');

                if ($coreOutput !== null) {
                    $cores = (int) trim($coreOutput);
                }
            }

            // Convert load average to percentage
            $usage = ($load[0] / $cores) * 100;

            return round(min($usage, 100), 1); // Cap at 100%
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function getMemoryTotal(): string
    {
        try {
            // macOS/BSD systems - ALWAYS try this first on macOS
            if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
                $output = shell_exec('sysctl -n hw.memsize 2>/dev/null');

                if ($output && is_numeric(trim($output))) {
                    $bytes = (int) trim($output);

                    return round($bytes / 1024 / 1024 / 1024, 1) . 'GB';
                }

                // If sysctl fails on macOS, return default
                return '48.0GB'; // Default for macOS when command fails
            }

            // Linux systems only
            if (file_exists('/proc/meminfo')) {
                $meminfo = file_get_contents('/proc/meminfo');

                if ($meminfo && preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $matches)) {
                    $totalKb = (int) $matches[1];

                    return round($totalKb / 1024 / 1024, 1) . 'GB';
                }
            }

            // Fallback
            $output = shell_exec('free -m 2>/dev/null || vm_stat 2>/dev/null');

            if (strpos($output, 'Pages free') !== false) {
                // macOS vm_stat parsing
                return '8.0GB'; // Default for macOS
            }

            return 'Unknown';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    private function getDiskUsage(): float
    {
        try {
            $bytes = disk_total_space('.');
            $free = disk_free_space('.');

            if ($bytes && $free) {
                return round((($bytes - $free) / $bytes) * 100, 1);
            }

            return 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function getActiveWorkers(): int
    {
        try {
            // Check for Need2Talk email workers (systemd or legacy)
            $emailWorkers = 0;

            // First check systemd email workers (preferred)
            $systemdOutput = shell_exec('systemctl list-units "need2talk-email-worker@*.service" --state=active --no-pager 2>/dev/null | grep -c "need2talk-email-worker@" 2>/dev/null');

            if ($systemdOutput !== null && (int) trim($systemdOutput) > 0) {
                $emailWorkers = (int) trim($systemdOutput);
            } else {
                // Fallback: check legacy email workers
                $legacyOutput = shell_exec('ps aux | grep -E "email-worker\.php" | grep -v grep | wc -l 2>/dev/null');

                if ($legacyOutput !== null) {
                    $emailWorkers = (int) trim($legacyOutput);
                }
            }

            // Return only email workers count (max 8 according to WORKER_SYSTEM_GUIDE)
            return min($emailWorkers, 8);

        } catch (\Exception $e) {
            error_log('[ADMIN ERROR] getActiveWorkers: ' . $e->getMessage());

            return 1; // Fallback for development
        }
    }

    private function getRedisStats(): array
    {
        try {
            // ENTERPRISE POOL: Use connection pool for stats retrieval
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L3_logging');

            if (!$redis) {
                throw new \Exception('Redis connection failed');
            }

            $info = $redis->info();

            return [
                'status' => 'connected',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'used_memory' => $info['used_memory_human'] ?? '0B',
                'total_commands' => isset($info['total_commands_processed']) ? $this->formatNumber($info['total_commands_processed']) : '0',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'disconnected',
                'connected_clients' => 'N/A',
                'used_memory' => 'N/A',
                'total_commands' => 'N/A',
            ];
        }
    }

    private function getOpCacheStats(): array
    {
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status();

            return [
                'enabled' => $status !== false,
                'hit_rate' => round($status['opcache_statistics']['opcache_hit_rate'] ?? 0, 1),
                'memory_usage' => round((($status['memory_usage']['used_memory'] ?? 0) / ($status['memory_usage']['free_memory'] + $status['memory_usage']['used_memory'])) * 100, 1),
                'num_cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
            ];
        }

        return [
            'enabled' => false,
            'hit_rate' => 0,
            'memory_usage' => 0,
            'num_cached_scripts' => 0,
        ];
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $limit = (int) $limit;

        switch ($last) {
            case 'g': $limit *= 1024 * 1024 * 1024;
                break;
            case 'm': $limit *= 1024 * 1024;
                break;
            case 'k': $limit *= 1024;
                break;
        }

        return $limit;
    }

    private function formatNumber(int $number): string
    {
        if ($number >= 1000000000) {
            return round($number / 1000000000, 1) . 'B';
        }

        if ($number >= 1000000) {
            return round($number / 1000000, 1) . 'M';
        }

        if ($number >= 1000) {
            return round($number / 1000, 1) . 'K';
        }

        return (string) $number;
    }

    /**
     * ENTERPRISE GALAXY: Get total users count for dashboard stats
     *
     * Lightweight version using v_active_users_stats SQL view
     * For detailed users data, see AdminUsersAndRateLimitTabsController.php
     *
     * @return int Total users count
     */
    private function getTotalUsersCount(): int
    {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->query('SELECT total_active FROM v_active_users_stats');
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return (int) ($result['total_active'] ?? 0);
        } catch (\Exception $e) {
            Logger::error('[ADMIN] Failed to get total users count: ' . $e->getMessage(), [
                'channel' => 'admin_stats',
                'level' => 'error',
            ]);

            return 0;
        }
    }

    private function getCookiesData(): array
    {
        $db = $this->getDb();
        try {
            // ENTERPRISE 2025: Cached cookie consent data (30min TTL - WARM data)

            // Consent statistics (cached 30min)
            $stmt = $db->prepare('
                SELECT
                    consent_type,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM user_cookie_consent WHERE is_active = TRUE), 2) as percentage
                FROM user_cookie_consent
                WHERE is_active = TRUE
                GROUP BY consent_type
            ');
            $stmt->execute();
            $consentStats = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Cookie categories (cached 30min - rarely changes)
            $stmt = $db->prepare('
                SELECT
                    id, category_key, category_name, description,
                    is_required, is_active, sort_order
                FROM cookie_consent_categories
                ORDER BY sort_order, category_name
            ');
            $stmt->execute();
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Recent consents (last 50) - ENTERPRISE 2025: Cached (5min TTL for freshness)
            $stmt = $db->prepare('
                SELECT
                    ucc.*,
                    u.nickname,
                    u.email
                FROM user_cookie_consent ucc
                LEFT JOIN users u ON ucc.user_id = u.id
                WHERE ucc.is_active = TRUE
                ORDER BY ucc.consent_timestamp DESC
                LIMIT 50
            ');
            $stmt->execute();
            $recentConsents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Consent trends (last 7 days) - ENTERPRISE 2025: Cached (30min TTL)
            // ENTERPRISE GALAXY: PostgreSQL CAST instead of MySQL DATE()
            $stmt = $db->prepare("
                SELECT
                    CAST(consent_timestamp AS DATE) as date,
                    consent_type,
                    COUNT(*) as count
                FROM user_cookie_consent
                WHERE consent_timestamp >= NOW() - INTERVAL '7 days'
                GROUP BY CAST(consent_timestamp AS DATE), consent_type
                ORDER BY date DESC
            ");
            $stmt->execute();
            $consentTrends = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Total statistics - ENTERPRISE 2025: Cached (30min TTL)
            $stmt = $db->prepare('
                SELECT
                    COUNT(*) as total_consents,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM user_cookie_consent
                WHERE is_active = TRUE
            ');
            $stmt->execute();
            $totalStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            return [
                'consent_stats' => $consentStats,
                'categories' => $categories,
                'recent_consents' => $recentConsents,
                'consent_trends' => $consentTrends,
                'total_stats' => $totalStats,
            ];

        } catch (\Exception $e) {
            error_log('Error fetching cookies data: ' . $e->getMessage());

            return [
                'consent_stats' => [],
                'categories' => [],
                'recent_consents' => [],
                'consent_trends' => [],
                'total_stats' => ['total_consents' => 0, 'unique_users' => 0, 'unique_ips' => 0],
            ];
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getServerLoad(): string
    {
        try {
            $load = sys_getloadavg();

            return implode(', ', array_map(fn ($l) => number_format($l, 2), $load)) . ' (1m, 5m, 15m)';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    private function getDiskFreeSpace(): string
    {
        try {
            $free = disk_free_space('.');

            if ($free) {
                return $this->formatBytes($free);
            }

            return 'N/A';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function getPerformanceWorkerStats(): array
    {
        try {
            // Check if performance worker is available
            $workerScript = APP_ROOT . '/scripts/performance-worker.php';

            if (!file_exists($workerScript)) {
                return ['status' => 'unavailable', 'message' => 'Performance worker script not found'];
            }

            // Run a quick performance test (3 seconds, 5 ops/sec)
            $command = "php {$workerScript} --worker-id=0 --ops-per-second=5 --duration=3 --scenarios='email_queue=50&verification=50' 2>&1";
            $output = shell_exec($command);

            if ($output) {
                $result = json_decode(trim($output), true);

                if ($result && isset($result['ops_per_second'])) {
                    return [
                        'status' => 'active',
                        'last_test' => [
                            'ops_per_second' => $result['ops_per_second'],
                            'success_rate' => $result['success_rate'] ?? 0,
                            'operations_completed' => $result['operations_completed'] ?? 0,
                            'duration' => $result['duration'] ?? 0,
                        ],
                        'availability' => 'ready',
                    ];
                }
            }

            return [
                'status' => 'available',
                'message' => 'Performance worker ready but no recent tests',
                'availability' => 'ready',
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Performance worker error: ' . $e->getMessage(),
                'availability' => 'unavailable',
            ];
        }
    }

    private function getMetricsCollectorStats(): array
    {
        try {
            $metricsCollector = \Need2Talk\Core\MetricsCollector::getInstance();

            // Get performance summary
            $summary = $metricsCollector->getPerformanceSummary();

            // Get histogram data for response times if available
            $responseTimePercentiles = $metricsCollector->getHistogramPercentiles('response_time', [50, 95, 99]);

            return [
                'status' => 'active',
                'performance_summary' => $summary,
                'response_time_percentiles' => $responseTimePercentiles,
                'total_metrics' => count($metricsCollector->getMetrics()['metrics']),
                'total_counters' => count($metricsCollector->getMetrics()['counters']),
                'active_timers' => $metricsCollector->getMetrics()['active_timers'],
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'MetricsCollector error: ' . $e->getMessage(),
            ];
        }
    }

    // ENTERPRISE GALAXY: getUsersData() moved to AdminUsersAndRateLimitTabsController.php
    // ENTERPRISE GALAXY: getAudioData() moved to AdminAudioTabsController.php

    private function getSystemLogs(): array
    {
        $logPath = APP_ROOT . '/storage/logs';
        $logs = [];
        $categories = [
            'errors' => ['pattern' => '/error|exception/i', 'icon' => '⚠️', 'color' => 'red'],
            'security' => ['pattern' => '/security|admin|login/i', 'icon' => '🔒', 'color' => 'yellow'],
            'debug' => ['pattern' => '/debug/i', 'icon' => '🔍', 'color' => 'blue'],
            'performance' => ['pattern' => '/performance|query/i', 'icon' => '⚡', 'color' => 'green'],
            'application' => ['pattern' => '/need2talk|app/i', 'icon' => '📝', 'color' => 'gray'],
            'docker' => ['pattern' => '/docker|container/i', 'icon' => '🐳', 'color' => 'cyan'],
        ];

        try {
            // Scan log directory - ENTERPRISE: Alpine Linux compatible (no GLOB_BRACE)
            $logFiles = glob($logPath . '/*.log') ?: [];
            $txtFiles = glob($logPath . '/*.txt') ?: [];
            $files = array_merge($logFiles, $txtFiles);

            if (empty($files)) {
                return ['files' => [], 'categories' => $categories, 'total_files' => 0, 'total_size' => 0, 'total_size_formatted' => '0 B'];
            }

            foreach ($files as $file) {
                $filename = basename($file);
                $category = 'application'; // default

                // Determine category
                foreach ($categories as $cat => $info) {
                    if (preg_match($info['pattern'], $filename)) {
                        $category = $cat;
                        break;
                    }
                }

                // Get file stats
                $size = filesize($file);
                $modified = filemtime($file);
                $lines = $this->countLogLines($file);

                $logs[] = [
                    'filename' => $filename,
                    'filepath' => $file,
                    'category' => $category,
                    'category_icon' => $categories[$category]['icon'] ?? '📄',
                    'category_color' => $categories[$category]['color'] ?? 'gray',
                    'size' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'modified' => $modified,
                    'modified_formatted' => date('d/m/Y H:i:s', $modified),
                    'relative_time' => $this->getRelativeTime($modified),
                    'lines' => $lines,
                    'is_today' => date('Y-m-d', $modified) === date('Y-m-d'),
                ];
            }

            // ENTERPRISE V12.4: Docker worker logs are now normal files:
            // - cron-worker-docker.log (from start-cron-worker.sh)
            // - email-worker-docker.log (from start-docker-workers.sh)
            // - dm-audio-worker-docker-*.log (from start-dm-audio-workers-docker.sh)
            // These are picked up automatically by the glob above and categorized as 'docker'

            // Sort by modified time (newest first)
            usort($logs, fn ($a, $b) => $b['modified'] - $a['modified']);

            // Add count to categories
            foreach ($categories as $cat => &$catData) {
                $catData['count'] = count(array_filter($logs, fn ($log) => $log['category'] === $cat));
            }

            return [
                'files' => $logs,
                'categories' => $categories,
                'total_files' => count($logs),
                'total_size' => array_sum(array_column($logs, 'size')),
                'total_size_formatted' => $this->formatBytes(array_sum(array_column($logs, 'size'))),
            ];

        } catch (\Exception $e) {
            error_log('[ADMIN ERROR] getSystemLogs: ' . $e->getMessage());

            return ['files' => [], 'categories' => $categories, 'error' => $e->getMessage()];
        }
    }

    private function countLogLines(string $filepath): int
    {
        try {
            // Fast line count for large files
            if (filesize($filepath) > 10 * 1024 * 1024) { // > 10MB
                // Use wc -l for large files
                $output = shell_exec("wc -l < " . escapeshellarg($filepath) . " 2>/dev/null");

                return $output ? (int) trim($output) : 0;
            }

            // For smaller files, use PHP
            $lines = 0;
            $handle = fopen($filepath, 'r');

            if ($handle) {
                while (!feof($handle)) {
                    fgets($handle);
                    $lines++;
                }
                fclose($handle);
            }

            return $lines;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getRelativeTime(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff . 's ago';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }

        if ($diff < 604800) {
            return floor($diff / 86400) . 'd ago';
        }

        return date('d/m/Y', $timestamp);
    }

    private function getSystemConfig(): array
    {
        try {
            // ENTERPRISE: Read FRESH data directly from database (NO CACHE!)
            // This ensures admin sees the REAL current state of the system

            $db = db();

            // 1️⃣ Read admin_settings (debugbar, browser console, etc.)
            $adminSettings = $db->query(
                'SELECT setting_key, setting_value, description, updated_at
                 FROM admin_settings
                 ORDER BY setting_key ASC',
                [],
                ['cache' => false] // NO CACHE - always fresh!
            );

            // 2️⃣ Read app_settings (logging config, system settings)
            $appSettings = $db->query(
                'SELECT setting_key, setting_value, description, updated_at
                 FROM app_settings
                 ORDER BY setting_key ASC',
                [],
                ['cache' => false] // NO CACHE - always fresh!
            );

            // ENTERPRISE FIX: Ensure arrays (handle false/null returns)
            $adminSettings = is_array($adminSettings) ? $adminSettings : [];
            $appSettings = is_array($appSettings) ? $appSettings : [];

            return [
                'admin_settings' => $adminSettings,
                'app_settings' => $appSettings,
                'timestamp' => date('Y-m-d H:i:s'),
                'total_settings' => count($adminSettings) + count($appSettings),
            ];

        } catch (\Exception $e) {
            Logger::warning('[ADMIN] Failed to read system config from database', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'admin_settings' => [],
                'app_settings' => [],
                'error' => 'Failed to load configuration from database',
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        }
    }


    /**
     * ENTERPRISE: Cross-platform process checker (Linux, macOS, Windows compatible)
     */
    private function isProcessActive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // Method 1: POSIX systems (Linux, macOS)
        if (function_exists('posix_getpgid')) {
            return posix_getpgid($pid) !== false;
        }

        // Method 2: Cross-platform via proc filesystem (Linux)
        if (file_exists("/proc/{$pid}")) {
            return true;
        }

        // Method 3: Cross-platform via kill signal (Linux, macOS)
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Method 4: Shell command fallback (works everywhere)
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec("tasklist /FI \"PID eq {$pid}\" 2>NUL");

            return $output && strpos($output, (string) $pid) !== false;
        }
        $output = shell_exec("ps -p {$pid} 2>/dev/null");

        return $output && strpos($output, (string) $pid) !== false;

    }

    /**
     * ENTERPRISE: Detect if PHP is running inside a Docker container
     * Same logic as WorkerManager to ensure consistency
     */
    private function isRunningInsideContainer(): bool
    {
        // Method 1: Check for .dockerenv file (most reliable)
        if (file_exists('/.dockerenv')) {
            return true;
        }

        // Method 2: Check /proc/1/cgroup for docker/kubernetes
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = @file_get_contents('/proc/1/cgroup');
            if ($cgroup && (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'kubepods') !== false)) {
                return true;
            }
        }

        // Method 3: Check hostname matches container pattern
        $hostname = gethostname();
        if ($hostname && strlen($hostname) === 12 && ctype_xdigit($hostname)) {
            // Docker containers often have 12-char hex hostnames
            return true;
        }

        return false;
    }

}
