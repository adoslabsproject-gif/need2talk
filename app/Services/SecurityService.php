<?php

namespace Need2Talk\Services;

/**
 * Security Service - Anti-Malicious Protection
 *
 * Sistema di sicurezza avanzato per proteggere da attacchi automatizzati,
 * bot, spam e altre attività malevole
 */
class SecurityService
{
    /**
     * Calcola un punteggio di probabilità che l'utente sia un bot
     * Range: 0.0 (umano) - 1.0 (sicuramente bot)
     */
    public function calculateBotScore(string $userAgent, array $formData): float
    {
        $score = 0.0;

        // 1. User Agent Analysis
        $score += $this->analyzeUserAgent($userAgent);

        // 2. Form Completion Pattern Analysis
        $score += $this->analyzeFormPatterns($formData);

        // 3. Behavioral Analysis
        $score += $this->analyzeBehavioralPatterns();

        return min($score, 1.0);
    }

    /**
     * Analizza la forza di una password e fornisce feedback
     */
    public function analyzePasswordStrength(string $password): array
    {
        $score = 0;
        $feedback = [];

        // Lunghezza (ENTERPRISE: Allineato con frontend validation.js)
        $length = strlen($password);

        if ($length >= 8) {
            $score += 20;
        } // Allineato con frontend: 20 punti per 8+ caratteri
        else {
            $feedback[] = 'Usa almeno 8 caratteri';
        }

        // Varietà caratteri (ENTERPRISE: Allineato con frontend validation.js)
        if (preg_match('/[a-z]/', $password)) {
            $score += 20;
        } // Allineato: 20 punti
        else {
            $feedback[] = 'Aggiungi lettere minuscole';
        }

        if (preg_match('/[A-Z]/', $password)) {
            $score += 20;
        } // Allineato: 20 punti
        else {
            $feedback[] = 'Aggiungi lettere maiuscole';
        }

        if (preg_match('/[0-9]/', $password)) {
            $score += 20;
        } // Allineato: 20 punti
        else {
            $feedback[] = 'Aggiungi numeri';
        }

        // ENTERPRISE: Pattern caratteri speciali allineato con frontend
        if (preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
            $score += 20;
        } // Allineato: 20 punti
        else {
            $feedback[] = 'Aggiungi caratteri speciali';
        }

        // Pattern comuni (riducono score)
        if (preg_match('/(.)\1{2,}/', $password)) {
            $score -= 10;
        } // caratteri ripetuti

        if (preg_match('/123|abc|password|qwerty/i', $password)) {
            $score -= 20;
        } // pattern comuni

        return [
            'score' => max(0, min(100, $score)),
            'feedback' => implode('. ', $feedback),
            'strength' => $this->getPasswordStrengthLabel($score),
        ];
    }

    /**
     * Registra attività sospette per monitoring
     * ENTERPRISE GALAXY: Uses Logger::security() for dual-write (DB + file logs)
     */
    public function logSuspiciousActivity(string $ip, string $type, string $details): void
    {
        // ENTERPRISE GALAXY: Map event type to PSR-3 level
        $levelMap = [
            'SQL_INJECTION_ATTEMPT' => 'critical',
            'XSS_ATTEMPT' => 'critical',
            'CODE_INJECTION_ATTEMPT' => 'critical',
            'PATH_TRAVERSAL_ATTEMPT' => 'critical',
            'COMMAND_INJECTION' => 'critical',
            'AUTHENTICATION_BYPASS_ATTEMPT' => 'critical',
            'BLACKLISTED_IP_ACCESS' => 'critical',
            'HONEYPOT_TRIGGERED' => 'error',
            'HIGH_BOT_SCORE' => 'error',
            'BRUTE_FORCE_ATTEMPT' => 'error',
            'SUSPICIOUS_URI' => 'error',
            'SUSPICIOUS_USER_AGENT' => 'error',
            'SESSION_HIJACK_ATTEMPT' => 'error',
            'CSRF_TOKEN_MISMATCH' => 'error',
            'RATE_LIMIT_EXCEEDED' => 'warning',
            'MULTIPLE_FAILED_LOGINS' => 'warning',
            'TOKEN_NOT_FOUND' => 'warning',
            'EXPIRED_TOKEN' => 'warning',
        ];

        $level = $levelMap[$type] ?? 'warning';

        // ENTERPRISE GALAXY: Dual-write via Logger::security() (DB + file)
        Logger::security($level, "SECURITY: {$type}", [
            'ip' => $ip,
            'event_type' => $type,
            'details' => $details,
            'user_id' => $_SESSION['user_id'] ?? null,
        ]);
    }

