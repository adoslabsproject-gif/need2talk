<?php

declare(strict_types=1);

namespace Need2Talk\Adapters\Database;

use Need2Talk\Contracts\Database\DatabaseAdapterInterface;
use Need2Talk\Core\Database;

/**
 * PhpFpmDatabaseAdapter - Database Adapter for PHP-FPM Context
 *
 * Wraps the existing db() helper function which returns Database or EnterpriseSecureDatabasePool.
 * This adapter is ONLY used in PHP-FPM context (HTTP requests).
 *
 * ARCHITECTURE NOTE:
 * The db() helper returns Need2Talk\Core\Database as its declared return type.
 * At runtime, it may return EnterpriseSecureDatabasePool (which extends Database)
 * when EnterpriseBootstrap is initialized. Both classes implement the same
 * query/execute/findOne/findMany interface, so we use Database as the type hint
 * for maximum compatibility.
 *
 * Features inherited from underlying database:
 * - Connection pooling (50 connections) when EnterpriseSecureDatabasePool
 * - Query caching with SHA256 keys
 * - Slow query detection (>100ms)
 * - Auto-release PDO wrapper
 * - Transaction timeout prevention
 *
 * NOTE: WebSocket server does NOT use this adapter.
 * Database operations from WebSocket should go through HTTP API or queue.
 *
 * @package Need2Talk\Adapters\Database
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @updated 2025-12-03 - Fixed return type to use Database base class
 */
class PhpFpmDatabaseAdapter implements DatabaseAdapterInterface
{
    /**
     * Cached database instance for this adapter
     * @var Database|null
     */
    private ?Database $dbInstance = null;

    /**
     * Get the database instance from the db() helper
     *
     * Uses lazy initialization with caching to avoid repeated db() calls.
     * The db() helper returns Database (base class) but may actually return
     * EnterpriseSecureDatabasePool at runtime (polymorphism).
     *
     * @return Database The database instance (may be EnterpriseSecureDatabasePool)
     */
    private function db(): Database
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = db();
        }
        return $this->dbInstance;
    }

    // ========================================================================
    // QUERY OPERATIONS
    // ========================================================================

    public function query(string $sql, array $params = [], array $options = []): array
    {
        return $this->db()->query($sql, $params, $options);
    }

    public function execute(string $sql, array $params = [], array $options = []): int
    {
        return $this->db()->execute($sql, $params, $options);
    }

    public function findOne(string $sql, array $params = [], array $options = []): ?array
    {
        return $this->db()->findOne($sql, $params, $options);
    }

    public function findMany(string $sql, array $params = [], array $options = []): array
    {
        return $this->db()->findMany($sql, $params, $options);
    }

    // ========================================================================
    // TRANSACTION OPERATIONS
    // ========================================================================

    public function beginTransaction(int $timeout = 30): void
    {
        $this->db()->beginTransaction($timeout);
    }

    public function commit(): void
    {
        $this->db()->commit();
    }

    public function rollback(): void
    {
        $this->db()->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->db()->inTransaction();
    }

    // ========================================================================
    // CONVENIENCE METHODS
    // ========================================================================

    public function lastInsertId(): string|false
    {
        return $this->db()->lastInsertId();
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        return $this->db()->count($table, $where, $params);
    }
}
