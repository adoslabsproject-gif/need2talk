<?php

namespace Need2Talk\Services\Audio\Social;

use Need2Talk\Repositories\Audio\AudioCommentRepository;
use Need2Talk\Services\Logger;

/**
 * Audio Interaction Service - Enterprise Galaxy
 *
 * Business logic for comments
 * Handles social interactions with validation and rate limiting
 *
 * NOTE: Likes system replaced with Emotional Reactions (10 emotions)
 * See: AudioReactionController, EmotionalReactionService
 *
 * @package Need2Talk\Services\Audio\Social
 */
class AudioInteractionService
{
    private AudioCommentRepository $commentRepo;

    public function __construct()
    {
        $this->commentRepo = new AudioCommentRepository();
    }

    // ==================== COMMENTS ====================

    /**
     * Add comment to audio
     *
     * @param int $userId User ID
     * @param int $audioId Audio ID
     * @param string $commentText Comment text
     * @param int|null $parentCommentId Parent comment ID (for replies)
     * @param array $mentionedUsers Mentioned user IDs
     * @return array Result with comment ID
     */
    public function addComment(
        int $userId,
        int $audioId,
        string $commentText,
        ?int $parentCommentId = null,
        array $mentionedUsers = []
    ): array {
        try {
            // Validate comment length (max 1000 chars)
            if (mb_strlen($commentText) > 1000) {
                return [
                    'success' => false,
                    'error' => 'comment_too_long',
                    'message' => 'Commento troppo lungo (max 1000 caratteri)',
                ];
            }

            // Validate comment not empty
            if (trim($commentText) === '') {
                return [
                    'success' => false,
                    'error' => 'comment_empty',
                    'message' => 'Il commento non può essere vuoto',
                ];
            }

            $commentData = [
                'audio_file_id' => $audioId,
                'user_id' => $userId,
                'parent_comment_id' => $parentCommentId,
                'comment_text' => $commentText,
                'mentioned_users' => $mentionedUsers,
                'status' => 'active',
            ];

            $commentId = $this->commentRepo->create($commentData);

            if (!$commentId) {
                return [
                    'success' => false,
                    'error' => 'comment_failed',
                    'message' => 'Errore durante la creazione del commento',
                ];
            }

            Logger::info('Comment added', [
                'user_id' => $userId,
                'audio_id' => $audioId,
                'comment_id' => $commentId,
                'is_reply' => !empty($parentCommentId),
            ]);

            return [
                'success' => true,
                'comment_id' => $commentId,
                'message' => 'Commento pubblicato',
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to add comment', [
                'user_id' => $userId,
                'audio_id' => $audioId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ];
        }
    }

    /**
     * Get comments for audio
     *
     * @param int $audioId Audio ID
     * @param int $page Page number
     * @param int $perPage Comments per page
     * @return array Comments
     */
    public function getComments(int $audioId, int $page = 1, int $perPage = 10): array
    {
        try {
            $offset = ($page - 1) * $perPage;
            $comments = $this->commentRepo->getAudioComments($audioId, $perPage, $offset);

            // Get replies for each comment
            $commentsWithReplies = array_map(function ($comment) {
                $replies = $this->commentRepo->getReplies($comment['id'], 10, 0);
                $comment['replies'] = $replies;
                $comment['has_more_replies'] = $comment['reply_count'] > count($replies);

                return $comment;
            }, $comments);

            return [
                'success' => true,
                'comments' => $commentsWithReplies,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'has_more' => count($comments) === $perPage,
                ],
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get comments', [
                'audio_id' => $audioId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'comments' => [],
            ];
        }
    }

    /**
     * Get replies to a comment
     *
     * @param int $commentId Comment ID
     * @param int $page Page number
     * @param int $perPage Replies per page
     * @return array Replies
     */
    public function getReplies(int $commentId, int $page = 1, int $perPage = 10): array
    {
        try {
            $offset = ($page - 1) * $perPage;
            $replies = $this->commentRepo->getReplies($commentId, $perPage, $offset);

            return [
                'success' => true,
                'replies' => $replies,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'has_more' => count($replies) === $perPage,
                ],
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get replies', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'replies' => [],
            ];
        }
    }

    /**
     * Edit comment
     *
     * @param int $commentId Comment ID
     * @param int $userId User ID (ownership check)
     * @param string $newText New comment text
     * @return array Result
     */
    public function editComment(int $commentId, int $userId, string $newText): array
    {
        try {
            // Check ownership
            if (!$this->commentRepo->isOwner($commentId, $userId)) {
                return [
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Non sei autorizzato a modificare questo commento',
                ];
            }

            // Validate length
            if (mb_strlen($newText) > 1000) {
                return [
                    'success' => false,
                    'error' => 'comment_too_long',
                    'message' => 'Commento troppo lungo (max 1000 caratteri)',
                ];
            }

            if (trim($newText) === '') {
                return [
                    'success' => false,
                    'error' => 'comment_empty',
                    'message' => 'Il commento non può essere vuoto',
                ];
            }

            $result = $this->commentRepo->update($commentId, $newText);

            if ($result) {
                Logger::info('Comment edited', [
                    'comment_id' => $commentId,
                    'user_id' => $userId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Commento modificato',
                ];
            }

            return [
                'success' => false,
                'error' => 'update_failed',
                'message' => 'Errore durante la modifica',
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to edit comment', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ];
        }
    }

    /**
     * Delete comment
     *
     * @param int $commentId Comment ID
     * @param int $userId User ID (ownership check)
     * @return array Result
     */
    public function deleteComment(int $commentId, int $userId): array
    {
        try {
            // Check ownership
            if (!$this->commentRepo->isOwner($commentId, $userId)) {
                return [
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Non sei autorizzato a eliminare questo commento',
                ];
            }

            $result = $this->commentRepo->delete($commentId);

            if ($result) {
                Logger::info('Comment deleted', [
                    'comment_id' => $commentId,
                    'user_id' => $userId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Commento eliminato',
                ];
            }

            return [
                'success' => false,
                'error' => 'deletion_failed',
                'message' => 'Errore durante l\'eliminazione',
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to delete comment', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ];
        }
    }
}
