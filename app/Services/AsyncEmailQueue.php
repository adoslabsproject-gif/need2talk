<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Traits\EnterpriseRedisSafety;

/**
 * Async Email Queue - Enterprise Grade for 100k+ Concurrent Users
 *
 * Sistema di queue email asincrono progettato per:
 * - 100,000+ utenti simultanei senza blocchi
 * - Redis-based queue con persistenza
 * - Worker pool distribuiti
 * - Rate limiting per provider email
 * - Retry mechanisms con backoff esponenziale
 * - Dead letter queue per email fallite
 * - Monitoring e metrics real-time
 */
class AsyncEmailQueue
{
    use EnterpriseRedisSafety;

    // Redis keys per enterprise scalability
    private const QUEUE_KEY = 'email_queue:pending';
    private const PROCESSING_KEY = 'email_queue:processing';
    private const FAILED_KEY = 'email_queue:failed';
    private const DEAD_LETTER_KEY = 'email_queue:dead_letter';
    private const METRICS_KEY = 'email_queue:metrics';
    private const WORKER_HEARTBEAT_KEY = 'email_queue:workers';

    // Queue priority levels (PUBLIC for external access)
    public const PRIORITY_HIGH = 1;     // Verification emails
    public const PRIORITY_NORMAL = 5;   // General notifications
    public const PRIORITY_LOW = 10;     // Marketing emails

    // ENTERPRISE V12.4: String-to-numeric priority mapping
    // External services may pass 'high', 'normal', 'low' strings
    private const PRIORITY_MAP = [
        'high' => self::PRIORITY_HIGH,
        'critical' => self::PRIORITY_HIGH,
        'urgent' => self::PRIORITY_HIGH,
        'normal' => self::PRIORITY_NORMAL,
        'default' => self::PRIORITY_NORMAL,
        'low' => self::PRIORITY_LOW,
        'bulk' => self::PRIORITY_LOW,
        'marketing' => self::PRIORITY_LOW,
    ];

    private $redisManager;

    private $config;

    private $logger;

    private $unifiedMetrics;

    // ENTERPRISE GALAXY: Track email errors for batch processing (indexed by position)
    private $batchEmailErrors = [];

    public function __construct()
    {
        // ENTERPRISE TIPS: Initialize Logger with system integration
        try {
            $this->logger = new Logger();
        } catch (\Exception $e) {
            // Fallback to null with error logging
            error_log('AsyncEmailQueue: Failed to initialize Logger - ' . $e->getMessage());
            $this->logger = null;
        }

        // ENTERPRISE METRICS: Initialize unified metrics system
        try {
            $this->unifiedMetrics = new EnterpriseEmailMetricsUnified();
        } catch (\Exception $e) {
            error_log('AsyncEmailQueue: Failed to initialize EnterpriseEmailMetricsUnified - ' . $e->getMessage());
            $this->unifiedMetrics = null;
        }

        // ENTERPRISE INTEGRATION: Use existing RedisManager with circuit breaker
        $this->redisManager = EnterpriseRedisManager::getInstance();

        $this->config = [
            'max_retry_attempts' => 3,
            'retry_delay' => 60, // seconds
            'dead_letter_ttl' => 86400 * 7, // 7 days
            'worker_timeout' => 300, // 5 minutes
            'batch_size' => 100, // Process emails in batches
        ];
    }

    /**
     * ENTERPRISE V12.4: Normalize priority to numeric value
     *
     * Converts string priorities ('high', 'normal', 'low') to numeric constants.
     * External services may pass string values instead of AsyncEmailQueue::PRIORITY_* constants.
     * This prevents Redis zAdd "scores must be numeric" errors.
     *
     * @param int|string $priority Priority value (numeric or string)
     * @return int Numeric priority value
     */
    private function normalizePriority(int|string $priority): int
    {
        // Already numeric? Ensure it's a valid range
        if (is_int($priority) || is_numeric($priority)) {
            $numericPriority = (int) $priority;
            // Clamp to valid range [1, 10]
            return max(1, min(10, $numericPriority));
        }

        // String priority? Map to numeric constant
        if (is_string($priority)) {
            $normalized = strtolower(trim($priority));
            if (isset(self::PRIORITY_MAP[$normalized])) {
                return self::PRIORITY_MAP[$normalized];
            }
        }

        // Unknown value - default to normal priority with warning
        Logger::email('warning', 'EMAIL: Unknown priority value, defaulting to NORMAL', [
            'priority_value' => $priority,
            'priority_type' => gettype($priority),
            'default_applied' => self::PRIORITY_NORMAL,
        ]);

        return self::PRIORITY_NORMAL;
    }

