<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;

/**
 * ENTERPRISE GALAXY: Admin Email Queue System
 *
 * Separate queue system for admin-initiated emails (bulk operations, newsletters, etc.)
 * Uses Redis DB 4 to avoid conflicts with AsyncEmailQueue (DB 2)
 *
 * Features:
 * - Priority-based queuing (urgent, high, normal, low)
 * - Scheduled email support
 * - Retry mechanism with exponential backoff
 * - Idempotency key support to prevent duplicates
 * - Complete audit trail integration
 * - Rate limiting protection
 *
 * @package Need2Talk\Services
 * @version 2.0.0
 */
class AdminEmailQueue
{
    private const REDIS_DB = 4; // Dedicated database for admin emails
    private const QUEUE_PREFIX = 'admin_email_queue:';
    private const PROCESSING_PREFIX = 'admin_email_processing:';
    private const FAILED_PREFIX = 'admin_email_failed:';
    private const IDEMPOTENCY_PREFIX = 'admin_email_idempotency:';

    // Queue names by priority
    private const QUEUE_URGENT = 'urgent';
    private const QUEUE_HIGH = 'high';
    private const QUEUE_NORMAL = 'normal';
    private const QUEUE_LOW = 'low';

    // TTLs
    private const IDEMPOTENCY_TTL = 86400; // 24 hours
    private const PROCESSING_TTL = 3600; // 1 hour
    private const FAILED_TTL = 604800; // 7 days

    private ?\Redis $redis = null;

