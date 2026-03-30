<?php

namespace Need2Talk\Core;

/**
 * BaseModel - Essential model functionality
 *
 * Clean, focused base model avoiding bloated ActiveRecord pattern
 */
abstract class BaseModel
{
    protected string $table;

    protected string $primaryKey = 'id';

    protected bool $usesSoftDeletes = false;

    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?";

        if ($this->usesSoftDeletes) {
            $sql .= ' AND deleted_at IS NULL';
        }

        // ENTERPRISE: Use Database::findOne() with caching
        return $this->db()->findOne($sql, [$id], [
            'cache' => true,
            'cache_ttl' => 'short', // 5 minutes
        ]);
    }

    /**
     * Find records by conditions
     *
     * ENTERPRISE GALAXY V6.6: Added $options parameter for cache control
     *
     * @param array $conditions Column => value pairs
     * @param int|null $limit Max records to return
     * @param array $options Query options (e.g., ['cache' => false] for auth-critical queries)
     * @return array Matching records
     */
    public function findBy(array $conditions, ?int $limit = null, array $options = []): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE ";
        $params = [];

        foreach ($conditions as $field => $value) {
            $sql .= "{$field} = ? AND ";
            $params[] = $value;
        }

        $sql = rtrim($sql, ' AND ');

        if ($this->usesSoftDeletes) {
            $sql .= ' AND deleted_at IS NULL';
        }

        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }

        // ENTERPRISE GALAXY V6.6: Allow cache control via options
        // Default: cache enabled with short TTL
        // Auth-critical queries should pass ['cache' => false]
        $queryOptions = array_merge([
            'cache' => true,
            'cache_ttl' => 'short', // 5 minutes
        ], $options);

        return $this->db()->query($sql, $params, $queryOptions);
    }

    public function create(array $data): int
    {
        // ENTERPRISE: Use Database::insert() with auto-caching and connection pooling
        return $this->db()->insert($this->table, $data);
    }

    public function update(int $id, array $data): bool
    {
        // ENTERPRISE: Use Database::update() with auto-caching and connection pooling
        $affectedRows = $this->db()->update(
            $this->table,
            $data,
            "{$this->primaryKey} = ?",
            [$id]
        );

        return $affectedRows > 0;
    }

    public function delete(int $id): bool
    {
        if ($this->usesSoftDeletes) {
            return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
        }

        // ENTERPRISE: Use Database::delete() with auto-caching and connection pooling
        $affectedRows = $this->db()->delete(
            $this->table,
            "{$this->primaryKey} = ?",
            [$id]
        );

        return $affectedRows > 0;
    }

    /**
     * Restore a soft-deleted record (GDPR account deletion cancellation)
     *
     * @param int $id Primary key value
     * @return bool Success
     */
    public function restore(int $id): bool
    {
        if (!$this->usesSoftDeletes) {
            return false; // Can't restore hard-deleted records
        }

        // ENTERPRISE: Restore by setting deleted_at to NULL
        return $this->update($id, ['deleted_at' => null]);
    }

    protected function db()
    {
        // ENTERPRISE: Return cached Database object instead of raw PDO
        // This enables automatic query caching for all Models
        return db();
    }
}
