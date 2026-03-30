<?php

/**
 * RECAPTCHA V3 SERVICE - ENTERPRISE GALAXY LEVEL
 *
 * PSR-12 compliant service per bot protection con Google reCAPTCHA v3
 * Basato su google/recaptcha library
 *
 * RECAPTCHA V3 vs V2:
 * - V3: Score-based (0.0-1.0), NO checkbox, invisibile, machine learning
 * - V2: Challenge-based, checkbox "I'm not a robot", visible
 *
 * FEATURES:
 * - Score-based validation (0.5 threshold = very permissive/blando)
 * - Automatic bot detection (no user interaction)
 * - Failed login tracking (show after 3 failed attempts)
 * - Always-on for registration (prevent bot signups)
 * - Configurable threshold via .env
 *
 * USAGE:
 * - Registration: ALWAYS validate reCAPTCHA
 * - Login: Validate ONLY dopo 3 failed attempts (progressive challenge)
 *
 * SECURITY:
 * - Server-side validation (client can't bypass)
 * - Score threshold configurable (default 0.5 = blando)
 * - Failed attempts logged to security log
 * - IP-based rate limiting integration
 *
 * @package Need2Talk\Services
 * @version 1.0.0
 */

namespace Need2Talk\Services;

use ReCaptcha\ReCaptcha;
use Exception;
use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

class RecaptchaService
{
    private ReCaptcha $recaptcha;
    private float $minScore;
    private string $siteKey;
    private $redis;

    /**
     * Initialize reCAPTCHA service
     *
     * @throws Exception Se mancano credenziali
     */
    public function __construct()
    {
        $secretKey = env('RECAPTCHA_SECRET_KEY');
        $this->siteKey = env('RECAPTCHA_SITE_KEY');
        $this->minScore = (float) env('RECAPTCHA_MIN_SCORE', 0.5);

        if (empty($secretKey) || empty($this->siteKey)) {
            throw new Exception('reCAPTCHA credentials not configured in .env');
        }

        $this->recaptcha = new ReCaptcha($secretKey);

        // Initialize Redis connection
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection();
    }

    /**
     * Get site key for frontend JavaScript
     *
     * @return string Site key pubblico
     */
    public function getSiteKey(): string
    {
        return $this->siteKey;
    }

