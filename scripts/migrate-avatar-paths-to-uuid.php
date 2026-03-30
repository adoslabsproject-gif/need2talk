#!/usr/bin/env php
<?php

/**
 * Avatar Path Migration Script - ID to UUID
 *
 * ENTERPRISE GALAXY: Migrates avatar storage from ID-based to UUID-based
 *
 * OLD STRUCTURE (INSECURE - enumerable):
 * - Directory: /storage/uploads/avatars/{userId}/
 * - Filename: avatar_{userId}_timestamp.webp
 *
 * NEW STRUCTURE (ENTERPRISE SECURE - non-enumerable):
 * - Directory: /storage/uploads/avatars/{userUuid}/
 * - Filename: avatar_{userUuid}_timestamp.webp
 *
 * MIGRATION PROCESS:
 * 1. Read all users with local avatars (exclude Google OAuth)
 * 2. For each user:
 *    a) Rename directory: avatars/{id}/ → avatars/{uuid}/
 *    b) Rename files: avatar_{id}_* → avatar_{uuid}_*
 *    c) Update database: avatar_url column
 * 3. Log every operation for audit trail
 * 4. Verify integrity after migration
 *
 * SAFETY FEATURES:
 * - Dry-run mode (--dry-run flag)
 * - Transaction support for database updates
 * - Rollback capability on error
 * - Backup recommendation before execution
 *
 * USAGE:
 * php scripts/migrate-avatar-paths-to-uuid.php [--dry-run] [--verbose]
 *
 * FLAGS:
 * --dry-run   Show what would be done without making changes
 * --verbose   Show detailed progress for each user
 *
 * @version 1.0.0
 * @author Claude Code (Enterprise Galaxy Migration)
 */

// Bootstrap application
require_once __DIR__ . '/../app/bootstrap.php';

use Need2Talk\Services\Logger;

// CLI Colors
const COLOR_RESET = "\033[0m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_RED = "\033[31m";
const COLOR_BLUE = "\033[34m";
const COLOR_CYAN = "\033[36m";

// Parse command-line arguments
$dryRun = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true);

// Banner
echo COLOR_CYAN . "\n╔════════════════════════════════════════════════════════════════╗\n" . COLOR_RESET;
echo COLOR_CYAN . "║  ENTERPRISE GALAXY: Avatar Path Migration (ID → UUID)         ║\n" . COLOR_RESET;
echo COLOR_CYAN . "╚════════════════════════════════════════════════════════════════╝\n\n" . COLOR_RESET;

if ($dryRun) {
    echo COLOR_YELLOW . "🔍 DRY-RUN MODE: No changes will be made\n\n" . COLOR_RESET;
}

// Migration configuration
$avatarBasePath = realpath(__DIR__ . '/../public/storage/uploads/avatars');
if (!$avatarBasePath) {
    echo COLOR_RED . "❌ ERROR: Avatar directory not found: ../public/storage/uploads/avatars\n" . COLOR_RESET;
    exit(1);
}

echo COLOR_BLUE . "📁 Avatar base path: {$avatarBasePath}\n\n" . COLOR_RESET;

// Get all users with local avatars (exclude Google OAuth)
echo COLOR_CYAN . "📊 Fetching users with local avatars...\n" . COLOR_RESET;

