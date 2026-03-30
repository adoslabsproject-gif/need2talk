<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\AdminEmailQueue;
use Need2Talk\Services\Logger;
use Need2Talk\Services\NewsletterWorkerManager;

/**
 * Newsletter Worker API Controller
 *
 * ENTERPRISE GALAXY: Handles newsletter worker control and monitoring from admin dashboard
 * Uses NewsletterWorkerManager service for unified worker management
 *
 * Features:
 * - Start/stop newsletter workers via NewsletterWorkerManager (Docker/systemd aware)
 * - Real-time monitoring output
 * - Performance testing
 * - Health monitoring
 *
 * @package Need2Talk\Controllers
 */
class NewsletterWorkerApiController extends BaseController
{
    private NewsletterWorkerManager $workerManager;
    private string $projectRoot;

    public function __construct()
    {
        parent::__construct();
        $this->workerManager = new NewsletterWorkerManager();
        $this->projectRoot = env('PROJECT_ROOT', '/var/www/html');
    }

    /**
     * API Endpoint: Start admin email workers
     * POST /api/worker/start
     */
    public function startWorker(): void
    {
        $this->disableHttpCache();

        try {
            // Get admin info for security audit
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            if (!$adminId || !$adminEmail) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 401);

                return;
            }

            // Get number of workers to start (default: 2)
            $numWorkers = (int) ($_POST['num_workers'] ?? 2);
            $numWorkers = max(1, min(10, $numWorkers)); // 1-10 workers

            // Start workers using AdminWorkerManager
            $result = $this->workerManager->startWorkers($numWorkers);

