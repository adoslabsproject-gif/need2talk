<?php

namespace Need2Talk\Middleware;

use Need2Talk\Services\Logger;

/**
 * NEED2TALK - REQUEST LOGGING MIDDLEWARE
 *
 * LOGGING COMPLETO RICHIESTE:
 * 1. Log tutte le richieste HTTP con dettagli completi
 * 2. Performance monitoring per ogni request
 * 3. Security monitoring (IP, user agent, headers sospetti)
 * 4. Error tracking e response codes
 * 5. Scalabile per milioni di utenti contemporanei
 *
 * LOGS GENERATI:
 * - requests.log: Tutte le richieste HTTP
 * - performance.log: Tempi di risposta e resource usage
 * - security.log: Richieste sospette e pattern di attacco
 */
class RequestLoggingMiddleware
{
    private float $startTime;

    private int $startMemory;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * LOG REQUEST completo per monitoring
     *
     * @return mixed
     */
    public function handle(?callable $next = null)
    {
        // PERFORMANCE: Start monitoring
        $requestStart = microtime(true);
        $memoryStart = memory_get_usage(true);

        // SECURITY: Detect suspicious patterns
        $this->checkSecurityThreats();

        // LOG REQUEST START
        $requestId = $this->generateRequestId();
        $this->logRequestStart($requestId);

        // EXECUTE REQUEST (if next callback provided)
        $response = $next ? $next() : null;

        // PERFORMANCE: Calculate execution time
        $executionTime = microtime(true) - $requestStart;
        $memoryPeak = memory_get_peak_usage(true);
        $memoryUsed = $memoryPeak - $memoryStart;

        // LOG REQUEST COMPLETION
        $this->logRequestComplete($requestId, $executionTime, $memoryUsed, $memoryPeak);

        // PERFORMANCE MONITORING
        $this->logPerformanceMetrics($requestId, $executionTime, $memoryUsed, $memoryPeak);

        return $response;
    }

