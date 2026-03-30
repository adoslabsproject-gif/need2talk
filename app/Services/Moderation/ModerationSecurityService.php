<?php

namespace Need2Talk\Services\Moderation;

use Need2Talk\Services\CrossPlatformSessionService;
use Need2Talk\Services\Logger;

/**
 * ModerationSecurityService - Enterprise Moderation Portal Security
 *
 * Handles dynamic URL generation, authentication, and 2FA for the Moderation Portal.
 * Completely separate from AdminSecurityService - different secrets, different tables.
 *
 * SECURITY FEATURES:
 * - Dynamic URL rotation (every 8 hours)
 * - 2FA via email (from moderation@need2talk.it)
 * - Session management with 4-hour timeout
 * - Rate limiting on login attempts
 * - Complete audit logging
 *
 * @package Need2Talk\Services\Moderation
 */
class ModerationSecurityService
{
    // URL Configuration
    private const URL_PREFIX = 'mod_';
    private const URL_LENGTH = 16;
    private const URL_ROTATION_MINUTES = 480; // 8 hours

    // Session Configuration
    private const SESSION_TIMEOUT_MINUTES = 240; // 4 hours
    private const SESSION_TOKEN_LENGTH = 64;

    // 2FA Configuration
    private const CODE_LENGTH = 6;
    private const CODE_TTL_SECONDS = 300; // 5 minutes

    // Rate Limiting
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 30;

    // Email Configuration (moderation@need2talk.it)
    private const MODERATION_EMAIL_FROM = 'moderation@need2talk.it';
    private const MODERATION_EMAIL_NAME = 'need2talk Moderation';

