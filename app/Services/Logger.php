<?php

namespace Need2Talk\Services;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Need2Talk\Traits\EnterpriseRedisSafety;

/**
 * Comprehensive Logging Service - need2talk
 *
 * Sistema unificato per tutti i tipi di log:
 * - Errori applicazione
 * - Errori PHP
 * - Log richieste HTTP
 * - Log WebSocket
 * - Log sicurezza
 * - Log performance
 */
class Logger
{
    use EnterpriseRedisSafety;

    private static array $loggers = [];

    private static string $logPath;

    private static ?self $instance = null;

    // ENTERPRISE: Rate limiting e deduplicazione
    private static array $rateLimitCache = [];

    private static array $deduplicationCache = [];

    private static int $rateLimitWindow = 60; // 60 secondi

    private static int $maxLogPerMinute = 10; // Max 10 log identici per minuto

    // ENTERPRISE: Redis L3 cache for high-volume logging
    private static ?\Redis $redisL3 = null;

    private static array $logBuffer = [];

    private static int $bufferSize = 10; // Buffer 10 logs before flush (ENTERPRISE GALAXY - Optimized for high concurrency)

    private static int $lastFlush = 0;

    private static int $flushInterval = 5; // Flush every 5 seconds minimum

    /**
     * Inizializza sistema logging
     */
    public static function init(): void
    {
        self::$logPath = APP_ROOT . '/storage/logs';

        // Crea directory se non esiste
        if (!is_dir(self::$logPath)) {
            mkdir(self::$logPath, 0755, true);
        }

        // Inizializza logger specifici
        self::initErrorLogger();
        self::initRequestLogger();
        self::initWebSocketLogger();
        self::initSecurityLogger();
        self::initPerformanceLogger();
        self::initPhpErrorLogger();
        self::initDebugGeneralLogger();

        // ENTERPRISE: Initialize channel-based loggers for admin panel (8 channels)
        self::initEmailLogger();
        self::initAudioLogger();
        self::initApiLogger();
        self::initDatabaseLogger();
        self::initJsErrorLogger(); // ENTERPRISE GALAXY: JavaScript error logging
        self::initOverlayLogger(); // ENTERPRISE GALAXY V4.1: Overlay cache flush logging

        // ENTERPRISE: Initialize Redis L3 for high-volume logging
        self::initRedisL3();

        // Configura PHP error handler
        self::configurePHPErrorHandler();

        // ENTERPRISE: Register shutdown function for buffer flush
        register_shutdown_function([self::class, 'flushLogBuffer']);
    }

    /**
     * ENTERPRISE: Flush log buffer to files and Redis backup
     * PERFORMANCE FIX: Also cleanup rate limit and deduplication caches
     */
    public static function flushLogBuffer(): void
    {
        // PERFORMANCE FIX: Aggressive cache cleanup to prevent memory leaks
        self::performAggressiveCacheCleanup();

        if (empty(self::$logBuffer)) {
            return;
        }

        $bufferedLogs = self::$logBuffer;
        self::$logBuffer = []; // Clear buffer immediately

        // Backup to Redis L3 first (high availability)
        if (self::$redisL3) {
            try {
                foreach ($bufferedLogs as $logEntry) {
                    (new static())->safeRedisCall(self::$redisL3, 'rPush', ['logger:backup:' . date('Y-m-d'), json_encode($logEntry)]);
                }

                // Set TTL on backup (7 days)
                (new static())->safeRedisCall(self::$redisL3, 'expire', ['logger:backup:' . date('Y-m-d'), 604800]);

            } catch (\Exception $e) {
                // ENTERPRISE: Safe logging during bootstrap with should_log check
                if (function_exists('should_log') && should_log('default', 'debug')) {
                    error_log('[ENTERPRISE LOGGER] Redis backup failed: ' . $e->getMessage());
                }
            }
        }

        // Write to files
        foreach ($bufferedLogs as $logEntry) {
            $loggerName = $logEntry['logger'] ?? 'debug_general';
            $level = $logEntry['level'] ?? 'info';
            $message = $logEntry['message'] ?? '';
            $context = $logEntry['context'] ?? [];

            if (isset(self::$loggers[$loggerName])) {
                self::$loggers[$loggerName]->log($level, $message, $context);
            }
        }

        self::$lastFlush = time();
    }

    /**
     * ENTERPRISE: Log errore applicazione con spam protection
     */
    public static function error(string $message, $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if error logging enabled
        if (function_exists('should_log') && !should_log('default', 'error')) {
            return; // Exit immediately - zero overhead
        }

        // ENTERPRISE: Blocca spam messages
        if (self::shouldBlockSpamMessage($message)) {
            return;
        }

        // ENTERPRISE: Rate limiting e deduplicazione
        if (!self::shouldLogWithRateLimit($message, 'error')
            || !self::shouldLogWithDeduplication($message, 'error')) {
            return;
        }

        // ENTERPRISE TYPE SAFETY: Auto-correct common mistakes
        $context = self::normalizeContext($context, 'error');

        self::$loggers['error']?->error($message, array_merge($context, [
            'request_id' => self::getRequestId(),
            'user_id' => self::getCurrentUserId(),
            'ip' => self::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]));

        // ENTERPRISE GALAXY: Telegram alert (async, rate-limited)
        self::queueTelegramAlert('error', 'default', $message, $context);
    }

    /**
     * Log warning - ENTERPRISE TYPE SAFETY
     */
    public static function warning(string $message, $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if warning logging enabled
        if (function_exists('should_log') && !should_log('default', 'warning')) {
            return; // Exit immediately - zero overhead
        }

        // ENTERPRISE TYPE SAFETY: Auto-correct common mistakes
        $context = self::normalizeContext($context, 'warning');

        self::$loggers['error']?->warning($message, array_merge($context, [
            'request_id' => self::getRequestId(),
        ]));

        // ENTERPRISE GALAXY: Telegram alert (async, rate-limited)
        self::queueTelegramAlert('warning', 'default', $message, $context);
    }

    /**
     * Log info - ENTERPRISE TYPE SAFETY
     */
    public static function info(string $message, $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if info logging enabled (debug_general channel)
        if (function_exists('should_log') && !should_log('debug_general', 'info')) {
            return; // Exit immediately - zero overhead
        }

        // ENTERPRISE TYPE SAFETY: Auto-correct common mistakes
        $context = self::normalizeContext($context, 'info');

        // Ensure loggers are initialized
        if (empty(self::$loggers)) {
            self::init();
        }

        // Use debug_general logger instead of error logger for info messages
        if (!isset(self::$loggers['debug_general'])) {
            error_log('[LOGGER DEBUG] debug_general logger not found, reinitializing...');
            self::init();
        }

        if (self::$loggers['debug_general']) {
            self::$loggers['debug_general']->info($message, array_merge($context, [
                'request_id' => self::getRequestId(),
            ]));
        } else {
            error_log("[LOGGER DEBUG] Failed to initialize debug_general logger for message: $message");
        }
    }

