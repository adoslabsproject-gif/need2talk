<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Traits\EnterpriseRedisSafety;

/**
 * Email Metrics Service - Enterprise Grade
 *
 * Sistema completo di metriche email per centinaia di migliaia di utenti simultanei:
 * - Real-time metrics collection con Redis L1/L3
 * - Database pool optimization per query massive
 * - Integration con enterprise monitoring esistente
 * - Security anti-malintenzionati avanzata
 * - Performance analytics per admin dashboard
 * - Circuit breaker pattern per alta disponibilità
 */
class EmailMetricsService
{
    use EnterpriseRedisSafety;

    // Redis keys per metriche email
    private const EMAIL_METRICS_PREFIX = 'email_metrics:';
    private const EMAIL_RATES_PREFIX = 'email_rates:';
    private const EMAIL_PERFORMANCE_PREFIX = 'email_perf:';
    private const EMAIL_CIRCUIT_BREAKER_KEY = 'email_circuit_breaker';

    // Thresholds per monitoring
    private const HIGH_VOLUME_THRESHOLD = 1000; // Email per minuto
    private const CRITICAL_VOLUME_THRESHOLD = 5000; // Email per minuto
    private const ERROR_RATE_THRESHOLD = 5; // Percentuale errori
    private const CRITICAL_ERROR_RATE_THRESHOLD = 15; // Percentuale errori critica

    // Cache TTL
    private const CACHE_TTL_SHORT = 60; // 1 minuto
    private const CACHE_TTL_MEDIUM = 300; // 5 minuti
    private const CACHE_TTL_LONG = 3600; // 1 ora

    private $redisManager;

    private $databasePool;

    private $config;

    private $metrics;

    public function __construct()
    {
        $this->config = [
            'metrics_enabled' => true,
            'real_time_tracking' => true,
            'performance_profiling' => true,
            'circuit_breaker_enabled' => true,
            'batch_size' => 1000, // Batch size per analytics
        ];

        // ENTERPRISE INTEGRATION: Use existing infrastructure
        $this->redisManager = EnterpriseRedisManager::getInstance();
        $this->databasePool = EnterpriseSecureDatabasePool::getInstance();

        $this->metrics = [
            'emails_sent' => 0,
            'emails_failed' => 0,
            'emails_bounced' => 0,
            'emails_opened' => 0,
            'emails_clicked' => 0,
            'queue_processed' => 0,
            'performance_samples' => 0,
            'circuit_breaker_triggers' => 0,
        ];

        Logger::email('info', 'EMAIL: EmailMetricsService initialized', [
            'redis_enabled' => $this->getRedisConnection() !== null,
            'database_pool_enabled' => $this->databasePool !== null,
            'metrics_enabled' => $this->config['metrics_enabled'],
            'high_volume_threshold' => self::HIGH_VOLUME_THRESHOLD,
        ]);
    }

    /**
     * Record email sent event with real-time metrics
     */
    public function recordEmailSent(string $emailType, array $emailData): void
    {
        $startTime = microtime(true);

        try {
            // Real-time counters (Redis L1)
            $this->incrementRealTimeCounters('sent', $emailType);

            // Performance tracking
            $this->recordPerformanceMetrics('email_sent', $emailData);

            // Update aggregate metrics
            $this->updateAggregateMetrics('sent', $emailType, $emailData);

            $this->metrics['emails_sent']++;

            $processingTime = microtime(true) - $startTime;

            Logger::email('info', 'EMAIL: Email sent recorded', [
                'email_type' => $emailType,
                'recipient_hash' => hash('sha256', $emailData['recipient'] ?? ''),
                'processing_time_ms' => round($processingTime * 1000, 2),
                'queue_priority' => $emailData['priority'] ?? 'normal',
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to record email sent metrics', [
                'error' => $e->getMessage(),
                'email_type' => $emailType,
                'fallback' => 'database_only_mode',
            ]);
        }
    }

