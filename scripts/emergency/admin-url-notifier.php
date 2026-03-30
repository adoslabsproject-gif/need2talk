#!/usr/bin/env php
<?php
/**
 * ENTERPRISE GALAXY: Admin URL Notifier
 *
 * SECURITY FEATURES:
 * - Multiple CLI-only checks
 * - IP whitelist (localhost/SSH only)
 * - Complete audit logging
 * - Safe from web access (outside document root)
 *
 * USAGE:
 * ssh root@YOUR_SERVER_IP "docker exec need2talk_php php /var/www/html/scripts/emergency/admin-url-notifier.php"
 *
 * @author Claude Code (Enterprise Galaxy Initiative)
 * @since 2025-10-27
 */

// ============================================================================
// ENTERPRISE SECURITY: Multi-Layer CLI Protection
// ============================================================================

// Layer 1: PHP SAPI check
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit('<h1>404 - Page Not Found</h1>');
}

// Layer 2: Verify not accessed via web (check for HTTP-specific vars)
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI']) || isset($_SERVER['HTTP_USER_AGENT'])) {
    exit("ERROR: This script can only be executed via CLI\n");
}

// Layer 3: IP whitelist (only localhost or SSH tunnel)
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
$sshClient = getenv('SSH_CLIENT');

if ($clientIP !== 'CLI' && !in_array($clientIP, $allowedIPs) && !$sshClient) {
    exit("ERROR: Access denied. Only localhost/SSH access permitted.\n");
}

// ============================================================================
// Bootstrap Application
// ============================================================================

require_once __DIR__.'/../../app/bootstrap.php';

use Need2Talk\Services\AdminUrlNotificationService;
use Need2Talk\Services\AdminSecurityService;
use Need2Talk\Services\Logger;

echo str_repeat("=", 70) . "\n";
echo "  🔐 ENTERPRISE GALAXY: Admin URL Notifier\n";
echo str_repeat("=", 70) . "\n\n";

// ============================================================================
// ENTERPRISE: Collect Executor Information
// ============================================================================

/**
 * Gather comprehensive information about who executed this script
 *
 * NOTE: When running via `docker exec`, SSH environment variables are NOT passed.
 * To capture real SSH client info, use:
 *   SSH_CLIENT="$SSH_CLIENT" SSH_CONNECTION="$SSH_CONNECTION" docker exec -e SSH_CLIENT -e SSH_CONNECTION need2talk_php php /var/www/html/scripts/emergency/admin-url-notifier.php
 *
 * Or pass explicitly:
 *   docker exec -e EXECUTOR_IP="$(echo $SSH_CLIENT | cut -d' ' -f1)" -e EXECUTOR_USER="$USER" need2talk_php php /var/www/html/scripts/emergency/admin-url-notifier.php
 */
