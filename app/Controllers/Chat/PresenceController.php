<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Chat;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Chat\ChatServiceFactory;
use Need2Talk\Services\Chat\PresenceService;

/**
 * Presence API Controller
 *
 * Handles user online status and heartbeat
 *
 * ENTERPRISE ARCHITECTURE:
 * - Uses ChatServiceFactory for proper Dependency Injection
 * - Services are created with context-aware Redis adapters
 * - PHP-FPM context: PhpFpmRedisAdapter (EnterpriseRedisManager wrapper)
 * - Swoole context: SwooleCoroutineRedisAdapter (per-coroutine connections)
 *
 * Endpoints:
 * - POST /api/chat/presence/heartbeat - Send heartbeat
 * - POST /api/chat/presence/status - Update status
 * - GET  /api/chat/presence/{uuid} - Get user presence
 * - GET  /api/chat/presence/batch - Get multiple users' presence
 *
 * @package Need2Talk\Controllers\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @updated 2025-12-03 - Refactored to use ChatServiceFactory (Enterprise DI Pattern)
 */
class PresenceController extends BaseController
{
    private PresenceService $presenceService;

    public function __construct()
    {
        parent::__construct();

        // ENTERPRISE DI: Use ChatServiceFactory for proper dependency injection
        // This ensures services receive context-aware Redis adapters:
        // - PHP-FPM: PhpFpmRedisAdapter (wraps EnterpriseRedisManager)
        // - Swoole: SwooleCoroutineRedisAdapter (per-coroutine connections, no deadlock)
        $this->presenceService = ChatServiceFactory::createPresenceService();
    }

    /**
     * POST /api/chat/presence/heartbeat
     *
     * Send heartbeat to keep presence active
     * Body: { current_room_id?: string }
     */
    public function heartbeat(): void
    {
        $user = $this->requireAuth();

        $input = $this->getJsonInput();
        $currentRoomId = $input['current_room_id'] ?? null;

        $success = $this->presenceService->heartbeat($user['uuid'], $currentRoomId);

        $this->json([
            'success' => $success,
            'timestamp' => time(),
        ]);
    }

    /**
     * POST /api/chat/presence/status
     *
     * Update user status
     * Body: { status: 'online'|'away'|'dnd'|'invisible' }
     */
    public function updateStatus(): void
    {
        $user = $this->requireAuth();

        $input = $this->getJsonInput();
        $status = $input['status'] ?? PresenceService::STATUS_ONLINE;

        // Validate status
        $validStatuses = [
            PresenceService::STATUS_ONLINE,
            PresenceService::STATUS_AWAY,
            PresenceService::STATUS_DND,
            PresenceService::STATUS_INVISIBLE,
        ];

        if (!in_array($status, $validStatuses, true)) {
            $this->json(['success' => false, 'error' => 'Invalid status'], 400);
            return;
        }

        $success = $this->presenceService->setStatus($user['uuid'], $status);

        $this->json([
            'success' => $success,
            'status' => $status,
        ]);
    }

    /**
     * GET /api/chat/presence/{uuid}
     *
     * Get a user's presence status
     */
    public function show(string $uuid): void
    {
        $this->requireAuth();

        if (strlen($uuid) !== 36) {
            $this->json(['success' => false, 'error' => 'Invalid UUID'], 400);
            return;
        }

        $status = $this->presenceService->getStatus($uuid);
        $lastSeenFormatted = $this->presenceService->getLastSeenFormatted($uuid);

        $this->json([
            'success' => true,
            'data' => [
                'uuid' => $uuid,
                'status' => $status['status'],
                'is_online' => $status['is_online'],
                'last_seen' => $lastSeenFormatted,
                'last_seen_timestamp' => $status['last_seen'],
            ],
        ]);
    }

    /**
     * GET /api/chat/presence/batch
     *
     * Get presence for multiple users
     * Query params:
     * - uuids: comma-separated UUIDs (max 50)
     */
    public function batch(): void
    {
        $this->requireAuth();

        $uuidsParam = $_GET['uuids'] ?? '';
        $uuids = array_filter(explode(',', $uuidsParam));

        if (empty($uuids)) {
            $this->json(['success' => false, 'error' => 'UUIDs required'], 400);
            return;
        }

        // Limit to 50 UUIDs
        $uuids = array_slice($uuids, 0, 50);

        // Validate UUID format
        foreach ($uuids as $uuid) {
            if (strlen(trim($uuid)) !== 36) {
                $this->json(['success' => false, 'error' => 'Invalid UUID format'], 400);
                return;
            }
        }

        $statuses = $this->presenceService->getStatuses(array_map('trim', $uuids));

        // Add formatted last_seen
        $result = [];
        foreach ($statuses as $uuid => $status) {
            $result[$uuid] = [
                'status' => $status['status'],
                'is_online' => $status['is_online'],
                'last_seen' => $this->presenceService->getLastSeenFormatted($uuid),
                'last_seen_timestamp' => $status['last_seen'],
            ];
        }

        $this->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * POST /api/chat/presence/offline
     *
     * Explicitly mark user as offline (e.g., on page unload)
     */
    public function setOffline(): void
    {
        $user = $this->requireAuth();

        $success = $this->presenceService->setOffline($user['uuid']);

        $this->json([
            'success' => $success,
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
}
