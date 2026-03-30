<?php

namespace Need2Talk\Middleware;

use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY SECURITY: Session Creation Rate Limiting
 *
 * THREAT MITIGATION: Redis Memory Exhaustion via Session Flooding
 *
 * ATTACK SCENARIO:
 * - Attacker creates 10,000 sessions/minute (100 req/sec × 100 threads)
 * - Each session: ~2KB in Redis (serialized PHP session data)
 * - 10k sessions × 2KB = 20MB/minute → 1.2GB/hour
 * - Redis max memory: 2GB → Evicts critical cache → Performance degradation
 * - Session sync worker (5min interval) can't keep up → Sessions pile up
 *
 * PROTECTION STRATEGY:
 * - Rate limit: Max 10 new sessions per IP per hour
 * - Detection: Check if session_id() creates NEW session (vs existing)
 * - Tracking: Redis counter with 1-hour TTL
 * - Response: 429 Too Many Requests + auto-ban IP (24h)
 *
 * PERFORMANCE:
 * - Redis check: <1ms (single GET + INCR operation)
 * - Zero overhead for existing sessions (early return)
 * - Fail-open: If Redis unavailable, allow request (availability priority)
 *
 * COMPATIBILITY:
 * - Works with all session storage backends (Redis, file, database)
 * - Compatible with session_regenerate_id() (doesn't trigger rate limit)
 * - Doesn't block legitimate users (10 sessions/hour = generous)
 *
 * @version 1.0.0
 * @since 2025-11-22
 */
class SessionCreationRateLimitMiddleware
{
    /**
     * ENTERPRISE GALAXY: Tiered rate limiting (PUBLIC vs AUTHENTICATED pages)
     *
     * RATIONALE:
     * - Public pages (/, /login, /register): PERMISSIVE (30 sessions/hour)
     *   → Allows logout cycles, multiple devices, tab hopping, SEO crawlers
     * - Authenticated pages: STRICT (10 sessions/hour)
     *   → Real session flooding risk happens AFTER login
     *
     * SECURITY:
     * - Public session flooding is LOW RISK (anonymous sessions, auto-cleanup)
     * - Authenticated session flooding is HIGH RISK (contains user data, longer TTL)
     * - Nginx rate limiting (200 req/min) protects against DDoS
     * - Redis eviction (allkeys-lru) prevents memory exhaustion
     */
    private const MAX_SESSIONS_PUBLIC = 30;   // Permissive for public pages
    private const MAX_SESSIONS_AUTH = 10;     // Strict for authenticated pages

    /**
     * Rate limit window in seconds (1 hour)
     */
    private const WINDOW_SECONDS = 3600;

    /**
     * Redis key prefixes (separate tracking for public vs auth)
     */
    private const REDIS_PREFIX_PUBLIC = 'session_limit:public:';
    private const REDIS_PREFIX_AUTH = 'session_limit:auth:';

    /**
     * Redis connection (lazy loaded)
     */
    private ?\Redis $redis = null;

    /**
     * Handle incoming request
     */
    public function handle(?callable $next = null)
    {
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // ========================================================================
        // ENTERPRISE GALAXY SECURITY: Block requests without User-Agent
        // ========================================================================
        // DEFENSE IN DEPTH: Nginx blocks first, this catches bypasses
        // Bot scanners without UA create 94 sessions/hour (memory waste)
        // ========================================================================
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (empty($userAgent) && !$this->isInternalIP($clientIP)) {
            Logger::security('warning', 'Request without User-Agent blocked (PHP layer)', [
                'ip' => $clientIP,
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            ]);

            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Forbidden',
                'message' => 'User-Agent header is required',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ENTERPRISE: Skip localhost/Docker internal IPs (development/internal)
        if ($this->isInternalIP($clientIP)) {
            if ($next) {
                return $next();
            }

            return;
        }

        // ENTERPRISE: Skip API routes (they don't create sessions, use stateless auth)
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_starts_with($requestUri, '/api/')) {
            if ($next) {
                return $next();
            }

            return;
        }

        // ENTERPRISE GALAXY: Determine if this is a PUBLIC or AUTHENTICATED page
        // Public pages get more permissive rate limiting (30/hour vs 10/hour)
        $publicPages = [
            '/',
            '/login',
            '/register',
            '/auth/login',
            '/auth/register',
            '/about',
            '/privacy',
            '/terms',
            '/contact',
        ];

        // Parse URI without query string
        $parsedUri = parse_url($requestUri, PHP_URL_PATH) ?? $requestUri;
        $isPublicPage = in_array($parsedUri, $publicPages, true);

        // Determine rate limit and Redis key based on page type
        $maxSessions = $isPublicPage ? self::MAX_SESSIONS_PUBLIC : self::MAX_SESSIONS_AUTH;
        $redisPrefix = $isPublicPage ? self::REDIS_PREFIX_PUBLIC : self::REDIS_PREFIX_AUTH;

        // ENTERPRISE: Check if this request will create a NEW session
        // If session already exists (cookie present), skip rate limiting
        // CRITICAL FIX: Check for session cookie BEFORE session_start() to detect existing sessions
        // This prevents counting page navigation as "new sessions"
        $sessionName = session_name();
        $hasExistingSession = isset($_COOKIE[$sessionName]) && !empty($_COOKIE[$sessionName]);

        if ($hasExistingSession) {
            // Existing session cookie found - no rate limit
            if ($next) {
                return $next();
            }

            return;
        }

        // CRITICAL: Initialize Redis connection
        if (!$this->initRedis()) {
            // Redis unavailable - fail-open (allow request)
            Logger::security('warning', 'Session creation rate limit: Redis unavailable', [
                'ip' => $clientIP,
            ]);

            if ($next) {
                return $next();
            }

            return;
        }

        // Check rate limit (using dynamic prefix based on page type)
        $redisKey = $redisPrefix . $clientIP;
        $count = $this->redis->get($redisKey);

        if ($count === false) {
            // First session creation in this window
            $this->redis->setex($redisKey, self::WINDOW_SECONDS, 1);

            if ($next) {
                return $next();
            }

            return;
        }

        $count = (int) $count;

        if ($count >= $maxSessions) {
            // Rate limit exceeded - block and log
            $this->handleRateLimitExceeded($clientIP, $count, $maxSessions, $isPublicPage);

            return; // 429 response sent, stop execution
        }

        // Increment counter
        $this->redis->incr($redisKey);

        if ($next) {
            return $next();
        }
    }

    /**
     * Handle rate limit exceeded
     *
     * ENTERPRISE GALAXY v6.7: Session Creation Abuse Protection
     *
     * PROTECTION STRATEGY (3-tier defense):
     * 1. Deduplication log (1 log per 15 seconds per IP)
     *    → Prevents log pollution (30+ logs/sec → 4 logs/min)
     *    → 15s window allows tracking attack duration (not just first event)
     * 2. Ban automatico if IP supera 20 session/hour (doppio del limite)
     *    → Auto-ban scanners aggressivi
     *    → Uses AntiVulnerabilityScanningMiddleware for scoring + ban
     * 3. Score incrementale per abuso moderato (10-20 sessions)
     *    → Progressive penalties without instant ban
     *    → Repeated abuse → eventual ban via score accumulation
     *
     * RATIONALE (based on real attack analysis):
     * - Attack IP 38.60.136.9 created 30+ sessions in 1-2 seconds
     * - BEFORE: 30+ identical logs in 2 seconds (log pollution)
     * - AFTER: 4 logs/minute (attack visible but not spammy)
     *
     * @param string $ip Client IP
     * @param int $attemptCount Current session creation count
     * @param int $maxSessions Max sessions allowed
     * @param bool $isPublicPage True if PUBLIC page (30/hour), false if AUTHENTICATED (10/hour)
     */
    private function handleRateLimitExceeded(string $ip, int $attemptCount, int $maxSessions, bool $isPublicPage): void
    {
        $pageType = $isPublicPage ? 'PUBLIC' : 'AUTHENTICATED';

        // ========================================================================
        // PROTECTION #1: Deduplication Log (15-second time window)
        // ========================================================================
        // CRITICAL: Prevents log pollution from rapid session creation
        // Time bucket: 15 seconds (4 logs per minute max)
        // Redis key: session_rate_limit_logged:{ip}:{15s_bucket}
        // TTL: 15 seconds (auto-cleanup)
        // ========================================================================
        $timeBucket = intdiv(time(), 15); // 15-second buckets (4 logs/minute max)
        $dedupKey = "session_rate_limit_logged:{$ip}:{$timeBucket}";

        try {
            // Check if we already logged for this IP in this 15-second window
            if (!$this->redis->exists($dedupKey)) {
                // FIRST log in this 15-second window - log it
                Logger::security('error', 'SECURITY: RATE_LIMIT_EXCEEDED', [
                    'event_type' => 'RATE_LIMIT_EXCEEDED',
                    'ip' => $ip,
                    'page_type' => $pageType,
                    'attempt_count' => $attemptCount,
                    'max_sessions' => $maxSessions,
                    'details' => "Session creation rate limit exceeded ({$pageType} pages): {$attemptCount} sessions in 1 hour (max {$maxSessions})",
                    'timestamp' => time(),
                    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
                ]);

                // Mark as logged for this 15-second window
                $this->redis->setex($dedupKey, 15, '1');
            }
            // ELSE: Already logged in this 15-second window - skip logging (deduplication)

        } catch (\Exception $e) {
            // FAIL-SAFE: If Redis dedup fails, still log (better over-log than under-log)
            Logger::security('error', 'SECURITY: RATE_LIMIT_EXCEEDED', [
                'event_type' => 'RATE_LIMIT_EXCEEDED',
                'ip' => $ip,
                'page_type' => $pageType,
                'attempt_count' => $attemptCount,
                'max_sessions' => $maxSessions,
                'details' => "Session creation rate limit exceeded ({$pageType} pages): {$attemptCount} sessions in 1 hour (max {$maxSessions})",
                'redis_dedup_failed' => true,
                'timestamp' => time(),
            ]);
        }

        // ========================================================================
        // PROTECTION #2: Auto-Ban for Aggressive Abuse (≥20 sessions/hour)
        // ========================================================================
        // CRITICAL: Ban IPs that double the rate limit (clear abuse)
        // Threshold: 20 sessions/hour (2x AUTH limit, 0.66x PUBLIC limit)
        // Score: +50 (instant ban via AntiVulnerabilityScanningMiddleware)
        // Ban duration: 86400 seconds (24 hours)
        // ========================================================================
        $aggressiveThreshold = 20; // 2x AUTHENTICATED limit (clear abuse)

        if ($attemptCount >= $aggressiveThreshold) {
            try {
                // ENTERPRISE v6.5: Use static method for scoring + ban
                // Add critical score (+50 = instant ban)
                \Need2Talk\Middleware\AntiVulnerabilityScanningMiddleware::addScore($ip, 50, 'session_creation_abuse');

                Logger::security('critical', 'SECURITY: SESSION_ABUSE_AUTO_BAN', [
                    'ip' => $ip,
                    'page_type' => $pageType,
                    'attempt_count' => $attemptCount,
                    'threshold' => $aggressiveThreshold,
                    'score_added' => 50,
                    'ban_duration' => 86400,
                    'reason' => 'Aggressive session creation abuse (auto-ban)',
                ]);

            } catch (\Exception $e) {
                // NEVER fail rate limit response because of ban error
                Logger::error('SESSION: Auto-ban failed (non-critical)', [
                    'error' => $e->getMessage(),
                    'ip' => $ip,
                    'attempt_count' => $attemptCount,
                ]);
            }
        }

        // ========================================================================
        // PROTECTION #3: Progressive Scoring for Moderate Abuse (10-19 sessions)
        // ========================================================================
        // STRATEGY: Add score proportional to abuse severity
        // - 10-14 sessions: +5 score (warning)
        // - 15-19 sessions: +10 score (moderate abuse)
        // - Repeated abuse: Score accumulates → eventual ban
        // ========================================================================
        elseif ($attemptCount >= $maxSessions) {
            try {
                // Progressive scoring based on severity
                if ($attemptCount >= 15) {
                    $scoreToAdd = 10; // Moderate abuse
                } else {
                    $scoreToAdd = 5;  // Minor abuse (just over limit)
                }

                // ENTERPRISE v6.5: Use static method for scoring
                \Need2Talk\Middleware\AntiVulnerabilityScanningMiddleware::addScore($ip, $scoreToAdd, 'session_creation_moderate_abuse');

                Logger::security('warning', 'SECURITY: SESSION_ABUSE_SCORE', [
                    'ip' => $ip,
                    'page_type' => $pageType,
                    'attempt_count' => $attemptCount,
                    'max_sessions' => $maxSessions,
                    'score_added' => $scoreToAdd,
                    'reason' => 'Moderate session creation abuse (scoring)',
                ]);

            } catch (\Exception $e) {
                // NEVER fail rate limit response because of scoring error
                Logger::error('SESSION: Progressive scoring failed (non-critical)', [
                    'error' => $e->getMessage(),
                    'ip' => $ip,
                ]);
            }
        }

        // ========================================================================
        // Send 429 Response (always, regardless of ban/score success)
        // ========================================================================
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 3600'); // Retry after 1 hour

        echo json_encode([
            'error' => 'Too many sessions created',
            'message' => 'You have exceeded the maximum number of sessions allowed per hour. Please try again later.',
            'retry_after' => 3600,
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /**
     * Initialize Redis connection
     */
    private function initRedis(): bool
    {
        if ($this->redis !== null) {
            return true;
        }

        try {
            $this->redis = new \Redis();
            $this->redis->connect(
                get_env('REDIS_HOST', 'redis'),
                (int) get_env('REDIS_PORT', 6379)
            );

            $password = get_env('REDIS_PASSWORD');
            if ($password) {
                $this->redis->auth($password);
            }

            // Use DB 3 (rate limiting database)
            $this->redis->select((int) get_env('REDIS_DB_RATE_LIMIT', 3));

            return true;

        } catch (\Exception $e) {
            $this->redis = null;

            return false;
        }
    }

    /**
     * Check if IP is internal (localhost/Docker)
     */
    private function isInternalIP(string $ip): bool
    {
        return str_starts_with($ip, '127.')
            || str_starts_with($ip, '10.')
            || str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '172.16.')
            || str_starts_with($ip, '172.17.')
            || str_starts_with($ip, '172.18.')
            || str_starts_with($ip, '172.19.')
            || str_starts_with($ip, '172.20.')
            || str_starts_with($ip, '172.21.')
            || str_starts_with($ip, '172.22.')
            || str_starts_with($ip, '172.23.')
            || str_starts_with($ip, '172.24.')
            || str_starts_with($ip, '172.25.')
            || str_starts_with($ip, '172.26.')
            || str_starts_with($ip, '172.27.')
            || str_starts_with($ip, '172.28.')
            || str_starts_with($ip, '172.29.')
            || str_starts_with($ip, '172.30.')
            || str_starts_with($ip, '172.31.');
    }
}
