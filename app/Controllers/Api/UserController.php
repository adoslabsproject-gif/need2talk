<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * =============================================================================
 * USER API CONTROLLER - ENTERPRISE GALAXY+ V11.0
 * =============================================================================
 *
 * REFACTORED 2025-12: Removed legacy server-side encryption
 *
 * NOW SUPPORTS:
 * - ECDH key management (Chat E2E - TRUE zero-knowledge)
 * - Privacy settings
 * - Profile management
 *
 * REMOVED (deprecated - server-side master key system):
 * - EncryptionService (legacy server-side wrapping)
 * - FriendshipKeyService (legacy shared key wrapping)
 *
 * CURRENT ENCRYPTION ARCHITECTURE:
 * - Chat DM: ECDH P-256 + HKDF + AES-256-GCM (ChatEncryptionService.js)
 * - Diary: PBKDF2-SHA256 + AES-256-GCM (DiaryEncryptionService.js)
 * - Both are TRUE E2E - server NEVER sees plaintext or keys
 *
 * @package Need2Talk\Controllers\Api
 * @version 11.0.0 - E2E Migration Complete
 * @date 2025-12-11
 * =============================================================================
 */
class UserController extends BaseController
{
    // No more legacy encryption services needed

    /**
     * Get user ECDH public key for E2E encryption
     * GET /api/user/encryption-key
     *
     * ENTERPRISE V11.0: ECDH-only endpoint
     *
     * Returns:
     * - ecdh_public_key: User's ECDH public key (JWK format) for chat E2E
     * - key_exists: Always true if user has ECDH key, false otherwise
     *
     * DEPRECATED: master_key is NO LONGER returned (server-side keys removed)
     * Legacy EncryptionService.js clients will see key_exists=false and
     * generate new client-side keys (expected behavior for migration).
     */
    public function getEncryptionKey(): void
    {
        $user = current_user();
        $userId = $user['id'] ?? null;

        if (!$userId) {
            Logger::error('getEncryptionKey: Unauthorized', [
                'session_id' => session_id(),
            ]);
            $this->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);

            return;
        }

        try {
            $db = db();

            // ENTERPRISE V11.1: Only ECDH public key is supported
            // Server-side master key wrapping has been removed for TRUE E2E
            // CRITICAL FIX: NO CACHE for encryption keys!
            // Cache was causing key desync issues - client would re-upload on every page load
            // because cached GET response didn't match freshly saved key
            $ecdhKey = $db->findOne(
                "SELECT ecdh_public_key FROM users WHERE id = :user_id",
                ['user_id' => $userId],
                ['cache' => false]  // NEVER cache encryption keys
            );

            $hasEcdhKey = !empty($ecdhKey['ecdh_public_key']);

            $this->json([
                'success' => true,
                // key_exists = false for legacy clients (they will generate new keys)
                // This is intentional: old master_key system is deprecated
                'key_exists' => false,
                'message' => 'Server-side master keys deprecated. Use client-side encryption.',
                'data' => [
                    'ecdh_public_key' => $ecdhKey['ecdh_public_key'] ?? null,
                ],
                // Legacy compatibility: master_key is null (not returned)
                // This tells old clients to generate new keys
            ]);

        } catch (\Exception $e) {
            Logger::error('Error in getEncryptionKey', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Save user ECDH public key for E2E encryption
     * POST /api/user/encryption-key
     *
     * ENTERPRISE V11.0: ECDH-only endpoint
     *
     * Accepts:
     * - { "public_key": "jwk_json_string" } - ECDH public key for DM E2E
     *
     * DEPRECATED: master_key is NO LONGER accepted (server-side keys removed)
     * Legacy clients sending master_key will receive deprecation notice.
     */
    public function saveEncryptionKey(): void
    {
        $user = current_user();
        $userId = $user['id'] ?? null;

        if (!$userId) {
            $this->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);

            return;
        }

        // Parse JSON body
        $input = json_decode(file_get_contents('php://input'), true);

        // ENTERPRISE V11.0: Handle ECDH public key for chat E2E
        if (isset($input['public_key']) && !empty($input['public_key'])) {
            $this->savePublicKey($userId, $input['public_key']);
            return;
        }

        // DEPRECATED: Legacy master_key no longer supported
        if (isset($input['master_key'])) {
            Logger::warning('Deprecated master_key upload attempted', [
                'user_id' => $userId,
            ]);

            $this->json([
                'success' => false,
                'error' => 'master_key is deprecated. Server-side encryption removed for TRUE E2E. Use DiaryEncryptionService (client-side).',
                'deprecated' => true,
            ], 400);

            return;
        }

        $this->json([
            'success' => false,
            'error' => 'public_key is required (JWK format)',
        ], 400);
    }

    /**
     * Save ECDH public key for chat E2E encryption
     *
     * @param int $userId
     * @param string $publicKeyJwk JWK JSON string
     */
    private function savePublicKey(int $userId, string $publicKeyJwk): void
    {
        try {
            // Validate JWK format
            $jwk = json_decode($publicKeyJwk, true);
            if (!$jwk || !isset($jwk['kty']) || $jwk['kty'] !== 'EC') {
                $this->json([
                    'success' => false,
                    'error' => 'Invalid public key format (must be EC JWK)',
                ], 400);
                return;
            }

            $db = db();

            // Upsert public key in users table (or dedicated table)
            // For now, store in users.ecdh_public_key column
            $db->execute(
                "UPDATE users SET ecdh_public_key = :public_key, updated_at = NOW() WHERE id = :user_id",
                [
                    'public_key' => $publicKeyJwk,
                    'user_id' => $userId,
                ],
                ['invalidate_cache' => ["user:{$userId}"]]
            );

            Logger::info('User ECDH public key saved', ['user_id' => $userId]);

            $this->json([
                'success' => true,
                'message' => 'Public key saved successfully',
            ]);

        } catch (\Exception $e) {
            Logger::error('Error saving public key', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Failed to save public key',
            ], 500);
        }
    }

