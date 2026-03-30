<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Traits\EnterpriseRedisSafety;

/**
 * Verification Spike Optimizer - Enterprise Grade
 *
 * Gestisce picchi di verifiche email per 100k+ utenti simultanei:
 * - Database query optimization per verifiche massive
 * - Redis caching per token validation
 * - Rate limiting specifico per verification endpoints
 * - Preemptive connection pooling durante picchi
 * - Circuit breaker per protezione sistema
 */
class VerificationSpikeOptimizer
{
    use EnterpriseRedisSafety;

    // Redis keys specializzate per verification
    private const VERIFICATION_CACHE_PREFIX = 'verification:';
    private const VERIFICATION_RATE_PREFIX = 'verify_rate:';
    private const SPIKE_DETECTION_KEY = 'verification_spike_metrics';
    private const PRELOAD_CONNECTIONS_KEY = 'verification_preload_trigger';

    // Thresholds per spike detection
    private const SPIKE_THRESHOLD = 50; // Verifiche al minuto per attivare ottimizzazioni
    private const CRITICAL_THRESHOLD = 200; // Verifiche al minuto per attivare circuit breaker
    private const CACHE_TTL_NORMAL = 3600; // 1 ora cache normale
    private const CACHE_TTL_SPIKE = 1800; // 30 minuti cache durante spike

    private $redisManager;

    private $databasePool;

    private $config;

    private $metrics;

    public function __construct()
    {
        $this->config = [
            'spike_detection_enabled' => true,
            'preload_connections' => 20, // Connessioni da pre-caricare durante spike
            'verification_batch_size' => 100, // Batch size per processamento mass verification
        ];

        // ENTERPRISE INTEGRATION: Use existing RedisManager with circuit breaker
        $this->redisManager = EnterpriseRedisManager::getInstance();
        $this->databasePool = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance();

        $this->metrics = [
            'verifications_processed' => 0,
            'spike_detections' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'connections_preloaded' => 0,
            'circuit_breaker_activations' => 0,
        ];
    }

    /**
     * Optimize email verification with caching and spike detection
     */
    public function optimizeVerification(string $token): ?array
    {
        $startTime = microtime(true);

        // Record verification attempt
        $this->recordVerificationAttempt();

        // Check for spike and apply optimizations
        $spikeLevel = $this->detectVerificationSpike();

        if ($spikeLevel > 0) {
            $this->applySpikeMitigation($spikeLevel);
        }

        // Try cache first (Enterprise Redis)
        $redis = $this->getRedisConnection();

        if ($redis) {
            $cacheKey = self::VERIFICATION_CACHE_PREFIX . hash('sha256', $token);
            $cachedResult = $this->safeRedisCall($redis, 'get', [$cacheKey]);

            if ($cachedResult !== false && $cachedResult !== null) {
                $this->metrics['cache_hits']++;
                $result = json_decode($cachedResult, true);

                return $result;
            }

            $this->metrics['cache_misses']++;
        }

        // Database lookup with optimization
        $result = $this->performOptimizedDatabaseLookup($token);

        // Cache result if valid
        if ($result && $redis) {
            $ttl = $spikeLevel > 1 ? self::CACHE_TTL_SPIKE : self::CACHE_TTL_NORMAL;
            $this->safeRedisCall($redis, 'setex', [$cacheKey, $ttl, json_encode($result)]);
        }

        return $result;
    }

