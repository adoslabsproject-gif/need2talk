<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Models\User;
use Need2Talk\Services\Logger;

/**
 * UserSearchController - API per ricerca utenti navbar
 *
 * Endpoint ottimizzato per navbar search con protezioni anti-abuse
 */
class UserSearchController extends BaseController
{
    private User $userModel;

    private Logger $logger;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
        $this->logger = new Logger();
    }

    /**
     * Ricerca utenti per navbar
     * GET /api/users/search?q=term&limit=10
     */
    public function search(): void
    {
        // SECURITY: Verifica autenticazione
        $user = $this->requireAuth();

        $query = trim($_GET['q'] ?? '');
        $limit = max(1, min((int) ($_GET['limit'] ?? 10), 20)); // Max 20 risultati

        // VALIDATION: Query minima
        if (strlen($query) < 2) {
            $this->json(['users' => []]);

            return;
        }

        // SECURITY: Sanitize input
        $query = htmlspecialchars($query, ENT_QUOTES, 'UTF-8');

        try {
            // PERFORMANCE: Query ottimizzata
            $users = $this->performUserSearch($query, $limit);

            $this->json([
                'success' => true,
                'users' => $users,
                'query' => $query,
                'count' => count($users),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Search failed', [
                'query' => $query,
                'user_id' => current_user()['id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json(['error' => 'Search failed'], 500);
        }
    }

    /**
     * Esegui ricerca utenti ottimizzata
     * ENTERPRISE V6.7 (2025-11-30): Return uuid and avatar_url for navbar search
     * ENTERPRISE V4.7 (2025-12-06): SOFT-HIDE BANNED/SUSPENDED USERS
     * - Only shows users with status = 'active' in search results
     * - Suspended/banned users are hidden from search
     */
    private function performUserSearch(string $query, int $limit): array
    {
        $currentUserId = current_user()['id'] ?? 0;

        // ENTERPRISE V4.7: u.status = 'active' filter for soft-hide
        // Previously used wrong column 'account_status', now corrected to 'status'
        $stmt = db_pdo()->prepare("
            SELECT
                u.uuid,
                u.nickname,
                u.birth_year,
                u.location,
                u.avatar_url,
                u.created_at,
                EXTRACT(YEAR FROM CURRENT_DATE) - u.birth_year as age,
                CASE
                    WHEN u.nickname ILIKE ? THEN 1
                    WHEN u.nickname ILIKE ? THEN 2
                    ELSE 3
                END as relevance_score
            FROM users u
            WHERE u.id != ?
              AND u.deleted_at IS NULL
              AND u.status = 'active'
              AND (
                u.nickname ILIKE ? OR
                u.location ILIKE ?
              )
            ORDER BY relevance_score ASC, u.nickname ASC
            LIMIT ?
        ");

        $exactMatch = $query;
        $startsWith = $query . '%';
        $contains = '%' . $query . '%';

        $stmt->execute([
            $exactMatch,
            $startsWith,
            $currentUserId,
            $contains,
            $contains,
            $limit,
        ]);

        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // ENTERPRISE V6.7: Format avatar_url for frontend
        foreach ($users as &$user) {
            $user['avatar_url'] = get_avatar_url($user['avatar_url']);
            $user['profile_url'] = '/u/' . $user['uuid'];
        }

        return $users;
    }
}
