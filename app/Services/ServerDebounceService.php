<?php

declare(strict_types=1);

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;

/**
 * ServerDebounceService - Enterprise Galaxy V10.2 (2025-12-10)
 *
 * Server-side debouncing for high-frequency actions to prevent:
 * - Click spam / double-click issues
 * - API abuse from malfunctioning clients
 * - Unnecessary database writes
 * - Resource exhaustion attacks
 *
 * ARCHITECTURE:
 * ┌─────────────────────────────────────────────────────────────────────────────┐
 * │ Client clicks "Play" rapidly 10 times in 500ms                             │
 * │                                                                             │
 * │ WITHOUT DEBOUNCE:                                                           │
 * │   → 10 API calls → 10 DB queries → 10 WebSocket broadcasts                 │
 * │   → CPU spike, DB contention, wasted resources                             │
 * │                                                                             │
 * │ WITH SERVER DEBOUNCE:                                                       │
 * │   → 10 API calls → 1st accepted, 9 rejected (429) → 1 DB query             │
 * │   → Clean, efficient, no resource waste                                    │
 * ├─────────────────────────────────────────────────────────────────────────────┤
 * │ Redis Key: debounce:{action}:{user_id}:{entity_id}                         │
 * │ TTL: Configurable per action (default: 500ms - 2s)                         │
 * │ Pattern: SET NX EX (atomic, no Lua needed for simple case)                 │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * USE CASES:
 * - Audio play: Prevent counting same play multiple times
 * - Reactions: Prevent rapid toggle spam
 * - Comments: Prevent double-posting
 * - Form submissions: Prevent duplicate submissions
 *
 * @package Need2Talk\Services
 */
class ServerDebounceService
{
    // =========================================================================
    // CONFIGURATION - Debounce windows per action type
    // =========================================================================

    /**
     * Debounce configurations per action
     *
     * Format: 'action' => [
     *     'window_ms' => milliseconds to debounce,
     *     'per_entity' => true if debounce is per entity (e.g., per post), false if per user only
     * ]
     */
    private const DEBOUNCE_CONFIG = [
        // Audio player actions
        'audio_play' => [
            'window_ms' => 1000,      // 1 second between plays of same audio
            'per_entity' => true,     // Per audio file
        ],
        'audio_pause' => [
            'window_ms' => 200,       // 200ms between pause clicks
            'per_entity' => true,
        ],
        'audio_seek' => [
            'window_ms' => 100,       // 100ms between seeks (allows smooth dragging)
            'per_entity' => true,
        ],

        // Reaction actions
        'reaction_add' => [
            'window_ms' => 1000,      // 1 second between reactions on same post
            'per_entity' => true,
        ],
        'reaction_remove' => [
            'window_ms' => 1000,      // 1 second between reaction removals
            'per_entity' => true,
        ],

        // Comment actions
        'comment_submit' => [
            'window_ms' => 2000,      // 2 seconds between comments (prevents double-post)
            'per_entity' => true,     // Per post
        ],
        'comment_like' => [
            'window_ms' => 500,       // 500ms between comment likes
            'per_entity' => true,     // Per comment
        ],

        // Social actions
        'follow' => [
            'window_ms' => 1000,      // 1 second between follow/unfollow
            'per_entity' => true,     // Per target user
        ],
        'friend_request' => [
            'window_ms' => 2000,      // 2 seconds between friend requests
            'per_entity' => true,
        ],

        // Form submissions
        'form_submit' => [
            'window_ms' => 3000,      // 3 seconds between form submissions
            'per_entity' => false,    // Per user globally
        ],

        // Chat actions
        'dm_send' => [
            'window_ms' => 200,       // 200ms between DM sends (allows fast typing)
            'per_entity' => true,     // Per conversation
        ],
        'dm_audio_send' => [
            'window_ms' => 2000,      // 2 seconds between audio DMs
            'per_entity' => true,
        ],

        // Profile actions
        'profile_update' => [
            'window_ms' => 1000,      // 1 second between profile updates
            'per_entity' => false,    // Per user globally
        ],
    ];

    // =========================================================================
    // SINGLETON
    // =========================================================================

    private static ?self $instance = null;
    private ?\Redis $redis = null;
    private bool $available = false;

