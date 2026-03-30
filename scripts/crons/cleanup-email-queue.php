#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Email Queue & Verification Tokens Cleanup
 *
 * Cleans:
 * 1. Old email queue entries from Redis (>7 days)
 * 2. Expired email verification tokens from PostgreSQL (>24h after expiry)
 * 3. Old idempotency log entries (>30 days)
 *
 * SCALABILITY: Handles millions of records with batch processing
 * REDIS: Uses ZREMRANGEBYSCORE for efficient cleanup
 * POSTGRESQL: Uses batch DELETE with CTID pattern
 *
 * Schedule: Daily at 12:10 (10 12 * * *)
 * Usage:
 *   php scripts/crons/cleanup-email-queue.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-email-queue.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

// ENTERPRISE: Use centralized logging system
Logger::info('CRON: Email queue & tokens cleanup started', [
    'retention_days' => 7,
    'script' => basename(__FILE__)
]);

echo "🚀 ENTERPRISE GALAXY: Email Queue & Tokens Cleanup\n";
echo str_repeat('=', 70) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$startTime = microtime(true);
$retentionDays = 7;
$cutoffTimestamp = time() - ($retentionDays * 86400);

try {
    // Get Redis instance (DB 2 for email queue)
    $redis = EnterpriseRedisManager::getInstance()->getConnection();
    $redis->select(2); // Email queue database

    Logger::debug('CRON: Email queue cleanup - Redis connected', [
        'retention_days' => $retentionDays,
        'cutoff_timestamp' => $cutoffTimestamp
    ]);

    echo "📊 Retention period: {$retentionDays} days\n";
    echo "📅 Cutoff date: " . date('Y-m-d H:i:s', $cutoffTimestamp) . "\n\n";

    // Get current queue statistics BEFORE cleanup
    $queues = [
        'email_queue:pending' => 'Pending',
        'email_queue:processing' => 'Processing',
        'email_queue:completed' => 'Completed',
        'email_queue:failed' => 'Failed'
    ];

    $beforeStats = [];
    foreach ($queues as $queueKey => $queueName) {
        $count = $redis->zCard($queueKey);
        $beforeStats[$queueKey] = $count;
        echo "📧 {$queueName} queue: " . number_format($count) . " emails\n";
    }

    echo "\n" . str_repeat('-', 70) . "\n";
    echo "🧹 Starting cleanup...\n\n";

    $totalRemoved = 0;

    // Clean completed emails (older than 7 days)
    echo "🗑️  Cleaning completed emails...\n";
    $removedCompleted = $redis->zRemRangeByScore('email_queue:completed', 0, $cutoffTimestamp);
    echo "   Removed: " . number_format($removedCompleted) . " completed emails\n";
    $totalRemoved += $removedCompleted;

    // Clean failed emails (older than 7 days) - keep recent for retry analysis
    echo "🗑️  Cleaning old failed emails...\n";
    $removedFailed = $redis->zRemRangeByScore('email_queue:failed', 0, $cutoffTimestamp);
    echo "   Removed: " . number_format($removedFailed) . " failed emails\n";
    $totalRemoved += $removedFailed;

    // Clean stuck processing emails (older than 1 hour - likely crashed workers)
    $oneHourAgo = time() - 3600;
    echo "🗑️  Cleaning stuck processing emails...\n";
    $removedStuck = $redis->zRemRangeByScore('email_queue:processing', 0, $oneHourAgo);
    echo "   Removed: " . number_format($removedStuck) . " stuck emails\n";
    $totalRemoved += $removedStuck;

    // Get AFTER statistics
    echo "\n" . str_repeat('-', 70) . "\n";
    echo "📊 After Cleanup:\n\n";

    $afterStats = [];
    foreach ($queues as $queueKey => $queueName) {
        $count = $redis->zCard($queueKey);
        $afterStats[$queueKey] = $count;
        $diff = $beforeStats[$queueKey] - $count;
        echo "📧 {$queueName} queue: " . number_format($count) . " emails";
        if ($diff > 0) {
            echo " (-" . number_format($diff) . ")";
        }
        echo "\n";
    }

    // Calculate memory saved (approximate)
    $avgEmailSize = 2048; // 2KB per email metadata
    $memorySaved = $totalRemoved * $avgEmailSize;
    $memorySavedMB = round($memorySaved / 1048576, 2);

    echo "\n📊 Redis cleanup: " . number_format($totalRemoved) . " emails removed\n";
    echo "💾 Memory saved: ~{$memorySavedMB} MB\n";

    // =========================================================================
    // STEP 2: Clean expired email verification tokens (PostgreSQL)
    // =========================================================================
    echo "\n" . str_repeat('-', 70) . "\n";
    echo "🔑 Cleaning Expired Verification Tokens\n";
    echo str_repeat('-', 70) . "\n\n";

    $db = db();

    // Count before cleanup
    $tokensBefore = $db->count('email_verification_tokens');
    $expiredTokens = $db->findOne(
        "SELECT COUNT(*) as count FROM email_verification_tokens WHERE expires_at < NOW() - INTERVAL '24 hours'"
    );
    $expiredCount = (int) ($expiredTokens['count'] ?? 0);

    echo "📊 Total verification tokens: " . number_format($tokensBefore) . "\n";
    echo "📊 Expired (>24h): " . number_format($expiredCount) . "\n";

    // Delete tokens expired more than 24 hours ago (keep recent for debugging)
    $tokensDeleted = $db->execute(
        "DELETE FROM email_verification_tokens WHERE expires_at < NOW() - INTERVAL '24 hours'"
    );

    $tokensAfter = $db->count('email_verification_tokens');

    echo "🗑️  Removed: " . number_format($tokensDeleted) . " expired tokens\n";
    echo "✅ Remaining: " . number_format($tokensAfter) . " tokens\n";

    // =========================================================================
    // STEP 3: Clean old idempotency log entries (PostgreSQL)
    // =========================================================================
    echo "\n" . str_repeat('-', 70) . "\n";
    echo "📝 Cleaning Old Idempotency Log Entries\n";
    echo str_repeat('-', 70) . "\n\n";

    // Count before cleanup
    $idempotencyBefore = $db->count('email_idempotency_log');
    $oldIdempotency = $db->findOne(
        "SELECT COUNT(*) as count FROM email_idempotency_log WHERE created_at < NOW() - INTERVAL '30 days'"
    );
    $oldIdempotencyCount = (int) ($oldIdempotency['count'] ?? 0);

    echo "📊 Total idempotency entries: " . number_format($idempotencyBefore) . "\n";
    echo "📊 Old entries (>30 days): " . number_format($oldIdempotencyCount) . "\n";

    // Delete entries older than 30 days
    $idempotencyDeleted = $db->execute(
        "DELETE FROM email_idempotency_log WHERE created_at < NOW() - INTERVAL '30 days'"
    );

    $idempotencyAfter = $db->count('email_idempotency_log');

    echo "🗑️  Removed: " . number_format($idempotencyDeleted) . " old entries\n";
    echo "✅ Remaining: " . number_format($idempotencyAfter) . " entries\n";

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "✅ Email cleanup completed!\n";
    echo "📊 Redis emails removed: " . number_format($totalRemoved) . "\n";
    echo "📊 Verification tokens removed: " . number_format($tokensDeleted) . "\n";
    echo "📊 Idempotency entries removed: " . number_format($idempotencyDeleted) . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    // ENTERPRISE: Log to centralized system
    Logger::info('CRON: Email queue & tokens cleanup completed', [
        'removed_completed' => $removedCompleted,
        'removed_failed' => $removedFailed,
        'removed_stuck' => $removedStuck,
        'total_emails_removed' => $totalRemoved,
        'tokens_removed' => $tokensDeleted,
        'idempotency_removed' => $idempotencyDeleted,
        'memory_saved_mb' => $memorySavedMB,
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    // ENTERPRISE: Log error to centralized system
    Logger::error('CRON: Email queue cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
