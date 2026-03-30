<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: Security Events Test Controller
 *
 * Generates test security events for testing the dual-write system
 * PERFORMANCE OPTIMIZED: Uses connection pool instead of fresh connections
 */
class SecurityTestController extends BaseController
{
    /**
     * Generate test security event
     */
    public function generateTestEvent(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);

            $level = $input['level'] ?? 'info';
            $scenario = $input['scenario'] ?? 'generic_test';

            // Validate PSR-3 level
            $validLevels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
            if (!in_array($level, $validLevels)) {
                $this->json(['success' => false, 'error' => 'Invalid PSR-3 level'], 400);

                return;
            }

            // Generate security event based on scenario
            $message = '';
            $context = [
                'test_mode' => true,
                'timestamp' => time(),
                'scenario' => $scenario,
            ];

            switch ($scenario) {
                case 'login_attempt':
                    $message = "Test {$level}: Failed login attempt";
                    $context['email_hash'] = hash('sha256', 'test@example.com');
                    $context['reason'] = 'Invalid credentials';
                    break;

                case 'authorization_failure':
                    $message = "Test {$level}: Authorization check failed";
                    $context['resource'] = 'admin_panel';
                    $context['required_permission'] = 'admin.access';
                    break;

                case 'suspicious_activity':
                    $message = "Test {$level}: Suspicious activity detected";
                    $context['activity_type'] = 'multiple_failed_attempts';
                    $context['threshold_exceeded'] = true;
                    break;

                case 'data_access':
                    $message = "Test {$level}: Sensitive data access";
                    $context['data_type'] = 'user_profile';
                    $context['action'] = 'read';
                    break;

                case 'configuration_change':
                    $message = "Test {$level}: Configuration modified";
                    $context['setting'] = 'logging_level';
                    $context['old_value'] = 'warning';
                    $context['new_value'] = 'debug';
                    break;

                case 'rate_limit':
                    $message = "Test {$level}: Rate limit exceeded";
                    $context['limit_type'] = 'api_requests';
                    $context['limit'] = 100;
                    $context['current'] = 105;
                    break;

                case 'session_management':
                    $message = "Test {$level}: Session management event";
                    $context['action'] = 'force_logout';
                    $context['target_session'] = 'test_session_id_' . time();
                    break;

                case 'csrf_attack':
                    $message = "Test {$level}: CSRF token validation failed";
                    $context['expected_token'] = 'valid_token';
                    $context['received_token'] = 'invalid_token';
                    break;

                default:
                    $message = "Test {$level}: Generic security event";
                    $context['description'] = 'Generic security test event';
                    break;
            }

            // ENTERPRISE GALAXY: Log security event (dual-write to DB + file)
            Logger::security($level, $message, $context);

            $this->json([
                'success' => true,
                'message' => 'Security event logged successfully',
                'level' => $level,
                'scenario' => $scenario,
                'logged_message' => $message,
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
     * Get recent security events from database
     * ENTERPRISE GALAXY ULTIMATE: Fresh PDO bypassing ALL cache layers for real-time data
     * PERFORMANCE: Direct connection bypasses pool cache for guaranteed fresh data
     */
    public function getRecentEvents(): void
    {
        // ENTERPRISE TIPS: Disable HTTP caching for real-time data
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        try {
            $limit = min((int) ($_GET['limit'] ?? 20), 100);

            // ENTERPRISE NUCLEAR OPTION: Create completely fresh PDO connection bypassing ALL cache layers
            // This ensures we ALWAYS get real-time data from database, no stale cache
            // CRITICAL for test pages that need to show newly generated events immediately
            $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE', 'need2talk');
            $pdo = new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,  // Force real prepared statements (no query cache)
            ]);

            // Get total count
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM security_events');
            $total = (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0);

            // Get recent events
            $stmt = $pdo->prepare('
                SELECT id, channel, level, message, context, ip_address, user_agent,
                       user_id, session_id, created_at
                FROM security_events
                ORDER BY id DESC
                LIMIT ?
            ');
            $stmt->execute([$limit]);
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
                'level_counts' => $levelCounts,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
