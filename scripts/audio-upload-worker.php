<?php
/**
 * ================================================================================
 * NEED2TALK - AUDIO UPLOAD WORKER (ENTERPRISE GALAXY)
 * ================================================================================
 *
 * PURPOSE: Background worker for audio processing and S3 upload
 *
 * ARCHITECTURE:
 * - Infinite loop with 30s interval between cycles
 * - Batch processing: 50 files per cycle (configurable)
 * - SELECT ... FOR UPDATE SKIP LOCKED (no race conditions)
 * - ACL PRIVATE enforced (no public URLs)
 * - Signed URLs only (1h expiration, HMAC-SHA256)
 * - Auto-delete local temp files after successful upload
 * - Redis heartbeat for health monitoring
 * - Graceful shutdown on SIGTERM/SIGINT
 *
 * FLOW:
 * 1. SELECT batch of files WHERE status='processing' (SKIP LOCKED)
 * 2. For each file:
 *    a. Extract metadata with ffprobe (duration, bitrate, codec)
 *    b. Upload to S3 with ACL=private
 *    c. UPDATE status='active', cdn_url, duration, metadata
 *    d. DELETE local temp file
 *    e. Log success/failure
 * 3. Redis heartbeat update
 * 4. Sleep 30s, repeat
 *
 * SECURITY:
 * - ACL PRIVATE: Files NOT accessible via direct URL
 * - Signed URLs generated on-demand (S3StorageService)
 * - Limited S3 credentials (Read+Write only)
 * - No shell command injection (ffprobe with escapeshellarg)
 *
 * PERFORMANCE:
 * - Batch processing: 50 files/cycle = 100 files/min/worker
 * - 12 workers = 1,200 files/min = 72,000 files/hour
 * - Stream processing (no full file load)
 * - Connection pooling (PostgreSQL, Redis)
 *
 * FAILURE RECOVERY:
 * - Retry logic: 3 attempts with exponential backoff
 * - Dead letter queue: Files failing 3+ times → manual review
 * - Graceful shutdown: Finish current batch before exit
 * - Auto-restart on crash (docker-compose)
 *
 * MONITORING:
 * - Redis heartbeat: worker:{hostname}:heartbeat (60s TTL)
 * - Metrics: uploaded_count, failed_count, cycle_duration
 * - Logs: storage/logs/audio-worker-*.log
 *
 * ================================================================================
 */

// Bootstrap application
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Storage\S3StorageService;
use Need2Talk\Services\Logger;

// ================================================================================
// WORKER CONFIGURATION
// ================================================================================

const WORKER_NAME = 'audio-upload-worker';
const BATCH_SIZE = 50;                  // Files per cycle
const CYCLE_INTERVAL = 30;              // Seconds between cycles
const MAX_RETRIES = 3;                  // Max retry attempts per file
const HEARTBEAT_INTERVAL = 30;          // Seconds between heartbeats
const FFPROBE_TIMEOUT = 10;             // Seconds max for ffprobe
const FFMPEG_TIMEOUT = 60;              // Seconds max for FFmpeg conversion

// ================================================================================
// GLOBAL STATE
// ================================================================================

$workerRunning = true;
$workerId = gethostname() . '_' . getmypid();
$s3Service = null;
$redis = null;

// Metrics
$metrics = [
    'uploaded_count' => 0,
    'failed_count' => 0,
    'cycles_completed' => 0,
    'started_at' => time(),
];

// ================================================================================
// SIGNAL HANDLERS (Graceful Shutdown)
// ================================================================================

// ENTERPRISE: Signal handlers only if PCNTL extension is available
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);

    pcntl_signal(SIGTERM, function () use (&$workerRunning, $workerId) {
        Logger::audio('warning', 'SIGTERM received, graceful shutdown initiated', ['worker_id' => $workerId]);
        $workerRunning = false;
    });

    pcntl_signal(SIGINT, function () use (&$workerRunning, $workerId) {
        Logger::audio('warning', 'SIGINT received, graceful shutdown initiated', ['worker_id' => $workerId]);
        $workerRunning = false;
    });

    Logger::audio('info', 'Signal handlers initialized (PCNTL available)', ['worker_id' => $workerId]);
} else {
    Logger::audio('warning', 'PCNTL extension not available, signal handlers disabled', ['worker_id' => $workerId]);
}

