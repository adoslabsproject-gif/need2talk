<?php

/**
 * need2talk Bootstrap
 *
 * Application initialization and configuration
 */

declare(strict_types=1);

// ============================================================================
// ENTERPRISE PERFORMANCE PROFILING (PostgreSQL optimization - 2025-11-23)
// DISABLED IN PRODUCTION - Enable only for debugging performance issues
// ============================================================================
// Profiling completed - optimizations applied:
// - force_generic_plan: 40x faster query planning
// - Cache max_connections: Eliminates PostgreSQL query
// - Lazy warmup: 90ms → 0.26ms (350x faster)
// - Bootstrap: 101ms → 7ms (14x faster!)
// ============================================================================
define('BOOTSTRAP_PROFILE_ENABLED', false);  // Set to true to re-enable profiling

if (BOOTSTRAP_PROFILE_ENABLED) {
    // PROFILING ENABLED - Full tracking
    global $_BOOTSTRAP_TIMES;
    $_BOOTSTRAP_TIMES = ['start' => microtime(true)];

    function bootstrap_checkpoint($label) {
        global $_BOOTSTRAP_TIMES;
        $_BOOTSTRAP_TIMES[$label] = microtime(true);
    }

    function bootstrap_report() {
        global $_BOOTSTRAP_TIMES;
        if (!isset($_BOOTSTRAP_TIMES['start'])) return;

        $start = $_BOOTSTRAP_TIMES['start'];
        $report = "\n=== BOOTSTRAP PROFILING ===\n";
        $prev = $start;

        foreach ($_BOOTSTRAP_TIMES as $label => $time) {
            if ($label === 'start') continue;
            $delta = round(($time - $prev) * 1000, 2);
            $total = round(($time - $start) * 1000, 2);
            $report .= sprintf("%-30s: +%6.2fms (total: %6.2fms)\n", $label, $delta, $total);
            $prev = $time;
        }

        $report .= "=== TOTAL: " . round((microtime(true) - $start) * 1000, 2) . "ms ===\n";
        error_log($report);
    }

    register_shutdown_function('bootstrap_report');
} else {
    // PROFILING DISABLED - Stub functions (no overhead)
    function bootstrap_checkpoint($label) { /* disabled */ }
    function bootstrap_report() { /* disabled */ }
}


// Define APP_ROOT if not already defined (for CLI scripts and tests)
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Enterprise PHP Configuration Optimizations for 100k+ concurrent users
if (function_exists('ini_set')) {
    // Memory optimization for enterprise scale (WebM files ~180KB each + Redis connections pool)
    ini_set('memory_limit', '4096M'); // ENTERPRISE GALAXY: Increased for high concurrency (was 512M)

    // Upload optimizations for WebM audio files
    ini_set('upload_max_filesize', '1M'); // WebM files are ~180KB, 1M is generous
    ini_set('post_max_size', '2M'); // Allow for form data + file
    ini_set('max_file_uploads', '5'); // Reasonable limit to prevent abuse

    // Execution time optimizations
    // ENTERPRISE GALAXY V12.8 (2026-01-18): CRITICAL FIX - Workers need infinite timeout
    // CONTEXT: Email worker connection recycle was hitting 30s timeout during PostgreSQL reconnection
    // ROOT CAUSE: Workers run infinite loops with DB connection recycling every 5 minutes
    // SOLUTION: Only apply timeout limits to WEB requests, not CLI/worker processes
    if (PHP_SAPI !== 'cli') {
        // WEB only: 30s timeout for HTTP requests (audio processing)
        ini_set('max_execution_time', '30'); // 30s for audio processing
        ini_set('max_input_time', '30'); // Input processing timeout
    } else {
        // CLI/Workers: Infinite timeout (long-running processes, cron jobs, email workers)
        ini_set('max_execution_time', '0'); // No timeout for background workers
        ini_set('max_input_time', '0'); // No input timeout for CLI
    }

    // Session optimizations for high concurrency (only if headers not sent)
    // ENTERPRISE GALAXY V6.6: session.gc_maxlifetime is now set by SecureSessionManager
    // using the centralized EnterpriseGlobalsManager::getSessionLifetimeSeconds()
    // This ensures the value comes from .env (SESSION_LIFETIME) instead of hardcoded
    if (!headers_sent()) {
        // gc_maxlifetime moved to SecureSessionManager::initSecureSession()
        ini_set('session.gc_probability', '1'); // GC probability
        ini_set('session.gc_divisor', '1000'); // GC runs 1/1000 requests

        // Output buffering for better performance (WEB ONLY - not CLI)
        if (PHP_SAPI !== 'cli') {
            ini_set('output_buffering', '4096'); // 4KB buffer
            ini_set('zlib.output_compression', '1'); // Enable compression (breaks cron output if enabled for CLI)
        }
    }

    // Opcache optimizations (if available)
    if (function_exists('opcache_get_status')) {
        ini_set('opcache.memory_consumption', '256'); // 256MB for opcache
        ini_set('opcache.max_accelerated_files', '20000'); // More files cached
        ini_set('opcache.validate_timestamps', '0'); // Disable in production
        ini_set('opcache.save_comments', '0'); // Remove comments for space
        ini_set('opcache.enable_file_override', '1'); // File override for performance
    }

    // Error reporting optimizations for enterprise
    // ENTERPRISE: Always redirect error_log to need2talk directory (not MAMP Pro)
    ini_set('log_errors', '1');
    ini_set('error_log', APP_ROOT . '/storage/logs/php_errors.log');

    if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
        ini_set('display_errors', '0');
    } else {
        // Development mode - show errors but still log to our file
        ini_set('display_errors', '1');
    }
}