    /**
     * Analizza User Agent per pattern sospetti
     */
    private function analyzeUserAgent(string $userAgent): float
    {
        $score = 0.0;

        // Bot signatures comuni
        $botSignatures = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python',
            'httpie', 'postman', 'insomnia', 'requests', 'scrapy',
        ];

        foreach ($botSignatures as $signature) {
            if (stripos($userAgent, $signature) !== false) {
                $score += 0.4;
            }
        }

        // User agent troppo generico o assente
        if (empty($userAgent) || strlen($userAgent) < 20) {
            $score += 0.3;
        }

        // Pattern sospetti
        if (!preg_match('/Mozilla|Chrome|Firefox|Safari|Edge/i', $userAgent)) {
            $score += 0.3;
        }

        return min($score, 1.0);
    }

    /**
     * Analizza pattern di compilazione form
     */
    private function analyzeFormPatterns(array $formData): float
    {
        $score = 0.0;

        // Campi compilati con pattern troppo perfetti
        if (isset($formData['nickname']) && preg_match('/^[a-z]+[0-9]+$/', $formData['nickname'])) {
            $score += 0.2; // pattern tipico bot: username123
        }

        // Email con pattern sospetti
        if (isset($formData['email'])) {
            $email = $formData['email'];
            $localPart = explode('@', $email)[0] ?? '';

            // Email troppo casuali o con pattern bot
            if (preg_match('/^[a-z]+[0-9]{4,}@/', $email)
                || strlen($localPart) > 15 && ctype_alnum($localPart)) {
                $score += 0.3;
            }
        }

        return $score;
    }

    /**
     * Analizza pattern comportamentali
     */
    private function analyzeBehavioralPatterns(): float
    {
        $score = 0.0;

        // Tempo di compilazione form troppo veloce
        $formStartTime = $_SESSION['form_start_time'] ?? time();
        $completionTime = time() - $formStartTime;

        if ($completionTime < 15) {
            $score += 0.4; // Form compilato troppo velocemente
        }

        // Mancanza di movimento mouse/interazione (da JavaScript)
        if (empty($_POST['mouse_movements']) || (int) ($_POST['mouse_movements'] ?? 0) < 10) {
            $score += 0.2;
        }

        return $score;
    }

    /**
     * Converte punteggio password in etichetta
     */
    private function getPasswordStrengthLabel(int $score): string
    {
        if ($score >= 80) {
            return 'Forte';
        }

        if ($score >= 60) {
            return 'Media';
        }

        if ($score >= 40) {
            return 'Debole';
        }

        return 'Molto debole';
    }

    /**
     * Controlla se un IP deve essere bloccato automaticamente
     */
    private function checkForAutoBlock(string $ip): void
    {
        // Conta eventi sospetti dalle ultime 24 ore
        $stmt = db_pdo()->prepare(
            'SELECT COUNT(*) as event_count
             FROM security_events
             WHERE ip_address = ? AND created_at > NOW() - INTERVAL \'24 hours\''
        );
        $stmt->execute([$ip]);
        $eventCount = $stmt->fetch()['event_count'] ?? 0;

        // Se più di 10 eventi sospetti in 24h, blocca temporaneamente
        if ($eventCount > 10) {
            $this->addIPToTempBlock($ip, 'AUTO_BLOCK_SUSPICIOUS_ACTIVITY');
        }
    }

    /**
     * Aggiunge IP a blocco temporaneo
     * ENTERPRISE: Ottimizzato con indice unique_ip e incremento ban_count
     */
    private function addIPToTempBlock(string $ip, string $reason): void
    {
        try {
            // ENTERPRISE: Query ottimizzata sfruttando UNIQUE KEY unique_ip (ip_address)
            // Incrementa ban_count ad ogni blocco ripetuto per tracking
            $stmt = db_pdo()->prepare(
                'INSERT INTO ip_bans (ip_address, reason, expires_at, is_permanent, ban_count, created_at)
                 VALUES (?, ?, NOW() + INTERVAL \'24 hours\', 0, 1, NOW())
                 ON CONFLICT (ip_address) DO UPDATE SET
                    expires_at = NOW() + INTERVAL \'24 hours\',
                    reason = EXCLUDED.reason,
                    is_permanent = FALSE,
                    ban_count = ip_bans.ban_count + 1,
                    updated_at = CURRENT_TIMESTAMP'
            );

            $stmt->execute([$ip, $reason]);

            Logger::security('critical', 'SECURITY: IP temporarily blocked', [
                'ip' => $ip,
                'reason' => $reason,
                'blocked_duration' => '24 hours',
                'action' => 'ip_temp_block',
            ]);

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: Failed to block IP', [
                'ip' => $ip,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'operation' => 'addIPToTempBlock',
            ]);
        }
    }
}
