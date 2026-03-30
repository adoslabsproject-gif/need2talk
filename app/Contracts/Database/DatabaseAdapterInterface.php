<?php

declare(strict_types=1);

namespace Need2Talk\Contracts\Database;

/**
 * Database Adapter Interface - Enterprise Database Access Abstraction
 *
 * This interface abstracts database operations, allowing services to work
 * with the database without coupling to the specific implementation.
 *
 * In PHP-FPM context: Wraps the db() helper and EnterpriseSecureDatabasePool
 * In Swoole context: NOT available (WebSocket server doesn't access DB directly)
 *
 * @package Need2Talk\Contracts\Database
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
interface DatabaseAdapterInterface
{
    // ========================================================================
    // QUERY OPERATIONS
    // ========================================================================

    /**
     * Execute a SELECT query and return results
     *
     * @param string $sql SQL query with named or positional placeholders
     * @param array $params Parameters for the query
     * @param array $options Options like 'cache' => true, 'cache_ttl' => 'medium'
     * @return array Array of result rows
     */
    public function query(string $sql, array $params = [], array $options = []): array;

    /**
     * Execute an INSERT, UPDATE, or DELETE query
     *
     * @param string $sql SQL statement with placeholders
     * @param array $params Parameters for the statement
     * @param array $options Options like 'invalidate_cache' => ['table:users']
     * @return int Number of affected rows
     */
    public function execute(string $sql, array $params = [], array $options = []): int;

    /**
     * Find a single row
     *
     * @param string $sql SQL query
     * @param array $params Parameters
     * @param array $options Query options
     * @return array|null Single row or null if not found
     */
    public function findOne(string $sql, array $params = [], array $options = []): ?array;

    /**
     * Find multiple rows
     *
     * @param string $sql SQL query
     * @param array $params Parameters
     * @param array $options Query options
     * @return array Array of rows
     */
    public function findMany(string $sql, array $params = [], array $options = []): array;

    // ========================================================================
    // TRANSACTION OPERATIONS
    // ========================================================================

    /**
     * Begin a transaction
     *
     * @param int $timeout Transaction timeout in seconds (prevents deadlocks)
     * @return void
     */
    public function beginTransaction(int $timeout = 30): void;

    /**
     * Commit the current transaction
     *
     * @return void
     */
    public function commit(): void;

    /**
     * Rollback the current transaction
     *
     * @return void
     */
    public function rollback(): void;

    /**
     * Check if currently in a transaction
     *
     * @return bool True if in transaction
     */
    public function inTransaction(): bool;

    // ========================================================================
    // CONVENIENCE METHODS
    // ========================================================================

    /**
     * Get the last inserted ID
     *
     * @return string|false Last insert ID or false
     */
    public function lastInsertId(): string|false;

    /**
     * Count rows matching a condition
     *
     * @param string $table Table name
     * @param string $where WHERE clause (without "WHERE" keyword)
     * @param array $params Parameters for the WHERE clause
     * @return int Count of matching rows
     */
    public function count(string $table, string $where = '1=1', array $params = []): int;
}
