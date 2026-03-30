<?php

declare(strict_types=1);

namespace Need2Talk\Services\Audio\Social;

use Need2Talk\Repositories\Audio\CommentRepository;
use Need2Talk\Repositories\Audio\AudioPostRepository;
use Need2Talk\Services\Logger;
use Need2Talk\Services\NotificationService;
use Need2Talk\Services\Cache\CommentOverlayService;
use Need2Talk\Services\Cache\WriteBehindBuffer;
use Need2Talk\Services\Cache\UserSettingsOverlayService;
use Need2Talk\Models\Friendship;

/**
 * Comment Service - Enterprise Galaxy V4
 *
 * Business logic for comment management on audio posts
 * Handles create, delete, like/unlike, edit operations
 *
 * ENTERPRISE ARCHITECTURE (2025-11-28):
 * - Overlay cache for likes/counts (Redis pipeline)
 * - Write-behind buffer for DB sync
 * - Optimistic UI updates (immediate feedback)
 * - 1-level replies only (no deep nesting)
 *
 * @package Need2Talk\Services\Audio\Social
 */
class CommentService
{
    private CommentRepository $repository;
    private AudioPostRepository $postRepository;
    private ?CommentOverlayService $overlayService = null;
    private ?WriteBehindBuffer $writeBehindBuffer = null;

    private const MAX_COMMENT_LENGTH = 500;
    private const MAX_REPLIES_PER_COMMENT = 50;
    private const MAX_COMMENTS_PER_PAGE = 20;

    public function __construct()
    {
        $this->repository = new CommentRepository();
        $this->postRepository = new AudioPostRepository();
        $this->overlayService = CommentOverlayService::getInstance();
        $this->writeBehindBuffer = WriteBehindBuffer::getInstance();
    }

