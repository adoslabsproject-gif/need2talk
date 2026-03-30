<?php

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * OverlayBatchLoader - Redis Pipeline Batch Loading
 *
 * ENTERPRISE GALAXY V11 (2025-12-11) - ABSOLUTE VALUE SYSTEM
 *
 * Uses Redis pipeline to load overlay data for multiple posts in a single
 * round-trip. This dramatically reduces latency for feed loading:
 *
 * Before: 20 posts × 6 overlay keys = 120 Redis round-trips (~120ms)
 * After:  20 posts × 6 overlay keys = 1 pipeline (~5ms)
 *
 * Loads per post:
 * - overlay:{id}:tombstone → soft delete marker
 * - overlay:{id}:patch → content updates
 * - overlay:{id}:reactions → HASH {emotion_id: count} (ABSOLUTE)
 * - overlay:abs:plays:{audioFileId} → play count (V11 ABSOLUTE)
 * - overlay:abs:comments:{postId} → comment count (V11 ABSOLUTE)
 * - personal:{userId}:rx:{postId} → user's reaction (if userId provided)
 *
 * V11 FIX - ABSOLUTE VALUE PATTERN:
 * ════════════════════════════════════════════════════════════════════════════
 * Previous versions used DELTA system:
 *   display = DB_base + overlay_delta
 *
 * This had timing bugs between flush and cache reload:
 *   T1: Play → delta=+1 → display = DB(5) + 1 = 6 ✓
 *   T2: Flush → DB=6, delta reset
 *   T3: Reload → display = CACHED_DB(5) + 0 = 5 ✗ WRONG!
 *
 * V11 uses ABSOLUTE values (like reactions):
 *   display = overlay ?? DB (overlay wins if present)
 *
 *   T1: Play → overlay=6 (absolute) → display = 6 ✓
 *   T2: Flush → DB=6, DELETE overlay
 *   T3: Reload → overlay=NULL → display = DB(6) ✓
 * ════════════════════════════════════════════════════════════════════════════
 *
 * @package Need2Talk\Services\Cache
 */
class OverlayBatchLoader
{
    private static ?self $instance = null;
    private ?\Redis $redis = null;

