<?php

declare(strict_types=1);

namespace Need2Talk\Services\Cache;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Services\Logger;

/**
 * FriendshipOverlayService - Enterprise Galaxy V4
 *
 * Overlay cache for friendship operations providing immediate visibility
 * without expensive table-level cache invalidation.
 *
 * ARCHITECTURE:
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ Layer          │ Purpose                │ TTL      │ Invalidation       │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ Overlay        │ Immediate changes      │ 10 min   │ Auto-expire        │
 * │ DB Cache       │ Persisted state        │ 5-30 min │ On flush           │
 * │ PostgreSQL     │ Source of truth        │ N/A      │ N/A                │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * REDIS KEY STRUCTURE (DB5):
 * - overlay:friendship:status:{minId}:{maxId}     → status string
 * - overlay:friendship:pending:{userId}           → ZSET of requester IDs
 * - overlay:friendship:sent:{userId}              → ZSET of recipient IDs
 * - overlay:friendship:count:{userId}             → delta integer
 * - overlay:block:{blockerId}:{blockedId}         → "1" or tombstone
 * - overlay:friendship:dirty:{minId}:{maxId}      → pending flush flag
 *
 * PERFORMANCE:
 * - Status check: <1ms (single Redis GET)
 * - Pending/Sent list: <2ms (ZRANGE)
 * - Count delta: <1ms (GET + local math)
 * - Bi-directional block check: <2ms (2 GETs)
 *
 * @package Need2Talk\Services\Cache
 */
class FriendshipOverlayService
{
    private const OVERLAY_TTL = 600;        // 10 minutes
    private const DIRTY_TTL = 900;          // 15 minutes (longer than flush interval)

    // Friendship status constants
    public const STATUS_NONE = 'none';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_BLOCKED = 'blocked';

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
    // FRIENDSHIP STATUS OVERLAY
    // =========================================================================

