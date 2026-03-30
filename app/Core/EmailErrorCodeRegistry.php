<?php

namespace Need2Talk\Core;

/**
 * ============================================================================
 * EMAIL ERROR CODE REGISTRY - ENTERPRISE STANDARD
 * ============================================================================
 *
 * Registro centralizzato di codici errore standardizzati per:
 * - Email Verification System
 * - Password Reset System
 *
 * Ogni errore ha:
 * - Codice univoco (es: RATE_LIMIT_IP_EXCEEDED)
 * - Descrizione chiara in italiano
 * - Categoria (rate_limit, database, email, token, validation, etc.)
 * - Severità (info, warning, error, critical)
 *
 * DESIGN GOALS:
 * ✅ Standardizzazione - Codici univoci cross-system
 * ✅ Tracciabilità - Ogni errore è identificabile nelle metrics
 * ✅ Debugging rapido - Descrizioni chiare e actionable
 * ✅ Analytics - Categorizzazione per dashboard
 * ✅ Enterprise-grade - Gestione errori professionale
 *
 * @package Need2Talk\Core
 * @version 1.0.0 Enterprise
 * @author need2talk Engineering Team
 */
class EmailErrorCodeRegistry
{
    // ========================================================================
    // CATEGORY: RATE LIMITING ERRORS
    // ========================================================================

    /** Rate limit exceeded per IP address */
    public const RATE_LIMIT_IP_EXCEEDED = 'RATE_LIMIT_IP_EXCEEDED';

    /** Rate limit exceeded per email address */
    public const RATE_LIMIT_EMAIL_EXCEEDED = 'RATE_LIMIT_EMAIL_EXCEEDED';

    /** Global rate limit exceeded (system-wide protection) */
    public const RATE_LIMIT_GLOBAL_EXCEEDED = 'RATE_LIMIT_GLOBAL_EXCEEDED';

    /** Rate limit exceeded per user */
    public const RATE_LIMIT_USER_EXCEEDED = 'RATE_LIMIT_USER_EXCEEDED';

    // ========================================================================
    // CATEGORY: DATABASE ERRORS
    // ========================================================================

    /** Database connection failed */
    public const DATABASE_CONNECTION_FAILED = 'DATABASE_CONNECTION_FAILED';

    /** Database query failed */
    public const DATABASE_QUERY_FAILED = 'DATABASE_QUERY_FAILED';

    /** Database transaction failed */
    public const DATABASE_TRANSACTION_FAILED = 'DATABASE_TRANSACTION_FAILED';

    /** Database timeout */
    public const DATABASE_TIMEOUT = 'DATABASE_TIMEOUT';

    /** Database deadlock detected */
    public const DATABASE_DEADLOCK = 'DATABASE_DEADLOCK';

    /** User not found in database */
    public const DATABASE_USER_NOT_FOUND = 'DATABASE_USER_NOT_FOUND';

    // ========================================================================
    // CATEGORY: EMAIL/SMTP ERRORS
    // ========================================================================

    /** Email queue full (Redis queue capacity reached) */
    public const EMAIL_QUEUE_FULL = 'EMAIL_QUEUE_FULL';

    /** Email queue failed (Redis zadd failed) */
    public const EMAIL_QUEUE_FAILED = 'EMAIL_QUEUE_FAILED';

    /** SMTP connection failed */
    public const EMAIL_SMTP_CONNECTION_FAILED = 'EMAIL_SMTP_CONNECTION_FAILED';

    /** SMTP authentication failed */
    public const EMAIL_SMTP_AUTH_FAILED = 'EMAIL_SMTP_AUTH_FAILED';

    /** Email sending failed (generic SMTP error) */
    public const EMAIL_SEND_FAILED = 'EMAIL_SEND_FAILED';

    /** Email recipient rejected by SMTP server */
    public const EMAIL_RECIPIENT_REJECTED = 'EMAIL_RECIPIENT_REJECTED';

    /** Email timeout (sending took too long) */
    public const EMAIL_TIMEOUT = 'EMAIL_TIMEOUT';

    /** Email template rendering failed */
    public const EMAIL_TEMPLATE_FAILED = 'EMAIL_TEMPLATE_FAILED';

    // ========================================================================
    // CATEGORY: TOKEN ERRORS
    // ========================================================================

    /** Token not found in database or cache */
    public const TOKEN_NOT_FOUND = 'TOKEN_NOT_FOUND';

