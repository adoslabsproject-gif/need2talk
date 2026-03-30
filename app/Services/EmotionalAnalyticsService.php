<?php

declare(strict_types=1);

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;

/**
 * EmotionalAnalyticsService - ENTERPRISE GALAXY V13.0
 *
 * EVIDENCE-BASED Emotional Analytics using validated psychological models.
 *
 * ============================================================================
 * SCIENTIFIC FOUNDATION
 * ============================================================================
 *
 * 1. RUSSELL'S CIRCUMPLEX MODEL (Russell, 1980)
 *    - Gold standard in affective science for 40+ years
 *    - Two dimensions: Valence (pleasant/unpleasant) + Arousal (activated/deactivated)
 *    - No value judgment - describes emotional states without "good/bad"
 *    - Citation: Russell, J. A. (1980). A circumplex model of affect.
 *                Journal of Personality and Social Psychology, 39(6), 1161-1178.
 *
 * 2. EMOTIONAL GRANULARITY (Lisa Feldman Barrett)
 *    - People who differentiate emotions precisely have better outcomes
 *    - More granular = better emotional regulation (validated finding)
 *    - Citation: Barrett, L. F. (2017). How Emotions Are Made.
 *                Houghton Mifflin Harcourt.
 *
 * 3. EMOTIONS AS INFORMATION (Schwarz & Clore, 1983)
 *    - Emotions provide valuable data about our relationship with environment
 *    - All emotions serve adaptive functions - none are "bad"
 *    - Citation: Schwarz, N., & Clore, G. L. (1983). Mood, misattribution,
 *                and judgments of well-being. Cognitive Psychology.
 *
 * ============================================================================
 * WHAT THIS SERVICE DOES NOT DO (IMPORTANT)
 * ============================================================================
 *
 * - NO "Health Score" (0-100) - not scientifically validated
 * - NO "optimal ratio" claims - Losada ratio was retracted in 2013
 * - NO judgment of emotions as "good" or "bad"
 * - NO clinical diagnoses or mental health assessments
 *
 * This is a DESCRIPTIVE tool, not a PRESCRIPTIVE one.
 * It shows patterns; it does not judge them.
 *
 * @package Need2Talk\Services
 */
class EmotionalAnalyticsService
{
    private const CACHE_TTL = 300;
    private const CACHE_KEY_PREFIX = 'need2talk:emotional:analysis:';

    /**
     * Russell Circumplex coordinates for each emotion
     *
     * Valence: -1 (unpleasant) to +1 (pleasant)
     * Arousal: -1 (deactivated) to +1 (activated)
     *
     * Based on Russell (1980) and subsequent empirical studies
     */
    private const EMOTION_CIRCUMPLEX = [
        // Emotion ID => [valence, arousal, quadrant]
        1 => ['valence' => 0.80, 'arousal' => 0.60, 'quadrant' => 'high_valence_high_arousal'],  // Gioia
        2 => ['valence' => 0.70, 'arousal' => 0.85, 'quadrant' => 'high_valence_high_arousal'],  // Meraviglia
        3 => ['valence' => 0.85, 'arousal' => 0.40, 'quadrant' => 'high_valence_low_arousal'],   // Amore
        4 => ['valence' => 0.75, 'arousal' => 0.25, 'quadrant' => 'high_valence_low_arousal'],   // Gratitudine
        5 => ['valence' => 0.60, 'arousal' => 0.35, 'quadrant' => 'high_valence_low_arousal'],   // Speranza
        6 => ['valence' => -0.70, 'arousal' => -0.50, 'quadrant' => 'low_valence_low_arousal'],  // Tristezza
        7 => ['valence' => -0.75, 'arousal' => 0.80, 'quadrant' => 'low_valence_high_arousal'],  // Rabbia
        8 => ['valence' => -0.55, 'arousal' => 0.75, 'quadrant' => 'low_valence_high_arousal'],  // Ansia
        9 => ['valence' => -0.65, 'arousal' => 0.80, 'quadrant' => 'low_valence_high_arousal'],  // Paura
        10 => ['valence' => -0.60, 'arousal' => -0.40, 'quadrant' => 'low_valence_low_arousal'], // Solitudine
    ];

