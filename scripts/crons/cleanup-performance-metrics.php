#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Performance Metrics Cleanup
 *
 * Cron job to delete old performance metrics (>60 days)
 * Keeps table size manageable and queries fast
 *
 * RETENTION: 60 days for raw metrics (configurable)
 * SUMMARY: Summary data kept indefinitely (lightweight)
 *
 * Schedule: 0 2 * * * (daily at 2 AM)
 *
 * Usage:
 *   php scripts/crons/cleanup-performance-metrics.php [--days=60] [--dry-run]
 *   docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-performance-metrics.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

$startTime = microtime(true);

// Parse command line arguments
$retentionDays = 60; // Default retention
$dryRun = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--days=') === 0) {
        $retentionDays = (int) substr($arg, 7);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

Logger::info('CRON: Performance metrics cleanup started', [
    'retention_days' => $retentionDays,
    'dry_run' => $dryRun,
    'script' => basename(__FILE__)
]);

echo "🚀 ENTERPRISE: Performance Metrics Cleanup\n";
echo str_repeat('=', 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Retention period: {$retentionDays} days\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no deletion)" : "LIVE") . "\n\n";

try {
    $db = db();

    // Calculate cutoff date
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
    echo "📅 Cutoff date: {$cutoffDate}\n";
    echo "📅 Will delete records older than this date\n\n";

    // Check how many records will be deleted
    $countResult = $db->findOne("
        SELECT
            COUNT(*) as total_records,
            COUNT(CASE WHEN created_at < ? THEN 1 END) as old_records,
            MIN(created_at) as oldest_record,
            MAX(created_at) as newest_record
        FROM enterprise_performance_metrics
    ", [$cutoffDate]);

    $totalRecords = $countResult['total_records'];
    $oldRecords = $countResult['old_records'];
    $oldestRecord = $countResult['oldest_record'];
    $newestRecord = $countResult['newest_record'];

    echo "📊 Current Statistics:\n";
    echo "   Total records: " . number_format($totalRecords) . "\n";
    echo "   Records to delete: " . number_format($oldRecords) . "\n";
    echo "   Records to keep: " . number_format($totalRecords - $oldRecords) . "\n";
    echo "   Oldest record: {$oldestRecord}\n";
    echo "   Newest record: {$newestRecord}\n\n";

    if ($oldRecords === 0) {
        echo "✅ No records to delete!\n";
        echo "All records are within the {$retentionDays}-day retention period.\n";
        exit(0);
    }

    // Get breakdown by page URL
    $breakdown = $db->query("
        SELECT
            page_url,
            COUNT(*) as count_to_delete
        FROM enterprise_performance_metrics
        WHERE created_at < ?
        GROUP BY page_url
        ORDER BY count_to_delete DESC
        LIMIT 10
    ", [$cutoffDate]);

    if (!empty($breakdown)) {
        echo "📊 Top 10 pages with old records:\n";
        foreach ($breakdown as $row) {
            echo "   - {$row['page_url']}: " . number_format($row['count_to_delete']) . " records\n";
        }
        echo "\n";
    }

    if ($dryRun) {
        echo "🔍 DRY RUN MODE - No deletion performed\n";
        echo "Run without --dry-run flag to actually delete records\n";
        exit(0);
    }

    // Perform deletion in batches to avoid locking table
    $batchSize = 10000;
    $totalDeleted = 0;
    $batchCount = 0;

    echo "🗑️  Starting deletion in batches of {$batchSize}...\n\n";

    while (true) {
        // PostgreSQL-compatible batch deletion using CTID subquery
        // (PostgreSQL doesn't support DELETE ... LIMIT like MySQL)
        $deleted = $db->execute("
            DELETE FROM enterprise_performance_metrics
            WHERE ctid IN (
                SELECT ctid FROM enterprise_performance_metrics
                WHERE created_at < ?
                LIMIT ?
            )
        ", [$cutoffDate, $batchSize]);

        if ($deleted === 0) {
            break;
        }

        $totalDeleted += $deleted;
        $batchCount++;

        echo "   Batch #{$batchCount}: Deleted " . number_format($deleted) . " records\n";

        // Small delay to avoid overwhelming the database
        usleep(100000); // 100ms
    }

    // PostgreSQL: VACUUM to reclaim disk space (equivalent to MySQL OPTIMIZE TABLE)
    echo "\n🔧 Running VACUUM ANALYZE to reclaim disk space and update statistics...\n";
    $db->execute("VACUUM ANALYZE enterprise_performance_metrics");

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✅ Cleanup completed successfully!\n";
    echo "📊 Total records deleted: " . number_format($totalDeleted) . "\n";
    echo "📊 Batches processed: {$batchCount}\n";
    echo "💾 Table optimized and disk space reclaimed\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";
    echo "🕐 Completed at: " . date('Y-m-d H:i:s') . "\n";

    Logger::info('Performance metrics cleanup completed', [
        'retention_days' => $retentionDays,
        'records_deleted' => $totalDeleted,
        'batches' => $batchCount,
        'execution_time' => $executionTime
    ]);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Execution time: {$executionTime}ms\n";

    Logger::error('Performance metrics cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
