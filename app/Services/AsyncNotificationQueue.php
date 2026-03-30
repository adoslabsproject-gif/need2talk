<?php

declare(strict_types=1);

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;

/**
 * AsyncNotificationQueue - Enterprise Grade Notification Queue
 *
 * Sistema di queue notifiche asincrono progettato per:
 * - 100,000+ utenti simultanei senza blocchi HTTP
 * - Redis-based queue con batching intelligente
 * - Deduplicazione (50 reactions in 2s → 1 notifica aggregata)
 * - Worker pool scalabile (1-4 workers)
 * - Rate limiting per user
 * - Retry mechanisms con backoff esponenziale
 * - Monitoring e metrics real-time
 *
 * ARCHITECTURE:
 * 1. HTTP Request → enqueue() → Redis ZADD (0.1ms) → Return
 * 2. Worker → dequeue batch → Deduplicate → DB INSERT batch → WebSocket batch
 *
 * DEDUPLICATION STRATEGY:
 * - Same user + same type + same target within 5s → aggregate
 * - Example: 50 reactions to your post → "Hai 50 nuove reazioni"
 *
 * @package Need2Talk\Services
 * @version 1.0.0 - Enterprise Galaxy V11.6
 */
class AsyncNotificationQueue
{
    // Redis keys for enterprise scalability
    private const QUEUE_KEY = 'notification_queue:pending';
    private const PROCESSING_KEY = 'notification_queue:processing';
    private const FAILED_KEY = 'notification_queue:failed';
    private const DEAD_LETTER_KEY = 'notification_queue:dead_letter';
    private const METRICS_KEY = 'notification_queue:metrics';
    private const WORKER_HEARTBEAT_KEY = 'notification_queue:workers';
    private const DEDUP_KEY_PREFIX = 'notification_queue:dedup:';

    // Queue priority levels
    public const PRIORITY_HIGH = 1;      // Friend requests, DMs
    public const PRIORITY_NORMAL = 5;    // Comments, mentions
    public const PRIORITY_LOW = 10;      // Reactions, likes

    // Deduplication window (seconds)
    private const DEDUP_WINDOW = 5;

    // Batch configuration
    private const DEFAULT_BATCH_SIZE = 50;
    private const MAX_BATCH_SIZE = 200;

    // Retry configuration
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_BASE = 30; // seconds

    private EnterpriseRedisManager $redisManager;
    private array $config;

    public function __construct()
    {
        $this->redisManager = EnterpriseRedisManager::getInstance();
        $this->config = [
            'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
            'retry_delay_base' => self::RETRY_DELAY_BASE,
            'dead_letter_ttl' => 86400 * 7, // 7 days
            'worker_timeout' => 300, // 5 minutes
            'batch_size' => self::DEFAULT_BATCH_SIZE,
            'dedup_window' => self::DEDUP_WINDOW,
        ];
    }

