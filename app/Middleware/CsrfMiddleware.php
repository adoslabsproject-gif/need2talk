<?php

namespace Need2Talk\Middleware;

use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Core\EnterpriseSecurityFunctions;
use Need2Talk\Services\Logger;

/**
 * CsrfMiddleware - Protezione CSRF per need2talk
 *
 * Gestisce:
 * - Generazione token CSRF unici per sessione
 * - Validazione token su richieste POST/PUT/DELETE
 * - Protezione contro attacchi Cross-Site Request Forgery
 * - Whitelisting pagine che non richiedono CSRF
 * - Logging eventi di sicurezza CSRF
 */
class CsrfMiddleware
{
    private array $exemptRoutes;

    private string $tokenName;

    private string $headerName;

    public function __construct()
    {
        $this->tokenName = '_csrf_token';
        $this->headerName = 'X-CSRF-TOKEN';

        // Rotte esenti da validazione CSRF
        $this->exemptRoutes = [
            '/api/auth/login',       // API auth routes
            '/api/auth/register',    // API auth routes
            '/api/auth/logout',
            '/webhook/*',
            '/api/public/*',
            '/api/cookie-consent/*', // GDPR: Cookie consent must work for first-time visitors (no session/CSRF required)
            '/api/logs/client',      // Logging client-side, performance critica
            '/api/csrf/refresh',     // CSRF refresh endpoint (chicken-and-egg problem)
            '/api/validate/*',       // ENTERPRISE: Real-time validation (read-only, no data modification)
            '/api/enterprise-logging', // ENTERPRISE: Error logging endpoint (critical for monitoring)
            '/api/v1/analytics/*',      // ENTERPRISE: Analytics endpoints (performance critical)
            '/api/security-test/*',     // ENTERPRISE: Security testing endpoints (admin testing tools)
            '/api/audio/cache-metrics', // ENTERPRISE GALAXY: Service Worker cache metrics (SW can't send CSRF tokens)
            '*/api/cron/*',             // ENTERPRISE GALAXY: Cron management API (admin routes with dynamic prefix)
            '/api/audio-workers/*',     // ENTERPRISE GALAXY: Audio workers API (admin worker management)
            '/api/email-workers/*',     // ENTERPRISE GALAXY: Email workers API (admin worker management)
            '/api/newsletter-worker/*', // ENTERPRISE GALAXY: Newsletter workers API (admin worker management)
            '/newsletter/unsubscribe/*', // GDPR unsubscribe (token-based auth, no CSRF needed)
            '/newsletter/resubscribe/*', // GDPR re-subscribe (token-based auth, no CSRF needed)
        ];
    }

    /**
     * Handle CSRF protection per richieste modificanti
     *
     * ENTERPRISE GALAXY v10.0: Modern CSRF Protection Architecture
     *
     * TWO-TIER VERIFICATION:
     * 1. AJAX/API requests (JSON): Origin header verification (modern, no sync issues)
     * 2. HTML form submissions: Traditional CSRF token (legacy compatibility)
     *
     * WHY THIS APPROACH:
     * - Origin verification is immune to session regeneration (no token sync needed)
     * - Multiple users on same IP can't cause token conflicts
     * - Multiple tabs/windows work correctly
     * - Standard used by Google, Facebook, GitHub, Stripe
     *
     * CSRF TOKEN STILL REQUIRED FOR:
     * - Traditional HTML form POST (no Origin header on form submit)
     * - Legacy browser support
     * - File upload forms
     */
    public function handle(): void
    {
        // ENTERPRISE GALAXY: Ensure session started for CSRF validation
        // POST requests always need session (handled by conditional logic)
        if (session_status() === PHP_SESSION_NONE) {
            \Need2Talk\Services\SecureSessionManager::ensureSessionStarted();
        }

        $method = EnterpriseGlobalsManager::getServer('REQUEST_METHOD', 'GET');
        $uri = EnterpriseGlobalsManager::getServer('REQUEST_URI', '/');

        // ENTERPRISE FIX: Generate token ONLY on safe methods (GET/HEAD/OPTIONS)
        // For POST/PUT/DELETE, use existing token (don't regenerate during validation!)
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->generateToken();

            return; // Safe methods don't need validation
        }

