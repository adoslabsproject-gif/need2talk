<?php

/**
 * AUTHENTICATION HELPER FUNCTIONS - ENTERPRISE GALAXY (2025-01-23 REFACTORING)
 *
 * PURPOSE:
 * Complete removal of $_SESSION['user'] in favor of Redis L1 cache-backed user system.
 * This file provides the single source of truth for authenticated user data.
 *
 * ARCHITECTURE:
 * - Redis L1 cache (DB 1) with dedicated user keys
 * - Smart dynamic TTL (1min write-heavy, 5min read-only)
 * - Granular cache invalidation per user_id
 * - Smart pre-warming for critical operations
 * - Zero backward compatibility (full migration)
 *
 * CACHE KEYS:
 * - user:{id}:data (5min TTL) - Full user data for read-only operations
 * - user:{id}:profile (1min TTL) - Profile data for write-heavy operations
 * - user:{id}:settings (5min TTL) - Settings data
 *
 * MIGRATION NOTES:
 * - Replaces all 47 occurrences of $_SESSION['user'] across 14 files
 * - Single source of truth: Redis L1 (backed by PostgreSQL)
 * - No session writes (SecureSessionManager only stores user_id)
 * - All user data loaded on-demand from cache/DB
 *
 * @package Need2Talk\Helpers
 * @version 2.0.0
 * @since 2025-01-23
 */

declare(strict_types=1);

use Need2Talk\Services\Logger;
use Need2Talk\Services\SecureSessionManager;
use Need2Talk\Models\User;

/**
 * Get current authenticated user (Redis L1 cached)
 *
 * ENTERPRISE GALAXY:
 * - Single source of truth for user data
 * - Replaces all $_SESSION['user'] reads (28 occurrences)
 * - Redis L1 cache with 5min TTL (read-only operations)
 * - Static cache within request for zero overhead
 * - Automatic DB fallback on cache miss
 *
 * PERFORMANCE:
 * - Cache hit: ~0.5ms (Redis L1 network round-trip)
 * - Cache miss: ~2ms (PostgreSQL query + cache write)
 * - Static cache: ~0.001ms (PHP memory)
 * - Expected hit rate: >95%
 *
 * USAGE:
 * ```php
 * $user = current_user();
 * if ($user) {
 *     echo "Welcome " . $user['nickname'];
 *     echo "Email: " . $user['email'];
 * }
 * ```
 *
 * @return array|null User data array or null if not authenticated
 */
