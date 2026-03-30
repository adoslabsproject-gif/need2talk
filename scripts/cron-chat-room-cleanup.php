#!/usr/bin/env php
<?php

/**
 * Chat Room Cleanup Cron Job
 *
 * ENTERPRISE GALAXY CHAT (2025-12-02)
 *
 * This script runs every minute to:
 * 1. Auto-close EMPTY user rooms (5 min after last user leaves)
 * 2. Clean up stale presence data
 * 3. Remove orphaned typing indicators
 * 4. Archive old room messages from Redis
 *
 * Crontab entry:
 * /5 * * * * php /var/www/need2talk/scripts/cron-chat-room-cleanup.php >> /var/www/need2talk/storage/logs/chat_cleanup.log 2>&1
 *
 * @package Need2Talk\Scripts
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

// Script lock to prevent overlapping runs
$lockFile = APP_ROOT . '/storage/locks/chat_cleanup.lock';
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

echo "[" . date('Y-m-d H:i:s') . "] Starting chat room cleanup...\n";

$startTime = microtime(true);
$stats = [
    'rooms_archived' => 0,
    'rooms_hard_deleted' => 0,
    'rooms_checked' => 0,
    'presence_cleaned' => 0,
    'typing_cleaned' => 0,
    'messages_trimmed' => 0,
    'errors' => 0,
];

try {
    $redis = EnterpriseRedisManager::getInstance()->getConnection('chat');

    if (!$redis) {
        throw new Exception('Failed to connect to Redis (chat pool)');
    }

    // ========================================================================
    // 1. AUTO-CLOSE EMPTY USER ROOMS (10 min after last user leaves)
    // ENTERPRISE V10.83: Rooms stay open while users are inside (member_count > 0)
    //                    Rooms close 10 min after becoming empty (trigger sets auto_close_at)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Checking for empty rooms to close...\n";

    $db = db();

    // Find rooms where:
    // 1. Status is active
    // 2. auto_close_at has passed (room has been empty for 10 minutes)
    // 3. member_count = 0 (double-check room is truly empty)
    // ENTERPRISE V10.142: CRITICAL - Disable cache for real-time room status check
    // Cache would return stale data preventing timely room closure
    $activeRooms = $db->findMany(
        "SELECT id, uuid, name, last_activity_at, member_count, auto_close_at, created_at
         FROM chat_rooms
         WHERE status = 'active'
         AND room_type IN ('user_created', 'private')
         AND deleted_at IS NULL
         AND auto_close_at IS NOT NULL
         AND auto_close_at < NOW()
         AND member_count = 0",
        [],
        ['cache' => false]
    );

    $stats['rooms_checked'] = count($activeRooms);
    $roomsToClose = [];

    // Verify each room's Redis online count as a safety check
    foreach ($activeRooms as $room) {
        $onlineKey = 'chat:room:private:' . $room['uuid'] . ':online';
        $onlineCount = (int) $redis->sCard($onlineKey);

        // Double-check: only close if Redis also shows 0 users
        if ($onlineCount === 0) {
            $roomsToClose[] = $room;
        } else {
            // Redis has users but DB shows empty - sync member_count
            echo "[" . date('Y-m-d H:i:s') . "] Room {$room['uuid']} has Redis users ({$onlineCount}) but DB shows empty. Syncing...\n";
            $db->execute(
                "UPDATE chat_rooms SET member_count = :count WHERE id = :id",
                ['count' => $onlineCount, 'id' => $room['id']]
            );
        }
    }

    $inactiveRooms = $roomsToClose;

    foreach ($inactiveRooms as $room) {
        try {
            // Archive the room
            $db->execute(
                "UPDATE chat_rooms SET status = 'archived', deleted_at = NOW() WHERE id = :id",
                ['id' => $room['id']]
            );

            // Clean up Redis keys for this room
            $roomRedisKey = 'chat:room:private:' . $room['uuid'];
            $keysToDelete = [];

            // Find all keys for this room
            $iterator = null;
            do {
                $keys = $redis->scan($iterator, $roomRedisKey . ':*', 100);
                if ($keys) {
                    $keysToDelete = array_merge($keysToDelete, $keys);
                }
            } while ($iterator > 0);

            // Delete the keys
            if (!empty($keysToDelete)) {
                $redis->del(...$keysToDelete);
            }

            $stats['rooms_archived']++;
            echo "[" . date('Y-m-d H:i:s') . "] Archived room: {$room['name']} ({$room['uuid']})\n";

        } catch (Exception $e) {
            $stats['errors']++;
            Logger::warning('Failed to archive room', [
                'room_uuid' => $room['uuid'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ========================================================================
    // 1b. HARD DELETE OLD ARCHIVED/DELETED ROOMS (older than 24 hours)
    // ENTERPRISE V11.8: Remove zombie rooms from database permanently
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Hard deleting old archived rooms...\n";

    $deletedRooms = $db->execute(
        "DELETE FROM chat_rooms
         WHERE status IN ('archived', 'deleted')
         AND deleted_at IS NOT NULL
         AND deleted_at < NOW() - INTERVAL '24 hours'
         RETURNING id, name",
        [],
        ['cache' => false]
    );

    $hardDeleteCount = is_array($deletedRooms) ? count($deletedRooms) : 0;
    $stats['rooms_hard_deleted'] = $hardDeleteCount;

    if ($hardDeleteCount > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Hard deleted {$hardDeleteCount} old archived rooms\n";
    }

    // ========================================================================
    // 2. CLEAN UP STALE PRESENCE DATA (expired TTLs should auto-clean, but verify)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning stale presence data...\n";

    // Presence keys should auto-expire with TTL, but scan for any orphaned ones
    $presencePattern = 'chat:presence:*';
    $iterator = null;
    $presenceCount = 0;

    do {
        $keys = $redis->scan($iterator, $presencePattern, 100);
        if ($keys) {
            foreach ($keys as $key) {
                // Check if key has TTL, if not, set one or delete
                $ttl = $redis->ttl($key);
                if ($ttl === -1) {
                    // Key exists but no TTL - set one
                    $redis->expire($key, 300); // 5 min TTL
                    $presenceCount++;
                }
            }
        }
    } while ($iterator > 0);

    $stats['presence_cleaned'] = $presenceCount;

    // ========================================================================
    // 3. CLEAN UP ORPHANED TYPING INDICATORS
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning orphaned typing indicators...\n";

    // Typing keys should have 3s TTL, but verify
    $typingPatterns = ['chat:typing:room:*', 'chat:typing:dm:*'];
    $typingCount = 0;

    foreach ($typingPatterns as $pattern) {
        $iterator = null;
        do {
            $keys = $redis->scan($iterator, $pattern, 100);
            if ($keys) {
                foreach ($keys as $key) {
                    $ttl = $redis->ttl($key);
                    if ($ttl === -1) {
                        // No TTL - delete stale typing indicator
                        $redis->del($key);
                        $typingCount++;
                    } elseif ($ttl > 10) {
                        // TTL too long - reset to 3s
                        $redis->expire($key, 3);
                        $typingCount++;
                    }
                }
            }
        } while ($iterator > 0);
    }

    $stats['typing_cleaned'] = $typingCount;

    // ========================================================================
    // 4. DELETE OLD MESSAGES FROM EMOTION ROOMS (TTL-based + max 100 trim)
    // ENTERPRISE FIX v11.9 (2025-12-15): Messages MUST be deleted after 1 hour
    // Previously only trimmed to 100, now also deletes by age
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning emotion room messages (TTL + trim)...\n";

    $emotionRooms = [
        'emotion:joy', 'emotion:sadness', 'emotion:anxiety', 'emotion:anger',
        'emotion:fear', 'emotion:love', 'emotion:loneliness', 'emotion:hope',
        'emotion:calm', 'emotion:confusion',
    ];

    // TTL: 1 hour (3600 seconds) - matches EmotionRoomService::MESSAGE_TTL_SECONDS
    $messageTtlSeconds = 3600;
    $cutoffTimestamp = microtime(true) - $messageTtlSeconds;

    foreach ($emotionRooms as $roomId) {
        $messagesKey = "chat:room:{$roomId}:messages";

        // STEP 1: Delete messages older than TTL (score = timestamp)
        // zRemRangeByScore removes members with scores in the given range
        // -inf to cutoff = all messages older than 1 hour
        $deletedByTtl = $redis->zRemRangeByScore($messagesKey, '-inf', (string) $cutoffTimestamp);
        if ($deletedByTtl > 0) {
            $stats['messages_trimmed'] += $deletedByTtl;
            echo "[" . date('Y-m-d H:i:s') . "] Deleted {$deletedByTtl} expired messages from {$roomId}\n";
        }

        // STEP 2: Trim to max 100 messages (safety limit)
        $count = $redis->zCard($messagesKey);
        if ($count > 100) {
            $toRemove = $count - 100;
            $redis->zRemRangeByRank($messagesKey, 0, $toRemove - 1);
            $stats['messages_trimmed'] += $toRemove;
            echo "[" . date('Y-m-d H:i:s') . "] Trimmed {$toRemove} excess messages from {$roomId}\n";
        }
    }

    // ========================================================================
    // 4b. DELETE OLD MESSAGES FROM USER ROOMS (TTL-based cleanup)
    // ENTERPRISE FIX v11.9 (2025-12-15): User rooms have 4-hour TTL
    // Scan all active user room message keys and delete expired messages
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning user room messages (TTL-based)...\n";

    // User rooms TTL: 4 hours (14400 seconds) - matches ChatRoomService::MESSAGE_TTL_SECONDS
    $userRoomTtlSeconds = 14400;
    $userRoomCutoff = microtime(true) - $userRoomTtlSeconds;

    // Scan for user room message keys (pattern: chat:room:private:*:messages)
    $userRoomPattern = 'chat:room:private:*:messages';
    $iterator = null;
    $userRoomMessagesDeleted = 0;

    do {
        $keys = $redis->scan($iterator, $userRoomPattern, 100);
        if ($keys) {
            foreach ($keys as $messagesKey) {
                // Delete messages older than 4 hours
                $deleted = $redis->zRemRangeByScore($messagesKey, '-inf', (string) $userRoomCutoff);
                if ($deleted > 0) {
                    $userRoomMessagesDeleted += $deleted;
                }
            }
        }
    } while ($iterator > 0);

    if ($userRoomMessagesDeleted > 0) {
        $stats['messages_trimmed'] += $userRoomMessagesDeleted;
        echo "[" . date('Y-m-d H:i:s') . "] Deleted {$userRoomMessagesDeleted} expired messages from user rooms\n";
    }

    // ========================================================================
    // 5. CLEAN UP OLD LAST_SEEN DATA (older than 7 days)
    // ========================================================================
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning old last_seen data...\n";

    $lastSeenPattern = 'chat:lastseen:*';
    $iterator = null;
    $oneWeekAgo = time() - (7 * 86400);

    do {
        $keys = $redis->scan($iterator, $lastSeenPattern, 100);
        if ($keys) {
            foreach ($keys as $key) {
                $value = $redis->get($key);
                if ($value && (int) $value < $oneWeekAgo) {
                    $redis->del($key);
                }
            }
        }
    } while ($iterator > 0);

    // Calculate duration
    $duration = round((microtime(true) - $startTime) * 1000, 2);

    // Log results
    Logger::info('Chat cleanup completed', [
        'stats' => $stats,
        'duration_ms' => $duration,
    ]);

    echo "[" . date('Y-m-d H:i:s') . "] Cleanup completed in {$duration}ms\n";
    echo "[" . date('Y-m-d H:i:s') . "] Stats: " . json_encode($stats) . "\n";

} catch (Exception $e) {
    $stats['errors']++;
    Logger::error('Chat cleanup failed', [
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
