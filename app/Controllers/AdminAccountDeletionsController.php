<?php

namespace Need2Talk\Controllers;

use Need2Talk\Services\AccountDeletionAnalyticsService;
use Need2Talk\Services\AdminSecurityService;
use Need2Talk\Services\Logger;

/**
 * 🚀 ENTERPRISE GALAXY: Admin Account Deletions Dashboard Controller
 *
 * GDPR Article 17 compliance monitoring and analytics dashboard.
 * Provides comprehensive oversight of account deletion lifecycle.
 *
 * SECURITY:
 * - Admin-only access (validated via AdminSecurityService)
 * - IP address masking for privacy
 * - Rate limiting for dashboard access
 * - Audit logging for all actions
 *
 * FEATURES:
 * - Timeline visualization (daily/weekly/monthly)
 * - Real-time KPI dashboard
 * - Deletion records management
 * - Recovery rate analytics
 * - Rate limiting violations monitoring
 */
class AdminAccountDeletionsController
{
    private AccountDeletionAnalyticsService $analyticsService;
    private AdminSecurityService $adminSecurity;

    public function __construct()
    {
        $this->analyticsService = new AccountDeletionAnalyticsService();
        $this->adminSecurity = new AdminSecurityService();

        Logger::info('ADMIN: AdminAccountDeletionsController initialized');
    }

