<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\CronManager;
use Need2Talk\Services\Logger;

/**
 * 🚀 ENTERPRISE GALAXY: Admin Cron Management Controller
 *
 * Web-based cron job management interface for enterprise applications
 * Provides complete control over scheduled tasks without touching crontab
 *
 * FEATURES:
 * - View all registered jobs with status
 * - Enable/disable jobs
 * - Execute jobs manually (Run Now)
 * - View execution history
 * - Monitor job performance
 *
 * SECURITY: Admin-only access, no arbitrary command execution
 * SCALABILITY: Handles millions of users with Redis locking
 *
 * @package Need2Talk\Controllers
 * @version 1.0.0 Galaxy Edition
 */
class AdminCronController extends BaseController
{
    private CronManager $cronManager;

    public function __construct()
    {
        parent::__construct();
        $this->cronManager = CronManager::getInstance();
    }

    /**
     * Main cron management page
     * Returns data for AdminController to render
     */
    public function getPageData(): array
    {
        $jobs = $this->cronManager->getAllJobs();

        // Enrich jobs with recent execution status
        foreach ($jobs as &$job) {
            $recentExecutions = $this->cronManager->getJobHistory($job['name'], 5);
            $job['recent_executions'] = $recentExecutions;
            $job['last_execution'] = $recentExecutions[0] ?? null;

            // Calculate success rate
            if ($job['total_runs'] > 0) {
                $job['success_rate'] = round(($job['successful_runs'] / $job['total_runs']) * 100, 2);
            } else {
                $job['success_rate'] = 0;
            }

            // Determine health status
            if ($job['last_execution']) {
                if ($job['last_execution']['success']) {
                    $job['health_status'] = 'healthy';
                } else {
                    $job['health_status'] = 'error';
                }
            } else {
                $job['health_status'] = $job['enabled'] ? 'pending' : 'disabled';
            }
        }

        return [
            'title' => '⚙️ Cron Jobs Management',
            'jobs' => $jobs,
            'stats' => [
                'total' => count($jobs),
                'enabled' => count(array_filter($jobs, fn ($j) => $j['enabled'])),
                'disabled' => count(array_filter($jobs, fn ($j) => !$j['enabled'])),
                'healthy' => count(array_filter($jobs, fn ($j) => $j['health_status'] === 'healthy')),
                'error' => count(array_filter($jobs, fn ($j) => $j['health_status'] === 'error')),
            ],
        ];
    }

