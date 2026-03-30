#!/usr/bin/env php
<?php

/**
 * Telegram Daily Logs - Enterprise Galaxy
 *
 * Sends yesterday's log files to Telegram admin.
 * Run via cron at 06:00 daily.
 *
 * CRON ENTRY:
 * 0 6 * * * docker exec need2talk_php php /var/www/html/scripts/cron-telegram-daily-logs.php
 *
 * @package Need2Talk\Scripts
 */

declare(strict_types=1);

// Ensure CLI only
if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

// Bootstrap - go up 2 directories: crons → scripts → project root
define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\TelegramNotificationService;
use Need2Talk\Services\Logger;

echo "=== Telegram Daily Logs ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Check if Telegram is configured
if (!TelegramNotificationService::isConfigured()) {
    echo "ERROR: Telegram not configured\n";
    exit(1);
}

echo "Sending daily logs to Telegram...\n";

$results = TelegramNotificationService::sendDailyLogs();

echo "\nResults:\n";
foreach ($results as $type => $status) {
    $icon = match ($status) {
        'sent' => '✅',
        'already_sent' => '✓',  // Already sent - deduplication working
        'empty' => '📭',
        'not_found' => '❌',
        'failed' => '⚠️',
        default => '❓',
    };
    echo "  {$icon} {$type}: {$status}\n";
}

$sent = count(array_filter($results, fn($s) => $s === 'sent'));
echo "\nTotal sent: {$sent}/" . count($results) . "\n";

Logger::info('Telegram daily logs sent', [
    'results' => $results,
    'sent_count' => $sent,
]);

echo "\n=== Done ===\n";
exit(0);
