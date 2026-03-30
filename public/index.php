<?php

/**
 * need2talk - Entry Point
 *
 * Unified audio sharing platform
 */

declare(strict_types=1);

// ============================================================================
// CRITICAL SECURITY: Validate allowed domains FIRST (before ANY output)
// This MUST be the first thing that runs to ensure proper redirect
// ============================================================================
$allowedHosts = [
    'need2talk.test',
    'need2talk.local',
    'need2talk.ngrok.app',
    'localhost',
    '127.0.0.1',
    'www.need2talk.it',
    'need2talk.it',
    'YOUR_SERVER_IP',
];

$currentHost = $_SERVER['HTTP_HOST'] ?? '';
// Remove port from host for validation
$currentHostNoPort = preg_replace('/:\d+$/', '', $currentHost);

$isAllowedHost = in_array($currentHostNoPort, $allowedHosts);

if (!$isAllowedHost && !empty($currentHost)) {
    // Unauthorized domain - redirect to correct domain IMMEDIATELY
    // BEFORE output buffering, cache, or any other processing
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $correctUrl = $protocol . '://need2talk.it' . ($_SERVER['REQUEST_URI'] ?? '/');

    // Clear any accidental output and send clean redirect
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $correctUrl);
    exit;
}

// ============================================================================
// ENTERPRISE: Enable output buffering for debugbar injection
// ============================================================================

// Start output buffering for debugbar injection (only for valid domains)
ob_start();

// Performance tracking
$startTime = microtime(true);

// Define script start time for accurate performance tracking
define('SCRIPT_START_TIME', $startTime);

// Define application root
define('APP_ROOT', dirname(__DIR__));

// ============================================================================
// ENTERPRISE: EARLY PAGE CACHE - Check AFTER domain validation
// ============================================================================
require_once APP_ROOT . '/app/Middleware/EarlyPageCache.php';

$cachedHtml = EarlyPageCache::get();
if ($cachedHtml !== null) {
    // CACHE HIT - Send HTML and exit immediately (bypass 312ms bootstrap!)
    echo $cachedHtml;
    exit;
}

// CACHE MISS - Continue with normal bootstrap...

// ENTERPRISE: Serve static files directly (bypass router for performance and avoid CSRF issues)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// ENTERPRISE GALAXY: parse_url() can return null on malformed URLs - ensure string type for preg_match()
if ($requestPath !== null && preg_match('/\.(js|css|png|jpg|jpeg|gif|webp|svg|ico|woff|woff2|ttf|eot|mp3|mp4|webm|pdf)$/i', $requestPath)) {
    $filePath = __DIR__ . $requestPath;

    if (file_exists($filePath) && is_readable($filePath)) {
        // Set appropriate MIME type
        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
        ];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

        // Add charset=UTF-8 for text-based files (CSS, JS, SVG)
        $textBasedTypes = ['application/javascript', 'text/css', 'image/svg+xml'];
        if (in_array($mimeType, $textBasedTypes)) {
            $mimeType .= '; charset=UTF-8';
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=31536000'); // 1 year cache

        readfile($filePath);
        exit;
    }
    // File not found
    http_response_code(404);
    echo 'File not found';
    exit;

}

// ============================================================================
// ENTERPRISE GALAXY: EARLY BANNED IP CHECK - Before bootstrap (NO SESSION!)
// Performance optimization: Block banned scanners without creating PHP session
// This saves Redis/session overhead for known malicious IPs
// ============================================================================

// SECURITY: Use X-Real-IP (set by Nginx from $remote_addr) - cannot be spoofed!
// X-Forwarded-For can be manipulated by attackers, X-Real-IP is set by our trusted proxy
$clientIP = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

