<?php

namespace Need2Talk\Traits;

use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Services\Logger;

/**
 * Enterprise Dependencies Management Trait
 *
 * Provides safe handling of optional enterprise dependencies like AWS S3, getID3
 * with automatic fallback and detailed monitoring for production environments
 * supporting hundreds of thousands of concurrent users.
 */
trait EnterpriseDependencies
{
    /** @var array<string, bool> Dependency availability cache */
    private static array $dependencyAvailabilityCache = [];

    /** @var array<string, mixed> Cached instances of dependencies */
    private static array $dependencyInstanceCache = [];

    /**
     * NOTA: safeGetID3Analysis rimosso - usiamo esclusivamente ffmpeg per l'analisi audio
     * come specificatamente richiesto dall'utente. Tutti i metadata audio vengono estratti
     * tramite ffprobe che è più sicuro, veloce e affidabile di getID3.
     */

    /**
     * Get dependency statistics for monitoring
     */
    public static function getDependencyStats(): array
    {
        return [
            'available_dependencies' => array_filter(self::$dependencyAvailabilityCache),
            'cached_instances' => count(self::$dependencyInstanceCache),
            'total_checks' => count(self::$dependencyAvailabilityCache),
        ];
    }

    /**
     * Reset dependency cache (for testing)
     */
    public static function resetDependencyCache(): void
    {
        self::$dependencyAvailabilityCache = [];
        self::$dependencyInstanceCache = [];
    }

    /**
     * Safe AWS S3 Client creation with enterprise error handling
     */
    protected function createS3ClientSafely(?array $config = null): mixed
    {
        // Check if AWS SDK is available
        if (!$this->isDependencyAvailable('aws/aws-sdk-php')) {
            Logger::warning('DEFAULT: AWS SDK not available for S3 operations', [
                'service' => static::class,
                'suggestion' => 'Run: composer require aws/aws-sdk-php for cloud storage features',
            ]);

            return null;
        }

        if (!class_exists('\Aws\S3\S3Client')) {
            Logger::error('DEFAULT: AWS S3Client class not found despite SDK being available', [
                'service' => static::class,
                'suggestion' => 'Check AWS SDK installation and version compatibility',
            ]);

            return null;
        }

        try {
            $defaultConfig = [
                'version' => 'latest',
                'region' => EnterpriseGlobalsManager::getEnv('DO_SPACES_REGION') ?? EnterpriseGlobalsManager::getEnv('AWS_DEFAULT_REGION', 'us-east-1'),
                'credentials' => [
                    'key' => EnterpriseGlobalsManager::getEnv('DO_SPACES_KEY') ?? EnterpriseGlobalsManager::getEnv('AWS_ACCESS_KEY_ID', ''),
                    'secret' => EnterpriseGlobalsManager::getEnv('DO_SPACES_SECRET') ?? EnterpriseGlobalsManager::getEnv('AWS_SECRET_ACCESS_KEY', ''),
                ],
            ];

            // DigitalOcean Spaces compatibility
            $doSpacesRegion = EnterpriseGlobalsManager::getEnv('DO_SPACES_REGION');

            if (!empty($doSpacesRegion)) {
                $defaultConfig['endpoint'] = 'https://' . $doSpacesRegion . '.digitaloceanspaces.com';
            }

            $config = array_merge($defaultConfig, $config ?? []);

            // Validate required credentials
            if (empty($config['credentials']['key']) || empty($config['credentials']['secret'])) {
                Logger::warning('DEFAULT: S3 credentials not configured', [
                    'service' => static::class,
                    'suggestion' => 'Configure DO_SPACES_KEY/DO_SPACES_SECRET or AWS credentials',
                ]);

                return null;
            }

            // Create S3 client dynamically to avoid IDE type errors when AWS SDK not installed
            $s3ClientClass = '\\Aws\\S3\\S3Client';
            $s3Client = new $s3ClientClass($config);

            Logger::info('DEFAULT: S3 client created successfully', [
                'service' => static::class,
                'region' => $config['region'],
                'endpoint' => $config['endpoint'] ?? 'default',
            ]);

            return $s3Client;

        } catch (\Exception $e) {
            Logger::error('DEFAULT: Failed to create S3 client', [
                'service' => static::class,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * NOTA: getID3 è stato rimosso - usiamo esclusivamente ffmpeg per metadata audio
     * come specificatamente richiesto dall'utente
     */

    /**
     * Check if a Composer package is available
     */
    protected function isDependencyAvailable(string $packageName): bool
    {
        if (isset(self::$dependencyAvailabilityCache[$packageName])) {
            return self::$dependencyAvailabilityCache[$packageName];
        }

        $available = false;

        // Check if composer.json exists and package is listed
        $composerPath = $this->findComposerPath();

        if ($composerPath && file_exists($composerPath)) {
            $composerData = json_decode(file_get_contents($composerPath), true);

            $available = isset($composerData['require'][$packageName])
                        || isset($composerData['require-dev'][$packageName]);
        }

        // If not found in composer.json, check if classes exist (for manual installations)
        if (!$available) {
            $available = match($packageName) {
                'aws/aws-sdk-php' => class_exists('\\Aws\\S3\\S3Client'),
                // getID3 rimosso - usiamo solo ffmpeg per metadata audio
                default => false
            };
        }

        self::$dependencyAvailabilityCache[$packageName] = $available;

        return $available;
    }

    /**
     * Safely execute S3 operation with error handling
     */
    protected function safeS3Operation(mixed $s3Client, string $operation, array $params = []): mixed
    {
        if (!$s3Client) {
            Logger::warning('DEFAULT: S3 operation attempted without valid client', [
                'operation' => $operation,
                'service' => static::class,
            ]);

            return null;
        }

        try {
            $startTime = microtime(true);
            $result = $s3Client->{$operation}($params);
            $executionTime = microtime(true) - $startTime;

            // Log slow operations (>5 seconds)
            if ($executionTime > 5.0) {
                Logger::warning('PERFORMANCE: Slow S3 operation detected', [
                    'operation' => $operation,
                    'execution_time' => round($executionTime, 2) . 's',
                    'service' => static::class,
                    'bucket' => $params['Bucket'] ?? 'unknown',
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Logger::error('DEFAULT: S3 operation failed', [
                'operation' => $operation,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'service' => static::class,
                'params' => array_keys($params),
            ]);

            return null;
        }
    }

    /**
     * Find composer.json path
     */
    private function findComposerPath(): ?string
    {
        $paths = [
            __DIR__ . '/../../composer.json',  // From traits folder
            __DIR__ . '/../../../composer.json', // From deeper nested folder
            __DIR__ . '/../../../../composer.json', // From vendor folder
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
