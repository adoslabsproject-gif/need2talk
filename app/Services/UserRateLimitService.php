<?php

namespace Need2Talk\Services;

/**
 * User Rate Limit Service - High Performance Rate Limiting for User Actions
 *
 * Sistema di rate limiting per azioni utente (registrazione, login, etc.)
 * Scalabile per migliaia di utenti simultanei con protezione anti-malintenzionati
 * Separato dal rate limiting audio per performance ottimali
 */
class UserRateLimitService
{
    // ⚠️⚠️⚠️ IMPORTANT: MODIFICARE IN PRODUZIONE! ⚠️⚠️⚠️
    // I limiti sono stati aumentati per DEVELOPMENT/TESTING
    // IN PRODUZIONE RIPRISTINARE VALORI ORIGINALI PER SICUREZZA:
    // registration: max_attempts => 3 (non 10!)
    // login: max_attempts => 5 (non 10!)
    // ⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️

    // Configurazione rate limits
    private const LIMITS = [
        'registration' => ['window' => 3600, 'max_attempts' => 10], // 10 per ora per IP (DEVELOPMENT ONLY!)
        'login' => ['window' => 900, 'max_attempts' => 10],         // 10 per 15 min (DEVELOPMENT ONLY!)
        'password_reset' => ['window' => 3600, 'max_attempts' => 5], // 5 per ora
        'email_change' => ['window' => 86400, 'max_attempts' => 3], // 3 per giorno
    ];

    /**
     * Controlla rate limit per IP
     */
    public function checkIPRateLimit(string $ip, string $action, ?int $maxAttempts = null): bool
    {
        $config = self::LIMITS[$action] ?? ['window' => 3600, 'max_attempts' => 10];
        $maxAttempts = $maxAttempts ?? $config['max_attempts'];
        $window = $config['window'];

        return $this->checkRateLimit($ip, $action, $maxAttempts, $window, 'ip');
    }

    /**
     * Controlla rate limit per email
     */
    public function checkEmailRateLimit(string $email, string $action, ?int $maxAttempts = null): bool
    {
        $config = self::LIMITS[$action] ?? ['window' => 86400, 'max_attempts' => 1];
        $maxAttempts = $maxAttempts ?? $config['max_attempts'];
        $window = $config['window'];

        // Hash email per privacy
        $hashedEmail = hash('sha256', strtolower($email));

        return $this->checkRateLimit($hashedEmail, $action, $maxAttempts, $window, 'email');
    }

    /**
     * Controlla rate limit per user ID
     */
    public function checkUserRateLimit(int $userId, string $action, ?int $maxAttempts = null): bool
    {
        $config = self::LIMITS[$action] ?? ['window' => 3600, 'max_attempts' => 10];
        $maxAttempts = $maxAttempts ?? $config['max_attempts'];
        $window = $config['window'];

        return $this->checkRateLimit((string) $userId, $action, $maxAttempts, $window, 'user');
    }

