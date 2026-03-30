#!/usr/bin/env php
<?php
/**
 * ENTERPRISE GALAXY: Cache Warmup for Public Pages (SEO/Google Bots)
 *
 * SCHEDULE: Daily at 05:00 AM (same time as DB cache warmup)
 * PURPOSE: Pre-cache public HTML pages for Google/Bing bots
 * PERFORMANCE: ~1 second execution (4 HTTP requests)
 * SAFE: Idempotent - can run multiple times without side effects
 *
 * @author Claude Code (Enterprise Galaxy Initiative)
 * @since 2025-10-27
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use Need2Talk\Services\Logger;

// ============================================================================
// Configuration
// ============================================================================
const SCRIPT_NAME = 'cache-warmup-public-pages';
const LOG_PREFIX = '[PUBLIC CACHE WARMUP]';
const REQUEST_TIMEOUT = 5;  // 5 seconds timeout per request
const REQUEST_DELAY = 100;  // 100ms delay between requests

// ============================================================================
// Main Execution
// ============================================================================

echo str_repeat("=", 70) . "\n";
echo "🔥 ENTERPRISE GALAXY: PUBLIC PAGES CACHE WARMUP\n";
echo str_repeat("=", 70) . "\n\n";

$startTime = microtime(true);

try {
    // Pagine pubbliche critiche da pre-cachare (per bot Google/Bing/SEO)
    // ENTERPRISE: /auth/* pages are NOT cacheable (contain dynamic CSRF tokens)
    $publicPages = [
        '/',                    // Homepage
        // '/auth/register',    // ← REMOVED: Contains dynamic CSRF token (causes login failure)
        // '/auth/login',       // ← REMOVED: Contains dynamic CSRF token (causes login failure)
        '/legal/privacy',       // Privacy policy
        '/legal/terms',         // Terms of service
        '/about',               // About page
    ];

    $domain = env('APP_URL', 'http://localhost');
    $warmedPages = 0;
    $failedPages = 0;
    $totalSize = 0;

    echo "Domain: {$domain}\n";
    echo "Pages to warm: " . count($publicPages) . "\n\n";

    echo "⏳ Warming up public pages...\n\n";

    foreach ($publicPages as $page) {
        $url = $domain . $page;

        try {
            // HTTP request con timeout corto
            $context = stream_context_create([
                'http' => [
                    'timeout' => REQUEST_TIMEOUT,
                    'header' => "User-Agent: Need2Talk-CacheWarmup/1.0 (SEO Bot Preloader)\r\n",
                    'ignore_errors' => true,  // Capture HTTP error responses
                ]
            ]);

            $startRequest = microtime(true);
            $response = @file_get_contents($url, false, $context);
            $requestTime = round((microtime(true) - $startRequest) * 1000, 2);

            // Check HTTP status code
            $statusCode = 200;
            if (isset($http_response_header) && count($http_response_header) > 0) {
                preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
                if (isset($matches[1])) {
                    $statusCode = (int)$matches[1];
                }
            }

            if ($response !== false && $statusCode >= 200 && $statusCode < 400) {
                $size = strlen($response);
                $sizeKB = round($size / 1024, 2);
                $totalSize += $size;

                echo sprintf(
                    "   ✅ %-25s  %6.2f KB  %6.2fms  (HTTP %d)\n",
                    $page,
                    $sizeKB,
                    $requestTime,
                    $statusCode
                );
                $warmedPages++;
            } else {
                echo sprintf(
                    "   ❌ %-25s  FAILED  %6.2fms  (HTTP %d)\n",
                    $page,
                    $requestTime,
                    $statusCode
                );
                $failedPages++;
            }

            // Small delay to avoid hammering the server
            usleep(REQUEST_DELAY * 1000);

        } catch (\Exception $e) {
            echo "   ❌ {$page} - Error: {$e->getMessage()}\n";
            $failedPages++;
        }
    }

    $endTime = microtime(true);
    $duration = round(($endTime - $startTime) * 1000, 2);
    $totalSizeKB = round($totalSize / 1024, 2);

    echo "\n" . str_repeat("=", 70) . "\n";
    echo "✅ PUBLIC CACHE WARMUP COMPLETED\n";
    echo str_repeat("=", 70) . "\n\n";

    echo "SUMMARY:\n";
    echo "  • Pages warmed: {$warmedPages}\n";
    echo "  • Pages failed: {$failedPages}\n";
    echo "  • Total cached: {$totalSizeKB} KB\n";
    echo "  • Execution time: {$duration}ms\n\n";

    // Log completion
    Logger::info('Public pages cache warmup completed', [
        'script' => SCRIPT_NAME,
        'pages_warmed' => $warmedPages,
        'pages_failed' => $failedPages,
        'total_size_kb' => $totalSizeKB,
        'duration_ms' => $duration,
    ]);

    exit(0);

} catch (\Exception $e) {
    $error = "Unexpected error: " . $e->getMessage();
    echo "\n❌ ERROR: {$error}\n";

    Logger::error('Public cache warmup failed', [
        'script' => SCRIPT_NAME,
        'error' => $error,
    ]);

    exit(1);
}
