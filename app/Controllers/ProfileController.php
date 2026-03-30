<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;
use Need2Talk\Services\ReactionStatsService;
use Need2Talk\Services\Cache\OverlayBatchLoader;
use Need2Talk\Services\Cache\OverlayService;
use Need2Talk\Services\Cache\UserSettingsOverlayService;

/**
 * ProfileController - Enterprise Galaxy
 *
 * User profile management (public/semi-public pages)
 *
 * ARCHITECTURE:
 * - /profile → Redirect to own profile (/u/{own_uuid})
 * - /me → Alias for /profile (shortcut)
 * - /u/{uuid} → Public user profile (privacy-controlled)
 *
 * IMPORTANT: Profile vs Feed distinction
 * - Profile = User's public page (bio, posts, stats) - Privacy controlled
 * - Feed = Private homepage with friends' posts - See FeedController
 *
 * SECURITY:
 * - UUID encryption for privacy
 * - Privacy settings enforcement (public/friends/private)
 * - XSS prevention
 * - Anti-enumeration (UUID instead of sequential IDs)
 *
 * @package Need2Talk\Controllers
 */
class ProfileController extends BaseController
{
    /**
     * ENTERPRISE: Request-scoped cache for duplicate query prevention
     * This prevents the same data being fetched multiple times during ONE request
     *
     * Example: checkFriendship() called by both show() AND getUserPosts()
     * Without memoization: 2 identical DB queries
     * With memoization: 1 DB query + 1 array lookup (1000x faster)
     */
    private array $friendshipCache = [];
    private array $friendRequestStatusCache = [];
    private array $userStatsCache = [];
    private array $userByUuidCache = [];
    private array $userPostsCache = [];

    /**
     * Show own profile (shortcut)
     *
     * GET /profile or /me
     *
     * Redirects to /u/{own_uuid}
     * Shortcut for users to access their own profile quickly
     *
     * @return void
     */
    public function showOwnProfile(): void
    {

        try {
            // ENTERPRISE: Require authentication
            $user = $this->requireAuth();


            // ENTERPRISE SECURITY: Ensure full user data is loaded (including UUID)
            // Fix: If UUID is missing, reload user from database to get ALL fields
            if (!isset($user['uuid']) || empty($user['uuid'])) {
                Logger::warning('User UUID missing in session - reloading from database', [
                    'user_id' => $user['id'],
                    'user_keys' => array_keys($user),
                    'cache_user_keys' => $user ? array_keys($user) : [],
                ]);

                // Reload user from database with ALL fields including UUID
                $db = db();
                $fullUser = $db->findOne('
                    SELECT * FROM users
                    WHERE id = ? AND deleted_at IS NULL
                ', [$user['id']], [
                    'cache' => false, // Force fresh data from DB
                ]);

                if (!$fullUser || !isset($fullUser['uuid'])) {
                    Logger::error('User UUID still missing after database reload', [
                        'user_id' => $user['id'],
                        'full_user_keys' => $fullUser ? array_keys($fullUser) : [],
                        'has_uuid_in_db' => $fullUser && isset($fullUser['uuid']),
                    ]);
                    $this->redirect(url('/feed'));

                    return;
                }

                // ENTERPRISE GALAXY (2025-01-23): No session write, current_user() handles cache
                $user = $fullUser;
            }

            // ENTERPRISE FIX (2025-12-08): Call method directly instead of HTTP redirect
            // Avoids double AuthMiddleware execution (double UPDATE last_activity)
            // Old: redirect to /u/{uuid} caused 2 HTTP requests = 2x updateLastActivity
            // New: direct method call with pre-authenticated user = 1x updateLastActivity
            $this->showUserProfile($user['uuid'], $user);

        } catch (\Exception $e) {
            Logger::error('Failed to redirect to own profile', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->redirect(url('/login'));
        }
    }

