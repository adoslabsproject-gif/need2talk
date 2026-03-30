#!/usr/bin/env php
<?php

/**
 * Room Inactivity Kick Cron Job
 *
 * ENTERPRISE V10.56 (2025-12-07)
 *
 * Kicks users from chat rooms (both emotion and user-created) after 1 hour of inactivity.
 * Uses Redis HASH to track last activity timestamps per user per room.
 *
 * Key structure:
 * - Emotion rooms: chat:room:emotion:{id}:activity → HASH {userUuid: timestamp}
 * - User rooms: chat:room:private:{uuid}:activity → HASH {userUuid: timestamp}
 *
 * The kicked users receive a WebSocket notification with the message:
 * "Sei stato accompagnato alla porta per inattività. Torna quando vuoi!"
 *
 * Crontab entry (run every 5 minutes):
 * *\/5 * * * * php /var/www/need2talk/scripts/cron-room-inactivity-kick.php >> /var/www/need2talk/storage/logs/inactivity_kick.log 2>&1
 *
 * @package Need2Talk\Scripts
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-07
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

// Configuration
const INACTIVITY_THRESHOLD_SECONDS = 3600; // 1 hour
const BATCH_SIZE = 100;                     // Process 100 rooms per batch

// Script lock to prevent overlapping runs
$lockFile = APP_ROOT . '/storage/locks/inactivity_kick.lock';
$lockDir = dirname($lockFile);

if (!is_dir($lockDir)) {
    if (!mkdir($lockDir, 0775, true)) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: Cannot create lock directory: {$lockDir}\n";
        exit(1);
    }
}

$fp = @fopen($lockFile, 'c+');
if ($fp === false) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Cannot open lock file: {$lockFile}\n";
    exit(1);
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Another instance is already running. Exiting.\n";
    fclose($fp);
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting room inactivity check...\n";

$startTime = microtime(true);
$stats = [
    'rooms_checked' => 0,
    'users_kicked' => 0,
    'errors' => 0,
];

try {
    $redis = EnterpriseRedisManager::getInstance()->getConnection('chat');

    if (!$redis) {
        throw new \Exception('Failed to connect to Redis (chat pool)');
    }

    $now = time();
    $threshold = $now - INACTIVITY_THRESHOLD_SECONDS;

    // ========================================================================
    // 1. Check EMOTION ROOMS (10 fixed rooms)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Checking emotion rooms...\n";

    $emotionRooms = [
        'emotion:joy', 'emotion:sadness', 'emotion:anxiety', 'emotion:anger',
        'emotion:fear', 'emotion:love', 'emotion:loneliness', 'emotion:hope',
        'emotion:calm', 'emotion:confusion',
    ];

    foreach ($emotionRooms as $roomId) {
        $stats['rooms_checked']++;
        $kicked = processRoom($redis, $roomId, 'chat:room:', $threshold, $now);
        $stats['users_kicked'] += $kicked;
    }

    // ========================================================================
    // 2. Check USER ROOMS (scan for active rooms)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Checking user rooms...\n";

    // Scan for activity keys of user rooms
    $pattern = 'chat:room:private:*:activity';
    $iterator = null;

    do {
        $keys = $redis->scan($iterator, $pattern, BATCH_SIZE);
        if ($keys) {
            foreach ($keys as $activityKey) {
                // Extract room UUID from key: chat:room:private:{uuid}:activity
                if (preg_match('/chat:room:private:([^:]+):activity/', $activityKey, $matches)) {
                    $roomUuid = $matches[1];
                    $stats['rooms_checked']++;
                    $kicked = processRoom($redis, $roomUuid, 'chat:room:private:', $threshold, $now);
                    $stats['users_kicked'] += $kicked;
                }
            }
        }
    } while ($iterator > 0);

    // Calculate duration
    $duration = round((microtime(true) - $startTime) * 1000, 2);

    // Log results
    if ($stats['users_kicked'] > 0) {
        Logger::info('Room inactivity kick completed', [
            'stats' => $stats,
            'duration_ms' => $duration,
        ]);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Completed in {$duration}ms\n";
    echo "[" . date('Y-m-d H:i:s') . "] Stats: " . json_encode($stats) . "\n";

} catch (\Exception $e) {
    $stats['errors']++;
    Logger::error('Room inactivity kick failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);

} finally {
    // Release lock
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink($lockFile);
}

exit(0);

/**
 * Process a single room for inactivity kicks
 *
 * @param \Redis $redis Redis connection
 * @param string $roomId Room ID or UUID
 * @param string $prefix Key prefix (chat:room: for emotions, chat:room:private: for user rooms)
 * @param int $threshold Timestamp threshold (users older than this get kicked)
 * @param int $now Current timestamp
 * @return int Number of users kicked
 */
function processRoom(\Redis $redis, string $roomId, string $prefix, int $threshold, int $now): int
{
    $kicked = 0;

    try {
        $onlineKey = $prefix . $roomId . ':online';
        $activityKey = $prefix . $roomId . ':activity';

        // Get all activity timestamps
        $activities = $redis->hGetAll($activityKey);

        if (empty($activities)) {
            return 0;
        }

        $usersToKick = [];

        foreach ($activities as $userUuid => $lastActivity) {
            $lastActivityTs = (int) $lastActivity;

            // Check if user is inactive (last activity > 1 hour ago)
            if ($lastActivityTs < $threshold) {
                $usersToKick[] = $userUuid;
            }
        }

        if (empty($usersToKick)) {
            return 0;
        }

        // Remove inactive users from online set and activity hash
        foreach ($usersToKick as $userUuid) {
            // Remove from online set
            $redis->sRem($onlineKey, $userUuid);

            // Remove from activity hash
            $redis->hDel($activityKey, $userUuid);

            // Publish kick notification via Redis pub/sub
            // The WebSocket server listens for this and sends the notification to the user
            $redis->publish('room:inactivity_kick', json_encode([
                'room_id' => $roomId,
                'user_uuid' => $userUuid,
                'message' => 'Sei stato accompagnato alla porta per inattività. Torna quando vuoi!',
                'timestamp' => $now,
            ]));

            $kicked++;

            echo "[" . date('Y-m-d H:i:s') . "]   Kicked {$userUuid} from {$roomId} (inactive " .
                 round(($now - (int) $activities[$userUuid]) / 60) . " min)\n";
        }

        // Publish updated online count for the room
        $newCount = (int) $redis->sCard($onlineKey);
        $redis->publish('room:online_count_updated', json_encode([
            'room_id' => $roomId,
            'online_count' => $newCount,
        ]));

    } catch (\Exception $e) {
        Logger::warning('Failed to process room for inactivity', [
            'room_id' => $roomId,
            'error' => $e->getMessage(),
        ]);
    }

    return $kicked;
}
