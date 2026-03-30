<?php

namespace Need2Talk\Middleware;

use Need2Talk\Models\User;
use Need2Talk\Services\Logger;
use Need2Talk\Services\SecureSessionManager;

/**
 * AuthMiddleware - Autenticazione utenti need2talk (ENTERPRISE UNIFIED)
 *
 * ENTERPRISE GALAXY: Migrated to unified SecureSessionManager
 * - Redis primary storage (100x faster than PostgreSQL)
 * - PostgreSQL audit trail (GDPR compliance)
 * - Multi-device management
 * - Remember-me tokens
 * - Session activity logging
 *
 * Gestisce:
 * - Verifica sessioni attive (Redis + PostgreSQL hybrid)
 * - Validazione remember me tokens
 * - Controllo permessi utente
 * - Redirect automatico login
 * - Età minima 18 anni
 */
class AuthMiddleware
{
    private User $userModel;

    private Logger $logger;

    public function __construct()
    {
        $this->userModel = new User();
        $this->logger = new Logger();
    }

    /**
     * Handle middleware per autenticazione obbligatoria
     */
    public function handle(): ?array
    {
        $user = $this->getCurrentUser();

        if (!$user) {
            // ENTERPRISE V12.3: Detect AJAX requests and respond with JSON 401
            // This prevents NETWORK_ERROR in JavaScript when session expires
            $isAjax = (
                (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                 strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                (isset($_SERVER['HTTP_ACCEPT']) &&
                 strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            );

            if ($isAjax) {
                // JSON response for AJAX requests
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => 'UNAUTHORIZED',
                    'message' => 'La tua sessione è scaduta. Ricarica la pagina e riprova.',
                    'redirect' => url('auth/login'),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Redirect a login se non autenticato (HTML requests)
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
            $loginUrl = url('auth/login');

            // Aggiungi redirect parameter se non già in login/register
            if (!in_array($currentUrl, ['/auth/login', '/auth/register', '/'], true)) {
                $loginUrl .= '?redirect=' . urlencode($currentUrl);
            }

            http_response_code(302);
            header("Location: {$loginUrl}");
            exit;
        }

        // Verifica età minima 18 anni (SKIP per utenti pending che devono completare profilo)
        // OAuth users con status='pending' non hanno ancora birth_year/birth_month
        // Verranno verificati in CompleteProfileController quando compilano il form
        if ($user['status'] !== 'pending' && !$this->verifyMinimumAge($user)) {
            $this->logger->warning('Underage user blocked', [
                'user_id' => $user['id'],
                'birth_year' => $user['birth_year'],
            ]);

            // Logout e redirect con messaggio (ENTERPRISE: SecureSessionManager)
            SecureSessionManager::logout();

            http_response_code(302);
            header('Location: ' . url('auth/login?error=age_restricted'));
            exit;
        }

        // CRITICAL SECURITY: Check if user is banned (HIGHEST PRIORITY)
        // Must be checked BEFORE everything else to prevent VPN/proxy evasion
        if (isset($user['status']) && $user['status'] === 'banned') {
            // Log critical security event
            Logger::security('critical', 'BANNED USER LOGIN ATTEMPT BLOCKED', [
                'user_id' => $user['id'],
                'email' => $user['email'] ?? 'unknown',
                'banned_at' => $user['banned_at'] ?? null,
                'ban_reason' => $user['ban_reason'] ?? 'Unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            // Destroy ALL sessions for banned user (CRITICAL)
            SecureSessionManager::logout();

            // Return 403 Forbidden (do NOT redirect to login - user is permanently banned)
            $this->showBannedPage($user['ban_reason'] ?? 'Security violation');
            exit;
        }

        // ═══════════════════════════════════════════════════════════════
        // ENTERPRISE MODERATION: Check granular ban system (user_bans table)
        // Global scope bans block ALL access to the platform
        // Scope-specific bans (chat, posts, comments) are checked at action level
        // ═══════════════════════════════════════════════════════════════
        try {
            $banService = new \Need2Talk\Services\Moderation\UserBanService();
            $banInfo = $banService->getBanInfoForUser($user['id'], 'global');

            if ($banInfo && $banInfo['is_banned']) {
                Logger::security('critical', 'GLOBAL BAN - USER ACCESS BLOCKED', [
                    'user_id' => $user['id'],
                    'email' => $user['email'] ?? 'unknown',
                    'ban_reason' => $banInfo['reason'],
                    'expires_at' => $banInfo['expires_at'],
                    'is_permanent' => $banInfo['is_permanent'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Destroy session
                SecureSessionManager::logout();

                // Show banned page with reason
                $banReason = $banInfo['reason'];
                if (!$banInfo['is_permanent'] && $banInfo['expires_at']) {
                    $banReason .= "\n\nBan expires: " . $banInfo['expires_at'];
                }

                $this->showBannedPage($banReason, !$banInfo['is_permanent']);
                exit;
            }
        } catch (\Exception $e) {
            // Don't block access if ban service fails - log and continue
            Logger::warning('Ban check failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);
        }

        // Verifica email confermata
        if (!$user['email_verified']) {
            http_response_code(302);
            header('Location: ' . url('auth/verify-email'));
            exit;
        }

        // Verifica account non bloccato
        if ($this->userModel->isAccountLocked($user['id'])) {
            // ENTERPRISE: SecureSessionManager logout
            SecureSessionManager::logout();

            http_response_code(302);
            header('Location: ' . url('auth/login?error=account_locked'));
            exit;
        }

        // ENTERPRISE V11.9: Anti-cache headers for authenticated pages
        // Prevents "back button after logout" showing stale authenticated content
        // Critical for PWA security where browser aggressively caches pages
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

        // ENTERPRISE FIX (2025-12-08): Skip updateLastActivity for API/AJAX calls
        // These are background requests from already-active pages, no need to update activity
        // This reduces UPDATE queries from ~10 per page to 1 per page
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Skip for: /api/*, /social/* (AJAX endpoints that aren't full page loads)
        $isApiRequest = str_starts_with($requestUri, '/api/')
                     || str_starts_with($requestUri, '/social/');

        // ENTERPRISE FIX (2025-12-08): Request-level deduplication
        // Prevents duplicate UPDATE when handle() is called multiple times per request
        static $activityUpdatedForUser = [];
        $alreadyUpdated = isset($activityUpdatedForUser[$user['id']]);

        if (!$isApiRequest && !$alreadyUpdated) {
            $this->userModel->updateLastActivity($user['id']);
            $activityUpdatedForUser[$user['id']] = true;
        }

        return $user;
    }

    /**
     * Handle per guest (solo utenti non autenticati)
     */
    public function handleGuest(): void
    {
        $user = $this->getCurrentUser();

        if ($user) {
            // Redirect al profilo se già autenticato
            http_response_code(302);
            header('Location: ' . url('profile'));
            exit;
        }
    }

    /**
     * Handle per optional auth (utente opzionale)
     */
    public function handleOptional(): ?array
    {
        return $this->getCurrentUser();
    }

    /**
     * Verifica permessi admin
     */
    public function requireAdmin(): array
    {
        $user = $this->handle(); // Prima autentica

        if ($user['role'] !== 'admin') {
            $this->logger->warning('Admin access denied', [
                'user_id' => $user['id'],
                'role' => $user['role'],
            ]);

            // Use 403 error page
            require_once __DIR__ . '/../Views/errors/403.php';
            exit;
        }

        return $user;
    }

    /**
     * Verifica se utente può accedere a risorsa
     */
    public function canAccess(string $resource, ?array $user = null): bool
    {
        if (!$user) {
            $user = $this->getCurrentUser();
        }

        if (!$user) {
            return false;
        }

        // Controlli specifici per risorsa
        switch ($resource) {
            case 'admin_panel':
                return $user['role'] === 'admin';

            case 'audio_upload':
                return !$this->userModel->isAccountLocked($user['id']);

            case 'comments':
                return $user['email_verified'] && !$this->userModel->isAccountLocked($user['id']);

            case 'profile_edit':
                return $user['email_verified'];

            default:
                return true;
        }
    }

    /**
     * Logging security events
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        $this->logger->security($event, array_merge([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => time(),
        ], $data));
    }

    /**
     * Ottieni utente corrente da sessione o remember me (ENTERPRISE UNIFIED)
     *
     * ENTERPRISE GALAXY: Uses SecureSessionManager exclusively
     * - Redis primary storage (instant session lookup)
     * - PostgreSQL audit trail (GDPR compliance)
     * - Remember-me auto-login
     * - Session activity logging
     *
     * This method is CRITICAL: it's the single source of truth for authentication
     */
    private function getCurrentUser(): ?array
    {
        // ENTERPRISE GALAXY: Auth middleware always needs session
        // Private routes require authentication = session must be active
        if (session_status() === PHP_SESSION_NONE) {
            SecureSessionManager::ensureSessionStarted();
        }

        $userId = null;

        // ENTERPRISE: Use SecureSessionManager (Redis + PostgreSQL hybrid)
        if (SecureSessionManager::isAuthenticated()) {
            $userId = SecureSessionManager::getCurrentUserId();
        }

        // ENTERPRISE FIX (2025-11-12): Remember-me RE-ENABLED with proper security
        // BUG FIX: The issue was NOT in remember-me, but in HomeController redirect to /dashboard
        // /dashboard was a PUBLIC admin route (admin_routes.php loaded in config/routes.php)
        // Now admin routes are ONLY loaded via admin.php with proper authentication

        // FALLBACK: Try remember-me token if no active session
        if (!$userId) {
            // Check if current URL is admin panel
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $isAdminUrl = (strpos($requestUri, '/admin_') === 0 || strpos($requestUri, '/x7f9k2m8q1') === 0);

            // Only allow remember-me for NON-admin URLs (admin requires manual 2FA login)
            if (!$isAdminUrl) {
                $userId = SecureSessionManager::validateRememberToken();

                if ($userId) {
                    // Auto-login from remember-me token (creates Redis + PostgreSQL USER session)
                    SecureSessionManager::loginUser($userId, false);
                }
            }
        }

        // Carica dati utente se trovato
        if ($userId) {
            $user = $this->userModel->find($userId);

            if ($user && !$user['deleted_at']) {
                // ENTERPRISE GALAXY (2025-01-23 REFACTORING):
                // $_SESSION['user'] removed - use current_user() helper instead
                // User data now cached in Redis L1 (user:{id}:data) with 5min TTL
                // Zero session writes for better performance and cache consistency

                return $user;
            }

            // Utente eliminato/inesistente, pulisci sessione
            SecureSessionManager::logout();
        }

        return null;
    }

    /**
     * Verifica età minima 18 anni
     */
    private function verifyMinimumAge(array $user): bool
    {
        if (empty($user['birth_year']) || empty($user['birth_month'])) {
            return false; // Dati nascita mancanti
        }

        $birthYear = (int) $user['birth_year'];
        $birthMonth = (int) $user['birth_month'];

        // Calcola età precisa
        $today = new \DateTime();
        $birthDate = new \DateTime("{$birthYear}-{$birthMonth}-01");
        $age = $today->diff($birthDate)->y;

        return $age >= 18;
    }

    /**
     * Show banned page with reason
     * ENTERPRISE MODERATION: Displays user-friendly ban information
     *
     * @param string $reason Ban reason to display
     * @param bool $isTemporary Whether ban is temporary
     */
    private function showBannedPage(string $reason, bool $isTemporary = false): void
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');

        $title = $isTemporary ? 'Account Temporarily Suspended' : 'Account Permanently Banned';
        $icon = $isTemporary ? '⏸️' : '⛔';
        $color = $isTemporary ? '#f59e0b' : '#ff4444';
        $message = $isTemporary
            ? 'Your account has been temporarily suspended from need2talk.'
            : 'Your account has been permanently banned from need2talk for violating our Terms of Service and security policies.';

        echo '<!DOCTYPE html>
<html>
<head>
    <title>' . htmlspecialchars($title) . ' - need2talk</title>
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; text-align: center; padding: 50px; }
        h1 { color: ' . $color . '; font-size: 48px; margin-bottom: 20px; }
        p { font-size: 18px; color: #ccc; max-width: 600px; margin: 20px auto; }
        .reason { background: #2a2a2a; padding: 20px; border-radius: 8px; margin: 30px auto; max-width: 700px; white-space: pre-wrap; }
        .contact { margin-top: 40px; font-size: 14px; color: #888; }
    </style>
</head>
<body>
    <h1>' . $icon . ' ' . htmlspecialchars($title) . '</h1>
    <p>' . htmlspecialchars($message) . '</p>
    <div class="reason">
        <strong>Reason:</strong><br>
        ' . nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) . '
    </div>
    <p class="contact">If you believe this is an error, contact us at <a href="mailto:support@need2talk.it" style="color: #4CAF50;">support@need2talk.it</a></p>
</body>
</html>';
    }
}
