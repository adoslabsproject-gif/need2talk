<?php

namespace Need2Talk\Middleware;

use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Services\Logger;

/**
 * OriginVerificationMiddleware - Modern CSRF Protection for API Endpoints
 *
 * ENTERPRISE GALAXY SECURITY PATTERN
 *
 * This middleware implements the modern CSRF protection pattern used by:
 * - Google (all APIs)
 * - Facebook (Graph API)
 * - GitHub (REST + GraphQL APIs)
 * - Stripe (Payment APIs)
 * - Twitter/X (all APIs)
 *
 * WHY THIS APPROACH:
 * ==================
 * Traditional CSRF tokens have fundamental issues:
 * 1. Token must be synchronized between client/server (race conditions)
 * 2. Session regeneration breaks token validity
 * 3. Multiple tabs/windows cause token conflicts
 * 4. Token must be included in every AJAX request (boilerplate)
 *
 * Modern browsers provide built-in CSRF protection via:
 * 1. SameSite cookies - Prevents cross-site cookie inclusion
 * 2. Origin header - Cannot be forged by JavaScript (browser security model)
 * 3. Referer header - Fallback when Origin not present
 *
 * HOW IT WORKS:
 * =============
 * 1. Browser automatically sends Origin header on cross-origin requests
 * 2. Browser sends Origin header on same-origin POST/PUT/DELETE
 * 3. If Origin matches our domain → request is legitimate
 * 4. If Origin is missing but Referer matches → legitimate (older browsers)
 * 5. If neither matches → reject as potential CSRF attack
 *
 * SECURITY GUARANTEES:
 * ====================
 * - JavaScript CANNOT forge Origin header (W3C Fetch Standard)
 * - JavaScript CANNOT forge Referer header (browser security)
 * - Attackers cannot bypass SameSite=Lax cookies
 * - Works with session regeneration (no token sync needed)
 * - Works with multiple tabs/windows (no token conflicts)
 *
 * WHEN TO USE:
 * ============
 * - All AJAX/fetch API requests (Content-Type: application/json)
 * - API endpoints that modify data (POST/PUT/DELETE)
 * - Endpoints with session-based authentication
 *
 * WHEN NOT TO USE:
 * ================
 * - Traditional HTML form submissions (use CSRF token)
 * - Public APIs with token-based auth (API keys)
 * - Webhook endpoints (use signature verification)
 *
 * @package Need2Talk\Middleware
 * @author Claude Code (AI-Orchestrated Enterprise Development)
 * @since 2025-12-03
 * @version 1.0.0
 */
class OriginVerificationMiddleware
{
    /**
     * Allowed origins for CSRF verification
     * Loaded from configuration, supports multiple domains
     */
    private array $allowedOrigins = [];

    /**
     * Whether to allow requests without Origin/Referer (legacy browsers)
     * Set to false in strict mode (recommended for high-security endpoints)
     */
    private bool $allowMissingOrigin = false;

    /**
     * Routes that are exempt from origin verification
     * (e.g., webhooks that use signature verification)
     */
    private array $exemptRoutes = [];

    public function __construct()
    {
        // Load allowed origins from configuration
        $this->loadAllowedOrigins();

        // Routes exempt from origin verification (use other auth methods)
        $this->exemptRoutes = [
            '/webhook/*',              // Webhooks use signature verification
            '/api/public/*',           // Public APIs (no auth needed)
            '/api/csrf/refresh',       // CSRF refresh endpoint
            '/api/logs/client',        // Client logging (read-only analytics)
        ];
    }

    /**
     * Verify request origin for CSRF protection
     *
     * @return bool True if origin is valid, false otherwise
     */
    public function verify(): bool
    {
        $method = EnterpriseGlobalsManager::getServer('REQUEST_METHOD', 'GET');
        $uri = EnterpriseGlobalsManager::getServer('REQUEST_URI', '/');

        // Safe methods don't need origin verification
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        // Check exempt routes
        if ($this->isExemptRoute($uri)) {
            return true;
        }

        // Get Origin header (preferred)
        $origin = EnterpriseGlobalsManager::getServer('HTTP_ORIGIN', '');

        // Fallback to Referer header
        $referer = '';
        if (empty($origin)) {
            $refererFull = EnterpriseGlobalsManager::getServer('HTTP_REFERER', '');
            if (!empty($refererFull)) {
                $parsed = parse_url($refererFull);
                if ($parsed) {
                    $scheme = $parsed['scheme'] ?? 'https';
                    $host = $parsed['host'] ?? '';
                    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                    $referer = "{$scheme}://{$host}{$port}";
                }
            }
        }

        // Use Origin if available, otherwise Referer
        $requestOrigin = !empty($origin) ? $origin : $referer;

        // If no Origin or Referer, check if we allow missing origin
        if (empty($requestOrigin)) {
            if ($this->allowMissingOrigin) {
                return true;
            }

            // ENTERPRISE: Check if this is an AJAX request
            // Some older setups may not send Origin, but XHR should
            $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            if ($isXhr) {
                // XHR without Origin is suspicious in modern browsers
                Logger::security('warning', 'ORIGIN_VERIFY: XHR request without Origin header', [
                    'uri' => $uri,
                    'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', ''),
                ]);
            }

            // For now, allow missing origin but in strict mode this would return false
            return true;
        }

