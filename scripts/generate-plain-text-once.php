<?php

/**
 * ENTERPRISE: One-time script to generate plain_text_body for all campaigns
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\NewsletterCampaignManager;

echo "🔧 Generating plain text for all campaigns...\n";

$manager = new NewsletterCampaignManager();
$pdo = db_pdo();

$stmt = $pdo->query("SELECT id, campaign_name FROM newsletters WHERE plain_text_body IS NULL OR plain_text_body = ''");
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($campaigns) . " campaigns without plain text\n\n";

foreach ($campaigns as $campaign) {
    echo "Processing Campaign #{$campaign['id']}: {$campaign['campaign_name']}...";
    $result = $manager->ensurePlainTextExists($campaign['id']);
    echo ($result ? " ✅ Done\n" : " ❌ Failed\n");
}

// Also fix campaign 4 status
echo "\nFixing campaign #4 status...\n";
$pdo->exec("UPDATE newsletters SET status = 'sent', completed_sending_at = NOW(), processing_time_ms = 120000 WHERE id = 4 AND status = 'sending'");

echo "\n✅ All done!\n";
