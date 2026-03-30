<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Chat;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Chat\ChatServiceFactory;
use Need2Talk\Services\Chat\DirectMessageService;
use Need2Talk\Services\Chat\DMAudioService;
use Need2Talk\Services\Chat\PresenceService;
use Need2Talk\Services\Logger;
use Need2Talk\Services\NotificationService;

/**
 * Direct Message API Controller
 *
 * Handles 1:1 private messaging with E2E encryption
 *
 * ENTERPRISE ARCHITECTURE:
 * - Uses ChatServiceFactory for proper Dependency Injection
 * - Services are created with context-aware Redis adapters
 * - PHP-FPM context: PhpFpmRedisAdapter (EnterpriseRedisManager wrapper)
 * - Swoole context: SwooleCoroutineRedisAdapter (per-coroutine connections)
 *
 * Endpoints:
 * - GET  /api/chat/dm - List conversations (inbox)
 * - POST /api/chat/dm - Create/get conversation
 * - GET  /api/chat/dm/{uuid} - Get conversation details
 * - GET  /api/chat/dm/{uuid}/messages - Get messages
 * - POST /api/chat/dm/{uuid}/messages - Send message
 * - POST /api/chat/dm/{uuid}/audio - Upload and send audio message (30s max)
 * - POST /api/chat/dm/{uuid}/read - Mark as read
 * - POST /api/chat/dm/{uuid}/typing - Send typing indicator
 *
 * @package Need2Talk\Controllers\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @updated 2025-12-03 - Refactored to use ChatServiceFactory (Enterprise DI Pattern)
 */
class DMController extends BaseController
{
    private DirectMessageService $dmService;
    private PresenceService $presenceService;

    public function __construct()
    {
        parent::__construct();

        // ENTERPRISE DI: Use ChatServiceFactory for proper dependency injection
        // This ensures services receive context-aware Redis adapters:
        // - PHP-FPM: PhpFpmRedisAdapter (wraps EnterpriseRedisManager)
        // - Swoole: SwooleCoroutineRedisAdapter (per-coroutine connections, no deadlock)
        $this->dmService = ChatServiceFactory::createDirectMessageService();
        $this->presenceService = ChatServiceFactory::createPresenceService();
    }

