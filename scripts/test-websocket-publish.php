<?php
/**
 * Test WebSocket Publish - Enterprise Debug Script
 *
 * Tests the WebSocketPublisher to verify Redis PUBLISH is working
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== WebSocket Publish Test ===\n\n";

// Full framework bootstrap (includes env() helper)
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/app/bootstrap.php';

$redisPassword = env('REDIS_PASSWORD', '');
echo "Redis password from env: " . substr($redisPassword, 0, 4) . "...\n\n";

// Test 1: Direct Redis connection
echo "TEST 1: Direct Redis PUBLISH (from .env)\n";
try {
    $redis = new Redis();
    $redis->connect('redis', 6379, 5.0);
    $redis->auth($redisPassword);
    $redis->select(4);

    $testChannel = 'websocket:events:test-' . time();
    $testMessage = json_encode([
        'channel' => 'test',
        'event' => 'test_event',
        'data' => ['test' => true, 'time' => time()],
        'timestamp' => microtime(true)
    ]);

    $result = $redis->publish($testChannel, $testMessage);
    echo "  Redis PUBLISH result: $result subscribers received\n";
    echo "  Channel: $testChannel\n";
    echo "  ✅ Direct Redis PUBLISH works!\n\n";
} catch (Exception $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n\n";
}

// Test 2: WebSocketPublisher via framework
echo "TEST 2: WebSocketPublisher::publish\n";
try {
    $result = \Need2Talk\Services\WebSocketPublisher::publish(
        'user:test-user-uuid-12345678901234567890',
        'test_event',
        ['test' => true, 'from' => 'test-script']
    );
    echo "  WebSocketPublisher::publish result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
    if ($result) {
        echo "  ✅ WebSocketPublisher works!\n";
    } else {
        echo "  ❌ WebSocketPublisher returned false\n";
    }
} catch (Exception $e) {
    echo "  ❌ EXCEPTION: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Test 3: WebSocketPublisher to a real user (if I can find one in the test)
echo "\nTEST 3: WebSocketPublisher::publishToUser (dm_received event)\n";
try {
    // Use a known test UUID
    $testUuid = 'acc353e9-e27e-4406-908e-f4a0040e2373';
    $result = \Need2Talk\Services\WebSocketPublisher::publishToUser(
        $testUuid,
        'dm_received',
        [
            'conversation_uuid' => 'test-conv-' . time(),
            'message' => [
                'uuid' => 'test-msg-' . time(),
                'content' => 'Test message from debug script',
                'sender_uuid' => 'test-sender',
                'sender_nickname' => 'Debug Script',
                'created_at' => date('c')
            ]
        ]
    );
    echo "  publishToUser result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
    if ($result) {
        echo "  ✅ dm_received event published to $testUuid!\n";
    } else {
        echo "  ❌ publishToUser returned false\n";
    }
} catch (Exception $e) {
    echo "  ❌ EXCEPTION: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Test Complete ===\n";
