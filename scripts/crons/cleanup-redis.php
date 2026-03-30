#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Redis Cleanup Script
 *
 * Cleans expired and orphaned Redis keys:
 * - Expired session keys
 * - Old rate limit entries
 * - Stale cache entries
 * - Orphaned locks
 *
 * PERFORMANCE: Prevents Redis memory bloat
 * SCALABILITY: Handles millions of keys efficiently
 *
 * Schedule: 0 4 * * * (daily at 4 AM)
 *
 * Usage:
 *   php scripts/crons/cleanup-redis.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-redis.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

$startTime = microtime(true);

Logger::info('CRON: Redis cleanup started', [
    'script' => basename(__FILE__)
]);

echo "🔴 ENTERPRISE: Redis Cleanup Script\n";
echo str_repeat('=', 60) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Connect to Redis
    $redis = new Redis();
    $redis->connect(
        env('REDIS_HOST', 'redis'),
        (int) env('REDIS_PORT', 6379)
    );

    // ENTERPRISE FIX: Authenticate with password if configured
    $redisPassword = env('REDIS_PASSWORD', '');
    if ($redisPassword) {
        $redis->auth($redisPassword);
    }

    $totalDeleted = 0;

    // 1. Clean Database 0 (General cache + locks)
    echo "⏳ Cleaning Redis DB 0 (cache + locks)...\n";
    $redis->select(0);

    // Clean old locks (older than 1 hour)
    $lockPattern = 'cron:lock:*';
    $locks = $redis->keys($lockPattern);
    $deletedLocks = 0;

    foreach ($locks as $lock) {
        $ttl = $redis->ttl($lock);
        if ($ttl === -1) { // No expiration set (orphaned lock)
            $redis->del($lock);
            $deletedLocks++;
        }
    }

    echo "   ✅ Deleted {$deletedLocks} orphaned locks\n";
    $totalDeleted += $deletedLocks;

    // 2. Clean Database 1 (L1 Cache + Sessions)
    echo "⏳ Cleaning Redis DB 1 (L1 cache + sessions)...\n";
    $redis->select(1);

    // Sessions are auto-expired by Redis TTL
    // L1 cache is auto-expired (5min TTL)
    $dbSize = $redis->dbSize();
    echo "   ℹ️  Current DB size: {$dbSize} keys (auto-expired)\n";

    // 3. Clean Database 2 (Email Queue)
    echo "⏳ Cleaning Redis DB 2 (email queue)...\n";
    $redis->select(2);

    // Clean completed emails older than 24 hours
    $oneDayAgo = time() - 86400;
    $deletedCompleted = $redis->zRemRangeByScore('email_queue:completed', '-inf', $oneDayAgo);

    echo "   ✅ Deleted {$deletedCompleted} old completed emails\n";
    $totalDeleted += $deletedCompleted;

    // Clean failed emails older than 7 days
    $sevenDaysAgo = time() - (86400 * 7);
    $deletedFailed = $redis->zRemRangeByScore('email_queue:failed', '-inf', $sevenDaysAgo);

    echo "   ✅ Deleted {$deletedFailed} old failed emails\n";
    $totalDeleted += $deletedFailed;

    // 4. Clean Database 3 (Rate Limiting)
    echo "⏳ Cleaning Redis DB 3 (rate limiting)...\n";
    $redis->select(3);

    // Rate limit entries are auto-expired by TTL
    $dbSize = $redis->dbSize();
    echo "   ℹ️  Current DB size: {$dbSize} keys (auto-expired)\n";

    // 5. Redis Memory Info
    echo "\n📊 Redis Memory Statistics:\n";
    $info = $redis->info('memory');

    $usedMemory = isset($info['used_memory_human']) ? $info['used_memory_human'] : 'N/A';
    $peakMemory = isset($info['used_memory_peak_human']) ? $info['used_memory_peak_human'] : 'N/A';
    $fragmentation = isset($info['mem_fragmentation_ratio']) ? $info['mem_fragmentation_ratio'] : 'N/A';

    echo "   Memory used: {$usedMemory}\n";
    echo "   Peak memory: {$peakMemory}\n";
    echo "   Fragmentation ratio: {$fragmentation}\n";

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✅ Redis cleanup completed!\n";
    echo "🗑️  Total keys deleted: {$totalDeleted}\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";
    echo "🕐 Completed at: " . date('Y-m-d H:i:s') . "\n";

    Logger::info('Redis cleanup completed', [
        'keys_deleted' => $totalDeleted,
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Execution time: {$executionTime}ms\n";

    Logger::error('Redis cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