        // Skip CSRF per rotte esenti
        if ($this->isExemptRoute($uri)) {
            return;
        }

        // ====================================================================
        // ENTERPRISE GALAXY v10.0: Two-Tier CSRF Verification
        // ====================================================================
        //
        // TIER 1: Origin Verification for AJAX/API requests
        // --------------------------------------------------
        // For requests with JSON content-type or AJAX markers, use Origin verification.
        // This is the modern standard and doesn't require token synchronization.
        //
        // TIER 2: Token Verification for HTML forms
        // -----------------------------------------
        // For traditional form submissions, use CSRF token validation.
        // This is needed because browsers don't send Origin on form POST.
        //
        // ====================================================================

        if ($this->isAjaxOrApiRequest()) {
            // TIER 1: Use Origin verification for AJAX/API requests
            $originMiddleware = new OriginVerificationMiddleware();

            if ($originMiddleware->verify()) {
                return; // Origin verified - CSRF protection complete
            }

            // Origin verification failed - this is suspicious for AJAX
            Logger::security('warning', 'CSRF: Origin verification failed for AJAX request', [
                'method' => $method,
                'uri' => $uri,
                'origin' => EnterpriseGlobalsManager::getServer('HTTP_ORIGIN', 'MISSING'),
                'referer' => EnterpriseGlobalsManager::getServer('HTTP_REFERER', 'MISSING'),
            ]);

            // For AJAX, if Origin fails, it's likely an attack - don't fall back to token
            $this->handleCsrfFailure($uri, $method);
            return;
        }

