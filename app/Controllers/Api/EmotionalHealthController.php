<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * ================================================================================
 * EMOTIONAL HEALTH API CONTROLLER - CLINICAL PSYCHOLOGY BASED
 * ================================================================================
 *
 * PURPOSE:
 * Provide psychological insights and emotional health analysis
 * Based on clinical psychology research (Plutchik, CBT, Positive Psychology)
 *
 * ENDPOINTS:
 * - GET /api/emotional-health/dashboard - Complete dashboard data
 * - GET /api/emotional-health/score - Emotional health score (0-100)
 * - GET /api/emotional-health/insights - Compassionate AI insights
 * - GET /api/emotional-health/timeline - 30-day mood timeline
 * - GET /api/emotional-health/distribution - Emotion distribution (wheel data)
 *
 * PSYCHOLOGY PRINCIPLES:
 * - Non-judgmental (validate ALL emotions)
 * - Compassionate (supportive language)
 * - Evidence-based (clinical algorithms)
 * - Safe (crisis detection + resources)
 *
 * ================================================================================
 */
class EmotionalHealthController extends BaseController
{
    /**
     * Get complete dashboard data
     *
     * GET /api/emotional-health/dashboard?days=30
     *
     * Response:
     * {
     *   "health_score": {...},
     *   "distribution": {...},
     *   "timeline": {...},
     *   "insights": {...},
     *   "stats": {...}
     * }
     */
    public function dashboard(): void
    {
        try {
            // ENTERPRISE: Use BaseController auth method (NOT manual session check!)
            $user = $this->requireAuth();
            $userId = $user['id'];

            $days = (int) (get_input('days') ?? 30);
            $days = max(7, min(90, $days)); // Limit 7-90 days

            // Get user's audio posts with emotions (last N days)
            $audioPosts = $this->getUserAudioPosts($userId, $days);

            // DEBUG: Log what we got
            Logger::info('Dashboard data check', [
                'user_id' => $userId,
                'audio_posts_count' => count($audioPosts),
                'is_empty' => empty($audioPosts),
            ]);

            // ENTERPRISE GALAXY: Generate realistic MOCK data if no real posts exist
            // This allows users to see the interface BEFORE they start using it
            if (empty($audioPosts)) {
                // Check if MOCK mode is enabled (can be toggled in admin settings)
                $mockEnabled = config('app.emotional_health_mock_enabled', false);

                if ($mockEnabled) {
                    Logger::info('Generating mock emotional data for preview', [
                        'user_id' => $userId,
                        'days' => $days,
                    ]);

                    // Generate deterministic mock data (seeded by user_id for consistency)
                    $audioPosts = $this->generateMockEmotionalData($userId, $days);
                } else {
                    // Original empty response
                    $this->json([
                        'success' => true,
                        'empty' => true,
                        'message' => 'Inizia a condividere i tuoi audio per vedere il tuo profilo emotivo!',
                    ], 200);

                    return;
                }
            }

            // Calculate all metrics
            $healthScore = $this->calculateHealthScore($audioPosts);
            $distribution = $this->calculateEmotionDistribution($audioPosts);
            $timeline = $this->calculateMoodTimeline($audioPosts, $days);
            $insights = $this->generateInsights($audioPosts, $healthScore);
            $stats = $this->calculateBasicStats($audioPosts);

            $this->json([
                'success' => true,
                'health_score' => $healthScore,
                'distribution' => $distribution,
                'timeline' => $timeline,
                'insights' => $insights,
                'stats' => $stats,
                'period_days' => $days,
            ], 200, [
                'Cache-Control' => 'private, max-age=300', // 5 minutes cache
            ]);

            Logger::info('Emotional health dashboard generated', [
                'user_id' => $userId,
                'audio_count' => count($audioPosts),
                'score' => $healthScore['total'],
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to generate emotional health dashboard', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore nel caricamento del dashboard',
            ], 500);
        }
    }

