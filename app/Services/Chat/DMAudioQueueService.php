<?php

declare(strict_types=1);

namespace Need2Talk\Services\Chat;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;
use Need2Talk\Services\WebSocketPublisher;

/**
 * DMAudioQueueService - Enterprise Async Queue for DM Audio E2E Messages
 *
 * PURPOSE: Decouple HTTP request from S3 upload to free PHP-FPM for real-time chat
 *
 * ARCHITECTURE:
 * - Producer (PHP-FPM): enqueue() saves temp file + pushes job to Redis (<5ms)
 * - Consumer (Worker): process() uploads to S3, saves to DB, notifies via WebSocket
 *
 * QUEUE STRUCTURE (Redis List):
 * - Queue: need2talk:queue:dm_audio
 * - Failed: need2talk:queue:dm_audio:failed
 * - Processing: need2talk:queue:dm_audio:processing (for visibility)
 *
 * JOB PAYLOAD:
 * {
 *   "job_id": "uuid",
 *   "conversation_uuid": "uuid",
 *   "sender_id": 123,
 *   "sender_uuid": "uuid",
 *   "temp_file_path": "/tmp/dm_audio_xxx.webm",
 *   "file_size": 180000,
 *   "duration_seconds": 25.5,
 *   "encryption_iv": "base64...",
 *   "encryption_tag": "base64...",
 *   "encryption_algorithm": "AES-256-GCM",
 *   "created_at": 1704067200,
 *   "attempts": 0
 * }
 *
 * PERFORMANCE:
 * - Enqueue: <5ms (Redis LPUSH + temp file write)
 * - Process: ~100-200ms (S3 upload + DB + WebSocket)
 * - Throughput: 50-100 jobs/min per worker
 * - Max workers: 4 (auto-scale based on queue depth)
 *
 * AUTO-SCALING RULES:
 * - Queue < 10: 1 worker
 * - Queue 10-50: 2 workers
 * - Queue 50-100: 3 workers
 * - Queue > 100: 4 workers (max)
 *
 * @package Need2Talk\Services\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-10
 * @version 1.0.0
 */
class DMAudioQueueService
{
    private const QUEUE_NAME = 'need2talk:queue:dm_audio';
    private const FAILED_QUEUE = 'need2talk:queue:dm_audio:failed';
    private const PROCESSING_SET = 'need2talk:queue:dm_audio:processing';
    private const METRICS_KEY = 'need2talk:metrics:dm_audio_queue';

    private const MAX_RETRIES = 3;
    private const JOB_TIMEOUT_SECONDS = 300; // 5 minutes max per job

    // ENTERPRISE V10.182: Use shared storage path (not /tmp) for Docker container compatibility
    // Both PHP-FPM and DM Audio Worker containers mount ./:/var/www/html
    private const TEMP_DIR = '/var/www/html/storage/temp/dm_audio_queue';

    private ?\Redis $redis = null;
    private ?DMAudioService $audioService = null;

    public function __construct()
    {
        $this->ensureTempDir();
    }

    /**
     * Get Redis connection (lazy initialization)
     */
    private function getRedis(): \Redis
    {
        if ($this->redis === null) {
            $this->redis = EnterpriseRedisManager::getInstance()->getConnection('queue');

            if (!$this->redis) {
                throw new \RuntimeException('Failed to get Redis queue connection');
            }
        }

        return $this->redis;
    }

    /**
     * Get DMAudioService (lazy initialization)
     */
    private function getAudioService(): DMAudioService
    {
        if ($this->audioService === null) {
            $this->audioService = new DMAudioService();
        }

        return $this->audioService;
    }

