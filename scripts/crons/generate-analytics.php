#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Daily Analytics Generator
 *
 * Generates daily analytics report with:
 * - User activity metrics
 * - Performance statistics
 * - Error rates
 * - System health
 *
 * PERFORMANCE: Aggregates data from multiple sources
 * SCALABILITY: Handles millions of events efficiently
 *
 * Schedule: 0 6 * * * (daily at 6 AM)
 *
 * Usage:
 *   php scripts/crons/generate-analytics.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/generate-analytics.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

$startTime = microtime(true);

Logger::info('CRON: Daily analytics generation started', [
    'script' => basename(__FILE__)
]);

echo "📈 ENTERPRISE: Daily Analytics Generator\n";
echo str_repeat('=', 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = db();
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    echo "📊 Generating analytics for: {$yesterday}\n\n";

    // 1. User Activity Metrics
    echo "⏳ Calculating user activity...\n";
    $activeUsers = $db->findOne("
        SELECT COUNT(DISTINCT user_id) as count
        FROM sessions
        WHERE CAST(to_timestamp(last_activity) AS date) = ?
    ", [$yesterday]);

    echo "   ✅ Active users: " . ($activeUsers['count'] ?? 0) . "\n";

    // 2. Registration Metrics
    echo "⏳ Calculating registrations...\n";
    $newUsers = $db->findOne("
        SELECT COUNT(*) as count
        FROM users
        WHERE created_at::date = ?
    ", [$yesterday]);

    echo "   ✅ New registrations: " . ($newUsers['count'] ?? 0) . "\n";

    // 3. Performance Metrics
    echo "⏳ Calculating performance stats...\n";
    $performance = $db->findOne("
        SELECT
            COUNT(*) as total_requests,
            ROUND(AVG(page_load_time)) as avg_load_time,
            MAX(page_load_time) as max_load_time
        FROM enterprise_performance_metrics
        WHERE created_at::date = ?
    ", [$yesterday]);

    echo "   ✅ Total requests: " . ($performance['total_requests'] ?? 0) . "\n";
    echo "   ✅ Avg load time: " . ($performance['avg_load_time'] ?? 0) . "ms\n";

    // 4. Error Metrics (from security events)
    echo "⏳ Calculating error rates...\n";
    $errors = $db->findOne("
        SELECT COUNT(*) as count
        FROM security_events
        WHERE created_at::date = ? AND level IN ('error', 'critical', 'emergency', 'alert')
    ", [$yesterday]);

    echo "   ✅ Security events logged: " . ($errors['count'] ?? 0) . "\n";

    // 5. Store analytics in database (if you have an analytics table)
    // Uncomment if you want to persist analytics
    /*
    $db->execute("
        INSERT INTO daily_analytics
        (date, active_users, new_users, total_requests, avg_load_time, errors, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON CONFLICT (id) DO UPDATE SET
            active_users = EXCLUDED.active_users,
            new_users = EXCLUDED.new_users,
            total_requests = EXCLUDED.total_requests,
            avg_load_time = EXCLUDED.avg_load_time,
            errors = EXCLUDED.errors
    ", [
        $yesterday,
        $activeUsers['count'] ?? 0,
        $newUsers['count'] ?? 0,
        $performance['total_requests'] ?? 0,
        $performance['avg_load_time'] ?? 0,
        $errors['count'] ?? 0
    ]);
    */

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✅ Analytics generation completed!\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";
    echo "🕐 Completed at: " . date('Y-m-d H:i:s') . "\n";

    Logger::info('Daily analytics generated', [
        'date' => $yesterday,
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Execution time: {$executionTime}ms\n";

    Logger::error('Daily analytics generation failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
