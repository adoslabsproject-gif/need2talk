<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * 🚀 ENTERPRISE GALAXY: Admin Performance Metrics Controller
 *
 * Real-time frontend performance monitoring from enterprise_performance_metrics
 * Provides comprehensive analytics for page load times, server response, and user experience
 *
 * PERFORMANCE OPTIMIZED: Uses fresh PDO connections for real-time data
 *
 * @package Need2Talk\Controllers
 * @version 1.0.0 Galaxy Edition
 */
class AdminPerformanceController extends BaseController
{
    /**
     * Get all data for Performance Metrics admin page
     * Returns data array for AdminController to render
     *
     * ENTERPRISE: Real-time performance metrics monitoring with no caching
     */
    public function getPageData(): array
    {
        // ENTERPRISE: No-cache headers for real-time data
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // Get dashboard metrics
        $dashboardMetrics = $this->getPerformanceDashboard();

        // Return data for rendering
        return [
            'title' => '🚀 Performance Metrics Dashboard',
            'dashboard' => $dashboardMetrics,
        ];
    }

    /**
     * ENTERPRISE GALAXY: Get comprehensive performance metrics dashboard
     * HYBRID MODE: Combines summary table (>1h ago) + real-time data (last hour)
     *
     * PERFORMANCE:
     * - Summary queries: <5ms (pre-aggregated)
     * - Real-time queries: ~20ms (only last hour)
     * - Total: ~25ms vs 50-100ms (pure raw queries)
     */
    private function getPerformanceDashboard(): array
    {
        try {
            $pdo = $this->getFreshPDO();

            // 📊 KPI Cards (Last 24h) - HYBRID
            $kpis = $this->getKPIsHybrid($pdo);

            // 🚀 ENTERPRISE GALAXY: Calculate Performance Breakdown (Server/Network/Client)
            $performanceBreakdown = $this->calculatePerformanceBreakdown($kpis);

            // 🔥 Slowest Pages (Last 24h, >100ms) - HYBRID MODE
            // Uses summary (>1h ago) + realtime (last 1h) for instant refresh
            $slowPages = $this->getSlowPagesHybrid($pdo);

            // 🏆 MOST Fast Pages (Top 10 best times) - ALL COLUMNS
            $stmt = $pdo->query("
                SELECT
                    page_url,
                    page_load_time,
                    server_response_time,
                    dom_ready_time,
                    first_byte_time,
                    dns_lookup_time,
                    connect_time,
                    user_id,
                    created_at
                FROM enterprise_performance_metrics
                WHERE created_at > NOW() - INTERVAL '24 hours'
                ORDER BY page_load_time ASC
                LIMIT 10
            ");
            $mostFastPages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 🚀 Recent Fast Requests (Last 50 requests <50ms) - ALL COLUMNS
            $stmt = $pdo->query("
                SELECT
                    page_url,
                    page_load_time,
                    server_response_time,
                    dom_ready_time,
                    first_byte_time,
                    dns_lookup_time,
                    connect_time,
                    user_agent,
                    user_id,
                    created_at
                FROM enterprise_performance_metrics
                WHERE page_load_time < 50
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $recentFast = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 📊 Hourly Performance Trend (Last 24h)
            $stmt = $pdo->query("
                SELECT
                    TO_CHAR(created_at, 'YYYY-MM-DD HH24:00') as hour,
                    COUNT(*) as requests,
                    ROUND(AVG(page_load_time)) as avg_load,
                    ROUND(AVG(server_response_time)) as avg_server,
                    ROUND(AVG(dom_ready_time)) as avg_dom,
                    MAX(page_load_time) as max_load
                FROM enterprise_performance_metrics
                WHERE created_at > NOW() - INTERVAL '24 hours'
                GROUP BY hour
                ORDER BY hour ASC
            ");
            $hourlyTrend = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 🎯 All Pages Performance Summary - HYBRID MODE
            $allPages = $this->getAllPagesHybrid($pdo);

            // 🕐 Recent Slow Requests (Last 50 requests >200ms)
            $stmt = $pdo->query("
                SELECT
                    page_url,
                    page_load_time,
                    server_response_time,
                    dom_ready_time,
                    first_byte_time,
                    dns_lookup_time,
                    connect_time,
                    user_agent,
                    user_id,
                    created_at
                FROM enterprise_performance_metrics
                WHERE page_load_time > 200
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $recentSlow = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 📊 Database Stats (ENTERPRISE GALAXY: PostgreSQL CAST instead of MySQL DATE())
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as total_metrics,
                    MIN(created_at) as oldest_metric,
                    MAX(created_at) as newest_metric,
                    COUNT(DISTINCT page_url) as unique_pages,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT CAST(created_at AS DATE)) as days_tracked
                FROM enterprise_performance_metrics
            ");
            $dbStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // 📊 Performance Distribution (Fast/Medium/Slow)
            $stmt = $pdo->query("
                SELECT
                    SUM(CASE WHEN page_load_time < 50 THEN 1 ELSE 0 END) as fast_count,
                    SUM(CASE WHEN page_load_time BETWEEN 50 AND 200 THEN 1 ELSE 0 END) as medium_count,
                    SUM(CASE WHEN page_load_time > 200 THEN 1 ELSE 0 END) as slow_count,
                    ROUND(SUM(CASE WHEN page_load_time < 50 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as fast_pct,
                    ROUND(SUM(CASE WHEN page_load_time BETWEEN 50 AND 200 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as medium_pct,
                    ROUND(SUM(CASE WHEN page_load_time > 200 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as slow_pct
                FROM enterprise_performance_metrics
                WHERE created_at > NOW() - INTERVAL '24 hours'
            ");
            $distribution = $stmt->fetch(\PDO::FETCH_ASSOC);

            return [
                'kpis' => $kpis,
                'performance_breakdown' => $performanceBreakdown,  // 🚀 ENTERPRISE: Server/Network/Client breakdown
                'slow_pages' => $slowPages,
                'most_fast_pages' => $mostFastPages,
                'recent_fast' => $recentFast,
                'hourly_trend' => $hourlyTrend,
                'all_pages' => $allPages,
                'recent_slow' => $recentSlow,
                'db_stats' => $dbStats,
                'distribution' => $distribution,
                'timestamp' => date('c'),
            ];

        } catch (\Exception $e) {
            Logger::performance('error', 'Failed to get performance metrics dashboard', 0.0, [
                'error' => $e->getMessage(),
            ]);

            return [
                'kpis' => [],
                'slow_pages' => [],
                'most_fast_pages' => [],
                'recent_fast' => [],
                'hourly_trend' => [],
                'all_pages' => [],
                'recent_slow' => [],
                'db_stats' => [],
                'distribution' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * API Endpoint: Get detailed metrics with pagination
     * AJAX endpoint for real-time data refresh
     */
    public function getDetailedMetrics(): void
    {
        $this->disableHttpCache();

        try {
            $limit = (int) ($_GET['limit'] ?? 100);
            $page = (int) ($_GET['page'] ?? 1);
            $limit = in_array($limit, [50, 100, 200, 500]) ? $limit : 100;
            $page = max(1, $page);
            $offset = ($page - 1) * $limit;

            $pdo = $this->getFreshPDO();

            // Get total count
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM enterprise_performance_metrics');
            $total = (int) $stmt->fetchColumn();

            // Get metrics
            $stmt = $pdo->prepare("
                SELECT
                    id, page_url, page_load_time, dom_ready_time, first_byte_time,
                    dns_lookup_time, connect_time, server_response_time,
                    user_agent, user_id, created_at
                FROM enterprise_performance_metrics
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $metrics = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'metrics' => $metrics,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::performance('error', 'Failed to get detailed metrics', 0.0, ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Could not retrieve metrics'], 500);
        }
    }

    /**
     * API Endpoint: Get page-specific metrics
     */
    public function getPageMetrics(): void
    {
        $this->disableHttpCache();

        try {
            $pageUrl = $_GET['page_url'] ?? null;
            $days = (int) ($_GET['days'] ?? 7);
            $days = min(365, max(1, $days));

            if (!$pageUrl) {
                $this->json(['success' => false, 'message' => 'page_url parameter required'], 400);

                return;
            }

            $pdo = $this->getFreshPDO();

            $stmt = $pdo->prepare("
                SELECT
                    page_load_time,
                    dom_ready_time,
                    first_byte_time,
                    server_response_time,
                    user_agent,
                    user_id,
                    created_at
                FROM enterprise_performance_metrics
                WHERE page_url = ? AND created_at >= NOW() - INTERVAL '1 day' * ?
                ORDER BY created_at DESC
                LIMIT 1000
            ");
            $stmt->execute([$pageUrl, $days]);
            $metrics = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Calculate stats
            $stats = [
                'count' => count($metrics),
                'avg_load' => 0,
                'avg_server' => 0,
                'max_load' => 0,
                'min_load' => PHP_INT_MAX,
            ];

            if (count($metrics) > 0) {
                $totalLoad = 0;
                $totalServer = 0;

                foreach ($metrics as $m) {
                    $totalLoad += $m['page_load_time'];
                    $totalServer += $m['server_response_time'];
                    $stats['max_load'] = max($stats['max_load'], $m['page_load_time']);
                    $stats['min_load'] = min($stats['min_load'], $m['page_load_time']);
                }

                $stats['avg_load'] = round($totalLoad / count($metrics));
                $stats['avg_server'] = round($totalServer / count($metrics));
            }

            $this->json([
                'success' => true,
                'page_url' => $pageUrl,
                'metrics' => $metrics,
                'stats' => $stats,
                'days' => $days,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::performance('error', 'Failed to get page metrics', 0.0, ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Could not retrieve metrics'], 500);
        }
    }

    /**
     * API Endpoint: Export performance metrics to CSV
     */
    public function exportMetrics(): void
    {
        try {
            $days = (int) ($_GET['days'] ?? 30);
            $days = min(365, max(1, $days));

            $pdo = $this->getFreshPDO();

            $filename = "performance_metrics_" . date('Y-m-d_His') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');

            // CSV Header
            fputcsv($output, [
                'ID', 'Page URL', 'Page Load Time (ms)', 'DOM Ready Time (ms)',
                'First Byte Time (ms)', 'DNS Lookup Time (ms)', 'Connect Time (ms)',
                'Server Response Time (ms)', 'User Agent', 'User ID', 'Created At',
            ]);

            // Get data
            $stmt = $pdo->prepare("
                SELECT
                    id, page_url, page_load_time, dom_ready_time, first_byte_time,
                    dns_lookup_time, connect_time, server_response_time,
                    user_agent, user_id, created_at
                FROM enterprise_performance_metrics
                WHERE created_at >= NOW() - INTERVAL '1 day' * ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$days]);

            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                fputcsv($output, $row);
            }

            fclose($output);
            exit;

        } catch (\Exception $e) {
            Logger::performance('error', 'Failed to export performance metrics', 0.0, [
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            echo "Export failed: " . htmlspecialchars($e->getMessage());
            exit;
        }
    }

    /**
     * API Endpoint: Clear old metrics (cleanup)
     */
    public function clearOldMetrics(): void
    {
        $this->disableHttpCache();

        try {
            $days = (int) ($_POST['days'] ?? 30);
            $days = max(7, $days); // Minimum 7 days retention

            $pdo = $this->getFreshPDO();

            $stmt = $pdo->prepare("
                DELETE FROM enterprise_performance_metrics
                WHERE created_at < NOW() - INTERVAL '1 day' * ?
            ");
            $stmt->execute([$days]);

            $deletedCount = $stmt->rowCount();

            Logger::performance('info', 'Performance metrics cleaned up', 0.0, [
                'deleted_count' => $deletedCount,
                'retention_days' => $days,
            ]);

            $this->json([
                'success' => true,
                'deleted_count' => $deletedCount,
                'retention_days' => $days,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::performance('error', 'Failed to clear old metrics', 0.0, ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Could not clear metrics'], 500);
        }
    }

    /**
     * HYBRID: Get KPIs combining summary + real-time data
     *
     * @param \PDO $pdo
     * @return array
     */
    private function getKPIsHybrid(\PDO $pdo): array
    {
        // Get from summary (>1 hour ago, up to 24 hours)
        $oneHourAgo = date('Y-m-d H:00:00', strtotime('-1 hour'));
        $twentyFourHoursAgo = date('Y-m-d H:00:00', strtotime('-24 hours'));

        $summaryData = $pdo->query("
            SELECT
                SUM(total_requests) as total_requests,
                ROUND(AVG(avg_page_load_time)) as avg_page_load,
                ROUND(AVG(avg_server_response_time)) as avg_server_response,
                ROUND(AVG(avg_dom_ready_time)) as avg_dom_ready,
                ROUND(AVG(avg_first_byte_time)) as avg_first_byte,
                MAX(max_page_load_time) as max_page_load,
                MIN(min_page_load_time) as min_page_load
            FROM performance_metrics_summary
            WHERE time_bucket >= '{$twentyFourHoursAgo}'
              AND time_bucket < '{$oneHourAgo}'
        ")->fetch(\PDO::FETCH_ASSOC);

        // Get from raw metrics (last hour)
        $realtimeData = $pdo->query("
            SELECT
                COUNT(*) as total_requests,
                ROUND(AVG(page_load_time)) as avg_page_load,
                ROUND(AVG(server_response_time)) as avg_server_response,
                ROUND(AVG(dom_ready_time)) as avg_dom_ready,
                ROUND(AVG(first_byte_time)) as avg_first_byte,
                MAX(page_load_time) as max_page_load,
                MIN(page_load_time) as min_page_load,
                COUNT(DISTINCT page_url) as unique_pages,
                COUNT(DISTINCT user_id) as unique_users
            FROM enterprise_performance_metrics
            WHERE created_at >= '{$oneHourAgo}'
        ")->fetch(\PDO::FETCH_ASSOC);

        // Combine data
        $totalRequests = ($summaryData['total_requests'] ?? 0) + ($realtimeData['total_requests'] ?? 0);

        if ($totalRequests === 0) {
            return [
                'total_requests' => 0,
                'avg_page_load' => 0,
                'avg_server_response' => 0,
                'avg_dom_ready' => 0,
                'avg_first_byte' => 0,
                'max_page_load' => 0,
                'min_page_load' => 0,
                'unique_pages' => 0,
                'unique_users' => 0,
            ];
        }

        // Weighted average
        $summaryRequests = $summaryData['total_requests'] ?? 0;
        $realtimeRequests = $realtimeData['total_requests'] ?? 0;

        return [
            'total_requests' => $totalRequests,
            'avg_page_load' => round((
                ($summaryData['avg_page_load'] ?? 0) * $summaryRequests +
                ($realtimeData['avg_page_load'] ?? 0) * $realtimeRequests
            ) / $totalRequests),
            'avg_server_response' => round((
                ($summaryData['avg_server_response'] ?? 0) * $summaryRequests +
                ($realtimeData['avg_server_response'] ?? 0) * $realtimeRequests
            ) / $totalRequests),
            'avg_dom_ready' => round((
                ($summaryData['avg_dom_ready'] ?? 0) * $summaryRequests +
                ($realtimeData['avg_dom_ready'] ?? 0) * $realtimeRequests
            ) / $totalRequests),
            'avg_first_byte' => round((
                ($summaryData['avg_first_byte'] ?? 0) * $summaryRequests +
                ($realtimeData['avg_first_byte'] ?? 0) * $realtimeRequests
            ) / $totalRequests),
            'max_page_load' => max(
                $summaryData['max_page_load'] ?? 0,
                $realtimeData['max_page_load'] ?? 0
            ),
            'min_page_load' => min(
                $summaryData['min_page_load'] ?? PHP_INT_MAX,
                $realtimeData['min_page_load'] ?? PHP_INT_MAX
            ),
            'unique_pages' => $realtimeData['unique_pages'] ?? 0, // Only from realtime
            'unique_users' => $realtimeData['unique_users'] ?? 0,  // Only from realtime
        ];
    }

    /**
     * 🚀 ENTERPRISE GALAXY: Calculate Performance Breakdown (Server / Network / Client)
     *
     * METRICS BREAKDOWN EXPLANATION:
     * ================================
     * 1. SERVER PERFORMANCE (Your Control) = server_response_time
     *    - Pure PHP backend execution time
     *    - Target: <100ms (EXCELLENT), <200ms (GOOD), >200ms (NEEDS OPTIMIZATION)
     *
     * 2. NETWORK LATENCY (User Connection) = first_byte_time - server_response_time
     *    - DNS + TCP + SSL + Network RTT
     *    - Affected by: User's internet speed, ISP, location, device
     *    - Target: <200ms (GOOD), <500ms (ACCEPTABLE), >500ms (USER'S SLOW CONNECTION)
     *
     * 3. CLIENT RENDERING (User Device) = page_load_time - first_byte_time
     *    - HTML parsing + CSS + JS execution + Resources download
     *    - Affected by: User's device speed, browser, resources size
     *    - Target: <500ms (EXCELLENT), <1500ms (GOOD), >1500ms (USER'S SLOW DEVICE)
     *
     * ENTERPRISE RATIONALE:
     * - Separates controllable metrics (server) from uncontrollable (user-side)
     * - Prevents misleading "slow site" perception when issue is user's connection/device
     * - Aligns with Google Lighthouse/PageSpeed methodology
     * - Provides actionable insights: optimize server vs optimize assets
     *
     * @param array $kpis Raw KPIs from getKPIsHybrid()
     * @return array Performance breakdown with color-coded status
     */
    private function calculatePerformanceBreakdown(array $kpis): array
    {
        // Extract raw metrics (all in milliseconds)
        $serverResponseTime = (float) ($kpis['avg_server_response'] ?? 0);
        $firstByteTime = (float) ($kpis['avg_first_byte'] ?? 0);
        $pageLoadTime = (float) ($kpis['avg_page_load'] ?? 0);

        // Calculate breakdown components
        $networkLatency = max(0, $firstByteTime - $serverResponseTime);
        $clientRendering = max(0, $pageLoadTime - $firstByteTime);

        // Color-coded status evaluation (enterprise thresholds)
        $serverStatus = $this->evaluateMetricStatus($serverResponseTime, [
            'excellent' => 100,  // <100ms
            'good' => 200,       // <200ms
            'warning' => 300,    // <300ms
            // >=300ms = critical
        ]);

        $networkStatus = $this->evaluateMetricStatus($networkLatency, [
            'excellent' => 200,  // <200ms
            'good' => 500,       // <500ms
            'warning' => 1000,   // <1s
            // >=1s = critical (user's poor connection)
        ]);

        $clientStatus = $this->evaluateMetricStatus($clientRendering, [
            'excellent' => 500,  // <500ms
            'good' => 1500,      // <1.5s
            'warning' => 3000,   // <3s
            // >=3s = critical (user's slow device)
        ]);

        return [
            'server' => [
                'value' => round($serverResponseTime, 2),
                'status' => $serverStatus,
                'controllable' => true,
                'label' => 'Server Performance',
                'description' => 'PHP backend execution time (your control)',
                'icon' => '🚀',
            ],
            'network' => [
                'value' => round($networkLatency, 2),
                'status' => $networkStatus,
                'controllable' => false,
                'label' => 'Network Latency',
                'description' => 'User\'s internet connection speed (DNS + TCP + SSL)',
                'icon' => '🌐',
            ],
            'client' => [
                'value' => round($clientRendering, 2),
                'status' => $clientStatus,
                'controllable' => false,
                'label' => 'Client Rendering',
                'description' => 'User\'s browser + device rendering speed',
                'icon' => '💻',
            ],
            'total' => [
                'value' => round($pageLoadTime, 2),
                'status' => $this->evaluateMetricStatus($pageLoadTime, [
                    'excellent' => 1000,  // <1s
                    'good' => 3000,       // <3s
                    'warning' => 5000,    // <5s
                ]),
                'label' => 'Total Page Load',
                'description' => 'Complete user-perceived load time',
                'icon' => '⚡',
            ],
        ];
    }

    /**
     * 🎨 ENTERPRISE: Evaluate metric status with color coding
     *
     * @param float $value Metric value in milliseconds
     * @param array $thresholds Thresholds: ['excellent' => X, 'good' => Y, 'warning' => Z]
     * @return array Status data with color, badge, CSS class
     */
    private function evaluateMetricStatus(float $value, array $thresholds): array
    {
        if ($value < $thresholds['excellent']) {
            return [
                'level' => 'excellent',
                'color' => '#10b981',  // Green-500 (Tailwind)
                'bg_color' => '#d1fae5',  // Green-100
                'badge' => 'EXCELLENT',
                'css_class' => 'text-green-700 bg-green-100',
                'icon' => '✅',
            ];
        }

        if ($value < $thresholds['good']) {
            return [
                'level' => 'good',
                'color' => '#22c55e',  // Green-500
                'bg_color' => '#dcfce7',  // Green-50
                'badge' => 'GOOD',
                'css_class' => 'text-green-600 bg-green-50',
                'icon' => '✓',
            ];
        }

        if ($value < $thresholds['warning']) {
            return [
                'level' => 'warning',
                'color' => '#f59e0b',  // Amber-500
                'bg_color' => '#fef3c7',  // Amber-100
                'badge' => 'ACCEPTABLE',
                'css_class' => 'text-amber-700 bg-amber-100',
                'icon' => '⚠️',
            ];
        }

        return [
            'level' => 'critical',
            'color' => '#ef4444',  // Red-500
            'bg_color' => '#fee2e2',  // Red-100
            'badge' => 'NEEDS ATTENTION',
            'css_class' => 'text-red-700 bg-red-100',
            'icon' => '❌',
        ];
    }

    /**
     * HYBRID: Get all pages performance combining summary + real-time data
     * PERFORMANCE: ~5ms (summary) + ~10ms (realtime) = ~15ms vs ~70ms (pure raw)
     *
     * @param \PDO $pdo
     * @return array All pages sorted by requests DESC
     */
    private function getAllPagesHybrid(\PDO $pdo): array
    {
        $oneHourAgo = date('Y-m-d H:00:00', strtotime('-1 hour'));
        $twentyFourHoursAgo = date('Y-m-d H:00:00', strtotime('-24 hours'));

        // Get from summary (>1 hour ago, up to 24 hours)
        $summaryPages = $pdo->query("
            SELECT
                page_url,
                SUM(total_requests) as requests,
                ROUND(AVG(avg_page_load_time)) as avg_load,
                ROUND(AVG(avg_server_response_time)) as avg_server,
                ROUND(AVG(avg_dom_ready_time)) as avg_dom,
                ROUND(AVG(avg_first_byte_time)) as avg_first_byte,
                MAX(max_page_load_time) as max_load,
                MIN(min_page_load_time) as min_load
            FROM performance_metrics_summary
            WHERE time_bucket >= '{$twentyFourHoursAgo}'
              AND time_bucket < '{$oneHourAgo}'
            GROUP BY page_url
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // Get from raw metrics (last hour)
        $realtimePages = $pdo->query("
            SELECT
                page_url,
                COUNT(*) as requests,
                ROUND(AVG(page_load_time)) as avg_load,
                ROUND(AVG(server_response_time)) as avg_server,
                ROUND(AVG(dom_ready_time)) as avg_dom,
                ROUND(AVG(first_byte_time)) as avg_first_byte,
                MAX(page_load_time) as max_load,
                MIN(page_load_time) as min_load
            FROM enterprise_performance_metrics
            WHERE created_at >= '{$oneHourAgo}'
            GROUP BY page_url
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // Merge data by page_url
        $mergedPages = [];

        foreach ($summaryPages as $page) {
            $url = $page['page_url'];
            $mergedPages[$url] = $page;
        }

        foreach ($realtimePages as $page) {
            $url = $page['page_url'];

            if (isset($mergedPages[$url])) {
                // Combine: weighted average
                $summaryRequests = $mergedPages[$url]['requests'];
                $realtimeRequests = $page['requests'];
                $totalRequests = $summaryRequests + $realtimeRequests;

                $mergedPages[$url] = [
                    'page_url' => $url,
                    'requests' => $totalRequests,
                    'avg_load' => round((
                        $mergedPages[$url]['avg_load'] * $summaryRequests +
                        $page['avg_load'] * $realtimeRequests
                    ) / $totalRequests),
                    'avg_server' => round((
                        $mergedPages[$url]['avg_server'] * $summaryRequests +
                        $page['avg_server'] * $realtimeRequests
                    ) / $totalRequests),
                    'avg_dom' => round((
                        $mergedPages[$url]['avg_dom'] * $summaryRequests +
                        $page['avg_dom'] * $realtimeRequests
                    ) / $totalRequests),
                    'avg_first_byte' => round((
                        $mergedPages[$url]['avg_first_byte'] * $summaryRequests +
                        $page['avg_first_byte'] * $realtimeRequests
                    ) / $totalRequests),
                    'max_load' => max($mergedPages[$url]['max_load'], $page['max_load']),
                    'min_load' => min($mergedPages[$url]['min_load'], $page['min_load']),
                ];
            } else {
                // New page (only in last hour)
                $mergedPages[$url] = $page;
            }
        }

        // Convert to array and sort by requests DESC
        $allPages = array_values($mergedPages);

        usort($allPages, function ($a, $b) {
            return $b['requests'] <=> $a['requests'];
        });

        return $allPages;
    }

    /**
     * HYBRID: Get slowest pages combining summary + real-time data
     * PERFORMANCE: ~5ms (summary) + ~10ms (realtime) = ~15ms vs ~50ms (pure raw)
     *
     * @param \PDO $pdo
     * @return array Top 10 slowest pages
     */
    private function getSlowPagesHybrid(\PDO $pdo): array
    {
        $oneHourAgo = date('Y-m-d H:00:00', strtotime('-1 hour'));
        $twentyFourHoursAgo = date('Y-m-d H:00:00', strtotime('-24 hours'));

        // Get from summary (>1 hour ago, up to 24 hours)
        $summaryPages = $pdo->query("
            SELECT
                page_url,
                SUM(total_requests) as requests,
                ROUND(AVG(avg_page_load_time)) as avg_load,
                ROUND(AVG(avg_server_response_time)) as avg_server,
                ROUND(AVG(avg_dom_ready_time)) as avg_dom,
                ROUND(AVG(avg_first_byte_time)) as avg_ttfb,
                ROUND(AVG(avg_dns_lookup_time)) as avg_dns,
                ROUND(AVG(avg_connect_time)) as avg_connect,
                MAX(max_page_load_time) as max_load,
                MIN(min_page_load_time) as min_load
            FROM performance_metrics_summary
            WHERE time_bucket >= '{$twentyFourHoursAgo}'
              AND time_bucket < '{$oneHourAgo}'
            GROUP BY page_url
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // Get from raw metrics (last hour)
        $realtimePages = $pdo->query("
            SELECT
                page_url,
                COUNT(*) as requests,
                ROUND(AVG(page_load_time)) as avg_load,
                ROUND(AVG(server_response_time)) as avg_server,
                ROUND(AVG(dom_ready_time)) as avg_dom,
                ROUND(AVG(first_byte_time)) as avg_ttfb,
                ROUND(AVG(dns_lookup_time)) as avg_dns,
                ROUND(AVG(connect_time)) as avg_connect,
                MAX(page_load_time) as max_load,
                MIN(page_load_time) as min_load
            FROM enterprise_performance_metrics
            WHERE created_at >= '{$oneHourAgo}'
            GROUP BY page_url
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // Merge data by page_url
        $mergedPages = [];

        foreach ($summaryPages as $page) {
            $url = $page['page_url'];
            $mergedPages[$url] = $page;
        }

        foreach ($realtimePages as $page) {
            $url = $page['page_url'];

            if (isset($mergedPages[$url])) {
                // Combine: weighted average
                $summaryRequests = $mergedPages[$url]['requests'];
                $realtimeRequests = $page['requests'];
                $totalRequests = $summaryRequests + $realtimeRequests;

                $mergedPages[$url] = [
                    'page_url' => $url,
                    'requests' => $totalRequests,
                    'avg_load' => round((
                        $mergedPages[$url]['avg_load'] * $summaryRequests +
                        $page['avg_load'] * $realtimeRequests
                    ) / $totalRequests),
                    'avg_server' => round((
                        $mergedPages[$url]['avg_server'] * $summaryRequests +
                        $page['avg_server'] * $realtimeRequests
                    ) / $totalRequests),
                    'avg_dom' => round((
                        $mergedPages[$url]['avg_dom'] * $summaryRequests +
                        $page['avg_dom'] * $realtimeRequests
                    ) / $totalRequests),
                    'avg_ttfb' => round((
                        $mergedPages[$url]['avg_ttfb'] * $summaryRequests +
                        $page['avg_ttfb'] * $realtimeRequests
                    ) / $totalRequests),
                    'avg_dns' => round((
                        $mergedPages[$url]['avg_dns'] * $summaryRequests +
                        $page['avg_dns'] * $realtimeRequests
                    ) / $totalRequests),
                    'avg_connect' => round((
                        $mergedPages[$url]['avg_connect'] * $summaryRequests +
                        $page['avg_connect'] * $realtimeRequests
                    ) / $totalRequests),
                    'max_load' => max($mergedPages[$url]['max_load'], $page['max_load']),
                    'min_load' => min($mergedPages[$url]['min_load'], $page['min_load']),
                ];
            } else {
                // New page (only in last hour)
                $mergedPages[$url] = $page;
            }
        }

        // Filter >100ms and sort
        $slowPages = array_filter($mergedPages, function ($page) {
            return $page['avg_load'] > 100;
        });

        usort($slowPages, function ($a, $b) {
            return $b['avg_load'] <=> $a['avg_load'];
        });

        return array_slice($slowPages, 0, 10);
    }

    /**
     * Create fresh PDO connection bypassing all cache layers
     * ENTERPRISE: Guarantees real-time data
     */
    private function getFreshPDO(): \PDO
    {
        $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') .
               ';dbname=' . env('DB_DATABASE', 'need2talk');

        return new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Disable HTTP caching for real-time data
     */
    private function disableHttpCache(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
