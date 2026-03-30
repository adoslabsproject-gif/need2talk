<?php

declare(strict_types=1);

namespace Need2Talk\Core;

/**
 * ServiceContainer - Lightweight Dependency Injection Container
 *
 * A minimal service container designed for performance and simplicity.
 * NOT a full DI container like Laravel's - just what need2talk needs:
 *
 * Features:
 * - Register services by name (direct instance)
 * - Register factories for lazy instantiation
 * - Singleton pattern (services created once)
 * - Context-aware (PHP-FPM vs Swoole adapters registered at bootstrap)
 *
 * Usage:
 *   // In bootstrap (PHP-FPM):
 *   ServiceContainer::registerFactory('redis', fn() => new PhpFpmRedisAdapter());
 *
 *   // In websocket-bootstrap (Swoole):
 *   ServiceContainer::register('redis', new SwooleCoroutineRedisAdapter());
 *
 *   // In services:
 *   $redis = ServiceContainer::get('redis');
 *
 * Why not a full DI container?
 * - Performance: No reflection, no autowiring overhead
 * - Simplicity: 50 lines vs 5000+ lines in full containers
 * - Control: Explicit registration, no magic
 * - Memory: Minimal footprint (~1KB vs 100KB+)
 *
 * @package Need2Talk\Core
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class ServiceContainer
{
    /**
     * Cached service instances (singleton pattern)
     * @var array<string, object>
     */
    private static array $instances = [];

    /**
     * Factory callables for lazy instantiation
     * @var array<string, callable>
     */
    private static array $factories = [];

    /**
     * Register a service instance directly
     *
     * Use this when you have an already-created instance:
     *   ServiceContainer::register('redis', new SwooleRedisAdapter());
     *
     * @param string $name Service name (e.g., 'redis', 'database', 'publisher')
     * @param object $instance The service instance
     * @return void
     */
    public static function register(string $name, object $instance): void
    {
        self::$instances[$name] = $instance;
    }

    /**
     * Register a factory for lazy instantiation
     *
     * Use this for expensive services that might not be needed:
     *   ServiceContainer::registerFactory('database', fn() => new DbAdapter());
     *
     * The factory is only called on first get(), and result is cached.
     *
     * @param string $name Service name
     * @param callable $factory Factory function returning the service instance
     * @return void
     */
    public static function registerFactory(string $name, callable $factory): void
    {
        self::$factories[$name] = $factory;
    }

    /**
     * Get a service by name
     *
     * Returns cached instance if available, otherwise creates from factory.
     * Returns null if service not registered.
     *
     * @param string $name Service name
     * @return object|null The service instance or null
     */
    public static function get(string $name): ?object
    {
        // Return cached instance
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        // Create from factory if registered
        if (isset(self::$factories[$name])) {
            self::$instances[$name] = (self::$factories[$name])();
            return self::$instances[$name];
        }

        return null;
    }

    /**
     * Get a service or throw exception
     *
     * Use when the service MUST exist (fail-fast for misconfigurations).
     *
     * @param string $name Service name
     * @return object The service instance
     * @throws \RuntimeException If service not registered
     */
    public static function getOrFail(string $name): object
    {
        $service = self::get($name);

        if ($service === null) {
            throw new \RuntimeException(
                "Service '{$name}' not registered in ServiceContainer. " .
                "Check bootstrap registration order."
            );
        }

        return $service;
    }

    /**
     * Check if a service is registered
     *
     * @param string $name Service name
     * @return bool True if service or factory is registered
     */
    public static function has(string $name): bool
    {
        return isset(self::$instances[$name]) || isset(self::$factories[$name]);
    }

    /**
     * Remove a service registration
     *
     * @param string $name Service name
     * @return void
     */
    public static function remove(string $name): void
    {
        unset(self::$instances[$name], self::$factories[$name]);
    }

    /**
     * Reset the container (for testing or worker restart)
     *
     * Clears all registered services and factories.
     * Use with caution in production!
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instances = [];
        self::$factories = [];
    }

    /**
     * Get all registered service names
     *
     * Useful for debugging and health checks.
     *
     * @return array<string> List of registered service names
     */
    public static function getRegisteredServices(): array
    {
        return array_unique(
            array_merge(
                array_keys(self::$instances),
                array_keys(self::$factories)
            )
        );
    }

    /**
     * Check if a service has been instantiated
     *
     * Returns true only if the service has been created (not just registered).
     *
     * @param string $name Service name
     * @return bool True if service instance exists
     */
    public static function isInstantiated(string $name): bool
    {
        return isset(self::$instances[$name]);
    }
}
