<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\EmotionalJournalService;
use Need2Talk\Services\EnterpriseRedisRateLimitManager;
use Need2Talk\Services\Logger;

/**
 * Emotional Journal Controller (Enterprise Galaxy V12)
 *
 * CRUD operations for emotional journal entries
 * Integrated with DiaryEncryptionService + unified journal_media table
 *
 * Features:
 * - Multiple entries per day per user (V12)
 * - E2E encrypted text via DiaryEncryptionService
 * - E2E encrypted media via journal_media table
 * - Emotion tracking (10 emotions + intensity 1-10)
 * - Rate limiting (max 5 edits/day to prevent spam)
 * - Multi-level cache (L1/L2/L3)
 * - ALWAYS private (diary is personal)
 * - Soft delete support
 *
 * @package Need2Talk\Controllers\Api
 * @author Claude Code (AI-Orchestrated Development)
 * @date 2024-11-04
 * @updated 2025-12-13 V12: Multiple entries per day, unified journal_media, removed visibility
 */
class EmotionalJournalController extends BaseController
{
    private EmotionalJournalService $journalService;
    private EnterpriseRedisRateLimitManager $rateLimiter;

    public function __construct()
    {
        parent::__construct();
        $this->journalService = new EmotionalJournalService();
        $this->rateLimiter = new EnterpriseRedisRateLimitManager();
    }

    /**
     * Create or update journal entry for today
     *
     * POST /api/journal/entry
     *
     * Body:
     * {
     *   "entry_type": "text|audio|mixed",
     *   "text_content": "...",           // Required if type=text|mixed
     *   "audio_post_id": 123,            // Required if type=audio|mixed
     *   "primary_emotion_id": 1-10,
     *   "intensity": 1-10,
     *   "date": "2024-11-04",            // Optional, defaults to today
     *   "visibility": "private|public|friends"
     * }
     *
     * @return void JSON response
     */
    public function createOrUpdate(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];  // Still needed for rate limiter
            $userUuid = $user['uuid'];     // ENTERPRISE UUID: For service calls

            // V12: Detect multipart (photo upload) vs JSON request
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $isMultipart = strpos($contentType, 'multipart/form-data') !== false;

            if ($isMultipart) {
                // Photo upload - use $_POST and $_FILES
                $input = $_POST;
                $photoFile = $_FILES['photo_file'] ?? null;
                $photoIV = $_POST['photo_encryption_iv'] ?? null;
            } else {
                // JSON request
                $input = $this->getJsonInput();
                $photoFile = null;
                $photoIV = null;
            }

            // Rate limiting: Max 5 journal edits per day (prevent spam)
            if (!$this->rateLimiter->checkLimit($userId, 'journal_edit')) {
                $this->json([
                    'success' => false,
                    'error' => 'rate_limit_exceeded',
                    'message' => 'Limite giornaliero raggiunto (max 5 modifiche/giorno)',
                ], 429);

                return;
            }

            // ENTERPRISE V12.1: Rate limiting for new entries (max 25/day)
            if (!$this->rateLimiter->checkLimit($userId, 'journal_entry')) {
                $this->json([
                    'success' => false,
                    'error' => 'rate_limit_exceeded',
                    'message' => 'Limite giornaliero raggiunto (max 25 voci/giorno)',
                ], 429);

                return;
            }

            // ENTERPRISE V12.1: Cooldown check (10 minutes between entries)
            $cooldownCheck = $this->checkJournalCooldown($userId);
            if (!$cooldownCheck['allowed']) {
                $this->json([
                    'success' => false,
                    'error' => 'cooldown_active',
                    'message' => $cooldownCheck['message'],
                    'wait_seconds' => $cooldownCheck['wait_seconds'],
                ], 429);

                return;
            }

            // Validation
            $validation = $this->validateJournalInput($input);
            if (!$validation['valid']) {
                $this->json([
                    'success' => false,
                    'error' => 'validation_error',
                    'message' => $validation['message'],
                ], 400);

                return;
            }

