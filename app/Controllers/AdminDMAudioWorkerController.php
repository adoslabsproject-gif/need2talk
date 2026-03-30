<?php

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;
use Need2Talk\Services\Chat\DMAudioQueueService;

/**
 * NEED2TALK - ADMIN DM AUDIO E2E WORKER CONTROLLER (ENTERPRISE GALAXY V4.3)
 *
 * Gestisce monitoring e controllo dei worker DM Audio E2E via Docker
 * Worker dedicato per processare audio DM crittografati end-to-end
 *
 * FEATURES:
 * - Start/Stop/Restart workers
 * - Scale dinamico (1-4 workers)
 * - Real-time queue metrics (pending, processing, delayed, failed)
 * - Redis heartbeat monitoring
 * - Docker container stats (CPU, RAM, health)
 * - Auto-scaling recommendations
 * - Live logs viewer
 *
 * ARCHITECTURE: Docker Compose con scaling support
 * Service: dm_audio_worker (no container_name per permettere scaling)
 * Queue: need2talk:queue:dm_audio (Redis DB 2)
 *
 * @since 2025-12-10
 * @version 1.0.0
 */
class AdminDMAudioWorkerController
{
    private const COMPOSE_SERVICE = 'dm_audio_worker';
    private const CONTAINER_PREFIX = 'need2talk-dm_audio_worker';
    private const MAX_WORKERS = 4;