    /**
     * Registra un tentativo per il rate limiting
     */
    public function recordAttempt(string $identifier, string $action, string $type = 'ip'): void
    {
        try {
            $key = "rate_limit:{$type}:{$action}:{$identifier}";
            $identifierHash = hash('sha256', $key);

            $stmt = db_pdo()->prepare(
                'INSERT INTO user_rate_limit_log (identifier_hash, action_type, identifier_type, ip_address, user_agent, user_id, request_fingerprint, session_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );

            // Generate browser fingerprint and get session data
            $requestFingerprint = $this->generateRequestFingerprint();
            $sessionId = session_id() ?: null;
            $userId = $_SESSION['user_id'] ?? null;

            $stmt->execute([
                $identifierHash,
                $action,
                $type,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $userId,
                $requestFingerprint,
                $sessionId,
            ]);

        } catch (\Exception $e) {
            error_log('Failed to record rate limit attempt: ' . $e->getMessage());
        }
    }

    /**
     * Ottiene tempo rimanente per reset del rate limit
     */
    public function getTimeUntilReset(string $identifier, string $action, string $type = 'ip'): int
    {
        try {
            $config = self::LIMITS[$action] ?? ['window' => 3600];
            $window = $config['window'];

            $key = "rate_limit:{$type}:{$action}:{$identifier}";
            $identifierHash = hash('sha256', $key);

            $stmt = db_pdo()->prepare(
                "SELECT MAX(created_at) as last_attempt
                 FROM user_rate_limit_log
                 WHERE identifier_hash = ?
                   AND action_type = ?
                   AND identifier_type = ?
                   AND created_at > NOW() - INTERVAL '1 second' * ?"
            );

            $stmt->execute([$identifierHash, $action, $type, $window]);
            $lastAttempt = $stmt->fetch()['last_attempt'] ?? null;

            if (!$lastAttempt) {
                return 0;
            }

            $resetTime = strtotime($lastAttempt) + $window;
            $remainingTime = $resetTime - time();

            return max(0, $remainingTime);

        } catch (\Exception $e) {
            error_log('Failed to get rate limit reset time: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Rate limiting progressivo - aumenta il delay ad ogni violazione
     */
    public function getProgressiveDelay(string $identifier, string $action, string $type = 'ip'): int
    {
        try {
            $key = "rate_limit:{$type}:{$action}:{$identifier}";
            $identifierHash = hash('sha256', $key);

            // Conta violazioni nelle ultime 24 ore
            $stmt = db_pdo()->prepare(
                "SELECT COUNT(*) as violations
                 FROM user_rate_limit_violations
                 WHERE identifier_hash = ?
                   AND action_type = ?
                   AND created_at > NOW() - INTERVAL '24 hours'"
            );

            $stmt->execute([$identifierHash, $action]);
            $violations = (int) ($stmt->fetch()['violations'] ?? 0);

            // Delay progressivo: 1min, 5min, 15min, 1h, 6h, 24h
            $delays = [60, 300, 900, 3600, 21600, 86400];
            $delayIndex = min($violations, count($delays) - 1);

            return $delays[$delayIndex];

        } catch (\Exception $e) {
            error_log('Failed to calculate progressive delay: ' . $e->getMessage());

            return 60; // Default 1 minuto
        }
    }

    /**
     * Pulisce vecchi record per performance
     */
    public function cleanupOldRecords(): void
    {
        try {
            // Rimuovi record più vecchi di 30 giorni
            $stmt = db_pdo()->prepare(
                "DELETE FROM user_rate_limit_log
                 WHERE created_at < NOW() - INTERVAL '30 days'"
            );
            $stmt->execute();

            $stmt = db_pdo()->prepare(
                "DELETE FROM user_rate_limit_violations
                 WHERE created_at < NOW() - INTERVAL '30 days'"
            );
            $stmt->execute();

        } catch (\Exception $e) {
            error_log('Rate limit cleanup failed: ' . $e->getMessage());
        }
    }

    /**
     * Whitelist per IP fidati (admin, monitoring tools)
     */
    public function isWhitelistedIP(string $ip): bool
    {
        $whitelist = [
            '127.0.0.1',
            '::1',
            // Aggiungi IP admin/monitoring
        ];

        return in_array($ip, $whitelist, true);
    }

    /**
     * Reset manual rate limit per admin
     */
    public function resetRateLimit(string $identifier, string $action, string $type = 'ip'): bool
    {
        try {
            $key = "rate_limit:{$type}:{$action}:{$identifier}";
            $identifierHash = hash('sha256', $key);

            $stmt = db_pdo()->prepare(
                'DELETE FROM user_rate_limit_log
                 WHERE identifier_hash = ? AND action_type = ? AND identifier_type = ?'
            );
            $stmt->execute([$identifierHash, $action, $type]);

            $stmt = db_pdo()->prepare(
                'DELETE FROM user_rate_limit_violations
                 WHERE identifier_hash = ? AND action_type = ?'
            );
            $stmt->execute([$identifierHash, $action]);

            return true;

        } catch (\Exception $e) {
            error_log('Failed to reset rate limit: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Get count for specific key (compatibility method)
     */
    public function getCount(string $key, int $ttl = 3600): int
    {
        try {
            $identifierHash = hash('sha256', $key);

            $stmt = db_pdo()->prepare(
                "SELECT COUNT(*) as count
                 FROM user_rate_limit_log
                 WHERE identifier_hash = ?
                   AND created_at > NOW() - INTERVAL '1 second' * ?"
            );

            $stmt->execute([$identifierHash, $ttl]);

            return (int) ($stmt->fetch()['count'] ?? 0);

        } catch (\Exception $e) {
            error_log('Failed to get count: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Increment count for specific key (Email Rate Limit tracking)
     *
     * ENTERPRISE CONTEXT: Called by EmailRateLimitMiddleware for:
     * - email_rate_ip_hour_{$ip} / email_rate_ip_day_{$ip} → IP-based email rate limiting
     * - email_rate_user_hour_{$userId} / email_rate_user_day_{$userId} → User-based email rate limiting
     */
    public function incrementCount(string $key, int $ttl = 3600): void
    {
        try {
            $identifierHash = hash('sha256', $key);

            // ENTERPRISE FIX: Detect identifier_type from key pattern
            // Keys format: email_rate_{type}_{period}_{identifier}
            $identifierType = 'ip'; // Default to IP
            if (str_contains($key, 'email_rate_user_')) {
                $identifierType = 'user';
            } elseif (str_contains($key, 'email_rate_email_')) {
                $identifierType = 'email';
            }

            $stmt = db_pdo()->prepare(
                "INSERT INTO user_rate_limit_log (identifier_hash, action_type, identifier_type, ip_address, user_agent, created_at)
                 VALUES (?, 'email_rate_limit', ?, ?, ?, NOW())"
            );

            $stmt->execute([
                $identifierHash,
                $identifierType, // ENTERPRISE: 'ip', 'user', or 'email' based on key pattern
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);

            // TTL is handled by the database cleanup - no need to store it separately for compatibility mode

        } catch (\Exception $e) {
            error_log('Failed to increment count: ' . $e->getMessage());
        }
    }

    /**
     * Set with TTL (compatibility method)
     */
    public function setWithTTL(string $key, int $value, int $ttl): void
    {
        // For email rate limiting, we'll use the database approach
        // This is a compatibility method for existing code
        $this->incrementCount($key);
    }

    /**
     * Check if key exists (compatibility method)
     */
    public function exists(string $key): bool
    {
        return $this->getCount($key) > 0;
    }

    /**
     * Sistema di rate limiting generico con sliding window
     */
    private function checkRateLimit(string $identifier, string $action, int $maxAttempts, int $window, string $type): bool
    {
        try {
            // Chiave unica per questo rate limit
            $key = "rate_limit:{$type}:{$action}:{$identifier}";

            // Query atomica per contare tentativi nel window
            $stmt = db_pdo()->prepare(
                "SELECT COUNT(*) as attempts
                 FROM user_rate_limit_log
                 WHERE identifier_hash = ?
                   AND action_type = ?
                   AND identifier_type = ?
                   AND created_at > NOW() - INTERVAL '1 second' * ?"
            );

            $identifierHash = hash('sha256', $key);
            $stmt->execute([$identifierHash, $action, $type, $window]);
            $currentAttempts = (int) ($stmt->fetch()['attempts'] ?? 0);

            // Se superato il limite, blocca
            if ($currentAttempts >= $maxAttempts) {
                $this->logRateLimitViolation($identifier, $action, $type, $currentAttempts);

                return false;
            }

            return true;

        } catch (\Exception $e) {
            Logger::error('Rate limit check failed', [
                'error_type' => 'rate_limit_system_failure',
                'identifier_type' => $type,
                'action' => $action,
                'error_message' => $e->getMessage(),
                'fail_open' => true,
            ]);

            // In caso di errore, permettiamo l'azione (fail-open)
            return true;
        }
    }

    /**
     * Registra violazione del rate limit
     */
    private function logRateLimitViolation(string $identifier, string $action, string $type, int $attempts): void
    {
        try {
            $key = "rate_limit:{$type}:{$action}:{$identifier}";
            $identifierHash = hash('sha256', $key);

            $stmt = db_pdo()->prepare(
                'INSERT INTO user_rate_limit_violations (identifier_hash, action_type, identifier_type, attempts_count, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (identifier_hash, action_type) DO UPDATE SET
                    attempts_count = EXCLUDED.attempts_count,
                    ip_address = EXCLUDED.ip_address,
                    created_at = NOW()'
            );

            $stmt->execute([
                $identifierHash,
                $action,
                $type,
                $attempts,
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

        } catch (\Exception $e) {
            error_log('Failed to log rate limit violation: ' . $e->getMessage());
        }
    }

    /**
     * Generate browser fingerprint for request tracking
     */
    private function generateRequestFingerprint(): string
    {
        $fingerprint = [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        ];

        return hash('sha256', json_encode($fingerprint));
    }
}