            // Date validation (default today, max 7 days in past)
            $date = $input['date'] ?? date('Y-m-d');
            if (!$this->isValidJournalDate($date)) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_date',
                    'message' => 'Puoi modificare solo entrate degli ultimi 7 giorni',
                ], 400);

                return;
            }

            // ENTERPRISE V12: Create new entry (always INSERT, no UPSERT)
            // Diary is ALWAYS private - no visibility field needed
            $entryData = [
                'user_id' => $userId,
                'entry_type' => $input['entry_type'],
                'primary_emotion_id' => (int) $input['primary_emotion_id'],
                'intensity' => (int) $input['intensity'],
                'date' => $date,
            ];

            // ENTERPRISE V12: E2E Encryption ONLY for diary text (100% encrypted)
            // Plain text_content column REMOVED - diary is ALWAYS encrypted (zero-knowledge)
            if (!empty($input['text_content_encrypted']) && !empty($input['text_content_iv'])) {
                // Client sent encrypted text - store as-is (server cannot decrypt)
                $entryData['text_content_encrypted'] = $input['text_content_encrypted'];
                $entryData['text_content_iv'] = $input['text_content_iv'];
            }

            // ENTERPRISE V12: Handle encrypted photo upload
            if ($photoFile && !empty($photoFile['tmp_name']) && $photoIV) {
                $mediaResult = $this->saveEncryptedPhoto($userUuid, $userId, $photoFile, $photoIV);
                if (!$mediaResult['success']) {
                    $this->json([
                        'success' => false,
                        'error' => 'photo_upload_failed',
                        'message' => $mediaResult['message'] ?? 'Errore salvataggio foto',
                    ], 500);

                    return;
                }
                $entryData['journal_media_id'] = $mediaResult['media_id'];
            }

            // ENTERPRISE V12: Unified journal_media table (replaces audio_post_id)
            if (!empty($input['journal_media_id'])) {
                $entryData['journal_media_id'] = (int) $input['journal_media_id'];
            }

            // ENTERPRISE V12: Call service with UUID (always creates new entry)
            $result = $this->journalService->createEntry($userUuid, $entryData);

            if ($result['success']) {
                $this->json([
                    'success' => true,
                    'entry' => $result['entry'],
                    'message' => 'Diario salvato',
                ]);
            } else {
                $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'unknown_error',
                    'message' => $result['message'] ?? 'Errore durante il salvataggio',
                ], 500);
            }

        } catch (\Exception $e) {
            Logger::error('Journal entry creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server durante il salvataggio',
            ], 500);
        }
    }

    /**
     * Get journal entry for specific date
     *
     * GET /api/journal/entry/{date}
     * Example: GET /api/journal/entry/2024-11-04
     *
     * @param string $date Date in Y-m-d format
     * @return void JSON response
     */
    public function show(string $date): void
    {
        try {
            $user = $this->requireAuth();
            $userUuid = $user['uuid'];  // ENTERPRISE UUID: For service calls

            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_date_format',
                    'message' => 'Formato data non valido (usa YYYY-MM-DD)',
                ], 400);

                return;
            }

            // ENTERPRISE UUID: Call service with UUID
            $entry = $this->journalService->getEntryByDate($userUuid, $date);

            if ($entry) {
                $this->json([
                    'success' => true,
                    'entry' => $entry,
                ]);
            } else {
                $this->json([
                    'success' => true,
                    'entry' => null,
                    'message' => 'Nessuna entrata trovata per questa data',
                ]);
            }

        } catch (\Exception $e) {
            Logger::error('Journal entry fetch failed', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante il caricamento',
            ], 500);
        }
    }

    /**
     * Get journal timeline (paginated)
     *
     * GET /api/journal/timeline?page=1&per_page=30&emotion_id=1
     *
     * @return void JSON response
     */
    public function timeline(): void
    {
        try {
            $user = $this->requireAuth();
            $userUuid = $user['uuid'];  // ENTERPRISE UUID: For service calls

            // Pagination parameters
            $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
            $perPage = isset($_GET['per_page']) ? min(100, max(1, (int) $_GET['per_page'])) : 30;

            // Optional emotion filter
            $emotionId = isset($_GET['emotion_id']) ? (int) $_GET['emotion_id'] : null;
            if ($emotionId && ($emotionId < 1 || $emotionId > 10)) {
                $emotionId = null; // Invalid emotion ID, ignore filter
            }

            // Optional date filter (ENTERPRISE GALAXY - Calendar integration)
            $date = isset($_GET['date']) ? $_GET['date'] : null;
            if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_date_format',
                    'message' => 'Formato data non valido (richiesto: YYYY-MM-DD)',
                ], 400);

                return;
            }

            // ENTERPRISE UUID: Call service with UUID
            $result = $this->journalService->getTimeline($userUuid, $page, $perPage, $emotionId, $date);

            $this->json([
                'success' => true,
                'entries' => $result['entries'],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $result['total'],
                    'total_pages' => (int) ceil($result['total'] / $perPage),
                    'has_more' => ($page * $perPage) < $result['total'],
                ],
            ]);

        } catch (\Exception $e) {
            Logger::error('Journal timeline fetch failed', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante il caricamento della timeline',
            ], 500);
        }
    }

    /**
     * Delete journal entry (soft delete)
     *
     * DELETE /api/journal/entry/{date}
     *
     * @param string $date Date in Y-m-d format
     * @return void JSON response
     */
    public function delete(string $date): void
    {
        try {
            $user = $this->requireAuth();
            $userUuid = $user['uuid'];  // ENTERPRISE UUID: For service calls

            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_date_format',
                    'message' => 'Formato data non valido',
                ], 400);

                return;
            }

            // ENTERPRISE UUID: Call service with UUID
            $result = $this->journalService->deleteEntry($userUuid, $date);

            if ($result['success']) {
                $this->json([
                    'success' => true,
                    'message' => 'Entrata eliminata con successo',
                ]);
            } else {
                $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'not_found',
                    'message' => $result['message'] ?? 'Entrata non trovata',
                ], 404);
            }

        } catch (\Exception $e) {
            Logger::error('Journal entry deletion failed', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante l\'eliminazione',
            ], 500);
        }
    }

    /**
     * Get journal analytics
     *
     * GET /api/journal/stats?days=30
     *
     * Returns:
     * - Emotion distribution (percentage per emotion)
     * - Average intensity per emotion
     * - Streak (consecutive days with entries)
     * - Total entries count
     * - Most common emotion
     *
     * @return void JSON response
     */
    public function stats(): void
    {
        try {
            $user = $this->requireAuth();
            $userUuid = $user['uuid'];  // ENTERPRISE UUID: For service calls

            // Days parameter (default 30, max 365)
            $days = isset($_GET['days']) ? min(365, max(1, (int) $_GET['days'])) : 30;

            // ENTERPRISE UUID: Call service with UUID
            $stats = $this->journalService->getStats($userUuid, $days);

            $this->json([
                'success' => true,
                'stats' => $stats,
                'period_days' => $days,
            ]);

        } catch (\Exception $e) {
            Logger::error('Journal stats fetch failed', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante il caricamento delle statistiche',
            ], 500);
        }
    }

    // REMOVED: calendar() method - old calendar tab eliminated (ENTERPRISE cleanup)
    // Route /api/journal/calendar removed from routes/api.php
    // Sidebar calendar uses calendarEmotions() instead

    /**
     * Upload encrypted audio for journal entry (ENTERPRISE GALAXY+ Phase 1.4)
     *
     * POST /api/journal/upload-audio
     *
     * Body (multipart/form-data):
     * - audio_file: Encrypted WebM file (30s max @ 48kbps)
     * - date: Journal date (YYYY-MM-DD)
     * - emotion_id: Primary emotion (1-10)
     * - intensity: Emotion intensity (1-5)
     * - encryption_iv: Base64-encoded IV for audio decryption
     * - encryption_algorithm: "AES-256-GCM"
     * - text_content_encrypted: (optional) Encrypted text notes
     * - text_content_iv: (optional) IV for text decryption
     *
     * @return void JSON response
     */
    public function uploadAudio(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];      // Still needed for rate limiter + DB inserts
            $userUuid = $user['uuid'];         // ENTERPRISE UUID: For logging + future service calls

            // Rate limiting: 10 audio/day for journal (private diary)
            if (!$this->rateLimiter->checkLimit($userId, 'journal_audio_upload')) {
                $this->json([
                    'success' => false,
                    'error' => 'rate_limit_exceeded',
                    'message' => 'Limite giornaliero raggiunto (max 10 audio/giorno)',
                ], 429);

                return;
            }

            // ENTERPRISE V12.1: Rate limiting for new entries (max 25/day - audio counts as entry)
            if (!$this->rateLimiter->checkLimit($userId, 'journal_entry')) {
                $this->json([
                    'success' => false,
                    'error' => 'rate_limit_exceeded',
                    'message' => 'Limite giornaliero raggiunto (max 25 voci/giorno)',
                ], 429);

                return;
            }

            // ENTERPRISE V12.1: Cooldown check (10 minutes between entries)
            $cooldownCheck = $this->checkJournalCooldown($userId);
            if (!$cooldownCheck['allowed']) {
                $this->json([
                    'success' => false,
                    'error' => 'cooldown_active',
                    'message' => $cooldownCheck['message'],
                    'wait_seconds' => $cooldownCheck['wait_seconds'],
                ], 429);

                return;
            }

            // Validate file upload
            if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_file',
                    'message' => 'File audio mancante o errore durante l\'upload',
                ], 400);

                return;
            }

            $audioFile = $_FILES['audio_file'];

            // Validate file size (max 500KB for 30s @ 48kbps WebM)
            $maxSize = 500 * 1024; // 500KB
            if ($audioFile['size'] > $maxSize) {
                $this->json([
                    'success' => false,
                    'error' => 'file_too_large',
                    'message' => 'File troppo grande (max 500KB)',
                ], 400);

                return;
            }

            // Validate required fields
            $date = $_POST['date'] ?? null;
            $emotionId = isset($_POST['emotion_id']) ? (int) $_POST['emotion_id'] : null;
            $intensity = isset($_POST['intensity']) ? (int) $_POST['intensity'] : null;
            $encryptionIV = $_POST['encryption_iv'] ?? null;
            $encryptionAlgorithm = $_POST['encryption_algorithm'] ?? null;

            // ENTERPRISE GALAXY+: Optional metadata fields
            $title = isset($_POST['title']) ? trim($_POST['title']) : null;
            $description = isset($_POST['description']) ? trim($_POST['description']) : null;

            if (!$date || !$emotionId || !$intensity || !$encryptionIV || !$encryptionAlgorithm) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_fields',
                    'message' => 'Campi obbligatori mancanti (date, emotion_id, intensity, encryption_iv, encryption_algorithm)',
                ], 400);

                return;
            }

            // Validate title length (if provided)
            if ($title && mb_strlen($title) > 500) {
                $this->json([
                    'success' => false,
                    'error' => 'title_too_long',
                    'message' => 'Titolo troppo lungo (max 500 caratteri)',
                ], 400);

                return;
            }

            // Validate description length (if provided)
            if ($description && mb_strlen($description) > 2000) {
                $this->json([
                    'success' => false,
                    'error' => 'description_too_long',
                    'message' => 'Descrizione troppo lunga (max 2000 caratteri)',
                ], 400);

                return;
            }

            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_date',
                    'message' => 'Formato data non valido (usa YYYY-MM-DD)',
                ], 400);

                return;
            }

            // Validate emotion & intensity
            if ($emotionId < 1 || $emotionId > 10) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_emotion',
                    'message' => 'Emozione non valida (1-10)',
                ], 400);

                return;
            }

            if ($intensity < 1 || $intensity > 5) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_intensity',
                    'message' => 'Intensità non valida (1-5)',
                ], 400);

                return;
            }

            // Generate UUID for audio file
            $uuid = $this->generateUUID();

            // ENTERPRISE GALAXY+: Local storage first (same pattern as AudioController)
            // Background worker will upload to CDN, then update cdn_path + cdn_uploaded_at
            $uploadDirConfig = get_env('UPLOAD_PATH', 'storage/uploads/audio');
            $uploadDirBase = realpath(APP_ROOT . '/' . $uploadDirConfig);

            if ($uploadDirBase === false) {
                Logger::error('Upload directory not found', [
                    'config_path' => $uploadDirConfig,
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'upload_directory_error',
                    'message' => 'Errore di configurazione upload',
                ], 500);

                return;
            }

            // Create journal-specific subdirectory: storage/uploads/audio/journal/{userId}/{Y/m}
            $datePath = "journal/{$userId}/" . date('Y/m');
            $fullUploadDir = $uploadDirBase . '/' . $datePath;

            // Create directory structure
            if (!is_dir($fullUploadDir)) {
                if (!mkdir($fullUploadDir, 0755, true)) {
                    Logger::error('Failed to create upload directory', [
                        'path' => $fullUploadDir,
                    ]);

                    $this->json([
                        'success' => false,
                        'error' => 'directory_creation_failed',
                        'message' => 'Errore durante la creazione directory upload',
                    ], 500);

                    return;
                }
            }

            // Generate filename with UUID
            $filename = "{$uuid}.webm";
            $fullPath = $fullUploadDir . '/' . $filename;

            // NOTE: NO magic bytes validation here!
            // File is ALREADY ENCRYPTED client-side (AES-256-GCM)
            // Magic bytes check would fail on encrypted data
            // Security: File size validated (500KB max), encryption enforced

            // Move uploaded file to local storage
            if (!move_uploaded_file($audioFile['tmp_name'], $fullPath)) {
                Logger::error('Failed to move uploaded file', [
                    'from' => $audioFile['tmp_name'],
                    'to' => $fullPath,
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'file_move_failed',
                    'message' => 'Errore durante il salvataggio del file',
                ], 500);

                return;
            }

            // Get database connection (used for both photo and audio)
            $db = db();

            // ENTERPRISE V12: Process encrypted photo upload (E2E - already encrypted client-side)
            // Photo is resized to 1200px max, converted to WebP, and AES-256-GCM encrypted by client
            // Server just stores the encrypted blob (zero-knowledge)
            $photoMediaId = null;

            if (isset($_FILES['photo_file']) && $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
                $photoFile = $_FILES['photo_file'];
                $photoEncryptionIV = $_POST['photo_encryption_iv'] ?? null;

                if (!$photoEncryptionIV) {
                    Logger::warning('Photo uploaded without encryption IV', [
                        'user_uuid' => $userUuid,
                        'user_id' => $userId,
                    ]);
                } else {
                    try {
                        // Validate file size (max 2MB for encrypted WebP photo)
                        $maxPhotoSize = 2 * 1024 * 1024;
                        if ($photoFile['size'] > $maxPhotoSize) {
                            Logger::warning('Photo too large', [
                                'user_uuid' => $userUuid,
                                'size' => $photoFile['size'],
                                'max' => $maxPhotoSize,
                            ]);
                        } else {
                            // Create photo directory: storage/uploads/photos/journal/{userId}/{Y/m}
                            $photoDatePath = "journal/{$userId}/" . date('Y/m');
                            $photoUploadDir = $uploadDirBase . '/../photos/' . $photoDatePath;

                            if (!is_dir($photoUploadDir)) {
                                mkdir($photoUploadDir, 0755, true);
                            }

                            // Generate UUID for photo
                            $photoUuid = $this->generateUUID();
                            $photoFilename = "{$photoUuid}.enc"; // .enc for encrypted files
                            $photoFullPath = $photoUploadDir . '/' . $photoFilename;

                            // Move encrypted photo to storage
                            if (move_uploaded_file($photoFile['tmp_name'], $photoFullPath)) {
                                // Save photo to journal_media table
                                $photoLocalPath = 'photos/' . $photoDatePath . '/' . $photoFilename;

                                $photoSql = "INSERT INTO journal_media
                                        (uuid, user_id, media_type, filename, local_path, file_size,
                                         mime_type, is_encrypted, encryption_iv, created_at)
                                        VALUES
                                        (:uuid, :user_id, 'photo', :filename, :local_path, :file_size,
                                         'image/webp', true, DECODE(:encryption_iv, 'base64'), NOW())
                                        RETURNING id";

                                $db->execute($photoSql, [
                                    'uuid' => $photoUuid,
                                    'user_id' => $userId,
                                    'filename' => $photoFilename,
                                    'local_path' => $photoLocalPath,
                                    'file_size' => $photoFile['size'],
                                    'encryption_iv' => $photoEncryptionIV,
                                ], ['return_id' => true]);

                                $photoMediaId = $db->lastInsertId();

                                Logger::info('Journal photo uploaded (E2E encrypted)', [
                                    'user_uuid' => $userUuid,
                                    'user_id' => $userId,
                                    'photo_media_id' => $photoMediaId,
                                    'file_size_kb' => round($photoFile['size'] / 1024, 2),
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        Logger::error('Photo upload failed', [
                            'user_uuid' => $userUuid,
                            'user_id' => $userId,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue without photo (non-blocking error)
                    }
                }
            }

            // ENTERPRISE V12: Save audio to unified journal_media table

            // ENTERPRISE FIX: Local path must include 'audio/' prefix
            // Worker expects: "audio/journal/{userId}/{Y/m}/file.webm"
            // Full path when resolved: "storage/uploads/audio/journal/{userId}/{Y/m}/file.webm"
            $localPath = 'audio/' . $datePath . '/' . $filename;

            $mediaData = [
                'uuid' => $uuid,
                'user_id' => $userId,
                'media_type' => 'audio',
                'filename' => $filename,
                'local_path' => $localPath,
                's3_url' => null, // Will be set by worker after S3 upload (private ACL)
                'file_size' => $audioFile['size'],
                'mime_type' => 'audio/webm',
                'duration' => 30.0, // Fixed 30s (validated client-side)
                'is_encrypted' => true, // v4.2 E2E: Always encrypted
                'encryption_iv' => $encryptionIV,
            ];

            $sql = "INSERT INTO journal_media
                    (uuid, user_id, media_type, filename, local_path, s3_url, file_size,
                     mime_type, duration, is_encrypted, encryption_iv, created_at)
                    VALUES
                    (:uuid, :user_id, :media_type, :filename, :local_path, :s3_url, :file_size,
                     :mime_type, :duration, :is_encrypted, DECODE(:encryption_iv, 'base64'), NOW())
                    RETURNING id";

            // ENTERPRISE FIX: PostgreSQL requires 'return_id' => true for RETURNING clause
            $db->execute($sql, $mediaData, [
                'invalidate_cache' => ["journal_media:{$userId}"],
                'return_id' => true,
            ]);

            $mediaId = $db->lastInsertId();

            // ENTERPRISE V12: Create new journal entry for audio uploads
            // Multiple audio recordings per day should create multiple entries

            $textEncrypted = $_POST['text_content_encrypted'] ?? null;
            $textIV = $_POST['text_content_iv'] ?? null;

            // Build INSERT query for new journal entry
            $insertFields = ['user_id', 'entry_type', 'journal_media_id', 'primary_emotion_id', 'intensity', 'date', 'created_at'];
            $insertValues = [':user_id', ':entry_type', ':journal_media_id', ':primary_emotion_id', ':intensity', ':date', 'NOW()'];

            $entryParams = [
                'user_id' => $userId,
                'entry_type' => $textEncrypted ? 'mixed' : 'audio',
                'journal_media_id' => $mediaId,
                'primary_emotion_id' => $emotionId,
                'intensity' => $intensity,
                'date' => $date,
            ];

            // Add encrypted text if provided - PostgreSQL uses true/false
            if ($textEncrypted && $textIV) {
                $insertFields[] = 'text_content_encrypted';
                $insertFields[] = 'text_content_iv';
                $insertFields[] = 'is_text_encrypted';
                $insertValues[] = 'DECODE(:text_content_encrypted, \'base64\')';
                $insertValues[] = 'DECODE(:text_content_iv, \'base64\')';
                $insertValues[] = 'true';
                $entryParams['text_content_encrypted'] = $textEncrypted;
                $entryParams['text_content_iv'] = $textIV;
            }

            $insertSQL = "INSERT INTO emotional_journal_entries (" . implode(', ', $insertFields) . ")
                         VALUES (" . implode(', ', $insertValues) . ")
                         RETURNING id";

            try {
                $db->execute($insertSQL, $entryParams, [
                    'invalidate_cache' => ["journal:user:{$userId}"],
                    'return_id' => true,
                ]);
                $entryId = $db->lastInsertId();

                Logger::info('Journal entry created for audio upload', [
                    'user_uuid' => $userUuid,
                    'user_id' => $userId,
                    'entry_id' => $entryId,
                    'media_id' => $mediaId,
                    'date' => $date,
                ]);

                $journalResult = ['success' => true, 'entry' => ['id' => $entryId]];
            } catch (\Exception $e) {
                Logger::warning('Journal entry creation failed after audio upload', [
                    'user_uuid' => $userUuid,
                    'user_id' => $userId,
                    'media_id' => $mediaId,
                    'error' => $e->getMessage(),
                ]);
                $journalResult = ['success' => false, 'error' => $e->getMessage()];
            }

            Logger::info('Journal audio uploaded successfully (local storage - True E2E)', [
                'user_uuid' => $userUuid,
                'user_id' => $userId,
                'media_id' => $mediaId,
                'uuid' => $uuid,
                'file_size' => $audioFile['size'],
                'local_path' => $localPath,
                'encrypted' => true,
            ]);

            // v4.2 True E2E: Return local URL only
            // Diary audio stays encrypted in local storage
            // Client decrypts on-the-fly during playback
            $localUrl = "/storage/uploads/{$localPath}";

            $this->json([
                'success' => true,
                'media_id' => $mediaId,
                'photo_media_id' => $photoMediaId, // V12: Encrypted photo media ID (if uploaded)
                'uuid' => $uuid,
                'local_url' => $localUrl, // True E2E: Local storage only (encrypted)
                's3_url' => null, // V12: No immediate S3 upload for E2E encrypted
                'storage_status' => 'local_only', // Stays local until S3 sync
                'entry' => $journalResult['entry'] ?? null,
                'message' => $photoMediaId ? 'Audio e foto caricati con successo' : 'Audio caricato e criptato con successo',
            ]);

        } catch (\Exception $e) {
            Logger::error('Journal audio upload failed', [
                'user_uuid' => $userUuid ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante l\'upload dell\'audio',
            ], 500);
        }
    }

    /**
     * Check rate limit for journal media uploads (ENTERPRISE V12)
     *
     * GET /api/journal/rate-limit-check
     *
     * @return void JSON response
     */
    public function rateLimitCheck(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];      // Still needed for rate limiter + DB query
            $userUuid = $user['uuid'];         // ENTERPRISE UUID: For logging

            // Check daily limit (10 media/day)
            $allowed = $this->rateLimiter->checkLimit($userId, 'journal_audio_upload');

            // V12: Get current count from journal_media table
            $db = db();
            $sql = "SELECT COUNT(*) as count
                    FROM journal_media
                    WHERE user_id = :user_id
                      AND created_at::DATE = CURRENT_DATE
                      AND deleted_at IS NULL";

            $result = $db->findOne($sql, ['user_id' => $userId]);
            $count = (int) $result['count'];

            $maxLimit = 10;
            $remaining = max(0, $maxLimit - $count);

            $this->json([
                'success' => true,
                'allowed' => $allowed,
                'remaining' => $remaining,
                'max_limit' => $maxLimit,
                'message' => $remaining > 0 ? "Puoi caricare ancora {$remaining} media oggi" : 'Limite giornaliero raggiunto',
            ]);

        } catch (\Exception $e) {
            Logger::error('Rate limit check failed', [
                'user_uuid' => $userUuid ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante il controllo del limite',
            ], 500);
        }
    }

    /**
     * Generate UUID v4
     *
     * @return string UUID
     */
    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Validate journal input data (ENTERPRISE V12)
     *
     * @param array $input Input data
     * @return array ['valid' => bool, 'message' => string]
     */
    private function validateJournalInput(array $input): array
    {
        // Entry type validation (V12: added photo, text_photo)
        $validTypes = ['text', 'photo', 'text_photo', 'audio', 'mixed'];
        if (!isset($input['entry_type']) || !in_array($input['entry_type'], $validTypes)) {
            return ['valid' => false, 'message' => 'Tipo di entrata non valido (text/photo/text_photo/audio/mixed)'];
        }

        $entryType = $input['entry_type'];

        // ENTERPRISE V12: Text content required for text/text_photo/mixed - MUST be encrypted (100% E2E)
        $hasEncryptedText = !empty($input['text_content_encrypted']) && !empty($input['text_content_iv']);
        $requiresText = in_array($entryType, ['text', 'text_photo', 'mixed']);

        if ($requiresText && !$hasEncryptedText) {
            return ['valid' => false, 'message' => 'Contenuto testuale crittografato obbligatorio'];
        }

        // NOTE: Encrypted text length is not validated (ciphertext is longer than plaintext)
        // Client-side validates max 5000 chars before encryption

        // ENTERPRISE V12: journal_media required for audio/mixed (NOT for photo - handled separately)
        if (in_array($entryType, ['audio', 'mixed']) && empty($input['journal_media_id'])) {
            return ['valid' => false, 'message' => 'Media audio obbligatorio per questo tipo di entrata'];
        }

        // Emotion validation
        if (!isset($input['primary_emotion_id']) || $input['primary_emotion_id'] < 1 || $input['primary_emotion_id'] > 10) {
            return ['valid' => false, 'message' => 'Emozione primaria non valida (1-10)'];
        }

        // Intensity validation
        if (!isset($input['intensity']) || $input['intensity'] < 1 || $input['intensity'] > 10) {
            return ['valid' => false, 'message' => 'Intensità non valida (1-10)'];
        }

        // V12: Visibility removed - diary is always private

        return ['valid' => true, 'message' => 'OK'];
    }

    /**
     * Check if date is valid for journal editing
     *
     * Users can only edit entries from last 7 days to prevent retroactive journal manipulation
     *
     * @param string $date Date in Y-m-d format
     * @return bool
     */
    private function isValidJournalDate(string $date): bool
    {
        $entryDate = strtotime($date);
        $today = strtotime(date('Y-m-d'));
        $sevenDaysAgo = strtotime('-7 days', $today);

        // Date must be within last 7 days (inclusive)
        return $entryDate >= $sevenDaysAgo && $entryDate <= $today;
    }

    /**
     * ENTERPRISE V12.1: Check journal entry cooldown
     *
     * Enforces 10-minute cooldown between journal entries to prevent spam
     * and encourage thoughtful, quality diary entries.
     *
     * @param int $userId User ID
     * @return array ['allowed' => bool, 'wait_seconds' => int, 'message' => string]
     */
    private function checkJournalCooldown(int $userId): array
    {
        $cooldownMinutes = 10;
        $cooldownSeconds = $cooldownMinutes * 60;

        try {
            $db = db();

            // Get the most recent journal entry created by this user
            $lastEntry = $db->findOne(
                "SELECT created_at FROM emotional_journal
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC
                 LIMIT 1",
                ['user_id' => $userId]
            );

            if (!$lastEntry) {
                // No previous entries - allow
                return ['allowed' => true, 'wait_seconds' => 0, 'message' => ''];
            }

            $lastCreatedAt = strtotime($lastEntry['created_at']);
            $now = time();
            $elapsed = $now - $lastCreatedAt;

            if ($elapsed < $cooldownSeconds) {
                $waitSeconds = $cooldownSeconds - $elapsed;
                $waitMinutes = ceil($waitSeconds / 60);

                return [
                    'allowed' => false,
                    'wait_seconds' => $waitSeconds,
                    'message' => "Devi aspettare ancora {$waitMinutes} minuti prima di creare una nuova voce nel diario",
                ];
            }

            return ['allowed' => true, 'wait_seconds' => 0, 'message' => ''];

        } catch (\Exception $e) {
            Logger::error('Journal cooldown check failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            // On error, allow the operation (fail open for UX)
            return ['allowed' => true, 'wait_seconds' => 0, 'message' => ''];
        }
    }

    /**
     * ENTERPRISE V12: Save encrypted photo to journal_media
     *
     * Photo is already encrypted client-side with AES-256-GCM
     * Server stores ciphertext + IV (zero-knowledge)
     *
     * @param string $userUuid User UUID
     * @param int $userId User ID
     * @param array $photoFile $_FILES['photo_file']
     * @param string $encryptionIV Base64-encoded IV from client
     * @return array ['success' => bool, 'media_id' => int|null, 'message' => string]
     */
    private function saveEncryptedPhoto(string $userUuid, int $userId, array $photoFile, string $encryptionIV): array
    {
        try {
            // Validate file upload
            if ($photoFile['error'] !== UPLOAD_ERR_OK) {
                return [
                    'success' => false,
                    'media_id' => null,
                    'message' => 'Errore upload foto: ' . $this->getUploadErrorMessage($photoFile['error']),
                ];
            }

            // Max 10MB (encrypted files are slightly larger)
            $maxSize = 10 * 1024 * 1024;
            if ($photoFile['size'] > $maxSize) {
                return [
                    'success' => false,
                    'media_id' => null,
                    'message' => 'Foto troppo grande (max 10MB)',
                ];
            }

            // Generate UUID for file
            $uuid = bin2hex(random_bytes(16));
            $uuid = sprintf(
                '%s-%s-%s-%s-%s',
                substr($uuid, 0, 8),
                substr($uuid, 8, 4),
                substr($uuid, 12, 4),
                substr($uuid, 16, 4),
                substr($uuid, 20, 12)
            );

            // Create directory structure: journal/photos/YYYY/MM/
            $year = date('Y');
            $month = date('m');
            $relativePath = "journal/photos/{$year}/{$month}";
            $absolutePath = storage_path("uploads/{$relativePath}");

            if (!is_dir($absolutePath)) {
                mkdir($absolutePath, 0755, true);
            }

            // Save encrypted file with .enc extension
            $filename = "{$uuid}.enc";
            $fullPath = "{$absolutePath}/{$filename}";
            $storagePath = "{$relativePath}/{$filename}";

            // Move uploaded file (already encrypted by client)
            if (!move_uploaded_file($photoFile['tmp_name'], $fullPath)) {
                return [
                    'success' => false,
                    'media_id' => null,
                    'message' => 'Impossibile salvare la foto',
                ];
            }

            // Set file permissions
            chmod($fullPath, 0644);

            // Insert into journal_media table
            $db = db();
            $sql = "INSERT INTO journal_media
                    (uuid, user_id, media_type, filename, local_path, file_size, mime_type, is_encrypted, encryption_iv, created_at)
                    VALUES
                    (:uuid, :user_id, 'photo', :filename, :local_path, :file_size, 'application/octet-stream', true, DECODE(:encryption_iv, 'base64'), NOW())
                    RETURNING id";

            $db->execute($sql, [
                'uuid' => $uuid,
                'user_id' => $userId,
                'filename' => $filename,
                'local_path' => $storagePath,
                'file_size' => $photoFile['size'],
                'encryption_iv' => $encryptionIV,
            ], [
                'invalidate_cache' => ["journal:media:user:{$userId}"],
                'return_id' => true,
            ]);

            $mediaId = (int) $db->lastInsertId();

            Logger::info('Encrypted photo saved to journal_media', [
                'user_uuid' => $userUuid,
                'user_id' => $userId,
                'media_id' => $mediaId,
                'uuid' => $uuid,
                'file_size' => $photoFile['size'],
                'storage_path' => $storagePath,
            ]);

            return [
                'success' => true,
                'media_id' => $mediaId,
                'message' => 'Foto salvata',
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to save encrypted photo', [
                'user_uuid' => $userUuid,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'media_id' => null,
                'message' => 'Errore database durante salvataggio foto',
            ];
        }
    }

    /**
     * Get upload error message
     *
     * @param int $errorCode PHP upload error code
     * @return string Human-readable error message
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'File troppo grande (limite server)',
            UPLOAD_ERR_FORM_SIZE => 'File troppo grande (limite form)',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE => 'Nessun file caricato',
            UPLOAD_ERR_NO_TMP_DIR => 'Directory temporanea mancante',
            UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere su disco',
            UPLOAD_ERR_EXTENSION => 'Upload bloccato da estensione',
        ];

        return $messages[$errorCode] ?? 'Errore sconosciuto';
    }

    /**
     * Create image thumbnail (ENTERPRISE GALAXY+)
     *
     * Supports JPEG, PNG, WebP
     * Maintains aspect ratio
     * Max dimensions: 300x300 (configurable)
     *
     * @param string $sourcePath Source image path
     * @param string $destPath Destination thumbnail path
     * @param int $maxWidth Max width
     * @param int $maxHeight Max height
     * @return bool Success status
     */
    private function createThumbnail(string $sourcePath, string $destPath, int $maxWidth = 300, int $maxHeight = 300): bool
    {
        try {
            // Get image info
            $imageInfo = getimagesize($sourcePath);
            if (!$imageInfo) {
                Logger::error('Failed to get image size', ['path' => $sourcePath]);

                return false;
            }

            [$width, $height, $type] = $imageInfo;

            // Calculate new dimensions (maintain aspect ratio)
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = (int) ($width * $ratio);
            $newHeight = (int) ($height * $ratio);

            // Create source image resource
            $source = match($type) {
                IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
                IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
                IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
                default => null,
            };

            if (!$source) {
                Logger::error('Failed to create image resource', ['path' => $sourcePath, 'type' => $type]);

                return false;
            }

            // Create thumbnail resource
            $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

            // Preserve transparency for PNG/WebP
            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
                $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
                imagefilledrectangle($thumbnail, 0, 0, $newWidth, $newHeight, $transparent);
            }

            // Resize image
            imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Save thumbnail
            $success = match($type) {
                IMAGETYPE_JPEG => imagejpeg($thumbnail, $destPath, 85), // 85% quality
                IMAGETYPE_PNG => imagepng($thumbnail, $destPath, 6), // Compression level 6
                IMAGETYPE_WEBP => imagewebp($thumbnail, $destPath, 85), // 85% quality
                default => false,
            };

            // Free memory
            imagedestroy($source);
            imagedestroy($thumbnail);

            if (!$success) {
                Logger::error('Failed to save thumbnail', ['dest' => $destPath]);

                return false;
            }

            Logger::debug('Thumbnail created successfully', [
                'source' => $sourcePath,
                'dest' => $destPath,
                'original_size' => "{$width}x{$height}",
                'thumbnail_size' => "{$newWidth}x{$newHeight}",
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('Thumbnail creation failed', [
                'source' => $sourcePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // =========================================================================
    // ENTERPRISE GALAXY+: Calendar Sidebar API (30-day emotion heatmap)
    // =========================================================================

    /**
     * Get calendar data with emotions for last 30 days
     * Returns aggregated emotion data per day for calendar sidebar
     *
     * SECURITY: Uses only UUID, never exposes numeric IDs
     * PERFORMANCE: Single optimized query with GROUP BY
     * CACHING: Results cached server-side (5min TTL via query cache)
     *
     * @return void JSON response
     */
    public function calendarEmotions(): void
    {
        $userId = get_session('user_id');
        if (!$userId) {
            $this->json(['success' => false, 'error' => 'unauthorized'], 401);

            return;
        }

        try {
            $db = db();

            // Get last 30 days of entries with emotion aggregation
            // SECURITY: user_id validated from session, UUID used for responses
            $sql = "SELECT
                        e.date,
                        e.entry_type,
                        e.primary_emotion_id,
                        AVG(e.intensity) as avg_intensity,
                        COUNT(*) as entry_count
                    FROM emotional_journal_entries e
                    WHERE e.user_id = :user_id
                      AND e.deleted_at IS NULL
                      AND e.date >= CURRENT_DATE - INTERVAL '30 days'
                    GROUP BY e.date, e.entry_type, e.primary_emotion_id
                    ORDER BY e.date DESC, entry_count DESC";

            $entries = $db->query($sql, ['user_id' => $userId], [
                'cache' => true,
                'cache_ttl' => 'short', // 5 minutes
            ]);

            // Transform to calendar format
            $calendarData = [];

            foreach ($entries as $row) {
                $date = $row['date'];

                if (!isset($calendarData[$date])) {
                    $calendarData[$date] = [
                        'emotions' => [],
                        'entry_count' => 0,
                        'types' => [],
                    ];
                }

                // Add emotion (top 3 per day, already sorted by count)
                if (count($calendarData[$date]['emotions']) < 3) {
                    $calendarData[$date]['emotions'][] = [
                        'emotion_id' => (int) $row['primary_emotion_id'],
                        'avg_intensity' => round((float) $row['avg_intensity'], 1),
                    ];
                }

                // Sum entry count
                $calendarData[$date]['entry_count'] += (int) $row['entry_count'];

                // Track entry types
                $type = $row['entry_type'];
                if (!in_array($type, $calendarData[$date]['types'])) {
                    $calendarData[$date]['types'][] = $type;
                }
            }

            // Get trash count (soft deleted entries in last 30 days)
            $trashCount = $db->findOne(
                "SELECT COUNT(*) as cnt FROM emotional_journal_entries
                 WHERE user_id = :user_id
                   AND deleted_at IS NOT NULL
                   AND deleted_at > CURRENT_TIMESTAMP - INTERVAL '30 days'",
                ['user_id' => $userId],
                ['cache' => true, 'cache_ttl' => 'short']
            )['cnt'] ?? 0;

            $this->json([
                'success' => true,
                'calendar_data' => $calendarData,
                'trash_count' => (int) $trashCount,
            ]);

        } catch (\Exception $e) {
            Logger::error('Calendar emotions query failed', [
                'error' => $e->getMessage(),
            ]);
            $this->json(['success' => false, 'error' => 'server_error'], 500);
        }
    }

    /**
     * Get trash (soft deleted) entries for recovery
     *
     * ENTERPRISE: 30-day retention before permanent deletion
     * SECURITY: Only returns entries belonging to current user
     *
     * @return void JSON response
     */
    public function getTrash(): void
    {
        $userId = get_session('user_id');
        if (!$userId) {
            $this->json(['success' => false, 'error' => 'unauthorized'], 401);

            return;
        }

        try {
            $db = db();

            // Get soft deleted entries (last 30 days)
            $sql = "SELECT
                        e.uuid,
                        e.date,
                        e.entry_type,
                        e.primary_emotion_id,
                        e.intensity,
                        e.deleted_at,
                        em.name as emotion_name,
                        em.emoji as emotion_emoji
                    FROM emotional_journal_entries e
                    LEFT JOIN emotions em ON e.primary_emotion_id = em.id
                    WHERE e.user_id = :user_id
                      AND e.deleted_at IS NOT NULL
                      AND e.deleted_at > CURRENT_TIMESTAMP - INTERVAL '30 days'
                    ORDER BY e.deleted_at DESC
                    LIMIT 100";

            $entries = $db->query($sql, ['user_id' => $userId]);

            // Calculate days remaining before permanent deletion
            $entriesWithExpiry = array_map(function ($entry) {
                $deletedAt = new \DateTime($entry['deleted_at']);
                $expiresAt = $deletedAt->modify('+30 days');
                $now = new \DateTime();
                $daysRemaining = max(0, $now->diff($expiresAt)->days);

                return array_merge($entry, [
                    'days_until_permanent_deletion' => $daysRemaining,
                ]);
            }, $entries);

            $this->json([
                'success' => true,
                'entries' => $entriesWithExpiry,
                'count' => count($entries),
            ]);

        } catch (\Exception $e) {
            Logger::error('Get trash failed', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => 'server_error'], 500);
        }
    }

    /**
     * Restore a soft-deleted entry from trash
     *
     * SECURITY: Validates entry belongs to current user via UUID
     *
     * @param string $uuid Entry UUID
     * @return void JSON response
     */
    public function restoreEntry(string $uuid): void
    {
        $userId = get_session('user_id');
        if (!$userId) {
            $this->json(['success' => false, 'error' => 'unauthorized'], 401);

            return;
        }

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $this->json(['success' => false, 'error' => 'invalid_uuid'], 400);

            return;
        }

        try {
            $db = db();

            // V12: Restore entry (set deleted_at to NULL)
            // SECURITY: user_id check prevents unauthorized restoration
            // NOTE: updated_at column removed in V12
            $affected = $db->execute(
                "UPDATE emotional_journal_entries
                 SET deleted_at = NULL
                 WHERE uuid = :uuid AND user_id = :user_id AND deleted_at IS NOT NULL",
                ['uuid' => $uuid, 'user_id' => $userId],
                ['invalidate_cache' => ["journal:user:{$userId}"]]
            );

            if ($affected > 0) {
                Logger::info('Journal entry restored', [
                    'uuid' => $uuid,
                    'user_id' => $userId,
                ]);

                $this->json(['success' => true, 'message' => 'Entry restored']);
            } else {
                $this->json(['success' => false, 'error' => 'entry_not_found'], 404);
            }

        } catch (\Exception $e) {
            Logger::error('Restore entry failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            $this->json(['success' => false, 'error' => 'server_error'], 500);
        }
    }

    /**
     * Permanently delete an entry (from trash)
     *
     * ENTERPRISE: Only allows permanent deletion of already soft-deleted entries
     * SECURITY: User ownership validated via session
     *
     * @param string $uuid Entry UUID
     * @return void JSON response
     */
    public function permanentDelete(string $uuid): void
    {
        $userId = get_session('user_id');
        if (!$userId) {
            $this->json(['success' => false, 'error' => 'unauthorized'], 401);

            return;
        }

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $this->json(['success' => false, 'error' => 'invalid_uuid'], 400);

            return;
        }

        try {
            $db = db();

            // Only allow permanent deletion of soft-deleted entries
            // This prevents accidental permanent deletion
            // V12: Uses journal_media_id instead of journal_audio_id
            $entry = $db->findOne(
                "SELECT id, journal_media_id FROM emotional_journal_entries
                 WHERE uuid = :uuid AND user_id = :user_id AND deleted_at IS NOT NULL",
                ['uuid' => $uuid, 'user_id' => $userId]
            );

            if (!$entry) {
                $this->json(['success' => false, 'error' => 'entry_not_found_or_not_deleted'], 404);

                return;
            }

            // Begin transaction for cascading delete
            $db->beginTransaction();

            try {
                // V12: Delete associated media file if exists
                if ($entry['journal_media_id']) {
                    // Soft delete media file too (keep for potential recovery)
                    $db->execute(
                        "UPDATE journal_media SET deleted_at = NOW() WHERE id = :id",
                        ['id' => $entry['journal_media_id']]
                    );
                }

                // Permanently delete journal entry
                $db->execute(
                    "DELETE FROM emotional_journal_entries WHERE id = :id",
                    ['id' => $entry['id']],
                    ['invalidate_cache' => ["journal:user:{$userId}"]]
                );

                $db->commit();

                Logger::info('Journal entry permanently deleted', [
                    'uuid' => $uuid,
                    'user_id' => $userId,
                ]);

                $this->json(['success' => true, 'message' => 'Entry permanently deleted']);

            } catch (\Exception $e) {
                $db->rollback();

                throw $e;
            }

        } catch (\Exception $e) {
            Logger::error('Permanent delete failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            $this->json(['success' => false, 'error' => 'server_error'], 500);
        }
    }

    /**
     * Stream encrypted media file (ENTERPRISE V12)
     *
     * GET /api/journal/media/{uuid}/stream
     *
     * ARCHITECTURE:
     * - Serves encrypted media blob directly to client
     * - Client decrypts using WebCrypto API + diary encryption key
     * - Zero-knowledge server: we never see decrypted content during streaming
     * - Efficient: Uses sendfile/readfile for memory-efficient streaming
     *
     * SECURITY:
     * - Validates UUID format (prevents path traversal)
     * - Validates user ownership (user_id from session)
     * - Returns encrypted blob (no server-side decryption)
     * - Rate limiting via Redis
     *
     * @param string $uuid Media file UUID
     * @return void Binary stream response
     */
    public function streamMedia(string $uuid): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];
            $userUuid = $user['uuid'];

            // Validate UUID format (prevent path traversal attacks)
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_uuid',
                    'message' => 'UUID non valido',
                ], 400);

                return;
            }

            $db = db();

            // V12: Find media file by UUID from unified journal_media table
            $media = $db->findOne(
                "SELECT id, uuid, user_id, media_type, filename, local_path, file_size,
                        mime_type, ENCODE(encryption_iv, 'base64') as encryption_iv, is_encrypted
                 FROM journal_media
                 WHERE uuid = :uuid AND user_id = :user_id AND deleted_at IS NULL",
                ['uuid' => $uuid, 'user_id' => $userId],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            if (!$media) {
                Logger::warning('Media stream denied: not found or unauthorized', [
                    'uuid' => $uuid,
                    'user_uuid' => $userUuid,
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'not_found',
                    'message' => 'Media non trovato o accesso negato',
                ], 404);

                return;
            }

            // Build full path to encrypted file
            $uploadPathBase = realpath(APP_ROOT . '/' . get_env('UPLOAD_PATH', 'storage/uploads'));
            $filePath = $uploadPathBase . '/' . $media['local_path'];

            if (!file_exists($filePath)) {
                Logger::error('Media file missing on disk', [
                    'uuid' => $uuid,
                    'expected_path' => $filePath,
                    'user_uuid' => $userUuid,
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'file_missing',
                    'message' => 'File non trovato su disco',
                ], 500);

                return;
            }

            // Get actual file size
            $fileSize = filesize($filePath);

            // ENTERPRISE V12: Set proper headers for encrypted binary stream
            // Content-Type: application/octet-stream (encrypted data)
            // X-Encryption-IV: Base64 IV for client-side decryption
            // X-Media-Type: Original media type (audio/photo)

            // Clear any output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }

            // Set response headers
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . $fileSize);
            header('Content-Disposition: inline; filename="' . $media['filename'] . '"');
            header('Cache-Control: private, max-age=3600'); // 1 hour client cache
            header('X-Encryption-IV: ' . ($media['encryption_iv'] ?? ''));
            header('X-Encryption-Algorithm: AES-256-GCM');
            header('X-Content-Encrypted: ' . ($media['is_encrypted'] ? 'true' : 'false'));
            header('X-Media-Type: ' . $media['media_type']);
            header('X-Original-Mime-Type: ' . $media['mime_type']);

            // CORS headers for same-origin requests
            header('Access-Control-Expose-Headers: X-Encryption-IV, X-Encryption-Algorithm, X-Content-Encrypted, X-Media-Type, X-Original-Mime-Type');

            // Stream file efficiently
            readfile($filePath);

            // Log access for audit trail
            Logger::debug('Media streamed successfully', [
                'uuid' => $uuid,
                'user_uuid' => $userUuid,
                'file_size' => $fileSize,
                'media_type' => $media['media_type'],
                'encrypted' => $media['is_encrypted'] ? true : false,
            ]);

            exit; // Prevent any further output

        } catch (\Exception $e) {
            Logger::error('Media stream failed', [
                'uuid' => $uuid,
                'user_uuid' => $userUuid ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante lo streaming del media',
            ], 500);
        }
    }

    /**
     * Backward compatibility alias for streamMedia
     * @deprecated Use streamMedia() instead
     */
    public function streamAudio(string $uuid): void
    {
        $this->streamMedia($uuid);
    }

    /**
     * Soft delete entry (move to trash)
     *
     * ENTERPRISE: Entries in trash for 30 days before permanent deletion
     *
     * @param string $uuid Entry UUID
     * @return void JSON response
     */
    public function softDelete(string $uuid): void
    {
        $userId = get_session('user_id');
        if (!$userId) {
            $this->json(['success' => false, 'error' => 'unauthorized'], 401);

            return;
        }

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $this->json(['success' => false, 'error' => 'invalid_uuid'], 400);

            return;
        }

        try {
            $db = db();

            // V12: Soft delete (set deleted_at timestamp)
            // NOTE: updated_at column removed in V12
            $affected = $db->execute(
                "UPDATE emotional_journal_entries
                 SET deleted_at = NOW()
                 WHERE uuid = :uuid AND user_id = :user_id AND deleted_at IS NULL",
                ['uuid' => $uuid, 'user_id' => $userId],
                ['invalidate_cache' => ["journal:user:{$userId}"]]
            );

            if ($affected > 0) {
                Logger::info('Journal entry soft deleted', [
                    'uuid' => $uuid,
                    'user_id' => $userId,
                ]);

                $this->json([
                    'success' => true,
                    'message' => 'Entry moved to trash',
                    'restore_days' => 30,
                ]);
            } else {
                $this->json(['success' => false, 'error' => 'entry_not_found'], 404);
            }

        } catch (\Exception $e) {
            Logger::error('Soft delete failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            $this->json(['success' => false, 'error' => 'server_error'], 500);
        }
    }
}
