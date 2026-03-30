<?php

declare(strict_types=1);

/**
 * AdminHelpers.php - ENTERPRISE GALAXY ADMIN HELPERS
 *
 * CRITICAL: This file is loaded ONLY on admin routes
 * Performance: Zero impact on public/user routes
 *
 * Isolation: Admin functions completely separated from public helpers
 * Security: Enterprise-grade authentication and authorization
 *
 * @package Need2Talk\Helpers
 */

// ENTERPRISE: Use fully qualified class names (no 'use' statements in global scope)

// ============================================================================
// ADMIN AUTHENTICATION & AUTHORIZATION
// ============================================================================

if (!function_exists('is_admin_user')) {
    /**
     * Check if current user is authenticated admin
     *
     * ENTERPRISE SECURITY:
     * - Validates admin_session cookie
     * - Checks session expiration
     * - Verifies IP whitelist (if configured)
     * - Cached for request lifecycle (performance)
     *
     * Performance: <5ms (cached after first call)
     * Cache: Per-request only (never persisted)
     *
     * @return bool True if admin authenticated
     */
    function is_admin_user(): bool
    {
        static $isAdmin = null;
        static $adminData = null;

        // PERFORMANCE: Cache result for request lifecycle
        if ($isAdmin !== null) {
            return $isAdmin;
        }

        // Check if admin_session cookie exists
        $sessionToken = $_COOKIE['__Host-admin_session'] ?? null;

        if (!$sessionToken) {
            $isAdmin = false;

            return false;
        }

        // ENTERPRISE: Validate session with AdminSecurityService
        try {
            $adminService = new \Need2Talk\Services\AdminSecurityService();
            $adminData = $adminService->validateAdminSession($sessionToken);

            if ($adminData === null) {
                // Session invalid or expired
                $isAdmin = false;

                // SECURITY: Log invalid session attempt
                \Need2Talk\Services\Logger::security('warning', 'Invalid admin session token used', [
                    'session_token' => substr($sessionToken, 0, 16) . '...', // Partial token for security
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);

                return false;
            }

            // Session valid
            $isAdmin = true;

            // SECURITY: Log admin access (audit trail)
            \Need2Talk\Services\Logger::security('debug', 'Admin user authenticated', [
                'admin_id' => $adminData['id'] ?? 'unknown',
                'admin_email' => $adminData['email'] ?? 'unknown',
                'admin_role' => $adminData['role'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            return true;

        } catch (\Exception $e) {
            // ENTERPRISE: Graceful degradation on error
            \Need2Talk\Services\Logger::error('Admin authentication check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $isAdmin = false;

            return false;
        }
    }
}

if (!function_exists('get_admin_user')) {
    /**
     * Get current authenticated admin user data
     *
     * ENTERPRISE: Returns admin user info for audit logging
     *
     * @return array|null Admin data or null if not authenticated
     */
    function get_admin_user(): ?array
    {
        static $adminData = null;

        // Check if already cached
        if ($adminData !== null) {
            return $adminData;
        }

        // Validate session
        $sessionToken = $_COOKIE['__Host-admin_session'] ?? null;

        if (!$sessionToken) {
            return null;
        }

        try {
            $adminService = new \Need2Talk\Services\AdminSecurityService();
            $adminData = $adminService->validateAdminSession($sessionToken);

            return $adminData;

        } catch (\Exception $e) {
            \Need2Talk\Services\Logger::error('Failed to get admin user data', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

if (!function_exists('require_admin')) {
    /**
     * Require admin authentication (or die with 403)
     *
     * ENTERPRISE: Use this at the top of admin-only endpoints
     *
     * @return void
     */
    function require_admin(): void
    {
        if (!is_admin_user()) {
            // SECURITY: Log unauthorized access attempt
            \Need2Talk\Services\Logger::security('warning', 'Unauthorized admin access attempt', [
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => 'Admin authentication required',
            ]);
            exit;
        }
    }
}

// ============================================================================
// ADMIN DATABASE HELPERS (Fresh Data, No Cache)
// ============================================================================

if (!function_exists('admin_db_fresh')) {
    /**
     * Get fresh PDO connection for admin (bypasses cache, resets connection state)
     *
     * ENTERPRISE ADMIN: Always fresh data for real-time monitoring
     * - DISCARD ALL (PostgreSQL) to clear session state, temp tables, prepared statements
     * - Bypasses query cache (admin needs real-time data)
     * - PostgreSQL uses READ COMMITTED isolation by default (no dirty reads)
     *
     * Performance: Acceptable trade-off for admin accuracy
     * Impact: Admin only - zero impact on public routes
     *
     * @return \PDO|\AutoReleasePDO Fresh PDO connection
     */
    function admin_db_fresh()
    {
        $pdo = db_pdo();

        // ENTERPRISE: PostgreSQL session reset - Nuclear option for fresh data
        try {
            $pdo->exec('DISCARD ALL'); // PostgreSQL: Reset session state (temp tables, prepared statements, sequences)
        } catch (\Exception $e) {
            // Fallback: Minimal reset
            try {
                $pdo->exec('ROLLBACK'); // Exit any transaction (PostgreSQL returns to autocommit mode)
            } catch (\Exception $e2) {
                // Ignore errors
            }
        }

        return $pdo;
    }
}

if (!function_exists('admin_invalidate_cache')) {
    /**
     * Invalidate cache for admin operations
     *
     * ENTERPRISE: Call this after admin modifications to force cache refresh
     *
     * @param string|array $patterns Cache key patterns to invalidate
     * @return void
     */
    function admin_invalidate_cache($patterns): void
    {
        if (!is_array($patterns)) {
            $patterns = [$patterns];
        }

        $cache = cache();
        if (!$cache) {
            return;
        }

        foreach ($patterns as $pattern) {
            try {
                // Pattern-based invalidation
                if (strpos($pattern, '*') !== false) {
                    // Wildcard invalidation (e.g., "user:*")
                    $cache->deleteByPattern($pattern);
                } else {
                    // Exact key invalidation
                    $cache->delete($pattern);
                }

                \Need2Talk\Services\Logger::debug('Admin cache invalidation', [
                    'pattern' => $pattern,
                ]);
            } catch (\Exception $e) {
                \Need2Talk\Services\Logger::error('Failed to invalidate cache', [
                    'pattern' => $pattern,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

// ============================================================================
// ADMIN AUDIT LOGGING
// ============================================================================

if (!function_exists('admin_audit_log')) {
    /**
     * Log admin action for audit trail
     *
     * ENTERPRISE: Comprehensive audit logging for compliance
     *
     * @param string $action Action performed (e.g., "DELETE_USER", "MODERATE_CONTENT")
     * @param array $details Action details
     * @return void
     */
    function admin_audit_log(string $action, array $details = []): void
    {
        $adminUser = get_admin_user();

        if (!$adminUser) {
            return;
        }

        try {
            $adminService = new \Need2Talk\Services\AdminSecurityService();
            $adminService->logAdminAction(
                (int)$adminUser['id'],
                $action,
                array_merge($details, [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                ])
            );
        } catch (\Exception $e) {
            \Need2Talk\Services\Logger::error('Failed to log admin action', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

// ============================================================================
// ADMIN PERFORMANCE HELPERS
// ============================================================================

if (!function_exists('admin_disable_cache_headers')) {
    /**
     * Disable cache headers for admin pages (always fresh data)
     *
     * ENTERPRISE: Admin pages must NEVER be cached
     *
     * @return void
     */
    function admin_disable_cache_headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

// ============================================================================
// ADMIN VIEW HELPERS
// ============================================================================

if (!function_exists('admin_avatar_url')) {
    /**
     * Normalize avatar URL for display in admin views
     *
     * ENTERPRISE: Handles all avatar URL formats:
     * - External URLs (https://...) → return as-is
     * - Absolute paths (/storage/...) → return as-is
     * - Relative paths (avatars/uuid/...) → add /storage/uploads/ prefix
     *
     * @param string|null $avatarUrl Raw avatar URL from database
     * @param string $default Default avatar if null/empty
     * @return string Normalized avatar URL
     */
    function admin_avatar_url(?string $avatarUrl, string $default = '/assets/img/default-avatar.png'): string
    {
        if (empty($avatarUrl)) {
            return $default;
        }

        // External URL (Google OAuth, etc.) - return as-is
        if (str_starts_with($avatarUrl, 'http://') || str_starts_with($avatarUrl, 'https://')) {
            return $avatarUrl;
        }

        // Already absolute path - return as-is
        if (str_starts_with($avatarUrl, '/')) {
            return $avatarUrl;
        }

        // Relative path - add /storage/uploads/ prefix
        return '/storage/uploads/' . $avatarUrl;
    }
}

// ============================================================================
// AUTOLOAD CONFIRMATION
// ============================================================================

// ENTERPRISE: Log that admin helpers are loaded (debug only)
if (config('app.debug', false)) {
    \Need2Talk\Services\Logger::debug('AdminHelpers loaded successfully', [
        'file' => __FILE__,
        'functions' => [
            'is_admin_user',
            'get_admin_user',
            'require_admin',
            'admin_db_fresh',
            'admin_invalidate_cache',
            'admin_audit_log',
            'admin_disable_cache_headers',
        ],
    ]);
}
