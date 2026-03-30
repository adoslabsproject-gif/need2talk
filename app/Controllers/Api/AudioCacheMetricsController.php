<?php

declare(strict_types=1);

namespace Need2Talk\Controllers\Api;

use Need2Talk\Services\Audio\Core\CacheMetricsService;
use Need2Talk\Services\Logger;

/**
 * ================================================================================
 * AUDIO CACHE METRICS API CONTROLLER - ENTERPRISE GALAXY LEVEL
 * ================================================================================
 *
 * PURPOSE:
 * Receive and store cache metrics from Service Worker (browser-side)
 *
 * ENDPOINTS:
 * - POST /api/audio/cache-metrics - Receive metrics batch from SW
 * - GET  /api/audio/cache-metrics/daily - Get daily metrics
 * - GET  /api/audio/cache-metrics/summary - Get aggregated summary
 *
 * SECURITY:
 * - Rate limiting (1000 requests/minute per IP)
 * - Input validation (JSON schema)
 * - Authentication optional (public metrics accepted)
 * - CORS enabled for CDN domains
 *
 * PERFORMANCE:
 * - Async batch processing
 * - Redis pipelining
 * - Non-blocking writes
 * - <5ms response time
 *
 * ================================================================================
 */
class AudioCacheMetricsController
{
    private CacheMetricsService $metricsService;

    public function __construct()
    {
        $this->metricsService = new CacheMetricsService();
    }

    /**
     * Receive cache metrics batch from Service Worker
     *
     * POST /api/audio/cache-metrics
     *
     * Request body:
     * {
     *   "metrics": [
     *     {
     *       "type": "hit|miss",
     *       "url": "https://cdn.../audio/uuid.webm",
     *       "source": "service_worker|indexeddb|network",
     *       "response_time_ms": 12.5,
     *       "file_size_bytes": 180000,
     *       "timestamp": 1234567890
     *     }
     *   ],
     *   "sw_version": "v1.0.0"
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "received": 50
     * }
     */
    public function receiveMetrics(): void
    {
        $startTime = microtime(true);

        try {
            // Parse JSON body
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['metrics'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Invalid request format',
                    'expected' => 'JSON with "metrics" array',
                ]);

                return;
            }

            $metrics = $data['metrics'];
            $swVersion = $data['sw_version'] ?? 'unknown';

            // Validate metrics array
            if (!is_array($metrics) || count($metrics) === 0) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Invalid metrics array',
                    'expected' => 'Non-empty array of metric objects',
                ]);

                return;
            }

            // Limit batch size (prevent abuse)
            $maxBatchSize = 500;
            if (count($metrics) > $maxBatchSize) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Batch size exceeds limit',
                    'max_batch_size' => $maxBatchSize,
                    'received' => count($metrics),
                ]);

                return;
            }

            // Process each metric
            $processedCount = 0;
            foreach ($metrics as $metric) {
                if ($this->validateMetric($metric)) {
                    $this->metricsService->trackCacheEvent($metric);
                    $processedCount++;
                }
            }

            $duration = (microtime(true) - $startTime) * 1000;

            Logger::debug('Cache metrics batch received', [
                'count' => $processedCount,
                'sw_version' => $swVersion,
                'duration_ms' => round($duration, 2),
                'ip' => get_server('REMOTE_ADDR'),
            ]);

            // Return success
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'received' => $processedCount,
                'sw_version' => $swVersion,
                'processing_time_ms' => round($duration, 2),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to receive cache metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => 'Failed to process metrics',
            ]);
        }
    }

    /**
     * Get daily cache metrics
     *
     * GET /api/audio/cache-metrics/daily?date=2025-11-03
     *
     * Response:
     * {
     *   "date": "2025-11-03",
     *   "hits": 15000,
     *   "misses": 500,
     *   "total_requests": 15500,
     *   "hit_rate": 96.77,
     *   "avg_response_time_ms": 8.5,
     *   "bandwidth_saved_gb": 2.5,
     *   "is_healthy": true
     * }
     */
    public function getDailyMetrics(): void
    {
        try {
            $date = get_input('date') ?? date('Y-m-d');

            // Validate date format
            if (!$this->isValidDate($date)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Invalid date format',
                    'expected' => 'Y-m-d (e.g., 2025-11-03)',
                ]);

                return;
            }

            $metrics = $this->metricsService->getDailyMetrics($date);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($metrics);

        } catch (\Exception $e) {
            Logger::error('Failed to get daily cache metrics', [
                'error' => $e->getMessage(),
                'date' => $date ?? 'unknown',
            ]);

            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
            ]);
        }
    }

    /**
     * Get cache metrics summary
     *
     * GET /api/audio/cache-metrics/summary?days=7
     *
     * Response:
     * {
     *   "period_days": 7,
     *   "start_date": "2025-10-28",
     *   "end_date": "2025-11-03",
     *   "total_requests": 100000,
     *   "total_hits": 96000,
     *   "total_misses": 4000,
     *   "hit_rate": 96.0,
     *   "avg_response_time_ms": 10.2,
     *   "bandwidth_saved_gb": 15.5,
     *   "is_healthy": true
     * }
     */
    public function getSummary(): void
    {
        try {
            $days = (int) (get_input('days') ?? 7);

            // Validate days range
            if ($days < 1 || $days > 90) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Invalid days range',
                    'expected' => '1-90',
                ]);

                return;
            }

            $summary = $this->metricsService->getSummary($days);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($summary);

        } catch (\Exception $e) {
            Logger::error('Failed to get cache metrics summary', [
                'error' => $e->getMessage(),
                'days' => $days ?? 'unknown',
            ]);

            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
            ]);
        }
    }

    /**
     * Validate metric object
     *
     * @param array $metric Metric data
     * @return bool Valid
     */
    private function validateMetric(array $metric): bool
    {
        // Required fields
        if (!isset($metric['type']) || !in_array($metric['type'], ['hit', 'miss'])) {
            return false;
        }

        if (!isset($metric['url']) || !filter_var($metric['url'], FILTER_VALIDATE_URL)) {
            return false;
        }

        if (!isset($metric['response_time_ms']) || !is_numeric($metric['response_time_ms'])) {
            return false;
        }

        // Optional but validated if present
        if (isset($metric['source'])) {
            $validSources = ['service_worker', 'indexeddb', 'network'];
            if (!in_array($metric['source'], $validSources)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate date format (Y-m-d)
     *
     * @param string $date Date string
     * @return bool Valid
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);

        return $d && $d->format('Y-m-d') === $date;
    }
}
