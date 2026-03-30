#!/usr/bin/env php
<?php
/**
 * Journal Trash Cleanup Cron Job (ENTERPRISE V12)
 *
 * ENTERPRISE ARCHITECTURE:
 * This script runs periodically to permanently delete soft-deleted journal entries.
 * Entries in trash have a 30-day grace period before permanent deletion.
 *
 * SCHEDULE: Run daily at 2:00 AM via cron
 * 0 2 * * * php /var/www/need2talk/scripts/cron-journal-trash-cleanup.php
 *
 * V12 CHANGES:
 * - Uses unified `journal_media` table (replaces journal_audio_files)
 * - Handles both audio and photo media
 * - Uses `journal_media_id` FK (replaces journal_audio_id)
 *
 * CLEANUP SEQUENCE (ORDER MATTERS):
 * 1. Delete media files from local storage and AWS S3
 * 2. Delete records from journal_media table
 * 3. Delete records from emotional_journal_entries table
 *
 * WHY THIS ORDER:
 * - Files deleted FIRST to prevent orphaned files
 * - Database deletion follows (FK cascade handles media association)
 * - GDPR compliant: Data truly gone after 30-day retention period
 *
 * PERFORMANCE:
 * - Batch processing (100 entries per batch)
 * - S3 API calls limited to MEDIA_BATCH_SIZE per run
 * - Short pauses between batches to reduce lock contention
 *
 * @package Need2Talk\Scripts
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-07
 * @updated 2025-12-14 V12: Unified journal_media table
 */

declare(strict_types=1);

// Load bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;
use Need2Talk\Services\Storage\S3StorageService;

// Configuration
const BATCH_SIZE = 100;           // Delete entries in batches
const MAX_BATCHES = 50;           // Safety limit: max 5000 entries per run
const MEDIA_BATCH_SIZE = 50;      // Media cleanup batch size (S3 API calls)
const RETENTION_DAYS = 30;        // Days before permanent deletion

echo "========================================\n";
echo "Journal Trash Cleanup V12 - Started " . date('Y-m-d H:i:s') . "\n";
echo "Retention period: " . RETENTION_DAYS . " days\n";
echo "========================================\n";