    /**
     * Ensure temp directory exists
     */
    private function ensureTempDir(): void
    {
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }
    }

    /**
     * PRODUCER: Enqueue audio upload job
     *
     * Called by DMController - must be FAST (<5ms)
     *
     * @param array $fileData $_FILES array element
     * @param string $conversationUuid Conversation UUID
     * @param int $senderId Sender user ID
     * @param string $senderUuid Sender UUID
     * @param float|null $duration Client-provided duration
     * @param string $encryptionIv Base64 IV for E2E decryption
     * @param string $encryptionTag Base64 TAG for E2E decryption
     * @return array{success: bool, job_id?: string, error?: string}
     */
    public function enqueue(
        array $fileData,
        string $conversationUuid,
        int $senderId,
        string $senderUuid,
        ?float $duration,
        string $encryptionIv,
        string $encryptionTag
    ): array {
        $startTime = microtime(true);

        try {
            // Validate file basics
            if (!isset($fileData['tmp_name']) || !is_uploaded_file($fileData['tmp_name'])) {
                return ['success' => false, 'error' => 'File non ricevuto correttamente'];
            }

            if ($fileData['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'Errore upload: ' . $fileData['error']];
            }

            // Check file size (500KB max)
            if ($fileData['size'] > DMAudioService::MAX_FILE_SIZE_BYTES) {
                $maxKb = DMAudioService::MAX_FILE_SIZE_BYTES / 1024;
                return ['success' => false, 'error' => "File troppo grande (max {$maxKb}KB)"];
            }

            // Generate job ID
            $jobId = $this->generateUuid();

            // Move uploaded file to queue temp directory
            $tempPath = self::TEMP_DIR . "/{$jobId}.webm";
            if (!move_uploaded_file($fileData['tmp_name'], $tempPath)) {
                return ['success' => false, 'error' => 'Errore salvataggio file temporaneo'];
            }

            // Build job payload
            $job = [
                'job_id' => $jobId,
                'conversation_uuid' => $conversationUuid,
                'sender_id' => $senderId,
                'sender_uuid' => $senderUuid,
                'temp_file_path' => $tempPath,
                'file_size' => $fileData['size'],
                'duration_seconds' => $duration,
                'encryption_iv' => $encryptionIv,
                'encryption_tag' => $encryptionTag,
                'encryption_algorithm' => 'AES-256-GCM',
                'created_at' => time(),
                'attempts' => 0,
            ];

            // Push to Redis queue
            $redis = $this->getRedis();
            $pushed = $redis->lPush(self::QUEUE_NAME, json_encode($job));

            if (!$pushed) {
                // Cleanup temp file on queue failure
                @unlink($tempPath);
                return ['success' => false, 'error' => 'Errore accodamento job'];
            }

            // Update metrics
            $redis->hIncrBy(self::METRICS_KEY, 'enqueued_total', 1);
            $redis->hSet(self::METRICS_KEY, 'last_enqueue_at', (string) time());

            $elapsed = (microtime(true) - $startTime) * 1000;

            Logger::audio('info', 'DM audio job enqueued', [
                'job_id' => $jobId,
                'conversation_uuid' => $conversationUuid,
                'sender_uuid' => $senderUuid,
                'file_size' => $fileData['size'],
                'duration' => $duration,
                'enqueue_time_ms' => round($elapsed, 2),
            ]);

            return [
                'success' => true,
                'job_id' => $jobId,
                'status' => 'queued',
            ];

        } catch (\Exception $e) {
            Logger::audio('error', 'DM audio enqueue failed', [
                'conversation_uuid' => $conversationUuid,
                'sender_uuid' => $senderUuid,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Errore interno accodamento'];
        }
    }

    /**
     * CONSUMER: Process next job from queue
     *
     * Called by dm-audio-worker.php in loop
     *
     * @param int $timeout BLPOP timeout in seconds
     * @return array{processed: bool, job_id?: string, error?: string}
     */
    public function processNext(int $timeout = 5): array
    {
        try {
            $redis = $this->getRedis();

            // Blocking pop with timeout
            $result = $redis->blPop([self::QUEUE_NAME], $timeout);

            if (!$result) {
                return ['processed' => false]; // No job available
            }

            $job = json_decode($result[1], true);

            if (!$job || !isset($job['job_id'])) {
                Logger::audio('warning', 'DM audio queue: Invalid job payload', ['raw' => $result[1]]);
                return ['processed' => false, 'error' => 'Invalid job payload'];
            }

            // Mark as processing
            $redis->sAdd(self::PROCESSING_SET, $job['job_id']);

            // Process the job
            $processResult = $this->processJob($job);

            // Remove from processing set
            $redis->sRem(self::PROCESSING_SET, $job['job_id']);

            if ($processResult['success']) {
                $redis->hIncrBy(self::METRICS_KEY, 'processed_total', 1);
                $redis->hSet(self::METRICS_KEY, 'last_process_at', (string) time());

                return [
                    'processed' => true,
                    'job_id' => $job['job_id'],
                ];
            } else {
                // Handle failure - retry or dead letter
                $this->handleJobFailure($job, $processResult['error'] ?? 'Unknown error');

                return [
                    'processed' => false,
                    'job_id' => $job['job_id'],
                    'error' => $processResult['error'],
                ];
            }

        } catch (\Exception $e) {
            Logger::audio('error', 'DM audio processNext failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['processed' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process a single job
     *
     * @param array $job Job payload
     * @return array{success: bool, s3_key?: string, error?: string}
     */
    private function processJob(array $job): array
    {
        $startTime = microtime(true);
        $jobId = $job['job_id'];

        try {
            $tempPath = $job['temp_file_path'];

            // Verify temp file exists
            if (!file_exists($tempPath)) {
                return ['success' => false, 'error' => 'Temp file not found: ' . $tempPath];
            }

            // Create pseudo $_FILES array for DMAudioService
            $fileData = [
                'tmp_name' => $tempPath,
                'size' => $job['file_size'],
                'error' => UPLOAD_ERR_OK,
                'type' => 'audio/webm',
                'name' => basename($tempPath),
            ];

            // Upload to S3 via DMAudioService
            $audioService = $this->getAudioService();
            $uploadResult = $audioService->uploadAudioFromPath(
                $tempPath,
                $job['sender_uuid'],
                $job['conversation_uuid'],
                $job['duration_seconds'],
                $job['encryption_iv']
            );

            if (!$uploadResult['success']) {
                return ['success' => false, 'error' => $uploadResult['error'] ?? 'S3 upload failed'];
            }

            // Save message to database
            $messageResult = $this->saveMessageToDatabase($job, $uploadResult);

            if (!$messageResult['success']) {
                // Cleanup S3 file on DB failure
                $audioService->deleteAudio($uploadResult['s3_key']);
                return ['success' => false, 'error' => $messageResult['error'] ?? 'DB save failed'];
            }

            // Send WebSocket notification to recipient
            $this->notifyRecipient($job, $uploadResult, $messageResult);

            // Cleanup temp file
            @unlink($tempPath);

            $elapsed = (microtime(true) - $startTime) * 1000;

            Logger::audio('info', 'DM audio job processed', [
                'job_id' => $jobId,
                'conversation_uuid' => $job['conversation_uuid'],
                's3_key' => $uploadResult['s3_key'],
                'message_uuid' => $messageResult['message_uuid'],
                'process_time_ms' => round($elapsed, 2),
            ]);

            return [
                'success' => true,
                's3_key' => $uploadResult['s3_key'],
                'message_uuid' => $messageResult['message_uuid'],
            ];

        } catch (\Exception $e) {
            Logger::audio('error', 'DM audio job processing failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Save DM message to database
     */
    private function saveMessageToDatabase(array $job, array $uploadResult): array
    {
        try {
            $db = db();

            // Get conversation ID
            $conversation = $db->findOne(
                "SELECT id, user1_uuid::text AS user1_uuid, user2_uuid::text AS user2_uuid
                 FROM direct_conversations WHERE uuid::text = ?",
                [$job['conversation_uuid']],
                ['cache' => false]
            );

            if (!$conversation) {
                return ['success' => false, 'error' => 'Conversation not found'];
            }

            $messageUuid = $this->generateUuid();
            $expiresAt = date('Y-m-d H:i:s', time() + DirectMessageService::MESSAGE_TTL_SECONDS);

            // ENTERPRISE V11.6: Include file_size_bytes for audio messages
            $fileSizeBytes = isset($job['file_size']) ? (int) $job['file_size'] : null;

            $db->execute(
                "INSERT INTO direct_messages
                 (uuid, conversation_id, sender_id, sender_uuid, message_type,
                  file_url, file_size_bytes, duration_seconds, status, created_at, expires_at,
                  audio_is_encrypted, audio_encryption_iv, audio_encryption_tag, audio_encryption_algorithm)
                 VALUES (?::uuid, ?, ?, ?::uuid, 'audio', ?, ?, ?, 'sent', NOW(), ?::timestamptz, TRUE, ?, ?, ?)",
                [
                    $messageUuid,
                    $conversation['id'],
                    $job['sender_id'],
                    $job['sender_uuid'],
                    $uploadResult['s3_key'],
                    $fileSizeBytes,
                    $uploadResult['duration'],
                    $expiresAt,
                    $job['encryption_iv'],
                    $job['encryption_tag'],
                    $job['encryption_algorithm'],
                ]
            );

            // Update conversation last_message
            $db->execute(
                "UPDATE direct_conversations
                 SET last_message_at = NOW(),
                     last_message_preview = '[Audio E2E]',
                     updated_at = NOW()
                 WHERE id = ?",
                [$conversation['id']]
            );

            // Increment unread count for recipient
            $isUser1Sender = $conversation['user1_uuid'] === $job['sender_uuid'];
            $unreadColumn = $isUser1Sender ? 'user2_unread_count' : 'user1_unread_count';

            $db->execute(
                "UPDATE direct_conversations SET {$unreadColumn} = {$unreadColumn} + 1 WHERE id = ?",
                [$conversation['id']]
            );

            return [
                'success' => true,
                'message_uuid' => $messageUuid,
                'conversation' => $conversation,
            ];

        } catch (\Exception $e) {
            Logger::audio('error', 'DM audio save to DB failed', [
                'job_id' => $job['job_id'],
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send WebSocket notifications to both sender and recipient
     */
    private function notifyRecipient(array $job, array $uploadResult, array $messageResult): void
    {
        try {
            $conversation = $messageResult['conversation'];

            // Determine recipient UUID
            $isUser1Sender = $conversation['user1_uuid'] === $job['sender_uuid'];
            $recipientUuid = $isUser1Sender ? $conversation['user2_uuid'] : $conversation['user1_uuid'];

            // Get sender info
            $db = db();
            $sender = $db->findOne(
                "SELECT nickname, avatar_url FROM users WHERE uuid::text = ?",
                [$job['sender_uuid']],
                ['cache' => true, 'cache_ttl' => 'medium']
            );

            // Generate signed URL for playback
            $audioService = $this->getAudioService();
            $signedUrl = $audioService->getSignedUrl($uploadResult['s3_key']);

            // Build message payload
            // ENTERPRISE V11.8: Include ALL fields required for MessageList rendering
            $messagePayload = [
                'id' => $messageResult['message_uuid'], // MessageList uses 'id' for DOM
                'uuid' => $messageResult['message_uuid'],
                'sender_uuid' => $job['sender_uuid'],
                'sender_nickname' => $sender['nickname'] ?? 'Utente',
                'sender_avatar' => get_avatar_url($sender['avatar_url'] ?? null),
                'message_type' => 'audio',
                'is_encrypted' => false, // Text encryption
                'deleted' => false, // CRITICAL: Prevent "rimosso dal moderatore" message
                'file_url' => $uploadResult['s3_key'],
                'audio_url' => $signedUrl,
                'duration_seconds' => $uploadResult['duration'],
                'audio_is_encrypted' => true,
                'audio_encryption_iv' => $job['encryption_iv'],
                'audio_encryption_tag' => $job['encryption_tag'],
                'audio_encryption_algorithm' => $job['encryption_algorithm'],
                'created_at' => time(),
                'expires_at' => date('Y-m-d H:i:s', time() + DirectMessageService::MESSAGE_TTL_SECONDS),
                'status' => 'sent', // Message status for UI
            ];

            // ENTERPRISE: Notify SENDER that audio upload completed (for UI update)
            WebSocketPublisher::publishToUser($job['sender_uuid'], 'dm_audio_uploaded', [
                'conversation_uuid' => $job['conversation_uuid'],
                'job_id' => $job['job_id'],
                'status' => 'completed',
                'message' => $messagePayload,
            ]);

            // ENTERPRISE: Notify RECIPIENT of new audio message
            WebSocketPublisher::publishToUser($recipientUuid, 'dm_received', [
                'conversation_uuid' => $job['conversation_uuid'],
                'message' => $messagePayload,
            ]);

            Logger::audio('debug', 'DM audio WebSocket notifications sent', [
                'sender_uuid' => $job['sender_uuid'],
                'recipient_uuid' => $recipientUuid,
                'message_uuid' => $messageResult['message_uuid'],
            ]);

        } catch (\Exception $e) {
            // Non-fatal - message is saved, notification can fail
            Logger::audio('warning', 'DM audio WebSocket notification failed', [
                'job_id' => $job['job_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle job failure - retry or dead letter
     */
    private function handleJobFailure(array $job, string $error): void
    {
        $job['attempts']++;
        $job['last_error'] = $error;
        $job['failed_at'] = time();

        $redis = $this->getRedis();

        if ($job['attempts'] < self::MAX_RETRIES) {
            // Re-queue for retry with exponential backoff
            $delay = pow(2, $job['attempts']) * 10; // 10s, 20s, 40s
            $job['retry_after'] = time() + $delay;

            // Use sorted set for delayed retry
            $redis->zAdd(
                self::QUEUE_NAME . ':delayed',
                $job['retry_after'],
                json_encode($job)
            );

            Logger::audio('warning', 'DM audio job queued for retry', [
                'job_id' => $job['job_id'],
                'attempt' => $job['attempts'],
                'retry_delay' => $delay,
                'error' => $error,
            ]);
        } else {
            // Move to failed queue (dead letter)
            $redis->lPush(self::FAILED_QUEUE, json_encode($job));
            $redis->hIncrBy(self::METRICS_KEY, 'failed_total', 1);

            // Cleanup temp file
            if (isset($job['temp_file_path']) && file_exists($job['temp_file_path'])) {
                @unlink($job['temp_file_path']);
            }

            Logger::audio('error', 'DM audio job moved to failed queue', [
                'job_id' => $job['job_id'],
                'attempts' => $job['attempts'],
                'error' => $error,
            ]);
        }
    }

    /**
     * Process delayed retry jobs
     * Called periodically by worker
     */
    public function processDelayedRetries(): int
    {
        try {
            $redis = $this->getRedis();
            $now = time();

            // Get jobs ready for retry
            $jobs = $redis->zRangeByScore(
                self::QUEUE_NAME . ':delayed',
                '-inf',
                (string) $now,
                ['limit' => [0, 10]]
            );

            $processed = 0;
            foreach ($jobs as $jobJson) {
                // Remove from delayed set
                $redis->zRem(self::QUEUE_NAME . ':delayed', $jobJson);

                // Re-queue for processing
                $redis->lPush(self::QUEUE_NAME, $jobJson);
                $processed++;
            }

            return $processed;

        } catch (\Exception $e) {
            Logger::audio('warning', 'DM audio delayed retry processing failed', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        try {
            $redis = $this->getRedis();

            return [
                'pending' => $redis->lLen(self::QUEUE_NAME),
                'processing' => $redis->sCard(self::PROCESSING_SET),
                'delayed' => $redis->zCard(self::QUEUE_NAME . ':delayed'),
                'failed' => $redis->lLen(self::FAILED_QUEUE),
                'metrics' => $redis->hGetAll(self::METRICS_KEY),
            ];

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get recommended worker count based on queue depth
     *
     * AUTO-SCALING RULES:
     * - Queue < 10: 1 worker
     * - Queue 10-50: 2 workers
     * - Queue 50-100: 3 workers
     * - Queue > 100: 4 workers (max)
     */
    public function getRecommendedWorkerCount(): int
    {
        try {
            $redis = $this->getRedis();
            $queueSize = $redis->lLen(self::QUEUE_NAME);

            if ($queueSize < 10) {
                return 1;
            } elseif ($queueSize < 50) {
                return 2;
            } elseif ($queueSize < 100) {
                return 3;
            } else {
                return 4; // Max workers
            }

        } catch (\Exception $e) {
            return 1; // Default to 1 worker on error
        }
    }

    /**
     * Cleanup old temp files (called by cron)
     */
    public function cleanupTempFiles(int $maxAgeSeconds = 3600): int
    {
        $cleaned = 0;
        $now = time();

        if (!is_dir(self::TEMP_DIR)) {
            return 0;
        }

        $files = glob(self::TEMP_DIR . '/*.webm');
        foreach ($files as $file) {
            if ($now - filemtime($file) > $maxAgeSeconds) {
                @unlink($file);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            Logger::audio('info', 'DM audio temp files cleaned', ['count' => $cleaned]);
        }

        return $cleaned;
    }

    /**
     * Generate UUID v4
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Reset Redis connection (for worker connection recycling)
     */
    public function resetConnection(): void
    {
        if ($this->redis !== null) {
            try {
                $this->redis->close();
            } catch (\Exception $e) {
                // Ignore close errors
            }
            $this->redis = null;
        }

        $this->audioService = null;
    }
}
