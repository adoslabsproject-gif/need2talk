<?php

/**
 * ENTERPRISE: Diagnose Newsletter Image Display Issues
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

echo "🔍 ENTERPRISE GALAXY: Newsletter Image Diagnostics\n";
echo "================================================\n\n";

$pdo = db_pdo();

// Get latest newsletter with images
$stmt = $pdo->prepare("
    SELECT id, campaign_name, html_body, subject
    FROM newsletters
    WHERE html_body LIKE '%<img%'
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute();
$newsletter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$newsletter) {
    echo "❌ No newsletters with images found\n";
    exit(1);
}

echo "📧 Campaign #{$newsletter['id']}: {$newsletter['campaign_name']}\n";
echo "Subject: {$newsletter['subject']}\n\n";

// Extract all img tags
preg_match_all('/<img[^>]+>/i', $newsletter['html_body'], $matches);

if (empty($matches[0])) {
    echo "❌ No <img> tags found in HTML body\n";
    exit(1);
}

echo "Found " . count($matches[0]) . " image(s):\n\n";

foreach ($matches[0] as $index => $imgTag) {
    echo "Image #" . ($index + 1) . ":\n";
    echo "  Full tag: " . $imgTag . "\n";

    // Extract src attribute
    if (preg_match('/src=["\']([^"\']+)["\']/', $imgTag, $srcMatch)) {
        $src = $srcMatch[1];
        echo "  Source: " . $src . "\n";

        // Check if absolute URL
        if (strpos($src, 'http://') === 0 || strpos($src, 'https://') === 0) {
            echo "  ✅ Absolute URL\n";

            // Test accessibility
            $headers = @get_headers($src, 1);
            if ($headers && strpos($headers[0], '200') !== false) {
                echo "  ✅ Accessible (HTTP 200)\n";
            } else {
                echo "  ❌ Not accessible or error\n";
            }
        } else {
            echo "  ❌ RELATIVE URL - THIS IS THE PROBLEM!\n";
            echo "  🔧 Should be: https://need2talk.it" . $src . "\n";
        }
    } else {
        echo "  ❌ No src attribute found\n";
    }

    // Check for alt text
    if (preg_match('/alt=["\']([^"\']*)["\']/', $imgTag, $altMatch)) {
        echo "  Alt text: " . ($altMatch[1] ?: '(empty)') . "\n";
    } else {
        echo "  ⚠️  No alt attribute\n";
    }

    // Check dimensions
    if (preg_match('/width=["\']?(\d+)["\']?/', $imgTag, $widthMatch)) {
        echo "  Width: " . $widthMatch[1] . "px\n";
    }
    if (preg_match('/height=["\']?(\d+)["\']?/', $imgTag, $heightMatch)) {
        echo "  Height: " . $heightMatch[1] . "px\n";
    }

    echo "\n";
}

echo "================================================\n";
echo "Common Email Client Image Issues:\n\n";

echo "1. 🛡️  Remote Image Blocking (MOST COMMON)\n";
echo "   - Gmail, Outlook, Yahoo block external images by default\n";
echo "   - Users must click 'Display images' button\n";
echo "   - This is NORMAL security behavior\n\n";

echo "2. 🔗 Relative URLs\n";
echo "   - Images must use absolute URLs (https://...)\n";
echo "   - Checked above - look for ❌ marks\n\n";

echo "3. 📝 Missing Alt Text\n";
echo "   - Alt text shows when images are blocked\n";
echo "   - Improves accessibility\n\n";

echo "4. 🔒 Mixed Content\n";
echo "   - All images should use HTTPS (not HTTP)\n";
echo "   - Modern email clients block HTTP images\n\n";

echo "\n✅ Diagnostics complete!\n";
