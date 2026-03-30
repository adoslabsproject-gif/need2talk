#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Notification Queue Worker - Enterprise Galaxy V11.6
 *
 * Background worker per processare la queue notifiche asincrona
 * Progettato per gestire 100k+ utenti simultanei senza blocchi HTTP
 *
 * FEATURES:
 * - Batch processing (50-200 notifiche per ciclo)
 * - Deduplicazione intelligente (50 reactions → 1 notifica)
 * - Connection recycling ogni 5 minuti
 * - Memory leak detection
 * - Graceful shutdown
 * - Worker heartbeat per monitoring
 *
 * Usage:
 * php scripts/notification-worker.php [--batch-size=50] [--sleep=100] [--max-runtime=3600]
 *
 * Monitoring:
 * - Worker heartbeat in Redis
 * - Metrics collection in real-time
 * - Automatic restart su errori fatali
 *
 * @package Need2Talk\Workers
 * @version 1.0.0
 */

// ENTERPRISE: Long-running process configuration
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '256M');
ignore_user_abort(true);

// Force Italian timezone
date_default_timezone_set('Europe/Rome');
ini_set('date.timezone', 'Europe/Rome');

// CLI only
if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

// Bootstrap application
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\AsyncNotificationQueue;
use Need2Talk\Services\Logger;
use Need2Talk\Core\EnterpriseRedisManager;

/**
 * Enterprise Notification Worker Class
 */
class NotificationWorker
{
    private ?AsyncNotificationQueue $queue = null;
    private bool $running = true;
    private string $workerId;
    private array $config;
    private int $processedCount = 0;
    private int $errorCount = 0;
    private float $startTime;

    // Redis connection for monitoring
    private ?\Redis $redis = null;

    // Memory management
    private int $lastMemoryCheck = 0;
    private int $connectionRecycles = 0;
    private float $lastConnectionRecycle = 0;
    private array $memoryHistory = [];

