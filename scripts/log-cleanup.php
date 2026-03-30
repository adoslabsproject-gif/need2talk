#!/usr/bin/env php
<?php

/**
 * Log Cleanup Script - need2talk
 *
 * Script per pulizia automatica dei log vecchi
 * Da eseguire via cron giornalmente
 */
define('APP_ROOT', dirname(__DIR__));

// Composer autoloader
require_once APP_ROOT.'/vendor/autoload.php';

// Load environment
if (file_exists(APP_ROOT.'/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(APP_ROOT);
    $dotenv->load();
}

// Bootstrap app
require_once APP_ROOT.'/app/bootstrap.php';

use Need2Talk\Services\Logger;

echo "🧹 Starting log cleanup...\n";

try {
    // Cleanup log vecchi (default 30 giorni)
    $daysToKeep = (int) ($argv[1] ?? 30);
    $cleaned = Logger::cleanOldLogs($daysToKeep);

    echo "✅ Cleaned $cleaned old log files (keeping last $daysToKeep days)\n";

    // Mostra dimensioni directory log
    $stats = Logger::getLogDirectorySize();
    echo "📊 Log directory stats:\n";
    echo "   - Total files: {$stats['file_count']}\n";
    echo "   - Total size: {$stats['total_size_mb']} MB\n";

    // Log dell'operazione
        Logger::info('DEFAULT: Log cleanup completed', [
            'files_cleaned' => $cleaned,
            'days_to_keep' => $daysToKeep,
            'directory_stats' => $stats,
            'script' => 'log-cleanup.php',
        ]);

    echo "✅ Log cleanup completed successfully\n";

} catch (Exception $e) {
    echo '❌ Error during log cleanup: '.$e->getMessage()."\n";
        Logger::error('DEFAULT: Log cleanup failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'script' => 'log-cleanup.php',
        ]);
    exit(1);
}
