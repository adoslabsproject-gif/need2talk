#!/usr/bin/env php
<?php

/**
 * Performance Worker - Single Worker Process
 *
 * Worker process per performance testing che simula operazioni utente:
 * - Registration simulation
 * - Email verification simulation
 * - Email queue operations
 * - Mixed load patterns
 */

declare(strict_types=1);

// ENTERPRISE TIPS: Disable output compression/buffering for clean JSON output
// When called via shell_exec, we need raw output without any encoding
ini_set('zlib.output_compression', 'Off');
ini_set('output_buffering', 'Off');
ini_set('implicit_flush', '1');

// Clean any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Bootstrap
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT.'/app/bootstrap.php';

use Need2Talk\Services\AsyncEmailQueue;
use Need2Talk\Services\VerificationSpikeOptimizer;

/**
 * Performance Worker Process
 */
class PerformanceWorker
{
    private int $workerId;
    private int $opsPerSecond;
    private int $duration;
    private array $scenarios;
    private bool $running = true;
    private int $operationsCompleted = 0;
    private int $operationsFailed = 0;
    private float $startTime;

    private AsyncEmailQueue $emailQueue;
    private VerificationSpikeOptimizer $spikeOptimizer;

    public function __construct(int $workerId, int $opsPerSecond, int $duration, array $scenarios)
    {
        $this->workerId = $workerId;
        $this->opsPerSecond = $opsPerSecond;
        $this->duration = $duration;
        $this->scenarios = $scenarios;
        $this->startTime = microtime(true);

        // Initialize services
        $this->emailQueue = new AsyncEmailQueue();
        $this->spikeOptimizer = new VerificationSpikeOptimizer();
    }

    /**
     * Run worker operations
     */
    public function run(): void
    {
        $endTime = $this->startTime + $this->duration;
        $operationInterval = 1.0 / $this->opsPerSecond; // Seconds between operations
        $lastOperation = $this->startTime - $operationInterval; // Allow first operation immediately

        while ($this->running && microtime(true) < $endTime) {
            $currentTime = microtime(true);

            // Check if it's time for next operation
            if ($currentTime - $lastOperation >= $operationInterval) {
                $this->performRandomOperation();
                $lastOperation = $currentTime;
            }

            // Small sleep to prevent CPU spinning
            usleep(1000); // 1ms
        }

        $this->reportResults();
    }

    /**
     * Perform a random operation based on scenario weights
     */
    private function performRandomOperation(): void
    {
        $scenario = $this->selectScenario();
        $startTime = microtime(true);

        try {
            switch ($scenario) {
                case 'email_queue':
                    $this->performEmailQueueOperation();
                    break;
                case 'verification':
                    $this->performVerificationOperation();
                    break;
                case 'registration':
                    $this->performRegistrationOperation();
                    break;
                case 'mixed_load':
                    $this->performMixedOperation();
                    break;
            }

            $this->operationsCompleted++;

        } catch (Exception $e) {
            $this->operationsFailed++;

            // Log error (simplified for performance)
            error_log("Worker {$this->workerId} operation failed: ".$e->getMessage());
        }
    }

    /**
     * Select scenario based on weights
     */
    private function selectScenario(): string
    {
        $rand = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($this->scenarios as $scenario => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $scenario;
            }
        }

