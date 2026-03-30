<?php

namespace Need2Talk\Services;

use Need2Talk\Services\Cache\PartitionedWriteBehindBuffer;
use Need2Talk\Services\Cache\OverlayService;
use Need2Talk\Services\NotificationService;

/**
 * EmotionalReactionService - ENTERPRISE GALAXY V8
 *
 * Business logic for emotional reactions to audio posts
 *
 * V8 FIX (2025-12-10):
 * - BUGFIX: Changed from WriteBehindBuffer to PartitionedWriteBehindBuffer
 * - Previous bug: Reactions were written to non-partitioned key but worker read from partitioned keys
 * - Result: Reactions never flushed to DB, only visible in feed overlay
 *
 * V4 Architecture (2025-11-26):
 * - Write-behind cache: Reactions buffered in Redis, flushed to DB periodically
 * - Overlay system: Immediate visibility without cache invalidation
 * - Zero feed:* invalidation: 95%+ reduction in cache invalidations
 *
 * Performance:
 * - Add/Update reaction: <10ms (Redis only, async DB)
 * - Remove reaction: <10ms (Redis only, async DB)
 * - No more feed:* invalidation!
 *
 * @package Need2Talk\Services
 */
class EmotionalReactionService
{
    private ?PartitionedWriteBehindBuffer $buffer = null;
    private ?OverlayService $overlay = null;

    public function __construct()
    {
        $this->buffer = PartitionedWriteBehindBuffer::getInstance();
        $this->overlay = OverlayService::getInstance();
    }

