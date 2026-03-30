<?php

declare(strict_types=1);

namespace Need2Talk\Services\Chat;

use Need2Talk\Contracts\Database\DatabaseAdapterInterface;
use Need2Talk\Contracts\Redis\RedisAdapterInterface;
use Need2Talk\Services\Logger;

/**
 * ChatModerationService - Content moderation for chat
 *
 * Features:
 * - Keyword blacklist filtering (with fuzzy matching)
 * - Message reports management
 * - Escrow key release for E2E moderation
 * - Admin moderation queue
 *
 * ENTERPRISE DI: This service uses constructor injection for Database and Redis.
 * Use ChatServiceFactory::createModerationService() to instantiate.
 *
 * NOTE: In Swoole WebSocket context, database is NOT available.
 * Only checkContent() works in Swoole (uses cached keywords from Redis).
 *
 * @package Need2Talk\Services\Chat
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */
class ChatModerationService
{
    /**
     * Cache configuration
     */
    private const CACHE_KEY = 'chat:moderation:blacklist:v1';
    private const CACHE_TTL = 300;  // 5 minutes

    /**
     * Severity thresholds
     */
    private const SEVERITY_LOG_ONLY = 1;
    private const SEVERITY_WARN = 2;
    private const SEVERITY_FILTER = 3;
    private const SEVERITY_BLOCK = 4;
    private const SEVERITY_CRITICAL = 5;

    /**
     * Auto-escalation threshold
     */
    private const AUTO_ESCALATE_REPORT_COUNT = 3;

    /**
     * Cached keywords
     */
    private static ?array $keywordsCache = null;

    /**
     * Leet speak mapping for fuzzy matching
     */
    private const LEET_MAP = [
        '4' => 'a', '@' => 'a',
        '3' => 'e',
        '1' => 'i', '!' => 'i',
        '0' => 'o',
        '5' => 's', '$' => 's',
        '7' => 't', '+' => 't',
        '8' => 'b',
    ];

    private ?DatabaseAdapterInterface $database;
    private ?RedisAdapterInterface $redis;

    /**
     * Constructor with dependency injection
     *
     * @param DatabaseAdapterInterface|null $database Database adapter (null in Swoole context)
     * @param RedisAdapterInterface|null $redis Redis adapter (for keyword caching)
     */
    public function __construct(
        ?DatabaseAdapterInterface $database = null,
        ?RedisAdapterInterface $redis = null
    ) {
        $this->database = $database;
        $this->redis = $redis;
    }

    /**
     * Check message content against blacklist
     *
     * @param string $text Message content
     * @param bool $isRoom Is this for a room (not DM)?
     * @return array [
     *     'allowed' => bool,      // Can message be sent?
     *     'filtered' => string,   // Filtered content (asterisks)
     *     'action' => string,     // 'allow', 'warn', 'filter', 'block'
     *     'severity' => int,      // Highest matched severity
     *     'matches' => array,     // Matched keywords
     * ]
     */
    public function checkContent(string $text, bool $isRoom = true): array
    {
        $keywords = $this->loadKeywords();
        $normalizedText = $this->normalizeText($text);

        $matches = [];
        $highestSeverity = 0;
        $action = 'allow';
        $filteredText = $text;

        foreach ($keywords as $keyword) {
            // Skip if doesn't apply to context
            if ($isRoom && !$keyword['applies_to_rooms']) {
                continue;
            }
            if (!$isRoom && !$keyword['applies_to_dm']) {
                continue;
            }
            if (!$keyword['is_active']) {
                continue;
            }

            $matched = $this->matchKeyword($normalizedText, $keyword);

            if ($matched) {
                $matches[] = [
                    'keyword_id' => $keyword['id'],
                    'keyword' => $keyword['keyword'],
                    'category' => $keyword['category'],
                    'severity' => $keyword['severity'],
                    'action' => $keyword['action_type'],
                ];

                // Track highest severity
                if ($keyword['severity'] > $highestSeverity) {
                    $highestSeverity = $keyword['severity'];
                    $action = $keyword['action_type'];
                }

                // Apply filter to content
                $filteredText = $this->filterWord($filteredText, $keyword['keyword']);
            }
        }

        return [
            'allowed' => $action !== 'block',
            'filtered' => $filteredText,
            'action' => $action,
            'severity' => $highestSeverity,
            'matches' => $matches,
            'is_blocked' => $action === 'block',
            'is_warned' => $action === 'warn',
            'is_filtered' => $action === 'filter' || $action === 'shadow_hide',
        ];
    }

