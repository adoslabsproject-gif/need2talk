<?php

namespace Need2Talk\Services;

use Need2Talk\Core\Database;

/**
 * 🚀 ENTERPRISE GALAXY: Cron Manager Service
 *
 * Centralized cron job management system with:
 * - Job registration and scheduling
 * - Execution tracking and logging
 * - Lock mechanism (prevent concurrent runs)
 * - Health monitoring
 * - Performance metrics
 *
 * ARCHITECTURE:
 * - Jobs stored in cron_jobs table
 * - Execution history in cron_executions table
 * - Redis-based locking mechanism
 * - Automatic retry on failure
 *
 * @package Need2Talk\Services
 * @version 1.0.0 Galaxy Edition
 */
class CronManager
{
    private static ?self $instance = null;
    private Database $db;
    private ?\Redis $redis = null;
    private const LOCK_TTL = 60; // 60 seconds max execution time (most jobs complete in <50s, locks auto-expire fast)

    private function __construct()
    {
        $this->db = db();

        // Initialize Redis for locking
        if (class_exists('Redis')) {
            try {
                $this->redis = new \Redis();
                $this->redis->pconnect(
                    env('REDIS_HOST', 'redis'),
                    (int) env('REDIS_PORT', 6379)
                );

                // ENTERPRISE FIX: Authenticate with password if configured
                $redisPassword = env('REDIS_PASSWORD', '');
                if ($redisPassword) {
                    $this->redis->auth($redisPassword);
                }

                $this->redis->select(0); // Use DB 0 for cron locks
            } catch (\Exception $e) {
                Logger::error('CronManager: Failed to connect to Redis', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a cron job
     *
     * @param string $name Job name (unique identifier)
     * @param string $description Job description
     * @param string $schedule Cron expression (e.g., "0 * * * *" = hourly)
     * @param string $command Command to execute
     * @param bool $enabled Is job enabled?
     */
    public function registerJob(
        string $name,
        string $description,
        string $schedule,
        string $command,
        bool $enabled = true
    ): bool {
        try {
            $existing = $this->db->findOne(
                "SELECT id FROM cron_jobs WHERE name = ?",
                [$name]
            );

            if ($existing) {
                // Update existing job
                $this->db->execute(
                    "UPDATE cron_jobs
                     SET description = ?, schedule = ?, command = ?, enabled = ?, updated_at = NOW()
                     WHERE name = ?",
                    [$description, $schedule, $command, $enabled ? 1 : 0, $name],
                    ['invalidate_cache' => ['table:cron_jobs', 'cron:*']]
                );
            } else {
                // Insert new job
                $this->db->execute(
                    "INSERT INTO cron_jobs (name, description, schedule, command, enabled, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                    [$name, $description, $schedule, $command, $enabled ? 1 : 0],
                    ['invalidate_cache' => ['table:cron_jobs', 'cron:*']]
                );
            }

            Logger::info('CronManager: Job registered', [
                'job' => $name,
                'schedule' => $schedule,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('CronManager: Failed to register job', [
                'job' => $name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Execute a cron job
     *
     * @param string $name Job name
     * @return array Execution result
     */
    public function executeJob(string $name): array
    {
        $startTime = microtime(true);
        $lockKey = "cron:lock:{$name}";

        try {
            // Check if job exists and is enabled (no cache for real-time execution)
            $job = $this->db->findOne(
                "SELECT * FROM cron_jobs WHERE name = ? AND enabled = TRUE",
                [$name],
                ['cache' => false]
            );

            if (!$job) {
                return [
                    'success' => false,
                    'message' => 'Job not found or disabled',
                    'execution_time' => 0,
                ];
            }

            // Acquire lock to prevent concurrent execution
            if (!$this->acquireLock($lockKey)) {
                return [
                    'success' => false,
                    'message' => 'Job already running (locked)',
                    'execution_time' => 0,
                ];
            }

            // CRITICAL FIX: Close session before exec() to prevent session locking
            // When exec() is called from admin panel (which has an active session),
            // the child process will hang waiting for the session lock.
            // Solution: Close the session write lock before spawning the child process.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Execute command
            $output = [];
            $returnCode = 0;

            // ENTERPRISE FIX: Prepend 'sh' to .sh files for proper shell interpretation
            // This ensures shell scripts work even without execute permission
            $command = $job['command'];
            if (str_ends_with($command, '.sh')) {
                $command = 'sh ' . $command;
            } elseif (str_ends_with($command, '.php')) {
                // Ensure PHP scripts use the php interpreter
                $command = 'php ' . $command;
            }

            exec($command . ' 2>&1', $output, $returnCode);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $success = ($returnCode === 0);
            $outputText = implode("\n", $output);

            // Log execution
            $this->logExecution(
                $job['id'],
                $success,
                $executionTime,
                $outputText,
                $returnCode
            );

            // Update last_run in cron_jobs and invalidate cache
            $this->db->execute(
                "UPDATE cron_jobs SET last_run = NOW() WHERE id = ?",
                [$job['id']],
                ['invalidate_cache' => ['table:cron_jobs', 'cron:*']]
            );

            // Release lock
            $this->releaseLock($lockKey);

            return [
                'success' => $success,
                'message' => $success ? 'Job completed successfully' : 'Job failed',
                'output' => $outputText,
                'execution_time' => $executionTime,
                'return_code' => $returnCode,
            ];

        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            Logger::error('CronManager: Job execution failed', [
                'job' => $name,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
            ]);

            // Release lock on error
            $this->releaseLock($lockKey);

            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'execution_time' => $executionTime,
            ];
        }
    }

    /**
     * Check if a job should run based on schedule
     *
     * @param string $name Job name
     * @return bool
     */
    public function shouldRun(string $name): bool
    {
        try {
            $job = $this->db->findOne(
                "SELECT * FROM cron_jobs WHERE name = ? AND enabled = TRUE",
                [$name],
                ['cache' => false]
            );

            if (!$job) {
                return false;
            }

            // If never run, should run
            if (!$job['last_run']) {
                return true;
            }

            // Parse cron schedule and check if should run
            // Simplified: Check based on schedule interval
            return $this->checkSchedule($job['schedule'], $job['last_run']);

        } catch (\Exception $e) {
            Logger::error('CronManager: Failed to check schedule', [
                'job' => $name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get all registered jobs
     *
     * ENTERPRISE GALAXY: Uses LEFT JOIN aggregation instead of subqueries
     * to avoid cache issues with correlated subqueries
     */
    public function getAllJobs(): array
    {
        return $this->db->query("
            SELECT
                j.*,
                COUNT(e.id) as total_runs,
                SUM(CASE WHEN e.success = TRUE THEN 1 ELSE 0 END) as successful_runs,
                AVG(CASE WHEN e.success = TRUE THEN e.execution_time ELSE NULL END) as avg_execution_time
            FROM cron_jobs j
            LEFT JOIN cron_executions e ON e.job_id = j.id
            GROUP BY j.id
            ORDER BY j.name ASC
        ", [], ['cache' => false]);
    }

    /**
     * Get job execution history
     */
    public function getJobHistory(string $name, int $limit = 50): array
    {
        return $this->db->query("
            SELECT e.*
            FROM cron_executions e
            JOIN cron_jobs j ON e.job_id = j.id
            WHERE j.name = ?
            ORDER BY e.executed_at DESC
            LIMIT ?
        ", [$name, $limit], ['cache' => false]);
    }

    /**
     * Enable/disable a job
     */
    public function setJobEnabled(string $name, bool $enabled): bool
    {
        try {
            $this->db->execute(
                "UPDATE cron_jobs SET enabled = ?, updated_at = NOW() WHERE name = ?",
                [$enabled ? 1 : 0, $name]
            );

            Logger::info('CronManager: Job ' . ($enabled ? 'enabled' : 'disabled'), [
                'job' => $name,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('CronManager: Failed to update job status', [
                'job' => $name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Acquire Redis lock
     */
    private function acquireLock(string $key): bool
    {
        if (!$this->redis) {
            return true; // No Redis, allow execution
        }

        try {
            return $this->redis->set($key, '1', ['NX', 'EX' => self::LOCK_TTL]);
        } catch (\Exception $e) {
            Logger::error('CronManager: Failed to acquire lock', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return true; // Allow execution on lock failure
        }
    }

    /**
     * Release Redis lock
     */
    private function releaseLock(string $key): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->del($key);
        } catch (\Exception $e) {
            Logger::error('CronManager: Failed to release lock', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log job execution
     */
    private function logExecution(
        int $jobId,
        bool $success,
        float $executionTime,
        string $output,
        int $returnCode
    ): void {
        try {
            // ENTERPRISE FIX: Detect and handle binary/gzip data (but allow UTF-8 emoji/unicode)
            // If output appears to be binary (gzip header 0x1F8B or lots of null bytes), replace with summary
            if (substr($output, 0, 2) === "\x1F\x8B" || substr_count($output, "\x00") > 5) {
                $sanitizedOutput = '[Binary/Compressed output - ' . strlen($output) . ' bytes]';
            } else {
                // Keep output as-is (supports UTF-8 emoji and unicode)
                // Only remove actual null bytes which break PostgreSQL TEXT fields
                $sanitizedOutput = str_replace("\x00", '', $output);
            }

            // Limit to 10k chars for database storage
            $sanitizedOutput = mb_substr($sanitizedOutput, 0, 10000, 'UTF-8');

            $this->db->execute("
                INSERT INTO cron_executions
                (job_id, success, execution_time, output, return_code, executed_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ", [
                $jobId,
                $success ? 1 : 0,
                $executionTime,
                $sanitizedOutput,
                $returnCode,
            ]);
        } catch (\Exception $e) {
            Logger::error('CronManager: Failed to log execution', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if job should run based on schedule
     * ENTERPRISE GALAXY: Proper cron expression parsing
     *
     * Supports:
     * - 0 * * * *      → Hourly at minute 0
     * - 0 0 * * *      → Daily at midnight
     * - 0 3 * * *      → Daily at 3:00 AM
     * - 0 6 * * *      → Daily at 6:00 AM
     * - 0 0 * * 0      → Weekly (Sunday midnight)
     * - *\/N * * * *   → Every N minutes
     * - 0 H * * *      → Daily at hour H
     */
    private function checkSchedule(string $schedule, string $lastRun): bool
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Rome'));
        $lastRunDt = new \DateTime($lastRun, new \DateTimeZone('Europe/Rome'));

        // Parse cron expression: second minute hour day month weekday (6 parts)
        // OR standard: minute hour day month weekday (5 parts)
        $parts = explode(' ', trim($schedule));

        // ENTERPRISE V2.0: Support 6-part schedule with seconds
        // Format: */N * * * * * = every N seconds
        if (count($parts) === 6) {
            [$second, $minute, $hour, $day, $month, $weekday] = $parts;

            // Pattern: */N * * * * * (every N seconds)
            if (preg_match('/^\*\/(\d+)$/', $second, $matches) && $minute === '*' && $hour === '*') {
                $intervalSeconds = (int) $matches[1];
                $timeSinceLastRun = $now->getTimestamp() - $lastRunDt->getTimestamp();
                return $timeSinceLastRun >= $intervalSeconds;
            }

            // For other 6-part patterns, strip seconds and process as 5-part
            $parts = [$minute, $hour, $day, $month, $weekday];
        }

        if (count($parts) !== 5) {
            // Invalid schedule, don't run
            Logger::error('CronManager: Invalid cron schedule', ['schedule' => $schedule]);
            return false;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        // Pattern: */N * * * * (every N minutes)
        if (preg_match('/^\*\/(\d+)$/', $minute, $matches) && $hour === '*') {
            $intervalMinutes = (int) $matches[1];
            $timeSinceLastRun = $now->getTimestamp() - $lastRunDt->getTimestamp();
            return $timeSinceLastRun >= ($intervalMinutes * 60);
        }

        // Pattern: 0 * * * * (every hour at minute 0)
        if ($minute === '0' && $hour === '*' && $day === '*' && $month === '*' && $weekday === '*') {
            $timeSinceLastRun = $now->getTimestamp() - $lastRunDt->getTimestamp();
            return $timeSinceLastRun >= 3600;
        }

        // Pattern: 0 H * * * (daily at specific hour H)
        // This is the CRITICAL fix for telegram-daily-logs (0 3 * * *)
        if ($minute === '0' && is_numeric($hour) && $day === '*' && $month === '*' && $weekday === '*') {
            $targetHour = (int) $hour;
            $currentHour = (int) $now->format('G');
            $currentMinute = (int) $now->format('i');

            // Check if we're within the target hour (with 5 min tolerance for scheduler delay)
            $isTargetTime = ($currentHour === $targetHour && $currentMinute <= 5);

            // Check if already ran today at target time
            $lastRunDate = $lastRunDt->format('Y-m-d');
            $todayDate = $now->format('Y-m-d');
            $alreadyRanToday = ($lastRunDate === $todayDate);

            // Run only if: we're at target time AND haven't run today yet
            return $isTargetTime && !$alreadyRanToday;
        }

        // Pattern: M H * * * (daily at specific minute M and hour H)
        if (is_numeric($minute) && is_numeric($hour) && $day === '*' && $month === '*' && $weekday === '*') {
            $targetHour = (int) $hour;
            $targetMinute = (int) $minute;
            $currentHour = (int) $now->format('G');
            $currentMinute = (int) $now->format('i');

            // Check if we're within the target window (with 5 min tolerance)
            $isTargetTime = ($currentHour === $targetHour && $currentMinute >= $targetMinute && $currentMinute <= $targetMinute + 5);

            // Check if already ran today
            $lastRunDate = $lastRunDt->format('Y-m-d');
            $todayDate = $now->format('Y-m-d');
            $alreadyRanToday = ($lastRunDate === $todayDate);

            return $isTargetTime && !$alreadyRanToday;
        }

        // Pattern: 0 0 * * 0 (weekly on Sunday at midnight)
        if ($minute === '0' && $hour === '0' && $day === '*' && $month === '*' && $weekday === '0') {
            $timeSinceLastRun = $now->getTimestamp() - $lastRunDt->getTimestamp();
            return $timeSinceLastRun >= 604800; // 7 days
        }

        // Pattern: M H D * * (monthly on specific day D at hour H and minute M)
        // Examples: "0 8 1 * *" = 08:00 on 1st of every month
        //           "0 3 1 * *" = 03:00 on 1st of every month
        if (is_numeric($minute) && is_numeric($hour) && is_numeric($day) && $month === '*' && $weekday === '*') {
            $targetDay = (int) $day;
            $targetHour = (int) $hour;
            $targetMinute = (int) $minute;

            $currentDay = (int) $now->format('j');
            $currentHour = (int) $now->format('G');
            $currentMinute = (int) $now->format('i');

            // Check if we're on the target day and within the target time window (5 min tolerance)
            $isTargetDay = ($currentDay === $targetDay);
            $isTargetTime = ($currentHour === $targetHour && $currentMinute >= $targetMinute && $currentMinute <= $targetMinute + 5);

            // Check if already ran this month
            $lastRunMonth = $lastRunDt->format('Y-m');
            $currentMonth = $now->format('Y-m');
            $alreadyRanThisMonth = ($lastRunMonth === $currentMonth);

            return $isTargetDay && $isTargetTime && !$alreadyRanThisMonth;
        }

        // Unknown pattern: log warning and don't run (safer than defaulting to hourly)
        Logger::warning('CronManager: Unsupported cron schedule pattern', [
            'schedule' => $schedule,
            'job_last_run' => $lastRun,
        ]);

        return false;
    }
}
