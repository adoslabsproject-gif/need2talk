<?php

declare(strict_types=1);

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;
use Need2Talk\Services\NotificationWorkerManager;
use Need2Talk\Services\AsyncNotificationQueue;
use Need2Talk\Services\NotificationService;

/**
 * Admin Notification Worker Controller - Enterprise Galaxy V11.6
 *
 * Gestisce il controllo e monitoring dei worker notifiche via Docker/systemd
 * per la dashboard admin.
 *
 * FEATURES:
 * - Start/stop/restart 1-4 workers
 * - Real-time queue statistics
 * - Worker health monitoring
 * - Async mode toggle
 *
 * ARCHITECTURE: Docker Compose orchestration con scaling dinamico
 * Container: need2talk_worker (shared with email workers)
 */
class AdminNotificationWorkerController
{
    private NotificationWorkerManager $manager;

    /**
     * @var string Redis key for autostart configuration
     */
    private const AUTOSTART_KEY = 'need2talk:workers:notification:autostart';

    /**
     * @var string Redis key for default worker count
     */
    private const AUTOSTART_COUNT_KEY = 'need2talk:workers:notification:autostart_count';

    public function __construct()
    {
        $this->manager = new NotificationWorkerManager();
    }

    /**
     * Get worker status
     */
    public function getStatus(): array
    {
        try {
            $status = $this->manager->getStatus();

            // Add async mode status
            $status['async_enabled'] = NotificationService::isAsyncEnabled();

            // Get detailed queue stats
            $queue = new AsyncNotificationQueue();
            $queueStats = $queue->getStats();

            $status['queue_details'] = [
                'pending' => $queueStats['pending'] ?? 0,
                'processing' => $queueStats['processing'] ?? 0,
                'failed' => $queueStats['failed'] ?? 0,
                'dead_letter' => $queueStats['dead_letter'] ?? 0,
                'metrics' => $queueStats['metrics'] ?? [],
                'active_workers' => $queueStats['workers'] ?? [],
            ];

            $status['timestamp'] = date('Y-m-d H:i:s');

            return $status;

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get notification worker status', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'workers' => ['running' => 0, 'status' => 'error'],
                'async_enabled' => NotificationService::isAsyncEnabled(),
            ];
        }
    }

    /**
     * Start notification workers
     * POST /api/notification-workers/start
     * Body: { "count": 2 }
     */
    public function start(): void
    {
        header('Content-Type: application/json');

        try {
            // Parse JSON body
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $count = min(max((int)($input['count'] ?? 2), 1), 4);

            $result = $this->manager->startWorkers($count);

            if ($result['success']) {
                Logger::security('info', 'ADMIN: Notification workers started', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'workers_started' => $result['started'] ?? 0,
                    'workers_requested' => $count,
                ]);
            }

            echo json_encode($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to start notification workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop all notification workers
     * POST /api/notification-workers/stop
     */
    public function stop(): void
    {
        header('Content-Type: application/json');

        try {
            $result = $this->manager->stopWorkers();

            if ($result['success']) {
                Logger::security('warning', 'ADMIN: Notification workers stopped', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                ]);
            }

            echo json_encode($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to stop notification workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Restart notification workers
     * POST /api/notification-workers/restart
     * Body: { "count": 2 }
     */
    public function restart(): void
    {
        header('Content-Type: application/json');

        try {
            // Parse JSON body
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $count = min(max((int)($input['count'] ?? 2), 1), 4);

            $result = $this->manager->restartWorkers($count);

            if ($result['success']) {
                Logger::security('info', 'ADMIN: Notification workers restarted', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'workers_count' => $count,
                ]);
            }

            echo json_encode($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to restart notification workers', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enable async notification processing
     * POST /api/notification-workers/enable-async
     */
    public function enableAsync(): void
    {
        header('Content-Type: application/json');

        try {
            NotificationService::enableAsync();

            Logger::security('info', 'ADMIN: Async notifications enabled', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Async notification processing enabled',
                'async_enabled' => true,
            ]);

        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Disable async notification processing (fallback to sync)
     * POST /api/notification-workers/disable-async
     */
    public function disableAsync(): void
    {
        header('Content-Type: application/json');

        try {
            NotificationService::disableAsync();

            Logger::security('warning', 'ADMIN: Async notifications disabled', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'note' => 'Notifications will be processed synchronously',
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Async notification processing disabled. Notifications will be processed synchronously.',
                'async_enabled' => false,
            ]);

        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear all notification queues (emergency action)
     * POST /api/notification-workers/clear-queues
     */
    public function clearQueues(): void
    {
        header('Content-Type: application/json');

        try {
            $queue = new AsyncNotificationQueue();
            $result = $queue->clearQueues();

            if ($result) {
                Logger::security('critical', 'ADMIN: Notification queues cleared', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'action' => 'emergency_queue_clear',
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'All notification queues have been cleared',
                ]);
                return;
            }

            echo json_encode([
                'success' => false,
                'error' => 'Failed to clear queues',
            ]);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to clear notification queues', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get queue statistics for dashboard
     * GET /api/notification-workers/queue-stats
     */
    public function getQueueStats(): void
    {
        header('Content-Type: application/json');

        try {
            $queue = new AsyncNotificationQueue();
            $stats = $queue->getStats();

            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get monitoring output for CLI/dashboard
     * GET /api/notification-workers/monitoring
     */
    public function getMonitoringOutput(): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->manager->getMonitoringOutput());
    }

    /**
     * Process failed queue manually (retry failed notifications)
     * POST /api/notification-workers/process-failed
     * Body: { "limit": 50 }
     */
    public function processFailedQueue(): void
    {
        header('Content-Type: application/json');

        try {
            // Parse JSON body
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $limit = min(max((int)($input['limit'] ?? 50), 1), 500);

            $queue = new AsyncNotificationQueue();
            $processed = $queue->processFailedQueue($limit);

            Logger::info('ADMIN: Manually processed failed notification queue', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'processed' => $processed,
                'limit' => $limit,
            ]);

            echo json_encode([
                'success' => true,
                'processed' => $processed,
                'message' => "Processed {$processed} failed notifications",
            ]);

        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Scale workers (change number of running workers)
     * POST /api/notification-workers/scale
     * Body: { "count": 2 }
     */
    public function scale(): void
    {
        header('Content-Type: application/json');

        try {
            // Parse JSON body
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $count = min(max((int)($input['count'] ?? 2), 1), 4);

            $currentCount = $this->manager->getRunningWorkersCount();

            if ($count === $currentCount) {
                echo json_encode([
                    'success' => true,
                    'message' => "Already running {$count} workers",
                    'workers' => $count,
                ]);
                return;
            }

            if ($count > $currentCount) {
                // Start additional workers
                $toStart = $count - $currentCount;
                $result = $this->manager->startWorkers($toStart);
            } else {
                // Need to restart with fewer workers
                $result = $this->manager->restartWorkers($count);
            }

            Logger::security('info', 'ADMIN: Notification workers scaled', [
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                'from' => $currentCount,
                'to' => $count,
            ]);

            echo json_encode($result);

        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get autostart status
     * ENTERPRISE GALAXY V11.6: Autostart configuration stored in Redis
     */
    public function getAutostartStatus(): array
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('default');
            $enabled = $redis->get(self::AUTOSTART_KEY) === '1';
            $workerCount = (int)($redis->get(self::AUTOSTART_COUNT_KEY) ?: 2);

            $result = [
                'success' => true,
                'autostart_enabled' => $enabled,
                'autostart_worker_count' => $workerCount,
            ];
        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get notification autostart status', [
                'error' => $e->getMessage(),
            ]);

            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'autostart_enabled' => false,
                'autostart_worker_count' => 2,
            ];
        }

        // Output JSON for API calls
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }

        return $result;
    }

    /**
     * Set autostart configuration
     * ENTERPRISE GALAXY V11.6: Enable/disable autostart with worker count
     */
    public function setAutostart(): void
    {
        header('Content-Type: application/json');

        try {
            // Handle various boolean representations: "1", "true", "on", true
            $enabledRaw = $_POST['enabled'] ?? '0';
            $enabled = in_array($enabledRaw, ['1', 'true', 'on', true], true) || $enabledRaw === 1;
            $workerCount = min(max((int)($_POST['worker_count'] ?? 2), 1), 4);

            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('default');

            if ($enabled) {
                // Enable autostart
                $redis->set(self::AUTOSTART_KEY, '1');
                $redis->set(self::AUTOSTART_COUNT_KEY, (string)$workerCount);

                // Start workers immediately if not running
                $status = $this->manager->getStatus();
                if (($status['workers']['running'] ?? 0) === 0) {
                    $this->manager->startWorkers($workerCount);
                }

                Logger::security('info', 'ADMIN: Notification workers autostart ENABLED', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'worker_count' => $workerCount,
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => "Autostart enabled with {$workerCount} workers",
                    'autostart_enabled' => true,
                    'autostart_worker_count' => $workerCount,
                ]);
            } else {
                // Disable autostart (but don't stop running workers)
                $redis->set(self::AUTOSTART_KEY, '0');

                Logger::security('warning', 'ADMIN: Notification workers autostart DISABLED', [
                    'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
                    'note' => 'Running workers not stopped',
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Autostart disabled. Running workers will continue until they finish.',
                    'autostart_enabled' => false,
                    'autostart_worker_count' => $workerCount,
                ]);
            }
        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to set notification autostart', [
                'error' => $e->getMessage(),
                'admin_user' => $_SESSION['admin_id'] ?? 'unknown',
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if autostart is enabled (for startup scripts)
     */
    public static function isAutostartEnabled(): bool
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('default');
            return $redis->get(self::AUTOSTART_KEY) === '1';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get autostart worker count (for startup scripts)
     */
    public static function getAutostartWorkerCount(): int
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('default');
            return (int)($redis->get(self::AUTOSTART_COUNT_KEY) ?: 2);
        } catch (\Exception $e) {
            return 2;
        }
    }
}
