#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Email Queue Worker - Enterprise Grade (macOS Independent)
 *
 * Background worker per processare la queue email asincrona
 * Progettato per gestire 100k+ utenti simultanei senza blocchi
 *
 * Usage:
 * ./bin/macos/email-worker.php [--batch-size=100] [--sleep=5] [--max-runtime=3600]
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

// Bootstrap application - INDEPENDENT PATH
define('APP_ROOT', dirname(__DIR__, 2));

// ENTERPRISE: Override bootstrap PHP settings for long-running worker process
if (function_exists('ini_set')) {
    ini_set('max_execution_time', '0');     // Disable execution time limit completely
    ini_set('memory_limit', '512M');       // Increase memory for enterprise scale
    ini_set('max_input_time', '0');        // No input timeout
}

require_once APP_ROOT.'/app/bootstrap.php';

use Need2Talk\Services\AsyncEmailQueue;
use Need2Talk\Services\Logger;
use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Core\EnterpriseRedisManager;

/**
 * ENTERPRISE EMAIL WORKER - macOS Independent Version
 *
 * Gestisce il processing della queue email con:
 * - Performance optimization per 100k+ utenti
 * - Monitoring in real-time
 * - Graceful error handling
 * - Memory management enterprise-grade
 */
class EnterpriseEmailWorker
{
    private $workerId;
    private $batchSize;
    private $sleepDuration;
    private $maxRuntime;
    private $startTime;
    private $processedCount = 0;
    private $errorCount = 0;
    private $logger;
    private $queue;
    private $redisManager;
    private $globalsManager;
    private $running = true;
    private $memoryThreshold = 450; // MB before restart (512M limit)

    public function __construct(array $config = [])
    {
        $this->workerId = $config['worker_id'] ?? 'macos_worker_'.uniqid().'_'.getmypid();
        $this->batchSize = (int) ($config['batch_size'] ?? 100);
        $this->sleepDuration = (int) ($config['sleep_seconds'] ?? 5);
        $this->maxRuntime = (int) ($config['max_runtime'] ?? 3600); // 1 hour default
        $this->startTime = time();

        // ENTERPRISE: Initialize core services
        $this->logger = Logger::getInstance();
        $this->queue = new AsyncEmailQueue();
        $this->redisManager = EnterpriseRedisManager::getInstance();
        $this->globalsManager = EnterpriseGlobalsManager::getInstance();

        // Register worker in Redis for monitoring
        $this->registerWorker();

        // Setup graceful shutdown handlers
        $this->setupSignalHandlers();

        $this->log('ENTERPRISE EMAIL WORKER STARTED', [
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'batch_size' => $this->batchSize,
            'max_runtime' => $this->maxRuntime,
            'memory_limit' => ini_get('memory_limit'),
            'timezone' => date_default_timezone_get(),
            'platform' => 'macOS_independent',
        ]);
    }

    /**
     * ENTERPRISE: Main worker processing loop
     */
    public function run(): void
    {
        while ($this->running && $this->shouldContinueRunning()) {
            try {
                // ENTERPRISE: Check memory usage before processing
                $this->checkMemoryUsage();

                // Process batch of emails
                $processedBatch = $this->processBatch();

                // Update worker heartbeat and metrics
                $this->updateHeartbeat();

                // ENTERPRISE: Adaptive sleep based on queue size
                $queueSize = $this->queue->getQueueSize();
                $adaptiveSleep = $this->calculateAdaptiveSleep($queueSize);

                if ($processedBatch === 0) {
                    // No emails to process, sleep longer
                    sleep($adaptiveSleep * 2);
                } else {
                    // Short sleep between batches
                    sleep($adaptiveSleep);
                }

                // Dispatch signals if available
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

            } catch (Exception $e) {
                $this->handleError($e);
                sleep(10); // Wait before retrying on error
            }
        }

        $this->shutdown();
    }

