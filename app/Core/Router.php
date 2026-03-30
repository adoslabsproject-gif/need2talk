<?php

namespace Need2Talk\Core;

class Router
{
    private array $routes = [];

    private array $middleware = [];

    private array $globalMiddleware = [];

    public function get(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function patch(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function options(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('OPTIONS', $path, $handler, $middleware);
    }

    public function dispatch(string $uri, string $method): void
    {
        // Remove query string and normalize URI
        $uri = parse_url($uri, PHP_URL_PATH);

        // ENTERPRISE: Handle malformed URIs gracefully (e.g., //path, ///path)
        // parse_url() returns null for malformed URLs - normalize to '/' for 404 handling
        if ($uri === null || $uri === false) {
            $uri = '/';
        }

        // SICUREZZA: Esegui global middleware PRIMA di tutto
        foreach ($this->globalMiddleware as $middleware) {
            $this->executeMiddleware($middleware);
        }

        foreach ($this->routes as $route) {
            // ENTERPRISE TIPS: Allow HEAD requests for GET routes (HTTP standard)
            $allowedMethods = [$route['method']];

            if ($route['method'] === 'GET') {
                $allowedMethods[] = 'HEAD';
            }

            if (!in_array($method, $allowedMethods, true)) {
                continue;
            }

            // Convert route pattern to regex
            $pattern = $this->convertToRegex($route['path']);

            if (preg_match($pattern, $uri, $matches)) {

                // Extract parameters
                $params = array_slice($matches, 1);

                // Execute route-specific middleware
                foreach ($route['middleware'] as $middleware) {
                    $this->executeMiddleware($middleware);
                }

                // Execute handler
                try {
                    $this->executeHandler($route['handler'], $params);
                } catch (\Throwable $e) {
                    error_log('[ROUTER] CRITICAL ERROR in executeHandler: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                    error_log('[ROUTER] Stack trace: ' . $e->getTraceAsString());

                    throw $e;
                }

                return;
            }
        }

        // ENTERPRISE GALAXY: Intelligent 404 fallback with anti-scanning detection
        $this->handle404WithAntiScan($uri, $method);
    }

    /**
     * ENTERPRISE GALAXY: Intelligent 404 handler with anti-scanning detection
     *
     * Tracks multiple 404s from same IP and scores suspicious behavior:
     * - 5+ 404s in 5 minutes = +5 points
     * - 10+ 404s in 5 minutes = +10 points
     * - 20+ 404s in 5 minutes = +20 points (near instant ban)
     *
     * Integrates with AntiVulnerabilityScanningMiddleware scoring system
     */
    private function handle404WithAntiScan(string $uri, string $method): void
    {
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // ENTERPRISE: Track 404s in Redis for anti-scanning detection
        $this->track404Request($clientIP, $uri, $method);

        // Send 404 response
        http_response_code(404);

        // ENTERPRISE SECURITY: Anti-cache headers to prevent back button issues after logout
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past

        require APP_ROOT . '/app/Views/errors/404.php';
        exit;
    }

    /**
     * Track 404 requests and detect scanning behavior
     */
    private function track404Request(string $ip, string $uri, string $method): void
    {
        try {
            $redis = new \Redis();

            // ENTERPRISE: Connect to Redis DB 3 (Rate Limiting + Anti-Scan)
            $redisHost = $_ENV['REDIS_HOST'] ?? 'redis';
            $redisPort = (int) ($_ENV['REDIS_PORT'] ?? 6379);

            if (!$redis->pconnect($redisHost, $redisPort, 0.1)) {
                return; // Fail silently - don't block 404 page
            }

            // ENTERPRISE GALAXY: Authenticate with Redis password
            $redisPassword = $_ENV['REDIS_PASSWORD'] ?? null;
            if ($redisPassword) {
                if (!$redis->auth($redisPassword)) {
                    $redis->close();

                    return; // Auth failed - don't block 404 page
                }
            }

            $redis->select(3); // DB 3: Rate Limiting + Anti-Scan

            // Track 404 count (5 minute window)
            $key404 = "anti_scan:404:{$ip}";
            $count404 = $redis->incr($key404);

            if ($count404 === 1) {
                $redis->expire($key404, 300); // 5 minutes
            }

            // ENTERPRISE: Progressive scoring based on 404 count
            $score = 0;
            $reason = null;

            if ($count404 >= 20) {
                $score = 20; // Near instant ban threshold (total 50 needed)
                $reason = 'excessive_404_scanning';
            } elseif ($count404 >= 10) {
                $score = 10;
                $reason = 'high_404_rate';
            } elseif ($count404 >= 5) {
                $score = 5;
                $reason = 'multiple_404s';
            }

            // Add score to IP if threshold exceeded
            if ($score > 0) {
                $scoreKey = "anti_scan:ip:{$ip}";
                $redis->incrBy($scoreKey, $score);
                $redis->expire($scoreKey, 3600); // 1 hour tracking window

                // Store reason
                $reasonsKey = "anti_scan:reasons:{$ip}";
                $redis->sAdd($reasonsKey, $reason);
                $redis->expire($reasonsKey, 3600);

                // Log security event
                $totalScore = (int) $redis->get($scoreKey);

                $logClass = class_exists('Need2Talk\\Services\\Logger')
                    ? 'Need2Talk\\Services\\Logger'
                    : null;

                if ($logClass) {
                    $logClass::security('warning', 'ANTI-SCAN: Multiple 404s detected - possible scanning behavior', [
                        'ip' => $ip,
                        'uri' => $uri,
                        'method' => $method,
                        '404_count' => $count404,
                        'score_added' => $score,
                        'total_score' => $totalScore,
                        'reason' => $reason,
                        'threshold' => 50,
                        'distance_to_ban' => 50 - $totalScore,
                    ]);
                }

                // Check if ban threshold exceeded (50 points)
                $totalScore = (int) $redis->get($scoreKey);
                if ($totalScore >= 50) {
                    $this->banIPFor404Scanning($redis, $ip, $totalScore, $count404);
                }
            }

            $redis->close();

        } catch (\Throwable $e) {
            // Fail silently - don't block 404 page
            error_log('[ROUTER] Failed to track 404 for anti-scan: ' . $e->getMessage());
        }
    }

    /**
     * Ban IP for excessive 404 scanning (ENTERPRISE DUAL-WRITE: Redis + Database)
     */
    private function banIPFor404Scanning(\Redis $redis, string $ip, int $totalScore, int $count404): void
    {
        try {
            // ENTERPRISE: Redis ban (fast check)
            $banKey = "anti_scan:banned:{$ip}";

            $banData = json_encode([
                'timestamp' => time(),
                'score' => $totalScore,
                'reasons' => ['excessive_404_scanning'],
                '404_count' => $count404,
            ]);

            $redis->setEx($banKey, 86400, $banData); // 24 hours

            // ENTERPRISE DUAL-WRITE: Database ban
            if (function_exists('db')) {
                $db = db();

                $severity = match (true) {
                    $totalScore >= 100 => 'critical',
                    $totalScore >= 75 => 'high',
                    $totalScore >= 50 => 'medium',
                    default => 'low',
                };

                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

                $db->execute('
                    INSERT INTO vulnerability_scan_bans
                    (ip_address, ban_type, severity, score, threshold_exceeded,
                     banned_at, expires_at, duration_seconds,
                     scan_patterns, paths_accessed, honeypot_triggered,
                     user_agent, user_agent_type, request_method,
                     violation_count, created_by)
                    VALUES (?, ?, ?, ?, ?,
                            NOW(), NOW() + MAKE_INTERVAL(secs => ?), ?,
                            ?::jsonb, ?::jsonb, ?,
                            ?, ?, ?,
                            1, ?)
                    ON CONFLICT (ip_address) DO UPDATE SET
                        ban_type = EXCLUDED.ban_type,
                        severity = EXCLUDED.severity,
                        score = EXCLUDED.score,
                        banned_at = NOW(),
                        expires_at = EXCLUDED.expires_at,
                        duration_seconds = EXCLUDED.duration_seconds,
                        scan_patterns = COALESCE(vulnerability_scan_bans.scan_patterns, \'[]\'::jsonb) || \'["excessive_404_scanning"]\'::jsonb,
                        paths_accessed = COALESCE(vulnerability_scan_bans.paths_accessed, \'[]\'::jsonb) || ?::jsonb,
                        violation_count = vulnerability_scan_bans.violation_count + 1
                ', [
                    $ip,
                    'automatic',
                    $severity,
                    $totalScore,
                    50,
                    86400,
                    86400,
                    json_encode(['excessive_404_scanning']),
                    json_encode([$currentPath]),
                    0,
                    $userAgent,
                    'unknown',
                    $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    'system',
                    json_encode([$currentPath]),
                ]);
            }

            // Log critical security event
            $logClass = class_exists('Need2Talk\\Services\\Logger')
                ? 'Need2Talk\\Services\\Logger'
                : null;

            if ($logClass) {
                $logClass::security('critical', 'ANTI-SCAN: IP automatically banned for excessive 404 scanning', [
                    'ip' => $ip,
                    'total_score' => $totalScore,
                    '404_count' => $count404,
                    'reasons' => ['excessive_404_scanning'],
                    'ban_duration' => 86400,
                    'threshold' => 50,
                    'dual_write' => true,
                ]);
            }

        } catch (\Throwable $e) {
            error_log('[ROUTER] Failed to ban IP for 404 scanning: ' . $e->getMessage());
        }
    }

    public function addGlobalMiddleware(string $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    private function addRoute(string $method, string $path, $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];

    }

    private function convertToRegex(string $path): string
    {
        // Convert {param} to regex groups
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $path);

        return '#^' . $pattern . '$#';
    }

    private function executeMiddleware(string $middleware): void
    {
        $class = "Need2Talk\\Middleware\\{$middleware}";

        if (class_exists($class)) {
            $instance = new $class();
            $instance->handle();
        }
    }

    private function executeHandler($handler, array $params): void
    {

        if (is_string($handler)) {
            // Controller@method format
            [$controller, $method] = explode('@', $handler);
            $class = "Need2Talk\\Controllers\\{$controller}";

            if (class_exists($class) && method_exists($class, $method)) {
                $instance = new $class();
                call_user_func_array([$instance, $method], $params);
            } else {
                throw new \Exception("Controller not found: {$class}::{$method}");
            }
        } elseif (is_array($handler) && count($handler) === 2) {
            // [ControllerClass::class, 'method'] format
            [$class, $method] = $handler;

            if (class_exists($class) && method_exists($class, $method)) {
                $instance = new $class();
                call_user_func_array([$instance, $method], $params);
            } else {
                throw new \Exception("Controller not found: {$class}::{$method}");
            }
        } elseif (is_callable($handler)) {
            // Closure handler
            call_user_func_array($handler, $params);
        } else {
            throw new \Exception('Invalid handler format');
        }
    }
}
