<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Chat;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Chat\ChatServiceFactory;
use Need2Talk\Services\Chat\ChatRoomService;
use Need2Talk\Services\Chat\EmotionRoomService;
use Need2Talk\Services\Chat\PresenceService;

/**
 * Chat Room API Controller
 *
 * Handles emotion rooms and user-created rooms
 *
 * ENTERPRISE ARCHITECTURE:
 * - Uses ChatServiceFactory for proper Dependency Injection
 * - Services are created with context-aware Redis adapters
 * - PHP-FPM context: PhpFpmRedisAdapter (EnterpriseRedisManager wrapper)
 * - Swoole context: SwooleCoroutineRedisAdapter (per-coroutine connections)
 *
 * Endpoints:
 * - GET  /api/chat/rooms/emotions - List 10 emotion rooms with online counts
 * - GET  /api/chat/rooms - List user-created rooms
 * - POST /api/chat/rooms - Create new room
 * - GET  /api/chat/rooms/{uuid} - Get room details
 * - DELETE /api/chat/rooms/{uuid} - Delete room (creator only)
 * - POST /api/chat/rooms/{uuid}/join - Join room
 * - POST /api/chat/rooms/{uuid}/leave - Leave room
 * - GET  /api/chat/rooms/{uuid}/messages - Get messages
 * - GET  /api/chat/rooms/{uuid}/online - Get online users
 *
 * @package Need2Talk\Controllers\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @updated 2025-12-03 - Refactored to use ChatServiceFactory (Enterprise DI Pattern)
 */
class RoomController extends BaseController
{
    private EmotionRoomService $emotionService;
    private ChatRoomService $roomService;
    private PresenceService $presenceService;

    public function __construct()
    {
        parent::__construct();

        // ENTERPRISE DI: Use ChatServiceFactory for proper dependency injection
        // This ensures services receive context-aware Redis adapters:
        // - PHP-FPM: PhpFpmRedisAdapter (wraps EnterpriseRedisManager)
        // - Swoole: SwooleCoroutineRedisAdapter (per-coroutine connections, no deadlock)
        $this->emotionService = ChatServiceFactory::createEmotionRoomService();
        $this->roomService = ChatServiceFactory::createChatRoomService();
        $this->presenceService = ChatServiceFactory::createPresenceService();
    }

    // =========================================================================
    // SECURITY V10.90: ENTERPRISE AUTHORIZATION LAYER
    // =========================================================================
    // Centralized room authorization with:
    // - IDOR (Insecure Direct Object Reference) protection
    // - Security audit logging for forensics
    // - Consistent error responses (no information leakage)
    // - Emotion rooms bypass (public by design)
    // =========================================================================

    /**
     * ENTERPRISE SECURITY: Verify user has access to room
     *
     * Authorization flow:
     * 1. Emotion rooms (emotion:*) are PUBLIC - always allowed
     * 2. User-created rooms require membership verification
     * 3. Failed attempts logged for security monitoring & forensics
     *
     * @param string $roomUuid Room UUID (URL-decoded)
     * @param array $user Authenticated user data
     * @param string $action Action being attempted (for audit log)
     * @return bool True if authorized
     */
    private function authorizeRoomAccess(string $roomUuid, array $user, string $action): bool
    {
        // Emotion rooms are PUBLIC by design - no authorization needed
        if (strpos($roomUuid, 'emotion:') === 0) {
            return true;
        }

        // User-created/private rooms require membership
        if (!$this->roomService->isMember($roomUuid, $user['uuid'])) {
            // SECURITY AUDIT: Log unauthorized access attempt
            // This enables forensic analysis and abuse detection
            \Need2Talk\Services\Logger::security('warning', 'IDOR attempt: Unauthorized room access', [
                'action' => $action,
                'room_uuid' => $roomUuid,
                'user_uuid' => $user['uuid'],
                'user_id' => $user['id'],
                'ip' => get_server('REMOTE_ADDR'),
                'user_agent' => substr(get_server('HTTP_USER_AGENT', ''), 0, 200),
            ]);

            return false;
        }

        return true;
    }

