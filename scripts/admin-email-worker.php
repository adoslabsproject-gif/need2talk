<?php

/**
 * ENTERPRISE GALAXY: Admin Email Worker
 *
 * Dedicated worker for processing admin-initiated emails
 * Separate from AsyncEmailQueue system (uses Redis DB 4)
 *
 * Features:
 * - Graceful shutdown with signal handling (SIGTERM, SIGINT)
 * - Priority-based job processing (urgent → high → normal → low)
 * - Comprehensive error handling with retry logic
 * - Performance metrics and monitoring
 * - Memory leak prevention
 * - Complete audit trail
 * - Dual logging: PSR-3 system + dedicated worker log file
 *
 * Usage:
 *   php scripts/admin-email-worker.php [--worker-id=X]
 *
 * Docker Usage:
 *   docker exec need2talk_php php /var/www/html/scripts/admin-email-worker.php
 *
 * @package Need2Talk
 * @version 3.0.0
 */

declare(ticks=1);

// ENTERPRISE: Long-running process configuration
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '256M');
ignore_user_abort(true);

// ENTERPRISE TIPS: Force Italian timezone for consistency
date_default_timezone_set('Europe/Rome');
ini_set('date.timezone', 'Europe/Rome');

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

// Bootstrap application
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\AdminEmailQueue;
use Need2Talk\Services\EmailService;
use Need2Talk\Services\Logger;
use Need2Talk\Services\NewsletterCampaignManager;
use Need2Talk\Services\NewsletterLinkWrapperService; // ENTERPRISE GALAXY: Link tracking

/**
 * ENTERPRISE: Get process ID (cross-platform compatible)
 * Uses posix_getpid() if available, falls back to getmypid()
 */
function get_process_id(): int
{
    return function_exists('posix_getpid') ? posix_getpid() : getmypid();
}

// ENTERPRISE GALAXY: Signal handling for graceful shutdown
$shutdown = false;
$currentJobId = null;
$workerLogFile = null; // Dedicated worker log file handle
$workerId = null; // Worker ID for logging

/**
 * ENTERPRISE: Setup dedicated worker log file
 * Matches email-worker.php logging pattern
 */
function setupWorkerLogging(int $workerNumber = 1): void
{
    global $workerLogFile, $workerId;

    // Generate worker ID
    $workerId = "admin-email-worker-{$workerNumber}";
    $logFile = APP_ROOT . "/storage/logs/{$workerId}.log";

    // Remove old log file if exists
    if (file_exists($logFile)) {
        unlink($logFile);
    }

    // Create fresh log file
    touch($logFile);
    chmod($logFile, 0666);

    // Open file handle for writing
    $workerLogFile = fopen($logFile, 'a');

    if ($workerLogFile) {
        // Write startup header
        $timestamp = date('Y-m-d H:i:s');
        $header = <<<LOG
╔══════════════════════════════════════════════════════════════════════╗
║  ADMIN EMAIL WORKER STARTED                                          ║
╚══════════════════════════════════════════════════════════════════════╝
[{$timestamp}] Worker ID: {$workerId}
[{$timestamp}] PID: {pid}
[{$timestamp}] Redis DB: 4 (admin_email_queue)
[{$timestamp}] Memory Limit: 256M
[{$timestamp}] Timezone: Europe/Rome
╔══════════════════════════════════════════════════════════════════════╗
║  MAIN LOOP STARTED - Waiting for jobs...                            ║
╚══════════════════════════════════════════════════════════════════════╝

LOG;
        $header = str_replace('{pid}', get_process_id(), $header);
        fwrite($workerLogFile, $header);
        fflush($workerLogFile);
    }
}

/**
 * ENTERPRISE: Log message to dedicated worker file
 * Matches email-worker.php pattern with enhanced formatting
 */
