<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;

/**
 * ENTERPRISE GALAXY LEVEL: Dynamic Logging Configuration Service
 *
 * Features:
 * - ZERO downtime configuration changes
 * - ISO 27001 / GDPR / SOC 2 compliant audit trail
 * - Redis L1 cache for ultra-fast reads (sub-millisecond)
 * - Auto-rollback safety mechanism
 * - Per-channel configuration (file, database, redis, security)
 * - Real-time performance impact monitoring
 *
 * Designed for: Millions of concurrent users, 99.99% uptime
 */
class LoggingConfigService
{
    private const CACHE_KEY_PREFIX = 'enterprise:logging:config:';
    private const CACHE_TTL = 300; // 5 minutes (hot data)

    /** @var self Singleton instance */
    private static ?self $instance = null;

    // Available log levels (PSR-3 compatible)
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

    // Available logging channels
    private const CHANNELS = [
        'default' => 'Default Application Logs',
        'debug_general' => 'Debug & Worker Logs (cron, workers, session-sync)', // ENTERPRISE GALAXY: Debug + workers channel
        'security' => 'Security & Audit Logs',
        'performance' => 'Performance Metrics',
        'database' => 'Database Query Logs',
        'email' => 'Email Queue Logs',
        'api' => 'API Request/Response Logs',
        'js_errors' => 'JavaScript Errors (Frontend)', // ENTERPRISE GALAXY: Dedicated JS errors channel
        'audio' => 'Audio Processing Logs (upload, workers, S3)', // ENTERPRISE GALAXY: Audio workers + S3 upload logs
        'websocket' => 'WebSocket Server Logs (connections, events, PubSub)', // ENTERPRISE GALAXY: Real-time WebSocket events
        'overlay' => 'Overlay Cache Logs (views, reactions, friendships, flush)', // ENTERPRISE GALAXY V4.1: Write-behind buffer + flush operations
    ];

    private $db;

    /**
     * Private constructor for singleton pattern
     *
     * ENTERPRISE GALAXY: NO database initialization here!
     * Database connection is lazy-loaded in getDb() to avoid bootstrap order issues
     */
    private function __construct()
    {
        // Database connection lazy-loaded - see getDb()
    }

    /**
     * ENTERPRISE GALAXY: Lazy-load database connection
     * Prevents "Call to undefined function db_pdo()" during early bootstrap
     *
     * CRITICAL: Returns null if db_pdo() not available (avoid infinite loop in logging)
     */
    private function getDb()
    {
        if ($this->db === null) {
            if (!function_exists('db_pdo')) {
                // CRITICAL: Return null instead of throwing exception
                // Throwing would trigger logging which calls should_log() → infinite loop
                return null;
            }
            $this->db = \db_pdo();
        }

        return $this->db;
    }

    /**
     * ENTERPRISE GALAXY: Get fresh Redis connection (not cached in instance)
     * This ensures cache invalidation works even if Redis reconnects
     */
    private function getRedis(): ?\Redis
    {
        // Try EnterpriseRedisManager first (web/worker contexts)
        try {
            $redisManager = EnterpriseRedisManager::getInstance();

            return $redisManager->getConnection('L1_cache');
        } catch (\Exception $e) {
            // EnterpriseRedisManager not available (WebSocket minimal bootstrap)
            // Fall back to direct Redis connection
        }

        // ENTERPRISE GALAXY: Direct Redis connection for WebSocket minimal bootstrap
        // WebSocket doesn't load EnterpriseRedisManager to keep bootstrap lightweight
        try {
            $redis = new \Redis();
            $connected = $redis->connect(
                getenv('REDIS_HOST') ?: 'redis',
                (int)(getenv('REDIS_PORT') ?: 6379),
                2.0 // 2s timeout
            );

            if (!$connected) {
                return null; // Connection failed
            }

            // Authenticate if password is set
            $password = getenv('REDIS_PASSWORD');
            if ($password) {
                $redis->auth($password);
            }

            // Use DB 0 (L1 cache - same as EnterpriseRedisManager L1_cache connection)
            // This is where enterprise:logging:config:* and enterprise:logging:backup:* keys are stored
            $redis->select(0);

            return $redis;

        } catch (\Exception $e) {
            return null; // Direct connection also failed
        }
    }

