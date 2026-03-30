<?php

declare(strict_types=1);

namespace Need2Talk\Services\Chat;

use Need2Talk\Core\ServiceContainer;
use Need2Talk\Contracts\Redis\RedisAdapterInterface;
use Need2Talk\Contracts\Database\DatabaseAdapterInterface;
use Need2Talk\Contracts\Publisher\EventPublisherInterface;

/**
 * ChatServiceFactory - Factory for Creating Chat Services with Dependency Injection
 *
 * This factory centralizes the creation of all Chat services, ensuring:
 * - Proper dependency injection (no getInstance() calls in services)
 * - Singleton pattern for service reuse within a request
 * - Context-aware adapters (PHP-FPM vs Swoole)
 * - Easy testing via mock injection
 *
 * Usage in Controllers/Routes:
 *   $presenceService = ChatServiceFactory::createPresenceService();
 *   $dmService = ChatServiceFactory::createDirectMessageService();
 *
 * Usage in WebSocket Server:
 *   $services = ChatServiceFactory::getChatServices();
 *
 * Testing with Mocks:
 *   ServiceContainer::register('redis', new MockRedisAdapter());
 *   $presenceService = ChatServiceFactory::createPresenceService();
 *
 * @package Need2Talk\Services\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class ChatServiceFactory
{
    /**
     * Cached service instances (singleton per request)
     * @var array<string, object>
     */
    private static array $instances = [];

    // ========================================================================
    // CORE SERVICE CREATORS
    // ========================================================================

    /**
     * Create PresenceService with injected dependencies
     *
     * Dependencies:
     * - Redis: For presence storage (DB 6: chat)
     * - Publisher: For real-time presence updates to friends
     *
     * @return PresenceService
     */
    public static function createPresenceService(): PresenceService
    {
        if (!isset(self::$instances['presence'])) {
            $redis = self::getRedisAdapter();
            $publisher = self::getPublisherAdapter();

            self::$instances['presence'] = new PresenceService($redis, $publisher);
        }

        return self::$instances['presence'];
    }

    /**
     * Create EmotionRoomService with injected dependencies
     *
     * Dependencies:
     * - Redis: For ephemeral room messages (DB 6: chat)
     *
     * @return EmotionRoomService
     */
    public static function createEmotionRoomService(): EmotionRoomService
    {
        if (!isset(self::$instances['emotion'])) {
            $redis = self::getRedisAdapter();

            self::$instances['emotion'] = new EmotionRoomService($redis);
        }

        return self::$instances['emotion'];
    }

    /**
     * Create ChatModerationService with injected dependencies
     *
     * Dependencies:
     * - Database: For blacklist keywords and reports (optional in Swoole)
     * - Redis: For cached keywords
     *
     * @return ChatModerationService
     */
    public static function createModerationService(): ChatModerationService
    {
        if (!isset(self::$instances['moderation'])) {
            $database = self::getDatabaseAdapter(); // May be null in Swoole
            $redis = self::getRedisAdapter();

            self::$instances['moderation'] = new ChatModerationService($database, $redis);
        }

        return self::$instances['moderation'];
    }

    /**
     * Create ChatRoomService with injected dependencies
     *
     * Dependencies:
     * - Redis: For ephemeral messages and room state
     * - Database: For room persistence (optional in Swoole)
     * - EmotionRoomService: For emotion room handling
     * - ChatModerationService: For content filtering
     * - Publisher: For real-time room events
     *
     * @return ChatRoomService
     */
    public static function createChatRoomService(): ChatRoomService
    {
        if (!isset(self::$instances['room'])) {
            $redis = self::getRedisAdapter();
            $database = self::getDatabaseAdapter();
            $emotionService = self::createEmotionRoomService();
            $moderationService = self::createModerationService();
            $publisher = self::getPublisherAdapter();

            self::$instances['room'] = new ChatRoomService(
                $redis,
                $database,
                $emotionService,
                $moderationService,
                $publisher
            );
        }

        return self::$instances['room'];
    }

    /**
     * Create DirectMessageService with injected dependencies
     *
     * Dependencies:
     * - Redis: For typing indicators and message cache
     * - Database: For persistent messages (required in PHP-FPM, not available in Swoole)
     * - PresenceService: For recipient status checks
     * - ChatModerationService: For content filtering
     * - Publisher: For real-time DM notifications
     *
     * @return DirectMessageService
     */
    public static function createDirectMessageService(): DirectMessageService
    {
        if (!isset(self::$instances['dm'])) {
            $redis = self::getRedisAdapter();
            $database = self::getDatabaseAdapter();
            $presenceService = self::createPresenceService();
            $moderationService = self::createModerationService();
            $publisher = self::getPublisherAdapter();

            self::$instances['dm'] = new DirectMessageService(
                $redis,
                $database,
                $presenceService,
                $moderationService,
                $publisher
            );
        }

        return self::$instances['dm'];
    }

    // ========================================================================
    // BATCH CREATORS (for WebSocket server)
    // ========================================================================

    /**
     * Get all chat services as array (for WebSocket server)
     *
     * @return array<string, object>
     */
    public static function getChatServices(): array
    {
        return [
            'presence' => self::createPresenceService(),
            'emotion' => self::createEmotionRoomService(),
            'moderation' => self::createModerationService(),
            'room' => self::createChatRoomService(),
            'dm' => self::createDirectMessageService(),
        ];
    }

    // ========================================================================
    // ADAPTER GETTERS (with graceful fallbacks)
    // ========================================================================

    /**
     * Get Redis adapter from ServiceContainer
     *
     * @return RedisAdapterInterface
     * @throws \RuntimeException If Redis adapter not registered
     */
    private static function getRedisAdapter(): RedisAdapterInterface
    {
        $redis = ServiceContainer::get('redis');

        if ($redis === null) {
            throw new \RuntimeException(
                'Redis adapter not registered in ServiceContainer. ' .
                'Ensure bootstrap.php or websocket-bootstrap.php has run.'
            );
        }

        return $redis;
    }

    /**
     * Get Database adapter from ServiceContainer
     *
     * Returns null in Swoole context (database not available).
     * Services must handle null database gracefully.
     *
     * @return DatabaseAdapterInterface|null
     */
    private static function getDatabaseAdapter(): ?DatabaseAdapterInterface
    {
        return ServiceContainer::get('database');
    }

    /**
     * Get Publisher adapter from ServiceContainer
     *
     * @return EventPublisherInterface
     * @throws \RuntimeException If Publisher adapter not registered
     */
    private static function getPublisherAdapter(): EventPublisherInterface
    {
        $publisher = ServiceContainer::get('publisher');

        if ($publisher === null) {
            throw new \RuntimeException(
                'Publisher adapter not registered in ServiceContainer. ' .
                'Ensure bootstrap.php or websocket-bootstrap.php has run.'
            );
        }

        return $publisher;
    }

    // ========================================================================
    // UTILITY METHODS
    // ========================================================================

    /**
     * Reset all cached instances
     *
     * Use for testing or worker restart (Swoole worker reload).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instances = [];
    }

    /**
     * Check if a service has been created
     *
     * @param string $serviceName Service name (presence, emotion, moderation, room, dm)
     * @return bool
     */
    public static function hasInstance(string $serviceName): bool
    {
        return isset(self::$instances[$serviceName]);
    }

    /**
     * Get list of created services
     *
     * @return array<string>
     */
    public static function getCreatedServices(): array
    {
        return array_keys(self::$instances);
    }
}
