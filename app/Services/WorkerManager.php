<?php

namespace Need2Talk\Services;

/**
 * ENTERPRISE GALAXY LEVEL: Worker Manager
 *
 * Unified worker management for both development (Docker) and production (systemd).
 * Auto-detects environment and uses appropriate commands.
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
class WorkerManager
{
    private string $environment;
    private string $dockerCmd;
    private string $phpContainer = 'need2talk_php';
    private string $workerContainer = 'need2talk_worker'; // ENTERPRISE GALAXY: Dedicated worker container
    private string $redisContainer = 'need2talk_redis';
    private string $workerScript = '/var/www/html/scripts/email-worker.php';
    private int $defaultWorkers = 2;

    /**
     * ENTERPRISE: Constructor - Auto-detect environment
     */
    public function __construct()
    {
        $this->detectEnvironment();
        $this->dockerCmd = $this->findDockerCommand();

        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[WORKER_MANAGER] Initialized', [
                'environment' => $this->environment,
                'docker_cmd' => $this->dockerCmd,
            ]);
        }
    }

    /**
     * ENTERPRISE: Auto-detect if we're in Docker or systemd environment
     */
    private function detectEnvironment(): void
    {
        // ENTERPRISE GALAXY FIX: Detect if we're INSIDE a Docker container
        // When PHP-FPM runs inside a container, we can't use 'docker exec' (no docker-in-docker)
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

        // Check for need2talk systemd services
        $hasWorkerServices = false;
        if ($hasSystemd) {
            $output = $this->execCommand('systemctl list-unit-files "need2talk-email-worker@*.service" 2>/dev/null');
            $hasWorkerServices = !empty($output) && strpos($output, 'need2talk-email-worker@') !== false;
        }

        // Log detection results
        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[WORKER_MANAGER] Environment detection', [
                'inside_container' => $insideContainer,
                'has_systemd' => $hasSystemd,
                'has_worker_services' => $hasWorkerServices,
                'has_docker' => $hasDocker,
            ]);
        }

        // Determine environment
        if ($hasWorkerServices && $hasSystemd) {
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
     * ENTERPRISE: Start workers
     *
     * @param int|null $count Number of workers to start
     * @return array Result with success status and output
     */
    public function startWorkers(?int $count = null): array
    {
        $count = $count ?? $this->defaultWorkers;

        if (function_exists('should_log') && should_log('default', 'info')) {
            Logger::info('[WORKER_MANAGER] Starting workers', [
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

                case 'legacy_scripts':
                    return $this->startLegacyWorkers($count);

                default:
                    throw new \Exception('Unknown environment: ' . $this->environment);
            }
        } catch (\Exception $e) {
            Logger::security('critical', 'WORKER: Worker start exception', [
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
     * No docker-in-docker needed - execute workers directly!
     */
    /**
     * ENTERPRISE GALAXY: Start workers when running INSIDE container
     *
     * CRITICAL FIX: Workers run in DEDICATED need2talk_worker container,
     * so we must use docker exec to start them from PHP container
     */
    private function startInsideContainerWorkers(int $count): array
    {
        $output = "🚀 Starting {$count} Workers (Inside Container)...\n\n";
        $started = 0;
        $errors = [];

        for ($i = 1; $i <= $count; $i++) {
            $workerCmd = "php {$this->workerScript} --batch-size=50 --max-runtime=14400 --sleep-seconds=2";

            // ENTERPRISE GALAXY FIX: Execute worker in DEDICATED worker container using docker exec
            $fullCmd = "{$this->dockerCmd} exec -d {$this->workerContainer} sh -c '{$workerCmd}' 2>&1";
            $result = trim($this->execCommand($fullCmd));

            // Docker exec -d doesn't return PID, so we check if command succeeded
            if (strpos($result, 'Error') === false && strpos($result, 'error') === false) {
                $output .= "✅ Started worker {$i} in container {$this->workerContainer}\n";
                $started++;
            } else {
                $errors[] = "⚠️  Worker {$i} failed to start: {$result}";
                $output .= "⚠️  Worker {$i} failed to start\n";
            }

            sleep(1);
        }

        // Check running workers
        $runningCount = $this->getRunningWorkersCount();
        $output .= "\n🎯 Workers Started: {$started}/{$count}\n";
        $output .= "📊 Total running workers: {$runningCount}\n";
        $output .= "\nℹ️  Workers run in dedicated need2talk_worker container\n";
        $output .= "ℹ️  Failed email retry is integrated in workers\n";

        return [
            'success' => $started > 0,
            'output' => $output,
            'started' => $started,
            'requested' => $count,
            'running' => $runningCount,
            'errors' => $errors,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Start Docker workers (development)
     */
    private function startDockerWorkers(int $count): array
    {
        $output = "🚀 Starting {$count} Docker Enterprise Workers...\n\n";
        $started = 0;
        $errors = [];

        // Check if container is running
        $containerCheck = $this->execCommand("{$this->dockerCmd} ps --filter name={$this->workerContainer} --format '{{.Names}}'");
        if (strpos($containerCheck, $this->phpContainer) === false) {
            return [
                'success' => false,
                'error' => "Docker container {$this->workerContainer} is not running. Start with: docker-compose up -d",
                'environment' => $this->environment,
            ];
        }

        for ($i = 1; $i <= $count; $i++) {
            $workerCmd = "php {$this->workerScript} --batch-size=400 --max-runtime=14400 --sleep-seconds=2";
            $dockerExec = "{$this->dockerCmd} exec -d {$this->workerContainer} {$workerCmd}";

            $result = $this->execCommand("{$dockerExec} 2>&1");

            // Get PID inside container (ENTERPRISE TIPS: Use pgrep for BusyBox compatibility)
            sleep(1);
            $pidCheck = $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f email-worker.php 2>/dev/null | tail -1");
            $pid = trim($pidCheck);

            if (!empty($pid)) {
                $output .= "✅ Started worker {$i}: Container PID {$pid}\n";
                $started++;
            } else {
                $errors[] = "⚠️  Worker {$i} started but PID not captured";
                $output .= "⚠️  Worker {$i} started but PID not captured\n";
            }
        }

        // Check running workers
        $runningCount = $this->getRunningWorkersCount();
        $output .= "\n🎯 Workers Started: {$started}/{$count}\n";
        $output .= "📊 Total running workers: {$runningCount}\n";
        $output .= "\nℹ️  Workers run inside Docker container ({$this->workerContainer})\n";
        $output .= "ℹ️  Failed email retry is integrated in workers\n";

        return [
            'success' => $started > 0,
            'output' => $output,
            'started' => $started,
            'requested' => $count,
            'running' => $runningCount,
            'errors' => $errors,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Start systemd workers (production)
     */
    private function startSystemdWorkers(int $count): array
    {
        $output = "🚀 Starting {$count} Systemd Workers...\n\n";
        $started = 0;
        $errors = [];

        for ($i = 1; $i <= $count; $i++) {
            $service = "need2talk-email-worker@{$i}.service";
            $result = $this->execCommand("sudo systemctl start {$service} 2>&1");

            // Check if started successfully
            $status = $this->execCommand("systemctl is-active {$service}");
            if (trim($status) === 'active') {
                $output .= "✅ Started worker {$i}: {$service}\n";
                $started++;
            } else {
                $errors[] = "❌ Failed to start worker {$i}";
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
            'errors' => $errors,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Start legacy workers (fallback)
     */
    private function startLegacyWorkers(int $count): array
    {
        $script = APP_ROOT . '/scripts/start-workers-docker.sh';

        if (!file_exists($script)) {
            return [
                'success' => false,
                'error' => 'Legacy worker script not found',
                'environment' => $this->environment,
            ];
        }

        $output = $this->execCommand("sh {$script} {$count} 2>&1");

        return [
            'success' => true,
            'output' => $output ?: 'Workers started via legacy script',
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Stop all workers
     */
    public function stopWorkers(bool $cleanLogs = false): array
    {
        if (function_exists('should_log') && should_log('default', 'info')) {
            Logger::info('[WORKER_MANAGER] Stopping workers', [
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

                case 'legacy_scripts':
                    return $this->stopLegacyWorkers($cleanLogs);

                default:
                    throw new \Exception('Unknown environment: ' . $this->environment);
            }
        } catch (\Exception $e) {
            Logger::security('critical', 'WORKER: Worker stop exception', [
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
     *
     * CRITICAL FIX: Workers run in DEDICATED need2talk_worker container,
     * so we must use docker exec to reach them from PHP container
     */
    private function stopInsideContainerWorkers(bool $cleanLogs): array
    {
        $output = "🛑 Stopping Workers (Inside Container)...\n\n";

        // ENTERPRISE GALAXY FIX: Workers run in need2talk_worker container, not PHP container
        // Use docker exec from PHP container to reach worker container
        $pidsOutput = $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f email-worker.php 2>/dev/null");
        $pids = array_filter(explode("\n", trim($pidsOutput)));

        if (empty($pids)) {
            $output .= "⚠️  No workers running\n";
        } else {
            $output .= "📊 Found " . count($pids) . " worker(s) running\n\n";

            foreach ($pids as $pid) {
                $pid = trim($pid);
                if (empty($pid)) {
                    continue;
                }

                $output .= "🔴 Stopping worker PID: {$pid}\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} kill {$pid} 2>/dev/null");
            }

            sleep(1);

            // Force kill if any remaining
            $remaining = $this->getRunningWorkersCount();
            if ($remaining > 0) {
                $output .= "\n⚠️  {$remaining} processes still running, force killing...\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pkill -9 -f email-worker.php 2>/dev/null");
                sleep(1);
                $remaining = $this->getRunningWorkersCount();
            }

            $output .= "\n🎯 All Workers Stopped! (Remaining: {$remaining})\n";
        }

        if ($cleanLogs) {
            $output .= "\n🧹 Cleaning worker logs...\n";
            $this->cleanWorkerLogs();
            $output .= "✅ Logs cleaned\n";
        }

        return [
            'success' => true,
            'output' => $output,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Stop Docker workers
     */
    private function stopDockerWorkers(bool $cleanLogs): array
    {
        $output = "🛑 Stopping Docker Workers...\n\n";

        // Get all worker PIDs (ENTERPRISE TIPS: Use pgrep instead of ps aux for BusyBox compatibility)
        $pidsOutput = $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f email-worker.php 2>/dev/null");
        $pids = array_filter(explode("\n", trim($pidsOutput)));

        if (empty($pids)) {
            $output .= "⚠️  No workers running\n";
        } else {
            $output .= "📊 Found " . count($pids) . " worker(s) running\n\n";

            foreach ($pids as $pid) {
                $pid = trim($pid);
                if (empty($pid)) {
                    continue;
                }

                $output .= "🔴 Stopping worker PID: {$pid}\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} kill {$pid} 2>/dev/null");
            }

            sleep(1);

            // Force kill if any remaining
            $remaining = $this->getRunningWorkersCount();
            if ($remaining > 0) {
                $output .= "\n⚠️  {$remaining} processes still running, force killing...\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pkill -9 -f email-worker.php 2>/dev/null");
                sleep(1);
                $remaining = $this->getRunningWorkersCount();
            }

            $output .= "\n🎯 All Workers Stopped! (Remaining: {$remaining})\n";
        }

        if ($cleanLogs) {
            $output .= "\n🧹 Cleaning worker logs...\n";
            $this->cleanWorkerLogs();
            $output .= "✅ Logs cleaned\n";
        }

        return [
            'success' => true,
            'output' => $output,
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Stop systemd workers
     */
    private function stopSystemdWorkers(bool $cleanLogs): array
    {
        $output = "🛑 Stopping Systemd Workers...\n\n";

        // Get all active worker services
        $servicesOutput = $this->execCommand("systemctl list-units 'need2talk-email-worker@*.service' --state=active --no-legend | awk '{print \$1}'");
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
            'environment' => $this->environment,
        ];
    }

    /**
     * ENTERPRISE: Stop legacy workers
     */
    private function stopLegacyWorkers(bool $cleanLogs): array
    {
        $script = APP_ROOT . '/scripts/stop-workers-docker.sh';

        if (!file_exists($script)) {
            return [
                'success' => false,
                'error' => 'Legacy worker script not found',
                'environment' => $this->environment,
            ];
        }

        $cleanFlag = $cleanLogs ? '--clean' : '';
        $output = $this->execCommand("sh {$script} {$cleanFlag} 2>&1");

        return [
            'success' => true,
            'output' => $output ?: 'Workers stopped via legacy script',
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
                'workers' => [
                    'running' => $runningCount,
                    'status' => $runningCount > 0 ? 'active' : 'stopped',
                ],
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
                $output = $this->execCommand("systemctl list-units 'need2talk-email-worker@*.service' --state=active --no-legend | wc -l");

                return (int) trim($output);

            case 'inside_container':
                // ENTERPRISE GALAXY: PHP-FPM inside container with Docker CLI access
                // Use docker exec to check the DEDICATED worker container (not PHP container!)
                $output = $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f '^php /var/www/html/scripts/email-worker.php' 2>/dev/null | wc -l");

                return (int) trim($output);

            case 'development_docker':
                // ENTERPRISE GALAXY: Check DEDICATED worker container (not PHP container!)
                $output = $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f '^php /var/www/html/scripts/email-worker.php' 2>/dev/null | wc -l");

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
                $pending = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 2 ZCARD email_queue:pending 2>/dev/null"));
                $processing = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 2 HLEN email_queue:processing 2>/dev/null"));
                $failed = trim($this->execCommand("{$this->dockerCmd} exec {$this->redisContainer} redis-cli -n 2 ZCARD email_queue:failed 2>/dev/null"));
            } else {
                // Inside container OR production: direct Redis connection
                $redis = new \Redis();
                $redis->pconnect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379));
                $redis->select(2); // Email queue database

                $pending = $redis->zCard('email_queue:pending');
                $processing = $redis->hLen('email_queue:processing');
                $failed = $redis->zCard('email_queue:failed');
            }

            return [
                'pending' => (int)($pending ?: 0),
                'processing' => (int)($processing ?: 0),
                'failed' => (int)($failed ?: 0),
            ];
        } catch (\Exception $e) {
            return [
                'pending' => 'N/A',
                'processing' => 'N/A',
                'failed' => 'N/A',
            ];
        }
    }

    /**
     * ENTERPRISE: Clean worker logs
     */
    private function cleanWorkerLogs(): void
    {
        $logPath = APP_ROOT . '/storage/logs';
        $files = glob($logPath . '/worker_*.log') ?: [];

        foreach ($files as $file) {
            @unlink($file);
        }

        @unlink($logPath . '/docker-enterprise-workers.pids');
        @unlink($logPath . '/legacy-enterprise-workers.pids');
    }

    /**
     * ENTERPRISE: Get detailed monitoring output
     */
    public function getMonitoringOutput(): array
    {
        $output = "═══════════════════════════════════════════════════════\n";
        $output .= "🔍 WORKER STATUS - need2talk\n";
        $output .= "═══════════════════════════════════════════════════════\n\n";

        $status = $this->getStatus();

        $output .= "📊 Environment: {$this->environment}\n";
        $output .= "📊 Active Workers: {$status['workers']['running']}\n";
        $output .= "📊 Status: {$status['workers']['status']}\n\n";

        $output .= "📬 Email Queue Status:\n";
        $output .= "────────────────────────────────────────────────────────\n";
        $output .= "   Pending:    {$status['queue']['pending']}\n";
        $output .= "   Processing: {$status['queue']['processing']}\n";
        $output .= "   Failed:     {$status['queue']['failed']}\n\n";

        $output .= "═══════════════════════════════════════════════════════\n";
        $output .= date('Y-m-d H:M:S') . "\n";

        return [
            'success' => true,
            'output' => $output,
            'data' => $status,
        ];
    }
}
