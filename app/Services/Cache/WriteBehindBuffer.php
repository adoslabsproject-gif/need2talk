<?php

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * WriteBehindBuffer - Event-Driven Write-Behind Cache
 *
 * ENTERPRISE GALAXY V4 (2025-11-28)
 *
 * Buffers reaction/view/comment changes in Redis and flushes to DB periodically.
 * This provides immediate visibility while reducing DB writes by ~95%.
 *
 * Strategy: "Solo Redis" (No WAL)
 * - Accept max 60s data loss in case of Redis crash (rare)
 * - Simpler architecture, faster writes
 *
 * Flush Trigger:
 * - Event-driven: When buffer > FLUSH_THRESHOLD items
 * - Fallback: overlay-flush-worker checks every cycle
 *
 * Data Structures:
 * - overlay:dirty:reactions (ZSET with timestamp scores)
 * - overlay:dirty:views (ZSET with timestamp scores)
 * - overlay:dirty:comment_likes (ZSET with timestamp scores) [V4]
 * - overlay:dirty:comments (ZSET with timestamp scores) [V4]
 * - overlay:flush:trigger (flag for flush worker)
 *
 * @package Need2Talk\Services\Cache
 */
class WriteBehindBuffer
{
    private const FLUSH_THRESHOLD = 100;  // Trigger flush when buffer > 100 items
    private const MAX_BUFFER_AGE = 300;   // Force flush if oldest item > 5 minutes

    private static ?self $instance = null;
    private ?\Redis $redis = null;
    private OverlayService $overlay;
    private CommentOverlayService $commentOverlay;

