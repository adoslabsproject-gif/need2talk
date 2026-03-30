<?php

declare(strict_types=1);

namespace Need2Talk\Adapters\Database;

use Need2Talk\Contracts\Database\DatabaseAdapterInterface;
use PDO;
use PDOException;
use Swoole\Coroutine\Channel;

/**
 * SwooleDatabaseAdapter - Coroutine-safe Database Access for Swoole WebSocket
 *
 * ENTERPRISE ARCHITECTURE (2025-12-06):
 * - Implements DatabaseAdapterInterface for ChatRoomService compatibility
 * - Uses PDO connection pool with Swoole\Coroutine\Channel
 * - Each coroutine borrows a connection, uses it, and returns it
 * - No coroutine contention (unlike shared connections)
 *
 * CONNECTION POOLING:
 * - Pool is initialized in workerStart callback (outside coroutines)
 * - Each worker has its own pool (process isolation)
 * - Connections are borrowed/returned via Channel (coroutine-safe queue)
 * - Pool exhaustion = coroutine waits (with timeout)
 *
 * USAGE:
 * - Register in ServiceContainer during websocket-bootstrap.php
 * - ChatServiceFactory::getDatabaseAdapter() returns this adapter
 * - ChatRoomService uses it for user lookups in joinRoom()
 *
 * PERFORMANCE:
 * - Pool size: 10 connections per worker (tunable)
 * - Connection timeout: 1 second (prevents indefinite waits)
 * - Automatic reconnection on dead connections
 *
 * @package Need2Talk\Adapters\Database
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-06
 */
class SwooleDatabaseAdapter implements DatabaseAdapterInterface
{
    /**
     * Connection pool (Swoole Channel)
     */
    private ?Channel $pool = null;

    /**
     * Pool size
     */
    private int $poolSize;

    /**
     * Database connection parameters
     */
    private string $dsn;
    private string $username;
    private string $password;

    /**
     * Transaction state (per-coroutine, stored in connection)
     */
    private ?PDO $transactionConnection = null;

    /**
     * Pool borrow timeout in seconds
     */
    private float $borrowTimeout;

    /**
     * Constructor
     *
     * NOTE: Does NOT create connections - call initPool() in workerStart
     *
     * @param int $poolSize Number of connections per worker
     * @param float $borrowTimeout Seconds to wait for available connection
     */
    public function __construct(int $poolSize = 10, float $borrowTimeout = 1.0)
    {
        $this->poolSize = $poolSize;
        $this->borrowTimeout = $borrowTimeout;

        // Build DSN from environment
        $host = $_ENV['DB_HOST'] ?? 'postgres';
        $port = $_ENV['DB_PORT'] ?? '5432';
        $database = $_ENV['DB_DATABASE'] ?? 'need2talk';
        $this->username = $_ENV['DB_USERNAME'] ?? 'need2talk';
        $this->password = $_ENV['DB_PASSWORD'] ?? '';

        $this->dsn = "pgsql:host={$host};port={$port};dbname={$database}";
    }

    /**
     * Initialize connection pool
     *
     * MUST be called in Swoole workerStart callback (outside coroutines)
     * Creates poolSize PDO connections and stores in Channel
     *
     * @param int $workerId Worker process ID (for logging)
     * @return int Number of connections created
     */
    public function initPool(int $workerId): int
    {
        $this->pool = new Channel($this->poolSize);
        $created = 0;

        for ($i = 0; $i < $this->poolSize; $i++) {
            try {
                $pdo = $this->createConnection();
                $this->pool->push($pdo);
                $created++;
            } catch (PDOException $e) {
                error_log("[Worker #{$workerId}] Failed to create DB connection {$i}: " . $e->getMessage());
            }
        }

        if (function_exists('ws_debug')) {
            ws_debug("Worker #{$workerId} created DB pool", ['size' => $created]);
        }

        return $created;
    }

    /**
     * Create a new PDO connection
     *
     * ENTERPRISE v4.2: Added TCP keepalive to prevent idle disconnections
     */
    private function createConnection(): PDO
    {
        $pdo = new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        // Set session parameters for PostgreSQL
        $pdo->exec("SET TIME ZONE 'Europe/Rome'");
        $pdo->exec("SET statement_timeout = '30000'"); // 30 seconds

        // ENTERPRISE v4.2: Enable TCP keepalive to prevent idle_session_timeout (30min)
        // This sends periodic TCP keepalive packets to keep connection alive
        $pdo->exec("SET tcp_keepalives_idle = 300"); // 5 minutes before first keepalive
        $pdo->exec("SET tcp_keepalives_interval = 60"); // 1 minute between keepalives
        $pdo->exec("SET tcp_keepalives_count = 3"); // 3 retries before considering dead

        return $pdo;
    }

