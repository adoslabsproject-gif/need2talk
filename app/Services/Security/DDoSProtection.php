<?php

namespace Need2Talk\Services\Security;

use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: DDoS Protection Layer
 *
 * Protezione contro attacchi DDoS distribuiti che bypassano
 * il rate limiting per-IP di Nginx.
 *
 * PROBLEMA:
 * - Nginx rate limit: 1000 req/min per IP
 * - Botnet con 10,000 IP: 10,000 × 1000 = 10M req/min
 * - Server capacity: ~1000 req/sec = 60,000 req/min
 * - Risultato: Server saturo, utenti legittimi bloccati
 *
 * SOLUZIONE:
 * - Rate limiting GLOBALE (tutti gli IP sommati)
 * - Spike detection (aumento improvviso)
 * - Endpoint-specific protection (login, register più protetti)
 * - Automatic throttling progressivo
 *
 * @version 1.0.0
 */
class DDoSProtection
{
    private const REDIS_PREFIX = 'ddos:';
    private const REDIS_DB = 3;

    // Global rate limits (tutti gli IP sommati)
    private const GLOBAL_LIMITS = [
        'requests_per_second' => 500,      // Max 500 req/sec globali
        'requests_per_minute' => 20000,    // Max 20k req/min globali
        'spike_threshold' => 3.0,          // 3x il rate normale = spike
        'spike_window' => 10,              // Finestra spike detection (secondi)
    ];

    // Endpoint-specific limits (più restrittivi)
    private const ENDPOINT_LIMITS = [
        '/auth/login' => ['per_second' => 20, 'per_minute' => 300],
        '/auth/register' => ['per_second' => 10, 'per_minute' => 100],
        '/api/audio/upload' => ['per_second' => 30, 'per_minute' => 500],
        '/api/password/reset' => ['per_second' => 5, 'per_minute' => 50],
    ];

    // Throttle levels
    private const THROTTLE_LEVELS = [
        0 => ['delay_ms' => 0, 'reject_percent' => 0],      // Normal
        1 => ['delay_ms' => 100, 'reject_percent' => 10],   // Light load
        2 => ['delay_ms' => 500, 'reject_percent' => 30],   // Medium load
        3 => ['delay_ms' => 1000, 'reject_percent' => 50],  // Heavy load
        4 => ['delay_ms' => 2000, 'reject_percent' => 80],  // Critical
        5 => ['delay_ms' => 5000, 'reject_percent' => 95],  // Emergency
    ];

