<?php

namespace Need2Talk\Controllers;

use Exception;
use Need2Talk\Core\BaseController;
use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Models\User;
use Need2Talk\Services\CrossPlatformSessionService;
use Need2Talk\Services\Logger;
use Need2Talk\Services\PasswordResetService;
use Need2Talk\Services\SecureSessionManager;
use Need2Talk\Services\TokenBucketRateLimiter;
use Need2Talk\Services\JWTService;

class AuthController extends BaseController
{
    private User $userModel;

    private Logger $logger;

    private ?TokenBucketRateLimiter $rateLimiter = null;

    private ?PasswordResetService $passwordResetService = null;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
        $this->logger = new Logger();
        // ULTRA-ENTERPRISE PERFORMANCE: Lazy load ALL heavy services (avoid 20-40ms Redis overhead)
        // - TokenBucketRateLimiter: Redis connection only for login/resend (not showLogin, showRegister, etc.)
        // - PasswordResetService: Redis connection only for password reset operations
        // - SecureSessionManager: Static methods (no instantiation needed)
    }

    /**
     * Get PasswordResetService instance (lazy loading for performance)
     *
     * ULTRA-ENTERPRISE: Instantiate only when needed, avoiding 10-20ms Redis connection
     * on every page load. Service is initialized only for password reset operations.
     *
     * @return PasswordResetService
     */
    private function getPasswordResetService(): PasswordResetService
    {
        if ($this->passwordResetService === null) {
            $this->passwordResetService = new PasswordResetService();
        }

        return $this->passwordResetService;
    }

    /**
     * Get TokenBucketRateLimiter instance (lazy loading for performance)
     *
     * ULTRA-ENTERPRISE: Instantiate only when needed, avoiding 10-20ms Redis connection
     * on EVERY auth page. Rate limiter is ONLY needed for login and resendVerification.
     * All other pages (showLogin, showRegister, showForgotPassword, etc.) run with ZERO overhead.
     *
     * PERFORMANCE GAIN:
     * - Before: 20-40ms Redis overhead on ALL 13 auth endpoints
     * - After: 0ms on 11 endpoints, 20-40ms only on 2 endpoints that need it
     *
     * @return TokenBucketRateLimiter
     */
    private function getRateLimiter(): TokenBucketRateLimiter
    {
        if ($this->rateLimiter === null) {
            $this->rateLimiter = new TokenBucketRateLimiter();
        }

        return $this->rateLimiter;
    }

    /**
     * Mostra pagina login
     */
    public function showLogin(): void
    {
        // ENTERPRISE: Check session without triggering lazy load (faster)
        $user = current_user();
        if ($user && !empty($user['id'])) {
            Logger::security('debug', 'AUTH: Already authenticated user redirected to feed', [
                'user_id' => $user['id'],
                'redirect_to' => 'feed',
            ]);
            $this->redirect(url('feed'));
        }

        $this->view('auth.login');
    }

    /**
     * Processo login con sistema avanzato
     *
     * ENTERPRISE GALAXY ULTIMATE: Dynamic Logging with should_log()
     * - Debug logs: Conditional based on 'security' channel level
     * - Security logs: Always active (warning level)
     * - Performance: Zero overhead when debug disabled
     */
    public function login(): void
    {
        // ENTERPRISE DYNAMIC LOGGING: Debug level only
        Logger::security('debug', 'AUTH: Login attempt started', [
            'has_email' => !empty($_POST['email'] ?? ''),
            'has_password' => !empty($_POST['password'] ?? ''),
            'remember_me' => isset($_POST['remember']),
            'csrf_validated' => false,
        ]);

        $this->validateCsrf();

        // ENTERPRISE DYNAMIC LOGGING: Debug level only
        Logger::security('debug', 'AUTH: CSRF validation passed for login', []);

        // ENTERPRISE GALAXY: reCAPTCHA v3 validation (CONDITIONAL - after 3 failed attempts)
        $recaptchaService = new \Need2Talk\Services\RecaptchaService();
        $recaptchaRequired = $recaptchaService->isRequiredForLogin();

        if ($recaptchaRequired) {
            Logger::security('debug', 'AUTH: reCAPTCHA required for login (3+ failed attempts)', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'fail_count' => $recaptchaService->getLoginFailsCount(),
            ]);

            $recaptchaToken = $_POST['recaptcha_token'] ?? '';
            if (empty($recaptchaToken)) {
                Logger::security('warning', 'AUTH: reCAPTCHA token missing on login', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ]);
                $this->view('auth.login', [
                    'error' => 'Verifica di sicurezza richiesta. Ricarica la pagina.',
                    'show_recaptcha' => true,
                ]);
                return;
            }

            $recaptchaResult = $recaptchaService->verify($recaptchaToken, 'login');
            if (!$recaptchaResult['success']) {
                // ENTERPRISE V6.1: Safari iOS Graceful Degradation for Login
                $isBrowserError = $recaptchaResult['browser_error'] ?? false;

                if ($isBrowserError) {
                    // Browser blocked reCAPTCHA - allow login attempt with extra logging
                    // Login already has rate limiting per email, so we just log and continue
                    Logger::security('info', 'AUTH: reCAPTCHA browser error on login - allowing (Safari ITP fix)', [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'errors' => $recaptchaResult['errors'],
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'action' => 'graceful_degradation',
                    ]);
                    // Continue with login (skip reCAPTCHA block) - existing rate limiting will protect
                } else {
                    // Normal reCAPTCHA failure - block
                    Logger::security('warning', 'AUTH: reCAPTCHA validation failed on login', [
                        'score' => $recaptchaResult['score'],
                        'errors' => $recaptchaResult['errors'],
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ]);
                    $this->view('auth.login', [
                        'error' => 'Verifica di sicurezza fallita. Se non sei un bot, riprova.',
                        'show_recaptcha' => true,
                    ]);
                    return;
                }
            } else {
                Logger::security('info', 'AUTH: reCAPTCHA validation successful on login', [
                    'score' => $recaptchaResult['score'],
                ]);
            }
        }

        // CRITICAL FIX: Normalize email to lowercase (registration saves lowercase)
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        // Validazione base
        if (empty($email) || empty($password)) {
            // ENTERPRISE: Login failures tracked for brute force detection
            Logger::security('warning', 'AUTH: Login failed - missing credentials', [
                'has_email' => !empty($email),
                'has_password' => !empty($password),
            ]);
            $this->view('auth.login', ['error' => 'Email e password sono obbligatori']);

            return;
        }

        // ================================================================
        // ENTERPRISE V6.1: HONEYPOT EMAIL TRAP
        // Attaccanti provano email admin/root/administrator nel form user
        // Questa email esiste SOLO per admin panel dinamico → BAN IMMEDIATO
        // ================================================================
        if ($this->isHoneypotEmail($email)) {
            $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // CRITICAL: Ban via AntiVulnerabilityScanningMiddleware (centralizzato)
            try {
                \Need2Talk\Middleware\AntiVulnerabilityScanningMiddleware::handleHoneypotAccess(
                    $clientIP,
                    $userAgent,
                    '/login [HONEYPOT EMAIL: ' . $email . ']'
                );
            } catch (\Throwable $e) {
                Logger::security('error', 'HONEYPOT: Failed to ban IP for honeypot email', [
                    'ip' => $clientIP,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }

            Logger::security('critical', 'HONEYPOT: Admin email used in user login form - IP BANNED', [
                'ip' => $clientIP,
                'email' => $email,
                'user_agent' => $userAgent,
                'attack_type' => 'admin_enumeration',
            ]);

            // Fake response - non rivelare che è honeypot
            sleep(2); // Rallenta attaccante
            $this->view('auth.login', ['error' => 'Credenziali non valide']);
            return;
        }

        // ================================================================
        // ENTERPRISE RATE LIMITING: Token Bucket Algorithm
        // Protegge da brute force attacks (max 5 tentativi/minuto per email)
        // ================================================================
        $rateLimitKey = "login:{$email}";
        if (!$this->getRateLimiter()->allow($rateLimitKey, 'login')) {
            // ENTERPRISE GALAXY ULTIMATE: Security event with explicit level (warning = rate limit violation)
            Logger::security('warning', 'Login rate limit exceeded', [
                'email_hash' => hash('sha256', strtolower($email)),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            $this->view('auth.login', [
                'error' => 'Troppi tentativi di login. Riprova tra qualche minuto.',
                'rate_limited' => true,
            ]);

            return;
        }

        // Trova utente
        // ENTERPRISE GALAXY V6.6: Use findByEmailForAuth to bypass cache
        // User status MUST be real-time to enforce bans/suspensions correctly
        Logger::security('debug', 'AUTH: Looking up user by email (no cache)', [
            'email_hash' => hash('sha256', strtolower($email)),
        ]);

        $user = $this->userModel->findByEmailForAuth($email);

        if (!$user) {
            // ENTERPRISE: Failed login tracked for brute force detection
            Logger::security('warning', 'AUTH: Login failed - user not found', [
                'email_hash' => hash('sha256', strtolower($email)),
            ]);
            $this->logFailedLogin($email, 'User not found');

            // ENTERPRISE GALAXY: Increment reCAPTCHA fail counter
            $recaptchaService->incrementLoginFails();

            $this->view('auth.login', ['error' => 'Credenziali non valide']);

            return;
        }

        // ENTERPRISE DYNAMIC LOGGING: Debug level only
        Logger::security('debug', 'AUTH: User found, checking account status', [
            'user_id' => $user['id'],
            'user_status' => $user['status'] ?? 'unknown',
        ]);

        // CRITICAL SECURITY: Check if user is BANNED (HIGHEST PRIORITY - before any other checks)
        if (isset($user['status']) && $user['status'] === 'banned') {
            // Log CRITICAL security event - banned user attempting login
            Logger::security('critical', 'BANNED USER LOGIN ATTEMPT', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($email)),
                'banned_at' => $user['banned_at'] ?? null,
                'ban_reason' => $user['ban_reason'] ?? 'Unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            // Log failed login
            $this->logFailedLogin($email, 'Account permanently banned');

            // Return custom banned page (do NOT reveal ban details to attacker)
            $this->view('auth.login', [
                'error' => 'Account sospeso. Contatta support@need2talk.it per informazioni.',
                'banned' => true,
            ]);

            return;
        }

        // Verifica account bloccato
        if ($this->userModel->isAccountLocked($user['id'])) {
            // ENTERPRISE DYNAMIC LOGGING: Warning level (account locked is security-relevant)
            Logger::security('warning', 'AUTH: Login failed - account locked', [
                'user_id' => $user['id'],
            ]);

            // ENTERPRISE SECURITY LOG: Account locked login attempt
            Logger::security('critical', 'Login attempt on locked account', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($email)),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            $this->logFailedLogin($email, 'Account locked');
            $this->view('auth.login', [
                'error' => 'Account temporaneamente bloccato. Riprova più tardi.',
            ]);

            return;
        }

        // Verifica password
        // ENTERPRISE DYNAMIC LOGGING: Debug level only
        Logger::security('debug', 'AUTH: Verifying password', [
            'user_id' => $user['id'],
        ]);

        if (!password_verify($password, $user['password_hash'])) {
            // ENTERPRISE: Failed password tracked for brute force detection
            Logger::security('warning', 'AUTH: Login failed - invalid password', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($email)),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'failed_attempts' => ($user['failed_login_attempts'] ?? 0) + 1,
            ]);

            $this->userModel->incrementFailedAttempts($user['id']);
            $this->logFailedLogin($email, 'Invalid password');

            // ENTERPRISE GALAXY: Increment reCAPTCHA fail counter (triggers captcha after 3 fails)
            $failCount = $recaptchaService->incrementLoginFails();
            Logger::security('debug', 'AUTH: Incremented login fail counter', [
                'fail_count' => $failCount,
                'recaptcha_will_be_required' => $failCount >= 3,
            ]);

            $this->view('auth.login', ['error' => 'Credenziali non valide']);

            return;
        }

        // Verifica email confermata
        // ENTERPRISE DYNAMIC LOGGING: Debug level only
        Logger::security('debug', 'AUTH: Checking email verification status', [
            'user_id' => $user['id'],
            'email_verified' => $this->userModel->isEmailVerified($user['id']),
        ]);

        if (!$this->userModel->isEmailVerified($user['id'])) {
            // ENTERPRISE DYNAMIC LOGGING: Info level (blocked login worth tracking)
            Logger::security('info', 'AUTH: Login blocked - email not verified', [
                'user_id' => $user['id'],
            ]);

            // ENTERPRISE SECURITY LOG: Login blocked for unverified email
            Logger::security('info', 'Login blocked - email not verified', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($email)),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'account_status' => 'unverified',
            ]);

            $this->view('auth.login', [
                'error' => 'Devi verificare il tuo indirizzo email prima di accedere',
                'show_resend' => true,
                'user_id' => $user['id'],
            ]);

            return;
        }

        // ====================================================================
        // ENTERPRISE GALAXY: Cross-Platform Session Isolation
        // Prevents simultaneous login as User AND Moderator with same email
        // ====================================================================
        $xplatformCheck = CrossPlatformSessionService::canLogin(
            $user['email'],
            CrossPlatformSessionService::PLATFORM_USER
        );

        if (!$xplatformCheck['allowed']) {
            Logger::security('warning', 'AUTH: Login blocked - cross-platform session conflict', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($email)),
                'blocking_platform' => $xplatformCheck['blocking_platform'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->view('auth.login', [
                'error' => $xplatformCheck['reason'],
                'xplatform_blocked' => true,
            ]);

            return;
        }

        // Login riuscito
        $this->performSuccessfulLogin($user, $remember);
    }

    /**
     * Mostra pagina registrazione
     */
    public function showRegister(): void
    {
        // ENTERPRISE: Check session without triggering lazy load (faster)
        $user = current_user();
        if ($user && !empty($user['id'])) {
            $this->redirect(url('feed'));
        }

        $this->view('auth.register');
    }

    /**
     * Registrazione ultra-sicura per migliaia di utenti simultanei
     *
     * ENTERPRISE GALAXY ULTIMATE: Dynamic Logging with should_log()
     * - Debug logs: Conditional based on 'security' channel level
     * - Security logs: Always active (info/warning level)
     * - Email logs: Conditional based on 'email' channel level
     * - Performance: Zero overhead when debug disabled
     */
    public function register(): void
    {
        // ENTERPRISE SECURITY: Anti-cache headers to prevent back button access
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past

        // ENTERPRISE DYNAMIC LOGGING: Debug level only
        Logger::security('debug', 'AUTH: Registration attempt started', [
            'has_email' => !empty($_POST['email'] ?? ''),
            'has_nickname' => !empty($_POST['nickname'] ?? ''),
            'has_password' => !empty($_POST['password'] ?? ''),
            'post_data_count' => count($_POST),
            'csrf_validated' => false,
        ]);

        // ENTERPRISE: Critical debugging - catch ANY fatal errors immediately
        try {
            // ENTERPRISE DYNAMIC LOGGING: Debug level only
            Logger::security('debug', 'AUTH: REGISTER METHOD ENTRY - Start of controller method', [
                'phase' => 'method_entry',
                'post_data_count' => count($_POST),
                'session_status' => session_status(),
                'session_id' => substr(session_id(), 0, 8),
                'memory_usage' => memory_get_usage(),
                'peak_memory' => memory_get_peak_usage(),
                'enterprise_mode' => 'ultra_secure_debugging',
            ]);

            // SECURITY: Validazione CSRF avanzata
            // ENTERPRISE DYNAMIC LOGGING: Debug level only
            Logger::security('debug', 'AUTH: About to call validateCsrf method', [
                'phase' => 'pre_csrf_validation',
                'csrf_handled_by_middleware' => true,
            ]);

            $this->validateCsrf();

            // ENTERPRISE DYNAMIC LOGGING: Debug level only
            Logger::security('debug', 'AUTH: validateCsrf method completed successfully', [
                'phase' => 'post_csrf_validation',
                'csrf_validation_result' => 'success',
            ]);

            // ENTERPRISE GALAXY: reCAPTCHA v3 validation (ALWAYS required for registration)
            Logger::security('debug', 'AUTH: Starting reCAPTCHA v3 validation', [
                'phase' => 'recaptcha_validation',
                'has_token' => !empty($_POST['recaptcha_token'] ?? ''),
            ]);

            $recaptchaToken = $_POST['recaptcha_token'] ?? '';
            if (empty($recaptchaToken)) {
                Logger::security('warning', 'AUTH: reCAPTCHA token missing', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ]);
                EnterpriseGlobalsManager::setSession('errors', ['Verifica di sicurezza fallita. Ricarica la pagina e riprova.']);
                $this->redirect(url('register'));
                return;
            }

            $recaptchaService = new \Need2Talk\Services\RecaptchaService();
            $recaptchaResult = $recaptchaService->verify($recaptchaToken, 'register');

            if (!$recaptchaResult['success']) {
                // ENTERPRISE V6.1: Safari iOS Graceful Degradation
                // If reCAPTCHA failed due to browser blocking (Safari ITP, content blockers),
                // allow registration with strict rate limiting instead of blocking
                $isBrowserError = $recaptchaResult['browser_error'] ?? false;

                if ($isBrowserError) {
                    // Browser blocked reCAPTCHA - apply strict rate limit (1 registration/hour per IP)
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $rateLimitKey = "register:browser_error:{$ip}";

                    try {
                        $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection();
                        $recentAttempt = $redis->get($rateLimitKey);

                        if ($recentAttempt) {
                            // Already tried within the hour - block
                            Logger::security('warning', 'AUTH: reCAPTCHA browser error + rate limited', [
                                'ip' => $ip,
                                'errors' => $recaptchaResult['errors'],
                                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                            ]);
                            EnterpriseGlobalsManager::setSession('errors', ['Troppe richieste. Riprova tra un\'ora o usa un browser diverso.']);
                            $this->redirect(url('register'));
                            return;
                        }

                        // First attempt - allow but set rate limit
                        $redis->setEx($rateLimitKey, 3600, time()); // 1 hour TTL

                        Logger::security('info', 'AUTH: reCAPTCHA browser error - allowing with rate limit (Safari ITP fix)', [
                            'ip' => $ip,
                            'errors' => $recaptchaResult['errors'],
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                            'action' => 'graceful_degradation',
                        ]);

                        // Continue with registration (skip reCAPTCHA block)

                    } catch (\Throwable $e) {
                        // Redis error - fail safe (block)
                        Logger::security('error', 'AUTH: Redis error during browser error handling', [
                            'error' => $e->getMessage(),
                        ]);
                        EnterpriseGlobalsManager::setSession('errors', ['Verifica di sicurezza fallita. Riprova.']);
                        $this->redirect(url('register'));
                        return;
                    }
                } else {
                    // Normal reCAPTCHA failure (bot detected) - block
                    Logger::security('warning', 'AUTH: reCAPTCHA validation failed', [
                        'score' => $recaptchaResult['score'],
                        'errors' => $recaptchaResult['errors'],
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ]);
                    EnterpriseGlobalsManager::setSession('errors', ['Verifica di sicurezza fallita. Se non sei un bot, riprova.']);
                    $this->redirect(url('register'));
                    return;
                }
            } else {
                Logger::security('info', 'AUTH: reCAPTCHA validation successful', [
                    'score' => $recaptchaResult['score'],
                ]);
            }

        } catch (\Error $e) {
            // ENTERPRISE DYNAMIC LOGGING: Error level (ALWAYS logged)
            Logger::security('error', 'AUTH: FATAL ERROR in register method entry', [
                'phase' => 'fatal_error',
                'error_type' => 'Error',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            // Return error instead of crashing
            EnterpriseGlobalsManager::setSession('errors', ['Sistema temporaneamente non disponibile. Riprova più tardi.']);
            $this->redirect(url('register'));

            return;

        } catch (Exception $e) {
            // ENTERPRISE DYNAMIC LOGGING: Error level (ALWAYS logged)
            Logger::security('error', 'AUTH: EXCEPTION in register method entry', [
                'phase' => 'exception',
                'error_type' => 'Exception',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            // Return error instead of crashing
            EnterpriseGlobalsManager::setSession('errors', ['Si è verificato un errore. Riprova.']);
            $this->redirect(url('register'));

            return;
        }

        // ================================================================
        // ENTERPRISE V6.2: IP RATE LIMIT (1 account per IP every 24h)
        // Prevents mass account creation from same IP
        // ================================================================
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        try {
            $db = db();
            $recentRegistration = $db->findOne(
                "SELECT id, nickname, created_at FROM users
                 WHERE registration_ip = :ip
                 AND created_at > NOW() - INTERVAL '24 hours'
                 ORDER BY created_at DESC LIMIT 1",
                ['ip' => $clientIP]
            );

            if ($recentRegistration) {
                Logger::security('warning', 'AUTH: IP rate limit exceeded - 1 account/24h', [
                    'ip' => $clientIP,
                    'existing_account_id' => $recentRegistration['id'],
                    'existing_nickname' => $recentRegistration['nickname'],
                    'registered_at' => $recentRegistration['created_at'],
                ]);

                EnterpriseGlobalsManager::setSession('errors', [
                    'Hai già creato un account nelle ultime 24 ore. Riprova domani o contatta il supporto.'
                ]);
                $this->redirect(url('register'));
                return;
            }
        } catch (\Throwable $e) {
            // Log but don't block on DB error
            Logger::security('error', 'AUTH: IP rate limit check failed', [
                'ip' => $clientIP,
                'error' => $e->getMessage(),
            ]);
        }

        // ================================================================
        // ENTERPRISE V6.3: DISPOSABLE EMAIL BLOCKING (Registration)
        // Prevents spam accounts with temporary email addresses
        // Uses comprehensive blocklist (500+ domains) + pattern matching
        // ================================================================
        $postData = EnterpriseGlobalsManager::getAllPost();
        $registrationEmail = trim($postData['email'] ?? '');

        if (!empty($registrationEmail)) {
            $disposableCheck = \Need2Talk\Services\DisposableEmailService::check($registrationEmail);

            if ($disposableCheck['is_disposable']) {
                Logger::security('warning', 'AUTH: Disposable email blocked at registration', [
                    'ip' => $clientIP,
                    'email_domain' => $disposableCheck['domain'],
                    'detection_reason' => $disposableCheck['reason'],
                    'email_hash' => hash('sha256', $registrationEmail),
                ]);

                EnterpriseGlobalsManager::setSession('errors', [
                    'Le email temporanee o usa e getta non sono consentite. Usa un indirizzo email permanente.'
                ]);
                $this->redirect(url('register'));
                return;
            }
        }

        // ================================================================
        // ENTERPRISE V6.1: HONEYPOT EMAIL TRAP (Registration)
        // Attaccanti provano email admin/root nel form registrazione
        // ================================================================
        if ($this->isHoneypotEmail($registrationEmail)) {
            $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // CRITICAL: Ban via AntiVulnerabilityScanningMiddleware (centralizzato)
            try {
                \Need2Talk\Middleware\AntiVulnerabilityScanningMiddleware::handleHoneypotAccess(
                    $clientIP,
                    $userAgent,
                    '/register [HONEYPOT EMAIL: ' . $registrationEmail . ']'
                );
            } catch (\Throwable $e) {
                Logger::security('error', 'HONEYPOT: Failed to ban IP for honeypot email (register)', [
                    'ip' => $clientIP,
                    'email' => $registrationEmail,
                    'error' => $e->getMessage(),
                ]);
            }

            Logger::security('critical', 'HONEYPOT: Admin email used in registration form - IP BANNED', [
                'ip' => $clientIP,
                'email' => $registrationEmail,
                'user_agent' => $userAgent,
                'attack_type' => 'admin_enumeration_register',
            ]);

            // Fake response - non rivelare che è honeypot
            sleep(2); // Rallenta attaccante
            EnterpriseGlobalsManager::setSession('errors', ['Questa email non può essere utilizzata.']);
            $this->redirect(url('register'));
            return;
        }

        // ENTERPRISE: Continue with registration process after CSRF validation
        try {
            // ENTERPRISE DYNAMIC LOGGING: Debug level only
            Logger::security('debug', 'AUTH: Initializing RegistrationService', [
                'phase' => 'service_initialization',
                'post_data_size' => count(EnterpriseGlobalsManager::getAllPost()),
                'enterprise_security' => 'ultra_secure_mode',
            ]);

            $registrationService = new \Need2Talk\Services\RegistrationService();

            // ENTERPRISE DYNAMIC LOGGING: Debug level only
            Logger::security('debug', 'AUTH: RegistrationService initialized - calling register method', [
                'phase' => 'calling_register_method',
                'service_class' => get_class($registrationService),
            ]);

            // SECURITY: Esegui registrazione con tutti i controlli anti-malintenzionati
            $result = $registrationService->register(EnterpriseGlobalsManager::getAllPost());

            // ENTERPRISE DYNAMIC LOGGING: Debug level only
            Logger::security('debug', 'AUTH: RegistrationService register method returned', [
                'phase' => 'register_method_returned',
                'success' => $result['success'] ?? false,
                'has_errors' => isset($result['errors']),
                'error_count' => isset($result['errors']) ? count($result['errors']) : 0,
                'result_keys' => array_keys($result),
            ]);

            if ($result['success']) {
                // SUCCESSO: Registrazione completata
                EnterpriseGlobalsManager::setSession('success', $result['message'] ?? 'Account creato con successo!');

                // SECURITY: Set session variables for verify-email-sent page access control
                $postData = EnterpriseGlobalsManager::getAllPost();
                EnterpriseGlobalsManager::setSession('just_registered', true);
                EnterpriseGlobalsManager::setSession('verification_email', $postData['email'] ?? '');
                EnterpriseGlobalsManager::setSession('verification_nickname', $postData['nickname'] ?? '');
                EnterpriseGlobalsManager::setSession('verification_page_accessed', false);

                // ENTERPRISE GALAXY ULTIMATE: Security event with explicit level (info = successful operation)
                Logger::security('info', 'Successful user registration', [
                    'user_id' => $result['user_id'],
                    'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
                    'user_agent' => EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', ''),
                    'session_id' => session_id(),
                ]);

                // REDIRECT: Vai alla destinazione appropriata
                $redirectUrl = $result['redirect'] ?? url('login?registered=1');
                $this->redirect($redirectUrl);

            } else {
                // ERRORE: Preserva input e mostra errori
                if (isset($result['errors'])) {
                    EnterpriseGlobalsManager::setSession('errors', $result['errors']);
                } else {
                    EnterpriseGlobalsManager::setSession('errors', [$result['error'] ?? 'Si è verificato un errore durante la registrazione.']);
                }

                // SECURITY: Preserva input sicuri (no password)
                $postData = EnterpriseGlobalsManager::getAllPost();
                EnterpriseGlobalsManager::setSession('old_input', array_filter($postData, function ($key) {
                    return !in_array($key, [
                        'password', 'password_confirmation', 'csrf_token',
                        'hp_email', 'hp_name', 'hp_phone', 'website', 'url', 'homepage',
                    ], true);
                }, ARRAY_FILTER_USE_KEY));

                // ENTERPRISE DYNAMIC LOGGING: Info level (failed attempts worth tracking)
                Logger::security('info', 'AUTH: Registration failed', [
                    'error_code' => $result['error_code'] ?? 'UNKNOWN',
                    'has_validation_errors' => isset($result['errors']),
                    'error_count' => isset($result['errors']) ? count($result['errors']) : 0,
                    'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
                    'email_hash' => hash('sha256', ($postData['email'] ?? '')),
                ]);

                // REDIRECT: Torna al form con errori
                $this->redirect(url('register'));
            }

        } catch (\Error $e) {
            // ENTERPRISE DYNAMIC LOGGING: Error level (ALWAYS logged)
            Logger::security('error', 'AUTH: FATAL ERROR in registration process', [
                'phase' => 'registration_fatal_error',
                'error_type' => 'Error',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            // Return enterprise-grade error
            EnterpriseGlobalsManager::setSession('errors', ['Sistema temporaneamente non disponibile. Il nostro team tecnico è stato notificato.']);
            $this->redirect(url('register'));

        } catch (Exception $e) {
            // ENTERPRISE DYNAMIC LOGGING: Error level (ALWAYS logged)
            Logger::security('error', 'AUTH: EXCEPTION in registration process', [
                'phase' => 'registration_exception',
                'error_type' => 'Exception',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            // Return enterprise-grade error
            EnterpriseGlobalsManager::setSession('errors', ['Si è verificato un errore durante la registrazione. Riprova più tardi.']);
            $this->redirect(url('register'));
        }
    }

    /**
     * Logout completo con pulizia sessioni
     */
    public function logout(): void
    {
        if ($this->user) {
            $this->logger->info('User logout', [
                'user_id' => $this->user['id'],
                'nickname' => $this->user['nickname'],
            ]);

            // ====================================================================
            // ENTERPRISE GALAXY: Clear Cross-Platform Session
            // Allows login as Moderator after logging out as User
            // ====================================================================
            if (!empty($this->user['email'])) {
                CrossPlatformSessionService::clearSession(
                    $this->user['email'],
                    CrossPlatformSessionService::PLATFORM_USER
                );
            }
        }

        // ENTERPRISE UNIFIED: SecureSessionManager logout (clears session + remember-me + logs GDPR audit)
        SecureSessionManager::logout();
        $this->redirect(url('/'));
    }

    /**
     * Mostra form password dimenticata
     */
    public function showForgotPassword(): void
    {
        // ENTERPRISE: Check if user already logged in
        $user = current_user();
        if ($user && !empty($user['id'])) {
            $this->redirect(url('feed'));
        }

        $this->view('auth.forgot-password');
    }

    /**
     * Processo password dimenticata - ENTERPRISE INTEGRATION
     *
     * Renamed from forgotPassword() to sendResetLink() to match routes
     * Integrato completamente con PasswordResetService enterprise-grade
     */
    public function sendResetLink(): void
    {
        // ENTERPRISE: Log request entry
        Logger::security('info', 'AUTH: Password reset link request started', [
            'has_email' => !empty($_POST['email'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        $this->validateCsrf();

        // CRITICAL FIX: Normalize email to lowercase (registration saves lowercase)
        $email = strtolower(trim($_POST['email'] ?? ''));

        // ====================================================================
        // VALIDATION: Basic email validation
        // ====================================================================
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Logger::security('warning', 'AUTH: Invalid email format for password reset', [
                'email_hash' => hash('sha256', $email),
            ]);

            $this->view('auth.forgot-password', [
                'error' => 'Indirizzo email non valido',
                'old_input' => ['email' => $email],
            ]);

            return;
        }

        // ====================================================================
        // ENTERPRISE: Call PasswordResetService (handles all logic)
        // ====================================================================
        // Service gestisce:
        // - Rate limiting multi-level (IP + Email)
        // - Token generation (SHA-256, 128 chars)
        // - Database storage + Redis caching
        // - Async email via 8 workers
        // - Metrics tracking
        // - Security logging
        // - Privacy-safe responses
        // ====================================================================
        $result = $this->getPasswordResetService()->requestPasswordReset($email);

        Logger::security('info', 'AUTH: PasswordResetService completed', [
            'success' => $result['success'],
            'error_code' => $result['error_code'] ?? null,
        ]);

        // ====================================================================
        // RESPONSE: Show result to user
        // ====================================================================
        if ($result['success']) {
            // SUCCESS: Show privacy-safe message
            $this->view('auth.forgot-password', [
                'success' => $result['message'],
            ]);
        } else {
            // ERROR: Rate limit or other error
            $this->view('auth.forgot-password', [
                'error' => $result['error'],
                'old_input' => ['email' => $email],
            ]);
        }
    }

    /**
     * Mostra form reset password - ENTERPRISE INTEGRATION
     *
     * Integrato completamente con PasswordResetService per validazione token
     */
    public function showResetPassword(string $token = ''): void
    {
        // ENTERPRISE SECURITY: Anti-cache headers to prevent back button access
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past

        // ENTERPRISE: Check if user already logged in
        $user = current_user();
        if ($user && !empty($user['id'])) {
            $this->redirect(url('feed'));
        }

        // Get token from query string if not in parameter
        if (empty($token)) {
            $token = $_GET['token'] ?? '';
        }

        // VALIDATION: Token required
        if (empty($token)) {
            Logger::security('warning', 'AUTH: Password reset page accessed without token', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->view('auth.forgot-password', [
                'error' => 'Token mancante. Richiedi un nuovo link di reset.',
            ]);

            return;
        }

        // ====================================================================
        // ENTERPRISE: Verify token validity via PasswordResetService
        // ====================================================================
        $verificationResult = $this->getPasswordResetService()->verifyToken($token);

        if (!$verificationResult['valid']) {
            // INVALID TOKEN: Show error
            Logger::security('warning', 'AUTH: Invalid password reset token on show form', [
                'token_hash' => hash('sha256', $token),
                'error_code' => $verificationResult['error_code'] ?? 'unknown',
            ]);

            $this->view('auth.forgot-password', [
                'error' => $verificationResult['error'],
            ]);

            return;
        }

        // VALID TOKEN: Show reset form
        $user = $verificationResult['user'];

        Logger::security('info', 'AUTH: Valid token, showing reset form', [
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $token),
        ]);

        $this->view('auth.reset-password', [
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Processo reset password - ENTERPRISE INTEGRATION
     *
     * Integrato completamente con PasswordResetService
     */
    public function resetPassword(): void
    {
        // ENTERPRISE SECURITY: Anti-cache headers to prevent back button access
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past

        // ENTERPRISE: Log request entry
        Logger::security('info', 'AUTH: Password reset submission started', [
            'has_token' => !empty($_POST['token'] ?? ''),
            'has_password' => !empty($_POST['password'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        $this->validateCsrf();

        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // ====================================================================
        // BASIC VALIDATION
        // ====================================================================
        $errors = [];

        if (empty($token)) {
            $errors[] = 'Token non valido';
        }

        if (empty($password)) {
            $errors[] = 'La password è obbligatoria';
        }

        if (!empty($errors)) {
            $this->view('auth.reset-password', [
                'errors' => $errors,
                'token' => $token,
            ]);

            return;
        }

        // ====================================================================
        // ENTERPRISE: Call PasswordResetService (handles all logic)
        // ====================================================================
        // Service gestisce:
        // - Token validation (expiry, usage)
        // - Password strength validation
        // - Password hashing (bcrypt cost 12)
        // - Database update (atomic transaction)
        // - Session invalidation (security)
        // - Confirmation email (async via workers)
        // - Metrics tracking
        // - Security logging
        // ====================================================================
        $result = $this->getPasswordResetService()->resetPassword($token, $password, $confirmPassword);

        Logger::security('info', 'AUTH: PasswordResetService reset completed', [
            'success' => $result['success'],
            'error_code' => $result['error_code'] ?? null,
        ]);

        // ====================================================================
        // RESPONSE: Handle result
        // ====================================================================
        if ($result['success']) {
            // SUCCESS: Show success message on same page, then auto-redirect
            Logger::security('info', 'AUTH: Password reset completed successfully', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // ENTERPRISE UX: Show success on same page with auto-redirect
            // This provides immediate feedback and prevents back button issues
            $this->view('auth.reset-password', [
                'success' => $result['message'],
                'redirect_to_login' => true,
                'token' => $token, // Keep token for form compatibility
            ]);

        } else {
            // ERROR: Show errors on reset form
            if (isset($result['errors'])) {
                // Multiple validation errors
                $this->view('auth.reset-password', [
                    'errors' => $result['errors'],
                    'token' => $token,
                ]);
            } else {
                // Single error
                $this->view('auth.reset-password', [
                    'error' => $result['error'],
                    'token' => $token,
                ]);
            }
        }
    }

    /**
     * Verifica email con token - Enterprise Grade Security
     */
    public function verifyEmail(): void
    {
        // ENTERPRISE SECURITY: Anti-cache headers to prevent back button access
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past

        $token = trim(EnterpriseGlobalsManager::getGet('token', ''));

        // ENTERPRISE: Se non c'è token, controlla se è un reload della pagina
        if (empty($token)) {
            $sessionStatus = $_SESSION['verification_status'] ?? null;
            $verificationCompletedAt = $_SESSION['verification_completed_at'] ?? null;

            // Se c'è uno stato di verifica nella sessione (reload dopo verifica)
            if ($sessionStatus) {
                // ENTERPRISE: Se è passato troppo tempo (> 15 secondi), redirect al login
                if ($verificationCompletedAt && (time() - $verificationCompletedAt) > 15) {
                    // Cleanup session
                    unset($_SESSION['verification_status'], $_SESSION['verification_message'],
                        $_SESSION['verification_error'], $_SESSION['verified_user_nickname'],
                        $_SESSION['verification_completed_at']);

                    // Log redirect to login
                    Logger::security('info', 'AUTH: Verification page expired, redirecting to login', [
                        'seconds_elapsed' => time() - $verificationCompletedAt,
                        'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
                    ]);

                    // ENTERPRISE: Redirect to login with success message
                    EnterpriseGlobalsManager::setSession('success', 'Email verificata! Ora puoi accedere al tuo account.');
                    $this->redirect(url('auth/login?verified=success'));

                    return;
                }

                // ENTERPRISE: Ancora entro i 15 secondi, mostra la view con lo stato corrente
                // Questo gestisce il caso di refresh della pagina subito dopo la verifica
                Logger::security('info', 'AUTH: Verification page reloaded within time limit', [
                    'status' => $sessionStatus,
                    'seconds_elapsed' => $verificationCompletedAt ? (time() - $verificationCompletedAt) : 0,
                ]);

                $this->view('auth.verify-email');

                return;
            }

            // ENTERPRISE v6.9: Check if user is already logged in and verified
            // This handles the common case where user clicks link in WebView (Gmail/Facebook app),
            // verification succeeds, then they open the link again in regular browser
            $userId = $_SESSION['user_id'] ?? null;
            if ($userId) {
                // User is logged in - check if their email is already verified
                $db = db();
                $user = $db->findOne(
                    "SELECT email_verified_at, nickname FROM users WHERE id = :id",
                    ['id' => $userId]
                );

                if ($user && $user['email_verified_at']) {
                    // User is already verified! Show friendly message instead of error
                    Logger::security('info', 'AUTH: Verified user accessed verify-email without token', [
                        'user_id' => $userId,
                        'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
                    ]);

                    EnterpriseGlobalsManager::setSession('verification_status', 'already_verified');
                    EnterpriseGlobalsManager::setSession('verification_message', 'Il tuo account è già stato verificato. Puoi accedere a tutte le funzionalità di need2talk!');
                    EnterpriseGlobalsManager::setSession('verified_user_nickname', $user['nickname'] ?? '');
                    $this->view('auth.verify-email');

                    return;
                }
            }

            // ENTERPRISE v6.9: For non-logged users without token, redirect to login
            // with a friendly message - they probably verified in a different browser/session
            Logger::security('info', 'AUTH: Non-logged user accessed verify-email without token', [
                'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
            ]);

            EnterpriseGlobalsManager::setSession('info', 'Se hai già verificato la tua email, puoi accedere normalmente. Altrimenti controlla la tua casella di posta.');
            $this->redirect(url('auth/login'));

            return;
        }

        // Inizializza il servizio di verifica email
        $emailVerificationService = new \Need2Talk\Services\EmailVerificationService();

        // Verifica il token
        $result = $emailVerificationService->verifyEmailToken($token);

        if ($result['success']) {
            // Successo: Email verificata
            EnterpriseGlobalsManager::setSession('verification_status', 'success');
            EnterpriseGlobalsManager::setSession('verification_message', $result['message']);
            EnterpriseGlobalsManager::setSession('verified_user_nickname', $result['user']['nickname'] ?? '');

            // ENTERPRISE SECURITY: Mark that verification was completed to prevent page reload
            EnterpriseGlobalsManager::setSession('verification_completed_at', time());

            // Log successful verification
            Logger::security('info', 'AUTH: Email verification successful', [
                'user_id' => $result['user']['id'] ?? null,
                'token_hash' => hash('sha256', $token),
            ]);

        } else {
            // ENTERPRISE PRIVACY PROTECTION: Gestisci casi specifici con messaggi appropriati
            $errorCode = $result['error_code'] ?? null;

            // Caso 1: Email già verificata (token già usato o account già attivo)
            if ($errorCode === 'ALREADY_VERIFIED') {
                EnterpriseGlobalsManager::setSession('verification_status', 'already_verified');
                EnterpriseGlobalsManager::setSession('verification_message', 'Questo account è già stato verificato. Puoi accedere normalmente.');

                Logger::security('info', 'AUTH: Email verification attempted on already verified account', [
                    'token_hash' => hash('sha256', $token),
                    'privacy_note' => 'user_informed_account_active',
                ]);
            }
            // Caso 2: Token scaduto (richiede resend)
            elseif (isset($result['action']) && $result['action'] === 'resend_required') {
                EnterpriseGlobalsManager::setSession('verification_status', 'expired');
                EnterpriseGlobalsManager::setSession('verification_error', $result['error']);
            }
            // Caso 3: Token invalido o altri errori (PRIVACY: messaggio generico)
            else {
                EnterpriseGlobalsManager::setSession('verification_status', 'error');
                EnterpriseGlobalsManager::setSession('verification_error', 'Link di verifica non valido o scaduto. Richiedi un nuovo link.');

                Logger::security('warning', 'AUTH: Email verification failed', [
                    'error' => $result['error'],
                    'error_code' => $errorCode,
                    'token_hash' => hash('sha256', $token),
                    'privacy_note' => 'generic_message_for_privacy',
                ]);
            }
        }

        $this->view('auth.verify-email');
    }

    /**
     * Mostra form per richiedere nuova email di verifica
     */
    public function showResendVerification(): void
    {
        $this->view('auth.resend-verification');
    }

    /**
     * Processa richiesta nuova email di verifica - Enterprise Grade con sistema esistente
     */
    public function resendVerification(): void
    {
        // ENTERPRISE SECURITY: CSRF validation with comprehensive logging
        Logger::security('info', 'AUTH: Resend verification request started', [
            'has_email' => !empty($_POST['email'] ?? ''),
            'csrf_token_present' => !empty($_POST['_csrf_token'] ?? ''),
            'session_id' => substr(session_id(), 0, 8),
            'ip_address' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', ''),
            'user_agent_hash' => hash('sha256', EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', '')),
        ]);

        $this->validateCsrf();

        Logger::security('debug', 'AUTH: CSRF validation passed for resend verification', []);

        // CRITICAL FIX: Normalize email to lowercase (registration saves lowercase)
        $email = strtolower(trim($_POST['email'] ?? ''));

        // ENTERPRISE SECURITY: Comprehensive input validation
        if (empty($email)) {
            Logger::security('warning', 'AUTH: Resend verification - email field empty', []);
            EnterpriseGlobalsManager::setSession('errors', ['email' => 'Indirizzo email richiesto']);
            $this->redirect(url('auth/resend-verification-form'));

            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Logger::security('warning', 'AUTH: Resend verification - invalid email format', [
                'email_hash' => hash('sha256', $email),
            ]);
            EnterpriseGlobalsManager::setSession('errors', ['email' => 'Formato email non valido']);
            EnterpriseGlobalsManager::setSession('old_input', ['email' => $email]);
            $this->redirect(url('auth/resend-verification-form'));

            return;
        }

        // ================================================================
        // ENTERPRISE RATE LIMITING: Resend Verification Protection
        // Layer 1: Controller-level protection (2 tentativi/minuto)
        // Layer 2: Service-level protection (handled by EmailVerificationService)
        // ================================================================
        $rateLimitKey = "resend_verification:{$email}";
        if (!$this->getRateLimiter()->allow($rateLimitKey, 'resend_verification')) {
            Logger::security('warning', 'SECURITY: Resend verification rate limit exceeded (controller level)', [
                'email_hash' => hash('sha256', strtolower($email)),
                'ip' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', 'unknown'),
            ]);

            EnterpriseGlobalsManager::setSession('errors', ['general' => 'Troppi tentativi. Attendi un minuto prima di riprovare.']);
            EnterpriseGlobalsManager::setSession('old_input', ['email' => $email]);
            $this->redirect(url('auth/resend-verification-form'));

            return;
        }

        // ENTERPRISE: Use existing EmailVerificationService with all enterprise features
        $emailVerificationService = new \Need2Talk\Services\EmailVerificationService();

        Logger::security('debug', 'AUTH: Calling EmailVerificationService resendVerificationEmail', [
            'email_hash' => hash('sha256', $email),
            'service_used' => 'EmailVerificationService',
        ]);

        // Call the existing resend method with comprehensive error handling
        $result = $emailVerificationService->resendVerificationEmail($email);

        Logger::security('info', 'AUTH: EmailVerificationService result', [
            'success' => $result['success'],
            'has_error_code' => isset($result['error_code']) && !empty($result['error_code']),
            'error_code' => $result['error_code'] ?? null,
            'has_error' => isset($result['error']) && !empty($result['error']),
            'error' => $result['error'] ?? null,
        ]);

        // ENTERPRISE PRIVACY PROTECTION: Gestisci errori e successo con protezione privacy
        $errorCode = $result['error_code'] ?? '';

        // Caso 1: Rate limiting - deve essere mostrato (legittimo blocco temporaneo)
        if (in_array($errorCode, ['RATE_LIMIT_EXCEEDED', 'RESEND_RATE_LIMIT'], true)) {
            $errorMessage = $result['error'] ?? 'Troppi tentativi. Riprova più tardi.';
            EnterpriseGlobalsManager::setSession('errors', ['general' => $errorMessage]);
            EnterpriseGlobalsManager::setSession('old_input', ['email' => $email]);

            Logger::security('warning', 'SECURITY: Rate limit exceeded for email verification resend', [
                'email_hash' => hash('sha256', $email),
                'ip_address' => EnterpriseGlobalsManager::getServer('REMOTE_ADDR', ''),
                'user_agent_hash' => hash('sha256', EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', '')),
            ]);

            $this->redirect(url('auth/resend-verification-form'));

            return;
        }

        // Caso 2: PRIVACY PROTECTION - Per tutti gli altri casi (user not found, already verified, ecc)
        // Mostra SEMPRE successo per non rivelare se l'email esiste nel sistema
        if ($result['success'] || in_array($errorCode, ['USER_NOT_FOUND', 'ALREADY_VERIFIED'], true)) {
            // SUCCESS or PRIVACY: Set session data for verify-email-sent page
            $successMessage = $result['success']
                ? $result['message']
                : 'Se l\'indirizzo email è registrato, riceverai un link di verifica.';

            EnterpriseGlobalsManager::setSession('success', $successMessage);
            EnterpriseGlobalsManager::setSession('verification_email', $email);

            // ENTERPRISE: Set additional session data for verify-email-sent page navigation
            EnterpriseGlobalsManager::setSession('just_registered', true);
            EnterpriseGlobalsManager::setSession('verification_page_accessed', false);

            // ENTERPRISE: Set nickname for verify-email-sent page (only if email exists)
            if ($result['success']) {
                $user = $this->userModel->findByEmail($email);

                if ($user) {
                    EnterpriseGlobalsManager::setSession('verification_nickname', $user['nickname']);
                }
            }

            Logger::security('info', 'AUTH: Verification email resend response', [
                'email_hash' => hash('sha256', $email),
                'actual_success' => $result['success'],
                'error_code' => $errorCode ?: 'none',
                'privacy_protected' => in_array($errorCode, ['USER_NOT_FOUND', 'ALREADY_VERIFIED'], true),
                'redirect_to' => 'verify-email-sent',
            ]);

            $this->redirect(url('auth/verify-email-sent'));
        } else {
            // Caso 3: Altri errori non previsti (fallback generico)
            $errorMessage = 'Si è verificato un errore. Riprova più tardi.';
            EnterpriseGlobalsManager::setSession('errors', ['email' => $errorMessage]);
            EnterpriseGlobalsManager::setSession('old_input', ['email' => $email]);

            Logger::security('error', 'AUTH: Verification email resend failed - unexpected error', [
                'email_hash' => hash('sha256', $email),
                'error_code' => $errorCode ?: 'UNKNOWN',
                'error' => $result['error'] ?? 'unknown',
            ]);

            $this->redirect(url('auth/resend-verification-form'));
        }
    }

    /**
     * Mostra pagina conferma invio email verifica con controllo accesso e timer
     */
    public function showVerificationSent(): void
    {
        // ENTERPRISE SECURITY: Anti-cache headers to prevent back button access
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past

        // SECURITY: Pagina accessibile solo se utente non autenticato
        if (SecureSessionManager::isAuthenticated()) {
            // Se utente già loggato, redirect alla dashboard
            $this->redirect(url('/'));

            return;
        }

        // SECURITY: Controlla che sia stata appena fatta una registrazione
        $canAccess = isset($_SESSION['just_registered']) && $_SESSION['just_registered'] === true;
        $verificationEmail = $_SESSION['verification_email'] ?? null;
        $verificationNickname = $_SESSION['verification_nickname'] ?? null;
        $pageAlreadyAccessed = isset($_SESSION['verification_page_accessed']) && $_SESSION['verification_page_accessed'] === true;

        // SECURITY: Se la pagina è già stata visualizzata o mancano i dati di registrazione, redirect alla home
        if ($pageAlreadyAccessed || !$canAccess || !$verificationEmail || !$verificationNickname) {
            // Pulisci le variabili di sessione
            unset($_SESSION['just_registered']);
            unset($_SESSION['verification_email']);
            unset($_SESSION['verification_nickname']);
            unset($_SESSION['verification_page_accessed']);

            // Redirect alla home
            $this->redirect(url('/'));

            return;
        }

        // SECURITY: Marca che la pagina è stata visualizzata (one-time access)
        $_SESSION['verification_page_accessed'] = true;

        // SECURITY: Imposta timer per auto-redirect e pulizia sessione
        $_SESSION['verification_page_timer_start'] = time();

        $this->view('auth.verify-email-sent');
    }

    /**
     * Pulisce le variabili di sessione per verification page (AJAX endpoint)
     */
    public function clearVerificationSession(): void
    {
        // SECURITY: Solo richieste AJAX
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);

            return;
        }

        // SECURITY: Pulisci tutte le variabili di sessione relative alla verifica
        unset($_SESSION['just_registered']);
        unset($_SESSION['verification_email']);
        unset($_SESSION['verification_nickname']);
        unset($_SESSION['verification_page_accessed']);
        unset($_SESSION['verification_page_timer_start']);

        // Log cleanup for security monitoring
        Logger::security('info', 'AUTH: Verification session variables cleared', [
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
            'method' => 'clearVerificationSession',
        ]);

        // Return success response
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }

    // === METODI PRIVATI === //

    /**
     * Esegui login riuscito - ENTERPRISE TRACKING
     *
     * ENTERPRISE TIPS: Track last_ip and last_login_at in database
     *
     * ENTERPRISE GALAXY FIX (2025-01-17):
     * - Added $redirectUrl parameter for custom redirect (e.g. /complete-profile for OAuth)
     * - Default: redirect to /profile (normal login)
     *
     * @param array $user User data
     * @param bool $remember Remember-me checkbox
     * @param string|null $redirectUrl Custom redirect URL (default: /profile)
     */
    private function performSuccessfulLogin(array $user, bool $remember, ?string $redirectUrl = null): void
    {
        Logger::security('debug', 'AUTH: Starting successful login process', [
            'user_id' => $user['id'],
            'remember_me' => $remember,
        ]);

        // ====================================================================
        // ENTERPRISE GALAXY: Account Status Check (HIGHEST PRIORITY)
        // Blocks banned/suspended users from ALL login methods (normal, Google, etc.)
        // MUST be checked BEFORE any session creation
        // ====================================================================
        $userStatus = $user['status'] ?? 'active';

        if ($userStatus === 'banned') {
            Logger::security('critical', 'AUTH: BANNED user login attempt blocked', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($user['email'])),
                'status' => $userStatus,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $_SESSION['flash_error'] = 'Account sospeso permanentemente. Contatta support@need2talk.it per informazioni.';
            header('Location: /auth/login');
            exit;
        }

        if ($userStatus === 'suspended') {
            Logger::security('warning', 'AUTH: SUSPENDED user login attempt blocked', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($user['email'])),
                'status' => $userStatus,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $_SESSION['flash_error'] = 'Account temporaneamente sospeso. Contatta support@need2talk.it per informazioni.';
            header('Location: /auth/login');
            exit;
        }

        // ====================================================================
        // ENTERPRISE GALAXY: Cross-Platform Session Check
        // Blocks login if already logged in on another platform (moderator/user)
        // Applies to ALL login methods: normal, Google OAuth, future OAuth
        // ====================================================================
        $xplatformCheck = CrossPlatformSessionService::canLogin(
            $user['email'],
            CrossPlatformSessionService::PLATFORM_USER
        );

        if (!$xplatformCheck['allowed']) {
            Logger::security('warning', 'AUTH: Cross-platform login blocked in performSuccessfulLogin', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($user['email'])),
                'blocking_platform' => $xplatformCheck['blocking_platform'] ?? 'unknown',
            ]);

            // Set error message and redirect to login
            $_SESSION['flash_error'] = $xplatformCheck['reason'];
            header('Location: /auth/login');
            exit;
        }

        // ENTERPRISE GALAXY: Reset reCAPTCHA fail counter (successful login)
        try {
            $recaptchaService = new \Need2Talk\Services\RecaptchaService();
            $recaptchaService->resetLoginFails();
            Logger::security('debug', 'AUTH: Reset reCAPTCHA fail counter', [
                'user_id' => $user['id'],
            ]);
        } catch (Exception $e) {
            // Non-blocking: se reset fallisce, login continua
            Logger::error('Failed to reset reCAPTCHA counter', [
                'error' => $e->getMessage(),
            ]);
        }

        // ENTERPRISE: Get client IP address (supports proxy/load balancer)
        $ipAddress = $this->getClientIpAddress();

        // ENTERPRISE UNIFIED: Create session with SecureSessionManager
        // - Redis session storage (instant validation)
        // - PostgreSQL audit trail (GDPR compliance)
        // - Remember-me token if requested
        // - Session activity logging
        Logger::security('debug', 'AUTH: Creating user session (ENTERPRISE UNIFIED)', [
            'user_id' => $user['id'],
            'remember_me' => $remember,
        ]);
        SecureSessionManager::loginUser($user['id'], $remember);

        // ====================================================================
        // ENTERPRISE GALAXY: Register Cross-Platform Session
        // Prevents simultaneous login as User AND Moderator with same email
        // ====================================================================
        CrossPlatformSessionService::registerLogin(
            $user['email'],
            CrossPlatformSessionService::PLATFORM_USER,
            session_id(),
            [
                'ip' => $ipAddress,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]
        );

        // ====================================================================
        // ENTERPRISE GALAXY V6.5: Generate JWT Token for WebSocket Authentication
        // ====================================================================
        // The JWT token enables secure, stateless authentication for WebSocket
        // connections. The token contains only the user UUID (minimal payload)
        // and expires after 24 hours (re-login daily regenerates token).
        //
        // SECURITY:
        // - Token is stored in PHP session (httpOnly, secure cookie)
        // - Token is passed to frontend via server-rendered page
        // - WebSocket server validates token signature + expiration
        // - No sensitive data in token payload (stateless, no DB query needed)
        //
        // PERFORMANCE:
        // - Token generation: ~0.1ms (HS256 is very fast)
        // - Token verification: ~0.1ms (no DB query)
        // - Stored in session: auto-expires with session
        // ====================================================================
        try {
            // ENTERPRISE V9.4: Include nickname AND avatar_url in JWT for WebSocket chat
            // This avoids database/Redis lookup on WebSocket connection
            // avatar_url is used by MessageList.js to render sender avatars in real-time
            // ENTERPRISE V9.5: Use get_avatar_url() to convert relative path to full URL
            $wsToken = JWTService::generate($user['uuid'], [
                'nickname' => $user['nickname'] ?? 'Anonymous',
                'avatar_url' => get_avatar_url($user['avatar_url'] ?? null),
            ]);
            EnterpriseGlobalsManager::setSession('ws_token', $wsToken);

            Logger::security('debug', 'AUTH: WebSocket JWT token generated', [
                'user_id' => $user['id'],
                'user_uuid' => substr($user['uuid'], 0, 8) . '...',
                'nickname' => $user['nickname'] ?? 'Anonymous',
                'has_avatar' => !empty($user['avatar_url']),
                'token_length' => strlen($wsToken),
            ]);

            // ENTERPRISE V9.4: Also cache user data with UUID key for WebSocket fallback
            // WebSocket server (Swoole) cannot use db() helper, so it reads from Redis
            // This ensures ws_get_user() can find user data even for old JWT tokens
            try {
                $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_enterprise');
                if ($redis) {
                    $uuidCacheKey = "need2talk:user:{$user['uuid']}";
                    $userData = [
                        'uuid' => $user['uuid'],
                        'nickname' => $user['nickname'] ?? 'Anonymous',
                        'avatar_url' => get_avatar_url($user['avatar_url'] ?? null),
                    ];
                    // TTL: 24 hours (same as JWT lifetime)
                    $redis->setex($uuidCacheKey, 86400, json_encode($userData));
                }
            } catch (\Throwable $e) {
                // Non-blocking: WebSocket will use JWT data instead
            }

            // ENTERPRISE V9.5: Cache friend list for Swoole presence fan-out
            // Swoole cannot query DB, so it reads friend list from Redis cache
            // This enables real-time presence notifications when user disconnects
            try {
                $db = db();
                $friends = $db->findMany(
                    "SELECT CASE
                        WHEN f.user_uuid = :uuid THEN f.friend_uuid
                        ELSE f.user_uuid
                     END as friend_uuid
                     FROM friendships f
                     WHERE (f.user_uuid = :uuid OR f.friend_uuid = :uuid)
                       AND f.status = 'accepted'
                       AND f.deleted_at IS NULL",
                    ['uuid' => $user['uuid']]
                );

                if (!empty($friends)) {
                    $friendUuids = array_column($friends, 'friend_uuid');
                    $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_enterprise');
                    if ($redis) {
                        $friendsCacheKey = "need2talk:friends:{$user['uuid']}";
                        // TTL: 24 hours (same as JWT/session)
                        $redis->setex($friendsCacheKey, 86400, json_encode($friendUuids));
                    }
                }
            } catch (\Throwable $e) {
                // Non-blocking: Presence fan-out will use fallback polling
            }
        } catch (Exception $e) {
            // Non-blocking: if JWT generation fails, WebSocket won't work but login continues
            Logger::error('AUTH: Failed to generate WebSocket JWT token', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);
        }

        // ENTERPRISE TIPS: Update last_ip, last_login_at, login_count in users table
        try {
            $pdo = db_pdo();
            $stmt = $pdo->prepare("
                UPDATE users
                SET last_ip = :ip,
                    last_login_at = NOW(),
                    login_count = COALESCE(login_count, 0) + 1,
                    updated_at = NOW()
                WHERE id = :user_id
            ");

            $stmt->execute([
                'ip' => $ipAddress,
                'user_id' => $user['id'],
            ]);

            Logger::security('info', 'AUTH: Updated user login tracking', [
                'user_id' => $user['id'],
                'ip' => $ipAddress,
                'login_count' => ($user['login_count'] ?? 0) + 1,
            ]);

        } catch (\PDOException $e) {
            // Don't fail login if tracking fails
            Logger::database('error', 'DATABASE: Failed to update login tracking', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);
        }

        // Reset tentativi falliti
        Logger::security('debug', 'AUTH: Resetting failed login attempts', [
            'user_id' => $user['id'],
        ]);
        $this->userModel->resetFailedAttempts($user['id']);

        // ENTERPRISE SECURITY LOG: Successful login
        Logger::security('info', 'AUTH: Successful login', [
            'user_id' => $user['id'],
            'email_hash' => hash('sha256', strtolower($user['email'])),
            'nickname' => $user['nickname'],
            'remember_me' => $remember,
            'ip' => $ipAddress,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'session_id' => session_id(),
        ]);

        // ENTERPRISE GALAXY FIX (2025-01-17): Custom redirect support
        // 2025-12-20: Changed default from /profile to /feed for better UX
        $finalRedirect = $redirectUrl ?? url('feed');

        Logger::security('debug', 'AUTH: Login process completed successfully', [
            'user_id' => $user['id'],
            'redirect_to' => $finalRedirect,
            'custom_redirect' => $redirectUrl !== null,
        ]);

        // Redirect (default: feed, custom: e.g. /complete-profile for OAuth)
        $this->redirect($finalRedirect);
    }

    /**
     * Get client IP address - ENTERPRISE PROXY/LOAD BALANCER SUPPORT
     *
     * Supports:
     * - Direct connections
     * - Proxy servers (X-Forwarded-For)
     * - Cloudflare (CF-Connecting-IP)
     * - Load balancers
     *
     * @return string IP address
     */
    private function getClientIpAddress(): string
    {
        // Priority order for IP detection
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',   // Standard proxy
            'HTTP_X_REAL_IP',         // Nginx proxy
            'HTTP_CLIENT_IP',         // Proxy
            'REMOTE_ADDR',            // Direct connection
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // X-Forwarded-For can contain multiple IPs (client, proxy1, proxy2)
                // Take the first one (client IP)
                if ($header === 'HTTP_X_FORWARDED_FOR' && strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        // Fallback
        return 'unknown';
    }

    /**
     * Validazione dati registrazione
     */
    private function validateRegistrationData(array $data): array
    {
        $errors = [];

        // Nickname
        if (empty($data['nickname']) || strlen($data['nickname']) < 3) {
            $errors[] = 'Il nickname deve essere di almeno 3 caratteri';
        } elseif (strlen($data['nickname']) > 50) {
            $errors[] = 'Il nickname deve essere massimo 50 caratteri';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $data['nickname'])) {
            $errors[] = 'Il nickname può contenere solo lettere, numeri, underscore e trattini';
        } elseif ($this->userModel->nicknameExists($data['nickname'])) {
            $errors[] = 'Nickname già esistente';
        }

        // Email
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Indirizzo email non valido';
        } elseif ($this->userModel->emailExists($data['email'])) {
            $errors[] = 'Email già registrata';
        }

        // Password
        if (empty($data['password']) || strlen($data['password']) < 8) {
            $errors[] = 'La password deve essere di almeno 8 caratteri';
        } elseif ($data['password'] !== $data['confirm_password']) {
            $errors[] = 'Le password non coincidono';
        }

        // Età (almeno 18 anni) - REQUISITO NEED2TALK
        $currentYear = (int) date('Y');

        if ($data['birth_year'] < 1900 || $data['birth_year'] > $currentYear - 18) {
            $errors[] = 'Devi avere almeno 18 anni per registrarti su need2talk';
        }

        if ($data['birth_month'] < 1 || $data['birth_month'] > 12) {
            $errors[] = 'Mese di nascita non valido';
        }

        // Accettazione termini
        if (!$data['accept_terms']) {
            $errors[] = 'Devi accettare i termini di servizio';
        }

        if (!$data['accept_privacy']) {
            $errors[] = 'Devi accettare la privacy policy';
        }

        return $errors;
    }

    /**
     * Log tentativo login fallito
     */
    private function logFailedLogin(string $email, string $reason): void
    {
        // ENTERPRISE SECURITY LOG: Failed login attempt
        Logger::security('warning', 'AUTH: Login failed', [
            'email_hash' => hash('sha256', strtolower($email)),
            'reason' => $reason,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => time(),
        ]);

        // General log
        Logger::warning('Login failed', [
            'email' => $email,
            'reason' => $reason,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    }

    // ========================================================================
    // GOOGLE OAUTH 2.0 METHODS - ENTERPRISE GALAXY
    // ========================================================================

    /**
     * Redirect to Google OAuth consent screen
     *
     * Workflow:
     * 1. User clicks "Continua con Google" button
     * 2. This method generates OAuth URL with state token (anti-CSRF)
     * 3. Redirect to Google for authentication
     * 4. Google redirects back to /auth/google/callback
     */
    public function googleOAuthRedirect(): void
    {
        try {
            $googleOAuth = new \Need2Talk\Services\GoogleOAuthService();
            $authUrl = $googleOAuth->getAuthUrl();

            // Redirect to Google
            header('Location: ' . $authUrl);
            exit;
        } catch (Exception $e) {
            Logger::error('Google OAuth redirect failed', [
                'error' => $e->getMessage(),
            ]);

            // Redirect back to login with error
            $_SESSION['flash_error'] = 'Impossibile connettersi a Google. Riprova più tardi.';
            header('Location: /auth/login');
            exit;
        }
    }

    /**
     * Handle Google OAuth callback
     *
     * Workflow:
     * 1. Google redirects here with code + state parameters
     * 2. Validate state token (anti-CSRF)
     * 3. Exchange code for access token
     * 4. Fetch user info from Google
     * 5. Register new user OR login existing user
     * 6. Create session and redirect to dashboard
     */
    public function googleOAuthCallback(): void
    {
        try {
            // Get parameters from Google
            $code = $_GET['code'] ?? '';
            $state = $_GET['state'] ?? '';

            if (empty($code)) {
                throw new Exception('Missing authorization code from Google');
            }

            // Handle OAuth flow
            $googleOAuth = new \Need2Talk\Services\GoogleOAuthService();
            $googleData = $googleOAuth->handleCallback($code, $state);

            // Register or login user
            $result = $googleOAuth->registerOrLogin($googleData);

            // Load full user data
            // ENTERPRISE GALAXY V6.5: Include uuid for JWT token generation
            // ENTERPRISE GALAXY V6.6: BYPASS CACHE for auth-critical queries
            // User status MUST be real-time to enforce bans/suspensions correctly
            $db = db();
            $user = $db->findOne(
                "SELECT id, uuid, email, nickname, avatar_url, email_verified, oauth_provider, status
                 FROM users
                 WHERE id = ?
                 LIMIT 1",
                [$result['user_id']],
                ['cache' => false]  // CRITICAL: Never cache user status for auth decisions
            );

            if (!$user) {
                throw new Exception('User not found after Google OAuth');
            }

            // ENTERPRISE GALAXY FIX (2025-01-17): GDPR Compliance for OAuth users
            // NEW users (status='pending') MUST complete profile before accessing site
            // EXISTING users (status='active') can login normally

            // Flash message
            if ($result['is_new_user']) {
                $_SESSION['flash_success'] = "Benvenuto {$user['nickname']}! Completa il profilo per iniziare.";
            } else {
                $_SESSION['flash_success'] = "Bentornato {$user['nickname']}!";
            }

            // Log successful OAuth (before session creation)
            Logger::security('info', 'AUTH: Google OAuth successful', [
                'user_id' => $user['id'],
                'email_hash' => hash('sha256', strtolower($user['email'])),
                'is_new_user' => $result['is_new_user'],
                'user_status' => $user['status'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            // ENTERPRISE GALAXY: Redirect based on profile completion status
            // NEW users (status='pending') → /complete-profile (MANDATORY GDPR consent)
            // EXISTING users (status='active') → /feed (2025-12-20: changed from profile)
            $redirectUrl = ($user['status'] === 'pending') ? url('complete-profile') : url('feed');

            // Create session and redirect
            $this->performSuccessfulLogin($user, false, $redirectUrl);
        } catch (Exception $e) {
            Logger::error('Google OAuth callback failed', [
                'error' => $e->getMessage(),
                'code' => $_GET['code'] ?? 'missing',
            ]);

            // Redirect back to login with error
            $_SESSION['flash_error'] = 'Login con Google fallito: ' . $e->getMessage();
            header('Location: /auth/login');
            exit;
        }
    }

    /**
     * ENTERPRISE V6.1: Check if email is a honeypot trap
     *
     * Attackers try admin/root/administrator emails in user forms
     * These emails should NEVER be used in user login/register → instant ban
     *
     * @param string $email Email to check
     * @return bool True if honeypot email (should trigger ban)
     */
    private function isHoneypotEmail(string $email): bool
    {
        $emailLower = strtolower(trim($email));

        // ENTERPRISE: Exact honeypot emails (our domain)
        $exactHoneypots = [
            'admin@need2talk.it',
            'administrator@need2talk.it',
            'root@need2talk.it',
            'webmaster@need2talk.it',
            'postmaster@need2talk.it',
            'hostmaster@need2talk.it',
            'info@need2talk.it',
            'support@need2talk.it', // Real support uses different system
            'test@need2talk.it',
            'demo@need2talk.it',
        ];

        if (in_array($emailLower, $exactHoneypots)) {
            return true;
        }

        // ENTERPRISE: Prefix patterns (any domain) - common attack patterns
        $suspiciousPrefixes = [
            'admin@',
            'administrator@',
            'root@',
            'superuser@',
            'sysadmin@',
            'webmaster@',
            'hostmaster@',
            'postmaster@',
        ];

        foreach ($suspiciousPrefixes as $prefix) {
            if (str_starts_with($emailLower, $prefix)) {
                return true;
            }
        }

        return false;
    }

}
