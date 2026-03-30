<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;

/**
 * Emotion API Controller
 * Enterprise Galaxy - Emotion Data Endpoint
 *
 * Provides emotion data for EmotionSelector component
 * Cached responses for performance
 *
 * @package Need2Talk\Controllers\Api
 */
class EmotionController extends BaseController
{
    /**
     * Get all emotions
     *
     * GET /api/emotions
     *
     * Returns list of all active emotions with metadata
     * Response is cached for 1 hour (emotions rarely change)
     *
     * @return void JSON response
     */
    public function index(): void
    {
        try {
            $db = db();

            // Query emotions (cached for 1 hour)
            $emotions = $db->query(
                "SELECT
                    id,
                    name_it,
                    name_en,
                    category,
                    icon_emoji,
                    color_hex,
                    description,
                    ai_keywords
                FROM emotions
                WHERE is_active = TRUE
                ORDER BY
                    CASE
                        WHEN category = 'positive' THEN 1
                        WHEN category = 'negative' THEN 2
                        ELSE 3
                    END,
                    name_it ASC",
                [],
                [
                    'cache' => true,
                    'cache_ttl' => 'very_long', // 2 hours cache
                ]
            );

            // Transform ai_keywords JSON string to array
            foreach ($emotions as &$emotion) {
                if (!empty($emotion['ai_keywords'])) {
                    $emotion['ai_keywords'] = json_decode($emotion['ai_keywords'], true) ?: [];
                } else {
                    $emotion['ai_keywords'] = [];
                }

                // Keep description field (already in Italian)
            }

            // Success response
            $this->json([
                'success' => true,
                'emotions' => $emotions,
                'total' => count($emotions),
                'cached' => true, // Client knows this is cacheable
                'cache_ttl' => 300, // 5 minutes client-side cache
            ], 200, [
                'Cache-Control' => 'public, max-age=300', // 5 minutes browser cache
                'Expires' => gmdate('D, d M Y H:i:s', time() + 300) . ' GMT',
            ]);

        } catch (\Exception $e) {
            error_log('EmotionController: Failed to fetch emotions - ' . $e->getMessage());

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore nel caricamento delle emozioni',
            ], 500);
        }
    }

    /**
     * Get single emotion by ID
     *
     * GET /api/emotions/{id}
     *
     * @param int $id Emotion ID
     * @return void JSON response
     */
    public function show(int $id): void
    {
        try {
            $db = db();

            $emotion = $db->findOne(
                "SELECT
                    id,
                    name_it,
                    name_en,
                    category,
                    icon_emoji,
                    color_hex,
                    description,
                    ai_keywords
                FROM emotions
                WHERE id = ? AND is_active = TRUE",
                [$id],
                ['cache' => true, 'cache_ttl' => 'very_long']
            );

            if (!$emotion) {
                $this->json([
                    'success' => false,
                    'error' => 'not_found',
                    'message' => 'Emozione non trovata',
                ], 404);

                return;
            }

            // Transform ai_keywords
            if (!empty($emotion['ai_keywords'])) {
                $emotion['ai_keywords'] = json_decode($emotion['ai_keywords'], true) ?: [];
            } else {
                $emotion['ai_keywords'] = [];
            }

            $this->json([
                'success' => true,
                'emotion' => $emotion,
            ], 200, [
                'Cache-Control' => 'public, max-age=3600', // 1 hour browser cache
                'Expires' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT',
            ]);

        } catch (\Exception $e) {
            error_log('EmotionController: Failed to fetch emotion ' . $id . ' - ' . $e->getMessage());

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore nel caricamento dell\'emozione',
            ], 500);
        }
    }

    /**
     * Get emotions by category
     *
     * GET /api/emotions/category/{category}
     *
     * @param string $category Category ('positive' or 'negative')
     * @return void JSON response
     */
    public function byCategory(string $category): void
    {
        try {
            // Validate category
            if (!in_array($category, ['positive', 'negative'], true)) {
                $this->json([
                    'success' => false,
                    'error' => 'invalid_category',
                    'message' => 'Categoria non valida. Usa: positive o negative',
                ], 400);

                return;
            }

            $db = db();

            $emotions = $db->query(
                "SELECT
                    id,
                    name_it,
                    name_en,
                    category,
                    icon_emoji,
                    color_hex,
                    description
                FROM emotions
                WHERE category = :category AND is_active = TRUE
                ORDER BY name_it ASC",
                ['category' => $category],
                [
                    'cache' => true,
                    'cache_ttl' => 'very_long',
                ]
            );

            $this->json([
                'success' => true,
                'category' => $category,
                'emotions' => $emotions,
                'total' => count($emotions),
            ], 200, [
                'Cache-Control' => 'public, max-age=600',
                'Expires' => gmdate('D, d M Y H:i:s', time() + 600) . ' GMT',
            ]);

        } catch (\Exception $e) {
            error_log('EmotionController: Failed to fetch emotions by category ' . $category . ' - ' . $e->getMessage());

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore nel caricamento delle emozioni',
            ], 500);
        }
    }
}
