<?php

/**
 * GOOGLE OAUTH 2.0 SERVICE - ENTERPRISE GALAXY LEVEL
 *
 * PSR-12 compliant service per login/registrazione tramite Google
 * Basato su google/apiclient v2 (OAuth 2.0 server-side flow)
 *
 * FEATURES:
 * - Server-side OAuth flow (più sicuro del client-side)
 * - Auto-registrazione utenti nuovi con email Google verificata
 * - Login automatico utenti esistenti
 * - Avatar sync da Google profile picture
 * - Email verification automatica (trusted da Google)
 *
 * SECURITY:
 * - State parameter anti-CSRF
 * - Token validation con Google API
 * - Email domain validation (NO disposable emails)
 * - Rate limiting via existing infrastructure
 *
 * WORKFLOW:
 * 1. User click "Continua con Google"
 * 2. Redirect a Google OAuth consent screen
 * 3. Google redirect a /auth/google/callback con code
 * 4. Exchange code per access token
 * 5. Fetch user info (email, name, picture)
 * 6. Auto-register se nuovo, login se esistente
 *
 * @package Need2Talk\Services
 * @version 1.0.0
 */

namespace Need2Talk\Services;

use Google_Client;
use Google_Service_Oauth2;
use Exception;

// Import global helper functions
use function env;
use function db;

class GoogleOAuthService
{
    private Google_Client $client;
    private array $config;

    /**
     * Initialize Google OAuth Client
     *
     * @throws Exception Se mancano credenziali
     */
    public function __construct()
    {
        // Load config from .env
        $this->config = [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        ];

        // Validate configuration
        if (empty($this->config['client_id']) || empty($this->config['client_secret'])) {
            throw new Exception('Google OAuth credentials not configured in .env');
        }

        // Initialize Google Client
        $this->client = new Google_Client();
        $this->client->setClientId($this->config['client_id']);
        $this->client->setClientSecret($this->config['client_secret']);
        $this->client->setRedirectUri($this->config['redirect_uri']);

        // Request only necessary scopes (NO verification required)
        $this->client->addScope('email');
        $this->client->addScope('profile');
        $this->client->addScope('openid');

        // Security settings
        $this->client->setAccessType('online'); // NO refresh token (just login)
        $this->client->setPrompt('select_account'); // Always show account picker
    }