try {
    $db = db();
    $s3 = new S3StorageService();

    $stats = [
        'entries_deleted' => 0,
        'media_files_deleted' => 0,
        'media_files_failed' => 0,
        's3_files_deleted' => 0,
        's3_files_failed' => 0,
        'local_files_deleted' => 0,
        'batches_processed' => 0,
    ];

    // Get upload path base for local file deletion
    $uploadPathBase = realpath(APP_ROOT . '/' . get_env('UPLOAD_PATH', 'storage/uploads'));

    // =========================================================================
    // V12 Phase 1: Clean up media files (audio + photos) from storage
    // Must happen before database deletion to avoid orphaned files
    // =========================================================================
    echo "\n-- Phase 1: Media Files Cleanup (S3 + Local) --\n";

    // Find expired media files (associated with soft-deleted entries)
    // V12: Uses journal_media table with journal_media_id FK
    $expiredMediaFiles = $db->findMany(
        "SELECT DISTINCT
            jm.id,
            jm.uuid,
            jm.media_type,
            jm.s3_url,
            jm.local_path,
            jm.filename
         FROM journal_media jm
         INNER JOIN emotional_journal_entries ej ON ej.journal_media_id = jm.id
         WHERE ej.deleted_at IS NOT NULL
           AND ej.deleted_at < NOW() - INTERVAL '" . RETENTION_DAYS . " days'
         LIMIT ?",
        [MEDIA_BATCH_SIZE]
    );

    if (count($expiredMediaFiles) > 0) {
        echo "  Found " . count($expiredMediaFiles) . " expired media files to cleanup\n";

        foreach ($expiredMediaFiles as $media) {
            $stats['media_files_deleted']++;

            // Extract S3 key from s3_url (format: s3://bucket/key or https://...)
            $s3Key = null;
            if (!empty($media['s3_url'])) {
                $s3Key = $s3->extractS3Key($media['s3_url']);
            }

            // Delete from S3 (if uploaded)
            if ($s3Key) {
                $deleted = $s3->deleteFile($s3Key);
                if ($deleted) {
                    $stats['s3_files_deleted']++;
                    echo "  [S3] Deleted: {$s3Key}\n";
                } else {
                    $stats['s3_files_failed']++;
                    echo "  [S3 FAILED] Could not delete: {$s3Key}\n";
                }
            }

            // Delete local file if exists
            if (!empty($media['local_path'])) {
                $localFullPath = $uploadPathBase . '/' . $media['local_path'];
                if (file_exists($localFullPath)) {
                    if (@unlink($localFullPath)) {
                        $stats['local_files_deleted']++;
                        echo "  [LOCAL] Deleted: {$media['local_path']}\n";
                    } else {
                        echo "  [LOCAL FAILED] Could not delete: {$media['local_path']}\n";
                    }
                }
            }
        }

        // Delete media records from database
        $mediaIds = array_column($expiredMediaFiles, 'id');
        if (!empty($mediaIds)) {
            $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
            $deletedMediaRecords = $db->execute(
                "DELETE FROM journal_media WHERE id IN ({$placeholders})",
                $mediaIds
            );
            echo "  Database: Deleted {$deletedMediaRecords} media records\n";
        }
    } else {
        echo "  No expired media files to cleanup\n";
    }

    // =========================================================================
    // Phase 2: Clean up orphaned media files (safety check)
    // Media files soft-deleted directly (not via entry) for > 30 days
    // =========================================================================
    echo "\n-- Phase 2: Orphaned Media Cleanup --\n";

    $orphanedMedia = $db->findMany(
        "SELECT jm.id, jm.uuid, jm.media_type, jm.s3_url, jm.local_path
         FROM journal_media jm
         WHERE jm.deleted_at IS NOT NULL
           AND jm.deleted_at < NOW() - INTERVAL '" . RETENTION_DAYS . " days'
         LIMIT ?",
        [MEDIA_BATCH_SIZE]
    );

    if (count($orphanedMedia) > 0) {
        echo "  Found " . count($orphanedMedia) . " orphaned media files\n";

        foreach ($orphanedMedia as $media) {
            // Delete from S3
            $s3Key = $media['s3_url'] ? $s3->extractS3Key($media['s3_url']) : null;
            if ($s3Key) {
                $s3->deleteFile($s3Key);
            }

            // Delete local file
            if (!empty($media['local_path'])) {
                $localFullPath = $uploadPathBase . '/' . $media['local_path'];
                if (file_exists($localFullPath)) {
                    @unlink($localFullPath);
                }
            }
        }

        // Delete orphaned media records
        $orphanIds = array_column($orphanedMedia, 'id');
        if (!empty($orphanIds)) {
            $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
            $db->execute(
                "DELETE FROM journal_media WHERE id IN ({$placeholders})",
                $orphanIds
            );
            echo "  Deleted " . count($orphanIds) . " orphaned media records\n";
        }
    } else {
        echo "  No orphaned media files found\n";
    }

    // =========================================================================
    // Phase 3: Delete expired journal entries from database
    // =========================================================================
    echo "\n-- Phase 3: Journal Entries Cleanup --\n";

    $batchCount = 0;
    $totalDeleted = 0;

    while ($batchCount < MAX_BATCHES) {
        // Delete entries that have been in trash for > RETENTION_DAYS
        // ENTERPRISE: Batch delete with LIMIT to prevent long locks
        $deleted = $db->execute(
            "DELETE FROM emotional_journal_entries
             WHERE id IN (
                 SELECT id FROM emotional_journal_entries
                 WHERE deleted_at IS NOT NULL
                   AND deleted_at < NOW() - INTERVAL '" . RETENTION_DAYS . " days'
                 LIMIT ?
             )",
            [BATCH_SIZE]
        );

        if ($deleted === 0) {
            break; // No more expired entries
        }

        $totalDeleted += $deleted;
        $batchCount++;

        echo "  Batch {$batchCount}: Deleted {$deleted} journal entries\n";

        // Short pause between batches to reduce lock contention
        usleep(100000); // 100ms
    }

    $stats['entries_deleted'] = $totalDeleted;
    $stats['batches_processed'] = $batchCount;

    // =========================================================================
    // Phase 4: Vacuum ANALYZE (PostgreSQL optimization)
    // =========================================================================
    echo "\n-- Phase 4: Database Optimization --\n";

    if ($totalDeleted > 100 || $stats['media_files_deleted'] > 50) {
        // ANALYZE tables for query planner optimization (non-locking)
        $db->execute("ANALYZE emotional_journal_entries");
        $db->execute("ANALYZE journal_media");
        echo "  ANALYZE completed for journal tables\n";
    } else {
        echo "  Skipped (not enough deletions to warrant optimization)\n";
    }

    // =========================================================================
    // Summary
    // =========================================================================
    echo "\n========================================\n";
    echo "Journal Trash Cleanup V12 - Completed\n";
    echo "========================================\n";
    echo "Journal entries deleted: {$stats['entries_deleted']}\n";
    echo "Media files processed:   {$stats['media_files_deleted']}\n";
    echo "S3 files deleted:        {$stats['s3_files_deleted']}\n";
    echo "S3 files failed:         {$stats['s3_files_failed']}\n";
    echo "Local files deleted:     {$stats['local_files_deleted']}\n";
    echo "Batches processed:       {$stats['batches_processed']}\n";
    echo "========================================\n";

    // Log result
    if ($stats['entries_deleted'] > 0 || $stats['media_files_deleted'] > 0) {
        Logger::info('Journal trash cleanup completed', $stats);
    }

    exit(0);

} catch (\Exception $e) {
    Logger::error('Journal trash cleanup failed', ['error' => $e->getMessage()]);
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
