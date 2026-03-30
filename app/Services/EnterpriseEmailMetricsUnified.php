<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Traits\EnterpriseRedisSafety;

/**
 * Enterprise Email Metrics Unified Service
 *
 * Sistema unificato che popola automaticamente TUTTE le 3 tabelle metrics:
 * 1. email_verification_metrics (record dettagliati per utente)
 * 2. email_verification_analytics (aggregazioni giornaliere)
 * 3. email_metrics_hourly/daily (metriche sistema generale)
 *
 * Progettato per:
 * - Centinaia di migliaia di utenti simultanei
 * - Redis L1/L3 per performance enterprise
 * - Anti-malicious security avanzata
 * - Database pool connections
 * - CSRF integration
 * - Sistema logging generale
 * - Monitor sistema integrato
 * - Diagnostics incluso
 */
class EnterpriseEmailMetricsUnified
{
    use EnterpriseRedisSafety;

    // Redis keys for enterprise performance
    private const METRICS_L1_PREFIX = 'enterprise_email_metrics:';
    private const ANALYTICS_L3_PREFIX = 'enterprise_analytics:';
    private const HOURLY_COUNTER_PREFIX = 'hourly_metrics:';
    private const DAILY_COUNTER_PREFIX = 'daily_metrics:';

    // Enterprise batch processing
    private const BATCH_INSERT_SIZE = 1000;
    private const CACHE_TTL_SHORT = 300; // 5 minutes
    private const CACHE_TTL_MEDIUM = 1800; // 30 minutes
    private const CACHE_TTL_LONG = 7200; // 2 hours

    private $redisManager;

    private $databasePool;

