<?php

/**
 * ENTERPRISE GALAXY: Fix Newsletter Campaigns
 *
 * One-time script to fix campaigns stuck in 'sending' status
 * with missing plain_text_body and completion data
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\NewsletterCampaignManager;
use Need2Talk\Services\Logger;

$manager = new NewsletterCampaignManager();
$pdo = db_pdo();

echo "🔧 ENTERPRISE GALAXY: Newsletter Campaign Fixer\n";
echo "================================================\n\n";

// Find campaigns that are stuck
$stmt = $pdo->prepare("
    SELECT
        id,
        campaign_name,
        status,
        total_recipients,
        sent_count,
        failed_count,
        started_sending_at,
        plain_text_body IS NULL as missing_plaintext
    FROM newsletters
    WHERE (sent_count + failed_count >= total_recipients AND status = 'sending')
       OR plain_text_body IS NULL
    ORDER BY id DESC
");
$stmt->execute();
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($campaigns)) {
    echo "✅ No campaigns need fixing!\n";
    exit(0);
}

echo "Found " . count($campaigns) . " campaigns to fix:\n\n";

foreach ($campaigns as $campaign) {
    echo "📧 Campaign #{$campaign['id']}: {$campaign['campaign_name']}\n";
    echo "   Status: {$campaign['status']}\n";
    echo "   Progress: {$campaign['sent_count']}/{$campaign['total_recipients']} sent, {$campaign['failed_count']} failed\n";

    // Fix plain text if missing
    if ($campaign['missing_plaintext']) {
        echo "   🔄 Generating plain text...\n";
        $manager->ensurePlainTextExists($campaign['id']);
    }

    // Fix completion status if all emails processed
    $totalProcessed = $campaign['sent_count'] + $campaign['failed_count'];
    if ($totalProcessed >= $campaign['total_recipients'] && $campaign['status'] === 'sending') {
        echo "   🔄 Updating campaign to 'sent' status...\n";

        // Calculate processing time
        $startTime = strtotime($campaign['started_sending_at']);
        $endTime = time();
        $processingTimeMs = ($endTime - $startTime) * 1000;

        $stmt = $pdo->prepare("
            UPDATE newsletters
            SET status = 'sent',
                completed_sending_at = NOW(),
                processing_time_ms = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$processingTimeMs, $campaign['id']]);

        echo "   ✅ Campaign updated: status='sent', processing_time={$processingTimeMs}ms\n";

        Logger::email('info', 'NewsletterCampaignFixer: Fixed stuck campaign', [
            'campaign_id' => $campaign['id'],
            'sent_count' => $campaign['sent_count'],
            'failed_count' => $campaign['failed_count'],
            'processing_time_ms' => $processingTimeMs,
        ]);
    }

    echo "\n";
}

echo "================================================\n";
echo "✅ All campaigns fixed successfully!\n";
