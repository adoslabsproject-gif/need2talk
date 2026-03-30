<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: Honeypot Controller v2.0
 *
 * Sistema di TRAP per bot scanner - Cattura, banna e raccoglie intelligence
 *
 * FUNZIONAMENTO:
 * - Espone endpoint fasulli che sembrano vulnerabili
 * - Qualsiasi accesso = comportamento malevolo = BAN IMMEDIATO
 * - Log centralizzato dual-write per forensics
 * - Notifiche real-time per attacchi attivi
 * - Intelligence gathering per identificare competitor/scanner
 *
 * HONEYPOT ENDPOINTS v2.0:
 * - /.env (fake environment file)
 * - /phpinfo.php (fake PHP info)
 * - /wp-admin (fake WordPress)
 * - /admin.php (fake admin panel)
 * - /config.php (fake config)
 * - /.git/config (fake git repo)
 * - /api/v1/users (fake API endpoint) [NEW]
 * - /api/v2/debug (fake debug endpoint) [NEW]
 * - /backup.sql, /db.sql (fake database dumps) [NEW]
 * - /uploads/audio/*.mp3 (fake audio paths) [NEW]
 * - /storage/users/*.json (fake user data) [NEW]
 * - /graphql (fake GraphQL endpoint) [NEW]
 * - /swagger.json, /openapi.json (fake API docs) [NEW]
 *
 * INTELLIGENCE GATHERING:
 * - Fingerprint dello scanner (headers, timing, pattern)
 * - Geolocalizzazione IP
 * - Correlazione con attacchi precedenti
 * - Identificazione VPS/Hosting provider
 *
 * SCORING:
 * - Accesso a honeypot = +100 punti (instant ban)
 * - IP bannato per 7 giorni
 * - Alert CRITICAL inviato a team security
 */
class HoneypotController extends BaseController
{
    private const HONEYPOT_BAN_DURATION = 604800; // 7 days
    private const HONEYPOT_SCORE = 100; // Instant ban score
    private const REDIS_DB = 3; // Rate limiting DB
    private const INTEL_REDIS_KEY = 'honeypot:intelligence:';

    /**
     * Handle all honeypot requests
     */
    public function trap(): void
    {
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // 🚀 ENTERPRISE GALAXY v2.0: Gather intelligence BEFORE banning
        $intelligence = $this->gatherIntelligence($clientIP, $userAgent, $requestPath, $method);

        // 🚀 ENTERPRISE GALAXY: Use centralized anti-scan ban system (no code duplication!)
        try {
            \Need2Talk\Middleware\AntiVulnerabilityScanningMiddleware::handleHoneypotAccess(
                $clientIP,
                $userAgent,
                $requestPath
            );
        } catch (\Throwable $e) {
            // ENTERPRISE: Log honeypot system failure (critical - attacker might escape ban!)
            Logger::security('critical', 'HONEYPOT SYSTEM FAILURE: Failed to process honeypot access', [
                'ip' => $clientIP,
                'path' => $requestPath,
                'user_agent' => $userAgent,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'impact' => 'Attacker may have escaped ban - manual review required',
                'action_required' => 'Check anti-scan middleware and Redis/DB connectivity',
                'intelligence' => $intelligence,
            ]);

            // Continue to send fake response (don't reveal error to attacker)
        }

        // Store intelligence in Redis for analysis
        $this->storeIntelligence($clientIP, $intelligence);

        // ENTERPRISE: Send realistic fake response to confuse bot
        $this->sendFakeResponse($requestPath);
    }

