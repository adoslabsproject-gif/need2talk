<?php

declare(strict_types=1);

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;
use Need2Talk\Services\Cache\ActiveUserTracker;
use Need2Talk\Services\Cache\FeedPrecomputeService;
use Need2Talk\Services\Cache\FeedInvalidationService;
use Need2Talk\Services\Cache\PartitionLockManager;
use Need2Talk\Services\Cache\PartitionedWriteBehindBuffer;
use Need2Talk\Core\EnterpriseRedisManager;

/**
 * Admin Enterprise Monitor Controller - Enterprise Galaxy V8.0
 *
 * Monitoring dashboard for distributed workers and feed pre-computation.
 *
 * @package Need2Talk\Controllers
 */
class AdminEnterpriseMonitorController
{
    /**
     * Get complete status of all Enterprise V8.0 services
     *
     * @return void (outputs JSON)
     */
    public function getStatus(): void
    {
        try {
            $status = [
                'timestamp' => date('Y-m-d H:i:s'),
                'services' => [],
                'workers' => [],
                'recommendations' => [],
            ];

            // 1. ActiveUserTracker status
            try {
                $tracker = ActiveUserTracker::getInstance();
                $topUsers = $tracker->getTopActiveUsers(10);
                $status['services']['active_user_tracker'] = [
                    'status' => 'healthy',
                    'active_users_tracked' => count($topUsers),
                    'top_users' => $topUsers,
                ];
            } catch (\Exception $e) {
                $status['services']['active_user_tracker'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }

            // 2. FeedPrecomputeService status
            try {
                $feedService = FeedPrecomputeService::getInstance();
                $queueSize = $feedService->getQueueSize();
                $circuitOpen = $feedService->isCircuitOpen();

                $status['services']['feed_precompute'] = [
                    'status' => $circuitOpen ? 'paused' : 'healthy',
                    'queue_size' => $queueSize,
                    'circuit_breaker' => $circuitOpen ? 'OPEN' : 'CLOSED',
                    'circuit_breaker_thresholds' => [
                        'open_at' => 10000,
                        'close_at' => 5000,
                    ],
                ];

                // Add recommendation if queue is getting large
                if ($queueSize > 5000) {
                    $status['recommendations'][] = "Feed queue has {$queueSize} items. Consider scaling feed_worker.";
                }
            } catch (\Exception $e) {
                $status['services']['feed_precompute'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }

            // 3. FeedInvalidationService status
            try {
                $invalidation = FeedInvalidationService::getInstance();
                $invStatus = $invalidation->getStatus();

                $status['services']['feed_invalidation'] = [
                    'status' => $invStatus['available'] ? 'healthy' : 'unavailable',
                    'metrics' => $invStatus['metrics'],
                    'config' => [
                        'dedup_window_seconds' => $invStatus['dedup_window_seconds'],
                        'max_invalidations_per_second' => $invStatus['max_invalidations_per_second'],
                        'follower_batch_size' => $invStatus['follower_batch_size'],
                    ],
                ];
            } catch (\Exception $e) {
                $status['services']['feed_invalidation'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }

            // 4. PartitionLockManager status
            try {
                $lockManager = PartitionLockManager::getInstance();
                $lockStats = $lockManager->getLockStats();

                $status['services']['partition_locks'] = [
                    'status' => 'healthy',
                    'lock_ttl_seconds' => 10,
                    'heartbeat_interval_seconds' => 3,
                    'partitions' => 16,
                    'active_locks' => $lockStats,
                ];
            } catch (\Exception $e) {
                $status['services']['partition_locks'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }

            // 5. PartitionedWriteBehindBuffer status
            try {
                $buffer = PartitionedWriteBehindBuffer::getInstance();

                $status['services']['write_behind_buffer'] = [
                    'status' => 'healthy',
                    'partitions' => 16,
                    'partition_algorithm' => 'entity_id % 16',
                ];
            } catch (\Exception $e) {
                $status['services']['write_behind_buffer'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }

            // 6. Check Docker workers status (via Redis heartbeat keys)
            try {
                $redis = EnterpriseRedisManager::getInstance()->getConnection('cache');
                if ($redis) {
                    // Check for overlay worker heartbeats
                    $overlayWorkers = $redis->keys('overlay_worker:heartbeat:*');
                    $feedWorkers = $redis->keys('feed_worker:heartbeat:*');

                    $status['workers']['overlay_workers'] = [
                        'active_count' => count($overlayWorkers),
                        'worker_ids' => array_map(fn($k) => str_replace('overlay_worker:heartbeat:', '', $k), $overlayWorkers),
                        'recommendation' => count($overlayWorkers) === 0
                            ? 'No overlay workers running. Start with: docker-compose up -d overlay_worker'
                            : null,
                    ];

                    $status['workers']['feed_workers'] = [
                        'active_count' => count($feedWorkers),
                        'worker_ids' => array_map(fn($k) => str_replace('feed_worker:heartbeat:', '', $k), $feedWorkers),
                        'recommendation' => count($feedWorkers) === 0
                            ? 'No feed workers running. Start with: docker-compose up -d feed_worker'
                            : null,
                    ];

                    // Add worker recommendations
                    if (count($overlayWorkers) === 0) {
                        $status['recommendations'][] = 'Overlay workers not running. Reactions/views may not be persisted to DB.';
                    }
                    if (count($feedWorkers) === 0) {
                        $status['recommendations'][] = 'Feed workers not running. Feed will be computed on-demand (slower).';
                    }
                }
            } catch (\Exception $e) {
                $status['workers']['error'] = $e->getMessage();
            }

            // 7. Overall health
            $healthyServices = array_filter($status['services'], fn($s) => ($s['status'] ?? 'error') === 'healthy');
            $status['overall_health'] = count($healthyServices) === count($status['services']) ? 'healthy' : 'degraded';

            if (!headers_sent()) {
                if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            }
            echo json_encode($status, JSON_PRETTY_PRINT);

        } catch (\Exception $e) {
            Logger::error('AdminEnterpriseMonitorController::getStatus failed', [
                'error' => $e->getMessage(),
            ]);

            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            http_response_code(500);
            echo json_encode([
                'error' => 'Failed to get enterprise status',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start/scale overlay workers
     *
     * @return void (outputs JSON)
     */
    public function scaleOverlayWorkers(): void
    {
        try {
            $count = (int) ($_POST['count'] ?? $_GET['count'] ?? 1);
            $count = max(1, min(8, $count)); // 1-8 workers

            // Note: This requires docker-compose access from PHP
            // In production, this would be done via an API or shell script
            $command = "cd /var/www/need2talk && docker-compose up -d --scale overlay_worker={$count} 2>&1";

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => $returnCode === 0,
                'action' => 'scale_overlay_workers',
                'target_count' => $count,
                'output' => implode("\n", $output),
                'return_code' => $returnCode,
                'note' => 'Workers will start within 10-30 seconds',
            ]);

        } catch (\Exception $e) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            http_response_code(500);
            echo json_encode([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start/scale feed workers
     *
     * @return void (outputs JSON)
     */
    public function scaleFeedWorkers(): void
    {
        try {
            $count = (int) ($_POST['count'] ?? $_GET['count'] ?? 1);
            $count = max(1, min(4, $count)); // 1-4 workers

            $command = "cd /var/www/need2talk && docker-compose up -d --scale feed_worker={$count} 2>&1";

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => $returnCode === 0,
                'action' => 'scale_feed_workers',
                'target_count' => $count,
                'output' => implode("\n", $output),
                'return_code' => $returnCode,
                'note' => 'Workers will start within 10-30 seconds',
            ]);

        } catch (\Exception $e) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            http_response_code(500);
            echo json_encode([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get worker logs
     *
     * @return void (outputs JSON)
     */
    public function getWorkerLogs(): void
    {
        try {
            $workerType = $_GET['type'] ?? 'overlay';
            $lines = (int) ($_GET['lines'] ?? 50);
            $lines = max(10, min(500, $lines));

            $service = $workerType === 'feed' ? 'feed_worker' : 'overlay_worker';
            $command = "cd /var/www/need2talk && docker-compose logs --tail={$lines} {$service} 2>&1";

            $output = [];
            exec($command, $output);

            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'worker_type' => $workerType,
                'lines_requested' => $lines,
                'logs' => implode("\n", $output),
            ]);

        } catch (\Exception $e) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            http_response_code(500);
            echo json_encode([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset feed invalidation metrics (for testing)
     *
     * @return void (outputs JSON)
     */
    public function resetMetrics(): void
    {
        try {
            $invalidation = FeedInvalidationService::getInstance();
            $invalidation->resetMetrics();

            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => true,
                'action' => 'reset_metrics',
                'message' => 'Feed invalidation metrics reset',
            ]);

        } catch (\Exception $e) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            http_response_code(500);
            echo json_encode([
                'error' => $e->getMessage(),
            ]);
        }
    }
}
