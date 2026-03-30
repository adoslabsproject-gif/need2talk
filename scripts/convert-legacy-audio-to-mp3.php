#!/usr/bin/env php
<?php
/**
 * Convert Legacy Audio to MP3 - S3 Batch Processor
 *
 * ENTERPRISE GALAXY - need2talk.it
 *
 * Converts all existing WebM audio files to MP3 for universal browser compatibility.
 * MP3 is supported by ALL browsers including Safari.
 *
 * Usage:
 *   php scripts/convert-legacy-audio-to-mp3.php              # Dry run
 *   php scripts/convert-legacy-audio-to-mp3.php --execute    # Actually convert
 *   php scripts/convert-legacy-audio-to-mp3.php --post-id=17 # Single post
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
echo "  Legacy Audio to MP3 Converter\n";
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

// Get all WebM audio files from S3
$query = "
    SELECT
        ap.id AS post_id,
        af.id AS file_id,
        af.cdn_url,
        af.duration,
        af.mime_type,
        ap.created_at
    FROM audio_posts ap
    JOIN audio_files af ON ap.audio_file_id = af.id
    WHERE af.cdn_url LIKE 's3://%'
      AND af.deleted_at IS NULL
      AND (af.mime_type = 'audio/webm' OR af.cdn_url LIKE '%.webm')
";

if ($singlePostId) {
    $query .= " AND ap.id = :post_id";
    $params = ['post_id' => $singlePostId];
} else {
    $params = [];
}

$query .= " ORDER BY ap.id ASC";

$audioFiles = $db->query($query, $params);

echo "Found " . count($audioFiles) . " WebM audio file(s) to convert\n\n";

$converted = 0;
$skipped = 0;
$errors = 0;
$totalSizeBefore = 0;
$totalSizeAfter = 0;

foreach ($audioFiles as $file) {
    $postId = $file['post_id'];
    $fileId = $file['file_id'];
    $cdnUrl = $file['cdn_url'];
    $dbDuration = $file['duration'];

    echo "Post #$postId (File #$fileId):\n";
    echo "  S3 URL: $cdnUrl\n";
    echo "  Duration: {$dbDuration}s\n";

    // Extract S3 key from cdn_url (s3://bucket/key)
    $s3Key = $s3->extractS3Key($cdnUrl);
    if (!$s3Key) {
        echo "  ERROR: Cannot extract S3 key\n\n";
        $errors++;
        continue;
    }

    // Check if already MP3
    if (str_ends_with(strtolower($s3Key), '.mp3')) {
        echo "  STATUS: Already MP3, skipping\n\n";
        $skipped++;
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
    $tempInput = "/tmp/convert_input_$postId.webm";
    $tempOutput = "/tmp/convert_output_$postId.mp3";

    // Download
    $downloadCmd = "curl -s -o " . escapeshellarg($tempInput) . " " . escapeshellarg($signedUrl);
    exec($downloadCmd, $output, $returnCode);

    if ($returnCode !== 0 || !file_exists($tempInput)) {
        echo "  ERROR: Download failed\n\n";
        $errors++;
        continue;
    }

    $sizeBefore = filesize($tempInput);
    $totalSizeBefore += $sizeBefore;
    echo "  Original size: " . number_format($sizeBefore) . " bytes\n";

    if ($dryRun) {
        // Estimate MP3 size (roughly similar at 64kbps)
        $estimatedSize = (int) ($dbDuration * 64000 / 8);
        echo "  Estimated MP3 size: ~" . number_format($estimatedSize) . " bytes\n";
        echo "  ACTION: Would convert to MP3 (dry run)\n\n";
        @unlink($tempInput);
        $converted++;
        continue;
    }

    // Convert to MP3 with optimal settings for voice
    // 64kbps mono is excellent quality for voice, similar size to WebM
    $ffmpegCmd = sprintf(
        'ffmpeg -y -i %s -c:a libmp3lame -b:a 64k -ar 44100 -ac 1 %s 2>&1',
        escapeshellarg($tempInput),
        escapeshellarg($tempOutput)
    );
    $ffmpegOutput = shell_exec($ffmpegCmd);

    if (!file_exists($tempOutput)) {
        echo "  ERROR: FFmpeg conversion failed\n";
        echo "  FFmpeg output: $ffmpegOutput\n\n";
        @unlink($tempInput);
        $errors++;
        continue;
    }

    $sizeAfter = filesize($tempOutput);
    $totalSizeAfter += $sizeAfter;
    echo "  MP3 size: " . number_format($sizeAfter) . " bytes\n";

    // Verify MP3 duration
    $probeCmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($tempOutput) . " 2>&1";
    $mp3Duration = trim(shell_exec($probeCmd) ?? '');
    echo "  MP3 duration: {$mp3Duration}s\n";

    // Generate new S3 key (change extension)
    $newS3Key = preg_replace('/\.webm$/i', '.mp3', $s3Key);
    echo "  New S3 key: $newS3Key\n";

    // Upload MP3 to S3
    $uploadResult = $s3->uploadFile($tempOutput, $newS3Key, ['ContentType' => 'audio/mpeg']);

    if (!$uploadResult) {
        echo "  ERROR: S3 upload failed\n\n";
        @unlink($tempInput);
        @unlink($tempOutput);
        $errors++;
        continue;
    }

    // Update database
    $newCdnUrl = preg_replace('/\.webm$/i', '.mp3', $cdnUrl);
    $db->execute(
        "UPDATE audio_files SET cdn_url = :cdn_url, mime_type = 'audio/mpeg', duration = :duration WHERE id = :id",
        ['cdn_url' => $newCdnUrl, 'duration' => (float) $mp3Duration, 'id' => $fileId]
    );
    echo "  DB updated: cdn_url and mime_type\n";

    // Delete old WebM from S3 (optional - keep for safety during transition)
    // $s3->deleteFile($s3Key);
    // echo "  Old WebM deleted from S3\n";

    echo "  ACTION: Converted and uploaded!\n\n";

    // Cleanup
    @unlink($tempInput);
    @unlink($tempOutput);

    $converted++;

    // Log
    Logger::info('Legacy audio converted to MP3', [
        'post_id' => $postId,
        'file_id' => $fileId,
        'old_key' => $s3Key,
        'new_key' => $newS3Key,
        'size_before' => $sizeBefore,
        'size_after' => $sizeAfter,
    ]);
}

echo "========================================\n";
echo "Summary:\n";
echo "  Converted: $converted\n";
echo "  Skipped (already MP3): $skipped\n";
echo "  Errors: $errors\n";

if (!$dryRun && $totalSizeBefore > 0) {
    $savings = $totalSizeBefore - $totalSizeAfter;
    $savingsPercent = ($savings / $totalSizeBefore) * 100;
    echo "  Total size before: " . number_format($totalSizeBefore) . " bytes\n";
    echo "  Total size after: " . number_format($totalSizeAfter) . " bytes\n";
    echo "  Size change: " . ($savings >= 0 ? '-' : '+') . number_format(abs($savings)) . " bytes (" . number_format(abs($savingsPercent), 1) . "%)\n";
}

echo "========================================\n";

if ($dryRun && $converted > 0) {
    echo "\nRun with --execute to apply conversions.\n";
}