function collectExecutorInfo(): array
{
    // SSH_CLIENT format: "client_ip client_port server_port"
    // Check both native env vars and explicitly passed ones
    $sshClient = getenv('SSH_CLIENT');
    $sshConnection = getenv('SSH_CONNECTION'); // "client_ip client_port server_ip server_port"

    // ENTERPRISE: Check for explicitly passed executor info (from docker exec -e)
    $explicitIp = getenv('EXECUTOR_IP');
    $explicitUser = getenv('EXECUTOR_USER');
    $explicitHostUser = getenv('EXECUTOR_HOST_USER'); // SSH user on host

    // Parse SSH client IP
    $clientIp = null;
    if ($explicitIp) {
        $clientIp = $explicitIp; // Explicitly passed takes priority
    } elseif ($sshClient) {
        $parts = explode(' ', $sshClient);
        $clientIp = $parts[0] ?? null;
    } elseif ($sshConnection) {
        $parts = explode(' ', $sshConnection);
        $clientIp = $parts[0] ?? null;
    }

    // Get hostname of client IP (reverse DNS)
    $clientHostname = null;
    if ($clientIp) {
        $clientHostname = @gethostbyaddr($clientIp);
        if ($clientHostname === $clientIp) {
            $clientHostname = null; // No reverse DNS available
        }
    }

    // Get server info
    $serverHostname = gethostname() ?: 'unknown';
    $serverIp = getenv('SSH_CONNECTION') ? explode(' ', getenv('SSH_CONNECTION'))[2] ?? null : null;

    // Get system user executing the script
    // Priority: explicitly passed > env vars > posix
    $systemUser = $explicitUser ?: getenv('USER') ?: getenv('LOGNAME') ?: posix_getpwuid(posix_geteuid())['name'] ?? 'unknown';

    // Get sudo info if available
    $sudoUser = getenv('SUDO_USER');
    $sudoUid = getenv('SUDO_UID');

    // Get host SSH user if passed
    $hostUser = $explicitHostUser ?: $sudoUser;

    // Get TTY info
    $tty = getenv('SSH_TTY') ?: (posix_ttyname(STDOUT) ?: 'no-tty');

    // Get Docker container info (if running in Docker)
    $dockerContainer = null;
    if (file_exists('/.dockerenv')) {
        $dockerContainer = getenv('HOSTNAME') ?: 'docker-container';
    }

    // Get process info
    $pid = getmypid();
    $ppid = posix_getppid();

    // Get parent process name (who called this script)
    $parentProcess = null;
    if ($ppid) {
        $parentCmdline = @file_get_contents("/proc/{$ppid}/cmdline");
        if ($parentCmdline) {
            $parentProcess = str_replace("\0", ' ', trim($parentCmdline));
            $parentProcess = substr($parentProcess, 0, 200); // Limit length
        }
    }

    return [
        'client_ip' => $clientIp ?: 'localhost',
        'client_hostname' => $clientHostname,
        'client_port' => $sshClient ? (explode(' ', $sshClient)[1] ?? null) : null,
        'server_hostname' => $serverHostname,
        'server_ip' => $serverIp,
        'system_user' => $systemUser,
        'sudo_user' => $sudoUser,
        'sudo_uid' => $sudoUid,
        'host_user' => $hostUser,
        'effective_user' => $hostUser ?: $sudoUser ?: $systemUser,
        'tty' => $tty,
        'docker_container' => $dockerContainer,
        'pid' => $pid,
        'ppid' => $ppid,
        'parent_process' => $parentProcess,
        'ssh_client_raw' => $sshClient ?: null,
        'ssh_connection_raw' => $sshConnection ?: null,
        'explicit_ip' => $explicitIp,
        'explicit_user' => $explicitUser,
        'timestamp' => date('c'),
        'timestamp_unix' => time(),
        'timezone' => date_default_timezone_get(),
    ];
}

$executorInfo = collectExecutorInfo();

echo "📋 Executor Information:\n";
echo "   └─ IP: {$executorInfo['client_ip']}" . ($executorInfo['client_hostname'] ? " ({$executorInfo['client_hostname']})" : "") . "\n";
echo "   └─ User: {$executorInfo['effective_user']}" . ($executorInfo['sudo_user'] ? " (via sudo from {$executorInfo['system_user']})" : "") . "\n";
echo "   └─ Server: {$executorInfo['server_hostname']}\n";
if ($executorInfo['docker_container']) {
    echo "   └─ Container: {$executorInfo['docker_container']}\n";
}
echo "\n";

try {
    // ENTERPRISE: Log script execution with comprehensive info
    Logger::security('warning', 'ADMIN_URL_NOTIFIER_EXECUTED', [
        'executor' => $executorInfo,
        'action' => 'generate_new_admin_url',
        'script' => __FILE__,
    ]);

    // Generate new URL and notify all admins (passing executor info)
    echo "🔐 Generating new secure admin URL...\n";

    AdminUrlNotificationService::notifyUrlChange($executorInfo);

    // Generate full URL using APP_URL from environment
    $currentUrl = AdminSecurityService::generateSecureAdminUrl(true);
    $environment = env('APP_ENV', 'production');
    $baseUrl = env('APP_URL', 'https://need2talk.it');

    echo "✅ New admin URL generated and notified!\n\n";
    echo "📧 Notifications sent to:\n";
    echo "   - All admin emails\n";
    echo "   - Telegram (if configured)\n";
    echo "   - SMS to super admins (if configured)\n";
    echo "   - Slack/Discord webhooks (if configured)\n\n";

    echo "🔗 Current URL: {$currentUrl}\n";
    echo "🌍 Environment: {$environment}\n";
    echo "🌐 Base URL: {$baseUrl}\n";
    echo "⏰ URL expires in: 30 minutes\n\n";

    echo "📄 URL also logged to: storage/logs/admin-urls.log\n\n";

    if ($environment !== 'production') {
        echo "⚙️  Configuration needed for full notifications:\n";
        echo "   - TELEGRAM_BOT_TOKEN (Telegram notifications)\n";
        echo "   - TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM (SMS)\n";
        echo "   - SLACK_ADMIN_WEBHOOK (Slack notifications)\n";
        echo "   - DISCORD_ADMIN_WEBHOOK (Discord notifications)\n\n";
    }

    echo str_repeat("=", 70) . "\n";
    echo "✅ Admin URL notification completed!\n";
    echo str_repeat("=", 70) . "\n";

    exit(0);

} catch (Exception $e) {
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n";

    Logger::security('error', 'Admin URL notifier failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
}
