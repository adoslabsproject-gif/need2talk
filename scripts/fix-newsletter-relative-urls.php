<?php

/**
 * ENTERPRISE GALAXY: Fix Newsletter Relative URLs
 *
 * Converts relative image URLs to absolute URLs in existing newsletters
 * Fixes: ../assets/uploads/newsletter/image.jpg
 * To: https://need2talk.it/assets/uploads/newsletter/image.jpg
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\Logger;

echo "🔧 ENTERPRISE GALAXY: Newsletter URL Fixer\n";
echo "============================================\n\n";

$pdo = db_pdo();

// Find newsletters with relative URLs in images
$stmt = $pdo->prepare("
    SELECT id, campaign_name, html_body
    FROM newsletters
    WHERE html_body LIKE '%<img%src=\"../%'
       OR html_body LIKE '%<img%src=''../%'
       OR html_body LIKE '%<img%src=/%'
    ORDER BY id DESC
");
$stmt->execute();
$newsletters = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($newsletters)) {
    echo "✅ No newsletters with relative URLs found!\n";
    echo "All newsletters already use absolute URLs.\n";
    exit(0);
}

echo "Found " . count($newsletters) . " newsletters with relative URLs:\n\n";

$fixedCount = 0;

foreach ($newsletters as $newsletter) {
    echo "📧 Campaign #{$newsletter['id']}: {$newsletter['campaign_name']}\n";

    $originalHtml = $newsletter['html_body'];
    $fixedHtml = $originalHtml;

    // ENTERPRISE FIX 1: Convert ../assets/... to absolute URL
    $fixedHtml = preg_replace(
        '/(<img[^>]+src=["\'])\.\.\/assets\/uploads\/newsletter\/([^"\']+)(["\'])/i',
        '$1https://need2talk.it/assets/uploads/newsletter/$2$3',
        $fixedHtml
    );

    // ENTERPRISE FIX 2: Convert /assets/... (root-relative) to absolute URL
    $fixedHtml = preg_replace(
        '/(<img[^>]+src=["\'])\/assets\/uploads\/newsletter\/([^"\']+)(["\'])/i',
        '$1https://need2talk.it/assets/uploads/newsletter/$2$3',
        $fixedHtml
    );

    // ENTERPRISE FIX 3: Convert assets/... (relative without ../) to absolute URL
    $fixedHtml = preg_replace(
        '/(<img[^>]+src=["\'])assets\/uploads\/newsletter\/([^"\']+)(["\'])/i',
        '$1https://need2talk.it/assets/uploads/newsletter/$2$3',
        $fixedHtml
    );

    // Check if anything changed
    if ($fixedHtml !== $originalHtml) {
        // Count how many URLs were fixed
        preg_match_all('/<img[^>]+>/i', $originalHtml, $originalMatches);
        preg_match_all('/<img[^>]+>/i', $fixedHtml, $fixedMatches);

        $imageCount = count($originalMatches[0]);

        // Update database
        $stmt = $pdo->prepare("
            UPDATE newsletters
            SET html_body = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$fixedHtml, $newsletter['id']]);

        echo "   ✅ Fixed {$imageCount} image(s)\n";

        // Show before/after for first image
        if (preg_match('/<img[^>]+>/i', $originalHtml, $origImg)) {
            echo "   📷 Example transformation:\n";
            echo "      Before: " . substr($origImg[0], 0, 100) . "...\n";
        }
        if (preg_match('/<img[^>]+>/i', $fixedHtml, $newImg)) {
            echo "      After:  " . substr($newImg[0], 0, 100) . "...\n";
        }

        Logger::email('info', 'Fixed newsletter relative URLs', [
            'campaign_id' => $newsletter['id'],
            'campaign_name' => $newsletter['campaign_name'],
            'images_fixed' => $imageCount,
        ]);

        $fixedCount++;
    } else {
        echo "   ℹ️  No changes needed (already absolute)\n";
    }

    echo "\n";
}

echo "============================================\n";
echo "✅ Fixed {$fixedCount} newsletter(s)!\n\n";

echo "📋 NEXT STEPS:\n";
echo "1. Create a NEW newsletter with an image (TinyMCE now uses absolute URLs)\n";
echo "2. Send test email to yourself\n";
echo "3. Open in email client - images should now display!\n\n";

echo "⚠️  NOTE: Email clients (Gmail, Outlook) may still block remote images\n";
echo "   by default for security. Users need to click 'Display images' button.\n";
echo "   This is NORMAL and expected behavior.\n";
