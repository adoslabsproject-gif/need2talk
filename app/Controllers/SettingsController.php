<?php

namespace Need2Talk\Controllers;

use Exception;
use Need2Talk\Core\BaseController;
use Need2Talk\Models\User;
use Need2Talk\Models\UserSettings;
use Need2Talk\Services\AvatarService;
use Need2Talk\Services\GDPRExportService;
use Need2Talk\Services\Logger;
use Need2Talk\Services\SettingsValidationService;

/**
 * SettingsController - Enterprise Galaxy
 *
 * Comprehensive user settings management:
 * - Account settings (nickname, email, avatar, delete account)
 * - Privacy settings (profile visibility, tabs visibility)
 * - Notification preferences (email, push)
 * - Security settings (password change, 2FA)
 * - GDPR operations (data export, account deletion)
 *
 * ARCHITECTURE:
 * - /settings → Settings index (landing page with sidebar)
 * - /settings/account → Account settings
 * - /settings/privacy → Privacy settings
 * - /settings/notifications → Notification preferences
 * - /settings/security → Security settings (password, 2FA)
 * - /settings/data-export → GDPR data export & account deletion
 *
 * ENTERPRISE FEATURES:
 * - Granular tab visibility control (6 profile tabs)
 * - OAuth-aware nickname limits (1 cambio for Google users)
 * - Email change with verification flow
 * - 30-day grace period for account deletion
 * - Comprehensive data export (JSON + media files)
 * - Multi-layer validation (format + business rules)
 *
 * SECURITY:
 * - CSRF protection (global middleware)
 * - Input validation and sanitization
 * - Rate limiting on sensitive operations
 * - Password confirmation for critical changes
 * - Audit logging for security events
 *
 * SCALABILITY: 100,000+ concurrent users
 *
 * @package Need2Talk\Controllers
 * @version 1.0.0
 * @author Claude Code (AI-Orchestrated Development)
 */
class SettingsController extends BaseController
{
    /**
     * User model
     */
    private User $userModel;

    /**
     * UserSettings model
     */
    private UserSettings $settingsModel;

    /**
     * Avatar service
     */
    private AvatarService $avatarService;

    /**
     * GDPR export service
     */
    private GDPRExportService $gdprService;

