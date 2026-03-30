#!/usr/bin/env php
<?php

/**
 * Enterprise Cache Verification Script
 *
 * Verifies Redis, Memcached, and OPcache are working correctly
 * for millions of concurrent users
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

echo "\n";
echo "========================================================================\n";
echo "ENTERPRISE CACHE VERIFICATION - MILLIONS OF CONCURRENT USERS\n";
echo "========================================================================\n";
echo "\n";

$results = [
    'redis' => [],
    'memcached' => [],
    'opcache' => [],
    'overall_status' => 'PASS',
];

// ========================================================================
// REDIS VERIFICATION
// ========================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1. REDIS VERIFICATION\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $redis = new Redis();
    $host = $_ENV['REDIS_HOST'] ?? 'redis';
    $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);

    echo "Connecting to Redis at {$host}:{$port}...\n";
    $connected = $redis->connect($host, $port, 2.5);

    if (!$connected) {
        throw new Exception("Failed to connect to Redis");
    }

    echo "✓ Redis connection: SUCCESS\n";
    $results['redis']['connection'] = 'OK';

    // Test PING
    $ping = $redis->ping();
    echo "✓ Redis PING: " . ($ping === true || $ping === '+PONG' ? 'PONG' : $ping) . "\n";
    $results['redis']['ping'] = 'OK';

    // Get Redis info
    $info = $redis->info();
    echo "\nRedis Server Info:\n";
    echo "  - Version: " . ($info['redis_version'] ?? 'unknown') . "\n";
    echo "  - Uptime: " . ($info['uptime_in_seconds'] ?? 0) . " seconds\n";
    echo "  - Connected clients: " . ($info['connected_clients'] ?? 0) . "\n";
    echo "  - Used memory: " . ($info['used_memory_human'] ?? 'unknown') . "\n";
    echo "  - Total commands processed: " . number_format($info['total_commands_processed'] ?? 0) . "\n";

    // Test all databases used by the system
    $databases = [
        0 => 'Cache & General Data',
        1 => 'L1 Enterprise Cache + Sessions',
        2 => 'Email Queue',
        3 => 'Rate Limiting',
    ];

    echo "\n";
    foreach ($databases as $dbNum => $purpose) {
        $redis->select($dbNum);
        $keyCount = $redis->dbSize();
        echo "  - DB{$dbNum} ({$purpose}): " . number_format($keyCount) . " keys\n";
        $results['redis']["db{$dbNum}"] = $keyCount;
    }

    // Test write/read performance
    echo "\n";
    echo "Testing Redis performance...\n";
    $redis->select(0);

    $testKey = 'enterprise_test_' . uniqid();
    $testValue = str_repeat('A', 1024); // 1KB test data

    $iterations = 1000;
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $redis->setex($testKey . "_$i", 60, $testValue);
    }

    $writeTime = microtime(true) - $start;
    $writeOps = $iterations / $writeTime;

    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $redis->get($testKey . "_$i");
    }

    $readTime = microtime(true) - $start;
    $readOps = $iterations / $readTime;

    // Cleanup
    for ($i = 0; $i < $iterations; $i++) {
        $redis->del($testKey . "_$i");
    }

    echo "  - Write operations: " . number_format($writeOps, 0) . " ops/sec\n";
    echo "  - Read operations: " . number_format($readOps, 0) . " ops/sec\n";
    $results['redis']['write_ops'] = $writeOps;
    $results['redis']['read_ops'] = $readOps;

    // Performance thresholds for millions of users
    if ($writeOps < 5000) {
        echo "  ⚠ WARNING: Write performance below recommended threshold (5000 ops/sec)\n";
        $results['overall_status'] = 'WARNING';
    } else {
        echo "  ✓ Write performance: EXCELLENT\n";
    }

    if ($readOps < 10000) {
        echo "  ⚠ WARNING: Read performance below recommended threshold (10000 ops/sec)\n";
        $results['overall_status'] = 'WARNING';
    } else {
        echo "  ✓ Read performance: EXCELLENT\n";
    }

    echo "\n✓ Redis verification: PASS\n";
    $results['redis']['status'] = 'PASS';

} catch (Exception $e) {
    echo "\n✗ Redis verification: FAIL\n";
    echo "Error: " . $e->getMessage() . "\n";
    $results['redis']['status'] = 'FAIL';
    $results['redis']['error'] = $e->getMessage();
    $results['overall_status'] = 'FAIL';
}

// ========================================================================
// MEMCACHED VERIFICATION
// ========================================================================

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2. MEMCACHED VERIFICATION\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    if (!class_exists('Memcached')) {
        throw new Exception("Memcached extension not installed");
    }

    $memcached = new Memcached();
    $host = $_ENV['MEMCACHED_HOST'] ?? '127.0.0.1';
    $port = (int) ($_ENV['MEMCACHED_PORT'] ?? 11211);

    echo "Connecting to Memcached at {$host}:{$port}...\n";
    $memcached->addServer($host, $port);

    // Test connection with a simple set/get
    $testKey = 'enterprise_memcached_test_' . uniqid();
    $testValue = 'connection_test';

    $setResult = $memcached->set($testKey, $testValue, 60);
    if (!$setResult) {
        throw new Exception("Failed to write to Memcached: " . $memcached->getResultMessage());
    }

    $getValue = $memcached->get($testKey);
    if ($getValue !== $testValue) {
        throw new Exception("Failed to read from Memcached: " . $memcached->getResultMessage());
    }

    echo "✓ Memcached connection: SUCCESS\n";
    $results['memcached']['connection'] = 'OK';

    // Get stats
    $stats = $memcached->getStats();
    $serverKey = array_key_first($stats);
    $serverStats = $stats[$serverKey];

    if ($serverStats !== false && is_array($serverStats)) {
        echo "\nMemcached Server Info:\n";
        echo "  - Version: " . ($serverStats['version'] ?? 'unknown') . "\n";
        echo "  - Uptime: " . ($serverStats['uptime'] ?? 0) . " seconds\n";
        echo "  - Current items: " . number_format($serverStats['curr_items'] ?? 0) . "\n";
        echo "  - Total items: " . number_format($serverStats['total_items'] ?? 0) . "\n";
        echo "  - Memory used: " . number_format(($serverStats['bytes'] ?? 0) / 1024 / 1024, 2) . " MB\n";
        echo "  - Memory limit: " . number_format(($serverStats['limit_maxbytes'] ?? 0) / 1024 / 1024, 2) . " MB\n";

        $hitRate = 0;
        if (isset($serverStats['get_hits']) && isset($serverStats['cmd_get']) && $serverStats['cmd_get'] > 0) {
            $hitRate = ($serverStats['get_hits'] / $serverStats['cmd_get']) * 100;
        }
        echo "  - Hit rate: " . number_format($hitRate, 2) . "%\n";

        if ($hitRate > 0 && $hitRate < 80) {
            echo "  ⚠ WARNING: Hit rate below 80%\n";
            $results['overall_status'] = 'WARNING';
        } elseif ($hitRate >= 80) {
            echo "  ✓ Hit rate: EXCELLENT\n";
        }
    }

    // Test performance
    echo "\nTesting Memcached performance...\n";

    $testKey = 'enterprise_perf_test_';
    $testValue = str_repeat('B', 1024); // 1KB test data

    $iterations = 1000;
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $memcached->set($testKey . $i, $testValue, 60);
    }

    $writeTime = microtime(true) - $start;
    $writeOps = $iterations / $writeTime;

    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $memcached->get($testKey . $i);
    }

    $readTime = microtime(true) - $start;
    $readOps = $iterations / $readTime;

    // Cleanup
    for ($i = 0; $i < $iterations; $i++) {
        $memcached->delete($testKey . $i);
    }

    echo "  - Write operations: " . number_format($writeOps, 0) . " ops/sec\n";
    echo "  - Read operations: " . number_format($readOps, 0) . " ops/sec\n";
    $results['memcached']['write_ops'] = $writeOps;
    $results['memcached']['read_ops'] = $readOps;

    // Performance thresholds
    if ($writeOps < 3000) {
        echo "  ⚠ WARNING: Write performance below recommended threshold (3000 ops/sec)\n";
        $results['overall_status'] = 'WARNING';
    } else {
        echo "  ✓ Write performance: EXCELLENT\n";
    }

    if ($readOps < 5000) {
        echo "  ⚠ WARNING: Read performance below recommended threshold (5000 ops/sec)\n";
        $results['overall_status'] = 'WARNING';
    } else {
        echo "  ✓ Read performance: EXCELLENT\n";
    }

    echo "\n✓ Memcached verification: PASS\n";
    $results['memcached']['status'] = 'PASS';

} catch (Exception $e) {
    echo "\n✗ Memcached verification: FAIL\n";
    echo "Error: " . $e->getMessage() . "\n";
    $results['memcached']['status'] = 'FAIL';
    $results['memcached']['error'] = $e->getMessage();
    $results['overall_status'] = 'FAIL';
}

// ========================================================================
// OPCACHE VERIFICATION
// ========================================================================

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3. OPCACHE VERIFICATION\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    if (!function_exists('opcache_get_status')) {
        throw new Exception("OPcache not installed or not enabled");
    }

    $status = opcache_get_status();

    if ($status === false) {
        throw new Exception("OPcache is installed but not enabled");
    }

    echo "✓ OPcache enabled: SUCCESS\n";
    $results['opcache']['enabled'] = true;

    $config = opcache_get_configuration();

    echo "\nOPcache Configuration:\n";
    echo "  - Memory consumption: " . number_format($config['directives']['opcache.memory_consumption'] / 1024 / 1024, 0) . " MB\n";
    echo "  - Max accelerated files: " . number_format($config['directives']['opcache.max_accelerated_files']) . "\n";
    echo "  - Interned strings buffer: " . number_format($config['directives']['opcache.interned_strings_buffer']) . " MB\n";
    echo "  - Validate timestamps: " . ($config['directives']['opcache.validate_timestamps'] ? 'Yes' : 'No') . "\n";
    echo "  - Revalidate frequency: " . $config['directives']['opcache.revalidate_freq'] . " seconds\n";

    echo "\nOPcache Statistics:\n";
    $memUsage = $status['memory_usage'];
    echo "  - Used memory: " . number_format($memUsage['used_memory'] / 1024 / 1024, 2) . " MB\n";
    echo "  - Free memory: " . number_format($memUsage['free_memory'] / 1024 / 1024, 2) . " MB\n";
    echo "  - Wasted memory: " . number_format($memUsage['wasted_memory'] / 1024 / 1024, 2) . " MB (" . number_format($memUsage['current_wasted_percentage'], 2) . "%)\n";

    $stats = $status['opcache_statistics'];
    echo "  - Cached scripts: " . number_format($stats['num_cached_scripts']) . "\n";
    echo "  - Cached keys: " . number_format($stats['num_cached_keys']) . "\n";
    echo "  - Max cached keys: " . number_format($stats['max_cached_keys']) . "\n";
    echo "  - Hits: " . number_format($stats['hits']) . "\n";
    echo "  - Misses: " . number_format($stats['misses']) . "\n";

    $hitRate = 0;
    if (($stats['hits'] + $stats['misses']) > 0) {
        $hitRate = ($stats['hits'] / ($stats['hits'] + $stats['misses'])) * 100;
    }
    echo "  - Hit rate: " . number_format($hitRate, 2) . "%\n";

    $results['opcache']['hit_rate'] = $hitRate;

    // Check thresholds
    $warnings = [];

    if ($hitRate > 0 && $hitRate < 95) {
        $warnings[] = "Hit rate below 95% (current: " . number_format($hitRate, 2) . "%)";
    } else {
        echo "  ✓ Hit rate: EXCELLENT\n";
    }

    if ($memUsage['current_wasted_percentage'] > 10) {
        $warnings[] = "Wasted memory above 10% (current: " . number_format($memUsage['current_wasted_percentage'], 2) . "%)";
    }

    $memoryUsagePercent = ($memUsage['used_memory'] / ($memUsage['used_memory'] + $memUsage['free_memory'])) * 100;
    if ($memoryUsagePercent > 90) {
        $warnings[] = "Memory usage above 90% (current: " . number_format($memoryUsagePercent, 2) . "%)";
    }

    if ($stats['num_cached_scripts'] >= $stats['max_cached_keys'] * 0.9) {
        $warnings[] = "Cached scripts approaching limit";
    }

    if (!empty($warnings)) {
        echo "\n  ⚠ WARNINGS:\n";
        foreach ($warnings as $warning) {
            echo "    - " . $warning . "\n";
        }
        $results['overall_status'] = 'WARNING';
        $results['opcache']['warnings'] = $warnings;
    }

    // Recommendation for enterprise scale
    $memConsumption = $config['directives']['opcache.memory_consumption'];
    $maxFiles = $config['directives']['opcache.max_accelerated_files'];

    echo "\n";
    echo "Enterprise Scale Recommendations:\n";

    if ($memConsumption < 256 * 1024 * 1024) {
        echo "  ⚠ Consider increasing opcache.memory_consumption to at least 256MB\n";
        echo "    Current: " . number_format($memConsumption / 1024 / 1024, 0) . "MB\n";
        $results['overall_status'] = 'WARNING';
    } else {
        echo "  ✓ Memory consumption: ADEQUATE for millions of users\n";
    }

    if ($maxFiles < 20000) {
        echo "  ⚠ Consider increasing opcache.max_accelerated_files to at least 20000\n";
        echo "    Current: " . number_format($maxFiles) . "\n";
        $results['overall_status'] = 'WARNING';
    } else {
        echo "  ✓ Max accelerated files: ADEQUATE for millions of users\n";
    }

    echo "\n✓ OPcache verification: PASS\n";
    $results['opcache']['status'] = 'PASS';

} catch (Exception $e) {
    echo "\n✗ OPcache verification: FAIL\n";
    echo "Error: " . $e->getMessage() . "\n";
    $results['opcache']['status'] = 'FAIL';
    $results['opcache']['error'] = $e->getMessage();
    $results['overall_status'] = 'FAIL';
}

// ========================================================================
// MULTI-LEVEL CACHE TEST
// ========================================================================

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4. MULTI-LEVEL CACHE INTEGRATION TEST\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    echo "Testing Enterprise Multi-Level Cache (L1→L2→L3)...\n";

    $cache = \Need2Talk\Core\EnterpriseCacheFactory::getInstance();

    if (!$cache) {
        throw new Exception("EnterpriseCacheFactory failed to initialize");
    }

    echo "✓ EnterpriseCacheFactory initialized\n";

    // Test cache write/read
    $testKey = 'enterprise_multilevel_test_' . uniqid();
    $testData = [
        'user_id' => 12345,
        'data' => str_repeat('X', 1024),
        'timestamp' => time(),
    ];

    // Write to cache
    $writeResult = $cache->set($testKey, $testData, 300);
    if (!$writeResult) {
        throw new Exception("Failed to write to multi-level cache");
    }
    echo "✓ Write to multi-level cache: SUCCESS\n";

    // Read from cache
    $readData = $cache->get($testKey);
    if ($readData === false || $readData !== $testData) {
        throw new Exception("Failed to read from multi-level cache or data mismatch");
    }
    echo "✓ Read from multi-level cache: SUCCESS\n";
    echo "✓ Data integrity: VERIFIED\n";

    // Test cache invalidation
    $deleteResult = $cache->delete($testKey);
    if (!$deleteResult) {
        throw new Exception("Failed to delete from multi-level cache");
    }
    echo "✓ Cache invalidation: SUCCESS\n";

    // Give cache layers time to sync (if needed)
    usleep(50000); // 50ms

    // Verify deletion from each layer
    echo "\nVerifying deletion from each layer...\n";

    // Check L3 (Redis)
    $redis = new Redis();
    $redis->connect($_ENV['REDIS_HOST'] ?? 'redis', (int) ($_ENV['REDIS_PORT'] ?? 6379));
    $redis->select(0);
    $redisHasKey = $redis->exists($testKey) > 0;
    echo "  - Redis L3: " . ($redisHasKey ? '✗ STILL EXISTS' : '✓ Deleted') . "\n";

    // Check L2 (Memcached)
    $memcached = new Memcached();
    $memcached->addServer($_ENV['MEMCACHED_HOST'] ?? 'memcached', (int) ($_ENV['MEMCACHED_PORT'] ?? 11211));
    $memcachedValue = $memcached->get($testKey);
    $memcachedHasKey = ($memcachedValue !== false) || ($memcached->getResultCode() !== \Memcached::RES_NOTFOUND);
    echo "  - Memcached L2: " . ($memcachedHasKey ? '✗ STILL EXISTS' : '✓ Deleted') . "\n";

    // Check L1 (Enterprise Redis with prefix)
    $redis->setOption(Redis::OPT_PREFIX, 'L1:');
    $l1HasKey = $redis->exists($testKey) > 0;
    echo "  - Enterprise L1: " . ($l1HasKey ? '✗ STILL EXISTS' : '✓ Deleted') . "\n";

    // Overall verification
    if ($redisHasKey || $memcachedHasKey || $l1HasKey) {
        throw new Exception("Cache key still exists in one or more layers after deletion");
    }

    // Verify through cache manager using has() method
    if ($cache->has($testKey)) {
        throw new Exception("Cache manager reports key still exists after deletion");
    }

    // Verify get() returns null/false for deleted key
    $verifyDeleted = $cache->get($testKey);
    if ($verifyDeleted !== null && $verifyDeleted !== false) {
        throw new Exception("Cache manager returns unexpected data: " . var_export($verifyDeleted, true));
    }
    echo "✓ Deletion verified: SUCCESS\n";

    echo "\n✓ Multi-level cache integration: PASS\n";
    $results['multilevel_cache']['status'] = 'PASS';

} catch (Exception $e) {
    echo "\n✗ Multi-level cache integration: FAIL\n";
    echo "Error: " . $e->getMessage() . "\n";
    $results['multilevel_cache']['status'] = 'FAIL';
    $results['multilevel_cache']['error'] = $e->getMessage();
    $results['overall_status'] = 'FAIL';
}

// ========================================================================
// FINAL SUMMARY
// ========================================================================

echo "\n";
echo "========================================================================\n";
echo "FINAL SUMMARY\n";
echo "========================================================================\n";
echo "\n";

echo "Redis: " . ($results['redis']['status'] ?? 'N/A') . "\n";
echo "Memcached: " . ($results['memcached']['status'] ?? 'N/A') . "\n";
echo "OPcache: " . ($results['opcache']['status'] ?? 'N/A') . "\n";
echo "Multi-level Cache: " . ($results['multilevel_cache']['status'] ?? 'N/A') . "\n";
echo "\n";

$statusEmoji = [
    'PASS' => '✓',
    'WARNING' => '⚠',
    'FAIL' => '✗',
];

$emoji = $statusEmoji[$results['overall_status']] ?? '?';
echo "{$emoji} OVERALL STATUS: {$results['overall_status']}\n";
echo "\n";

if ($results['overall_status'] === 'FAIL') {
    echo "CRITICAL: System NOT ready for production at enterprise scale.\n";
    echo "Fix errors above before deploying.\n";
    exit(1);
} elseif ($results['overall_status'] === 'WARNING') {
    echo "WARNING: System functional but has performance concerns.\n";
    echo "Review warnings above for optimization opportunities.\n";
    exit(0);
} else {
    echo "SUCCESS: System ready for millions of concurrent users.\n";
    echo "All caching systems verified and performing at enterprise level.\n";
    exit(0);
}
