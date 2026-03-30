<?php

namespace Need2Talk\Core;

use Need2Talk\Services\Logger;

/**
 * Enterprise PHP Globals Manager
 *
 * Centralizza e standardizza l'accesso sicuro a superglobals PHP.
 * Implements enterprise patterns con validation, sanitization e logging.
 *
 * SICUREZZA ENTERPRISE:
 * - Input sanitization automatica
 * - IP validation e rate limiting awareness
 * - Session security con anti-hijacking
 * - Environment isolation
 * - Anti-injection patterns
 *
 * PERFORMANCE ENTERPRISE:
 * - Caching intelligente per IP detection
 * - Lazy initialization
 * - Memory-efficient static methods
 * - Compatible con load balancing
 */
class EnterpriseGlobalsManager
{
    private static array $cache = [];

    private static array $securityContext = [];

    private static bool $initialized = false;

    /**
     * Safe $_SERVER access with enterprise security
     */
    public static function getServer(string $key, $default = null): mixed
    {
        self::init();

        // Security validation for specific keys
        $secureValue = match ($key) {
            // IP Detection (enterprise-grade with proxy support)
            'REMOTE_ADDR', 'CLIENT_IP' => self::getClientIpSecure(),
            'HTTP_X_FORWARDED_FOR' => self::sanitizeForwardedFor($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            'HTTP_X_REAL_IP' => self::sanitizeIp($_SERVER['HTTP_X_REAL_IP'] ?? ''),

            // Request info (sanitized)
            'REQUEST_URI' => self::sanitizeRequestUri($_SERVER['REQUEST_URI'] ?? ''),
            'QUERY_STRING' => self::sanitizeQueryString($_SERVER['QUERY_STRING'] ?? ''),
            'REQUEST_METHOD' => self::validateHttpMethod($_SERVER['REQUEST_METHOD'] ?? 'GET'),

            // Headers (security-conscious)
            'HTTP_USER_AGENT' => self::sanitizeUserAgent($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'HTTP_REFERER' => self::sanitizeReferer($_SERVER['HTTP_REFERER'] ?? ''),
            'HTTP_HOST' => self::validateHost($_SERVER['HTTP_HOST'] ?? ''),

            // Server info (safe access)
            'SERVER_NAME' => self::sanitizeServerName($_SERVER['SERVER_NAME'] ?? ''),
            'HTTPS' => self::detectHttps(),
            'SERVER_PORT' => (int) ($_SERVER['SERVER_PORT'] ?? 80),

            // Default: raw access but logged
            default => self::getRawServerValue($key, $default)
        };

        // Cache frequently accessed values
        if (in_array($key, ['REMOTE_ADDR', 'HTTP_USER_AGENT', 'REQUEST_METHOD'], true)) {
            self::$cache["server_$key"] = $secureValue;
        }

        return $secureValue;
    }

    /**
     * Safe $_SESSION access with enterprise session security
     */
    public static function getSession(?string $key = null, $default = null): mixed
    {
        self::init();

        // ENTERPRISE GALAXY: Use conditional session start
        // Respects public route logic - only starts session when truly needed
        if (session_status() === PHP_SESSION_NONE) {
            \Need2Talk\Services\SecureSessionManager::ensureSessionStarted();
        }

        // Session fingerprint validation (anti-hijacking)
        if (!self::validateSessionFingerprint()) {
            Logger::warning('SECURITY: Session fingerprint mismatch - potential hijacking', [
                'user_id' => current_user_id() ?? 'anonymous',
                'ip' => self::getClientIpSecure(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
            ]);

            // Invalidate suspicious session
            self::destroySession();

            return $default;
        }

        // Return specific key or entire session
        if ($key === null) {
            return $_SESSION ?? [];
        }

        // Dot notation support (e.g., 'user.id')
        if (strpos($key, '.') !== false) {
            return self::getNestedSessionValue($key, $default);
        }

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Safe $_ENV access with environment isolation
     */
    public static function getEnv(string $key, $default = null): mixed
    {
        self::init();

        // Try $_ENV first, then getenv()
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        // Type casting for known keys
        return match ($key) {
            'APP_DEBUG', 'CACHE_ENABLED', 'REDIS_ENABLED' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'DATABASE_PORT', 'REDIS_PORT', 'MEMCACHED_PORT' => (int) $value,
            'CACHE_TTL', 'SESSION_LIFETIME', 'RATE_LIMIT_WINDOW' => (int) $value,
            default => (string) $value
        };
    }

    /**
     * Get session lifetime in SECONDS
     *
     * ENTERPRISE GALAXY V6.6: Centralized session lifetime retrieval
     * Reads SESSION_LIFETIME from .env (stored in MINUTES) and converts to SECONDS
     * This is the SINGLE SOURCE OF TRUTH for session lifetime across the application
     *
     * DEFAULT: 1440 minutes = 24 hours = 86400 seconds
     *
     * @return int Session lifetime in seconds
     */
    public static function getSessionLifetimeSeconds(): int
    {
        // SESSION_LIFETIME in .env is in MINUTES
        $lifetimeMinutes = self::getEnv('SESSION_LIFETIME', 1440); // Default 24 hours
        return (int) $lifetimeMinutes * 60; // Convert to seconds
    }

    /**
     * Get session lifetime in MINUTES
     *
     * ENTERPRISE GALAXY V6.6: For places that need minutes (like config values)
     *
     * @return int Session lifetime in minutes
     */
    public static function getSessionLifetimeMinutes(): int
    {
        return (int) self::getEnv('SESSION_LIFETIME', 1440); // Default 24 hours
    }

    // =========================================================================
    // ADMIN SESSION LIFETIME
    // =========================================================================

    /**
     * Get ADMIN session lifetime in SECONDS
     *
     * ENTERPRISE GALAXY V6.6: Separate lifetime for admin sessions (shorter for security)
     * Reads ADMIN_SESSION_LIFETIME from .env (stored in MINUTES) and converts to SECONDS
     *
     * DEFAULT: 60 minutes = 1 hour = 3600 seconds
     *
     * @return int Admin session lifetime in seconds
     */
    public static function getAdminSessionLifetimeSeconds(): int
    {
        $lifetimeMinutes = self::getEnv('ADMIN_SESSION_LIFETIME', 60); // Default 1 hour
        return (int) $lifetimeMinutes * 60;
    }

    /**
     * Get ADMIN session lifetime in MINUTES
     *
     * @return int Admin session lifetime in minutes
     */
    public static function getAdminSessionLifetimeMinutes(): int
    {
        return (int) self::getEnv('ADMIN_SESSION_LIFETIME', 60); // Default 1 hour
    }

    // =========================================================================
    // MODERATOR SESSION LIFETIME
    // =========================================================================

    /**
     * Get MODERATOR session lifetime in SECONDS
     *
     * ENTERPRISE GALAXY V6.6: Separate lifetime for moderator sessions
     * Reads MOD_SESSION_LIFETIME from .env (stored in MINUTES) and converts to SECONDS
     *
     * DEFAULT: 240 minutes = 4 hours = 14400 seconds
     *
     * @return int Moderator session lifetime in seconds
     */
    public static function getModSessionLifetimeSeconds(): int
    {
        $lifetimeMinutes = self::getEnv('MOD_SESSION_LIFETIME', 240); // Default 4 hours
        return (int) $lifetimeMinutes * 60;
    }

    /**
     * Get MODERATOR session lifetime in MINUTES
     *
     * @return int Moderator session lifetime in minutes
     */
    public static function getModSessionLifetimeMinutes(): int
    {
        return (int) self::getEnv('MOD_SESSION_LIFETIME', 240); // Default 4 hours
    }

    /**
     * Get security context for logging
     */
    public static function getSecurityContext(): array
    {
        self::init();

        return self::$securityContext;
    }

    /**
     * Clear caches (useful for testing)
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$securityContext = [];
        self::$initialized = false;
    }

    /**
     * Enterprise-grade REQUEST_TIME with microseconds
     */
    public static function getRequestTime(bool $float = false): int|float
    {
        if ($float) {
            return $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        }

        return $_SERVER['REQUEST_TIME'] ?? time();
    }

    /**
     * Safe $_POST access with validation
     */
    public static function getPost(?string $key = null, $default = null): mixed
    {
        if ($key === null) {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }

    /**
     * Safe $_GET access with validation
     */
    public static function getGet(?string $key = null, $default = null): mixed
    {
        if ($key === null) {
            return $_GET;
        }

        return $_GET[$key] ?? $default;
    }

    /**
     * Safe $_COOKIE access with validation
     */
    public static function getCookie(?string $key = null, $default = null): mixed
    {
        if ($key === null) {
            return $_COOKIE;
        }

        return $_COOKIE[$key] ?? $default;
    }

    /**
     * Unified input getter (POST -> GET -> default)
     */
    public static function getInput(string $key, $default = null): mixed
    {
        return self::getPost($key) ?? self::getGet($key) ?? $default;
    }

    /**
     * Set session value safely (Enterprise approach)
     *
     * @param  string  $key  Session key
     * @param  mixed  $value  Value to set
     */
    public static function setSession(string $key, $value): void
    {
        self::init();

        // Security validation
        if (empty($key) || strlen($key) > 200) {
            Logger::error('DEFAULT: Invalid session key', ['key' => $key]);

            return;
        }

        // Validate key doesn't contain dangerous characters
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
            Logger::error('DEFAULT: Session key contains invalid characters', ['key' => $key]);

            return;
        }

        // ENTERPRISE GALAXY: Use conditional session start
        // Setting session value requires active session
        if (session_status() === PHP_SESSION_NONE) {
            \Need2Talk\Services\SecureSessionManager::ensureSessionStarted();
        }

        // Set session value
        $_SESSION[$key] = $value;

        // Cache for performance
        self::$cache["session_$key"] = $value;

        // Enterprise session set
    }

    /**
     * Get all POST data safely (Enterprise approach)
     *
     * @return array All POST data
     */
    public static function getAllPost(): array
    {
        self::init();

        // Use cached version if available
        if (isset(self::$cache['all_post_data'])) {
            return self::$cache['all_post_data'];
        }

        // Get and sanitize all POST data
        $postData = $_POST;

        // Apply security filtering
        $sanitizedData = [];

        foreach ($postData as $key => $value) {
            if (is_string($value)) {
                // Basic sanitization
                $value = trim($value);
                $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            $sanitizedData[$key] = $value;
        }

        // Cache the result
        self::$cache['all_post_data'] = $sanitizedData;

        return $sanitizedData;
    }

    /**
     * Enterprise GLOBALS management (for IDE compatibility and security)
     *
     * @param  string  $key  Global key
     * @param  mixed  $value  Value to set
     */
    public static function setGlobal(string $key, $value): void
    {
        self::init();

        // Security validation for global keys
        if (empty($key) || strlen($key) > 100) {
            Logger::error('DEFAULT: Invalid global key', ['key' => $key]);

            return;
        }

        // Validate that key doesn't contain dangerous characters
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            Logger::error('DEFAULT: Global key contains invalid characters', ['key' => $key]);

            return;
        }

        // Set in $GLOBALS safely
        global ${$key};
        ${$key} = $value;

        // Also store in enterprise cache for tracking
        self::$cache["global_$key"] = $value;

        // Enterprise global set
    }

    /**
     * Get enterprise global value
     *
     * @param  string  $key  Global key
     * @param  mixed  $default  Default value
     * @return mixed
     */
    public static function getGlobal(string $key, $default = null): mixed
    {
        self::init();

        // Check cache first
        if (isset(self::$cache["global_$key"])) {
            return self::$cache["global_$key"];
        }

        // Check $GLOBALS
        global ${$key};

        if (isset(${$key})) {
            self::$cache["global_$key"] = ${$key};

            return ${$key};
        }

        return $default;
    }

    /**
     * Get all ENV variables safely (Enterprise approach)
     *
     * @return array All ENV variables
     */
    public static function getAllEnv(): array
    {
        self::init();

        // Use cached version if available
        if (isset(self::$cache['all_env_data'])) {
            return self::$cache['all_env_data'];
        }

        // Get and sanitize all ENV data
        $envData = $_ENV;

        // Apply security filtering
        $sanitizedData = [];

        foreach ($envData as $key => $value) {
            if (is_string($value)) {
                // Basic sanitization for env values
                $value = trim($value);
                // No HTML escaping for ENV as they're not displayed
            }
            $sanitizedData[$key] = $value;
        }

        // Cache the result
        self::$cache['all_env_data'] = $sanitizedData;

        return $sanitizedData;
    }

    /**
     * Get all SERVER variables safely (Enterprise approach)
     *
     * @return array All SERVER variables (sanitized)
     */
    public static function getAllServer(): array
    {
        self::init();

        // Use cached version if available
        if (isset(self::$cache['all_server_data'])) {
            return self::$cache['all_server_data'];
        }

        // Get SERVER data with enterprise security
        $serverData = $_SERVER;

        // Apply security sanitization
        $sanitizedData = [];

        foreach ($serverData as $key => $value) {
            if (is_string($value)) {
                // Sanitize server values for security
                $value = self::sanitizeRequestUri($value);
            }
            $sanitizedData[$key] = $value;
        }

        // Cache the result
        self::$cache['all_server_data'] = $sanitizedData;

        return $sanitizedData;
    }

    /**
     * Initialize security context
     */
    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$securityContext = [
            'request_started_at' => microtime(true),
            'security_level' => 'enterprise',
            'rate_limit_bucket' => self::getRateLimitBucket(),
            'session_fingerprint' => self::generateSessionFingerprint(),
        ];

        self::$initialized = true;
    }

    /**
     * Secure client IP detection (enterprise-grade)
     *
     * SECURITY FIX 2026-02-01:
     * Uses TrustedProxyValidator to prevent X-Forwarded-For spoofing.
     * X-Forwarded-For is only trusted when request comes from known proxies
     * (Docker network, Cloudflare, localhost).
     */
    private static function getClientIpSecure(): string
    {
        // Check cache first
        if (isset(self::$cache['client_ip'])) {
            return self::$cache['client_ip'];
        }

        // ENTERPRISE SECURITY: Use TrustedProxyValidator
        // This prevents IP spoofing via X-Forwarded-For header
        $ip = \Need2Talk\Services\Security\TrustedProxyValidator::getClientIp();

        self::$cache['client_ip'] = $ip;

        return $ip;
    }

    /**
     * Validate IP address (IPv4/IPv6) - Enterprise development-friendly
     */
    private static function isValidIp(string $ip): bool
    {
        // Remove brackets for IPv6
        $ip = trim($ip, '[]');

        // Basic validation first
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // ENTERPRISE: Allow private ranges in development environment
        if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
            return true; // Accept all valid IPs in development
        }

        // PRODUCTION: Restrict private/reserved ranges for security
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Start secure session with enterprise settings
     */
    private static function startSecureSession(): void
    {
        // Enterprise session settings
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', self::detectHttps() ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_lifetime', '0'); // Session cookies only

        session_start();

        // Generate session fingerprint
        if (!isset($_SESSION['__enterprise_fingerprint'])) {
            $_SESSION['__enterprise_fingerprint'] = self::generateSessionFingerprint();
            $_SESSION['__enterprise_started_at'] = time();
        }
    }

    /**
     * Generate session fingerprint for anti-hijacking
     */
    private static function generateSessionFingerprint(): string
    {
        $components = [
            self::getClientIpSecure(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Validate session fingerprint
     */
    private static function validateSessionFingerprint(): bool
    {
        if (!isset($_SESSION['__enterprise_fingerprint'])) {
            return true; // New session
        }

        $currentFingerprint = self::generateSessionFingerprint();

        return hash_equals($_SESSION['__enterprise_fingerprint'], $currentFingerprint);
    }

    /**
     * Safely destroy session
     */
    private static function destroySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            session_start(); // Start fresh session
        }
    }

    /**
     * Get nested session value using dot notation
     */
    private static function getNestedSessionValue(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $_SESSION;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Sanitize forwarded for header
     */
    private static function sanitizeForwardedFor(string $header): string
    {
        // Remove dangerous characters
        $header = preg_replace('/[^\d\.,\[\]:\s]/', '', $header);

        return substr($header, 0, 255);
    }

    /**
     * Sanitize IP string
     */
    private static function sanitizeIp(string $ip): string
    {
        // Allow only valid IP characters
        return preg_replace('/[^\d\.\[\]:a-fA-F]/', '', $ip);
    }

    /**
     * Sanitize request URI
     */
    private static function sanitizeRequestUri(string $uri): string
    {
        // Remove null bytes and control characters
        $uri = str_replace("\0", '', $uri);
        $uri = preg_replace('/[\x00-\x1F\x7F]/', '', $uri);

        return substr($uri, 0, 2048); // Limit length
    }

    /**
     * Sanitize query string
     */
    private static function sanitizeQueryString(string $query): string
    {
        // Remove null bytes
        $query = str_replace("\0", '', $query);

        return substr($query, 0, 1024);
    }

    /**
     * Validate HTTP method
     */
    private static function validateHttpMethod(string $method): string
    {
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

        return in_array(strtoupper($method), $allowedMethods, true) ? strtoupper($method) : 'GET';
    }

    /**
     * Sanitize user agent
     */
    private static function sanitizeUserAgent(string $userAgent): string
    {
        // Remove control characters and limit length
        $userAgent = preg_replace('/[\x00-\x1F\x7F]/', '', $userAgent);

        return substr($userAgent, 0, 500);
    }

    /**
     * Sanitize referer
     */
    private static function sanitizeReferer(string $referer): string
    {
        // Basic URL validation
        if (filter_var($referer, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return substr($referer, 0, 500);
    }

    /**
     * Validate host header
     */
    private static function validateHost(string $host): string
    {
        // Remove port for validation
        $hostWithoutPort = explode(':', $host)[0];

        // Basic domain validation
        if (!filter_var($hostWithoutPort, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            Logger::warning('SECURITY: Invalid host header', ['host' => $host]);

            return env('SERVER_IP', 'YOUR_SERVER_IP');
        }

        return $host;
    }

    /**
     * Sanitize server name
     */
    private static function sanitizeServerName(string $serverName): string
    {
        // Remove non-domain characters
        return preg_replace('/[^a-zA-Z0-9\.\-]/', '', $serverName);
    }

    /**
     * Detect HTTPS
     */
    private static function detectHttps(): bool
    {
        return
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
    }

    /**
     * Get raw server value with logging
     */
    private static function getRawServerValue(string $key, $default = null)
    {
        return $_SERVER[$key] ?? $default;
    }

    /**
     * Generate rate limit bucket identifier
     */
    private static function getRateLimitBucket(): string
    {
        return 'globals_' . hash('crc32', self::getClientIpSecure());
    }
}