    /**
     * Settings validation service
     */
    private SettingsValidationService $validator;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->userModel = new User();
        $this->settingsModel = new UserSettings();
        $this->avatarService = new AvatarService();
        $this->gdprService = new GDPRExportService();
        $this->validator = new SettingsValidationService();
    }

    /**
     * Settings index page (landing page with sidebar)
     *
     * GET /settings
     *
     * Shows overview of all settings sections with sidebar navigation
     *
     * @return void
     */
    public function index(): void
    {
        $user = $this->requireAuth();

        // Get user settings
        $settings = $this->settingsModel->findByUserId($user['id']);

        // Check if account deletion pending
        $db = db();
        $pendingDeletion = $db->findOne(
            "SELECT scheduled_deletion_at FROM account_deletions
             WHERE user_id = ? AND status = 'pending'",
            [$user['id']]
        );

        // Get nickname change info (OAuth users: max 1 cambio)
        $nicknameChangeInfo = [
            'can_change' => $this->userModel->canChangeNickname($user['id']),
            'change_count' => $user['nickname_change_count'] ?? 0,
            'is_oauth_user' => !empty($user['oauth_provider']),
            'last_changed_at' => $user['nickname_changed_at'] ?? null,
        ];

        $this->render('settings/index', [
            'user' => $user,
            'settings' => $settings,
            'pending_deletion' => $pendingDeletion,
            'nickname_change_info' => $nicknameChangeInfo,
            'page_title' => 'Settings',
        ]);
    }

    /**
     * Account settings page
     *
     * GET /settings/account
     *
     * Nickname, email, avatar, delete account
     *
     * @return void
     */
    public function account(): void
    {
        $user = $this->requireAuth();

        // Get nickname change info
        $nicknameChangeInfo = [
            'can_change' => $this->userModel->canChangeNickname($user['id']),
            'change_count' => $user['nickname_change_count'] ?? 0,
            'is_oauth_user' => !empty($user['oauth_provider']),
            'oauth_provider' => $user['oauth_provider'] ?? null,
            'last_changed_at' => $user['nickname_changed_at'] ?? null,
        ];

        // Get avatar URL (ENTERPRISE UUID-based)
        $avatarUrl = $this->avatarService->getAvatarUrl($user['uuid'], 'medium');
        $isGoogleAvatar = AvatarService::isGoogleAvatar($user['avatar_url'] ?? '');

        $this->render('settings/account', [
            'user' => $user,
            'nickname_change_info' => $nicknameChangeInfo,
            'avatar_url' => $avatarUrl,
            'is_google_avatar' => $isGoogleAvatar,
            'page_title' => 'Account Settings',
        ]);
    }

    /**
     * Update nickname
     *
     * POST /settings/account/nickname
     *
     * @return void
     */
    public function updateNickname(): void
    {
        $user = $this->requireAuth();

        // ENTERPRISE FIX: Use getInput() to support both POST and JSON requests
        $newNickname = SettingsValidationService::sanitizeInput($this->getInput('nickname', ''));

        // Validate nickname
        $validation = $this->validator->validateNicknameChange($user['id'], $newNickname);

        if (!$validation['valid']) {
            $this->jsonResponse([
                'success' => false,
                'errors' => $validation['errors'],
            ], 400);
            return;
        }

        // Update nickname
        $success = $this->userModel->changeNickname($user['id'], $newNickname);

        if ($success) {
            // ENTERPRISE GALAXY (2025-01-23): Invalidate cache instead of session write
            invalidate_user_cache($user['id'], ['data', 'profile', 'settings']);

            Logger::info('Nickname changed successfully', [
                'user_id' => $user['id'],
                'old_nickname' => $user['nickname'],
                'new_nickname' => $newNickname,
                'is_oauth_user' => !empty($user['oauth_provider']),
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Nickname changed successfully',
                'new_nickname' => $newNickname,
                'can_change_again' => $this->userModel->canChangeNickname($user['id']),
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['Failed to update nickname'],
            ], 500);
        }
    }

    /**
     * Check nickname availability (real-time validation)
     *
     * GET /api/settings/check-nickname?nickname=username
     *
     * Enterprise: Fast query with index on nickname column
     * Security: Excludes current user from check
     *
     * @return void
     */
    public function checkNicknameAvailability(): void
    {
        $user = $this->requireAuth();

        $nickname = SettingsValidationService::sanitizeInput($_GET['nickname'] ?? '');

        // Basic format validation
        if (strlen($nickname) < 3 || strlen($nickname) > 50) {
            $this->jsonResponse([
                'available' => false,
                'message' => 'Nickname must be 3-50 characters',
            ]);
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $nickname)) {
            $this->jsonResponse([
                'available' => false,
                'message' => 'Solo lettere, numeri, underscore e trattini',
            ]);
            return;
        }

        // Check if nickname is same as current (always available)
        if ($nickname === $user['nickname']) {
            $this->jsonResponse([
                'available' => true,
                'message' => 'This is your current nickname',
            ]);
            return;
        }

        // Enterprise query: Check if nickname exists (excluding current user)
        $db = db();
        $exists = $db->findOne(
            "SELECT id FROM users
             WHERE nickname = ? AND id != ? AND deleted_at IS NULL",
            [$nickname, $user['id']],
            ['cache' => true, 'cache_ttl' => 'short'] // 5min cache for performance
        );

        if ($exists) {
            $this->jsonResponse([
                'available' => false,
                'message' => 'Nickname already taken',
            ]);
        } else {
            $this->jsonResponse([
                'available' => true,
                'message' => 'Nickname available',
            ]);
        }
    }

    /**
     * Request email change (sends verification email)
     *
     * POST /settings/account/email
     *
     * @return void
     */
    public function requestEmailChange(): void
    {
        $user = $this->requireAuth();

        // ENTERPRISE CRITICAL: Block OAuth users from changing email manually
        // Email is managed by identity provider (Google, Facebook, etc.)
        if (!empty($user['oauth_provider'])) {
            $providerName = ucfirst($user['oauth_provider']);

            $this->jsonResponse([
                'success' => false,
                'errors' => ["Stai usando accesso con {$providerName}. L'indirizzo email è gestito dal tuo account {$providerName}."],
                'oauth_provider' => $user['oauth_provider'],
                'blocked_reason' => 'oauth_user',
            ], 403); // 403 Forbidden
            return;
        }

        // Get new email from input (supports both POST and JSON)
        $newEmail = SettingsValidationService::sanitizeInput($this->getInput('new_email', ''));

        // ENTERPRISE: Use EmailChangeVerificationService for all logic
        try {
            $emailChangeService = new \Need2Talk\Services\EmailChangeVerificationService();

            // Request email change (validates, creates request, sends email)
            $result = $emailChangeService->requestEmailChange($user['id'], $newEmail);

            if (!$result['success']) {
                // Return validation errors or constraints (cooldown, pending request, etc.)
                $statusCode = isset($result['reason']) && $result['reason'] === 'cooldown' ? 429 : 400;

                $this->jsonResponse([
                    'success' => false,
                    'errors' => $result['errors'],
                    'reason' => $result['reason'] ?? null,
                    'data' => $result['data'] ?? null,
                ], $statusCode);
                return;
            }

            // Success - email verification sent
            $this->jsonResponse([
                'success' => true,
                'message' => $result['message'],
                'expires_in_hours' => $result['expires_in_hours'],
            ]);

        } catch (Exception $e) {
            Logger::error('Email change request failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => ['Errore durante la richiesta di cambio email. Riprova più tardi.'],
            ], 500);
        }
    }

    /**
     * Confirm email change (verify token)
     *
     * GET /settings/account/email/confirm/{token}
     *
     * ENTERPRISE: Uses EmailChangeVerificationService for token validation
     *
     * @param string $token Email change verification token (64 hex chars)
     * @return void
     */
    public function confirmEmailChange(string $token): void
    {
        // ENTERPRISE: Email confirmation doesn't require auth
        // User might be logged out when clicking link from email
        // Token validation is sufficient for security

        try {
            $emailChangeService = new \Need2Talk\Services\EmailChangeVerificationService();

            // Verify and confirm email change
            $result = $emailChangeService->verifyAndConfirmChange($token);

            if ($result['success']) {
                // ENTERPRISE: Force cache bypass for next user load
                // User email just changed, need fresh data from database
                $_SESSION['_user_cache_bypass_until'] = time() + 60;

                // ENTERPRISE GALAXY (2025-01-23): Invalidate cache after avatar upload
                if (isset($result['user_id'])) {
                    invalidate_user_cache($result['user_id'], ['data', 'profile']);
                    // Smart pre-warming with 1min TTL (avatar is write-heavy operation)
                    warm_user_cache($result['user_id'], 'profile');
                }

                $this->flashSuccess($result['message']);
                $this->redirect(url('/settings/account'));
            } else {
                $this->flashError($result['message']);
                $this->redirect(url('/settings/account'));
            }

        } catch (Exception $e) {
            Logger::error('Email confirmation failed', [
                'token_hash' => hash('sha256', $token),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->flashError('Errore durante la conferma del cambio email. Riprova più tardi.');
            $this->redirect(url('/settings/account'));
        }
    }

    /**
     * Upload avatar
     *
     * POST /settings/account/avatar
     *
     * @return void
     */
    public function uploadAvatar(): void
    {
        $user = $this->requireAuth();

        // Check if file uploaded
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['No file uploaded or upload error'],
            ], 400);
            return;
        }

        try {
            // Upload avatar via AvatarService (ENTERPRISE UUID-based)
            $result = $this->avatarService->uploadAvatar($user['uuid'], $_FILES['avatar']);

            // ENTERPRISE SOLUTION: Force fresh user reload from database
            // Instead of trying to invalidate cache (complex, fragile with Redis prefixes),
            // we set a timestamp-based session flag that forces ALL user queries to skip cache
            // This guarantees the next request gets fresh data from database

            // ENTERPRISE GALAXY (2025-01-23): Invalidate cache + smart pre-warming
            invalidate_user_cache($user['id'], ['data', 'profile', 'settings']);
            warm_user_cache($user['id'], 'profile'); // 1min TTL for write-heavy operation

            // ENTERPRISE GALAXY: Real-time WebSocket notification (Hybrid System)
            // Opzione B: Notifica utente + followers con feed aperto (5ms overhead per ~50 followers online)
            // Session Flag + WebSocket = Best of both worlds (30s force refresh + real-time updates)

            // Step 4A: Notifica l'utente stesso (tutte le sue tab/device)
            // Aggiorna avatar in tempo reale: navbar, sidebar, feed posts propri
            \Need2Talk\Services\WebSocketPublisher::publishToUser($user['uuid'], 'avatar_updated', [
                'user_uuid' => $user['uuid'],
                'avatar_url' => $result['avatar_url'],
                'thumbnail_small' => $result['thumbnail_small'],
                'thumbnail_medium' => $result['thumbnail_medium'],
                'timestamp' => microtime(true),
            ]);

            // Step 4B: Notifica followers che hanno il feed aperto (OPTIONAL - ENTERPRISE SMART)
            // Solo followers con feed aperto vedranno l'avatar aggiornato in real-time
            // Altri vedranno l'aggiornamento al prossimo reload (cache 5-30min)
            \Need2Talk\Services\WebSocketPublisher::publishToFollowers($user['uuid'], 'user_avatar_updated', [
                'user_uuid' => $user['uuid'],
                'avatar_url' => $result['avatar_url'],
                'thumbnail_small' => $result['thumbnail_small'],
                'timestamp' => microtime(true),
            ]);

            // ENTERPRISE PSR-3 LOGGING
            Logger::info('Avatar updated with real-time notification', [
                'user_uuid' => $user['uuid'],
                'user_id' => $user['id'],
                'avatar_url' => $result['avatar_url'],
                'websocket_published' => true,
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Avatar uploaded successfully',
                'avatar_url' => $result['avatar_url'],
                'thumbnail_small' => $result['thumbnail_small'],
                'thumbnail_medium' => $result['thumbnail_medium'],
            ]);
        } catch (Exception $e) {
            Logger::error('Avatar upload failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }

    /**
     * Privacy settings page
     *
     * GET /settings/privacy
     *
     * Profile visibility, activity visibility, tabs visibility
     *
     * @return void
     */
    public function privacy(): void
    {
        $user = $this->requireAuth();

        // Get user settings
        $settings = $this->settingsModel->findByUserId($user['id']);

        // Parse tabs visibility JSON
        $tabsVisibility = !empty($settings['tabs_visibility'])
            ? json_decode($settings['tabs_visibility'], true)
            : UserSettings::getDefaultTabsVisibility();

        $this->render('settings/privacy', [
            'user' => $user,
            'settings' => $settings,
            'tabs_visibility' => $tabsVisibility,
            'page_title' => 'Privacy Settings',
        ]);
    }

    /**
     * Update privacy settings
     *
     * POST /settings/privacy
     *
     * @return void
     */
    public function updatePrivacy(): void
    {
        try {
            $user = $this->requireAuth();

            // ENTERPRISE V5.7: Read JSON body (api.post sends JSON, not form data)
            $jsonInput = file_get_contents('php://input');
            $data = json_decode($jsonInput, true) ?? [];

            // ENTERPRISE V5.7: Simplified privacy settings - only 3 boolean toggles
            $privacyData = [
                'show_online_status' => !empty($data['show_online_status']) ? 1 : 0,
                'allow_friend_requests' => !empty($data['allow_friend_requests']) ? 1 : 0,
                'allow_direct_messages' => !empty($data['allow_direct_messages']) ? 1 : 0,
            ];

            // Validate privacy settings
            $validation = $this->validator->validatePrivacySettings($privacyData);

            if (!$validation['valid']) {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => $validation['errors'],
                ], 400);
                return;
            }

            // Update privacy settings
            $success = $this->settingsModel->updatePrivacy($user['id'], $privacyData);

            if ($success) {
                Logger::info('Privacy settings updated', [
                    'user_id' => $user['id'],
                    'settings' => $privacyData,
                ]);

                $this->jsonResponse([
                    'success' => true,
                    'message' => 'Privacy settings updated successfully',
                ]);
            } else {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => ['Failed to update privacy settings'],
                ], 500);
            }

        } catch (\Exception $e) {
            Logger::error('Privacy settings update error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => ['Internal error: ' . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Update tabs visibility
     *
     * POST /settings/privacy/tabs
     *
     * @return void
     */
    public function updateTabsVisibility(): void
    {
        $user = $this->requireAuth();

        // Get tabs visibility from POST data
        $tabsVisibility = [
            'panoramica' => $_POST['tab_panoramica'] ?? 'public',
            'diario' => $_POST['tab_diario'] ?? 'private', // Always private (enforced by model)
            'timeline' => $_POST['tab_timeline'] ?? 'friends',
            'calendario' => $_POST['tab_calendario'] ?? 'friends',
            'emozioni' => $_POST['tab_emozioni'] ?? 'private',
            'archivio' => $_POST['tab_archivio'] ?? 'private',
        ];

        // Validate tabs visibility
        $validation = $this->validator->validateTabsVisibility($tabsVisibility);

        if (!$validation['valid']) {
            $this->jsonResponse([
                'success' => false,
                'errors' => $validation['errors'],
            ], 400);
            return;
        }

        // Update tabs visibility (with "diario" forced to private)
        $success = $this->settingsModel->updateTabsVisibility($user['id'], $validation['sanitized']);

        if ($success) {
            Logger::info('Tabs visibility updated', [
                'user_id' => $user['id'],
                'tabs' => $validation['sanitized'],
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Tabs visibility updated successfully',
                'tabs' => $validation['sanitized'], // Return sanitized (with diario forced)
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['Failed to update tabs visibility'],
            ], 500);
        }
    }

    /**
     * Notification settings page
     *
     * GET /settings/notifications
     *
     * Email and push notification preferences
     *
     * @return void
     */
    public function notifications(): void
    {
        $user = $this->requireAuth();

        // Get user settings
        $settings = $this->settingsModel->findByUserId($user['id']);

        $this->render('settings/notifications', [
            'user' => $user,
            'settings' => $settings,
            'page_title' => 'Notification Settings',
        ]);
    }

    /**
     * Update notification preferences
     *
     * POST /settings/notifications
     *
     * ENTERPRISE GALAXY V4: In-app notifications (campanella) + Newsletter only
     * Email notifications for social activity removed (spam, non-GDPR friendly)
     *
     * @return void
     */
    public function updateNotifications(): void
    {
        $user = $this->requireAuth();

        // ENTERPRISE V13: Read JSON body (ApiClient sends JSON, not form data)
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        // ENTERPRISE GALAXY V4: New granular in-app notification preferences
        $notificationData = [
            // In-app notifications (campanella)
            'notify_comments' => !empty($input['notify_comments']) ? 1 : 0,
            'notify_replies' => !empty($input['notify_replies']) ? 1 : 0,
            'notify_reactions' => !empty($input['notify_reactions']) ? 1 : 0,
            'notify_comment_likes' => !empty($input['notify_comment_likes']) ? 1 : 0,
            'notify_mentions' => !empty($input['notify_mentions']) ? 1 : 0,
            'notify_friend_requests' => !empty($input['notify_friend_requests']) ? 1 : 0,
            'notify_friend_accepted' => !empty($input['notify_friend_accepted']) ? 1 : 0,
            // Chat/DM notifications
            'notify_dm_received' => !empty($input['notify_dm_received']) ? 1 : 0,
            // Newsletter (only email we keep - GDPR opt-in)
            'email_newsletter' => !empty($input['email_newsletter']) ? 1 : 0,
        ];

        // Update notification preferences directly (no complex validation needed for booleans)
        $success = $this->settingsModel->updateNotifications($user['id'], $notificationData);

        if ($success) {
            // Invalidate user settings cache
            invalidate_user_cache($user['id'], ['settings']);

            Logger::info('Notification preferences updated', [
                'user_id' => $user['id'],
                'settings' => $notificationData,
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Preferenze notifiche aggiornate con successo',
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['Impossibile aggiornare le preferenze'],
            ], 500);
        }
    }

    /**
     * Security settings page
     *
     * GET /settings/security
     *
     * Password change, 2FA
     *
     * @return void
     */
    public function security(): void
    {
        $user = $this->requireAuth();

        // Check if user has password (OAuth users might not have password)
        // ENTERPRISE FIX: Correct column name is 'password_hash', not 'password'
        $hasPassword = !empty($user['password_hash']);

        // Check if 2FA enabled
        $twoFactorEnabled = !empty($user['two_factor_enabled']);

        $this->render('settings/security', [
            'user' => $user,
            'has_password' => $hasPassword,
            'two_factor_enabled' => $twoFactorEnabled,
            'page_title' => 'Security Settings',
        ]);
    }

    /**
     * Change password
     *
     * POST /settings/security/password
     *
     * @return void
     */
    public function changePassword(): void
    {
        $user = $this->requireAuth();

        // ENTERPRISE FIX: Use getInput() to support both POST and JSON requests
        $currentPassword = SettingsValidationService::sanitizeInput($this->getInput('current_password', ''));
        $newPassword = SettingsValidationService::sanitizeInput($this->getInput('new_password', ''));
        $confirmPassword = SettingsValidationService::sanitizeInput($this->getInput('confirm_password', ''));

        // ENTERPRISE FIX: Correct column name is 'password_hash', not 'password'
        // Verify current password (if user has password)
        if (!empty($user['password_hash'])) {
            if (!secure_password_verify($currentPassword, $user['password_hash'])) {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => ['Current password is incorrect'],
                ], 400);
                return;
            }
        }

        // Check if new passwords match
        if ($newPassword !== $confirmPassword) {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['New passwords do not match'],
            ], 400);
            return;
        }

        // Validate new password strength
        $validation = $this->validator->validatePassword($newPassword);

        if (!$validation['valid']) {
            $this->jsonResponse([
                'success' => false,
                'errors' => $validation['errors'],
                'strength' => $validation['strength'],
            ], 400);
            return;
        }

        // Update password (ENTERPRISE FIX: Correct column name is 'password_hash')
        $hashedPassword = secure_password_hash($newPassword);
        $success = (bool) db()->execute(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$hashedPassword, $user['id']],
            ['invalidate_cache' => ['table:users', "user:{$user['id']}"]]
        );

        if ($success) {
            Logger::security('info', 'Password changed', [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'is_oauth_user' => !empty($user['oauth_provider']),
                'was_setting_backup_password' => empty($user['password_hash']),
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Password changed successfully',
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'errors' => ['Failed to change password'],
            ], 500);
        }
    }

    /**
     * GDPR data export & account deletion page
     *
     * GET /settings/data-export
     *
     * Data export, account deletion with 30-day grace period
     *
     * @return void
     */
    public function dataExport(): void
    {
        $user = $this->requireAuth();

        // Check if deletion pending
        $db = db();
        $pendingDeletion = $db->findOne(
            "SELECT id, scheduled_deletion_at, reason, cancelled_at FROM account_deletions
             WHERE user_id = ? AND status = 'pending'",
            [$user['id']]
        );

        $this->render('settings/data-export', [
            'user' => $user,
            'pending_deletion' => $pendingDeletion,
            'has_password' => !empty($user['password_hash']),
            'page_title' => 'Data Export & Account Deletion',
        ]);
    }

    /**
     * Export user data (GDPR Article 20)
     *
     * POST /settings/data-export/export
     *
     * @return void
     */
    public function exportData(): void
    {
        $user = $this->requireAuth();

        try {
            $result = $this->gdprService->exportUserData($user['id']);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Data export completed successfully',
                'download_url' => $result['download_url'],
                'file_size' => $result['file_size'],
                'expires_at' => $result['expires_at'],
            ]);
        } catch (Exception $e) {
            Logger::error('Data export failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }

    /**
     * Schedule account deletion (GDPR Article 17)
     *
     * POST /settings/data-export/delete-account
     *
     * 30-day grace period before hard delete
     *
     * @return void
     */
    public function scheduleAccountDeletion(): void
    {
        $user = $this->requireAuth();

        // ENTERPRISE FIX: Use getInput() to support both POST and JSON requests
        // ApiClient.js sends JSON body, not form-encoded data, so $_POST is always empty
        $reason = $this->getInput('reason', null);
        $confirmPassword = $this->getInput('confirm_password', '');

        // Verify password (if user has password)
        // ENTERPRISE FIX: Correct column name is 'password_hash', not 'password'
        if (!empty($user['password_hash'])) {
            if (!secure_password_verify($confirmPassword, $user['password_hash'])) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'PASSWORD_INCORRECT',
                    'message' => 'La password inserita non è corretta.',
                    'errors' => ['La password inserita non è corretta.'],
                ], 400);
                return;
            }
        }

        try {
            $result = $this->gdprService->scheduleAccountDeletion($user['id'], $reason);

            // ENTERPRISE FIX: Logout user IMMEDIATELY (account soft deleted)
            // Clear session data first
            $_SESSION = [];

            // Delete session cookie (ENTERPRISE: __Host- prefix requires NO domain)
            if (ini_get('session.use_cookies')) {
                setcookie(
                    session_name(), // __Host-N2T_SESSION
                    '',
                    [
                        'expires' => time() - 42000,
                        'path' => '/',
                        // NO 'domain' - required for __Host- prefix
                        'secure' => true,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]
                );
            }

            // Destroy session
            session_destroy();

            $this->jsonResponse([
                'success' => true,
                'message' => 'Account deletion scheduled. You have 30 days to cancel.',
                'deletion_id' => $result['deletion_id'],
                'scheduled_at' => $result['scheduled_at'],
                'redirect_url' => url('/'),
            ]);
        } catch (Exception $e) {
            Logger::error('Account deletion scheduling failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }

    /**
     * Cancel account deletion
     *
     * POST /settings/data-export/cancel-deletion
     *
     * @return void
     */
    public function cancelAccountDeletion(): void
    {
        $user = $this->requireAuth();

        try {
            $success = $this->gdprService->cancelAccountDeletion($user['id']);

            if ($success) {
                // ENTERPRISE GALAXY (2025-01-23): Invalidate cache after cancellation
                invalidate_user_cache($user['id'], ['data', 'profile', 'settings']);

                $this->jsonResponse([
                    'success' => true,
                    'message' => 'Account deletion cancelled successfully',
                ]);
            } else {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => ['Failed to cancel account deletion'],
                ], 500);
            }
        } catch (Exception $e) {
            Logger::error('Account deletion cancellation failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }

    /**
     * Flash success message (stored in session)
     *
     * @param string $message Success message
     */
    private function flashSuccess(string $message): void
    {
        $_SESSION['flash_success'] = $message;
    }

    /**
     * Flash error message (stored in session)
     *
     * @param string $message Error message
     */
    private function flashError(string $message): void
    {
        $_SESSION['flash_error'] = $message;
    }

    /**
     * ENTERPRISE: Generate cache key for user query (same logic as Database::generateCacheKey)
     * This allows us to invalidate the SPECIFIC cached query without global flush
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return string Cache key
     */
    private function generateUserQueryCacheKey(string $sql, array $params): string
    {
        // Same logic as Database::generateCacheKey() in app/Core/Database.php
        $combined = $sql . '|' . json_encode($params);

        // Use SHA256 for cache key (same as Database class)
        if (function_exists('hash')) {
            return 'query:' . hash('sha256', $combined);
        }

        // Fallback to MD5 (should never happen with PHP 8+)
        return 'query:' . md5($combined);
    }
}
