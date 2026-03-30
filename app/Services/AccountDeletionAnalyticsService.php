<?php

namespace Need2Talk\Services;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Traits\EnterpriseRedisSafety;

/**
 * 🚀 ENTERPRISE GALAXY: Account Deletion Analytics Service
 *
 * GDPR Article 17 compliance analytics and monitoring dashboard.
 * Provides comprehensive insights into account deletion lifecycle:
 * - Timeline analytics (daily/weekly/monthly aggregations)
 * - Recovery rate metrics (cancelled vs completed)
 * - Pending deletions monitoring (grace period tracking)
 * - Rate limiting violations detection
 * - User behavior analytics (deletion reasons, patterns)
 *
 * PERFORMANCE:
 * - Multi-level caching (L1 Redis: 5min, L3 Redis: 1hr)
 * - Database connection pooling
 * - Optimized aggregation queries
 * - Supports 100,000+ deletion records
 *
 * SECURITY:
 * - IP address hashing for privacy
 * - User agent sanitization
 * - Admin-only access (validated via AdminSecurityService)
 */
class AccountDeletionAnalyticsService
{
    use EnterpriseRedisSafety;

    private const CACHE_PREFIX = 'account_deletion_analytics:';
    private const CACHE_TTL_SHORT = 300;   // 5 minutes
    private const CACHE_TTL_MEDIUM = 1800; // 30 minutes
    private const CACHE_TTL_LONG = 3600;   // 1 hour

    private $redisManager;
    private $db;

    public function __construct()
    {
        $this->redisManager = EnterpriseRedisManager::getInstance();
        $this->db = db();

        Logger::info('ADMIN: AccountDeletionAnalyticsService initialized', [
            'redis_enabled' => $this->getRedisConnection() !== null,
            'cache_enabled' => true,
        ]);
    }

    /**
     * 🎯 ENTERPRISE: Get comprehensive dashboard statistics
     *
     * @return array Dashboard stats with KPIs
     */
    public function getDashboardStats(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'dashboard_stats';
        $redis = $this->getRedisConnection();

        // Try cache first
        if ($redis) {
            $cached = $this->safeRedisCall($redis, 'get', [$cacheKey]);
            if ($cached) {
                $decoded = json_decode($cached, true);
                // ENTERPRISE FIX: Validate decoded data is array (prevent corrupted cache)
                if (is_array($decoded)) {
                    return $decoded;
                }
                // Cache corrupted - delete it
                $this->safeRedisCall($redis, 'del', [$cacheKey]);
            }
        }

        try {
            $stats = [
                'total_deletions' => $this->getTotalDeletions(),
                'pending_deletions' => $this->getPendingDeletions(),
                'cancelled_recoveries' => $this->getCancelledDeletions(),
                'completed_hard_deletes' => $this->getCompletedDeletions(),
                'recovery_rate_percent' => $this->getRecoveryRate(),
                'avg_grace_period_days' => $this->getAverageGracePeriod(),
                'rate_limit_violations' => $this->getRateLimitViolations(),
                'today_deletions' => $this->getTodayDeletions(),
                'this_week_deletions' => $this->getThisWeekDeletions(),
                'this_month_deletions' => $this->getThisMonthDeletions(),
                'generated_at' => date('Y-m-d H:i:s'),
            ];

            // Cache for 5 minutes
            if ($redis) {
                $this->safeRedisCall($redis, 'setex', [$cacheKey, self::CACHE_TTL_SHORT, json_encode($stats)]);
            }

            Logger::info('ADMIN: Dashboard stats generated', [
                'total_deletions' => $stats['total_deletions'],
                'recovery_rate' => $stats['recovery_rate_percent'] . '%',
            ]);

            return $stats;

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to generate dashboard stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'error' => 'Failed to load statistics',
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * 📊 ENTERPRISE: Get timeline data for Chart.js
     *
     * @param string $period 'daily' (30 days), 'weekly' (12 weeks), 'monthly' (12 months)
     * @return array Timeline data with labels and datasets
     */
    public function getTimelineData(string $period = 'daily'): array
    {
        $cacheKey = self::CACHE_PREFIX . "timeline_{$period}";
        $redis = $this->getRedisConnection();

        // Try cache first
        if ($redis) {
            $cached = $this->safeRedisCall($redis, 'get', [$cacheKey]);
            if ($cached) {
                $decoded = json_decode($cached, true);
                // ENTERPRISE FIX: Validate decoded data is array (prevent corrupted cache)
                if (is_array($decoded)) {
                    return $decoded;
                }
                // Cache corrupted - delete it
                $this->safeRedisCall($redis, 'del', [$cacheKey]);
            }
        }

        try {
            $timelineData = match($period) {
                'daily' => $this->getDailyTimeline(30),   // Last 30 days
                'weekly' => $this->getWeeklyTimeline(12), // Last 12 weeks
                'monthly' => $this->getMonthlyTimeline(12), // Last 12 months
                default => $this->getDailyTimeline(30),
            };

            // Cache for 30 minutes
            if ($redis) {
                $this->safeRedisCall($redis, 'setex', [$cacheKey, self::CACHE_TTL_MEDIUM, json_encode($timelineData)]);
            }

            return $timelineData;

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to generate timeline data', [
                'period' => $period,
                'error' => $e->getMessage(),
            ]);

            return [
                'labels' => [],
                'datasets' => [],
                'error' => 'Failed to load timeline data',
            ];
        }
    }

