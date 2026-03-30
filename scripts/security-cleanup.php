#!/usr/bin/env php
<?php

/**
 * Security Events Cleanup Script
 *
 * ENTERPRISE GALAXY: Auto retention policy for security_events table
 *
 * RETENTION POLICY (PSR-3 levels):
 * - info/notice/warning events: 90 days
 * - error events: 180 days (6 months)
 * - critical/alert/emergency events: 365 days (1 year, compliance)
 *
 * USAGE:
 * - Manual: php scripts/security-cleanup.php
 * - Cron (daily): 0 2 * * * /usr/bin/php /path/to/scripts/security-cleanup.php
 *
 * @version 2.0.0 Enterprise Galaxy
 */

// Bootstrap application
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;

echo "===========================================\n";
echo "ENTERPRISE GALAXY SECURITY CLEANUP\n";
echo "===========================================\n\n";

$startTime = microtime(true);
$startMemory = memory_get_usage(true);

try {
    // Check database connection
    echo "[1/3] Checking database connection...\n";
    $testQuery = db()->findOne("SELECT COUNT(*) as total FROM security_events");
    $totalBefore = $testQuery['total'] ?? 0;
    echo "      Total events before cleanup: {$totalBefore}\n\n";

    // Execute cleanup via Logger (ENTERPRISE GALAXY: centralized cleanup)
    echo "[2/3] Executing retention policy...\n";
    $stats = Logger::cleanupSecurityEvents();

    echo "      - info/notice/warning (90 days): {$stats['deleted_info_notice_warning']} deleted\n";
    echo "      - error (180 days): {$stats['deleted_error']} deleted\n";
    echo "      - critical/alert/emergency (365 days): {$stats['deleted_critical_alert_emergency']} deleted\n";
    echo "      Total deleted: {$stats['total_deleted']} events\n\n";

    // Verify
    echo "[3/3] Verifying cleanup...\n";
    $testQuery = db()->findOne("SELECT COUNT(*) as total FROM security_events");
    $totalAfter = $testQuery['total'] ?? 0;
    echo "      Total events after cleanup: {$totalAfter}\n";
    echo "      Space freed: " . ($totalBefore - $totalAfter) . " rows\n\n";

    // ENTERPRISE: Analyze table for PostgreSQL (VACUUM is automatic)
    echo "[OPTIMIZE] Analyzing table statistics...\n";
    db()->execute("ANALYZE security_events");
    echo "      Table analyzed (PostgreSQL auto-vacuums)\n\n";

    // Statistics
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    $memoryUsed = round((memory_get_usage(true) - $startMemory) / 1024 / 1024, 2);

    echo "===========================================\n";
    echo "CLEANUP COMPLETED\n";
    echo "===========================================\n";
    echo "Execution time: {$executionTime}ms\n";
    echo "Memory used: {$memoryUsed}MB\n";
    echo "Status: " . ($stats['success'] ? 'SUCCESS' : 'PARTIAL') . "\n\n";

    exit($stats['success'] ? 0 : 1);

} catch (\Exception $e) {
    echo "\n[ERROR] Cleanup failed: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n\n";

    exit(1);
}
