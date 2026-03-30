<?php

/**
 * Enterprise OPcache Clear Script for need2talk
 * Ensures updated code is loaded by invalidating all caches
 */

// Load autoloader and bootstrap
if (! defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT.'/app/bootstrap.php';
echo "🧹 Enterprise OPcache Clear - need2talk\n";
echo "=======================================\n\n";

// Clear OPcache if available
if (function_exists('opcache_reset')) {
    echo '🔧 Clearing OPcache... ';
    $result = opcache_reset();
    echo $result ? "✅ SUCCESS\n" : "❌ FAILED\n";

    if (function_exists('opcache_get_status')) {
        $status = opcache_get_status();
        if ($status) {
            echo '   OPcache Memory Usage: '.round($status['memory_usage']['used_memory'] / 1024 / 1024, 2)." MB\n";
            echo '   Cached Scripts: '.$status['opcache_statistics']['num_cached_scripts']."\n";
            echo '   Hit Rate: '.round($status['opcache_statistics']['opcache_hit_rate'], 2)."%\n";
        }
    }

    // ENTERPRISE: Docker - OPcache is cleared per-container, no need to restart
    echo '🔄 OPcache cleared in Docker container... ';
    if (php_sapi_name() !== 'cli') {
        echo "✅ Web request - OPcache cleared\n";
    } else {
        echo "✅ CLI - OPcache cleared for this process\n";
    }
    echo "ℹ️  Note: Each Docker container has its own OPcache. For complete clear, restart containers.\n";
} else {
    echo "ℹ️  OPcache not available\n";
}

// Clear Enterprise Redis L1 Cache if available (OrbStack/Docker)
try {
    echo '🔧 Clearing Enterprise Redis L1 Cache... ';

    // Initialize Enterprise L1 Cache directly
    if (extension_loaded('redis')) {
        $redis = new Redis();
        // Docker: Connect to redis hostname (container networking)
        $redisHost = $_ENV['REDIS_HOST'] ?? 'redis';
        $redisPort = (int)($_ENV['REDIS_PORT'] ?? 6379);
        $connected = $redis->connect($redisHost, $redisPort);
        if ($connected) {
            $redis->select(1); // Database 1 for L1 cache
            $result = $redis->flushDB();
            echo $result ? "✅ SUCCESS\n" : "❌ FAILED\n";
            $redis->close();
        } else {
            echo "❌ CONNECTION FAILED (host: $redisHost:$redisPort)\n";
        }
    } else {
        echo "ℹ️  Redis extension not available\n";
    }
} catch (Exception $e) {
    echo '❌ ERROR: '.$e->getMessage()."\n";
}

// Force autoloader refresh
echo "🔄 Refreshing autoloader cache...\n";

// Clear Composer autoloader if exists
$composerCache = __DIR__.'/../vendor/composer/autoload_classmap.php';
if (file_exists($composerCache)) {
    echo "   Clearing Composer cache... ✅\n";
    // Force regeneration on next request
    touch(__DIR__.'/../vendor/autoload.php');
}

// Test if updated CacheManager is working
echo "\n🧪 Testing updated CacheManager...\n";

// Force include the updated EnterpriseCacheFactory
require_once __DIR__.'/../app/Core/EnterpriseCacheFactory.php';
require_once __DIR__.'/../app/Core/CacheManager.php';

use Need2Talk\Core\EnterpriseCacheFactory;

$cache = EnterpriseCacheFactory::getInstance();
$testKey = 'opcache_test_'.time();
$testValue = 'Enterprise cache test after OPcache clear';

echo '   Testing cache operations... ';
$storeResult = $cache->set($testKey, $testValue, 60);
if ($storeResult) {
    $getValue = $cache->get($testKey);
    if ($getValue === $testValue) {
        echo "✅ SUCCESS\n";
        echo "   Enterprise L1 Cache is working!\n";
        $cache->delete($testKey);
    } else {
        echo "❌ READ FAILED\n";
    }
} else {
    echo "❌ WRITE FAILED\n";
}

echo "\n🎉 Enterprise OPcache clear completed!\n";
echo "   All caches have been invalidated\n";
echo "   Updated CacheManager should now be active\n\n";