// Composer autoloader (if not already loaded)
if (!class_exists('Dotenv\Dotenv')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}
bootstrap_checkpoint('composer_autoload');

// ENTERPRISE IDE STUBS: NOT loaded at runtime for performance
// Stub files exist in /app/Core/Stubs/ for IDE autocomplete and error checking ONLY
// IDEs (PHPStorm, VSCode Intelephense) automatically discover them via filesystem analysis
// Loading them at runtime would add unnecessary parsing overhead for 100k+ concurrent users

// ENTERPRISE: .env loading with OPcache optimization
// Reduces .env loading from 192ms to <1ms for 100k+ concurrent users
$envFile = APP_ROOT . '/.env';
$envCacheFile = APP_ROOT . '/storage/cache/env.php';

if (file_exists($envFile)) {
    try {
        // Check if cache exists and is fresh
        $useCache = false;
        if (file_exists($envCacheFile)) {
            $envMtime = filemtime($envFile);
            $cacheMtime = filemtime($envCacheFile);
            $useCache = ($cacheMtime >= $envMtime);
        }

        if ($useCache) {
            // FAST PATH: Load from OPcache-optimized PHP file (<1ms)
            $envVars = include $envCacheFile;
            foreach ($envVars as $key => $value) {
                $_ENV[$key] = $value;

                // GALAXY LEVEL FIX: Handle array/object values for putenv (convert to JSON)
                if (is_array($value) || is_object($value)) {
                    putenv("$key=" . json_encode($value));
                } elseif (is_bool($value)) {
                    putenv("$key=" . ($value ? 'true' : 'false'));
                } elseif (is_null($value)) {
                    putenv("$key=");
                } else {
                    putenv("$key=$value");
                }
            }
        } else {
            // SLOW PATH: Parse .env and regenerate cache (only when .env changes)
            // ENTERPRISE FIX: Store $_ENV BEFORE Dotenv load to compare
            $envBefore = $_ENV;

            if (class_exists('\Dotenv\Dotenv')) {
                $dotenv = \Dotenv\Dotenv::createImmutable(APP_ROOT);
                $dotenv->load();
            }

            // ENTERPRISE FIX: Extract ONLY variables loaded from .env file (not container vars)
            $dotenvVars = array_diff_key($_ENV, $envBefore);

            // Merge: container vars + .env vars (with .env having priority)
            $allVars = array_merge($envBefore, $dotenvVars);

            // Generate cache file for next request (WITH .env variables)
            if (!is_dir(dirname($envCacheFile))) {
                @mkdir(dirname($envCacheFile), 0755, true);
            }
            $cacheContent = "<?php\n// Generated: " . date('Y-m-d H:i:s') . "\n";
            $cacheContent .= "// DO NOT EDIT - Auto-generated from .env\n";
            $cacheContent .= "return " . var_export($allVars, true) . ";\n";
            @file_put_contents($envCacheFile, $cacheContent, LOCK_EX);
        }
    } catch (Exception $e) {
        error_log('Bootstrap: .env loading failed: ' . $e->getMessage());
    }
}
bootstrap_checkpoint('env_loading');