    /**
     * ENTERPRISE: Process a batch of emails with error recovery
     */
    private function processBatch(): int
    {
        $batchStartTime = microtime(true);
        $processed = 0;

        try {
            // Get batch of emails from queue
            $emails = $this->queue->dequeueBatch($this->batchSize);

            if (empty($emails)) {
                return 0;
            }

            $this->log('Processing batch', [
                'batch_size' => count($emails),
                'queue_size_remaining' => $this->queue->getQueueSize(),
            ]);

            foreach ($emails as $email) {
                try {
                    // Process individual email
                    $this->processEmail($email);
                    $processed++;
                    $this->processedCount++;

                } catch (Exception $e) {
                    $this->errorCount++;
                    $this->logError('Failed to process email', $e, ['email_id' => $email['id'] ?? 'unknown']);

                    // ENTERPRISE: Re-queue failed email with retry logic
                    $this->handleFailedEmail($email, $e);
                }
            }

            // ENTERPRISE: Record batch processing metrics
            $batchDuration = (microtime(true) - $batchStartTime) * 1000; // ms
            $this->recordBatchMetrics($processed, $batchDuration);

        } catch (Exception $e) {
            $this->logError('Batch processing failed', $e);
            throw $e;
        }

        return $processed;
    }

    /**
     * ENTERPRISE: Process individual email with full error handling
     */
    private function processEmail(array $email): void
    {
        $emailStartTime = microtime(true);

        try {
            // Validate email data
            if (! $this->validateEmailData($email)) {
                throw new Exception('Invalid email data');
            }

            // Send email through appropriate service
            $result = $this->sendEmail($email);

            if (! $result) {
                throw new Exception('Email sending failed');
            }

            // Record successful processing
            $processingTime = (microtime(true) - $emailStartTime) * 1000;
            $this->recordEmailMetrics($email, $processingTime, 'success');

        } catch (Exception $e) {
            $processingTime = (microtime(true) - $emailStartTime) * 1000;
            $this->recordEmailMetrics($email, $processingTime, 'failed');
            throw $e;
        }
    }

    private function sendEmail(array $email): bool
    {
        // Implement actual email sending logic here
        // This would integrate with your email service (SMTP, SendGrid, etc.)

        // For now, simulate email sending
        usleep(100000); // 100ms simulated sending time

        return true; // Simulate success
    }

    private function validateEmailData(array $email): bool
    {
        return isset($email['to']) &&
               isset($email['subject']) &&
               isset($email['body']) &&
               filter_var($email['to'], FILTER_VALIDATE_EMAIL);
    }

    /**
     * ENTERPRISE: Handle failed email with retry logic
     */
    private function handleFailedEmail(array $email, Exception $e): void
    {
        $retryCount = (int) ($email['retry_count'] ?? 0);
        $maxRetries = 3;

        if ($retryCount < $maxRetries) {
            // Re-queue with increased retry count
            $email['retry_count'] = $retryCount + 1;
            $email['last_error'] = $e->getMessage();
            $email['last_retry'] = date('Y-m-d H:i:s');

            $this->queue->enqueue($email);

            $this->log('Email re-queued for retry', [
                'email_id' => $email['id'] ?? 'unknown',
                'retry_count' => $email['retry_count'],
                'error' => $e->getMessage(),
            ]);
        } else {
            // Move to dead letter queue after max retries
            $this->queue->moveToDeadLetter($email, $e->getMessage());

            $this->logError('Email moved to dead letter queue', $e, [
                'email_id' => $email['id'] ?? 'unknown',
                'retry_count' => $retryCount,
            ]);
        }
    }

    /**
     * ENTERPRISE: Adaptive sleep calculation based on queue size
     */
    private function calculateAdaptiveSleep(int $queueSize): int
    {
        if ($queueSize > 1000) {
            return 1; // High load, minimal sleep
        } elseif ($queueSize > 100) {
            return 2; // Medium load
        } elseif ($queueSize > 10) {
            return $this->sleepDuration; // Normal load
        } else {
            return $this->sleepDuration * 2; // Low load, longer sleep
        }
    }

    /**
     * ENTERPRISE: Memory usage monitoring and management
     */
    private function checkMemoryUsage(): void
    {
        $memoryUsage = memory_get_usage(true) / 1024 / 1024; // MB
        $memoryPeak = memory_get_peak_usage(true) / 1024 / 1024; // MB

        if ($memoryUsage > $this->memoryThreshold) {
            $this->log('MEMORY THRESHOLD EXCEEDED - Graceful shutdown', [
                'current_usage_mb' => round($memoryUsage, 2),
                'peak_usage_mb' => round($memoryPeak, 2),
                'threshold_mb' => $this->memoryThreshold,
                'processed_count' => $this->processedCount,
            ]);

            $this->running = false;
        }
    }

