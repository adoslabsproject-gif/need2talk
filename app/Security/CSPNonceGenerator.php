<?php

declare(strict_types=1);

namespace Need2Talk\Security;

/**
 * CSP Nonce Generator - Enterprise Security
 *
 * SECURITY FEATURES:
 * - Cryptographically secure random nonce generation
 * - Per-request nonce (prevents replay attacks)
 * - Base64-encoded for CSP compliance
 * - Zero file I/O (OPcache friendly)
 *
 * PERFORMANCE:
 * - Generated once per request
 * - Stored in static property (no overhead)
 * - No database/cache queries
 *
 * USAGE:
 * In middleware:
 *   CSPNonceGenerator::generate();
 *
 * In views:
 *   <script nonce="<?= csp_nonce() ?>">
 *   <style nonce="<?= csp_nonce() ?>">
 *
 * @author Claude Code + zelistore
 * @version 1.0.0
 * @since 2025-11-19
 */
class CSPNonceGenerator
{
    /**
     * Current request nonce (singleton per request)
     */
    private static ?string $nonce = null;

    /**
     * Generate cryptographically secure nonce for current request
     *
     * ENTERPRISE: Uses random_bytes (CSPRNG) instead of mt_rand
     * RFC 2397: Base64 encoding without padding (CSP spec)
     *
     * @return string Base64-encoded nonce (22 characters)
     */
    public static function generate(): string
    {
        // ENTERPRISE: Generate only once per request (performance)
        if (self::$nonce !== null) {
            return self::$nonce;
        }

        // SECURITY: 128-bit random (16 bytes = 128 bits)
        // Base64 encoding: 16 bytes → 22 characters (no padding)
        $randomBytes = random_bytes(16);

        // COMPLIANCE: Base64 URL-safe encoding (RFC 4648)
        // Remove padding (=) for cleaner CSP header
        self::$nonce = rtrim(base64_encode($randomBytes), '=');

        return self::$nonce;
    }

    /**
     * Get current request nonce (must be generated first)
     *
     * @return string|null Current nonce or null if not generated yet
     */
    public static function getNonce(): ?string
    {
        return self::$nonce;
    }

    /**
     * Reset nonce (for testing purposes only)
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$nonce = null;
    }

    /**
     * Get CSP-formatted nonce attribute
     *
     * Example: nonce="abc123def456"
     *
     * @return string HTML-safe nonce attribute
     */
    public static function getNonceAttribute(): string
    {
        $nonce = self::getNonce();

        if ($nonce === null) {
            // ENTERPRISE: Auto-generate if not done by middleware
            // This ensures views always work even if middleware fails
            $nonce = self::generate();
        }

        return 'nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Get CSP-formatted nonce for header
     *
     * Example: 'nonce-abc123def456'
     *
     * @return string CSP header-safe nonce value
     */
    public static function getNonceForHeader(): string
    {
        $nonce = self::getNonce();

        if ($nonce === null) {
            $nonce = self::generate();
        }

        return "'nonce-{$nonce}'";
    }
}
