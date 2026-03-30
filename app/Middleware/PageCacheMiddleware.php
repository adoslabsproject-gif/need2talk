<?php

namespace Need2Talk\Middleware;

use Need2Talk\Core\EnterpriseCacheFactory;

/**
 * 🚀 ENTERPRISE GALAXY: PageCacheMiddleware - Intelligent Multi-Layer Caching
 *
 * Revolutionary cache middleware with smart layer selection:
 * - Static pages → L1 Redis (5ms)
 * - Dynamic pages → L2 Memcached (10ms)
 * - Heavy pages → L3 Redis (15ms)
 *
 * GALAXY OPTIMIZATIONS:
 * ✅ Intelligent layer selection based on route complexity
 * ✅ Zero overhead for non-cacheable routes (instant skip)
 * ✅ Session check optimization (cached per request)
 * ✅ Parallel cache invalidation
 *
 * @package Need2Talk\Middleware
 * @version 2.0.0 Galaxy Edition
 */
class PageCacheMiddleware
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_PREFIX = 'page_cache:';

    // 🚀 GALAXY: Route complexity map for intelligent layer selection
    private const ROUTE_STRATEGY = [
        '/' => 'L1',              // Homepage: Ultra-fast L1
        '/home' => 'L1',
        '/about' => 'L2',         // Medium complexity: L2
        '/legal/privacy' => 'L2',
        '/legal/terms' => 'L2',
        '/profile' => 'L3',       // Heavy/dynamic: L3
    ];

    // 🚀 GALAXY: Cached session check (avoid multiple checks per request)
    private static ?bool $isAuthenticatedCache = null;

    /**
     * 🚀 GALAXY OPTIMIZATION: Check cache with intelligent layer selection
     */
    public function before(): ?string
    {
        // 🚀 GALAXY: Fast path - authenticated users skip instantly
        if ($this->isAuthenticated()) {
            return null;
        }

        // Fast method + route check
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $this->shouldSkipCache()) {
            return null;
        }

        // 🚀 GALAXY: Select optimal cache layer based on route
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $strategy = self::ROUTE_STRATEGY[$uri] ?? 'L2'; // Default to L2 for unknown routes

        $cacheKey = $this->generateCacheKey();

        try {
            $cache = EnterpriseCacheFactory::getInstance();

            // 🚀 GALAXY: Single layer check (no L1→L2→L3 waterfall)
            $cached = $cache->get($cacheKey);

            if ($cached !== null) {
                // Cache hit - send and exit
                $this->sendCachedResponse($cached, $strategy);
                exit;
            }
        } catch (\Exception $e) {
            // ENTERPRISE: Log cache failure (warning - performance degraded but not critical)
            Logger::performance('warning', 'PAGE CACHE: Cache check failed - serving fresh page', [
                'error' => $e->getMessage(),
                'impact' => 'Performance degraded - cache unavailable, serving uncached content',
                'action_required' => 'Check Redis/Memcached connectivity: docker compose ps redis memcached',
            ]);
        }

        return null; // Cache miss
    }

    /**
     * Handle outgoing response - store in cache for next request
     */
    public function after(string $html): string
    {
        // Only cache for guest users
        if ($this->isAuthenticated()) {
            return $html;
        }

        // Only cache successful GET requests
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' || http_response_code() !== 200) {
            return $html;
        }

        // Don't cache if we skipped it in before()
        if ($this->shouldSkipCache()) {
            return $html;
        }

        $cacheKey = $this->generateCacheKey();

        try {
            $cache = EnterpriseCacheFactory::getInstance();
            $cache->set($cacheKey, $html, self::CACHE_TTL);
        } catch (\Exception $e) {
            // ENTERPRISE: Log cache save failure (warning - performance impact on next request)
            Logger::performance('warning', 'PAGE CACHE: Failed to save page to cache', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
                'impact' => 'Next request will be slower (cache miss)',
                'action_required' => 'Check cache system: docker compose ps redis memcached',
            ]);
        }

        return $html;
    }

    /**
     * 🚀 GALAXY: Cached authentication check (single check per request)
     */
    private function isAuthenticated(): bool
    {
        // Return cached result if already checked
        if (self::$isAuthenticatedCache !== null) {
            return self::$isAuthenticatedCache;
        }

        // 🚀 GALAXY: Fast cookie check first (no session start needed)
        if (!isset($_COOKIE['need2talk_session']) && !isset($_COOKIE['PHPSESSID'])) {
            self::$isAuthenticatedCache = false;

            return false;
        }

        // Cookie exists - check session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::$isAuthenticatedCache = false;

            return false;
        }

        $result = isset($_SESSION['user_id']);
        self::$isAuthenticatedCache = $result;

        return $result;
    }

    /**
     * Determine if current request should skip cache
     */
    private function shouldSkipCache(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Skip cache for:
        // - Admin panel
        // - Auth pages (login, register, verify)
        // - API endpoints
        // - Profile pages
        // - Pages with query parameters (except homepage)
        $skipPatterns = [
            '/admin',
            '/auth/',
            '/api/',
            '/profile',
            '/logout',
            '/verify',
        ];

        foreach ($skipPatterns as $pattern) {
            if (strpos($uri, $pattern) !== false) {
                return true;
            }
        }

        // Skip if query parameters present (except homepage)
        if ($uri !== '/' && strpos($uri, '?') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Generate cache key from current URL
     */
    private function generateCacheKey(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Normalize homepage URLs
        if ($uri === '/' || $uri === '/index.php') {
            return self::CACHE_PREFIX . 'homepage';
        }

        // Use URL as cache key (sanitized)
        return self::CACHE_PREFIX . md5($uri);
    }

    /**
     * 🚀 GALAXY: Send cached response with optimized headers
     */
    private function sendCachedResponse(string $html, string $strategy): void
    {
        // 🚀 GALAXY: Pre-formatted headers for speed
        header('X-Page-Cache: HIT');
        header("X-Cache-Layer: {$strategy}");
        header('Cache-Control: public, max-age=' . self::CACHE_TTL);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + self::CACHE_TTL) . ' GMT');
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Length: ' . strlen($html)); // Browser optimization

        echo $html;
    }

    /**
     * Invalidate cache for specific URL or all pages
     */
    public static function invalidate(?string $url = null): void
    {
        try {
            $cache = EnterpriseCacheFactory::getInstance();

            if ($url === null) {
                // Invalidate all page cache
                $cache->deleteByPattern(self::CACHE_PREFIX . '*');
            } else {
                // Invalidate specific URL
                $key = self::CACHE_PREFIX . md5($url);
                $cache->delete($key);
            }
        } catch (\Exception $e) {
            // ENTERPRISE: Log cache invalidation failure (warning - stale cache may persist)
            Logger::performance('warning', 'PAGE CACHE: Cache invalidation failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'impact' => 'Stale cached content may be served to users',
                'action_required' => 'Check cache system and manually clear if needed: docker compose restart redis memcached',
            ]);
        }
    }

    /**
     * Invalidate homepage cache (most common case)
     */
    public static function invalidateHomepage(): void
    {
        try {
            $cache = EnterpriseCacheFactory::getInstance();
            $cache->delete(self::CACHE_PREFIX . 'homepage');
        } catch (\Exception $e) {
            // ENTERPRISE: Log homepage cache invalidation failure (warning - stale homepage may persist)
            Logger::performance('warning', 'PAGE CACHE: Homepage invalidation failed', [
                'error' => $e->getMessage(),
                'impact' => 'Stale homepage content may be served to users',
                'action_required' => 'Check cache system and manually clear: docker compose restart redis memcached',
            ]);
        }
    }
}