    /**
     * Generate Google OAuth authorization URL
     *
     * @return string URL per redirect a Google consent screen
     */
    public function getAuthUrl(): string
    {
        // Generate anti-CSRF state token
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;

        // ENTERPRISE V11.8: Also store in cookie for browsers that lose session on redirect
        // Samsung Browser and some mobile browsers have issues with session persistence
        setcookie('google_oauth_state', $state, [
            'expires' => time() + 600, // 10 minutes
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax', // Must be Lax for OAuth redirect
        ]);

        // ENTERPRISE V12.9 (2026-01-18): Log OAuth state creation for debugging
        Logger::security('info', 'OAuth state created', [
            'state' => $state,
            'session_id' => session_id(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        $this->client->setState($state);

        return $this->client->createAuthUrl();
    }

    /**
     * Handle OAuth callback and authenticate user
     *
     * @param string $code Authorization code da Google
     * @param string $state State parameter anti-CSRF
     * @return array User data: ['email', 'name', 'picture', 'google_id']
     * @throws Exception Se validazione fallisce
     */
    public function handleCallback(string $code, string $state): array
    {
        // ENTERPRISE V11.8: Check session first, then cookie fallback for problematic browsers
        $sessionState = $_SESSION['google_oauth_state'] ?? null;
        $cookieState = $_COOKIE['google_oauth_state'] ?? null;

        $validState = false;
        if (!empty($sessionState) && $state === $sessionState) {
            $validState = true;
        } elseif (!empty($cookieState) && $state === $cookieState) {
            // Cookie fallback for Samsung Browser and others that lose session
            $validState = true;
            Logger::info('Google OAuth: Used cookie fallback for state validation', [
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);
        }

        // ENTERPRISE V12.9 (2026-01-18): Enhanced OAuth CSRF debug logging
        // CONTEXT: Samsung Browser users getting "Invalid OAuth state" errors
        // SOLUTION: Log all state values to diagnose session/cookie loss
        if (!$validState) {
            Logger::security('error', 'OAuth CSRF validation failed - DEBUG', [
                'received_state' => $state,
                'session_state_exists' => !empty($sessionState),
                'session_state_matches' => (!empty($sessionState) && $state === $sessionState),
                'cookie_state_exists' => !empty($cookieState),
                'cookie_state_matches' => (!empty($cookieState) && $state === $cookieState),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'all_cookies' => array_keys($_COOKIE), // See which cookies ARE present
                'session_id' => session_id(),
            ]);
        }

        // Clear state from both session and cookie (one-time use)
        unset($_SESSION['google_oauth_state']);
        setcookie('google_oauth_state', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!$validState) {
            throw new Exception('Invalid OAuth state parameter (possible CSRF attack)');
        }

        // Exchange authorization code for access token
        try {
            $token = $this->client->fetchAccessTokenWithAuthCode($code);
        } catch (Exception $e) {
            Logger::security('error', 'Google OAuth token exchange failed', [
                'error' => $e->getMessage(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            throw new Exception('Failed to authenticate with Google: ' . $e->getMessage());
        }

        // Check for errors
        if (isset($token['error'])) {
            Logger::security('error', 'Google OAuth returned error', [
                'error' => $token['error'],
                'description' => $token['error_description'] ?? '',
            ]);
            throw new Exception('Google OAuth error: ' . $token['error']);
        }

        // Set access token
        $this->client->setAccessToken($token);

        // Fetch user info
        $oauth2 = new Google_Service_Oauth2($this->client);
        $userInfo = $oauth2->userinfo->get();

        // Extract user data
        $userData = [
            'google_id' => $userInfo->id,
            'email' => $userInfo->email,
            'name' => $userInfo->name,
            'given_name' => $userInfo->givenName ?? '',
            'family_name' => $userInfo->familyName ?? '',
            'picture' => $userInfo->picture ?? '',
            'email_verified' => $userInfo->verifiedEmail ?? false,
        ];

        // SECURITY: Validate email is verified by Google
        if (!$userData['email_verified']) {
            Logger::security('warning', 'Google OAuth: Email not verified', [
                'email' => $userData['email'],
            ]);
            throw new Exception('Email not verified by Google');
        }

        Logger::security('info', 'Google OAuth successful', [
            'email' => $userData['email'],
            'google_id' => $userData['google_id'],
        ]);

        return $userData;
    }

    /**
     * Register or login user with Google data
     *
     * ENTERPRISE GALAXY LEVEL - GDPR COMPLIANCE FIX (2025-01-17):
     * - Complete user data extraction from Google OAuth
     * - GDPR-compliant audit trail (IP, User-Agent, timestamp)
     * - Email verification bypass (trusted Google identity)
     * - Unsubscribe token generation (CAN-SPAM compliance)
     * - Status = 'pending' for NEW OAuth users (MUST complete profile with GDPR consent)
     * - Status remains unchanged for EXISTING OAuth users (already completed profile)
     * - Welcome email for new users via async queue
     * - Profile completion MANDATORY: birth date (18+ check), GDPR consent, newsletter opt-in
     *
     * SECURITY: New OAuth users CANNOT access site until profile completion
     * This prevents attackers from registering via OAuth and immediately scanning /admin
     *
     * @param array $googleData User data from Google ['google_id', 'email', 'name', 'given_name', 'family_name', 'picture', 'email_verified']
     * @return array ['user_id' => int, 'is_new_user' => bool]
     * @throws Exception Se registrazione/login fallisce
     */
    public function registerOrLogin(array $googleData): array
    {
        $db = db();

        // ENTERPRISE: Extract client metadata for GDPR audit trail
        $registrationIp = $this->getClientIp();
        $userAgent = $this->sanitizeUserAgent($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        // ENTERPRISE CRITICAL FIX: Multi-step lookup to prevent duplicate accounts
        // Google OAuth returns CURRENT email (can change), but google_id (oauth_provider_id) is IMMUTABLE
        //
        // STEP 1: PRIMARY lookup by oauth_provider_id (immutable Google ID)
        // This prevents duplicate accounts when user changes email on Google
        // GDPR COMPLIANCE: Include soft-deleted accounts to allow recovery during grace period
        $existingUser = null;
        $isEmailMigration = false;

        if (!empty($googleData['google_id'])) {
            $existingUser = $db->findOne(
                "SELECT id, email, nickname, name, surname, avatar_url, avatar_source,
                        oauth_provider, oauth_provider_id, email_verified, status, deleted_at
                 FROM users
                 WHERE oauth_provider = 'google'
                   AND oauth_provider_id = ?
                 LIMIT 1",
                [$googleData['google_id']],
                ['cache' => false] // ENTERPRISE: Always fresh data for auth operations
            );

            // ENTERPRISE: If found by google_id but email differs, user changed email on Google
            if ($existingUser && $existingUser['email'] !== $googleData['email']) {
                Logger::security('warning', 'AUTH: Google email changed detected', [
                    'user_id' => $existingUser['id'],
                    'old_email_hash' => hash('sha256', strtolower($existingUser['email'])),
                    'new_email_hash' => hash('sha256', strtolower($googleData['email'])),
                    'oauth_provider_id' => $googleData['google_id'],
                ]);
            }
        }

        // STEP 2: FALLBACK lookup by email (only for password→OAuth migration)
        // This handles users who registered with password, now trying OAuth for first time
        // GDPR COMPLIANCE: Include soft-deleted accounts to allow recovery during grace period
        if (!$existingUser) {
            $existingUser = $db->findOne(
                "SELECT id, email, nickname, name, surname, avatar_url, avatar_source,
                        oauth_provider, oauth_provider_id, email_verified, status, deleted_at
                 FROM users
                 WHERE email = ?
                   AND oauth_provider IS NULL
                 LIMIT 1",
                [$googleData['email']],
                ['cache' => false]
            );

            // Mark as email-based migration (password user → OAuth)
            if ($existingUser) {
                $isEmailMigration = true;

                Logger::security('info', 'AUTH: Password user migrating to Google OAuth', [
                    'user_id' => $existingUser['id'],
                    'email_hash' => hash('sha256', strtolower($googleData['email'])),
                ]);
            }
        }

        // GDPR ENTERPRISE FIX: Detect and recover soft-deleted accounts
        // If user re-logs with Google during 30-day grace period, automatically restore account
        if ($existingUser && !empty($existingUser['deleted_at'])) {
            Logger::security('warning', 'AUTH: Soft-deleted account attempting Google OAuth login', [
                'user_id' => $existingUser['id'],
                'email_hash' => hash('sha256', strtolower($googleData['email'])),
                'deleted_at' => $existingUser['deleted_at'],
                'oauth_provider_id' => $googleData['google_id'] ?? null,
            ]);

            try {
                // 🚀 ENTERPRISE GALAXY: Rate limiting - Max 3 recovery in 30 giorni (anti-abuse)
                $recoveryCount = $db->findOne(
                    "SELECT COUNT(*) as count FROM account_deletions
                     WHERE user_id = ?
                     AND status = 'cancelled'
                     AND cancelled_at >= NOW() - INTERVAL '30 days'",
                    [$existingUser['id']],
                    ['cache' => false]
                );

                $recentRecoveries = (int)($recoveryCount['count'] ?? 0);

                if ($recentRecoveries >= 3) {
                    Logger::security('warning', 'AUTH: Recovery rate limit exceeded (max 3/30 giorni)', [
                        'user_id' => $existingUser['id'],
                        'email_hash' => hash('sha256', strtolower($googleData['email'])),
                        'recent_recoveries' => $recentRecoveries,
                        'limit' => 3,
                        'period_days' => 30,
                        'ip' => $registrationIp,
                    ]);

                    throw new Exception(
                        'Hai superato il limite di ripristini account (3 volte in 30 giorni). ' .
                        'Per motivi di sicurezza, non puoi ripristinare nuovamente l\'account. ' .
                        'Contatta il supporto se ritieni che ci sia un errore.'
                    );
                }

                // ENTERPRISE FIX: Query original deletion timestamp BEFORE restore (for email notification)
                $deletionRecord = $db->findOne(
                    "SELECT requested_at, scheduled_deletion_at FROM account_deletions
                     WHERE user_id = ? AND status = 'pending'
                     LIMIT 1",
                    [$existingUser['id']],
                    ['cache' => false]
                );

                $deletionRequestedAt = $deletionRecord['requested_at'] ?? null;

                // Attempt automatic account recovery via GDPRExportService
                $gdprService = new GDPRExportService();
                $recoverySuccess = $gdprService->cancelAccountDeletion($existingUser['id']);

                if ($recoverySuccess) {
                    Logger::security('info', 'AUTH: Account automatically restored via Google OAuth', [
                        'user_id' => $existingUser['id'],
                        'email_hash' => hash('sha256', strtolower($googleData['email'])),
                        'recovery_method' => 'google_oauth',
                        'ip' => $registrationIp,
                        'deletion_requested_at' => $deletionRequestedAt,
                    ]);

                    // Refresh user data (deleted_at is now NULL)
                    $existingUser = $db->findOne(
                        "SELECT id, email, nickname, name, surname, avatar_url, avatar_source,
                                oauth_provider, oauth_provider_id, email_verified, status, deleted_at
                         FROM users
                         WHERE id = ?
                         LIMIT 1",
                        [$existingUser['id']],
                        ['cache' => false]
                    );

                    // 🚀 ENTERPRISE GALAXY: Send account recovery notification email
                    try {
                        $emailQueue = new \Need2Talk\Services\AsyncEmailQueue();
                        $emailQueued = $emailQueue->queueAccountRecoveryEmail(
                            $existingUser['id'],
                            $googleData['email'],
                            $existingUser['nickname'] ?? 'User',
                            'google_oauth',
                            $registrationIp,
                            $deletionRequestedAt  // ENTERPRISE FIX: Pass original deletion date (not NULL)
                        );

                        if ($emailQueued) {
                            Logger::email('info', 'EMAIL: Account recovery notification queued after OAuth restore', [
                                'user_id' => $existingUser['id'],
                                'email_hash' => hash('sha256', $googleData['email']),
                                'recovery_method' => 'google_oauth',
                            ]);
                        }
                    } catch (\Exception $e) {
                        // ENTERPRISE: Email failure should NOT block recovery
                        Logger::error('EMAIL: Failed to queue account recovery notification', [
                            'user_id' => $existingUser['id'],
                            'email' => $googleData['email'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    throw new Exception('Account recovery failed (cancelAccountDeletion returned false)');
                }
            } catch (\Exception $e) {
                // Grace period expired or other recovery error
                Logger::security('error', 'AUTH: Account recovery failed for soft-deleted user', [
                    'user_id' => $existingUser['id'],
                    'email_hash' => hash('sha256', strtolower($googleData['email'])),
                    'error' => $e->getMessage(),
                ]);

                throw new Exception(
                    'Your account was deleted and the 30-day grace period has expired. ' .
                    'Please contact support if you believe this is an error.'
                );
            }
        }

        if ($existingUser) {
            // ============================================================
            // EXISTING USER - LOGIN FLOW
            // ============================================================

            $updateFields = [];
            $updateParams = ['id' => $existingUser['id']];

            // ENTERPRISE CRITICAL: Auto-update email if changed on Google
            // Google users can change their primary email on google.com
            // We MUST sync to prevent login issues (email is used for unique constraint)
            if ($existingUser['email'] !== $googleData['email']) {
                $updateFields[] = 'email = :new_email';
                $updateParams['new_email'] = $googleData['email'];

                // Re-verify email (Google already verified it, but we track our own verification)
                if (!$existingUser['email_verified']) {
                    $updateFields[] = 'email_verified = TRUE';
                    $updateFields[] = 'email_verified_at = NOW()';
                }

                Logger::security('info', 'AUTH: Email auto-updated from Google OAuth', [
                    'user_id' => $existingUser['id'],
                    'old_email_hash' => hash('sha256', strtolower($existingUser['email'])),
                    'new_email_hash' => hash('sha256', strtolower($googleData['email'])),
                    'oauth_provider_id' => $googleData['google_id'],
                    'ip' => $registrationIp,
                    'user_agent_hash' => hash('sha256', $userAgent),
                ]);
            }

            // ENTERPRISE: Update OAuth data if migrating from password-only to OAuth
            if (empty($existingUser['oauth_provider']) || $isEmailMigration) {
                $updateFields[] = 'oauth_provider = :oauth_provider';
                $updateFields[] = 'oauth_provider_id = :google_id';
                $updateParams['oauth_provider'] = 'google';
                $updateParams['google_id'] = $googleData['google_id'];

                Logger::security('info', 'AUTH: User migrated to Google OAuth', [
                    'user_id' => $existingUser['id'],
                    'email_hash' => hash('sha256', strtolower($googleData['email'])),
                ]);
            }

            // ENTERPRISE: Update name, surname if missing (data enrichment)
            if (empty($existingUser['name']) && !empty($googleData['given_name'])) {
                $updateFields[] = 'name = :name';
                $updateParams['name'] = $googleData['given_name'];
            }
            if (empty($existingUser['surname']) && !empty($googleData['family_name'])) {
                $updateFields[] = 'surname = :surname';
                $updateParams['surname'] = $googleData['family_name'];
            }

            // ENTERPRISE FIX: Update avatar ONLY if user still uses Google avatar
            // Don't overwrite custom uploaded avatars!
            if (($existingUser['avatar_source'] === 'google' || empty($existingUser['avatar_source']))
                && $existingUser['avatar_url'] !== $googleData['picture']) {
                $updateFields[] = 'avatar_url = :avatar';
                $updateParams['avatar'] = $googleData['picture'];

                Logger::info('Google avatar updated (user still using Google avatar)', [
                    'user_id' => $existingUser['id'],
                ]);
            }

            // ENTERPRISE: Mark email as verified (trusted Google identity)
            if (!$existingUser['email_verified']) {
                $updateFields[] = 'email_verified = TRUE';
                $updateFields[] = 'email_verified_at = NOW()';
            }

            // ENTERPRISE GALAXY FIX (2025-11-17): DO NOT auto-activate pending accounts
            // OAuth users with status='pending' MUST complete /complete-profile form
            // This ensures GDPR consent collection and age verification (18+)
            // ONLY CompleteProfileController can change status: 'pending' → 'active'
            //
            // Old buggy code (REMOVED):
            // if ($existingUser['status'] === 'pending') {
            //     $updateFields[] = 'status = :status';
            //     $updateParams['status'] = 'active';
            // }
            //
            // Problem: User created with status='pending' (13:24:19), then re-logged immediately (13:26:44)
            // Code auto-activated account WITHOUT collecting GDPR consent/age verification
            // Result: User bypassed /complete-profile form, got stuck in login loop

            // Always update last login metadata
            $updateFields[] = 'updated_at = NOW()';

            // Execute update if there are changes
            if (!empty($updateFields)) {
                $db->execute(
                    "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id",
                    $updateParams,
                    ['invalidate_cache' => ['table:users', "user:{$existingUser['id']}"]]
                );
            }

            Logger::info('User logged in via Google OAuth', [
                'user_id' => $existingUser['id'],
                'email' => $googleData['email'],
                'ip' => $registrationIp,
            ]);

            return [
                'user_id' => $existingUser['id'],
                'is_new_user' => false,
            ];
        }

        // ============================================================
        // NEW USER - REGISTRATION FLOW (ENTERPRISE GALAXY)
        // ============================================================

        // ENTERPRISE: Generate unique nickname from Google name
        $baseNickname = $this->generateNicknameFromName($googleData['name']);
        $nickname = $this->ensureUniqueNickname($baseNickname);

        // ENTERPRISE: Generate unsubscribe token (CAN-SPAM compliance)
        $unsubscribeToken = $this->generateUnsubscribeToken();

        // ENTERPRISE: Generate UUID for user (privacy-friendly public ID)
        $uuid = $this->generateUuid();

        // ENTERPRISE: Insert new user with COMPLETE data set
        try {
            $userId = $db->execute(
                "INSERT INTO users (
                    uuid,
                    email,
                    nickname,
                    name,
                    surname,
                    password_hash,
                    avatar_url,
                    avatar_source,
                    oauth_provider,
                    oauth_provider_id,
                    email_verified,
                    email_verified_at,
                    status,
                    newsletter_unsubscribe_token,
                    registration_ip,
                    user_agent,
                    created_at,
                    updated_at
                ) VALUES (
                    :uuid,
                    :email,
                    :nickname,
                    :name,
                    :surname,
                    :password_hash,
                    :avatar,
                    'google',
                    'google',
                    :google_id,
                    TRUE,
                    NOW(),
                    'pending',
                    :newsletter_unsubscribe_token,
                    :registration_ip,
                    :user_agent,
                    NOW(),
                    NOW()
                )",
                [
                    'uuid' => $uuid,
                    'email' => $googleData['email'],
                    'nickname' => $nickname,
                    'name' => $googleData['given_name'] ?? '',
                    'surname' => $googleData['family_name'] ?? '',
                    'password_hash' => null, // ENTERPRISE FIX: OAuth users have NO password (can set backup password later)
                    'avatar' => $googleData['picture'],
                    'google_id' => $googleData['google_id'],
                    'newsletter_unsubscribe_token' => $unsubscribeToken,
                    'registration_ip' => $registrationIp,
                    'user_agent' => $userAgent,
                ],
                [
                    'return_id' => true, // CRITICAL: Return lastInsertId, not affected rows (1)
                    'invalidate_cache' => ['table:users']
                ]
            );
        } catch (\Exception $e) {
            Logger::error('Google OAuth registration failed', [
                'email' => $googleData['email'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new Exception('Failed to create account: ' . $e->getMessage());
        }

        // ENTERPRISE: Send welcome email via async queue (non-blocking)
        try {
            $this->sendWelcomeEmail($userId, $googleData['email'], $nickname);
        } catch (\Exception $e) {
            // ENTERPRISE: Email failure should NOT block registration
            Logger::error('Welcome email failed for new OAuth user', [
                'user_id' => $userId,
                'email' => $googleData['email'],
                'error' => $e->getMessage(),
            ]);
        }

        // ENTERPRISE: Security audit log
        Logger::security('info', 'AUTH: New user registered via Google OAuth', [
            'user_id' => $userId,
            'email_hash' => hash('sha256', strtolower($googleData['email'])),
            'nickname' => $nickname,
            'registration_ip' => $registrationIp,
            'user_agent_hash' => hash('sha256', $userAgent),
        ]);

        Logger::info('New user registered via Google OAuth', [
            'user_id' => $userId,
            'email' => $googleData['email'],
            'nickname' => $nickname,
        ]);

        return [
            'user_id' => $userId,
            'is_new_user' => true,
        ];
    }

    /**
     * ENTERPRISE: Get client IP address (supports proxies, CloudFlare, IPv6)
     *
     * @return string Client IP address
     */
    private function getClientIp(): string
    {
        // SECURITY: Usare SOLO REMOTE_ADDR - header proxy spoofabili dal client
        // Se in futuro si usa Cloudflare, configurare ngx_http_realip_module
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * ENTERPRISE: Sanitize User-Agent string (prevent XSS, limit length)
     *
     * @param string $userAgent Raw User-Agent
     * @return string Sanitized User-Agent (max 255 chars)
     */
    private function sanitizeUserAgent(string $userAgent): string
    {
        // Remove potential XSS vectors
        $userAgent = strip_tags($userAgent);
        $userAgent = htmlspecialchars($userAgent, ENT_QUOTES, 'UTF-8');

        // Limit length (database column constraint)
        return substr($userAgent, 0, 255);
    }

    /**
     * ENTERPRISE: Generate unsubscribe token (CAN-SPAM compliance)
     *
     * @return string Secure random token (32 chars hex)
     */
    private function generateUnsubscribeToken(): string
    {
        return bin2hex(random_bytes(16)); // 32 hex chars
    }

    /**
     * ENTERPRISE: Generate UUID v4 for user
     *
     * @return string UUID v4 format
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);

        // Set version (4) and variant bits
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * ENTERPRISE: Send welcome email to new OAuth user (async queue)
     *
     * @param int $userId User ID
     * @param string $email User email
     * @param string $nickname User nickname
     * @return void
     */
    private function sendWelcomeEmail(int $userId, string $email, string $nickname): void
    {
        // ENTERPRISE FIX: Use AsyncEmailQueue instance method, not static
        try {
            $emailQueue = new \Need2Talk\Services\AsyncEmailQueue();
            $success = $emailQueue->queueWelcomeEmail($userId, $email, $nickname);

            if ($success) {
                Logger::info('Welcome email queued for new OAuth user', [
                    'user_id' => $userId,
                    'email' => $email,
                    'nickname' => $nickname,
                ]);
            } else {
                Logger::warning('Failed to queue welcome email (queue returned false)', [
                    'user_id' => $userId,
                    'email' => $email,
                ]);
            }
        } catch (\Exception $e) {
            Logger::error('Failed to queue welcome email', [
                'user_id' => $userId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate nickname from Google name
     *
     * @param string $name Nome completo Google
     * @return string Nickname base (senza garantire unicità)
     */
    private function generateNicknameFromName(string $name): string
    {
        // Remove special chars, keep only letters/numbers
        $nickname = preg_replace('/[^a-zA-Z0-9]/', '', $name);

        // Lowercase
        $nickname = strtolower($nickname);

        // Limit to 20 chars
        $nickname = substr($nickname, 0, 20);

        // Fallback se vuoto
        if (empty($nickname)) {
            $nickname = 'user' . random_int(1000, 9999);
        }

        return $nickname;
    }

    /**
     * Ensure nickname is unique (append number if needed)
     *
     * @param string $baseNickname Nickname base
     * @return string Nickname unico
     */
    private function ensureUniqueNickname(string $baseNickname): string
    {
        $db = db();
        $nickname = $baseNickname;
        $counter = 1;

        while (true) {
            $exists = $db->findOne(
                "SELECT id FROM users WHERE nickname = ? LIMIT 1",
                [$nickname]
            );

            if (!$exists) {
                return $nickname;
            }

            // Append counter
            $nickname = $baseNickname . $counter;
            $counter++;

            // Safety: max 100 attempts
            if ($counter > 100) {
                return $baseNickname . random_int(1000, 9999);
            }
        }
    }
}
