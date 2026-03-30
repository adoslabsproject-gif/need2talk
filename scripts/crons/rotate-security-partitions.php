<?php
/**
 * ENTERPRISE GALAXY: Monthly Partition Rotation for security_events
 *
 * ⚠️  DEPRECATED FOR POSTGRESQL ⚠️
 *
 * This script was designed for MySQL partitioning (INFORMATION_SCHEMA.PARTITIONS).
 * PostgreSQL uses a completely different partitioning system.
 *
 * POSTGRESQL ALTERNATIVE:
 * - Use time-based table inheritance or declarative partitioning
 * - Partitions are managed via pg_inherits and pg_partitioned_table
 * - DROP partition via: ALTER TABLE security_events DETACH PARTITION partition_name;
 *
 * TODO: Rewrite this script for PostgreSQL partitioning
 *
 * CURRENTLY: Script exits gracefully for PostgreSQL compatibility
 *
 * @author Claude Code (Enterprise Galaxy Initiative)
 * @since 2025-10-27
 * @deprecated PostgreSQL migration - needs rewrite
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

// ENTERPRISE: Detect database type and exit gracefully for PostgreSQL
$dbType = env('DB_CONNECTION', 'mysql');
if ($dbType === 'pgsql' || $dbType === 'postgres') {
    echo "ℹ️  This script is designed for MySQL partitioning.\n";
    echo "ℹ️  PostgreSQL uses a different partitioning system.\n";
    echo "ℹ️  Skipping partition rotation (not applicable).\n\n";

    Logger::security('info', 'Partition rotation skipped (PostgreSQL)', [
        'script' => 'rotate-security-partitions',
        'reason' => 'MySQL partitioning not applicable to PostgreSQL',
    ]);

    exit(0);
}

// ============================================================================
// Configuration
// ============================================================================
const SCRIPT_NAME = 'rotate-security-partitions';
const LOG_PREFIX = '[PARTITION ROTATION]';
const TABLE_NAME = 'security_events';
const RETENTION_MONTHS = 6;

// ============================================================================
// Main Execution
// ============================================================================

echo str_repeat("=", 70) . "\n";
echo "🔄 ENTERPRISE GALAXY: MONTHLY PARTITION ROTATION\n";
echo str_repeat("=", 70) . "\n\n";

$startTime = microtime(true);

try {
    // Direct PDO connection
    $pdo = new PDO(
        "pgsql:host=" . env('DB_HOST', 'postgres') . ";dbname=" . env('DB_NAME', 'need2talk'),
        env('DB_USER', 'need2talk'),
        env('DB_PASSWORD', '')
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // STEP 1: Get all current partitions
    echo "[1/5] Analyzing current partitions...\n";
    $stmt = $pdo->query("
        SELECT
            PARTITION_NAME,
            PARTITION_DESCRIPTION,
            TABLE_ROWS,
            CREATE_TIME
        FROM INFORMATION_SCHEMA.PARTITIONS
        WHERE TABLE_NAME = '" . TABLE_NAME . "'
          AND TABLE_SCHEMA = '" . env('DB_NAME', 'need2talk') . "'
          AND PARTITION_NAME IS NOT NULL
          AND PARTITION_NAME != 'p_future'
        ORDER BY PARTITION_ORDINAL_POSITION
    ");
    $partitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "      Current partitions: " . count($partitions) . "\n";
    foreach ($partitions as $p) {
        echo sprintf(
            "        • %-15s  %10s rows  (created: %s)\n",
            $p['PARTITION_NAME'],
            number_format($p['TABLE_ROWS']),
            $p['CREATE_TIME'] ? date('Y-m-d', strtotime($p['CREATE_TIME'])) : 'N/A'
        );
    }
    echo "\n";

    // STEP 2: Identify oldest partition to drop
    echo "[2/5] Identifying oldest partition...\n";
    if (empty($partitions)) {
        echo "      ⚠️  No partitions found! Table might not be partitioned.\n";
        exit(0);
    }

    $oldestPartition = $partitions[0];
    $oldestName = $oldestPartition['PARTITION_NAME'];
    $oldestRows = $oldestPartition['TABLE_ROWS'];

    // Extract date from partition name (format: p_YYYYMM)
    if (!preg_match('/^p_(\d{6})$/', $oldestName, $matches)) {
        echo "      ⚠️  Partition name format not recognized: {$oldestName}\n";
        echo "      Expected format: p_YYYYMM\n";
        exit(0);
    }

    $oldestYearMonth = $matches[1];
    $oldestYear = substr($oldestYearMonth, 0, 4);
    $oldestMonth = substr($oldestYearMonth, 4, 2);
    $oldestDate = new DateTime("{$oldestYear}-{$oldestMonth}-01");
    $monthsOld = (int)$oldestDate->diff(new DateTime())->format('%m') +
                 ((int)$oldestDate->diff(new DateTime())->format('%y') * 12);

    echo "      Oldest partition: {$oldestName}\n";
    echo "      Partition date: " . $oldestDate->format('F Y') . "\n";
    echo "      Age: {$monthsOld} months\n";
    echo "      Rows to be dropped: " . number_format($oldestRows) . "\n\n";

    // STEP 3: Check if oldest partition should be dropped
    echo "[3/5] Checking retention policy...\n";
    if ($monthsOld < RETENTION_MONTHS) {
        echo "      ✅ Partition is younger than retention period (" . RETENTION_MONTHS . " months)\n";
        echo "      Skipping drop operation.\n\n";

        // Still add new partition for future
        goto add_new_partition;
    }

    echo "      ✅ Partition is older than retention period (" . RETENTION_MONTHS . " months)\n";
    echo "      Proceeding with drop operation...\n\n";

    // STEP 4: Drop oldest partition
    echo "[4/5] Dropping oldest partition...\n";
    $dropSQL = "ALTER TABLE " . TABLE_NAME . " DROP PARTITION " . $oldestName;

    $pdo->exec($dropSQL);

    echo "      ✅ Partition '{$oldestName}' dropped successfully\n";
    echo "      Space freed: ~" . number_format($oldestRows) . " rows\n\n";

    Logger::security('notice', 'Partition dropped (retention policy)', [
        'script' => SCRIPT_NAME,
        'partition_name' => $oldestName,
        'partition_date' => $oldestDate->format('Y-m-d'),
        'rows_dropped' => $oldestRows,
        'age_months' => $monthsOld,
    ]);

    // STEP 5: Add new partition for future month
    add_new_partition:
    echo "[5/5] Adding new partition for future month...\n";

    // Calculate next month partition
    $nextMonth = (new DateTime())->modify('+2 months');
    $partitionName = 'p_' . $nextMonth->format('Ym');
    $partitionLimit = $nextMonth->modify('first day of next month')->format('Y-m-d');
    $partitionTimestamp = strtotime($partitionLimit);

    // Check if partition already exists
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.PARTITIONS
        WHERE TABLE_NAME = '" . TABLE_NAME . "'
          AND TABLE_SCHEMA = '" . env('DB_NAME', 'need2talk') . "'
          AND PARTITION_NAME = ?
    ");
    $stmt->execute([$partitionName]);
    if ($stmt->fetchColumn() > 0) {
        echo "      ⚠️  Partition '{$partitionName}' already exists\n";
        echo "      Skipping creation.\n\n";
    } else {
        // Reorganize p_future partition to add new partition
        $reorganizeSQL = "
            ALTER TABLE " . TABLE_NAME . "
            REORGANIZE PARTITION p_future INTO (
                PARTITION {$partitionName} VALUES LESS THAN ({$partitionTimestamp}),
                PARTITION p_future VALUES LESS THAN MAXVALUE
            )
        ";

        $pdo->exec($reorganizeSQL);

        echo "      ✅ Partition '{$partitionName}' created successfully\n";
        echo "      Covers data until: {$partitionLimit}\n\n";

        Logger::security('notice', 'New partition added', [
            'script' => SCRIPT_NAME,
            'partition_name' => $partitionName,
            'partition_limit' => $partitionLimit,
        ]);
    }

    // Final summary
    $endTime = microtime(true);
    $duration = round(($endTime - $startTime) * 1000, 2);

    echo str_repeat("=", 70) . "\n";
    echo "✅ PARTITION ROTATION COMPLETED SUCCESSFULLY\n";
    echo str_repeat("=", 70) . "\n\n";

    echo "SUMMARY:\n";
    if (isset($oldestName) && $monthsOld >= RETENTION_MONTHS) {
        echo "  • Dropped: {$oldestName} (~" . number_format($oldestRows) . " rows)\n";
    } else {
        echo "  • Dropped: None (retention period not reached)\n";
    }
    echo "  • Created: {$partitionName}\n";
    echo "  • Execution time: {$duration}ms\n\n";

    Logger::security('notice', 'Monthly partition rotation completed', [
        'script' => SCRIPT_NAME,
        'dropped_partition' => isset($oldestName) && $monthsOld >= RETENTION_MONTHS ? $oldestName : null,
        'created_partition' => $partitionName,
        'duration_ms' => $duration,
    ]);

    exit(0);

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    echo "\n❌ ERROR: {$error}\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";

    Logger::security('error', 'Partition rotation failed', [
        'script' => SCRIPT_NAME,
        'error' => $error,
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);

} catch (Exception $e) {
    $error = "Unexpected error: " . $e->getMessage();
    echo "\n❌ ERROR: {$error}\n";

    Logger::security('error', 'Partition rotation failed', [
        'script' => SCRIPT_NAME,
        'error' => $error,
    ]);

    exit(1);
}
