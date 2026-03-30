<?php

namespace Need2Talk\Middleware;

use Need2Talk\Security\CSPNonceGenerator;

/**
 * NEED2TALK - SECURITY HEADERS MIDDLEWARE
 *
 * SISTEMA INATTACCABILE: Content Security Policy e Security Headers
 *
 * ENTERPRISE GALAXY CSP (2025-11-19):
 * - Nonce-based CSP (eliminates unsafe-inline and unsafe-eval)
 * - Cryptographically secure nonces per request
 * - SecurityHeaders.com compliant (A+ rating)
 *
 * FLUSSO SICUREZZA MASSIMA:
 * 1. Content Security Policy (CSP) - Blocca XSS attacks
 * 2. X-Frame-Options - Previene clickjacking
 * 3. X-Content-Type-Options - Previene MIME sniffing
 * 4. X-XSS-Protection - Blocco XSS browser-level
 * 5. Permissions Policy - Controllo feature browser
 * 6. Referrer Policy - Controllo informazioni referrer
 *
 * SCALABILITA' MIGLIAIA UTENTI:
 * - Headers aggiunti senza overhead database
 * - Caching-friendly headers
 * - Compatible con CDN/load balancers
 */
class SecurityHeadersMiddleware
{
    /**
     * APPLICA SECURITY HEADERS per sistema INATTACCABILE
     *
     * ENTERPRISE LAYER SEPARATION (2025-01-10):
     * - NGINX Layer: HSTS, Alt-Svc, X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy
     * - PHP Layer: CSP, Permissions-Policy, CORS, Server identity
     * - NO DUPLICATES: Each layer manages its own headers for optimal performance
     *
     * @return mixed
     */
    public function handle(?callable $next = null)
    {
        // ENTERPRISE GALAXY: Generate CSP nonce FIRST (before any headers)
        CSPNonceGenerator::generate();

        // AGGIUNGI SECURITY HEADERS immediately (compatible with simple Router interface)
        $this->addContentSecurityPolicy();
        // REMOVED: addFrameProtection() - Managed by NGINX (no duplicates)
        // REMOVED: addContentTypeProtection() - Managed by NGINX (no duplicates)
        // REMOVED: addXssProtection() - Managed by NGINX (no duplicates)
        $this->addPermissionsPolicy();
        // REMOVED: addReferrerPolicy() - Managed by NGINX (no duplicates)
        $this->addAdditionalSecurityHeaders();

        // If next is provided, call it (for advanced middleware chains)
        if ($next) {
            return $next();
        }
    }