try {
    // ULTRA-FAST Redis check (100ms timeout) - DB 3 for rate_limit
    $earlyRedis = new \Redis();

    // Read Redis password from .env (minimal parse before bootstrap)
    $envFile = dirname(__DIR__) . '/.env';
    $redisPassword = null;
    if (file_exists($envFile)) {
        $envContent = file_get_contents($envFile);
        if (preg_match('/^REDIS_PASSWORD=(.*)$/m', $envContent, $matches)) {
            $redisPassword = trim($matches[1]);
        }
    }

    if (@$earlyRedis->connect('redis', 6379, 0.1)) {
        if ($redisPassword) {
            @$earlyRedis->auth($redisPassword);
        }
        $earlyRedis->select(3); // DB 3 = rate_limit (where banned IPs are stored)

        // ENTERPRISE GALAXY FIX: Check whitelist BEFORE checking ban
        // Whitelisted IPs (owner/admin) must NEVER be blocked, even if accidentally banned
        $whitelistKey = "ip_whitelist:active:{$clientIP}";
        if ($earlyRedis->exists($whitelistKey)) {
            // IP is whitelisted - skip ban check entirely
            @$earlyRedis->close();
            // Continue to normal bootstrap (whitelist check will be repeated by middleware for audit)
        } else {
            // Not whitelisted - check if banned
            $banKey = "anti_scan:banned:{$clientIP}";
            if ($earlyRedis->exists($banKey)) {
                // BANNED IP - Log and block WITHOUT session/bootstrap
                $logMessage = sprintf(
                    "[%s] EARLY_BLOCK: Banned IP blocked before bootstrap | IP: %s | Path: %s | UA: %s\n",
                    date('Y-m-d H:i:s'),
                    $clientIP,
                    $requestUri,
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                );

                // Rate-limit logging (1 log per hour per IP to prevent spam)
                $logLimitKey = "antiscanning:early_log_count:{$clientIP}";
                $logCount = (int) $earlyRedis->get($logLimitKey);

                if ($logCount < 1) {
                    // Log to security file (first request only)
                    $securityLogFile = dirname(__DIR__) . '/storage/logs/security-' . date('Y-m-d') . '.log';
                    @file_put_contents($securityLogFile, $logMessage, FILE_APPEND | LOCK_EX);
                    $earlyRedis->incr($logLimitKey);
                    $earlyRedis->expire($logLimitKey, 3600); // 1 hour TTL
                }

                @$earlyRedis->close();

                // Return 403 and exit (NO session created!)
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                header('X-Block-Reason: IP_BANNED_EARLY');
                echo 'Access Denied';
                exit;
            }

            @$earlyRedis->close();
        }
    }
} catch (\Throwable $e) {
    // Redis unavailable - continue with normal bootstrap (middleware will handle)
    // Silent fail is acceptable here - security is still enforced by middleware
}

// ============================================================================
// ENTERPRISE: ROUTE DETECTION - Determine bootstrap mode for code splitting
// ============================================================================

$path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

// ENTERPRISE: Detect route type for optimized bootstrap
$isAdminRoute = false;
$isModerationRoute = false;
$isPublicRoute = false;

if (str_starts_with($path, '/x7f9k2m8q1') || preg_match('/^\/admin_[a-f0-9]{15,16}/', $path) || $path === '/admin.php') {
    $isAdminRoute = true;
} elseif (preg_match('/^\/mod_[a-f0-9]{16}/', $path)) {
    $isModerationRoute = true;
} else {
    // ENTERPRISE: Public routes get minimal bootstrap (ZERO debugbar overhead)
    $publicRoutes = [
        '/', '/home', '/login', '/register', '/about',
        '/legal/privacy', '/legal/terms', '/legal/contacts', '/legal/report',
        '/help/faq', '/help/guide', '/help/safety',
        '/auth/login', '/auth/register', '/auth/verify-email', '/auth/verify-email-sent',
        '/auth/resend-verification-form',
        '/forgot-password', '/reset-password'
    ];
    $isPublicRoute = in_array($path, $publicRoutes) || str_starts_with($path, '/emotion/') || str_starts_with($path, '/search');
}

// ============================================================================
// GALAXY LEVEL: Ultra-fast debugbar check (< 0.5ms) to override public mode
// ============================================================================
if ($isPublicRoute && !$isAdminRoute) {
    // ONLY check if on public route - admin routes always get full bootstrap
    $shouldLoadDebugbar = false;

    try {
        // ULTRA-FAST Redis check (0.1s timeout = 100ms max)
        // GALAXY LEVEL: Hardcoded Redis connection for pre-bootstrap speed
        $redis = new \Redis();
        if (@$redis->connect('redis', 6379, 0.1)) {
            $redis->select(0); // DB 0 for cache
            $cachedSettings = @$redis->get('debugbar:settings');

            if ($cachedSettings !== false) {
                $settings = @json_decode($cachedSettings, true);
                if ($settings && !empty($settings['debugbar_enabled'])) {
                    // ENTERPRISE GALAXY FIX: Check admin_only setting
                    $adminOnly = !empty($settings['debugbar_admin_only']);

                    if (!$adminOnly) {
                        // Admin-only DISABLED - everyone can see debugbar on all pages (public + admin)
                        $shouldLoadDebugbar = true;
                    }
                    // ENTERPRISE GALAXY FIX: If admin_only = true, DON'T load debugbar on public routes
                    // It will only appear on admin routes (checked in next section with $isAdminRoute)
                    // Public routes include homepage, login, register, etc.
                }
            }
            @$redis->close();
        }
    } catch (\Throwable $e) {
        // Redis unavailable - keep public mode for maximum performance (78ms)
        // On next request, bootstrap.php will populate Redis cache
    }

    // Override public route if debugbar needs to load
    if ($shouldLoadDebugbar) {
        $isPublicRoute = false; // Force full bootstrap
    }
}

