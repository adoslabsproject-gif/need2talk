<?php

declare(strict_types=1);

namespace Need2Talk\Services;

/**
 * NotificationWorkerManager - Enterprise Notification Worker Management
 *
 * Manages notification queue workers with support for 1-4 scalable workers.
 * Auto-detects environment (Docker vs systemd vs legacy).
 *
 * Features:
 * - Start/stop/restart workers
 * - Status monitoring with queue stats
 * - Docker container integration
 * - Systemd integration (production)
 * - Health checks via Redis heartbeat
 *
 * @package Need2Talk\Services
 * @version 1.0.0 - Enterprise Galaxy V11.6
 */
class NotificationWorkerManager
{
    private string $environment;
    private string $dockerCmd;
    // ENTERPRISE GALAXY V11.6 FIX: Dedicated notification_worker container
    // NOTE: Docker Compose scalable services use pattern: need2talk-notification_worker-N
    private string $workerContainer = 'need2talk-notification_worker-1';
    private string $redisContainer = 'need2talk_redis';
    private string $workerScript = '/var/www/html/scripts/notification-worker.php';
    private int $defaultWorkers = 2;
    private int $maxWorkers = 4;

    public function __construct()
    {
        $this->detectEnvironment();
        $this->dockerCmd = $this->findDockerCommand();

        if (function_exists('should_log') && should_log('default', 'debug')) {
            Logger::debug('[NOTIFICATION_WORKER_MANAGER] Initialized', [
                'environment' => $this->environment,
                'docker_cmd' => $this->dockerCmd,
            ]);
        }
    }

    /**
     * Auto-detect environment (Docker, systemd, or legacy)
     */
    private function detectEnvironment(): void
    {
        $insideContainer = $this->isRunningInsideContainer();
        $systemdExists = file_exists('/run/systemd/system');
        $hasSystemd = $systemdExists && $this->commandExists('systemctl');
        $hasDocker = !$insideContainer && (
            file_exists('/usr/local/bin/docker') ||
            file_exists('/usr/bin/docker') ||
            $this->commandExists('docker')
        );

        // Check for need2talk notification worker services
        $hasWorkerServices = false;
        if ($hasSystemd) {
            $output = $this->execCommand('systemctl list-unit-files "need2talk-notification-worker@*.service" 2>/dev/null');
            $hasWorkerServices = !empty($output) && strpos($output, 'need2talk-notification-worker@') !== false;
        }

        if ($hasWorkerServices && $hasSystemd) {
            $this->environment = 'production_systemd';
        } elseif ($insideContainer) {
            $this->environment = 'inside_container';
        } elseif ($hasDocker) {
            $this->environment = 'development_docker';
        } else {
            $this->environment = 'legacy_scripts';
        }
    }

    /**
     * Detect if running inside a Docker container
     */
    private function isRunningInsideContainer(): bool
    {
        if (file_exists('/.dockerenv')) {
            return true;
        }

        if (file_exists('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'kubepods') !== false) {
                return true;
            }
        }

        $hostname = gethostname();
        if ($hostname && strlen($hostname) === 12 && ctype_xdigit($hostname)) {
            return true;
        }

        return false;
    }

    /**
     * Find docker command path
     */
    private function findDockerCommand(): string
    {
        $paths = [
            '/usr/local/bin/docker',
            '/usr/bin/docker',
            'docker',
        ];

        foreach ($paths as $path) {
            if ($path === 'docker' || file_exists($path)) {
                return $path;
            }
        }

        return 'docker';
    }

    /**
     * Check if command exists
     */
    private function commandExists(string $command): bool
    {
        $output = $this->execCommand("which {$command} 2>/dev/null");
        return !empty($output);
    }

    /**
     * Execute command with enhanced PATH
     */
    private function execCommand(string $command): string
    {
        $path = '/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin';
        $fullCommand = "PATH=\"{$path}\" {$command}";
        $output = shell_exec($fullCommand);
        return $output ?: '';
    }