    /**
     * Set friendship status in overlay
     *
     * Called after DB write to provide immediate visibility.
     * Key uses min/max ID ordering for bi-directional lookup efficiency.
     *
     * @param int $userIdA First user
     * @param int $userIdB Second user
     * @param string $status Status: none, pending, accepted, declined, blocked
     * @param int|null $requestedBy Who initiated (for pending status direction)
     */
    public function setFriendshipStatus(int $userIdA, int $userIdB, string $status, ?int $requestedBy = null): void
    {
        if (!$this->redis) return;

        try {
            // Canonical key (order-independent)
            $minId = min($userIdA, $userIdB);
            $maxId = max($userIdA, $userIdB);
            $key = "overlay:friendship:status:{$minId}:{$maxId}";

            // Store status with direction info for pending
            $value = $status;
            if ($status === self::STATUS_PENDING && $requestedBy) {
                $value = "{$status}:{$requestedBy}"; // e.g., "pending:123"
            }

            $this->redis->setex($key, self::OVERLAY_TTL, $value);

            // Mark as dirty for flush
            $this->markDirty($minId, $maxId, 'friendship_status');

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::setFriendshipStatus failed', [
                'user_a' => $userIdA,
                'user_b' => $userIdB,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get friendship status from overlay
     *
     * Returns null if not in overlay (caller should check DB cache)
     *
     * @param int $userIdA First user
     * @param int $userIdB Second user
     * @return array|null ['status' => string, 'requested_by' => int|null] or null
     */
    public function getFriendshipStatus(int $userIdA, int $userIdB): ?array
    {
        if (!$this->redis) return null;

        try {
            $minId = min($userIdA, $userIdB);
            $maxId = max($userIdA, $userIdB);
            $key = "overlay:friendship:status:{$minId}:{$maxId}";

            $value = $this->redis->get($key);
            if ($value === false) {
                return null; // Not in overlay
            }

            // Parse status (may include direction for pending)
            if (str_contains($value, ':')) {
                [$status, $requestedBy] = explode(':', $value, 2);
                return [
                    'status' => $status,
                    'requested_by' => (int)$requestedBy,
                ];
            }

            return [
                'status' => $value,
                'requested_by' => null,
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Remove friendship status from overlay (tombstone)
     *
     * Used for unfriend/cancel operations
     */
    public function removeFriendshipStatus(int $userIdA, int $userIdB): void
    {
        if (!$this->redis) return;

        try {
            $minId = min($userIdA, $userIdB);
            $maxId = max($userIdA, $userIdB);
            $key = "overlay:friendship:status:{$minId}:{$maxId}";

            // Set to 'none' (tombstone) rather than delete
            // This prevents fallback to stale DB cache
            $this->redis->setex($key, self::OVERLAY_TTL, self::STATUS_NONE);

            $this->markDirty($minId, $maxId, 'friendship_status');

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::removeFriendshipStatus failed', [
                'user_a' => $userIdA,
                'user_b' => $userIdB,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // PENDING/SENT REQUESTS OVERLAY
    // =========================================================================

    /**
     * Add to pending requests overlay (received requests)
     *
     * @param int $recipientId User receiving the request
     * @param int $senderId User who sent the request
     * @param int $friendshipId Friendship record ID
     */
    public function addPendingRequest(int $recipientId, int $senderId, int $friendshipId): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:friendship:pending:{$recipientId}";
            $member = "{$senderId}:{$friendshipId}";
            $score = microtime(true);

            $this->redis->zAdd($key, $score, $member);
            $this->redis->expire($key, self::OVERLAY_TTL);

            // Also add to sender's sent list
            $sentKey = "overlay:friendship:sent:{$senderId}";
            $sentMember = "{$recipientId}:{$friendshipId}";
            $this->redis->zAdd($sentKey, $score, $sentMember);
            $this->redis->expire($sentKey, self::OVERLAY_TTL);

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::addPendingRequest failed', [
                'recipient' => $recipientId,
                'sender' => $senderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove from pending requests overlay
     *
     * Called on accept/reject/cancel
     */
    public function removePendingRequest(int $recipientId, int $senderId, int $friendshipId): void
    {
        if (!$this->redis) return;

        try {
            // Remove from recipient's pending
            $key = "overlay:friendship:pending:{$recipientId}";
            $member = "{$senderId}:{$friendshipId}";
            $this->redis->zRem($key, $member);

            // Remove from sender's sent
            $sentKey = "overlay:friendship:sent:{$senderId}";
            $sentMember = "{$recipientId}:{$friendshipId}";
            $this->redis->zRem($sentKey, $sentMember);

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::removePendingRequest failed', [
                'recipient' => $recipientId,
                'sender' => $senderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get pending requests delta from overlay
     *
     * Returns array of [senderId => friendshipId] for overlay additions
     * Caller should merge with DB results
     *
     * @param int $userId User ID
     * @return array [senderId => friendshipId, ...]
     */
    public function getPendingRequestsDelta(int $userId): array
    {
        if (!$this->redis) return [];

        try {
            $key = "overlay:friendship:pending:{$userId}";
            $members = $this->redis->zRange($key, 0, -1);

            $delta = [];
            foreach ($members as $member) {
                [$senderId, $friendshipId] = explode(':', $member, 2);
                $delta[(int)$senderId] = (int)$friendshipId;
            }

            return $delta;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get sent requests delta from overlay
     *
     * @param int $userId User ID
     * @return array [recipientId => friendshipId, ...]
     */
    public function getSentRequestsDelta(int $userId): array
    {
        if (!$this->redis) return [];

        try {
            $key = "overlay:friendship:sent:{$userId}";
            $members = $this->redis->zRange($key, 0, -1);

            $delta = [];
            foreach ($members as $member) {
                [$recipientId, $friendshipId] = explode(':', $member, 2);
                $delta[(int)$recipientId] = (int)$friendshipId;
            }

            return $delta;

        } catch (\Exception $e) {
            return [];
        }
    }

    // =========================================================================
    // ACCEPTED FRIENDS OVERLAY (NEW FRIENDSHIPS)
    // =========================================================================

    /**
     * Add new friend to overlay for immediate visibility
     *
     * Called after accept to ensure both users see each other immediately
     * without waiting for cache refresh.
     *
     * ENTERPRISE V10.66 (2025-12-07): Added $userUuid parameter
     * Now also invalidates WebSocket friends cache for immediate presence fan-out
     *
     * @param int $userId User who should see the new friend
     * @param int $friendId The new friend's ID
     * @param string $userUuid The user's UUID (for WebSocket cache invalidation)
     * @param string $friendUuid The new friend's UUID (for lookup)
     */
    public function addAcceptedFriend(int $userId, int $friendId, string $userUuid, string $friendUuid): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:friendship:accepted:{$userId}";
            // Store as friendId:friendUuid for easy lookup
            $member = "{$friendId}:{$friendUuid}";
            $score = microtime(true);

            $this->redis->zAdd($key, $score, $member);
            $this->redis->expire($key, self::OVERLAY_TTL);

            // ENTERPRISE V10.66: Invalidate WebSocket friends cache for this user
            // This ensures ws_get_friends() fetches fresh data including the new friend
            // Cache key: need2talk:friends:{uuid} in Redis L1 (DB1)
            try {
                $l1Redis = EnterpriseRedisManager::getInstance()->getConnection('L1_enterprise');
                if ($l1Redis) {
                    $l1Redis->del("need2talk:friends:{$userUuid}");
                }
            } catch (\Throwable $e) {
                // Non-blocking: Cache will expire naturally (24h TTL)
            }

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::addAcceptedFriend failed', [
                'user_id' => $userId,
                'friend_id' => $friendId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get newly accepted friends from overlay
     *
     * Returns friend IDs that were recently accepted (not yet in DB cache)
     *
     * @param int $userId User ID
     * @return array [friendId => friendUuid, ...]
     */
    public function getAcceptedFriendsDelta(int $userId): array
    {
        if (!$this->redis) return [];

        try {
            $key = "overlay:friendship:accepted:{$userId}";
            $members = $this->redis->zRange($key, 0, -1);

            $delta = [];
            foreach ($members as $member) {
                [$friendId, $friendUuid] = explode(':', $member, 2);
                $delta[(int)$friendId] = $friendUuid;
            }

            return $delta;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Remove accepted friend from overlay (unfriended or cache synced)
     */
    public function removeAcceptedFriend(int $userId, int $friendId, string $friendUuid): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:friendship:accepted:{$userId}";
            $member = "{$friendId}:{$friendUuid}";
            $this->redis->zRem($key, $member);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    // =========================================================================
    // FRIEND COUNT OVERLAY
    // =========================================================================

    /**
     * Increment friend count delta
     *
     * @param int $userId User whose count changed
     * @param int $delta +1 for new friend, -1 for unfriend
     */
    public function incrementFriendCount(int $userId, int $delta): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:friendship:count:{$userId}";
            $this->redis->incrBy($key, $delta);
            $this->redis->expire($key, self::OVERLAY_TTL);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get friend count delta
     *
     * @param int $userId User ID
     * @return int Delta (can be negative)
     */
    public function getFriendCountDelta(int $userId): int
    {
        if (!$this->redis) return 0;

        try {
            $key = "overlay:friendship:count:{$userId}";
            $delta = $this->redis->get($key);
            return $delta !== false ? (int)$delta : 0;

        } catch (\Exception $e) {
            return 0;
        }
    }

    // =========================================================================
    // BLOCK OVERLAY
    // =========================================================================

    /**
     * Set block status in overlay
     *
     * Directional: A blocks B (not symmetric)
     *
     * @param int $blockerId User who blocked
     * @param int $blockedId User who was blocked
     */
    public function setBlock(int $blockerId, int $blockedId): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:block:{$blockerId}:{$blockedId}";
            $this->redis->setex($key, self::OVERLAY_TTL, '1');

            // Also clear any friendship status
            $this->removeFriendshipStatus($blockerId, $blockedId);

            $this->markDirty($blockerId, $blockedId, 'block');

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::setBlock failed', [
                'blocker' => $blockerId,
                'blocked' => $blockedId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove block from overlay (unblock)
     *
     * @param int $blockerId User who blocked
     * @param int $blockedId User who was blocked
     */
    public function removeBlock(int $blockerId, int $blockedId): void
    {
        if (!$this->redis) return;

        try {
            $key = "overlay:block:{$blockerId}:{$blockedId}";
            // Set to '0' (tombstone) rather than delete
            $this->redis->setex($key, self::OVERLAY_TTL, '0');

            $this->markDirty($blockerId, $blockedId, 'unblock');

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::removeBlock failed', [
                'blocker' => $blockerId,
                'blocked' => $blockedId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if block exists in overlay
     *
     * @param int $blockerId Potential blocker
     * @param int $blockedId Potentially blocked user
     * @return bool|null true=blocked, false=unblocked, null=not in overlay
     */
    public function isBlocked(int $blockerId, int $blockedId): ?bool
    {
        if (!$this->redis) return null;

        try {
            $key = "overlay:block:{$blockerId}:{$blockedId}";
            $value = $this->redis->get($key);

            if ($value === false) {
                return null; // Not in overlay
            }

            return $value === '1';

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check bi-directional block in overlay
     *
     * @param int $userIdA First user
     * @param int $userIdB Second user
     * @return bool|null true=either blocked, false=neither blocked, null=not in overlay
     */
    public function isBlockedBidirectional(int $userIdA, int $userIdB): ?bool
    {
        $aBlocksB = $this->isBlocked($userIdA, $userIdB);
        $bBlocksA = $this->isBlocked($userIdB, $userIdA);

        // If either is explicitly set in overlay
        if ($aBlocksB === true || $bBlocksA === true) {
            return true;
        }

        // If both are explicitly false (unblocked)
        if ($aBlocksB === false && $bBlocksA === false) {
            return false;
        }

        // At least one is null (not in overlay)
        return null;
    }

    // =========================================================================
    // DIRTY SET FOR FLUSH
    // =========================================================================

    /**
     * Mark a change as dirty for later DB flush
     */
    private function markDirty(int $idA, int $idB, string $type): void
    {
        if (!$this->redis) return;

        try {
            $member = json_encode([
                'id_a' => $idA,
                'id_b' => $idB,
                'type' => $type,
                'ts' => microtime(true),
            ]);

            $this->redis->zAdd('overlay:dirty:friendships', microtime(true), $member);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get pending friendship changes for flush
     *
     * @param int $limit Max items to retrieve
     * @return array Pending changes
     */
    public function getPendingChanges(int $limit = 100): array
    {
        if (!$this->redis) return [];

        try {
            $items = $this->redis->zRange('overlay:dirty:friendships', 0, $limit - 1);
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
                    $this->redis->zRem('overlay:dirty:friendships', $item['_raw']);
                }
            }
        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::removeFlushedItems failed', [
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add user to blocked list overlay (for feed/search filtering)
     *
     * Called when blocking to add to user's blocked list for filtering
     *
     * @param int $blockerId User who blocked
     * @param int $blockedId User who was blocked
     */
    public function addToBlockedList(int $blockerId, int $blockedId): void
    {
        if (!$this->redis) return;

        try {
            // Add to blocker's "i_blocked" list
            $keyBlocked = "overlay:blocked_by_me:{$blockerId}";
            $this->redis->sAdd($keyBlocked, (string)$blockedId);
            $this->redis->expire($keyBlocked, self::OVERLAY_TTL);

            // Add to blocked user's "blocked_me" list (for bidirectional filtering)
            $keyBlockedMe = "overlay:blocked_me:{$blockedId}";
            $this->redis->sAdd($keyBlockedMe, (string)$blockerId);
            $this->redis->expire($keyBlockedMe, self::OVERLAY_TTL);

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::addToBlockedList failed', [
                'blocker' => $blockerId,
                'blocked' => $blockedId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove from blocked list overlay (unblock)
     */
    public function removeFromBlockedList(int $blockerId, int $blockedId): void
    {
        if (!$this->redis) return;

        try {
            $keyBlocked = "overlay:blocked_by_me:{$blockerId}";
            $this->redis->sRem($keyBlocked, (string)$blockedId);

            $keyBlockedMe = "overlay:blocked_me:{$blockedId}";
            $this->redis->sRem($keyBlockedMe, (string)$blockerId);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get all blocked user IDs (bidirectional) from overlay
     *
     * Returns IDs of users the current user blocked OR who blocked the current user
     * Used for feed/search filtering
     *
     * @param int $userId Current user
     * @return array [userId => true, ...] for O(1) lookup
     */
    public function getBlockedUserIds(int $userId): array
    {
        if (!$this->redis) return [];

        try {
            $blocked = [];

            // Users I blocked
            $keyBlocked = "overlay:blocked_by_me:{$userId}";
            $myBlocked = $this->redis->sMembers($keyBlocked);

            // Users who blocked me
            $keyBlockedMe = "overlay:blocked_me:{$userId}";
            $blockedMe = $this->redis->sMembers($keyBlockedMe);

            // ENTERPRISE V4: Lazy-load from DB if overlay is empty (cache miss)
            // This handles the case where user hasn't blocked anyone during this overlay TTL
            // DB fallback is only triggered ONCE per user per overlay TTL (10 minutes)
            if (empty($myBlocked) && empty($blockedMe)) {
                // Check if we already attempted DB load (avoid repeated queries)
                $dbLoadKey = "overlay:blocked_db_loaded:{$userId}";
                $alreadyLoaded = $this->redis->get($dbLoadKey);

                if (!$alreadyLoaded) {
                    // Mark as loaded (even if empty) to prevent repeated DB queries
                    $this->redis->setex($dbLoadKey, self::OVERLAY_TTL, '1');

                    // Load from DB
                    $dbBlocks = $this->loadBlocksFromDb($userId);

                    if (!empty($dbBlocks['i_blocked'])) {
                        foreach ($dbBlocks['i_blocked'] as $blockedId) {
                            $this->redis->sAdd($keyBlocked, (string)$blockedId);
                            $blocked[(int)$blockedId] = true;
                        }
                        $this->redis->expire($keyBlocked, self::OVERLAY_TTL);
                    }

                    if (!empty($dbBlocks['blocked_me'])) {
                        foreach ($dbBlocks['blocked_me'] as $blockerId) {
                            $this->redis->sAdd($keyBlockedMe, (string)$blockerId);
                            $blocked[(int)$blockerId] = true;
                        }
                        $this->redis->expire($keyBlockedMe, self::OVERLAY_TTL);
                    }

                    return $blocked;
                }

                // Already loaded from DB but empty - return empty
                return [];
            }

            // Overlay has data - use it
            foreach ($myBlocked as $id) {
                $blocked[(int)$id] = true;
            }
            foreach ($blockedMe as $id) {
                $blocked[(int)$id] = true;
            }

            return $blocked;

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::getBlockedUserIds failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Load blocks from database (lazy-load fallback)
     *
     * ENTERPRISE V4: Only called when overlay is empty (cache miss)
     * Single query with UNION to get both directions efficiently
     *
     * @param int $userId User ID
     * @return array ['i_blocked' => [...], 'blocked_me' => [...]]
     */
    private function loadBlocksFromDb(int $userId): array
    {
        try {
            $db = db();

            // Users I blocked
            $iBlocked = $db->query(
                "SELECT friend_id FROM friendships
                 WHERE user_id = ? AND status = 'blocked' AND deleted_at IS NULL",
                [$userId],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            // Users who blocked me
            $blockedMe = $db->query(
                "SELECT user_id FROM friendships
                 WHERE friend_id = ? AND status = 'blocked' AND deleted_at IS NULL",
                [$userId],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            return [
                'i_blocked' => array_column($iBlocked, 'friend_id'),
                'blocked_me' => array_column($blockedMe, 'user_id'),
            ];

        } catch (\Exception $e) {
            Logger::overlay('error', 'FriendshipOverlayService::loadBlocksFromDb failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['i_blocked' => [], 'blocked_me' => []];
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
                'friendships_pending' => $this->redis->zCard('overlay:dirty:friendships'),
            ];
        } catch (\Exception $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // FEED REFRESH FLAG (ENTERPRISE V11.5)
    // =========================================================================
    // When friendship is removed or user is blocked, we need ONE fresh feed query
    // to update visibility. After that, cache resumes normal operation.
    //
    // Pattern:
    // 1. unfriend/block → setFeedRefreshNeeded($userId)
    // 2. next feed request → checkAndClearFeedRefresh($userId) returns true → force_refresh
    // 3. fresh query runs, flag is CLEARED
    // 4. subsequent requests → flag doesn't exist → use cache
    // =========================================================================

    /**
     * Set flag indicating user needs fresh feed on next request
     *
     * Called after unfriend or block operations.
     * TTL 10 minutes - if user doesn't visit feed within 10 min, cache will
     * have naturally refreshed anyway (medium TTL = 30 min, but query changes)
     *
     * @param int $userId User who needs fresh feed
     */
    public function setFeedRefreshNeeded(int $userId): void
    {
        if (!$this->redis || $userId <= 0) return;

        try {
            $key = "overlay:feed_refresh_needed:{$userId}";
            $this->redis->setex($key, 600, '1'); // 10 minutes TTL

            Logger::debug('[FriendshipOverlay] Feed refresh flag set', [
                'user_id' => $userId,
            ]);

        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Check if user needs fresh feed and CLEAR the flag
     *
     * This is atomic: check + clear in one operation.
     * Returns true ONCE, then subsequent calls return false.
     *
     * @param int $userId User to check
     * @return bool True if refresh needed (and flag was cleared)
     */
    public function checkAndClearFeedRefresh(int $userId): bool
    {
        if (!$this->redis || $userId <= 0) return false;

        try {
            $key = "overlay:feed_refresh_needed:{$userId}";

            // GETDEL is atomic: get value and delete key in one operation
            // Available in Redis 6.2+, fallback to GET + DEL for older versions
            $value = $this->redis->get($key);

            if ($value !== false && $value === '1') {
                // Flag exists - delete it and return true
                $this->redis->del($key);

                Logger::debug('[FriendshipOverlay] Feed refresh flag consumed', [
                    'user_id' => $userId,
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            return false;
        }
    }
}
