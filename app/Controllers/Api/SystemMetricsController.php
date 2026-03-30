<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;
use Need2Talk\Services\SystemMonitorService;

/**
 * ENTERPRISE GALAXY: System Metrics API Controller
 *
 * Provides RESTful API endpoints for real-time system metrics
 * Used by admin dashboard for AJAX updates without page reload
 *
 * Endpoints:
 * - GET /api/metrics/dashboard - Complete dashboard stats
 * - GET /api/metrics/realtime - Real-time metrics only
 * - GET /api/metrics/historical - Historical data with timeframe
 * - GET /api/metrics/alerts - Active alerts
 * - GET /api/metrics/health - System health check
 * - GET /api/metrics/export - Export metrics (CSV/JSON/PDF)
 */
class SystemMetricsController extends BaseController
{
    private SystemMonitorService $monitor;

    public function __construct()
    {
        parent::__construct();
        $this->monitor = new SystemMonitorService();
    }

    /**
     * ENTERPRISE: Complete dashboard stats (30s cache)
     * GET /api/metrics/dashboard
     */
    public function dashboard(): void
    {
        $this->jsonResponse([
            'success' => true,
            'data' => $this->monitor->getDashboardStats(),
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * ENTERPRISE: Real-time metrics (no cache)
     * GET /api/metrics/realtime
     */
    public function realtime(): void
    {
        $this->jsonResponse([
            'success' => true,
            'data' => $this->monitor->getRealtimeMetrics(),
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * ENTERPRISE: Historical performance data
     * GET /api/metrics/historical?hours=24
     */
    public function historical(): void
    {
        $hours = (int) ($_GET['hours'] ?? 24);
        $hours = min(max($hours, 1), 720); // Limit 1-720 hours (30 days)

        try {
            $data = $this->monitor->getHistoricalData($hours);

            $this->jsonResponse([
                'success' => true,
                'data' => $data,
                'timeframe_hours' => $hours,
                'timestamp' => microtime(true),
            ]);
        } catch (\Exception $e) {
            Logger::error('DEFAULT: Failed to fetch historical metrics', [
                'error' => $e->getMessage(),
                'hours' => $hours,
            ]);

            $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to fetch historical data',
            ], 500);
        }
    }

    /**
     * ENTERPRISE: Active alerts only
     * GET /api/metrics/alerts
     */
    public function alerts(): void
    {
        try {
            $dashboardStats = $this->monitor->getDashboardStats();
            $alerts = $dashboardStats['alerts'] ?? [];

            $this->jsonResponse([
                'success' => true,
                'alerts' => $alerts,
                'count' => count($alerts),
                'has_critical' => !empty(array_filter($alerts, fn ($a) => $a['level'] === 'critical')),
                'timestamp' => microtime(true),
            ]);
        } catch (\Exception $e) {
            Logger::error('DEFAULT: Failed to fetch alerts', [
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to fetch alerts',
            ], 500);
        }
    }

    /**
     * ENTERPRISE: System health check
     * GET /api/metrics/health
     */
    public function health(): void
    {
        try {
            $dashboardStats = $this->monitor->getDashboardStats();

            // Calculate overall health score (0-100)
            $healthScore = $this->calculateHealthScore($dashboardStats);

            // Health status
            $status = $healthScore >= 80 ? 'healthy' : ($healthScore >= 50 ? 'degraded' : 'critical');

            $this->jsonResponse([
                'success' => true,
                'health' => [
                    'status' => $status,
                    'score' => $healthScore,
                    'components' => [
                        'database' => $dashboardStats['database']['status'] ?? 'unknown',
                        'cache' => $dashboardStats['cache']['health']['status'] ?? 'unknown',
                        'security' => $dashboardStats['security']['threat_level'] ?? 'unknown',
                    ],
                    'alerts_count' => count($dashboardStats['alerts'] ?? []),
                ],
                'timestamp' => microtime(true),
            ]);
        } catch (\Exception $e) {
            Logger::error('DEFAULT: Failed to fetch health check', [
                'error' => $e->getMessage(),
            ]);

            $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to perform health check',
            ], 500);
        }
    }

    /**
     * ENTERPRISE: Export metrics in multiple formats
     * GET /api/metrics/export?format=json|csv|pdf&timeframe=24h
     */
    public function export(): void
    {
        $format = $_GET['format'] ?? 'json';
        $timeframe = $_GET['timeframe'] ?? '24h';

        try {
            $dashboardStats = $this->monitor->getDashboardStats();

            // Parse timeframe
            $hours = $this->parseTimeframe($timeframe);
            $historicalData = $this->monitor->getHistoricalData($hours);

            $exportData = [
                'generated_at' => date('Y-m-d H:i:s'),
                'timeframe' => $timeframe,
                'dashboard_stats' => $dashboardStats,
                'historical_data' => $historicalData,
            ];

            switch ($format) {
                case 'csv':
                    $this->exportAsCsv($exportData);
                    break;

                case 'txt':
                case 'pdf':
                    $this->exportAsPdf($exportData);
                    break;

                case 'json':
                default:
                    $this->exportAsJson($exportData);
                    break;
            }
        } catch (\Exception $e) {
            Logger::error('DEFAULT: Failed to export metrics', [
                'error' => $e->getMessage(),
                'format' => $format,
                'timeframe' => $timeframe,
            ]);

            $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to export metrics',
            ], 500);
        }
    }

