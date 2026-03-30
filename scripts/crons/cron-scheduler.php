#!/usr/bin/env php
<?php

/**
 * 🚀 ENTERPRISE GALAXY: Master Cron Scheduler
 *
 * Entry point for all cron jobs - runs every minute via crontab
 * Checks all enabled jobs and executes those that should run
 *
 * SCALABILITY: Handles millions of users with Redis-based locking
 * ARCHITECTURE: Database-driven scheduling with web UI management
 *
 * Add to crontab:
 *   * * * * * docker exec need2talk_php php /var/www/html/scripts/cron-scheduler.php >> /var/log/cron.log 2>&1
 *
 * Or on host machine (macOS):
 *   * * * * * cd /var/www/need2talk && docker exec need2talk_php php /var/www/html/scripts/cron-scheduler.php >> storage/logs/cron.log 2>&1
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\CronManager;
use Need2Talk\Services\Logger;

// Suppress output for cron (will be logged instead)
$quietMode = in_array('--quiet', $argv ?? []);

if (!$quietMode) {
    echo "[" . date('Y-m-d H:i:s') . "] 🚀 ENTERPRISE CRON SCHEDULER - Starting\n";
}

$startTime = microtime(true);

try {
    $cronManager = CronManager::getInstance();

    // Get all enabled jobs
    $jobs = $cronManager->getAllJobs();

    if (empty($jobs)) {
        if (!$quietMode) {
            echo "[" . date('Y-m-d H:i:s') . "] ℹ️  No jobs registered\n";
        }
        exit(0);
    }

    $executedCount = 0;
    $skippedCount = 0;

    foreach ($jobs as $job) {
        // Skip disabled jobs
        if (!$job['enabled']) {
            $skippedCount++;
            continue;
        }

        // Check if job should run based on schedule
        if ($cronManager->shouldRun($job['name'])) {
            if (!$quietMode) {
                echo "[" . date('Y-m-d H:i:s') . "] ⏳ Executing: {$job['name']}\n";
            }

            $result = $cronManager->executeJob($job['name']);

            if ($result['success']) {
                if (!$quietMode) {
                    echo "[" . date('Y-m-d H:i:s') . "] ✅ Completed: {$job['name']} ({$result['execution_time']}ms)\n";
                }
                Logger::info('Cron job executed successfully', [
                    'job' => $job['name'],
                    'execution_time' => $result['execution_time']
                ]);
            } else {
                if (!$quietMode) {
                    echo "[" . date('Y-m-d H:i:s') . "] ❌ Failed: {$job['name']} - {$result['message']}\n";
                }
                Logger::error('Cron job execution failed', [
                    'job' => $job['name'],
                    'message' => $result['message'],
                    'execution_time' => $result['execution_time']
                ]);
            }

            $executedCount++;
        }
    }

    $totalTime = round((microtime(true) - $startTime) * 1000, 2);

    if (!$quietMode) {
        echo "[" . date('Y-m-d H:i:s') . "] 📊 Summary: {$executedCount} executed, {$skippedCount} skipped\n";
        echo "[" . date('Y-m-d H:i:s') . "] ⏱️  Total time: {$totalTime}ms\n";
        echo "[" . date('Y-m-d H:i:s') . "] ✅ Scheduler completed\n";
    }

    // Log summary
    if ($executedCount > 0) {
        Logger::info('Cron scheduler completed', [
            'executed' => $executedCount,
            'skipped' => $skippedCount,
            'total_time' => $totalTime
        ]);
    }

} catch (\Exception $e) {
    $totalTime = round((microtime(true) - $startTime) * 1000, 2);

    if (!$quietMode) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ ERROR: " . $e->getMessage() . "\n";
    }

    Logger::error('Cron scheduler failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'total_time' => $totalTime
    ]);

    exit(1);
}
