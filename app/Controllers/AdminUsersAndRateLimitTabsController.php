<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;
use Need2Talk\Services\EmailService;

/**
 * ENTERPRISE GALAXY: Admin Users and Rate Limit Tabs Controller
 *
 * Manages EVERYTHING for the "Rate Limits e Utenti" tab with:
 * - Users table with ALL columns (no cache - fresh PDO)
 * - CSV export (all or selected via checkbox)
 * - Rate limit bans management
 * - Rate limit logs visualization
 * - Rate limit violations tracking
 * - Rate limit monitoring
 * - Rate limit alerts
 *
 * PERFORMANCE: Uses fresh PDO connections bypassing ALL cache layers
 * for guaranteed real-time data (like AdminSecurityEventsController)
 *
 * SCALABILITY: Optimized for millions of concurrent users
 */
class AdminUsersAndRateLimitTabsController extends BaseController
{
    /**
     * Get all data for Users and Rate Limits admin page
     * Returns data array for AdminController to render
     *
     * ENTERPRISE GALAXY: Real-time monitoring with no caching
     */
    public function getPageData(): array
    {
        // ENTERPRISE GALAXY: No-cache headers for real-time data
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // Get pagination parameters
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = (int) ($_GET['per_page'] ?? 50);

        // Validate per_page value
        $allowedPerPage = [25, 50, 100, 250, 500];
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 50;
        }

        $limit = $perPage;
        $offset = ($page - 1) * $limit;

        // Get users data with fresh PDO
        $usersData = $this->getUsersData($limit, $offset);
        $totalUsers = $this->getTotalUsersCount();
        $totalPages = $totalUsers > 0 ? ceil($totalUsers / $limit) : 1;

        // Get rate limiting statistics
        $rateLimitStats = $this->getRateLimitStatistics();

        // Return data for rendering
        return [
            'title' => 'Rate Limits e Utenti',
            'users' => $usersData['users'],
            'total_users' => $totalUsers,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'users_per_page' => $limit,
            'per_page_options' => [25, 50, 100, 250, 500],
            'current_per_page' => $perPage,
            'pagination' => [
                'current' => $page,
                'total' => $totalPages,
                'prev' => $page > 1 ? $page - 1 : null,
                'next' => $page < $totalPages ? $page + 1 : null,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
            ],
            'rate_limit_stats' => $rateLimitStats,
        ];
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Get users data with fresh PDO
     * Bypasses ALL cache layers for guaranteed real-time data
     */
    private function getUsersData(int $limit = 50, int $offset = 0): array
    {
        try {
            // ENTERPRISE NUCLEAR OPTION: Create completely fresh PDO connection
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,  // Force real prepared statements
            ]);

