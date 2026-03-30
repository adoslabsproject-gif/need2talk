<?php
/**
 * ENTERPRISE GALAXY: Daily Cleanup of Whitelisted IP Security Events
 *
 * SCHEDULE: Daily at 04:00 AM
 * PURPOSE: Removes security events from whitelisted IPs (owner, staff, bots)
 * SAFE: Idempotent - can run multiple times without side effects
 * PERFORMANCE: Uses direct PDO for maintenance operations
 *
 * @author Claude Code (Enterprise Galaxy Initiative)
 * @since 2025-10-27
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

// ============================================================================
// Configuration
// ============================================================================
const SCRIPT_NAME = 'cleanup-whitelisted-security-events';
const LOG_PREFIX = '[ENTERPRISE CLEANUP]';

// ============================================================================
// Main Execution
// ============================================================================

echo str_repeat("=", 70) . "\n";
echo "🧹 ENTERPRISE GALAXY: WHITELISTED IPS SECURITY EVENTS CLEANUP\n";
echo str_repeat("=", 70) . "\n\n";

$startTime = microtime(true);
$startMemory = memory_get_usage();

try {
    // Direct PDO connection (bypass wrapper for maintenance)
    $pdo = new PDO(
        "pgsql:host=" . env('DB_HOST', 'postgres') . ";dbname=" . env('DB_NAME', 'need2talk'),
        env('DB_USER', 'need2talk'),
        env('DB_PASSWORD', '')
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // STEP 1: Count total events before cleanup
    echo "[1/4] Counting events before cleanup...\n";
    $totalBefore = $pdo->query("SELECT COUNT(*) FROM security_events")->fetchColumn();
    echo "      Total events: " . number_format($totalBefore) . "\n\n";

    // STEP 2: Get all active whitelisted IPs
    echo "[2/4] Fetching whitelisted IPs...\n";
    $stmt = $pdo->query("
        SELECT ip_address, label, type
        FROM ip_whitelist
        WHERE is_active = TRUE
        ORDER BY type, label
    ");
    $whitelistedIPs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "      Whitelisted IPs found: " . count($whitelistedIPs) . "\n\n";

    // STEP 3: Delete events for each whitelisted IP
    echo "[3/4] Cleaning events for whitelisted IPs...\n";
    $totalDeleted = 0;
    $cleaned = 0;

    foreach ($whitelistedIPs as $entry) {
        $ip = $entry['ip_address'];
        $label = $entry['label'];
        $type = $entry['type'];

        // ENTERPRISE FIX: Use partition pruning by including created_at range
        // security_events is PARTITIONED by RANGE on created_at
        // Without created_at in WHERE, PostgreSQL scans ALL 6 partitions!
        $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months'));

        // Count events for this IP (use partition pruning)
        $count = $pdo->prepare("
            SELECT COUNT(*)
            FROM security_events
            WHERE ip_address = ?
            AND created_at >= ?
        ");
        $count->execute([$ip, $sixMonthsAgo]);
        $eventsCount = $count->fetchColumn();

        if ($eventsCount > 0) {
            echo sprintf(
                "      %-20s  %-25s  [%-10s]  %s events\n",
                $ip,
                substr($label, 0, 25),
                $type,
                number_format($eventsCount)
            );

            // Delete events (use partition pruning + batch delete)
            // ENTERPRISE: Delete in batches to avoid long locks
            $batchSize = 1000;
            $totalDeletedForIP = 0;

            while (true) {
                // ENTERPRISE GALAXY: PostgreSQL DELETE...LIMIT not supported
                // Use CTID subquery pattern for batch deletion (enterprise-grade performance)
                $delete = $pdo->prepare("
                    DELETE FROM security_events
                    WHERE ctid IN (
                        SELECT ctid
                        FROM security_events
                        WHERE ip_address = :ip
                        AND created_at >= :cutoff
                        LIMIT :limit
                    )
                ");
                $delete->bindValue(':ip', $ip, PDO::PARAM_STR);
                $delete->bindValue(':cutoff', $sixMonthsAgo, PDO::PARAM_STR);
                $delete->bindValue(':limit', $batchSize, PDO::PARAM_INT);
                $delete->execute();
                $deleted = $delete->rowCount();

                $totalDeletedForIP += $deleted;

                if ($deleted < $batchSize) {
                    break; // No more rows to delete
                }

                // Small delay between batches
                usleep(50000); // 50ms
            }

            $totalDeleted += $totalDeletedForIP;
            $cleaned++;

            // Log to security channel
            Logger::security('info', 'Daily cleanup: removed security events for whitelisted IP', [
                'script' => SCRIPT_NAME,
                'ip' => $ip,
                'label' => $label,
                'type' => $type,
                'events_deleted' => $totalDeletedForIP,
                'partition_pruning' => true, // ENTERPRISE: Used partition pruning
            ]);
        }
    }

    if ($cleaned === 0) {
        echo "      ✅ No events to clean (all whitelisted IPs already clean)\n\n";
    } else {
        echo "\n";
    }

    // STEP 4: Show results
    echo "[4/4] Cleanup summary...\n";
    $totalAfter = $pdo->query("SELECT COUNT(*) FROM security_events")->fetchColumn();

    $endTime = microtime(true);
    $endMemory = memory_get_usage();
    $duration = round(($endTime - $startTime) * 1000, 2);
    $memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2);

    echo "      Events before:  " . number_format($totalBefore) . "\n";
    echo "      Events deleted: " . number_format($totalDeleted) . "\n";
    echo "      Events after:   " . number_format($totalAfter) . "\n";

    if ($totalDeleted > 0) {
        $percentage = round(($totalDeleted / $totalBefore) * 100, 2);
        echo "      Space saved:    {$percentage}%\n";
    }

    echo "\n" . str_repeat("=", 70) . "\n";
    echo "✅ CLEANUP COMPLETED SUCCESSFULLY\n";
    echo str_repeat("=", 70) . "\n\n";

    echo "PERFORMANCE METRICS:\n";
    echo "  • Execution time: {$duration}ms\n";
    echo "  • Memory used: {$memoryUsed}MB\n";
    echo "  • IPs cleaned: {$cleaned}\n";
    echo "  • Events removed: " . number_format($totalDeleted) . "\n\n";

    // Log completion
    Logger::security('notice', 'Daily whitelisted IPs cleanup completed', [
        'script' => SCRIPT_NAME,
        'total_before' => $totalBefore,
        'total_deleted' => $totalDeleted,
        'total_after' => $totalAfter,
        'ips_cleaned' => $cleaned,
        'duration_ms' => $duration,
        'memory_mb' => $memoryUsed,
    ]);

    exit(0);

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    echo "\n❌ ERROR: {$error}\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";

    Logger::security('error', 'Daily cleanup failed', [
        'script' => SCRIPT_NAME,
        'error' => $error,
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);

} catch (Exception $e) {
    $error = "Unexpected error: " . $e->getMessage();
    echo "\n❌ ERROR: {$error}\n";

    Logger::security('error', 'Daily cleanup failed', [
        'script' => SCRIPT_NAME,
        'error' => $error,
    ]);

    exit(1);
}