    /**
     * Get database adapter (with fallback to db() helper)
     */
    private function getDatabase(): ?\Need2Talk\Core\Database
    {
        if ($this->database !== null) {
            // Use injected adapter (would need to be proper Database instance)
            // For now, fall through to helper
        }

        // Fallback to global db() helper if available
        if (function_exists('db')) {
            return db();
        }

        return null;
    }

    /**
     * Report a message
     *
     * NOTE: Only works in PHP-FPM context (requires database)
     *
     * @param string $messageUuid
     * @param int $reporterId
     * @param string $reporterUuid
     * @param string $reportType harassment|spam|inappropriate|hate_speech|self_harm|other
     * @param string|null $reason Optional description
     * @param int|null $conversationId For DM messages
     * @return array ['success' => bool, 'report_id' => int|null, 'error' => string|null]
     */
    public function reportMessage(
        string $messageUuid,
        int $reporterId,
        string $reporterUuid,
        string $reportType,
        ?string $reason = null,
        ?int $conversationId = null
    ): array {
        $db = $this->getDatabase();

        if (!$db) {
            return [
                'success' => false,
                'report_id' => null,
                'error' => 'no_database',
                'message' => 'Database non disponibile',
            ];
        }

        try {
            // Check for duplicate report
            $existing = $db->findOne(
                "SELECT id FROM chat_message_reports
                 WHERE message_uuid = ? AND reporter_id = ?",
                [$messageUuid, $reporterId]
            );

            if ($existing) {
                return [
                    'success' => false,
                    'report_id' => null,
                    'error' => 'already_reported',
                    'message' => 'Hai già segnalato questo messaggio',
                ];
            }

            $db->beginTransaction();

            // Create report
            $db->execute(
                "INSERT INTO chat_message_reports
                 (message_uuid, reporter_id, reporter_uuid, report_type,
                  report_reason, conversation_id, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())",
                [
                    $messageUuid,
                    $reporterId,
                    $reporterUuid,
                    $reportType,
                    $reason,
                    $conversationId,
                ]
            );

            $reportId = $db->lastInsertId();

            // ENTERPRISE V11.6: DM moderation removed - reports tracked only in chat_message_reports table
            // Direct messages are E2E encrypted and cannot be moderated server-side

            $db->commit();

            Logger::security('info', 'Message reported', [
                'message_uuid' => $messageUuid,
                'reporter_id' => $reporterId,
                'report_type' => $reportType,
            ]);

            return [
                'success' => true,
                'report_id' => (int) $reportId,
                'error' => null,
                'message' => 'Segnalazione ricevuta',
            ];

        } catch (\Exception $e) {
            $db->rollback();
            Logger::error('Failed to create report', [
                'message_uuid' => $messageUuid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'report_id' => null,
                'error' => 'database_error',
                'message' => 'Errore durante la segnalazione',
            ];
        }
    }

    /**
     * Release escrow key for message decryption (Admin only)
     *
     * @param int $reportId
     * @param int $adminId
     * @param string $reason
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function releaseEscrowKey(int $reportId, int $adminId, string $reason): array
    {
        $db = $this->getDatabase();

        if (!$db) {
            return [
                'success' => false,
                'error' => 'no_database',
                'message' => 'Database non disponibile',
            ];
        }

        try {
            // Verify admin has permission
            $admin = $db->findOne(
                "SELECT id, role FROM users WHERE id = ? AND role IN ('admin', 'moderator')",
                [$adminId]
            );

            if (!$admin) {
                return [
                    'success' => false,
                    'error' => 'unauthorized',
                    'message' => 'Non autorizzato',
                ];
            }

            // Get report
            $report = $db->findOne(
                "SELECT * FROM chat_message_reports WHERE id = ?",
                [$reportId]
            );

            if (!$report) {
                return [
                    'success' => false,
                    'error' => 'not_found',
                    'message' => 'Segnalazione non trovata',
                ];
            }

            if ($report['escrow_key_released']) {
                return [
                    'success' => false,
                    'error' => 'already_released',
                    'message' => 'Chiave escrow già rilasciata',
                ];
            }

            // Release escrow key
            $db->execute(
                "UPDATE chat_message_reports
                 SET escrow_key_released = TRUE,
                     escrow_release_reason = ?,
                     escrow_released_by = ?,
                     escrow_released_at = NOW(),
                     status = 'reviewing',
                     updated_at = NOW()
                 WHERE id = ?",
                [$reason, $adminId, $reportId]
            );

            Logger::security('warning', 'Escrow key released for moderation', [
                'report_id' => $reportId,
                'message_uuid' => $report['message_uuid'],
                'admin_id' => $adminId,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'error' => null,
                'message' => 'Chiave escrow rilasciata',
                'conversation_id' => $report['conversation_id'],
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to release escrow key', [
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'database_error',
                'message' => 'Errore durante il rilascio',
            ];
        }
    }

    /**
     * Get pending moderation queue
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getModerationQueue(int $limit = 50, int $offset = 0): array
    {
        $db = $this->getDatabase();

        if (!$db) {
            return [
                'reports' => [],
                'total' => 0,
                'has_more' => false,
            ];
        }

        try {
            $reports = $db->query(
                "SELECT r.*,
                        u.nickname as reporter_nickname,
                        u.uuid as reporter_uuid_display
                 FROM chat_message_reports r
                 JOIN users u ON u.id = r.reporter_id
                 WHERE r.status IN ('pending', 'escalated')
                 ORDER BY
                     CASE r.status WHEN 'escalated' THEN 0 ELSE 1 END,
                     r.created_at ASC
                 LIMIT ? OFFSET ?",
                [$limit, $offset]
            );

            $totalCount = $db->count(
                'chat_message_reports',
                "status IN ('pending', 'escalated')"
            );

            return [
                'reports' => $reports,
                'total' => $totalCount,
                'has_more' => ($offset + count($reports)) < $totalCount,
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get moderation queue', [
                'error' => $e->getMessage(),
            ]);
            return [
                'reports' => [],
                'total' => 0,
                'has_more' => false,
            ];
        }
    }

    /**
     * Resolve a report
     *
     * @param int $reportId
     * @param int $adminId
     * @param string $status resolved|dismissed
     * @param string $actionTaken warning|message_hidden|user_banned|none
     * @param string|null $notes
     * @return bool
     */
    public function resolveReport(
        int $reportId,
        int $adminId,
        string $status,
        string $actionTaken,
        ?string $notes = null
    ): bool {
        $db = $this->getDatabase();

        if (!$db) {
            return false;
        }

        try {
            $db->execute(
                "UPDATE chat_message_reports
                 SET status = ?,
                     action_taken = ?,
                     resolution_notes = ?,
                     reviewed_by = ?,
                     reviewed_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?",
                [$status, $actionTaken, $notes, $adminId, $reportId]
            );

            Logger::security('info', 'Report resolved', [
                'report_id' => $reportId,
                'admin_id' => $adminId,
                'status' => $status,
                'action' => $actionTaken,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('Failed to resolve report', [
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Add keyword to blacklist
     *
     * @param string $keyword
     * @param int $severity 1-5
     * @param string $category
     * @param string $actionType block|warn|filter|shadow_hide
     * @param string $matchType exact|contains|regex|fuzzy
     * @param int|null $adminId
     * @return bool
     */
    public function addKeyword(
        string $keyword,
        int $severity = 3,
        string $category = 'offensive',
        string $actionType = 'block',
        string $matchType = 'contains',
        ?int $adminId = null
    ): bool {
        $db = $this->getDatabase();

        if (!$db) {
            return false;
        }

        try {
            $db->execute(
                "INSERT INTO keyword_blacklist
                 (keyword, severity, category, action_type, match_type,
                  applies_to_rooms, created_by, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, TRUE, ?, TRUE, NOW())
                 ON CONFLICT (keyword_normalized) DO UPDATE SET
                     severity = EXCLUDED.severity,
                     category = EXCLUDED.category,
                     action_type = EXCLUDED.action_type,
                     match_type = EXCLUDED.match_type,
                     is_active = TRUE,
                     updated_at = NOW()",
                [$keyword, $severity, $category, $actionType, $matchType, $adminId]
            );

            // Invalidate cache
            $this->invalidateCache();

            Logger::info('Keyword added to blacklist', [
                'keyword' => $keyword,
                'severity' => $severity,
                'admin_id' => $adminId,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::error('Failed to add keyword', [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove keyword from blacklist
     *
     * @param int $keywordId
     * @return bool
     */
    public function removeKeyword(int $keywordId): bool
    {
        $db = $this->getDatabase();

        if (!$db) {
            return false;
        }

        try {
            $db->execute(
                "UPDATE keyword_blacklist SET is_active = FALSE, updated_at = NOW() WHERE id = ?",
                [$keywordId]
            );

            $this->invalidateCache();

            return true;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all keywords (for admin)
     *
     * @param bool $activeOnly
     * @return array
     */
    public function getKeywords(bool $activeOnly = true): array
    {
        $db = $this->getDatabase();

        if (!$db) {
            return [];
        }

        $where = $activeOnly ? 'is_active = TRUE' : '1=1';

        return $db->query(
            "SELECT * FROM keyword_blacklist WHERE {$where} ORDER BY severity DESC, keyword ASC",
            [],
            ['cache' => false]
        );
    }

    /**
     * Load keywords from cache/DB
     *
     * @return array
     */
    private function loadKeywords(): array
    {
        // Check static cache first
        if (self::$keywordsCache !== null) {
            return self::$keywordsCache;
        }

        // ENTERPRISE V4.9: Try multiple Redis cache locations
        // Keywords are cached to enable Swoole WebSocket server to access them
        // without direct database connection
        $cached = null;

        // Try 1: Injected Redis adapter (Swoole context uses SwooleCoroutineRedisAdapter)
        if ($this->redis !== null) {
            $cached = $this->redis->get(self::CACHE_KEY, 'L1_cache');
            if ($cached) {
                $cached = json_decode($cached, true);
            }
        }

        // Try 2: cache() helper (PHP-FPM context)
        if (($cached === null || $cached === false) && function_exists('cache')) {
            $cache = cache();
            if ($cache) {
                $cached = $cache->get(self::CACHE_KEY);
            }
        }

        // Try 3: Direct Redis connection (fallback)
        if (($cached === null || $cached === false) && function_exists('get_env')) {
            try {
                $redisHost = get_env('REDIS_HOST') ?: 'redis';
                $redisPort = (int) (get_env('REDIS_PORT') ?: 6379);
                $redisPassword = get_env('REDIS_PASSWORD');

                $directRedis = new \Redis();
                $directRedis->connect($redisHost, $redisPort);
                if ($redisPassword) {
                    $directRedis->auth($redisPassword);
                }
                $directRedis->select(1); // L1 cache DB

                $cached = $directRedis->get(self::CACHE_KEY);
                if ($cached) {
                    $cached = json_decode($cached, true);
                }
                $directRedis->close();
            } catch (\Exception $e) {
                // Continue without direct Redis
            }
        }

        if ($cached !== null && $cached !== false && is_array($cached)) {
            self::$keywordsCache = $cached;
            return self::$keywordsCache;
        }

        // Load from database
        $db = $this->getDatabase();

        if (!$db) {
            // In Swoole context without DB, return empty (no moderation)
            // ENTERPRISE: Log warning for visibility
            if (function_exists('error_log')) {
                error_log('[ChatModeration] WARNING: No keywords available - database not accessible and cache empty');
            }
            return [];
        }

        try {
            self::$keywordsCache = $db->query(
                "SELECT id, keyword, match_type, regex_pattern, severity, category,
                        action_type, applies_to_rooms, applies_to_dm, is_active
                 FROM keyword_blacklist
                 WHERE is_active = TRUE
                 ORDER BY severity DESC",
                [],
                ['cache' => false]
            );

            // ENTERPRISE V4.9: Cache to MULTIPLE locations for cross-context access
            // This ensures both PHP-FPM and Swoole WebSocket can access keywords
            $serialized = json_encode(self::$keywordsCache);

            // Cache via injected adapter
            if ($this->redis !== null) {
                $this->redis->setex(self::CACHE_KEY, self::CACHE_TTL, $serialized, 'L1_cache');
            }

            // Cache via cache() helper
            if (function_exists('cache')) {
                $cache = cache();
                if ($cache) {
                    $cache->set(self::CACHE_KEY, self::$keywordsCache, self::CACHE_TTL);
                }
            }

            // Cache via direct Redis (ensures Swoole can read it)
            if (function_exists('get_env')) {
                try {
                    $redisHost = get_env('REDIS_HOST') ?: 'redis';
                    $redisPort = (int) (get_env('REDIS_PORT') ?: 6379);
                    $redisPassword = get_env('REDIS_PASSWORD');

                    $directRedis = new \Redis();
                    $directRedis->connect($redisHost, $redisPort);
                    if ($redisPassword) {
                        $directRedis->auth($redisPassword);
                    }
                    $directRedis->select(1); // L1 cache DB
                    $directRedis->setex(self::CACHE_KEY, self::CACHE_TTL, $serialized);
                    $directRedis->close();
                } catch (\Exception $e) {
                    // Non-critical: other cache locations may work
                }
            }

            return self::$keywordsCache;

        } catch (\Exception $e) {
            Logger::error('Failed to load keywords', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ENTERPRISE V4.9: Warmup keywords cache
     *
     * Call this from bootstrap or cron to ensure keywords are cached
     * before Swoole WebSocket server needs them.
     *
     * @return int Number of keywords loaded
     */
    public static function warmupCache(): int
    {
        $service = new self(null, null);
        $keywords = $service->loadKeywords();
        return count($keywords);
    }

    /**
     * Match keyword against text
     *
     * @param string $text Normalized text
     * @param array $keyword Keyword config
     * @return bool
     */
    private function matchKeyword(string $text, array $keyword): bool
    {
        $keywordLower = strtolower($keyword['keyword']);

        return match ($keyword['match_type']) {
            'exact' => $this->matchExact($text, $keywordLower),
            'contains' => $this->matchContains($text, $keywordLower),
            'regex' => $this->matchRegex($text, $keyword['regex_pattern']),
            'fuzzy' => $this->matchFuzzy($text, $keywordLower),
            default => $this->matchContains($text, $keywordLower),
        };
    }

    /**
     * Exact word match
     */
    private function matchExact(string $text, string $keyword): bool
    {
        $words = preg_split('/\s+/', $text);
        return in_array($keyword, $words, true);
    }

    /**
     * Contains match
     */
    private function matchContains(string $text, string $keyword): bool
    {
        return str_contains($text, $keyword);
    }

    /**
     * Regex match
     */
    private function matchRegex(string $text, ?string $pattern): bool
    {
        if (!$pattern) {
            return false;
        }

        return (bool) preg_match($pattern, $text);
    }

    /**
     * Fuzzy match (Levenshtein distance <= 2)
     */
    private function matchFuzzy(string $text, string $keyword): bool
    {
        $words = preg_split('/\s+/', $text);

        foreach ($words as $word) {
            if (strlen($word) >= strlen($keyword) - 2 && strlen($word) <= strlen($keyword) + 2) {
                if (levenshtein($word, $keyword) <= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalize text for matching
     * - Lowercase
     * - Remove leet speak
     * - Remove repeated chars
     */
    private function normalizeText(string $text): string
    {
        $text = strtolower($text);

        // Replace leet speak
        $text = strtr($text, self::LEET_MAP);

        // Remove repeated characters (e.g., "caazzzooo" -> "cazo")
        $text = preg_replace('/(.)\1{2,}/', '$1', $text);

        return $text;
    }

    /**
     * Filter word in text (replace with asterisks)
     */
    private function filterWord(string $text, string $keyword): string
    {
        $replacement = str_repeat('*', mb_strlen($keyword));
        return preg_replace('/\b' . preg_quote($keyword, '/') . '\b/iu', $replacement, $text);
    }

    /**
     * Auto-escalate message after multiple reports
     * ENTERPRISE V11.6: DM moderation removed - this only applies to chat room messages now
     */
    private function autoEscalate(string $messageUuid, int $reportCount): void
    {
        $db = $this->getDatabase();

        if (!$db) {
            return;
        }

        try {
            // Update all pending reports to escalated
            $db->execute(
                "UPDATE chat_message_reports
                 SET status = 'escalated', updated_at = NOW()
                 WHERE message_uuid = ? AND status = 'pending'",
                [$messageUuid]
            );

            // ENTERPRISE V11.6: DM moderation removed
            // direct_messages table no longer has is_hidden_by_mod column
            // Chat room messages use chat_messages table (separate)

            Logger::security('critical', 'Message auto-escalated', [
                'message_uuid' => $messageUuid,
                'report_count' => $reportCount,
            ]);

        } catch (\Exception $e) {
            Logger::error('Auto-escalation failed', [
                'message_uuid' => $messageUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate keywords cache
     */
    public function invalidateCache(): void
    {
        self::$keywordsCache = null;

        if ($this->redis !== null) {
            $this->redis->del(self::CACHE_KEY, 'L1_cache');
        } elseif (function_exists('cache')) {
            $cache = cache();
            if ($cache) {
                $cache->delete(self::CACHE_KEY);
            }
        }
    }
}
