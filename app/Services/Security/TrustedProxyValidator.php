<?php

namespace Need2Talk\Services\Security;

/**
 * ENTERPRISE GALAXY: Trusted Proxy Validator
 *
 * Previene IP Spoofing via X-Forwarded-For validando che le richieste
 * con header proxy provengano SOLO da proxy fidati.
 *
 * SENZA QUESTA PROTEZIONE:
 * - Attacker può spoofare X-Forwarded-For: 1.2.3.4
 * - Bypassa TUTTI i rate limit IP-based
 * - Bypassa ban per IP
 * - Inquina i log con IP falsi
 *
 * CON QUESTA PROTEZIONE:
 * - X-Forwarded-For accettato SOLO da proxy trusted (Nginx, Docker, Cloudflare)
 * - Richieste dirette usano REMOTE_ADDR (non spoofabile)
 * - Rate limiting funziona correttamente
 *
 * @version 1.0.0
 */
class TrustedProxyValidator
{
    /**
     * Trusted proxy IP ranges
     * Questi sono gli unici IP da cui accettiamo X-Forwarded-For
     */
    private const TRUSTED_PROXIES = [
        // Docker internal networks
        '172.16.0.0/12',    // Docker default bridge
        '10.0.0.0/8',       // Docker custom networks
        '192.168.0.0/16',   // Docker host mode

        // Localhost
        '127.0.0.1/32',
        '::1/128',

        // Cloudflare IP ranges (aggiornato 2025)
        // IPv4: https://www.cloudflare.com/ips-v4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',

        // Cloudflare IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Trusted proxy headers in priority order
     */
    private const PROXY_HEADERS = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare (highest trust)
        'HTTP_X_FORWARDED_FOR',      // Standard proxy header
        'HTTP_X_REAL_IP',            // Nginx
    ];

    private static ?bool $isTrustedProxy = null;
    private static ?string $validatedClientIp = null;

    /**
     * Get the REAL client IP, immune to spoofing
     *
     * @return string Real client IP address
     */
    public static function getClientIp(): string
    {
        // Return cached result
        if (self::$validatedClientIp !== null) {
            return self::$validatedClientIp;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        // Check if request comes from a trusted proxy
        if (self::isTrustedProxy($remoteAddr)) {
            // Only now do we trust X-Forwarded-For
            foreach (self::PROXY_HEADERS as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = self::extractClientIpFromHeader($_SERVER[$header]);
                    if ($ip !== null) {
                        self::$validatedClientIp = $ip;
                        return $ip;
                    }
                }
            }
        }

        // Not from trusted proxy OR no valid forwarded IP
        // Use REMOTE_ADDR (cannot be spoofed at TCP level)
        self::$validatedClientIp = filter_var($remoteAddr, FILTER_VALIDATE_IP) ?: 'unknown';

        return self::$validatedClientIp;
    }

    /**
     * Check if the direct connection (REMOTE_ADDR) is from a trusted proxy
     */
    public static function isTrustedProxy(string $ip): bool
    {
        if (self::$isTrustedProxy !== null && $ip === ($_SERVER['REMOTE_ADDR'] ?? '')) {
            return self::$isTrustedProxy;
        }

        foreach (self::TRUSTED_PROXIES as $range) {
            if (self::ipInRange($ip, $range)) {
                self::$isTrustedProxy = true;
                return true;
            }
        }

        self::$isTrustedProxy = false;
        return false;
    }

    /**
     * Extract the real client IP from X-Forwarded-For header
     *
     * X-Forwarded-For format: client, proxy1, proxy2, ...
     * We want the LEFTMOST non-trusted IP (the real client)
     */
    private static function extractClientIpFromHeader(string $header): ?string
    {
        // Split by comma and process
        $ips = array_map('trim', explode(',', $header));

        // Walk through from left (client) to right (proxies)
        foreach ($ips as $ip) {
            // Remove port if present (e.g., "1.2.3.4:12345")
            if (str_contains($ip, ':') && !str_contains($ip, '[')) {
                // IPv4 with port
                $ip = explode(':', $ip)[0];
            }

            // Validate IP format
            $ip = filter_var($ip, FILTER_VALIDATE_IP);
            if ($ip === false) {
                continue;
            }

            // Skip trusted proxies in the chain
            if (self::isTrustedProxyIp($ip)) {
                continue;
            }

            // First non-trusted IP is the client
            return $ip;
        }

        return null;
    }

    /**
     * Check if IP is a trusted proxy (for X-Forwarded-For chain validation)
     */
    private static function isTrustedProxyIp(string $ip): bool
    {
        foreach (self::TRUSTED_PROXIES as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an IP is within a CIDR range
     */
    private static function ipInRange(string $ip, string $cidr): bool
    {
        // Handle IPv6
        if (str_contains($cidr, ':')) {
            return self::ipv6InRange($ip, $cidr);
        }

        // IPv4
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$range, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $rangeLong = ip2long($range);

        if ($ipLong === false || $rangeLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        $ipLong &= $mask;
        $rangeLong &= $mask;

        return $ipLong === $rangeLong;
    }

    /**
     * Check if IPv6 is in range
     */
    private static function ipv6InRange(string $ip, string $cidr): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        [$range, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipBin = inet_pton($ip);
        $rangeBin = inet_pton($range);

        if ($ipBin === false || $rangeBin === false) {
            return false;
        }

        // Create mask
        $mask = str_repeat("\xff", (int) ($bits / 8));
        if ($bits % 8) {
            $mask .= chr(0xff << (8 - ($bits % 8)));
        }
        $mask = str_pad($mask, 16, "\x00");

        return ($ipBin & $mask) === ($rangeBin & $mask);
    }

    /**
     * Get diagnostic info for debugging
     */
    public static function getDiagnostics(): array
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        return [
            'remote_addr' => $remoteAddr,
            'is_trusted_proxy' => self::isTrustedProxy($remoteAddr),
            'validated_client_ip' => self::getClientIp(),
            'raw_x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'raw_x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
            'raw_cf_connecting_ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            'trusted_proxy_count' => count(self::TRUSTED_PROXIES),
        ];
    }

    /**
     * Reset cache (useful for testing)
     */
    public static function resetCache(): void
    {
        self::$isTrustedProxy = null;
        self::$validatedClientIp = null;
    }
}
