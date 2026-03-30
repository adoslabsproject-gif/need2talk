#!/usr/bin/env php
<?php

/**
 * 🔒 Security Audit Script
 *
 * Performs security checks and audits
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

$startTime = microtime(true);

echo "🔒 ENTERPRISE: Security Audit\n";
echo str_repeat('=', 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = db();

    echo "⏳ Running security checks...\n";

    // Check for suspicious login attempts (channel-based)
    $suspiciousLogins = $db->findOne("
        SELECT COUNT(*) as count
        FROM security_events
        WHERE channel = 'auth' AND level = 'warning'
        AND created_at > NOW() - INTERVAL '1 day'
    ");
    echo "   ℹ️  Failed logins (24h): " . ($suspiciousLogins['count'] ?? 0) . "\n";

    // Check for banned IPs
    $bannedIps = $db->findOne("SELECT COUNT(*) as count FROM ip_bans WHERE expires_at > NOW()");
    echo "   ℹ️  Active IP bans: " . ($bannedIps['count'] ?? 0) . "\n";

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✅ Security audit completed!\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    Logger::info('Security audit completed', [
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n❌ ERROR: " . $e->getMessage() . "\n";

    Logger::error('Security audit failed', [
        'error' => $e->getMessage(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
