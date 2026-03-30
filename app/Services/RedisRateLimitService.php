<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Traits\EnterpriseRedisSafety;
use Redis;

/**
 * Redis Rate Limiting Service - ENTERPRISE SCALABILITY
 *
 * Replaces database-based rate limiting for 100k+ concurrent users
 * Uses Redis for ultra-fast rate limit checks with automatic expiration
 *
 * Performance: ~0.1ms Redis lookup vs ~50ms database query
 * Scalability: Supports unlimited concurrent users
 */
class RedisRateLimitService
{
    use EnterpriseRedisSafety;

    private ?Redis $redis = null;

    private bool $fallbackToDatabase = true;

    // Rate limit configurations - same as UserRateLimitMiddleware
    private array $limits = [
        'web' => [
            'requests' => 100,    // 100 requests per hour
            'window' => 3600,     // 1 hour window
            'ban_duration' => 3600, // 1 hour ban
        ],
        'api' => [
            'requests' => 500,    // 500 API requests per hour
            'window' => 3600,     // 1 hour window
            'ban_duration' => 7200, // 2 hour ban
        ],
        'auth' => [
            'requests' => 10,     // 10 login attempts
            'window' => 900,      // 15 minutes window
            'ban_duration' => 1800, // 30 minute ban
        ],
        'user' => [
            'requests' => 200,    // 200 requests per hour for authenticated users
            'window' => 3600,     // 1 hour window
            'ban_duration' => 1800, // 30 minute ban
        ],
    ];

    private array $whitelistIps = [
        '127.0.0.1',
        '::1',
        'localhost',
    ];

    public function __construct()
    {
        $this->initializeRedis();
    }

    /**
     * Check if request is allowed (main entry point)
     */
    public function isRequestAllowed(string $ip, ?int $userId = null, string $type = 'web'): bool
    {
        // Whitelist check (always fast)
        if ($this->isWhitelisted($ip, $userId)) {
            return true;
        }

        // Check if IP is banned
        if ($this->isBanned($ip)) {
            Logger::security('warning', "RATE LIMIT: Banned IP attempted access: $ip", ['service' => 'RedisRateLimitService']);

            return false;
        }

        // Use Redis if available, fallback to database
        if ($this->redis) {
            return $this->checkRateLimitRedis($ip, $userId, $type);
        }

        return $this->checkRateLimitDatabase($ip, $userId, $type);

    }

    /**
     * Record request (increment counters)
     */
    public function recordRequest(string $ip, ?int $userId = null, string $type = 'web'): void
    {
        if ($this->redis) {
            $this->recordRequestRedis($ip, $userId, $type);
        } else {
            $this->recordRequestDatabase($ip, $userId, $type);
        }
    }

    /**
     * Get client IP (SECURE - trust only REMOTE_ADDR)
     *
     * SECURITY: Non fidarsi MAI di header proxy (X-Forwarded-For, CF-Connecting-IP, etc.)
     * che possono essere spoofati dal client per bypassare rate limiting.
     * Nginx passa l'IP reale in REMOTE_ADDR via fastcgi_param.
     */
    public function getClientIp(): string
    {
        return EnterpriseGlobalsManager::getServer('REMOTE_ADDR', '127.0.0.1');
    }

