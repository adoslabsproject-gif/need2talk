<?php

declare(strict_types=1);

namespace Need2Talk\Adapters\Publisher;

use Need2Talk\Contracts\Publisher\EventPublisherInterface;
use Need2Talk\Services\WebSocketPublisher;

/**
 * WebSocketPublisherAdapter - Event Publisher for PHP-FPM Context
 *
 * Wraps the static WebSocketPublisher class, adapting it to the
 * EventPublisherInterface for dependency injection.
 *
 * Features:
 * - Delegates to existing WebSocketPublisher (Redis PubSub)
 * - Used in PHP-FPM HTTP request context
 * - Fan-out to friends for presence updates
 * - Room and conversation broadcasting
 *
 * @package Need2Talk\Adapters\Publisher
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class WebSocketPublisherAdapter implements EventPublisherInterface
{
    // ========================================================================
    // USER-TARGETED EVENTS
    // ========================================================================

    public function publishToUser(string $userUuid, string $event, array $data): bool
    {
        return WebSocketPublisher::publishToUser($userUuid, $event, $data);
    }

    // ========================================================================
    // ROOM-TARGETED EVENTS
    // ========================================================================

    public function publishToRoom(string $roomId, string $event, array $data): bool
    {
        return WebSocketPublisher::publishToRoom($roomId, $event, $data);
    }

    // ========================================================================
    // CONVERSATION-TARGETED EVENTS (DM)
    // ========================================================================

    public function publishToConversation(string $conversationUuid, string $event, array $data): bool
    {
        // Extract exclude_uuid from data if present
        $excludeUuid = $data['exclude_uuid'] ?? null;
        unset($data['exclude_uuid']);

        return WebSocketPublisher::publishToConversation($conversationUuid, $event, $data, $excludeUuid);
    }

    // ========================================================================
    // PRESENCE EVENTS
    // ========================================================================

    public function publishPresenceUpdate(string $userUuid, string $status, array $meta = []): bool
    {
        return WebSocketPublisher::publishPresenceUpdate($userUuid, $status, $meta);
    }

    // ========================================================================
    // TYPING INDICATORS
    // ========================================================================

    public function publishTypingRoom(string $roomId, string $userUuid, bool $isTyping): bool
    {
        return WebSocketPublisher::publishTypingIndicator($roomId, 'room', $userUuid, $isTyping);
    }

    public function publishTypingDM(string $conversationUuid, string $userUuid, string $recipientUuid, bool $isTyping): bool
    {
        return WebSocketPublisher::publishTypingIndicator($conversationUuid, 'dm', $userUuid, $isTyping);
    }

    // ========================================================================
    // READ RECEIPTS
    // ========================================================================

    public function publishReadReceipt(string $conversationUuid, string $readerUuid, ?string $lastMessageUuid = null): bool
    {
        if ($lastMessageUuid === null) {
            return false;
        }

        return WebSocketPublisher::publishReadReceipt($conversationUuid, $readerUuid, $lastMessageUuid);
    }
}
