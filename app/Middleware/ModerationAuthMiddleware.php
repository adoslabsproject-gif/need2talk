<?php

namespace Need2Talk\Middleware;

use Need2Talk\Services\Logger;
use Need2Talk\Services\Moderation\ModerationSecurityService;

/**
 * ModerationAuthMiddleware - Enterprise Moderation Portal Authentication
 *
 * Validates moderator sessions and permissions for protected routes.
 * Completely separate from AdminAuthMiddleware.
 *
 * @package Need2Talk\Middleware
 */
class ModerationAuthMiddleware
{
    /**
     * Handle the middleware check
     *
     * @param string|null $requiredPermission Optional specific permission to check
     * @return bool True if authenticated and authorized
     */
    public function handle(?string $requiredPermission = null): bool
    {
        // Validate session
        $session = ModerationSecurityService::validateSession();

        if (!$session) {
            $this->handleUnauthenticated();
            return false;
        }

        // Store session data for use in controllers
        $GLOBALS['mod_session'] = $session;

        // Check specific permission if required
        if ($requiredPermission && !$this->hasPermission($session, $requiredPermission)) {
            $this->handleUnauthorized($requiredPermission);
            return false;
        }

        return true;
    }

    /**
     * Get current moderator session
     */
    public static function getSession(): ?array
    {
        return $GLOBALS['mod_session'] ?? null;
    }

    /**
     * Get current moderator ID
     */
    public static function getModeratorId(): ?int
    {
        return $GLOBALS['mod_session']['moderator_id'] ?? null;
    }

    /**
     * Check if moderator has specific permission
     */
    public function hasPermission(array $session, string $permission): bool
    {
        $permissionMap = [
            'view_rooms' => 'can_view_rooms',
            'ban_users' => 'can_ban_users',
            'delete_messages' => 'can_delete_messages',
            'manage_keywords' => 'can_manage_keywords',
            'view_reports' => 'can_view_reports',
            'resolve_reports' => 'can_resolve_reports',
            'escalate_reports' => 'can_escalate_reports',
        ];

        $dbField = $permissionMap[$permission] ?? $permission;

        return !empty($session[$dbField]);
    }

    /**
     * Check permission statically
     */
    public static function can(string $permission): bool
    {
        $session = self::getSession();
        if (!$session) {
            return false;
        }

        $instance = new self();
        return $instance->hasPermission($session, $permission);
    }

    /**
     * Handle unauthenticated request
     */
    private function handleUnauthenticated(): void
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

        if ($isAjax || $isApiRequest) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Authentication required',
                'redirect' => ModerationSecurityService::generateModerationUrl() . '/login',
            ]);
            exit;
        }

        // Redirect to login
        $loginUrl = ModerationSecurityService::generateModerationUrl() . '/login';
        header('Location: ' . $loginUrl);
        exit;
    }

    /**
     * Handle unauthorized request (authenticated but lacks permission)
     */
    private function handleUnauthorized(string $permission): void
    {
        Logger::security('warning', 'MOD: Unauthorized access attempt', [
            'moderator_id' => self::getModeratorId(),
            'required_permission' => $permission,
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => get_server('REMOTE_ADDR'),
        ]);

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

        if ($isAjax || $isApiRequest) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Permission denied: ' . $permission,
            ]);
            exit;
        }

        // Show 403 page
        http_response_code(403);
        echo '<html><head><title>Access Denied</title></head>';
        echo '<body style="background:#0f0f0f;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;">';
        echo '<div style="text-align:center;">';
        echo '<h1 style="color:#ef4444;">403 - Access Denied</h1>';
        echo '<p style="color:#9ca3af;">You do not have permission to access this resource.</p>';
        echo '<p style="color:#6b7280;font-size:14px;">Required permission: ' . htmlspecialchars($permission) . '</p>';
        echo '<a href="' . ModerationSecurityService::generateModerationUrl() . '/dashboard" style="color:#a855f7;">Return to Dashboard</a>';
        echo '</div></body></html>';
        exit;
    }
}
