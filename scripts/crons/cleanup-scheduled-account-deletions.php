#!/usr/bin/env php
<?php

/**
 * ENTERPRISE GALAXY: Scheduled Account Deletions (GDPR Article 17)
 *
 * Hard deletes accounts after 30-day grace period
 *
 * GDPR COMPLIANCE:
 * - Article 17: Right to erasure ("right to be forgotten")
 * - 30-day grace period to prevent accidental deletions
 * - Irreversible after grace period expires
 *
 * SECURITY:
 * - Permanently deletes all user data (profile, posts, comments, etc.)
 * - Updates account_deletions table (status='executed')
 * - Logs all deletions for audit trail
 *
 * Schedule: Daily at 3 AM (0 3 * * *)
 * Usage:
 *   php scripts/crons/cleanup-scheduled-account-deletions.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-scheduled-account-deletions.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;
use Need2Talk\Services\GDPRExportService;

Logger::info('CRON: Scheduled account deletions cleanup started', [
    'script' => basename(__FILE__)
]);

echo "ENTERPRISE GALAXY: Scheduled Account Deletions\n";
echo str_repeat('=', 70) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$startTime = microtime(true);

try {
    $gdprService = new GDPRExportService();

    // Use the service's built-in method that handles the entire flow:
    // 1. Finds pending deletions past grace period
    // 2. Hard deletes user data via hardDeleteUser()
    // 3. Marks deletion records as 'executed'
    // 4. Logs audit trail
    $result = $gdprService->executeScheduledDeletions();

    $duration = round(microtime(true) - $startTime, 2);

    echo str_repeat('=', 70) . "\n";
    echo "SUMMARY\n";
    echo str_repeat('=', 70) . "\n";
    echo "Accounts deleted: {$result['deleted_count']}\n";

    if (!empty($result['deleted_user_ids'])) {
        echo "User IDs: " . implode(', ', $result['deleted_user_ids']) . "\n";
    }

    echo "Duration: {$duration}s\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat('=', 70) . "\n";

    Logger::info('CRON: Scheduled account deletions completed', [
        'deleted_count' => $result['deleted_count'],
        'deleted_user_ids' => $result['deleted_user_ids'],
        'duration' => $duration
    ]);

    exit(0);

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";

    Logger::error('CRON: Fatal error in scheduled account deletions', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    exit(1);
}
