<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseGlobalsManager;

/**
 * DebugbarService - Enterprise Debugbar Integration for Lightning Framework
 *
 * ENTERPRISE DEBUGBAR FEATURES:
 * - Admin-controlled activation via dashboard toggle
 * - Lightning Framework optimized performance
 * - Standalone DebugBar integration (no Laravel dependency)
 * - Enterprise security with admin-only access
 * - Query tracking and performance metrics
 */
class DebugbarService
{
    private static ?self $instance = null;

    private static ?object $debugbar = null;

    private static bool $enabled = false;

    private static bool $initialized = false;

    private static array $settings = [];

    // ENTERPRISE: Settings cache with request-level persistence
    private static ?array $settingsCache = null;

    private static int $initCallCount = 0;

    private static float $firstInitTime = 0;

    private function __construct()
    {
        // Constructor privato per singleton pattern
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize Debugbar based on admin settings
     */
    public static function initialize(bool $force = false): void
    {
        // ENTERPRISE: Track initialization calls for monitoring
        self::$initCallCount++;

        if (self::$firstInitTime === 0) {
            self::$firstInitTime = microtime(true);
        }

        if (self::$initialized && !$force) {
            return; // Already initialized
        }

        try {

            // Load debugbar settings from admin_settings
            self::$settings = self::getDebugbarSettings();

            if (!self::$settings['debugbar_enabled']) {
                // ENTERPRISE TIPS: Reset all static variables when disabled
                self::$enabled = false;
                self::$debugbar = null;
                self::$initialized = true;

                return; // Debugbar disabled
            }

            // Check if admin-only and user is not admin
            $isAdmin = self::isAdminUser();

            if (self::$settings['debugbar_admin_only'] && !$isAdmin) {
                // ENTERPRISE TIPS: Reset all static variables when blocked
                self::$enabled = false;
                self::$debugbar = null;
                self::$initialized = true;

                return; // Not admin user
            }

            // Check if DebugBar is available
            if (!self::isDebugbarAvailable()) {
                // ENTERPRISE TIPS: Reset all static variables when unavailable
                self::$enabled = false;
                self::$debugbar = null;
                self::$initialized = true;

                return;
            }

            // Initialize standard DebugBar
            self::initializeDebugbar();

            self::$initialized = true;
            self::$enabled = true;

        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'error')) {
                Logger::error('[DEBUGBAR] Initialization failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            self::$initialized = true;
        }
    }

    /**
     * Add PDO connection to debugbar for automatic query tracking
     */
    public static function addPDO($pdo): void
    {
        if (!self::isEnabled() || !self::$debugbar || !self::$debugbar->hasCollector('pdo')) {
            return;
        }

        try {
            $collector = self::$debugbar->getCollector('pdo');

            // Handle TrackedPDO wrapper - register the original PDO
            if ($pdo instanceof \Need2Talk\Services\TrackedPDO) {
                // Get the underlying PDO instance
                $realPdo = $pdo->getOriginalPDO();
                $collector->addConnection($realPdo, 'main');
            } else {
                // Regular PDO
                $collector->addConnection($pdo, 'main');
            }
        } catch (\Throwable $e) {
            // Ultra-safe: never let debugbar errors affect main functionality
        }
    }

    /**
     * Add debug message
     */
    public function addMessage(string $message, string $type = 'info'): void
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            self::$debugbar['messages']->addMessage($message, $type);
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to add message', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ENTERPRISE: Track query from TrackedPDO wrapper (ultra-safe)
     */
    public static function trackQuery(string $sql, array $params, float $duration, bool $failed): void
    {
        if (!self::isEnabled() || !self::$debugbar) {
            return;
        }

        try {
            // ENTERPRISE TIPS: Filter out PDO internal commands (SET, ROLLBACK, RESET CONNECTION)
            // These are PostgreSQL session management commands that clutter DebugBar
            $sqlUpper = strtoupper(trim($sql));
            // ENTERPRISE: PostgreSQL internal commands (filter from debugbar query log)
            $internalCommands = ['SET SESSION', 'DISCARD ALL', 'ROLLBACK', 'SET NAMES', 'BEGIN', 'COMMIT'];

            foreach ($internalCommands as $command) {
                if (str_starts_with($sqlUpper, $command)) {
                    return; // Skip internal PDO commands
                }
            }

            // Add query info to messages collector for visibility
            if (self::$debugbar->hasCollector('messages')) {
                $status = $failed ? 'FAILED' : 'SUCCESS';
                $durationMs = number_format($duration * 1000, 2);
                $message = "[{$status}] {$sql} | {$durationMs}ms";

                if (!empty($params)) {
                    $message .= ' | Params: ' . json_encode($params);
                }
                self::$debugbar['messages']->addMessage($message, $failed ? 'error' : 'debug');
            }

            // CRITICAL: Add to our custom TrackedQueriesCollector
            if (self::$debugbar->hasCollector('tracked_queries')) {
                TrackedQueriesCollector::addQuery($sql, $params, $duration, $failed);
            }
        } catch (\Throwable $e) {
            // Ultra-safe: never let debugbar errors affect main functionality
            error_log('[DEBUGBAR] Error in trackQuery: ' . $e->getMessage());
        }
    }

    /**
     * Manually add SQL query to debugbar (for Enterprise Database Pool compatibility)
     */
    public static function addQuery(string $sql, array $params = [], float $duration = 0, bool $failed = false): void
    {
        self::trackQuery($sql, $params, $duration, $failed);
    }

    /**
     * Start measuring execution time
     */
    public function startMeasure(string $name, ?string $label = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            self::$debugbar['time']->startMeasure($name, $label ?? $name);
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to start measure', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Stop measuring execution time
     */
    public function stopMeasure(string $name): void
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            self::$debugbar['time']->stopMeasure($name);
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to stop measure', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Measure function execution
     */
    public function measure(string $label, callable $callback)
    {
        if (!self::isEnabled()) {
            return $callback();
        }

        $this->startMeasure($label);

        try {
            $result = $callback();
            $this->stopMeasure($label);

            return $result;
        } catch (\Exception $e) {
            $this->stopMeasure($label);

            throw $e;
        }
    }

    /**
     * Alias for addMessage with info level
     */
    public function log(string $message, array $context = []): void
    {
        $this->addMessage($message, 'info');
    }

    /**
     * Add real exception to debugbar
     */
    public static function addException(\Throwable $e): void
    {
        if (!self::isEnabled() || !self::$debugbar || !self::$debugbar->hasCollector('exceptions')) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] addException called but debugbar not enabled or no collector', []);
            }

            return;
        }