// PSR-4 autoloader for Need2Talk namespace
spl_autoload_register(function (string $class) {
    $prefix = 'Need2Talk\\';
    $base_dir = APP_ROOT . '/app/';

    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
bootstrap_checkpoint('psr4_autoloader');

// ENTERPRISE: UserModelStubs loading disabled - only for IDE support
// Runtime should use real User model from PSR-4 autoloader

// Enterprise IDE Support - Stubs already loaded at the beginning of bootstrap

// Initialize logging system early
use Need2Talk\Bootstrap\EnterpriseBootstrap;
use Need2Talk\Services\ErrorHandler;
use Need2Talk\Services\Logger;

// Environment helper function
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $default;
    }
}

// Storage path helper function - MUST BE DEFINED EARLY
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return APP_ROOT . '/storage/' . ltrim($path, '/');
    }
}

// ENTERPRISE: Timezone-aware logging function (defined early for Logger initialization)
if (!function_exists('enterprise_log')) {
    function enterprise_log(string $message): void
    {
        // ENTERPRISE: Route to Logger default channel if available
        if (class_exists('\Need2Talk\Services\Logger')) {
            Logger::info($message);

            return;
        }

        // Fallback during bootstrap: use error_log with timezone
        $timezone = env('APP_TIMEZONE', 'Europe/Rome');
        $timestamp = (new DateTime('now', new DateTimeZone($timezone)))->format('d-M-Y H:i:s T');
        error_log("[$timestamp] $message");
    }
}

// ========================================================================
// ENTERPRISE LOGGING INITIALIZATION - MUST BE FIRST
// ========================================================================
// Inizializza sistema di logging
Logger::init();
bootstrap_checkpoint('logger_init');

// ENTERPRISE GALAXY ULTIMATE: Load dynamic logging helpers
// CRITICAL: Must be loaded AFTER Logger::init() but BEFORE EnterpriseBootstrap
// EnterpriseBootstrap uses should_log() function defined here
if (file_exists(APP_ROOT . '/app/Helpers/LoggingHelpers.php')) {
    require_once APP_ROOT . '/app/Helpers/LoggingHelpers.php';
}
bootstrap_checkpoint('logging_helpers');

// ========================================================================
// ENTERPRISE BOOTSTRAP - Initialize EARLY for 100k+ users scalability
// ========================================================================
// CRITICAL: Requires should_log() to be defined (from LoggingHelpers.php above)
// - Enterprise caching layer
// - Connection pooling
// - Lazy loading of components
// - Performance optimizations
try {
    EnterpriseBootstrap::initialize();
} catch (\Exception $e) {
    // Log error but continue - fallback to standard bootstrap
    error_log('Enterprise Bootstrap failed, using standard mode: ' . $e->getMessage());
}
bootstrap_checkpoint('enterprise_bootstrap');

// Error handler is initialized early in index.php for development
// For production, use custom ErrorHandler
if (env('APP_ENV') === 'production') {
    ErrorHandler::init();
}

// Configuration helper function
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        static $config = [];

        if (empty($config)) {
            $config = require APP_ROOT . '/config/app.php';
        }

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }
}

