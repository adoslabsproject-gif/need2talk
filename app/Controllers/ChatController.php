<?php

declare(strict_types=1);

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Chat\ChatServiceFactory;
use Need2Talk\Services\Logger;

/**
 * Chat Controller (Web Pages)
 *
 * Handles rendering of chat HTML pages.
 * API endpoints are in Chat\RoomController, Chat\DMController, etc.
 *
 * ENTERPRISE ARCHITECTURE:
 * - Uses ChatServiceFactory for proper Dependency Injection
 * - Services are created with context-aware Redis adapters
 * - PHP-FPM context: PhpFpmRedisAdapter (EnterpriseRedisManager wrapper)
 *
 * @package Need2Talk
 * @since 2025-12-02
 * @updated 2025-12-03 - Refactored to use ChatServiceFactory (Enterprise DI Pattern)
 */
class ChatController extends BaseController
{
    /**
     * Main chat index page
     * Shows emotion rooms, user rooms list, and DM inbox
     */
    public function index(): void
    {
        try {
            // ENTERPRISE SECURITY: Anti-cache headers
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            // Require authentication
            $user = $this->requireAuth();

            // ENTERPRISE V10.3: Fetch user's current presence status for instant UI render
            // This eliminates the "flash" of wrong status on page load
            $presenceService = ChatServiceFactory::createPresenceService();
            $presence = $presenceService->getStatus($user['uuid']);
            $userStatus = $presence['status'] ?? 'online';

            // Render chat index with layout
            $this->view('chat.index', [
                'user' => $user,
                'userStatus' => $userStatus,
                'title' => 'Chat - need2talk',
                'description' => 'Chat con altri utenti in tempo reale',
                'pageCSS' => ['chat'],
                // NOTE: ChatEncryptionService loaded globally in app-post-login.php layout
                'pageJS' => [
                    'chat/ChatManager',
                    'chat/EmotionRoomSelector',
                    'chat/MessageList',
                    'chat/MessageInput',
                    'chat/TypingIndicator',
                    'chat/UserPresence',
                    'chat/ReadReceipts',
                    'chat/ReportModal',
                ],
            ], 'app-post-login');

        } catch (\Exception $e) {
            Logger::error('Failed to load chat index', [
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error_message'] = 'Sessione scaduta. Effettua nuovamente il login.';
            $this->redirect(url('/login'));
        }
    }

    /**
     * Single room view
     * Shows room messages, user list, and input
     */
    public function room(string $uuid): void
    {
        try {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            $user = $this->requireAuth();

            // Validate UUID format
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)) {
                http_response_code(400);
                $this->json(['error' => 'UUID non valido']);
                return;
            }

            $this->view('chat.room', [
                'user' => $user,
                'roomUuid' => $uuid,
                'title' => 'Stanza Chat - need2talk',
                'description' => 'Chat room in tempo reale',
                'pageCSS' => ['chat'],
                // NOTE: ChatEncryptionService loaded globally in app-post-login.php layout
                'pageJS' => [
                    'chat/ChatManager',
                    'chat/EmotionRoomSelector',
                    'chat/MessageList',
                    'chat/MessageInput',
                    'chat/TypingIndicator',
                    'chat/UserPresence',
                    'chat/ReadReceipts',
                    'chat/ReportModal',
                ],
            ], 'app-post-login');

        } catch (\Exception $e) {
            Logger::error('Failed to load chat room', ['error' => $e->getMessage()]);
            $this->redirect(url('/login'));
        }
    }

    /**
     * Emotion room view
     * Shows predefined emotion room (joy, sadness, anxiety, etc.)
     */
    public function emotionRoom(string $emotionId): void
    {
        try {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            $user = $this->requireAuth();

            // Valid emotion IDs (synchronized with database `emotions` table)
            $validEmotions = [
                'joy', 'wonder', 'love', 'gratitude', 'hope',
                'sadness', 'anger', 'anxiety', 'fear', 'loneliness'
            ];

            if (!in_array($emotionId, $validEmotions, true)) {
                http_response_code(404);
                include __DIR__ . '/../Views/errors/404.php';
                return;
            }

            // Emotion metadata (synchronized with database `emotions` table)
            $emotions = [
                'joy' => ['emoji' => '😊', 'name' => 'Gioia', 'color' => '#FFD700'],
                'wonder' => ['emoji' => '✨', 'name' => 'Meraviglia', 'color' => '#FF6B35'],
                'love' => ['emoji' => '❤️', 'name' => 'Amore', 'color' => '#FF1493'],
                'gratitude' => ['emoji' => '🙏', 'name' => 'Gratitudine', 'color' => '#32CD32'],
                'hope' => ['emoji' => '🌟', 'name' => 'Speranza', 'color' => '#87CEEB'],
                'sadness' => ['emoji' => '😢', 'name' => 'Tristezza', 'color' => '#4682B4'],
                'anger' => ['emoji' => '😠', 'name' => 'Rabbia', 'color' => '#DC143C'],
                'anxiety' => ['emoji' => '😰', 'name' => 'Ansia', 'color' => '#FF8C00'],
                'fear' => ['emoji' => '😨', 'name' => 'Paura', 'color' => '#8B008B'],
                'loneliness' => ['emoji' => '😔', 'name' => 'Solitudine', 'color' => '#696969'],
            ];

            $emotion = $emotions[$emotionId];

            $this->view('chat.room', [
                'user' => $user,
                'roomId' => 'emotion:' . $emotionId,
                'emotion' => $emotion,
                'emotionId' => $emotionId,
                'isEmotionRoom' => true,
                'title' => $emotion['name'] . ' - Chat need2talk',
                'description' => 'Stanza ' . $emotion['name'] . ' - Condividi i tuoi pensieri',
                'pageCSS' => ['chat'],
                // NOTE: ChatEncryptionService loaded globally in app-post-login.php layout
                'pageJS' => [
                    'chat/ChatManager',
                    'chat/EmotionRoomSelector',
                    'chat/MessageList',
                    'chat/MessageInput',
                    'chat/TypingIndicator',
                    'chat/UserPresence',
                    'chat/ReadReceipts',
                    'chat/ReportModal',
                ],
            ], 'app-post-login');

        } catch (\Exception $e) {
            Logger::error('Failed to load emotion room', ['error' => $e->getMessage()]);
            $this->redirect(url('/login'));
        }
    }

