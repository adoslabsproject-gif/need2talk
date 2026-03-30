<?php

namespace Need2Talk\Middleware;

/**
 * NEED2TALK - HTTPS ENFORCEMENT MIDDLEWARE
 *
 * SICUREZZA CRITICA: Forza HTTPS per rendere sistema INATTACCABILE
 *
 * FLUSSO SICUREZZA:
 * 1. Controlla se richiesta è HTTPS
 * 2. Se HTTP, redirect 301 a HTTPS
 * 3. Previene man-in-the-middle attacks
 * 4. Protegge credenziali e dati sensibili
 * 5. Ottimizzato per migliaia di utenti contemporanei
 *
 * SCALABILITA' MIGLIAIA UTENTI:
 * - Redirect veloce senza overhead database
 * - Header caching per performance
 * - Compatible con load balancer/CDN
 */
class HttpsMiddleware
{
    /**
     * ENFORCE HTTPS per sistema INATTACCABILE
     *
     * @return mixed
     */
    public function handle(callable $next)
    {
        // SICUREZZA: Controlla se request è già HTTPS
        if (!$this->isHttpsRequest()) {
            // REDIRECT SICURO: 301 permanent redirect a HTTPS
            $this->enforceHttpsRedirect();

            return; // Stop execution dopo redirect
        }

        // SECURITY HEADERS: Aggiungi header HTTPS avanzati
        $this->addSecurityHeaders();

        return $next();
    }

    /**
     * CONFIGURAZIONE MIDDLEWARE - Escludi API specifiche se necessario
     */
    public static function getExcludedPaths(): array
    {
        return [
            // Eventuali endpoint che devono rimanere HTTP (rate)
            // '/api/health-check'
        ];
    }

    /**
     * CONTROLLA se richiesta è HTTPS
     */
    private function isHttpsRequest(): bool
    {
        // METODO 1: Standard HTTPS check
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        }

        // METODO 2: Check per load balancer/proxy (X-Forwarded-Proto)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        // METODO 3: Check porta 443 standard HTTPS
        if (isset($_SERVER['SERVER_PORT'])
            && $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        // METODO 4: Check per Cloudflare/CDN
        if (isset($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);

            if (isset($visitor['scheme']) && $visitor['scheme'] === 'https') {
                return true;
            }
        }

        return false;
    }

    /**
     * ENFORCES HTTPS redirect - SICUREZZA MASSIMA
     */
    private function enforceHttpsRedirect(): void
    {
        // COSTRUISCI URL HTTPS sicuro
        $host = $_SERVER['HTTP_HOST'] ?? env('SERVER_IP', 'YOUR_SERVER_IP');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // SANITIZZAZIONE URL per prevenire header injection
        $host = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $host);
        $uri = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');

        $httpsUrl = 'https://' . $host . $uri;

        // SECURITY HEADERS per redirect
        header('Location: ' . $httpsUrl, true, 301);
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

        // ENTERPRISE GALAXY (2025-11-11): REMOVED global no-cache headers
        // - HTTP→HTTPS redirect doesn't need no-cache (kills browser back button UX)
        // - NGINX handles cache control intelligently per route type
        // - Public pages SHOULD be cached for performance
        // OLD (WRONG): header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        // OLD (WRONG): header('Pragma: no-cache');

        exit();
    }

    /**
     * AGGIUNGI SECURITY HEADERS avanzati per HTTPS
     */
    private function addSecurityHeaders(): void
    {
        // HSTS: HTTP Strict Transport Security - FORZA HTTPS per 1 anno
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

        // SECURITY: Upgrade insecure requests
        header('Content-Security-Policy: upgrade-insecure-requests');

        // SECURITY: Referrer policy per HTTPS
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