// Enterprise Database helper - Returns Database object with enterprise features when available
if (!function_exists('db')) {
    function db(): Need2Talk\Core\Database
    {
        static $database = null;

        if ($database === null) {
            // Try enterprise database if EnterpriseBootstrap is available and initialized
            if (class_exists('Need2Talk\Bootstrap\EnterpriseBootstrap')) {
                try {
                    $enterpriseDb = EnterpriseBootstrap::getComponent('database');

                    if ($enterpriseDb) {
                        $database = $enterpriseDb;
                    }
                } catch (\Exception $e) {
                    // Enterprise not initialized, fallback to standard database
                    $database = null;
                }
            }

            // Fallback to standard database
            if (!$database) {
                $database = new Need2Talk\Core\Database();
            }
        }

        return $database;
    }
}

// ENTERPRISE: Auto-Release PDO Wrapper
if (!class_exists('AutoReleasePDO')) {
    class AutoReleasePDO
    {
        private $pdo;

        private $released = false;

        public function __construct($pdo)
        {
            $this->pdo = $pdo;
        }

        public function __destruct()
        {
            $this->release();
        }

        public function release(): void
        {
            if (!$this->released && $this->pdo) {
                try {
                    // Get the original PDO if this is a TrackedPDO wrapper
                    $originalPdo = $this->pdo;

                    if ($this->pdo instanceof \Need2Talk\Services\TrackedPDO) {
                        if (method_exists($this->pdo, 'getOriginalPdo')) {
                            $originalPdo = $this->pdo->getOriginalPdo();
                        } else {
                            // Fallback: use reflection to access private $pdo property
                            try {
                                $reflection = new \ReflectionClass($this->pdo);
                                $property = $reflection->getProperty('pdo');
                                $property->setAccessible(true);
                                $originalPdo = $property->getValue($this->pdo);
                            } catch (\Throwable $e) {
                                // Use TrackedPDO as-is if reflection fails
                                $originalPdo = $this->pdo;
                            }
                        }
                    }

                    Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($originalPdo);
                    $this->released = true;
                } catch (\Throwable $e) {
                    // Silent failure - connection cleanup is non-critical
                }
            }
        }

        public function __call($method, $args)
        {
            return call_user_func_array([$this->pdo, $method], $args);
        }

        public function getAttribute($attribute)
        {
            return $this->pdo->getAttribute($attribute);
        }

        public function prepare($statement, $driver_options = [])
        {
            return $this->pdo->prepare($statement, $driver_options);
        }

        public function exec($statement)
        {
            return $this->pdo->exec($statement);
        }

        public function query($statement, $mode = null, ...$args)
        {
            if ($mode === null) {
                return $this->pdo->query($statement);
            }

            return $this->pdo->query($statement, $mode, ...$args);
        }

        public function beginTransaction()
        {
            return $this->pdo->beginTransaction();
        }

        public function commit()
        {
            return $this->pdo->commit();
        }

        public function rollBack()
        {
            return $this->pdo->rollBack();
        }

        public function lastInsertId($name = null)
        {
            return $this->pdo->lastInsertId($name);
        }

        public function inTransaction()
        {
            return $this->pdo->inTransaction();
        }
    }
}

// Legacy PDO connection helper (for backward compatibility)
if (!function_exists('db_pdo')) {
    function db_pdo()
    {
        $pdo = Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();

        // ENTERPRISE: Ultra-safe TrackedPDO wrapper first (needs real PDO)
        // ENTERPRISE TIPS: Check if already wrapped to avoid double-wrapping
        if (class_exists('\Need2Talk\Services\TrackedPDO')) {
            try {
                // Skip wrapping if already a TrackedPDO
                if ($pdo instanceof \Need2Talk\Services\TrackedPDO) {
                    $trackedPdo = $pdo;
                } else {
                    $trackedPdo = new \Need2Talk\Services\TrackedPDO($pdo);
                }

                // ENTERPRISE: Then wrap TrackedPDO with auto-release proxy
                $autoReleasePdo = new AutoReleasePDO($trackedPdo);

                // ENTERPRISE: Register TrackedPDO with DebugBar collector for query tracking
                if (class_exists('\Need2Talk\Services\DebugbarService')) {
                    try {
                        \Need2Talk\Services\DebugbarService::addPDO($trackedPdo);
                    } catch (\Throwable $e) {
                        // Ultra-safe: never break main functionality
                        error_log('[DB_PDO] Failed to register TrackedPDO: ' . $e->getMessage());
                    }
                }

                return $autoReleasePdo;
            } catch (\Throwable $e) {
                // ENTERPRISE: Ultra-safe fallback - never break main functionality
                enterprise_log('[DEBUGBAR] TrackedPDO wrapper failed, using AutoReleasePDO: ' . $e->getMessage());
            }
        }

        // Fallback: AutoReleasePDO directly on PDO
        $autoReleasePdo = new AutoReleasePDO($pdo);

        // Register with DebugBar if available
        if (class_exists('\Need2Talk\Services\DebugbarService')) {
            try {
                \Need2Talk\Services\DebugbarService::addPDO($autoReleasePdo);
            } catch (\Throwable $e) {
                // Ultra-safe: never break main functionality
            }
        }

        return $autoReleasePdo;
    }
}

