<?php

namespace Need2Talk\Middleware;

use Need2Talk\Services\UserRateLimitService;

/**
 * NEED2TALK - EMAIL RATE LIMITING MIDDLEWARE
 *
 * PROTEZIONE ANTI-SPAM: Previene email bombing attacks
 *
 * FLUSSO SICUREZZA EMAIL:
 * 1. Controlla rate limit per IP e utente
 * 2. Blocca invii email eccessivi
 * 3. Previene abuso sistema email
 * 4. Log tentativi sospetti
 * 5. Scalabile per migliaia di utenti
 *
 * LIMITI SICUREZZA:
 * - 5 email per IP per ora
 * - 10 email per utente per ora
 * - 50 email per IP per giorno
 * - Blacklist automatica IP abusivi
 */
class EmailRateLimitMiddleware
{
    private UserRateLimitService $userRateLimitService;

    public function __construct()
    {
        $this->userRateLimitService = new UserRateLimitService();
    }

    /**
     * CONTROLLO RATE LIMIT EMAIL per sistema INATTACCABILE
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        $ip = $this->getUserIP();
        $userId = $_SESSION['user_id'] ?? null;

        // CONTROLLO 1: Rate limit per IP
        if (!$this->checkIPRateLimit($ip)) {
            $this->logSuspiciousActivity($ip, 'IP_EMAIL_RATE_LIMIT_EXCEEDED');
            $this->sendRateLimitResponse('Troppi invii email da questo IP. Riprova più tardi.');
        }

        // CONTROLLO 2: Rate limit per utente (se autenticato)
        if ($userId && !$this->checkUserRateLimit($userId)) {
            $this->logSuspiciousActivity($ip, 'USER_EMAIL_RATE_LIMIT_EXCEEDED', $userId);
            $this->sendRateLimitResponse('Troppi invii email per questo account. Riprova più tardi.');
        }

        // CONTROLLO 3: Blacklist IP
        if ($this->isIPBlacklisted($ip)) {
            $this->logSuspiciousActivity($ip, 'BLACKLISTED_IP_EMAIL_ATTEMPT');
            $this->sendRateLimitResponse('Accesso negato.', 403);
        }

        // REGISTRA invio email per tracking
        $this->recordEmailSent($ip, $userId);

        // Middleware passed - Router will continue automatically
    }

    /**
     * CONFIGURA WHITELIST IP per amministratori
     */
    public static function getWhitelistedIPs(): array
    {
        return [
            '127.0.0.1',     // Localhost
            '::1',           // IPv6 localhost
            // Aggiungi IP admin se necessario
        ];
    }

    /**
     * CONTROLLA RATE LIMIT per IP
     */
    private function checkIPRateLimit(string $ip): bool
    {
        // LIMITE ORARIO: 5 email per IP per ora
        $hourlyKey = "email_rate_ip_hour_{$ip}";
        $hourlyCount = $this->userRateLimitService->getCount($hourlyKey, 3600); // 1 ora

        if ($hourlyCount >= 5) {
            return false;
        }

        // LIMITE GIORNALIERO: 50 email per IP per giorno
        $dailyKey = "email_rate_ip_day_{$ip}";
        $dailyCount = $this->userRateLimitService->getCount($dailyKey, 86400); // 24 ore

        if ($dailyCount >= 50) {
            // BLACKLIST automatica per IP troppo attivi
            $this->blacklistIP($ip, 'EXCESSIVE_DAILY_EMAILS');

            return false;
        }

        return true;
    }

    /**
     * CONTROLLA RATE LIMIT per utente
     */
    private function checkUserRateLimit(int $userId): bool
    {
        // LIMITE ORARIO: 10 email per utente per ora
        $hourlyKey = "email_rate_user_hour_{$userId}";
        $hourlyCount = $this->userRateLimitService->getCount($hourlyKey, 3600);

        if ($hourlyCount >= 10) {
            return false;
        }

        // LIMITE GIORNALIERO: 100 email per utente per giorno
        $dailyKey = "email_rate_user_day_{$userId}";
        $dailyCount = $this->userRateLimitService->getCount($dailyKey, 86400);

        if ($dailyCount >= 100) {
            return false;
        }

        return true;
    }

    /**
     * REGISTRA invio email per tracking
     */
    private function recordEmailSent(string $ip, ?int $userId): void
    {
        // INCREMENTA counter IP
        $this->userRateLimitService->incrementCount("email_rate_ip_hour_{$ip}", 3600);
        $this->userRateLimitService->incrementCount("email_rate_ip_day_{$ip}", 86400);

        // INCREMENTA counter utente se autenticato
        if ($userId) {
            $this->userRateLimitService->incrementCount("email_rate_user_hour_{$userId}", 3600);
            $this->userRateLimitService->incrementCount("email_rate_user_day_{$userId}", 86400);
        }
    }

    /**
     * BLACKLIST IP per comportamento abusivo
     */
    private function blacklistIP(string $ip, string $reason): void
    {
        // BLACKLIST per 24 ore
        $blacklistKey = "ip_blacklist_{$ip}";
        $this->userRateLimitService->setWithTTL($blacklistKey, $reason, 86400);

        // LOG blacklisting
        $this->logSuspiciousActivity($ip, "IP_BLACKLISTED: {$reason}");
    }

    /**
     * CONTROLLA se IP è blacklisted
     */
    private function isIPBlacklisted(string $ip): bool
    {
        $blacklistKey = "ip_blacklist_{$ip}";

        return $this->userRateLimitService->exists($blacklistKey);
    }

    /**
     * INVIA RESPONSE rate limit exceeded
     */
    private function sendRateLimitResponse(string $message, int $statusCode = 429): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Retry-After: 3600'); // Retry dopo 1 ora

        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => 'EMAIL_RATE_LIMIT_EXCEEDED',
            'retry_after' => 3600,
        ]);

        exit();
    }

    /**
     * LOG ATTIVITA' SOSPETTE per monitoring
     * ENTERPRISE GALAXY: Uses Logger::security() for dual-write (DB + file logs)
     */
    private function logSuspiciousActivity(string $ip, string $type, ?int $userId = null): void
    {
        // ENTERPRISE GALAXY: Map event type to PSR-3 level
        $levelMap = [
            'IP_EMAIL_RATE_LIMIT_EXCEEDED' => 'warning',
            'USER_EMAIL_RATE_LIMIT_EXCEEDED' => 'warning',
            'BLACKLISTED_IP_EMAIL_ATTEMPT' => 'error',
            'EMAIL_FLOOD_DETECTED' => 'error',
        ];

        $level = $levelMap[$type] ?? 'warning';

        // ENTERPRISE GALAXY: Dual-write via Logger::security() (DB + file)
        \Need2Talk\Services\Logger::security($level, "EMAIL_RATE_LIMIT: {$type}", [
            'event_type' => $type,
            'ip' => $ip,
            'user_id' => $userId,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
        ]);
    }

    /**
     * GET USER IP con proxy detection
     */
    private function getUserIP(): string
    {
        // CHECK per proxy/load balancer headers
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancer
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Standard
            'HTTP_FORWARDED',            // Standard
            'REMOTE_ADDR',                // Direct connection
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // SE multiple IP (proxy chain), prendi prima
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // VALIDAZIONE IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // FALLBACK: IP diretto o localhost
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * BYPASS rate limit per IP whitelisted
     */
    private function isWhitelistedIP(string $ip): bool
    {
        return in_array($ip, self::getWhitelistedIPs(), true);
    }
}
