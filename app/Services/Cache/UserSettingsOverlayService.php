<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * UserSettingsOverlayService - Enterprise Galaxy V4
 *
 * Overlay cache for user settings and profile changes providing immediate
 * visibility without expensive cache invalidation.
 *
 * ARCHITECTURE:
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ Layer          │ Purpose                │ TTL      │ Invalidation       │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ Overlay        │ Immediate changes      │ 10 min   │ Auto-expire        │
 * │ DB Cache       │ Persisted state        │ 1 hour   │ On flush           │
 * │ PostgreSQL     │ Source of truth        │ N/A      │ N/A                │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * REDIS KEY STRUCTURE (DB5):
 * - overlay:user:profile:{userId}              → {nickname, avatar_url, ...}
 * - overlay:user:settings:{userId}             → {privacy, notifications, ...}
 * - overlay:user:avatar:{userId}               → new avatar URL
 * - overlay:user:privacy:{userId}              → privacy settings patch
 * - overlay:user:notifications:{userId}        → notification settings patch
 *
 * USE CASES:
 * 1. Avatar change: Immediate visibility to all viewers without feed invalidation
 * 2. Nickname change: Immediate update in all contexts
 * 3. Privacy settings: Immediate effect on profile visibility
 * 4. Notification prefs: Immediate effect on email/push delivery
 *
 * PERFORMANCE:
 * - Profile fetch: <1ms (Redis GET + JSON decode)
 * - Settings fetch: <1ms (Redis GET)
 * - Avatar URL: <0.5ms (Redis GET)
 *
 * @package Need2Talk\Services\Cache
 */
class UserSettingsOverlayService
{
    private const OVERLAY_TTL = 600;        // 10 minutes
    private const AVATAR_TTL = 1800;        // 30 minutes (avatar changes rare but important)
    private const DIRTY_TTL = 900;          // 15 minutes

    private static ?self $instance = null;
    private ?\Redis $redis = null;