// Release database connection back to pool
if (!function_exists('db_release')) {
    /**
     * Release database connection back to pool
     * Accepts both PDO and AutoReleasePDO wrapper objects
     *
     * @param PDO|object $pdo Connection to release
     */
    function db_release(object $pdo): void
    {
        Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($pdo);
    }
}

// ENTERPRISE: Force cleanup on request end (critical for admin panel)
if (!function_exists('enterprise_cleanup_connections')) {
    function enterprise_cleanup_connections(): void
    {
        try {
            // Force garbage collection to trigger AutoReleasePDO destructors
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Force pool cleanup with health check
            if (class_exists('Need2Talk\Services\EnterpriseSecureDatabasePool')) {
                $pool = Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance();

                // Run health check first to identify issues
                if (method_exists($pool, 'healthCheckConnections')) {
                    $unhealthyCount = $pool->healthCheckConnections();

                    if ($unhealthyCount > 0) {
                        error_log("[ENTERPRISE_CLEANUP] Removed $unhealthyCount unhealthy connections");
                    }
                }

                // Then force cleanup
                if (method_exists($pool, 'forceCleanup')) {
                    $pool->forceCleanup();
                }
            }
        } catch (\Throwable $e) {
            error_log('[ENTERPRISE_CLEANUP] Error during cleanup: ' . $e->getMessage());
        }
    }
}

// Register cleanup function for admin requests
if (!defined('ENTERPRISE_CLEANUP_REGISTERED')) {
    define('ENTERPRISE_CLEANUP_REGISTERED', true);

    register_shutdown_function(function () {
        // Only run cleanup for admin requests to prevent overhead on normal requests
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        if (strpos($requestUri, '/admin_') !== false || strpos($requestUri, '/admin.php') !== false) {
            enterprise_cleanup_connections();
        }
    });
}

// Cache helper function
if (!function_exists('cache')) {
    function cache(): ?Need2Talk\Core\CacheManager
    {
        return EnterpriseBootstrap::getComponent('cache');
    }
}

// ENTERPRISE: Load Icon Helper (Heroicons SVG system)
require_once APP_ROOT . '/app/Helpers/Icon.php';
bootstrap_checkpoint('icon_helper');

// ENTERPRISE GALAXY: Load CSP Nonce Helpers (SecurityHeaders.com compliance)
require_once APP_ROOT . '/app/Helpers/csp_helpers.php';
bootstrap_checkpoint('csp_helpers');

// ENTERPRISE GALAXY (2025-01-23): Load Auth Helpers (Redis L1 cache-backed user system)
// CRITICAL: Replaces all $_SESSION['user'] occurrences with current_user() helper
require_once APP_ROOT . '/app/Helpers/auth_helpers.php';
bootstrap_checkpoint('auth_helpers');

