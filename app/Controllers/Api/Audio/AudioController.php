<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Api\Audio;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Audio\Social\AudioPostService;
use Need2Talk\Services\CDN\SignedUrlService;
use Need2Talk\Services\EnterpriseGeoIPService;
use Need2Talk\Services\Logger;

/**
 * Audio Controller - Enterprise Galaxy
 *
 * HTTP endpoints for audio post management
 * Upload, feed, playback, deletion
 *
 * @package Need2Talk\Controllers\Api\Audio
 */
class AudioController extends BaseController
{
    private AudioPostService $audioService;

    public function __construct()
    {
        parent::__construct();
        $this->audioService = new AudioPostService();
    }

    /**
     * Upload new audio post
     *
     * POST /api/audio/upload
     *
     * Body:
     * - audio_file: WebM audio file (max 500KB, 30s)
     * - title: Optional title (max 100 chars)
     * - emotion_id: Emotion ID (1-10)
     * - visibility: public/friends/private
     *
     * @return void JSON response
     */
    public function upload(): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            // ENTERPRISE UUID: Get user UUID for UUID-based system
            $userUuid = $this->getUserUuid();
            $userId = $this->getUserId(); // Still needed for logging compatibility

            // Check file upload
            if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
                $this->json([
                    'success' => false,
                    'error' => 'no_file',
                    'message' => 'Nessun file audio caricato',
                ], 400);

