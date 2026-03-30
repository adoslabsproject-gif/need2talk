#!/usr/bin/env php
<?php

/**
 * ENTERPRISE: Clear Failed Email Queue in Redis
 */

require_once __DIR__ . '/../app/bootstrap.php';

echo "================================================================\n";
echo "  ENTERPRISE: Redis Failed Queue Cleanup\n";
echo "================================================================\n\n";

try {
    $redis = new Redis();
    $redis->connect('redis', 6379);
    $redis->select(2); // Email queue DB

    // Check queue size
    $failedCount = $redis->zCard('email_queue:failed');

    echo "Failed emails in queue: $failedCount\n\n";

    if ($failedCount === 0) {
        echo "✅ No failed emails to clean up.\n";
        exit(0);
    }

    // Show sample
    echo "Sample failed emails (first 10):\n";
    $sample = $redis->zRange('email_queue:failed', 0, 9);
    foreach ($sample as $i => $email) {
        $data = json_decode($email, true);
        echo sprintf("  %d. %s (User: %d)\n",
            $i + 1,
            $data['email'] ?? 'unknown',
            $data['user_id'] ?? 0
        );
    }

    // Confirmation
    echo "\n⚠️  WARNING: This will DELETE $failedCount failed emails from Redis!\n";
    echo "Proceed? (yes/no): ";

    $handle = fopen("php://stdin", "r");
    $line = strtolower(trim(fgets($handle)));
    fclose($handle);

    if ($line !== 'yes' && $line !== 'y') {
        echo "\n❌ Cleanup cancelled.\n";
        exit(0);
    }

    // Delete the queue
    echo "\nDeleting failed queue...\n";
    $deleted = $redis->del('email_queue:failed');

    // Verify
    $remaining = $redis->zCard('email_queue:failed');

    echo "\n✅ Cleanup completed!\n";
    echo "  - Deleted: $failedCount emails\n";
    echo "  - Remaining: $remaining emails\n";

    $redis->close();

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
