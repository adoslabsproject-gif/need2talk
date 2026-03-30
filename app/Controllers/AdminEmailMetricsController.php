<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * Admin Email Metrics Controller
 *
 * ENTERPRISE: Handles ALL email metrics visualization and export for admin panel
 * Reads from previously "zombie" tables to provide comprehensive email analytics
 * - email_verification_metrics (real-time verification events)
 * - email_metrics_hourly (hourly aggregates)
 * - email_metrics_daily (daily aggregates)
 * - password_reset_metrics (password reset events)
 * - email_idempotency_log (duplicate prevention tracking)
 *
 * PERFORMANCE OPTIMIZED: Uses fresh PDO connections for real-time data
 */
class AdminEmailMetricsController extends BaseController
{
    /**
     * Get all data for Email Metrics admin page
     * Returns data array for AdminController to render
     *
     * ENTERPRISE: Real-time email metrics monitoring with no caching
     */
    public function getPageData(): array
    {
        // ENTERPRISE: No-cache headers for real-time data
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // Get dashboard stats
        $dashboardStats = $this->getEmailMetricsDashboard();

        // Return data for rendering
        return [
            'title' => 'Email Metrics & Analytics',
            'dashboard' => $dashboardStats,
        ];
    }

    /**
     * ENTERPRISE: Get comprehensive email metrics dashboard
     * Aggregates data from all email-related tables
     */
    private function getEmailMetricsDashboard(): array
    {
        try {
            $pdo = $this->getFreshPDO();

            // Get verification metrics summary
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as total_events,
                    SUM(CASE WHEN status = 'sent_successfully' THEN 1 ELSE 0 END) as sent_count,
                    SUM(CASE WHEN status = 'send_failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN status = 'token_verified' THEN 1 ELSE 0 END) as verified_count,
                    AVG(CASE WHEN processing_time_ms IS NOT NULL THEN processing_time_ms ELSE NULL END) as avg_processing_time,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT worker_id) as active_workers
                FROM email_verification_metrics
                WHERE created_at >= NOW() - INTERVAL '24 hours'
            ");
            $verificationStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Get password reset metrics summary
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as total_events,
                    SUM(CASE WHEN action = 'sent_successfully' THEN 1 ELSE 0 END) as sent_count,
                    SUM(CASE WHEN action = 'send_failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN action = 'token_verified' THEN 1 ELSE 0 END) as verified_count,
                    AVG(CASE WHEN processing_time_ms IS NOT NULL THEN processing_time_ms ELSE NULL END) as avg_processing_time,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT worker_id) as active_workers
                FROM password_reset_metrics
                WHERE created_at >= NOW() - INTERVAL '24 hours'
            ");
            $passwordResetStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Get hourly metrics (last 24 hours)
            $stmt = $pdo->query("
                SELECT
                    email_type,
                    action,
                    SUM(count) as total_count,
                    AVG(avg_processing_time) as avg_time
                FROM email_metrics_hourly
                WHERE hour >= NOW() - INTERVAL '24 hours'
                GROUP BY email_type, action
                ORDER BY total_count DESC
            ");
            $hourlyMetrics = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get daily metrics (last 30 days)
            $stmt = $pdo->query("
                SELECT
                    email_type,
                    action,
                    SUM(count) as total_count,
                    AVG(avg_processing_time) as avg_time
                FROM email_metrics_daily
                WHERE day >= NOW() - INTERVAL '30 days'
                GROUP BY email_type, action
                ORDER BY total_count DESC
            ");
            $dailyMetrics = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get idempotency stats
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as total_logs,
                    COUNT(DISTINCT user_uuid) as unique_users,
                    COUNT(DISTINCT email_hash) as unique_emails,
                    email_type,
                    COUNT(*) as count_by_type
                FROM email_idempotency_log
                WHERE created_at >= NOW() - INTERVAL '24 hours'
                GROUP BY email_type
            ");
            $idempotencyStats = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get total idempotency count
            $stmt = $pdo->query("
                SELECT COUNT(*) as total
                FROM email_idempotency_log
                WHERE created_at >= NOW() - INTERVAL '24 hours'
            ");
            $totalIdempotency = (int) $stmt->fetchColumn();

            return [
                'verification' => $verificationStats,
                'password_reset' => $passwordResetStats,
                'hourly' => $hourlyMetrics,
                'daily' => $dailyMetrics,
                'idempotency' => $idempotencyStats,
                'total_idempotency' => $totalIdempotency,
                'timestamp' => date('c'),
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get email metrics dashboard', [
                'error' => $e->getMessage(),
            ]);

            return [
                'verification' => [],
                'password_reset' => [],
                'hourly' => [],
                'daily' => [],
                'idempotency' => [],
                'total_idempotency' => 0,
            ];
        }
    }

    /**
     * API Endpoint: Get email verification metrics with pagination
     * AJAX endpoint for real-time data refresh
     */
    public function getVerificationMetrics(): void
    {
        $this->disableHttpCache();

        try {
            $limit = (int) ($_GET['limit'] ?? 100);
            $page = (int) ($_GET['page'] ?? 1);
            $limit = in_array($limit, [50, 100, 200]) ? $limit : 100;
            $page = max(1, $page);
            $offset = ($page - 1) * $limit;

            $pdo = $this->getFreshPDO();

            // Get total count
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM email_verification_metrics');
            $total = (int) $stmt->fetchColumn();

            // Get metrics
            $stmt = $pdo->prepare("
                SELECT
                    id, user_id, status, created_at, created_date, created_hour,
                    queue_time_ms, processing_time_ms, worker_id, retry_count,
                    error_code, error_message, redis_l1_status, database_pool_id,
                    server_load_avg, memory_usage_mb
                FROM email_verification_metrics
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $metrics = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'metrics' => $metrics,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get verification metrics', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Could not retrieve metrics'], 500);
        }
    }

    /**
     * API Endpoint: Get password reset metrics with pagination
     */
    public function getPasswordResetMetrics(): void
    {
        $this->disableHttpCache();

        try {
            $limit = (int) ($_GET['limit'] ?? 100);
            $page = (int) ($_GET['page'] ?? 1);
            $limit = in_array($limit, [50, 100, 200]) ? $limit : 100;
            $page = max(1, $page);
            $offset = ($page - 1) * $limit;

            $pdo = $this->getFreshPDO();

            // Get total count
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM password_reset_metrics');
            $total = (int) $stmt->fetchColumn();

            // Get metrics
            $stmt = $pdo->prepare("
                SELECT
                    id, user_id, email, action, ip_address, user_agent,
                    token_hash, error_code, error_message, worker_id, retry_count,
                    queue_time_ms, processing_time_ms, redis_l1_status,
                    database_pool_id, server_load_avg, memory_usage_mb,
                    metadata, created_at, created_date, created_hour
                FROM password_reset_metrics
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $metrics = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'metrics' => $metrics,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get password reset metrics', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Could not retrieve metrics'], 500);
        }
    }