    /**
     * Create new comment on a post
     *
     * @param int $postId Audio post ID
     * @param int $userId Author user ID
     * @param string $userUuid Author user UUID
     * @param string $text Comment text
     * @param int|null $parentCommentId Parent comment ID for replies
     * @return array Result with comment data or error
     */
    public function createComment(
        int $postId,
        int $userId,
        string $userUuid,
        string $text,
        ?int $parentCommentId = null
    ): array {
        try {
            // 1. Validate text length
            $text = trim($text);
            if (empty($text)) {
                return [
                    'success' => false,
                    'error' => 'empty_comment',
                    'message' => 'Il commento non può essere vuoto',
                ];
            }

            if (mb_strlen($text) > self::MAX_COMMENT_LENGTH) {
                return [
                    'success' => false,
                    'error' => 'comment_too_long',
                    'message' => 'Commento troppo lungo (max ' . self::MAX_COMMENT_LENGTH . ' caratteri)',
                ];
            }

            // 2. Verify post exists
            $post = $this->postRepository->findById($postId);
            if (!$post) {
                return [
                    'success' => false,
                    'error' => 'post_not_found',
                    'message' => 'Post non trovato',
                ];
            }

            // 3. If reply, verify parent comment exists and belongs to same post
            if ($parentCommentId) {
                $parentComment = $this->repository->findById($parentCommentId);
                if (!$parentComment) {
                    return [
                        'success' => false,
                        'error' => 'parent_comment_not_found',
                        'message' => 'Commento genitore non trovato',
                    ];
                }

                // Check parent belongs to same post
                if ((int)$parentComment['audio_post_id'] !== $postId) {
                    return [
                        'success' => false,
                        'error' => 'invalid_parent',
                        'message' => 'Commento genitore non valido',
                    ];
                }

                // ENTERPRISE: Only 1 level of replies allowed
                if ($parentComment['parent_comment_id'] !== null) {
                    return [
                        'success' => false,
                        'error' => 'nested_reply_not_allowed',
                        'message' => 'Non puoi rispondere a una risposta',
                    ];
                }
            }

            // 4. Extract and validate @mentions (friends only)
            $mentionedUsers = $this->extractAndValidateMentions($text, $userUuid);

            // 5. Create comment in DB
            // ENTERPRISE V4.11 (2025-11-30): Include audio_file_id for legacy query compatibility
            // The lightbox show() endpoint queries comments by audio_file_id, not audio_post_id
            $commentId = $this->repository->create([
                'audio_post_id' => $postId,
                'audio_file_id' => $post['audio_file_id'] ?? null, // CRITICAL: Required for lightbox comment display
                'user_id' => $userId,
                'user_uuid' => $userUuid,
                'parent_comment_id' => $parentCommentId,
                'comment_text' => $text,
                'mentioned_users' => !empty($mentionedUsers) ? $mentionedUsers : null,
            ]);

            if (!$commentId) {
                return [
                    'success' => false,
                    'error' => 'db_error',
                    'message' => 'Errore durante la creazione del commento',
                ];
            }

            // 6. ENTERPRISE V10.153: Buffer overlay updates for write-behind (PRIMARY)
            // If buffer fails (Redis unavailable), use direct DB update as FALLBACK
            $buffered = $this->writeBehindBuffer->bufferNewComment($postId, $commentId, $userId, $parentCommentId);

            // ENTERPRISE V11.5 (2025-12-11): Update reply_count overlay IMMEDIATELY for instant UI
            // Uses ABSOLUTE VALUE pattern like V11.3 for post comment_count
            // - Overlay is updated NOW for immediate UI display
            // - Buffer handles async DB sync later
            if ($parentCommentId !== null && $this->overlayService && $this->overlayService->isAvailable()) {
                $newReplyCount = $this->overlayService->incrementReplyAbsolute($parentCommentId);
                Logger::overlay('debug', 'V11.5 Reply created: overlay updated immediately', [
                    'comment_id' => $commentId,
                    'parent_comment_id' => $parentCommentId,
                    'new_reply_count' => $newReplyCount,
                ]);
            }

            if (!$buffered && $parentCommentId !== null) {
                // FALLBACK: Direct DB update when Redis buffer is unavailable
                // This ensures reply_count is ALWAYS updated, even without Redis
                Logger::warning('CommentService: FALLBACK - Direct reply_count update (buffer failed)', [
                    'comment_id' => $commentId,
                    'parent_comment_id' => $parentCommentId,
                    'post_id' => $postId,
                ]);

                try {
                    $db = db();
                    $db->execute(
                        "UPDATE audio_comments SET reply_count = reply_count + 1, updated_at = NOW() WHERE id = :parent_id",
                        ['parent_id' => $parentCommentId],
                        ['invalidate_cache' => ["comment:{$parentCommentId}"]]
                    );
                } catch (\Exception $fallbackError) {
                    Logger::error('CommentService: FALLBACK reply_count update FAILED', [
                        'parent_comment_id' => $parentCommentId,
                        'error' => $fallbackError->getMessage(),
                    ]);
                }
            }

            // 7. Fetch created comment with user data
            $comment = $this->repository->findById($commentId);

            // ENTERPRISE V4 (2025-11-28): Load avatar overlay for comment author
            // In case the user recently changed their avatar
            $avatarOverlays = [];
            $userSettingsOverlay = UserSettingsOverlayService::getInstance();
            if ($userSettingsOverlay->isAvailable()) {
                $avatarUrl = $userSettingsOverlay->getAvatarUrl($userId, null);
                if ($avatarUrl !== null) {
                    $avatarOverlays[$userId] = $avatarUrl;
                }
            }

            Logger::info('CommentService: Comment created', [
                'comment_id' => $commentId,
                'post_id' => $postId,
                'user_id' => $userId,
                'is_reply' => $parentCommentId !== null,
            ]);

            // 8. ENTERPRISE: Send notifications (WebSocket + DB)
            $this->sendCommentNotifications(
                $postId,
                $commentId,
                $userId,
                $text,
                $parentCommentId,
                $parentComment ?? null,
                $post,
                $mentionedUsers
            );

            return [
                'success' => true,
                'comment' => $this->formatComment($comment, $userId, $avatarOverlays),
            ];

        } catch (\Exception $e) {
            Logger::error('CommentService::createComment failed', [
                'post_id' => $postId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Errore durante la creazione del commento',
            ];
        }
    }

    /**
     * Delete comment
     *
     * @param int $commentId
     * @param int $userId Current user ID (for permission check)
     * @return array
     */
    public function deleteComment(int $commentId, int $userId): array
    {
        try {
            // 1. Get comment
            $comment = $this->repository->findById($commentId);
            if (!$comment) {
                return [
                    'success' => false,
                    'error' => 'comment_not_found',
                    'message' => 'Commento non trovato',
                ];
            }

            // 2. Check ownership
            if ((int)$comment['user_id'] !== $userId) {
                return [
                    'success' => false,
                    'error' => 'not_owner',
                    'message' => 'Non puoi eliminare questo commento',
                ];
            }

            // 3. ENTERPRISE V10.153: Buffer overlay update (tombstone + decrement counts)
            // If buffer fails (Redis unavailable), use direct DB update as FALLBACK
            $parentId = $comment['parent_comment_id'] ? (int)$comment['parent_comment_id'] : null;
            $buffered = $this->writeBehindBuffer->bufferDeleteComment(
                (int)$comment['audio_post_id'],
                $commentId,
                $parentId
            );

            if (!$buffered && $parentId !== null) {
                // FALLBACK: Direct DB update when Redis buffer is unavailable
                Logger::warning('CommentService: FALLBACK - Direct reply_count decrement (buffer failed)', [
                    'comment_id' => $commentId,
                    'parent_comment_id' => $parentId,
                    'post_id' => $comment['audio_post_id'],
                ]);

                try {
                    $db = db();
                    $db->execute(
                        "UPDATE audio_comments SET reply_count = GREATEST(0, reply_count - 1), updated_at = NOW() WHERE id = :parent_id",
                        ['parent_id' => $parentId],
                        ['invalidate_cache' => ["comment:{$parentId}"]]
                    );
                } catch (\Exception $fallbackError) {
                    Logger::error('CommentService: FALLBACK reply_count decrement FAILED', [
                        'parent_comment_id' => $parentId,
                        'error' => $fallbackError->getMessage(),
                    ]);
                }
            }

            // 4. Soft delete in DB
            $this->repository->delete($commentId, $parentId, (int)$comment['audio_post_id']);

            $postId = (int)$comment['audio_post_id'];

            // ENTERPRISE V11.6 (2025-12-11): Update comment_count overlay IMMEDIATELY for instant UI
            // BUG FIX: This was missing! Only reply_count was being updated, not post comment_count
            if ($this->overlayService && $this->overlayService->isAvailable()) {
                $newCommentCount = $this->overlayService->decrementCommentAbsolute($postId);
                Logger::overlay('debug', 'V11.6 Comment deleted: post comment_count updated immediately', [
                    'comment_id' => $commentId,
                    'post_id' => $postId,
                    'new_comment_count' => $newCommentCount,
                ]);
            }

            // ENTERPRISE V11.5 (2025-12-11): Update reply_count overlay IMMEDIATELY for instant UI
            // Called AFTER soft delete so COUNT(*) excludes the just-deleted record
            if ($parentId !== null && $this->overlayService && $this->overlayService->isAvailable()) {
                $newReplyCount = $this->overlayService->decrementReplyAbsolute($parentId);
                Logger::overlay('debug', 'V11.5 Reply deleted: overlay updated immediately', [
                    'comment_id' => $commentId,
                    'parent_comment_id' => $parentId,
                    'new_reply_count' => $newReplyCount,
                ]);
            }

            Logger::info('CommentService: Comment deleted', [
                'comment_id' => $commentId,
                'post_id' => $comment['audio_post_id'],
                'user_id' => $userId,
            ]);

            return [
                'success' => true,
                'message' => 'Commento eliminato',
            ];

        } catch (\Exception $e) {
            Logger::error('CommentService::deleteComment failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Errore durante l\'eliminazione',
            ];
        }
    }

    /**
     * Edit comment text
     *
     * ENTERPRISE V4 (2025-11-28):
     * - Uses OVERLAY PATCH instead of DB write + cache invalidation
     * - Saves edit history for audit trail via WriteBehind
     * - Returns is_edited flag for clock icon display
     * - Zero cache invalidation = no cache miss storms
     *
     * @param int $commentId
     * @param int $userId
     * @param string $newText
     * @return array
     */
    public function editComment(int $commentId, int $userId, string $newText): array
    {
        try {
            // 1. Validate text
            $newText = trim($newText);
            if (empty($newText)) {
                return [
                    'success' => false,
                    'error' => 'empty_comment',
                    'message' => 'Il commento non può essere vuoto',
                ];
            }

            if (mb_strlen($newText) > self::MAX_COMMENT_LENGTH) {
                return [
                    'success' => false,
                    'error' => 'comment_too_long',
                    'message' => 'Commento troppo lungo',
                ];
            }

            // 2. Get current comment for ownership check and old text
            $comment = $this->repository->findById($commentId);
            if (!$comment) {
                return [
                    'success' => false,
                    'error' => 'comment_not_found',
                    'message' => 'Commento non trovato',
                ];
            }

            // 3. Check ownership
            if ((int)$comment['user_id'] !== $userId) {
                return [
                    'success' => false,
                    'error' => 'not_owner',
                    'message' => 'Non puoi modificare questo commento',
                ];
            }

            // ENTERPRISE V6 (2025-11-30): Check edit count limit (MAX 3 edits per comment)
            // This prevents abuse and maintains comment integrity
            $editCount = $this->getEditCount($commentId);
            if ($editCount >= 3) {
                return [
                    'success' => false,
                    'error' => 'max_edits_reached',
                    'message' => 'Hai raggiunto il limite massimo di 3 modifiche per questo commento',
                ];
            }

            $oldText = $comment['comment_text'];

            // ENTERPRISE V6: Save edit history BEFORE overwriting (for clock icon popup)
            // Only save if text actually changed
            if ($oldText !== $newText) {
                $this->saveEditHistory($commentId, $userId, $oldText);
            }

            // 4. ENTERPRISE V4: Write to OVERLAY instead of DB (same as post patches)
            // This avoids cache invalidation entirely!
            $this->overlayService->setPatch($commentId, $newText, true);

            // 5. Buffer the edit for WriteBehind DB sync
            $this->writeBehindBuffer->bufferCommentEdit($commentId, $userId, $newText, $oldText);

            // 6. ENTERPRISE V4: Load avatar overlay for comment author
            $avatarOverlays = [];
            $userSettingsOverlay = UserSettingsOverlayService::getInstance();
            if ($userSettingsOverlay->isAvailable()) {
                $avatarUrl = $userSettingsOverlay->getAvatarUrl($userId, null);
                if ($avatarUrl !== null) {
                    $avatarOverlays[$userId] = $avatarUrl;
                }
            }

            // 7. Return comment with patched text (overlay wins over DB)
            $comment['comment_text'] = $newText;
            $comment['is_edited'] = true;

            Logger::info('CommentService: Comment edited via overlay', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'text_changed' => $oldText !== $newText,
            ]);

            return [
                'success' => true,
                'comment' => $this->formatComment($comment, $userId, $avatarOverlays),
            ];

        } catch (\Exception $e) {
            Logger::error('CommentService::editComment failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Errore durante la modifica',
            ];
        }
    }