    /**
     * GENERA REQUEST ID unico per tracking
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * LOG REQUEST START con tutti i dettagli
     */
    private function logRequestStart(string $requestId): void
    {
        $requestData = [
            'request_id' => $requestId,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
            'host' => $_SERVER['HTTP_HOST'] ?? env('SERVER_IP', 'YOUR_SERVER_IP'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'ip' => $this->getClientIP(),
            'forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
            'user_id' => $_SESSION['user_id'] ?? null,
            'session_id' => session_id(),
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'https' => $this->isHTTPS(),
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null,
            'connection' => $_SERVER['HTTP_CONNECTION'] ?? null,
        ];

        // LOG richiesta
        Logger::logRequest($requestData);
    }

    /**
     * LOG REQUEST COMPLETION con response data
     */
    private function logRequestComplete(string $requestId, float $executionTime, int $memoryUsed, int $memoryPeak): void
    {
        $responseData = [
            'request_id' => $requestId,
            'status_code' => http_response_code(),
            'execution_time_ms' => round($executionTime * 1000, 2),
            'memory_used_bytes' => $memoryUsed,
            'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
            'memory_peak_bytes' => $memoryPeak,
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
        ];

        Logger::logRequest(array_merge([
            'type' => 'response',
            'request_id' => $requestId,
        ], $responseData));

        // 🚀 ENTERPRISE GALAXY: Track HTTP errors (4xx/5xx) for analytics
        $statusCode = $responseData['status_code'];
        if ($statusCode >= 400 && $statusCode < 600) {
            $this->trackHttpError($statusCode, $executionTime);
        }

        // 🚀 ENTERPRISE 2025: session_activity rimosso per scalabilità
        // Scriveva "http_request" per ogni page view (24k+ record inutili)
        // session_activity deve registrare solo eventi significativi:
        // - login/logout (gestiti da SecureSessionManager)
        // - timeout, force_logout, remember_login, password_change
        // NON deve registrare ogni singola richiesta HTTP
    }


    /**
     * LOG PERFORMANCE METRICS dettagliati
     */
    private function logPerformanceMetrics(string $requestId, float $executionTime, int $memoryUsed, int $memoryPeak): void
    {
        $performanceData = [
            'request_id' => $requestId,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'execution_time_ms' => round($executionTime * 1000, 2),
            'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'cpu_usage' => $this->getCPUUsage(),
            'load_average' => $this->getLoadAverage(),
            'db_queries' => $this->getDatabaseQueryCount(),
            'cache_hits' => $this->getCacheHits(),
            'is_slow' => $executionTime > 2.0, // Slow request >2s
            'is_memory_intensive' => $memoryUsed > (50 * 1024 * 1024), // >50MB
            'user_id' => $_SESSION['user_id'] ?? null,
            'timestamp' => time(),
        ];

        // ENTERPRISE: Log solo richieste problematiche (non tutte le richieste)
        // Logger::performance fa già il check should_log internamente, ma loggiamo solo slow/memory intensive
        if ($executionTime > 2.0) {
            // Slow request (>2s)
            Logger::performance('warning', 'PERFORMANCE: Slow request', $executionTime, $performanceData);
        }

        if ($memoryUsed > (50 * 1024 * 1024)) {
            // Memory intensive (>50MB)
            Logger::performance('warning', 'PERFORMANCE: High memory usage', $executionTime, $performanceData);
        }
    }

    /**
     * CHECK SECURITY THREATS e pattern sospetti
     *
     * ENTERPRISE GALAXY v6.5: URL-decode detection for encoded attack payloads
     * Attackers often URL-encode payloads to bypass WAF detection:
     * - %3Cscript%3E instead of <script>
     * - %27%20UNION instead of ' UNION
     * - %2e%2e%2f instead of ../
     */
    private function checkSecurityThreats(): void
    {
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // ENTERPRISE: Decode URI for pattern matching (catches URL-encoded attacks)
        // Check both raw and decoded versions to catch all evasion techniques
        $uriDecoded = urldecode($uri);
        $uriDoubleDecoded = urldecode($uriDecoded); // Double-encoding evasion

        // PATTERN SOSPETTI nell'URI
        // ENTERPRISE v6.5: Extended patterns for better attack detection
        $suspiciousPatterns = [
            // Path Traversal (various encodings)
            '/\.\.\//' => 'PATH_TRAVERSAL',
            '/\.\.\\\\/' => 'PATH_TRAVERSAL', // Windows-style
            '/%2e%2e[%2f%5c]/i' => 'PATH_TRAVERSAL_ENCODED',

            // SQL Injection
            '/union\s*(all\s+)?select/i' => 'SQL_INJECTION',
            '/select\s+.*\s+from\s+/i' => 'SQL_INJECTION',
            '/insert\s+into\s+/i' => 'SQL_INJECTION',
            '/delete\s+from\s+/i' => 'SQL_INJECTION',
            '/drop\s+(table|database)/i' => 'SQL_INJECTION',
            '/;\s*--/' => 'SQL_INJECTION', // SQL comment injection
            '/\'\s*or\s+[\'"]?[\d]+[\'"]?\s*=\s*[\'"]?[\d]+/i' => 'SQL_INJECTION', // ' OR '1'='1

            // XSS (Cross-Site Scripting)
            '/<script/i' => 'XSS',
            '/<\/script/i' => 'XSS',
            '/javascript\s*:/i' => 'XSS',
            '/on(error|load|click|mouse|focus|blur|change|submit)\s*=/i' => 'XSS',
            '/<iframe/i' => 'XSS',
            '/<img[^>]+onerror/i' => 'XSS',
            '/<svg[^>]+onload/i' => 'XSS',
            '/<body[^>]+onload/i' => 'XSS',

            // Code Injection
            '/eval\s*\(/i' => 'CODE_INJECTION',
            '/base64_decode\s*\(/i' => 'CODE_INJECTION',
            '/system\s*\(/i' => 'COMMAND_INJECTION',
            '/exec\s*\(/i' => 'COMMAND_INJECTION',
            '/passthru\s*\(/i' => 'COMMAND_INJECTION',
            '/shell_exec\s*\(/i' => 'COMMAND_INJECTION',
            '/`[^`]*`/' => 'COMMAND_INJECTION', // Backtick execution

            // File Inclusion / System Access
            '/etc\/passwd/' => 'FILE_INCLUSION',
            '/etc\/shadow/' => 'FILE_INCLUSION',
            '/proc\/self/' => 'FILE_INCLUSION',
            '/\.htaccess/i' => 'FILE_INCLUSION',
            '/web\.config/i' => 'FILE_INCLUSION',

            // CMS/Admin Scanning
            '/wp-admin|wp-login/i' => 'CMS_SCAN',
            '/administrator\/|joomla/i' => 'CMS_SCAN',
            '/phpmyadmin|adminer/i' => 'ADMIN_SCAN',

            // Remote File Download/Upload
            '/wget\s+/i' => 'REMOTE_DOWNLOAD',
            '/curl\s+/i' => 'REMOTE_DOWNLOAD',
        ];

        // Check patterns against raw URI, decoded URI, and double-decoded URI
        $urisToCheck = [
            'raw' => $uri,
            'decoded' => $uriDecoded,
            'double_decoded' => $uriDoubleDecoded,
        ];

        foreach ($suspiciousPatterns as $pattern => $threatType) {
            foreach ($urisToCheck as $decodeType => $uriToCheck) {
                if (preg_match($pattern, $uriToCheck)) {
                    $this->logSecurityThreat('SUSPICIOUS_URI', [
                        'pattern' => $pattern,
                        'threat_type' => $threatType,
                        'uri' => $uri,
                        'uri_decoded' => ($decodeType !== 'raw') ? $uriToCheck : null,
                        'decode_level' => $decodeType,
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                    ]);
                    // Don't log same pattern multiple times for different decode levels
                    break;
                }
            }
        }

        // USER AGENT sospetti
        // ENTERPRISE GALAXY: ESCLUDE legitimate bots (Googlebot, Bingbot, etc.)
        // Uses AntiVulnerabilityScanningMiddleware whitelist (50+ legitimate bots)
        $suspiciousUserAgents = [
            '/bot/i', // Bots (potrebbero essere legittimi)
            '/scanner/i', // Security scanners
            '/sqlmap/i', // SQL injection tool
            '/nikto/i', // Web scanner
            '/masscan/i', // Port scanner
        ];

        foreach ($suspiciousUserAgents as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                // ENTERPRISE: Skip legitimate bots (Googlebot, Bingbot, etc.)
                if (\Need2Talk\Middleware\AntiVulnerabilityScanningMiddleware::isLegitimateBot($userAgent)) {
                    // Legitimate bot - skip logging as suspicious
                    continue;
                }

                $this->logSecurityThreat('SUSPICIOUS_USER_AGENT', [
                    'pattern' => $pattern,
                    'user_agent' => $userAgent,
                    'ip' => $ip,
                    'uri' => $uri,
                ]);
            }
        }

        // RATE LIMITING checks
        $this->checkRateLimitViolations($ip);
    }

