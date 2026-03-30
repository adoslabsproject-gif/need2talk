<?php

declare(strict_types=1);

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;
use Need2Talk\Services\Cache\OverlayFlushService;
use Need2Talk\Services\Cache\WriteBehindBuffer;

/**
 * AdminOverlayWorkerController - Enterprise Overlay Worker Management
 *
 * ENTERPRISE GALAXY V4.3 (2025-12)
 *
 * Dedicated admin controller for managing Overlay Flush Workers.
 * Overlay workers flush write-behind cache (reactions, plays, comments) to PostgreSQL.
 *
 * Architecture:
 * - Workers run in Docker: docker compose up -d --scale overlay_worker=N
 * - Adaptive scheduling based on buffer activity level (IDLE/LOW/NORMAL/HIGH/PEAK)
 * - 16 partitions for distributed processing (up to 16 workers)
 * - Redis heartbeats: overlay_worker:heartbeat:{worker_id}
 *
 * @package Need2Talk\Controllers
 */
class AdminOverlayWorkerController
{
    /**
     * @var int Maximum number of overlay workers allowed
     */
    private const MAX_WORKERS = 8;

    /**
     * @var string Redis key prefix for worker heartbeats
     */
    private const HEARTBEAT_PREFIX = 'overlay_worker:heartbeat:';

    /**
     * @var string Redis key for autostart configuration
     */
    private const AUTOSTART_KEY = 'need2talk:workers:overlay:autostart';

    /**
     * Get current overlay worker status
     *
     * Returns comprehensive status including:
     * - Active worker count and heartbeats
     * - Queue health (reactions, plays, comments pending)
     * - Activity level (IDLE/LOW/NORMAL/HIGH/PEAK)
     * - Recent logs
     *
     * @return array Status data for view rendering
     */
    public function getStatus(): array
    {
        $status = [
            'active' => false,
            'workers' => 0,
            'heartbeats' => [],
            'queue' => [
                'reactions_pending' => 0,
                'plays_pending' => 0,
                'comments_pending' => 0,
                'total_pending' => 0,
            ],
            'activity_level' => 'unknown',
            'health' => 'unknown',
            'queue_health' => null,
            'recent_logs' => [],
        ];

        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                $status['health'] = 'redis_unavailable';
                return $status;
            }

            // Get worker heartbeats
            $heartbeatKeys = $redis->keys(self::HEARTBEAT_PREFIX . '*');
            $status['workers'] = count($heartbeatKeys);
            $status['active'] = $status['workers'] > 0;

            foreach ($heartbeatKeys as $key) {
                $data = $redis->get($key);
                if ($data) {
                    $heartbeat = json_decode($data, true);
                    if ($heartbeat) {
                        $status['heartbeats'][] = [
                            'worker_id' => str_replace(self::HEARTBEAT_PREFIX, '', $key),
                            'last_heartbeat' => $heartbeat['timestamp'] ?? time(),
                            'partition' => $heartbeat['partition'] ?? null,
                            'metrics' => [
                                'flush_count' => $heartbeat['flush_count'] ?? 0,
                                'reactions_flushed' => $heartbeat['reactions_flushed'] ?? 0,
                                'plays_flushed' => $heartbeat['plays_flushed'] ?? 0,
                                'memory_mb' => $heartbeat['memory_mb'] ?? 0,
                            ],
                        ];
                    }
                }
            }

            // Get buffer status
            $buffer = WriteBehindBuffer::getInstance();
            $bufferStatus = $buffer->getBufferStatus();

            $status['queue']['reactions_pending'] = $bufferStatus['reactions_pending'] ?? 0;
            $status['queue']['plays_pending'] = $bufferStatus['plays_pending'] ?? 0;
            $status['queue']['comments_pending'] = $bufferStatus['comments_pending'] ?? 0;
            $status['queue']['total_pending'] = $status['queue']['reactions_pending']
                + $status['queue']['plays_pending']
                + $status['queue']['comments_pending'];

            // Determine activity level
            $total = $status['queue']['total_pending'];
            if ($total === 0) {
                $status['activity_level'] = 'IDLE';
            } elseif ($total < 50) {
                $status['activity_level'] = 'LOW';
            } elseif ($total < 100) {
                $status['activity_level'] = 'NORMAL';
            } elseif ($total < 500) {
                $status['activity_level'] = 'HIGH';
            } else {
                $status['activity_level'] = 'PEAK';
            }