    // V11: Number of keys per post (without user context)
    // tombstone, patch, reactions, plays_absolute, comments_absolute
    private const KEYS_PER_POST = 5;
    // V11: Number of keys per post (with user context)
    // + user_reaction
    private const KEYS_PER_POST_WITH_USER = 6;

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
     * Load overlays for multiple posts in a single pipeline
     *
     * V11 ABSOLUTE VALUE: Loads absolute counts for plays and comments.
     * Returns null for counts if overlay doesn't exist (use DB value).
     *
     * @param array $postIds Array of post IDs to load
     * @param int|null $userId Current user ID (optional, for loading user reactions)
     * @param array $audioFileIds Map of postId => audioFileId for play count lookup
     * @return array [postId => ['tombstone' => bool, 'patch' => array|null, 'reactions' => array,
     *                           'play_count' => int|null, 'comment_count' => int|null, 'user_reaction' => int|null], ...]
     */
    public function loadBatch(array $postIds, ?int $userId = null, array $audioFileIds = []): array
    {
        if (!$this->redis || empty($postIds)) {
            return $this->getEmptyResults($postIds);
        }

        try {
            // Use pipeline for single round-trip
            $pipe = $this->redis->pipeline();

            foreach ($postIds as $postId) {
                $pipe->get("overlay:{$postId}:tombstone");
                $pipe->get("overlay:{$postId}:patch");
                $pipe->hGetAll("overlay:{$postId}:reactions");

                // V11 ABSOLUTE: Load play count (uses audioFileId, not postId)
                $audioFileId = $audioFileIds[$postId] ?? $postId; // Fallback to postId if not mapped
                $pipe->get("overlay:abs:plays:{$audioFileId}");

                // V11 ABSOLUTE: Load comment count
                $pipe->get("overlay:abs:comments:{$postId}");

                if ($userId !== null) {
                    $pipe->get("personal:{$userId}:rx:{$postId}");
                }
            }

            // Execute pipeline - single round-trip!
            $results = $pipe->exec();

            return $this->parseResults($postIds, $results, $userId !== null, $audioFileIds);

        } catch (\Exception $e) {
            Logger::overlay('error', 'OverlayBatchLoader::loadBatch failed', [
                'post_count' => count($postIds),
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->getEmptyResults($postIds);
        }
    }

    /**
     * Parse pipeline results into structured array
     *
     * V11: Returns null for play_count/comment_count if overlay doesn't exist.
     * This signals to applyOverlays() to use DB value instead.
     */
    private function parseResults(array $postIds, array $results, bool $hasUserId, array $audioFileIds = []): array
    {
        $keysPerPost = $hasUserId ? self::KEYS_PER_POST_WITH_USER : self::KEYS_PER_POST;
        $output = [];

        foreach ($postIds as $index => $postId) {
            $offset = $index * $keysPerPost;

            // Parse tombstone (string "1" or false)
            $tombstoneRaw = $results[$offset] ?? false;
            $hasTombstone = $tombstoneRaw === '1';

            // Parse patch (JSON string or false)
            $patchRaw = $results[$offset + 1] ?? false;
            $patch = $patchRaw ? json_decode($patchRaw, true) : null;

            // Parse reactions (array or empty)
            // NOTE: Reactions are ABSOLUTE values - "overlay wins" if present
            $reactionsRaw = $results[$offset + 2] ?? [];
            $reactions = [];
            if (is_array($reactionsRaw)) {
                foreach ($reactionsRaw as $emotionId => $count) {
                    if ($emotionId === '_init') continue; // Skip placeholder
                    $reactions[(int)$emotionId] = (int)$count;
                }
            }

            // V11 ABSOLUTE: Parse play count (null if not exists = use DB)
            $playCountRaw = $results[$offset + 3] ?? false;
            $playCount = ($playCountRaw !== false) ? (int)$playCountRaw : null;

            // V11 ABSOLUTE: Parse comment count (null if not exists = use DB)
            $commentCountRaw = $results[$offset + 4] ?? false;
            $commentCount = ($commentCountRaw !== false) ? (int)$commentCountRaw : null;

            // Parse user reaction if present
            $userReaction = null;
            if ($hasUserId) {
                $userReactionRaw = $results[$offset + 5] ?? false;
                $userReaction = $userReactionRaw !== false ? (int)$userReactionRaw : null;
            }

            $output[$postId] = [
                'tombstone' => $hasTombstone,
                'patch' => $patch,
                'reactions' => $reactions,
                'play_count' => $playCount,      // V11: null = use DB
                'comment_count' => $commentCount, // V11: null = use DB
                'user_reaction' => $userReaction,
                // Legacy key for backwards compat (deprecated)
                'views' => $playCount ?? 0,
            ];
        }

        return $output;
    }

    /**
     * Get empty results structure (fallback when Redis unavailable)
     *
     * V11: Returns null for counts = use DB value (no overlay data)
     */
    private function getEmptyResults(array $postIds): array
    {
        $output = [];
        foreach ($postIds as $postId) {
            $output[$postId] = [
                'tombstone' => false,
                'patch' => null,
                'reactions' => [],
                'play_count' => null,      // V11: null = use DB
                'comment_count' => null,   // V11: null = use DB
                'user_reaction' => null,
                'views' => 0,              // Legacy compat
            ];
        }
        return $output;
    }

    /**
     * Preload reactions from database into overlay cache
     *
     * Used to warm the cache when posts are first loaded from DB
     *
     * @param array $postIds
     * @param array $dbReactions [postId => [emotion_id => count, ...], ...]
     */
    public function warmReactionsCache(array $postIds, array $dbReactions): void
    {
        if (!$this->redis || empty($postIds)) {
            return;
        }

        try {
            $pipe = $this->redis->pipeline();

            foreach ($postIds as $postId) {
                $key = "overlay:{$postId}:reactions";

                if (isset($dbReactions[$postId]) && !empty($dbReactions[$postId])) {
                    // Delete existing and set new
                    $pipe->del($key);
                    foreach ($dbReactions[$postId] as $emotionId => $count) {
                        $pipe->hSet($key, (string)$emotionId, $count);
                    }
                    $pipe->expire($key, 3600); // 1h TTL
                }
            }

            $pipe->exec();

        } catch (\Exception $e) {
            Logger::overlay('warning', 'OverlayBatchLoader::warmReactionsCache failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Preload user reactions from database into overlay cache
     *
     * @param int $userId
     * @param array $userReactions [postId => emotion_id, ...]
     */
    public function warmUserReactionsCache(int $userId, array $userReactions): void
    {
        if (!$this->redis || empty($userReactions)) {
            return;
        }

        try {
            $pipe = $this->redis->pipeline();

            foreach ($userReactions as $postId => $emotionId) {
                $key = "personal:{$userId}:rx:{$postId}";
                $pipe->setex($key, 300, $emotionId); // 5min TTL
            }

            $pipe->exec();

        } catch (\Exception $e) {
            Logger::overlay('warning', 'OverlayBatchLoader::warmUserReactionsCache failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply overlays to post data
     *
     * Merges overlay data into post objects, applying patches and filtering tombstones
     *
     * ENTERPRISE V11 (2025-12-11): ABSOLUTE VALUE PATTERN - "OVERLAY WINS"
     * - play_count and comment_count use ABSOLUTE values (not deltas)
     * - If overlay exists → use overlay value
     * - If overlay is null → use DB value (no change)
     * - This eliminates timing bugs between flush and cache reload
     *
     * ENTERPRISE V4.7 (2025-12-06): BANNED USERS FILTERING
     * - Also filters posts from banned users via BannedUsersOverlayService
     * - Instant effect when admin bans a user (no cache invalidation needed)
     *
     * @param array $posts Array of post data from DB/cache
     * @param array $overlays Output from loadBatch()
     * @param bool $filterTombstones Remove posts with tombstones (default true)
     * @param bool $filterBannedUsers Remove posts from banned users (default true)
     * @return array Modified posts array
     */
    public function applyOverlays(
        array $posts,
        array $overlays,
        bool $filterTombstones = true,
        bool $filterBannedUsers = true
    ): array {
        // ENTERPRISE V4.7: Filter posts from banned users FIRST
        // This ensures banned users' posts are removed even if tombstone not set
        if ($filterBannedUsers && !empty($posts)) {
            $bannedOverlay = BannedUsersOverlayService::getInstance();
            if ($bannedOverlay->isAvailable()) {
                $posts = $bannedOverlay->filterBannedUsersPosts($posts);
            }
        }

        $result = [];

        foreach ($posts as $post) {
            $postId = $post['id'] ?? $post['post_id'] ?? null;
            if (!$postId) {
                $result[] = $post;
                continue;
            }

            $overlay = $overlays[$postId] ?? null;
            if (!$overlay) {
                $result[] = $post;
                continue;
            }

            // Filter tombstoned posts
            if ($filterTombstones && $overlay['tombstone']) {
                continue; // Skip deleted posts
            }

            // Apply patch (content updates)
            if ($overlay['patch']) {
                foreach ($overlay['patch'] as $key => $value) {
                    $post[$key] = $value;
                }
            }

            // V11 ABSOLUTE: Reactions - "overlay wins" if present
            // (This was already correct in V4)
            if (!empty($overlay['reactions'])) {
                $post['reaction_stats'] = $overlay['reactions'];
            }

            // V11 ABSOLUTE: Play count - "overlay wins" if present (not null)
            // If overlay is null → keep DB value (no change)
            // If overlay is int → use overlay value (REPLACES, not adds!)
            if ($overlay['play_count'] !== null) {
                $post['play_count'] = $overlay['play_count'];
            }

            // V11 ABSOLUTE: Comment count - "overlay wins" if present (not null)
            // If overlay is null → keep DB value (no change)
            // If overlay is int → use overlay value (REPLACES, not adds!)
            if ($overlay['comment_count'] !== null) {
                $post['comment_count'] = $overlay['comment_count'];
            }

            // Add user's reaction
            if ($overlay['user_reaction'] !== null) {
                $post['user_reaction'] = $overlay['user_reaction'];
            }

            $result[] = $post;
        }

        return $result;
    }
}
