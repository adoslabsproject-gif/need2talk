#!/usr/bin/env php
<?php
/**
 * TEST SCRIPT: Cookie Consent Adoption Flow
 *
 * Simulates the complete flow:
 * 1. Anonymous user accepts cookies (user_id=NULL, session_id=old)
 * 2. User logs in (adoption should set user_id, update session_id)
 * 3. Verify adoption worked correctly
 */

// Bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

echo "=== COOKIE CONSENT ADOPTION TEST ===\n\n";

// Test database connection
$db = db_pdo();

// Get first real user ID (to satisfy foreign key constraint)
$stmt = $db->query('SELECT id FROM users LIMIT 1');
$firstUser = $stmt->fetch(PDO::FETCH_ASSOC);
$testUserId = $firstUser['id'];

echo "Using test user ID: {$testUserId}\n\n";

// Step 1: Create anonymous consent (simulate pre-login)
echo "STEP 1: Creating anonymous cookie consent...\n";
$oldSessionId = 'test_session_' . bin2hex(random_bytes(16));
$testIp = '127.0.0.1';

$stmt = $db->prepare('
    INSERT INTO user_cookie_consent
    (user_id, session_id, ip_address, user_agent, consent_type, consent_version, expires_at)
    VALUES
    (NULL, :session_id, :ip, :user_agent, :consent_type, :version, NOW() + INTERVAL \'365 days\')
');

$stmt->execute([
    'session_id' => $oldSessionId,
    'ip' => $testIp,
    'user_agent' => 'TEST-SCRIPT',
    'consent_type' => 'accepted_all',
    'version' => '1.0',
]);

$consentId = $db->lastInsertId();
echo "✓ Created consent ID: {$consentId}\n";
echo "  - user_id: NULL\n";
echo "  - session_id: {$oldSessionId}\n\n";

// Verify insertion
$stmt = $db->prepare('
    SELECT id, user_id, session_id, consent_timestamp, last_updated
    FROM user_cookie_consent
    WHERE id = :id
');
$stmt->execute(['id' => $consentId]);
$beforeAdoption = $stmt->fetch(PDO::FETCH_ASSOC);

echo "BEFORE ADOPTION:\n";
echo "  - id: {$beforeAdoption['id']}\n";
echo "  - user_id: " . ($beforeAdoption['user_id'] ?? 'NULL') . "\n";
echo "  - session_id: {$beforeAdoption['session_id']}\n";
echo "  - consent_timestamp: {$beforeAdoption['consent_timestamp']}\n";
echo "  - last_updated: {$beforeAdoption['last_updated']}\n\n";

// Wait 1 second to ensure timestamp difference
sleep(1);

// Step 2: Simulate adoption (what loginUser() does)
echo "STEP 2: Simulating adoption (user logs in)...\n";
$newSessionId = 'test_session_' . bin2hex(random_bytes(16));

$stmt = $db->prepare('
    UPDATE user_cookie_consent
    SET user_id = :user_id,
        session_id = :new_session_id,
        last_updated = NOW()
    WHERE session_id = :old_session_id
      AND user_id IS NULL
      AND is_active = 1
');

$stmt->execute([
    'user_id' => $testUserId,
    'new_session_id' => $newSessionId,
    'old_session_id' => $oldSessionId,
]);

$rowsUpdated = $stmt->rowCount();
echo "✓ Adoption query executed\n";
echo "  - Rows updated: {$rowsUpdated}\n\n";

// Step 3: Verify adoption
echo "STEP 3: Verifying adoption...\n";
$stmt = $db->prepare('
    SELECT id, user_id, session_id, consent_timestamp, last_updated,
           EXTRACT(EPOCH FROM (last_updated - consent_timestamp))::INTEGER as seconds_to_adoption
    FROM user_cookie_consent
    WHERE id = :id
');
$stmt->execute(['id' => $consentId]);
$afterAdoption = $stmt->fetch(PDO::FETCH_ASSOC);

echo "AFTER ADOPTION:\n";
echo "  - id: {$afterAdoption['id']}\n";
echo "  - user_id: " . ($afterAdoption['user_id'] ?? 'NULL') . "\n";
echo "  - session_id: {$afterAdoption['session_id']}\n";
echo "  - consent_timestamp: {$afterAdoption['consent_timestamp']}\n";
echo "  - last_updated: {$afterAdoption['last_updated']}\n";
echo "  - seconds_to_adoption: {$afterAdoption['seconds_to_adoption']}\n\n";

// Step 4: Validate results
echo "STEP 4: Validating results...\n";
$passed = true;

if ($rowsUpdated !== 1) {
    echo "❌ FAIL: Expected 1 row updated, got {$rowsUpdated}\n";
    $passed = false;
}

if ($afterAdoption['user_id'] != $testUserId) {
    echo "❌ FAIL: user_id not set (expected {$testUserId}, got " . ($afterAdoption['user_id'] ?? 'NULL') . ")\n";
    $passed = false;
}

if ($afterAdoption['session_id'] !== $newSessionId) {
    echo "❌ FAIL: session_id not updated (expected {$newSessionId}, got {$afterAdoption['session_id']})\n";
    $passed = false;
}

if ($afterAdoption['seconds_to_adoption'] <= 0) {
    echo "❌ FAIL: last_updated not updated (seconds_to_adoption: {$afterAdoption['seconds_to_adoption']})\n";
    $passed = false;
}

// Step 5: Cleanup test data
echo "\nSTEP 5: Cleaning up test data...\n";
$stmt = $db->prepare('DELETE FROM user_cookie_consent WHERE id = :id');
$stmt->execute(['id' => $consentId]);
echo "✓ Test consent record deleted\n\n";

// Final result
if ($passed) {
    echo "✅ ✅ ✅ ALL TESTS PASSED! ✅ ✅ ✅\n";
    echo "\nCONCLUSION:\n";
    echo "- Adoption query works correctly\n";
    echo "- user_id is properly set\n";
    echo "- session_id is properly updated\n";
    echo "- last_updated timestamp is properly updated\n";
    echo "- Column name fix (last_updated) is working!\n";
    exit(0);
} else {
    echo "❌ ❌ ❌ TESTS FAILED! ❌ ❌ ❌\n";
    exit(1);
}