    private $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);

        // ENTERPRISE INTEGRATION: Use existing infrastructure
        $this->redisManager = EnterpriseRedisManager::getInstance();
        $this->databasePool = EnterpriseSecureDatabasePool::getInstance();
    }

    /**
     * ENTERPRISE MASTER METHOD: Record complete email verification event
     * Popola automaticamente TUTTE le 3 tabelle metrics con una sola chiamata
     */
    public function recordEmailVerificationEvent(
        int $userId,
        string $status,
        array $context = []
    ): ?int {
        $eventStartTime = microtime(true);

        try {
            // ENTERPRISE SECURITY: Validate input per anti-malicious
            if (!$this->validateEmailMetricsInput($userId, $status, $context)) {
                return null;
            }

            // Generate comprehensive system metrics
            // ENTERPRISE TIPS: Pass status in context for queue_time_ms calculation
            $context['status'] = $status;
            $systemMetrics = $this->generateEnterpriseSystemMetrics($eventStartTime, $context);
            $emailType = $context['email_type'] ?? 'verification';

            // ENTERPRISE PARALLEL PROCESSING: Execute all 3 inserts simultaneously
            $promises = [];
            $recordId = null;

            // 1. Insert into appropriate metrics table based on email_type
            if ($emailType === 'password_reset') {
                $recordId = $this->insertPasswordResetMetricsAsync(
                    $userId,
                    $status,
                    $systemMetrics,
                    $context
                );
                $promises['password_reset_metrics'] = $recordId !== null;
            } else {
                // Default: email_verification_metrics
                $recordId = $this->insertEmailVerificationMetricsAsync(
                    $userId,
                    $status,
                    $systemMetrics,
                    $context
                );
                $promises['verification_metrics'] = $recordId !== null;
            }

            // 2. EMAIL_VERIFICATION_ANALYTICS IS A VIEW - auto-updated from email_verification_metrics
            // No direct insert needed - the view automatically aggregates data

            // 3. Update email_metrics_hourly/daily (general system metrics)
            // ENTERPRISE TIPS: Skip hourly/daily updates for resend emails to prevent double counting
            // (EmailVerificationService already records them via EmailMetricsService)
            if (($context['context'] ?? 'initial') !== 'resend') {
                $promises['system_metrics'] = $this->updateSystemEmailMetricsAsync(
                    $emailType,
                    $status,
                    $systemMetrics,
                    $context
                );
            }

            // ENTERPRISE: Wait for operations to complete (reduced since analytics is auto-updated)
            $this->waitForAsyncOperations($promises, 3000); // 3 second timeout

            // ENTERPRISE REDIS L1: Cache aggregated metrics for high-speed access
            $this->cacheAggregatedMetrics($emailType, $status, $systemMetrics);

            return $recordId;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL METRICS: Failed to record unified email metrics', [
                'user_id' => $userId,
                'status' => $status,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'processing_time_ms' => round((microtime(true) - $eventStartTime) * 1000, 2),
                'critical_failure' => true,
                'requires_investigation' => true,
            ]);

            return null;
        }
    }

    /**
     * ENTERPRISE DIAGNOSTICS: Get comprehensive metrics health status
     */
    public function getMetricsHealthStatus(): array
    {
        $diagnostics = [];

        try {
            // Check Redis connectivity
            $diagnostics['redis'] = $this->getRedisHealthStatus();

            // Check database connectivity
            $diagnostics['database'] = [
                'pool_available' => $this->databasePool !== null,
                'connection_active' => false,
            ];

            if ($this->databasePool) {
                try {
                    $pdo = $this->databasePool->getConnection();
                    $stmt = $pdo->query('SELECT 1');
                    $diagnostics['database']['connection_active'] = $stmt !== false;
                } catch (\Exception $e) {
                    $diagnostics['database']['error'] = $e->getMessage();
                }
            }

            // Check table existence
            $diagnostics['tables'] = $this->checkMetricsTablesExistence();

            // System metrics
            $diagnostics['system'] = [
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024),
                'uptime_seconds' => round(microtime(true) - $this->startTime, 2),
            ];

            $diagnostics['status'] = 'healthy';

        } catch (\Exception $e) {
            $diagnostics['status'] = 'error';
            $diagnostics['error'] = $e->getMessage();
        }

        return $diagnostics;
    }

    /**
     * ENTERPRISE ASYNC: Insert/Update email_verification_metrics with full system data
     * INSERT new record if status=queued_successfully AND (no record today OR last record is sent_successfully)
     * UPDATE if record exists in progress state (processed_by_worker, etc)
     */
    private function insertEmailVerificationMetricsAsync(
        int $userId,
        string $status,
        array $systemMetrics,
        array $context
    ): ?int {
        try {
            $pdo = $this->databasePool->getConnection();

            // ENTERPRISE LOGIC: Always INSERT for queued_successfully (new email entering queue)
            // Only UPDATE for status progression (processed_by_worker -> sent_successfully)
            if ($status === 'queued_successfully') {
                // ENTERPRISE: INSERT new record (first email of day OR resend after completion)
                // CRITICAL: Do NOT specify created_at - let PostgreSQL default (CURRENT_TIMESTAMP) work
                // The BEFORE INSERT trigger reads created_at to populate created_date (partition key)
                // ENTERPRISE FIX: MUST specify created_at explicitly!
                // PostgreSQL DEFAULT is applied AFTER BEFORE INSERT trigger
                // Trigger needs created_at to populate created_date (partition key)
                // ENTERPRISE GALAXY: Table is now de-partitioned (was causing INSERT failures)
                // BEFORE INSERT trigger automatically sets created_date from created_at
                $stmt = $pdo->prepare('
                    INSERT INTO email_verification_metrics (
                        user_id, status, queue_time_ms, processing_time_ms, worker_id,
                        redis_l1_status, database_pool_id, server_load_avg, memory_usage_mb,
                        retry_count, error_code, error_message
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                $queueTimeValue = $systemMetrics['queue_time_ms'] ?? 0;

                // ENTERPRISE FIX: redis_l1_status ENUM only accepts: 'active', 'inactive', 'error'
                // Mapping: 'hit'/'miss' → 'active'/'inactive', any error → 'error'
                $redisL1Status = $systemMetrics['redis_l1_status'] ?? 'inactive';
                if (!in_array($redisL1Status, ['active', 'inactive', 'error'], true)) {
                    $redisL1Status = ($redisL1Status === 'hit') ? 'active' : 'inactive';
                }

                $params = [
                    $userId,
                    $status,
                    $queueTimeValue,
                    $systemMetrics['processing_time_ms'] ?? null,
                    $systemMetrics['worker_id'] ?? null,
                    $redisL1Status,  // ENTERPRISE: Validated enum value
                    $systemMetrics['database_pool_id'] ?? 'unknown',
                    $systemMetrics['server_load_avg'] ?? 0.0,
                    $systemMetrics['memory_usage_mb'] ?? 0,
                    $context['retry_count'] ?? 0,
                    $context['error_code'] ?? null,
                    $context['error_message'] ?? null,
                ];

                $result = $stmt->execute($params);

                if (!$result) {
                    $errorInfo = $stmt->errorInfo();
                    Logger::database('error', 'EMAIL METRICS: PDO INSERT FAILED', [
                        'error_code' => $errorInfo[0],
                        'driver_error_code' => $errorInfo[1],
                        'error_message' => $errorInfo[2],
                        'params' => $params,
                    ]);
                }

                $recordId = $result ? (int) $pdo->lastInsertId() : null;

                // Verify what was actually saved in database (silent verification for performance)
                if ($recordId) {
                    $verifyStmt = $pdo->prepare('SELECT id, user_id, queue_time_ms, status FROM email_verification_metrics WHERE id = ?');
                    $verifyStmt->execute([$recordId]);
                    $row = $verifyStmt->fetch(\PDO::FETCH_ASSOC);
                }

                return $recordId;

            } else {
                // ENTERPRISE: For other statuses (processed_by_worker, sent_successfully, etc.)
                // we don't create new records here - they should be updates via worker
                Logger::email('warning', 'EMAIL METRICS: Attempted to create non-queued status via insertEmailVerificationMetricsAsync', [
                    'user_id' => $userId,
                    'status' => $status,
                    'note' => 'This method should only be called with queued_successfully status',
                ]);

                return null;
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL METRICS: Failed email_verification_metrics operation', [
                'user_id' => $userId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * ENTERPRISE ASYNC: Insert into password_reset_metrics with full system data
     * Identical structure to email_verification_metrics for unified analytics
     */
    private function insertPasswordResetMetricsAsync(
        int $userId,
        string $status,
        array $systemMetrics,
        array $context
    ): ?int {
        try {
            $pdo = $this->databasePool->getConnection();

            $stmt = $pdo->prepare('
                INSERT INTO password_reset_metrics (
                    user_id, email, action, ip_address, user_agent, token_hash,
                    error_code, error_message, worker_id, retry_count,
                    queue_time_ms, processing_time_ms, redis_l1_status,
                    database_pool_id, server_load_avg, memory_usage_mb, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');

            // ENTERPRISE GALAXY FIX: queue_time_ms = NULL at INSERT (not 0!)
            // Worker will calculate milliseconds from created_at TIMESTAMP field
            $result = $stmt->execute([
                $userId,
                $context['email'] ?? 'unknown@need2talk.app',
                $status, // action column renamed to status-compatible enum
                $context['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $context['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $context['token_hash'] ?? null,
                $context['error_code'] ?? null,
                $context['error_message'] ?? null,
                $systemMetrics['worker_id'] ?? null,
                $context['retry_count'] ?? 0,
                $systemMetrics['queue_time_ms'] ?? null,  // NULL - worker calculates from created_at
                $systemMetrics['processing_time_ms'] ?? null,
                $systemMetrics['redis_l1_status'] ?? null,
                $systemMetrics['database_pool_id'] ?? null,
                $systemMetrics['server_load_avg'] ?? null,
                $systemMetrics['memory_usage_mb'] ?? null,
            ]);

            $recordId = $result ? (int) $pdo->lastInsertId() : null;

            return $recordId;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL METRICS: Failed password_reset_metrics insert', [
                'user_id' => $userId,
                'status' => $status,
                'error' => $e->getMessage(),
                'email_type' => 'password_reset',
            ]);

            return null;
        }
    }

    // REMOVED: updateEmailVerificationAnalyticsAsync - email_verification_analytics is a VIEW that auto-updates

    /**
     * ENTERPRISE ASYNC: Update email_metrics_hourly and daily system metrics
     */
    private function updateSystemEmailMetricsAsync(
        string $emailType,
        string $status,
        array $systemMetrics,
        array $context
    ): bool {
        try {
            $pdo = $this->databasePool->getConnection();

            // ENTERPRISE TIPS: Count only actually sent emails, not queued or processing steps

            // Only update metrics for final statuses (not intermediate steps)
            if (!in_array($status, ['sent_successfully', 'send_failed', 'critical_failure'], true)) {
                return true; // Skip metrics update for intermediate steps
            }

            // Update hourly metrics - count only final email delivery status
            $action = ($status === 'sent_successfully') ? 'sent' : 'failed';

            $hourlyStmt = $pdo->prepare("
                INSERT INTO email_metrics_hourly
                (hour, email_type, action, count, total_size, avg_processing_time)
                VALUES (DATE_TRUNC('hour', NOW()), ?, ?, 1, ?, ?)
                ON CONFLICT (hour, email_type, action) DO UPDATE SET
                    count = email_metrics_hourly.count + 1,
                    total_size = email_metrics_hourly.total_size + EXCLUDED.total_size,
                    avg_processing_time = (email_metrics_hourly.avg_processing_time * (email_metrics_hourly.count - 1) + EXCLUDED.avg_processing_time) / email_metrics_hourly.count
            ");

            $processingTime = $systemMetrics['processing_time_ms'] ?? 0;
            $emailSize = $context['size'] ?? 0; // Get email size from context

            $hourlyResult = $hourlyStmt->execute([
                $emailType,
                $action,
                $emailSize,
                $processingTime,
            ]);

            // Update daily metrics - adapt to existing schema
            $dailyStmt = $pdo->prepare('
                INSERT INTO email_metrics_daily
                (day, email_type, action, count, total_size, avg_processing_time)
                VALUES (CURRENT_DATE, ?, ?, 1, ?, ?)
                ON CONFLICT (day, email_type, action) DO UPDATE SET
                    count = email_metrics_daily.count + 1,
                    total_size = email_metrics_daily.total_size + EXCLUDED.total_size,
                    avg_processing_time = (email_metrics_daily.avg_processing_time * (email_metrics_daily.count - 1) + EXCLUDED.avg_processing_time) / email_metrics_daily.count
            ');

            $dailyResult = $dailyStmt->execute([
                $emailType,
                $action,
                $emailSize,
                $processingTime,
            ]);

            return $hourlyResult && $dailyResult;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL METRICS: Failed system email metrics update', [
                'email_type' => $emailType,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE SECURITY: Validate input against malicious attacks
     */
    private function validateEmailMetricsInput(int $userId, string $status, array $context): bool
    {
        // Anti-malicious validation
        if ($userId <= 0 || $userId > 999999999) {
            Logger::security('warning', 'SECURITY: Invalid user_id in email metrics', [
                'user_id' => $userId,
                'blocked' => true,
                'reason' => 'user_id_out_of_range',
            ]);

            return false;
        }

        // Status validation
        $validStatuses = [
            'queued_successfully', 'queue_failed', 'critical_failure',
            'processed_by_worker', 'sent_successfully', 'send_failed',
            'token_verified', 'token_expired', 'token_invalid',
        ];

        if (!in_array($status, $validStatuses, true)) {
            Logger::security('warning', 'SECURITY: Invalid status in email metrics', [
                'user_id' => $userId,
                'invalid_status' => $status,
                'blocked' => true,
            ]);

            return false;
        }

        // Context size validation (prevent payload attacks)
        if (strlen(json_encode($context)) > 10000) {
            Logger::security('warning', 'SECURITY: Oversized context in email metrics', [
                'user_id' => $userId,
                'context_size' => strlen(json_encode($context)),
                'blocked' => true,
            ]);

            return false;
        }

        return true;
    }

    /**
     * ENTERPRISE: Generate comprehensive system metrics
     */
    private function generateEnterpriseSystemMetrics(float $eventStartTime, array $context): array
    {
        $queueStartTime = $context['queue_start_time'] ?? $eventStartTime;

        // ENTERPRISE GALAXY FIX: queue_time_ms stores NULL at INSERT
        // Worker will calculate milliseconds using created_at timestamp (TIMESTAMP field)
        // This prevents confusion between Unix timestamp and milliseconds in same field
        $status = $context['status'] ?? 'unknown';
        $queueTimeMs = null;  // Always NULL at INSERT - worker calculates from created_at

        // Redis L1 status check
        $redis = $this->redisManager->getConnection('L1_cache');
        $redisL1Status = 'inactive';

        if ($redis) {
            try {
                $redis->ping();
                $redisL1Status = 'active';
            } catch (\Exception $e) {
                $redisL1Status = 'error';
            }
        }

        // Database pool ID
        $databasePoolId = 'enterprise_pool_' . gethostname() . '_' . getmypid();

        // Server load average (Unix systems)
        $serverLoadAvg = 0.0;

        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $serverLoadAvg = $load[0] ?? 0.0;
        }

        // Memory usage
        $memoryUsageMb = (int) round(memory_get_usage(true) / 1024 / 1024);

        // Worker ID if present
        $workerId = $context['worker_id'] ?? null;

        $metrics = [
            'queue_time_ms' => $queueTimeMs,  // NULL - worker calculates from created_at
            'processing_time_ms' => $context['processing_time_ms'] ?? null,
            'worker_id' => $workerId,
            'redis_l1_status' => $redisL1Status,
            'database_pool_id' => $databasePoolId,
            'server_load_avg' => $serverLoadAvg,
            'memory_usage_mb' => $memoryUsageMb,
            'system_timestamp' => time(),
            'php_version' => PHP_VERSION,
            'enterprise_metrics_version' => '2.0',
        ];

        return $metrics;
    }

    /**
     * Convert status to analytics action
     */
    private function convertStatusToAction(string $status): string
    {
        $actionMap = [
            'queued_successfully' => 'queued',
            'queue_failed' => 'failed',
            'critical_failure' => 'failed',
            'processed_by_worker' => 'processed',
            'sent_successfully' => 'sent',
            'send_failed' => 'failed',
            'token_verified' => 'verified',
            'token_expired' => 'expired',
            'token_invalid' => 'invalid',
        ];

        return $actionMap[$status] ?? 'unknown';
    }

    /**
     * ENTERPRISE: Cache aggregated metrics in Redis L1 for high-speed access
     */
    private function cacheAggregatedMetrics(string $emailType, string $status, array $systemMetrics): void
    {
        try {
            $redis = $this->redisManager->getConnection('L1_cache');

            if (!$redis) {
                return;
            }

            $today = date('Y-m-d');
            $currentHour = date('Y-m-d H:00:00');

            // Cache daily counters
            $dailyKey = self::DAILY_COUNTER_PREFIX . $emailType . ':' . $today;
            $redis->hincrby($dailyKey, $status, 1);
            $redis->expire($dailyKey, self::CACHE_TTL_LONG);

            // Cache hourly counters
            $hourlyKey = self::HOURLY_COUNTER_PREFIX . $emailType . ':' . $currentHour;
            $redis->hincrby($hourlyKey, $status, 1);
            $redis->expire($hourlyKey, self::CACHE_TTL_MEDIUM);

            // Cache latest metrics for dashboard
            $metricsKey = self::METRICS_L1_PREFIX . 'latest:' . $emailType;
            $redis->hset($metricsKey, 'last_status', $status);
            $redis->hset($metricsKey, 'last_update', time());
            $redis->hset($metricsKey, 'redis_status', $systemMetrics['redis_l1_status']);
            $redis->hset($metricsKey, 'server_load', $systemMetrics['server_load_avg']);
            $redis->hset($metricsKey, 'memory_mb', $systemMetrics['memory_usage_mb']);
            $redis->expire($metricsKey, self::CACHE_TTL_SHORT);

        } catch (\Exception $e) {
            Logger::database('warning', 'EMAIL METRICS: Failed to cache aggregated metrics', [
                'email_type' => $emailType,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE: Get Redis health status for all pools
     */
    private function getRedisHealthStatus(): array
    {
        $status = [];
        $pools = ['L1_cache', 'L3_async_email', 'metrics'];

        foreach ($pools as $pool) {
            $redis = $this->redisManager->getConnection($pool);

            try {
                if ($redis && $redis->ping()) {
                    $status[$pool] = 'connected';
                } else {
                    $status[$pool] = 'disconnected';
                }
            } catch (\Exception $e) {
                $status[$pool] = 'error';
            }
        }

        return $status;
    }

    /**
     * ENTERPRISE: Wait for async operations to complete (simplified for PHP)
     */
    private function waitForAsyncOperations(array $promises, int $timeoutMs): void
    {
        // In PHP, operations are actually synchronous
        // This method is placeholder for future async implementation
    }

    /**
     * ENTERPRISE: Check if all metrics tables exist (compatible method)
     */
    private function checkMetricsTablesExistence(): array
    {
        $tables = [
            'email_verification_metrics',
            'email_verification_analytics',
            'email_metrics_hourly',
            'email_metrics_daily',
        ];

        $status = [];

        try {
            $pdo = $this->databasePool->getConnection();

            // ENTERPRISE: Use INFORMATION_SCHEMA for reliable table checking (PostgreSQL)
            $stmt = $pdo->prepare('
                SELECT table_name
                FROM information_schema.tables
                WHERE table_catalog = CURRENT_DATABASE()
                  AND table_schema = \'public\'
                  AND table_name IN (?, ?, ?, ?)
            ');

            $stmt->execute($tables);
            $existingTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $status[$table] = in_array($table, $existingTables, true);
            }

        } catch (\Exception $e) {
            foreach ($tables as $table) {
                $status[$table] = 'error: ' . $e->getMessage();
            }
        }

        return $status;
    }
}
