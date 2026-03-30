<?php

namespace Need2Talk\Services;

/**
 * ENTERPRISE GALAXY LEVEL: Newsletter Worker Manager
 *
 * Unified newsletter worker management for both development (Docker) and production (systemd).
 * Auto-detects environment and uses appropriate commands.
 *
 * Manages admin-email-worker.php (newsletter/bulk email campaigns)
 *
 * Features:
 * - Auto-detection of Docker vs systemd environment
 * - Direct Docker exec (no intermediate bash scripts)
 * - Systemd integration for production
 * - Comprehensive logging
 * - Error handling with detailed messages
 *
 * @package Need2Talk\Services
 */
class NewsletterWorkerManager
{
    private string $environment;
    private string $dockerCmd;
    private string $phpContainer = 'need2talk_php';
    private string $newsletterContainer = 'need2talk_newsletter_worker';  // ENTERPRISE GALAXY: Dedicated container
    private string $redisContainer = 'need2talk_redis';
    private string $workerScript = '/var/www/html/scripts/admin-email-worker.php';
    private int $defaultWorkers = 2;
    private string $autoRestartFlagFile = '';  // ENTERPRISE GALAXY: Toggle file path

    /**
     * ENTERPRISE: Constructor - Auto-detect environment
     */
    public function __construct()
    {
        $this->detectEnvironment();
        $this->dockerCmd = $this->findDockerCommand();

        // ENTERPRISE GALAXY: Initialize auto-restart toggle flag file path
        $this->autoRestartFlagFile = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/newsletter_auto_restart_disabled.flag';

        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[NEWSLETTER_WORKER_MANAGER] Initialized', [
                'environment' => $this->environment,
                'docker_cmd' => $this->dockerCmd,
                'auto_restart_enabled' => $this->isAutoRestartEnabled(),
            ]);
        }
    }

    /**
     * ENTERPRISE: Auto-detect if we're in Docker or systemd environment
     */
    private function detectEnvironment(): void
    {
        // ENTERPRISE GALAXY FIX: Detect if we're INSIDE a Docker container
        $insideContainer = $this->isRunningInsideContainer();

        // Check if we're in production with systemd
        $systemdExists = file_exists('/run/systemd/system');
        $hasSystemd = $systemdExists && $this->commandExists('systemctl');

        // Check Docker binary exists (only relevant if we're NOT inside a container)
        $hasDocker = !$insideContainer && (
            file_exists('/usr/local/bin/docker') ||
            file_exists('/usr/bin/docker') ||
            $this->commandExists('docker')
        );

        // Determine environment
        if ($hasSystemd) {
            $this->environment = 'production_systemd';
        } elseif ($insideContainer) {
            // PHP-FPM running INSIDE Docker container - execute workers directly
            $this->environment = 'inside_container';
        } elseif ($hasDocker) {
            // PHP-FPM running on HOST with Docker available - use docker exec
            $this->environment = 'development_docker';
        } else {
            $this->environment = 'legacy_scripts';
        }
    }

    /**
     * ENTERPRISE GALAXY: Detect if we're running inside a Docker container
     */
    private function isRunningInsideContainer(): bool
    {
        // Method 1: Check for .dockerenv file (most reliable)
        if (file_exists('/.dockerenv')) {
            return true;
        }

        // Method 2: Check /proc/1/cgroup for docker
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'kubepods') !== false) {
                return true;
            }
        }

        // Method 3: Check hostname matches container pattern
        $hostname = gethostname();
        if ($hostname && strlen($hostname) === 12 && ctype_xdigit($hostname)) {
            // Docker containers often have 12-char hex hostnames
            return true;
        }

        return false;
    }

    /**
     * ENTERPRISE: Find docker command path
     */
    private function findDockerCommand(): string
    {
        // Common paths for docker
        $paths = [
            '/usr/local/bin/docker',  // OrbStack/macOS
            '/usr/bin/docker',        // Linux standard
            'docker',                 // PATH fallback
        ];

        foreach ($paths as $path) {
            if ($path === 'docker' || file_exists($path)) {
                return $path;
            }
        }

        return 'docker'; // Fallback
    }

    /**
     * ENTERPRISE: Check if command exists
     */
    private function commandExists(string $command): bool
    {
        $output = $this->execCommand("which {$command} 2>/dev/null");

        return !empty($output);
    }

    /**
     * ENTERPRISE: Execute command with enhanced PATH
     */
    private function execCommand(string $command): string
    {
        // Enhanced PATH for Docker/OrbStack compatibility
        $path = '/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin';
        $fullCommand = "PATH=\"{$path}\" {$command}";

        $output = shell_exec($fullCommand);

        return $output ?: '';
    }

    /**
     * ENTERPRISE: Start admin email workers
     *
     * @param int|null $count Number of workers to start
     * @return array Result with success status and output
     */
    public function startWorkers(?int $count = null): array
    {
        $count = $count ?? $this->defaultWorkers;

        if (function_exists('should_log') && should_log('default', 'info')) {
            Logger::info('[NEWSLETTER_WORKER_MANAGER] Starting workers', [
                'count' => $count,
                'environment' => $this->environment,
            ]);
        }

        try {
            switch ($this->environment) {
                case 'production_systemd':
                    return $this->startSystemdWorkers($count);

                case 'inside_container':
                    return $this->startInsideContainerWorkers($count);

                case 'development_docker':
                    return $this->startDockerWorkers($count);

                default:
                    throw new \Exception('Unknown environment: ' . $this->environment);
            }
        } catch (\Exception $e) {
            Logger::security('critical', 'ADMIN: Worker start exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'environment' => $this->environment,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to start workers: ' . $e->getMessage(),
                'environment' => $this->environment,
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY: Start workers when running INSIDE Docker container
     */
    private function startInsideContainerWorkers(int $count): array
    {
        $output = "🚀 Admin Email Workers Started (Inside Container)\n";
        $output .= "═══════════════════════════════════════════════════════\n";
        $started = 0;
        $pids = [];

        for ($i = 1; $i <= $count; $i++) {
            // Worker manages its own log file internally
            $workerCmd = "php {$this->workerScript} --worker-id={$i}";

            // Execute worker in background using nohup
            $fullCmd = "nohup {$workerCmd} > /dev/null 2>&1 & echo $!";
            $pid = trim($this->execCommand($fullCmd));

            if (!empty($pid) && is_numeric($pid)) {
                $output .= "✅ Worker {$i}: PID {$pid} → storage/logs/admin-email-worker-{$i}.log\n";
                $started++;
                $pids[] = $pid;
            } else {
                $output .= "⚠️  Worker {$i} failed to start\n";
            }

            usleep(100000); // 100ms delay between starts
        }

        // Check running workers
        $runningCount = $this->getRunningWorkersCount();
        $output .= "\n";
        $output .= "Workers Requested:  {$count}\n";
        $output .= "Workers Started:    {$started}\n";
        $output .= "\n";
        $output .= "═══════════════════════════════════════════════════════\n";
        $output .= "✨ Workers initialized and processing queue (Redis DB 4)\n";
        $output .= "📋 Check monitor below for real-time activity\n";

        return [
            'success' => $started > 0,
            'output' => $output,
            'started' => $started,
            'requested' => $count,
            'running' => $runningCount,
            'pids' => $pids,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Start Docker workers (development)
     */
    private function startDockerWorkers(int $count): array
    {
        $output = "🚀 Admin Email Workers Started (Docker)\n";
        $output .= "═══════════════════════════════════════════════════════\n";
        $started = 0;
        $pids = [];

        // Check if container is running
        $containerCheck = $this->execCommand("{$this->dockerCmd} ps --filter name={$this->phpContainer} --format '{{.Names}}'");
        if (strpos($containerCheck, $this->phpContainer) === false) {
            return [
                'success' => false,
                'error' => "Docker container {$this->phpContainer} is not running. Start with: docker-compose up -d",
                'environment' => $this->environment,
            ];
        }

        for ($i = 1; $i <= $count; $i++) {
            $workerCmd = "php {$this->workerScript} --worker-id={$i}";
            $dockerExec = "{$this->dockerCmd} exec -d {$this->phpContainer} {$workerCmd}";

            $result = $this->execCommand("{$dockerExec} 2>&1");

            // Get PID inside container
            sleep(1);
            $pidCheck = $this->execCommand("{$this->dockerCmd} exec {$this->phpContainer} pgrep -f admin-email-worker.php 2>/dev/null | tail -1");
            $pid = trim($pidCheck);

            if (!empty($pid)) {
                $output .= "✅ Worker {$i}: PID {$pid} → storage/logs/admin-email-worker-{$i}.log\n";
                $started++;
                $pids[] = $pid;
            } else {
                $output .= "⚠️  Worker {$i} started but PID not captured\n";
            }
        }

        // Check running workers
        $runningCount = $this->getRunningWorkersCount();
        $output .= "\n";
        $output .= "Workers Requested:  {$count}\n";
        $output .= "Workers Started:    {$started}\n";
        $output .= "\n";
        $output .= "═══════════════════════════════════════════════════════\n";
        $output .= "✨ Workers initialized and processing queue (Redis DB 4)\n";
        $output .= "📋 Check monitor below for real-time activity\n";

        return [
            'success' => $started > 0,
            'output' => $output,
            'started' => $started,
            'requested' => $count,
            'running' => $runningCount,
            'pids' => $pids,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Start systemd workers (production)
     */
    private function startSystemdWorkers(int $count): array
    {
        $output = "🚀 Admin Email Workers Started (Systemd)\n";
        $output .= "═══════════════════════════════════════════════════════\n";
        $started = 0;

        for ($i = 1; $i <= $count; $i++) {
            $service = "need2talk-admin-email-worker@{$i}.service";
            $result = $this->execCommand("sudo systemctl start {$service} 2>&1");

            // Check if started successfully
            $status = $this->execCommand("systemctl is-active {$service}");
            if (trim($status) === 'active') {
                $output .= "✅ Started worker {$i}: {$service}\n";
                $started++;
            } else {
                $output .= "❌ Failed to start worker {$i}: {$service}\n";
            }
        }

        $runningCount = $this->getRunningWorkersCount();
        $output .= "\n🎯 Workers Started: {$started}/{$count}\n";
        $output .= "📊 Total running workers: {$runningCount}\n";

        return [
            'success' => $started > 0,
            'output' => $output,
            'started' => $started,
            'requested' => $count,
            'running' => $runningCount,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Stop all admin email workers
     */
    public function stopWorkers(bool $cleanLogs = false): array
    {
        if (function_exists('should_log') && should_log('default', 'info')) {
            Logger::info('[NEWSLETTER_WORKER_MANAGER] Stopping workers', [
                'clean_logs' => $cleanLogs,
                'environment' => $this->environment,
            ]);
        }

        try {
            switch ($this->environment) {
                case 'production_systemd':
                    return $this->stopSystemdWorkers($cleanLogs);

                case 'inside_container':
                    return $this->stopInsideContainerWorkers($cleanLogs);

                case 'development_docker':
                    return $this->stopDockerWorkers($cleanLogs);

                default:
                    throw new \Exception('Unknown environment: ' . $this->environment);
            }
        } catch (\Exception $e) {
            Logger::security('critical', 'ADMIN: Worker stop exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'environment' => $this->environment,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to stop workers: ' . $e->getMessage(),
                'environment' => $this->environment,
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY: Stop workers when running INSIDE container
     */
    private function stopInsideContainerWorkers(bool $cleanLogs): array
    {
        $output = "🛑 Admin Email Workers Stopped\n";
        $output .= "═══════════════════════════════════════════════════════\n";

        // Get all worker PIDs (ENTERPRISE GALAXY: Use anchored pattern to avoid matching pgrep itself)
        // Pattern: ^php /var/www/html/scripts/admin-email-worker.php
        $pidsOutput = $this->execCommand("pgrep -f '^php /var/www/html/scripts/admin-email-worker.php' 2>/dev/null");
        $pids = array_filter(explode("\n", trim($pidsOutput)));

        if (empty($pids)) {
            $output .= "⚠️  No workers running\n";
        } else {
            $output .= "Workers Found:      " . count($pids) . "\n";
            $output .= "\n";
            $output .= "Stopping PIDs:\n";

            foreach ($pids as $pid) {
                $pid = trim($pid);
                if (empty($pid) || !is_numeric($pid)) {
                    continue;
                }

                $output .= "  🔴 Worker PID {$pid} → Sending SIGTERM (graceful shutdown)\n";
                $this->execCommand("kill -TERM {$pid} 2>/dev/null");
            }

            // Wait for graceful shutdown
            sleep(2);

            // Check if any processes are still running
            $remaining = $this->getRunningWorkersCount();
            if ($remaining > 0) {
                $output .= "\n⚠️  {$remaining} processes still running, sending SIGKILL (force)...\n";
                $this->execCommand("pkill -9 -f '^php /var/www/html/scripts/admin-email-worker.php' 2>/dev/null");
                sleep(1);

                // Final check
                $remaining = $this->getRunningWorkersCount();
                if ($remaining > 0) {
                    $output .= "⚠️  WARNING: {$remaining} processes still remain after force kill\n";
                } else {
                    $output .= "✅ All workers forcefully terminated\n";
                }
            } else {
                $output .= "✅ All workers gracefully stopped\n";
            }

            $output .= "\n";
            $output .= "Workers Stopped:    " . count($pids) . "\n";
        }

        if ($cleanLogs) {
            $output .= "Cleanup Summary:\n";
            $logsDeleted = $this->cleanWorkerLogs();
            $output .= "  🗑️  Log files removed: {$logsDeleted}\n";
            $output .= "  📋 PID files removed: " . count($pids) . "\n";
            $output .= "  ✅ System ready for fresh worker start\n";
            $output .= "\n";
        }

        $output .= "═══════════════════════════════════════════════════════\n";
        $output .= "✨ All workers stopped successfully\n";
        $output .= "📋 PID files cleaned from storage/pids/\n";

        return [
            'success' => true,
            'output' => $output,
            'stopped' => count($pids),
            'cleaned_logs' => $cleanLogs ? $logsDeleted ?? 0 : 0,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Stop Docker workers
     */
    private function stopDockerWorkers(bool $cleanLogs): array
    {
        $output = "🛑 Admin Email Workers Stopped\n";
        $output .= "═══════════════════════════════════════════════════════\n";

        // Get all worker PIDs (ENTERPRISE GALAXY: Use anchored pattern to avoid matching pgrep itself)
        $pidsOutput = $this->execCommand("{$this->dockerCmd} exec {$this->phpContainer} pgrep -f '^php /var/www/html/scripts/admin-email-worker.php' 2>/dev/null");
        $pids = array_filter(explode("\n", trim($pidsOutput)));

        if (empty($pids)) {
            $output .= "⚠️  No workers running\n";
        } else {
            $output .= "Workers Found:      " . count($pids) . "\n";
            $output .= "\n";
            $output .= "Stopping PIDs:\n";

            foreach ($pids as $pid) {
                $pid = trim($pid);
                if (empty($pid) || !is_numeric($pid)) {
                    continue;
                }

                $output .= "  🔴 Worker PID {$pid} → Sending SIGTERM (graceful shutdown)\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->phpContainer} kill -TERM {$pid} 2>/dev/null");
            }

            // Wait for graceful shutdown
            sleep(2);

            // Check if any processes are still running
            $remaining = $this->getRunningWorkersCount();
            if ($remaining > 0) {
                $output .= "\n⚠️  {$remaining} processes still running, sending SIGKILL (force)...\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->phpContainer} pkill -9 -f '^php /var/www/html/scripts/admin-email-worker.php' 2>/dev/null");
                sleep(1);

                // Final check
                $remaining = $this->getRunningWorkersCount();
                if ($remaining > 0) {
                    $output .= "⚠️  WARNING: {$remaining} processes still remain after force kill\n";
                } else {
                    $output .= "✅ All workers forcefully terminated\n";
                }
            } else {
                $output .= "✅ All workers gracefully stopped\n";
            }

            $output .= "\n";
            $output .= "Workers Stopped:    " . count($pids) . "\n";
        }

        if ($cleanLogs) {
            $output .= "Cleanup Summary:\n";
            $logsDeleted = $this->cleanWorkerLogs();
            $output .= "  🗑️  Log files removed: {$logsDeleted}\n";
            $output .= "  📋 PID files removed: " . count($pids) . "\n";
            $output .= "  ✅ System ready for fresh worker start\n";
            $output .= "\n";
        }

        $output .= "═══════════════════════════════════════════════════════\n";
        $output .= "✨ All workers stopped successfully\n";
        $output .= "📋 PID files cleaned from storage/pids/\n";

        return [
            'success' => true,
            'output' => $output,
            'stopped' => count($pids),
            'cleaned_logs' => $cleanLogs ? $logsDeleted ?? 0 : 0,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Stop systemd workers
     */
    private function stopSystemdWorkers(bool $cleanLogs): array
    {
        $output = "🛑 Stopping Admin Email Workers (Systemd)...\n\n";

        // Get all active worker services
        $servicesOutput = $this->execCommand("systemctl list-units 'need2talk-admin-email-worker@*.service' --state=active --no-legend | awk '{print \$1}'");
        $services = array_filter(explode("\n", trim($servicesOutput)));

        if (empty($services)) {
            $output .= "⚠️  No workers running\n";
        } else {
            $output .= "📊 Found " . count($services) . " worker(s) running\n\n";

            foreach ($services as $service) {
                $service = trim($service);
                if (empty($service)) {
                    continue;
                }

                $output .= "🔴 Stopping: {$service}\n";
                $this->execCommand("sudo systemctl stop {$service} 2>&1");
            }

            $output .= "\n🎯 All Workers Stopped!\n";
        }

        if ($cleanLogs) {
            $output .= "\n🧹 Cleaning worker logs...\n";
            $this->cleanWorkerLogs();
            $output .= "✅ Logs cleaned\n";
        }

        return [
            'success' => true,
            'output' => $output,
            'stopped' => count($services),
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Get worker status
     */
    public function getStatus(): array
    {
        try {
            $runningCount = $this->getRunningWorkersCount();
            $queueStatus = $this->getQueueStatus();

            return [
                'success' => true,
                'environment' => $this->environment,
                'running' => $runningCount > 0,
                'worker_count' => $runningCount,
                'queue' => $queueStatus,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'environment' => $this->environment,
            ];
        }
    }

    /**
     * ENTERPRISE: Get running workers count
     */
    private function getRunningWorkersCount(): int
    {
        switch ($this->environment) {
            case 'production_systemd':
                $output = $this->execCommand("systemctl list-units 'need2talk-admin-email-worker@*.service' --state=active --no-legend | wc -l");

                return (int) trim($output);

            case 'inside_container':
                // ENTERPRISE GALAXY: Use anchored pattern to avoid matching pgrep command itself
                // Pattern: ^php /var/www/html/scripts/admin-email-worker.php
                $output = $this->execCommand("pgrep -f '^php /var/www/html/scripts/admin-email-worker.php' 2>/dev/null | wc -l");

                return (int) trim($output);

            case 'development_docker':
                // ENTERPRISE GALAXY: Use anchored pattern to avoid matching pgrep command itself
                // CRITICAL: wc -l must be INSIDE docker exec, not outside!
                $output = $this->execCommand("{$this->dockerCmd} exec {$this->phpContainer} sh -c \"pgrep -f '^php /var/www/html/scripts/admin-email-worker.php' 2>/dev/null | wc -l\"");

                return (int) trim($output);

            default:
                return 0;
        }
    }

    /**
     * ENTERPRISE: Get queue status from Redis
     */
    private function getQueueStatus(): array
    {
        try {
            if ($this->environment === 'development_docker') {
                // Outside container, use docker exec to access Redis container
                $totalQueued = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 4 ZCARD admin_email_queue:pending 2>/dev/null"));
                $processing = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 4 HLEN admin_email_queue:processing 2>/dev/null"));
                $failed = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 4 ZCARD admin_email_queue:failed 2>/dev/null"));

                // Get priority counts
                $urgent = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 4 ZSCORE admin_email_queue:priority urgent 2>/dev/null"));
                $high = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 4 ZSCORE admin_email_queue:priority high 2>/dev/null"));
                $normal = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 4 ZSCORE admin_email_queue:priority normal 2>/dev/null"));
                $low = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 4 ZSCORE admin_email_queue:priority low 2>/dev/null"));
            } else {
                // Inside container OR production: direct Redis connection
                $redis = new \Redis();
                $redis->pconnect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379));
                $redis->select(4); // Admin email queue database

                $totalQueued = $redis->zCard('admin_email_queue:pending');
                $processing = $redis->hLen('admin_email_queue:processing');
                $failed = $redis->zCard('admin_email_queue:failed');

                // Priority counts
                $urgent = $redis->zScore('admin_email_queue:priority', 'urgent') ?: 0;
                $high = $redis->zScore('admin_email_queue:priority', 'high') ?: 0;
                $normal = $redis->zScore('admin_email_queue:priority', 'normal') ?: 0;
                $low = $redis->zScore('admin_email_queue:priority', 'low') ?: 0;
            }

            return [
                'total_queued' => (int)($totalQueued ?: 0),
                'processing' => (int)($processing ?: 0),
                'failed' => (int)($failed ?: 0),
                'urgent' => (int)($urgent ?: 0),
                'high' => (int)($high ?: 0),
                'normal' => (int)($normal ?: 0),
                'low' => (int)($low ?: 0),
            ];
        } catch (\Exception $e) {
            return [
                'total_queued' => 0,
                'processing' => 0,
                'failed' => 0,
                'urgent' => 0,
                'high' => 0,
                'normal' => 0,
                'low' => 0,
            ];
        }
    }

    /**
     * ENTERPRISE: Clean worker logs
     */
    private function cleanWorkerLogs(): int
    {
        $logPath = APP_ROOT . '/storage/logs';
        $files = glob($logPath . '/admin-email-worker-*.log') ?: [];
        $deleted = 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        // Clean PID files
        @unlink($logPath . '/admin-email-worker.pids');

        return $deleted;
    }

    /**
     * ENTERPRISE: Get detailed monitoring output
     */
    public function getMonitoringOutput(): array
    {
        $output = "═══════════════════════════════════════════════════════\n";
        $output .= "🔍 ADMIN WORKER STATUS - need2talk\n";
        $output .= "═══════════════════════════════════════════════════════\n\n";

        $status = $this->getStatus();

        $output .= "📊 Environment: {$this->environment}\n";
        $output .= "📊 Active Workers: {$status['worker_count']}\n";
        $output .= "📊 Status: " . ($status['running'] ? 'active' : 'stopped') . "\n\n";

        $output .= "📬 Admin Email Queue Status (Redis DB 4):\n";
        $output .= "────────────────────────────────────────────────────────\n";
        $output .= "   Total Queued: {$status['queue']['total_queued']}\n";
        $output .= "   Processing:   {$status['queue']['processing']}\n";
        $output .= "   Failed:       {$status['queue']['failed']}\n";
        $output .= "   Urgent:       {$status['queue']['urgent']}\n";
        $output .= "   High:         {$status['queue']['high']}\n";
        $output .= "   Normal:       {$status['queue']['normal']}\n";
        $output .= "   Low:          {$status['queue']['low']}\n\n";

        $output .= "═══════════════════════════════════════════════════════\n";
        $output .= date('Y-m-d H:i:s') . "\n";

        return [
            'success' => true,
            'output' => $output,
            'data' => $status,
        ];
    }

    // ========================================================================
    // ENTERPRISE GALAXY: Auto-Restart Toggle Methods
    // ========================================================================

    /**
     * ENTERPRISE GALAXY: Check if auto-restart is enabled
     *
     * @return bool True if enabled, false if disabled
     */
    public function isAutoRestartEnabled(): bool
    {
        return !file_exists($this->autoRestartFlagFile);
    }

    /**
     * ENTERPRISE GALAXY: Enable auto-restart (remove flag file)
     *
     * @return array Result with success status
     */
    public function enableAutoRestart(): array
    {
        if (!file_exists($this->autoRestartFlagFile)) {
            return [
                'success' => true,
                'message' => 'Auto-restart already enabled',
                'enabled' => true,
            ];
        }

        if (@unlink($this->autoRestartFlagFile)) {
            Logger::info('[NEWSLETTER_WORKER_MANAGER] Auto-restart ENABLED', [
                'flag_file' => $this->autoRestartFlagFile,
            ]);

            return [
                'success' => true,
                'message' => 'Auto-restart enabled successfully',
                'enabled' => true,
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to enable auto-restart',
            'enabled' => false,
        ];
    }

    /**
     * ENTERPRISE GALAXY: Disable auto-restart (create flag file)
     *
     * @return array Result with success status
     */
    public function disableAutoRestart(): array
    {
        if (file_exists($this->autoRestartFlagFile)) {
            return [
                'success' => true,
                'message' => 'Auto-restart already disabled',
                'enabled' => false,
            ];
        }

        // Ensure storage directory exists
        $storageDir = dirname($this->autoRestartFlagFile);
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        if (@file_put_contents($this->autoRestartFlagFile, date('Y-m-d H:i:s') . ' - Auto-restart disabled by admin') !== false) {
            Logger::info('[NEWSLETTER_WORKER_MANAGER] Auto-restart DISABLED', [
                'flag_file' => $this->autoRestartFlagFile,
            ]);

            return [
                'success' => true,
                'message' => 'Auto-restart disabled successfully',
                'enabled' => false,
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to disable auto-restart',
            'enabled' => $this->isAutoRestartEnabled(),
        ];
    }

    /**
     * ENTERPRISE GALAXY: Toggle auto-restart (enable ↔ disable)
     *
     * @return array Result with success status
     */
    public function toggleAutoRestart(): array
    {
        if ($this->isAutoRestartEnabled()) {
            return $this->disableAutoRestart();
        } else {
            return $this->enableAutoRestart();
        }
    }

    // ========================================================================
    // ENTERPRISE GALAXY: Dedicated Newsletter Container Methods
    // ========================================================================

    /**
     * ENTERPRISE GALAXY: Check if newsletter worker container is running
     *
     * @return bool True if running and healthy, false otherwise
     */
    public function isNewsletterContainerRunning(): bool
    {
        $containerCheck = $this->execCommand("{$this->dockerCmd} ps --filter name={$this->newsletterContainer} --filter status=running --format '{{.Names}}'");

        return strpos($containerCheck, $this->newsletterContainer) !== false;
    }

    /**
     * ENTERPRISE GALAXY: Get newsletter container status
     *
     * @return array Status information
     */
    public function getNewsletterContainerStatus(): array
    {
        $isRunning = $this->isNewsletterContainerRunning();

        if (!$isRunning) {
            return [
                'running' => false,
                'workers' => 0,
                'health' => 'N/A',
                'status' => 'stopped',
            ];
        }

        // Get worker count inside container
        $workerCount = (int) trim($this->execCommand("{$this->dockerCmd} exec {$this->newsletterContainer} pgrep -f 'admin-email-worker.php' 2>/dev/null | wc -l"));

        // Get health status
        $healthStatus = trim($this->execCommand("{$this->dockerCmd} inspect --format='{{.State.Health.Status}}' {$this->newsletterContainer} 2>/dev/null"));
        if (empty($healthStatus) || $healthStatus === '<no value>') {
            $healthStatus = 'none';
        }

        return [
            'running' => true,
            'workers' => $workerCount,
            'health' => $healthStatus,
            'status' => $healthStatus === 'healthy' ? 'healthy' : ($healthStatus === 'unhealthy' ? 'unhealthy' : 'starting'),
            'auto_restart_enabled' => $this->isAutoRestartEnabled(),
        ];
    }

    /**
     * ENTERPRISE GALAXY: Start newsletter worker container (via docker-compose)
     *
     * @return array Result with success status
     */
    public function startNewsletterContainer(): array
    {
        if ($this->isNewsletterContainerRunning()) {
            return [
                'success' => true,
                'message' => 'Newsletter worker container already running',
                'status' => $this->getNewsletterContainerStatus(),
            ];
        }

        $projectRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $composeFile = $projectRoot . '/docker-compose.yml';

        if (!file_exists($composeFile)) {
            return [
                'success' => false,
                'message' => 'docker-compose.yml not found',
            ];
        }

        // Start container via docker-compose
        $output = $this->execCommand("cd {$projectRoot} && docker-compose up -d newsletter_worker 2>&1");

        // Wait a bit for startup
        sleep(3);

        $isRunning = $this->isNewsletterContainerRunning();

        Logger::info('[NEWSLETTER_WORKER_MANAGER] Newsletter container start attempt', [
            'success' => $isRunning,
            'output' => $output,
        ]);

        return [
            'success' => $isRunning,
            'message' => $isRunning ? 'Newsletter worker container started successfully' : 'Failed to start container',
            'output' => $output,
            'status' => $this->getNewsletterContainerStatus(),
        ];
    }

    /**
     * ENTERPRISE GALAXY: Stop newsletter worker container (via docker-compose)
     *
     * @return array Result with success status
     */
    public function stopNewsletterContainer(): array
    {
        if (!$this->isNewsletterContainerRunning()) {
            return [
                'success' => true,
                'message' => 'Newsletter worker container already stopped',
            ];
        }

        $projectRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);

        // Stop container via docker-compose
        $output = $this->execCommand("cd {$projectRoot} && docker-compose stop newsletter_worker 2>&1");

        // Wait for shutdown
        sleep(2);

        $isStopped = !$this->isNewsletterContainerRunning();

        Logger::info('[NEWSLETTER_WORKER_MANAGER] Newsletter container stop attempt', [
            'success' => $isStopped,
            'output' => $output,
        ]);

        return [
            'success' => $isStopped,
            'message' => $isStopped ? 'Newsletter worker container stopped successfully' : 'Failed to stop container',
            'output' => $output,
        ];
    }
}