    /**
     * GET STATUS - Worker status + metrics
     */
    public function getStatus(): array
    {
        try {
            // Count active dm_audio_worker containers
            exec("docker ps --filter name=dm_audio_worker --format '{{.Names}}'", $containers);
            $workerCount = count(array_filter($containers));

            // Get queue stats via service
            $queueService = new DMAudioQueueService();
            $queueStats = $queueService->getQueueStats();
            $recommendedWorkers = $queueService->getRecommendedWorkerCount();

            if ($workerCount === 0) {
                return [
                    'status' => 'not_running',
                    'message' => 'No DM audio workers running. Use "Start Workers" button.',
                    'active' => false,
                    'workers' => 0,
                    'recommended_workers' => $recommendedWorkers,
                    'queue' => $queueStats,
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

            // Get recent logs
            exec("cd /var/www/need2talk && docker compose logs --tail=10 " . self::COMPOSE_SERVICE . " 2>&1", $recentLogs);

            // Scaling recommendation
            $scalingAction = null;
            if ($workerCount < $recommendedWorkers) {
                $scalingAction = ['action' => 'scale_up', 'target' => $recommendedWorkers, 'message' => "Queue backlog detected. Scale UP to {$recommendedWorkers} workers."];
            } elseif ($workerCount > $recommendedWorkers && $workerCount > 1) {
                $scalingAction = ['action' => 'scale_down', 'target' => $recommendedWorkers, 'message' => "Queue is light. Consider scaling DOWN to {$recommendedWorkers} worker(s)."];
            }

            return [
                'status' => 'running',
                'active' => true,
                'workers' => $workerCount,
                'recommended_workers' => $recommendedWorkers,
                'memory' => $stats['memory'],
                'cpu' => $stats['cpu'],
                'health' => $stats['health'],
                'heartbeats' => $heartbeats,
                'queue' => $queueStats,
                'scaling_action' => $scalingAction,
                'recent_logs' => array_slice($recentLogs, -5),
                'timestamp' => date('Y-m-d H:i:s'),
            ];

        } catch (\Exception $e) {
            Logger::audio('error', 'ADMIN: Failed to get DM audio worker status', ['error' => $e->getMessage()]);

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
            $postData = json_decode(file_get_contents('php://input'), true);
            $workerCount = (int) ($postData['worker_count'] ?? 1);
            $workerCount = max(1, min($workerCount, self::MAX_WORKERS));

            // Get all dm_audio_worker containers (running or stopped)
            exec("docker ps -a --filter name=dm_audio_worker --format '{{.Names}}' 2>&1", $containers, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception('Failed to list containers');
            }

            $containers = array_filter($containers);

            if (empty($containers)) {
                // Create via docker compose
                exec("cd /var/www/need2talk && docker compose up -d --scale " . self::COMPOSE_SERVICE . "={$workerCount} " . self::COMPOSE_SERVICE . " 2>&1", $output, $composeReturn);

                if ($composeReturn !== 0) {
                    throw new \Exception('Failed to create containers: ' . implode("\n", $output));
                }
            } else {
                // Start existing containers
                $containersToStart = array_slice($containers, 0, $workerCount);
                $containerList = implode(' ', $containersToStart);

                exec("docker start {$containerList} 2>&1", $output, $startReturnCode);
                sleep(2);

                if ($startReturnCode != 0) {
                    throw new \Exception('Failed to start containers: ' . implode("\n", $output));
                }
            }

            Logger::security('info', 'ADMIN: DM audio workers started', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'worker_count' => $workerCount,
            ]);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => "Started {$workerCount} DM audio worker(s)",
                'status' => $this->getStatus(),
            ]);
            exit;

        } catch (\Exception $e) {
            Logger::audio('error', 'ADMIN: Failed to start DM audio workers', ['error' => $e->getMessage()]);

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
            exec("docker ps --filter name=dm_audio_worker --format '{{.Names}}' 2>&1", $containers, $returnCode);

            if ($returnCode !== 0 || empty(array_filter($containers))) {
                Logger::security('warning', 'ADMIN: No DM audio workers running to stop', ['admin_user' => $_SESSION['admin_id'] ?? 'unknown']);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'No DM audio workers running',
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            $containerList = implode(' ', array_filter($containers));
            exec("docker stop {$containerList} 2>&1", $output, $stopReturnCode);
            sleep(2);

            if ($stopReturnCode == 0) {
                Logger::security('warning', 'ADMIN: DM audio workers stopped', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'containers_stopped' => count(array_filter($containers)),
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => count(array_filter($containers)) . ' DM audio worker(s) stopped',
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            throw new \Exception('Failed to stop containers: ' . implode("\n", $output));

        } catch (\Exception $e) {
            Logger::audio('error', 'ADMIN: Failed to stop DM audio workers', ['error' => $e->getMessage()]);

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
            $postData = json_decode(file_get_contents('php://input'), true);
            $workerCount = (int) ($postData['worker_count'] ?? 1);
            $workerCount = max(1, min($workerCount, self::MAX_WORKERS));

            exec("cd /var/www/need2talk && docker compose up -d --scale " . self::COMPOSE_SERVICE . "={$workerCount} " . self::COMPOSE_SERVICE . " 2>&1", $output, $returnCode);
            sleep(2);

            if ($returnCode === 0) {
                Logger::security('info', 'ADMIN: DM audio workers scaled', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'worker_count' => $workerCount,
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => "Scaled to {$workerCount} DM audio workers",
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            throw new \Exception('Failed to scale: ' . implode("\n", $output));

        } catch (\Exception $e) {
            Logger::audio('error', 'ADMIN: Failed to scale DM audio workers', ['error' => $e->getMessage()]);

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * AUTO-SCALE based on queue depth
     */
    public function autoScale(): void
    {
        try {
            $queueService = new DMAudioQueueService();
            $recommendedWorkers = $queueService->getRecommendedWorkerCount();

            // Check current worker count
            exec("docker ps --filter name=dm_audio_worker --format '{{.Names}}'", $containers);
            $currentWorkers = count(array_filter($containers));

            if ($currentWorkers === $recommendedWorkers) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => "Workers already at optimal count ({$currentWorkers})",
                    'action' => 'none',
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            // Scale to recommended
            exec("cd /var/www/need2talk && docker compose up -d --scale " . self::COMPOSE_SERVICE . "={$recommendedWorkers} " . self::COMPOSE_SERVICE . " 2>&1", $output, $returnCode);
            sleep(2);

            if ($returnCode === 0) {
                $action = $recommendedWorkers > $currentWorkers ? 'scaled_up' : 'scaled_down';

                Logger::security('info', 'ADMIN: DM audio workers auto-scaled', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'from' => $currentWorkers,
                    'to' => $recommendedWorkers,
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => "Auto-scaled from {$currentWorkers} to {$recommendedWorkers} workers",
                    'action' => $action,
                    'status' => $this->getStatus(),
                ]);
                exit;
            }

            throw new \Exception('Auto-scale failed: ' . implode("\n", $output));

        } catch (\Exception $e) {
            Logger::audio('error', 'ADMIN: Failed to auto-scale DM audio workers', ['error' => $e->getMessage()]);

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * GET WORKER HEARTBEATS from Redis
     */
    private function getWorkerHeartbeats(): array
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('queue');

            if (!$redis) {
                return [];
            }

            $keys = $redis->keys("dm_audio_worker:*:heartbeat");
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
            $lines = (int) ($_GET['lines'] ?? 100);
            $lines = max(10, min($lines, 1000));

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
     * CLEANUP FAILED JOBS (move to retry or delete)
     */
    public function cleanupFailed(): void
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('queue');

            if (!$redis) {
                throw new \Exception('Failed to connect to Redis');
            }

            // Get failed job count before cleanup
            $failedCount = $redis->lLen('need2talk:queue:dm_audio:failed');

            if ($failedCount === 0) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'No failed jobs to cleanup',
                    'cleaned' => 0,
                ]);
                exit;
            }

            // Clear failed queue
            $redis->del('need2talk:queue:dm_audio:failed');

            Logger::security('warning', 'ADMIN: DM audio failed jobs cleared', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'jobs_cleared' => $failedCount,
            ]);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => "Cleared {$failedCount} failed jobs",
                'cleaned' => $failedCount,
            ]);
            exit;

        } catch (\Exception $e) {
            Logger::audio('error', 'ADMIN: Failed to cleanup DM audio failed jobs', ['error' => $e->getMessage()]);

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * GET AUTOSTART STATUS
     */
    public function getAutostartStatus(): void
    {
        try {
            exec("docker ps --filter name=dm_audio_worker --format '{{.Names}}'", $containers);
            $workerCount = count(array_filter($containers));
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
     */
    public function setAutostart(): void
    {
        try {
            $postData = json_decode(file_get_contents('php://input'), true);
            $enabled = (bool) ($postData['enabled'] ?? false);

            if ($enabled) {
                // Enable = start 1 worker
                exec("cd /var/www/need2talk && docker compose up -d --scale " . self::COMPOSE_SERVICE . "=1 " . self::COMPOSE_SERVICE . " 2>&1", $output, $returnCode);
                sleep(2);

                if ($returnCode !== 0) {
                    throw new \Exception('Failed to enable autostart: ' . implode("\n", $output));
                }

                Logger::security('info', 'ADMIN: DM audio workers autostart ENABLED', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                ]);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Autostart enabled - Worker started',
                    'autostart_enabled' => true,
                ]);
            } else {
                // Disable = stop all
                exec("docker ps --filter name=dm_audio_worker --format '{{.Names}}' 2>&1", $containers, $listReturnCode);

                if ($listReturnCode === 0 && !empty(array_filter($containers))) {
                    $containerList = implode(' ', array_filter($containers));
                    exec("docker stop {$containerList} 2>&1", $output, $stopReturnCode);
                    sleep(2);

                    if ($stopReturnCode !== 0) {
                        throw new \Exception('Failed to stop workers: ' . implode("\n", $output));
                    }
                }

                Logger::security('warning', 'ADMIN: DM audio workers autostart DISABLED', [
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
            Logger::audio('error', 'ADMIN: Failed to set DM audio workers autostart', ['error' => $e->getMessage()]);

            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
}
