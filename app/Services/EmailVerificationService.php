<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EmailErrorCodeRegistry;
use Need2Talk\Models\User;
use Need2Talk\Traits\EnterpriseRedisSafety;

/**
 * Email Verification Service - Enterprise Grade Security & Scalability
 *
 * Sistema di verifica email progettato per:
 * - Migliaia di utenti simultanei
 * - Protezione anti-spam e anti-abuse
 * - Scalabilità orizzontale con Redis
 * - Token crittograficamente sicuri
 * - Rate limiting avanzato
 * - Monitoring e analytics
 */
class EmailVerificationService
{
    use EnterpriseRedisSafety;

    // Security Constants - ENTERPRISE: Token expires in 24 hours (industry standard)
    private const TOKEN_LENGTH = 128;
    private const TOKEN_EXPIRY_HOURS = 24;
    private const MAX_VERIFICATION_ATTEMPTS = 5;
    private const RATE_LIMIT_PER_IP_HOUR = 5;
    private const RATE_LIMIT_PER_EMAIL_DAY = 5;  // Allows legitimate resends when token expires or email doesn't arrive

    // Redis Keys for Scalability
    private const REDIS_TOKEN_PREFIX = 'email_verify:';
    private const REDIS_RATE_LIMIT_IP = 'rate:ip:email_verify:';
    private const REDIS_RATE_LIMIT_EMAIL = 'rate:email:email_verify:';
    private const REDIS_FAILED_ATTEMPTS = 'fail:email_verify:';

    private User $userModel;

    private SecurityService $security;

    private EmailService $emailService;

    private EmailMetricsService $metricsService;

    private EnterpriseEmailMetricsUnified $unifiedMetrics;

    private UserRateLimitService $rateLimitService;

    private $logger;

    public function __construct()
    {
        $this->userModel = new User();
        $this->security = new SecurityService();
        $this->emailService = new EmailService();
        $this->metricsService = new EmailMetricsService();
        $this->unifiedMetrics = new EnterpriseEmailMetricsUnified();
        $this->rateLimitService = new UserRateLimitService();
        $this->logger = null; // Use error_log for development
    }

    /**
     * Genera e invia token di verifica email con sicurezza enterprise
     */
    public function sendVerificationEmail(int $userId, string $email, string $nickname): array
    {
        try {
            // SECURITY: Rate limiting per IP e email
            if (!$this->checkRateLimit($email)) {
                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::RATE_LIMIT_GLOBAL_EXCEEDED;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                ];
            }

            // SECURITY: Verifica che l'utente esista e non sia già verificato
            $user = $this->userModel->findById($userId);

            if (!$user) {
                error_log("Verification email attempt for non-existent user - User ID: $userId, Email hash: " . hash('sha256', $email) . ', IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::DATABASE_USER_NOT_FOUND;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                ];
            }

            if ($user['email_verified']) {
                // ENTERPRISE: Use custom error message since there's no exact match in registry
                return [
                    'success' => false,
                    'error' => 'Email già verificata',
                    'error_code' => 'ALREADY_VERIFIED', // Custom code for this specific case
                ];
            }

            // SECURITY: Genera token crittograficamente sicuro
            $token = $this->generateSecureToken($userId);

            // PERFORMANCE: Salva token in Redis per scalabilità
            $this->storeTokenInRedis($token, $userId, $email);

            // BACKUP: Salva anche in database per resilienza
            $this->storeTokenInDatabase($userId, $token);

            // ENTERPRISE: Use AsyncEmailQueue worker system (same as resend for consistency)
            $asyncEmailQueue = new AsyncEmailQueue();
            $verificationUrl = $this->buildVerificationUrl($token);

            // ENTERPRISE TIPS: Get user UUID and use UUID-first queueing to avoid legacy fallback
            $user = $this->userModel->findById($userId);
            $userUuid = $user['uuid'] ?? null;

            if ($userUuid) {
                $emailQueued = $asyncEmailQueue->queueVerificationEmailByUuid($userUuid, $email, $nickname, 'initial');
            } else {
                // Fallback to user_id method if UUID not available
                $emailQueued = $asyncEmailQueue->queueVerificationEmail($userId, $email, $nickname);
            }

            if ($emailQueued) {
                // ENTERPRISE TIPS: Increment rate limit ONLY after successful email queue
                // ENTERPRISE TIPS: Rate limit now properly logged before check, no need to increment after success

                // ENTERPRISE: Update resend rate limiting for initial registration too
                $this->updateResendRateLimit($email);

                // ANALYTICS: Log successful email queue
                Logger::email('info', 'EMAIL: Verification email queued successfully', [
                    'user_id' => $userId,
                    'email_hash' => hash('sha256', $email),
                    'token_hash' => hash('sha256', $token),
                    'context' => 'initial_registration',
                    'queue_system' => 'AsyncEmailQueue',
                ]);

                // ENTERPRISE METRICS: Record email sent event (registration) with REAL email size
                $asyncEmailQueue = new AsyncEmailQueue();
                $emailSize = $asyncEmailQueue->calculateRealEmailSize($email);
                $this->metricsService->recordEmailSent('verification', [
                    'recipient' => $email,
                    'context' => 'initial_registration',
                    'user_id' => $userId,
                    'queue_time' => microtime(true),
                    'priority' => 'normal',
                    'size' => $emailSize,
                ]);

                // ENTERPRISE TIPS: Let AsyncEmailQueue worker handle ALL metrics recording
                // This ensures single-point metrics control and prevents duplicates
                // Only worker creates and manages email_verification_metrics records

                return [
                    'success' => true,
                    'message' => 'Email di verifica inviata con successo',
                    'token_expires' => self::TOKEN_EXPIRY_HOURS . ' ore',
                ];
            }

            throw new \Exception('Errore nel queueing dell\'email');
        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Verification queue failed', [
                'user_id' => $userId,
                'email_hash' => hash('sha256', $email),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'context' => 'initial_registration',
            ]);

            // ENTERPRISE METRICS: Record email failure (registration)
            $this->metricsService->recordEmailFailure('verification', [
                'recipient' => $email,
                'context' => 'initial_registration',
                'user_id' => $userId,
                'processing_time' => 0,
            ], 'Email queue failed: ' . $e->getMessage());

            // ENTERPRISE UNIFIED METRICS: Record failure in ALL 3 tables
            $errorCode = EmailErrorCodeRegistry::detectErrorCode($e);
            $this->unifiedMetrics->recordEmailVerificationEvent($userId, 'queue_failed', [
                'email_type' => 'verification',
                'context' => 'initial_registration',
                'queue_start_time' => microtime(true),
                'error_code' => $errorCode,
                'error_message' => EmailErrorCodeRegistry::getDescription($errorCode),
            ]);

            // ENTERPRISE: Use standardized error code
            $errorCode = EmailErrorCodeRegistry::EMAIL_QUEUE_FAILED;

