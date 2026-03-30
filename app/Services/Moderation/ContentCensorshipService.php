<?php

namespace Need2Talk\Services\Moderation;

use Need2Talk\Services\Logger;

/**
 * ContentCensorshipService - Enterprise Content Filtering
 *
 * Automatically censors prohibited words in user-generated content.
 * Replaces matched words with configurable replacements (default: ***)
 *
 * Features:
 * - Multiple match types: exact, contains, regex, fuzzy
 * - Leet speak normalization (4→a, 3→e, etc.)
 * - Context-aware filtering (titles, descriptions, comments, chat)
 * - Redis caching for performance
 * - Audit logging of censored content
 *
 * @package Need2Talk\Services\Moderation
 */
class ContentCensorshipService
{
    private const CACHE_KEY = 'moderation:keywords:';
    private const CACHE_TTL = 300; // 5 minutes

    private ?array $keywordsCache = null;

    // Leet speak normalization map
    private const LEET_MAP = [
        '0' => 'o',
        '1' => 'i',
        '3' => 'e',
        '4' => 'a',
        '5' => 's',
        '6' => 'g',
        '7' => 't',
        '8' => 'b',
        '9' => 'g',
        '@' => 'a',
        '$' => 's',
        '!' => 'i',
        '+' => 't',
    ];

    /**
     * Censor content by replacing prohibited words with ***
     *
     * @param string $content The text to censor
     * @param string $context Context: 'post_title', 'post_description', 'comment', 'chat'
     * @return array [
     *   'censored' => string,      // The censored text
     *   'original' => string,      // Original text
     *   'matched' => array,        // List of matched keywords
     *   'was_censored' => bool,    // Whether any censorship occurred
     *   'match_count' => int,      // Number of replacements made
     * ]
     */
    public function censorContent(string $content, string $context): array
    {
        if (empty(trim($content))) {
            return [
                'censored' => $content,
                'original' => $content,
                'matched' => [],
                'was_censored' => false,
                'match_count' => 0,
            ];
        }

        $keywords = $this->getActiveKeywords($context);
        $censored = $content;
        $matched = [];
        $matchCount = 0;

        foreach ($keywords as $keyword) {
            $result = $this->processKeyword($censored, $keyword);
            if ($result['matched']) {
                $matched[] = [
                    'keyword' => $keyword['keyword'],
                    'severity' => $keyword['severity'],
                    'category' => $keyword['category'] ?? null,
                ];
                $censored = $result['text'];
                $matchCount += $result['count'];
            }
        }

        $wasCensored = $matchCount > 0;

        // Log if censored (for analytics)
        if ($wasCensored && get_env('CENSORSHIP_LOG_MATCHES') !== 'false') {
            $this->logCensoredContent($context, $matched, $matchCount);
        }

        return [
            'censored' => $censored,
            'original' => $content,
            'matched' => $matched,
            'was_censored' => $wasCensored,
            'match_count' => $matchCount,
        ];
    }

    /**
     * Check if content contains prohibited words (without modifying)
     */
    public function containsProhibited(string $content, string $context): array
    {
        if (empty(trim($content))) {
            return ['has_prohibited' => false, 'matched' => []];
        }

        $keywords = $this->getActiveKeywords($context);
        $matched = [];

        foreach ($keywords as $keyword) {
            if ($this->matchesKeyword($content, $keyword)) {
                $matched[] = [
                    'keyword' => $keyword['keyword'],
                    'severity' => $keyword['severity'],
                    'category' => $keyword['category'] ?? null,
                ];
            }
        }

        return [
            'has_prohibited' => count($matched) > 0,
            'matched' => $matched,
        ];
    }

