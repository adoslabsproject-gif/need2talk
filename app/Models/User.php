<?php

namespace Need2Talk\Models;

use Need2Talk\Core\BaseModel;
use Need2Talk\Core\EnterpriseGlobalsManager;
use Need2Talk\Services\Cache\UserSettingsOverlayService;
use Need2Talk\Services\Logger;

/**
 * User Model - Sistema utenti unificato need2talk
 *
 * Gestisce autenticazione, profili e statistiche utente
 * Sistema unificato secondo specifiche need2talk.md
 */
class User extends BaseModel
{
    protected string $table = 'users';

    protected bool $usesSoftDeletes = true;

    /**
     * Trova utente per ID
     *
     * ENTERPRISE V4: Applies overlay for immediate visibility of recent changes
     */
    public function findById(int $id): ?array
    {
        // Get user from DB/cache
        $user = $this->find($id);

        if (!$user) {
            return null;
        }

        // ENTERPRISE V4: Apply overlay for avatar and profile changes
        return $this->applyUserOverlay($user);
    }

    /**
     * Trova utente per email
     *
     * ENTERPRISE V4: Applies overlay
     * NOTE: Uses cache - for auth-critical queries use findByEmailForAuth()
     */
    public function findByEmail(string $email): ?array
    {
        $user = $this->findBy(['email' => $email], 1)[0] ?? null;
        return $user ? $this->applyUserOverlay($user) : null;
    }

    /**
     * Trova utente per email - AUTH CRITICAL (no cache)
     *
     * ENTERPRISE GALAXY V6.6: Bypasses cache for authentication decisions
     * Use this method when checking user status for login/access control
     * to ensure real-time status enforcement (bans/suspensions)
     *
     * @param string $email User email
     * @return array|null User data or null if not found
     */
    public function findByEmailForAuth(string $email): ?array
    {
        // CRITICAL: Bypass cache to get real-time user status
        $user = $this->findBy(['email' => $email], 1, ['cache' => false])[0] ?? null;
        return $user ? $this->applyUserOverlay($user) : null;
    }

    /**
     * Trova utente per nickname
     *
     * ENTERPRISE V4: Applies overlay
     */
    public function findByNickname(string $nickname): ?array
    {
        $user = $this->findBy(['nickname' => $nickname], 1)[0] ?? null;
        return $user ? $this->applyUserOverlay($user) : null;
    }

    /**
     * Trova utente per UUID
     *
     * ENTERPRISE V4: Applies overlay
     */
    public function findByUuid(string $uuid): ?array
    {
        $user = $this->findBy(['uuid' => $uuid], 1)[0] ?? null;
        return $user ? $this->applyUserOverlay($user) : null;
    }

