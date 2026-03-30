<?php

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;

/**
 * NEED2TALK - ADMIN AUDIO WORKER CONTROLLER (ENTERPRISE GALAXY)
 *
 * Gestisce monitoring e controllo dei worker audio via Docker
 * Ispirato ad AdminEmailWorkerController ma con funzionalità audio-specific
 *
 * FEATURES:
 * - Start/Stop/Restart workers
 * - Scale dinamico (1-12 workers)
 * - Real-time metrics (queue, throughput, errors)
 * - Redis heartbeat monitoring
 * - Docker container stats (CPU, RAM, health)
 * - Live logs viewer
 *
 * ARCHITECTURE: Docker Compose con scaling support
 * Service: audio_worker (no container_name per permettere scaling)
 */
class AdminAudioWorkerController
{
    private const COMPOSE_SERVICE = 'audio_worker';
    private const CONTAINER_PREFIX = 'need2talk-audio_worker'; // Docker compose naming: project-service-index
    private const MAX_WORKERS = 12;

    /**
     * GET STATUS - Worker status + metrics
     */
    public function getStatus(): array
    {
        try {
            // Count active audio worker containers
            exec("docker ps --filter name=audio_worker --format '{{.Names}}'", $containers);
            $workerCount = count(array_filter($containers));

            if ($workerCount === 0) {
                return [
                    'status' => 'not_running',
                    'message' => 'No audio workers running. Use "Start Workers" button.',
                    'active' => false,
                    'workers' => 0,
                    'queue_size' => $this->getQueueSize(),
                ];
            }

            // Get container stats (first worker for sample)
            $firstContainer = $containers[0] ?? null;
            $stats = ['memory' => 'N/A', 'cpu' => 'N/A', 'health' => 'unknown'];

            if ($firstContainer) {
                exec("docker stats {$firstContainer} --no-stream --format '{{.MemUsage}}|{{.CPUPerc}}'", $statsOutput);
                $statsParts = explode('|', $statsOutput[0] ?? '');
                $stats['memory'] = trim($statsParts[0] ?? 'N/A');
                $stats['cpu'] = trim($statsParts[1] ?? 'N/A');

                exec("docker inspect {$firstContainer} --format '{{.State.Health.Status}}' 2>/dev/null", $healthOutput);
                $stats['health'] = trim($healthOutput[0] ?? 'unknown');
            }

            // Get Redis heartbeats
            $heartbeats = $this->getWorkerHeartbeats();

            // Get queue metrics
            $queueMetrics = $this->getQueueMetrics();

            // ENTERPRISE GALAXY: Get recent logs from Docker Compose service
            exec("cd /var/www/need2talk && docker compose logs --tail=10 " . self::COMPOSE_SERVICE . " 2>&1", $recentLogs);

            return [
                'status' => 'running',
                'active' => true,
                'workers' => $workerCount,
                'memory' => $stats['memory'],
                'cpu' => $stats['cpu'],
                'health' => $stats['health'],
                'heartbeats' => $heartbeats,
                'queue' => $queueMetrics,
                'recent_logs' => array_slice($recentLogs, -5),
                'timestamp' => date('Y-m-d H:i:s'),
            ];

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get audio worker status', ['error' => $e->getMessage()]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'active' => false,
                'workers' => 0,
            ];
        }
    }

    /**
     * START WORKERS
     */
    public function start(): void
    {
        try {
            // Get worker count from POST (default: 1)
            $postData = json_decode(file_get_contents('php://input'), true);
            $workerCount = (int) ($postData['worker_count'] ?? 1);
            $workerCount = max(1, min($workerCount, self::MAX_WORKERS));

            // ENTERPRISE GALAXY: Get all audio_worker containers (running or stopped)
            exec("docker ps -a --filter name=audio_worker --format '{{.Names}}' 2>&1", $containers, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception('Failed to list containers');
            }

            $containers = array_filter($containers);

            // If no containers exist, need to create them first (requires host action)
            if (empty($containers)) {
                throw new \Exception('No audio worker containers found. Please create them via docker-compose first.');
            }

            // Start existing containers (up to workerCount)
            $containersToStart = array_slice($containers, 0, $workerCount);
            $containerList = implode(' ', $containersToStart);

            exec("docker start {$containerList} 2>&1", $output, $startReturnCode);
            sleep(2);

            if ($startReturnCode == 0) {
                Logger::security('info', 'ADMIN: Audio workers started', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'worker_count' => count($containersToStart),
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => "Started " . count($containersToStart) . " audio worker(s)",
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            throw new \Exception('Failed to start containers: ' . implode("\n", $output));

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to start audio workers', ['error' => $e->getMessage()]);

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * STOP WORKERS
     */
    public function stop(): void
    {
        try {
            // ENTERPRISE GALAXY: Get all audio_worker container names and stop them
            // Use docker stop directly (like newsletter) instead of docker compose
            exec("docker ps --filter name=audio_worker --format '{{.Names}}' 2>&1", $containers, $returnCode);

            if ($returnCode !== 0 || empty(array_filter($containers))) {
                Logger::security('warning', 'ADMIN: No audio workers running to stop', ['admin_user' => $_SESSION['admin_id'] ?? 'unknown']);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'No audio workers running',
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            // Stop all found containers
            $containerList = implode(' ', array_filter($containers));
            exec("docker stop {$containerList} 2>&1", $output, $stopReturnCode);
            sleep(2);

            if ($stopReturnCode == 0) {  // Loose comparison
                Logger::security('warning', 'ADMIN: Audio workers stopped', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'containers_stopped' => count(array_filter($containers)),
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => count(array_filter($containers)) . ' audio worker(s) stopped',
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            throw new \Exception('Failed to stop containers: ' . implode("\n", $output));

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to stop audio workers', ['error' => $e->getMessage()]);

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * SCALE WORKERS
     */
    public function scale(): void
    {
        try {
            // Get worker count from POST
            $postData = json_decode(file_get_contents('php://input'), true);
            $workerCount = (int) ($postData['worker_count'] ?? 1);
            $workerCount = max(1, min($workerCount, self::MAX_WORKERS));

            // ENTERPRISE GALAXY: Scale workers via Docker Compose
            exec("cd /var/www/need2talk && docker compose up -d --scale " . self::COMPOSE_SERVICE . "={$workerCount} " . self::COMPOSE_SERVICE . " 2>&1", $output, $returnCode);
            sleep(2);

            if ($returnCode === 0) {
                Logger::security('info', 'ADMIN: Audio workers scaled', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'worker_count' => $workerCount,
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => "Scaled to {$workerCount} workers",
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            throw new \Exception('Failed to scale: ' . implode("\n", $output));

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to scale audio workers', ['error' => $e->getMessage()]);

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * GET QUEUE METRICS
     */
    private function getQueueMetrics(): array
    {
        try {
            $db = db();

            $processing = $db->count('audio_files', "status = 'processing'") ?? 0;
            $active = $db->count('audio_files', "status = 'active'") ?? 0;
            $failed = $db->count('audio_files', "status = 'failed'") ?? 0;

            return [
                'processing' => $processing,
                'active' => $active,
                'failed' => $failed,
                'total' => $processing + $active + $failed,
            ];

        } catch (\Exception $e) {
            return ['processing' => 0, 'active' => 0, 'failed' => 0, 'total' => 0];
        }
    }

    /**
     * GET QUEUE SIZE
     */
    private function getQueueSize(): int
    {
        try {
            $db = db();

            return $db->count('audio_files', "status = 'processing'") ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * GET WORKER HEARTBEATS from Redis
     */
    private function getWorkerHeartbeats(): array
    {
        try {
            // ENTERPRISE POOL: Use connection pool for queue DB access
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('queue');

            if (!$redis) {
                return [];
            }

            $keys = $redis->keys("worker:audio:*:heartbeat");
            $heartbeats = [];

            foreach ($keys as $key) {
                $data = $redis->get($key);
                if ($data) {
                    $heartbeats[] = json_decode($data, true);
                }
            }

            return $heartbeats;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * GET LOGS (paginated)
     */
    public function getLogs(): void
    {
        try {
            // Get lines from query param (default: 100)
            $lines = (int) ($_GET['lines'] ?? 100);
            $lines = max(10, min($lines, 1000)); // Between 10 and 1000

            // ENTERPRISE GALAXY: Get logs from Docker Compose service
            exec("cd /var/www/need2talk && docker compose logs --tail={$lines} " . self::COMPOSE_SERVICE . " 2>&1", $logs);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'lines' => count($logs),
            ]);
            exit;

        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'logs' => [],
                'error' => $e->getMessage(),
            ]);
            exit;
        }
    }

    /**
     * GET AUTOSTART STATUS
     *
     * ENTERPRISE: Autostart is controlled by Docker restart policy (unless-stopped)
     * - Container RUNNING = Autostart ENABLED (will restart on server boot)
     * - Container STOPPED = Autostart DISABLED (will NOT restart on server boot)
     */
    public function getAutostartStatus(): void
    {
        try {
            // Check if container is running (means autostart is enabled)
            exec("docker ps --filter name=audio_worker --format '{{.Names}}'", $containers);
            $workerCount = count(array_filter($containers));

            // Autostart enabled = at least one container running
            $enabled = $workerCount > 0;

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'autostart_enabled' => $enabled,
            ]);
            exit;

        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * SET AUTOSTART (Enable/Disable)
     *
     * ENTERPRISE: Autostart is controlled by Docker container state
     * - Enable = START container (Docker will auto-restart on server boot with policy unless-stopped)
     * - Disable = STOP container (Docker will NOT restart on server boot)
     */
    public function setAutostart(): void
    {
        try {
            $postData = json_decode(file_get_contents('php://input'), true);
            $enabled = (bool) ($postData['enabled'] ?? false);

            if ($enabled) {
                // Enable autostart = START container (use docker start on existing containers)
                exec("docker ps -a --filter name=audio_worker --format '{{.Names}}' 2>&1", $containers, $listReturnCode);

                if ($listReturnCode !== 0 || empty(array_filter($containers))) {
                    throw new \Exception('No audio worker containers found. Please create them via docker-compose first.');
                }

                // Start first container (for autostart, 1 worker is enough)
                $firstContainer = array_filter($containers)[0] ?? null;
                if ($firstContainer) {
                    exec("docker start {$firstContainer} 2>&1", $output, $startReturnCode);
                    sleep(2);

                    if ($startReturnCode !== 0) {
                        throw new \Exception('Failed to start worker: ' . implode("\n", $output));
                    }
                }

                Logger::security('info', 'ADMIN: Audio workers autostart ENABLED', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Autostart enabled - Workers started',
                    'autostart_enabled' => true,
                ]);
            } else {
                // Disable autostart = STOP all containers (use direct docker stop)
                exec("docker ps --filter name=audio_worker --format '{{.Names}}' 2>&1", $containers, $listReturnCode);

                if ($listReturnCode === 0 && !empty(array_filter($containers))) {
                    $containerList = implode(' ', array_filter($containers));
                    exec("docker stop {$containerList} 2>&1", $output, $stopReturnCode);
                    sleep(2);

                    if ($stopReturnCode !== 0) {
                        throw new \Exception('Failed to stop workers: ' . implode("\n", $output));
                    }
                }

                Logger::security('warning', 'ADMIN: Audio workers autostart DISABLED', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Autostart disabled - Workers stopped',
                    'autostart_enabled' => false,
                ]);
            }
            exit;

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to set audio workers autostart', ['error' => $e->getMessage()]);

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Format metric values for dashboard display
     *
     * ENTERPRISE: Formats complex array data structures into readable HTML
     * Called from admin/dashboard.php view
     *
     * @param string $key Metric key name
     * @param mixed $value Metric value (array or scalar)
     * @return string Formatted HTML-safe string
     */
    public function formatMetricValue(string $key, $value): string
    {
        if (!is_array($value)) {
            return htmlspecialchars((string)$value);
        }

        // Format specific audio worker metrics
        switch ($key) {
            case 'heartbeats':
                // Show worker count + last heartbeat timestamp
                $count = count($value);
                if ($count === 0) {
                    return '<span style="color: red;">No heartbeats</span>';
                }
                $latest = $value[0]['timestamp'] ?? 'unknown';

                return "<span style='color: green;'>✓ {$count} workers</span> (last: " . date('H:i:s', $latest) . ")";

            case 'queue_metrics':
            case 'logs':
                // Show formatted key-value pairs
                $html = '<ul style="margin: 5px 0; padding-left: 20px; font-size: 0.9em;">';
                foreach ($value as $k => $v) {
                    if (is_array($v)) {
                        $v = json_encode($v);
                    }
                    $html .= '<li>' . htmlspecialchars($k) . ': <strong>' . htmlspecialchars((string)$v) . '</strong></li>';
                }
                $html .= '</ul>';

                return $html;

            case 'recent_logs':
                // Show log lines with ellipsis
                $count = count($value);
                if ($count === 0) {
                    return '<em>No logs</em>';
                }
                $preview = htmlspecialchars(substr($value[0] ?? '', 0, 80));

                return "{$count} log entries<br><small style='color: #666;'>{$preview}...</small>";

            default:
                // For simple arrays, show count
                return count($value) . ' items';
        }
    }
}