// URL helper function
if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        // ENTERPRISE FIX: Auto-detect from current request (localhost-friendly)
        // Use HTTP_HOST for web requests, APP_URL only for CLI/cron/email contexts
        if (!empty($_SERVER['HTTP_HOST'])) {
            // WEB REQUEST: Use current request host (supports localhost, need2talk.test, ngrok, etc)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? env('SERVER_IP', 'YOUR_SERVER_IP');
            $base = $protocol . '://' . $host;
        } else {
            // CLI/CRON/EMAIL: Fallback to APP_URL from .env
            $base = env('APP_URL', 'https://need2talk.test');
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

// Asset helper function with manifest support (Option 3: real file hashing)
if (!function_exists('asset')) {
    function asset(string $path): string
    {
        // ENTERPRISE: Detect file type and route to correct subdirectory
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        // Build base path
        $basePath = '';
        if ($extension === 'css') {
            $basePath = 'assets/css/' . ltrim($path, '/');
        } elseif ($extension === 'js') {
            $basePath = 'assets/js/' . ltrim($path, '/');
        } else {
            // Generic assets (images, fonts, etc.)
            $basePath = 'assets/' . ltrim($path, '/');
        }

        // ENTERPRISE V8.0: Add automatic versioning from ASSET_VERSION (cache busting)
        // Format: YYYYMMDD.N (e.g., 20251201.1)
        // Increment in .env when deploying JS/CSS changes
        $version = env('ASSET_VERSION', date('Ymd'));
        $separator = strpos($basePath, '?') !== false ? '&' : '?';

        return url($basePath . $separator . 'v=' . $version);
    }
}

/**
 * ENTERPRISE: Avatar URL Helper
 *
 * Converts database avatar_url (relative path or Google URL) to full public URL
 *
 * Database avatar_url formats:
 * - Google OAuth: "https://lh3.googleusercontent.com/..." (passthrough)
 * - Local upload: "avatars/123/avatar_123_1234567890.webp" (relative)
 * - Null/empty: Default avatar
 *
 * Performance: <0.1ms (string operations only, no DB/filesystem access)
 * Scalability: 100,000+ concurrent calls/second
 *
 * @param string|null $avatarUrl Avatar URL from database (nullable)
 * @return string Full avatar URL for <img src="">
 */
if (!function_exists('get_avatar_url')) {
    function get_avatar_url(?string $avatarUrl): string
    {
        // ENTERPRISE: Fast path - empty/null check first
        if (empty($avatarUrl)) {
            return asset('img/default-avatar.png');
        }

        // ENTERPRISE: Google OAuth avatar (external CDN)
        // Format: https://lh3.googleusercontent.com/...
        if (str_starts_with($avatarUrl, 'https://')) {
            return $avatarUrl; // Passthrough - no modification needed
        }

        // ENTERPRISE: Local uploaded avatar (relative path)
        // Format: avatars/123/avatar_123_1234567890.webp
        // Convert to: /storage/uploads/avatars/123/avatar_123_1234567890.webp

        // FIX: Check if prefix already present (defensive programming)
        if (str_starts_with($avatarUrl, '/storage/uploads/')) {
            return $avatarUrl; // Already prefixed
        }

        return '/storage/uploads/' . ltrim($avatarUrl, '/');
    }
}

// CRITICAL: Define debugbar_add_view() BEFORE view() so it's available when view() is called
if (!function_exists('debugbar_add_view')) {
    function debugbar_add_view(string $view, array $data = []): void
    {
        if (class_exists('\Need2Talk\Services\DebugbarService')) {
            \Need2Talk\Services\DebugbarService::addView($view, $data);
        }
    }
}

// View helper function
if (!function_exists('view')) {
    function view(string $template, array $data = []): string
    {
        $file = APP_ROOT . '/app/Views/' . str_replace('.', '/', $template) . '.php';

        if (!file_exists($file)) {
            throw new Exception("View not found: {$template}");
        }

        // Register view with debugbar if available
        if (function_exists('debugbar_add_view')) {
            debugbar_add_view($template, $data);
        }

        extract($data);
        ob_start();
        include $file;

        return ob_get_clean();
    }
}

// Security helper - CSRF token
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        // ENTERPRISE GALAXY: FORCE session start when generating CSRF token
        // If csrf_token() is called, it means there's a form that needs protection
        // Public routes with forms (login, register) MUST have session
        \Need2Talk\Services\SecureSessionManager::forceSessionStart();

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(\Need2Talk\Core\EnterpriseSecurityFunctions::randomBytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

// Enterprise Globals helper functions - Transparent wrapper for IDE compatibility
if (!function_exists('get_server')) {
    function get_server(string $key, $default = null)
    {
        return \Need2Talk\Core\EnterpriseGlobalsManager::getServer($key, $default);
    }
}

if (!function_exists('get_session')) {
    function get_session(?string $key = null, $default = null)
    {
        return \Need2Talk\Core\EnterpriseGlobalsManager::getSession($key, $default);
    }
}

if (!function_exists('get_env')) {
    function get_env(string $key, $default = null)
    {
        return \Need2Talk\Core\EnterpriseGlobalsManager::getEnv($key, $default);
    }
}

if (!function_exists('get_input')) {
    function get_input(string $key, $default = null)
    {
        return \Need2Talk\Core\EnterpriseGlobalsManager::getInput($key, $default);
    }
}

// Enterprise Security Functions - Transparent wrapper for IDE compatibility
if (!function_exists('secure_random_bytes')) {
    function secure_random_bytes(int $length): string
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::randomBytes($length);
    }
}

if (!function_exists('secure_hash')) {
    function secure_hash(string $algorithm, string $data, bool $binary = false): string
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::hash($algorithm, $data, $binary);
    }
}

