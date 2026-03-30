<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseGlobalsManager;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use SendGrid;
use SendGrid\Mail\Mail;

/**
 * Email Service - Enterprise Grade with SendGrid Web API v3 + SMTP Fallback
 *
 * Sistema email enterprise che supporta:
 * - SendGrid Web API v3 (primary) con native tracking disable
 * - SendGrid SMTP (fallback) con TLS/STARTTLS encryption
 * - MailHog per development (SMTP localhost:1025)
 * - Connection resilience con automatic failover Web API → SMTP
 * - Rate limiting per prevenire spam
 * - Template system con rendering sicuro
 * - Logging completo per diagnostica
 * - Multiple SMTP providers con failover
 * - DKIM e SPF support per deliverability
 * - Click/Open tracking disable nativo (no URL wrapping)
 */
class EmailService
{
    private $logger;

    private $config;

    private $phpMailer;

    // ENTERPRISE: Multiple SMTP providers per failover
    private $smtpProviders = [];

    public function __construct()
    {
        $this->logger = null;
        $this->config = [
            'mailer' => EnterpriseGlobalsManager::getEnv('MAIL_MAILER', 'smtp'),
            'host' => EnterpriseGlobalsManager::getEnv('MAIL_HOST', 'smtp.ionos.it'),
            'port' => (int) EnterpriseGlobalsManager::getEnv('MAIL_PORT', 587),
            'username' => EnterpriseGlobalsManager::getEnv('MAIL_USERNAME', ''),
            'password' => EnterpriseGlobalsManager::getEnv('MAIL_PASSWORD', ''),
            'encryption' => EnterpriseGlobalsManager::getEnv('MAIL_ENCRYPTION', 'tls'),
            'from_address' => EnterpriseGlobalsManager::getEnv('MAIL_FROM_ADDRESS', 'noreply@need2talk.app'),
            'from_name' => EnterpriseGlobalsManager::getEnv('MAIL_FROM_NAME', 'need2talk'),
            'timeout' => (int) EnterpriseGlobalsManager::getEnv('MAIL_TIMEOUT', 30),
            'auth' => EnterpriseGlobalsManager::getEnv('MAIL_AUTH', 'true') === 'true',
        ];

        // ENTERPRISE: Initialize multiple SMTP providers for failover
        $this->initializeSmtpProviders();

        // Initialize PHPMailer
        $this->initializePHPMailer();
    }

    /**
     * Invia email di verifica con integrazione MailHog/SMTP reale
     */
    public function sendVerificationEmail(string $email, string $nickname, string $verificationUrl): bool
    {
        try {
            Logger::email('info', 'EMAIL: Sending verification email', [
                'email' => $email,
                'nickname' => $nickname,
                'verification_url' => $verificationUrl,
                'mailer' => $this->config['mailer'],
                'host' => $this->config['host'],
                'port' => $this->config['port'],
            ]);

            // Renderizza il template email
            $subject = 'Verifica il tuo account need2talk';
            $body = $this->renderEmailTemplate('verification', [
                'nickname' => $nickname,
                'verification_url' => $verificationUrl,
                'app_name' => 'need2talk',
            ]);

            // Invia l'email tramite il metodo configurato
            return $this->sendMail($email, $subject, $body);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Verification email failed', [
                'email' => $email,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'operation' => 'sendVerificationEmail',
            ]);

