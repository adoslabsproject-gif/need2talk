<?php

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * CommentOverlayService - Enterprise Cache Overlay for Comments
 *
 * ENTERPRISE GALAXY V4 (2025-11-28)
 *
 * Manages overlay data for comments without requiring full cache invalidation.
 * Follows the same pattern as OverlayService for reactions/views.
 *
 * Overlay Types:
 * - Comment Likes:   overlay:comment:{commentId}:likes = INT
 * - Comment Tombstone: overlay:comment:{commentId}:tombstone = "1"
 * - Post Comment Count: overlay:{postId}:comment_count = INT
 * - Reply Count: overlay:comment:{commentId}:reply_count = INT
 *
 * Personal Data:
 * - User's like: personal:{userId}:cmtlike:{commentId} = "1" or "0" (tombstone)
 *
 * TTL Strategy:
 * - Likes/Counts: 1h (refreshed on write)
 * - Personal: 5min
 * - Tombstone: 1h
 *
 * @package Need2Talk\Services\Cache
 */
class CommentOverlayService
{
    private const TTL_LIKES = 3600;         // 1 hour
    private const TTL_COUNTS = 3600;        // 1 hour
    private const TTL_PERSONAL = 300;       // 5 minutes
    private const TTL_TOMBSTONE = 3600;     // 1 hour

    // V6 GENERATIONAL: Event TTL (24 hours)
    private const TTL_EVENTS = 86400;

    // V6 GENERATIONAL: Key prefixes (same as OverlayService)
    private const GEN_OVERLAY_PREFIX = 'gen:overlay:';
    private const GEN_DIRTY_PREFIX = 'gen:dirty:';
    // V6.2: Flush timestamp prefix
    private const GEN_FLUSH_PREFIX = 'gen:flush:';

    // V6 Redis DB (overlay database - separate from cache)
    private const OVERLAY_DB = 5;

    private static ?self $instance = null;
    private ?\Redis $redis = null;

