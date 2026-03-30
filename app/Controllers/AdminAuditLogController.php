<?php

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;

/**
 * ENTERPRISE: Admin Audit Log Controller
 * Dedicated controller for admin action audit logging and monitoring
 *
 * Features:
 * - Real-time admin action monitoring
 * - Advanced filtering (admin, action type, date range)
 * - JSON details viewer with syntax highlighting
 * - Export functionality (CSV/JSON)
 * - Pagination for large datasets
 */
class AdminAuditLogController
{
    /**
     * ENTERPRISE: Get database connection from pool
     */
    private function getDb()
    {
        return db_pdo();
    }

    /**
     * ENTERPRISE: Get page data for audit log view
     */
    public function getPageData(): array
    {
        // Get filter parameters
        $adminId = $_GET['admin_id'] ?? null;
        $action = $_GET['action'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 500); // Max 500
        $offset = (int)($_GET['offset'] ?? 0);

        // Get audit logs with filters
        $auditLogs = $this->getAuditLogs($adminId, $action, $dateFrom, $dateTo, $limit, $offset);

        // Get action types for filter dropdown
        $actionTypes = $this->getAuditActionTypes();

        // Get admin users for filter dropdown
        $adminUsers = $this->getAdminUsers();

        // Get statistics
        $stats = $this->getAuditStats();

        return [
            'title' => 'Admin Audit Log',
            'audit_logs' => $auditLogs['logs'],
            'total_count' => $auditLogs['total'],
            'action_types' => $actionTypes,
            'admin_users' => $adminUsers,
            'stats' => $stats,
            'filters' => [
                'admin_id' => $adminId,
                'action' => $action,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * ENTERPRISE: Get audit logs with filters
     */
    private function getAuditLogs(?string $adminId, ?string $action, ?string $dateFrom, ?string $dateTo, int $limit, int $offset): array
    {
        $db = $this->getDb();

        // Build WHERE conditions
        $where = [];
        $params = [];

        if ($adminId !== null && $adminId !== '') {
            $where[] = 'aal.admin_id = ?';
            $params[] = $adminId;
        }

        if ($action !== null && $action !== '') {
            $where[] = 'aal.action = ?';
            $params[] = $action;
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'aal.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'aal.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count
        $countSql = "
            SELECT COUNT(*) as total
            FROM admin_audit_log aal
            $whereClause
        ";

        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetch()['total'];

        // Get logs with admin user info
        $sql = "
            SELECT
                aal.id,
                aal.admin_id,
                aal.action,
                aal.details,
                aal.ip_address,
                aal.user_agent,
                aal.created_at,
                u.email as admin_email,
                u.name as admin_name
            FROM admin_audit_log aal
            LEFT JOIN users u ON aal.admin_id = u.id
            $whereClause
            ORDER BY aal.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Parse JSON details
        foreach ($logs as &$log) {
            if ($log['details']) {
                $log['details_parsed'] = json_decode($log['details'], true);
            } else {
                $log['details_parsed'] = null;
            }
        }

        return [
            'logs' => $logs,
            'total' => $total,
        ];
    }

    /**
     * ENTERPRISE: Get distinct action types for filter dropdown
     */
    private function getAuditActionTypes(): array
    {
        $db = $this->getDb();

        $sql = "
            SELECT DISTINCT action, COUNT(*) as count
            FROM admin_audit_log
            GROUP BY action
            ORDER BY count DESC, action ASC
        ";

        $stmt = $db->query($sql);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * ENTERPRISE: Get admin users for filter dropdown
     */
    private function getAdminUsers(): array
    {
        $db = $this->getDb();

        $sql = "
            SELECT DISTINCT
                u.id,
                u.email,
                u.name
            FROM admin_audit_log aal
            INNER JOIN users u ON aal.admin_id = u.id
            ORDER BY u.email ASC
        ";

        $stmt = $db->query($sql);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * ENTERPRISE: Get audit statistics
     */
    private function getAuditStats(): array
    {
        $db = $this->getDb();

        // Total actions
        $stmt = $db->query('SELECT COUNT(*) as total FROM admin_audit_log');
        $totalActions = (int) $stmt->fetch()['total'];

        // Actions today
        $stmt = $db->query('
            SELECT COUNT(*) as today
            FROM admin_audit_log
            WHERE created_at::DATE = CURRENT_DATE
        ');
        $actionsToday = (int) $stmt->fetch()['today'];

        // Actions last 24h
        $stmt = $db->query("
            SELECT COUNT(*) as last_24h
            FROM admin_audit_log
            WHERE created_at >= NOW() - INTERVAL '24 hours'
        ");
        $actions24h = (int) $stmt->fetch()['last_24h'];

        // Unique admins
        $stmt = $db->query('SELECT COUNT(DISTINCT admin_id) as unique_admins FROM admin_audit_log');
        $uniqueAdmins = (int) $stmt->fetch()['unique_admins'];

        // Most common actions (last 7 days)
        $stmt = $db->query("
            SELECT action, COUNT(*) as count
            FROM admin_audit_log
            WHERE created_at >= NOW() - INTERVAL '7 days'
            GROUP BY action
            ORDER BY count DESC
            LIMIT 5
        ");
        $topActions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Most active admins (last 7 days)
        $stmt = $db->query("
            SELECT
                aal.admin_id,
                u.email,
                u.name,
                COUNT(*) as action_count
            FROM admin_audit_log aal
            LEFT JOIN users u ON aal.admin_id = u.id
            WHERE aal.created_at >= NOW() - INTERVAL '7 days'
            GROUP BY aal.admin_id, u.email, u.name
            ORDER BY action_count DESC
            LIMIT 5
        ");
        $topAdmins = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'total_actions' => $totalActions,
            'actions_today' => $actionsToday,
            'actions_24h' => $actions24h,
            'unique_admins' => $uniqueAdmins,
            'top_actions' => $topActions,
            'top_admins' => $topAdmins,
        ];
    }

    /**
     * ENTERPRISE API: Get audit logs for AJAX (JSON response)
     */
    public function getDatabaseLogs(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        try {
            $adminId = $_GET['admin_id'] ?? null;
            $action = $_GET['action'] ?? null;
            $dateFrom = $_GET['date_from'] ?? null;
            $dateTo = $_GET['date_to'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 50), 500);
            $offset = (int)($_GET['offset'] ?? 0);

            $result = $this->getAuditLogs($adminId, $action, $dateFrom, $dateTo, $limit, $offset);

            echo json_encode([
                'success' => true,
                'data' => $result['logs'],
                'total' => $result['total'],
                'offset' => $offset,
                'limit' => $limit,
            ]);
        } catch (\Exception $e) {
            Logger::error('DEFAULT: Failed to fetch audit logs', [
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to fetch audit logs',
            ]);
        }
    }

    /**
     * ENTERPRISE API: Export audit logs (CSV/JSON)
     */
    public function exportAuditLogs(): void
    {
        $format = $_GET['format'] ?? 'csv';
        $adminId = $_GET['admin_id'] ?? null;
        $action = $_GET['action'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        try {
            // Get all matching logs (no limit for export)
            $result = $this->getAuditLogs($adminId, $action, $dateFrom, $dateTo, 10000, 0);

            if ($format === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_H-i-s') . '.json"');
                echo json_encode($result['logs'], JSON_PRETTY_PRINT);
            } else {
                // CSV export
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_H-i-s') . '.csv"');

                $output = fopen('php://output', 'w');

                // Header
                fputcsv($output, ['ID', 'Admin ID', 'Admin Email', 'Action', 'Details', 'IP Address', 'User Agent', 'Created At']);

                // Data
                foreach ($result['logs'] as $log) {
                    fputcsv($output, [
                        $log['id'],
                        $log['admin_id'],
                        $log['admin_email'] ?? 'N/A',
                        $log['action'],
                        $log['details'] ?? '',
                        $log['ip_address'] ?? '',
                        $log['user_agent'] ?? '',
                        $log['created_at'],
                    ]);
                }

                fclose($output);
            }

            exit;
        } catch (\Exception $e) {
            Logger::error('DEFAULT: Failed to export audit logs', [
                'error' => $e->getMessage(),
                'format' => $format,
            ]);

            http_response_code(500);
            echo 'Failed to export audit logs';
            exit;
        }
    }
}
