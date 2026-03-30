#!/usr/bin/env php
<?php
/**
 * TELEGRAM LOG ALERTS QUEUE PROCESSOR (OPTIMIZED)
 *
 * ENTERPRISE GALAXY: Process queued Telegram log alerts from Redis
 * Runs every 2 minutes via cron
 *
 * OPTIMIZATION:
 * - Quick Redis check BEFORE bootstrap (1ms if queue empty)
 * - Silent exit when nothing to do (zero log spam)
 * - Full bootstrap only when messages exist
 *
 * CRON ENTRY (every 2min) - MANAGED VIA CRONTAB:
 * star/2 * * * * docker exec need2talk_php php /var/www/html/scripts/crons/telegram-alerts.php
 */

declare(strict_types=1);

// Quick Redis check - NO bootstrap overhead
$envFile = __DIR__ . '/../../.env';
if (!file_exists($envFile)) {
    exit(1);
}

// Parse only needed env vars (fast)
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim($value, '"\'');
}

// Quick Redis connection check
$redisHost = $env['REDIS_HOST'] ?? 'redis';
$redisPort = (int)($env['REDIS_PORT'] ?? 6379);
$redisPass = $env['REDIS_PASSWORD'] ?? null;

try {
    $redis = new Redis();
    $redis->connect($redisHost, $redisPort, 1.0); // 1s timeout
    if ($redisPass) $redis->auth($redisPass);
    $redis->select(0); // L1_cache DB (where TelegramLogAlertService writes)

    // Check queue length - O(1) operation
    $queueLen = $redis->lLen('telegram:log_alerts:queue');
    $redis->close();

    // Silent exit if nothing to process
    if ($queueLen === 0) {
        exit(0);
    }
} catch (Throwable $e) {
    // Redis down - exit silently, don't spam logs
    exit(1);
}

// --- QUEUE HAS MESSAGES: Load full bootstrap ---

define('TELEGRAM_ALERT_WORKER', true);

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\TelegramLogAlertService;

try {
    // Process up to 30 alerts per run (30s interval = ~1/sec rate limit friendly)
    $processed = TelegramLogAlertService::processQueue(30);

    // ENTERPRISE V9.4: Silent processing - only log errors, not success
    // If debug logging is needed, uncomment the line below:
    // Logger::debug('TelegramAlertWorker processed alerts', ['count' => $processed]);

} catch (Throwable $e) {
    // Only log actual errors
    error_log('[TelegramAlertWorker] ERROR: ' . $e->getMessage());
    exit(1);
}
