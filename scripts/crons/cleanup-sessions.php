#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Session Cleanup
 *
 * Cleans expired sessions from both Redis and PostgreSQL
 * Sessions are stored in Redis (DB 1) and synced to PostgreSQL for persistence
 *
 * SCALABILITY: Handles millions of sessions with batch processing
 * ARCHITECTURE: Dual cleanup (Redis + PostgreSQL) to prevent data accumulation
 *
 * Schedule: Daily at 4 AM (0 4 * * *)
 * Usage:
 *   php scripts/crons/cleanup-sessions.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-sessions.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

Logger::info('CRON: Session cleanup started', [
    'script' => basename(__FILE__)
]);

echo "🚀 ENTERPRISE GALAXY: Session Cleanup\n";
echo str_repeat('=', 70) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$startTime = microtime(true);
$sessionLifetime = 1440 * 60; // 1440 minutes = 24 hours (from config)
$cutoffTime = time() - $sessionLifetime;
$cutoffDate = date('Y-m-d H:i:s', $cutoffTime);

try {
    // Get Redis instance (DB 1 for sessions)
    $redis = EnterpriseRedisManager::getInstance()->getConnection();
    $redis->select(1); // Sessions + L1 Cache database

    echo "📊 Session lifetime: " . ($sessionLifetime / 3600) . " hours\n";
    echo "📅 Cutoff date: {$cutoffDate}\n\n";

    // STEP 1: Clean Redis sessions
    echo str_repeat('-', 70) . "\n";
    echo "🔴 Redis Session Cleanup\n";
    echo str_repeat('-', 70) . "\n\n";

    $sessionPrefix = 'need2talk:session:';
    $cursor = null;
    $redisSessionsBefore = 0;
    $redisSessionsRemoved = 0;
    $batchSize = 1000;

    // Count all sessions first
    $cursor = 0;
    do {
        $result = $redis->scan($cursor, $sessionPrefix . '*', $batchSize);
        if ($result === false) break;

        list($cursor, $keys) = $result;
        $redisSessionsBefore += count($keys);
    } while ($cursor !== 0);

    echo "📊 Total Redis sessions: " . number_format($redisSessionsBefore) . "\n";

    // Now scan and delete expired sessions
    $cursor = 0;
    do {
        $result = $redis->scan($cursor, $sessionPrefix . '*', $batchSize);
        if ($result === false) break;

        list($cursor, $keys) = $result;

        foreach ($keys as $key) {
            $ttl = $redis->ttl($key);
            // If TTL is -1 (no expiry) or -2 (doesn't exist) or expired, remove
            if ($ttl === -1 || $ttl === -2) {
                $redis->del($key);
                $redisSessionsRemoved++;
            }
        }
    } while ($cursor !== 0);

    $redisSessionsAfter = $redisSessionsBefore - $redisSessionsRemoved;

    echo "🗑️  Removed: " . number_format($redisSessionsRemoved) . " expired sessions\n";
    echo "✅ Remaining: " . number_format($redisSessionsAfter) . " active sessions\n\n";

    // STEP 2: Clean PostgreSQL sessions
    echo str_repeat('-', 70) . "\n";
    echo "💾 PostgreSQL Session Cleanup\n";
    echo str_repeat('-', 70) . "\n\n";

    $db = db();

    // Count before
    $pgSessionsBefore = $db->count('sessions');
    echo "📊 Total PostgreSQL sessions: " . number_format($pgSessionsBefore) . "\n";

    // Delete expired sessions in batches (PostgreSQL CTID pattern)
    $batchSize = 10000;
    $totalDeleted = 0;

    while (true) {
        $deleted = $db->execute(
            "DELETE FROM sessions
             WHERE ctid IN (
                 SELECT ctid
                 FROM sessions
                 WHERE last_activity < :cutoff
                 LIMIT :limit
             )",
            [
                'cutoff' => $cutoffTime, // Unix timestamp
                'limit' => $batchSize
            ]
        );

        $totalDeleted += $deleted;

        if ($deleted === 0) {
            break;
        }

        echo "   Deleted batch: " . number_format($deleted) . " sessions\n";
        usleep(100000); // 100ms delay between batches to avoid table locks
    }

    $pgSessionsAfter = $db->count('sessions');

    echo "🗑️  Total removed: " . number_format($totalDeleted) . " expired sessions\n";
    echo "✅ Remaining: " . number_format($pgSessionsAfter) . " active sessions\n\n";

    // STEP 3: Vacuum and analyze sessions table (PostgreSQL optimization)
    echo str_repeat('-', 70) . "\n";
    echo "⚡ Vacuuming and analyzing sessions table...\n";
    $db->execute("VACUUM ANALYZE sessions");
    echo "✅ Table optimized\n\n";

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo str_repeat('=', 70) . "\n";
    echo "✅ Session cleanup completed!\n";
    echo "📊 Redis sessions removed: " . number_format($redisSessionsRemoved) . "\n";
    echo "📊 PostgreSQL sessions removed: " . number_format($totalDeleted) . "\n";
    echo "📊 Total removed: " . number_format($redisSessionsRemoved + $totalDeleted) . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    // Log to system
    Logger::info('Session cleanup completed', [
        'redis_sessions_removed' => $redisSessionsRemoved,
        'postgres_sessions_removed' => $totalDeleted,
        'total_removed' => $redisSessionsRemoved + $totalDeleted,
        'redis_sessions_remaining' => $redisSessionsAfter,
        'postgres_sessions_remaining' => $pgSessionsAfter,
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    Logger::error('Session cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
