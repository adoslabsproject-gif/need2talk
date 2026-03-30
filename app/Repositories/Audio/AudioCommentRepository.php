<?php

namespace Need2Talk\Repositories\Audio;

use Need2Talk\Core\Database;

/**
 * Audio Comment Repository - Enterprise Galaxy
 *
 * Database layer for audio_comments table
 * Supports 2-level comment recursion (comment → reply)
 * Auto-updates counters via PostgreSQL triggers
 *
 * @package Need2Talk\Repositories\Audio
 */
class AudioCommentRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * Create new comment
     *
     * @param array $data Comment data
     * @return int|false Comment ID or false
     */
    public function create(array $data): int|false
    {
        // Validate 2-level recursion: if parent_comment_id is set, check parent has no parent
        if (!empty($data['parent_comment_id'])) {
            $parent = $this->findById($data['parent_comment_id']);
            if (!$parent) {
                return false; // Parent doesn't exist
            }
            if ($parent['parent_comment_id'] !== null) {
                return false; // Parent is already a reply (max 2 levels)
            }
        }

        $sql = "INSERT INTO audio_comments (
            audio_file_id,
            user_id,
            parent_comment_id,
            comment_text,
            mentioned_users,
            status,
            created_at
        ) VALUES (
            :audio_file_id,
            :user_id,
            :parent_comment_id,
            :comment_text,
            :mentioned_users,
            :status,
            NOW()
        )";

        $result = $this->db->execute($sql, [
            'audio_file_id' => $data['audio_file_id'],
            'user_id' => $data['user_id'],
            'parent_comment_id' => $data['parent_comment_id'] ?? null,
            'comment_text' => $data['comment_text'],
            'mentioned_users' => !empty($data['mentioned_users']) ? json_encode($data['mentioned_users']) : null,
            'status' => $data['status'] ?? 'active',
        ], [
            'invalidate_cache' => [
                "audio:{$data['audio_file_id']}",
                "audio_comments:{$data['audio_file_id']}",
            ],
        ]);

        return $result ? $this->db->lastInsertId() : false;
    }

    /**
     * Get comment by ID
     *
     * @param int $commentId Comment ID
     * @return array|null Comment data
     */
    public function findById(int $commentId): ?array
    {
        $sql = "SELECT
            id,
            audio_file_id,
            user_id,
            parent_comment_id,
            comment_text,
            mentioned_users,
            like_count,
            reply_count,
            is_edited,
            status,
            created_at,
            updated_at
        FROM audio_comments
        WHERE id = :id";

        return $this->db->findOne($sql, ['id' => $commentId], [
            'cache' => true,
            'cache_ttl' => 'medium',
        ]);
    }

    /**
     * Get comments for audio post (top-level only)
     *
     * @param int $audioId Audio ID
     * @param int $limit Comments per page
     * @param int $offset Pagination offset
     * @return array Comments
     */
    public function getAudioComments(int $audioId, int $limit = 20, int $offset = 0): array
    {
        $sql = "SELECT
            ac.id,
            ac.user_id,
            ac.comment_text,
            ac.like_count,
            ac.reply_count,
            ac.is_edited,
            ac.created_at,
            u.nickname,
            u.avatar_url
        FROM audio_comments ac
        INNER JOIN users u ON ac.user_id = u.id
        WHERE ac.audio_file_id = :audio_id
          AND ac.parent_comment_id IS NULL
          AND ac.status = 'active'
        ORDER BY ac.created_at DESC
        LIMIT :limit OFFSET :offset";

        return $this->db->query($sql, [
            'audio_id' => $audioId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * Get replies to a comment
     *
     * @param int $parentCommentId Parent comment ID
     * @param int $limit Replies per page
     * @param int $offset Pagination offset
     * @return array Replies
     */
    public function getReplies(int $parentCommentId, int $limit = 10, int $offset = 0): array
    {
        $sql = "SELECT
            ac.id,
            ac.user_id,
            ac.comment_text,
            ac.like_count,
            ac.is_edited,
            ac.created_at,
            u.nickname,
            u.avatar_url
        FROM audio_comments ac
        INNER JOIN users u ON ac.user_id = u.id
        WHERE ac.parent_comment_id = :parent_id
          AND ac.status = 'active'
        ORDER BY ac.created_at ASC
        LIMIT :limit OFFSET :offset";

        return $this->db->query($sql, [
            'parent_id' => $parentCommentId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * Update comment text
     *
     * @param int $commentId Comment ID
     * @param string $newText New comment text
     * @return bool Success
     */
    public function update(int $commentId, string $newText): bool
    {
        $sql = "UPDATE audio_comments
                SET comment_text = :text,
                    is_edited = TRUE,
                    updated_at = NOW()
                WHERE id = :id";

        $comment = $this->findById($commentId);
        if (!$comment) {
            return false;
        }

        return $this->db->execute($sql, [
            'id' => $commentId,
            'text' => $newText,
        ], [
            'invalidate_cache' => [
                "audio:{$comment['audio_file_id']}",
                "audio_comments:{$comment['audio_file_id']}",
                "comment:{$commentId}",
            ],
        ]);
    }

    /**
     * Soft delete comment (set status to 'deleted')
     *
     * @param int $commentId Comment ID
     * @return bool Success
     */
    public function delete(int $commentId): bool
    {
        $sql = "UPDATE audio_comments
                SET status = 'deleted',
                    updated_at = NOW()
                WHERE id = :id";

        $comment = $this->findById($commentId);
        if (!$comment) {
            return false;
        }

        return $this->db->execute($sql, ['id' => $commentId], [
            'invalidate_cache' => [
                "audio:{$comment['audio_file_id']}",
                "audio_comments:{$comment['audio_file_id']}",
                "comment:{$commentId}",
            ],
        ]);
    }

    /**
     * Check if user owns comment
     *
     * @param int $commentId Comment ID
     * @param int $userId User ID
     * @return bool Ownership
     */
    public function isOwner(int $commentId, int $userId): bool
    {
        $sql = "SELECT 1 FROM audio_comments
                WHERE id = :comment_id
                  AND user_id = :user_id";

        return (bool) $this->db->findOne($sql, [
            'comment_id' => $commentId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Get total comments count for audio
     *
     * @param int $audioId Audio ID
     * @return int Total comments (includes replies)
     */
    public function count(int $audioId): int
    {
        return $this->db->count('audio_comments', 'audio_file_id = ? AND status = ?', [$audioId, 'active']);
    }

    /**
     * Get user's comments
     *
     * @param int $userId User ID
     * @param int $limit Comments per page
     * @param int $offset Pagination offset
     * @return array Comments
     */
    public function getUserComments(int $userId, int $limit = 20, int $offset = 0): array
    {
        $sql = "SELECT
            ac.id,
            ac.audio_file_id,
            ac.parent_comment_id,
            ac.comment_text,
            ac.like_count,
            ac.reply_count,
            ac.is_edited,
            ac.created_at,
            af.title as audio_title,
            af.user_id as audio_author_id
        FROM audio_comments ac
        INNER JOIN audio_files af ON ac.audio_file_id = af.id
        WHERE ac.user_id = :user_id
          AND ac.status = 'active'
          AND af.deleted_at IS NULL
        ORDER BY ac.created_at DESC
        LIMIT :limit OFFSET :offset";

        return $this->db->query($sql, [
            'user_id' => $userId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'cache' => true,
            'cache_ttl' => 'medium',
        ]);
    }
}