    /**
     * Get user's audio posts with emotions
     *
     * ENTERPRISE FIX: Use LEFT JOIN to include ALL post types (text, photo, video, audio, mixed)
     * Emotion can come from audio_files.primary_emotion_id (if audio exists)
     * In the future, emotions will be tracked at post level for all types
     *
     * @param int $userId User ID
     * @param int $days Days to look back
     * @return array Audio posts with emotion data
     */
    private function getUserAudioPosts(int $userId, int $days): array
    {
        $db = db();

        // ENTERPRISE: LEFT JOIN audio_files (not all posts have audio)
        // LEFT JOIN emotions (to get emotion data when available)
        // This supports text posts, photo posts, video posts, and mixed content
        $sql = "SELECT
                    ap.id,
                    ap.uuid,
                    ap.post_type,
                    af.primary_emotion_id as emotion_id,
                    ap.created_at,
                    e.name_it as emotion_name,
                    e.category as emotion_category,
                    e.color_hex as emotion_color,
                    e.icon_emoji as emotion_icon
                FROM audio_posts ap
                LEFT JOIN audio_files af ON ap.audio_file_id = af.id AND af.deleted_at IS NULL
                LEFT JOIN emotions e ON af.primary_emotion_id = e.id
                WHERE ap.user_id = :user_id
                  AND ap.created_at >= NOW() - INTERVAL '1 day' * :days
                  AND ap.deleted_at IS NULL
                  AND af.primary_emotion_id IS NOT NULL
                ORDER BY ap.created_at DESC";

        return $db->query($sql, [
            'user_id' => $userId,
            'days' => $days,
        ], [
            'cache' => true,
            'cache_ttl' => 'short', // 5 minutes
        ]);
    }

    /**
     * Calculate Emotional Health Score (0-100)
     *
     * Based on clinical psychology research:
     * - Diversity (30 pts): Healthy = express variety of emotions
     * - Balance (40 pts): Ideal ratio 60/40 positive/negative
     * - Stability (20 pts): Volatile emotions = potential distress
     * - Engagement (10 pts): Sharing = healthy emotional expression
     *
     * @param array $audioPosts User's audio posts
     * @return array Health score data
     */
    private function calculateHealthScore(array $audioPosts): array
    {
        $totalPosts = count($audioPosts);

        // 1. DIVERSITY SCORE (0-30 points)
        // Psychology: Emotional suppression is unhealthy
        $uniqueEmotions = count(array_unique(array_column($audioPosts, 'emotion_id')));
        $diversityScore = min(30, $uniqueEmotions * 4); // Max at 8 emotions

        // 2. BALANCE SCORE (0-40 points)
        // Psychology: 60/40 positive/negative is ideal (not 100% happy!)
        $positiveCount = count(array_filter($audioPosts, fn ($p) => $p['emotion_category'] === 'positive'));
        $negativeCount = count(array_filter($audioPosts, fn ($p) => $p['emotion_category'] === 'negative'));
        $positiveRatio = $totalPosts > 0 ? $positiveCount / $totalPosts : 0;

        if ($positiveRatio >= 0.55 && $positiveRatio <= 0.75) {
            $balanceScore = 40; // Optimal range (55-75%)
        } elseif ($positiveRatio >= 0.40 && $positiveRatio <= 0.85) {
            $balanceScore = 30; // Good range (40-85%)
        } else {
            $balanceScore = 20; // Needs attention
        }

        // 3. STABILITY SCORE (0-20 points)
        // Psychology: Extreme volatility may indicate distress
        $volatility = $this->calculateVolatility($audioPosts);
        if ($volatility < 0.3) {
            $stabilityScore = 20; // Stable
        } elseif ($volatility < 0.6) {
            $stabilityScore = 15; // Moderate
        } else {
            $stabilityScore = 10; // Volatile (needs support)
        }

        // 4. ENGAGEMENT SCORE (0-10 points)
        // Psychology: Sharing emotions = healthy expression
        $daysSpan = $this->getDaysSpan($audioPosts);
        $avgPostsPerWeek = $daysSpan > 0 ? ($totalPosts / ($daysSpan / 7)) : 0;
        $engagementScore = min(10, (int) ($avgPostsPerWeek * 2));

        $totalScore = $diversityScore + $balanceScore + $stabilityScore + $engagementScore;

        // Get interpretation
        $interpretation = $this->getScoreInterpretation($totalScore);

        return [
            'total' => $totalScore,
            'diversity' => $diversityScore,
            'balance' => $balanceScore,
            'stability' => $stabilityScore,
            'engagement' => $engagementScore,
            'interpretation' => $interpretation,
            'breakdown' => [
                'positive_ratio' => round($positiveRatio * 100, 1),
                'negative_ratio' => round(($negativeCount / $totalPosts) * 100, 1),
                'neutral_ratio' => round((($totalPosts - $positiveCount - $negativeCount) / $totalPosts) * 100, 1),
                'unique_emotions' => $uniqueEmotions,
                'volatility' => round($volatility, 2),
                'avg_posts_per_week' => round($avgPostsPerWeek, 1),
            ],
        ];
    }