    /**
     * Generate the current moderation portal URL
     * URL changes every URL_ROTATION_MINUTES
     *
     * @param bool $fullUrl Se true, restituisce l'URL completo (es. https://need2talk.it/mod_abc...),
     *                      altrimenti solo il path (es. /mod_abc...)
     * @param bool $log Se true, logga la generazione in security log
     */
    public static function generateModerationUrl(bool $fullUrl = false, bool $log = false): string
    {
        $secret = self::getUrlSecret();
        $timeWindow = floor(time() / (self::URL_ROTATION_MINUTES * 60));

        // Create deterministic hash based on secret + time window
        $payload = implode('|', [
            $secret,
            $timeWindow,
            'moderation_portal',
            php_uname('n'), // Server hostname for additional entropy
        ]);

        $hash = hash('sha256', $payload);
        $urlHash = substr($hash, 0, self::URL_LENGTH);

        $generatedUrl = '/' . self::URL_PREFIX . $urlHash;

        // Log URL generation if requested
        if ($log) {
            Logger::security('info', 'MODERATION_URL_GENERATED', [
                'url_hash' => $urlHash,
                'time_window' => $timeWindow,
                'expires_in_minutes' => self::getUrlExpiresInMinutes(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'CLI',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
            ]);
        }

        if ($fullUrl) {
            return self::getBaseUrl() . $generatedUrl;
        }

        return $generatedUrl;
    }

    /**
     * Get minutes until current URL expires
     */
    public static function getUrlExpiresInMinutes(): int
    {
        $rotationSeconds = self::URL_ROTATION_MINUTES * 60;
        $currentTime = time();
        $currentWindowStart = floor($currentTime / $rotationSeconds) * $rotationSeconds;
        $nextWindowStart = $currentWindowStart + $rotationSeconds;

        return (int) ceil(($nextWindowStart - $currentTime) / 60);
    }

    /**
     * Get URL expiration timestamp
     */
    public static function getUrlExpiresAt(): int
    {
        $rotationSeconds = self::URL_ROTATION_MINUTES * 60;
        $currentTime = time();
        $currentWindowStart = floor($currentTime / $rotationSeconds) * $rotationSeconds;

        return $currentWindowStart + $rotationSeconds;
    }

    /**
     * Get base URL for full URL generation
     */
    private static function getBaseUrl(): string
    {
        $protocol = 'https';
        $host = $_ENV['APP_URL'] ?? $_SERVER['HTTP_HOST'] ?? 'need2talk.it';

        // Remove protocol if present in APP_URL
        $host = preg_replace('#^https?://#', '', $host);

        return $protocol . '://' . $host;
    }

    /**
     * Validate if a given path is a valid moderation URL
     */
    public static function validateModerationUrl(string $path): bool
    {
        // Extract hash from path
        if (!preg_match('#^/' . self::URL_PREFIX . '([a-f0-9]{' . self::URL_LENGTH . '})#', $path, $matches)) {
            return false;
        }

        $providedHash = $matches[1];

        // Check current time window
        $currentUrl = self::generateModerationUrl();
        if ($path === $currentUrl || strpos($path, $currentUrl) === 0) {
            return true;
        }

        // Check previous time window (for rotation overlap)
        $secret = self::getUrlSecret();
        $prevTimeWindow = floor(time() / (self::URL_ROTATION_MINUTES * 60)) - 1;
        $prevPayload = implode('|', [
            $secret,
            $prevTimeWindow,
            'moderation_portal',
            php_uname('n'),
        ]);
        $prevHash = substr(hash('sha256', $prevPayload), 0, self::URL_LENGTH);

        return $providedHash === $prevHash;
    }

    /**
     * Authenticate moderator with email and password
     */
    public static function authenticateModerator(string $email, string $password): array
    {
        $pdo = db_pdo();

        // Find moderator by email
        $stmt = $pdo->prepare("
            SELECT id, uuid, username, email, password_hash, display_name,
                   is_active, failed_login_attempts, locked_until, two_factor_enabled
            FROM moderators
            WHERE email = :email
        ");
        $stmt->execute(['email' => strtolower(trim($email))]);
        $moderator = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$moderator) {
            self::logSecurityEvent('mod_login_failed', null, 'User not found', ['email' => $email]);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        // Check if account is locked
        if ($moderator['locked_until'] && strtotime($moderator['locked_until']) > time()) {
            $minutesLeft = ceil((strtotime($moderator['locked_until']) - time()) / 60);
            self::logSecurityEvent('mod_login_locked', $moderator['id'], 'Account locked', [
                'minutes_left' => $minutesLeft,
            ]);
            return [
                'success' => false,
                'error' => "Account locked. Try again in {$minutesLeft} minutes.",
            ];
        }

        // Check if account is active
        if (!$moderator['is_active']) {
            self::logSecurityEvent('mod_login_inactive', $moderator['id'], 'Inactive account');
            return ['success' => false, 'error' => 'Account is deactivated'];
        }

        // Verify password
        if (!password_verify($password, $moderator['password_hash'])) {
            self::incrementFailedAttempts($pdo, $moderator['id']);
            self::logSecurityEvent('mod_login_failed', $moderator['id'], 'Wrong password');
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        // Password correct - reset failed attempts
        self::resetFailedAttempts($pdo, $moderator['id']);

        // ====================================================================
        // ENTERPRISE GALAXY: Cross-Platform Session Isolation
        // Check BEFORE sending 2FA code to not waste email if blocked
        // ====================================================================
        $xplatformCheck = CrossPlatformSessionService::canLogin(
            $moderator['email'],
            CrossPlatformSessionService::PLATFORM_MODERATOR
        );

        if (!$xplatformCheck['allowed']) {
            self::logSecurityEvent('mod_login_xplatform_blocked', $moderator['id'], 'Cross-platform session conflict', [
                'blocking_platform' => $xplatformCheck['blocking_platform'] ?? 'unknown',
            ]);
            return [
                'success' => false,
                'error' => $xplatformCheck['reason'],
                'xplatform_blocked' => true,
            ];
        }

        // Generate and send 2FA code
        $code = self::generate2FACode();
        self::store2FACode($pdo, $moderator['id'], $code);

        // Send 2FA email from moderation@need2talk.it
        $emailSent = self::send2FAEmail($moderator['email'], $moderator['display_name'] ?? $moderator['username'], $code);

        if (!$emailSent) {
            Logger::error('Failed to send 2FA email to moderator', [
                'moderator_id' => $moderator['id'],
                'email' => $moderator['email'],
            ]);
            return ['success' => false, 'error' => 'Failed to send verification code'];
        }

        // Store pending auth in session
        $_SESSION['mod_pending_auth'] = [
            'moderator_id' => $moderator['id'],
            'email' => $moderator['email'],
            'timestamp' => time(),
        ];

        self::logSecurityEvent('mod_2fa_sent', $moderator['id'], '2FA code sent');

        return [
            'success' => true,
            'requires_2fa' => true,
            'message' => 'Verification code sent to your email',
        ];
    }

    /**
     * Verify 2FA code and create session
     */
    public static function verify2FACode(string $code): array
    {
        if (!isset($_SESSION['mod_pending_auth'])) {
            return ['success' => false, 'error' => 'No pending authentication'];
        }

        $pending = $_SESSION['mod_pending_auth'];

        // Check if pending auth is still valid (5 minutes)
        if (time() - $pending['timestamp'] > self::CODE_TTL_SECONDS) {
            unset($_SESSION['mod_pending_auth']);
            return ['success' => false, 'error' => 'Verification expired. Please login again.'];
        }

        $pdo = db_pdo();
        $moderatorId = $pending['moderator_id'];

        // Verify code
        $stmt = $pdo->prepare("
            SELECT id, code_hash, expires_at
            FROM moderator_2fa_codes
            WHERE moderator_id = :moderator_id
            AND used_at IS NULL
            AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['moderator_id' => $moderatorId]);
        $codeRecord = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$codeRecord) {
            self::logSecurityEvent('mod_2fa_expired', $moderatorId, 'No valid 2FA code found');
            return ['success' => false, 'error' => 'Verification code expired'];
        }

        // Verify code hash
        if (!hash_equals($codeRecord['code_hash'], hash('sha256', $code))) {
            self::logSecurityEvent('mod_2fa_failed', $moderatorId, 'Wrong 2FA code');
            return ['success' => false, 'error' => 'Invalid verification code'];
        }

        // Mark code as used
        $stmt = $pdo->prepare("UPDATE moderator_2fa_codes SET used_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $codeRecord['id']]);

        // Create session
        $sessionToken = bin2hex(random_bytes(self::SESSION_TOKEN_LENGTH));
        $expiresAt = date('Y-m-d H:i:s', time() + (self::SESSION_TIMEOUT_MINUTES * 60));

        $stmt = $pdo->prepare("
            INSERT INTO moderator_sessions (
                moderator_id, session_token, ip_address, user_agent,
                expires_at, two_factor_verified, two_factor_verified_at
            ) VALUES (
                :moderator_id, :token, :ip, :ua,
                :expires_at, TRUE, NOW()
            )
        ");
        $stmt->execute([
            'moderator_id' => $moderatorId,
            'token' => $sessionToken,
            'ip' => get_server('REMOTE_ADDR'),
            'ua' => get_server('HTTP_USER_AGENT'),
            'expires_at' => $expiresAt,
        ]);

        // Update moderator last login
        $stmt = $pdo->prepare("
            UPDATE moderators
            SET last_login_at = NOW(),
                login_count = login_count + 1
            WHERE id = :id
        ");
        $stmt->execute(['id' => $moderatorId]);

        // Store session in PHP session
        $_SESSION['mod_session_token'] = $sessionToken;
        $_SESSION['mod_moderator_id'] = $moderatorId;
        unset($_SESSION['mod_pending_auth']);

        // ====================================================================
        // ENTERPRISE GALAXY: Register Cross-Platform Session
        // Prevents simultaneous login as User AND Moderator with same email
        // ====================================================================
        CrossPlatformSessionService::registerLogin(
            $pending['email'],
            CrossPlatformSessionService::PLATFORM_MODERATOR,
            $sessionToken,
            [
                'ip' => get_server('REMOTE_ADDR'),
                'user_agent' => get_server('HTTP_USER_AGENT'),
            ]
        );

        // Log action
        self::logModerationAction($moderatorId, 'login', null, null, [
            'ip' => get_server('REMOTE_ADDR'),
        ]);

        self::logSecurityEvent('mod_login_success', $moderatorId, 'Login successful');

        return [
            'success' => true,
            'message' => 'Login successful',
            'redirect' => self::generateModerationUrl() . '/dashboard',
        ];
    }

    /**
     * Validate current session
     */
    public static function validateSession(): ?array
    {
        if (!isset($_SESSION['mod_session_token']) || !isset($_SESSION['mod_moderator_id'])) {
            return null;
        }

        $pdo = db_pdo();

        $stmt = $pdo->prepare("
            SELECT
                ms.id AS session_id,
                ms.expires_at,
                ms.last_activity_at,
                m.id AS moderator_id,
                m.uuid,
                m.username,
                m.email,
                m.display_name,
                m.is_active,
                m.can_view_rooms,
                m.can_ban_users,
                m.can_delete_messages,
                m.can_manage_keywords,
                m.can_view_reports,
                m.can_resolve_reports,
                m.can_escalate_reports
            FROM moderator_sessions ms
            JOIN moderators m ON m.id = ms.moderator_id
            WHERE ms.session_token = :token
            AND ms.moderator_id = :moderator_id
            AND ms.expires_at > NOW()
            AND m.is_active = TRUE
        ");
        $stmt->execute([
            'token' => $_SESSION['mod_session_token'],
            'moderator_id' => $_SESSION['mod_moderator_id'],
        ]);

        $session = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$session) {
            // Invalid session - clear it
            unset($_SESSION['mod_session_token'], $_SESSION['mod_moderator_id']);
            return null;
        }

        // ENTERPRISE GALAXY: Sliding session expiration
        // Update last_activity AND extend expires_at on every activity
        // This implements a "sliding window" session that stays valid as long as user is active
        $newExpiresAt = date('Y-m-d H:i:s', time() + (self::SESSION_TIMEOUT_MINUTES * 60));
        $stmt = $pdo->prepare("
            UPDATE moderator_sessions
            SET last_activity_at = NOW(),
                expires_at = :new_expires_at
            WHERE session_token = :token
        ");
        $stmt->execute([
            'token' => $_SESSION['mod_session_token'],
            'new_expires_at' => $newExpiresAt,
        ]);

        // Update moderator last action
        $stmt = $pdo->prepare("UPDATE moderators SET last_action_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $session['moderator_id']]);

        return $session;
    }

    /**
     * Logout moderator
     */
    public static function logout(): void
    {
        if (isset($_SESSION['mod_session_token'])) {
            $pdo = db_pdo();

            // ====================================================================
            // ENTERPRISE GALAXY: Clear Cross-Platform Session
            // Get moderator email BEFORE deleting session for xplatform cleanup
            // ====================================================================
            $moderatorEmail = null;
            if (isset($_SESSION['mod_moderator_id'])) {
                $stmt = $pdo->prepare("SELECT email FROM moderators WHERE id = :id");
                $stmt->execute(['id' => $_SESSION['mod_moderator_id']]);
                $moderator = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($moderator) {
                    $moderatorEmail = $moderator['email'];
                }
            }

            // Delete session from database
            $stmt = $pdo->prepare("DELETE FROM moderator_sessions WHERE session_token = :token");
            $stmt->execute(['token' => $_SESSION['mod_session_token']]);

            // Log action
            if (isset($_SESSION['mod_moderator_id'])) {
                self::logModerationAction($_SESSION['mod_moderator_id'], 'logout', null, null, [
                    'ip' => get_server('REMOTE_ADDR'),
                ]);
            }

            // ====================================================================
            // ENTERPRISE GALAXY: Clear Cross-Platform Session
            // Allows login as User after logging out as Moderator
            // ====================================================================
            if ($moderatorEmail) {
                CrossPlatformSessionService::clearSession(
                    $moderatorEmail,
                    CrossPlatformSessionService::PLATFORM_MODERATOR
                );
            }
        }

        // Clear session
        unset($_SESSION['mod_session_token'], $_SESSION['mod_moderator_id'], $_SESSION['mod_pending_auth']);
    }

    /**
     * Get moderator by ID
     */
    public static function getModeratorById(int $id): ?array
    {
        $pdo = db_pdo();
        $stmt = $pdo->prepare("
            SELECT id, uuid, username, email, display_name, is_active,
                   can_view_rooms, can_ban_users, can_delete_messages,
                   can_manage_keywords, can_view_reports, can_resolve_reports,
                   can_escalate_reports
            FROM moderators
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Log moderation action to audit trail
     * ENTERPRISE GALAXY: Validates UUIDs before insertion to PostgreSQL
     */
    public static function logModerationAction(
        int $moderatorId,
        string $actionType,
        ?int $targetUserId = null,
        ?string $targetMessageUuid = null,
        array $details = []
    ): void {
        try {
            // ENTERPRISE FIX: Validate UUID format before insertion
            // PostgreSQL uuid type rejects non-UUID strings like "mod_1_xxx"
            $validUuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

            $safeMessageUuid = null;
            if ($targetMessageUuid !== null) {
                if (preg_match($validUuidPattern, $targetMessageUuid)) {
                    $safeMessageUuid = $targetMessageUuid;
                } else {
                    // Not a valid UUID, store in details instead
                    $details['message_id'] = $targetMessageUuid;
                }
            }

            $pdo = db_pdo();
            $stmt = $pdo->prepare("
                INSERT INTO moderation_actions_log (
                    moderator_id, action_type, target_user_id, target_message_uuid,
                    details, ip_address, created_at
                ) VALUES (
                    :moderator_id, :action_type, :target_user_id, :target_message_uuid,
                    :details, :ip_address, NOW()
                )
            ");
            $stmt->execute([
                'moderator_id' => $moderatorId,
                'action_type' => $actionType,
                'target_user_id' => $targetUserId,
                'target_message_uuid' => $safeMessageUuid,
                'details' => json_encode($details),
                'ip_address' => get_server('REMOTE_ADDR'),
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to log moderation action', [
                'error' => $e->getMessage(),
                'action_type' => $actionType,
            ]);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Get URL secret from environment
     */
    private static function getUrlSecret(): string
    {
        $secret = get_env('MOD_URL_SECRET');
        if (!$secret) {
            // Fallback: generate from APP_KEY + constant
            $appKey = get_env('APP_KEY') ?? 'need2talk_default_key';
            $secret = hash('sha256', $appKey . '_moderation_portal_secret');
        }
        return $secret;
    }

    /**
     * Generate 6-digit 2FA code
     */
    private static function generate2FACode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Store 2FA code in database
     * @param \PDO|AutoReleasePDO $pdo Database connection
     */
    private static function store2FACode($pdo, int $moderatorId, string $code): void
    {
        // Delete old codes for this moderator
        $stmt = $pdo->prepare("DELETE FROM moderator_2fa_codes WHERE moderator_id = :id");
        $stmt->execute(['id' => $moderatorId]);

        // Insert new code
        $expiresAt = date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS);
        $stmt = $pdo->prepare("
            INSERT INTO moderator_2fa_codes (moderator_id, code_hash, expires_at, ip_address)
            VALUES (:moderator_id, :code_hash, :expires_at, :ip)
        ");
        $stmt->execute([
            'moderator_id' => $moderatorId,
            'code_hash' => hash('sha256', $code),
            'expires_at' => $expiresAt,
            'ip' => get_server('REMOTE_ADDR'),
        ]);
    }

    /**
     * Send 2FA code via email from moderation@need2talk.it
     */
    private static function send2FAEmail(string $to, string $name, string $code): bool
    {
        try {
            // Use SendGrid API directly with moderation credentials
            $apiKey = get_env('SENDGRID_API_KEY');
            if (!$apiKey) {
                Logger::error('SENDGRID_API_KEY not configured for moderation emails');
                return false;
            }

            $emailData = [
                'personalizations' => [
                    [
                        'to' => [['email' => $to, 'name' => $name]],
                        'subject' => "need2talk Moderation - Your verification code: {$code}",
                    ],
                ],
                'from' => [
                    'email' => self::MODERATION_EMAIL_FROM,
                    'name' => self::MODERATION_EMAIL_NAME,
                ],
                'content' => [
                    [
                        'type' => 'text/html',
                        'value' => self::get2FAEmailTemplate($name, $code),
                    ],
                ],
                'tracking_settings' => [
                    'click_tracking' => ['enable' => false],
                    'open_tracking' => ['enable' => false],
                ],
            ];

            $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($emailData),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                Logger::info('Moderation 2FA email sent', ['to' => $to]);
                return true;
            }

            Logger::error('Moderation 2FA email failed', [
                'to' => $to,
                'http_code' => $httpCode,
                'response' => $response,
            ]);
            return false;
        } catch (\Exception $e) {
            Logger::error('Moderation 2FA email exception', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get 2FA email HTML template
     */
    private static function get2FAEmailTemplate(string $name, string $code): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f0f0f; color: #ffffff; padding: 20px; }
        .container { max-width: 500px; margin: 0 auto; background: #1a1a2e; border-radius: 12px; padding: 30px; border: 1px solid rgba(147, 51, 234, 0.3); }
        .logo { text-align: center; margin-bottom: 20px; }
        .logo span { font-size: 24px; font-weight: bold; background: linear-gradient(to right, #a855f7, #ec4899); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .badge { display: inline-block; background: rgba(147, 51, 234, 0.2); border: 1px solid rgba(147, 51, 234, 0.4); border-radius: 20px; padding: 4px 12px; font-size: 12px; color: #a855f7; margin-bottom: 20px; }
        h1 { color: #ffffff; font-size: 20px; margin-bottom: 10px; }
        p { color: #a0a0a0; line-height: 1.6; margin-bottom: 20px; }
        .code-box { background: #0f0f0f; border: 2px solid #a855f7; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0; }
        .code { font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #a855f7; font-family: monospace; }
        .warning { background: rgba(234, 179, 8, 0.1); border: 1px solid rgba(234, 179, 8, 0.3); border-radius: 8px; padding: 12px; margin-top: 20px; }
        .warning p { color: #eab308; font-size: 13px; margin: 0; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .footer p { color: #666; font-size: 12px; margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo"><span>need2talk</span></div>
        <div style="text-align: center;"><span class="badge">MODERATION PORTAL</span></div>

        <h1>Ciao {$name},</h1>
        <p>Ecco il tuo codice di verifica per accedere al Portale Moderazione:</p>

        <div class="code-box">
            <div class="code">{$code}</div>
        </div>

        <p>Questo codice scade tra <strong>5 minuti</strong>.</p>

        <div class="warning">
            <p>Se non hai richiesto questo codice, ignora questa email. Qualcuno potrebbe aver inserito la tua email per errore.</p>
        </div>

        <div class="footer">
            <p>need2talk Moderation Team</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Increment failed login attempts
     * @param \PDO|AutoReleasePDO $pdo Database connection
     */
    private static function incrementFailedAttempts($pdo, int $moderatorId): void
    {
        // PostgreSQL: INTERVAL must use concatenation or make_interval()
        // Cannot use placeholder inside INTERVAL string literal
        $lockoutMinutes = (int) self::LOCKOUT_MINUTES;
        $maxAttempts = (int) self::MAX_LOGIN_ATTEMPTS;

        $stmt = $pdo->prepare("
            UPDATE moderators
            SET failed_login_attempts = failed_login_attempts + 1,
                locked_until = CASE
                    WHEN failed_login_attempts + 1 >= {$maxAttempts}
                    THEN NOW() + INTERVAL '{$lockoutMinutes} minutes'
                    ELSE locked_until
                END
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $moderatorId,
        ]);
    }

    /**
     * Reset failed login attempts
     * @param \PDO|AutoReleasePDO $pdo Database connection
     */
    private static function resetFailedAttempts($pdo, int $moderatorId): void
    {
        $stmt = $pdo->prepare("
            UPDATE moderators
            SET failed_login_attempts = 0, locked_until = NULL
            WHERE id = :id
        ");
        $stmt->execute(['id' => $moderatorId]);
    }

    /**
     * Log security event
     */
    private static function logSecurityEvent(string $event, ?int $moderatorId, string $message, array $context = []): void
    {
        Logger::security('info', "MOD_SECURITY: {$event} - {$message}", array_merge([
            'moderator_id' => $moderatorId,
            'ip' => get_server('REMOTE_ADDR'),
            'user_agent' => get_server('HTTP_USER_AGENT'),
        ], $context));
    }
}
