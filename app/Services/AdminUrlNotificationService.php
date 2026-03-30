<?php

namespace Need2Talk\Services;

/**
 * Admin URL Notification Service - Enterprise
 *
 * SISTEMA NOTIFICA AUTOMATICA URL ADMIN:
 * 1. Notifica via email quando URL cambia
 * 2. Notifica via Telegram/Slack/Discord
 * 3. Log file con timestamp
 * 4. SMS backup (Twilio integration)
 */
class AdminUrlNotificationService
{
    private $db;

    private $emailService;

    public function __construct()
    {
        $this->db = db_pdo();
        $this->emailService = new EmailService();
    }

    /**
     * Notifica nuovo URL admin a tutti gli admin
     *
     * @param array|null $executorInfo Information about who triggered the notification
     */
    public static function notifyUrlChange(?array $executorInfo = null): void
    {
        $service = new self();
        $newUrl = AdminSecurityService::generateSecureAdminUrl();

        // Log the change with executor info
        $service->logUrlChange($newUrl, $executorInfo);
        $urlHash = substr($newUrl, 7); // Remove /admin_ prefix

        // Get all active admin users
        $admins = $service->getActiveAdmins();

        foreach ($admins as $admin) {
            // Email notification (with executor info)
            $service->sendEmailNotification($admin, $newUrl, $executorInfo);

            // SMS backup if critical admin
            if ($admin['role'] === 'super_admin' && $admin['phone']) {
                $service->sendSmsNotification($admin['phone'], $newUrl);
            }
        }

        // ENTERPRISE: Send Telegram notification to admin (uses TELEGRAM_ADMIN_CHAT_ID from .env)
        if (TelegramNotificationService::isConfigured()) {
            TelegramNotificationService::sendAdminUrlNotification($newUrl, $executorInfo);
        }

        // Webhook notifications (Slack/Discord) - if configured
        $service->sendWebhookNotifications($newUrl, $executorInfo);

        // Mark notifications as sent
        $service->markNotificationSent($urlHash);

        // ENTERPRISE: Log successful notification with full details
        Logger::security('warning', 'ADMIN_URL_NOTIFICATION_SENT', [
            'url_hash' => $urlHash,
            'admins_notified' => count($admins),
            'executor' => $executorInfo,
            'channels' => [
                'email' => count($admins),
                'telegram' => count(array_filter($admins, fn($a) => !empty($a['telegram_chat_id']))),
                'sms' => count(array_filter($admins, fn($a) => $a['role'] === 'super_admin' && !empty($a['phone']))),
            ],
        ]);
    }

    /**
     * ENTERPRISE: Send emergency access notification to all admins
     */
    public function sendEmergencyAccessNotification(string $emergencyCode, array $accessDetails): void
    {
        // Get all active admin users
        $admins = $this->getActiveAdmins();

        foreach ($admins as $admin) {
            // Send email notification
            $this->sendEmergencyEmailNotification($admin, $emergencyCode, $accessDetails);

            // Telegram notification if configured
            if ($admin['telegram_chat_id']) {
                $this->sendEmergencyTelegramNotification($admin['telegram_chat_id'], $emergencyCode, $accessDetails);
            }
        }
    }

