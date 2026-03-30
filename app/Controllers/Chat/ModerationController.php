<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Chat;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Chat\ChatServiceFactory;
use Need2Talk\Services\Chat\ChatModerationService;

/**
 * Chat Moderation API Controller
 *
 * Handles message reporting and admin moderation
 *
 * ENTERPRISE ARCHITECTURE:
 * - Uses ChatServiceFactory for proper Dependency Injection
 * - Services are created with context-aware Redis adapters
 * - PHP-FPM context: PhpFpmRedisAdapter (EnterpriseRedisManager wrapper)
 *
 * Endpoints:
 * - POST /api/chat/messages/{uuid}/report - Report a message
 * - GET  /api/chat/moderation/queue - Get moderation queue (admin)
 * - POST /api/chat/moderation/review - Review a report (admin)
 * - GET  /api/chat/moderation/keywords - List keywords (admin)
 * - POST /api/chat/moderation/keywords - Add keyword (admin)
 * - DELETE /api/chat/moderation/keywords/{id} - Delete keyword (admin)
 *
 * @package Need2Talk\Controllers\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @updated 2025-12-03 - Refactored to use ChatServiceFactory (Enterprise DI Pattern)
 */
class ModerationController extends BaseController
{
    private ChatModerationService $moderationService;

    public function __construct()
    {
        parent::__construct();

        // ENTERPRISE DI: Use ChatServiceFactory for proper dependency injection
        $this->moderationService = ChatServiceFactory::createModerationService();
    }