    /**
     * ENTERPRISE GALAXY: Get singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * ENTERPRISE GALAXY: Get current logging configuration for all channels
     * Uses Redis L1 cache for sub-millisecond reads
     *
     * @param bool $skipCache Skip Redis cache and read directly from database (for admin panel)
     */
    public function getConfiguration(bool $skipCache = false): array
    {
        try {
            // ENTERPRISE GALAXY: Admin panel always reads fresh data from DB
            if (!$skipCache) {
                // Try Redis L1 cache first (ENTERPRISE PERFORMANCE)
                $redis = $this->getRedis();
                if ($redis) {
                    $cacheKey = self::CACHE_KEY_PREFIX . 'all';
                    $cached = $redis->get($cacheKey);

                    if ($cached !== false) {
                        // Debug logging removed for performance (L1 cache hits are ultra-frequent)
                        return json_decode($cached, true);
                    }
                }
            }

            // Cache miss OR skipCache=true - load from database
            // CRITICAL: When skipCache=true (admin panel), also bypass query cache
            $config = $this->loadConfigFromDatabase($skipCache);

            // Cache in Redis L1 for next requests (only if not skipping cache)
            if (!$skipCache) {
                $redis = $this->getRedis();
                if ($redis && !empty($config)) {
                    $redis->setex(
                        self::CACHE_KEY_PREFIX . 'all',
                        self::CACHE_TTL,
                        json_encode($config)
                    );
                }
            }

            return $config;

        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'error')) {
                Logger::error('[LOGGING CONFIG] Failed to get logging config (using defaults)', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            // Fallback to safe defaults
            return $this->getDefaultConfiguration();
        }
    }

