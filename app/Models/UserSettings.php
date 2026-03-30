<?php

/**
 * UserSettings Model - Enterprise Galaxy V4
 *
 * HYBRID APPROACH: Typed columns + JSON for flexibility
 * - Common settings: Typed columns (fast queries + indexes)
 * - Advanced settings: JSON (flexible schema, no migrations)
 *
 * FEATURES:
 * - Privacy settings (profile visibility, activity, friend requests)
 * - Notification preferences (email, push)
 * - Profile tabs visibility (6 tabs configurable)
 * - Content settings (default post visibility, auto-save)
 * - UI preferences (theme, layout)
 * - Language & timezone
 *
 * PERFORMANCE (V4):
 * - Overlay cache for immediate visibility (sub-ms reads)
 * - Removed heavy table-level invalidation
 * - Multi-level caching (Overlay → L1 → L2 → DB)
 * - Request-scoped memoization
 * - Default settings created on user registration
 *
 * SCALABILITY: 100,000+ concurrent users
 *
 * @package Need2Talk\Models
 * @version 2.0.0 (V4 Overlay)
 * @author Claude Code (AI-Orchestrated Development)
 */

namespace Need2Talk\Models;

use Need2Talk\Core\BaseModel;
use Need2Talk\Services\Cache\UserSettingsOverlayService;

class UserSettings extends BaseModel
{
    protected string $table = 'user_settings';

    // Settings don't use soft deletes (cascade deleted with user)
    protected bool $usesSoftDeletes = false;

    /**
     * Default tabs visibility (matches database JSON default)
     */
    private const DEFAULT_TABS_VISIBILITY = [
        'panoramica' => 'public',   // Overview - psychological dashboard
        'diario' => 'private',      // Emotional journal - always private
        'timeline' => 'friends',    // Timeline - posts feed
        'calendario' => 'friends',  // Calendar - emotional calendar
        'emozioni' => 'private',    // Emotions - emotion analytics
        'archivio' => 'private',    // Archive - old posts
    ];

    /**
     * Find settings by user ID
     *
     * @param int $userId User ID
     * @return array|null Settings array or null if not found
     */
    public function findByUserId(int $userId): ?array
    {
        return $this->db()->findOne(
            "SELECT * FROM {$this->table} WHERE user_id = ?",
            [$userId],
            [
                'cache' => true,
                'cache_ttl' => 'long', // 1 hour - settings rarely change
            ]
        );
    }

    /**
     * Create default settings for user
     *
     * Called automatically on user registration
     * V4: Uses overlay for immediate visibility
     *
     * @param int $userId User ID
     * @return bool Success
     */
    public function createDefault(int $userId): bool
    {
        // V4: Removed heavy table-level invalidation
        $success = (bool) $this->db()->execute(
            "INSERT INTO {$this->table} (user_id) VALUES (?)",
            [$userId]
            // V4: No invalidate_cache - overlay provides immediate visibility
        );

        // V4: No overlay needed for new user (no prior cached state to override)
        return $success;
    }