        // Fallback
        return array_key_first($this->scenarios);
    }

    /**
     * Perform email queue operation
     * ENTERPRISE TIPS: Use only 4 dedicated test users to prevent random test emails
     * Each user gets exactly ONE email per test run
     */
    private function performEmailQueueOperation(): void
    {
        // ENTERPRISE: Use ONLY dedicated test users (created in database)
        // Test users: perf_test_1@need2talk.test through perf_test_4@need2talk.test
        // IDs: 99999, 100000, 100001, 100002
        $testUsers = [
            ['id' => 99999, 'email' => 'perf_test_1@need2talk.test', 'nickname' => 'PerfTest1'],
            ['id' => 100000, 'email' => 'perf_test_2@need2talk.test', 'nickname' => 'PerfTest2'],
            ['id' => 100001, 'email' => 'perf_test_3@need2talk.test', 'nickname' => 'PerfTest3'],
            ['id' => 100002, 'email' => 'perf_test_4@need2talk.test', 'nickname' => 'PerfTest4'],
        ];

        // ENTERPRISE TIPS: Pick user sequentially based on operation count to guarantee 1 email per user
        // This ensures fair distribution: operation 0 -> user 0, operation 1 -> user 1, etc.
        $userIndex = $this->operationsCompleted % count($testUsers);
        $user = $testUsers[$userIndex];

        $result = $this->emailQueue->queueVerificationEmail($user['id'], $user['email'], $user['nickname']);

        if (! $result) {
            throw new Exception('Failed to queue email');
        }
    }

    /**
     * Perform verification operation
     */
    private function performVerificationOperation(): void
    {
        // Generate a test token
        $token = bin2hex(random_bytes(32));

        // Test the spike optimizer
        $result = $this->spikeOptimizer->optimizeVerification($token);

        // Expected to return null for non-existent tokens, which is normal
    }

    /**
     * Perform registration simulation
     * ENTERPRISE TIPS: Use only 4 dedicated test users to prevent random test emails
     */
    private function performRegistrationOperation(): void
    {
        // ENTERPRISE: Use ONLY dedicated test users (same 4 users)
        $testUsers = [
            ['id' => 99999, 'email' => 'perf_test_1@need2talk.test', 'nickname' => 'PerfTest1'],
            ['id' => 100000, 'email' => 'perf_test_2@need2talk.test', 'nickname' => 'PerfTest2'],
            ['id' => 100001, 'email' => 'perf_test_3@need2talk.test', 'nickname' => 'PerfTest3'],
            ['id' => 100002, 'email' => 'perf_test_4@need2talk.test', 'nickname' => 'PerfTest4'],
        ];

        // Simulate some processing time
        usleep(mt_rand(1000, 5000)); // 1-5ms

        // Pick random test user
        $user = $testUsers[array_rand($testUsers)];

        // Simulate queueing welcome email (won't actually send, just tests queue)
        $this->emailQueue->queueEmail([
            'type' => 'welcome',
            'user_id' => $user['id'],
            'email' => $user['email'],
            'template' => 'welcome',
            'priority' => 5,
        ]);
    }

    /**
     * Perform mixed operations
     */
    private function performMixedOperation(): void
    {
        // Randomly choose between different operations
        $operations = ['email_queue', 'verification'];
        $operation = $operations[mt_rand(0, count($operations) - 1)];

        switch ($operation) {
            case 'email_queue':
                $this->performEmailQueueOperation();
                break;
            case 'verification':
                $this->performVerificationOperation();
                break;
        }
    }

    /**
     * Report worker results
     */
    private function reportResults(): void
    {
        $duration = microtime(true) - $this->startTime;
        $opsPerSecond = $duration > 0 ? $this->operationsCompleted / $duration : 0;

        $report = [
            'worker_id' => $this->workerId,
            'duration' => round($duration, 2),
            'operations_completed' => $this->operationsCompleted,
            'operations_failed' => $this->operationsFailed,
            'ops_per_second' => round($opsPerSecond, 2),
            'success_rate' => $this->operationsCompleted > 0 ?
                round(($this->operationsCompleted / ($this->operationsCompleted + $this->operationsFailed)) * 100, 2) : 0,
        ];

        // ENTERPRISE TIPS: Ensure absolutely NO compression/buffering before JSON output
        // Bootstrap might have re-enabled compression, so we force it off again
        while (ob_get_level()) {
            ob_end_clean();
        }
        ini_set('zlib.output_compression', 'Off');

        echo json_encode($report)."\n";

        // Force flush to ensure output is sent immediately
        if (function_exists('flush')) {
            flush();
        }
    }
}

/**
 * Main execution
 */
function main(): void
{
    // Parse command line arguments
    $options = getopt('', [
        'worker-id:',
        'ops-per-second:',
        'duration:',
        'scenarios:',
    ]);

    $workerId = (int) ($options['worker-id'] ?? 1);
    $opsPerSecond = (int) ($options['ops-per-second'] ?? 10);
    $duration = (int) ($options['duration'] ?? 60);

    // Parse scenarios
    $scenarios = [];
    if (isset($options['scenarios'])) {
        parse_str($options['scenarios'], $scenarios);
        $scenarios = array_map('intval', $scenarios);
    } else {
        $scenarios = [
            'email_queue' => 40,
            'verification' => 30,
            'registration' => 20,
            'mixed_load' => 10,
        ];
    }

    try {
        $worker = new PerformanceWorker($workerId, $opsPerSecond, $duration, $scenarios);
        $worker->run();
    } catch (Exception $e) {
        // ENTERPRISE TIPS: Ensure clean output for error JSON too
        while (ob_get_level()) {
            ob_end_clean();
        }
        ini_set('zlib.output_compression', 'Off');

        echo json_encode([
            'worker_id' => $workerId,
            'error' => $e->getMessage(),
            'failed' => true,
        ])."\n";

        if (function_exists('flush')) {
            flush();
        }
        exit(1);
    }
}

// Run the worker
main();