    /**
     * 🕵️ ENTERPRISE GALAXY v2.0: Gather intelligence about the attacker
     *
     * Collects fingerprint data to identify scanner/competitor
     */
    private function gatherIntelligence(string $ip, string $userAgent, string $path, string $method): array
    {
        $intel = [
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'ip' => $ip,
            'path' => $path,
            'method' => $method,
            'user_agent' => $userAgent,

            // HTTP Headers fingerprint
            'headers' => [
                'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
                'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
                'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
                'connection' => $_SERVER['HTTP_CONNECTION'] ?? '',
                'cache_control' => $_SERVER['HTTP_CACHE_CONTROL'] ?? '',
                'referer' => $_SERVER['HTTP_REFERER'] ?? '',
                'origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
                'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
                'x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? '',
            ],

            // Request fingerprint
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 0,

            // Scanner identification
            'scanner_type' => $this->identifyScannerType($userAgent, $path),
            'is_vps' => $this->detectVPS($ip),
            'threat_level' => 'high', // Default for honeypot access
        ];

        // Log intelligence
        Logger::security('warning', 'HONEYPOT INTELLIGENCE: Scanner fingerprinted', $intel);

        return $intel;
    }

    /**
     * Identify the type of scanner based on patterns
     */
    private function identifyScannerType(string $userAgent, string $path): string
    {
        $uaLower = strtolower($userAgent);

        // Known scanners
        $scanners = [
            'gptbot' => 'OpenAI GPTBot Spoofer',
            'googlebot' => 'Google Bot Spoofer',
            'bingbot' => 'Bing Bot Spoofer',
            'sqlmap' => 'SQLMap (SQL Injection)',
            'nikto' => 'Nikto (Web Scanner)',
            'nmap' => 'Nmap (Port Scanner)',
            'burp' => 'Burp Suite (Pentest)',
            'acunetix' => 'Acunetix (Vuln Scanner)',
            'nessus' => 'Nessus (Vuln Scanner)',
            'wpscan' => 'WPScan (WordPress)',
            'dirbuster' => 'DirBuster (Dir Enum)',
            'gobuster' => 'Gobuster (Dir Enum)',
            'ffuf' => 'FFUF (Fuzzer)',
            'nuclei' => 'Nuclei (Vuln Scanner)',
            'curl' => 'cURL (Manual/Script)',
            'python-requests' => 'Python Requests',
            'go-http-client' => 'Go HTTP Client',
            'axios' => 'Axios (Node.js)',
            'palo alto' => 'Palo Alto Networks Scanner',
        ];

        foreach ($scanners as $pattern => $name) {
            if (str_contains($uaLower, $pattern)) {
                return $name;
            }
        }

        // Path-based identification
        if (str_contains($path, 'wp-') || str_contains($path, 'wordpress')) {
            return 'WordPress Scanner';
        }
        if (str_contains($path, '.sql') || str_contains($path, 'backup')) {
            return 'Database Dumper';
        }
        if (str_contains($path, 'api/') || str_contains($path, 'graphql')) {
            return 'API Enumerator';
        }
        if (str_contains($path, '.env') || str_contains($path, 'config')) {
            return 'Config Hunter';
        }

        return 'Unknown Scanner';
    }

    /**
     * Detect if IP is from a VPS/Cloud provider (likely attacker)
     */
    private function detectVPS(string $ip): array
    {
        // Known VPS/Cloud ASN patterns (non-exhaustive)
        $vpsProviders = [
            'aruba' => 'Aruba S.p.A.',
            'ovh' => 'OVH',
            'hetzner' => 'Hetzner',
            'digitalocean' => 'DigitalOcean',
            'vultr' => 'Vultr',
            'linode' => 'Linode',
            'aws' => 'Amazon AWS',
            'azure' => 'Microsoft Azure',
            'google' => 'Google Cloud',
            'contabo' => 'Contabo',
            'scaleway' => 'Scaleway',
            'hostinger' => 'Hostinger',
        ];

        // We'll mark as VPS if we detect specific IP ranges later
        // For now, store the IP for manual analysis
        return [
            'suspected_vps' => true,
            'ip' => $ip,
            'note' => 'Check ip-api.com or similar for ASN info',
        ];
    }