    /**
     * Verify reCAPTCHA v3 token
     *
     * ENTERPRISE V6.1: Safari iOS Compatibility Fix
     * - Accepts both need2talk.it and www.need2talk.it hostnames
     * - Detects browser errors (Safari ITP, content blockers)
     * - Returns specific error type for graceful degradation
     *
     * @param string $token Token da frontend (grecaptcha.execute)
     * @param string $action Action name (es: 'register', 'login')
     * @param string|null $expectedAction Expected action (validation)
     * @return array ['success' => bool, 'score' => float, 'errors' => array, 'browser_error' => bool]
     */
    public function verify(string $token, string $action, ?string $expectedAction = null): array
    {
        // Get user IP
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;

        // ENTERPRISE V6.1: Get actual hostname from request (handles www vs non-www)
        $requestHostname = $_SERVER['HTTP_HOST'] ?? parse_url(env('APP_URL'), PHP_URL_HOST);
        // Strip port if present
        $requestHostname = preg_replace('/:\d+$/', '', $requestHostname);

        // ENTERPRISE V6.1: Accept both www and non-www variants
        $allowedHostnames = [
            'need2talk.it',
            'www.need2talk.it',
        ];

        // If request hostname is in allowed list, use it; otherwise fallback to APP_URL
        $expectedHostname = in_array($requestHostname, $allowedHostnames)
            ? $requestHostname
            : parse_url(env('APP_URL'), PHP_URL_HOST);

        // Verify with Google API
        $response = $this->recaptcha
            ->setExpectedHostname($expectedHostname)
            ->setExpectedAction($expectedAction ?? $action)
            ->setScoreThreshold($this->minScore)
            ->verify($token, $remoteIp);

        $success = $response->isSuccess();
        $score = $response->getScore();
        $errors = $response->getErrorCodes();

        // ENTERPRISE V6.1: Detect browser errors (Safari ITP, content blockers)
        // These errors are NOT security issues - user's browser blocked reCAPTCHA script
        $browserErrors = ['browser-error', 'timeout-or-duplicate'];
        $isBrowserError = !empty(array_intersect($errors, $browserErrors));

        // ENTERPRISE V6.1: Detect hostname mismatch (www vs non-www)
        // If ONLY hostname-mismatch error (no other issues), it's likely www redirect issue
        $isHostnameMismatchOnly = count($errors) === 1 && in_array('hostname-mismatch', $errors);

        // Log result
        if (!$success) {
            $logLevel = $isBrowserError ? 'info' : 'warning';
            $logMessage = $isBrowserError
                ? 'reCAPTCHA blocked by browser (Safari ITP/content blocker)'
                : 'reCAPTCHA validation failed';

            Logger::security($logLevel, $logMessage, [
                'action' => $action,
                'score' => $score,
                'errors' => $errors,
                'ip' => $remoteIp,
                'browser_error' => $isBrowserError,
                'hostname_mismatch_only' => $isHostnameMismatchOnly,
                'request_hostname' => $requestHostname,
                'expected_hostname' => $expectedHostname,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);
        } else {
            Logger::debug('reCAPTCHA validation successful', [
                'action' => $action,
                'score' => $score,
                'ip' => $remoteIp,
            ]);
        }

        return [
            'success' => $success,
            'score' => $score,
            'errors' => $errors,
            'browser_error' => $isBrowserError,
            'hostname_mismatch_only' => $isHostnameMismatchOnly,
        ];
    }

    /**
     * Check if reCAPTCHA should be shown for login
     *
     * Uses Redis to track failed login attempts per IP
     * After 3 failed attempts, reCAPTCHA becomes required
     *
     * @param string|null $ip IP address (default: current user IP)
     * @return bool TRUE se reCAPTCHA è richiesto
     */
    public function isRequiredForLogin(?string $ip = null): bool
    {
        $ip = $ip ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "recaptcha:login_fails:{$ip}";

        try {
            $failCount = (int) $this->redis->get($key);

            // Require reCAPTCHA dopo 3 failed attempts
            return $failCount >= 3;
        } catch (Exception $e) {
            // Fallback: se Redis non disponibile, richiedi sempre (sicuro)
            Logger::warning('Redis unavailable for reCAPTCHA check', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Increment failed login attempts counter
     *
     * @param string|null $ip IP address (default: current user IP)
     * @return int New fail count
     */
    public function incrementLoginFails(?string $ip = null): int
    {
        $ip = $ip ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "recaptcha:login_fails:{$ip}";

        try {
            $failCount = $this->redis->incr($key);

            // Expire dopo 1 ora (reset automatico)
            if ($failCount === 1) {
                $this->redis->expire($key, 3600);
            }

            return $failCount;
        } catch (Exception $e) {
            Logger::warning('Failed to increment login fails counter', [
                'error' => $e->getMessage(),
                'ip' => $ip,
            ]);
            return 0;
        }
    }

    /**
     * Reset failed login attempts counter (successful login)
     *
     * @param string|null $ip IP address (default: current user IP)
     * @return void
     */
    public function resetLoginFails(?string $ip = null): void
    {
        $ip = $ip ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "recaptcha:login_fails:{$ip}";

        try {
            $this->redis->del($key);
        } catch (Exception $e) {
            Logger::warning('Failed to reset login fails counter', [
                'error' => $e->getMessage(),
                'ip' => $ip,
            ]);
        }
    }

    /**
     * Get current failed login attempts count
     *
     * @param string|null $ip IP address (default: current user IP)
     * @return int Fail count
     */
    public function getLoginFailsCount(?string $ip = null): int
    {
        $ip = $ip ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "recaptcha:login_fails:{$ip}";

        try {
            return (int) $this->redis->get($key);
        } catch (Exception $e) {
            return 0;
        }
    }
}
