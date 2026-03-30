#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Performance Metrics Summary Updater
 *
 * Cron job to aggregate performance metrics into hourly summary table
 * Runs every 60 minutes to update performance_metrics_summary
 *
 * PERFORMANCE: Reduces query time from 50-100ms to <5ms for dashboard
 * SCALABILITY: Handles millions of raw metrics efficiently
 *
 * Schedule: 0 * * * * (every hour at minute 0)
 *
 * Usage:
 *   php scripts/crons/update-performance-summary.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/update-performance-summary.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

$startTime = microtime(true);

Logger::info('CRON: Performance summary update started', [
    'script' => basename(__FILE__)
]);

echo "🚀 ENTERPRISE: Performance Summary Updater\n";
echo str_repeat('=', 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = db();

    // Get the last hour that was processed
    $lastProcessed = $db->findOne("
        SELECT MAX(time_bucket) as last_bucket
        FROM performance_metrics_summary
    ");

    $startBucket = $lastProcessed['last_bucket'] ?? null;

    if ($startBucket) {
        // Process from last bucket + 1 hour
        $startTime = strtotime($startBucket) + 3600;
        echo "📊 Last processed bucket: {$startBucket}\n";
        echo "📊 Starting from: " . date('Y-m-d H:00:00', $startTime) . "\n\n";
    } else {
        // First run: process from oldest record
        $oldest = $db->findOne("
            SELECT MIN(created_at) as oldest
            FROM enterprise_performance_metrics
        ");

        if (!$oldest || !$oldest['oldest']) {
            echo "ℹ️  No metrics to process\n";
            exit(0);
        }

        $startTime = strtotime(date('Y-m-d H:00:00', strtotime($oldest['oldest'])));
        echo "📊 First run - processing from: " . date('Y-m-d H:00:00', $startTime) . "\n\n";
    }

    // Process up to current hour - 1 (don't process incomplete hour)
    $currentHour = strtotime(date('Y-m-d H:00:00'));
    $endTime = $currentHour - 3600; // Process up to 1 hour ago

    if ($startTime >= $currentHour) {
        echo "✅ All hours up to date!\n";
        echo "📊 Current hour (" . date('Y-m-d H:00', $currentHour) . ") is still incomplete\n";
        exit(0);
    }

    $hoursProcessed = 0;
    $metricsAggregated = 0;

    // Process each hour
    for ($bucket = $startTime; $bucket <= $endTime; $bucket += 3600) {
        $bucketStart = date('Y-m-d H:00:00', $bucket);
        $bucketEnd = date('Y-m-d H:59:59', $bucket);

        echo "⏳ Processing hour: {$bucketStart}\n";

        // Aggregate metrics for this hour
        $aggregated = $db->query("
            SELECT
                page_url,
                ? as time_bucket,
                COUNT(*) as total_requests,
                ROUND(AVG(page_load_time)) as avg_page_load_time,
                ROUND(AVG(server_response_time)) as avg_server_response_time,
                ROUND(AVG(dom_ready_time)) as avg_dom_ready_time,
                ROUND(AVG(first_byte_time)) as avg_first_byte_time,
                ROUND(AVG(dns_lookup_time)) as avg_dns_lookup_time,
                ROUND(AVG(connect_time)) as avg_connect_time,
                MIN(page_load_time) as min_page_load_time,
                MAX(page_load_time) as max_page_load_time,
                SUM(CASE WHEN page_load_time < 50 THEN 1 ELSE 0 END) as fast_requests,
                SUM(CASE WHEN page_load_time BETWEEN 50 AND 200 THEN 1 ELSE 0 END) as medium_requests,
                SUM(CASE WHEN page_load_time > 200 THEN 1 ELSE 0 END) as slow_requests
            FROM enterprise_performance_metrics
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY page_url
        ", [$bucketStart, $bucketStart, $bucketEnd]);

        if (empty($aggregated)) {
            echo "   ℹ️  No metrics in this hour\n";
            continue;
        }

        // Insert aggregated data
        foreach ($aggregated as $row) {
            $db->execute("
                INSERT INTO performance_metrics_summary
                (page_url, time_bucket, total_requests,
                 avg_page_load_time, avg_server_response_time, avg_dom_ready_time,
                 avg_first_byte_time, avg_dns_lookup_time, avg_connect_time,
                 min_page_load_time, max_page_load_time,
                 fast_requests, medium_requests, slow_requests,
                 last_updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON CONFLICT (page_url, time_bucket) DO UPDATE SET
                    total_requests = EXCLUDED.total_requests,
                    avg_page_load_time = EXCLUDED.avg_page_load_time,
                    avg_server_response_time = EXCLUDED.avg_server_response_time,
                    avg_dom_ready_time = EXCLUDED.avg_dom_ready_time,
                    avg_first_byte_time = EXCLUDED.avg_first_byte_time,
                    avg_dns_lookup_time = EXCLUDED.avg_dns_lookup_time,
                    avg_connect_time = EXCLUDED.avg_connect_time,
                    min_page_load_time = EXCLUDED.min_page_load_time,
                    max_page_load_time = EXCLUDED.max_page_load_time,
                    fast_requests = EXCLUDED.fast_requests,
                    medium_requests = EXCLUDED.medium_requests,
                    slow_requests = EXCLUDED.slow_requests,
                    last_updated = NOW()
            ", [
                $row['page_url'],
                $row['time_bucket'],
                $row['total_requests'],
                $row['avg_page_load_time'],
                $row['avg_server_response_time'],
                $row['avg_dom_ready_time'],
                $row['avg_first_byte_time'],
                $row['avg_dns_lookup_time'],
                $row['avg_connect_time'],
                $row['min_page_load_time'],
                $row['max_page_load_time'],
                $row['fast_requests'],
                $row['medium_requests'],
                $row['slow_requests']
            ]);

            $metricsAggregated++;
        }

        echo "   ✅ Aggregated " . count($aggregated) . " page(s)\n";
        $hoursProcessed++;
    }

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✅ Summary update completed!\n";
    echo "📊 Hours processed: {$hoursProcessed}\n";
    echo "📊 Metrics aggregated: {$metricsAggregated}\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";
    echo "🕐 Completed at: " . date('Y-m-d H:i:s') . "\n";

    Logger::info('Performance summary updated', [
        'hours_processed' => $hoursProcessed,
        'metrics_aggregated' => $metricsAggregated,
        'execution_time' => $executionTime
    ]);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Execution time: {$executionTime}ms\n";

    Logger::error('Performance summary update failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