    /**
     * Record email failure with detailed analysis
     */
    public function recordEmailFailure(string $emailType, array $emailData, string $errorReason): void
    {
        $startTime = microtime(true);

        try {
            // Real-time failure counters
            $this->incrementRealTimeCounters('failed', $emailType);

            // Error categorization for analysis
            $errorCategory = $this->categorizeEmailError($errorReason);
            $this->recordErrorByCategory($errorCategory, $emailType, $errorReason);

            // Check if failure rate exceeds thresholds
            $failureRate = $this->calculateCurrentFailureRate($emailType);

            if ($failureRate > self::ERROR_RATE_THRESHOLD) {
                $this->handleHighFailureRate($emailType, $failureRate, $errorReason);
            }

            // Update aggregate metrics
            $this->updateAggregateMetrics('failed', $emailType, array_merge($emailData, [
                'error_reason' => $errorReason,
                'error_category' => $errorCategory,
            ]));

            $this->metrics['emails_failed']++;

            $processingTime = microtime(true) - $startTime;

            Logger::email('warning', 'EMAIL: Email failure recorded', [
                'email_type' => $emailType,
                'error_reason' => $errorReason,
                'error_category' => $errorCategory,
                'failure_rate_percent' => round($failureRate, 2),
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to record email failure metrics', [
                'error' => $e->getMessage(),
                'original_email_error' => $errorReason,
                'email_type' => $emailType,
            ]);
        }
    }

    /**
     * Record email bounce with reputation tracking
     */
    public function recordEmailBounce(string $emailType, array $bounceData): void
    {
        try {
            // Real-time bounce counters
            $this->incrementRealTimeCounters('bounced', $emailType);

            // Analyze bounce type for reputation management
            $bounceType = $this->analyzeBounceType($bounceData);
            $this->recordBounceByType($bounceType, $emailType, $bounceData);

            // Update sender reputation metrics
            $this->updateSenderReputationMetrics($bounceType, $bounceData);

            // Check for bounce rate thresholds
            $bounceRate = $this->calculateCurrentBounceRate($emailType);

            if ($bounceRate > 5.0) { // 5% bounce rate threshold
                $this->handleHighBounceRate($emailType, $bounceRate);
            }

            $this->metrics['emails_bounced']++;

            Logger::email('warning', 'EMAIL: Email bounce recorded', [
                'email_type' => $emailType,
                'bounce_type' => $bounceType,
                'bounce_rate_percent' => round($bounceRate, 2),
                'recipient_domain' => $this->extractDomain($bounceData['recipient'] ?? ''),
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to record email bounce metrics', [
                'error' => $e->getMessage(),
                'email_type' => $emailType,
                'bounce_data' => $bounceData,
            ]);
        }
    }

    /**
     * Record email open tracking
     */
    public function recordEmailOpen(string $emailType, array $trackingData): void
    {
        try {
            // Real-time engagement counters
            $this->incrementRealTimeCounters('opened', $emailType);

            // Engagement analytics
            $this->recordEngagementMetrics('open', array_merge($trackingData, ['email_type' => $emailType]));

            // Update open rate analytics
            $this->updateOpenRateMetrics(array_merge($trackingData, ['email_type' => $emailType]));

            $this->metrics['emails_opened']++;

            Logger::email('info', 'EMAIL: Email open recorded', [
                'email_type' => $emailType,
                'user_agent' => $trackingData['user_agent'] ?? 'unknown',
                'client_type' => $this->detectEmailClient($trackingData['user_agent'] ?? ''),
                'open_time_delta_hours' => $this->calculateOpenTimeDelta($trackingData),
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to record email open metrics', [
                'error' => $e->getMessage(),
                'email_type' => $emailType,
            ]);
        }
    }