    /**
     * Get ultimo URL per accesso di emergenza
     */
    public static function getLastAdminUrl(): ?string
    {
        $logFile = APP_ROOT . '/storage/logs/admin-urls.log';

        if (!file_exists($logFile)) {
            return null;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $lastLine = end($lines);

        if (preg_match('/https:\/\/need2talk\.com(\/admin_[a-f0-9]{16})/', $lastLine, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Ottieni tutti gli admin attivi
     */
    private function getActiveAdmins(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                id, email, full_name, phone, role,
                telegram_chat_id, discord_user_id
            FROM admin_users
            WHERE status = 'active'
            AND deleted_at IS NULL
            ORDER BY role DESC
        ");

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Log URL change per audit - Enterprise Edition
     *
     * @param string $newUrl The new admin URL
     * @param array|null $executorInfo Information about who triggered the change
     */
    private function logUrlChange(string $newUrl, ?array $executorInfo = null): void
    {
        $urlHash = substr($newUrl, 7); // Remove /admin_ prefix
        $timeWindow = floor(time() / 3600) * 3600; // Current time window

        // ENTERPRISE FIX: Use 8-hour timeout (28800s) instead of hardcoded 1-hour (3600s)
        // Previously expired after 1 hour, now correctly expires after 8 hours as designed
        // Matches AdminSecurityService::ADMIN_URL_TIMEOUT constant
        $expiresAt = date('Y-m-d H:i:s', $timeWindow + 28800);

        // 12 FACTOR APP: Use environment config instead of hardcoded IP
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');
        $environment = $_ENV['APP_ENV'] ?? 'development';

        // Extract executor details for logging
        $executorIp = $executorInfo['client_ip'] ?? 'unknown';
        $executorUser = $executorInfo['effective_user'] ?? 'unknown';
        $executorHostname = $executorInfo['client_hostname'] ?? null;
        $executorContainer = $executorInfo['docker_container'] ?? null;

        // Enterprise database log with time window tracking
        $stmt = $this->db->prepare("
            INSERT INTO admin_url_changes (
                url_hash, full_url, time_window, expires_at,
                domain, environment, notification_status
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");

        $stmt->execute([$urlHash, $newUrl, $timeWindow, $expiresAt, $domain, $environment]);

        // ENTERPRISE: Build detailed log entry with executor info
        $executorDetails = "IP: {$executorIp}";
        if ($executorHostname) {
            $executorDetails .= " ({$executorHostname})";
        }
        $executorDetails .= ", User: {$executorUser}";
        if ($executorContainer) {
            $executorDetails .= ", Container: {$executorContainer}";
        }

        // File log for external access
        $logFile = APP_ROOT . '/storage/logs/admin-urls.log';
        $logEntry = sprintf(
            "[%s] [%s] New Admin URL: https://%s%s (expires: %s, window: %s) | Executor: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($environment),
            $domain,
            $newUrl,
            $expiresAt,
            $timeWindow,
            $executorDetails
        );
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Invia notifica email
     *
     * @param array $admin Admin user data
     * @param string $newUrl The new admin URL
     * @param array|null $executorInfo Information about who triggered the notification
     */
    private function sendEmailNotification(array $admin, string $newUrl, ?array $executorInfo = null): void
    {
        // 12 FACTOR APP: Use environment config instead of hardcoded IP
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');
        $environment = $_ENV['APP_ENV'] ?? 'development';
        $envLabel = $environment === 'production' ? '' : ' [' . strtoupper($environment) . ']';

        $subject = '🔐 need2talk Admin' . $envLabel . ' - Nuovo URL Sicuro';

        // Extract executor details for email
        $executorIp = $executorInfo['client_ip'] ?? 'N/A';
        $executorHostname = $executorInfo['client_hostname'] ?? null;
        $executorUser = $executorInfo['effective_user'] ?? 'N/A';
        $executorServer = $executorInfo['server_hostname'] ?? 'N/A';
        $executorContainer = $executorInfo['docker_container'] ?? null;
        $executorTimestamp = $executorInfo['timestamp'] ?? date('c');

        // Build executor display string
        $executorIpDisplay = $executorIp;
        if ($executorHostname) {
            $executorIpDisplay .= " ({$executorHostname})";
        }

        $message = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #8B5CF6, #3B82F6); padding: 30px; text-align: center; color: white; border-radius: 12px 12px 0 0;'>
                    <h1 style='margin: 0; font-size: 28px;'>🔐 need2talk Admin</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Nuovo URL di Accesso Generato</p>
                </div>

                <div style='background: #ffffff; padding: 40px; border-radius: 0 0 12px 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
                    <p style='font-size: 16px; color: #374151; margin-bottom: 20px;'>
                        Ciao <strong>{$admin['full_name']}</strong>,
                    </p>

                    <p style='color: #6B7280; margin-bottom: 25px;'>
                        Il sistema ha generato un nuovo URL sicuro per l'accesso admin.
                        Questo URL è valido fino al prossimo riavvio del sistema.
                    </p>

                    <div style='background: #F8FAFC; border: 2px solid #E5E7EB; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                        <h3 style='color: #374151; margin: 0 0 15px 0; font-size: 18px;'>🔗 Nuovo URL Admin:</h3>
                        <div style='background: #FFFFFF; border: 1px solid #D1D5DB; border-radius: 6px; padding: 15px; font-family: monospace; word-break: break-all;'>
                            <strong style='color: #8B5CF6; font-size: 16px;'>https://{$domain}{$newUrl}</strong>
                        </div>
                    </div>

                    <!-- ENTERPRISE: Executor Information Section -->
                    <div style='background: #EEF2FF; border: 2px solid #C7D2FE; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                        <h3 style='color: #4338CA; margin: 0 0 15px 0; font-size: 18px;'>👤 Chi ha richiesto questo URL:</h3>
                        <table style='width: 100%; font-size: 14px; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px 0; color: #6366F1; width: 35%;'><strong>🌐 IP Address:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937; font-family: monospace;'>{$executorIpDisplay}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #6366F1;'><strong>👤 System User:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937; font-family: monospace;'>{$executorUser}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #6366F1;'><strong>🖥️ Server:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937; font-family: monospace;'>{$executorServer}</td>
                            </tr>" .
                            ($executorContainer ? "
                            <tr>
                                <td style='padding: 8px 0; color: #6366F1;'><strong>🐳 Container:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937; font-family: monospace;'>{$executorContainer}</td>
                            </tr>" : "") . "
                            <tr>
                                <td style='padding: 8px 0; color: #6366F1;'><strong>⏰ Timestamp:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937; font-family: monospace;'>{$executorTimestamp}</td>
                            </tr>
                        </table>
                    </div>

                    <div style='background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 15px; margin: 25px 0; border-radius: 0 6px 6px 0;'>
                        <h4 style='color: #92400E; margin: 0 0 10px 0;'>⚠️ Importante:</h4>
                        <ul style='color: #92400E; margin: 0; padding-left: 20px;'>
                            <li>Salva questo URL in un luogo sicuro</li>
                            <li>Non condividerlo mai via email o chat</li>
                            <li>L'URL cambierà al prossimo riavvio del sistema</li>
                            <li>Utilizza sempre l'autenticazione 2FA</li>
                            <li><strong>Verifica che l'IP sopra sia autorizzato!</strong></li>
                        </ul>
                    </div>

                    <div style='background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                        <h4 style='color: #166534; margin: 0 0 15px 0;'>🛡️ Caratteristiche di Sicurezza:</h4>
                        <div style='color: #166534; line-height: 1.8;'>
                            ✅ URL criptato con hash SHA-256<br>
                            ✅ Autenticazione 2FA obbligatoria<br>
                            ✅ Sessione auto-scadenza 15 minuti<br>
                            ✅ Rate limiting anti-bruteforce<br>
                            ✅ Audit log completo delle azioni
                        </div>
                    </div>

                    <div style='text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB;'>
                        <p style='color: #9CA3AF; font-size: 12px; margin: 0;'>
                            Generato automaticamente il " . date('d/m/Y H:i:s') . '<br>
                            need2talk Enterprise Security System
                        </p>
                    </div>
                </div>
            </div>
        ';

        try {
            $this->emailService->send($admin['email'], $subject, $message);
        } catch (\Exception $e) {
            Logger::email('error', 'ADMIN NOTIFICATION: Failed to send admin URL notification email', [
                'service' => 'AdminUrlNotificationService',
                'admin_email' => $admin['email'],
                'notification_type' => 'email',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invia notifica Telegram
     *
     * @param string $chatId Telegram chat ID
     * @param string $newUrl The new admin URL
     * @param array|null $executorInfo Information about who triggered the notification
     */
    private function sendTelegramNotification(string $chatId, string $newUrl, ?array $executorInfo = null): void
    {
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? null;

        if (!$botToken) {
            return;
        }

        // ENTERPRISE: Environment and domain detection
        // 12 FACTOR APP: Use environment config instead of hardcoded IP
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');
        $environment = $_ENV['APP_ENV'] ?? 'development';

        // Extract executor details
        $executorIp = $executorInfo['client_ip'] ?? 'N/A';
        $executorUser = $executorInfo['effective_user'] ?? 'N/A';
        $executorServer = $executorInfo['server_hostname'] ?? 'N/A';

        $message = "🔐 *need2talk Admin [{$environment}]*\n\n" .
                  "🆕 Nuovo URL sicuro generato:\n" .
                  "`https://{$domain}{$newUrl}`\n\n" .
                  "👤 *Richiesto da:*\n" .
                  "├ IP: `{$executorIp}`\n" .
                  "├ User: `{$executorUser}`\n" .
                  "└ Server: `{$executorServer}`\n\n" .
                  "⚠️ *Salva in luogo sicuro*\n" .
                  "🔒 Usa sempre 2FA per accedere\n" .
                  "⏰ URL valido fino al prossimo riavvio\n\n" .
                  '📅 ' . date('d/m/Y H:i:s');

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ];

        try {
            $response = file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query($data),
                ],
            ]));

            // Success - no logging needed
        } catch (\Exception $e) {
            Logger::email('error', 'ADMIN NOTIFICATION: Telegram notification failed', [
                'service' => 'AdminUrlNotificationService',
                'notification_type' => 'telegram',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invia SMS backup per super admin
     */
    private function sendSmsNotification(string $phone, string $newUrl): void
    {
        $twilioSid = $_ENV['TWILIO_SID'] ?? null;
        $twilioToken = $_ENV['TWILIO_TOKEN'] ?? null;
        $twilioFrom = $_ENV['TWILIO_FROM'] ?? null;

        if (!$twilioSid || !$twilioToken || !$twilioFrom) {
            return;
        }

        // ENTERPRISE: Environment and domain detection
        // 12 FACTOR APP: Use environment config instead of hardcoded IP
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');
        $environment = $_ENV['APP_ENV'] ?? 'development';

        $message = "🔐 need2talk Admin [{$environment}]\n\n" .
                  "Nuovo URL sicuro:\n" .
                  "https://{$domain}{$newUrl}\n\n" .
                  "⚠️ Salva in luogo sicuro\n" .
                  date('d/m/Y H:i');

        try {
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";

            $response = file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Authorization: Basic ' . base64_encode("{$twilioSid}:{$twilioToken}") . "\r\n" .
                        'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query([
                        'From' => $twilioFrom,
                        'To' => $phone,
                        'Body' => $message,
                    ]),
                ],
            ]));

            // Success - no logging needed
        } catch (\Exception $e) {
            Logger::email('error', 'ADMIN NOTIFICATION: SMS notification failed', [
                'service' => 'AdminUrlNotificationService',
                'notification_type' => 'sms',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Webhook notifications (Slack/Discord)
     *
     * @param string $newUrl The new admin URL
     * @param array|null $executorInfo Information about who triggered the notification
     */
    private function sendWebhookNotifications(string $newUrl, ?array $executorInfo = null): void
    {
        // Slack webhook
        $slackWebhook = $_ENV['SLACK_ADMIN_WEBHOOK'] ?? null;

        if ($slackWebhook) {
            $this->sendSlackNotification($slackWebhook, $newUrl, $executorInfo);
        }

        // Discord webhook
        $discordWebhook = $_ENV['DISCORD_ADMIN_WEBHOOK'] ?? null;

        if ($discordWebhook) {
            $this->sendDiscordNotification($discordWebhook, $newUrl, $executorInfo);
        }
    }

    /**
     * Slack notification
     *
     * @param string $webhookUrl Slack webhook URL
     * @param string $newUrl The new admin URL
     * @param array|null $executorInfo Information about who triggered the notification
     */
    private function sendSlackNotification(string $webhookUrl, string $newUrl, ?array $executorInfo = null): void
    {
        // ENTERPRISE: Environment and domain detection
        // 12 FACTOR APP: Use environment config instead of hardcoded IP
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');

        // Extract executor details
        $executorIp = $executorInfo['client_ip'] ?? 'N/A';
        $executorUser = $executorInfo['effective_user'] ?? 'N/A';

        $payload = [
            'text' => '🔐 need2talk Admin - Nuovo URL Sicuro',
            'attachments' => [[
                'color' => 'good',
                'fields' => [
                    [
                        'title' => '🔗 Nuovo URL Admin',
                        'value' => "`https://{$domain}{$newUrl}`",
                        'short' => false,
                    ],
                    [
                        'title' => '👤 Richiesto da',
                        'value' => "IP: {$executorIp}\nUser: {$executorUser}",
                        'short' => false,
                    ],
                    [
                        'title' => '⏰ Timestamp',
                        'value' => date('d/m/Y H:i:s'),
                        'short' => true,
                    ],
                    [
                        'title' => '🛡️ Sicurezza',
                        'value' => '2FA obbligatoria + 15min timeout',
                        'short' => true,
                    ],
                ],
            ]],
        ];

        try {
            $response = file_get_contents($webhookUrl, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($payload),
                ],
            ]));

            // Success - no logging needed
        } catch (\Exception $e) {
            Logger::email('error', 'ADMIN NOTIFICATION: Slack notification failed', [
                'service' => 'AdminUrlNotificationService',
                'notification_type' => 'slack',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Discord notification
     *
     * @param string $webhookUrl Discord webhook URL
     * @param string $newUrl The new admin URL
     * @param array|null $executorInfo Information about who triggered the notification
     */
    private function sendDiscordNotification(string $webhookUrl, string $newUrl, ?array $executorInfo = null): void
    {
        // ENTERPRISE: Environment and domain detection
        // 12 FACTOR APP: Use environment config instead of hardcoded IP
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');

        // Extract executor details
        $executorIp = $executorInfo['client_ip'] ?? 'N/A';
        $executorUser = $executorInfo['effective_user'] ?? 'N/A';
        $executorServer = $executorInfo['server_hostname'] ?? 'N/A';

        $payload = [
            'content' => '🔐 **need2talk Admin** - Nuovo URL Sicuro',
            'embeds' => [[
                'title' => '🔗 Nuovo URL Admin Generato',
                'description' => "```\nhttps://{$domain}{$newUrl}\n```",
                'color' => 3447003, // Blue color
                'fields' => [
                    [
                        'name' => '👤 Richiesto da',
                        'value' => "IP: `{$executorIp}`\nUser: `{$executorUser}`\nServer: `{$executorServer}`",
                        'inline' => false,
                    ],
                    [
                        'name' => '⏰ Timestamp',
                        'value' => date('d/m/Y H:i:s'),
                        'inline' => true,
                    ],
                    [
                        'name' => '🛡️ Sicurezza',
                        'value' => '2FA + 15min timeout',
                        'inline' => true,
                    ],
                ],
                'footer' => [
                    'text' => 'need2talk Enterprise Security',
                ],
            ]],
        ];

        try {
            $response = file_get_contents($webhookUrl, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($payload),
                ],
            ]));

            // Success - no logging needed
        } catch (\Exception $e) {
            Logger::email('error', 'ADMIN NOTIFICATION: Discord notification failed', [
                'service' => 'AdminUrlNotificationService',
                'notification_type' => 'discord',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark notification as sent
     */
    private function markNotificationSent(string $urlHash): void
    {
        $stmt = $this->db->prepare("
            UPDATE admin_url_changes
            SET notification_status = 'sent', notified_at = NOW()
            WHERE url_hash = ? AND notification_status = 'pending'
        ");
        $stmt->execute([$urlHash]);
    }

    /**
     * Send emergency access email notification
     */
    private function sendEmergencyEmailNotification(array $admin, string $emergencyCode, array $accessDetails): void
    {
        // 12 FACTOR APP: Use environment config instead of hardcoded IP
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');
        $environment = $_ENV['APP_ENV'] ?? 'development';
        $envLabel = $environment === 'production' ? '' : ' [' . strtoupper($environment) . ']';

        $subject = '🚨 need2talk Admin' . $envLabel . ' - ACCESSO DI EMERGENZA UTILIZZATO';

        $codeUsed = substr($emergencyCode, 0, 4) . '****';
        $userAgent = substr($accessDetails['user_agent'], 0, 100) . '...';
        $currentDate = date('d/m/Y H:i:s');

        $message = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #EF4444, #DC2626); padding: 30px; text-align: center; color: white; border-radius: 12px 12px 0 0;'>
                    <h1 style='margin: 0; font-size: 28px;'>🚨 ALERT SICUREZZA</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9; font-size: 18px;'>Accesso di Emergenza Utilizzato</p>
                </div>

                <div style='background: #ffffff; padding: 40px; border-radius: 0 0 12px 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
                    <p style='font-size: 16px; color: #374151; margin-bottom: 20px;'>
                        Ciao <strong>{$admin['full_name']}</strong>,
                    </p>

                    <div style='background: #FEE2E2; border-left: 4px solid #EF4444; padding: 20px; margin: 25px 0; border-radius: 0 6px 6px 0;'>
                        <h3 style='color: #B91C1C; margin: 0 0 15px 0; font-size: 18px;'>⚠️ ACCESSO DI EMERGENZA ATTIVATO</h3>
                        <p style='color: #7F1D1D; margin: 0; line-height: 1.6;'>
                            Un codice di emergenza è stato utilizzato per accedere al pannello admin,
                            bypassando completamente il sistema 2FA. Verifica immediatamente se questo accesso è autorizzato.
                        </p>
                    </div>

                    <div style='background: #F8FAFC; border: 2px solid #E5E7EB; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                        <h3 style='color: #374151; margin: 0 0 15px 0; font-size: 18px;'>📋 Dettagli Accesso:</h3>

                        <table style='width: 100%; font-family: monospace; font-size: 14px;'>
                            <tr>
                                <td style='padding: 8px 0; color: #6B7280; width: 30%;'><strong>Codice Utilizzato:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937;'>" . substr($emergencyCode, 0, 4) . "****</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #6B7280;'><strong>Timestamp:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937;'>{$accessDetails['timestamp']}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #6B7280;'><strong>Indirizzo IP:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937; word-break: break-all;'>{$accessDetails['ip_address']}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #6B7280;'><strong>User Agent:</strong></td>
                                <td style='padding: 8px 0; color: #1F2937; word-break: break-all; font-size: 12px;'>" . substr($accessDetails['user_agent'], 0, 100) . '...</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; color: #6B7280;"><strong>Bypass 2FA:</strong></td>
                                <td style="padding: 8px 0; color: #EF4444;"><strong>SÌ - COMPLETO</strong></td>
                            </tr>
                        </table>
                    </div>

                    <div style="background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 15px; margin: 25px 0; border-radius: 0 6px 6px 0;">
                        <h4 style="color: #92400E; margin: 0 0 10px 0;">🔧 Azioni Consigliate:</h4>
                        <ul style="color: #92400E; margin: 0; padding-left: 20px; line-height: 1.8;">
                            <li>Verifica con il team se questo accesso è autorizzato</li>
                            <li>Controlla i log di sicurezza per attività sospette</li>
                            <li>Considera la generazione di nuovi codici di emergenza</li>
                            <li>Monitora le sessioni admin attive</li>
                            <li>Esamina le modifiche apportate durante questa sessione</li>
                        </ul>
                    </div>

                    <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 20px; margin: 25px 0;">
                        <h4 style="color: #166534; margin: 0 0 15px 0;">🛡️ Informazioni Sistema:</h4>
                        <div style=\"color: #166534; line-height: 1.8; font-size: 14px;\">
                            🌐 <strong>Dominio:</strong> {$domain}<br>
                            🔧 <strong>Ambiente:</strong> {$environment}<br>
                            📅 <strong>Data Alert:</strong> {$currentDate}<br>
                            🔒 <strong>Tipo Accesso:</strong> Emergency Bypass<br>
                            ⏱️ <strong>Durata Sessione:</strong> 30 minuti max
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB;">
                        <p style="color: #EF4444; font-size: 14px; margin: 0; font-weight: bold;">
                            ⚠️ QUESTO È UN ALERT DI SICUREZZA CRITICO ⚠️
                        </p>
                        <p style="color: #9CA3AF; font-size: 12px; margin: 10px 0 0 0;">
                            need2talk Enterprise Security System<br>
                            Generato automaticamente per accesso di emergenza
                        </p>
                    </div>
                </div>
            </div>
        ';

        try {
            $this->emailService->send($admin['email'], $subject, $message);
        } catch (\Exception $e) {
            Logger::email('error', 'ADMIN NOTIFICATION: Failed to send emergency access notification email', [
                'service' => 'AdminUrlNotificationService',
                'admin_email' => $admin['email'],
                'notification_type' => 'emergency_email',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send emergency access Telegram notification
     */
    private function sendEmergencyTelegramNotification(string $chatId, string $emergencyCode, array $accessDetails): void
    {
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? null;

        if (!$botToken) {
            return;
        }

        // 12 FACTOR APP: Use environment config instead of hardcoded IP
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');
        $environment = $_ENV['APP_ENV'] ?? 'development';

        $message = "🚨 *ALERT SICUREZZA* 🚨\n\n" .
                  "⚠️ *ACCESSO DI EMERGENZA UTILIZZATO*\n" .
                  "need2talk Admin [{$environment}]\n\n" .
                  "📋 *Dettagli:*\n" .
                  '🔑 Codice: `' . substr($emergencyCode, 0, 4) . "****`\n" .
                  "🕐 Ora: `{$accessDetails['timestamp']}`\n" .
                  "🌐 IP: `{$accessDetails['ip_address']}`\n" .
                  "🔓 Bypass 2FA: *COMPLETO*\n\n" .
                  "🛡️ *Sistema:* {$domain}\n" .
                  "⏰ *Durata sessione:* 30 minuti max\n\n" .
                  "🔧 *Verifica immediatamente se autorizzato*\n\n" .
                  '📅 ' . date('d/m/Y H:i:s');

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ];

        try {
            $response = file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query($data),
                ],
            ]));

            // Success - no logging needed
        } catch (\Exception $e) {
            Logger::email('error', 'ADMIN NOTIFICATION: Emergency access notification Telegram failed', [
                'service' => 'AdminUrlNotificationService',
                'notification_type' => 'emergency_telegram',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