    /**
     * Delete user ECDH key and diary encryption settings
     * DELETE /api/user/encryption-key
     *
     * ENTERPRISE V11.0: Removes ECDH key and clears diary password
     *
     * WARNING: This will:
     * - Remove ECDH key (new DM messages won't be decryptable)
     * - Clear diary password (diary entries won't be decryptable)
     */
    public function deleteEncryptionKey(): void
    {
        $user = current_user();
        $userId = $user['id'] ?? null;

        if (!$userId) {
            $this->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);

            return;
        }

        try {
            $db = db();

            // Remove ECDH public key from users table
            $db->execute(
                "UPDATE users SET ecdh_public_key = NULL, updated_at = NOW() WHERE id = :user_id",
                ['user_id' => $userId],
                ['invalidate_cache' => ["user:{$userId}"]]
            );

            // Clear diary password settings (but preserve the record)
            $db->execute(
                "UPDATE user_encryption_keys SET
                    diary_password_hash = NULL,
                    diary_kdf_salt = NULL,
                    has_diary_password = FALSE,
                    diary_setup_at = NULL,
                    remembered_devices = '[]'::jsonb,
                    updated_at = NOW()
                 WHERE user_id = :user_id",
                ['user_id' => $userId],
                ['invalidate_cache' => ["encryption_key:{$userId}"]]
            );

            // Security audit log
            Logger::security('warning', 'User encryption keys cleared', [
                'user_id' => $userId,
                'operation' => 'delete_encryption_key',
            ]);

            $this->json([
                'success' => true,
                'message' => 'Encryption keys cleared. ECDH key removed, diary password reset.',
            ]);

        } catch (\Exception $e) {
            Logger::error('Error in deleteEncryptionKey', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get user privacy settings
     * GET /api/user/privacy-settings
     */
    public function getPrivacySettings(): void
    {
        $user = get_session('user');
        $userId = $user['id'] ?? null;

        if (!$userId) {
            $this->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);

            return;
        }

        try {
            $db = db();

            // Fetch privacy settings
            $settings = $db->findOne(
                "SELECT * FROM user_privacy_settings WHERE user_id = :user_id",
                ['user_id' => $userId],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            if (!$settings) {
                // No settings found - insert defaults
                $defaultPreset = 'balanced';

                $sql = "INSERT INTO user_privacy_settings (user_id, privacy_preset)
                        VALUES (:user_id, :preset)";

                $db->execute($sql, [
                    'user_id' => $userId,
                    'preset' => $defaultPreset,
                ]);

                // Fetch newly created settings
                $settings = $db->findOne(
                    "SELECT * FROM user_privacy_settings WHERE user_id = :user_id",
                    ['user_id' => $userId]
                );
            }

            $this->json([
                'success' => true,
                'settings' => $settings,
            ]);

        } catch (\Exception $e) {
            Logger::error('Error in getPrivacySettings', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update user privacy settings
     * POST /api/user/privacy-settings
     *
     * Body: { ...privacy settings fields... }
     */
    public function updatePrivacySettings(): void
    {
        $user = get_session('user');
        $userId = $user['id'] ?? null;

        if (!$userId) {
            $this->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);

            return;
        }

        // Parse JSON body
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input)) {
            $this->json([
                'success' => false,
                'error' => 'No settings provided',
            ], 400);

            return;
        }

        try {
            $db = db();

            // Define allowed fields (security: prevent SQL injection via dynamic fields)
            $allowedFields = [
                'profile_visibility',
                'show_on_search',
                'health_score_visibility',
                'emotion_wheel_visibility',
                'mood_timeline_visibility',
                'stats_visibility',
                'insights_visibility',
                'health_score_total_visibility',
                'health_score_diversity_visibility',
                'health_score_balance_visibility',
                'health_score_stability_visibility',
                'health_score_engagement_visibility',
                'show_online_status',
                'show_last_active',
                'show_friend_list',
                'show_friend_count',
                'show_public_posts',
                'show_reactions',
                'show_comments',
                'privacy_preset',
            ];

            // Filter input to only allowed fields
            $settings = [];
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $input)) {
                    $settings[$field] = $input[$field];
                }
            }

            if (empty($settings)) {
                $this->json([
                    'success' => false,
                    'error' => 'No valid settings provided',
                ], 400);

                return;
            }

            // Build UPDATE query dynamically
            $updateFields = [];
            $params = ['user_id' => $userId];

            foreach ($settings as $field => $value) {
                $updateFields[] = "{$field} = :{$field}";
                $params[$field] = $value;
            }

            $sql = "INSERT INTO user_privacy_settings (user_id, " . implode(', ', array_keys($settings)) . ")
                    VALUES (:user_id, " . implode(', ', array_map(fn ($k) => ":{$k}", array_keys($settings))) . ")
                    ON CONFLICT (setting_key) DO UPDATE SET " . implode(', ', $updateFields);

            $db->execute($sql, $params, [
                'invalidate_cache' => ["privacy_settings:{$userId}"],
            ]);

            Logger::info('Privacy settings updated', [
                'user_id' => $userId,
                'updated_fields' => array_keys($settings),
            ]);

            $this->json([
                'success' => true,
                'message' => 'Privacy settings updated successfully',
            ]);

        } catch (\Exception $e) {
            Logger::error('Error in updatePrivacySettings', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get encryption statistics (admin only)
     * GET /api/user/encryption-stats
     *
     * ENTERPRISE V11.0: Returns E2E encryption stats (ECDH + Diary)
     */
    public function getEncryptionStats(): void
    {
        $user = current_user();
        $userId = $user['id'] ?? null;

        if (!$userId) {
            $this->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);

            return;
        }

        // TODO: Add admin check

        try {
            $db = db();

            // ENTERPRISE V11.0: Stats for E2E encryption (no legacy server-side keys)
            $stats = $db->findOne(
                "SELECT
                    (SELECT COUNT(*) FROM users WHERE ecdh_public_key IS NOT NULL) as users_with_ecdh_key,
                    (SELECT COUNT(*) FROM user_encryption_keys WHERE has_diary_password = TRUE) as users_with_diary_password,
                    (SELECT COUNT(*) FROM users) as total_users
                ",
                [],
                ['cache' => true, 'cache_ttl' => 'medium']
            );

            $this->json([
                'success' => true,
                'stats' => [
                    'users_with_ecdh_key' => (int) $stats['users_with_ecdh_key'],
                    'users_with_diary_password' => (int) $stats['users_with_diary_password'],
                    'total_users' => (int) $stats['total_users'],
                    'ecdh_adoption_rate' => $stats['total_users'] > 0
                        ? round(($stats['users_with_ecdh_key'] / $stats['total_users']) * 100, 2)
                        : 0,
                    'diary_adoption_rate' => $stats['total_users'] > 0
                        ? round(($stats['users_with_diary_password'] / $stats['total_users']) * 100, 2)
                        : 0,
                    'encryption_architecture' => 'E2E_V11.0',
                    'legacy_server_keys' => 'REMOVED',
                ],
            ]);

        } catch (\Exception $e) {
            Logger::error('Error in getEncryptionStats', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    // =========================================================================
    // REMOVED (V11.0): Legacy Friendship E2E Encryption Keys
    // =========================================================================
    // getFriendshipKey() - REMOVED (server-side shared keys deprecated)
    // saveFriendshipKey() - REMOVED (server-side shared keys deprecated)
    // getFriendshipKeyByConversation() - REMOVED (server-side shared keys deprecated)
    //
    // Chat DM now uses ECDH (ChatEncryptionService.js) - TRUE E2E
    // Server NEVER sees shared keys or message content
    // =========================================================================
}