            return [
                'success' => false,
                'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                'error_code' => $errorCode,
            ];
        }
    }

    /**
     * 🚀 ENTERPRISE UUID-FIRST: Send verification email using UUID (eliminates race conditions)
     * Designed for hundreds of thousands of concurrent users with zero race conditions
     *
     * @param  string  $userUuid  User UUID (race-condition-free identifier)
     * @param  string  $email  User email address
     * @param  string  $nickname  User nickname for personalization
     * @param  array  $context  Context information (e.g., worker context to bypass rate limiting)
     * @return array Success/failure response with enterprise monitoring
     */
    public function sendVerificationEmailByUuid(string $userUuid, string $email, string $nickname, array $context = []): array
    {
        try {
            Logger::email('info', 'EMAIL: ENTERPRISE UUID-FIRST - Starting email verification via UUID', [
                'user_uuid' => $userUuid,
                'email_hash' => hash('sha256', $email),
                'approach' => 'uuid_first_enterprise',
                'eliminates_race_condition' => true,
                'supports_concurrent_users' => 'hundreds_of_thousands',
            ]);

            // 🚀 ENTERPRISE SECURITY: Rate limiting with worker bypass
            // Workers processing Transactional Outbox events bypass rate limiting
            // because emails are already pre-authorized during atomic registration
            $isWorkerContext = ($context['worker_bypass'] ?? false) === true;

            if (!$isWorkerContext && !$this->checkRateLimit($email)) {
                Logger::security('warning', 'SECURITY: ENTERPRISE UUID-FIRST - Rate limit exceeded', [
                    'user_uuid' => $userUuid,
                    'email_hash' => hash('sha256', $email),
                    'rate_limit_trigger' => true,
                    'context' => 'user_request',
                ]);

                return [
                    'success' => false,
                    'error' => 'Troppi tentativi. Riprova più tardi.',
                    'error_code' => 'RATE_LIMIT_EXCEEDED',
                ];
            }

            if ($isWorkerContext) {
                Logger::email('info', 'EMAIL: ENTERPRISE UUID-FIRST - Rate limit bypassed for worker', [
                    'user_uuid' => $userUuid,
                    'email_hash' => hash('sha256', $email),
                    'context' => 'transactional_outbox_worker',
                    'security_note' => 'email_pre_authorized_atomically',
                ]);
            }

            // ⭐ ENTERPRISE TIPS: Generate idempotency key for deduplication
            $idempotencyKey = $this->generateIdempotencyKey($userUuid, $email, $context);

            // Check if email already sent with this idempotency key
            if ($this->isEmailAlreadySent($idempotencyKey)) {
                Logger::email('info', 'EMAIL: Duplicate email prevented by idempotency', [
                    'user_uuid' => $userUuid,
                    'email_hash' => hash('sha256', $email),
                    'idempotency_key' => $idempotencyKey,
                    'prevented_duplicate' => true,
                    'enterprise_deduplication' => true,
                ]);

                return [
                    'success' => true,
                    'message' => 'Email already sent (duplicate prevented)',
                    'duplicate_prevented' => true,
                    'idempotency_key' => $idempotencyKey,
                ];
            }

            // 🚀 ENTERPRISE UUID-FIRST: Get user_id via UUID with Redis L1 caching
            $userId = $this->getUserIdFromUuid($userUuid);

            if (!$userId) {
                Logger::email('error', 'EMAIL: ENTERPRISE UUID-FIRST - User not found by UUID', [
                    'user_uuid' => $userUuid,
                    'email_hash' => hash('sha256', $email),
                    'idempotency_key' => $idempotencyKey,
                    'possible_causes' => ['user_deleted', 'uuid_mismatch', 'database_issue'],
                ]);

                return [
                    'success' => false,
                    'error' => 'Utente non trovato',
                    'error_code' => 'USER_NOT_FOUND_UUID',
                ];
            }

            // SECURITY: Verifica che l'utente esista e non sia già verificato
            $user = $this->userModel->findById($userId);

            if (!$user) {
                Logger::email('error', 'EMAIL: ENTERPRISE UUID-FIRST - User ID resolved but user not found', [
                    'user_uuid' => $userUuid,
                    'resolved_user_id' => $userId,
                    'data_inconsistency' => true,
                ]);

                return [
                    'success' => false,
                    'error' => 'Utente non trovato',
                    'error_code' => 'USER_DATA_INCONSISTENT',
                ];
            }

            if ($user['email_verified']) {
                Logger::email('info', 'EMAIL: ENTERPRISE UUID-FIRST - Email already verified', [
                    'user_uuid' => $userUuid,
                    'user_id' => $userId,
                    'email_verified_at' => $user['email_verified_at'] ?? 'unknown',
                ]);

                // ENTERPRISE: Use custom error message since there's no exact match in registry
                return [
                    'success' => false,
                    'error' => 'Email già verificata',
                    'error_code' => 'ALREADY_VERIFIED', // Custom code for this specific case
                ];
            }

            // SECURITY: Genera token crittograficamente sicuro
            $token = $this->generateSecureToken($userId);

            Logger::security('info', 'SECURITY: ENTERPRISE UUID-FIRST - Cryptographic token generated', [
                'user_uuid' => $userUuid,
                'user_id' => $userId,
                'token_length' => strlen($token),
                'token_algorithm' => 'random_bytes_32 + sha256_hash + hex_encoding',
                'cryptographic_strength' => 'enterprise_grade',
                'entropy_bits' => 256,
            ]);

            // 🚀 ENTERPRISE UUID-FIRST: Store token with both user_id AND user_uuid
            $this->storeTokenInRedisWithUuid($token, $userId, $userUuid, $email);
            $this->storeTokenInDatabaseWithUuid($userId, $userUuid, $token);

            // ENTERPRISE: Worker context vs User context routing
            $verificationUrl = $this->buildVerificationUrl($token);

            if ($isWorkerContext) {
                // WORKER CONTEXT: Send directly via EmailService (we're already in worker!)
                // ENTERPRISE TIPS: Do NOT create new record here - it was already created by AsyncEmailQueue::queueEmail()
                // We only UPDATE the existing record through all processing steps

                // ENTERPRISE TIPS: Worker processing starts NOW
                $workerStartTime = microtime(true);

                // STEP 1: Update EXISTING metrics record to 'processed_by_worker' BEFORE sending
                $workerId = $context['worker_id'] ?? 'worker_' . uniqid() . '_' . getmypid();
                $metricsRecordId = $context['metrics_record_id'] ?? null;  // ENTERPRISE: Extract specific record ID from context
                $this->updateMetricsToProcessing($userId, $email, $workerId, $workerStartTime, $metricsRecordId);  // Pass to method for thread-safe update

                // STEP 2: ACTUAL EMAIL SENDING via EmailService
                Logger::email('info', 'EMAIL: STEP 2 - Starting SMTP delivery via EmailService', [
                    'user_uuid' => $userUuid,
                    'user_id' => $userId,
                    'worker_id' => $workerId,
                    'email_hash' => hash('sha256', $email),
                    'verification_url_ready' => true,
                    'smtp_delivery_starting' => true,
                ]);

                $emailService = new EmailService();
                $emailResult = $emailService->sendVerificationEmail($email, $nickname, $verificationUrl);

                // ENTERPRISE TIPS: Processing time is from worker start to now
                $processingTimeMs = round((microtime(true) - $workerStartTime) * 1000, 2);

                Logger::email('info', 'EMAIL: STEP 2 COMPLETED - SMTP delivery ' . ($emailResult ? 'successful' : 'failed'), [
                    'user_uuid' => $userUuid,
                    'user_id' => $userId,
                    'worker_id' => $workerId,
                    'email_hash' => hash('sha256', $email),
                    'smtp_success' => $emailResult,
                    'processing_time_ms' => $processingTimeMs,
                    'worker_direct_send' => true,
                    'no_double_queuing' => true,
                ]);

                // STEP 3: Update metrics to final status AFTER sending
                // 🚀 ENTERPRISE TIPS: Skip final update when in worker context
                // AsyncEmailQueue handles the final update with queue time calculation
                // This prevents double UPDATE conflict where both services try to update same record
                if (!$isWorkerContext) {
                    // DIRECT SEND CONTEXT: EmailVerificationService handles final update
                    if ($emailResult) {
                        $this->updateMetricsToSuccess($userId, $email, $workerId, $processingTimeMs, $metricsRecordId);
                    } else {
                        $this->updateMetricsToFailed($userId, $email, $workerId, $processingTimeMs, $metricsRecordId);
                    }
                } else {
                    // WORKER CONTEXT: AsyncEmailQueue will handle final update
                    // with queue time calculation - we just log here
                    Logger::email('info', 'EMAIL: STEP 3 SKIPPED - AsyncEmailQueue will handle final metrics update', [
                        'user_uuid' => $userUuid,
                        'user_id' => $userId,
                        'worker_id' => $workerId,
                        'smtp_success' => $emailResult,
                        'processing_time_ms' => $processingTimeMs,
                        'metrics_record_id' => $metricsRecordId,
                        'reason' => 'worker_context_bypass',
                        'final_update_delegated_to' => 'AsyncEmailQueue::updateEmailVerificationMetrics()',
                    ]);
                }
            } else {
                // USER CONTEXT: Queue for worker processing
                $asyncEmailQueue = new AsyncEmailQueue();
                $emailResult = $asyncEmailQueue->queueVerificationEmail($userId, $email, $nickname);

                Logger::email('info', 'EMAIL: ENTERPRISE UUID-FIRST - Email queued for worker processing', [
                    'user_uuid' => $userUuid,
                    'user_id' => $userId,
                    'queued_for_worker' => true,
                ]);
            }

            if ($emailResult) {
                // ENTERPRISE TIPS: Increment rate limit ONLY after successful email send
                // ENTERPRISE TIPS: Rate limit now properly logged before check, no need to increment after success

                // ENTERPRISE TIPS: Store idempotency key and message ID after successful send
                $messageId = uniqid('msg_', true);
                $this->storeIdempotencyRecord($idempotencyKey, $messageId, $userUuid, $email, $context);

                Logger::email('info', 'EMAIL: ENTERPRISE UUID-FIRST - Verification email sent with idempotency tracking', [
                    'user_uuid' => $userUuid,
                    'user_id' => $userId,
                    'email_hash' => hash('sha256', $email),
                    'message_id' => $messageId,
                    'idempotency_key' => $idempotencyKey,
                    'token_length' => strlen($token),
                    'verification_url_length' => strlen($verificationUrl),
                    'race_condition_eliminated' => true,
                    'duplicate_prevention' => 'active',
                ]);

                // ENTERPRISE METRICS: Record email sent event for hourly/daily metrics
                // Only skip if we're in a worker AND processing from async queue (not direct user resend)
                $emailContext = $context['email_context'] ?? 'unknown';
                $skipMetrics = $isWorkerContext && $emailContext !== 'resend';

                if (!$skipMetrics) {
                    $asyncEmailQueue = new AsyncEmailQueue();
                    $emailSize = $asyncEmailQueue->calculateRealEmailSize($email);
                    $this->metricsService->recordEmailSent('verification', [
                        'recipient' => $email,
                        'context' => 'uuid_direct',
                        'user_id' => $userId,
                        'user_uuid' => $userUuid,
                        'queue_time' => microtime(true),
                        'priority' => 'normal',
                        'size' => $emailSize,
                    ]);
                }

                return [
                    'success' => true,
                    'message' => 'Email di verifica inviata con successo',
                    'user_uuid' => $userUuid,
                    'user_id' => $userId,
                    'message_id' => $messageId,
                    'idempotency_key' => $idempotencyKey,
                ];
            }

            Logger::email('error', 'EMAIL: ENTERPRISE UUID-FIRST - Email sending failed', [
                'user_uuid' => $userUuid,
                'user_id' => $userId,
                'email_service_error' => $emailResult['error'] ?? 'unknown',
                'token_created' => true,
            ]);

            return [
                'success' => false,
                'error' => 'Errore nell\'invio dell\'email di verifica',
            ];

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: ENTERPRISE UUID-FIRST - Exception in email verification', [
                'user_uuid' => $userUuid,
                'email_hash' => hash('sha256', $email),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'requires_investigation' => true,
            ]);

            // ENTERPRISE: Use standardized error code
            $errorCode = EmailErrorCodeRegistry::EMAIL_SEND_FAILED;

            return [
                'success' => false,
                'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                'error_code' => $errorCode,
            ];
        }
    }

    /**
     * Verifica token di verifica email con controlli anti-abuse e ottimizzazioni per spike
     */
    public function verifyEmailToken(string $token): array
    {
        try {
            // ENTERPRISE GALAXY SECURITY FIX 2026-02-01: Rate limit token verification attempts
            // Prevents brute-forcing 6-digit codes (1,000,000 possible combinations)
            $ip = \Need2Talk\Services\Security\TrustedProxyValidator::getClientIp();
            $rateLimiter = new EnterpriseRedisRateLimitManager();

            // Check IP-based rate limit first
            $rateLimitResult = $rateLimiter->checkRateLimit('email_verify_token', 'ip', $ip);
            if (!($rateLimitResult['allowed'] ?? true)) {
                Logger::security('warning', 'SECURITY: Email verification token brute-force blocked', [
                    'ip' => $ip,
                    'reason' => 'ip_rate_limit_exceeded',
                ]);

                return [
                    'success' => false,
                    'error' => 'Too many verification attempts. Please try again later.',
                    'error_code' => EmailErrorCodeRegistry::RATE_LIMITED ?? 'RATE_LIMITED',
                    'retry_after' => 3600,
                ];
            }

            // SECURITY: Validazione formato token
            if (!$this->isValidTokenFormat($token)) {
                $this->logSuspiciousActivity('Invalid token format', $token);

                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::TOKEN_INVALID_FORMAT;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                ];
            }

            // ENTERPRISE: Use VerificationSpikeOptimizer for 100k+ concurrent users
            $spikeOptimizer = new VerificationSpikeOptimizer();

            // Check circuit breaker before processing
            if ($spikeOptimizer->isCircuitBreakerActive()) {
                error_log('Verification blocked by circuit breaker - Token hash: ' . hash('sha256', $token) . ', IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::SYSTEM_OVERLOADED;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                ];
            }

            // PERFORMANCE: Optimized lookup with spike handling
            $tokenData = $spikeOptimizer->optimizeVerification($token);

            // FALLBACK: If spike optimizer fails, use traditional method
            if (!$tokenData) {
                error_log('Spike optimizer failed, falling back to traditional lookup - Token hash: ' . hash('sha256', $token));

                // PERFORMANCE: Cerca token in Redis prima
                $tokenData = $this->getTokenFromRedis($token);

                // FALLBACK: Se non in Redis, cerca in database
                if (!$tokenData) {
                    $tokenData = $this->getTokenFromDatabase($token);
                }
            }

            if (!$tokenData) {
                $this->logSuspiciousActivity('Token not found', $token);

                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::TOKEN_NOT_FOUND;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                ];
            }

            // ENTERPRISE GALAXY SECURITY FIX 2026-02-01: Per-email rate limit
            // Now that we have the email, check per-email brute force attempts
            $email = $tokenData['email'] ?? null;
            if ($email && !$rateLimiter->checkLimit('email_verify_token', $email, 'email')) {
                Logger::security('warning', 'SECURITY: Email verification brute-force blocked (per-email)', [
                    'ip' => $ip,
                    'email_hash' => hash('sha256', $email),
                    'reason' => 'email_rate_limit_exceeded',
                ]);

                return [
                    'success' => false,
                    'error' => 'Too many verification attempts for this email. Please try again tomorrow.',
                    'error_code' => EmailErrorCodeRegistry::RATE_LIMITED ?? 'RATE_LIMITED',
                    'retry_after' => 86400,
                ];
            }

            // SECURITY: Verifica che il token non sia scaduto
            // Convert PostgreSQL timestamp string to Unix timestamp
            $createdAtTimestamp = strtotime($tokenData['created_at']);

            // ENTERPRISE FIX: Handle strtotime() failure (NULL, empty string, or invalid format)
            if ($createdAtTimestamp === false) {
                Logger::security('error', 'SECURITY: Invalid created_at timestamp in token data', [
                    'created_at_raw' => $tokenData['created_at'],
                    'created_at_type' => gettype($tokenData['created_at']),
                    'token_hash' => substr(hash('sha256', $token), 0, 16) . '...',
                    'action' => 'treating_as_expired',
                    'fix_required' => 'database_integrity_check',
                ]);

                // Treat invalid timestamp as expired token for security
                $this->cleanupExpiredToken($token);

                $errorCode = EmailErrorCodeRegistry::TOKEN_EXPIRED;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                    'action' => 'resend_required',
                ];
            }

            if ($this->isTokenExpired($createdAtTimestamp)) {
                $this->cleanupExpiredToken($token);

                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::TOKEN_EXPIRED;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                    'action' => 'resend_required',
                ];
            }

            // SECURITY: Verifica che l'utente esista
            $user = $this->userModel->findById($tokenData['user_id']);

            if (!$user) {
                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::DATABASE_USER_NOT_FOUND;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                ];
            }

            // SECURITY: Verifica che non sia già verificato
            if ($user['email_verified']) {
                $this->cleanupToken($token);

                // ENTERPRISE: Use custom error message since there's no exact match in registry
                return [
                    'success' => false,
                    'error' => 'Email già verificata',
                    'error_code' => 'ALREADY_VERIFIED', // Custom code for this specific case
                ];
            }

            // ENTERPRISE V11.8: Atomic lock to prevent race condition on simultaneous verifications
            // Problem: Two clicks within 3 seconds can both pass the email_verified check
            // Solution: Use Redis SETNX (SET if Not eXists) as a distributed lock
            $redis = $this->getRedisConnection();
            $lockKey = 'email_verify_lock:' . $tokenData['user_id'];

            if ($redis) {
                // Try to acquire lock (expires in 30 seconds to prevent deadlocks)
                $lockAcquired = $redis->set($lockKey, time(), ['NX', 'EX' => 30]);

                if (!$lockAcquired) {
                    // Another request is already processing this verification
                    Logger::security('warning', 'EMAIL: Duplicate verification attempt blocked', [
                        'user_id' => $tokenData['user_id'],
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]);

                    return [
                        'success' => false,
                        'error' => 'Verifica già in corso, riprova tra qualche secondo',
                        'error_code' => 'VERIFICATION_IN_PROGRESS',
                    ];
                }
            }

            // SUCCESS: Marca email come verificata
            $verified = $this->markEmailAsVerified($tokenData['user_id']);

            if ($verified) {
                // CLEANUP: Rimuovi token usato
                $this->cleanupToken($token);

                // ANALYTICS: Log successful verification
                error_log('Email verification successful - User ID: ' . $tokenData['user_id'] . ', Email hash: ' . hash('sha256', $user['email']) . ', IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                // 🚀 ENTERPRISE: Queue welcome email post-verification (NON-BLOCKING)
                // Email di benvenuto inviata DOPO la verifica con successo
                try {
                    $asyncEmailQueue = new AsyncEmailQueue();

                    // ENTERPRISE UUID-FIRST: Use UUID if available for consistency
                    $userUuid = $user['uuid'] ?? null;

                    if ($userUuid) {
                        $welcomeQueued = $asyncEmailQueue->queueWelcomeEmailByUuid(
                            $userUuid,
                            $user['email'],
                            $user['nickname']
                        );
                    } else {
                        // Fallback to user_id method
                        $welcomeQueued = $asyncEmailQueue->queueWelcomeEmail(
                            $user['id'],
                            $user['email'],
                            $user['nickname']
                        );
                    }

                    if ($welcomeQueued) {
                        Logger::email('info', 'EMAIL: Welcome email queued post-verification', [
                            'user_id' => $user['id'],
                            'user_uuid' => $userUuid,
                            'email_hash' => hash('sha256', $user['email']),
                            'trigger' => 'email_verification_success',
                        ]);
                    } else {
                        // NON-BLOCKING: Log warning but don't fail verification
                        Logger::email('warning', 'EMAIL: Welcome email queue failed (non-critical)', [
                            'user_id' => $user['id'],
                            'email_hash' => hash('sha256', $user['email']),
                            'impact' => 'user_verified_but_no_welcome_email',
                        ]);
                    }
                } catch (\Exception $e) {
                    // NON-BLOCKING: Log error but don't fail verification
                    Logger::email('error', 'EMAIL: Welcome email queue exception (non-critical)', [
                        'user_id' => $user['id'],
                        'error' => $e->getMessage(),
                        'impact' => 'user_verified_but_no_welcome_email',
                    ]);
                }

                return [
                    'success' => true,
                    'message' => 'Email verificata con successo!',
                    'user' => [
                        'id' => $user['id'],
                        'nickname' => $user['nickname'],
                        'email' => $user['email'],
                    ],
                ];
            }

            throw new \Exception('Errore nella verifica email');
        } catch (\Exception $e) {
            error_log('Email verification failed - Token hash: ' . hash('sha256', $token) . ', Error: ' . $e->getMessage());

            // ENTERPRISE: Use standardized error code
            $errorCode = EmailErrorCodeRegistry::SYSTEM_UNKNOWN_ERROR;

            return [
                'success' => false,
                'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                'error_code' => $errorCode,
            ];
        }
    }

    /**
     * ENTERPRISE: Resend verification email with IP blocking and privacy protection
     * SECURITY: Anti-malicious IP blocking after 5 failed attempts for 4 hours
     * PRIVACY: Never reveals user information (nickname, status, etc.)
     */
    public function resendVerificationEmail(string $email): array
    {
        // ENTERPRISE SECURITY: Check IP blocking first (4-hour block after 5 failed attempts)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if ($this->isIPBlockedForResend($ip)) {
            // ENTERPRISE: Use standardized error code
            $errorCode = EmailErrorCodeRegistry::SECURITY_IP_BLOCKED;

            return [
                'success' => false,
                'error' => 'Hai inserito troppi indirizzi email errati. Riprova fra 4 ore dall\'ultimo tentativo.',
                'error_code' => $errorCode,
            ];
        }

        // SECURITY: Rate limiting per IP (10/hour)
        if (!$this->checkRateLimit($email)) {
            return [
                'success' => false,
                'error' => 'Troppi tentativi. Riprova più tardi.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
            ];
        }

        // SECURITY: Rate limiting specifico per resend (5 minuti tra email)
        if (!$this->checkResendRateLimit($email)) {
            // ENTERPRISE: Use standardized error code
            $errorCode = EmailErrorCodeRegistry::RATE_LIMIT_EMAIL_EXCEEDED;

            return [
                'success' => false,
                'error' => 'Puoi richiedere una nuova email solo ogni 5 minuti',
                'error_code' => $errorCode,
            ];
        }

        // Trova utente
        $user = $this->userModel->findByEmail($email);

        if (!$user) {
            // ENTERPRISE SECURITY: Track failed resend attempts by IP
            $this->trackFailedResendAttempt($ip, $email);

            // PRIVACY: Messaggio standard per non rivelare se l'email esiste
            return [
                'success' => true,
                'message' => 'Se l\'indirizzo email è registrato e non verificato, riceverai una nuova email di verifica. Controlla la tua casella email.',
            ];
        }

        if ($user['email_verified']) {
            // ENTERPRISE SECURITY: Track failed resend attempts by IP (email already verified)
            $this->trackFailedResendAttempt($ip, $email);

            // PRIVACY: Messaggio standard per non rivelare lo stato dell'email
            return [
                'success' => true,
                'message' => 'Se l\'indirizzo email è registrato e non verificato, riceverai una nuova email di verifica. Controlla la tua casella email.',
            ];
        }

        // ENTERPRISE SECURITY: Reset failed attempts counter for valid unverified email
        $this->resetFailedResendAttempts($ip);

        // CLEANUP: Rimuovi vecchi token
        $this->cleanupUserTokens($user['id']);

        // ENTERPRISE TIPS: Direct bypass to queueVerificationEmailByUuid to prevent duplicate records
        // User has UUID from findByEmail, bypass sendVerificationEmailAsync to avoid loop
        $userUuid = $user['uuid'] ?? null;

        if ($userUuid) {
            // Direct path: Generate token here and queue with UUID (like registration)
            $token = $this->generateSecureToken($user['id']);
            $this->storeTokenInRedis($token, $user['id'], $user['email']);
            $this->storeTokenInDatabaseWithUuid($user['id'], $userUuid, $token);

            // Direct queueing with UUID - worker will use bypass
            $asyncEmailQueue = new AsyncEmailQueue();
            $emailQueued = $asyncEmailQueue->queueVerificationEmailByUuid($userUuid, $user['email'], $user['nickname'] ?? 'User', 'resend');

            $result = [
                'success' => $emailQueued,
                'message' => $emailQueued ? 'Email di verifica inviata con successo' : 'Errore nell\'accodamento email',
            ];
        } else {
            // Fallback to old method if no UUID
            $result = $this->sendVerificationEmailAsync($user['id'], $user['email'], $user['nickname'] ?? 'User', 'resend');
        }

        // PRIVACY: Sostituisci il messaggio con uno standard che non rivela informazioni
        if ($result['success']) {
            $result['message'] = 'Se l\'indirizzo email è registrato e non verificato, riceverai una nuova email di verifica. Controlla la tua casella email.';
        }

        return $result;
    }

    /**
     * ENTERPRISE: Send verification email using AsyncEmailQueue worker system
     * Supports 100k+ concurrent users with Redis L1/L3 caching
     */
    public function sendVerificationEmailAsync(int $userId, string $email, string $nickname, string $context = 'initial'): array
    {
        // ENTERPRISE: Track operation start time for metrics
        $startTime = microtime(true);

        try {
            // SECURITY: Rate limiting per IP e email
            if (!$this->checkRateLimit($email)) {
                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::RATE_LIMIT_GLOBAL_EXCEEDED;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                ];
            }

            // SECURITY: Verifica che l'utente esista e non sia già verificato
            $user = $this->userModel->findById($userId);

            if (!$user) {
                Logger::email('warning', 'EMAIL: Verification email attempt for non-existent user', [
                    'user_id' => $userId,
                    'email_hash' => hash('sha256', $email),
                    'context' => $context,
                ]);

                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::DATABASE_USER_NOT_FOUND;

                return [
                    'success' => false,
                    'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                    'error_code' => $errorCode,
                ];
            }

            if ($user['email_verified']) {
                // ENTERPRISE: Use custom error message since there's no exact match in registry
                return [
                    'success' => false,
                    'error' => 'Email già verificata',
                    'error_code' => 'ALREADY_VERIFIED', // Custom code for this specific case
                ];
            }

            // SECURITY: Genera token crittograficamente sicuro
            $token = $this->generateSecureToken($userId);

            // ENTERPRISE TIPS: Get user UUID FIRST for database storage
            $user = $this->userModel->findById($userId);
            $userUuid = $user['uuid'] ?? null;

            // PERFORMANCE: Salva token in Redis L1 per scalabilità
            $this->storeTokenInRedis($token, $userId, $email);

            // BACKUP: Salva anche in database per resilienza CON UUID
            if ($userUuid) {
                $this->storeTokenInDatabaseWithUuid($userId, $userUuid, $token);
            } else {
                $this->storeTokenInDatabase($userId, $token);
            }

            // ENTERPRISE: Use AsyncEmailQueue worker system for scalability
            $asyncEmailQueue = new AsyncEmailQueue();
            $verificationUrl = $this->buildVerificationUrl($token);

            if ($userUuid) {
                $emailQueued = $asyncEmailQueue->queueVerificationEmailByUuid($userUuid, $email, $nickname, $context);
            } else {
                // Fallback to user_id method if UUID not available
                $emailQueued = $asyncEmailQueue->queueVerificationEmail($userId, $email, $nickname, $context);
            }

            if ($emailQueued) {
                // ENTERPRISE TIPS: Increment rate limit ONLY after successful email queue
                // ENTERPRISE TIPS: Rate limit now properly logged before check, no need to increment after success

                // ENTERPRISE: Update resend rate limiting timestamp (CRITICAL FIX)
                if ($context === 'resend' || $context === 'initial_registration') {
                    $this->updateResendRateLimit($email);
                }

                // ANALYTICS: Log successful email queue
                Logger::email('info', 'EMAIL: Verification email queued successfully', [
                    'user_id' => $userId,
                    'email_hash' => hash('sha256', $email),
                    'token_hash' => hash('sha256', $token),
                    'context' => $context,
                    'queue_system' => 'AsyncEmailQueue',
                ]);

                // ENTERPRISE TIPS: Let AsyncEmailQueue worker handle ALL metrics recording
                // This prevents duplicate records - only worker creates and updates metrics
                // Removed recordEmailVerificationEvent call to eliminate Record ID 83 type duplicates

                // ENTERPRISE METRICS: Record email sent event with REAL email size
                $asyncEmailQueue = new AsyncEmailQueue();
                $emailSize = $asyncEmailQueue->calculateRealEmailSize($email);
                $this->metricsService->recordEmailSent('verification', [
                    'recipient' => $email,
                    'context' => $context,
                    'user_id' => $userId,
                    'queue_time' => microtime(true),
                    'priority' => 'normal',
                    'size' => $emailSize,
                ]);

                // ENTERPRISE UNIFIED METRICS: Email successfully queued (NOT sent yet - worker will record actual send)
                // NOTE: 'sent_successfully' will be recorded by AsyncEmailQueue worker after actual SMTP delivery

                return [
                    'success' => true,
                    'message' => 'Email di verifica inviata con successo',
                    'token_expires' => self::TOKEN_EXPIRY_HOURS . ' ore',
                ];
            }

            Logger::email('error', 'EMAIL: Failed to queue verification email', [
                'user_id' => $userId,
                'email_hash' => hash('sha256', $email),
                'context' => $context,
                'queue_system' => 'AsyncEmailQueue',
            ]);

            // ENTERPRISE METRICS: Record email failure
            $this->metricsService->recordEmailFailure('verification', [
                'recipient' => $email,
                'context' => $context,
                'user_id' => $userId,
                'processing_time' => 0,
            ], 'Queue system failure - AsyncEmailQueue not accepting jobs');

            // ENTERPRISE UNIFIED METRICS: Record failure in ALL 3 tables
            $errorCode = EmailErrorCodeRegistry::EMAIL_QUEUE_FAILED;
            $this->unifiedMetrics->recordEmailVerificationEvent($userId, 'send_failed', [
                'email_type' => 'verification',
                'context' => $context,
                'queue_start_time' => $startTime,
                'error_code' => $errorCode,
                'error_message' => EmailErrorCodeRegistry::getDescription($errorCode),
            ]);

            // ENTERPRISE: Use standardized error code
            $errorCode = EmailErrorCodeRegistry::EMAIL_QUEUE_FAILED;

            return [
                'success' => false,
                'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                'error_code' => $errorCode,
            ];

        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Exception in sendVerificationEmailAsync', [
                'user_id' => $userId,
                'email_hash' => hash('sha256', $email),
                'context' => $context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // ENTERPRISE METRICS: Record exception as email failure
            $this->metricsService->recordEmailFailure('verification', [
                'recipient' => $email,
                'context' => $context,
                'user_id' => $userId,
                'processing_time' => 0,
            ], 'Service exception: ' . $e->getMessage());

            // ENTERPRISE UNIFIED METRICS: Record critical failure in ALL 3 tables
            $errorCode = EmailErrorCodeRegistry::detectErrorCode($e);
            $this->unifiedMetrics->recordEmailVerificationEvent($userId, 'critical_failure', [
                'email_type' => 'verification',
                'context' => $context,
                'queue_start_time' => $startTime,
                'error_code' => $errorCode,
                'error_message' => $e->getMessage(),
            ]);

            // ENTERPRISE: Use standardized error code
            $errorCode = EmailErrorCodeRegistry::SYSTEM_CRITICAL_FAILURE;

            return [
                'success' => false,
                'error' => EmailErrorCodeRegistry::getDescription($errorCode),
                'error_code' => $errorCode,
            ];
        }
    }

    // ==================== PRIVATE SECURITY METHODS ====================

    private function generateSecureToken(int $userId): string
    {
        // SECURITY: Token crittograficamente sicuro con user_id embedded
        $randomBytes = random_bytes(32);
        $userIdHash = hash('sha256', $userId . time() . random_bytes(16));
        // ENTERPRISE TIPS: Use full hash as binary data for proper 128-char token
        $userIdHashBytes = hex2bin($userIdHash);
        $combined = $randomBytes . $userIdHashBytes;

        return bin2hex($combined);
    }

    private function isValidTokenFormat(string $token): bool
    {
        return strlen($token) === self::TOKEN_LENGTH && ctype_xdigit($token);
    }

    private function checkRateLimit(string $email): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // ENTERPRISE TIPS: Record attempt FIRST, then check limits
        // This ensures every request is logged for proper rate limiting
        $this->rateLimitService->recordAttempt($ip, 'email_verification', 'ip');
        $this->rateLimitService->recordAttempt($email, 'email_verification', 'email');

        // Check IP rate limit using enterprise service (uses database logs)
        $ipAllowed = $this->rateLimitService->checkIPRateLimit($ip, 'email_verification', self::RATE_LIMIT_PER_IP_HOUR);

        // Check email rate limit using enterprise service (uses database logs)
        $emailAllowed = $this->rateLimitService->checkEmailRateLimit($email, 'email_verification', self::RATE_LIMIT_PER_EMAIL_DAY);

        // ENTERPRISE SECURITY: Track violations and escalate for EVERY attempt when limits exceeded
        $ipAttempts = $this->countRecentAttempts($ip, 'ip');
        $emailAttempts = $this->countRecentAttempts($email, 'email');

        if ($ipAttempts >= self::RATE_LIMIT_PER_IP_HOUR) {
            $this->trackRateLimitViolation($ip, 'email_verification', 'ip', $ipAttempts);
        }

        if ($emailAttempts >= self::RATE_LIMIT_PER_EMAIL_DAY) {
            $this->trackRateLimitViolation($email, 'email_verification', 'email', $emailAttempts);
        }

        // ENTERPRISE GALAXY: Log rate limit decision with full context
        $decision = $ipAllowed && $emailAllowed;
        if ($decision) {
            Logger::security('debug', 'SECURITY: Rate limit check PASSED', [
                'email_hash' => hash('sha256', $email),
                'ip_hash' => hash('sha256', $ip),
                'ip_attempts' => $ipAttempts,
                'email_attempts' => $emailAttempts,
                'ip_limit' => self::RATE_LIMIT_PER_IP_HOUR,
                'email_limit' => self::RATE_LIMIT_PER_EMAIL_DAY,
                'decision' => 'allowed',
            ]);
        } else {
            Logger::security('warning', 'SECURITY: Rate limit check BLOCKED', [
                'email_hash' => hash('sha256', $email),
                'ip_hash' => hash('sha256', $ip),
                'ip_attempts' => $ipAttempts,
                'email_attempts' => $emailAttempts,
                'ip_limit' => self::RATE_LIMIT_PER_IP_HOUR,
                'email_limit' => self::RATE_LIMIT_PER_EMAIL_DAY,
                'ip_allowed' => $ipAllowed,
                'email_allowed' => $emailAllowed,
                'decision' => 'blocked',
                'reason' => !$ipAllowed ? 'ip_limit_exceeded' : 'email_limit_exceeded',
            ]);
        }

        return $decision;
    }

    private function checkResendRateLimit(string $email): bool
    {
        $key = 'resend:' . hash('sha256', $email);
        $lastSend = $this->getRedisValue($key);

        $now = (new \DateTime('now', new \DateTimeZone('Europe/Rome')))->getTimestamp();

        // ENTERPRISE: Explicit cast to int for type-safe arithmetic operation
        $allowed = !$lastSend || ($now - (int) $lastSend) > 300; // 5 minutes

        // ENTERPRISE GALAXY: Log resend rate limit decision
        if ($allowed) {
            Logger::security('debug', 'SECURITY: Resend rate limit check PASSED', [
                'email_hash' => hash('sha256', $email),
                'last_send_timestamp' => $lastSend ? (int) $lastSend : null,
                'time_since_last_send_seconds' => $lastSend ? ($now - (int) $lastSend) : null,
                'required_interval_seconds' => 300,
                'decision' => 'allowed',
            ]);
        } else {
            $secondsRemaining = 300 - ($now - (int) $lastSend);
            Logger::security('warning', 'SECURITY: Resend rate limit check BLOCKED', [
                'email_hash' => hash('sha256', $email),
                'last_send_timestamp' => (int) $lastSend,
                'time_since_last_send_seconds' => $now - (int) $lastSend,
                'required_interval_seconds' => 300,
                'seconds_remaining' => $secondsRemaining,
                'decision' => 'blocked',
                'reason' => '5_minute_interval_not_elapsed',
            ]);
        }

        return $allowed;
    }

    /**
     * ENTERPRISE: Update resend rate limiting timestamp after successful queue
     */
    private function updateResendRateLimit(string $email): void
    {
        $key = 'resend:' . hash('sha256', $email);
        $now = (new \DateTime('now', new \DateTimeZone('Europe/Rome')))->getTimestamp();

        // Store timestamp in Redis with 1-hour TTL (longer than 5-minute limit for analytics)
        $this->setRedisValue($key, $now, 3600);
    }

    private function storeTokenInRedis(string $token, int $userId, string $email): void
    {
        if ($this->isRedisAvailable()) {
            $key = self::REDIS_TOKEN_PREFIX . $token;
            $data = json_encode([
                'user_id' => $userId,
                'email' => $email,
                'created_at' => (new \DateTime('now', new \DateTimeZone('Europe/Rome')))->getTimestamp(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->setRedisValue($key, $data, self::TOKEN_EXPIRY_HOURS * 3600);
        }
    }

    private function storeTokenInDatabase(int $userId, string $token): void
    {
        // Assicura che PostgreSQL usi il timezone corretto
        $this->ensureDatabaseTimezone();

        // ENTERPRISE FIX: No UNIQUE constraint exists, just INSERT
        // Multiple tokens per user allowed (resend scenario)
        // Old tokens cleanup handled by cron (DELETE WHERE expires_at < NOW())
        $stmt = db_pdo()->prepare(
            "INSERT INTO email_verification_tokens (user_id, token, expires_at, created_at)
             VALUES (?, ?, NOW() + INTERVAL '1 hour' * ?, NOW())"
        );

        $stmt->execute([$userId, hash('sha256', $token), self::TOKEN_EXPIRY_HOURS]);
    }

    private function markEmailAsVerified(int $userId): bool
    {
        // Assicura che PostgreSQL usi il timezone corretto
        $this->ensureDatabaseTimezone();

        $stmt = db_pdo()->prepare(
            'UPDATE users SET email_verified = TRUE, email_verified_at = NOW() WHERE id = ?'
        );

        $success = $stmt->execute([$userId]);

        // ENTERPRISE GALAXY: Log successful email verification (critical business event)
        if ($success) {
            Logger::email('info', 'EMAIL: User email SUCCESSFULLY VERIFIED', [
                'user_id' => $userId,
                'verification_timestamp' => date('Y-m-d H:i:s'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'business_event' => 'email_verification_completed',
                'user_can_now_access' => 'full_platform_features',
            ]);
        } else {
            Logger::email('error', 'EMAIL: FAILED to mark email as verified in database', [
                'user_id' => $userId,
                'database_operation' => 'UPDATE users SET email_verified',
                'requires_investigation' => true,
            ]);
        }

        return $success;
    }

    private function buildVerificationUrl(string $token): string
    {
        // ENTERPRISE TIPS: Direct URL building to avoid Laravel UrlGenerator dependency in workers
        $base = $_ENV['APP_URL'] ?? null;

        if (!$base) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || $_SERVER['SERVER_PORT'] === 443 ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? env('SERVER_IP', 'YOUR_SERVER_IP');
            $base = $protocol . '://' . $host;
        }

        return rtrim($base, '/') . '/auth/verify-email?token=' . urlencode($token);
    }

    /**
     * ENTERPRISE GALAXY: Uses Logger::security() for dual-write (DB + file logs)
     */
    private function logSuspiciousActivity(string $activity, string $token): void
    {
        // ENTERPRISE GALAXY: Map activity to PSR-3 level
        $levelMap = [
            'TOKEN_NOT_FOUND' => 'warning',
            'EXPIRED_TOKEN' => 'warning',
            'INVALID_TOKEN_FORMAT' => 'error',
            'TOKEN_REPLAY_ATTACK' => 'error',
            'MULTIPLE_VERIFICATION_ATTEMPTS' => 'warning',
        ];

        $eventType = strtoupper(str_replace(' ', '_', $activity));
        $level = $levelMap[$eventType] ?? 'warning';

        // ENTERPRISE GALAXY: Dual-write via Logger::security() (DB + file)
        Logger::security($level, "EMAIL_VERIFICATION: {$eventType}", [
            'activity' => $activity,
            'token_hash' => hash('sha256', $token),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }

    // ==================== REDIS HELPER METHODS ====================

    private function isRedisAvailable(): bool
    {
        try {
            return class_exists('Redis') && extension_loaded('redis');
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getRedisConnection(): ?\Redis
    {
        static $redis = null;

        if ($redis === null && $this->isRedisAvailable()) {
            try {
                // ENTERPRISE POOL: Use connection pool for rate limiting
                $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('rate_limit');

                if (!$redis) {
                    $redis = false;
                }
            } catch (\Exception $e) {
                $redis = false;
            }
        }

        return $redis ?: null;
    }

    private function getRedisCount(string $key, int $ttl): int
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return 0;
        }

        try {
            $count = $this->safeRedisCall($redis, 'incr', [$key]);

            if ($count === 1) {
                $this->safeRedisCall($redis, 'expire', [$key, $ttl]);
            }

            return $count ?: 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * ENTERPRISE TIPS: Get Redis count WITHOUT incrementing (for rate limit checking)
     */
    private function getRedisCountWithoutIncrement(string $key): int
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return 0;
        }

        try {
            $count = $this->safeRedisCall($redis, 'get', [$key]);

            return $count ? (int) $count : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * ENTERPRISE TIPS: Increment rate limit counter ONLY after successful email send
     */
    // ENTERPRISE TIPS: Removed incrementRateLimitAfterSuccess() method
    // Rate limiting now properly logs attempts BEFORE checking, using UserRateLimitService
    // This ensures proper rate limiting without race conditions

    private function getRedisValue(string $key): ?string
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return null;
        }

        try {
            $value = $redis->get($key);

            return $value === false ? null : $value;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function setRedisValue(string $key, string $value, int $ttl): void
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            Logger::database('error', 'REDIS: Connection failed in setRedisValue', [
                'key' => substr($key, 0, 50) . '...',
                'method' => 'setRedisValue',
            ]);

            return;
        }

        try {
            $result = $redis->setex($key, $ttl, $value);

            if (!$result) {
                Logger::database('error', 'REDIS: setex returned false', [
                    'key' => substr($key, 0, 50) . '...',
                    'ttl' => $ttl,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Logger::database('error', 'REDIS: setex failed with exception', [
                'key' => substr($key, 0, 50) . '...',
                'error' => $e->getMessage(),
                'ttl' => $ttl,
            ]);
        }
    }

    // ==================== DATABASE FALLBACK METHODS ====================

    private function getTokenFromRedis(string $token): ?array
    {
        $redis = $this->getRedisConnection();

        if (!$redis) {
            return null;
        }

        try {
            $key = self::REDIS_TOKEN_PREFIX . $token;
            $data = $redis->get($key);

            return $data ? json_decode($data, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getTokenFromDatabase(string $token): ?array
    {
        // SECURITY: Token is ALWAYS stored as SHA256 hash in database
        // Search only for hashed token
        $stmt = db_pdo()->prepare(
            'SELECT user_id, created_at FROM email_verification_tokens
             WHERE token = ? AND expires_at > NOW()'
        );

        $stmt->execute([hash('sha256', $token)]);
        $result = $stmt->fetch();

        if (!$result) {
            Logger::database('warning', 'DATABASE: Token NOT found in fallback lookup', [
                'token_hash' => substr(hash('sha256', $token), 0, 16) . '...',
                'possible_reasons' => ['token_invalid', 'token_expired', 'token_already_used'],
            ]);
        }

        return $result ?: null;
    }

    private function isTokenExpired(int $createdAt): bool
    {
        // ENTERPRISE FIX: Defense in depth - validate timestamp is reasonable
        // If timestamp is 0 or negative, it's invalid (should never happen after upstream fix)
        if ($createdAt <= 0) {
            Logger::security('critical', 'SECURITY: Invalid timestamp passed to isTokenExpired()', [
                'created_at_timestamp' => $createdAt,
                'action' => 'treating_as_expired',
                'defense_in_depth' => true,
                'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
            ]);

            return true; // Treat invalid timestamp as expired for security
        }

        // BUGFIX: Use same timezone for both timestamps
        // $createdAt comes from strtotime() which uses system timezone
        // So we need to use system timezone for $now too
        $now = time(); // System timezone timestamp

        $ageSeconds = $now - $createdAt;
        $expirySeconds = self::TOKEN_EXPIRY_HOURS * 3600;
        $expired = $ageSeconds > $expirySeconds;

        // ENTERPRISE GALAXY: Log token expiry check with timing details
        if ($expired) {
            Logger::security('warning', 'SECURITY: Token EXPIRED during verification attempt', [
                'token_age_seconds' => $ageSeconds,
                'token_age_hours' => round($ageSeconds / 3600, 2),
                'expiry_threshold_hours' => self::TOKEN_EXPIRY_HOURS,
                'created_at_timestamp' => $createdAt,
                'created_at_datetime' => date('Y-m-d H:i:s', $createdAt),
                'exceeded_by_seconds' => $ageSeconds - $expirySeconds,
                'decision' => 'expired',
                'action_required' => 'user_must_request_new_token',
            ]);
        }

        return $expired;
    }

    private function cleanupToken(string $token): void
    {
        // Redis cleanup
        $redis = $this->getRedisConnection();

        if ($redis) {
            try {
                $redis->del(self::REDIS_TOKEN_PREFIX . $token);
            } catch (\Exception $e) {
                // Continue to database cleanup
            }
        }

        // Database cleanup - token is ALWAYS stored as SHA256 hash
        $stmt = db_pdo()->prepare('DELETE FROM email_verification_tokens WHERE token = ?');
        $stmt->execute([hash('sha256', $token)]);
    }

    private function cleanupExpiredToken(string $token): void
    {
        $this->cleanupToken($token);
    }

    private function cleanupUserTokens(int $userId): void
    {
        // ENTERPRISE TIPS: First get all tokens for this user to clean from Redis
        // Note: tokens are stored as SHA256 hashes in database, but cleanupToken() expects original token
        // Since we can't reverse SHA256, we'll delete from database without Redis cleanup
        // Redis keys will expire naturally after TOKEN_EXPIRY_HOURS

        // Delete from database (tokens are hashed)
        $stmt = db_pdo()->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);

        // Redis keys will expire naturally after TOKEN_EXPIRY_HOURS
    }

    /**
     * Assicura che la sessione database usi il timezone italiano
     */
    private function ensureDatabaseTimezone(): void
    {
        static $timezoneSet = false;

        if (!$timezoneSet) {
            try {
                db_pdo()->exec("SET timezone = 'Europe/Rome'"); // PostgreSQL: 'timezone' not 'time_zone'
                $timezoneSet = true;
            } catch (\Exception $e) {
                // Log ma non blocca l'operazione
                error_log('Could not set database timezone - Error: ' . $e->getMessage());
            }
        }
    }

    // ==================== ENTERPRISE UUID-FIRST HELPER METHODS ====================

    /**
     * 🚀 ENTERPRISE UUID-FIRST: Get user_id from UUID with Redis L1 caching
     * Eliminates race conditions in high-concurrency scenarios
     */
    private function getUserIdFromUuid(string $userUuid): ?int
    {
        // Try Redis L1 cache first for sub-millisecond performance
        $cacheKey = "enterprise:user_id_by_uuid:$userUuid";

        // ENTERPRISE: Initialize $redis for proper scope across try blocks
        $redis = null;

        try {
            $redis = $this->getRedisConnection();

            if ($redis && $redis->ping()) {
                $cachedUserId = $redis->get($cacheKey);

                if ($cachedUserId !== false) {
                    // Cache hit - return immediately (sub-millisecond performance)
                    return (int) $cachedUserId;
                }
            }
        } catch (\Exception $e) {
            Logger::database('warning', 'REDIS: L1 unavailable for user_id lookup', [
                'user_uuid' => $userUuid,
                'redis_error' => $e->getMessage(),
            ]);
        }

        // Fallback to database with enterprise-grade prepared statement
        try {
            $stmt = db_pdo()->prepare('SELECT id FROM users WHERE uuid = ? LIMIT 1');
            $stmt->execute([$userUuid]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result) {
                $userId = (int) $result['id'];

                // Cache for future requests
                try {
                    if ($redis && $redis->ping()) {
                        $redis->setex($cacheKey, 3600, $userId); // 1 hour TTL
                    }
                } catch (\Exception $e) {
                    // Non-blocking: cache write failed but user_id was found
                    Logger::database('warning', 'REDIS: Failed to cache user_id after lookup', [
                        'user_uuid' => $userUuid,
                        'redis_error' => $e->getMessage(),
                    ]);
                }

                return $userId;
            }
        } catch (\Exception $e) {
            Logger::database('error', 'DATABASE: User ID lookup by UUID failed', [
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 🚀 ENTERPRISE UUID-FIRST: Store token in Redis with UUID support
     */
    private function storeTokenInRedisWithUuid(string $token, int $userId, string $userUuid, string $email): void
    {
        if ($this->isRedisAvailable()) {
            try {
                $redis = $this->getRedisConnection();

                if ($redis && $redis->ping()) {
                    // ENTERPRISE FIX (2026-01-24): Use ISO 8601 format for created_at consistency
                    // BUG: Redis stored time() (INTEGER), PostgreSQL stores NOW() (TIMESTAMP)
                    // When token read from Redis → strtotime(INTEGER) → false → token treated as expired
                    // SOLUTION: Always use ISO 8601 string format (compatible with both Redis and PostgreSQL)
                    $tokenData = [
                        'user_id' => $userId,
                        'user_uuid' => $userUuid,
                        'email' => $email,
                        'created_at' => date('Y-m-d H:i:s'), // ISO 8601 format (strtotime() compatible)
                    ];

                    $redis->setex(
                        self::REDIS_TOKEN_PREFIX . $token,
                        self::TOKEN_EXPIRY_HOURS * 3600,
                        json_encode($tokenData)
                    );

                    // Token stored successfully in Redis L1 cache
                }
            } catch (\Exception $e) {
                // Non-blocking: Redis storage failed, database fallback available
                Logger::database('warning', 'REDIS: Token storage failed (database fallback available)', [
                    'user_uuid' => $userUuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 🚀 ENTERPRISE UUID-FIRST: Store token in database with UUID support
     */
    private function storeTokenInDatabaseWithUuid(int $userId, string $userUuid, string $token): void
    {
        try {
            // ENTERPRISE FIX: No UNIQUE constraint exists, just INSERT
            // Multiple tokens per user allowed (resend scenario)
            $stmt = db_pdo()->prepare(
                "INSERT INTO email_verification_tokens (user_id, user_uuid, token, expires_at, created_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL '1 hour' * ?, NOW())"
            );

            $stmt->execute([
                $userId,
                $userUuid,
                hash('sha256', $token), // ENTERPRISE SECURITY: Hash token before database storage
                self::TOKEN_EXPIRY_HOURS,
            ]);

            // Token stored successfully in database

        } catch (\Exception $e) {
            Logger::database('error', 'DATABASE: Verification token storage failed', [
                'user_uuid' => $userUuid,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'requires_investigation' => true,
            ]);

            throw new \Exception('Failed to store verification token in database');
        }
    }

    /**
     * ⭐ ENTERPRISE TIPS: Generate idempotency key for deduplication
     */
    private function generateIdempotencyKey(string $userUuid, string $email, array $context): string
    {
        $workerId = $context['worker_id'] ?? '';
        $emailContext = $context['email_context'] ?? 'default'; // registration vs resend vs direct
        $timestamp = date('Y-m-d'); // Daily uniqueness for verification emails

        return hash('sha256', $userUuid . $email . $emailContext . $workerId . $timestamp);
    }

    /**
     * ⭐ ENTERPRISE TIPS: Check if email already sent with this idempotency key
     *
     * ENTERPRISE STANDARD BEHAVIOR:
     * - Idempotency log is ADVISORY ONLY (for tracking/analytics, 24h retention)
     * - NEVER blocks email sending - only logs for monitoring
     * - Redis rate limiting (5/day per email, 5/hour per IP) is the PRIMARY anti-abuse control
     * - This allows legitimate resends when:
     *   * Token expires (1 hour)
     *   * Email doesn't arrive
     *   * User requests resend for any reason
     * - Privacy protection: System never reveals if email exists in DB
     */
    private function isEmailAlreadySent(string $idempotencyKey): bool
    {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->prepare("
                SELECT id, created_at FROM email_idempotency_log
                WHERE idempotency_key = ? AND created_at > NOW() - INTERVAL '24 hours'
            ");
            $stmt->execute([$idempotencyKey]);

            if ($stmt->rowCount() > 0) {
                // Advisory log only - not blocking
                $record = $stmt->fetch(\PDO::FETCH_ASSOC);
                Logger::email('info', 'EMAIL: Idempotency - Recent send detected (advisory only)', [
                    'idempotency_key' => $idempotencyKey,
                    'last_sent' => $record['created_at'],
                    'not_blocking' => 'redis_rate_limiting_handles_abuse',
                ]);
            }

            // ENTERPRISE: Always return false (never block). Rate limiting handles abuse.
            return false;
        } catch (\Exception $e) {
            Logger::email('error', 'EMAIL: Idempotency check failed', [
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);

            return false; // Fail open to avoid blocking email sending
        }
    }

    /**
     * ⭐ ENTERPRISE TIPS: Store idempotency record after successful email send
     */
    private function storeIdempotencyRecord(string $idempotencyKey, string $messageId, string $userUuid, string $email, array $context): void
    {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->prepare('
                INSERT INTO email_idempotency_log
                (idempotency_key, message_id, user_uuid, email_hash, email_type, worker_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON CONFLICT (id) DO UPDATE SET updated_at = NOW()
            ');

            $stmt->execute([
                $idempotencyKey,
                $messageId,
                $userUuid,
                hash('sha256', $email),
                'verification',
                $context['worker_id'] ?? null,
            ]);

            // Idempotency record stored for analytics

        } catch (\Exception $e) {
            // Non-blocking error - email was already sent successfully
            Logger::email('error', 'EMAIL: Idempotency record storage failed (non-blocking)', [
                'idempotency_key' => $idempotencyKey,
                'message_id' => $messageId,
                'user_uuid' => $userUuid,
                'error' => $e->getMessage(),
            ]);
            // Email was already sent successfully - idempotency is just analytics
        }
    }

    /**
     * ENTERPRISE SECURITY: Track rate limit violations for analysis and escalation
     */
    private function trackRateLimitViolation(string $identifier, string $action, string $type, int $attempts): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $identifierHash = hash('sha256', "rate_limit:{$type}:{$action}:{$identifier}");

            // Get user_id and calculate risk metrics
            $userId = $_SESSION['user_id'] ?? null;
            $violationPattern = $this->detectViolationPattern($identifier, $type);
            $riskScore = $this->calculateRiskScore($attempts, $type, $violationPattern);

            // Insert into violations table for security analysis
            $stmt = db_pdo()->prepare(
                'INSERT INTO user_rate_limit_violations
                 (identifier_hash, action_type, identifier_type, attempts_count, ip_address, user_id, escalation_level, violation_pattern, risk_score, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, NOW())
                 ON CONFLICT (ip_address) DO UPDATE SET
                   attempts_count = EXCLUDED.attempts_count,
                   escalation_level = LEAST(user_rate_limit_violations.escalation_level + 1, 5),
                   violation_pattern = EXCLUDED.violation_pattern,
                   risk_score = EXCLUDED.risk_score,
                   user_id = COALESCE(EXCLUDED.user_id, user_rate_limit_violations.user_id)'
            );

            $stmt->execute([
                $identifierHash,
                $action,
                $type,
                $attempts,
                $ip,
                $userId,
                $violationPattern,
                $riskScore,
            ]);

            // Check if we should escalate to a ban
            $this->checkForRateLimitEscalation($identifierHash, $identifier, $type);

            Logger::security('warning', 'SECURITY: Rate limit violation tracked', [
                'identifier_type' => $type,
                'action' => $action,
                'attempts' => $attempts,
                'identifier_hash' => substr($identifierHash, 0, 12),
                'ip' => $ip,
            ]);

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: Failed to track rate limit violation', [
                'identifier_type' => $type,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Detect violation pattern for risk analysis
     */
    private function detectViolationPattern(string $identifier, string $type): string
    {
        $pattern = [
            'type' => $type === 'ip' ? 'multiple_emails_same_ip' : 'same_email_repeated',
            'timestamp' => date('Y-m-d H:i:s'),
            'window' => $type === 'ip' ? '1_hour' : '24_hours',
        ];

        return json_encode($pattern);
    }

    /**
     * Calculate risk score based on violation pattern
     */
    private function calculateRiskScore(int $attempts, string $type, string $pattern): float
    {
        // Base score starts at 1.0 for reaching the limit (5 for IP, 3 for email)
        $limitThreshold = ($type === 'ip') ? self::RATE_LIMIT_PER_IP_HOUR : self::RATE_LIMIT_PER_EMAIL_DAY;

        if ($attempts < $limitThreshold) {
            return 0.0; // No risk if below limit
        }

        // Base score: 1.0 when reaching limit + 0.25 for each additional attempt
        $excessAttempts = $attempts - $limitThreshold;
        $baseScore = 1.0 + ($excessAttempts * 0.25);

        // Extract pattern type from JSON
        $patternData = json_decode($pattern, true);
        $patternType = $patternData['type'] ?? 'unknown';

        $patternMultiplier = match($patternType) {
            'multiple_emails_same_ip' => 2.0, // Double risk for IP spamming with different emails
            'same_email_repeated' => 1.0,     // Normal risk for same email
            default => 1.0
        };

        return round(min($baseScore * $patternMultiplier, 10.0), 2); // Max score 10.0
    }

    /**
     * Count recent attempts for violations tracking
     */
    private function countRecentAttempts(string $identifier, string $type): int
    {
        try {
            $key = "rate_limit:{$type}:email_verification:{$identifier}";
            $identifierHash = hash('sha256', $key);

            $window = ($type === 'ip') ? 3600 : 86400; // 1 hour for IP, 24 hours for email

            $stmt = db_pdo()->prepare(
                "SELECT COUNT(*) as attempts
                 FROM user_rate_limit_log
                 WHERE identifier_hash = ?
                   AND action_type = 'email_verification'
                   AND identifier_type = ?
                   AND created_at > NOW() - INTERVAL '1 second' * ?"
            );

            $stmt->execute([$identifierHash, $type, $window]);

            return (int) ($stmt->fetch()['attempts'] ?? 0);

        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * ENTERPRISE SECURITY: Check if violations should escalate to ban
     */
    private function checkForRateLimitEscalation(string $identifierHash, string $identifier, string $type): void
    {
        try {
            // Check violation count in last 24 hours
            $stmt = db_pdo()->prepare(
                "SELECT escalation_level, attempts_count
                 FROM user_rate_limit_violations
                 WHERE identifier_hash = ?
                   AND created_at > NOW() - INTERVAL '24 hours'"
            );
            $stmt->execute([$identifierHash]);
            $violation = $stmt->fetch();

            if ($violation && $violation['escalation_level'] >= 3) {
                // Escalate to ban for repeated violations
                $banDuration = min(86400 * $violation['escalation_level'], 604800); // Max 7 days

                // Only ban IPs, not email addresses (ip_address is NOT NULL)
                if ($type === 'ip') {
                    $stmt = db_pdo()->prepare(
                        "INSERT INTO user_rate_limit_bans
                         (ip_address, ban_type, severity, expires_at, reason, violation_count, created_by, created_at)
                         VALUES (?, 'escalated', 'high', NOW() + INTERVAL '1 second' * ?,
                                 'Automated escalation from repeated rate limit violations', ?, 'system', NOW())
                         ON CONFLICT (ip_address) DO UPDATE SET
                           expires_at = NOW() + INTERVAL '1 second' * ?,
                           violation_count = violation_count + 1"
                    );

                    $stmt->execute([
                        $identifier,
                        $banDuration,
                        $violation['escalation_level'],
                        $banDuration,
                    ]);

                    Logger::security('critical', 'SECURITY: IP escalated to ban', [
                        'ip_address' => $identifier,
                        'escalation_level' => $violation['escalation_level'],
                        'ban_duration_hours' => $banDuration / 3600,
                    ]);
                } else {
                    Logger::security('warning', 'SECURITY: Email violation tracked (no IP ban)', [
                        'identifier_type' => $type,
                        'escalation_level' => $violation['escalation_level'],
                        'note' => 'Email violations tracked but no ban created (requires IP)',
                    ]);
                }
            }

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: Failed to check escalation', [
                'identifier_hash' => substr($identifierHash, 0, 12),
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== ENTERPRISE IP BLOCKING FOR RESEND ====================

    /**
     * ENTERPRISE SECURITY: Check if IP is blocked for resend attempts
     * Blocking mechanism: 5 failed attempts = 4-hour block
     */
    private function isIPBlockedForResend(string $ip): bool
    {
        try {
            // Check Redis first (L1 cache)
            $redis = $this->getRedisConnection();

            if ($redis) {
                $blockKey = 'resend_ip_block:' . hash('sha256', $ip);
                $blocked = $this->safeRedisCall($redis, 'get', [$blockKey]);

                if ($blocked) {
                    return true;
                }
            }

            // Fallback to database check using existing user_rate_limit_bans table
            // ENTERPRISE FIX: Use action_type (not ban_type) for rate limit action identification
            $stmt = db_pdo()->prepare(
                "SELECT COUNT(*) as is_blocked FROM user_rate_limit_bans
                 WHERE ip_address = ?
                   AND action_type = 'resend_email'
                   AND expires_at > NOW()"
            );
            $stmt->execute([$ip]);
            $result = $stmt->fetch();

            return ($result['is_blocked'] ?? 0) > 0;

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: Failed to check IP block for resend', [
                'ip_hash' => hash('sha256', $ip),
                'error' => $e->getMessage(),
            ]);

            return false; // Fail-open for availability
        }
    }

    /**
     * ENTERPRISE SECURITY: Track failed resend attempts by IP
     * Uses existing user_rate_limit_log table for tracking
     */
    private function trackFailedResendAttempt(string $ip, string $email): void
    {
        try {
            // Store in database using existing table
            $stmt = db_pdo()->prepare(
                "INSERT INTO user_rate_limit_log
                 (identifier_hash, action_type, identifier_type, ip_address, user_agent, created_at)
                 VALUES (?, 'resend_failed', 'ip', ?, ?, NOW())"
            );
            $stmt->execute([
                hash('sha256', $ip),
                $ip,
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            // Check if this IP has 5 failed attempts in the last hour
            $stmt = db_pdo()->prepare(
                "SELECT COUNT(*) as failed_count
                 FROM user_rate_limit_log
                 WHERE ip_address = ?
                   AND action_type = 'resend_failed'
                   AND created_at >= NOW() - INTERVAL '1 hours'"
            );
            $stmt->execute([$ip]);
            $result = $stmt->fetch();
            $failedCount = $result['failed_count'] ?? 0;

            // Block IP if 5 or more failed attempts
            if ($failedCount >= 5) {
                $this->blockIPForResend($ip, 14400); // 4 hours = 14400 seconds
            }

            Logger::security('warning', 'SECURITY: Failed resend attempt tracked', [
                'ip_hash' => hash('sha256', $ip),
                'email_hash' => hash('sha256', $email),
                'failed_count' => $failedCount,
                'blocked' => $failedCount >= 5,
            ]);

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: Failed to track resend attempt', [
                'ip_hash' => hash('sha256', $ip),
                'email_hash' => hash('sha256', $email),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE SECURITY: Block IP for resend attempts for specified duration
     * Uses existing user_rate_limit_bans table
     */
    private function blockIPForResend(string $ip, int $durationSeconds): void
    {
        try {
            // ENTERPRISE FIX: Use action_type and identifier_hash (not ban_type)
            // Table schema: identifier_hash + action_type = UNIQUE constraint
            $identifierHash = hash('sha256', $ip);

            $stmt = db_pdo()->prepare(
                "INSERT INTO user_rate_limit_bans
                 (ip_address, identifier_hash, identifier_type, action_type, ban_type, severity, expires_at, reason, created_at)
                 VALUES (?, ?, 'ip', 'resend_email', 'temporary', 'medium', NOW() + INTERVAL '1 second' * ?, 'Failed resend attempts', NOW())
                 ON CONFLICT (identifier_hash, action_type) DO UPDATE SET
                   expires_at = NOW() + INTERVAL '1 second' * ?,
                   reason = 'Failed resend attempts',
                   updated_at = NOW()"
            );
            $stmt->execute([$ip, $identifierHash, $durationSeconds, $durationSeconds]);

            // Also store in Redis L1 for fast lookups
            $redis = $this->getRedisConnection();

            if ($redis) {
                $blockKey = 'resend_ip_block:' . hash('sha256', $ip);
                $this->safeRedisCall($redis, 'setex', [$blockKey, $durationSeconds, '1']);
            }

            Logger::security('critical', 'SECURITY: IP blocked for resend attempts', [
                'ip_hash' => hash('sha256', $ip),
                'duration_hours' => $durationSeconds / 3600,
                'redis_cached' => $redis !== null,
            ]);

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: Failed to block IP for resend', [
                'ip_hash' => hash('sha256', $ip),
                'duration_seconds' => $durationSeconds,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE SECURITY: Reset failed resend attempts for valid email
     * Called when user provides a valid unverified email
     */
    private function resetFailedResendAttempts(string $ip): void
    {
        try {
            // Clear Redis block
            $redis = $this->getRedisConnection();

            if ($redis) {
                $blockKey = 'resend_ip_block:' . hash('sha256', $ip);
                $this->safeRedisCall($redis, 'del', [$blockKey]);
            }

            // Remove from database bans
            // ENTERPRISE FIX: Use action_type (not ban_type)
            $stmt = db_pdo()->prepare(
                "DELETE FROM user_rate_limit_bans
                 WHERE ip_address = ? AND action_type = 'resend_email'"
            );
            $stmt->execute([$ip]);

            // Clean old failed attempts (keep recent ones for analytics)
            $stmt = db_pdo()->prepare(
                "DELETE FROM user_rate_limit_log
                 WHERE ip_address = ?
                   AND action_type = 'resend_failed'
                   AND created_at < NOW() - INTERVAL '1 hours'"
            );
            $stmt->execute([$ip]);

            // Attempts reset - IP unblocked for valid email

        } catch (\Exception $e) {
            Logger::security('error', 'SECURITY: Failed to reset resend attempts', [
                'ip_hash' => hash('sha256', $ip),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE METRICS: Raccoglie dati di sistema per email_verification_metrics
     */
    private function getSystemMetricsData(float $queueStartTime): array
    {
        $queueTimeMs = (int) round((microtime(true) - $queueStartTime) * 1000);

        // Redis L1 status check
        $redis = $this->getRedisConnection();
        $redisL1Status = 'inactive';

        if ($redis) {
            try {
                $redis->ping();
                $redisL1Status = 'active';
            } catch (\Exception $e) {
                $redisL1Status = 'error';
            }
        }

        // Database pool ID (based on EnterpriseSecureDatabasePool)
        $databasePoolId = 'enterprise_pool_' . gethostname() . '_' . getmypid();

        // Server load average (Unix systems)
        $serverLoadAvg = 0.0;

        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $serverLoadAvg = $load[0] ?? 0.0; // 1-minute load average
        }

        // Memory usage
        $memoryUsageMb = (int) round(memory_get_usage(true) / 1024 / 1024);

        return [
            'queue_time_ms' => $queueTimeMs,
            'redis_l1_status' => $redisL1Status,
            'database_pool_id' => $databasePoolId,
            'server_load_avg' => $serverLoadAvg,
            'memory_usage_mb' => $memoryUsageMb,
        ];
    }

    // REMOVED: Old insertEmailVerificationMetrics method replaced by EnterpriseEmailMetricsUnified

    /**
     * ENTERPRISE WORKER: Update metrics to 'processed_by_worker' status (STEP 1)
     * Called BEFORE sending email in worker context
     *
     * @param int $userId User ID
     * @param string $email User email
     * @param string $workerId Worker identifier
     * @param float $startTime Processing start time (microtime)
     * @param int|null $metricsRecordId Specific metrics record ID (ENTERPRISE: prevents race conditions)
     */
    private function updateMetricsToProcessing(int $userId, string $email, string $workerId, float $startTime, ?int $metricsRecordId = null): void
    {
        try {
            $pdo = db_pdo();

            // ENTERPRISE TIPS: Use specific record ID when available (race-condition-free for millions of concurrent users)
            if ($metricsRecordId) {
                // THREAD-SAFE: Query by specific record ID (O(1) lookup, no race conditions)
                $stmt = $pdo->prepare('
                    SELECT id, queue_time_ms FROM email_verification_metrics
                    WHERE id = ?
                    LIMIT 1
                ');
                $stmt->execute([$metricsRecordId]);
            } else {
                // LEGACY FALLBACK: Find the most recent 'queued_successfully' record for this user
                // WARNING: May have race conditions if user triggers multiple emails simultaneously
                $stmt = $pdo->prepare('
                    SELECT id, queue_time_ms FROM email_verification_metrics
                    WHERE user_id = ?
                    AND status = \'queued_successfully\'
                    ORDER BY created_at DESC
                    LIMIT 1
                ');
                $stmt->execute([$userId]);
            }

            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($record) {
                // ENTERPRISE TIPS: queue_time_ms contains microtime timestamp
                // DO NOT OVERWRITE IT HERE - AsyncEmailQueue will read it and calculate final queue time
                $queuedAtMicrotime = (float) $record['queue_time_ms'];
                $queueTimeMs = round(($startTime - $queuedAtMicrotime) * 1000, 2);

                // CRITICAL: Do NOT overwrite queue_time_ms here!
                // AsyncEmailQueue needs the original timestamp to calculate the final queue time
                $updateStmt = $pdo->prepare('
                    UPDATE email_verification_metrics
                    SET status = \'processed_by_worker\',
                        worker_id = ?
                    WHERE id = ?
                ');

                $updateStmt->execute([$workerId, $record['id']]);

                // Verify queue_time_ms is still intact after UPDATE
                $verifyStmt = $pdo->prepare('SELECT queue_time_ms, status FROM email_verification_metrics WHERE id = ?');
                $verifyStmt->execute([$record['id']]);
                $afterUpdate = $verifyStmt->fetch(\PDO::FETCH_ASSOC);

                // Verification completed - queue_time_ms preserved for AsyncEmailQueue
            } else {
                Logger::database('warning', 'DATABASE: No queued record found for metrics update', [
                    'user_id' => $userId,
                    'email_hash' => hash('sha256', $email),
                    'worker_id' => $workerId,
                ]);
            }
        } catch (\Exception $e) {
            Logger::database('error', 'DATABASE: Failed to update metrics to processing', [
                'user_id' => $userId,
                'email_hash' => hash('sha256', $email),
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE WORKER: Update metrics to 'sent_successfully' status (STEP 3)
     * Called AFTER successful email sending in worker context
     */
    private function updateMetricsToSuccess(int $userId, string $email, string $workerId, float $processingTimeMs, ?int $metricsRecordId = null): void
    {
        try {
            $pdo = db_pdo();

            // ENTERPRISE TIPS: Use specific record ID when available (thread-safe)
            if ($metricsRecordId) {
                $stmt = $pdo->prepare('
                    SELECT id FROM email_verification_metrics
                    WHERE id = ?
                    LIMIT 1
                ');
                $stmt->execute([$metricsRecordId]);
            } else {
                // LEGACY FALLBACK: Find the most recent 'processed_by_worker' record for this user
                $stmt = $pdo->prepare('
                    SELECT id FROM email_verification_metrics
                    WHERE user_id = ?
                    AND status = \'processed_by_worker\'
                    AND processing_time_ms IS NULL
                    ORDER BY created_at DESC
                    LIMIT 1
                ');
                $stmt->execute([$userId]);
            }
            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($record) {
                // Read queue_time_ms BEFORE update
                $preUpdateStmt = $pdo->prepare('SELECT queue_time_ms, status FROM email_verification_metrics WHERE id = ?');
                $preUpdateStmt->execute([$record['id']]);
                $preUpdate = $preUpdateStmt->fetch(\PDO::FETCH_ASSOC);

                $updateStmt = $pdo->prepare('
                    UPDATE email_verification_metrics
                    SET status = \'sent_successfully\',
                        processing_time_ms = ?
                    WHERE id = ?
                ');

                $updateStmt->execute([$processingTimeMs, $record['id']]);

                // Verify queue_time_ms AFTER update
                $postUpdateStmt = $pdo->prepare('SELECT queue_time_ms, status, processing_time_ms FROM email_verification_metrics WHERE id = ?');
                $postUpdateStmt->execute([$record['id']]);
                $postUpdate = $postUpdateStmt->fetch(\PDO::FETCH_ASSOC);

                // ENTERPRISE TIPS: Do NOT call recordEmailVerificationEvent here!
                // We already updated the record directly above.
                // Calling it would create a NEW record because status is sent_successfully (completed).
            } else {
                Logger::database('warning', 'DATABASE: No processed_by_worker record found for success update', [
                    'user_id' => $userId,
                    'email_hash' => hash('sha256', $email),
                    'worker_id' => $workerId,
                ]);
            }
        } catch (\Exception $e) {
            Logger::database('error', 'DATABASE: Failed to update metrics to success', [
                'user_id' => $userId,
                'email_hash' => hash('sha256', $email),
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE WORKER: Update metrics to 'send_failed' status (STEP 3 - failure case)
     * Called AFTER failed email sending in worker context
     */
    private function updateMetricsToFailed(int $userId, string $email, string $workerId, float $processingTimeMs, ?int $metricsRecordId = null): void
    {
        try {
            $pdo = db_pdo();

            // ENTERPRISE TIPS: Use specific record ID when available (thread-safe)
            if ($metricsRecordId) {
                $stmt = $pdo->prepare('
                    SELECT id FROM email_verification_metrics
                    WHERE id = ?
                    LIMIT 1
                ');
                $stmt->execute([$metricsRecordId]);
            } else {
                // LEGACY FALLBACK: Find the most recent 'processed_by_worker' record for this user
                $stmt = $pdo->prepare('
                    SELECT id FROM email_verification_metrics
                    WHERE user_id = ?
                    AND status = \'processed_by_worker\'
                    AND processing_time_ms IS NULL
                    ORDER BY created_at DESC
                    LIMIT 1
                ');
                $stmt->execute([$userId]);
            }
            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($record) {
                // ENTERPRISE: Use standardized error code
                $errorCode = EmailErrorCodeRegistry::EMAIL_SEND_FAILED;

                $updateStmt = $pdo->prepare('
                    UPDATE email_verification_metrics
                    SET status = \'send_failed\',
                        processing_time_ms = ?,
                        error_code = ?,
                        error_message = ?
                    WHERE id = ?
                ');

                $updateStmt->execute([
                    $processingTimeMs,
                    $errorCode,
                    EmailErrorCodeRegistry::getDescription($errorCode),
                    $record['id'],
                ]);

                // ENTERPRISE TIPS: Do NOT call recordEmailVerificationEvent here!
                // We already updated the record directly above.
                // Calling it would create a NEW record because status is send_failed (completed).
            } else {
                Logger::database('warning', 'DATABASE: No processed_by_worker record found for failure update', [
                    'user_id' => $userId,
                    'email_hash' => hash('sha256', $email),
                    'worker_id' => $workerId,
                ]);
            }
        } catch (\Exception $e) {
            Logger::database('error', 'DATABASE: Failed to update metrics to failed', [
                'user_id' => $userId,
                'email_hash' => hash('sha256', $email),
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