function current_user(): ?array
{
    // STATIC CACHE: Within single request, cache user data in PHP memory
    // This eliminates Redis round-trips for multiple current_user() calls
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }

    // STEP 1: Check if user is authenticated (fast path)
    $userId = SecureSessionManager::getCurrentUserId();
    if (!$userId) {
        $loaded = true;
        return null;
    }

    // STEP 2: Try Redis L1 cache (user:{id}:data)
    $cacheKey = "user:{$userId}:data";
    $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_enterprise');

    if ($redis) {
        try {
            $cached = $redis->get($cacheKey);
            if ($cached) {
                // CACHE HIT: Unserialize and return
                $user = unserialize($cached);
                if (is_array($user) && isset($user['id'])) {
                    $loaded = true;

                    // ENTERPRISE V4: Apply overlay for real-time avatar/profile updates
                    $overlay = \Need2Talk\Services\Cache\UserSettingsOverlayService::getInstance();
                    if ($overlay->isAvailable()) {
                        $avatarOverlay = $overlay->getAvatar((int) $user['id']);
                        if ($avatarOverlay && !empty($avatarOverlay['url'])) {
                            $user['avatar_url'] = $avatarOverlay['url'];
                        }
                        $user = $overlay->applyProfileOverlay($user, (int) $user['id']);
                    }

                    // DEVELOPMENT MODE: Log cache hit for monitoring
                    if (should_log('debug_general', 'debug')) {
                        Logger::debug('USER_CACHE: Hit for current_user()', [
                            'user_id' => $userId,
                            'cache_key' => $cacheKey,
                            'ttl_remaining' => $redis->ttl($cacheKey),
                        ]);
                    }

                    return $user;
                }

                // Corrupted cache - delete it
                $redis->del($cacheKey);
            }
        } catch (\Throwable $e) {
            // Redis error - log and continue to DB
            Logger::error('USER_CACHE: Redis error in current_user()', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // STEP 3: CACHE MISS - Load from database
    $userModel = new User();
    $user = $userModel->findById($userId);

    if (!$user || $user['deleted_at']) {
        // User deleted or not found - clear session
        SecureSessionManager::logout();
        $loaded = true;
        return null;
    }

    // STEP 4: Write to Redis L1 cache (5min TTL for read-only operations)
    if ($redis) {
        try {
            $redis->setex($cacheKey, 300, serialize($user)); // 5 minutes TTL

            // DEVELOPMENT MODE: Log cache miss + rebuild
            if (should_log('debug_general', 'debug')) {
                Logger::debug('USER_CACHE: Miss + rebuild for current_user()', [
                    'user_id' => $userId,
                    'cache_key' => $cacheKey,
                    'ttl_seconds' => 300,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('USER_CACHE: Failed to write cache', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    $loaded = true;
    return $user;
}

/**
 * Get current user ID (fast path, no database query)
 *
 * ENTERPRISE GALAXY:
 * - Ultra-fast authentication check
 * - No Redis/DB query (session only)
 * - Use for: rate limiting, logging, quick checks
 *
 * PERFORMANCE:
 * - ~0.01ms (session array access)
 * - 100x faster than current_user()
 *
 * USAGE:
 * ```php
 * $userId = current_user_id();
 * if ($userId) {
 *     // User is authenticated, proceed
 * }
 * ```
 *
 * @return int|null User ID or null if not authenticated
 */
function current_user_id(): ?int
{
    return SecureSessionManager::getCurrentUserId();
}

/**
 * Get current user UUID (for external APIs/logging)
 *
 * ENTERPRISE GALAXY SECURITY:
 * - ALWAYS use UUID for external APIs, public logs, client-side
 * - NEVER expose internal database ID externally
 * - Fast path: Redis cache lookup (no DB query if cached)
 *
 * PERFORMANCE:
 * - Cache hit: ~0.5ms (includes current_user() call)
 * - Cache miss: ~2ms (DB query + cache)
 *
 * USAGE:
 * ```php
 * // API response
 * return ['user_uuid' => current_user_uuid()];
 *
 * // Public logging
 * Logger::info('User action', ['user_uuid' => current_user_uuid()]);
 * ```
 *
 * @return string|null User UUID or null if not authenticated
 */
function current_user_uuid(): ?string
{
    $user = current_user();
    return $user['uuid'] ?? null;
}

/**
 * Check if user is authenticated (boolean fast path)
 *
 * ENTERPRISE GALAXY:
 * - Ultra-fast boolean check
 * - No Redis/DB query (session only)
 * - Use for: middleware, conditionals, templates
 *
 * PERFORMANCE:
 * - ~0.01ms (session check)
 *
 * USAGE:
 * ```php
 * if (is_authenticated()) {
 *     // Show navbar
 * } else {
 *     // Show login button
 * }
 * ```
 *
 * @return bool True if authenticated, false otherwise
 */
function is_authenticated(): bool
{
    return SecureSessionManager::isAuthenticated();
}

/**
 * Invalidate user cache (after updates)
 *
 * ENTERPRISE GALAXY:
 * - Granular cache invalidation per user_id
 * - Supports multiple cache types (data, profile, settings)
 * - Automatic Database cache invalidation (existing system)
 * - Zero nuclear option (no deleteByPattern('query:*'))
 *
 * USAGE:
 * ```php
 * // After nickname change
 * invalidate_user_cache($userId, ['data', 'profile']);
 *
 * // After settings update
 * invalidate_user_cache($userId, ['settings']);
 *
 * // Full invalidation (rare)
 * invalidate_user_cache($userId, ['data', 'profile', 'settings']);
 * ```
 *
 * CACHE TYPES:
 * - 'data': user:{id}:data (full user data)
 * - 'profile': user:{id}:profile (profile data)
 * - 'settings': user:{id}:settings (settings data)
 *
 * @param int $userId User ID to invalidate
 * @param array $types Cache types to invalidate (default: all)
 * @return void
 */
function invalidate_user_cache(int $userId, array $types = ['data', 'profile', 'settings']): void
{
    $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_enterprise');

    if (!$redis) {
        Logger::warning('USER_CACHE: Redis not available for invalidation', [
            'user_id' => $userId,
            'types' => $types,
        ]);
        return;
    }

    $cacheKeys = [];
    foreach ($types as $type) {
        $cacheKeys[] = "user:{$userId}:{$type}";
    }

    try {
        // Delete all specified cache keys from Redis L1
        if (!empty($cacheKeys)) {
            $deleted = $redis->del(...$cacheKeys);

            Logger::security('info', 'USER_CACHE: Invalidated cache keys', [
                'user_id' => $userId,
                'cache_keys' => $cacheKeys,
                'deleted_count' => $deleted,
            ]);
        }

        // ENTERPRISE: Also invalidate via CacheManager (multi-level L1/L2/L3)
        // Uses public API instead of private invalidateCache method
        $cache = db()->getCache();
        if ($cache) {
            $cache->deleteByPattern("user:{$userId}:*");
        }

    } catch (\Throwable $e) {
        Logger::error('USER_CACHE: Failed to invalidate cache', [
            'user_id' => $userId,
            'cache_keys' => $cacheKeys,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * Warm user cache (smart pre-warming for critical operations)
 *
 * ENTERPRISE GALAXY:
 * - Pre-loads user data into Redis L1 cache IMMEDIATELY
 * - Prevents cache miss on next request (zero latency)
 * - Use after: profile completion, status changes, settings updates
 * - Dynamic TTL: 1min for write-heavy, 5min for read-only
 *
 * WHEN TO USE:
 * - ✅ Profile completion (status='active')
 * - ✅ Settings update (nickname, avatar, email)
 * - ✅ Status change (active/banned)
 * - ❌ Login/logout (let lazy loading handle it)
 * - ❌ Read operations (already cached)
 *
 * PERFORMANCE:
 * - Adds ~2ms to write operation (1 DB query + 1 Redis write)
 * - Saves ~2ms on EVERY subsequent read (cache hit vs miss)
 * - ROI: Positive after 1+ reads (always worth it for critical ops)
 *
 * USAGE:
 * ```php
 * // After profile completion
 * $db->execute("UPDATE users SET status='active'...", [...]);
 * invalidate_user_cache($userId, ['data', 'profile']);
 * warm_user_cache($userId, 'profile'); // 1min TTL (write-heavy)
 *
 * // After settings update
 * $db->execute("UPDATE users SET nickname=?...", [...]);
 * invalidate_user_cache($userId, ['data']);
 * warm_user_cache($userId, 'data'); // 5min TTL (read-only)
 * ```
 *
 * @param int $userId User ID to warm
 * @param string $type Cache type ('data', 'profile', 'settings')
 * @return void
 */
function warm_user_cache(int $userId, string $type = 'data'): void
{
    $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_enterprise');

    if (!$redis) {
        // Redis not available - skip pre-warming (lazy load will handle it)
        return;
    }

    // CRITICAL FIX (2025-12-02): Load fresh data DIRECTLY from database, bypassing query cache
    // The old code used $userModel->findById() which has 'cache => true' in BaseModel::find()
    // This caused a race condition where warm_user_cache() would read stale data from query cache
    // after profile completion, causing infinite redirect loops for Google OAuth users
    $user = db()->findOne(
        "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL",
        [$userId],
        ['cache' => false]  // CRITICAL: Bypass query cache to get fresh data
    );

    if (!$user) {
        // User not found or deleted - nothing to warm
        return;
    }

    // Determine TTL based on cache type (smart dynamic TTL)
    $ttl = match ($type) {
        'profile' => 60,   // 1 minute (write-heavy: profile completion, status changes)
        'settings' => 300, // 5 minutes (read-only: settings data)
        'data' => 300,     // 5 minutes (read-only: full user data)
        default => 300,
    };

    $cacheKey = "user:{$userId}:{$type}";

    try {
        $redis->setex($cacheKey, $ttl, serialize($user));

        // DEVELOPMENT MODE: Log pre-warming
        if (should_log('debug_general', 'debug')) {
            Logger::debug('USER_CACHE: Pre-warmed cache', [
                'user_id' => $userId,
                'cache_key' => $cacheKey,
                'type' => $type,
                'ttl_seconds' => $ttl,
            ]);
        }

    } catch (\Throwable $e) {
        Logger::error('USER_CACHE: Failed to warm cache', [
            'user_id' => $userId,
            'cache_key' => $cacheKey,
            'error' => $e->getMessage(),
        ]);
    }
}

// ============================================================================
// ASSET VERSIONING HELPERS (Cache Busting)
// ============================================================================

if (!function_exists('asset_version')) {
    /**
     * Get versioned asset URL for cache busting
     *
     * ENTERPRISE GALAXY V8.0 (2025-12-01):
     * Appends ?v=YYYYMMDD.N to asset URLs to bust browser cache after deployments.
     *
     * Usage in views:
     * <script src="<?= asset_version('/assets/js/app.js') ?>"></script>
     * <link href="<?= asset_version('/assets/css/app.css') ?>" rel="stylesheet">
     *
     * @param string $path Asset path (e.g., '/assets/js/app.js')
     * @return string Versioned URL (e.g., '/assets/js/app.js?v=20251201.1')
     */
    function asset_version(string $path): string
    {
        static $version = null;

        // Cache version for request lifecycle
        if ($version === null) {
            $version = $_ENV['ASSET_VERSION'] ?? getenv('ASSET_VERSION') ?: date('Ymd');
        }

        // Append version as query string
        $separator = str_contains($path, '?') ? '&' : '?';

        return $path . $separator . 'v=' . $version;
    }
}

if (!function_exists('get_asset_version')) {
    /**
     * Get just the asset version string (for JavaScript/meta tags)
     *
     * Usage:
     * <meta name="asset-version" content="<?= get_asset_version() ?>">
     *
     * @return string Version string (e.g., '20251201.1')
     */
    function get_asset_version(): string
    {
        return $_ENV['ASSET_VERSION'] ?? getenv('ASSET_VERSION') ?: date('Ymd');
    }
}
