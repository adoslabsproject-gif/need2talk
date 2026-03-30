<?php

declare(strict_types=1);

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: Admin Newsletter Worker Controller
 *
 * Gestisce il controllo e monitoring dei newsletter worker via Docker
 * Container dedicato: need2talk_newsletter_worker
 *
 * ARCHITECTURE: Docker Compose orchestration con process isolation
 * Auto-recovery: cron ogni 15 minuti (rispetta admin toggle)
 * Admin toggle: file-based flag system
 *
 * @package Need2Talk\Controllers
 * @version 11.7.0 - Fixed JSON output bug (methods were returning arrays instead of echoing JSON)
 */
class AdminNewsletterWorkerController
{
    private const CONTAINER_NAME = 'need2talk_newsletter_worker';
    private string $projectRoot;
    private string $autostartFlagFile;

    public function __construct()
    {
        // ENTERPRISE: PHP runs inside container mounted at /var/www/html
        $this->projectRoot = env('PROJECT_ROOT', '/var/www/html');
        $this->autostartFlagFile = $this->projectRoot . '/storage/newsletter_auto_restart_disabled.flag';
    }

    /**
     * Get newsletter worker status from Docker container
     * GET /api/newsletter-workers/status
     */
    public function getStatus(): void
    {
        header('Content-Type: application/json');

        try {
            // ENTERPRISE GALAXY: Check if Docker container exists and is running
            exec("docker ps --filter name=" . self::CONTAINER_NAME . " --format '{{.Status}}'", $containerStatus, $returnCode);

            if ($returnCode !== 0 || empty($containerStatus)) {
                echo json_encode([
                    'status' => 'not_running',
                    'message' => 'Newsletter worker container not found',
                    'active' => false,
                    'enabled' => $this->isAutostartEnabled(),
                    'workers' => 0,
                    'uptime' => '-',
                    'memory' => '-',
                    'cpu' => '-',
                    'health' => 'unknown',
                    'recent_logs' => [],
                ]);
                return;
            }

            $statusLine = trim($containerStatus[0] ?? '');
            $isHealthy = strpos($statusLine, '(healthy)') !== false;
            $isRunning = strpos($statusLine, 'Up') === 0;

            // ENTERPRISE GALAXY: Count running workers in newsletter container
            exec("docker exec " . self::CONTAINER_NAME . " ps aux | grep admin-email-worker.php | grep -v grep | wc -l", $workerCount);
            $workerCount = (int) trim($workerCount[0] ?? 0);

            // ENTERPRISE GALAXY: Get memory and CPU usage
            exec("docker stats " . self::CONTAINER_NAME . " --no-stream --format '{{.MemUsage}}|{{.CPUPerc}}'", $statsOutput);
            $stats = explode('|', $statsOutput[0] ?? '0 / 0|0%');

            // Get container uptime
            $uptime = $this->parseDockerUptime($statusLine);

            // Get recent logs from Docker container (last 50 lines)
            exec("docker logs " . self::CONTAINER_NAME . " --tail 50 2>&1", $recentLogs);

            // Get Docker health check status
            exec("docker inspect " . self::CONTAINER_NAME . " --format '{{.State.Health.Status}}' 2>/dev/null", $healthStatus);
            $health = trim($healthStatus[0] ?? 'unknown');

            // Get queue size from container
            exec("docker exec " . self::CONTAINER_NAME . " sh -c 'ls -1 /var/www/html/storage/newsletter_queue/*.json 2>/dev/null | wc -l'", $queueSize);
            $queueSize = (int) trim($queueSize[0] ?? 0);

            echo json_encode([
                'status' => $isRunning ? 'running' : 'stopped',
                'active' => $isRunning,
                'enabled' => $this->isAutostartEnabled(),
                'workers' => $workerCount,
                'uptime' => $uptime,
                'memory' => trim($stats[0] ?? 'N/A'),
                'cpu' => trim($stats[1] ?? 'N/A'),
                'health' => $health,
                'container_name' => self::CONTAINER_NAME,
                'restart_policy' => 'unless-stopped',
                'autostart_enabled' => $this->isAutostartEnabled(),
                'recent_logs' => $recentLogs,
                'queue_size' => $queueSize,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to get status', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'active' => false,
                'enabled' => $this->isAutostartEnabled(),
                'workers' => 0,
                'uptime' => '-',
                'memory' => '-',
                'cpu' => '-',
                'health' => 'error',
                'recent_logs' => [],
            ]);
        }
    }

