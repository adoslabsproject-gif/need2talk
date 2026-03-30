<?php

/**
 * COMPLETE PROFILE CONTROLLER - ENTERPRISE GALAXY GDPR COMPLIANCE
 *
 * PSR-12 compliant controller for OAuth profile completion
 *
 * PURPOSE:
 * After OAuth registration (Google, etc.), users MUST complete their profile
 * to comply with GDPR and age verification requirements.
 *
 * MANDATORY FIELDS:
 * - Birth date (year/month) - 18+ age verification
 * - GDPR consent acceptance - EU/UK legal requirement
 * - Newsletter opt-in - Optional but tracked
 *
 * SECURITY:
 * - Only accessible by authenticated users with status='pending'
 * - CSRF protection on form submission
 * - Rate limiting via existing infrastructure
 * - Validates age requirement (18+)
 * - Atomic database transaction
 *
 * FLOW:
 * 1. User completes OAuth (Google) → status='pending' + session created
 * 2. Redirected to /complete-profile (this controller)
 * 3. Must fill: birth date, accept GDPR consent, newsletter opt-in
 * 4. On submit: Validate → Update DB → Set status='active' → Redirect to profile
 * 5. If user tries to access site without completing: middleware redirects back here
 *
 * @package Need2Talk\Controllers
 * @version 1.0.0
 * @since 2025-01-17
 */

namespace Need2Talk\Controllers;

use Exception;
use Need2Talk\Core\BaseController;
use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Services\Logger;

