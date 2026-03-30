<?php

declare(strict_types=1);

namespace Need2Talk\Services;

/**
 * EmoFriendly Service - Enterprise Galaxy
 *
 * Gestisce il sistema di suggerimento amicizie basato sulla compatibilità emotiva.
 * Calcola profili emotivi (vettori normalizzati) e trova match affini/complementari
 * usando cosine similarity.
 *
 * ALGORITMO:
 * - Profilo emotivo = 60% reactions date + 40% reactions ricevute
 * - Affini = cosine similarity alta (pattern simili)
 * - Complementari = inverse similarity (pattern opposti)
 *
 * @package Need2Talk\Services
 */
class EmoFriendlyService
{
    private const WEIGHT_GIVEN = 0.6;
    private const WEIGHT_RECEIVED = 0.4;
    private const MIN_REACTIONS = 2;
    private const SUGGESTIONS_PER_TYPE = 15;
    private const SUGGESTION_TTL_HOURS = 24;
    private const MIN_SIMILARITY_SCORE = 0.3;

    /**
     * Aggiorna i profili emotivi per utenti con attività recente
     *
     * @param int $batchSize Numero di utenti per batch
     * @return int Numero di profili aggiornati
     */
    public function updateEmotionProfiles(int $batchSize = 500): int
    {
        $db = db();
        $updated = 0;

        // Trova utenti con reactions negli ultimi 7 giorni che necessitano aggiornamento
        $users = $db->findMany("
            SELECT DISTINCT u.id
            FROM users u
            WHERE u.status = 'active'
              AND u.id IN (
                  -- Utenti che hanno dato reactions recenti
                  SELECT DISTINCT ar.user_id
                  FROM audio_reactions ar
                  WHERE ar.created_at > NOW() - INTERVAL '7 days'
                  UNION
                  -- Utenti che hanno ricevuto reactions recenti
                  SELECT DISTINCT ap.user_id
                  FROM audio_posts ap
                  JOIN audio_reactions ar ON ar.audio_post_id = ap.id
                  WHERE ar.created_at > NOW() - INTERVAL '7 days'
              )
              AND (
                  -- Mai calcolato o calcolato più di 1 ora fa
                  NOT EXISTS (
                      SELECT 1 FROM user_emotion_profiles uep
                      WHERE uep.user_id = u.id
                  )
                  OR u.id IN (
                      SELECT uep.user_id
                      FROM user_emotion_profiles uep
                      WHERE uep.user_id = u.id
                        AND uep.last_calculated_at < NOW() - INTERVAL '1 hour'
                  )
              )
            ORDER BY u.id
            LIMIT :batch_size
        ", ['batch_size' => $batchSize]);

        foreach ($users as $user) {
            try {
                $this->calculateAndSaveProfile((int)$user['id']);
                $updated++;
            } catch (\Exception $e) {
                Logger::error('[EmoFriendly] Profile calculation failed', [
                    'user_id' => $user['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $updated;
    }

    /**
     * Calcola e salva il profilo emotivo per un utente
     */
    private function calculateAndSaveProfile(int $userId): void
    {
        $db = db();

        // Reactions date dall'utente (raggruppate per emotion_id)
        $given = $db->findMany("
            SELECT emotion_id, COUNT(*) as count
            FROM audio_reactions
            WHERE user_id = :user_id
            GROUP BY emotion_id
        ", ['user_id' => $userId]);

        // Reactions ricevute sui propri post
        $received = $db->findMany("
            SELECT ar.emotion_id, COUNT(*) as count
            FROM audio_reactions ar
            JOIN audio_posts ap ON ap.id = ar.audio_post_id
            WHERE ap.user_id = :user_id
            GROUP BY ar.emotion_id
        ", ['user_id' => $userId]);

        // Costruisci vettore emozioni (10 dimensioni)
        $vector = [];
        $totalGiven = 0;
        $totalReceived = 0;

        // Inizializza vettore a 0 per tutte le 10 emozioni
        for ($i = 1; $i <= 10; $i++) {
            $vector[$i] = 0.0;
        }

        // Aggiungi reactions date (peso 60%)
        foreach ($given as $row) {
            $emotionId = (int)$row['emotion_id'];
            $count = (int)$row['count'];
            if ($emotionId >= 1 && $emotionId <= 10) {
                $vector[$emotionId] += $count * self::WEIGHT_GIVEN;
                $totalGiven += $count;
            }
        }

        // Aggiungi reactions ricevute (peso 40%)
        foreach ($received as $row) {
            $emotionId = (int)$row['emotion_id'];
            $count = (int)$row['count'];
            if ($emotionId >= 1 && $emotionId <= 10) {
                $vector[$emotionId] += $count * self::WEIGHT_RECEIVED;
                $totalReceived += $count;
            }
        }

        // Normalizza vettore (somma = 1.0)
        $sum = array_sum($vector);
        if ($sum > 0) {
            foreach ($vector as $key => $value) {
                $vector[$key] = round($value / $sum, 4);
            }
        }

        // Trova emozione dominante
        $dominantEmotion = null;
        $maxValue = 0;
        foreach ($vector as $emotionId => $value) {
            if ($value > $maxValue) {
                $maxValue = $value;
                $dominantEmotion = $emotionId;
            }
        }

        // Calcola sentiment ratio (emozioni positive 1-5 vs negative 6-10)
        $positive = 0;
        $negative = 0;
        for ($i = 1; $i <= 5; $i++) {
            $positive += $vector[$i] ?? 0;
        }
        for ($i = 6; $i <= 10; $i++) {
            $negative += $vector[$i] ?? 0;
        }
        $sentimentRatio = ($positive + $negative) > 0
            ? round($positive / ($positive + $negative), 3)
            : 0.5;

        // Converti vettore in formato JSON-safe (string keys)
        $vectorJson = [];
        foreach ($vector as $k => $v) {
            $vectorJson[(string)$k] = $v;
        }

        // Upsert profilo
        $db->execute("
            INSERT INTO user_emotion_profiles
                (user_id, emotion_vector, total_reactions_given, total_reactions_received,
                 dominant_emotion_id, sentiment_ratio, last_calculated_at, created_at)
            VALUES
                (:user_id, :vector, :given, :received, :dominant, :sentiment, NOW(), NOW())
            ON CONFLICT (user_id) DO UPDATE SET
                emotion_vector = :vector,
                total_reactions_given = :given,
                total_reactions_received = :received,
                dominant_emotion_id = :dominant,
                sentiment_ratio = :sentiment,
                last_calculated_at = NOW()
        ", [
            'user_id' => $userId,
            'vector' => json_encode($vectorJson),
            'given' => $totalGiven,
            'received' => $totalReceived,
            'dominant' => $dominantEmotion,
            'sentiment' => $sentimentRatio,
        ]);
    }

    /**
     * Genera suggerimenti per tutti gli utenti con profilo aggiornato
     *
     * @param int $maxAffine Numero massimo di suggerimenti affini
     * @param int $maxComplementary Numero massimo di suggerimenti complementari
     * @param float $minSimilarity Score minimo per essere incluso
     * @return int Numero di suggerimenti generati
     */
    public function generateSuggestions(
        int $maxAffine = self::SUGGESTIONS_PER_TYPE,
        int $maxComplementary = self::SUGGESTIONS_PER_TYPE,
        float $minSimilarity = self::MIN_SIMILARITY_SCORE
    ): int {
        $db = db();
        $generated = 0;

        // Trova utenti con profili validi (>= MIN_REACTIONS totali)
        $users = $db->findMany("
            SELECT user_id, emotion_vector, sentiment_ratio
            FROM user_emotion_profiles
            WHERE (total_reactions_given + total_reactions_received) >= :min_reactions
        ", ['min_reactions' => self::MIN_REACTIONS]);

        if (count($users) < 2) {
            Logger::info('[EmoFriendly] Not enough users with profiles', [
                'users_count' => count($users),
            ]);
            return 0;
        }

        // Per ogni utente, genera suggerimenti
        foreach ($users as $user) {
            try {
                $count = $this->generateSuggestionsForUser(
                    (int)$user['user_id'],
                    $users,
                    $maxAffine,
                    $maxComplementary,
                    $minSimilarity
                );
                $generated += $count;
            } catch (\Exception $e) {
                Logger::error('[EmoFriendly] Suggestion generation failed', [
                    'user_id' => $user['user_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $generated;
    }

    /**
     * Genera suggerimenti per un singolo utente
     */
    private function generateSuggestionsForUser(
        int $userId,
        array $allProfiles,
        int $maxAffine,
        int $maxComplementary,
        float $minSimilarity
    ): int {
        $db = db();

        // Trova il profilo dell'utente corrente
        $userProfile = null;
        foreach ($allProfiles as $profile) {
            if ((int)$profile['user_id'] === $userId) {
                $userProfile = $profile;
                break;
            }
        }

        if (!$userProfile) {
            return 0;
        }

        $userVector = json_decode($userProfile['emotion_vector'], true) ?: [];

        // Ottieni utenti da escludere
        $excluded = $this->getExcludedUsers($userId);

        // Calcola similarità con tutti gli altri utenti
        $affineScores = [];
        $complementaryScores = [];

        foreach ($allProfiles as $profile) {
            $otherUserId = (int)$profile['user_id'];

            // Salta se stesso o esclusi
            if ($otherUserId === $userId || in_array($otherUserId, $excluded)) {
                continue;
            }

            $otherVector = json_decode($profile['emotion_vector'], true) ?: [];

            // Calcola cosine similarity (affini)
            $affineSim = $this->cosineSimilarity($userVector, $otherVector);
            if ($affineSim >= $minSimilarity) {
                $affineScores[$otherUserId] = $affineSim;
            }

            // Calcola inverse similarity (complementari)
            $complementarySim = $this->inverseSimilarity($userVector, $otherVector);
            if ($complementarySim >= $minSimilarity) {
                $complementaryScores[$otherUserId] = $complementarySim;
            }
        }

        // Ordina per score decrescente
        arsort($affineScores);
        arsort($complementaryScores);

        // Prendi i top N
        $topAffine = array_slice($affineScores, 0, $maxAffine, true);
        $topComplementary = array_slice($complementaryScores, 0, $maxComplementary, true);

        // Elimina suggerimenti vecchi per questo utente
        $db->execute("
            DELETE FROM emotion_suggestions
            WHERE user_id = :user_id
        ", ['user_id' => $userId]);

        // Inserisci nuovi suggerimenti
        $count = 0;
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::SUGGESTION_TTL_HOURS . ' hours'));

        $position = 1;
        foreach ($topAffine as $suggestedId => $score) {
            $db->execute("
                INSERT INTO emotion_suggestions
                    (user_id, suggested_user_id, match_type, similarity_score, ranking_position, calculated_at, expires_at)
                VALUES
                    (:user_id, :suggested_id, 'affine', :score, :position, NOW(), :expires)
                ON CONFLICT (user_id, suggested_user_id, match_type) DO UPDATE SET
                    similarity_score = :score,
                    ranking_position = :position,
                    calculated_at = NOW(),
                    expires_at = :expires
            ", [
                'user_id' => $userId,
                'suggested_id' => $suggestedId,
                'score' => round($score, 4),
                'position' => $position++,
                'expires' => $expiresAt,
            ]);
            $count++;
        }

        $position = 1;
        foreach ($topComplementary as $suggestedId => $score) {
            $db->execute("
                INSERT INTO emotion_suggestions
                    (user_id, suggested_user_id, match_type, similarity_score, ranking_position, calculated_at, expires_at)
                VALUES
                    (:user_id, :suggested_id, 'complementary', :score, :position, NOW(), :expires)
                ON CONFLICT (user_id, suggested_user_id, match_type) DO UPDATE SET
                    similarity_score = :score,
                    ranking_position = :position,
                    calculated_at = NOW(),
                    expires_at = :expires
            ", [
                'user_id' => $userId,
                'suggested_id' => $suggestedId,
                'score' => round($score, 4),
                'position' => $position++,
                'expires' => $expiresAt,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Ottiene la lista di utenti da escludere dai suggerimenti
     */
    private function getExcludedUsers(int $userId): array
    {
        $db = db();
        $excluded = [];

        // Amici esistenti
        $friends = $db->findMany("
            SELECT friend_id FROM friendships
            WHERE user_id = :user_id AND status = 'accepted'
            UNION
            SELECT user_id FROM friendships
            WHERE friend_id = :user_id AND status = 'accepted'
        ", ['user_id' => $userId]);

        foreach ($friends as $row) {
            $excluded[] = (int)($row['friend_id'] ?? $row['user_id']);
        }

        // Richieste pendenti
        $pending = $db->findMany("
            SELECT friend_id FROM friendships
            WHERE user_id = :user_id AND status = 'pending'
            UNION
            SELECT user_id FROM friendships
            WHERE friend_id = :user_id AND status = 'pending'
        ", ['user_id' => $userId]);

        foreach ($pending as $row) {
            $excluded[] = (int)($row['friend_id'] ?? $row['user_id']);
        }

        // Utenti bloccati (in entrambe le direzioni)
        $blocked = $db->findMany("
            SELECT blocked_id FROM user_blocks WHERE blocker_id = :user_id
            UNION
            SELECT blocker_id FROM user_blocks WHERE blocked_id = :user_id
        ", ['user_id' => $userId]);

        foreach ($blocked as $row) {
            $excluded[] = (int)($row['blocked_id'] ?? $row['blocker_id']);
        }

        // Utenti già rimossi/dismissati
        $dismissed = $db->findMany("
            SELECT dismissed_user_id FROM emotion_suggestion_dismissals
            WHERE user_id = :user_id
        ", ['user_id' => $userId]);

        foreach ($dismissed as $row) {
            $excluded[] = (int)$row['dismissed_user_id'];
        }

        // Utenti inattivi (>90 giorni)
        $inactive = $db->findMany("
            SELECT id FROM users
            WHERE (last_activity IS NULL OR last_activity < NOW() - INTERVAL '90 days')
              AND status = 'active'
        ");

        foreach ($inactive as $row) {
            $excluded[] = (int)$row['id'];
        }

        return array_unique($excluded);
    }

    /**
     * Calcola cosine similarity tra due vettori
     */
    private function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 1; $i <= 10; $i++) {
            $key = (string)$i;
            $a = (float)($vectorA[$key] ?? $vectorA[$i] ?? 0.0);
            $b = (float)($vectorB[$key] ?? $vectorB[$i] ?? 0.0);

            $dotProduct += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Calcola inverse similarity (per utenti complementari)
     * Utenti con pattern opposti ma "bilancianti"
     */
    private function inverseSimilarity(array $vectorA, array $vectorB): float
    {
        // Inverti il vettore B
        $invertedB = [];
        $maxVal = max(array_values($vectorB) ?: [0]);

        if ($maxVal == 0) {
            return 0.0;
        }

        for ($i = 1; $i <= 10; $i++) {
            $key = (string)$i;
            $val = (float)($vectorB[$key] ?? $vectorB[$i] ?? 0.0);
            // Inverti: alto diventa basso, basso diventa alto
            $invertedB[$key] = $maxVal - $val;
        }

        // Normalizza il vettore invertito
        $sum = array_sum($invertedB);
        if ($sum > 0) {
            foreach ($invertedB as $key => $value) {
                $invertedB[$key] = $value / $sum;
            }
        }

        // Calcola cosine similarity con vettore invertito
        return $this->cosineSimilarity($vectorA, $invertedB);
    }

    /**
     * Ottiene i suggerimenti per un utente (API)
     *
     * @param int $userId ID dell'utente
     * @return array ['affine' => [...], 'complementary' => [...]]
     */
    public function getSuggestionsForUser(int $userId): array
    {
        $db = db();

        // Ottieni suggerimenti affini
        $affine = $db->findMany("
            SELECT
                es.suggested_user_id,
                es.similarity_score,
                es.ranking_position,
                u.nickname,
                u.uuid,
                u.avatar_url,
                uep.emotion_vector,
                uep.dominant_emotion_id
            FROM emotion_suggestions es
            JOIN users u ON u.id = es.suggested_user_id
            LEFT JOIN user_emotion_profiles uep ON uep.user_id = es.suggested_user_id
            WHERE es.user_id = :user_id
              AND es.match_type = 'affine'
              AND es.expires_at > NOW()
              AND u.status = 'active'
            ORDER BY es.ranking_position ASC
            LIMIT :limit
        ", ['user_id' => $userId, 'limit' => self::SUGGESTIONS_PER_TYPE]);

        // Ottieni suggerimenti complementari
        $complementary = $db->findMany("
            SELECT
                es.suggested_user_id,
                es.similarity_score,
                es.ranking_position,
                u.nickname,
                u.uuid,
                u.avatar_url,
                uep.emotion_vector,
                uep.dominant_emotion_id
            FROM emotion_suggestions es
            JOIN users u ON u.id = es.suggested_user_id
            LEFT JOIN user_emotion_profiles uep ON uep.user_id = es.suggested_user_id
            WHERE es.user_id = :user_id
              AND es.match_type = 'complementary'
              AND es.expires_at > NOW()
              AND u.status = 'active'
            ORDER BY es.ranking_position ASC
            LIMIT :limit
        ", ['user_id' => $userId, 'limit' => self::SUGGESTIONS_PER_TYPE]);

        // Arricchisci con dati emozioni
        $emotions = $this->getEmotionsMap();

        $formatUser = function ($row) use ($emotions) {
            $topEmotions = [];
            if (!empty($row['emotion_vector'])) {
                $vector = json_decode($row['emotion_vector'], true) ?: [];
                arsort($vector);
                $count = 0;
                foreach ($vector as $emotionId => $score) {
                    if ($count >= 3) break;
                    if (isset($emotions[$emotionId])) {
                        $topEmotions[] = [
                            'id' => (int)$emotionId,
                            'name' => $emotions[$emotionId]['name_it'],
                            'icon' => $emotions[$emotionId]['icon_emoji'],
                        ];
                        $count++;
                    }
                }
            }

            return [
                'uuid' => $row['uuid'],
                'nickname' => $row['nickname'],
                'avatar_url' => get_avatar_url($row['avatar_url']),
                'similarity_score' => (float)$row['similarity_score'],
                'top_emotions' => $topEmotions,
            ];
        };

        return [
            'affine' => array_map($formatUser, $affine),
            'complementary' => array_map($formatUser, $complementary),
        ];
    }

    /**
     * Ottiene la mappa delle emozioni
     */
    private function getEmotionsMap(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $db = db();
        $emotions = $db->findMany("
            SELECT id, name_it, name_en, icon_emoji
            FROM emotions
            WHERE is_active = true
        ");

        $cache = [];
        foreach ($emotions as $e) {
            $cache[$e['id']] = $e;
        }

        return $cache;
    }

    /**
     * Rimuove un suggerimento per un utente
     *
     * @param int $userId ID dell'utente che rimuove
     * @param int $dismissedUserId ID dell'utente da rimuovere
     * @param string $type 'remove' o 'block'
     * @return bool
     */
    public function dismissSuggestion(int $userId, int $dismissedUserId, string $type = 'remove'): bool
    {
        $db = db();

        if (!in_array($type, ['remove', 'block'])) {
            $type = 'remove';
        }

        // Salva dismissal
        $db->execute("
            INSERT INTO emotion_suggestion_dismissals
                (user_id, dismissed_user_id, dismissal_type, created_at)
            VALUES
                (:user_id, :dismissed_id, :type, NOW())
            ON CONFLICT (user_id, dismissed_user_id) DO UPDATE SET
                dismissal_type = :type,
                created_at = NOW()
        ", [
            'user_id' => $userId,
            'dismissed_id' => $dismissedUserId,
            'type' => $type,
        ]);

        // Rimuovi dai suggerimenti correnti
        $db->execute("
            DELETE FROM emotion_suggestions
            WHERE user_id = :user_id AND suggested_user_id = :dismissed_id
        ", [
            'user_id' => $userId,
            'dismissed_id' => $dismissedUserId,
        ]);

        Logger::info('[EmoFriendly] Suggestion dismissed', [
            'user_id' => $userId,
            'dismissed_user_id' => $dismissedUserId,
            'type' => $type,
        ]);

        return true;
    }

    /**
     * Pulisce i suggerimenti scaduti
     *
     * @return int Numero di suggerimenti eliminati
     */
    public function cleanupExpiredSuggestions(): int
    {
        $db = db();

        $result = $db->execute("
            DELETE FROM emotion_suggestions
            WHERE expires_at < NOW()
        ");

        return $result;
    }

    /**
     * Esegue il calcolo completo (per cron job)
     *
     * @return array Statistiche dell'esecuzione
     */
    public function runFullCalculation(): array
    {
        $startTime = microtime(true);

        // Step 1: Aggiorna profili emotivi
        $profilesUpdated = $this->updateEmotionProfiles(500);

        // Step 2: Genera suggerimenti
        $suggestionsGenerated = $this->generateSuggestions(
            self::SUGGESTIONS_PER_TYPE,
            self::SUGGESTIONS_PER_TYPE,
            self::MIN_SIMILARITY_SCORE
        );

        // Step 3: Cleanup scaduti
        $expired = $this->cleanupExpiredSuggestions();

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $stats = [
            'profiles_updated' => $profilesUpdated,
            'suggestions_generated' => $suggestionsGenerated,
            'expired_cleaned' => $expired,
            'duration_ms' => $duration,
        ];

        Logger::info('[EmoFriendly] Full calculation completed', $stats);

        return $stats;
    }
}