// ENTERPRISE: Set bootstrap mode flag BEFORE loading bootstrap.php
if ($isPublicRoute) {
    define('BOOTSTRAP_MODE', 'public'); // Minimal bootstrap for public pages (78ms)
} else {
    define('BOOTSTRAP_MODE', 'full'); // Full bootstrap for authenticated/dynamic pages
}

// ============================================================================
// APPLICATION BOOTSTRAP - Enterprise-grade initialization (133ms)
// ============================================================================

// Application bootstrap (includes Composer, .env, autoloaders, EnterpriseBootstrap)
require_once APP_ROOT . '/app/bootstrap.php';

// 🚀 GALAXY: Intelligent PageCacheMiddleware - Smart Layer Selection
// Performance: 181ms → 5-15ms based on route complexity
// Strategy: Static routes (L1 Redis 5ms) | Dynamic routes (L2 Memcached 10ms) | Heavy routes (L3 Redis 15ms)
if (class_exists('\Need2Talk\Middleware\PageCacheMiddleware')) {
    $pageCacheMiddleware = new \Need2Talk\Middleware\PageCacheMiddleware();
    $cachedHtml = $pageCacheMiddleware->before();

    if ($cachedHtml !== null) {
        // 🚀 GALAXY CACHE HIT - Instant response (layer info in X-Cache-Layer header)
        exit;
    }
}

// ENTERPRISE: Initialize DebugBar ONLY for non-public routes (code splitting optimization)
// Public routes skip Debugbar to achieve ZERO overhead (252ms → 88ms for homepage)
if (!$isPublicRoute && class_exists('\Need2Talk\Services\DebugbarService')) {
    \Need2Talk\Services\DebugbarService::initialize(true);
}

// ENTERPRISE: Handle admin routes with security validation
if ($isAdminRoute) {
    // ENTERPRISE GALAXY: Load admin helpers ONLY for admin routes (zero impact on public performance)
    require_once APP_ROOT . '/app/Helpers/AdminHelpers.php';

    if (str_starts_with($path, '/x7f9k2m8q1')) {
        // FILAMENT ADMIN PANEL: Legacy route required for Filament
        error_log('[FILAMENT ACCESS] Legacy admin panel accessed: ' . $path . ' from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        require __DIR__ . '/admin-simple.php';
        exit;
    }

    if (preg_match('/^\/admin_[a-f0-9]{16}/', $path)) {
        // ENTERPRISE SECURITY: Validate admin URL before access
        require_once APP_ROOT . '/app/Services/AdminSecurityService.php';

        if (! \Need2Talk\Services\AdminSecurityService::validateAdminUrl($path)) {
            $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            // ENTERPRISE RATE LIMITING: Check failed attempts
            $cacheFile = APP_ROOT . '/storage/logs/admin_failed_' . md5($clientIP) . '.tmp';
            $attempts = 0;

            if (file_exists($cacheFile)) {
                $data = json_decode(file_get_contents($cacheFile), true);

                if ($data && time() - $data['first_attempt'] < 3600) { // 1 hour window
                    $attempts = $data['count'];
                }
            }

            $attempts++;

            // Block after 5 attempts
            if ($attempts >= 5) {
                error_log("[SECURITY CRITICAL] IP BLOCKED for admin URL bruteforce: $clientIP - Attempts: $attempts");
                http_response_code(429);
                echo '<h1>Too Many Requests</h1>';
                exit;
            }

            // Update attempts counter
            file_put_contents($cacheFile, json_encode([
                'count' => $attempts,
                'first_attempt' => file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true)['first_attempt'] ?? time() : time(),
                'last_attempt' => time(),
            ]), LOCK_EX);

            // ENTERPRISE AUDIT: Log security violation
            $securityLog = [
                'timestamp' => date('Y-m-d H:i:s'),
                'event' => 'invalid_admin_url_access',
                'url' => $path,
                'ip' => $clientIP,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'severity' => 'HIGH',
                'attempts' => $attempts,
            ];

            error_log('[SECURITY BLOCK] ' . json_encode($securityLog));

            // Write to dedicated security log
            $securityLogFile = APP_ROOT . '/storage/logs/security-' . date('Y-m-d') . '.log';
            file_put_contents($securityLogFile, json_encode($securityLog) . "\n", FILE_APPEND | LOCK_EX);

            http_response_code(404);
            require APP_ROOT . '/app/Views/errors/404.php';
            exit;
        }

        // Valid admin URL - proceed to admin.php
        require __DIR__ . '/admin.php';
        exit;
    }

    if ($path === '/admin.php') {
        // Direct admin.php access
        require __DIR__ . '/admin.php';
        exit;
    }
}

