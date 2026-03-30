<?php

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * OverlayService - Enterprise Cache Overlay System
 *
 * ENTERPRISE GALAXY V6 (2025-11-29) - GENERATIONAL OVERLAY ARCHITECTURE
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE PROBLEM WITH V4 OVERLAY (fixed in V6):
 * ════════════════════════════════════════════════════════════════════════════
 *
 * V4 used simple counters: overlay:{id}:views = INT
 *
 * BROKEN SCENARIO (V4 with views - now fixed in V6 for listens):
 *   T=0: Cache loads → DB play_count=5, overlay=0, display=5 ✓
 *   T=1: User listens → overlay=1, display=5+1=6 ✓
 *   T=2: User listens → overlay=2, display=5+2=7 ✓
 *   T=3: FLUSH        → DB=7, overlay reset to 0
 *   T=4: Display      → cache(5) + overlay(0) = 5 ✗ WRONG!
 *
 * ════════════════════════════════════════════════════════════════════════════
 * V6 SOLUTION - GENERATIONAL OVERLAY:
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Events are stored with timestamps in Sorted Sets.
 * Cache has a "generation timestamp" marking when it was loaded.
 * Delta is calculated as: events SINCE generation timestamp.
 *
 * CORRECT SCENARIO (V6 with listens for audio):
 *   T=0: Cache loads → DB play_count=5, generation_ts=T0, display=5 ✓
 *   T=1: User listens → event{ts=T1} stored, display=5+events_since(T0)=5+1=6 ✓
 *   T=2: User listens → event{ts=T2} stored, display=5+events_since(T0)=5+2=7 ✓
 *   T=3: FLUSH        → DB=7 (overlay NOT touched!)
 *   T=4: Display      → cache(5) + events_since(T0) = 5+2 = 7 ✓
 *   T=5: Cache expires, reloads → DB=7, generation_ts=T5
 *   T=6: Display      → cache(7) + events_since(T5) = 7+0 = 7 ✓
 *
 * ════════════════════════════════════════════════════════════════════════════
 * DATA STRUCTURES:
 * ════════════════════════════════════════════════════════════════════════════
 *
 * EVENTS (Sorted Sets, score=timestamp):
 *   gen:overlay:views:{postId}           → Listen events (legacy name, tracks audio plays)
 *   gen:overlay:reactions:{postId}       → Reaction events (value includes action/emotion)
 *   gen:overlay:comments:{postId}        → Comment count events
 *   gen:overlay:plays:{audioFileId}      → Play count events (audio_files.play_count)
 *
 * GENERATION MARKERS:
 *   gen:cache:post:{postId}              → When post data was cached
 *   gen:cache:file:{audioFileId}         → When file data was cached
 *
 * DIRTY BUFFER (for flush worker):
 *   gen:dirty:views                      → ZSET of postIds to flush (listens)
 *   gen:dirty:reactions                  → ZSET of postIds to flush
 *   gen:dirty:comments                   → ZSET of postIds to flush
 *   gen:dirty:plays                      → ZSET of audioFileIds to flush (play_count)
 *
 * LEGACY KEYS (backwards compatibility during migration):
 *   overlay:{id}:* → Still read for existing data
 *
 * ════════════════════════════════════════════════════════════════════════════
 *
 * @package Need2Talk\Services\Cache
 */
class OverlayService
{
    // =========================================================================
    // CONSTANTS
    // =========================================================================

    // TTLs (seconds)
    private const TTL_REACTIONS = 3600;     // 1 hour
    private const TTL_VIEWS = 3600;         // 1 hour
    private const TTL_COMMENTS = 3600;      // 1 hour
    private const TTL_PLAYS = 3600;         // 1 hour
    private const TTL_PERSONAL = 300;       // 5 minutes
    private const TTL_TOMBSTONE = 3600;     // 1 hour
    private const TTL_PATCH = 3600;         // 1 hour

    // V6 GENERATIONAL: Event TTL (24 hours - for history)
    private const TTL_EVENTS = 86400;
    // V6 GENERATIONAL: Generation marker TTL (2 hours - longer than max cache TTL)
    private const TTL_GENERATION = 7200;
    // V6 GENERATIONAL: Prune events older than this (24 hours)
    private const PRUNE_THRESHOLD = 86400;

    // V6 GENERATIONAL: Key prefixes
    private const GEN_OVERLAY_PREFIX = 'gen:overlay:';
    private const GEN_CACHE_PREFIX = 'gen:cache:';
    private const GEN_DIRTY_PREFIX = 'gen:dirty:';
    // V6.2: Flush timestamp prefix (marks when events were last flushed to DB)
    private const GEN_FLUSH_PREFIX = 'gen:flush:';

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

    // =========================================================================
    // TOMBSTONE OPERATIONS (Soft Delete)
    // =========================================================================