    /**
     * API: Get all jobs (AJAX refresh)
     */
    public function getAllJobs(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        try {
            // ENTERPRISE NUCLEAR OPTION: Create completely fresh PDO connection bypassing ALL cache layers
            // This ensures we ALWAYS get real-time data from database, no stale cache
            // Same approach as JS Errors page - admin panel needs 100% fresh data
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);

            // Query cron jobs with aggregated stats (LEFT JOIN for atomic query)
            $stmt = $pdo->prepare("
                SELECT
                    j.*,
                    COUNT(e.id) as total_runs,
                    SUM(CASE WHEN e.success = TRUE THEN 1 ELSE 0 END) as successful_runs,
                    AVG(CASE WHEN e.success = TRUE THEN e.execution_time ELSE NULL END) as avg_execution_time
                FROM cron_jobs j
                LEFT JOIN cron_executions e ON e.job_id = j.id
                GROUP BY j.id
                ORDER BY j.name ASC
            ");
            $stmt->execute();
            $jobs = $stmt->fetchAll();

            // Enrich jobs with same data as getPageData()
            foreach ($jobs as &$job) {
                // ENTERPRISE NUCLEAR: Get recent executions with fresh PDO connection
                $stmt = $pdo->prepare("
                    SELECT e.*
                    FROM cron_executions e
                    JOIN cron_jobs j ON e.job_id = j.id
                    WHERE j.name = ?
                    ORDER BY e.executed_at DESC
                    LIMIT 5
                ");
                $stmt->execute([$job['name']]);
                $recentExecutions = $stmt->fetchAll();

                $job['recent_executions'] = $recentExecutions;
                $job['last_execution'] = $recentExecutions[0] ?? null;

                // Calculate success rate
                if ($job['total_runs'] > 0) {
                    $job['success_rate'] = round(($job['successful_runs'] / $job['total_runs']) * 100, 2);
                } else {
                    $job['success_rate'] = 0;
                }

                // Determine health status
                if ($job['last_execution']) {
                    if ($job['last_execution']['success']) {
                        $job['health_status'] = 'healthy';
                    } else {
                        $job['health_status'] = 'error';
                    }
                } else {
                    $job['health_status'] = $job['enabled'] ? 'pending' : 'disabled';
                }
            }

            $this->json([
                'success' => true,
                'jobs' => $jobs,
                'timestamp' => date('c'),
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get cron jobs', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'message' => 'Failed to load jobs',
            ], 500);
        }
    }

    /**
     * API: Enable/Disable a job
     */
    public function toggleJob(): void
    {
        header('Content-Type: application/json');

        try {
            $jobName = $_POST['job_name'] ?? null;
            $enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : null;

            if (!$jobName || $enabled === null) {
                $this->json([
                    'success' => false,
                    'message' => 'Missing required parameters',
                ], 400);

                return;
            }

            $result = $this->cronManager->setJobEnabled($jobName, $enabled);

            if ($result) {
                Logger::info('Cron job toggled', [
                    'job' => $jobName,
                    'enabled' => $enabled,
                    'admin_user' => $_SESSION['admin_user']['username'] ?? 'unknown',
                ]);

                $this->json([
                    'success' => true,
                    'message' => 'Job ' . ($enabled ? 'enabled' : 'disabled') . ' successfully',
                ]);
            } else {
                $this->json([
                    'success' => false,
                    'message' => 'Failed to update job status',
                ], 500);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to toggle cron job', [
                'error' => $e->getMessage(),
                'job' => $jobName ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'message' => 'Error updating job status',
            ], 500);
        }
    }

