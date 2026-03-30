<?php

namespace Need2Talk\Traits;

// ENTERPRISE FIX: NO Logger import to prevent circular dependency!
// This trait is used by Logger itself, so it must use error_log() directly

/**
 * Enterprise Redis Safety Trait
 *
 * Provides enterprise-grade Redis method safety validation for hundreds of thousands
 * of concurrent users, with automatic fallback and detailed monitoring.
 *
 * CRITICAL: This trait does NOT use Logger:: to avoid circular dependency
 * (Logger uses this trait, so trait cannot use Logger)
 */
trait EnterpriseRedisSafety
{
    /** @var array<string, bool> Method availability cache */
    private static array $methodAvailabilityCache = [];

    /** @var array<string, int> Method call statistics */
    private static array $methodCallStats = [];

    /** @var bool Redis extension availability */
    private static ?bool $redisExtensionAvailable = null;

    /**
     * Get Redis method call statistics
     */
    public static function getRedisMethodStats(): array
    {
        return [
            'method_calls' => self::$methodCallStats,
            'cached_methods' => count(self::$methodAvailabilityCache),
            'redis_available' => self::$redisExtensionAvailable,
            'total_calls' => array_sum(self::$methodCallStats),
        ];
    }

    /**
     * Reset statistics (for testing or monitoring reset)
     */
    public static function resetRedisStats(): void
    {
        self::$methodCallStats = [];
        self::$methodAvailabilityCache = [];
        self::$redisExtensionAvailable = null;
    }

    /**
     * Safely execute Redis method with enterprise error handling
     */
    protected function safeRedisCall(\Redis $redis, string $method, array $args = []): mixed
    {
        // Fast path: Check if Redis extension is available
        if (!$this->isRedisExtensionAvailable()) {
            // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
            error_log("[EnterpriseRedisSafety] Redis extension not available for method: $method");

            return null;
        }

        // Validate method exists on Redis instance
        if (!$this->isRedisMethodAvailable($redis, $method)) {
            // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
            error_log("[EnterpriseRedisSafety] Redis method not available: $method");

            return null;
        }

        try {
            // Track method call statistics
            $this->trackMethodCall($method);

            // Execute the method with enterprise error handling
            $startTime = microtime(true);
            $result = $redis->{$method}(...$args);
            $executionTime = microtime(true) - $startTime;

            // Log slow Redis operations (>100ms)
            // ENTERPRISE FIX: Exclude blocking commands (bzpopmin, brpop, blpop, etc.) as they intentionally wait
            $blockingCommands = ['bzpopmin', 'bzpopmax', 'brpop', 'blpop', 'brpoplpush', 'bzmpop', 'blmove', 'blmpop'];
            $isBlockingCommand = in_array(strtolower($method), $blockingCommands);

            if ($executionTime > 0.1 && !$isBlockingCommand) {
                // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
                $execTimeMs = round($executionTime * 1000, 2);
                $argsCount = count($args);
                $service = static::class;
                error_log("[EnterpriseRedisSafety] PERFORMANCE: Slow Redis operation detected | method={$method} | execution_time={$execTimeMs}ms | args_count={$argsCount} | service={$service}");
            }

            return $result;

        } catch (\Exception $e) {
            // Catch all exceptions including RedisException when Redis extension is available
            // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
            $errorMsg = $e->getMessage();
            $errorCode = $e->getCode();
            $exceptionType = get_class($e);
            $argsCount = count($args);
            $service = static::class;
            error_log("[EnterpriseRedisSafety] ERROR: Redis operation failed | method={$method} | error={$errorMsg} | error_code={$errorCode} | exception_type={$exceptionType} | service={$service} | args_count={$argsCount}");

            return null;

        } catch (\Exception $e) {
            // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
            $errorMsg = $e->getMessage();
            $errorType = get_class($e);
            $argsCount = count($args);
            $service = static::class;
            error_log("[EnterpriseRedisSafety] ERROR: Unexpected error in Redis operation | method={$method} | error={$errorMsg} | error_type={$errorType} | service={$service} | args_count={$argsCount}");

            return null;
        }
    }