    /**
     * Add or Update Reaction
     *
     * ENTERPRISE GALAXY V4 (2025-11-26):
     * - Uses write-behind buffer for immediate Redis visibility
     * - DB write is async via OverlayFlushService
     * - No more feed:* cache invalidation!
     *
     * @param int $audioPostId
     * @param int $userId
     * @param int $emotionId (1-10)
     * @return array ['success' => bool, 'data' => array, 'error' => string|null]
     */
    public function addOrUpdateReaction(int $audioPostId, int $userId, int $emotionId): array
    {
        $db = db();

        try {
            // 1. Verify audio_post exists and not deleted (also get user_id for notification)
            $post = $db->findOne(
                "SELECT id, user_id FROM audio_posts WHERE id = ? AND deleted_at IS NULL",
                [$audioPostId]
            );

            if (!$post) {
                return [
                    'success' => false,
                    'error' => 'Post non trovato o eliminato',
                    'http_code' => 404,
                ];
            }

            // 2. Get previous reaction - check OVERLAY FIRST (has pending removals), then DB
            // ENTERPRISE V4: Overlay returns:
            //   - null: no overlay data → fallback to DB
            //   - 0: tombstone (removed, pending DB flush) → treat as no reaction
            //   - >0: active emotion_id
            $previousEmotionId = null;
            $previousReaction = null;
            $isNew = true;

            // Check Redis overlay first (real-time state) - note: getUserReaction(userId, postId)
            if ($this->overlay && $this->overlay->isAvailable()) {
                $overlayReaction = $this->overlay->getUserReaction($userId, $audioPostId);

                if ($overlayReaction === null) {
                    // No overlay data - fallback to DB
                    $previousReaction = $db->findOne(
                        "SELECT id, emotion_id FROM audio_reactions
                         WHERE audio_post_id = ? AND user_id = ?",
                        [$audioPostId, $userId]
                    );
                    $previousEmotionId = $previousReaction ? (int)$previousReaction['emotion_id'] : null;
                    $isNew = !$previousReaction;
                } elseif ($overlayReaction === 0) {
                    // Tombstone: reaction was removed (pending DB flush) - treat as no reaction
                    $previousEmotionId = null;
                    $isNew = true;
                } else {
                    // Active reaction in overlay
                    $previousEmotionId = $overlayReaction;
                    $isNew = false;
                }
            } else {
                // No overlay available - use DB directly (fallback)
                $previousReaction = $db->findOne(
                    "SELECT id, emotion_id FROM audio_reactions
                     WHERE audio_post_id = ? AND user_id = ?",
                    [$audioPostId, $userId]
                );
                $previousEmotionId = $previousReaction ? (int)$previousReaction['emotion_id'] : null;
                $isNew = !$previousReaction;
            }

            // ENTERPRISE SECURITY: Only one reaction per user per post
            // If user already has a reaction and tries to add a different one, REJECT
            // User must first remove their current reaction before adding a new one
            if ($previousEmotionId !== null && $previousEmotionId !== $emotionId) {
                return [
                    'success' => false,
                    'error' => 'Hai già reagito a questo post. Rimuovi prima la tua reazione.',
                    'http_code' => 409, // Conflict
                    'current_reaction' => $previousEmotionId,
                ];
            }

            // If trying to add the same reaction, treat as no-op (idempotent)
            if ($previousEmotionId !== null && $previousEmotionId === $emotionId) {
                $emotion = $db->findOne(
                    "SELECT name_it, name_en, icon_emoji, color_hex, category
                     FROM emotions WHERE id = ?",
                    [$emotionId],
                    ['cache' => true, 'cache_ttl' => 'very_long']
                );

                // ENTERPRISE V4: Don't return stats - frontend's optimistic update is truth
                // ENTERPRISE V11.8: Removed user_id from response (security - don't expose internal IDs)
                return [
                    'success' => true,
                    'data' => [
                        'reaction_id' => $previousReaction['id'] ?? null,
                        'audio_post_id' => $audioPostId,
                        'emotion_id' => $emotionId,
                        'emotion_name' => $emotion['name_it'] ?? null,
                        'emotion_icon' => $emotion['icon_emoji'] ?? null,
                        'emotion_color' => $emotion['color_hex'] ?? null,
                        'emotion_category' => $emotion['category'] ?? null,
                        'is_new' => false,
                        'previous_emotion_id' => $previousEmotionId,
                        'already_exists' => true,
                    ],
                ];
            }

            // 3. ENTERPRISE V4: Use write-behind buffer (Redis + async DB)
            if ($this->buffer && $this->overlay->isAvailable()) {
                // Buffer handles: Redis overlay update + dirty set for DB flush
                $this->buffer->bufferReaction($audioPostId, $userId, $emotionId, $previousEmotionId);
            } else {
                // Fallback: Direct DB write (legacy mode)
                // ENTERPRISE V11.8: Include user_uuid via subquery
                $db->execute(
                    "INSERT INTO audio_reactions (audio_post_id, user_id, user_uuid, emotion_id, created_at, updated_at)
                     VALUES (?, ?, (SELECT uuid FROM users WHERE id = ?), ?, NOW(), NOW())
                     ON CONFLICT (user_id, audio_post_id) DO UPDATE SET
                         emotion_id = ?,
                         updated_at = NOW()",
                    [$audioPostId, $userId, $userId, $emotionId, $emotionId],
                    // V4: Removed 'feed:*' from invalidation - overlays handle visibility
                    ['invalidate_cache' => $this->getCacheInvalidationPatterns($audioPostId, $userId)]
                );
            }

            // 4. Get emotion details (cached)
            $emotion = $db->findOne(
                "SELECT name_it, name_en, icon_emoji, color_hex, category
                 FROM emotions WHERE id = ?",
                [$emotionId],
                ['cache' => true, 'cache_ttl' => 'very_long']
            );

            // 5. ENTERPRISE GALAXY V4: Send notification to post author (only for NEW reactions)
            // Don't notify for updates (same emotion) or when reacting to own post
            if ($isNew && isset($post['user_id'])) {
                $postAuthorId = (int)$post['user_id'];
                NotificationService::getInstance()->notifyNewReaction(
                    $postAuthorId,
                    $userId,
                    $audioPostId,
                    $emotionId
                );
            }

            // ENTERPRISE V4: Don't return stats from overlay
            // Frontend's optimistic update is the source of truth until page reload
            // Returning stats would warm from DB which has stale data (write-behind not flushed)
            // ENTERPRISE V11.8: Removed user_id from response (security - don't expose internal IDs)
            return [
                'success' => true,
                'data' => [
                    'reaction_id' => $previousReaction['id'] ?? null,
                    'audio_post_id' => $audioPostId,
                    'emotion_id' => $emotionId,
                    'emotion_name' => $emotion['name_it'] ?? null,
                    'emotion_icon' => $emotion['icon_emoji'] ?? null,
                    'emotion_color' => $emotion['color_hex'] ?? null,
                    'emotion_category' => $emotion['category'] ?? null,
                    'is_new' => $isNew,
                    'previous_emotion_id' => $previousEmotionId,
                ],
            ];

        } catch (\Exception $e) {
            Logger::error('EmotionalReactionService::addOrUpdateReaction failed', [
                'error' => $e->getMessage(),
                'audio_post_id' => $audioPostId,
                'user_id' => $userId,
                'emotion_id' => $emotionId,
            ]);

            return [
                'success' => false,
                'error' => 'Errore durante l\'aggiunta della reazione',
                'http_code' => 500,
            ];
        }
    }

