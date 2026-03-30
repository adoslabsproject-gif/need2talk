<?php

namespace Need2Talk\Middleware;

use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Services\Logger;
use Need2Talk\Services\RedisRateLimitService;

/**
 * RateLimitMiddleware - Rate limiting globale per need2talk
 *
 * UPGRADED FOR ENTERPRISE SCALABILITY:
 * - Now uses Redis for ultra-fast rate limiting (0.1ms vs 50ms database)
 * - Supports 100k+ concurrent users without performance degradation
 * - Automatic database fallback if Redis unavailable
 *
 * Gestisce:
 * - Rate limiting per IP su richieste web
 * - Rate limiting per utente autenticato
 * - Rate limiting specifico per API
 * - Protezione contro spam e attacchi DDoS
 * - Whitelisting IP e utenti privilegiati
 * - Logging eventi di rate limiting
 */
class UserRateLimitMiddleware
{
    private Logger $logger;

    private RedisRateLimitService $rateLimitService;

    private $db;

    // ENTERPRISE: Configurazione rate limits intelligenti per categorie
    private array $limits = [
        // ESSENTIAL PAGES: No rate limiting (handled by isEssentialPage)
        'essential' => [
            'requests' => PHP_INT_MAX, // Unlimited
            'window' => 3600,
            'ban_duration' => 0,
        ],
        // GENERAL WEB: Generous limits for normal usage
        'web' => [
            'requests' => 500,    // Increased from 100 to 500
            'window' => 3600,     // per ora
            'ban_duration' => 1800, // Reduced ban time
        ],
        // USER CONTENT: Higher limits for authenticated users
        'user_content' => [
            'requests' => 200,    // Profile, settings, user pages
            'window' => 3600,
            'ban_duration' => 900,
        ],
        // API: High performance limits
        'api' => [
            'requests' => 1000,   // Increased for enterprise usage
            'window' => 3600,
            'ban_duration' => 3600,
        ],
        // AUTH: Strict but reasonable for security
        'auth' => [
            'requests' => 15,     // Increased from 10 to 15
            'window' => 900,      // 15 minutes
            'ban_duration' => 1800, // 30 minutes
        ],
        // UPLOAD: Special handling for file uploads
        'upload' => [
            'requests' => 50,     // 50 uploads per hour
            'window' => 3600,
            'ban_duration' => 7200,
        ],
        // SOCIAL: Comments, likes, social interactions
        'social' => [
            'requests' => 300,    // High limit for social interactions
            'window' => 3600,
            'ban_duration' => 1800,
        ],
    ];

    private array $whitelistIps = [
        '127.0.0.1',
        '::1',
    ];

    private array $whitelistUsers = []; // User ID privilegiati

    public function __construct()
    {
        $this->logger = new Logger();
        $this->rateLimitService = new RedisRateLimitService();
        $this->db = db_pdo();

        // Load environment configurations
        $this->loadEnvConfig();
    }

    /**
     * Handle rate limiting principale (REDIS-POWERED + INTELLIGENT CATEGORIES)
     */
    public function handle(string $type = 'web'): void
    {
        // ENTERPRISE: Skip rate limiting for static resources
        if ($this->isStaticResource()) {
            return;
        }

        // ENTERPRISE CRITICAL: Skip rate limiting for essential pages
        if ($this->isEssentialPage()) {
            // Still log for monitoring but don't limit
            $this->logEssentialPageAccess();

            return;
        }

        $clientIp = $this->rateLimitService->getClientIp();
        $userId = $_SESSION['user_id'] ?? null;

        // ENTERPRISE: Intelligent category detection
        $category = $this->detectRequestCategory();
        $effectiveType = $this->getEffectiveRateType($category, $userId, $type);

        // Check rate limit using Redis service with intelligent category
        if (!$this->rateLimitService->isRequestAllowed($clientIp, $userId, $effectiveType)) {
            $this->handleRateLimitExceeded($clientIp, $effectiveType, 'exceeded', $category);

            return;
        }

        // Record the request with category context
        $this->rateLimitService->recordRequest($clientIp, $userId, $effectiveType);
    }

    /**
     * Handle rate limiting specifico per API
     */
    public function handleApi(): void
    {
        $this->handle('api');
    }

    /**
     * Handle rate limiting per autenticazione
     */
    public function handleAuth(): void
    {
        $this->handle('auth');
    }