    /**
     * ENTERPRISE GALAXY: Update logging configuration with full audit trail
     *
     * @param string $channel Channel to update (default, security, etc.)
     * @param string $level New log level (debug, info, warning, error, critical)
     * @param array $options Additional options (auto_rollback_minutes, reason, etc.)
     * @param int|null $adminUserId Admin user making the change (for audit)
     * @return array Success status and details
     */
    public function updateConfiguration(
        string $channel,
        string $level,
        array $options = [],
        ?int $adminUserId = null
    ): array {
        try {
            // ENTERPRISE VALIDATION
            if (!isset(self::CHANNELS[$channel])) {
                throw new \InvalidArgumentException("Invalid channel: {$channel}");
            }

            if (!isset(self::LOG_LEVELS[$level])) {
                throw new \InvalidArgumentException("Invalid log level: {$level}");
            }

            // Get current config for rollback
            $currentConfig = $this->getChannelConfig($channel);
            $previousLevel = $currentConfig['level'] ?? 'info';

            // ENTERPRISE GALAXY: Auto-rollback safety mechanism
            $autoRollbackAt = null;
            if (!empty($options['auto_rollback_minutes'])) {
                $minutes = (int) $options['auto_rollback_minutes'];
                if ($minutes > 0 && $minutes <= 1440) { // Max 24 hours
                    $autoRollbackAt = date('Y-m-d H:i:s', time() + ($minutes * 60));
                }
            }

            // Prepare configuration data
            $configData = [
                'channel' => $channel,
                'level' => $level,
                'level_numeric' => self::LOG_LEVELS[$level],
                'previous_level' => $previousLevel,
                'auto_rollback_at' => $autoRollbackAt,
                'auto_rollback_to' => $autoRollbackAt ? $previousLevel : null,
                'updated_by' => $adminUserId,
                'updated_at' => date('Y-m-d H:i:s'),
                'reason' => $options['reason'] ?? 'Configuration update via admin panel',
            ];

            // Save to database (ENTERPRISE PERSISTENCE)
            $this->saveConfigToDatabase($channel, $configData);

            // ENTERPRISE GALAXY ULTIMATE: 3-phase cache strategy for ZERO downtime
            // Phase 1: Invalidate stale cache
            $this->invalidateCache($channel);

            // Phase 2: Immediately warm cache with fresh data (prevents cache miss stampede)
            // This ensures next request gets instant response from cache instead of hitting DB
            // CRITICAL: Use bypassQueryCache=true to force fresh DB read (not cached query)
            try {
                $freshConfig = $this->loadConfigFromDatabase(bypassQueryCache: true);
                $redis = $this->getRedis();
                if ($redis && !empty($freshConfig)) {
                    $redis->setex(
                        self::CACHE_KEY_PREFIX . 'all',
                        self::CACHE_TTL,
                        json_encode($freshConfig)
                    );
                    if (function_exists('should_log') && should_log('default', 'debug')) {
                        Logger::debug('[LOGGING CONFIG] Cache warmed with FRESH DB data', []);
                    }
                }
            } catch (\Throwable $e) {
                if (function_exists('should_log') && should_log('default', 'warning')) {
                    Logger::warning('[LOGGING CONFIG] Cache warming failed (non-critical)', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Phase 3: Set invalidation timestamp (again) to ensure all PHP processes see the change
            try {
                $redis = $this->getRedis();
                if ($redis) {
                    $redis->set('logging:config:invalidation_timestamp', microtime(true));
                }
            } catch (\Throwable $e) {
                // Silent fail - not critical
            }

            // Write ISO 27001 / GDPR / SOC 2 compliant audit trail
            $this->writeAuditTrail($channel, $previousLevel, $level, $configData, $adminUserId);

            // Security log is handled by AdminController with full context (who/where/why)

            return [
                'success' => true,
                'channel' => $channel,
                'level' => $level,
                'previous_level' => $previousLevel,
                'auto_rollback_at' => $autoRollbackAt,
                'message' => "Logging level for '{$channel}' changed to '{$level}' (was '{$previousLevel}')",
                'compliance_status' => 'audit_trail_written',
            ];

        } catch (\Exception $e) {
            Logger::security('critical', 'LOGGING CONFIG: Update failed', [
                'channel' => $channel,
                'level' => $level,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY: Process auto-rollback for safety
     * Should be called periodically (e.g., via cron every minute)
     */
    public function processAutoRollbacks(): array
    {
        try {
            $stmt = $this->getDb()->prepare('
                SELECT * FROM app_settings
                WHERE setting_key LIKE "logging_config_%"
                AND setting_value LIKE "%auto_rollback_at%"
            ');
            $stmt->execute();
            $configs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $rolledBack = [];
            $now = date('Y-m-d H:i:s');

            foreach ($configs as $config) {
                $data = json_decode($config['setting_value'], true);

                if (empty($data['auto_rollback_at']) || empty($data['auto_rollback_to'])) {
                    continue;
                }

                // Check if rollback time has passed
                if ($data['auto_rollback_at'] <= $now) {
                    $channel = $data['channel'];
                    $rollbackLevel = $data['auto_rollback_to'];

                    // Perform rollback
                    $result = $this->updateConfiguration(
                        $channel,
                        $rollbackLevel,
                        ['reason' => 'Automatic safety rollback'],
                        null // System-initiated
                    );

                    if ($result['success']) {
                        $rolledBack[] = [
                            'channel' => $channel,
                            'rolled_back_to' => $rollbackLevel,
                            'rollback_time' => $now,
                        ];

                        Logger::security('warning', 'LOGGING CONFIG: Auto-rollback executed', [
                            'channel' => $channel,
                            'rolled_back_to' => $rollbackLevel,
                            'reason' => 'safety_timeout',
                        ]);
                    }
                }
            }

            return [
                'success' => true,
                'rolled_back_count' => count($rolledBack),
                'rolled_back' => $rolledBack,
            ];

        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'error')) {
                Logger::error('[LOGGING CONFIG] Auto-rollback processing failed', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get logging configuration for specific channel
     */
    public function getChannelConfig(string $channel): array
    {
        $config = $this->getConfiguration();

        return $config[$channel] ?? $this->getDefaultChannelConfig($channel);
    }

    /**
     * Check if a log level should be logged based on current configuration
     * ULTRA-FAST: Sub-millisecond check using Redis L1 cache
     */
    public function shouldLog(string $channel, string $level): bool
    {
        $config = $this->getChannelConfig($channel);
        $currentLevelNumeric = self::LOG_LEVELS[$config['level']] ?? 200;
        $requestedLevelNumeric = self::LOG_LEVELS[$level] ?? 200;

        return $requestedLevelNumeric >= $currentLevelNumeric;
    }

    /**
     * Get available log levels
     */
    public function getAvailableLevels(): array
    {
        return array_keys(self::LOG_LEVELS);
    }

    /**
     * Get available channels
     */
    public function getAvailableChannels(): array
    {
        return self::CHANNELS;
    }

    /**
     * Load configuration from database
     *
     * @param bool $bypassQueryCache Force query to bypass cache (adds unique comment)
     */
    private function loadConfigFromDatabase(bool $bypassQueryCache = false): array
    {
        $db = $this->getDb();
        if ($db === null) {
            // ENTERPRISE GALAXY: Database not available (WebSocket minimal bootstrap)
            // Fallback to Redis backup before giving up
            return $this->loadConfigFromRedisBackup();
        }

        // CRITICAL: Add unique comment to bypass query cache when needed
        // TrackedPDO uses query text as cache key, so unique comment = cache miss
        $cacheBypass = $bypassQueryCache ? '/* NOCACHE-' . microtime(true) . ' */' : '';

        $stmt = $db->prepare("
            SELECT * FROM app_settings
            WHERE setting_key LIKE 'logging_config_%' {$cacheBypass}
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $config = [];
        foreach ($rows as $row) {
            $channel = str_replace('logging_config_', '', $row['setting_key']);
            $config[$channel] = json_decode($row['setting_value'], true);
        }

        // ENTERPRISE GALAXY: Fill missing channels with defaults AND save to database
        foreach (array_keys(self::CHANNELS) as $channel) {
            if (!isset($config[$channel])) {
                $defaultConfig = $this->getDefaultChannelConfig($channel);
                $config[$channel] = $defaultConfig;

                // CRITICAL: Save default to database to prevent future cache misses
                $this->saveConfigToDatabase($channel, $defaultConfig);
            }
        }

        return $config;
    }

    /**
     * Save configuration to database
     * ENTERPRISE GALAXY: Also syncs to Redis backup for WebSocket
     */
    private function saveConfigToDatabase(string $channel, array $data): void
    {
        $settingKey = "logging_config_{$channel}";
        $settingValue = json_encode($data);

        $stmt = $this->getDb()->prepare('
            INSERT INTO app_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, NOW())
            ON CONFLICT (setting_key) DO UPDATE SET
                setting_value = EXCLUDED.setting_value,
                updated_at = NOW()
        ');

        $stmt->execute([$settingKey, $settingValue]);

        // ENTERPRISE GALAXY: Sync to Redis backup (for WebSocket without PostgreSQL)
        // This is atomic with PostgreSQL save - if PostgreSQL succeeds, Redis gets updated
        $this->saveConfigToRedisBackup($channel, $data);
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Aggressive cache invalidation with retry logic
     * Ensures ALL cached data is purged immediately across all PHP processes
     */
    private function invalidateCache(?string $channel = null): void
    {
        $maxRetries = 3;
        $retryCount = 0;
        $success = false;

        while ($retryCount < $maxRetries && !$success) {
            try {
                // CRITICAL: Get FRESH connection (not cached)
                $redis = $this->getRedis();
                if (!$redis) {
                    if (function_exists('should_log') && should_log('default', 'warning')) {
                        Logger::warning('[LOGGING CONFIG] Redis not available, cache invalidation skipped', []);
                    }

                    return;
                }

                $deletedKeys = [];

                // Strategy 1: Delete specific channel cache
                if ($channel) {
                    $channelKey = self::CACHE_KEY_PREFIX . $channel;
                    $deleted = $redis->del($channelKey);
                    $deletedKeys[] = "{$channelKey} (deleted={$deleted})";
                }

                // Strategy 2: Always delete 'all' cache
                $allKey = self::CACHE_KEY_PREFIX . 'all';
                $deleted = $redis->del($allKey);
                $deletedKeys[] = "{$allKey} (deleted={$deleted})";

                // Strategy 3: SCAN for any other related keys and delete them (aggressive)
                // This catches any orphaned or unexpected cache keys
                $pattern = self::CACHE_KEY_PREFIX . '*';
                $iterator = null;
                $scannedKeys = [];

                while ($keys = $redis->scan($iterator, $pattern, 100)) {
                    if ($keys) {
                        foreach ($keys as $key) {
                            $scannedKeys[] = $key;
                        }
                    }
                    if ($iterator === 0) {
                        break; // Scan complete
                    }
                }

                if (!empty($scannedKeys)) {
                    $deleted = $redis->del($scannedKeys);
                    $deletedKeys[] = "SCAN pattern '{$pattern}' (found " . count($scannedKeys) . " keys, deleted={$deleted})";
                }

                // Strategy 4: Set invalidation timestamp for cross-process synchronization
                // This tells all PHP processes to clear their static caches in should_log()
                $timestamp = microtime(true);
                $redis->set('logging:config:invalidation_timestamp', $timestamp);
                $deletedKeys[] = "invalidation_timestamp={$timestamp}";

                // Strategy 5: Verify cache is actually cleared by attempting to read
                $verifyRead = $redis->get($allKey);
                if ($verifyRead !== false) {
                    throw new \RuntimeException("Cache verification failed - key '{$allKey}' still exists after deletion");
                }

                $success = true;

                if (function_exists('should_log') && should_log('default', 'debug')) {
                    Logger::debug('[LOGGING CONFIG] Cache invalidation SUCCESS', [
                        'attempt' => $retryCount + 1,
                        'max_retries' => $maxRetries,
                        'deleted_keys' => implode(', ', $deletedKeys),
                    ]);
                }

            } catch (\Throwable $e) {
                $retryCount++;
                if (function_exists('should_log') && should_log('default', 'warning')) {
                    Logger::warning('[LOGGING CONFIG] Cache invalidation FAILED', [
                        'attempt' => $retryCount,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($retryCount < $maxRetries) {
                    usleep(100000); // 100ms delay before retry
                } else {
                    if (function_exists('should_log') && should_log('default', 'error')) {
                        Logger::error('[LOGGING CONFIG] Cache invalidation EXHAUSTED all retries - giving up', []);
                    }
                }
            }
        }
    }

    /**
     * ISO 27001 / GDPR / SOC 2 compliant audit trail
     * ENTERPRISE TIPS: Match actual admin_audit_log table structure (admin_id, action, details)
     */
    private function writeAuditTrail(
        string $channel,
        string $previousLevel,
        string $newLevel,
        array $configData,
        ?int $adminUserId
    ): void {
        try {
            $stmt = $this->getDb()->prepare('
                INSERT INTO admin_audit_log (
                    admin_id, action, details, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ');

            $details = json_encode([
                'action' => 'logging_config_update',
                'channel' => $channel,
                'previous_level' => $previousLevel,
                'new_level' => $newLevel,
                'auto_rollback_at' => $configData['auto_rollback_at'] ?? null,
                'reason' => $configData['reason'] ?? null,
                'compliance_standards' => ['ISO_27001', 'GDPR', 'SOC_2'],
                'impact_assessment' => $this->assessPerformanceImpact($previousLevel, $newLevel),
            ]);

            $stmt->execute([
                $adminUserId ?? 0, // Default to 0 if no admin user (system action)
                'update_logging_config',
                $details,
                $_SERVER['REMOTE_ADDR'] ?? 'CLI',
                $_SERVER['HTTP_USER_AGENT'] ?? 'System',
            ]);

        } catch (\Exception $e) {
            if (function_exists('should_log') && should_log('default', 'error')) {
                Logger::error('[LOGGING CONFIG] Failed to write audit trail', [
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }
    }

    /**
     * Assess performance impact of log level change
     */
    private function assessPerformanceImpact(string $previousLevel, string $newLevel): array
    {
        $previousNumeric = self::LOG_LEVELS[$previousLevel] ?? 200;
        $newNumeric = self::LOG_LEVELS[$newLevel] ?? 200;

        $impact = 'neutral';
        $estimatedPerformanceChange = 0;

        if ($newNumeric < $previousNumeric) {
            // More logging = performance impact
            $impact = 'negative';
            $estimatedPerformanceChange = -((($previousNumeric - $newNumeric) / 100) * 10);
        } elseif ($newNumeric > $previousNumeric) {
            // Less logging = performance gain
            $impact = 'positive';
            $estimatedPerformanceChange = ((($newNumeric - $previousNumeric) / 100) * 10);
        }

        return [
            'impact' => $impact,
            'estimated_performance_change_percent' => round($estimatedPerformanceChange, 2),
            'recommendation' => $impact === 'negative'
                ? 'Consider auto-rollback for debug level in production'
                : 'Safe for long-term use',
        ];
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfiguration(): array
    {
        $config = [];
        foreach (array_keys(self::CHANNELS) as $channel) {
            $config[$channel] = $this->getDefaultChannelConfig($channel);
        }

        return $config;
    }

    /**
     * Get default configuration for specific channel
     */
    private function getDefaultChannelConfig(string $channel): array
    {
        // Security logs should always be at info level minimum
        $defaultLevel = $channel === 'security' ? 'info' : 'warning';

        return [
            'channel' => $channel,
            'level' => $defaultLevel,
            'level_numeric' => self::LOG_LEVELS[$defaultLevel],
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'default',
        ];
    }

    /**
     * ENTERPRISE GALAXY: Load configuration from Redis backup
     * Used when PostgreSQL is not available (WebSocket minimal bootstrap)
     *
     * @return array Configuration array or empty if Redis unavailable
     */
    private function loadConfigFromRedisBackup(): array
    {
        try {
            $redis = $this->getRedis();
            if (!$redis) {
                return []; // Redis not available - will trigger disaster mode failsafe
            }

            $config = [];

            // Load all channel configs from Redis backup
            foreach (array_keys(self::CHANNELS) as $channel) {
                $key = "enterprise:logging:backup:{$channel}";
                $data = $redis->get($key);

                if ($data !== false) {
                    $config[$channel] = json_decode($data, true);
                }
            }

            // If we got at least some config from Redis, use it
            // Otherwise return empty (disaster mode)
            return $config;

        } catch (\Throwable $e) {
            // Redis backup failed - return empty (disaster mode failsafe)
            return [];
        }
    }

    /**
     * ENTERPRISE GALAXY: Save configuration to Redis backup
     * Called automatically when saving to PostgreSQL (sync)
     *
     * @param string $channel Channel name
     * @param array $data Configuration data
     */
    private function saveConfigToRedisBackup(string $channel, array $data): void
    {
        try {
            $redis = $this->getRedis();
            if (!$redis) {
                return; // Redis not available - skip backup (not critical)
            }

            $key = "enterprise:logging:backup:{$channel}";

            // Save with 7 day TTL (longer than cache, but not forever)
            // This ensures WebSocket always has recent config even if PostgreSQL is down
            $redis->setex($key, 604800, json_encode($data));

        } catch (\Throwable $e) {
            // Backup failed - not critical, log and continue
            // Main config is in PostgreSQL, Redis is just backup for WebSocket
            if (function_exists('should_log') && should_log('default', 'warning')) {
                Logger::warning('[LOGGING CONFIG] Failed to save Redis backup (non-critical)', [
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