    /**
     * GET /api/chat/dm
     *
     * List conversations (inbox)
     * Query params:
     * - limit: int (default 20, max 50)
     * - offset: int (default 0)
     */
    public function inbox(): void
    {
        $user = $this->requireAuth();

        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $conversations = $this->dmService->getConversations($user['uuid'], $limit, $offset);
        $unreadTotal = $this->dmService->getUnreadCount($user['uuid']);

        $this->json([
            'success' => true,
            'data' => [
                'conversations' => $conversations,
                'unread_total' => $unreadTotal,
                'has_more' => count($conversations) === $limit,
            ],
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * POST /api/chat/dm
     *
     * Create or get existing conversation with a user
     * Body: { recipient_uuid }
     */
    public function create(): void
    {
        $user = $this->requireAuth();

        $input = $this->getJsonInput();
        $recipientUuid = $input['recipient_uuid'] ?? null;

        if (!$recipientUuid || strlen($recipientUuid) !== 36) {
            $this->json(['success' => false, 'error' => 'Invalid recipient UUID'], 400);
            return;
        }

        if ($recipientUuid === $user['uuid']) {
            $this->json(['success' => false, 'error' => 'Cannot message yourself'], 400);
            return;
        }

        // Lookup recipient's numeric ID from UUID (required by DirectMessageService)
        $db = db();
        $recipient = $db->findOne(
            "SELECT id FROM users WHERE uuid::text = ?",
            [$recipientUuid]
        );

        if (!$recipient) {
            $this->json(['success' => false, 'error' => 'Recipient not found'], 404);
            return;
        }

        // DirectMessageService expects: (int $user1Id, string $user1Uuid, int $user2Id, string $user2Uuid)
        $conversation = $this->dmService->getOrCreateConversation(
            (int) $user['id'],
            $user['uuid'],
            (int) $recipient['id'],
            $recipientUuid
        );

        if ($conversation) {
            $this->json([
                'success' => true,
                'data' => [
                    'conversation' => $conversation,
                ],
            ], 200);
        } else {
            $this->json([
                'success' => false,
                'error' => 'Failed to create conversation (user may be blocked)',
            ], 400);
        }
    }

    /**
     * GET /api/chat/dm/{uuid}
     *
     * Get conversation details
     */
    public function conversation(string $uuid): void
    {
        $user = $this->requireAuth();

        $conversation = $this->dmService->getConversation($uuid, $user['uuid']);

        if (!$conversation) {
            $this->json(['success' => false, 'error' => 'Conversation not found'], 404);
            return;
        }

        // Get other user's presence
        $otherUuid = $conversation['user1_uuid'] === $user['uuid']
            ? $conversation['user2_uuid']
            : $conversation['user1_uuid'];

        $otherPresence = $this->presenceService->getStatus($otherUuid);
        $lastSeenFormatted = $this->presenceService->getLastSeenFormatted($otherUuid);

        // ENTERPRISE V10.170: Check if other user has E2E key for encryption warning
        $otherUserHasE2eKey = false;
        $otherUser = db()->findOne(
            'SELECT ecdh_public_key FROM users WHERE uuid = ?',
            [$otherUuid],
            ['cache' => true, 'cache_ttl' => 'medium']
        );
        if ($otherUser && !empty($otherUser['ecdh_public_key'])) {
            $otherUserHasE2eKey = true;
        }

        $this->json([
            'success' => true,
            'data' => [
                'conversation' => $conversation,
                'other_user_presence' => [
                    'status' => $otherPresence['status'],
                    'is_online' => $otherPresence['is_online'],
                    'last_seen' => $lastSeenFormatted,
                ],
                'other_user_has_e2e_key' => $otherUserHasE2eKey,
            ],
        ]);
    }

    /**
     * GET /api/chat/dm/{uuid}/messages
     *
     * Get conversation messages (encrypted)
     * Query params:
     * - limit: int (default 50, max 100)
     * - before: string (message UUID for pagination)
     */
    public function messages(string $uuid): void
    {
        $user = $this->requireAuth();

        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
        $before = $_GET['before'] ?? null;

        // getMessages returns array of messages directly (empty array if not found/unauthorized)
        $messages = $this->dmService->getMessages($uuid, $user['uuid'], $limit, $before);

        $this->json([
            'success' => true,
            'data' => [
                'messages' => $messages,
                'has_more' => count($messages) === $limit,
            ],
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * POST /api/chat/dm/{uuid}/messages
     *
     * Send encrypted message
     * Body: {
     *   encrypted_content: base64,
     *   content_iv: base64,
     *   content_tag: base64,
     *   message_type?: 'text'|'audio'|'image'
     * }
     *
     * Note: Encryption happens client-side with Web Crypto API
     * Server stores encrypted blob, cannot read content
     *
     * ENTERPRISE v9.9: Rate limited to 15 messages/minute per user
     */
    public function send(string $uuid): void
    {
        $user = $this->requireAuth();

        // ENTERPRISE v9.9: Check message rate limit (15/min per user)
        // Returns warning before blocking to allow user to slow down
        $rateLimitResult = $this->checkMessageRateLimit((int) $user['id']);
        if (!$rateLimitResult['allowed']) {
            $this->json([
                'success' => false,
                'error' => 'Stai inviando messaggi troppo velocemente. Attendi qualche secondo.',
                'rate_limited' => true,
                'retry_after' => $rateLimitResult['retry_after'] ?? 60,
            ], 429);
            return;
        }

        // ENTERPRISE v9.9: Return warning if approaching rate limit (>12 messages in last minute)
        $warning = $rateLimitResult['warning'] ?? null;

        $input = $this->getJsonInput();

        $encryptedContent = $input['encrypted_content'] ?? null;
        $contentIv = $input['content_iv'] ?? null;
        $contentTag = $input['content_tag'] ?? null;
        $messageType = $input['message_type'] ?? 'text';

        if (!$encryptedContent) {
            $this->json(['success' => false, 'error' => 'Encrypted content is required'], 400);
            return;
        }

        // Validate base64 format
        if (!$this->isValidBase64($encryptedContent)) {
            $this->json(['success' => false, 'error' => 'Invalid encrypted content format'], 400);
            return;
        }

        // Max encrypted message size: 64KB
        if (strlen($encryptedContent) > 65536) {
            $this->json(['success' => false, 'error' => 'Message too large'], 400);
            return;
        }

        // ENTERPRISE V9.2: sendMessage signature is:
        // sendMessage(convUuid, senderId, senderUuid, content, encryptedData, messageType, extra)
        $result = $this->dmService->sendMessage(
            $uuid,                      // conversation UUID
            (int) $user['id'],          // sender ID (integer)
            $user['uuid'],              // sender UUID
            null,                       // content (null because encrypted)
            [                           // encrypted data
                'ciphertext' => $encryptedContent,
                'iv' => $contentIv,
                'tag' => $contentTag,
            ],
            $messageType,               // message type
            []                          // extra data
        );

        // ENTERPRISE V10.145: Check if message was blocked by DND
        if (is_array($result) && isset($result['blocked']) && $result['blocked'] === true) {
            $this->json([
                'success' => false,
                'blocked' => true,
                'reason' => $result['reason'] ?? 'dnd',
                'error' => $result['message'] ?? 'L\'utente è occupato. Riprova più tardi.',
            ], 403);
            return;
        }

        if ($result) {
            // ENTERPRISE GALAXY v9.5: Create bell notification for recipient
            // WebSocket real-time delivery is handled in sendMessage()
            // This creates the PERSISTENT notification for the bell icon
            $this->createDmNotification($uuid, $user);

            // ENTERPRISE V10.7: Get sender's current status (may have changed from DND to online)
            $senderPresence = $this->presenceService->getStatus($user['uuid']);

            // ENTERPRISE V10.145: Check if this was first message to DND user
            $recipientUuid = $this->dmService->getRecipientUuidFromConversation($uuid, $user['uuid']);
            $recipientInDnd = $recipientUuid ? $this->presenceService->isInDndMode($recipientUuid) : false;

            $response = [
                'success' => true,
                'data' => [
                    'message' => $result,  // sendMessage returns the message array directly
                    'sender_status' => $senderPresence['status'] ?? 'online', // Current sender status
                    'recipient_dnd' => $recipientInDnd, // ENTERPRISE V10.145: Tell frontend recipient is DND
                ],
            ];

            // ENTERPRISE v9.9: Include rate limit warning if approaching limit
            if ($warning) {
                $response['warning'] = $warning;
            }

            $this->json($response, 201);
        } else {
            $this->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to send message',
            ], 400);
        }
    }

    /**
     * POST /api/chat/dm/{uuid}/audio
     *
     * Upload and send audio message (ASYNC via worker queue)
     * Accepts multipart/form-data with:
     * - audio: File (WebM/Opus, max 30s, max 500KB)
     * - encryption_iv: Base64 IV for E2E decryption
     * - encryption_tag: Base64 TAG for E2E decryption
     * - is_encrypted: "1" (required)
     * - duration: float (client-provided duration)
     *
     * ENTERPRISE V4.3 (2025-12-10): ASYNC WORKER ARCHITECTURE
     * - Audio queued in Redis for background processing
     * - PHP-FPM returns immediately (202 Accepted) with job_id
     * - Worker uploads to S3, saves to DB, notifies via WebSocket
     * - Client receives `dm_audio_uploaded` event when complete
     *
     * FLOW:
     * 1. Client POSTs audio → PHP-FPM validates & enqueues (<5ms)
     * 2. PHP-FPM returns 202 Accepted with job_id
     * 3. DM Audio Worker processes queue (S3 + DB + WebSocket)
     * 4. Sender receives `dm_audio_uploaded` WebSocket event
     * 5. Recipient receives `dm_received` WebSocket event
     *
     * Rate limited: 30 audio messages per day per user
     *
     * @param string $uuid Conversation UUID
     */
    public function uploadAudio(string $uuid): void
    {
        $user = $this->requireAuth();

        // ENTERPRISE DEBUG: Log incoming request
        Logger::audio('info', 'DM audio upload request (async)', [
            'uuid' => $uuid,
            'user_id' => $user['id'] ?? null,
            'files_keys' => array_keys($_FILES),
            'files_audio' => isset($_FILES['audio']) ? [
                'name' => $_FILES['audio']['name'] ?? null,
                'type' => $_FILES['audio']['type'] ?? null,
                'size' => $_FILES['audio']['size'] ?? null,
                'error' => $_FILES['audio']['error'] ?? null,
            ] : 'NOT SET',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        ]);

        // ENTERPRISE: Check audio rate limit (30/day per user)
        $rateLimitResult = $this->checkAudioRateLimit((int) $user['id']);
        if (!$rateLimitResult['allowed']) {
            $this->json([
                'success' => false,
                'error' => 'Hai raggiunto il limite di messaggi audio giornalieri. Riprova domani.',
                'rate_limited' => true,
                'retry_after' => $rateLimitResult['retry_after'] ?? 3600,
            ], 429);
            return;
        }

        // Validate file upload
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            Logger::audio('warning', 'DM audio upload failed - no file received', [
                'uuid' => $uuid,
                'files' => $_FILES,
                'error_code' => $_FILES['audio']['error'] ?? 'NOT SET',
            ]);
            $this->json([
                'success' => false,
                'error' => 'Nessun file audio ricevuto',
            ], 400);
            return;
        }

        // Get client-provided parameters
        $clientDuration = isset($_POST['duration']) ? (float) $_POST['duration'] : null;
        $encryptionIv = isset($_POST['encryption_iv']) ? trim($_POST['encryption_iv']) : null;
        $encryptionTag = isset($_POST['encryption_tag']) ? trim($_POST['encryption_tag']) : null;
        $isEncrypted = isset($_POST['is_encrypted']) && $_POST['is_encrypted'] === '1';

        // ENTERPRISE V10.100: TRUE E2E - encryption is REQUIRED
        if (!$isEncrypted || !$encryptionIv || !$encryptionTag) {
            Logger::security('warning', 'DM audio rejected - E2E encryption required', [
                'conversation_uuid' => $uuid,
                'sender_uuid' => $user['uuid'],
                'is_encrypted' => $isEncrypted,
                'has_iv' => !empty($encryptionIv),
                'has_tag' => !empty($encryptionTag),
            ]);
            $this->json([
                'success' => false,
                'error' => 'E2E encryption required for audio messages',
            ], 400);
            return;
        }

        // ENTERPRISE V4.3: Enqueue job for async processing
        // This returns immediately (<5ms), freeing PHP-FPM for real-time chat
        $queueService = new \Need2Talk\Services\Chat\DMAudioQueueService();
        $queueResult = $queueService->enqueue(
            $_FILES['audio'],
            $uuid,
            (int) $user['id'],
            $user['uuid'],
            $clientDuration,
            $encryptionIv,
            $encryptionTag
        );

        if (!$queueResult['success']) {
            Logger::audio('error', 'DM audio queue failed', [
                'conversation_uuid' => $uuid,
                'sender_uuid' => $user['uuid'],
                'error' => $queueResult['error'] ?? 'Unknown error',
            ]);
            $this->json([
                'success' => false,
                'error' => $queueResult['error'] ?? 'Errore durante l\'accodamento dell\'audio',
            ], 400);
            return;
        }

        // ENTERPRISE V4.3: Return 202 Accepted immediately
        // Client will receive WebSocket event `dm_audio_uploaded` when processing completes
        Logger::audio('info', 'DM audio job queued successfully', [
            'job_id' => $queueResult['job_id'],
            'conversation_uuid' => $uuid,
            'sender_uuid' => $user['uuid'],
            'file_size' => $_FILES['audio']['size'] ?? 0,
        ]);

        $this->json([
            'success' => true,
            'data' => [
                'status' => 'queued',
                'job_id' => $queueResult['job_id'],
                'message' => 'Audio in elaborazione. Riceverai una notifica al completamento.',
            ],
        ], 202); // 202 Accepted - processing async
    }

    /**
     * Check audio message rate limit for user
     *
     * ENTERPRISE V3.1: 30 audio messages per day per user (DM = more frequent than posts)
     *
     * @param int $userId User ID
     * @return array ['allowed' => bool, 'retry_after' => int|null]
     */
    private function checkAudioRateLimit(int $userId): array
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $connection = $redis->getConnection('rate_limit');

            if (!$connection) {
                return ['allowed' => true]; // Fail-open
            }

            $key = "chat:dm:audio:rate:{$userId}";
            $maxAudioPerDay = 30;
            $windowSeconds = 86400; // 24 hours

            $count = (int) $connection->get($key);

            if ($count >= $maxAudioPerDay) {
                $ttl = $connection->ttl($key);
                return [
                    'allowed' => false,
                    'retry_after' => max(1, $ttl),
                ];
            }

            // Increment counter
            $connection->incr($key);
            if ($count === 0) {
                $connection->expire($key, $windowSeconds);
            }

            return ['allowed' => true];

        } catch (\Exception $e) {
            \Need2Talk\Services\Logger::warning('Audio rate limit check failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['allowed' => true]; // Fail-open
        }
    }

    /**
     * Check message rate limit for user
     *
     * ENTERPRISE v9.9: 15 messages per minute per user
     * Returns warning when approaching limit (>12 messages)
     *
     * @param int $userId User ID
     * @return array ['allowed' => bool, 'warning' => string|null, 'retry_after' => int|null]
     */
    private function checkMessageRateLimit(int $userId): array
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $connection = $redis->getConnection('rate_limit');

            if (!$connection) {
                // Redis unavailable - allow (fail-open for UX)
                return ['allowed' => true, 'warning' => null];
            }

            $key = "chat:msg:rate:{$userId}";
            $window = 60; // 60 seconds window
            $maxMessages = 15;
            $warningThreshold = 12; // Warn when >12 messages in window

            $now = time();
            $windowStart = $now - $window;

            // Use Redis sorted set with timestamp as score
            // Remove old entries outside the window
            $connection->zRemRangeByScore($key, '-inf', (string) $windowStart);

            // Get current count in window
            $count = $connection->zCard($key);

            if ($count >= $maxMessages) {
                // Rate limited
                $oldestInWindow = $connection->zRange($key, 0, 0, ['WITHSCORES' => true]);
                $retryAfter = $window;
                if (!empty($oldestInWindow)) {
                    $oldestTime = (int) reset($oldestInWindow);
                    $retryAfter = max(1, ($oldestTime + $window) - $now);
                }

                return [
                    'allowed' => false,
                    'warning' => null,
                    'retry_after' => $retryAfter,
                ];
            }

            // Add this request to the sorted set
            $connection->zAdd($key, $now, $now . ':' . mt_rand());
            $connection->expire($key, $window + 10); // TTL slightly longer than window

            // Return warning if approaching limit
            $warning = null;
            if ($count >= $warningThreshold) {
                $remaining = $maxMessages - $count - 1;
                $warning = "Rallenta! Puoi inviare ancora {$remaining} messaggi in questo minuto.";
            }

            return [
                'allowed' => true,
                'warning' => $warning,
            ];

        } catch (\Exception $e) {
            \Need2Talk\Services\Logger::warning('Message rate limit check failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            // On error, allow (fail-open)
            return ['allowed' => true, 'warning' => null];
        }
    }

    /**
     * POST /api/chat/dm/{uuid}/read
     *
     * Mark messages as read
     */
    public function markRead(string $uuid): void
    {
        $user = $this->requireAuth();

        // markAsRead returns int (count of updated messages)
        $updated = $this->dmService->markAsRead($uuid, $user['uuid']);

        $this->json([
            'success' => true,
            'updated' => $updated,
            'message' => $updated > 0 ? "Marked $updated messages as read" : 'No unread messages',
        ]);
    }

    /**
     * POST /api/chat/dm/{uuid}/typing
     *
     * Send typing indicator via HTTP (fallback for non-WebSocket)
     * Body: { is_typing: bool }
     */
    public function typing(string $uuid): void
    {
        $user = $this->requireAuth();

        $input = $this->getJsonInput();
        $isTyping = (bool) ($input['is_typing'] ?? true);

        // Get conversation to find recipient
        $conversation = $this->dmService->getConversation($uuid, $user['uuid']);

        if (!$conversation) {
            $this->json(['success' => false, 'error' => 'Conversation not found'], 404);
            return;
        }

        $recipientUuid = $conversation['user1_uuid'] === $user['uuid']
            ? $conversation['user2_uuid']
            : $conversation['user1_uuid'];

        if ($isTyping) {
            $this->presenceService->setTypingDM($uuid, $user['uuid'], $recipientUuid);
        } else {
            $this->presenceService->clearTypingDM($uuid, $user['uuid'], $recipientUuid);
        }

        $this->json(['success' => true]);
    }

    /**
     * GET /api/chat/dm/{uuid}/key
     *
     * Get encryption key fingerprint for the other user in conversation
     * Used by the "Verify Encryption" modal to display key fingerprints
     *
     * ENTERPRISE V10.34: Key Verification Endpoint
     *
     * Response: {
     *   success: true,
     *   data: {
     *     other_user_fingerprint: string (hex format XX:XX:XX...)
     *   }
     * }
     */
    public function getKey(string $uuid): void
    {
        $user = $this->requireAuth();

        // Get conversation to find other user
        $conversation = $this->dmService->getConversation($uuid, $user['uuid']);

        if (!$conversation) {
            $this->json(['success' => false, 'error' => 'Conversation not found'], 404);
            return;
        }

        // Determine other user UUID (formatConversation provides other_user_uuid)
        $otherUserUuid = $conversation['other_user_uuid'] ?? null;

        if (!$otherUserUuid) {
            // Fallback: determine from user1_uuid/user2_uuid
            $otherUserUuid = $conversation['user1_uuid'] === $user['uuid']
                ? $conversation['user2_uuid']
                : $conversation['user1_uuid'];
        }

        // ENTERPRISE V10.100 (2025-12-09): Get ECDH public key for TRUE E2E
        // This is the key needed for ECDH key derivation in ChatEncryptionService.js
        $db = db();

        // Get other user's ECDH public key directly from users table
        // ENTERPRISE V10.183: NO CACHE for ECDH keys - they can change and cause decryption failures
        $otherUser = $db->findOne(
            "SELECT ecdh_public_key FROM users WHERE uuid::text = :user_uuid",
            ['user_uuid' => $otherUserUuid],
            ['cache' => false]  // CRITICAL: No cache for crypto keys
        );

        $ecdhPublicKey = $otherUser['ecdh_public_key'] ?? null;

        // Generate fingerprint from ECDH public key (TRUE E2E - no server-side keys)
        $fingerprint = 'Non disponibile';
        if ($ecdhPublicKey) {
            $hash = hash('sha256', $ecdhPublicKey, true);
            $fingerprint = implode(':', array_map(
                fn($byte) => sprintf('%02x', ord($byte)),
                str_split(substr($hash, 0, 16))
            ));
        }

        $this->json([
            'success' => true,
            'data' => [
                'other_user_fingerprint' => $fingerprint,
                // ENTERPRISE V10.100: TRUE E2E - include ECDH public key for key derivation
                'other_user_public_key' => $ecdhPublicKey,
            ],
        ]);
    }

    /**
     * Helper: Get JSON input from request body
     */
    protected function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    /**
     * Helper: Validate base64 string
     */
    private function isValidBase64(string $string): bool
    {
        if (empty($string)) {
            return false;
        }

        // Remove whitespace
        $string = preg_replace('/\s/', '', $string);

        // Check if valid base64
        return base64_encode(base64_decode($string, true)) === $string;
    }

    /**
     * Create bell notification for DM recipient
     *
     * ENTERPRISE GALAXY v9.5: DM Notification System
     * ENTERPRISE V10.77 (2025-12-07): Smart notification with cooldown + activity check
     *
     * This creates a PERSISTENT notification that appears in the bell icon.
     * The real-time WebSocket push is already handled in DirectMessageService::sendMessage().
     *
     * Architecture:
     * 1. WebSocket `dm_received` event → Real-time chat update
     * 2. NotificationService::notifyDmReceived() → Bell icon badge (this method)
     *
     * SMART NOTIFICATION RULES (V10.77):
     * - Cooldown: 10 minutes between notifications for same conversation
     * - Activity check: If chat activity in last 3 min, skip notification (users are chatting)
     * - This prevents notification spam during active conversations
     *
     * @param string $conversationUuid Conversation UUID
     * @param array $sender Authenticated user who sent the message
     */
    private function createDmNotification(string $conversationUuid, array $sender): void
    {
        try {
            $db = db();

            // Get conversation to find recipient
            $conversation = $db->findOne(
                "SELECT
                    dc.id,
                    dc.user1_id,
                    dc.user1_uuid::text AS user1_uuid,
                    dc.user2_id,
                    dc.user2_uuid::text AS user2_uuid
                 FROM direct_conversations dc
                 WHERE dc.uuid::text = ?",
                [$conversationUuid]
            );

            if (!$conversation) {
                return;
            }

            // Determine recipient
            $isUser1 = $conversation['user1_uuid'] === $sender['uuid'];
            $recipientId = $isUser1 ? (int) $conversation['user2_id'] : (int) $conversation['user1_id'];
            $recipientUuid = $isUser1 ? $conversation['user2_uuid'] : $conversation['user1_uuid'];

            // ENTERPRISE V10.77: Check cooldown and recent activity before sending notification
            $shouldNotify = $this->shouldSendDmNotification(
                $conversationUuid,
                (int) $conversation['id'],
                $recipientId
            );

            if (!$shouldNotify) {
                return; // Skip notification - either cooldown active or recent chat activity
            }

            // Check if recipient is online
            $recipientPresence = $this->presenceService->getStatus($recipientUuid);
            $isOnline = $recipientPresence['is_online'] ?? false;

            // Create notification
            $notificationService = NotificationService::getInstance();

            if ($isOnline) {
                // User is online: standard DM notification
                // Preview is encrypted, so we use a generic message
                $notificationService->notifyDmReceived(
                    $recipientId,
                    (int) $sender['id'],
                    $conversationUuid,
                    'Ti ha inviato un messaggio' // Generic preview (content is encrypted)
                );
            } else {
                // User is offline: "Mi ha cercato" notification
                // This persists and shows when user logs back in
                $notificationService->notifyDmMiHaCercato(
                    $recipientId,
                    (int) $sender['id'],
                    $conversationUuid
                );
            }

            // ENTERPRISE V10.77: Record notification time for cooldown tracking
            $this->recordDmNotificationSent($conversationUuid, $recipientId);

        } catch (\Exception $e) {
            // Don't fail message send if notification fails
            \Need2Talk\Services\Logger::warning('Failed to create DM notification', [
                'conversation_uuid' => $conversationUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE V10.77: Check if DM notification should be sent
     *
     * Rules:
     * 1. Cooldown: 10 minutes between notifications for same recipient in same conversation
     * 2. Activity check: If there was chat activity (from recipient) in last 3 minutes, skip
     *    This indicates an active conversation where notifications would be spam
     *
     * @param string $conversationUuid Conversation UUID
     * @param int $conversationId Conversation database ID
     * @param int $recipientId Recipient user ID
     * @return bool True if notification should be sent
     */
    private function shouldSendDmNotification(string $conversationUuid, int $conversationId, int $recipientId): bool
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('cache');
            if (!$redis) {
                return true; // Default to sending if Redis unavailable
            }
            $now = time();

            // Check cooldown (10 minutes = 600 seconds)
            $cooldownKey = "dm_notif_cooldown:{$conversationUuid}:{$recipientId}";
            $lastNotificationTime = $redis->get($cooldownKey);

            if ($lastNotificationTime !== false) {
                $elapsed = $now - (int) $lastNotificationTime;
                if ($elapsed < 600) { // 10 minutes cooldown
                    Logger::info('DM notification skipped (cooldown active)', [
                        'conversation_uuid' => $conversationUuid,
                        'recipient_id' => $recipientId,
                        'cooldown_remaining' => 600 - $elapsed,
                    ]);
                    return false;
                }
            }

            // Check recent chat activity from recipient (last 3 minutes)
            // If recipient has sent messages recently, they're actively chatting - skip notification
            $db = \db();
            $recentActivityCheck = $db->findOne(
                "SELECT 1 FROM direct_messages
                 WHERE conversation_id = ?
                   AND sender_id = ?
                   AND created_at > NOW() - INTERVAL '3 minutes'
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$conversationId, $recipientId],
                ['cache' => false]
            );

            if ($recentActivityCheck) {
                Logger::info('DM notification skipped (active conversation)', [
                    'conversation_uuid' => $conversationUuid,
                    'recipient_id' => $recipientId,
                    'reason' => 'Recipient sent message in last 3 min',
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            // On error, default to sending notification
            Logger::warning('DM notification check failed, defaulting to send', [
                'conversation_uuid' => $conversationUuid,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * ENTERPRISE V10.77: Record notification sent time for cooldown tracking
     *
     * @param string $conversationUuid
     * @param int $recipientId
     */
    private function recordDmNotificationSent(string $conversationUuid, int $recipientId): void
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('cache');
            if (!$redis) {
                return;
            }
            $cooldownKey = "dm_notif_cooldown:{$conversationUuid}:{$recipientId}";
            // Store timestamp with 15 minute expiry (slightly longer than cooldown for safety)
            $redis->setex($cooldownKey, 900, time());
        } catch (\Exception $e) {
            // Non-critical, just log
            Logger::warning('Failed to record DM notification time', [
                'conversation_uuid' => $conversationUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
