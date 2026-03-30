<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Audio\Social\CommentService;
use Need2Talk\Services\Logger;

/**
 * CommentController - ENTERPRISE GALAXY V4
 *
 * Manages comments on audio posts (1 level of replies)
 *
 * Performance Targets:
 * - Add comment: <50ms
 * - Get comments: <100ms (with overlay merge)
 * - Like/unlike: <30ms
 *
 * Endpoints:
 * - POST   /api/comments                    - Create comment
 * - GET    /api/comments/{post_id}          - Get post comments
 * - GET    /api/comments/{comment_id}/replies - Get replies
 * - PUT    /api/comments/{comment_id}       - Edit comment
 * - DELETE /api/comments/{comment_id}       - Delete comment
 * - POST   /api/comments/{comment_id}/like  - Like comment
 * - DELETE /api/comments/{comment_id}/like  - Unlike comment
 *
 * @package Need2Talk\Controllers\Api
 */
class CommentController extends BaseController
{
    private CommentService $commentService;

    public function __construct()
    {
        parent::__construct();
        $this->commentService = new CommentService();
    }

    /**
     * Create new comment
     *
     * POST /api/comments
     *
     * Request body:
     * {
     *   "post_id": 123,
     *   "text": "Great post!",
     *   "parent_comment_id": null  // Optional: for replies
     * }
     */
    public function create(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $userId = $this->getUserId();
            $userUuid = $this->getUserUuid();

            $input = $this->getJsonInput();
            $postId = $input['post_id'] ?? null;
            $text = $input['text'] ?? '';
            $parentCommentId = $input['parent_comment_id'] ?? null;

            if (!$postId) {
                $this->json([
                    'success' => false,
                    'error' => 'post_id richiesto',
                ], 400);
                return;
            }

            // ═══════════════════════════════════════════════════════════════
            // ENTERPRISE MODERATION: Apply content censorship
            // Replace prohibited words with *** before saving
            // ═══════════════════════════════════════════════════════════════
            if (!empty($text)) {
                try {
                    $censorshipService = new \Need2Talk\Services\Moderation\ContentCensorshipService();
                    $censorResult = $censorshipService->censorContent($text, 'comment');
                    $text = $censorResult['censored'];

                    if ($censorResult['was_censored']) {
                        Logger::info('Content censored in comment', [
                            'user_id' => $userId,
                            'post_id' => $postId,
                            'matched_keywords' => $censorResult['matched'],
                        ]);
                    }
                } catch (\Exception $e) {
                    // Don't block comment creation if censorship fails
                    Logger::warning('Content censorship service failed', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId,
                    ]);
                }
            }

            $result = $this->commentService->createComment(
                (int) $postId,
                $userId,
                $userUuid,
                $text,
                $parentCommentId ? (int) $parentCommentId : null
            );

            if (!$result['success']) {
                $this->json($result, 400);
                return;
            }

            $this->json($result, 201);

        } catch (\Exception $e) {
            Logger::error('CommentController::create failed', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }

    /**
     * Get comments for a post
     *
     * GET /api/comments/{post_id}?limit=20&offset=0
     */
    public function getPostComments(int $postId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $userId = $this->getUserId();
            // ENTERPRISE: Default 10 comments per page for UX (load more pattern)
            $limit = (int) ($_GET['limit'] ?? 10);
            $offset = (int) ($_GET['offset'] ?? 0);

            $result = $this->commentService->getPostComments($postId, $userId, $limit, $offset);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('CommentController::getPostComments failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }

    /**
     * Get replies for a comment
     *
     * GET /api/comments/{comment_id}/replies
     */
    public function getReplies(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $userId = $this->getUserId();
            $result = $this->commentService->getReplies($commentId, $userId);

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('CommentController::getReplies failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }

    /**
     * Edit comment
     *
     * PUT /api/comments/{comment_id}
     *
     * Request body:
     * {
     *   "text": "Updated comment text"
     * }
     */
    public function edit(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $userId = $this->getUserId();
            $input = $this->getJsonInput();
            $text = $input['text'] ?? '';

            // ═══════════════════════════════════════════════════════════════
            // ENTERPRISE MODERATION: Apply content censorship on edit
            // Replace prohibited words with *** before saving
            // ═══════════════════════════════════════════════════════════════
            if (!empty($text)) {
                try {
                    $censorshipService = new \Need2Talk\Services\Moderation\ContentCensorshipService();
                    $censorResult = $censorshipService->censorContent($text, 'comment');
                    $text = $censorResult['censored'];

                    if ($censorResult['was_censored']) {
                        Logger::info('Content censored in comment edit', [
                            'user_id' => $userId,
                            'comment_id' => $commentId,
                            'matched_keywords' => $censorResult['matched'],
                        ]);
                    }
                } catch (\Exception $e) {
                    // Don't block comment edit if censorship fails
                    Logger::warning('Content censorship service failed', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId,
                    ]);
                }
            }

            $result = $this->commentService->editComment($commentId, $userId, $text);

            if (!$result['success']) {
                $statusCode = $result['error'] === 'not_owner' ? 403 : 400;
                $this->json($result, $statusCode);
                return;
            }

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('CommentController::edit failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }

    /**
     * Delete comment
     *
     * DELETE /api/comments/{comment_id}
     */
    public function delete(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $userId = $this->getUserId();
            $result = $this->commentService->deleteComment($commentId, $userId);

            if (!$result['success']) {
                $statusCode = $result['error'] === 'not_owner' ? 403 : 400;
                $this->json($result, $statusCode);
                return;
            }

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('CommentController::delete failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }

    /**
     * Like a comment
     *
     * POST /api/comments/{comment_id}/like
     */
    public function like(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $userId = $this->getUserId();
            $result = $this->commentService->likeComment($commentId, $userId);

            if (!$result['success']) {
                $this->json($result, 400);
                return;
            }

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('CommentController::like failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }

    /**
     * Unlike a comment
     *
     * DELETE /api/comments/{comment_id}/like
     */
    public function unlike(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $userId = $this->getUserId();
            $result = $this->commentService->unlikeComment($commentId, $userId);

            if (!$result['success']) {
                $this->json($result, 400);
                return;
            }

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('CommentController::unlike failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }

    /**
     * Get comment count for a post (for feed display)
     *
     * GET /api/comments/{post_id}/count
     */
    public function getCount(int $postId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $count = $this->commentService->getCommentCount($postId);

            $this->json([
                'success' => true,
                'count' => $count,
            ]);

        } catch (\Exception $e) {
            Logger::error('CommentController::getCount failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }

    /**
     * Get edit history for a comment
     *
     * ENTERPRISE V4 (2025-11-28): Shows previous versions of edited comments
     * Anyone can view edit history (transparency principle)
     *
     * GET /api/comments/{comment_id}/history
     *
     * Response:
     * {
     *   "success": true,
     *   "comment_id": 123,
     *   "current_text": "Current version",
     *   "is_edited": true,
     *   "history": [
     *     { "text": "Previous version", "edited_at": "2025-11-28 10:30:00" }
     *   ]
     * }
     */
    public function getEditHistory(int $commentId): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);
                return;
            }

            $userId = $this->getUserId();
            $result = $this->commentService->getEditHistory($commentId, $userId);

            if (!$result['success']) {
                $this->json($result, 404);
                return;
            }

            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('CommentController::getEditHistory failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno',
            ], 500);
        }
    }
}
