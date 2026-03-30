#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Monthly Performance Report Generator
 *
 * Generates comprehensive monthly report with:
 * - User growth statistics
 * - Performance trends
 * - System health metrics
 * - Resource utilization
 *
 * PERFORMANCE: Aggregates 30 days of data efficiently
 * SCALABILITY: Handles millions of data points
 *
 * Schedule: 0 8 1 * * (1st of every month at 8 AM)
 *
 * Usage:
 *   php scripts/crons/generate-monthly-report.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/generate-monthly-report.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

$startTime = microtime(true);

Logger::info('CRON: Monthly report generation started', [
    'script' => basename(__FILE__)
]);

echo "📊 ENTERPRISE: Monthly Performance Report Generator\n";
echo str_repeat('=', 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = db();

    // Get last month's date range
    $lastMonth = date('Y-m', strtotime('-1 month'));
    $startDate = $lastMonth . '-01';
    $endDate = date('Y-m-t', strtotime($startDate)); // Last day of month

    echo "📅 Generating report for: {$lastMonth}\n";
    echo "📅 Period: {$startDate} to {$endDate}\n\n";

    // 1. User Growth
    echo "⏳ Calculating user growth...\n";
    $userGrowth = $db->findOne("
        SELECT
            COUNT(*) as new_users,
            (SELECT COUNT(*) FROM users WHERE created_at < ?) as users_before,
            (SELECT COUNT(*) FROM users WHERE created_at <= ?) as users_after
        FROM users
        WHERE created_at >= ? AND created_at <= ?
    ", [$startDate, $endDate, $startDate, $endDate]);

    $growthRate = $userGrowth['users_before'] > 0
        ? round(($userGrowth['new_users'] / $userGrowth['users_before']) * 100, 2)
        : 0;

    echo "   ✅ New users: " . ($userGrowth['new_users'] ?? 0) . "\n";
    echo "   ✅ Growth rate: {$growthRate}%\n";

    // 2. Activity Metrics
    echo "⏳ Calculating activity metrics...\n";
    // Convert dates to Unix timestamps (last_activity is INTEGER)
    $startTimestamp = strtotime($startDate);
    $endTimestamp = strtotime($endDate . ' 23:59:59');

    $activity = $db->findOne("
        SELECT
            COUNT(DISTINCT user_id) as active_users,
            COUNT(DISTINCT CAST(to_timestamp(last_activity) AS date)) as active_days
        FROM sessions
        WHERE last_activity >= ? AND last_activity <= ?
    ", [$startTimestamp, $endTimestamp]);

    echo "   ✅ Active users: " . ($activity['active_users'] ?? 0) . "\n";
    echo "   ✅ Active days: " . ($activity['active_days'] ?? 0) . "\n";

    // 3. Performance Trends
    echo "⏳ Calculating performance trends...\n";
    $performance = $db->findOne("
        SELECT
            COUNT(*) as total_requests,
            ROUND(AVG(page_load_time)) as avg_load_time,
            ROUND(MIN(page_load_time)) as min_load_time,
            ROUND(MAX(page_load_time)) as max_load_time,
            SUM(CASE WHEN page_load_time < 50 THEN 1 ELSE 0 END) as fast_requests,
            SUM(CASE WHEN page_load_time BETWEEN 50 AND 200 THEN 1 ELSE 0 END) as medium_requests,
            SUM(CASE WHEN page_load_time > 200 THEN 1 ELSE 0 END) as slow_requests
        FROM enterprise_performance_metrics
        WHERE created_at >= ? AND created_at <= ?
    ", [$startDate, $endDate . ' 23:59:59']);

    echo "   ✅ Total requests: " . ($performance['total_requests'] ?? 0) . "\n";
    echo "   ✅ Avg load time: " . ($performance['avg_load_time'] ?? 0) . "ms\n";
    echo "   ✅ Fast requests (<50ms): " . ($performance['fast_requests'] ?? 0) . "\n";

    // 4. Error Analysis (from security events)
    echo "⏳ Calculating error metrics...\n";
    $errors = $db->query("
        SELECT
            level,
            COUNT(*) as count
        FROM security_events
        WHERE created_at >= ? AND created_at <= ?
        AND level IN ('error', 'critical', 'warning', 'emergency', 'alert')
        GROUP BY level
    ", [$startDate, $endDate . ' 23:59:59']);

    if (!empty($errors)) {
        foreach ($errors as $error) {
            echo "   ✅ {$error['level']}: {$error['count']}\n";
        }
    } else {
        echo "   ✅ No errors logged in this period\n";
    }

    // 5. Store monthly report (if you have a reports table)
    // Uncomment if you want to persist reports
    /*
    $db->execute("
        INSERT INTO monthly_reports
        (month, new_users, growth_rate, active_users, total_requests,
         avg_load_time, errors, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON CONFLICT (id) DO UPDATE SET
            new_users = EXCLUDED.new_users,
            growth_rate = EXCLUDED.growth_rate,
            active_users = EXCLUDED.active_users,
            total_requests = EXCLUDED.total_requests,
            avg_load_time = EXCLUDED.avg_load_time,
            errors = EXCLUDED.errors
    ", [
        $lastMonth,
        $userGrowth['new_users'] ?? 0,
        $growthRate,
        $activity['active_users'] ?? 0,
        $performance['total_requests'] ?? 0,
        $performance['avg_load_time'] ?? 0,
        array_sum(array_column($errors, 'count'))
    ]);
    */

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✅ Monthly report generation completed!\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";
    echo "🕐 Completed at: " . date('Y-m-d H:i:s') . "\n";

    Logger::info('Monthly report generated', [
        'month' => $lastMonth,
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Execution time: {$executionTime}ms\n";

    Logger::error('Monthly report generation failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
