#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Feed Pre-Compute Worker - Enterprise Galaxy V8.0
 *
 * FEED PRE-COMPUTATION SYSTEM (2025-12-01)
 *
 * Background worker that pre-computes feeds for active users.
 * Reduces feed response time from 50-100ms to 5-10ms.
 *
 * ARCHITECTURE:
 * ┌───────────────────────────────────────────────────────────────────────────────┐
 * │ Main Loop:                                                                    │
 * │ 1. Process refresh queue (invalidated feeds that need recomputation)         │
 * │ 2. Pre-compute for top N active users (proactive caching)                    │
 * │ 3. Check circuit breaker (pause if queue overloaded)                         │
 * │ 4. Sleep and repeat                                                          │
 * └───────────────────────────────────────────────────────────────────────────────┘
 *
 * CIRCUIT BREAKER:
 * - If queue > 10,000 items: PAUSE pre-computation (let queue drain)
 * - If queue < 5,000 items: RESUME normal operation
 * - This prevents runaway resource consumption during traffic spikes
 *
 * SCHEDULING:
 * - Refresh queue: Process immediately (high priority)
 * - Active users: Refresh every 3 minutes (proactive)
 * - Adaptive sleep based on work availability
 *
 * DEPLOYMENT:
 * - Docker: Add to docker-compose.yml as separate service
 * - Manual: php scripts/feed-precompute-worker.php
 * - Cron: Run every 5 minutes with --max-runtime=240
 *
 * @package Need2Talk\Scripts
 */

// ENTERPRISE: Long-running process configuration
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M'); // Feeds can be memory-intensive
ignore_user_abort(true);

date_default_timezone_set('Europe/Rome');

// Ensure CLI only
if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

// Bootstrap
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\Logger;
use Need2Talk\Services\Cache\FeedPrecomputeService;
use Need2Talk\Services\Cache\ActiveUserTracker;
use Need2Talk\Core\EnterpriseRedisManager;

/**
 * Feed Pre-Compute Worker
 */
class FeedPrecomputeWorker
{
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /** @var int Maximum runtime in seconds */
    private int $maxRuntime;

    /** @var int Batch size for refresh queue processing */
    private const QUEUE_BATCH_SIZE = 50;

    /** @var int Batch size for proactive pre-computation */
    private const PROACTIVE_BATCH_SIZE = 20;

    /** @var int Interval between proactive pre-computation cycles (seconds) */
    private const PROACTIVE_INTERVAL = 180; // 3 minutes

    /** @var int Memory threshold in MB before graceful exit */
    private const MEMORY_THRESHOLD_MB = 400;

    /** @var int Max consecutive errors before exit */
    private const MAX_ERRORS = 10;

    // =========================================================================
    // STATE
    // =========================================================================

    private bool $running = true;
    private string $workerId;
    private float $startTime;
    private float $lastProactiveRun = 0;

    // Metrics
    private int $queueProcessed = 0;
    private int $proactivePrecomputed = 0;
    private int $errorCount = 0;
    private float $totalPrecomputeDuration = 0;

    // Redis connection for heartbeat
    private ?\Redis $redis = null;
    private float $lastHeartbeatTime = 0;
    private const HEARTBEAT_KEY_TTL = 30; // Redis key TTL in seconds
    private const HEARTBEAT_REFRESH_INTERVAL = 10; // Refresh every 10s

    // Services
    private ?FeedPrecomputeService $feedService = null;
    private ?ActiveUserTracker $activeUserTracker = null;

    public function __construct(int $maxRuntime = 300)
    {
        $this->maxRuntime = $maxRuntime;
        $this->workerId = 'feed_precompute_' . uniqid() . '_' . getmypid();
        $this->startTime = microtime(true);

        $this->setupSignalHandlers();

        enterprise_log("[FEED-PRECOMPUTE] 🚀 Worker starting: {$this->workerId}");
    }