    /**
     * Verifica esistenza email
     *
     * ENTERPRISE V4.7: Only checks ACTIVE users (deleted_at IS NULL)
     * Soft-deleted users' emails are available for re-registration
     */
    public function emailExists(string $email, ?int $excludeUserId = null): bool
    {
        $params = [$email];
        $whereClause = 'email = ? AND deleted_at IS NULL';

        if ($excludeUserId) {
            $whereClause .= ' AND id != ?';
            $params[] = $excludeUserId;
        }

        $result = $this->db()->findOne("SELECT COUNT(*) as count FROM {$this->table} WHERE {$whereClause}", $params, [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);

        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Verifica esistenza nickname
     *
     * ENTERPRISE V4.7: Only checks ACTIVE users (deleted_at IS NULL)
     * Soft-deleted users' nicknames are available for re-registration
     */
    public function nicknameExists(string $nickname, ?int $excludeUserId = null): bool
    {
        $params = [$nickname];
        $whereClause = 'nickname = ? AND deleted_at IS NULL';

        if ($excludeUserId) {
            $whereClause .= ' AND id != ?';
            $params[] = $excludeUserId;
        }

        $result = $this->db()->findOne("SELECT COUNT(*) as count FROM {$this->table} WHERE {$whereClause}", $params, [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);

        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Crea utente con profilo (transazione)
     */
    public function createUserWithProfile(array $userData, array $profileData = []): int
    {
        $this->db()->beginTransaction();

        try {
            // Genera UUID univoco
            $userData['uuid'] = $this->generateUuid();
            $userData['created_at'] = date('Y-m-d H:i:s');

            // ENTERPRISE GALAXY: Generate newsletter unsubscribe token
            if (!isset($userData['newsletter_unsubscribe_token'])) {
                $userData['newsletter_unsubscribe_token'] = $this->generateNewsletterToken();
            }

            // ENTERPRISE GALAXY: Default newsletter opt-in (can be overridden)
            if (!isset($userData['newsletter_opt_in'])) {
                $userData['newsletter_opt_in'] = 1; // Default: opted in
                $userData['newsletter_opt_in_at'] = date('Y-m-d H:i:s');
            }

            // Crea utente
            $userId = $this->create($userData);

            // Crea profilo utente se fornito
            if (!empty($profileData)) {
                $profileModel = new UserProfile();
                $profileModel->create(array_merge([
                    'user_id' => $userId,
                    'created_at' => date('Y-m-d H:i:s'),
                ], $profileData));
            }

            $this->db()->commit();

            return $userId;

        } catch (\Exception $e) {
            $this->db()->rollBack();

            throw $e;
        }
    }

    /**
     * Ottieni dettagli utente completi
     */
    public function getUserProfile(int $userId): ?array
    {
        return $this->db()->findOne("
            SELECT u.*, up.bio, up.location, up.website, up.avatar,
                   COUNT(DISTINCT a.id) as total_audios,
                   COUNT(DISTINCT l.id) as total_likes_received,
                   COUNT(DISTINCT c.id) as total_comments_received
            FROM {$this->table} u
            LEFT JOIN user_profiles up ON u.id = up.user_id
            LEFT JOIN audio_files a ON u.id = a.user_id AND a.deleted_at IS NULL
            LEFT JOIN likes l ON a.id = l.audio_id
            LEFT JOIN comments c ON a.id = c.audio_id AND c.deleted_at IS NULL
            WHERE u.id = ? AND u.deleted_at IS NULL
            GROUP BY u.id
        ", [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * Calcola età da birth_year e birth_month
     */
    public function calculateAge(int $birthYear, int $birthMonth): int
    {
        $today = new \DateTime();
        $birthDate = new \DateTime("{$birthYear}-{$birthMonth}-01");

        return $today->diff($birthDate)->y;
    }

    /**
     * Ottieni statistiche utente
     *
     * ENTERPRISE: Migrated to audio_posts + audio_reactions system
     * - Uses audio_posts (not audio_files)
     * - Reactions counted from audio_reactions table
     * - Covering indexes for optimal performance
     */
    public function getUserStats(int $userId): array
    {
        return $this->db()->findOne('
            SELECT
                (SELECT COUNT(*) FROM audio_posts WHERE user_id = ? AND deleted_at IS NULL) as total_audios,
                (SELECT COUNT(*) FROM audio_reactions ar
                 JOIN audio_posts ap ON ar.audio_post_id = ap.id
                 WHERE ap.user_id = ? AND ap.deleted_at IS NULL) as reactions_received,
                (SELECT SUM(play_count) FROM audio_files WHERE user_id = ? AND deleted_at IS NULL) as total_plays,
                (SELECT COUNT(*) FROM audio_comments WHERE user_id = ? AND status = \'active\') as total_comments
        ', [$userId, $userId, $userId, $userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]) ?: [];
    }

    /**
     * Sistema autenticazione - incrementa tentativi falliti
     */
    public function incrementFailedAttempts(int $userId): void
    {
        $this->db()->execute("
            UPDATE {$this->table}
            SET failed_login_attempts = failed_login_attempts + 1,
                locked_until = CASE
                    WHEN failed_login_attempts >= 4 THEN NOW() + INTERVAL '30 minutes'
                    ELSE locked_until
                END
            WHERE id = ?
        ", [$userId], [
            'invalidate_cache' => ["user:{$userId}"],
        ]);
    }

    /**
     * Sistema autenticazione - reset tentativi dopo login riuscito
     */
    public function resetFailedAttempts(int $userId): void
    {
        $this->db()->execute("
            UPDATE {$this->table}
            SET failed_login_attempts = 0,
                locked_until = NULL,
                last_login_at = NOW(),
                login_count = login_count + 1,
                last_ip = ?
            WHERE id = ?
        ", [EnterpriseGlobalsManager::getServer('REMOTE_ADDR', ''), $userId], [
            'invalidate_cache' => ["user:{$userId}"],
        ]);
    }

    /**
     * Verifica se account è bloccato
     */
    public function isAccountLocked(int $userId): bool
    {
        $result = $this->db()->findOne("
            SELECT locked_until FROM {$this->table}
            WHERE id = ? AND locked_until > NOW()
        ", [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
            'skip_l2' => true,  // CRITICAL: Skip Memcached per fresh data (account lock check)
        ]);

        return $result !== null;
    }

    /**
     * Aggiorna last activity per session management
     */
    public function updateLastActivity(int $userId): void
    {
        $this->db()->execute("
            UPDATE {$this->table}
            SET last_activity = NOW()
            WHERE id = ?
        ", [$userId]);
    }

    /**
     * Verifica email confermata
     */
    public function isEmailVerified(int $userId): bool
    {
        $result = $this->db()->findOne("
            SELECT email_verified FROM {$this->table} WHERE id = ?
        ", [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
            'skip_l2' => true,  // CRITICAL: Skip Memcached per fresh data (email verification check)
        ]);

        return (bool) ($result['email_verified'] ?? false);
    }

    /**
     * Conferma email
     */
    public function verifyEmail(int $userId): bool
    {
        return $this->db()->execute("
            UPDATE {$this->table}
            SET email_verified = TRUE, email_verified_at = NOW()
            WHERE id = ?
        ", [$userId]);
    }

    /**
     * Ottieni utenti attivi recenti (per admin)
     */
    public function getActiveUsers(int $limit = 50): array
    {
        return $this->db()->query("
            SELECT u.*, up.avatar,
                   COUNT(DISTINCT a.id) as recent_audios
            FROM {$this->table} u
            LEFT JOIN user_profiles up ON u.id = up.user_id
            LEFT JOIN audio_files a ON u.id = a.user_id
                AND a.created_at >= NOW() - INTERVAL '30 days'
                AND a.deleted_at IS NULL
            WHERE u.deleted_at IS NULL
                AND u.last_activity >= NOW() - INTERVAL '30 days'
            GROUP BY u.id
            ORDER BY u.last_activity DESC
            LIMIT ?
        ", [$limit], [
            'cache' => true,
            'cache_ttl' => 'medium',
        ]);
    }

    /**
     * Count total users - for Filament widgets
     */
    public static function count(): int
    {
        $instance = new static();
        $result = $instance->db()->findOne('SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL', [], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get latest users - for Filament widgets
     */
    public static function latest(string $column = 'created_at'): array
    {
        $instance = new static();

        return $instance->db()->query("SELECT * FROM users WHERE deleted_at IS NULL ORDER BY {$column} DESC", [], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * Get latest users with limit - for Filament widgets
     */
    public static function latestWithLimit(int $limit = 5): array
    {
        $instance = new static();

        return $instance->db()->query('SELECT * FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT ?', [$limit], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * Two-Factor Authentication - Check if enabled
     */
    public function hasTwoFactorAuthentication(): bool
    {
        $result = $this->db()->findOne("SELECT two_factor_secret FROM users WHERE id = ? AND two_factor_secret IS NOT NULL AND two_factor_secret != ''", [auth()->id()], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);

        return (bool) $result;
    }

    /**
     * Two-Factor Authentication - Enable
     */
    public function enableTwoFactorAuthentication(string $secret): bool
    {
        return $this->db()->execute('UPDATE users SET two_factor_secret = ?, two_factor_enabled = TRUE WHERE id = ?', [$secret, auth()->id()]);
    }

    /**
     * Two-Factor Authentication - Disable
     */
    public function disableTwoFactorAuthentication(): bool
    {
        return $this->db()->execute('UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0, recovery_codes = NULL WHERE id = ?', [auth()->id()]);
    }

    /**
     * Two-Factor Authentication - Generate recovery codes
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < 10; $i++) {
            $codes[] = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        }

        $this->db()->execute('UPDATE users SET recovery_codes = ? WHERE id = ?', [json_encode($codes), auth()->id()]);

        return $codes;
    }

    /**
     * Two-Factor Authentication - Verify code
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        // Simulated verification - in production use Google2FA
        return strlen($code) === 6 && ctype_digit($code);
    }

    /**
     * ENTERPRISE GALAXY: Newsletter Opt-In
     *
     * V4: Uses overlay for immediate visibility
     */
    public function optInNewsletter(int $userId): bool
    {
        $success = $this->db()->execute("
            UPDATE {$this->table}
            SET newsletter_opt_in = TRUE,
                newsletter_opt_in_at = NOW(),
                newsletter_opt_out_at = NULL,
                updated_at = NOW()
            WHERE id = ?
        ", [$userId]);

        if ($success) {
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setProfilePatch($userId, ['newsletter_opt_in' => true]);
            }
        }

        return $success;
    }

    /**
     * ENTERPRISE GALAXY: Newsletter Opt-Out
     *
     * V4: Uses overlay for immediate visibility
     */
    public function optOutNewsletter(int $userId, ?string $reason = null): bool
    {
        $result = $this->db()->execute("
            UPDATE {$this->table}
            SET newsletter_opt_in = 0,
                newsletter_opt_out_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ", [$userId]);

        if ($result) {
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setProfilePatch($userId, ['newsletter_opt_in' => false]);
            }
        }

        // Log reason if provided
        if ($result && $reason) {
            $user = $this->findById($userId);
            if ($user) {
                $this->db()->execute("
                    INSERT INTO newsletter_unsubscribe_log (user_id, email, reason, unsubscribed_at)
                    VALUES (?, ?, ?, NOW())
                ", [$userId, $user['email'], $reason]);
            }
        }

        return $result;
    }

    /**
     * ENTERPRISE GALAXY: Check if user is opted in to newsletter
     */
    public function isNewsletterOptedIn(int $userId): bool
    {
        $result = $this->db()->findOne("
            SELECT newsletter_opt_in FROM {$this->table} WHERE id = ?
        ", [$userId], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);

        return (bool) ($result['newsletter_opt_in'] ?? false);
    }

    /**
     * ENTERPRISE GALAXY: Get newsletter subscribers
     * Returns users who are opted in and verified
     */
    public function getNewsletterSubscribers(array $filters = []): array
    {
        $where = [
            'deleted_at IS NULL',
            'newsletter_opt_in = TRUE',
            'email_verified = TRUE',
        ];
        $params = [];

        // Apply optional filters
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['verified_only'])) {
            $where[] = 'email_verified = TRUE';
        }

        $whereClause = implode(' AND ', $where);

        return $this->db()->query("
            SELECT id, email, nickname, newsletter_unsubscribe_token
            FROM {$this->table}
            WHERE {$whereClause}
            ORDER BY id ASC
        ", $params, [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * ENTERPRISE GALAXY: Get newsletter subscriber count
     */
    public function getNewsletterSubscriberCount(): int
    {
        $result = $this->db()->findOne("
            SELECT COUNT(*) as count
            FROM {$this->table}
            WHERE deleted_at IS NULL
              AND newsletter_opt_in = TRUE
              AND email_verified = TRUE
        ", [], [
            'cache' => true,
            'cache_ttl' => 'medium',
        ]);

        return (int) ($result['count'] ?? 0);
    }

    /**
     * ENTERPRISE GALAXY: Find user by newsletter token
     */
    public function findByNewsletterToken(string $token): ?array
    {
        return $this->db()->findOne("
            SELECT id, email, nickname, newsletter_opt_in
            FROM {$this->table}
            WHERE newsletter_unsubscribe_token = ?
              AND deleted_at IS NULL
        ", [$token], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }

    /**
     * ENTERPRISE GALAXY: Generate newsletter unsubscribe token (64-char hex)
     */
    private function generateNewsletterToken(): string
    {
        do {
            // Generate 64 character hexadecimal token (32 bytes)
            $token = bin2hex(random_bytes(32));

            // Check uniqueness
            $exists = $this->db()->findOne("
                SELECT id FROM {$this->table}
                WHERE newsletter_unsubscribe_token = ?
            ", [$token]);
        } while ($exists);

        return $token;
    }

    /**
     * Genera UUID univoco per utente
     */
    private function generateUuid(): string
    {
        do {
            // Generate standard UUID v4 format: 8-4-4-4-12
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0F | 0x40); // version 4
            $data[8] = chr(ord($data[8]) & 0x3F | 0x80); // variant bits

            $uuid = sprintf(
                '%08s-%04s-%04s-%04s-%12s',
                bin2hex(substr($data, 0, 4)),
                bin2hex(substr($data, 4, 2)),
                bin2hex(substr($data, 6, 2)),
                bin2hex(substr($data, 8, 2)),
                bin2hex(substr($data, 10, 6))
            );
        } while ($this->findByUuid($uuid));

        return $uuid;
    }

    // ========================================================================
    // ENTERPRISE SETTINGS SYSTEM - Avatar, Nickname, Email, GDPR
    // ========================================================================

    /**
     * Check if user can change nickname
     *
     * ENTERPRISE POLICY (UPDATED 2025-11-15):
     * - ALL users (OAuth and local): max 1 cambio lifetime
     * - Anti-abuse measure for security and traceability
     * - Prevents nickname squatting and impersonation
     *
     * @param int $userId User ID
     * @return bool True if can change
     */
    public function canChangeNickname(int $userId): bool
    {
        $user = $this->findById($userId);

        if (!$user) {
            return false;
        }

        // ENTERPRISE POLICY: Max 1 cambio for ALL users (not just OAuth)
        // This prevents abuse, nickname squatting, and maintains accountability
        return ($user['nickname_change_count'] ?? 0) < 1;
    }

    /**
     * Change nickname (with validation and tracking)
     *
     * ENTERPRISE V4: Uses overlay for immediate visibility without cache invalidation
     *
     * @param int $userId User ID
     * @param string $newNickname New nickname
     * @return bool Success
     */
    public function changeNickname(int $userId, string $newNickname): bool
    {
        // Validate uniqueness (case-insensitive)
        if ($this->nicknameExists($newNickname, $userId)) {
            return false;
        }

        // Get user UUID for WebSocket cache invalidation
        $userUuid = $this->db()->findOne(
            "SELECT uuid FROM {$this->table} WHERE id = ?",
            [$userId]
        )['uuid'] ?? null;

        // ENTERPRISE V11.8: Update DB AND invalidate query cache
        // FIX: Query cache was NOT being invalidated, causing stale data in navbar
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table}
             SET nickname = ?,
                 nickname_change_count = nickname_change_count + 1,
                 nickname_changed_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?",
            [$newNickname, $userId],
            ['invalidate_cache' => ['table:users']] // Invalidate query cache for users table
        );

        if ($success) {
            // V4: Set overlay for immediate visibility across all views
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setProfilePatch($userId, [
                    'nickname' => $newNickname,
                ]);
            }

            // ENTERPRISE V11.8: Invalidate WebSocket cache (24h TTL)
            // This cache is used by Swoole for real-time presence
            if ($userUuid) {
                try {
                    $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_enterprise');
                    if ($redis) {
                        $redis->del("need2talk:user:{$userUuid}");
                    }
                } catch (\Throwable $e) {
                    // Non-blocking
                }
            }

            Logger::security('info', 'Nickname changed', [
                'user_id' => $userId,
                'new_nickname' => $newNickname,
                'caches_invalidated' => ['query_cache', 'websocket_cache', 'overlay_set'],
            ]);
        }

        return $success;
    }

    /**
     * Get avatar URL (with fallback logic)
     *
     * Priority: avatar_url → avatar (legacy) → default-avatar.png
     *
     * @param int $userId User ID
     * @return string Avatar URL
     */
    public function getAvatarUrl(int $userId): string
    {
        $user = $this->findById($userId);

        if (!$user) {
            return asset('img/default-avatar.png');
        }

        // Priority 1: avatar_url (new column)
        if (!empty($user['avatar_url'])) {
            // Google avatars start with https://
            if (str_starts_with($user['avatar_url'], 'https://')) {
                return $user['avatar_url'];
            }

            // Local avatars: add /storage/uploads/ prefix
            return asset('storage/uploads/' . $user['avatar_url']);
        }

        // Priority 2: avatar (legacy column)
        if (!empty($user['avatar'])) {
            return asset('storage/uploads/' . $user['avatar']);
        }

        // Priority 3: Default avatar
        return asset('img/default-avatar.png');
    }

    /**
     * ENTERPRISE V12.2: Batch fetch avatars by UUIDs
     *
     * Optimized for chat message enrichment - fetches current avatars
     * for multiple users in a single query instead of N queries.
     *
     * @param array $uuids Array of user UUIDs
     * @return array Map of [uuid => avatar_url]
     */
    public function getAvatarsByUuids(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        // Remove duplicates and empty values
        $uuids = array_filter(array_unique($uuids));
        if (empty($uuids)) {
            return [];
        }

        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));

        $users = $this->db()->findMany(
            "SELECT uuid, avatar_url
             FROM {$this->table}
             WHERE uuid IN ({$placeholders})
               AND deleted_at IS NULL",
            array_values($uuids),
            ['cache' => true, 'cache_ttl' => 'short']
        );

        $defaultAvatar = asset('img/default-avatar.png');
        $avatarMap = [];

        foreach ($users as $user) {
            $uuid = $user['uuid'];

            // avatar_url → default
            if (!empty($user['avatar_url'])) {
                if (str_starts_with($user['avatar_url'], 'https://')) {
                    $avatarMap[$uuid] = $user['avatar_url'];
                } else {
                    $avatarMap[$uuid] = asset('storage/uploads/' . $user['avatar_url']);
                }
            } else {
                $avatarMap[$uuid] = $defaultAvatar;
            }
        }

        // Fill missing UUIDs with default avatar
        foreach ($uuids as $uuid) {
            if (!isset($avatarMap[$uuid])) {
                $avatarMap[$uuid] = $defaultAvatar;
            }
        }

        return $avatarMap;
    }

    /**
     * Set avatar from Google OAuth
     *
     * ENTERPRISE V4: Uses overlay for immediate visibility
     *
     * @param int $userId User ID
     * @param string $googleAvatarUrl Google avatar URL (https://lh3.googleusercontent.com/...)
     * @return bool Success
     */
    public function setAvatarFromGoogle(int $userId, string $googleAvatarUrl): bool
    {
        // V4: Removed heavy table-level invalidation
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table}
             SET avatar_url = ?,
                 avatar_source = 'google',
                 updated_at = NOW()
             WHERE id = ?",
            [$googleAvatarUrl, $userId]
            // V4: No invalidate_cache - overlay provides immediate visibility
        );

        if ($success) {
            // ENTERPRISE V4: Update overlay for immediate visibility
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setAvatar($userId, $googleAvatarUrl, []);
            }
        }

        return $success;
    }

    /**
     * Set avatar from local upload
     *
     * ENTERPRISE V4: Uses overlay for immediate visibility
     *
     * @param int $userId User ID
     * @param string $localPath Local avatar path (relative to storage/uploads/)
     * @return bool Success
     */
    public function setAvatarFromUpload(int $userId, string $localPath): bool
    {
        // Get user UUID for WebSocket cache invalidation
        $userUuid = $this->db()->findOne(
            "SELECT uuid FROM {$this->table} WHERE id = ?",
            [$userId]
        )['uuid'] ?? null;

        // ENTERPRISE V11.8: Update DB AND invalidate query cache
        // FIX: Query cache was NOT being invalidated, causing stale avatar in navbar
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table}
             SET avatar_url = ?,
                 avatar_source = 'upload',
                 updated_at = NOW()
             WHERE id = ?",
            [$localPath, $userId],
            ['invalidate_cache' => ['table:users']] // Invalidate query cache for users table
        );

        if ($success) {
            // ENTERPRISE V4: Update overlay for immediate visibility
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                // Store with full URL path for overlay
                $avatarUrl = '/storage/uploads/' . $localPath;
                $overlay->setAvatar($userId, $avatarUrl, []);
            }

            // ENTERPRISE V11.8: Invalidate WebSocket cache (24h TTL)
            if ($userUuid) {
                try {
                    $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('L1_enterprise');
                    if ($redis) {
                        $redis->del("need2talk:user:{$userUuid}");
                    }
                } catch (\Throwable $e) {
                    // Non-blocking
                }
            }
        }

        return $success;
    }

    /**
     * Request email change (generates verification token)
     *
     * @param int $userId User ID
     * @param string $newEmail New email address
     * @return string|false Verification token or false on failure
     */
    public function requestEmailChange(int $userId, string $newEmail): string|false
    {
        // Check if email already in use
        if ($this->emailExists($newEmail, $userId)) {
            return false;
        }

        // Generate verification token (64 chars hex)
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours

        // Store in pending_email_changes table (you need to create this)
        // For now, use a simple approach with users table
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table}
             SET email_change_token = ?,
                 email_change_new_email = ?,
                 email_change_expires_at = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [$token, $newEmail, $expiresAt, $userId],
            ['invalidate_cache' => ['table:users', "user:{$userId}"]]
        );

        return $success ? $token : false;
    }

    /**
     * Confirm email change (validates token and updates email)
     *
     * @param string $token Verification token
     * @return bool Success
     */
    public function confirmEmailChange(string $token): bool
    {
        // Find user by token (check not expired)
        $user = $this->db()->findOne(
            "SELECT id, email_change_new_email FROM {$this->table}
             WHERE email_change_token = ?
             AND email_change_expires_at > NOW()
             AND deleted_at IS NULL",
            [$token]
        );

        if (!$user) {
            return false;
        }

        // Update email
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table}
             SET email = ?,
                 email_verified = 0,
                 email_change_token = NULL,
                 email_change_new_email = NULL,
                 email_change_expires_at = NULL,
                 updated_at = NOW()
             WHERE id = ?",
            [$user['email_change_new_email'], $user['id']],
            ['invalidate_cache' => ['table:users', "user:{$user['id']}"]]
        );

