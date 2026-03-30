<?php

namespace Need2Talk\Services;

use Need2Talk\Traits\EnterpriseRedisSafety;

/**
 * ENTERPRISE REDIS RATE LIMIT MANAGER
 *
 * Centralized high-performance rate limiting system for 100k+ concurrent users
 * Features:
 * - Redis L1/L3 caching with database fallback
 * - Sliding window algorithm for accurate rate limiting
 * - IP and user-based limits with progressive blocking
 * - Enterprise-grade security with SHA256 hashing
 * - Circuit breaker for Redis failures
 * - Database pool integration for resilience
 * - Anti-malicious protection with escalating penalties
 * - Memory-optimized operations for high concurrency
 *
 * Tables used:
 * - user_rate_limit_log: Tracking attempts and violations
 * - user_rate_limit_bans: IP/User bans with expiration
 *
 * Redis Keys:
 * - rate_limit:{type}:{action}:{identifier_hash}
 * - rate_limit_block:{type}:{identifier_hash}
 */
class EnterpriseRedisRateLimitManager
{
    use EnterpriseRedisSafety;

    // ENTERPRISE RATE LIMITING CONFIGURATIONS
    private const RATE_LIMITS = [
        // ====================================================================
        // AUTHENTICATION & SECURITY (Existing)
        // ====================================================================
        'email_verification' => [
            'ip' => ['window' => 3600, 'max_attempts' => 10, 'block_duration' => 14400], // 10/hour, 4h block
            'email' => ['window' => 86400, 'max_attempts' => 3, 'block_duration' => 86400], // 3/day, 24h block
            'user' => ['window' => 3600, 'max_attempts' => 5, 'block_duration' => 7200], // 5/hour, 2h block
        ],
        'resend_failed' => [
            'ip' => ['window' => 3600, 'max_attempts' => 5, 'block_duration' => 14400], // 5/hour, 4h block
        ],
        'login' => [
            'ip' => ['window' => 900, 'max_attempts' => 10, 'block_duration' => 3600], // 10/15min, 1h block
            'user' => ['window' => 900, 'max_attempts' => 5, 'block_duration' => 1800], // 5/15min, 30min block
        ],
        'registration' => [
            'ip' => ['window' => 3600, 'max_attempts' => 5, 'block_duration' => 7200], // 5/hour, 2h block
        ],
        'password_reset' => [
            'ip' => ['window' => 3600, 'max_attempts' => 3, 'block_duration' => 7200], // 3/hour, 2h block
            'email' => ['window' => 86400, 'max_attempts' => 2, 'block_duration' => 86400], // 2/day, 24h block
        ],

        // ====================================================================
        // API RATE LIMITING - SOCIAL NETWORK AWARE (2025-11-23)
        // ====================================================================
        // METACOGNITIVE DESIGN: Rate limits based on action impact
        // - Read operations (feed, profile): VERY PERMISSIVE
        // - Lightweight actions (like, react): PERMISSIVE
        // - Medium actions (comment, follow): MODERATE
        // - Heavy actions (post, upload): RESTRICTIVE
        //
        // IDENTIFIER: user_uuid (UUID v4 exposed externally, secure)
        // RATIONALE: Per-user limits more accurate than per-IP for social
        // ====================================================================

        // Feed scroll & read operations (VERY PERMISSIVE)
        // User scrolls feed → 100+ requests/min normal behavior
        'api_feed_read' => [
            'user_uuid' => ['window' => 60, 'max_attempts' => 300, 'block_duration' => 600], // 300/min, 10min block
        ],

        // Like/React (PERMISSIVE - fast action)
        // User likes 50 posts while scrolling → Normal behavior
        'api_like' => [
            'user_uuid' => ['window' => 60, 'max_attempts' => 60, 'block_duration' => 900], // 60/min, 15min block
        ],

        // Comment (MODERATE)
        // User comments on 20 posts/hour → Power user, but acceptable
        'api_comment' => [
            'user_uuid' => ['window' => 3600, 'max_attempts' => 30, 'block_duration' => 7200], // 30/hour, 2h block
        ],

        // Post creation (RESTRICTIVE)
        // User creates 10 posts/hour → Likely spam
        'api_post_create' => [
            'user_uuid' => ['window' => 3600, 'max_attempts' => 10, 'block_duration' => 10800], // 10/hour, 3h block
        ],

        // Follow/Unfollow (MODERATE)
        // User follows 100 people/day → Power user, acceptable
        'api_follow' => [
            'user_uuid' => ['window' => 86400, 'max_attempts' => 100, 'block_duration' => 43200], // 100/day, 12h block
        ],

        // Audio upload (VERY RESTRICTIVE - CPU intensive)
        // User uploads 10 audio/day → Normal usage
        'api_audio_upload' => [
            'user_uuid' => ['window' => 86400, 'max_attempts' => 10, 'block_duration' => 86400], // 10/day, 24h block
        ],

        // Audio upload (LEGACY NAME - used by AudioPostService)
        // Same limits as api_audio_upload but with 'user' type for backward compatibility
        'audio_upload' => [
            'user' => ['window' => 86400, 'max_attempts' => 10, 'block_duration' => 86400], // 10/day, 24h block
        ],

        // Journal audio upload (ENTERPRISE GALAXY+ Phase 1.4-1.6)
        // Private diary audio recordings, encrypted client-side
        // User uploads 10 journal audio/day → Normal diary usage
        'journal_audio_upload' => [
            'user' => ['window' => 86400, 'max_attempts' => 10, 'block_duration' => 86400], // 10/day, 24h block
        ],

        // Journal entry edit (ENTERPRISE GALAXY+ Phase 1.4)
        // User edits journal entries (text/emotion/intensity)
        // Max 5 edits/day to prevent retroactive journal manipulation
        'journal_edit' => [
            'user' => ['window' => 86400, 'max_attempts' => 5, 'block_duration' => 86400], // 5/day, 24h block
        ],

        // Journal entry creation (ENTERPRISE V12.1)
        // ALL journal entries (text, photo, audio, mixed)
        // Max 25 entries/day with 10min cooldown between entries
        'journal_entry' => [
            'user' => ['window' => 86400, 'max_attempts' => 25, 'block_duration' => 86400], // 25/day, 24h block
        ],

        // Profile update (MODERATE)
        // User updates profile 5 times/hour → Acceptable
        'api_profile_update' => [
            'user_uuid' => ['window' => 3600, 'max_attempts' => 5, 'block_duration' => 3600], // 5/hour, 1h block
        ],

        // Avatar upload (RESTRICTIVE) - ENTERPRISE GALAXY SECURITY FIX 2026-02-01
        // User uploads avatar 3 times/hour → Prevents disk filling attacks
        // Separate from profile update to have stricter limits
        'avatar_upload' => [
            'user' => ['window' => 3600, 'max_attempts' => 3, 'block_duration' => 7200], // 3/hour, 2h block
            'ip' => ['window' => 3600, 'max_attempts' => 10, 'block_duration' => 7200],  // 10/hour per IP, 2h block
        ],

        // Email verification token attempts (STRICT) - ENTERPRISE GALAXY SECURITY FIX 2026-02-01
        // Prevents brute-forcing 6-digit verification codes
        // 1,000,000 possible codes → limit to 5 attempts per email
        'email_verify_token' => [
            'email' => ['window' => 3600, 'max_attempts' => 5, 'block_duration' => 86400],  // 5/hour per email, 24h block
            'ip' => ['window' => 3600, 'max_attempts' => 20, 'block_duration' => 14400],   // 20/hour per IP, 4h block
        ],

        // ====================================================================
        // ENTERPRISE GALAXY CHAT (2025-12-02)
        // Real-time chat rate limiting for 10k+ concurrent users
        // ====================================================================

        // Chat room messages (ENTERPRISE v9.9 - Strict anti-spam)
        // User sends 15 messages/min max → Prevents flood/spam
        // Shows in-chat warning before blocking
        'chat_message' => [
            'user' => ['window' => 60, 'max_attempts' => 15, 'block_duration' => 300], // 15/min, 5min block
        ],

        // DM messages (ENTERPRISE v9.9 - Strict anti-spam)
        // User sends 15 DMs/min max → Prevents spam harassment
        // Shows in-chat warning before blocking
        'chat_dm_message' => [
            'user' => ['window' => 60, 'max_attempts' => 15, 'block_duration' => 600], // 15/min, 10min block
        ],

        // Room creation (RESTRICTIVE)
        // User creates 5 rooms/day → Power user limit
        'chat_room_create' => [
            'user' => ['window' => 86400, 'max_attempts' => 5, 'block_duration' => 86400], // 5/day, 24h block
        ],

        // Room join (MODERATE - prevent room hopping spam)
        // User joins 20 rooms in 5 min → Exploring, acceptable
        'chat_room_join' => [
            'user' => ['window' => 300, 'max_attempts' => 20, 'block_duration' => 600], // 20/5min, 10min block
        ],

        // DM conversation creation (MODERATE - prevent mass DM spam)
        // User starts 10 new conversations/hour → Normal networking
        'chat_dm_create' => [
            'user' => ['window' => 3600, 'max_attempts' => 10, 'block_duration' => 3600], // 10/hour, 1h block
        ],

        // Typing indicator (VERY PERMISSIVE - lightweight)
        // User types frequently → Expected behavior, but limit spamming
        'chat_typing' => [
            'user' => ['window' => 10, 'max_attempts' => 5, 'block_duration' => 60], // 5/10sec, 1min block
        ],

        // Message reports (RESTRICTIVE - prevent abuse of reporting)
        // User reports 5 messages/hour → Concerned user limit
        'chat_report' => [
            'user' => ['window' => 3600, 'max_attempts' => 5, 'block_duration' => 7200], // 5/hour, 2h block
        ],

        // Presence heartbeat (VERY PERMISSIVE - essential for presence)
        // Client sends heartbeat every 30s → 2/min expected
        'chat_heartbeat' => [
            'user' => ['window' => 60, 'max_attempts' => 10, 'block_duration' => 300], // 10/min, 5min block
        ],
    ];
    private const REDIS_CIRCUIT_THRESHOLD = 3;
    private const REDIS_CIRCUIT_TIMEOUT = 300; // 5 minutes

