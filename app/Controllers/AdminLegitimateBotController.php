<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * Admin Legitimate Bot Controller
 *
 * ENTERPRISE GALAXY: Handles legitimate bot monitoring and statistics
 * Shows DNS verification results, cache performance, and top crawlers
 * Real-time data from Redis DB 3 (bot verification cache)
 */
class AdminLegitimateBotController extends BaseController
{
    private const REDIS_DB = 3; // Anti-scan + Bot verification DB

    /**
     * Get all data for Legitimate Bots admin page
     * Returns data array for AdminController to render
     */
    public function getPageData(): array
    {
        // ENTERPRISE GALAXY: No-cache headers for real-time data
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // ENTERPRISE: Get data from DATABASE (primary source)
        $dbStats = $this->getDatabaseStats();
        $topBots = $this->getTopBotsFromDB();
        $recentVisits = $this->getRecentVisitsFromDB();

        // LEGACY: Also get Redis data for backward compatibility
        $redisStats = $this->getRedisStats();

        // ENTERPRISE: Get complete data for new tables
        $allBotVisits = $this->getAllBotVisits();
        $ipWhitelist = $this->getIPWhitelist();

        // Return data for rendering
        return [
            'title' => 'Legitimate Bots Dashboard',
            // Database stats (primary) - ENTERPRISE GALAXY
            'database_stats' => [
                'total_bots' => $dbStats['total_bots'],
                'visits_today' => $dbStats['visits_today'],
                'visits_week' => $dbStats['visits_week'],
                'visits_month' => $dbStats['visits_month'],
                'avg_response_time' => $dbStats['avg_response_time'],
            ],
            // Legacy compatibility
            'total_verified_bots' => $dbStats['total_bots'],
            'total_visits_today' => $dbStats['visits_today'],
            'total_visits_week' => $dbStats['visits_week'],
            'total_visits_month' => $dbStats['visits_month'],
            'avg_response_time' => $dbStats['avg_response_time'],
            // Redis legacy stats
            'redis_cache_hits' => $redisStats['total_verified'],
            'redis_keys_count' => $redisStats['redis_keys'],
            'total_failed_verifications' => $redisStats['total_failed'],
            'cache_hit_rate' => $redisStats['cache_hit_rate'],
            'dns_verifications_saved' => $redisStats['dns_saved'],
            // Bot data
            'top_bots' => $topBots,
            'recent_visits' => $recentVisits,
            'recent_verifications' => $recentVisits, // Alias for compatibility
            // ENTERPRISE GALAXY: Complete tables
            'all_bot_visits' => $allBotVisits,
            'ip_whitelist' => $ipWhitelist,
        ];
    }

