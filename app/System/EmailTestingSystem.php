<?php

namespace Need2Talk\System;

use Need2Talk\Services\EnterpriseSecureDatabasePool;

/**
 * EMAIL TESTING SYSTEM
 *
 * Sistema RAW per testare workers e database pool.
 * BYPASSA COMPLETAMENTE AsyncEmailQueue e tutti i controlli.
 * Mette email DIRETTAMENTE in Redis nel formato workers.
 */
class EmailTestingSystem
{
    // Redis keys (stesso formato AsyncEmailQueue)
    private const QUEUE_KEY = 'email_queue:pending';
    private const FAILED_KEY = 'email_queue:failed';
    private const PROCESSING_KEY = 'email_queue:processing';

    private \Redis $redis;

    private EnterpriseSecureDatabasePool $dbPool;

    public function __construct()
    {
        $this->dbPool = EnterpriseSecureDatabasePool::getInstance();

        // Connect DIRECTLY to Redis (no managers, no pools, no checks)
        $this->redis = new \Redis();

        if (!$this->redis->connect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379))) {
            throw new \Exception('Cannot connect to Redis');
        }

        // ENTERPRISE GALAXY: Authenticate with Redis password
        $redisPassword = $_ENV['REDIS_PASSWORD'] ?? null;
        if ($redisPassword) {
            if (!$this->redis->auth($redisPassword)) {
                $this->redis->close();
                throw new \Exception('Redis authentication failed');
            }
        }

        $this->redis->select(2); // Email queue database
    }

    /**
     * Accoda email DIRETTAMENTE in Redis usando UTENTI VERI dal database
     * Questo testa il flusso COMPLETO: Redis → Workers → DB queries → SMTP
     */
    public function queueEmailsWithRealUsers(string $userPrefix): array
    {
        $startTime = microtime(true);
        $queued = 0;
        $failed = 0;

        // Get real users from database
        $pdo = db_pdo();
        $stmt = $pdo->prepare('
            SELECT id, uuid, email, nickname
            FROM users
            WHERE email LIKE ?
            ORDER BY id ASC
        ');
        $stmt->execute(["{$userPrefix}_%"]);
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($users)) {
            throw new \Exception("No users found with prefix: {$userPrefix}_");
        }

        $count = count($users);

        foreach ($users as $user) {
            $jobId = uniqid('email_', true);

            // Email data - EXACT format workers expect with REAL user data
            $emailData = [
                'type' => 'verification',
                'user_id' => (int)$user['id'],
                'user_uuid' => $user['uuid'],
                'email' => $user['email'],
                'context' => 'load_test',
                'template_data' => [
                    'user_data' => [
                        'nickname' => $user['nickname'],
                    ],
                ],
                'priority' => 5, // HIGH priority
                'max_attempts' => 1,
                'job_id' => $jobId,
                'attempts' => 0,
                'load_test' => true, // Flag for identification
            ];

            // Add DIRECTLY to Redis sorted set
            // Score = priority (lower = higher priority, workers use zpopmin)
            $json = json_encode($emailData);
            // @phpstan-ignore-next-line Redis::zAdd() exists but IDE doesn't recognize it
            $result = $this->redis->zAdd(self::QUEUE_KEY, 5, $json);

            if ($result !== false) {
                $queued++;
            } else {
                $failed++;
            }
        }

        $time = microtime(true) - $startTime;

        return [
            'queued' => $queued,
            'failed' => $failed,
            'time' => $time,
            'rate' => $time > 0 ? $queued / $time : 0,
            'user_count' => $count,
        ];
    }

    /**
     * Get queue size DIRECTLY from Redis
     */
    public function getQueueSize(): int
    {
        // @phpstan-ignore-next-line Redis::zCard() exists but IDE doesn't recognize it
        return $this->redis->zCard(self::QUEUE_KEY);
    }

    /**
     * Get failed queue size
     */
    public function getFailedQueueSize(): int
    {
        // @phpstan-ignore-next-line Redis::zCard() exists but IDE doesn't recognize it
        return $this->redis->zCard(self::FAILED_KEY);
    }

    /**
     * Clear queues
     */
    public function clearQueues(): array
    {
        return [
            'pending' => $this->redis->del(self::QUEUE_KEY),
            'failed' => $this->redis->del(self::FAILED_KEY),
            'processing' => $this->redis->del(self::PROCESSING_KEY),
        ];
    }

    /**
     * Monitor workers processing
     */
    public function monitorWorkers(int $intervalSec = 2, int $maxWaitSec = 300, ?callable $callback = null): array
    {
        $startTime = microtime(true);
        $initialSize = $this->getQueueSize();
        $lastSize = $initialSize;
        $measurements = [];

        while (true) {
            sleep($intervalSec);
            $elapsed = (int)(microtime(true) - $startTime);

            if ($elapsed > $maxWaitSec) {
                break;
            }

            $currentSize = $this->getQueueSize();
            $failedSize = $this->getFailedQueueSize();
            $processed = $lastSize - $currentSize;
            $rate = $processed / $intervalSec;

            // Get pool stats
            $poolStats = $this->dbPool->getStats();

            $stats = [
                'elapsed' => $elapsed,
                'queue' => $currentSize,
                'failed' => $failedSize,
                'processed' => $processed,
                'rate' => $rate,
                'pool_active' => $poolStats['active_connections'] ?? 0,
                'pool_max' => $poolStats['max_connections'] ?? 0,
                'pool_health' => $poolStats['health_ratio'] ?? 100,
                'circuit_breaker' => $poolStats['circuit_breaker_state'] ?? '?',
            ];

            $measurements[] = $stats;

            if ($callback) {
                $callback($stats);
            }

            $lastSize = $currentSize;

            // ENTERPRISE TIPS: Don't stop immediately when queue=0
            // Workers may still be processing emails!
            // Wait until BOTH queue=0 AND no processing activity for 2 intervals
            if ($currentSize === 0 && $processed === 0) {
                // Queue empty and no activity - wait one more interval to be sure
                sleep($intervalSec);
                $finalCheck = $this->getQueueSize();

                if ($finalCheck === 0) {
                    // Give workers extra time to finish SMTP sends
                    sleep($intervalSec * 2);
                    break;
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        $totalProcessed = $initialSize - $this->getQueueSize();

        return [
            'initial_queue' => $initialSize,
            'final_queue' => $this->getQueueSize(),
            'total_processed' => $totalProcessed,
            'final_failed' => $this->getFailedQueueSize(),
            'total_time' => $totalTime,
            'avg_rate' => $totalTime > 0 ? $totalProcessed / $totalTime : 0,
            'measurements' => $measurements,
        ];
    }

    /**
     * Get pool stats
     */
    public function getPoolStats(): array
    {
        return $this->dbPool->getStats();
    }

    /**
     * Get first failed email for debug
     */
    public function getFirstFailed(): ?array
    {
        // @phpstan-ignore-next-line Redis::zRange() exists but IDE doesn't recognize it
        $result = $this->redis->zRange(self::FAILED_KEY, 0, 0);

        return $result ? json_decode($result[0], true) : null;
    }

    /**
     * Health check
     */
    public function healthCheck(): array
    {
        $health = [
            'redis' => false,
            'database' => false,
            'pool' => false,
            'workers' => 0,
        ];

        // Redis
        try {
            $health['redis'] = $this->redis->ping() === true;
        } catch (\Exception $e) {
            $health['redis_error'] = $e->getMessage();
        }

        // Database
        try {
            db_pdo()->query('SELECT 1');
            $health['database'] = true;
        } catch (\Exception $e) {
            $health['db_error'] = $e->getMessage();
        }

        // Pool
        try {
            $health['pool_stats'] = $this->dbPool->getStats();
            $health['pool'] = true;
        } catch (\Exception $e) {
            $health['pool_error'] = $e->getMessage();
        }

        // Workers
        $health['workers'] = count(glob(__DIR__ . '/../../storage/logs/worker_*.log'));

        return $health;
    }

    /**
     * Generate fake UUID v4
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