if (!function_exists('secure_password_hash')) {
    function secure_password_hash(string $password, ?string $algorithm = null, array $options = []): string
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::passwordHash($password, $algorithm, $options);
    }
}

if (!function_exists('secure_password_verify')) {
    function secure_password_verify(string $password, string $hash): bool
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::passwordVerify($password, $hash);
    }
}

if (!function_exists('secure_file_get_contents')) {
    function secure_file_get_contents(string $filename, int $maxSize = 10485760): string
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::fileGetContents($filename, $maxSize);
    }
}

if (!function_exists('secure_json_encode')) {
    function secure_json_encode($value, int $flags = 0, int $depth = 512): string
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::jsonEncode($value, $flags, $depth);
    }
}

if (!function_exists('secure_json_decode')) {
    function secure_json_decode(string $json, bool $associative = false, int $depth = 512, int $flags = 0)
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::jsonDecode($json, $associative, $depth, $flags);
    }
}

if (!function_exists('secure_hash_equals')) {
    function secure_hash_equals(string $expected, string $actual): bool
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::hashEquals($expected, $actual);
    }
}

if (!function_exists('secure_session_start')) {
    function secure_session_start(array $options = []): bool
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::sessionStart($options);
    }
}

// ENTERPRISE: Transparent SuperGlobal Compatibility Functions
// Allows existing code to work without namespace imports
if (!function_exists('enterprise_server')) {
    function enterprise_server(string $key, $default = null)
    {
        return \Need2Talk\Core\EnterpriseGlobalsManager::getServer($key, $default);
    }
}

if (!function_exists('enterprise_session')) {
    function enterprise_session(?string $key = null, $default = null)
    {
        return \Need2Talk\Core\EnterpriseGlobalsManager::getSession($key, $default);
    }
}

if (!function_exists('enterprise_random_bytes')) {
    function enterprise_random_bytes(int $length): string
    {
        return \Need2Talk\Core\EnterpriseSecurityFunctions::randomBytes($length);
    }
}

// ENTERPRISE: Create global $_SERVER proxy for backward compatibility
// This makes existing code work transparently with enterprise security
if (!defined('ENTERPRISE_GLOBALS_INITIALIZED')) {
    define('ENTERPRISE_GLOBALS_INITIALIZED', true);

    // Note: This is for IDE compatibility only - actual runtime uses enterprise functions
    // In development, we can optionally redirect $_SERVER access to enterprise functions
    if (defined('ENTERPRISE_REDIRECT_GLOBALS') && ENTERPRISE_REDIRECT_GLOBALS) {
        // Advanced: Runtime redirection (experimental - can cause issues)
        // Only enable in specific testing scenarios
    }
}