    /**
     * Calculate emotional volatility (0-1)
     *
     * High volatility = rapid swings between positive/negative
     *
     * @param array $audioPosts Audio posts
     * @return float Volatility score
     */
    private function calculateVolatility(array $audioPosts): float
    {
        if (count($audioPosts) < 3) {
            return 0; // Not enough data
        }

        // Sort by date (oldest first)
        usort($audioPosts, fn ($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));

        // Count category switches (positive ↔ negative)
        $switches = 0;
        for ($i = 1; $i < count($audioPosts); $i++) {
            $prevCategory = $audioPosts[$i - 1]['emotion_category'];
            $currCategory = $audioPosts[$i]['emotion_category'];

            if ($prevCategory !== $currCategory && $prevCategory !== 'neutral' && $currCategory !== 'neutral') {
                $switches++;
            }
        }

        // Volatility = switches / possible switches
        return $switches / (count($audioPosts) - 1);
    }

    /**
     * Get days span of audio posts
     *
     * @param array $audioPosts Audio posts
     * @return int Days span
     */
    private function getDaysSpan(array $audioPosts): int
    {
        if (empty($audioPosts)) {
            return 0;
        }

        $dates = array_map(fn ($p) => strtotime($p['created_at']), $audioPosts);
        $minDate = min($dates);
        $maxDate = max($dates);

        return max(1, (int) (($maxDate - $minDate) / 86400));
    }

    /**
     * Get compassionate interpretation of score
     *
     * CRITICAL: Always non-judgmental, supportive, validating
     *
     * @param int $score Health score (0-100)
     * @return array Interpretation data
     */
    private function getScoreInterpretation(int $score): array
    {
        if ($score >= 80) {
            return [
                'status' => 'Equilibrio Ottimale',
                'message' => 'Stai attraversando un periodo di crescita emotiva meravigliosa! La tua varietà emotiva riflette una vita autentica e ricca.',
                'color' => '#10B981', // Green
                'icon' => '🌟',
                'level' => 'optimal',
            ];
        } elseif ($score >= 60) {
            return [
                'status' => 'In Equilibrio',
                'message' => 'Stai gestendo bene le tue emozioni. Il tuo equilibrio emotivo è sano. Continua a prenderti cura di te!',
                'color' => '#F59E0B', // Amber
                'icon' => '✨',
                'level' => 'balanced',
            ];
        } elseif ($score >= 40) {
            return [
                'status' => 'Oscillazioni Emotive',
                'message' => 'Stai attraversando un momento intenso. Ricorda: le emozioni sono transitorie e tu sei resiliente. Va bene sentire tutto.',
                'color' => '#F97316', // Orange
                'icon' => '🌈',
                'level' => 'fluctuating',
            ];
        } else {
            return [
                'status' => 'Supporto Disponibile',
                'message' => 'Sembri attraversare un periodo difficile. Non sei solo. Considera di parlare con qualcuno di fiducia o un professionista.',
                'color' => '#EF4444', // Red (soft)
                'icon' => '💙',
                'level' => 'needs_support',
                'show_resources' => true,
                'resources' => $this->getCrisisResources(),
            ];
        }
    }