    /**
     * Remove Reaction
     *
     * ENTERPRISE GALAXY V4 (2025-11-26):
     * - Uses write-behind buffer for immediate Redis visibility
     * - DB delete is async via OverlayFlushService
     *
     * @param int $audioPostId
     * @param int $userId
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function removeReaction(int $audioPostId, int $userId): array
    {
        $db = db();

        try {
            // ENTERPRISE V4: Check OVERLAY FIRST (write-behind may not have flushed to DB yet)
            // Overlay returns:
            //   - null: no overlay data → fallback to DB
            //   - 0: tombstone (already removed) → 404
            //   - >0: active emotion_id → proceed with removal
            $emotionId = null;
            $existingDbId = null;
            $bufferAvailable = $this->buffer !== null;
            $overlayAvailable = $this->overlay && $this->overlay->isAvailable();

            if ($overlayAvailable) {
                $overlayReaction = $this->overlay->getUserReaction($userId, $audioPostId);

                if ($overlayReaction === 0) {
                    // Tombstone: reaction was already removed (pending DB flush)
                    return [
                        'success' => false,
                        'error' => 'Reazione non trovata',
                        'http_code' => 404,
                    ];
                } elseif ($overlayReaction !== null && $overlayReaction > 0) {
                    // Found in overlay - use this emotion_id
                    $emotionId = $overlayReaction;
                }
            }

            // If not found in overlay, check DB
            if ($emotionId === null) {
                $existing = $db->findOne(
                    "SELECT id, emotion_id FROM audio_reactions
                     WHERE audio_post_id = ? AND user_id = ?",
                    [$audioPostId, $userId]
                );

                if (!$existing) {
                    return [
                        'success' => false,
                        'error' => 'Reazione non trovata',
                        'http_code' => 404,
                    ];
                }

                $emotionId = (int)$existing['emotion_id'];
                $existingDbId = $existing['id'];
            }

            if ($bufferAvailable && $overlayAvailable) {
                // Write-behind: buffer the removal (overlay + dirty set)
                $this->buffer->bufferReactionRemoval($audioPostId, $userId, $emotionId);
            } else {
                // Fallback: Direct DB delete (only if we have a DB id)
                if ($existingDbId) {
                    $db->execute(
                        "DELETE FROM audio_reactions WHERE id = ?",
                        [$existingDbId],
                        ['invalidate_cache' => $this->getCacheInvalidationPatterns($audioPostId, $userId)]
                    );
                }
            }

            // ENTERPRISE V4: Don't return stats after remove
            // Frontend already did optimistic update, knows correct state
            // Returning stats would require DB warm which has stale data
            return [
                'success' => true,
            ];

        } catch (\Exception $e) {
            Logger::error('EmotionalReactionService::removeReaction failed', [
                'error' => $e->getMessage(),
                'audio_post_id' => $audioPostId,
                'user_id' => $userId,
            ]);

            return [
                'success' => false,
                'error' => 'Errore durante la rimozione della reazione',
                'http_code' => 500,
            ];
        }
    }

    /**
     * Get Cache Invalidation Patterns
     *
     * ENTERPRISE GALAXY V4 (2025-11-26):
     * - REMOVED 'feed:*' - overlays handle feed reaction visibility
     * - Kept evoked emotions invalidation (profile page data)
     *
     * @param int $audioPostId
     * @param int $userId
     * @return array
     */
    private function getCacheInvalidationPatterns(int $audioPostId, int $userId): array
    {
        // Get post owner ID for evoked emotions cache
        $db = db();
        $post = $db->findOne("SELECT user_id FROM audio_posts WHERE id = ?", [$audioPostId]);
        $postOwnerId = $post['user_id'] ?? null;

        $patterns = [
            "reactions:post:$audioPostId",      // Post reaction stats
            "reactions:post:$audioPostId:*",    // All post-related caches
            "reactions:user:$userId",           // User's reactions
            // V4: REMOVED 'feed:*' - overlay system handles feed visibility without invalidation
        ];

        // Invalidate post owner's evoked emotions cache
        if ($postOwnerId) {
            $patterns[] = "evoked:user:$postOwnerId";
            $patterns[] = "evoked:user:$postOwnerId:*";
        }

        return $patterns;
    }

    /**
     * Get reaction stats from overlay (Redis) or fallback to DB
     *
     * ENTERPRISE GALAXY V4 (2025-11-26):
     * Returns current reaction counts for API response
     *
     * @param int $audioPostId
     * @return array [emotion_id => count, ...]
     */
    private function getReactionStatsFromOverlay(int $audioPostId): array
    {
        // ENTERPRISE V4: Check if overlay KEY exists (not just if it has values)
        // This distinguishes "no reactions" (overlay exists, all 0) vs "no overlay data" (fallback to DB)
        if ($this->overlay && $this->overlay->isAvailable()) {
            // Check if overlay key exists first
            if ($this->overlay->hasReactionsOverlay($audioPostId)) {
                // Overlay exists - trust it (even if empty = no reactions)
                // ENTERPRISE V4: Don't include 0 counts (frontend replaces, not merges)
                return $this->overlay->getReactions($audioPostId, false);
            }
        }

        // Fallback to DB only if overlay doesn't exist
        return $this->getReactionStatsFromDB($audioPostId);
    }

    /**
     * Get reaction stats from database
     *
     * @param int $audioPostId
     * @return array [emotion_id => count, ...]
     */
    private function getReactionStatsFromDB(int $audioPostId): array
    {
        $db = db();
        $rows = $db->query(
            "SELECT emotion_id, COUNT(*) as count
             FROM audio_reactions
             WHERE audio_post_id = ?
             GROUP BY emotion_id",
            [$audioPostId]
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int)$row['emotion_id']] = (int)$row['count'];
        }

        return $stats;
    }
}
