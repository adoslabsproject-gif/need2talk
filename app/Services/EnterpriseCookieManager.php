<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseGlobalsManager;

/**
 * Enterprise Cookie Manager - Smart Cookie Handling
 *
 * Features:
 * - Environment-aware security (dev/prod)
 * - SameSite policy management
 * - Performance optimized for 10,000+ users
 * - GDPR compliance ready
 * - Automatic fallback for development environments
 */
class EnterpriseCookieManager
{
    private static ?self $instance = null;

    private EnterpriseEnvironmentManager $envManager;

    private bool $isDevelopment = false;

    private bool $isHttps = false;

    private function __construct()
    {
        $this->envManager = EnterpriseEnvironmentManager::getInstance();
        $this->isDevelopment = $this->envManager->get('APP_ENV') === 'development';

        // ENTERPRISE FIX: Durante OPcache preload (CLI), $_SERVER non esiste
        // Lazy-load isHttps solo quando serve (in web context)
        if (PHP_SAPI !== 'cli') {
            $this->isHttps = $this->detectHttps();
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Configure session cookie parameters for enterprise environments
     *
     * ENTERPRISE GALAXY V6.6: Default lifetime now reads from env
     *
     * @param int|null $lifetime Session lifetime in seconds (null = read from env)
     */
    public function configureSessionCookies(?int $lifetime = null): array
    {
        // ENTERPRISE GALAXY V6.6: Use centralized session lifetime if not provided
        $lifetime = $lifetime ?? EnterpriseGlobalsManager::getSessionLifetimeSeconds();
        // ENTERPRISE SECURITY: __Host- prefix requires NO domain attribute
        // RFC 6265bis: __Host- cookies are host-only (more secure than domain cookies)
        $params = [
            'lifetime' => $lifetime,
            'path' => '/',
            // NO 'domain' parameter (required for __Host- prefix)
            'secure' => true,  // __Host- requires HTTPS
            'httponly' => true,
            'samesite' => $this->getSameSitePolicy(),
        ];

        session_set_cookie_params($params);

        return $params;
    }

    /**
     * Set application cookie with enterprise settings and __Host- prefix
     *
     * ENTERPRISE SECURITY: Uses __Host- prefix for maximum security
     * __Host- prefix requirements (RFC 6265bis):
     * - Secure flag MUST be set (HTTPS only)
     * - Path MUST be / (entire domain)
     * - Domain attribute MUST NOT be present (host-only cookie)
     *
     * @param string $name Cookie name (without prefix, will be auto-added)
     * @param string $value Cookie value
     * @param int $expire Expiration in seconds (0 = session cookie)
     * @param array $options Override options (for backward compatibility)
     * @return bool Success status
     */
    public function setApplicationCookie(string $name, string $value, int $expire = 0, array $options = []): bool
    {
        // ENTERPRISE: Auto-add __Host- prefix if not already present
        if (!str_starts_with($name, '__Host-') && !str_starts_with($name, '__Secure-')) {
            $name = '__Host-' . $name;
        }

        // __Host- prefix requires strict settings
        $defaultOptions = [
            'path' => '/',
            // NO 'domain' - required for __Host- prefix (host-only binding)
            'secure' => true,  // __Host- requires HTTPS
            'httponly' => true,
            'samesite' => $this->getSameSitePolicy(),
        ];

        $options = array_merge($defaultOptions, $options);

        // Use expire = 0 for session cookie, otherwise calculate expiry
        if ($expire > 0) {
            $expire = time() + $expire;
        }

        $result = setcookie($name, $value, [
            'expires' => $expire,
            'path' => $options['path'],
            // NO 'domain' parameter (required for __Host- prefix)
            'secure' => $options['secure'],
            'httponly' => $options['httponly'],
            'samesite' => $options['samesite'],
        ]);

        // ENTERPRISE: NO logging here to prevent circular dependency during OPcache preload
        // Cookie operations should be transparent and not trigger Logger
        // Security auditing happens at session/auth level, not cookie level

        return $result;
    }

    /**
     * Get diagnostic information
     */
    public function getDiagnostics(): array
    {
        return [
            'environment' => $this->isDevelopment ? 'development' : 'production',
            'https_detected' => $this->isHttps,
            'cookie_strategy' => $this->getCookieStrategy(),
            'cookie_domain' => $this->getCookieDomain(),
            'secure_cookies' => $this->shouldUseSecureCookies(),
            'samesite_policy' => $this->getSameSitePolicy(),
            'server_info' => [
                'http_host' => $_SERVER['HTTP_HOST'] ?? null,
                'server_port' => $_SERVER['SERVER_PORT'] ?? null,
                'https_header' => $_SERVER['HTTPS'] ?? null,
                'forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
            ],
        ];
    }

    /**
     * Detect HTTPS intelligently across environments
     */
    private function detectHttps(): bool
    {
        // Check standard HTTPS indicators
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Check reverse proxy headers
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }

        // Check port 443 (standard HTTPS)
        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        // Force HTTPS in production even if not detected
        if (!$this->isDevelopment && $this->envManager->get('FORCE_HTTPS', false)) {
            return true;
        }

        return false;
    }

    /**
     * Get cookie strategy based on environment
     */
    private function getCookieStrategy(): string
    {
        if ($this->isDevelopment) {
            return $this->isHttps ? 'dev_secure' : 'dev_insecure';
        }

        return $this->isHttps ? 'prod_secure' : 'prod_fallback';
    }

    /**
     * Get cookie domain intelligently
     */
    private function getCookieDomain(): string
    {
        // Development: use empty domain for localhost/127.0.0.1
        if ($this->isDevelopment) {
            $host = $_SERVER['HTTP_HOST'] ?? env('SERVER_IP', 'YOUR_SERVER_IP');

            // For localhost/127.0.0.1, use empty domain
            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                || strpos($host, 'localhost:') === 0
                || strpos($host, '127.0.0.1:') === 0) {
                return '';
            }

            return $host;
        }

        // Production: use configured domain or auto-detect
        $configuredDomain = $this->envManager->get('COOKIE_DOMAIN');

        if ($configuredDomain) {
            return $configuredDomain;
        }

        // Auto-detect from HTTP_HOST, strip port
        $host = $_SERVER['HTTP_HOST'] ?? env('SERVER_IP', 'YOUR_SERVER_IP');
        $host = explode(':', $host)[0]; // Remove port

        return $host;
    }

    /**
     * Determine if cookies should be secure
     */
    private function shouldUseSecureCookies(): bool
    {
        // In development with HTTP, allow insecure cookies
        if ($this->isDevelopment && !$this->isHttps) {
            return false;
        }

        // Production should always use secure cookies
        if (!$this->isDevelopment) {
            return true;
        }

        // Development with HTTPS
        return $this->isHttps;
    }

    /**
     * Get SameSite policy based on environment
     */
    private function getSameSitePolicy(): string
    {
        // Development: More permissive
        if ($this->isDevelopment) {
            return 'Lax';
        }

        // Production: More restrictive based on configuration
        return $this->envManager->get('COOKIE_SAMESITE', 'Strict');
    }
}