    /**
     * Initialize services
     */
    private function initializeServices(): bool
    {
        try {
            $this->feedService = FeedPrecomputeService::getInstance();
            $this->activeUserTracker = ActiveUserTracker::getInstance();

            // Initialize Redis for heartbeat registration
            $this->redis = EnterpriseRedisManager::getInstance()->getConnection('cache');

            return true;
        } catch (\Exception $e) {
            enterprise_log("[FEED-PRECOMPUTE] ❌ Service initialization failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Register worker heartbeat in Redis for monitoring dashboard
     */
    private function registerHeartbeat(): void
    {
        $now = microtime(true);

        // Only refresh if interval has passed
        if (($now - $this->lastHeartbeatTime) < self::HEARTBEAT_REFRESH_INTERVAL) {
            return;
        }

        try {
            if ($this->redis) {
                $heartbeatKey = "feed_worker:heartbeat:{$this->workerId}";
                $heartbeatData = json_encode([
                    'worker_id' => $this->workerId,
                    'started_at' => date('Y-m-d H:i:s', (int) $this->startTime),
                    'last_heartbeat' => date('Y-m-d H:i:s'),
                    'uptime_seconds' => (int) ($now - $this->startTime),
                    'queue_processed' => $this->queueProcessed,
                    'proactive_precomputed' => $this->proactivePrecomputed,
                    'errors' => $this->errorCount,
                    'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                ]);

                $this->redis->setex($heartbeatKey, self::HEARTBEAT_KEY_TTL, $heartbeatData);
                $this->lastHeartbeatTime = $now;
            }
        } catch (\Exception $e) {
            // Non-critical, don't fail on heartbeat errors
            enterprise_log("[FEED-PRECOMPUTE] ⚠️ Heartbeat registration failed: " . $e->getMessage());
        }
    }

    /**
     * Remove heartbeat on shutdown
     */
    private function removeHeartbeat(): void
    {
        try {
            if ($this->redis) {
                $heartbeatKey = "feed_worker:heartbeat:{$this->workerId}";
                $this->redis->del($heartbeatKey);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Main entry point
     */
    public function run(): int
    {
        // Step 1: Initialize services
        if (!$this->initializeServices()) {
            return 1;
        }

        enterprise_log("[FEED-PRECOMPUTE] ⚡ Running with max_runtime={$this->maxRuntime}s");

        // Step 2: Enter main loop
        $this->mainLoop();

        // Step 3: Cleanup (remove heartbeat from Redis)
        $this->removeHeartbeat();
        enterprise_log("[FEED-PRECOMPUTE] 🧹 Cleanup complete: heartbeat removed");

        // Step 4: Log final stats
        $this->logFinalStats();

        return 0;
    }

    /**
     * Main processing loop
     */
    private function mainLoop(): void
    {
        while ($this->running && $this->shouldContinue()) {
            try {
                $workDone = false;

                // Step 1: Check circuit breaker
                if ($this->feedService->isCircuitOpen()) {
                    enterprise_log("[FEED-PRECOMPUTE] ⚡ Circuit breaker OPEN - pausing pre-computation");
                    sleep(10); // Wait for queue to drain
                    continue;
                }

                // Step 2: Process refresh queue (high priority)
                $queueWork = $this->processRefreshQueue();
                if ($queueWork > 0) {
                    $workDone = true;
                }

                // Step 3: Proactive pre-computation for active users
                if ($this->shouldRunProactive()) {
                    $proactiveWork = $this->runProactivePrecomputation();
                    if ($proactiveWork > 0) {
                        $workDone = true;
                    }
                    $this->lastProactiveRun = microtime(true);
                }

                // ENTERPRISE V11.11: Reset database pool to prevent connection leak
                try {
                    \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->resetPool();
                    gc_collect_cycles();
                } catch (\Throwable $e) {
                    // Silent fail
                }

                // Step 4: Adaptive sleep
                if (!$workDone) {
                    // No work available, longer sleep
                    sleep(5);
                } else {
                    // Work done, shorter sleep
                    usleep(100000); // 100ms
                }

                // Periodic status log
                $this->periodicStatusLog();

                // Register heartbeat for monitoring dashboard
                $this->registerHeartbeat();

            } catch (\Exception $e) {
                $this->errorCount++;
                enterprise_log("[FEED-PRECOMPUTE] ❌ Error: " . $e->getMessage());

                Logger::error('FEED_PRECOMPUTE_ERROR', [
                    'worker_id' => $this->workerId,
                    'error' => $e->getMessage(),
                    'error_count' => $this->errorCount,
                ]);

                // Backoff after error
                sleep(min($this->errorCount * 2, 30));
            }
        }
    }

    /**
     * Process the refresh queue (invalidated feeds)
     *
     * @return int Number of feeds pre-computed
     */
    private function processRefreshQueue(): int
    {
        $userIds = $this->feedService->getRefreshQueue(self::QUEUE_BATCH_SIZE);

        if (empty($userIds)) {
            return 0;
        }

        $precomputed = 0;
        $startTime = microtime(true);

        foreach ($userIds as $userId) {
            if (!$this->running) break;

            try {
                $feed = $this->feedService->precomputeFeed($userId);
                $precomputed++;
                $this->queueProcessed++;
            } catch (\Exception $e) {
                Logger::error('Feed precomputation failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $durationMs = (microtime(true) - $startTime) * 1000;
        $this->totalPrecomputeDuration += $durationMs;

        if ($precomputed > 0) {
            enterprise_log("[FEED-PRECOMPUTE] ✅ Queue: {$precomputed} feeds ({$durationMs}ms)");
        }

        return $precomputed;
    }

    /**
     * Run proactive pre-computation for active users
     *
     * @return int Number of feeds pre-computed
     */
    private function runProactivePrecomputation(): int
    {
        // Get users who need feed refresh (active but stale)
        $userIds = $this->activeUserTracker->getUsersNeedingFeedRefresh(
            180, // Stale threshold (3 minutes)
            self::PROACTIVE_BATCH_SIZE
        );

        if (empty($userIds)) {
            // Fallback: pre-compute for top active users
            $userIds = $this->activeUserTracker->getTopActiveUsers(self::PROACTIVE_BATCH_SIZE);
        }

        if (empty($userIds)) {
            return 0;
        }

        $precomputed = 0;
        $startTime = microtime(true);

        foreach ($userIds as $userId) {
            if (!$this->running) break;

            // Check circuit breaker on each iteration
            if ($this->feedService->isCircuitOpen()) {
                break;
            }

            try {
                $feed = $this->feedService->precomputeFeed($userId);
                $precomputed++;
                $this->proactivePrecomputed++;
            } catch (\Exception $e) {
                Logger::error('Proactive feed precomputation failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $durationMs = (microtime(true) - $startTime) * 1000;
        $this->totalPrecomputeDuration += $durationMs;

        if ($precomputed > 0) {
            enterprise_log("[FEED-PRECOMPUTE] 📊 Proactive: {$precomputed} feeds ({$durationMs}ms)");
        }

        return $precomputed;
    }

    /**
     * Check if proactive pre-computation should run
     */
    private function shouldRunProactive(): bool
    {
        return (microtime(true) - $this->lastProactiveRun) >= self::PROACTIVE_INTERVAL;
    }

    /**
     * Periodic status log
     */
    private function periodicStatusLog(): void
    {
        static $lastLog = 0;

        $runtime = microtime(true) - $this->startTime;
        if ($runtime - $lastLog >= 60) { // Every 60 seconds
            $queueSize = $this->feedService->getQueueSize();
            $circuitState = $this->feedService->isCircuitOpen() ? 'OPEN' : 'closed';

            enterprise_log("[FEED-PRECOMPUTE] 📊 Status: " .
                "queue={$this->queueProcessed}, proactive={$this->proactivePrecomputed}, " .
                "errors={$this->errorCount}, pending={$queueSize}, circuit={$circuitState}");

            $lastLog = $runtime;
        }
    }

    /**
     * Check if worker should continue
     */
    private function shouldContinue(): bool
    {
        // Check runtime
        $runtime = microtime(true) - $this->startTime;
        if ($runtime >= $this->maxRuntime) {
            enterprise_log("[FEED-PRECOMPUTE] ⏰ Max runtime reached ({$this->maxRuntime}s)");
            return false;
        }

        // Check errors
        if ($this->errorCount >= self::MAX_ERRORS) {
            enterprise_log("[FEED-PRECOMPUTE] ❌ Max errors reached ({$this->errorCount})");
            return false;
        }

        // Check memory
        $memoryMB = memory_get_usage(true) / 1024 / 1024;
        if ($memoryMB > self::MEMORY_THRESHOLD_MB) {
            enterprise_log("[FEED-PRECOMPUTE] 💾 Memory threshold exceeded ({$memoryMB}MB)");
            return false;
        }

        return true;
    }

    /**
     * Log final statistics
     */
    private function logFinalStats(): void
    {
        $runtime = round(microtime(true) - $this->startTime, 2);
        $totalPrecomputed = $this->queueProcessed + $this->proactivePrecomputed;
        $avgMs = $totalPrecomputed > 0 ? round($this->totalPrecomputeDuration / $totalPrecomputed, 2) : 0;

        enterprise_log("[FEED-PRECOMPUTE] 📊 Final stats: " .
            "{$totalPrecomputed} total ({$this->queueProcessed} queue, {$this->proactivePrecomputed} proactive), " .
            "{$this->errorCount} errors, avg {$avgMs}ms/feed, {$runtime}s runtime");

        Logger::info('FEED_PRECOMPUTE_COMPLETE', [
            'worker_id' => $this->workerId,
            'queue_processed' => $this->queueProcessed,
            'proactive_precomputed' => $this->proactivePrecomputed,
            'error_count' => $this->errorCount,
            'avg_precompute_ms' => $avgMs,
            'runtime_seconds' => $runtime,
        ]);
    }

    /**
     * Setup signal handlers
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleShutdownSignal']);
        }
    }

    /**
     * Handle shutdown signals
     */
    public function handleShutdownSignal(int $signal): void
    {
        enterprise_log("[FEED-PRECOMPUTE] 🛑 Shutdown signal received (signal: {$signal})");
        $this->running = false;
    }
}

/**
 * Main execution
 */
function main(): int
{
    $options = getopt('', [
        'max-runtime:',
        'help',
    ]);

    if (isset($options['help'])) {
        echo "Feed Pre-Compute Worker - Enterprise Galaxy V8.0\n";
        echo "=================================================\n\n";
        echo "Background worker that pre-computes feeds for active users.\n";
        echo "Reduces feed response time from 50-100ms to 5-10ms.\n\n";
        echo "Usage: php feed-precompute-worker.php [options]\n\n";
        echo "Options:\n";
        echo "  --max-runtime=N   Maximum runtime in seconds (default: 300)\n";
        echo "  --help            Show this help\n\n";
        echo "Architecture:\n";
        echo "  1. Process refresh queue (invalidated feeds)\n";
        echo "  2. Proactive pre-computation for active users\n";
        echo "  3. Circuit breaker for load protection\n\n";
        echo "Circuit Breaker:\n";
        echo "  - OPEN when queue > 10,000 items\n";
        echo "  - CLOSED when queue < 5,000 items\n\n";
        exit(0);
    }

    $maxRuntime = isset($options['max-runtime']) ? max(30, (int)$options['max-runtime']) : 300;

    try {
        $worker = new FeedPrecomputeWorker($maxRuntime);
        return $worker->run();
    } catch (\Exception $e) {
        enterprise_log("[FEED-PRECOMPUTE] ❌ Worker failed: " . $e->getMessage());
        echo "ERROR: " . $e->getMessage() . "\n";
        return 1;
    }
}

exit(main());