    /** Token has expired */
    public const TOKEN_EXPIRED = 'TOKEN_EXPIRED';

    /** Token already used (replay attack prevention) */
    public const TOKEN_ALREADY_USED = 'TOKEN_ALREADY_USED';

    /** Token format invalid */
    public const TOKEN_INVALID_FORMAT = 'TOKEN_INVALID_FORMAT';

    /** Token hash mismatch */
    public const TOKEN_HASH_MISMATCH = 'TOKEN_HASH_MISMATCH';

    /** Token generation failed */
    public const TOKEN_GENERATION_FAILED = 'TOKEN_GENERATION_FAILED';

    // ========================================================================
    // CATEGORY: VALIDATION ERRORS
    // ========================================================================

    /** Email address format invalid */
    public const VALIDATION_EMAIL_INVALID = 'VALIDATION_EMAIL_INVALID';

    /** Password too short */
    public const VALIDATION_PASSWORD_TOO_SHORT = 'VALIDATION_PASSWORD_TOO_SHORT';

    /** Password confirmation mismatch */
    public const VALIDATION_PASSWORD_MISMATCH = 'VALIDATION_PASSWORD_MISMATCH';

    /** Password too weak */
    public const VALIDATION_PASSWORD_TOO_WEAK = 'VALIDATION_PASSWORD_TOO_WEAK';

    /** Required field missing */
    public const VALIDATION_FIELD_REQUIRED = 'VALIDATION_FIELD_REQUIRED';

    /** Input validation failed (generic) */
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    // ========================================================================
    // CATEGORY: REDIS ERRORS
    // ========================================================================

    /** Redis connection failed */
    public const REDIS_CONNECTION_FAILED = 'REDIS_CONNECTION_FAILED';

    /** Redis command failed */
    public const REDIS_COMMAND_FAILED = 'REDIS_COMMAND_FAILED';

    /** Redis timeout */
    public const REDIS_TIMEOUT = 'REDIS_TIMEOUT';

    /** Redis key not found */
    public const REDIS_KEY_NOT_FOUND = 'REDIS_KEY_NOT_FOUND';

    // ========================================================================
    // CATEGORY: WORKER/QUEUE ERRORS
    // ========================================================================

    /** Worker not available */
    public const WORKER_NOT_AVAILABLE = 'WORKER_NOT_AVAILABLE';

    /** Worker processing failed */
    public const WORKER_PROCESSING_FAILED = 'WORKER_PROCESSING_FAILED';

    /** Worker timeout */
    public const WORKER_TIMEOUT = 'WORKER_TIMEOUT';

    /** Queue processing timeout */
    public const QUEUE_PROCESSING_TIMEOUT = 'QUEUE_PROCESSING_TIMEOUT';

    // ========================================================================
    // CATEGORY: SECURITY ERRORS
    // ========================================================================

    /** Suspicious activity detected */
    public const SECURITY_SUSPICIOUS_ACTIVITY = 'SECURITY_SUSPICIOUS_ACTIVITY';

    /** Blocked by security rules */
    public const SECURITY_BLOCKED = 'SECURITY_BLOCKED';

    /** CSRF token invalid */
    public const SECURITY_CSRF_INVALID = 'SECURITY_CSRF_INVALID';

    /** IP address blocked */
    public const SECURITY_IP_BLOCKED = 'SECURITY_IP_BLOCKED';

    // ========================================================================
    // CATEGORY: SYSTEM ERRORS
    // ========================================================================

    /** System overloaded */
    public const SYSTEM_OVERLOADED = 'SYSTEM_OVERLOADED';

    /** Timeout (generic) */
    public const SYSTEM_TIMEOUT = 'SYSTEM_TIMEOUT';

    /** Out of memory */
    public const SYSTEM_OUT_OF_MEMORY = 'SYSTEM_OUT_OF_MEMORY';

    /** Critical system failure */
    public const SYSTEM_CRITICAL_FAILURE = 'SYSTEM_CRITICAL_FAILURE';

    /** Unknown error */
    public const SYSTEM_UNKNOWN_ERROR = 'SYSTEM_UNKNOWN_ERROR';

    // ========================================================================
    // ERROR DESCRIPTIONS (Italian)
    // ========================================================================