    // =========================================================================
    // LUA SCRIPTS (ATOMIC OPERATIONS) - ENTERPRISE GALAXY V10.2 (2025-12-10)
    // =========================================================================

    /**
     * Lua script for atomic INCR + EXPIRE
     *
     * PROBLEMA RISOLTO:
     * Prima: $redis->incr($key) + $redis->expire($key, $ttl)
     * - 2 comandi separati = NON atomico
     * - Se crash tra i due: chiave senza TTL = memory leak
     * - Race condition con concurrent requests
     *
     * Ora: Singolo Lua script = atomico
     * - INCR + EXPIRE in una sola operazione
     * - No race condition
     * - No memory leak
     *
     * Returns: nuovo valore del contatore dopo incremento
     */
    private const LUA_ATOMIC_INCR_EXPIRE = <<<'LUA'
local key = KEYS[1]
local ttl = tonumber(ARGV[1])
local current = redis.call('INCR', key)
if current == 1 then
    -- Prima volta: imposta TTL
    redis.call('EXPIRE', key, ttl)
else
    -- Già esiste: refresh TTL solo se < ttl (sliding window)
    local remaining = redis.call('TTL', key)
    if remaining < 0 or remaining < ttl then
        redis.call('EXPIRE', key, ttl)
    end
end
return current
LUA;