        if ($success) {
            Logger::security('info', 'Email changed', [
                'user_id' => $user['id'],
                'new_email_hash' => hash('sha256', $user['email_change_new_email']),
            ]);
        }

        return $success;
    }

    /**
     * Schedule account deletion (GDPR Right to be Forgotten)
     *
     * 30-day grace period before hard delete
     *
     * @param int $userId User ID
     * @param string $reason Deletion reason
     * @return bool Success
     */
    public function scheduleAccountDeletion(int $userId, string $reason = ''): bool
    {
        $user = $this->findById($userId);

        if (!$user) {
            return false;
        }

        $scheduledDeletion = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 days

        // Insert into account_deletions table
        $deletionId = $this->db()->execute(
            "INSERT INTO account_deletions
             (user_id, email, nickname, reason, requested_at, scheduled_deletion_at, status)
             VALUES (?, ?, ?, ?, NOW(), ?, 'pending')",
            [$userId, $user['email'], $user['nickname'], $reason, $scheduledDeletion]
        );

        if ($deletionId) {
            Logger::security('warning', 'Account deletion scheduled', [
                'user_id' => $userId,
                'email_hash' => hash('sha256', $user['email']),
                'scheduled_for' => $scheduledDeletion,
            ]);
        }

        return (bool) $deletionId;
    }

    /**
     * Cancel account deletion (within 30-day grace period)
     *
     * @param int $userId User ID
     * @return bool Success
     */
    public function cancelAccountDeletion(int $userId): bool
    {
        $success = (bool) $this->db()->execute(
            "UPDATE account_deletions
             SET status = 'cancelled',
                 updated_at = NOW()
             WHERE user_id = ?
             AND status = 'pending'
             AND scheduled_deletion_at > NOW()",
            [$userId]
        );

        if ($success) {
            Logger::security('info', 'Account deletion cancelled', [
                'user_id' => $userId,
            ]);
        }

        return $success;
    }

    /**
     * Get pending account deletion (if exists)
     *
     * @param int $userId User ID
     * @return array|null Deletion info or null
     */
    public function getPendingAccountDeletion(int $userId): ?array
    {
        return $this->db()->findOne(
            "SELECT * FROM account_deletions
             WHERE user_id = ?
             AND status = 'pending'
             AND scheduled_deletion_at > NOW()
             ORDER BY requested_at DESC LIMIT 1",
            [$userId],
            [
                'cache' => true,
                'cache_ttl' => 'short',
            ]
        );
    }

    // =========================================================================
    // ENTERPRISE V4: OVERLAY INTEGRATION
    // =========================================================================

    /**
     * Apply overlay to user data
     *
     * ENTERPRISE V4: Merges cached DB data with overlay for immediate visibility
     * of recent changes (avatar, profile fields).
     *
     * Called by all find* methods to ensure consistency.
     *
     * @param array $user User data from DB/cache
     * @return array User data with overlay applied
     */
    private function applyUserOverlay(array $user): array
    {
        if (empty($user['id'])) {
            return $user;
        }

        $userId = (int) $user['id'];
        $overlay = UserSettingsOverlayService::getInstance();

        if (!$overlay->isAvailable()) {
            return $user;
        }

        // Apply avatar overlay (highest priority - most frequently changed)
        $avatarOverlay = $overlay->getAvatar($userId);
        if ($avatarOverlay && !empty($avatarOverlay['url'])) {
            $user['avatar_url'] = $avatarOverlay['url'];
        }

        // Apply profile patch overlay (nickname, bio, etc.)
        $user = $overlay->applyProfileOverlay($user, $userId);

        return $user;
    }

    /**
     * Apply overlay to multiple users (batch)
     *
     * ENTERPRISE V4: Efficient batch overlay application for lists (feed, search, etc.)
     *
     * @param array $users Array of user data
     * @return array Users with overlay applied
     */
    public function applyUsersOverlay(array $users): array
    {
        if (empty($users)) {
            return $users;
        }

        $overlay = UserSettingsOverlayService::getInstance();
        if (!$overlay->isAvailable()) {
            return $users;
        }

        // Collect all user IDs
        $userIds = [];
        foreach ($users as $user) {
            if (!empty($user['id'])) {
                $userIds[] = (int) $user['id'];
            } elseif (!empty($user['user_id'])) {
                $userIds[] = (int) $user['user_id'];
            }
        }

        if (empty($userIds)) {
            return $users;
        }

        // Batch load avatars from overlay
        $avatars = $overlay->batchLoadAvatars($userIds);

        // Apply overlays to each user
        foreach ($users as &$user) {
            $userId = (int) ($user['id'] ?? $user['user_id'] ?? 0);
            if ($userId && isset($avatars[$userId])) {
                $user['avatar_url'] = $avatars[$userId];
            }
        }

        return $users;
    }
}
