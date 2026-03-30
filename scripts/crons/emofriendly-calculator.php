#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 🚀 ENTERPRISE GALAXY: EmoFriendly Calculator
 *
 * Calcola i profili emotivi e genera suggerimenti di amicizia basati su
 * compatibilità emotiva (Anime Affini e Anime Complementari).
 *
 * SCHEDULE: 0 * * * * (ogni ora)
 *
 * PROCESSO:
 * 1. Aggiorna profili emotivi per utenti con attività recente (batch 500)
 * 2. Genera suggerimenti (15 affini + 15 complementari per utente)
 * 3. Cleanup suggerimenti scaduti
 *
 * Usage:
 *   php scripts/crons/emofriendly-calculator.php
 *   docker exec need2talk_php php /var/www/html/scripts/crons/emofriendly-calculator.php
 *
 * @package Need2Talk\Crons
 */

// Force Italian timezone
date_default_timezone_set('Europe/Rome');

// CLI only
if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from command line\n");
}

// Bootstrap
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/app/bootstrap.php';

use Need2Talk\Services\EmoFriendlyService;
use Need2Talk\Services\Logger;

echo "[" . date('Y-m-d H:i:s') . "] EmoFriendly Calculator starting...\n";
$startTime = microtime(true);

try {
    $service = new EmoFriendlyService();

    // Esegui calcolo completo
    $stats = $service->runFullCalculation();

    $duration = round((microtime(true) - $startTime) * 1000, 2);

    echo "✅ EmoFriendly Calculator completed:\n";
    echo "   - Profiles updated: {$stats['profiles_updated']}\n";
    echo "   - Suggestions generated: {$stats['suggestions_generated']}\n";
    echo "   - Expired cleaned: {$stats['expired_cleaned']}\n";
    echo "   - Duration: {$duration}ms\n";

    exit(0);

} catch (\Exception $e) {
    Logger::error('[EmoFriendly] Calculator failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