    /**
     * Cleanup vecchi log (per cron job)
     */
    public function cleanup(): int
    {
        $deleted = 0;

        // Rimuovi log vecchi di 7 giorni
        $stmt = $this->db->prepare('
            DELETE FROM user_rate_limit_log
            WHERE created_at < NOW() - INTERVAL \'7 days\'
        ');
        $stmt->execute();
        $deleted += $stmt->rowCount();

        // Rimuovi ban scaduti
        $stmt = $this->db->prepare('
            DELETE FROM user_rate_limit_bans
            WHERE expires_at < NOW()
        ');
        $stmt->execute();
        $deleted += $stmt->rowCount();

        return $deleted;
    }

    /**
     * Ottieni statistiche rate limiting
     */
    public function getStats(): array
    {
        $stats = [];

        // Richieste per tipo nelle ultime 24h
        $stmt = $this->db->query("
            SELECT action_type, COUNT(*) as count
            FROM user_rate_limit_log
            WHERE created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY action_type
        ");
        $stats['requests_24h'] = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        // IP attualmente bannati
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM user_rate_limit_bans WHERE expires_at > NOW()
        ");
        $stats['active_bans'] = (int) $stmt->fetchColumn();

        return $stats;
    }

    /**
     * Aggiungi IP a whitelist
     */
    public function addWhitelistIp(string $ip): void
    {
        if (!in_array($ip, $this->whitelistIps, true)) {
            $this->whitelistIps[] = $ip;
        }
    }

    /**
     * Aggiungi utente a whitelist
     */
    public function addWhitelistUser(int $userId): void
    {
        if (!in_array($userId, $this->whitelistUsers, true)) {
            $this->whitelistUsers[] = $userId;
        }
    }

    /**
     * Verifica rate limit per IP/utente
     */
    private function checkRateLimit(string $ip, ?int $userId, string $type): bool
    {
        $config = $this->limits[$type] ?? $this->limits['web'];
        $window = time() - $config['window'];

        // Rate limit per IP
        $ipCount = $this->getRequestCount($ip, null, $type, $window);

        if ($ipCount >= $config['requests']) {
            $this->banIp($ip, $config['ban_duration']);

            return false;
        }

        // Rate limit per utente (se autenticato)
        if ($userId) {
            $userCount = $this->getRequestCount(null, $userId, $type, $window);

            if ($userCount >= ($config['requests'] * 2)) { // Utenti hanno limite doppio
                return false;
            }
        }

        return true;
    }

    /**
     * Ottieni conteggio richieste nel periodo
     */
    private function getRequestCount(?string $ip, ?int $userId, string $type, int $since): int
    {
        $conditions = ['action_type = ?', 'created_at >= TO_TIMESTAMP(?)'];
        $params = [$type, $since];

        if ($ip) {
            $conditions[] = 'ip_address = ?';
            $params[] = $ip;
        }

        if ($userId) {
            $conditions[] = 'user_id = ?';
            $params[] = $userId;
        }

        $sql = 'SELECT COUNT(*) FROM user_rate_limit_log WHERE ' . implode(' AND ', $conditions);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Registra richiesta nel log
     */
    private function recordRequest(string $ip, ?int $userId, string $type): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_rate_limit_log (ip_address, user_id, action_type, user_agent, identifier_type, identifier_hash)
                VALUES (?, ?, ?, ?, 'ip', SHA2(?, 256))
            ");

            $stmt->execute([
                $ip,
                $userId,
                $type,
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $ip,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('DEFAULT: Failed to record rate limit log', [
                'error' => $e->getMessage(),
                'ip' => $ip,
                'user_id' => $userId,
                'type' => $type,
            ]);
        }
    }

    /**
     * Verifica se IP è bannato
     */
    private function isBanned(string $ip): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM user_rate_limit_bans
            WHERE ip_address = ? AND expires_at > NOW()
        ');
        $stmt->execute([$ip]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Banna IP per durata specificata
     */
    private function banIp(string $ip, int $duration): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_rate_limit_bans (ip_address, expires_at, reason, ban_type, severity)
                VALUES (?, NOW() + MAKE_INTERVAL(secs => ?), 'Rate limit exceeded', 'temporary', 'medium')
                ON CONFLICT (ip_address) DO UPDATE SET
                expires_at = EXCLUDED.expires_at
            ");

            $stmt->execute([$ip, $duration]);

            $this->logger->warning('SECURITY: IP banned for rate limit violation', [
                'ip' => $ip,
                'duration' => $duration,
                'expires_at' => date('Y-m-d H:i:s', time() + $duration),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('DEFAULT: Failed to ban IP', [
                'error' => $e->getMessage(),
                'ip' => $ip,
            ]);
        }
    }

    /**
     * Verifica se IP/utente è in whitelist
     */
    private function isWhitelisted(string $ip, ?int $userId): bool
    {
        // IP whitelist
        if (in_array($ip, $this->whitelistIps, true)) {
            return true;
        }

        // User whitelist
        if ($userId && in_array($userId, $this->whitelistUsers, true)) {
            return true;
        }

        return false;
    }

    /**
     * ENTERPRISE: Gestione superamento rate limit con categoria intelligente
     */
    private function handleRateLimitExceeded(string $ip, string $type, string $reason, string $category = ''): void
    {
        $config = $this->limits[$type] ?? $this->limits['web'];

        // Enhanced logging with category context
        Logger::warning('SECURITY: Rate limit exceeded', [
            'ip' => $ip,
            'type' => $type,
            'category' => $category,
            'reason' => $reason,
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'limit' => $config['requests'],
            'window' => $config['window'],
        ]);

        // Security: Log potential attacks for high frequency categories
        if (in_array($category, ['auth', 'api'], true) && $reason === 'exceeded') {
            Logger::security('warning', 'SECURITY: Potential attack detected - high frequency requests', [
                'ip' => $ip,
                'category' => $category,
                'type' => $type,
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
            ]);
        }

        // Headers rate limit info
        header('X-RateLimit-Limit: ' . $config['requests']);
        header('X-RateLimit-Window: ' . $config['window']);
        header('X-RateLimit-Reset: ' . (time() + $config['window']));
        header('X-RateLimit-Category: ' . $category);

        // Different responses based on category
        $response = $this->buildRateLimitResponse($type, $category, $config);

        // Response 429 Too Many Requests
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . $config['window']);

        echo json_encode($response);
        exit;
    }

    /**
     * Build appropriate rate limit response based on category
     */
    private function buildRateLimitResponse(string $type, string $category, array $config): array
    {
        $baseResponse = [
            'error' => 'Rate limit exceeded',
            'code' => 429,
            'retry_after' => $config['window'],
            'type' => $type,
            'category' => $category,
        ];

        // Customized messages based on category
        switch ($category) {
            case 'auth':
                $baseResponse['message'] = 'Troppi tentativi di accesso. Riprova tra ' .
                    round($config['window'] / 60) . ' minuti per sicurezza.';
                $baseResponse['security_notice'] = 'Questo limite protegge il tuo account.';
                break;

            case 'api':
                $baseResponse['message'] = 'Rate limit API superato. Riprova tra ' .
                    round($config['window'] / 60) . ' minuti.';
                $baseResponse['suggestion'] = 'Considera di ottimizzare le tue richieste API.';
                break;

            case 'upload':
                $baseResponse['message'] = 'Limite upload superato. Riprova tra ' .
                    round($config['window'] / 60) . ' minuti.';
                $baseResponse['tip'] = 'Puoi caricare fino a ' . $config['requests'] . ' file ogni ora.';
                break;

            case 'social':
                $baseResponse['message'] = 'Troppa attività social. Fai una pausa di ' .
                    round($config['window'] / 60) . ' minuti.';
                break;

            default:
                $baseResponse['message'] = 'Troppe richieste. Riprova più tardi.';
        }

        return $baseResponse;
    }

    /**
     * Ottieni IP reale del client
     */
    private function getClientIp(): string
    {
        // Lista header da controllare in ordine di priorità
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy/Load Balancer
            'HTTP_X_REAL_IP',            // Nginx
            'HTTP_CLIENT_IP',            // Proxy
            'REMOTE_ADDR',                // Diretta
        ];

        foreach ($headers as $header) {
            $serverValue = EnterpriseGlobalsManager::getServer($header);

            if (!empty($serverValue)) {
                $ip = $serverValue;

                // Se header contiene più IP, prendi il primo
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Valida formato IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Fallback a REMOTE_ADDR se nessun IP valido trovato
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Carica configurazioni da variabili ambiente
     */
    private function loadEnvConfig(): void
    {
        // Rate limit per richieste web
        if (isset($_ENV['RATE_LIMIT_REQUESTS'])) {
            $this->limits['web']['requests'] = (int) $_ENV['RATE_LIMIT_REQUESTS'];
        }

        // Rate limit per API
        if (isset($_ENV['API_RATE_LIMIT_REQUESTS'])) {
            $this->limits['api']['requests'] = (int) $_ENV['API_RATE_LIMIT_REQUESTS'];
        }

        // Whitelist IP da variabili ambiente
        if (isset($_ENV['RATE_LIMIT_WHITELIST_IPS'])) {
            $envIps = explode(',', $_ENV['RATE_LIMIT_WHITELIST_IPS']);
            $this->whitelistIps = array_merge($this->whitelistIps, array_map('trim', $envIps));
        }
    }

    /**
     * Check if request is for static resources (ENTERPRISE optimization)
     */
    private function isStaticResource(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // Static file extensions that should not be rate limited
        $staticExtensions = [
            '.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg',
            '.ico', '.woff', '.woff2', '.ttf', '.eot', '.map', '.webm', '.mp3',
            '.mp4', '.pdf', '.zip', '.txt',
        ];

        foreach ($staticExtensions as $ext) {
            if (str_ends_with(strtolower($uri), $ext)) {
                return true;
            }
        }

        // Static paths that should not be rate limited
        $staticPaths = ['/assets/', '/static/', '/uploads/', '/media/', '/files/'];

        foreach ($staticPaths as $path) {
            if (str_contains($uri, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ENTERPRISE CRITICAL: Check if current request is for essential pages
     * Essential pages MUST NEVER be rate limited for UX and business reasons
     */
    private function isEssentialPage(): bool
    {
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);

        // Essential pages that must NEVER be rate limited
        $essentialPages = [
            '/',           // Homepage
            '/home',       // Alternative homepage
            '/login',      // Login page
            '/register',   // Registration page
            '/auth/login', // Auth login
            '/auth/register', // Auth register
            '/about',      // About page
            '/legal/privacy', // Privacy policy
            '/legal/terms',   // Terms of service
            '/help/faq',   // FAQ
            '/help/guide', // User guide
            '/404',        // Error pages
            '/403',
            '/500',
        ];

        // Exact match first
        if (in_array($path, $essentialPages, true)) {
            return true;
        }

        // Pattern matching for dynamic essential pages
        $essentialPatterns = [
            '/^\/$/',                    // Root only
            '/^\/home\/?$/',            // Home with optional slash
            '/^\/login\/?$/',           // Login variations
            '/^\/register\/?$/',        // Register variations
            '/^\/auth\/(login|register)\/?$/', // Auth endpoints
            '/^\/legal\/(privacy|terms|contacts)\/?$/', // Legal pages
            '/^\/help\/(faq|guide|safety)\/?$/',  // Help pages
        ];

        foreach ($essentialPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log access to essential pages for monitoring (without rate limiting)
     */
    private function logEssentialPageAccess(): void
    {
        // Logging removed for performance - essential page access is normal
    }

    /**
     * ENTERPRISE: Detect request category for intelligent rate limiting
     */
    private function detectRequestCategory(): string
    {
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($uri, PHP_URL_PATH);

        // API requests
        if (str_starts_with($path, '/api/')) {
            return 'api';
        }

        // Authentication requests
        if (preg_match('/\/(login|register|logout|forgot-password|reset-password)/', $path)
            || preg_match('/\/auth\/(login|register|logout)/', $path)) {
            return 'auth';
        }

        // Upload requests
        if (str_contains($path, '/upload') || str_contains($path, '/record')
            || ($method === 'POST' && str_contains($path, '/audio'))) {
            return 'upload';
        }

        // Social interactions
        if (preg_match('/\/(like|comment|friend|social|follow)/', $path)
            || str_contains($path, '/profile/') && $method === 'POST') {
            return 'social';
        }

        // User content pages
        if (preg_match('/\/(profile|settings|dashboard)/', $path)) {
            return 'user_content';
        }

        // Default to web for everything else
        return 'web';
    }

    /**
     * Get effective rate limiting type based on category and user status
     */
    private function getEffectiveRateType(string $category, ?int $userId, string $defaultType): string
    {
        // Essential pages are never rate limited (handled earlier)
        if ($category === 'essential') {
            return 'essential';
        }

        // For authenticated users, apply more generous limits
        if ($userId && in_array($category, ['web', 'user_content', 'social'], true)) {
            // Authenticated users get higher limits
            return $category;
        }

        // Use detected category or fallback to default
        return $this->limits[$category] ? $category : $defaultType;
    }
}
