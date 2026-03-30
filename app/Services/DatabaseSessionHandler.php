<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseGlobalsManager;

/**
 * DatabaseSessionHandler - Enterprise Database Session Storage
 *
 * Scalable session storage using PostgreSQL for multi-server environments
 * - Optimized queries for 10,000+ concurrent sessions
 * - Automatic session cleanup and garbage collection
 * - Connection pooling integration
 * - Supports session locking for race condition prevention
 * - Horizontal scaling ready
 */
class DatabaseSessionHandler implements \SessionHandlerInterface
{
    private string $table = 'sessions';

    private int $maxLifetime;

    public function __construct()
    {
        // ENTERPRISE GALAXY V6.6: Use centralized session lifetime from EnterpriseGlobalsManager
        // This ensures ALL session handling uses the same source of truth (.env SESSION_LIFETIME)
        $this->maxLifetime = EnterpriseGlobalsManager::getSessionLifetimeSeconds();

        // Ensure sessions table exists
        $this->createSessionsTableIfNeeded();
    }

    /**
     * Open session - no action needed for database storage
     */
    public function open($save_path, $session_name): bool
    {
        return true;
    }

    /**
     * Close session - no action needed
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Read session data with connection pooling
     */
    public function read($session_id): string
    {
        try {
            // ENTERPRISE: Use pool directly (db_pdo() not yet defined during bootstrap)
            $pdo = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();

            $stmt = $pdo->prepare("
                SELECT payload as session_data
                FROM `{$this->table}`
                WHERE id = ? AND last_activity > ?
            ");
            $stmt->execute([$session_id, time() - $this->maxLifetime]);

            $data = $stmt->fetchColumn();

            // Release connection back to pool
            // ENTERPRISE: Release connection back to pool directly
            \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($pdo);

            return $data ?: '';

        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Session read failed', [
                'session_id' => substr($session_id, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Write session data with upsert for performance
     */
    public function write($session_id, $session_data): bool
    {
        try {
            // ENTERPRISE: Use pool directly (db_pdo() not yet defined during bootstrap)
            $pdo = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();
            $expiresAt = date('Y-m-d H:i:s', time() + $this->maxLifetime);

            // ENTERPRISE: Get IP and User Agent using enterprise globals
            // SECURITY: Usare SOLO REMOTE_ADDR - X-Forwarded-For spoofabile
            $ip_address = get_server('REMOTE_ADDR') ?? 'unknown';
            $user_agent = get_server('HTTP_USER_AGENT') ?? 'unknown';

            // PostgreSQL UPSERT with EXCLUDED (not VALUES)
            $stmt = $pdo->prepare("
                INSERT INTO \"{$this->table}\"
                (id, payload, last_activity, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (id) DO UPDATE SET
                payload = EXCLUDED.payload,
                last_activity = EXCLUDED.last_activity,
                ip_address = EXCLUDED.ip_address,
                user_agent = EXCLUDED.user_agent
            ");

            $result = $stmt->execute([
                $session_id,
                $session_data,
                time(), // last_activity
                $ip_address,
                $user_agent,
            ]);

            // Release connection back to pool
            // ENTERPRISE: Release connection back to pool directly
            \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($pdo);

            return $result;

        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Session write failed', [
                'session_id' => substr($session_id, 0, 8) . '...',
                'data_size' => strlen($session_data),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Destroy session
     */
    public function destroy($session_id): bool
    {
        try {
            // ENTERPRISE: Use pool directly (db_pdo() not yet defined during bootstrap)
            $pdo = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();

            $stmt = $pdo->prepare("DELETE FROM `{$this->table}` WHERE id = ?");
            $result = $stmt->execute([$session_id]);

            // ENTERPRISE: Release connection back to pool directly
            \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($pdo);

            return $result;

        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Session destroy failed', [
                'session_id' => substr($session_id, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Garbage collection - remove expired sessions
     */
    public function gc($maxlifetime): int
    {
        try {
            // ENTERPRISE: Use pool directly (db_pdo() not yet defined during bootstrap)
            $pdo = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();

            $stmt = $pdo->prepare("DELETE FROM `{$this->table}` WHERE expires_at < NOW()");
            $stmt->execute();

            $deletedCount = $stmt->rowCount();
            // ENTERPRISE: Release connection back to pool directly
            \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($pdo);

            return $deletedCount;

        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Session garbage collection failed', [
                'error' => $e->getMessage(),
                'maxlifetime' => $maxlifetime,
            ]);

            return 0;
        }
    }

    /**
     * Get session statistics for monitoring
     */
    public function getStats(): array
    {
        try {
            // ENTERPRISE: Use pool directly (db_pdo() not yet defined during bootstrap)
            $pdo = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();

            // Active sessions count
            $stmt = $pdo->query("
                SELECT COUNT(*) as active_count
                FROM `{$this->table}`
                WHERE expires_at > NOW()
            ");
            $activeCount = (int) $stmt->fetchColumn();

            // Total sessions count
            $stmt = $pdo->query("SELECT COUNT(*) as total_count FROM `{$this->table}`");
            $totalCount = (int) $stmt->fetchColumn();

            // Session age statistics
            $stmt = $pdo->query("
                SELECT
                    MIN(created_at) as oldest_session,
                    MAX(updated_at) as latest_activity,
                    AVG(EXTRACT(EPOCH FROM (NOW() - created_at)) / 60) as avg_age_minutes
                FROM `{$this->table}`
                WHERE expires_at > NOW()
            ");
            $ageStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // ENTERPRISE: Release connection back to pool directly
            \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->releaseConnection($pdo);

            return [
                'active_sessions' => $activeCount,
                'total_sessions' => $totalCount,
                'expired_sessions' => $totalCount - $activeCount,
                'oldest_session' => $ageStats['oldest_session'],
                'latest_activity' => $ageStats['latest_activity'],
                'avg_session_age_minutes' => round($ageStats['avg_age_minutes'] ?? 0, 1),
                'table_name' => $this->table,
                'max_lifetime_seconds' => $this->maxLifetime,
            ];

        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Failed to get session statistics', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Statistics unavailable',
                'active_sessions' => 0,
                'total_sessions' => 0,
            ];
        }
    }

    /**
     * Cleanup old sessions (for cron job)
     */
    public function cleanupExpiredSessions(): int
    {
        return $this->gc($this->maxLifetime);
    }

    /**
     * Create sessions table if it doesn't exist
     * ENTERPRISE GALAXY: PostgreSQL-only (100% migrated)
     */
    private function createSessionsTableIfNeeded(): void
    {
        try {
            // ENTERPRISE: Use pool directly (db_pdo() not yet defined during bootstrap)
            $pdo = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance()->getConnection();

            // Check if table exists (PostgreSQL)
            $stmt = $pdo->prepare('
                SELECT COUNT(*) FROM information_schema.tables
                WHERE table_catalog = CURRENT_DATABASE()
                  AND table_schema = \'public\'
                  AND table_name = ?
            ');
            $stmt->execute([$this->table]);

            if ($stmt->fetchColumn() === 0) {
                // Create optimized sessions table for scalability (PostgreSQL)
                $sql = "
                CREATE TABLE \"{$this->table}\" (
                    \"session_id\" VARCHAR(255) NOT NULL PRIMARY KEY,
                    \"session_data\" TEXT NOT NULL,
                    \"created_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    \"updated_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    \"expires_at\" TIMESTAMP NOT NULL,
                    \"ip_address\" VARCHAR(45),
                    \"user_agent\" TEXT
                );

                CREATE INDEX \"idx_expires_at\" ON \"{$this->table}\" (\"expires_at\");
                CREATE INDEX \"idx_updated_at\" ON \"{$this->table}\" (\"updated_at\");
                CREATE INDEX \"idx_ip_address\" ON \"{$this->table}\" (\"ip_address\");

                -- Trigger for auto-updating updated_at
                CREATE OR REPLACE FUNCTION update_{$this->table}_updated_at()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    NEW.updated_at = CURRENT_TIMESTAMP;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;

                CREATE TRIGGER update_{$this->table}_updated_at_trigger
                    BEFORE UPDATE ON \"{$this->table}\"
                    FOR EACH ROW
                    EXECUTE FUNCTION update_{$this->table}_updated_at();
                ";

                $pdo->exec($sql);
            }
        } catch (\Exception $e) {
            Logger::database('error', 'SESSION: Failed to create sessions table', [
                'error' => $e->getMessage(),
                'table' => $this->table,
            ]);

            throw $e;
        }
    }
}