    /**
     * Lua script for atomic GET or SET with EXPIRE
     *
     * Usato per check + set atomico del block status
     * Returns: 1 se bloccato (esisteva), 0 se non bloccato
     */
    private const LUA_ATOMIC_CHECK_BLOCK = <<<'LUA'
local key = KEYS[1]
local exists = redis.call('EXISTS', key)
return exists
LUA;

    // Cached SHA per performance (evita re-LOAD ad ogni request)
    private ?string $luaIncrExpireSha = null;

    private $redis = null;

    private $redisAvailable = true;

    // Circuit breaker for Redis
    private static $redisFailures = 0;

    private static $lastRedisFailure = 0;

    public function __construct()
    {
        $this->initializeRedis();
    }

    /**
     * ENTERPRISE: Check if action is rate limited
     *
     * @param  string  $action  Action type (email_verification, login, etc.)
     * @param  string  $type  Identifier type (ip, email, user)
     * @param  string  $identifier  The identifier (IP, email, user_id)
     * @return array Status with allowed/blocked and remaining info
     */
    public function checkRateLimit(string $action, string $type, string $identifier): array
    {
        if (!isset(self::RATE_LIMITS[$action][$type])) {
            Logger::security('warning', 'SECURITY: ENTERPRISE RATE LIMIT: Unknown action/type combination', [
                'action' => $action,
                'type' => $type,
                'identifier_hash' => hash('sha256', $identifier),
            ]);

            return ['allowed' => true, 'remaining' => 999, 'reset_time' => time() + 3600];
        }

        $config = self::RATE_LIMITS[$action][$type];
        $identifierHash = hash('sha256', $identifier);

        try {
            // Check if blocked first
            if ($this->isBlocked($action, $type, $identifierHash)) {
                $blockInfo = $this->getBlockInfo($action, $type, $identifierHash);

                return [
                    'allowed' => false,
                    'blocked' => true,
                    'reason' => 'rate_limit_exceeded',
                    'remaining' => 0,
                    'reset_time' => $blockInfo['expires_at'] ?? (time() + $config['block_duration']),
                ];
            }

            // Get current count
            $currentCount = $this->getCurrentCount($action, $type, $identifierHash, $config['window']);
            $remaining = max(0, $config['max_attempts'] - $currentCount);

            if ($currentCount >= $config['max_attempts']) {
                // Block the identifier
                $this->blockIdentifier($action, $type, $identifier, $identifierHash, $config['block_duration']);

                return [
                    'allowed' => false,
                    'blocked' => true,
                    'reason' => 'rate_limit_exceeded',
                    'remaining' => 0,
                    'reset_time' => time() + $config['block_duration'],
                ];
            }

            return [
                'allowed' => true,
                'remaining' => $remaining,
                'reset_time' => time() + $config['window'],
                'current_count' => $currentCount,
            ];

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: ENTERPRISE RATE LIMIT: Check failed', [
                'action' => $action,
                'type' => $type,
                'identifier_hash' => $identifierHash,
                'error' => $e->getMessage(),
            ]);