            // Get queue health from OverlayFlushService
            try {
                $flushService = OverlayFlushService::getInstance();
                $status['queue_health'] = $flushService->checkQueueHealth();
                $status['health'] = $status['queue_health']['status'] ?? 'unknown';
            } catch (\Exception $e) {
                Logger::overlay('error', 'Failed to check queue health', ['error' => $e->getMessage()]);
            }

            // Get recent logs
            $status['recent_logs'] = $this->getRecentLogs(50);

        } catch (\Exception $e) {
            Logger::overlay('error', 'AdminOverlayWorkerController::getStatus failed', [
                'error' => $e->getMessage(),
            ]);
            $status['health'] = 'error';
        }

        return $status;
    }

    /**
     * Start overlay workers
     *
     * @return void JSON response
     */
    public function start(): void
    {
        try {
            $count = (int) ($_POST['worker_count'] ?? 1);
            $count = max(1, min(self::MAX_WORKERS, $count));

            // Execute docker compose scale
            $command = "cd /var/www/need2talk && docker compose up -d --scale overlay_worker={$count} 2>&1";
            $output = shell_exec($command);

            Logger::overlay('info', 'OVERLAY_WORKERS_STARTED', [
                'count' => $count,
                'output' => substr($output ?? '', 0, 500),
                'admin_user' => get_session('user_id'),
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Avviati {$count} overlay workers",
                'worker_count' => $count,
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'Failed to start overlay workers', ['error' => $e->getMessage()]);
            echo json_encode([
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stop overlay workers
     *
     * @return void JSON response
     */
    public function stop(): void
    {
        try {
            // Scale down to 0
            $command = "cd /var/www/need2talk && docker compose up -d --scale overlay_worker=0 2>&1";
            $output = shell_exec($command);

            Logger::overlay('info', 'OVERLAY_WORKERS_STOPPED', [
                'output' => substr($output ?? '', 0, 500),
                'admin_user' => get_session('user_id'),
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Overlay workers fermati',
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'Failed to stop overlay workers', ['error' => $e->getMessage()]);
            echo json_encode([
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Scale overlay workers to specified count
     *
     * @return void JSON response
     */
    public function scale(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $count = (int) ($input['worker_count'] ?? 1);
            $count = max(1, min(self::MAX_WORKERS, $count));

            // Execute docker compose scale
            $command = "cd /var/www/need2talk && docker compose up -d --scale overlay_worker={$count} 2>&1";
            $output = shell_exec($command);

            Logger::overlay('info', 'OVERLAY_WORKERS_SCALED', [
                'new_count' => $count,
                'output' => substr($output ?? '', 0, 500),
                'admin_user' => get_session('user_id'),
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Scalato a {$count} overlay workers",
                'worker_count' => $count,
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'Failed to scale overlay workers', ['error' => $e->getMessage()]);
            echo json_encode([
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto-scale workers based on queue depth
     *
     * Scaling rules:
     * - < 50 items: 1 worker
     * - 50-100 items: 2 workers
     * - 100-500 items: 4 workers
     * - > 500 items: 8 workers
     *
     * @return void JSON response
     */
    public function autoScale(): void
    {
        try {
            $buffer = WriteBehindBuffer::getInstance();
            $bufferStatus = $buffer->getBufferStatus();

            $total = ($bufferStatus['reactions_pending'] ?? 0)
                + ($bufferStatus['plays_pending'] ?? 0)
                + ($bufferStatus['comments_pending'] ?? 0);

            // Determine optimal worker count
            if ($total < 50) {
                $newCount = 1;
            } elseif ($total < 100) {
                $newCount = 2;
            } elseif ($total < 500) {
                $newCount = 4;
            } else {
                $newCount = self::MAX_WORKERS;
            }

            // Execute scaling
            $command = "cd /var/www/need2talk && docker compose up -d --scale overlay_worker={$newCount} 2>&1";
            $output = shell_exec($command);

            Logger::overlay('info', 'OVERLAY_WORKERS_AUTO_SCALED', [
                'queue_depth' => $total,
                'new_count' => $newCount,
                'output' => substr($output ?? '', 0, 500),
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Auto-scale completato: {$newCount} workers",
                'new_count' => $newCount,
                'queue_depth' => $total,
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'Failed to auto-scale overlay workers', ['error' => $e->getMessage()]);
            echo json_encode([
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Force flush overlay buffer manually
     *
     * @return void JSON response
     */
    public function forceFlush(): void
    {
        try {
            $flushService = OverlayFlushService::getInstance();
            $result = $flushService->flush();

            Logger::overlay('info', 'OVERLAY_MANUAL_FLUSH', [
                'result' => $result,
                'admin_user' => get_session('user_id'),
            ]);

            echo json_encode([
                'success' => $result['success'] ?? false,
                'message' => 'Flush completato',
                'reactions_flushed' => $result['reactions_flushed'] ?? 0,
                'plays_flushed' => $result['plays_flushed'] ?? 0,
                'comments_flushed' => ($result['comments_flushed'] ?? 0) + ($result['comments_v6_flushed'] ?? 0),
                'duration_ms' => $result['duration_ms'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'Manual flush failed', ['error' => $e->getMessage()]);
            echo json_encode([
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recent logs from overlay channel
     *
     * @param int $limit Number of log lines to retrieve
     * @return array Log lines
     */
    public function getLogs(int $limit = 100): array
    {
        return $this->getRecentLogs($limit);
    }

    /**
     * Get autostart status
     *
     * @return void JSON response
     */
    public function getAutostartStatus(): void
    {
        try {
            $redis = $this->getRedisConnection();
            $enabled = $redis ? (bool) $redis->get(self::AUTOSTART_KEY) : false;

            echo json_encode([
                'success' => true,
                'autostart_enabled' => $enabled,
            ]);

        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set autostart configuration
     *
     * @return void JSON response
     */
    public function setAutostart(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $enabled = (bool) ($input['enabled'] ?? false);

            $redis = $this->getRedisConnection();
            if (!$redis) {
                throw new \Exception('Redis connection unavailable');
            }

            if ($enabled) {
                $redis->set(self::AUTOSTART_KEY, '1');
            } else {
                $redis->del(self::AUTOSTART_KEY);
            }

            Logger::overlay('info', 'OVERLAY_AUTOSTART_CHANGED', [
                'enabled' => $enabled,
                'admin_user' => get_session('user_id'),
            ]);

            echo json_encode([
                'success' => true,
                'message' => $enabled ? 'Autostart abilitato' : 'Autostart disabilitato',
                'autostart_enabled' => $enabled,
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'Failed to set autostart', ['error' => $e->getMessage()]);
            echo json_encode([
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Redis connection
     *
     * @return \Redis|null
     */
    private function getRedisConnection(): ?\Redis
    {
        try {
            $redis = new \Redis();
            $host = get_env('REDIS_HOST', 'redis');
            $port = (int) get_env('REDIS_PORT', 6379);
            $password = get_env('REDIS_PASSWORD', '');

            $redis->connect($host, $port, 2.0);

            if (!empty($password)) {
                $redis->auth($password);
            }

            return $redis;

        } catch (\Exception $e) {
            Logger::overlay('error', 'Redis connection failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get recent overlay logs from file
     *
     * @param int $limit Number of lines
     * @return array Log lines
     */
    private function getRecentLogs(int $limit = 100): array
    {
        $logs = [];

        try {
            $logFile = APP_ROOT . '/storage/logs/overlay-' . date('Y-m-d') . '.log';

            if (!file_exists($logFile)) {
                // Try enterprise log format
                $logFile = APP_ROOT . '/storage/logs/debug_general-' . date('Y-m-d') . '.log';
            }

            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines) {
                    // Filter for overlay-related logs
                    $overlayLines = array_filter($lines, function ($line) {
                        return stripos($line, 'overlay') !== false
                            || stripos($line, 'flush') !== false
                            || stripos($line, 'buffer') !== false;
                    });

                    $logs = array_slice($overlayLines, -$limit);
                }
            }

        } catch (\Exception $e) {
            // Non-critical
        }

        return array_values($logs);
    }
}
