<?php

namespace Need2Talk\Core;

use Need2Talk\Services\Logger;

/**
 * Enterprise Cache Factory - Singleton Pattern
 *
 * Ensures all CacheManager instances use the same Enterprise L1 Cache
 * configuration across the entire system. This eliminates old APCu
 * error messages and guarantees consistent enterprise caching.
 */
class EnterpriseCacheFactory
{
    private static ?CacheManager $instance = null;

    private static bool $initialized = false;

    /**
     * Get singleton CacheManager instance with Enterprise L1 Cache
     */
    public static function getInstance(array $config = []): CacheManager
    {
        if (self::$instance === null) {
            self::$instance = new CacheManager($config);
            self::$initialized = true;

            // Cache factory initialized
        }

        return self::$instance;
    }

    /**
     * Force refresh cache instance (for testing/debugging)
     */
    public static function refresh(array $config = []): CacheManager
    {
        self::$instance = null;
        self::$initialized = false;

        return self::getInstance($config);
    }

    /**
     * Check if enterprise cache is initialized
     */
    public static function isInitialized(): bool
    {
        return self::$initialized && self::$instance !== null;
    }

    /**
     * Get cache status for monitoring
     */
    public static function getStatus(): array
    {
        if (self::$instance === null) {
            return [
                'initialized' => false,
                'type' => 'none',
            ];
        }

        return [
            'initialized' => true,
            'type' => 'enterprise_singleton',
            'l1_available' => self::$instance->has('test_key') !== false,
            'instance_hash' => spl_object_hash(self::$instance),
        ];
    }

    /**
     * Enterprise cache warmup
     */
    public static function warmup(): void
    {
        if (self::$instance === null) {
            return;
        }

        // Warm up with commonly used cache keys
        $warmupKeys = [
            'system_status' => 'Enterprise system active',
            'cache_test' => time(),
            'warmup_complete' => true,
        ];

        foreach ($warmupKeys as $key => $value) {
            self::$instance->set("warmup:$key", $value, 300);
        }
    }
}
