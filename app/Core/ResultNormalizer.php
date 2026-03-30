<?php

namespace Need2Talk\Core;

/**
 * ENTERPRISE GALAXY: PostgreSQL Result Normalizer
 *
 * Normalizes database query results to ensure all values are serialization-safe.
 * This is CRITICAL for query caching - PHP resource streams (BYTEA) cannot be
 * serialized and would return null when retrieved from cache.
 *
 * PROBLEM SOLVED:
 * ┌─────────────────────────────────────────────────────────────────────────────┐
 * │ PostgreSQL BYTEA field → PDO returns PHP resource stream                   │
 * │ Resource stream → json_encode/serialize → NULL                             │
 * │ Cache stores NULL → Application receives NULL → Empty message bubbles      │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * SOLUTION:
 * ┌─────────────────────────────────────────────────────────────────────────────┐
 * │ PDO returns result → ResultNormalizer converts resources to strings →      │
 * │ Cache stores valid string → Application receives valid data                │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * SUPPORTED TYPES:
 * - PHP resource streams (BYTEA fields)
 * - PostgreSQL hex-encoded BYTEA (\x format)
 * - Future: interval, tsrange, etc. (extensible architecture)
 *
 * @package Need2Talk\Core
 * @since 2025-12-03
 * @version 1.0.0 - Initial enterprise implementation
 */
class ResultNormalizer
{
    /**
     * Column name patterns that indicate binary/BYTEA content
     * These columns will have their resource streams converted to strings
     */
    private const BYTEA_COLUMN_PATTERNS = [
        'encrypted',
        'content_encrypted',
        'key_encrypted',
        'iv',
        'tag',
        'content_tag',
        'signature',
        'binary_data',
        'blob',
        'raw_data',
        'ciphertext',
    ];

    /**
     * Normalize a query result set for safe serialization/caching
     *
     * Processes all rows and converts non-serializable types (resources) to strings.
     * This MUST be called BEFORE caching query results.
     *
     * Performance: O(n*m) where n=rows, m=columns. Optimized for typical result sets.
     * For 1000 rows × 20 columns = 20,000 iterations, takes ~1ms on modern hardware.
     *
     * @param array $results Query result rows from PDO::fetchAll()
     * @return array Normalized results safe for serialization
     */
    public static function normalize(array $results): array
    {
        if (empty($results)) {
            return $results;
        }

        // Process each row
        foreach ($results as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            // Process each column in the row
            foreach ($row as $column => $value) {
                $results[$rowIndex][$column] = self::normalizeValue($value, $column);
            }
        }

        return $results;
    }

    /**
     * Normalize a single row (for findOne() results)
     *
     * @param array|null $row Single row from query
     * @return array|null Normalized row
     */
    public static function normalizeRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        foreach ($row as $column => $value) {
            $row[$column] = self::normalizeValue($value, $column);
        }

        return $row;
    }

    /**
     * Normalize a single value based on its type
     *
     * Handles:
     * 1. PHP resource streams (from BYTEA fields) → converted to string
     * 2. PostgreSQL hex-encoded BYTEA (\x...) → decoded to binary string
     * 3. All other types → passed through unchanged
     *
     * @param mixed $value The value to normalize
     * @param string $column Column name (used for context-aware normalization)
     * @return mixed Normalized value
     */
    private static function normalizeValue(mixed $value, string $column): mixed
    {
        // Handle PHP resource streams (BYTEA fields return as streams)
        if (is_resource($value)) {
            return self::normalizeResourceStream($value);
        }

        // Handle PostgreSQL hex-encoded BYTEA format (\x...)
        // This format is returned when bytea_output = 'hex' (PostgreSQL default since 9.0)
        if (is_string($value) && str_starts_with($value, '\\x') && strlen($value) > 2) {
            // Only decode if this looks like a BYTEA column
            if (self::isByteaColumn($column)) {
                return self::decodeHexBytea($value);
            }
        }

        // All other types pass through unchanged
        return $value;
    }

    /**
     * Convert a PHP resource stream to string
     *
     * CRITICAL: Resource streams can only be read ONCE. After stream_get_contents(),
     * the stream pointer is at the end. This is why caching without normalization fails -
     * the first read consumes the stream, subsequent reads return empty string.
     *
     * @param resource $stream The resource stream to convert
     * @return string|null The stream contents, or null on failure
     */
    private static function normalizeResourceStream($stream): ?string
    {
        try {
            // Rewind stream to beginning (in case it was partially read)
            if (stream_get_meta_data($stream)['seekable'] ?? false) {
                rewind($stream);
            }

            // Read entire stream contents
            $content = stream_get_contents($stream);

            // Return null if stream was empty or read failed
            return $content !== false ? $content : null;

        } catch (\Throwable $e) {
            // Log error but don't break the application
            if (class_exists('\Need2Talk\Services\Logger')) {
                \Need2Talk\Services\Logger::warning('ResultNormalizer: Failed to read resource stream', [
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
        }
    }

    /**
     * Decode PostgreSQL hex-encoded BYTEA string
     *
     * PostgreSQL BYTEA fields are returned as hex strings in format: \xHEXDATA
     * Example: \x636961 → "cia" (hex 63=c, 69=i, 61=a)
     *
     * @param string $hexString The hex-encoded string (with \x prefix)
     * @return string The decoded binary data
     */
    private static function decodeHexBytea(string $hexString): string
    {
        // Remove \x prefix and decode hex to binary
        $hexData = substr($hexString, 2);

        // hex2bin returns false on invalid hex, return original on failure
        $decoded = hex2bin($hexData);

        return $decoded !== false ? $decoded : $hexString;
    }

    /**
     * Check if a column name indicates BYTEA content
     *
     * @param string $column Column name to check
     * @return bool True if column likely contains BYTEA data
     */
    private static function isByteaColumn(string $column): bool
    {
        $columnLower = strtolower($column);

        foreach (self::BYTEA_COLUMN_PATTERNS as $pattern) {
            if (str_contains($columnLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a result set contains any non-serializable values
     *
     * Utility method for debugging cache issues.
     * Returns details about which columns have problematic values.
     *
     * @param array $results Query results to check
     * @return array Analysis report with 'has_issues' boolean and 'details' array
     */
    public static function analyze(array $results): array
    {
        $report = [
            'has_issues' => false,
            'total_rows' => count($results),
            'resource_streams' => [],
            'hex_bytea' => [],
        ];

        if (empty($results)) {
            return $report;
        }

        foreach ($results as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $column => $value) {
                if (is_resource($value)) {
                    $report['has_issues'] = true;
                    $report['resource_streams'][] = [
                        'row' => $rowIndex,
                        'column' => $column,
                        'type' => get_resource_type($value),
                    ];
                }

                if (is_string($value) && str_starts_with($value, '\\x') && self::isByteaColumn($column)) {
                    $report['hex_bytea'][] = [
                        'row' => $rowIndex,
                        'column' => $column,
                        'length' => strlen($value),
                    ];
                }
            }
        }

        return $report;
    }
}
