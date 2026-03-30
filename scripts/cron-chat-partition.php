#!/usr/bin/env php
<?php

/**
 * Chat Database Partition Maintenance Cron Job
 *
 * ENTERPRISE GALAXY CHAT (2025-12-02)
 *
 * This script runs monthly (25th of each month) to:
 * 1. Create next month's partition for direct_messages table
 * 2. Verify existing partitions
 * 3. Report partition status
 *
 * PostgreSQL partitioning enables:
 * - Efficient queries on recent data
 * - Easy archival of old data
 * - Better vacuum/analyze performance
 * - Scalability to 100M+ messages
 *
 * Crontab entry:
 * 0 0 25 * * php /var/www/need2talk/scripts/cron-chat-partition.php >> /var/www/need2talk/storage/logs/chat_partition.log 2>&1
 *
 * @package Need2Talk\Scripts
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;

echo "[" . date('Y-m-d H:i:s') . "] Starting chat partition maintenance...\n";

$startTime = microtime(true);
$stats = [
    'partitions_created' => 0,
    'partitions_verified' => 0,
    'errors' => 0,
];

try {
    $db = db();

    // Calculate next month dates
    $nextMonth = new DateTime('first day of next month');
    $nextMonthStart = $nextMonth->format('Y-m-01');
    $nextMonthEnd = $nextMonth->modify('+1 month')->format('Y-m-01');

    $partitionName = 'direct_messages_' . (new DateTime('first day of next month'))->format('Y_m');

    echo "[" . date('Y-m-d H:i:s') . "] Creating partition: {$partitionName}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Range: {$nextMonthStart} to {$nextMonthEnd}\n";

    // ========================================================================
    // 1. CHECK IF PARTITION ALREADY EXISTS
    // ========================================================================

    $existingPartition = $db->findOne(
        "SELECT tablename FROM pg_tables
         WHERE schemaname = 'public' AND tablename = :partition_name",
        ['partition_name' => $partitionName]
    );

    if ($existingPartition) {
        echo "[" . date('Y-m-d H:i:s') . "] Partition {$partitionName} already exists. Skipping creation.\n";
        $stats['partitions_verified']++;
    } else {
        // ========================================================================
        // 2. CREATE NEW PARTITION
        // ========================================================================

        $sql = "CREATE TABLE {$partitionName} PARTITION OF direct_messages
                FOR VALUES FROM ('{$nextMonthStart}') TO ('{$nextMonthEnd}')";

        $db->execute($sql);

        echo "[" . date('Y-m-d H:i:s') . "] Created partition: {$partitionName}\n";
        $stats['partitions_created']++;

        // Create indexes on the new partition
        echo "[" . date('Y-m-d H:i:s') . "] Creating indexes on {$partitionName}...\n";

        // Index for conversation queries
        $db->execute("CREATE INDEX IF NOT EXISTS idx_{$partitionName}_conv
                      ON {$partitionName} (conversation_id, created_at DESC)");

        // Index for unread messages
        $db->execute("CREATE INDEX IF NOT EXISTS idx_{$partitionName}_unread
                      ON {$partitionName} (conversation_id, status) WHERE status != 'read'");

        // Index for UUID lookups
        $db->execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_{$partitionName}_uuid
                      ON {$partitionName} (uuid)");

        echo "[" . date('Y-m-d H:i:s') . "] Indexes created.\n";
    }

    // ========================================================================
    // 3. VERIFY ALL EXISTING PARTITIONS
    // ========================================================================

    echo "[" . date('Y-m-d H:i:s') . "] Verifying existing partitions...\n";

    $partitions = $db->findMany(
        "SELECT
            child.relname AS partition_name,
            pg_size_pretty(pg_relation_size(child.oid)) AS size,
            pg_stat_get_live_tuples(child.oid) AS row_count
         FROM pg_inherits
         JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
         JOIN pg_class child ON pg_inherits.inhrelid = child.oid
         WHERE parent.relname = 'direct_messages'
         ORDER BY child.relname"
    );

    if (empty($partitions)) {
        echo "[" . date('Y-m-d H:i:s') . "] WARNING: No partitions found for direct_messages table.\n";
        $stats['errors']++;
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Found " . count($partitions) . " partitions:\n";
        foreach ($partitions as $partition) {
            echo "  - {$partition['partition_name']}: {$partition['size']} ({$partition['row_count']} rows)\n";
            $stats['partitions_verified']++;
        }
    }

    // ========================================================================
    // 4. CHECK FOR MISSING PARTITIONS (next 3 months)
    // ========================================================================

    echo "[" . date('Y-m-d H:i:s') . "] Checking for missing partitions (next 3 months)...\n";

    for ($i = 1; $i <= 3; $i++) {
        $futureMonth = new DateTime("+{$i} month");
        $futureName = 'direct_messages_' . $futureMonth->format('Y_m');

        $exists = $db->findOne(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = :name",
            ['name' => $futureName]
        );

        if (!$exists) {
            echo "[" . date('Y-m-d H:i:s') . "] MISSING: {$futureName} (will be created on 25th of previous month)\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] OK: {$futureName} exists\n";
        }
    }

    // ========================================================================
    // 5. ANALYZE PARTITIONS FOR QUERY OPTIMIZER
    // ========================================================================

    echo "[" . date('Y-m-d H:i:s') . "] Running ANALYZE on direct_messages...\n";
    $db->execute("ANALYZE direct_messages");

    // Calculate duration
    $duration = round((microtime(true) - $startTime) * 1000, 2);

    // Log results
    Logger::info('Chat partition maintenance completed', [
        'stats' => $stats,
        'duration_ms' => $duration,
    ]);

    echo "[" . date('Y-m-d H:i:s') . "] Partition maintenance completed in {$duration}ms\n";
    echo "[" . date('Y-m-d H:i:s') . "] Stats: " . json_encode($stats) . "\n";

} catch (Exception $e) {
    $stats['errors']++;
    Logger::error('Chat partition maintenance failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