            // Security audit log
            Logger::security('warn', 'ADMIN: Email workers started from dashboard', [
                'admin_id' => $adminId,
                'admin_email' => $adminEmail,
                'requested_workers' => $numWorkers,
                'started_workers' => $result['started'] ?? 0,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => $result['success'],
                'message' => $result['success']
                    ? "Started {$result['started']} worker(s) successfully"
                    : ($result['error'] ?? 'Failed to start workers'),
                'workers_requested' => $numWorkers,
                'workers_started' => $result['started'] ?? 0,
                'pids' => $result['pids'] ?? [],
                'output' => $result['output'] ?? '',
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminWorkerController: Failed to start workers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed to start workers: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API Endpoint: Stop admin email workers
     * POST /api/worker/stop
     */
    public function stopWorker(): void
    {
        $this->disableHttpCache();

        try {
            // Get admin info for security audit
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            if (!$adminId || !$adminEmail) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 401);

                return;
            }

            // Stop workers using AdminWorkerManager
            $result = $this->workerManager->stopWorkers(false);

            // Security audit log
            Logger::security('warn', 'ADMIN: Email workers stopped from dashboard', [
                'admin_id' => $adminId,
                'admin_email' => $adminEmail,
                'stopped_count' => $result['stopped'] ?? 0,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => $result['success'],
                'message' => 'Workers stopped successfully',
                'stopped' => $result['stopped'] ?? 0,
                'output' => $result['output'] ?? '',
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminWorkerController: Failed to stop workers', [
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed to stop workers: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API Endpoint: Stop workers and clean logs
     * POST /api/worker/stop-clean
     */
    public function stopAndClean(): void
    {
        $this->disableHttpCache();

        try {
            // Get admin info
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            if (!$adminId || !$adminEmail) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 401);

                return;
            }

            // Stop workers and clean logs using AdminWorkerManager
            $result = $this->workerManager->stopWorkers(true);

            // Security audit log
            Logger::security('warn', 'ADMIN: Workers stopped and logs cleaned', [
                'admin_id' => $adminId,
                'admin_email' => $adminEmail,
                'stopped_pids' => $result['stopped'] ?? 0,
                'cleaned_logs' => $result['cleaned_logs'] ?? 0,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => $result['success'],
                'message' => 'Workers stopped and logs cleaned',
                'stopped_pids' => $result['stopped'] ?? 0,
                'cleaned_logs' => $result['cleaned_logs'] ?? 0,
                'output' => $result['output'] ?? '',
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminWorkerController: Failed to stop and clean', [
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API Endpoint: Get worker status
     * GET /api/worker/status
     */
    public function getStatus(): void
    {
        $this->disableHttpCache();

        try {
            // Get status from AdminWorkerManager
            $status = $this->workerManager->getStatus();

            if (!$status['success']) {
                $this->json(['success' => false, 'message' => $status['error'] ?? 'Failed to get status'], 500);

                return;
            }

            $this->json([
                'success' => true,
                'running' => $status['running'],
                'worker_count' => $status['worker_count'],
                'queue' => $status['queue'],
                'environment' => $status['environment'],
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminWorkerController: Failed to get status', [
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed to get status: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API Endpoint: Performance test
     * POST /api/worker/performance-test
     */
    public function performanceTest(): void
    {
        $this->disableHttpCache();

        try {
            // Get admin info
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            if (!$adminId || !$adminEmail) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 401);

                return;
            }

            $numTestEmails = (int) ($_POST['num_emails'] ?? 10);
            $numTestEmails = max(1, min(100, $numTestEmails)); // 1-100 emails

            // Queue test emails
            $queue = new AdminEmailQueue();
            $startTime = microtime(true);
            $queued = 0;

            for ($i = 1; $i <= $numTestEmails; $i++) {
                $jobId = $queue->enqueue([
                    'email_type' => 'admin_notification',
                    'admin_id' => $adminId,
                    'recipient_email' => "test{$i}@example.com",
                    'subject' => "Performance Test #{$i}",
                    'template' => 'admin_bulk_email',
                    'template_data' => [
                        'message' => "This is performance test email #{$i}",
                        'admin_email' => $adminEmail,
                    ],
                    'priority' => 'low',
                    'idempotency_key' => 'perf_test_' . time() . '_' . $i,
                ]);

                if ($jobId !== false) {
                    $queued++;
                }
            }

            $endTime = microtime(true);
            $totalTime = round(($endTime - $startTime) * 1000, 2); // ms
            $avgTime = $queued > 0 ? round($totalTime / $queued, 2) : 0;
            $emailsPerSec = $totalTime > 0 ? round($queued / ($totalTime / 1000), 2) : 0;

            // Format output for monitor display
            $outputLines = [];
            $outputLines[] = "⚡ Email Queue Performance Test\n";
            $outputLines[] = "═══════════════════════════════════════════════════════\n";
            $outputLines[] = "Test Configuration:";
            $outputLines[] = "  📧 Test Emails Requested: {$numTestEmails}";
            $outputLines[] = "  ✅ Successfully Queued:   {$queued}";
            $outputLines[] = "  🎯 Priority Level:        Low (test)";
            $outputLines[] = "";
            $outputLines[] = "Performance Metrics:";
            $outputLines[] = "  ⏱️  Total Time:           {$totalTime} ms";
            $outputLines[] = "  📊 Average per Email:     {$avgTime} ms";
            $outputLines[] = "  🚀 Throughput:            {$emailsPerSec} emails/sec";
            $outputLines[] = "";
            $outputLines[] = "Queue Status:";
            $outputLines[] = "  📬 Test emails queued in Redis DB 4";
            $outputLines[] = "  🔄 Workers will process automatically";
            $outputLines[] = "  📋 Check monitor below for processing activity";
            $outputLines[] = "";
            $outputLines[] = "═══════════════════════════════════════════════════════";
            $outputLines[] = "✨ Performance test completed successfully!";
            $outputLines[] = "💡 Tip: Start workers if not running to process test queue";

            $formattedOutput = implode("\n", $outputLines);

            $this->json([
                'success' => true,
                'message' => "Queued {$queued} test emails",
                'queued' => $queued,
                'total_time_ms' => $totalTime,
                'avg_time_ms' => $avgTime,
                'emails_per_second' => $emailsPerSec,
                'output' => $formattedOutput,
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminWorkerController: Performance test failed', [
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'message' => 'Performance test failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API Endpoint: Get workers health status
     * GET /api/worker/health
     *
     * ENTERPRISE GALAXY: Comprehensive worker health monitoring
     */
    public function getHealth(): void
    {
        $this->disableHttpCache();

        try {
            // Get status from AdminWorkerManager
            $status = $this->workerManager->getStatus();

            if (!$status['success']) {
                $this->json(['success' => false, 'message' => $status['error'] ?? 'Failed to get health status'], 500);

                return;
            }

            $workerCount = $status['worker_count'];
            $queueStats = $status['queue'];

            // Get recent activity from database
            try {
                $pdo = $this->getFreshPDO();
                $stmt = $pdo->prepare("
                    SELECT email_type, status, recipient_email, subject,
                           processing_time_ms, created_at
                    FROM admin_email_audit
                    ORDER BY created_at DESC
                    LIMIT 10
                ");
                $stmt->execute();
                $recentActivity = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                $recentActivity = [];
            }

            // Get recent log entries
            $logFile = "{$this->projectRoot}/storage/logs/admin-email-worker-1.log";
            $logOutput = [];
            if (file_exists($logFile)) {
                $logOutput = array_slice(file($logFile, FILE_IGNORE_NEW_LINES), -10);
            }

            // Calculate health score
            $healthScore = 100;
            $healthIssues = [];

            if ($workerCount === 0) {
                $healthScore -= 50;
                $healthIssues[] = 'No workers running';
            }

            if (isset($queueStats['failed']) && $queueStats['failed'] > 10) {
                $healthScore -= 20;
                $healthIssues[] = 'High failed job count: ' . $queueStats['failed'];
            }

            if (isset($queueStats['total_queued']) && $queueStats['total_queued'] > 100) {
                $healthScore -= 15;
                $healthIssues[] = 'Large queue backlog: ' . $queueStats['total_queued'];
            }

            $healthStatus = match(true) {
                $healthScore >= 90 => 'excellent',
                $healthScore >= 70 => 'good',
                $healthScore >= 50 => 'degraded',
                default => 'critical'
            };

            $this->json([
                'success' => true,
                'health' => [
                    'status' => $healthStatus,
                    'score' => max(0, $healthScore),
                    'issues' => $healthIssues,
                ],
                'workers' => [
                    'running' => $workerCount,
                    'details' => [],
                ],
                'queue' => $queueStats,
                'recent_activity' => $recentActivity,
                'recent_logs' => $logOutput,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminWorkerController: Failed to get health status', [
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed to get health status: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API Endpoint: Get monitor output
     * GET /api/worker/monitor
     */
    public function getMonitorOutput(): void
    {
        $this->disableHttpCache();

        try {
            $lines = (int) ($_GET['lines'] ?? 50);
            $lines = max(10, min(500, $lines)); // 10-500 lines

            // Get last N lines from worker log
            $logFile = "{$this->projectRoot}/storage/logs/admin-email-worker-1.log";
            $logOutput = [];

            if (file_exists($logFile)) {
                $allLines = file($logFile, FILE_IGNORE_NEW_LINES);
                $logOutput = array_slice($allLines, -$lines);
            }

            // Get recent activity from database
            $pdo = $this->getFreshPDO();
            $stmt = $pdo->prepare("
                SELECT email_type, status, recipient_email, processing_time_ms, created_at
                FROM admin_email_audit
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            $recentActivity = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get queue stats
            $status = $this->workerManager->getStatus();
            $queueStats = $status['queue'] ?? [];

            $this->json([
                'success' => true,
                'log_output' => $logOutput,
                'recent_activity' => $recentActivity,
                'queue_stats' => $queueStats,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminWorkerController: Failed to get monitor output', [
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed to get monitor output: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create fresh PDO connection
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
     * Disable HTTP caching
     */
    private function disableHttpCache(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