// ============================================================================
// ENTERPRISE: Handle moderation portal routes with security validation
// ============================================================================
if ($isModerationRoute) {
    require_once APP_ROOT . '/app/Services/Moderation/ModerationSecurityService.php';

    if (!\Need2Talk\Services\Moderation\ModerationSecurityService::validateModerationUrl($path)) {
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // ENTERPRISE RATE LIMITING: Check failed attempts for moderation URL
        $cacheFile = APP_ROOT . '/storage/logs/mod_failed_' . md5($clientIP) . '.tmp';
        $attempts = 0;

        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);

            if ($data && time() - $data['first_attempt'] < 3600) { // 1 hour window
                $attempts = $data['count'];
            }
        }

        $attempts++;

        // Block after 5 attempts
        if ($attempts >= 5) {
            error_log("[SECURITY CRITICAL] IP BLOCKED for moderation URL bruteforce: $clientIP - Attempts: $attempts");
            http_response_code(429);
            echo '<h1>Too Many Requests</h1>';
            exit;
        }

        // Update attempts counter
        file_put_contents($cacheFile, json_encode([
            'count' => $attempts,
            'first_attempt' => file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true)['first_attempt'] ?? time() : time(),
            'last_attempt' => time(),
        ]), LOCK_EX);

        // ENTERPRISE AUDIT: Log security violation
        $securityLog = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => 'invalid_moderation_url_access',
            'url' => $path,
            'ip' => $clientIP,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'severity' => 'HIGH',
            'attempts' => $attempts,
        ];

        error_log('[SECURITY BLOCK] ' . json_encode($securityLog));

        // Write to dedicated security log
        $securityLogFile = APP_ROOT . '/storage/logs/security-' . date('Y-m-d') . '.log';
        file_put_contents($securityLogFile, json_encode($securityLog) . "\n", FILE_APPEND | LOCK_EX);

        http_response_code(404);
        require APP_ROOT . '/app/Views/errors/404.php';
        exit;
    }

    // Valid moderation URL - proceed to moderation portal
    require __DIR__ . '/moderation.php';
    exit;
}

// Initialize router
try {
    $router = new Need2Talk\Core\Router();
} catch (\Throwable $e) {
    error_log('[ENTERPRISE INDEX] CRITICAL: Router initialization failed - ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('[ENTERPRISE INDEX] Stack trace: ' . $e->getTraceAsString());

    throw $e;
}

// Add global middleware for scalability and security
$router->addGlobalMiddleware('HostHeaderValidationMiddleware'); // CRITICAL FIRST: Validate Host header (DNS rebinding, cache poisoning protection)
$router->addGlobalMiddleware('SecurityHeadersMiddleware'); // CRITICAL: Security headers for enterprise
$router->addGlobalMiddleware('RequestLoggingMiddleware'); // ENTERPRISE GALAXY: Request logging for historical stats
$router->addGlobalMiddleware('AntiVulnerabilityScanningMiddleware'); // ENTERPRISE GALAXY: Anti-bot scanning protection
$router->addGlobalMiddleware('SessionCreationRateLimitMiddleware'); // ENTERPRISE GALAXY SECURITY: Prevent Redis memory exhaustion via session flooding
// ENTERPRISE TIPS: UserRateLimitMiddleware moved to route-specific to avoid blocking essential pages
$router->addGlobalMiddleware('CsrfMiddleware');

// Load routes
try {
    require_once APP_ROOT . '/config/routes.php';
} catch (\Throwable $e) {
    error_log('[ENTERPRISE INDEX] CRITICAL: Routes loading failed - ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('[ENTERPRISE INDEX] Stack trace: ' . $e->getTraceAsString());

    throw $e;
}

// Dispatch request

try {
    $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);

    // ENTERPRISE: Debugbar injection and caching are now handled in BaseController::view()
    // No additional processing needed here - output has already been sent

} catch (\Throwable $e) {
    error_log('[ENTERPRISE INDEX] CRITICAL: Request dispatch failed - ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('[ENTERPRISE INDEX] Stack trace: ' . $e->getTraceAsString());

    throw $e;
}

// Performance info for development
if ($_ENV['APP_ENV'] === 'development' && isset($_GET['debug'])) {
    $endTime = microtime(true);
    $executionTime = ($endTime - $startTime) * 1000;
    $memoryUsage = memory_get_peak_usage(true) / 1024 / 1024;

    echo "<div style='margin-top:20px;padding:10px;background:#f0f0f0;font-family:monospace;font-size:12px;'>";
    echo 'Execution time: ' . number_format($executionTime, 2) . 'ms<br>';
    echo 'Peak memory: ' . number_format($memoryUsage, 2) . 'MB<br>';
    echo 'Included files: ' . count(get_included_files()) . '<br>';
    echo '</div>';
}