    /**
     * CHECK RATE LIMIT violations
     */
    private function checkRateLimitViolations(string $ip): void
    {
        // Placeholder per rate limit integration
        // Questo dovrebbe integrarsi con UserRateLimitMiddleware
        static $requestCounts = [];

        $minute = date('Y-m-d H:i');
        $key = $ip . '_' . $minute;

        if (!isset($requestCounts[$key])) {
            $requestCounts[$key] = 0;
        }

        $requestCounts[$key]++;

        // ALERT per troppi request dallo stesso IP
        if ($requestCounts[$key] > 100) { // >100 request per minuto
            $this->logSecurityThreat('RATE_LIMIT_VIOLATION', [
                'ip' => $ip,
                'requests_per_minute' => $requestCounts[$key],
                'minute' => $minute,
                'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            ]);
        }
    }

    /**
     * LOG SECURITY THREAT con dettagli completi
     * ENTERPRISE GALAXY: Uses Logger::security() for dual-write (DB + file logs)
     */
    private function logSecurityThreat(string $threatType, array $data): void
    {
        // ENTERPRISE GALAXY: Map threat type to PSR-3 level
        $levelMap = [
            'SQL_INJECTION_ATTEMPT' => 'critical',
            'XSS_ATTEMPT' => 'critical',
            'CODE_INJECTION_ATTEMPT' => 'critical',
            'PATH_TRAVERSAL_ATTEMPT' => 'critical',
            'COMMAND_INJECTION' => 'critical',
            'SUSPICIOUS_URI' => 'error',
            'SUSPICIOUS_USER_AGENT' => 'error',
            'BRUTE_FORCE_ATTEMPT' => 'error',
            'RATE_LIMIT_VIOLATION' => 'warning',
        ];

        $level = $levelMap[$threatType] ?? 'warning';

        // ENTERPRISE GALAXY: Dual-write via Logger::security() (DB + file)
        Logger::security($level, "REQUEST_THREAT: {$threatType}", [
            'event_type' => $threatType,
            'ip' => $data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => $data['uri'] ?? $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '',
            'pattern' => $data['pattern'] ?? null,
            'headers' => $this->getSecurityRelevantHeaders(),
        ]);
    }

    /**
     * GET SECURITY relevant headers
     */
    private function getSecurityRelevantHeaders(): array
    {
        return [
            'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
            'x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
            'cf_connecting_ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            'cf_ray' => $_SERVER['HTTP_CF_RAY'] ?? null,
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
        ];
    }

    /**
     * GET CLIENT IP con proxy detection
     */
    private function getClientIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Se multiple IP (proxy chain), prendi prima
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Valida IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * CHECK se richiesta è HTTPS
     */
    private function isHTTPS(): bool
    {
        return
            (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
    }

    /**
     * GET CPU USAGE (se disponibile)
     */
    private function getCPUUsage(): ?float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();

            return $load[0] ?? null;
        }

        return null;
    }

    /**
     * GET LOAD AVERAGE (se disponibile)
     */
    private function getLoadAverage(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }

        return null;
    }

