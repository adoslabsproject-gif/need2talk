#!/usr/bin/env php
<?php

/**
 * Email Worker Manager - Enterprise Grade
 *
 * Gestisce un pool di worker email per scalabilità enterprise
 * - Automatic scaling basato su queue size
 * - Health monitoring e restart automatico
 * - Load balancing e distributed processing
 *
 * Usage:
 * php scripts/email-worker-manager.php [--workers=4] [--monitor-interval=30] [--scale-threshold=500]
 */

declare(strict_types=1);

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

// Bootstrap application
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT.'/app/bootstrap.php';

use Need2Talk\Services\AsyncEmailQueue;
use Need2Talk\Services\Logger;

/**
 * Enterprise Email Worker Manager
 */
class EmailWorkerManager
{
    private array $workers = [];
    private bool $running = true;
    private string $managerId;
    private array $config;
    private AsyncEmailQueue $emailQueue;
    private float $startTime;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'min_workers' => 1,
            'max_workers' => 8,
            'initial_workers' => 2,
            'scale_up_threshold' => 500,    // Queue size to scale up
            'scale_down_threshold' => 100,  // Queue size to scale down
            'monitor_interval' => 30,       // Seconds between health checks
            'worker_timeout' => 300,        // 5 minutes worker timeout
            'restart_delay' => 5,            // Seconds before restarting failed worker
        ], $config);

        $this->managerId = 'manager-'.getmypid().'-'.uniqid();
        $this->startTime = microtime(true);
        $this->emailQueue = new AsyncEmailQueue();

        // Setup signal handlers
        $this->setupSignalHandlers();

            Logger::email('info', 'EMAIL WORKER MANAGER: Manager started', [
                'manager_id' => $this->managerId,
                'pid' => getmypid(),
                'config' => $this->config,
                'start_time' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Main manager loop
     */
    public function run(): void
    {
        // Start initial workers
        $this->startInitialWorkers();

            Logger::email('info', 'EMAIL WORKER MANAGER: Entering monitoring loop', [
                'manager_id' => $this->managerId,
                'initial_workers' => count($this->workers),
            ]);

        while ($this->running) {
            try {
                // Monitor and manage workers
                $this->monitorWorkers();
                $this->checkScaling();
                $this->logStatus();

                // Health check interval
                sleep($this->config['monitor_interval']);

            } catch (\Exception $e) {
                    Logger::email('error', 'EMAIL WORKER MANAGER: Manager error', [
                        'manager_id' => $this->managerId,
                        'error_message' => $e->getMessage(),
                        'error_file' => $e->getFile(),
                        'error_line' => $e->getLine(),
                    ]);

                sleep(10); // Wait before retrying
            }
        }

        $this->shutdown();
    }

    /**
     * Start initial worker pool
     */
    private function startInitialWorkers(): void
    {
        for ($i = 0; $i < $this->config['initial_workers']; $i++) {
            $this->startWorker();
        }

            Logger::email('info', 'EMAIL WORKER MANAGER: Initial worker pool started', [
                'manager_id' => $this->managerId,
                'workers_started' => count($this->workers),
            ]);
    }

    /**
     * Start a new worker process
     */
    private function startWorker(): bool
    {
        $workerScript = APP_ROOT.'/scripts/email-worker.php';

        if (! file_exists($workerScript)) {
                Logger::email('error', 'EMAIL WORKER MANAGER: Worker script not found', [
                    'manager_id' => $this->managerId,
                    'script_path' => $workerScript,
                ]);

            return false;
        }

        // Build worker command
        $command = sprintf(
            'php %s --batch-size=100 --sleep=5 --max-runtime=3600 > /dev/null 2>&1 &',
            escapeshellarg($workerScript)
        );

        // Start worker process
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        // Get the PID of the started process (approximation)
        $pid = $this->getLastStartedPid();

        if ($pid) {
            $workerId = 'worker-'.$pid.'-'.uniqid();
            $this->workers[$workerId] = [
                'pid' => $pid,
                'started_at' => time(),
                'last_check' => time(),
                'status' => 'running',
                'restart_count' => 0,
            ];

                Logger::email('info', 'EMAIL WORKER MANAGER: Worker started successfully', [
                    'manager_id' => $this->managerId,
                    'worker_id' => $workerId,
                    'pid' => $pid,
                    'total_workers' => count($this->workers),
                ]);

            return true;
        }

            Logger::email('error', 'EMAIL WORKER MANAGER: Failed to start worker', [
                'manager_id' => $this->managerId,
                'command' => $command,
                'return_code' => $returnCode,
            ]);

        return false;
    }

    /**
     * Get PID of last started process (approximation)
     */
    private function getLastStartedPid(): ?int
    {
        // This is a simplified approach - in production you might want a more robust method
        $pids = [];
        exec("pgrep -f 'email-worker.php'", $pids);

        if (! empty($pids)) {
            return (int) end($pids);
        }

        return null;
    }

    /**
     * Monitor worker health and restart if needed
     */
    private function monitorWorkers(): void
    {
        foreach ($this->workers as $workerId => $worker) {
            // Check if process is still running
            if (! $this->isProcessRunning($worker['pid'])) {
                    Logger::email('warning', 'EMAIL WORKER MANAGER: Worker process died', [
                        'manager_id' => $this->managerId,
                        'worker_id' => $workerId,
                        'pid' => $worker['pid'],
                        'uptime_seconds' => time() - $worker['started_at'],
                    ]);

                // Remove dead worker
                unset($this->workers[$workerId]);

                // Restart if within limits
                if (count($this->workers) < $this->config['min_workers']) {
                    sleep($this->config['restart_delay']);
                    $this->startWorker();
                }
            }
        }
    }

    /**
     * Check if process is running
     */
    private function isProcessRunning(int $pid): bool
    {
        return file_exists("/proc/$pid") || (function_exists('posix_kill') && posix_kill($pid, 0));
    }

    /**
     * Check if scaling up or down is needed
     */
    private function checkScaling(): void
    {
        $queueSize = $this->emailQueue->getQueueSize();
        $currentWorkers = count($this->workers);

        // Scale up if queue is large
        if ($queueSize > $this->config['scale_up_threshold'] && $currentWorkers < $this->config['max_workers']) {
                Logger::email('info', 'EMAIL WORKER MANAGER: Scaling up workers', [
                    'manager_id' => $this->managerId,
                    'queue_size' => $queueSize,
                    'current_workers' => $currentWorkers,
                    'threshold' => $this->config['scale_up_threshold'],
                ]);

            $this->startWorker();
        }

        // Scale down if queue is small (but keep minimum)
        elseif ($queueSize < $this->config['scale_down_threshold'] && $currentWorkers > $this->config['min_workers']) {
                Logger::email('info', 'EMAIL WORKER MANAGER: Scaling down workers', [
                    'manager_id' => $this->managerId,
                    'queue_size' => $queueSize,
                    'current_workers' => $currentWorkers,
                    'threshold' => $this->config['scale_down_threshold'],
                ]);

            $this->stopOldestWorker();
        }
    }

    /**
     * Stop the oldest worker for scale down
     */
    private function stopOldestWorker(): void
    {
        if (empty($this->workers)) {
            return;
        }

        // Find oldest worker
        $oldestWorkerId = null;
        $oldestTime = time();

        foreach ($this->workers as $workerId => $worker) {
            if ($worker['started_at'] < $oldestTime) {
                $oldestTime = $worker['started_at'];
                $oldestWorkerId = $workerId;
            }
        }

        if ($oldestWorkerId) {
            $worker = $this->workers[$oldestWorkerId];

            // Send SIGTERM for graceful shutdown
            if (function_exists('posix_kill')) {
                posix_kill($worker['pid'], SIGTERM);
            } else {
                exec("kill {$worker['pid']}");
            }

                Logger::email('info', 'EMAIL WORKER MANAGER: Worker stopped for scaling', [
                    'manager_id' => $this->managerId,
                    'worker_id' => $oldestWorkerId,
                    'pid' => $worker['pid'],
                    'uptime_seconds' => time() - $worker['started_at'],
                ]);

            unset($this->workers[$oldestWorkerId]);
        }
    }

    /**
     * Log current status
     */
    private function logStatus(): void
    {
        $queueStats = $this->emailQueue->getStats();
        $runtime = microtime(true) - $this->startTime;

            Logger::email('debug', 'EMAIL WORKER MANAGER: Manager status', [
                'manager_id' => $this->managerId,
                'active_workers' => count($this->workers),
                'queue_stats' => $queueStats,
                'runtime_seconds' => round($runtime, 2),
                'worker_pids' => array_column($this->workers, 'pid'),
                'scaling_config' => [
                    'min_workers' => $this->config['min_workers'],
                    'max_workers' => $this->config['max_workers'],
                    'scale_up_threshold' => $this->config['scale_up_threshold'],
                    'scale_down_threshold' => $this->config['scale_down_threshold'],
                ],
            ]);
    }

    /**
     * Setup signal handlers
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
            Logger::email('info', 'EMAIL WORKER MANAGER: Manager received shutdown signal', [
                'manager_id' => $this->managerId,
                'signal' => $signal,
                'active_workers' => count($this->workers),
            ]);

        $this->running = false;
    }

    /**
     * Handle reload signals
     */
    public function handleReloadSignal(int $signal): void
    {
            Logger::email('info', 'EMAIL WORKER MANAGER: Manager received reload signal', [
                'manager_id' => $this->managerId,
                'signal' => $signal,
                'action' => 'graceful_restart_all_workers',
            ]);

        // Gracefully restart all workers
        $this->restartAllWorkers();
    }

    /**
     * Restart all workers gracefully
     */
    private function restartAllWorkers(): void
    {
            Logger::email('info', 'EMAIL WORKER MANAGER: Restarting all workers', [
                'manager_id' => $this->managerId,
                'workers_to_restart' => count($this->workers),
            ]);

        // Stop all workers
        foreach ($this->workers as $workerId => $worker) {
            if (function_exists('posix_kill')) {
                posix_kill($worker['pid'], SIGTERM);
            } else {
                exec("kill {$worker['pid']}");
            }
        }

        // Wait for graceful shutdown
        sleep(5);

        // Clear worker list
        $this->workers = [];

        // Start fresh workers
        $this->startInitialWorkers();
    }

    /**
     * Graceful shutdown
     */
    private function shutdown(): void
    {
            Logger::email('info', 'EMAIL WORKER MANAGER: Manager shutting down', [
                'manager_id' => $this->managerId,
                'workers_to_stop' => count($this->workers),
            ]);

        // Stop all workers
        foreach ($this->workers as $workerId => $worker) {
                Logger::email('info', 'EMAIL WORKER MANAGER: Stopping worker', [
                    'manager_id' => $this->managerId,
                    'worker_id' => $workerId,
                    'pid' => $worker['pid'],
                ]);

            if (function_exists('posix_kill')) {
                posix_kill($worker['pid'], SIGTERM);
            } else {
                exec("kill {$worker['pid']}");
            }
        }

        // Wait for workers to shutdown
        sleep(5);

            Logger::email('info', 'EMAIL WORKER MANAGER: Manager shutdown complete', [
                'manager_id' => $this->managerId,
                'runtime_seconds' => round(microtime(true) - $this->startTime, 2),
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
        'workers:',
        'monitor-interval:',
        'scale-threshold:',
        'help',
    ]);

    if (isset($options['help'])) {
        echo "Email Worker Manager - Enterprise Grade\n";
        echo "Usage: php email-worker-manager.php [options]\n\n";
        echo "Options:\n";
        echo "  --workers=N           Initial number of workers (default: 2)\n";
        echo "  --monitor-interval=N  Health check interval in seconds (default: 30)\n";
        echo "  --scale-threshold=N   Queue size to trigger scaling (default: 500)\n";
        echo "  --help               Show this help message\n\n";
        echo "Examples:\n";
        echo "  php email-worker-manager.php                    # Run with defaults\n";
        echo "  php email-worker-manager.php --workers=4        # Start with 4 workers\n";
        echo "  php email-worker-manager.php --scale-threshold=1000 # Scale at 1000 queue size\n";
        exit(0);
    }

    // Build config from command line
    $config = [];
    if (isset($options['workers'])) {
        $workers = max(1, (int) $options['workers']);
        $config['initial_workers'] = $workers;
        $config['min_workers'] = max(1, $workers - 1);
        $config['max_workers'] = $workers * 2;
    }
    if (isset($options['monitor-interval'])) {
        $config['monitor_interval'] = max(10, (int) $options['monitor-interval']);
    }
    if (isset($options['scale-threshold'])) {
        $config['scale_up_threshold'] = max(100, (int) $options['scale-threshold']);
        $config['scale_down_threshold'] = (int) ($config['scale_up_threshold'] * 0.2);
    }

    // Create and run manager
    try {
        $manager = new EmailWorkerManager($config);
        $manager->run();
        exit(0);
    } catch (\Exception $e) {
            Logger::email('error', 'EMAIL WORKER MANAGER: Manager failed to start', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

        echo 'ERROR: Failed to start email worker manager: '.$e->getMessage()."\n";
        exit(1);
    }
}

// Run the manager
main();