    /**
     * Get rate limit statistics
     */
    public function getStats(): array
    {
        $stats = [
            'redis_available' => $this->redis !== null,
            'fallback_mode' => $this->redis === null,
            'limits' => $this->limits,
        ];

        if ($this->redis) {
            try {
                $stats['redis_info'] = $this->redis->info('memory');
            } catch (\Exception $e) {
                $stats['redis_error'] = $e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * Initialize Redis connection with failover
     */
    private function initializeRedis(): void
    {
        try {
            $this->redis = new Redis();

            // Try MAMP Redis first (port 6379)
            if ($this->redis->pconnect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379), 1.0)) {
                // ENTERPRISE FIX: Authenticate with password if provided
                $password = $_ENV['REDIS_PASSWORD'] ?? null;
                if ($password) {
                    $this->redis->auth($password);
                }

                $this->redis->ping();

                return;
            }

            // Fallback to standard Redis port
            if ($this->redis->pconnect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379), 1.0)) {
                // ENTERPRISE FIX: Authenticate with password if provided
                $password = $_ENV['REDIS_PASSWORD'] ?? null;
                if ($password) {
                    $this->redis->auth($password);
                }

                $this->redis->ping();

                return;
            }

            throw new \Exception('Could not connect to Redis on any port');
        } catch (\Exception $e) {
            Logger::database('error', 'RATE LIMIT: Redis connection failed', [
                'service' => 'RedisRateLimitService',
                'error' => $e->getMessage(),
            ]);
            $this->redis = null;
        }
    }

    /**
     * Redis-based rate limit check (ULTRA FAST)
     */
    private function checkRateLimitRedis(string $ip, ?int $userId, string $type): bool
    {
        $config = $this->limits[$type] ?? $this->limits['web'];
        $window = $config['window'];
        $maxRequests = $config['requests'];

        try {
            // Check IP rate limit
            $ipKey = "rate_limit:ip:$type:$ip";
            $ipCount = (int) $this->redis->get($ipKey);

            if ($ipCount >= $maxRequests) {
                // Ban the IP
                $this->banIpRedis($ip, $config['ban_duration']);

                return false;
            }

            // Check user rate limit (if authenticated)
            if ($userId) {
                $userKey = "rate_limit:user:$type:$userId";
                $userCount = (int) $this->redis->get($userKey);

                // Users get double the limit
                if ($userCount >= ($maxRequests * 2)) {
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            Logger::database('error', 'RATE LIMIT: Redis rate limit check failed: ' . $e->getMessage(), ['service' => 'RedisRateLimitService']);

            return $this->checkRateLimitDatabase($ip, $userId, $type);
        }
    }

    /**
     * Record request in Redis with automatic expiration
     */
    private function recordRequestRedis(string $ip, ?int $userId, string $type): void
    {
        $config = $this->limits[$type] ?? $this->limits['web'];
        $window = $config['window'];

        try {
            // Use enterprise Redis safety for transaction
            $result = $this->safeRedisTransaction($this->redis, function ($redis) use ($ip, $type, $window, $userId) {
                // Increment IP counter
                $ipKey = "rate_limit:ip:$type:$ip";
                $this->safeRedisCall($redis, 'incr', [$ipKey]);
                $this->safeRedisCall($redis, 'expire', [$ipKey, $window]);

                // Increment user counter (if authenticated)
                if ($userId) {
                    $userKey = "rate_limit:user:$type:$userId";
                    $this->safeRedisCall($redis, 'incr', [$userKey]);
                    $this->safeRedisCall($redis, 'expire', [$userKey, $window]);
                }
            });

            if ($result !== null) {
            }

        } catch (\Exception $e) {
            Logger::database('error', 'RATE LIMIT: Redis record request failed: ' . $e->getMessage(), ['service' => 'RedisRateLimitService']);
            $this->recordRequestDatabase($ip, $userId, $type);
        }
    }

    /**
     * Ban IP in Redis
     */
    private function banIpRedis(string $ip, int $duration): void
    {
        try {
            $banKey = "rate_limit:ban:$ip";
            $this->redis->setex($banKey, $duration, time());
            Logger::security('warning', "RATE LIMIT: IP banned for $duration seconds: $ip", ['service' => 'RedisRateLimitService']);
        } catch (\Exception $e) {
            Logger::database('error', 'RATE LIMIT: Redis IP ban failed: ' . $e->getMessage(), ['service' => 'RedisRateLimitService']);
        }
    }

    /**
     * Check if IP is banned
     */
    private function isBanned(string $ip): bool
    {
        if ($this->redis) {
            try {
                $banKey = "rate_limit:ban:$ip";

                return $this->redis->exists($banKey);
            } catch (\Exception $e) {
                Logger::database('error', 'RATE LIMIT: Redis ban check failed: ' . $e->getMessage(), ['service' => 'RedisRateLimitService']);
            }
        }

        // Fallback to database ban check
        return $this->isBannedDatabase($ip);
    }

    /**
     * Database fallback methods
     */
    private function checkRateLimitDatabase(string $ip, ?int $userId, string $type): bool
    {
        $config = $this->limits[$type] ?? $this->limits['web'];
        $window = time() - $config['window'];

        try {
            $db = db_pdo();

            // Check IP rate limit
            $stmt = $db->prepare('
                SELECT COUNT(*) FROM user_rate_limit_log
                WHERE ip_address = ? AND action_type = ? AND created_at >= TO_TIMESTAMP(?)
            ');
            $stmt->execute([$ip, $type, $window]);
            $ipCount = (int) $stmt->fetchColumn();

            if ($ipCount >= $config['requests']) {
                $this->banIpDatabase($ip, $config['ban_duration']);

                return false;
            }

            // Check user rate limit
            if ($userId) {
                $stmt = $db->prepare('
                    SELECT COUNT(*) FROM user_rate_limit_log
                    WHERE user_id = ? AND action_type = ? AND created_at >= TO_TIMESTAMP(?)
                ');
                $stmt->execute([$userId, $type, $window]);
                $userCount = (int) $stmt->fetchColumn();

                if ($userCount >= ($config['requests'] * 2)) {
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            Logger::database('error', 'RATE LIMIT: Database rate limit check failed: ' . $e->getMessage(), ['service' => 'RedisRateLimitService']);

            return true; // Allow request if database fails (graceful degradation)
        }
    }

    private function recordRequestDatabase(string $ip, ?int $userId, string $type): void
    {
        try {
            $db = db_pdo();
            $stmt = $db->prepare("
                INSERT INTO user_rate_limit_log (
                    ip_address, user_id, action_type, identifier_type, identifier_hash, created_at
                ) VALUES (?, ?, ?, 'ip', SHA2(?, 256), NOW())
            ");
            $stmt->execute([$ip, $userId, $type, $ip]);
        } catch (\Exception $e) {
            Logger::database('error', 'RATE LIMIT: Database record request failed: ' . $e->getMessage(), ['service' => 'RedisRateLimitService']);
        }
    }

    private function banIpDatabase(string $ip, int $duration): void
    {
        try {
            $db = db_pdo();
            $stmt = $db->prepare("
                INSERT INTO user_rate_limit_bans (ip_address, expires_at, reason, ban_type, severity)
                VALUES (?, NOW() + INTERVAL '1 second' * ?, 'Rate limit exceeded', 'temporary', 'medium')
                ON CONFLICT (ip_address) DO UPDATE SET expires_at = EXCLUDED.expires_at, reason = EXCLUDED.reason
            ");
            $stmt->execute([$ip, $duration]);
            Logger::security('warning', "RATE LIMIT: IP banned in database for $duration seconds: $ip", ['service' => 'RedisRateLimitService']);
        } catch (\Exception $e) {
            Logger::database('error', 'RATE LIMIT: Database IP ban failed: ' . $e->getMessage(), ['service' => 'RedisRateLimitService']);
        }
    }

    private function isBannedDatabase(string $ip): bool
    {
        try {
            $db = db_pdo();
            $stmt = $db->prepare('
                SELECT COUNT(*) FROM user_rate_limit_bans
                WHERE ip_address = ? AND expires_at > NOW()
            ');
            $stmt->execute([$ip]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (\Exception $e) {
            Logger::database('error', 'RATE LIMIT: Database ban check failed: ' . $e->getMessage(), ['service' => 'RedisRateLimitService']);

            return false;
        }
    }

    /**
     * Check if IP/user is whitelisted
     */
    private function isWhitelisted(string $ip, ?int $userId): bool
    {
        // IP whitelist
        if (in_array($ip, $this->whitelistIps, true)) {
            return true;
        }

        // Admin users bypass rate limiting
        if ($userId && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
            return true;
        }

        return false;
    }
}
