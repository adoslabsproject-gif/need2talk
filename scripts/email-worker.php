#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Email Queue Worker - Enterprise Grade
 *
 * Background worker per processare la queue email asincrona
 * Progettato per gestire 100k+ utenti simultanei senza blocchi
 *
 * Usage:
 * php scripts/email-worker.php [--batch-size=100] [--sleep=5] [--max-runtime=3600]
 *
 * Monitoring:
 * - Worker heartbeat in Redis
 * - Metrics collection in real-time
 * - Automatic restart su errori fatali
 */

// ENTERPRISE: Long-running process configuration for 100k+ concurrent users
set_time_limit(0);                    // Remove execution time limit
ini_set('max_execution_time', 0);     // Ensure no execution time limit
ini_set('memory_limit', '256M');      // Increased memory for enterprise scale
ignore_user_abort(true);              // Continue processing if user disconnects

// ENTERPRISE TIPS: Force Italian timezone for local consistency
date_default_timezone_set('Europe/Rome');
ini_set('date.timezone', 'Europe/Rome');

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

// Bootstrap application
define('APP_ROOT', dirname(__DIR__));

// ENTERPRISE: Override bootstrap PHP settings for long-running worker process
if (function_exists('ini_set')) {
    ini_set('max_execution_time', '0');     // Disable execution time limit completely
    ini_set('memory_limit', '512M');       // Increase memory for enterprise scale
    ini_set('max_input_time', '0');        // No input timeout
}

require_once APP_ROOT.'/app/bootstrap.php';

use Need2Talk\Services\AsyncEmailQueue;
use Need2Talk\Services\Logger;
use Need2Talk\Core\EnterpriseRedisManager;

/**
 * Enterprise Email Worker Class
 */
class EmailWorker
{
    private ?AsyncEmailQueue $emailQueue = null; // ENTERPRISE: Lazy initialization
    private bool $running = true;
    private string $workerId;
    private array $config;
    private int $processedCount = 0;
    private int $errorCount = 0;
    private float $startTime;

    // ⭐ ENTERPRISE TIPS: Redis connection for logging
    private ?\Redis $redis = null;

    // ENTERPRISE: Memory leak prevention
    private int $lastMemoryCheck = 0;
    private int $connectionRecycles = 0;
    private float $lastConnectionRecycle = 0;
    private array $memoryHistory = [];