    /**
     * Queue verification email (HIGH PRIORITY) - NON BLOCCANTE
     * ENTERPRISE INTEGRATION: Compatible with EmailVerificationService
     */
    public function queueVerificationEmail(?int $userId, string $email, string $nickname, string $context = 'initial'): bool
    {
        // ENTERPRISE VALIDATION: Input validation before queuing
        if ($userId === null || $userId <= 0) {
            Logger::email('error', 'EMAIL: Invalid user_id for verification email', [
                'user_id' => $userId,
                'user_id_type' => gettype($userId),
                'email' => $email,
                'error' => 'user_id must be positive integer (got: ' . ($userId === null ? 'NULL' : $userId) . ')',
                'validation_failed' => 'user_id',
                'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);

            return false;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Logger::email('error', 'EMAIL: Invalid email for verification', [
                'user_id' => $userId,
                'email' => $email,
                'error' => 'invalid email format',
                'validation_failed' => 'email_format',
            ]);

            return false;
        }

        return $this->queueEmail([
            'type' => 'verification',
            'user_id' => $userId,
            'email' => $email,
            'context' => $context,
            'template_data' => [
                'user_data' => [
                    'nickname' => $nickname,
                ],
                // EmailVerificationService will generate token and URL automatically
            ],
            'priority' => self::PRIORITY_HIGH,
            'max_attempts' => 5, // Higher for critical emails
        ]);
    }

    /**
     * Queue generic email - ENTERPRISE GRADE (PUBLIC API)
     *
     * ENTERPRISE COMPATIBILITY: Supports all email types for backward compatibility
     * Used by: RegistrationService, PerformanceWorker, Tests, External integrations
     */
    public function queueEmail(array $emailData): bool
    {
        try {
            // ENTERPRISE V12.4: Normalize 'to' field to 'email' for compatibility
            // Some callers (bulk actions, external integrations) use 'to' instead of 'email'
            if (!isset($emailData['email']) && isset($emailData['to'])) {
                $emailData['email'] = $emailData['to'];
                unset($emailData['to']);
            }

            // ENTERPRISE V12.4: Validate email field exists and is valid
            if (empty($emailData['email']) || !filter_var($emailData['email'], FILTER_VALIDATE_EMAIL)) {
                Logger::email('error', 'EMAIL: ENTERPRISE EMAIL QUEUE VALIDATION FAILED', [
                    'error' => 'Invalid or missing email address',
                    'email_value' => $emailData['email'] ?? 'NOT_SET',
                    'to_value' => $emailData['to'] ?? 'NOT_SET',
                    'email_data_keys' => array_keys($emailData),
                    'action' => 'email_rejected',
                    'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
                ]);

                return false;
            }

            // ENTERPRISE V12.4: Default type for generic emails (bulk actions, external)
            if (!isset($emailData['type'])) {
                $emailData['type'] = 'generic';
            }

            // ENTERPRISE V12.4: Normalize subject/body/nickname to template_data
            // Bulk actions pass these directly, but email handlers expect them in template_data
            if (!isset($emailData['template_data'])) {
                $emailData['template_data'] = [];
            }
            if (isset($emailData['subject']) && !isset($emailData['template_data']['subject'])) {
                $emailData['template_data']['subject'] = $emailData['subject'];
            }
            if (isset($emailData['body']) && !isset($emailData['template_data']['body'])) {
                $emailData['template_data']['body'] = $emailData['body'];
            }
            if (isset($emailData['nickname']) && !isset($emailData['template_data']['nickname'])) {
                $emailData['template_data']['nickname'] = $emailData['nickname'];
            }

            // ENTERPRISE VALIDATION: Prevent invalid user_id (NULL, 0, or negative)
            // EXCEPTION: Allow NULL user_id for report emails (public submissions without authentication)
            $emailType = $emailData['type'] ?? 'unknown';
            $allowNullUserId = in_array($emailType, ['report'], true);

            if (!$allowNullUserId && (!isset($emailData['user_id']) || $emailData['user_id'] === null || $emailData['user_id'] <= 0)) {
                Logger::email('error', 'EMAIL: ENTERPRISE EMAIL QUEUE VALIDATION FAILED', [
                    'error' => 'Invalid or missing user_id detected',
                    'user_id_value' => $emailData['user_id'] ?? 'NOT_SET',
                    'email_type' => $emailType,
                    'email_data' => $emailData,
                    'action' => 'email_rejected',
                    'security_level' => 'enterprise_validation',
                    'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
                ]);

                return false;
            }

            // Generate unique job ID
            $jobId = uniqid('email_', true);
            $emailData['job_id'] = $jobId;
            $emailData['attempts'] = 0;

            // ENTERPRISE TIPS: Add queued_at timestamp BEFORE metrics recording
            // This ensures the timestamp is available in recordEmailEventMetrics for proper queue time calculation
            $emailData['queued_at'] = microtime(true);

            // ENTERPRISE METRICS UNIFIED: Record queuing event FIRST to get the metrics record ID
            $metricsRecordId = $this->recordEmailEventMetrics($emailData, 'queued_successfully');

            // ENTERPRISE TIPS: Add metrics_record_id to email data so worker can update the correct record
            if ($metricsRecordId) {
                $emailData['metrics_record_id'] = $metricsRecordId;
            } else {
                // Only log as warning if user_id was provided but metrics still failed
                // For verification emails without user_id, this is expected behavior
                $userId = $emailData['user_id'] ?? null;
                if ($userId && $userId > 0) {
                    Logger::email('warning', 'EMAIL: No metrics record ID returned', [
                        'email' => $emailData['email'] ?? 'unknown',
                        'user_id' => $userId,
                        'email_type' => $emailData['type'] ?? 'unknown',
                    ]);
                }
                // Silent for emails without user_id (verification emails for new users)
            }

            // Add to priority queue
            // ENTERPRISE V12.4: Convert string priority to numeric (fixes Redis zAdd warning)
            // External callers may pass 'high', 'normal', 'low' instead of numeric constants
            $priority = $this->normalizePriority($emailData['priority'] ?? self::PRIORITY_NORMAL);
            $queueData = json_encode($emailData);

            // ENTERPRISE: Use Redis with circuit breaker protection
            $redis = $this->getRedisConnection();

            if (!$redis) {
                Logger::error('ENTERPRISE: Redis connection failed for email queue', [
                    'job_id' => $jobId,
                    'fallback' => 'database_queue_not_implemented',
                ]);

                return false;
            }

            $result = $this->safeRedisCall($redis, 'zadd', [self::QUEUE_KEY, $priority, $queueData]);

            if ($result) {
                // Update metrics
                $this->updateMetrics('queued', 1);

                // Email queued successfully - reduced logging
                Logger::email('info', 'EMAIL: Email queued', [
                    'job_id' => $jobId,
                    'type' => $emailData['type'] ?? 'unknown',
                    'metrics_record_id' => $metricsRecordId,
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: AsyncEmailQueue: Failed to queue email', [
                'error' => $e->getMessage(),
                'email_data' => $emailData,
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Get next email from queue (for workers) - ENTERPRISE LOCKING
     */
    public function getNextEmail(string $workerId): ?array
    {
        try {
            // PERFORMANCE FIX: Commented out intensive queue polling logging that causes Redis CPU overload
            // if (should_log('email', 'debug')) {
            //     Logger::debugGeneral('ENTERPRISE_WORKER_GETNEXT_START', 'EMAIL: Getting next email from queue', [
            //         'worker_id' => $workerId,
            //         'queue_size_before' => $this->getQueueSize()
            //     ]);
            // }

            // Get highest priority email
            $redis = $this->getRedisConnection();

            if (!$redis) {
                Logger::error('ENTERPRISE WORKER: Redis connection failed', [
                    'worker_id' => $workerId,
                ]);

                return null;
            }

            // ENTERPRISE TIPS: Use BZPOPMIN for better worker distribution
            // BZPOPMIN blocks for 1 second, allowing multiple workers to wait efficiently
            // This distributes load evenly across all 8 workers instead of one worker dominating
            $email = $this->safeRedisCall($redis, 'bzpopmin', [self::QUEUE_KEY, 1]);

            // BZPOPMIN returns: [key, member, score] or false if timeout
            // ZPOPMIN returns: [member => score]

            if ($email && is_array($email) && count($email) >= 2) {
                // BZPOPMIN format: [0 => key_name, 1 => member_data, 2 => score]
                $rawData = $email[1] ?? null; // Get member data (the JSON)

                // Decoding email data - removed verbose logging

                if (!$rawData) {
                    Logger::warning('ENTERPRISE WORKER: No raw data in BZPOPMIN result', [
                        'worker_id' => $workerId,
                        'email_array' => $email,
                    ]);

                    return null;
                }

                $emailData = json_decode($rawData, true);
                $jsonError = json_last_error();

                // JSON decode result - log only on errors
                if ($jsonError !== JSON_ERROR_NONE) {
                    Logger::error('JSON decode failed for email data', [
                        'worker_id' => $workerId,
                        'json_error' => $jsonError,
                        'json_error_msg' => json_last_error_msg(),
                    ]);
                }

                if ($emailData && is_array($emailData)) {
                    // Mark as processing to prevent double processing
                    $this->markAsProcessing($emailData, $workerId);

                    return $emailData;
                }
                Logger::email('error', 'EMAIL: ENTERPRISE WORKER: Invalid email data after JSON decode', [
                    'worker_id' => $workerId,
                    'decoded_data' => $emailData,
                    'json_error' => $jsonError,
                    'json_error_msg' => json_last_error_msg(),
                ]);

            }

            return null;

        } catch (\Exception $e) {
            Logger::error('AsyncEmailQueue: Failed to get next email', [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Mark email as completed successfully
     */
    public function markAsCompleted(array $emailData): bool
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return false;
            }

            $result = $this->safeRedisCall($redis, 'hdel', [self::PROCESSING_KEY, $emailData['job_id']]);

            if ($result) {
                $this->updateMetrics('completed', 1);

                // ENTERPRISE: Log at WARNING level so it appears in production logs (global level is WARNING)
                Logger::email('warning', 'EMAIL: Email sent successfully', [
                    'job_id' => $emailData['job_id'],
                    'type' => $emailData['type'] ?? 'unknown',
                    'email' => $emailData['email'] ?? 'unknown',
                    'processing_time' => time() - ($emailData['started_at'] ?? time()),
                ]);

                // ENTERPRISE METRICS: Final UPDATE handled by updateEmailVerificationMetrics in processEmail()
            }

            return (bool) $result;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: AsyncEmailQueue: Failed to mark as completed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark email as failed (with retry logic)
     */
    public function markAsFailed(array $emailData, string $error): bool
    {
        try {
            $emailData['attempts'] = ($emailData['attempts'] ?? 0) + 1;
            $emailData['last_error'] = $error;
            $maxAttempts = $emailData['max_attempts'] ?? $this->config['max_retry_attempts'];

            // ENTERPRISE GALAXY: Detect permanent failures that should not be retried
            $permanentFailurePatterns = [
                'user_not_found',
                'User not found',
                'email_processing_aborted',
                'Invalid verification email data',
            ];

            $isPermanentFailure = false;
            foreach ($permanentFailurePatterns as $pattern) {
                if (stripos($error, $pattern) !== false) {
                    $isPermanentFailure = true;
                    Logger::email('warning', 'EMAIL: ENTERPRISE GALAXY: Permanent failure detected, skipping retries', [
                        'job_id' => $emailData['job_id'] ?? 'unknown',
                        'error' => $error,
                        'pattern_matched' => $pattern,
                        'action' => 'moving_to_database_immediately',
                    ]);
                    // Force max attempts to move directly to database
                    $emailData['attempts'] = $maxAttempts;
                    break;
                }
            }

            // ENTERPRISE: Try Redis first for fast retry queue
            $redis = $this->getRedisConnection();
            $redisAvailable = ($redis !== null);

            if ($redisAvailable) {
                // Remove from processing
                $this->safeRedisCall($redis, 'hdel', [self::PROCESSING_KEY, $emailData['job_id']]);
            }

            // ENTERPRISE: Fast path - Redis retry queue (if Redis available and attempts < max)
            if ($redisAvailable && $emailData['attempts'] < $maxAttempts) {
                // Requeue with exponential backoff
                $retryDelay = $this->config['retry_delay'] * pow(2, $emailData['attempts'] - 1);
                $retryTime = time() + $retryDelay;

                $result = $this->safeRedisCall($redis, 'zadd', [self::FAILED_KEY, $retryTime, json_encode($emailData)]);

                if ($result) {
                    $this->updateMetrics('failed_retry', 1);

                    // ENTERPRISE TIPS: Do NOT create new metrics record for each retry attempt
                    // The record was already created when the email was queued
                    // Only the final status (sent_successfully or send_failed) should update it
                    // This prevents 40+ duplicate records for the same email

                    Logger::email('warning', 'EMAIL: Email failed, retry scheduled - ' . $emailData['job_id'], [
                        'job_id' => $emailData['job_id'],
                        'attempt' => $emailData['attempts'],
                        'max_attempts' => $maxAttempts,
                        'retry_in_seconds' => $retryDelay,
                        'error' => $error,
                    ]);

                    return true;
                }
            }

            // ENTERPRISE FALLBACK: Save to DB for persistent retry
            // This happens when:
            // 1. Redis is down (100k+ users resilience)
            // 2. Max attempts reached (dead letter)
            // 3. Redis retry failed

            if ($redisAvailable) {
                // Try dead letter queue if Redis available
                $this->safeRedisCall($redis, 'setex', [
                    self::DEAD_LETTER_KEY . ':' . $emailData['job_id'],
                    $this->config['dead_letter_ttl'],
                    json_encode($emailData),
                ]);

                $this->updateMetrics('dead_letter', 1);
            }

            // ENTERPRISE METRICS UNIFIED: Record failure
            $this->recordEmailEventMetrics($emailData, 'critical_failure', [
                'retry_count' => $emailData['attempts'],
                'final_error' => $error,
                'will_retry' => true,
                'redis_available' => $redisAvailable,
            ]);

            // ENTERPRISE DB FALLBACK: Always save to database (works even if Redis down)
            try {
                $retryService = new FailedEmailRetryService();
                $dbId = $retryService->saveFromDeadLetter($emailData, $error);

                Logger::email('error', 'EMAIL: Email saved to DB for retry - ' . $emailData['job_id'], [
                    'job_id' => $emailData['job_id'],
                    'db_id' => $dbId,
                    'attempts' => $emailData['attempts'],
                    'error' => $error,
                    'email' => $emailData['email'] ?? 'unknown',
                    'redis_available' => $redisAvailable,
                    'will_retry_from_db' => $dbId !== null,
                ]);

                return $dbId !== null;

            } catch (\Exception $e) {
                // ENTERPRISE GALAXY: Handle foreign key constraint for non-existent users
                if (stripos($e->getMessage(), 'foreign key constraint') !== false ||
                    stripos($e->getMessage(), '1452') !== false) {
                    Logger::email('warning', 'EMAIL: ENTERPRISE GALAXY: Email discarded - user does not exist', [
                        'job_id' => $emailData['job_id'],
                        'email' => $emailData['email'] ?? 'unknown',
                        'user_id' => $emailData['user_id'] ?? 'unknown',
                        'error' => 'User not found in database (foreign key constraint)',
                        'action' => 'email_permanently_discarded',
                        'attempts' => $emailData['attempts'],
                    ]);

                    // Return true to indicate "success" - email has been handled (discarded)
                    // This prevents requeuing
                    return true;
                }

                Logger::email('error', 'EMAIL: CRITICAL: Failed to save email to DB fallback', [
                    'job_id' => $emailData['job_id'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return false;
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: AsyncEmailQueue: Failed to mark as failed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'original_error' => $error,
            ]);

            return false;
        }
    }

    /**
     * Get queue sizes for monitoring
     */
    public function getQueueSize(): int
    {
        try {
            $redis = $this->getRedisConnection();

            return $redis ? ($this->safeRedisCall($redis, 'zcard', [self::QUEUE_KEY]) ?: 0) : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getProcessingSize(): int
    {
        try {
            $redis = $this->getRedisConnection();

            return $redis ? ($this->safeRedisCall($redis, 'hlen', [self::PROCESSING_KEY]) ?: 0) : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getFailedSize(): int
    {
        try {
            $redis = $this->getRedisConnection();

            return $redis ? ($this->safeRedisCall($redis, 'zcard', [self::FAILED_KEY]) ?: 0) : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * ENTERPRISE GALAXY LEVEL: Process failed emails ready for retry from Redis ZSET
     *
     * Retrieves emails from email_queue:failed where retry_time <= now()
     * and moves them back to pending queue for retry
     *
     * @param int $limit Maximum number of emails to retry per call
     * @return int Number of emails moved to retry
     */
    public function processFailedQueueRetry(int $limit = 100): int
    {
        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                return 0;
            }

            $now = time();
            $processed = 0;

            // ENTERPRISE: Get emails ready for retry (score <= current timestamp)
            // Redis zRangeByScore syntax: key, min, max, [options]
            $failedEmails = $this->safeRedisCall($redis, 'zrangebyscore', [
                self::FAILED_KEY,
                0,
                $now,
                ['limit' => [0, $limit]], // Correct Redis PHP syntax
            ]);

            if (empty($failedEmails)) {
                return 0;
            }

            Logger::email('info', 'EMAIL: [RETRY] Processing failed emails from Redis queue', [
                'count' => count($failedEmails),
                'limit' => $limit,
                'timestamp' => $now,
            ]);

            foreach ($failedEmails as $emailJson) {
                try {
                    $emailData = json_decode($emailJson, true);
                    if (!$emailData) {
                        Logger::email('warning', 'EMAIL: [RETRY] Invalid JSON in failed queue, skipping', [
                            'json' => substr($emailJson, 0, 200),
                        ]);
                        // Remove invalid entry
                        $this->safeRedisCall($redis, 'zrem', [self::FAILED_KEY, $emailJson]);
                        continue;
                    }

                    $jobId = $emailData['job_id'] ?? 'unknown';
                    $attempts = $emailData['attempts'] ?? 0;
                    $maxAttempts = $emailData['max_attempts'] ?? $this->config['max_retry_attempts'];

                    // ENTERPRISE: Check if max attempts reached
                    if ($attempts >= $maxAttempts) {
                        Logger::email('warning', 'EMAIL: [RETRY] Max attempts reached, moving to database', [
                            'job_id' => $jobId,
                            'attempts' => $attempts,
                            'max_attempts' => $maxAttempts,
                        ]);

                        // Move to database for manual review
                        $retryService = new FailedEmailRetryService();
                        $retryService->saveFromDeadLetter($emailData, $emailData['last_error'] ?? 'Max retry attempts reached');

                        // Remove from Redis failed queue
                        $this->safeRedisCall($redis, 'zrem', [self::FAILED_KEY, $emailJson]);
                        $processed++;
                        continue;
                    }

                    // ENTERPRISE GALAXY: Re-queue for retry with higher priority
                    $emailData['priority'] = min(($emailData['priority'] ?? 1) + 1, 10); // Increase priority
                    $score = time() - 1; // Process immediately

                    $added = $this->safeRedisCall($redis, 'zadd', [
                        self::QUEUE_KEY,
                        $score,
                        json_encode($emailData),
                    ]);

                    if ($added) {
                        // Remove from failed queue
                        $this->safeRedisCall($redis, 'zrem', [self::FAILED_KEY, $emailJson]);
                        $processed++;

                        Logger::email('info', 'EMAIL: [RETRY] Email moved to pending queue', [
                            'job_id' => $jobId,
                            'attempt' => $attempts + 1,
                            'max_attempts' => $maxAttempts,
                            'new_priority' => $emailData['priority'],
                        ]);

                        // Update metrics
                        $this->updateMetrics('retry_requeued', 1);
                    } else {
                        Logger::email('error', 'EMAIL: [RETRY] Failed to add email to pending queue', [
                            'job_id' => $jobId,
                        ]);
                    }

                } catch (\Exception $e) {
                    Logger::email('error', 'EMAIL: [RETRY] Error processing failed email', [
                        'error' => $e->getMessage(),
                        'email_json' => substr($emailJson, 0, 200),
                    ]);
                }
            }

            if ($processed > 0) {
                Logger::email('info', 'EMAIL: [RETRY] Completed processing failed emails', [
                    'processed' => $processed,
                    'total_failed' => count($failedEmails),
                ]);
            }

            return $processed;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: [RETRY] Critical error in processFailedQueueRetry', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 0;
        }
    }

    /**
     * Register worker heartbeat for monitoring
     */
    public function registerWorkerHeartbeat(string $workerId): void
    {
        try {
            $redis = $this->getRedisConnection();

            if ($redis) {
                $this->safeRedisCall($redis, 'hset', [
                    self::WORKER_HEARTBEAT_KEY,
                    $workerId,
                    json_encode([
                        'last_seen' => time(),
                        'pid' => getmypid(),
                        'memory_usage' => memory_get_usage(true),
                    ]),
                ]);
            }
        } catch (\Exception $e) {
            // Silent fail for heartbeat
        }
    }

    /**
     * Unregister worker
     */
    public function unregisterWorker(string $workerId): void
    {
        try {
            $redis = $this->getRedisConnection();

            if ($redis) {
                $this->safeRedisCall($redis, 'hdel', [self::WORKER_HEARTBEAT_KEY, $workerId]);
            }
        } catch (\Exception $e) {
            // Silent fail
        }
    }

    /**
     * ENTERPRISE: Process email queue with intelligent batch optimization
     * Automatically chooses between batch and individual processing
     */
    public function processQueue(?int $batchSize = null): int
    {
        $batchSize = $batchSize ?? $this->config['batch_size'];

        // Check if batch processing is more efficient
        $queueSize = $this->getQueueSize();
        $shouldUseBatchProcessing = $queueSize >= 5; // Batch when 5+ emails waiting

        if ($shouldUseBatchProcessing) {
            return $this->processQueueBatch($batchSize);
        }

        return $this->processQueueIndividual($batchSize);

    }

    /**
     * Get comprehensive stats
     */
    public function getStats(): array
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return ['error' => 'Redis connection failed'];
            }

            $metrics = $this->safeRedisCall($redis, 'hgetall', [self::METRICS_KEY]) ?: [];
            $workers = $this->safeRedisCall($redis, 'hlen', [self::WORKER_HEARTBEAT_KEY]) ?: 0;

            return [
                'queue_size' => $this->getQueueSize(),
                'processing_size' => $this->getProcessingSize(),
                'failed_size' => $this->getFailedSize(),
                'active_workers' => $workers,
                'redis_status' => 'connected',
                'metrics' => $metrics,
                'system_health' => $this->getSystemHealthMetrics(),
                'uptime' => time() - (int) ($metrics['started_at'] ?? time()),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get system health metrics
     */
    public function getSystemHealthMetrics(): array
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return ['redis_status' => 'disconnected'];
            }

            $metrics = $this->safeRedisCall($redis, 'hgetall', [self::METRICS_KEY]) ?: [];
            $workers = $this->safeRedisCall($redis, 'hlen', [self::WORKER_HEARTBEAT_KEY]) ?: 0;

            return [
                'queue_size' => $this->getQueueSize(),
                'processing_size' => $this->getProcessingSize(),
                'failed_size' => $this->getFailedSize(),
                'active_workers' => $workers,
                'redis_status' => 'connected',
                'metrics' => $metrics,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'redis_status' => 'error'];
        }
    }

    /**
     * ENTERPRISE: Run worker to process email queue
     * This method should be called by a background daemon/cron
     */
    public function runWorker(int $maxEmails = 100, int $timeout = 60): array
    {
        $startTime = microtime(true);
        $workerId = $this->registerWorker();
        $processed = 0;
        $failed = 0;
        $results = [];

        Logger::info('PERFORMANCE: Email worker starting', [
            'worker_id' => $workerId,
            'max_emails' => $maxEmails,
            'timeout_seconds' => $timeout,
            'queue_size' => $this->getQueueSize(),
        ]);

        try {
            $endTime = $startTime + $timeout;

            while (microtime(true) < $endTime && $processed < $maxEmails) {
                // Get batch of emails
                $emailBatch = $this->collectEmailBatch(min(10, $maxEmails - $processed));

                if (empty($emailBatch)) {
                    // No emails in queue, implement progressive backoff
                    static $emptyQueueCount = 0;
                    $emptyQueueCount++;

                    // Progressive sleep: 1s -> 2s -> 4s -> 8s -> 16s -> 32s -> 60s (max)
                    $sleepTime = min(60, max(1, pow(2, floor($emptyQueueCount / 3))));

                    // Queue empty - reduced logging (only log occasionally)
                    if ($emptyQueueCount % 10 === 0) { // Log every 10th empty check
                        Logger::info('PERFORMANCE: Queue empty, progressive backoff', [
                            'worker_id' => $workerId,
                            'empty_count' => $emptyQueueCount,
                            'sleep_seconds' => $sleepTime,
                            'processed_so_far' => $processed,
                        ]);
                    }

                    sleep($sleepTime);

                    // Reset counter if we've been sleeping too long (prevents infinite growth)
                    if ($emptyQueueCount > 20) {
                        $emptyQueueCount = 0;
                    }

                    continue;
                }
                // Reset empty queue counter when we find emails
                $emptyQueueCount = 0;

                // Process batch using existing enterprise method
                $groupedEmails = $this->groupEmailsByType($emailBatch);
                $batchResults = [];

                foreach ($groupedEmails as $emailType => $typeEmails) {
                    $typeResults = $this->processBatchByType($emailType, $typeEmails, $workerId);
                    $batchResults = array_merge($batchResults, $typeResults);
                }

                foreach ($batchResults as $success) {
                    if ($success) {
                        $processed++;
                    } else {
                        $failed++;
                    }
                }

                // Update results
                $results = array_merge($results, $batchResults);

                // Update worker heartbeat
                $this->updateWorkerHeartbeat($workerId);
            }

            $processingTime = microtime(true) - $startTime;

            Logger::info('PERFORMANCE: Email worker completed', [
                'worker_id' => $workerId,
                'processed' => $processed,
                'failed' => $failed,
                'processing_time_seconds' => round($processingTime, 2),
                'emails_per_second' => $processed > 0 ? round($processed / $processingTime, 2) : 0,
                'success_rate' => ($processed + $failed) > 0 ? round(($processed / ($processed + $failed)) * 100, 2) : 0,
            ]);

            return [
                'success' => true,
                'processed' => $processed,
                'failed' => $failed,
                'processing_time' => $processingTime,
                'worker_id' => $workerId,
                'results' => $results,
            ];

        } catch (\Exception $e) {
            Logger::error('PERFORMANCE: Email worker error', [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
                'processed' => $processed,
                'failed' => $failed,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => $processed,
                'failed' => $failed,
                'worker_id' => $workerId,
            ];
        }
    }

    /**
     * Process a single email immediately (for testing)
     */
    public function processOneEmail(): ?array
    {
        $emailBatch = $this->collectEmailBatch(1);

        if (empty($emailBatch)) {
            return null;
        }

        $email = $emailBatch[0];
        $workerId = 'immediate_' . uniqid();

        Logger::email('info', 'EMAIL: ENTERPRISE: Processing single email immediately', [
            'email_type' => $email['type'] ?? 'unknown',
            'email_to' => $email['email'] ?? 'unknown',
            'worker_id' => $workerId,
        ]);

        $result = $this->processEmail($email, $workerId);

        if ($result) {
            $this->markAsCompleted($email);
            Logger::email('info', 'EMAIL: ENTERPRISE: Email processed successfully', [
                'email_type' => $email['type'] ?? 'unknown',
                'worker_id' => $workerId,
            ]);
        } else {
            $this->markAsFailed($email, 'Immediate processing failed');
            Logger::email('error', 'EMAIL: ENTERPRISE: Email processing failed', [
                'email_type' => $email['type'] ?? 'unknown',
                'worker_id' => $workerId,
            ]);
        }

        return [
            'success' => $result,
            'email' => $email,
            'worker_id' => $workerId,
        ];
    }

    /**
     * 🚀 ENTERPRISE UUID-BASED: Queue verification email using UUID instead of user_id
     * Risolve il problema user_id=0 durante race condition in registration
     */
    public function queueVerificationEmailByUuid(string $userUuid, string $email, string $nickname, string $context = 'initial'): bool
    {
        try {
            // ENTERPRISE: Validate UUID format with detailed logging
            if (!$this->isValidUuid($userUuid)) {
                Logger::email('error', 'EMAIL: Invalid UUID format in queueVerificationEmailByUuid', [
                    'uuid' => $userUuid,
                    'email' => hash('sha256', $email),
                    'uuid_length' => strlen($userUuid),
                    'expected_pattern' => '8-4-4-4-12 hex chars with dashes',
                    'actual_format' => substr($userUuid, 0, 50),
                    'action' => 'email_queue_rejected',
                ]);

                return false;
            }

            // ENTERPRISE: Get user_id from UUID with enhanced caching and logging
            $userId = $this->getUserIdFromUuid($userUuid);

            if ($userId === null) {
                Logger::email('error', 'EMAIL: ENTERPRISE: User not found by UUID in queueVerificationEmailByUuid', [
                    'uuid' => $userUuid,
                    'email' => hash('sha256', $email),
                    'uuid_valid' => true,
                    'cache_checked' => true,
                    'database_queried' => true,
                    'possible_causes' => ['user_deleted', 'uuid_mismatch', 'database_connection_issue'],
                    'action' => 'email_queue_rejected',
                ]);

                return false;
            }

            // Log the UUID-based approach
            Logger::email('info', 'EMAIL: ENTERPRISE UUID: Queueing verification email by UUID', [
                'uuid' => $userUuid,
                'resolved_user_id' => $userId,
                'email' => $email,
                'nickname' => $nickname,
            ]);

            // 🚀 ENTERPRISE UUID-FIRST: Queue with both user_id AND UUID for optimal processing
            return $this->queueEmail([
                'type' => 'verification',
                'user_id' => $userId,
                'user_uuid' => $userUuid, // 🚀 CRITICAL: Include UUID for worker processing
                'email' => $email,
                'context' => $context,
                'template_data' => [
                    'user_data' => [
                        'nickname' => $nickname,
                    ],
                    // EmailVerificationService will generate token and URL automatically
                ],
                'priority' => self::PRIORITY_HIGH,
                'max_attempts' => 5, // Higher for critical emails
                'processing_method' => 'uuid_first_enterprise', // Flag for monitoring
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: UUID-based queue failed', [
                'uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Queue welcome email (NORMAL PRIORITY) - Sent after email verification
     * ENTERPRISE INTEGRATION: Welcome email sent post-verification
     *
     * @param int $userId User ID
     * @param string $email User email
     * @param string $nickname User nickname
     * @return bool True if queued successfully, false otherwise
     */
    public function queueWelcomeEmail(int $userId, string $email, string $nickname): bool
    {
        // ENTERPRISE VALIDATION: Input validation before queuing
        if ($userId <= 0) {
            Logger::email('error', 'EMAIL: Invalid user_id for welcome email', [
                'user_id' => $userId,
                'email' => $email,
                'error' => 'user_id must be positive integer',
                'validation_failed' => 'user_id',
            ]);

            return false;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Logger::email('error', 'EMAIL: Invalid email for welcome', [
                'user_id' => $userId,
                'email' => $email,
                'error' => 'invalid email format',
                'validation_failed' => 'email_format',
            ]);

            return false;
        }

        return $this->queueEmail([
            'type' => 'welcome',
            'user_id' => $userId,
            'email' => $email,
            'template_data' => [
                'user_data' => [
                    'nickname' => $nickname,
                ],
            ],
            'priority' => self::PRIORITY_NORMAL, // Normal priority (not critical like verification)
            'max_attempts' => 3, // Lower retry count for welcome emails
        ]);
    }

    /**
     * 🚀 ENTERPRISE UUID-BASED: Queue welcome email using UUID instead of user_id
     * Ensures consistency with verification email flow
     *
     * @param string $userUuid User UUID
     * @param string $email User email
     * @param string $nickname User nickname
     * @return bool True if queued successfully, false otherwise
     */
    public function queueWelcomeEmailByUuid(string $userUuid, string $email, string $nickname): bool
    {
        try {
            // ENTERPRISE: Validate UUID format with detailed logging
            if (!$this->isValidUuid($userUuid)) {
                Logger::email('error', 'EMAIL: Invalid UUID format in queueWelcomeEmailByUuid', [
                    'uuid' => $userUuid,
                    'email' => hash('sha256', $email),
                    'uuid_length' => strlen($userUuid),
                    'expected_pattern' => '8-4-4-4-12 hex chars with dashes',
                    'actual_format' => substr($userUuid, 0, 50),
                    'action' => 'email_queue_rejected',
                ]);

                return false;
            }

            // ENTERPRISE: Get user_id from UUID with enhanced caching and logging
            $userId = $this->getUserIdFromUuid($userUuid);

            if ($userId === null) {
                Logger::email('error', 'EMAIL: ENTERPRISE: User not found by UUID in queueWelcomeEmailByUuid', [
                    'uuid' => $userUuid,
                    'email' => hash('sha256', $email),
                    'uuid_valid' => true,
                    'cache_checked' => true,
                    'database_queried' => true,
                    'possible_causes' => ['user_deleted', 'uuid_mismatch', 'database_connection_issue'],
                    'action' => 'email_queue_rejected',
                ]);

                return false;
            }

            // Log the UUID-based approach
            Logger::email('info', 'EMAIL: ENTERPRISE UUID: Queueing welcome email by UUID', [
                'uuid' => $userUuid,
                'resolved_user_id' => $userId,
                'email' => $email,
                'nickname' => $nickname,
            ]);

            // 🚀 ENTERPRISE UUID-FIRST: Queue with both user_id AND UUID for optimal processing
            return $this->queueEmail([
                'type' => 'welcome',
                'user_id' => $userId,
                'user_uuid' => $userUuid, // 🚀 CRITICAL: Include UUID for worker processing
                'email' => $email,
                'template_data' => [
                    'user_data' => [
                        'nickname' => $nickname,
                    ],
                ],
                'priority' => self::PRIORITY_NORMAL,
                'max_attempts' => 3,
                'processing_method' => 'uuid_first_enterprise', // Flag for monitoring
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: UUID-based welcome queue failed', [
                'uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Calculate real verification email size (unified method for all flows)
     * Uses consistent logic for both registration and resend flows
     */
    public function calculateRealEmailSize(string $email): int
    {
        try {
            // ENTERPRISE: Get actual user data for realistic calculation
            $pdo = db_pdo();
            $stmt = $pdo->prepare('SELECT nickname FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            $nickname = $user['nickname'] ?? 'User';

            // ENTERPRISE TIPS: Generate realistic verification token - MUST be 128 characters like EmailVerificationService
            $token = bin2hex(random_bytes(64)); // 128 characters (matches EmailVerificationService::generateSecureToken)
            $verificationUrl = ($_ENV['APP_URL'] ?? 'http://YOUR_SERVER_IP') . '/auth/verify-email?token=' . $token;

            // ENTERPRISE: Render actual email template with real data
            $emailSubject = 'Verifica il tuo account need2talk';
            $templateData = [
                'email' => $email,
                'template_data' => [
                    'user_data' => [
                        'nickname' => $nickname,
                        'verification_url' => $verificationUrl,
                    ],
                ],
            ];

            // Build actual email content using the same logic as processEmail
            $emailBody = $this->buildEmailBody('verification', $templateData);

            // ENTERPRISE: Calculate REAL size including SMTP headers and multipart structure
            $smtpHeaders = $this->calculateSmtpHeadersSize($email, $emailSubject);
            $contentSize = strlen($emailBody);
            $multipartOverhead = 500; // Boundary markers, content-type headers, etc.

            $totalSize = $smtpHeaders + $contentSize + $multipartOverhead;

            // Email size calculated - removed verbose logging

            return $totalSize;

        } catch (\Exception $e) {
            // ENTERPRISE: Fallback to enhanced estimation if template rendering fails
            Logger::warning('Real email size calculation failed, using enhanced fallback', [
                'email_hash' => hash('sha256', $email),
                'error' => $e->getMessage(),
                'fallback_method' => 'enhanced_estimation',
            ]);

            // Enhanced fallback with more realistic estimation
            $nickname = 'User';
            $tokenLength = 128; // ENTERPRISE TIPS: Token is 128 characters, not 64
            $baseUrl = ($_ENV['APP_URL'] ?? 'http://YOUR_SERVER_IP') . '/auth/verify-email?token=';
            $verificationUrl = $baseUrl . str_repeat('x', $tokenLength);

            // More realistic calculation including HTML template content
            $baseContentSize = strlen($nickname . $verificationUrl);
            $htmlTemplateSize = 8000; // Realistic size based on current template
            $smtpHeadersSize = 800; // Realistic SMTP headers size

            return $baseContentSize + $htmlTemplateSize + $smtpHeadersSize;
        }
    }

    /**
     * ENTERPRISE: Get pending emails count for monitoring
     *
     * @return int Number of pending emails in queue
     */
    public function getPendingEmailsCount(): int
    {
        try {
            $redis = $this->redisManager->getConnection('email_queue');

            if (!$redis) {
                Logger::warning('DEFAULT: Redis unavailable for pending email count');

                return 0;
            }

            // ENTERPRISE TIPS: Use correct Redis commands for each data structure
            // - QUEUE_KEY (pending) is ZSET → use ZCARD
            // - PROCESSING_KEY is HASH → use HLEN
            $pendingCount = $this->safeRedisCall($redis, 'zcard', [self::QUEUE_KEY]) ?: 0;
            $processingCount = $this->safeRedisCall($redis, 'hlen', [self::PROCESSING_KEY]) ?: 0;

            // Total count includes both pending and processing
            return $pendingCount + $processingCount;

        } catch (\Exception $e) {
            Logger::error('Failed to get pending emails count', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 0;
        }
    }

    /**
     * DUAL-LAYER RETRY: Process failed emails from database
     * Called by dedicated worker to retry emails that failed Redis retry
     */
    public function processFailedEmailsFromDatabase(int $batchSize = 50): int
    {
        try {
            $retryService = new FailedEmailRetryService();
            $failedEmails = $retryService->getEmailsForRetry($batchSize);

            if (empty($failedEmails)) {
                return 0;
            }

            $processed = 0;
            $workerId = 'db_retry_worker_' . uniqid() . '_' . getmypid();

            foreach ($failedEmails as $failedEmail) {
                // Mark as processing to prevent duplicate processing
                if (!$retryService->markAsProcessing($failedEmail['id'], $workerId)) {
                    continue;  // Already being processed by another worker
                }

                try {
                    // Rebuild email data from database record
                    $emailData = [
                        'job_id' => $failedEmail['job_id'] . '_retry_' . $failedEmail['attempts'],
                        'user_id' => $failedEmail['user_id'],
                        'user_uuid' => $failedEmail['user_uuid'],
                        'email' => $failedEmail['email'],
                        'type' => $failedEmail['email_type'],
                        'template_data' => json_decode($failedEmail['template_data'], true) ?? [],
                        'priority' => $failedEmail['priority'],
                        'context' => $failedEmail['context'] ?? 'db_retry',
                    ];

                    // Attempt to send email directly
                    $success = $this->sendVerificationEmailDirect($emailData);

                    if ($success) {
                        // Success! Remove from failed_emails table
                        $retryService->markRetrySuccess($failedEmail['id']);
                        $processed++;

                        Logger::email('info', 'EMAIL: DUAL-LAYER RETRY: Email sent successfully', [
                            'id' => $failedEmail['id'],
                            'job_id' => $failedEmail['job_id'],
                            'email' => $failedEmail['email'],
                            'attempt' => $failedEmail['attempts'] + 1,
                        ]);
                    } else {
                        // Failed again, schedule next retry
                        $retryService->markRetryFailed($failedEmail['id'], 'Email sending failed on DB retry');

                        Logger::email('warning', 'EMAIL: DUAL-LAYER RETRY: Email failed on retry', [
                            'id' => $failedEmail['id'],
                            'job_id' => $failedEmail['job_id'],
                            'email' => $failedEmail['email'],
                            'attempt' => $failedEmail['attempts'] + 1,
                        ]);
                    }

                } catch (\Exception $e) {
                    // Exception during retry
                    $retryService->markRetryFailed($failedEmail['id'], $e->getMessage());

                    Logger::email('error', 'EMAIL: DUAL-LAYER RETRY: Exception during retry', [
                        'id' => $failedEmail['id'],
                        'job_id' => $failedEmail['job_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Logger::email('info', 'EMAIL: DUAL-LAYER RETRY: Batch complete', [
                'worker_id' => $workerId,
                'total' => count($failedEmails),
                'processed' => $processed,
                'failed' => count($failedEmails) - $processed,
            ]);

            return $processed;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: DUAL-LAYER RETRY: Failed to process database retries', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 0;
        }
    }

    /**
     * ENTERPRISE: Get Redis connection using enterprise manager with circuit breaker
     */
    private function getRedisConnection(): ?\Redis
    {
        return $this->redisManager->getConnection('L3_async_email'); // Use L3_async_email pool for email queues
    }

    /**
     * Mark email as processing (prevent double processing)
     */
    private function markAsProcessing(array $emailData, string $workerId): bool
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return false;
            }

            return $this->safeRedisCall($redis, 'hset', [
                self::PROCESSING_KEY,
                $emailData['job_id'],
                json_encode([
                    'email_data' => $emailData,
                    'started_at' => time(),
                    'worker_id' => $workerId,
                ]),
            ]);

        } catch (\Exception $e) {
            Logger::error('AsyncEmailQueue: Failed to mark as processing', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Update metrics for monitoring
     */
    private function updateMetrics(string $metric, int $increment = 1): void
    {
        try {
            $redis = $this->getRedisConnection();

            if ($redis) {
                $this->safeRedisCall($redis, 'hincrby', [self::METRICS_KEY, $metric, $increment]);
                $this->safeRedisCall($redis, 'hincrby', [self::METRICS_KEY, $metric . '_' . date('Y-m-d'), $increment]);
            }
        } catch (\Exception $e) {
            // Silent fail for metrics
        }
    }

    /**
     * ENTERPRISE: Process email queue with SMTP batch processing for maximum efficiency
     */
    private function processQueueBatch(?int $batchSize = null): int
    {
        $batchSize = $batchSize ?? $this->config['batch_size'];
        $processed = 0;

        try {
            // Register worker heartbeat
            $workerId = $this->registerWorker();

            // Collect batch of emails from queue
            $emailBatch = $this->collectEmailBatch($batchSize);

            if (empty($emailBatch)) {
                $this->unregisterWorker($workerId);

                return 0;
            }

            // Group emails by type for optimized sending
            $emailsByType = $this->groupEmailsByType($emailBatch);

            foreach ($emailsByType as $emailType => $typeEmails) {
                $results = $this->processBatchByType($emailType, $typeEmails, $workerId);
                $this->updateBatchResults($typeEmails, $results);
                $processed += count(array_filter($results));
            }

            $this->unregisterWorker($workerId);

            return $processed;

        } catch (\Exception $e) {
            Logger::error('PERFORMANCE: AsyncEmailQueue: Batch processing failed', [
                'error' => $e->getMessage(),
                'batch_size' => $batchSize,
                'trace' => $e->getTraceAsString(),
            ]);

            if (isset($workerId)) {
                $this->unregisterWorker($workerId);
            }

            return $processed;
        }
    }

    /**
     * ENTERPRISE: Process email queue individually
     */
    private function processQueueIndividual(?int $batchSize = null): int
    {
        $batchSize = $batchSize ?? $this->config['batch_size'];
        $processed = 0;

        try {
            $workerId = $this->registerWorker();

            // Worker individual processing started - removed verbose logging

            for ($i = 0; $i < $batchSize; $i++) {
                // PERFORMANCE FIX: Commented out intensive logging that causes Redis CPU overload
                // if (should_log('email', 'debug')) {
                //     Logger::debugGeneral('ENTERPRISE_WORKER_LOOP', 'EMAIL: Processing loop iteration', [
                //         'worker_id' => $workerId,
                //         'iteration' => $i,
                //         'batch_size' => $batchSize,
                //         'processed_so_far' => $processed
                //     ]);
                // }

                $this->updateWorkerHeartbeat($workerId);

                // PERFORMANCE FIX: Commented out intensive heartbeat logging that causes Redis CPU overload
                // if (should_log('email', 'debug')) {
                //     Logger::debugGeneral('ENTERPRISE_WORKER_HEARTBEAT', 'EMAIL: Worker heartbeat updated', [
                //         'worker_id' => $workerId,
                //         'iteration' => $i
                //     ]);
                // }

                $emailData = $this->getNextEmail($workerId);

                // PERFORMANCE FIX: Commented out intensive getNextEmail logging that causes Redis CPU overload
                // if (should_log('email', 'debug')) {
                //     Logger::debugGeneral('ENTERPRISE_WORKER_GETNEXT', 'EMAIL: getNextEmail completed', [
                //         'worker_id' => $workerId,
                //         'iteration' => $i,
                //         'email_data_received' => !empty($emailData)
                //     ]);
                // }

                // PERFORMANCE FIX: Commented out intensive getNextEmail result logging that causes Redis CPU overload
                // if (should_log('email', 'info')) {
                //     Logger::info('EMAIL: ENTERPRISE WORKER: getNextEmail result', [
                //         'worker_id' => $workerId,
                //         'iteration' => $i,
                //         'emailData' => $emailData,
                //         'has_data' => !empty($emailData)
                //     ]);
                // }

                if (!$emailData) {
                    // No emails in queue, implement progressive backoff
                    static $emptyQueueCount = 0;
                    $emptyQueueCount++;

                    // Progressive sleep: 1s -> 2s -> 4s -> 8s -> 16s -> 32s -> 60s (max)
                    $sleepTime = min(60, max(1, pow(2, floor($emptyQueueCount / 3))));

                    // Queue empty - minimal logging
                    if ($emptyQueueCount % 50 === 0) { // Log every 50th empty check
                        Logger::debug('PERFORMANCE: ENTERPRISE WORKER: Queue empty, sleeping', [
                            'worker_id' => $workerId,
                            'empty_count' => $emptyQueueCount,
                        ]);
                    }

                    sleep($sleepTime);

                    // Reset counter if we've been sleeping too long (prevents infinite growth)
                    if ($emptyQueueCount > 20) {
                        $emptyQueueCount = 0;
                    }

                    break; // No more emails to process
                }

                if ($this->processEmail($emailData, $workerId)) {
                    Logger::email('info', 'EMAIL: ENTERPRISE WORKER: Email processed successfully', [
                        'worker_id' => $workerId,
                        'job_id' => $emailData['job_id'] ?? 'unknown',
                    ]);
                    $this->markAsCompleted($emailData);
                    $processed++;
                } else {
                    Logger::email('error', 'EMAIL: ENTERPRISE WORKER: Email processing failed', [
                        'worker_id' => $workerId,
                        'job_id' => $emailData['job_id'] ?? 'unknown',
                    ]);
                    $this->markAsFailed($emailData, 'Email processing failed');
                }
            }

            $this->unregisterWorker($workerId);

            return $processed;

        } catch (\Exception $e) {
            Logger::error('PERFORMANCE: AsyncEmailQueue: Individual processing failed', [
                'error' => $e->getMessage(),
                'processed' => $processed,
            ]);

            if (isset($workerId)) {
                $this->unregisterWorker($workerId);
            }

            return $processed;
        }
    }

    /**
     * Process single email
     */
    private function processEmail(array $emailData, string $workerId): bool
    {
        try {
            $startTime = microtime(true);

            // Update metrics record to 'processing' status with worker_id and timing
            if (in_array($emailData['type'], ['verification', 'welcome', 'account_recovery']) && !empty($emailData['email'])) {
                $this->updateEmailProcessingStatus($emailData, $workerId, $startTime);
            }

            // Route to appropriate handler based on email type
            $success = match($emailData['type'] ?? 'generic') {
                'verification' => $this->sendVerificationEmail($emailData),
                'password_reset' => $this->sendPasswordResetEmail($emailData),
                'welcome' => $this->sendWelcomeEmail($emailData),
                'account_recovery' => $this->sendAccountRecoveryEmail($emailData),
                'account_deletion_goodbye' => $this->sendAccountDeletionGoodbyeEmail($emailData),
                'account_suspension_3rd_deletion' => $this->sendAccountSuspensionEmail($emailData), // 🚀 ENTERPRISE: 3rd deletion
                'notification' => $this->sendNotificationEmail($emailData),
                default => $this->sendGenericEmail($emailData)
            };

            $processingTime = microtime(true) - $startTime;
            $processingTimeMs = (int)round($processingTime * 1000);  // ENTERPRISE FIX: Cast to INT (database column type)

            // ENTERPRISE: Update metrics with processing time and worker_id based on email type
            if (in_array($emailData['type'], ['verification', 'welcome', 'account_recovery']) && !empty($emailData['email'])) {
                $this->updateEmailVerificationMetrics($emailData, $processingTimeMs, $workerId, $success);
            } elseif ($emailData['type'] === 'password_reset' && !empty($emailData['user_id'])) {
                // ENTERPRISE: Update password_reset_metrics with worker_id and processing_time_ms
                $this->updatePasswordResetMetrics($emailData, $processingTimeMs, $workerId, $success);
            } elseif ($emailData['type'] === 'report' && !empty($emailData['metadata']['report_id'])) {
                // ENTERPRISE GALAXY: Update report email metrics (report_email_performance + hourly/daily)
                if ($success) {
                    try {
                        \Need2Talk\Services\ReportEmailService::updateEmailMetrics(
                            $emailData['metadata']['report_id'],
                            $processingTimeMs,  // send_duration_ms
                            $workerId           // worker_id
                        );
                        Logger::email('info', 'EMAIL: Report email sent successfully', [
                            'report_id' => $emailData['metadata']['report_id'],
                            'email' => $emailData['email'],
                            'processing_time_ms' => $processingTimeMs,
                            'worker_id' => $workerId,
                        ]);
                    } catch (\Exception $e) {
                        Logger::email('error', 'EMAIL: Failed to update report metrics after send', [
                            'report_id' => $emailData['metadata']['report_id'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // ENTERPRISE: Log ALL email sends (success + failure)
            if ($success) {
                Logger::email('info', 'EMAIL: Email sent successfully', [
                    'job_id' => $emailData['job_id'],
                    'type' => $emailData['type'] ?? 'generic',
                    'email' => hash('sha256', $emailData['email'] ?? ''),
                    'processing_time_ms' => $processingTimeMs,
                    'worker_id' => $workerId,
                ]);
            } else {
                Logger::email('warning', 'EMAIL: Email processing failed', [
                    'job_id' => $emailData['job_id'],
                    'type' => $emailData['type'] ?? 'generic',
                    'processing_time_ms' => $processingTimeMs,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->logEnterpriseError('EMAIL_PROCESSING_ERROR', $emailData);
            Logger::email('error', 'EMAIL: Email processing failed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Update email to processing status with timing (UPSERT logic)
     */
    private function updateEmailProcessingStatus(array $emailData, string $workerId, float $startTime): void
    {
        try {
            $pdo = db_pdo();
            $email = $emailData['email'] ?? 'unknown';

            // GALAXY: PostgreSQL timezone set via TZ=Europe/Rome in docker-compose.yml (auto-adjusts DST)

            // ENTERPRISE TIPS: Use metrics_record_id from queue data (passed from queueEmail)
            $metricsRecordId = $emailData['metrics_record_id'] ?? null;

            if (!$metricsRecordId) {
                Logger::warning('DEFAULT: No metrics_record_id in email data, cannot update processing status', [
                    'email' => $email,
                    'worker_id' => $workerId,
                    'job_id' => $emailData['job_id'] ?? 'unknown',
                ]);

                return;
            }

            // Read the specific record by ID to get created_at for queue time calculation
            $selectStmt = $pdo->prepare('SELECT id, created_at, user_id FROM email_verification_metrics WHERE id = ?');
            $selectStmt->execute([$metricsRecordId]);
            $record = $selectStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$record) {
                Logger::error('DEFAULT: Record not found by metrics_record_id', [
                    'metrics_record_id' => $metricsRecordId,
                    'email' => $email,
                    'worker_id' => $workerId,
                    'action' => 'cannot_update_processing_status',
                ]);

                return;
            }

            // Update the specific record - DON'T touch queue_time_ms yet (STEP 3 will calculate it)
            $updateStmt = $pdo->prepare('
                UPDATE email_verification_metrics
                SET status = \'processed_by_worker\',
                    worker_id = ?
                WHERE id = ?
            ');

            $result = $updateStmt->execute([$workerId, $record['id']]);

            if (!$result) {
                Logger::error('DEFAULT: Failed to update processing status', [
                    'record_id' => $record['id'],
                    'email' => $email,
                    'worker_id' => $workerId,
                    'database_update_failed' => true,
                ]);
            }

        } catch (\Exception $e) {
            Logger::error('DEFAULT: Error updating email processing status', [
                'email' => $emailData['email'] ?? 'unknown',
                'metrics_record_id' => $emailData['metrics_record_id'] ?? null,
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send verification email using existing EmailVerificationService
     * ENTERPRISE INTEGRATION: Use existing services instead of duplicating logic
     */
    private function sendVerificationEmail(array $emailData): bool
    {
        try {

            // Extract data for EmailVerificationService
            $userId = $emailData['user_id'] ?? 0;
            $email = $emailData['email'] ?? '';
            $nickname = $emailData['template_data']['user_data']['nickname'] ?? '';

            // 🚀 ENTERPRISE UUID-FIRST: Extract UUID for race-condition-free processing
            $userUuid = $emailData['user_uuid'] ?? null;

            // 🚀 ENTERPRISE UUID-FIRST PROCESSING: Prioritize UUID over user_id
            if (!empty($userUuid)) {
                // Get user_id from UUID with Redis L1 caching
                $resolvedUserId = $this->getUserIdFromUuidCached($userUuid);

                if ($resolvedUserId) {
                    $userId = $resolvedUserId;
                } else {
                    Logger::email('error', 'EMAIL: Failed to resolve user_id from UUID', [
                        'uuid' => $userUuid,
                        'email' => $email,
                        'fallback_action' => 'will_try_legacy_approach',
                        'resolution_failed' => true,
                    ]);
                    // Continue with legacy approach as fallback
                }
            }

            // ENTERPRISE LEGACY FALLBACK: Handle missing UUID or user_id = 0 from old race conditions
            if ((empty($userUuid) || $userId === 0) && !empty($email)) {
                // Look up user_id and UUID from database
                $pdo = db_pdo();
                $stmt = $pdo->prepare('SELECT id, uuid FROM users WHERE email = ? ORDER BY created_at DESC LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($user) {
                    $userId = (int) $user['id'];
                    $userUuid = $user['uuid']; // Store UUID for caching

                    // Cache the UUID->user_id mapping for future requests
                    if (!empty($userUuid)) {
                        $this->cacheUserIdUuidMapping($userUuid, $userId);
                    }
                } else {
                    Logger::email('error', 'EMAIL: User not found for email in queue', [
                        'email' => $email,
                        'nickname' => $nickname,
                        'database_status' => 'user_not_found',
                        'action' => 'email_processing_aborted',
                    ]);

                    // ENTERPRISE GALAXY: Track specific error for batch processing
                    $this->batchEmailErrors[] = 'user_not_found: ' . $email;

                    return false;
                }
            }

            // Final validation after potential user_id fix
            if ($userId === 0 || empty($email) || empty($nickname)) {
                Logger::email('error', 'EMAIL: Invalid verification email data after validation', [
                    'user_id' => $userId,
                    'email' => $email,
                    'nickname' => $nickname,
                    'full_email_data' => $emailData,
                    'validation_errors' => [
                        'user_id_zero' => $userId === 0,
                        'email_empty' => empty($email),
                        'nickname_empty' => empty($nickname),
                    ],
                ]);

                return false;
            }

            // ENTERPRISE: Verify user exists in database before sending
            $pdo = db_pdo();
            $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id = ? AND email = ?');
            $stmt->execute([$userId, $email]);
            $user = $stmt->fetch();

            if (!$user) {
                Logger::email('error', 'EMAIL: User not found for verification email', [
                    'provided_user_id' => $userId,
                    'provided_email' => $email,
                    'database_check' => 'user_not_found',
                    'action' => 'email_processing_aborted',
                ]);

                return false;
            }

            // ENTERPRISE: Use existing EmailVerificationService for proper token management
            if (class_exists('\\Need2Talk\\Services\\EmailVerificationService')) {

                $verificationService = new \Need2Talk\Services\EmailVerificationService();

                // 🚀 ENTERPRISE WORKER: Use proper EmailVerificationService method
                // Based on context: registration vs resend
                $context = $emailData['context'] ?? 'initial';

                // ENTERPRISE TIPS: Extract or generate worker_id for metrics consistency
                $workerId = $emailData['worker_id'] ?? 'worker_' . uniqid() . '_' . getmypid();

                // ENTERPRISE TIPS: Prioritize UUID over context to prevent duplicate records
                // If UUID is available, ALWAYS use UUID-first flow regardless of context
                if (!empty($userUuid)) {
                    // UUID-FIRST FLOW: Use existing token with bypass flag

                    // WORKER CONTEXT: Pass bypass flag, email context, and metrics_record_id for race-condition-free updates
                    $result = $verificationService->sendVerificationEmailByUuid($userUuid, $email, $nickname, [
                        'worker_bypass' => true,
                        'email_context' => $context, // Pass through registration/resend context for idempotency
                        'metrics_record_id' => $emailData['metrics_record_id'] ?? null, // ENTERPRISE: Specific record ID for thread-safe updates
                        'worker_id' => $workerId, // Pass worker ID for metrics consistency
                    ]);
                } elseif ($context === 'resend') {
                    // LEGACY RESEND FLOW: Only for emails without UUID (old queue entries)

                    // ENTERPRISE TIPS: NEVER read tokens from database - always use EmailVerificationService
                    // The database contains HASHED tokens, not plain text tokens for URLs

                    require_once __DIR__ . '/EmailVerificationService.php';
                    $emailVerificationService = new EmailVerificationService();

                    // Generate NEW verification token and send directly
                    $result = $emailVerificationService->sendVerificationEmail($userId, $email, $nickname);

                    if ($result['success']) {
                        return true;
                    }
                    Logger::email('error', 'EMAIL: ENTERPRISE WORKER: EmailVerificationService legacy resend failed', [
                        'user_id' => $userId,
                        'email' => $email,
                        'error' => $result['error'] ?? 'unknown',
                        'flow' => 'legacy_resend',
                    ]);

                    return false;

                } else {
                    // LEGACY REGISTRATION FLOW: For emails without UUID

                    $result = $verificationService->sendVerificationEmail($userId, $email, $nickname);
                }

                return $result['success'] ?? false;
            }

            // Fallback: Use EmailService directly if EmailVerificationService not available
            if (class_exists('\\Need2Talk\\Services\\EmailService')) {
                $emailService = new \Need2Talk\Services\EmailService();
                $verificationUrl = $emailData['template_data']['verification_url'] ?? '';

                $result = $emailService->sendVerificationEmail($email, $nickname, $verificationUrl);

                return $result;
            }

            Logger::email('error', 'EMAIL: No email service available!', [
                'worker_step' => 'no_service_available',
                'available_classes' => [
                    'EmailVerificationService' => class_exists('\\Need2Talk\\Services\\EmailVerificationService'),
                    'EmailService' => class_exists('\\Need2Talk\\Services\\EmailService'),
                ],
                'action' => 'falling_back_to_direct_smtp',
            ]);

            // Final fallback: Use SMTP directly
            $subject = $this->buildEmailSubject('verification', $emailData);
            $body = $this->buildEmailBody('verification', $emailData);
            $result = $this->sendEmailViaSMTP($email, $subject, $body, $emailData['template_data'] ?? []);

            return $result;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: ENTERPRISE WORKER: CRITICAL FAILURE in sendVerificationEmail', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'email' => $emailData['email'] ?? 'unknown',
                'user_id' => $emailData['user_id'] ?? 'unknown',
                'user_uuid' => $emailData['user_uuid'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'worker_crash' => true,
                'email_processing_failed' => true,
            ]);

            return false;
        } catch (\Error $e) {
            Logger::email('error', 'EMAIL: ENTERPRISE WORKER: FATAL ERROR in sendVerificationEmail', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'email' => $emailData['email'] ?? 'unknown',
                'fatal_error_message' => $e->getMessage(),
                'fatal_error_file' => $e->getFile(),
                'fatal_error_line' => $e->getLine(),
                'fatal_error_trace' => $e->getTraceAsString(),
                'worker_fatal_crash' => true,
                'email_processing_fatal' => true,
            ]);

            return false;
        }
    }

    /**
     * Send password reset email
     */
    private function sendPasswordResetEmail(array $emailData): bool
    {
        try {
            $subject = $this->buildEmailSubject('password_reset', $emailData);
            $body = $this->buildEmailBody('password_reset', $emailData);

            return $this->sendEmailViaSMTP($emailData['email'], $subject, $body, $emailData['template_data'] ?? []);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: AsyncEmailQueue: Password reset email failed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'email_type' => 'password_reset',
            ]);

            return false;
        }
    }

    /**
     * Send welcome email (post-verification)
     * ENTERPRISE: Welcome email sent after successful email verification
     */
    private function sendWelcomeEmail(array $emailData): bool
    {
        try {
            $subject = $this->buildEmailSubject('welcome', $emailData);
            $body = $this->buildEmailBody('welcome', $emailData);

            $result = $this->sendEmailViaSMTP($emailData['email'], $subject, $body, $emailData['template_data'] ?? []);

            // ENTERPRISE: Log welcome email success
            if ($result) {
                Logger::email('info', 'EMAIL: Welcome email sent successfully', [
                    'user_id' => $emailData['user_id'] ?? 'unknown',
                    'email_hash' => hash('sha256', $emailData['email']),
                    'job_id' => $emailData['job_id'] ?? 'unknown',
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: AsyncEmailQueue: Welcome email failed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'user_id' => $emailData['user_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'email_type' => 'welcome',
            ]);

            return false;
        }
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Send account recovery notification email
     *
     * Processes account recovery email with GDPR compliance messaging
     * - Uses account-recovery-notification.php template
     * - Includes security audit trail (IP, recovery method, timestamp)
     * - Enterprise metrics tracking (type: notification)
     *
     * @param array $emailData Email data with recovery information
     * @return bool True if sent successfully, false otherwise
     */
    private function sendAccountRecoveryEmail(array $emailData): bool
    {
        try {
            // ENTERPRISE: Build subject and body using template system
            $subject = $this->buildEmailSubject('account_recovery', $emailData);
            $body = $this->buildEmailBody('account_recovery', $emailData);

            $result = $this->sendEmailViaSMTP($emailData['email'], $subject, $body, $emailData['template_data'] ?? []);

            // ENTERPRISE: Log account recovery email success
            if ($result) {
                Logger::email('info', 'EMAIL: Account recovery notification sent successfully', [
                    'user_id' => $emailData['user_id'] ?? 'unknown',
                    'email_hash' => hash('sha256', $emailData['email']),
                    'job_id' => $emailData['job_id'] ?? 'unknown',
                    'recovery_method' => $emailData['metadata']['recovery_method'] ?? 'unknown',
                    'recovery_ip' => $emailData['metadata']['recovery_ip'] ?? 'unknown',
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Account recovery notification failed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'user_id' => $emailData['user_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'email_type' => 'account_recovery',
                'recovery_method' => $emailData['metadata']['recovery_method'] ?? 'unknown',
            ]);

            return false;
        }
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Send account deletion goodbye email
     *
     * Processes account deletion goodbye email with GDPR compliance messaging
     * - Uses account-deletion-goodbye.php template
     * - Includes deletion timeline, grace period, recovery link
     * - Enterprise metrics tracking (type: account_deletion_goodbye)
     *
     * @param array $emailData Email data with deletion information
     * @return bool True if sent successfully, false otherwise
     */
    private function sendAccountDeletionGoodbyeEmail(array $emailData): bool
    {
        try {
            // ENTERPRISE: Build subject and body using template system
            $subject = $this->buildEmailSubject('account_deletion_goodbye', $emailData);
            $body = $this->buildEmailBody('account_deletion_goodbye', $emailData);

            $result = $this->sendEmailViaSMTP($emailData['email'], $subject, $body, $emailData['template_data'] ?? []);

            // ENTERPRISE: Log account deletion goodbye email success (email_type: notification)
            if ($result) {
                Logger::email('info', 'EMAIL: Account deletion goodbye sent successfully', [
                    'user_id' => $emailData['user_id'] ?? 'unknown',
                    'email_hash' => hash('sha256', $emailData['email']),
                    'job_id' => $emailData['job_id'] ?? 'unknown',
                    'scheduled_deletion_at' => $emailData['template_data']['scheduled_deletion_at'] ?? 'unknown',
                    'email_type' => 'notification',  // ENTERPRISE: Registrato come notification per metrics
                    'notification_subtype' => 'account_deletion_goodbye',
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Account deletion goodbye failed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'user_id' => $emailData['user_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'email_type' => 'notification',  // ENTERPRISE: Registrato come notification per metrics
                'notification_subtype' => 'account_deletion_goodbye',
            ]);

            return false;
        }
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Send Account Suspension Email (3rd deletion)
     *
     * Email sent when user attempts 3rd deletion in 30 days.
     * Notifies user that account is SUSPENDED and they must contact support.
     *
     * @param array $emailData Email job data
     * @return bool Success status
     */
    private function sendAccountSuspensionEmail(array $emailData): bool
    {
        try {
            // ENTERPRISE: Build subject and body using template system
            $subject = $this->buildEmailSubject('account_suspension_3rd_deletion', $emailData);
            $body = $this->buildEmailBody('account_suspension_3rd_deletion', $emailData);

            $result = $this->sendEmailViaSMTP($emailData['email'], $subject, $body, $emailData['template_data'] ?? []);

            // ENTERPRISE: Log suspension email success
            if ($result) {
                Logger::email('info', 'EMAIL: Account suspension email sent successfully (3rd deletion)', [
                    'user_id' => $emailData['user_id'] ?? 'unknown',
                    'email_hash' => hash('sha256', $emailData['email']),
                    'job_id' => $emailData['job_id'] ?? 'unknown',
                    'hard_delete_deadline' => $emailData['template_data']['hard_delete_deadline'] ?? 'unknown',
                    'email_type' => 'notification',  // ENTERPRISE: Registrato come notification per metrics
                    'notification_subtype' => 'account_suspension_3rd_deletion',
                    'abuse_prevention' => true,
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Account suspension email failed (3rd deletion)', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'user_id' => $emailData['user_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'email_type' => 'notification',  // ENTERPRISE: Registrato come notification per metrics
                'notification_subtype' => 'account_suspension_3rd_deletion',
            ]);

            return false;
        }
    }

    /**
     * Send notification email
     */
    private function sendNotificationEmail(array $emailData): bool
    {
        try {
            $subject = $this->buildEmailSubject('notification', $emailData);
            $body = $this->buildEmailBody('notification', $emailData);

            return $this->sendEmailViaSMTP($emailData['email'], $subject, $body, $emailData['template_data'] ?? []);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: AsyncEmailQueue: Notification email failed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'email_type' => 'notification',
            ]);

            return false;
        }
    }

    /**
     * Send generic email
     */
    private function sendGenericEmail(array $emailData): bool
    {
        try {
            $subject = $this->buildEmailSubject('generic', $emailData);
            $body = $this->buildEmailBody('generic', $emailData);

            return $this->sendEmailViaSMTP($emailData['email'], $subject, $body, $emailData['template_data'] ?? []);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: AsyncEmailQueue: Generic email failed', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'email_type' => 'generic',
            ]);

            return false;
        }
    }

    /**
     * Enterprise SMTP sending with connection reuse
     */
    private function sendEmailViaSMTP(string $email, string $subject, string $body, array $templateData = []): bool
    {
        try {
            // ENTERPRISE INTEGRATION: Use existing EmailService for consistency
            if (class_exists('\\Need2Talk\\Services\\EmailService')) {
                $emailService = new \Need2Talk\Services\EmailService();

                return $emailService->send($email, $subject, $body);
            }

            // Fallback to direct PHPMailer if enterprise mailer doesn't exist
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host = EnterpriseGlobalsManager::getEnv('MAIL_HOST', 'smtp.ionos.it');
            $mail->SMTPAuth = true;
            $mail->Username = EnterpriseGlobalsManager::getEnv('MAIL_USERNAME', '');
            $mail->Password = EnterpriseGlobalsManager::getEnv('MAIL_PASSWORD', '');
            $mail->SMTPSecure = EnterpriseGlobalsManager::getEnv('MAIL_ENCRYPTION', 'tls');
            $mail->Port = (int) EnterpriseGlobalsManager::getEnv('MAIL_PORT', 587);

            // Email settings
            $mail->setFrom(
                EnterpriseGlobalsManager::getEnv('MAIL_FROM_ADDRESS', 'noreply@need2talk.com'),
                EnterpriseGlobalsManager::getEnv('MAIL_FROM_NAME', 'need2talk')
            );
            $mail->addAddress($email);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $body;

            return $mail->send();

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: AsyncEmailQueue: SMTP sending failed', [
                'email' => $email,
                'error' => $e->getMessage(),
                'smtp_host' => EnterpriseGlobalsManager::getEnv('MAIL_HOST', 'smtp.ionos.it'),
                'smtp_port' => EnterpriseGlobalsManager::getEnv('MAIL_PORT', 587),
            ]);

            return false;
        }
    }

    /**
     * Register worker for monitoring
     */
    private function registerWorker(): string
    {
        $workerId = 'worker_' . uniqid() . '_' . getmypid();
        $this->registerWorkerHeartbeat($workerId);

        return $workerId;
    }

    /**
     * Update worker heartbeat
     */
    private function updateWorkerHeartbeat(string $workerId): void
    {
        $this->registerWorkerHeartbeat($workerId);
    }

    /**
     * Collect batch of emails from queue
     */
    private function collectEmailBatch(int $maxBatchSize): array
    {
        $emails = [];
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return $emails;
        }

        for ($i = 0; $i < $maxBatchSize; $i++) {
            $result = $this->safeRedisCall($redis, 'zpopmin', [self::QUEUE_KEY]);

            // ENTERPRISE: $result verified non-empty by is_array + count check
            if ($result && is_array($result) && count($result) > 0) {
                // zpopmin returns array where key=JSON data, value=score
                $emailJson = array_keys($result)[0]; // Get first key (JSON data)
                $emailData = json_decode($emailJson, true);

                if ($emailData && is_array($emailData)) {
                    $emails[] = $emailData;
                } else {
                    Logger::email('warning', 'EMAIL: Invalid email JSON in Redis queue', [
                        'json_data' => $emailJson,
                        'decode_error' => json_last_error_msg(),
                        'action' => 'skipping_invalid_email',
                    ]);
                }
            } else {
                break; // No more emails
            }
        }

        return $emails;
    }

    /**
     * Group emails by type for batch optimization
     */
    private function groupEmailsByType(array $emailBatch): array
    {
        $grouped = [];

        foreach ($emailBatch as $email) {
            $type = $email['type'] ?? 'generic';

            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $email;
        }

        return $grouped;
    }

    /**
     * Process batch by type with shared SMTP connection
     */
    private function processBatchByType(string $emailType, array $typeEmails, string $workerId): array
    {
        $results = [];

        // ENTERPRISE GALAXY: Reset batch error tracking
        $this->batchEmailErrors = [];

        try {
            foreach ($typeEmails as $emailData) {
                $this->markAsProcessing($emailData, $workerId);
                $success = $this->processEmail($emailData, $workerId);
                $results[] = $success;

                // Track empty error if success (to maintain index alignment)
                if ($success) {
                    $this->batchEmailErrors[] = null;
                }
            }

        } catch (\Exception $e) {
            Logger::error('DEFAULT: Batch type processing failed', [
                'email_type' => $emailType,
                'count' => count($typeEmails),
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
                'processed' => count($results),
                'remaining' => count($typeEmails) - count($results),
            ]);

            // Mark remaining as failed
            while (count($results) < count($typeEmails)) {
                $results[] = false;
            }
        }

        return $results;
    }

    /**
     * Update batch results
     */
    private function updateBatchResults(array $emails, array $results): void
    {
        for ($i = 0; $i < count($emails); $i++) {
            $email = $emails[$i];
            $success = $results[$i] ?? false;

            if ($success) {
                $this->markAsCompleted($email);
            } else {
                // ENTERPRISE GALAXY: Use specific error if available, fallback to generic
                $error = $this->batchEmailErrors[$i] ?? 'Batch processing failed';
                $this->markAsFailed($email, $error);
            }
        }

        // ENTERPRISE GALAXY: Clear batch errors after processing
        $this->batchEmailErrors = [];
    }

    /**
     * Build email subject based on type
     */
    private function buildEmailSubject(string $emailType, array $emailData): string
    {
        $templateData = $emailData['template_data'] ?? [];

        return match($emailType) {
            'verification' => 'Verifica il tuo account need2talk',
            'password_reset' => 'Reset della password need2talk',
            'welcome' => 'Benvenuto in need2talk! 🎉',
            'account_recovery' => '🎉 Il tuo account need2talk è stato ripristinato!',
            'account_deletion_goodbye' => '👋 Richiesta di Cancellazione Account - need2talk',
            'account_suspension_3rd_deletion' => '⚠️ Account Sospeso - Contatta il Supporto - need2talk', // 🚀 ENTERPRISE
            'notification' => $templateData['subject'] ?? 'Notifica need2talk',
            default => $templateData['subject'] ?? 'need2talk'
        };
    }

    /**
     * Build email body based on type
     */
    private function buildEmailBody(string $emailType, array $emailData): string
    {
        $templateData = $emailData['template_data'] ?? [];

        return match($emailType) {
            'verification' => $templateData['body'] ?? 'Email di verifica need2talk', // Use existing EmailService templates
            'password_reset' => $templateData['body'] ?? $this->buildPasswordResetEmailBody($templateData), // ENTERPRISE: Use PasswordResetService template
            'welcome' => $templateData['body'] ?? $this->buildWelcomeEmailBody($templateData), // ENTERPRISE: Welcome email post-verification
            'account_recovery' => $templateData['body'] ?? $this->buildAccountRecoveryEmailBody($templateData), // ENTERPRISE GALAXY: Account recovery notification
            'account_deletion_goodbye' => $templateData['body'] ?? $this->buildAccountDeletionGoodbyeEmailBody($templateData), // ENTERPRISE GALAXY: Account deletion goodbye
            'account_suspension_3rd_deletion' => $templateData['body'] ?? $this->buildAccountSuspensionEmailBody($templateData), // 🚀 ENTERPRISE: 3rd deletion suspension
            'notification' => $this->buildNotificationEmailBody($templateData),
            'generic', 'admin_bulk' => $this->buildAdminBulkEmailBody($emailData), // ENTERPRISE V12.4: Use admin_bulk_email.php template
            default => $templateData['body'] ?? 'Email da need2talk'
        };
    }

    // REMOVED: buildVerificationEmailBody() - Now using existing EmailVerificationService templates

    /**
     * Build password reset email body
     */
    private function buildPasswordResetEmailBody(array $templateData): string
    {
        $resetUrl = $templateData['reset_url'] ?? '';

        return "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Reset Password need2talk</h2>
            <p>Hai richiesto il reset della password. Clicca sul link qui sotto:</p>
            <p><a href='{$resetUrl}' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Reset Password</a></p>
            <p>Se non hai richiesto questo reset, ignora questa email.</p>
        </body>
        </html>
        ";
    }

    /**
     * Build welcome email body (post-verification)
     * ENTERPRISE: Renders welcome.php template with user data
     */
    private function buildWelcomeEmailBody(array $templateData): string
    {
        try {
            // Extract user data from template
            $userData = $templateData['user_data'] ?? [];
            $nickname = $userData['nickname'] ?? 'User';
            $email = $userData['email'] ?? '';

            // ENTERPRISE: Use template rendering with output buffering
            $templatePath = dirname(dirname(__DIR__)) . '/app/Views/emails/welcome.php';

            if (!file_exists($templatePath)) {
                Logger::email('error', 'EMAIL: Welcome template not found', [
                    'template_path' => $templatePath,
                    'fallback' => 'using_simple_html',
                ]);

                // Fallback to simple HTML if template not found
                return $this->buildSimpleWelcomeEmail($nickname);
            }

            // Define variables for template
            $companyName = 'need2talk';
            $supportEmail = 'support@need2talk.it';

            // Render template with output buffering
            ob_start();
            if (!defined('APP_ROOT')) {
                define('APP_ROOT', dirname(dirname(__DIR__)));
            }
            include $templatePath;
            $body = ob_get_clean();

            return $body;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Welcome email template rendering failed', [
                'error' => $e->getMessage(),
                'fallback' => 'using_simple_html',
            ]);

            // Fallback to simple HTML
            return $this->buildSimpleWelcomeEmail($templateData['user_data']['nickname'] ?? 'User');
        }
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Build account recovery email body
     *
     * Renders account-recovery-notification.php template with:
     * - User data (nickname, email)
     * - Recovery data (method, IP, timestamp, deletion request date)
     * - GDPR compliance messaging
     * - Security audit trail
     *
     * @param array $templateData Template data with user_data and recovery_data
     * @return string HTML email body
     */
    private function buildAccountRecoveryEmailBody(array $templateData): string
    {
        try {
            // Extract user and recovery data
            $userData = $templateData['user_data'] ?? [];
            $recoveryData = $templateData['recovery_data'] ?? [];

            $nickname = $userData['nickname'] ?? 'User';
            $email = $userData['email'] ?? '';
            $recovery_method = $recoveryData['method'] ?? 'google_oauth';
            $recovery_ip = $recoveryData['ip'] ?? 'Non disponibile';
            $recovery_date = $recoveryData['date'] ?? date('d/m/Y H:i');
            $deletion_requested_at = $recoveryData['deletion_requested_at'] ?? 'N/A';

            // ENTERPRISE: Use template rendering with output buffering
            $templatePath = dirname(dirname(__DIR__)) . '/app/Views/emails/account-recovery-notification.php';

            if (!file_exists($templatePath)) {
                Logger::email('error', 'EMAIL: Account recovery template not found', [
                    'template_path' => $templatePath,
                    'fallback' => 'using_simple_html',
                ]);

                // Fallback to simple HTML if template not found
                return $this->buildSimpleAccountRecoveryEmail($nickname, $recovery_method);
            }

            // Define variables for template
            $companyName = 'need2talk';
            $supportEmail = 'support@need2talk.it';

            // Render template with output buffering
            ob_start();
            if (!defined('APP_ROOT')) {
                define('APP_ROOT', dirname(dirname(__DIR__)));
            }
            include $templatePath;
            $body = ob_get_clean();

            return $body;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Account recovery template rendering failed', [
                'error' => $e->getMessage(),
                'fallback' => 'using_simple_html',
            ]);

            // Fallback to simple HTML
            return $this->buildSimpleAccountRecoveryEmail(
                $templateData['user_data']['nickname'] ?? 'User',
                $templateData['recovery_data']['method'] ?? 'google_oauth'
            );
        }
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Build account deletion goodbye email body
     *
     * Renders account-deletion-goodbye.php template with deletion information
     *
     * @param array $templateData Template data with deletion info
     * @return string Rendered HTML email body
     */
    private function buildAccountDeletionGoodbyeEmailBody(array $templateData): string
    {
        try {
            // Extract deletion data
            $nickname = $templateData['nickname'] ?? 'User';
            $email = $templateData['email'] ?? '';
            $scheduled_deletion_at = $templateData['scheduled_deletion_at'] ?? date('Y-m-d H:i:s');
            $reason = $templateData['reason'] ?? null;

            // ENTERPRISE: Use template rendering with output buffering
            $templatePath = dirname(dirname(__DIR__)) . '/app/Views/emails/account-deletion-goodbye.php';

            if (!file_exists($templatePath)) {
                Logger::email('error', 'EMAIL: Account deletion goodbye template not found', [
                    'template_path' => $templatePath,
                    'fallback' => 'using_simple_html',
                ]);

                // Fallback to simple HTML if template not found
                return $this->buildSimpleAccountDeletionGoodbyeEmail($nickname, $scheduled_deletion_at);
            }

            // Define variables for template
            $companyName = 'need2talk';
            $supportEmail = 'support@need2talk.it';

            // Render template with output buffering
            ob_start();
            if (!defined('APP_ROOT')) {
                define('APP_ROOT', dirname(dirname(__DIR__)));
            }
            include $templatePath;
            $body = ob_get_clean();

            return $body;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Account deletion goodbye template rendering failed', [
                'error' => $e->getMessage(),
                'fallback' => 'using_simple_html',
            ]);

            // Fallback to simple HTML
            return $this->buildSimpleAccountDeletionGoodbyeEmail(
                $templateData['nickname'] ?? 'User',
                $templateData['scheduled_deletion_at'] ?? date('Y-m-d H:i:s')
            );
        }
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Build simple account deletion goodbye email (fallback)
     *
     * @param string $nickname User nickname
     * @param string $scheduledDeletionAt Scheduled deletion timestamp
     * @return string Simple HTML email body
     */
    private function buildSimpleAccountDeletionGoodbyeEmail(string $nickname, string $scheduledDeletionAt): string
    {
        $deletionDate = date('d/m/Y', strtotime($scheduledDeletionAt));

        return "
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; background: #0a0a0f; color: #e5e7eb; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #1a1625; padding: 30px; border-radius: 10px; }
                h1 { color: #a78bfa; }
                .warning { background: rgba(239, 68, 68, 0.1); border-left: 4px solid #ef4444; padding: 15px; margin: 20px 0; }
                .info { background: rgba(139, 92, 246, 0.1); border-left: 4px solid #8b5cf6; padding: 15px; margin: 20px 0; }
                a { color: #a78bfa; text-decoration: none; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h1>👋 Richiesta di Cancellazione Account</h1>
                <p>Ciao <strong>{$nickname}</strong>,</p>
                <p>Abbiamo ricevuto la tua richiesta di cancellazione account su need2talk.</p>

                <div class='warning'>
                    <h3>⚠️ Account Sospeso</h3>
                    <p>Il tuo account è stato <strong>sospeso immediatamente</strong> e non è più accessibile.</p>
                </div>

                <div class='info'>
                    <h3>🕐 Periodo di Grazia: 30 Giorni</h3>
                    <p>La cancellazione definitiva avverrà il <strong>{$deletionDate}</strong>.</p>
                    <p>Durante questo periodo puoi <strong>recuperare il tuo account</strong> effettuando il login.</p>
                </div>

                <p style='text-align: center; margin-top: 30px;'>
                    <a href='https://need2talk.it/login' style='background: linear-gradient(135deg, #8b5cf6 0%, #a855f7 100%); color: white; padding: 15px 30px; border-radius: 10px; text-decoration: none; display: inline-block;'>
                        🔄 Recupera il Tuo Account
                    </a>
                </p>

                <p style='margin-top: 30px; font-size: 14px; color: #9ca3af;'>
                    Grazie per aver fatto parte della community need2talk. Ci mancherai! 💜
                </p>

                <hr style='border: 1px solid rgba(139, 92, 246, 0.2); margin: 30px 0;'>

                <p style='font-size: 12px; color: #6b7280; text-align: center;'>
                    need2talk - Il tuo spazio per esprimere emozioni<br>
                    Per assistenza: <a href='mailto:support@need2talk.it'>support@need2talk.it</a>
                </p>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Build simple account recovery email (fallback)
     *
     * @param string $nickname User nickname
     * @param string $recoveryMethod Recovery method
     * @return string HTML email body
     */
    private function buildSimpleAccountRecoveryEmail(string $nickname, string $recoveryMethod): string
    {
        $baseUrl = $_ENV['APP_URL'] ?? 'https://need2talk.it';
        $methodLabel = match($recoveryMethod) {
            'google_oauth' => '🔐 Login con Google',
            'manual' => '🔗 Link di ripristino',
            'admin' => '👨‍💼 Intervento amministratore',
            default => '✅ Ripristino automatico',
        };

        return "
        <html>
        <body style='font-family: Arial, sans-serif; background-color: #000; color: #fff; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto; background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%); padding: 40px; border-radius: 12px;'>
                <h1 style='color: #10b981; text-align: center;'>🎉 Account Ripristinato!</h1>
                <h2 style='color: #fff; text-align: center;'>Bentornato, {$nickname}!</h2>
                <p style='color: #f6f6f6; font-size: 16px; text-align: center;'>
                    Il tuo account <strong style='color: #fff;'>need2talk</strong> è stato <strong style='color: #10b981;'>ripristinato con successo</strong>.
                </p>
                <div style='background-color: rgba(16, 185, 129, 0.1); border-left: 4px solid #10b981; padding: 20px; margin: 24px 0; border-radius: 8px;'>
                    <p style='color: #f6f6f6; font-size: 14px; margin: 0;'>
                        <strong>Metodo ripristino:</strong> {$methodLabel}
                    </p>
                </div>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$baseUrl}' style='display: inline-block; padding: 16px 40px; background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                        🚀 Accedi al tuo Account
                    </a>
                </div>
                <p style='color: #c3cbd6ff; font-size: 14px; text-align: center;'>
                    Grazie per essere parte della community <strong>need2talk</strong>! 💜
                </p>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Build simple welcome email (fallback)
     */
    private function buildSimpleWelcomeEmail(string $nickname): string
    {
        $baseUrl = $_ENV['APP_URL'] ?? 'https://need2talk.it';

        return "
        <html>
        <body style='font-family: Arial, sans-serif; background-color: #000; color: #fff; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto; background: linear-gradient(135deg, #1a0f2e 0%, #2d1b4e 50%, #0f0824 100%); padding: 40px; border-radius: 12px;'>
                <h1 style='color: #ec4899; text-align: center;'>🎉 Benvenuto in need2talk!</h1>
                <h2 style='color: #fff; text-align: center;'>Ciao {$nickname}!</h2>
                <p style='color: #f6f6f6; font-size: 16px; text-align: center;'>
                    La tua email è stata verificata e il tuo account è ora <strong style='color: #10b981;'>attivo</strong>.
                </p>
                <p style='color: #f6f6f6; font-size: 16px; text-align: center;'>
                    Sei pronto per iniziare a condividere le tue emozioni con chi ti capisce veramente.
                </p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$baseUrl}/profile' style='display: inline-block; padding: 16px 32px; background: linear-gradient(135deg, #9333ea 0%, #ec4899 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                        🚀 Vai al tuo Profilo
                    </a>
                </div>
                <p style='color: #c3cbd6ff; font-size: 14px; text-align: center;'>
                    - Il Team di need2talk
                </p>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Build notification email body
     */
    private function buildNotificationEmailBody(array $templateData): string
    {
        $message = $templateData['message'] ?? 'Hai una nuova notifica su need2talk';
        $actionUrl = $templateData['action_url'] ?? '';

        $body = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>need2talk Notification</h2>
            <p>{$message}</p>
        ";

        if ($actionUrl) {
            $body .= "<p><a href='{$actionUrl}' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Visualizza</a></p>";
        }

        $body .= '
        </body>
        </html>
        ';

        return $body;
    }

    /**
     * ENTERPRISE V12.4: Build admin bulk email using professional template
     *
     * Uses app/Views/emails/admin_bulk_email.php for consistent branding.
     * Template variables:
     * - $subject: Email subject
     * - $nickname: Recipient nickname
     * - $recipient_email: Recipient email
     * - $message: Main message body
     * - $additional_info: Optional additional info
     * - $cta_url + $cta_text: Optional call-to-action button
     * - $admin_name + $admin_email: Optional admin info
     *
     * @param array $emailData Full email data from queue
     * @return string Rendered HTML email body
     */
    private function buildAdminBulkEmailBody(array $emailData): string
    {
        $templateData = $emailData['template_data'] ?? [];

        // Extract variables for template
        $subject = $templateData['subject'] ?? 'Messaggio dall\'Amministrazione';
        $nickname = $templateData['nickname'] ?? null;
        $recipient_email = $emailData['email'] ?? '';
        $message = $templateData['body'] ?? $templateData['message'] ?? '';
        $additional_info = $templateData['additional_info'] ?? null;
        $cta_url = $templateData['cta_url'] ?? null;
        $cta_text = $templateData['cta_text'] ?? null;
        $admin_name = $templateData['admin_name'] ?? null;
        $admin_email = $templateData['admin_email'] ?? null;

        // Try to render the professional template
        $templatePath = __DIR__ . '/../Views/emails/admin_bulk_email.php';

        if (file_exists($templatePath)) {
            try {
                ob_start();
                include $templatePath;
                $renderedBody = ob_get_clean();

                if (!empty($renderedBody)) {
                    return $renderedBody;
                }
            } catch (\Exception $e) {
                Logger::email('warning', 'EMAIL: Failed to render admin_bulk_email template, using fallback', [
                    'error' => $e->getMessage(),
                    'template_path' => $templatePath,
                ]);
            }
        }

        // Fallback: Simple HTML if template fails
        return "
        <html>
        <body style='font-family: Arial, sans-serif; padding: 20px;'>
            <h2>need2talk - Messaggio dall'Amministrazione</h2>
            <p>Ciao " . htmlspecialchars($nickname ?? $recipient_email) . ",</p>
            <div style='padding: 15px; background: #f5f5f5; border-left: 4px solid #667eea; margin: 20px 0;'>
                " . nl2br(htmlspecialchars($message)) . "
            </div>
            <p style='color: #666; font-size: 12px;'>© " . date('Y') . " need2talk</p>
        </body>
        </html>
        ";
    }

    /**
     * Log enterprise error
     */
    private function logEnterpriseError(string $errorType, array $emailData): void
    {
        Logger::error('DEFAULT: Enterprise email system error - ' . $errorType, [
            'error_type' => $errorType,
            'job_id' => $emailData['job_id'] ?? 'unknown',
            'email_type' => $emailData['type'] ?? 'unknown',
            'email' => $emailData['email'] ?? 'unknown',
            'attempts' => $emailData['attempts'] ?? 0,
            'system_health' => $this->getSystemHealthMetrics(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get user_id from UUID with Redis caching
     */
    private function getUserIdFromUuid(string $uuid): ?int
    {
        try {
            // Try cache first (Redis L1)
            $cacheKey = "uuid_to_id:$uuid";
            $redis = $this->redisManager->getConnection('L1_cache');

            if ($redis) {
                $cachedId = $redis->get($cacheKey);

                if ($cachedId !== false) {
                    return (int) $cachedId;
                }
            }

            // Query database
            $pdo = db_pdo();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE uuid = ? LIMIT 1');
            $stmt->execute([$uuid]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result) {
                $userId = (int) $result['id'];

                // Cache for 1 hour
                if ($redis) {
                    $redis->setex($cacheKey, 3600, $userId);
                }

                return $userId;
            }

            return null;

        } catch (\Exception $e) {
            Logger::error('DEFAULT: getUserIdFromUuid failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
                'uuid_length' => strlen($uuid),
                'uuid_format_valid' => $this->isValidUuid($uuid),
            ]);

            return null;
        }
    }

    /**
     * ENTERPRISE: Validate UUID format (ALL VERSIONS)
     * Supporta UUID v1, v3, v4, v5 per massima compatibilità con database existenti
     * Critical per migliaia di utenti con UUID diversi in vari sistemi legacy
     */
    private function isValidUuid(string $uuid): bool
    {
        // ENTERPRISE GRADE: Accept ANY valid UUID format (v1-v5)
        // Pattern: 8 hex - 4 hex - 4 hex - 4 hex - 12 hex
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    /**
     * 🚀 ENTERPRISE: Get user_id from UUID with Redis L1 caching for maximum performance
     * Integrates with existing Redis infrastructure for 100k+ concurrent users
     */
    private function getUserIdFromUuidCached(string $uuid): ?int
    {
        try {
            // Validate UUID format first
            if (!$this->isValidUuid($uuid)) {
                Logger::error('DEFAULT: Invalid UUID format', [
                    'uuid' => $uuid,
                    'uuid_length' => strlen($uuid),
                ]);

                return null;
            }

            // Try Redis L1 cache first (fastest path)
            $cacheKey = "uuid_to_id:$uuid";
            $redis = $this->redisManager->getConnection('L1_cache');

            if ($redis) {
                $cachedId = $this->safeRedisCall($redis, 'get', [$cacheKey]);

                if ($cachedId !== false && $cachedId !== null) {
                    // UUID cache hit - removed verbose logging
                    return (int) $cachedId;
                }
            }

            // Query database with connection pooling
            $pdo = db_pdo();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE uuid = ? LIMIT 1');
            $stmt->execute([$uuid]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result) {
                $userId = (int) $result['id'];

                // Cache for 1 hour in Redis L1 for subsequent requests
                if ($redis) {
                    $this->safeRedisCall($redis, 'setex', [$cacheKey, 3600, $userId]);
                }

                return $userId;
            }

            Logger::warning('DEFAULT: User not found by UUID', [
                'uuid' => $uuid,
                'database_status' => 'user_not_found',
            ]);

            return null;

        } catch (\Exception $e) {
            Logger::error('DEFAULT: getUserIdFromUuidCached failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 🚀 ENTERPRISE: Cache UUID->user_id mapping for performance optimization
     */
    private function cacheUserIdUuidMapping(string $uuid, int $userId): void
    {
        try {
            if (!$this->isValidUuid($uuid) || $userId <= 0) {
                return;
            }

            $cacheKey = "uuid_to_id:$uuid";
            $redis = $this->redisManager->getConnection('L1_cache');

            if ($redis) {
                // Cache for 1 hour
                $this->safeRedisCall($redis, 'setex', [$cacheKey, 3600, $userId]);
            }

        } catch (\Exception $e) {
            Logger::error('DEFAULT: Failed to cache UUID mapping', [
                'uuid' => $uuid,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update email_verification_metrics with processing time and worker_id
     */
    private function updateEmailVerificationMetrics(array $emailData, float $processingTimeMs, string $workerId, bool $success): void
    {
        try {
            $email = $emailData['email'];
            $context = $emailData['context'] ?? 'initial';

            $pdo = db_pdo();

            // GALAXY: PostgreSQL timezone set via TZ=Europe/Rome in docker-compose.yml (auto-adjusts DST)

            // ENTERPRISE TIPS: Use metrics_record_id from queue data (passed from queueEmail)
            $metricsRecordId = $emailData['metrics_record_id'] ?? null;

            if (!$metricsRecordId) {
                Logger::warning('DEFAULT: No metrics_record_id in email data, cannot update metrics', [
                    'email' => $email,
                    'worker_id' => $workerId,
                    'job_id' => $emailData['job_id'] ?? 'unknown',
                ]);

                return;
            }

            // Update the specific record by ID
            $status = $success ? 'sent_successfully' : 'send_failed';

            // ENTERPRISE GALAXY FIX: Read created_at (TIMESTAMP) instead of queue_time_ms
            // queue_time_ms is NULL at INSERT, we calculate milliseconds from created_at
            $selectStmt = $pdo->prepare('SELECT EXTRACT(EPOCH FROM created_at) as created_timestamp FROM email_verification_metrics WHERE id = ?');
            $selectStmt->execute([$metricsRecordId]);
            $row = $selectStmt->fetch(\PDO::FETCH_ASSOC);
            $queueTimestamp = (float) ($row['created_timestamp'] ?? 0);

            $currentTime = microtime(true);

            // Calculate elapsed time in milliseconds from created_at
            $queueTimeMs = 0;
            if ($queueTimestamp > 1000000000) { // Valid Unix timestamp
                $elapsedSeconds = $currentTime - $queueTimestamp;
                $queueTimeMs = (int) round($elapsedSeconds * 1000);
            } else {
                Logger::error('DEFAULT: Invalid created_at timestamp', [
                    'metrics_record_id' => $metricsRecordId,
                    'created_timestamp' => $queueTimestamp,
                    'expected' => 'Unix timestamp > 1000000000',
                ]);
            }

            // Update the existing record with processing metrics using the specific ID
            $updateStmt = $pdo->prepare('
                UPDATE email_verification_metrics
                SET processing_time_ms = ?,
                    queue_time_ms = ?,
                    worker_id = ?,
                    status = ?
                WHERE id = ?
            ');

            $result = $updateStmt->execute([$processingTimeMs, $queueTimeMs, $workerId, $status, $metricsRecordId]);

            if (!$result) {
                Logger::error('DEFAULT: Failed to update metrics record', [
                    'metrics_record_id' => $metricsRecordId,
                    'email' => $email,
                ]);
            }

            if ($result) {
                // ENTERPRISE TIPS: Update hourly/daily metrics ONLY for registration emails
                // RESEND emails are already counted by EmailMetricsService to prevent duplication
                if ($this->unifiedMetrics && $status === 'sent_successfully' && $context !== 'resend') {
                    // Calculate email size using unified method
                    $emailSize = $this->calculateRealEmailSize($email);

                    // 🚀 ENTERPRISE GALAXY: Map email types to correct category for metrics
                    // welcome + account_recovery → notification (as requested for GDPR admin dashboard)
                    // verification → verification
                    $emailType = $emailData['type'] ?? 'verification';
                    $metricsCategory = $this->mapEmailTypeToMetricsCategory($emailType);

                    // Call ONLY the system metrics update (hourly/daily tables)
                    // NOT recordEmailVerificationEvent which would duplicate email_verification_metrics
                    $systemMetrics = [
                        'processing_time_ms' => $processingTimeMs,
                        'worker_id' => $workerId,
                        'email_size' => $emailSize,
                        'timestamp' => time(),
                    ];

                    $context = [
                        'size' => $emailSize, // This populates total_size
                        'worker_id' => $workerId,
                        'context' => 'registration_worker',
                    ];

                    // Direct call to update only hourly/daily metrics with CORRECT category
                    $this->callSystemMetricsUpdate($metricsCategory, $status, $systemMetrics, $context);
                }
            }

        } catch (\Exception $e) {
            Logger::error('DEFAULT: Error updating email verification metrics', [
                'email' => $email,
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE: Update password_reset_metrics with worker_id and processing_time_ms
     * Updates the most recent record with final delivery status and worker information
     */
    private function updatePasswordResetMetrics(array $emailData, float $processingTimeMs, string $workerId, bool $success): void
    {
        try {
            $userId = $emailData['user_id'];
            $email = $emailData['email'] ?? 'unknown@need2talk.app';

            $pdo = db_pdo();

            // ENTERPRISE: Find the most recent sent_successfully record without worker info
            // This is the record created by PasswordResetService::recordMetric('email_sent')
            $stmt = $pdo->prepare("
                SELECT id FROM password_reset_metrics
                WHERE user_id = ?
                AND action = 'sent_successfully'
                AND worker_id IS NULL
                AND processing_time_ms IS NULL
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            Logger::email('info', 'EMAIL: Looking for password_reset_metrics record to update', [
                'user_id' => $userId,
                'email' => $email,
                'found_record' => $record ? 'YES' : 'NO',
                'record_id' => $record['id'] ?? 'N/A',
                'worker_id' => $workerId,
            ]);

            if ($record) {
                $finalStatus = $success ? 'sent_successfully' : 'send_failed';
                $metricsRecordId = $record['id'];

                // ================================================================
                // ENTERPRISE GALAXY FIX: Calculate queue_time_ms from created_at
                // ================================================================

                Logger::email('info', 'EMAIL: ✅ STEP 4 - WORKER: Reading created_at from password_reset_metrics', [
                    'metrics_record_id' => $metricsRecordId,
                    'sql_query' => 'SELECT EXTRACT(EPOCH FROM created_at) FROM password_reset_metrics WHERE id = ?',
                ]);

                // ENTERPRISE GALAXY FIX: Read created_at (TIMESTAMP) instead of queue_time_ms
                // queue_time_ms is NULL at INSERT, we calculate milliseconds from created_at
                $selectStmt = $pdo->prepare('SELECT EXTRACT(EPOCH FROM created_at) as created_timestamp FROM password_reset_metrics WHERE id = ?');
                $executeResult = $selectStmt->execute([$metricsRecordId]);
                $row = $selectStmt->fetch(\PDO::FETCH_ASSOC);
                $queueTimestamp = (float) ($row['created_timestamp'] ?? 0);

                if ($queueTimestamp === 0.0) {
                    Logger::error('DEFAULT: ❌ STEP 4 ERROR - Failed to read created_timestamp', [
                        'metrics_record_id' => $metricsRecordId,
                        'execute_result' => $executeResult,
                        'fetch_result' => $row,
                    ]);
                }

                // Calculate queue time in milliseconds
                $currentTime = microtime(true);
                $elapsedSeconds = $currentTime - $queueTimestamp;
                $queueTimeMs = (int) round($elapsedSeconds * 1000);

                Logger::email('info', 'EMAIL: ✅ STEP 5 - WORKER calculated queue time from created_at', [
                    'metrics_record_id' => $metricsRecordId,
                    'created_timestamp' => $queueTimestamp,
                    'current_time' => $currentTime,
                    'elapsed_seconds' => $elapsedSeconds,
                    'queue_time_ms_final' => $queueTimeMs,
                    'calculation' => "({$currentTime} - {$queueTimestamp}) * 1000 = {$queueTimeMs}",
                    'source_field' => 'created_at TIMESTAMP',
                ]);

                // ================================================================
                // UPDATE with worker_id, processing_time_ms, queue_time_ms, and final status
                // ================================================================

                Logger::email('info', 'EMAIL: Updating password_reset_metrics with worker info', [
                    'record_id' => $metricsRecordId,
                    'user_id' => $userId,
                    'email' => $email,
                    'new_status' => $finalStatus,
                    'worker_id' => $workerId,
                    'processing_time_ms' => $processingTimeMs,
                    'queue_time_ms' => $queueTimeMs,
                ]);

                $updateStmt = $pdo->prepare('
                    UPDATE password_reset_metrics
                    SET processing_time_ms = ?,
                        worker_id = ?,
                        action = ?,
                        queue_time_ms = ?
                    WHERE id = ?
                ');

                $result = $updateStmt->execute([
                    $processingTimeMs,
                    $workerId,
                    $finalStatus,
                    $queueTimeMs,
                    $metricsRecordId,
                ]);

                // ================================================================
                // ENTERPRISE GALAXY: Verify what was actually saved
                // ================================================================

                if ($result) {
                    // Verify the update
                    $verifyStmt = $pdo->prepare('SELECT queue_time_ms, processing_time_ms, action FROM password_reset_metrics WHERE id = ?');
                    $verifyStmt->execute([$metricsRecordId]);
                    $row = $verifyStmt->fetch(\PDO::FETCH_ASSOC);

                    Logger::email('info', 'EMAIL: ✅ STEP 6 - WORKER final UPDATE verified (ENTERPRISE GALAXY)', [
                        'metrics_record_id' => $metricsRecordId,
                        'update_success' => true,
                        'milliseconds_calculated_from_created_at' => $queueTimeMs,
                        'saved_queue_time_ms' => $row['queue_time_ms'] ?? 'N/A',
                        'saved_processing_time_ms' => $row['processing_time_ms'] ?? 'N/A',
                        'saved_status' => $row['action'] ?? 'N/A',
                        'values_match' => abs($queueTimeMs - ($row['queue_time_ms'] ?? 0)) < 1,
                        'architecture' => 'Uses created_at TIMESTAMP field, never stores Unix timestamp in queue_time_ms',
                        'FINAL_RESULT' => ($row['queue_time_ms'] ?? 0) > 0 ? '✅ SUCCESS: Milliseconds saved correctly!' : '❌ FAILED: Value is 0 or NULL!',
                    ]);
                } else {
                    Logger::email('error', 'EMAIL: Failed to update password_reset_metrics', [
                        'record_id' => $metricsRecordId,
                        'user_id' => $userId,
                        'error' => 'database_update_failed',
                    ]);
                }
            } else {
                Logger::email('warning', 'EMAIL: No pending password_reset_metrics record found', [
                    'user_id' => $userId,
                    'email' => $email,
                    'note' => 'metrics_record_missing_or_already_processed',
                ]);
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Error updating password_reset_metrics', [
                'user_id' => $emailData['user_id'] ?? null,
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE: Record email event metrics using unified system
     * Integrates with EnterpriseEmailMetricsUnified for all event types
     */
    private function recordEmailEventMetrics(array $emailData, string $status, array $additionalContext = []): ?int
    {
        if (!$this->unifiedMetrics) {
            return null; // Graceful degradation if metrics system not available
        }

        try {
            // ENTERPRISE TIPS: Skip password_reset emails - PasswordResetService handles its own metrics
            // This prevents duplicate records in email_verification_metrics
            $emailType = $emailData['type'] ?? 'generic';
            if ($emailType === 'password_reset') {
                Logger::email('debug', 'EMAIL: Skipping AsyncEmailQueue metrics for password_reset (handled by PasswordResetService)', [
                    'email_type' => $emailType,
                    'status' => $status,
                    'user_id' => $emailData['user_id'] ?? null,
                ]);

                return null;
            }

            // Extract user_id from email data
            $userId = $emailData['user_id'] ?? null;

            if (!$userId || $userId <= 0) {
                return null; // Cannot record metrics without valid user_id
            }

            // Build comprehensive context
            $context = array_merge([
                'email_type' => $emailData['type'] ?? 'unknown',
                'context' => $emailData['context'] ?? 'initial', // ENTERPRISE TIPS: Include resend context
                'queue_start_time' => $emailData['queued_at'] ?? microtime(true),
                'processing_time_ms' => isset($emailData['started_at'])
                    ? (int) round((microtime(true) - $emailData['started_at']) * 1000)
                    : null,
                'worker_id' => $emailData['worker_id'] ?? null,
                'job_id' => $emailData['job_id'] ?? null,
                'attempts' => $emailData['attempts'] ?? 0,
                'max_attempts' => $emailData['max_attempts'] ?? 5,
                'processing_method' => $emailData['processing_method'] ?? 'standard',
                'email_hash' => isset($emailData['email']) ? hash('sha256', $emailData['email']) : null,
            ], $additionalContext);

            // Add error details for failed statuses
            if (in_array($status, ['send_failed', 'critical_failure', 'queue_failed'], true)) {
                $context['error_code'] = $this->getErrorCode($emailData['last_error'] ?? '');
                $context['error_message'] = $emailData['last_error'] ?? 'Unknown error';
            }

            // Record event in unified system and get the record ID
            $metricsRecordId = $this->unifiedMetrics->recordEmailVerificationEvent($userId, $status, $context);

            Logger::email('info', 'EMAIL: Email metrics recorded', [
                'user_id' => $userId,
                'status' => $status,
                'job_id' => $context['job_id'],
                'email_type' => $context['email_type'],
                'metrics_record_id' => $metricsRecordId,
            ]);

            return $metricsRecordId;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to record email event metrics', [
                'status' => $status,
                'user_id' => $emailData['user_id'] ?? null,
                'job_id' => $emailData['job_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Call system metrics update directly (hourly/daily tables only)
     * Avoids duplicating email_verification_metrics records
     */
    private function callSystemMetricsUpdate(string $emailType, string $status, array $systemMetrics, array $context): void
    {
        if (!$this->unifiedMetrics) {
            return;
        }

        try {
            // Use reflection to call the private method updateSystemEmailMetricsAsync
            $reflection = new \ReflectionClass($this->unifiedMetrics);
            $method = $reflection->getMethod('updateSystemEmailMetricsAsync');
            $method->setAccessible(true);

            $method->invoke($this->unifiedMetrics, $emailType, $status, $systemMetrics, $context);

            // System metrics updated - removed verbose logging

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to update system metrics directly', [
                'email_type' => $emailType,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Map email type to metrics category for hourly/daily tables
     *
     * Ensures welcome and account_recovery emails are tracked under 'notification' category
     * as requested for GDPR compliance and admin dashboard analytics.
     *
     * MAPPING:
     * - welcome → notification (first login/registration email)
     * - account_recovery → notification (GDPR Article 17 account restoration)
     * - verification → verification (email confirmation)
     * - password_reset → password_reset (password recovery)
     * - notification → notification (generic notifications)
     *
     * @param string $emailType Original email type from queue
     * @return string Metrics category for email_metrics_hourly/daily tables
     */
    private function mapEmailTypeToMetricsCategory(string $emailType): string
    {
        return match($emailType) {
            'welcome' => 'notification',
            'account_recovery' => 'notification',
            'verification' => 'verification',
            'password_reset' => 'password_reset',
            'notification' => 'notification',
            default => $emailType, // Fallback to original type for extensibility
        };
    }

    /**
     * Calculate SMTP headers size for realistic email size calculation
     */
    private function calculateSmtpHeadersSize(string $email, string $subject): int
    {
        $headers = [
            'Date: ' . date('r'),
            'To: ' . $email,
            'From: need2talk <noreply@need2talk.app>',
            'Subject: ' . $subject,
            'Message-ID: <' . uniqid() . '@need2talk.app>',
            'X-Mailer: need2talk EmailService',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="boundary_' . uniqid() . '"',
        ];

        $totalSize = 0;

        foreach ($headers as $header) {
            $totalSize += strlen($header) + 2; // +2 for \r\n
        }

        return $totalSize;
    }

    /**
     * Extract error code from error message for metrics categorization
     */
    private function getErrorCode(string $errorMessage): string
    {
        $errorMessage = strtolower($errorMessage);

        // Common error patterns
        $errorPatterns = [
            'user_not_found' => 'USER_NOT_FOUND_ERROR',
            'user not found' => 'USER_NOT_FOUND_ERROR',
            'invalid verification email data' => 'INVALID_DATA_ERROR',
            'connection' => 'CONNECTION_ERROR',
            'timeout' => 'TIMEOUT_ERROR',
            'dns' => 'DNS_ERROR',
            'smtp' => 'SMTP_ERROR',
            'authentication' => 'AUTH_ERROR',
            'ssl' => 'SSL_ERROR',
            'certificate' => 'SSL_ERROR',
            'tls' => 'TLS_ERROR',
            'mailbox' => 'MAILBOX_ERROR',
            'quota' => 'QUOTA_ERROR',
            'blacklist' => 'BLACKLIST_ERROR',
            'spam' => 'SPAM_ERROR',
            'rate limit' => 'RATE_LIMIT_ERROR',
        ];

        foreach ($errorPatterns as $pattern => $code) {
            if (strpos($errorMessage, $pattern) !== false) {
                return $code;
            }
        }

        return 'UNKNOWN_ERROR';
    }

    /**
     * Send email directly without queuing (for retry purposes)
     */
    private function sendVerificationEmailDirect(array $emailData): bool
    {
        try {
            // Use existing sendVerificationEmail method
            $result = $this->sendVerificationEmail($emailData);

            return $result !== false;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to send email directly', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'email_type' => $emailData['type'] ?? 'unknown',
            ]);

            return false;
        }
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Queue account recovery notification email
     *
     * Sends notification when user account is recovered during 30-day GDPR grace period
     * - Recovery method tracking (Google OAuth, manual link, admin intervention)
     * - IP address audit trail for security
     * - GDPR Article 17 compliance messaging
     * - Email metrics integration (type: 'notification')
     *
     * @param int $userId User ID
     * @param string $email User email
     * @param string $nickname User nickname
     * @param string $recoveryMethod Recovery method ('google_oauth', 'manual', 'admin')
     * @param string|null $recoveryIp IP address used for recovery (for security audit)
     * @param string|null $deletionRequestedAt Original deletion request timestamp
     * @return bool True if queued successfully, false otherwise
     */
    public function queueAccountRecoveryEmail(
        int $userId,
        string $email,
        string $nickname,
        string $recoveryMethod = 'google_oauth',
        ?string $recoveryIp = null,
        ?string $deletionRequestedAt = null
    ): bool {
        // ENTERPRISE VALIDATION: Input validation before queuing
        if ($userId <= 0) {
            Logger::email('error', 'EMAIL: Invalid user_id for account recovery email', [
                'user_id' => $userId,
                'email' => $email,
                'error' => 'user_id must be positive integer',
                'validation_failed' => 'user_id',
            ]);

            return false;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Logger::email('error', 'EMAIL: Invalid email for account recovery notification', [
                'user_id' => $userId,
                'email' => $email,
                'error' => 'invalid email format',
                'validation_failed' => 'email_format',
            ]);

            return false;
        }

        // ENTERPRISE: Validate recovery method
        $validMethods = ['google_oauth', 'manual', 'admin'];
        if (!in_array($recoveryMethod, $validMethods)) {
            Logger::email('warning', 'EMAIL: Invalid recovery method, defaulting to google_oauth', [
                'user_id' => $userId,
                'email' => $email,
                'invalid_method' => $recoveryMethod,
                'defaulted_to' => 'google_oauth',
            ]);

            $recoveryMethod = 'google_oauth';
        }

        // ENTERPRISE: Build template data with security audit trail
        $templateData = [
            'user_data' => [
                'nickname' => $nickname,
                'email' => $email,
            ],
            'recovery_data' => [
                'method' => $recoveryMethod,
                'ip' => $recoveryIp ?? 'Non disponibile',
                'date' => date('d/m/Y H:i'),
                'deletion_requested_at' => $deletionRequestedAt ?? 'N/A',
            ],
        ];

        // ENTERPRISE GALAXY: Queue with notification priority
        $queued = $this->queueEmail([
            'type' => 'account_recovery',
            'user_id' => $userId,
            'email' => $email,
            'template_data' => $templateData,
            'priority' => self::PRIORITY_NORMAL, // Normal priority (informational)
            'max_attempts' => 3, // Standard retry count
            'metadata' => [
                'recovery_method' => $recoveryMethod,
                'recovery_ip' => $recoveryIp,
                'email_category' => 'notification', // For metrics tracking
            ],
        ]);

        if ($queued) {
            Logger::email('info', 'EMAIL: Account recovery notification queued', [
                'user_id' => $userId,
                'email' => $email,
                'nickname' => $nickname,
                'recovery_method' => $recoveryMethod,
                'recovery_ip' => $recoveryIp,
            ]);
        }

        return $queued;
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Build Account Suspension Email Body (3rd deletion)
     *
     * Renders account-suspension-3rd-deletion.php template with suspension information
     *
     * @param array $templateData Template variables
     * @return string HTML email body
     */
    private function buildAccountSuspensionEmailBody(array $templateData): string
    {
        try {
            $nickname = $templateData['nickname'] ?? 'User';
            $email = $templateData['email'] ?? '';
            $hard_delete_deadline = $templateData['hard_delete_deadline'] ?? date('Y-m-d H:i:s');
            $supportEmail = $templateData['support_email'] ?? 'support@need2talk.it';
            $recentDeletions = $templateData['recent_deletions'] ?? 3;
            $reason = $templateData['reason'] ?? null;

            $templatePath = dirname(dirname(__DIR__)) . '/app/Views/emails/account-suspension-3rd-deletion.php';

            if (!file_exists($templatePath)) {
                Logger::email('error', 'EMAIL: Account suspension template not found', [
                    'template_path' => $templatePath,
                    'fallback' => 'using_simple_text',
                ]);

                // Fallback: simple text email
                $deadlineDate = date('d/m/Y', strtotime($hard_delete_deadline));
                return "<h1>Account Sospeso</h1><p>Il tuo account è stato sospeso. Contatta {$supportEmail} entro il {$deadlineDate} per riattivazione.</p>";
            }

            // ENTERPRISE: Render template with output buffering
            ob_start();
            if (!defined('APP_ROOT')) {
                define('APP_ROOT', dirname(dirname(__DIR__)));
            }
            include $templatePath;
            $body = ob_get_clean();

            return $body;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Account suspension template rendering failed', [
                'error' => $e->getMessage(),
                'fallback' => 'using_simple_text',
            ]);

            // Fallback: simple text email
            $deadlineDate = date('d/m/Y', strtotime($templateData['hard_delete_deadline'] ?? date('Y-m-d H:i:s')));
            $supportEmail = $templateData['support_email'] ?? 'support@need2talk.it';
            return "<h1>Account Sospeso</h1><p>Il tuo account è stato sospeso. Contatta {$supportEmail} entro il {$deadlineDate} per riattivazione.</p>";
        }
    }
}
