#!/usr/bin/env php
<?php

/**
 * Comment Count Fixer - Enterprise Galaxy Maintenance Script
 *
 * PURPOSE:
 * Recalculates and fixes denormalized counters that may have become inconsistent:
 * - audio_comments.reply_count (replies per root comment)
 * - audio_posts.comment_count (total comments per post)
 *
 * WHEN TO RUN:
 * - After any bug that may have caused double-counting
 * - As part of weekly maintenance routine
 * - When comment counts appear wrong in UI
 *
 * USAGE:
 *   docker exec need2talk_php php /var/www/html/scripts/fix-comment-counts.php
 *   docker exec need2talk_php php /var/www/html/scripts/fix-comment-counts.php --dry-run
 *
 * @package Need2Talk
 * @author Claude Code AI Assistant
 * @since 2025-11-29
 */

declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Parse arguments
$dryRun = in_array('--dry-run', $argv);

echo "═══════════════════════════════════════════════════════════════\n";
echo " COMMENT COUNT FIXER - Enterprise Galaxy Maintenance\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo $dryRun ? " MODE: DRY RUN (no changes will be made)\n" : " MODE: LIVE (changes will be committed)\n";
echo "───────────────────────────────────────────────────────────────\n\n";

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env manually without full bootstrap (CLI doesn't need sessions)
$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Init database connection directly
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $_ENV['DB_HOST'] ?? 'postgres',
    $_ENV['DB_PORT'] ?? '5432',
    $_ENV['DB_DATABASE'] ?? 'need2talk'
);

try {
    $pdo = new PDO($dsn, $_ENV['DB_USERNAME'] ?? 'need2talk', $_ENV['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "[OK] Database connected\n\n";

    // ─────────────────────────────────────────────────────────────────
    // STEP 1: Find reply_count inconsistencies
    // ─────────────────────────────────────────────────────────────────
    echo "[1/4] Checking reply_count on root comments...\n";

    $stmt = $pdo->query("
        SELECT
            c.id,
            c.reply_count as stored,
            COALESCE((
                SELECT COUNT(*)
                FROM audio_comments
                WHERE parent_comment_id = c.id AND status = 'active'
            ), 0) as actual,
            LEFT(c.comment_text, 40) as comment_preview
        FROM audio_comments c
        WHERE c.parent_comment_id IS NULL
          AND c.reply_count != COALESCE((
              SELECT COUNT(*)
              FROM audio_comments
              WHERE parent_comment_id = c.id AND status = 'active'
          ), 0)
        ORDER BY c.id
    ");
    $inconsistentReplies = $stmt->fetchAll();

    if (empty($inconsistentReplies)) {
        echo "      ✅ All reply_count values are correct!\n\n";
    } else {
        echo "      ⚠️  Found " . count($inconsistentReplies) . " inconsistent reply_count values:\n";
        foreach ($inconsistentReplies as $row) {
            echo "         Comment #{$row['id']}: stored={$row['stored']}, actual={$row['actual']}\n";
            echo "           \"{$row['comment_preview']}...\"\n";
        }
        echo "\n";
    }

    // ─────────────────────────────────────────────────────────────────
    // STEP 2: Find comment_count inconsistencies
    // ─────────────────────────────────────────────────────────────────
    echo "[2/4] Checking comment_count on audio_posts...\n";

    $stmt = $pdo->query("
        SELECT
            p.id,
            p.comment_count as stored,
            COALESCE((
                SELECT COUNT(*)
                FROM audio_comments
                WHERE audio_post_id = p.id AND status = 'active'
            ), 0) as actual
        FROM audio_posts p
        WHERE p.comment_count != COALESCE((
            SELECT COUNT(*)
            FROM audio_comments
            WHERE audio_post_id = p.id AND status = 'active'
        ), 0)
        ORDER BY p.id
    ");
    $inconsistentPosts = $stmt->fetchAll();

    if (empty($inconsistentPosts)) {
        echo "      ✅ All comment_count values are correct!\n\n";
    } else {
        echo "      ⚠️  Found " . count($inconsistentPosts) . " inconsistent comment_count values:\n";
        foreach ($inconsistentPosts as $row) {
            echo "         Post #{$row['id']}: stored={$row['stored']}, actual={$row['actual']}\n";
        }
        echo "\n";
    }

    // ─────────────────────────────────────────────────────────────────
    // STEP 3: Fix reply_count
    // ─────────────────────────────────────────────────────────────────
    if (!empty($inconsistentReplies)) {
        echo "[3/4] Fixing reply_count values...\n";

        if ($dryRun) {
            echo "      [DRY RUN] Would update " . count($inconsistentReplies) . " comments\n\n";
        } else {
            $result = $pdo->exec("
                UPDATE audio_comments c
                SET reply_count = COALESCE(
                    (SELECT COUNT(*) FROM audio_comments ac
                     WHERE ac.parent_comment_id = c.id AND ac.status = 'active'),
                    0
                )
                WHERE parent_comment_id IS NULL
            ");

            echo "      ✅ Updated $result root comments\n\n";
        }
    } else {
        echo "[3/4] No reply_count fixes needed\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    // STEP 4: Fix comment_count
    // ─────────────────────────────────────────────────────────────────
    if (!empty($inconsistentPosts)) {
        echo "[4/4] Fixing comment_count values...\n";

        if ($dryRun) {
            echo "      [DRY RUN] Would update " . count($inconsistentPosts) . " posts\n\n";
        } else {
            $result = $pdo->exec("
                UPDATE audio_posts p
                SET comment_count = COALESCE(
                    (SELECT COUNT(*) FROM audio_comments ac
                     WHERE ac.audio_post_id = p.id AND ac.status = 'active'),
                    0
                )
            ");

            echo "      ✅ Updated $result audio posts\n\n";
        }
    } else {
        echo "[4/4] No comment_count fixes needed\n\n";
    }

    // ─────────────────────────────────────────────────────────────────
    // Summary
    // ─────────────────────────────────────────────────────────────────
    echo "═══════════════════════════════════════════════════════════════\n";

    if (empty($inconsistentReplies) && empty($inconsistentPosts)) {
        echo " RESULT: All counters are already correct! No action needed.\n";
    } elseif ($dryRun) {
        echo " RESULT: DRY RUN complete. Run without --dry-run to apply fixes.\n";
    } else {
        echo " RESULT: Counters have been corrected!\n";
        echo "\n";
        echo " TIP: You may want to flush Redis cache to ensure the UI shows\n";
        echo "      the corrected values immediately:\n";
        echo "      docker exec need2talk_redis_master redis-cli -a <password> FLUSHALL\n";
    }

    echo "═══════════════════════════════════════════════════════════════\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
