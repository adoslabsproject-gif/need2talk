<?php

/**
 * CLEANUP TELEGRAM TABLES - Enterprise Galaxy
 *
 * Cleans old records from Telegram tracking tables:
 * - telegram_messages: Audit log of sent messages (30 days retention)
 * - telegram_log_deliveries: Daily log delivery tracking (30 days retention)
 *
 * SCHEDULE: Monthly (1st day of month at 4:00 AM)
 * 0 4 1 * * docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-telegram.php
 *
 * @package Need2Talk\Crons
 * @version 1.0.0
 */

declare(strict_types=1);

// Bootstrap
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use Need2Talk\Services\Logger;

// Configuration
const RETENTION_DAYS = 30;

echo "=== Telegram Tables Cleanup ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "Retention: " . RETENTION_DAYS . " days\n\n";

$cutoffDate = date('Y-m-d H:i:s', strtotime('-' . RETENTION_DAYS . ' days'));
echo "Cutoff date: {$cutoffDate}\n\n";

$totalDeleted = 0;
$results = [];

try {
    $db = db();

    // 1. Clean telegram_messages
    echo "[1/2] Cleaning telegram_messages...\n";

    $countBefore = $db->findOne(
        "SELECT COUNT(*) as cnt FROM telegram_messages WHERE created_at < :cutoff",
        ['cutoff' => $cutoffDate]
    )['cnt'] ?? 0;

    if ($countBefore > 0) {
        $db->execute(
            "DELETE FROM telegram_messages WHERE created_at < :cutoff",
            ['cutoff' => $cutoffDate]
        );
        echo "      Deleted: {$countBefore} old messages\n";
        $totalDeleted += $countBefore;
        $results['telegram_messages'] = $countBefore;
    } else {
        echo "      No old records to delete\n";
        $results['telegram_messages'] = 0;
    }

    // 2. Clean telegram_log_deliveries
    echo "[2/2] Cleaning telegram_log_deliveries...\n";

    $countBefore = $db->findOne(
        "SELECT COUNT(*) as cnt FROM telegram_log_deliveries WHERE sent_at < :cutoff",
        ['cutoff' => $cutoffDate]
    )['cnt'] ?? 0;

    if ($countBefore > 0) {
        $db->execute(
            "DELETE FROM telegram_log_deliveries WHERE sent_at < :cutoff",
            ['cutoff' => $cutoffDate]
        );
        echo "      Deleted: {$countBefore} old delivery records\n";
        $totalDeleted += $countBefore;
        $results['telegram_log_deliveries'] = $countBefore;
    } else {
        echo "      No old records to delete\n";
        $results['telegram_log_deliveries'] = 0;
    }

    // Summary
    echo "\n=== Summary ===\n";
    echo "Total deleted: {$totalDeleted} records\n";
    echo "Completed: " . date('Y-m-d H:i:s') . "\n";

    // Log success
    if ($totalDeleted > 0) {
        Logger::info('Telegram cleanup completed', [
            'retention_days' => RETENTION_DAYS,
            'cutoff_date' => $cutoffDate,
            'deleted' => $results,
            'total_deleted' => $totalDeleted,
        ]);
    }

    exit(0);

} catch (\Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";

    Logger::error('Telegram cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
}