    private const ERROR_DESCRIPTIONS = [
        // Rate Limiting
        self::RATE_LIMIT_IP_EXCEEDED => 'Troppi tentativi dallo stesso indirizzo IP. Attendi prima di riprovare.',
        self::RATE_LIMIT_EMAIL_EXCEEDED => 'Troppi tentativi per questa email. Riprova più tardi.',
        self::RATE_LIMIT_GLOBAL_EXCEEDED => 'Sistema sovraccarico. Riprova tra qualche minuto.',
        self::RATE_LIMIT_USER_EXCEEDED => 'Troppi tentativi per questo utente. Attendi prima di riprovare.',

        // Database
        self::DATABASE_CONNECTION_FAILED => 'Impossibile connettersi al database. Riprova più tardi.',
        self::DATABASE_QUERY_FAILED => 'Errore durante l\'esecuzione della query. Riprova più tardi.',
        self::DATABASE_TRANSACTION_FAILED => 'Transazione database fallita. Operazione annullata.',
        self::DATABASE_TIMEOUT => 'Timeout database. Il server è sovraccarico, riprova più tardi.',
        self::DATABASE_DEADLOCK => 'Conflitto database rilevato. Riprova l\'operazione.',
        self::DATABASE_USER_NOT_FOUND => 'Utente non trovato nel database.',

        // Email/SMTP
        self::EMAIL_QUEUE_FULL => 'Coda email piena. Il sistema sta elaborando molte email, riprova tra poco.',
        self::EMAIL_QUEUE_FAILED => 'Impossibile accodare l\'email. Riprova più tardi.',
        self::EMAIL_SMTP_CONNECTION_FAILED => 'Impossibile connettersi al server email. Riprova più tardi.',
        self::EMAIL_SMTP_AUTH_FAILED => 'Autenticazione SMTP fallita. Problema di configurazione server.',
        self::EMAIL_SEND_FAILED => 'Impossibile inviare l\'email. Riprova più tardi.',
        self::EMAIL_RECIPIENT_REJECTED => 'Indirizzo email rifiutato dal server. Verifica l\'indirizzo.',
        self::EMAIL_TIMEOUT => 'Timeout invio email. Il server è sovraccarico, riprova più tardi.',
        self::EMAIL_TEMPLATE_FAILED => 'Errore durante la creazione del template email.',

        // Token
        self::TOKEN_NOT_FOUND => 'Token non trovato o non valido. Richiedi un nuovo token.',
        self::TOKEN_EXPIRED => 'Token scaduto. Richiedi un nuovo token per continuare.',
        self::TOKEN_ALREADY_USED => 'Token già utilizzato. Richiedi un nuovo token.',
        self::TOKEN_INVALID_FORMAT => 'Formato token non valido. Il link potrebbe essere corrotto.',
        self::TOKEN_HASH_MISMATCH => 'Token non corrisponde all\'hash memorizzato. Possibile manomissione.',
        self::TOKEN_GENERATION_FAILED => 'Impossibile generare un token sicuro. Riprova.',

        // Validation
        self::VALIDATION_EMAIL_INVALID => 'Formato email non valido. Inserisci un indirizzo email corretto.',
        self::VALIDATION_PASSWORD_TOO_SHORT => 'Password troppo corta. Usa almeno 8 caratteri.',
        self::VALIDATION_PASSWORD_MISMATCH => 'Le password non coincidono. Riprova.',
        self::VALIDATION_PASSWORD_TOO_WEAK => 'Password troppo debole. Usa lettere maiuscole, numeri e simboli.',
        self::VALIDATION_FIELD_REQUIRED => 'Campo obbligatorio mancante. Compila tutti i campi richiesti.',
        self::VALIDATION_FAILED => 'Validazione fallita. Controlla i dati inseriti e riprova.',

        // Redis
        self::REDIS_CONNECTION_FAILED => 'Impossibile connettersi a Redis. Riprova più tardi.',
        self::REDIS_COMMAND_FAILED => 'Comando Redis fallito. Riprova più tardi.',
        self::REDIS_TIMEOUT => 'Timeout Redis. Il sistema di cache è sovraccarico.',
        self::REDIS_KEY_NOT_FOUND => 'Chiave Redis non trovata. I dati potrebbero essere scaduti.',

        // Worker/Queue
        self::WORKER_NOT_AVAILABLE => 'Nessun worker disponibile. Il sistema sta elaborando, riprova tra poco.',
        self::WORKER_PROCESSING_FAILED => 'Elaborazione worker fallita. Riprova più tardi.',
        self::WORKER_TIMEOUT => 'Timeout worker. Elaborazione troppo lenta, riprova.',
        self::QUEUE_PROCESSING_TIMEOUT => 'Timeout elaborazione coda. Sistema sovraccarico.',

        // Security
        self::SECURITY_SUSPICIOUS_ACTIVITY => 'Attività sospetta rilevata. Accesso bloccato per sicurezza.',
        self::SECURITY_BLOCKED => 'Accesso bloccato dalle regole di sicurezza. Contatta il supporto.',
        self::SECURITY_CSRF_INVALID => 'Token CSRF non valido. Ricarica la pagina e riprova.',
        self::SECURITY_IP_BLOCKED => 'Il tuo IP è stato bloccato. Contatta il supporto.',

        // System
        self::SYSTEM_OVERLOADED => 'Sistema sovraccarico. Riprova tra qualche minuto.',
        self::SYSTEM_TIMEOUT => 'Timeout sistema. Operazione troppo lenta, riprova.',
        self::SYSTEM_OUT_OF_MEMORY => 'Memoria esaurita. Il server è sovraccarico.',
        self::SYSTEM_CRITICAL_FAILURE => 'Errore critico di sistema. Contatta il supporto tecnico.',
        self::SYSTEM_UNKNOWN_ERROR => 'Errore sconosciuto. Contatta il supporto se il problema persiste.',
    ];