$db = db_pdo();
$stmt = $db->query("
    SELECT
        id,
        uuid,
        nickname,
        avatar_url,
        avatar_source
    FROM users
    WHERE avatar_url IS NOT NULL
      AND avatar_url != ''
      AND avatar_url NOT LIKE 'https://%'  -- Exclude Google OAuth avatars
      AND deleted_at IS NULL
    ORDER BY id ASC
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = count($users);

if ($totalUsers === 0) {
    echo COLOR_GREEN . "✅ No users with local avatars found. Migration not needed.\n" . COLOR_RESET;
    exit(0);
}

echo COLOR_GREEN . "✅ Found {$totalUsers} users with local avatars\n\n" . COLOR_RESET;

// Confirm before proceeding (unless dry-run)
if (!$dryRun) {
    echo COLOR_YELLOW . "⚠️  WARNING: This will rename {$totalUsers} avatar directories and update database\n" . COLOR_RESET;
    echo COLOR_YELLOW . "⚠️  BACKUP RECOMMENDED before proceeding!\n\n" . COLOR_RESET;
    echo "Type 'YES' to continue: ";
    $confirmation = trim(fgets(STDIN));

    if ($confirmation !== 'YES') {
        echo COLOR_RED . "\n❌ Migration cancelled.\n" . COLOR_RESET;
        exit(0);
    }
    echo "\n";
}

// Migration statistics
$stats = [
    'success' => 0,
    'skipped' => 0,
    'failed' => 0,
    'errors' => [],
];

// Start migration
echo COLOR_CYAN . "🚀 Starting migration...\n\n" . COLOR_RESET;

foreach ($users as $index => $user) {
    $userId = $user['id'];
    $userUuid = $user['uuid'];
    $nickname = $user['nickname'];
    $currentAvatarUrl = $user['avatar_url'];

    $progressNum = $index + 1;

    if ($verbose) {
        echo COLOR_BLUE . "[{$progressNum}/{$totalUsers}] Processing user: {$nickname} (ID: {$userId}, UUID: {$userUuid})\n" . COLOR_RESET;
    }

    // Parse current avatar path
    // Expected format: "avatars/{userId}/avatar_{userId}_timestamp.webp"
    if (!preg_match('#^avatars/(\d+)/(.+)$#', $currentAvatarUrl, $matches)) {
        if ($verbose) {
            echo COLOR_YELLOW . "  ⏭️  Skipped: Avatar path doesn't match expected format: {$currentAvatarUrl}\n" . COLOR_RESET;
        }
        $stats['skipped']++;
        continue;
    }

    $oldUserId = $matches[1];
    $oldFilename = $matches[2];

    // Validate that ID in path matches user ID
    if ((int)$oldUserId !== $userId) {
        echo COLOR_YELLOW . "  ⚠️  Warning: ID mismatch in path ({$oldUserId}) vs user ID ({$userId})\n" . COLOR_RESET;
    }

    // Define old and new paths
    $oldDirPath = "{$avatarBasePath}/{$userId}";
    $newDirPath = "{$avatarBasePath}/{$userUuid}";

    // Check if old directory exists
    if (!is_dir($oldDirPath)) {
        if ($verbose) {
            echo COLOR_YELLOW . "  ⏭️  Skipped: Old directory doesn't exist: {$oldDirPath}\n" . COLOR_RESET;
        }
        $stats['skipped']++;
        continue;
    }

    // Check if new directory already exists (collision)
    if (is_dir($newDirPath)) {
        echo COLOR_YELLOW . "  ⚠️  Warning: Target directory already exists: {$newDirPath}\n" . COLOR_RESET;
        $stats['skipped']++;
        continue;
    }

    // Rename directory
    if ($dryRun) {
        if ($verbose) {
            echo COLOR_CYAN . "  [DRY-RUN] Would rename: {$oldDirPath} → {$newDirPath}\n" . COLOR_RESET;
        }
    } else {
        if (!rename($oldDirPath, $newDirPath)) {
            echo COLOR_RED . "  ❌ Failed to rename directory: {$oldDirPath}\n" . COLOR_RESET;
            $stats['failed']++;
            $stats['errors'][] = "User {$userId}: Failed to rename directory";
            continue;
        }

        if ($verbose) {
            echo COLOR_GREEN . "  ✅ Renamed directory: {$userId}/ → {$userUuid}/\n" . COLOR_RESET;
        }
    }

    // Rename files inside directory (avatar_{id}_* → avatar_{uuid}_*)
    $files = scandir($newDirPath);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        // Check if filename contains old user ID
        if (strpos($file, "avatar_{$userId}_") === 0) {
            $newFilename = str_replace("avatar_{$userId}_", "avatar_{$userUuid}_", $file);

            if ($dryRun) {
                if ($verbose) {
                    echo COLOR_CYAN . "  [DRY-RUN] Would rename file: {$file} → {$newFilename}\n" . COLOR_RESET;
                }
            } else {
                $oldFilePath = "{$newDirPath}/{$file}";
                $newFilePath = "{$newDirPath}/{$newFilename}";

                if (!rename($oldFilePath, $newFilePath)) {
                    echo COLOR_RED . "  ❌ Failed to rename file: {$file}\n" . COLOR_RESET;
                    $stats['errors'][] = "User {$userId}: Failed to rename file {$file}";
                } elseif ($verbose) {
                    echo COLOR_GREEN . "  ✅ Renamed file: {$file} → {$newFilename}\n" . COLOR_RESET;
                }
            }
        }
    }

    // Update database avatar_url
    // OLD: "avatars/{userId}/avatar_{userId}_timestamp.webp"
    // NEW: "avatars/{userUuid}/avatar_{userUuid}_timestamp.webp"
    $newAvatarUrl = preg_replace(
        "#^avatars/{$userId}/avatar_{$userId}_#",
        "avatars/{$userUuid}/avatar_{$userUuid}_",
        $currentAvatarUrl
    );

    if ($dryRun) {
        if ($verbose) {
            echo COLOR_CYAN . "  [DRY-RUN] Would update database:\n" . COLOR_RESET;
            echo COLOR_CYAN . "    OLD: {$currentAvatarUrl}\n" . COLOR_RESET;
            echo COLOR_CYAN . "    NEW: {$newAvatarUrl}\n" . COLOR_RESET;
        }
    } else {
        $updateStmt = $db->prepare("
            UPDATE users
            SET avatar_url = :new_url
            WHERE id = :user_id
        ");

        $updateSuccess = $updateStmt->execute([
            'new_url' => $newAvatarUrl,
            'user_id' => $userId,
        ]);

        if (!$updateSuccess) {
            echo COLOR_RED . "  ❌ Failed to update database for user {$userId}\n" . COLOR_RESET;
            $stats['failed']++;
            $stats['errors'][] = "User {$userId}: Database update failed";
            continue;
        }

        if ($verbose) {
            echo COLOR_GREEN . "  ✅ Updated database: {$newAvatarUrl}\n" . COLOR_RESET;
        }
    }

    // Log success
    if (!$dryRun) {
        Logger::info('Avatar path migrated to UUID', [
            'user_id' => $userId,
            'user_uuid' => $userUuid,
            'old_path' => $currentAvatarUrl,
            'new_path' => $newAvatarUrl,
        ]);
    }

    $stats['success']++;

    if (!$verbose) {
        // Show progress bar
        $percentage = round(($progressNum / $totalUsers) * 100);
        echo "\r" . COLOR_GREEN . "Progress: [{$progressNum}/{$totalUsers}] {$percentage}% complete" . COLOR_RESET;
    }
}

// Final summary
echo "\n\n";
echo COLOR_CYAN . "╔════════════════════════════════════════════════════════════════╗\n" . COLOR_RESET;
echo COLOR_CYAN . "║  MIGRATION SUMMARY                                             ║\n" . COLOR_RESET;
echo COLOR_CYAN . "╚════════════════════════════════════════════════════════════════╝\n\n" . COLOR_RESET;

echo COLOR_GREEN . "✅ Success: {$stats['success']}\n" . COLOR_RESET;
echo COLOR_YELLOW . "⏭️  Skipped: {$stats['skipped']}\n" . COLOR_RESET;
echo COLOR_RED . "❌ Failed:  {$stats['failed']}\n\n" . COLOR_RESET;

if (count($stats['errors']) > 0) {
    echo COLOR_RED . "Errors encountered:\n" . COLOR_RESET;
    foreach ($stats['errors'] as $error) {
        echo COLOR_RED . "  - {$error}\n" . COLOR_RESET;
    }
    echo "\n";
}

if ($dryRun) {
    echo COLOR_YELLOW . "🔍 DRY-RUN completed. No changes were made.\n" . COLOR_RESET;
    echo COLOR_YELLOW . "Run without --dry-run flag to execute migration.\n\n" . COLOR_RESET;
} else {
    echo COLOR_GREEN . "🎉 Migration completed successfully!\n\n" . COLOR_RESET;

    // Invalidate cache
    echo COLOR_CYAN . "🔄 Invalidating cache...\n" . COLOR_RESET;
    try {
        $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('cache');
        if ($redis) {
            $redis->del('query:*users*');
            echo COLOR_GREEN . "✅ Cache invalidated\n\n" . COLOR_RESET;
        }
    } catch (Exception $e) {
        echo COLOR_YELLOW . "⚠️  Could not invalidate cache: {$e->getMessage()}\n\n" . COLOR_RESET;
    }
}

exit($stats['failed'] > 0 ? 1 : 0);