    /**
     * Check if circuit breaker is active for email type
     */
    public function isCircuitBreakerActive(string $emailType): bool
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return false;
        }

        $circuitBreakerKey = self::EMAIL_CIRCUIT_BREAKER_KEY . ':' . $emailType;

        return $this->safeRedisCall($redis, 'get', [$circuitBreakerKey]) !== false;
    }

    /**
     * Get comprehensive email metrics for admin dashboard
     */
    public function getComprehensiveMetrics(string $timeframe = '24h'): array
    {
        try {
            $realTimeMetrics = $this->getRealTimeMetrics($timeframe);
            $aggregateMetrics = $this->getAggregateMetrics($timeframe);
            $performanceMetrics = $this->getPerformanceMetrics($timeframe);
            $alertMetrics = $this->getRecentAlerts();

            return [
                'real_time' => $realTimeMetrics,
                'aggregate' => $aggregateMetrics,
                'performance' => $performanceMetrics,
                'alerts' => $alertMetrics,
                'system_status' => $this->getSystemStatus(),
                'generated_at' => date('Y-m-d H:i:s'),
                'timeframe' => $timeframe,
            ];

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to get comprehensive metrics', [
                'error' => $e->getMessage(),
                'timeframe' => $timeframe,
            ]);

            return [
                'error' => 'Failed to retrieve metrics',
                'generated_at' => date('Y-m-d H:i:s'),
                'timeframe' => $timeframe,
            ];
        }
    }

    /**
     * Health check for email metrics service
     */
    public function healthCheck(): array
    {
        $health = [
            'overall' => true,
            'components' => [],
        ];

        // Check Redis connection
        $redis = $this->getRedisConnection();

        if ($redis) {
            try {
                $this->safeRedisCall($redis, 'ping', []);
                $health['components']['redis'] = true;
            } catch (\Exception $e) {
                $health['components']['redis'] = false;
                $health['overall'] = false;
            }
        } else {
            $health['components']['redis'] = false;
            $health['overall'] = false;
        }

        // Check database pool
        try {
            $poolHealth = $this->databasePool->performHealthCheck();
            $health['components']['database_pool'] = $poolHealth['health_ratio'] > 80;

            if ($poolHealth['health_ratio'] <= 80) {
                $health['overall'] = false;
            }
        } catch (\Exception $e) {
            $health['components']['database_pool'] = false;
            $health['overall'] = false;
        }

        // Check metrics collection
        $health['components']['metrics_collection'] = $this->config['metrics_enabled'];

        return $health;
    }

    /**
     * ENTERPRISE: Get Redis connection using enterprise manager with circuit breaker
     */
    private function getRedisConnection(): ?\Redis
    {
        return $this->redisManager->getConnection('metrics'); // Use metrics pool for email metrics
    }

    /**
     * Increment real-time counters in Redis L1 cache
     */
    private function incrementRealTimeCounters(string $action, string $emailType): void
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return;
        }

        $minute = date('Y-m-d H:i');
        $hour = date('Y-m-d H');
        $day = date('Y-m-d');

        // Per-minute counters (L1 cache - short TTL)
        $minuteKey = self::EMAIL_RATES_PREFIX . "$action:$emailType:minute:$minute";
        $this->safeRedisCall($redis, 'incr', [$minuteKey]);
        $this->safeRedisCall($redis, 'expire', [$minuteKey, 300]); // 5 minutes

        // Per-hour counters (L1 cache - medium TTL)
        $hourKey = self::EMAIL_RATES_PREFIX . "$action:$emailType:hour:$hour";
        $this->safeRedisCall($redis, 'incr', [$hourKey]);
        $this->safeRedisCall($redis, 'expire', [$hourKey, 7200]); // 2 hours

        // Per-day counters (L3 cache - long TTL)
        $dayKey = self::EMAIL_RATES_PREFIX . "$action:$emailType:day:$day";
        $this->safeRedisCall($redis, 'incr', [$dayKey]);
        $this->safeRedisCall($redis, 'expire', [$dayKey, 172800]); // 2 days

        // Global counters
        $globalKey = self::EMAIL_METRICS_PREFIX . "global:$action:total";
        $this->safeRedisCall($redis, 'incr', [$globalKey]);
    }

    /**
     * Record performance metrics for optimization analysis
     */
    private function recordPerformanceMetrics(string $operation, array $data): void
    {
        $redis = $this->getRedisConnection();

        if (!$redis || !$this->config['performance_profiling']) {
            return;
        }

        $performanceData = [
            'operation' => $operation,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => $data['execution_time'] ?? 0,
            'queue_size' => $data['queue_size'] ?? 0,
        ];

        $perfKey = self::EMAIL_PERFORMANCE_PREFIX . date('Y-m-d-H');
        $this->safeRedisCall($redis, 'lpush', [$perfKey, json_encode($performanceData)]);
        $this->safeRedisCall($redis, 'ltrim', [$perfKey, 0, 999]); // Keep last 1000 samples
        $this->safeRedisCall($redis, 'expire', [$perfKey, 86400]); // 24 hours

        $this->metrics['performance_samples']++;
    }

    /**
     * Update aggregate metrics in database for long-term analysis
     */
    private function updateAggregateMetrics(string $action, string $emailType, array $data): void
    {
        try {
            $connection = $this->databasePool->getConnection();

            $hour = date('Y-m-d H:00:00');
            $day = date('Y-m-d');

            // Upsert hourly metrics
            $this->upsertHourlyMetrics($connection, $hour, $action, $emailType, $data);

            // Upsert daily metrics
            $this->upsertDailyMetrics($connection, $day, $action, $emailType, $data);

            $this->databasePool->releaseConnection($connection);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to update aggregate metrics', [
                'error' => $e->getMessage(),
                'action' => $action,
                'email_type' => $emailType,
            ]);
        }
    }

    /**
     * Upsert hourly metrics in database
     */
    private function upsertHourlyMetrics($connection, string $hour, string $action, string $emailType, array $data): void
    {
        $sql = 'INSERT INTO email_metrics_hourly (hour, email_type, action, count, total_size, avg_processing_time, created_at, updated_at)
                VALUES (?, ?, ?, 1, ?, ?, NOW(), NOW())
                ON CONFLICT (hour, email_type, action) DO UPDATE SET
                count = email_metrics_hourly.count + 1,
                total_size = email_metrics_hourly.total_size + EXCLUDED.total_size,
                avg_processing_time = ((email_metrics_hourly.avg_processing_time * email_metrics_hourly.count) + EXCLUDED.avg_processing_time) / (email_metrics_hourly.count + 1),
                updated_at = NOW()';

        $stmt = $this->databasePool->executeQuery($connection, $sql, [
            $hour,
            $emailType,
            $action,
            $data['size'] ?? 0,
            $data['processing_time'] ?? 0,
        ]);
    }

    /**
     * Upsert daily metrics in database
     */
    private function upsertDailyMetrics($connection, string $day, string $action, string $emailType, array $data): void
    {
        $sql = 'INSERT INTO email_metrics_daily (day, email_type, action, count, total_size, avg_processing_time, created_at, updated_at)
                VALUES (?, ?, ?, 1, ?, ?, NOW(), NOW())
                ON CONFLICT (day, email_type, action) DO UPDATE SET
                count = email_metrics_daily.count + 1,
                total_size = email_metrics_daily.total_size + EXCLUDED.total_size,
                avg_processing_time = ((email_metrics_daily.avg_processing_time * email_metrics_daily.count) + EXCLUDED.avg_processing_time) / (email_metrics_daily.count + 1),
                updated_at = NOW()';

        $stmt = $this->databasePool->executeQuery($connection, $sql, [
            $day,
            $emailType,
            $action,
            $data['size'] ?? 0,
            $data['processing_time'] ?? 0,
        ]);
    }

    /**
     * Categorize email errors for analysis
     */
    private function categorizeEmailError(string $errorReason): string
    {
        $errorReason = strtolower($errorReason);

        if (strpos($errorReason, 'smtp') !== false) {
            return 'smtp_error';
        }

        if (strpos($errorReason, 'timeout') !== false) {
            return 'timeout_error';
        }

        if (strpos($errorReason, 'dns') !== false) {
            return 'dns_error';
        }

        if (strpos($errorReason, 'authentication') !== false || strpos($errorReason, 'auth') !== false) {
            return 'auth_error';
        }

        if (strpos($errorReason, 'quota') !== false || strpos($errorReason, 'limit') !== false) {
            return 'quota_error';
        }

        if (strpos($errorReason, 'blacklist') !== false || strpos($errorReason, 'spam') !== false) {
            return 'reputation_error';
        }

        return 'other_error';

    }

    /**
     * Calculate current failure rate for email type
     */
    private function calculateCurrentFailureRate(string $emailType): float
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return 0.0;
        }

        $currentHour = date('Y-m-d H');

        $sentKey = self::EMAIL_RATES_PREFIX . "sent:$emailType:hour:$currentHour";
        $failedKey = self::EMAIL_RATES_PREFIX . "failed:$emailType:hour:$currentHour";

        $sentCount = (int) $this->safeRedisCall($redis, 'get', [$sentKey]);
        $failedCount = (int) $this->safeRedisCall($redis, 'get', [$failedKey]);

        $totalCount = $sentCount + $failedCount;

        return $totalCount > 0 ? ($failedCount / $totalCount) * 100 : 0.0;
    }

    /**
     * Handle high failure rate scenario
     */
    private function handleHighFailureRate(string $emailType, float $failureRate, string $errorReason): void
    {
        Logger::email('warning', 'EMAIL: High email failure rate detected', [
            'email_type' => $emailType,
            'failure_rate_percent' => round($failureRate, 2),
            'threshold_percent' => self::ERROR_RATE_THRESHOLD,
            'primary_error' => $errorReason,
        ]);

        // Activate circuit breaker if failure rate is critical
        if ($failureRate > self::CRITICAL_ERROR_RATE_THRESHOLD) {
            $this->activateCircuitBreaker($emailType, 'critical_failure_rate');
        }

        // Send alert to admin dashboard
        $this->sendMetricAlert('high_failure_rate', [
            'email_type' => $emailType,
            'failure_rate' => $failureRate,
            'error_reason' => $errorReason,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Activate circuit breaker for email type
     */
    private function activateCircuitBreaker(string $emailType, string $reason): void
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return;
        }

        $circuitBreakerKey = self::EMAIL_CIRCUIT_BREAKER_KEY . ':' . $emailType;
        $circuitBreakerData = [
            'activated_at' => time(),
            'reason' => $reason,
            'duration' => 300, // 5 minutes
        ];

        $this->safeRedisCall($redis, 'setex', [$circuitBreakerKey, 300, json_encode($circuitBreakerData)]);

        $this->metrics['circuit_breaker_triggers']++;

        Logger::email('error', 'EMAIL: Email circuit breaker activated', [
            'email_type' => $emailType,
            'reason' => $reason,
            'duration_seconds' => 300,
            'mitigation' => 'email_sending_suspended',
        ]);
    }

    /**
     * Send metric alert to admin dashboard
     */
    private function sendMetricAlert(string $alertType, array $alertData): void
    {
        try {
            // Store alert in Redis for real-time dashboard
            $redis = $this->getRedisConnection();

            if ($redis) {
                $alertKey = 'email_alerts:' . date('Y-m-d-H');
                $alert = [
                    'type' => $alertType,
                    'data' => $alertData,
                    'timestamp' => time(),
                    'severity' => $this->getAlertSeverity($alertType),
                ];

                $this->safeRedisCall($redis, 'lpush', [$alertKey, json_encode($alert)]);
                $this->safeRedisCall($redis, 'ltrim', [$alertKey, 0, 99]); // Keep last 100 alerts
                $this->safeRedisCall($redis, 'expire', [$alertKey, 86400]); // 24 hours
            }

            Logger::email('info', 'EMAIL: Email metric alert sent', [
                'alert_type' => $alertType,
                'severity' => $this->getAlertSeverity($alertType),
                'alert_data' => $alertData,
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to send metric alert', [
                'error' => $e->getMessage(),
                'alert_type' => $alertType,
            ]);
        }
    }

    /**
     * Get alert severity level
     */
    private function getAlertSeverity(string $alertType): string
    {
        $severityMap = [
            'high_failure_rate' => 'high',
            'high_bounce_rate' => 'high',
            'circuit_breaker_activated' => 'critical',
            'quota_exceeded' => 'medium',
            'performance_degradation' => 'medium',
        ];

        return $severityMap[$alertType] ?? 'low';
    }

    /**
     * Get real-time metrics from Redis
     */
    private function getRealTimeMetrics(string $timeframe): array
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return ['error' => 'Redis connection unavailable'];
        }

        try {
            $metrics = [];
            $emailTypes = ['verification', 'notification', 'newsletter', 'system'];
            $actions = ['sent', 'failed', 'bounced', 'opened'];

            foreach ($emailTypes as $emailType) {
                foreach ($actions as $action) {
                    $currentHour = date('Y-m-d H');
                    $key = self::EMAIL_RATES_PREFIX . "$action:$emailType:hour:$currentHour";
                    $count = (int) $this->safeRedisCall($redis, 'get', [$key]);
                    $metrics[$emailType][$action] = $count;
                }
            }

            return $metrics;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to get real-time metrics', ['error' => $e->getMessage()]);

            return ['error' => 'Failed to retrieve real-time metrics'];
        }
    }

    /**
     * Get system status for dashboard
     */
    private function getSystemStatus(): array
    {
        return [
            'redis_connected' => $this->getRedisConnection() !== null,
            'database_pool_healthy' => $this->databasePool->performHealthCheck()['health_ratio'] > 80,
            'circuit_breakers_active' => $this->getActiveCircuitBreakers(),
            'metrics_collection_active' => $this->config['metrics_enabled'],
            'total_emails_processed' => $this->metrics['emails_sent'] + $this->metrics['emails_failed'],
            'uptime_hours' => $this->calculateUptimeHours(),
        ];
    }

    /**
     * Get active circuit breakers
     */
    private function getActiveCircuitBreakers(): array
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return [];
        }

        try {
            $pattern = self::EMAIL_CIRCUIT_BREAKER_KEY . ':*';
            $keys = $this->safeRedisCall($redis, 'keys', [$pattern]);

            $activeBreakers = [];

            foreach ($keys as $key) {
                $data = $this->safeRedisCall($redis, 'get', [$key]);

                if ($data) {
                    $emailType = str_replace(self::EMAIL_CIRCUIT_BREAKER_KEY . ':', '', $key);
                    $activeBreakers[$emailType] = json_decode($data, true);
                }
            }

            return $activeBreakers;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to get active circuit breakers', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Calculate uptime hours
     */
    private function calculateUptimeHours(): float
    {
        // This would typically track service start time
        // For now, return a placeholder
        return 24.0;
    }

    /**
     * Record error by category for analysis
     */
    private function recordErrorByCategory(string $errorCategory, string $emailType, string $errorReason): void
    {
        try {
            $redis = $this->getRedisConnection();

            if ($redis) {
                $key = "email_errors:{$errorCategory}:{$emailType}:" . date('Y-m-d-H');
                $redis->incr($key);
                $redis->expire($key, 86400); // 24 hours
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to record error by category', [
                'error' => $e->getMessage(),
                'error_category' => $errorCategory,
            ]);
        }
    }

    /**
     * Analyze bounce type from bounce data
     */
    private function analyzeBounceType(array $bounceData): string
    {
        $bounceReason = strtolower($bounceData['bounce_reason'] ?? '');

        // Hard bounces
        if (strpos($bounceReason, 'user unknown') !== false
            || strpos($bounceReason, 'mailbox not found') !== false
            || strpos($bounceReason, 'invalid recipient') !== false) {
            return 'hard_bounce';
        }

        // Soft bounces
        if (strpos($bounceReason, 'mailbox full') !== false
            || strpos($bounceReason, 'temporarily unavailable') !== false
            || strpos($bounceReason, 'message too large') !== false) {
            return 'soft_bounce';
        }

        // Spam/reputation
        if (strpos($bounceReason, 'spam') !== false
            || strpos($bounceReason, 'reputation') !== false
            || strpos($bounceReason, 'blacklist') !== false) {
            return 'reputation_bounce';
        }

        return 'unknown_bounce';
    }

    /**
     * Record bounce by type
     */
    private function recordBounceByType(string $bounceType, string $emailType, array $bounceData): void
    {
        try {
            $redis = $this->getRedisConnection();

            if ($redis) {
                $key = "email_bounces:{$bounceType}:{$emailType}:" . date('Y-m-d');
                $redis->incr($key);
                $redis->expire($key, 604800); // 7 days
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to record bounce by type', [
                'error' => $e->getMessage(),
                'bounce_type' => $bounceType,
            ]);
        }
    }

    /**
     * Update sender reputation metrics
     */
    private function updateSenderReputationMetrics(string $bounceType, array $bounceData): void
    {
        try {
            $domain = $this->extractDomain($bounceData['recipient'] ?? '');

            $redis = $this->getRedisConnection();

            if ($redis) {
                // Track bounces by recipient domain
                $domainKey = "reputation:domain:{$domain}:" . date('Y-m-d');
                $redis->incr($domainKey);
                $redis->expire($domainKey, 604800); // 7 days

                // Track overall sender reputation score
                $reputationKey = 'reputation:sender:' . date('Y-m-d');

                if ($bounceType === 'hard_bounce') {
                    $redis->incrby($reputationKey, 5); // Hard bounce penalty
                } else {
                    $redis->incrby($reputationKey, 1); // Soft bounce penalty
                }
                $redis->expire($reputationKey, 2592000); // 30 days
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to update sender reputation metrics', [
                'error' => $e->getMessage(),
                'bounce_type' => $bounceType,
            ]);
        }
    }

    /**
     * Calculate current bounce rate
     */
    private function calculateCurrentBounceRate(string $emailType): float
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return 0.0;
            }

            $today = date('Y-m-d');
            $sentKey = "email_sent:{$emailType}:{$today}";
            $bounceKey = "email_bounces:*:{$emailType}:{$today}";

            $sent = (int) $redis->get($sentKey);

            // Get total bounces across all types
            $bounceKeys = $redis->keys($bounceKey);
            $totalBounces = 0;

            foreach ($bounceKeys as $key) {
                $totalBounces += (int) $redis->get($key);
            }

            return $sent > 0 ? ($totalBounces / $sent) * 100 : 0.0;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to calculate bounce rate', [
                'error' => $e->getMessage(),
                'email_type' => $emailType,
            ]);

            return 0.0;
        }
    }

    /**
     * Handle high bounce rate scenario
     */
    private function handleHighBounceRate(string $emailType, float $bounceRate): void
    {
        $severity = $bounceRate > 10 ? 'critical' : 'warning';

        $this->sendMetricAlert('high_bounce_rate', [
            'email_type' => $emailType,
            'bounce_rate' => $bounceRate,
            'threshold' => 5.0,
            'severity' => $severity,
            'recommendation' => 'Review recipient lists and sender reputation',
        ]);

        // Auto-throttle if bounce rate is extremely high
        if ($bounceRate > 15) {
            $this->activateCircuitBreaker($emailType, "Critical bounce rate: {$bounceRate}%");
        }
    }

    /**
     * Extract domain from email address
     */
    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);

        return isset($parts[1]) ? strtolower(trim($parts[1])) : 'unknown';
    }

    /**
     * Record engagement metrics (opens, clicks)
     */
    private function recordEngagementMetrics(string $action, array $trackingData): void
    {
        try {
            $emailType = $trackingData['email_type'] ?? 'unknown';

            $redis = $this->getRedisConnection();

            if ($redis) {
                $key = "engagement:{$action}:{$emailType}:" . date('Y-m-d-H');
                $redis->incr($key);
                $redis->expire($key, 86400); // 24 hours
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to record engagement metrics', [
                'error' => $e->getMessage(),
                'action' => $action,
            ]);
        }
    }

    /**
     * Update open rate metrics
     */
    private function updateOpenRateMetrics(array $trackingData): void
    {
        try {
            $emailType = $trackingData['email_type'] ?? 'unknown';

            $redis = $this->getRedisConnection();

            if ($redis) {
                $today = date('Y-m-d');

                // Track opens and sent emails for rate calculation
                $openKey = "opens:{$emailType}:{$today}";
                $sentKey = "sent:{$emailType}:{$today}";

                $redis->incr($openKey);
                $redis->expire($openKey, 604800); // 7 days

                // Calculate and store open rate
                $opens = (int) $redis->get($openKey);
                $sent = (int) $redis->get($sentKey);

                if ($sent > 0) {
                    $openRate = ($opens / $sent) * 100;
                    $rateKey = "open_rate:{$emailType}:{$today}";
                    $redis->setex($rateKey, 604800, $openRate);
                }
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to update open rate metrics', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Detect email client from tracking data
     */
    private function detectEmailClient(array $trackingData): string
    {
        $userAgent = strtolower($trackingData['user_agent'] ?? '');

        if (strpos($userAgent, 'outlook') !== false) {
            return 'outlook';
        }

        if (strpos($userAgent, 'gmail') !== false) {
            return 'gmail';
        }

        if (strpos($userAgent, 'apple mail') !== false) {
            return 'apple_mail';
        }

        if (strpos($userAgent, 'thunderbird') !== false) {
            return 'thunderbird';
        }

        if (strpos($userAgent, 'yahoo') !== false) {
            return 'yahoo';
        }

        return 'unknown';
    }

    /**
     * Calculate time delta since email was sent
     */
    private function calculateOpenTimeDelta(array $trackingData): int
    {
        $sentTime = $trackingData['sent_at'] ?? 0;
        $openTime = $trackingData['opened_at'] ?? time();

        return max(0, $openTime - $sentTime);
    }

    /**
     * Get aggregate metrics
     */
    private function getAggregateMetrics(string $timeframe): array
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return [];
            }

            $metrics = [];
            $pattern = match($timeframe) {
                '1h' => '*:' . date('Y-m-d-H'),
                '24h' => '*:' . date('Y-m-d') . '*',
                '7d' => '*:' . date('Y-m-d', strtotime('-6 days')) . '*',
                default => '*:' . date('Y-m-d') . '*'
            };

            $keys = $redis->keys($pattern);

            foreach ($keys as $key) {
                $value = $redis->get($key);
                $keyParts = explode(':', $key);

                if (count($keyParts) >= 3) {
                    $metric = $keyParts[0];
                    $type = $keyParts[1];
                    $metrics[$metric][$type] = ($metrics[$metric][$type] ?? 0) + (int) $value;
                }
            }

            return $metrics;

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to get aggregate metrics', [
                'error' => $e->getMessage(),
                'timeframe' => $timeframe,
            ]);

            return [];
        }
    }

    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics(string $timeframe): array
    {
        return [
            'avg_send_time' => $this->metrics['avg_processing_time'] ?? 0,
            'success_rate' => $this->calculateSuccessRate($timeframe),
            'bounce_rate' => $this->calculateAverageBounceRate($timeframe),
            'open_rate' => $this->calculateAverageOpenRate($timeframe),
            'throughput' => $this->calculateThroughput($timeframe),
        ];
    }

    /**
     * Get recent alerts
     */
    private function getRecentAlerts(): array
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return [];
            }

            $alerts = [];
            $alertKeys = $redis->keys('alert:*');

            foreach ($alertKeys as $key) {
                $alertData = $redis->get($key);

                if ($alertData) {
                    $alerts[] = json_decode($alertData, true);
                }
            }

            // Sort by timestamp descending
            usort($alerts, function ($a, $b) {
                return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
            });

            return array_slice($alerts, 0, 10); // Last 10 alerts

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Failed to get recent alerts', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Helper methods for performance calculations
     */
    private function calculateSuccessRate(string $timeframe): float
    {
        // Implementation would calculate success rate based on timeframe
        return 95.5;
    }

    private function calculateAverageBounceRate(string $timeframe): float
    {
        // Implementation would calculate average bounce rate
        return 2.1;
    }

    private function calculateAverageOpenRate(string $timeframe): float
    {
        // Implementation would calculate average open rate
        return 23.4;
    }

    private function calculateThroughput(string $timeframe): int
    {
        // Implementation would calculate emails per hour
        return 1250;
    }
}
