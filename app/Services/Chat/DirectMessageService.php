<?php

declare(strict_types=1);

namespace Need2Talk\Services\Chat;

use Need2Talk\Contracts\Redis\RedisAdapterInterface;
use Need2Talk\Contracts\Database\DatabaseAdapterInterface;
use Need2Talk\Contracts\Publisher\EventPublisherInterface;
use Need2Talk\Services\Chat\DMAudioService;
use Need2Talk\Services\Logger;
use Need2Talk\Services\NotificationService;

/**
 * DirectMessageService - Enterprise-Grade Private Messaging
 *
 * Features:
 * - Hybrid E2E encryption (client-side AES-256-GCM + server escrow)
 * - Persistent messages in PostgreSQL (partitioned by month)
 * - Read receipts with WebSocket notifications
 * - Message reporting with escrow key release
 * - User blocking
 *
 * Performance:
 * - All queries use explicit columns (no SELECT *)
 * - Proper table aliases prevent ambiguity
 * - UUID type casting for cross-table compatibility
 * - Index-optimized WHERE clauses
 * - Partitioned table queries optimized
 *
 * ENTERPRISE DI: This service uses constructor injection for all dependencies.
 * Use ChatServiceFactory::createDirectMessageService() to instantiate.
 *
 * @package Need2Talk\Services\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class DirectMessageService
{
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;

    /**
     * Message TTL (Time To Live) in seconds
     * Messages expire after this time for privacy protection
     * ENTERPRISE: 1 hour = 3600 seconds
     */
    public const MESSAGE_TTL_SECONDS = 3600;

    private RedisAdapterInterface $redis;
    private ?DatabaseAdapterInterface $database;
    private PresenceService $presenceService;
    private ChatModerationService $moderationService;
    private ?EventPublisherInterface $publisher;

    /**
     * Constructor with dependency injection
     *
     * @param RedisAdapterInterface $redis Redis adapter (context-aware)
     * @param DatabaseAdapterInterface|null $database Database adapter (optional in Swoole)
     * @param PresenceService $presenceService Injected presence service
     * @param ChatModerationService $moderationService Injected moderation service
     * @param EventPublisherInterface|null $publisher Event publisher for real-time updates
     */
    public function __construct(
        RedisAdapterInterface $redis,
        ?DatabaseAdapterInterface $database,
        PresenceService $presenceService,
        ChatModerationService $moderationService,
        ?EventPublisherInterface $publisher = null
    ) {
        $this->redis = $redis;
        $this->database = $database;
        $this->presenceService = $presenceService;
        $this->moderationService = $moderationService;
        $this->publisher = $publisher;
    }

    /**
     * Get database adapter (falls back to db() helper if not injected)
     */
    private function db(): DatabaseAdapterInterface|\Need2Talk\Core\EnterpriseSecureDatabasePool
    {
        return $this->database ?? db();
    }

    /**
     * Publish event to user (uses injected publisher or falls back to static)
     */
    private function publishToUser(string $userUuid, string $event, array $data): bool
    {
        if ($this->publisher) {
            return $this->publisher->publishToUser($userUuid, $event, $data);
        }

        // Fallback to static publisher for PHP-FPM context
        return \Need2Talk\Services\WebSocketPublisher::publishToUser($userUuid, $event, $data);
    }

    /**
     * Get or create conversation between two users
     * Uses canonical ordering (user1_id < user2_id) for uniqueness
     */
    public function getOrCreateConversation(
        int $user1Id,
        string $user1Uuid,
        int $user2Id,
        string $user2Uuid
    ): ?array {
        $db = $this->db();

        // Canonical ordering (user1_id must be < user2_id for unique constraint)
        if ($user1Id > $user2Id) {
            [$user1Id, $user2Id] = [$user2Id, $user1Id];
            [$user1Uuid, $user2Uuid] = [$user2Uuid, $user1Uuid];
        }

        try {
            // Check if blocked first
            if ($this->isBlocked($user1Id, $user2Id)) {
                return null;
            }

            // Use INSERT ... ON CONFLICT to handle race conditions
            // This atomically creates or returns existing conversation
            $conversation = $db->findOne(
                "INSERT INTO direct_conversations
                 (user1_id, user1_uuid, user2_id, user2_uuid, is_e2e_encrypted, created_at)
                 VALUES (?, ?::uuid, ?, ?::uuid, TRUE, NOW())
                 ON CONFLICT (user1_id, user2_id) DO UPDATE SET
                    updated_at = NOW()
                 RETURNING
                    id,
                    uuid::text AS uuid,
                    user1_id,
                    user1_uuid::text AS user1_uuid,
                    user2_id,
                    user2_uuid::text AS user2_uuid,
                    user1_status,
                    user2_status,
                    is_e2e_encrypted,
                    key_exchange_complete,
                    last_message_at,
                    last_message_preview,
                    user1_unread_count,
                    user2_unread_count,
                    created_at",
                [$user1Id, $user1Uuid, $user2Id, $user2Uuid]
            );

            if (!$conversation) {
                Logger::error('Failed to get/create DM conversation', [
                    'user1_id' => $user1Id,
                    'user2_id' => $user2Id,
                ]);
                return null;
            }

            return $this->formatConversation($conversation);

        } catch (\Exception $e) {
            Logger::error('Failed to get/create conversation', [
                'user1_uuid' => $user1Uuid,
                'user2_uuid' => $user2Uuid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get conversation by UUID
     * Verifies user is a participant
     */
    public function getConversation(string $conversationUuid, string $userUuid): ?array
    {
        $db = $this->db();

        $conversation = $db->findOne(
            "SELECT
                dc.id,
                dc.uuid::text AS uuid,
                dc.user1_id,
                dc.user1_uuid::text AS user1_uuid,
                dc.user2_id,
                dc.user2_uuid::text AS user2_uuid,
                dc.user1_status,
                dc.user2_status,
                dc.is_e2e_encrypted,
                dc.key_exchange_complete,
                dc.last_message_at,
                dc.last_message_preview,
                dc.user1_unread_count,
                dc.user2_unread_count,
                dc.created_at
             FROM direct_conversations dc
             WHERE dc.uuid::text = ?
               AND (dc.user1_uuid::text = ? OR dc.user2_uuid::text = ?)",
            [$conversationUuid, $userUuid, $userUuid]
        );

        return $conversation ? $this->formatConversation($conversation, $userUuid) : null;
    }

    /**
     * Get user's inbox (conversations list)
     * Uses partial indexes on user status for optimal performance
     */
    public function getInbox(string $userUuid, int $limit = 20, int $offset = 0): array
    {
        $db = $this->db();

        $conversations = $db->query(
            "SELECT
                dc.id,
                dc.uuid::text AS uuid,
                dc.user1_id,
                dc.user1_uuid::text AS user1_uuid,
                dc.user2_id,
                dc.user2_uuid::text AS user2_uuid,
                dc.user1_status,
                dc.user2_status,
                dc.is_e2e_encrypted,
                dc.key_exchange_complete,
                dc.last_message_at,
                dc.last_message_preview,
                dc.user1_unread_count,
                dc.user2_unread_count,
                dc.created_at,
                CASE
                    WHEN dc.user1_uuid::text = ? THEN dc.user2_uuid::text
                    ELSE dc.user1_uuid::text
                END AS other_user_uuid,
                CASE
                    WHEN dc.user1_uuid::text = ? THEN dc.user1_unread_count
                    ELSE dc.user2_unread_count
                END AS unread_count
             FROM direct_conversations dc
             WHERE (dc.user1_uuid::text = ? OR dc.user2_uuid::text = ?)
               AND (
                   (dc.user1_uuid::text = ? AND dc.user1_status = 'active') OR
                   (dc.user2_uuid::text = ? AND dc.user2_status = 'active')
               )
             ORDER BY dc.last_message_at DESC NULLS LAST
             LIMIT ? OFFSET ?",
            [$userUuid, $userUuid, $userUuid, $userUuid, $userUuid, $userUuid, $limit, $offset]
        );

        $result = [];
        foreach ($conversations as $conv) {
            $formatted = $this->formatConversation($conv, $userUuid);
            $otherUserUuid = $conv['other_user_uuid'];
            $otherUser = $this->getUserBasicInfo($otherUserUuid);

            if ($otherUser) {
                $formatted['other_user'] = $otherUser;
                $formatted['other_user']['status'] = $this->presenceService->getStatus($otherUserUuid);
            }

            $result[] = $formatted;
        }

        return $result;
    }

    /**
     * Get conversations (alias for getInbox)
     */
    public function getConversations(string $userUuid, int $limit = 20, int $offset = 0): array
    {
        return $this->getInbox($userUuid, $limit, $offset);
    }

    /**
     * Get total unread message count for user
     * Uses partial indexes on unread counts for optimal aggregation
     */
    public function getUnreadCount(string $userUuid): int
    {
        $db = $this->db();

        try {
            $result = $db->findOne(
                "SELECT
                    COALESCE(SUM(
                        CASE
                            WHEN dc.user1_uuid::text = ? THEN dc.user1_unread_count
                            WHEN dc.user2_uuid::text = ? THEN dc.user2_unread_count
                            ELSE 0
                        END
                    ), 0)::integer AS total_unread
                 FROM direct_conversations dc
                 WHERE (dc.user1_uuid::text = ? OR dc.user2_uuid::text = ?)
                   AND (
                       (dc.user1_uuid::text = ? AND dc.user1_status = 'active') OR
                       (dc.user2_uuid::text = ? AND dc.user2_status = 'active')
                   )",
                [$userUuid, $userUuid, $userUuid, $userUuid, $userUuid, $userUuid]
            );

            return (int) ($result['total_unread'] ?? 0);

        } catch (\Exception $e) {
            Logger::warning('Failed to get unread count', [
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Send a direct message
     * Transaction-safe with proper recipient notification
     */
    public function sendMessage(
        string $conversationUuid,
        int $senderId,
        string $senderUuid,
        ?string $content,
        array $encryptedData = [],
        string $messageType = 'text',
        array $extra = []
    ): ?array {
        $db = $this->db();

        try {
            $conversation = $db->findOne(
                "SELECT
                    dc.id,
                    dc.uuid::text AS uuid,
                    dc.user1_id,
                    dc.user1_uuid::text AS user1_uuid,
                    dc.user2_id,
                    dc.user2_uuid::text AS user2_uuid,
                    dc.user1_status,
                    dc.user2_status
                 FROM direct_conversations dc
                 WHERE dc.uuid::text = ?
                   AND (dc.user1_uuid::text = ? OR dc.user2_uuid::text = ?)",
                [$conversationUuid, $senderUuid, $senderUuid]
            );

            if (!$conversation) {
                return null;
            }

            // ENTERPRISE V10.19: Get both UUID and ID for recipient (needed for notifications)
            $isUser1Sender = $conversation['user1_uuid'] === $senderUuid;
            $recipientUuid = $isUser1Sender ? $conversation['user2_uuid'] : $conversation['user1_uuid'];
            $recipientId = $isUser1Sender ? (int) $conversation['user2_id'] : (int) $conversation['user1_id'];

            // ENTERPRISE V10.7: Auto-switch from DND when SENDER sends a message
            // If user A is "Occupato" and sends a message to anyone, A becomes "Online"
            // Rationale: If you're sending messages, you're clearly not busy anymore
            // This ensures recipients can reply without being blocked
            if ($this->presenceService->isInDndMode($senderUuid)) {
                $this->presenceService->setStatus($senderUuid, PresenceService::STATUS_ONLINE, [
                    'device' => 'web',
                    'reason' => 'auto_switch_on_send',
                ]);
                Logger::info('Auto-switched sender from DND to online on message send', [
                    'sender_uuid' => $senderUuid,
                    'conversation_uuid' => $conversationUuid,
                ]);
            }

            // ENTERPRISE v10.0: Fetch sender profile data for WebSocket payload
            // This ensures recipient sees sender's name and avatar in real-time
            $senderProfile = $db->findOne(
                "SELECT nickname, avatar_url FROM users WHERE uuid::text = ?",
                [$senderUuid]
            );
            $senderNickname = $senderProfile['nickname'] ?? 'Utente';
            // ENTERPRISE v10.1: Use get_avatar_url() to ensure proper path prefix
            $senderAvatar = get_avatar_url($senderProfile['avatar_url'] ?? null);

            if ($this->isBlockedByUuid($senderUuid, $recipientUuid)) {
                Logger::warning('Attempted to send DM to blocked user', [
                    'sender_uuid' => $senderUuid,
                    'recipient_uuid' => $recipientUuid,
                ]);
                return null;
            }

            $isUser1 = $conversation['user1_uuid'] === $senderUuid;
            $myStatus = $isUser1 ? $conversation['user1_status'] : $conversation['user2_status'];
            $theirStatus = $isUser1 ? $conversation['user2_status'] : $conversation['user1_status'];

            if ($myStatus === 'blocked' || $theirStatus === 'blocked') {
                return null;
            }

            // ENTERPRISE V10.145: DND Blocking System
            // If recipient is in DND mode:
            // - FIRST message: allowed, sender gets warning, recipient gets notification
            // - SUBSEQUENT messages: BLOCKED until recipient changes status
            $recipientInDnd = $this->presenceService->isInDndMode($recipientUuid);
            if ($recipientInDnd) {
                // Check if we've already sent a message to this DND user
                $alreadySentToDnd = $this->presenceService->hasDndNotificationBeenSent($recipientUuid, $senderUuid);

                if ($alreadySentToDnd) {
                    // BLOCK: Not the first message - reject it entirely
                    Logger::info('DND message blocked (not first)', [
                        'recipient_uuid' => $recipientUuid,
                        'sender_uuid' => $senderUuid,
                    ]);

                    // Return special response indicating DND block
                    return [
                        'blocked' => true,
                        'reason' => 'dnd',
                        'message' => 'L\'utente è ancora occupato. Riprova più tardi quando cambia stato.',
                    ];
                }

                // FIRST message to DND user - allow it but mark as sent
                $this->presenceService->markDndNotificationSent($recipientUuid, $senderUuid);

                // Send DND missed message notification to recipient
                $this->publishToUser($recipientUuid, 'dnd_missed_message', [
                    'sender_uuid' => $senderUuid,
                    'sender_nickname' => $senderNickname,
                    'sender_avatar' => $senderAvatar,
                    'conversation_uuid' => $conversationUuid,
                    'message' => 'Qualcuno ti ha cercato mentre eri occupato',
                    'timestamp' => time(),
                ]);

                Logger::info('DND first message allowed, notification sent', [
                    'recipient_uuid' => $recipientUuid,
                    'sender_uuid' => $senderUuid,
                    'conversation_uuid' => $conversationUuid,
                ]);

                // Continue to send the first message (don't return here)
            }

            $db->beginTransaction();

            $messageUuid = $this->generateUuid();
            $isEncrypted = !empty($encryptedData);

            $contentEncrypted = null;
            $contentIv = null;
            $contentTag = null;

            if ($isEncrypted) {
                // ENTERPRISE V11.6: Convert base64 to PostgreSQL bytea hex format
                // PDO with PARAM_STR doesn't handle binary data correctly for bytea columns
                // We decode base64 → binary → PostgreSQL hex escape format (\x...)
                $rawCiphertext = base64_decode($encryptedData['ciphertext'] ?? '', true);
                $rawIv = base64_decode($encryptedData['iv'] ?? '', true);
                $rawTag = base64_decode($encryptedData['tag'] ?? '', true);

                // Convert to PostgreSQL hex format for bytea columns
                $contentEncrypted = $rawCiphertext !== false ? '\\x' . bin2hex($rawCiphertext) : null;
                $contentIv = $rawIv !== false ? '\\x' . bin2hex($rawIv) : null;
                $contentTag = $rawTag !== false ? '\\x' . bin2hex($rawTag) : null;
            }

            // ENTERPRISE: Messages expire after 1 hour for privacy protection
            $expiresAt = date('Y-m-d H:i:s', time() + self::MESSAGE_TTL_SECONDS);

            // ENTERPRISE V10.100 (2025-12-09): TRUE E2E encryption metadata for audio
            // Server stores IV + TAG but CANNOT decrypt - only recipient can
            $audioIsEncrypted = !empty($extra['is_encrypted']);
            $audioEncryptionIv = $extra['encryption_iv'] ?? null;
            $audioEncryptionTag = $extra['encryption_tag'] ?? null;
            $audioEncryptionAlgorithm = $extra['encryption_algorithm'] ?? ($audioIsEncrypted ? 'AES-256-GCM' : null);

            // ENTERPRISE V11.6: Include file_size_bytes for audio messages
            $fileSizeBytes = isset($extra['file_size_bytes']) ? (int) $extra['file_size_bytes'] : null;

            $db->execute(
                "INSERT INTO direct_messages
                 (uuid, conversation_id, sender_id, sender_uuid, message_type,
                  content_encrypted, content_iv, content_tag,
                  file_url, file_size_bytes, duration_seconds, status, created_at, expires_at,
                  audio_is_encrypted, audio_encryption_iv, audio_encryption_tag, audio_encryption_algorithm)
                 VALUES (?::uuid, ?, ?, ?::uuid, ?::chat_message_type, ?::bytea, ?::bytea, ?::bytea, ?, ?, ?, 'sent', NOW(), ?::timestamptz, ?, ?, ?, ?)",
                [
                    $messageUuid,
                    $conversation['id'],
                    $senderId,
                    $senderUuid,
                    $messageType,
                    $contentEncrypted,
                    $contentIv,
                    $contentTag,
                    $extra['file_url'] ?? null,
                    $fileSizeBytes,
                    $extra['duration_seconds'] ?? null,
                    $expiresAt,
                    $audioIsEncrypted,
                    $audioEncryptionIv,
                    $audioEncryptionTag,
                    $audioEncryptionAlgorithm,
                ]
            );

            $preview = $content ? mb_substr($content, 0, 50) : '[Encrypted]';

            // ENTERPRISE V10.90: Removed unread_count increment from PHP
            // The PostgreSQL trigger dm_on_message_insert already handles this on INSERT
            // Having both caused DOUBLE INCREMENT of unread badges
            $db->execute(
                "UPDATE direct_conversations
                 SET last_message_at = NOW(),
                     last_message_preview = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$preview, $conversation['id']]
            );

            $db->commit();

            $message = [
                'uuid' => $messageUuid,
                'conversation_uuid' => $conversationUuid,
                'sender_uuid' => $senderUuid,
                'sender_nickname' => $senderNickname,
                'sender_avatar' => $senderAvatar,
                'message_type' => $messageType,
                'is_encrypted' => $isEncrypted,
                'content' => $content,
                'encrypted' => $isEncrypted ? $encryptedData : null,
                'file_url' => $extra['file_url'] ?? null,
                'duration_seconds' => $extra['duration_seconds'] ?? null,
                // ENTERPRISE V10.100 (2025-12-09): TRUE E2E encryption metadata for audio
                'audio_is_encrypted' => $extra['is_encrypted'] ?? false,
                'audio_encryption_iv' => $extra['encryption_iv'] ?? null,
                'audio_encryption_tag' => $extra['encryption_tag'] ?? null,
                'audio_encryption_algorithm' => $extra['encryption_algorithm'] ?? null,
                'status' => self::STATUS_SENT,
                'created_at' => time(),
                // ENTERPRISE: Message expiration timestamp (1 hour from now)
                'expires_at' => $expiresAt,
            ];

            // ENTERPRISE v10.0: WebSocket payload includes sender profile
            // This allows real-time display of sender's name and avatar
            // ENTERPRISE V3.1: Audio messages include signed URL for recipient playback
            $wsAudioUrl = null;
            $wsFileUrl = $extra['file_url'] ?? null;
            $wsDuration = $extra['duration_seconds'] ?? null;
            if ($messageType === 'audio' && !empty($wsFileUrl)) {
                try {
                    $audioService = new DMAudioService();
                    $wsAudioUrl = $audioService->getSignedUrl($wsFileUrl);
                } catch (\Exception $e) {
                    Logger::warning('Failed to generate AWS S3 signed URL for WS audio', [
                        'file_url' => $wsFileUrl,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ENTERPRISE V10.100 (2025-12-09): TRUE E2E encryption metadata for audio
            // Pass encryption IV, TAG and algorithm to recipient for client-side decryption
            $audioIsEncrypted = $extra['is_encrypted'] ?? false;
            $audioEncryptionIv = $extra['encryption_iv'] ?? null;
            $audioEncryptionTag = $extra['encryption_tag'] ?? null;
            $audioEncryptionAlgorithm = $extra['encryption_algorithm'] ?? null;

            $this->publishToUser($recipientUuid, 'dm_received', [
                'conversation_uuid' => $conversationUuid,
                'message' => [
                    'uuid' => $messageUuid,
                    'sender_uuid' => $senderUuid,
                    'sender_nickname' => $senderNickname,
                    'sender_avatar' => $senderAvatar,
                    'message_type' => $messageType,
                    'is_encrypted' => $isEncrypted,
                    'encrypted' => $isEncrypted ? $encryptedData : null,
                    'encrypted_content' => $isEncrypted ? $encryptedData['ciphertext'] : null,
                    'preview' => $preview,
                    'file_url' => $wsFileUrl,                       // S3 key for audio
                    'audio_url' => $wsAudioUrl,                     // Signed URL for playback
                    'duration_seconds' => $wsDuration,              // Audio duration
                    'audio_is_encrypted' => $audioIsEncrypted,      // TRUE E2E encrypted flag
                    'audio_encryption_iv' => $audioEncryptionIv,    // IV for decryption
                    'audio_encryption_tag' => $audioEncryptionTag,  // TAG for decryption (AES-GCM auth)
                    'audio_encryption_algorithm' => $audioEncryptionAlgorithm, // Algorithm (AES-256-GCM)
                    'created_at' => time(),
                    'expires_at' => $expiresAt,
                ],
            ]);

            // ENTERPRISE V10.19: Create bell notification for DM received
            // This ensures the notification badge updates in real-time on the navbar
            // NotificationService handles rate limiting (1 notification per 10 min per sender)
            // Skip notification if recipient is in DND mode (they already got dnd_missed_message)
            if (!$recipientInDnd) {
                try {
                    NotificationService::getInstance()->notifyDmReceived(
                        $recipientId,
                        $senderId,
                        $conversationUuid,
                        $preview
                    );
                } catch (\Throwable $e) {
                    // Non-critical: log and continue - the message was already sent
                    Logger::warning('Failed to create DM notification', [
                        'recipient_id' => $recipientId,
                        'sender_id' => $senderId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ENTERPRISE V12.2: Update sender's last_seen on message send
            // This fixes "online 13 min fa" showing stale timestamp when user
            // was idle but then sent a message - their activity wasn't tracked
            try {
                $this->presenceService->heartbeat($senderUuid);
            } catch (\Throwable $e) {
                // Non-critical: message was sent successfully
            }

            return $message;

        } catch (\Exception $e) {
            $db->rollback();
            Logger::error('Failed to send DM', [
                'conversation_uuid' => $conversationUuid,
                'sender_uuid' => $senderUuid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get messages from conversation
     * Optimized for partitioned table with created_at in WHERE
     */
    public function getMessages(
        string $conversationUuid,
        string $userUuid,
        int $limit = 50,
        ?string $beforeUuid = null
    ): array {
        $db = $this->db();
        $limit = min($limit, self::MAX_PAGE_SIZE);

        $conversation = $db->findOne(
            "SELECT dc.id
             FROM direct_conversations dc
             WHERE dc.uuid::text = ?
               AND (dc.user1_uuid::text = ? OR dc.user2_uuid::text = ?)",
            [$conversationUuid, $userUuid, $userUuid]
        );

        if (!$conversation) {
            return [];
        }

        $params = [$conversation['id']];
        $beforeClause = '';

        if ($beforeUuid) {
            $beforeMessage = $db->findOne(
                "SELECT dm.created_at FROM direct_messages dm WHERE dm.uuid::text = ?",
                [$beforeUuid]
            );
            if ($beforeMessage) {
                $beforeClause = "AND dm.created_at < ?";
                $params[] = $beforeMessage['created_at'];
            }
        }

        // ENTERPRISE: Filter expired messages (privacy feature)
        // Messages with expires_at in the past are not shown
        // CRITICAL: Cache DISABLED for DM messages because:
        // 1. Query uses NOW() - cached results would show expired messages
        // 2. Real-time chat requires instant visibility of new messages
        // 3. Messages expire (1hr TTL) - cache would serve stale data
        $messages = $db->query(
            "SELECT
                dm.uuid::text AS uuid,
                dm.sender_uuid::text AS sender_uuid,
                dm.message_type,
                dm.content_encrypted,
                dm.content_iv,
                dm.content_tag,
                dm.file_url,
                dm.duration_seconds,
                dm.status,
                dm.delivered_at,
                dm.read_at,
                dm.created_at,
                dm.expires_at,
                dm.audio_is_encrypted,
                dm.audio_encryption_iv,
                dm.audio_encryption_tag,
                dm.audio_encryption_algorithm,
                u.nickname AS sender_nickname,
                u.avatar_url AS sender_avatar
             FROM direct_messages dm
             INNER JOIN users u ON u.uuid::text = dm.sender_uuid::text
             WHERE dm.conversation_id = ?
               AND dm.deleted_at IS NULL
               AND (dm.expires_at IS NULL OR dm.expires_at > NOW())
               {$beforeClause}
             ORDER BY dm.created_at DESC
             LIMIT ?",
            array_merge($params, [$limit]),
            ['cache' => false]  // ENTERPRISE: No caching for real-time DM data
        );

        // ENTERPRISE V3.1: Initialize DMAudioService for audio messages (lazy, AWS S3)
        $audioService = null;

        return array_map(function ($msg) use (&$audioService) {
            // ENTERPRISE FIX: PostgreSQL BYTEA fields are now normalized by ResultNormalizer
            // in Database.php, but we keep fallback handling for safety
            $ciphertext = $msg['content_encrypted'] ?? null;
            $iv = $msg['content_iv'] ?? null;
            $tag = $msg['content_tag'] ?? null;

            // Handle resource streams from PostgreSQL BYTEA (fallback safety)
            if (is_resource($ciphertext)) {
                $ciphertext = stream_get_contents($ciphertext);
            }
            if (is_resource($iv)) {
                $iv = stream_get_contents($iv);
            }
            if (is_resource($tag)) {
                $tag = stream_get_contents($tag);
            }

            // ENTERPRISE FIX v9.9: Handle PostgreSQL hex-encoded BYTEA format
            // When bytea_output = 'hex', PostgreSQL returns '\x636961' format
            if (is_string($ciphertext) && str_starts_with($ciphertext, '\\x')) {
                $ciphertext = hex2bin(substr($ciphertext, 2));
            }
            if (is_string($iv) && str_starts_with($iv, '\\x')) {
                $iv = hex2bin(substr($iv, 2));
            }
            if (is_string($tag) && str_starts_with($tag, '\\x')) {
                $tag = hex2bin(substr($tag, 2));
            }

            // ENTERPRISE V3.1: Generate AWS S3 signed URL for audio messages
            $audioUrl = null;
            $fileUrl = $msg['file_url'] ?? null;
            if ($msg['message_type'] === 'audio' && !empty($fileUrl)) {
                try {
                    // Lazy instantiate DMAudioService (AWS S3)
                    if ($audioService === null) {
                        $audioService = new DMAudioService();
                    }
                    $audioUrl = $audioService->getSignedUrl($fileUrl);
                } catch (\Exception $e) {
                    Logger::warning('Failed to generate AWS S3 signed URL for DM audio', [
                        'file_url' => $fileUrl,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'uuid' => $msg['uuid'],
                'sender_uuid' => $msg['sender_uuid'],
                'sender_nickname' => $msg['sender_nickname'],
                // ENTERPRISE v10.1: Use get_avatar_url() to ensure proper path prefix
                'sender_avatar' => get_avatar_url($msg['sender_avatar'] ?? null),
                'message_type' => $msg['message_type'],
                'is_encrypted' => !empty($ciphertext),
                'encrypted' => $ciphertext ? [
                    'ciphertext' => base64_encode($ciphertext),
                    'iv' => base64_encode($iv ?? ''),
                    'tag' => base64_encode($tag ?? ''),
                ] : null,
                'file_url' => $fileUrl, // S3 key (for reference)
                'audio_url' => $audioUrl, // ENTERPRISE V3.1: Signed URL for playback
                'duration_seconds' => $msg['duration_seconds'],
                // ENTERPRISE V10.100 (2025-12-09): TRUE E2E encryption metadata for audio
                'audio_is_encrypted' => !empty($msg['audio_is_encrypted']),
                'audio_encryption_iv' => $msg['audio_encryption_iv'] ?? null,
                'audio_encryption_tag' => $msg['audio_encryption_tag'] ?? null,
                'audio_encryption_algorithm' => $msg['audio_encryption_algorithm'] ?? null,
                'status' => $msg['status'],
                'delivered_at' => $msg['delivered_at'],
                'read_at' => $msg['read_at'],
                'created_at' => $msg['created_at'],
                // ENTERPRISE: Message expiration timestamp (for UI countdown display)
                'expires_at' => $msg['expires_at'],
            ];
        }, $messages);
    }

    /**
     * Mark messages as read
     * Transaction-safe with WebSocket notification
     */
    public function markAsRead(string $conversationUuid, string $readerUuid): int
    {
        $db = $this->db();

        try {
            $conversation = $db->findOne(
                "SELECT
                    dc.id,
                    dc.user1_uuid::text AS user1_uuid,
                    dc.user2_uuid::text AS user2_uuid
                 FROM direct_conversations dc
                 WHERE dc.uuid::text = ?
                   AND (dc.user1_uuid::text = ? OR dc.user2_uuid::text = ?)",
                [$conversationUuid, $readerUuid, $readerUuid]
            );

            if (!$conversation) {
                return 0;
            }

            $db->beginTransaction();

            // ENTERPRISE V11.6: Set delivered_at if not already set (in case we skip 'delivered' status)
            $updated = $db->execute(
                "UPDATE direct_messages
                 SET status = 'read',
                     read_at = NOW(),
                     delivered_at = COALESCE(delivered_at, NOW())
                 WHERE conversation_id = ?
                   AND sender_uuid::text != ?
                   AND status != 'read'
                   AND deleted_at IS NULL",
                [$conversation['id'], $readerUuid]
            );

            $isUser1 = $conversation['user1_uuid'] === $readerUuid;
            $unreadColumn = $isUser1 ? 'user1_unread_count' : 'user2_unread_count';

            $db->execute(
                "UPDATE direct_conversations
                 SET {$unreadColumn} = 0, updated_at = NOW()
                 WHERE id = ?",
                [$conversation['id']]
            );

            $db->commit();

            $senderUuid = $isUser1 ? $conversation['user2_uuid'] : $conversation['user1_uuid'];

            // ENTERPRISE V10.40: Publish dm_read_receipt (matches ChatManager handler)
            // This is the single source of truth - WebSocket server should NOT re-publish
            $this->publishToUser($senderUuid, 'dm_read_receipt', [
                'conversation_uuid' => $conversationUuid,
                'reader_uuid' => $readerUuid,  // Consistent naming with other events
                'read_at' => time(),
            ]);

            return $updated;

        } catch (\Exception $e) {
            $db->rollback();
            Logger::error('Failed to mark messages as read', [
                'conversation_uuid' => $conversationUuid,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Block a user
     */
    public function blockUser(
        int $blockerId,
        string $blockerUuid,
        int $blockedId,
        string $blockedUuid,
        ?string $reason = null
    ): bool {
        $db = $this->db();

        try {
            $db->execute(
                "INSERT INTO user_blocks
                 (blocker_id, blocker_uuid, blocked_id, blocked_uuid, reason, created_at)
                 VALUES (?, ?::uuid, ?, ?::uuid, ?, NOW())
                 ON CONFLICT (blocker_id, blocked_id) DO NOTHING",
                [$blockerId, $blockerUuid, $blockedId, $blockedUuid, $reason]
            );

            $db->execute(
                "UPDATE direct_conversations
                 SET user1_status = CASE WHEN user1_uuid::text = ? THEN 'blocked' ELSE user1_status END,
                     user2_status = CASE WHEN user2_uuid::text = ? THEN 'blocked' ELSE user2_status END,
                     updated_at = NOW()
                 WHERE (user1_uuid::text = ? AND user2_uuid::text = ?)
                    OR (user1_uuid::text = ? AND user2_uuid::text = ?)",
                [$blockerUuid, $blockerUuid, $blockerUuid, $blockedUuid, $blockedUuid, $blockerUuid]
            );

            Logger::info('User blocked', [
                'blocker_uuid' => $blockerUuid,
                'blocked_uuid' => $blockedUuid,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('Failed to block user', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Unblock a user
     */
    public function unblockUser(int $blockerId, int $blockedId): bool
    {
        $db = $this->db();

        try {
            $db->execute(
                "DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?",
                [$blockerId, $blockedId]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if blocked (by user IDs)
     */
    private function isBlocked(int $userId1, int $userId2): bool
    {
        $db = $this->db();

        $block = $db->findOne(
            "SELECT ub.id
             FROM user_blocks ub
             WHERE (ub.blocker_id = ? AND ub.blocked_id = ?)
                OR (ub.blocker_id = ? AND ub.blocked_id = ?)",
            [$userId1, $userId2, $userId2, $userId1]
        );

        return $block !== null;
    }

    /**
     * Check if blocked (by UUIDs)
     */
    private function isBlockedByUuid(string $uuid1, string $uuid2): bool
    {
        $db = $this->db();

        $block = $db->findOne(
            "SELECT ub.id
             FROM user_blocks ub
             WHERE (ub.blocker_uuid::text = ? AND ub.blocked_uuid::text = ?)
                OR (ub.blocker_uuid::text = ? AND ub.blocked_uuid::text = ?)",
            [$uuid1, $uuid2, $uuid2, $uuid1]
        );

        return $block !== null;
    }

    /**
     * Format conversation for API output
     */
    private function formatConversation(array $conversation, ?string $forUserUuid = null): array
    {
        $isUser1 = $forUserUuid && $conversation['user1_uuid'] === $forUserUuid;

        return [
            'uuid' => $conversation['uuid'],
            'user1_uuid' => $conversation['user1_uuid'],
            'user2_uuid' => $conversation['user2_uuid'],
            'other_user_uuid' => $forUserUuid
                ? ($isUser1 ? $conversation['user2_uuid'] : $conversation['user1_uuid'])
                : null,
            'is_e2e_encrypted' => $conversation['is_e2e_encrypted'] ?? true,
            'key_exchange_complete' => $conversation['key_exchange_complete'] ?? false,
            'last_message_at' => $conversation['last_message_at'],
            'last_message_preview' => $conversation['last_message_preview'],
            'unread_count' => $conversation['unread_count'] ?? 0,
            'my_status' => $forUserUuid
                ? ($isUser1 ? $conversation['user1_status'] : $conversation['user2_status'])
                : null,
            'created_at' => $conversation['created_at'],
        ];
    }

    /**
     * Get basic user info
     * Cached for performance
     */
    private function getUserBasicInfo(string $userUuid): ?array
    {
        $db = $this->db();

        $user = $db->findOne(
            "SELECT
                u.uuid::text AS uuid,
                u.nickname,
                u.avatar_url
             FROM users u
             WHERE u.uuid::text = ?",
            [$userUuid],
            ['cache' => true, 'cache_ttl' => 'medium']
        );

        return $user ?: null;
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
     * ENTERPRISE V10.145: Get the other user's UUID from a conversation
     *
     * @param string $conversationUuid Conversation UUID
     * @param string $myUuid Current user's UUID
     * @return string|null Other user's UUID or null
     */
    public function getRecipientUuidFromConversation(string $conversationUuid, string $myUuid): ?string
    {
        $db = db();
        // ENTERPRISE V10.146: Fixed table name (was incorrectly dm_conversations)
        $conversation = $db->findOne(
            "SELECT user1_uuid::text, user2_uuid::text FROM direct_conversations WHERE uuid::text = ?",
            [$conversationUuid],
            ['cache' => true, 'cache_ttl' => 'medium']
        );

        if (!$conversation) {
            return null;
        }

        // Return the OTHER user's UUID
        if ($conversation['user1_uuid'] === $myUuid) {
            return $conversation['user2_uuid'];
        }
        return $conversation['user1_uuid'];
    }
}
