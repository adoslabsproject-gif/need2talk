#!/usr/bin/env php
<?php
/**
 * ENTERPRISE GALAXY: Cron Execution History Cleanup
 *
 * Deletes cron_executions records older than 7 days
 * Schedule: Daily at 12:50 (after other maintenance jobs)
 *
 * RATIONALE:
 * - cron_executions table grows ~200+ records/day (31 jobs × ~7 runs each)
 * - After 7 days: ~1,400+ records (enough for troubleshooting)
 * - Without cleanup: 10,000+ records/month (performance degradation)
 *
 * @version 2.0.0 - Rewritten from bash to PHP for container compatibility
 */

// Configuration
$retentionDays = 7;

// Load environment for database connection
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value, '"\'');
        putenv("$key=$value");
    }
}

// Database connection
$host = getenv('DB_HOST') ?: 'postgres';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_DATABASE') ?: 'need2talk';
$user = getenv('DB_USERNAME') ?: 'need2talk';
$password = getenv('DB_PASSWORD') ?: '';

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Get count before cleanup
    // NOTE: PostgreSQL INTERVAL doesn't support parameterized values, must be literal
    $countBefore = (int) $pdo->query(
        "SELECT COUNT(*) FROM cron_executions WHERE executed_at < NOW() - INTERVAL '{$retentionDays} days'"
    )->fetchColumn();

    if ($countBefore > 0) {
        // Delete old records
        $pdo->exec(
            "DELETE FROM cron_executions WHERE executed_at < NOW() - INTERVAL '{$retentionDays} days'"
        );

        echo "Deleted {$countBefore} cron execution records older than {$retentionDays} days\n";
    } else {
        echo "No old cron execution records to delete\n";
    }

    // Show current table size
    $total = $pdo->query("SELECT COUNT(*) FROM cron_executions")->fetchColumn();
    echo "Current cron_executions table size: {$total} records\n";

    exit(0);

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
