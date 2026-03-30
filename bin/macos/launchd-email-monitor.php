#!/usr/bin/env php
<?php

/**
 * LAUNCHD EMAIL SYSTEM MONITOR - ENTERPRISE MONITORING (macOS)
 *
 * Sistema di monitoraggio avanzato per Launchd Email Workers
 * - Health check tramite launchctl
 * - Restart automatico via launchd
 * - Logging dettagliato degli eventi
 * - Metrics integration monitoring
 * - Alert system per problemi critici
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT.'/app/bootstrap.php';

use Need2Talk\Services\AsyncEmailQueue;

class LaunchdEmailMonitor
{
    private $monitorId;
    private $checkInterval = 30; // 30 secondi
    private $logFile;
    private $maxWorkers;
    private $servicePrefix = 'com.need2talk.email-worker';
    private $running = true; // Flag per controllo loop

    public function __construct($config = [])
    {
        $this->monitorId = 'launchd_monitor_'.date('YmdHis');
        $this->logFile = APP_ROOT.'/storage/logs/launchd-email-monitor.log';
        $this->maxWorkers = $config['max_workers'] ?? 2; // Default 2 workers (~800 emails capacity)
        $this->checkInterval = $config['check_interval'] ?? 30;

        $this->log('LAUNCHD EMAIL MONITOR STARTED', [
            'monitor_id' => $this->monitorId,
            'max_workers' => $this->maxWorkers,
            'check_interval' => $this->checkInterval,
            'service_prefix' => $this->servicePrefix,
        ]);
    }

    public function startMonitoring(): void
    {
        $this->log('Starting continuous launchd monitoring...');

        while ($this->running) {
            try {
                $this->checkLaunchdServiceHealth();
                $this->checkEmailQueueBacklog();
                $this->checkSystemResources();
                $this->checkMetricsIntegration();
                $this->restartFailedServices();

            } catch (Exception $e) {
                $this->logError('Monitor cycle failed', $e);

                // In caso di errori critici, considera di fermare il monitor
                if ($this->isCriticalError($e)) {
                    $this->log('Critical error detected, stopping monitor');
                    $this->stop();
                    break;
                }
            }

            // Check for signals if PCNTL is available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            sleep($this->checkInterval);
        }

        $this->log('Monitoring stopped');
    }

    public function stop(): void
    {
        $this->running = false;
        $this->log('Monitor stop requested');
    }

    private function isCriticalError(Exception $e): bool
    {
        // Definisce errori critici che dovrebbero fermare il monitor
        $criticalErrors = [
            'Database connection failed permanently',
            'Unable to write to log file',
            'launchctl command not found',
        ];

        foreach ($criticalErrors as $critical) {
            if (strpos($e->getMessage(), $critical) !== false) {
                return true;
            }
        }

        return false;
    }

    public function performHealthCheck(): array
    {
        $results = [
            'services' => $this->checkLaunchdServiceHealth(),
            'queue' => $this->checkEmailQueueBacklog(),
            'resources' => $this->checkSystemResources(),
            'metrics' => $this->checkMetricsIntegration(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log('HEALTH CHECK COMPLETED', $results);

        return $results;
    }

    private function checkLaunchdServiceHealth(): array
    {
        $activeServices = [];
        $failedServices = [];
        $results = [];

        // Check individual worker services
        for ($i = 1; $i <= $this->maxWorkers; $i++) {
            $serviceName = "{$this->servicePrefix}.{$i}";

            // Check if service is loaded and get its status
            $listOutput = $this->executeLaunchctl('list');
            $isLoaded = strpos($listOutput, $serviceName) !== false;

            if ($isLoaded) {
                // Extract PID from list output
                $lines = explode("\n", $listOutput);
                foreach ($lines as $line) {
                    if (strpos($line, $serviceName) !== false) {
                        $parts = preg_split('/\s+/', trim($line));
                        $pid = $parts[0];

                        if ($pid !== '-' && is_numeric($pid)) {
                            $activeServices[] = $i;
                            $results['services'][$i] = [
                                'status' => 'active',
                                'pid' => (int) $pid,
                                'label' => $serviceName,
                            ];
                        } else {
                            $failedServices[] = $i;
                            $results['services'][$i] = [
                                'status' => 'loaded_but_not_running',
                                'label' => $serviceName,
                            ];
                        }
                        break;
                    }
                }
            } else {
                $failedServices[] = $i;
                $results['services'][$i] = [
                    'status' => 'not_loaded',
                    'label' => $serviceName,
                ];
            }
        }

        $this->log('Launchd service health check', [
            'active_count' => count($activeServices),
            'failed_count' => count($failedServices),
            'target_count' => $this->maxWorkers,
            'active_services' => $activeServices,
            'failed_services' => $failedServices,
        ]);

        $results['active_services'] = $activeServices;
        $results['failed_services'] = $failedServices;

        return $results;
    }

    private function checkEmailQueueBacklog(): array
    {
        $results = ['status' => 'unknown', 'pending' => 0];

        try {
            $queue = new AsyncEmailQueue();
            $pending = $queue->getPendingEmailsCount();

            $results = [
                'status' => 'healthy',
                'pending' => $pending,
            ];

            if ($pending > 1000) {
                $this->logAlert('HIGH EMAIL BACKLOG', $results);
                $results['alert'] = 'high_backlog';
            } elseif ($pending > 500) {
                $this->log('Moderate email backlog', $results);
                $results['alert'] = 'moderate_backlog';
            }

        } catch (Exception $e) {
            $this->logError('Failed to check email queue', $e);
            $results['status'] = 'error';
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    private function checkSystemResources(): array
    {
        $results = [];

        // Check memory usage
        $memUsage = memory_get_usage(true);
        $memLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memPercent = ($memUsage / $memLimit) * 100;

        $results['memory'] = [
            'usage_mb' => round($memUsage / 1024 / 1024, 2),
            'limit_mb' => round($memLimit / 1024 / 1024, 2),
            'percentage' => round($memPercent, 2),
        ];

        if ($memPercent > 80) {
            $this->logAlert('HIGH MEMORY USAGE', $results['memory']);
            $results['memory']['alert'] = 'high_usage';
        }

        // Check disk space
        $freeBytes = disk_free_space(APP_ROOT);
        $totalBytes = disk_total_space(APP_ROOT);
        $diskPercent = (($totalBytes - $freeBytes) / $totalBytes) * 100;

        $results['disk'] = [
            'free_gb' => round($freeBytes / 1024 / 1024 / 1024, 2),
            'total_gb' => round($totalBytes / 1024 / 1024 / 1024, 2),
            'used_percent' => round($diskPercent, 2),
        ];

        if ($diskPercent > 85) {
            $this->logAlert('LOW DISK SPACE', $results['disk']);
            $results['disk']['alert'] = 'low_space';
        }

        // Check system load (macOS compatible)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $results['load'] = [
                '1min' => $load[0],
                '5min' => $load[1],
                '15min' => $load[2],
            ];

            if ($load[0] > 4.0) {
                $this->logAlert('HIGH SYSTEM LOAD', $results['load']);
                $results['load']['alert'] = 'high_load';
            }
        }

        return $results;
    }

    private function checkMetricsIntegration(): array
    {
        $results = ['status' => 'unknown'];

        try {
            // ENTERPRISE: PostgreSQL connection (migrated from MySQL)
            $pdo = new PDO('pgsql:host=' . ($_ENV['DB_HOST'] ?? 'postgres') . ';port=' . ($_ENV['DB_PORT'] ?? '5432') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'need2talk'), $_ENV['DB_USER'] ?? 'need2talk', $_ENV['DB_PASSWORD'] ?? '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check recent metrics (PostgreSQL syntax)
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) as total_records,
                    SUM(CASE WHEN processing_time_ms IS NOT NULL THEN 1 ELSE 0 END) as populated_processing_time,
                    SUM(CASE WHEN worker_id IS NOT NULL THEN 1 ELSE 0 END) as populated_worker_id,
                    AVG(processing_time_ms) as avg_processing_time
                FROM email_verification_metrics
                WHERE created_at > NOW() - INTERVAL '1 hour'
            ");
            $stmt->execute();
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);

            $results = [
                'status' => 'healthy',
                'total_records' => (int) $metrics['total_records'],
                'populated_processing_time' => (int) $metrics['populated_processing_time'],
                'populated_worker_id' => (int) $metrics['populated_worker_id'],
                'avg_processing_time_ms' => round((float) $metrics['avg_processing_time'], 2),
                'integration_health' => 'working',
            ];

            // Check integration health
            if ($metrics['total_records'] > 0) {
                $processingTimeRate = $metrics['populated_processing_time'] / $metrics['total_records'];
                $workerIdRate = $metrics['populated_worker_id'] / $metrics['total_records'];

                if ($processingTimeRate < 0.8 || $workerIdRate < 0.8) {
                    $this->logAlert('METRICS INTEGRATION ISSUE', [
                        'processing_time_rate' => round($processingTimeRate * 100, 2).'%',
                        'worker_id_rate' => round($workerIdRate * 100, 2).'%',
                    ]);
                    $results['integration_health'] = 'degraded';
                }
            }

        } catch (Exception $e) {
            $this->logError('Failed to check metrics integration', $e);
            $results['status'] = 'error';
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    private function restartFailedServices(): void
    {
        $health = $this->checkLaunchdServiceHealth();

        if (! empty($health['failed_services'])) {
            $this->log('RESTARTING FAILED SERVICES', [
                'failed_services' => $health['failed_services'],
                'restart_method' => 'launchctl_restart',
            ]);

            foreach ($health['failed_services'] as $serviceNum) {
                $serviceName = "{$this->servicePrefix}.{$serviceNum}";
                $plistPath = "$HOME/Library/LaunchAgents/{$serviceName}.plist";

                // Try to unload and reload the service
                $this->executeLaunchctl("unload \"$plistPath\"");
                sleep(2);
                $result = $this->executeLaunchctl("load \"$plistPath\"");

                $this->log('SERVICE RESTART ATTEMPT', [
                    'service' => $serviceName,
                    'service_number' => $serviceNum,
                    'result' => trim($result),
                ]);

                sleep(2); // Stagger restarts
            }
        }
    }

    private function executeLaunchctl(string $command): string
    {
        $fullCommand = "launchctl $command 2>&1";
        $output = shell_exec($fullCommand);

        return $output ?: '';
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $multiplier = 1;

        switch (strtoupper(substr($limit, -1))) {
            case 'G': $multiplier = 1024 * 1024 * 1024;
                break;
            case 'M': $multiplier = 1024 * 1024;
                break;
            case 'K': $multiplier = 1024;
                break;
        }

        return (int) $limit * $multiplier;
    }

    private function log(string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : ' '.json_encode($context, JSON_UNESCAPED_SLASHES);
        $logEntry = "[$timestamp] [LAUNCHD-MONITOR] $message$contextStr\n";

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        echo $logEntry;
    }

    private function logAlert(string $message, array $context = []): void
    {
        $this->log("🚨 ALERT: $message", $context);

        // In production, qui si potrebbero inviare notifiche via email/Slack
        error_log("LAUNCHD EMAIL MONITOR ALERT: $message ".json_encode($context));
    }

    private function logError(string $message, Exception $e): void
    {
        $this->log("❌ ERROR: $message", [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }
}

// CLI argument parsing
$config = [];
$checkOnly = false;

foreach ($argv as $arg) {
    if ($arg === '--check-only') {
        $checkOnly = true;
    } elseif (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        if (count($parts) === 2) {
            $key = str_replace('-', '_', $parts[0]);
            $value = is_numeric($parts[1]) ? (int) $parts[1] : $parts[1];
            $config[$key] = $value;
        }
    }
}

// Reference to monitor instance for signal handlers
$monitorInstance = null;

// Signal handlers for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$monitorInstance) {
        echo "\nLaunchd Email Monitor shutting down gracefully...\n";
        if ($monitorInstance) {
            $monitorInstance->stop();
        }
        exit(0);
    });

    pcntl_signal(SIGINT, function () use (&$monitorInstance) {
        echo "\nLaunchd Email Monitor interrupted - shutting down...\n";
        if ($monitorInstance) {
            $monitorInstance->stop();
        }
        exit(0);
    });
}

echo "🚀 LAUNCHD EMAIL SYSTEM MONITOR\n";
echo "==============================\n";
echo 'Monitor ID: '.date('YmdHis')."\n";
echo 'Max Workers: '.($config['max_workers'] ?? 2)." (each handles 400 emails)\n";
echo 'Capacity: ~'.(($config['max_workers'] ?? 2) * 400)." emails/batch\n";
echo 'Check Interval: '.($config['check_interval'] ?? 30)." seconds\n";
echo "Service Prefix: com.need2talk.email-worker\n";
echo "==============================\n\n";

try {
    $monitor = new LaunchdEmailMonitor($config);
    $monitorInstance = $monitor; // For signal handlers

    if ($checkOnly) {
        $results = $monitor->performHealthCheck();
        echo "\n🏥 HEALTH CHECK RESULTS:\n";
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    } else {
        $monitor->startMonitoring();
    }
} catch (Exception $e) {
    echo '❌ MONITOR FAILED: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
    exit(1);
}