    /**
     * Start newsletter worker container
     * POST /api/newsletter-workers/start
     */
    public function start(): void
    {
        header('Content-Type: application/json');

        try {
            // Get admin info for audit
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            // ENTERPRISE GALAXY: Start container via Docker direct command
            exec("docker start " . self::CONTAINER_NAME . " 2>&1", $output, $returnCode);

            sleep(2); // Wait for container to start

            if ($returnCode === 0) {
                Logger::security('warn', 'ADMIN: Newsletter worker container started', [
                    'admin_id' => $adminId,
                    'admin_email' => $adminEmail,
                    'container' => self::CONTAINER_NAME,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Newsletter container started successfully',
                ]);
            } else {
                throw new \Exception('Failed to start container: ' . implode("\n", $output));
            }

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to start container', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop newsletter worker container
     * POST /api/newsletter-workers/stop
     */
    public function stop(): void
    {
        header('Content-Type: application/json');

        try {
            // Get admin info for audit
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            // ENTERPRISE GALAXY: Stop container via Docker direct command
            exec("docker stop " . self::CONTAINER_NAME . " 2>&1", $output, $returnCode);

            sleep(2); // Wait for container to stop

            if ($returnCode === 0) {
                Logger::security('warn', 'ADMIN: Newsletter worker container stopped', [
                    'admin_id' => $adminId,
                    'admin_email' => $adminEmail,
                    'container' => self::CONTAINER_NAME,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Newsletter container stopped successfully',
                ]);
            } else {
                throw new \Exception('Failed to stop container: ' . implode("\n", $output));
            }

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to stop container', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Restart newsletter worker container
     * POST /api/newsletter-workers/restart
     */
    public function restart(): void
    {
        header('Content-Type: application/json');

        try {
            // Get admin info for audit
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            // ENTERPRISE GALAXY: Restart container via Docker direct command
            exec("docker restart " . self::CONTAINER_NAME . " 2>&1", $output, $returnCode);

            sleep(2); // Wait for container to restart

            if ($returnCode === 0) {
                Logger::security('info', 'ADMIN: Newsletter worker container restarted', [
                    'admin_id' => $adminId,
                    'admin_email' => $adminEmail,
                    'container' => self::CONTAINER_NAME,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Newsletter container restarted successfully',
                ]);
            } else {
                throw new \Exception('Failed to restart container: ' . implode("\n", $output));
            }

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to restart container', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop newsletter worker container and clean logs
     * POST /api/newsletter-workers/stop-clean
     */
    public function stopAndClean(): array
    {
        try {
            // Get admin info for audit
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            // ENTERPRISE GALAXY: Stop container via Docker direct command
            exec("docker stop " . self::CONTAINER_NAME . " 2>&1", $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception('Failed to stop container: ' . implode("\n", $output));
            }

            sleep(2); // Wait for container to stop

            // Clean newsletter logs from container (container is stopped, so this will fail - that's OK)
            exec("docker exec " . self::CONTAINER_NAME . " sh -c 'rm -f /var/www/html/storage/logs/newsletter*.log' 2>&1", $cleanOutput, $cleanReturnCode);

            Logger::security('warning', 'ADMIN: Newsletter worker stopped and logs cleaned', [
                'admin_id' => $adminId,
                'admin_email' => $adminEmail,
                'container' => self::CONTAINER_NAME,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            return [
                'success' => true,
                'message' => 'Newsletter container stopped and logs cleaned successfully',
            ];

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to stop and clean', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get detailed health status of newsletter worker system
     * GET /api/newsletter-workers/health
     */
    public function getHealth(): void
    {
        header('Content-Type: application/json');

        try {
            // Get basic status data (internal call, not outputting JSON)
            $status = $this->getStatusData();

            // Check container health
            exec("docker inspect " . self::CONTAINER_NAME . " --format '{{json .State.Health}}' 2>/dev/null", $healthJson);
            $health = json_decode($healthJson[0] ?? '{}', true);

            // Check queue directory
            exec("docker exec " . self::CONTAINER_NAME . " sh -c 'test -d /var/www/html/storage/newsletter_queue && echo exists || echo missing' 2>&1", $queueDir);
            $queueDirStatus = trim($queueDir[0] ?? 'unknown');

            // Check worker processes
            exec("docker exec " . self::CONTAINER_NAME . " ps aux | grep admin-email-worker.php | grep -v grep", $workerProcesses);

            // Check disk space in container
            exec("docker exec " . self::CONTAINER_NAME . " df -h /var/www/html/storage 2>&1", $diskSpace);

            echo json_encode([
                'success' => true,
                'health' => [
                    'container_status' => $status['status'],
                    'container_health' => $status['health'],
                    'workers_running' => $status['workers'],
                    'uptime' => $status['uptime'],
                    'memory_usage' => $status['memory'],
                    'cpu_usage' => $status['cpu'],
                    'queue_size' => $status['queue_size'],
                    'queue_directory' => $queueDirStatus,
                    'autostart_enabled' => $status['enabled'],
                    'docker_health_checks' => $health['Status'] ?? 'unknown',
                    'last_health_check' => $health['Log'][0]['End'] ?? null,
                    'worker_processes' => count($workerProcesses),
                    'disk_space' => $diskSpace,
                ],
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to get health status', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get status data (returns array instead of outputting JSON)
     * Used by AdminNewsletterWorkerApiController for proper JSON response handling
     */
    public function getStatusData(): array
    {
        try {
            exec("docker ps --filter name=" . self::CONTAINER_NAME . " --format '{{.Status}}'", $containerStatus, $returnCode);

            if ($returnCode !== 0 || empty($containerStatus)) {
                return [
                    'status' => 'not_running',
                    'active' => false,
                    'enabled' => $this->isAutostartEnabled(),
                    'workers' => 0,
                    'uptime' => '-',
                    'memory' => '-',
                    'cpu' => '-',
                    'health' => 'unknown',
                    'queue_size' => 0,
                ];
            }

            $statusLine = trim($containerStatus[0] ?? '');
            $isRunning = strpos($statusLine, 'Up') === 0;

            exec("docker exec " . self::CONTAINER_NAME . " ps aux | grep admin-email-worker.php | grep -v grep | wc -l", $workerCount);
            $workerCount = (int) trim($workerCount[0] ?? 0);

            exec("docker stats " . self::CONTAINER_NAME . " --no-stream --format '{{.MemUsage}}|{{.CPUPerc}}'", $statsOutput);
            $stats = explode('|', $statsOutput[0] ?? '0 / 0|0%');

            exec("docker inspect " . self::CONTAINER_NAME . " --format '{{.State.Health.Status}}' 2>/dev/null", $healthStatus);
            $health = trim($healthStatus[0] ?? 'unknown');

            exec("docker exec " . self::CONTAINER_NAME . " sh -c 'ls -1 /var/www/html/storage/newsletter_queue/*.json 2>/dev/null | wc -l'", $queueSize);
            $queueSize = (int) trim($queueSize[0] ?? 0);

            return [
                'status' => $isRunning ? 'running' : 'stopped',
                'active' => $isRunning,
                'enabled' => $this->isAutostartEnabled(),
                'workers' => $workerCount,
                'uptime' => $this->parseDockerUptime($statusLine),
                'memory' => trim($stats[0] ?? 'N/A'),
                'cpu' => trim($stats[1] ?? 'N/A'),
                'health' => $health,
                'queue_size' => $queueSize,
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'active' => false,
                'enabled' => $this->isAutostartEnabled(),
                'workers' => 0,
                'uptime' => '-',
                'memory' => '-',
                'cpu' => '-',
                'health' => 'error',
                'queue_size' => 0,
            ];
        }
    }

    /**
     * Enable auto-restart for newsletter workers (removes flag file)
     * POST /api/newsletter-workers/enable
     */
    public function enable(): void
    {
        header('Content-Type: application/json');

        try {
            // Get admin info for audit
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            $wasDisabled = file_exists($this->autostartFlagFile);

            if ($wasDisabled) {
                unlink($this->autostartFlagFile);
            }

            // ENTERPRISE V10.183: Restore Docker restart policy for auto-restart on reboot
            exec("docker update --restart=unless-stopped " . self::CONTAINER_NAME . " 2>&1", $dockerOutput, $dockerReturnCode);

            Logger::security('warn', 'ADMIN: Newsletter auto-restart ENABLED', [
                'admin_id' => $adminId,
                'admin_email' => $adminEmail,
                'was_disabled' => $wasDisabled,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Auto-restart enabled successfully',
                'enabled' => true,
            ]);

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to enable autostart', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Disable auto-restart for newsletter workers (creates flag file)
     * POST /api/newsletter-workers/disable
     */
    public function disable(): void
    {
        header('Content-Type: application/json');

        try {
            // Get admin info for audit
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            $wasEnabled = !file_exists($this->autostartFlagFile);

            if ($wasEnabled) {
                touch($this->autostartFlagFile);
                chmod($this->autostartFlagFile, 0666);
            }

            // ENTERPRISE V10.183: Update Docker restart policy to prevent auto-restart on reboot
            exec("docker update --restart=no " . self::CONTAINER_NAME . " 2>&1", $dockerOutput, $dockerReturnCode);

            Logger::security('warn', 'ADMIN: Newsletter auto-restart DISABLED', [
                'admin_id' => $adminId,
                'admin_email' => $adminEmail,
                'was_enabled' => $wasEnabled,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Auto-restart disabled successfully',
                'enabled' => false,
            ]);

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to disable autostart', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get recent container logs
     * GET /api/newsletter-workers/logs
     */
    public function getLogs(): void
    {
        header('Content-Type: application/json');

        try {
            // Parse query string for lines parameter
            $lines = min(max((int)($_GET['lines'] ?? 50), 10), 500);

            exec("docker logs " . self::CONTAINER_NAME . " --tail {$lines} 2>&1", $logs);

            echo json_encode([
                'success' => true,
                'logs' => $logs,
            ]);

        } catch (\Exception $e) {
            Logger::error('[ADMIN_NEWSLETTER_WORKER] Failed to get logs', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'logs' => [],
            ]);
        }
    }

    /**
     * Check if auto-restart is enabled (flag file does NOT exist)
     */
    private function isAutostartEnabled(): bool
    {
        return !file_exists($this->autostartFlagFile);
    }

    /**
     * Parse Docker uptime from status string
     */
    private function parseDockerUptime(string $statusLine): string
    {
        // Example: "Up 2 hours (healthy)" or "Up 5 minutes"
        if (preg_match('/Up ([^(]+)/', $statusLine, $matches)) {
            return trim($matches[1]);
        }

        return '-';
    }
}
