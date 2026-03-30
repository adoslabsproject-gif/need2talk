<?php

/**
 * Admin URL Notifier - need2talk
 *
 * Script da eseguire automaticamente:
 * 1. All'avvio del server
 * 2. Dopo riavvio applicazione
 * 3. Manualmente quando necessario
 */

// Security check - only CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit('<h1>404 - Page Not Found</h1>');
}

require_once __DIR__.'/app/bootstrap.php';

use Need2Talk\Services\AdminUrlNotificationService;
use Need2Talk\Services\AdminSecurityService;

echo "===========================================\n";
echo "  need2talk Admin URL Notifier\n";
echo "===========================================\n\n";

try {
    // Generate new URL and notify all admins
    echo "🔐 Generating new secure admin URL...\n";

    AdminUrlNotificationService::notifyUrlChange();

    // Generate full URL using APP_URL from environment
    $currentUrl = AdminSecurityService::generateSecureAdminUrl(true);
    $environment = $_ENV['APP_ENV'] ?? 'development';
    $baseUrl = $_ENV['APP_URL'] ?? 'https://need2talk.test';

    echo "✅ New admin URL generated and notified!\n\n";
    echo "📧 Notifications sent to:\n";
    echo "   - All admin emails\n";
    echo "   - Telegram (if configured)\n";
    echo "   - SMS to super admins (if configured)\n";
    echo "   - Slack/Discord webhooks (if configured)\n\n";

    echo "🔗 Current URL: {$currentUrl}\n";
    echo "🌍 Environment: {$environment}\n";
    echo "🌐 Base URL: {$baseUrl}\n";
    echo "⏰ Expires in: 30 minutes\n\n";

    echo "📄 URL also logged to: storage/logs/admin-urls.log\n\n";

    echo "⚙️  Configuration needed for full notifications:\n";
    echo "   - TELEGRAM_BOT_TOKEN (Telegram notifications)\n";
    echo "   - TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM (SMS)\n";
    echo "   - SLACK_ADMIN_WEBHOOK (Slack notifications)\n";
    echo "   - DISCORD_ADMIN_WEBHOOK (Discord notifications)\n\n";

} catch (Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    exit(1);
}

echo "===========================================\n";
echo "Admin URL notification completed!\n";
echo "===========================================\n";
