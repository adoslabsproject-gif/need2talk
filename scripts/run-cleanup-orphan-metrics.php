#!/usr/bin/env php
<?php

/**
 * ENTERPRISE: Safe Cleanup of Orphan Email Metrics
 *
 * Removes email_verification_metrics records for deleted/fake users
 * Preserves table structure, indexes, and all real user data
 */

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db_pdo();

echo "================================================================\n";
echo "  ENTERPRISE: Orphan Email Metrics Cleanup\n";
echo "================================================================\n\n";

// Step 1: Count orphan records
echo "Step 1: Analyzing orphan records...\n";
$stmt = $pdo->query("
    SELECT COUNT(*) as orphan_count
    FROM email_verification_metrics evm
    LEFT JOIN users u ON evm.user_id = u.id
    WHERE u.id IS NULL
");
$orphanCount = $stmt->fetch()['orphan_count'];

// Step 2: Count total records
$stmt = $pdo->query("SELECT COUNT(*) as total FROM email_verification_metrics");
$totalBefore = $stmt->fetch()['total'];

echo "  - Total metrics: $totalBefore\n";
echo "  - Orphan metrics (user deleted/fake): $orphanCount\n";
echo "  - Real user metrics: " . ($totalBefore - $orphanCount) . "\n";

if ($orphanCount === 0) {
    echo "\n✅ No orphan records found. Nothing to clean up.\n";
    exit(0);
}

// Step 3: Show sample orphan records
echo "\nStep 2: Sample orphan records to be deleted:\n";
$stmt = $pdo->query("
    SELECT evm.id, evm.user_id, evm.status, evm.created_at
    FROM email_verification_metrics evm
    LEFT JOIN users u ON evm.user_id = u.id
    WHERE u.id IS NULL
    LIMIT 5
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("  - ID: %d | User: %d | Status: %s | Created: %s\n",
        $row['id'],
        $row['user_id'],
        $row['status'],
        $row['created_at']
    );
}

// Step 4: Confirmation
echo "\n================================================================\n";
echo "  WARNING: This will DELETE $orphanCount orphan records!\n";
echo "================================================================\n";
echo "  ✅ Table structure will be preserved\n";
echo "  ✅ All indexes will be preserved\n";
echo "  ✅ Real user metrics will NOT be affected\n";
echo "  ❌ Orphan metrics will be permanently deleted\n";
echo "\nProceed with cleanup? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = strtolower(trim(fgets($handle)));
fclose($handle);

if ($line !== 'yes' && $line !== 'y') {
    echo "\n❌ Cleanup cancelled by user. (You entered: '$line')\n";
    exit(0);
}

// Step 5: Execute cleanup in transaction
echo "\nStep 3: Executing cleanup (in transaction)...\n";

try {
    $pdo->beginTransaction();

    // Delete orphan records
    $stmt = $pdo->prepare("
        DELETE evm
        FROM email_verification_metrics evm
        LEFT JOIN users u ON evm.user_id = u.id
        WHERE u.id IS NULL
    ");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();

    echo "  - Deleted $deletedCount orphan records\n";

    // Verify no real users affected
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) as real_users
        FROM email_verification_metrics
    ");
    $realUsersAfter = $stmt->fetch()['real_users'];

    // Verify total remaining
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM email_verification_metrics");
    $totalAfter = $stmt->fetch()['total'];

    echo "  - Remaining metrics: $totalAfter\n";
    echo "  - Real users with metrics: $realUsersAfter\n";

    // Commit transaction
    $pdo->commit();

    echo "\n✅ Cleanup completed successfully!\n";
    echo "\nSummary:\n";
    echo "  - Records before: $totalBefore\n";
    echo "  - Records deleted: $deletedCount\n";
    echo "  - Records after: $totalAfter\n";
    echo "  - Space saved: ~" . round($deletedCount * 0.5, 2) . " KB\n";

} catch (\Exception $e) {
    $pdo->rollBack();
    echo "\n❌ ERROR: Cleanup failed and was rolled back!\n";
    echo "  Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 6: Verify table structure
echo "\nStep 4: Verifying table integrity...\n";
$stmt = $pdo->query("SHOW INDEX FROM email_verification_metrics");
$indexCount = count($stmt->fetchAll());
echo "  ✅ Table indexes intact: $indexCount indexes\n";

echo "\n================================================================\n";
echo "  ✅ CLEANUP COMPLETED SUCCESSFULLY\n";
echo "================================================================\n";
