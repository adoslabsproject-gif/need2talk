<?php

namespace Need2Talk\Middleware;

use Need2Talk\Services\Logger;
use Need2Talk\Services\SecureSessionManager;

/**
 * ProfileCompletionMiddleware - ENTERPRISE GALAXY GDPR COMPLIANCE (2025-01-17)
 *
 * PURPOSE:
 * Enforces profile completion for OAuth users (Google, etc.) who registered
 * with status='pending' and MUST complete GDPR consent + age verification
 * before accessing the site.
 *
 * SECURITY:
 * - Blocks ALL routes except /complete-profile for pending users
 * - Prevents attackers from registering via OAuth and immediately scanning /admin
 * - Ensures GDPR compliance (consent timestamp recorded)
 * - Ensures age verification (18+ requirement)
 *
 * FLOW:
 * 1. User completes OAuth → status='pending' + session created
 * 2. AuthMiddleware validates authentication
 * 3. THIS middleware checks if status='pending'
 * 4. If pending: redirect to /complete-profile (MANDATORY)
 * 5. If active: allow normal access
 *
 * USAGE:
 * Apply this middleware to ALL authenticated routes AFTER AuthMiddleware:
 * - Router: $router->get('/profile', 'ProfileController@show', ['auth', 'profile_completion']);
 * - Middleware runs in order: auth → profile_completion → controller
 *
 * @package Need2Talk\Middleware
 * @version 1.0.0
 * @since 2025-01-17
 */
class ProfileCompletionMiddleware
{
    /**
     * Whitelisted routes that don't require profile completion
     * These routes are accessible even with status='pending'
     *
     * CRITICAL: Keep this list MINIMAL for security
     */
    private const WHITELIST_ROUTES = [
        '/complete-profile',           // The completion form itself
        '/auth/logout',                // Allow logout
        '/api/health',                 // Health check
    ];

    /**
     * Whitelisted route prefixes
     * Routes starting with these patterns don't require completion
     */
    private const WHITELIST_PREFIXES = [
        '/complete-profile',           // All completion-related routes
    ];

    /**
     * Handle middleware: Check if user has completed profile
     *
     * ENTERPRISE GALAXY LEVEL:
     * - Only runs for authenticated users (after AuthMiddleware)
     * - Checks user status: 'pending' = incomplete, 'active' = complete
     * - Redirects pending users to /complete-profile
     * - Logs security events
     * - Prevents session hijacking by checking user data freshness
     *
     * @return void
     */
    public function handle(): void
    {
        // SECURITY: Must be authenticated (AuthMiddleware runs first)
        if (!SecureSessionManager::isAuthenticated()) {
            // Not authenticated - let AuthMiddleware handle it
            return;
        }

        // ENTERPRISE GALAXY (2025-01-23 REFACTORING): Get user from Redis L1 cache
        $user = current_user();

        if (!$user) {
            // No user authenticated - redirect to login
            Logger::security('warning', 'PROFILE_COMPLETION: No authenticated user', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'session_id' => session_id(),
            ]);
            SecureSessionManager::logout();
            header('Location: ' . url('auth/login'));
            exit;
        }

        // Get current route
        $currentRoute = $_SERVER['REQUEST_URI'] ?? '/';
        $currentPath = parse_url($currentRoute, PHP_URL_PATH) ?? '/';

        // WHITELIST: Allow specific routes without profile completion
        if ($this->isWhitelisted($currentPath)) {
            return;
        }

        // CHECK: If user status is 'pending', redirect to complete-profile
        $userStatus = $user['status'] ?? 'pending';

        if ($userStatus === 'pending') {
            // Redirect to profile completion form
            http_response_code(302);
            header('Location: ' . url('complete-profile'));
            exit;
        }

        // OPTIONAL: Check if GDPR consent is missing (additional safety check)
        // Even if status='active', enforce GDPR consent for old OAuth users
        if (empty($user['gdpr_consent_at']) && ($user['oauth_provider'] ?? null)) {
            // ENTERPRISE FIX: Reload from database to check if session is just stale
            // This prevents infinite loop when session is not in sync with database
            $db = db();
            $freshUser = $db->findOne(
                'SELECT gdpr_consent_at, status FROM users WHERE id = ?',
                [$user['id']],
                ['cache' => false] // Force fresh read
            );

            // If user actually HAS consent in DB, invalidate cache and allow access
            if ($freshUser && !empty($freshUser['gdpr_consent_at'])) {
                // ENTERPRISE GALAXY (2025-01-23): Invalidate cache instead of session write
                invalidate_user_cache($user['id'], ['data', 'profile']);
                return;
            }

            // User genuinely missing GDPR consent - redirect
            Logger::security('warning', 'PROFILE_COMPLETION: Active OAuth user missing GDPR consent', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($user['email'] ?? '')),
                'oauth_provider' => $user['oauth_provider'],
                'status' => $userStatus,
            ]);

            // Redirect to complete-profile for GDPR consent
            http_response_code(302);
            header('Location: ' . url('complete-profile'));
            exit;
        }

        // User has completed profile - allow access
        return;
    }

    /**
     * Check if current route is whitelisted
     *
     * @param string $path Current route path
     * @return bool True if whitelisted, false otherwise
     */
    private function isWhitelisted(string $path): bool
    {
        // Exact match
        if (in_array($path, self::WHITELIST_ROUTES, true)) {
            return true;
        }

        // Prefix match
        foreach (self::WHITELIST_PREFIXES as $prefix) {
            if (strpos($path, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