    /**
     * POST /api/chat/messages/{uuid}/report
     *
     * Report a message
     * Body: { report_type, report_reason? }
     */
    public function report(string $uuid): void
    {
        $user = $this->requireAuth();

        $input = $this->getJsonInput();

        $reportType = $input['report_type'] ?? '';
        $reportReason = trim($input['report_reason'] ?? '');

        // Validate report type
        $validTypes = ['harassment', 'spam', 'inappropriate', 'hate_speech', 'other'];
        if (!in_array($reportType, $validTypes, true)) {
            $this->json(['success' => false, 'error' => 'Invalid report type'], 400);
            return;
        }

        // Require reason for 'other' type
        if ($reportType === 'other' && empty($reportReason)) {
            $this->json(['success' => false, 'error' => 'Reason required for "other" report type'], 400);
            return;
        }

        $result = $this->moderationService->reportMessage($uuid, $user['uuid'], $reportType, $reportReason);

        if ($result['success']) {
            $this->json([
                'success' => true,
                'message' => 'Report submitted successfully',
                'report_id' => $result['report_id'] ?? null,
            ]);
        } else {
            $this->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to submit report',
            ], 400);
        }
    }

    /**
     * GET /api/chat/moderation/queue
     *
     * Get pending reports (admin only)
     * Query params:
     * - limit: int (default 20, max 100)
     * - offset: int (default 0)
     * - status: string (pending, reviewing, all)
     */
    public function queue(): void
    {
        $user = $this->requireAdminAuth();

        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $status = $_GET['status'] ?? 'pending';

        $reports = $this->moderationService->getModerationQueue($limit, $offset, $status);
        $pendingCount = $this->moderationService->getPendingReportCount();

        $this->json([
            'success' => true,
            'data' => [
                'reports' => $reports,
                'pending_count' => $pendingCount,
                'has_more' => count($reports) === $limit,
            ],
        ]);
    }

    /**
     * POST /api/chat/moderation/review
     *
     * Review a report (admin only)
     * Body: { report_id, action, release_escrow? }
     * Actions: dismiss, warn, mute_user, ban_user, delete_message
     */
    public function review(): void
    {
        $user = $this->requireAdminAuth();

        $input = $this->getJsonInput();

        $reportId = (int) ($input['report_id'] ?? 0);
        $action = $input['action'] ?? '';
        $releaseEscrow = (bool) ($input['release_escrow'] ?? false);
        $notes = trim($input['notes'] ?? '');

        if ($reportId <= 0) {
            $this->json(['success' => false, 'error' => 'Invalid report ID'], 400);
            return;
        }

        $validActions = ['dismiss', 'warn', 'mute_user', 'ban_user', 'delete_message', 'escalate'];
        if (!in_array($action, $validActions, true)) {
            $this->json(['success' => false, 'error' => 'Invalid action'], 400);
            return;
        }

        $result = $this->moderationService->reviewReport($reportId, $user['id'], $action, $notes, $releaseEscrow);

        if ($result['success']) {
            $this->json([
                'success' => true,
                'message' => 'Report reviewed successfully',
            ]);
        } else {
            $this->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to review report',
            ], 400);
        }
    }

    /**
     * GET /api/chat/moderation/keywords
     *
     * List blacklist keywords (admin only)
     */
    public function keywords(): void
    {
        $user = $this->requireAdminAuth();

        $keywords = $this->moderationService->getKeywords();

        $this->json([
            'success' => true,
            'data' => [
                'keywords' => $keywords,
            ],
        ]);
    }

    /**
     * POST /api/chat/moderation/keywords
     *
     * Add keyword to blacklist (admin only)
     * Body: { keyword, match_type?, severity?, category?, action_type? }
     */
    public function addKeyword(): void
    {
        $user = $this->requireAdminAuth();

        $input = $this->getJsonInput();

        $keyword = trim($input['keyword'] ?? '');
        $matchType = $input['match_type'] ?? 'contains';
        $severity = (int) ($input['severity'] ?? 2);
        $category = $input['category'] ?? 'offensive';
        $actionType = $input['action_type'] ?? 'block';

        if (empty($keyword)) {
            $this->json(['success' => false, 'error' => 'Keyword is required'], 400);
            return;
        }

        if (strlen($keyword) > 100) {
            $this->json(['success' => false, 'error' => 'Keyword too long (max 100 chars)'], 400);
            return;
        }

        $validMatchTypes = ['exact', 'contains', 'regex', 'fuzzy'];
        if (!in_array($matchType, $validMatchTypes, true)) {
            $this->json(['success' => false, 'error' => 'Invalid match type'], 400);
            return;
        }

        $validActionTypes = ['block', 'flag', 'shadow_hide', 'warn'];
        if (!in_array($actionType, $validActionTypes, true)) {
            $this->json(['success' => false, 'error' => 'Invalid action type'], 400);
            return;
        }

        $result = $this->moderationService->addKeyword($keyword, $matchType, $severity, $category, $actionType, $user['id']);

        if ($result['success']) {
            $this->json([
                'success' => true,
                'message' => 'Keyword added successfully',
                'keyword_id' => $result['keyword_id'] ?? null,
            ], 201);
        } else {
            $this->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to add keyword',
            ], 400);
        }
    }

    /**
     * DELETE /api/chat/moderation/keywords/{id}
     *
     * Delete keyword from blacklist (admin only)
     */
    public function deleteKeyword(string $id): void
    {
        $user = $this->requireAdminAuth();

        $keywordId = (int) $id;
        if ($keywordId <= 0) {
            $this->json(['success' => false, 'error' => 'Invalid keyword ID'], 400);
            return;
        }

        $result = $this->moderationService->deleteKeyword($keywordId);

        if ($result['success']) {
            $this->json([
                'success' => true,
                'message' => 'Keyword deleted successfully',
            ]);
        } else {
            $this->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete keyword',
            ], 400);
        }
    }

    /**
     * POST /api/chat/moderation/escrow/release
     *
     * Release escrow key for E2E message (admin only)
     * This allows decryption of a reported E2E message for moderation
     * Body: { message_uuid, reason }
     */
    public function releaseEscrow(): void
    {
        $user = $this->requireAdminAuth();

        $input = $this->getJsonInput();

        $messageUuid = $input['message_uuid'] ?? '';
        $reason = trim($input['reason'] ?? '');

        if (empty($messageUuid) || strlen($messageUuid) !== 36) {
            $this->json(['success' => false, 'error' => 'Invalid message UUID'], 400);
            return;
        }

        if (empty($reason)) {
            $this->json(['success' => false, 'error' => 'Reason is required for escrow release'], 400);
            return;
        }

        $result = $this->moderationService->releaseEscrowKey($messageUuid, $user['id'], $reason);

        if ($result['success']) {
            $this->json([
                'success' => true,
                'message' => 'Escrow key released',
                'escrow_key' => $result['escrow_key'] ?? null, // Encrypted conversation key
            ]);
        } else {
            $this->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to release escrow key',
            ], 400);
        }
    }

    /**
     * Require admin authentication
     */
    private function requireAdminAuth(): array
    {
        $user = $this->requireAuth();

        // Check if user is admin (assuming is_admin column exists)
        if (empty($user['is_admin'])) {
            $this->json(['success' => false, 'error' => 'Admin access required'], 403);
            exit;
        }

        return $user;
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