    private function __construct()
    {
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection('overlay');
        $this->overlay = OverlayService::getInstance();
        $this->commentOverlay = CommentOverlayService::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Buffer a reaction change
     *
     * 1. Updates Redis overlay for immediate visibility
     * 2. Adds to dirty set for later DB flush
     * 3. Triggers async flush if buffer exceeds threshold
     *
     * @param int $postId
     * @param int $userId
     * @param int $emotionId New emotion ID
     * @param int|null $prevEmotionId Previous emotion ID (for change/remove)
     */
    public function bufferReaction(int $postId, int $userId, int $emotionId, ?int $prevEmotionId = null): void
    {
        if (!$this->redis) {
            Logger::overlay('warning', 'WriteBehindBuffer::bufferReaction - Redis not available, using DB fallback', [
                'post_id' => $postId,
                'user_id' => $userId,
                'emotion_id' => $emotionId,
            ]);
            return; // Fallback: direct DB write in EmotionalReactionService
        }

        try {
            $timestamp = microtime(true);

            // 1. Update Redis overlay (immediate visibility)
            $this->overlay->incrementReaction($postId, $emotionId);
            if ($prevEmotionId && $prevEmotionId !== $emotionId) {
                $this->overlay->decrementReaction($postId, $prevEmotionId);
            }

            // 2. Set user's personal reaction
            $setResult = $this->overlay->setUserReaction($userId, $postId, $emotionId);
            if (!$setResult) {
                Logger::overlay('warning', 'WriteBehindBuffer::bufferReaction - setUserReaction returned false', [
                    'post_id' => $postId,
                    'user_id' => $userId,
                    'emotion_id' => $emotionId,
                ]);
            }

            // 3. Add to dirty set for DB flush
            $member = json_encode([
                'post_id' => $postId,
                'user_id' => $userId,
                'emotion_id' => $emotionId,
                'prev_emotion_id' => $prevEmotionId,
                'action' => 'upsert',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->zAdd('overlay:dirty:reactions', $timestamp, $member);

            // 4. Check if flush needed
            $this->checkFlushTrigger('reactions');

        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::bufferReaction failed', [
                'post_id' => $postId,
                'user_id' => $userId,
                'emotion_id' => $emotionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buffer a reaction removal
     */
    public function bufferReactionRemoval(int $postId, int $userId, int $emotionId): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $timestamp = microtime(true);

            // 1. Update Redis overlay
            $this->overlay->decrementReaction($postId, $emotionId);

            // 2. Remove user's personal reaction (sets tombstone)
            $this->overlay->removeUserReaction($userId, $postId);

            // 3. Add to dirty set for DB flush
            $member = json_encode([
                'post_id' => $postId,
                'user_id' => $userId,
                'emotion_id' => $emotionId,
                'prev_emotion_id' => null,
                'action' => 'delete',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->zAdd('overlay:dirty:reactions', $timestamp, $member);

            // 4. Check if flush needed
            $this->checkFlushTrigger('reactions');

        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::bufferReactionRemoval failed', [
                'post_id' => $postId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buffer a view increment
     */
    public function bufferView(int $postId, int $userId): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $timestamp = microtime(true);

            // 1. Increment view in overlay
            $this->overlay->incrementViews($postId);

            // 2. Add to dirty set (deduplicated by user+post in flush)
            $member = json_encode([
                'post_id' => $postId,
                'user_id' => $userId,
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->zAdd('overlay:dirty:views', $timestamp, $member);

            // 3. Check if flush needed
            $this->checkFlushTrigger('views');

        } catch (\Exception $e) {
            // Views are non-critical, silent fail
        }
    }

    /**
     * Check if buffer exceeds threshold and trigger flush
     */
    private function checkFlushTrigger(string $type): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:dirty:{$type}";
            $bufferSize = $this->redis->zCard($key);

            if ($bufferSize >= self::FLUSH_THRESHOLD) {
                // Set trigger flag for session-sync to pick up
                $this->redis->setex('overlay:flush:trigger', 10, time());

                Logger::overlay('info', 'WriteBehindBuffer: Flush threshold reached', [
                    'type' => $type,
                    'buffer_size' => $bufferSize,
                ]);
            }
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get current buffer sizes (for monitoring)
     *
     * ENTERPRISE V10.9 (2025-12-10): Fixed to use V6 generational keys (gen:dirty:*)
     * The old keys (overlay:dirty:*) are deprecated - V6 uses sorted sets with timestamps
     * Without correct keys, overlay-flush-worker exits as IDLE when items are pending!
     */
    public function getBufferStatus(): array
    {
        if (!$this->redis) {
            Logger::overlay('warning', 'WriteBehindBuffer::getBufferStatus - Redis not available');
            return ['available' => false];
        }

        try {
            // V6 GENERATIONAL: Use gen:dirty:* keys (NOT overlay:dirty:*)
            $reactionsPending = $this->redis->zCard('gen:dirty:reactions') ?: 0;
            $viewsPending = $this->redis->zCard('gen:dirty:views') ?: 0;
            $playsPending = $this->redis->zCard('gen:dirty:plays') ?: 0;
            $commentsPending = $this->redis->zCard('gen:dirty:comments') ?: 0;

            $status = [
                'available' => true,
                'reactions_pending' => $reactionsPending,
                'views_pending' => $viewsPending,
                'plays_pending' => $playsPending,
                'comments_pending' => $commentsPending,
                'flush_triggered' => $this->redis->exists('overlay:flush:trigger') > 0,
            ];

            // Log if there are pending items (useful for debugging)
            $total = $reactionsPending + $viewsPending + $playsPending + $commentsPending;
            if ($total > 0) {
                Logger::overlay('debug', 'WriteBehindBuffer::getBufferStatus', [
                    'reactions' => $reactionsPending,
                    'views' => $viewsPending,
                    'plays' => $playsPending,
                    'comments' => $commentsPending,
                    'total' => $total,
                ]);
            }

            return $status;
        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::getBufferStatus failed', [
                'error' => $e->getMessage(),
            ]);
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get pending reaction changes for flush
     *
     * @param int $limit Max items to retrieve
     * @return array Array of pending changes
     */
    public function getPendingReactions(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            // Get oldest items first (FIFO)
            $items = $this->redis->zRange('overlay:dirty:reactions', 0, $limit - 1);
            $result = [];

            foreach ($items as $json) {
                $data = json_decode($json, true);
                if ($data) {
                    $data['_raw'] = $json; // Keep raw for removal after flush
                    $result[] = $data;
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get pending view changes for flush
     *
     * @param int $limit
     * @return array
     */
    public function getPendingViews(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $items = $this->redis->zRange('overlay:dirty:views', 0, $limit - 1);
            $result = [];

            foreach ($items as $json) {
                $data = json_decode($json, true);
                if ($data) {
                    $data['_raw'] = $json;
                    $result[] = $data;
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Remove flushed items from buffer
     *
     * @param array $items Items with '_raw' key
     * @param string $type 'reactions' or 'views'
     */
    public function removeFlushedItems(array $items, string $type): void
    {
        if (!$this->redis || empty($items)) return;

        try {
            $key = "overlay:dirty:{$type}";

            foreach ($items as $item) {
                if (isset($item['_raw'])) {
                    $this->redis->zRem($key, $item['_raw']);
                }
            }
        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::removeFlushedItems failed', [
                'type' => $type,
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if flush is needed (called by session-sync)
     */
    public function needsFlush(): bool
    {
        if (!$this->redis) return false;

        try {
            // Check explicit trigger
            if ($this->redis->exists('overlay:flush:trigger')) {
                return true;
            }

            // Check buffer sizes
            $reactionsCount = $this->redis->zCard('overlay:dirty:reactions');
            $viewsCount = $this->redis->zCard('overlay:dirty:views');

            if ($reactionsCount >= self::FLUSH_THRESHOLD || $viewsCount >= self::FLUSH_THRESHOLD) {
                return true;
            }

            // Check oldest item age
            $oldestReaction = $this->redis->zRange('overlay:dirty:reactions', 0, 0, true);
            if (!empty($oldestReaction)) {
                $oldestTs = reset($oldestReaction);
                if ((microtime(true) - $oldestTs) > self::MAX_BUFFER_AGE) {
                    return true;
                }
            }

            $oldestView = $this->redis->zRange('overlay:dirty:views', 0, 0, true);
            if (!empty($oldestView)) {
                $oldestTs = reset($oldestView);
                if ((microtime(true) - $oldestTs) > self::MAX_BUFFER_AGE) {
                    return true;
                }
            }

            return false;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear flush trigger after successful flush
     */
    public function clearFlushTrigger(): void
    {
        if (!$this->redis) return;

        try {
            $this->redis->del('overlay:flush:trigger');
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    // =========================================================================
    // COMMENT LIKES OPERATIONS (ENTERPRISE GALAXY V4 - 2025-11-28)
    // =========================================================================

    /**
     * Buffer a comment like
     *
     * 1. Updates Redis overlay for immediate visibility
     * 2. Adds to dirty set for later DB flush
     * 3. Triggers async flush if buffer exceeds threshold
     *
     * @param int $commentId
     * @param int $userId
     */
    public function bufferCommentLike(int $commentId, int $userId): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $timestamp = microtime(true);

            // 1. Update Redis overlay (immediate visibility)
            $this->commentOverlay->incrementLikes($commentId);

            // 2. Set user's personal like
            $this->commentOverlay->setUserLike($userId, $commentId);

            // 3. Add to dirty set for DB flush
            $member = json_encode([
                'comment_id' => $commentId,
                'user_id' => $userId,
                'action' => 'like',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->zAdd('overlay:dirty:comment_likes', $timestamp, $member);

            // 4. Check if flush needed
            $this->checkFlushTrigger('comment_likes');

        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::bufferCommentLike failed', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buffer a comment unlike
     */
    public function bufferCommentUnlike(int $commentId, int $userId): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $timestamp = microtime(true);

            // 1. Update Redis overlay
            $this->commentOverlay->decrementLikes($commentId);

            // 2. Remove user's personal like (tombstone)
            $this->commentOverlay->removeUserLike($userId, $commentId);

            // 3. Add to dirty set
            $member = json_encode([
                'comment_id' => $commentId,
                'user_id' => $userId,
                'action' => 'unlike',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->zAdd('overlay:dirty:comment_likes', $timestamp, $member);

            // 4. Check if flush needed
            $this->checkFlushTrigger('comment_likes');

        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::bufferCommentUnlike failed', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buffer a new comment
     *
     * ENTERPRISE V10.153 (2025-12-09): Buffer for async reply_count updates
     * - CommentRepository does NOT update reply_count directly (removed novice pattern)
     * - flushComments() reads this buffer and updates reply_count asynchronously
     * - Better for high concurrency: no DB writes blocking HTTP requests
     * - Post comment_count is handled separately by V6 overlay (gen:overlay:comments:*)
     *
     * @param int $postId Post the comment belongs to
     * @param int $commentId The new comment ID
     * @param int $userId Author of the comment
     * @param int|null $parentCommentId If this is a reply
     * @return bool True if buffered successfully, false if Redis unavailable (caller should fallback)
     */
    public function bufferNewComment(int $postId, int $commentId, int $userId, ?int $parentCommentId = null): bool
    {
        if (!$this->redis) {
            Logger::overlay('warning', 'WriteBehindBuffer::bufferNewComment - Redis not available, caller should use DB fallback', [
                'post_id' => $postId,
                'comment_id' => $commentId,
                'parent_comment_id' => $parentCommentId,
            ]);
            return false;
        }

        try {
            $timestamp = microtime(true);

            // Buffer event with parent_comment_id for reply_count tracking
            $member = json_encode([
                'post_id' => $postId,
                'comment_id' => $commentId,
                'user_id' => $userId,
                'parent_comment_id' => $parentCommentId,
                'action' => 'create',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $result = $this->redis->zAdd('overlay:dirty:comments', $timestamp, $member);
            return $result !== false;

        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::bufferNewComment failed', [
                'post_id' => $postId,
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Buffer a comment deletion
     *
     * ENTERPRISE V10.153 (2025-12-09): Buffer for async reply_count updates
     * - Tombstone for immediate UI update (comment disappears instantly)
     * - flushComments() reads this buffer and updates reply_count asynchronously
     * - Post comment_count handled separately by V6 overlay (gen:overlay:comments:*)
     *
     * @param int $postId
     * @param int $commentId
     * @param int|null $parentCommentId Parent comment ID if this is a reply
     * @return bool True if buffered successfully, false if Redis unavailable (caller should fallback)
     */
    public function bufferDeleteComment(int $postId, int $commentId, ?int $parentCommentId = null): bool
    {
        if (!$this->redis) {
            Logger::overlay('warning', 'WriteBehindBuffer::bufferDeleteComment - Redis not available, caller should use DB fallback', [
                'post_id' => $postId,
                'comment_id' => $commentId,
                'parent_comment_id' => $parentCommentId,
            ]);
            return false;
        }

        try {
            $timestamp = microtime(true);

            // Set tombstone for immediate visibility (comment disappears from UI instantly)
            $this->commentOverlay->setTombstone($commentId);

            // Buffer event with parent_comment_id for reply_count tracking
            $member = json_encode([
                'post_id' => $postId,
                'comment_id' => $commentId,
                'parent_comment_id' => $parentCommentId,
                'action' => 'delete',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $result = $this->redis->zAdd('overlay:dirty:comments', $timestamp, $member);
            return $result !== false;

        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::bufferDeleteComment failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Buffer a comment edit
     *
     * ENTERPRISE V4 (2025-11-28): Comment text edits via overlay.
     * The overlay patch is already set by CommentOverlayService::setPatch().
     * This buffers for DB sync and edit history.
     *
     * @param int $commentId
     * @param int $userId
     * @param string $newText New comment text
     * @param string $oldText Old comment text (for history)
     */
    public function bufferCommentEdit(int $commentId, int $userId, string $newText, string $oldText): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $timestamp = microtime(true);

            // NOTE: Overlay patch already set by CommentOverlayService::setPatch()
            // Here we just buffer for DB flush + edit history

            // Add to dirty set for DB sync
            $member = json_encode([
                'comment_id' => $commentId,
                'user_id' => $userId,
                'new_text' => $newText,
                'old_text' => $oldText,
                'action' => 'edit',
                'ts' => $timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->zAdd('overlay:dirty:comment_edits', $timestamp, $member);

            // Check if flush needed
            $this->checkFlushTrigger('comment_edits');

        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::bufferCommentEdit failed', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get pending comment edits for flush
     *
     * @param int $limit Max items to retrieve
     * @return array Array of pending edits
     */
    public function getPendingCommentEdits(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $items = $this->redis->zRange('overlay:dirty:comment_edits', 0, $limit - 1);
            $result = [];

            foreach ($items as $json) {
                $data = json_decode($json, true);
                if ($data) {
                    $data['_raw'] = $json;
                    $result[] = $data;
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Remove flushed comment edits from dirty set
     *
     * @param array $items Items that were flushed (must have _raw key)
     * @return int Number removed
     */
    public function removeFlushedCommentEdits(array $items): int
    {
        if (!$this->redis || empty($items)) return 0;

        try {
            $rawItems = array_map(fn($item) => $item['_raw'] ?? '', $items);
            $rawItems = array_filter($rawItems);

            if (empty($rawItems)) return 0;

            return $this->redis->zRem('overlay:dirty:comment_edits', ...$rawItems);
        } catch (\Exception $e) {
            Logger::overlay('error', 'WriteBehindBuffer::removeFlushedCommentEdits failed', [
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get pending comment likes for flush
     *
     * @param int $limit Max items to retrieve
     * @return array Array of pending changes
     */
    public function getPendingCommentLikes(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $items = $this->redis->zRange('overlay:dirty:comment_likes', 0, $limit - 1);
            $result = [];

            foreach ($items as $json) {
                $data = json_decode($json, true);
                if ($data) {
                    $data['_raw'] = $json;
                    $result[] = $data;
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get pending comment changes for flush
     *
     * @param int $limit
     * @return array
     */
    public function getPendingComments(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $items = $this->redis->zRange('overlay:dirty:comments', 0, $limit - 1);
            $result = [];

            foreach ($items as $json) {
                $data = json_decode($json, true);
                if ($data) {
                    $data['_raw'] = $json;
                    $result[] = $data;
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get extended buffer status including comments
     */
    public function getExtendedBufferStatus(): array
    {
        if (!$this->redis) {
            return ['available' => false];
        }

        try {
            return [
                'available' => true,
                'reactions_pending' => $this->redis->zCard('overlay:dirty:reactions'),
                'views_pending' => $this->redis->zCard('overlay:dirty:views'),
                'comment_likes_pending' => $this->redis->zCard('overlay:dirty:comment_likes'),
                'comments_pending' => $this->redis->zCard('overlay:dirty:comments'),
                'flush_triggered' => $this->redis->exists('overlay:flush:trigger') > 0,
            ];
        } catch (\Exception $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }
}
