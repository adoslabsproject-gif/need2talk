<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\ReactionStatsService;

/**
 * Profile API Controller
 *
 * Gestisce le operazioni API per profili utente
 */
class ProfileApiController extends BaseController
{
    /**
     * Ottieni statistiche utente
     *
     * ENTERPRISE GALAXY (2025-12-10): Real stats from database
     * Used by PostLoginApp.js for feed sidebar stats display
     *
     * Returns:
     * - posts_count: Number of audio posts by user
     * - likes_received: Total reactions received on user's posts
     * - total_plays: Total play count on user's posts (approximated from reactions)
     */
    public function stats(): void
    {
        $user = $this->requireAuth();
        $userId = (int) $user['id'];

        $db = db();

        // ENTERPRISE: Count posts using idx_user_posts covering index
        $postsCount = $db->findOne('
            SELECT COUNT(*) as count
            FROM audio_posts
            WHERE user_id = ? AND deleted_at IS NULL
        ', [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ])['count'] ?? 0;

        // ENTERPRISE: Count reactions received (likes/emotions on user's posts)
        $likesReceived = $db->findOne('
            SELECT COUNT(*) as count
            FROM audio_reactions ar
            JOIN audio_posts ap ON ar.audio_post_id = ap.id
            WHERE ap.user_id = ? AND ap.deleted_at IS NULL
        ', [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ])['count'] ?? 0;

        // ENTERPRISE: Total plays on user's posts (sum of play_count from audio_files)
        $totalPlays = $db->findOne('
            SELECT COALESCE(SUM(af.play_count), 0) as count
            FROM audio_files af
            JOIN audio_posts ap ON ap.audio_file_id = af.id
            WHERE ap.user_id = ? AND ap.deleted_at IS NULL
        ', [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ])['count'] ?? 0;

        // Return stats in format expected by PostLoginApp.js
        $this->json([
            'success' => true,
            'stats' => [
                'posts_count' => (int) $postsCount,
                'likes_received' => (int) $likesReceived,
                'total_plays' => (int) $totalPlays,
            ],
        ]);
    }

    /**
     * Ottieni attività utente recente
     */
    public function activity(): void
    {
        $user = $this->requireAuth();

        // TODO: Recuperare attività reali da database
        $this->json([
            'success' => true,
            'data' => [
                'recent_activities' => [],
                'last_login' => $user['last_login'] ?? null,
            ],
        ]);
    }

    /**
     * Aggiorna impostazioni profilo
     */
    public function updateSettings(): void
    {
        $user = $this->requireAuth();
        $input = $this->getJsonInput();

        // TODO: Implementare aggiornamento impostazioni
        $this->json([
            'success' => false,
            'error' => 'Settings update not yet implemented',
        ], 501);
    }

    /**
     * Ottieni profilo utente corrente
     *
     * ENTERPRISE SECURITY: Only expose safe public fields
     * NEVER expose: password_hash, email, IPs, tokens, internal IDs
     */
    public function show(): void
    {
        $user = $this->requireAuth();

        // =========================================================================
        // ENTERPRISE SECURITY: Sanitize user data - expose ONLY safe public fields
        // CRITICAL: $user contains password_hash, email, IPs, tokens - NEVER expose!
        // =========================================================================
        $safeUserData = [
            'uuid' => $user['uuid'],
            'nickname' => $user['nickname'],
            'avatar_url' => get_avatar_url($user['avatar_url'] ?? null),
            'birth_year' => $user['birth_year'] ?? null,
            'gender' => $user['gender'] ?? null,
            'status' => $user['status'] ?? null,
            'created_at' => $user['created_at'] ?? null,
        ];

        $this->json([
            'success' => true,
            'data' => [
                'user' => $safeUserData,
                'stats' => [
                    'total_audio' => 0,
                    'total_listens' => 0,
                    'followers' => 0,
                    'following' => 0,
                ],
            ],
        ]);
    }

    /**
     * Aggiorna profilo utente
     */
    public function update(): void
    {
        $user = $this->requireAuth();
        $input = $this->getJsonInput();

        // TODO: Implementare aggiornamento profilo
        $this->json([
            'success' => false,
            'error' => 'Profile update not yet implemented',
        ], 501);
    }

    /**
     * Upload avatar utente
     */
    public function uploadAvatar(): void
    {
        $user = $this->requireAuth();

        // TODO: Implementare upload avatar
        $this->json([
            'success' => false,
            'error' => 'Avatar upload not yet implemented',
        ], 501);
    }

    /**
     * Cambia password utente
     */
    public function changePassword(): void
    {
        $user = $this->requireAuth();
        $input = $this->getJsonInput();

        // TODO: Implementare cambio password
        $this->json([
            'success' => false,
            'error' => 'Password change not yet implemented',
        ], 501);
    }

    /**
     * Get Evoked Emotions Analytics
     *
     * Returns emotions that user's posts evoke in other users
     * Useful for psychological profile dashboard
     *
     * GET /api/profile/evoked-emotions?days=30
     *
     * @return void JSON response
     */
    public function evokedEmotions(): void
    {
        try {
            $user = $this->requireAuth();
            $userId = $user['id'];

            // Get days parameter (default 30)
            $days = isset($_GET['days']) ? min(365, max(1, (int)$_GET['days'])) : 30;

            // Get evoked emotions analytics
            $reactionStats = new ReactionStatsService();
            $emotions = $reactionStats->getUserEvokedEmotions($userId, $days);

            $this->json([
                'success' => true,
                'evoked_emotions' => $emotions,
                'period_days' => $days,
            ]);

        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore durante il caricamento delle emozioni evocate',
            ], 500);
        }
    }

    /**
     * Elimina account utente
     */
    public function deleteAccount(): void
    {
        $user = $this->requireAuth();

        // TODO: Implementare eliminazione account
        $this->json([
            'success' => false,
            'error' => 'Account deletion not yet implemented',
        ], 501);
    }
}