    /**
     * GET DATABASE QUERY COUNT (placeholder)
     */
    private function getDatabaseQueryCount(): int
    {
        // Placeholder - should integrate with database layer
        return 0;
    }

    /**
     * GET CACHE HITS (placeholder)
     */
    private function getCacheHits(): int
    {
        // Placeholder - should integrate with cache layer
        return 0;
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Track HTTP Errors (4xx/5xx) for Analytics
     *
     * Stores HTTP error details in enterprise_http_errors table for:
     * - Error Rate Chart in Stats Dashboard
     * - Temporal analysis and trending
     * - User impact assessment
     * - Performance correlation
     *
     * PERFORMANCE: Direct INSERT (errors are rare, no batch needed)
     * SCALABILITY: Partitioned table by month, auto-cleanup
     * RELIABILITY: Silent failure to prevent error cascades
     *
     * @param int $statusCode HTTP status code (400-599)
     * @param float $executionTime Request execution time in seconds
     * @return void
     */
    private function trackHttpError(int $statusCode, float $executionTime): void
    {
        try {
            // Determine error type
            $errorType = ($statusCode >= 400 && $statusCode < 500) ? 'client' : 'server';

            // Collect request context
            $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $referer = $_SERVER['HTTP_REFERER'] ?? null;
            $userId = $_SESSION['user_id'] ?? null;
            $ip = $this->getClientIP();

            // Convert execution time to milliseconds
            $responseTimeMs = (int) round($executionTime * 1000);

            // Get database connection
            $db = db();

            // INSERT error record (partitioned table)
            $db->execute('
                INSERT INTO enterprise_http_errors (
                    status_code,
                    request_method,
                    request_uri,
                    user_id,
                    user_agent,
                    ip_address,
                    referer,
                    response_time_ms,
                    error_message,
                    error_type,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ', [
                $statusCode,
                $method,
                $uri,
                $userId,
                $userAgent,
                $ip,
                $referer,
                $responseTimeMs,
                null, // error_message filled by error handler if available
                $errorType,
            ]);

            // Log to security channel for 5xx errors (server failures)
            if ($statusCode >= 500) {
                Logger::security('warning', 'HTTP 5xx Server Error', [
                    'status_code' => $statusCode,
                    'uri' => $uri,
                    'method' => $method,
                    'user_id' => $userId,
                    'ip' => $ip,
                    'response_time_ms' => $responseTimeMs,
                ]);
            }

        } catch (\Throwable $e) {
            // ENTERPRISE: Silent failure - error tracking should NEVER break the request
            // Log to file for debugging, but continue request processing
            Logger::error('ENTERPRISE: Failed to track HTTP error', [
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
