<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseGlobalsManager;

/**
 * Enterprise Environment Manager - Scalable Configuration Management
 *
 * Features:
 * - Memory caching for 10,000+ concurrent requests
 * - Multi-source configuration loading (.env, system, override)
 * - Validation and type casting
 * - Thread-safe singleton pattern
 * - Development/Production environment detection
 * - Configuration hot-reload without server restart
 */
class EnterpriseEnvironmentManager
{
    private const CACHE_TTL = 300; // 5 minutes

    private static ?self $instance = null;

    private array $config = [];

    private array $cache = [];

    private int $lastReload = 0;

    private function __construct()
    {
        $this->loadConfiguration();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get configuration value with caching
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Check if we need to reload configuration
        if (time() - $this->lastReload > self::CACHE_TTL) {
            $this->loadConfiguration();
        }

        return $this->config[$key] ?? $default;
    }

    /**
     * Get all configuration (for debugging)
     */
    public function getAll(): array
    {
        // Don't expose sensitive data
        $safe = $this->config;
        $sensitive = ['DB_PASSWORD', 'DB_PASS', 'API_KEY', 'SECRET_KEY', 'PRIVATE_KEY'];

        foreach ($sensitive as $key) {
            if (isset($safe[$key])) {
                $safe[$key] = '***HIDDEN***';
            }
        }

        return $safe;
    }

    /**
     * Hot reload configuration without server restart
     */
    public function reload(): void
    {
        $this->config = [];
        $this->cache = [];
        $this->loadConfiguration();
    }

    /**
     * Get database configuration as array (optimized for EnterpriseSecureDatabasePool)
     */
    public function getDatabaseConfig(): array
    {
        return [
            'host' => $this->get('DB_HOST', 'postgres'),  // ENTERPRISE: PostgreSQL (migrated from MySQL)
            'port' => $this->get('DB_PORT', 5432),        // PostgreSQL default port (was 3306 for MySQL)
            'dbname' => $this->get('DB_NAME', 'need2talk'),
            'username' => $this->get('DB_USERNAME') ?? $this->get('DB_USER', 'need2talk'), // PostgreSQL user (was 'root')
            'password' => $this->get('DB_PASSWORD', ''),
            'charset' => 'utf8',  // PostgreSQL uses 'utf8' (NOT 'utf8mb4' like MySQL)
        ];
    }

    /**
     * Get Redis configuration
     */
    public function getRedisConfig(): array
    {
        return [
            'host' => $this->get('REDIS_HOST', 'redis'),
            'port' => $this->get('REDIS_PORT', 6379),
            'password' => $this->get('REDIS_PASSWORD'),
            'database' => $this->get('REDIS_DATABASE', 0),
            'prefix' => $this->get('REDIS_PREFIX', 'n2t:'),
            'timeout' => $this->get('REDIS_TIMEOUT', 2.0),
        ];
    }

    /**
     * Get performance statistics
     */
    public function getStats(): array
    {
        return [
            'config_count' => count($this->config),
            'last_reload' => $this->lastReload,
            'cache_age_seconds' => time() - $this->lastReload,
            'memory_usage_mb' => round(memory_get_usage() / 1024 / 1024, 2),
            'environment' => $this->get('APP_ENV', 'unknown'),
        ];
    }

    /**
     * Load configuration from multiple sources with priority
     */
    private function loadConfiguration(): void
    {
        $startTime = microtime(true);

        // Load from .env file (highest priority)
        $this->loadFromEnvFile();

        // Load from system environment
        $this->loadFromSystemEnv();

        // Apply production overrides if needed
        $this->applyEnvironmentOverrides();

        // Validate critical configurations
        $this->validateCriticalConfig();

        $this->lastReload = time();

        // Configuration loaded
    }

    /**
     * Load configuration from .env file
     */
    private function loadFromEnvFile(): void
    {
        $envFile = APP_ROOT . '/.env';

        if (!file_exists($envFile)) {
            Logger::warning('ENV: No .env file found, using system environment only');

            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Parse KEY=VALUE
            $pos = strpos($line, '=');

            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Remove quotes
            $value = trim($value, '"\'');

            $this->config[$key] = $this->castValue($value);
        }
    }

    /**
     * Load from system environment variables
     */
    private function loadFromSystemEnv(): void
    {
        // Load critical system variables
        $systemVars = ['HOME', 'PATH', 'USER', 'SHELL', 'PWD'];

        foreach ($systemVars as $var) {
            if (($value = getenv($var)) !== false) {
                $this->config[$var] = $value;
            }
        }

        // Load $_ENV variables (lower priority than .env file)
        $envVars = EnterpriseGlobalsManager::getAllEnv();

        foreach ($envVars as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $this->castValue($value);
            }
        }
    }

    /**
     * Apply environment-specific overrides
     */
    private function applyEnvironmentOverrides(): void
    {
        $environment = $this->config['APP_ENV'] ?? 'production';

        switch ($environment) {
            case 'development':
                $this->config['APP_DEBUG'] = true;
                $this->config['LOG_LEVEL'] = 'debug';
                break;

            case 'production':
                $this->config['APP_DEBUG'] = false;
                $this->config['LOG_LEVEL'] = 'error';
                // Force secure settings
                $this->config['SESSION_SECURE'] = true;
                $this->config['FORCE_HTTPS'] = true;
                break;

            case 'testing':
                $this->config['APP_DEBUG'] = true;
                $this->config['LOG_LEVEL'] = 'info';
                break;
        }
    }

    /**
     * Validate critical configuration values
     */
    private function validateCriticalConfig(): void
    {
        $required = [
            'APP_NAME' => 'string',
            'APP_ENV' => 'string',
            'DB_HOST' => 'string',
            'DB_PORT' => 'integer',
            'DB_NAME' => 'string',
            'DB_USERNAME' => 'string', // Changed from DB_USER to DB_USERNAME (standard)
        ];

        $errors = [];

        foreach ($required as $key => $expectedType) {
            if (!isset($this->config[$key])) {
                $errors[] = "Missing required configuration: {$key}";

                continue;
            }

            $value = $this->config[$key];
            $actualType = gettype($value);

            if ($expectedType === 'integer' && !is_int($value)) {
                $errors[] = "Configuration {$key} must be integer, got {$actualType}";
            } elseif ($expectedType === 'string' && !is_string($value)) {
                $errors[] = "Configuration {$key} must be string, got {$actualType}";
            }
        }

        if (!empty($errors)) {
            Logger::error('ENV: Configuration validation failed', ['errors' => $errors]);

            throw new \RuntimeException('Critical configuration validation failed: ' . implode(', ', $errors));
        }
    }

    /**
     * Cast string values to appropriate types
     */
    private function castValue(string|array $value): mixed
    {
        // Handle arrays (from Docker Compose variable expansion)
        if (is_array($value)) {
            // If array, convert to JSON string for storage
            return json_encode($value);
        }

        // Boolean values
        if (in_array(strtolower($value), ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array(strtolower($value), ['false', '0', 'no', 'off', ''], true)) {
            return false;
        }

        // Numeric values
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }

        return $value;
    }
}