    /**
     * Check if circuit breaker is active
     */
    public function isCircuitBreakerActive(): bool
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return false;
        }

        return $this->safeRedisCall($redis, 'get', ['verification_circuit_breaker']) !== false;
    }

    /**
     * Batch process multiple verification tokens (for mass verification scenarios)
     */
    public function batchOptimizeVerifications(array $tokens): array
    {
        $startTime = microtime(true);
        $results = [];
        $cacheHits = 0;

        if (empty($tokens)) {
            return [];
        }

        // Check cache for all tokens first
        $redis = $this->getRedisConnection();

        if ($redis) {
            $cacheKeys = [];

            foreach ($tokens as $token) {
                $cacheKeys[] = self::VERIFICATION_CACHE_PREFIX . hash('sha256', $token);
            }

            $cachedResults = $this->safeRedisCall($redis, 'mget', [$cacheKeys]);

            foreach ($tokens as $index => $token) {
                if ($cachedResults[$index] !== false) {
                    $results[$token] = json_decode($cachedResults[$index], true);
                    $cacheHits++;
                } else {
                    $results[$token] = null; // Mark for database lookup
                }
            }
        } else {
            // No cache, mark all for database lookup
            foreach ($tokens as $token) {
                $results[$token] = null;
            }
        }

        // Database lookup for cache misses
        $tokensToLookup = array_keys(array_filter($results, fn ($result) => $result === null));

        if (!empty($tokensToLookup)) {
            $dbResults = $this->batchDatabaseLookup($tokensToLookup);

            // Update results and cache
            foreach ($dbResults as $token => $result) {
                $results[$token] = $result;

                // Cache the result
                if ($redis && $result) {
                    $cacheKey = self::VERIFICATION_CACHE_PREFIX . hash('sha256', $token);
                    $this->safeRedisCall($redis, 'setex', [$cacheKey, self::CACHE_TTL_NORMAL, json_encode($result)]);
                }
            }
        }

        return $results;
    }

    /**
     * Get verification spike optimizer metrics
     */
    public function getMetrics(): array
    {
        $spikeLevel = $this->detectVerificationSpike();

        return [
            'verification_optimizer' => [
                'verifications_processed' => $this->metrics['verifications_processed'],
                'spike_detections' => $this->metrics['spike_detections'],
                'cache_hits' => $this->metrics['cache_hits'],
                'cache_misses' => $this->metrics['cache_misses'],
                'cache_hit_ratio' => ($this->metrics['cache_hits'] + $this->metrics['cache_misses']) > 0 ?
                    round(($this->metrics['cache_hits'] / ($this->metrics['cache_hits'] + $this->metrics['cache_misses'])) * 100, 2) : 0,
                'connections_preloaded' => $this->metrics['connections_preloaded'],
                'circuit_breaker_activations' => $this->metrics['circuit_breaker_activations'],
                'current_spike_level' => $spikeLevel,
                'circuit_breaker_active' => $this->isCircuitBreakerActive(),
                'redis_enabled' => $this->getRedisConnection() !== null,
            ],
        ];
    }

    /**
     * Health check for verification spike optimizer
     */
    public function healthCheck(): array
    {
        $health = [
            'overall' => true,
            'components' => [],
        ];

        // Check Redis connection
        $redis = $this->getRedisConnection();

        if ($redis) {
            try {
                $this->safeRedisCall($redis, 'ping', []);
                $health['components']['redis'] = true;
            } catch (\Exception $e) {
                $health['components']['redis'] = false;
                $health['overall'] = false;
            }
        } else {
            $health['components']['redis'] = false;
            $health['overall'] = false;
        }

        // Check database pool
        try {
            $poolHealth = $this->databasePool->performHealthCheck();
            $health['components']['database_pool'] = $poolHealth['health_ratio'] > 80;

            if ($poolHealth['health_ratio'] <= 80) {
                $health['overall'] = false;
            }
        } catch (\Exception $e) {
            $health['components']['database_pool'] = false;
            $health['overall'] = false;
        }

        // Check circuit breaker status
        $health['components']['circuit_breaker'] = !$this->isCircuitBreakerActive();

        return $health;
    }

    /**
     * ENTERPRISE: Get Redis connection using enterprise manager with circuit breaker
     */
    private function getRedisConnection(): ?\Redis
    {
        return $this->redisManager->getConnection('L3_async_email'); // Use L3_async_email pool for verification
    }

    /**
     * Perform optimized database lookup for verification
     */
    private function performOptimizedDatabaseLookup(string $token): ?array
    {
        try {
            $connection = $this->databasePool->getConnection();

            // SECURITY: Token is stored as SHA256 hash in database
            $tokenHash = hash('sha256', $token);

            // Optimized query with covering index
            $stmt = $this->databasePool->executeQuery(
                $connection,
                'SELECT user_id, user_uuid, token, expires_at, created_at
                 FROM email_verification_tokens
                 WHERE token = ?
                 AND expires_at > NOW()
                 LIMIT 1',
                [$tokenHash]
            );

            $result = $stmt->fetch();

            $this->databasePool->releaseConnection($connection);

            return $result ?: null;

        } catch (\Exception $e) {
            Logger::database('error', 'VERIFICATION: Database lookup failed during spike', [
                'error' => $e->getMessage(),
                'token_hash' => hash('sha256', $token),
            ]);

            return null;
        }
    }

    /**
     * Record verification attempt for spike detection
     */
    private function recordVerificationAttempt(): void
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return;
        }

        $minute = date('Y-m-d H:i');
        $key = self::SPIKE_DETECTION_KEY . ':' . $minute;

        $this->safeRedisCall($redis, 'incr', [$key]);
        $this->safeRedisCall($redis, 'expire', [$key, 120]); // Keep for 2 minutes

        $this->metrics['verifications_processed']++;
    }

    /**
     * Detect verification spike level
     */
    private function detectVerificationSpike(): int
    {
        $redis = $this->getRedisConnection();

        if (!$redis || !$this->config['spike_detection_enabled']) {
            return 0;
        }

        $currentMinute = date('Y-m-d H:i');
        $previousMinute = date('Y-m-d H:i', strtotime('-1 minute'));

        $currentCount = (int) $this->safeRedisCall($redis, 'get', [self::SPIKE_DETECTION_KEY . ':' . $currentMinute]);
        $previousCount = (int) $this->safeRedisCall($redis, 'get', [self::SPIKE_DETECTION_KEY . ':' . $previousMinute]);

        $averagePerMinute = ($currentCount + $previousCount) / 2;

        if ($averagePerMinute >= self::CRITICAL_THRESHOLD) {
            return 3; // Critical spike
        }

        if ($averagePerMinute >= self::SPIKE_THRESHOLD * 2) {
            return 2; // High spike
        }

        if ($averagePerMinute >= self::SPIKE_THRESHOLD) {
            return 1; // Moderate spike
        }

        return 0; // No spike
    }

    /**
     * Apply spike mitigation strategies
     */
    private function applySpikeMitigation(int $spikeLevel): void
    {
        switch ($spikeLevel) {
            case 3: // Critical spike
                $this->activateCircuitBreaker();
                $this->preloadDatabaseConnections();
                $this->enableAggressiveCaching();
                break;

            case 2: // High spike
                $this->preloadDatabaseConnections();
                $this->enableAggressiveCaching();
                break;

            case 1: // Moderate spike
                $this->preloadDatabaseConnections();
                break;
        }

        $this->metrics['spike_detections']++;
    }

    /**
     * Preload database connections during spikes
     */
    private function preloadDatabaseConnections(): void
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return;
        }

        // Check if preloading is already in progress
        $preloadKey = self::PRELOAD_CONNECTIONS_KEY;

        if ($this->safeRedisCall($redis, 'get', [$preloadKey])) {
            return; // Already preloading
        }

        // Set preload flag
        $this->safeRedisCall($redis, 'setex', [$preloadKey, 300, '1']); // 5 minutes

        try {
            $preloadCount = $this->config['preload_connections'];
            $preloadedConnections = [];

            for ($i = 0; $i < $preloadCount; $i++) {
                try {
                    $connection = $this->databasePool->getConnection();
                    $preloadedConnections[] = $connection;

                    // Perform warmup query
                    $stmt = $connection->query('SELECT 1');

                } catch (\Exception $e) {
                    Logger::database('warning', 'VERIFICATION: Failed to preload connection', [
                        'connection_index' => $i,
                        'error' => $e->getMessage(),
                    ]);
                    break;
                }
            }

            // Release connections back to pool
            foreach ($preloadedConnections as $connection) {
                $this->databasePool->releaseConnection($connection);
            }

            $this->metrics['connections_preloaded'] += count($preloadedConnections);

        } catch (\Exception $e) {
            Logger::database('error', 'VERIFICATION: Database connection preloading failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enable aggressive caching during spikes
     */
    private function enableAggressiveCaching(): void
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return;
        }

        // Cache common verification queries
        $this->cacheCommonVerificationQueries();

        // Set aggressive cache flag
        $this->safeRedisCall($redis, 'setex', ['verification_aggressive_cache', 600, '1']); // 10 minutes
    }

    /**
     * Cache common verification queries
     */
    private function cacheCommonVerificationQueries(): void
    {
        try {
            $connection = $this->databasePool->getConnection();

            // Cache count of pending verifications
            $stmt = $this->databasePool->executeQuery(
                $connection,
                'SELECT COUNT(*) as pending_count
                 FROM email_verification_tokens
                 WHERE expires_at > NOW()'
            );

            $result = $stmt->fetch();

            if ($result) {
                $redis = $this->getRedisConnection();

                if ($redis) {
                    $this->safeRedisCall($redis, 'setex', ['verification_pending_count', 300, $result['pending_count']]);
                }
            }

            // Cache recent verification rate
            $stmt = $this->databasePool->executeQuery(
                $connection,
                "SELECT COUNT(*) as recent_verifications
                 FROM email_verification_tokens
                 WHERE created_at > NOW() - INTERVAL '1 hours'"
            );

            $result = $stmt->fetch();

            if ($result) {
                $redis = $this->getRedisConnection();

                if ($redis) {
                    $this->safeRedisCall($redis, 'setex', ['verification_hourly_rate', 300, $result['recent_verifications']]);
                }
            }

            $this->databasePool->releaseConnection($connection);

        } catch (\Exception $e) {
            Logger::database('error', 'VERIFICATION: Failed to cache common verification queries', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Activate circuit breaker for critical spikes
     */
    private function activateCircuitBreaker(): void
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return;
        }

        $circuitBreakerKey = 'verification_circuit_breaker';
        $this->safeRedisCall($redis, 'setex', [$circuitBreakerKey, 300, '1']); // 5 minutes

        $this->metrics['circuit_breaker_activations']++;

        Logger::email('warning', 'VERIFICATION: Circuit breaker activated', [
            'duration_seconds' => 300,
            'reason' => 'critical_spike_detected',
            'mitigation' => 'degraded_mode_enabled',
        ]);
    }

    /**
     * Perform batch database lookup for multiple tokens
     */
    private function batchDatabaseLookup(array $tokens): array
    {
        $results = [];

        if (empty($tokens)) {
            return $results;
        }

        try {
            $connection = $this->databasePool->getConnection();

            // SECURITY: Hash all tokens before database lookup
            $tokenHashes = [];
            $hashToOriginal = [];

            foreach ($tokens as $token) {
                $hash = hash('sha256', $token);
                $tokenHashes[] = $hash;
                $hashToOriginal[$hash] = $token;
            }

            // Build IN clause for batch query
            $placeholders = str_repeat('?,', count($tokenHashes) - 1) . '?';
            $query = "SELECT user_id, user_uuid, token, expires_at, created_at
                      FROM email_verification_tokens
                      WHERE token IN ($placeholders)
                      AND expires_at > NOW()";

            $stmt = $this->databasePool->executeQuery($connection, $query, $tokenHashes);

            while ($row = $stmt->fetch()) {
                // Map back from hash to original token
                $originalToken = $hashToOriginal[$row['token']] ?? null;

                if ($originalToken) {
                    $results[$originalToken] = $row;
                }
            }

            // Fill in null results for tokens not found
            foreach ($tokens as $token) {
                if (!isset($results[$token])) {
                    $results[$token] = null;
                }
            }

            $this->databasePool->releaseConnection($connection);

        } catch (\Exception $e) {
            Logger::database('error', 'VERIFICATION: Batch database lookup failed', [
                'error' => $e->getMessage(),
                'token_count' => count($tokens),
            ]);

            // Return null results for all tokens
            foreach ($tokens as $token) {
                $results[$token] = null;
            }
        }

        return $results;
    }
}