    /**
     * Get active keywords for a specific context from cache or database
     */
    public function getActiveKeywords(string $context): array
    {
        $cacheKey = self::CACHE_KEY . $context;

        // Check instance cache first
        if (isset($this->keywordsCache[$context])) {
            return $this->keywordsCache[$context];
        }

        // Try Redis cache
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $cached = $redis->get($cacheKey);
            if ($cached) {
                $keywords = json_decode($cached, true);
                if (is_array($keywords)) {
                    $this->keywordsCache[$context] = $keywords;
                    return $keywords;
                }
            }
        } catch (\Exception $e) {
            // Redis not available, continue to database
        }

        // Load from database
        $keywords = $this->loadKeywordsFromDatabase($context);

        // Cache in Redis
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $redis->setex($cacheKey, self::CACHE_TTL, json_encode($keywords));
        } catch (\Exception $e) {
            // Continue without caching
        }

        $this->keywordsCache[$context] = $keywords;
        return $keywords;
    }

    /**
     * Load keywords from database filtered by context
     */
    private function loadKeywordsFromDatabase(string $context): array
    {
        $pdo = db_pdo();

        // Map context to database column
        $contextColumn = match ($context) {
            'post_title' => 'applies_to_titles',
            'post_description' => 'applies_to_posts',
            'comment' => 'applies_to_comments',
            'chat' => 'applies_to_rooms',
            default => 'applies_to_posts',
        };

        $stmt = $pdo->prepare("
            SELECT
                id,
                keyword,
                keyword_normalized,
                match_type,
                severity,
                action_type,
                category,
                replacement
            FROM keyword_blacklist
            WHERE is_active = TRUE
            AND {$contextColumn} = TRUE
            ORDER BY severity DESC, LENGTH(keyword) DESC
        ");
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Process a single keyword against the content
     */
    private function processKeyword(string $content, array $keyword): array
    {
        $replacement = $keyword['replacement'] ?? '***';
        $matchType = $keyword['match_type'] ?? 'contains';
        $keywordText = $keyword['keyword'];
        $count = 0;
        $matched = false;

        switch ($matchType) {
            case 'exact':
                // Exact word match (word boundaries)
                $pattern = '/\b' . preg_quote($keywordText, '/') . '\b/iu';
                $content = preg_replace_callback($pattern, function ($matches) use ($replacement, &$count) {
                    $count++;
                    return $this->generateReplacement($matches[0], $replacement);
                }, $content);
                $matched = $count > 0;
                break;

            case 'contains':
                // Contains anywhere (case-insensitive)
                $pattern = '/' . preg_quote($keywordText, '/') . '/iu';
                $content = preg_replace_callback($pattern, function ($matches) use ($replacement, &$count) {
                    $count++;
                    return $this->generateReplacement($matches[0], $replacement);
                }, $content);
                $matched = $count > 0;
                break;

            case 'regex':
                // Custom regex pattern
                try {
                    $content = preg_replace_callback('/' . $keywordText . '/iu', function ($matches) use ($replacement, &$count) {
                        $count++;
                        return $this->generateReplacement($matches[0], $replacement);
                    }, $content);
                    $matched = $count > 0;
                } catch (\Exception $e) {
                    Logger::warning('Invalid regex pattern in keyword', ['keyword_id' => $keyword['id']]);
                }
                break;

            case 'fuzzy':
                // Fuzzy match with leet speak normalization
                $normalizedContent = $this->normalizeLeetSpeak($content);
                $normalizedKeyword = $this->normalizeLeetSpeak($keywordText);

                // Check if normalized content contains the normalized keyword
                $pattern = '/' . preg_quote($normalizedKeyword, '/') . '/iu';
                if (preg_match($pattern, $normalizedContent)) {
                    // Find and replace in original content
                    // This is complex - we need to find the original text that normalized to the match
                    $content = $this->fuzzyReplace($content, $keywordText, $replacement, $count);
                    $matched = $count > 0;
                }
                break;
        }

        return [
            'text' => $content,
            'matched' => $matched,
            'count' => $count,
        ];
    }

    /**
     * Check if content matches a keyword (without replacement)
     */
    private function matchesKeyword(string $content, array $keyword): bool
    {
        $matchType = $keyword['match_type'] ?? 'contains';
        $keywordText = $keyword['keyword'];

        switch ($matchType) {
            case 'exact':
                $pattern = '/\b' . preg_quote($keywordText, '/') . '\b/iu';
                return (bool) preg_match($pattern, $content);

            case 'contains':
                return stripos($content, $keywordText) !== false;

            case 'regex':
                try {
                    return (bool) preg_match('/' . $keywordText . '/iu', $content);
                } catch (\Exception $e) {
                    return false;
                }

            case 'fuzzy':
                $normalizedContent = $this->normalizeLeetSpeak($content);
                $normalizedKeyword = $this->normalizeLeetSpeak($keywordText);
                return stripos($normalizedContent, $normalizedKeyword) !== false;
        }

        return false;
    }

    /**
     * Generate replacement text (preserves length if possible)
     */
    private function generateReplacement(string $original, string $replacement): string
    {
        if ($replacement === '***') {
            // Generate asterisks matching the length of the original
            return str_repeat('*', mb_strlen($original));
        }

        return $replacement;
    }

    /**
     * Normalize leet speak to regular letters
     */
    private function normalizeLeetSpeak(string $text): string
    {
        $normalized = mb_strtolower($text);

        foreach (self::LEET_MAP as $leet => $letter) {
            $normalized = str_replace($leet, $letter, $normalized);
        }

        // Remove accents
        $normalized = $this->removeAccents($normalized);

        return $normalized;
    }

    /**
     * Remove accents from text
     */
    private function removeAccents(string $text): string
    {
        $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];

        return strtr($text, $accents);
    }

    /**
     * Fuzzy replace with leet speak normalization
     */
    private function fuzzyReplace(string $content, string $keyword, string $replacement, int &$count): string
    {
        // Simple implementation: try common leet variations
        $variations = $this->generateLeetVariations($keyword);

        foreach ($variations as $variant) {
            $pattern = '/' . preg_quote($variant, '/') . '/iu';
            $content = preg_replace_callback($pattern, function ($matches) use ($replacement, &$count) {
                $count++;
                return $this->generateReplacement($matches[0], $replacement);
            }, $content);
        }

        return $content;
    }

    /**
     * Generate common leet speak variations of a word
     */
    private function generateLeetVariations(string $word): array
    {
        $variations = [$word];

        // Add variations with common substitutions
        $substitutions = [
            'a' => ['4', '@'],
            'e' => ['3'],
            'i' => ['1', '!'],
            'o' => ['0'],
            's' => ['5', '$'],
            't' => ['7', '+'],
        ];

        // Generate single-substitution variations
        $lower = mb_strtolower($word);
        foreach ($substitutions as $letter => $subs) {
            foreach ($subs as $sub) {
                $variant = str_ireplace($letter, $sub, $lower);
                if ($variant !== $lower) {
                    $variations[] = $variant;
                }
            }
        }

        return array_unique($variations);
    }

    /**
     * Log censored content for analytics
     */
    private function logCensoredContent(string $context, array $matched, int $matchCount): void
    {
        Logger::info('Content censored', [
            'context' => $context,
            'matched_count' => count($matched),
            'replacement_count' => $matchCount,
            'keywords' => array_column($matched, 'keyword'),
        ]);
    }

    /**
     * Invalidate keyword cache (call when keywords are modified)
     */
    public function invalidateCache(?string $context = null): void
    {
        try {
            $redis = \Need2Talk\Core\EnterpriseRedisManager::getInstance();

            if ($context) {
                $redis->del(self::CACHE_KEY . $context);
            } else {
                // Invalidate all contexts
                $contexts = ['post_title', 'post_description', 'comment', 'chat'];
                foreach ($contexts as $ctx) {
                    $redis->del(self::CACHE_KEY . $ctx);
                }
            }

            // Clear instance cache
            $this->keywordsCache = null;

            Logger::info('Keyword cache invalidated', ['context' => $context ?? 'all']);
        } catch (\Exception $e) {
            Logger::warning('Failed to invalidate keyword cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get all active keywords (for admin display)
     */
    public function getAllKeywords(): array
    {
        $pdo = db_pdo();
        $stmt = $pdo->query("
            SELECT
                id, keyword, match_type, severity, action_type, category,
                replacement, applies_to_posts, applies_to_comments,
                applies_to_titles, applies_to_rooms, is_active, created_at
            FROM keyword_blacklist
            ORDER BY severity DESC, keyword ASC
        ");

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Add a new keyword to the blacklist
     */
    public function addKeyword(array $data, ?int $moderatorId = null): array
    {
        $pdo = db_pdo();

        // Validate required fields
        if (empty($data['keyword'])) {
            return ['success' => false, 'error' => 'Keyword is required'];
        }

        // Normalize keyword
        $keyword = trim($data['keyword']);
        $keywordNormalized = $this->normalizeLeetSpeak($keyword);

        // Check for duplicate
        $stmt = $pdo->prepare("SELECT id FROM keyword_blacklist WHERE keyword = :keyword");
        $stmt->execute(['keyword' => $keyword]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Keyword already exists'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO keyword_blacklist (
                keyword, keyword_normalized, match_type, severity, action_type,
                category, replacement, applies_to_posts, applies_to_comments,
                applies_to_titles, applies_to_rooms, applies_to_dm, is_active, created_by
            ) VALUES (
                :keyword, :keyword_normalized, :match_type, :severity, :action_type,
                :category, :replacement, :applies_to_posts, :applies_to_comments,
                :applies_to_titles, :applies_to_rooms, FALSE, TRUE, :created_by
            )
            RETURNING id
        ");

        $stmt->execute([
            'keyword' => $keyword,
            'keyword_normalized' => $keywordNormalized,
            'match_type' => $data['match_type'] ?? 'contains',
            'severity' => (int) ($data['severity'] ?? 3),
            'action_type' => $data['action_type'] ?? 'block',
            'category' => $data['category'] ?? null,
            'replacement' => $data['replacement'] ?? '***',
            'applies_to_posts' => ($data['applies_to_posts'] ?? true) ? 't' : 'f',
            'applies_to_comments' => ($data['applies_to_comments'] ?? true) ? 't' : 'f',
            'applies_to_titles' => ($data['applies_to_titles'] ?? true) ? 't' : 'f',
            'applies_to_rooms' => ($data['applies_to_rooms'] ?? true) ? 't' : 'f',
            'created_by' => $moderatorId,
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Invalidate cache
        $this->invalidateCache();

        // Log action
        if ($moderatorId) {
            ModerationSecurityService::logModerationAction($moderatorId, 'add_keyword', null, null, [
                'keyword' => $keyword,
                'severity' => $data['severity'] ?? 3,
            ]);
        }

        return ['success' => true, 'id' => $result['id']];
    }

    /**
     * Delete a keyword from the blacklist
     */
    public function deleteKeyword(int $keywordId, ?int $moderatorId = null): bool
    {
        $pdo = db_pdo();

        // Get keyword for logging
        $stmt = $pdo->prepare("SELECT keyword FROM keyword_blacklist WHERE id = :id");
        $stmt->execute(['id' => $keywordId]);
        $keyword = $stmt->fetchColumn();

        // Delete keyword
        $stmt = $pdo->prepare("DELETE FROM keyword_blacklist WHERE id = :id");
        $stmt->execute(['id' => $keywordId]);

        // Invalidate cache
        $this->invalidateCache();

        // Log action
        if ($moderatorId && $keyword) {
            ModerationSecurityService::logModerationAction($moderatorId, 'remove_keyword', null, null, [
                'keyword_id' => $keywordId,
                'keyword' => $keyword,
            ]);
        }

        return true;
    }
}