    // ========================================================================
    // ERROR CATEGORIES
    // ========================================================================

    private const ERROR_CATEGORIES = [
        'rate_limit' => [
            self::RATE_LIMIT_IP_EXCEEDED,
            self::RATE_LIMIT_EMAIL_EXCEEDED,
            self::RATE_LIMIT_GLOBAL_EXCEEDED,
            self::RATE_LIMIT_USER_EXCEEDED,
        ],
        'database' => [
            self::DATABASE_CONNECTION_FAILED,
            self::DATABASE_QUERY_FAILED,
            self::DATABASE_TRANSACTION_FAILED,
            self::DATABASE_TIMEOUT,
            self::DATABASE_DEADLOCK,
            self::DATABASE_USER_NOT_FOUND,
        ],
        'email' => [
            self::EMAIL_QUEUE_FULL,
            self::EMAIL_QUEUE_FAILED,
            self::EMAIL_SMTP_CONNECTION_FAILED,
            self::EMAIL_SMTP_AUTH_FAILED,
            self::EMAIL_SEND_FAILED,
            self::EMAIL_RECIPIENT_REJECTED,
            self::EMAIL_TIMEOUT,
            self::EMAIL_TEMPLATE_FAILED,
        ],
        'token' => [
            self::TOKEN_NOT_FOUND,
            self::TOKEN_EXPIRED,
            self::TOKEN_ALREADY_USED,
            self::TOKEN_INVALID_FORMAT,
            self::TOKEN_HASH_MISMATCH,
            self::TOKEN_GENERATION_FAILED,
        ],
        'validation' => [
            self::VALIDATION_EMAIL_INVALID,
            self::VALIDATION_PASSWORD_TOO_SHORT,
            self::VALIDATION_PASSWORD_MISMATCH,
            self::VALIDATION_PASSWORD_TOO_WEAK,
            self::VALIDATION_FIELD_REQUIRED,
            self::VALIDATION_FAILED,
        ],
        'redis' => [
            self::REDIS_CONNECTION_FAILED,
            self::REDIS_COMMAND_FAILED,
            self::REDIS_TIMEOUT,
            self::REDIS_KEY_NOT_FOUND,
        ],
        'worker' => [
            self::WORKER_NOT_AVAILABLE,
            self::WORKER_PROCESSING_FAILED,
            self::WORKER_TIMEOUT,
            self::QUEUE_PROCESSING_TIMEOUT,
        ],
        'security' => [
            self::SECURITY_SUSPICIOUS_ACTIVITY,
            self::SECURITY_BLOCKED,
            self::SECURITY_CSRF_INVALID,
            self::SECURITY_IP_BLOCKED,
        ],
        'system' => [
            self::SYSTEM_OVERLOADED,
            self::SYSTEM_TIMEOUT,
            self::SYSTEM_OUT_OF_MEMORY,
            self::SYSTEM_CRITICAL_FAILURE,
            self::SYSTEM_UNKNOWN_ERROR,
        ],
    ];

