<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;

/**
 * Emotions API Controller - Enterprise Galaxy
 *
 * Simple endpoint to fetch the 10 Plutchik emotions
 * Used by EmotionalJournal.js for emotion selector
 *
 * ENDPOINTS:
 * - GET /api/emotions/list - Get all emotions
 *
 * CACHE STRATEGY:
 * - Very long cache (2h) - emotions table is static
 * - Multi-level cache (L1/L2/L3)
 *
 * PERFORMANCE:
 * - <2ms query (10 rows, indexed)
 * - <1ms from cache (after first request)
 *
 * @package Need2Talk\Controllers\Api
 * @author Claude Code (AI-Orchestrated Development)
 */
class EmotionsController extends BaseController
{
    /**
     * Get all emotions
     *
     * GET /api/emotions/list
     *
     * Response:
     * {
     *   "success": true,
     *   "emotions": [
     *     {
     *       "id": 1,
     *       "name_it": "Gioia",
     *       "name_en": "Joy",
     *       "icon_emoji": "😊",
     *       "color_hex": "#FFD700",
     *       "category": "positive"
     *     },
     *     ...
     *   ]
     * }
     */
    public function list(): void
    {
        try {
            $db = db();

            // ENTERPRISE: Cache very long (emotions table is static)
            // Uses idx_is_active (is_active, sort_order)
            $emotions = $db->findMany(
                "SELECT
                    id,
                    name_it,
                    name_en,
                    icon_emoji,
                    color_hex,
                    category
                 FROM emotions
                 WHERE is_active = TRUE
                 ORDER BY sort_order ASC",
                [],
                [
                    'cache' => true,
                    'cache_ttl' => 'very_long', // 2h cache
                ]
            );

            $this->json([
                'success' => true,
                'emotions' => $emotions,
            ], 200);

        } catch (\Exception $e) {
            \Need2Talk\Services\Logger::error('Emotions list fetch failed', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'database_error',
                'message' => 'Errore nel caricamento delle emozioni',
            ], 500);
        }
    }
}
