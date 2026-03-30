<?php

namespace Need2Talk\Controllers\Moderation;

use Need2Talk\Middleware\ModerationAuthMiddleware;
use Need2Talk\Services\Logger;
use Need2Talk\Services\Moderation\AudioReportService;
use Need2Talk\Services\Moderation\ContentCensorshipService;
use Need2Talk\Services\Moderation\ModerationSecurityService;
use Need2Talk\Services\Moderation\UserBanService;

/**
 * ModerationController - Enterprise Moderation Portal
 *
 * Main controller for the Moderation Portal.
 * Handles authentication, dashboard, live monitoring, bans, keywords, and reports.
 *
 * @package Need2Talk\Controllers\Moderation
 */
class ModerationController
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = ModerationSecurityService::generateModerationUrl();
    }

    /**
     * Get Redis connection for chat operations
     * @return \Redis|null Redis connection or null if unavailable
     */
    private function getRedis(): ?\Redis
    {
        try {
            return \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('chat');
        } catch (\Exception $e) {
            Logger::error('Failed to get Redis connection', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================================
    // AUTHENTICATION
    // =========================================================================

    /**
     * Show login page
     */
    public function showLogin(): void
    {
        // If already authenticated, redirect to dashboard
        $session = ModerationSecurityService::validateSession();
        if ($session) {
            header('Location: ' . $this->baseUrl . '/dashboard');
            exit;
        }

        $this->render('login', [
            'title' => 'Moderation Portal Login',
            'baseUrl' => $this->baseUrl,
        ]);
    }

    /**
     * Handle login POST
     */
    public function login(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $this->render('login', [
                'title' => 'Moderation Portal Login',
                'baseUrl' => $this->baseUrl,
                'error' => 'Email and password are required',
            ]);
            return;
        }

        $result = ModerationSecurityService::authenticateModerator($email, $password);

        if ($result['success'] && $result['requires_2fa']) {
            // Redirect to 2FA page
            header('Location: ' . $this->baseUrl . '/verify-2fa');
            exit;
        }

        // Login failed
        $this->render('login', [
            'title' => 'Moderation Portal Login',
            'baseUrl' => $this->baseUrl,
            'error' => $result['error'] ?? 'Login failed',
            'email' => $email,
        ]);
    }

    /**
     * Show 2FA verification page
     */
    public function show2FA(): void
    {
        if (!isset($_SESSION['mod_pending_auth'])) {
            header('Location: ' . $this->baseUrl . '/login');
            exit;
        }

        $this->render('verify-2fa', [
            'title' => 'Verify Your Identity',
            'baseUrl' => $this->baseUrl,
            'email' => $_SESSION['mod_pending_auth']['email'] ?? '',
        ]);
    }

    /**
     * Handle 2FA verification POST
     */
    public function verify2FA(): void
    {
        $code = trim($_POST['code'] ?? '');

        if (empty($code)) {
            $this->render('verify-2fa', [
                'title' => 'Verify Your Identity',
                'baseUrl' => $this->baseUrl,
                'error' => 'Verification code is required',
            ]);
            return;
        }

        $result = ModerationSecurityService::verify2FACode($code);

        if ($result['success']) {
            header('Location: ' . $result['redirect']);
            exit;
        }

        $this->render('verify-2fa', [
            'title' => 'Verify Your Identity',
            'baseUrl' => $this->baseUrl,
            'error' => $result['error'] ?? 'Verification failed',
        ]);
    }

    /**
     * Handle logout
     */
    public function logout(): void
    {
        ModerationSecurityService::logout();
        header('Location: ' . $this->baseUrl . '/login');
        exit;
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    /**
     * Main dashboard
     */
    public function dashboard(): void
    {
        $session = $this->requireAuth();

        // Get dashboard stats
        $banService = new UserBanService();
        $censorshipService = new ContentCensorshipService();

        $banCounts = $banService->getBanCountsByScope();
        $stats = [
            'banCounts' => $banCounts,
            'total_banned' => array_sum($banCounts),
            'keywords' => count($censorshipService->getAllKeywords()),
        ];

        // Get pending reports count (if moderator has permission)
        $pendingReports = 0;
        if (ModerationAuthMiddleware::can('view_reports')) {
            $pendingReports = $this->getPendingReportsCount();
        }

        // Get recent actions
        $recentActions = $this->getRecentActions(10);

        $this->renderWithLayout('dashboard', [
            'title' => 'Moderation Dashboard',
            'view' => 'dashboard',
            'session' => $session,
            'stats' => $stats,
            'pendingReports' => $pendingReports,
            'activeBans' => $stats['total_banned'],
            'onlineUsers' => $this->getOnlineUsersCount(),
            'activeRooms' => 10, // Fixed: 10 emotion rooms
            'recentActions' => $recentActions,
        ]);
    }

    // =========================================================================
    // LIVE MONITORING
    // =========================================================================

    /**
     * Live rooms monitoring page
     */
    public function liveRooms(): void
    {
        $session = $this->requireAuth('view_rooms');

        // Get list of all emotion rooms + active user rooms
        $rooms = $this->getActiveRooms();

        $this->renderWithLayout('live', [
            'title' => 'Live Chat Monitoring',
            'view' => 'live',
            'session' => $session,
            'rooms' => $rooms,
        ]);
    }

    /**
     * API: Heartbeat to keep session alive
     * Note: requireAuth() already calls validateSession() which updates last_activity_at
     */
    public function heartbeat(): void
    {
        $session = $this->requireAuth();

        $this->jsonResponse([
            'success' => true,
            'timestamp' => time(),
            'moderator_id' => $session['moderator_id'] ?? null,
        ]);
    }

    /**
     * API: Get all room online counts (emotion + user rooms)
     */
    public function getRoomCounts(): void
    {
        $this->requireAuth('view_rooms');

        try {
            $redis = $this->getRedis();
            if (!$redis) {
                $this->jsonResponse(['success' => false, 'error' => 'Redis unavailable'], 503);
                return;
            }

            $counts = [];
            // Synchronized with database `emotions` table
            $emotionRooms = ['joy', 'wonder', 'love', 'gratitude', 'hope', 'sadness', 'anger', 'anxiety', 'fear', 'loneliness'];
            $now = time();

            // 1. Emotion rooms
            foreach ($emotionRooms as $emotion) {
                $roomId = "emotion:{$emotion}";
                $onlineKey = "chat:room:{$roomId}:online";

                // Try online set first
                $onlineCount = $redis->sCard($onlineKey);

                // If no set, try presence hash
                if ($onlineCount === 0) {
                    $presenceKey = "chat:room:{$roomId}:presence";
                    $users = $redis->hGetAll($presenceKey);
                    if ($users) {
                        foreach ($users as $lastSeen) {
                            if ($now - (int) $lastSeen < 300) {
                                $onlineCount++;
                            }
                        }
                    }
                }

                $counts[$roomId] = $onlineCount;
            }

            // 2. User-created rooms (active ones from database)
            $userRoomCounts = [];
            try {
                $db = db();
                $activeUserRooms = $db->findMany(
                    "SELECT uuid FROM chat_rooms
                     WHERE status = 'active'
                     AND room_type IN ('user_created', 'private')
                     AND deleted_at IS NULL
                     ORDER BY last_activity_at DESC
                     LIMIT 50"
                );

                foreach ($activeUserRooms as $room) {
                    $roomUuid = $room['uuid'];
                    $onlineKey = "chat:room:private:{$roomUuid}:online";
                    $onlineCount = (int) $redis->sCard($onlineKey);
                    $userRoomCounts["private:{$roomUuid}"] = $onlineCount;
                }
            } catch (\Exception $e) {
                Logger::warning('Failed to get user room counts', ['error' => $e->getMessage()]);
            }

            $this->jsonResponse([
                'success' => true,
                'counts' => $counts,
                'user_room_counts' => $userRoomCounts,
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get room counts', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to load counts'], 500);
        }
    }

    /**
     * API: Get active user-created rooms
     *
     * Returns list of active user-created rooms with online counts for moderation monitoring.
     */
    public function getUserCreatedRooms(): void
    {
        $this->requireAuth('view_rooms');

        try {
            $db = db();
            $redis = $this->getRedis();

            // Get active user-created rooms from database
            $rooms = $db->findMany(
                "SELECT
                    cr.id,
                    cr.uuid,
                    cr.name,
                    cr.description,
                    cr.room_type,
                    cr.is_ephemeral AS is_private,
                    cr.member_count,
                    cr.created_at,
                    cr.last_activity_at,
                    u.nickname AS creator_nickname,
                    cr.creator_uuid
                 FROM chat_rooms cr
                 LEFT JOIN users u ON u.id = cr.creator_id
                 WHERE cr.status = 'active'
                 AND cr.room_type IN ('user_created', 'private')
                 AND cr.deleted_at IS NULL
                 ORDER BY cr.last_activity_at DESC
                 LIMIT 100"
            );

            // Enrich with Redis online counts
            $enrichedRooms = [];
            foreach ($rooms as $room) {
                $roomUuid = $room['uuid'];
                $onlineCount = 0;

                if ($redis) {
                    $onlineKey = "chat:room:private:{$roomUuid}:online";
                    $onlineCount = (int) $redis->sCard($onlineKey);
                }

                $enrichedRooms[] = [
                    'uuid' => $roomUuid,
                    'room_id' => "private:{$roomUuid}",
                    'name' => $room['name'],
                    'description' => $room['description'],
                    'type' => $room['room_type'],
                    'is_private' => (bool) $room['is_private'],
                    'member_count' => (int) $room['member_count'],
                    'online_count' => $onlineCount,
                    'creator' => $room['creator_nickname'] ?? 'Unknown',
                    'creator_uuid' => $room['creator_uuid'],
                    'created_at' => $room['created_at'],
                    'last_activity_at' => $room['last_activity_at'],
                ];
            }

            $this->jsonResponse([
                'success' => true,
                'rooms' => $enrichedRooms,
                'total' => count($enrichedRooms),
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get user-created rooms', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to load user rooms'], 500);
        }
    }

    /**
     * API: Get room messages
     *
     * ENTERPRISE FIX: EmotionRoomService uses ZSET (sorted set), not LIST.
     * Use zRange instead of lRange to read messages correctly.
     */
    public function getRoomMessages(string $roomUuid): void
    {
        $this->requireAuth('view_rooms');

        try {
            // URL decode the room ID (e.g., 'emotion%3Ajoy' -> 'emotion:joy')
            $roomId = urldecode($roomUuid);

            // Get messages from Redis (ZSET, same as EmotionRoomService)
            $redis = $this->getRedis();
            if (!$redis) {
                $this->jsonResponse(['success' => false, 'error' => 'Redis unavailable'], 503);
                return;
            }

            $messagesKey = "chat:room:{$roomId}:messages";

            // Use ZRANGE with WITHSCORES to get messages from sorted set
            // Get last 100 messages (negative indices for newest)
            // zRange returns in ASC order by score (timestamp), so oldest first → newest last
            // This is correct for chat: oldest at top, newest at bottom, scroll to bottom
            $rawMessages = $redis->zRange($messagesKey, -100, -1, true); // true = WITHSCORES

            $parsedMessages = [];
            if ($rawMessages) {
                // NO array_reverse! zRange already returns chronological order (oldest→newest)
                // ENTERPRISE V10.87: Include deleted messages for moderator view (with deleted flag)
                foreach ($rawMessages as $msgJson => $score) {
                    $decoded = json_decode($msgJson, true);
                    if ($decoded) {
                        $parsedMessages[] = $decoded;
                    }
                }
            }

            // Get online count
            $onlineKey = "chat:room:{$roomId}:online";
            $onlineCount = $redis->sCard($onlineKey);

            $this->jsonResponse([
                'success' => true,
                'messages' => $parsedMessages,
                'online_count' => $onlineCount,
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get room messages', ['error' => $e->getMessage(), 'room' => $roomUuid]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to load messages'], 500);
        }
    }

    /**
     * API: Get online users in a room
     *
     * ENTERPRISE FIX: Redis stores user UUIDs (not bigint IDs) in the online set.
     * Query users table by uuid column, not id column.
     */
    public function getOnlineUsers(string $roomUuid): void
    {
        $this->requireAuth('view_rooms');

        try {
            // URL decode the room ID
            $roomId = urldecode($roomUuid);

            $redis = $this->getRedis();
            if (!$redis) {
                $this->jsonResponse(['success' => false, 'error' => 'Redis unavailable'], 503);
                return;
            }

            // Get online user UUIDs from Redis
            $onlineKey = "chat:room:{$roomId}:online";
            $userUuids = $redis->sMembers($onlineKey);

            $onlineUsers = [];
            if ($userUuids && count($userUuids) > 0) {
                // Filter out any invalid UUIDs
                $validUuids = array_filter($userUuids, function ($uuid) {
                    return is_string($uuid) && preg_match('/^[a-f0-9-]{36}$/i', $uuid);
                });

                if (count($validUuids) > 0) {
                    // Get user nicknames from database by UUID (not ID)
                    $pdo = db_pdo();
                    $placeholders = implode(',', array_fill(0, count($validUuids), '?'));
                    $stmt = $pdo->prepare("SELECT uuid, nickname FROM users WHERE uuid IN ({$placeholders})");
                    $stmt->execute(array_values($validUuids));
                    $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    foreach ($users as $user) {
                        $onlineUsers[] = [
                            'user_uuid' => $user['uuid'],
                            'nickname' => $user['nickname'] ?? 'Anonimo',
                        ];
                    }
                }
            }

            $this->jsonResponse([
                'success' => true,
                'count' => count($onlineUsers),
                'users' => $onlineUsers,
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get online users', ['error' => $e->getMessage(), 'room' => $roomUuid]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to load online users'], 500);
        }
    }

    /**
     * API: Send a message to a room (Moderator participation)
     *
     * Allows moderators to participate in chat rooms using their Display Name.
     * Messages are marked as 'moderator' type for visual distinction.
     */
    public function sendMessage(string $roomUuid): void
    {
        $session = $this->requireAuth('view_rooms');

        $input = $this->getJsonInput();
        $content = trim($input['content'] ?? '');

        if (empty($content)) {
            $this->jsonResponse(['success' => false, 'error' => 'Message content required'], 400);
            return;
        }

        // Limit message length
        if (mb_strlen($content) > 500) {
            $this->jsonResponse(['success' => false, 'error' => 'Message too long (max 500 characters)'], 400);
            return;
        }

        try {
            // URL decode the room ID
            $roomId = urldecode($roomUuid);

            $redis = $this->getRedis();
            if (!$redis) {
                $this->jsonResponse(['success' => false, 'error' => 'Redis unavailable'], 503);
                return;
            }

            // Generate unique message ID
            $messageId = sprintf(
                'mod_%s_%s',
                $session['moderator_id'],
                substr(md5(uniqid((string) mt_rand(), true)), 0, 12)
            );

            $timestamp = microtime(true);

            // Create message with moderator badge
            $message = [
                'id' => $messageId,
                'uuid' => $messageId, // For compatibility with client
                'room_id' => $roomId,
                'sender_uuid' => 'moderator_' . $session['moderator_id'],
                'sender_nickname' => $session['display_name'] ?? $session['username'] ?? 'Moderatore',
                'nickname' => $session['display_name'] ?? $session['username'] ?? 'Moderatore', // Alias for client
                'content' => $content,
                'type' => 'text',
                'is_moderator' => true, // Flag for visual distinction
                'moderator_badge' => '🛡️', // Shield badge
                'created_at' => $timestamp,
                'created_at_formatted' => date('H:i', (int) $timestamp),
            ];

            $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE);
            $messagesKey = "chat:room:{$roomId}:messages";

            // Add to sorted set with timestamp as score (same as EmotionRoomService)
            $redis->zAdd($messagesKey, $timestamp, $messageJson);

            // Keep only last 100 messages (trim old ones)
            $redis->zRemRangeByRank($messagesKey, 0, -101);

            // Refresh TTL (1 hour)
            $redis->expire($messagesKey, 3600);

            // Publish to WebSocket for real-time delivery via WebSocketPublisher
            // Uses proper channel: websocket:events:room:{roomId} (subscribed by WebSocket server)
            \Need2Talk\Services\WebSocketPublisher::publishToRoom($roomId, 'room_message', [
                'room_id' => $roomId,
                'message' => $message,
            ]);

            // Log moderator action
            // ENTERPRISE FIX: messageId is not a UUID, store it in details instead
            ModerationSecurityService::logModerationAction(
                $session['moderator_id'],
                'send_message',
                null,
                null, // target_message_uuid - our message IDs are not UUIDs
                ['room_id' => $roomId, 'message_id' => $messageId, 'content_preview' => mb_substr($content, 0, 50)]
            );

            $this->jsonResponse([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to send moderator message', ['error' => $e->getMessage(), 'room' => $roomUuid]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to send message'], 500);
        }
    }

    /**
     * API: Delete a message
     *
     * ENTERPRISE: Messages are stored in ZSET (sorted set), not LIST.
     * We find the message by scanning the ZSET, remove it, and re-add with deleted flag.
     */
    public function deleteMessage(): void
    {
        $session = $this->requireAuth('delete_messages');

        $input = $this->getJsonInput();
        $roomUuid = $input['room_uuid'] ?? null;
        $messageUuid = $input['message_uuid'] ?? null;

        if (!$roomUuid || !$messageUuid) {
            $this->jsonResponse(['success' => false, 'error' => 'Room UUID and message UUID required'], 400);
            return;
        }

        try {
            $redis = $this->getRedis();
            if (!$redis) {
                $this->jsonResponse(['success' => false, 'error' => 'Redis unavailable'], 503);
                return;
            }

            // Messages are in ZSET (sorted set) with timestamp as score
            $messagesKey = "chat:room:{$roomUuid}:messages";

            // Get all messages with scores
            $messagesWithScores = $redis->zRange($messagesKey, 0, -1, true);

            if ($messagesWithScores) {
                foreach ($messagesWithScores as $msgJson => $score) {
                    $msg = json_decode($msgJson, true);
                    $msgId = $msg['uuid'] ?? $msg['id'] ?? null;

                    if ($msgId === $messageUuid) {
                        // Remove original message from ZSET
                        $redis->zRem($messagesKey, $msgJson);

                        // Mark as deleted and re-add with same score
                        $msg['deleted'] = true;
                        $msg['deleted_by'] = 'moderator';
                        $msg['deleted_at'] = date('c');
                        $redis->zAdd($messagesKey, $score, json_encode($msg, JSON_UNESCAPED_UNICODE));

                        // ENTERPRISE V10.84 (2025-12-07): Fix WebSocket room ID format
                        // Moderation uses "private:{uuid}" but chat users subscribe to "{uuid}"
                        // Extract raw UUID for WebSocket broadcast
                        $wsRoomId = str_starts_with($roomUuid, 'private:')
                            ? substr($roomUuid, 8)  // Strip 'private:' prefix
                            : $roomUuid;

                        // DEBUG: Log the broadcast attempt
                        Logger::info('Message deletion broadcast', [
                            'original_room_uuid' => $roomUuid,
                            'ws_room_id' => $wsRoomId,
                            'message_uuid' => $messageUuid,
                        ]);

                        // Publish delete event to WebSocket using raw UUID
                        $publishResult = \Need2Talk\Services\WebSocketPublisher::publishToRoom($wsRoomId, 'message_deleted', [
                            'room_id' => $wsRoomId,
                            'message_uuid' => $messageUuid,
                        ]);

                        Logger::info('WebSocket publish result', [
                            'result' => $publishResult,
                            'room' => $wsRoomId,
                        ]);

                        // Log action
                        ModerationSecurityService::logModerationAction(
                            $session['moderator_id'],
                            'delete_message',
                            $msg['user_id'] ?? null,
                            $messageUuid,
                            ['room_uuid' => $roomUuid]
                        );

                        $this->jsonResponse(['success' => true, 'message' => 'Message deleted']);
                        return;
                    }
                }
            }

            $this->jsonResponse(['success' => false, 'error' => 'Message not found'], 404);
        } catch (\Exception $e) {
            Logger::error('Failed to delete message', ['error' => $e->getMessage(), 'room' => $roomUuid]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to delete message'], 500);
        }
    }

    // =========================================================================
    // BAN MANAGEMENT
    // =========================================================================

    /**
     * Ban management page
     */
    public function banManagement(): void
    {
        $session = $this->requireAuth('ban_users');

        $banService = new UserBanService();
        $scope = $_GET['scope'] ?? null;
        $bannedUsers = $banService->getBannedUsers(100, 0, $scope);
        $banCounts = $banService->getBanCountsByScope();

        $this->renderWithLayout('bans', [
            'title' => 'Ban Management',
            'view' => 'bans',
            'session' => $session,
            'bannedUsers' => $bannedUsers,
            'banCounts' => $banCounts,
            'currentScope' => $scope ?? 'all',
        ]);
    }

    /**
     * API: Ban a user
     */
    public function banUser(): void
    {
        $session = $this->requireAuth('ban_users');

        $input = $this->getJsonInput();

        $userId = (int) ($input['user_id'] ?? 0);
        $scope = $input['scope'] ?? 'chat';
        $reason = trim($input['reason'] ?? '');
        $duration = isset($input['duration']) ? (int) $input['duration'] : null;

        if (!$userId || !$reason) {
            $this->jsonResponse(['success' => false, 'error' => 'User ID and reason are required'], 400);
            return;
        }

        $banService = new UserBanService();
        $result = $banService->banUser(
            $userId,
            $scope,
            $reason,
            $duration,
            $session['moderator_id'],
            null,
            [
                'internal_notes' => $input['internal_notes'] ?? null,
                'severity' => $input['severity'] ?? 'ban',
                'related_message_uuid' => $input['message_uuid'] ?? null,
                'related_room_uuid' => $input['room_uuid'] ?? null,
            ]
        );

        $this->jsonResponse($result, $result['success'] ? 200 : 400);
    }

    /**
     * API: Unban a user
     */
    public function unbanUser(): void
    {
        $session = $this->requireAuth('ban_users');

        $input = $this->getJsonInput();
        $banId = (int) ($input['ban_id'] ?? 0);
        $reason = trim($input['reason'] ?? 'Revoked by moderator');

        if (!$banId) {
            $this->jsonResponse(['success' => false, 'error' => 'Ban ID required'], 400);
            return;
        }

        $banService = new UserBanService();
        $result = $banService->revokeBan($banId, $reason, $session['moderator_id']);

        $this->jsonResponse($result, $result['success'] ? 200 : 400);
    }

    /**
     * API: Get banned users list
     */
    public function getBannedUsers(): void
    {
        $this->requireAuth('ban_users');

        $limit = min((int) ($_GET['limit'] ?? 100), 200);
        $offset = (int) ($_GET['offset'] ?? 0);
        $scope = $_GET['scope'] ?? null;

        $banService = new UserBanService();
        $users = $banService->getBannedUsers($limit, $offset, $scope);

        $this->jsonResponse(['success' => true, 'data' => $users]);
    }

    // =========================================================================
    // KEYWORD MANAGEMENT
    // =========================================================================

    /**
     * Keywords management page
     */
    public function keywords(): void
    {
        $session = $this->requireAuth('manage_keywords');

        $censorshipService = new ContentCensorshipService();
        $keywords = $censorshipService->getAllKeywords();

        $this->renderWithLayout('keywords', [
            'title' => 'Keyword Blacklist',
            'view' => 'keywords',
            'session' => $session,
            'keywords' => $keywords,
        ]);
    }

    /**
     * API: Add keyword
     */
    public function addKeyword(): void
    {
        $session = $this->requireAuth('manage_keywords');

        $input = $this->getJsonInput();

        $censorshipService = new ContentCensorshipService();
        $result = $censorshipService->addKeyword($input, $session['moderator_id']);

        $this->jsonResponse($result, $result['success'] ? 200 : 400);
    }

    /**
     * API: Delete keyword
     */
    public function deleteKeyword(int $id): void
    {
        $session = $this->requireAuth('manage_keywords');

        $censorshipService = new ContentCensorshipService();
        $censorshipService->deleteKeyword($id, $session['moderator_id']);

        $this->jsonResponse(['success' => true, 'message' => 'Keyword deleted']);
    }

    // =========================================================================
    // REPORTS (AUDIO POSTS - Enterprise)
    // =========================================================================

    /**
     * Reports queue page - Audio Post Reports
     */
    public function reports(): void
    {
        $session = $this->requireAuth('view_reports');

        $reportService = new AudioReportService();
        $status = $_GET['status'] ?? 'pending';

        // Build filters based on status
        $filters = [];
        if ($status !== 'all') {
            $filters['status'] = $status;
        }

        $result = $reportService->getPendingReports(100, 0, $filters);
        $reports = $result['reports'] ?? [];
        $totalReports = $result['total'] ?? 0;

        // ENTERPRISE GALAXY: Extract stats from nested response
        $statsResult = $reportService->getReportStats();
        $stats = $statsResult['stats'] ?? [];

        $this->renderWithLayout('reports', [
            'title' => 'Audio Post Reports',
            'view' => 'reports',
            'session' => $session,
            'reports' => $reports,
            'totalReports' => $totalReports,
            'stats' => $stats,
            'currentStatus' => $status,
        ]);
    }

    /**
     * API: Dismiss a report (no action taken)
     */
    public function dismissReport(): void
    {
        $session = $this->requireAuth('resolve_reports');

        $input = $this->getJsonInput();
        $reportId = (int) ($input['report_id'] ?? 0);
        $notes = $input['notes'] ?? '';

        if (!$reportId) {
            $this->jsonResponse(['success' => false, 'error' => 'Report ID required'], 400);
            return;
        }

        try {
            $reportService = new AudioReportService();
            $result = $reportService->reviewReport(
                $reportId,
                $session['moderator_id'],
                'dismissed',
                'no_action',
                $notes
            );

            if ($result['success']) {
                $this->jsonResponse(['success' => true, 'message' => 'Report dismissed']);
            } else {
                $this->jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Failed to dismiss'], 500);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to dismiss report', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to dismiss report'], 500);
        }
    }

    /**
     * API: Resolve a report with action
     */
    public function resolveReport(): void
    {
        $session = $this->requireAuth('resolve_reports');

        $input = $this->getJsonInput();
        $reportId = (int) ($input['report_id'] ?? 0);
        $action = $input['action'] ?? 'no_action';
        $notes = $input['notes'] ?? '';

        if (!$reportId) {
            $this->jsonResponse(['success' => false, 'error' => 'Report ID required'], 400);
            return;
        }

        try {
            $reportService = new AudioReportService();
            $result = $reportService->reviewReport(
                $reportId,
                $session['moderator_id'],
                'action_taken',
                $action,
                $notes
            );

            if ($result['success']) {
                $this->jsonResponse(['success' => true, 'message' => 'Report resolved with action: ' . $action]);
            } else {
                $this->jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Failed to resolve'], 500);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to resolve report', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to resolve report'], 500);
        }
    }

    /**
     * API: Escalate a report to admin
     */
    public function escalateReport(): void
    {
        $session = $this->requireAuth('escalate_reports');

        $input = $this->getJsonInput();
        $reportId = (int) ($input['report_id'] ?? 0);
        $reason = $input['reason'] ?? '';

        if (!$reportId) {
            $this->jsonResponse(['success' => false, 'error' => 'Report ID required'], 400);
            return;
        }

        try {
            $reportService = new AudioReportService();
            $result = $reportService->escalateReport($reportId, $session['moderator_id'], $reason);

            if ($result['success']) {
                $this->jsonResponse(['success' => true, 'message' => 'Report escalated to admin']);
            } else {
                $this->jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Failed to escalate'], 500);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to escalate report', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to escalate report'], 500);
        }
    }

    /**
     * API: Send warning email to user
     *
     * ENTERPRISE GALAXY: Accepts user_uuid (not user_id) for security
     * sendWarningEmail signature: (int $userId, string $warningType, int $moderatorId, array $context)
     */
    public function sendWarning(): void
    {
        $session = $this->requireAuth('resolve_reports');

        $input = $this->getJsonInput();
        $reportId = (int) ($input['report_id'] ?? 0);
        // ENTERPRISE: Accept user_uuid, resolve to user_id
        $userUuid = $input['user_uuid'] ?? $input['user_id'] ?? '';  // Fallback to user_id for BC
        $warningType = $input['warning_type'] ?? 'content_report';
        $customMessage = $input['custom_message'] ?? null;

        if (!$reportId || !$userUuid) {
            $this->jsonResponse(['success' => false, 'error' => 'Report ID and User UUID required'], 400);
            return;
        }

        try {
            // ENTERPRISE: Resolve UUID to user ID
            $userId = $this->resolveUserUuid((string) $userUuid);
            if (!$userId) {
                $this->jsonResponse(['success' => false, 'error' => 'User not found'], 404);
                return;
            }

            $reportService = new AudioReportService();

            // Build context with report info
            $context = [
                'report_id' => $reportId,
                'custom_message' => $customMessage,
            ];

            // Get audio info from report for context
            $pdo = db_pdo();
            $stmt = $pdo->prepare("
                SELECT af.id AS audio_id, af.title AS audio_title
                FROM audio_reports ar
                JOIN audio_files af ON af.id = ar.audio_file_id
                WHERE ar.id = :report_id
            ");
            $stmt->execute(['report_id' => $reportId]);
            $reportInfo = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($reportInfo) {
                $context['audio_id'] = $reportInfo['audio_id'];
                $context['audio_title'] = $reportInfo['audio_title'];
            }

            // Call with correct signature: (userId, warningType, moderatorId, context)
            $result = $reportService->sendWarningEmail(
                $userId,
                $warningType,
                $session['moderator_id'],
                $context
            );

            if ($result['success']) {
                // Also mark report as reviewed with warning action
                $reportService->reviewReport(
                    $reportId,
                    $session['moderator_id'],
                    'action_taken',
                    'warning_sent',
                    'Warning email sent: ' . $warningType
                );

                $this->jsonResponse(['success' => true, 'message' => 'Warning email sent successfully']);
            } else {
                $this->jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Failed to send warning'], 500);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to send warning', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to send warning email'], 500);
        }
    }

    /**
     * API: Hide audio content (soft delete)
     */
    public function hideContent(): void
    {
        $session = $this->requireAuth('resolve_reports');

        $input = $this->getJsonInput();
        $audioId = (int) ($input['audio_id'] ?? 0);
        $reason = $input['reason'] ?? '';
        $reportId = (int) ($input['report_id'] ?? 0);

        if (!$audioId) {
            $this->jsonResponse(['success' => false, 'error' => 'Audio ID required'], 400);
            return;
        }

        try {
            $reportService = new AudioReportService();
            $result = $reportService->hideAudioPost($audioId, $session['moderator_id'], $reason);

            if ($result['success']) {
                // If report_id provided, also mark report as resolved
                if ($reportId) {
                    $reportService->reviewReport(
                        $reportId,
                        $session['moderator_id'],
                        'action_taken',
                        'content_hidden',
                        'Content hidden: ' . $reason
                    );
                }
                $this->jsonResponse(['success' => true, 'message' => 'Content hidden successfully']);
            } else {
                $this->jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Failed to hide content'], 500);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to hide content', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to hide content'], 500);
        }
    }

    /**
     * API: Unhide audio content (restore)
     */
    public function unhideContent(): void
    {
        $session = $this->requireAuth('resolve_reports');

        $input = $this->getJsonInput();
        $audioId = (int) ($input['audio_id'] ?? 0);

        if (!$audioId) {
            $this->jsonResponse(['success' => false, 'error' => 'Audio ID required'], 400);
            return;
        }

        try {
            $reportService = new AudioReportService();
            $result = $reportService->unhideAudioPost($audioId, $session['moderator_id']);

            if ($result['success']) {
                $this->jsonResponse(['success' => true, 'message' => 'Content restored successfully']);
            } else {
                $this->jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Failed to restore content'], 500);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to unhide content', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to restore content'], 500);
        }
    }

    /**
     * API: Get user moderation history
     *
     * ENTERPRISE GALAXY: Accepts user UUID (not numeric ID) for security
     * UUID prevents ID enumeration attacks
     *
     * @param string $id User UUID from route parameter
     */
    public function getUserModerationHistory(string $id): void
    {
        $session = $this->requireAuth('view_reports');

        if (!$id) {
            $this->jsonResponse(['success' => false, 'error' => 'User UUID required'], 400);
            return;
        }

        try {
            // ENTERPRISE: Resolve UUID to user ID
            $userId = $this->resolveUserUuid($id);
            if (!$userId) {
                $this->jsonResponse(['success' => false, 'error' => 'User not found'], 404);
                return;
            }

            $reportService = new AudioReportService();
            $history = $reportService->getUserModerationHistory($userId);

            // Return in expected format for frontend renderUserHistory()
            $this->jsonResponse([
                'success' => true,
                'stats' => $history['stats'] ?? [],
                'warnings' => $history['warnings'] ?? [],
                'active_bans' => $history['active_bans'] ?? [],
                'recent_reports' => $history['recent_reports'] ?? [],
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get user history', ['error' => $e->getMessage(), 'user_uuid' => $id]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to get user history'], 500);
        }
    }

    /**
     * Resolve user UUID to numeric user ID
     *
     * ENTERPRISE: Supports both UUID format and numeric ID for backwards compatibility
     *
     * @param string $identifier User UUID or numeric ID
     * @return int|null User ID or null if not found
     */
    private function resolveUserUuid(string $identifier): ?int
    {
        // Fast path: numeric ID (backwards compatibility)
        if (ctype_digit($identifier)) {
            return (int) $identifier;
        }

        // UUID format validation (8-4-4-4-12 hex)
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            return null;
        }

        try {
            $db = db();
            $result = $db->findOne(
                "SELECT id FROM users WHERE uuid = :uuid",
                ['uuid' => $identifier],
                ['cache' => true, 'cache_ttl' => 'medium']
            );
            return $result ? (int) $result['id'] : null;
        } catch (\Exception $e) {
            Logger::error('Failed to resolve user UUID', ['uuid' => $identifier, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================================
    // TEAM MANAGEMENT
    // =========================================================================

    /**
     * Team management page - List moderators
     */
    public function team(): void
    {
        $session = $this->requireAuth();

        $moderators = $this->getModerators();

        $this->renderWithLayout('team', [
            'title' => 'Team Management',
            'view' => 'team',
            'session' => $session,
            'moderators' => $moderators,
        ]);
    }

    /**
     * API: Create a new moderator
     */
    public function createModerator(): void
    {
        $session = $this->requireAuth();

        $input = $this->getJsonInput();

        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $displayName = trim($input['display_name'] ?? '') ?: null;
        $password = $input['password'] ?? '';

        // Validation
        if (empty($username) || empty($email) || empty($password)) {
            $this->jsonResponse(['success' => false, 'error' => 'Username, email and password are required'], 400);
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid username format'], 400);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
            return;
        }

        try {
            $pdo = db_pdo();

            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM moderators WHERE username = :username OR email = :email");
            $stmt->execute(['username' => $username, 'email' => $email]);
            if ($stmt->fetch()) {
                $this->jsonResponse(['success' => false, 'error' => 'Username or email already exists'], 400);
                return;
            }

            // Hash password
            $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

            // Insert moderator
            $stmt = $pdo->prepare("
                INSERT INTO moderators (username, email, password_hash, display_name,
                    can_view_rooms, can_ban_users, can_manage_keywords, created_at)
                VALUES (:username, :email, :password_hash, :display_name,
                    :can_view_rooms, :can_ban_users, :can_manage_keywords, NOW())
                RETURNING id
            ");
            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'can_view_rooms' => $input['can_view_rooms'] ?? true,
                'can_ban_users' => $input['can_ban_users'] ?? true,
                'can_manage_keywords' => $input['can_manage_keywords'] ?? false,
            ]);

            $moderatorId = $stmt->fetchColumn();

            // Log action
            ModerationSecurityService::logModerationAction(
                $session['moderator_id'],
                'add_keyword', // Using existing enum - TODO: add 'create_moderator' enum
                null,
                null,
                ['created_moderator_id' => $moderatorId, 'username' => $username]
            );

            Logger::security('info', 'MODERATOR_CREATED', [
                'created_by' => $session['moderator_id'],
                'moderator_id' => $moderatorId,
                'username' => $username,
                'email' => $email,
            ]);

            $this->jsonResponse(['success' => true, 'moderator_id' => $moderatorId]);

        } catch (\Exception $e) {
            Logger::error('Failed to create moderator', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to create moderator'], 500);
        }
    }

    /**
     * API: Toggle moderator active status
     */
    public function toggleModeratorStatus(): void
    {
        $session = $this->requireAuth();

        $input = $this->getJsonInput();
        $moderatorId = (int) ($input['moderator_id'] ?? 0);
        $enable = (bool) ($input['enable'] ?? false);

        if (!$moderatorId) {
            $this->jsonResponse(['success' => false, 'error' => 'Moderator ID required'], 400);
            return;
        }

        // Prevent disabling yourself
        if ($moderatorId === $session['moderator_id'] && !$enable) {
            $this->jsonResponse(['success' => false, 'error' => 'Cannot disable your own account'], 400);
            return;
        }

        try {
            $pdo = db_pdo();
            $stmt = $pdo->prepare("
                UPDATE moderators
                SET is_active = :is_active,
                    updated_at = NOW(),
                    deactivated_at = CASE WHEN :is_active THEN NULL ELSE NOW() END
                WHERE id = :id
            ");
            $stmt->execute([
                'is_active' => $enable,
                'id' => $moderatorId,
            ]);

            Logger::security('info', $enable ? 'MODERATOR_ENABLED' : 'MODERATOR_DISABLED', [
                'changed_by' => $session['moderator_id'],
                'moderator_id' => $moderatorId,
            ]);

            $this->jsonResponse(['success' => true]);

        } catch (\Exception $e) {
            Logger::error('Failed to toggle moderator status', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to update status'], 500);
        }
    }

    /**
     * API: Send URL email to selected moderators
     */
    public function sendUrlEmail(): void
    {
        $session = $this->requireAuth();

        $input = $this->getJsonInput();
        $moderatorIds = $input['moderator_ids'] ?? [];

        if (empty($moderatorIds) || !is_array($moderatorIds)) {
            $this->jsonResponse(['success' => false, 'error' => 'Select at least one moderator'], 400);
            return;
        }

        try {
            $pdo = db_pdo();

            // Get moderators' emails
            $placeholders = implode(',', array_fill(0, count($moderatorIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id, email, display_name, username
                FROM moderators
                WHERE id IN ({$placeholders}) AND is_active = TRUE
            ");
            $stmt->execute($moderatorIds);
            $moderators = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($moderators)) {
                $this->jsonResponse(['success' => false, 'error' => 'No active moderators found'], 400);
                return;
            }

            // Generate current URL
            $portalUrl = ModerationSecurityService::generateModerationUrl(true);
            $expiresInMinutes = ModerationSecurityService::getUrlExpiresInMinutes();

            // Send emails
            $sentCount = 0;
            foreach ($moderators as $mod) {
                $subject = 'need2talk Moderation Portal - New Access URL';
                $body = $this->buildUrlEmailBody($mod, $portalUrl, $expiresInMinutes);

                // Use async email queue
                \Need2Talk\Services\Email\AsyncEmailQueue::enqueue(
                    $mod['email'],
                    $subject,
                    $body,
                    'moderation_url'
                );
                $sentCount++;
            }

            Logger::security('info', 'MODERATION_URL_SENT_VIA_EMAIL', [
                'sent_by' => $session['moderator_id'],
                'sent_count' => $sentCount,
                'moderator_ids' => $moderatorIds,
            ]);

            $this->jsonResponse(['success' => true, 'sent_count' => $sentCount]);

        } catch (\Exception $e) {
            Logger::error('Failed to send moderation URL email', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => 'Failed to send emails'], 500);
        }
    }

    /**
     * Build email body for moderation URL notification
     */
    private function buildUrlEmailBody(array $moderator, string $portalUrl, int $expiresInMinutes): string
    {
        $name = $moderator['display_name'] ?? $moderator['username'];

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Moderation Portal Access</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #0f0f0f; color: #ffffff; padding: 40px 20px;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #171717; border-radius: 12px; padding: 40px; border: 1px solid rgba(217, 70, 239, 0.2);">
        <h1 style="color: #d946ef; margin-bottom: 24px; font-size: 24px;">need2talk Moderation Portal</h1>

        <p style="color: #d1d5db; margin-bottom: 20px;">Hello {$name},</p>

        <p style="color: #d1d5db; margin-bottom: 20px;">
            You have been sent the current access URL for the need2talk Moderation Portal.
        </p>

        <div style="background-color: rgba(217, 70, 239, 0.1); border: 1px solid rgba(217, 70, 239, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 24px;">
            <p style="color: #f0abfc; margin: 0 0 8px 0; font-size: 14px;">Portal URL:</p>
            <a href="{$portalUrl}" style="color: #e879f9; font-family: monospace; word-break: break-all; font-size: 14px;">
                {$portalUrl}
            </a>
        </div>

        <p style="color: #f59e0b; font-size: 14px; margin-bottom: 20px;">
            ⚠️ This URL will expire in approximately <strong>{$expiresInMinutes} minutes</strong>.
            After expiration, you'll need a new URL.
        </p>

        <p style="color: #6b7280; font-size: 12px; margin-top: 32px;">
            This email was sent from the need2talk Moderation System.<br>
            If you did not expect this email, please contact your administrator.
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get all moderators
     */
    private function getModerators(): array
    {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->query("
                SELECT id, uuid, username, email, display_name, is_active, last_login_at,
                       can_view_rooms, can_ban_users, can_manage_keywords, created_at
                FROM moderators
                ORDER BY created_at DESC
            ");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            Logger::error('Failed to get moderators', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // =========================================================================
    // AUDIT LOG
    // =========================================================================

    /**
     * Action log page
     */
    public function actionLog(): void
    {
        $session = $this->requireAuth();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        $actionType = $_GET['action'] ?? null;

        $actions = $this->getRecentActions($perPage, $offset, $actionType);
        $totalActions = $this->countActions($actionType);

        $this->renderWithLayout('log', [
            'title' => 'Moderation Activity Log',
            'view' => 'log',
            'session' => $session,
            'actions' => $actions,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalActions,
                'total_pages' => ceil($totalActions / $perPage),
            ],
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Require authentication and optionally a specific permission
     */
    private function requireAuth(?string $permission = null): array
    {
        $middleware = new ModerationAuthMiddleware();
        $middleware->handle($permission);

        return ModerationAuthMiddleware::getSession();
    }

    /**
     * Render a view (standalone, no layout)
     */
    private function render(string $view, array $data = []): void
    {
        extract($data);
        include __DIR__ . '/../../Views/moderation/' . $view . '.php';
    }

    /**
     * Render a view with the layout
     */
    private function renderWithLayout(string $viewName, array $data = []): void
    {
        $data['view'] = $viewName;
        extract($data);
        include __DIR__ . '/../../Views/moderation/layout.php';
    }

    /**
     * Send JSON response
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $json = file_get_contents('php://input');
        return json_decode($json, true) ?? [];
    }

    /**
     * Get active rooms (emotion + user-created)
     */
    private function getActiveRooms(): array
    {
        // Emotion rooms (synchronized with database `emotions` table)
        $emotionRooms = [
            ['uuid' => 'emotion:joy', 'name' => 'Gioia', 'emoji' => '😊', 'type' => 'emotion', 'color' => 'gold'],
            ['uuid' => 'emotion:wonder', 'name' => 'Meraviglia', 'emoji' => '✨', 'type' => 'emotion', 'color' => 'orange'],
            ['uuid' => 'emotion:love', 'name' => 'Amore', 'emoji' => '❤️', 'type' => 'emotion', 'color' => 'pink'],
            ['uuid' => 'emotion:gratitude', 'name' => 'Gratitudine', 'emoji' => '🙏', 'type' => 'emotion', 'color' => 'green'],
            ['uuid' => 'emotion:hope', 'name' => 'Speranza', 'emoji' => '🌟', 'type' => 'emotion', 'color' => 'cyan'],
            ['uuid' => 'emotion:sadness', 'name' => 'Tristezza', 'emoji' => '😢', 'type' => 'emotion', 'color' => 'blue'],
            ['uuid' => 'emotion:anger', 'name' => 'Rabbia', 'emoji' => '😠', 'type' => 'emotion', 'color' => 'red'],
            ['uuid' => 'emotion:anxiety', 'name' => 'Ansia', 'emoji' => '😰', 'type' => 'emotion', 'color' => 'darkorange'],
            ['uuid' => 'emotion:fear', 'name' => 'Paura', 'emoji' => '😨', 'type' => 'emotion', 'color' => 'purple'],
            ['uuid' => 'emotion:loneliness', 'name' => 'Solitudine', 'emoji' => '😔', 'type' => 'emotion', 'color' => 'gray'],
        ];

        // Add online counts from Redis
        try {
            $redis = $this->getRedis();
            if ($redis) {
                foreach ($emotionRooms as &$room) {
                    $presenceKey = "chat:room:{$room['uuid']}:presence";
                    $users = $redis->hGetAll($presenceKey);
                    $now = time();
                    $online = 0;
                    if ($users) {
                        foreach ($users as $lastSeen) {
                            if ($now - (int) $lastSeen < 300) {
                                $online++;
                            }
                        }
                    }
                    $room['online_count'] = $online;
                }
            }
        } catch (\Exception $e) {
            // Continue without online counts
        }

        // TODO: Add user-created rooms from database
        // For now, return only emotion rooms

        return $emotionRooms;
    }

    /**
     * Get pending reports count
     */
    private function getPendingReportsCount(): int
    {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->query("SELECT COUNT(*) FROM chat_message_reports WHERE status = 'pending'");
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get pending reports
     */
    private function getPendingReports(): array
    {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->query("
                SELECT
                    r.*,
                    u.nickname AS reporter_nickname
                FROM chat_message_reports r
                LEFT JOIN users u ON u.id = r.reporter_id
                WHERE r.status = 'pending'
                ORDER BY r.created_at DESC
                LIMIT 100
            ");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get recent moderation actions
     */
    private function getRecentActions(int $limit = 100, int $offset = 0, ?string $actionType = null): array
    {
        try {
            $pdo = db_pdo();

            $where = '';
            if ($actionType) {
                $where = "WHERE mal.action_type = :action_type";
            }

            $stmt = $pdo->prepare("
                SELECT
                    mal.*,
                    m.username AS moderator_username,
                    u.nickname AS target_nickname
                FROM moderation_actions_log mal
                LEFT JOIN moderators m ON m.id = mal.moderator_id
                LEFT JOIN users u ON u.id = mal.target_user_id
                {$where}
                ORDER BY mal.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            if ($actionType) {
                $stmt->bindValue(':action_type', $actionType);
            }
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Count total actions
     */
    private function countActions(?string $actionType = null): int
    {
        try {
            $pdo = db_pdo();

            $where = '';
            if ($actionType) {
                $where = "WHERE action_type = :action_type";
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM moderation_actions_log {$where}");
            if ($actionType) {
                $stmt->bindValue(':action_type', $actionType);
            }
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get total online users count across all rooms
     */
    private function getOnlineUsersCount(): int
    {
        try {
            $redisManager = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $redis = $redisManager->getConnection('chat');

            if (!$redis) {
                return 0;
            }

            $totalOnline = 0;
            $now = time();

            // Check all emotion rooms (synchronized with database `emotions` table)
            $emotionRoomSlugs = ['joy', 'wonder', 'love', 'gratitude', 'hope', 'sadness', 'anger', 'anxiety', 'fear', 'loneliness'];
            foreach ($emotionRoomSlugs as $slug) {
                $presenceKey = "chat:room:emotion:{$slug}:presence";
                $users = $redis->hGetAll($presenceKey);
                if ($users) {
                    foreach ($users as $lastSeen) {
                        if ($now - (int) $lastSeen < 300) {
                            $totalOnline++;
                        }
                    }
                }
            }

            return $totalOnline;
        } catch (\Exception $e) {
            return 0;
        }
    }

}