    /**
     * Emotion names for display
     */
    private const EMOTION_NAMES = [
        1 => ['it' => 'Gioia', 'en' => 'Happiness', 'icon' => '😊', 'color' => '#FFD700'],
        2 => ['it' => 'Meraviglia', 'en' => 'Wonder', 'icon' => '✨', 'color' => '#FF6B35'],
        3 => ['it' => 'Amore', 'en' => 'Love', 'icon' => '❤️', 'color' => '#FF1493'],
        4 => ['it' => 'Gratitudine', 'en' => 'Gratitude', 'icon' => '🙏', 'color' => '#32CD32'],
        5 => ['it' => 'Speranza', 'en' => 'Hope', 'icon' => '🌟', 'color' => '#87CEEB'],
        6 => ['it' => 'Tristezza', 'en' => 'Sadness', 'icon' => '😢', 'color' => '#4682B4'],
        7 => ['it' => 'Rabbia', 'en' => 'Anger', 'icon' => '😠', 'color' => '#DC143C'],
        8 => ['it' => 'Ansia', 'en' => 'Anxiety', 'icon' => '😰', 'color' => '#FF8C00'],
        9 => ['it' => 'Paura', 'en' => 'Fear', 'icon' => '😨', 'color' => '#8B008B'],
        10 => ['it' => 'Solitudine', 'en' => 'Loneliness', 'icon' => '😔', 'color' => '#696969'],
    ];

    /**
     * Quadrant descriptions (non-judgmental)
     */
    private const QUADRANT_INFO = [
        'high_valence_high_arousal' => [
            'name_it' => 'Attivazione Piacevole',
            'name_en' => 'Pleasant Activation',
            'description_it' => 'Stati di energia e piacevolezza: entusiasmo, gioia attiva, eccitazione.',
            'description_en' => 'States of energy and pleasantness: enthusiasm, active joy, excitement.',
            'color' => '#10B981',
        ],
        'high_valence_low_arousal' => [
            'name_it' => 'Calma Piacevole',
            'name_en' => 'Pleasant Deactivation',
            'description_it' => 'Stati di serenità e benessere calmo: contentezza, gratitudine, pace.',
            'description_en' => 'States of serenity and calm wellbeing: contentment, gratitude, peace.',
            'color' => '#3B82F6',
        ],
        'low_valence_high_arousal' => [
            'name_it' => 'Attivazione Spiacevole',
            'name_en' => 'Unpleasant Activation',
            'description_it' => 'Stati di tensione e disagio attivo: ansia, rabbia, paura. Queste emozioni segnalano che qualcosa richiede attenzione.',
            'description_en' => 'States of tension and active discomfort: anxiety, anger, fear. These emotions signal something needs attention.',
            'color' => '#EF4444',
        ],
        'low_valence_low_arousal' => [
            'name_it' => 'Calma Spiacevole',
            'name_en' => 'Unpleasant Deactivation',
            'description_it' => 'Stati di bassa energia e disagio: tristezza, solitudine, stanchezza emotiva. Spesso segnalano bisogno di riposo o connessione.',
            'description_en' => 'States of low energy and discomfort: sadness, loneliness, emotional fatigue. Often signal need for rest or connection.',
            'color' => '#6366F1',
        ],
    ];

    /**
     * Get complete emotional analytics for user
     */
    public function getAnalytics(int $userId, bool $forceRefresh = false): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $userId;