    /**
     * Mark post as deleted (soft delete via tombstone)
     */
    public function setTombstone(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:{$postId}:tombstone";
            return $this->redis->setex($key, self::TTL_TOMBSTONE, '1');
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::setTombstone failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove tombstone (restore post)
     */
    public function removeTombstone(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:{$postId}:tombstone";
            return $this->redis->del($key) >= 0;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::removeTombstone failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if post has tombstone
     */
    public function hasTombstone(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->exists("overlay:{$postId}:tombstone") > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // PATCH OPERATIONS (Content Updates)
    // =========================================================================

    /**
     * Set content patch (partial update without cache invalidation)
     *
     * @param int $postId
     * @param array $changes ['content' => '...', 'title' => '...', 'photo_url' => '...']
     */
    public function setPatch(int $postId, array $changes): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:{$postId}:patch";
            $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
            return $this->redis->setex($key, self::TTL_PATCH, $json);
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::setPatch failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get content patch
     *
     * @return array|null Patch data or null if none
     */
    public function getPatch(int $postId): ?array
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:{$postId}:patch";
            $json = $this->redis->get($key);
            return $json ? json_decode($json, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Remove patch (after DB sync)
     */
    public function removePatch(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->del("overlay:{$postId}:patch") >= 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // REACTIONS OPERATIONS (Write-Behind)
    // =========================================================================

    /**
     * Increment reaction count for an emotion
     *
     * ENTERPRISE V4 FIX: DON'T warm from DB!
     * Write-behind means DB is stale. Warming corrupts overlay with old data.
     * Just increment directly - Redis hIncrBy creates key if not exists.
     * Frontend uses optimistic updates, eventual consistency on page reload.
     */
    public function incrementReaction(int $postId, int $emotionId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:{$postId}:reactions";

            // NO DB WARM! Just increment directly.
            // If key doesn't exist, Redis creates it with value 0 then increments.
            $newCount = $this->redis->hIncrBy($key, (string)$emotionId, 1);
            $this->redis->expire($key, self::TTL_REACTIONS);
            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::incrementReaction failed', [
                'post_id' => $postId,
                'emotion_id' => $emotionId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Decrement reaction count for an emotion
     *
     * ENTERPRISE V5 FIX (2025-11-30): MUST warm from DB if overlay doesn't exist!
     *
     * THE BUG (V4):
     *   1. User A adds reaction → overlay:{postId}:reactions[emotion] = 1
     *   2. Overlay expires (TTL)
     *   3. User B adds reaction → DB has 2 reactions
     *   4. User A removes reaction → decrementReaction CREATES overlay with -1 → clamped to 0
     *   5. Now overlay exists with {emotion: 0}, but DB has User B's reaction!
     *   6. Feed loads → overlay REPLACES DB → User B's reaction disappears!
     *
     * THE FIX:
     *   - If overlay doesn't exist, warm from DB first
     *   - This ensures we decrement from the correct base value
     *   - Even if DB is "stale" for pending adds, existing reactions ARE in DB
     */
    public function decrementReaction(int $postId, int $emotionId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:{$postId}:reactions";

            // V5 FIX: If overlay doesn't exist, warm from DB first!
            // This prevents creating an "empty" overlay that replaces valid DB data
            if (!$this->redis->exists($key)) {
                $this->warmReactionOverlay($postId);
            }

            // Now decrement (overlay guaranteed to exist with correct base values)
            $newCount = $this->redis->hIncrBy($key, (string)$emotionId, -1);

            // Don't allow negative counts
            if ($newCount < 0) {
                $this->redis->hSet($key, (string)$emotionId, 0);
                $newCount = 0;
            }

            $this->redis->expire($key, self::TTL_REACTIONS);
            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::decrementReaction failed', [
                'post_id' => $postId,
                'emotion_id' => $emotionId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get all reaction counts for a post
     *
     * ENTERPRISE V4: Include 0 counts so frontend can properly merge and delete
     * The frontend filters out <= 0 counts after merge
     *
     * @param int $postId
     * @param bool $includeZero Include emotions with 0 count (for API merge)
     * @return array [emotion_id => count, ...]
     */
    public function getReactions(int $postId, bool $includeZero = false): array
    {
        if (!$this->redis) return [];

        try {
            $key = "overlay:{$postId}:reactions";
            $data = $this->redis->hGetAll($key);
            if (!$data) return [];

            // Convert string keys to int, skip internal keys like _init
            $result = [];
            foreach ($data as $emotionId => $count) {
                if ($emotionId === '_init') continue; // Skip placeholder
                $intCount = (int)$count;
                // ENTERPRISE V4: Include 0 counts for API merge, frontend will filter
                if ($intCount > 0 || $includeZero) {
                    $result[(int)$emotionId] = $intCount;
                }
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if reaction overlay exists for a post
     *
     * ENTERPRISE V4: Used to distinguish between "no reactions" (overlay exists, all 0)
     * vs "no overlay data" (should fallback to DB)
     */
    public function hasReactionsOverlay(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->exists("overlay:{$postId}:reactions") > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Set all reaction counts for a post (used for initialization from DB)
     */
    public function setReactions(int $postId, array $reactions): bool
    {
        if (!$this->redis) return false;

        try {
            $key = "overlay:{$postId}:reactions";

            // Clear existing
            $this->redis->del($key);

            // Set new values if any
            if (!empty($reactions)) {
                foreach ($reactions as $emotionId => $count) {
                    $this->redis->hSet($key, (string)$emotionId, $count);
                }
                $this->redis->expire($key, self::TTL_REACTIONS);
            }

            return true;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::setReactions failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * ENTERPRISE V4: Warm reaction overlay from DB (lazy load)
     *
     * Called when overlay doesn't exist and we need to modify it.
     * Loads current counts from DB into Redis overlay.
     */
    private function warmReactionOverlay(int $postId): void
    {
        try {
            $db = db();
            $rows = $db->query(
                "SELECT emotion_id, COUNT(*) as count
                 FROM audio_reactions
                 WHERE audio_post_id = ?
                 GROUP BY emotion_id",
                [$postId]
            );

            $reactions = [];
            foreach ($rows as $row) {
                $reactions[(int)$row['emotion_id']] = (int)$row['count'];
            }

            // Initialize overlay with DB values (even if empty - prevents repeated DB queries)
            $key = "overlay:{$postId}:reactions";
            if (!empty($reactions)) {
                foreach ($reactions as $emotionId => $count) {
                    $this->redis->hSet($key, (string)$emotionId, $count);
                }
            } else {
                // Set a placeholder to indicate "overlay exists but empty"
                $this->redis->hSet($key, '_init', '1');
            }
            $this->redis->expire($key, self::TTL_REACTIONS);

        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::warmReactionOverlay failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // VIEWS OPERATIONS - V6 GENERATIONAL
    // =========================================================================

    /**
     * Record a view event (V6 GENERATIONAL)
     *
     * Stores event with timestamp in sorted set. Delta is calculated
     * based on cache generation, not reset on DB flush.
     *
     * @param int $postId Post ID
     * @param int $userId User ID (for analytics)
     * @return bool Success
     */
    public function recordView(int $postId, int $userId): bool
    {
        if (!$this->redis) return false;

        try {
            $now = microtime(true);
            $eventKey = self::GEN_OVERLAY_PREFIX . "views:{$postId}";
            $dirtyKey = self::GEN_DIRTY_PREFIX . "views";

            // Event value: userId:timestamp (unique per view)
            $eventValue = "{$userId}:{$now}";

            // Pipeline for atomicity
            $pipe = $this->redis->pipeline();
            $pipe->zAdd($eventKey, $now, $eventValue);
            $pipe->expire($eventKey, self::TTL_EVENTS);
            $pipe->zAdd($dirtyKey, $now, (string)$postId);
            $pipe->exec();

            return true;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::recordView failed', [
                'post_id' => $postId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Increment view count (V6 - calls recordView internally)
     *
     * @deprecated Use recordView() for proper generation tracking
     */
    public function incrementViews(int $postId, int $delta = 1): int
    {
        // V6: Record as event instead of simple increment
        // Use system user ID 0 for legacy calls without user context
        for ($i = 0; $i < $delta; $i++) {
            $this->recordView($postId, 0);
        }
        return $delta; // Return delta (can't know total without generation)
    }

    /**
     * Get view count delta since cache generation (V6 GENERATIONAL)
     *
     * @param int $postId Post ID
     * @param float|null $generationTs Cache generation timestamp (if known)
     * @return int Delta (number of views since cache load)
     */
    public function getViewsDelta(int $postId, ?float $generationTs = null): int
    {
        if (!$this->redis) return 0;

        try {
            $eventKey = self::GEN_OVERLAY_PREFIX . "views:{$postId}";

            // If no generation provided, get from stored marker
            if ($generationTs === null) {
                $genKey = self::GEN_CACHE_PREFIX . "post:{$postId}";
                $stored = $this->redis->get($genKey);
                $generationTs = $stored !== false ? (float)$stored : 0.0;
            }

            // Count events since generation timestamp
            return (int)$this->redis->zCount($eventKey, (string)$generationTs, '+inf');
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get view count delta (V6 - alias for getViewsDelta)
     *
     * @deprecated Use getViewsDelta() for clarity
     */
    public function getViews(int $postId): int
    {
        return $this->getViewsDelta($postId);
    }

    /**
     * Get view deltas for multiple posts (V6 GENERATIONAL - batch)
     *
     * @param array $postIds Array of post IDs
     * @return array [postId => delta, ...]
     */
    public function getBatchViewsDeltas(array $postIds): array
    {
        if (!$this->redis || empty($postIds)) {
            return array_fill_keys($postIds, 0);
        }

        try {
            // Get all generation timestamps
            $pipe = $this->redis->pipeline();
            foreach ($postIds as $postId) {
                $pipe->get(self::GEN_CACHE_PREFIX . "post:{$postId}");
            }
            $generations = $pipe->exec();

            // Get all deltas
            $pipe = $this->redis->pipeline();
            foreach ($postIds as $i => $postId) {
                $eventKey = self::GEN_OVERLAY_PREFIX . "views:{$postId}";
                $genTs = $generations[$i] !== false ? (string)$generations[$i] : '0';
                $pipe->zCount($eventKey, $genTs, '+inf');
            }
            $deltas = $pipe->exec();

            // Build results
            $results = [];
            foreach ($postIds as $i => $postId) {
                $results[$postId] = (int)($deltas[$i] ?? 0);
            }

            return $results;
        } catch (\Exception $e) {
            return array_fill_keys($postIds, 0);
        }
    }

    /**
     * Set cache generation for a post (V6 GENERATIONAL)
     *
     * Call this when post data is loaded from DB/cache.
     *
     * @param int $postId Post ID
     * @param float|null $timestamp Generation timestamp (default: now)
     * @return bool Success
     */
    public function setPostGeneration(int $postId, ?float $timestamp = null): bool
    {
        if (!$this->redis) return false;

        try {
            $ts = $timestamp ?? microtime(true);
            $key = self::GEN_CACHE_PREFIX . "post:{$postId}";
            $this->redis->setex($key, self::TTL_GENERATION, (string)$ts);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Set cache generation for multiple posts (V6 GENERATIONAL - batch) - SET IF NOT EXISTS
     *
     * CRITICAL: Uses SETNX to NOT overwrite existing generations!
     * If generation exists, events are counted from that timestamp.
     * If we overwrote it, we'd lose track of events since original load.
     *
     * @param array $postIds Array of post IDs
     * @param float|null $timestamp Generation timestamp (default: now)
     * @return bool Success
     */
    public function setBatchPostGenerations(array $postIds, ?float $timestamp = null): bool
    {
        if (!$this->redis || empty($postIds)) return false;

        try {
            $ts = (string)($timestamp ?? microtime(true));
            $pipe = $this->redis->pipeline();

            foreach ($postIds as $postId) {
                $key = self::GEN_CACHE_PREFIX . "post:{$postId}";
                // SETNX then EXPIRE: Pipeline-safe way to set only if not exists
                $pipe->rawCommand('SET', $key, $ts, 'NX', 'EX', self::TTL_GENERATION);
            }

            $pipe->exec();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Set view count (V6 - no-op, kept for compatibility)
     *
     * @deprecated V6 uses events, not counters
     */
    public function setViews(int $postId, int $count): bool
    {
        // V6: No-op - generation tracking doesn't need initialization
        return true;
    }

    /**
     * Reset view count after flush (V6 - no-op!)
     *
     * ENTERPRISE V6 (2025-11-29): NO LONGER NEEDED!
     * With generational overlay, we DON'T reset after flush.
     * The delta is calculated from generation timestamp, not a counter.
     * Events remain in sorted set until natural TTL expiry.
     *
     * @deprecated V6 doesn't reset - kept for API compatibility
     */
    public function resetViewsAfterFlush(int $postId, int $flushedCount): bool
    {
        // V6: No-op - generation tracking handles this automatically
        // The flush worker updates DB, but overlay events stay.
        // When cache reloads, new generation timestamp means delta = 0.
        return true;
    }

    /**
     * Get total event count for flush (V6 GENERATIONAL)
     *
     * Used by flush worker to know how many views to add to DB.
     *
     * @param int $postId Post ID
     * @param float|null $sinceTimestamp Only count events since this time
     * @return int Total view count
     */
    public function getViewsForFlush(int $postId, ?float $sinceTimestamp = null): int
    {
        if (!$this->redis) return 0;

        try {
            $eventKey = self::GEN_OVERLAY_PREFIX . "views:{$postId}";

            if ($sinceTimestamp !== null) {
                return (int)$this->redis->zCount($eventKey, (string)$sinceTimestamp, '+inf');
            }

            return (int)$this->redis->zCard($eventKey);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get dirty view posts for flush (V6 GENERATIONAL)
     *
     * @param int $limit Max posts to return
     * @return array Array of post IDs
     */
    public function getDirtyViewPosts(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . "views";
            $posts = $this->redis->zRange($dirtyKey, 0, $limit - 1);
            return array_map('intval', $posts ?: []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Clear dirty marker for posts (V6 GENERATIONAL)
     *
     * @param array $postIds Posts that were flushed
     * @return bool Success
     */
    public function clearDirtyViewPosts(array $postIds): bool
    {
        if (!$this->redis || empty($postIds)) return false;

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . "views";
            $this->redis->zRem($dirtyKey, ...$postIds);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Prune old view events (V6 GENERATIONAL - memory management)
     *
     * @param int $postId Post ID
     * @return int Number of events pruned
     */
    public function pruneOldViewEvents(int $postId): int
    {
        if (!$this->redis) return 0;

        try {
            $eventKey = self::GEN_OVERLAY_PREFIX . "views:{$postId}";
            $threshold = microtime(true) - self::PRUNE_THRESHOLD;
            return (int)$this->redis->zRemRangeByScore($eventKey, '-inf', (string)$threshold);
        } catch (\Exception $e) {
            return 0;
        }
    }

    // =========================================================================
    // COMMENT COUNT OPERATIONS - ENTERPRISE V11 ABSOLUTE VALUE (2025-12-11)
    // =========================================================================
    //
    // V11 FIX: Switched from DELTA system to ABSOLUTE VALUE system (like reactions)
    //
    // THE BUG (V4-V10 Delta System):
    //   T1: Comment added → overlay delta=+1 → display = DB(2) + 1 = 3 ✓
    //   T2: Flush executed → DB updated to 3, overlay delta reset to 0
    //   T3: Page reload → display = CACHED_DB(2) + 0 = 2 ✗ WRONG!
    //   T4: Cache expires → display = DB(3) + 0 = 3 ✓
    //
    // THE FIX (V11 Absolute Value):
    //   T1: Comment added → overlay = 3 (absolute) → display = 3 ✓
    //   T2: Flush executed → DB = 3, DELETE overlay key
    //   T3: Page reload → overlay NULL → display = DB(3) = 3 ✓
    //
    // PATTERN: "Overlay wins" - if overlay exists, use it; else use DB
    // =========================================================================

    /**
     * Increment comment count (V11.3 ABSOLUTE VALUE - NO DOUBLE COUNT)
     *
     * ENTERPRISE V11.3 (2025-12-11): Fixed double-counting bug!
     *
     * THE BUG (V11-V11.2):
     *   1. INSERT comment → DB has N comments
     *   2. incrementCommentAbsolute() called
     *   3. Overlay doesn't exist → warmCommentOverlay() does COUNT(*) = N
     *   4. incrBy(1) → overlay = N+1 → WRONG! (double count)
     *
     * THE FIX (V11.3):
     *   - If overlay doesn't exist → warm from DB and DON'T increment!
     *   - COUNT(*) already reflects current state (including just-inserted record)
     *   - If overlay exists → increment normally (state is from BEFORE this operation)
     *
     * @param int $postId Post ID
     * @param int $delta Amount to increment (default 1)
     * @return int New absolute count
     */
    public function incrementCommentAbsolute(int $postId, int $delta = 1): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:abs:comments:{$postId}";

            // V11.3: Check if overlay exists BEFORE any operation
            $overlayExisted = $this->redis->exists($key);

            if (!$overlayExisted) {
                // Overlay doesn't exist - warm from DB
                // DON'T increment! COUNT(*) already includes the just-inserted record
                $this->warmCommentOverlay($postId);

                // Get the warmed value (this IS the correct count)
                $currentCount = (int)$this->redis->get($key);

                // Mark as dirty for flush
                $this->redis->zAdd(self::GEN_DIRTY_PREFIX . 'comments:abs', microtime(true), (string)$postId);

                Logger::overlay('debug', 'V11.3 Comment increment: warmed from DB (no increment)', [
                    'post_id' => $postId,
                    'count' => $currentCount,
                ]);

                return $currentCount;
            }

            // Overlay existed - increment normally
            // The overlay value is from BEFORE this operation, so increment is correct
            $newCount = $this->redis->incrBy($key, $delta);
            $this->redis->expire($key, self::TTL_COMMENTS);

            // Mark as dirty for flush
            $this->redis->zAdd(self::GEN_DIRTY_PREFIX . 'comments:abs', microtime(true), (string)$postId);

            Logger::overlay('debug', 'V11.3 Comment increment: overlay existed, incremented', [
                'post_id' => $postId,
                'delta' => $delta,
                'new_count' => $newCount,
            ]);

            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::incrementCommentAbsolute failed', [
                'post_id' => $postId,
                'delta' => $delta,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Decrement comment count (V11.3 ABSOLUTE VALUE - NO DOUBLE COUNT)
     *
     * ENTERPRISE V11.3 (2025-12-11): Fixed double-counting bug!
     *
     * THE BUG (V11-V11.2):
     *   1. DELETE/UPDATE comment → DB has N comments (the deleted one is excluded from COUNT)
     *   2. decrementCommentAbsolute() called
     *   3. Overlay doesn't exist → warmCommentOverlay() does COUNT(*) = N (already EXCLUDES deleted)
     *   4. decrBy(1) → overlay = N-1 → WRONG! (double decrement)
     *
     * THE FIX (V11.3):
     *   - If overlay doesn't exist → warm from DB and DON'T decrement!
     *   - COUNT(*) already reflects current state (excluding just-deleted record)
     *   - If overlay exists → decrement normally (state is from BEFORE this operation)
     *
     * @param int $postId Post ID
     * @param int $delta Amount to decrement (default 1)
     * @return int New absolute count
     */
    public function decrementCommentAbsolute(int $postId, int $delta = 1): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:abs:comments:{$postId}";

            // V11.3: Check if overlay exists BEFORE any operation
            $overlayExisted = $this->redis->exists($key);

            if (!$overlayExisted) {
                // Overlay doesn't exist - warm from DB
                // DON'T decrement! COUNT(*) already excludes the just-deleted record
                $this->warmCommentOverlay($postId);

                // Get the warmed value (this IS the correct count)
                $currentCount = (int)$this->redis->get($key);

                // Mark as dirty for flush
                $this->redis->zAdd(self::GEN_DIRTY_PREFIX . 'comments:abs', microtime(true), (string)$postId);

                Logger::overlay('debug', 'V11.3 Comment decrement: warmed from DB (no decrement)', [
                    'post_id' => $postId,
                    'count' => $currentCount,
                ]);

                return $currentCount;
            }

            // Overlay existed - decrement normally
            // The overlay value is from BEFORE this operation, so decrement is correct
            $newCount = $this->redis->decrBy($key, $delta);

            // Don't allow negative counts
            if ($newCount < 0) {
                $this->redis->set($key, 0);
                $newCount = 0;
            }

            $this->redis->expire($key, self::TTL_COMMENTS);

            // Mark as dirty for flush
            $this->redis->zAdd(self::GEN_DIRTY_PREFIX . 'comments:abs', microtime(true), (string)$postId);

            Logger::overlay('debug', 'V11.3 Comment decrement: overlay existed, decremented', [
                'post_id' => $postId,
                'delta' => $delta,
                'new_count' => $newCount,
            ]);

            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::decrementCommentAbsolute failed', [
                'post_id' => $postId,
                'delta' => $delta,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get comment count (V11 ABSOLUTE VALUE)
     *
     * Returns absolute count or null (fallback to DB).
     *
     * @param int $postId Post ID
     * @return int|null Absolute count, or null if no overlay (use DB)
     */
    public function getCommentAbsolute(int $postId): ?int
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:abs:comments:{$postId}";
            $value = $this->redis->get($key);

            if ($value === false) {
                return null; // No overlay - caller should use DB
            }

            return (int)$value;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get comment counts for multiple posts (V11 ABSOLUTE VALUE - batch)
     *
     * Returns [postId => count|null]. Null means use DB value.
     *
     * @param array $postIds Array of post IDs
     * @return array [postId => int|null]
     */
    public function getBatchCommentAbsolutes(array $postIds): array
    {
        if (!$this->redis || empty($postIds)) {
            return array_fill_keys($postIds, null);
        }

        try {
            // Build keys
            $keys = [];
            foreach ($postIds as $postId) {
                $keys[] = "overlay:abs:comments:{$postId}";
            }

            // MGET all values
            $values = $this->redis->mGet($keys);

            // Build results
            $results = [];
            foreach ($postIds as $i => $postId) {
                $value = $values[$i] ?? false;
                $results[$postId] = ($value !== false) ? (int)$value : null;
            }

            return $results;
        } catch (\Exception $e) {
            return array_fill_keys($postIds, null);
        }
    }

    /**
     * Check if comment overlay exists (V11)
     *
     * @param int $postId Post ID
     * @return bool True if overlay exists
     */
    public function hasCommentOverlay(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->exists("overlay:abs:comments:{$postId}") > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete comment overlay after flush (V11)
     *
     * Called by OverlayFlushService after writing to DB.
     * Forces next read to fallback to fresh DB value.
     *
     * @param int $postId Post ID
     * @return bool Success
     */
    public function deleteCommentOverlay(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            $this->redis->del("overlay:abs:comments:{$postId}");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Warm comment overlay from DB (V11 - private helper)
     *
     * ENTERPRISE V11.1 (2025-12-11): Count REAL comments, not denormalized field!
     * audio_posts.comment_count can be stale. COUNT(*) is the source of truth.
     */
    private function warmCommentOverlay(int $postId): void
    {
        try {
            $db = db();

            // V11.1: COUNT real comments - NOT the denormalized comment_count field!
            // The denormalized field can be stale, causing wrong counts after warm.
            $row = $db->findOne(
                "SELECT COUNT(*) as real_count FROM audio_comments WHERE audio_post_id = ?",
                [$postId]
            );

            $count = (int)($row['real_count'] ?? 0);

            // Initialize overlay with REAL count
            $key = "overlay:abs:comments:{$postId}";
            $this->redis->setex($key, self::TTL_COMMENTS, $count);

            Logger::overlay('debug', 'V11.1 Comment overlay warmed from COUNT(*)', [
                'post_id' => $postId,
                'real_count' => $count,
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::warmCommentOverlay failed', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get dirty comment posts for flush (V11)
     *
     * @param int $limit Max posts to return
     * @return array Array of post IDs
     */
    public function getDirtyCommentPostsAbsolute(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . 'comments:abs';
            $posts = $this->redis->zRange($dirtyKey, 0, $limit - 1);
            return array_map('intval', $posts ?: []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Clear dirty markers for comment posts (V11)
     *
     * @param array $postIds Posts that were flushed
     * @return bool Success
     */
    public function clearDirtyCommentPostsAbsolute(array $postIds): bool
    {
        if (!$this->redis || empty($postIds)) return false;

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . 'comments:abs';
            $this->redis->zRem($dirtyKey, ...$postIds);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // LEGACY COMMENT COUNT (V4 Delta) - DEPRECATED, kept for transition
    // =========================================================================

    /**
     * @deprecated Use incrementCommentAbsolute() instead
     */
    public function incrementCommentCount(int $postId, int $delta = 1): int
    {
        // V11: Redirect to new absolute method
        return $this->incrementCommentAbsolute($postId, $delta);
    }

    /**
     * @deprecated Use getCommentAbsolute() instead
     */
    public function getCommentCount(int $postId): int
    {
        // V11: For backwards compatibility, return 0 if no overlay
        return $this->getCommentAbsolute($postId) ?? 0;
    }

    /**
     * @deprecated V11 uses absolute values, not delta set
     */
    public function setCommentCount(int $postId, int $delta): bool
    {
        // V11: No-op for legacy compatibility
        return true;
    }

    // =========================================================================
    // PLAY COUNT OPERATIONS - ENTERPRISE V11 ABSOLUTE VALUE (2025-12-11)
    // =========================================================================
    //
    // V11 FIX: Switched from DELTA/EVENT system to ABSOLUTE VALUE (like reactions)
    //
    // THE BUG (V6 Generational Event System):
    //   T1: Play recorded → event in sorted set → delta = ZCARD = 1
    //   T2: Flush → DB += 1, events deleted
    //   T3: Page reload → delta = ZCARD = 0, display = CACHED_DB(old) + 0 = WRONG!
    //
    // THE FIX (V11 Absolute Value):
    //   T1: Play recorded → overlay = DB + 1 (absolute) → display = overlay ✓
    //   T2: Flush → DB = overlay value, DELETE overlay key
    //   T3: Page reload → overlay NULL → display = DB (fresh) ✓
    //
    // PATTERN: "Overlay wins" - if overlay exists, use it; else use DB
    // =========================================================================

    /**
     * Increment play count (V11 ABSOLUTE VALUE)
     *
     * Like reactions: stores ABSOLUTE count, not delta.
     * Warms from DB if overlay doesn't exist.
     *
     * @param int $audioFileId Audio file ID
     * @param int $userId User ID (for analytics, stored separately)
     * @return int New absolute count
     */
    public function incrementPlayAbsolute(int $audioFileId, int $userId = 0): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:abs:plays:{$audioFileId}";

            // V11: If overlay doesn't exist, warm from DB first
            if (!$this->redis->exists($key)) {
                $this->warmPlayOverlay($audioFileId);
            }

            // Increment absolute value
            $newCount = $this->redis->incr($key);
            $this->redis->expire($key, self::TTL_PLAYS);

            // Mark as dirty for flush
            $this->redis->zAdd(self::GEN_DIRTY_PREFIX . 'plays:abs', microtime(true), (string)$audioFileId);

            return $newCount;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::incrementPlayAbsolute failed', [
                'audio_file_id' => $audioFileId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get play count (V11 ABSOLUTE VALUE)
     *
     * Returns absolute count or null (fallback to DB).
     *
     * @param int $audioFileId Audio file ID
     * @return int|null Absolute count, or null if no overlay (use DB)
     */
    public function getPlayAbsolute(int $audioFileId): ?int
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:abs:plays:{$audioFileId}";
            $value = $this->redis->get($key);

            if ($value === false) {
                return null; // No overlay - caller should use DB
            }

            return (int)$value;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get play counts for multiple audio files (V11 ABSOLUTE VALUE - batch)
     *
     * Returns [fileId => count|null]. Null means use DB value.
     *
     * @param array $audioFileIds Array of audio file IDs
     * @return array [audioFileId => int|null]
     */
    public function getBatchPlayAbsolutes(array $audioFileIds): array
    {
        if (!$this->redis || empty($audioFileIds)) {
            return array_fill_keys($audioFileIds, null);
        }

        try {
            // Build keys
            $keys = [];
            foreach ($audioFileIds as $fileId) {
                $keys[] = "overlay:abs:plays:{$fileId}";
            }

            // MGET all values
            $values = $this->redis->mGet($keys);

            // Build results
            $results = [];
            foreach ($audioFileIds as $i => $fileId) {
                $value = $values[$i] ?? false;
                $results[$fileId] = ($value !== false) ? (int)$value : null;
            }

            return $results;
        } catch (\Exception $e) {
            return array_fill_keys($audioFileIds, null);
        }
    }

    /**
     * Check if play overlay exists (V11)
     *
     * @param int $audioFileId Audio file ID
     * @return bool True if overlay exists
     */
    public function hasPlayOverlay(int $audioFileId): bool
    {
        if (!$this->redis) return false;

        try {
            return $this->redis->exists("overlay:abs:plays:{$audioFileId}") > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete play overlay after flush (V11)
     *
     * Called by OverlayFlushService after writing to DB.
     * Forces next read to fallback to fresh DB value.
     *
     * @param int $audioFileId Audio file ID
     * @return bool Success
     */
    public function deletePlayOverlay(int $audioFileId): bool
    {
        if (!$this->redis) return false;

        try {
            $this->redis->del("overlay:abs:plays:{$audioFileId}");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Warm play overlay from DB (V11 - private helper)
     *
     * Loads current play_count from DB into Redis overlay.
     */
    private function warmPlayOverlay(int $audioFileId): void
    {
        try {
            $db = db();
            $row = $db->findOne(
                "SELECT play_count FROM audio_files WHERE id = ?",
                [$audioFileId]
            );

            $count = (int)($row['play_count'] ?? 0);

            // Initialize overlay with DB value
            $key = "overlay:abs:plays:{$audioFileId}";
            $this->redis->setex($key, self::TTL_PLAYS, $count);

        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::warmPlayOverlay failed', [
                'audio_file_id' => $audioFileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get dirty audio files for flush (V11)
     *
     * @param int $limit Max files to return
     * @return array Array of audio file IDs
     */
    public function getDirtyPlayFilesAbsolute(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . 'plays:abs';
            $files = $this->redis->zRange($dirtyKey, 0, $limit - 1);
            return array_map('intval', $files ?: []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Clear dirty markers for play files (V11)
     *
     * @param array $audioFileIds Files that were flushed
     * @return bool Success
     */
    public function clearDirtyPlayFilesAbsolute(array $audioFileIds): bool
    {
        if (!$this->redis || empty($audioFileIds)) return false;

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . 'plays:abs';
            $this->redis->zRem($dirtyKey, ...$audioFileIds);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // LEGACY PLAY COUNT (V6 Generational) - DEPRECATED, kept for transition
    // =========================================================================

    /**
     * Record a play event (V6 GENERATIONAL)
     *
     * @deprecated V11 uses incrementPlayAbsolute() instead
     *
     * Stores play events in sorted set with timestamp for generation-based delta.
     * This solves the flush-reset bug where counts were wrong after flush.
     *
     * @param int $audioFileId Audio file ID
     * @param int $userId User ID (0 for anonymous)
     * @return bool Success
     */
    public function recordPlay(int $audioFileId, int $userId): bool
    {
        if (!$this->redis) return false;

        try {
            $now = microtime(true);
            $eventKey = self::GEN_OVERLAY_PREFIX . "plays:{$audioFileId}";
            $dirtyKey = self::GEN_DIRTY_PREFIX . "plays";

            // Event value: userId:timestamp (unique per play)
            $eventValue = "{$userId}:{$now}";

            // Pipeline for atomicity
            $pipe = $this->redis->pipeline();
            $pipe->zAdd($eventKey, $now, $eventValue);
            $pipe->expire($eventKey, self::TTL_EVENTS);
            $pipe->zAdd($dirtyKey, $now, (string)$audioFileId);
            $pipe->exec();

            return true;
        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayService::recordPlay failed', [
                'audio_file_id' => $audioFileId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get play count delta since cache generation (V6 GENERATIONAL)
     *
     * @param int $audioFileId Audio file ID
     * @param float|null $generationTs Cache generation timestamp
     * @return int Delta
     */
    public function getPlaysDelta(int $audioFileId, ?float $generationTs = null): int
    {
        if (!$this->redis) return 0;

        try {
            // Get generation timestamp if not provided
            if ($generationTs === null) {
                $genKey = self::GEN_CACHE_PREFIX . "file:{$audioFileId}";
                $generationTs = $this->redis->get($genKey);
                if ($generationTs === false) {
                    $generationTs = 0; // No generation = count all events
                }
            }

            // Count events since generation
            $eventKey = self::GEN_OVERLAY_PREFIX . "plays:{$audioFileId}";
            return (int)$this->redis->zCount($eventKey, (string)$generationTs, '+inf');
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get play deltas for multiple audio files (V6.3 - ALL EVENTS, NO FLUSH TIMESTAMP)
     *
     * ENTERPRISE V6.3 (2025-11-29): Count ALL events in sorted set.
     *
     * The flush worker NO LONGER sets a flush timestamp. Instead:
     * 1. Flush: Reads total event count via ZCARD
     * 2. Flush: Increments DB by that count
     * 3. Flush: DELETES events from sorted set (not just mark with timestamp)
     * 4. New events accumulate in (now empty) sorted set
     *
     * This ensures:
     * - Cache stale (old DB) + all events = correct total
     * - Cache fresh (new DB) + all events = correct total (events were deleted after DB update)
     *
     * NO CACHE INVALIDATION NEEDED!
     *
     * @param array $audioFileIds Array of audio file IDs
     * @return array [audioFileId => delta, ...]
     */
    public function getBatchPlaysDeltas(array $audioFileIds): array
    {
        if (!$this->redis || empty($audioFileIds)) {
            return array_fill_keys($audioFileIds, 0);
        }

        try {
            // V6.3: Simply count ALL events (no flush timestamp filtering)
            $pipe = $this->redis->pipeline();
            foreach ($audioFileIds as $fileId) {
                $eventKey = self::GEN_OVERLAY_PREFIX . "plays:{$fileId}";
                $pipe->zCard($eventKey);
            }
            $counts = $pipe->exec();

            // Build results
            $results = [];
            foreach ($audioFileIds as $i => $fileId) {
                $results[$fileId] = (int)($counts[$i] ?? 0);
            }

            return $results;
        } catch (\Exception $e) {
            return array_fill_keys($audioFileIds, 0);
        }
    }

    /**
     * Set audio file cache generation timestamp (V6)
     *
     * @param int $audioFileId Audio file ID
     * @param float|null $timestamp Timestamp (null = now)
     * @return bool Success
     */
    public function setFileGeneration(int $audioFileId, ?float $timestamp = null): bool
    {
        if (!$this->redis) return false;

        try {
            $ts = $timestamp ?? microtime(true);
            $key = self::GEN_CACHE_PREFIX . "file:{$audioFileId}";
            $this->redis->setex($key, self::TTL_GENERATION, (string)$ts);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Set batch audio file cache generations (V6) - SET IF NOT EXISTS
     *
     * CRITICAL: Uses SETNX to NOT overwrite existing generations!
     * If generation exists, events are counted from that timestamp.
     * If we overwrote it, we'd lose track of events since original load.
     *
     * @param array $audioFileIds Array of audio file IDs
     * @param float|null $timestamp Timestamp (null = now)
     * @return bool Success
     */
    public function setBatchFileGenerations(array $audioFileIds, ?float $timestamp = null): bool
    {
        if (!$this->redis || empty($audioFileIds)) return false;

        try {
            $ts = (string)($timestamp ?? microtime(true));
            $pipe = $this->redis->pipeline();
            foreach ($audioFileIds as $fileId) {
                $key = self::GEN_CACHE_PREFIX . "file:{$fileId}";
                // SETNX then EXPIRE: Pipeline-safe way to set only if not exists
                $pipe->rawCommand('SET', $key, $ts, 'NX', 'EX', self::TTL_GENERATION);
            }
            $pipe->exec();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Increment play count delta (V6 - calls recordPlay internally)
     *
     * @deprecated Use recordPlay() for proper generation tracking
     */
    public function incrementPlayCount(int $audioFileId, int $delta = 1): int
    {
        // V6: Record as events instead of simple increment
        for ($i = 0; $i < $delta; $i++) {
            $this->recordPlay($audioFileId, 0);
        }
        return $delta;
    }

    /**
     * Get play count delta (V6 - alias for getPlaysDelta)
     *
     * @deprecated Use getPlaysDelta() for clarity
     */
    public function getPlayCount(int $audioFileId): int
    {
        return $this->getPlaysDelta($audioFileId);
    }

    /**
     * Set play count delta (V6 - no-op, generations handle this)
     *
     * @deprecated V6 doesn't need explicit set - generations handle it
     */
    public function setPlayCount(int $audioFileId, int $delta): bool
    {
        // V6: No-op - generations handle delta calculation automatically
        return true;
    }

    // =========================================================================
    // V6 GENERATIONAL: PLAY FLUSH METHODS (for OverlayFlushService)
    // =========================================================================

    /**
     * Get dirty audio files that have pending play events (V6)
     *
     * @param int $limit Max files to return
     * @return array Array of audio file IDs
     */
    public function getDirtyPlayFiles(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . "plays";
            $files = $this->redis->zRange($dirtyKey, 0, $limit - 1);
            return array_map('intval', $files ?: []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get total play count for flush (V6)
     *
     * @param int $audioFileId Audio file ID
     * @return int Total play events to flush
     */
    public function getPlaysForFlush(int $audioFileId): int
    {
        if (!$this->redis) return 0;

        try {
            $eventKey = self::GEN_OVERLAY_PREFIX . "plays:{$audioFileId}";
            return (int)$this->redis->zCard($eventKey);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get play count and max score for V6.2 flush
     *
     * Returns both the count of events and the max timestamp (score).
     * The max score should be used as flush timestamp so that
     * getBatchPlaysDeltas() correctly excludes flushed events.
     *
     * @param int $audioFileId Audio file ID
     * @return array ['count' => int, 'max_score' => float]
     */
    public function getPlaysForFlushWithMaxScore(int $audioFileId): array
    {
        if (!$this->redis) {
            return ['count' => 0, 'max_score' => 0.0];
        }

        try {
            $eventKey = self::GEN_OVERLAY_PREFIX . "plays:{$audioFileId}";

            // Pipeline: get count and max element in single round trip
            $pipe = $this->redis->pipeline();
            $pipe->zCard($eventKey);
            $pipe->zRange($eventKey, -1, -1, true); // Get last element with score
            $results = $pipe->exec();

            $count = (int)($results[0] ?? 0);
            $maxScore = 0.0;

            // zRange with WITHSCORES returns [member => score]
            if (!empty($results[1]) && is_array($results[1])) {
                $maxScore = (float)array_values($results[1])[0];
            }

            return ['count' => $count, 'max_score' => $maxScore];
        } catch (\Exception $e) {
            return ['count' => 0, 'max_score' => 0.0];
        }
    }

    /**
     * Clear dirty markers for play files (V6)
     *
     * @param array $audioFileIds Files that were flushed
     * @return bool Success
     */
    public function clearDirtyPlayFiles(array $audioFileIds): bool
    {
        if (!$this->redis || empty($audioFileIds)) return false;

        try {
            $dirtyKey = self::GEN_DIRTY_PREFIX . "plays";
            $this->redis->zRem($dirtyKey, ...$audioFileIds);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear play events after flush (V6) - DEPRECATED in V6.2
     *
     * @deprecated Use setPlayFlushTimestamp() instead - V6.2 doesn't delete events
     * @param int $audioFileId Audio file ID
     * @return int Number of events cleared
     */
    public function clearPlayEvents(int $audioFileId): int
    {
        // V6.2: Don't delete events! Use setPlayFlushTimestamp() instead
        // This method is kept for backwards compatibility but does nothing
        return 0;
    }

    /**
     * Set flush timestamp for play events (V6.2)
     *
     * ENTERPRISE V6.2: Instead of deleting events after flush, we set a timestamp.
     * getBatchPlaysDeltas() will only count events AFTER this timestamp.
     *
     * Benefits:
     * - NO cache invalidation needed
     * - Old cached data + events since flush = correct count
     * - Events auto-expire via Redis TTL (24h)
     *
     * @param int $audioFileId Audio file ID
     * @param float|null $timestamp Flush timestamp (null = now)
     * @return bool Success
     */
    public function setPlayFlushTimestamp(int $audioFileId, ?float $timestamp = null): bool
    {
        if (!$this->redis) return false;

        try {
            $ts = $timestamp ?? microtime(true);
            $flushKey = self::GEN_FLUSH_PREFIX . "plays:{$audioFileId}";
            // TTL: 24 hours (events older than this are auto-pruned anyway)
            $this->redis->setex($flushKey, self::TTL_EVENTS, (string)$ts);
            return true;
        } catch (\Exception $e) {
            Logger::overlay('error', 'setPlayFlushTimestamp failed', [
                'audio_file_id' => $audioFileId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Prune old play events (V6.2)
     *
     * Called periodically to clean up events older than flush timestamp.
     * This is optional - events will auto-expire via TTL anyway.
     *
     * @param int $audioFileId Audio file ID
     * @return int Number of events pruned
     */
    public function pruneOldPlayEvents(int $audioFileId): int
    {
        if (!$this->redis) return 0;

        try {
            // Get flush timestamp
            $flushKey = self::GEN_FLUSH_PREFIX . "plays:{$audioFileId}";
            $flushTs = $this->redis->get($flushKey);

            if ($flushTs === false || $flushTs <= 0) {
                return 0; // No flush yet, keep all events
            }

            // Remove events with score <= flushTs (already flushed to DB)
            $eventKey = self::GEN_OVERLAY_PREFIX . "plays:{$audioFileId}";
            return (int)$this->redis->zRemRangeByScore($eventKey, '-inf', $flushTs);
        } catch (\Exception $e) {
            return 0;
        }
    }

    // =========================================================================
    // PERSONAL DATA OPERATIONS (User-Specific)
    // =========================================================================

    /**
     * Set user's reaction to a post
     */
    public function setUserReaction(int $userId, int $postId, int $emotionId): bool
    {
        if (!$this->redis) {
            Logger::overlay('warning', 'OverlayService::setUserReaction - Redis not available', [
                'user_id' => $userId,
                'post_id' => $postId,
                'emotion_id' => $emotionId,
            ]);
            return false;
        }

        try {
            $key = "personal:{$userId}:rx:{$postId}";
            $result = $this->redis->setex($key, self::TTL_PERSONAL, $emotionId);

            if (!$result) {
                Logger::overlay('warning', 'OverlayService::setUserReaction - Redis SETEX failed', [
                    'user_id' => $userId,
                    'post_id' => $postId,
                    'emotion_id' => $emotionId,
                    'key' => $key,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Logger::overlay('warning', 'OverlayService::setUserReaction - Exception', [
                'user_id' => $userId,
                'post_id' => $postId,
                'emotion_id' => $emotionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get user's reaction to a post
     *
     * ENTERPRISE V4: Returns emotion_id, 0 (tombstone=removed), or null (no data)
     *
     * @return int|null emotion_id (>0), 0 (tombstone=removed), or null (no overlay data)
     */
    public function getUserReaction(int $userId, int $postId): ?int
    {
        if (!$this->redis) return null;

        try {
            $key = "personal:{$userId}:rx:{$postId}";
            $value = $this->redis->get($key);

            if ($value === false) {
                return null; // No data in overlay - caller should fallback to DB
            }

            // Return the value (could be 0=tombstone or >0=emotion_id)
            return (int)$value;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Remove user's reaction from overlay
     *
     * ENTERPRISE V4: Uses tombstone (value=0) instead of DELETE
     * This allows distinguishing between:
     * - null (no overlay data, fallback to DB)
     * - 0 (tombstone: removed, don't fallback to DB)
     * - >0 (active emotion_id)
     */
    public function removeUserReaction(int $userId, int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            // Set tombstone (0) instead of DELETE - allows detecting "removed" vs "no data"
            // TTL matches the flush interval + buffer (10 minutes should be plenty)
            return $this->redis->setex("personal:{$userId}:rx:{$postId}", 600, '0');
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Get raw Redis connection for advanced operations (pipeline, etc.)
     */
    public function getRedisConnection(): ?\Redis
    {
        return $this->redis;
    }

    /**
     * Clear all overlays for a post (used when post is permanently deleted)
     *
     * ENTERPRISE V4: Includes all overlay types (views, comments, plays, reactions)
     */
    public function clearPostOverlays(int $postId): bool
    {
        if (!$this->redis) return false;

        try {
            $keys = [
                "overlay:{$postId}:tombstone",
                "overlay:{$postId}:patch",
                "overlay:{$postId}:reactions",
                "overlay:{$postId}:views",
                "overlay:{$postId}:comments",  // ENTERPRISE V4
            ];
            $this->redis->del($keys);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear all overlays for an audio file (used when file is permanently deleted)
     *
     * ENTERPRISE V4: Separate method for file overlays (play_count)
     */
    public function clearFileOverlays(int $audioFileId): bool
    {
        if (!$this->redis) return false;

        try {
            $keys = [
                "overlay:file:{$audioFileId}:plays",
            ];
            $this->redis->del($keys);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