    /**
     * Update privacy settings
     *
     * ENTERPRISE V4: Uses overlay for immediate visibility
     *
     * @param int $userId User ID
     * @param array $data Privacy settings data
     * @return bool Success
     */
    public function updatePrivacy(int $userId, array $data): bool
    {
        // ENTERPRISE V5.7: Simplified privacy fields - only 3 boolean toggles
        $allowedFields = [
            'show_online_status',
            'allow_friend_requests',
            'allow_direct_messages',
        ];

        // Filter only allowed fields
        $updates = [];
        $params = [];
        $privacyPatch = [];
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $updates[] = "{$field} = ?";
                $params[] = $value;
                $privacyPatch[$field] = $value;
            }
        }

        if (empty($updates)) {
            return true; // Nothing to update
        }

        $params[] = $userId;

        // V4: Removed heavy table-level invalidation
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table} SET " . implode(', ', $updates) . " WHERE user_id = ?",
            $params
            // V4: No invalidate_cache - overlay provides immediate visibility
        );

        if ($success) {
            // ENTERPRISE V4: Update overlay for immediate visibility
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setPrivacyPatch($userId, $privacyPatch);
            }

            // V4: WebSocket cache invalidation signals kept for client-side cache
            $userUuid = $this->getUserUuid($userId);
            if ($userUuid) {
                \Need2Talk\Services\WebSocketPublisher::publishCacheInvalidation($userUuid, [
                    'user_settings',
                    'privacy_settings',
                ]);
            }
        }

        return $success;
    }

    /**
     * Update notification preferences
     *
     * ENTERPRISE V4: Uses overlay for immediate visibility
     * ENTERPRISE GALAXY V4 (2025-11-29): In-app notifications (campanella) + Newsletter only
     *
     * @param int $userId User ID
     * @param array $data Notification settings data
     * @return bool Success
     */
    public function updateNotifications(int $userId, array $data): bool
    {
        $allowedFields = [
            // In-app notification preferences (campanella)
            'notify_comments',
            'notify_replies',
            'notify_reactions',
            'notify_comment_likes',
            'notify_mentions',
            'notify_friend_requests',
            'notify_friend_accepted',
            // Chat/DM notifications
            'notify_dm_received',
            // Newsletter (unica email GDPR-compliant)
            'email_newsletter',
        ];

        $updates = [];
        $params = [];
        $notificationsPatch = [];
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $updates[] = "{$field} = ?";
                $params[] = $value;
                $notificationsPatch[$field] = $value;
            }
        }

        if (empty($updates)) {
            return true;
        }

        $params[] = $userId;

        // V4: Removed heavy table-level invalidation
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table} SET " . implode(', ', $updates) . " WHERE user_id = ?",
            $params
            // V4: No invalidate_cache - overlay provides immediate visibility
        );

        if ($success) {
            // ENTERPRISE V4: Update overlay for immediate visibility
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setNotificationsPatch($userId, $notificationsPatch);
            }

            // V4: WebSocket cache invalidation signals kept for client-side cache
            $userUuid = $this->getUserUuid($userId);
            if ($userUuid) {
                \Need2Talk\Services\WebSocketPublisher::publishCacheInvalidation($userUuid, [
                    'user_settings',
                    'notification_settings',
                ]);
            }
        }

        return $success;
    }

    /**
     * Update tabs visibility (JSON column)
     *
     * ENTERPRISE FEATURE: Granular control over which tabs are visible to friends/public
     * V4: Uses overlay for immediate visibility
     *
     * @param int $userId User ID
     * @param array $tabsVisibility Tab visibility map: ['tab_name' => 'public|friends|private']
     * @return bool Success
     */
    public function updateTabsVisibility(int $userId, array $tabsVisibility): bool
    {
        // Validate all required tabs are present
        foreach (array_keys(self::DEFAULT_TABS_VISIBILITY) as $tab) {
            if (!isset($tabsVisibility[$tab])) {
                return false; // Missing required tab
            }

            // Validate visibility value
            if (!in_array($tabsVisibility[$tab], ['public', 'friends', 'private'], true)) {
                return false; // Invalid visibility
            }
        }

        // "diario" (emotional journal) is ALWAYS private (override user input)
        $tabsVisibility['diario'] = 'private';

        $jsonData = json_encode($tabsVisibility);

        // V4: Removed heavy table-level invalidation
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table} SET tabs_visibility = ? WHERE user_id = ?",
            [$jsonData, $userId]
            // V4: No invalidate_cache - overlay provides immediate visibility
        );

        if ($success) {
            // ENTERPRISE V4: Update overlay for immediate visibility
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setTabsVisibility($userId, $tabsVisibility);
            }

            // V4: WebSocket cache invalidation signals kept for client-side cache
            $userUuid = $this->getUserUuid($userId);
            if ($userUuid) {
                \Need2Talk\Services\WebSocketPublisher::publishCacheInvalidation($userUuid, [
                    'user_settings',
                    'tabs_visibility',
                    'profile_tabs',
                ]);
            }
        }

        return $success;
    }

    /**
     * Get tab visibility for specific tab
     *
     * Used by ProfileController to check if viewer can see tab
     *
     * @param int $userId User ID (profile owner)
     * @param string $tab Tab name (panoramica, diario, timeline, etc.)
     * @return string Visibility: 'public', 'friends', 'private'
     */
    public function getTabVisibility(int $userId, string $tab): string
    {
        $settings = $this->findByUserId($userId);

        if (!$settings || empty($settings['tabs_visibility'])) {
            // Fallback to default
            return self::DEFAULT_TABS_VISIBILITY[$tab] ?? 'private';
        }

        $tabsVisibility = json_decode($settings['tabs_visibility'], true);

        return $tabsVisibility[$tab] ?? self::DEFAULT_TABS_VISIBILITY[$tab] ?? 'private';
    }

    /**
     * Update content settings
     *
     * ENTERPRISE V4: Uses overlay for immediate visibility
     *
     * @param int $userId User ID
     * @param array $data Content settings data
     * @return bool Success
     */
    public function updateContent(int $userId, array $data): bool
    {
        $allowedFields = [
            'default_post_visibility',
            'auto_save_drafts',
        ];

        $updates = [];
        $params = [];
        $contentPatch = [];
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $updates[] = "{$field} = ?";
                $params[] = $value;
                $contentPatch[$field] = $value;
            }
        }

        if (empty($updates)) {
            return true;
        }

        $params[] = $userId;

        // V4: Removed heavy table-level invalidation
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table} SET " . implode(', ', $updates) . " WHERE user_id = ?",
            $params
            // V4: No invalidate_cache - overlay provides immediate visibility
        );

        if ($success && !empty($contentPatch)) {
            // ENTERPRISE V4: Update overlay for immediate visibility
            // Content settings go to profile patch
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setProfilePatch($userId, $contentPatch);
            }
        }

        return $success;
    }

    /**
     * Update localization settings
     *
     * ENTERPRISE V4: Uses overlay for immediate visibility
     *
     * @param int $userId User ID
     * @param string $language Language code (ISO 639-1)
     * @param string $timezone Timezone (IANA format)
     * @return bool Success
     */
    public function updateLocalization(int $userId, string $language, string $timezone): bool
    {
        // V4: Removed heavy table-level invalidation
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table} SET language = ?, timezone = ? WHERE user_id = ?",
            [$language, $timezone, $userId]
            // V4: No invalidate_cache - overlay provides immediate visibility
        );

        if ($success) {
            // ENTERPRISE V4: Update overlay for immediate visibility
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                $overlay->setProfilePatch($userId, [
                    'language' => $language,
                    'timezone' => $timezone,
                ]);
            }
        }

        return $success;
    }

    /**
     * Update advanced settings (JSON)
     *
     * Used for flexible settings that don't require typed columns
     * ENTERPRISE V4: Uses overlay for immediate visibility
     *
     * @param int $userId User ID
     * @param string $jsonField JSON field name (notification_preferences, privacy_advanced, ui_preferences)
     * @param array $data Settings data
     * @return bool Success
     */
    public function updateAdvancedSettings(int $userId, string $jsonField, array $data): bool
    {
        $allowedFields = ['notification_preferences', 'privacy_advanced', 'ui_preferences'];

        if (!in_array($jsonField, $allowedFields, true)) {
            return false; // Invalid field
        }

        $jsonData = json_encode($data);

        // V4: Removed heavy table-level invalidation
        $success = (bool) $this->db()->execute(
            "UPDATE {$this->table} SET {$jsonField} = ? WHERE user_id = ?",
            [$jsonData, $userId]
            // V4: No invalidate_cache - overlay provides immediate visibility
        );

        if ($success) {
            // ENTERPRISE V4: Update overlay based on field type
            $overlay = UserSettingsOverlayService::getInstance();
            if ($overlay->isAvailable()) {
                switch ($jsonField) {
                    case 'notification_preferences':
                        $overlay->setNotificationsPatch($userId, $data);
                        break;
                    case 'privacy_advanced':
                        $overlay->setPrivacyPatch($userId, $data);
                        break;
                    case 'ui_preferences':
                        $overlay->setProfilePatch($userId, ['ui_preferences' => $data]);
                        break;
                }
            }
        }

        return $success;
    }

    /**
     * Get profile visibility for user
     *
     * @param int $userId User ID
     * @return string Visibility: 'public', 'friends', 'private'
     */
    public function getProfileVisibility(int $userId): string
    {
        $settings = $this->findByUserId($userId);

        return $settings['profile_visibility'] ?? 'public';
    }

    /**
     * Get activity visibility for user
     *
     * @param int $userId User ID
     * @return string Visibility: 'public', 'friends', 'private'
     */
    public function getActivityVisibility(int $userId): string
    {
        $settings = $this->findByUserId($userId);

        return $settings['activity_visibility'] ?? 'friends';
    }

    /**
     * Check if user shows online status
     *
     * @param int $userId User ID
     * @return bool True if online status visible
     */
    public function showsOnlineStatus(int $userId): bool
    {
        $settings = $this->findByUserId($userId);

        return (bool) ($settings['show_online_status'] ?? true);
    }

    /**
     * Check if user allows friend requests
     *
     * @param int $userId User ID
     * @return bool True if friend requests allowed
     */
    public function allowsFriendRequests(int $userId): bool
    {
        $settings = $this->findByUserId($userId);

        return (bool) ($settings['allow_friend_requests'] ?? true);
    }

    /**
     * Get default tabs visibility
     *
     * @return array Default tabs visibility map
     */
    public static function getDefaultTabsVisibility(): array
    {
        return self::DEFAULT_TABS_VISIBILITY;
    }

    /**
     * Get user UUID from ID
     *
     * Helper for V4 WebSocket notifications
     *
     * @param int $userId User ID
     * @return string|null User UUID or null
     */
    private function getUserUuid(int $userId): ?string
    {
        $user = $this->db()->findOne(
            "SELECT uuid FROM users WHERE id = ?",
            [$userId],
            ['cache' => true, 'cache_ttl' => 'long']
        );

        return $user['uuid'] ?? null;
    }
}