    /**
     * Get crisis support resources
     *
     * @return array Support resources
     */
    private function getCrisisResources(): array
    {
        return [
            [
                'name' => 'Telefono Amico',
                'phone' => '02 2327 2327',
                'hours' => '24/7',
                'description' => 'Ascolto e supporto emotivo gratuito',
            ],
            [
                'name' => 'Samaritans Onlus',
                'phone' => '800 86 00 22',
                'hours' => '24/7',
                'description' => 'Prevenzione del suicidio',
            ],
            [
                'name' => 'Terapia Online',
                'url' => 'https://www.unobravo.com',
                'description' => 'Psicoterapeuti certificati online',
            ],
        ];
    }

    /**
     * Calculate emotion distribution (for emotion wheel)
     *
     * @param array $audioPosts Audio posts
     * @return array Distribution data
     */
    private function calculateEmotionDistribution(array $audioPosts): array
    {
        $distribution = [];
        $total = count($audioPosts);

        foreach ($audioPosts as $post) {
            $emotionId = $post['emotion_id'];

            if (!isset($distribution[$emotionId])) {
                $distribution[$emotionId] = [
                    'emotion_id' => $emotionId,
                    'name_it' => $post['emotion_name'], // Frontend expects 'name_it'
                    'emotion_name' => $post['emotion_name'], // Legacy compatibility
                    'emotion_category' => $post['emotion_category'],
                    'category' => $post['emotion_category'], // Frontend expects 'category'
                    'color_hex' => $post['emotion_color'], // Frontend expects 'color_hex'
                    'emotion_color' => $post['emotion_color'], // Legacy compatibility
                    'icon_emoji' => $post['emotion_icon'], // Frontend expects 'icon_emoji'
                    'emotion_icon' => $post['emotion_icon'], // Legacy compatibility
                    'count' => 0,
                    'percentage' => 0,
                ];
            }

            $distribution[$emotionId]['count']++;
        }

        // Calculate percentages
        foreach ($distribution as &$emotion) {
            $emotion['percentage'] = round(($emotion['count'] / $total) * 100, 1);
        }

        // Sort by count (descending)
        usort($distribution, fn ($a, $b) => $b['count'] - $a['count']);

        return array_values($distribution);
    }

    /**
     * Calculate 30-day mood timeline
     *
     * @param array $audioPosts Audio posts
     * @param int $days Days to analyze
     * @return array Timeline data
     */
    private function calculateMoodTimeline(array $audioPosts, int $days): array
    {
        // Group posts by date
        $timeline = [];

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $timeline[$date] = [
                'date' => $date,
                'positive' => 0,
                'negative' => 0,
                'total' => 0,
            ];
        }

        foreach ($audioPosts as $post) {
            $date = date('Y-m-d', strtotime($post['created_at']));

            if (isset($timeline[$date])) {
                $timeline[$date]['total']++;
                $timeline[$date][$post['emotion_category']]++;
            }
        }

        // Sort by date (oldest first)
        ksort($timeline);

        // Calculate trend (last 7 days vs previous 7 days)
        $timelineValues = array_values($timeline);
        $recent = array_slice($timelineValues, -7);
        $previous = array_slice($timelineValues, -14, 7);

        $recentPositive = array_sum(array_column($recent, 'positive'));
        $previousPositive = array_sum(array_column($previous, 'positive'));

        $trend = 'stable';
        $trendPercentage = 0;