    private function __construct()
    {
        try {
            $this->redis = EnterpriseRedisManager::getInstance()->getConnection('rate_limit');
            $this->available = ($this->redis !== null);
        } catch (\Exception $e) {
            Logger::warning('ServerDebounceService: Redis unavailable', [
                'error' => $e->getMessage(),
            ]);
            $this->available = false;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Check if action is allowed (not debounced)
     *
     * USAGE:
     * ```php
     * $debounce = ServerDebounceService::getInstance();
     *
     * // Check if play is allowed for this user on this audio
     * if (!$debounce->isAllowed('audio_play', $userId, $audioFileId)) {
     *     return $this->json(['error' => 'Too fast, please wait'], 429);
     * }
     *
     * // Action is allowed, proceed...
     * ```
     *
     * @param string $action Action type (must exist in DEBOUNCE_CONFIG)
     * @param int|string $userId User performing the action
     * @param int|string|null $entityId Entity being acted upon (post_id, audio_id, etc.)
     * @return bool True if action is allowed, false if debounced
     */
    public function isAllowed(string $action, $userId, $entityId = null): bool
    {
        // Fail-open if Redis unavailable
        if (!$this->available || !$this->redis) {
            return true;
        }

        // Get config for this action
        $config = self::DEBOUNCE_CONFIG[$action] ?? null;
        if ($config === null) {
            // Unknown action, allow by default
            Logger::debug('ServerDebounceService: Unknown action', [
                'action' => $action,
                'user_id' => $userId,
            ]);
            return true;
        }

        // Build Redis key
        $key = $this->buildKey($action, $userId, $config['per_entity'] ? $entityId : null);

        try {
            // Convert milliseconds to seconds for Redis (minimum 1 second)
            $ttlSeconds = max(1, (int) ceil($config['window_ms'] / 1000));

            // Atomic SET NX EX - sets key only if not exists, with TTL
            // Returns TRUE if key was set (action allowed), FALSE if key exists (debounced)
            $result = $this->redis->set($key, '1', ['NX', 'EX' => $ttlSeconds]);

            if ($result === false) {
                // Key exists = action was recently performed = debounced
                Logger::debug('ServerDebounceService: Action debounced', [
                    'action' => $action,
                    'user_id' => $userId,
                    'entity_id' => $entityId,
                    'window_ms' => $config['window_ms'],
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            // Fail-open on Redis errors
            Logger::warning('ServerDebounceService: Redis error, allowing action', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Check and record action in one call
     *
     * Convenience method that returns debounce result and records if allowed
     *
     * @param string $action Action type
     * @param int|string $userId User ID
     * @param int|string|null $entityId Entity ID
     * @return array ['allowed' => bool, 'retry_after_ms' => int|null]
     */
    public function checkAndRecord(string $action, $userId, $entityId = null): array
    {
        $allowed = $this->isAllowed($action, $userId, $entityId);

        if (!$allowed) {
            $config = self::DEBOUNCE_CONFIG[$action] ?? ['window_ms' => 1000];
            $retryAfterMs = $this->getRetryAfter($action, $userId, $entityId);

            return [
                'allowed' => false,
                'retry_after_ms' => $retryAfterMs ?? $config['window_ms'],
                'reason' => 'debounced',
            ];
        }

        return [
            'allowed' => true,
            'retry_after_ms' => null,
        ];
    }

    /**
     * Get remaining debounce time in milliseconds
     *
     * @param string $action Action type
     * @param int|string $userId User ID
     * @param int|string|null $entityId Entity ID
     * @return int|null Milliseconds until retry allowed, null if not debounced
     */
    public function getRetryAfter(string $action, $userId, $entityId = null): ?int
    {
        if (!$this->available || !$this->redis) {
            return null;
        }

        $config = self::DEBOUNCE_CONFIG[$action] ?? null;
        if ($config === null) {
            return null;
        }

        $key = $this->buildKey($action, $userId, $config['per_entity'] ? $entityId : null);

        try {
            // PTTL returns milliseconds remaining, -2 if key doesn't exist, -1 if no TTL
            $pttl = $this->redis->pttl($key);

            if ($pttl > 0) {
                return (int) $pttl;
            }

            return null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Clear debounce for specific action (admin/testing)
     *
     * @param string $action Action type
     * @param int|string $userId User ID
     * @param int|string|null $entityId Entity ID
     * @return bool Success
     */
    public function clearDebounce(string $action, $userId, $entityId = null): bool
    {
        if (!$this->available || !$this->redis) {
            return false;
        }

        $config = self::DEBOUNCE_CONFIG[$action] ?? ['per_entity' => true];
        $key = $this->buildKey($action, $userId, $config['per_entity'] ? $entityId : null);

        try {
            return (bool) $this->redis->del($key);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get debounce config for action (for client info)
     *
     * @param string $action Action type
     * @return array|null Config or null if unknown action
     */
    public function getConfig(string $action): ?array
    {
        return self::DEBOUNCE_CONFIG[$action] ?? null;
    }

    /**
     * Check if service is available
     *
     * @return bool True if Redis connection is available
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Build Redis key for debounce
     *
     * Format: debounce:{action}:{user_id}:{entity_id}
     * Or:     debounce:{action}:{user_id} (if no entity)
     */
    private function buildKey(string $action, $userId, $entityId = null): string
    {
        $key = "debounce:{$action}:{$userId}";

        if ($entityId !== null) {
            $key .= ":{$entityId}";
        }

        return $key;
    }
}
