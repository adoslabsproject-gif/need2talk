#!/usr/bin/env php
<?php
/**
 * Fix WebM Duration Metadata - S3 Batch Processor
 *
 * ENTERPRISE GALAXY - need2talk.it
 *
 * Problem: Chrome MediaRecorder creates WebM files WITHOUT duration metadata.
 * This causes Safari/Chrome to fail with MEDIA_ERR_DECODE (code 3).
 *
 * Solution: Re-mux all WebM files with FFmpeg to add proper duration.
 *
 * Usage:
 *   php scripts/fix-webm-duration-s3.php              # Dry run (show what would be fixed)
 *   php scripts/fix-webm-duration-s3.php --execute    # Actually fix files
 *   php scripts/fix-webm-duration-s3.php --post-id=17 # Fix single post
 *
 * @author Claude Code (AI-Orchestrated Development)
 * @version 1.0.0
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Storage\S3StorageService;
use Need2Talk\Services\Logger;

// Parse arguments
$dryRun = !in_array('--execute', $argv);
$singlePostId = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--post-id=')) {
        $singlePostId = (int) substr($arg, 10);
    }
}

echo "========================================\n";
echo "  WebM Duration Fix - S3 Batch Processor\n";
echo "========================================\n\n";

if ($dryRun) {
    echo "** DRY RUN MODE ** (use --execute to apply changes)\n\n";
}

// Check FFmpeg availability
$ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
if (empty($ffmpegPath)) {
    echo "ERROR: FFmpeg not found. Install it first:\n";
    echo "  apt-get install ffmpeg\n";
    exit(1);
}
echo "FFmpeg found: $ffmpegPath\n\n";

// Initialize services
$db = db();
$s3 = new S3StorageService();

// Get all audio files from S3
$query = "
    SELECT
        ap.id AS post_id,
        af.id AS file_id,
        af.cdn_url,
        af.duration,
        ap.created_at
    FROM audio_posts ap
    JOIN audio_files af ON ap.audio_file_id = af.id
    WHERE af.cdn_url LIKE 's3://%'
      AND af.deleted_at IS NULL
";

if ($singlePostId) {
    $query .= " AND ap.id = :post_id";
    $params = ['post_id' => $singlePostId];
} else {
    $params = [];
}

$query .= " ORDER BY ap.id DESC";

$audioFiles = $db->query($query, $params);

echo "Found " . count($audioFiles) . " audio file(s) to check\n\n";

$fixed = 0;
$skipped = 0;
$errors = 0;

foreach ($audioFiles as $file) {
    $postId = $file['post_id'];
    $fileId = $file['file_id'];
    $cdnUrl = $file['cdn_url'];
    $dbDuration = $file['duration'];

    echo "Post #$postId (File #$fileId):\n";
    echo "  S3 URL: $cdnUrl\n";
    echo "  DB Duration: {$dbDuration}s\n";

    // Extract S3 key from cdn_url (s3://bucket/key)
    $s3Key = $s3->extractS3Key($cdnUrl);
    if (!$s3Key) {
        echo "  ERROR: Cannot extract S3 key\n\n";
        $errors++;
        continue;
    }

    // Get signed URL for download
    $signedUrl = $s3->getSignedUrl($s3Key, 3600);
    if (!$signedUrl) {
        echo "  ERROR: Cannot generate signed URL\n\n";
        $errors++;
        continue;
    }

    // Download to temp file
    $tempInput = "/tmp/webm_fix_input_$postId.webm";
    $tempOutput = "/tmp/webm_fix_output_$postId.webm";

    // Download
    $downloadCmd = "curl -s -o " . escapeshellarg($tempInput) . " " . escapeshellarg($signedUrl);
    exec($downloadCmd, $output, $returnCode);

    if ($returnCode !== 0 || !file_exists($tempInput)) {
        echo "  ERROR: Download failed\n\n";
        $errors++;
        continue;
    }

    // Check current duration with ffprobe
    $probeCmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($tempInput) . " 2>&1";
    $currentDuration = trim(shell_exec($probeCmd) ?? '');

    echo "  File Duration: " . ($currentDuration ?: "N/A (missing!)") . "\n";

    // Check if fix needed
    if ($currentDuration && $currentDuration !== 'N/A' && is_numeric($currentDuration)) {
        echo "  STATUS: Already has duration, skipping\n\n";
        @unlink($tempInput);
        $skipped++;
        continue;
    }

    echo "  STATUS: Needs fix!\n";

    if ($dryRun) {
        echo "  ACTION: Would re-mux with FFmpeg (dry run)\n\n";
        @unlink($tempInput);
        $fixed++;
        continue;
    }

    // Re-mux with FFmpeg (this adds duration metadata)
    $ffmpegCmd = "ffmpeg -y -i " . escapeshellarg($tempInput) . " -c copy " . escapeshellarg($tempOutput) . " 2>&1";
    $ffmpegOutput = shell_exec($ffmpegCmd);

    if (!file_exists($tempOutput)) {
        echo "  ERROR: FFmpeg re-mux failed\n";
        echo "  FFmpeg output: $ffmpegOutput\n\n";
        @unlink($tempInput);
        $errors++;
        continue;
    }

    // Verify fix
    $newDuration = trim(shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($tempOutput) . " 2>&1") ?? '');
    echo "  New Duration: $newDuration\n";

    if (!$newDuration || !is_numeric($newDuration)) {
        echo "  ERROR: Fix failed - still no duration\n\n";
        @unlink($tempInput);
        @unlink($tempOutput);
        $errors++;
        continue;
    }

    // Upload back to S3 (overwrite)
    $uploadResult = $s3->uploadFile($tempOutput, $s3Key, ['ContentType' => 'audio/webm']);

    if (!$uploadResult) {
        echo "  ERROR: S3 upload failed\n\n";
        @unlink($tempInput);
        @unlink($tempOutput);
        $errors++;
        continue;
    }

    // Update DB duration if significantly different
    $newDurationFloat = (float) $newDuration;
    if (abs($newDurationFloat - (float) $dbDuration) > 0.5) {
        $db->execute(
            "UPDATE audio_files SET duration = :duration WHERE id = :id",
            ['duration' => $newDurationFloat, 'id' => $fileId]
        );
        echo "  DB Duration updated: {$dbDuration}s -> {$newDurationFloat}s\n";
    }

    echo "  ACTION: Fixed and uploaded!\n\n";

    // Cleanup
    @unlink($tempInput);
    @unlink($tempOutput);

    $fixed++;

    // Log
    Logger::info('WebM duration fix applied', [
        'post_id' => $postId,
        'file_id' => $fileId,
        'old_duration' => $dbDuration,
        'new_duration' => $newDurationFloat,
    ]);
}

echo "========================================\n";
echo "Summary:\n";
echo "  Fixed: $fixed\n";
echo "  Skipped (already OK): $skipped\n";
echo "  Errors: $errors\n";
echo "========================================\n";

if ($dryRun && $fixed > 0) {
    echo "\nRun with --execute to apply fixes.\n";
}