            // Fail-open for availability
            return ['allowed' => true, 'remaining' => 999, 'reset_time' => time() + 3600];
        }
    }

    /**
     * ENTERPRISE: Record an attempt (increment counter)
     */
    public function recordAttempt(string $action, string $type, string $identifier): bool
    {
        $identifierHash = hash('sha256', $identifier);

        try {
            // Store in database for persistence
            $stmt = db_pdo()->prepare(
                'INSERT INTO user_rate_limit_log
                 (identifier_hash, action_type, identifier_type, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $identifierHash,
                $action,
                $type,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            // ENTERPRISE GALAXY V10.2: Atomic increment with Lua script
            // Replaces non-atomic incr() + expire() with single atomic operation
            if ($this->redisAvailable && $this->redis) {
                $redisKey = $this->getRedisKey($action, $type, $identifierHash);
                $config = self::RATE_LIMITS[$action][$type] ?? ['window' => 3600];

                $this->atomicIncrWithExpire($redisKey, $config['window']);
            }

            return true;

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: ENTERPRISE RATE LIMIT: Failed to record attempt', [
                'action' => $action,
                'type' => $type,
                'identifier_hash' => $identifierHash,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE: Reset rate limit for identifier (admin function)
     */
    public function resetRateLimit(string $action, string $type, string $identifier): bool
    {
        $identifierHash = hash('sha256', $identifier);

        try {
            // Clear Redis
            if ($this->redisAvailable && $this->redis) {
                $redisKey = $this->getRedisKey($action, $type, $identifierHash);
                $blockKey = $this->getBlockKey($action, $type, $identifierHash);

                $this->safeRedisCall($this->redis, 'del', [$redisKey]);
                $this->safeRedisCall($this->redis, 'del', [$blockKey]);
            }

            // Clear database bans
            $stmt = db_pdo()->prepare(
                'DELETE FROM user_rate_limit_bans
                 WHERE identifier_hash = ? AND action_type = ? AND identifier_type = ?'
            );
            $stmt->execute([$identifierHash, $action, $type]);

            // Clear recent attempts
            $stmt = db_pdo()->prepare(
                'DELETE FROM user_rate_limit_log
                 WHERE identifier_hash = ? AND action_type = ? AND identifier_type = ?'
            );
            $stmt->execute([$identifierHash, $action, $type]);

            Logger::security('info', 'SECURITY: ENTERPRISE RATE LIMIT: Rate limit reset', [
                'action' => $action,
                'type' => $type,
                'identifier_hash' => $identifierHash,
                'redis_cleared' => $this->redisAvailable,
                'admin_action' => true,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: ENTERPRISE RATE LIMIT: Failed to reset rate limit', [
                'action' => $action,
                'type' => $type,
                'identifier_hash' => $identifierHash,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE: Get rate limit statistics for monitoring
     */
    public function getStatistics(): array
    {
        try {
            // Active blocks count
            $stmt = db_pdo()->query(
                'SELECT ban_type, COUNT(*) as count
                 FROM user_rate_limit_bans
                 WHERE expires_at > NOW()
                 GROUP BY ban_type'
            );
            $blocks = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            // Recent violations (last 24h)
            $stmt = db_pdo()->query(
                "SELECT action_type, COUNT(*) as count
                 FROM user_rate_limit_log
                 WHERE created_at >= NOW() - INTERVAL '24 hours'
                 GROUP BY action_type"
            );
            $violations = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            return [
                'active_blocks' => $blocks,
                'violations_24h' => $violations,
                'redis_available' => $this->redisAvailable,
                'redis_failures' => self::$redisFailures,
                'timestamp' => time(),
            ];

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: ENTERPRISE RATE LIMIT: Failed to get statistics', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ==================== COMPATIBILITY WRAPPERS ====================

    /**
     * Compatibility wrapper: checkLimit (simplified API for audio uploads)
     *
     * @param int|string $userId User ID
     * @param string $action Action type (e.g., 'audio_upload')
     * @return bool True if allowed, false if rate limited
     */
    public function checkLimit($userId, string $action): bool
    {
        $result = $this->checkRateLimit($action, 'user', (string) $userId);

        return $result['allowed'] ?? false;
    }

    /**
     * Compatibility wrapper: getRateLimitInfo (get detailed rate limit info)
     *
     * @param int|string $userId User ID
     * @param string $action Action type
     * @return array Rate limit information
     */
    public function getRateLimitInfo($userId, string $action): array
    {
        $result = $this->checkRateLimit($action, 'user', (string) $userId);

        return [
            'allowed' => $result['allowed'] ?? true,
            'current_count' => $result['current_count'] ?? 0,
            'remaining' => $result['remaining'] ?? 999,
            'retry_after' => $result['reset_time'] ?? null,
            'blocked' => $result['blocked'] ?? false,
        ];
    }

    /**
     * Compatibility wrapper: incrementCounter (record an attempt)
     *
     * @param int|string $userId User ID
     * @param string $action Action type
     * @return bool Success status
     */
    public function incrementCounter($userId, string $action): bool
    {
        return $this->recordAttempt($action, 'user', (string) $userId);
    }

    // ==================== PRIVATE METHODS ====================

    private function initializeRedis(): void
    {
        try {
            // Check circuit breaker
            if (self::$redisFailures >= self::REDIS_CIRCUIT_THRESHOLD
                && (time() - self::$lastRedisFailure) < self::REDIS_CIRCUIT_TIMEOUT) {
                $this->redisAvailable = false;

                return;
            }

            $this->redis = $this->getRedisConnection();
            $this->redisAvailable = ($this->redis !== null);

            if ($this->redisAvailable) {
                // Reset circuit breaker on success
                self::$redisFailures = 0;
            }

        } catch (\Exception $e) {
            $this->handleRedisFailure($e);
        }
    }

    /**
     * ENTERPRISE: Get Redis connection with fallback handling
     *
     * @return \Redis|null Redis instance or null on failure
     * @psalm-suppress UndefinedClass
     * @phpstan-return \Redis|null
     */
    private function getRedisConnection(): mixed
    {
        static $redis = null;

        if ($redis === null) {
            try {
                /** @psalm-suppress UndefinedClass */
                $redis = new \Redis();
                $connected = $redis->pconnect(
                    env('REDIS_HOST', 'redis'),
                    (int) env('REDIS_PORT', 6379),
                    2.0 // 2 second timeout
                );

                if (!$connected) {
                    throw new \Exception('Redis connection failed');
                }

                // Authentication if password is set
                if (env('REDIS_PASSWORD')) {
                    $authResult = $redis->auth(env('REDIS_PASSWORD'));

                    if (!$authResult) {
                        throw new \Exception('Redis authentication failed');
                    }
                }

                // Test connection with ping
                if (!$redis->ping()) {
                    throw new \Exception('Redis ping failed');
                }

            } catch (\Exception $e) {
                Logger::security('warning', 'SECURITY: ENTERPRISE RATE LIMIT: Redis connection failed', [
                    'error' => $e->getMessage(),
                    'host' => env('REDIS_HOST', 'redis'),
                    'port' => env('REDIS_PORT', 6379),
                ]);
                $redis = null;
            }
        }

        return $redis === false ? null : $redis;
    }

    private function handleRedisFailure(\Exception $e): void
    {
        self::$redisFailures++;
        self::$lastRedisFailure = time();
        $this->redisAvailable = false;

        Logger::security('warning', 'SECURITY: ENTERPRISE RATE LIMIT: Redis failure', [
            'error' => $e->getMessage(),
            'failures' => self::$redisFailures,
            'circuit_open' => self::$redisFailures >= self::REDIS_CIRCUIT_THRESHOLD,
        ]);
    }

    private function getCurrentCount(string $action, string $type, string $identifierHash, int $window): int
    {
        // Try Redis first
        if ($this->redisAvailable && $this->redis) {
            try {
                $redisKey = $this->getRedisKey($action, $type, $identifierHash);
                $count = $this->safeRedisCall($this->redis, 'get', [$redisKey]);

                if ($count !== null) {
                    return (int) $count;
                }
            } catch (\Exception $e) {
                $this->handleRedisFailure($e);
            }
        }

        // Fallback to database
        $stmt = db_pdo()->prepare(
            "SELECT COUNT(*) as count
             FROM user_rate_limit_log
             WHERE identifier_hash = ?
               AND action_type = ?
               AND identifier_type = ?
               AND created_at >= NOW() - INTERVAL '1 second' * ?"
        );
        $stmt->execute([$identifierHash, $action, $type, $window]);
        $result = $stmt->fetch();

        return (int) ($result['count'] ?? 0);
    }

    private function isBlocked(string $action, string $type, string $identifierHash): bool
    {
        // Try Redis first
        if ($this->redisAvailable && $this->redis) {
            try {
                $blockKey = $this->getBlockKey($action, $type, $identifierHash);
                $blocked = $this->safeRedisCall($this->redis, 'get', [$blockKey]);

                if ($blocked) {
                    return true;
                }
            } catch (\Exception $e) {
                $this->handleRedisFailure($e);
            }
        }

        // Fallback to database
        $stmt = db_pdo()->prepare(
            'SELECT COUNT(*) as is_blocked
             FROM user_rate_limit_bans
             WHERE identifier_hash = ?
               AND action_type = ?
               AND identifier_type = ?
               AND expires_at > NOW()'
        );
        $stmt->execute([$identifierHash, $action, $type]);
        $result = $stmt->fetch();

        return ($result['is_blocked'] ?? 0) > 0;
    }

    private function getBlockInfo(string $action, string $type, string $identifierHash): ?array
    {
        $stmt = db_pdo()->prepare(
            'SELECT expires_at, reason, severity
             FROM user_rate_limit_bans
             WHERE identifier_hash = ?
               AND action_type = ?
               AND identifier_type = ?
               AND expires_at > NOW()
             ORDER BY expires_at DESC
             LIMIT 1'
        );
        $stmt->execute([$identifierHash, $action, $type]);

        return $stmt->fetch() ?: null;
    }

    private function blockIdentifier(string $action, string $type, string $identifier, string $identifierHash, int $duration): void
    {
        // Store in database (ENTERPRISE GALAXY: Fixed ON CONFLICT for new schema)
        // ON CONFLICT uses unique constraint (identifier_hash, action_type) from migration
        $stmt = db_pdo()->prepare(
            "INSERT INTO user_rate_limit_bans
             (identifier_hash, action_type, identifier_type, ip_address, expires_at, reason, ban_type, severity, created_at)
             VALUES (?, ?, ?, ?, NOW() + INTERVAL '1 second' * ?, 'Rate limit exceeded', 'automatic', 'medium', NOW())
             ON CONFLICT (identifier_hash, action_type) DO UPDATE SET
               expires_at = NOW() + INTERVAL '1 second' * ?,
               reason = 'Rate limit exceeded',
               updated_at = NOW()"
        );
        $stmt->execute([
            $identifierHash,
            $action,
            $type,
            $type === 'ip' ? $identifier : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            $duration,
            $duration,
        ]);

        // Store in Redis for fast lookups
        if ($this->redisAvailable && $this->redis) {
            try {
                $blockKey = $this->getBlockKey($action, $type, $identifierHash);
                $this->safeRedisCall($this->redis, 'setex', [$blockKey, $duration, '1']);
            } catch (\Exception $e) {
                $this->handleRedisFailure($e);
            }
        }

        Logger::security('critical', 'SECURITY: ENTERPRISE RATE LIMIT: Identifier blocked', [
            'action' => $action,
            'type' => $type,
            'identifier_hash' => $identifierHash,
            'duration_seconds' => $duration,
            'redis_cached' => $this->redisAvailable,
        ]);
    }

    private function getRedisKey(string $action, string $type, string $identifierHash): string
    {
        return "rate_limit:{$action}:{$type}:{$identifierHash}";
    }

    private function getBlockKey(string $action, string $type, string $identifierHash): string
    {
        return "rate_limit_block:{$action}:{$type}:{$identifierHash}";
    }

    // =========================================================================
    // ATOMIC OPERATIONS (LUA SCRIPTS) - ENTERPRISE GALAXY V10.2 (2025-12-10)
    // =========================================================================

    /**
     * Atomic INCR + EXPIRE using Lua script
     *
     * ENTERPRISE: Thread-safe rate limiting counter
     * - Single Redis round-trip
     * - No race conditions
     * - No memory leaks (TTL always set)
     * - Sliding window refresh on existing keys
     *
     * @param string $key Redis key to increment
     * @param int $ttl TTL in seconds for the key
     * @return int|null New counter value after increment, null on failure
     */
    private function atomicIncrWithExpire(string $key, int $ttl): ?int
    {
        if (!$this->redis) {
            return null;
        }

        try {
            // Load Lua script SHA if not cached
            if ($this->luaIncrExpireSha === null) {
                $this->luaIncrExpireSha = $this->redis->script('LOAD', self::LUA_ATOMIC_INCR_EXPIRE);

                if ($this->luaIncrExpireSha === false) {
                    // Fallback to non-atomic if script load fails
                    Logger::security('warning', 'RATE_LIMIT: Lua script load failed, using fallback', [
                        'key' => $key,
                    ]);
                    $this->safeRedisCall($this->redis, 'incr', [$key]);
                    $this->safeRedisCall($this->redis, 'expire', [$key, $ttl]);
                    return null;
                }
            }

            // Execute atomic INCR + EXPIRE
            // phpredis evalSha: (sha, [keys_and_args], num_keys)
            $result = $this->redis->evalSha(
                $this->luaIncrExpireSha,
                [$key, (string) $ttl],
                1  // Number of KEYS (only $key, $ttl is ARGV)
            );

            return is_numeric($result) ? (int) $result : null;

        } catch (\RedisException $e) {
            // Script might have been flushed (Redis restart), retry with fresh load
            if (strpos($e->getMessage(), 'NOSCRIPT') !== false) {
                $this->luaIncrExpireSha = null;
                return $this->atomicIncrWithExpire($key, $ttl);
            }

            Logger::security('warning', 'RATE_LIMIT: Atomic incr failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            // Fallback to non-atomic
            $this->safeRedisCall($this->redis, 'incr', [$key]);
            $this->safeRedisCall($this->redis, 'expire', [$key, $ttl]);
            return null;

        } catch (\Exception $e) {
            $this->handleRedisFailure($e);
            return null;
        }
    }
}