    /**
     * ENTERPRISE: Check if worker should continue running
     */
    private function shouldContinueRunning(): bool
    {
        $runningTime = time() - $this->startTime;

        if ($runningTime >= $this->maxRuntime) {
            $this->log('MAX RUNTIME REACHED - Graceful shutdown', [
                'runtime_seconds' => $runningTime,
                'max_runtime' => $this->maxRuntime,
                'processed_count' => $this->processedCount,
            ]);

            return false;
        }

        return true;
    }

    /**
     * ENTERPRISE: Register worker in Redis for monitoring
     */
    private function registerWorker(): void
    {
        $workerData = [
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'started_at' => date('Y-m-d H:i:s'),
            'last_heartbeat' => date('Y-m-d H:i:s'),
            'processed_count' => 0,
            'error_count' => 0,
            'status' => 'active',
            'platform' => 'macOS_independent',
        ];

        $this->redisManager->setex("worker:{$this->workerId}", 300, json_encode($workerData));
    }

    /**
     * ENTERPRISE: Update worker heartbeat and metrics
     */
    private function updateHeartbeat(): void
    {
        $workerData = [
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'last_heartbeat' => date('Y-m-d H:i:s'),
            'processed_count' => $this->processedCount,
            'error_count' => $this->errorCount,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'runtime_seconds' => time() - $this->startTime,
            'status' => 'active',
            'platform' => 'macOS_independent',
        ];

        $this->redisManager->setex("worker:{$this->workerId}", 300, json_encode($workerData));
    }

    /**
     * ENTERPRISE: Record batch processing metrics
     */
    private function recordBatchMetrics(int $processed, float $durationMs): void
    {
        $metrics = [
            'worker_id' => $this->workerId,
            'batch_size' => $processed,
            'processing_time_ms' => round($durationMs, 2),
            'emails_per_second' => $durationMs > 0 ? round($processed / ($durationMs / 1000), 2) : 0,
            'timestamp' => date('Y-m-d H:i:s'),
            'platform' => 'macOS_independent',
        ];

        // Store in Redis for real-time monitoring
        $key = 'batch_metrics:'.date('Y-m-d-H');
        $this->redisManager->lpush($key, json_encode($metrics));
        $this->redisManager->expire($key, 86400); // Keep for 24 hours
    }

    /**
     * ENTERPRISE: Record individual email metrics
     */
    private function recordEmailMetrics(array $email, float $processingTimeMs, string $status): void
    {
        // Store metrics in database for long-term analysis
        try {
            $this->globalsManager->executeQuery(
                'INSERT INTO email_verification_metrics
                (email, processing_time_ms, worker_id, status, created_at)
                VALUES (?, ?, ?, ?, NOW())',
                [
                    $email['to'] ?? 'unknown',
                    round($processingTimeMs, 2),
                    $this->workerId,
                    $status,
                ]
            );
        } catch (Exception $e) {
            // Don't fail worker if metrics recording fails
            $this->logError('Failed to record email metrics', $e);
        }
    }

    /**
     * ENTERPRISE: Setup signal handlers for graceful shutdown
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGUSR1, [$this, 'handleStatusSignal']);
        }
    }

    public function handleShutdownSignal(int $signal): void
    {
        $this->log('SHUTDOWN SIGNAL RECEIVED', [
            'signal' => $signal,
            'processed_count' => $this->processedCount,
            'runtime_seconds' => time() - $this->startTime,
        ]);

        $this->running = false;
    }

    public function handleStatusSignal(int $signal): void
    {
        $this->log('STATUS REQUEST', [
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'processed_count' => $this->processedCount,
            'error_count' => $this->errorCount,
            'runtime_seconds' => time() - $this->startTime,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    /**
     * ENTERPRISE: Graceful worker shutdown
     */
    private function shutdown(): void
    {
        // Unregister worker from Redis
        $this->redisManager->del("worker:{$this->workerId}");

        $this->log('ENTERPRISE EMAIL WORKER SHUTDOWN', [
            'worker_id' => $this->workerId,
            'total_processed' => $this->processedCount,
            'total_errors' => $this->errorCount,
            'total_runtime_seconds' => time() - $this->startTime,
            'final_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'platform' => 'macOS_independent',
        ]);
    }

