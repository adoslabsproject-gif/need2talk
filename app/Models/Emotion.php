<?php

namespace Need2Talk\Models;

use Need2Talk\Core\BaseModel;

/**
 * Emotion Model - Enterprise Galaxy (10 Emotions System)
 *
 * Sistema ottimizzato con 10 emozioni core:
 * - 5 Positive: Gioia, Meraviglia, Amore, Gratitudine, Speranza
 * - 5 Negative: Tristezza, Rabbia, Ansia, Paura, Solitudine
 *
 * PERFORMANCE:
 * - Multi-level caching (L1 Redis, L2 Memcached, L3 Redis)
 * - Optimized indexes: idx_active_sort (is_active, sort_order)
 * - Query cost: <0.1ms for getAll() (cached)
 *
 * ARCHITECTURE:
 * - Indexed queries for 100k+ concurrent users
 * - JSON ai_keywords for AI emotion detection
 * - Category-based filtering with index hints
 *
 * @package Need2Talk\Models
 */
class Emotion extends BaseModel
{
    protected string $table = 'emotions';

    /**
     * Get all active emotions ordered by category and sort_order
     *
     * Uses idx_active_sort composite index for optimal performance
     *
     * @param bool $activeOnly Filter only active emotions (default: true)
     * @return array List of emotions with all fields
     */
    public function getAll(bool $activeOnly = true): array
    {
        $whereClause = $activeOnly ? 'WHERE is_active = TRUE' : '';

        // ENTERPRISE: Cache emotions list for 1 hour (changes very rarely)
        // Query uses idx_active_sort index for O(log n) lookup
        return $this->db()->query("
            SELECT * FROM {$this->table}
            {$whereClause}
            ORDER BY category, sort_order ASC
        ", [], [
            'cache' => true,
            'cache_ttl' => 'long', // 1 hour - emotions list is almost static
        ]);
    }

    /**
     * Get emotions by category (positive/negative)
     *
     * Uses idx_active_sort for category filtering
     *
     * @param string $category 'positive' or 'negative'
     * @return array Filtered emotions (5 per category)
     */
    public function getByCategory(string $category): array
    {
        // ENTERPRISE: Validate category to prevent SQL injection
        if (!in_array($category, ['positive', 'negative'], true)) {
            return [];
        }

        return $this->db()->query("
            SELECT * FROM {$this->table}
            WHERE category = ? AND is_active = TRUE
            ORDER BY sort_order ASC
        ", [$category], [
            'cache' => true,
            'cache_ttl' => 'long',
        ]);
    }

    /**
     * Get positive emotions (5 emotions)
     *
     * Returns: Gioia, Meraviglia, Amore, Gratitudine, Speranza
     *
     * @return array 5 positive emotions
     */
    public function getPositiveEmotions(): array
    {
        return $this->getByCategory('positive');
    }

    /**
     * Get negative emotions (5 emotions)
     *
     * Returns: Tristezza, Rabbia, Ansia, Paura, Solitudine
     *
     * @return array 5 negative emotions
     */
    public function getNegativeEmotions(): array
    {
        return $this->getByCategory('negative');
    }

    /**
     * Get emotion by English name
     *
     * Uses UNIQUE index on name_en for O(1) lookup
     *
     * @param string $nameEn English emotion name (e.g., "happiness")
     * @return array|null Emotion data or null if not found
     */
    public function getByNameEn(string $nameEn): ?array
    {
        // ENTERPRISE: Use UNIQUE index on name_en for instant lookup
        return $this->db()->findOne("
            SELECT * FROM {$this->table}
            WHERE name_en = ? AND is_active = TRUE
        ", [$nameEn], [
            'cache' => true,
            'cache_ttl' => 'long',
        ]);
    }

    /**
     * Get popular emotions with usage statistics
     *
     * Aggregates data from audio_files table (expensive query)
     * Cache: 10 minutes (statistics change gradually)
     *
     * @param int $limit Number of emotions to return (default: 10, max: all)
     * @return array Emotions with usage_count, total_likes, total_plays
     */
    public function getPopularEmotions(int $limit = 10): array
    {
        return $this->db()->query("
            SELECT
                e.*,
                COUNT(a.id) as usage_count,
                SUM(a.like_count) as total_likes,
                SUM(a.play_count) as total_plays
            FROM {$this->table} e
            LEFT JOIN audio_files a ON e.id = a.primary_emotion_id
                AND a.privacy_level = 'public'
                AND a.status = 'approved'
                AND a.deleted_at IS NULL
            WHERE e.is_active = TRUE
            GROUP BY e.id
            ORDER BY usage_count DESC, e.sort_order ASC
            LIMIT ?
        ", [$limit], [
            'cache' => true,
            'cache_ttl' => 'medium', // 10 minutes - balance freshness/performance
        ]);
    }

    /**
     * Get complete emotion statistics grouped by category
     *
     * EXPENSIVE QUERY: LEFT JOIN + GROUP BY + aggregations
     * Cache: 10 minutes to reduce database load
     *
     * @return array Emotions grouped by category with statistics
     */
    public function getEmotionStatistics(): array
    {
        // ENTERPRISE: Cache expensive statistics query for 10 minutes
        $results = $this->db()->query("
            SELECT
                e.category,
                e.name_en,
                e.name_it,
                e.icon_emoji,
                e.color_hex,
                COUNT(a.id) as total_usage,
                NULL as avg_confidence,
                SUM(a.like_count) as total_likes,
                SUM(a.play_count) as total_plays
            FROM {$this->table} e
            LEFT JOIN audio_files a ON e.id = a.primary_emotion_id
                AND a.deleted_at IS NULL
            WHERE e.is_active = TRUE
            GROUP BY e.id
            ORDER BY e.category, e.sort_order ASC
        ", [], [
            'cache' => true,
            'cache_ttl' => 'medium', // 10 minutes - stats change gradually
        ]);

        // Group by category for easy consumption
        $grouped = [
            'positive' => [],
            'negative' => [],
        ];

        foreach ($results as $emotion) {
            $category = $emotion['category'] ?? 'neutral';
            if (isset($grouped[$category])) {
                $grouped[$category][] = $emotion;
            }
        }

        return $grouped;
    }

    /**
     * Search emotions by AI keywords
     *
     * Uses JSON ai_keywords field for semantic matching
     * Returns emotions sorted by match score (descending)
     *
     * @param string $text Text to match against keywords
     * @param float $threshold Minimum match score (0.0-1.0, default: 0.5)
     * @return array Matching emotions with match_score field
     */
    public function findByKeywords(string $text, float $threshold = 0.5): array
    {
        $text = strtolower(trim($text));

        // ENTERPRISE: Fetch all emotions from cache
        $emotions = $this->db()->query("
            SELECT e.*,
                   JSON_LENGTH(e.ai_keywords) as keyword_count
            FROM {$this->table} e
            WHERE e.is_active = TRUE
            ORDER BY e.sort_order ASC
        ", [], [
            'cache' => true,
            'cache_ttl' => 'long',
        ]);

        $matches = [];

        // PHP-side keyword matching (faster than JSON queries for 10 emotions)
        foreach ($emotions as $emotion) {
            if (empty($emotion['ai_keywords'])) {
                continue;
            }

            $keywords = json_decode($emotion['ai_keywords'], true) ?: [];
            $matchScore = 0;

            foreach ($keywords as $keyword) {
                if (stripos($text, $keyword) !== false) {
                    $matchScore += 1 / count($keywords);
                }
            }

            if ($matchScore >= $threshold) {
                $emotion['match_score'] = $matchScore;
                $matches[] = $emotion;
            }
        }

        // Sort by match score (descending)
        usort($matches, fn ($a, $b) => $b['match_score'] <=> $a['match_score']);

        return $matches;
    }

    /**
     * Get system summary (10 emotions: 5 positive, 5 negative)
     *
     * Returns category counts and emotion lists
     * Used for dashboard and admin panels
     *
     * @return array Summary by category
     */
    public function getSystemSummary(): array
    {
        return $this->db()->query("
            SELECT
                category,
                COUNT(*) as count,
                STRING_AGG(name_it, ', ' ORDER BY sort_order) as emotions_list
            FROM {$this->table}
            WHERE is_active = TRUE
            GROUP BY category
            ORDER BY
                CASE category
                    WHEN 'positive' THEN 1
                    WHEN 'negative' THEN 2
                    ELSE 3
                END
        ", [], [
            'cache' => true,
            'cache_ttl' => 'long',
        ]);
    }

    /**
     * Validate 10-emotion system integrity
     *
     * Checks:
     * - 5 positive emotions
     * - 5 negative emotions
     * - Total: 10 emotions
     *
     * @return array Validation result with issues and summary
     */
    public function validateSystem(): array
    {
        $summary = $this->getSystemSummary();
        $issues = [];

        // ENTERPRISE: Validate emotion counts (10 emotions total)
        foreach ($summary as $category) {
            $expectedCount = match($category['category']) {
                'positive' => 5,
                'negative' => 5,
                default => 0
            };

            if ((int)$category['count'] !== $expectedCount) {
                $issues[] = "Category {$category['category']}: found {$category['count']} emotions, expected {$expectedCount}";
            }
        }

        $totalEmotions = array_sum(array_column($summary, 'count'));

        if ($totalEmotions !== 10) {
            $issues[] = "Total emotions: {$totalEmotions}, expected 10";
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'summary' => $summary,
            'total_emotions' => $totalEmotions,
            'system_version' => '2.0', // 10-emotion system
        ];
    }

    /**
     * Find emotion by ID
     *
     * Uses PRIMARY KEY index for O(1) lookup
     *
     * @param int $id Emotion ID (1-10)
     * @return array|null Emotion data or null
     */
    public function findById(int $id): ?array
    {
        // ENTERPRISE: PRIMARY KEY lookup (instant)
        return $this->find($id);
    }

    /**
     * Get emotion by Italian name
     *
     * Uses UNIQUE index on name_it for O(1) lookup
     *
     * @param string $nameIt Italian emotion name (e.g., "Gioia")
     * @return array|null Emotion data or null if not found
     */
    public function getByNameIt(string $nameIt): ?array
    {
        return $this->db()->findOne("
            SELECT * FROM {$this->table}
            WHERE name_it = ? AND is_active = TRUE
        ", [$nameIt], [
            'cache' => true,
            'cache_ttl' => 'long',
        ]);
    }

    /**
     * Get emotions for dropdown/select UI elements
     *
     * Returns minimal data for frontend selectors
     * Format: [{id, name_it, name_en, icon_emoji, color_hex}]
     *
     * @return array Emotion list optimized for UI
     */
    public function getForSelect(): array
    {
        return $this->db()->query("
            SELECT
                id,
                name_it,
                name_en,
                icon_emoji,
                color_hex,
                category
            FROM {$this->table}
            WHERE is_active = TRUE
            ORDER BY category, sort_order ASC
        ", [], [
            'cache' => true,
            'cache_ttl' => 'long',
        ]);
    }
}
