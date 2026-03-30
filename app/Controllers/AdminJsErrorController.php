<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * Admin JS Error Controller
 *
 * Handles ALL JavaScript error management for admin panel
 * Separated from AdminController for cleaner code organization
 * PERFORMANCE OPTIMIZED: Uses connection pool instead of fresh connections
 */
class AdminJsErrorController extends BaseController
{
    /**
     * Get all data for JS Errors admin page (log files + database)
     * Returns data array for AdminController to render
     */
    public function getPageData(): array
    {
        // ENTERPRISE GALAXY: Real-time JS error monitoring
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // Get JS error log files (js_errors-{date}.log)
        $jsErrorLogs = $this->getJsErrorLogFiles();

        // Get database errors with pagination
        $limit = (int) ($_GET['limit'] ?? 50);
        $page = (int) ($_GET['page'] ?? 1);
        $databaseData = $this->getJsErrorsFromDatabase($limit, $page);

        // Return data for rendering
        return [
            'title' => 'JS Error Monitoring',
            'js_error_logs' => $jsErrorLogs,
            'errors' => $databaseData['errors'],
            'total_errors' => $databaseData['total'],
            'severity_counts' => $databaseData['severity_counts'],
            'current_page' => $databaseData['page'],
            'total_pages' => $databaseData['total_pages'],
            'limit' => $databaseData['limit'],
        ];
    }

    /**
     * Get JS error log files with metadata
     */
    private function getJsErrorLogFiles(): array
    {
        $logPath = APP_ROOT . '/storage/logs';
        $jsErrorFiles = [];
        $totalSize = 0;

        if (!is_dir($logPath)) {
            return [
                'files' => [],
                'total_files' => 0,
                'total_size' => 0,
                'total_size_formatted' => '0 B',
            ];
        }

        // Find all js_errors-*.log files
        $files = glob($logPath . '/js_errors-*.log');

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

                $jsErrorFiles[] = [
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
            'files' => $jsErrorFiles,
            'total_files' => count($jsErrorFiles),
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
        ];
    }

    /**
     * Format bytes to human readable size
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
     * Get JS errors from database with pagination
     * ENTERPRISE GALAXY ULTIMATE: Fresh PDO bypassing ALL cache layers for real-time data
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    private function getJsErrorsFromDatabase(int $limit = 50, int $page = 1): array
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

            // Get total
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM enterprise_js_errors');
            $total = (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0);

            // Get errors
            $stmt = $pdo->prepare('
                SELECT id, error_type, message, filename, line_number,
                       column_number, stack_trace, page_url, user_agent,
                       user_id, severity, created_at
                FROM enterprise_js_errors
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            $errors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get severity counts
            $stmt = $pdo->query('
                SELECT severity, COUNT(*) as count
                FROM enterprise_js_errors
                GROUP BY severity
            ');
            $severityCounts = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $severityCounts[$row['severity']] = (int) $row['count'];
            }

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            return [
                'errors' => $errors,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
                'severity_counts' => $severityCounts,
            ];
        } catch (\Exception $e) {
            return [
                'errors' => [],
                'total' => 0,
                'page' => 1,
                'limit' => $limit,
                'total_pages' => 1,
                'severity_counts' => [],
            ];
        }
    }

    /**
     * Get errors from database with pagination (API endpoint for AJAX refresh)
     * ENTERPRISE GALAXY ULTIMATE: Fresh PDO bypassing ALL cache layers for real-time data
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    public function getDatabaseErrors(): void
    {
        // ENTERPRISE TIPS: Disable HTTP caching for real-time data
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
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM enterprise_js_errors');
            $countResult = $stmt->fetch(\PDO::FETCH_ASSOC);
            $total = (int) ($countResult['total'] ?? 0);

            // Calculate total pages
            $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

            // Ensure page doesn't exceed total pages
            if ($page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $limit;
            }

            // Get errors
            $stmt = $pdo->prepare('
                SELECT id, error_type, message, filename, line_number,
                       column_number, stack_trace, page_url, user_agent,
                       user_id, severity, created_at
                FROM enterprise_js_errors
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            $errors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get severity counts
            $stmt = $pdo->query('
                SELECT severity, COUNT(*) as count
                FROM enterprise_js_errors
                GROUP BY severity
            ');
            $severityCounts = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $severityCounts[$row['severity']] = (int) $row['count'];
            }

            // ENTERPRISE: Fresh PDO connection closed automatically when out of scope (no pool return)

            $this->json([
                'success' => true,
                'errors' => $errors,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'severity_counts' => $severityCounts,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get JS errors for admin panel', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'message' => 'Could not retrieve errors',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