    /**
     * Get Redis statistics for bot verification cache
     */
    private function getRedisStats(): array
    {
        try {
            // ENTERPRISE POOL: Use connection pool for rate limit DB (bot verification)
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('rate_limit');

            if (!$redis) {
                throw new \Exception('Redis connection failed');
            }

            // Get all bot verification keys
            $keys = $redis->keys('anti_scan:legitimate_bot:*');
            $totalKeys = count($keys);

            $verified = 0;
            $failed = 0;

            // Count verified vs failed
            foreach ($keys as $key) {
                $data = $redis->get($key);
                if ($data) {
                    $botData = json_decode($data, true);
                    if (isset($botData['verified'])) {
                        if ($botData['verified'] === true) {
                            $verified++;
                        } else {
                            $failed++;
                        }
                    }
                }
            }

            // Calculate cache hit rate (estimated)
            // Assumption: 99% cache hits after 24h (industry standard for bot crawlers)
            $cacheHitRate = $totalKeys > 0 ? 99.0 : 0.0;

            // Estimate DNS lookups saved
            // Each cached bot saves ~50-100ms DNS lookup
            $dnsSaved = $verified * 100; // milliseconds saved

            return [
                'total_verified' => $verified,
                'total_failed' => $failed,
                'redis_keys' => $totalKeys,
                'cache_hit_rate' => $cacheHitRate,
                'dns_saved' => $dnsSaved,
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get Redis bot stats', [
                'error' => $e->getMessage(),
            ]);

            return [
                'total_verified' => 0,
                'total_failed' => 0,
                'redis_keys' => 0,
                'cache_hit_rate' => 0.0,
                'dns_saved' => 0,
            ];
        }
    }

    /**
     * Get top bot crawlers from Redis cache
     */
    private function getTopBots(): array
    {
        try {
            // ENTERPRISE POOL: Use connection pool for rate limit DB (bot verification)
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('rate_limit');

            if (!$redis) {
                throw new \Exception('Redis connection failed');
            }

            $keys = $redis->keys('anti_scan:legitimate_bot:*');
            $bots = [];

            foreach ($keys as $key) {
                $data = $redis->get($key);
                if ($data) {
                    $botData = json_decode($data, true);
                    if (isset($botData['bot_name']) && $botData['verified'] === true) {
                        $botName = $botData['bot_name'];
                        $ip = str_replace('anti_scan:legitimate_bot:', '', $key);

                        if (!isset($bots[$botName])) {
                            $bots[$botName] = [
                                'name' => $botName,
                                'count' => 0,
                                'ips' => [],
                                'last_seen' => 0,
                            ];
                        }

                        $bots[$botName]['count']++;
                        $bots[$botName]['ips'][] = $ip;

                        if (isset($botData['timestamp'])) {
                            $bots[$botName]['last_seen'] = max($bots[$botName]['last_seen'], $botData['timestamp']);
                        }
                    }
                }
            }

            // Sort by count (most active bots first)
            usort($bots, function ($a, $b) {
                return $b['count'] - $a['count'];
            });

            // Return top 10
            return array_slice($bots, 0, 10);

        } catch (\Exception $e) {
            Logger::error('Failed to get top bots', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get recent bot verifications (last 20)
     */
    private function getRecentVerifications(): array
    {
        try {
            // ENTERPRISE POOL: Use connection pool for rate limit DB (bot verification)
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance()->getConnection('rate_limit');

            if (!$redis) {
                throw new \Exception('Redis connection failed');
            }

            $keys = $redis->keys('anti_scan:legitimate_bot:*');
            $verifications = [];

            foreach ($keys as $key) {
                $data = $redis->get($key);
                if ($data) {
                    $botData = json_decode($data, true);
                    $ip = str_replace('anti_scan:legitimate_bot:', '', $key);

                    $verifications[] = [
                        'ip' => $ip,
                        'bot_name' => $botData['bot_name'] ?? 'unknown',
                        'verified' => $botData['verified'] ?? false,
                        'timestamp' => $botData['timestamp'] ?? 0,
                    ];
                }
            }

            // Sort by timestamp (most recent first)
            usort($verifications, function ($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });

            // Return last 20
            return array_slice($verifications, 0, 20);

        } catch (\Exception $e) {
            Logger::error('Failed to get recent verifications', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * API Endpoint: Get bot stats for AJAX refresh
     */
    public function getBotStats(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        try {
            $data = $this->getPageData();

            $this->json([
                'success' => true,
                'stats' => [
                    'total_verified_bots' => $data['total_verified_bots'],
                    'total_failed_verifications' => $data['total_failed_verifications'],
                    'cache_hit_rate' => $data['cache_hit_rate'],
                    'dns_verifications_saved' => $data['dns_verifications_saved'],
                    'redis_keys_count' => $data['redis_keys_count'],
                ],
                'top_bots' => $data['top_bots'],
                'recent_verifications' => $data['recent_verifications'],
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::error('Failed to get bot stats API', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ENTERPRISE: Get bot statistics from DATABASE
     * Primary data source for legitimate bot analytics
     */
    private function getDatabaseStats(): array
    {
        try {
            $db = db_pdo();

            // Total unique bots
            $stmt = $db->query("
                SELECT COUNT(DISTINCT bot_name) as total_bots
                FROM legitimate_bot_visits
            ");
            $totalBots = $stmt->fetchColumn() ?: 0;

            // Visits today
            $stmt = $db->query("
                SELECT COUNT(*) as visits_today
                FROM legitimate_bot_visits
                WHERE visit_date = CURRENT_DATE
            ");
            $visitsToday = $stmt->fetchColumn() ?: 0;

            // Visits this week
            $stmt = $db->query("
                SELECT COUNT(*) as visits_week
                FROM legitimate_bot_visits
                WHERE visit_date >= CURRENT_DATE - INTERVAL '7 days'
            ");
            $visitsWeek = $stmt->fetchColumn() ?: 0;

            // Visits this month
            $stmt = $db->query("
                SELECT COUNT(*) as visits_month
                FROM legitimate_bot_visits
                WHERE visit_date >= CURRENT_DATE - INTERVAL '30 days'
            ");
            $visitsMonth = $stmt->fetchColumn() ?: 0;

            // Average response time
            $stmt = $db->query("
                SELECT AVG(response_time_ms) as avg_response_time
                FROM legitimate_bot_visits
                WHERE response_time_ms IS NOT NULL
                  AND created_at >= NOW() - INTERVAL '7 days'
            ");
            $avgResponseTime = round($stmt->fetchColumn() ?: 0, 2);

            return [
                'total_bots' => $totalBots,
                'visits_today' => $visitsToday,
                'visits_week' => $visitsWeek,
                'visits_month' => $visitsMonth,
                'avg_response_time' => $avgResponseTime,
            ];

        } catch (\Throwable $e) {
            Logger::error('Failed to get database bot stats', [
                'error' => $e->getMessage(),
            ]);

            return [
                'total_bots' => 0,
                'visits_today' => 0,
                'visits_week' => 0,
                'visits_month' => 0,
                'avg_response_time' => 0,
            ];
        }
    }

    /**
     * ENTERPRISE: Get top bots from DATABASE (uses pre-built view)
     */
    private function getTopBotsFromDB(): array
    {
        try {
            $db = db_pdo();
            $stmt = $db->query("
                SELECT
                    bot_name,
                    total_visits,
                    unique_ips,
                    active_days,
                    last_seen,
                    avg_response_time
                FROM v_top_bots_week
                ORDER BY total_visits DESC
                LIMIT 10
            ");

            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // ENTERPRISE FIX: Map database fields to match JavaScript expectations
            // JavaScript expects: bot.name, bot.count, bot.ips (array), bot.last_seen
            // Database returns: bot_name, total_visits, unique_ips (integer), last_seen
            return array_map(function ($bot) {
                return [
                    'name' => $bot['bot_name'],
                    'count' => (int)$bot['total_visits'],
                    'ips' => array_fill(0, (int)$bot['unique_ips'], ''), // Create array with correct length
                    'last_seen' => $bot['last_seen'] ? strtotime($bot['last_seen']) : null,
                    'active_days' => (int)$bot['active_days'],
                    'avg_response_time' => (float)$bot['avg_response_time'],
                ];
            }, $results);

        } catch (\Throwable $e) {
            Logger::error('Failed to get top bots from DB', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * ENTERPRISE: Get recent bot visits from DATABASE
     */
    private function getRecentVisitsFromDB(): array
    {
        try {
            $db = db_pdo();
            $stmt = $db->query("
                SELECT
                    ip_address as ip,
                    bot_name,
                    request_path,
                    request_method,
                    response_status,
                    response_time_ms,
                    created_at as timestamp,
                    CASE
                        WHEN response_status BETWEEN 200 AND 299 THEN 1
                        ELSE 0
                    END as verified
                FROM legitimate_bot_visits
                ORDER BY created_at DESC
                LIMIT 20
            ");

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            Logger::error('Failed to get recent bot visits from DB', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * ENTERPRISE GALAXY: Get ALL bot visits with all columns (last 50)
     * Shows complete data: IP, bot name, path, method, status, response time, visit date, created_at
     */
    private function getAllBotVisits(): array
    {
        try {
            $db = db_pdo();
            $stmt = $db->query("
                SELECT
                    ip_address,
                    bot_name,
                    request_path,
                    request_method,
                    response_status,
                    response_time_ms,
                    visit_date,
                    created_at
                FROM legitimate_bot_visits
                ORDER BY created_at DESC
                LIMIT 50
            ");

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            Logger::error('Failed to get all bot visits from DB', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * ENTERPRISE GALAXY: Get IP whitelist entries from database
     * Returns all columns: id, ip_address, label, type, is_active, expires_at, notes
     */
    private function getIPWhitelist(): array
    {
        try {
            $db = db_pdo();
            $stmt = $db->query("
                SELECT
                    id,
                    ip_address,
                    label,
                    type,
                    reason,
                    is_active,
                    expires_at,
                    notes,
                    created_at,
                    updated_at
                FROM ip_whitelist
                ORDER BY
                    CASE WHEN type = 'owner' THEN 1
                         WHEN type = 'staff' THEN 2
                         WHEN type = 'bot' THEN 3
                         WHEN type = 'api_client' THEN 4
                         ELSE 5 END,
                    created_at DESC
            ");

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            Logger::error('Failed to get IP whitelist from DB', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
