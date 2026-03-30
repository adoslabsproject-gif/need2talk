<?php

namespace Need2Talk\Services;

use Need2Talk\Jobs\Admin\SendTwoFactorEmailJob;

/**
 * Enterprise Queue Dispatcher
 * Integrates with existing Redis queue system for admin operations
 */
class EnterpriseQueueDispatcher
{
    /** @var \Redis */
    private $redis;

    private $queueName;

    public function __construct()
    {
        // Use Redis configuration from .env (Lightning Framework style)
        $host = $_ENV['REDIS_HOST'] ?? 'redis';
        $port = $_ENV['REDIS_PORT'] ?? 6379;
        $password = $_ENV['REDIS_PASSWORD'] ?? null;
        $database = $_ENV['REDIS_DATABASE'] ?? 0;

        $this->redis = new \Redis();
        $this->redis->pconnect($host, $port);

        if ($password) {
            $this->redis->auth($password);
        }

        $this->redis->select($database);
        $this->queueName = 'need2talk:queue:admin';
    }

    /**
     * Dispatch 2FA email job to queue
     */
    public function dispatch2FAEmail($user, string $code, string $ipAddress, string $userAgent): bool
    {
        try {
            $job = new SendTwoFactorEmailJob($user, $code, $ipAddress, $userAgent);

            // Serialize job data
            $jobData = [
                'job' => 'Need2Talk\\Jobs\\Admin\\SendTwoFactorEmailJob',
                'data' => serialize($job),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time(),
                'created_at' => time(),
                'priority' => 'high', // High priority for security-related emails
            ];

            // Push to Redis queue
            /** @phpstan-ignore-next-line */
            $success = $this->redis->lPush($this->queueName, json_encode($jobData));

            if ($success) {
                // ENTERPRISE: Auto-process 2FA emails immediately for better UX
                error_log('[ENTERPRISE DEBUG] About to auto-process critical emails');

                try {
                    $this->autoProcessCriticalEmails();
                    error_log('[ENTERPRISE DEBUG] Auto-process completed successfully');
                } catch (\Exception $e) {
                    error_log('[ENTERPRISE DEBUG] Auto-process failed: ' . $e->getMessage());
                }

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Logger::email('error', 'QUEUE: Failed to dispatch 2FA email job', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Get queue stats
     */
    public function getQueueStats(): array
    {
        return [
            'pending_jobs' => $this->redis->lLen($this->queueName),
            'queue_name' => $this->queueName,
            'redis_info' => $this->redis->info('memory'),
        ];
    }

    /**
     * Process next job (used by workers)
     */
    public function processNextJob(): bool
    {
        try {
            // Pop job from queue (blocking with 1 second timeout)
            /** @phpstan-ignore-next-line */
            $jobData = $this->redis->brPop([$this->queueName], 1);

            if (!$jobData) {
                return false; // No jobs available
            }

            $job = json_decode($jobData[1], true);
            $jobInstance = unserialize($job['data']);

            // Execute the job
            $jobInstance->handle();

            return true;

        } catch (\Exception $e) {
            Logger::database('error', 'QUEUE: Failed to process queue job', [
                'error' => $e->getMessage(),
                'queue' => $this->queueName,
            ]);

            // Re-queue job for retry (simplified retry logic)
            if (isset($job)) {
                $job['attempts']++;

                if ($job['attempts'] < 3) {
                    /** @phpstan-ignore-next-line */
                    $this->redis->lPush($this->queueName . ':retry', json_encode($job));
                }
            }

            return false;
        }
    }

    /**
     * Clear queue (admin utility)
     */
    public function clearQueue(): int
    {
        $cleared = $this->redis->lLen($this->queueName);
        $this->redis->del($this->queueName);

        Logger::security('warning', 'QUEUE: Admin queue cleared', [
            'jobs_cleared' => $cleared,
            'queue' => $this->queueName,
        ]);

        return $cleared;
    }

    /**
     * ENTERPRISE: Auto-process critical emails (2FA, security alerts)
     */
    private function autoProcessCriticalEmails(): void
    {
        try {
            $processed = 0;

            // Process up to 5 high-priority jobs immediately
            for ($i = 0; $i < 5; $i++) {
                if ($this->processNextJob()) {
                    $processed++;
                } else {
                    break; // No more jobs
                }
            }

        } catch (\Exception $e) {
            Logger::database('error', 'QUEUE: Failed to auto-process critical emails', [
                'error' => $e->getMessage(),
                'queue' => $this->queueName,
            ]);
        }
    }
}
