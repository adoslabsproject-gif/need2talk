#!/usr/bin/env php
<?php

/**
 * Log Monitor Script - need2talk
 *
 * Monitora log in real-time e invia alert per errori critici
 * Utile per debug e monitoraggio produzione
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

// Check for --once flag (single execution for dashboard)
$runOnce = in_array('--once', $argv);

if (!$runOnce) {
    echo "📊 Starting log monitor...\n";
    echo "Press Ctrl+C to stop\n\n";
}

$logPath = APP_ROOT.'/storage/logs';
$lastPositions = [];

// File da monitorare
$logFiles = [
    'errors.log' => '🚨',
    'php_errors.log' => '⚠️',
    'security.log' => '🔒',
    'websocket.log' => '🔌',
    'requests.log' => '🌐',
];

// Funzione per leggere nuove righe
function readNewLines($filePath, &$lastPosition)
{
    if (! file_exists($filePath)) {
        return [];
    }

    $fileSize = filesize($filePath);

    if (! isset($lastPosition)) {
        $lastPosition = max(0, $fileSize - 1000); // Inizia dagli ultimi 1KB
    }

    if ($fileSize <= $lastPosition) {
        return [];
    }

    $handle = fopen($filePath, 'r');
    fseek($handle, $lastPosition);

    $lines = [];
    while (($line = fgets($handle)) !== false) {
        $lines[] = rtrim($line);
    }

    $lastPosition = ftell($handle);
    fclose($handle);

    return $lines;
}

// Funzione per colorare output
function colorizeLogLine($line, $emoji)
{
    $timestamp = date('H:i:s');

    // Colori ANSI
    $colors = [
        '🚨' => "\033[91m", // Rosso brillante per errori
        '⚠️' => "\033[93m",  // Giallo per warnings
        '🔒' => "\033[95m", // Magenta per sicurezza
        '🔌' => "\033[96m", // Ciano per websocket
        '🌐' => "\033[92m",  // Verde per requests
    ];

    $reset = "\033[0m";
    $color = $colors[$emoji] ?? '';

    return "[$timestamp] $emoji $color$line$reset";
}

// Loop di monitoring
try {
    $iterations = $runOnce ? 1 : PHP_INT_MAX;
    $count = 0;

    while ($count < $iterations) {
        $foundLines = false;

        foreach ($logFiles as $logFile => $emoji) {
            $filePath = "$logPath/$logFile";
            $lines = readNewLines($filePath, $lastPositions[$logFile]);

            foreach ($lines as $line) {
                if (! empty(trim($line))) {
                    echo colorizeLogLine($line, $emoji)."\n";
                    $foundLines = true;

                    // Alert per errori critici
                    if (strpos(strtolower($line), 'fatal') !== false ||
                        strpos(strtolower($line), 'critical') !== false) {
                        echo "\033[41m🚨 CRITICAL ERROR DETECTED 🚨\033[0m\n";
                        // TODO: Invia notifica (email, Slack, etc.)
                    }
                }
            }
        }

        if ($runOnce) {
            if (!$foundLines) {
                echo "No recent log entries found.\n";
            }
            break;
        }

        usleep(500000); // 0.5 secondi
        $count++;
    }

} catch (Exception $e) {
    echo "\n❌ Monitor error: ".$e->getMessage()."\n";
        Logger::error('DEFAULT: Log monitor crashed', [
            'error' => $e->getMessage(),
            'script' => 'log-monitor.php',
        ]);
    exit(1);
}