    /**
     * Direct message conversation view
     * Shows DM conversation with E2E encryption
     */
    public function dm(string $uuid): void
    {
        try {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            $user = $this->requireAuth();

            // Validate UUID format
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)) {
                http_response_code(400);
                $this->json(['error' => 'UUID non valido']);
                return;
            }

            // ENTERPRISE DI: Use ChatServiceFactory for proper dependency injection
            // This ensures services receive context-aware Redis adapters
            $dmService = ChatServiceFactory::createDirectMessageService();
            $presenceService = ChatServiceFactory::createPresenceService();

            $conversation = $dmService->getConversation($uuid, $user['uuid']);

            if (!$conversation) {
                // Conversation not found or user not authorized
                http_response_code(404);
                $_SESSION['error_message'] = 'Conversazione non trovata';
                $this->redirect(url('/chat'));
                return;
            }

            // Determine other user UUID
            $otherUserUuid = $conversation['user1_uuid'] === $user['uuid']
                ? $conversation['user2_uuid']
                : $conversation['user1_uuid'];

            // Load other user's profile from database
            // ENTERPRISE V10.170: Include ecdh_public_key to check E2E readiness
            $db = db();
            $otherUser = $db->findOne(
                "SELECT
                    u.uuid::text AS uuid,
                    u.nickname,
                    u.name,
                    u.avatar_url,
                    u.last_login_at,
                    u.ecdh_public_key
                 FROM users u
                 WHERE u.uuid::text = ?",
                [$otherUserUuid]
            );

            if (!$otherUser) {
                $otherUser = [
                    'uuid' => $otherUserUuid,
                    'nickname' => 'Utente',
                    'name' => 'Utente',
                    'avatar_url' => '/assets/img/default-avatar.png',
                    'last_login_at' => null,
                    'has_e2e_key' => false,
                ];
            } else {
                // ENTERPRISE v10.1: Transform avatar_url to full path
                $otherUser['avatar_url'] = get_avatar_url($otherUser['avatar_url'] ?? null);
                // ENTERPRISE V10.170: Check if other user has E2E key
                $otherUser['has_e2e_key'] = !empty($otherUser['ecdh_public_key']);
                unset($otherUser['ecdh_public_key']); // Don't expose key to frontend
            }

            // Get presence status from Redis (includes last_seen from presence heartbeat)
            $presence = $presenceService->getStatus($otherUserUuid);
            $otherUser['status'] = $presence['status'] ?? 'offline';
            $otherUser['is_online'] = $presence['is_online'] ?? false;
            $otherUser['last_seen'] = $presence['last_seen'] ?? $otherUser['last_login_at'];

            // Add other_user to conversation array for view
            $conversation['other_user'] = $otherUser;

            $this->view('chat.dm', [
                'user' => $user,
                'conversation' => $conversation,
                'conversationUuid' => $uuid,
                'title' => 'Messaggio Privato - need2talk',
                'description' => 'Conversazione privata con crittografia end-to-end',
                'pageCSS' => ['chat'],
                // NOTE: ChatEncryptionService loaded globally in app-post-login.php layout
                'pageJS' => [
                    'chat/ChatManager',
                    'chat/EmotionRoomSelector',
                    'chat/MessageList',
                    'chat/MessageInput',
                    'chat/TypingIndicator',
                    'chat/UserPresence',
                    'chat/ReadReceipts',
                    'chat/ReportModal',
                ],
            ], 'app-post-login');

        } catch (\Exception $e) {
            Logger::error('Failed to load DM', ['error' => $e->getMessage()]);
            $this->redirect(url('/login'));
        }
    }

    /**
     * Admin moderation dashboard
     * Shows pending reports, keyword filter management
     */
    public function moderation(): void
    {
        try {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            $user = $this->requireAuth();

            // Check if user is admin/moderator
            $userRole = $user['role'] ?? 'user';
            if (!in_array($userRole, ['admin', 'moderator'], true)) {
                http_response_code(403);
                include __DIR__ . '/../Views/errors/403.php';
                return;
            }

            $this->view('chat.admin.moderation', [
                'user' => $user,
                'title' => 'Moderazione Chat - Admin',
                'description' => 'Dashboard moderazione chat',
                'pageCSS' => ['chat'],
                'pageJS' => [
                    'chat/ChatManager',
                    'chat/ReportModal',
                ],
            ], 'app-post-login');

        } catch (\Exception $e) {
            Logger::error('Failed to load moderation', ['error' => $e->getMessage()]);
            $this->redirect(url('/login'));
        }
    }
}
