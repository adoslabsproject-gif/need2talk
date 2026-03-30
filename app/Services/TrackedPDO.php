<?php

namespace Need2Talk\Services;

/**
 * ENTERPRISE TrackedPDO - PDO Wrapper for DebugBar Query Tracking
 *
 * ENTERPRISE GALAXY 2025:
 * - Dynamic DebugBar state detection (not cached at construction time)
 * - Per-request tracking state with lazy evaluation
 * - Ultra-safe error handling - never breaks main functionality
 * - Complete PDO compatibility via magic methods
 * - Zero overhead when DebugBar disabled
 * - Automatic query parameter tracking
 * - Enterprise-grade error isolation
 *
 * CRITICAL FIX (2025-12-08):
 * - Removed static caching of $trackingEnabled in constructor
 * - Now checks DebugbarService::isEnabled() dynamically
 * - Fixes issue where DebugBar enabled via admin panel wasn't detected
 *
 * @see Database.php for caching (TaggedQueryCache, multi-level L1/L2/L3)
 */
class TrackedPDO
{
    private \PDO $pdo;

    /**
     * ENTERPRISE: Request-scoped cache for isEnabled() check
     * Avoids calling DebugbarService::isEnabled() on EVERY query
     * Cache is per-request (static var resets between requests)
     */
    private static ?bool $requestScopedTrackingEnabled = null;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ENTERPRISE: Dynamic tracking check with request-scoped caching
     *
     * Why not cache in constructor?
     * - DebugBar can be enabled via admin panel AFTER PDO connection is created
     * - Static vars in constructor would cache the "disabled" state forever
     *
     * Why cache at all?
     * - Avoid calling DebugbarService::isEnabled() on every single query
     * - Once per request is enough (admin panel changes require page reload anyway)
     */
    private static function isTrackingEnabled(): bool
    {
        // Return cached value if already checked this request
        if (self::$requestScopedTrackingEnabled !== null) {
            return self::$requestScopedTrackingEnabled;
        }

        // ENTERPRISE: Ultra-safe dynamic check
        try {
            if (!class_exists('\Need2Talk\Services\DebugbarService')) {
                self::$requestScopedTrackingEnabled = false;
                return false;
            }

            self::$requestScopedTrackingEnabled = \Need2Talk\Services\DebugbarService::isEnabled();
            return self::$requestScopedTrackingEnabled;
        } catch (\Throwable $e) {
            // ENTERPRISE: Never let debugbar errors affect main functionality
            self::$requestScopedTrackingEnabled = false;

            if (class_exists('\Need2Talk\Services\Logger')) {
                Logger::error('TrackedPDO: DebugBar check failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * Prepare statement with tracking wrapper
     */
    #[\ReturnTypeWillChange]
    public function prepare($statement, $driver_options = [])
    {
        try {
            $stmt = $this->pdo->prepare($statement, $driver_options);

            if ($stmt !== false) {
                return new TrackedPDOStatement($stmt, $statement);
            }

            return $stmt;
        } catch (\Throwable $e) {
            if (class_exists('\Need2Talk\Services\Logger')) {
                Logger::database('error', 'SQL prepare() failed', [
                    'error' => $e->getMessage(),
                    'query' => $statement,
                ]);
            }

            // Track failed query
            if (self::isTrackingEnabled()) {
                self::trackQueryStatic($statement, [], 0.0, true);
            }

            throw $e;
        }
    }

    /**
     * Execute statement with tracking
     */
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        $startTime = microtime(true);

        try {
            $result = $this->pdo->exec($statement);

            if (self::isTrackingEnabled()) {
                self::trackQueryStatic($statement, [], microtime(true) - $startTime, false);
            }

            return $result;
        } catch (\Throwable $e) {
            if (self::isTrackingEnabled()) {
                self::trackQueryStatic($statement, [], microtime(true) - $startTime, true);
            }

            throw $e;
        }
    }

    /**
     * Direct query with tracking
     */
    #[\ReturnTypeWillChange]
    public function query($query, ...$args)
    {
        $startTime = microtime(true);

        try {
            $result = $this->pdo->query($query, ...$args);

            if (self::isTrackingEnabled()) {
                self::trackQueryStatic($query, [], microtime(true) - $startTime, false);
            }

            return $result;
        } catch (\Throwable $e) {
            if (self::isTrackingEnabled()) {
                self::trackQueryStatic($query, [], microtime(true) - $startTime, true);
            }

            throw $e;
        }
    }

    /**
     * Proxy all other PDO methods
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->pdo, $method], $args);
    }

    public function __get($property)
    {
        return $this->pdo->$property;
    }

    public function __set($property, $value)
    {
        $this->pdo->$property = $value;
    }

    /**
     * Get original PDO instance
     */
    public function getOriginalPDO(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Alias for getOriginalPDO() - used by DatabasePool
     */
    public function getWrappedPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * ENTERPRISE: Static method for tracking - shared with TrackedPDOStatement
     * Ultra-safe, never fails
     */
    public static function trackQueryStatic(string $sql, array $params, float $duration, bool $failed): void
    {
        try {
            \Need2Talk\Services\DebugbarService::trackQuery($sql, $params, $duration, $failed);
        } catch (\Throwable $e) {
            // ENTERPRISE: Never let tracking errors affect functionality
        }
    }

    /**
     * ENTERPRISE: Expose isTrackingEnabled for TrackedPDOStatement
     */
    public static function shouldTrack(): bool
    {
        return self::isTrackingEnabled();
    }
}

/**
 * ENTERPRISE TrackedPDOStatement - PDOStatement Wrapper for DebugBar Query Tracking
 *
 * ENTERPRISE GALAXY 2025:
 * - Uses parent TrackedPDO for tracking state (single source of truth)
 * - No static state duplication
 * - Complete PDOStatement compatibility
 * - Bound parameters tracking support
 */
class TrackedPDOStatement
{
    private \PDOStatement $stmt;

    private string $sql;

    /**
     * ENTERPRISE: Track parameters bound via bindValue/bindParam
     * These aren't passed to execute(), so we need to collect them
     */
    private array $boundParams = [];

    public function __construct(\PDOStatement $stmt, string $sql)
    {
        $this->stmt = $stmt;
        $this->sql = $sql;
    }

    /**
     * ENTERPRISE: Track bindValue calls for parameter logging
     */
    #[\ReturnTypeWillChange]
    public function bindValue($param, $value, $type = \PDO::PARAM_STR): bool
    {
        $this->boundParams[$param] = $value;
        return $this->stmt->bindValue($param, $value, $type);
    }

    /**
     * ENTERPRISE: Track bindParam calls for parameter logging
     */
    #[\ReturnTypeWillChange]
    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = null, $driverOptions = null): bool
    {
        $this->boundParams[$param] = &$var;
        if ($maxLength !== null) {
            return $this->stmt->bindParam($param, $var, $type, $maxLength, $driverOptions);
        }
        return $this->stmt->bindParam($param, $var, $type);
    }

    /**
     * Execute with tracking
     */
    #[\ReturnTypeWillChange]
    public function execute($input_parameters = null): bool
    {
        // ENTERPRISE: Merge input_parameters with bound params for complete logging
        $params = $input_parameters ?: $this->boundParams;
        $startTime = microtime(true);

        try {
            $result = $this->stmt->execute($input_parameters);

            if (TrackedPDO::shouldTrack()) {
                TrackedPDO::trackQueryStatic($this->sql, $params, microtime(true) - $startTime, false);
            }

            return $result;
        } catch (\Throwable $e) {
            if (TrackedPDO::shouldTrack()) {
                TrackedPDO::trackQueryStatic($this->sql, $params, microtime(true) - $startTime, true);
            }

            throw $e;
        }
    }

    /**
     * Proxy fetch methods directly to PDOStatement
     */
    #[\ReturnTypeWillChange]
    public function fetch($fetch_style = \PDO::FETCH_BOTH, $cursor_orientation = \PDO::FETCH_ORI_NEXT, $cursor_offset = 0)
    {
        return $this->stmt->fetch($fetch_style, $cursor_orientation, $cursor_offset);
    }

    #[\ReturnTypeWillChange]
    public function fetchAll($fetch_style = \PDO::FETCH_BOTH, $fetch_argument = null, $ctor_args = null): array
    {
        $args = [$fetch_style];
        if ($fetch_argument !== null) {
            $args[] = $fetch_argument;
        }
        if ($ctor_args !== null) {
            $args[] = $ctor_args;
        }

        return $this->stmt->fetchAll(...$args);
    }

    #[\ReturnTypeWillChange]
    public function fetchColumn($column_number = 0)
    {
        return $this->stmt->fetchColumn($column_number);
    }

    #[\ReturnTypeWillChange]
    public function rowCount(): int
    {
        return $this->stmt->rowCount();
    }

    /**
     * Proxy all other PDOStatement methods
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->stmt, $method], $args);
    }

    public function __get($property)
    {
        return $this->stmt->$property;
    }

    public function __set($property, $value)
    {
        $this->stmt->$property = $value;
    }
}
