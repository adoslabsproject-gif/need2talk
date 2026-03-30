#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Session Sync Worker - Enterprise Galaxy (Adaptive)
 *
 * Async worker per sincronizzare sessioni Redis → PostgreSQL
 * Per GDPR compliance e audit trail senza bloccare le request
 *
 * ADAPTIVE SCHEDULING (same pattern as overlay-flush-worker):
 * ┌────────────────┬─────────────┬────────────────┬─────────────────┐
 * │ Activity Level │ Sessions    │ Sync Interval  │ Max Runtime     │
 * ├────────────────┼─────────────┼────────────────┼─────────────────┤
 * │ IDLE           │ 0           │ exit           │ 0 (immediate)   │
 * │ LOW            │ 1-50        │ 2 minutes      │ 5 seconds       │
 * │ NORMAL         │ 51-200      │ 30 seconds     │ 2 minutes       │
 * │ HIGH           │ 200+        │ 5 seconds      │ 5 minutes       │
 * └────────────────┴─────────────┴────────────────┴─────────────────┘
 *
 * CRON CONFIGURATION:
 *   Every 5 minutes (cron: 0,5,10,15,20,25,30,35,40,45,50,55)
 *   Worker adapts interval internally based on load
 *   Exits when queue empty (IDLE) -> Cron restarts next cycle
 *
 * Performance:
 * - Batch INSERT (100 sessioni/query)
 * - Adaptive interval (5s to 2min based on load)
 * - ZERO impatto sulle request real-time
 *
 * Usage:
 * php scripts/session-sync-worker.php [--batch-size=100]
 */

// ENTERPRISE: Long-running process configuration
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');
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
use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\EnterpriseSecureDatabasePool;

/**
 * Activity levels for adaptive scheduling
 * ENTERPRISE GALAXY: Same pattern as overlay-flush-worker (proven, tested)
 */
class SessionActivityLevel
{
    public const IDLE = 'idle';       // 0 sessions
    public const LOW = 'low';         // 1-50 sessions
    public const NORMAL = 'normal';   // 51-200 sessions
    public const HIGH = 'high';       // 200+ sessions

    /**
     * Get sync interval in seconds for activity level
     */
    public static function getSyncInterval(string $level): int
    {
        return match ($level) {
            self::IDLE => 0,       // No sync needed, exit
            self::LOW => 120,      // 2 minutes
            self::NORMAL => 30,    // 30 seconds
            self::HIGH => 5,       // 5 seconds
            default => 30,
        };
    }

    /**
     * Get max runtime in seconds for activity level
     */
    public static function getMaxRuntime(string $level): int
    {
        return match ($level) {
            self::IDLE => 0,       // Exit immediately
            self::LOW => 5,        // Single sync, exit
            self::NORMAL => 120,   // 2 minutes continuous
            self::HIGH => 300,     // 5 minutes continuous
            default => 120,
        };
    }

    /**
     * Determine activity level from session count
     */
    public static function fromSessionCount(int $count): string
    {
        if ($count === 0) {
            return self::IDLE;
        } elseif ($count <= 50) {
            return self::LOW;
        } elseif ($count <= 200) {
            return self::NORMAL;
        } else {
            return self::HIGH;
        }
    }
}

/**
 * Enterprise Session Sync Worker (Adaptive)
 */
class SessionSyncWorker
{
    private ?\Redis $redis = null;
    private $pdo = null;
    private bool $running = true;
    private string $workerId;
    private array $config;
    private int $syncedCount = 0;
    private int $errorCount = 0;
    private float $startTime;
    private string $activityLevel = SessionActivityLevel::IDLE;
    private int $maxRuntime = 300; // Default, adjusted dynamically