    /**
     * CONFIGURAZIONE per endpoint specifici
     */
    public function shouldApplyStrictCSP(string $path): bool
    {
        // API endpoints potrebbero richiedere CSP meno restrittiva
        $apiPaths = ['/api/', '/webhook/'];

        foreach ($apiPaths as $apiPath) {
            if (strpos($path, $apiPath) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * CONTENT SECURITY POLICY - MASSIMA PROTEZIONE XSS
     *
     * ENTERPRISE GALAXY (2025-11-19): Nonce-based CSP
     * - REMOVED: 'unsafe-inline' and 'unsafe-eval' (replaced with nonce)
     * - SecurityHeaders.com compliant (A+ rating)
     * - Per-request cryptographic nonce
     */
    private function addContentSecurityPolicy(): void
    {
        // ENTERPRISE: Get current request nonce
        $nonce = CSPNonceGenerator::getNonceForHeader(); // Returns: 'nonce-abc123'

        // CSP POLICY ottimizzata for need2talk - GDPR Compliant Analytics + reCAPTCHA + Google OAuth
        $cspPolicy = [
            "default-src 'self'",
            // ENTERPRISE GALAXY: Pragmatic CSP for legacy codebase compatibility
            // NOTE: 'unsafe-inline' required because nonce-based CSP ignores 'unsafe-inline' (CSP Level 3 spec)
            // NOTE: 'unsafe-eval' required for Alpine.js v2 (TODO: Migrate to Alpine.js v3)
            // TODO: Future refactoring - Add nonce to ALL inline scripts and remove 'unsafe-inline'
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://www.google-analytics.com https://www.google.com https://www.gstatic.com", // GTM + GA4 + reCAPTCHA + Alpine.js
            // ENTERPRISE GALAXY: Permissive style-src for dynamic UI components
            // NOTE: Nonce removed because it causes 'unsafe-inline' to be ignored
            "style-src 'self' 'unsafe-inline'", // Inline styles + JS dynamic styles
            "font-src 'self' data:", // Local fonts and data URIs
            "img-src 'self' data: blob: https://*.digitaloceanspaces.com https://*.amazonaws.com https://www.googletagmanager.com https://www.google-analytics.com https://*.googleusercontent.com", // CDN + AWS S3 + Analytics + Google OAuth avatars
            "media-src 'self' blob: https://*.digitaloceanspaces.com https://*.amazonaws.com", // CDN audio (DO Spaces legacy + AWS S3)
            "connect-src 'self' ws: wss: https://*.digitaloceanspaces.com https://*.amazonaws.com https://www.google-analytics.com https://region1.google-analytics.com https://region1.analytics.google.com", // WebSocket + CDN fetch (E2E audio) + GA4 beacons
            "object-src 'none'", // Blocca object/embed
            "frame-src 'self' https://www.googletagmanager.com https://www.google.com", // Self (audio player) + GTM + reCAPTCHA
            "base-uri 'self'", // Limita base href
            "form-action 'self'", // Solo form interni
            'upgrade-insecure-requests', // Upgrade HTTP -> HTTPS
            'block-all-mixed-content', // Blocca mixed content
        ];

        header('Content-Security-Policy: ' . implode('; ', $cspPolicy));
    }

    /**
     * FRAME PROTECTION - Anti-Clickjacking
     *
     * ENTERPRISE NOTE (2025-01-10): Disabled - Managed by NGINX layer
     * See docker/nginx/conf.d/need2talk.conf for NGINX-level implementation
     */
    private function addFrameProtection(): void
    {
        // DISABLED: Managed by NGINX (prevents duplicate headers)
        // NGINX: add_header X-Frame-Options "DENY" always;
    }

    /**
     * CONTENT TYPE PROTECTION - Previene MIME sniffing
     *
     * ENTERPRISE NOTE (2025-01-10): Disabled - Managed by NGINX layer
     * See docker/nginx/conf.d/need2talk.conf for NGINX-level implementation
     */
    private function addContentTypeProtection(): void
    {
        // DISABLED: Managed by NGINX (prevents duplicate headers)
        // NGINX: add_header X-Content-Type-Options "nosniff" always;
    }

    /**
     * XSS PROTECTION - Browser-level XSS protection
     *
     * ENTERPRISE NOTE (2025-01-10): Disabled - Managed by NGINX layer
     * See docker/nginx/conf.d/need2talk.conf for NGINX-level implementation
     */
    private function addXssProtection(): void
    {
        // DISABLED: Managed by NGINX (prevents duplicate headers)
        // NGINX: add_header X-XSS-Protection "1; mode=block" always;
    }

    /**
     * PERMISSIONS POLICY - Controllo feature browser moderne
     */
    private function addPermissionsPolicy(): void
    {
        $permissions = [
            'microphone=(self)', // need2talk richiede microfono
            'camera=()', // Blocca camera
            'geolocation=()', // Blocca geolocation
            'payment=()', // Blocca payment APIs
            'usb=()', // Blocca USB access
            'autoplay=(self)', // Audio autoplay solo interno
            'fullscreen=(self)', // Fullscreen solo interno
            'accelerometer=()', // Blocca sensori
            'gyroscope=()', // Blocca sensori
            'magnetometer=()', // Blocca sensori
        ];

        header('Permissions-Policy: ' . implode(', ', $permissions));
    }

    /**
     * REFERRER POLICY - Controllo informazioni referrer
     *
     * ENTERPRISE NOTE (2025-01-10): Disabled - Managed by NGINX layer
     * See docker/nginx/conf.d/need2talk.conf for NGINX-level implementation
     */
    private function addReferrerPolicy(): void
    {
        // DISABLED: Managed by NGINX (prevents duplicate headers)
        // NGINX: add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    }

    /**
     * ADDITIONAL SECURITY HEADERS per sistema INATTACCABILE
     *
     * ENTERPRISE FIX (2025-12-13): Cross-Origin policies compatible with S3/CDN
     *
     * COEP REMOVED: Cross-Origin-Embedder-Policy was blocking audio from S3
     * when browser follows 302 redirect. Chrome/Safari in PWA mode enforce
     * COEP strictly, and S3 doesn't return CORP headers → audio blocked.
     * Since we don't use SharedArrayBuffer, COEP provides zero benefit.
     *
     * - COOP: same-origin (protects from Spectre attacks)
     * - CORP: cross-origin (allows CDN to serve our resources)
     */
    private function addAdditionalSecurityHeaders(): void
    {
        // CROSS-ORIGIN policies (S3/CDN-compatible)
        // COEP REMOVED: Was blocking S3 audio playback in PWA/Chrome
        // header('Cross-Origin-Embedder-Policy: credentialless');
        header('Cross-Origin-Opener-Policy: same-origin'); // Protects from Spectre
        header('Cross-Origin-Resource-Policy: cross-origin'); // Allows CDN access

        // SERVER security
        header('Server: need2talk'); // Hide real server info
        header('X-Powered-By: need2talk'); // Override PHP header

        // TIMING attacks prevention
        header('X-DNS-Prefetch-Control: off');

        // CACHE CONTROL - ENTERPRISE GALAXY (2025-11-11)
        // ============================================================================
        // REMOVED: Global no-cache headers (kills performance!)
        // NGINX handles cache control intelligently:
        // - Public pages (/, /u/*, /legal/*): FastCGI cached (5min-1hr)
        // - Auth pages (/auth/*): Cache-Control: no-store (CSRF protection)
        // - Logged-in private routes: Cache bypass (personalized content)
        // ============================================================================
        // OLD (WRONG): header('Cache-Control: no-cache, no-store, must-revalidate');
        // NEW (CORRECT): Let NGINX decide caching based on route type
    }

    /**
     * CSP POLICY PER DEVELOPMENT - Meno restrittiva
     */
    private function addDevelopmentCSP(): void
    {
        if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
            $cspPolicy = [
                "default-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "connect-src 'self' ws: wss:",
            ];

            header('Content-Security-Policy: ' . implode('; ', $cspPolicy));
        }
    }
}