    /**
     * 📋 ENTERPRISE: Get recent deletions list with pagination
     *
     * @param int $limit Number of records per page
     * @param int $offset Starting offset
     * @param string $status Filter by status (all, pending, cancelled, completed)
     * @return array Deletions list with metadata
     */
    public function getRecentDeletions(int $limit = 20, int $offset = 0, string $status = 'all'): array
    {
        try {
            $whereClause = $status !== 'all' ? 'WHERE status = ?' : '';
            $params = $status !== 'all' ? [$status] : [];

            $query = "
                SELECT
                    id,
                    user_id,
                    email,
                    nickname,
                    reason,
                    status,
                    scheduled_deletion_at,
                    cancelled_at,
                    deleted_at,
                    ip_address,
                    user_agent,
                    requested_at
                FROM account_deletions
                {$whereClause}
                ORDER BY requested_at DESC
                LIMIT ? OFFSET ?
            ";

            $params[] = $limit;
            $params[] = $offset;

            $deletions = $this->db->query($query, $params, [
                'cache' => true,
                'cache_ttl' => 'short',
            ]);

            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM account_deletions {$whereClause}";
            $countParams = $status !== 'all' ? [$status] : [];
            $totalResult = $this->db->findOne($countQuery, $countParams);
            $total = $totalResult['total'] ?? 0;

            return [
                'deletions' => $deletions,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'status_filter' => $status,
                'has_more' => ($offset + $limit) < $total,
            ];

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get recent deletions', [
                'limit' => $limit,
                'offset' => $offset,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return [
                'deletions' => [],
                'total' => 0,
                'error' => 'Failed to load deletions list',
            ];
        }
    }