class CompleteProfileController extends BaseController
{
    /**
     * Show profile completion form
     *
     * ENTERPRISE SECURITY:
     * - Only accessible if user is logged in AND status='pending'
     * - If already completed (status='active'), redirect to profile
     * - If not logged in, redirect to login
     */
    public function show(): void
    {
        // SECURITY: Must be authenticated
        if (!$this->user) {
            Logger::security('warning', 'COMPLETE_PROFILE: Unauthenticated access attempt', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            $this->redirect(url('auth/login'));
            return;
        }

        // SECURITY: If profile already completed, redirect to profile
        if ($this->user['status'] === 'active') {
            Logger::security('info', 'COMPLETE_PROFILE: Already completed, redirecting to profile', [
                'user_id' => $this->user['id'],
            ]);
            $this->redirect(url('profile'));
            return;
        }

        // SECURITY: Only 'pending' status users should be here
        if ($this->user['status'] !== 'pending') {
            Logger::security('warning', 'COMPLETE_PROFILE: Invalid user status for profile completion', [
                'user_id' => $this->user['id'],
                'status' => $this->user['status'],
            ]);
            $this->redirect(url('/'));
            return;
        }

        // Show form
        Logger::security('info', 'COMPLETE_PROFILE: Showing form to user', [
            'user_id' => $this->user['id'],
            'oauth_provider' => $this->user['oauth_provider'] ?? 'none',
        ]);

        $this->view('auth.complete-profile');
    }

    /**
     * Process profile completion form
     *
     * ENTERPRISE GALAXY LEVEL:
     * - Validates all required fields (birth date, GDPR consent)
     * - Age verification (18+)
     * - Atomic database transaction
     * - GDPR consent timestamp recording
     * - Status change: 'pending' → 'active'
     * - Security audit logging
     */
    public function complete(): void
    {
        // ENTERPRISE SECURITY: Anti-cache headers to prevent back button access
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

        // SECURITY: Must be authenticated
        if (!$this->user) {
            Logger::security('warning', 'COMPLETE_PROFILE: Unauthenticated completion attempt', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            $this->redirect(url('auth/login'));
            return;
        }

        // SECURITY: Only 'pending' status users can complete profile
        if ($this->user['status'] !== 'pending') {
            Logger::security('warning', 'COMPLETE_PROFILE: Invalid status for completion', [
                'user_id' => $this->user['id'],
                'status' => $this->user['status'],
            ]);
            $this->redirect(url('/'));
            return;
        }

        // CSRF validation
        $this->validateCsrf();

        // Get form data
        $birthYear = (int) ($_POST['birth_year'] ?? 0);
        $birthMonth = (int) ($_POST['birth_month'] ?? 0);
        $gdprConsent = isset($_POST['gdpr_consent']) && $_POST['gdpr_consent'] === '1';
        $newsletterOptIn = isset($_POST['newsletter_opt_in']) && $_POST['newsletter_opt_in'] === '1';

        // Validation
        $errors = $this->validateProfileData($birthYear, $birthMonth, $gdprConsent);

        if (!empty($errors)) {
            Logger::security('info', 'COMPLETE_PROFILE: Validation failed', [
                'user_id' => $this->user['id'],
                'error_count' => count($errors),
                'has_gdpr_consent' => $gdprConsent,
            ]);

            EnterpriseGlobalsManager::setSession('errors', $errors);
            EnterpriseGlobalsManager::setSession('old_input', [
                'birth_year' => $birthYear,
                'birth_month' => $birthMonth,
                'newsletter_opt_in' => $newsletterOptIn,
            ]);

            $this->redirect(url('complete-profile'));
            return;
        }

        // ENTERPRISE: Update user profile with transaction safety
        try {
            $db = db();
            $db->beginTransaction(30);

            $updateResult = $db->execute(
                "UPDATE users
                 SET birth_year = :birth_year,
                     birth_month = :birth_month,
                     gdpr_consent_at = NOW(),
                     newsletter_opt_in = :newsletter_opt_in,
                     newsletter_subscribed = :newsletter_subscribed,
                     status = 'active',
                     updated_at = NOW()
                 WHERE id = :user_id
                   AND status = 'pending'",
                [
                    'birth_year' => $birthYear,
                    'birth_month' => $birthMonth,
                    'newsletter_opt_in' => $newsletterOptIn,
                    'newsletter_subscribed' => $newsletterOptIn,
                    'user_id' => $this->user['id'],
                ],
                [
                    'invalidate_cache' => ['table:users', "user:{$this->user['id']}"]
                ]
            );

            $db->commit();

            // ENTERPRISE GALAXY (2025-01-23 REFACTORING): Cache invalidation + smart pre-warming
            // This fixes the redirect loop by ensuring current_user() immediately returns fresh data

            // STEP 1: Invalidate ALL user cache keys (data, profile, settings)
            invalidate_user_cache($this->user['id'], ['data', 'profile', 'settings']);

            // STEP 2: Smart pre-warming for 'data' cache (used by current_user())
            // This preloads fresh data IMMEDIATELY so next request has zero cache miss
            // CRITICAL: Must use 'data' type because current_user() looks for user:{id}:data
            warm_user_cache($this->user['id'], 'data');

            // ENTERPRISE SECURITY LOG: Profile completion (use UUID for external logging)
            Logger::security('info', 'COMPLETE_PROFILE: Profile completed successfully', [
                'user_id' => $this->user['id'],  // Internal ID (security logs only)
                'user_uuid' => $this->user['uuid'] ?? null,  // External UUID
                'email_hash' => hash('sha256', strtolower($this->user['email'])),
                'oauth_provider' => $this->user['oauth_provider'] ?? 'none',
                'newsletter_opt_in' => $newsletterOptIn,
                'age_verified' => true,
                'gdpr_consent' => true,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent_hash' => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);

            // Success message
            EnterpriseGlobalsManager::setSession('success', 'Profilo completato! Benvenuto su need2talk.it');

            // Redirect to feed (2025-12-20: changed from profile for better UX)
            Logger::security('info', 'COMPLETE_PROFILE: Redirecting to feed after completion', [
                'user_id' => $this->user['id'],
            ]);

            $this->redirect(url('feed'));

        } catch (Exception $e) {
            $db->rollback();

            Logger::security('error', 'COMPLETE_PROFILE: Database error during completion', [
                'user_id' => $this->user['id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            EnterpriseGlobalsManager::setSession('errors', [
                'Si è verificato un errore durante il salvataggio. Riprova.',
            ]);
            $this->redirect(url('complete-profile'));
        }
    }

    /**
     * Validate profile completion data
     *
     * ENTERPRISE VALIDATION:
     * - Birth year: 1900 to (current_year - 18) - Age verification
     * - Birth month: 1-12
     * - GDPR consent: MANDATORY (EU/UK legal requirement)
     *
     * @param int $birthYear Birth year
     * @param int $birthMonth Birth month
     * @param bool $gdprConsent GDPR consent accepted
     * @return array Array of error messages (empty if valid)
     */
    private function validateProfileData(int $birthYear, int $birthMonth, bool $gdprConsent): array
    {
        $errors = [];
        $currentYear = (int) date('Y');

        // Birth year validation - 18+ requirement
        if ($birthYear < 1900 || $birthYear > ($currentYear - 18)) {
            $errors[] = 'Devi avere almeno 18 anni per utilizzare need2talk';
            Logger::security('warning', 'COMPLETE_PROFILE: Age verification failed', [
                'user_id' => $this->user['id'],
                'birth_year' => $birthYear,
                'min_required_year' => $currentYear - 18,
            ]);
        }

        // Birth month validation
        if ($birthMonth < 1 || $birthMonth > 12) {
            $errors[] = 'Mese di nascita non valido';
        }

        // GDPR consent validation - MANDATORY
        if (!$gdprConsent) {
            $errors[] = 'Devi accettare la privacy policy per continuare (requisito GDPR)';
            Logger::security('warning', 'COMPLETE_PROFILE: GDPR consent not provided', [
                'user_id' => $this->user['id'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        }

        return $errors;
    }
}
