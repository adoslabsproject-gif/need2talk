<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\EmotionalReactionService;
use Need2Talk\Services\EnterpriseRedisRateLimitManager;
use Need2Talk\Services\Logger;
use Need2Talk\Services\ReactionStatsService;
use Need2Talk\Services\ServerDebounceService;

/**
 * AudioReactionController - ENTERPRISE GALAXY
 *
 * Manages emotional reactions to audio posts (10 emotions: 5 positive + 5 negative)
 *
 * Performance Targets:
 * - Add/Remove reaction: <30ms
 * - Get reaction stats: <100ms (with 10M+ reactions)
 * - Cache hit ratio: >99%
 * - Concurrent users: 1M+
 *
 * Endpoints:
 * - POST   /api/audio/reaction              - Add or change reaction
 * - DELETE /api/audio/reaction/{post_id}    - Remove reaction
 * - GET    /api/audio/reactions/{post_id}   - Get reaction stats for post
 * - GET    /api/user/evoked-emotions/{user_id} - Get emotions user evokes in others
 *
 * @package Need2Talk\Controllers\Api
 */
class AudioReactionController extends BaseController
{
    private EmotionalReactionService $reactionService;
    private ReactionStatsService $statsService;
    private EnterpriseRedisRateLimitManager $rateLimiter;

    public function __construct()
    {
        parent::__construct();

        $this->reactionService = new EmotionalReactionService();
        $this->statsService = new ReactionStatsService();
        $this->rateLimiter = new EnterpriseRedisRateLimitManager();
    }

    /**
     * Add or Change Reaction
     *
     * POST /api/audio/reaction
     *
     * Request body:
     * {
     *   "audio_post_id": 123,
     *   "emotion_id": 2  // 1-10
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "message": "Reazione aggiunta",
     *   "data": {
     *     "reaction_id": 456,
     *     "audio_post_id": 123,
     *     "emotion_id": 2,
     *     "emotion_name": "Entusiasmo",
     *     "emotion_icon": "🎉",
     *     "is_new": true,
     *     "previous_emotion_id": null
     *   },
     *   "stats": {
     *     "total_reactions": 42,
     *     "emotion_distribution": [...]
     *   }
     * }
     */
    public function addReaction(): void
    {
        try {
            // 1. Authentication check (ENTERPRISE UUID)
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);

                return;
            }

            $userId = $this->getUserId();          // Still needed for service calls + rate limiter
            $userUuid = $this->getUserUuid();      // ENTERPRISE UUID: For logging

            // 2. Validate input (ENTERPRISE FIX: Use BaseController helper instead of Request object)
            $input = $this->getJsonInput();
            $audioPostId = $input['audio_post_id'] ?? null;
            $emotionId = $input['emotion_id'] ?? null;

            if (!$audioPostId || !$emotionId) {
                $this->json([
                    'success' => false,
                    'error' => 'Parametri mancanti: audio_post_id e emotion_id richiesti',
                ], 400);

                return;
            }

            // 3. Validate emotion_id (1-10)
            if (!in_array((int)$emotionId, range(1, 10))) {
                $this->json([
                    'success' => false,
                    'error' => 'emotion_id non valido (deve essere 1-10)',
                ], 400);

                return;
            }

            // 4. ENTERPRISE GALAXY V10.2 (2025-12-10): Server-side debounce
            // Replaces client-only protection with server-side enforcement
            // Prevents abuse from malfunctioning clients or API abuse
            // Window: 1 second per user per post (see ServerDebounceService::DEBOUNCE_CONFIG)
            $debounce = ServerDebounceService::getInstance();
            if (!$debounce->isAllowed('reaction_add', $userId, $audioPostId)) {
                $retryAfter = $debounce->getRetryAfter('reaction_add', $userId, $audioPostId);
                $this->json([
                    'success' => false,
                    'error' => 'Troppo veloce, attendi un momento',
                    'retry_after_ms' => $retryAfter ?? 1000,
                ], 429);

                return;
            }

            // 5. Add/update reaction (business logic)
            $result = $this->reactionService->addOrUpdateReaction(
                (int)$audioPostId,
                (int)$userId,
                (int)$emotionId
            );