function logToWorkerFile(string $message, string $level = 'INFO'): void
{
    global $workerLogFile, $workerId;

    // 🔥 ENTERPRISE FIX: Respect logging configuration (email channel)
    if (function_exists('should_log') && !should_log('email', strtolower($level))) {
        return; // Skip logging if level is below configured threshold
    }

    $timestamp = date('Y-m-d H:i:s');
    $pid = get_process_id();
    $memoryMB = round(memory_get_usage(true) / 1024 / 1024, 2);

    // Format: [timestamp] [LEVEL] [PID:123] [MEM:45MB] Message
    $logMessage = "[{$timestamp}] [{$level}] [PID:{$pid}] [MEM:{$memoryMB}MB] {$message}\n";

    if ($workerLogFile && is_resource($workerLogFile)) {
        fwrite($workerLogFile, $logMessage);
        fflush($workerLogFile);
    } else {
        // Fallback: write directly to file
        $logFile = APP_ROOT . "/storage/logs/{$workerId}.log";
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

function signalHandler(int $signal): void
{
    global $shutdown, $currentJobId;

    $signalName = match ($signal) {
        SIGTERM => 'SIGTERM',
        SIGINT => 'SIGINT',
        default => "Signal $signal"
    };

    // Log to dedicated file
    logToWorkerFile("Received {$signalName}, initiating graceful shutdown (current_job: {$currentJobId})", 'WARN');

        Logger::email('info', "AdminEmailWorker: Received {$signalName}, initiating graceful shutdown", [
            'current_job' => $currentJobId,
            'pid' => get_process_id()
        ]);

    $shutdown = true;
}

// Register signal handlers (POSIX signals)
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'signalHandler'); // Graceful termination
    pcntl_signal(SIGINT, 'signalHandler');  // Ctrl+C

        Logger::email('info', 'AdminEmailWorker: Signal handlers registered', [
            'signals' => ['SIGTERM', 'SIGINT'],
            'pid' => get_process_id()
        ]);
} else {
        Logger::email('warning', 'AdminEmailWorker: PCNTL extension not available, signal handling disabled');
}

// ENTERPRISE: Parse command line arguments
$options = getopt('', ['worker-id:']);
$workerNumber = isset($options['worker-id']) ? (int)$options['worker-id'] : 1;

// ENTERPRISE: Setup dedicated worker logging FIRST
setupWorkerLogging($workerNumber);
logToWorkerFile("Worker initialization started", 'INFO');

// ENTERPRISE: Worker configuration
$config = [
    'max_jobs_per_cycle' => 100,
    'sleep_when_empty' => 5, // seconds
    'memory_limit_mb' => 256,
    'max_execution_time' => 0, // Unlimited (long-running worker)
    'performance_log_interval' => 50, // Log stats every N jobs
];

// ENTERPRISE: Performance metrics
$stats = [
    'started_at' => time(),
    'jobs_processed' => 0,
    'jobs_succeeded' => 0,
    'jobs_failed' => 0,
    'total_processing_time_ms' => 0,
    'peak_memory_mb' => 0,
];

// Initialize services
try {
    logToWorkerFile("Initializing AdminEmailQueue (Redis DB 4)...", 'INFO');
    $queue = new AdminEmailQueue();

    logToWorkerFile("Initializing EmailService...", 'INFO');
    $emailService = new EmailService();

    logToWorkerFile("Initializing NewsletterCampaignManager...", 'INFO');
    $campaignManager = new NewsletterCampaignManager();

    logToWorkerFile("Initializing NewsletterLinkWrapperService (ENTERPRISE GALAXY)...", 'INFO');
    $linkWrapperService = new NewsletterLinkWrapperService();

    logToWorkerFile("All services initialized successfully", 'INFO');

        Logger::email('info', 'AdminEmailWorker: Started', [
            'pid' => get_process_id(),
            'worker_number' => $workerNumber,
            'config' => $config,
            'redis_db' => 4
        ]);
} catch (\Exception $e) {
    logToWorkerFile("FATAL: Failed to initialize - {$e->getMessage()}", 'ERROR');

        Logger::email('critical', 'AdminEmailWorker: Failed to initialize', [
            'error' => $e->getMessage()
        ]);
    exit(1);
}

