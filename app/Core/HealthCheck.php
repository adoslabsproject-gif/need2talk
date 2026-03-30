<?php

namespace Need2Talk\Core;

/**
 * Enterprise System Health Check
 *
 * Verifica che tutti i componenti enterprise siano funzionanti:
 * - Estensioni cache (Redis, Memcached, APCu)
 * - Database connectivity
 * - Memory limits appropriati
 * - Performance thresholds
 */
class HealthCheck
{
    private array $results = [];

    public function runCompleteCheck(): array
    {
        $this->checkExtensions();
        $this->checkDatabase();
        $this->checkMemoryLimits();
        $this->checkCacheConfiguration();
        $this->checkSystemRequirements();

        return [
            'overall_status' => $this->getOverallStatus(),
            'checks' => $this->results,
            'timestamp' => time(),
            'environment' => $_ENV['APP_ENV'] ?? 'unknown',
        ];
    }

    /**
     * Quick status check for monitoring systems
     */
    public function getStatusCode(): int
    {
        $status = $this->getOverallStatus();

        return match($status) {
            'OK' => 200,
            'WARNING' => 206, // Partial content
            'ERROR' => 503,   // Service unavailable
            default => 500
        };
    }

    private function checkExtensions(): void
    {
        $extensions = [
            'redis' => 'Redis caching (L1 + L3)',
            'memcached' => 'Memcached caching (L2)',
            'pdo_pgsql' => 'PostgreSQL database connectivity',  // ENTERPRISE: Migrated from MySQL to PostgreSQL
            'curl' => 'HTTP client functionality',
            'gd' => 'Image processing',
            'mbstring' => 'Multibyte string support',
        ];

        foreach ($extensions as $ext => $description) {
            $loaded = extension_loaded($ext);
            $this->results['extensions'][$ext] = [
                'status' => $loaded ? 'OK' : 'MISSING',
                'description' => $description,
                'critical' => in_array($ext, ['pdo_pgsql', 'mbstring'], true),  // PostgreSQL + mbstring critical
            ];
        }
    }

    private function checkDatabase(): void
    {
        try {
            $db = db_pdo();
            $stmt = $db->query('SELECT 1 as test');
            $result = $stmt->fetch();

            $this->results['database'] = [
                'status' => $result['test'] === 1 ? 'OK' : 'ERROR',
                'connection' => 'Connected',
                'driver' => $db->getAttribute(\PDO::ATTR_DRIVER_NAME),
            ];
        } catch (\Exception $e) {
            $this->results['database'] = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
                'connection' => 'Failed',
            ];
        }
    }

    private function checkMemoryLimits(): void
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->parseMemoryLimit($memoryLimit);
        $currentUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);

        // For enterprise scale, minimum 512MB
        $minRequired = 512 * 1024 * 1024; // 512MB

        $this->results['memory'] = [
            'status' => $memoryLimitBytes >= $minRequired ? 'OK' : 'WARNING',
            'limit' => $memoryLimit,
            'current_usage' => $this->formatBytes($currentUsage),
            'peak_usage' => $this->formatBytes($peakUsage),
            'usage_percentage' => round(($currentUsage / $memoryLimitBytes) * 100, 2),
        ];
    }

    private function checkCacheConfiguration(): void
    {
        try {
            $cache = EnterpriseCacheFactory::getInstance();
            $testKey = 'health_check_' . time();
            $testValue = 'test_data';

            // Test cache set/get
            $cache->set($testKey, $testValue, 60);
            $retrieved = $cache->get($testKey);

            $this->results['cache'] = [
                'status' => $retrieved === $testValue ? 'OK' : 'WARNING',
                'test_passed' => $retrieved === $testValue,
                'available_layers' => $this->getAvailableCacheLayers(),
            ];

            // Cleanup test key
            $cache->delete($testKey);

        } catch (\Exception $e) {
            $this->results['cache'] = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkSystemRequirements(): void
    {
        $requirements = [
            'php_version' => [
                'current' => PHP_VERSION,
                'required' => '8.0.0',
                'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'OK' : 'ERROR',
            ],
            'max_execution_time' => [
                'current' => ini_get('max_execution_time'),
                'recommended' => '60',
                'status' => ini_get('max_execution_time') >= 60 ? 'OK' : 'WARNING',
            ],
            'upload_max_filesize' => [
                'current' => ini_get('upload_max_filesize'),
                'recommended' => '10M',
                'status' => $this->parseMemoryLimit(ini_get('upload_max_filesize')) >= (10 * 1024 * 1024) ? 'OK' : 'WARNING',
            ],
        ];

        $this->results['system_requirements'] = $requirements;
    }

    private function getAvailableCacheLayers(): array
    {
        $cache = EnterpriseCacheFactory::getInstance();
        $stats = $cache->getStats();

        return [
            'L1_Enterprise_Redis_Cache' => $stats['layers']['l1'] ?? false,
            'L2_Memcached' => extension_loaded('memcached') && class_exists('Memcached'),
            'L3_Redis_Persistent' => extension_loaded('redis') && class_exists('Redis'),
        ];
    }

    private function getOverallStatus(): string
    {
        $hasErrors = false;
        $hasWarnings = false;

        array_walk_recursive($this->results, function ($value, $key) use (&$hasErrors, &$hasWarnings) {
            if ($key === 'status') {
                if ($value === 'ERROR') {
                    $hasErrors = true;
                }

                if ($value === 'WARNING') {
                    $hasWarnings = true;
                }
            }
        });

        if ($hasErrors) {
            return 'ERROR';
        }

        if ($hasWarnings) {
            return 'WARNING';
        }

        return 'OK';
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $limit = trim($limit);
        $last = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);

        switch ($last) {
            case 'g': $value *= 1024 * 1024 * 1024;
                break;
            case 'm': $value *= 1024 * 1024;
                break;
            case 'k': $value *= 1024;
                break;
            default: $value = (int) $limit;
                break;
        }

        return $value;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
}
