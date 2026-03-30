<?php

declare(strict_types=1);

namespace Need2Talk\Contracts\Publisher;

/**
 * Event Publisher Interface - Real-time Event Distribution
 *
 * This interface abstracts WebSocket event publishing, allowing services
 * to broadcast events without coupling to the specific implementation.
 *
 * In PHP-FPM context: Uses WebSocketPublisher (Redis PubSub to Swoole)
 * In Swoole context: Direct broadcast to connected clients
 *
 * Event Flow:
 * 1. Service calls publishToUser() / publishToRoom()
 * 2. In PHP-FPM: Message published to Redis channel
 * 3. Swoole WebSocket server receives via PubSub subscription
 * 4. Server broadcasts to connected clients
 *
 * @package Need2Talk\Contracts\Publisher
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
interface EventPublisherInterface
{
    // ========================================================================
    // USER-TARGETED EVENTS
    // ========================================================================

    /**
     * Publish an event to a specific user
     *
     * Typical events:
     * - friend_request_received
     * - dm_received
     * - notification
     * - mi_ha_cercato (user was searched while DND)
     *
     * @param string $userUuid Target user's UUID
     * @param string $event Event type
     * @param array $data Event payload
     * @return bool True if successfully queued
     */
    public function publishToUser(string $userUuid, string $event, array $data): bool;

    // ========================================================================
    // ROOM-TARGETED EVENTS
    // ========================================================================

    /**
     * Publish an event to all users in a chat room
     *
     * Typical events:
     * - room_message (new message in room)
     * - user_joined (user entered room)
     * - user_left (user left room)
     * - typing_indicator
     *
     * @param string $roomId Room identifier (e.g., "emotion:joy", "room:uuid")
     * @param string $event Event type
     * @param array $data Event payload
     * @return bool True if successfully queued
     */
    public function publishToRoom(string $roomId, string $event, array $data): bool;

    // ========================================================================
    // CONVERSATION-TARGETED EVENTS (DM)
    // ========================================================================

    /**
     * Publish an event to a direct message conversation
     *
     * Typical events:
     * - dm_message (new DM)
     * - dm_read (message read receipt)
     * - typing_indicator
     *
     * @param string $conversationUuid Conversation UUID
     * @param string $event Event type
     * @param array $data Event payload
     * @return bool True if successfully queued
     */
    public function publishToConversation(string $conversationUuid, string $event, array $data): bool;

    // ========================================================================
    // PRESENCE EVENTS
    // ========================================================================

    /**
     * Publish a presence update (online status change)
     *
     * Status values:
     * - online: User is active
     * - away: User is idle
     * - dnd: Do Not Disturb
     * - invisible: Appear offline
     * - offline: User disconnected
     *
     * @param string $userUuid User's UUID
     * @param string $status New status
     * @param array $meta Additional metadata (device, location, etc.)
     * @return bool True if successfully queued
     */
    public function publishPresenceUpdate(string $userUuid, string $status, array $meta = []): bool;

    // ========================================================================
    // TYPING INDICATORS
    // ========================================================================

    /**
     * Publish typing indicator for a room
     *
     * @param string $roomId Room identifier
     * @param string $userUuid User who is typing
     * @param bool $isTyping True if started typing, false if stopped
     * @return bool True if successfully queued
     */
    public function publishTypingRoom(string $roomId, string $userUuid, bool $isTyping): bool;

    /**
     * Publish typing indicator for a DM conversation
     *
     * @param string $conversationUuid Conversation UUID
     * @param string $userUuid User who is typing
     * @param string $recipientUuid Recipient to notify
     * @param bool $isTyping True if started typing, false if stopped
     * @return bool True if successfully queued
     */
    public function publishTypingDM(string $conversationUuid, string $userUuid, string $recipientUuid, bool $isTyping): bool;

    // ========================================================================
    // READ RECEIPTS
    // ========================================================================

    /**
     * Publish read receipt for a DM conversation
     *
     * @param string $conversationUuid Conversation UUID
     * @param string $readerUuid UUID of user who read messages
     * @param string|null $lastMessageUuid UUID of last read message (optional)
     * @return bool True if successfully queued
     */
    public function publishReadReceipt(string $conversationUuid, string $readerUuid, ?string $lastMessageUuid = null): bool;
}
