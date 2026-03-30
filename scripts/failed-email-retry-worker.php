#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Failed Email Retry Worker - Dual-Layer Retry System
 *
 * Processes emails that failed Redis retry (3 attempts)
 * and are stored in database for long-term retry (5 additional attempts)
 *
 * Usage:
 * php scripts/failed-email-retry-worker.php [--batch-size=50] [--sleep=300]
 *
 * This worker runs less frequently than regular email workers (every 5 minutes vs 5 seconds)
 * because it handles emails that already failed multiple times.
 */

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '256M');
ignore_user_abort(true);

date_default_timezone_set('Europe/Rome');
ini_set('date.timezone', 'Europe/Rome');

if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\AsyncEmailQueue;
use Need2Talk\Services\FailedEmailRetryService;
use Need2Talk\Services\Logger;

class FailedEmailRetryWorker
{
    private bool $running = true;
    private string $workerId;
    private array $config;
    private int $processedCount = 0;
    private int $errorCount = 0;
    private float $startTime;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 50,          // Process 50 failed emails per cycle
            'sleep_seconds' => 300,      // 5 minutes between cycles
            'max_runtime' => 86400,      // 24 hours max runtime
            'max_errors' => 20,          // Max consecutive errors before restart
        ], $config);

        $this->workerId = 'failed_email_retry_worker_' . uniqid() . '_' . getmypid();
        $this->startTime = microtime(true);

        // Setup signal handlers
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

            Logger::email('info', 'FAILED EMAIL RETRY WORKER: Started', [
                'worker_id' => $this->workerId,
                'config' => $this->config,
                'pid' => getmypid(),
            ]);
    }

    public function run(): void
    {
        echo "====================================\n";
        echo "Failed Email Retry Worker Started\n";
        echo "====================================\n";
        echo "Worker ID: {$this->workerId}\n";
        echo "Batch Size: {$this->config['batch_size']}\n";
        echo "Sleep: {$this->config['sleep_seconds']}s\n";
        echo "Max Runtime: {$this->config['max_runtime']}s\n";
        echo "====================================\n\n";

        $consecutiveErrors = 0;

        while ($this->running) {
            // Check signal handlers
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Check runtime limit
            $runtime = microtime(true) - $this->startTime;
            if ($runtime >= $this->config['max_runtime']) {
                echo "Max runtime reached ({$this->config['max_runtime']}s), shutting down...\n";
                break;
            }

            // Check error limit
            if ($consecutiveErrors >= $this->config['max_errors']) {
                echo "Too many consecutive errors ({$consecutiveErrors}), shutting down...\n";
                    Logger::email('error', 'FAILED EMAIL RETRY WORKER: Too many errors, shutting down', [
                        'worker_id' => $this->workerId,
                        'consecutive_errors' => $consecutiveErrors,
                    ]);
                break;
            }

            try {
                // Get statistics before processing
                $retryService = new FailedEmailRetryService();
                $stats = $retryService->getStats();

                $readyForRetry = $stats['ready_for_retry'] ?? 0;

                if ($readyForRetry > 0) {
                    echo "[" . date('Y-m-d H:i:s') . "] Processing {$readyForRetry} failed emails ready for retry...\n";

                    // Process batch
                    $emailQueue = new AsyncEmailQueue();
                    $processed = $emailQueue->processFailedEmailsFromDatabase($this->config['batch_size']);

                    $this->processedCount += $processed;
                    $consecutiveErrors = 0;  // Reset error counter on success

                    echo "[" . date('Y-m-d H:i:s') . "] Processed: {$processed} | Total: {$this->processedCount}\n";

                    // Show stats
                    $this->displayStats($stats);

                } else {
                    echo "[" . date('Y-m-d H:i:s') . "] No emails ready for retry. Waiting...\n";
                }

            } catch (\Exception $e) {
                $consecutiveErrors++;
                $this->errorCount++;

                echo "[ERROR] " . $e->getMessage() . "\n";

                    Logger::email('error', 'FAILED EMAIL RETRY WORKER: Processing error', [
                        'worker_id' => $this->workerId,
                        'error' => $e->getMessage(),
                        'consecutive_errors' => $consecutiveErrors,
                    ]);
            }

            // Memory check
            $memoryMB = memory_get_usage(true) / 1024 / 1024;
            if ($memoryMB > 200) {
                echo "[WARNING] High memory usage: {$memoryMB}MB\n";
            }

            // Sleep before next cycle
            if ($this->running) {
                echo "Sleeping {$this->config['sleep_seconds']}s until next cycle...\n\n";
                sleep($this->config['sleep_seconds']);
            }
        }

        $this->shutdown();
    }

    private function displayStats(array $stats): void
    {
        echo "\n--- Statistics ---\n";
        echo "Pending: " . ($stats['pending'] ?? 0) . "\n";
        echo "Processing: " . ($stats['processing'] ?? 0) . "\n";
        echo "Permanent Failures: " . ($stats['permanent_failure'] ?? 0) . "\n";

        if (!empty($stats['error_types'])) {
            echo "\nTop Error Types:\n";
            foreach (array_slice($stats['error_types'], 0, 5) as $error) {
                echo "  {$error['error_type']}: {$error['count']}\n";
            }
        }
        echo "-------------------\n\n";
    }

    private function handleSignal(int $signal): void
    {
        echo "\nReceived signal {$signal}, shutting down gracefully...\n";
        $this->running = false;
    }

    private function shutdown(): void
    {
        $runtime = round(microtime(true) - $this->startTime, 2);

        echo "\n====================================\n";
        echo "Failed Email Retry Worker Stopped\n";
        echo "====================================\n";
        echo "Worker ID: {$this->workerId}\n";
        echo "Runtime: {$runtime}s\n";
        echo "Processed: {$this->processedCount}\n";
        echo "Errors: {$this->errorCount}\n";
        echo "====================================\n";

            Logger::email('info', 'FAILED EMAIL RETRY WORKER: Shutdown', [
                'worker_id' => $this->workerId,
                'runtime' => $runtime,
                'processed' => $this->processedCount,
                'errors' => $this->errorCount,
            ]);
    }
}

// Parse command line arguments
$config = [];

foreach ($argv as $arg) {
    if (strpos($arg, '--batch-size=') === 0) {
        $config['batch_size'] = (int) substr($arg, 13);
    }
    if (strpos($arg, '--sleep=') === 0) {
        $config['sleep_seconds'] = (int) substr($arg, 8);
    }
}

// Start worker
try {
    $worker = new FailedEmailRetryWorker($config);
    $worker->run();
} catch (\Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
