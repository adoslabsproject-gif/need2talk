<?php
/**
 * ================================================================================
 * ENTERPRISE GALAXY: Permanent Deletion of Soft-Deleted Audio Posts
 * ================================================================================
 *
 * PURPOSE:
 * Permanently delete audio posts that were soft-deleted (deleted_at set) more than
 * 30 days ago. This includes all related data:
 * - Reactions
 * - Comments (and their replies)
 * - Comment likes
 * - Comment edit history
 * - Notifications
 * - Audio files (physical files + DB records)
 * - Listen tracking
 * - View counters
 * - Photos associated with posts
 *
 * IMPORTANT:
 * - Most FK constraints have ON DELETE CASCADE, so deleting the post cascades
 * - Audio files need physical file deletion (storage + CDN)
 * - Photos need physical file deletion
 * - Notifications referencing the post should be cleaned
 *
 * SCHEDULE: Daily at 3:30 AM via cron
 * RUN: php /var/www/need2talk/scripts/crons/cleanup-deleted-audio-posts.php
 *
 * @version 1.0.0
 * @author need2talk.it - AI-Orchestrated Development
 * ================================================================================
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

echo "[" . date('Y-m-d H:i:s') . "] ENTERPRISE GALAXY: Audio Posts Cleanup Starting...\n";

$db = db();
$deletedCount = 0;
$filesDeleted = 0;
$photosDeleted = 0;
$errors = [];

try {
    // Configuration
    $daysOld = 30;
    $batchSize = 100;

    // Find posts soft-deleted more than 30 days ago
    $sql = "SELECT
                ap.id,
                ap.uuid,
                ap.user_id,
                ap.audio_file_id,
                ap.photo_urls,
                af.file_path as audio_path,
                af.cdn_url
            FROM audio_posts ap
            LEFT JOIN audio_files af ON af.id = ap.audio_file_id
            WHERE ap.deleted_at IS NOT NULL
              AND ap.deleted_at < NOW() - INTERVAL '{$daysOld} days'
            LIMIT :batch_size";

    $postsToDelete = $db->findMany($sql, ['batch_size' => $batchSize]);

    if (empty($postsToDelete)) {
        echo "[" . date('Y-m-d H:i:s') . "] No posts to permanently delete.\n";
        exit(0);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Found " . count($postsToDelete) . " posts to permanently delete\n";

    // Storage base path
    $storagePath = '/var/www/need2talk/storage/uploads';

    foreach ($postsToDelete as $post) {
        $postId = (int)$post['id'];
        $postUuid = $post['uuid'];
        $audioFileId = $post['audio_file_id'] ? (int)$post['audio_file_id'] : null;
        $audioPath = $post['audio_path'];
        $photoUrls = $post['photo_urls'];

        echo "[" . date('Y-m-d H:i:s') . "] Processing post ID: {$postId} (UUID: {$postUuid})\n";

        try {
            $db->beginTransaction(30);

            // 1. Clean up notifications referencing this post
            // (target_type = 'post' AND target_id = post_id)
            // OR (target_type = 'comment' AND data->>'post_id' = post_id)
            $db->execute(
                "DELETE FROM notifications
                 WHERE (target_type = 'post' AND target_id = :post_id)
                    OR (target_type = 'comment' AND data->>'post_id' = :post_id_str)",
                ['post_id' => $postId, 'post_id_str' => (string)$postId]
            );

            // 2. Delete comment edit history for comments on this post
            // (CASCADE handles this via FK, but let's be explicit for logging)
            $deletedEditHistory = $db->execute(
                "DELETE FROM comment_edit_history
                 WHERE comment_id IN (
                     SELECT id FROM audio_comments WHERE audio_post_id = :post_id
                 )",
                ['post_id' => $postId]
            );

            // 3. Delete comment likes for comments on this post
            // (CASCADE handles this, but explicit for safety)
            $deletedCommentLikes = $db->execute(
                "DELETE FROM comment_likes
                 WHERE comment_id IN (
                     SELECT id FROM audio_comments WHERE audio_post_id = :post_id
                 )",
                ['post_id' => $postId]
            );

            // 4. Delete comments (replies cascade via parent_comment_id FK)
            // Note: FK CASCADE handles this when post is deleted, but we log it
            $commentCount = $db->findOne(
                "SELECT COUNT(*) as cnt FROM audio_comments WHERE audio_post_id = :post_id",
                ['post_id' => $postId]
            )['cnt'] ?? 0;

            // 5. Delete reactions (FK CASCADE handles this)
            $reactionCount = $db->findOne(
                "SELECT COUNT(*) as cnt FROM audio_reactions WHERE audio_post_id = :post_id",
                ['post_id' => $postId]
            )['cnt'] ?? 0;

            // 6. NOW delete the post - this triggers CASCADE for:
            //    - audio_comments
            //    - audio_reactions
            //    - audio_listen_tracking
            //    - audio_post_listen_counters (renamed from audio_post_view_counters)
            //    - audio_post_listen_tracking_mode (renamed from audio_post_tracking_mode)
            //    - hidden_posts
            $db->execute("DELETE FROM audio_posts WHERE id = :id", ['id' => $postId]);

            // 7. Delete audio file record (if exists and orphaned)
            if ($audioFileId) {
                // Check if any other post uses this audio file
                $otherUsage = $db->findOne(
                    "SELECT COUNT(*) as cnt FROM audio_posts WHERE audio_file_id = :audio_id",
                    ['audio_id' => $audioFileId]
                )['cnt'] ?? 0;

                if ($otherUsage == 0) {
                    $db->execute("DELETE FROM audio_files WHERE id = :id", ['id' => $audioFileId]);

                    // Delete physical audio file
                    if ($audioPath) {
                        $fullPath = $storagePath . '/' . ltrim($audioPath, '/');
                        if (file_exists($fullPath)) {
                            unlink($fullPath);
                            $filesDeleted++;
                            echo "  - Deleted audio file: {$audioPath}\n";
                        }
                    }
                }
            }

            // 8. Delete physical photo files
            if ($photoUrls) {
                $photos = json_decode($photoUrls, true);
                if (is_array($photos)) {
                    foreach ($photos as $photoPath) {
                        // Normalize path
                        if (strpos($photoPath, '/var/www/') === 0) {
                            $photoPath = str_replace('/var/www/html/public/storage/uploads/', '', $photoPath);
                        }
                        if (strpos($photoPath, '/storage/uploads/') === 0) {
                            $photoPath = str_replace('/storage/uploads/', '', $photoPath);
                        }

                        $fullPhotoPath = $storagePath . '/' . ltrim($photoPath, '/');
                        if (file_exists($fullPhotoPath)) {
                            unlink($fullPhotoPath);
                            $photosDeleted++;
                            echo "  - Deleted photo: {$photoPath}\n";
                        }
                    }
                }
            }

            $db->commit();
            $deletedCount++;

            echo "  ✓ Post {$postId} permanently deleted (comments: {$commentCount}, reactions: {$reactionCount})\n";

        } catch (\Exception $e) {
            $db->rollback();
            $errors[] = "Post {$postId}: " . $e->getMessage();
            echo "  ✗ ERROR: " . $e->getMessage() . "\n";
        }
    }

    // Log summary
    Logger::info('Audio posts cleanup completed', [
        'posts_deleted' => $deletedCount,
        'audio_files_deleted' => $filesDeleted,
        'photos_deleted' => $photosDeleted,
        'errors' => count($errors),
    ]);

    echo "\n[" . date('Y-m-d H:i:s') . "] ========================================\n";
    echo "CLEANUP SUMMARY:\n";
    echo "  Posts permanently deleted: {$deletedCount}\n";
    echo "  Audio files deleted: {$filesDeleted}\n";
    echo "  Photos deleted: {$photosDeleted}\n";
    echo "  Errors: " . count($errors) . "\n";

    if (!empty($errors)) {
        echo "\nERRORS:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] DONE\n";

} catch (\Exception $e) {
    Logger::error('Audio posts cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    echo "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