    /**
     * Calculate overall system health score (0-100)
     */
    private function calculateHealthScore(array $stats): int
    {
        $score = 100;

        // Database health (-30 if error)
        if (($stats['database']['status'] ?? 'error') !== 'healthy') {
            $score -= 30;
        }

        // Connection pool utilization penalty
        $poolUtil = $stats['database']['connection_pool']['utilization_percent'] ?? 0;
        if ($poolUtil > 90) {
            $score -= 20;
        } elseif ($poolUtil > 80) {
            $score -= 10;
        }

        // Cache health (-20 if unhealthy)
        if (($stats['cache']['health']['status'] ?? 'unhealthy') !== 'healthy') {
            $score -= 20;
        }

        // Cache hit ratio penalty
        $hitRatio = $stats['cache']['performance']['hit_ratio'] ?? 0;
        if ($hitRatio < 50) {
            $score -= 15;
        } elseif ($hitRatio < 70) {
            $score -= 10;
        }

        // Security threat level penalty
        $threatLevel = $stats['security']['threat_level'] ?? 'low';
        if ($threatLevel === 'high') {
            $score -= 25;
        } elseif ($threatLevel === 'medium') {
            $score -= 10;
        }

        // Error rate penalty
        $errorRate = $stats['performance']['error_rate_percent'] ?? 0;
        if ($errorRate > 5) {
            $score -= 20;
        } elseif ($errorRate > 2) {
            $score -= 10;
        }

        // Response time penalty
        $avgResponseTime = $stats['performance']['avg_response_time_ms'] ?? 0;
        if ($avgResponseTime > 1000) {
            $score -= 15;
        } elseif ($avgResponseTime > 500) {
            $score -= 5;
        }

        return max(0, min(100, $score));
    }

    /**
     * Parse timeframe string to hours
     */
    private function parseTimeframe(string $timeframe): int
    {
        return match ($timeframe) {
            '1h' => 1,
            '6h' => 6,
            '12h' => 12,
            '24h' => 24,
            '7d' => 168,
            '30d' => 720,
            default => 24,
        };
    }

    /**
     * Export as JSON
     */
    private function exportAsJson(array $data): void
    {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="metrics_export_' . date('Y-m-d_H-i-s') . '.json"');

        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Export as CSV
     */
    private function exportAsCsv(array $data): void
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="metrics_export_' . date('Y-m-d_H-i-s') . '.csv"');

        $output = fopen('php://output', 'w');

        // Header
        fputcsv($output, ['Metric', 'Value', 'Category']);

        // Dashboard stats flattened
        $this->flattenArrayToCsv($data['dashboard_stats'], $output);

        // Historical data
        fputcsv($output, ['']); // Empty row
        fputcsv($output, ['Historical Data']);
        fputcsv($output, ['Time Slot', 'Avg Response Time (ms)', 'Requests', 'Errors']);

        foreach ($data['historical_data'] as $row) {
            fputcsv($output, [
                $row['time_slot'] ?? '',
                $row['avg_response_time'] ?? 0,
                $row['requests'] ?? 0,
                $row['errors'] ?? 0,
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Export as PDF (basic text-based PDF)
     */
    private function exportAsPdf(array $data): void
    {
        // For now, export as formatted text (full PDF requires TCPDF/FPDF library)
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="metrics_export_' . date('Y-m-d_H-i-s') . '.txt"');

        echo "=== ENTERPRISE SYSTEM METRICS REPORT ===\n";
        echo "Generated: " . $data['generated_at'] . "\n";
        echo "Timeframe: " . $data['timeframe'] . "\n\n";

        echo "=== DASHBOARD STATISTICS ===\n";
        echo $this->formatArrayAsText($data['dashboard_stats']);

        echo "\n=== HISTORICAL DATA ===\n";
        foreach ($data['historical_data'] as $row) {
            echo sprintf(
                "%s | Response: %.2fms | Requests: %d | Errors: %d\n",
                $row['time_slot'] ?? '',
                $row['avg_response_time'] ?? 0,
                $row['requests'] ?? 0,
                $row['errors'] ?? 0
            );
        }

        exit;
    }

    /**
     * Flatten array for CSV export
     */
    private function flattenArrayToCsv(array $array, $output, string $prefix = ''): void
    {
        foreach ($array as $key => $value) {
            $currentKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $this->flattenArrayToCsv($value, $output, $currentKey);
            } else {
                fputcsv($output, [$currentKey, $value, $prefix ?: 'general']);
            }
        }
    }

    /**
     * Format array as readable text
     */
    private function formatArrayAsText(array $array, int $depth = 0): string
    {
        $output = '';
        $indent = str_repeat('  ', $depth);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $output .= "{$indent}{$key}:\n";
                $output .= $this->formatArrayAsText($value, $depth + 1);
            } else {
                $output .= "{$indent}{$key}: {$value}\n";
            }
        }

        return $output;
    }

    /**
     * Send JSON response
     * ENTERPRISE: Match BaseController signature exactly (PSR-12 compliance)
     */
    protected function jsonResponse(array $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        // Apply custom headers if provided
        foreach ($headers as $key => $value) {
            header("{$key}: {$value}");
        }

        echo json_encode($data);
        exit;
    }
}