// ENTERPRISE GALAXY: Main worker loop
while (!$shutdown) {
    try {
        // Check memory usage
        $memoryUsageMb = memory_get_usage(true) / 1024 / 1024;
        $stats['peak_memory_mb'] = max($stats['peak_memory_mb'], $memoryUsageMb);

        if ($memoryUsageMb > $config['memory_limit_mb']) {
                Logger::email('warning', 'AdminEmailWorker: Memory limit exceeded, restarting', [
                    'memory_mb' => round($memoryUsageMb, 2),
                    'limit_mb' => $config['memory_limit_mb']
                ]);
            break;
        }

        // Check execution time (0 = unlimited)
        $runningTime = time() - $stats['started_at'];
        if ($config['max_execution_time'] > 0 && $runningTime > $config['max_execution_time']) {
                Logger::email('info', 'AdminEmailWorker: Max execution time reached, restarting', [
                    'running_time' => $runningTime,
                    'limit' => $config['max_execution_time']
                ]);
            break;
        }

        // ENTERPRISE GALAXY: Log queue check (like retry worker pattern)
        static $loopCounter = 0;
        $loopCounter++;

        // Log every loop iteration for visibility (matches retry worker pattern)
        logToWorkerFile("LOOP #{$loopCounter}: Checking admin email queue (Redis DB 4)...", 'INFO');

        // ENTERPRISE GALAXY: PostgreSQL connection health check (prevent "gone away" errors)
        try {
            $pdo = db_pdo();
            $pdo->query('SELECT 1'); // Ping PostgreSQL to check connection
        } catch (\PDOException $e) {
            logToWorkerFile("PostgreSQL connection lost, reconnecting...", 'WARNING');
            // Force new connection by clearing PDO singleton
            $GLOBALS['enterprise_pdo_singleton'] = null;
            $pdo = db_pdo(); // Get fresh connection
            logToWorkerFile("PostgreSQL reconnected successfully", 'INFO');
        }

        // Dequeue next job (priority-based)
        $job = $queue->dequeue();

        if ($job === null) {
            // Queue empty, sleep and continue
            logToWorkerFile("QUEUE EMPTY: No pending admin emails found, sleeping {$config['sleep_when_empty']}s...", 'DEBUG');

            // ENTERPRISE: No centralized logging for routine queue checks (spam prevention)
            // Worker log file has all details for debugging
            sleep($config['sleep_when_empty']);
            continue;
        }

        // Process job
        $currentJobId = $job['job_id'];
        $startTime = microtime(true);

        // Log to dedicated file
        logToWorkerFile("DEQUEUED JOB: {$job['job_id']} | Type: {$job['email_type']} | Priority: {$job['priority']} | To: {$job['recipient_email']}", 'INFO');

        // ENTERPRISE DEBUG: Log newsletter_id value
        if ($job['email_type'] === 'newsletter') {
            $newsletterId = $job['newsletter_id'] ?? 'NOT_SET';
            $isEmpty = empty($job['newsletter_id']) ? 'YES' : 'NO';
            logToWorkerFile("DEBUG: newsletter_id = {$newsletterId} | empty() = {$isEmpty} | isset() = " . (isset($job['newsletter_id']) ? 'YES' : 'NO'), 'DEBUG');
        }

            Logger::email('debug', 'AdminEmailWorker: Processing job', [
                'job_id' => $job['job_id'],
                'email_type' => $job['email_type'],
                'recipient' => $job['recipient_email'],
                'priority' => $job['priority']
            ]);

        try {
            // ENTERPRISE GALAXY: Ensure plain text exists for newsletter campaigns
            if ($job['email_type'] === 'newsletter' && !empty($job['newsletter_id'])) {
                $campaignManager->ensurePlainTextExists($job['newsletter_id']);
                logToWorkerFile("NEWSLETTER: Generated plain text for campaign {$job['newsletter_id']}", 'DEBUG');
            }

            // ENTERPRISE GALAXY: Create newsletter_metrics record BEFORE sending
            if ($job['email_type'] === 'newsletter' && !empty($job['newsletter_id'])) {
                $pdo = db_pdo();

                // Check if record exists
                $stmt = $pdo->prepare("
                    SELECT id FROM newsletter_metrics
                    WHERE newsletter_id = ? AND recipient_email = ?
                ");
                $stmt->execute([$job['newsletter_id'], $job['recipient_email']]);
                $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$existing) {
                    // Create new record
                    // ENTERPRISE FIX: Use recipient_user_id from job, not hardcoded 0
                    $stmt = $pdo->prepare("
                        INSERT INTO newsletter_metrics
                        (newsletter_id, user_id, recipient_email, sent_at, status, created_at)
                        VALUES (?, ?, ?, NOW(), 'sent', NOW())
                    ");
                    $stmt->execute([$job['newsletter_id'], $job['recipient_user_id'], $job['recipient_email']]);

                    logToWorkerFile("NEWSLETTER: Created metrics record for {$job['recipient_email']}", 'DEBUG');
                }
            }

            // Load email template
            $templatePath = __DIR__ . '/../app/Views/emails/' . $job['template'] . '.php';

            if (!file_exists($templatePath)) {
                throw new \Exception("Email template not found: {$job['template']}");
            }

            // Render template
            ob_start();
            extract($job['template_data']);
            require $templatePath;
            $htmlBody = ob_get_clean();

            // ENTERPRISE GALAXY: Wrap links and add tracking pixel for newsletters
            if ($job['email_type'] === 'newsletter' && !empty($job['newsletter_id'])) {
                $recipientHash = hash('sha256', $job['recipient_email'] . ':' . $job['newsletter_id']);

                // CRITICAL FIX: Save original rendered HTML BEFORE wrapping
                // This is needed for storeLinkMappings() to extract the original URLs
                $originalRenderedHtml = $htmlBody;

                // Wrap all links with tracking URLs
                $htmlBody = $linkWrapperService->wrapLinks(
                    $htmlBody,
                    $job['newsletter_id'],
                    $job['recipient_email']
                );

                // Add tracking pixel at the end (before </body> or at the very end)
                $trackingPixel = '<img src="' . ($_ENV['APP_URL'] ?? 'https://need2talk.it') .
                                 '/newsletter/track/open/' . $job['newsletter_id'] . '/' . $recipientHash .
                                 '" width="1" height="1" style="display:none;opacity:0;" alt="" />';

                if (stripos($htmlBody, '</body>') !== false) {
                    $htmlBody = str_ireplace('</body>', $trackingPixel . '</body>', $htmlBody);
                } else {
                    $htmlBody .= $trackingPixel;
                }

                logToWorkerFile("NEWSLETTER: Wrapped links and added tracking pixel", 'DEBUG');
            }

            // Send email (EmailService::send only accepts 3 parameters: to, subject, body)
            // TODO: Add custom headers support to EmailService if needed
            $sent = $emailService->send(
                $job['recipient_email'],
                $job['subject'],
                $htmlBody
            );

            if (!$sent) {
                throw new \Exception('EmailService::send() returned false');
            }

            // Calculate processing time
            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Mark as completed
            $queue->markCompleted($job['job_id'], $processingTimeMs);

            // ENTERPRISE GALAXY: Update newsletter campaign metrics
            if ($job['email_type'] === 'newsletter' && !empty($job['newsletter_id'])) {
                $campaignManager->markEmailSent($job['newsletter_id']);
                logToWorkerFile("NEWSLETTER: Updated campaign {$job['newsletter_id']} sent_count", 'DEBUG');

                // ENTERPRISE GALAXY: Store link mappings for click tracking
                // CRITICAL FIX: Use original rendered HTML (BEFORE wrapping) to extract real URLs
                $linkWrapperService->storeLinkMappings(
                    $job['newsletter_id'],
                    $job['recipient_email'],
                    $originalRenderedHtml // HTML rendered with template BEFORE link wrapping
                );
                logToWorkerFile("NEWSLETTER: Stored link mappings", 'DEBUG');
            }

            // Update stats
            $stats['jobs_processed']++;
            $stats['jobs_succeeded']++;
            $stats['total_processing_time_ms'] += $processingTimeMs;

            // Log to dedicated file
            logToWorkerFile("✅ JOB SUCCESS: {$job['job_id']} | Time: {$processingTimeMs}ms | Total: {$stats['jobs_processed']}", 'INFO');

                Logger::email('warning', 'AdminEmailWorker: Job completed successfully', [
                    'job_id' => $job['job_id'],
                    'processing_time_ms' => $processingTimeMs
                ]);

            // Log performance metrics periodically
            if ($stats['jobs_processed'] % $config['performance_log_interval'] === 0) {
                $avgProcessingTime = $stats['total_processing_time_ms'] / $stats['jobs_processed'];

                    Logger::performance('info', 'AdminEmailWorker: Performance metrics', $avgProcessingTime, [
                        'jobs_processed' => $stats['jobs_processed'],
                        'jobs_succeeded' => $stats['jobs_succeeded'],
                        'jobs_failed' => $stats['jobs_failed'],
                        'avg_processing_time_ms' => round($avgProcessingTime, 2),
                        'peak_memory_mb' => round($stats['peak_memory_mb'], 2),
                        'uptime_seconds' => time() - $stats['started_at']
                    ]);
            }

        } catch (\Exception $e) {
            // Job failed
            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $queue->markFailed(
                $job['job_id'],
                $e->getMessage(),
                get_class($e)
            );

            // ENTERPRISE GALAXY: Update newsletter campaign failed count
            if ($job['email_type'] === 'newsletter' && !empty($job['newsletter_id'])) {
                $campaignManager->markEmailFailed($job['newsletter_id']);
                logToWorkerFile("NEWSLETTER: Updated campaign {$job['newsletter_id']} failed_count", 'DEBUG');
            }

            $stats['jobs_processed']++;
            $stats['jobs_failed']++;

            // Log to dedicated file
            logToWorkerFile("❌ JOB FAILED: {$job['job_id']} | Error: {$e->getMessage()} | Retry: " . ($job['retry_count'] ?? 0), 'ERROR');

                Logger::email('error', 'AdminEmailWorker: Job failed', [
                    'job_id' => $job['job_id'],
                    'error' => $e->getMessage(),
                    'retry_count' => $job['retry_count'] ?? 0
                ]);
        }

        $currentJobId = null;

        // Check if shutdown requested
        if ($shutdown) {
                Logger::email('info', 'AdminEmailWorker: Shutdown requested, stopping after current job');
            break;
        }

        // Prevent CPU spinning
        usleep(100000); // 100ms between jobs

    } catch (\Exception $e) {
            Logger::email('critical', 'AdminEmailWorker: Unexpected error in main loop', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

        // Sleep before retrying
        sleep(5);
    }
}

// ENTERPRISE GALAXY: Graceful shutdown
$uptime = time() - $stats['started_at'];
$avgProcessingTime = $stats['jobs_processed'] > 0
    ? $stats['total_processing_time_ms'] / $stats['jobs_processed']
    : 0;
$successRate = $stats['jobs_processed'] > 0
    ? round(($stats['jobs_succeeded'] / $stats['jobs_processed']) * 100, 2)
    : 0;

// Log to dedicated file
$shutdownLog = <<<SHUTDOWN
╔══════════════════════════════════════════════════════════════════════╗
║  WORKER SHUTDOWN                                                     ║
╚══════════════════════════════════════════════════════════════════════╝
Uptime: {$uptime}s | Jobs: {$stats['jobs_processed']} (✅{$stats['jobs_succeeded']} ❌{$stats['jobs_failed']})
Success Rate: {$successRate}% | Avg Time: {$avgProcessingTime}ms
Peak Memory: {$stats['peak_memory_mb']}MB
╚══════════════════════════════════════════════════════════════════════╝
SHUTDOWN;
logToWorkerFile($shutdownLog, 'INFO');

    Logger::email('info', 'AdminEmailWorker: Shutdown complete', [
        'pid' => get_process_id(),
        'uptime_seconds' => $uptime,
        'jobs_processed' => $stats['jobs_processed'],
        'jobs_succeeded' => $stats['jobs_succeeded'],
        'jobs_failed' => $stats['jobs_failed'],
        'success_rate' => $successRate . '%',
        'avg_processing_time_ms' => round($avgProcessingTime, 2),
        'peak_memory_mb' => round($stats['peak_memory_mb'], 2)
    ]);

// Close log file handle
if ($workerLogFile && is_resource($workerLogFile)) {
    fclose($workerLogFile);
}

exit(0);