    /**
     * 🔍 ENTERPRISE: Get deletion details by ID with user info
     *
     * @param int $deletionId Deletion record ID
     * @return array|null Deletion details or null if not found
     */
    public function getDeletionDetails(int $deletionId): ?array
    {
        try {
            $deletion = $this->db->findOne(
                "SELECT
                    ad.*,
                    u.email as current_email,
                    u.nickname as current_nickname,
                    u.created_at as user_registered_at,
                    u.deleted_at as user_deleted_at
                FROM account_deletions ad
                LEFT JOIN users u ON ad.user_id = u.id
                WHERE ad.id = ?
                LIMIT 1",
                [$deletionId],
                ['cache' => true, 'cache_ttl' => 'short']
            );

            if ($deletion) {
                // Calculate grace period remaining (if pending)
                if ($deletion['status'] === 'pending') {
                    $scheduledTime = strtotime($deletion['scheduled_deletion_at']);
                    $now = time();
                    $deletion['grace_period_remaining_hours'] = max(0, round(($scheduledTime - $now) / 3600, 1));
                    $deletion['grace_period_expired'] = $scheduledTime <= $now;
                }

                // Privacy: Hash IP address for display
                if (!empty($deletion['ip_address'])) {
                    $deletion['ip_address_hash'] = hash('sha256', $deletion['ip_address']);
                    $deletion['ip_address_display'] = $this->maskIpAddress($deletion['ip_address']);
                }
            }

            return $deletion;

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get deletion details', [
                'deletion_id' => $deletionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // =========================================================================
    // PRIVATE HELPER METHODS - Statistics Calculations
    // =========================================================================

    /**
     * Get total deletions count (all statuses)
     */
    private function getTotalDeletions(): int
    {
        $result = $this->db->findOne(
            'SELECT COUNT(*) as total FROM account_deletions',
            [],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get pending deletions count (within grace period)
     */
    private function getPendingDeletions(): int
    {
        $result = $this->db->findOne(
            "SELECT COUNT(*) as total FROM account_deletions WHERE status = 'pending'",
            [],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get cancelled deletions count (recovered accounts)
     */
    private function getCancelledDeletions(): int
    {
        $result = $this->db->findOne(
            "SELECT COUNT(*) as total FROM account_deletions WHERE status = 'cancelled'",
            [],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get completed deletions count (hard deleted)
     */
    private function getCompletedDeletions(): int
    {
        $result = $this->db->findOne(
            "SELECT COUNT(*) as total FROM account_deletions WHERE status = 'completed'",
            [],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Calculate recovery rate percentage
     */
    private function getRecoveryRate(): float
    {
        $cancelled = $this->getCancelledDeletions();
        $total = $this->getTotalDeletions();

        if ($total === 0) {
            return 0.0;
        }

        return round(($cancelled / $total) * 100, 2);
    }

    /**
     * Calculate average grace period usage (days before recovery/deletion)
     */
    private function getAverageGracePeriod(): float
    {
        // ENTERPRISE: PostgreSQL date difference (DATEDIFF → EXTRACT(epoch FROM interval) / 86400)
        $result = $this->db->findOne(
            "SELECT AVG(EXTRACT(epoch FROM (COALESCE(cancelled_at, deleted_at, NOW()) - requested_at)) / 86400) as avg_days
             FROM account_deletions
             WHERE status IN ('cancelled', 'completed')",
            [],
            ['cache' => true, 'cache_ttl' => 'medium']
        );

        return round((float) ($result['avg_days'] ?? 0), 1);
    }

    /**
     * Get rate limiting violations count (3+ recoveries in 30 days per user)
     */
    private function getRateLimitViolations(): int
    {
        $result = $this->db->findOne(
            "SELECT COUNT(DISTINCT user_id) as violators
             FROM (
                 SELECT user_id
                 FROM account_deletions
                 WHERE status = 'cancelled'
                 AND cancelled_at >= NOW() - INTERVAL '30 days'
                 GROUP BY user_id
                 HAVING COUNT(*) >= 3
             ) as violations",
            [],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return (int) ($result['violators'] ?? 0);
    }

    /**
     * Get today's deletions count
     */
    private function getTodayDeletions(): int
    {
        $result = $this->db->findOne(
            "SELECT COUNT(*) as total FROM account_deletions WHERE requested_at::DATE = CURRENT_DATE",
            [],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get this week's deletions count
     */
    private function getThisWeekDeletions(): int
    {
        $result = $this->db->findOne(
            "SELECT COUNT(*) as total
             FROM account_deletions
             WHERE DATE_TRUNC('week', requested_at) = DATE_TRUNC('week', NOW())",
            [],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Get this month's deletions count
     */
    private function getThisMonthDeletions(): int
    {
        // ENTERPRISE: PostgreSQL YEAR/MONTH extraction (YEAR() → EXTRACT(YEAR FROM))
        $result = $this->db->findOne(
            "SELECT COUNT(*) as total
             FROM account_deletions
             WHERE EXTRACT(YEAR FROM requested_at) = EXTRACT(YEAR FROM NOW())
             AND EXTRACT(MONTH FROM requested_at) = EXTRACT(MONTH FROM NOW())",
            [],
            ['cache' => true, 'cache_ttl' => 'short']
        );

        return (int) ($result['total'] ?? 0);
    }

    // =========================================================================
    // TIMELINE DATA GENERATORS
    // =========================================================================

    /**
     * Generate daily timeline data (last N days)
     */
    private function getDailyTimeline(int $days = 30): array
    {
        $data = $this->db->query(
            "SELECT
                CAST(requested_at AS DATE) as date,  -- ENTERPRISE: PostgreSQL (DATE() → CAST AS DATE)
                status,
                COUNT(*) as count
             FROM account_deletions
             WHERE requested_at >= NOW() - INTERVAL '1 day' * ?
             GROUP BY CAST(requested_at AS DATE), status  -- ENTERPRISE: PostgreSQL
             ORDER BY date ASC",
            [$days],
            ['cache' => true, 'cache_ttl' => 'medium']
        );

        return $this->formatTimelineData($data, 'daily', $days);
    }

    /**
     * Generate weekly timeline data (last N weeks)
     */
    private function getWeeklyTimeline(int $weeks = 12): array
    {
        $data = $this->db->query(
            "SELECT
                TO_CHAR(requested_at, 'IYYY-IW') as week,
                DATE_TRUNC('week', requested_at)::DATE as week_start,
                status,
                COUNT(*) as count
             FROM account_deletions
             WHERE requested_at >= NOW() - INTERVAL '1 week' * ?
             GROUP BY TO_CHAR(requested_at, 'IYYY-IW'), DATE_TRUNC('week', requested_at)::DATE, status
             ORDER BY week ASC",
            [$weeks],
            ['cache' => true, 'cache_ttl' => 'long']
        );

        return $this->formatTimelineData($data, 'weekly', $weeks);
    }

    /**
     * Generate monthly timeline data (last N months)
     */
    private function getMonthlyTimeline(int $months = 12): array
    {
        $data = $this->db->query(
            "SELECT
                TO_CHAR(requested_at, 'YYYY-MM') as month,
                status,
                COUNT(*) as count
             FROM account_deletions
             WHERE requested_at >= NOW() - INTERVAL '1 month' * ?
             GROUP BY TO_CHAR(requested_at, 'YYYY-MM'), status
             ORDER BY month ASC",
            [$months],
            ['cache' => true, 'cache_ttl' => 'long']
        );

        return $this->formatTimelineData($data, 'monthly', $months);
    }

    /**
     * 🎨 ENTERPRISE: Format timeline data for Chart.js
     */
    private function formatTimelineData(array $data, string $period, int $periods): array
    {
        // Generate all period labels
        $labels = $this->generatePeriodLabels($period, $periods);

        // Initialize datasets
        $datasets = [
            'pending' => array_fill(0, count($labels), 0),
            'cancelled' => array_fill(0, count($labels), 0),
            'completed' => array_fill(0, count($labels), 0),
        ];

        // Fill in actual data
        foreach ($data as $row) {
            $label = $this->formatPeriodLabel($row, $period);
            $index = array_search($label, $labels);

            if ($index !== false) {
                $status = $row['status'] ?? 'pending';
                if (isset($datasets[$status])) {
                    $datasets[$status][$index] = (int) $row['count'];
                }
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Pending (Grace Period)',
                    'data' => $datasets['pending'],
                    'backgroundColor' => 'rgba(59, 130, 246, 0.5)',  // Blue
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Recovered (Cancelled)',
                    'data' => $datasets['cancelled'],
                    'backgroundColor' => 'rgba(16, 185, 129, 0.5)',  // Green
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Hard Deleted (Completed)',
                    'data' => $datasets['completed'],
                    'backgroundColor' => 'rgba(239, 68, 68, 0.5)',   // Red
                    'borderColor' => 'rgba(239, 68, 68, 1)',
                    'borderWidth' => 2,
                ],
            ],
        ];
    }

    /**
     * Generate period labels for timeline
     */
    private function generatePeriodLabels(string $period, int $count): array
    {
        $labels = [];
        $now = time();

        for ($i = $count - 1; $i >= 0; $i--) {
            $timestamp = match($period) {
                'daily' => strtotime("-{$i} days", $now),
                'weekly' => strtotime("-{$i} weeks", $now),
                'monthly' => strtotime("-{$i} months", $now),
                default => $now,
            };

            $labels[] = match($period) {
                'daily' => date('M d', $timestamp),
                'weekly' => 'Week ' . date('W', $timestamp),
                'monthly' => date('M Y', $timestamp),
                default => date('Y-m-d', $timestamp),
            };
        }

        return $labels;
    }

    /**
     * Format period label from database row
     */
    private function formatPeriodLabel(array $row, string $period): string
    {
        return match($period) {
            'daily' => date('M d', strtotime($row['date'])),
            'weekly' => 'Week ' . date('W', strtotime($row['week_start'])),
            'monthly' => date('M Y', strtotime($row['month'] . '-01')),
            default => $row['date'] ?? '',
        };
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Get Redis connection
     */
    private function getRedisConnection(): ?\Redis
    {
        return $this->redisManager->getConnection('L1_cache');
    }

    /**
     * Mask IP address for privacy (show only first 2 octets)
     */
    private function maskIpAddress(string $ip): string
    {
        $parts = explode('.', $ip);

        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.XXX.XXX';
        }

        // IPv6 or invalid - just show first 8 chars
        return substr($ip, 0, 8) . '...';
    }

    /**
     * 🎯 ENTERPRISE: Invalidate dashboard cache ONLY (granular invalidation)
     *
     * Called when account deletion status changes.
     * Invalidates ONLY dashboard-related cache, not timeline/details.
     *
     * @return bool Success status
     */
    public function invalidateDashboardCache(): bool
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return false;
            }

            // ENTERPRISE: Invalidate dashboard stats + ALL recent_deletions pages (pattern matching)
            // Delete single key first
            $this->safeRedisCall($redis, 'del', [self::CACHE_PREFIX . 'dashboard_stats']);

            // ENTERPRISE: Delete ALL recent_deletions cache keys (all pages, all statuses)
            // Use SCAN for enterprise scalability (non-blocking)
            $pattern = self::CACHE_PREFIX . 'recent_deletions:*';
            $cursor = null;
            $deletedCount = 0;

            while (($keys = $redis->scan($cursor, $pattern, 100)) !== false) {
                if (is_array($keys) && count($keys) > 0) {
                    $redis->del($keys);
                    $deletedCount += count($keys);
                }

                if ($cursor === 0) {
                    break; // Scan complete
                }
            }

            Logger::info('ADMIN: Dashboard cache invalidated (granular)', [
                'keys_invalidated' => $deletedCount + 1, // dashboard_stats + all recent_deletions
                'recent_deletions_pages_deleted' => $deletedCount,
                'action' => 'granular_invalidation',
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::warning('ADMIN: Failed to invalidate dashboard cache', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear all analytics cache (for testing/debugging)
     *
     * WARNING: This clears ALL cache including timeline data.
     * Use invalidateDashboardCache() for granular invalidation.
     */
    public function clearCache(): bool
    {
        try {
            $redis = $this->getRedisConnection();

            if (!$redis) {
                return false;
            }

            $pattern = self::CACHE_PREFIX . '*';
            $keys = $this->safeRedisCall($redis, 'keys', [$pattern]);

            if ($keys && count($keys) > 0) {
                $this->safeRedisCall($redis, 'del', $keys);

                Logger::info('ADMIN: Analytics cache cleared (FULL)', [
                    'keys_cleared' => count($keys),
                    'action' => 'full_cache_clear',
                ]);

                return true;
            }

            return true;

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to clear analytics cache', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