    /**
     * 📊 ENTERPRISE: Main dashboard view
     *
     * Displays comprehensive account deletions analytics with:
     * - KPI cards (total, pending, recovered, completed)
     * - Timeline chart (Chart.js)
     * - Recent deletions table
     * - Rate limiting violations alert
     *
     * @return void Renders dashboard view
     */
    public function dashboard(): void
    {
        try {
            // SECURITY: Validate admin access
            if (!$this->validateAdminAccess()) {
                $this->unauthorized();
                return;
            }

            // Get dashboard data
            $stats = $this->analyticsService->getDashboardStats();
            $timelinePeriod = $_GET['period'] ?? 'daily';
            $timelineData = $this->analyticsService->getTimelineData($timelinePeriod);

            // Get recent deletions (first page)
            $statusFilter = $_GET['status'] ?? 'all';
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = 20;
            $offset = ($page - 1) * $limit;

            $deletionsData = $this->analyticsService->getRecentDeletions($limit, $offset, $statusFilter);

            // Prepare view data
            $viewData = [
                'stats' => $stats,
                'timeline' => $timelineData,
                'timeline_period' => $timelinePeriod,
                'deletions' => $deletionsData['deletions'],
                'deletions_total' => $deletionsData['total'],
                'deletions_pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => $deletionsData['has_more'],
                    'total_pages' => ceil($deletionsData['total'] / $limit),
                ],
                'status_filter' => $statusFilter,
                'page_title' => 'Account Deletions Dashboard - GDPR Compliance',
            ];

            // Audit log
            Logger::security('info', 'ADMIN: Account deletions dashboard accessed', [
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'period' => $timelinePeriod,
                'status_filter' => $statusFilter,
                'page' => $page,
            ]);

            // 🚀 ENTERPRISE GALAXY: Use standard admin layout pattern
            // Extract variables for view
            extract($viewData);

            // Set view and title for layout.php
            $view = 'account-deletions-dashboard';
            $title = 'Account Deletions Dashboard';

            // Render using standard admin layout (includes sidebar)
            $layoutPath = APP_ROOT . '/app/Views/admin/layout.php';
            if (file_exists($layoutPath)) {
                include $layoutPath;
            } else {
                throw new \Exception('Admin layout not found');
            }

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to load account deletions dashboard', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->errorPage('Failed to load dashboard', $e->getMessage());
        }
    }

    /**
     * 🔍 ENTERPRISE: Get deletion details (AJAX endpoint)
     *
     * @param int $id Deletion record ID (matches route parameter {id})
     * @return void JSON response
     */
    public function getDeletionDetails(int $id): void
    {
        try {
            // SECURITY: Validate admin access
            if (!$this->validateAdminAccess()) {
                $this->jsonResponse(['error' => 'Unauthorized'], 403);
                return;
            }

            $details = $this->analyticsService->getDeletionDetails($id);

            if (!$details) {
                $this->jsonResponse(['error' => 'Deletion not found'], 404);
                return;
            }

            // Audit log
            Logger::security('info', 'ADMIN: Viewed deletion details', [
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'deletion_id' => $id,
                'user_id' => $details['user_id'],
            ]);

            $this->jsonResponse([
                'success' => true,
                'deletion' => $details,
            ]);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get deletion details', [
                'deletion_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse(['error' => 'Failed to load details'], 500);
        }
    }

    /**
     * 📈 ENTERPRISE: Get timeline data (AJAX endpoint for chart updates)
     *
     * @return void JSON response with Chart.js data
     */
    public function getTimelineData(): void
    {
        try {
            // SECURITY: Validate admin access
            if (!$this->validateAdminAccess()) {
                $this->jsonResponse(['error' => 'Unauthorized'], 403);
                return;
            }

            $period = $_GET['period'] ?? 'daily';
            $timelineData = $this->analyticsService->getTimelineData($period);

            $this->jsonResponse([
                'success' => true,
                'timeline' => $timelineData,
                'period' => $period,
            ]);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get timeline data', [
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse(['error' => 'Failed to load timeline'], 500);
        }
    }

    /**
     * 📊 ENTERPRISE: Export deletions to CSV
     *
     * @return void CSV download
     */
    public function exportCsv(): void
    {
        try {
            // SECURITY: Validate admin access
            if (!$this->validateAdminAccess()) {
                $this->unauthorized();
                return;
            }

            $statusFilter = $_GET['status'] ?? 'all';

            // Get all deletions (no pagination)
            $deletionsData = $this->analyticsService->getRecentDeletions(10000, 0, $statusFilter);
            $deletions = $deletionsData['deletions'];

            // Audit log
            Logger::security('warning', 'ADMIN: Exported account deletions CSV', [
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'status_filter' => $statusFilter,
                'records_count' => count($deletions),
            ]);

            // Generate CSV
            $filename = 'account_deletions_' . date('Y-m-d_His') . '.csv';

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');

            $output = fopen('php://output', 'w');

            // CSV headers
            fputcsv($output, [
                'ID',
                'User ID',
                'Email',
                'Nickname',
                'Status',
                'Reason',
                'Requested At',
                'Scheduled Deletion',
                'Cancelled At',
                'Deleted At',
                'IP Address (Masked)',
                'User Agent',
            ]);

            // CSV data
            foreach ($deletions as $deletion) {
                fputcsv($output, [
                    $deletion['id'],
                    $deletion['user_id'],
                    $deletion['email'],
                    $deletion['nickname'],
                    $deletion['status'],
                    $deletion['reason'] ?? '',
                    $deletion['created_at'],
                    $deletion['scheduled_deletion_at'],
                    $deletion['cancelled_at'] ?? '',
                    $deletion['deleted_at'] ?? '',
                    $this->maskIpAddress($deletion['ip_address'] ?? ''),
                    substr($deletion['user_agent'] ?? '', 0, 100),
                ]);
            }

            fclose($output);
            exit;

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to export CSV', [
                'error' => $e->getMessage(),
            ]);

            $this->errorPage('Export Failed', $e->getMessage());
        }
    }

    /**
     * 🔄 ENTERPRISE: Refresh dashboard (granular cache invalidation)
     *
     * Invalidates ONLY dashboard cache, not timeline/details.
     * Used by "Refresh" button for live updates.
     *
     * @return void JSON response
     */
    public function refreshDashboard(): void
    {
        try {
            // SECURITY: Validate admin access
            if (!$this->validateAdminAccess()) {
                $this->jsonResponse(['error' => 'Unauthorized'], 403);
                return;
            }

            $success = $this->analyticsService->invalidateDashboardCache();

            // Audit log
            Logger::info('ADMIN: Dashboard cache refreshed (granular)', [
                'admin_user_id' => $_SESSION['admin_user_id'] ?? null,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'success' => $success,
            ]);

            $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'Dashboard aggiornata!' : 'Errore aggiornamento',
            ]);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to refresh dashboard', [
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse(['error' => 'Errore aggiornamento'], 500);
        }
    }

    /**
     * 🗑️ ENTERPRISE: Clear analytics cache (admin utility)
     *
     * WARNING: Clears ALL cache including timeline data.
     * Use refreshDashboard() for granular invalidation.
     *
     * @return void JSON response
     */
    public function clearCache(): void
    {
        try {
            // SECURITY: Validate admin access
            if (!$this->validateAdminAccess()) {
                $this->jsonResponse(['error' => 'Unauthorized'], 403);
                return;
            }

            $success = $this->analyticsService->clearCache();

            // Audit log
            Logger::security('warning', 'ADMIN: Cleared account deletions analytics cache (FULL)', [
                'admin_user_id' => $_SESSION['admin_user_id'] ?? null,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'success' => $success,
            ]);

            $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'Cache cleared successfully' : 'Failed to clear cache',
            ]);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to clear cache', [
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse(['error' => 'Failed to clear cache'], 500);
        }
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Validate admin access using AdminSecurityService
     */
    private function validateAdminAccess(): bool
    {
        try {
            // 🚀 ENTERPRISE GALAXY: Check admin session (populated by admin.php)
            // admin.php sets: admin_user_id, admin_role, admin_email, admin_full_name
            if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_role'])) {
                // LOG: Access denied - No valid admin session
                Logger::security('warning', 'ADMIN: Access denied to deletions dashboard - No valid admin session', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'uri' => $_SERVER['REQUEST_URI'] ?? '/',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
                    'reason' => 'Missing admin session (admin_user_id or admin_role)',
                    'session_exists' => !empty($_SESSION),
                    'has_admin_user_id' => isset($_SESSION['admin_user_id']),
                    'has_admin_role' => isset($_SESSION['admin_role']),
                ]);

                return false;
            }

            // ENTERPRISE GALAXY: Role hierarchy - super_admin has all admin permissions
            $allowedRoles = ['admin', 'super_admin'];
            if (!in_array($_SESSION['admin_role'], $allowedRoles, true)) {
                // LOG: Access denied - Role mismatch
                Logger::security('warning', 'ADMIN: Access denied to deletions dashboard - Invalid role', [
                    'admin_user_id' => $_SESSION['admin_user_id'],
                    'admin_email' => $_SESSION['admin_email'] ?? 'unknown',
                    'actual_role' => $_SESSION['admin_role'],
                    'allowed_roles' => $allowedRoles,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'uri' => $_SERVER['REQUEST_URI'] ?? '/',
                ]);

                return false;
            }

            return true;

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to validate admin access', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Render view with layout
     */
    private function renderView(string $view, array $data = []): void
    {
        extract($data);

        $viewPath = __DIR__ . '/../Views/' . $view . '.php';

        if (!file_exists($viewPath)) {
            Logger::error('ADMIN: View not found', [
                'view' => $view,
                'path' => $viewPath,
            ]);

            $this->errorPage('View Not Found', "View file not found: {$view}");
            return;
        }

        require $viewPath;
    }

    /**
     * JSON response helper
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Unauthorized access page
     */
    private function unauthorized(): void
    {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1>';
        echo '<p>You do not have permission to access this page.</p>';
        exit;
    }

    /**
     * Error page
     */
    private function errorPage(string $title, string $message): void
    {
        http_response_code(500);
        echo '<h1>' . htmlspecialchars($title) . '</h1>';
        echo '<p>' . htmlspecialchars($message) . '</p>';
        exit;
    }

    /**
     * Mask IP address for privacy
     */
    private function maskIpAddress(string $ip): string
    {
        if (empty($ip)) {
            return 'N/A';
        }

        $parts = explode('.', $ip);

        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.XXX.XXX';
        }

        // IPv6 or invalid
        return substr($ip, 0, 8) . '...';
    }
}
