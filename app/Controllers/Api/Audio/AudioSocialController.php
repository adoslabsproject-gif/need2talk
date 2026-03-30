<?php

namespace Need2Talk\Controllers\Api\Audio;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Audio\Social\AudioInteractionService;
use Need2Talk\Services\Audio\Social\AudioModerationService;
use Need2Talk\Services\Cache\HiddenPostsOverlayService;
use Need2Talk\Services\Logger;

/**
 * Audio Social Controller - Enterprise Galaxy
 *
 * HTTP endpoints for social interactions
 * Comments, reports
 *
 * NOTE: Likes system replaced with Emotional Reactions (10 emotions)
 * See: AudioReactionController
 *
 * @package Need2Talk\Controllers\Api\Audio
 */
class AudioSocialController extends BaseController
{
    private AudioInteractionService $interactionService;
    private AudioModerationService $moderationService;

    public function __construct()
    {
        parent::__construct();
        $this->interactionService = new AudioInteractionService();
        $this->moderationService = new AudioModerationService();
    }

    // ==================== LIKES ====================
    // REMOVED: Likes system replaced with Emotional Reactions (10 emotions)
    // See: AudioReactionController, EmotionalReactionService, ReactionStatsService
    //
    // Old endpoints (DEPRECATED):
    // - POST   /api/audio/{id}/like
    // - DELETE /api/audio/{id}/like
    // - GET    /api/audio/{id}/likers
    //
    // New endpoints (Emotional Reactions):
    // - POST   /api/audio/reaction
    // - DELETE /api/audio/reaction/{audioPostId}
    // - GET    /api/audio/reactions/{audioPostId}

    // ==================== COMMENTS ====================

    /**
     * Get comments for audio
     *
     * GET /api/audio/{id}/comments
     *
     * @param int $id Audio ID
     * @return void JSON response
     */
    public function comments(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
            // ENTERPRISE: Default 10 comments per page for UX (load more pattern)
            $perPage = isset($_GET['per_page']) ? min(50, max(1, (int) $_GET['per_page'])) : 10;

            $result = $this->interactionService->getComments($id, $page, $perPage);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to get comments', [
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
     * Add comment to audio
     *
     * POST /api/audio/{id}/comments
     *
     * Body:
     * - comment_text: Comment text (max 1000 chars)
     * - parent_comment_id: Optional parent comment ID (for replies)
     * - mentioned_users: Optional array of user IDs
     *
     * @param int $id Audio ID
     * @return void JSON response
     */
    public function addComment(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            $userId = $this->getUserId();          // Still needed for service calls
            $userUuid = $this->getUserUuid();      // ENTERPRISE UUID: For future use

            // Get JSON body
            $body = json_decode(file_get_contents('php://input'), true);

            if (!isset($body['comment_text'])) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_text',
                    'message' => 'Testo del commento mancante',
                ], 400);