    /**
     * Store intelligence in Redis for later analysis
     */
    private function storeIntelligence(string $ip, array $intelligence): void
    {
        try {
            $redis = new \Redis();
            $redisHost = get_env('REDIS_HOST', 'redis');
            $redisPort = (int) get_env('REDIS_PORT', 6379);
            $redisPassword = get_env('REDIS_PASSWORD', '');

            $redis->connect($redisHost, $redisPort);
            if ($redisPassword) {
                $redis->auth($redisPassword);
            }
            $redis->select(self::REDIS_DB);

            $key = self::INTEL_REDIS_KEY . $ip;

            // Store as JSON list (append to existing intel for this IP)
            $existing = $redis->get($key);
            $intelList = $existing ? json_decode($existing, true) : [];
            $intelList[] = $intelligence;

            // Keep last 50 entries per IP
            if (count($intelList) > 50) {
                $intelList = array_slice($intelList, -50);
            }

            // Store with 30-day expiry
            $redis->setex($key, 2592000, json_encode($intelList));

            // Also store in a global honeypot hits list for dashboard
            $globalKey = 'honeypot:hits:' . date('Y-m-d');
            $redis->rpush($globalKey, json_encode([
                'ip' => $ip,
                'time' => time(),
                'path' => $intelligence['path'],
                'scanner' => $intelligence['scanner_type'],
            ]));
            $redis->expire($globalKey, 604800); // 7 days

            $redis->close();
        } catch (\Throwable $e) {
            // Don't fail if Redis is unavailable
            Logger::error('Failed to store honeypot intelligence', [
                'error' => $e->getMessage(),
                'ip' => $ip,
            ]);
        }
    }

    /**
     * Send realistic fake response to confuse bot scanner
     */
    private function sendFakeResponse(string $path): void
    {
        // ENTERPRISE v2.0: Send different fake responses based on path to waste bot's time
        $pathLower = strtolower($path);

        // Add random delay (100-500ms) to slow down scanners
        usleep(random_int(100000, 500000));

        if (str_contains($pathLower, '.env')) {
            $this->sendFakeEnvFile();
        } elseif (str_contains($pathLower, 'phpinfo')) {
            $this->sendFakePhpInfo();
        } elseif (str_contains($pathLower, 'wp-admin') || str_contains($pathLower, 'wp-login')) {
            $this->sendFakeWordPressLogin();
        } elseif (str_contains($pathLower, '.git')) {
            $this->sendFakeGitConfig();
        } elseif (str_contains($pathLower, 'graphql')) {
            $this->sendFakeGraphQL();
        } elseif (str_contains($pathLower, 'swagger') || str_contains($pathLower, 'openapi')) {
            $this->sendFakeSwagger();
        } elseif (str_contains($pathLower, 'api/')) {
            $this->sendFakeAPI($path);
        } elseif (str_contains($pathLower, '.sql') || str_contains($pathLower, 'backup') || str_contains($pathLower, 'dump')) {
            $this->sendFakeSQLDump();
        } elseif (str_contains($pathLower, 'storage/') || str_contains($pathLower, 'uploads/')) {
            $this->sendFakeStorageFile($path);
        } elseif (str_contains($pathLower, 'config') || str_contains($pathLower, 'settings')) {
            $this->sendFakeConfigFile();
        } elseif (str_contains($pathLower, 'debug') || str_contains($pathLower, 'log')) {
            $this->sendFakeDebugLog();
        } else {
            $this->sendGeneric404();
        }
    }