    /**
     * ENTERPRISE SECURITY: Require room access or return 403 error
     *
     * @param string $roomUuid Room UUID (URL-decoded)
     * @param array $user Authenticated user data
     * @param string $action Action being attempted (for audit log)
     * @return bool True if authorized, false if 403 response was sent
     */
    private function requireRoomAccess(string $roomUuid, array $user, string $action): bool
    {
        if (!$this->authorizeRoomAccess($roomUuid, $user, $action)) {
            // Generic error message - don't reveal if room exists or not (IDOR protection)
            $this->json([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
            return false;
        }
        return true;
    }

    /**
     * GET /api/chat/rooms/emotions
     *
     * List all 10 emotion rooms with online counts
     * Public endpoint - no auth required
     */
    public function emotionRooms(): void
    {
        $rooms = $this->emotionService->getAllRooms();

        $this->json([
            'success' => true,
            'data' => [
                'rooms' => $rooms,
            ],
        ]);
    }

    /**
     * GET /api/chat/rooms
     *
     * List public user-created rooms (discovery endpoint)
     * Only returns rooms with online users (for discovery purposes)
     *
     * Query params:
     * - limit: int (default 20, max 50)
     * - offset: int (default 0)
     * - status: string (active, all)
     */
    public function index(): void
    {
        $user = $this->requireAuth();

        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $status = $_GET['status'] ?? 'active';

        // ENTERPRISE V10.52: Public discovery shows ALL active user rooms
        // includeEmpty = true ensures rooms appear even with 0 online users
        // This fixes the bug where only the creator could see their room
        // Cache disabled in listRooms() to ensure real-time room visibility
        $rooms = $this->roomService->listRooms($limit, $offset, $status, true);

        // ENTERPRISE V10.52: Prevent browser/CDN caching of room list
        // Room list must always be fresh to show newly created rooms to all users
        $this->json([
            'success' => true,
            'data' => [
                'rooms' => $rooms,
                'has_more' => count($rooms) === $limit,
            ],
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * GET /api/chat/rooms/mine
     *
     * List rooms the current user has created or joined
     * ENTERPRISE V9.3 (2025-12-06): Dedicated endpoint for user's own rooms
     *
     * Unlike index() which filters by online_count > 0 (for discovery),
     * this returns ALL rooms the user is a member of, regardless of online count.
     * This ensures newly created rooms appear immediately in the user's list.
     *
     * Query params:
     * - limit: int (default 20, max 50)
     */
    public function mine(): void
    {
        $user = $this->requireAuth();

        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));

        // getUserRooms returns rooms where user is a member (created or joined)
        // Does NOT filter by online_count, so newly created rooms appear immediately
        $rooms = $this->roomService->getUserRooms($user['uuid'], $limit);

        $this->json([
            'success' => true,
            'data' => [
                'rooms' => $rooms,
                'has_more' => count($rooms) === $limit,
            ],
        ]);
    }

    /**
     * POST /api/chat/rooms
     *
     * Create a new user room
     * Body: { name, description?, emotion_id, max_members?, is_private? }
     */
    public function create(): void
    {
        $user = $this->requireAuth();

        $input = $this->getJsonInput();

        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $emotionId = isset($input['emotion_id']) ? (int) $input['emotion_id'] : null;
        $maxMembers = (int) ($input['max_members'] ?? 50);
        $isPrivate = (bool) ($input['is_private'] ?? false);

        if (empty($name)) {
            $this->json(['success' => false, 'error' => 'Room name is required'], 400);
            return;
        }

        if (strlen($name) < 3 || strlen($name) > 100) {
            $this->json(['success' => false, 'error' => 'Room name must be 3-100 characters'], 400);
            return;
        }

        // Validate emotion_id (1-10)
        if ($emotionId === null || $emotionId < 1 || $emotionId > 10) {
            $this->json(['success' => false, 'error' => 'Seleziona un\'emozione valida (1-10)'], 400);
            return;
        }

        // ChatRoomService::createRoom expects (int creatorId, string creatorUuid, array data)
        $room = $this->roomService->createRoom((int) $user['id'], $user['uuid'], [
            'name' => $name,
            'description' => $description,
            'emotion_id' => $emotionId,
            'max_members' => min(100, max(2, $maxMembers)),
            'room_type' => $isPrivate ? 'private' : 'user_created',
        ]);

        if ($room) {
            $this->json([
                'success' => true,
                'data' => [
                    'room' => $room,
                ],
            ], 201);
        } else {
            $this->json([
                'success' => false,
                'error' => 'Failed to create room',
            ], 400);
        }
    }

    /**
     * GET /api/chat/rooms/{uuid}
     *
     * Get room details
     *
     * SECURITY V10.90: Private room protection
     * - Emotion rooms: Public (no auth needed), includes messages & online users
     * - User-created public rooms: Basic details visible to all authenticated users
     * - Private rooms (room_type = 'private'): Membership verification required
     */
    public function show(string $uuid): void
    {
        $user = $this->requireAuth();
        $uuid = urldecode($uuid);

        // Check if it's an emotion room (always public)
        if (strpos($uuid, 'emotion:') === 0) {
            $room = $this->emotionService->getRoom($uuid);
            if ($room) {
                $messages = $this->emotionService->getMessages($uuid);
                $onlineUsers = $this->emotionService->getOnlineUsers($uuid);

                $this->json([
                    'success' => true,
                    'data' => [
                        'room' => $room,
                        'messages' => $messages,
                        'online_users' => $onlineUsers,
                    ],
                ]);
                return;
            }
        }

        // User-created room
        $room = $this->roomService->getRoom($uuid);

        if (!$room) {
            $this->json(['success' => false, 'error' => 'Room not found'], 404);
            return;
        }

        // SECURITY V10.90: Private rooms require membership to view details
        // This prevents information leakage about private room existence and metadata
        $isPrivate = ($room['room_type'] ?? '') === 'private';
        if ($isPrivate && !$this->roomService->isMember($uuid, $user['uuid'])) {
            \Need2Talk\Services\Logger::security('warning', 'IDOR attempt: Private room details access', [
                'action' => 'room:show',
                'room_uuid' => $uuid,
                'user_uuid' => $user['uuid'],
                'user_id' => $user['id'],
                'ip' => get_server('REMOTE_ADDR'),
            ]);
            // Return 404 instead of 403 to avoid revealing room existence
            $this->json(['success' => false, 'error' => 'Room not found'], 404);
            return;
        }

        $this->json([
            'success' => true,
            'data' => [
                'room' => $room,
            ],
        ]);
    }

    /**
     * DELETE /api/chat/rooms/{uuid}
     *
     * Delete a room (creator only)
     */
    public function delete(string $uuid): void
    {
        $user = $this->requireAuth();

        $result = $this->roomService->deleteRoom($uuid, $user['uuid']);

        if ($result['success']) {
            $this->json([
                'success' => true,
                'message' => 'Room deleted successfully',
            ]);
        } else {
            $this->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete room',
            ], $result['code'] ?? 400);
        }
    }

    /**
     * POST /api/chat/rooms/{uuid}/join
     *
     * Join a room (via HTTP - WebSocket handles real-time)
     */
    public function join(string $uuid): void
    {
        $user = $this->requireAuth();

        // URL-decode the uuid (emotion%3Asadness → emotion:sadness)
        $uuid = urldecode($uuid);

        // Check if emotion room
        if (strpos($uuid, 'emotion:') === 0) {
            // EmotionRoomService now returns array like ChatRoomService
            $result = $this->emotionService->joinRoom($uuid, $user['uuid']);

            if ($result['success']) {
                $this->json([
                    'success' => true,
                    'data' => [
                        'room' => $result['room'] ?? null,
                        'online_count' => $result['online_count'] ?? 0,
                    ],
                ]);
            } else {
                $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to join emotion room',
                ], 400);
            }
        } else {
            // ChatRoomService expects (roomUuid, userUuid, userId) - userId optional
            $result = $this->roomService->joinRoom($uuid, $user['uuid'], (int) $user['id']);

            if ($result['success']) {
                $this->json([
                    'success' => true,
                    'data' => [
                        'room' => $result['room'] ?? null,
                        'online_count' => $result['online_count'] ?? 0,
                    ],
                ]);
            } else {
                $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to join room',
                ], 400);
            }
        }
    }

    /**
     * POST /api/chat/rooms/{uuid}/leave
     *
     * Leave a room
     */
    public function leave(string $uuid): void
    {
        $user = $this->requireAuth();

        // URL-decode the uuid (emotion%3Asadness → emotion:sadness)
        $uuid = urldecode($uuid);

        // Check if emotion room
        if (strpos($uuid, 'emotion:') === 0) {
            $this->emotionService->leaveRoom($uuid, $user['uuid']);
        } else {
            // ChatRoomService expects (roomUuid, userUuid, userId) - userId optional
            $this->roomService->leaveRoom($uuid, $user['uuid'], (int) $user['id']);
        }

        $this->json([
            'success' => true,
            'message' => 'Left room successfully',
        ]);
    }

    /**
     * GET /api/chat/rooms/{uuid}/messages
     *
     * Get room messages with authorization
     *
     * Query params:
     * - limit: int (default 50, max 100)
     * - before: string (message ID for pagination)
     *
     * SECURITY V10.90: Enterprise authorization layer
     * - Emotion rooms: Public (no auth needed)
     * - User-created rooms: Membership verification via requireRoomAccess()
     */
    public function messages(string $uuid): void
    {
        $user = $this->requireAuth();
        $uuid = urldecode($uuid);

        // SECURITY V10.90: Centralized authorization check
        if (!$this->requireRoomAccess($uuid, $user, 'messages:read')) {
            return; // 403 already sent by requireRoomAccess()
        }

        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
        $before = $_GET['before'] ?? null;

        // Route to appropriate service based on room type
        $messages = strpos($uuid, 'emotion:') === 0
            ? $this->emotionService->getMessages($uuid, $limit)
            : $this->roomService->getMessages($uuid, $limit, $before);

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
     * POST /api/chat/rooms/{uuid}/messages
     *
     * Send a message to a room (emotion or user-created)
     * Body: { content: string, type?: string }
     *
     * SECURITY V10.90: Enterprise authorization layer
     * - Emotion rooms: Public (no auth needed)
     * - User-created rooms: Membership verification via requireRoomAccess()
     */
    public function sendMessage(string $uuid): void
    {
        $user = $this->requireAuth();
        $uuid = urldecode($uuid);

        // SECURITY V10.90: Centralized authorization check
        if (!$this->requireRoomAccess($uuid, $user, 'messages:send')) {
            return; // 403 already sent by requireRoomAccess()
        }

        $input = $this->getJsonInput();
        $content = trim($input['content'] ?? '');
        $messageType = $input['type'] ?? 'text';

        // Input validation
        if (empty($content)) {
            $this->json(['success' => false, 'error' => 'Message content is required'], 400);
            return;
        }

        if (strlen($content) > 2000) {
            $this->json(['success' => false, 'error' => 'Message too long (max 2000 chars)'], 400);
            return;
        }

        // Get user nickname for display
        $nickname = $user['nickname'] ?? $user['username'] ?? 'Anonymous';

        // Send message via service (handles both emotion and user rooms)
        $message = $this->roomService->sendMessage(
            $uuid,
            $user['uuid'],
            $nickname,
            $content,
            $messageType
        );

        if ($message) {
            $this->json([
                'success' => true,
                'data' => [
                    'message' => $message,
                ],
            ], 201);
        } else {
            $this->json([
                'success' => false,
                'error' => 'Failed to send message. Content may be blocked by moderation.',
            ], 400);
        }
    }

    /**
     * GET /api/chat/rooms/{uuid}/online
     *
     * Get online users in room with full user data (nickname, avatar)
     *
     * ENTERPRISE V10.81: Returns resolved user data instead of just UUIDs
     *
     * SECURITY V10.90: Enterprise authorization layer
     * - Emotion rooms: Public (no auth needed)
     * - User-created rooms: Membership verification via requireRoomAccess()
     * - Prevents information leakage about who is in private rooms
     */
    public function onlineUsers(string $uuid): void
    {
        $user = $this->requireAuth();
        $uuid = urldecode($uuid);

        // SECURITY V10.90: Centralized authorization check
        // Prevents information leakage about who is in private rooms
        if (!$this->requireRoomAccess($uuid, $user, 'users:list')) {
            return; // 403 already sent by requireRoomAccess()
        }

        // Get online user UUIDs from Redis
        $userUuids = strpos($uuid, 'emotion:') === 0
            ? $this->emotionService->getOnlineUsers($uuid)
            : $this->roomService->getOnlineUsers($uuid);

        $count = count($userUuids);

        // ENTERPRISE V10.81: Resolve UUIDs to full user data
        $users = [];
        if (!empty($userUuids)) {
            // Limit to 20 users for performance
            $userUuids = array_slice($userUuids, 0, 20);

            // Build placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($userUuids), '?'));

            $db = \db();
            $usersData = $db->findMany(
                "SELECT uuid, nickname, avatar_url
                 FROM users
                 WHERE uuid IN ({$placeholders})
                 LIMIT 20",
                array_values($userUuids)
            );

            // Format for frontend
            // ENTERPRISE V10.141: Normalize avatar URL
            foreach ($usersData as $userData) {
                $users[] = [
                    'uuid' => $userData['uuid'],
                    'nickname' => $userData['nickname'] ?? 'Utente',
                    'avatar_url' => get_avatar_url($userData['avatar_url'] ?? null),
                ];
            }
        }

        $this->json([
            'success' => true,
            'data' => [
                'users' => $users,
                'count' => $count,
            ],
        ]);
    }

    /**
     * POST /api/chat/rooms/{uuid}/invite
     *
     * Invite a friend to join the room
     * Body: { friend_uuid: string }
     *
     * ENTERPRISE V5.5: Real-time room invitations
     * - Creates invite record in database
     * - Sends WebSocket notification to invitee
     * - Only works for user-created rooms (not emotion rooms)
     */
    public function invite(string $uuid): void
    {
        $user = $this->requireAuth();
        $uuid = urldecode($uuid);
        $db = db();

        $input = $this->getJsonInput();
        $friendUuid = $input['friend_uuid'] ?? null;

        if (!$friendUuid) {
            $this->json([
                'success' => false,
                'error' => 'friend_uuid richiesto',
            ], 400);
            return;
        }

        // Verify friendship exists
        $friend = $db->findOne(
            "SELECT u.uuid, u.nickname
             FROM users u
             INNER JOIN friendships f ON (
                 (f.user_uuid = ? AND f.friend_uuid = u.uuid) OR
                 (f.friend_uuid = ? AND f.user_uuid = u.uuid)
             )
             WHERE f.status = 'accepted' AND u.uuid = ?",
            [$user['uuid'], $user['uuid'], $friendUuid]
        );

        if (!$friend) {
            $this->json([
                'success' => false,
                'error' => 'Puoi invitare solo i tuoi amici',
            ], 403);
            return;
        }

        // ENTERPRISE V11.6: Cooldown - max 1 invite per user every 5 minutes
        // Key format: room_invite_cooldown:{sender}:{recipient}
        $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('cache');
        $cooldownKey = "room_invite_cooldown:{$user['uuid']}:{$friendUuid}";
        $cooldownSeconds = 300; // 5 minutes

        if ($redis && $redis->exists($cooldownKey)) {
            $ttl = $redis->ttl($cooldownKey);
            $minutesLeft = ceil($ttl / 60);
            $this->json([
                'success' => false,
                'error' => "Puoi inviare un altro invito a {$friend['nickname']} tra {$minutesLeft} minuti",
            ], 429);
            return;
        }

        // ENTERPRISE V5.6: Check if friend is online via PresenceService (Redis)
        $presenceService = \Need2Talk\Services\Chat\ChatServiceFactory::createPresenceService();
        $friendStatus = $presenceService->getStatus($friendUuid);
        $isOnline = $friendStatus && $friendStatus !== 'offline' && $friendStatus !== 'invisible';

        if (!$isOnline) {
            $this->json([
                'success' => false,
                'error' => ($friend['nickname'] ?? 'L\'utente') . ' è offline',
            ], 400);
            return;
        }

        // Handle emotion rooms (format: emotion:slug)
        $isEmotionRoom = strpos($uuid, 'emotion:') === 0;
        $roomName = '';
        $emotionEmoji = '💬';

        if ($isEmotionRoom) {
            // Get emotion info from slug (name_en is the slug)
            $emotionSlug = substr($uuid, 8); // Remove "emotion:" prefix
            $emotion = $db->findOne(
                "SELECT id, name_it, icon_emoji FROM emotions WHERE name_en = ?",
                [$emotionSlug],
                ['cache' => true, 'cache_ttl' => 'very_long']
            );

            if (!$emotion) {
                $this->json([
                    'success' => false,
                    'error' => 'Stanza emozionale non trovata',
                ], 404);
                return;
            }

            $roomName = $emotion['name_it'];
            $emotionEmoji = $emotion['icon_emoji'] ?? '💬';
        } else {
            // User-created room - verify access
            if (!$this->requireRoomAccess($uuid, $user, 'invite')) {
                return;
            }

            // Get room info
            $room = $db->findOne(
                "SELECT uuid, name, emotion_id FROM chat_rooms WHERE uuid = ?",
                [$uuid]
            );

            if (!$room) {
                $this->json([
                    'success' => false,
                    'error' => 'Stanza non trovata',
                ], 404);
                return;
            }

            $roomName = $room['name'];

            // Get emotion emoji if room has emotion_id
            if ($room['emotion_id']) {
                $emotion = $db->findOne(
                    "SELECT icon_emoji FROM emotions WHERE id = ?",
                    [$room['emotion_id']],
                    ['cache' => true, 'cache_ttl' => 'very_long']
                );
                $emotionEmoji = $emotion['icon_emoji'] ?? '💬';
            }
        }

        // ENTERPRISE V11.6: Create notification via NotificationService (shows in bell)
        // Get friend's user ID for notification
        $friendUser = $db->findOne(
            "SELECT id FROM users WHERE uuid = ?",
            [$friendUuid]
        );

        if ($friendUser) {
            $notificationService = \Need2Talk\Services\NotificationService::getInstance();
            $notificationService->create(
                $friendUser['id'],
                \Need2Talk\Services\NotificationService::TYPE_ROOM_INVITE,
                $user['id'],
                'chat_room',
                null,
                [
                    'room_uuid' => $uuid,
                    'room_name' => $roomName,
                    'room_emoji' => $emotionEmoji,
                    'is_emotion_room' => $isEmotionRoom,
                ],
                true // forceSync for real-time delivery
            );
        }

        // ENTERPRISE V11.6: Set cooldown after successful invite
        if ($redis) {
            $redis->setex($cooldownKey, $cooldownSeconds, '1');
        }

        $this->json([
            'success' => true,
            'message' => 'Invito inviato!',
        ]);
    }

    /**
     * GET /api/chat/rooms/invites
     *
     * Get pending invites for current user
     */
    public function getPendingInvites(): void
    {
        $user = $this->requireAuth();

        $db = db();
        $invites = $db->findMany(
            "SELECT
                i.room_uuid,
                i.inviter_uuid,
                i.created_at,
                r.name as room_name,
                r.emotion_id,
                u.nickname as inviter_name,
                u.avatar_url as inviter_avatar
             FROM chat_room_invites i
             JOIN chat_rooms r ON r.uuid = i.room_uuid
             JOIN users u ON u.uuid = i.inviter_uuid
             WHERE i.invitee_uuid = ? AND i.status = 'pending'
             ORDER BY i.created_at DESC
             LIMIT 20",
            [$user['uuid']]
        );

        // Enrich with emotion emoji
        foreach ($invites as &$invite) {
            $invite['room_emoji'] = '💬';
            if ($invite['emotion_id']) {
                $emotion = $db->findOne(
                    "SELECT icon_emoji FROM emotions WHERE id = ?",
                    [$invite['emotion_id']],
                    ['cache' => true, 'cache_ttl' => 'very_long']
                );
                $invite['room_emoji'] = $emotion['icon_emoji'] ?? '💬';
            }
            $invite['inviter_avatar'] = get_avatar_url($invite['inviter_avatar']);
            unset($invite['emotion_id']);
        }

        $this->json([
            'success' => true,
            'data' => [
                'invites' => $invites,
                'count' => count($invites),
            ],
        ]);
    }

    /**
     * POST /api/chat/rooms/invites/{room_uuid}/respond
     *
     * Accept or decline a room invite
     * Body: { action: 'accept' | 'decline' }
     */
    public function respondToInvite(string $roomUuid): void
    {
        $user = $this->requireAuth();
        $roomUuid = urldecode($roomUuid);

        $input = $this->getJsonInput();
        $action = $input['action'] ?? null;

        if (!in_array($action, ['accept', 'decline'])) {
            $this->json([
                'success' => false,
                'error' => 'action deve essere accept o decline',
            ], 400);
            return;
        }

        $db = db();

        // Find pending invite
        $invite = $db->findOne(
            "SELECT id FROM chat_room_invites
             WHERE room_uuid = ? AND invitee_uuid = ? AND status = 'pending'",
            [$roomUuid, $user['uuid']]
        );

        if (!$invite) {
            $this->json([
                'success' => false,
                'error' => 'Invito non trovato o già risposto',
            ], 404);
            return;
        }

        // Update invite status
        $newStatus = $action === 'accept' ? 'accepted' : 'declined';
        $db->execute(
            "UPDATE chat_room_invites SET status = ?, responded_at = NOW() WHERE id = ?",
            [$newStatus, $invite['id']]
        );

        if ($action === 'accept') {
            // Join the room automatically
            $result = $this->roomService->joinRoom($roomUuid, $user['uuid'], (int) $user['id']);

            $this->json([
                'success' => true,
                'message' => 'Sei entrato nella stanza!',
                'data' => [
                    'room_uuid' => $roomUuid,
                    'joined' => $result['success'] ?? false,
                ],
            ]);
        } else {
            $this->json([
                'success' => true,
                'message' => 'Invito rifiutato',
            ]);
        }
    }

    /**
     * Helper: Get JSON input from request body
     */
    protected function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }
}