                return;
            }

            $file = $_FILES['audio_file'];

            // Validate file size (max 500KB)
            if ($file['size'] > 500 * 1024) {
                $this->json([
                    'success' => false,
                    'error' => 'file_too_large',
                    'message' => 'File troppo grande (max 500KB)',
                ], 400);

                return;
            }

            // ENTERPRISE: Validate WebM audio format
            // PHP finfo_file() does NOT reliably detect WebM (often returns application/octet-stream)
            // Solution: Check EBML/WebM binary signature (first 4 bytes: 0x1A 0x45 0xDF 0xA3)

            // Read first 4 bytes for signature check
            $handle = fopen($file['tmp_name'], 'rb');
            $signature = fread($handle, 4);
            fclose($handle);

            // WebM/EBML signature: 0x1A 0x45 0xDF 0xA3
            $isWebM = (bin2hex($signature) === '1a45dfa3');

            // Fallback: check MIME type from finfo (unreliable for WebM)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            // SECURITY FIX: Strict MIME validation (Defense in Depth)
            // REQUIRE BOTH WebM signature AND valid MIME type (no application/octet-stream)
            // NOTE: MediaRecorder API generates WebM containers that finfo_file() may detect as
            // 'video/webm' even for audio-only recordings (no video tracks). This is expected.
            $allowedMimes = ['audio/webm', 'audio/ogg', 'audio/opus', 'video/webm'];
            $isMimeValid = in_array($detectedMime, $allowedMimes, true);

            // CRITICAL: Require BOTH checks to pass (prevent malware upload)
            if (!$isWebM || !$isMimeValid) {
                Logger::security('warning', 'Audio upload rejected: Failed validation', [
                    'detected_mime' => $detectedMime,
                    'is_webm_signature' => $isWebM,
                    'mime_valid' => $isMimeValid,
                    'user_id' => $userId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'invalid_format',
                    'message' => 'Formato audio non valido. Solo file WebM/Opus validi.',
                ], 400);

                return;
            }

            // SECURITY: Verify file is actually playable audio (prevent renamed malicious files)
            $duration = $this->getAudioDuration($file['tmp_name']);
            if ($duration === false || $duration <= 0 || $duration > 30) {
                Logger::security('warning', 'Audio file not playable or invalid duration', [
                    'duration' => $duration,
                    'detected_mime' => $detectedMime,
                    'user_id' => $userId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'invalid_audio_file',
                    'message' => 'File audio non riproducibile o durata non valida (max 30s)',
                ], 400);

                return;
            }

            // Calculate file hash
            $fileHash = hash_file('sha256', $file['tmp_name']);

            // Get duration using getID3 (if available)
            $duration = $this->getAudioDuration($file['tmp_name']);

            if ($duration === false || $duration > 30) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_duration',
                    'message' => 'Durata non valida (max 30 secondi)',
                ], 400);

                return;
            }

            // SECURITY FIX: Validate and canonicalize upload path (Path Traversal Prevention)
            $uploadDirConfig = get_env('UPLOAD_PATH', 'storage/uploads/audio');
            $uploadDirBase = realpath(APP_ROOT . '/' . $uploadDirConfig);

            // Verify upload directory exists and is writable
            if ($uploadDirBase === false || !is_dir($uploadDirBase) || !is_writable($uploadDirBase)) {
                Logger::security('critical', 'Invalid upload directory configuration', [
                    'configured_path' => $uploadDirConfig,
                    'resolved_path' => $uploadDirBase,
                    'user_id' => $userId,
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'server_error',
                    'message' => 'Errore di configurazione del server',
                ], 500);

                return;
            }

            // Generate unique filename
            $filename = uniqid('audio_', true) . '.webm';
            $datePath = date('Y/m/d'); // e.g., 2025/11/04
            $filePath = $uploadDirBase . '/' . $datePath;
            $fullPath = $filePath . '/' . $filename;

            // CRITICAL FIX: Create directory BEFORE realpath() validation
            // realpath() returns false for non-existent paths, so we must mkdir() first
            if (!is_dir($filePath)) {
                if (!mkdir($filePath, 0755, true)) {
                    Logger::error('Failed to create upload directory', [
                        'path' => $filePath,
                        'user_id' => $userId,
                    ]);

                    $this->json([
                        'success' => false,
                        'error' => 'upload_failed',
                        'message' => 'Errore durante il caricamento',
                    ], 500);

                    return;
                }
            }

            // SECURITY: Validate final path is within upload directory (after mkdir)
            $canonicalPath = realpath(dirname($fullPath));
            if ($canonicalPath === false || strpos($canonicalPath, $uploadDirBase) !== 0) {
                Logger::security('critical', 'Path traversal attempt detected', [
                    'upload_dir' => $uploadDirBase,
                    'attempted_path' => $fullPath,
                    'canonical_path' => $canonicalPath,
                    'user_id' => $userId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                $this->json([
                    'success' => false,
                    'error' => 'invalid_path',
                    'message' => 'Percorso file non valido',
                ], 400);

                return;
            }

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                $this->json([
                    'success' => false,
                    'error' => 'upload_failed',
                    'message' => 'Errore durante il caricamento',
                ], 500);

                return;
            }

            // Get form data
            $title = $_POST['title'] ?? null;
            $description = $_POST['description'] ?? null;
            $emotionId = isset($_POST['emotion_id']) ? (int) $_POST['emotion_id'] : null;
            $visibility = $_POST['visibility'] ?? 'public';
            $hashtagsJson = $_POST['hashtags'] ?? null;

            // Validate visibility (match database ENUM: 'private', 'friends', 'friends_of_friends', 'public')
            if (!in_array($visibility, ['private', 'friends', 'friends_of_friends', 'public'], true)) {
                $visibility = 'public'; // Default fallback
            }

            // Validate and sanitize title (XSS prevention)
            if ($title !== null) {
                $title = strip_tags($title); // Remove HTML tags
                if (mb_strlen($title) > 500) { // audio_files.title is VARCHAR(500)
                    $title = mb_substr($title, 0, 500);
                }
            }

            // Validate and sanitize description (XSS prevention)
            if ($description !== null) {
                $description = strip_tags($description); // Remove HTML tags
                if (mb_strlen($description) > 5000) { // TEXT field, limit 5000 chars
                    $description = mb_substr($description, 0, 5000);
                }
            }

            // ENTERPRISE MODERATION: Apply content censorship (replace prohibited words with ***)
            try {
                $censorshipService = new \Need2Talk\Services\Moderation\ContentCensorshipService();

                // Censor title
                if ($title !== null) {
                    $titleResult = $censorshipService->censorContent($title, 'post_title');
                    $title = $titleResult['censored'];
                    if ($titleResult['was_censored']) {
                        Logger::info('Content censored in audio title', [
                            'user_id' => $userId,
                            'matched' => $titleResult['matched'],
                        ]);
                    }
                }

                // Censor description
                if ($description !== null) {
                    $descResult = $censorshipService->censorContent($description, 'post_description');
                    $description = $descResult['censored'];
                    if ($descResult['was_censored']) {
                        Logger::info('Content censored in audio description', [
                            'user_id' => $userId,
                            'matched' => $descResult['matched'],
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Continue without censorship if service fails
                Logger::warning('Content censorship service failed', ['error' => $e->getMessage()]);
            }

            // Parse hashtags
            $hashtags = [];
            if ($hashtagsJson !== null) {
                $hashtags = json_decode($hashtagsJson, true);
                if (!is_array($hashtags)) {
                    $hashtags = [];
                }
                // Limit to 10 hashtags, max 50 chars each
                $hashtags = array_slice($hashtags, 0, 10);
                $hashtags = array_map(function ($tag) {
                    return mb_substr(strip_tags($tag), 0, 50);
                }, $hashtags);
            }

            // ENTERPRISE V4 (2025-11-30): Parse mentioned_users for @mention tagging
            $mentionedUsers = [];
            $mentionedUsersJson = $_POST['mentioned_users'] ?? null;
            if ($mentionedUsersJson !== null) {
                $mentionedUsers = json_decode($mentionedUsersJson, true);
                if (!is_array($mentionedUsers)) {
                    $mentionedUsers = [];
                }
                // Limit to 10 mentions, sanitize data
                $mentionedUsers = array_slice($mentionedUsers, 0, 10);
                $mentionedUsers = array_map(function ($user) {
                    return [
                        'uuid' => $user['uuid'] ?? '',
                        'nickname' => mb_substr(strip_tags($user['nickname'] ?? ''), 0, 50),
                        // ENTERPRISE V10.141: Normalize avatar URL
                        'avatar_url' => get_avatar_url($user['avatar_url'] ?? null),
                    ];
                }, $mentionedUsers);
                // Filter out invalid entries
                $mentionedUsers = array_filter($mentionedUsers, fn($u) => !empty($u['uuid']) && !empty($u['nickname']));
                $mentionedUsers = array_values($mentionedUsers); // Re-index array
            }

            // Handle photo upload
            $photoPath = null;
            $photoThumbnail = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $photoFile = $_FILES['photo'];

                // Validate photo size (max 2MB after resize)
                if ($photoFile['size'] > 2 * 1024 * 1024) {
                    $this->json([
                        'success' => false,
                        'error' => 'photo_too_large',
                        'message' => 'Foto troppo grande (max 2MB)',
                    ], 400);

                    return;
                }

                // Validate photo MIME type
                $allowedPhotoMimes = ['image/jpeg', 'image/png', 'image/webp'];
                $photoMime = mime_content_type($photoFile['tmp_name']);
                if (!in_array($photoMime, $allowedPhotoMimes, true)) {
                    $this->json([
                        'success' => false,
                        'error' => 'invalid_photo_format',
                        'message' => 'Formato foto non valido (solo JPEG, PNG, WebP)',
                    ], 400);

                    return;
                }

                // ENTERPRISE GALAXY: Photo optimization with WebP + thumbnails
                // Before: Saved raw JPEG with no optimization
                // After: WebP conversion + resize + multi-size thumbnails (-70% storage)
                try {
                    // ENTERPRISE FIX: Save to public/storage/uploads (web-accessible)
                    $uploadPathBase = realpath(APP_ROOT . '/public/storage/uploads');
                    $photoDatePath = date('Y/m/d');
                    $photoUploadDir = $uploadPathBase . '/photos/' . $photoDatePath;
                    $photoBaseName = uniqid('cover_', true);

                    // Optimize photo with PhotoOptimizationService
                    $photoService = new \Need2Talk\Services\Media\PhotoOptimizationService();
                    $optimizedPhoto = $photoService->optimizePhoto(
                        $photoFile['tmp_name'],
                        $photoUploadDir,
                        $photoBaseName
                    );

                    // Set URLs (relative paths)
                    $photoPath = $optimizedPhoto['full']; // Full-size WebP
                    $photoThumbnail = $optimizedPhoto['thumbnails']['medium'] ?? $photoPath; // 300px thumbnail

                    Logger::info('Audio post photo optimized', [
                        'user_id' => $userId,
                        'savings_percent' => $optimizedPhoto['metadata']['savings_percent'],
                    ]);

                } catch (\Exception $e) {
                    Logger::error('Photo optimization failed for audio post', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue without photo
                }
            }

            // ENTERPRISE V5.3: Auto-populate location from GeoIP (GDPR: city only, no coords)
            $location = null;
            try {
                $clientIp = get_server('REMOTE_ADDR');
                $geoData = EnterpriseGeoIPService::getGeoLocation($clientIp);
                if (!empty($geoData['city']) && $geoData['city'] !== 'Unknown') {
                    // Format: "City, Country" (e.g., "Milan, Italy")
                    $location = $geoData['city'];
                    if (!empty($geoData['country']) && $geoData['country'] !== 'Unknown') {
                        $location .= ', ' . $geoData['country'];
                    }
                }
            } catch (\Exception $e) {
                // Non-critical: continue without location
                Logger::debug('GeoIP lookup failed for audio upload', ['error' => $e->getMessage()]);
            }

            // Create audio post
            $audioData = [
                'file_path' => $fullPath,
                'file_hash' => $fileHash,
                'file_size' => $file['size'],
                'duration' => $duration,
                'title' => $title,
                'description' => $description,
                'primary_emotion_id' => $emotionId,
                'visibility' => $visibility,
                'photo_url' => $photoPath,
                'photo_thumbnail' => $photoThumbnail,
                'hashtags' => $hashtags,
                'mentioned_users' => $mentionedUsers,
                'location' => $location, // ENTERPRISE V5.3: Auto GeoIP location
            ];

            // ENTERPRISE UUID: Call service with UUID
            $result = $this->audioService->createPost($userUuid, $audioData);

            if ($result['success']) {
                // ENTERPRISE GALAXY: Set cache bypass for 30 seconds
                // User will see their new post immediately when refreshing feed/profile
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['_user_cache_bypass_until'] = time() + 30;
                }

                // ENTERPRISE GALAXY: Return full post data for instant UI update
                // Solution: Frontend adds post to DOM immediately (NO cache invalidation needed)
                // This is how Twitter/Instagram/Facebook work (real-time UI prepend)
                $db = db();
                $newPost = $db->findOne('
                    SELECT ap.id, ap.uuid, ap.post_type, ap.content,
                           ap.audio_file_id, ap.photo_urls, ap.visibility,
                           ap.created_at, u.nickname, u.avatar_url,
                           af.duration AS audio_duration,
                           af.title AS audio_title,
                           af.description AS audio_description,
                           af.cdn_url AS audio_cdn_url,
                           af.photo_url AS audio_photo_url,
                           af.photo_thumbnail AS audio_photo_thumbnail,
                           e.id AS emotion_id,
                           e.name_it AS emotion_name_it,
                           e.color_hex AS emotion_color_hex,
                           e.icon_emoji AS emotion_icon_emoji
                    FROM audio_posts ap
                    INNER JOIN users u ON ap.user_id = u.id
                    LEFT JOIN audio_files af ON ap.audio_file_id = af.id
                    LEFT JOIN emotions e ON af.primary_emotion_id = e.id
                    WHERE ap.id = ?
                ', [$result['post_id']], ['cache' => false]);

                // ENTERPRISE FIX: Invalidate ALL feed cache (30min TTL = acceptable)
                // Cache key is SHA256(sql+params), can't use pattern matching
                // Simpler: just wait 30min OR user will see post via prependPost() instantly
                // Profilo shows it immediately because different query/cache key

                // ENTERPRISE FIX (2025-12-20): Normalize avatar URL for proper rendering
                // Raw database value may be relative path, needs normalization
                if ($newPost) {
                    $newPost['avatar_url'] = get_avatar_url($newPost['avatar_url'] ?? null);
                }

                $this->json([
                    'success' => true,
                    'post_id' => $result['post_id'],
                    'post' => $newPost, // Full post data for instant UI rendering
                    'message' => 'Audio caricato con successo',
                ], 201);
            } else {
                // Delete uploaded file on failure
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }

                $this->json($result, 400);
            }

        } catch (\Exception $e) {
            Logger::api('error', 'Audio upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    /**
     * Get social feed
     *
     * GET /api/audio/feed
     *
     * Query params:
     * - page: Page number (default 1)
     * - per_page: Posts per page (default 20, max 50)
     *
     * @return void JSON response
     */
    public function feed(): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            // ENTERPRISE UUID: Get user UUID for UUID-based system
            $userUuid = $this->getUserUuid();
            $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
            // ENTERPRISE: Default 10 posts per page for UX (load more pattern)
            $perPage = isset($_GET['per_page']) ? min(50, max(1, (int) $_GET['per_page'])) : 10;

            // ENTERPRISE UUID: Call service with UUID
            $result = $this->audioService->getFeed($userUuid, $page, $perPage);

            // =========================================================================
            // ENTERPRISE SECURITY: Sanitize feed response - remove internal IDs
            // =========================================================================
            if (isset($result['posts']) && is_array($result['posts'])) {
                $result['posts'] = array_map(function ($post) {
                    // Remove author.id (internal DB ID) - keep only UUID
                    if (isset($post['author']['id'])) {
                        unset($post['author']['id']);
                    }
                    return $post;
                }, $result['posts']);
            }

            $this->json($result);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to get feed', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    /**
     * Get audio post details
     *
     * GET /api/audio/{id}
     *
     * @param int $id Audio ID
     * @return void JSON response
     */
    public function show(int $id): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            // ENTERPRISE UUID: Get user UUID for UUID-based system
            $userUuid = $this->getUserUuid();

            // ENTERPRISE UUID: Call service with UUID
            $post = $this->audioService->getPost($id, $userUuid);

            if (!$post) {
                $this->json([
                    'success' => false,
                    'error' => 'not_found',
                    'message' => 'Audio non trovato',
                ], 404);

                return;
            }

            // Load comments for lightbox display
            // ENTERPRISE V6.5 (2025-11-30): Comments are linked via audio_post_id, not audio_file_id
            // ENTERPRISE V8.0 (2025-12-01): Added mentioned_users for @mention linking in lightbox
            $comments = db()->query("
                SELECT
                    c.id,
                    c.uuid,
                    c.comment_text,
                    c.parent_comment_id,
                    c.like_count,
                    c.reply_count,
                    c.is_edited,
                    c.mentioned_users,
                    c.created_at,
                    c.updated_at,
                    u.id AS user_id,
                    u.uuid AS user_uuid,
                    u.nickname AS user_nickname,
                    u.avatar_url AS user_avatar
                FROM audio_comments c
                INNER JOIN users u ON c.user_id = u.id
                WHERE c.audio_post_id = :post_id
                  AND c.status = 'active'
                  AND c.parent_comment_id IS NULL
                ORDER BY c.created_at DESC
                LIMIT 50
            ", [
                'post_id' => $id,
            ], [
                'cache' => true,
                'cache_ttl' => 'short', // 5min cache
            ]);

            // ENTERPRISE V8.0: Format comments with mentioned_users properly decoded
            $formattedComments = array_map(function ($comment) {
                $comment['mentioned_users'] = $comment['mentioned_users']
                    ? json_decode($comment['mentioned_users'], true)
                    : [];
                return $comment;
            }, $comments ?: []);

            // ENTERPRISE SECURITY: Use streaming proxy endpoint (files have private ACL)
            // TODO: Implement proper AWS SDK presigned URLs for better CDN performance
            // For now, use authenticated streaming endpoint which verifies user auth
            $signedUrl = "/api/audio/{$id}/stream";
            $expiresAt = null; // Stream endpoint validates auth on each request

            // =========================================================================
            // ENTERPRISE SECURITY: Sanitize response - remove sensitive internal data
            // These fields are used internally (streaming) but MUST NOT be exposed
            // =========================================================================
            $sanitizedPost = $post;
            unset($sanitizedPost['user_id']);      // Internal DB ID - use user_uuid instead
            unset($sanitizedPost['file_path']);    // Server filesystem path - CRITICAL LEAK
            unset($sanitizedPost['cdn_url']);      // S3 bucket name exposure

            // Sanitize comments - remove internal user_id
            $sanitizedComments = array_map(function ($comment) {
                unset($comment['user_id']);
                return $comment;
            }, $formattedComments);

            $this->json([
                'success' => true,
                'post' => $sanitizedPost,
                'signed_url' => $signedUrl,
                'expires_at' => $expiresAt,
                'comments' => $sanitizedComments,
            ]);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to get audio post', [
                'audio_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    /**
     * Stream audio file
     *
     * GET /api/audio/{id}/stream
     *
     * ENTERPRISE ARCHITECTURE (2025-12-06):
     * - AWS S3 files: 302 redirect to presigned URL (direct S3 download, no PHP proxy)
     * - Local files: Direct streaming with range request support
     * - Old DO Spaces files: PHP proxy (presigned URLs broken)
     *
     * SCALABILITY:
     * - Presigned URL redirect = 0 bandwidth through PHP-FPM
     * - Scales to millions of concurrent streams
     * - ~15ms latency (eu-south-1 Milano)
     *
     * ENTERPRISE GALAXY (2025-12-06): Supports both numeric ID and UUID
     * - Numeric ID: Direct lookup (internal use, legacy)
     * - UUID: Public-facing identifier (preferred for external APIs)
     *
     * @param string $identifier Audio ID (numeric) or UUID (36-char string)
     * @return void Audio stream or redirect
     */
    public function stream(string $identifier): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }

            $userUuid = $this->getUserUuid();
            $userId = $this->getUserId();

            // ENTERPRISE GALAXY: Resolve identifier to internal ID
            // Supports both numeric ID (legacy) and UUID (public-facing)
            $id = $this->resolveAudioIdentifier($identifier);

            if ($id === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Invalid audio identifier']);
                return;
            }

            $post = $this->audioService->getPost($id, $userUuid);

            if (!$post) {
                http_response_code(404);
                echo json_encode(['error' => 'Audio not found']);
                return;
            }

            // SECURITY: Check visibility authorization (IDOR prevention)
            if (!$this->canAccessPost($post, $userId)) {
                Logger::security('warning', 'Unauthorized audio stream attempt', [
                    'post_id' => $id,
                    'user_id' => $userId,
                    'post_owner' => $post['user_id'],
                    'visibility' => $post['visibility'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                return;
            }

            // =========================================================================
            // STRATEGY 1: AWS S3 - PHP Proxy Stream (ENTERPRISE - 2025-12-13)
            // =========================================================================
            // CHANGED: Always use PHP proxy instead of 302 redirect
            // REASON: Presigned URL redirects fail in some browsers (Chrome/Safari PWA)
            // due to cross-origin issues, even without COEP headers.
            // PHP proxy is more compatible and works everywhere.
            // =========================================================================

            if (!empty($post['cdn_url']) && str_starts_with($post['cdn_url'], 's3://')) {
                $s3Service = new \Need2Talk\Services\Storage\S3StorageService();
                $s3Key = $s3Service->extractS3Key($post['cdn_url']);

                if ($s3Key) {
                    $presignedUrl = $s3Service->getSignedUrl($s3Key, 3600); // 1 hour

                    if ($presignedUrl) {
                        // ALWAYS use PHP proxy for maximum compatibility
                        $this->proxyS3Stream($presignedUrl, $post);
                        return;
                    }
                }

                Logger::warning('AWS S3 presigned URL generation failed, falling back', [
                    'post_id' => $id,
                    'cdn_url' => $post['cdn_url'],
                ]);
            }

            // =========================================================================
            // STRATEGY 2: Old DO Spaces files - PHP proxy (presigned URLs broken)
            // =========================================================================
            if (!empty($post['cdn_url']) && str_contains($post['cdn_url'], '.digitaloceanspaces.com')) {
                try {
                    $signedUrlService = new \Need2Talk\Services\CDN\SignedUrlService();
                    $objectKey = $signedUrlService->extractObjectKey($post['cdn_url']);

                    if ($objectKey) {
                        $s3Client = new \Aws\S3\S3Client([
                            'version' => 'latest',
                            'region' => get_env('SPACES_REGION', 'fra1'),
                            'endpoint' => get_env('DO_SPACES_ENDPOINT', 'https://fra1.digitaloceanspaces.com'),
                            'credentials' => [
                                'key' => get_env('SPACES_KEY'),
                                'secret' => get_env('SPACES_SECRET'),
                            ],
                            'use_path_style_endpoint' => true,
                        ]);

                        $result = $s3Client->getObject([
                            'Bucket' => get_env('SPACES_BUCKET', 'need2talk'),
                            'Key' => $objectKey,
                        ]);

                        header('Content-Type: ' . ($result['ContentType'] ?? 'audio/webm'));
                        header('Content-Length: ' . ($result['ContentLength'] ?? ''));
                        header('Accept-Ranges: bytes');
                        header('Cache-Control: public, max-age=3600');

                        echo $result['Body'];
                        exit;
                    }
                } catch (\Exception $e) {
                    Logger::error('DO Spaces proxy streaming failed', [
                        'post_id' => $id,
                        'cdn_url' => $post['cdn_url'],
                        'error' => $e->getMessage(),
                    ]);
                    // Fallback to local file
                }
            }

            // =========================================================================
            // STRATEGY 3: Local file streaming (processing or no CDN upload yet)
            // =========================================================================
            if (empty($post['file_path'])) {
                Logger::error('Audio post missing file_path and cdn_url', [
                    'post_id' => $id,
                ]);
                http_response_code(500);
                echo json_encode(['error' => 'Audio file not available']);
                return;
            }

            $filePath = $post['file_path'];

            // Normalize container paths
            if (str_starts_with($filePath, '/var/www/html/')) {
                $filePath = str_replace('/var/www/html/', APP_ROOT . '/', $filePath);
            } elseif (!str_starts_with($filePath, '/') && !str_starts_with($filePath, APP_ROOT)) {
                $filePath = APP_ROOT . '/' . $filePath;
            }

            if (!file_exists($filePath)) {
                Logger::error('Audio file not found', [
                    'post_id' => $id,
                    'path' => $filePath,
                ]);
                http_response_code(404);
                echo json_encode(['error' => 'File not found']);
                return;
            }

            // ENTERPRISE 2025-12-13: Use mime_type from database (supports MP3, WebM, etc.)
            $mimeType = $post['audio_mime_type'] ?? $post['mime_type'] ?? 'audio/mpeg';
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . filesize($filePath));
            header('Accept-Ranges: bytes');
            header('Cache-Control: public, max-age=3600');

            if (isset($_SERVER['HTTP_RANGE'])) {
                $this->streamRange($filePath);
            } else {
                readfile($filePath);
            }

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to stream audio', [
                'audio_id' => $id,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'Server error']);
        }
    }

    /**
     * Proxy S3 stream through PHP
     *
     * ENTERPRISE GALAXY: Used when ?proxy=1 is set
     * Needed for moderation panel where redirect breaks HTML5 audio progress bar
     *
     * @param string $presignedUrl The S3 presigned URL
     * @param array $post Audio post data
     */
    private function proxyS3Stream(string $presignedUrl, array $post): void
    {
        // ENTERPRISE 2025-12-13: Support Range Requests for Safari/Chrome compatibility
        // Safari requires proper range request handling for audio seek to work

        // First, get file size with HEAD request
        $ch = curl_init($presignedUrl);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $fileSize = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);

        if ($fileSize <= 0) {
            // Fallback: use database file_size
            $fileSize = (int) ($post['file_size'] ?? 0);
        }

        // Parse Range header if present
        $start = 0;
        $end = $fileSize - 1;
        $isRangeRequest = false;

        if (isset($_SERVER['HTTP_RANGE']) && $fileSize > 0) {
            $isRangeRequest = true;

            // Parse "bytes=START-END" format
            if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                $start = $matches[1] !== '' ? (int) $matches[1] : 0;
                $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;

                // Validate range
                if ($start > $end || $start >= $fileSize) {
                    http_response_code(416); // Range Not Satisfiable
                    header("Content-Range: bytes */$fileSize");
                    return;
                }

                // Cap end to file size
                $end = min($end, $fileSize - 1);
            }
        }

        $length = $end - $start + 1;

        // Fetch content from S3 with Range if needed
        $ch = curl_init($presignedUrl);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Accept: audio/*',
            ],
        ];

        // Add Range header if this is a range request
        if ($isRangeRequest) {
            $curlOptions[CURLOPT_HTTPHEADER][] = "Range: bytes=$start-$end";
        }

        curl_setopt_array($ch, $curlOptions);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (($httpCode !== 200 && $httpCode !== 206) || $content === false || empty($content)) {
            // ENTERPRISE 2025-12-13: Prevent nginx from caching error responses
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            http_response_code(502);
            Logger::error('Audio S3 proxy failed', [
                'http_code' => $httpCode,
                'content_empty' => empty($content),
                'curl_error' => $curlError,
            ]);
            echo json_encode(['error' => 'Failed to fetch audio from storage']);
            return;
        }

        // Set appropriate response headers
        // ENTERPRISE 2025-12-13: Use mime_type from database (supports MP3, WebM, etc.)
        $mimeType = $post['audio_mime_type'] ?? $post['mime_type'] ?? 'audio/mpeg';
        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');

        // ENTERPRISE 2025-12-13: Always use actual content length for accuracy
        $actualContentLength = strlen($content);

        if ($isRangeRequest) {
            // 206 Partial Content for range requests
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$fileSize");
            header('Content-Length: ' . $actualContentLength);
        } else {
            // 200 OK for full content - use actual downloaded size
            header('Content-Length: ' . $actualContentLength);
        }

        // Output content
        echo $content;
        exit;
    }

    /**
     * Delete audio post
     *
     * DELETE /api/audio/{id}
     *
     * @param int $id Audio ID
     * @return void JSON response
     */
    public function delete(int $id): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            // ENTERPRISE UUID: Get user UUID for UUID-based system
            $userUuid = $this->getUserUuid();

            // ENTERPRISE UUID: Call service with UUID
            $result = $this->audioService->deletePost($id, $userUuid);

            $statusCode = $result['success'] ? 200 : 400;
            $this->json($result, $statusCode);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to delete audio', [
                'audio_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    /**
     * Check rate limit status
     *
     * GET /api/audio/rate-limit-check
     *
     * @return void JSON response
     */
    public function rateLimitCheck(): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            // ENTERPRISE UUID: Get user UUID for UUID-based system
            $userUuid = $this->getUserUuid();

            // ENTERPRISE UUID: Call service with UUID
            $status = $this->audioService->getRateLimitStatus($userUuid);

            $this->json([
                'success' => true,
                'rate_limit' => $status,
            ]);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to check rate limit', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Check if user can access post based on visibility
     *
     * SECURITY: IDOR prevention - verify ownership and friendship
     *
     * @param array $post Post data with visibility and user_id
     * @param int $userId Current user ID
     * @return bool True if user can access, false otherwise
     */
    private function canAccessPost(array $post, int $userId): bool
    {
        // ENTERPRISE GALAXY: Admin override - admins can access ALL content for moderation
        // This is FIRST check for security (admins bypass all restrictions)
        if (function_exists('is_admin_user') && is_admin_user()) {
            $adminData = get_admin_user();

            // SECURITY AUDIT: Log admin content access
            admin_audit_log('ADMIN_ACCESS_AUDIO_POST', [
                'post_id' => $post['id'] ?? 'unknown',
                'post_visibility' => $post['visibility'] ?? 'unknown',
                'post_owner_id' => $post['user_id'] ?? 'unknown',
                'admin_id' => $adminData['id'] ?? 'unknown',
                'admin_email' => $adminData['email'] ?? 'unknown',
                'admin_role' => $adminData['role'] ?? 'unknown',
                'reason' => 'content_moderation',
            ]);

            Logger::security('info', 'Admin accessed audio post for moderation', [
                'post_id' => $post['id'] ?? 'unknown',
                'post_visibility' => $post['visibility'],
                'admin_id' => $adminData['id'] ?? 'unknown',
                'admin_email' => $adminData['email'] ?? 'unknown',
            ]);

            return true;
        }

        // Public: everyone can access
        if ($post['visibility'] === 'public') {
            return true;
        }

        // Owner: always can access own posts
        if ((int)$post['user_id'] === $userId) {
            return true;
        }

        // Friends: check friendship status
        if ($post['visibility'] === 'friends') {
            return $this->checkFriendship((int)$post['user_id'], $userId);
        }

        // Private: only owner
        // friends_of_friends: not implemented yet (future)
        return false;
    }

    /**
     * Check if two users are friends
     *
     * ENTERPRISE V6.8 (2025-11-30): OVERLAY-FIRST ARCHITECTURE
     * - CHECK OVERLAY FIRST for immediate visibility after accept
     * - Overlay contains 'accepted' status for newly accepted friendships
     * - Overlay contains 'none' tombstone for cancelled/unfriended
     * - Fallback to DB only on overlay miss
     *
     * @param int $user1 First user ID
     * @param int $user2 Second user ID
     * @return bool True if friends, false otherwise
     */
    private function checkFriendship(int $user1, int $user2): bool
    {
        try {
            // =========================================================================
            // ENTERPRISE V6.8: CHECK OVERLAY FIRST (CRITICAL FOR REAL-TIME)
            // =========================================================================
            $overlay = \Need2Talk\Services\Cache\FriendshipOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlayStatus = $overlay->getFriendshipStatus($user1, $user2);

                if ($overlayStatus !== null) {
                    // ACCEPTED: Friends confirmed in overlay
                    if ($overlayStatus['status'] === 'accepted') {
                        return true;
                    }

                    // TOMBSTONE or PENDING: Not friends (yet or anymore)
                    // 'none' = cancelled/unfriended, 'pending' = request not accepted
                    if ($overlayStatus['status'] === 'none' || $overlayStatus['status'] === 'pending') {
                        return false;
                    }
                }
            }

            // =========================================================================
            // FALLBACK: Query database (overlay miss or unavailable)
            // =========================================================================
            $db = db();

            // Check bidirectional friendship (status = 'accepted')
            $friendship = $db->findOne(
                "SELECT id FROM friendships
                 WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))
                   AND status = 'accepted'
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$user1, $user2, $user2, $user1],
                ['cache' => true, 'cache_ttl' => 'medium'] // 30min cache
            );

            return $friendship !== null;

        } catch (\Exception $e) {
            Logger::error('Failed to check friendship', [
                'user1' => $user1,
                'user2' => $user2,
                'error' => $e->getMessage(),
            ]);

            // Fail closed: deny access on error
            return false;
        }
    }

    /**
     * Get audio duration using getID3
     *
     * @param string $filePath File path
     * @return float|false Duration in seconds or false
     */
    private function getAudioDuration(string $filePath): float|false
    {
        try {
            // Try getID3 first (if available)
            if (class_exists('\getID3')) {
                $getID3 = new \getID3();
                $info = $getID3->analyze($filePath);
                if (isset($info['playtime_seconds'])) {
                    return (float) $info['playtime_seconds'];
                }
            }

            // Fallback: estimate from file size (WebM @ 48kbps)
            $fileSize = filesize($filePath);
            $bitrate = 48000; // 48kbps
            $duration = ($fileSize * 8) / $bitrate;

            return min(30, max(0, $duration));

        } catch (\Exception $e) {
            Logger::api('warning', 'Failed to get audio duration', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Stream file with range support (for seeking)
     *
     * @param string $filePath File path
     * @return void
     */
    private function streamRange(string $filePath): void
    {
        $fileSize = filesize($filePath);
        $range = $_SERVER['HTTP_RANGE'];
        $range = str_replace('bytes=', '', $range);
        list($start, $end) = explode('-', $range);

        $start = (int) $start;
        $end = $end ? (int) $end : $fileSize - 1;

        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
        header('Content-Length: ' . ($end - $start + 1));

        $fp = fopen($filePath, 'rb');
        fseek($fp, $start);
        echo fread($fp, $end - $start + 1);
        fclose($fp);
    }

    /**
     * Get recent photos for gallery widget
     *
     * GET /api/audio/photos/recent?limit=5
     *
     * @return void JSON response
     */
    public function getRecentPhotos(): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            $userId = $this->getUserId();          // Still needed for WHERE clause
            $userUuid = $this->getUserUuid();      // ENTERPRISE UUID: For logging

            // ENTERPRISE V11.8: Sanitize limit parameter (prevent negative values from fuzzing)
            $limit = (int) ($this->getInput('limit') ?? 5);
            $limit = max(1, min($limit, 20)); // Clamp between 1 and 20

            // Get recent audio posts with photos from current user
            $photos = db()->query("
                SELECT
                    ap.id AS post_id,
                    af.photo_url AS url,
                    af.photo_thumbnail AS thumbnail,
                    af.title AS title,
                    ap.created_at
                FROM audio_posts ap
                INNER JOIN audio_files af ON ap.audio_file_id = af.id
                WHERE ap.user_id = :user_id
                  AND af.photo_url IS NOT NULL
                  AND ap.deleted_at IS NULL
                ORDER BY ap.created_at DESC
                LIMIT :limit
            ", [
                'user_id' => $userId,
                'limit' => $limit,
            ], [
                'cache' => true,
                'cache_ttl' => 'short', // 5min cache
            ]);

            $this->json([
                'success' => true,
                'photos' => $photos ?: [],
            ]);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to get recent photos', [
                'user_uuid' => $userUuid,  // ENTERPRISE: UUID in logs
                'user_id' => $this->getUserId(),
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    /**
     * Track audio listen progress (ENTERPRISE GALAXY V2)
     *
     * POST /api/audio/{id}/track-listen
     *
     * Body:
     * - percentage: Listen percentage (0-100)
     * - duration_played: Duration played in seconds
     *
     * Business Rules:
     * 1. Author listens NOT counted
     * 2. Must reach 80%+ to count
     * 3. 60-second cooldown per user
     * 4. Persistent tracking in PostgreSQL
     *
     * @param int $id Audio post ID
     * @return void JSON response
     */
    public function trackListen(int $id): void
    {
        try {
            // =================================================================
            // AUTH CHECK (optional - track guests too)
            // =================================================================
            $userId = $this->getUserId();          // NULL if not authenticated
            $userUuid = $this->getUserUuid();      // ENTERPRISE UUID: For logging

            // =================================================================
            // INPUT VALIDATION
            // =================================================================
            $inputRaw = file_get_contents('php://input');
            $input = json_decode($inputRaw, true);

            if (!$input) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_input',
                    'message' => 'JSON invalido',
                ], 400);

                return;
            }

            // Validate percentage
            if (!isset($input['percentage']) || !is_numeric($input['percentage'])) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_percentage',
                    'message' => 'Percentuale di ascolto richiesta',
                ], 400);

                return;
            }

            $percentage = (float) $input['percentage'];
            $durationPlayed = isset($input['duration_played']) ? (float) $input['duration_played'] : 0;

            // Validate range
            if ($percentage < 0 || $percentage > 100) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_percentage',
                    'message' => 'Percentuale deve essere tra 0 e 100',
                ], 400);

                return;
            }

            // =================================================================
            // GET AUDIO POST (verify exists + get author)
            // =================================================================
            $db = db();
            $post = $db->findOne(
                "SELECT id, user_id, visibility
                 FROM audio_posts
                 WHERE id = :id
                   AND deleted_at IS NULL
                 LIMIT 1",
                ['id' => $id],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            if (!$post) {
                $this->json([
                    'success' => false,
                    'error' => 'not_found',
                    'message' => 'Audio post non trovato',
                ], 404);

                return;
            }

            // =================================================================
            // TRACK LISTEN (Enterprise service with 80% threshold)
            // =================================================================
            $trackingService = new \Need2Talk\Services\Audio\ListenTrackingService();

            $result = $trackingService->trackListenProgress(
                audioPostId: $id,
                authorId: (int) $post['user_id'],
                userId: $userId,
                listenPercentage: $percentage,
                durationPlayed: $durationPlayed
            );

            // =================================================================
            // RESPONSE
            // =================================================================
            $statusCode = $result['counted'] ? 200 : 200; // Always 200 (not an error)

            $this->json([
                'success' => $result['counted'],
                'counted' => $result['counted'],
                'reason' => $result['reason'],
                'cooldown_remaining' => $result['cooldown_remaining'],
                'message' => $this->getListenMessage($result['reason']),
            ], $statusCode);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to track listen', [
                'audio_post_id' => $id,
                'user_uuid' => $userUuid,  // ENTERPRISE: UUID in logs
                'user_id' => $this->getUserId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    /**
     * Get user-friendly message for listen tracking result
     *
     * @param string $reason Tracking result reason
     * @return string Message
     */
    private function getListenMessage(string $reason): string
    {
        return match ($reason) {
            'success' => 'Ascolto registrato',
            'author_listen' => 'I tuoi ascolti non vengono conteggiati',
            'threshold_not_reached' => 'Ascolta almeno l\'80% per contare',
            'cooldown' => 'Attendi 60 secondi per ricontare',
            'database_error' => 'Errore nel salvataggio',
            default => 'Stato sconosciuto',
        };
    }

    /**
     * Update audio post privacy (ENTERPRISE GALAXY 2025-11-21)
     *
     * PATCH /api/audio/{id}/privacy
     *
     * Body:
     * - visibility: private|friends|friends_of_friends|public
     *
     * @param int $id Audio post ID
     * @return void JSON response
     */
    public function updatePrivacy(int $id): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);
                return;
            }

            // Get user UUID
            $userUuid = $this->getUserUuid();
            $userId = $this->getUserId();

            // Parse JSON body
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['visibility'])) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_field',
                    'message' => 'Campo visibility obbligatorio',
                ], 400);
                return;
            }

            $visibility = trim($input['visibility']);

            // Validate visibility value
            $allowedValues = ['private', 'friends', 'friends_of_friends', 'public'];
            if (!in_array($visibility, $allowedValues, true)) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_visibility',
                    'message' => 'Valore visibility non valido (usa: private, friends, friends_of_friends, public)',
                ], 400);
                return;
            }

            // Update privacy with service
            $result = $this->audioService->updatePrivacy($id, $userUuid, $visibility);

            $statusCode = $result['success'] ? 200 : 400;
            $this->json($result, $statusCode);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to update audio privacy', [
                'audio_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    /**
     * Update audio post content (ENTERPRISE GALAXY 2025-11-21)
     *
     * PATCH /api/audio/{id}
     *
     * Body:
     * - title: Optional audio title (max 500 chars) → audio_files.title
     * - content: Optional description (max 5000 chars) → audio_posts.content
     *
     * @param int $id Audio post ID
     * @return void JSON response
     */
    public function update(int $id): void
    {
        try {
            // Auth check
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);
                return;
            }

            // Get user UUID
            $userUuid = $this->getUserUuid();
            $userId = $this->getUserId();

            // Parse JSON body
            $input = json_decode(file_get_contents('php://input'), true);

            // At least one field must be provided
            if (!isset($input['title']) && !isset($input['content'])) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_fields',
                    'message' => 'Fornisci almeno un campo (title o content)',
                ], 400);
                return;
            }

            // Validate title length
            if (isset($input['title'])) {
                $title = trim($input['title']);
                if (mb_strlen($title) > 500) {
                    $this->json([
                        'success' => false,
                        'error' => 'title_too_long',
                        'message' => 'Titolo troppo lungo (max 500 caratteri)',
                    ], 400);
                    return;
                }
                // Allow empty string to clear title
                $input['title'] = $title === '' ? null : $title;
            }

            // Validate content length
            if (isset($input['content'])) {
                $content = trim($input['content']);
                if (mb_strlen($content) > 5000) {
                    $this->json([
                        'success' => false,
                        'error' => 'content_too_long',
                        'message' => 'Descrizione troppo lunga (max 5000 caratteri)',
                    ], 400);
                    return;
                }
                // Allow empty string to clear content
                $input['content'] = $content === '' ? null : $content;
            }

            // ENTERPRISE MODERATION: Apply content censorship on update
            try {
                $censorshipService = new \Need2Talk\Services\Moderation\ContentCensorshipService();

                if (isset($input['title']) && $input['title'] !== null) {
                    $titleResult = $censorshipService->censorContent($input['title'], 'post_title');
                    $input['title'] = $titleResult['censored'];
                    if ($titleResult['was_censored']) {
                        Logger::info('Content censored in audio title (update)', [
                            'post_id' => $id,
                            'matched' => $titleResult['matched'],
                        ]);
                    }
                }

                if (isset($input['content']) && $input['content'] !== null) {
                    $contentResult = $censorshipService->censorContent($input['content'], 'post_description');
                    $input['content'] = $contentResult['censored'];
                    if ($contentResult['was_censored']) {
                        Logger::info('Content censored in audio content (update)', [
                            'post_id' => $id,
                            'matched' => $contentResult['matched'],
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Logger::warning('Content censorship service failed', ['error' => $e->getMessage()]);
            }

            // Update post with service
            $result = $this->audioService->updatePost($id, $userUuid, $input);

            $statusCode = $result['success'] ? 200 : 400;
            $this->json($result, $statusCode);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to update audio post', [
                'audio_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    // ============================================================================
    // ENTERPRISE GALAXY: Helper Methods
    // ============================================================================

    /**
     * Resolve audio identifier to internal ID
     *
     * ENTERPRISE GALAXY: Supports both numeric ID and UUID for flexible API access.
     * This enables:
     * - Legacy compatibility (numeric IDs in existing code)
     * - Public API with UUIDs (no internal ID exposure)
     * - Moderation panel using UUIDs for security
     *
     * @param string $identifier Numeric ID or UUID
     * @return int|null Internal ID or null if not found
     */
    private function resolveAudioIdentifier(string $identifier): ?int
    {
        // Fast path: Numeric ID (most common case in production)
        if (ctype_digit($identifier)) {
            return (int) $identifier;
        }

        // UUID format validation: 8-4-4-4-12 hex chars with hyphens
        // Example: e0b76c87-26c4-4f9a-8b5d-1234567890ab
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            // Look up by UUID
            $db = db();
            $result = $db->findOne(
                "SELECT id FROM audio_files WHERE uuid = :uuid AND deleted_at IS NULL",
                ['uuid' => $identifier],
                ['cache' => true, 'cache_ttl' => 'medium']  // Cache UUID lookups for performance
            );

            return $result ? (int) $result['id'] : null;
        }

        // Invalid format
        Logger::warning('Invalid audio identifier format', [
            'identifier' => substr($identifier, 0, 50),  // Truncate for log safety
        ]);

        return null;
    }
}
