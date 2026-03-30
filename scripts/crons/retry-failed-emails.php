#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Failed Emails Retry (Cron Version)
 *
 * Processes failed emails from database for retry (max 5 additional attempts)
 * This is a single-run cron version of failed-email-retry-worker.php
 *
 * Emails that fail Redis retry (3 attempts) are stored in database
 * This cron retries them periodically with exponential backoff
 *
 * Schedule: Every 30 minutes 
 * Usage:
 *   php scripts/crons/retry-failed-emails.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/retry-failed-emails.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\AsyncEmailQueue;
use Need2Talk\Services\FailedEmailRetryService;
use Need2Talk\Services\Logger;

echo "🚀 ENTERPRISE GALAXY: Failed Emails Retry\n";
echo str_repeat('=', 70) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$startTime = microtime(true);
$batchSize = 50;

try {
    // Check if FailedEmailRetryService exists
    if (!class_exists('Need2Talk\\Services\\FailedEmailRetryService')) {
        echo "⚠️  FailedEmailRetryService not available\n";
        echo "✅ No failed emails to retry at this time\n";
        exit(0);
    }

    // Get retry service statistics
    $retryService = new FailedEmailRetryService();
    $stats = $retryService->getStats();

    echo "📊 Failed Email Statistics\n";
    echo str_repeat('-', 70) . "\n";
    echo "   Pending: " . number_format($stats['pending'] ?? 0) . "\n";
    echo "   Processing: " . number_format($stats['processing'] ?? 0) . "\n";
    echo "   Ready for retry: " . number_format($stats['ready_for_retry'] ?? 0) . "\n";
    echo "   Permanent failures: " . number_format($stats['permanent_failure'] ?? 0) . "\n";

    // Show top error types
    if (!empty($stats['error_types'])) {
        echo "\n📋 Top Error Types:\n";
        foreach (array_slice($stats['error_types'], 0, 5) as $error) {
            echo "   • {$error['error_type']}: " . number_format($error['count']) . "\n";
        }
    }

    $readyForRetry = $stats['ready_for_retry'] ?? 0;

    if ($readyForRetry === 0) {
        echo "\n✅ No emails ready for retry at this time\n";

        Logger::info('Failed emails retry cron - no emails to retry', [
            'pending' => $stats['pending'] ?? 0,
            'permanent_failure' => $stats['permanent_failure'] ?? 0
        ]);

        exit(0);
    }

    // Process batch
    echo "\n" . str_repeat('-', 70) . "\n";
    echo "🔄 Processing {$readyForRetry} emails (batch size: {$batchSize})\n";
    echo str_repeat('-', 70) . "\n\n";

    $emailQueue = new AsyncEmailQueue();
    $processed = $emailQueue->processFailedEmailsFromDatabase($batchSize);

    echo "✅ Processed: " . number_format($processed) . " emails\n";

    // Get updated statistics
    $statsAfter = $retryService->getStats();

    echo "\n📊 After Processing\n";
    echo str_repeat('-', 70) . "\n";
    echo "   Pending: " . number_format($statsAfter['pending'] ?? 0) . "\n";
    echo "   Ready for retry: " . number_format($statsAfter['ready_for_retry'] ?? 0) . "\n";

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "✅ Failed emails retry completed!\n";
    echo "📊 Emails processed: " . number_format($processed) . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    // Log to system
    Logger::info('Failed emails retry cron completed', [
        'processed' => $processed,
        'ready_before' => $readyForRetry,
        'ready_after' => $statsAfter['ready_for_retry'] ?? 0,
        'pending' => $statsAfter['pending'] ?? 0,
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    Logger::error('Failed emails retry cron failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