    /**
     * Log debug - ENTERPRISE TYPE SAFETY with correct logger
     */
    public static function debug(string $message, $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if debug logging enabled (debug_general channel)
        if (function_exists('should_log') && !should_log('debug_general', 'debug')) {
            return; // Exit immediately - zero overhead
        }

        // ENTERPRISE: Always log debug in development AND write to debug_general
        $context = self::normalizeContext($context, 'debug');

        if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
            // Use correct debug_general logger instead of error logger
            self::$loggers['debug_general']?->debug($message, array_merge($context, [
                'request_id' => self::getRequestId(),
                'user_id' => self::getCurrentUserId(),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]));
        }
    }

    /**
     * Log notice - ENTERPRISE TYPE SAFETY
     * Significant but non-critical events
     */
    public static function notice(string $message, $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if notice logging enabled
        // Notice belongs to debug_general channel (debug/info/notice)
        if (function_exists('should_log') && !should_log('debug_general', 'notice')) {
            return; // Exit immediately - zero overhead
        }

        // ENTERPRISE TYPE SAFETY: Auto-correct common mistakes
        $context = self::normalizeContext($context, 'notice');

        self::$loggers['debug_general']?->notice($message, array_merge($context, [
            'request_id' => self::getRequestId(),
        ]));
    }

    /**
     * Log critical - Enterprise level for system-threatening errors
     */
    public static function critical(string $message, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if critical logging enabled
        if (function_exists('should_log') && !should_log('default', 'critical')) {
            return; // Exit immediately - zero overhead
        }

        self::$loggers['error']?->critical($message, array_merge($context, [
            'request_id' => self::getRequestId(),
            'severity' => 'CRITICAL',
            'alert_required' => true,
        ]));

        // Enterprise: Log critical errors also to security log
        self::security('critical', 'critical_error', array_merge($context, [
            'message' => $message,
            'timestamp' => time(),
            'alert_level' => 'IMMEDIATE',
        ]));

        // ENTERPRISE GALAXY: Telegram alert (async, rate-limited)
        self::queueTelegramAlert('critical', 'default', $message, $context);
    }

    /**
     * Log alert - Enterprise level for immediate action required
     * System must be attended to immediately
     */
    public static function alert(string $message, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if alert logging enabled
        if (function_exists('should_log') && !should_log('default', 'alert')) {
            return; // Exit immediately - zero overhead
        }

        self::$loggers['error']?->alert($message, array_merge($context, [
            'request_id' => self::getRequestId(),
            'severity' => 'ALERT',
            'alert_required' => true,
            'escalation_level' => 'IMMEDIATE',
        ]));

        // Enterprise: Log alerts also to security log
        self::security('alert', 'alert_triggered', array_merge($context, [
            'message' => $message,
            'timestamp' => time(),
            'alert_level' => 'URGENT',
            'requires_action' => true,
        ]));

        // ENTERPRISE GALAXY: Telegram alert (async, rate-limited)
        self::queueTelegramAlert('alert', 'default', $message, $context);
    }

    /**
     * Log emergency - Enterprise level for system unusable
     * Highest severity - system is down or completely unusable
     */
    public static function emergency(string $message, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if emergency logging enabled
        if (function_exists('should_log') && !should_log('default', 'emergency')) {
            return; // Exit immediately - zero overhead
        }

        self::$loggers['error']?->emergency($message, array_merge($context, [
            'request_id' => self::getRequestId(),
            'severity' => 'EMERGENCY',
            'alert_required' => true,
            'escalation_level' => 'CRITICAL',
            'system_status' => 'UNUSABLE',
        ]));

        // Enterprise: Emergency logs also to security with highest priority
        self::security('emergency', 'emergency_alert', array_merge($context, [
            'message' => $message,
            'timestamp' => time(),
            'alert_level' => 'EMERGENCY',
            'requires_immediate_action' => true,
            'system_down' => true,
        ]));

        // ENTERPRISE GALAXY: Telegram alert (async, rate-limited)
        self::queueTelegramAlert('emergency', 'default', $message, $context);

        // Enterprise: Force immediate flush of log buffer
        self::flushLogBuffer();
    }

    /**
     * ENTERPRISE: Logger debug GENERALE per ogni avvenimento - High Volume Optimized
     */
    public static function debugGeneral(string $event, string $message, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if debug logging enabled (zero overhead if disabled)
        if (function_exists('should_log') && !should_log('default', 'debug')) {
            return; // Exit immediately - zero overhead
        }

        // ENTERPRISE CRITICAL: Immediate fallback if Redis/buffering fails
        try {
            $debugData = array_merge([
                'event' => $event,
                'message' => $message,
                'timestamp' => time(),
                'datetime' => (new \DateTime('now', new \DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s'),
                'request_id' => self::getRequestId(),
                'user_id' => self::getCurrentUserId(),
                'ip' => self::getClientIp(),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'execution_time_ms' => round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 2),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'session_id' => session_id(),
                'server_name' => $_SERVER['SERVER_NAME'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                // ENTERPRISE: Add system health metrics (safe fallback)
                'db_pool_active' => self::getDatabasePoolStatus(),
                'redis_status' => self::getRedisStatus(),
                'system_load' => sys_getloadavg()[0] ?? 0,
            ], $context);

            // ENTERPRISE: Use buffered logging for high volume
            self::bufferLog('debug_general', 'debug', "[{$event}] {$message}", $debugData);

        } catch (\Throwable $e) {
            // ENTERPRISE ULTIMATE FALLBACK: Direct file write, no Redis, no buffer
            $fallbackData = [
                'event' => $event,
                'message' => $message,
                'timestamp' => time(),
                'datetime' => (new \DateTime('now', new \DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s'),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'session_id' => session_id(),
                'fallback_reason' => 'Logger system failed: ' . $e->getMessage(),
            ];

            // Direct file write - cannot fail
            $romeTime = new \DateTime('now', new \DateTimeZone('Europe/Rome'));
            $logPath = defined('APP_ROOT') ? APP_ROOT . '/storage/logs/debug_general-' . $romeTime->format('Y-m-d') . '.log' : '/tmp/debug_fallback.log';
            $logLine = '[' . $romeTime->format('Y-m-d H:i:s') . '] DEBUG_GENERAL: [FALLBACK] ' . $event . ' - ' . $message . ' ' . json_encode($fallbackData) . "\n";
            file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);

            // Also log the original error for debugging
            error_log('[LOGGER FALLBACK] debugGeneral failed, used direct file write. Error: ' . $e->getMessage());
        }
    }

    /**
     * Log richiesta HTTP - ENTERPRISE GALAXY ULTIMATE: Full PSR-3 level support
     *
     * @param string $level Log level (debug, info, notice, warning, error)
     * @param array $requestData Request data
     */
    public static function api(string $level, string $message, array $requestData = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if API logging enabled
        if (function_exists('should_log') && !should_log('api', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $logger = self::$loggers['api'];

        if (!$logger) {
            return;
        }

        $apiContext = array_merge($requestData, [
            'timestamp' => time(),
            'request_id' => self::getRequestId(),
        ]);

        switch (strtolower($level)) {
            case 'error':
                $logger->error($message, $apiContext);
                break;
            case 'warning':
                $logger->warning($message, $apiContext);
                break;
            case 'notice':
                $logger->notice($message, $apiContext);
                break;
            case 'info':
                $logger->info($message, $apiContext);
                break;
            case 'debug':
            default:
                $logger->debug($message, $apiContext);
                break;
        }

        // ENTERPRISE GALAXY: Telegram alert for API events (warning+)
        $telegramLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (in_array(strtolower($level), $telegramLevels, true)) {
            self::queueTelegramAlert($level, 'api', $message, $apiContext);
        }
    }

    /**
     * DEPRECATED: Use Logger::api() instead
     * Kept for backward compatibility
     */
    public static function logRequest(array $requestData): void
    {
        self::api('info', 'HTTP Request', $requestData);
    }

    /**
     * Log WebSocket eventi
     */
    public static function websocket(string $level, string $message, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if websocket logging enabled (use level from parameter)
        if (function_exists('should_log') && !should_log('websocket', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $logger = self::$loggers['websocket'];

        if (!$logger) {
            return;
        }

        switch (strtolower($level)) {
            case 'error':
                $logger->error($message, $context);
                break;
            case 'warning':
                $logger->warning($message, $context);
                break;
            case 'info':
                $logger->info($message, $context);
                break;
            case 'debug':
            default:
                $logger->debug($message, $context);
                break;
        }

        // ENTERPRISE GALAXY: Telegram alert for WebSocket events (warning+)
        $telegramLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (in_array(strtolower($level), $telegramLevels, true)) {
            self::queueTelegramAlert($level, 'websocket', $message, $context);
        }
    }

    /**
     * Log eventi sicurezza - ENTERPRISE GALAXY ULTIMATE: Dual-write (DB + File logs)
     *
     * ENTERPRISE GALAXY: Security events are written to BOTH:
     * 1. Database (security_events table) for real-time monitoring
     * 2. File logs (security.log) for audit trail and compliance
     *
     * @param string $level Log level (debug, info, notice, warning, error, critical, alert, emergency)
     * @param string $event Event name/description
     * @param array $context Additional context
     */
    public static function security(string $level, string $event, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if security logging enabled (zero overhead if disabled)
        if (function_exists('should_log') && !should_log('security', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $logger = self::$loggers['security'];

        if (!$logger) {
            return;
        }

        $securityContext = array_merge($context, [
            'timestamp' => time(),
            'ip' => self::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'user_id' => self::getCurrentUserId(),
            'session_id' => session_id(),
        ]);

        // ENTERPRISE GALAXY: Write to file logs (async via Monolog)
        switch (strtolower($level)) {
            case 'emergency':
                $logger->emergency($event, $securityContext);
                break;
            case 'alert':
                $logger->alert($event, $securityContext);
                break;
            case 'critical':
                $logger->critical($event, $securityContext);
                break;
            case 'error':
                $logger->error($event, $securityContext);
                break;
            case 'warning':
                $logger->warning($event, $securityContext);
                break;
            case 'notice':
                $logger->notice($event, $securityContext);
                break;
            case 'info':
                $logger->info($event, $securityContext);
                break;
            case 'debug':
            default:
                $logger->debug($event, $securityContext);
                break;
        }

        // ENTERPRISE GALAXY: Dual-write to database for real-time admin monitoring
        self::writeSecurityEventToDatabase($level, $event, $securityContext);

        // ENTERPRISE GALAXY: Telegram alert for security events (warning+)
        $telegramLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (in_array(strtolower($level), $telegramLevels, true)) {
            self::queueTelegramAlert($level, 'security', $event, $securityContext);
        }
    }

    /**
     * ENTERPRISE GALAXY: Write security event to database (async, non-blocking)
     * Optimized for millions of concurrent security events
     */
    private static function writeSecurityEventToDatabase(string $level, string $message, array $context): void
    {
        // 🚀 ENTERPRISE GALAXY: Database logging threshold - only WARNING and above
        // File logs still respect channel configuration (can be debug)
        // Database is persistent audit trail - only important events (warning, error, critical, alert, emergency)
        $databaseLevelThreshold = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (!in_array(strtolower($level), $databaseLevelThreshold, true)) {
            return; // Skip debug/info/notice from database (still logged to files)
        }

        try {
            // ENTERPRISE GALAXY: Use connection pool with auto-release
            $pdo = db_pdo();

            // ENTERPRISE GALAXY: Prepare optimized INSERT with index-friendly structure
            $stmt = $pdo->prepare('
                INSERT INTO security_events
                (channel, level, message, context, ip_address, user_agent, user_id, session_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');

            // Extract core fields from context
            $ipAddress = $context['ip'] ?? self::getClientIp();
            $userAgent = $context['user_agent'] ?? null;
            $userId = $context['user_id'] ?? null;
            $sessionId = $context['session_id'] ?? null;

            // Remove redundant fields from JSON context (save space)
            $cleanContext = $context;
            unset($cleanContext['ip'], $cleanContext['user_agent'], $cleanContext['user_id'], $cleanContext['session_id']);

            // ENTERPRISE GALAXY: Execute with error handling (never break main flow)
            $stmt->execute([
                'security', // channel
                strtolower($level),
                $message,
                json_encode($cleanContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ipAddress,
                $userAgent,
                $userId,
                $sessionId,
            ]);

        } catch (\Exception $e) {
            // ENTERPRISE GALAXY: Silent failure for database write - never break security logging
            // Log to error_log instead to avoid recursion
            error_log('[SECURITY EVENT DB WRITE FAILED] ' . $e->getMessage());
        }
    }

    /**
     * Log performance - ENTERPRISE GALAXY ULTIMATE: Full PSR-3 level support
     *
     * @param string $level Log level (debug, info, notice, warning, error, critical)
     * @param string $operation Operation name/description
     * @param float $executionTime Execution time in seconds
     * @param array $context Additional context
     */
    public static function performance(string $level, string $operation, float $executionTime, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if performance logging enabled
        if (function_exists('should_log') && !should_log('performance', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $logger = self::$loggers['performance'];

        if (!$logger) {
            return;
        }

        $performanceContext = array_merge($context, [
            'execution_time_ms' => round($executionTime * 1000, 2),
            'memory_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'timestamp' => time(),
        ]);

        switch (strtolower($level)) {
            case 'critical':
                $logger->critical($operation, $performanceContext);
                break;
            case 'error':
                $logger->error($operation, $performanceContext);
                break;
            case 'warning':
                $logger->warning($operation, $performanceContext);
                break;
            case 'notice':
                $logger->notice($operation, $performanceContext);
                break;
            case 'info':
                $logger->info($operation, $performanceContext);
                break;
            case 'debug':
            default:
                $logger->debug($operation, $performanceContext);
                break;
        }

        // ENTERPRISE GALAXY: Telegram alert for Performance events (warning+)
        $telegramLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (in_array(strtolower($level), $telegramLevels, true)) {
            self::queueTelegramAlert($level, 'performance', $operation, $performanceContext);
        }
    }

    /**
     * ENTERPRISE: Log email operations (queue, sending, verification, metrics)
     */
    public static function email(string $level, string $message, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if email logging enabled
        if (function_exists('should_log') && !should_log('email', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $logger = self::$loggers['email'];

        if (!$logger) {
            return;
        }

        $emailContext = array_merge($context, [
            'timestamp' => microtime(true),
            'request_id' => self::getRequestId(),
            'user_id' => self::getCurrentUserId(),
        ]);

        switch (strtolower($level)) {
            case 'error':
                $logger->error($message, $emailContext);
                break;
            case 'warning':
                $logger->warning($message, $emailContext);
                break;
            case 'info':
                $logger->info($message, $emailContext);
                break;
            case 'debug':
            default:
                $logger->debug($message, $emailContext);
                break;
        }

        // ENTERPRISE GALAXY: Telegram alert for Email events (warning+)
        $telegramLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (in_array(strtolower($level), $telegramLevels, true)) {
            self::queueTelegramAlert($level, 'email', $message, $emailContext);
        }
    }

    /**
     * ENTERPRISE GALAXY: Log JavaScript errors from frontend
     * Dedicated channel for all JS errors - FULL PSR-3 level support
     *
     * @param string $level PSR-3 level (debug, info, notice, warning, error, critical, alert, emergency)
     * @param string $message Error message
     * @param array $context Error context (stack trace, user agent, page URL, etc.)
     */
    public static function jsError(string $level, string $message, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if JS error logging enabled
        if (function_exists('should_log') && !should_log('js_errors', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $logger = self::$loggers['js_errors'];

        if (!$logger) {
            return;
        }

        $jsContext = array_merge($context, [
            'source' => 'javascript',
            'timestamp' => microtime(true),
            'request_id' => self::getRequestId(),
            'user_id' => self::getCurrentUserId(),
            'ip' => self::getClientIp(),
        ]);

        switch (strtolower($level)) {
            case 'emergency':
                $logger->emergency($message, $jsContext);
                break;
            case 'alert':
                $logger->alert($message, $jsContext);
                break;
            case 'critical':
                $logger->critical($message, $jsContext);
                break;
            case 'error':
                $logger->error($message, $jsContext);
                break;
            case 'warning':
                $logger->warning($message, $jsContext);
                break;
            case 'notice':
                $logger->notice($message, $jsContext);
                break;
            case 'info':
                $logger->info($message, $jsContext);
                break;
            case 'debug':
            default:
                $logger->debug($message, $jsContext);
                break;
        }
    }

    /**
     * ENTERPRISE: Log errore PHP nativo con spam protection
     */
    public static function phpError(string $type, string $message, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if PHP error logging enabled
        if (function_exists('should_log') && !should_log('default', 'error')) {
            return; // Exit immediately - zero overhead
        }

        $fullMessage = "$type: $message";

        // ENTERPRISE: Blocca spam messages (Xdebug, Redis Connection, etc)
        if (self::shouldBlockSpamMessage($fullMessage)) {
            return;
        }

        // ENTERPRISE: Rate limiting e deduplicazione
        if (!self::shouldLogWithRateLimit($fullMessage, 'error')
            || !self::shouldLogWithDeduplication($fullMessage, 'error')) {
            return;
        }

        self::$loggers['php_error']?->error($fullMessage, array_merge($context, [
            'request_id' => self::getRequestId(),
            'timestamp' => time(),
        ]));
    }

    /**
     * Clean old logs (chiamato da cron)
     */
    public static function cleanOldLogs(int $daysToKeep = 30): int
    {
        $cleaned = 0;
        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);

        $files = glob(self::$logPath . '/*.log*');

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $cleaned++;
                }
            }
        }

        self::info('Log cleanup completed', [
            'files_cleaned' => $cleaned,
            'days_to_keep' => $daysToKeep,
        ]);

        return $cleaned;
    }

    /**
     * ENTERPRISE GALAXY: Cleanup old security events from database
     *
     * RETENTION POLICY (PSR-3 levels):
     * - info/notice/warning events: 90 days
     * - error events: 180 days (6 months)
     * - critical/alert/emergency events: 365 days (1 year, compliance)
     *
     * OPTIMIZATION: Uses partition-aware queries for instant deletion
     * The security_events table is partitioned by RANGE(created_at) monthly.
     * PostgreSQL uses partition pruning when created_at is the primary filter.
     *
     * @return array Cleanup statistics
     */
    public static function cleanupSecurityEvents(): array
    {
        $stats = [
            'deleted_info_notice_warning' => 0,
            'deleted_error' => 0,
            'deleted_critical_alert_emergency' => 0,
            'total_deleted' => 0,
            'success' => false,
        ];

        try {
            // ENTERPRISE GALAXY: Partition-optimized queries
            // created_at filter FIRST enables PostgreSQL partition pruning
            // Uses idx_security_events_composite (channel, level, created_at)

            // Delete info/notice/warning events older than 90 days
            $cutoff90 = date('Y-m-d H:i:s', strtotime('-90 days'));
            $result = db()->execute(
                "DELETE FROM security_events
                 WHERE created_at < :cutoff
                   AND level IN ('info', 'notice', 'warning')",
                ['cutoff' => $cutoff90]
            );
            $stats['deleted_info_notice_warning'] = $result ?? 0;

            // Delete error events older than 180 days (6 months)
            $cutoff180 = date('Y-m-d H:i:s', strtotime('-180 days'));
            $result = db()->execute(
                "DELETE FROM security_events
                 WHERE created_at < :cutoff
                   AND level = 'error'",
                ['cutoff' => $cutoff180]
            );
            $stats['deleted_error'] = $result ?? 0;

            // Delete critical/alert/emergency events older than 365 days (compliance)
            $cutoff365 = date('Y-m-d H:i:s', strtotime('-365 days'));
            $result = db()->execute(
                "DELETE FROM security_events
                 WHERE created_at < :cutoff
                   AND level IN ('critical', 'alert', 'emergency')",
                ['cutoff' => $cutoff365]
            );
            $stats['deleted_critical_alert_emergency'] = $result ?? 0;

            $stats['total_deleted'] = $stats['deleted_info_notice_warning']
                + $stats['deleted_error']
                + $stats['deleted_critical_alert_emergency'];

            $stats['success'] = true;

            // Log cleanup completion
            self::info('Security events cleanup completed', $stats);

        } catch (\Exception $e) {
            self::error('Security events cleanup failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return $stats;
    }

    /**
     * Ottieni dimensioni log directory
     */
    public static function getLogDirectorySize(): array
    {
        $totalSize = 0;
        $fileCount = 0;

        $files = glob(self::$logPath . '/*.log*');

        foreach ($files as $file) {
            $totalSize += filesize($file);
            $fileCount++;
        }

        return [
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'file_count' => $fileCount,
        ];
    }

    /**
     * ENTERPRISE DIAGNOSTIC: Get type safety statistics
     */
    public static function getTypeSafetyStats(): array
    {
        static $conversionCounts = [];

        return [
            'total_conversions' => array_sum($conversionCounts),
            'conversion_breakdown' => $conversionCounts,
            'most_common_mistake' => !empty($conversionCounts) ? array_keys($conversionCounts, max($conversionCounts), true)[0] : null,
            'diagnostic_enabled' => ($_ENV['APP_ENV'] ?? 'production') === 'development',
        ];
    }

    /**
     * ENTERPRISE: Singleton getInstance method
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * ENTERPRISE: Log CSRF events for security monitoring
     */
    public static function csrfEvent(string $event, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if security logging enabled
        if (function_exists('should_log') && !should_log('security', 'warning')) {
            return; // Exit immediately - zero overhead
        }

        $csrfData = array_merge([
            'csrf_event' => $event,
            'timestamp' => time(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'ip' => self::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'session_id' => session_id(),
            'user_id' => self::getCurrentUserId(),
        ], $context);

        // Log to security channel for CSRF events (info level - normal operation, not warning)
        self::security('info', "CSRF: {$event}", $csrfData);

        // Also log to debug_general for flow tracking
        self::debugGeneral('csrf', $event, $csrfData);
    }

    /**
     * ENTERPRISE: Log database operations - ENTERPRISE GALAXY ULTIMATE: Full PSR-3 level support
     *
     * @param string $level Log level (debug, info, notice, warning, error, critical)
     * @param string $operation Operation description
     * @param array $context Additional context
     */
    public static function database(string $level, string $operation, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if database logging enabled
        if (function_exists('should_log') && !should_log('database', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $dbData = array_merge([
            'db_operation' => $operation,
            'timestamp' => microtime(true),
            'execution_time_ms' => $context['execution_time_ms'] ?? null,
            'query_type' => $context['query_type'] ?? null,
            'affected_rows' => $context['affected_rows'] ?? null,
            'pool_status' => self::getDatabasePoolStatus(),
        ], $context);

        // ENTERPRISE: Route to database logger (channel-based)
        self::bufferLog('database', strtolower($level), "DB: {$operation}", $dbData);

        // ENTERPRISE GALAXY: Telegram alert for Database events (warning+)
        $telegramLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (in_array(strtolower($level), $telegramLevels, true)) {
            self::queueTelegramAlert($level, 'database', "DB: {$operation}", $dbData);
        }
    }

    /**
     * DEPRECATED: Use Logger::database() instead
     * Kept for backward compatibility
     */
    public static function databaseOperation(string $operation, array $context = []): void
    {
        self::database('info', $operation, $context);
    }

    /**
     * ENTERPRISE: Log JavaScript events from frontend
     */
    public static function javascriptEvent(string $event, array $context = []): void
    {
        // ENTERPRISE GALAXY ULTIMATE: Check if JavaScript event logging enabled
        if (function_exists('should_log') && !should_log('default', 'info')) {
            return; // Exit immediately - zero overhead
        }

        $jsData = array_merge([
            'js_event' => $event,
            'timestamp' => microtime(true),
            'client_timestamp' => $context['client_timestamp'] ?? null,
            'page_url' => $context['page_url'] ?? $_SERVER['HTTP_REFERER'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip' => self::getClientIp(),
            'session_id' => session_id(),
            'user_id' => self::getCurrentUserId(),
        ], $context);

        // Buffer JavaScript events for performance
        self::bufferLog('debug_general', 'info', "JS: {$event}", $jsData);
    }

    /**
     * Logger errori applicazione
     */
    private static function initErrorLogger(): void
    {
        $logger = new MonologLogger('app_errors');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero
        // Level::Warning = accetta warning, error, critical, alert, emergency
        // (debug/info/notice vanno in debug_general, non qui)
        $handler = new RotatingFileHandler(
            self::$logPath . '/errors.log',
            7, // 7 giorni di retention
            Level::Warning
        );

        // Formatter con stack trace
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['error'] = $logger;
    }

    /**
     * Logger richieste HTTP
     */
    private static function initRequestLogger(): void
    {
        $logger = new MonologLogger('http_requests');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero con formato JSON
        $handler = new RotatingFileHandler(
            self::$logPath . '/requests.log',
            30, // 30 giorni di retention
            Level::Info
        );

        $handler->setFormatter(new JsonFormatter());

        $logger->pushHandler($handler);
        self::$loggers['request'] = $logger;
    }

    /**
     * Logger WebSocket
     */
    private static function initWebSocketLogger(): void
    {
        $logger = new MonologLogger('websocket');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        $handler = new RotatingFileHandler(
            self::$logPath . '/websocket.log',
            7,
            Level::Debug
        );

        $formatter = new LineFormatter(
            "[%datetime%] WS.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s'
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['websocket'] = $logger;
    }

    /**
     * Logger sicurezza - ENTERPRISE GALAXY ULTIMATE: Accepts all PSR-3 levels
     */
    private static function initSecurityLogger(): void
    {
        $logger = new MonologLogger('security');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        $handler = new RotatingFileHandler(
            self::$logPath . '/security.log',
            90, // 90 giorni per sicurezza
            Level::Debug // ENTERPRISE: Accept all levels (filtering via should_log)
        );

        $formatter = new LineFormatter(
            "[%datetime%] SECURITY.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s'
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['security'] = $logger;
    }

    /**
     * Logger performance - ENTERPRISE GALAXY ULTIMATE: Accepts all PSR-3 levels
     */
    private static function initPerformanceLogger(): void
    {
        $logger = new MonologLogger('performance');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        $handler = new RotatingFileHandler(
            self::$logPath . '/performance.log',
            7,
            Level::Debug // ENTERPRISE: Accept all levels (filtering via should_log)
        );

        // ENTERPRISE: Use LineFormatter to match other loggers (security, database, etc.)
        $formatter = new LineFormatter(
            "[%datetime%] PERFORMANCE.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['performance'] = $logger;
    }

    /**
     * Logger errori PHP nativi
     */
    private static function initPhpErrorLogger(): void
    {
        $logger = new MonologLogger('php_errors');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        $handler = new StreamHandler(
            self::$logPath . '/php_errors.log',
            Level::Error
        );

        $formatter = new LineFormatter(
            "[%datetime%] PHP.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s'
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['php_error'] = $logger;
    }

    /**
     * Logger debug generale per ogni avvenimento
     */
    private static function initDebugGeneralLogger(): void
    {
        $logger = new MonologLogger('debug_general');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero per debug generale
        $handler = new RotatingFileHandler(
            self::$logPath . '/debug_general.log',
            7, // 7 giorni di retention
            Level::Debug
        );

        // Formatter leggibile come php_errors.log
        $formatter = new LineFormatter(
            "[%datetime%] DEBUG_GENERAL: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['debug_general'] = $logger;
    }

    /**
     * ENTERPRISE: Logger email operations
     * Dedicated logger for email queue, sending, verification, metrics
     */
    private static function initEmailLogger(): void
    {
        $logger = new MonologLogger('email');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero per email operations
        $handler = new RotatingFileHandler(
            self::$logPath . '/email.log',
            30, // 30 giorni di retention (important for email tracking)
            Level::Debug // Capture all email events
        );

        // Formatter leggibile con contesto completo
        $formatter = new LineFormatter(
            "[%datetime%] EMAIL.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['email'] = $logger;
    }

    /**
     * ENTERPRISE GALAXY: Logger audio operations (upload, workers, S3)
     * Dedicated logger for audio file processing and S3 upload
     */
    private static function initAudioLogger(): void
    {
        $logger = new MonologLogger('audio');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero per audio operations
        $handler = new RotatingFileHandler(
            self::$logPath . '/audio.log',
            14, // 14 giorni di retention (audio processing logs)
            Level::Debug // Capture all audio events
        );

        // Formatter leggibile con contesto completo
        $formatter = new LineFormatter(
            "[%datetime%] AUDIO.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['audio'] = $logger;
    }

    /**
     * ENTERPRISE: Logger API operations - ENTERPRISE GALAXY ULTIMATE: Accepts all PSR-3 levels
     * Dedicated logger for API requests, responses, rate limits
     */
    private static function initApiLogger(): void
    {
        $logger = new MonologLogger('api');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero per API operations
        $handler = new RotatingFileHandler(
            self::$logPath . '/api.log',
            30, // 30 giorni di retention (important for API analytics)
            Level::Debug // ENTERPRISE: Accept all levels (filtering via should_log)
        );

        // ENTERPRISE GALAXY: Use LineFormatter like all other channels
        $formatter = new LineFormatter(
            "[%datetime%] API.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['api'] = $logger;
    }

    /**
     * ENTERPRISE: Logger database operations - ENTERPRISE GALAXY ULTIMATE: Accepts all PSR-3 levels
     * Dedicated logger for queries, pool status, slow queries
     */
    private static function initDatabaseLogger(): void
    {
        $logger = new MonologLogger('database');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero per database operations
        $handler = new RotatingFileHandler(
            self::$logPath . '/database.log',
            7, // 7 giorni di retention (high volume)
            Level::Debug // ENTERPRISE: Accept all levels (filtering via should_log)
        );

        // ENTERPRISE: Formatter leggibile come gli altri log (invece di JSON compatto)
        $formatter = new LineFormatter(
            "[%datetime%] DATABASE.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['database'] = $logger;
    }

    /**
     * ENTERPRISE GALAXY: Logger JavaScript errors
     * Dedicated logger for frontend error monitoring
     */
    private static function initJsErrorLogger(): void
    {
        $logger = new MonologLogger('js_errors');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero per JS errors
        $handler = new RotatingFileHandler(
            self::$logPath . '/js_errors.log',
            30, // 30 giorni di retention (important for JS error analysis)
            Level::Debug // ENTERPRISE: Accept all levels (filtering via should_log)
        );

        // Formatter leggibile con contesto completo
        $formatter = new LineFormatter(
            "[%datetime%] JS_ERRORS.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['js_errors'] = $logger;
    }

    /**
     * ENTERPRISE: Initialize Redis L3 using EnterpriseRedisManager with Circuit Breaker
     * PRODUCTION-READY: Redis L3 enabled with circuit breaker protection (like Netflix/Facebook)
     */
    private static function initRedisL3(): void
    {
        try {
            // ENTERPRISE: Use EnterpriseRedisManager with circuit breaker protection
            $redisManager = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            self::$redisL3 = $redisManager->getConnection('L3_logging');

            if (self::$redisL3) {
                // ENTERPRISE: Load any pending logs from previous crash/restart
                self::loadPendingLogsFromRedis();

                // ENTERPRISE: Only log if debug enabled for default channel
                if (function_exists('should_log') && should_log('default', 'debug')) {
                    error_log('[ENTERPRISE LOGGER] Redis L3 logging initialized with circuit breaker protection');
                }
            } else {
                // Circuit breaker is open - graceful degradation to file-only logging
                if (function_exists('should_log') && should_log('default', 'debug')) {
                    error_log('[ENTERPRISE LOGGER] Redis L3 unavailable - using file-only logging (circuit breaker protection)');
                }
            }
        } catch (\Exception $e) {
            // Graceful degradation - file-based logging always works
            self::$redisL3 = null;
            if (function_exists('should_log') && should_log('default', 'debug')) {
                error_log('[ENTERPRISE LOGGER] Redis L3 initialization failed: ' . $e->getMessage() . ' - using file-only logging');
            }
        }

        self::$lastFlush = time();
    }

    /**
     * ENTERPRISE: Load pending logs from Redis on system restart
     */
    private static function loadPendingLogsFromRedis(): void
    {
        if (!self::$redisL3) {
            return;
        }

        try {
            $pendingLogs = (new static())->safeRedisCall(self::$redisL3, 'lRange', ['logger:buffer', 0, -1]);

            if ($pendingLogs) {
                foreach ($pendingLogs as $logEntry) {
                    $decoded = json_decode($logEntry, true);

                    if ($decoded) {
                        self::$logBuffer[] = $decoded;
                    }
                }

                // Clear from Redis after loading
                self::$redisL3->del('logger:buffer');

                // Force flush loaded logs
                self::flushLogBuffer();
            }
        } catch (\Exception $e) {
            // ENTERPRISE: Safe logging during bootstrap with should_log check
            if (function_exists('should_log') && should_log('default', 'debug')) {
                error_log('[ENTERPRISE LOGGER] Failed to load pending logs from Redis: ' . $e->getMessage());
            }
        }
    }

    /**
     * ENTERPRISE: Add log to buffer (for high-volume systems)
     * PERFORMANCE FIX: Proactive cleanup to prevent memory leaks
     */
    private static function bufferLog(string $logger, string $level, string $message, array $context = []): void
    {
        $logEntry = [
            'logger' => $logger,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ];

        self::$logBuffer[] = $logEntry;

        // PERFORMANCE FIX: Proactive cleanup if caches are growing too large
        // This prevents accumulation before it becomes a problem
        if (count(self::$rateLimitCache) > 500 || count(self::$deduplicationCache) > 500) {
            self::performAggressiveCacheCleanup();
        }

        // Backup to Redis immediately for critical logs
        if (in_array($level, ['critical', 'error'], true) && self::$redisL3) {
            try {
                (new static())->safeRedisCall(self::$redisL3, 'rPush', ['logger:buffer', json_encode($logEntry)]);
            } catch (\Exception $e) {
                // Fail silently, don't break application
            }
        }

        // Force flush if buffer is full or time interval reached
        $currentTime = time();

        if (count(self::$logBuffer) >= self::$bufferSize
            || ($currentTime - self::$lastFlush) >= self::$flushInterval) {
            self::flushLogBuffer();
        }
    }

    /**
     * Configura PHP error handler
     */
    private static function configurePHPErrorHandler(): void
    {
        // Custom error handler
        set_error_handler(function ($severity, $message, $file, $line) {
            // Non loggare warning minori in produzione
            if (!(error_reporting() & $severity)) {
                return false;
            }

            $errorTypes = [
                E_ERROR => 'Fatal Error',
                E_WARNING => 'Warning',
                E_PARSE => 'Parse Error',
                E_NOTICE => 'Notice',
                E_CORE_ERROR => 'Core Error',
                E_CORE_WARNING => 'Core Warning',
                E_COMPILE_ERROR => 'Compile Error',
                E_COMPILE_WARNING => 'Compile Warning',
                E_USER_ERROR => 'User Error',
                E_USER_WARNING => 'User Warning',
                E_USER_NOTICE => 'User Notice',
                // E_STRICT => 'Strict Notice', // Deprecated in PHP 8+
                E_RECOVERABLE_ERROR => 'Recoverable Error',
                E_DEPRECATED => 'Deprecated',
                E_USER_DEPRECATED => 'User Deprecated',
            ];

            $errorType = $errorTypes[$severity] ?? 'Unknown Error';

            self::phpError($errorType, $message, [
                'file' => $file,
                'line' => $line,
                'severity' => $severity,
            ]);

            return true;
        });

        // Exception handler
        set_exception_handler(function ($exception) {
            // DEBUGBAR: Add exception to debugbar first (if enabled)
            if (class_exists('\\Need2Talk\\Services\\DebugbarService')) {
                try {
                    \Need2Talk\Services\DebugbarService::addException($exception);
                } catch (\Throwable $e) {
                    // Never let debugbar break error handling
                }
            }

            self::error('Uncaught Exception', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        });

        // Fatal error handler
        register_shutdown_function(function () {
            $error = error_get_last();

            if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                self::phpError('Fatal Error', $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                ]);
            }
        });
    }

    /**
     * Ottieni ID richiesta unico
     */
    private static function getRequestId(): string
    {
        static $requestId = null;

        if ($requestId === null) {
            $requestId = uniqid('req_', true);
        }

        return $requestId;
    }

    /**
     * Ottieni IP client (SECURE - non fidarsi di X-Forwarded-For)
     *
     * SECURITY: X-Forwarded-For e X-Real-IP possono essere spoofati dal client.
     * Nginx passa l'IP reale in REMOTE_ADDR via fastcgi_param.
     * Se in futuro si usa Cloudflare/CDN, configurare ngx_http_realip_module
     * per settare $remote_addr correttamente lato Nginx.
     */
    private static function getClientIp(): string
    {
        // REMOTE_ADDR = IP reale dal container Nginx (fidato)
        // Non fidarsi MAI di HTTP_X_FORWARDED_FOR o HTTP_X_REAL_IP dal client
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Ottieni ID utente corrente
     * ENTERPRISE: Supports both regular users and admin users
     * ENTERPRISE V10.16: Safe for WebSocket context where current_user_id() doesn't exist
     */
    private static function getCurrentUserId(): ?int
    {
        // Check regular user session first (only if helper exists - not in WebSocket context)
        if (function_exists('current_user_id') && !empty(current_user_id())) {
            return (int) current_user_id();
        }

        // Check admin session
        if (!empty($_SESSION['admin']['id'])) {
            return (int) $_SESSION['admin']['id'];
        }

        // Check simplified admin session (from AdminController)
        if (!empty($_SESSION['admin_id'])) {
            return (int) $_SESSION['admin_id'];
        }

        return null;
    }

    /**
     * ENTERPRISE TYPE SAFETY: Normalize context with auto-correction
     *
     * Handles common developer mistakes:
     * - String passed instead of array -> converts to array
     * - Null passed -> converts to empty array
     * - Object passed -> converts to array
     * - Invalid data types -> sanitizes and logs diagnostic
     */
    private static function normalizeContext($context, string $level): array
    {
        static $diagnosticEnabled = null;
        static $conversionCounts = [];

        if ($diagnosticEnabled === null) {
            $diagnosticEnabled = ($_ENV['APP_ENV'] ?? 'production') === 'development';
        }

        // Fast path for correct usage
        if (is_array($context)) {
            return $context;
        }

        // ENTERPRISE AUTO-CORRECTION with diagnostic tracking
        $originalType = gettype($context);
        $correctedContext = [];

        // Track conversion statistics
        if (!isset($conversionCounts[$originalType])) {
            $conversionCounts[$originalType] = 0;
        }
        $conversionCounts[$originalType]++;

        switch ($originalType) {
            case 'string':
                $correctedContext = ['message' => $context];
                break;

            case 'NULL':
                $correctedContext = [];
                break;

            case 'object':
                if (method_exists($context, 'toArray')) {
                    $correctedContext = $context->toArray();
                } elseif ($context instanceof \JsonSerializable) {
                    $correctedContext = $context->jsonSerialize();
                } else {
                    $correctedContext = (array) $context;
                }
                break;

            case 'boolean':
                $correctedContext = ['value' => $context ? 'true' : 'false'];
                break;

            case 'integer':
            case 'double':
                $correctedContext = ['value' => $context];
                break;

            default:
                $correctedContext = ['raw_value' => (string) $context, 'original_type' => $originalType];
                break;
        }

        // Add enterprise diagnostic metadata
        $correctedContext['_logger_diagnostic'] = [
            'auto_corrected' => true,
            'original_type' => $originalType,
            'level' => $level,
            'timestamp' => microtime(true),
            'correction_count_session' => $conversionCounts[$originalType],
        ];

        // Log diagnostic in development
        if ($diagnosticEnabled) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = $backtrace[2] ?? ['file' => 'unknown', 'line' => 0, 'function' => 'unknown'];

            error_log("🔧 LOGGER AUTO-CORRECTION: {$originalType} → array in {$caller['file']}:{$caller['line']} [{$caller['function']}()]");
        }

        return $correctedContext;
    }

    /**
     * ENTERPRISE: Rate limiting per log identici
     */
    private static function shouldLogWithRateLimit(string $message, string $level = 'info'): bool
    {
        $now = time();
        $cacheKey = md5($message . $level);

        // Pulisci cache vecchia
        self::cleanRateLimitCache($now);

        // Controlla rate limit per questo messaggio specifico
        if (!isset(self::$rateLimitCache[$cacheKey])) {
            self::$rateLimitCache[$cacheKey] = ['count' => 0, 'first_seen' => $now, 'last_seen' => $now];
        }

        $entry = &self::$rateLimitCache[$cacheKey];

        // Se è nella stessa finestra temporale
        if (($now - $entry['first_seen']) < self::$rateLimitWindow) {
            $entry['count']++;
            $entry['last_seen'] = $now;

            // Rate limit raggiunto - non loggare
            if ($entry['count'] > self::$maxLogPerMinute) {
                return false;
            }

            // Se è il limite esatto, logga un warning che stiamo silenzindo
            if ($entry['count'] === self::$maxLogPerMinute) {
                self::forceLogWithoutRateLimit(
                    'warning',
                    'ENTERPRISE RATE LIMIT: Silencing repeated log message',
                    ['message_hash' => $cacheKey, 'suppressed_after' => self::$maxLogPerMinute]
                );

                return false;
            }
        } else {
            // Nuova finestra temporale - reset
            $entry = ['count' => 1, 'first_seen' => $now, 'last_seen' => $now];
        }

        return true;
    }

    /**
     * ENTERPRISE: Deduplicazione log su brevi periodi
     */
    private static function shouldLogWithDeduplication(string $message, string $level = 'info'): bool
    {
        $now = time();
        $cacheKey = md5($message . $level);

        // Pulisci cache deduplicazione (finestra più corta - 10 secondi)
        self::cleanDeduplicationCache($now);

        if (isset(self::$deduplicationCache[$cacheKey])) {
            $lastSeen = self::$deduplicationCache[$cacheKey];

            // Se già visto negli ultimi 10 secondi, non loggare
            if (($now - $lastSeen) < 10) {
                return false;
            }
        }

        self::$deduplicationCache[$cacheKey] = $now;

        return true;
    }

    /**
     * Log forzato senza rate limiting (per messaggi interni sistema)
     */
    private static function forceLogWithoutRateLimit(string $level, string $message, array $context = []): void
    {
        $context = self::normalizeContext($context, $level);

        self::$loggers['error']?->log($level, $message, array_merge($context, [
            'request_id' => self::getRequestId(),
            'user_id' => self::getCurrentUserId(),
            'ip' => self::getClientIp(),
            'memory_usage' => memory_get_usage(true),
            'timestamp' => microtime(true),
        ]));
    }

    /**
     * Pulisci cache rate limiting
     */
    private static function cleanRateLimitCache(int $currentTime): void
    {
        foreach (self::$rateLimitCache as $key => $entry) {
            if (($currentTime - $entry['last_seen']) > self::$rateLimitWindow) {
                unset(self::$rateLimitCache[$key]);
            }
        }
    }

    /**
     * Pulisci cache deduplicazione
     */
    private static function cleanDeduplicationCache(int $currentTime): void
    {
        foreach (self::$deduplicationCache as $key => $lastSeen) {
            if (($currentTime - $lastSeen) > 10) {
                unset(self::$deduplicationCache[$key]);
            }
        }
    }

    /**
     * ENTERPRISE: Get Database Pool status for diagnostics
     */
    private static function getDatabasePoolStatus(): array
    {
        try {
            if (class_exists('Need2Talk\\Services\\EnterpriseSecureDatabasePool')) {
                $pool = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance();

                return [
                    'active_connections' => $pool->getActiveConnectionsCount(),
                    'max_connections' => $pool->getMaxConnections(),
                    'health_status' => 'healthy',
                ];
            }
        } catch (\Exception $e) {
            return ['error' => 'EnterpriseSecureDatabasePool not available'];
        }

        return ['status' => 'not_configured'];
    }

    /**
     * ENTERPRISE: Get Redis L1/L3 status for diagnostics
     */
    private static function getRedisStatus(): array
    {
        $status = [];

        // Check Redis L3 (logging)
        if (self::$redisL3) {
            try {
                self::$redisL3->ping();
                $status['L3_logging'] = 'connected';
                $status['L3_buffer_size'] = count(self::$logBuffer);
            } catch (\Exception $e) {
                $status['L3_logging'] = 'disconnected';
            }
        } else {
            $status['L3_logging'] = 'not_initialized';
        }

        // Check Redis status using EnterpriseRedisManager
        try {
            $redisManager = \Need2Talk\Core\EnterpriseRedisManager::getInstance();

            // Check L1 Cache
            $redisL1 = $redisManager->getConnection('L1_cache');
            $status['L1_cache'] = $redisL1 ? 'connected' : 'circuit_breaker_open';

            // Check L3 Logging
            $redisL3 = $redisManager->getConnection('L3_logging');
            $status['L3_logging'] = $redisL3 ? 'connected' : 'circuit_breaker_open';

            // Get circuit breaker status
            $circuitStatus = $redisManager->getCircuitBreakerStatus();
            $status['circuit_breakers'] = $circuitStatus;

        } catch (\Exception $e) {
            $status['L1_cache'] = 'error';
            $status['L3_logging'] = 'error';
        }

        return $status;
    }

    /**
     * ENTERPRISE PERFORMANCE FIX: Aggressive cache cleanup
     * Prevents memory leaks from static caches growing indefinitely
     * Called during buffer flush to ensure caches stay bounded
     */
    private static function performAggressiveCacheCleanup(): void
    {
        $now = time();
        $initialMemory = memory_get_usage(true);

        // Strategy 1: Clean rate limit cache (aggressive threshold)
        $rateLimitKeys = count(self::$rateLimitCache);
        if ($rateLimitKeys > 100) { // If more than 100 entries, force cleanup
            foreach (self::$rateLimitCache as $key => $entry) {
                if (($now - $entry['last_seen']) > self::$rateLimitWindow) {
                    unset(self::$rateLimitCache[$key]);
                }
            }
            $cleaned = $rateLimitKeys - count(self::$rateLimitCache);
            if ($cleaned > 0 && function_exists('should_log') && should_log('default', 'debug')) {
                error_log("[LOGGER CLEANUP] Rate limit cache: removed {$cleaned}/{$rateLimitKeys} stale entries");
            }
        }

        // Strategy 2: Clean deduplication cache (aggressive threshold)
        $dedupKeys = count(self::$deduplicationCache);
        if ($dedupKeys > 100) { // If more than 100 entries, force cleanup
            foreach (self::$deduplicationCache as $key => $lastSeen) {
                if (($now - $lastSeen) > 10) {
                    unset(self::$deduplicationCache[$key]);
                }
            }
            $cleaned = $dedupKeys - count(self::$deduplicationCache);
            if ($cleaned > 0 && function_exists('should_log') && should_log('default', 'debug')) {
                error_log("[LOGGER CLEANUP] Deduplication cache: removed {$cleaned}/{$dedupKeys} stale entries");
            }
        }

        // Strategy 3: Emergency full reset if caches are huge (memory leak protection)
        $rateLimitKeys = count(self::$rateLimitCache);
        $dedupKeys = count(self::$deduplicationCache);

        if ($rateLimitKeys > 1000) {
            if (function_exists('should_log') && should_log('default', 'warning')) {
                error_log("[LOGGER EMERGENCY] Rate limit cache exceeded 1000 entries ({$rateLimitKeys}) - FULL RESET");
            }
            self::$rateLimitCache = [];
        }

        if ($dedupKeys > 1000) {
            if (function_exists('should_log') && should_log('default', 'warning')) {
                error_log("[LOGGER EMERGENCY] Deduplication cache exceeded 1000 entries ({$dedupKeys}) - FULL RESET");
            }
            self::$deduplicationCache = [];
        }

        // Strategy 4: Check memory usage and force cleanup if high
        $currentMemory = memory_get_usage(true);
        $memoryMB = round($currentMemory / 1024 / 1024, 2);

        if ($memoryMB > 128) { // If PHP process using > 128MB, aggressive cleanup
            if (function_exists('should_log') && should_log('default', 'warning')) {
                error_log("[LOGGER CLEANUP] High memory usage detected ({$memoryMB} MB) - aggressive cleanup");
            }

            // Clear ALL caches
            self::$rateLimitCache = [];
            self::$deduplicationCache = [];
            self::$logBuffer = [];

            // Force garbage collection
            if (function_exists('gc_collect_cycles')) {
                $collected = gc_collect_cycles();
                if ($collected > 0 && function_exists('should_log') && should_log('default', 'debug')) {
                    error_log("[LOGGER CLEANUP] Garbage collection freed {$collected} cycles");
                }
            }
        }

        $finalMemory = memory_get_usage(true);
        $memoryFreed = $initialMemory - $finalMemory;

        if ($memoryFreed > 0 && function_exists('should_log') && should_log('default', 'debug')) {
            $freedMB = round($memoryFreed / 1024 / 1024, 2);
            error_log("[LOGGER CLEANUP] Memory freed: {$freedMB} MB");
        }
    }

    /**
     * ENTERPRISE GALAXY: Queue Telegram alert for log event
     *
     * Non-blocking - queues to Redis for async processing.
     * The TelegramLogAlertService handles:
     * - Configuration check (enabled, min level)
     * - Rate limiting (same error max 1/5min)
     * - Async sending via Redis queue
     *
     * @param string $level Log level
     * @param string $channel Log channel
     * @param string $message Log message
     * @param array $context Context
     */
    private static function queueTelegramAlert(string $level, string $channel, string $message, array $context = []): void
    {
        // ENTERPRISE: Check with autoload enabled (true = default)
        // class_exists returns false if class can't be loaded, without throwing
        if (!class_exists(TelegramLogAlertService::class)) {
            return;
        }

        try {
            TelegramLogAlertService::queueAlert($level, $channel, $message, $context);
        } catch (\Throwable $e) {
            // Never break logging - fail silently for any runtime errors
        }
    }

    /**
     * ENTERPRISE: Blocca completamente log ripetuti critici (Xdebug, Redis Connection)
     */
    private static function shouldBlockSpamMessage(string $message): bool
    {
        $spamPatterns = [
            '/Xdebug.*Could not connect to debugging client/i',
            '/Redis.*Connection refused/i',
            '/Could not connect to debugging client/i',
            '/Connection refused.*Redis/i',
        ];

        foreach ($spamPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                // Log solo una volta ogni 5 minuti per questi errori
                $cacheKey = 'spam_block_' . md5($message);
                $now = time();

                if (isset(self::$rateLimitCache[$cacheKey])) {
                    if (($now - self::$rateLimitCache[$cacheKey]['last_seen']) < 300) { // 5 minuti
                        return true; // Blocca
                    }
                }

                self::$rateLimitCache[$cacheKey] = ['last_seen' => $now];

                return false; // Permetti uno ogni 5 minuti
            }
        }

        return false;
    }

    /**
     * AUDIO LOGGING - Dedicated channel for audio processing
     *
     * ENTERPRISE: Separate logs per audio upload, worker processing, S3 upload
     * Performance: should_log() check to avoid overhead when disabled
     *
     * @param string $level Log level (debug, info, warning, error)
     * @param string $message Log message
     * @param array $context Additional context (audio_id, user_id, worker_id, etc)
     * @return void
     */
    public static function audio(string $level, string $message, array $context = []): void
    {
        // ENTERPRISE: Check if audio logging enabled (zero overhead if disabled)
        if (function_exists('should_log') && !should_log('audio', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $logger = self::$loggers['audio'] ?? null;

        if (!$logger) {
            return;
        }

        $audioContext = array_merge($context, [
            'timestamp' => time(),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);

        // ENTERPRISE: Write to audio.log (async via Monolog)
        switch (strtolower($level)) {
            case 'emergency':
                $logger->emergency($message, $audioContext);
                break;
            case 'alert':
                $logger->alert($message, $audioContext);
                break;
            case 'critical':
                $logger->critical($message, $audioContext);
                break;
            case 'error':
                $logger->error($message, $audioContext);
                break;
            case 'warning':
                $logger->warning($message, $audioContext);
                break;
            case 'notice':
                $logger->notice($message, $audioContext);
                break;
            case 'info':
                $logger->info($message, $audioContext);
                break;
            case 'debug':
            default:
                $logger->debug($message, $audioContext);
                break;
        }

        // ENTERPRISE GALAXY: Telegram alert for Audio events (warning+)
        $telegramLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (in_array(strtolower($level), $telegramLevels, true)) {
            self::queueTelegramAlert($level, 'audio', $message, $audioContext);
        }
    }

    /**
     * OVERLAY LOGGING - Dedicated channel for overlay cache operations
     *
     * ENTERPRISE GALAXY V4.1: Separate logs for overlay cache flush, views, reactions, friendships
     * Performance: should_log() check to avoid overhead when disabled
     *
     * @param string $level Log level (debug, info, notice, warning, error, critical, alert, emergency)
     * @param string $message Log message
     * @param array $context Additional context (post_id, user_id, flush_type, duration_ms, etc)
     * @return void
     */
    public static function overlay(string $level, string $message, array $context = []): void
    {
        // ENTERPRISE: Check if overlay logging enabled (zero overhead if disabled)
        if (function_exists('should_log') && !should_log('overlay', strtolower($level))) {
            return; // Exit immediately - zero overhead
        }

        $logger = self::$loggers['overlay'] ?? null;

        if (!$logger) {
            return;
        }

        $overlayContext = array_merge($context, [
            'timestamp' => time(),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);

        // ENTERPRISE: Write to overlay.log (async via Monolog)
        switch (strtolower($level)) {
            case 'emergency':
                $logger->emergency($message, $overlayContext);
                break;
            case 'alert':
                $logger->alert($message, $overlayContext);
                break;
            case 'critical':
                $logger->critical($message, $overlayContext);
                break;
            case 'error':
                $logger->error($message, $overlayContext);
                break;
            case 'warning':
                $logger->warning($message, $overlayContext);
                break;
            case 'notice':
                $logger->notice($message, $overlayContext);
                break;
            case 'info':
                $logger->info($message, $overlayContext);
                break;
            case 'debug':
            default:
                $logger->debug($message, $overlayContext);
                break;
        }

        // ENTERPRISE GALAXY: Telegram alert for Overlay events (warning+)
        $telegramLevels = ['warning', 'error', 'critical', 'alert', 'emergency'];
        if (in_array(strtolower($level), $telegramLevels, true)) {
            self::queueTelegramAlert($level, 'overlay', $message, $overlayContext);
        }
    }

    /**
     * ENTERPRISE GALAXY V4.1: Logger overlay cache operations
     * Dedicated logger for write-behind buffer flush, views, reactions, friendships
     */
    private static function initOverlayLogger(): void
    {
        $logger = new MonologLogger('overlay');
        $logger->setTimezone(new \DateTimeZone('Europe/Rome'));

        // File rotante giornaliero per overlay operations
        $handler = new RotatingFileHandler(
            self::$logPath . '/overlay.log',
            14, // 14 giorni di retention (overlay cache + flush logs)
            Level::Debug // ENTERPRISE: Accept all levels (filtering via should_log)
        );

        // Formatter leggibile con contesto completo
        $formatter = new LineFormatter(
            "[%datetime%] OVERLAY.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);
        self::$loggers['overlay'] = $logger;
    }
}
