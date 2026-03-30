#!/usr/bin/env php
<?php
/**
 * OVERLAY SYSTEM END-TO-END TEST
 * Tests all overlay components: Listens (Plays), Reactions, Flush
 *
 * ENTERPRISE V5.3: Renamed "views" to "listens" - these are AUDIO files, not videos!
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Cache\WriteBehindBuffer;
use Need2Talk\Services\Cache\OverlayService;
use Need2Talk\Services\Cache\OverlayFlushService;
use Need2Talk\Services\Cache\FriendshipOverlayService;
use Need2Talk\Services\Cache\UserSettingsOverlayService;

echo "=== OVERLAY SYSTEM END-TO-END TEST ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

$buffer = WriteBehindBuffer::getInstance();
$overlay = OverlayService::getInstance();
$flush = OverlayFlushService::getInstance();

// Check availability
echo "1. CHECKING SERVICE AVAILABILITY\n";
echo "   WriteBehindBuffer: " . ($buffer ? "OK" : "FAIL") . "\n";
echo "   OverlayService: " . ($overlay->isAvailable() ? "OK" : "FAIL") . "\n";
echo "   OverlayFlushService: " . ($flush ? "OK" : "FAIL") . "\n";

// Check friendships overlay
$friendshipOverlay = FriendshipOverlayService::getInstance();
echo "   FriendshipOverlayService: " . ($friendshipOverlay->isAvailable() ? "OK" : "FAIL") . "\n";

// Check settings overlay
$settingsOverlay = UserSettingsOverlayService::getInstance();
echo "   UserSettingsOverlayService: " . ($settingsOverlay->isAvailable() ? "OK" : "FAIL") . "\n";
echo "\n";

// Get test data
$db = db();
$testPost = $db->findOne("SELECT ap.id, af.play_count FROM audio_posts ap JOIN audio_files af ON ap.audio_file_id = af.id WHERE ap.deleted_at IS NULL LIMIT 1");
if (!$testPost) {
    echo "ERROR: No test post found\n";
    exit(1);
}
$postId = (int)$testPost['id'];
$originalPlayCount = (int)$testPost['play_count'];
$userId = 100025; // Valid user (zelistore) from DB

echo "2. TEST DATA\n";
echo "   Post ID: $postId\n";
echo "   User ID: $userId\n";
echo "   Original play_count: $originalPlayCount\n\n";

// Test listen (play) buffering - ENTERPRISE V5.3: views → listens for audio
echo "3. TESTING LISTEN BUFFERING (audio plays)\n";
$listensBefore = $overlay->getViews($postId); // Method still named getViews internally
echo "   Overlay listens BEFORE: $listensBefore\n";

$buffer->bufferView($postId, $userId); // Method still named bufferView internally

$listensAfter = $overlay->getViews($postId);
echo "   Overlay listens AFTER: $listensAfter\n";

$statusAfterListen = $buffer->getBufferStatus();
echo "   Dirty set listens_pending: " . $statusAfterListen['views_pending'] . "\n";

if ($listensAfter > $listensBefore) {
    echo "   RESULT: LISTEN OVERLAY INCREMENT OK\n";
} else {
    echo "   RESULT: LISTEN OVERLAY INCREMENT FAILED\n";
}
echo "\n";

// Test reaction buffering
echo "4. TESTING REACTION BUFFERING\n";
$buffer->bufferReaction($postId, $userId, 1, null); // emotion_id=1, prevEmotionId=null

$statusAfterReaction = $buffer->getBufferStatus();
echo "   Dirty set reactions_pending: " . $statusAfterReaction['reactions_pending'] . "\n";

if ($statusAfterReaction['reactions_pending'] > 0) {
    echo "   RESULT: REACTION BUFFERING OK\n";
} else {
    echo "   RESULT: REACTION BUFFERING FAILED\n";
}
echo "\n";

// Test flush
echo "5. TESTING FLUSH TO DATABASE\n";
$flushResult = $flush->flush();

echo "   Success: " . ($flushResult['success'] ? 'YES' : 'NO') . "\n";
echo "   Reactions flushed: " . $flushResult['reactions_flushed'] . "\n";
echo "   Listens flushed: " . $flushResult['views_flushed'] . "\n"; // Key still 'views_flushed' internally
echo "   Friendships cleaned: " . $flushResult['friendships_cleaned'] . "\n";
echo "   Settings cleaned: " . $flushResult['settings_cleaned'] . "\n";
echo "   Duration: " . $flushResult['duration_ms'] . "ms\n";

if (isset($flushResult['error'])) {
    echo "   ERROR: " . $flushResult['error'] . "\n";
}
echo "\n";

// Verify DB - ENTERPRISE V5.3: play_count is in audio_files, not audio_posts
echo "6. VERIFYING DATABASE UPDATES\n";
$updatedPost = $db->findOne(
    "SELECT af.play_count FROM audio_posts ap JOIN audio_files af ON ap.audio_file_id = af.id WHERE ap.id = ?",
    [$postId],
    ['cache' => false]
);
$newPlayCount = (int)$updatedPost['play_count'];
echo "   New play_count: $newPlayCount\n";

if ($newPlayCount > $originalPlayCount) {
    echo "   RESULT: DB UPDATE OK (increased by " . ($newPlayCount - $originalPlayCount) . ")\n";
} else {
    echo "   RESULT: DB play_count unchanged (overlay may still have pending)\n";
}

// Check reaction in DB
$reaction = $db->findOne(
    "SELECT * FROM audio_reactions WHERE audio_post_id = ? AND user_id = ?",
    [$postId, $userId],
    ['cache' => false]
);
if ($reaction) {
    echo "   Reaction in DB: emotion_id=" . $reaction['emotion_id'] . "\n";
} else {
    echo "   No reaction found in DB\n";
}
echo "\n";

// Final status
echo "7. FINAL BUFFER STATUS\n";
$finalStatus = $flush->getStatus();
echo "   Reactions pending: " . $finalStatus['reactions_pending'] . "\n";
echo "   Listens pending: " . $finalStatus['views_pending'] . "\n"; // Key still 'views_pending' internally
echo "   Friendships pending: " . $finalStatus['friendships_pending'] . "\n";
echo "   Settings pending: " . $finalStatus['settings_pending'] . "\n";
echo "\n";

echo "=== TEST COMPLETE ===\n";