        if (!$forceRefresh) {
            try {
                $redis = EnterpriseRedisManager::getInstance()->getConnection('default');
                $cached = $redis->get($cacheKey);

                if ($cached) {
                    $data = json_decode($cached, true);
                    if ($data) {
                        return $data;
                    }
                }
            } catch (\Exception $e) {
                Logger::warning('EmotionalAnalytics: Cache read failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $analytics = $this->computeAnalytics($userId);

        try {
            $redis = EnterpriseRedisManager::getInstance()->getConnection('default');
            $redis->setex($cacheKey, self::CACHE_TTL, json_encode($analytics));
        } catch (\Exception $e) {
            Logger::warning('EmotionalAnalytics: Cache write failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return $analytics;
    }

    /**
     * Compute all analytics
     */
    private function computeAnalytics(int $userId): array
    {
        $db = db();
        $now = time();
        $days = 30;

        // 1. Get all emotional data sources
        $evokedEmotions = $this->getEvokedEmotions($db, $userId, $days);
        $expressedEmotions = $this->getExpressedEmotions($db, $userId, $days);
        $journalEmotions = $this->getJournalEmotions($db, $userId, $days);

        // 2. Calculate Russell Circumplex position (Valence-Arousal)
        $circumplexPosition = $this->calculateCircumplexPosition($evokedEmotions, $expressedEmotions, $journalEmotions);

        // 3. Calculate Emotional Granularity (Barrett)
        $granularity = $this->calculateEmotionalGranularity($evokedEmotions, $expressedEmotions, $journalEmotions);

        // 4. Get mood timeline (descriptive, no judgment)
        $moodTimeline = $this->getMoodTimeline($db, $userId, $days);

        // 5. Get patterns (month-over-month comparison, descriptive)
        $patterns = $this->getPatterns($db, $userId);

        // 6. Generate psychoeducational insights (informative, not prescriptive)
        $insights = $this->generateInsights($circumplexPosition, $granularity, $evokedEmotions, $journalEmotions);

        // 7. Build circumplex visualization data
        $circumplexWheel = $this->buildCircumplexWheelData($evokedEmotions, $expressedEmotions, $journalEmotions);

        return [
            'user_id' => $userId,
            'cached_at' => $now,
            'cached_at_formatted' => date('H:i', $now),
            'cache_ttl_seconds' => self::CACHE_TTL,

            // Core emotion data (descriptive)
            'evoked_emotions' => $evokedEmotions,
            'expressed_emotions' => $expressedEmotions,
            'journal_emotions' => $journalEmotions,

            // Scientific models
            'circumplex_position' => $circumplexPosition,  // Russell model
            'granularity' => $granularity,                 // Barrett model

            // Visualizations
            'mood_timeline' => $moodTimeline,
            'patterns' => $patterns,
            'circumplex_wheel' => $circumplexWheel,

            // Educational content
            'insights' => $insights,

            // Meta info
            'scientific_basis' => [
                'circumplex_model' => 'Russell, J. A. (1980). A circumplex model of affect.',
                'granularity' => 'Barrett, L. F. (2017). How Emotions Are Made.',
                'disclaimer' => 'Questa è un\'analisi descrittiva dei pattern emotivi. Non costituisce diagnosi o consiglio medico.',
            ],
        ];
    }

    /**
     * Get evoked emotions (reactions received)
     */
    private function getEvokedEmotions($db, int $userId, int $days): array
    {
        $totalReactions = (int) ($db->findOne(
            "SELECT COUNT(*) as total
             FROM audio_reactions ar
             JOIN audio_posts ap ON ar.audio_post_id = ap.id
             WHERE ap.user_id = ?
               AND ap.deleted_at IS NULL
               AND ar.created_at >= NOW() - INTERVAL '1 day' * ?",
            [$userId, $days],
            ['cache' => false]
        )['total'] ?? 0);

        if ($totalReactions === 0) {
            return $this->emptyEmotionResult();
        }

        $distribution = $db->query(
            "SELECT
                ar.emotion_id,
                COUNT(*) as count,
                ROUND(COUNT(*) * 100.0 / ?, 1) as percentage
             FROM audio_reactions ar
             JOIN audio_posts ap ON ar.audio_post_id = ap.id
             WHERE ap.user_id = ?
               AND ap.deleted_at IS NULL
               AND ar.created_at >= NOW() - INTERVAL '1 day' * ?
             GROUP BY ar.emotion_id
             ORDER BY count DESC",
            [$totalReactions, $userId, $days],
            ['cache' => false]
        );

        return $this->enrichEmotionData($distribution, $totalReactions);
    }

    /**
     * Get expressed emotions (reactions given)
     */
    private function getExpressedEmotions($db, int $userId, int $days): array
    {
        $totalReactions = (int) ($db->findOne(
            "SELECT COUNT(*) as total
             FROM audio_reactions ar
             WHERE ar.user_id = ?
               AND ar.created_at >= NOW() - INTERVAL '1 day' * ?",
            [$userId, $days],
            ['cache' => false]
        )['total'] ?? 0);

        if ($totalReactions === 0) {
            return $this->emptyEmotionResult();
        }

        $distribution = $db->query(
            "SELECT
                ar.emotion_id,
                COUNT(*) as count,
                ROUND(COUNT(*) * 100.0 / ?, 1) as percentage
             FROM audio_reactions ar
             WHERE ar.user_id = ?
               AND ar.created_at >= NOW() - INTERVAL '1 day' * ?
             GROUP BY ar.emotion_id
             ORDER BY count DESC",
            [$totalReactions, $userId, $days],
            ['cache' => false]
        );

        return $this->enrichEmotionData($distribution, $totalReactions);
    }

    /**
     * Get journal emotions (self-reported)
     */
    private function getJournalEmotions($db, int $userId, int $days): array
    {
        $totalEntries = (int) ($db->findOne(
            "SELECT COUNT(*) as total
             FROM emotional_journal_entries ej
             WHERE ej.user_id = ?
               AND ej.deleted_at IS NULL
               AND ej.created_at >= NOW() - INTERVAL '1 day' * ?",
            [$userId, $days],
            ['cache' => false]
        )['total'] ?? 0);

        if ($totalEntries === 0) {
            return $this->emptyEmotionResult();
        }

        $distribution = $db->query(
            "SELECT
                ej.primary_emotion_id as emotion_id,
                COUNT(*) as count,
                ROUND(AVG(ej.intensity), 1) as avg_intensity,
                ROUND(COUNT(*) * 100.0 / ?, 1) as percentage
             FROM emotional_journal_entries ej
             WHERE ej.user_id = ?
               AND ej.deleted_at IS NULL
               AND ej.created_at >= NOW() - INTERVAL '1 day' * ?
             GROUP BY ej.primary_emotion_id
             ORDER BY count DESC",
            [$totalEntries, $userId, $days],
            ['cache' => false]
        );

        $result = $this->enrichEmotionData($distribution, $totalEntries);
        $result['has_intensity'] = true;

        return $result;
    }

    /**
     * Empty result template
     */
    private function emptyEmotionResult(): array
    {
        return [
            'total' => 0,
            'distribution' => [],
            'unique_emotions_count' => 0,
            'predominant' => null,
            'has_intensity' => false,
        ];
    }

    /**
     * Enrich emotion data with circumplex info
     */
    private function enrichEmotionData(array $distribution, int $total): array
    {
        $enriched = [];
        $uniqueEmotions = [];

        foreach ($distribution as $emotion) {
            $emotionId = (int) $emotion['emotion_id'];
            $uniqueEmotions[$emotionId] = true;

            $circumplex = self::EMOTION_CIRCUMPLEX[$emotionId] ?? null;
            $meta = self::EMOTION_NAMES[$emotionId] ?? null;

            $enriched[] = [
                'emotion_id' => $emotionId,
                'name_it' => $meta['it'] ?? 'Unknown',
                'name_en' => $meta['en'] ?? 'Unknown',
                'icon' => $meta['icon'] ?? '❓',
                'color' => $meta['color'] ?? '#888888',
                'count' => (int) $emotion['count'],
                'percentage' => (float) $emotion['percentage'],
                'avg_intensity' => isset($emotion['avg_intensity']) ? (float) $emotion['avg_intensity'] : null,
                'valence' => $circumplex['valence'] ?? 0,
                'arousal' => $circumplex['arousal'] ?? 0,
                'quadrant' => $circumplex['quadrant'] ?? 'unknown',
            ];
        }

        return [
            'total' => $total,
            'distribution' => $enriched,
            'unique_emotions_count' => count($uniqueEmotions),
            'predominant' => $enriched[0] ?? null,
            'has_intensity' => false,
        ];
    }

    /**
     * Calculate position on Russell's Circumplex Model
     *
     * This is the weighted average of valence and arousal
     * based on all emotions recorded.
     */
    private function calculateCircumplexPosition(array $evoked, array $expressed, array $journal): array
    {
        $totalWeight = 0;
        $weightedValence = 0;
        $weightedArousal = 0;

        // Process all sources
        $sources = [
            ['data' => $evoked, 'weight' => 1.0],
            ['data' => $expressed, 'weight' => 0.8],  // Slightly less weight
            ['data' => $journal, 'weight' => 1.2],    // Slightly more weight (self-report)
        ];

        foreach ($sources as $source) {
            foreach ($source['data']['distribution'] ?? [] as $emotion) {
                $count = (int) $emotion['count'];
                $weight = $count * $source['weight'];

                $weightedValence += $emotion['valence'] * $weight;
                $weightedArousal += $emotion['arousal'] * $weight;
                $totalWeight += $weight;
            }
        }

        if ($totalWeight === 0) {
            return [
                'valence' => 0,
                'arousal' => 0,
                'quadrant' => 'neutral',
                'quadrant_info' => null,
                'interpretation' => 'Dati insufficienti per calcolare la posizione.',
            ];
        }

        $avgValence = $weightedValence / $totalWeight;
        $avgArousal = $weightedArousal / $totalWeight;

        // Determine quadrant
        $quadrant = $this->determineQuadrant($avgValence, $avgArousal);
        $quadrantInfo = self::QUADRANT_INFO[$quadrant] ?? null;

        // Generate interpretation (descriptive, not prescriptive)
        $interpretation = $this->generateCircumplexInterpretation($avgValence, $avgArousal, $quadrant);

        return [
            'valence' => round($avgValence, 2),
            'arousal' => round($avgArousal, 2),
            'quadrant' => $quadrant,
            'quadrant_info' => $quadrantInfo,
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Determine quadrant from valence/arousal coordinates
     */
    private function determineQuadrant(float $valence, float $arousal): string
    {
        if ($valence >= 0 && $arousal >= 0) {
            return 'high_valence_high_arousal';
        } elseif ($valence >= 0 && $arousal < 0) {
            return 'high_valence_low_arousal';
        } elseif ($valence < 0 && $arousal >= 0) {
            return 'low_valence_high_arousal';
        } else {
            return 'low_valence_low_arousal';
        }
    }

    /**
     * Generate circumplex interpretation (descriptive)
     */
    private function generateCircumplexInterpretation(float $valence, float $arousal, string $quadrant): string
    {
        $valenceDesc = $valence > 0.3 ? 'prevalentemente piacevoli'
            : ($valence < -0.3 ? 'prevalentemente spiacevoli' : 'miste');

        $arousalDesc = $arousal > 0.3 ? 'ad alta energia'
            : ($arousal < -0.3 ? 'a bassa energia' : 'di energia moderata');

        return "Le tue esperienze emotive recenti sono state {$valenceDesc} e {$arousalDesc}.";
    }

    /**
     * Calculate Emotional Granularity (Barrett)
     *
     * Emotional granularity = ability to make fine-grained distinctions
     * between emotions. Research shows higher granularity correlates
     * with better emotional regulation.
     */
    private function calculateEmotionalGranularity(array $evoked, array $expressed, array $journal): array
    {
        $allEmotionIds = [];

        // Collect all unique emotions from all sources
        foreach ($evoked['distribution'] ?? [] as $e) {
            $allEmotionIds[$e['emotion_id']] = true;
        }
        foreach ($expressed['distribution'] ?? [] as $e) {
            $allEmotionIds[$e['emotion_id']] = true;
        }
        foreach ($journal['distribution'] ?? [] as $e) {
            $allEmotionIds[$e['emotion_id']] = true;
        }

        $uniqueCount = count($allEmotionIds);
        $maxPossible = 10; // We have 10 emotions in the system

        // Calculate granularity score (0-100, based on diversity)
        $granularityScore = round(($uniqueCount / $maxPossible) * 100);

        // Determine level (based on research thresholds)
        if ($uniqueCount >= 7) {
            $level = 'high';
            $levelIt = 'Alta';
            $description = 'Utilizzi un\'ampia gamma di emozioni. La ricerca mostra che le persone con alta granularità emotiva tendono a regolare meglio le proprie emozioni.';
        } elseif ($uniqueCount >= 4) {
            $level = 'moderate';
            $levelIt = 'Moderata';
            $description = 'Utilizzi una varietà moderata di emozioni. Esplorare nuove sfumature emotive può arricchire la consapevolezza di sé.';
        } else {
            $level = 'developing';
            $levelIt = 'In Sviluppo';
            $description = 'Tendi a utilizzare poche etichette emotive. Questo non è "sbagliato", ma espandere il vocabolario emotivo può aiutare a comprendere meglio le proprie esperienze.';
        }

        return [
            'unique_emotions' => $uniqueCount,
            'max_possible' => $maxPossible,
            'score' => $granularityScore,
            'level' => $level,
            'level_it' => $levelIt,
            'description' => $description,
            'scientific_note' => 'La granularità emotiva è la capacità di distinguere tra emozioni simili. Studi mostrano che maggiore granularità è associata a migliore regolazione emotiva (Barrett, 2017).',
        ];
    }

    /**
     * Get mood timeline (descriptive, no value judgments)
     */
    private function getMoodTimeline($db, int $userId, int $days): array
    {
        // Reactions timeline
        $reactionsData = $db->query(
            "SELECT
                DATE(ar.created_at) as date,
                ar.emotion_id,
                COUNT(*) as count
             FROM audio_reactions ar
             JOIN audio_posts ap ON ar.audio_post_id = ap.id
             WHERE ap.user_id = ?
               AND ap.deleted_at IS NULL
               AND ar.created_at >= NOW() - INTERVAL '1 day' * ?
             GROUP BY DATE(ar.created_at), ar.emotion_id
             ORDER BY date ASC",
            [$userId, $days],
            ['cache' => false]
        );

        // Journal timeline
        $journalData = $db->query(
            "SELECT
                DATE(ej.created_at) as date,
                ej.primary_emotion_id as emotion_id,
                COUNT(*) as count,
                AVG(ej.intensity) as avg_intensity
             FROM emotional_journal_entries ej
             WHERE ej.user_id = ?
               AND ej.deleted_at IS NULL
               AND ej.created_at >= NOW() - INTERVAL '1 day' * ?
             GROUP BY DATE(ej.created_at), ej.primary_emotion_id
             ORDER BY date ASC",
            [$userId, $days],
            ['cache' => false]
        );

        // Build timeline with valence/arousal averages per day
        $dailyData = [];

        foreach ($reactionsData as $row) {
            $date = $row['date'];
            if (!isset($dailyData[$date])) {
                $dailyData[$date] = ['valence_sum' => 0, 'arousal_sum' => 0, 'count' => 0];
            }
            $emotionId = (int) $row['emotion_id'];
            $circumplex = self::EMOTION_CIRCUMPLEX[$emotionId] ?? ['valence' => 0, 'arousal' => 0];
            $count = (int) $row['count'];

            $dailyData[$date]['valence_sum'] += $circumplex['valence'] * $count;
            $dailyData[$date]['arousal_sum'] += $circumplex['arousal'] * $count;
            $dailyData[$date]['count'] += $count;
        }

        foreach ($journalData as $row) {
            $date = $row['date'];
            if (!isset($dailyData[$date])) {
                $dailyData[$date] = ['valence_sum' => 0, 'arousal_sum' => 0, 'count' => 0];
            }
            $emotionId = (int) $row['emotion_id'];
            $circumplex = self::EMOTION_CIRCUMPLEX[$emotionId] ?? ['valence' => 0, 'arousal' => 0];
            $count = (int) $row['count'];

            $dailyData[$date]['valence_sum'] += $circumplex['valence'] * $count;
            $dailyData[$date]['arousal_sum'] += $circumplex['arousal'] * $count;
            $dailyData[$date]['count'] += $count;
        }

        // Build timeline array
        $timeline = [];
        $startDate = new \DateTime("-{$days} days");
        $endDate = new \DateTime();

        while ($startDate <= $endDate) {
            $dateStr = $startDate->format('Y-m-d');
            $day = $dailyData[$dateStr] ?? null;

            if ($day && $day['count'] > 0) {
                $avgValence = $day['valence_sum'] / $day['count'];
                $avgArousal = $day['arousal_sum'] / $day['count'];
            } else {
                $avgValence = null;
                $avgArousal = null;
            }

            $timeline[] = [
                'date' => $dateStr,
                'label' => $startDate->format('d/m'),
                'valence' => $avgValence !== null ? round($avgValence, 2) : null,
                'arousal' => $avgArousal !== null ? round($avgArousal, 2) : null,
                'entry_count' => $day['count'] ?? 0,
            ];

            $startDate->modify('+1 day');
        }

        return [
            'days' => $days,
            'timeline' => $timeline,
        ];
    }

    /**
     * Get patterns (month-over-month, descriptive)
     */
    private function getPatterns($db, int $userId): array
    {
        // This month average valence/arousal
        $thisMonth = $this->getMonthAverages($db, $userId, 0);
        $lastMonth = $this->getMonthAverages($db, $userId, 1);

        // Calculate changes (descriptive)
        $valenceChange = null;
        $arousalChange = null;
        $interpretation = null;

        if ($thisMonth['count'] > 0 && $lastMonth['count'] > 0) {
            $valenceChange = round($thisMonth['avg_valence'] - $lastMonth['avg_valence'], 2);
            $arousalChange = round($thisMonth['avg_arousal'] - $lastMonth['avg_arousal'], 2);

            // Descriptive interpretation
            $valenceDesc = $valenceChange > 0.1 ? 'più piacevoli'
                : ($valenceChange < -0.1 ? 'meno piacevoli' : 'simili');
            $arousalDesc = $arousalChange > 0.1 ? 'più attivate'
                : ($arousalChange < -0.1 ? 'meno attivate' : 'simili');

            $interpretation = "Rispetto al mese scorso, le tue esperienze emotive sono state {$valenceDesc} e {$arousalDesc}.";
        }

        return [
            'this_month' => [
                'avg_valence' => $thisMonth['avg_valence'],
                'avg_arousal' => $thisMonth['avg_arousal'],
                'entry_count' => $thisMonth['count'],
            ],
            'last_month' => [
                'avg_valence' => $lastMonth['avg_valence'],
                'avg_arousal' => $lastMonth['avg_arousal'],
                'entry_count' => $lastMonth['count'],
            ],
            'changes' => [
                'valence' => $valenceChange,
                'arousal' => $arousalChange,
            ],
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Get month averages for patterns
     */
    private function getMonthAverages($db, int $userId, int $monthsAgo): array
    {
        $dateStart = $monthsAgo === 0 ? "DATE_TRUNC('month', NOW())"
            : "DATE_TRUNC('month', NOW() - INTERVAL '{$monthsAgo} month')";
        $dateEnd = $monthsAgo === 0 ? "NOW()"
            : "DATE_TRUNC('month', NOW() - INTERVAL '" . ($monthsAgo - 1) . " month')";

        // Get reactions
        $reactions = $db->query(
            "SELECT ar.emotion_id, COUNT(*) as count
             FROM audio_reactions ar
             JOIN audio_posts ap ON ar.audio_post_id = ap.id
             WHERE ap.user_id = ?
               AND ap.deleted_at IS NULL
               AND ar.created_at >= {$dateStart}
               AND ar.created_at < {$dateEnd}
             GROUP BY ar.emotion_id",
            [$userId],
            ['cache' => false]
        );

        // Get journal
        $journal = $db->query(
            "SELECT ej.primary_emotion_id as emotion_id, COUNT(*) as count
             FROM emotional_journal_entries ej
             WHERE ej.user_id = ?
               AND ej.deleted_at IS NULL
               AND ej.created_at >= {$dateStart}
               AND ej.created_at < {$dateEnd}
             GROUP BY ej.primary_emotion_id",
            [$userId],
            ['cache' => false]
        );

        $totalCount = 0;
        $valenceSum = 0;
        $arousalSum = 0;

        foreach ([$reactions, $journal] as $data) {
            foreach ($data as $row) {
                $emotionId = (int) $row['emotion_id'];
                $count = (int) $row['count'];
                $circumplex = self::EMOTION_CIRCUMPLEX[$emotionId] ?? ['valence' => 0, 'arousal' => 0];

                $valenceSum += $circumplex['valence'] * $count;
                $arousalSum += $circumplex['arousal'] * $count;
                $totalCount += $count;
            }
        }

        return [
            'avg_valence' => $totalCount > 0 ? round($valenceSum / $totalCount, 2) : null,
            'avg_arousal' => $totalCount > 0 ? round($arousalSum / $totalCount, 2) : null,
            'count' => $totalCount,
        ];
    }

    /**
     * Generate psychoeducational insights (informative, not prescriptive)
     */
    private function generateInsights(array $circumplex, array $granularity, array $evoked, array $journal): array
    {
        $insights = [];

        // 1. Granularity insight (this IS scientifically validated)
        $insights[] = [
            'type' => 'granularity',
            'icon' => '🎯',
            'title' => 'Consapevolezza Emotiva',
            'message' => "Utilizzi {$granularity['unique_emotions']} emozioni diverse su 10 disponibili. " . $granularity['description'],
            'scientific_basis' => 'Barrett, 2017',
        ];

        // 2. Predominant emotion insight (descriptive)
        $predominant = $evoked['predominant'] ?? $journal['predominant'] ?? null;
        if ($predominant) {
            $insights[] = [
                'type' => 'predominant',
                'icon' => $predominant['icon'],
                'title' => 'Emozione Frequente',
                'message' => "{$predominant['name_it']} è l'emozione che ricorre più spesso nelle tue esperienze recenti. " .
                    $this->getEmotionExplanation($predominant['emotion_id']),
                'scientific_basis' => 'Analisi descrittiva',
            ];
        }

        // 3. Quadrant insight (educational)
        if ($circumplex['quadrant_info']) {
            $insights[] = [
                'type' => 'quadrant',
                'icon' => '📊',
                'title' => $circumplex['quadrant_info']['name_it'],
                'message' => $circumplex['quadrant_info']['description_it'],
                'scientific_basis' => 'Russell Circumplex Model, 1980',
            ];
        }

        // 4. Journal-specific insight if available
        $journalTotal = $journal['total'] ?? 0;
        if ($journalTotal > 0) {
            $insights[] = [
                'type' => 'self_reflection',
                'icon' => '📔',
                'title' => 'Auto-riflessione',
                'message' => "Hai registrato {$journalTotal} riflessioni emotive nel tuo diario. " .
                    "Tenere traccia delle proprie emozioni è una pratica che favorisce la consapevolezza emotiva.",
                'scientific_basis' => 'Pennebaker, 1997 - Writing about emotions',
            ];
        }

        return array_slice($insights, 0, 4);
    }

    /**
     * Get emotion explanation (educational, non-judgmental)
     */
    private function getEmotionExplanation(int $emotionId): string
    {
        $explanations = [
            1 => 'La gioia è un\'emozione che segnala il raggiungimento di qualcosa di positivo.',
            2 => 'L\'entusiasmo indica alta energia e anticipazione positiva.',
            3 => 'L\'amore riflette connessione e attaccamento verso persone o cose importanti.',
            4 => 'La gratitudine emerge quando riconosciamo qualcosa di positivo ricevuto.',
            5 => 'La speranza indica aspettativa positiva per il futuro.',
            6 => 'La tristezza segnala una perdita o un bisogno non soddisfatto. È un\'emozione che invita al riposo e alla riflessione.',
            7 => 'La rabbia indica che un confine è stato violato o che qualcosa blocca un obiettivo. Può motivare l\'azione.',
            8 => 'L\'ansia segnala potenziale pericolo futuro e prepara il corpo a rispondere. In eccesso può essere faticosa.',
            9 => 'La paura è una risposta protettiva che segnala pericolo imminente.',
            10 => 'La solitudine indica un bisogno di connessione sociale non soddisfatto.',
        ];

        return $explanations[$emotionId] ?? '';
    }

    /**
     * Build circumplex wheel visualization data
     */
    private function buildCircumplexWheelData(array $evoked, array $expressed, array $journal): array
    {
        $wheel = [];

        foreach (self::EMOTION_NAMES as $id => $meta) {
            $circumplex = self::EMOTION_CIRCUMPLEX[$id];

            // Count from all sources
            $evokedCount = 0;
            foreach ($evoked['distribution'] ?? [] as $e) {
                if ((int) $e['emotion_id'] === $id) {
                    $evokedCount = (int) $e['count'];
                    break;
                }
            }

            $expressedCount = 0;
            foreach ($expressed['distribution'] ?? [] as $e) {
                if ((int) $e['emotion_id'] === $id) {
                    $expressedCount = (int) $e['count'];
                    break;
                }
            }

            $journalCount = 0;
            foreach ($journal['distribution'] ?? [] as $e) {
                if ((int) $e['emotion_id'] === $id) {
                    $journalCount = (int) $e['count'];
                    break;
                }
            }

            $wheel[] = [
                'emotion_id' => $id,
                'name_it' => $meta['it'],
                'name_en' => $meta['en'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'valence' => $circumplex['valence'],
                'arousal' => $circumplex['arousal'],
                'quadrant' => $circumplex['quadrant'],
                'evoked_count' => $evokedCount,
                'expressed_count' => $expressedCount,
                'journal_count' => $journalCount,
                'total_count' => $evokedCount + $expressedCount + $journalCount,
            ];
        }

        return $wheel;
    }

    /**
     * Invalidate user's analytics cache
     */
    public function invalidateCache(int $userId): bool
    {
        try {
            $redis = EnterpriseRedisManager::getInstance()->getConnection('default');
            $redis->del(self::CACHE_KEY_PREFIX . $userId);

            return true;
        } catch (\Exception $e) {
            Logger::warning('EmotionalAnalytics: Cache invalidation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
