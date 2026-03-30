<?php

namespace Need2Talk\Services;

use Exception;
use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Core\EnterpriseSecurityFunctions;

/**
 * Admin Security Service - Enterprise 2FA System
 *
 * SISTEMA SICUREZZA MASSIMA:
 * 1. URL criptato per admin access
 * 2. 2FA con email + password + codice temporaneo
 * 3. Rate limiting specifico per admin
 * 4. Audit log completo di ogni azione admin
 * 5. Session timeout ridotto per admin
 */
class AdminSecurityService
{
    // ENTERPRISE GALAXY: Admin security configuration
    private const ADMIN_SESSION_TIMEOUT = 3600; // 60 minutes (1 hour) - Security-first approach
    private const ADMIN_SESSION_EXTENSION_THRESHOLD = 300; // 5 minutes - Extension window at session end
    private const ADMIN_URL_TIMEOUT = 28800; // 8 hours - URL remains valid longer than session
    private const CODE_EXPIRY_MINUTES = 5;
    private const MAX_2FA_ATTEMPTS = 3;

    // ENTERPRISE GALAXY: Activity-based auto-extension
    // If user is active in last 5 minutes of session, extend by another 60 minutes
    private const ENABLE_SMART_SESSION_EXTENSION = true;

    private $logger;

    public function __construct()
    {
        $this->logger = new Logger();
    }

    /**
     * ENTERPRISE: Get fresh connection for each query (no connection leaks)
     * Returns TrackedPDO which extends PDO
     */
    private function getDb()
    {
        return \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();
    }

    /**
     * ENTERPRISE: Release connection back to pool
     * Accepts any PDO-compatible object (TrackedPDO, AutoReleasePDO, PDO)
     */
    private function releaseDb($pdo): void
    {
        \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($pdo);
    }