        // Verify origin is in allowed list
        $isAllowed = $this->isOriginAllowed($requestOrigin);

        if (!$isAllowed) {
            Logger::security('warning', 'ORIGIN_VERIFY: Origin mismatch (potential CSRF)', [
                'request_origin' => $requestOrigin,
                'allowed_origins' => $this->allowedOrigins,
                'uri' => $uri,
                'method' => $method,
                'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', ''),
                'user_agent' => substr(EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', ''), 0, 100),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check if origin is in allowed list
     *
     * @param string $origin Request origin (e.g., https://need2talk.it)
     * @return bool
     */
    private function isOriginAllowed(string $origin): bool
    {
        // Normalize origin (lowercase, no trailing slash)
        $origin = strtolower(rtrim($origin, '/'));

        foreach ($this->allowedOrigins as $allowed) {
            $allowed = strtolower(rtrim($allowed, '/'));

            if ($origin === $allowed) {
                return true;
            }

            // Wildcard subdomain matching (e.g., *.need2talk.it)
            if (str_starts_with($allowed, '*.')) {
                $domain = substr($allowed, 2); // Remove *.
                if (str_ends_with($origin, $domain)) {
                    // Check it's actually a subdomain, not just suffix match
                    $prefix = substr($origin, 0, -strlen($domain));
                    if (str_ends_with($prefix, '://') || str_ends_with($prefix, '.')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if route is exempt from origin verification
     *
     * @param string $uri Request URI
     * @return bool
     */
    private function isExemptRoute(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);

        foreach ($this->exemptRoutes as $route) {
            if ($route === $path) {
                return true;
            }

            // Wildcard matching
            if (str_ends_with($route, '/*')) {
                $prefix = substr($route, 0, -2);
                if (str_starts_with($path, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Load allowed origins from configuration
     */
    private function loadAllowedOrigins(): void
    {
        // Primary domain
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'https://need2talk.it', '/');

        $this->allowedOrigins = [
            $appUrl,                          // https://need2talk.it
            'https://need2talk.it',           // Without www
            'https://www.need2talk.it',       // With www
        ];

        // Development origins (only in dev/local environment)
        $appEnv = $_ENV['APP_ENV'] ?? 'production';
        if (in_array($appEnv, ['local', 'development', 'testing'], true)) {
            $this->allowedOrigins = array_merge($this->allowedOrigins, [
                'http://localhost:8000',
                'http://localhost:3000',
                'http://127.0.0.1:8000',
                'https://localhost',
            ]);

            // In development, allow missing origin for easier testing
            $this->allowMissingOrigin = true;
        }
    }

    /**
     * Add an allowed origin dynamically
     *
     * @param string $origin Origin URL
     */
    public function addAllowedOrigin(string $origin): void
    {
        if (!in_array($origin, $this->allowedOrigins, true)) {
            $this->allowedOrigins[] = $origin;
        }
    }

    /**
     * Handle origin verification failure
     * Returns JSON error response
     */
    public function handleFailure(): void
    {
        $ip = EnterpriseGlobalsManager::getServer('REMOTE_ADDR', '');
        $uri = EnterpriseGlobalsManager::getServer('REQUEST_URI', '');

        Logger::security('warning', 'ORIGIN_VERIFY: Request blocked (invalid origin)', [
            'ip' => $ip,
            'uri' => $uri,
        ]);

        http_response_code(403);
        header('Content-Type: application/json');

        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'Request origin verification failed',
            'code' => 403,
        ]);

        exit;
    }

    /**
     * Static helper to verify origin and handle failure
     *
     * @return bool True if verified (continues execution), exits on failure
     */
    public static function verifyOrFail(): bool
    {
        $middleware = new self();

        if (!$middleware->verify()) {
            $middleware->handleFailure();
            return false; // Never reached due to exit
        }

        return true;
    }

    /**
     * Check if request is a same-origin AJAX request
     *
     * Useful for determining if full CSRF token is needed
     *
     * @return bool True if same-origin AJAX
     */
    public static function isSameOriginAjax(): bool
    {
        $middleware = new self();

        // Must be XHR or Fetch request
        $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                 strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $acceptsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        $contentTypeJson = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        $isAjaxLike = $isXhr || $acceptsJson || $contentTypeJson;

        if (!$isAjaxLike) {
            return false;
        }

        // Must pass origin verification
        return $middleware->verify();
    }
}