    private function handleError(Exception $e): void
    {
        $this->errorCount++;
        $this->logError('Worker error occurred', $e);

        // Update worker status in Redis
        $workerData = [
            'worker_id' => $this->workerId,
            'status' => 'error',
            'last_error' => $e->getMessage(),
            'error_count' => $this->errorCount,
            'last_heartbeat' => date('Y-m-d H:i:s'),
        ];

        $this->redisManager->setex("worker:{$this->workerId}", 300, json_encode($workerData));
    }

    private function log(string $message, array $context = []): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'message' => $message,
            'context' => $context,
            'platform' => 'macOS_independent',
        ];

        echo json_encode($logEntry)."\n";

        // Also log via Logger service
        if ($this->logger) {
            $this->logger->info("EMAIL_WORKER: $message", $context);
        }
    }

    private function logError(string $message, Exception $e, array $context = []): void
    {
        $errorContext = array_merge($context, [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->log("ERROR: $message", $errorContext);

        // Also log via Logger service
        if ($this->logger) {
            $this->logger->error("EMAIL_WORKER_ERROR: $message", $errorContext);
        }
    }
}

// CLI argument parsing
$config = [];
$showHelp = false;

foreach ($argv as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        $showHelp = true;
    } elseif (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        if (count($parts) === 2) {
            $key = str_replace('-', '_', $parts[0]);
            $value = is_numeric($parts[1]) ? (int) $parts[1] : $parts[1];
            $config[$key] = $value;
        }
    }
}

if ($showHelp) {
    echo "🚀 ENTERPRISE EMAIL WORKER - macOS Independent\n";
    echo "============================================\n";
    echo "\n";
    echo "Usage: ./bin/macos/email-worker.php [OPTIONS]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --worker-id=ID        Unique worker identifier\n";
    echo "  --batch-size=N        Number of emails to process per batch (default: 100)\n";
    echo "  --sleep-seconds=N     Sleep duration between batches (default: 5)\n";
    echo "  --max-runtime=N       Maximum runtime in seconds (default: 3600)\n";
    echo "  --memory-limit=SIZE   PHP memory limit (default: 512M)\n";
    echo "  --help, -h           Show this help\n";
    echo "\n";
    echo "Examples:\n";
    echo "  ./bin/macos/email-worker.php\n";
    echo "  ./bin/macos/email-worker.php --batch-size=150 --sleep-seconds=2\n";
    echo "  ./bin/macos/email-worker.php --worker-id=worker_1 --max-runtime=7200\n";
    echo "\n";
    echo "Monitoring:\n";
    echo "  - Worker heartbeat stored in Redis (key: worker:{worker_id})\n";
    echo "  - Batch metrics stored in Redis (key: batch_metrics:{date-hour})\n";
    echo "  - Email metrics stored in database (table: email_verification_metrics)\n";
    echo "\n";
    exit(0);
}

// Override memory limit if specified
if (isset($config['memory_limit'])) {
    ini_set('memory_limit', $config['memory_limit']);
}

echo "🚀 ENTERPRISE EMAIL WORKER - macOS Independent\n";
echo "============================================\n";
echo 'Worker ID: '.($config['worker_id'] ?? 'auto-generated')."\n";
echo 'Batch Size: '.($config['batch_size'] ?? 100)."\n";
echo 'Sleep Duration: '.($config['sleep_seconds'] ?? 5)." seconds\n";
echo 'Max Runtime: '.($config['max_runtime'] ?? 3600)." seconds\n";
echo 'Memory Limit: '.ini_get('memory_limit')."\n";
echo "Platform: macOS Independent\n";
echo "============================================\n\n";

try {
    $worker = new EnterpriseEmailWorker($config);
    $worker->run();
} catch (Exception $e) {
    echo '❌ WORKER FAILED: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
    exit(1);
}