    /**
     * Get base URL from environment configuration
     */
    private static function getBaseUrl(): string
    {
        // Get APP_URL from environment
        $appUrl = $_ENV['APP_URL'] ?? null;

        if ($appUrl) {
            return rtrim($appUrl, '/');
        }

        // Fallback: auto-detect from server variables
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';

            return $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? env('SERVER_IP', 'YOUR_SERVER_IP'));
        }

        // Final fallback
        return $_ENV['APP_URL'] ?? 'http://YOUR_SERVER_IP';
    }

    /**
     * Genera URL admin criptato univoco - ENTERPRISE ANTI-PREDICTION
     *
     * @param bool $fullUrl Se true, restituisce l'URL completo (es. https://need2talk.test/admin_abc...),
     *                      altrimenti solo il path (es. /admin_abc...)
     */
    public static function generateSecureAdminUrl(bool $fullUrl = false): string
    {
        $currentTime = time();
        $timeWindow = floor($currentTime / 3600) * 3600; // 60-minute windows

        // ENTERPRISE SECURITY: Add server boot time to prevent future URL prediction
        $serverBootTime = self::getServerBootTime();

        $payload = [
            'env' => $_ENV['APP_ENV'] ?? 'production',
            'time_window' => $timeWindow,
            'signature' => 'need2talk_admin_2024',
            'server_boot' => $serverBootTime, // CRITICAL: Makes URLs unpredictable
            'process_id' => getmypid(), // Additional entropy
        ];

        $encoded = base64_encode(json_encode($payload));
        $hash = hash('sha256', $encoded . ($_ENV['APP_SECRET'] ?? 'default_secret'));

        $generatedUrl = '/admin_' . substr($hash, 0, 16);

        // ENTERPRISE GALAXY: Store valid URL in whitelist with 8-hour expiration (outlives session)
        // URL remains valid for multiple 60-minute sessions within the 8-hour window
        self::whitelistUrl(substr($generatedUrl, 7), time() + self::ADMIN_URL_TIMEOUT);

        // Return full URL or just path based on parameter
        if ($fullUrl) {
            return self::getBaseUrl() . $generatedUrl;
        }

        return $generatedUrl;
    }

    /**
     * Verifica URL admin valido - ENTERPRISE UNIFIED SECURITY
     * PERFORMANCE: Static cache to avoid duplicate queries within same request
     */
    public static function validateAdminUrl(string $url): bool
    {
        // ENTERPRISE TIPS: Static cache per request to prevent duplicate queries
        static $validationCache = [];

        // Return cached result if already validated in this request
        if (isset($validationCache[$url])) {
            return $validationCache[$url];
        }

        // Accept both base admin URL and admin paths (login, 2fa, etc.)
        if (!preg_match('/^\/admin_([a-f0-9]{16})(?:\/(.+))?$/', $url, $matches)) {
            $validationCache[$url] = false;

            return false;
        }

        $urlHash = $matches[1];

        try {
            // ENTERPRISE: Use pool directly (db_pdo() not yet defined during bootstrap)
            $pool = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance();
            $db = $pool->getConnection();

            try {
                // ENTERPRISE: Check whitelist (authorization)
                $stmt = $db->prepare('SELECT url_hash, expires_at, source FROM admin_url_whitelist WHERE url_hash = ? AND expires_at > NOW()');
                $stmt->execute([$urlHash]);
                $whitelistEntry = $stmt->fetch();

                if ($whitelistEntry) {
                    // ENTERPRISE: Cleanup expired entries periodically
                    self::cleanupExpiredWhitelist();

                    $validationCache[$url] = true;

                    return true;
                }

                // ENTERPRISE STEP 3: Check if it's a valid emergency URL from admin_url_changes
                $stmt = $db->prepare('SELECT url_hash, expires_at FROM admin_url_changes WHERE url_hash = ? AND expires_at > NOW()');
                $stmt->execute([$urlHash]);
                $emergencyEntry = $stmt->fetch();

                if ($emergencyEntry) {
                    // ENTERPRISE: Auto-sync emergency URL to whitelist
                    self::syncEmergencyToWhitelist($urlHash, $emergencyEntry['expires_at']);

                    $validationCache[$url] = true;

                    return true;
                }

                // ENTERPRISE SECURITY: Invalid URL - blocked
                if (class_exists('\Need2Talk\Services\Logger')) {
                    Logger::security('warning', 'Invalid admin URL blocked', [
                        'url_hash' => $urlHash,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    ]);
                }

                $validationCache[$url] = false;

                return false;

            } finally {
                // PERFORMANCE: Always release connection back to pool
                $pool->releaseConnection($db);
            }

        } catch (Exception $e) {
            if (class_exists('\Need2Talk\Services\Logger')) {
                Logger::error('Admin URL validation failed', [
                    'error' => $e->getMessage(),
                    'url' => $url ?? 'unknown',
                ]);
            }

            $validationCache[$url] = false;

            return false;
        }
    }

    // REMOVED: blacklistUrl function - no longer needed with direct 403 blocking

    /**
     * STEP 1: Admin login con email + password
     */
    public function initiateAdminLogin(string $email, string $password): array
    {
        $timestamp = microtime(true);

        // Rate limiting specifico admin
        $this->checkAdminRateLimit($email);

        // Verifica admin user
        $admin = $this->validateAdminCredentials($email, $password);

        if (!$admin) {
            $this->logFailedAdminAttempt($email, 'invalid_credentials');

            return ['success' => false, 'error' => 'Credenziali non valide'];
        }

        // Log successful login attempt
        $this->logSuccessfulAdminAttempt($email, 'credentials_valid');

        // Genera e invia codice 2FA
        $code = $this->generate2FACode();
        $this->store2FACode($admin['id'], $code);

        $this->send2FACodeEmail($admin['email'], $code);

        // Store temporary session
        $tempToken = bin2hex(EnterpriseSecurityFunctions::randomBytes(32));
        $this->storeTempAdminSession($admin['id'], $tempToken);

        $this->logAdminAction($admin['id'], 'login_initiated', [
            'ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        return [
            'success' => true,
            'temp_token' => $tempToken,
            'message' => 'Codice 2FA inviato via email',
        ];
    }

    /**
     * STEP 2: Verifica codice 2FA
     */
    public function verify2FACode(string $tempToken, string $code): array
    {
        $tempSession = $this->getTempAdminSession($tempToken);

        if (!$tempSession) {
            return ['success' => false, 'error' => 'Sessione scaduta'];
        }

        $storedCode = $this->get2FACode($tempSession['admin_id']);

        if ($storedCode) {
            $isValid = password_verify($code, $storedCode['code']);
        }

        if (!$storedCode || !password_verify($code, $storedCode['code'])) {
            $this->increment2FAAttempts($tempSession['admin_id']);

            return ['success' => false, 'error' => 'Codice non valido'];
        }

        // Codice valido - crea sessione admin completa
        $sessionToken = $this->createAdminSession($tempSession['admin_id']);

        // Cleanup
        $this->clearTempSession($tempToken);
        $this->clear2FACode($tempSession['admin_id']);

        $this->logAdminAction($tempSession['admin_id'], 'login_completed', [
            'ip' => $this->getClientIp(),
            'session_token' => substr($sessionToken, 0, 8) . '...',
        ]);

        return [
            'success' => true,
            'session_token' => $sessionToken,
            'redirect' => $this->generateSecureAdminUrl() . '/dashboard',
        ];
    }

    /**
     * ENTERPRISE Verifica sessione admin valida - Optimized for millions of requests/hour
     */
    public function validateAdminSession(string $sessionToken): ?array
    {
        $hashedToken = hash('sha256', $sessionToken);

        $db = $this->getDb();
        try {
            // ENTERPRISE GALAXY: Optimized query with index hints and minimal data transfer
            // Includes last_activity for smart session extension logic
            $stmt = $db->prepare("
                SELECT
                    s.admin_id,
                    s.created_at,
                    s.expires_at,
                    s.last_activity,
                    a.email,
                    a.role,
                    a.full_name,
                    a.status
                FROM admin_sessions s
                INNER JOIN admin_users a ON s.admin_id = a.id
                WHERE s.session_token = ?
                AND s.expires_at > NOW()
                AND a.status = 'active'
                AND a.deleted_at IS NULL
                LIMIT 1
            ");

            $stmt->execute([$hashedToken]);
            $session = $stmt->fetch();

            if ($session) {
                // ENTERPRISE GALAXY: Smart session extension based on activity
                // Only extend if user is active in the final 5 minutes of session
                $timeToExpiry = strtotime($session['expires_at']) - time();
                $lastActivity = strtotime($session['last_activity'] ?? $session['created_at']);
                $timeSinceActivity = time() - $lastActivity;

                if (self::ENABLE_SMART_SESSION_EXTENSION &&
                    $timeToExpiry <= self::ADMIN_SESSION_EXTENSION_THRESHOLD && // Last 5 minutes
                    $timeSinceActivity <= self::ADMIN_SESSION_EXTENSION_THRESHOLD) { // Active in last 5 minutes

                    // ENTERPRISE: Extend by another 60 minutes (automatic activity-based extension)
                    $this->extendAdminSession($hashedToken);

                    Logger::security('info', 'ADMIN: Session auto-extended (activity detected)', [
                        'admin_id' => $session['admin_id'],
                        'time_remaining' => $timeToExpiry,
                        'time_since_activity' => $timeSinceActivity,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]);
                }

                return $session;
            }

            return null;
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Log azione admin per audit
     */
    public function logAdminAction(int $adminId, string $action, array $details = []): void
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('
                INSERT INTO admin_audit_log (admin_id, action, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $adminId,
                $action,
                json_encode($details),
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * ENTERPRISE GALAXY: Logout admin with URL invalidation
     *
     * When admin session is invalidated, the associated admin URL is also invalidated
     * This ensures that if session expires, the URL cannot be reused without re-authentication
     *
     * @param string $sessionToken Raw session token (not hashed)
     * @return void
     */
    public function logoutAdmin(string $sessionToken): void
    {
        $hashedToken = hash('sha256', $sessionToken);

        $db = $this->getDb();
        try {
            // ENTERPRISE GALAXY: Transaction for atomic session + URL invalidation
            $db->beginTransaction();

            try {
                // STEP 1: Invalidate admin session
                $stmt = $db->prepare('
                    UPDATE admin_sessions
                    SET expires_at = NOW()
                    WHERE session_token = ?
                ');
                $stmt->execute([$hashedToken]);

                // STEP 2: Get current admin URL from session context
                // Extract admin URL from current request or session storage
                $currentAdminUrl = null;
                if (isset($_SERVER['REQUEST_URI'])) {
                    if (preg_match('/\/admin_([a-f0-9]{16})/', $_SERVER['REQUEST_URI'], $matches)) {
                        $currentAdminUrl = $matches[1]; // Just the hash part
                    }
                }

                // ENTERPRISE GALAXY: Invalidate admin URL from whitelist
                // This prevents reuse of the URL even if it hasn't expired yet
                if ($currentAdminUrl) {
                    $stmt = $db->prepare('
                        UPDATE admin_url_whitelist
                        SET expires_at = NOW()
                        WHERE url_hash = ? AND expires_at > NOW()
                    ');
                    $stmt->execute([$currentAdminUrl]);

                    Logger::security('info', 'ADMIN: URL invalidated on logout', [
                        'url_hash' => $currentAdminUrl,
                        'reason' => 'session_logout',
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]);
                }

                $db->commit();

                // ENTERPRISE GALAXY: Trigger email notification of URL change
                // Admin will receive new URL via email for next login
                $this->scheduleUrlChangeNotification();

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * ENTERPRISE GALAXY: Schedule URL change notification
     * Sends email to admin@need2talk.it with new admin URL
     *
     * @return void
     */
    private function scheduleUrlChangeNotification(): void
    {
        try {
            // ENTERPRISE: Generate new URL and notify admin
            \Need2Talk\Services\AdminUrlNotificationService::notifyUrlChange();
        } catch (Exception $e) {
            // URL notification failure is not critical - admin can request emergency access
            Logger::email('warning', 'ADMIN: Failed to send URL change notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get server boot time for URL unpredictability
     */
    private static function getServerBootTime(): int
    {
        static $bootTime = null;

        if ($bootTime === null) {
            // Try to get server boot time (works on most systems)
            if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')), true)) {
                exec('uptime -s 2>/dev/null', $output);

                if (!empty($output[0])) {
                    $bootTime = strtotime($output[0]);
                }
            }

            // Fallback: use a file-based boot time
            if (!$bootTime) {
                $bootFile = APP_ROOT . '/storage/.server_boot';

                if (!file_exists($bootFile)) {
                    file_put_contents($bootFile, time());
                }
                $bootTime = (int) file_get_contents($bootFile);
            }
        }

        return $bootTime;
    }

    /**
     * GALAXY ENTERPRISE: Get direct lightweight PostgreSQL connection for critical security operations
     *
     * Why bypass the pool?
     * ✅ Works in ALL contexts (web, CLI, early bootstrap)
     * ✅ Zero dependencies on Redis/pool initialization
     * ✅ Used only for rare critical operations (URL generation)
     * ✅ Separate security-critical path from performance-critical pool
     */
    private static function getDirectConnection(): \PDO
    {
        static $directConnection = null;

        if ($directConnection === null) {
            $host = $_ENV['DB_HOST'] ?? 'postgres';
            $port = $_ENV['DB_PORT'] ?? '5432';
            $dbname = $_ENV['DB_NAME'] ?? 'need2talk';
            $username = $_ENV['DB_USER'] ?? 'root';
            $password = $_ENV['DB_PASSWORD'] ?? 'root';

            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ];

            $directConnection = new \PDO($dsn, $username, $password, $options);

            // GALAXY: PostgreSQL container timezone is set via TZ=Europe/Rome in docker-compose.yml
            // No need to hardcode timezone offset - it auto-adjusts for DST (ora legale/solare)
        }

        return $directConnection;
    }

    /**
     * ENTERPRISE: Whitelist valid URLs - GALAXY LEVEL with direct connection
     */
    private static function whitelistUrl(string $urlHash, int $expiresAt): void
    {
        try {
            // GALAXY: Use direct lightweight connection for critical security
            $db = self::getDirectConnection();

            // GALAXY: Use PostgreSQL NOW() + INTERVAL instead of PHP date() to respect PostgreSQL session timezone
            $secondsFromNow = $expiresAt - time();
            $stmt = $db->prepare('INSERT INTO admin_url_whitelist (url_hash, expires_at, created_at) VALUES (?, NOW() + make_interval(secs => ?), NOW()) ON CONFLICT (url_hash) DO NOTHING');
            $stmt->execute([$urlHash, $secondsFromNow]);
        } catch (Exception $e) {
            if (class_exists('\Need2Talk\Services\Logger')) {
                Logger::error('Failed to whitelist admin URL', [
                    'error' => $e->getMessage(),
                    'url_hash' => $urlHash,
                ]);
            }
        }
    }

    /**
     * ENTERPRISE: Sync emergency URL to whitelist - GALAXY LEVEL with direct connection
     */
    private static function syncEmergencyToWhitelist(string $urlHash, string $expiresAt): void
    {
        try {
            // GALAXY: Use direct lightweight connection for critical security
            $db = self::getDirectConnection();
            $stmt = $db->prepare("INSERT INTO admin_url_whitelist (url_hash, expires_at, source) VALUES (?, ?, 'emergency') ON CONFLICT (url_hash) DO NOTHING");
            $stmt->execute([$urlHash, $expiresAt]);
        } catch (Exception $e) {
            if (class_exists('\Need2Talk\Services\Logger')) {
                Logger::error('Failed to sync emergency URL', [
                    'error' => $e->getMessage(),
                    'url_hash' => $urlHash,
                ]);
            }
        }
    }

    /**
     * ENTERPRISE: Cleanup expired whitelist entries - GALAXY LEVEL with direct connection
     */
    private static function cleanupExpiredWhitelist(): void
    {
        static $lastCleanup = 0;
        $now = time();

        // Cleanup every 5 minutes
        if ($now - $lastCleanup < 300) {
            return;
        }

        try {
            // GALAXY: Use direct lightweight connection for critical security
            $db = self::getDirectConnection();
            $stmt = $db->prepare('DELETE FROM admin_url_whitelist WHERE expires_at < NOW()');
            $stmt->execute();

            $lastCleanup = $now;
        } catch (Exception $e) {
            if (class_exists('\Need2Talk\Services\Logger')) {
                Logger::database('warning', 'Admin whitelist cleanup failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Verifica admin credentials con tabella dedicata admin_users
     */
    private function validateAdminCredentials(string $email, string $password): ?array
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare("
                SELECT
                    id,
                    email,
                    password_hash,
                    role,
                    status,
                    last_login_at,
                    full_name,
                    mfa_enabled,
                    ip_whitelist,
                    failed_login_attempts,
                    locked_until
                FROM admin_users
                WHERE email = ?
                AND status = 'active'
                AND deleted_at IS NULL
            ");

            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            if (!$admin) {
                return null;
            }

            // Check if account is locked
            if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
                throw new Exception('Account temporaneamente bloccato per sicurezza');
            }

            // Verify password
            if (!password_verify($password, $admin['password_hash'])) {
                // Increment failed attempts
                $this->incrementFailedLoginAttempts($admin['id']);

                return null;
            }

            // Check IP whitelist if configured
            if ($admin['ip_whitelist'] && !$this->checkIpWhitelist($admin['ip_whitelist'])) {
                throw new Exception('Accesso negato: IP non autorizzato');
            }

            // Reset failed attempts on successful login
            $this->resetFailedLoginAttempts($admin['id']);

            return $admin;
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Genera codice 2FA sicuro
     */
    private function generate2FACode(): string
    {
        return str_pad((string) EnterpriseSecurityFunctions::randomInt(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Memorizza codice 2FA
     */
    private function store2FACode(int $adminId, string $code): void
    {
        $db = $this->getDb();
        try {
            // ENTERPRISE: Use PostgreSQL DATE_ADD to avoid timezone issues
            $stmt = $db->prepare('
                INSERT INTO admin_2fa_codes (admin_id, code, expires_at)
                VALUES (?, ?, NOW() + make_interval(mins => ?))
                ON CONFLICT (admin_id) DO UPDATE SET
                code = EXCLUDED.code,
                expires_at = EXCLUDED.expires_at,
                attempts = 0,
                created_at = NOW()
            ');

            $stmt->execute([$adminId, password_hash($code, PASSWORD_DEFAULT), self::CODE_EXPIRY_MINUTES]);
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Invia codice 2FA via email usando EnterpriseQueueDispatcher
     */
    private function send2FACodeEmail(string $email, string $code): void
    {
        // ENTERPRISE: Use EnterpriseQueueDispatcher for admin 2FA emails
        try {
            $dispatcher = new \Need2Talk\Services\EnterpriseQueueDispatcher();

            // Get actual AdminUser object from database
            $db = $this->getDb();
            try {
                $stmt = $db->prepare("SELECT * FROM admin_users WHERE email = ? AND status = 'active'");
                $stmt->execute([$email]);
                $adminData = $stmt->fetch();
            } finally {
                $this->releaseDb($db);
            }

            if (!$adminData) {
                throw new Exception('Admin user not found for queue dispatch');
            }

            // Create simple object for queue (Lightning Framework style)
            $adminUser = (object) [
                'id' => $adminData['id'],
                'email' => $adminData['email'],
                'name' => $adminData['full_name'] ?? 'Admin',
                'role' => $adminData['role'],
            ];

            $success = $dispatcher->dispatch2FAEmail(
                $adminUser,
                $code,
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );

            if ($success) {
                // Success - dispatcher handled it
            } else {
                throw new Exception('EnterpriseQueueDispatcher returned false');
            }

        } catch (Exception $e) {
            Logger::email('error', 'ADMIN EMAIL: 2FA dispatch failed', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'fallback' => 'direct_email',
            ]);

            // ENTERPRISE FALLBACK: Direct EmailService
            $this->sendDirectAdminEmail($email, $code);
        }
    }

    /**
     * Fallback: Send admin 2FA email directly
     */
    private function sendDirectAdminEmail(string $email, string $code): void
    {
        $domain = $_ENV['APP_URL'] ? parse_url($_ENV['APP_URL'], PHP_URL_HOST) : env('SERVER_IP', 'YOUR_SERVER_IP');
        $environment = $_ENV['APP_ENV'] ?? 'development';
        $envLabel = $environment === 'production' ? '' : ' [' . strtoupper($environment) . ']';

        $subject = '🔐 need2talk Admin' . $envLabel . ' - Codice 2FA (FALLBACK)';

        $message = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #8B5CF6, #3B82F6); padding: 30px; text-align: center; color: white; border-radius: 12px 12px 0 0;'>
                    <h1 style='margin: 0; font-size: 28px;'>🔐 need2talk Admin</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Autenticazione a Due Fattori</p>
                </div>

                <div style='background: #ffffff; padding: 40px; border-radius: 0 0 12px 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
                    <p style='font-size: 16px; color: #374151; margin-bottom: 20px;'>
                        Ciao <strong>Admin</strong>,
                    </p>

                    <p style='color: #6B7280; margin-bottom: 25px;'>
                        È stato richiesto l'accesso al pannello admin. Inserisci il codice seguente per completare l'autenticazione.
                    </p>

                    <div style='background: #F8FAFC; border: 2px solid #E5E7EB; border-radius: 8px; padding: 30px; margin: 25px 0; text-align: center;'>
                        <h3 style='color: #374151; margin: 0 0 20px 0; font-size: 18px;'>🔑 Il tuo codice 2FA:</h3>
                        <div style='background: linear-gradient(135deg, #8B5CF6, #3B82F6); color: white; padding: 20px; border-radius: 8px; font-family: monospace; font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center; margin: 0 auto; max-width: 300px;'>
                            {$code}
                        </div>
                    </div>

                    <div style='background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 15px; margin: 25px 0; border-radius: 0 6px 6px 0;'>
                        <h4 style='color: #92400E; margin: 0 0 10px 0;'>⚠️ Importante:</h4>
                        <ul style='color: #92400E; margin: 0; padding-left: 20px;'>
                            <li>Questo codice scade in <strong>" . self::CODE_EXPIRY_MINUTES . " minuti</strong></li>
                            <li>Utilizzabile una sola volta</li>
                            <li>Se non hai richiesto l'accesso, ignora questa email</li>
                        </ul>
                    </div>

                    <div style='background: #FEE2E2; border: 1px solid #FECACA; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                        <h4 style='color: #DC2626; margin: 0 0 15px 0;'>🔄 Sistema Fallback Attivato</h4>
                        <p style='color: #7F1D1D; margin: 0; font-size: 14px;'>
                            <strong>Questa email è stata inviata tramite sistema di fallback.</strong><br>
                            Il sistema di coda principale potrebbe essere temporaneamente non disponibile.
                            Il codice funziona normalmente.
                        </p>
                    </div>

                    <div style='background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                        <h4 style='color: #166534; margin: 0 0 15px 0;'>🛡️ Dettagli Accesso:</h4>
                        <table style='width: 100%; font-family: monospace; font-size: 14px;'>
                            <tr>
                                <td style='padding: 4px 0; color: #6B7280; width: 30%;'><strong>Timestamp:</strong></td>
                                <td style='padding: 4px 0; color: #1F2937;'>" . date('d/m/Y H:i:s') . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 4px 0; color: #6B7280;"><strong>Indirizzo IP:</strong></td>
                                <td style="padding: 4px 0; color: #1F2937; word-break: break-all;">' . $this->getClientIp() . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 4px 0; color: #6B7280;"><strong>Sistema:</strong></td>
                                <td style="padding: 4px 0; color: #1F2937;">' . $domain . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 4px 0; color: #6B7280;"><strong>Metodo:</strong></td>
                                <td style="padding: 4px 0; color: #DC2626;"><strong>Direct Fallback</strong></td>
                            </tr>
                        </table>
                    </div>

                    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB;">
                        <p style="color: #9CA3AF; font-size: 12px; margin: 0;">
                            Generato automaticamente il ' . date('d/m/Y H:i:s') . '<br>
                            need2talk Enterprise Security System (Fallback Mode)
                        </p>
                    </div>
                </div>
            </div>
        ';

        $emailService = new \Need2Talk\Services\EmailService();
        $emailService->send($email, $subject, $message);
    }

    /**
     * Crea sessione admin completa
     */
    private function createAdminSession(int $adminId): string
    {
        $sessionToken = bin2hex(EnterpriseSecurityFunctions::randomBytes(64));
        $hashedToken = hash('sha256', $sessionToken);

        $db = $this->getDb();
        try {
            // ENTERPRISE: Use PostgreSQL DATE_ADD for timezone safety
            $stmt = $db->prepare('
                INSERT INTO admin_sessions (admin_id, session_token, expires_at, ip_address, user_agent, last_activity)
                VALUES (?, ?, NOW() + make_interval(secs => ?), ?, ?, NOW())
            ');

            $stmt->execute([
                $adminId,
                $hashedToken,
                self::ADMIN_SESSION_TIMEOUT,
                $this->getClientIp(),
                EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', 'Unknown'),
            ]);

            return $sessionToken;
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Estendi sessione admin
     */
    /**
     * ENTERPRISE GALAXY: Extend admin session by ADMIN_SESSION_TIMEOUT (60 minutes)
     * Called automatically when activity detected in final 5 minutes of session
     *
     * @param string $hashedToken SHA256 hashed session token
     * @return void
     */
    private function extendAdminSession(string $hashedToken): void
    {
        // ENTERPRISE: Extend by 60 minutes from NOW (not from old expiry)
        $newExpiry = date('Y-m-d H:i:s', time() + self::ADMIN_SESSION_TIMEOUT);

        $db = $this->getDb();
        try {
            $stmt = $db->prepare('
                UPDATE admin_sessions
                SET expires_at = ?, last_activity = NOW()
                WHERE session_token = ?
            ');

            $stmt->execute([$newExpiry, $hashedToken]);
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * ENTERPRISE Rate limiting per admin login - Optimized for 100k+ concurrent users
     */
    private function checkAdminRateLimit(string $email): void
    {
        $db = $this->getDb();
        try {
            // ENTERPRISE: Use optimized query with index hint and LIMIT for performance
            $stmt = $db->prepare('
                SELECT COUNT(*)
                FROM admin_login_attempts
                WHERE email = ?
                AND created_at > NOW() - INTERVAL \'15 minutes\'
                LIMIT 10
            ');

            $stmt->execute([$email]);
            $attempts = (int) $stmt->fetchColumn();

            if ($attempts >= 5) {
                // ENTERPRISE: Log with structured data for monitoring systems
                Logger::security('warning', 'ADMIN SECURITY: Rate limit exceeded', [
                    'email' => $email,
                    'ip' => $this->getClientIp(),
                    'attempts' => $attempts,
                    'window' => '15_minutes',
                    'threshold' => 5,
                ]);

                http_response_code(429);

                throw new Exception('Troppi tentativi. Riprova tra 15 minuti.');
            }
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Log successful admin attempt
     */
    private function logSuccessfulAdminAttempt(string $email, string $reason): void
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('
                INSERT INTO admin_login_attempts (email, ip_address, user_agent, failure_reason)
                VALUES (?, ?, ?, ?)
            ');

            $stmt->execute([
                $email,
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $reason . '_SUCCESS', // Mark as success
            ]);
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Log fallito tentativo admin
     */
    private function logFailedAdminAttempt(string $email, string $reason): void
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('
                INSERT INTO admin_login_attempts (email, ip_address, user_agent, failure_reason)
                VALUES (?, ?, ?, ?)
            ');

            $stmt->execute([
                $email,
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $reason,
            ]);
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Get client IP (SECURE - trust only REMOTE_ADDR)
     *
     * SECURITY: Non fidarsi di header proxy (X-Forwarded-For, CF-Connecting-IP)
     * che possono essere spoofati. Nginx passa l'IP reale in REMOTE_ADDR.
     */
    private function getClientIp(): string
    {
        $ip = EnterpriseGlobalsManager::getServer('REMOTE_ADDR');

        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return 'unknown';
    }

    /**
     * Incrementa tentativi di login falliti
     */
    private function incrementFailedLoginAttempts(int $adminId): void
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('
                UPDATE admin_users
                SET failed_login_attempts = failed_login_attempts + 1,
                    locked_until = CASE
                        WHEN failed_login_attempts + 1 >= 5
                        THEN NOW() + INTERVAL \'30 minutes\'
                        ELSE locked_until
                    END
                WHERE id = ?
            ');

            $stmt->execute([$adminId]);
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Reset tentativi di login falliti
     */
    private function resetFailedLoginAttempts(int $adminId): void
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('
                UPDATE admin_users
                SET failed_login_attempts = 0,
                    locked_until = NULL,
                    last_login_ip = ?,
                    last_login_at = NOW(),
                    login_count = login_count + 1
                WHERE id = ?
            ');

            $stmt->execute([$this->getClientIp(), $adminId]);
        } finally {
            $this->releaseDb($db);
        }
    }

    /**
     * Verifica IP whitelist
     */
    private function checkIpWhitelist(string $whitelistJson): bool
    {
        $whitelist = json_decode($whitelistJson, true);

        if (!is_array($whitelist) || empty($whitelist)) {
            return true; // No whitelist configured
        }

        $clientIp = $this->getClientIp();

        foreach ($whitelist as $allowedIp) {
            // Support CIDR notation and exact match
            if ($this->ipInRange($clientIp, $allowedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se IP è in range (supporta CIDR)
     */
    private function ipInRange(string $ip, string $range): bool
    {
        if ($ip === $range) {
            return true;
        }

        // CIDR notation support
        if (strpos($range, '/') !== false) {
            [$subnet, $mask] = explode('/', $range);

            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int) $mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        return false;
    }

    // Helper methods for temp sessions and 2FA codes
    private function storeTempAdminSession(int $adminId, string $tempToken): void
    {
        $db = $this->getDb();
        try {
            // ENTERPRISE: Enhanced temp session storage with complete audit data
            $stmt = $db->prepare('
                INSERT INTO admin_temp_sessions (admin_id, temp_token, expires_at, ip_address, user_agent)
                VALUES (?, ?, NOW() + INTERVAL \'10 minutes\', ?, ?)
            ');
            $stmt->execute([
                $adminId,
                hash('sha256', $tempToken),
                $this->getClientIp(),
                EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', 'Unknown'),
            ]);
        } finally {
            $this->releaseDb($db);
        }
    }

    private function getTempAdminSession(string $tempToken): ?array
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('
                SELECT admin_id FROM admin_temp_sessions
                WHERE temp_token = ? AND expires_at > NOW()
            ');
            $stmt->execute([hash('sha256', $tempToken)]);

            return $stmt->fetch() ?: null;
        } finally {
            $this->releaseDb($db);
        }
    }

    private function get2FACode(int $adminId): ?array
    {
        $db = $this->getDb();
        try {
            // ENTERPRISE: Optimized 2FA validation with composite index
            $stmt = $db->prepare('
                SELECT code, attempts, expires_at
                FROM admin_2fa_codes
                WHERE admin_id = ?
                AND expires_at > NOW()
                LIMIT 1
            ');
            $stmt->execute([$adminId]);

            return $stmt->fetch() ?: null;
        } finally {
            $this->releaseDb($db);
        }
    }

    private function increment2FAAttempts(int $adminId): void
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('
                UPDATE admin_2fa_codes
                SET attempts = attempts + 1
                WHERE admin_id = ?
            ');
            $stmt->execute([$adminId]);
        } finally {
            $this->releaseDb($db);
        }
    }

    private function clearTempSession(string $tempToken): void
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('DELETE FROM admin_temp_sessions WHERE temp_token = ?');
            $stmt->execute([hash('sha256', $tempToken)]);
        } finally {
            $this->releaseDb($db);
        }
    }

    private function clear2FACode(int $adminId): void
    {
        $db = $this->getDb();
        try {
            $stmt = $db->prepare('DELETE FROM admin_2fa_codes WHERE admin_id = ?');
            $stmt->execute([$adminId]);
        } finally {
            $this->releaseDb($db);
        }
    }
}