    /**
     * Fake .env file with honeypot data
     */
    private function sendFakeEnvFile(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain');

        // ENTERPRISE HONEYPOT: Randomize fake database types to waste more bot time
        // Bots will try ALL credentials → maximum time wasted
        $fakeDbTypes = [
            [
                'connection' => 'mysql',
                'port' => 3306,
                'database' => 'honeypot_fake_db',
                'username' => 'honeypot_user',
                'password' => 'ThisIsAFakePassword123!NotReal',
            ],
            [
                'connection' => 'pgsql',
                'port' => 5432,
                'database' => 'postgres_honeypot_fake',
                'username' => 'postgres',
                'password' => 'FakePostgresPass!2024',
            ],
            [
                'connection' => 'mongodb',
                'port' => 27017,
                'database' => 'mongo_honeypot_db',
                'username' => 'mongodb_admin',
                'password' => 'MongoHoneypot456!Fake',
            ],
            [
                'connection' => 'sqlite',
                'port' => '',
                'database' => '/var/www/honeypot_fake.sqlite',
                'username' => '',
                'password' => '',
            ],
        ];

        // ENTERPRISE: Rotate fake credentials randomly (different each request)
        $selectedDb = $fakeDbTypes[array_rand($fakeDbTypes)];

        // ENTERPRISE: Fake credentials to waste bot's time
        echo <<<ENV
APP_NAME=need2talk
APP_ENV=production
APP_KEY=base64:FAKE_KEY_TO_WASTE_BOT_TIME_1234567890
APP_DEBUG=false
// APP_URL=https://need2talk.test

DB_CONNECTION={$selectedDb['connection']}
// DB_HOST=127.0.0.1
DB_PORT={$selectedDb['port']}
DB_DATABASE={$selectedDb['database']}
DB_USERNAME={$selectedDb['username']}
DB_PASSWORD={$selectedDb['password']}

// REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Fake credentials - completely useless
ADMIN_EMAIL=admin@fake-honeypot.test
ADMIN_PASSWORD=FakePassword123NotReal!
ENV;
        exit;
    }

