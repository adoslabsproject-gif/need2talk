#!/usr/bin/env php
<?php

/**
 * 🔥 Cache Warmup Script
 *
 * Warms up critical application caches
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

$startTime = microtime(true);

echo "🔥 ENTERPRISE: Cache Warmup\n";
echo str_repeat('=', 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = db();

    echo "⏳ Warming up critical caches...\n\n";

    // 1. Warm up app settings cache (configurazioni applicazione)
    $appSettings = $db->query("SELECT * FROM app_settings LIMIT 100", [], ['cache' => true]);
    echo "   ✅ App settings cache warmed (" . count($appSettings) . " settings)\n";

    // 2. Warm up admin settings cache
    $adminSettings = $db->query("SELECT * FROM admin_settings LIMIT 100", [], ['cache' => true]);
    echo "   ✅ Admin settings cache warmed (" . count($adminSettings) . " settings)\n";

    // 3. Warm up active users cache (top 100 utenti attivi)
    $activeUsers = $db->query("
        SELECT id, email, created_at
        FROM users
        WHERE email_verified = TRUE
        ORDER BY created_at DESC
        LIMIT 100
    ", [], ['cache' => true]);
    echo "   ✅ Active users cache warmed (" . count($activeUsers) . " users)\n";

    // 4. Warm up emotions cache (emozioni disponibili)
    $emotions = $db->query("SELECT * FROM emotions", [], ['cache' => true]);
    echo "   ✅ Emotions cache warmed (" . count($emotions) . " emotions)\n";

    // 5. Warm up performance metrics summary
    $perfMetrics = $db->query("
        SELECT * FROM performance_metrics_summary
        ORDER BY last_updated DESC
        LIMIT 50
    ", [], ['cache' => true]);
    echo "   ✅ Performance metrics cache warmed (" . count($perfMetrics) . " metrics)\n";

    // 6. Warm up cron jobs cache
    $cronJobs = $db->query("SELECT * FROM cron_jobs", [], ['cache' => true]);
    echo "   ✅ Cron jobs cache warmed (" . count($cronJobs) . " jobs)\n";

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✅ Cache warmup completed!\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    Logger::info('Cache warmup completed', [
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n❌ ERROR: " . $e->getMessage() . "\n";

    Logger::error('Cache warmup failed', [
        'error' => $e->getMessage(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
