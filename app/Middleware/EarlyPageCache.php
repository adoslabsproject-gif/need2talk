<?php

/**
 * 🚀 ENTERPRISE GALAXY: EarlyPageCache - Zero-Overhead Ultra-Fast Page Cache
 *
 * Revolutionary cache system that works BEFORE bootstrap initialization
 * Performance: 312ms → 2-5ms for cached pages (98% reduction)
 *
 * GALAXY OPTIMIZATIONS:
 * ✅ Static route map for instant skip decisions (no function calls)
 * ✅ Zero logging overhead in production
 * ✅ File-based storage (no Redis dependency before bootstrap)
 * ✅ Intelligent cache strategy per route type
 *
 * @package Need2Talk\Enterprise
 * @version 2.0.0 Galaxy Edition
 */
class EarlyPageCache
{
    private const CACHE_DIR = '/storage/cache/pages/';
    private const CACHE_TTL = 300; // 5 minutes

    // 🚀 GALAXY: Static cacheable routes map (precomputed for zero overhead)
    private const CACHEABLE_ROUTES = [
        '/' => true,
        '/home' => true,
        '/about' => true,
        '/legal/privacy' => true,
        '/legal/terms' => true,
        '/legal/contacts' => true,
        '/help/faq' => true,
        '/help/guide' => true,
        '/help/safety' => true,
    ];

    // 🚀 GALAXY: Static skip patterns (compiled once, used everywhere)
    private const SKIP_PATTERNS = ['/admin', '/auth/', '/api/', '/profile', '/logout', '/verify'];

    /**
     * 🚀 GALAXY OPTIMIZATION: Cache DISABLED - Direct generation faster than SSD I/O
     *
     * PARADOX DISCOVERED (Oct 2025):
     * - In-memory generation (PostgreSQL pool + OPcache): 25-47ms
     * - SSD file cache read: Variable, often slower
     * - Debugbar caching issue: Frozen timing data
     *
     * DECISION: Disable completely. System runs optimally without it.
     * Returns HTML string or null if cache miss
     */
    public static function get(): ?string
    {
        // 🚀 ENTERPRISE 2025: CACHE DISABLED - in-memory faster than SSD
        // Paradox: PostgreSQL pool + OPcache (25-47ms) < SSD I/O (variable)
        return null;
    }

    /**
     * 🚀 ENTERPRISE 2025: Cache write DISABLED
     * In-memory generation outperforms SSD I/O
     */
    public static function set(string $html): void
    {
        // CACHE DISABLED - no write needed
        return;
    }

    /**
     * 🚀 GALAXY: Invalidate cache with glob optimization
     */
    public static function invalidate(?string $uri = null): void
    {
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $cacheDir = $appRoot . self::CACHE_DIR;

        if ($uri === null) {
            // 🚀 GALAXY: Bulk delete with array_map (faster than foreach)
            array_map('unlink', glob($cacheDir . '*.html') ?: []);
        } else {
            // Invalidate specific URL
            $cacheKey = self::getCacheKey($uri);
            @unlink($cacheDir . $cacheKey . '.html');
        }
    }

    /**
     * Generate cache key from URI
     */
    private static function getCacheKey(string $uri): string
    {
        // Normalize homepage URLs
        if ($uri === '/' || $uri === '/index.php') {
            return 'homepage';
        }

        // Use MD5 of URI as cache key
        return md5($uri);
    }

    /**
     * Get full cache file path
     */
    private static function getCacheFilePath(string $cacheKey): string
    {
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);

        return $appRoot . self::CACHE_DIR . $cacheKey . '.html';
    }
}
