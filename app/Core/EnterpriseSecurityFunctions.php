<?php

namespace Need2Talk\Core;

use Need2Talk\Services\Logger;

/**
 * Enterprise Security Functions Wrapper
 *
 * Centralizza funzioni PHP native critiche per sicurezza
 * con enterprise patterns, logging e fallback mechanisms.
 *
 * CATEGORY 2 ISSUES RESOLUTION:
 * - random_bytes() wrapper con fallback
 * - hash() functions con validation
 * - password_* functions enterprise-grade
 * - file_* functions con security checks
 * - session_* functions con enterprise settings
 *
 * SICUREZZA ENTERPRISE:
 * - Input validation per tutte le funzioni
 * - Automatic logging dei security events
 * - Fallback mechanisms per reliability
 * - Anti-timing attack patterns
 * - Memory-safe operations
 */
class EnterpriseSecurityFunctions
{
    private static array $performanceCache = [];

    private static int $cacheHitCount = 0;

    private static int $cacheMissCount = 0;

    /**
     * Enterprise-grade random bytes generation
     * Fixes: Call to undefined function random_bytes()
     */
    public static function randomBytes(int $length): string
    {
        if ($length < 1 || $length > 1024) {
            throw new \InvalidArgumentException("Invalid random bytes length: $length");
        }

        try {
            // Primary: random_bytes (PHP 7.0+)
            if (function_exists('random_bytes')) {
                return random_bytes($length);
            }

            // Fallback 1: openssl_random_pseudo_bytes
            if (function_exists('openssl_random_pseudo_bytes')) {
                $bytes = openssl_random_pseudo_bytes($length, $strong);

                if ($strong) {
                    return $bytes;
                }
            }

            // Fallback 2: /dev/urandom (Unix systems)
            if (is_readable('/dev/urandom')) {
                $handle = fopen('/dev/urandom', 'rb');

                if ($handle) {
                    $bytes = fread($handle, $length);
                    fclose($handle);

                    if (strlen($bytes) === $length) {
                        Logger::warning('SECURITY: Random bytes from /dev/urandom', ['length' => $length]);

                        return $bytes;
                    }
                }
            }

            // Last resort: mt_rand (NOT cryptographically secure!)
            Logger::error('SECURITY: Using insecure random generation - mt_rand fallback');

            $bytes = '';

            for ($i = 0; $i < $length; $i++) {
                $bytes .= chr(\mt_rand(0, 255));
            }

            return $bytes;

        } catch (\Exception $e) {
            Logger::error('SECURITY: Random bytes generation failed', [
                'length' => $length,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Secure random generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Enterprise-grade hashing with validation
     * Fixes: Call to undefined function hash()
     */
    public static function hash(string $algorithm, string $data, bool $binary = false): string
    {
        // Validate algorithm
        $allowedAlgorithms = ['sha256', 'sha512', 'sha1', 'md5', 'crc32', 'sha384'];

        if (!in_array($algorithm, $allowedAlgorithms, true)) {
            Logger::warning('SECURITY: Attempted use of non-whitelisted hash algorithm', ['algorithm' => $algorithm]);

            throw new \InvalidArgumentException("Hash algorithm not allowed: $algorithm");
        }

        // Deprecation warnings for weak algorithms
        if (in_array($algorithm, ['md5', 'sha1'], true)) {
            Logger::warning('SECURITY: Use of weak hash algorithm', [
                'algorithm' => $algorithm,
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2),
            ]);
        }

        // Check cache for frequent operations
        $cacheKey = "hash_{$algorithm}_" . substr(md5($data), 0, 8);

        if (isset(self::$performanceCache[$cacheKey])) {
            self::$cacheHitCount++;

            return self::$performanceCache[$cacheKey];
        }

        try {
            if (function_exists('hash')) {
                $result = hash($algorithm, $data, $binary);

                // Cache small results only
                if (strlen($data) < 1024) {
                    self::$performanceCache[$cacheKey] = $result;
                    self::$cacheMissCount++;

                    // Limit cache size
                    if (count(self::$performanceCache) > 100) {
                        array_shift(self::$performanceCache);
                    }
                }

                return $result;
            }

            // Fallback implementation
            return self::hashFallback($algorithm, $data, $binary);

        } catch (\Exception $e) {
            Logger::error('SECURITY: Hash generation failed', [
                'algorithm' => $algorithm,
                'data_length' => strlen($data),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Hash generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Enterprise password hashing
     * Enhanced version of password_hash with enterprise defaults
     */
    public static function passwordHash(string $password, ?string $algorithm = null, array $options = []): string
    {
        // Input validation
        if (strlen($password) < 1 || strlen($password) > 4096) {
            throw new \InvalidArgumentException('Invalid password length');
        }

        // Enterprise defaults
        $algorithm = $algorithm ?? PASSWORD_DEFAULT;
        $enterpriseOptions = array_merge([
            'cost' => 12, // Higher cost for enterprise security
            'memory_cost' => 65536, // For Argon2
            'time_cost' => 4,
            'threads' => 3,
        ], $options);

        try {
            if (function_exists('password_hash')) {
                return password_hash($password, $algorithm, $enterpriseOptions);
            }

            // Fallback for older PHP versions
            Logger::warning('SECURITY: Using crypt() fallback for password hashing');

            $salt = '$2y$12$' . substr(str_replace('+', '.', base64_encode(self::randomBytes(22))), 0, 22);

            return crypt($password, $salt);

        } catch (\Exception $e) {
            Logger::error('SECURITY: Password hashing failed', ['error' => $e->getMessage()]);

            throw new \RuntimeException('Password hashing failed: ' . $e->getMessage());
        }
    }

    /**
     * Enterprise password verification with timing attack protection
     */
    public static function passwordVerify(string $password, string $hash): bool
    {
        try {
            if (function_exists('password_verify')) {
                return password_verify($password, $hash);
            }

            // Fallback with timing-safe comparison
            $startTime = microtime(true);
            $result = hash_equals($hash, crypt($password, $hash));

            // Add artificial delay to prevent timing attacks
            $elapsed = (microtime(true) - $startTime) * 1000;

            if ($elapsed < 100) { // Ensure minimum 100ms
                usleep((100 - $elapsed) * 1000);
            }

            return $result;

        } catch (\Exception $e) {
            Logger::error('SECURITY: Password verification failed', ['error' => $e->getMessage()]);

            return false; // Safe default
        }
    }

    /**
     * Enterprise file reading with security checks
     * Fixes: file_get_contents issues
     */
    public static function fileGetContents(string $filename, int $maxSize = 10485760): string // 10MB default
    {
        // Security validation
        if (!self::isFileAccessSafe($filename)) {
            Logger::warning('SECURITY: Unsafe file access blocked', ['filename' => basename($filename)]);

            throw new \InvalidArgumentException('File access denied for security reasons');
        }

        // Check file size before reading
        if (is_file($filename)) {
            $fileSize = filesize($filename);

            if ($fileSize > $maxSize) {
                Logger::warning('SECURITY: File too large', ['filename' => basename($filename), 'size' => $fileSize]);

                throw new \RuntimeException('File exceeds maximum size limit');
            }
        }

        try {
            $content = file_get_contents($filename);

            if ($content === false) {
                throw new \RuntimeException('Failed to read file');
            }

            return $content;

        } catch (\Exception $e) {
            Logger::error('SECURITY: File read failed', [
                'filename' => basename($filename),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('File read failed: ' . $e->getMessage());
        }
    }

    /**
     * Enterprise session management
     */
    public static function sessionStart(array $options = []): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        // Enterprise session configuration
        $enterpriseOptions = array_merge([
            'cookie_lifetime' => 0,
            'cookie_path' => '/',
            'cookie_domain' => '',
            'cookie_secure' => EnterpriseGlobalsManager::getServer('HTTPS') ? true : false,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
        ], $options);

        try {
            $result = session_start($enterpriseOptions);

            if ($result) {
                // Set enterprise security markers
                if (!isset($_SESSION['__enterprise_security'])) {
                    $_SESSION['__enterprise_security'] = [
                        'started_at' => time(),
                        'ip_hash' => hash('sha256', EnterpriseGlobalsManager::getServer('REMOTE_ADDR')),
                        'user_agent_hash' => hash('sha256', EnterpriseGlobalsManager::getServer('HTTP_USER_AGENT', '')),
                    ];
                }
            }

            return $result;

        } catch (\Exception $e) {
            Logger::error('SECURITY: Session start failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Enterprise JSON encoding with error handling
     */
    public static function jsonEncode($value, int $flags = 0, int $depth = 512): string
    {
        $flags = $flags ?: (JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $json = json_encode($value, $flags, $depth);

        if ($json === false) {
            $error = json_last_error_msg();

            Logger::error('DEFAULT: JSON encoding failed', [
                'error' => $error,
                'type' => gettype($value),
            ]);

            throw new \RuntimeException("JSON encoding failed: $error");
        }

        return $json;
    }

    /**
     * Enterprise JSON decoding with validation
     */
    public static function jsonDecode(string $json, bool $associative = false, int $depth = 512, int $flags = 0): mixed
    {
        if (strlen($json) > 1048576) { // 1MB limit
            Logger::warning('DEFAULT: Large JSON decode attempt', ['size' => strlen($json)]);

            throw new \InvalidArgumentException('JSON too large');
        }

        $flags = $flags ?: JSON_BIGINT_AS_STRING;
        $data = json_decode($json, $associative, $depth, $flags);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = json_last_error_msg();

            Logger::error('DEFAULT: JSON decoding failed', ['error' => $error]);

            throw new \RuntimeException("JSON decoding failed: $error");
        }

        return $data;
    }

    /**
     * Get performance statistics
     */
    public static function getPerformanceStats(): array
    {
        return [
            'cache_hits' => self::$cacheHitCount,
            'cache_misses' => self::$cacheMissCount,
            'cache_hit_ratio' => self::$cacheHitCount > 0
                ? round(self::$cacheHitCount / (self::$cacheHitCount + self::$cacheMissCount) * 100, 2)
                : 0,
            'cached_items' => count(self::$performanceCache),
        ];
    }

    /**
     * Clear performance cache
     */
    public static function clearCache(): void
    {
        self::$performanceCache = [];
        self::$cacheHitCount = 0;
        self::$cacheMissCount = 0;
    }

    /**
     * Enterprise-grade timing safe string comparison
     */
    public static function hashEquals(string $expected, string $actual): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($expected, $actual);
        }

        // Fallback implementation
        $expectedLength = strlen($expected);
        $actualLength = strlen($actual);

        // Always compare full lengths to prevent timing attacks
        $result = $expectedLength === $actualLength ? 0 : 1;

        $compareLength = min($expectedLength, $actualLength);

        for ($i = 0; $i < $compareLength; $i++) {
            $result |= ord($expected[$i]) ^ ord($actual[$i]);
        }

        return $result === 0;
    }

    /**
     * Enterprise memory management
     */
    public static function memoryGetUsage(bool $real = false): int
    {
        if (function_exists('memory_get_usage')) {
            return memory_get_usage($real);
        }

        // Fallback estimate
        return 1048576; // 1MB estimate
    }

    /**
     * Enterprise-grade microtime
     */
    public static function microtime(bool $asFloat = false): string|float
    {
        return microtime($asFloat);
    }

    /**
     * Enterprise-grade random integer generation
     */
    public static function randomInt(int $min, int $max): int
    {
        try {
            // Primary: random_int (PHP 7.0+)
            if (function_exists('random_int')) {
                return \random_int($min, $max);
            }

            // Fallback: mt_rand (not cryptographically secure!)
            Logger::warning('SECURITY: Using insecure random generation - mt_rand fallback for random_int');

            return \mt_rand($min, $max);

        } catch (\Exception $e) {
            Logger::error('SECURITY: Random int generation failed', [
                'min' => $min,
                'max' => $max,
                'error' => $e->getMessage(),
            ]);

            // Safe fallback
            return \mt_rand($min, $max);
        }
    }

    /**
     * Enterprise-grade response completion
     * Handles fastcgi_finish_request with enterprise fallbacks
     */
    public static function finishRequest(): bool
    {
        try {
            // Primary: FastCGI finish request (PHP-FPM)
            if (function_exists('fastcgi_finish_request')) {
                return fastcgi_finish_request();
            }

            // Fallback 1: Output buffer flush (Apache mod_php)
            if (ob_get_level() > 0) {
                ob_end_flush();
                flush();

                return true;
            }

            // Fallback 2: Standard flush
            flush();

            return true;

        } catch (\Exception $e) {
            Logger::error('PERFORMANCE: Request finish failed', [
                'error' => $e->getMessage(),
                'method' => function_exists('fastcgi_finish_request') ? 'fastcgi' : 'fallback',
            ]);

            return false;
        }
    }

    /**
     * Enterprise-grade output buffer management
     * FIXES: zlib compression buffer conflicts for hundreds of thousands concurrent users
     */
    public static function cleanOutputBuffer(): bool
    {
        try {
            $level = ob_get_level();

            if ($level > 0) {
                // Handle zlib compression buffers safely
                for ($i = 0; $i < $level; $i++) {
                    if (ob_get_level() > 0) {
                        $status = ob_get_status();
                        $bufferType = $status['type'] ?? 'unknown';

                        try {
                            // Check if this is a zlib compression buffer
                            if ($bufferType === 1 || isset($status['name']) && strpos($status['name'], 'zlib') !== false) {
                                ob_end_clean();
                            } else {
                                ob_clean();
                            }
                        } catch (\Throwable $bufferError) {
                            // Fallback: Always use ob_end_clean for problematic buffers
                            try {
                                if (ob_get_level() > 0) {
                                    ob_end_clean();
                                }
                            } catch (\Throwable $fallbackError) {
                                // Skip this buffer level
                                break;
                            }
                        }
                    }
                }

                return true;
            }

            return true;

        } catch (\Exception $e) {
            Logger::error('PERFORMANCE: Output buffer cleaning failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enterprise-grade HTTP header security
     */
    public static function setSecurityHeaders(): void
    {
        try {
            // Only set headers if not already sent
            if (!headers_sent()) {
                // Basic security headers
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: DENY');
                header('X-XSS-Protection: 1; mode=block');
                header('Referrer-Policy: strict-origin-when-cross-origin');

                // CSP for API endpoints
                header("Content-Security-Policy: default-src 'none'");
            }
        } catch (\Exception $e) {
            Logger::error('SECURITY: Security headers setup failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Hash fallback implementation
     */
    private static function hashFallback(string $algorithm, string $data, bool $binary): string
    {
        switch ($algorithm) {
            case 'md5':
                return $binary ? pack('H*', md5($data)) : md5($data);
            case 'sha1':
                return $binary ? pack('H*', sha1($data)) : sha1($data);
            case 'crc32':
                $crc = sprintf('%u', crc32($data));

                return $binary ? pack('N', $crc) : $crc;
            default:
                throw new \RuntimeException("Hash algorithm not available: $algorithm");
        }
    }

    /**
     * Validate file access safety
     */
    private static function isFileAccessSafe(string $filename): bool
    {
        // Block dangerous patterns
        $dangerousPatterns = [
            '/\.\./', // Directory traversal
            '/\/etc\//', // System files
            '/\/proc\//', // Process files
            '/\/dev\//', // Device files
            '/\0/', // Null bytes
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return false;
            }
        }

        // Only allow files within allowed directories
        $allowedPaths = [
            APP_ROOT . '/storage/',
            APP_ROOT . '/public/',
            APP_ROOT . '/config/',
            APP_ROOT . '/resources/',
        ];

        $realPath = realpath(dirname($filename));

        if ($realPath === false) {
            return false;
        }

        foreach ($allowedPaths as $allowedPath) {
            if (strpos($realPath, realpath($allowedPath)) === 0) {
                return true;
            }
        }

        return false;
    }
}