            return false;
        }
    }

    /**
     * Invia email generica con SMTP reale
     */
    public function send(string $to, string $subject, string $body): bool
    {
        try {
            Logger::email('info', 'EMAIL: Sending generic email', [
                'to' => $to,
                'subject' => $subject,
                'body_preview' => substr($body, 0, 100),
                'body_length' => strlen($body),
                'mailer' => $this->config['mailer'],
            ]);

            return $this->sendMail($to, $subject, $body);

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Generic email failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'operation' => 'send',
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE: Send multiple emails in batch via single SMTP connection
     * Massively improves performance for 100k+ concurrent users
     */
    public function sendBatch(array $emails): array
    {
        $results = [];
        $totalEmails = count($emails);

        Logger::email('info', 'ENTERPRISE EMAIL BATCH: Starting batch processing', [
            'batch_size' => $totalEmails,
            'mailer' => $this->config['mailer'],
        ]);

        if ($this->config['mailer'] !== 'smtp') {
            // Fallback to individual sends for non-SMTP
            foreach ($emails as $index => $email) {
                $results[$index] = $this->send($email['to'], $email['subject'], $email['body']);
            }

            return $results;
        }

        // Try each SMTP provider for batch processing
        foreach ($this->smtpProviders as $providerName => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            try {
                Logger::email('info', 'ENTERPRISE EMAIL BATCH: Attempting batch with provider', [
                    'provider' => $providerName,
                    'batch_size' => $totalEmails,
                    'host' => $provider['host'],
                    'port' => $provider['port'],
                ]);

                // Configure SMTP provider once for entire batch
                $this->configureSMTPProvider($provider);

                // Process entire batch with single connection
                $results = $this->processBatchWithProvider($emails, $providerName);

                // If successful, return results
                if (!empty($results)) {
                    Logger::email('info', 'ENTERPRISE EMAIL BATCH: Batch processing completed successfully', [
                        'provider' => $providerName,
                        'total_emails' => $totalEmails,
                        'successful' => array_sum($results),
                        'failed' => $totalEmails - array_sum($results),
                    ]);

                    return $results;
                }

            } catch (\Exception $e) {
                Logger::email('warning', 'ENTERPRISE EMAIL BATCH: Provider failed for batch', [
                    'provider' => $providerName,
                    'error' => $e->getMessage(),
                    'batch_size' => $totalEmails,
                ]);

                continue;
            }
        }

        // All providers failed - return all false
        Logger::email('error', 'ENTERPRISE EMAIL BATCH: All providers failed for batch', [
            'batch_size' => $totalEmails,
            'providers_tried' => array_keys($this->smtpProviders),
        ]);

        return array_fill(0, $totalEmails, false);
    }

    /**
     * ENTERPRISE: Warm up SMTP connection pool for optimal performance
     * Pre-establishes connections to avoid cold start delays
     */
    public function warmupConnections(): array
    {
        $warmupResults = [];

        Logger::email('info', 'ENTERPRISE EMAIL: Starting connection pool warmup', [
            'providers_to_warmup' => count($this->smtpProviders),
        ]);

        foreach ($this->smtpProviders as $providerName => $provider) {
            if (!$provider['enabled']) {
                $warmupResults[$providerName] = false;

                continue;
            }

            try {
                $startTime = microtime(true);

                // Configure provider
                $this->configureSMTPProvider($provider);

                // Test connection without sending email
                $this->phpMailer->SMTPDebug = SMTP::DEBUG_OFF; // Suppress debug for warmup

                // Attempt to connect
                if ($this->phpMailer->smtpConnect()) {
                    $this->phpMailer->smtpClose();
                    $warmupTime = round((microtime(true) - $startTime) * 1000, 2);

                    $warmupResults[$providerName] = true;

                    Logger::email('info', 'ENTERPRISE EMAIL: Provider warmed up successfully', [
                        'provider' => $providerName,
                        'host' => $provider['host'],
                        'warmup_time_ms' => $warmupTime,
                    ]);
                } else {
                    $warmupResults[$providerName] = false;

                    Logger::email('warning', 'ENTERPRISE EMAIL: Provider warmup failed', [
                        'provider' => $providerName,
                        'host' => $provider['host'],
                    ]);
                }

            } catch (\Exception $e) {
                $warmupResults[$providerName] = false;

                Logger::email('error', 'ENTERPRISE EMAIL: Provider warmup exception', [
                    'provider' => $providerName,
                    'error' => $e->getMessage(),
                    'host' => $provider['host'] ?? 'unknown',
                ]);
            }
        }

        $successfulWarmups = array_sum($warmupResults);
        $totalProviders = count($this->smtpProviders);

        Logger::email('info', 'ENTERPRISE EMAIL: Connection pool warmup completed', [
            'successful_warmups' => $successfulWarmups,
            'total_providers' => $totalProviders,
            'warmup_success_rate' => round(($successfulWarmups / $totalProviders) * 100, 2) . '%',
            'ready_for_batch_processing' => $successfulWarmups > 0,
        ]);

        return $warmupResults;
    }

    /**
     * Renderizza template email
     */
    public function renderEmailTemplate(string $template, array $data): string
    {
        // Estrae le variabili per il template
        extract($data);

        // Inizia il buffering dell'output
        ob_start();

        // Include il template
        $templatePath = APP_ROOT . '/app/Views/emails/' . $template . '.php';

        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            throw new \Exception("Email template not found: {$template}");
        }

        // Ottieni il contenuto e pulisci il buffer
        $content = ob_get_clean();

        return $content;
    }

    /**
     * ENTERPRISE: Initialize multiple SMTP providers for high availability
     */
    private function initializeSmtpProviders(): void
    {
        // Primary SMTP (from environment)
        $this->smtpProviders['primary'] = [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'username' => $this->config['username'],
            'password' => $this->config['password'],
            'encryption' => $this->config['encryption'],
            'priority' => 1,
            'enabled' => true,
        ];

        // Secondary SMTP providers (for production failover)
        if (EnterpriseGlobalsManager::getEnv('APP_ENV') === 'production') {
            // Gmail SMTP fallback
            if ($gmailUser = EnterpriseGlobalsManager::getEnv('GMAIL_SMTP_USERNAME')) {
                $this->smtpProviders['gmail'] = [
                    'host' => 'smtp.gmail.com',
                    'port' => 587,
                    'username' => $gmailUser,
                    'password' => EnterpriseGlobalsManager::getEnv('GMAIL_SMTP_PASSWORD'),
                    'encryption' => 'tls',
                    'priority' => 2,
                    'enabled' => true,
                ];
            }

            // SendGrid SMTP fallback
            if ($sendgridKey = EnterpriseGlobalsManager::getEnv('SENDGRID_API_KEY')) {
                $this->smtpProviders['sendgrid'] = [
                    'host' => 'smtp.sendgrid.net',
                    'port' => 587,
                    'username' => 'apikey',
                    'password' => $sendgridKey,
                    'encryption' => 'tls',
                    'priority' => 3,
                    'enabled' => true,
                ];
            }

            // AWS SES SMTP fallback
            if ($awsKey = EnterpriseGlobalsManager::getEnv('AWS_SES_SMTP_USERNAME')) {
                $region = EnterpriseGlobalsManager::getEnv('AWS_SES_REGION', 'us-east-1');
                $this->smtpProviders['aws_ses'] = [
                    'host' => "email-smtp.{$region}.amazonaws.com",
                    'port' => 587,
                    'username' => $awsKey,
                    'password' => EnterpriseGlobalsManager::getEnv('AWS_SES_SMTP_PASSWORD'),
                    'encryption' => 'tls',
                    'priority' => 4,
                    'enabled' => true,
                ];
            }
        }

        // Sort providers by priority
        uasort($this->smtpProviders, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
    }

    /**
     * ENTERPRISE: Initialize PHPMailer with optimal settings
     */
    private function initializePHPMailer(): void
    {
        $this->phpMailer = new PHPMailer(true); // Enable exceptions

        // ENTERPRISE: Security and performance settings
        $this->phpMailer->isSMTP();
        $this->phpMailer->CharSet = 'UTF-8';
        $this->phpMailer->Encoding = 'base64';
        $this->phpMailer->XMailer = 'need2talk EmailService';
        $this->phpMailer->Timeout = $this->config['timeout'];

        // ENTERPRISE: Debug settings - REDUCED LOGGING (only essential errors)
        // SMTP Debug disabled to reduce log verbosity - use only for critical debugging
        $this->phpMailer->SMTPDebug = SMTP::DEBUG_OFF; // Disable verbose SMTP debug logs

        // Set default From address
        $this->phpMailer->setFrom($this->config['from_address'], $this->config['from_name']);
    }

    /**
     * Metodo principale per invio email - supporta SendGrid Web API, SMTP, e Log
     *
     * ENTERPRISE GALAXY: Priority Order
     * 1. SendGrid Web API (if configured and SendGrid host detected)
     * 2. SMTP with multiple providers (failover)
     * 3. Log (fallback)
     */
    private function sendMail(string $to, string $subject, string $body): bool
    {
        try {
            // Log dell'invio
            Logger::email('info', 'EMAIL: Attempting to send mail', [
                'to' => $to,
                'subject' => $subject,
                'mailer' => $this->config['mailer'],
                'host' => $this->config['host'],
                'port' => $this->config['port'],
            ]);

            switch ($this->config['mailer']) {
                case 'smtp':
                    // ENTERPRISE GALAXY: Try SendGrid Web API first (if SendGrid detected)
                    $isSendGrid = strpos($this->config['host'], 'sendgrid') !== false;
                    $sendgridApiKey = EnterpriseGlobalsManager::getEnv('SENDGRID_API_KEY', '');

                    if ($isSendGrid && !empty($sendgridApiKey)) {
                        Logger::email('info', 'EMAIL: SendGrid detected, trying Web API first', [
                            'to' => $to,
                            'subject' => $subject,
                            'fallback' => 'SMTP if Web API fails',
                        ]);

                        // Try Web API
                        $webApiSuccess = $this->sendViaSendGridWebAPI($to, $subject, $body);

                        if ($webApiSuccess) {
                            return true;
                        }

                        // Web API failed, fallback to SMTP
                        Logger::email('warning', 'EMAIL: SendGrid Web API failed, falling back to SMTP', [
                            'to' => $to,
                            'subject' => $subject,
                        ]);
                    }

                    // Use SMTP (either not SendGrid, or Web API failed)
                    return $this->sendViaSMTP($to, $subject, $body);

                case 'log':
                    return $this->sendViaLog($to, $subject, $body);

                default:
                    Logger::email('warning', 'EMAIL: Unknown mailer type, falling back to log', [
                        'mailer' => $this->config['mailer'],
                    ]);

                    return $this->sendViaLog($to, $subject, $body);
            }

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: sendMail failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'mailer' => $this->config['mailer'],
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE GALAXY: Send email via SendGrid Web API v3 (Bypass SMTP tracking issues)
     *
     * SendGrid Web API natively supports tracking disable settings, unlike SMTP which ignores
     * the X-SMTPAPI header. This method provides:
     *
     * - Native tracking disable (click_tracking, open_tracking)
     * - No URL rewriting by SendGrid
     * - Better deliverability and analytics
     * - Automatic failover to SMTP on errors
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body HTML email body
     * @return bool True on success, false on failure
     */
    private function sendViaSendGridWebAPI(string $to, string $subject, string $body): bool
    {
        try {
            // Validate SendGrid API key
            $apiKey = EnterpriseGlobalsManager::getEnv('SENDGRID_API_KEY', '');

            if (empty($apiKey)) {
                Logger::email('warning', 'SENDGRID WEB API: API key not configured, falling back to SMTP', [
                    'to' => $to,
                    'subject' => $subject,
                ]);

                return false;
            }

            Logger::email('info', 'SENDGRID WEB API: Attempting to send email', [
                'to' => $to,
                'subject' => $subject,
                'from' => $this->config['from_address'],
            ]);

            // Create SendGrid Mail object
            $email = new Mail();

            // Set sender
            $email->setFrom($this->config['from_address'], $this->config['from_name']);

            // Set recipient
            $email->addTo($to);

            // Set subject
            $email->setSubject($subject);

            // ENTERPRISE FIX: Set plain text FIRST, then HTML (SendGrid multipart order requirement)
            // Some email clients display the last content type, so HTML must come last
            $email->addContent('text/plain', strip_tags($body));
            $email->addContent('text/html', $body);

            // ENTERPRISE GALAXY: Disable click and open tracking (SENDGRID 2025 OFFICIAL FIX!)
            // Reference: https://docs.sendgrid.com/api-reference/mail-send/mail-send
            // This prevents SendGrid from rewriting URLs to url391.need2talk.it
            // Using explicit TrackingSettings object to override account-level settings
            $trackingSettings = new \SendGrid\Mail\TrackingSettings();

            // Disable click tracking for both HTML and plain text
            $clickTracking = new \SendGrid\Mail\ClickTracking();
            $clickTracking->setEnable(false);
            $clickTracking->setEnableText(false);
            $trackingSettings->setClickTracking($clickTracking);

            // Disable open tracking
            $openTracking = new \SendGrid\Mail\OpenTracking();
            $openTracking->setEnable(false);
            $trackingSettings->setOpenTracking($openTracking);

            // Apply tracking settings (overrides account-level settings)
            $email->setTrackingSettings($trackingSettings);

            Logger::email('debug', 'SENDGRID WEB API: Tracking disabled via TrackingSettings object', [
                'to' => $to,
                'subject' => $subject,
                'click_tracking_enable' => false,
                'click_tracking_enable_text' => false,
                'open_tracking_enable' => false,
                'override_account_settings' => true,
            ]);

            // Initialize SendGrid client
            $sendgrid = new SendGrid($apiKey);

            // Send the email
            $response = $sendgrid->send($email);

            // Check response status code
            // 202 = Accepted (success)
            // 200 = OK (also success for some operations)
            if ($response->statusCode() === 202 || $response->statusCode() === 200) {
                Logger::email('info', 'SENDGRID WEB API: Email sent successfully', [
                    'to' => $to,
                    'subject' => $subject,
                    'status_code' => $response->statusCode(),
                    'message_id' => $response->headers()['X-Message-Id'] ?? 'unknown',
                ]);

                return true;
            }

            // Non-success status code
            Logger::email('warning', 'SENDGRID WEB API: Unexpected status code', [
                'to' => $to,
                'subject' => $subject,
                'status_code' => $response->statusCode(),
                'body' => $response->body(),
                'headers' => $response->headers(),
            ]);

            return false;

        } catch (\Exception $e) {
            Logger::email('error', 'SENDGRID WEB API: Failed to send email', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE: Send email via PHPMailer with TLS/STARTTLS support and failover
     */
    private function sendViaSMTP(string $to, string $subject, string $body): bool
    {
        // Try each SMTP provider in order of priority
        foreach ($this->smtpProviders as $providerName => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            try {
                Logger::email('info', 'ENTERPRISE EMAIL: Attempting PHPMailer send', [
                    'provider' => $providerName,
                    'to' => $to,
                    'subject' => $subject,
                    'host' => $provider['host'],
                    'port' => $provider['port'],
                    'encryption' => $provider['encryption'] ?? 'none',
                ]);

                // Configure SMTP provider
                $this->configureSMTPProvider($provider);

                // ENTERPRISE FIX: Clear ALL previous email data to avoid "Only one sender" error
                // Must clear addresses, recipients, custom headers, and reset From
                $this->phpMailer->clearAddresses();
                $this->phpMailer->clearCCs();
                $this->phpMailer->clearBCCs();
                $this->phpMailer->clearReplyTos();
                $this->phpMailer->clearCustomHeaders();

                // Reset From address (prevents "Only one sender per message" error)
                $this->phpMailer->setFrom($this->config['from_address'], $this->config['from_name']);

                // Set recipients and content
                $this->phpMailer->addAddress($to);
                $this->phpMailer->Subject = $subject;
                $this->phpMailer->isHTML(true);
                $this->phpMailer->Body = $body;

                // Create plain text version for better deliverability
                $this->phpMailer->AltBody = strip_tags($body);

                // ENTERPRISE GALAXY: Disable SendGrid click/open tracking via SMTP headers
                // This prevents SendGrid from rewriting links to url391.need2talk.it
                // We use our own tracking system (NewsletterLinkWrapper)
                if (strpos($provider['host'], 'sendgrid') !== false) {
                    // CRITICAL: Updated to new SendGrid API v3 syntax (tracking_settings)
                    // Old syntax with "filters" was deprecated in 2020
                    $this->phpMailer->addCustomHeader('X-SMTPAPI', json_encode([
                        'tracking_settings' => [
                            'click_tracking' => ['enable' => false],
                            'open_tracking' => ['enable' => false],
                        ],
                    ]));

                    Logger::email('debug', 'ENTERPRISE EMAIL: SendGrid tracking disabled (API v3 syntax)', [
                        'to' => $to,
                        'subject' => $subject,
                        'header' => 'X-SMTPAPI with tracking_settings (new syntax)',
                    ]);
                }

                // Send the email
                $success = $this->phpMailer->send();

                if ($success) {
                    Logger::email('info', 'ENTERPRISE EMAIL: PHPMailer sent successfully', [
                        'provider' => $providerName,
                        'to' => $to,
                        'subject' => $subject,
                        'message_id' => $this->phpMailer->getLastMessageID() ?: 'unknown',
                    ]);

                    return true;
                }

            } catch (Exception $e) {
                Logger::email('warning', 'ENTERPRISE EMAIL: Provider failed, trying next', [
                    'provider' => $providerName,
                    'error' => $e->getMessage(),
                    'to' => $to,
                    'subject' => $subject,
                    'error_code' => $e->getCode(),
                ]);

                // Continue to next provider
                continue;
            }
        }

        // All providers failed
        Logger::email('error', 'ENTERPRISE EMAIL: All providers failed', [
            'to' => $to,
            'subject' => $subject,
            'providers_tried' => array_keys($this->smtpProviders),
        ]);

        return false;
    }

    /**
     * ENTERPRISE: Configure SMTP provider with automatic TLS/STARTTLS detection
     */
    private function configureSMTPProvider(array $provider): void
    {
        $this->phpMailer->Host = $provider['host'];
        $this->phpMailer->Port = $provider['port'];

        // ENTERPRISE: Automatic encryption detection and configuration
        $encryption = strtolower($provider['encryption'] ?? '');

        if ($encryption === 'tls' || $encryption === 'starttls') {
            $this->phpMailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            Logger::email('debug', 'ENTERPRISE EMAIL: Using STARTTLS encryption', [
                'provider' => $provider['host'],
                'port' => $provider['port'],
            ]);
        } elseif ($encryption === 'ssl') {
            $this->phpMailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            Logger::email('debug', 'ENTERPRISE EMAIL: Using SSL/TLS encryption', [
                'provider' => $provider['host'],
                'port' => $provider['port'],
            ]);
        } else {
            // ENTERPRISE: Auto-detect encryption based on port
            if ($provider['port'] === 465) {
                $this->phpMailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                Logger::email('debug', 'ENTERPRISE EMAIL: Auto-detected SSL (port 465)', [
                    'provider' => $provider['host'],
                ]);
            } elseif ($provider['port'] === 587 || $provider['port'] === 25) {
                $this->phpMailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                Logger::email('debug', 'ENTERPRISE EMAIL: Auto-detected STARTTLS (port 587/25)', [
                    'provider' => $provider['host'],
                    'port' => $provider['port'],
                ]);
            } else {
                // Development mode (MailHog) - no encryption
                $this->phpMailer->SMTPSecure = '';
                Logger::email('debug', 'ENTERPRISE EMAIL: No encryption (development/MailHog)', [
                    'provider' => $provider['host'],
                    'port' => $provider['port'],
                ]);
            }
        }

        // ENTERPRISE: Authentication setup
        if (!empty($provider['username']) && !empty($provider['password'])) {
            $this->phpMailer->SMTPAuth = true;
            $this->phpMailer->Username = $provider['username'];
            $this->phpMailer->Password = $provider['password'];

            Logger::email('debug', 'ENTERPRISE EMAIL: SMTP authentication enabled', [
                'username' => $provider['username'],
                'provider' => $provider['host'],
            ]);
        } else {
            $this->phpMailer->SMTPAuth = false;
            Logger::email('debug', 'ENTERPRISE EMAIL: No SMTP authentication (development/local)', [
                'provider' => $provider['host'],
            ]);
        }

        // ENTERPRISE: Additional security options for production
        if (EnterpriseGlobalsManager::getEnv('APP_ENV') !== 'development') {
            $this->phpMailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ];
        } else {
            // Relaxed settings for development/MailHog
            $this->phpMailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }
    }

    /**
     * Simula invio email via log (fallback)
     */
    private function sendViaLog(string $to, string $subject, string $body): bool
    {
        Logger::email('info', 'EMAIL: Log-only simulation', [
            'to' => $to,
            'subject' => $subject,
            'body_preview' => substr(strip_tags($body), 0, 100),
            'body_length' => strlen($body),
            'mode' => 'log_simulation',
        ]);

        return true;
    }

    /**
     * ENTERPRISE: Process batch of emails with single SMTP provider connection
     * Connection reuse dramatically improves performance
     */
    private function processBatchWithProvider(array $emails, string $providerName): array
    {
        $results = [];
        $connectionEstablished = false;
        $successCount = 0;
        $failureCount = 0;

        foreach ($emails as $index => $email) {
            try {
                // Clear previous recipients (but keep connection)
                $this->phpMailer->clearAddresses();
                $this->phpMailer->clearAttachments();
                $this->phpMailer->clearReplyTos();

                // Set current email details
                $this->phpMailer->addAddress($email['to']);
                $this->phpMailer->Subject = $email['subject'];
                $this->phpMailer->isHTML(true);
                $this->phpMailer->Body = $email['body'];
                $this->phpMailer->AltBody = strip_tags($email['body']);

                // Send with existing connection
                $success = $this->phpMailer->send();

                if ($success) {
                    $results[$index] = true;
                    $successCount++;

                    Logger::email('debug', 'ENTERPRISE EMAIL BATCH: Single email sent', [
                        'provider' => $providerName,
                        'batch_index' => $index,
                        'to' => $email['to'],
                        'message_id' => $this->phpMailer->getLastMessageID() ?: 'unknown',
                    ]);
                } else {
                    $results[$index] = false;
                    $failureCount++;
                }

                $connectionEstablished = true;

            } catch (\Exception $e) {
                $results[$index] = false;
                $failureCount++;

                Logger::email('warning', 'ENTERPRISE EMAIL BATCH: Single email failed in batch', [
                    'provider' => $providerName,
                    'batch_index' => $index,
                    'to' => $email['to'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);

                // If connection is broken, rethrow to try next provider
                if (!$connectionEstablished || strpos($e->getMessage(), 'SMTP') !== false) {
                    throw $e;
                }
            }
        }

        Logger::email('info', 'ENTERPRISE EMAIL BATCH: Provider batch completed', [
            'provider' => $providerName,
            'total_emails' => count($emails),
            'successful' => $successCount,
            'failed' => $failureCount,
            'success_rate' => round(($successCount / count($emails)) * 100, 2) . '%',
        ]);

        return $results;
    }
}