    // ENTERPRISE: Hard limits for enterprise scalability
    private const MEMORY_LEAK_THRESHOLD_MB = 128;
    private const CONNECTION_RECYCLE_INTERVAL = 300; // 5 minutes
    private const MEMORY_CHECK_INTERVAL = 50; // Every 50 operations
    private const MAX_MEMORY_HISTORY = 10;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 100,
            'sleep_seconds' => 5,
            'max_runtime' => 3600, // 1 hour
            'max_errors' => 50,     // Max errors before restart
            'memory_limit' => '256M',
        ], $config);

        // ENTERPRISE TIPS: Use provided worker_id or generate unified format
        if (! empty($this->config['worker_id'])) {
            $this->workerId = $this->config['worker_id'];
        } else {
            // ENTERPRISE: Generate unified format matching AsyncEmailQueue
            $this->workerId = 'worker_'.uniqid().'_'.getmypid();
        }

        $this->startTime = microtime(true);

        // ENTERPRISE: Set hard memory limit for worker process
        ini_set('memory_limit', $this->config['memory_limit']);

        // ENTERPRISE: Initialize tracking variables
        $this->lastMemoryCheck = time();
        $this->lastConnectionRecycle = microtime(true);

        // ENTERPRISE: Email queue initialized lazily to prevent connection issues
        // $this->emailQueue will be created on first use with fresh connections

        // Setup signal handlers for graceful shutdown
        $this->setupSignalHandlers();

        Logger::email('info', 'EMAIL WORKER: Worker started', [
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'config' => $this->config,
            'start_time' => date('Y-m-d H:i:s'),
            'php_memory_limit' => ini_get('memory_limit'),
        ]);
    }

    /**
     * ENTERPRISE: Get email queue with connection recycling and memory management
     */
    private function getEmailQueue(): AsyncEmailQueue
    {
        $currentTime = microtime(true);

        // ENTERPRISE: Force connection recycle every 5 minutes to prevent Redis "went away"
        if ($this->emailQueue === null ||
            ($currentTime - $this->lastConnectionRecycle) > self::CONNECTION_RECYCLE_INTERVAL) {

            $memoryBefore = memory_get_usage(true);

            if ($this->emailQueue !== null) {
                    Logger::email('debug', 'ENTERPRISE EMAIL WORKER: Recycling email queue connection', [
                        'worker_id' => $this->workerId,
                        'recycle_count' => ++$this->connectionRecycles,
                        'interval_seconds' => round($currentTime - $this->lastConnectionRecycle, 2),
                        'reason' => 'preventive_connection_refresh',
                        'memory_usage' => $this->getMemoryUsage(),
                        'memory_before_cleanup_mb' => round($memoryBefore / 1024 / 1024, 2),
                    ]);

                // ⭐ ENTERPRISE TIPS: Explicit cleanup of old connections before creating new ones
                if ($this->emailQueue !== null) {
                    // Force garbage collection of the old AsyncEmailQueue instance
                    unset($this->emailQueue);
                }
                $this->emailQueue = null; // Explicit null before recreating
            }

            // ⭐ ENTERPRISE TIPS: Also cleanup Redis connection if available
            if ($this->redis !== null) {
                try {
                    $this->redis->close();
                    $this->redis = null;
                } catch (\Exception $e) {
                        Logger::email('debug', 'ENTERPRISE EMAIL WORKER: Redis cleanup warning during recycle', [
                            'error' => $e->getMessage(),
                        ]);
                }
            }

            // ⭐ ENTERPRISE V11.11: Reset database connection pool to prevent PostgreSQL connection leak
            // TrackedPDO + DebugbarService keep references that prevent auto-release
            try {
                \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->resetPool();
            } catch (\Exception $e) {
                // Silent fail - pool reset is not critical
            }

            // Force garbage collection after cleanup
            gc_collect_cycles();

            // ENTERPRISE: Create fresh AsyncEmailQueue with new Redis connections
            $this->emailQueue = new AsyncEmailQueue();

            // Recreate Redis connection for logging
            $this->redis = EnterpriseRedisManager::getInstance()->getConnection('L3_logging');
            $this->lastConnectionRecycle = $currentTime;

            // ENTERPRISE: No centralized logging for routine connection recycling (spam prevention)
            // Memory stats are tracked in worker monitoring if needed
        }

        return $this->emailQueue;
    }

    /**
     * ENTERPRISE: Memory leak detection and prevention
     */
    private function checkMemoryLeak(): bool
    {
        $currentMemoryMB = memory_get_usage(true) / 1024 / 1024;

        // Add to history
        $this->memoryHistory[] = $currentMemoryMB;
        if (count($this->memoryHistory) > self::MAX_MEMORY_HISTORY) {
            array_shift($this->memoryHistory);
        }

        // ENTERPRISE: Detect memory leak pattern
        if (count($this->memoryHistory) >= 5) {
            $trend = 0;
            for ($i = 1; $i < count($this->memoryHistory); $i++) {
                if ($this->memoryHistory[$i] > $this->memoryHistory[$i - 1]) {
                    $trend++;
                }
            }

            // If memory consistently increases and exceeds threshold
            if ($trend >= 4 && $currentMemoryMB > self::MEMORY_LEAK_THRESHOLD_MB) {
                    Logger::email('critical', 'ENTERPRISE EMAIL WORKER: Memory leak detected - Worker restart required', [
                        'worker_id' => $this->workerId,
                        'current_memory_mb' => round($currentMemoryMB, 2),
                        'threshold_mb' => self::MEMORY_LEAK_THRESHOLD_MB,
                        'memory_history' => array_map(function ($m) { return round($m, 2); }, $this->memoryHistory),
                        'trend_score' => $trend,
                        'processed_count' => $this->processedCount,
                        'action' => 'graceful_shutdown_required',
                    ]);

                return true; // Memory leak detected
            }
        }

        // ENTERPRISE: Hard memory limit check
        if ($currentMemoryMB > self::MEMORY_LEAK_THRESHOLD_MB) {
                Logger::email('warning', 'ENTERPRISE EMAIL WORKER: Memory threshold exceeded - Worker restart', [
                    'worker_id' => $this->workerId,
                    'current_memory_mb' => round($currentMemoryMB, 2),
                    'threshold_mb' => self::MEMORY_LEAK_THRESHOLD_MB,
                    'processed_count' => $this->processedCount,
                    'action' => 'graceful_shutdown',
                ]);

            return true;
        }

        return false;
    }

    /**
     * ENTERPRISE: Force garbage collection and resource cleanup
     */
    private function performEnterpriseCleanup(): void
    {
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            $collected = gc_collect_cycles();
            if ($collected > 0) {
                    Logger::email('debug', 'ENTERPRISE EMAIL WORKER: Garbage collection performed', [
                        'worker_id' => $this->workerId,
                        'cycles_collected' => $collected,
                        'memory_before' => round(memory_get_usage(true) / 1024 / 1024, 2),
                        'memory_after' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    ]);
            }
        }

        // ENTERPRISE: Clear any object caches or buffers
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Force memory cleanup
        if (function_exists('memory_get_usage')) {
            $memoryBefore = memory_get_usage(true);
            gc_mem_caches();
            $memoryAfter = memory_get_usage(true);

            if ($memoryBefore > $memoryAfter) {
                    Logger::email('debug', 'ENTERPRISE EMAIL WORKER: Memory cache cleanup successful', [
                        'worker_id' => $this->workerId,
                        'memory_freed_mb' => round(($memoryBefore - $memoryAfter) / 1024 / 1024, 2),
                    ]);
            }
        }
    }

    /**
     * Main worker loop
     */
    public function run(): void
    {
        // ENTERPRISE: Worker lifecycle with enterprise system integration
            Logger::email('info', 'EMAIL WORKER: Worker entering main loop', [
                'worker_id' => $this->workerId,
                'batch_size' => $this->config['batch_size'],
                'worker_type' => 'enterprise_email_async_worker',
                'enterprise_integrations' => [
                    'redis_L1_cache' => class_exists('Need2Talk\\Core\\EnterpriseL1Cache'),
                    'database_pool' => class_exists('Need2Talk\\Services\\EnterpriseSecureDatabasePool'),
                    'csrf_middleware' => class_exists('Need2Talk\\Middleware\\CsrfMiddleware'),
                    'logging_system' => true,
                    'monitoring_enabled' => true,
                ],
            ]);

        while ($this->running && $this->shouldContinueRunning()) {
            try {
                // ENTERPRISE: Reset execution time limit each iteration for long-running processes
                set_time_limit(0); // No time limit for enterprise worker processes

                // ENTERPRISE: Get email queue with connection recycling
                $emailQueue = $this->getEmailQueue();

                // Process batch of emails
                $processed = $emailQueue->processQueue($this->config['batch_size']);
                $this->processedCount += $processed;

                if ($processed > 0) {
                    // ENTERPRISE: Log at WARNING level so it appears in production (global level is WARNING)
                    Logger::email('warning', 'EMAIL WORKER: Email batch processed', [
                        'worker_id' => $this->workerId,
                        'batch_processed' => $processed,
                        'total_processed' => $this->processedCount,
                        'memory_usage' => $this->getMemoryUsage(),
                    ]);
                }

                // ENTERPRISE GALAXY LEVEL: TRIPLE-LAYER RETRY SYSTEM
                // Process failed emails from BOTH Redis queue and database every 10 cycles (~20 seconds)
                static $cycleCounter = 0;
                if (++$cycleCounter >= 10) {
                    // LAYER 1: Redis failed queue (fast retry with exponential backoff)
                    try {
                        $redisRetried = $emailQueue->processFailedQueueRetry(100);
                        if ($redisRetried > 0) {
                            Logger::email('info', 'EMAIL WORKER GALAXY: Processed failed emails from Redis queue', [
                                'worker_id' => $this->workerId,
                                'redis_retried' => $redisRetried,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Logger::email('error', 'EMAIL WORKER GALAXY: Error processing Redis failed queue', [
                            'worker_id' => $this->workerId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // LAYER 2: Database failed_emails table (persistent retry for max-attempts failures)
                    try {
                        $dbRetried = $emailQueue->processFailedEmailsFromDatabase(10);
                        if ($dbRetried > 0) {
                            Logger::email('info', 'EMAIL WORKER GALAXY: Processed failed emails from database', [
                                'worker_id' => $this->workerId,
                                'db_retried' => $dbRetried,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Logger::email('error', 'EMAIL WORKER GALAXY: Error processing database failed emails', [
                            'worker_id' => $this->workerId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $cycleCounter = 0;  // Reset counter
                }

                // Check queue size for monitoring
                $queueSize = $emailQueue->getQueueSize();
                if ($queueSize > 1000) {
                        Logger::email('warning', 'EMAIL WORKER: Large email queue detected', [
                            'worker_id' => $this->workerId,
                            'queue_size' => $queueSize,
                            'recommendation' => 'consider_scaling_workers',
                        ]);
                }

                // ENTERPRISE TIPS: AsyncEmailQueue now handles progressive backoff internally
                // Sleep only handled by AsyncEmailQueue progressive backoff system for efficiency
                // Removed fixed sleep to allow progressive backoff: 1s -> 2s -> 4s -> 8s -> 16s -> 60s max

                // ENTERPRISE: Memory leak detection and prevention
                if ($this->processedCount > 0 && $this->processedCount % self::MEMORY_CHECK_INTERVAL === 0) {
                    if ($this->checkMemoryLeak()) {
                            Logger::email('critical', 'EMAIL WORKER: Memory leak detected - Shutting down worker', [
                                'worker_id' => $this->workerId,
                                'processed_count' => $this->processedCount,
                                'action' => 'graceful_shutdown_for_restart',
                            ]);
                        $this->running = false;
                        break;
                    }
                }

                // ENTERPRISE: Periodic cleanup and maintenance
                if ($this->processedCount % 25 === 0) { // More frequent cleanup
                    $this->performEnterpriseCleanup();
                    $this->performMaintenanceTasks();
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
                Logger::email('info', 'EMAIL WORKER: Worker reached max runtime, shutting down', [
                    'worker_id' => $this->workerId,
                    'runtime_seconds' => $runtime,
                    'max_runtime' => $this->config['max_runtime'],
                ]);

            return false;
        }

        // Check error limit
        if ($this->errorCount > $this->config['max_errors']) {
                Logger::email('error', 'EMAIL WORKER: Worker reached max error count, shutting down', [
                    'worker_id' => $this->workerId,
                    'error_count' => $this->errorCount,
                    'max_errors' => $this->config['max_errors'],
                ]);

            return false;
        }

        // Check memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit($this->config['memory_limit']);
        if ($memoryUsage > $memoryLimit * 0.9) { // 90% of limit
                Logger::email('warning', 'EMAIL WORKER: Worker approaching memory limit, shutting down', [
                    'worker_id' => $this->workerId,
                    'memory_usage' => $memoryUsage,
                    'memory_limit' => $memoryLimit,
                    'usage_percentage' => round(($memoryUsage / $memoryLimit) * 100, 2),
                ]);

            return false;
        }

        return true;
    }

    /**
     * Handle worker errors
     */
    private function handleError(\Exception $e): void
    {
        $this->errorCount++;

            Logger::email('error', 'EMAIL WORKER: Worker error', [
                'worker_id' => $this->workerId,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_count' => $this->errorCount,
                'stack_trace' => $e->getTraceAsString(),
            ]);

        // Sleep longer after error to prevent rapid error loops
        sleep(min($this->errorCount, 30)); // Max 30 seconds
    }

    /**
     * Perform maintenance tasks
     */
    private function performMaintenanceTasks(): void
    {
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            $collected = gc_collect_cycles();
            if ($collected > 0) {
                    Logger::email('debug', 'EMAIL WORKER: Garbage collection performed', [
                        'worker_id' => $this->workerId,
                        'cycles_collected' => $collected,
                        'memory_after_gc' => $this->getMemoryUsage(),
                    ]);
            }
        }

        // Worker stats tracked but not logged (too verbose)
    }

    /**
     * Sleep between processing cycles
     */
    private function sleep(): void
    {
        sleep($this->config['sleep_seconds']);
    }

    /**
     * Get memory usage information
     */
    private function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'current_formatted' => $this->formatBytes(memory_get_usage(true)),
            'peak_formatted' => $this->formatBytes(memory_get_peak_usage(true)),
        ];
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
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
     * Handle shutdown signals
     */
    public function handleShutdownSignal(int $signal): void
    {
            Logger::email('info', 'EMAIL WORKER: Worker received shutdown signal', [
                'worker_id' => $this->workerId,
                'signal' => $signal,
                'processed_count' => $this->processedCount,
            ]);

        $this->running = false;
    }

    /**
     * Handle reload signals
     */
    public function handleReloadSignal(int $signal): void
    {
            Logger::email('info', 'EMAIL WORKER: Worker received reload signal', [
                'worker_id' => $this->workerId,
                'signal' => $signal,
                'action' => 'graceful_restart_after_current_batch',
            ]);

        $this->running = false;
    }

    /**
     * Graceful shutdown
     */
    private function shutdown(): void
    {
        $runtime = microtime(true) - $this->startTime;

            Logger::email('info', 'EMAIL WORKER: Worker shutting down', [
                'worker_id' => $this->workerId,
                'total_processed' => $this->processedCount,
                'total_errors' => $this->errorCount,
                'runtime_seconds' => round($runtime, 2),
                'emails_per_second' => round($this->processedCount / max($runtime, 1), 2),
                'final_memory_usage' => $this->getMemoryUsage(),
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
        echo "Email Queue Worker - Enterprise Grade\n";
        echo "Usage: php email-worker.php [options]\n\n";
        echo "Options:\n";
        echo "  --batch-size=N    Number of emails to process per batch (default: 100)\n";
        echo "  --sleep=N         Seconds to sleep between batches (default: 5)\n";
        echo "  --max-runtime=N   Maximum runtime in seconds (default: 3600)\n";
        echo "  --worker-id=ID    Custom worker ID (default: auto-generated legacy format)\n";
        echo "  --help            Show this help message\n\n";
        echo "Examples:\n";
        echo "  php email-worker.php                    # Run with defaults\n";
        echo "  php email-worker.php --batch-size=50    # Process 50 emails per batch\n";
        echo "  php email-worker.php --sleep=2          # Sleep 2 seconds between batches\n";
        exit(0);
    }

    // Build config from command line
    $config = [];
    if (isset($options['batch-size'])) {
        $config['batch_size'] = max(1, (int) $options['batch-size']);
    }
    if (isset($options['sleep'])) {
        $config['sleep_seconds'] = max(1, (int) $options['sleep']);
    }
    if (isset($options['max-runtime'])) {
        $config['max_runtime'] = max(60, (int) $options['max-runtime']);
    }

    // ENTERPRISE TIPS: Handle worker-id parameter for legacy format correlation
    if (isset($options['worker-id']) && ! empty($options['worker-id'])) {
        $config['worker_id'] = $options['worker-id'];
    }

    // Create and run worker
    try {
        $worker = new EmailWorker($config);
        $worker->run();
        exit(0);
    } catch (\Exception $e) {
            Logger::email('error', 'EMAIL WORKER: Worker failed to start', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

        echo 'ERROR: Failed to start email worker: '.$e->getMessage()."\n";
        exit(1);
    }
}

// Run the worker
main();
