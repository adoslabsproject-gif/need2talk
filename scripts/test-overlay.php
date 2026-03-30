<?php
/**
 * Test Overlay Service - Debug reactions
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Cache\OverlayService;
use Need2Talk\Core\EnterpriseRedisManager;

echo "=== OVERLAY SERVICE TEST ===\n\n";

$overlay = OverlayService::getInstance();

echo "1. Overlay available: " . ($overlay->isAvailable() ? "YES" : "NO") . "\n";

// Test setUserReaction
$testUserId = 100017;
$testPostId = 10;
$testEmotionId = 1;

echo "\n2. Testing setUserReaction($testUserId, $testPostId, $testEmotionId)...\n";
$result = $overlay->setUserReaction($testUserId, $testPostId, $testEmotionId);
echo "   Result: " . ($result ? "true" : "false") . "\n";

// Check Redis directly
echo "\n3. Checking Redis directly...\n";
try {
    $redis = EnterpriseRedisManager::getInstance()->getConnection('overlay');
    $key = "personal:{$testUserId}:rx:{$testPostId}";
    $value = $redis->get($key);
    echo "   Key: $key\n";
    echo "   Value: " . var_export($value, true) . "\n";

    // List all personal keys
    echo "\n4. All personal:* keys in overlay DB:\n";
    $keys = $redis->keys("personal:*");
    if (empty($keys)) {
        echo "   (none found)\n";
    } else {
        foreach ($keys as $k) {
            $v = $redis->get($k);
            echo "   $k = $v\n";
        }
    }

    // Check overlay reactions hash
    echo "\n5. overlay:$testPostId:reactions hash:\n";
    $reactions = $redis->hGetAll("overlay:{$testPostId}:reactions");
    print_r($reactions);

} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
