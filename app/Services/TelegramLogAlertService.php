<?php

declare(strict_types=1);

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;

/**
 * Telegram Log Alert Service - Enterprise Galaxy Edition
 *
 * Real-time Telegram notifications for critical log events.
 *
 * ARCHITECTURE:
 * - Async: Alerts queued to Redis, processed by worker (non-blocking)
 * - Rate Limited: Same error max 1 alert per 5 minutes (configurable)
 * - Configurable: Enable/disable, min level via app_settings + admin panel
 * - Professional: Clean formatted messages with context
 *
 * CONFIGURATION (app_settings):
 * - telegram_log_alerts_enabled: true/false
 * - telegram_log_alerts_min_level: warning|error|critical|emergency
 * - telegram_log_alerts_rate_limit_seconds: 300 (default 5 min)
 *
 * LOG LEVELS (PSR-3, lowest to highest):
 * - debug (100)
 * - info (200)
 * - notice (250)
 * - warning (300)   <- Minimum selectable
 * - error (400)
 * - critical (500)
 * - alert (550)
 * - emergency (600)
 *
 * @package Need2Talk\Services
 * @author Claude Code (Enterprise Galaxy Initiative)
 * @version 1.0.0
 */
class TelegramLogAlertService
{
    private const REDIS_QUEUE_KEY = 'telegram:log_alerts:queue';
    private const REDIS_RATE_LIMIT_PREFIX = 'telegram:log_alerts:rate:';
    private const CACHE_KEY = 'telegram_log_alerts_config';
    private const CACHE_TTL = 300; // 5 minutes
    private const INVALIDATION_TIMESTAMP_KEY = 'telegram:log_alerts:invalidation_timestamp';

    // PSR-3 log levels (numeric values for comparison)
    private const LOG_LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    // Level emojis for Telegram
    private const LEVEL_EMOJIS = [
        'warning' => '⚠️',
        'error' => '❌',
        'critical' => '🚨',
        'alert' => '🔔',
        'emergency' => '🆘',
    ];

    private static ?array $configCache = null;
    private static float $configLoadedAt = 0;

