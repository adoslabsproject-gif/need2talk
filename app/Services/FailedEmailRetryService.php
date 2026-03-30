<?php

namespace Need2Talk\Services;

use Need2Talk\Core\Database;

/**
 * Failed Email Retry Service - Dual-Layer Retry System
 *
 * LAYER 1: Redis FAILED_KEY (immediate retry, 3 attempts in ~7 minutes)
 * LAYER 2: Database failed_emails (persistent retry, 5 attempts in 24-48 hours)
 *
 * Total: 8 attempts before permanent_failure
 *
 * Architecture:
 * - Redis handles fast retries (60, 120, 240 seconds)
 * - Database handles long-term retries (1h, 3h, 6h, 12h, 24h)
 * - Supports ALL email types (verification, password_reset, notification)
 */
class FailedEmailRetryService
{
    // Retry configuration
    private const MAX_DB_ATTEMPTS = 5;  // Additional attempts beyond Redis
    private const RETRY_DELAYS = [
        1 => 3600,    // 1 hour
        2 => 10800,   // 3 hours
        3 => 21600,   // 6 hours
        4 => 43200,   // 12 hours
        5 => 86400,   // 24 hours
    ];

    private $pdo;

    public function __construct()
    {
        // Use EnterpriseSecureDatabasePool for connection
        $pool = EnterpriseSecureDatabasePool::getInstance();
        $this->pdo = $pool->getConnection();
    }