                return;
            }

            $commentText = $body['comment_text'];
            $parentCommentId = $body['parent_comment_id'] ?? null;
            $mentionedUsers = $body['mentioned_users'] ?? [];

            $result = $this->interactionService->addComment(
                $userId,
                $id,
                $commentText,
                $parentCommentId,
                $mentionedUsers
            );

            $statusCode = $result['success'] ? 201 : 400;
            $this->json($result, $statusCode);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to add comment', [
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
     * Edit comment
     *
     * PUT /api/audio/comments/{commentId}
     *
     * Body:
     * - comment_text: New comment text
     *
     * @param int $commentId Comment ID
     * @return void JSON response
     */
    public function editComment(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            $userId = $this->getUserId();          // Still needed for service calls
            $userUuid = $this->getUserUuid();      // ENTERPRISE UUID: For future use

            // Get JSON body
            $body = json_decode(file_get_contents('php://input'), true);

            if (!isset($body['comment_text'])) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_text',
                    'message' => 'Nuovo testo del commento mancante',
                ], 400);

                return;
            }

            $result = $this->interactionService->editComment($commentId, $userId, $body['comment_text']);

            $statusCode = $result['success'] ? 200 : 400;
            $this->json($result, $statusCode);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to edit comment', [
                'comment_id' => $commentId,
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
     * Delete comment
     *
     * DELETE /api/audio/comments/{commentId}
     *
     * @param int $commentId Comment ID
     * @return void JSON response
     */
    public function deleteComment(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            $userId = $this->getUserId();          // Still needed for service calls
            $userUuid = $this->getUserUuid();      // ENTERPRISE UUID: For future use
            $result = $this->interactionService->deleteComment($commentId, $userId);

            $statusCode = $result['success'] ? 200 : 400;
            $this->json($result, $statusCode);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to delete comment', [
                'comment_id' => $commentId,
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
     * Get replies to comment
     *
     * GET /api/audio/comments/{commentId}/replies
     *
     * @param int $commentId Comment ID
     * @return void JSON response
     */
    public function replies(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
            $perPage = isset($_GET['per_page']) ? min(50, max(1, (int) $_GET['per_page'])) : 10;

            $result = $this->interactionService->getReplies($commentId, $page, $perPage);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to get replies', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    // ==================== REPORTS ====================

    /**
     * Report audio post
     *
     * POST /api/audio/{id}/report
     *
     * Body:
     * - reason: Report reason (spam, harassment, etc.)
     * - description: Optional description
     *
     * @param int $id Audio ID
     * @return void JSON response
     */
    public function report(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);

                return;
            }

            $userId = $this->getUserId();
            $userUuid = $this->getUserUuid();      // ENTERPRISE UUID: For logging

            // Get JSON body
            $body = json_decode(file_get_contents('php://input'), true);

            if (!isset($body['reason'])) {
                $this->json([
                    'success' => false,
                    'error' => 'missing_reason',
                    'message' => 'Motivo della segnalazione mancante',
                ], 400);

                return;
            }

            $reason = $body['reason'];
            $description = $body['description'] ?? null;

            $result = $this->moderationService->reportAudio($userId, $id, $reason, $description);

            // ENTERPRISE GALAXY: Semantic HTTP status codes for different error types
            // This enables proper client-side error handling with appropriate UX
            if ($result['success']) {
                $statusCode = 201; // Created
            } else {
                $statusCode = match ($result['error'] ?? 'unknown') {
                    'already_reported' => 409,    // Conflict - resource already exists
                    'own_content'      => 403,    // Forbidden - cannot report own content
                    'invalid_reason'   => 400,    // Bad Request - invalid input
                    'not_found'        => 404,    // Not Found - audio doesn't exist
                    default            => 400,    // Bad Request - generic error
                };
            }
            $this->json($result, $statusCode);

        } catch (\Exception $e) {
            Logger::api('error', 'Failed to report audio', [
                'audio_id' => $id,
                'user_uuid' => $userUuid ?? null,  // ENTERPRISE: UUID in logs
                'user_id' => $userId ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }

    // ==================== HIDDEN POSTS ====================

    /**
     * Hide audio post from user's feed (ENTERPRISE GALAXY - OVERLAY CACHE)
     *
     * POST /api/audio/{id}/hide
     *
     * Uses HiddenPostsOverlayService for immediate visibility.
     * Post is hidden only for the current user, not deleted.
     *
     * @param int $id Audio post ID
     * @return void JSON response
     */
    public function hidePost(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);
                return;
            }

            $userId = $this->getUserId();

            // Verify post exists
            $db = db();
            $post = $db->findOne(
                "SELECT id FROM audio_posts WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );

            if (!$post) {
                $this->json([
                    'success' => false,
                    'error' => 'not_found',
                    'message' => 'Post non trovato',
                ], 404);
                return;
            }

            // ENTERPRISE OVERLAY: Immediate visibility
            $overlay = HiddenPostsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->hidePost($userId, $id);
            }

            // Persist to database (async via overlay dirty set)
            $db->query(
                "INSERT INTO hidden_posts (user_id, audio_post_id, hidden_at)
                 VALUES (?, ?, NOW())
                 ON CONFLICT (user_id, audio_post_id) DO NOTHING",
                [$userId, $id]
            );

            Logger::info('Post hidden', [
                'user_id' => $userId,
                'post_id' => $id,
            ]);

            $this->json([
                'success' => true,
                'message' => 'Post nascosto dal tuo feed',
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to hide post', [
                'post_id' => $id,
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
     * Unhide audio post from user's feed
     *
     * DELETE /api/audio/{id}/hide
     *
     * @param int $id Audio post ID
     * @return void JSON response
     */
    public function unhidePost(int $id): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Devi effettuare il login',
                ], 401);
                return;
            }

            $userId = $this->getUserId();

            // ENTERPRISE OVERLAY: Immediate visibility
            $overlay = HiddenPostsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->unhidePost($userId, $id);
            }

            // Remove from database
            $db = db();
            $db->query(
                "DELETE FROM hidden_posts WHERE user_id = ? AND audio_post_id = ?",
                [$userId, $id]
            );

            Logger::info('Post unhidden', [
                'user_id' => $userId,
                'post_id' => $id,
            ]);

            $this->json([
                'success' => true,
                'message' => 'Post ripristinato nel feed',
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to unhide post', [
                'post_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ], 500);
        }
    }
}