    /**
     * API: Execute a job immediately (Run Now)
     *
     * ENTERPRISE GALAXY: Direct script execution (like AdminWorkerController)
     * Launches .sh scripts directly from local filesystem with proper PATH
     */
    public function executeJob(): void
    {
        // ENTERPRISE DEBUG: Clear any output buffers before sending JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Note: Don't set Content-Type here - BaseController->json() will do it
        header('Cache-Control: no-cache, no-store, must-revalidate');

        try {
            // ENTERPRISE DEBUG: Log entry to method
            error_log('[CRON EXECUTE] Method called at ' . date('Y-m-d H:i:s'));

            $jobName = $_POST['job_name'] ?? null;

            error_log('[CRON EXECUTE] Job name received: ' . ($jobName ?? 'NULL'));

            if (!$jobName) {
                error_log('[CRON EXECUTE] ERROR: Missing job_name parameter');
                $this->json([
                    'success' => false,
                    'message' => 'Missing job_name parameter',
                ], 400);

                return;
            }

            Logger::info('Manual cron job execution requested', [
                'job' => $jobName,
                'admin_user' => $_SESSION['admin_user']['username'] ?? 'unknown',
            ]);

            // Map job names to script paths (Docker container paths)
            // ENTERPRISE V4.6: Complete cron migration - ALL jobs mapped
            $projectRoot = '/var/www/html';
            $scriptMap = [
                // === SYSTEM ===
                'session-sync' => 'session-sync-worker.sh',
                'cache-warmup' => 'cache-warmup.sh',
                'cache-warmup-public-pages' => 'cache-warmup-public-pages.sh',
                'redis-cleanup' => 'cleanup-redis.sh',

                // === MAINTENANCE ===
                'performance-summary-update' => 'update-performance-summary.sh',
                'performance-cleanup' => 'cleanup-performance-metrics.sh',
                'log-cleanup' => 'cleanup-logs.sh',
                'rate-limit-cleanup' => 'cleanup-rate-limits.sh',
                'session-cleanup' => 'cleanup-sessions.sh',
                'email-queue-cleanup' => 'cleanup-email-queue.sh',

                // === ANALYTICS ===
                'daily-analytics' => 'generate-analytics.sh',
                'monthly-report' => 'generate-monthly-report.sh',

                // === SECURITY ===
                'security-audit' => 'security-audit.sh',
                'gdpr-account-deletion' => 'cleanup-scheduled-account-deletions.sh',
                'cleanup-whitelisted-security-events' => 'cleanup-whitelisted-security-events.sh',
                'rotate-security-partitions' => 'rotate-security-partitions.sh',
                'cleanup-old-vulnerability-bans' => 'cleanup-old-vulnerability-bans.sh',

                // === EMAIL ===
                'failed-emails-retry' => 'retry-failed-emails.sh',

                // === CHAT ===
                'chat-room-cleanup' => 'chat-room-cleanup.sh',
                'chat-partition' => 'chat-partition.sh',
                'chat-archive' => 'chat-archive.sh',
                'dm-message-cleanup' => 'dm-cleanup.sh',
                'room-inactivity-kick' => 'room-inactivity-kick.sh',

                // === ALERTS ===
                'telegram-alerts' => 'telegram-alerts.sh',
                'telegram-daily-logs' => 'telegram-daily-logs.sh',
                'cleanup-telegram' => 'cleanup-telegram.sh',

                // === AUDIO ===
                'orphaned-audio-cleanup' => 'cron-orphaned-audio-cleanup.sh',
                'cleanup-deleted-audio-posts' => 'cleanup-deleted-audio-posts.sh',

                // === JOURNAL ===
                'journal-trash-cleanup' => 'journal-trash-cleanup.sh',

                // === OVERLAY/FLUSH ===
                'overlay-flush' => 'overlay-flush-worker.sh',

                // === EMOFRIENDLY ===
                'emofriendly-calculator' => 'emofriendly-calculator.sh',
            ];

            if (!isset($scriptMap[$jobName])) {
                error_log('[CRON EXECUTE] ERROR: Unknown job: ' . $jobName);
                $this->json([
                    'success' => false,
                    'message' => 'Unknown job: ' . $jobName,
                ], 400);

                return;
            }

            $scriptPath = $projectRoot . '/scripts/crons/' . $scriptMap[$jobName];
            error_log('[CRON EXECUTE] Script path: ' . $scriptPath);

            if (!file_exists($scriptPath)) {
                error_log('[CRON EXECUTE] ERROR: Script not found: ' . $scriptPath);
                $this->json([
                    'success' => false,
                    'message' => 'Script not found: ' . $scriptPath,
                ], 404);

                return;
            }

            error_log('[CRON EXECUTE] Script exists, proceeding with execution');

            // CRITICAL FIX: Close session before exec() to prevent session locking
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Execute script DIRECTLY (like AdminWorkerController does with workers)
            $startTime = microtime(true);
            $oldDir = getcwd();
            chdir($projectRoot);

            // ENTERPRISE GALAXY: Detect environment and use appropriate shell
            // Docker containers: use /bin/sh (bash might not exist)
            // macOS/Local: use /bin/bash
            $shell = file_exists('/bin/bash') ? '/bin/bash' : '/bin/sh';

            // ENTERPRISE FIX: Force UTF-8 locale to properly capture emoji output
            // This ensures exec() interprets the script output as UTF-8 instead of binary
            $command = sprintf(
                'LC_ALL=en_US.UTF-8 LANG=en_US.UTF-8 PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH" %s %s 2>&1',
                $shell,
                escapeshellarg($scriptPath)
            );

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            $outputText = implode("\n", $output);

            chdir($oldDir);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Determine success based on return code (0 = success)
            $success = ($returnCode === 0);

            error_log('[CRON EXECUTE] Script executed: return_code=' . $returnCode . ', execution_time=' . $executionTime . 'ms, success=' . ($success ? 'true' : 'false'));

            // ENTERPRISE FIX: The output is being captured as ISO-8859-1 instead of UTF-8
            // We need to convert it properly
            $cleanOutput = $outputText ?: 'No output';

            // Check if output is gzip compressed (magic bytes: 0x1f 0x8b)
            if (strlen($cleanOutput) > 2 && ord($cleanOutput[0]) === 0x1f && ord($cleanOutput[1]) === 0x8b) {
                error_log('[CRON EXECUTE] Output is GZIP compressed, decompressing...');
                $cleanOutput = @gzdecode($cleanOutput);
                if ($cleanOutput === false) {
                    $cleanOutput = '[Error: Failed to decompress gzip output]';
                }
            }

            // Convert from ISO-8859-1 to UTF-8 if needed
            $detectedEncoding = mb_detect_encoding($cleanOutput, ['UTF-8', 'ISO-8859-1', 'ASCII'], true);
            if ($detectedEncoding === 'ISO-8859-1') {
                error_log('[CRON EXECUTE] Converting from ISO-8859-1 to UTF-8');
                $cleanOutput = mb_convert_encoding($cleanOutput, 'UTF-8', 'ISO-8859-1');
            }

            // Log execution in database and update last_run
            try {
                error_log('[CRON EXECUTE] Attempting to save to database...');
                $db = db();
                $jobRow = $db->findOne("SELECT id FROM cron_jobs WHERE name = ?", [$jobName]);

                if ($jobRow) {
                    error_log('[CRON EXECUTE] Found job_id: ' . $jobRow['id']);

                    // ENTERPRISE DEBUG: Check output encoding before insert
                    $outputLength = strlen($cleanOutput);
                    $outputEncoding = mb_detect_encoding($cleanOutput, ['UTF-8', 'ASCII', 'ISO-8859-1'], true);
                    error_log('[CRON EXECUTE] Output: length=' . $outputLength . ', encoding=' . ($outputEncoding ?: 'UNKNOWN'));

                    // Insert execution record with CLEAN output
                    try {
                        $db->execute("
                            INSERT INTO cron_executions
                            (job_id, success, execution_time, output, return_code, executed_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ", [
                            $jobRow['id'],
                            $success ? 1 : 0,
                            $executionTime,
                            substr($cleanOutput, 0, 10000), // Limit to 10k chars, use CLEAN output
                            $returnCode,
                        ]);

                        error_log('[CRON EXECUTE] Database insert successful');
                    } catch (\Exception $insertEx) {
                        error_log('[CRON EXECUTE] DATABASE INSERT FAILED: ' . $insertEx->getMessage());
                        error_log('[CRON EXECUTE] Output sample: ' . substr($cleanOutput, 0, 50));
                        throw $insertEx; // Re-throw to be caught by outer catch
                    }

                    // Update last_run timestamp and invalidate cache
                    $db->execute(
                        "UPDATE cron_jobs SET last_run = NOW() WHERE id = ?",
                        [$jobRow['id']],
                        ['invalidate_cache' => ['table:cron_jobs', 'cron:*']]
                    );
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Logger::error('Failed to log cron execution', [
                    'job' => $jobName,
                    'error' => $e->getMessage(),
                ]);
            }

            Logger::info('Manual cron job executed', [
                'job' => $jobName,
                'execution_time' => $executionTime,
                'success' => $success,
            ]);

            error_log('[CRON EXECUTE] About to send JSON response');

            // Note: cleanOutput was already created above for database insert

            $response = [
                'success' => $success,
                'message' => $success ? 'Job executed successfully' : 'Job execution failed',
                'execution_time' => $executionTime,
                'output' => $cleanOutput,
                'return_code' => $returnCode,
            ];

            // ENTERPRISE DEBUG: Check json_encode result
            $jsonTest = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($jsonTest === false) {
                error_log('[CRON EXECUTE] JSON ENCODE FAILED: ' . json_last_error_msg());
                error_log('[CRON EXECUTE] Output length: ' . strlen($outputText));
                error_log('[CRON EXECUTE] Output sample: ' . substr($outputText, 0, 100));

                // FALLBACK: Send response without output if encoding fails
                $response['output'] = 'Output contains invalid UTF-8 characters';
            } else {
                error_log('[CRON EXECUTE] JSON encode SUCCESS, length: ' . strlen($jsonTest));
            }

            $this->json($response);

            error_log('[CRON EXECUTE] Response sent successfully');

        } catch (\Exception $e) {
            error_log('[CRON EXECUTE] EXCEPTION: ' . $e->getMessage());
            error_log('[CRON EXECUTE] TRACE: ' . $e->getTraceAsString());

            Logger::error('Failed to execute cron job', [
                'error' => $e->getMessage(),
                'job' => $jobName ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'message' => 'Error executing job: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Get job execution history
     */
    public function getJobHistory(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        try {
            $jobName = $_GET['job_name'] ?? null;
            $limit = (int) ($_GET['limit'] ?? 50);
            $limit = min(500, max(10, $limit));

            if (!$jobName) {
                $this->json([
                    'success' => false,
                    'message' => 'Missing job_name parameter',
                ], 400);

                return;
            }

            $history = $this->cronManager->getJobHistory($jobName, $limit);

            $this->json([
                'success' => true,
                'job_name' => $jobName,
                'history' => $history,
                'count' => count($history),
                'timestamp' => date('c'),
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get job history', [
                'error' => $e->getMessage(),
                'job' => $jobName ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'message' => 'Failed to load job history',
            ], 500);
        }
    }

    /**
     * API: Get cron worker container status
     * ENTERPRISE GALAXY V4.7: Docker container monitoring
     */
    public function getWorkerStatus(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        try {
            // Get container status via docker inspect
            $output = [];
            $returnCode = 0;

            // Check if container is running
            exec('docker inspect --format="{{.State.Running}}" need2talk_cron_worker 2>&1', $output, $returnCode);
            $running = ($returnCode === 0 && trim($output[0] ?? '') === 'true');

            // Get container stats
            $stats = [
                'running' => $running,
                'uptime' => '-',
                'memory' => '-',
                'cpu' => '-',
                'healthy' => false,
            ];

            if ($running) {
                // Get uptime
                $uptimeOutput = [];
                exec('docker inspect --format="{{.State.StartedAt}}" need2talk_cron_worker 2>&1', $uptimeOutput, $returnCode);
                if ($returnCode === 0 && !empty($uptimeOutput[0])) {
                    $startTime = strtotime($uptimeOutput[0]);
                    $uptime = time() - $startTime;
                    $stats['uptime'] = $this->formatUptime($uptime);
                }

                // Get memory and CPU from docker stats
                $dockerStats = [];
                exec('docker stats need2talk_cron_worker --no-stream --format "{{.MemUsage}}|{{.CPUPerc}}" 2>&1', $dockerStats, $returnCode);
                if ($returnCode === 0 && !empty($dockerStats[0])) {
                    $parts = explode('|', $dockerStats[0]);
                    $stats['memory'] = $parts[0] ?? '-';
                    $stats['cpu'] = $parts[1] ?? '-';
                }

                // Check health status
                $healthOutput = [];
                exec('docker inspect --format="{{.State.Health.Status}}" need2talk_cron_worker 2>&1', $healthOutput, $returnCode);
                $stats['healthy'] = ($returnCode === 0 && trim($healthOutput[0] ?? '') === 'healthy');
            }

            $this->json([
                'success' => true,
                'status' => $stats,
                'timestamp' => date('c'),
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get cron worker status', ['error' => $e->getMessage()]);
            $this->json([
                'success' => false,
                'message' => 'Failed to get worker status',
            ], 500);
        }
    }

    /**
     * Format uptime in human-readable format
     */
    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        } elseif ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $mins = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $mins . 'm';
        } else {
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            return $days . 'd ' . $hours . 'h';
        }
    }

    /**
     * API: Get cron worker logs
     * ENTERPRISE GALAXY V4.7: Docker logs streaming
     */
    public function getWorkerLogs(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        try {
            $lines = (int) ($_GET['lines'] ?? 100);
            $lines = min(500, max(10, $lines));

            $output = [];
            $returnCode = 0;

            // Get docker logs
            exec("docker logs need2talk_cron_worker --tail {$lines} 2>&1", $output, $returnCode);

            $logs = implode("\n", $output);

            $this->json([
                'success' => true,
                'logs' => $logs,
                'lines_returned' => count($output),
                'timestamp' => date('c'),
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get cron worker logs', ['error' => $e->getMessage()]);
            $this->json([
                'success' => false,
                'message' => 'Failed to get worker logs',
            ], 500);
        }
    }

    /**
     * API: Control cron worker (start/stop/restart)
     * ENTERPRISE GALAXY V4.7: Docker container control
     */
    public function workerControl(): void
    {
        header('Content-Type: application/json');

        try {
            $action = $_POST['action'] ?? null;

            if (!in_array($action, ['start', 'stop', 'restart'])) {
                $this->json([
                    'success' => false,
                    'message' => 'Invalid action. Use: start, stop, restart',
                ], 400);
                return;
            }

            $output = [];
            $returnCode = 0;

            // Execute docker compose command
            $command = match ($action) {
                'start' => 'cd /var/www/need2talk && docker compose up -d cron_worker 2>&1',
                'stop' => 'docker stop need2talk_cron_worker 2>&1',
                'restart' => 'docker restart need2talk_cron_worker 2>&1',
            };

            exec($command, $output, $returnCode);

            $success = ($returnCode === 0);

            Logger::info('Cron worker control action', [
                'action' => $action,
                'success' => $success,
                'output' => implode("\n", $output),
                'admin_user' => $_SESSION['admin_user']['username'] ?? 'unknown',
            ]);

            $messages = [
                'start' => $success ? 'Cron worker avviato' : 'Errore avvio worker',
                'stop' => $success ? 'Cron worker fermato' : 'Errore stop worker',
                'restart' => $success ? 'Cron worker riavviato' : 'Errore riavvio worker',
            ];

            $this->json([
                'success' => $success,
                'message' => $messages[$action],
                'output' => implode("\n", $output),
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to control cron worker', [
                'error' => $e->getMessage(),
                'action' => $action ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'message' => 'Error controlling worker: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Get system health status
     */
    public function getHealthStatus(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        try {
            $jobs = $this->cronManager->getAllJobs();

            $stats = [
                'total_jobs' => count($jobs),
                'enabled_jobs' => 0,
                'disabled_jobs' => 0,
                'healthy_jobs' => 0,
                'error_jobs' => 0,
                'pending_jobs' => 0,
                'total_executions' => 0,
                'successful_executions' => 0,
                'failed_executions' => 0,
                'avg_execution_time' => 0,
            ];

            $executionTimes = [];

            foreach ($jobs as $job) {
                if ($job['enabled']) {
                    $stats['enabled_jobs']++;
                } else {
                    $stats['disabled_jobs']++;
                }

                $stats['total_executions'] += $job['total_runs'];
                $stats['successful_executions'] += $job['successful_runs'];
                $stats['failed_executions'] += ($job['total_runs'] - $job['successful_runs']);

                if ($job['avg_execution_time']) {
                    $executionTimes[] = $job['avg_execution_time'];
                }

                // Determine health
                if ($job['last_run']) {
                    $recentExecutions = $this->cronManager->getJobHistory($job['name'], 1);
                    if (!empty($recentExecutions) && $recentExecutions[0]['success']) {
                        $stats['healthy_jobs']++;
                    } else {
                        $stats['error_jobs']++;
                    }
                } else {
                    $stats['pending_jobs']++;
                }
            }

            if (count($executionTimes) > 0) {
                $stats['avg_execution_time'] = round(array_sum($executionTimes) / count($executionTimes), 2);
            }

            $stats['success_rate'] = $stats['total_executions'] > 0
                ? round(($stats['successful_executions'] / $stats['total_executions']) * 100, 2)
                : 0;

            $this->json([
                'success' => true,
                'stats' => $stats,
                'timestamp' => date('c'),
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get health status', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'message' => 'Failed to load health status',
            ], 500);
        }
    }
}