    private ?\Redis $redis = null;
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->initRedis();
    }

    private function initRedis(): bool
    {
        try {
            $this->redis = new \Redis();
            $host = $_ENV['REDIS_HOST'] ?? 'redis';
            $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
            $password = $_ENV['REDIS_PASSWORD'] ?? null;

            $this->redis->connect($host, $port, 2.0);

            if ($password) {
                $this->redis->auth($password);
            }

            $this->redis->select(self::REDIS_DB);

            return true;
        } catch (\Exception $e) {
            $this->redis = null;
            return false;
        }
    }

    /**
     * Check if request should be allowed
     *
     * @param string $path Request path
     * @param string $ip Client IP
     * @return array ['allowed' => bool, 'throttle_level' => int, 'reason' => string|null]
     */
    public function checkRequest(string $path, string $ip): array
    {
        if (!$this->redis) {
            // Fail open if Redis unavailable
            return ['allowed' => true, 'throttle_level' => 0, 'reason' => null];
        }

        $now = time();
        $currentSecond = $now;
        $currentMinute = (int) ($now / 60);

        // Increment counters atomically
        $pipe = $this->redis->pipeline();
        $pipe->incr(self::REDIS_PREFIX . "global:sec:{$currentSecond}");
        $pipe->expire(self::REDIS_PREFIX . "global:sec:{$currentSecond}", 10);
        $pipe->incr(self::REDIS_PREFIX . "global:min:{$currentMinute}");
        $pipe->expire(self::REDIS_PREFIX . "global:min:{$currentMinute}", 120);
        $results = $pipe->exec();

        $requestsThisSecond = (int) $results[0];
        $requestsThisMinute = (int) $results[2];

        // Check endpoint-specific limits
        $endpointKey = $this->getEndpointKey($path);
        if ($endpointKey && isset(self::ENDPOINT_LIMITS[$endpointKey])) {
            $endpointResult = $this->checkEndpointLimit($endpointKey, $currentSecond, $currentMinute);
            if (!$endpointResult['allowed']) {
                return $endpointResult;
            }
        }

        // Check global limits
        $throttleLevel = $this->calculateThrottleLevel($requestsThisSecond, $requestsThisMinute);

        // Spike detection
        if ($this->detectSpike($currentSecond)) {
            $throttleLevel = max($throttleLevel, 3);

            Logger::security('warning', 'DDOS: Spike detected', [
                'requests_per_second' => $requestsThisSecond,
                'requests_per_minute' => $requestsThisMinute,
                'throttle_level' => $throttleLevel,
            ]);
        }

        // Apply throttle decision
        if ($throttleLevel > 0) {
            $throttle = self::THROTTLE_LEVELS[$throttleLevel];

            // Random rejection based on throttle level
            if ($throttle['reject_percent'] > 0 && random_int(1, 100) <= $throttle['reject_percent']) {
                $this->logDDoSEvent($ip, $path, 'rejected', $throttleLevel);

                return [
                    'allowed' => false,
                    'throttle_level' => $throttleLevel,
                    'reason' => 'server_busy',
                    'retry_after' => (int) ($throttle['delay_ms'] / 1000) + 1,
                ];
            }

            // Apply delay for allowed requests
            if ($throttle['delay_ms'] > 0) {
                usleep($throttle['delay_ms'] * 1000);
            }
        }

        return [
            'allowed' => true,
            'throttle_level' => $throttleLevel,
            'reason' => null,
        ];
    }

    /**
     * Check endpoint-specific rate limit
     */
    private function checkEndpointLimit(string $endpoint, int $currentSecond, int $currentMinute): array
    {
        $limits = self::ENDPOINT_LIMITS[$endpoint];
        $key = str_replace('/', '_', $endpoint);

        $pipe = $this->redis->pipeline();
        $pipe->incr(self::REDIS_PREFIX . "endpoint:{$key}:sec:{$currentSecond}");
        $pipe->expire(self::REDIS_PREFIX . "endpoint:{$key}:sec:{$currentSecond}", 10);
        $pipe->incr(self::REDIS_PREFIX . "endpoint:{$key}:min:{$currentMinute}");
        $pipe->expire(self::REDIS_PREFIX . "endpoint:{$key}:min:{$currentMinute}", 120);
        $results = $pipe->exec();

        $perSecond = (int) $results[0];
        $perMinute = (int) $results[2];

        if ($perSecond > $limits['per_second'] || $perMinute > $limits['per_minute']) {
            return [
                'allowed' => false,
                'throttle_level' => 5,
                'reason' => 'endpoint_limit_exceeded',
                'retry_after' => 60,
            ];
        }

        return ['allowed' => true, 'throttle_level' => 0, 'reason' => null];
    }

    /**
     * Match request path to endpoint limit key
     */
    private function getEndpointKey(string $path): ?string
    {
        foreach (array_keys(self::ENDPOINT_LIMITS) as $endpoint) {
            if (str_starts_with($path, $endpoint)) {
                return $endpoint;
            }
        }
        return null;
    }

    /**
     * Calculate throttle level based on current load
     */
    private function calculateThrottleLevel(int $requestsPerSecond, int $requestsPerMinute): int
    {
        $secLimit = self::GLOBAL_LIMITS['requests_per_second'];
        $minLimit = self::GLOBAL_LIMITS['requests_per_minute'];

        // Calculate load percentage (use higher of the two)
        $secLoad = $requestsPerSecond / $secLimit;
        $minLoad = $requestsPerMinute / $minLimit;
        $load = max($secLoad, $minLoad);

        if ($load >= 1.0) {
            return 5; // Emergency
        }
        if ($load >= 0.9) {
            return 4; // Critical
        }
        if ($load >= 0.75) {
            return 3; // Heavy
        }
        if ($load >= 0.5) {
            return 2; // Medium
        }
        if ($load >= 0.3) {
            return 1; // Light
        }

        return 0; // Normal
    }

    /**
     * Detect traffic spike (sudden increase)
     */
    private function detectSpike(int $currentSecond): bool
    {
        $window = self::GLOBAL_LIMITS['spike_window'];
        $threshold = self::GLOBAL_LIMITS['spike_threshold'];

        // Get requests from last N seconds
        $pipe = $this->redis->pipeline();
        for ($i = 1; $i <= $window; $i++) {
            $pipe->get(self::REDIS_PREFIX . "global:sec:" . ($currentSecond - $i));
        }
        $results = $pipe->exec();

        $recentRequests = array_map('intval', $results);
        $avgRecent = count($recentRequests) > 0 ? array_sum($recentRequests) / count($recentRequests) : 0;

        if ($avgRecent === 0) {
            return false;
        }

        $currentRequests = (int) $this->redis->get(self::REDIS_PREFIX . "global:sec:{$currentSecond}");

        return $currentRequests > ($avgRecent * $threshold);
    }

    /**
     * Log DDoS event
     */
    private function logDDoSEvent(string $ip, string $path, string $action, int $level): void
    {
        // Rate limit logging to prevent log flooding during attack
        $logKey = self::REDIS_PREFIX . "log:last";
        $lastLog = (int) $this->redis->get($logKey);

        if (time() - $lastLog < 1) {
            // Max 1 log per second during attack
            return;
        }

        $this->redis->setex($logKey, 5, time());

        Logger::security('warning', "DDOS: Request {$action}", [
            'ip' => $ip,
            'path' => $path,
            'throttle_level' => $level,
            'action' => $action,
        ]);
    }

    /**
     * Get current DDoS protection status
     */
    public function getStatus(): array
    {
        if (!$this->redis) {
            return ['enabled' => false, 'reason' => 'redis_unavailable'];
        }

        $now = time();
        $currentSecond = $now;
        $currentMinute = (int) ($now / 60);

        $requestsPerSecond = (int) $this->redis->get(self::REDIS_PREFIX . "global:sec:{$currentSecond}");
        $requestsPerMinute = (int) $this->redis->get(self::REDIS_PREFIX . "global:min:{$currentMinute}");

        $throttleLevel = $this->calculateThrottleLevel($requestsPerSecond, $requestsPerMinute);

        return [
            'enabled' => true,
            'requests_per_second' => $requestsPerSecond,
            'requests_per_minute' => $requestsPerMinute,
            'throttle_level' => $throttleLevel,
            'throttle_name' => $this->getThrottleName($throttleLevel),
            'limits' => self::GLOBAL_LIMITS,
            'load_percent' => round(max(
                $requestsPerSecond / self::GLOBAL_LIMITS['requests_per_second'],
                $requestsPerMinute / self::GLOBAL_LIMITS['requests_per_minute']
            ) * 100, 1),
        ];
    }

    /**
     * Get human-readable throttle level name
     */
    private function getThrottleName(int $level): string
    {
        return match ($level) {
            0 => 'normal',
            1 => 'light_load',
            2 => 'medium_load',
            3 => 'heavy_load',
            4 => 'critical',
            5 => 'emergency',
            default => 'unknown',
        };
    }

    /**
     * Get endpoint-specific stats
     */
    public function getEndpointStats(): array
    {
        if (!$this->redis) {
            return [];
        }

        $now = time();
        $currentMinute = (int) ($now / 60);
        $stats = [];

        foreach (self::ENDPOINT_LIMITS as $endpoint => $limits) {
            $key = str_replace('/', '_', $endpoint);
            $count = (int) $this->redis->get(self::REDIS_PREFIX . "endpoint:{$key}:min:{$currentMinute}");

            $stats[$endpoint] = [
                'requests_this_minute' => $count,
                'limit_per_minute' => $limits['per_minute'],
                'usage_percent' => $limits['per_minute'] > 0 ? round($count / $limits['per_minute'] * 100, 1) : 0,
            ];
        }

        return $stats;
    }
}