    /**
     * API Endpoint: Get hourly aggregated metrics
     */
    public function getHourlyMetrics(): void
    {
        $this->disableHttpCache();

        try {
            $hours = (int) ($_GET['hours'] ?? 168); // Default 7 days instead of 24h
            $hours = min(720, max(1, $hours)); // Max 30 days

            $pdo = $this->getFreshPDO();

            $stmt = $pdo->prepare("
                SELECT
                    id, hour, email_type, action, count, total_size,
                    avg_processing_time, created_at, updated_at
                FROM email_metrics_hourly
                WHERE hour >= NOW() - INTERVAL '1 hour' * ?
                ORDER BY hour DESC
                LIMIT 1000
            ");
            $stmt->execute([$hours]);
            $metrics = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'metrics' => $metrics,
                'hours' => $hours,
                'count' => count($metrics),
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get hourly metrics', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Could not retrieve metrics', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * API Endpoint: Get daily aggregated metrics
     */
    public function getDailyMetrics(): void
    {
        $this->disableHttpCache();

        try {
            $days = (int) ($_GET['days'] ?? 30);
            $days = min(365, max(1, $days)); // Max 1 year

            $pdo = $this->getFreshPDO();

            $stmt = $pdo->prepare("
                SELECT
                    id, day, email_type, action, count, total_size,
                    avg_processing_time, created_at, updated_at
                FROM email_metrics_daily
                WHERE day >= NOW() - INTERVAL '1 day' * ?
                ORDER BY day DESC
            ");
            $stmt->execute([$days]);
            $metrics = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'metrics' => $metrics,
                'days' => $days,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get daily metrics', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Could not retrieve metrics'], 500);
        }
    }

    /**
     * API Endpoint: Get idempotency log with pagination
     */
    public function getIdempotencyLog(): void
    {
        $this->disableHttpCache();

        try {
            $limit = (int) ($_GET['limit'] ?? 100);
            $page = (int) ($_GET['page'] ?? 1);
            $limit = in_array($limit, [50, 100, 200]) ? $limit : 100;
            $page = max(1, $page);
            $offset = ($page - 1) * $limit;

            $pdo = $this->getFreshPDO();

            // Get total count
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM email_idempotency_log');
            $total = (int) $stmt->fetchColumn();

            // Get logs
            $stmt = $pdo->prepare("
                SELECT
                    id, idempotency_key, message_id, user_uuid, email_hash,
                    email_type, worker_id, created_at, updated_at
                FROM email_idempotency_log
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'logs' => $logs,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get idempotency log', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Could not retrieve logs'], 500);
        }
    }

    /**
     * API Endpoint: Export email metrics to CSV
     * Supports all metric types with customizable date range
     */
    public function exportMetrics(): void
    {
        try {
            $type = $_GET['type'] ?? 'verification';
            $days = (int) ($_GET['days'] ?? 30);
            $days = min(365, max(1, $days));

            $pdo = $this->getFreshPDO();

            $filename = "email_metrics_{$type}_" . date('Y-m-d_His') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');

            switch ($type) {
                case 'verification':
                    fputcsv($output, ['ID', 'User ID', 'Status', 'Queue Time (ms)', 'Processing Time (ms)',
                                      'Worker ID', 'Retry Count', 'Error Code', 'Error Message', 'Redis L1 Status',
                                      'Server Load', 'Memory (MB)', 'Created At']);

                    $stmt = $pdo->prepare("
                        SELECT id, user_id, status, queue_time_ms, processing_time_ms, worker_id,
                               retry_count, error_code, error_message, redis_l1_status,
                               server_load_avg, memory_usage_mb, created_at
                        FROM email_verification_metrics
                        WHERE created_at >= NOW() - INTERVAL '1 day' * ?
                        ORDER BY id DESC
                    ");
                    $stmt->execute([$days]);
                    break;

                case 'password_reset':
                    fputcsv($output, ['ID', 'User ID', 'Email', 'Action', 'IP Address', 'Queue Time (ms)',
                                      'Processing Time (ms)', 'Worker ID', 'Retry Count', 'Error Code',
                                      'Error Message', 'Redis L1 Status', 'Server Load', 'Memory (MB)', 'Created At']);

                    $stmt = $pdo->prepare("
                        SELECT id, user_id, email, action, ip_address, queue_time_ms, processing_time_ms,
                               worker_id, retry_count, error_code, error_message, redis_l1_status,
                               server_load_avg, memory_usage_mb, created_at
                        FROM password_reset_metrics
                        WHERE created_at >= NOW() - INTERVAL '1 day' * ?
                        ORDER BY id DESC
                    ");
                    $stmt->execute([$days]);
                    break;

                case 'hourly':
                    fputcsv($output, ['ID', 'Hour', 'Email Type', 'Action', 'Count', 'Total Size (bytes)',
                                      'Avg Processing Time (ms)', 'Created At', 'Updated At']);

                    $stmt = $pdo->prepare("
                        SELECT id, hour, email_type, action, count, total_size, avg_processing_time,
                               created_at, updated_at
                        FROM email_metrics_hourly
                        WHERE hour >= NOW() - INTERVAL '1 day' * ?
                        ORDER BY hour DESC
                    ");
                    $stmt->execute([$days]);
                    break;

                case 'daily':
                    fputcsv($output, ['ID', 'Day', 'Email Type', 'Action', 'Count', 'Total Size (bytes)',
                                      'Avg Processing Time (ms)', 'Created At', 'Updated At']);

                    $stmt = $pdo->prepare("
                        SELECT id, day, email_type, action, count, total_size, avg_processing_time,
                               created_at, updated_at
                        FROM email_metrics_daily
                        WHERE day >= NOW() - INTERVAL '1 day' * ?
                        ORDER BY day DESC
                    ");
                    $stmt->execute([$days]);
                    break;

                case 'idempotency':
                    fputcsv($output, ['ID', 'Idempotency Key', 'Message ID', 'User UUID', 'Email Hash',
                                      'Email Type', 'Worker ID', 'Created At', 'Updated At']);

                    $stmt = $pdo->prepare("
                        SELECT id, idempotency_key, message_id, user_uuid, email_hash, email_type,
                               worker_id, created_at, updated_at
                        FROM email_idempotency_log
                        WHERE created_at >= NOW() - INTERVAL '1 day' * ?
                        ORDER BY id DESC
                    ");
                    $stmt->execute([$days]);
                    break;

                default:
                    throw new \Exception('Invalid export type');
            }

            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                fputcsv($output, $row);
            }

            fclose($output);
            exit;

        } catch (\Exception $e) {
            Logger::error('Failed to export email metrics', [
                'type' => $type ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            echo "Export failed: " . htmlspecialchars($e->getMessage());
            exit;
        }
    }

    /**
     * Create fresh PDO connection bypassing all cache layers
     * ENTERPRISE: Guarantees real-time data
     */
    private function getFreshPDO(): \PDO
    {
        $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') .
               ';dbname=' . env('DB_DATABASE', 'need2talk');

        return new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Disable HTTP caching for real-time data
     */
    private function disableHttpCache(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    // ============================================================================
    // SYSTEMD EMAIL VERIFICATION WORKER CONTROL (ENTERPRISE GALAXY AUTO-RESTART)
    // ============================================================================
    // NOTA: Questi sono per i verification workers (email-worker.php con systemd)
    // NON confondere con AdminEmailWorkerController.php (solo newsletter, manuale)

    /**
     * ENTERPRISE GALAXY: Call Docker Worker Controller (NEW - replaces Python Gateway)
     *
     * Docker PHP container → AdminEmailWorkerController → Docker commands
     * Manages need2talk_worker container (email verification workers)
     */
    private function callWorkerController(string $action, array $params = []): array
    {
        try {
            $controller = new AdminEmailWorkerController();

            return match($action) {
                'status' => $controller->getStatus(),
                'start' => $controller->start(),
                'stop' => $controller->stop(),
                'restart' => $controller->restart(),
                'enable' => $controller->enable(),
                'disable' => $controller->disable(),
                'logs' => $controller->getLogs($params['lines'] ?? 50),
                default => throw new \Exception("Unknown action: {$action}"),
            };

        } catch (\Exception $e) {
            Logger::error('WORKER: Controller call failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * DEPRECATED: Old Python Gateway method (kept for reference)
     * Use callWorkerController() instead
     */
    private function callGateway_DEPRECATED(string $action, array $params = []): array
    {
        return [
            'success' => false,
            'error' => 'Python Gateway removed - use Docker Worker Controller instead',
        ];
    }

    /**
     * API Endpoint: Get systemd email verification worker status
     * ENTERPRISE: Real-time status via Python gateway → systemd
     */
    public function getSystemdWorkerStatus(): void
    {
        $this->disableHttpCache();

        try {
            $status = $this->callWorkerController('status');

            // Add admin audit log
            Logger::security('info', 'ADMIN: Email worker status checked', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'status' => $status['status'] ?? 'unknown',
                'workers' => $status['workers'] ?? 0,
            ]);

            $this->json($status);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get systemd worker status', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Start systemd email verification workers
     */
    public function startSystemdWorkers(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->callWorkerController('start');

            Logger::security('warning', 'ADMIN: Email workers START via systemd', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'success' => $result['success'] ?? false,
            ]);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to start systemd workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Stop systemd email verification workers
     */
    public function stopSystemdWorkers(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->callWorkerController('stop');

            Logger::security('critical', 'ADMIN: Email workers STOP via systemd', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'success' => $result['success'] ?? false,
            ]);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to stop systemd workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Restart systemd email verification workers
     */
    public function restartSystemdWorkers(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->callWorkerController('restart');

            Logger::security('warning', 'ADMIN: Email workers RESTART via systemd', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'success' => $result['success'] ?? false,
            ]);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to restart systemd workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Enable systemd email verification workers auto-start
     */
    public function enableSystemdWorkers(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->callWorkerController('enable');

            Logger::security('warning', 'ADMIN: Email workers AUTO-START ENABLED', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'success' => $result['success'] ?? false,
            ]);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to enable systemd workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Disable systemd email verification workers auto-start
     */
    public function disableSystemdWorkers(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->callWorkerController('disable');

            Logger::security('critical', 'ADMIN: Email workers AUTO-START DISABLED', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'success' => $result['success'] ?? false,
            ]);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to disable systemd workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Get systemd logs for email verification workers
     */
    public function getSystemdWorkerLogs(): void
    {
        $this->disableHttpCache();

        try {
            $lines = (int) ($_GET['lines'] ?? 50);
            $lines = min(500, max(10, $lines)); // Limit 10-500 lines

            $result = $this->callWorkerController('logs', ['lines' => $lines]);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get systemd worker logs', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