    private function __construct()
    {
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection('overlay');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if Redis overlay is available
     */
    public function isAvailable(): bool
    {
        return $this->redis !== null;
    }

    private const TTL_PATCH = 3600;         // 1 hour (same as post patches)

    // =========================================================================
    // PATCH OPERATIONS (Comment Text Edit - No Cache Invalidation)
    // =========================================================================

    /**
     * Set comment text patch (edit without cache invalidation)
     *
     * ENTERPRISE V4 (2025-11-28): Same pattern as post patches.
     * Stores edited text in Redis overlay, merged on read.
     * WriteBehind flushes to DB periodically.
     *
     * @param int $commentId
     * @param string $newText New comment text
     * @param bool $isEdited Flag to show "(modificato)"
     * @return bool
     */
    public function setPatch(int $commentId, string $newText, bool $isEdited = true): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:comment:{$commentId}:patch";
            $data = json_encode([
                'text' => $newText,
                'is_edited' => $isEdited,
                'edited_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE);

            return $this->redis->setex($key, self::TTL_PATCH, $data);
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::setPatch failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get comment text patch
     *
     * @param int $commentId
     * @return array|null ['text' => string, 'is_edited' => bool, 'edited_at' => string] or null
     */
    public function getPatch(int $commentId): ?array
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:comment:{$commentId}:patch";
            $json = $this->redis->get($key);
            return $json ? json_decode($json, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Remove comment patch (after DB sync)
     *
     * @param int $commentId
     * @return bool
     */
    public function removePatch(int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->del("overlay:comment:{$commentId}:patch") >= 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if comment has a patch
     *
     * @param int $commentId
     * @return bool
     */
    public function hasPatch(int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->exists("overlay:comment:{$commentId}:patch") > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // V6 GENERATIONAL OVERLAY - Comment Count Events
    // =========================================================================

    /**
     * Record a new comment event (V6 GENERATIONAL)
     *
     * ENTERPRISE V6 (2025-11-29): Events stored in sorted set with timestamp as score.
     * Flush worker reads events and writes to DB, then removes processed events.
     * Delta = ZCARD (count all events in set).
     *
     * @param int $postId Post ID that received the comment
     * @param int $userId User who commented
     * @param int $delta +1 for new comment, -1 for deleted comment
     * @return bool
     */
    public function recordCommentEvent(int $postId, int $userId, int $delta = 1): bool
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $now = microtime(true);

            // Event key: gen:overlay:comments:{postId}
            $eventKey = self::GEN_OVERLAY_PREFIX . "comments:{$postId}";

            // Dirty set for flush worker
            $dirtyKey = self::GEN_DIRTY_PREFIX . 'comments';

            // Event value: delta:userId:timestamp (delta can be +1 or -1)
            $eventValue = "{$delta}:{$userId}:{$now}";

            // Pipeline: add event + mark dirty
            $pipe = $this->redis->pipeline();
            $pipe->zAdd($eventKey, $now, $eventValue);
            $pipe->expire($eventKey, self::TTL_EVENTS);
            $pipe->zAdd($dirtyKey, $now, (string) $postId);
            $pipe->exec();

            return true;

        } catch (\Exception $e) {
            Logger::error('CommentOverlayService::recordCommentEvent failed', [
                'post_id' => $postId,
                'user_id' => $userId,
                'delta' => $delta,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get comment count deltas for multiple posts (V6.2 - FLUSH TIMESTAMP BASED)
     *
     * ENTERPRISE V6.2 (2025-11-29): Count events AFTER flush timestamp.
     * Sum all event deltas (+1/-1) with score > flush_timestamp.
     *
     * Benefits:
     * - NO cache invalidation needed
     * - Old cache + events since flush = correct
     * - Events auto-expire via TTL (24h)
     *
     * @param array $postIds Array of post IDs
     * @return array [postId => delta]
     */
    public function getBatchCommentDeltas(array $postIds): array
    {
        if (!$this->redis || empty($postIds)) {
            return array_fill_keys($postIds, 0);
        }

        try {
            // ENTERPRISE V10.154 (2025-12-09): Timestamp-based filtering
            // Events with score <= flush_timestamp have ALREADY been flushed to DB
            // Only count events with score > flush_timestamp (NEW events)
            //
            // Flow:
            // 1. Flush worker updates DB with delta, sets flush_ts = max(event.score)
            // 2. Events are NOT deleted (timestamp-based system)
            // 3. getBatchCommentDeltas() only counts events AFTER flush_ts
            // 4. Result: DB + new_overlay_delta = correct total
            $results = [];

            foreach ($postIds as $postId) {
                $eventKey = self::GEN_OVERLAY_PREFIX . "comments:{$postId}";
                $flushKey = self::GEN_FLUSH_PREFIX . "comments:{$postId}";

                // ENTERPRISE V10.154: Get flush timestamp as STRING to preserve precision
                // PHP float conversion loses precision: "1765285338.729219" → 1765285338.7292
                // Using raw string ensures exact comparison in Redis
                $flushTs = $this->redis->get($flushKey) ?: '0';

                // Get events with score > flush_ts (NEW events only)
                // Using '(' prefix for exclusive range (score > flushTs, not >=)
                $events = $this->redis->zRangeByScore($eventKey, '(' . $flushTs, '+inf');

                $delta = 0;
                if ($events) {
                    foreach ($events as $eventValue) {
                        // Parse "delta:userId:timestamp"
                        $parts = explode(':', $eventValue);
                        if (count($parts) >= 1) {
                            $delta += (int) $parts[0];
                        }
                    }
                }

                $results[$postId] = $delta;
            }

            return $results;

        } catch (\Exception $e) {
            Logger::error('CommentOverlayService::getBatchCommentDeltas failed', [
                'post_count' => count($postIds),
                'error' => $e->getMessage(),
            ]);
            return array_fill_keys($postIds, 0);
        }
    }

    /**
     * Get dirty post IDs that have pending comment events
     *
     * @param int $limit Max IDs to return
     * @return array Post IDs with pending events
     */
    public function getDirtyCommentPostIds(int $limit = 100): array
    {
        if (!$this->redis) {
            return [];
        }

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . 'comments';
            return $this->redis->zRange($dirtyKey, 0, $limit - 1);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Clear events for a post after flush (V6 - DEPRECATED in V6.2)
     *
     * @deprecated Use setCommentFlushTimestamp() instead - V6.2 doesn't delete events
     * @param int $postId
     * @return bool
     */
    public function clearCommentEvents(int $postId): bool
    {
        // V6.2: Don't delete events! Use setCommentFlushTimestamp() instead
        // Just clear the dirty marker
        if (!$this->redis) {
            return false;
        }

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . 'comments';
            $this->redis->zRem($dirtyKey, (string) $postId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Set flush timestamp for comment events (V6.2)
     *
     * ENTERPRISE V6.2: Instead of deleting events after flush, we set a timestamp.
     * getBatchCommentDeltas() will only count events AFTER this timestamp.
     *
     * @param int $postId Post ID
     * @param float|null $timestamp Flush timestamp (null = now)
     * @return bool Success
     */
    public function setCommentFlushTimestamp(int $postId, ?float $timestamp = null): bool
    {
        if (!$this->redis) return false;

        try {
            $ts = $timestamp ?? microtime(true);
            $flushKey = self::GEN_FLUSH_PREFIX . "comments:{$postId}";
            // TTL: 24 hours
            $this->redis->setex($flushKey, self::TTL_EVENTS, (string)$ts);
            return true;
        } catch (\Exception $e) {
            Logger::overlay('error', 'setCommentFlushTimestamp failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Prune old comment events (V6.2)
     *
     * Called periodically to clean up events older than flush timestamp.
     *
     * @param int $postId Post ID
     * @return int Number of events pruned
     */
    public function pruneOldCommentEvents(int $postId): int
    {
        if (!$this->redis) return 0;

        try {
            $flushKey = self::GEN_FLUSH_PREFIX . "comments:{$postId}";
            $flushTs = $this->redis->get($flushKey);

            if ($flushTs === false || $flushTs <= 0) {
                return 0;
            }

            $eventKey = self::GEN_OVERLAY_PREFIX . "comments:{$postId}";
            return (int)$this->redis->zRemRangeByScore($eventKey, '-inf', $flushTs);
        } catch (\Exception $e) {
            return 0;
        }
    }

    // =========================================================================
    // TOMBSTONE OPERATIONS (Soft Delete Comments)
    // =========================================================================

    /**
     * Mark comment as deleted (soft delete via tombstone)
     */
    public function setTombstone(int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:comment:{$commentId}:tombstone";
            return $this->redis->setex($key, self::TTL_TOMBSTONE, '1');
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::setTombstone failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove tombstone (restore comment)
     */
    public function removeTombstone(int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:comment:{$commentId}:tombstone";
            return $this->redis->del($key) >= 0;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::removeTombstone failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if comment has tombstone
     */
    public function hasTombstone(int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->exists("overlay:comment:{$commentId}:tombstone") > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // COMMENT LIKES OPERATIONS (Write-Behind)
    // =========================================================================

    /**
     * Increment like count for a comment
     *
     * ENTERPRISE V4: No DB warm - Write-behind means DB is stale.
     * Redis incrBy creates key if not exists.
     */
    public function incrementLikes(int $commentId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:comment:{$commentId}:likes";
            $newCount = $this->redis->incrBy($key, 1);
            $this->redis->expire($key, self::TTL_LIKES);
            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::incrementLikes failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Decrement like count for a comment
     */
    public function decrementLikes(int $commentId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:comment:{$commentId}:likes";
            $newCount = $this->redis->incrBy($key, -1);

            // Don't allow negative counts
            if ($newCount < 0) {
                $this->redis->set($key, 0);
                $newCount = 0;
            }

            $this->redis->expire($key, self::TTL_LIKES);
            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::decrementLikes failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get like count delta for a comment
     *
     * @return int|null Delta value or null if no overlay data
     */
    public function getLikes(int $commentId): ?int
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:comment:{$commentId}:likes";
            $value = $this->redis->get($key);
            return $value !== false ? (int)$value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if likes overlay exists for a comment
     */
    public function hasLikesOverlay(int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->exists("overlay:comment:{$commentId}:likes") > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Set like count (used for initialization from DB)
     */
    public function setLikes(int $commentId, int $count): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:comment:{$commentId}:likes";
            $this->redis->setex($key, self::TTL_LIKES, $count);
            return true;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::setLikes failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // POST COMMENT COUNT OPERATIONS
    // =========================================================================

    /**
     * Increment comment count for a post
     */
    public function incrementPostCommentCount(int $postId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:{$postId}:comment_count";
            $newCount = $this->redis->incrBy($key, 1);
            $this->redis->expire($key, self::TTL_COUNTS);
            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::incrementPostCommentCount failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Decrement comment count for a post
     */
    public function decrementPostCommentCount(int $postId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:{$postId}:comment_count";
            $newCount = $this->redis->incrBy($key, -1);

            if ($newCount < 0) {
                $this->redis->set($key, 0);
                $newCount = 0;
            }

            $this->redis->expire($key, self::TTL_COUNTS);
            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::decrementPostCommentCount failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get comment count delta for a post
     */
    public function getPostCommentCount(int $postId): ?int
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:{$postId}:comment_count";
            $value = $this->redis->get($key);
            return $value !== false ? (int)$value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Set comment count for a post (initialization)
     */
    public function setPostCommentCount(int $postId, int $count): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:{$postId}:comment_count";
            $this->redis->setex($key, self::TTL_COUNTS, $count);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // REPLY COUNT OPERATIONS (V11.5 ABSOLUTE VALUE PATTERN)
    // =========================================================================

    /**
     * Increment reply count for a parent comment (V11.5 ABSOLUTE VALUE)
     *
     * ENTERPRISE V11.5 (2025-12-11): ABSOLUTE VALUE pattern for reply_count!
     *
     * Same pattern as V11.3 for post comment_count:
     * - If overlay doesn't exist → warm from DB COUNT(*), DON'T increment
     * - If overlay exists → increment normally
     * - "Overlay wins" pattern in formatComment()
     *
     * @param int $parentCommentId Parent comment ID
     * @return int New absolute reply count
     */
    public function incrementReplyAbsolute(int $parentCommentId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:abs:replies:{$parentCommentId}";

            // V11.5: Check if overlay exists BEFORE any operation
            $overlayExisted = $this->redis->exists($key);

            if (!$overlayExisted) {
                // Overlay doesn't exist - warm from DB
                // DON'T increment! COUNT(*) already includes the just-inserted reply
                $this->warmReplyOverlay($parentCommentId);

                // Get the warmed value (this IS the correct count)
                $currentCount = (int)$this->redis->get($key);

                Logger::overlay('debug', 'V11.5 Reply increment: warmed from DB (no increment)', [
                    'parent_comment_id' => $parentCommentId,
                    'count' => $currentCount,
                ]);

                return $currentCount;
            }

            // Overlay existed - increment normally
            $newCount = $this->redis->incrBy($key, 1);
            $this->redis->expire($key, self::TTL_COUNTS);

            Logger::overlay('debug', 'V11.5 Reply increment: overlay existed, incremented', [
                'parent_comment_id' => $parentCommentId,
                'new_count' => $newCount,
            ]);

            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::incrementReplyAbsolute failed', [
                'parent_comment_id' => $parentCommentId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Decrement reply count for a parent comment (V11.5 ABSOLUTE VALUE)
     *
     * ENTERPRISE V11.5 (2025-12-11): ABSOLUTE VALUE pattern for reply_count!
     *
     * Same pattern as V11.3 for post comment_count:
     * - If overlay doesn't exist → warm from DB COUNT(*), DON'T decrement
     * - If overlay exists → decrement normally
     *
     * @param int $parentCommentId Parent comment ID
     * @return int New absolute reply count
     */
    public function decrementReplyAbsolute(int $parentCommentId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:abs:replies:{$parentCommentId}";

            // V11.5: Check if overlay exists BEFORE any operation
            $overlayExisted = $this->redis->exists($key);

            if (!$overlayExisted) {
                // Overlay doesn't exist - warm from DB
                // DON'T decrement! COUNT(*) already excludes the just-deleted reply
                $this->warmReplyOverlay($parentCommentId);

                // Get the warmed value (this IS the correct count)
                $currentCount = (int)$this->redis->get($key);

                Logger::overlay('debug', 'V11.5 Reply decrement: warmed from DB (no decrement)', [
                    'parent_comment_id' => $parentCommentId,
                    'count' => $currentCount,
                ]);

                return $currentCount;
            }

            // Overlay existed - decrement normally
            $newCount = $this->redis->incrBy($key, -1);

            if ($newCount < 0) {
                $this->redis->set($key, 0);
                $newCount = 0;
            }

            $this->redis->expire($key, self::TTL_COUNTS);

            Logger::overlay('debug', 'V11.5 Reply decrement: overlay existed, decremented', [
                'parent_comment_id' => $parentCommentId,
                'new_count' => $newCount,
            ]);

            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::decrementReplyAbsolute failed', [
                'parent_comment_id' => $parentCommentId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Warm reply overlay from DB COUNT(*)
     *
     * ENTERPRISE V11.5 (2025-12-11): Gets current reply count from database.
     *
     * @param int $parentCommentId Parent comment ID
     */
    private function warmReplyOverlay(int $parentCommentId): void
    {
        try {
            $db = db();
            $count = $db->count(
                'audio_comments',
                "parent_comment_id = :parent_id AND status != 'deleted'",
                ['parent_id' => $parentCommentId]
            );

            $key = "overlay:abs:replies:{$parentCommentId}";
            $this->redis->setex($key, self::TTL_COUNTS, (string)$count);

            Logger::overlay('debug', 'V11.5 Reply overlay warmed from DB', [
                'parent_comment_id' => $parentCommentId,
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::warmReplyOverlay failed', [
                'parent_comment_id' => $parentCommentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get ABSOLUTE reply count (V11.5 - "Overlay wins" pattern)
     *
     * ENTERPRISE V11.5 (2025-12-11): Returns absolute count from overlay.
     * If overlay doesn't exist, returns null (caller should use DB value).
     *
     * @param int $parentCommentId Parent comment ID
     * @return int|null Absolute reply count, or null if no overlay
     */
    public function getAbsoluteReplyCount(int $parentCommentId): ?int
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:abs:replies:{$parentCommentId}";
            $value = $this->redis->get($key);
            return $value !== false ? (int)$value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Increment reply count for a parent comment
     *
     * @deprecated Use incrementReplyAbsolute() instead (V11.5)
     */
    public function incrementReplyCount(int $parentCommentId): int
    {
        // V11.5: Redirect to absolute method
        return $this->incrementReplyAbsolute($parentCommentId);
    }

    /**
     * Decrement reply count for a parent comment
     *
     * @deprecated Use decrementReplyAbsolute() instead (V11.5)
     */
    public function decrementReplyCount(int $parentCommentId): int
    {
        // V11.5: Redirect to absolute method
        return $this->decrementReplyAbsolute($parentCommentId);
    }

    /**
     * Get reply count delta
     *
     * @deprecated Use getAbsoluteReplyCount() instead (V11.5)
     */
    public function getReplyCount(int $parentCommentId): ?int
    {
        // V11.5: Redirect to absolute method
        return $this->getAbsoluteReplyCount($parentCommentId);
    }

    // =========================================================================
    // PERSONAL DATA OPERATIONS (User's Like)
    // =========================================================================

    /**
     * Set user's like on a comment
     */
    public function setUserLike(int $userId, int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "personal:{$userId}:cmtlike:{$commentId}";
            return $this->redis->setex($key, self::TTL_PERSONAL, '1');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get user's like status on a comment
     *
     * @return int|null 1 (liked), 0 (tombstone=unliked), null (no overlay data)
     */
    public function getUserLike(int $userId, int $commentId): ?int
    {
        if (!$this->redis) return null;

        try {
            $key = "personal:{$userId}:cmtlike:{$commentId}";
            $value = $this->redis->get($key);

            if ($value === false) {
                return null; // No overlay data - fallback to DB
            }

            return (int)$value;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Remove user's like (sets tombstone=0)
     *
     * ENTERPRISE V4: Uses tombstone instead of DELETE to distinguish:
     * - null: no overlay data, fallback to DB
     * - 0: tombstone, user removed like
     * - 1: user has liked
     */
    public function removeUserLike(int $userId, int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            // Set tombstone (0) instead of DELETE
            // TTL 10 minutes (longer than flush interval)
            return $this->redis->setex("personal:{$userId}:cmtlike:{$commentId}", 600, '0');
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // BATCH OPERATIONS (for Feed Loading)
    // =========================================================================

    /**
     * Get overlay data for multiple comments (pipeline)
     *
     * ENTERPRISE V11.5 (2025-12-11): Updated for ABSOLUTE VALUE pattern.
     * - reply_count now uses overlay:abs:replies:{id} (absolute, not delta)
     * - "Overlay wins" pattern: if overlay exists, use it directly
     *
     * @param array $commentIds
     * @param int|null $userId Current user for personal likes
     * @return array [commentId => ['likes' => int, 'reply_count' => int|null (ABSOLUTE), 'tombstone' => bool, 'user_liked' => bool, 'patch' => array|null]]
     */
    public function getBatchOverlays(array $commentIds, ?int $userId = null): array
    {
        if (!$this->redis || empty($commentIds)) return [];

        try {
            $results = [];
            $pipe = $this->redis->pipeline();

            foreach ($commentIds as $commentId) {
                $pipe->get("overlay:comment:{$commentId}:likes");
                // V11.5: Use absolute key for reply_count
                $pipe->get("overlay:abs:replies:{$commentId}");
                $pipe->exists("overlay:comment:{$commentId}:tombstone");
                $pipe->get("overlay:comment:{$commentId}:patch"); // ENTERPRISE V4: Include patch
                if ($userId) {
                    $pipe->get("personal:{$userId}:cmtlike:{$commentId}");
                }
            }

            $responses = $pipe->exec();
            $fieldsPerComment = $userId ? 5 : 4;

            foreach ($commentIds as $i => $commentId) {
                $offset = $i * $fieldsPerComment;
                $patchJson = $responses[$offset + 3];
                $results[$commentId] = [
                    'likes' => $responses[$offset] !== false ? (int)$responses[$offset] : null,
                    // V11.5: reply_count is now ABSOLUTE (use directly, not as delta)
                    'reply_count' => $responses[$offset + 1] !== false ? (int)$responses[$offset + 1] : null,
                    'tombstone' => $responses[$offset + 2] > 0,
                    'patch' => $patchJson ? json_decode($patchJson, true) : null,
                    'user_liked' => $userId && isset($responses[$offset + 4]) && $responses[$offset + 4] !== false
                        ? (int)$responses[$offset + 4]
                        : null,
                ];
            }

            return $results;
        } catch (\Exception $e) {
            Logger::overlay('error', 'CommentOverlayService::getBatchOverlays failed', [
                'comment_count' => count($commentIds),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get comment count overlays for multiple posts (pipeline)
     *
     * @param array $postIds
     * @return array [postId => int|null]
     */
    public function getBatchPostCommentCounts(array $postIds): array
    {
        if (!$this->redis || empty($postIds)) return [];

        try {
            $pipe = $this->redis->pipeline();

            foreach ($postIds as $postId) {
                $pipe->get("overlay:{$postId}:comment_count");
            }

            $responses = $pipe->exec();
            $results = [];

            foreach ($postIds as $i => $postId) {
                $results[$postId] = $responses[$i] !== false ? (int)$responses[$i] : null;
            }

            return $results;
        } catch (\Exception $e) {
            return [];
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Get raw Redis connection for advanced operations
     */
    public function getRedisConnection(): ?\Redis
    {
        return $this->redis;
    }

    /**
     * Clear all overlays for a comment (permanent delete)
     */
    public function clearCommentOverlays(int $commentId): bool
    {
        if (!$this->redis) return false;

        try {
            $keys = [
                "overlay:comment:{$commentId}:tombstone",
                "overlay:comment:{$commentId}:likes",
                "overlay:comment:{$commentId}:reply_count",
            ];
            $this->redis->del($keys);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear comment count overlay for a post
     */
    public function clearPostCommentCountOverlay(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->del("overlay:{$postId}:comment_count") >= 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