    // ========================================================================
    // ERROR SEVERITY LEVELS
    // ========================================================================

    private const ERROR_SEVERITY = [
        // Info - non-critical, informational
        self::VALIDATION_FIELD_REQUIRED => 'info',
        self::VALIDATION_EMAIL_INVALID => 'info',
        self::VALIDATION_PASSWORD_TOO_SHORT => 'info',
        self::VALIDATION_PASSWORD_MISMATCH => 'info',

        // Warning - may cause issues, but not critical
        self::RATE_LIMIT_IP_EXCEEDED => 'warning',
        self::RATE_LIMIT_EMAIL_EXCEEDED => 'warning',
        self::RATE_LIMIT_USER_EXCEEDED => 'warning',
        self::TOKEN_EXPIRED => 'warning',
        self::TOKEN_ALREADY_USED => 'warning',
        self::REDIS_KEY_NOT_FOUND => 'warning',

        // Error - functionality impaired
        self::DATABASE_QUERY_FAILED => 'error',
        self::DATABASE_TIMEOUT => 'error',
        self::EMAIL_SEND_FAILED => 'error',
        self::EMAIL_SMTP_CONNECTION_FAILED => 'error',
        self::EMAIL_QUEUE_FAILED => 'error',
        self::WORKER_PROCESSING_FAILED => 'error',
        self::TOKEN_NOT_FOUND => 'error',
        self::VALIDATION_FAILED => 'error',

        // Critical - system failure
        self::DATABASE_CONNECTION_FAILED => 'critical',
        self::DATABASE_TRANSACTION_FAILED => 'critical',
        self::DATABASE_DEADLOCK => 'critical',
        self::REDIS_CONNECTION_FAILED => 'critical',
        self::SYSTEM_CRITICAL_FAILURE => 'critical',
        self::SYSTEM_OUT_OF_MEMORY => 'critical',
        self::SECURITY_BLOCKED => 'critical',
    ];

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    /**
     * Get error description by code
     *
     * @param string $code Error code constant
     * @return string Error description in Italian
     */
    public static function getDescription(string $code): string
    {
        return self::ERROR_DESCRIPTIONS[$code] ?? self::ERROR_DESCRIPTIONS[self::SYSTEM_UNKNOWN_ERROR];
    }

    /**
     * Get error category by code
     *
     * @param string $code Error code constant
     * @return string Category name (rate_limit, database, email, etc.)
     */
    public static function getCategory(string $code): string
    {
        foreach (self::ERROR_CATEGORIES as $category => $codes) {
            if (in_array($code, $codes, true)) {
                return $category;
            }
        }

        return 'system';
    }

    /**
     * Get error severity by code
     *
     * @param string $code Error code constant
     * @return string Severity level (info, warning, error, critical)
     */
    public static function getSeverity(string $code): string
    {
        return self::ERROR_SEVERITY[$code] ?? 'error';
    }

    /**
     * Create standardized error array
     *
     * ENTERPRISE: Returns consistent error structure for metrics and responses
     *
     * @param string $code Error code constant
     * @param string|null $additionalInfo Additional context (optional)
     * @return array Error data with code, description, category, severity
     */
    public static function createError(string $code, ?string $additionalInfo = null): array
    {
        $error = [
            'error_code' => $code,
            'error_message' => self::getDescription($code),
            'error_category' => self::getCategory($code),
            'error_severity' => self::getSeverity($code),
            'timestamp' => time(),
        ];

        if ($additionalInfo !== null) {
            $error['additional_info'] = substr($additionalInfo, 0, 500);
        }

        return $error;
    }

    /**
     * Check if error code is valid
     *
     * @param string $code Error code to validate
     * @return bool True if code exists in registry
     */
    public static function isValidCode(string $code): bool
    {
        return array_key_exists($code, self::ERROR_DESCRIPTIONS);
    }

    /**
     * Get all error codes by category
     *
     * @param string $category Category name
     * @return array List of error codes in category
     */
    public static function getCodesByCategory(string $category): array
    {
        return self::ERROR_CATEGORIES[$category] ?? [];
    }

    /**
     * Get all available categories
     *
     * @return array List of category names
     */
    public static function getCategories(): array
    {
        return array_keys(self::ERROR_CATEGORIES);
    }