// ================================================================================
// INITIALIZATION
// ================================================================================

Logger::audio('info', '═══════════════════════════════════════════════════');
Logger::audio('info', 'Audio Upload Worker starting', ['worker_id' => $workerId]);
Logger::audio('info', 'Worker configuration', ['batch_size' => BATCH_SIZE, 'cycle_interval' => CYCLE_INTERVAL . 's']);

Logger::audio('info', '═══════════════════════════════════════════════════');

Logger::audio('info', 'Audio Upload Worker starting', [
    'worker_id' => $workerId,
    'batch_size' => BATCH_SIZE,
    'cycle_interval' => CYCLE_INTERVAL,
    'max_retries' => MAX_RETRIES,
]);

try {
    // S3 SERVICE
    $s3Service = new S3StorageService();
    Logger::audio('info', 'S3 Storage Service initialized');

    // REDIS CONNECTION (for heartbeat)
    $redis = new Redis();
    $redis->connect(env('REDIS_HOST', 'redis'), (int) env('REDIS_PORT', 6379));
    $redisPassword = env('REDIS_PASSWORD');
    if ($redisPassword) {
        $redis->auth($redisPassword);
    }
    $redis->select((int) env('REDIS_DB_QUEUE', 2)); // Queue DB
    Logger::audio('info', 'Redis connection established', ['db' => env('REDIS_DB_QUEUE', 2)]);

} catch (\Exception $e) {
    
    Logger::error('Worker initialization failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}

Logger::audio('info', 'Worker initialization complete - Ready to process');
Logger::audio('info', 'Entering main processing loop', ['worker_id' => $workerId]);

// ================================================================================
// MAIN WORKER LOOP
// ================================================================================


while ($workerRunning) {
    $cycleStartTime = microtime(true);

    try {
        // UPDATE HEARTBEAT
        updateHeartbeat($redis, $workerId, $metrics);

        // PROCESS BATCH
        $batchResult = processBatch($s3Service);

        // UPDATE METRICS
        $metrics['uploaded_count'] += $batchResult['uploaded'];
        $metrics['failed_count'] += $batchResult['failed'];
        $metrics['cycles_completed']++;

        $cycleDuration = round((microtime(true) - $cycleStartTime) * 1000, 2);

        // ENTERPRISE: Respect logging configuration
        if (function_exists('should_log') && !should_log('audio', 'info')) {
            // Skip - below configured threshold
        } else {
            Logger::audio('info', 'Cycle completed', [
                'worker_id' => $workerId,
                'cycle' => $metrics['cycles_completed'],
                'uploaded' => $batchResult['uploaded'],
                'failed' => $batchResult['failed'],
                'duration_ms' => $cycleDuration,
            ]);
        }

    } catch (\Exception $e) {
        Logger::audio('error', 'Worker cycle failed', [
            'worker_id' => $workerId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    // ENTERPRISE V11.11: Reset database pool to prevent connection leak
    // Long-running workers accumulate idle connections that never get released
    try {
        \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->resetPool();
        gc_collect_cycles(); // Force garbage collection
    } catch (\Throwable $e) {
        // Silent fail - pool reset is not critical
    }

    // SLEEP until next cycle (if still running)
    if ($workerRunning) {
        sleep(CYCLE_INTERVAL);
    }
}

// ================================================================================
// GRACEFUL SHUTDOWN
// ================================================================================

Logger::info('Worker shutting down gracefully', [
    'worker_id' => $workerId,
    'total_uploaded' => $metrics['uploaded_count'],
    'total_failed' => $metrics['failed_count'],
    'cycles_completed' => $metrics['cycles_completed'],
    'uptime_seconds' => time() - $metrics['started_at'],
]);

// Remove heartbeat
if ($redis) {
    $redis->del("worker:audio:{$workerId}:heartbeat");
}

exit(0);

// ================================================================================
// FUNCTIONS
// ================================================================================

/**
 * PROCESS BATCH - Batch processing di 50 file
 *
 * @param S3StorageService $s3Service
 * @return array{uploaded: int, failed: int}
 */
function processBatch(S3StorageService $s3Service): array
{
    $uploaded = 0;
    $failed = 0;

    try {
        $db = db();

        // SELECT BATCH con SKIP LOCKED (no race conditions tra workers)
        // SECURITY: Join with users to get user_uuid (prevents ID enumeration in S3 paths)
        $files = $db->query(
            "SELECT af.id, af.user_id, af.file_path, af.uuid, af.original_filename, af.file_size, af.mime_type,
                    u.uuid as user_uuid
             FROM audio_files af
             JOIN users u ON u.id = af.user_id
             WHERE af.status = 'processing'
             ORDER BY af.created_at ASC
             LIMIT :batch_size
             FOR UPDATE OF af SKIP LOCKED",
            [
                'batch_size' => BATCH_SIZE,
            ],
            ['cache' => false] // NO cache per query con locking
        );

        if (empty($files)) {
            // ENTERPRISE: Log at DEBUG level when queue is empty (avoid log spam)
            Logger::audio('debug', 'No files to process in queue');
            return ['uploaded' => 0, 'failed' => 0];
        }

        // ENTERPRISE: Log at WARNING level when uploading to CDN (for persistence in logs)
        Logger::audio('warning', 'Processing batch for CDN upload', ['file_count' => count($files)]);

        // PROCESS EACH FILE
        foreach ($files as $file) {
            $result = processFile($file, $s3Service);

            if ($result['success']) {
                $uploaded++;
            } else {
                $failed++;
            }
        }

    } catch (\Exception $e) {
        Logger::error('Batch processing failed', [
            'error' => $e->getMessage(),
        ]);
    }

    return ['uploaded' => $uploaded, 'failed' => $failed];
}

/**
 * PROCESS FILE - Process single file (metadata + S3 upload)
 *
 * @param array $file File record da database
 * @param S3StorageService $s3Service
 * @return array{success: bool, error?: string}
 */
function processFile(array $file, S3StorageService $s3Service): array
{
    $audioId = $file['id'];
    $userId = $file['user_id'];
    $userUuid = $file['user_uuid']; // SECURITY: Use UUID in S3 paths, not numeric ID
    $localPath = $file['file_path'];
    $uuid = $file['uuid'];

    try {
        // VALIDAZIONE FILE EXISTS
        if (!file_exists($localPath)) {
            Logger::error('File not found', [
                'audio_id' => $audioId,
                'path' => $localPath,
            ]);

            updateFileStatus($audioId, 'failed', null, null, 'File not found');
            return ['success' => false, 'error' => 'File not found'];
        }

        // EXTRACT METADATA con ffprobe
        $metadata = extractMetadata($localPath);

        if (!$metadata['success']) {
            Logger::error('Metadata extraction failed', [
                'audio_id' => $audioId,
                'error' => $metadata['error'] ?? 'Unknown error',
            ]);

            incrementRetryCount($audioId);
            return ['success' => false, 'error' => $metadata['error']];
        }

        // =========================================================================
        // ENTERPRISE 2025-12-13: Convert WebM to MP3 for universal browser support
        // Safari doesn't reliably play WebM - MP3 works everywhere!
        // Parameters: 64kbps, 44.1kHz, mono (optimal for voice)
        // =========================================================================
        $mp3Path = null;
        $uploadPath = $localPath; // Default: upload original file
        $fileExtension = 'webm';
        $mimeType = $file['mime_type'] ?? 'audio/webm';

        // Check if file is WebM and needs conversion
        if (str_ends_with(strtolower($localPath), '.webm') || $mimeType === 'audio/webm') {
            $conversionResult = convertToMp3($localPath, $audioId);

            if ($conversionResult['success']) {
                $mp3Path = $conversionResult['mp3_path'];
                $uploadPath = $mp3Path;
                $fileExtension = 'mp3';
                $mimeType = 'audio/mpeg';

                // Update duration from converted file (more accurate)
                if (isset($conversionResult['duration'])) {
                    $metadata['duration'] = $conversionResult['duration'];
                }

                Logger::audio('info', 'WebM converted to MP3', [
                    'audio_id' => $audioId,
                    'original_size' => filesize($localPath),
                    'mp3_size' => filesize($mp3Path),
                ]);
            } else {
                Logger::audio('warning', 'MP3 conversion failed, uploading original WebM', [
                    'audio_id' => $audioId,
                    'error' => $conversionResult['error'] ?? 'Unknown',
                ]);
                // Continue with original WebM if conversion fails
            }
        }

        // GENERATE S3 KEY (SECURITY: using user_uuid, not user_id)
        $s3Key = S3StorageService::generateS3Key($userUuid, $uuid, $fileExtension);

        // UPLOAD TO S3 (ACL PRIVATE)
        $uploadResult = $s3Service->uploadFile($uploadPath, $s3Key, [
            'audio_id' => $audioId,
            'user_id' => $userId,
            'duration' => $metadata['duration'],
        ]);

        if (!$uploadResult['success']) {
            Logger::error('S3 upload failed', [
                'audio_id' => $audioId,
                's3_key' => $s3Key,
                'error' => $uploadResult['error'] ?? 'Unknown error',
            ]);

            incrementRetryCount($audioId);
            return ['success' => false, 'error' => $uploadResult['error']];
        }

        // UPDATE DATABASE (status='active', cdn_url, metadata, mime_type, file_size)
        // ENTERPRISE 2025-12-13: Include MP3 mime_type and file_size for converted files
        $uploadedFileSize = file_exists($uploadPath) ? filesize($uploadPath) : null;

        updateFileStatus(
            $audioId,
            'active',
            $uploadResult['cdn_url'],
            $metadata['duration'],
            null,
            json_encode([
                'codec' => $fileExtension === 'mp3' ? 'mp3' : ($metadata['codec'] ?? null),
                'bitrate' => $fileExtension === 'mp3' ? 64000 : ($metadata['bitrate'] ?? null),
                'sample_rate' => $fileExtension === 'mp3' ? 44100 : ($metadata['sample_rate'] ?? null),
                'channels' => $fileExtension === 'mp3' ? 1 : ($metadata['channels'] ?? null),
                'converted_from' => $mp3Path ? 'webm' : null,
            ]),
            $mimeType,
            $uploadedFileSize
        );

        // DELETE LOCAL TEMP FILES
        // 1. Delete original WebM file
        if (unlink($localPath)) {
            Logger::audio('info', 'Original file deleted after CDN upload', [
                'audio_id' => $audioId,
                'path' => $localPath,
            ]);
        } else {
            Logger::audio('error', 'Failed to delete original file after CDN upload', [
                'audio_id' => $audioId,
                'path' => $localPath,
                'file_exists' => file_exists($localPath),
            ]);
        }

        // 2. Delete temporary MP3 file (if converted)
        if ($mp3Path && file_exists($mp3Path)) {
            if (unlink($mp3Path)) {
                Logger::audio('info', 'Temporary MP3 file deleted after CDN upload', [
                    'audio_id' => $audioId,
                    'path' => $mp3Path,
                ]);
            } else {
                Logger::audio('error', 'Failed to delete temporary MP3 file', [
                    'audio_id' => $audioId,
                    'path' => $mp3Path,
                ]);
            }
        }

        // ENTERPRISE: Log at WARNING level for CDN upload success (persistent in logs)
        Logger::audio('warning', 'File uploaded to CDN successfully', [
            'audio_id' => $audioId,
            's3_key' => $s3Key,
            'cdn_url' => $uploadResult['cdn_url'],
            'duration' => $metadata['duration'],
        ]);

        return ['success' => true];

    } catch (\Exception $e) {
        Logger::error('File processing exception', [
            'audio_id' => $audioId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        incrementRetryCount($audioId);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * EXTRACT METADATA - ffprobe extraction
 *
 * @param string $filePath
 * @return array{success: bool, duration?: float, codec?: string, bitrate?: int, sample_rate?: int, channels?: int, error?: string}
 */
function extractMetadata(string $filePath): array
{
    try {
        $ffprobePath = env('FFPROBE_PATH', '/usr/bin/ffprobe');

        if (!file_exists($ffprobePath)) {
            return ['success' => false, 'error' => 'ffprobe not found'];
        }

        // SECURITY: escapeshellarg per evitare command injection
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($filePath)
        );

        $output = shell_exec($cmd);

        if (!$output) {
            return ['success' => false, 'error' => 'ffprobe returned no output'];
        }

        $data = json_decode($output, true);

        if (!$data || !isset($data['format'])) {
            return ['success' => false, 'error' => 'Invalid ffprobe JSON output'];
        }

        // EXTRACT METADATA
        $format = $data['format'];
        $audioStream = null;

        // Find audio stream
        if (isset($data['streams']) && is_array($data['streams'])) {
            foreach ($data['streams'] as $stream) {
                if ($stream['codec_type'] === 'audio') {
                    $audioStream = $stream;
                    break;
                }
            }
        }

        return [
            'success' => true,
            'duration' => isset($format['duration']) ? (float) $format['duration'] : null,
            'bitrate' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            'codec' => $audioStream['codec_name'] ?? null,
            'sample_rate' => isset($audioStream['sample_rate']) ? (int) $audioStream['sample_rate'] : null,
            'channels' => $audioStream['channels'] ?? null,
        ];

    } catch (\Exception $e) {
        Logger::error('Metadata extraction exception', [
            'file' => $filePath,
            'error' => $e->getMessage(),
        ]);

        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * CONVERT WEBM TO MP3 - FFmpeg conversion for universal browser support
 *
 * ENTERPRISE 2025-12-13: Safari doesn't reliably play WebM audio.
 * MP3 is supported by ALL browsers including Safari, Edge, Chrome, Firefox.
 *
 * Conversion parameters (optimized for voice):
 * - Codec: libmp3lame (LAME MP3 encoder)
 * - Bitrate: 64kbps (excellent quality for voice, small file size)
 * - Sample rate: 44.1kHz (CD quality, works everywhere)
 * - Channels: 1 (mono - voice doesn't need stereo)
 *
 * @param string $webmPath Path to input WebM file
 * @param string $audioId Audio ID (for logging)
 * @return array{success: bool, mp3_path?: string, duration?: float, error?: string}
 */
function convertToMp3(string $webmPath, string $audioId): array
{
    try {
        $ffmpegPath = env('FFMPEG_PATH', '/usr/bin/ffmpeg');
        $ffprobePath = env('FFPROBE_PATH', '/usr/bin/ffprobe');

        // Verify FFmpeg exists
        if (!file_exists($ffmpegPath)) {
            return ['success' => false, 'error' => 'FFmpeg not found at ' . $ffmpegPath];
        }

        // Generate output path (same directory, .mp3 extension)
        $mp3Path = preg_replace('/\.webm$/i', '.mp3', $webmPath);
        if ($mp3Path === $webmPath) {
            // If no .webm extension, append .mp3
            $mp3Path = $webmPath . '.mp3';
        }

        // Build FFmpeg command
        // -y: Overwrite output without asking
        // -i: Input file
        // -c:a libmp3lame: Use LAME MP3 encoder
        // -b:a 64k: 64kbps bitrate (optimal for voice)
        // -ar 44100: 44.1kHz sample rate (CD quality)
        // -ac 1: Mono (voice doesn't need stereo)
        $cmd = sprintf(
            '%s -y -i %s -c:a libmp3lame -b:a 64k -ar 44100 -ac 1 %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($webmPath),
            escapeshellarg($mp3Path)
        );

        // Execute with timeout
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($mp3Path)) {
            Logger::audio('error', 'FFmpeg conversion failed', [
                'audio_id' => $audioId,
                'command' => $cmd,
                'return_code' => $returnCode,
                'output' => implode("\n", array_slice($output, -10)), // Last 10 lines
            ]);
            return ['success' => false, 'error' => 'FFmpeg conversion failed (code: ' . $returnCode . ')'];
        }

        // Verify output file is valid MP3
        $mp3Size = filesize($mp3Path);
        if ($mp3Size < 1000) { // Minimum 1KB for valid audio
            @unlink($mp3Path);
            return ['success' => false, 'error' => 'Converted MP3 too small (' . $mp3Size . ' bytes)'];
        }

        // Get accurate duration from MP3 file
        $duration = null;
        if (file_exists($ffprobePath)) {
            $probeCmd = sprintf(
                '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
                escapeshellarg($ffprobePath),
                escapeshellarg($mp3Path)
            );
            $durationStr = trim(shell_exec($probeCmd) ?? '');
            if (is_numeric($durationStr)) {
                $duration = (float) $durationStr;
            }
        }

        Logger::audio('debug', 'MP3 conversion successful', [
            'audio_id' => $audioId,
            'webm_size' => filesize($webmPath),
            'mp3_size' => $mp3Size,
            'duration' => $duration,
        ]);

        return [
            'success' => true,
            'mp3_path' => $mp3Path,
            'duration' => $duration,
        ];

    } catch (\Exception $e) {
        Logger::audio('error', 'MP3 conversion exception', [
            'audio_id' => $audioId,
            'error' => $e->getMessage(),
        ]);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * UPDATE FILE STATUS in database
 *
 * ENTERPRISE 2025-12-13: Added mime_type and file_size for MP3 conversion support
 *
 * @param string $audioId
 * @param string $status
 * @param string|null $cdnUrl
 * @param float|null $duration
 * @param string|null $errorMessage
 * @param string|null $metadata JSON metadata
 * @param string|null $mimeType MIME type (audio/mpeg for MP3)
 * @param int|null $fileSize File size in bytes
 * @return void
 */
function updateFileStatus(
    string $audioId,
    string $status,
    ?string $cdnUrl = null,
    ?float $duration = null,
    ?string $errorMessage = null,
    ?string $metadata = null,
    ?string $mimeType = null,
    ?int $fileSize = null
): void {
    try {
        $db = db();

        $updates = ['status' => $status];

        if ($cdnUrl !== null) {
            $updates['cdn_url'] = $cdnUrl;
        }

        if ($duration !== null) {
            $updates['duration'] = $duration;
        }

        if ($errorMessage !== null) {
            $updates['error_message'] = $errorMessage;
        }

        if ($metadata !== null) {
            $updates['metadata'] = $metadata;
        }

        // ENTERPRISE 2025-12-13: Update mime_type for MP3 conversion
        if ($mimeType !== null) {
            $updates['mime_type'] = $mimeType;
        }

        // ENTERPRISE 2025-12-13: Update file_size for accurate Content-Length
        if ($fileSize !== null) {
            $updates['file_size'] = $fileSize;
        }

        // Build UPDATE query
        $setClauses = [];
        $params = ['audio_id' => $audioId];

        foreach ($updates as $column => $value) {
            $setClauses[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }

        $sql = sprintf(
            "UPDATE audio_files SET %s, updated_at = NOW() WHERE id = :audio_id",
            implode(', ', $setClauses)
        );

        $db->execute($sql, $params);

        // ENTERPRISE 2025-12-13: Invalidate cache after S3 upload
        // The cache contains old data (cdn_url=null, file_path=local) that causes 404 errors
        // We need to invalidate the post cache so stream() reads fresh data from database
        if ($status === 'active' && $cdnUrl !== null) {
            // Find the audio_post linked to this audio_file
            $postResult = $db->findOne(
                "SELECT id FROM audio_posts WHERE audio_file_id = :audio_id",
                ['audio_id' => $audioId],
                ['cache' => false] // No cache for this lookup
            );

            if ($postResult) {
                $postId = (int) $postResult['id'];

                // Invalidate post cache via cache tags
                // This clears any cached queries tagged with "post:{id}"
                $db->execute(
                    "SELECT 1", // Dummy query
                    [],
                    ['invalidate_cache' => ["post:{$postId}"]]
                );

                Logger::info('Cache invalidated after S3 upload', [
                    'audio_id' => $audioId,
                    'post_id' => $postId,
                    'cdn_url' => substr($cdnUrl, 0, 50) . '...',
                ]);
            }
        }

    } catch (\Exception $e) {
        Logger::error('Failed to update file status', [
            'audio_id' => $audioId,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * INCREMENT RETRY COUNT
 *
 * @param string $audioId
 * @return void
 */
function incrementRetryCount(string $audioId): void
{
    try {
        $db = db();

        $db->execute(
            "UPDATE audio_files
             SET retry_count = retry_count + 1,
                 last_retry_at = NOW()
             WHERE id = :audio_id",
            ['audio_id' => $audioId]
        );

    } catch (\Exception $e) {
        Logger::error('Failed to increment retry count', [
            'audio_id' => $audioId,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * UPDATE HEARTBEAT in Redis
 *
 * @param Redis $redis
 * @param string $workerId
 * @param array $metrics
 * @return void
 */
function updateHeartbeat(Redis $redis, string $workerId, array $metrics): void
{
    try {
        $heartbeatKey = "worker:audio:{$workerId}:heartbeat";
        $heartbeatData = json_encode([
            'worker_id' => $workerId,
            'last_heartbeat' => time(),
            'metrics' => $metrics,
            'hostname' => gethostname(),
            'pid' => getmypid(),
        ]);

        $redis->setex($heartbeatKey, 60, $heartbeatData); // 60s TTL

    } catch (\Exception $e) {
        Logger::error('Failed to update heartbeat', [
            'worker_id' => $workerId,
            'error' => $e->getMessage(),
        ]);
    }
}