    /**
     * Start notification workers
     *
     * @param int|null $count Number of workers to start (1-4)
     * @return array Result with success status and output
     */
    public function startWorkers(?int $count = null): array
    {
        $count = $count ?? $this->defaultWorkers;
        $count = min(max($count, 1), $this->maxWorkers);

        Logger::info('[NOTIFICATION_WORKER_MANAGER] Starting workers', [
            'count' => $count,
            'environment' => $this->environment,
        ]);

        try {
            return match ($this->environment) {
                'production_systemd' => $this->startSystemdWorkers($count),
                'inside_container' => $this->startInsideContainerWorkers($count),
                'development_docker' => $this->startDockerWorkers($count),
                default => $this->startLegacyWorkers($count),
            };
        } catch (\Exception $e) {
            Logger::error('[NOTIFICATION_WORKER_MANAGER] Start failed', [
                'error' => $e->getMessage(),
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
     * Start workers inside Docker container
     */
    private function startInsideContainerWorkers(int $count): array
    {
        $output = "Starting {$count} Notification Workers (Inside Container)...\n\n";
        $started = 0;
        $errors = [];

        for ($i = 1; $i <= $count; $i++) {
            $workerCmd = "php {$this->workerScript} --batch-size=50 --max-runtime=14400 --sleep=100";
            $fullCmd = "{$this->dockerCmd} exec -d {$this->workerContainer} sh -c '{$workerCmd}' 2>&1";
            $result = trim($this->execCommand($fullCmd));

            if (strpos($result, 'Error') === false && strpos($result, 'error') === false) {
                $output .= "Started worker {$i} in container {$this->workerContainer}\n";
                $started++;
            } else {
                $errors[] = "Worker {$i} failed to start: {$result}";
                $output .= "Worker {$i} failed to start\n";
            }

            usleep(500000); // 500ms between starts
        }

        $runningCount = $this->getRunningWorkersCount();
        $output .= "\nWorkers Started: {$started}/{$count}\n";
        $output .= "Total running workers: {$runningCount}\n";

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
     * Start Docker workers (development)
     */
    private function startDockerWorkers(int $count): array
    {
        $output = "Starting {$count} Docker Notification Workers...\n\n";
        $started = 0;
        $errors = [];

        // Check if container is running
        $containerCheck = $this->execCommand("{$this->dockerCmd} ps --filter name={$this->workerContainer} --format '{{.Names}}'");
        if (strpos($containerCheck, $this->workerContainer) === false) {
            return [
                'success' => false,
                'error' => "Docker container {$this->workerContainer} is not running. Start with: docker-compose up -d",
                'environment' => $this->environment,
            ];
        }

        for ($i = 1; $i <= $count; $i++) {
            $workerCmd = "php {$this->workerScript} --batch-size=50 --max-runtime=14400 --sleep=100";
            $dockerExec = "{$this->dockerCmd} exec -d {$this->workerContainer} {$workerCmd}";

            $this->execCommand("{$dockerExec} 2>&1");

            usleep(500000);
            $pidCheck = $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f notification-worker.php 2>/dev/null | tail -1");
            $pid = trim($pidCheck);

            if (!empty($pid)) {
                $output .= "Started worker {$i}: Container PID {$pid}\n";
                $started++;
            } else {
                $errors[] = "Worker {$i} started but PID not captured";
                $output .= "Worker {$i} started but PID not captured\n";
            }
        }

        $runningCount = $this->getRunningWorkersCount();
        $output .= "\nWorkers Started: {$started}/{$count}\n";
        $output .= "Total running workers: {$runningCount}\n";

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
     * Start systemd workers (production)
     */
    private function startSystemdWorkers(int $count): array
    {
        $output = "Starting {$count} Systemd Notification Workers...\n\n";
        $started = 0;
        $errors = [];

        for ($i = 1; $i <= $count; $i++) {
            $service = "need2talk-notification-worker@{$i}.service";
            $this->execCommand("sudo systemctl start {$service} 2>&1");

            $status = $this->execCommand("systemctl is-active {$service}");
            if (trim($status) === 'active') {
                $output .= "Started worker {$i}: {$service}\n";
                $started++;
            } else {
                $errors[] = "Failed to start worker {$i}";
                $output .= "Failed to start worker {$i}: {$service}\n";
            }
        }

        $runningCount = $this->getRunningWorkersCount();
        $output .= "\nWorkers Started: {$started}/{$count}\n";
        $output .= "Total running workers: {$runningCount}\n";

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
     * Start legacy workers (fallback)
     */
    private function startLegacyWorkers(int $count): array
    {
        $script = APP_ROOT . '/scripts/notification-worker.php';

        if (!file_exists($script)) {
            return [
                'success' => false,
                'error' => 'Notification worker script not found',
                'environment' => $this->environment,
            ];
        }

        $output = "Starting {$count} Legacy Notification Workers...\n\n";
        $started = 0;

        for ($i = 1; $i <= $count; $i++) {
            $cmd = "php {$script} --batch-size=50 --max-runtime=14400 > /dev/null 2>&1 &";
            exec($cmd);
            $started++;
            $output .= "Started worker {$i}\n";
            usleep(500000);
        }

        return [
            'success' => $started > 0,
            'output' => $output,
            'started' => $started,
            'environment' => $this->environment,
        ];
    }

    /**
     * Stop all notification workers
     */
    public function stopWorkers(): array
    {
        Logger::info('[NOTIFICATION_WORKER_MANAGER] Stopping workers', [
            'environment' => $this->environment,
        ]);

        try {
            return match ($this->environment) {
                'production_systemd' => $this->stopSystemdWorkers(),
                'inside_container' => $this->stopInsideContainerWorkers(),
                'development_docker' => $this->stopDockerWorkers(),
                default => $this->stopLegacyWorkers(),
            };
        } catch (\Exception $e) {
            Logger::error('[NOTIFICATION_WORKER_MANAGER] Stop failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to stop workers: ' . $e->getMessage(),
                'environment' => $this->environment,
            ];
        }
    }

    /**
     * Stop workers inside Docker container
     */
    private function stopInsideContainerWorkers(): array
    {
        $output = "Stopping Notification Workers (Inside Container)...\n\n";

        $pidsOutput = $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f notification-worker.php 2>/dev/null");
        $pids = array_filter(explode("\n", trim($pidsOutput)));

        if (empty($pids)) {
            $output .= "No workers running\n";
        } else {
            $output .= "Found " . count($pids) . " worker(s) running\n\n";

            foreach ($pids as $pid) {
                $pid = trim($pid);
                if (empty($pid)) continue;

                $output .= "Stopping worker PID: {$pid}\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} kill {$pid} 2>/dev/null");
            }

            sleep(1);

            $remaining = $this->getRunningWorkersCount();
            if ($remaining > 0) {
                $output .= "\n{$remaining} processes still running, force killing...\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pkill -9 -f notification-worker.php 2>/dev/null");
                sleep(1);
                $remaining = $this->getRunningWorkersCount();
            }

            $output .= "\nAll Workers Stopped! (Remaining: {$remaining})\n";
        }

        return [
            'success' => true,
            'output' => $output,
            'environment' => $this->environment,
        ];
    }

    /**
     * Stop Docker workers
     */
    private function stopDockerWorkers(): array
    {
        $output = "Stopping Docker Notification Workers...\n\n";

        $pidsOutput = $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f notification-worker.php 2>/dev/null");
        $pids = array_filter(explode("\n", trim($pidsOutput)));

        if (empty($pids)) {
            $output .= "No workers running\n";
        } else {
            $output .= "Found " . count($pids) . " worker(s) running\n\n";

            foreach ($pids as $pid) {
                $pid = trim($pid);
                if (empty($pid)) continue;

                $output .= "Stopping worker PID: {$pid}\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} kill {$pid} 2>/dev/null");
            }

            sleep(1);

            $remaining = $this->getRunningWorkersCount();
            if ($remaining > 0) {
                $output .= "\n{$remaining} processes still running, force killing...\n";
                $this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pkill -9 -f notification-worker.php 2>/dev/null");
                sleep(1);
                $remaining = $this->getRunningWorkersCount();
            }

            $output .= "\nAll Workers Stopped! (Remaining: {$remaining})\n";
        }

        return [
            'success' => true,
            'output' => $output,
            'environment' => $this->environment,
        ];
    }

    /**
     * Stop systemd workers
     */
    private function stopSystemdWorkers(): array
    {
        $output = "Stopping Systemd Notification Workers...\n\n";

        $servicesOutput = $this->execCommand("systemctl list-units 'need2talk-notification-worker@*.service' --state=active --no-legend | awk '{print \$1}'");
        $services = array_filter(explode("\n", trim($servicesOutput)));

        if (empty($services)) {
            $output .= "No workers running\n";
        } else {
            $output .= "Found " . count($services) . " worker(s) running\n\n";

            foreach ($services as $service) {
                $service = trim($service);
                if (empty($service)) continue;

                $output .= "Stopping: {$service}\n";
                $this->execCommand("sudo systemctl stop {$service} 2>&1");
            }

            $output .= "\nAll Workers Stopped!\n";
        }

        return [
            'success' => true,
            'output' => $output,
            'environment' => $this->environment,
        ];
    }

    /**
     * Stop legacy workers
     */
    private function stopLegacyWorkers(): array
    {
        $output = "Stopping Legacy Notification Workers...\n\n";

        exec("pkill -f notification-worker.php 2>/dev/null");
        $output .= "Sent kill signal to all notification workers\n";

        return [
            'success' => true,
            'output' => $output,
            'environment' => $this->environment,
        ];
    }

    /**
     * Get worker status
     */
    public function getStatus(): array
    {
        try {
            $runningCount = $this->getRunningWorkersCount();
            $queueStats = $this->getQueueStats();

            return [
                'success' => true,
                'environment' => $this->environment,
                'workers' => [
                    'running' => $runningCount,
                    'max' => $this->maxWorkers,
                    'status' => $runningCount > 0 ? 'active' : 'stopped',
                ],
                'queue' => $queueStats,
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
     * Get running workers count
     */
    public function getRunningWorkersCount(): int
    {
        return match ($this->environment) {
            'production_systemd' => (int)trim($this->execCommand("systemctl list-units 'need2talk-notification-worker@*.service' --state=active --no-legend | wc -l")),
            'inside_container', 'development_docker' => (int)trim($this->execCommand("{$this->dockerCmd} exec {$this->workerContainer} pgrep -f '^php /var/www/html/scripts/notification-worker.php' 2>/dev/null | wc -l")),
            default => 0,
        };
    }

    /**
     * Get queue statistics from Redis
     */
    private function getQueueStats(): array
    {
        try {
            $queue = new AsyncNotificationQueue();
            return $queue->getStats();
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get detailed monitoring output
     */
    public function getMonitoringOutput(): array
    {
        $status = $this->getStatus();

        $output = "=======================================================\n";
        $output .= "NOTIFICATION WORKER STATUS - need2talk\n";
        $output .= "=======================================================\n\n";

        $output .= "Environment: {$this->environment}\n";
        $output .= "Active Workers: {$status['workers']['running']}/{$status['workers']['max']}\n";
        $output .= "Status: {$status['workers']['status']}\n\n";

        $output .= "Notification Queue Status:\n";
        $output .= "-------------------------------------------------------\n";

        if (isset($status['queue']['error'])) {
            $output .= "   Error: {$status['queue']['error']}\n";
        } else {
            $output .= "   Pending:    " . ($status['queue']['pending'] ?? 'N/A') . "\n";
            $output .= "   Processing: " . ($status['queue']['processing'] ?? 'N/A') . "\n";
            $output .= "   Failed:     " . ($status['queue']['failed'] ?? 'N/A') . "\n";
            $output .= "   Dead Letter: " . ($status['queue']['dead_letter'] ?? 'N/A') . "\n";
        }

        $output .= "\n=======================================================\n";
        $output .= date('Y-m-d H:i:s') . "\n";

        return [
            'success' => true,
            'output' => $output,
            'data' => $status,
        ];
    }

    /**
     * Restart workers
     */
    public function restartWorkers(?int $count = null): array
    {
        $stopResult = $this->stopWorkers();
        sleep(2); // Wait for graceful shutdown
        $startResult = $this->startWorkers($count);

        return [
            'success' => $startResult['success'],
            'stop_result' => $stopResult,
            'start_result' => $startResult,
        ];
    }
}
