#!/usr/bin/env php
<?php
/**
 * DM Message Cleanup Cron Job
 *
 * ENTERPRISE ARCHITECTURE:
 * This script runs periodically to physically delete expired DM messages.
 * Messages have a 1-hour TTL (expires_at column) for privacy protection.
 *
 * SCHEDULE: Run every 15 minutes via cron
 * 0,15,30,45 * * * * php /var/www/need2talk/scripts/cron-dm-cleanup.php
 *
 * WHY PHYSICAL DELETE:
 * - Privacy: Ensures messages are truly gone after expiration
 * - Performance: Keeps table size manageable
 * - Compliance: GDPR data minimization
 *
 * ENTERPRISE V3.1 (2025-12-05):
 * - Added audio file cleanup from CDN before database deletion
 * - Audio files stored on AWS S3 (eu-south-1) are deleted first
 * - Prevents orphaned files on S3
 *
 * @package Need2Talk\Scripts
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-03
 */

declare(strict_types=1);

// Load bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;
use Need2Talk\Services\Chat\DMAudioService;

// Configuration
const BATCH_SIZE = 1000;  // Delete in batches to avoid long locks
const MAX_BATCHES = 100;  // Safety limit: max 100k messages per run
const AUDIO_BATCH_SIZE = 100;  // Audio cleanup batch size (smaller due to S3 API calls)

echo "========================================\n";
echo "DM Message Cleanup - Started " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

try {
    $db = db();
    $totalDeleted = 0;
    $batchCount = 0;

    // =========================================================================
    // ENTERPRISE V3.1: Clean up audio files from CDN FIRST
    // Must happen before database deletion to avoid orphaned files
    // =========================================================================
    echo "\n-- Phase 1: Audio CDN Cleanup --\n";

    $audioService = new DMAudioService();
    $audioStats = $audioService->cleanupExpiredAudio(AUDIO_BATCH_SIZE);

    if ($audioStats['processed'] > 0) {
        echo "  Audio files processed: {$audioStats['processed']}\n";
        echo "  Audio files deleted: {$audioStats['deleted']}\n";
        echo "  Audio files failed: {$audioStats['failed']}\n";
    } else {
        echo "  No expired audio files to cleanup\n";
    }

    // =========================================================================
    // Phase 2: Delete expired text messages from database
    // =========================================================================
    echo "\n-- Phase 2: Database Cleanup --\n";

    // ENTERPRISE V10.56 (2025-12-07): Soft-delete expired messages
    // Using UPDATE deleted_at instead of DELETE triggers the PostgreSQL trigger
    // trg_dm_message_deleted which automatically decrements unread counters
    // for any unread messages that are being expired
    while ($batchCount < MAX_BATCHES) {
        // Soft-delete expired messages (triggers counter update for unread ones)
        $softDeleted = $db->execute(
            "UPDATE direct_messages
             SET deleted_at = NOW()
             WHERE id IN (
                 SELECT id FROM direct_messages
                 WHERE expires_at IS NOT NULL
                   AND expires_at < NOW()
                   AND deleted_at IS NULL
                 LIMIT ?
             )",
            [BATCH_SIZE]
        );

        if ($softDeleted === 0) {
            break; // No more expired messages
        }

        $totalDeleted += $softDeleted;
        $batchCount++;

        echo "  Batch {$batchCount}: Soft-deleted {$softDeleted} messages\n";

        // Short pause between batches to reduce lock contention
        usleep(100000); // 100ms
    }

    // Phase 2b: Hard-delete old soft-deleted messages (older than 7 days)
    // These are messages already processed by triggers, safe to remove
    $hardDeleted = $db->execute(
        "DELETE FROM direct_messages
         WHERE deleted_at IS NOT NULL
           AND deleted_at < NOW() - INTERVAL '7 days'"
    );
    if ($hardDeleted > 0) {
        echo "  Hard-deleted {$hardDeleted} old soft-deleted messages\n";
    }

    echo "\n========================================\n";
    echo "Total deleted: {$totalDeleted} messages\n";
    echo "Batches processed: {$batchCount}\n";
    echo "Audio cleaned: {$audioStats['deleted']}\n";
    echo "========================================\n";

    // Log result
    if ($totalDeleted > 0 || $audioStats['deleted'] > 0) {
        Logger::info('DM cleanup completed', [
            'deleted_count' => $totalDeleted,
            'batches' => $batchCount,
            'audio_deleted' => $audioStats['deleted'],
            'audio_failed' => $audioStats['failed'],
        ]);
    }

    // Also cleanup orphaned conversations (no messages left, created > 24h ago)
    $orphanedConvs = $db->execute(
        "DELETE FROM direct_conversations dc
         WHERE NOT EXISTS (
             SELECT 1 FROM direct_messages dm
             WHERE dm.conversation_id = dc.id
               AND (dm.expires_at IS NULL OR dm.expires_at > NOW())
         )
         AND dc.created_at < NOW() - INTERVAL '24 hours'
         AND dc.last_message_at IS NULL"
    );

    if ($orphanedConvs > 0) {
        echo "Deleted {$orphanedConvs} orphaned conversations\n";
    }

    exit(0);

} catch (\Exception $e) {
    Logger::error('DM cleanup failed', ['error' => $e->getMessage()]);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