    /**
     * Check if Redis extension is available
     */
    protected function isRedisExtensionAvailable(): bool
    {
        if (self::$redisExtensionAvailable === null) {
            self::$redisExtensionAvailable = extension_loaded('redis') && class_exists('Redis');

            if (!self::$redisExtensionAvailable) {
                // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
                $service = static::class;
                error_log("[EnterpriseRedisSafety] WARNING: Redis extension not available | service={$service} | suggestion=Install php-redis extension for enterprise performance");
            }
        }

        return self::$redisExtensionAvailable;
    }

    /**
     * Check if specific Redis method is available
     */
    protected function isRedisMethodAvailable(\Redis $redis, string $method): bool
    {
        $cacheKey = get_class($redis) . '::' . $method;

        if (!isset(self::$methodAvailabilityCache[$cacheKey])) {
            self::$methodAvailabilityCache[$cacheKey] = method_exists($redis, $method);
        }

        return self::$methodAvailabilityCache[$cacheKey];
    }

    /**
     * Track method call statistics for monitoring
     */
    protected function trackMethodCall(string $method): void
    {
        $key = static::class . '::' . $method;
        self::$methodCallStats[$key] = (self::$methodCallStats[$key] ?? 0) + 1;
    }

    /**
     * Enterprise Redis connection validator
     */
    protected function validateRedisConnection(\Redis $redis): bool
    {
        try {
            $result = $this->safeRedisCall($redis, 'ping');

            return $result === 'PONG' || $result === '+PONG';

        } catch (\Exception $e) {
            // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
            $errorMsg = $e->getMessage();
            $service = static::class;
            error_log("[EnterpriseRedisSafety] ERROR: Redis connection validation failed | error={$errorMsg} | service={$service}");

            return false;
        }
    }

    /**
     * Get Redis info with safety checks
     */
    protected function getRedisInfoSafely(\Redis $redis, ?string $section = null): array
    {
        $info = $this->safeRedisCall($redis, 'info', [$section]);

        return is_array($info) ? $info : [];
    }

    /**
     * Execute Redis transaction safely
     */
    protected function safeRedisTransaction(\Redis $redis, callable $commands): mixed
    {
        try {
            // Start transaction
            $transaction = $this->safeRedisCall($redis, 'multi');

            if (!$transaction) {
                return null;
            }

            // Execute commands
            $result = $commands($redis);

            // Execute transaction
            $execResult = $this->safeRedisCall($redis, 'exec');

            return $execResult;

        } catch (\Exception $e) {
            // Try to discard transaction
            $this->safeRedisCall($redis, 'discard');

            // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
            $errorMsg = $e->getMessage();
            $service = static::class;
            error_log("[EnterpriseRedisSafety] ERROR: Redis transaction failed | error={$errorMsg} | service={$service}");

            return null;
        }
    }

    /**
     * Enterprise Redis batch operation
     */
    protected function safeRedisBatch(\Redis $redis, array $operations): array
    {
        $results = [];
        $failed = 0;

        foreach ($operations as $i => $operation) {
            if (!isset($operation['method'])) {
                $failed++;

                continue;
            }

            $result = $this->safeRedisCall(
                $redis,
                $operation['method'],
                $operation['args'] ?? []
            );

            $results[$i] = $result;

            if ($result === null) {
                $failed++;
            }
        }

        // Log batch operation statistics
        // ENTERPRISE FIX: Use error_log() to avoid circular dependency with Logger
        $totalOps = count($operations);
        $successful = $totalOps - $failed;
        $successRate = round(($successful / $totalOps) * 100, 2);
        $service = static::class;
        error_log("[EnterpriseRedisSafety] INFO: Redis batch operation completed | total_operations={$totalOps} | successful={$successful} | failed={$failed} | success_rate={$successRate}% | service={$service}");

        return $results;
    }
}
