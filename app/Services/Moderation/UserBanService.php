<?php

namespace Need2Talk\Services\Moderation;

use Need2Talk\Services\Logger;

/**
 * UserBanService - Enterprise Granular Ban System
 *
 * Handles user bans with granular scopes:
 * - global: Banned from everything (login blocked)
 * - chat: Banned from chat rooms only
 * - posts: Banned from creating/editing audio posts
 * - comments: Banned from commenting
 *
 * Note: DM are PRIVATE - no DM bans (users can block each other)
 *
 * Features:
 * - Temporary and permanent bans
 * - Severity levels (warning, mute, shadowban, ban)
 * - Redis caching for fast ban checks
 * - Complete audit trail
 * - Automatic expiration of temporary bans
 *
 * @package Need2Talk\Services\Moderation
 */
class UserBanService
{
    private const CACHE_PREFIX = 'user:ban:';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Ban a user with granular scope
     *
     * @param int $userId User to ban
     * @param string $scope Ban scope: 'global', 'chat', 'posts', 'comments'
     * @param string $reason Reason shown to user
     * @param int|null $durationMinutes Duration in minutes (null = permanent)
     * @param int|null $moderatorId Moderator who issued the ban
     * @param int|null $adminId Admin who issued the ban (if not moderator)
     * @param array $options Additional options: internal_notes, severity, related_*
     * @return array Result with success status
     */
    public function banUser(
        int $userId,
        string $scope,
        string $reason,
        ?int $durationMinutes = null,
        ?int $moderatorId = null,
        ?int $adminId = null,
        array $options = []
    ): array {
        // Validate scope
        $validScopes = ['global', 'chat', 'posts', 'comments'];
        if (!in_array($scope, $validScopes)) {
            return ['success' => false, 'error' => 'Invalid ban scope'];
        }

        // Check if user exists
        $pdo = db_pdo();
        $stmt = $pdo->prepare("SELECT id, uuid, nickname, email FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Check for existing active ban with same scope
        $stmt = $pdo->prepare("
            SELECT id FROM user_bans
            WHERE user_id = :user_id
            AND scope = :scope
            AND is_active = TRUE
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute(['user_id' => $userId, 'scope' => $scope]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'User already has an active ban for this scope'];
        }

        // Calculate expiration
        $banType = $durationMinutes ? 'temporary' : 'permanent';
        $expiresAt = $durationMinutes
            ? date('Y-m-d H:i:s', time() + ($durationMinutes * 60))
            : null;

        // Create ban record
        $stmt = $pdo->prepare("
            INSERT INTO user_bans (
                user_id, scope, ban_type, severity, reason, internal_notes,
                expires_at, banned_by_moderator_id, banned_by_admin_id,
                related_message_uuid, related_room_uuid, related_post_id, related_comment_id
            ) VALUES (
                :user_id, :scope, :ban_type, :severity, :reason, :internal_notes,
                :expires_at, :moderator_id, :admin_id,
                :message_uuid, :room_uuid, :post_id, :comment_id
            )
            RETURNING id, uuid
        ");

        $stmt->execute([
            'user_id' => $userId,
            'scope' => $scope,
            'ban_type' => $banType,
            'severity' => $options['severity'] ?? 'ban',
            'reason' => $reason,
            'internal_notes' => $options['internal_notes'] ?? null,
            'expires_at' => $expiresAt,
            'moderator_id' => $moderatorId,
            'admin_id' => $adminId,
            'message_uuid' => $options['related_message_uuid'] ?? null,
            'room_uuid' => $options['related_room_uuid'] ?? null,
            'post_id' => $options['related_post_id'] ?? null,
            'comment_id' => $options['related_comment_id'] ?? null,
        ]);

        $banRecord = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Invalidate ban cache
        $this->invalidateBanCache($userId);

        // If global ban, terminate all user sessions
        if ($scope === 'global') {
            $this->terminateUserSessions($userId, $pdo);
        }

        // If chat ban, disconnect from WebSocket (via Redis pub/sub)
        if ($scope === 'global' || $scope === 'chat') {
            $this->notifyWebSocketDisconnect($userId);
        }

        // Log action
        if ($moderatorId) {
            ModerationSecurityService::logModerationAction($moderatorId, 'ban_user', $userId, null, [
                'scope' => $scope,
                'ban_type' => $banType,
                'reason' => $reason,
                'duration_minutes' => $durationMinutes,
                'ban_id' => $banRecord['id'],
            ]);
        }

        Logger::security('warning', 'USER_BANNED', [
            'user_id' => $userId,
            'user_email' => $user['email'],
            'scope' => $scope,
            'ban_type' => $banType,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'moderator_id' => $moderatorId,
            'admin_id' => $adminId,
        ]);

        return [
            'success' => true,
            'ban_id' => $banRecord['id'],
            'ban_uuid' => $banRecord['uuid'],
            'message' => "User banned from {$scope}" . ($durationMinutes ? " for {$durationMinutes} minutes" : ' permanently'),
        ];
    }

    /**
     * Revoke a ban
     */
    public function revokeBan(
        int $banId,
        string $reason,
        ?int $moderatorId = null,
        ?int $adminId = null
    ): array {
        $pdo = db_pdo();

        // Get ban info
        $stmt = $pdo->prepare("
            SELECT id, user_id, scope, is_active
            FROM user_bans
            WHERE id = :id
        ");
        $stmt->execute(['id' => $banId]);
        $ban = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$ban) {
            return ['success' => false, 'error' => 'Ban not found'];
        }

        if (!$ban['is_active']) {
            return ['success' => false, 'error' => 'Ban is already revoked'];
        }

        // Revoke ban
        $stmt = $pdo->prepare("
            UPDATE user_bans
            SET is_active = FALSE,
                revoked_at = NOW(),
                revoked_by_moderator_id = :moderator_id,
                revoked_by_admin_id = :admin_id,
                revoke_reason = :reason
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $banId,
            'moderator_id' => $moderatorId,
            'admin_id' => $adminId,
            'reason' => $reason,
        ]);

        // Invalidate cache
        $this->invalidateBanCache($ban['user_id']);

        // Log action
        if ($moderatorId) {
            ModerationSecurityService::logModerationAction($moderatorId, 'unban_user', $ban['user_id'], null, [
                'ban_id' => $banId,
                'scope' => $ban['scope'],
                'reason' => $reason,
            ]);
        }

        Logger::security('info', 'USER_UNBANNED', [
            'user_id' => $ban['user_id'],
            'ban_id' => $banId,
            'scope' => $ban['scope'],
            'moderator_id' => $moderatorId,
            'admin_id' => $adminId,
        ]);

        return ['success' => true, 'message' => 'Ban revoked successfully'];
    }

    /**
     * Check if user is banned for a specific scope
     * Optimized with Redis caching
     *
     * @param int $userId User ID
     * @param string $scope Scope to check (or 'any' to check all)
     * @return bool True if banned
     */
    public function isBanned(int $userId, string $scope = 'any'): bool
    {
        $bans = $this->getActiveBans($userId);

        if (empty($bans)) {
            return false;
        }

        if ($scope === 'any') {
            return true;
        }

        // Check for global ban (blocks everything)
        foreach ($bans as $ban) {
            if ($ban['scope'] === 'global') {
                return true;
            }
            if ($ban['scope'] === $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all active bans for a user (cached)
     */
    public function getActiveBans(int $userId): array
    {
        // Try cache first
        $cached = $this->getBanCache($userId);
        if ($cached !== null) {
            return $cached;
        }

        // Load from database
        $pdo = db_pdo();
        $stmt = $pdo->prepare("
            SELECT
                id, uuid, scope, ban_type, severity, reason,
                expires_at, created_at,
                banned_by_moderator_id, banned_by_admin_id
            FROM user_bans
            WHERE user_id = :user_id
            AND is_active = TRUE
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        $bans = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Cache result
        $this->setBanCache($userId, $bans);

        return $bans;
    }

    /**
     * Get ban info to show to user
     */
    public function getBanInfoForUser(int $userId, string $scope): ?array
    {
        $bans = $this->getActiveBans($userId);

        foreach ($bans as $ban) {
            if ($ban['scope'] === 'global' || $ban['scope'] === $scope) {
                return [
                    'is_banned' => true,
                    'scope' => $ban['scope'],
                    'reason' => $ban['reason'],
                    'expires_at' => $ban['expires_at'],
                    'is_permanent' => $ban['ban_type'] === 'permanent',
                ];
            }
        }

        return null;
    }

    /**
     * Get all banned users (for admin display)
     */
    public function getBannedUsers(int $limit = 100, int $offset = 0, ?string $scope = null): array
    {
        $pdo = db_pdo();

        $scopeFilter = $scope ? "AND ub.scope = :scope" : "";

        $stmt = $pdo->prepare("
            SELECT
                ub.id AS ban_id,
                ub.uuid AS ban_uuid,
                ub.user_id,
                u.nickname,
                u.email,
                ub.scope,
                ub.ban_type,
                ub.severity,
                ub.reason,
                ub.expires_at,
                ub.created_at,
                m.username AS banned_by_moderator
            FROM user_bans ub
            JOIN users u ON u.id = ub.user_id
            LEFT JOIN moderators m ON m.id = ub.banned_by_moderator_id
            WHERE ub.is_active = TRUE
            AND (ub.expires_at IS NULL OR ub.expires_at > NOW())
            {$scopeFilter}
            ORDER BY ub.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        if ($scope) {
            $stmt->bindValue(':scope', $scope);
        }
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get ban count by scope
     */
    public function getBanCountsByScope(): array
    {
        $pdo = db_pdo();
        $stmt = $pdo->query("
            SELECT scope, COUNT(*) AS count
            FROM user_bans
            WHERE is_active = TRUE
            AND (expires_at IS NULL OR expires_at > NOW())
            GROUP BY scope
        ");

        $counts = ['global' => 0, 'chat' => 0, 'posts' => 0, 'comments' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[$row['scope']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Expire temporary bans (call from cron)
     */
    public function expireTemporaryBans(): int
    {
        $pdo = db_pdo();

        // Get users with expiring bans for cache invalidation
        $stmt = $pdo->query("
            SELECT DISTINCT user_id
            FROM user_bans
            WHERE is_active = TRUE
            AND ban_type = 'temporary'
            AND expires_at IS NOT NULL
            AND expires_at <= NOW()
        ");
        $userIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // Expire bans using the PostgreSQL function
        $stmt = $pdo->query("SELECT expire_temporary_bans()");
        $count = (int) $stmt->fetchColumn();

        // Invalidate cache for affected users
        foreach ($userIds as $userId) {
            $this->invalidateBanCache($userId);
        }

        if ($count > 0) {
            Logger::info('Temporary bans expired', ['count' => $count]);
        }

        return $count;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Get ban cache for user
     */
    private function getBanCache(int $userId): ?array
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $cached = $redis->get(self::CACHE_PREFIX . $userId);
            if ($cached) {
                return json_decode($cached, true);
            }
        } catch (\Exception $e) {
            // Redis not available
        }
        return null;
    }

    /**
     * Set ban cache for user
     */
    private function setBanCache(int $userId, array $bans): void
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $redis->setex(self::CACHE_PREFIX . $userId, self::CACHE_TTL, json_encode($bans));
        } catch (\Exception $e) {
            // Continue without caching
        }
    }

    /**
     * Invalidate ban cache for user
     */
    private function invalidateBanCache(int $userId): void
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $redis->del(self::CACHE_PREFIX . $userId);
        } catch (\Exception $e) {
            // Continue
        }
    }

    /**
     * Terminate all sessions for a user (for global ban)
     */
    private function terminateUserSessions(int $userId, \PDO $pdo): void
    {
        // Clear user sessions from database
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);

        // Invalidate Redis session
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            // Pattern match and delete all sessions for this user
            // Format: need2talk:session:*
            // Note: This is a simplified approach - in production you might want
            // to store user_id -> session_id mapping
        } catch (\Exception $e) {
            // Continue
        }
    }

    /**
     * Notify WebSocket to disconnect user (for chat/global bans)
     */
    private function notifyWebSocketDisconnect(int $userId): void
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            // Publish disconnect command to WebSocket server
            $redis->publish('moderation:disconnect', json_encode([
                'user_id' => $userId,
                'reason' => 'banned',
            ]));
        } catch (\Exception $e) {
            Logger::warning('Failed to notify WebSocket for user disconnect', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