    /**
     * Initialize Redis connection to DB 4
     */
    public function __construct()
    {
        try {
            $this->redis = EnterpriseRedisManager::getInstance()->getConnection();
            $this->redis->select(self::REDIS_DB);
        } catch (\Exception $e) {
            Logger::email('error', 'AdminEmailQueue: Redis connection failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Enqueue an admin email job
     *
     * @param array $emailData Email configuration
     * @return string|false Job ID or false on failure
     */
    public function enqueue(array $emailData): string|false
    {
        try {
            // Validate required fields
            $required = ['email_type', 'admin_id', 'admin_email', 'recipient_email', 'subject', 'template'];
            foreach ($required as $field) {
                if (empty($emailData[$field])) {
                    Logger::email('error', 'AdminEmailQueue: Missing required field', ['field' => $field]);

                    return false;
                }
            }

            // Generate job ID and idempotency key
            $jobId = $this->generateJobId();
            $idempotencyKey = $emailData['idempotency_key'] ?? null;

            // Check idempotency (prevent duplicates)
            if ($idempotencyKey && $this->isDuplicate($idempotencyKey)) {
                Logger::email('info', 'AdminEmailQueue: Duplicate email prevented', [
                    'idempotency_key' => $idempotencyKey,
                ]);

                return false;
            }

            // Prepare job data
            $job = [
                'job_id' => $jobId,
                'email_type' => $emailData['email_type'],
                'newsletter_id' => $emailData['newsletter_id'] ?? null, // ENTERPRISE GALAXY: Newsletter tracking
                'admin_id' => $emailData['admin_id'],
                'admin_email' => $emailData['admin_email'],
                'recipient_user_id' => $emailData['recipient_user_id'] ?? null,
                'recipient_email' => $emailData['recipient_email'],
                'recipient_count' => $emailData['recipient_count'] ?? 1,
                'subject' => $emailData['subject'],
                'template' => $emailData['template'],
                'template_data' => $emailData['template_data'] ?? [],
                'priority' => $emailData['priority'] ?? 'normal',
                'scheduled_for' => $emailData['scheduled_for'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'ip_address' => $emailData['ip_address'] ?? null,
                'user_agent' => $emailData['user_agent'] ?? null,
                'additional_data' => $emailData['additional_data'] ?? null,
                'retry_count' => 0,
                'max_retries' => $emailData['max_retries'] ?? 3,
                'queued_at' => time(),
            ];

            // Determine queue based on priority
            $queueName = $this->getQueueByPriority($job['priority']);
            $queueKey = self::QUEUE_PREFIX . $queueName;

            // Calculate score (timestamp for FIFO, earlier for scheduled)
            // ENTERPRISE V12.3: Ensure score is always numeric (strtotime can return false)
            $score = time();
            if (!empty($job['scheduled_for'])) {
                $parsedTime = strtotime($job['scheduled_for']);
                if ($parsedTime !== false && $parsedTime > 0) {
                    $score = $parsedTime;
                }
            }

            // Add to Redis sorted set (ZADD for priority queue)
            $result = $this->redis->zAdd($queueKey, $score, json_encode($job));

            if ($result === false) {
                Logger::email('error', 'AdminEmailQueue: Failed to enqueue', ['job_id' => $jobId]);

                return false;
            }

            // Set idempotency key if provided
            if ($idempotencyKey) {
                $this->redis->setex(
                    self::IDEMPOTENCY_PREFIX . $idempotencyKey,
                    self::IDEMPOTENCY_TTL,
                    $jobId
                );
            }

            // Create audit log entry
            $this->createAuditEntry($job, 'queued');

            return $jobId;

        } catch (\Exception $e) {
            Logger::email('error', 'AdminEmailQueue: Enqueue failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Dequeue next job from highest priority queue
     *
     * @return array|null Job data or null if queue empty
     */
    public function dequeue(): ?array
    {
        try {
            $priorities = [self::QUEUE_URGENT, self::QUEUE_HIGH, self::QUEUE_NORMAL, self::QUEUE_LOW];

            foreach ($priorities as $priority) {
                $queueKey = self::QUEUE_PREFIX . $priority;
                $now = time();

                // Get jobs that are ready (score <= current time)
                $jobs = $this->redis->zRangeByScore($queueKey, 0, $now, ['limit' => [0, 1]]);

                if (!empty($jobs)) {
                    $jobData = json_decode($jobs[0], true);

                    // Remove from queue
                    $this->redis->zRem($queueKey, $jobs[0]);

                    // Add to processing set
                    $processingKey = self::PROCESSING_PREFIX . $jobData['job_id'];
                    $this->redis->setex($processingKey, self::PROCESSING_TTL, json_encode($jobData));

                    // Update audit entry
                    $this->updateAuditStatus($jobData['job_id'], 'processing');

                    return $jobData;
                }
            }

            return null; // All queues empty

        } catch (\Exception $e) {
            Logger::email('error', 'AdminEmailQueue: Dequeue failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Mark job as completed successfully
     *
     * @param string $jobId Job identifier
     * @param int $processingTimeMs Processing time in milliseconds
     * @return bool Success
     */
    public function markCompleted(string $jobId, int $processingTimeMs = 0): bool
    {
        try {
            // Remove from processing
            $processingKey = self::PROCESSING_PREFIX . $jobId;
            $this->redis->del($processingKey);

            // Update audit entry
            $this->updateAuditStatus($jobId, 'sent', $processingTimeMs);

            return true;

        } catch (\Exception $e) {
            Logger::email('error', 'AdminEmailQueue: Mark completed failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark job as failed and optionally retry
     *
     * @param string $jobId Job identifier
     * @param string $errorMessage Error description
     * @param string|null $errorCode Error code
     * @return bool Success
     */
    public function markFailed(string $jobId, string $errorMessage, ?string $errorCode = null): bool
    {
        try {
            $processingKey = self::PROCESSING_PREFIX . $jobId;
            $jobDataJson = $this->redis->get($processingKey);

            if (!$jobDataJson) {
                Logger::email('warning', 'AdminEmailQueue: Job not found in processing', ['job_id' => $jobId]);

                return false;
            }

            $jobData = json_decode($jobDataJson, true);
            $jobData['retry_count']++;

            // Check if should retry
            if ($jobData['retry_count'] < $jobData['max_retries']) {
                // Re-enqueue with exponential backoff
                $backoffSeconds = pow(2, $jobData['retry_count']) * 60; // 2min, 4min, 8min...
                $queueName = $this->getQueueByPriority($jobData['priority']);
                $queueKey = self::QUEUE_PREFIX . $queueName;
                $score = time() + $backoffSeconds;

                $this->redis->zAdd($queueKey, $score, json_encode($jobData));
                $this->redis->del($processingKey);

                Logger::email('warning', 'AdminEmailQueue: Job failed, retrying', [
                    'job_id' => $jobId,
                    'retry_count' => $jobData['retry_count'],
                    'backoff_seconds' => $backoffSeconds,
                    'error' => $errorMessage,
                ]);

                // Update audit with error but keep status as queued
                $this->updateAuditError($jobId, $errorMessage, $errorCode, $jobData['retry_count']);

                return true;
            } else {
                // Max retries exceeded, move to failed
                $failedKey = self::FAILED_PREFIX . $jobId;
                $jobData['error_message'] = $errorMessage;
                $jobData['error_code'] = $errorCode;
                $jobData['failed_at'] = time();

                $this->redis->setex($failedKey, self::FAILED_TTL, json_encode($jobData));
                $this->redis->del($processingKey);

                // Update audit entry as failed
                $this->updateAuditStatus($jobId, 'failed', 0, $errorMessage, $errorCode);

                Logger::email('error', 'AdminEmailQueue: Job permanently failed', [
                    'job_id' => $jobId,
                    'retry_count' => $jobData['retry_count'],
                    'error' => $errorMessage,
                ]);

                return false;
            }

        } catch (\Exception $e) {
            Logger::email('error', 'AdminEmailQueue: Mark failed error', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get queue statistics
     *
     * @return array Queue stats
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'urgent' => $this->redis->zCard(self::QUEUE_PREFIX . self::QUEUE_URGENT),
                'high' => $this->redis->zCard(self::QUEUE_PREFIX . self::QUEUE_HIGH),
                'normal' => $this->redis->zCard(self::QUEUE_PREFIX . self::QUEUE_NORMAL),
                'low' => $this->redis->zCard(self::QUEUE_PREFIX . self::QUEUE_LOW),
                'processing' => count($this->redis->keys(self::PROCESSING_PREFIX . '*')),
                'failed' => count($this->redis->keys(self::FAILED_PREFIX . '*')),
                'total_queued' => 0,
            ];

            $stats['total_queued'] = $stats['urgent'] + $stats['high'] + $stats['normal'] + $stats['low'];

            return $stats;

        } catch (\Exception $e) {
            Logger::email('error', 'AdminEmailQueue: Get stats failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Clean up old failed jobs
     *
     * @param int $olderThanDays Remove jobs older than N days
     * @return int Number of jobs cleaned
     */
    public function cleanFailedJobs(int $olderThanDays = 7): int
    {
        try {
            $keys = $this->redis->keys(self::FAILED_PREFIX . '*');
            $cleaned = 0;
            $cutoffTime = time() - ($olderThanDays * 86400);

            foreach ($keys as $key) {
                $jobDataJson = $this->redis->get($key);
                if ($jobDataJson) {
                    $jobData = json_decode($jobDataJson, true);
                    if (isset($jobData['failed_at']) && $jobData['failed_at'] < $cutoffTime) {
                        $this->redis->del($key);
                        $cleaned++;
                    }
                }
            }

            return $cleaned;

        } catch (\Exception $e) {
            Logger::email('error', 'AdminEmailQueue: Clean failed jobs error', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Check if email is duplicate using idempotency key
     */
    private function isDuplicate(string $idempotencyKey): bool
    {
        return (bool) $this->redis->exists(self::IDEMPOTENCY_PREFIX . $idempotencyKey);
    }

    /**
     * Get queue name by priority level
     */
    private function getQueueByPriority(string $priority): string
    {
        return match ($priority) {
            'urgent' => self::QUEUE_URGENT,
            'high' => self::QUEUE_HIGH,
            'low' => self::QUEUE_LOW,
            default => self::QUEUE_NORMAL,
        };
    }

    /**
     * Generate unique job ID
     */
    private function generateJobId(): string
    {
        return 'admin_email_' . uniqid() . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Create audit entry in database
     */
    private function createAuditEntry(array $job, string $status): void
    {
        try {
            $pdo = db_pdo();

            $stmt = $pdo->prepare("
                INSERT INTO admin_email_audit (
                    email_type, queue_id, idempotency_key, admin_id, admin_email,
                    recipient_user_id, recipient_email, recipient_count,
                    subject, template_used, status, priority, scheduled_for,
                    ip_address, user_agent, additional_data, queued_at
                ) VALUES (
                    :email_type, :queue_id, :idempotency_key, :admin_id, :admin_email,
                    :recipient_user_id, :recipient_email, :recipient_count,
                    :subject, :template_used, :status, :priority, :scheduled_for,
                    :ip_address, :user_agent, :additional_data, TO_TIMESTAMP(:queued_at)
                )
            ");

            $stmt->execute([
                'email_type' => $job['email_type'],
                'queue_id' => $job['job_id'],
                'idempotency_key' => $job['idempotency_key'],
                'admin_id' => $job['admin_id'],
                'admin_email' => $job['admin_email'],
                'recipient_user_id' => $job['recipient_user_id'],
                'recipient_email' => $job['recipient_email'],
                'recipient_count' => $job['recipient_count'],
                'subject' => $job['subject'],
                'template_used' => $job['template'],
                'status' => $status,
                'priority' => $job['priority'],
                'scheduled_for' => $job['scheduled_for'],
                'ip_address' => $job['ip_address'],
                'user_agent' => $job['user_agent'],
                'additional_data' => json_encode($job['additional_data']),
                'queued_at' => $job['queued_at'],
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'AdminEmailQueue: Create audit entry failed', [
                'job_id' => $job['job_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update audit entry status
     */
    private function updateAuditStatus(
        string $jobId,
        string $status,
        int $processingTimeMs = 0,
        ?string $errorMessage = null,
        ?string $errorCode = null
    ): void {
        try {
            $pdo = db_pdo();

            $updates = ['status = :status'];
            $params = ['status' => $status, 'queue_id' => $jobId];

            if ($status === 'processing') {
                $updates[] = 'processing_started_at = NOW()';
            } elseif ($status === 'sent') {
                $updates[] = 'sent_at = NOW()';
                $updates[] = 'processing_time_ms = :processing_time_ms';
                $params['processing_time_ms'] = $processingTimeMs;
            } elseif ($status === 'failed') {
                $updates[] = 'error_message = :error_message';
                $updates[] = 'error_code = :error_code';
                $params['error_message'] = $errorMessage;
                $params['error_code'] = $errorCode;
            }

            $sql = "UPDATE admin_email_audit SET " . implode(', ', $updates) . " WHERE queue_id = :queue_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

        } catch (\Exception $e) {
            Logger::database('error', 'AdminEmailQueue: Update audit status failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update audit entry with error (for retries)
     */
    private function updateAuditError(string $jobId, string $errorMessage, ?string $errorCode, int $retryCount): void
    {
        try {
            $pdo = db_pdo();

            $stmt = $pdo->prepare("
                UPDATE admin_email_audit
                SET error_message = :error_message,
                    error_code = :error_code,
                    retry_count = :retry_count
                WHERE queue_id = :queue_id
            ");

            $stmt->execute([
                'error_message' => $errorMessage,
                'error_code' => $errorCode,
                'retry_count' => $retryCount,
                'queue_id' => $jobId,
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'AdminEmailQueue: Update audit error failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