// ENTERPRISE: Moved to line 193 for early initialization
// EnterpriseBootstrap::initialize() is now called EARLY in bootstrap
// This comment left for reference - DO NOT call here (double initialization)

// Inizializza sessione sicura (gestisce automaticamente duplicati)
// ENTERPRISE FIX: Skip session initialization in CLI mode (workers, cron scripts)
// Sessions are HTTP-only, CLI scripts don't need them and they cause "headers already sent" errors
if (php_sapi_name() !== 'cli') {
    \Need2Talk\Services\SecureSessionManager::initSecureSession();
    bootstrap_checkpoint('session_init');
}

// ENTERPRISE: Initialize Debugbar based on admin settings (ONLY for full bootstrap mode)
// Public mode skips Debugbar for maximum performance (code splitting optimization)
if (!defined('BOOTSTRAP_MODE') || BOOTSTRAP_MODE !== 'public') {
    try {
        \Need2Talk\Services\DebugbarService::initialize();
    } catch (\Exception $e) {
        enterprise_log('[DEBUGBAR] Initialization failed: ' . $e->getMessage());
    }
}
bootstrap_checkpoint('debugbar_init');

// ENTERPRISE: Debugbar helper functions for views
if (!function_exists('debugbar_render_head')) {
    function debugbar_render_head(): string
    {
        return \Need2Talk\Services\DebugbarService::renderHead();
    }
}

if (!function_exists('debugbar_render')) {
    function debugbar_render(): string
    {
        return \Need2Talk\Services\DebugbarService::render();
    }
}

if (!function_exists('debugbar_log')) {
    function debugbar_log(string $message, array $context = []): void
    {
        \Need2Talk\Services\DebugbarService::getInstance()->log($message, $context);
    }
}

if (!function_exists('debugbar_measure')) {
    function debugbar_measure(string $label, callable $callback)
    {
        return \Need2Talk\Services\DebugbarService::getInstance()->measure($label, $callback);
    }
}

// ENTERPRISE: Force timezone per TUTTI i contesti (web, CLI, error_log)
if (function_exists('date_default_timezone_set')) {
    $targetTimezone = env('APP_TIMEZONE', 'Europe/Rome');
    date_default_timezone_set($targetTimezone);

    // CRITICAL: Force anche per error_log nativo
    ini_set('date.timezone', $targetTimezone);

    // Timezone set to $targetTimezone
}
bootstrap_checkpoint('timezone_set');

// ENTERPRISE: Timezone-aware logging function (moved up before Logger::init())

// Initialize error logging (already configured above in enterprise section)

// ENTERPRISE: Log bootstrap performance metrics for monitoring
// Enterprise bootstrap timers available via EnterpriseBootstrap::getDebugTimers() if needed

// ============================================================================
// ENTERPRISE DI: Register Adapters for PHP-FPM Context
// ============================================================================
// This section registers context-appropriate adapters in the ServiceContainer.
// PHP-FPM uses EnterpriseRedisManager, db() helper, and WebSocketPublisher.
// Swoole WebSocket context uses different adapters (see websocket-bootstrap.php).
// ============================================================================

use Need2Talk\Core\ServiceContainer;
use Need2Talk\Adapters\Redis\PhpFpmRedisAdapter;
use Need2Talk\Adapters\Database\PhpFpmDatabaseAdapter;
use Need2Talk\Adapters\Publisher\WebSocketPublisherAdapter;

// Register Redis adapter (uses EnterpriseRedisManager internally)
// Lazy instantiation via factory - only created when first accessed
ServiceContainer::registerFactory('redis', function () {
    return new PhpFpmRedisAdapter();
});

// Register Database adapter (wraps db() helper)
// Lazy instantiation - DB connection only established when needed
ServiceContainer::registerFactory('database', function () {
    return new PhpFpmDatabaseAdapter();
});

// Register Event Publisher adapter (wraps WebSocketPublisher static class)
// Enables DI for Chat services while maintaining existing PubSub logic
ServiceContainer::registerFactory('publisher', function () {
    return new WebSocketPublisherAdapter();
});

bootstrap_checkpoint('service_container_init');
