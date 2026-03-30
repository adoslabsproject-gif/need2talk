<?php

declare(strict_types=1);

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;

/**
 * Admin Email Worker Controller - Enterprise Galaxy (Docker Edition)
 *
 * Gestisce il controllo e monitoring dei worker email via Docker
 * per la dashboard admin Filament
 *
 * ARCHITECTURE: Docker Compose orchestration con container dedicato
 * Container: need2talk_worker (process isolation, auto-healing)
 *
 * @version 11.7.0 - Fixed JSON output bug (methods were returning arrays instead of echoing JSON)
 */
class AdminEmailWorkerController
{
    private const CONTAINER_NAME = 'need2talk_worker';
    private const COMPOSE_SERVICE = 'worker';

    /**
     * Get worker status from Docker container
     * GET /api/email-workers/status
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
                    'message' => 'Worker container not found. Run: docker compose up -d worker',
                    'active' => false,
                    'enabled' => false,
                    'workers' => 0,
                ]);
                return;
            }

            $statusLine = trim($containerStatus[0] ?? '');
            $isHealthy = strpos($statusLine, '(healthy)') !== false;
            $isRunning = strpos($statusLine, 'Up') === 0;

            // ENTERPRISE GALAXY: Count running workers in DEDICATED Worker Container
            exec("docker exec " . self::CONTAINER_NAME . " ps aux | grep email-worker.php | grep -v grep | wc -l", $workerCount);
            $workerCount = (int) trim($workerCount[0] ?? 0);

            // ENTERPRISE GALAXY: Get memory and CPU usage from DEDICATED Worker Container
            exec("docker stats " . self::CONTAINER_NAME . " --no-stream --format '{{.MemUsage}}|{{.CPUPerc}}'", $statsOutput);
            $stats = explode('|', $statsOutput[0] ?? '0 / 0|0%');

            // Get container uptime
            $uptime = $this->parseDockerUptime($statusLine);

            // Get recent logs from Docker container
            exec("docker logs " . self::CONTAINER_NAME . " --tail 10 2>&1", $recentLogs);

            // Get Docker health check status
            exec("docker inspect " . self::CONTAINER_NAME . " --format '{{.State.Health.Status}}' 2>/dev/null", $healthStatus);
            $health = trim($healthStatus[0] ?? 'unknown');

            echo json_encode([
                'status' => $isRunning ? 'running' : 'stopped',
                'active' => $isRunning,
                'enabled' => true, // Docker restart policy = unless-stopped (always enabled)
                'workers' => $workerCount,
                'uptime' => $uptime,
                'memory' => trim($stats[0] ?? 'N/A'),
                'cpu' => trim($stats[1] ?? 'N/A'),
                'health' => $health,
                'container_name' => self::CONTAINER_NAME,
                'restart_policy' => 'unless-stopped',
                'restart_delay' => 'immediate',
                'recent_logs' => array_slice($recentLogs, -5), // Last 5 lines
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get email worker status', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'active' => false,
                'enabled' => false,
                'workers' => 0,
            ]);
        }
    }

    /**
     * Start Docker worker container
     * POST /api/email-workers/start
     */
    public function start(): void
    {
        header('Content-Type: application/json');

        try {
            // ENTERPRISE GALAXY: Start worker container via Docker Compose
            exec("docker compose start " . self::COMPOSE_SERVICE . " 2>&1", $output, $returnCode);

            sleep(2); // Wait for container to start

            if ($returnCode === 0) {
                Logger::security('info', 'ADMIN: Email workers started via Docker', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'container' => self::CONTAINER_NAME,
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Email workers started successfully',
                ]);
            } else {
                throw new \Exception('Failed to start container: ' . implode("\n", $output));
            }

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to start email workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop Docker worker container
     * POST /api/email-workers/stop
     */
    public function stop(): void
    {
        header('Content-Type: application/json');

        try {
            // ENTERPRISE GALAXY: Stop worker container via Docker Compose
            exec("docker compose stop " . self::COMPOSE_SERVICE . " 2>&1", $output, $returnCode);

            sleep(2); // Wait for container to stop

            if ($returnCode === 0) {
                Logger::security('warning', 'ADMIN: Email workers stopped via Docker', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'container' => self::CONTAINER_NAME,
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Email workers stopped successfully',
                ]);
            } else {
                throw new \Exception('Failed to stop container: ' . implode("\n", $output));
            }

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to stop email workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Restart Docker worker container
     * POST /api/email-workers/restart
     */
    public function restart(): void
    {
        header('Content-Type: application/json');

        try {
            // ENTERPRISE GALAXY: Restart worker container via Docker Compose
            exec("docker compose restart " . self::COMPOSE_SERVICE . " 2>&1", $output, $returnCode);

            sleep(2); // Wait for container to restart

            if ($returnCode === 0) {
                Logger::security('info', 'ADMIN: Email workers restarted via Docker', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'container' => self::CONTAINER_NAME,
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Email workers restarted successfully',
                ]);
            } else {
                throw new \Exception('Failed to restart container: ' . implode("\n", $output));
            }

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to restart email workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enable auto-start on boot (Docker restart policy)
     * POST /api/email-workers/enable
     *
     * NOTE: With Docker Compose restart: unless-stopped, this is always enabled
     */
    public function enable(): void
    {
        header('Content-Type: application/json');

        try {
            // ENTERPRISE GALAXY: Docker restart policy verification
            exec("docker inspect " . self::CONTAINER_NAME . " --format '{{.HostConfig.RestartPolicy.Name}}' 2>&1", $policy);
            $currentPolicy = trim($policy[0] ?? 'no');

            if ($currentPolicy === 'unless-stopped' || $currentPolicy === 'always') {
                Logger::security('info', 'ADMIN: Email workers auto-start verified', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'container' => self::CONTAINER_NAME,
                    'policy' => $currentPolicy,
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => "Auto-start already enabled (restart policy: $currentPolicy)",
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => "Container restart policy is '$currentPolicy'. Update docker-compose.yml to 'unless-stopped'.",
                ]);
            }

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to verify email workers auto-start', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Disable auto-start on boot (Docker restart policy)
     * POST /api/email-workers/disable
     *
     * NOTE: Cannot disable Docker restart policy without modifying docker-compose.yml
     */
    public function disable(): void
    {
        header('Content-Type: application/json');

        try {
            Logger::security('warning', 'ADMIN: Email workers auto-start disable attempted', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'container' => self::CONTAINER_NAME,
                'note' => 'Docker restart policy cannot be disabled at runtime',
            ]);

            echo json_encode([
                'success' => false,
                'message' => 'Cannot disable Docker restart policy. Edit docker-compose.yml and change restart: unless-stopped to restart: "no", then run: docker compose up -d',
            ]);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to disable email workers auto-start', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get Docker container logs
     * GET /api/email-workers/logs
     */
    public function getLogs(): void
    {
        header('Content-Type: application/json');

        try {
            // Parse query string for lines parameter
            $lines = min(max((int)($_GET['lines'] ?? 50), 10), 500);

            // ENTERPRISE GALAXY: Get logs from Docker container
            exec("docker logs " . self::CONTAINER_NAME . " --tail $lines 2>&1", $logs);

            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'lines' => count($logs),
            ]);

        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'logs' => [],
            ]);
        }
    }

    /**
     * Parse uptime from Docker container status
     *
     * Example status: "Up 3 hours (healthy)"
     */
    private function parseDockerUptime(string $statusLine): string
    {
        // Match patterns like "Up 3 hours", "Up 25 minutes", "Up 2 days"
        if (preg_match('/Up\s+(.+?)(?:\s+\(|$)/i', $statusLine, $matches)) {
            return trim($matches[1]);
        }

        return 'Unknown';
    }
}
