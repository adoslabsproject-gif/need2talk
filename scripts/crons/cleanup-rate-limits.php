#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Rate Limit Cleanup
 *
 * Cleans old rate limit entries from Redis DB 3
 * Removes expired rate limit keys to prevent Redis memory bloat
 *
 * SCALABILITY: Handles millions of rate limit entries
 * REDIS: Scans and removes expired keys from rate limit database
 *
 * Schedule: Daily at 5 AM (0 5 * * *)
 * Usage:
 *   php scripts/crons/cleanup-rate-limits.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-rate-limits.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

Logger::info('CRON: Rate limit cleanup started', [
    'script' => basename(__FILE__)
]);

echo "🚀 ENTERPRISE GALAXY: Rate Limit Cleanup\n";
echo str_repeat('=', 70) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$startTime = microtime(true);

try {
    // Get Redis instance (DB 3 for rate limiting)
    $redis = EnterpriseRedisManager::getInstance()->getConnection();
    $redis->select(3); // Rate limiting database

    echo "🔴 Redis DB 3: Rate Limiting\n\n";

    // Rate limit key patterns
    $patterns = [
        'rate_limit:*' => 'Rate Limit Counters',
        'rate_limit_ban:*' => 'Rate Limit Bans',
        'rate_limit_violations:*' => 'Rate Limit Violations',
        'progressive_penalty:*' => 'Progressive Penalties',
    ];

    $totalKeysBefore = 0;
    $totalKeysRemoved = 0;
    $batchSize = 1000;

    foreach ($patterns as $pattern => $description) {
        echo str_repeat('-', 70) . "\n";
        echo "📊 {$description}\n";
        echo "   Pattern: {$pattern}\n";
        echo str_repeat('-', 70) . "\n";

        $cursor = 0;
        $keysFound = 0;
        $keysRemoved = 0;

        do {
            $result = $redis->scan($cursor, $pattern, $batchSize);
            if ($result === false) break;

            list($cursor, $keys) = $result;
            $keysFound += count($keys);

            foreach ($keys as $key) {
                $ttl = $redis->ttl($key);

                // Remove if:
                // - No TTL set (eternal key, shouldn't happen)
                // - Key doesn't exist (-2)
                // - Already expired but not yet removed by Redis
                if ($ttl === -1 || $ttl === -2 || $ttl === 0) {
                    $redis->del($key);
                    $keysRemoved++;
                }
            }

        } while ($cursor !== 0);

        $keysRemaining = $keysFound - $keysRemoved;

        echo "   📊 Found: " . number_format($keysFound) . " keys\n";
        echo "   🗑️  Removed: " . number_format($keysRemoved) . " expired keys\n";
        echo "   ✅ Remaining: " . number_format($keysRemaining) . " active keys\n\n";

        $totalKeysBefore += $keysFound;
        $totalKeysRemoved += $keysRemoved;
    }

    // Get database info
    $dbInfo = $redis->info('keyspace');
    $dbSize = $redis->dbSize();

    echo str_repeat('=', 70) . "\n";
    echo "📊 Database Statistics\n";
    echo str_repeat('=', 70) . "\n";
    echo "Total keys in DB 3: " . number_format($dbSize) . "\n";
    echo "Memory usage: " . $redis->info('memory')['used_memory_human'] . "\n\n";

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo str_repeat('=', 70) . "\n";
    echo "✅ Rate limit cleanup completed!\n";
    echo "📊 Total keys scanned: " . number_format($totalKeysBefore) . "\n";
    echo "🗑️  Total keys removed: " . number_format($totalKeysRemoved) . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    // Log to system
    Logger::info('Rate limit cleanup completed', [
        'total_scanned' => $totalKeysBefore,
        'total_removed' => $totalKeysRemoved,
        'db_size' => $dbSize,
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    Logger::error('Rate limit cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
