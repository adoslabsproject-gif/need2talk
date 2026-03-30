<?php
/**
 * ENTERPRISE SECURITY EVENTS CLEANUP
 *
 * Removes ALL security events from whitelisted IPs (owner + staff)
 * Safe to run multiple times - idempotent operation
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;

echo "=== ENTERPRISE SECURITY EVENTS CLEANUP ===\n\n";

// ENTERPRISE: Direct PDO for maintenance scripts (PostgreSQL)
$pdo = new PDO(
    "pgsql:host=" . env('DB_HOST', 'postgres') . ";port=" . env('DB_PORT', '5432') . ";dbname=" . env('DB_NAME', 'need2talk'),
    env('DB_USER', 'need2talk'),
    env('DB_PASSWORD', '')
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Step 1: Count events before cleanup
$totalBefore = $pdo->query("SELECT COUNT(*) FROM security_events")->fetchColumn();
echo "Total events before cleanup: " . number_format($totalBefore) . "\n";

// Step 2: Get all whitelisted IPs
$stmt = $pdo->query("
    SELECT ip_address, label, type
    FROM ip_whitelist
    WHERE is_active = TRUE
");
$whitelistedIPs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Whitelisted IPs found: " . count($whitelistedIPs) . "\n\n";

// Step 3: Delete events for each whitelisted IP
$totalDeleted = 0;

foreach ($whitelistedIPs as $entry) {
    $ip = $entry['ip_address'];
    $label = $entry['label'];
    $type = $entry['type'];

    // Count events for this IP
    $count = $pdo->prepare("SELECT COUNT(*) FROM security_events WHERE ip_address = ?");
    $count->execute([$ip]);
    $eventsCount = $count->fetchColumn();

    if ($eventsCount > 0) {
        echo sprintf("  %-20s (%-20s %-10s): %s events\n",
            $ip,
            substr($label, 0, 20),
            "[$type]",
            number_format($eventsCount)
        );

        // Delete events
        $delete = $pdo->prepare("DELETE FROM security_events WHERE ip_address = ?");
        $delete->execute([$ip]);

        $totalDeleted += $eventsCount;

        Logger::security('info', 'Cleaned security events for whitelisted IP', [
            'ip' => $ip,
            'label' => $label,
            'type' => $type,
            'events_deleted' => $eventsCount,
        ]);
    }
}

// Step 4: Optimize table after cleanup (SKIPPED - richiede permessi ALTER)
// L'ottimizzazione avverrà automaticamente durante la manutenzione notturna
echo "\nTable optimization skipped (will run during nightly maintenance)...\n";

// Step 5: Show results
$totalAfter = $pdo->query("SELECT COUNT(*) FROM security_events")->fetchColumn();

echo "\n=== CLEANUP RESULTS ===\n";
echo "Events before: " . number_format($totalBefore) . "\n";
echo "Events deleted: " . number_format($totalDeleted) . "\n";
echo "Events after: " . number_format($totalAfter) . "\n";
echo "Space saved: " . number_format(($totalDeleted/$totalBefore)*100, 2) . "%\n";

echo "\n✅ Cleanup completed successfully!\n";

Logger::security('notice', 'Security events cleanup completed', [
    'total_before' => $totalBefore,
    'total_deleted' => $totalDeleted,
    'total_after' => $totalAfter,
    'percentage_cleaned' => ($totalDeleted/$totalBefore)*100,
]);