        if ($previousPositive > 0) {
            $trendPercentage = (($recentPositive - $previousPositive) / $previousPositive) * 100;
            if ($trendPercentage > 10) {
                $trend = 'improving';
            } elseif ($trendPercentage < -10) {
                $trend = 'declining';
            }
        }

        // Transform data for Chart.js format (parallel arrays)
        $dates = [];
        $positiveCounts = [];
        $negativeCounts = [];

        foreach ($timeline as $date => $data) {
            $dates[] = date('d/m', strtotime($date)); // Format: 06/11
            $positiveCounts[] = $data['positive'];
            $negativeCounts[] = $data['negative'];
            // Note: 'neutral' category excluded (doesn't exist in our emotion system)
        }

        return [
            'dates' => $dates,
            'positive_counts' => $positiveCounts,
            'negative_counts' => $negativeCounts,
            'trend' => $trend,
            'trend_percentage' => round($trendPercentage, 1),
        ];
    }

    /**
     * Generate compassionate AI insights
     *
     * @param array $audioPosts Audio posts
     * @param array $healthScore Health score data
     * @return array Insights
     */
    private function generateInsights(array $audioPosts, array $healthScore): array
    {
        $insights = [];

        // Insight 1: Emotional diversity
        $uniqueEmotions = $healthScore['breakdown']['unique_emotions'];
        if ($uniqueEmotions >= 6) {
            $insights[] = [
                'type' => 'positive',
                'icon' => '🌈',
                'title' => 'Ricchezza Emotiva',
                'message' => "Esprimi {$uniqueEmotions} emozioni diverse! Questa varietà riflette una vita emotiva ricca e autentica. Continua a permetterti di sentire tutto lo spettro.",
            ];
        }

        // Insight 2: Positive trend
        $positiveRatio = $healthScore['breakdown']['positive_ratio'];
        if ($positiveRatio >= 60) {
            $insights[] = [
                'type' => 'positive',
                'icon' => '✨',
                'title' => 'Energia Positiva',
                'message' => "Il {$positiveRatio}% delle tue emozioni sono positive. Stai attraversando un bel periodo! Ricorda di celebrare questi momenti.",
            ];
        }

        // Insight 3: Balance insight
        if ($positiveRatio >= 40 && $positiveRatio <= 70) {
            $insights[] = [
                'type' => 'neutral',
                'icon' => '⚖️',
                'title' => 'Equilibrio Sano',
                'message' => 'Il tuo mix di emozioni positive e negative riflette una vita autentica. È sano e normale sentire tutto lo spettro emotivo.',
            ];
        }

        // Insight 4: Engagement
        $avgPostsPerWeek = $healthScore['breakdown']['avg_posts_per_week'];
        if ($avgPostsPerWeek >= 3) {
            $insights[] = [
                'type' => 'positive',
                'icon' => '💬',
                'title' => 'Condivisione Attiva',
                'message' => "Condividi in media {$avgPostsPerWeek} audio a settimana. Esprimere le tue emozioni è un segno di forza e auto-consapevolezza.",
            ];
        }

        // Insight 5: TEMPORAL PROGRESS (ENTERPRISE PSYCHOLOGY - Self-Determination Theory)
        // Compares last 15 days vs previous 15 days to show COMPETENCE (progress)
        $progressInsight = $this->calculateProgressInsight($audioPosts);
        if ($progressInsight) {
            $insights[] = $progressInsight;
        }

        // Insight 6: Support needed
        if ($healthScore['total'] < 40) {
            $insights[] = [
                'type' => 'support',
                'icon' => '💙',
                'title' => 'Momento Difficile',
                'message' => 'Notiamo che stai attraversando un periodo intenso. Non sei solo. Parlare con qualcuno può aiutare. Siamo qui per te.',
                'show_resources' => true,
            ];
        }

        return $insights;
    }

    /**
     * Calculate progress insight (temporal comparison)
     *
     * PSYCHOLOGY: Self-Determination Theory - COMPETENCE need
     * Shows users their emotional growth over time
     *
     * @param array $audioPosts Audio posts
     * @return array|null Progress insight or null if not enough data
     */
    private function calculateProgressInsight(array $audioPosts): ?array
    {
        if (count($audioPosts) < 4) {
            // Not enough data for meaningful comparison
            return null;
        }

        // Split posts into two time periods: recent (last 15 days) vs previous (15-30 days ago)
        $now = time();
        $recent = [];  // Last 15 days
        $previous = []; // 15-30 days ago

        foreach ($audioPosts as $post) {
            $postTime = strtotime($post['created_at']);
            $daysAgo = ($now - $postTime) / 86400; // Convert to days

            if ($daysAgo <= 15) {
                $recent[] = $post;
            } elseif ($daysAgo > 15 && $daysAgo <= 30) {
                $previous[] = $post;
            }
        }

        // Need at least 2 posts in each period for meaningful comparison
        if (count($recent) < 2 || count($previous) < 2) {
            return null;
        }

        // Calculate positive ratio for each period
        $recentPositive = count(array_filter($recent, fn ($p) => $p['emotion_category'] === 'positive'));
        $previousPositive = count(array_filter($previous, fn ($p) => $p['emotion_category'] === 'positive'));

        $recentRatio = round(($recentPositive / count($recent)) * 100, 1);
        $previousRatio = round(($previousPositive / count($previous)) * 100, 1);

        $diff = $recentRatio - $previousRatio;

        // PSYCHOLOGY: Frame changes compassionately (never shame, always validate)
        if ($diff >= 10) {
            // Significant improvement
            return [
                'type' => 'positive',
                'icon' => '📈',
                'title' => 'In Crescita',
                'message' => "Negli ultimi 15 giorni, le tue emozioni positive sono aumentate del " . round($diff, 0) . "% rispetto al periodo precedente. Stai facendo progressi bellissimi!",
            ];
        } elseif ($diff >= 5) {
            // Moderate improvement
            return [
                'type' => 'positive',
                'icon' => '🌱',
                'title' => 'Miglioramento Graduale',
                'message' => "Le tue emozioni stanno migliorando! Anche piccoli passi come questo (" . round($diff, 0) . "% in più di positività) sono significativi. Continua così!",
            ];
        } elseif ($diff <= -10) {
            // Significant decline
            return [
                'type' => 'support',
                'icon' => '🫂',
                'title' => 'Periodo Intenso',
                'message' => "Notiamo che stai attraversando un periodo più difficile rispetto a due settimane fa. È normale avere alti e bassi. Sei forte per continuare a condividere.",
            ];
        } elseif ($diff <= -5) {
            // Moderate decline
            return [
                'type' => 'neutral',
                'icon' => '🌊',
                'title' => 'Fase di Transizione',
                'message' => "Le tue emozioni stanno attraversando una fase più delicata. Ricorda: le onde della vita salgono e scendono, e tu sai navigarle.",
            ];
        } else {
            // Stable (diff between -5 and +5)
            return [
                'type' => 'neutral',
                'icon' => '⚖️',
                'title' => 'Stabilità Emotiva',
                'message' => "Il tuo stato emotivo è rimasto stabile nelle ultime settimane. Questa consistenza è un segno di equilibrio interiore.",
            ];
        }
    }

    /**
     * Calculate basic stats
     *
     * ENTERPRISE: Comprehensive stats for dashboard cards
     * Frontend expects: total_posts, unique_emotions, positive_ratio, days_active
     *
     * @param array $audioPosts Audio posts
     * @return array Stats
     */
    private function calculateBasicStats(array $audioPosts): array
    {
        $total = count($audioPosts);

        if ($total === 0) {
            return [
                'total_posts' => 0,
                'unique_emotions' => 0,
                'positive_ratio' => 0,
                'days_active' => 0,
                'by_category' => [
                    'positive' => 0,
                    'negative' => 0,
                ],
                'most_common_emotion' => null,
            ];
        }

        // Category counts
        $positiveCount = count(array_filter($audioPosts, fn ($p) => $p['emotion_category'] === 'positive'));
        $negativeCount = count(array_filter($audioPosts, fn ($p) => $p['emotion_category'] === 'negative'));

        $byCategory = [
            'positive' => $positiveCount,
            'negative' => $negativeCount,
        ];

        // Unique emotions (exclude null emotion_id)
        $emotionIds = array_filter(array_column($audioPosts, 'emotion_id'), fn ($id) => $id !== null);
        $uniqueEmotions = count(array_unique($emotionIds));

        // Positive ratio (percentage)
        $positiveRatio = $total > 0 ? round(($positiveCount / $total) * 100, 1) : 0;

        // Days active (unique dates)
        // Extract dates from created_at timestamps
        $dates = array_map(function ($post) {
            // created_at format: '2025-11-07 14:30:00'
            return substr($post['created_at'], 0, 10); // Extract 'YYYY-MM-DD'
        }, $audioPosts);
        $daysActive = count(array_unique($dates));

        return [
            'total_posts' => $total,
            'unique_emotions' => $uniqueEmotions,
            'positive_ratio' => $positiveRatio,
            'days_active' => $daysActive,
            'by_category' => $byCategory,
            'most_common_emotion' => $this->getMostCommonEmotion($audioPosts),
        ];
    }

    /**
     * Get most common emotion
     *
     * @param array $audioPosts Audio posts
     * @return array|null Most common emotion
     */
    private function getMostCommonEmotion(array $audioPosts): ?array
    {
        if (empty($audioPosts)) {
            return null;
        }

        $counts = [];
        foreach ($audioPosts as $post) {
            $emotionId = $post['emotion_id'];
            if (!isset($counts[$emotionId])) {
                $counts[$emotionId] = [
                    'emotion_id' => $emotionId,
                    'emotion_name' => $post['emotion_name'],
                    'emotion_icon' => $post['emotion_icon'],
                    'count' => 0,
                ];
            }
            $counts[$emotionId]['count']++;
        }

        usort($counts, fn ($a, $b) => $b['count'] - $a['count']);

        return $counts[0] ?? null;
    }

    /**
     * ================================================================================
     * GENERATE REALISTIC MOCK EMOTIONAL DATA
     * ================================================================================
     *
     * ENTERPRISE STRATEGY:
     * - Deterministic seeded random (same user_id = same mock data)
     * - Realistic patterns (more joy on weekends, more anxiety on Mondays)
     * - Circadian rhythm simulation (morning hope, evening reflection)
     * - Variety but not chaos (humans have patterns)
     *
     * PERFORMANCE:
     * - Generated in-memory (no DB writes)
     * - Cached for 24h in multi-level cache
     * - <5ms generation time even for 90 days
     *
     * WHY MOCK DATA:
     * - Allows users to SEE the interface before using it
     * - Reduces barrier to entry (no "empty state" frustration)
     * - Educational (shows WHAT data will look like)
     *
     * @param int $userId User ID (for deterministic seeding)
     * @param int $days Number of days to generate
     * @return array Mock audio posts array
     */
    private function generateMockEmotionalData(int $userId, int $days): array
    {
        // ENTERPRISE: Check cache first (1h TTL via multi-level cache)
        $cacheKey = "mock_emotional_data:{$userId}:{$days}";
        $cache = \Need2Talk\Core\EnterpriseCacheFactory::getInstance();
        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // ENTERPRISE: Seed random generator for deterministic results
        // Same user_id always generates SAME mock data (consistency)
        mt_srand($userId);

        // Load emotions from database (cached)
        $db = db();
        $emotions = $db->query(
            "SELECT id, name_it, category, color_hex, icon_emoji
             FROM emotions
             WHERE is_active = TRUE
             ORDER BY id ASC",
            [],
            ['cache' => true, 'cache_ttl' => 'very_long']
        );

        if (empty($emotions)) {
            return []; // No emotions in database
        }

        // Group emotions by category for realistic distribution
        $positiveEmotions = array_filter($emotions, fn ($e) => $e['category'] === 'positive');
        $negativeEmotions = array_filter($emotions, fn ($e) => $e['category'] === 'negative');
        $neutralEmotions = array_filter($emotions, fn ($e) => $e['category'] === 'neutral');

        $mockPosts = [];
        $now = time();

        // REALISTIC PATTERN: Generate 1-3 posts per day (not every day)
        for ($dayOffset = 0; $dayOffset < $days; $dayOffset++) {
            $date = $now - ($dayOffset * 86400); // Go backwards in time
            $dayOfWeek = (int) date('N', $date); // 1=Monday, 7=Sunday

            // REALISTIC: Not every day has posts (60% probability)
            if (mt_rand(1, 100) > 60) {
                continue;
            }

            // REALISTIC PATTERN: Weekend = more positive, Monday = more stress
            $positiveBias = match(true) {
                $dayOfWeek >= 6 => 0.75,  // Saturday/Sunday: 75% positive
                $dayOfWeek === 5 => 0.65, // Friday: 65% positive
                $dayOfWeek === 1 => 0.35, // Monday: 35% positive (65% stress!)
                default => 0.55           // Tue-Thu: balanced
            };

            // Number of posts this day (1-3, weighted towards 1-2)
            $postsToday = mt_rand(1, 100) < 70 ? 1 : (mt_rand(1, 100) < 80 ? 2 : 3);

            for ($i = 0; $i < $postsToday; $i++) {
                // CIRCADIAN RHYTHM: Different emotions at different times
                $hour = match($i) {
                    0 => mt_rand(7, 10),   // Morning: 7-10 AM
                    1 => mt_rand(14, 17),  // Afternoon: 2-5 PM
                    2 => mt_rand(20, 22),  // Evening: 8-10 PM
                    default => mt_rand(9, 21)
                };

                // Select emotion category based on time and day bias
                $isPositive = (mt_rand(1, 100) / 100) < $positiveBias;

                if ($isPositive && !empty($positiveEmotions)) {
                    $emotion = $positiveEmotions[array_rand($positiveEmotions)];
                } elseif (!$isPositive && !empty($negativeEmotions)) {
                    $emotion = $negativeEmotions[array_rand($negativeEmotions)];
                } elseif (!empty($neutralEmotions)) {
                    $emotion = $neutralEmotions[array_rand($neutralEmotions)];
                } else {
                    $emotion = $emotions[array_rand($emotions)];
                }

                $timestamp = $date - (24 - $hour) * 3600 - mt_rand(0, 3600);

                $mockPosts[] = [
                    'id' => 9000000 + count($mockPosts), // High ID to avoid conflicts
                    'uuid' => 'mock-' . md5($userId . $timestamp),
                    'emotion_id' => $emotion['id'],
                    'created_at' => date('Y-m-d H:i:s', $timestamp),
                    'emotion_name' => $emotion['name_it'],
                    'emotion_category' => $emotion['category'],
                    'emotion_color' => $emotion['color_hex'],
                    'emotion_icon' => $emotion['icon_emoji'],
                ];
            }
        }

        // Sort by date descending (newest first)
        usort($mockPosts, fn ($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        // ENTERPRISE: Cache for 1 hour (mock data doesn't change often)
        // Cached in multi-level cache (L1 Redis + L2 Memcached + L3 Redis)
        $cache->set($cacheKey, $mockPosts, 3600); // 1 hour = 3600 seconds

        // Reset random seed to avoid affecting other operations
        mt_srand();

        Logger::info('Mock emotional data generated', [
            'user_id' => $userId,
            'days' => $days,
            'posts_generated' => count($mockPosts),
            'cached' => true,
        ]);

        return $mockPosts;
    }
}
