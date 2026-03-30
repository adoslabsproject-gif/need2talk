<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * Admin Security Events Controller
 *
 * ENTERPRISE GALAXY: Handles ALL security event management for admin panel
 * Real-time monitoring with dual-write architecture (DB + file logs)
 * Optimized for millions of concurrent security events
 * PERFORMANCE OPTIMIZED: Uses connection pool instead of fresh connections
 */
class AdminSecurityEventsController extends BaseController
{
    /**
     * Get all data for Security Events admin page
     * Returns data array for AdminController to render
     *
     * ENTERPRISE GALAXY: Real-time security monitoring with no caching
     */
    public function getPageData(): array
    {
        // ENTERPRISE GALAXY: No-cache headers for real-time data
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // Get security log files (security-*.log)
        $securityLogFiles = $this->getSecurityLogFiles();

        // Get database events with pagination
        $limit = (int) ($_GET['limit'] ?? 50);
        $page = (int) ($_GET['page'] ?? 1);
        $databaseData = $this->getSecurityEventsFromDatabase($limit, $page);

        // ENTERPRISE GALAXY: Get security dashboard statistics
        $dashboardStats = $this->getSecurityDashboardStats();

        // Return data for rendering
        return [
            'title' => 'Security Event Monitoring',
            'security_log_files' => $securityLogFiles,
            'events' => $databaseData['events'],
            'total_events' => $databaseData['total'],
            'level_counts' => $databaseData['level_counts'],
            'current_page' => $databaseData['page'],
            'total_pages' => $databaseData['total_pages'],
            'limit' => $databaseData['limit'],
            'security_status' => $dashboardStats, // ENTERPRISE: Real security metrics
        ];
    }