    /**
     * Queue an alert for async sending
     *
     * Called by Logger class when a log event matches the configured threshold.
     * This method is NON-BLOCKING - it queues to Redis and returns immediately.
     *
     * @param string $level Log level (warning, error, critical, emergency)
     * @param string $channel Log channel (default, security, api, database, etc.)
     * @param string $message Log message
     * @param array $context Additional context
     * @return bool True if queued successfully
     */
    public static function queueAlert(
        string $level,
        string $channel,
        string $message,
        array $context = []
    ): bool {
        try {
            // Load config (cached)
            $config = self::getConfig();

            // Check if enabled
            if (!$config['enabled']) {
                return false;
            }

            // Check if level meets minimum threshold
            if (!self::meetsThreshold($level, $config['min_level'])) {
                return false;
            }

            // Generate hash for rate limiting (same error = same hash)
            $alertHash = self::generateAlertHash($level, $channel, $message);

            // Check rate limit (via Redis)
            if (!self::checkRateLimit($alertHash, $config['rate_limit_seconds'])) {
                return false; // Rate limited, skip
            }

            // Queue alert to Redis
            $alertData = [
                'level' => $level,
                'channel' => $channel,
                'message' => $message,
                'context' => $context,
                'timestamp' => time(),
                'hash' => $alertHash,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ];

            $redis = self::getRedis();
            if (!$redis) {
                // Redis unavailable - send sync as fallback
                return self::sendAlertSync($alertData);
            }

            $redis->rPush(self::REDIS_QUEUE_KEY, json_encode($alertData));

            return true;

        } catch (\Throwable $e) {
            // Never break the application - fail silently
            error_log('[TelegramLogAlert] Queue failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process queued alerts (called by worker/cron)
     *
     * @param int $maxAlerts Maximum alerts to process per run
     * @return int Number of alerts processed
     */
    public static function processQueue(int $maxAlerts = 10): int
    {
        $processed = 0;

        try {
            $redis = self::getRedis();
            if (!$redis) {
                return 0;
            }

            for ($i = 0; $i < $maxAlerts; $i++) {
                $alertJson = $redis->lPop(self::REDIS_QUEUE_KEY);
                if (!$alertJson) {
                    break; // Queue empty
                }

                $alertData = json_decode($alertJson, true);
                if (!$alertData) {
                    continue; // Invalid JSON
                }

                self::sendAlertSync($alertData);
                $processed++;
            }

        } catch (\Throwable $e) {
            error_log('[TelegramLogAlert] Process queue failed: ' . $e->getMessage());
        }

        return $processed;
    }

    /**
     * Send alert synchronously (used by worker or as fallback)
     */
    private static function sendAlertSync(array $alertData): bool
    {
        $level = $alertData['level'] ?? 'error';
        $channel = $alertData['channel'] ?? 'default';
        $message = $alertData['message'] ?? 'Unknown error';
        $context = $alertData['context'] ?? [];
        $timestamp = $alertData['timestamp'] ?? time();
        $requestUri = $alertData['request_uri'] ?? null;
        $ip = $alertData['ip'] ?? null;

        // Format message
        $emoji = self::LEVEL_EMOJIS[$level] ?? '📋';
        $levelUpper = strtoupper($level);
        $channelDisplay = ucfirst($channel);

        $text = "{$emoji} *{$levelUpper}* | {$channelDisplay}\n\n";
        $text .= "*Message:*\n`{$message}`\n";

        if ($requestUri) {
            $text .= "\n*URI:* `{$requestUri}`";
        }
        if ($ip) {
            $text .= "\n*IP:* `{$ip}`";
        }

        // Add relevant context (limit to avoid huge messages)
        if (!empty($context)) {
            $contextStr = self::formatContext($context, 500);
            if ($contextStr) {
                $text .= "\n\n*Context:*\n```\n{$contextStr}\n```";
            }
        }

        $text .= "\n\n⏰ " . date('d/m/Y H:i:s', $timestamp) . " (Rome)";

        // Send via TelegramNotificationService
        return TelegramNotificationService::sendAdmin(
            $text,
            [], // No additional context (already in message)
            false, // Not silent for alerts
            'log_alert'
        );
    }

    /**
     * Check if log level meets minimum threshold
     */
    private static function meetsThreshold(string $level, string $minLevel): bool
    {
        $levelValue = self::LOG_LEVELS[strtolower($level)] ?? 0;
        $minValue = self::LOG_LEVELS[strtolower($minLevel)] ?? 400;

        return $levelValue >= $minValue;
    }

    /**
     * Generate hash for rate limiting (same error = same hash)
     */
    private static function generateAlertHash(string $level, string $channel, string $message): string
    {
        // Normalize message (remove variable parts like IDs, timestamps)
        $normalized = preg_replace('/\d+/', 'N', $message);
        $normalized = preg_replace('/[a-f0-9]{8,}/', 'HASH', $normalized);

        return hash('sha256', "{$level}:{$channel}:{$normalized}");
    }

    /**
     * Check rate limit for this alert hash
     *
     * @return bool True if allowed (not rate limited)
     */
    private static function checkRateLimit(string $hash, int $limitSeconds): bool
    {
        try {
            $redis = self::getRedis();
            if (!$redis) {
                return true; // No Redis = no rate limiting
            }

            $key = self::REDIS_RATE_LIMIT_PREFIX . $hash;

            // Try to set key with NX (only if not exists)
            $set = $redis->set($key, time(), ['nx', 'ex' => $limitSeconds]);

            return (bool) $set; // True if key was set (not rate limited)

        } catch (\Throwable $e) {
            return true; // On error, allow the alert
        }
    }

    /**
     * Get configuration from app_settings (with caching)
     *
     * ENTERPRISE GALAXY: Uses invalidation timestamp pattern (same as JS Errors DB Filter)
     * This ensures cache is refreshed when admin changes settings.
     */
    private static function getConfig(): array
    {
        $now = microtime(true);

        // ENTERPRISE: Check if there's an invalidation timestamp NEWER than our cache
        // This pattern ensures changes from admin panel propagate immediately
        try {
            $redis = self::getRedis();
            if ($redis && self::$configCache !== null) {
                $invalidationTs = (float) $redis->get(self::INVALIDATION_TIMESTAMP_KEY);
                if ($invalidationTs > 0 && $invalidationTs > self::$configLoadedAt) {
                    // Cache was invalidated after we loaded it - force reload
                    self::$configCache = null;
                    self::$configLoadedAt = 0;
                }
            }
        } catch (\Throwable $e) {
            // Continue with normal cache check
        }

        // Check memory cache (if not invalidated above)
        // Using microtime for all timestamps ensures consistent comparison
        if (self::$configCache !== null && ($now - self::$configLoadedAt) < self::CACHE_TTL) {
            return self::$configCache;
        }

        // Try Redis cache
        try {
            $redis = self::getRedis();
            if ($redis) {
                $cached = $redis->get(self::CACHE_KEY);
                if ($cached) {
                    self::$configCache = json_decode($cached, true);
                    self::$configLoadedAt = $now;
                    return self::$configCache;
                }
            }
        } catch (\Throwable $e) {
            // Continue to DB load
        }

        // Load from database
        self::$configCache = self::loadConfigFromDB();
        self::$configLoadedAt = $now;

        // Cache to Redis
        try {
            $redis = self::getRedis();
            if ($redis) {
                $redis->setex(self::CACHE_KEY, self::CACHE_TTL, json_encode(self::$configCache));
            }
        } catch (\Throwable $e) {
            // Ignore cache write failure
        }

        return self::$configCache;
    }

    /**
     * Load configuration from database
     *
     * ENTERPRISE FIX: AutoReleasePDO wrapper doesn't support FETCH_KEY_PAIR correctly,
     * so we manually process the results into a key-value array.
     */
    private static function loadConfigFromDB(): array
    {
        $defaults = [
            'enabled' => true,
            'min_level' => 'error',
            'rate_limit_seconds' => 300,
        ];

        try {
            // Use direct PDO to avoid circular dependency with Logger
            $pdo = db_pdo();

            $stmt = $pdo->prepare(
                "SELECT setting_key, setting_value FROM app_settings
                 WHERE setting_key IN (
                     'telegram_log_alerts_enabled',
                     'telegram_log_alerts_min_level',
                     'telegram_log_alerts_rate_limit_seconds'
                 )"
            );
            $stmt->execute();

            // ENTERPRISE FIX: Manually process results (AutoReleasePDO doesn't support FETCH_KEY_PAIR)
            $rows = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $rows[$row['setting_key']] = $row['setting_value'];
            }

            return [
                'enabled' => filter_var($rows['telegram_log_alerts_enabled'] ?? $defaults['enabled'], FILTER_VALIDATE_BOOLEAN),
                'min_level' => $rows['telegram_log_alerts_min_level'] ?? $defaults['min_level'],
                'rate_limit_seconds' => (int) ($rows['telegram_log_alerts_rate_limit_seconds'] ?? $defaults['rate_limit_seconds']),
            ];

        } catch (\Throwable $e) {
            error_log('[TelegramLogAlert] Config load failed: ' . $e->getMessage());
            return $defaults;
        }
    }

    /**
     * Invalidate config cache (called when admin changes settings)
     *
     * ENTERPRISE GALAXY: Sets invalidation timestamp so all PHP workers
     * know to refresh their caches on next request.
     */
    public static function invalidateConfigCache(): void
    {
        self::$configCache = null;
        self::$configLoadedAt = 0;

        try {
            $redis = self::getRedis();
            if ($redis) {
                // Delete cached config
                $redis->del(self::CACHE_KEY);

                // ENTERPRISE: Set invalidation timestamp (like JS Errors DB Filter)
                // All PHP workers check this timestamp before using their cache
                $redis->set(self::INVALIDATION_TIMESTAMP_KEY, microtime(true));
            }
        } catch (\Throwable $e) {
            // Ignore Redis errors - cache will expire naturally
        }
    }

    /**
     * Get current config (for admin panel display)
     */
    public static function getCurrentConfig(): array
    {
        return self::getConfig();
    }

    /**
     * Update configuration (from admin panel)
     *
     * ENTERPRISE GALAXY: Uses same pattern as Debugbar settings:
     * 1. Update DB with cache invalidation
     * 2. Clear static + Redis cache
     * 3. Repopulate Redis cache immediately with fresh values
     *
     * @param bool $enabled Enable/disable alerts
     * @param string $minLevel Minimum level (warning, error, critical, emergency)
     * @param int $rateLimitSeconds Rate limit in seconds
     * @return bool Success
     */
    public static function updateConfig(bool $enabled, string $minLevel, int $rateLimitSeconds = 300): bool
    {
        // Validate min_level
        $allowedLevels = ['warning', 'error', 'critical', 'emergency'];
        if (!in_array($minLevel, $allowedLevels, true)) {
            return false;
        }

        // Validate rate limit (min 60s, max 3600s)
        $rateLimitSeconds = max(60, min(3600, $rateLimitSeconds));

        try {
            $db = db();

            // Update each setting WITH cache invalidation (like other admin settings)
            $db->execute(
                "UPDATE app_settings SET setting_value = :value WHERE setting_key = 'telegram_log_alerts_enabled'",
                ['value' => $enabled ? 'true' : 'false'],
                ['invalidate_cache' => ['table:app_settings']]
            );
            $db->execute(
                "UPDATE app_settings SET setting_value = :value WHERE setting_key = 'telegram_log_alerts_min_level'",
                ['value' => $minLevel],
                ['invalidate_cache' => ['table:app_settings']]
            );
            $db->execute(
                "UPDATE app_settings SET setting_value = :value WHERE setting_key = 'telegram_log_alerts_rate_limit_seconds'",
                ['value' => (string) $rateLimitSeconds],
                ['invalidate_cache' => ['table:app_settings']]
            );

            // Invalidate cache (sets invalidation timestamp)
            self::invalidateConfigCache();

            // ENTERPRISE GALAXY ULTIMATE: Repopulate Redis cache IMMEDIATELY with fresh values
            // This prevents race condition where next request reads empty cache before DB repopulates
            // (Same pattern used by Debugbar settings)
            try {
                $redis = self::getRedis();
                if ($redis) {
                    $freshConfig = [
                        'enabled' => $enabled,
                        'min_level' => $minLevel,
                        'rate_limit_seconds' => $rateLimitSeconds,
                    ];

                    // Cache with standard TTL
                    $redis->setex(self::CACHE_KEY, self::CACHE_TTL, json_encode($freshConfig));

                    // Update static cache too
                    self::$configCache = $freshConfig;
                    self::$configLoadedAt = microtime(true);
                }
            } catch (\Throwable $e) {
                // Redis repopulation failed - cache will reload from DB on next request
                error_log('[TelegramLogAlert] Redis cache repopulation failed: ' . $e->getMessage());
            }

            return true;

        } catch (\Throwable $e) {
            error_log('[TelegramLogAlert] Update config failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get queue length (for monitoring)
     */
    public static function getQueueLength(): int
    {
        try {
            $redis = self::getRedis();
            if (!$redis) {
                return 0;
            }

            return (int) $redis->lLen(self::REDIS_QUEUE_KEY);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Test alert (sends a test message)
     */
    public static function sendTestAlert(): bool
    {
        return TelegramNotificationService::sendAdmin(
            "✅ *Telegram Log Alerts Test*\n\n" .
            "Configuration:\n" .
            "├ Enabled: " . (self::getConfig()['enabled'] ? 'Yes' : 'No') . "\n" .
            "├ Min Level: " . self::getConfig()['min_level'] . "\n" .
            "└ Rate Limit: " . self::getConfig()['rate_limit_seconds'] . "s\n\n" .
            "🎯 Log alerts are working correctly!",
            [],
            false,
            'log_alert_test'
        );
    }

    /**
     * Get Redis connection
     */
    private static function getRedis(): ?\Redis
    {
        try {
            $manager = EnterpriseRedisManager::getInstance();
            return $manager->getConnection('L1_cache');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Format context for display (truncated)
     */
    private static function formatContext(array $context, int $maxLength = 500): string
    {
        // Remove internal diagnostic data
        unset($context['_logger_diagnostic']);

        if (empty($context)) {
            return '';
        }

        $lines = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            }

            $line = "{$key}: {$value}";
            $lines[] = $line;
        }

        $result = implode("\n", $lines);

        // Truncate if too long
        if (strlen($result) > $maxLength) {
            $result = substr($result, 0, $maxLength) . "\n... (truncated)";
        }

        return $result;
    }
}