    /**
     * Extract error code from exception or error message
     *
     * ENTERPRISE: Smart error detection from exception messages
     *
     * @param \Exception|string $error Exception or error message
     * @return string Detected error code or SYSTEM_UNKNOWN_ERROR
     */
    public static function detectErrorCode($error): string
    {
        $message = ($error instanceof \Exception) ? $error->getMessage() : (string) $error;
        $message = strtolower($message);

        // Rate limiting
        if (str_contains($message, 'rate limit') || str_contains($message, 'too many')) {
            if (str_contains($message, 'ip')) {
                return self::RATE_LIMIT_IP_EXCEEDED;
            }
            if (str_contains($message, 'email')) {
                return self::RATE_LIMIT_EMAIL_EXCEEDED;
            }

            return self::RATE_LIMIT_GLOBAL_EXCEEDED;
        }

        // Database (ENTERPRISE: PostgreSQL + legacy MySQL error detection)
        if (str_contains($message, 'database') || str_contains($message, 'postgres') || str_contains($message, 'pgsql') || str_contains($message, 'mysql') || str_contains($message, 'pdo')) {
            if (str_contains($message, 'connection') || str_contains($message, 'connect')) {
                return self::DATABASE_CONNECTION_FAILED;
            }
            if (str_contains($message, 'timeout')) {
                return self::DATABASE_TIMEOUT;
            }
            if (str_contains($message, 'deadlock')) {
                return self::DATABASE_DEADLOCK;
            }
            if (str_contains($message, 'transaction')) {
                return self::DATABASE_TRANSACTION_FAILED;
            }

            return self::DATABASE_QUERY_FAILED;
        }

        // Email/SMTP
        if (str_contains($message, 'smtp') || str_contains($message, 'mail')) {
            if (str_contains($message, 'auth')) {
                return self::EMAIL_SMTP_AUTH_FAILED;
            }
            if (str_contains($message, 'connection') || str_contains($message, 'connect')) {
                return self::EMAIL_SMTP_CONNECTION_FAILED;
            }
            if (str_contains($message, 'timeout')) {
                return self::EMAIL_TIMEOUT;
            }

            return self::EMAIL_SEND_FAILED;
        }

        // Token
        if (str_contains($message, 'token')) {
            if (str_contains($message, 'expired') || str_contains($message, 'scaduto')) {
                return self::TOKEN_EXPIRED;
            }
            if (str_contains($message, 'used') || str_contains($message, 'utilizzato')) {
                return self::TOKEN_ALREADY_USED;
            }
            if (str_contains($message, 'invalid') || str_contains($message, 'non valido')) {
                return self::TOKEN_INVALID_FORMAT;
            }
            if (str_contains($message, 'not found') || str_contains($message, 'non trovato')) {
                return self::TOKEN_NOT_FOUND;
            }

            return self::TOKEN_INVALID_FORMAT;
        }

        // Redis
        if (str_contains($message, 'redis')) {
            if (str_contains($message, 'connection') || str_contains($message, 'connect')) {
                return self::REDIS_CONNECTION_FAILED;
            }
            if (str_contains($message, 'timeout')) {
                return self::REDIS_TIMEOUT;
            }

            return self::REDIS_COMMAND_FAILED;
        }

        // Worker
        if (str_contains($message, 'worker') || str_contains($message, 'queue')) {
            if (str_contains($message, 'timeout')) {
                return self::WORKER_TIMEOUT;
            }

            return self::WORKER_PROCESSING_FAILED;
        }

        // Validation
        if (str_contains($message, 'validation') || str_contains($message, 'invalid')) {
            if (str_contains($message, 'email')) {
                return self::VALIDATION_EMAIL_INVALID;
            }
            if (str_contains($message, 'password')) {
                if (str_contains($message, 'short')) {
                    return self::VALIDATION_PASSWORD_TOO_SHORT;
                }
                if (str_contains($message, 'match')) {
                    return self::VALIDATION_PASSWORD_MISMATCH;
                }

                return self::VALIDATION_PASSWORD_TOO_WEAK;
            }

            return self::VALIDATION_FAILED;
        }

        // Timeout (generic)
        if (str_contains($message, 'timeout')) {
            return self::SYSTEM_TIMEOUT;
        }

        // Memory
        if (str_contains($message, 'memory')) {
            return self::SYSTEM_OUT_OF_MEMORY;
        }

        // Default: unknown error
        return self::SYSTEM_UNKNOWN_ERROR;
    }
}
