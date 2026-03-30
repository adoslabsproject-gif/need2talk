<?php

/**
 * ENTERPRISE GALAXY: PostgreSQL Partitioning Migration
 *
 * DEPRECATED: This script was originally for MySQL partitioning.
 * PostgreSQL partitioning is already handled via native LIST partitioning
 * in the enterprise_app_errors table (partition_month column).
 *
 * For new tables, use PostgreSQL declarative partitioning:
 * - CREATE TABLE ... PARTITION BY RANGE/LIST (column)
 * - CREATE TABLE partition_name PARTITION OF parent FOR VALUES ...
 *
 * @see docs/database-partitioning.md
 */

require_once __DIR__ . '/../app/bootstrap.php';

echo "=== ENTERPRISE GALAXY: PARTITIONING STATUS CHECK ===\n\n";

// ENTERPRISE: PostgreSQL connection
$pdo = new PDO(
    "pgsql:host=" . env('DB_HOST', 'postgres') . ";port=" . env('DB_PORT', '5432') . ";dbname=" . env('DB_NAME', 'need2talk'),
    env('DB_USER', 'need2talk'),
    env('DB_PASSWORD', '')
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // Check existing partitioned tables in PostgreSQL
    echo "Checking partitioned tables in PostgreSQL...\n\n";

    $stmt = $pdo->query("
        SELECT
            parent.relname AS parent_table,
            child.relname AS partition_name,
            pg_get_expr(child.relpartbound, child.oid) AS partition_bounds
        FROM pg_inherits
        JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
        JOIN pg_class child ON pg_inherits.inhrelid = child.oid
        WHERE parent.relkind = 'p'
        ORDER BY parent.relname, child.relname
    ");

    $partitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($partitions)) {
        echo "No partitioned tables found.\n";
        echo "\nTo create a partitioned table in PostgreSQL:\n";
        echo "  CREATE TABLE my_table (...) PARTITION BY RANGE (created_at);\n";
        echo "  CREATE TABLE my_table_2025_12 PARTITION OF my_table\n";
        echo "    FOR VALUES FROM ('2025-12-01') TO ('2026-01-01');\n";
    } else {
        echo "Partitioned tables found:\n\n";

        $currentParent = '';
        foreach ($partitions as $p) {
            if ($p['parent_table'] !== $currentParent) {
                if ($currentParent !== '') {
                    echo "\n";
                }
                echo "📊 Table: {$p['parent_table']}\n";
                $currentParent = $p['parent_table'];
            }
            echo "   └─ {$p['partition_name']}: {$p['partition_bounds']}\n";
        }
    }

    echo "\n✅ Partitioning check complete.\n";
    echo "\nNote: This script is for STATUS CHECK only.\n";
    echo "PostgreSQL partitioning should be managed via migrations.\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
