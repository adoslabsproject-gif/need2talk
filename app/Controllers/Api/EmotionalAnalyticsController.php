<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\EmotionalAnalyticsService;
use Need2Talk\Services\Logger;

/**
 * EmotionalAnalyticsController - ENTERPRISE GALAXY V11.6
 *
 * API endpoints for reaction-based emotional analytics.
 * This is DIFFERENT from EmotionalHealthController which uses post emotions (self-reported).
 *
 * This controller uses REACTIONS data:
 * - Evoked emotions: What emotions others feel when listening to user's posts
 * - Expressed emotions: What emotions user gives to others' posts
 *
 * CACHE: 5-minute TTL with "Last updated: XX:XX" message for transparency
 *
 * @package Need2Talk\Controllers\Api
 */
class EmotionalAnalyticsController extends BaseController
{
    private EmotionalAnalyticsService $analyticsService;

    public function __construct()
    {
        parent::__construct();
        $this->analyticsService = new EmotionalAnalyticsService();
    }

    /**
     * Get emotional analytics for current user
     *
     * GET /api/profile/emotional-analytics
     * GET /api/profile/emotional-analytics?refresh=1 (force refresh)
     *
     * Response: Complete analytics with 5-min cache
     */
    public function getAnalytics(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = (int) $user['id'];

            $forceRefresh = (bool) (get_input('refresh') ?? false);

            $analytics = $this->analyticsService->getAnalytics($userId, $forceRefresh);

            $this->json([
                'success' => true,
                'data' => $analytics,
                'cache_info' => [
                    'cached_at' => $analytics['cached_at_formatted'] ?? date('H:i'),
                    'ttl_seconds' => $analytics['cache_ttl_seconds'] ?? 300,
                    'message' => 'I dati vengono aggiornati ogni 5 minuti',
                ],
            ]);

        } catch (\Exception $e) {
            Logger::error('EmotionalAnalyticsController: Failed to get analytics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore nel caricamento delle analytics emozionali',
            ], 500);
        }
    }

    /**
     * Get emotional analytics for specific user (public profile view)
     *
     * GET /api/profile/emotional-analytics/{userId}
     *
     * Note: Returns limited data for privacy (no detailed insights for others)
     */
    public function getAnalyticsForUser(int $userId): void
    {
        try {
            $viewer = $this->requireAuth();
            $viewerId = (int) $viewer['id'];

            // Check if viewing own profile or another user
            $isOwnProfile = ($viewerId === $userId);

            $analytics = $this->analyticsService->getAnalytics($userId);

            // For other users, return limited public data only
            if (!$isOwnProfile) {
                $analytics = $this->filterForPublicView($analytics);
            }

            $this->json([
                'success' => true,
                'data' => $analytics,
                'is_own_profile' => $isOwnProfile,
                'cache_info' => [
                    'cached_at' => $analytics['cached_at_formatted'] ?? date('H:i'),
                    'ttl_seconds' => $analytics['cache_ttl_seconds'] ?? 300,
                    'message' => 'I dati vengono aggiornati ogni 5 minuti',
                ],
            ]);

        } catch (\Exception $e) {
            Logger::error('EmotionalAnalyticsController: Failed to get analytics for user', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore nel caricamento delle analytics emozionali',
            ], 500);
        }
    }

    /**
     * Filter analytics for public view (privacy)
     *
     * Other users should not see detailed insights
     *
     * @param array $analytics Full analytics
     * @return array Filtered public analytics
     */
    private function filterForPublicView(array $analytics): array
    {
        // Public data only (what's visible on profile cards)
        return [
            'user_id' => $analytics['user_id'],
            'cached_at' => $analytics['cached_at'],
            'cached_at_formatted' => $analytics['cached_at_formatted'],
            'cache_ttl_seconds' => $analytics['cache_ttl_seconds'],

            // Only evoked emotions (public - what they evoke in others)
            'evoked_emotions' => [
                'total' => $analytics['evoked_emotions']['total'] ?? 0,
                'top_emotion' => $analytics['evoked_emotions']['top_emotion'] ?? null,
                'positive_percentage' => $analytics['evoked_emotions']['positive_percentage'] ?? 50,
                'negative_percentage' => $analytics['evoked_emotions']['negative_percentage'] ?? 50,
                // Distribution hidden for privacy
            ],

            // Public balance meter
            'balance_meter' => $analytics['balance_meter'] ?? null,

            // No expressed emotions (private)
            // No health score details (private)
            // No insights (private)
            // No mood timeline (private)
            // No growth tracking (private)
        ];
    }
}