    /**
     * Borrow a connection from pool
     *
     * @return PDO|null Connection or null if unavailable
     */
    private function borrowConnection(): ?PDO
    {
        // If in transaction, use the transaction connection
        if ($this->transactionConnection !== null) {
            return $this->transactionConnection;
        }

        if ($this->pool === null) {
            error_log("[SwooleDatabaseAdapter] Pool not initialized - call initPool() in workerStart");
            return null;
        }

        // Pop from channel (blocks until available or timeout)
        $pdo = $this->pool->pop($this->borrowTimeout);

        if (!$pdo instanceof PDO) {
            error_log("[SwooleDatabaseAdapter] Pool exhausted after {$this->borrowTimeout}s");
            return null;
        }

        // Verify connection is alive
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            // Connection dead (normal after idle_session_timeout), create new one silently
            // This is expected behavior - PostgreSQL closes idle connections after ~30min
            // Log only for debugging, not as error
            if (function_exists('ws_debug')) {
                ws_debug('Pool connection recycled (idle timeout)', [
                    'reason' => substr($e->getMessage(), 0, 100),
                ]);
            }
            try {
                return $this->createConnection();
            } catch (PDOException $e2) {
                // THIS is a real error - can't reconnect
                error_log("[SwooleDatabaseAdapter] CRITICAL: Failed to recreate connection: " . $e2->getMessage());
                return null;
            }
        }
    }

    /**
     * Return connection to pool
     */
    private function releaseConnection(PDO $pdo): void
    {
        // Don't return transaction connections to pool
        if ($this->transactionConnection !== null && $this->transactionConnection === $pdo) {
            return;
        }

        if ($this->pool !== null && !$this->pool->isFull()) {
            $this->pool->push($pdo);
        }
    }

    // ========================================================================
    // DatabaseAdapterInterface Implementation
    // ========================================================================

    /**
     * {@inheritdoc}
     */
    public function query(string $sql, array $params = [], array $options = []): array
    {
        $pdo = $this->borrowConnection();
        if (!$pdo) {
            throw new PDOException('No database connection available');
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, array $params = [], array $options = []): int
    {
        $pdo = $this->borrowConnection();
        if (!$pdo) {
            throw new PDOException('No database connection available');
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findOne(string $sql, array $params = [], array $options = []): ?array
    {
        $results = $this->query($sql, $params, $options);
        return $results[0] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function findMany(string $sql, array $params = [], array $options = []): array
    {
        return $this->query($sql, $params, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(int $timeout = 30): void
    {
        if ($this->transactionConnection !== null) {
            throw new \RuntimeException('Transaction already in progress');
        }

        $pdo = $this->borrowConnection();
        if (!$pdo) {
            throw new PDOException('No database connection available');
        }

        // Set transaction timeout
        $pdo->exec("SET LOCAL statement_timeout = '" . ($timeout * 1000) . "'");
        $pdo->beginTransaction();

        // Keep this connection for the transaction duration
        $this->transactionConnection = $pdo;
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): void
    {
        if ($this->transactionConnection === null) {
            throw new \RuntimeException('No transaction in progress');
        }

        try {
            $this->transactionConnection->commit();
        } finally {
            $pdo = $this->transactionConnection;
            $this->transactionConnection = null;
            $this->releaseConnection($pdo);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rollback(): void
    {
        if ($this->transactionConnection === null) {
            return; // Silent no-op for safety
        }

        try {
            $this->transactionConnection->rollBack();
        } finally {
            $pdo = $this->transactionConnection;
            $this->transactionConnection = null;
            $this->releaseConnection($pdo);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function inTransaction(): bool
    {
        return $this->transactionConnection !== null
            && $this->transactionConnection->inTransaction();
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): string|false
    {
        $pdo = $this->borrowConnection();
        if (!$pdo) {
            return false;
        }

        try {
            return $pdo->lastInsertId();
        } finally {
            $this->releaseConnection($pdo);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM {$table} WHERE {$where}";
        $result = $this->findOne($sql, $params);
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Get pool statistics (for monitoring)
     *
     * @return array Pool stats
     */
    public function getPoolStats(): array
    {
        if ($this->pool === null) {
            return ['initialized' => false];
        }

        return [
            'initialized' => true,
            'size' => $this->poolSize,
            'available' => $this->pool->length(),
            'in_transaction' => $this->transactionConnection !== null,
        ];
    }
}