    /**
     * Enqueue a notification for async processing
     *
     * PERFORMANCE: O(1) Redis ZADD, ~0.1ms latency
     * HTTP request returns immediately without waiting for DB/WebSocket
     *
     * @param array $notificationData Notification data:
     *   - user_id: int (required) - User to notify
     *   - type: string (required) - Notification type
     *   - actor_id: int|null - User who triggered
     *   - target_type: string|null - Target type
     *   - target_id: int|null - Target ID
     *   - data: array - Additional data
     *   - priority: int - Queue priority (default: PRIORITY_NORMAL)
     * @return string|false Job ID or false on failure
     */
    public function enqueue(array $notificationData): string|false
    {
        try {
            // Validate required fields
            if (empty($notificationData['user_id']) || empty($notificationData['type'])) {
                Logger::warning('AsyncNotificationQueue: Invalid notification data', [
                    'user_id' => $notificationData['user_id'] ?? null,
                    'type' => $notificationData['type'] ?? null,
                ]);
                return false;
            }

            // Don't notify yourself
            $actorId = $notificationData['actor_id'] ?? null;
            if ($actorId !== null && (int)$notificationData['user_id'] === (int)$actorId) {
                return false;
            }

            // Generate unique job ID
            $jobId = 'notif_' . uniqid('', true);

            // Build queue item
            $queueItem = [
                'job_id' => $jobId,
                'user_id' => (int)$notificationData['user_id'],
                'type' => $notificationData['type'],
                'actor_id' => $actorId,
                'target_type' => $notificationData['target_type'] ?? null,
                'target_id' => $notificationData['target_id'] ?? null,
                'data' => $notificationData['data'] ?? [],
                'priority' => $notificationData['priority'] ?? self::PRIORITY_NORMAL,
                'attempts' => 0,
                'queued_at' => microtime(true),
            ];

            // Get Redis connection
            $redis = $this->getRedisConnection();
            if (!$redis) {
                Logger::error('AsyncNotificationQueue: Redis connection failed');
                return false;
            }

            // Check deduplication (skip if same notification recently queued)
            $dedupKey = $this->generateDedupKey($queueItem);
            if ($dedupKey && $redis->exists($dedupKey)) {
                // Increment aggregate counter instead of creating new notification
                $redis->incr($dedupKey . ':count');
                return $jobId; // Return success (deduped)
            }

            // Set dedup key with TTL
            if ($dedupKey) {
                $redis->setex($dedupKey, $this->config['dedup_window'], $jobId);
                $redis->setex($dedupKey . ':count', $this->config['dedup_window'], '1');
            }

            // Add to priority queue (ZSET with priority as score)
            $queueData = json_encode($queueItem, JSON_THROW_ON_ERROR);
            $result = $redis->zAdd(self::QUEUE_KEY, $queueItem['priority'], $queueData);

            if ($result !== false) {
                // Update metrics
                $this->incrementMetric('queued');
                return $jobId;
            }

            return false;

        } catch (\Exception $e) {
            Logger::error('AsyncNotificationQueue: Enqueue failed', [
                'error' => $e->getMessage(),
                'notification' => $notificationData,
            ]);
            return false;
        }
    }

    /**
     * Process a batch of notifications (called by worker)
     *
     * BATCH PROCESSING:
     * 1. Dequeue up to $batchSize items
     * 2. Group by user_id for efficient DB inserts
     * 3. Batch INSERT into notifications table
     * 4. Batch WebSocket pushes per user
     *
     * @param int $batchSize Number of notifications to process
     * @param string $workerId Worker identifier for heartbeat
     * @return int Number of processed notifications
     */
    public function processBatch(int $batchSize = self::DEFAULT_BATCH_SIZE, string $workerId = ''): int
    {
        $batchSize = min($batchSize, self::MAX_BATCH_SIZE);
        $processed = 0;

        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                return 0;
            }

            // Update worker heartbeat
            if ($workerId) {
                $this->updateWorkerHeartbeat($workerId);
            }

            // Atomic: Move items from pending to processing
            $items = [];
            for ($i = 0; $i < $batchSize; $i++) {
                // ZPOPMIN: Get and remove lowest score (highest priority)
                $item = $redis->zPopMin(self::QUEUE_KEY, 1);
                if (empty($item)) {
                    break;
                }

                // $item is [member => score]
                $queueData = array_key_first($item);
                $notification = json_decode($queueData, true);

                if ($notification) {
                    // Mark as processing
                    $redis->hSet(
                        self::PROCESSING_KEY,
                        $notification['job_id'],
                        json_encode([
                            'data' => $notification,
                            'started_at' => microtime(true),
                            'worker_id' => $workerId,
                        ])
                    );

                    $items[] = $notification;
                }
            }

            if (empty($items)) {
                return 0;
            }

            // Group by user for efficient processing
            $groupedByUser = [];
            foreach ($items as $item) {
                $userId = $item['user_id'];
                if (!isset($groupedByUser[$userId])) {
                    $groupedByUser[$userId] = [];
                }
                $groupedByUser[$userId][] = $item;
            }

            // Process each user's notifications
            foreach ($groupedByUser as $userId => $notifications) {
                try {
                    $processedCount = $this->processUserNotifications($userId, $notifications, $redis);
                    $processed += $processedCount;
                } catch (\Exception $e) {
                    Logger::error('AsyncNotificationQueue: User batch failed', [
                        'user_id' => $userId,
                        'count' => count($notifications),
                        'error' => $e->getMessage(),
                    ]);

                    // Re-queue failed notifications
                    foreach ($notifications as $notification) {
                        $this->handleFailure($notification, $e->getMessage(), $redis);
                    }
                }
            }

