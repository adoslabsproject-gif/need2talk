#!/usr/bin/env php
<?php

/**
 * ENTERPRISE GALAXY: ML Initial Training Script
 *
 * Trains the AdvancedMLThreatEngine with historical data from:
 * 1. Database: security_events table (~587 records)
 * 2. Database: vulnerability_scan_bans table (~103 records)
 * 3. Log files: storage/logs/security-*.log (~2 months)
 *
 * Usage:
 *   php scripts/ml-initial-training.php
 *   php scripts/ml-initial-training.php --reset  # Reset model first
 *   php scripts/ml-initial-training.php --dry-run  # Show what would be trained
 *
 * @version 1.0.0
 */

// Prevent web access
if (PHP_SAPI !== 'cli') {
    die('This script can only be run from command line');
}

// ENTERPRISE: Skip session initialization in CLI mode
// This prevents "Headers already sent" warnings when bootstrap.php
// tries to initialize sessions (which makes no sense for CLI scripts)
define('SKIP_SESSION_INIT', true);

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║     ENTERPRISE GALAXY: ML Threat Engine Initial Training     ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Parse arguments
$reset = in_array('--reset', $argv);
$dryRun = in_array('--dry-run', $argv);

if ($dryRun) {
    echo "🔍 DRY RUN MODE - No training will be performed\n\n";
}

// Bootstrap application
require_once dirname(__DIR__) . '/app/bootstrap.php';

use Need2Talk\Services\Security\AdvancedMLThreatEngine;
use Need2Talk\Services\Logger;

echo "📊 Initializing ML Engine...\n";

try {
    $engine = new AdvancedMLThreatEngine();
    $currentStats = $engine->getStats();

    echo "   Current model status: {$currentStats['learning_status']}\n";
    echo "   Current training samples: {$currentStats['training_samples']}\n";
    echo "   Model version: {$currentStats['model_version']}\n\n";

    // Reset if requested
    if ($reset) {
        echo "⚠️  Resetting model to defaults...\n";
        if (!$dryRun) {
            $engine->resetModel();
            echo "   ✅ Model reset complete\n\n";
        } else {
            echo "   [DRY RUN] Would reset model\n\n";
        }
    }

    // Get database connection
    echo "🗄️  Connecting to database...\n";
    $pdo = db_pdo();

    // Count records
    $securityEventsCount = $pdo->query("SELECT COUNT(*) FROM security_events")->fetchColumn();
    $bansCount = $pdo->query("SELECT COUNT(*) FROM vulnerability_scan_bans")->fetchColumn();

    echo "   Found {$securityEventsCount} security_events records\n";
    echo "   Found {$bansCount} vulnerability_scan_bans records\n";

    // Count log files
    $logDir = dirname(__DIR__) . '/storage/logs';
    $logFiles = glob($logDir . '/security-*.log');
    $logLines = 0;
    foreach ($logFiles as $file) {
        $logLines += count(file($file));
    }

    echo "   Found " . count($logFiles) . " security log files ({$logLines} lines)\n\n";

    // Date range
    $dateRange = $pdo->query("
        SELECT
            MIN(created_at) as oldest,
            MAX(created_at) as newest
        FROM security_events
    ")->fetch(PDO::FETCH_ASSOC);

    echo "📅 Data range:\n";
    echo "   Oldest event: " . ($dateRange['oldest'] ?? 'N/A') . "\n";
    echo "   Newest event: " . ($dateRange['newest'] ?? 'N/A') . "\n\n";

    if ($dryRun) {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "DRY RUN SUMMARY:\n";
        echo "  Would train from:\n";
        echo "    - {$bansCount} vulnerability bans (all threats)\n";
        echo "    - {$securityEventsCount} security events (mixed)\n";
        echo "    - {$logLines} log lines\n";
        echo "  Estimated total samples: " . ($bansCount + $securityEventsCount) . "+\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        exit(0);
    }

    // Start training
    echo "🚀 Starting full training...\n\n";
    $startTime = microtime(true);

    $results = $engine->fullTraining($pdo, $logDir);

    $duration = round(microtime(true) - $startTime, 2);

    // Display results
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "                    TRAINING RESULTS\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";

    echo "Database Training:\n";
    if (isset($results['database'])) {
        echo "   Vulnerability Bans:\n";
        echo "      Trained: {$results['database']['vulnerability_bans']['trained']}\n";
        echo "      Errors:  {$results['database']['vulnerability_bans']['errors']}\n";
        echo "   Security Events:\n";
        echo "      Trained: {$results['database']['security_events']['trained']}\n";
        echo "      Errors:  {$results['database']['security_events']['errors']}\n";
        $skipped = $results['database']['skipped_noise'] ?? 0;
        echo "      Skipped (noise): {$skipped}\n";
    }
    echo "\n";

    echo "📁 Log File Training:\n";
    if (isset($results['logs'])) {
        echo "   ✅ Trained: {$results['logs']['trained']}\n";
        echo "   ❌ Errors:  {$results['logs']['errors']}\n";
    } else {
        echo "   (No log training performed)\n";
    }
    echo "\n";

    echo "═══════════════════════════════════════════════════════════════\n";
    echo "                      FINAL STATUS\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";

    $finalStats = $engine->getStats();
    echo "📈 Total training samples: {$finalStats['training_samples']}\n";
    echo "🎯 Model status: {$finalStats['learning_status']}\n";
    echo "🔍 Threats detected: {$finalStats['threats_detected']}\n";
    echo "✅ True positives: {$finalStats['true_positives']}\n";
    echo "⏱️  Duration: {$duration}s\n\n";

    // Category breakdown
    if (!empty($finalStats['categories'])) {
        echo "📋 Category Breakdown:\n";
        arsort($finalStats['categories']);
        foreach ($finalStats['categories'] as $category => $count) {
            echo "   {$category}: {$count}\n";
        }
        echo "\n";
    }

    // Configuration
    echo "⚙️  Current Configuration:\n";
    $config = $engine->getConfig();
    echo "   ML Enabled: " . ($config['ml_enabled'] ? 'Yes' : 'No') . "\n";
    echo "   ML Weight: " . ($config['ml_weight'] * 100) . "%\n";
    echo "   Block Threshold: " . ($config['block_threshold'] * 100) . "%\n";
    echo "   Ban Threshold: " . ($config['ban_threshold'] * 100) . "%\n";
    echo "   Auto Learn: " . ($config['auto_learn'] ? 'Yes' : 'No') . "\n";
    echo "   Learning Rate: {$config['learning_rate']}\n";
    echo "\n";

    echo "═══════════════════════════════════════════════════════════════\n";
    echo "✨ ML Engine training complete!\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";

    Logger::info('ML Initial Training completed via script', [
        'total_samples' => $finalStats['training_samples'],
        'status' => $finalStats['learning_status'],
        'duration_seconds' => $duration,
    ]);

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";

    Logger::error('ML Initial Training failed', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    exit(1);
}

exit(0);