    /**
     * Get edit count for a comment
     *
     * ENTERPRISE V6 (2025-11-30): Returns number of times a comment has been edited
     * Used to enforce 3-edit limit
     *
     * @param int $commentId
     * @return int Number of edits (0 if never edited)
     */
    private function getEditCount(int $commentId): int
    {
        try {
            $db = db();
            $sql = "SELECT COUNT(*) as edit_count FROM comment_edit_history WHERE comment_id = :comment_id";
            $result = $db->findOne($sql, ['comment_id' => $commentId]);
            return (int)($result['edit_count'] ?? 0);
        } catch (\Exception $e) {
            Logger::warning('CommentService::getEditCount failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return 0; // Fail open (allow edit if can't check)
        }
    }

    /**
     * Save edit history entry
     *
     * @param int $commentId
     * @param int $userId
     * @param string $previousText
     * @return bool
     */
    private function saveEditHistory(int $commentId, int $userId, string $previousText): bool
    {
        try {
            $db = db();
            $sql = "INSERT INTO comment_edit_history (comment_id, user_id, previous_text, edited_at)
                    VALUES (:comment_id, :user_id, :previous_text, NOW())";

            $db->execute($sql, [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'previous_text' => $previousText,
            ]);

            return true;
        } catch (\Exception $e) {
            Logger::error('CommentService::saveEditHistory failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get edit history for a comment
     *
     * ENTERPRISE V4 (2025-11-28): Returns all previous versions for clock icon popup
     *
     * @param int $commentId
     * @param int $userId Current user (for permission check)
     * @return array
     */
    public function getEditHistory(int $commentId, int $userId): array
    {
        try {
            // Check comment exists
            $comment = $this->repository->findById($commentId);
            if (!$comment) {
                return [
                    'success' => false,
                    'error' => 'comment_not_found',
                    'message' => 'Commento non trovato',
                ];
            }

            // Anyone can view edit history (transparency)
            $db = db();
            $sql = "SELECT id, previous_text, edited_at
                    FROM comment_edit_history
                    WHERE comment_id = :comment_id
                    ORDER BY edited_at DESC
                    LIMIT 20";

            $history = $db->query($sql, ['comment_id' => $commentId], [
                'cache' => true,
                'cache_ttl' => 'short',
            ]);

            return [
                'success' => true,
                'comment_id' => $commentId,
                'current_text' => $comment['comment_text'],
                'is_edited' => (bool)$comment['is_edited'],
                'history' => array_map(function ($entry) {
                    return [
                        'text' => $entry['previous_text'],
                        'edited_at' => $entry['edited_at'],
                    ];
                }, $history),
            ];

        } catch (\Exception $e) {
            Logger::error('CommentService::getEditHistory failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Errore nel caricamento cronologia',
            ];
        }
    }

    /**
     * Like a comment
     *
     * @param int $commentId
     * @param int $userId
     * @return array
     */
    public function likeComment(int $commentId, int $userId): array
    {
        try {
            // 1. Check if comment exists
            $comment = $this->repository->findById($commentId);
            if (!$comment || $comment['status'] !== 'active') {
                return [
                    'success' => false,
                    'error' => 'comment_not_found',
                    'message' => 'Commento non trovato',
                ];
            }

            // 2. Check if already liked in DB
            if ($this->repository->hasUserLiked($commentId, $userId)) {
                return [
                    'success' => false,
                    'error' => 'already_liked',
                    'message' => 'Hai già messo like a questo commento',
                ];
            }

            // ENTERPRISE V4.7 (2025-11-29): Write DIRECTLY to DB for data durability
            // WriteBehindBuffer was losing data when Redis was flushed before DB sync
            // Like operations are low-volume, so direct DB write is acceptable
            $db = db();

            // 3a. Insert into comment_likes table
            $db->execute(
                "INSERT INTO comment_likes (user_id, comment_id) VALUES (:user_id, :comment_id)
                 ON CONFLICT (user_id, comment_id) DO NOTHING",
                ['user_id' => $userId, 'comment_id' => $commentId],
                ['invalidate_cache' => ["comment:{$commentId}"]]
            );

            // 3b. Update like_count on audio_comments
            $db->execute(
                "UPDATE audio_comments SET like_count = like_count + 1 WHERE id = :comment_id",
                ['comment_id' => $commentId],
                ['invalidate_cache' => ['table:audio_comments', "comment:{$commentId}"]]
            );

            // 4. Get new like count from DB
            $newLikeCount = (int)$comment['like_count'] + 1;

            // 5. Update overlay for immediate UI consistency (optional optimization)
            try {
                $this->overlayService->setUserLike($userId, $commentId);
            } catch (\Exception $e) {
                // Non-critical - DB is source of truth now
            }

            // 6. ENTERPRISE: Send notification to comment author
            // V4.9 (2025-11-30): Include post_id for notification navigation
            $commentAuthorId = (int) $comment['user_id'];
            $postId = $comment['audio_post_id'] ? (int) $comment['audio_post_id'] : null;
            if ($commentAuthorId !== $userId) {
                try {
                    NotificationService::getInstance()->notifyCommentLiked(
                        $commentAuthorId,
                        $userId,
                        $commentId,
                        $postId
                    );
                } catch (\Exception $e) {
                    // Non-critical
                }
            }

            return [
                'success' => true,
                'liked' => true,
                'like_count' => max(0, $newLikeCount),
            ];

        } catch (\Exception $e) {
            Logger::error('CommentService::likeComment failed', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Errore',
            ];
        }
    }

    /**
     * Unlike a comment
     *
     * @param int $commentId
     * @param int $userId
     * @return array
     */
    public function unlikeComment(int $commentId, int $userId): array
    {
        try {
            // 1. Check if comment exists
            $comment = $this->repository->findById($commentId);
            if (!$comment || $comment['status'] !== 'active') {
                return [
                    'success' => false,
                    'error' => 'comment_not_found',
                    'message' => 'Commento non trovato',
                ];
            }

            // 2. Check if actually liked in DB
            if (!$this->repository->hasUserLiked($commentId, $userId)) {
                return [
                    'success' => false,
                    'error' => 'not_liked',
                    'message' => 'Non hai messo like a questo commento',
                ];
            }

            // ENTERPRISE V4.7 (2025-11-29): Write DIRECTLY to DB for data durability
            $db = db();

            // 3a. Delete from comment_likes table
            $db->execute(
                "DELETE FROM comment_likes WHERE user_id = :user_id AND comment_id = :comment_id",
                ['user_id' => $userId, 'comment_id' => $commentId],
                ['invalidate_cache' => ["comment:{$commentId}"]]
            );

            // 3b. Update like_count on audio_comments (ensure never negative)
            $db->execute(
                "UPDATE audio_comments SET like_count = GREATEST(0, like_count - 1) WHERE id = :comment_id",
                ['comment_id' => $commentId],
                ['invalidate_cache' => ['table:audio_comments', "comment:{$commentId}"]]
            );

            // 4. Get new like count
            $newLikeCount = max(0, (int)$comment['like_count'] - 1);

            // 5. Update overlay (optional)
            try {
                $this->overlayService->removeUserLike($userId, $commentId);
            } catch (\Exception $e) {
                // Non-critical
            }

            return [
                'success' => true,
                'liked' => false,
                'like_count' => $newLikeCount,
            ];

        } catch (\Exception $e) {
            Logger::error('CommentService::unlikeComment failed', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Errore',
            ];
        }
    }

    /**
     * Get comments for a post (with overlay merging)
     *
     * @param int $postId
     * @param int $userId Current user for like status
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getPostComments(int $postId, int $userId, int $limit = 20, int $offset = 0): array
    {
        try {
            $limit = min($limit, self::MAX_COMMENTS_PER_PAGE);

            // 1. Get comments from DB
            $comments = $this->repository->getPostComments($postId, $limit, $offset);

            if (empty($comments)) {
                return [
                    'success' => true,
                    'comments' => [],
                    'total' => 0,
                    'has_more' => false,
                ];
            }

            // 2. Get comment IDs for batch overlay loading
            $commentIds = array_column($comments, 'id');

            // 3. Load overlays (likes, tombstones, user likes)
            $overlays = $this->overlayService->getBatchOverlays($commentIds, $userId);

            // ENTERPRISE V4 (2025-11-28): Load avatar overlays for comment authors
            // When user changes avatar, overlay wins over DB value
            $userIds = array_unique(array_column($comments, 'user_id'));
            $avatarOverlays = [];
            $userSettingsOverlay = UserSettingsOverlayService::getInstance();
            if ($userSettingsOverlay->isAvailable()) {
                $avatarOverlays = $userSettingsOverlay->batchLoadAvatars(array_map('intval', $userIds));
            }

            // 4. If no overlay data, check DB for user likes
            $userLikedIds = [];
            $commentIdsNeedingDbCheck = [];
            foreach ($commentIds as $id) {
                if (!isset($overlays[$id]['user_liked']) || $overlays[$id]['user_liked'] === null) {
                    $commentIdsNeedingDbCheck[] = $id;
                }
            }

            if (!empty($commentIdsNeedingDbCheck)) {
                $userLikedIds = $this->repository->getUserLikedCommentIds($userId, $commentIdsNeedingDbCheck);
            }

            // 5. Format comments with overlay data
            $formattedComments = [];
            foreach ($comments as $comment) {
                $id = (int)$comment['id'];
                $overlay = $overlays[$id] ?? [];

                // Skip tombstoned comments
                if (!empty($overlay['tombstone'])) {
                    continue;
                }

                // ENTERPRISE V4: Apply text patch if exists (edited comment)
                // Patch contains: text, is_edited, edited_at
                if (!empty($overlay['patch'])) {
                    if (isset($overlay['patch']['text'])) {
                        $comment['comment_text'] = $overlay['patch']['text'];
                    }
                    if (isset($overlay['patch']['is_edited'])) {
                        $comment['is_edited'] = $overlay['patch']['is_edited'];
                    }
                }

                $formatted = $this->formatComment($comment, $userId, $avatarOverlays);

                // Merge overlay like count delta
                if (isset($overlay['likes']) && $overlay['likes'] !== null) {
                    $formatted['like_count'] = max(0, (int)$comment['like_count'] + $overlay['likes']);
                }

                // V11.5: OVERLAY WINS pattern for reply_count (ABSOLUTE, not delta)
                // If overlay exists, use it directly (it's the absolute count)
                // If overlay is null, fall back to DB value (already in $formatted)
                if (isset($overlay['reply_count']) && $overlay['reply_count'] !== null) {
                    $formatted['reply_count'] = $overlay['reply_count'];
                }

                // Determine user's like status
                if (isset($overlay['user_liked']) && $overlay['user_liked'] !== null) {
                    $formatted['user_liked'] = $overlay['user_liked'] === 1;
                } else {
                    $formatted['user_liked'] = in_array($id, $userLikedIds);
                }

                $formattedComments[] = $formatted;
            }

            // 6. Get total count
            $total = $this->repository->countPostComments($postId);

            // 7. Check overlay for comment count delta
            $overlayCount = $this->overlayService->getPostCommentCount($postId);
            if ($overlayCount !== null) {
                $total = max(0, $total + $overlayCount);
            }

            return [
                'success' => true,
                'comments' => $formattedComments,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total,
            ];

        } catch (\Exception $e) {
            Logger::error('CommentService::getPostComments failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Errore nel caricamento commenti',
            ];
        }
    }

    /**
     * Get replies for a parent comment
     *
     * @param int $parentCommentId
     * @param int $userId Current user
     * @return array
     */
    public function getReplies(int $parentCommentId, int $userId): array
    {
        try {
            // 1. Check parent exists
            $parent = $this->repository->findById($parentCommentId);
            if (!$parent || $parent['status'] !== 'active') {
                return [
                    'success' => false,
                    'error' => 'parent_not_found',
                    'message' => 'Commento non trovato',
                ];
            }

            // 2. Get replies
            $replies = $this->repository->getReplies($parentCommentId, self::MAX_REPLIES_PER_COMMENT);

            if (empty($replies)) {
                return [
                    'success' => true,
                    'replies' => [],
                ];
            }

            // 3. Load overlays
            $replyIds = array_column($replies, 'id');
            $overlays = $this->overlayService->getBatchOverlays($replyIds, $userId);

            // ENTERPRISE V4 (2025-11-28): Load avatar overlays for reply authors
            $userIds = array_unique(array_column($replies, 'user_id'));
            $avatarOverlays = [];
            $userSettingsOverlay = UserSettingsOverlayService::getInstance();
            if ($userSettingsOverlay->isAvailable()) {
                $avatarOverlays = $userSettingsOverlay->batchLoadAvatars(array_map('intval', $userIds));
            }

            // 4. Check DB for user likes where overlay is null
            $idsNeedingDbCheck = [];
            foreach ($replyIds as $id) {
                if (!isset($overlays[$id]['user_liked']) || $overlays[$id]['user_liked'] === null) {
                    $idsNeedingDbCheck[] = $id;
                }
            }
            $userLikedIds = !empty($idsNeedingDbCheck)
                ? $this->repository->getUserLikedCommentIds($userId, $idsNeedingDbCheck)
                : [];

            // 5. Format replies
            $formattedReplies = [];
            foreach ($replies as $reply) {
                $id = (int)$reply['id'];
                $overlay = $overlays[$id] ?? [];

                if (!empty($overlay['tombstone'])) {
                    continue;
                }

                // ENTERPRISE V4 (2025-11-28): Apply text patch if exists (edited reply)
                // Same pattern as post patches - overlay wins over DB
                if (!empty($overlay['patch'])) {
                    if (isset($overlay['patch']['text'])) {
                        $reply['comment_text'] = $overlay['patch']['text'];
                    }
                    if (isset($overlay['patch']['is_edited'])) {
                        $reply['is_edited'] = $overlay['patch']['is_edited'];
                    }
                }

                $formatted = $this->formatComment($reply, $userId, $avatarOverlays);

                if (isset($overlay['likes']) && $overlay['likes'] !== null) {
                    $formatted['like_count'] = max(0, (int)$reply['like_count'] + $overlay['likes']);
                }

                if (isset($overlay['user_liked']) && $overlay['user_liked'] !== null) {
                    $formatted['user_liked'] = $overlay['user_liked'] === 1;
                } else {
                    $formatted['user_liked'] = in_array($id, $userLikedIds);
                }

                $formattedReplies[] = $formatted;
            }

            return [
                'success' => true,
                'replies' => $formattedReplies,
            ];

        } catch (\Exception $e) {
            Logger::error('CommentService::getReplies failed', [
                'parent_comment_id' => $parentCommentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'exception',
                'message' => 'Errore nel caricamento risposte',
            ];
        }
    }

    /**
     * Get comment count for a post (with overlay delta)
     *
     * ENTERPRISE V4 FIX (2025-11-29): Returns ROOT comments only, not replies
     * Replies are counted separately via comment.reply_count
     *
     * @param int $postId
     * @return int
     */
    public function getCommentCount(int $postId): int
    {
        // Count ALL comments (root + replies) = total conversation count
        $dbCount = $this->repository->countAllPostComments($postId);

        return max(0, $dbCount);
    }

    /**
     * Format comment for API response
     *
     * ENTERPRISE V4 (2025-11-28): Supports avatar overlay - when user changes avatar,
     * the overlay value takes priority over the DB value for immediate visibility.
     *
     * @param array $comment Raw DB comment
     * @param int $currentUserId Current user ID
     * @param array $avatarOverlays [userId => avatarUrl] from UserSettingsOverlayService
     * @return array Formatted comment
     */
    private function formatComment(array $comment, int $currentUserId, array $avatarOverlays = []): array
    {
        $authorId = (int)$comment['user_id'];

        // ENTERPRISE V4: Avatar overlay wins over DB value
        // This ensures immediate visibility when user changes their avatar
        $avatarUrl = $avatarOverlays[$authorId] ?? $comment['avatar_url'];

        $isOwner = $authorId === $currentUserId;
        $commentId = (int)$comment['id'];

        // ENTERPRISE V6 (2025-11-30): Include edit_count for owner to enable/disable edit button
        // Only query edit_count for owner's comments (avoids N+1 for other users)
        $editCount = null;
        if ($isOwner) {
            $editCount = $this->getEditCount($commentId);
        }

        return [
            'id' => $commentId,
            'uuid' => $comment['uuid'],
            'text' => $comment['comment_text'],
            'is_edited' => (bool)$comment['is_edited'],
            'edit_count' => $editCount, // null for non-owners, 0-3 for owners
            'like_count' => (int)($comment['like_count'] ?? 0),
            'reply_count' => (int)($comment['reply_count'] ?? 0),
            'created_at' => $comment['created_at'],
            'is_owner' => $isOwner,
            'author' => [
                // ENTERPRISE SECURITY: Never expose numeric ID - use UUID only
                'uuid' => $comment['author_uuid'] ?? $comment['user_uuid'],
                'nickname' => $comment['nickname'],
                // ENTERPRISE: Use helper to normalize avatar path (DB stores relative, API returns absolute)
                // Avatar overlay already normalized from UserSettingsOverlayService
                'avatar_url' => get_avatar_url($avatarUrl),
            ],
            // ENTERPRISE V4 (2025-11-28): Include mentioned users for frontend highlighting
            'mentioned_users' => $this->parseMentionedUsersFromDb($comment['mentioned_users'] ?? null),
        ];
    }

    /**
     * Extract and validate @mentions from comment text
     *
     * ENTERPRISE V4 (2025-11-28): Only friends can be mentioned
     * - Parses @nickname patterns from text
     * - Validates each nickname belongs to user's friends list
     * - Returns array of validated users for DB storage
     *
     * @param string $text Comment text
     * @param string $userUuid Current user UUID (for friend lookup)
     * @return array Array of mentioned users [['id' => int, 'uuid' => string, 'nickname' => string], ...]
     */
    private function extractAndValidateMentions(string $text, string $userUuid): array
    {
        // Extract @nicknames from text (alphanumeric + underscores, 3-20 chars)
        preg_match_all('/@([a-zA-Z0-9_]{3,20})/', $text, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $mentionedNicknames = array_unique($matches[1]);

        // Get user's friends (cached, fast lookup)
        $friendship = new Friendship();
        $friends = $friendship->getFriendsByUuid($userUuid, 'accepted', 200, 0);

        if (empty($friends)) {
            return [];
        }

        // Build nickname -> friend map (case-insensitive)
        // ENTERPRISE FIX (2025-11-30): Include avatar_url for frontend display
        $friendMap = [];
        foreach ($friends as $friend) {
            $friendMap[strtolower($friend['nickname'])] = [
                'id' => (int)$friend['id'],
                'uuid' => $friend['uuid'],
                'nickname' => $friend['nickname'],
                // Include normalized avatar URL (handles Google OAuth, local storage, CDN)
                'avatar_url' => get_avatar_url($friend['avatar_url'] ?? null),
            ];
        }

        // Validate and collect mentioned friends
        $validMentions = [];
        foreach ($mentionedNicknames as $nickname) {
            $key = strtolower($nickname);
            if (isset($friendMap[$key])) {
                $validMentions[] = $friendMap[$key];
            }
        }

        return $validMentions;
    }

    /**
     * Parse mentioned_users JSONB from DB and enrich with avatar URLs
     *
     * ENTERPRISE FIX (2025-11-30): Enriches mentioned users with avatar_url
     * - New comments have avatar_url stored in JSON
     * - Old comments without avatar_url are enriched at read time
     * - Uses cached user lookups for performance
     *
     * @param string|null $json JSON from DB
     * @return array Parsed mentioned users with avatar_url or empty array
     */
    private function parseMentionedUsersFromDb(?string $json): array
    {
        if (empty($json)) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        // Check if we need to enrich with avatar URLs (backward compatibility)
        $needsEnrichment = false;
        foreach ($data as $user) {
            if (!isset($user['avatar_url'])) {
                $needsEnrichment = true;
                break;
            }
        }

        if (!$needsEnrichment) {
            return $data;
        }

        // Enrich: fetch avatar URLs for users missing them
        $userUuids = array_filter(array_column($data, 'uuid'));
        if (empty($userUuids)) {
            return $data;
        }

        // Batch lookup avatars (cached query)
        $db = db();
        $placeholders = implode(',', array_fill(0, count($userUuids), '?'));
        $avatars = $db->query(
            "SELECT uuid, avatar_url FROM users WHERE uuid IN ($placeholders)",
            array_values($userUuids),
            ['cache' => true, 'cache_ttl' => 'medium']
        );

        // Build uuid -> avatar_url map
        $avatarMap = [];
        foreach ($avatars as $row) {
            $avatarMap[$row['uuid']] = get_avatar_url($row['avatar_url'] ?? null);
        }

        // Enrich mentioned users with avatar URLs
        foreach ($data as &$user) {
            if (!isset($user['avatar_url']) && isset($user['uuid'], $avatarMap[$user['uuid']])) {
                $user['avatar_url'] = $avatarMap[$user['uuid']];
            }
        }

        return $data;
    }

    /**
     * Send notifications for new comment
     *
     * ENTERPRISE GALAXY (2025-11-29):
     * - Notifies post author of new comment (if not self)
     * - Notifies parent comment author of reply (if not self)
     * - Notifies mentioned users (if not self)
     *
     * TODO: Check user notification preferences before sending
     *
     * @param int $postId
     * @param int $commentId
     * @param int $commenterId
     * @param string $commentText
     * @param int|null $parentCommentId
     * @param array|null $parentComment
     * @param array $post
     * @param array $mentionedUsers
     */
    private function sendCommentNotifications(
        int $postId,
        int $commentId,
        int $commenterId,
        string $commentText,
        ?int $parentCommentId,
        ?array $parentComment,
        array $post,
        array $mentionedUsers
    ): void {
        try {
            $notificationService = NotificationService::getInstance();
            $postAuthorId = (int) $post['user_id'];
            $commentPreview = mb_substr($commentText, 0, 100);

            // 1. Notify post author of new comment (not if replying to a comment)
            // Only for ROOT comments, not replies
            if ($parentCommentId === null && $postAuthorId !== $commenterId) {
                // TODO: Check user preferences: $this->canNotify($postAuthorId, 'new_comment')
                $notificationService->notifyNewComment(
                    $postAuthorId,
                    $commenterId,
                    $postId,
                    $commentId,
                    $commentPreview
                );
            }

            // 2. Notify parent comment author of reply
            if ($parentComment !== null) {
                $parentAuthorId = (int) $parentComment['user_id'];
                if ($parentAuthorId !== $commenterId) {
                    // TODO: Check user preferences: $this->canNotify($parentAuthorId, 'comment_reply')
                    $notificationService->notifyCommentReply(
                        $parentAuthorId,
                        $commenterId,
                        $postId,
                        $commentId,
                        $commentPreview
                    );
                }
            }

            // 3. Notify mentioned users
            if (!empty($mentionedUsers)) {
                foreach ($mentionedUsers as $mentioned) {
                    $mentionedUserId = (int) ($mentioned['id'] ?? 0);
                    if ($mentionedUserId > 0 && $mentionedUserId !== $commenterId) {
                        // TODO: Check user preferences: $this->canNotify($mentionedUserId, 'mentioned')
                        // ENTERPRISE V4.14 (2025-11-30): Pass postId for notification navigation
                        $notificationService->notifyMentioned(
                            $mentionedUserId,
                            $commenterId,
                            NotificationService::TARGET_COMMENT,
                            $commentId,
                            $commentPreview,
                            $postId  // Added for navigation
                        );
                    }
                }
            }

        } catch (\Exception $e) {
            // Notification failure should not break comment creation
            Logger::warning('CommentService: Failed to send notifications', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
