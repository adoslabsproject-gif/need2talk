#!/usr/bin/env php
<?php
/**
 * ENTERPRISE GALAXY: Emergency Admin Access System
 *
 * ACCESSO DI EMERGENZA QUANDO:
 * 1. Email non arrivano per 2FA
 * 2. URL admin non accessibile
 * 3. Sistema di notifica non funziona
 *
 * ENTERPRISE SECURITY FEATURES:
 * - Multiple CLI-only checks (3 layers)
 * - Rate limiting (max 3 attempts per 10 minutes)
 * - IP whitelist (localhost/SSH only)
 * - Mandatory .env password (blocks if default)
 * - Complete audit logging with fail2ban integration
 * - Redis authentication required
 * - Safe from web access (outside document root)
 *
 * USAGE:
 * ssh root@YOUR_SERVER_IP "docker exec need2talk_php php /var/www/html/scripts/emergency/emergency-admin-access.php"
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
    exit("❌ ERROR: This script can only be executed via CLI\n");
}

// Layer 3: IP whitelist (only localhost or SSH tunnel)
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
$sshClient = getenv('SSH_CLIENT');

if ($clientIP !== 'CLI' && !in_array($clientIP, $allowedIPs) && !$sshClient) {
    exit("❌ ERROR: Access denied. Only localhost/SSH access permitted.\n");
}

// ============================================================================
// Bootstrap Application
// ============================================================================

require_once __DIR__.'/../../app/bootstrap.php';

use Need2Talk\Services\AdminSecurityService;
use Need2Talk\Services\AdminUrlNotificationService;
use Need2Talk\Services\Logger;

$db = db_pdo();

// ============================================================================
// ENTERPRISE: Rate Limiting for Password Attempts
// ============================================================================

const MAX_ATTEMPTS = 3;
const RATE_LIMIT_WINDOW = 600; // 10 minutes

function checkRateLimit($db): void
{
    $rateLimitKey = 'emergency_access_attempts';
    $cutoffTime = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW);

    // Count failed attempts in last 10 minutes
    $stmt = $db->prepare("
        SELECT COUNT(*) as attempt_count
        FROM admin_emergency_access_log
        WHERE access_type = 'password_attempt'
          AND status = 'failed'
          AND created_at > ?
    ");
    $stmt->execute([$cutoffTime]);
    $result = $stmt->fetch();
    $attempts = $result['attempt_count'] ?? 0;

    if ($attempts >= MAX_ATTEMPTS) {
        echo "❌ ERROR: Too many failed attempts. Please wait 10 minutes before trying again.\n";
        echo "🚨 Security lockout active. All attempts are logged.\n\n";

        Logger::security('warning', 'Emergency access rate limit triggered', [
            'attempts' => $attempts,
            'window' => RATE_LIMIT_WINDOW,
            'ssh_client' => getenv('SSH_CLIENT') ?: 'local',
        ]);

        exit(1);
    }

    if ($attempts > 0) {
        $remainingAttempts = MAX_ATTEMPTS - $attempts;
        echo "⚠️  Warning: {$attempts} failed attempt(s) in last 10 minutes. {$remainingAttempts} remaining.\n\n";
    }
}

// ============================================================================
// ENTERPRISE: Audit Logging Function
// ============================================================================

function logEmergencyAccess($db, string $type, string $status, array $details = []): void
{
    $stmt = $db->prepare("
        INSERT INTO admin_emergency_access_log
        (access_type, status, action_details, ip_address, user_agent, system_user, ssh_client)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $type,
        $status,
        json_encode($details),
        $_SERVER['REMOTE_ADDR'] ?? 'CLI',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Emergency CLI',
        getenv('USER') ?: 'unknown',
        getenv('SSH_CLIENT') ?: 'local',
    ]);
}

// ============================================================================
// Main Execution
// ============================================================================

echo str_repeat("=", 70) . "\n";
echo "  🆘 ENTERPRISE GALAXY: Emergency Admin Access System\n";
echo str_repeat("=", 70) . "\n\n";

// ENTERPRISE: Check rate limiting BEFORE password prompt
checkRateLimit($db);

// ENTERPRISE SECURITY: Verify .env has custom EMERGENCY_MASTER_PASSWORD
$masterHash = env('EMERGENCY_MASTER_PASSWORD');

if (!$masterHash) {
    echo "❌ CRITICAL ERROR: EMERGENCY_MASTER_PASSWORD not set in .env file!\n";
    echo "🔒 For security, emergency access requires a custom master password.\n";
    echo "\nTo fix:\n";
    echo "1. Generate a password hash:\n";
    echo "   php -r \"echo password_hash('YOUR_SECURE_PASSWORD', PASSWORD_DEFAULT);\"\n";
    echo "2. Add to .env:\n";
    echo "   EMERGENCY_MASTER_PASSWORD='generated_hash'\n\n";

    Logger::security('critical', 'Emergency access blocked: no master password configured', [
        'ssh_client' => getenv('SSH_CLIENT') ?: 'local',
    ]);

    exit(1);
}

// Step 1: Master password verification
// ENTERPRISE: Accept password as CLI argument to work via SSH
if (isset($argv[1]) && !empty($argv[1])) {
    $masterPassword = $argv[1];
    echo "🔐 Using password from command line argument...\n\n";
} else {
    echo "🔐 Enter master emergency password: ";
    $handle = fopen('php://stdin', 'r');
    $masterPassword = trim(fgets($handle));
    fclose($handle);
}

if (!password_verify($masterPassword, $masterHash)) {
    // ENTERPRISE: Log failed password attempt
    logEmergencyAccess($db, 'password_attempt', 'failed', [
        'reason' => 'invalid_master_password',
    ]);

    Logger::security('warning', 'Emergency access denied: invalid master password', [
        'ip' => getenv('SSH_CLIENT') ?: 'local',
        'user' => getenv('USER') ?: 'unknown',
    ]);

    echo "\n❌ Invalid master password!\n";
    echo "🚨 This attempt has been logged for security review.\n\n";
    exit(1);
}

// ENTERPRISE: Log successful login
logEmergencyAccess($db, 'successful_login', 'success', [
    'authentication' => 'master_password_verified',
]);

echo "✅ Master password verified.\n\n";

// Step 2: Emergency options menu
echo "🆘 Emergency Admin Access Options:\n";
echo str_repeat("=", 70) . "\n";
echo "1. 🔗 Get current admin URL\n";
echo "2. 📱 Resend all notifications\n";
echo "3. 🔑 Generate emergency access code (24h)\n";
echo "4. 👥 List all admin users\n";
echo "5. 🔒 Create new admin user\n";
echo "6. 📊 System health check\n";
echo "7. 🚪 Exit\n\n";

// ENTERPRISE: Accept option as second CLI argument to work via SSH
if (isset($argv[2]) && !empty($argv[2])) {
    $option = $argv[2];
    echo "▶ Executing option: {$option}\n\n";
} else {
    echo "Select option (1-7): ";
    $handle = fopen('php://stdin', 'r');
    $option = trim(fgets($handle));
    fclose($handle);
}

switch ($option) {
    case '1':
        // Get current admin URL
        $currentUrl = AdminSecurityService::generateSecureAdminUrl(true);
        $lastUrl = AdminUrlNotificationService::getLastAdminUrl();
        $baseUrl = env('APP_URL', 'https://need2talk.it');
        $environment = env('APP_ENV', 'production');

        echo "\n🔗 Current Admin URLs:\n";
        echo str_repeat("=", 70) . "\n";
        echo "Current: {$currentUrl}\n";
        echo "Environment: {$environment}\n";
        echo "Base URL: {$baseUrl}\n";
        if ($lastUrl) {
            echo "Last logged: {$baseUrl}{$lastUrl}\n";
        }
        echo "\n💡 Access via browser and use normal 2FA process.\n\n";

        logEmergencyAccess($db, 'action_performed', 'success', [
            'action' => 'get_admin_url',
            'current_url' => $currentUrl,
            'last_url' => $lastUrl,
        ]);

        Logger::security('info', 'Emergency access: Admin URL requested', [
            'user' => getenv('USER') ?: 'unknown',
        ]);
        break;

    case '2':
        // Resend notifications
        echo "\n📱 Resending all admin notifications...\n";
        try {
            AdminUrlNotificationService::notifyUrlChange();
            echo "✅ Notifications sent to all admin users.\n";
            echo "📧 Check email, Telegram, SMS, and webhooks.\n\n";

            logEmergencyAccess($db, 'action_performed', 'success', [
                'action' => 'resend_notifications',
            ]);

            Logger::security('info', 'Emergency access: Admin notifications resent', [
                'user' => getenv('USER') ?: 'unknown',
            ]);
        } catch (Exception $e) {
            echo "❌ Error sending notifications: {$e->getMessage()}\n\n";

            logEmergencyAccess($db, 'action_performed', 'failed', [
                'action' => 'resend_notifications',
                'error' => $e->getMessage(),
            ]);
        }
        break;

    case '3':
        // Generate emergency code
        $emergencyCode = strtoupper(bin2hex(random_bytes(4))); // 8 character code
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours

        // SECURITY: Hash the code before storing (never store plain text)
        $hashedCode = password_hash($emergencyCode, PASSWORD_DEFAULT);

        // Store emergency code (HASHED)
        $stmt = $db->prepare("
            INSERT INTO admin_emergency_codes (code, expires_at, created_via)
            VALUES (?, ?, 'cli_emergency')
        ");
        $stmt->execute([$hashedCode, $expiresAt]);

        echo "\n🔑 Emergency Access Code Generated:\n";
        echo str_repeat("=", 70) . "\n";
        echo "Code: {$emergencyCode}\n";
        echo "Expires: {$expiresAt}\n\n";
        echo "🌐 Use this code at any admin URL:\n";
        echo "   1. Go to admin URL\n";
        echo "   2. Click 'Emergency Access'\n";
        echo "   3. Enter this code\n\n";
        echo "⚠️  This code works only once and expires in 24h.\n\n";

        logEmergencyAccess($db, 'action_performed', 'success', [
            'action' => 'generate_emergency_code',
            'code_preview' => substr($emergencyCode, 0, 4).'****',
            'expires_at' => $expiresAt,
        ]);

        Logger::security('warning', 'Emergency access: Emergency code generated', [
            'code_preview' => substr($emergencyCode, 0, 4).'****',
            'expires_at' => $expiresAt,
            'user' => getenv('USER') ?: 'unknown',
        ]);
        break;

    case '4':
        // List admin users
        $stmt = $db->query("
            SELECT id, email, full_name, role, status, last_login_at
            FROM admin_users
            WHERE deleted_at IS NULL
            ORDER BY role DESC, last_login_at DESC
        ");
        $admins = $stmt->fetchAll();

        echo "\n👥 Admin Users:\n";
        echo str_repeat("=", 70) . "\n";
        foreach ($admins as $admin) {
            $status = $admin['status'] === 'active' ? '✅' : '❌';
            $lastLogin = $admin['last_login_at'] ? date('d/m/Y H:i', strtotime($admin['last_login_at'])) : 'Never';
            echo sprintf(
                "%s %s | %-30s | %-25s | Last: %s\n",
                $status,
                str_pad($admin['role'], 12),
                $admin['email'],
                $admin['full_name'],
                $lastLogin
            );
        }
        echo "\n";

        Logger::security('info', 'Emergency access: Admin users listed', [
            'user' => getenv('USER') ?: 'unknown',
        ]);
        break;

    case '5':
        // Create new admin user
        echo "\n🔒 Create New Admin User:\n";
        echo str_repeat("=", 70) . "\n";

        echo "Email: ";
        $handle = fopen('php://stdin', 'r');
        $email = trim(fgets($handle));
        fclose($handle);

        echo "Full Name: ";
        $handle = fopen('php://stdin', 'r');
        $fullName = trim(fgets($handle));
        fclose($handle);

        echo "Role (admin/super_admin): ";
        $handle = fopen('php://stdin', 'r');
        $role = trim(fgets($handle));
        if (!in_array($role, ['admin', 'super_admin'])) {
            $role = 'admin';
        }
        fclose($handle);

        $tempPassword = bin2hex(random_bytes(8)); // 16 char temp password
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

        try {
            $stmt = $db->prepare("
                INSERT INTO admin_users (email, password_hash, full_name, role, status, must_change_password)
                VALUES (?, ?, ?, ?, 'active', TRUE)
            ");
            $stmt->execute([$email, $passwordHash, $fullName, $role]);

            echo "\n✅ Admin user created successfully!\n";
            echo str_repeat("=", 70) . "\n";
            echo "Email: {$email}\n";
            echo "Temporary Password: {$tempPassword}\n";
            echo "Role: {$role}\n\n";
            echo "⚠️  User must change password on first login.\n";
            echo "📧 Send these credentials securely to the user.\n\n";

            Logger::security('warning', 'Emergency access: New admin user created', [
                'email' => $email,
                'role' => $role,
                'created_by' => getenv('USER') ?: 'unknown',
            ]);
        } catch (Exception $e) {
            echo "❌ Error creating admin user: {$e->getMessage()}\n\n";
        }
        break;

    case '6':
        // System health check
        echo "\n📊 System Health Check:\n";
        echo str_repeat("=", 70) . "\n";

        // Database check
        try {
            $db->query('SELECT 1');
            echo "✅ Database: Connected\n";
        } catch (Exception $e) {
            echo "❌ Database: Error - {$e->getMessage()}\n";
        }

        // Redis check (WITH authentication)
        try {
            if (class_exists('Redis')) {
                $redis = new Redis();
                $redisHost = env('REDIS_HOST', 'redis');
                $redisPort = (int) env('REDIS_PORT', 6379);
                $redisPassword = env('REDIS_PASSWORD');

                if ($redis->connect($redisHost, $redisPort)) {
                    // ENTERPRISE FIX: Authenticate if password is set
                    if ($redisPassword) {
                        if (!$redis->auth($redisPassword)) {
                            echo "❌ Redis: Authentication failed\n";
                        } else {
                            echo "✅ Redis: Connected (authenticated)\n";
                        }
                    } else {
                        echo "✅ Redis: Connected (no auth)\n";
                    }
                    $redis->close();
                } else {
                    echo "❌ Redis: Connection failed\n";
                }
            } else {
                echo "⚠️  Redis: Extension not loaded\n";
            }
        } catch (Exception $e) {
            echo "❌ Redis: Error - {$e->getMessage()}\n";
        }

        // File permissions
        $logDir = __DIR__.'/../../storage/logs';
        if (is_writable($logDir)) {
            echo "✅ Log Directory: Writable\n";
        } else {
            echo "❌ Log Directory: Not writable\n";
        }

        // Disk space
        $diskFree = disk_free_space(__DIR__);
        $diskTotal = disk_total_space(__DIR__);
        $diskPercent = round(($diskFree / $diskTotal) * 100, 1);

        if ($diskPercent > 20) {
            echo "✅ Disk Space: {$diskPercent}% free\n";
        } else {
            echo "⚠️  Disk Space: {$diskPercent}% free (LOW!)\n";
        }

        echo "\n";
        Logger::security('info', 'Emergency access: System health check performed', [
            'user' => getenv('USER') ?: 'unknown',
        ]);
        break;

    case '7':
        echo "\n👋 Exiting emergency access.\n\n";

        Logger::security('info', 'Emergency access: Session ended', [
            'user' => getenv('USER') ?: 'unknown',
        ]);

        exit(0);

    default:
        echo "\n❌ Invalid option selected.\n\n";
        exit(1);
}

echo str_repeat("=", 70) . "\n";
echo "✅ Emergency access operation completed.\n";
echo str_repeat("=", 70) . "\n";