    // Enterprise limits
    private const MEMORY_THRESHOLD_MB = 256;
    private const CONNECTION_RECYCLE_INTERVAL = 300; // 5 minutes
    private float $lastConnectionRecycle = 0;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 100,       // 100 sessioni per batch
            'max_errors' => 50,
        ], $config);

        $this->workerId = 'session_sync_' . uniqid() . '_' . getmypid();
        $this->startTime = microtime(true);
        $this->lastConnectionRecycle = microtime(true);

        $this->setupSignalHandlers();
        $this->initializeConnections();

        Logger::info("[SESSION-SYNC] Worker started: {$this->workerId} (PID: " . getmypid() . ")");
    }

    /**
     * Initialize Redis and PostgreSQL connections
     */
    private function initializeConnections(): void
    {
        try {
            // Redis connection (DB 1 = sessions)
            $redisManager = EnterpriseRedisManager::getInstance();
            $this->redis = $redisManager->getConnection('sessions');

            if (!$this->redis) {
                throw new \Exception('Redis connection failed for sessions');
            }

            // PostgreSQL connection
            $this->pdo = EnterpriseSecureDatabasePool::getInstance()->getConnection();

            if (!$this->pdo) {
                throw new \Exception('PostgreSQL connection failed');
            }

            Logger::info("[SESSION-SYNC] Connections initialized (Redis DB 1 + PostgreSQL)");
        } catch (\Exception $e) {
            Logger::info("[SESSION-SYNC] ❌ Connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Recycle connections to prevent "went away" errors
     */
    private function recycleConnectionsIfNeeded(): void
    {
        $currentTime = microtime(true);

        if (($currentTime - $this->lastConnectionRecycle) > self::CONNECTION_RECYCLE_INTERVAL) {
            Logger::info("[SESSION-SYNC] 🔄 Recycling connections (preventive maintenance)");

            try {
                if ($this->pdo) {
                    EnterpriseSecureDatabasePool::getInstance()->releaseConnection($this->pdo);
                }
                if ($this->redis) {
                    $this->redis->close();
                }
            } catch (\Exception $e) {
                Logger::warning('SESSION_SYNC: Connection close error during recycle', [
                    'error' => $e->getMessage(),
                ]);
            }

            $this->redis = null;
            $this->pdo = null;

            $this->initializeConnections();
            $this->lastConnectionRecycle = $currentTime;
        }
    }

    /**
     * Main worker loop with ADAPTIVE scheduling
     */
    public function run(): void
    {
        Logger::info("[SESSION-SYNC] 🚀 Worker starting with adaptive scheduling");

        // Step 1: Count pending sessions
        $sessionCount = $this->countPendingSessions();

        // Step 2: Determine activity level
        $this->activityLevel = SessionActivityLevel::fromSessionCount($sessionCount);

        Logger::info("[SESSION-SYNC] 📊 Session count: {$sessionCount} → Activity: {$this->activityLevel}");

        // Step 3: Exit immediately if IDLE (no sessions to sync)
        if ($this->activityLevel === SessionActivityLevel::IDLE) {
            Logger::info("[SESSION-SYNC] 😴 No sessions to sync, exiting (cron will restart in 5min)");
            $this->shutdown();
            return;
        }

        // Step 4: Adjust max runtime based on activity level
        $this->maxRuntime = SessionActivityLevel::getMaxRuntime($this->activityLevel);

        Logger::info("[SESSION-SYNC] ⚙️ Adaptive config: interval=" .
            SessionActivityLevel::getSyncInterval($this->activityLevel) . "s, max_runtime={$this->maxRuntime}s");

        // Main loop
        while ($this->running && $this->shouldContinueRunning()) {
            try {
                set_time_limit(0);

                // Recycle connections periodically
                $this->recycleConnectionsIfNeeded();

                // Sync sessions Redis → PostgreSQL
                $synced = $this->syncSessions();
                $this->syncedCount += $synced;

                if ($synced > 0) {
                    Logger::info("[SESSION-SYNC] ✅ Batch synced: {$synced} sessions | Total: {$this->syncedCount}");
                }

                // Cleanup expired sessions from PostgreSQL (every 10 batches)
                if ($this->syncedCount % 10 === 0 && $this->syncedCount > 0) {
                    $this->cleanupExpiredSessions();
                }

                // Re-evaluate activity level after each sync
                $this->updateActivityLevel();

                // Sleep based on current activity level
                $sleepSeconds = SessionActivityLevel::getSyncInterval($this->activityLevel);

                // Exit if activity dropped to IDLE
                if ($this->activityLevel === SessionActivityLevel::IDLE) {
                    Logger::info("[SESSION-SYNC] 😴 Queue empty, exiting");
                    break;
                }

                // Exit if activity is LOW (single sync done)
                if ($this->activityLevel === SessionActivityLevel::LOW) {
                    Logger::info("[SESSION-SYNC] 📉 Low activity, single sync done, exiting");
                    break;
                }

                if ($sleepSeconds > 0) {
                    sleep($sleepSeconds);
                }

            } catch (\Exception $e) {
                $this->handleError($e);
            }
        }

        $this->shutdown();
    }

    /**
     * Count pending sessions in Redis
     */
    private function countPendingSessions(): int
    {
        try {
            $pattern = 'n2t:sess:*';
            $count = 0;
            $cursor = null;

            // Use SCAN to count (memory efficient)
            while (($keys = $this->redis->scan($cursor, $pattern, 1000)) !== false) {
                if (is_array($keys)) {
                    $count += count($keys);
                }
                if ($cursor === 0) {
                    break;
                }
            }

            return $count;
        } catch (\Exception $e) {
            Logger::info("[SESSION-SYNC] ⚠️ Failed to count sessions: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Update activity level based on current session count
     */
    private function updateActivityLevel(): void
    {
        $count = $this->countPendingSessions();
        $newLevel = SessionActivityLevel::fromSessionCount($count);

        if ($newLevel !== $this->activityLevel) {
            Logger::info("[SESSION-SYNC] 📈 Activity changed: {$this->activityLevel} → {$newLevel} ({$count} sessions)");
            $this->activityLevel = $newLevel;
        }
    }

    /**
     * Sync sessions from Redis to PostgreSQL (BATCH INSERT)
     */
    private function syncSessions(): int
    {
        try {
            $pattern = 'n2t:sess:*';
            $cursor = null;
            $sessionKeys = [];

            // Use SCAN for enterprise scalability
            while (($keys = $this->redis->scan($cursor, $pattern, 100)) !== false) {
                if (is_array($keys)) {
                    $sessionKeys = array_merge($sessionKeys, $keys);
                }

                if ($cursor === 0) {
                    break;
                }

                if (count($sessionKeys) >= $this->config['batch_size']) {
                    break;
                }
            }

            if (empty($sessionKeys)) {
                return 0;
            }

            $sessionKeys = array_slice($sessionKeys, 0, $this->config['batch_size']);
            $batchData = [];

            foreach ($sessionKeys as $key) {
                $sessionId = str_replace('n2t:sess:', '', $key);
                $sessionData = $this->redis->get($key);

                if ($sessionData === false) {
                    continue;
                }

                $ttl = $this->redis->ttl($key);
                $lastActivity = time();

                if ($ttl > 0) {
                    $sessionLifetime = 3600;
                    $lastActivity = time() - ($sessionLifetime - $ttl);
                }

                $userIp = '0.0.0.0';
                $userAgent = 'unknown';

                if (preg_match('/user_ip\|s:\d+:"([^"]+)";/', $sessionData, $matches)) {
                    $userIp = $matches[1];
                }
                if (preg_match('/user_agent\|s:\d+:"([^"]+)";/', $sessionData, $matches)) {
                    $userAgent = $matches[1];
                }

                $batchData[] = [
                    'id' => $sessionId,
                    'payload' => $sessionData,
                    'last_activity' => $lastActivity,
                    'user_ip' => $userIp,
                    'user_agent' => $userAgent,
                ];
            }

            if (empty($batchData)) {
                return 0;
            }

            return $this->batchInsertSessions($batchData);

        } catch (\Exception $e) {
            Logger::info("[SESSION-SYNC] ❌ Sync error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Batch INSERT sessions into PostgreSQL
     */
    private function batchInsertSessions(array $batchData): int
    {
        if (empty($batchData)) {
            return 0;
        }

        try {
            $query = "INSERT INTO sessions (id, payload, last_activity, user_agent, ip_address) VALUES ";
            $values = [];
            $params = [];

            foreach ($batchData as $session) {
                $values[] = "(?, ?, ?, ?, ?)";
                $params[] = $session['id'];
                $params[] = $session['payload'];
                $params[] = $session['last_activity'];
                $params[] = $session['user_agent'];
                $params[] = $session['user_ip'];
            }

            $query .= implode(', ', $values);
            $query .= " ON CONFLICT (id) DO UPDATE SET
                payload = EXCLUDED.payload,
                last_activity = EXCLUDED.last_activity,
                user_agent = EXCLUDED.user_agent,
                ip_address = EXCLUDED.ip_address";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);

            return count($batchData);

        } catch (\Exception $e) {
            Logger::info("[SESSION-SYNC] ❌ Batch insert failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cleanup expired sessions from PostgreSQL
     */
    private function cleanupExpiredSessions(): void
    {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM sessions
                WHERE ctid IN (
                    SELECT ctid FROM sessions
                    WHERE last_activity < ?
                    LIMIT 1000
                )
            ");

            $expiryTime = time() - (24 * 3600);
            $stmt->execute([$expiryTime]);

            $deleted = $stmt->rowCount();
            if ($deleted > 0) {
                Logger::info("[SESSION-SYNC] 🗑️ Cleaned up {$deleted} expired sessions");
            }

        } catch (\Exception $e) {
            Logger::info("[SESSION-SYNC] ⚠️ Cleanup warning: " . $e->getMessage());
        }
    }

    /**
     * Check if worker should continue running
     */
    private function shouldContinueRunning(): bool
    {
        $runtime = microtime(true) - $this->startTime;

        if ($runtime > $this->maxRuntime) {
            Logger::info("[SESSION-SYNC] ⏰ Max runtime reached ({$runtime}s)");
            return false;
        }

        if ($this->errorCount > $this->config['max_errors']) {
            Logger::info("[SESSION-SYNC] ❌ Max errors reached ({$this->errorCount})");
            return false;
        }

        $memoryMB = memory_get_usage(true) / 1024 / 1024;
        if ($memoryMB > self::MEMORY_THRESHOLD_MB) {
            Logger::info("[SESSION-SYNC] 💾 Memory threshold exceeded ({$memoryMB}MB)");
            return false;
        }

        return true;
    }

    /**
     * Handle errors
     */
    private function handleError(\Exception $e): void
    {
        $this->errorCount++;
        Logger::info("[SESSION-SYNC] ❌ Error #{$this->errorCount}: " . $e->getMessage());

        Logger::error('SESSION_SYNC_WORKER_ERROR', [
            'worker_id' => $this->workerId,
            'error' => $e->getMessage(),
            'error_count' => $this->errorCount,
        ]);

        sleep(min($this->errorCount, 30));
    }

    /**
     * Setup signal handlers
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleReloadSignal']);
        }
    }

    public function handleShutdownSignal(int $signal): void
    {
        Logger::info("[SESSION-SYNC] 🛑 Shutdown signal received");
        $this->running = false;
    }

    public function handleReloadSignal(int $signal): void
    {
        Logger::info("[SESSION-SYNC] 🔄 Reload signal received");
        $this->running = false;
    }

    /**
     * Graceful shutdown
     */
    private function shutdown(): void
    {
        $runtime = microtime(true) - $this->startTime;

        try {
            if ($this->pdo) {
                EnterpriseSecureDatabasePool::getInstance()->releaseConnection($this->pdo);
                $this->pdo = null;
            }
            if ($this->redis) {
                $this->redis->close();
                $this->redis = null;
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        Logger::info("[SESSION-SYNC] 👋 Shutdown | Synced: {$this->syncedCount} | Errors: {$this->errorCount} | Runtime: " . round($runtime, 2) . "s | Final level: {$this->activityLevel}");

        Logger::info('SESSION_SYNC_WORKER_SHUTDOWN', [
            'worker_id' => $this->workerId,
            'total_synced' => $this->syncedCount,
            'total_errors' => $this->errorCount,
            'runtime_seconds' => round($runtime, 2),
            'final_activity_level' => $this->activityLevel,
        ]);
    }
}

/**
 * Main execution
 */
function main(): void
{
    $options = getopt('', ['batch-size:', 'help']);

    if (isset($options['help'])) {
        echo "Session Sync Worker - Enterprise Galaxy (Adaptive)\n";
        echo "Usage: php session-sync-worker.php [options]\n\n";
        echo "Options:\n";
        echo "  --batch-size=N    Sessions to sync per batch (default: 100)\n";
        echo "  --help            Show this help\n\n";
        echo "Adaptive Scheduling:\n";
        echo "  IDLE (0 sessions)      → exit immediately\n";
        echo "  LOW (1-50 sessions)    → sync once, exit (2min interval if continuous)\n";
        echo "  NORMAL (51-200)        → 30 second interval, 2min max runtime\n";
        echo "  HIGH (200+)            → 5 second interval, 5min max runtime\n\n";
        exit(0);
    }

    $config = [];
    if (isset($options['batch-size'])) {
        $config['batch_size'] = max(50, (int) $options['batch-size']);
    }

    try {
        $worker = new SessionSyncWorker($config);
        $worker->run();
        exit(0);
    } catch (\Exception $e) {
        Logger::info("[SESSION-SYNC] ❌ Worker failed: " . $e->getMessage());
        exit(1);
    }
}

main();
