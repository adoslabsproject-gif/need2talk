<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * Admin Anti-Scan System Controller
 *
 * ENTERPRISE GALAXY: Handles ALL anti-scan management for admin panel
 * Real-time monitoring with dual-write architecture (Redis + Database)
 * Optimized for millions of concurrent vulnerability scanning attempts
 * PERFORMANCE OPTIMIZED: Uses fresh PDO connections for real-time data
 */
class AdminAntiScanController extends BaseController
{
    /**
     * Get all data for Anti-Scan admin page
     * Returns data array for AdminController to render
     *
     * ENTERPRISE GALAXY: Real-time anti-scan monitoring with no caching
     */
    public function getPageData(): array
    {
        // ENTERPRISE GALAXY: No-cache headers for real-time data
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // Get database bans with pagination
        $limit = (int) ($_GET['limit'] ?? 50);
        $page = (int) ($_GET['page'] ?? 1);
        $databaseData = $this->getBansFromDatabase($limit, $page);

        // ENTERPRISE GALAXY: Get anti-scan dashboard statistics
        $dashboardStats = $this->getAntiScanDashboardStats();

        // Return data for rendering
        return [
            'title' => 'Anti-Scan System Monitoring',
            'bans' => $databaseData['bans'],
            'total_bans' => $databaseData['total'],
            'severity_counts' => $databaseData['severity_counts'],
            'current_page' => $databaseData['page'],
            'total_pages' => $databaseData['total_pages'],
            'limit' => $databaseData['limit'],
            'active_bans' => $dashboardStats['active_bans'],
            'honeypot_catches_24h' => $dashboardStats['honeypot_catches_24h'],
            'critical_bans_24h' => $dashboardStats['critical_bans_24h'],
            'honeypot_count' => $dashboardStats['honeypot_count'],
        ];
    }