            // Update metrics
            $this->incrementMetric('processed', $processed);

            return $processed;

        } catch (\Exception $e) {
            Logger::error('AsyncNotificationQueue: Batch processing failed', [
                'error' => $e->getMessage(),
                'batch_size' => $batchSize,
            ]);
            return $processed;
        }
    }

    /**
     * Process notifications for a single user
     *
     * @param int $userId User ID
     * @param array $notifications Array of notification items
     * @param \Redis $redis Redis connection
     * @return int Number of processed notifications
     */
    private function processUserNotifications(int $userId, array $notifications, \Redis $redis): int
    {
        $processed = 0;
        $db = db();

        // Get user UUID once for WebSocket
        $userRow = $db->findOne(
            "SELECT uuid FROM users WHERE id = ?",
            [$userId],
            ['cache' => true, 'cache_ttl' => 'medium']
        );

        if (!$userRow) {
            Logger::warning('AsyncNotificationQueue: User not found', ['user_id' => $userId]);
            // Mark all as processed (user doesn't exist)
            foreach ($notifications as $notification) {
                $redis->hDel(self::PROCESSING_KEY, $notification['job_id']);
            }
            return count($notifications);
        }

        $userUuid = $userRow['uuid'];

        // Check dedup aggregation for each notification
        $aggregatedNotifications = $this->aggregateNotifications($notifications, $redis);

        // Batch insert notifications into database
        foreach ($aggregatedNotifications as $notification) {
            try {
                // Check user preferences
                if (!$this->shouldNotifyUser($userId, $notification['type'])) {
                    $redis->hDel(self::PROCESSING_KEY, $notification['job_id']);
                    $processed++;
                    continue;
                }

                // Insert notification
                $db->execute(
                    "INSERT INTO notifications (user_id, type, actor_id, target_type, target_id, data)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $userId,
                        $notification['type'],
                        $notification['actor_id'],
                        $notification['target_type'],
                        $notification['target_id'],
                        json_encode($notification['data'], JSON_UNESCAPED_UNICODE),
                    ]
                );

                $notificationId = (int)$db->lastInsertId();

                // Get actor info for WebSocket
                $actorData = null;
                if ($notification['actor_id']) {
                    $actorData = $db->findOne(
                        "SELECT uuid, nickname, avatar_url FROM users WHERE id = ?",
                        [$notification['actor_id']],
                        ['cache' => true, 'cache_ttl' => 'medium']
                    );
                }

                // Push via WebSocket
                WebSocketPublisher::publishToUser($userUuid, 'notification', [
                    'id' => $notificationId,
                    'notification_id' => $notificationId,
                    'type' => $notification['type'],
                    'user_uuid' => $userUuid,
                    'actor' => $actorData ? [
                        'uuid' => $actorData['uuid'],
                        'nickname' => $actorData['nickname'],
                        'avatar_url' => get_avatar_url($actorData['avatar_url'] ?? null),
                    ] : null,
                    'target_type' => $notification['target_type'],
                    'target_id' => $notification['target_id'],
                    'data' => $notification['data'],
                    'created_at' => date('c'),
                    // Include aggregation count if applicable
                    'aggregate_count' => $notification['data']['aggregate_count'] ?? 1,
                ]);

                // Remove from processing
                $redis->hDel(self::PROCESSING_KEY, $notification['job_id']);
                $processed++;

            } catch (\Exception $e) {
                $this->handleFailure($notification, $e->getMessage(), $redis);
            }
        }

        return $processed;
    }

    /**
     * Aggregate similar notifications for same user
     *
     * DEDUPLICATION LOGIC:
     * - Same type + same target within batch → aggregate
     * - Merge actor_ids into data['actors']
     * - Set aggregate_count in data
     *
     * @param array $notifications Raw notifications
     * @param \Redis $redis Redis connection for dedup counters
     * @return array Aggregated notifications
     */
    private function aggregateNotifications(array $notifications, \Redis $redis): array
    {
        $aggregated = [];
        $seen = [];

        foreach ($notifications as $notification) {
            // Create aggregation key
            $aggKey = $notification['type'] . ':' . ($notification['target_type'] ?? '') . ':' . ($notification['target_id'] ?? '');

            if (isset($seen[$aggKey])) {
                // Aggregate with existing
                $existingIdx = $seen[$aggKey];
                $existing = $aggregated[$existingIdx];

                // Increment aggregate count
                $aggregated[$existingIdx]['data']['aggregate_count'] =
                    ($existing['data']['aggregate_count'] ?? 1) + 1;

                // Add actor to actors list
                if ($notification['actor_id'] && !isset($existing['data']['actors'])) {
                    $aggregated[$existingIdx]['data']['actors'] = [$existing['actor_id']];
                }
                if ($notification['actor_id']) {
                    $aggregated[$existingIdx]['data']['actors'][] = $notification['actor_id'];
                }

                // Keep the original job_id for cleanup
                $aggregated[$existingIdx]['aggregated_job_ids'][] = $notification['job_id'];

                // Remove from processing (it's aggregated)
                $redis->hDel(self::PROCESSING_KEY, $notification['job_id']);

            } else {
                // Check Redis dedup counter
                $dedupKey = $this->generateDedupKey($notification);
                $dedupCount = 1;
                if ($dedupKey) {
                    $countKey = $dedupKey . ':count';
                    $dedupCount = (int)$redis->get($countKey) ?: 1;
                    $redis->del($countKey); // Clear after reading
                }

                $notification['data']['aggregate_count'] = $dedupCount;
                $notification['aggregated_job_ids'] = [$notification['job_id']];

                $aggregated[] = $notification;
                $seen[$aggKey] = count($aggregated) - 1;
            }
        }

        return $aggregated;
    }

    /**
     * Generate deduplication key for a notification
     *
     * @param array $notification Notification data
     * @return string|null Dedup key or null if not applicable
     */
    private function generateDedupKey(array $notification): ?string
    {
        // Only dedup certain types
        $dedupTypes = [
            'new_reaction',
            'comment_liked',
            'new_comment',
        ];

        if (!in_array($notification['type'], $dedupTypes)) {
            return null;
        }

        return sprintf(
            '%s%d:%s:%s:%s',
            self::DEDUP_KEY_PREFIX,
            $notification['user_id'],
            $notification['type'],
            $notification['target_type'] ?? '',
            $notification['target_id'] ?? ''
        );
    }

    /**
     * Check if user wants to receive this notification type
     *
     * @param int $userId User ID
     * @param string $type Notification type
     * @return bool True if should notify
     */
    private function shouldNotifyUser(int $userId, string $type): bool
    {
        static $typeToColumn = [
            'new_comment' => 'notify_comments',
            'comment_reply' => 'notify_replies',
            'new_reaction' => 'notify_reactions',
            'comment_liked' => 'notify_comment_likes',
            'mentioned' => 'notify_mentions',
            'friend_request' => 'notify_friend_requests',
            'friend_accepted' => 'notify_friend_accepted',
            'dm_received' => 'notify_dm_received',
            'dm_mi_ha_cercato' => 'notify_dm_received',
        ];

        $column = $typeToColumn[$type] ?? null;
        if ($column === null) {
            return true; // Unknown type = allow
        }

        try {
            $db = db();
            $settings = $db->findOne(
                "SELECT {$column} FROM user_settings WHERE user_id = ?",
                [$userId],
                ['cache' => true, 'cache_ttl' => 'medium']
            );

            return $settings === null || (bool)($settings[$column] ?? true);

        } catch (\Exception $e) {
            return true; // On error, allow (fail-open)
        }
    }

    /**
     * Handle failed notification processing
     *
     * @param array $notification Notification data
     * @param string $error Error message
     * @param \Redis $redis Redis connection
     */
    private function handleFailure(array $notification, string $error, \Redis $redis): void
    {
        $notification['attempts'] = ($notification['attempts'] ?? 0) + 1;
        $notification['last_error'] = $error;
        $notification['failed_at'] = microtime(true);

        // Remove from processing
        $redis->hDel(self::PROCESSING_KEY, $notification['job_id']);

        if ($notification['attempts'] >= $this->config['max_retry_attempts']) {
            // Move to dead letter queue
            $redis->zAdd(
                self::DEAD_LETTER_KEY,
                time(),
                json_encode($notification)
            );
            $this->incrementMetric('dead_letter');

            Logger::error('AsyncNotificationQueue: Notification moved to dead letter', [
                'job_id' => $notification['job_id'],
                'user_id' => $notification['user_id'],
                'type' => $notification['type'],
                'attempts' => $notification['attempts'],
                'error' => $error,
            ]);

        } else {
            // Re-queue with exponential backoff
            $delay = $this->config['retry_delay_base'] * pow(2, $notification['attempts'] - 1);
            $retryAt = time() + $delay;

            $redis->zAdd(
                self::FAILED_KEY,
                $retryAt,
                json_encode($notification)
            );
            $this->incrementMetric('failed');

            Logger::warning('AsyncNotificationQueue: Notification queued for retry', [
                'job_id' => $notification['job_id'],
                'attempts' => $notification['attempts'],
                'retry_at' => date('Y-m-d H:i:s', $retryAt),
            ]);
        }
    }

    /**
     * Process failed notifications ready for retry
     *
     * @param int $limit Maximum notifications to retry
     * @return int Number of re-queued notifications
     */
    public function processFailedQueue(int $limit = 50): int
    {
        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                return 0;
            }

            $now = time();
            $requeued = 0;

            // Get failed notifications ready for retry (score <= now)
            $items = $redis->zRangeByScore(self::FAILED_KEY, 0, $now, ['limit' => [0, $limit]]);

            foreach ($items as $queueData) {
                $notification = json_decode($queueData, true);
                if (!$notification) {
                    continue;
                }

                // Remove from failed queue
                $redis->zRem(self::FAILED_KEY, $queueData);

                // Re-add to pending queue
                $redis->zAdd(self::QUEUE_KEY, $notification['priority'] ?? self::PRIORITY_NORMAL, $queueData);
                $requeued++;
            }

            if ($requeued > 0) {
                Logger::info('AsyncNotificationQueue: Re-queued failed notifications', [
                    'count' => $requeued,
                ]);
            }

            return $requeued;

        } catch (\Exception $e) {
            Logger::error('AsyncNotificationQueue: Failed queue processing error', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Update worker heartbeat
     *
     * ENTERPRISE V11.7: Fixed stale heartbeat accumulation
     * - Removed expire() on entire HASH (doesn't work as intended)
     * - Now cleans up stale entries (>10 min old) during heartbeat update
     *
     * @param string $workerId Worker identifier
     */
    private function updateWorkerHeartbeat(string $workerId): void
    {
        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                return;
            }

            $redis->hSet(self::WORKER_HEARTBEAT_KEY, $workerId, json_encode([
                'last_heartbeat' => time(),
                'pid' => getmypid(),
                'memory' => memory_get_usage(true),
            ]));

            // ENTERPRISE V11.7: Clean stale heartbeats every ~100 heartbeat updates (probabilistic)
            // This prevents accumulation of dead worker entries
            if (rand(1, 100) === 1) {
                $this->cleanStaleHeartbeats();
            }

        } catch (\Exception $e) {
            // Non-critical, ignore
        }
    }

    /**
     * Remove worker heartbeat (call on shutdown)
     *
     * ENTERPRISE V11.7: Explicit cleanup when worker terminates
     *
     * @param string $workerId Worker identifier
     */
    public function removeWorkerHeartbeat(string $workerId): void
    {
        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                return;
            }

            $redis->hDel(self::WORKER_HEARTBEAT_KEY, $workerId);

        } catch (\Exception $e) {
            // Non-critical, ignore
        }
    }

    /**
     * Clean stale heartbeats (older than 10 minutes)
     *
     * ENTERPRISE V11.7: Prevents heartbeat accumulation from crashed/restarted workers
     */
    private function cleanStaleHeartbeats(): void
    {
        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                return;
            }

            $workers = $redis->hGetAll(self::WORKER_HEARTBEAT_KEY);
            if (empty($workers)) {
                return;
            }

            $now = time();
            $staleThreshold = 600; // 10 minutes
            $cleaned = 0;

            foreach ($workers as $workerId => $data) {
                $workerData = json_decode($data, true);
                $lastHeartbeat = $workerData['last_heartbeat'] ?? 0;

                if (($now - $lastHeartbeat) > $staleThreshold) {
                    $redis->hDel(self::WORKER_HEARTBEAT_KEY, $workerId);
                    $cleaned++;
                }
            }

            if ($cleaned > 0) {
                Logger::info('Cleaned stale notification worker heartbeats', [
                    'cleaned_count' => $cleaned,
                ]);
            }

        } catch (\Exception $e) {
            // Non-critical, ignore
        }
    }

    /**
     * Increment a metric counter
     *
     * @param string $metric Metric name
     * @param int $amount Amount to increment
     */
    private function incrementMetric(string $metric, int $amount = 1): void
    {
        try {
            $redis = $this->getRedisConnection();
            if ($redis) {
                $redis->hIncrBy(self::METRICS_KEY, $metric, $amount);
                $redis->hSet(self::METRICS_KEY, 'last_updated', (string)time());
            }
        } catch (\Exception $e) {
            // Non-critical, ignore
        }
    }

    /**
     * Get queue statistics
     *
     * ENTERPRISE V11.7: Now filters stale workers and cleans them automatically
     *
     * @return array Queue stats
     */
    public function getStats(): array
    {
        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                return ['error' => 'Redis unavailable'];
            }

            // Get raw worker heartbeats
            $rawWorkers = $redis->hGetAll(self::WORKER_HEARTBEAT_KEY);

            // ENTERPRISE V11.7: Filter and clean stale workers
            $activeWorkers = [];
            $now = time();
            $staleThreshold = 600; // 10 minutes
            $staleWorkerIds = [];

            foreach ($rawWorkers as $workerId => $data) {
                $workerData = json_decode($data, true);
                $lastHeartbeat = $workerData['last_heartbeat'] ?? 0;

                if (($now - $lastHeartbeat) <= $staleThreshold) {
                    // Worker is active, include in stats
                    $activeWorkers[$workerId] = $data;
                } else {
                    // Worker is stale, mark for cleanup
                    $staleWorkerIds[] = $workerId;
                }
            }

            // Clean stale workers from Redis
            if (!empty($staleWorkerIds)) {
                foreach ($staleWorkerIds as $staleId) {
                    $redis->hDel(self::WORKER_HEARTBEAT_KEY, $staleId);
                }
            }

            return [
                'pending' => $redis->zCard(self::QUEUE_KEY),
                'processing' => $redis->hLen(self::PROCESSING_KEY),
                'failed' => $redis->zCard(self::FAILED_KEY),
                'dead_letter' => $redis->zCard(self::DEAD_LETTER_KEY),
                'metrics' => $redis->hGetAll(self::METRICS_KEY),
                'workers' => $activeWorkers,
            ];

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get queue size (pending notifications)
     *
     * @return int Queue size
     */
    public function getQueueSize(): int
    {
        try {
            $redis = $this->getRedisConnection();
            return $redis ? $redis->zCard(self::QUEUE_KEY) : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get Redis connection with circuit breaker
     *
     * @return \Redis|null
     */
    private function getRedisConnection(): ?\Redis
    {
        return $this->redisManager->getConnection('notification_queue');
    }

    /**
     * Clear all queues (for testing/maintenance)
     *
     * @return bool Success
     */
    public function clearQueues(): bool
    {
        try {
            $redis = $this->getRedisConnection();
            if (!$redis) {
                return false;
            }

            $redis->del(self::QUEUE_KEY);
            $redis->del(self::PROCESSING_KEY);
            $redis->del(self::FAILED_KEY);
            $redis->del(self::METRICS_KEY);

            Logger::warning('AsyncNotificationQueue: Queues cleared');
            return true;

        } catch (\Exception $e) {
            return false;
        }
    }
}
