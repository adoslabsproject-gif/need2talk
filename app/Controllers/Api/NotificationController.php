<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\NotificationService;

/**
 * Notification API Controller
 *
 * Endpoints:
 * - GET  /api/notifications - List notifications
 * - GET  /api/notifications/unread-count - Get unread count (for polling fallback)
 * - POST /api/notifications/:id/read - Mark as read
 * - POST /api/notifications/read-all - Mark all as read
 * - DELETE /api/notifications/:id - Delete notification
 *
 * @package Need2Talk\Controllers\Api
 */
class NotificationController extends BaseController
{
    private NotificationService $notificationService;

    public function __construct()
    {
        parent::__construct();
        $this->notificationService = NotificationService::getInstance();
    }

    /**
     * GET /api/notifications
     *
     * List notifications for current user
     *
     * Query params:
     * - limit: int (default 20, max 50)
     * - offset: int (default 0)
     * - unread_only: bool (default false)
     */
    public function index(): void
    {
        $user = $this->requireAuth();

        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $unreadOnly = filter_var($_GET['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $notifications = $this->notificationService->getForUser(
            $user['id'],
            $limit,
            $offset,
            $unreadOnly
        );

        $unreadCount = $this->notificationService->getUnreadCount($user['id']);

        $this->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
                'has_more' => count($notifications) === $limit,
            ],
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     *
     * Get unread count only (for polling fallback)
     * ENTERPRISE V7 FIX: No caching - always return fresh count
     */
    public function unreadCount(): void
    {
        $user = $this->requireAuth();

        $count = $this->notificationService->getUnreadCount($user['id']);

        $this->json([
            'success' => true,
            'data' => [
                'unread_count' => $count,
            ],
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * POST /api/notifications/:id/read
     *
     * Mark single notification as read
     */
    public function markAsRead(string $id): void
    {
        $user = $this->requireAuth();

        $notificationId = (int) $id;
        if ($notificationId <= 0) {
            $this->json(['success' => false, 'error' => 'Invalid notification ID'], 400);
            return;
        }

        $success = $this->notificationService->markAsRead($notificationId, $user['id']);

        $this->json([
            'success' => $success,
            'message' => $success ? 'Notification marked as read' : 'Failed to mark as read',
        ]);
    }

    /**
     * POST /api/notifications/read-all
     *
     * Mark all notifications as read
     */
    public function markAllAsRead(): void
    {
        $user = $this->requireAuth();

        $success = $this->notificationService->markAllAsRead($user['id']);

        $this->json([
            'success' => $success,
            'message' => $success ? 'All notifications marked as read' : 'Failed to mark all as read',
        ]);
    }

    /**
     * DELETE /api/notifications/:id
     *
     * Delete a notification
     */
    public function delete(string $id): void
    {
        $user = $this->requireAuth();

        $notificationId = (int) $id;
        if ($notificationId <= 0) {
            $this->json(['success' => false, 'error' => 'Invalid notification ID'], 400);
            return;
        }

        $success = $this->notificationService->delete($notificationId, $user['id']);

        $this->json([
            'success' => $success,
            'message' => $success ? 'Notification deleted' : 'Failed to delete notification',
        ]);
    }
}