    /**
     * Save failed email from Redis dead letter to database for long-term retry
     * Called when Redis gives up after 3 attempts
     */
    public function saveFromDeadLetter(array $emailData, string $finalError): ?int
    {
        try {
            // Validate required fields
            if (empty($emailData['job_id']) || empty($emailData['email'])) {
                Logger::email('error', 'EMAIL RETRY: Missing required fields', [
                    'email_data' => $emailData,
                ]);

                return null;
            }

            // Check if already exists (prevent duplicates)
            $stmt = $this->pdo->prepare('
                SELECT id FROM failed_emails WHERE job_id = ?
            ');
            $stmt->execute([$emailData['job_id']]);

            if ($stmt->fetch()) {
                Logger::email('warning', 'EMAIL RETRY: Email already in database', [
                    'job_id' => $emailData['job_id'],
                ]);

                return null;
            }

            // Extract data with defaults
            $userId = $emailData['user_id'] ?? null;
            $userUuid = $emailData['user_uuid'] ?? null;

            // If user_uuid is missing but user_id exists, fetch it from database
            if ($userId && !$userUuid) {
                try {
                    $stmt = $this->pdo->prepare('SELECT uuid FROM users WHERE id = ?');
                    $stmt->execute([$userId]);
                    $userUuid = $stmt->fetchColumn();
                } catch (\Exception $e) {
                    Logger::email('warning', 'EMAIL RETRY: Could not fetch user UUID', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $emailType = $emailData['type'] ?? 'notification';
            $templateData = json_encode($emailData['template_data'] ?? []);
            $context = $emailData['context'] ?? 'production';
            $redisAttempts = $emailData['attempts'] ?? 3;

            // Calculate next retry time (1 hour from now)
            $nextRetry = date('Y-m-d H:i:s', time() + self::RETRY_DELAYS[1]);

            // Get system metrics
            $systemLoad = sys_getloadavg()[0] ?? null;
            $circuitBreakerOpen = $this->isCircuitBreakerOpen();
            $redisAvailable = $this->isRedisAvailable();

            // Insert into database
            $stmt = $this->pdo->prepare("
                INSERT INTO failed_emails (
                    job_id, user_id, user_uuid, email, email_type,
                    subject, body_html, template_data,
                    attempts, max_attempts, priority,
                    first_error, last_error, error_type,
                    processing_status, next_retry_at, retry_delay_seconds,
                    context, circuit_breaker_open, redis_available, system_load,
                    first_failed_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    'pending', ?, ?,
                    ?, ?, ?, ?,
                    NOW()
                )
            ");

            $stmt->execute([
                $emailData['job_id'],
                $userId,
                $userUuid,
                $emailData['email'],
                $emailType,
                $this->buildSubject($emailType, $emailData),
                $this->buildBody($emailType, $emailData),
                $templateData,
                0,  // DB attempts start at 0
                self::MAX_DB_ATTEMPTS,
                $emailData['priority'] ?? 5,
                "Redis failed after {$redisAttempts} attempts",
                $finalError,
                $this->categorizeError($finalError),
                $nextRetry,
                self::RETRY_DELAYS[1],
                $context,
                $circuitBreakerOpen ? 1 : 0,
                $redisAvailable ? 1 : 0,
                $systemLoad,
            ]);

            $failedEmailId = $this->pdo->lastInsertId();

            return (int) $failedEmailId;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL RETRY: Failed to save to database', [
                'job_id' => $emailData['job_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get emails ready for retry (next_retry_at <= NOW and status = pending)
     * Used by worker to fetch batch of emails to retry
     */
    public function getEmailsForRetry(int $limit = 100): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM failed_emails
                WHERE processing_status = 'pending'
                AND next_retry_at <= NOW()
                AND attempts < max_attempts
                ORDER BY priority ASC, next_retry_at ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL RETRY: Failed to fetch emails for retry', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Mark email as processing (prevent duplicate processing by multiple workers)
     */
    public function markAsProcessing(int $id, string $workerId): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE failed_emails
                SET processing_status = 'processing',
                    worker_id = ?,
                    updated_at = NOW()
                WHERE id = ?
                AND processing_status = 'pending'
            ");
            $stmt->execute([$workerId, $id]);

            return $stmt->rowCount() > 0;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL RETRY: Failed to mark as processing', [
                'id' => $id,
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark retry attempt as failed, schedule next retry
     */
    public function markRetryFailed(int $id, string $error): bool
    {
        try {
            $stmt = $this->pdo->prepare('
                SELECT attempts, max_attempts FROM failed_emails WHERE id = ?
            ');
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return false;
            }

            $newAttempts = $row['attempts'] + 1;
            $maxAttempts = $row['max_attempts'];

            if ($newAttempts >= $maxAttempts) {
                // Permanent failure
                $stmt = $this->pdo->prepare("
                    UPDATE failed_emails
                    SET processing_status = 'permanent_failure',
                        attempts = ?,
                        last_error = ?,
                        last_attempt_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newAttempts, $error, $id]);

                Logger::email('error', 'EMAIL RETRY: Email marked as permanent failure', [
                    'id' => $id,
                    'total_attempts' => $newAttempts,
                    'error' => $error,
                ]);

            } else {
                // Schedule next retry with exponential backoff
                $retryDelay = self::RETRY_DELAYS[$newAttempts + 1] ?? 86400;  // Default 24h
                $nextRetry = date('Y-m-d H:i:s', time() + $retryDelay);

                $stmt = $this->pdo->prepare("
                    UPDATE failed_emails
                    SET processing_status = 'pending',
                        attempts = ?,
                        last_error = ?,
                        error_type = ?,
                        next_retry_at = ?,
                        retry_delay_seconds = ?,
                        last_attempt_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $newAttempts,
                    $error,
                    $this->categorizeError($error),
                    $nextRetry,
                    $retryDelay,
                    $id,
                ]);

                Logger::email('warning', 'EMAIL RETRY: Retry scheduled', [
                    'id' => $id,
                    'attempt' => $newAttempts,
                    'max_attempts' => $maxAttempts,
                    'next_retry' => $nextRetry,
                    'delay_seconds' => $retryDelay,
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL RETRY: Failed to mark retry as failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark retry as successful, remove from table
     */
    public function markRetrySuccess(int $id): bool
    {
        try {
            $stmt = $this->pdo->prepare('
                DELETE FROM failed_emails WHERE id = ?
            ');
            $stmt->execute([$id]);

            return true;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL RETRY: Failed to mark success', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get statistics for monitoring
     */
    public function getStats(): array
    {
        try {
            $stats = [];

            // Count by status
            $stmt = $this->pdo->query('
                SELECT processing_status, COUNT(*) as count
                FROM failed_emails
                GROUP BY processing_status
            ');

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $stats[$row['processing_status']] = (int) $row['count'];
            }

            // Count by error type
            $stmt = $this->pdo->query('
                SELECT error_type, COUNT(*) as count
                FROM failed_emails
                WHERE error_type IS NOT NULL
                GROUP BY error_type
                ORDER BY count DESC
                LIMIT 10
            ');
            $stats['error_types'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Emails ready for retry
            $stmt = $this->pdo->query("
                SELECT COUNT(*) FROM failed_emails
                WHERE processing_status = 'pending'
                AND next_retry_at <= NOW()
            ");
            $stats['ready_for_retry'] = (int) $stmt->fetchColumn();

            return $stats;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL RETRY: Failed to get stats', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Cleanup old permanent failures (optional - run via cron)
     */
    public function cleanupOldFailures(int $daysOld = 30): int
    {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM failed_emails
                WHERE processing_status = 'permanent_failure'
                AND created_at < NOW() - INTERVAL '1 day' * ?
            ");
            $stmt->execute([$daysOld]);

            $deleted = $stmt->rowCount();

            return $deleted;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL RETRY: Failed to cleanup old failures', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    // ========== PRIVATE HELPERS ==========

    private function buildSubject(string $emailType, array $emailData): string
    {
        $templateData = $emailData['template_data'] ?? [];

        return match($emailType) {
            'verification' => 'Verifica il tuo account Need2Talk',
            'password_reset' => 'Reset della password Need2Talk',
            'notification' => $templateData['subject'] ?? 'Notifica Need2Talk',
            default => $templateData['subject'] ?? 'Need2Talk'
        };
    }

    private function buildBody(string $emailType, array $emailData): ?string
    {
        // Body HTML può essere ricostruito dal template_data quando necessario
        return null;  // Will be rebuilt from template_data during retry
    }

    private function categorizeError(string $error): string
    {
        if (stripos($error, 'user not found') !== false) {
            return 'user_not_found';
        }

        if (stripos($error, 'smtp') !== false || stripos($error, 'connection') !== false) {
            return 'smtp_failed';
        }

        if (stripos($error, 'circuit breaker') !== false) {
            return 'circuit_breaker';
        }

        if (stripos($error, 'validation') !== false || stripos($error, 'invalid') !== false) {
            return 'validation_error';
        }

        if (stripos($error, 'batch processing') !== false) {
            return 'batch_failed';
        }

        if (stripos($error, 'timeout') !== false) {
            return 'timeout';
        }

        return 'unknown';
    }

    private function isCircuitBreakerOpen(): bool
    {
        try {
            // Check if database circuit breaker is open
            $poolStats = EnterpriseSecureDatabasePool::getInstance()->getPoolStats();

            return ($poolStats['circuit_breaker_state'] ?? 'CLOSED') === 'OPEN';
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isRedisAvailable(): bool
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection();

            return $redis && $redis->ping();
        } catch (\Exception $e) {
            return false;
        }
    }
}
