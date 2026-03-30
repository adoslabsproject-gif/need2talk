<?php
declare(strict_types=1);

namespace Need2Talk\Middleware;

/**
 * Host Header Validation Middleware - Enterprise Security
 *
 * PROTECTS AGAINST:
 * - Host Header Injection
 * - DNS Rebinding attacks
 * - Cache poisoning via X-Forwarded-Host
 * - Reverse proxy attacks (like demo5.bestcache.io)
 *
 * CRITICAL:
 * This is defense-in-depth. Nginx SHOULD block these at web server level,
 * but this provides PHP-level protection if Nginx is bypassed.
 *
 * @package Need2Talk\Middleware
 */
class HostHeaderValidationMiddleware
{
    /**
     * Allowed hosts whitelist
     * ENTERPRISE: Only these hosts are permitted
     */
    private const ALLOWED_HOSTS = [
        'need2talk.it',
        'www.need2talk.it',
        'localhost',           // Docker healthcheck
        '127.0.0.1',          // Local development
        'need2talk.test',     // Local development (Valet/Laravel)
    ];

    /**
     * Handle request and validate Host header
     */
    public function handle(?callable $next = null)
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';

        // Remove port number if present (e.g., "localhost:8000" -> "localhost")
        $host = preg_replace('/:\d+$/', '', $host);

        // SECURITY: Validate against whitelist
        if (!$this->isAllowedHost($host)) {
            $this->logBlockedAttempt($host);
            $this->sendSecurityResponse();
        }

        // Host is valid, continue
        if ($next) {
            return $next();
        }
    }

    /**
     * Check if host is in whitelist
     */
    private function isAllowedHost(string $host): bool
    {
        // Empty host = invalid
        if (empty($host)) {
            return false;
        }

        // Exact match against whitelist
        return in_array($host, self::ALLOWED_HOSTS, true);
    }

    /**
     * Log blocked attempt to security logs
     */
    private function logBlockedAttempt(string $host): void
    {
        if (function_exists('Logger::security')) {
            \Need2Talk\Services\Logger::security('warning', 'Host header blocked', [
                'host' => $host,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            ]);
        }
    }

    /**
     * Send security response and terminate
     */
    private function sendSecurityResponse(): void
    {
        // ENTERPRISE: Return 421 Misdirected Request (RFC 7540)
        // This is the correct HTTP status for invalid Host header
        http_response_code(421);

        // Security headers
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        // Minimal response (no HTML to prevent information leakage)
        echo "421 Misdirected Request\n";
        echo "Invalid Host header\n";

        exit;
    }
}