    private function __construct()
    {
        $this->redis = EnterpriseRedisManager::getInstance()->getConnection('overlay');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if overlay service is available
     */
    public function isAvailable(): bool
    {
        return $this->redis !== null;
    }

    // =========================================================================
    // PROFILE OVERLAY (nickname, avatar, basic info)
    // =========================================================================

    /**
     * Set profile patch in overlay
     *
     * Stores only changed fields, not full profile.
     * Caller should merge with DB data.
     *
     * @param int $userId User ID
     * @param array $patch Changed fields ['nickname' => 'new', 'avatar_url' => 'new', ...]
     */
    public function setProfilePatch(int $userId, array $patch): void
    {
        if (!$this->redis || empty($patch)) return;

        try {
            $key = "overlay:user:profile:{$userId}";

            // Get existing patch and merge
            $existing = $this->getProfilePatch($userId);
            $merged = array_merge($existing, $patch);
            $merged['_ts'] = microtime(true); // Timestamp for ordering

            $this->redis->setex($key, self::OVERLAY_TTL, json_encode($merged));

            // Mark dirty for flush
            $this->markDirty($userId, 'profile', $patch);

            Logger::overlay('info', 'UserSettingsOverlay: Profile patch set', [
                'user_id' => $userId,
                'fields' => array_keys($patch),
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'UserSettingsOverlayService::setProfilePatch failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get profile patch from overlay
     *
     * @param int $userId User ID
     * @return array Patch fields or empty array
     */
    public function getProfilePatch(int $userId): array
    {
        if (!$this->redis) return [];

        try {
            $key = "overlay:user:profile:{$userId}";
            $json = $this->redis->get($key);

            if ($json === false) {
                return [];
            }

            $patch = json_decode($json, true);
            return is_array($patch) ? $patch : [];

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Apply profile overlay to user data
     *
     * Merges overlay patch with DB data, overlay wins for conflicts.
     *
     * @param array $userData User data from DB
     * @param int $userId User ID
     * @return array Merged user data
     */
    public function applyProfileOverlay(array $userData, int $userId): array
    {
        $patch = $this->getProfilePatch($userId);

        if (empty($patch)) {
            return $userData;
        }

        // Remove internal fields
        unset($patch['_ts']);

        // Merge: overlay wins
        return array_merge($userData, $patch);
    }

    // =========================================================================
    // AVATAR OVERLAY (dedicated for performance)
    // =========================================================================

    /**
     * Set avatar URL in overlay
     *
     * Dedicated key for avatar due to high read frequency.
     * Used by feed, comments, profiles, search results.
     *
     * @param int $userId User ID
     * @param string $avatarUrl New avatar URL
     * @param array $thumbnails Optional thumbnail URLs ['small' => url, 'medium' => url]
     */
    public function setAvatar(int $userId, string $avatarUrl, array $thumbnails = []): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:user:avatar:{$userId}";

            $data = [
                'url' => $avatarUrl,
                'thumbnails' => $thumbnails,
                'ts' => microtime(true),
            ];

            $this->redis->setex($key, self::AVATAR_TTL, json_encode($data));

            // Also update profile patch
            $this->setProfilePatch($userId, [
                'avatar_url' => $avatarUrl,
            ]);

            Logger::overlay('info', 'UserSettingsOverlay: Avatar set', [
                'user_id' => $userId,
                'has_thumbnails' => !empty($thumbnails),
            ]);

        } catch (\Exception $e) {
            Logger::overlay('error', 'UserSettingsOverlayService::setAvatar failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get avatar from overlay
     *
     * @param int $userId User ID
     * @return array|null ['url' => string, 'thumbnails' => array] or null
     */
    public function getAvatar(int $userId): ?array
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:user:avatar:{$userId}";
            $json = $this->redis->get($key);

            if ($json === false) {
                return null;
            }

            return json_decode($json, true);

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get avatar URL with overlay priority
     *
     * @param int $userId User ID
     * @param string|null $dbAvatarUrl Avatar URL from DB (fallback)
     * @return string|null Avatar URL
     */
    public function getAvatarUrl(int $userId, ?string $dbAvatarUrl = null): ?string
    {
        $overlay = $this->getAvatar($userId);

        if ($overlay && !empty($overlay['url'])) {
            return $overlay['url'];
        }

        return $dbAvatarUrl;
    }

    // =========================================================================
    // SETTINGS OVERLAY (privacy, notifications, etc.)
    // =========================================================================

    /**
     * Set privacy settings patch
     *
     * @param int $userId User ID
     * @param array $patch Changed privacy settings
     */
    public function setPrivacyPatch(int $userId, array $patch): void
    {
        if (!$this->redis || empty($patch)) return;

        try {
            $key = "overlay:user:privacy:{$userId}";

            $existing = $this->getPrivacyPatch($userId);
            $merged = array_merge($existing, $patch);
            $merged['_ts'] = microtime(true);

            $this->redis->setex($key, self::OVERLAY_TTL, json_encode($merged));

            $this->markDirty($userId, 'privacy', $patch);

        } catch (\Exception $e) {
            Logger::overlay('error', 'UserSettingsOverlayService::setPrivacyPatch failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get privacy settings patch
     *
     * @param int $userId User ID
     * @return array Patch or empty array
     */
    public function getPrivacyPatch(int $userId): array
    {
        if (!$this->redis) return [];

        try {
            $key = "overlay:user:privacy:{$userId}";
            $json = $this->redis->get($key);

            if ($json === false) {
                return [];
            }

            $patch = json_decode($json, true);
            unset($patch['_ts']);
            return is_array($patch) ? $patch : [];

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Apply privacy overlay to settings
     *
     * @param array $settings Settings from DB
     * @param int $userId User ID
     * @return array Merged settings
     */
    public function applyPrivacyOverlay(array $settings, int $userId): array
    {
        $patch = $this->getPrivacyPatch($userId);

        if (empty($patch)) {
            return $settings;
        }

        return array_merge($settings, $patch);
    }

    /**
     * Set notification preferences patch
     *
     * @param int $userId User ID
     * @param array $patch Changed notification settings
     */
    public function setNotificationsPatch(int $userId, array $patch): void
    {
        if (!$this->redis || empty($patch)) return;

        try {
            $key = "overlay:user:notifications:{$userId}";

            $existing = $this->getNotificationsPatch($userId);
            $merged = array_merge($existing, $patch);
            $merged['_ts'] = microtime(true);

            $this->redis->setex($key, self::OVERLAY_TTL, json_encode($merged));

            $this->markDirty($userId, 'notifications', $patch);

        } catch (\Exception $e) {
            Logger::overlay('error', 'UserSettingsOverlayService::setNotificationsPatch failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get notification preferences patch
     *
     * @param int $userId User ID
     * @return array Patch or empty array
     */
    public function getNotificationsPatch(int $userId): array
    {
        if (!$this->redis) return [];

        try {
            $key = "overlay:user:notifications:{$userId}";
            $json = $this->redis->get($key);

            if ($json === false) {
                return [];
            }

            $patch = json_decode($json, true);
            unset($patch['_ts']);
            return is_array($patch) ? $patch : [];

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Apply notifications overlay to settings
     *
     * @param array $settings Settings from DB
     * @param int $userId User ID
     * @return array Merged settings
     */
    public function applyNotificationsOverlay(array $settings, int $userId): array
    {
        $patch = $this->getNotificationsPatch($userId);

        if (empty($patch)) {
            return $settings;
        }

        return array_merge($settings, $patch);
    }

    /**
     * Set tabs visibility patch
     *
     * @param int $userId User ID
     * @param array $tabsVisibility Full tabs visibility array
     */
    public function setTabsVisibility(int $userId, array $tabsVisibility): void
    {
        if (!$this->redis || empty($tabsVisibility)) return;

        try {
            $key = "overlay:user:tabs:{$userId}";
            $this->redis->setex($key, self::OVERLAY_TTL, json_encode($tabsVisibility));

            $this->markDirty($userId, 'tabs', $tabsVisibility);

        } catch (\Exception $e) {
            Logger::overlay('error', 'UserSettingsOverlayService::setTabsVisibility failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get tabs visibility from overlay
     *
     * @param int $userId User ID
     * @return array|null Tabs visibility or null
     */
    public function getTabsVisibility(int $userId): ?array
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:user:tabs:{$userId}";
            $json = $this->redis->get($key);

            if ($json === false) {
                return null;
            }

            return json_decode($json, true);

        } catch (\Exception $e) {
            return null;
        }
    }

    // =========================================================================
    // BATCH OPERATIONS (for feed/search optimization)
    // =========================================================================

    /**
     * Batch load avatar overlays for multiple users
     *
     * ENTERPRISE: Pipeline for O(1) round-trip regardless of user count
     *
     * @param array $userIds Array of user IDs
     * @return array [userId => avatarUrl, ...]
     */
    public function batchLoadAvatars(array $userIds): array
    {
        if (!$this->redis || empty($userIds)) return [];

        try {
            // Build keys
            $keys = [];
            foreach ($userIds as $userId) {
                $keys[] = "overlay:user:avatar:{$userId}";
            }

            // Pipeline MGET
            $values = $this->redis->mGet($keys);

            $result = [];
            foreach ($userIds as $index => $userId) {
                if (!empty($values[$index])) {
                    $data = json_decode($values[$index], true);
                    if (!empty($data['url'])) {
                        $result[$userId] = $data['url'];
                    }
                }
            }

            return $result;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Batch load profile patches for multiple users
     *
     * @param array $userIds Array of user IDs
     * @return array [userId => ['nickname' => x, ...], ...]
     */
    public function batchLoadProfiles(array $userIds): array
    {
        if (!$this->redis || empty($userIds)) return [];

        try {
            $keys = [];
            foreach ($userIds as $userId) {
                $keys[] = "overlay:user:profile:{$userId}";
            }

            $values = $this->redis->mGet($keys);

            $result = [];
            foreach ($userIds as $index => $userId) {
                if (!empty($values[$index])) {
                    $patch = json_decode($values[$index], true);
                    if (is_array($patch)) {
                        unset($patch['_ts']);
                        $result[$userId] = $patch;
                    }
                }
            }

            return $result;

        } catch (\Exception $e) {
            return [];
        }
    }

    // =========================================================================
    // DIRTY SET FOR FLUSH
    // =========================================================================

    /**
     * Mark a change as dirty for later DB flush
     */
    private function markDirty(int $userId, string $type, array $data): void
    {
        if (!$this->redis) return;

        try {
            $member = json_encode([
                'user_id' => $userId,
                'type' => $type,
                'data' => $data,
                'ts' => microtime(true),
            ], JSON_UNESCAPED_UNICODE);

            $this->redis->zAdd('overlay:dirty:user_settings', microtime(true), $member);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get pending settings changes for flush
     *
     * @param int $limit Max items to retrieve
     * @return array Pending changes
     */
    public function getPendingChanges(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $items = $this->redis->zRange('overlay:dirty:user_settings', 0, $limit - 1);
            $result = [];

            foreach ($items as $json) {
                $data = json_decode($json, true);
                if ($data) {
                    $data['_raw'] = $json;
                    $result[] = $data;
                }
            }

            return $result;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Remove flushed items from dirty set
     *
     * @param array $items Items with '_raw' key
     */
    public function removeFlushedItems(array $items): void
    {
        if (!$this->redis || empty($items)) return;

        try {
            foreach ($items as $item) {
                if (isset($item['_raw'])) {
                    $this->redis->zRem('overlay:dirty:user_settings', $item['_raw']);
                }
            }
        } catch (\Exception $e) {
            Logger::overlay('error', 'UserSettingsOverlayService::removeFlushedItems failed', [
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get buffer status for monitoring
     */
    public function getBufferStatus(): array
    {
        if (!$this->redis) {
            return ['available' => false];
        }

        try {
            return [
                'available' => true,
                'settings_pending' => $this->redis->zCard('overlay:dirty:user_settings'),
            ];
        } catch (\Exception $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clear all overlay data for a user (on account deletion)
     *
     * @param int $userId User ID
     */
    public function clearUserOverlay(int $userId): void
    {
        if (!$this->redis) return;

        try {
            $keys = [
                "overlay:user:profile:{$userId}",
                "overlay:user:avatar:{$userId}",
                "overlay:user:privacy:{$userId}",
                "overlay:user:notifications:{$userId}",
                "overlay:user:tabs:{$userId}",
            ];

            $this->redis->del($keys);

        } catch (\Exception $e) {
            Logger::overlay('error', 'UserSettingsOverlayService::clearUserOverlay failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
