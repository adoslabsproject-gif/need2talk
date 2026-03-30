<?php

declare(strict_types=1);

namespace Need2Talk\Adapters\Publisher;

use Need2Talk\Contracts\Publisher\EventPublisherInterface;
use Need2Talk\Contracts\Redis\RedisAdapterInterface;

/**
 * SwoolePublisherAdapter - Lightweight Event Publisher for Swoole WebSocket Context
 *
 * Unlike WebSocketPublisherAdapter (PHP-FPM), this adapter is designed for
 * the Swoole WebSocket server where:
 * - Broadcasting is done directly via Swoole\Server
 * - No database access (minimal bootstrap)
 * - PubSub is used for external events only
 *
 * ARCHITECTURE DECISION:
 * In Swoole context, the WebSocket server handles broadcasting directly via
 * Swoole\Table for room membership and $server->push() for delivery.
 * This adapter is primarily used for:
 * 1. Events that need Redis PubSub (cross-server scaling)
 * 2. Presence updates stored in Redis
 * 3. Typing indicators stored in Redis
 *
 * For local broadcasts (same server), use the server's broadcastToRoom() directly.
 *
 * @package Need2Talk\Adapters\Publisher
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class SwoolePublisherAdapter implements EventPublisherInterface
{
    private RedisAdapterInterface $redis;

    public function __construct(RedisAdapterInterface $redis)
    {
        $this->redis = $redis;
    }

    // ========================================================================
    // USER-TARGETED EVENTS
    // ========================================================================

    public function publishToUser(string $userUuid, string $event, array $data): bool
    {
        $message = $this->buildMessage("user:{$userUuid}", $event, $data);
        $pubsubChannel = "websocket:events:{$userUuid}";

        return $this->redis->publish($pubsubChannel, $message) !== false;
    }

    // ========================================================================
    // ROOM-TARGETED EVENTS
    // ========================================================================

    public function publishToRoom(string $roomId, string $event, array $data): bool
    {
        // Room broadcasts in Swoole should use server->broadcastToRoom() for local delivery
        // This publishes to Redis for cross-server scenarios
        $message = $this->buildMessage("room:{$roomId}", $event, $data);
        $pubsubChannel = "websocket:events:room:{$roomId}";

        return $this->redis->publish($pubsubChannel, $message) !== false;
    }

    // ========================================================================
    // CONVERSATION-TARGETED EVENTS (DM)
    // ========================================================================

    public function publishToConversation(string $conversationUuid, string $event, array $data): bool
    {
        $message = $this->buildMessage("conversation:{$conversationUuid}", $event, $data);
        $pubsubChannel = "websocket:events:conv:{$conversationUuid}";

        return $this->redis->publish($pubsubChannel, $message) !== false;
    }

    // ========================================================================
    // PRESENCE EVENTS
    // ========================================================================

    public function publishPresenceUpdate(string $userUuid, string $status, array $meta = []): bool
    {
        // ENTERPRISE V9.5: Publish presence update to user's channel
        $message = $this->buildMessage("presence:{$userUuid}", 'presence_update', [
            'user_uuid' => $userUuid,
            'status' => $status,
            'meta' => $meta,
            'timestamp' => microtime(true),
        ]);

        $pubsubChannel = "websocket:events:{$userUuid}";
        $published = $this->redis->publish($pubsubChannel, $message) !== false;

        // ENTERPRISE V9.5: Fan-out to friends from Redis cache
        // Friends list is cached at login with key: need2talk:friends:{uuid}
        // This enables presence notifications even in Swoole context (no DB access)
        $this->fanOutToFriendsFromCache($userUuid, $status, $meta);

        return $published;
    }

    /**
     * Fan out presence update to friends using cached friend list
     *
     * ARCHITECTURE V9.5:
     * - Friend list is cached in Redis at login (PHP-FPM caches it)
     * - Swoole reads from cache to avoid database access
     * - If cache miss, friends won't be notified (they'll sync on next poll)
     *
     * @param string $userUuid User whose status changed
     * @param string $status New status
     * @param array $meta Additional metadata
     */
    private function fanOutToFriendsFromCache(string $userUuid, string $status, array $meta): void
    {
        try {
            // Read cached friend list
            // ENTERPRISE V10.10 FIX: Friends cache is stored in L1_cache (DB 0), not chat (DB 6)
            // AuthController stores with EnterpriseRedisManager->getConnection('L1_enterprise')
            // which defaults to L1_cache (DB 0)
            $cacheKey = "need2talk:friends:{$userUuid}";
            $cached = $this->redis->get($cacheKey, 'L1_cache');

            if (!$cached) {
                // Cache miss - friends won't be notified in real-time
                // They will sync on next presence poll or page load
                return;
            }

            $friendUuids = json_decode($cached, true);
            if (!is_array($friendUuids) || empty($friendUuids)) {
                return;
            }

            // Build friend_presence_changed payload
            $payload = [
                'user_uuid' => $userUuid,
                'status' => $status,
                'is_online' => in_array($status, ['online', 'away', 'dnd']),
                'meta' => $meta,
                'timestamp' => microtime(true),
            ];

            // Publish to each friend's personal channel
            foreach ($friendUuids as $friendUuid) {
                if ($friendUuid && $friendUuid !== $userUuid) {
                    $friendMessage = $this->buildMessage(
                        "user:{$friendUuid}",
                        'friend_presence_changed',
                        $payload
                    );
                    $friendChannel = "websocket:events:{$friendUuid}";
                    $this->redis->publish($friendChannel, $friendMessage);
                }
            }

        } catch (\Throwable $e) {
            // Non-critical - log and continue
            // Friends will sync on next poll
        }
    }

    // ========================================================================
    // TYPING INDICATORS
    // ========================================================================

    public function publishTypingRoom(string $roomId, string $userUuid, bool $isTyping): bool
    {
        $message = $this->buildMessage("room:{$roomId}", 'typing_indicator', [
            'target_id' => $roomId,
            'target_type' => 'room',
            'user_uuid' => $userUuid,
            'is_typing' => $isTyping,
            'timestamp' => microtime(true),
        ]);

        $pubsubChannel = "websocket:events:room:{$roomId}";

        return $this->redis->publish($pubsubChannel, $message) !== false;
    }

    public function publishTypingDM(string $conversationUuid, string $userUuid, string $recipientUuid, bool $isTyping): bool
    {
        // Publish to recipient's personal channel
        $message = $this->buildMessage("user:{$recipientUuid}", 'typing_indicator', [
            'target_id' => $conversationUuid,
            'target_type' => 'dm',
            'user_uuid' => $userUuid,
            'is_typing' => $isTyping,
            'timestamp' => microtime(true),
        ]);

        $pubsubChannel = "websocket:events:{$recipientUuid}";

        return $this->redis->publish($pubsubChannel, $message) !== false;
    }

    // ========================================================================
    // READ RECEIPTS
    // ========================================================================

    public function publishReadReceipt(string $conversationUuid, string $readerUuid, ?string $lastMessageUuid = null): bool
    {
        if ($lastMessageUuid === null) {
            return false;
        }

        $message = $this->buildMessage("conversation:{$conversationUuid}", 'read_receipt', [
            'conversation_uuid' => $conversationUuid,
            'reader_uuid' => $readerUuid,
            'last_message_uuid' => $lastMessageUuid,
            'read_at' => microtime(true),
        ]);

        $pubsubChannel = "websocket:events:conv:{$conversationUuid}";

        return $this->redis->publish($pubsubChannel, $message) !== false;
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * Build standardized message payload
     *
     * @param string $channel Target channel
     * @param string $event Event name
     * @param array $data Event data
     * @return string JSON-encoded message
     */
    private function buildMessage(string $channel, string $event, array $data): string
    {
        return json_encode([
            'channel' => $channel,
            'event' => $event,
            'data' => $data,
            'timestamp' => microtime(true),
        ], JSON_THROW_ON_ERROR);
    }
}