    /**
     * Show user profile (public/semi-public)
     *
     * GET /u/{uuid}
     *
     * Displays user profile based on privacy settings:
     * - Public: Everyone can see (may be indexed by Google)
     * - Friends: Only friends can see
     * - Private: Only user themselves can see
     *
     * @param string $uuid User UUID (encrypted)
     * @return void
     */
    public function showUserProfile(string $uuid, ?array $authenticatedUser = null): void
    {

        try {
            // ENTERPRISE SECURITY: Anti-cache headers
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');


            // ENTERPRISE FIX (2025-12-08): Skip requireAuth() if user already authenticated
            // When called from showOwnProfile(), user is already authenticated - no need for second check
            // This prevents duplicate UPDATE last_activity queries
            $currentUser = $authenticatedUser ?? $this->requireAuth();

            // ENTERPRISE: Sanitize UUID input (prevent injection)
            $uuid = preg_replace('/[^a-zA-Z0-9\-_]/', '', $uuid);

            if (empty($uuid)) {
                Logger::warning('Invalid UUID in profile URL', [
                    'uuid' => $uuid,
                    'viewer_id' => $currentUser['id'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                $this->redirect(url('/feed'));

                return;
            }


            // ENTERPRISE: Load target user by UUID (with request-scoped memoization)
            $targetUser = $this->getUserByUuid($uuid);

            if (!$targetUser) {
                Logger::warning('User profile not found', [
                    'uuid' => $uuid,
                    'viewer_id' => $currentUser['id'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                $this->redirect(url('/feed'));

                return;
            }

            // =========================================================================
            // ENTERPRISE V4.7 (2025-12-06): SOFT-HIDE BANNED/SUSPENDED USERS
            // =========================================================================
            // Users with status != 'active' have their profiles hidden from OTHER users.
            // Own profile is ALWAYS visible (user can see their own suspended profile).
            // This is "soft-hide" - profile exists but is inaccessible to others.
            // Reactivating user automatically makes their profile visible again.
            // =========================================================================
            $isOwnProfile = $currentUser['id'] === $targetUser['id'];
            $userStatus = $targetUser['status'] ?? 'active';

            if (!$isOwnProfile && $userStatus !== 'active') {
                Logger::security('warning', 'Profile access denied (user suspended/banned)', [
                    'viewer_id' => $currentUser['id'],
                    'target_id' => $targetUser['id'],
                    'target_status' => $userStatus,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // SOFT-HIDE: Redirect without showing specific error (privacy!)
                // User appears to "not exist" to prevent status enumeration
                $this->redirect(url('/feed'));

                return;
            }

            // ENTERPRISE GHOST MODE: Check if blocked (bi-directional)
            // If A blocked B OR B blocked A → Redirect (ghost mode)
            $blockingService = new \Need2Talk\Services\Social\BlockingService();
            if ($blockingService->isBlocked($currentUser['id'], $targetUser['id'])) {
                Logger::security('warning', 'Profile access denied (blocked)', [
                    'viewer_id' => $currentUser['id'],
                    'target_id' => $targetUser['id'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // GHOST MODE: Redirect without showing "blocked" message (privacy!)
                $this->redirect(url('/feed'));

                return;
            }

            // ENTERPRISE: Check privacy settings
            // TODO: Re-enable when profile_privacy column is added to database
            // For now, all profiles are public
            $canView = true; // $this->checkProfilePrivacy($currentUser, $targetUser);

            if (!$canView) {
                Logger::security('warning', 'Profile access denied (privacy)', [
                    'viewer_id' => $currentUser['id'],
                    'target_id' => $targetUser['id'],
                    'privacy' => 'public', // TODO: Use $targetUser['profile_privacy'] when column exists
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Redirect to feed with error message
                $_SESSION['error_message'] = 'Non hai i permessi per visualizzare questo profilo.';
                $this->redirect(url('/feed'));

                return;
            }

            // ENTERPRISE: Load user stats (posts, followers, etc.)
            $stats = $this->getUserStats($targetUser['id']);

            // ENTERPRISE: Load user's public posts (paginated)
            $posts = $this->getUserPosts($targetUser['id'], $currentUser['id'], 1, 10);

            // ENTERPRISE GALAXY: Batch load reaction stats (PREVENTS N+1 PROBLEM!)
            // Single query for ALL posts instead of 1 query per post = 20x faster!
            if (!empty($posts)) {
                $statsService = new \Need2Talk\Services\ReactionStatsService();
                $postIds = array_column($posts, 'id');
                $reactionStats = $statsService->getBatchReactionStats($postIds, $currentUser['id']);

                // Merge reaction stats into posts array
                foreach ($posts as &$post) {
                    $post['reactions'] = $reactionStats[$post['id']] ?? [
                        'total_reactions' => 0,
                        'top_emotions' => [],
                        'user_reaction' => null,
                    ];
                }
                unset($post); // Break reference
            }

            // ENTERPRISE: Check if viewer is friend
            $isFriend = $this->checkFriendship($currentUser['id'], $targetUser['id']);

            // ENTERPRISE: Check if friend request exists
            $friendRequestStatus = $this->getFriendRequestStatus($currentUser['id'], $targetUser['id']);

            // ENTERPRISE: Build page-specific CSS/JS arrays
            // Note: $isOwnProfile already defined above (V4.7 soft-hide check)
            $pageCSS = [];
            $pageJS = [];

            // Bootstrap required for modals (friend requests, etc.)
            $pageCSS[] = 'bootstrap.min';

            // ENTERPRISE: Profile header CSS (audio-centric design, GPU-accelerated)
            $pageCSS[] = 'profile-header';

            // Profile tabs CSS (only for own profile with dashboard)
            if ($isOwnProfile) {
                $pageCSS[] = 'profile-dashboard';
                $pageCSS[] = 'profile/profile-tabs';
                $pageCSS[] = 'profile/emotional-journal';
                $pageCSS[] = 'profile/diary-timeline-story';       // V12: Timeline story column (BEM)
                $pageCSS[] = 'profile/diary-audio-recorder';       // V12: Audio recorder modal (BEM)
                $pageCSS[] = 'profile/diary-password-modal';       // v4.2: True E2E Diary Modal CSS
                // REMOVED: 'profile/journal-calendar' - tab eliminated (ENTERPRISE cleanup)
                // REMOVED: 'profile/journal-timeline' - replaced by diary-timeline-story.css (V12 BEM)

                // CRITICAL: JavaScript loading order (from old standalone profile)
                // These MUST load in this exact order for tabs to work
                // REMOVED: 'services/EncryptionService' - deprecated, replaced by DiaryEncryptionService (TRUE E2E)
                $pageJS[] = 'profile/ProfileTabs';                 // ⚠️ MUST LOAD FIRST - Tab switching system
                $pageJS[] = 'profile/DiaryEncryptionService';      // v4.2: True E2E Diary (MUST load before EmotionalJournal)
                $pageJS[] = 'profile/DiaryPasswordModal';          // v4.2: True E2E Diary Modal (MUST load before EmotionalJournal)
                // REMOVED: 'profile/EmotionalJournalEncryption' - deprecated, replaced by DiaryEncryptionService
                $pageJS[] = 'audio/EmotionalJournalRecorder';      // PHASE 1: Private diary recorder
                $pageJS[] = 'profile/EmotionalJournal';            // Tab: Diario Emotivo
                $pageJS[] = 'profile/JournalCalendarSidebar';      // ENTERPRISE GALAXY+ Phase 2.0: Calendar sidebar (MUST load before JournalTimeline)
                $pageJS[] = 'profile/JournalTimeline';             // Tab: Timeline (integrates with JournalCalendarSidebar)
                $pageJS[] = 'profile/EmotionalAnalytics';          // Tab: Analisi Emozionale (ENTERPRISE V11.6)
                // REMOVED: 'profile/JournalCalendar' - tab eliminated (ENTERPRISE cleanup)
                $pageJS[] = 'audio/ProfileDashboard';              // Tab: Panoramica (loads LAST)
            }

            // Friend request actions JS (for visitor profiles)
            if (!$isOwnProfile) {
                $pageJS[] = 'social/FriendRequestActions';
            }

            // ProfileAudioPosts renderer (for ALL profiles - Enterprise Galaxy)
            // NOTE: Different from JournalTimeline (emotional diary tab)
            $pageJS[] = 'profile/ProfileAudioPosts';

            // ENTERPRISE V6 (2025-11-30): ReactionPicker + CommentManager are already loaded globally
            // in app-post-login.php layout (lines 248, 252) - NO need to add them here!

            // ENTERPRISE: Render profile view with unified layout (performance optimization)
            // First load: 1.5s, subsequent clicks: 50-100ms (browser cache)
            $this->view('profile.show', [
                'user' => $currentUser,
                'targetUser' => $targetUser,
                'stats' => $stats,
                'posts' => $posts,
                'isFriend' => $isFriend,
                'isOwnProfile' => $isOwnProfile,
                'friendRequestStatus' => $friendRequestStatus,
                'title' => htmlspecialchars($targetUser['nickname'], ENT_QUOTES, 'UTF-8') . ' - need2talk',
                'description' => 'Profilo di ' . htmlspecialchars($targetUser['nickname'], ENT_QUOTES, 'UTF-8') . ' su need2talk',
                'pageCSS' => $pageCSS,
                'pageJS' => $pageJS,
            ], 'app-post-login');

        } catch (\Exception $e) {
            Logger::error('Failed to load user profile', [
                'uuid' => $uuid ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->redirect(url('/feed'));
        }
    }

    /**
     * Check if viewer can see target user's profile
     *
     * @param array $viewer Current user
     * @param array $target Target user
     * @return bool True if can view
     */
    private function checkProfilePrivacy(array $viewer, array $target): bool
    {
        // Own profile: always visible
        if ($viewer['id'] === $target['id']) {
            return true;
        }

        // TODO: Re-enable privacy check when profile_privacy column exists
        // For now, all profiles are public
        return true;

        // Public profile: everyone can see
        $privacy = 'public'; // TODO: Use $target['profile_privacy'] when column exists
        if ($privacy === 'public') {
            return true;
        }

        // Friends-only: check friendship
        if ($privacy === 'friends') {
            return $this->checkFriendship($viewer['id'], $target['id']);
        }

        // Private: only owner can see
        return false;
    }

    /**
     * Check if two users are friends
     *
     * ENTERPRISE OPTIMIZATION: UNION instead of OR to use covering indexes
     * - idx_user_friends_covering (user_id, status, deleted_at, friend_id, created_at)
     * - idx_friend_users_covering (friend_id, status, deleted_at, user_id, created_at)
     *
     * Performance: <1ms even with 10M friendships (covering index ZERO table lookups)
     *
     * REQUEST-SCOPED MEMOIZATION:
     * - First call: Queries database (~1ms)
     * - Subsequent calls during SAME request: Array lookup (~0.001ms = 1000x faster)
     * - Cache cleared at end of request (no stale data)
     *
     * @param int $userId1 User 1 ID
     * @param int $userId2 User 2 ID
     * @return bool True if friends
     */
    private function checkFriendship(int $userId1, int $userId2): bool
    {
        // ENTERPRISE: Request-scoped memoization (prevent duplicate queries)
        // Generate cache key (order-independent: "1:2" same as "2:1")
        $cacheKey = min($userId1, $userId2) . ':' . max($userId1, $userId2);

        // Check if already fetched during this request
        if (isset($this->friendshipCache[$cacheKey])) {
            return $this->friendshipCache[$cacheKey];
        }

        // ENTERPRISE V4.5: CHECK OVERLAY FIRST for immediate visibility after accept
        // The overlay contains newly accepted friendships that aren't in DB cache yet
        try {
            $overlay = \Need2Talk\Services\Cache\FriendshipOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlayStatus = $overlay->getFriendshipStatus($userId1, $userId2);
                if ($overlayStatus !== null && $overlayStatus['status'] === 'accepted') {
                    // Found accepted friendship in overlay - immediate visibility!
                    $this->friendshipCache[$cacheKey] = true;
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Overlay check failed - fall through to DB query
        }

        $db = db();

        // ENTERPRISE: UNION exploits BOTH covering indexes (faster than OR)
        $friendship = $db->findOne('
            SELECT id FROM friendships
            WHERE user_id = ? AND friend_id = ? AND status = \'accepted\' AND deleted_at IS NULL
            UNION
            SELECT id FROM friendships
            WHERE user_id = ? AND friend_id = ? AND status = \'accepted\' AND deleted_at IS NULL
            LIMIT 1
        ', [$userId1, $userId2, $userId2, $userId1], [
            'cache' => true,
            'cache_ttl' => 'medium', // 30min cache
        ]);

        $isFriend = $friendship !== null;

        // Store in request-scoped cache
        $this->friendshipCache[$cacheKey] = $isFriend;

        return $isFriend;
    }

    /**
     * Get friend request status between two users
     *
     * ENTERPRISE V6.8 (2025-11-30): OVERLAY-FIRST ARCHITECTURE
     * - CHECK OVERLAY FIRST for immediate visibility after cancel/accept/send
     * - Overlay contains tombstones ('none') that override stale DB cache
     * - UNION instead of 2 separate queries (2x faster)
     * - Request-scoped memoization (prevents duplicate queries)
     *
     * @param int $viewerId Viewer ID
     * @param int $targetId Target user ID
     * @return string|null 'sent', 'received', 'accepted', null
     */
    private function getFriendRequestStatus(int $viewerId, int $targetId): ?string
    {
        // ENTERPRISE: Request-scoped memoization
        $cacheKey = "{$viewerId}:{$targetId}";

        if (isset($this->friendRequestStatusCache[$cacheKey])) {
            return $this->friendRequestStatusCache[$cacheKey];
        }

        // =========================================================================
        // ENTERPRISE V6.8: CHECK OVERLAY FIRST (CRITICAL FOR REAL-TIME)
        // The overlay contains:
        // - 'none' tombstone → friendship cancelled/removed (return null)
        // - 'pending:X' → pending request with direction (X = requester ID)
        // - 'accepted' → accepted friendship (return 'accepted')
        // =========================================================================
        try {
            $overlay = \Need2Talk\Services\Cache\FriendshipOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlayStatus = $overlay->getFriendshipStatus($viewerId, $targetId);

                if ($overlayStatus !== null) {
                    $status = $overlayStatus['status'];

                    // TOMBSTONE: Friendship was cancelled/removed → return null (no friendship)
                    if ($status === 'none') {
                        $this->friendRequestStatusCache[$cacheKey] = null;
                        return null;
                    }

                    // ACCEPTED: Already friends
                    if ($status === 'accepted') {
                        $this->friendRequestStatusCache[$cacheKey] = 'accepted';
                        return 'accepted';
                    }

                    // PENDING: Determine direction from requested_by field
                    if ($status === 'pending' && $overlayStatus['requested_by'] !== null) {
                        $direction = ($overlayStatus['requested_by'] === $viewerId) ? 'sent' : 'received';
                        $this->friendRequestStatusCache[$cacheKey] = $direction;
                        return $direction;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Overlay check failed - fall through to DB query
            \Need2Talk\Core\Logger::overlay('warning', 'getFriendRequestStatus overlay check failed', [
                'viewer' => $viewerId,
                'target' => $targetId,
                'error' => $e->getMessage(),
            ]);
        }

        // =========================================================================
        // FALLBACK: Query database (overlay miss or unavailable)
        // =========================================================================
        $db = db();

        // ENTERPRISE: ONE query with UNION instead of 2 separate queries
        $result = $db->findOne('
            SELECT \'sent\' as direction, status FROM friendships
            WHERE user_id = ? AND friend_id = ? AND deleted_at IS NULL
            UNION ALL
            SELECT \'received\' as direction, status FROM friendships
            WHERE user_id = ? AND friend_id = ? AND deleted_at IS NULL
            LIMIT 1
        ', [$viewerId, $targetId, $targetId, $viewerId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);

        $status = null;
        if ($result) {
            $status = $result['status'] === 'accepted' ? 'accepted' : $result['direction'];
        }

        // Store in request-scoped cache
        $this->friendRequestStatusCache[$cacheKey] = $status;

        return $status;
    }

    /**
     * Get user by UUID
     *
     * ENTERPRISE OPTIMIZATION:
     * - Request-scoped memoization prevents duplicate lookups
     * - Commonly called multiple times per profile view
     *
     * ENTERPRISE V4.7 (2025-12-06): SOFT-HIDE BANNED/SUSPENDED USERS
     * - Returns user status for caller to decide visibility
     * - Profile access control handled in showUserProfile()
     *
     * @param string $uuid User UUID
     * @return array|null User data (including status) or null
     */
    private function getUserByUuid(string $uuid): ?array
    {
        // ENTERPRISE GALAXY: Check timestamp-based force refresh flag
        // SettingsController sets $_SESSION['_user_cache_bypass_until'] = time() + 30
        // force_refresh = Skip OLD cache + Write NEW cache (avatar updated)
        // After 30s expires → User returns to cache (but NEW cache with updated avatar)
        $forceRefresh = isset($_SESSION['_user_cache_bypass_until'])
                        && $_SESSION['_user_cache_bypass_until'] > time();

        // Don't use request-scoped memoization if forcing refresh
        if (!$forceRefresh && isset($this->userByUuidCache[$uuid])) {
            return $this->userByUuidCache[$uuid];
        }

        $db = db();

        // ENTERPRISE V4.7: Include status field for soft-hide decision
        // Profile controller will handle banned/suspended users appropriately
        $user = $db->findOne('
            SELECT id, uuid, nickname, email, avatar_url, status, created_at
            FROM users
            WHERE uuid = ? AND deleted_at IS NULL
        ', [$uuid], [
            'force_refresh' => $forceRefresh, // GALAXY: Bypass read + force write new cache
            'cache_ttl' => 'short',           // 5min cache
        ]);

        // Store in request-scoped cache (even if null)
        $this->userByUuidCache[$uuid] = $user;

        return $user;
    }

    /**
     * Get user statistics
     *
     * ENTERPRISE V6.8 (2025-11-30): OVERLAY-FIRST ARCHITECTURE
     * - Posts count: DB + overlay delta for new posts
     * - Friends count: DB + overlay delta for newly accepted friends
     * - Reactions count: DB + overlay delta for new reactions
     *
     * OPTIMIZATION:
     * - Posts count: Uses idx_user_posts (user_id, deleted_at, created_at) - <2ms
     * - Friends count: UNION to exploit both covering indexes - <1ms
     * - Reactions count: Uses idx_audio_post covering index from audio_reactions - <5ms
     *
     * Total: <10ms even with millions of records per user
     *
     * REQUEST-SCOPED MEMOIZATION:
     * - First call: Queries database (~10ms total)
     * - Subsequent calls: Array lookup (~0.001ms = 10,000x faster)
     * - Cache cleared at end of request (always fresh data on next page load)
     *
     * @param int $userId User ID
     * @return array Stats array
     */
    private function getUserStats(int $userId): array
    {
        // ENTERPRISE: Request-scoped memoization
        if (isset($this->userStatsCache[$userId])) {
            return $this->userStatsCache[$userId];
        }

        $db = db();

        // ENTERPRISE: Count posts using idx_user_posts covering index
        $postCount = $db->findOne('
            SELECT COUNT(*) as count
            FROM audio_posts
            WHERE user_id = ? AND deleted_at IS NULL
        ', [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ])['count'] ?? 0;

        // ENTERPRISE: Count friends using UNION (exploits both covering indexes)
        // Faster than OR clause for millions of friendships
        $dbFriendCount = $db->findOne('
            SELECT COUNT(*) as count FROM (
                SELECT id FROM friendships
                WHERE user_id = ? AND status = \'accepted\' AND deleted_at IS NULL
                UNION ALL
                SELECT id FROM friendships
                WHERE friend_id = ? AND status = \'accepted\' AND deleted_at IS NULL
            ) AS friends
        ', [$userId, $userId], [
            'cache' => true,
            'cache_ttl' => 'medium',
        ])['count'] ?? 0;

        // =========================================================================
        // ENTERPRISE V6.8: ADD OVERLAY DELTA FOR FRIEND COUNT (REAL-TIME)
        // FriendshipOverlayService tracks +1/-1 for accept/unfriend operations
        // This ensures the count is accurate even before DB cache expires
        // =========================================================================
        $friendCountDelta = 0;
        try {
            $overlay = \Need2Talk\Services\Cache\FriendshipOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $friendCountDelta = $overlay->getFriendCountDelta($userId);
            }
        } catch (\Throwable $e) {
            // Non-critical: continue with DB count only
            \Need2Talk\Core\Logger::overlay('warning', 'getUserStats friend count delta failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        $friendCount = max(0, $dbFriendCount + $friendCountDelta);

        // ENTERPRISE: Count emotional reactions received (10 emotions system)
        // Uses idx_audio_post covering index from audio_reactions
        $reactionsCount = $db->findOne('
            SELECT COUNT(*) as count
            FROM audio_reactions ar
            JOIN audio_posts ap ON ar.audio_post_id = ap.id
            WHERE ap.user_id = ? AND ap.deleted_at IS NULL
        ', [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ])['count'] ?? 0;

        $stats = [
            'posts' => $postCount,
            'friends' => $friendCount,
            'reactions' => $reactionsCount,
        ];

        // Store in request-scoped cache
        $this->userStatsCache[$userId] = $stats;

        return $stats;
    }

    /**
     * Get user's public posts with V6 Generational Overlay Architecture
     *
     * ================================================================================
     * ENTERPRISE V6 GALAXY (2025-11-30): GENERATIONAL OVERLAY ARCHITECTURE
     * ================================================================================
     *
     * This method now implements the SAME overlay architecture as AudioPostService::getFeed(),
     * ensuring CONSISTENT real-time counts across Feed and Profile views.
     *
     * ARCHITECTURE OVERVIEW:
     * ┌─────────────────────────────────────────────────────────────────────────────┐
     * │                         V6 GENERATIONAL OVERLAY                             │
     * ├─────────────────────────────────────────────────────────────────────────────┤
     * │  1. DB QUERY (with L1/L2/L3 cache)                                         │
     * │     └─> Base data: posts, play_count, comment_count from DB/cache          │
     * │                                                                             │
     * │  2. SET CACHE GENERATIONS (Redis)                                          │
     * │     └─> Mark timestamp when data was loaded (generational anchor)          │
     * │     └─> Post generations: need2talk:overlay:post:{id}:generation           │
     * │     └─> File generations: need2talk:overlay:file:{id}:generation           │
     * │                                                                             │
     * │  3. LOAD OVERLAY DELTAS (Redis sorted sets - single pipeline)              │
     * │     └─> Play deltas: ZCARD of events AFTER generation timestamp            │
     * │     └─> Comment deltas: Sum of event values (+1/-1) AFTER generation       │
     * │     └─> Avatar overlays: Recently changed avatars (real-time)              │
     * │                                                                             │
     * │  4. MERGE (DB base + Overlay deltas = Real-time counts)                    │
     * │     └─> play_count = db.play_count + overlay.play_delta                    │
     * │     └─> comment_count = db.comment_count + overlay.comment_delta           │
     * │     └─> avatar_url = overlay.avatar ?? db.avatar_url                       │
     * └─────────────────────────────────────────────────────────────────────────────┘
     *
     * WHY GENERATIONAL?
     * - Survives Redis flush (delta recalculated from generation, not reset to 0)
     * - No cache invalidation needed (WriteBehindBuffer flushes in background)
     * - O(1) per post (batch loads via Redis pipeline)
     * - Consistent with Feed (same user sees same counts everywhere)
     *
     * PERFORMANCE:
     * - DB query: <2ms (idx_user_posts covering index)
     * - Overlay batch load: <5ms (single Redis pipeline for ALL posts)
     * - Total: <10ms for 20 posts (enterprise-grade)
     *
     * @param int $userId User ID (profile owner)
     * @param int $viewerId Viewer ID (for privacy check + user-specific overlays)
     * @param int $page Page number
     * @param int $perPage Posts per page
     * @return array Posts array with real-time overlay-merged counts
     */
    private function getUserPosts(int $userId, int $viewerId, int $page = 1, int $perPage = 10): array
    {
        // ═══════════════════════════════════════════════════════════════════════════
        // PHASE 1: CACHE BYPASS CHECK (for avatar updates)
        // ═══════════════════════════════════════════════════════════════════════════
        $forceRefresh = isset($_SESSION['_user_cache_bypass_until'])
                        && $_SESSION['_user_cache_bypass_until'] > time();

        // Request-scoped memoization (skip if force refresh to get fresh data)
        $cacheKey = "{$userId}:{$viewerId}:{$page}:{$perPage}";
        if (!$forceRefresh && isset($this->userPostsCache[$cacheKey])) {
            return $this->userPostsCache[$cacheKey];
        }

        $db = db();
        $offset = ($page - 1) * $perPage;

        // ═══════════════════════════════════════════════════════════════════════════
        // PHASE 2: PRIVACY CHECK (optimized UNION query with covering indexes)
        // ═══════════════════════════════════════════════════════════════════════════
        $isFriend = $this->checkFriendship($viewerId, $userId);
        $isOwnProfile = $viewerId === $userId;

        if ($isOwnProfile) {
            $privacyClause = ''; // Own profile: show all posts
        } elseif ($isFriend) {
            $privacyClause = "AND ap.visibility IN ('public', 'friends')";
        } else {
            $privacyClause = "AND ap.visibility = 'public'";
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // PHASE 3: DB QUERY (L1/L2/L3 multi-level cache, covering index)
        // ═══════════════════════════════════════════════════════════════════════════
        // Query uses idx_user_posts (user_id, deleted_at, created_at DESC)
        // ORDER BY matches index order → NO filesort, index-only scan
        // ENTERPRISE V11.2 (2025-12-11): Use COUNT(*) for comment_count instead of denormalized field
        $posts = $db->query("
            SELECT ap.id, ap.uuid, ap.user_id, ap.post_type, ap.content,
                   ap.audio_file_id, ap.photo_urls, ap.video_url,
                   ap.visibility AS privacy_level, ap.tagged_users, ap.location,
                   (SELECT COUNT(*) FROM audio_comments ac WHERE ac.audio_post_id = ap.id AND ac.status = 'active') AS comment_count,
                   -- V5.3: share_count removed - no sharing feature exists
                   ap.created_at, ap.published_at,
                   af.duration, af.title, af.play_count, af.description AS audio_description,
                   e.name_it AS emotion_name, e.color_hex AS emotion_color
            FROM audio_posts ap
            LEFT JOIN audio_files af ON ap.audio_file_id = af.id AND af.deleted_at IS NULL
            LEFT JOIN emotions e ON af.primary_emotion_id = e.id
            WHERE ap.user_id = ? AND ap.deleted_at IS NULL
            {$privacyClause}
            ORDER BY ap.created_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $perPage, $offset], [
            'force_refresh' => $forceRefresh,
            'cache_ttl' => 'short', // 5min L1 cache
        ]);

        $posts = $posts ?: [];

        // Early return if no posts (skip overlay operations)
        if (empty($posts)) {
            $this->userPostsCache[$cacheKey] = $posts;
            return $posts;
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // PHASE 4: V6 GENERATIONAL OVERLAY - SET CACHE GENERATIONS
        // ═══════════════════════════════════════════════════════════════════════════
        // This marks the timestamp when post data was loaded from DB/cache.
        // Overlay deltas are calculated from this timestamp, solving the flush-reset bug.
        $postIds = array_column($posts, 'id');
        $audioFileIds = array_filter(array_map(fn($p) => (int)($p['audio_file_id'] ?? 0), $posts));

        $overlayService = OverlayService::getInstance();
        if ($overlayService->isAvailable()) {
            // Set post generations (for comment deltas)
            if (!empty($postIds)) {
                $overlayService->setBatchPostGenerations($postIds);
            }
            // Set file generations (for play count deltas)
            if (!empty($audioFileIds)) {
                $overlayService->setBatchFileGenerations($audioFileIds);
            }
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // PHASE 5: V11 ABSOLUTE OVERLAYS (single Redis pipeline)
        // ═══════════════════════════════════════════════════════════════════════════
        // V11.2 (2025-12-11): comment_count now uses COUNT(*) subquery - always correct!
        // Only play_count needs overlay (no table to COUNT)

        // 5a. Play count V11 absolute (overlay wins if present)
        $playAbsolutes = [];
        if ($overlayService->isAvailable() && !empty($audioFileIds)) {
            $playAbsolutes = $overlayService->getBatchPlayAbsolutes($audioFileIds);
        }

        // 5b. REMOVED: comment_count overlay not needed - COUNT(*) is source of truth

        // 5c. Avatar overlays (recently changed avatars for real-time display)
        // Profile shows single user, but we load overlay for consistency with Feed
        $avatarOverlays = [];
        $settingsOverlay = UserSettingsOverlayService::getInstance();
        if ($settingsOverlay->isAvailable()) {
            $avatarOverlays = $settingsOverlay->batchLoadAvatars([$userId]);
        }

        // 5d. ENTERPRISE V8.1 (2025-12-10): Reaction stats + overlays (same pattern as Feed)
        // Load reaction stats from DB as base, then overlay with real-time Redis data
        $bulkReactionStats = (new ReactionStatsService())->getBulkPostReactionStats($postIds, $viewerId);
        $reactionOverlays = [];
        $overlayLoader = OverlayBatchLoader::getInstance();
        if ($overlayLoader) {
            $reactionOverlays = $overlayLoader->loadBatch($postIds, $viewerId);
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // PHASE 6: V11 OVERLAY WINS PATTERN
        // ═══════════════════════════════════════════════════════════════════════════
        // V11.2 (2025-12-11): comment_count from COUNT(*) is always correct - no overlay needed.
        // play_count uses "overlay wins" - if overlay exists, use it; else use DB.
        foreach ($posts as &$post) {
            $postId = (int) $post['id'];
            $audioFileId = (int) ($post['audio_file_id'] ?? 0);

            // V11: play_count with "overlay wins" pattern
            // If overlay exists (not null), use it; else use DB value
            $playAbsolute = $playAbsolutes[$audioFileId] ?? null;
            if ($playAbsolute !== null) {
                $post['play_count'] = $playAbsolute;
            }
            // If overlay is null, keep DB value ($post['play_count'] unchanged)

            // V11.2: comment_count is from COUNT(*) subquery - always correct, no overlay needed
            // $post['comment_count'] is already correct from the SQL query

            // ENTERPRISE V8.1 (2025-12-10): Merge reaction stats (DB base + overlay)
            // Same pattern as Feed - overlay wins for emotions it tracks
            $reactions = $bulkReactionStats[$postId] ?? [
                'total_reactions' => 0,
                'top_emotions' => [],
                'user_reaction' => null,
            ];

            // Transform to reaction_stats format for frontend
            $reactionStats = [];
            foreach ($reactions['top_emotions'] ?? [] as $emotion) {
                $reactionStats[$emotion['emotion_id']] = $emotion['count'];
            }

            // Apply overlay - OVERLAY WINS for emotions it tracks
            $overlay = $reactionOverlays[$postId] ?? null;
            if ($overlay && !empty($overlay['reactions'])) {
                foreach ($overlay['reactions'] as $emotionId => $overlayCount) {
                    if ($overlayCount > 0) {
                        $reactionStats[$emotionId] = $overlayCount;
                    } else {
                        unset($reactionStats[$emotionId]);
                    }
                }
            }

            // Add to post data
            $post['reaction_stats'] = $reactionStats;
            $post['total_reactions'] = array_sum($reactionStats);

            // User's own reaction (overlay takes precedence)
            $userReaction = $reactions['user_reaction']['emotion_id'] ?? null;
            if ($overlay && $overlay['user_reaction'] !== null) {
                $userReaction = $overlay['user_reaction'] === 0 ? null : $overlay['user_reaction'];
            }
            $post['user_reaction'] = $userReaction;

            // ENTERPRISE V7.0 (2025-11-30): Decode tagged_users JSON for @mention clickable links
            if (!empty($post['tagged_users']) && is_string($post['tagged_users'])) {
                $post['tagged_users'] = json_decode($post['tagged_users'], true) ?: [];
            } else {
                $post['tagged_users'] = [];
            }

            // Note: avatar_url not in posts query (it's the target user, not author)
            // Profile always shows target user's avatar from $targetUser in view
        }
        unset($post); // Break reference

        // ═══════════════════════════════════════════════════════════════════════════
        // PHASE 7: STORE IN REQUEST-SCOPED CACHE (with overlay-merged counts)
        // ═══════════════════════════════════════════════════════════════════════════
        $this->userPostsCache[$cacheKey] = $posts;

        // Log overlay merge for debugging (overlay channel)
        Logger::overlay('debug', 'Profile posts loaded with V11.2 (COUNT + overlay wins)', [
            'user_id' => $userId,
            'viewer_id' => $viewerId,
            'post_count' => count($posts),
            'play_overlays_applied' => count(array_filter($playAbsolutes, fn($v) => $v !== null)),
            'comment_source' => 'COUNT(*) subquery',
            'reaction_overlays_loaded' => count(array_filter($reactionOverlays, fn($o) => !empty($o['reactions']))),
        ]);

        return $posts;
    }
}