        try {
            // Use addThrowable() which accepts both Exception and Error
            self::$debugbar['exceptions']->addThrowable($e);
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Exception added to collector', [
                    'exception' => $e->getMessage(),
                ]);
            }

            if (self::$debugbar->hasCollector('messages')) {
                self::$debugbar['messages']->addMessage('Exception caught: ' . $e->getMessage(), 'error');
            }
        } catch (\Throwable $ex) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to add exception', [
                    'error' => $ex->getMessage(),
                ]);
            }
        }
    }

    /**
     * Add request information
     */
    public static function addRequestData(): void
    {
        if (!self::isEnabled() || !self::$debugbar || !self::$debugbar->hasCollector('messages')) {
            return;
        }

        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            self::$debugbar['messages']->addMessage("Request: {$method} {$uri}", 'info');
            self::$debugbar['messages']->addMessage('User Agent: ' . substr($userAgent, 0, 100), 'debug');

            // ENTERPRISE: $_POST is always defined, only check if not empty
            if (!empty($_POST)) {
                self::$debugbar['messages']->addMessage('POST data received: ' . count($_POST) . ' fields', 'info');
            }

            // ENTERPRISE: $_GET is always defined, only check if not empty
            if (!empty($_GET)) {
                self::$debugbar['messages']->addMessage('GET params: ' . implode(', ', array_keys($_GET)), 'debug');
            }
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to add request data', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Render debugbar HTML output
     * GALAXY LEVEL: Uses minified assets for 49KB JS savings (63% smaller)
     */
    public static function render(): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        try {
            // ENTERPRISE FIX (2025-12-08): Force data collection before rendering
            // This ensures views added via addView() are included in the inline data
            self::$debugbar->collect();

            $renderer = self::$debugbar->getJavascriptRenderer();
            $renderer->setBaseUrl('/assets/debugbar');

            // AJAX SUPPORT: Enable AJAX tracking for requests
            $renderer->setOpenHandlerUrl('/debugbar/open');

            $defaultRender = $renderer->render();

            // GALAXY LEVEL: Replace with minified versions (49KB savings!)
            $minifiedRender = self::useMinifiedAssets($defaultRender);

            // ENTERPRISE: Persistent storage script disabled (file not found)
            // TODO: Create /public/assets/debugbar/debugbar-enterprise-storage.js if persistent storage needed
            // $appVersion = env('APP_VERSION', '1.6.1');
            // $storageScript = '<script src="/assets/debugbar/debugbar-enterprise-storage.js?v=' . $appVersion . '"></script>';
            // return $minifiedRender . "\n" . $storageScript;

            return $minifiedRender;
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to render', [
                    'error' => $e->getMessage(),
                ]);
            }

            return '';
        }
    }

    /**
     * ENTERPRISE: Finalize and save DebugBar data for AJAX/API requests
     *
     * This method MUST be called at the end of each request to save data.
     * For HTML responses, this is called automatically via render().
     * For AJAX/JSON responses, this must be called explicitly or via shutdown handler.
     */
    public static function finalize(): void
    {
        // ENTERPRISE: Prevent multiple finalize calls in same request
        static $finalized = false;
        if ($finalized) {
            return;
        }

        if (!self::isEnabled() || !self::$debugbar) {
            return;
        }

        try {
            // ENTERPRISE FIX (2025-12-08): Only save data for requests that have views
            // AJAX requests without views should NOT save data, otherwise they
            // overwrite the main page data in DebugBar's OpenHandler
            if (self::$debugbar->hasCollector('views')) {
                $viewsCollector = self::$debugbar->getCollector('views');
                $viewsData = $viewsCollector->collect();

                // Skip stackData for AJAX requests without views
                // This prevents AJAX calls from overwriting main page data
                if (($viewsData['count'] ?? 0) === 0) {
                    $finalized = true;
                    return; // Don't save empty views data
                }
            }

            // ENTERPRISE: Use stackData() - saves data for OpenHandler
            self::$debugbar->stackData();
            $finalized = true;
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * GALAXY LEVEL: Replace JS assets with minified versions
     * Saves 49KB of JavaScript (63% reduction)
     */
    private static function useMinifiedAssets(string $html): string
    {
        // Check if minified files exist
        $minPath = APP_ROOT . '/public/assets/debugbar-min';
        if (!is_dir($minPath)) {
            return $html; // Fallback to non-minified
        }

        // Replace debugbar.js → debugbar-min/debugbar.js (41KB → 15KB)
        $html = str_replace(
            '/assets/debugbar/debugbar.js',
            '/assets/debugbar-min/debugbar.js',
            $html
        );

        // Replace widgets.js → debugbar-min/widgets.js (33KB → 14KB)
        $html = str_replace(
            '/assets/debugbar/widgets.js',
            '/assets/debugbar-min/widgets.js',
            $html
        );

        // Replace openhandler.js → debugbar-min/openhandler.js (7KB → 4KB)
        $html = str_replace(
            '/assets/debugbar/openhandler.js',
            '/assets/debugbar-min/openhandler.js',
            $html
        );

        return $html;
    }

    /**
     * Render debugbar head (CSS)
     * ENTERPRISE: Includes custom dark theme CSS
     */
    public static function renderHead(): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        try {
            $renderer = self::$debugbar->getJavascriptRenderer();
            $renderer->setBaseUrl('/assets/debugbar');

            // AJAX SUPPORT: Enable AJAX tracking for requests
            $renderer->setOpenHandlerUrl('/debugbar/open');

            $defaultHead = $renderer->renderHead();

            // GALAXY LEVEL: Replace with minified versions (49KB savings!)
            $minifiedHead = self::useMinifiedAssets($defaultHead);

            // ENTERPRISE: Add custom theme based on settings (external CSS file)
            $settings = self::getDebugbarSettings();
            $theme = $settings['debugbar_theme'] ?? 'crt-amber';

            // Generate <link> tag for selected theme
            $appVersion = env('APP_VERSION', '1.6.2');
            $themeLink = '<link rel="stylesheet" type="text/css" href="/assets/debugbar/themes/' . htmlspecialchars($theme) . '.css?v=' . $appVersion . '">';

            return $minifiedHead . "\n" . $themeLink;
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to render head', [
                    'error' => $e->getMessage(),
                ]);
            }

            return '';
        }
    }

    /**
     * Check if debugbar is enabled and available
     */
    public static function isEnabled(): bool
    {
        return self::$enabled && self::$debugbar !== null;
    }

    /**
     * Get debugbar instance for advanced usage
     */
    public static function getDebugbar(): ?object
    {
        return self::$debugbar;
    }

    /**
     * Add PDO connection to debugbar for query tracking
     * Note: Query tracking is now handled by TrackedPDO wrapper
     */
    public function addPDOConnection(\PDO $pdo): void
    {
        // Query tracking is handled automatically by TrackedPDO wrapper
        // No need for manual registration
    }

    /**
     * Add view tracking information
     */
    public static function addView(string $view, array $data = []): void
    {
        if (!self::isEnabled() || !self::$debugbar || !self::$debugbar->hasCollector('views')) {
            return;
        }

        try {
            // CRITICAL FIX: Get the actual collector instance and call addView on it
            /** @var ViewsCollector $viewsCollector */
            $viewsCollector = self::$debugbar->getCollector('views');
            $viewsCollector->addView($view, $data);

            // Also add to messages for visibility
            if (self::$debugbar->hasCollector('messages')) {
                $dataCount = count($data);
                self::$debugbar['messages']->addMessage("View rendered: {$view} ({$dataCount} variables)", 'debug');
            }
        } catch (\Throwable $e) {
            // Ultra-safe: never let debugbar errors affect main functionality
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to add view', [
                    'view' => $view,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ENTERPRISE METHOD: Force complete reset of debugbar state for reactivation
     * Ensures debugbar reinitializes completely when enabled via AJAX
     */
    public static function forceReset(): void
    {
        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[DEBUGBAR] ENTERPRISE: Force reset initiated', []);
        }

        // Reset ALL static variables to pristine state
        self::$instance = null;
        self::$debugbar = null;
        self::$enabled = false;
        self::$initialized = false;
        self::$settings = [];

        // ENTERPRISE: Clear settings cache to force fresh DB load
        self::$settingsCache = null;
        self::$initCallCount = 0;
        self::$firstInitTime = 0;

        // ENTERPRISE: Reset ViewsCollector static data directly
        ViewsCollector::resetViews();

        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[DEBUGBAR] ENTERPRISE: All static variables and cache reset', []);
        }

        // Force immediate reinitialization with fresh settings
        self::initialize(true);

        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[DEBUGBAR] ENTERPRISE: Force reset completed successfully', []);
        }
    }

    /**
     * ENTERPRISE: Get initialization statistics for monitoring
     */
    public static function getInitStats(): array
    {
        return [
            'init_calls' => self::$initCallCount,
            'first_init_time' => self::$firstInitTime,
            'settings_cached' => self::$settingsCache !== null,
            'total_init_time' => self::$firstInitTime > 0 ? microtime(true) - self::$firstInitTime : 0,
            'initialized' => self::$initialized,
            'enabled' => self::$enabled,
        ];
    }

    /**
     * ENTERPRISE: Clear settings cache to force fresh DB load on next request
     * Invalidates both PHP memory cache and Redis cache
     */
    public static function clearSettingsCache(): void
    {
        // Clear PHP request-level cache
        self::$settingsCache = null;

        // ENTERPRISE: Clear Redis cache for immediate effect across all servers
        try {
            $redis = new \Redis();
            // ENTERPRISE PERFORMANCE FIX: Use persistent connection (consistent with app standard)
            if ($redis->pconnect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379), 0.1, 'debugbar_cache_clear')) {
                $redis->select(0); // DB 0 for cache
                $redis->del('debugbar:settings');
                $redis->close();
                \enterprise_log('[DEBUGBAR] Settings cache cleared (PHP + Redis) - next request will load fresh values from DB');
            }
        } catch (\Exception $e) {
            \enterprise_log('[DEBUGBAR] Settings cache cleared (PHP only) - Redis unavailable: ' . $e->getMessage());
        }
    }

    /**
     * Initialize DebugBar with collectors
     */
    private static function initializeDebugbar(): void
    {
        self::$debugbar = new \DebugBar\StandardDebugBar();

        // AJAX SUPPORT: Add file storage for AJAX requests tracking
        try {
            $storage_path = APP_ROOT . '/storage/debugbar';

            if (!is_dir($storage_path)) {
                mkdir($storage_path, 0755, true);
            }
            $fileStorage = new \DebugBar\Storage\FileStorage($storage_path);
            self::$debugbar->setStorage($fileStorage);
        } catch (\Exception $e) {
            // Storage failed, continue without file storage
        }

        // Add performance collectors if enabled (check if not already exists)
        if (self::$settings['debugbar_show_performance']) {
            if (!self::$debugbar->hasCollector('time')) {
                self::$debugbar->addCollector(new \DebugBar\DataCollector\TimeDataCollector());
            }

            if (!self::$debugbar->hasCollector('memory')) {
                self::$debugbar->addCollector(new \DebugBar\DataCollector\MemoryCollector());
            }
        }

        // Add PDO collector for database queries if enabled
        if (self::$settings['debugbar_show_queries']) {
            try {
                // Use standard PDO collector for maximum compatibility
                if (!self::$debugbar->hasCollector('pdo')) {
                    $pdoCollector = new \DebugBar\DataCollector\PDO\PDOCollector();
                    self::$debugbar->addCollector($pdoCollector);
                }
            } catch (\Exception $e) {
                // PDO collector failed, continue without it
            }
        }

        // ENTERPRISE: Views collector with FLAT structure (only strings)
        if (self::$settings['debugbar_collect_views']) {
            if (!self::$debugbar->hasCollector('views')) {
                // ENTERPRISE: addCollector() takes only 1 parameter, collector sets its own name
                $viewsCollector = new ViewsCollector();
                self::$debugbar->addCollector($viewsCollector);
            }
        }

        // Add exceptions collector (always enabled for debugging)
        if (!self::$debugbar->hasCollector('exceptions')) {
            $exceptionsCollector = new \DebugBar\DataCollector\ExceptionsCollector();
            self::$debugbar->addCollector($exceptionsCollector);

            // ENTERPRISE: Don't set exception/error handlers here - they're managed by ErrorHandler/Logger
            // Instead, those handlers will call DebugbarService::addException() when enabled
            // This prevents handler conflicts and ensures proper exception flow

        }

        // Add messages collector for errors and debugging
        if (!self::$debugbar->hasCollector('messages')) {
            self::$debugbar->addCollector(new \DebugBar\DataCollector\MessagesCollector());
        }

        // Add request data collector for AJAX and request info (MISSING TAB FIX)
        if (!self::$debugbar->hasCollector('request')) {
            self::$debugbar->addCollector(new \DebugBar\DataCollector\RequestDataCollector());
        }

        // Add PHP info collector for system information
        if (!self::$debugbar->hasCollector('php')) {
            self::$debugbar->addCollector(new \DebugBar\DataCollector\PhpInfoCollector());
        }

        // Add custom Lightning Framework collector
        if (!self::$debugbar->hasCollector('lightning')) {
            self::$debugbar->addCollector(new LightningFrameworkCollector());
        }

        // Add custom Query collector that works correctly
        if (!self::$debugbar->hasCollector('tracked_queries')) {
            self::$debugbar->addCollector(new TrackedQueriesCollector());
        }

        // Add real initialization messages
        if (self::$debugbar->hasCollector('messages')) {
            self::$debugbar['messages']->addMessage('Debugbar initialized successfully', 'info');
            self::$debugbar['messages']->addMessage('Lightning Framework v1.2.0 loaded', 'success');
            self::$debugbar['messages']->addMessage('Enterprise admin environment detected', 'info');
        }

        // PDO collector is now ready to track real application queries
        if (self::$debugbar->hasCollector('messages') && self::$settings['debugbar_show_queries']) {
            self::$debugbar['messages']->addMessage('PDO collector ready for query tracking', 'debug');
        }

        // Add real timeline measurements
        if (self::$debugbar->hasCollector('time')) {
            // ENTERPRISE TIPS: Use SCRIPT_START_TIME from index.php for accurate bootstrap measurement
            // This prevents Bootstrap Load being larger than Total PHP time
            $startTime = defined('SCRIPT_START_TIME') ? SCRIPT_START_TIME : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            $currentTime = microtime(true);

            self::$debugbar['time']->addMeasure('Request Start', $startTime, $startTime + 0.001);
            self::$debugbar['time']->addMeasure('Bootstrap Load', $startTime, $currentTime);

        }

        // Add real request information
        self::addRequestData();

        // AJAX SUPPORT: Configure JavaScript renderer early for AJAX tracking
        try {
            $renderer = self::$debugbar->getJavascriptRenderer();
            $renderer->setBaseUrl('/assets/debugbar');
            $renderer->setOpenHandlerUrl('/debugbar/open');
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to configure JavaScript renderer', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ENTERPRISE: Register callback with DatabasePool for automatic query tracking (after initialization)
        if (self::$settings['debugbar_show_queries']) {
            self::registerDatabaseCallback();
        }

        // ENTERPRISE (2025-12-08): Register shutdown handler to save DebugBar data
        // This ensures AJAX/API requests have their data saved for OpenHandler retrieval
        // For HTML responses, render() also saves data, but for JSON responses this is critical
        register_shutdown_function([self::class, 'finalize']);
    }

    /**
     * Register database callback for query tracking
     */
    private static function registerDatabaseCallback(): void
    {
        try {
            \Need2Talk\Services\EnterpriseSecureDatabasePool::setDebugbarCallback([self::class, 'trackQuery']);
        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'debug')) {
                Logger::debug('[DEBUGBAR] Failed to register database callback', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check if DebugBar package is available
     */
    private static function isDebugbarAvailable(): bool
    {
        return class_exists('DebugBar\StandardDebugBar');
    }

    /**
     * Get debugbar settings from admin_settings table - ENTERPRISE: With intelligent caching
     */
    private static function getDebugbarSettings(): array
    {
        // ENTERPRISE PERFORMANCE FIX: Skip ALL operations if debugbar disabled via env
        // This prevents unnecessary Redis connections + DB queries in production (ENABLE_DEBUGBAR=false)
        // Under 200 concurrent users, this eliminates ~200 queries/second overhead
        if (!env('ENABLE_DEBUGBAR', false)) {
            return self::$settingsCache = [
                'debugbar_enabled' => false,
                'debugbar_admin_only' => true,
                'debugbar_show_queries' => true,
                'debugbar_show_performance' => true,
                'debugbar_collect_views' => true,
                'debugbar_theme' => 'crt-amber',
            ];
        }

        // ENTERPRISE L1: Check PHP request-level cache first
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        // ENTERPRISE L2: Check Redis cache (10 second TTL)
        $cacheKey = 'debugbar:settings';
        $redis = null;

        try {
            // Try Redis first (ultra-fast <1ms)
            $redis = new \Redis();
            if ($redis->pconnect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379), 0.1)) {
                $redis->select(0); // DB 0 for cache
                $cached = $redis->get($cacheKey);

                if ($cached !== false) {
                    $settings = json_decode($cached, true);
                    if ($settings) {
                        self::$settingsCache = $settings;

                        return $settings;
                    }
                }
            }
        } catch (\Exception $e) {
            // Redis unavailable, fall through to DB
        }

        try {
            // ENTERPRISE L3: Database query (only if Redis miss)
            $rows = db()->query("
                SELECT setting_key, setting_value
                FROM admin_settings
                WHERE setting_key LIKE 'debugbar_%'
            ", [], ['cache' => false]);

            $settings = [];

            foreach ($rows as $row) {
                $key = $row['setting_key'];
                $value = $row['setting_value'];

                // ENTERPRISE: Handle different setting types
                if ($key === 'debugbar_theme') {
                    // String value for theme selection
                    $settings[$key] = $value;
                } else {
                    // Boolean conversion for other settings
                    $boolValue = ($value === '1' || $value === 1 || $value === true);
                    $settings[$key] = $boolValue;
                }
            }

            // Default values if not set
            $finalSettings = array_merge([
                'debugbar_enabled' => false,
                'debugbar_admin_only' => true,
                'debugbar_show_queries' => true,
                'debugbar_show_performance' => true,
                'debugbar_collect_views' => true,
                'debugbar_theme' => 'crt-amber',
            ], $settings);

            // ENTERPRISE: Cache in Redis for 10 seconds (balance between freshness and performance)
            if ($redis && $redis->isConnected()) {
                try {
                    $redis->setex($cacheKey, 10, json_encode($finalSettings));
                } catch (\Exception $e) {
                    // Silently fail if cache write fails
                }
            }

            // ENTERPRISE: Cache settings for this request to avoid duplicate queries
            self::$settingsCache = $finalSettings;

            return $finalSettings;

        } catch (\Exception $e) {

            // ENTERPRISE: Cache safe defaults even on DB failure
            $defaultSettings = [
                'debugbar_enabled' => false,
                'debugbar_admin_only' => true,
                'debugbar_show_queries' => true,
                'debugbar_show_performance' => true,
                'debugbar_collect_views' => true,
                'debugbar_theme' => 'crt-amber',
            ];

            self::$settingsCache = $defaultSettings;

            return $defaultSettings;
        } finally {
            // Clean up Redis connection
            if ($redis && $redis->isConnected()) {
                try {
                    $redis->close();
                } catch (\Exception $e) {
                    // Silently ignore close errors
                }
            }
        }
    }

    /**
     * Check if current user is admin or if admin-only is disabled
     * ENTERPRISE TIPS: When admin_only=true, debugbar ONLY appears on admin routes
     */
    private static function isAdminUser(): bool
    {
        // If admin_only is disabled, allow everyone on all routes
        if (empty(self::$settings['debugbar_admin_only'])) {
            return true;
        }

        // ENTERPRISE TIPS: If admin_only is enabled, debugbar should ONLY appear on admin routes
        // Check if we're on an admin route FIRST
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isAdminRoute = (
            preg_match('/^\/admin_[a-f0-9]{16}/', $uri) ||
            preg_match('/^\/x7f9k2m8q1/', $uri) ||
            $uri === '/admin.php'
        );

        // If we're NOT on an admin route, return false immediately (even if user is admin)
        if (!$isAdminRoute) {
            return false;
        }

        // We're on an admin route - now check if user is actually authenticated as admin
        // Check admin session cookie
        if (isset($_COOKIE['__Host-admin_session'])) {
            try {
                $adminSecurity = new \Need2Talk\Services\AdminSecurityService();
                $session = $adminSecurity->validateAdminSession($_COOKIE['__Host-admin_session']);

                if ($session) {
                    return true;
                }
            } catch (\Exception $e) {
                if (function_exists('should_log') && should_log('default', 'debug')) {
                    Logger::debug('[DEBUGBAR] Admin session validation failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Check admin session in different possible ways
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['admin_user_id']) || isset($_SESSION['admin_logged_in'])) {
                return true;
            }
        }

        // Check EnterpriseGlobalsManager session
        if (EnterpriseGlobalsManager::getSession('admin_user_id')
            || EnterpriseGlobalsManager::getSession('admin_logged_in')) {
            return true;
        }

        return false;
    }
}

/**
 * Custom Views Data Collector for Lightning Framework
 * CRITICAL FIX: Use instance property instead of static to ensure data persists in collector instance
 */
class ViewsCollector extends \DebugBar\DataCollector\DataCollector implements \DebugBar\DataCollector\Renderable
{
    protected array $views = [];

    public function addView(string $view, array $data = []): void
    {
        // ENTERPRISE: Clean data for better debugbar display
        $cleanData = [];

        foreach ($data as $key => $value) {
            if (is_object($value)) {
                $cleanData[$key] = get_class($value) . ' (object)';
            } elseif (is_array($value)) {
                $cleanData[$key] = 'Array[' . count($value) . ']';
            } elseif (is_null($value)) {
                $cleanData[$key] = 'NULL';
            } else {
                $cleanData[$key] = (string) $value;
            }
        }

        $this->views[] = [
            'name' => $view,
            'data_keys' => array_keys($data),
            'data_count' => count($data),
            'data_summary' => $cleanData,
            'memory' => memory_get_usage(true),
            'time' => time(),
        ];
    }

    /**
     * ENTERPRISE: Reset views data for fresh collection
     */
    public function resetViews(): void
    {
        $this->views = [];
    }

    /**
     * DEBUG: Get views array for debugging
     */
    public function getViews(): array
    {
        return $this->views;
    }

    public function collect()
    {
        // CRITICAL FIX: Format like MessagesWidget expects - array of messages with 'message' and 'label' keys
        $messages = [];

        foreach ($this->views as $index => $view) {
            $viewName = $view['name'];

            // Add main view entry
            $messages[] = [
                'message' => sprintf(
                    '%s - Data: %d params | Memory: %s KB',
                    $viewName,
                    $view['data_count'],
                    number_format($view['memory'] / 1024, 2)
                ),
                'label' => 'info',
                'is_string' => true,
            ];
        }

        return [
            'count' => count($this->views),
            'messages' => $messages,
        ];
    }

    public function getName()
    {
        return 'views';
    }

    public function getWidgets()
    {
        return [
            'views' => [
                'icon' => 'eye',
                'widget' => 'PhpDebugBar.Widgets.MessagesWidget',
                'map' => 'views.messages',
                'default' => '[]',
            ],
            'views:badge' => [
                'map' => 'views.count',
                'default' => 'null',
            ],
        ];
    }
}

/**
 * Custom Lightning Framework Data Collector for DebugBar
 */
class LightningFrameworkCollector extends \DebugBar\DataCollector\DataCollector implements \DebugBar\DataCollector\Renderable
{
    public function collect()
    {
        // ENTERPRISE TIPS: Use SCRIPT_START_TIME from index.php for accurate measurement
        $startTime = defined('SCRIPT_START_TIME') ? SCRIPT_START_TIME : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $currentTime = microtime(true);

        return [
            'framework' => 'Lightning Framework',
            'version' => '1.2.0',
            'performance_claim' => '12x faster than Laravel',
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'peak_memory' => $this->formatBytes(memory_get_peak_usage(true)),
            'request_time' => round(($currentTime - $startTime) * 1000, 2) . 'ms',
            'php_version' => PHP_VERSION,
            'opcache_enabled' => function_exists('opcache_get_status') ? 'Yes' : 'No',
            'environment' => $_ENV['APP_ENV'] ?? 'production',
        ];
    }

    public function getName()
    {
        return 'lightning';
    }

    public function getWidgets()
    {
        return [
            'lightning' => [
                'icon' => 'bolt',
                'widget' => 'PhpDebugBar.Widgets.VariableListWidget',
                'map' => 'lightning',
                'default' => '{}',
            ],
        ];
    }

    public function formatBytes($size, $precision = 2)
    {
        if ($size === 0) {
            return '0 B';
        }
        $base = log($size, 1024);
        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];

        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }
}

/**
 * ENTERPRISE: DatabasePool Query Collector for DebugBar
 * Ultra-lightweight collector that receives queries from DatabasePool callback
 */
class DatabasePoolCollector extends \DebugBar\DataCollector\DataCollector implements \DebugBar\DataCollector\Renderable
{
    private $queries = [];

    private $totalDuration = 0;

    private $failedQueries = 0;

    public function addQuery(string $sql, array $params, float $duration, bool $failed): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'duration_str' => number_format($duration * 1000, 2) . 'ms',
            'is_success' => !$failed,
            'error_code' => $failed ? 'ERROR' : null,
            'error_message' => $failed ? 'Query failed' : null,
            'stmt_id' => count($this->queries),
            'connection' => 'enterprise_pool',
        ];

        $this->totalDuration += $duration;

        if ($failed) {
            $this->failedQueries++;
        }
    }

    public function collect()
    {
        return [
            'nb_statements' => count($this->queries),
            'nb_failed_statements' => $this->failedQueries,
            'accumulated_duration' => $this->totalDuration,
            'accumulated_duration_str' => number_format($this->totalDuration * 1000, 2) . 'ms',
            'memory_usage' => 0,
            'memory_usage_str' => '0B',
            'peak_memory_usage' => 0,
            'peak_memory_usage_str' => '0B',
            'statements' => $this->queries,
        ];
    }

    public function getName()
    {
        return 'pdo';
    }

    public function getWidgets()
    {
        return [
            'database' => [
                'icon' => 'database',
                'widget' => 'PhpDebugBar.Widgets.SQLQueriesWidget',
                'map' => 'pdo',
                'default' => '[]',
            ],
            'database:badge' => [
                'map' => 'pdo.nb_statements',
                'default' => 0,
            ],
        ];
    }
}

