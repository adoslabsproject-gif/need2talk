#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Unified Log Cleanup & Rotation
 *
 * Combines log rotation (compress old logs) and cleanup (delete very old logs)
 * Handles all application logs in storage/logs/
 *
 * OPERATIONS:
 * 1. Rotate logs >7 days old (compress with gzip)
 * 2. Delete logs >30 days old
 * 3. Optimize storage space
 *
 * Schedule: Daily at 2 AM (0 2 * * *)
 * Usage:
 *   php scripts/crons/cleanup-logs.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-logs.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

Logger::info('CRON: Log cleanup started', [
    'rotation_days' => 7,
    'deletion_days' => 30,
    'script' => basename(__FILE__)
]);

echo "🚀 ENTERPRISE GALAXY: Unified Log Cleanup & Rotation\n";
echo str_repeat('=', 70) . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$startTime = microtime(true);
$logsDir = APP_ROOT . '/storage/logs';
$rotationDays = 7;  // Compress logs older than 7 days
$deletionDays = 30; // Delete logs older than 30 days

$rotationTime = time() - ($rotationDays * 86400);
$deletionTime = time() - ($deletionDays * 86400);

try {
    if (!is_dir($logsDir)) {
        throw new Exception("Logs directory not found: {$logsDir}");
    }

    echo "📂 Logs directory: {$logsDir}\n";
    echo "🔄 Rotation threshold: {$rotationDays} days\n";
    echo "🗑️  Deletion threshold: {$deletionDays} days\n\n";

    // Get all log files
    $logFiles = glob($logsDir . '/*.log');
    $gzFiles = glob($logsDir . '/*.log.gz');

    echo "📊 Current status:\n";
    echo "   Active logs: " . count($logFiles) . " files\n";
    echo "   Compressed logs: " . count($gzFiles) . " files\n\n";

    // STEP 1: Rotation (compress old .log files)
    echo str_repeat('-', 70) . "\n";
    echo "🔄 STEP 1: Log Rotation (Compression)\n";
    echo str_repeat('-', 70) . "\n\n";

    $rotatedCount = 0;
    $rotatedSize = 0;
    $compressedSize = 0;

    foreach ($logFiles as $logFile) {
        $filename = basename($logFile);

        // Skip special files
        if (in_array($filename, ['php_errors.log', 'need2talk.log'])) {
            echo "   ⏭️  Skipping active log: {$filename}\n";
            continue;
        }

        $fileTime = filemtime($logFile);
        $fileAge = time() - $fileTime;
        $fileSizeBefore = filesize($logFile);

        // Skip empty files (prevent division by zero)
        if ($fileSizeBefore === 0) {
            echo "   ⏭️  Skipping empty file: {$filename}\n";
            unlink($logFile); // Delete empty log file
            continue;
        }

        // Compress if older than rotation threshold
        if ($fileTime < $rotationTime) {
            $gzFile = $logFile . '.gz';

            // Skip if already compressed
            if (file_exists($gzFile)) {
                echo "   ⏭️  Already compressed: {$filename}\n";
                continue;
            }

            // Read and compress
            $logContent = file_get_contents($logFile);
            $compressed = gzencode($logContent, 9); // Max compression

            if ($compressed === false) {
                echo "   ❌ Failed to compress: {$filename}\n";
                continue;
            }

            // Write compressed file
            if (file_put_contents($gzFile, $compressed) === false) {
                echo "   ❌ Failed to write: {$filename}.gz\n";
                continue;
            }

            $fileSizeAfter = filesize($gzFile);
            $compressionRatio = round((1 - ($fileSizeAfter / $fileSizeBefore)) * 100, 1);

            echo "   ✅ Compressed: {$filename}\n";
            echo "      Before: " . number_format($fileSizeBefore) . " bytes\n";
            echo "      After: " . number_format($fileSizeAfter) . " bytes\n";
            echo "      Ratio: {$compressionRatio}% reduction\n";

            // Delete original file
            unlink($logFile);

            $rotatedCount++;
            $rotatedSize += $fileSizeBefore;
            $compressedSize += $fileSizeAfter;
        }
    }

    $spaceSaved = $rotatedSize - $compressedSize;
    $spaceSavedMB = round($spaceSaved / 1048576, 2);

    echo "\n📊 Rotation summary:\n";
    echo "   Files compressed: {$rotatedCount}\n";
    echo "   Space saved: {$spaceSavedMB} MB\n\n";

    // STEP 2: Deletion (remove very old .gz files)
    echo str_repeat('-', 70) . "\n";
    echo "🗑️  STEP 2: Log Deletion\n";
    echo str_repeat('-', 70) . "\n\n";

    $deletedCount = 0;
    $deletedSize = 0;

    // Refresh gz files list
    $gzFiles = glob($logsDir . '/*.log.gz');

    foreach ($gzFiles as $gzFile) {
        $filename = basename($gzFile);
        $fileTime = filemtime($gzFile);
        $fileSize = filesize($gzFile);

        // Delete if older than deletion threshold
        if ($fileTime < $deletionTime) {
            $ageInDays = round((time() - $fileTime) / 86400);

            if (unlink($gzFile)) {
                echo "   🗑️  Deleted: {$filename} (age: {$ageInDays} days)\n";
                $deletedCount++;
                $deletedSize += $fileSize;
            } else {
                echo "   ❌ Failed to delete: {$filename}\n";
            }
        }
    }

    $deletedSizeMB = round($deletedSize / 1048576, 2);

    echo "\n📊 Deletion summary:\n";
    echo "   Files deleted: {$deletedCount}\n";
    echo "   Space freed: {$deletedSizeMB} MB\n\n";

    // STEP 3: Final statistics
    $finalLogFiles = glob($logsDir . '/*.log');
    $finalGzFiles = glob($logsDir . '/*.log.gz');

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo str_repeat('=', 70) . "\n";
    echo "✅ Log cleanup completed!\n";
    echo str_repeat('=', 70) . "\n";
    echo "📊 Final status:\n";
    echo "   Active logs: " . count($finalLogFiles) . " files\n";
    echo "   Compressed logs: " . count($finalGzFiles) . " files\n";
    echo "   Files compressed: {$rotatedCount}\n";
    echo "   Files deleted: {$deletedCount}\n";
    echo "   Total space saved: " . ($spaceSavedMB + $deletedSizeMB) . " MB\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    // Log to system
    Logger::info('Log cleanup completed', [
        'files_compressed' => $rotatedCount,
        'files_deleted' => $deletedCount,
        'space_saved_mb' => $spaceSavedMB + $deletedSizeMB,
        'active_logs' => count($finalLogFiles),
        'compressed_logs' => count($finalGzFiles),
        'execution_time' => $executionTime
    ]);

    exit(0);

} catch (\Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "⏱️  Execution time: {$executionTime}ms\n";

    Logger::error('Log cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'execution_time' => $executionTime
    ]);

    exit(1);
}