            // ENTERPRISE V4.7: Get ALL users including soft-deleted (for restore capability)
            // Soft-deleted users have deleted_at set and status='deleted'
            $stmt = $pdo->prepare('
                SELECT
                    id,
                    uuid,
                    nickname,
                    name,
                    surname,
                    email,
                    birth_year,
                    birth_month,
                    gender,
                    email_verified,
                    email_verified_at,
                    avatar_url,
                    last_login_at,
                    login_count,
                    last_ip,
                    failed_login_attempts,
                    locked_until,
                    password_changed_at,
                    created_at,
                    updated_at,
                    deleted_at,
                    status,
                    gdpr_consent_at,
                    registration_ip,
                    user_agent,
                    newsletter_opt_in,
                    newsletter_opt_in_at,
                    newsletter_opt_out_at,
                    newsletter_unsubscribe_token
                FROM users
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$limit, $offset]);
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Format dates and add additional info
            foreach ($users as &$user) {
                $user['created_at_formatted'] = date('d/m/Y H:i', strtotime($user['created_at']));
                $user['last_login_formatted'] = $user['last_login_at'] ? date('d/m/Y H:i', strtotime($user['last_login_at'])) : 'Mai';
                $user['deleted_at_formatted'] = $user['deleted_at'] ? date('d/m/Y H:i', strtotime($user['deleted_at'])) : null;

                // ENTERPRISE V4.7: Status badge includes 'deleted' state
                if ($user['status'] === 'active') {
                    $user['status_badge'] = 'success';
                } elseif ($user['status'] === 'deleted') {
                    $user['status_badge'] = 'secondary'; // Gray for deleted
                } else {
                    $user['status_badge'] = 'danger'; // Red for banned/suspended
                }

                // ENTERPRISE V4.7: Flag for soft-deleted users (restorable)
                $user['is_deleted'] = $user['deleted_at'] !== null;

                $user['email_verified_badge'] = $user['email_verified'] ? 'success' : 'warning';
                $user['registration_days_ago'] = floor((time() - strtotime($user['created_at'])) / 86400);
                $user['age'] = $user['birth_year'] ? (date('Y') - $user['birth_year']) : 'N/A';
            }

            return [
                'users' => $users,
                'count' => count($users),
            ];

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to get users data', [
                'error' => $e->getMessage(),
                'limit' => $limit,
                'offset' => $offset,
            ]);

            return [
                'users' => [],
                'count' => 0,
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY ULTIMATE: Get total users count with fresh PDO
     */
    private function getTotalUsersCount(): int
    {
        try {
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // ENTERPRISE V4.7: Count ALL users including soft-deleted
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM users');
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return (int) ($result['total'] ?? 0);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to get total users count', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * ENTERPRISE GALAXY: Get rate limiting statistics
     */
    private function getRateLimitStatistics(): array
    {
        try {
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Active bans count
            $stmt = $pdo->query('SELECT COUNT(*) as count FROM user_rate_limit_bans WHERE expires_at > NOW()');
            $activeBans = (int) ($stmt->fetchColumn() ?? 0);

            // Total violations (last 24h)
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM user_rate_limit_violations WHERE created_at >= NOW() - INTERVAL '24 hours'");
            $violations24h = (int) ($stmt->fetchColumn() ?? 0);

            // Total requests (last 1h)
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM user_rate_limit_log WHERE created_at >= NOW() - INTERVAL '1 hours'");
            $requests1h = (int) ($stmt->fetchColumn() ?? 0);

            // Total alerts (last 24h)
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM user_rate_limit_alerts WHERE last_violation_at >= NOW() - INTERVAL '24 hours'");
            $alerts24h = (int) ($stmt->fetchColumn() ?? 0);

            return [
                'active_bans' => $activeBans,
                'violations_24h' => $violations24h,
                'requests_1h' => $requests1h,
                'alerts_24h' => $alerts24h,
            ];

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to get rate limit statistics', [
                'error' => $e->getMessage(),
            ]);

            return [
                'active_bans' => 0,
                'violations_24h' => 0,
                'requests_1h' => 0,
                'alerts_24h' => 0,
            ];
        }
    }

    /**
     * API ENDPOINT: Get users data (AJAX refresh)
     */
    public function getUsersDataAPI(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: application/json');

        try {
            $limit = (int) ($_GET['limit'] ?? 50);
            $page = (int) ($_GET['page'] ?? 1);

            if (!in_array($limit, [25, 50, 100, 250, 500])) {
                $limit = 50;
            }
            if ($page < 1) {
                $page = 1;
            }

            $offset = ($page - 1) * $limit;
            $usersData = $this->getUsersData($limit, $offset);
            $totalUsers = $this->getTotalUsersCount();
            $totalPages = $totalUsers > 0 ? ceil($totalUsers / $limit) : 1;

            $this->json([
                'success' => true,
                'users' => $usersData['users'],
                'total' => $totalUsers,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to get users data API', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'message' => 'Could not retrieve users data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API ENDPOINT: Get rate limit bans (AJAX refresh)
     */
    public function getRateLimitBansAPI(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Content-Type: application/json');

        try {
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $stmt = $pdo->query('
                SELECT *
                FROM user_rate_limit_bans
                ORDER BY created_at DESC
                LIMIT 100
            ');
            $bans = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'bans' => $bans,
                'total' => count($bans),
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API ENDPOINT: Get rate limit log (AJAX refresh)
     */
    public function getRateLimitLogAPI(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Content-Type: application/json');

        try {
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $stmt = $pdo->query('
                SELECT *
                FROM user_rate_limit_log
                ORDER BY created_at DESC
                LIMIT 200
            ');
            $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'logs' => $logs,
                'total' => count($logs),
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API ENDPOINT: Get rate limit violations (AJAX refresh)
     */
    public function getRateLimitViolationsAPI(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Content-Type: application/json');

        try {
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $stmt = $pdo->query('
                SELECT *
                FROM user_rate_limit_violations
                ORDER BY created_at DESC
                LIMIT 100
            ');
            $violations = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'violations' => $violations,
                'total' => count($violations),
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API ENDPOINT: Get rate limit monitor data (AJAX refresh)
     */
    public function getRateLimitMonitorAPI(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Content-Type: application/json');

        try {
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $stmt = $pdo->query('
                SELECT *
                FROM user_rate_limit_monitor
                ORDER BY last_request DESC
                LIMIT 100
            ');
            $monitor = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'monitor' => $monitor,
                'total' => count($monitor),
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API ENDPOINT: Get rate limit alerts (AJAX refresh)
     */
    public function getRateLimitAlertsAPI(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Content-Type: application/json');

        try {
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $stmt = $pdo->query('
                SELECT *
                FROM user_rate_limit_alerts
                ORDER BY last_violation_at DESC
                LIMIT 100
            ');
            $alerts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'alerts' => $alerts,
                'total' => count($alerts),
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API ENDPOINT: Export users to CSV (all or selected)
     */
    public function exportUsersCSV(): void
    {
        try {
            // Get selected user IDs from POST (comma-separated) or export all
            $selectedIds = $_POST['selected_ids'] ?? '';
            $exportAll = empty($selectedIds);

            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            if ($exportAll) {
                // Export all users
                $stmt = $pdo->query('
                    SELECT
                        id, uuid, nickname, name, surname, email, birth_year, birth_month, gender,
                        email_verified, email_verified_at, avatar_url, last_login_at, login_count,
                        last_ip, failed_login_attempts, locked_until, password_changed_at,
                        created_at, updated_at, status, gdpr_consent_at, registration_ip, user_agent,
                        newsletter_opt_in, newsletter_opt_in_at, newsletter_opt_out_at, newsletter_unsubscribe_token
                    FROM users
                    WHERE deleted_at IS NULL
                    ORDER BY created_at DESC
                ');
                $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } else {
                // Export selected users
                $ids = array_map('intval', explode(',', $selectedIds));
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                $stmt = $pdo->prepare("
                    SELECT
                        id, uuid, nickname, name, surname, email, birth_year, birth_month, gender,
                        email_verified, email_verified_at, avatar_url, last_login_at, login_count,
                        last_ip, failed_login_attempts, locked_until, password_changed_at,
                        created_at, updated_at, status, gdpr_consent_at, registration_ip, user_agent,
                        newsletter_opt_in, newsletter_opt_in_at, newsletter_opt_out_at, newsletter_unsubscribe_token
                    FROM users
                    WHERE deleted_at IS NULL AND id IN ($placeholders)
                    ORDER BY created_at DESC
                ");
                $stmt->execute($ids);
                $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            // Generate CSV
            $filename = 'users_export_' . date('Y-m-d_His') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // CSV Headers
            fputcsv($output, [
                'ID', 'UUID', 'Nickname', 'Name', 'Surname', 'Email', 'Birth Year', 'Birth Month', 'Gender',
                'Email Verified', 'Email Verified At', 'Avatar', 'Last Login At', 'Login Count',
                'Last IP', 'Failed Login Attempts', 'Locked Until', 'Password Changed At',
                'Created At', 'Updated At', 'Status', 'GDPR Consent At', 'Registration IP', 'User Agent',
                'Newsletter Opt-in', 'Newsletter Opt-in At', 'Newsletter Opt-out At', 'Newsletter Token',
            ]);

            // CSV Data
            foreach ($users as $user) {
                fputcsv($output, [
                    $user['id'],
                    $user['uuid'],
                    $user['nickname'],
                    $user['name'],
                    $user['surname'],
                    $user['email'],
                    $user['birth_year'],
                    $user['birth_month'],
                    $user['gender'],
                    $user['email_verified'] ? 'Yes' : 'No',
                    $user['email_verified_at'],
                    $user['avatar_url'] ?? '',
                    $user['last_login_at'],
                    $user['login_count'],
                    $user['last_ip'],
                    $user['failed_login_attempts'],
                    $user['locked_until'],
                    $user['password_changed_at'],
                    $user['created_at'],
                    $user['updated_at'],
                    $user['status'],
                    $user['gdpr_consent_at'],
                    $user['registration_ip'],
                    $user['user_agent'],
                    $user['newsletter_opt_in'] ? 'Yes' : 'No',
                    $user['newsletter_opt_in_at'],
                    $user['newsletter_opt_out_at'],
                    $user['newsletter_unsubscribe_token'],
                ]);
            }

            fclose($output);
            exit;

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to export users CSV', [
                'error' => $e->getMessage(),
            ]);

            header('Content-Type: application/json');
            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API ENDPOINT: Remove rate limit ban
     */
    public function removeBan(): void
    {
        header('Content-Type: application/json');

        try {
            $banId = (int) ($_POST['ban_id'] ?? 0);

            if ($banId <= 0) {
                $this->json(['success' => false, 'error' => 'Invalid ban ID'], 400);

                return;
            }

            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $stmt = $pdo->prepare('DELETE FROM user_rate_limit_bans WHERE id = ?');
            $stmt->execute([$banId]);

            Logger::security('info', 'ADMIN: Rate limit ban removed', [
                'ban_id' => $banId,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => 'Ban removed successfully',
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to remove ban', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ENTERPRISE: Bulk suspend users
     * Sets user status to 'suspended' for selected user IDs
     * Sends notification email to each suspended user (direct, synchronous)
     *
     * LIMIT: Max 20 users per operation to ensure reliable email delivery
     */
    public function bulkSuspendUsers(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            // ENTERPRISE: Max 20 users per bulk operation
            if (count($ids) > 20) {
                $this->json([
                    'success' => false,
                    'error' => 'Limite superato: max 20 utenti per operazione',
                ], 400);

                return;
            }

            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // STEP 1: Get user data BEFORE suspending (for email)
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, email, nickname FROM users WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $users = $stmt->fetchAll();

            // STEP 2: Suspend users in database
            $stmt = $pdo->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();

            Logger::security('info', 'ADMIN: Bulk suspend users', [
                'user_ids' => $ids,
                'affected' => $affected,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // STEP 3: Send notification emails (direct, synchronous)
            $emailsSent = 0;
            $emailService = new EmailService();

            foreach ($users as $user) {
                try {
                    $htmlBody = $this->renderSuspensionEmail($user['email'], $user['nickname'] ?? 'Utente');
                    $emailService->send(
                        $user['email'],
                        '⚠️ Account Sospeso - need2talk',
                        $htmlBody
                    );
                    $emailsSent++;
                } catch (\Exception $emailError) {
                    Logger::email('error', 'Failed to send suspension email', [
                        'user_id' => $user['id'],
                        'email' => $user['email'],
                        'error' => $emailError->getMessage(),
                    ]);
                    // Continue with other users even if one email fails
                }
            }

            $this->json([
                'success' => true,
                'message' => "Sospesi {$affected} utente/i. Inviate {$emailsSent} email di notifica.",
                'affected' => $affected,
                'emails_sent' => $emailsSent,
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to suspend users in bulk', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Render suspension email template
     */
    private function renderSuspensionEmail(string $email, string $nickname): string
    {
        $templatePath = APP_ROOT . '/app/Views/emails/user-suspended.php';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Suspension email template not found');
        }

        // ENTERPRISE FIX: Extract variables to local scope for template
        // Without this, $email and $nickname are not visible inside the include
        $data = [
            'email' => $email,
            'nickname' => $nickname,
        ];
        extract($data);

        ob_start();
        include $templatePath;

        return ob_get_clean();
    }

    /**
     * ENTERPRISE: Bulk activate users
     * Sets user status to 'active' for selected user IDs
     * Sends notification email ONLY to users who were suspended/banned (not already active)
     *
     * LIMIT: Max 20 users per operation to ensure reliable email delivery
     */
    public function bulkActivateUsers(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            // ENTERPRISE: Max 20 users per bulk operation
            if (count($ids) > 20) {
                $this->json([
                    'success' => false,
                    'error' => 'Limite superato: max 20 utenti per operazione',
                ], 400);

                return;
            }

            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // STEP 1: Get user data BEFORE activating (to know who was suspended/banned)
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, email, nickname, status FROM users WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $users = $stmt->fetchAll();

            // Filter: only users who were suspended or banned need email notification
            $usersToNotify = array_filter($users, function ($user) {
                return in_array($user['status'], ['suspended', 'banned'], true);
            });

            // STEP 2: Activate users in database
            $stmt = $pdo->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();

            // ENTERPRISE V4.7: Remove users from banned overlay for INSTANT feed visibility
            // This ensures posts from reactivated users appear immediately in all feeds
            $bannedOverlay = \Need2Talk\Services\Cache\BannedUsersOverlayService::getInstance();
            foreach ($ids as $reactivatedUserId) {
                $bannedOverlay->unbanUser($reactivatedUserId);
            }

            // ENTERPRISE V4.8 (2025-12-06): INVALIDATE FEED CACHE on user reactivation
            // The overlay can HIDE posts (ban) but cannot ADD posts back (unban).
            // When reactivating a user, we must invalidate cached feeds so queries
            // re-fetch from DB and include the user's posts.
            // This is an ASYMMETRIC design:
            //   - BAN: instant via overlay (no cache invalidation)
            //   - UNBAN: requires cache invalidation (rare operation, acceptable trade-off)
            try {
                $cache = db()->getCache();
                if ($cache) {
                    // Invalidate all feed-related cache patterns
                    $cache->deleteByPattern('feed:*');
                    $cache->deleteByPattern('query:*');  // Feed queries are cached here
                    $cache->deleteByPattern('L1:query:*');
                    $cache->deleteByPattern('precomputed_feed:*');

                    Logger::security('info', 'ADMIN: Feed cache invalidated for user reactivation', [
                        'user_ids' => $ids,
                        'patterns_cleared' => ['feed:*', 'query:*', 'L1:query:*', 'precomputed_feed:*'],
                    ]);
                }
            } catch (\Exception $cacheEx) {
                // Non-critical: cache will expire naturally
                Logger::warning('ADMIN: Feed cache invalidation failed (non-critical)', [
                    'error' => $cacheEx->getMessage(),
                ]);
            }

            Logger::security('info', 'ADMIN: Bulk activate users', [
                'user_ids' => $ids,
                'affected' => $affected,
                'users_to_notify' => count($usersToNotify),
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            // STEP 3: Send notification emails ONLY to previously suspended/banned users
            $emailsSent = 0;
            $emailService = new EmailService();

            foreach ($usersToNotify as $user) {
                try {
                    $htmlBody = $this->renderReactivationEmail($user['email'], $user['nickname'] ?? 'Utente');
                    $emailService->send(
                        $user['email'],
                        '✅ Account Riattivato - need2talk',
                        $htmlBody
                    );
                    $emailsSent++;

                    Logger::email('info', 'Reactivation email sent', [
                        'user_id' => $user['id'],
                        'previous_status' => $user['status'],
                    ]);
                } catch (\Exception $emailError) {
                    Logger::email('error', 'Failed to send reactivation email', [
                        'user_id' => $user['id'],
                        'email' => $user['email'],
                        'error' => $emailError->getMessage(),
                    ]);
                    // Continue with other users even if one email fails
                }
            }

            $this->json([
                'success' => true,
                'message' => "Attivati {$affected} utente/i. Inviate {$emailsSent} email di notifica.",
                'affected' => $affected,
                'emails_sent' => $emailsSent,
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to activate users in bulk', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Render reactivation email template
     */
    private function renderReactivationEmail(string $email, string $nickname): string
    {
        $templatePath = APP_ROOT . '/app/Views/emails/user-reactivated.php';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Reactivation email template not found');
        }

        // ENTERPRISE FIX: Extract variables to local scope for template
        // Without this, $email and $nickname are not visible inside the include
        $data = [
            'email' => $email,
            'nickname' => $nickname,
        ];
        extract($data);

        ob_start();
        include $templatePath;

        return ob_get_clean();
    }

    /**
     * Render ban email template
     */
    private function renderBanEmail(string $email, string $nickname, string $reason): string
    {
        $templatePath = APP_ROOT . '/app/Views/emails/user-banned.php';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Ban email template not found');
        }

        // ENTERPRISE FIX: Extract variables to local scope for template
        $data = [
            'email' => $email,
            'nickname' => $nickname,
            'reason' => $reason,
        ];
        extract($data);

        ob_start();
        include $templatePath;

        return ob_get_clean();
    }

    /**
     * ENTERPRISE: Bulk delete users (soft delete)
     * Sets deleted_at timestamp for selected user IDs
     */
    public function bulkDeleteUsers(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // ENTERPRISE FIX: Set status='deleted' for user_status_enum consistency
            $stmt = $pdo->prepare("UPDATE users SET status = 'deleted', deleted_at = NOW(), updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($ids);

            $affected = $stmt->rowCount();

            Logger::security('warning', 'ADMIN: Bulk delete users (soft delete)', [
                'user_ids' => $ids,
                'affected' => $affected,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => "Deleted {$affected} user(s)",
                'affected' => $affected,
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to delete users in bulk', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * ENTERPRISE V4.7: Bulk restore soft-deleted users
     * Clears deleted_at and sets status to 'active' for selected user IDs
     * Also removes user from banned overlay if present
     *
     * LIMIT: Max 20 users per operation
     */
    public function bulkRestoreUsers(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            // ENTERPRISE: Max 20 users per bulk operation
            if (count($ids) > 20) {
                $this->json([
                    'success' => false,
                    'error' => 'Limite superato: max 20 utenti per operazione',
                ], 400);

                return;
            }

            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Restore users: clear deleted_at and set status to active
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE users SET status = 'active', deleted_at = NULL, updated_at = NOW() WHERE id IN ($placeholders) AND deleted_at IS NOT NULL");
            $stmt->execute($ids);

            $affected = $stmt->rowCount();

            // ENTERPRISE V4.7: Remove users from banned overlay (in case they were banned before deletion)
            $bannedOverlay = \Need2Talk\Services\Cache\BannedUsersOverlayService::getInstance();
            foreach ($ids as $restoredUserId) {
                $bannedOverlay->unbanUser($restoredUserId);
            }

            // ENTERPRISE V4.8 (2025-12-06): INVALIDATE FEED CACHE on user restore
            // Same asymmetric design as bulkActivateUsers - restore requires cache clear
            try {
                $cache = db()->getCache();
                if ($cache) {
                    $cache->deleteByPattern('feed:*');
                    $cache->deleteByPattern('query:*');
                    $cache->deleteByPattern('L1:query:*');
                    $cache->deleteByPattern('precomputed_feed:*');

                    Logger::security('info', 'ADMIN: Feed cache invalidated for user restore', [
                        'user_ids' => $ids,
                    ]);
                }
            } catch (\Exception $cacheEx) {
                Logger::warning('ADMIN: Feed cache invalidation failed on restore', [
                    'error' => $cacheEx->getMessage(),
                ]);
            }

            Logger::security('info', 'ADMIN: Bulk restore deleted users', [
                'user_ids' => $ids,
                'affected' => $affected,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => "Ripristinati {$affected} utente/i. I loro post sono nuovamente visibili.",
                'affected' => $affected,
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to restore users in bulk', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * ENTERPRISE: Bulk send email to users
     * Queues email to selected users
     * NOTE: Temporary implementation - will be replaced with AdminEmailWorker system
     */
    public function bulkSendEmail(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';
            $subject = $_POST['subject'] ?? '';
            $message = $_POST['message'] ?? '';

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            if (empty($subject) || empty($message)) {
                $this->json(['success' => false, 'error' => 'Subject and message are required'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Get user emails
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, email, nickname FROM users WHERE id IN ($placeholders) AND deleted_at IS NULL");
            $stmt->execute($ids);
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($users) === 0) {
                $this->json(['success' => false, 'error' => 'No valid users found'], 404);

                return;
            }

            // Queue emails using AsyncEmailQueue
            $emailService = new \Need2Talk\Services\AsyncEmailQueue();
            $queued = 0;

            foreach ($users as $user) {
                try {
                    // ENTERPRISE V12.4: Pass all data for professional admin_bulk_email.php template
                    $emailService->queueEmail([
                        'to' => $user['email'],
                        'subject' => $subject,
                        'body' => str_replace(
                            ['{{nickname}}', '{{email}}'],
                            [$user['nickname'], $user['email']],
                            $message
                        ),
                        'nickname' => $user['nickname'],  // For template greeting
                        'priority' => 'normal',
                        'user_id' => (int) $user['id'],
                        'type' => 'admin_bulk',  // Use professional template
                    ]);
                    $queued++;
                } catch (\Exception $e) {
                    Logger::email('error', 'Failed to queue email for user', [
                        'user_id' => $user['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Logger::security('info', 'ADMIN: Bulk email sent to users', [
                'user_ids' => $ids,
                'queued' => $queued,
                'subject' => $subject,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => "Queued {$queued} email(s) to users",
                'queued' => $queued,
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'Failed to send bulk email', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * ENTERPRISE GALAXY: Bulk deactivate users
     * Sets user status to 'suspended' for selected user IDs
     * Note: user_status_enum values are: pending, active, suspended, deleted, banned
     */
    public function bulkDeactivateUsers(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            $pdo = $this->getFreshPDO();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($ids);

            $affected = $stmt->rowCount();

            Logger::security('info', 'ADMIN: Bulk deactivate users', [
                'user_ids' => $ids,
                'affected' => $affected,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => "Deactivated {$affected} user(s)",
                'affected' => $affected,
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to deactivate users in bulk', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * ENTERPRISE GALAXY: Force email verification for selected users
     * Sets email_verified = TRUE and email_verified_at = NOW()
     */
    public function bulkForceEmailVerification(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';
            $reason = $_POST['reason'] ?? 'Admin manual verification';

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            $pdo = $this->getFreshPDO();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE users SET email_verified = TRUE, email_verified_at = NOW(), updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute($ids);

            $affected = $stmt->rowCount();

            Logger::security('warning', 'ADMIN: Force email verification (bypassing standard process)', [
                'user_ids' => $ids,
                'affected' => $affected,
                'reason' => $reason,
                'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => "Force-verified {$affected} user(s)",
                'affected' => $affected,
            ]);

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to force verify users in bulk', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * ENTERPRISE GALAXY: Ban users permanently with IP blocking
     * Sets status='banned', logs ban, optionally blocks IP
     * Sends notification email to each banned user (direct, synchronous)
     *
     * LIMIT: Max 20 users per operation to ensure reliable email delivery
     */
    public function bulkBanUsers(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';
            $reason = $_POST['reason'] ?? 'Banned by administrator';
            $blockIp = filter_var($_POST['block_ip'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            // ENTERPRISE: Max 20 users per bulk operation
            if (count($ids) > 20) {
                $this->json([
                    'success' => false,
                    'error' => 'Limite superato: max 20 utenti per operazione',
                ], 400);

                return;
            }

            $pdo = $this->getFreshPDO();
            $pdo->beginTransaction();

            try {
                // Get users with their IPs and email info
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, email, nickname, last_ip FROM users WHERE id IN ($placeholders) AND deleted_at IS NULL");
                $stmt->execute($ids);
                $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                if (count($users) === 0) {
                    $pdo->rollBack();
                    $this->json(['success' => false, 'error' => 'No valid users found'], 404);

                    return;
                }

                // Ban users
                $stmt = $pdo->prepare("UPDATE users SET status = 'banned', updated_at = NOW() WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $affected = $stmt->rowCount();

                // Terminate all sessions
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id IN ($placeholders)");
                $stmt->execute($ids);

                // Block IPs if requested
                $blockedIps = 0;
                if ($blockIp) {
                    foreach ($users as $user) {
                        if (!empty($user['last_ip'])) {
                            // Check if IP ban table exists, if not skip IP blocking
                            try {
                                $stmt = $pdo->prepare("
                                    INSERT INTO ip_bans (ip_address, reason, created_at)
                                    VALUES (?, ?, NOW())
                                    ON CONFLICT (ip_address) DO UPDATE SET updated_at = NOW()
                                ");
                                $stmt->execute([$user['last_ip'], $reason]);
                                $blockedIps++;
                            } catch (\Exception $e) {
                                // IP bans table doesn't exist, skip IP blocking
                                Logger::database('warning', 'IP bans table not found, skipping IP blocking', [
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }

                $pdo->commit();

                // ENTERPRISE V4.7: Add banned users to overlay for INSTANT feed filtering
                // This ensures posts disappear from ALL feeds immediately (no cache invalidation needed)
                $bannedOverlay = \Need2Talk\Services\Cache\BannedUsersOverlayService::getInstance();
                foreach ($ids as $bannedUserId) {
                    $bannedOverlay->banUser($bannedUserId);
                }

                Logger::security('warning', 'ADMIN: Bulk ban users', [
                    'user_ids' => $ids,
                    'affected' => $affected,
                    'blocked_ips' => $blockedIps,
                    'reason' => $reason,
                    'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'overlay_updated' => true,
                ]);

                // STEP 4: Send notification emails (direct, synchronous)
                $emailsSent = 0;
                $emailService = new EmailService();

                foreach ($users as $user) {
                    try {
                        $htmlBody = $this->renderBanEmail($user['email'], $user['nickname'] ?? 'Utente', $reason);
                        $emailService->send(
                            $user['email'],
                            '🚫 Account Bannato - need2talk',
                            $htmlBody
                        );
                        $emailsSent++;

                        Logger::email('info', 'Ban notification email sent', [
                            'user_id' => $user['id'],
                            'reason' => $reason,
                        ]);
                    } catch (\Exception $emailError) {
                        Logger::email('error', 'Failed to send ban email', [
                            'user_id' => $user['id'],
                            'email' => $user['email'],
                            'error' => $emailError->getMessage(),
                        ]);
                        // Continue with other users even if one email fails
                    }
                }

                $this->json([
                    'success' => true,
                    'message' => "Bannati {$affected} utente/i" . ($blockedIps > 0 ? ", bloccati {$blockedIps} IP" : "") . ". Inviate {$emailsSent} email di notifica.",
                    'affected' => $affected,
                    'blocked_ips' => $blockedIps,
                    'emails_sent' => $emailsSent,
                ]);

            } catch (\Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Logger::database('error', 'Failed to ban users in bulk', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * ENTERPRISE GALAXY: Bulk send password reset tokens via AdminEmailQueue
     * Generates secure reset tokens and queues emails through dedicated admin worker
     */
    public function bulkSendPasswordReset(): void
    {
        header('Content-Type: application/json');

        try {
            $userIds = $_POST['user_ids'] ?? '';
            $tokenExpiryHours = (int) ($_POST['token_expiry_hours'] ?? 24);

            if (empty($userIds)) {
                $this->json(['success' => false, 'error' => 'No users selected'], 400);

                return;
            }

            $ids = array_map('intval', explode(',', $userIds));

            if (count($ids) === 0) {
                $this->json(['success' => false, 'error' => 'Invalid user IDs'], 400);

                return;
            }

            // Validate expiry hours (1-72 hours)
            if ($tokenExpiryHours < 1 || $tokenExpiryHours > 72) {
                $tokenExpiryHours = 24;
            }

            $pdo = $this->getFreshPDO();

            // Get user data
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, email, nickname FROM users WHERE id IN ($placeholders) AND deleted_at IS NULL");
            $stmt->execute($ids);
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($users) === 0) {
                $this->json(['success' => false, 'error' => 'No valid users found'], 404);

                return;
            }

            // Get admin info (assume logged in admin)
            $adminId = $_SESSION['admin_user_id'] ?? 1;
            $adminEmail = $_SESSION['admin_email'] ?? get_env('MAIL_FROM_ADDRESS', 'admin@need2talk.app');
            $adminName = $_SESSION['admin_name'] ?? 'Amministrazione';

            // Initialize AdminEmailQueue
            $queue = new \Need2Talk\Services\AdminEmailQueue();
            $queued = 0;
            $errors = [];

            foreach ($users as $user) {
                try {
                    // Generate secure reset token
                    $resetToken = bin2hex(random_bytes(32)); // 64-char hex token
                    $hashedToken = password_hash($resetToken, PASSWORD_ARGON2ID);
                    $expiresAt = date('Y-m-d H:i:s', time() + ($tokenExpiryHours * 3600));

                    // Store token in database
                    $stmt = $pdo->prepare("
                        INSERT INTO password_resets (user_id, email, token, expires_at, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                        ON CONFLICT (id) DO UPDATE SET
                            token = EXCLUDED.token,
                            expires_at = EXCLUDED.expires_at,
                            created_at = NOW()
                    ");
                    $stmt->execute([$user['id'], $user['email'], $hashedToken, $expiresAt]);

                    // Generate reset URL
                    $resetUrl = get_env('APP_URL') . '/auth/reset-password?token=' . urlencode($resetToken) . '&email=' . urlencode($user['email']);

                    // Queue email via AdminEmailQueue
                    $jobId = $queue->enqueue([
                        'email_type' => 'password_reset_token',
                        'admin_id' => $adminId,
                        'admin_email' => $adminEmail,
                        'recipient_user_id' => $user['id'],
                        'recipient_email' => $user['email'],
                        'subject' => 'Reset Password - need2talk',
                        'template' => 'password_reset_token',
                        'template_data' => [
                            'nickname' => $user['nickname'],
                            'recipient_email' => $user['email'],
                            'reset_token' => $resetToken,
                            'reset_url' => $resetUrl,
                            'token_expiry_hours' => $tokenExpiryHours,
                            'admin_name' => $adminName,
                            'admin_email' => $adminEmail,
                            'job_id' => null, // Will be set by queue
                        ],
                        'priority' => 'high', // Password resets are high priority
                        'ip_address' => get_server('REMOTE_ADDR'),
                        'user_agent' => get_server('HTTP_USER_AGENT'),
                        'additional_data' => [
                            'token_expiry_hours' => $tokenExpiryHours,
                            'generated_by_admin' => true,
                        ],
                    ]);

                    if ($jobId !== false) {
                        $queued++;
                    } else {
                        $errors[] = "Failed to queue email for {$user['email']}";
                    }

                } catch (\Exception $e) {
                    Logger::email('error', 'Failed to queue password reset for user', [
                        'user_id' => $user['id'],
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = "Error for user {$user['id']}: " . $e->getMessage();
                }
            }

            // Security audit log
            Logger::security('warning', 'ADMIN: Bulk password reset tokens sent', [
                'user_ids' => $ids,
                'queued' => $queued,
                'errors' => count($errors),
                'token_expiry_hours' => $tokenExpiryHours,
                'admin_id' => $adminId,
                'admin_ip' => get_server('REMOTE_ADDR'),
            ]);

            $response = [
                'success' => true,
                'message' => "Queued {$queued} password reset email(s)",
                'queued' => $queued,
                'total_users' => count($users),
            ];

            if (count($errors) > 0) {
                $response['errors'] = $errors;
                $response['message'] .= " ({$queued}/" . count($users) . " successful)";
            }

            $this->json($response);

        } catch (\Exception $e) {
            Logger::email('error', 'Failed to send bulk password reset', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper: Get fresh PDO connection (no cache)
     */
    private function getFreshPDO(): \PDO
    {
        $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');

        return new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