/**
 * TrackedQueriesCollector - Custom DebugBar collector for TrackedPDO queries
 */
class TrackedQueriesCollector extends \DebugBar\DataCollector\DataCollector implements \DebugBar\DataCollector\Renderable
{
    private static array $queries = [];

    public static function addQuery(string $sql, array $params, float $duration, bool $failed): void
    {
        self::$queries[] = [
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'duration_str' => number_format($duration * 1000, 2) . 'ms',
            'is_success' => !$failed,
            'stmt_id' => count(self::$queries) + 1,
        ];
    }

    public function collect()
    {
        $total_duration = array_sum(array_column(self::$queries, 'duration'));

        return [
            'nb_statements' => count(self::$queries),
            'nb_failed_statements' => count(array_filter(self::$queries, fn ($q) => !$q['is_success'])),
            'accumulated_duration' => $total_duration,
            'accumulated_duration_str' => number_format($total_duration * 1000, 2) . 'ms',
            'statements' => self::$queries,
        ];
    }

    public function getName()
    {
        return 'tracked_queries';
    }

    public function getWidgets()
    {
        return [
            'database' => [
                'icon' => 'database',
                'widget' => 'PhpDebugBar.Widgets.SQLQueriesWidget',
                'map' => 'tracked_queries',
                'default' => '[]',
            ],
            'database:badge' => [
                'map' => 'tracked_queries.nb_statements',
                'default' => 0,
            ],
        ];
    }
}