    // Enterprise constants
    private const MEMORY_LEAK_THRESHOLD_MB = 128;
    private const CONNECTION_RECYCLE_INTERVAL = 300; // 5 minutes
    private const MEMORY_CHECK_INTERVAL = 100; // Every 100 operations
    private const MAX_MEMORY_HISTORY = 10;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 50,
            'sleep_ms' => 100, // Milliseconds between batches
            'max_runtime' => 3600, // 1 hour
            'max_errors' => 50,
            'memory_limit' => '256M',
            'progressive_backoff' => true, // Enable progressive sleep when queue empty
        ], $config);

        // Generate worker ID
        if (!empty($this->config['worker_id'])) {
            $this->workerId = $this->config['worker_id'];
        } else {
            $this->workerId = 'notif_worker_' . uniqid() . '_' . getmypid();
        }

        $this->startTime = microtime(true);
        ini_set('memory_limit', $this->config['memory_limit']);

        $this->lastMemoryCheck = time();
        $this->lastConnectionRecycle = microtime(true);

        // Setup signal handlers
        $this->setupSignalHandlers();

        Logger::info('NOTIFICATION WORKER: Worker started', [
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'config' => $this->config,
            'start_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get notification queue with connection recycling
     */
    private function getQueue(): AsyncNotificationQueue
    {
        $currentTime = microtime(true);

        // Force connection recycle every 5 minutes
        if ($this->queue === null ||
            ($currentTime - $this->lastConnectionRecycle) > self::CONNECTION_RECYCLE_INTERVAL) {

            if ($this->queue !== null) {
                Logger::debug('NOTIFICATION WORKER: Recycling connections', [
                    'worker_id' => $this->workerId,
                    'recycle_count' => ++$this->connectionRecycles,
                    'interval_seconds' => round($currentTime - $this->lastConnectionRecycle, 2),
                ]);

                unset($this->queue);
                $this->queue = null;
            }

            // Cleanup Redis connection
            if ($this->redis !== null) {
                try {
                    $this->redis->close();
                    $this->redis = null;
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            // ENTERPRISE V11.11: Reset database pool to prevent connection leak
            try {
                \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->resetPool();
                gc_collect_cycles();
            } catch (\Throwable $e) {
                // Silent fail
            }

            // Create fresh queue
            $this->queue = new AsyncNotificationQueue();
            $this->redis = EnterpriseRedisManager::getInstance()->getConnection('notification_queue');
            $this->lastConnectionRecycle = $currentTime;
        }

        return $this->queue;
    }

    /**
     * Main worker loop
     */
    public function run(): void
    {
        Logger::info('NOTIFICATION WORKER: Entering main loop', [
            'worker_id' => $this->workerId,
            'batch_size' => $this->config['batch_size'],
        ]);

        // Progressive backoff state
        $emptyQueueStreak = 0;
        $maxBackoffMs = 2000; // Max 2 seconds

        while ($this->running && $this->shouldContinueRunning()) {
            try {
                // Reset execution time limit each iteration
                set_time_limit(0);

                // Get queue with connection recycling
                $queue = $this->getQueue();

                // Process batch
                $processed = $queue->processBatch($this->config['batch_size'], $this->workerId);
                $this->processedCount += $processed;

                if ($processed > 0) {
                    $emptyQueueStreak = 0;

                    Logger::info('NOTIFICATION WORKER: Batch processed', [
                        'worker_id' => $this->workerId,
                        'batch_processed' => $processed,
                        'total_processed' => $this->processedCount,
                        'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    ]);
                } else {
                    $emptyQueueStreak++;
                }

                // Process failed queue every 10 cycles
                static $cycleCounter = 0;
                if (++$cycleCounter >= 10) {
                    try {
                        $retried = $queue->processFailedQueue(50);
                        if ($retried > 0) {
                            Logger::info('NOTIFICATION WORKER: Retried failed notifications', [
                                'worker_id' => $this->workerId,
                                'retried' => $retried,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Logger::error('NOTIFICATION WORKER: Failed queue error', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $cycleCounter = 0;
                }

                // Progressive backoff when queue empty
                if ($this->config['progressive_backoff'] && $emptyQueueStreak > 0) {
                    // Exponential backoff: 100ms -> 200ms -> 400ms -> ... -> 2000ms max
                    $sleepMs = min($this->config['sleep_ms'] * pow(2, $emptyQueueStreak - 1), $maxBackoffMs);
                    usleep($sleepMs * 1000);
                } else {
                    // Normal sleep between batches
                    usleep($this->config['sleep_ms'] * 1000);
                }

                // Memory leak detection
                if ($this->processedCount > 0 && $this->processedCount % self::MEMORY_CHECK_INTERVAL === 0) {
                    if ($this->checkMemoryLeak()) {
                        Logger::warning('NOTIFICATION WORKER: Memory leak detected, shutting down', [
                            'worker_id' => $this->workerId,
                            'processed_count' => $this->processedCount,
                        ]);
                        $this->running = false;
                        break;
                    }
                }

                // Periodic cleanup
                if ($this->processedCount % 50 === 0) {
                    $this->performCleanup();
                }

            } catch (\Exception $e) {
                $this->handleError($e);
            }
        }

        $this->shutdown();
    }

    /**
     * Check if worker should continue running
     */
    private function shouldContinueRunning(): bool
    {
        // Check runtime limit
        $runtime = microtime(true) - $this->startTime;
        if ($runtime > $this->config['max_runtime']) {
            Logger::info('NOTIFICATION WORKER: Max runtime reached', [
                'worker_id' => $this->workerId,
                'runtime_seconds' => round($runtime),
                'max_runtime' => $this->config['max_runtime'],
            ]);
            return false;
        }

        // Check error limit
        if ($this->errorCount > $this->config['max_errors']) {
            Logger::error('NOTIFICATION WORKER: Max errors reached', [
                'worker_id' => $this->workerId,
                'error_count' => $this->errorCount,
            ]);
            return false;
        }

        // Check memory usage (90% threshold)
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit($this->config['memory_limit']);
        if ($memoryUsage > $memoryLimit * 0.9) {
            Logger::warning('NOTIFICATION WORKER: Memory limit approaching', [
                'worker_id' => $this->workerId,
                'memory_mb' => round($memoryUsage / 1024 / 1024, 2),
                'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check for memory leaks
     */
    private function checkMemoryLeak(): bool
    {
        $currentMemoryMB = memory_get_usage(true) / 1024 / 1024;

        $this->memoryHistory[] = $currentMemoryMB;
        if (count($this->memoryHistory) > self::MAX_MEMORY_HISTORY) {
            array_shift($this->memoryHistory);
        }

        // Detect consistent memory increase
        if (count($this->memoryHistory) >= 5) {
            $trend = 0;
            for ($i = 1; $i < count($this->memoryHistory); $i++) {
                if ($this->memoryHistory[$i] > $this->memoryHistory[$i - 1]) {
                    $trend++;
                }
            }

            if ($trend >= 4 && $currentMemoryMB > self::MEMORY_LEAK_THRESHOLD_MB) {
                return true;
            }
        }

        return $currentMemoryMB > self::MEMORY_LEAK_THRESHOLD_MB;
    }

    /**
     * Handle worker errors
     */
    private function handleError(\Exception $e): void
    {
        $this->errorCount++;

        Logger::error('NOTIFICATION WORKER: Error occurred', [
            'worker_id' => $this->workerId,
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'error_count' => $this->errorCount,
        ]);

        // Sleep after error to prevent rapid error loops
        sleep(min($this->errorCount, 30));
    }

    /**
     * Perform cleanup tasks
     */
    private function performCleanup(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtolower(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int)$limit,
        };
    }

    /**
     * Setup signal handlers for graceful shutdown
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleReloadSignal']);
        }
    }

    /**
     * Handle shutdown signal
     */
    public function handleShutdownSignal(int $signal): void
    {
        Logger::info('NOTIFICATION WORKER: Shutdown signal received', [
            'worker_id' => $this->workerId,
            'signal' => $signal,
        ]);
        $this->running = false;
    }

    /**
     * Handle reload signal
     */
    public function handleReloadSignal(int $signal): void
    {
        Logger::info('NOTIFICATION WORKER: Reload signal received', [
            'worker_id' => $this->workerId,
            'signal' => $signal,
        ]);
        $this->running = false;
    }

    /**
     * Graceful shutdown
     *
     * ENTERPRISE V11.7: Now cleans up worker heartbeat on shutdown
     */
    private function shutdown(): void
    {
        $runtime = microtime(true) - $this->startTime;

        // ENTERPRISE V11.7: Clean up heartbeat to prevent stale entries
        try {
            if ($this->queue !== null) {
                $this->queue->removeWorkerHeartbeat($this->workerId);
            }
        } catch (\Exception $e) {
            // Non-critical, ignore
        }

        Logger::info('NOTIFICATION WORKER: Shutting down', [
            'worker_id' => $this->workerId,
            'total_processed' => $this->processedCount,
            'total_errors' => $this->errorCount,
            'runtime_seconds' => round($runtime, 2),
            'rate_per_second' => round($this->processedCount / max($runtime, 1), 2),
            'final_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }
}

/**
 * Main execution
 */
function main(): void
{
    // Parse command line arguments
    $options = getopt('', [
        'batch-size:',
        'sleep:',
        'max-runtime:',
        'worker-id:',
        'help',
    ]);

    if (isset($options['help'])) {
        echo "Notification Queue Worker - Enterprise Galaxy V11.6\n";
        echo "Usage: php notification-worker.php [options]\n\n";
        echo "Options:\n";
        echo "  --batch-size=N    Notifications per batch (default: 50)\n";
        echo "  --sleep=N         Milliseconds between batches (default: 100)\n";
        echo "  --max-runtime=N   Maximum runtime in seconds (default: 3600)\n";
        echo "  --worker-id=ID    Custom worker ID\n";
        echo "  --help            Show this help message\n\n";
        echo "Examples:\n";
        echo "  php notification-worker.php                    # Run with defaults\n";
        echo "  php notification-worker.php --batch-size=100   # Process 100 per batch\n";
        echo "  php notification-worker.php --sleep=50         # 50ms between batches\n";
        exit(0);
    }

    // Build config from command line
    $config = [];
    if (isset($options['batch-size'])) {
        $config['batch_size'] = max(1, (int)$options['batch-size']);
    }
    if (isset($options['sleep'])) {
        $config['sleep_ms'] = max(10, (int)$options['sleep']);
    }
    if (isset($options['max-runtime'])) {
        $config['max_runtime'] = max(60, (int)$options['max-runtime']);
    }
    if (isset($options['worker-id'])) {
        $config['worker_id'] = $options['worker-id'];
    }

    // Create and run worker
    try {
        $worker = new NotificationWorker($config);
        $worker->run();
        exit(0);
    } catch (\Exception $e) {
        Logger::error('NOTIFICATION WORKER: Failed to start', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        echo 'ERROR: Failed to start notification worker: ' . $e->getMessage() . "\n";
        exit(1);
    }
}

// Run the worker
main();