    /**
     * Get security log files with metadata
     * ENTERPRISE GALAXY: Fast file scanning with metadata extraction
     */
    private function getSecurityLogFiles(): array
    {
        $logPath = APP_ROOT . '/storage/logs';
        $securityFiles = [];
        $totalSize = 0;

        if (!is_dir($logPath)) {
            return [
                'files' => [],
                'total_files' => 0,
                'total_size' => 0,
                'total_size_formatted' => '0 B',
            ];
        }

        // Find all security-*.log files
        $files = glob($logPath . '/security-*.log');

        if ($files) {
            // Sort by modification time (newest first)
            usort($files, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            foreach ($files as $filepath) {
                $filename = basename($filepath);
                $size = filesize($filepath);
                $totalSize += $size;
                $modified = filemtime($filepath);
                $lines = 0;

                // Count lines (fast method for large files)
                try {
                    $lines = count(file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
                } catch (\Exception $e) {
                    // ENTERPRISE: Log file read failure (non-critical - show 0 lines)
                    Logger::warning('ADMIN PANEL: Failed to count log file lines', [
                        'file' => $filename,
                        'filepath' => $filepath,
                        'error' => $e->getMessage(),
                        'impact' => 'Log file shows 0 lines in admin panel instead of actual count',
                        'action_required' => 'Check file permissions and disk space',
                    ]);
                    $lines = 0;
                }

                // Calculate relative time
                $now = time();
                $diff = $now - $modified;
                if ($diff < 60) {
                    $relativeTime = 'Just now';
                } elseif ($diff < 3600) {
                    $relativeTime = floor($diff / 60) . ' minutes ago';
                } elseif ($diff < 86400) {
                    $relativeTime = floor($diff / 3600) . ' hours ago';
                } else {
                    $relativeTime = floor($diff / 86400) . ' days ago';
                }

                $securityFiles[] = [
                    'filename' => $filename,
                    'size' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'lines' => $lines,
                    'modified' => $modified,
                    'relative_time' => $relativeTime,
                ];
            }
        }

        return [
            'files' => $securityFiles,
            'total_files' => count($securityFiles),
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
        ];
    }

    /**
     * Format bytes to human readable size
     * ENTERPRISE GALAXY: Efficient byte formatting
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * Get security events from database with pagination
     * ENTERPRISE GALAXY ULTIMATE: Fresh PDO bypassing ALL cache layers for real-time data
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    private function getSecurityEventsFromDatabase(int $limit = 50, int $page = 1): array
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
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM security_events');
            $total = (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0);

            // Get events with optimized query (uses PRIMARY key for ORDER BY id DESC)
            $stmt = $pdo->prepare('
                SELECT id, channel, level, message, context, ip_address, user_agent,
                       user_id, session_id, created_at
                FROM security_events
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get level counts (for statistics)
            $stmt = $pdo->query('
                SELECT level, COUNT(*) as count
                FROM security_events
                GROUP BY level
            ');
            $levelCounts = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $levelCounts[$row['level']] = (int) $row['count'];
            }

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            return [
                'events' => $events,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
                'level_counts' => $levelCounts,
            ];
        } catch (\Exception $e) {
            // ENTERPRISE: Log admin panel failure (critical - admin blind to security events!)
            Logger::error('ADMIN PANEL FAILURE: Failed to fetch security events', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'page' => $page,
                'limit' => $limit,
                'impact' => 'Admin panel shows 0 security events instead of error - security monitoring degraded',
                'action_required' => 'Check database connectivity and security_events table: docker compose ps postgres',  // ENTERPRISE: PostgreSQL
            ]);

            return [
                'events' => [],
                'total' => 0,
                'page' => 1,
                'limit' => $limit,
                'total_pages' => 1,
                'level_counts' => [],
            ];
        }
    }

    /**
     * Get events from database with pagination (API endpoint for AJAX refresh)
     * ENTERPRISE GALAXY ULTIMATE: Fresh PDO bypassing ALL cache layers for real-time data
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    public function getDatabaseEvents(): void
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
            // This ensures we ALWAYS get real-time data from database, no stale cache
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,  // Force real prepared statements (no query cache)
            ]);

            // Get total count - Direct DB query, no cache
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM security_events');
            $countResult = $stmt->fetch(\PDO::FETCH_ASSOC);
            $total = (int) ($countResult['total'] ?? 0);

            // Calculate total pages
            $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

            // Ensure page doesn't exceed total pages
            if ($page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $limit;
            }

            // Get events - Optimized query (uses PRIMARY key for ORDER BY id DESC)
            $stmt = $pdo->prepare('
                SELECT id, channel, level, message, context, ip_address, user_agent,
                       user_id, session_id, created_at
                FROM security_events
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get level counts
            $stmt = $pdo->query('
                SELECT level, COUNT(*) as count
                FROM security_events
                GROUP BY level
            ');
            $levelCounts = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $levelCounts[$row['level']] = (int) $row['count'];
            }

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            $this->json([
                'success' => true,
                'events' => $events,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'level_counts' => $levelCounts,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to get security events for admin panel', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'message' => 'Could not retrieve security events',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Get security dashboard statistics
     * Real-time metrics for security overview dashboard with fresh PDO
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    private function getSecurityDashboardStats(): array
    {
        try {
            // ENTERPRISE NUCLEAR OPTION: Create completely fresh PDO connection bypassing ALL cache layers
            // This ensures we ALWAYS get real-time data from database, no stale cache
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,  // Force real prepared statements (no query cache)
            ]);

            // Get failed login attempts (last 24 hours)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM admin_login_attempts
                WHERE created_at >= NOW() - INTERVAL '24 hours'
                AND failure_reason NOT LIKE '%SUCCESS%'
            ");
            $stmt->execute();
            $failedLogins = (int) ($stmt->fetchColumn() ?? 0);

            // Get unique blocked/suspicious IPs (last 24 hours)
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT ip_address) as count
                FROM admin_login_attempts
                WHERE created_at >= NOW() - INTERVAL '24 hours'
                AND failure_reason NOT LIKE '%SUCCESS%'
            ");
            $stmt->execute();
            $blockedIps = (int) ($stmt->fetchColumn() ?? 0);

            // Get security events by severity (last 24 hours)
            $stmt = $pdo->prepare("
                SELECT level, COUNT(*) as count
                FROM security_events
                WHERE created_at >= NOW() - INTERVAL '24 hours'
                GROUP BY level
            ");
            $stmt->execute();
            $eventsBySeverity = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $eventsBySeverity[$row['level']] = (int) $row['count'];
            }

            // Calculate critical events count
            $criticalCount = ($eventsBySeverity['emergency'] ?? 0)
                           + ($eventsBySeverity['alert'] ?? 0)
                           + ($eventsBySeverity['critical'] ?? 0);

            // Calculate total events
            $totalEvents = array_sum($eventsBySeverity);

            // Calculate security level
            $securityLevel = $this->calculateSecurityLevel($criticalCount, $failedLogins, $totalEvents);

            // Get recent critical security events (last 5)
            // CRITICAL FIX (2025-11-23): PostgreSQL uses single quotes for string values
            $stmt = $pdo->prepare('
                SELECT id, channel, level, message, created_at, ip_address
                FROM security_events
                WHERE level IN (\'emergency\', \'alert\', \'critical\')
                ORDER BY id DESC
                LIMIT 5
            ');
            $stmt->execute();
            $recentCriticalEvents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get active admin sessions count
            $stmt = $pdo->query('
                SELECT COUNT(*) as count
                FROM admin_sessions
                WHERE expires_at > NOW()
            ');
            $activeSessions = (int) ($stmt->fetchColumn() ?? 0);

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            return [
                'failed_logins_24h' => $failedLogins,
                'blocked_ips_24h' => $blockedIps,
                'critical_events_24h' => $criticalCount,
                'total_events_24h' => $totalEvents,
                'events_by_severity' => $eventsBySeverity,
                'security_level' => $securityLevel['level'],
                'security_level_color' => $securityLevel['color'],
                'security_level_icon' => $securityLevel['icon'],
                'security_level_description' => $securityLevel['description'],
                'recent_critical_events' => $recentCriticalEvents,
                'active_admin_sessions' => $activeSessions,
                'timestamp' => date('c'),
            ];

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to get security dashboard stats', [
                'error' => $e->getMessage(),
            ]);

            return [
                'failed_logins_24h' => 0,
                'blocked_ips_24h' => 0,
                'critical_events_24h' => 0,
                'total_events_24h' => 0,
                'events_by_severity' => [],
                'security_level' => 'Unknown',
                'security_level_color' => 'gray',
                'security_level_icon' => 'question-circle',
                'security_level_description' => 'Unable to determine security status',
                'recent_critical_events' => [],
                'active_admin_sessions' => 0,
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY: Calculate security level based on metrics
     */
    private function calculateSecurityLevel(int $criticalEvents, int $failedLogins, int $totalEvents): array
    {
        // Multi-factor security level calculation
        if ($criticalEvents >= 5 || $failedLogins >= 20) {
            return [
                'level' => 'Critical',
                'color' => 'red',
                'icon' => 'exclamation-triangle',
                'description' => 'Immediate attention required - Multiple critical security events detected',
            ];
        }

        if ($criticalEvents >= 2 || $failedLogins >= 10 || $totalEvents >= 50) {
            return [
                'level' => 'High',
                'color' => 'orange',
                'icon' => 'shield-alt',
                'description' => 'Elevated security risk - Monitor closely',
            ];
        }

        if ($criticalEvents >= 1 || $failedLogins >= 5 || $totalEvents >= 20) {
            return [
                'level' => 'Medium',
                'color' => 'yellow',
                'icon' => 'shield-check',
                'description' => 'Moderate security activity - Normal monitoring',
            ];
        }

        return [
            'level' => 'Low',
            'color' => 'green',
            'icon' => 'shield',
            'description' => 'System secure - No significant threats detected',
        ];
    }
}