        // TIER 2: Traditional token validation for HTML forms
        // Valida token CSRF per richieste modificanti (POST/PUT/DELETE)
        // CRITICAL: Do NOT call generateToken() before validation!
        if (!$this->validateToken()) {
            $this->handleCsrfFailure($uri, $method);
        }
    }

    /**
     * ENTERPRISE GALAXY v10.0: Detect if request is AJAX/API
     *
     * Returns true for:
     * - XMLHttpRequest (X-Requested-With header)
     * - Content-Type: application/json
     * - Accept: application/json
     * - Fetch API requests with JSON content
     *
     * @return bool True if AJAX/API request
     */
    private function isAjaxOrApiRequest(): bool
    {
        // XHR marker
        $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                 strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isXhr) {
            return true;
        }

        // JSON Content-Type (request body is JSON)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            return true;
        }

        // Accept header prefers JSON (Fetch API typically sends this)
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }

        // Check if URI starts with /api/ (API endpoint)
        $uri = EnterpriseGlobalsManager::getServer('REQUEST_URI', '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (str_starts_with($path, '/api/')) {
            return true;
        }

        return false;
    }

    /**
     * Genera nuovo token CSRF per la sessione corrente
     * ENTERPRISE DEBUG: Extensive logging to track token lifecycle
     * ENTERPRISE GALAXY: Skip token generation for legitimate bots
     */
    public function generateToken(): string
    {
        // ENTERPRISE GALAXY: Skip CSRF token generation for legitimate bots
        // Bots don't submit forms, so they don't need CSRF protection
        // This prevents log spam and unnecessary session creation
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (\Need2Talk\Middleware\AntiVulnerabilityScanningMiddleware::isLegitimateBot($userAgent)) {
            // Return empty token for bots (they never submit forms anyway)
            return '';
        }

        $tokenExists = !empty(EnterpriseGlobalsManager::getSession('csrf_token'));
        $existingToken = EnterpriseGlobalsManager::getSession('csrf_token', '');
        $isExpired = $this->isTokenExpired();

        if (!$tokenExists || $isExpired) {
            $newToken = $this->createToken();

            // PRODUCTION: Log only when token is REGENERATED (not every request)
            Logger::security('info', 'CSRF token REGENERATED', [
                'old_token_preview' => $existingToken ? substr($existingToken, 0, 20) . '...' : 'NONE',
                'new_token_preview' => substr($newToken, 0, 20) . '...',
                'reason' => $tokenExists ? 'expired' : 'new_session',
                'session_id' => session_id(),
            ]);

            EnterpriseGlobalsManager::setSession('csrf_token', $newToken);
            EnterpriseGlobalsManager::setSession('csrf_token_time', time());

            // Log CSRF event for security monitoring
            Logger::csrfEvent('token_generated', [
                'reason' => $tokenExists ? 'expired' : 'new_session',
            ]);
        }

        return EnterpriseGlobalsManager::getSession('csrf_token', '');
    }

    /**
     * Ottieni token corrente (per inserimento in form)
     */
    public function getToken(): string
    {
        return EnterpriseGlobalsManager::getSession('csrf_token') ?? $this->generateToken();
    }

    /**
     * Helper per inserimento token in form HTML
     */
    public static function tokenInput(): string
    {
        $middleware = new self();
        $token = $middleware->getToken();

        return "<input type=\"hidden\" name=\"_csrf_token\" value=\"{$token}\">";
    }

    /**
     * Helper per token JavaScript
     */
    public static function tokenMeta(): string
    {
        $middleware = new self();
        $token = $middleware->getToken();

        return "<meta name=\"csrf-token\" content=\"{$token}\">";
    }

    /**
     * Aggiungi rotta esente da CSRF
     */
    public function addExemptRoute(string $route): void
    {
        if (!in_array($route, $this->exemptRoutes, true)) {
            $this->exemptRoutes[] = $route;
        }
    }

    /**
     * Rimuovi rotta esente
     */
    public function removeExemptRoute(string $route): void
    {
        $key = array_search($route, $this->exemptRoutes, true);

        if ($key !== false) {
            unset($this->exemptRoutes[$key]);
        }
    }

    /**
     * Ottieni lista rotte esenti (per debug)
     */
    public function getExemptRoutes(): array
    {
        return $this->exemptRoutes;
    }

    /**
     * Valida token CSRF da POST data o header
     * ENTERPRISE DEBUG: Extensive logging to find mismatch source
     */
    private function validateToken(): bool
    {
        $sessionToken = EnterpriseGlobalsManager::getSession('csrf_token', '');
        $sessionTokenTime = EnterpriseGlobalsManager::getSession('csrf_token_time', 0);

        if (empty($sessionToken)) {
            Logger::security('warning', 'CSRF Validation: No token in session');
            return false;
        }

        // Check all possible token sources
        $submittedToken = '';

        // 1. Cerca token in POST data
        if (!empty($_POST[$this->tokenName])) {
            $submittedToken = $_POST[$this->tokenName];
        }

        // 2. Se non in POST, cerca in header
        if (empty($submittedToken)) {
            $headers = getallheaders();

            // ENTERPRISE FIX: Try multiple header name variations
            $headerVariations = [
                $this->headerName,           // X-CSRF-TOKEN
                'X-Csrf-Token',              // Case variation
                'HTTP_X_CSRF_TOKEN',         // $_SERVER format
            ];

            foreach ($headerVariations as $headerName) {
                if (!empty($headers[$headerName])) {
                    $submittedToken = $headers[$headerName];
                    $tokenSource = "HEADER:{$headerName}";
                    break;
                }
            }

            // Fallback: Check $_SERVER directly
            if (empty($submittedToken) && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
                $tokenSource = 'SERVER:HTTP_X_CSRF_TOKEN';
            }
        }

        // 3. Se non in header, cerca in JSON body
        if (empty($submittedToken)) {
            $jsonInput = json_decode(file_get_contents('php://input'), true);
            if (!empty($jsonInput[$this->tokenName])) {
                $submittedToken = $jsonInput[$this->tokenName];
            }
        }

        if (empty($submittedToken)) {
            Logger::security('warning', 'CSRF Validation: No token submitted');
            return false;
        }

        // Verifica token non scaduto
        if ($this->isTokenExpired()) {
            Logger::security('warning', 'CSRF Validation: Token expired');
            return false;
        }

        // Confronto sicuro token
        $valid = hash_equals($sessionToken, $submittedToken);

        if (!$valid) {
            Logger::security('warning', 'CSRF Validation: Token mismatch', [
                'session_token' => $sessionToken,
                'submitted_token' => $submittedToken,
                'session_id' => session_id(),
                'token_age_seconds' => time() - $sessionTokenTime,
            ]);
        }

        return $valid;
    }

    /**
     * Crea token crittograficamente sicuro
     */
    private function createToken(): string
    {
        return bin2hex(EnterpriseSecurityFunctions::randomBytes(32));
    }

    /**
     * Verifica se token è scaduto (2 ore)
     */
    private function isTokenExpired(): bool
    {
        if (!EnterpriseGlobalsManager::getSession('csrf_token_time')) {
            return true;
        }

        $maxAge = 2 * 60 * 60; // 2 ore

        return (time() - (EnterpriseGlobalsManager::getSession('csrf_token_time') ?? 0)) > $maxAge;
    }

    /**
     * Verifica se rotta è esente da CSRF
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
     * Gestione fallimento validazione CSRF
     * 🚀 ENTERPRISE GALAXY: Auto-ban per attacchi CSRF ripetuti (integrato con AntiVulnerabilityScanningMiddleware)
     */
    private function handleCsrfFailure(string $uri, string $method): void
    {
        $ip = EnterpriseGlobalsManager::getServer('REMOTE_ADDR', '');
        $userAgent = EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', '');

        Logger::security('warning', 'SECURITY: CSRF validation failed', [
            'method' => $method,
            'uri' => $uri,
            'user_id' => EnterpriseGlobalsManager::getSession('user_id'),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'referer' => EnterpriseGlobalsManager::getServer('HTTP_REFERER', ''),
        ]);

        // 🚀 ENTERPRISE GALAXY: Integrate with centralized anti-scan ban system (unified scoring)
        $antiScan = new AntiVulnerabilityScanningMiddleware();
        $antiScan->handleCsrfAttack($ip, $userAgent, $uri);

        // ENTERPRISE FIX: Regenerate token BEFORE showing error
        // This ensures the new page load has a FRESH token that matches session
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        $newToken = $this->generateToken();  // Generate NEW token immediately

        // ENTERPRISE UX FIX: Check if this is an AJAX request or browser form submission
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $prefersJson = strpos($acceptHeader, 'application/json') !== false;

        // AJAX or JSON request: Return JSON response with NEW token
        if ($isAjax || $prefersJson) {
            http_response_code(419);
            header('Content-Type: application/json');

            echo json_encode([
                'error' => 'CSRF token mismatch',
                'message' => 'La tua sessione è scaduta. Ricarica la pagina e riprova.',
                'code' => 419,
                'new_token' => $newToken,
            ]);
            exit;
        }

        // Browser form submission: FORCE PAGE RELOAD with cache-busting query param
        // CRITICAL: Standard redirect (Location header) doesn't force reload if browser has cached DOM
        // Solution: Add ?t=timestamp to force browser to treat as new URL → full page reload
        EnterpriseGlobalsManager::setSession('csrf_error', true);
        EnterpriseGlobalsManager::setSession('csrf_message', 'La tua sessione è scaduta. Ricarica la pagina e riprova.');

        // Try to redirect back to the referer, or to homepage if no referer
        $referer = EnterpriseGlobalsManager::getServer('HTTP_REFERER', '');

        if (!empty($referer) && strpos($referer, $_SERVER['HTTP_HOST']) !== false) {
            // Safe referer from same domain - add cache buster to FORCE reload
            $cacheBuster = time() . rand(100, 999);
            $separator = (strpos($referer, '?') !== false) ? '&' : '?';
            header('Location: ' . $referer . $separator . '_t=' . $cacheBuster);
        } else {
            // No safe referer, redirect to homepage with cache buster
            header('Location: /?_t=' . time() . rand(100, 999));
        }
        exit;
    }

    /**
     * ENTERPRISE: Determine token source for logging
     */
    private function getTokenSource(): string
    {
        // Check POST data first
        if (!empty($_POST[$this->tokenName] ?? '')) {
            return 'post_data';
        }

        // Check headers
        $headers = getallheaders();

        if (!empty($headers[$this->headerName] ?? '')) {
            return 'http_header';
        }

        return 'unknown';
    }
}
