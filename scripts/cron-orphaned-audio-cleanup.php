#!/usr/bin/env php
<?php
/**
 * Orphaned Audio Files Cleanup Cron Job
 *
 * ENTERPRISE ARCHITECTURE:
 * This script runs daily at 3:00 AM to clean up orphaned audio files.
 * Orphaned files are local audio files that:
 * - Don't have a corresponding record in the database
 * - OR have a failed/expired database record
 * - Are older than 24 hours (to avoid deleting files being processed)
 *
 * SCHEDULE: Daily at 3:00 AM via CronManager
 * 0 3 * * * php /var/www/need2talk/scripts/cron-orphaned-audio-cleanup.php
 *
 * WHY THIS IS NEEDED:
 * - When uploads fail mid-process, local files may remain without DB record
 * - When S3 upload fails after DB insert, file remains locally
 * - Prevents disk space waste from accumulating orphaned files
 *
 * SAFETY:
 * - Only processes files older than 24 hours
 * - Verifies file is not in 'processing' or 'active' state
 * - Logs all deletions for audit trail
 *
 * @package Need2Talk\Scripts
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-06
 */

declare(strict_types=1);

// Load bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;

// Configuration
const MIN_AGE_HOURS = 24;       // Only process files older than 24 hours
const MAX_FILES_PER_RUN = 500;  // Safety limit per execution
const AUDIO_BASE_PATH = '/var/www/html/storage/uploads/audio';

echo "========================================\n";
echo "🎵 Orphaned Audio Cleanup - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

$stats = [
    'scanned' => 0,
    'orphaned' => 0,
    'deleted' => 0,
    'failed' => 0,
    'skipped_too_new' => 0,
    'skipped_active' => 0,
];

try {
    $db = db();

    // Check if audio directory exists
    if (!is_dir(AUDIO_BASE_PATH)) {
        echo "Audio directory not found: " . AUDIO_BASE_PATH . "\n";
        echo "Nothing to clean up.\n";
        exit(0);
    }

    // Threshold: files must be older than MIN_AGE_HOURS
    $thresholdTime = time() - (MIN_AGE_HOURS * 3600);

    echo "\nScanning for audio files older than " . MIN_AGE_HOURS . " hours...\n";
    echo "Threshold: " . date('Y-m-d H:i:s', $thresholdTime) . "\n\n";

    // Recursively scan audio directory for .webm files
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(AUDIO_BASE_PATH, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $filesToCheck = [];

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'webm') {
            $filePath = $file->getPathname();
            $mtime = $file->getMTime();

            $stats['scanned']++;

            // Skip files that are too new
            if ($mtime > $thresholdTime) {
                $stats['skipped_too_new']++;
                continue;
            }

            $filesToCheck[] = [
                'path' => $filePath,
                'mtime' => $mtime,
                'size' => $file->getSize(),
            ];

            // Safety limit
            if (count($filesToCheck) >= MAX_FILES_PER_RUN) {
                echo "⚠️  Reached max files limit (" . MAX_FILES_PER_RUN . "). Will process remaining in next run.\n";
                break;
            }
        }
    }

    echo "Found " . count($filesToCheck) . " files to check (scanned: {$stats['scanned']})\n\n";

    if (empty($filesToCheck)) {
        echo "✅ No orphaned audio files to cleanup.\n";
        exit(0);
    }

    // For each file, check if it exists in database with active/processing status
    foreach ($filesToCheck as $fileInfo) {
        $filePath = $fileInfo['path'];

        // Check database for this file path
        $dbRecord = $db->findOne(
            "SELECT id, status, cdn_url FROM audio_files WHERE file_path = ?",
            [$filePath],
            ['cache' => false]
        );

        $isOrphaned = false;
        $orphanReason = '';

        if (!$dbRecord) {
            // No database record at all - orphaned
            $isOrphaned = true;
            $orphanReason = 'no_db_record';
        } elseif ($dbRecord['status'] === 'failed') {
            // Has DB record but marked as failed - orphaned
            $isOrphaned = true;
            $orphanReason = 'failed_status';
        } elseif ($dbRecord['status'] === 'active' && !empty($dbRecord['cdn_url'])) {
            // File uploaded to CDN but local copy remains - orphaned
            $isOrphaned = true;
            $orphanReason = 'cdn_uploaded';
        } elseif ($dbRecord['status'] === 'processing') {
            // Still processing - skip (might be slow upload)
            $stats['skipped_active']++;
            continue;
        } elseif ($dbRecord['status'] === 'active' && empty($dbRecord['cdn_url'])) {
            // Active but no CDN URL - strange state, skip for safety
            $stats['skipped_active']++;
            continue;
        }

        if ($isOrphaned) {
            $stats['orphaned']++;

            // Delete the orphaned file
            if (@unlink($filePath)) {
                $stats['deleted']++;

                Logger::audio('warning', 'Orphaned audio file deleted', [
                    'path' => $filePath,
                    'reason' => $orphanReason,
                    'file_age_hours' => round((time() - $fileInfo['mtime']) / 3600, 1),
                    'file_size_kb' => round($fileInfo['size'] / 1024, 1),
                ]);

                echo "  ✅ Deleted: " . basename($filePath) . " (reason: {$orphanReason})\n";

                // If there was a failed DB record, update it
                if ($dbRecord && $dbRecord['status'] === 'failed') {
                    $db->execute(
                        "UPDATE audio_files SET error_message = COALESCE(error_message, '') || ' [Orphaned file cleaned up]' WHERE id = ?",
                        [$dbRecord['id']]
                    );
                }

            } else {
                $stats['failed']++;

                Logger::audio('error', 'Failed to delete orphaned audio file', [
                    'path' => $filePath,
                    'reason' => $orphanReason,
                    'file_exists' => file_exists($filePath),
                    'is_writable' => is_writable(dirname($filePath)),
                ]);

                echo "  ❌ Failed: " . basename($filePath) . "\n";
            }
        }
    }

    // Summary
    echo "\n========================================\n";
    echo "📊 Cleanup Summary:\n";
    echo "========================================\n";
    echo "  Files scanned:      {$stats['scanned']}\n";
    echo "  Skipped (too new):  {$stats['skipped_too_new']}\n";
    echo "  Skipped (active):   {$stats['skipped_active']}\n";
    echo "  Orphaned found:     {$stats['orphaned']}\n";
    echo "  Deleted:            {$stats['deleted']}\n";
    echo "  Failed to delete:   {$stats['failed']}\n";
    echo "========================================\n";

    // Calculate freed space
    if ($stats['deleted'] > 0) {
        Logger::audio('warning', 'Orphaned audio cleanup completed', [
            'scanned' => $stats['scanned'],
            'deleted' => $stats['deleted'],
            'failed' => $stats['failed'],
        ]);
    }

    exit(0);

} catch (\Exception $e) {
    Logger::error('Orphaned audio cleanup failed', ['error' => $e->getMessage()]);
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