            if (!$result['success']) {
                $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Errore durante l\'aggiunta della reazione',
                ], $result['http_code'] ?? 500);

                return;
            }

            // 6. ENTERPRISE V4: NO stats in response!
            // Write-behind buffer means DB is stale. Frontend uses optimistic updates.
            // Stats returned here would be from stale DB data, corrupting frontend state.
            // Frontend's optimistic update is the source of truth until page reload.

            // 7. Log for analytics (ENTERPRISE UUID)
            Logger::info('Reaction added', [
                'user_uuid' => $userUuid,
                'user_id' => $userId,
                'audio_post_id' => $audioPostId,
                'emotion_id' => $emotionId,
                'is_new' => $result['data']['is_new'],
                'previous_emotion_id' => $result['data']['previous_emotion_id'],
            ]);

            // 8. Return success (NO stats - frontend optimistic update is truth)
            $this->json([
                'success' => true,
                'message' => $result['data']['is_new'] ? 'Reazione aggiunta' : 'Reazione aggiornata',
                'data' => $result['data'],
            ]);

        } catch (\Exception $e) {
            Logger::error('AudioReactionController::addReaction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno del server',
            ], 500);
        }
    }

    /**
     * Remove Reaction
     *
     * DELETE /api/audio/reaction/{audio_post_id}
     *
     * Response:
     * {
     *   "success": true,
     *   "message": "Reazione rimossa",
     *   "stats": {...}
     * }
     */
    public function removeReaction(int $audioPostId): void
    {
        try {
            // 1. Authentication check (ENTERPRISE UUID)
            if (!$this->isAuthenticated()) {
                $this->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                ], 401);

                return;
            }

            $userId = $this->getUserId();          // Still needed for service calls
            $userUuid = $this->getUserUuid();      // ENTERPRISE UUID: For logging

            // 2. ENTERPRISE GALAXY V10.2: Server-side debounce
            $debounce = ServerDebounceService::getInstance();
            if (!$debounce->isAllowed('reaction_remove', $userId, $audioPostId)) {
                $retryAfter = $debounce->getRetryAfter('reaction_remove', $userId, $audioPostId);
                $this->json([
                    'success' => false,
                    'error' => 'Troppo veloce, attendi un momento',
                    'retry_after_ms' => $retryAfter ?? 1000,
                ], 429);

                return;
            }

            // 3. Remove reaction (business logic)
            $result = $this->reactionService->removeReaction(
                (int)$audioPostId,
                (int)$userId
            );

            if (!$result['success']) {
                $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Errore durante la rimozione della reazione',
                ], $result['http_code'] ?? 500);

                return;
            }

            // 4. ENTERPRISE V4: NO stats in response!
            // Write-behind buffer means DB is stale. Frontend uses optimistic updates.

            // 5. Log for analytics (ENTERPRISE UUID)
            Logger::info('Reaction removed', [
                'user_uuid' => $userUuid,
                'user_id' => $userId,
                'audio_post_id' => $audioPostId,
            ]);

            // 6. Return success (NO stats - frontend optimistic update is truth)
            $this->json([
                'success' => true,
                'message' => 'Reazione rimossa',
            ]);

        } catch (\Exception $e) {
            Logger::error('AudioReactionController::removeReaction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore interno del server',
            ], 500);
        }
    }

    /**
     * Get Reaction Stats for Single Post
     *
     * GET /api/audio/reactions/{audio_post_id}
     *
     * Response:
     * {
     *   "success": true,
     *   "data": {
     *     "total_reactions": 42,
     *     "user_reaction": {
     *       "emotion_id": 2,
     *       "emotion_name": "Entusiasmo",
     *       "emotion_icon": "🎉"
     *     },
     *     "emotion_distribution": [
     *       {"emotion_id": 1, "name_it": "Gioia", "icon": "😊", "count": 12, "percentage": 28.6},
     *       ...
     *     ]
     *   }
     * }
     */
    public function getPostReactionStats(int $audioPostId): void
    {
        try {
            // 1. Get user ID (optional - affects user_reaction field) - ENTERPRISE UUID
            $userId = $this->isAuthenticated() ? $this->getUserId() : null;

            // 2. Get stats (from cache if possible)
            $stats = $this->statsService->getPostReactionStats(
                (int)$audioPostId,
                $userId ? (int)$userId : null
            );

            // 3. Return success
            $this->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Logger::error('AudioReactionController::getPostReactionStats failed', [
                'error' => $e->getMessage(),
                'audio_post_id' => $audioPostId,
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore durante il recupero delle statistiche',
            ], 500);
        }
    }

    /**
     * Get User's Evoked Emotions (Profile Dashboard)
     *
     * GET /api/user/evoked-emotions/{user_id}?days=30
     *
     * Returns what emotions this user's posts evoke in others
     *
     * Response:
     * {
     *   "success": true,
     *   "data": {
     *     "user_id": 123,
     *     "period_days": 30,
     *     "total_reactions_received": 127,
     *     "total_posts_with_reactions": 18,
     *     "emotion_distribution": [
     *       {"emotion_id": 1, "name_it": "Gioia", "icon": "😊", "color": "#FFD700", "count": 45, "percentage": 35.4},
     *       ...
     *     ],
     *     "top_emotion": {
     *       "emotion_id": 1,
     *       "name_it": "Gioia",
     *       "icon": "😊",
     *       "count": 45
     *     },
     *     "sentiment_breakdown": {
     *       "positive": 105,  // IDs 1-5
     *       "negative": 22    // IDs 6-10
     *     }
     *   }
     * }
     */
    public function getUserEvokedEmotions(int $targetUserId): void
    {
        try {
            // 1. Get period (default 30 days) - ENTERPRISE FIX: Use $_GET for query params
            $days = $_GET['days'] ?? 30;
            $days = max(1, min(365, (int)$days)); // Limit 1-365 days

            // 2. Get evoked emotions stats (from cache if possible)
            $stats = $this->statsService->getUserEvokedEmotions(
                (int)$targetUserId,
                (int)$days
            );

            // 3. Return success
            $this->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Logger::error('AudioReactionController::getUserEvokedEmotions failed', [
                'error' => $e->getMessage(),
                'user_id' => $targetUserId,
            ]);

            $this->json([
                'success' => false,
                'error' => 'Errore durante il recupero delle emozioni evocate',
            ], 500);
        }
    }
}