    /**
     * Fake phpinfo page
     */
    private function sendFakePhpInfo(): void
    {
        http_response_code(200);
        header('Content-Type: text/html');

        echo <<<HTML
<!DOCTYPE html>
<html>
<head><title>phpinfo()</title></head>
<body>
<h1>PHP Version 7.4.33 (Fake)</h1>
<table>
<tr><td>System</td><td>Linux honeypot 5.15.0</td></tr>
<tr><td>Server API</td><td>Apache 2.4.54</td></tr>
<tr><td>Loaded Configuration File</td><td>/etc/php/7.4/apache2/php.ini</td></tr>
<tr><td>register_globals</td><td>On (FAKE - Insecure on purpose)</td></tr>
<tr><td>allow_url_include</td><td>On (FAKE - Insecure on purpose)</td></tr>
</table>
<p>This is a honeypot. Your IP has been logged and banned.</p>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Fake WordPress login page
     */
    private function sendFakeWordPressLogin(): void
    {
        http_response_code(200);
        header('Content-Type: text/html');

        echo <<<HTML
<!DOCTYPE html>
<html>
<head><title>Log In &lsaquo; Honeypot &#8212; WordPress</title></head>
<body class="login">
<h1><a href="#">Powered by WordPress</a></h1>
<form name="loginform" method="post">
<label for="user_login">Username</label>
<input type="text" name="log" id="user_login" value="">
<label for="user_pass">Password</label>
<input type="password" name="pwd" id="user_pass" value="">
<p class="submit"><input type="submit" value="Log In"></p>
</form>
<p>This is a honeypot. Your IP has been logged and banned for 7 days.</p>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Fake .git/config file
     */
    private function sendFakeGitConfig(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain');

        echo <<<GIT
[core]
        repositoryformatversion = 0
        filemode = true
        bare = false
        logallrefupdates = true
[remote "origin"]
        url = https://github.com/honeypot/fake-repo.git
        fetch = +refs/heads/*:refs/remotes/origin/*
[branch "main"]
        remote = origin
        merge = refs/heads/main
[user]
        name = Honeypot User
        email = honeypot@fake.test

# This is a honeypot. Your IP has been banned.
GIT;
        exit;
    }

    /**
     * Fake config file
     */
    private function sendFakeConfigFile(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain');

        echo <<<CONFIG
<?php
// Fake configuration file - Honeypot
// define('DB_HOST', 'localhost');
define('DB_USER', 'honeypot_user');
define('DB_PASS', 'FakePassword123!');
define('DB_NAME', 'honeypot_db');
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123fake');
// Your IP has been logged and banned for accessing this honeypot
CONFIG;
        exit;
    }

    /**
     * 🚀 NEW v2.0: Fake GraphQL endpoint
     * Looks like a real GraphQL introspection response
     */
    private function sendFakeGraphQL(): void
    {
        http_response_code(200);
        header('Content-Type: application/json');

        echo json_encode([
            'data' => [
                '__schema' => [
                    'queryType' => ['name' => 'Query'],
                    'mutationType' => ['name' => 'Mutation'],
                    'types' => [
                        ['name' => 'User', 'fields' => [
                            ['name' => 'id', 'type' => 'ID'],
                            ['name' => 'email', 'type' => 'String'],
                            ['name' => 'password_hash', 'type' => 'String'],
                            ['name' => 'api_token', 'type' => 'String'],
                        ]],
                        ['name' => 'AudioPost', 'fields' => [
                            ['name' => 'id', 'type' => 'ID'],
                            ['name' => 'user_id', 'type' => 'Int'],
                            ['name' => 'file_path', 'type' => 'String'],
                            ['name' => 'private_url', 'type' => 'String'],
                        ]],
                        ['name' => 'AdminConfig', 'fields' => [
                            ['name' => 'secret_key', 'type' => 'String'],
                            ['name' => 'admin_password', 'type' => 'String'],
                        ]],
                    ],
                ],
            ],
            '_honeypot' => 'Your IP has been logged and banned.',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * 🚀 NEW v2.0: Fake Swagger/OpenAPI documentation
     * Makes scanner think they found API docs
     */
    private function sendFakeSwagger(): void
    {
        http_response_code(200);
        header('Content-Type: application/json');

        echo json_encode([
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'need2talk Internal API (CONFIDENTIAL)',
                'version' => '2.1.0',
                'description' => 'Internal API - DO NOT EXPOSE',
            ],
            'servers' => [
                ['url' => 'https://api-internal.need2talk.it/v2'],
            ],
            'paths' => [
                '/users' => [
                    'get' => [
                        'summary' => 'Get all users with passwords',
                        'security' => [['ApiKey' => []]],
                    ],
                ],
                '/admin/config' => [
                    'get' => [
                        'summary' => 'Get admin configuration',
                        'description' => 'Returns database credentials',
                    ],
                ],
                '/audio/private/{id}' => [
                    'get' => [
                        'summary' => 'Get private audio file',
                        'description' => 'No auth required (BUG - TODO fix)',
                    ],
                ],
                '/debug/sql' => [
                    'post' => [
                        'summary' => 'Execute raw SQL',
                        'description' => 'Dev endpoint - remove in production',
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'ApiKey' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-KEY',
                    ],
                ],
            ],
            '_note' => 'HONEYPOT: Your IP has been logged and banned for 7 days.',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * 🚀 NEW v2.0: Fake API response
     * Returns fake user data to waste attacker's time
     */
    private function sendFakeAPI(string $path): void
    {
        http_response_code(200);
        header('Content-Type: application/json');

        // Generate different fake data based on path
        if (str_contains($path, 'users')) {
            echo json_encode([
                'success' => true,
                'data' => [
                    ['id' => 1, 'email' => 'admin@fake.test', 'role' => 'admin', 'password_hash' => '$2y$10$FAKE_HASH_DO_NOT_USE'],
                    ['id' => 2, 'email' => 'user1@fake.test', 'role' => 'user', 'api_token' => 'fake_token_12345'],
                    ['id' => 3, 'email' => 'moderator@fake.test', 'role' => 'mod', 'secret' => 'honeypot_trap'],
                ],
                '_honeypot' => 'IP logged and banned.',
            ], JSON_PRETTY_PRINT);
        } elseif (str_contains($path, 'debug') || str_contains($path, 'internal')) {
            echo json_encode([
                'debug' => true,
                'database' => [
                    'host' => 'db-internal.fake.local',
                    'user' => 'root',
                    'pass' => 'FakePassword123!',
                    'name' => 'need2talk_prod',
                ],
                'redis' => [
                    'host' => 'redis.fake.local',
                    'password' => 'redis_fake_pass',
                ],
                '_honeypot' => 'Nice try. Banned.',
            ], JSON_PRETTY_PRINT);
        } else {
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Valid API key required',
                'hint' => 'Check /api/v1/auth/token endpoint',
                '_honeypot' => 'Banned.',
            ], JSON_PRETTY_PRINT);
        }
        exit;
    }

    /**
     * 🚀 NEW v2.0: Fake SQL dump
     * Looks like a real database backup
     */
    private function sendFakeSQLDump(): void
    {
        http_response_code(200);
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="backup_' . date('Y-m-d') . '.sql"');

        echo <<<SQL
-- HONEYPOT DATABASE DUMP
-- This is a fake backup file
-- Your IP has been logged and banned

-- MySQL dump 10.19
-- Host: localhost    Database: need2talk_fake
-- Server version: 8.0.32

SET NAMES utf8mb4;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `api_key` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

--
-- Dumping data for table `users`
--

INSERT INTO `users` VALUES
(1, 'admin@honeypot.fake', '\$2y\$10\$FAKE_BCRYPT_HASH_USELESS', 'FAKE_API_KEY_12345'),
(2, 'user@honeypot.fake', '\$2y\$10\$ANOTHER_FAKE_HASH_LOL', 'FAKE_API_KEY_67890');

--
-- Table structure for table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` int NOT NULL,
  `key` varchar(64) NOT NULL COMMENT 'HONEYPOT - All these are fake',
  `secret` varchar(128) NOT NULL
);

INSERT INTO `api_keys` VALUES
(1, 'sk_live_FAKE_stripe_key_honeypot', 'FAKE_SECRET_WASTE_YOUR_TIME'),
(2, 'AKIAFAKEAWSACCESSKEY', 'fake+aws+secret+key+honeypot+trap');

-- HONEYPOT: Your IP {$_SERVER['REMOTE_ADDR']} has been banned for 7 days.
-- All data in this file is fake and useless.
SQL;
        exit;
    }

    /**
     * 🚀 NEW v2.0: Fake storage/uploads file
     * Returns fake file info or 403
     */
    private function sendFakeStorageFile(string $path): void
    {
        // Sometimes return "forbidden" to seem more realistic
        if (random_int(0, 1) === 0) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Access denied',
                'message' => 'This file requires authentication',
                'auth_endpoint' => '/api/v1/auth/token',
                '_honeypot' => 'Banned.',
            ]);
        } else {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'file' => basename($path),
                'size' => random_int(10000, 5000000),
                'created' => date('Y-m-d H:i:s', strtotime('-' . random_int(1, 365) . ' days')),
                'owner' => 'user_' . random_int(1, 1000),
                'private_url' => 'https://cdn.fake.need2talk.it/private/' . bin2hex(random_bytes(16)),
                '_honeypot' => 'All data is fake. You are banned.',
            ]);
        }
        exit;
    }

    /**
     * 🚀 NEW v2.0: Fake debug/log file
     * Looks like exposed debug logs
     */
    private function sendFakeDebugLog(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain');

        $fakeIp = $_SERVER['REMOTE_ADDR'];
        $now = date('Y-m-d H:i:s');

        echo <<<LOG
[{$now}] DEBUG: Application started
[{$now}] INFO: Database connected to 192.168.1.100:5432 (FAKE)
[{$now}] DEBUG: Redis connection established (password: redis_fake_123)
[{$now}] WARNING: API key validation disabled in dev mode (FAKE)
[{$now}] INFO: Admin user authenticated: admin@need2talk.it (FAKE)
[{$now}] DEBUG: SQL Query: SELECT * FROM users WHERE role='admin' (FAKE)
[{$now}] INFO: Loaded 1,247 users from database (FAKE DATA)
[{$now}] DEBUG: AWS S3 bucket: need2talk-private-audio (FAKE)
[{$now}] DEBUG: S3 Access Key: AKIAFAKEACCESSKEY123 (HONEYPOT)
[{$now}] ERROR: Rate limit exceeded for IP {$fakeIp}
[{$now}] SECURITY: HONEYPOT TRIGGERED - IP {$fakeIp} BANNED FOR 7 DAYS
[{$now}] INFO: All data above is FAKE. You wasted your time. Goodbye.
LOG;
        exit;
    }

    /**
     * Generic 404 response
     */
    private function sendGeneric404(): void
    {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
}