    /**
     * Get bans from database with pagination
     * ENTERPRISE GALAXY ULTIMATE: Fresh PDO bypassing ALL cache layers for real-time data
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    private function getBansFromDatabase(int $limit = 50, int $page = 1): array
    {
        try {
            // Validate parameters
            if (!in_array($limit, [25, 50, 100])) {
                $limit = 50;
            }
            if ($page < 1) {
                $page = 1;
            }

            $offset = ($page - 1) * $limit;

            // ENTERPRISE NUCLEAR OPTION: Create completely fresh PDO connection bypassing ALL cache layers
            // This ensures we ALWAYS get real-time data from database, no stale cache
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,  // Force real prepared statements (no query cache)
            ]);

            // Get total count
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM vulnerability_scan_bans');
            $total = (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0);

            // Get bans with optimized query (uses PRIMARY key for ORDER BY id DESC)
            $stmt = $pdo->prepare('
                SELECT id, ip_address, ip_network, ban_type, severity, score, threshold_exceeded,
                       banned_at, expires_at, duration_seconds,
                       scan_patterns, paths_accessed, honeypot_triggered, honeypot_path,
                       user_agent, user_agent_type, referer, request_method,
                       country_code, asn, is_tor, is_vpn,
                       violation_count, total_requests, suspicious_requests,
                       created_by, admin_user_id, notes, updated_at
                FROM vulnerability_scan_bans
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            $bans = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get severity counts (for statistics)
            $stmt = $pdo->query('
                SELECT severity, COUNT(*) as count
                FROM vulnerability_scan_bans
                GROUP BY severity
            ');
            $severityCounts = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $severityCounts[$row['severity']] = (int) $row['count'];
            }

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            return [
                'bans' => $bans,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
                'severity_counts' => $severityCounts,
            ];
        } catch (\Exception $e) {
            // ENTERPRISE: Log admin panel failure (critical - admin blind to system errors!)
            Logger::error('ADMIN PANEL FAILURE: Failed to fetch anti-scan bans', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'page' => $page,
                'limit' => $limit,
                'impact' => 'Admin panel shows 0 bans instead of error - system monitoring degraded',
                'action_required' => 'Check database connectivity: docker compose ps postgres',  // ENTERPRISE: PostgreSQL (migrated)
            ]);

            return [
                'bans' => [],
                'total' => 0,
                'page' => 1,
                'limit' => $limit,
                'total_pages' => 1,
                'severity_counts' => [],
            ];
        }
    }

    /**
     * Get bans from database with pagination (API endpoint for AJAX refresh)
     * ENTERPRISE GALAXY ULTIMATE: Fresh PDO bypassing ALL cache layers for real-time data
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    public function getDatabaseBans(): void
    {
        // ENTERPRISE GALAXY: Disable HTTP caching for real-time data
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        try {
            // Get pagination parameters from query string
            $limit = (int) ($_GET['limit'] ?? 50);
            $page = (int) ($_GET['page'] ?? 1);

            // Validate limit (25, 50, or 100)
            if (!in_array($limit, [25, 50, 100])) {
                $limit = 50;
            }

            // Validate page (min 1)
            if ($page < 1) {
                $page = 1;
            }

            // Calculate offset
            $offset = ($page - 1) * $limit;

            // ENTERPRISE NUCLEAR OPTION: Create completely fresh PDO connection bypassing ALL cache layers
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,  // Force real prepared statements (no query cache)
            ]);

            // Get total count - Direct DB query, no cache
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM vulnerability_scan_bans');
            $countResult = $stmt->fetch(\PDO::FETCH_ASSOC);
            $total = (int) ($countResult['total'] ?? 0);

            // Calculate total pages
            $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

            // Ensure page doesn't exceed total pages
            if ($page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $limit;
            }

            // Get bans - Optimized query (uses PRIMARY key for ORDER BY id DESC)
            $stmt = $pdo->prepare('
                SELECT id, ip_address, ip_network, ban_type, severity, score, threshold_exceeded,
                       banned_at, expires_at, duration_seconds,
                       scan_patterns, paths_accessed, honeypot_triggered, honeypot_path,
                       user_agent, user_agent_type, referer, request_method,
                       country_code, asn, is_tor, is_vpn,
                       violation_count, total_requests, suspicious_requests,
                       created_by, admin_user_id, notes, updated_at
                FROM vulnerability_scan_bans
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            $bans = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get severity counts
            $stmt = $pdo->query('
                SELECT severity, COUNT(*) as count
                FROM vulnerability_scan_bans
                GROUP BY severity
            ');
            $severityCounts = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $severityCounts[$row['severity']] = (int) $row['count'];
            }

            // ENTERPRISE GALAXY: Get real-time statistics
            $stats = $this->getAntiScanDashboardStats();

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            $this->json([
                'success' => true,
                'bans' => $bans,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'severity_counts' => $severityCounts,
                'stats' => $stats,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get anti-scan bans for admin panel', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'message' => 'Could not retrieve anti-scan bans',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Get anti-scan dashboard statistics
     * Real-time metrics for anti-scan overview dashboard with fresh PDO
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    private function getAntiScanDashboardStats(): array
    {
        try {
            // ENTERPRISE NUCLEAR OPTION: Create completely fresh PDO connection bypassing ALL cache layers
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,  // Force real prepared statements (no query cache)
            ]);

            // Get total bans count
            $stmt = $pdo->query('SELECT COUNT(*) as count FROM vulnerability_scan_bans');
            $totalBans = (int) ($stmt->fetchColumn() ?? 0);

            // Get active bans (not expired)
            $stmt = $pdo->prepare('
                SELECT COUNT(*) as count
                FROM vulnerability_scan_bans
                WHERE expires_at > NOW()
            ');
            $stmt->execute();
            $activeBans = (int) ($stmt->fetchColumn() ?? 0);

            // Get honeypot catches (last 24 hours)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM vulnerability_scan_bans
                WHERE honeypot_triggered = TRUE
                AND banned_at >= NOW() - INTERVAL '24 hours'
            ");
            $stmt->execute();
            $honeypotCatches24h = (int) ($stmt->fetchColumn() ?? 0);

            // Get total honeypot count (all time)
            $stmt = $pdo->prepare('
                SELECT COUNT(*) as count
                FROM vulnerability_scan_bans
                WHERE honeypot_triggered = TRUE
            ');
            $stmt->execute();
            $honeypotCount = (int) ($stmt->fetchColumn() ?? 0);

            // Get critical bans (last 24 hours)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM vulnerability_scan_bans
                WHERE severity = 'critical'
                AND banned_at >= NOW() - INTERVAL '24 hours'
            ");
            $stmt->execute();
            $criticalBans24h = (int) ($stmt->fetchColumn() ?? 0);

            // Get bans by severity
            $stmt = $pdo->query('
                SELECT severity, COUNT(*) as count
                FROM vulnerability_scan_bans
                GROUP BY severity
            ');
            $bansBySeverity = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $bansBySeverity[$row['severity']] = (int) $row['count'];
            }

            // Get top threat IPs (last 30 days)
            $stmt = $pdo->prepare("
                SELECT ip_address, COUNT(*) as ban_count, MAX(score) as max_score,
                       MAX(severity) as max_severity, SUM(violation_count) as total_violations,
                       MAX(banned_at) as last_banned
                FROM vulnerability_scan_bans
                WHERE banned_at >= NOW() - INTERVAL '30 days'
                GROUP BY ip_address
                ORDER BY ban_count DESC, max_score DESC
                LIMIT 10
            ");
            $stmt->execute();
            $topThreatIPs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get recent bans (last 10)
            $stmt = $pdo->prepare('
                SELECT id, ip_address, ban_type, severity, score, honeypot_triggered,
                       honeypot_path, banned_at, expires_at
                FROM vulnerability_scan_bans
                ORDER BY id DESC
                LIMIT 10
            ');
            $stmt->execute();
            $recentBans = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get bans by type
            $stmt = $pdo->query('
                SELECT ban_type, COUNT(*) as count
                FROM vulnerability_scan_bans
                GROUP BY ban_type
            ');
            $bansByType = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $bansByType[$row['ban_type']] = (int) $row['count'];
            }

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            return [
                'total_bans' => $totalBans,
                'active_bans' => $activeBans,
                'honeypot_catches_24h' => $honeypotCatches24h,
                'honeypot_count' => $honeypotCount,
                'critical_bans_24h' => $criticalBans24h,
                'bans_by_severity' => $bansBySeverity,
                'bans_by_type' => $bansByType,
                'top_threat_ips' => $topThreatIPs,
                'recent_bans' => $recentBans,
                'timestamp' => date('c'),
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get anti-scan dashboard stats', [
                'error' => $e->getMessage(),
            ]);

            return [
                'total_bans' => 0,
                'active_bans' => 0,
                'honeypot_catches_24h' => 0,
                'honeypot_count' => 0,
                'critical_bans_24h' => 0,
                'bans_by_severity' => [],
                'bans_by_type' => [],
                'top_threat_ips' => [],
                'recent_bans' => [],
            ];
        }
    }
}
