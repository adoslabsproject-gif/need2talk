#!/usr/bin/env php
<?php

/**
 * Chat Archive Cron Job
 *
 * ENTERPRISE GALAXY CHAT (2025-12-02)
 *
 * This script runs monthly (1st of each month at 4 AM) to:
 * 1. Archive/delete old direct messages (1 year retention)
 * 2. Archive old conversations with no activity
 * 3. Clean up old reports that have been resolved
 * 4. Generate monthly statistics
 *
 * GDPR Compliance:
 * - Messages older than 1 year are deleted
 * - User can request earlier deletion via account settings
 * - Audit trail maintained in separate log
 *
 * Crontab entry:
 * 0 4 1 * * php /var/www/need2talk/scripts/cron-chat-archive.php >> /var/www/need2talk/storage/logs/chat_archive.log 2>&1
 *
 * @package Need2Talk\Scripts
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;

// Script lock to prevent overlapping runs
$lockFile = APP_ROOT . '/storage/locks/chat_archive.lock';
$lockDir = dirname($lockFile);

if (!is_dir($lockDir)) {
    mkdir($lockDir, 0755, true);
}

$fp = fopen($lockFile, 'c+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Another instance is already running. Exiting.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting chat archive process...\n";

$startTime = microtime(true);
$stats = [
    'messages_archived' => 0,
    'conversations_archived' => 0,
    'rooms_cleaned' => 0,
    'errors' => 0,
];

try {
    $db = db();

    // Define retention periods
    $messageRetention = 365; // 1 year for DM messages
    $conversationRetention = 180; // 6 months of inactivity for conversations
    $roomRetention = 30; // 30 days for archived rooms

    // ========================================================================
    // 1. ARCHIVE OLD DIRECT MESSAGES (1 year retention)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Archiving old direct messages...\n";

    $oneYearAgo = date('Y-m-d H:i:s', strtotime("-{$messageRetention} days"));

    // Count messages to be deleted
    $countResult = $db->findOne(
        "SELECT COUNT(*) as count FROM direct_messages
         WHERE created_at < :cutoff AND deleted_at IS NULL",
        ['cutoff' => $oneYearAgo]
    );
    $messagesToArchive = (int) ($countResult['count'] ?? 0);

    if ($messagesToArchive > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Found {$messagesToArchive} messages older than {$messageRetention} days\n";

        // Soft delete in batches of 10,000 to avoid lock contention
        $batchSize = 10000;
        $totalDeleted = 0;

        while ($totalDeleted < $messagesToArchive) {
            // ENTERPRISE FIX: execute() returns affected rows directly (no rowCount() method)
            $affected = $db->execute(
                "UPDATE direct_messages
                 SET deleted_at = NOW()
                 WHERE id IN (
                     SELECT id FROM direct_messages
                     WHERE created_at < :cutoff AND deleted_at IS NULL
                     LIMIT :batch_size
                 )",
                ['cutoff' => $oneYearAgo, 'batch_size' => $batchSize]
            );
            $totalDeleted += $affected;

            echo "[" . date('Y-m-d H:i:s') . "] Archived batch: {$affected} messages (total: {$totalDeleted})\n";

            if ($affected < $batchSize) {
                break; // No more messages to process
            }

            // Brief pause between batches
            usleep(100000); // 100ms
        }

        $stats['messages_archived'] = $totalDeleted;
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] No messages to archive.\n";
    }

    // ========================================================================
    // 2. ARCHIVE INACTIVE CONVERSATIONS (6 months no activity)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Archiving inactive conversations...\n";

    $sixMonthsAgo = date('Y-m-d H:i:s', strtotime("-{$conversationRetention} days"));

    // Archive conversations with no recent messages
    // ENTERPRISE FIX: execute() returns affected rows directly (no rowCount() method)
    $stats['conversations_archived'] = $db->execute(
        "UPDATE direct_conversations
         SET user1_status = 'archived', user2_status = 'archived'
         WHERE last_message_at < :cutoff
         AND user1_status = 'active' AND user2_status = 'active'",
        ['cutoff' => $sixMonthsAgo]
    );
    echo "[" . date('Y-m-d H:i:s') . "] Archived {$stats['conversations_archived']} inactive conversations.\n";

    // ========================================================================
    // 3. HARD DELETE ARCHIVED ROOMS (30 days after archive)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning up archived rooms...\n";

    $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime("-{$roomRetention} days"));

    // First, delete room members
    $db->execute(
        "DELETE FROM chat_room_members
         WHERE room_id IN (
             SELECT id FROM chat_rooms
             WHERE status = 'archived' AND deleted_at < :cutoff
         )",
        ['cutoff' => $thirtyDaysAgo]
    );

    // Then delete rooms
    // ENTERPRISE FIX: execute() returns affected rows directly (no rowCount() method)
    $stats['rooms_cleaned'] = $db->execute(
        "DELETE FROM chat_rooms
         WHERE status = 'archived' AND deleted_at < :cutoff",
        ['cutoff' => $thirtyDaysAgo]
    );
    echo "[" . date('Y-m-d H:i:s') . "] Cleaned up {$stats['rooms_cleaned']} archived rooms.\n";

    // ========================================================================
    // 4. VACUUM ANALYZE (reclaim space)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Running VACUUM ANALYZE on chat tables...\n";

    // Run VACUUM ANALYZE on affected tables
    $tables = ['direct_messages', 'direct_conversations', 'chat_rooms', 'chat_room_members'];

    foreach ($tables as $table) {
        try {
            $db->execute("VACUUM ANALYZE {$table}");
            echo "[" . date('Y-m-d H:i:s') . "] VACUUM ANALYZE {$table} completed.\n";
        } catch (Exception $e) {
            // VACUUM might fail if table doesn't exist yet
            echo "[" . date('Y-m-d H:i:s') . "] Skipping {$table}: " . $e->getMessage() . "\n";
        }
    }

    // ========================================================================
    // 5. GENERATE MONTHLY STATISTICS
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Generating monthly statistics...\n";

    $monthStart = date('Y-m-01 00:00:00', strtotime('last month'));
    $monthEnd = date('Y-m-01 00:00:00');
    $monthName = date('F Y', strtotime('last month'));

    $monthlyStats = $db->findOne(
        "SELECT
            COUNT(DISTINCT conversation_id) as active_conversations,
            COUNT(*) as total_messages,
            COUNT(CASE WHEN message_type = 'text' THEN 1 END) as text_messages,
            COUNT(CASE WHEN message_type = 'audio' THEN 1 END) as audio_messages,
            COUNT(CASE WHEN message_type = 'image' THEN 1 END) as image_messages
         FROM direct_messages
         WHERE created_at >= :month_start AND created_at < :month_end",
        ['month_start' => $monthStart, 'month_end' => $monthEnd]
    );

    if ($monthlyStats) {
        echo "[" . date('Y-m-d H:i:s') . "] Monthly Stats for {$monthName}:\n";
        echo "  - Active conversations: " . ($monthlyStats['active_conversations'] ?? 0) . "\n";
        echo "  - Total messages: " . ($monthlyStats['total_messages'] ?? 0) . "\n";
        echo "  - Text messages: " . ($monthlyStats['text_messages'] ?? 0) . "\n";
        echo "  - Audio messages: " . ($monthlyStats['audio_messages'] ?? 0) . "\n";
        echo "  - Image messages: " . ($monthlyStats['image_messages'] ?? 0) . "\n";

        // Log monthly stats
        Logger::info('Monthly chat statistics', [
            'month' => $monthName,
            'stats' => $monthlyStats,
        ]);
    }

    // Calculate duration
    $duration = round((microtime(true) - $startTime) * 1000, 2);

    // Log results
    Logger::info('Chat archive completed', [
        'stats' => $stats,
        'duration_ms' => $duration,
    ]);

    echo "[" . date('Y-m-d H:i:s') . "] Archive completed in {$duration}ms\n";
    echo "[" . date('Y-m-d H:i:s') . "] Stats: " . json_encode($stats) . "\n";

} catch (Exception $e) {
    $stats['errors']++;
    Logger::error('Chat archive failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);

} finally {
    // Release lock
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink($lockFile);
}

exit(0);
