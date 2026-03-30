<?php

namespace Need2Talk\Services;

/**
 * ENTERPRISE GALAXY: Newsletter Campaign Manager v3.0
 *
 * Gestisce il lifecycle completo delle campagne newsletter:
 * - Generazione plain text da HTML
 * - Tracking invii e fallimenti con Redis atomic counters
 * - Aggiornamento automatico status (zero PostgreSQL overhead)
 * - Calcolo metriche e completamento
 *
 * ENTERPRISE GALAXY ARCHITECTURE:
 * - Redis atomic counters (INCR) per sent_count/failed_count
 * - Zero SELECT query durante invio (performance ottimale)
 * - Solo ultimo worker aggiorna PostgreSQL (status='sent')
 * - Scala a milioni di email senza degradazione
 *
 * @package Need2Talk\Services
 * @version 3.0.0 ENTERPRISE GALAXY - Redis Atomic Counter
 */
class NewsletterCampaignManager
{
    /**
     * @var \Redis|null Redis instance for atomic counters
     */
    private $redis = null;

    /**
     * ENTERPRISE GALAXY: Get Redis connection for atomic counters
     * Uses persistent connection for optimal performance
     */
    private function getRedis(): \Redis
    {
        if ($this->redis !== null) {
            try {
                $this->redis->ping();

                return $this->redis;
            } catch (\Exception $e) {
                // Connection dead, recreate
                $this->redis = null;
            }
        }

        // Create new Redis connection
        $this->redis = new \Redis();
        $redisHost = $_ENV['REDIS_HOST'] ?? 'redis';
        $redisPort = (int) ($_ENV['REDIS_PORT'] ?? 6379);
        $redisPassword = $_ENV['REDIS_PASSWORD'] ?? null;

        // Use persistent connection (pconnect) for workers
        $this->redis->pconnect($redisHost, $redisPort, 2.0, 'newsletter_counters');

        if ($redisPassword) {
            $this->redis->auth($redisPassword);
        }

        // Use DB 2 for newsletter counters (queue DB)
        $this->redis->select(2);

        return $this->redis;
    }

    /**
     * ENTERPRISE: Generate plain text from HTML content
     *
     * Strip tags intelligentemente preservando la leggibilità
     *
     * @param string $html HTML content
     * @return string Plain text version
     */
    public function generatePlainText(string $html): string
    {
        // ENTERPRISE: Convert HTML to plain text preserving readability
        // Step 1: Convert <br> and </p> to newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);

        // Step 2: Strip all HTML tags
        $text = strip_tags($text);

        // Step 3: Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Step 4: Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text); // Multiple spaces → single space
        $text = preg_replace('/\n\s+/', "\n", $text); // Trim line start
        $text = preg_replace('/\s+\n/', "\n", $text); // Trim line end
        $text = preg_replace('/\n{3,}/', "\n\n", $text); // Max 2 consecutive newlines

        return trim($text);
    }

    /**
     * ENTERPRISE GALAXY: Update campaign after email sent successfully
     *
     * Uses Redis atomic counter for ZERO PostgreSQL overhead during sending
     * Only last worker updates PostgreSQL to status='sent'
     *
     * @param int $campaignId Campaign ID
     * @return bool Success
     */
    public function markEmailSent(int $campaignId): bool
    {
        try {
            $redis = $this->getRedis();

            // ENTERPRISE GALAXY: Atomic increment in Redis (zero PostgreSQL queries!)
            $redisKey = "newsletter:{$campaignId}:sent";
            $currentSent = $redis->incr($redisKey);

            // Set TTL on first increment (7 days cleanup)
            if ($currentSent === 1) {
                $redis->expire($redisKey, 604800); // 7 days
            }

            Logger::email('debug', 'ENTERPRISE GALAXY: Redis counter incremented', [
                'campaign_id' => $campaignId,
                'sent_count' => $currentSent,
                'redis_key' => $redisKey,
            ]);

            // Get total recipients and failed count from Redis
            $failedKey = "newsletter:{$campaignId}:failed";
            $currentFailed = (int) $redis->get($failedKey);

            // Check if this is the LAST email (completion)
            $totalKey = "newsletter:{$campaignId}:total";
            $totalRecipients = (int) $redis->get($totalKey);

            if ($totalRecipients === 0) {
                // First time, fetch from PostgreSQL and cache in Redis
                $pdo = db_pdo();
                $stmt = $pdo->prepare("SELECT total_recipients, started_sending_at FROM newsletters WHERE id = ?");
                $stmt->execute([$campaignId]);
                $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($campaign) {
                    $totalRecipients = (int) $campaign['total_recipients'];
                    $redis->setex($totalKey, 604800, $totalRecipients); // Cache 7 days

                    Logger::email('info', 'ENTERPRISE GALAXY: Cached total recipients in Redis', [
                        'campaign_id' => $campaignId,
                        'total_recipients' => $totalRecipients,
                    ]);
                }
            }

            $totalProcessed = $currentSent + $currentFailed;

            Logger::email('debug', 'ENTERPRISE GALAXY: Completion check', [
                'campaign_id' => $campaignId,
                'current_sent' => $currentSent,
                'current_failed' => $currentFailed,
                'total_processed' => $totalProcessed,
                'total_recipients' => $totalRecipients,
                'is_last_worker' => $totalProcessed >= $totalRecipients,
            ]);

            // AM I THE LAST WORKER? (sent + failed >= total)
            if ($totalProcessed >= $totalRecipients && $totalRecipients > 0) {
                // YES! I'm the last worker, update PostgreSQL status='sent'
                $this->completeCampaign($campaignId, $currentSent, $currentFailed);
            }

            return true;

        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterCampaignManager: Failed to mark email sent', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            // Direct PostgreSQL query if Redis fails
            return $this->markEmailSentFallback($campaignId);
        }
    }

    /**
     * FALLBACK: Update PostgreSQL directly if Redis unavailable
     */
    private function markEmailSentFallback(int $campaignId): bool
    {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->prepare("UPDATE newsletters SET sent_count = sent_count + 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$campaignId]);

            Logger::email('warning', 'ENTERPRISE GALAXY: Used PostgreSQL fallback (Redis unavailable)', [
                'campaign_id' => $campaignId,
            ]);

            return true;
        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterCampaignManager: Fallback also failed', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE GALAXY: Update campaign after email failed
     *
     * Uses Redis atomic counter for ZERO PostgreSQL overhead
     *
     * @param int $campaignId Campaign ID
     * @return bool Success
     */
    public function markEmailFailed(int $campaignId): bool
    {
        try {
            $redis = $this->getRedis();

            // ENTERPRISE GALAXY: Atomic increment in Redis
            $redisKey = "newsletter:{$campaignId}:failed";
            $currentFailed = $redis->incr($redisKey);

            // Set TTL on first increment
            if ($currentFailed === 1) {
                $redis->expire($redisKey, 604800); // 7 days
            }

            Logger::email('debug', 'ENTERPRISE GALAXY: Failed counter incremented', [
                'campaign_id' => $campaignId,
                'failed_count' => $currentFailed,
            ]);

            // Get sent count and total from Redis
            $sentKey = "newsletter:{$campaignId}:sent";
            $currentSent = (int) $redis->get($sentKey);

            $totalKey = "newsletter:{$campaignId}:total";
            $totalRecipients = (int) $redis->get($totalKey);

            if ($totalRecipients === 0) {
                // Fetch from PostgreSQL and cache
                $pdo = db_pdo();
                $stmt = $pdo->prepare("SELECT total_recipients FROM newsletters WHERE id = ?");
                $stmt->execute([$campaignId]);
                $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($campaign) {
                    $totalRecipients = (int) $campaign['total_recipients'];
                    $redis->setex($totalKey, 604800, $totalRecipients);
                }
            }

            $totalProcessed = $currentSent + $currentFailed;

            Logger::email('debug', 'ENTERPRISE GALAXY: Completion check (after fail)', [
                'campaign_id' => $campaignId,
                'current_sent' => $currentSent,
                'current_failed' => $currentFailed,
                'total_processed' => $totalProcessed,
                'total_recipients' => $totalRecipients,
            ]);

            // Check if campaign completed (even with failures)
            if ($totalProcessed >= $totalRecipients && $totalRecipients > 0) {
                $this->completeCampaign($campaignId, $currentSent, $currentFailed);
            }

            return true;

        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterCampaignManager: Failed to mark email failed', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            // Direct PostgreSQL query
            return $this->markEmailFailedFallback($campaignId);
        }
    }

    /**
     * FALLBACK: Update PostgreSQL directly if Redis unavailable
     */
    private function markEmailFailedFallback(int $campaignId): bool
    {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->prepare("UPDATE newsletters SET failed_count = failed_count + 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$campaignId]);

            Logger::email('warning', 'ENTERPRISE GALAXY: Used PostgreSQL fallback for failed (Redis unavailable)', [
                'campaign_id' => $campaignId,
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * ENTERPRISE GALAXY: Complete campaign and update PostgreSQL
     * Called ONLY by the last worker to process an email
     *
     * @param int $campaignId Campaign ID
     * @param int $sentCount Total emails sent (from Redis)
     * @param int $failedCount Total emails failed (from Redis)
     */
    private function completeCampaign(int $campaignId, int $sentCount, int $failedCount): void
    {
        try {
            $pdo = db_pdo();

            // Fetch campaign data for processing time calculation
            $stmt = $pdo->prepare("SELECT started_sending_at FROM newsletters WHERE id = ? AND status = 'sending'");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$campaign) {
                Logger::email('warning', 'ENTERPRISE GALAXY: Campaign already completed or not found', [
                    'campaign_id' => $campaignId,
                ]);

                return; // Already completed by another worker (race condition)
            }

            // Calculate processing time
            $startTime = strtotime($campaign['started_sending_at']);
            $endTime = time();
            $processingTimeMs = ($endTime - $startTime) * 1000;

            // ATOMIC UPDATE: Set status='sent' ONLY if still 'sending'
            // This prevents race condition if multiple workers reach completion simultaneously
            $stmt = $pdo->prepare("
                UPDATE newsletters
                SET status = 'sent',
                    sent_count = ?,
                    failed_count = ?,
                    completed_sending_at = NOW(),
                    processing_time_ms = ?,
                    updated_at = NOW()
                WHERE id = ? AND status = 'sending'
            ");

            $affected = $stmt->execute([$sentCount, $failedCount, $processingTimeMs, $campaignId]);

            if ($stmt->rowCount() > 0) {
                Logger::email('info', 'ENTERPRISE GALAXY: Campaign completed successfully', [
                    'campaign_id' => $campaignId,
                    'sent_count' => $sentCount,
                    'failed_count' => $failedCount,
                    'processing_time_ms' => $processingTimeMs,
                    'duration_seconds' => round($processingTimeMs / 1000, 2),
                ]);

                // ENTERPRISE GALAXY: Aggregate metrics from newsletter_metrics table
                // This updates unique_opens, total_clicks, avg_open_rate, avg_ctr, etc.
                $this->aggregateMetrics($campaignId);

                Logger::email('info', 'ENTERPRISE GALAXY: Metrics aggregated after campaign completion', [
                    'campaign_id' => $campaignId,
                ]);

                // Cleanup Redis counters (optional - TTL will auto-cleanup anyway)
                try {
                    $redis = $this->getRedis();
                    $redis->del([
                        "newsletter:{$campaignId}:sent",
                        "newsletter:{$campaignId}:failed",
                        "newsletter:{$campaignId}:total",
                    ]);

                    Logger::email('debug', 'ENTERPRISE GALAXY: Cleaned up Redis counters', [
                        'campaign_id' => $campaignId,
                    ]);
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            } else {
                Logger::email('debug', 'ENTERPRISE GALAXY: Campaign already completed by another worker', [
                    'campaign_id' => $campaignId,
                ]);
            }

        } catch (\Exception $e) {
            Logger::email('error', 'ENTERPRISE GALAXY: Failed to complete campaign', [
                'campaign_id' => $campaignId,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE GALAXY: Reset Redis counters for campaign re-send
     *
     * Called when admin wants to re-send a campaign
     * Clears Redis atomic counters to start fresh
     *
     * @param int $campaignId Campaign ID
     * @return bool Success
     */
    public function resetCounters(int $campaignId): bool
    {
        try {
            $redis = $this->getRedis();

            // Delete all Redis keys for this campaign
            $deletedKeys = $redis->del([
                "newsletter:{$campaignId}:sent",
                "newsletter:{$campaignId}:failed",
                "newsletter:{$campaignId}:total",
            ]);

            Logger::email('info', 'ENTERPRISE GALAXY: Reset campaign counters for re-send', [
                'campaign_id' => $campaignId,
                'keys_deleted' => $deletedKeys,
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::email('error', 'ENTERPRISE GALAXY: Failed to reset counters', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE: Update campaign plain text if missing
     *
     * Genera plain_text_body dall'HTML se è NULL
     *
     * @param int $campaignId Campaign ID
     * @return bool Success
     */
    public function ensurePlainTextExists(int $campaignId): bool
    {
        try {
            // ENTERPRISE FIX: Get fresh PDO from pool (avoid "PostgreSQL server has gone away")
            $pdo = db_pdo();

            // ENTERPRISE: Check if plain text is missing
            $stmt = $pdo->prepare("
                SELECT id, html_body, plain_text_body
                FROM newsletters
                WHERE id = ?
            ");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$campaign) {
                return false;
            }

            // ENTERPRISE: Generate plain text if missing
            if (empty($campaign['plain_text_body'])) {
                $plainText = $this->generatePlainText($campaign['html_body']);

                $stmt = $pdo->prepare("
                    UPDATE newsletters
                    SET plain_text_body = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$plainText, $campaignId]);

                Logger::email('info', 'NewsletterCampaignManager: Generated plain text', [
                    'campaign_id' => $campaignId,
                    'length' => strlen($plainText),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterCampaignManager: Failed to ensure plain text', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ENTERPRISE GALAXY: Aggregate newsletter metrics from newsletter_metrics to newsletters table
     *
     * Calculates and updates:
     * - unique_opens, total_opens
     * - unique_clicks, total_clicks
     * - unsubscribe_count, bounce_count
     * - avg_open_rate, avg_click_rate, avg_ctr
     *
     * @param int $campaignId Campaign ID
     * @return bool Success status
     */
    public function aggregateMetrics(int $campaignId): bool
    {
        try {
            $pdo = db_pdo();

            // ENTERPRISE: Aggregate metrics from newsletter_metrics
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL THEN id END) as unique_opens,
                    COALESCE(SUM(open_count), 0) as total_opens,
                    COUNT(DISTINCT CASE WHEN clicked_at IS NOT NULL THEN id END) as unique_clicks,
                    COALESCE(SUM(click_count), 0) as total_clicks,
                    COUNT(DISTINCT CASE WHEN bounced_at IS NOT NULL THEN id END) as bounce_count,
                    COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL THEN id END) as unsubscribe_count
                FROM newsletter_metrics
                WHERE newsletter_id = ?
            ");
            $stmt->execute([$campaignId]);
            $metrics = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$metrics) {
                return false;
            }

            // Get total recipients for percentage calculations
            $stmt = $pdo->prepare("SELECT total_recipients FROM newsletters WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);
            $totalRecipients = $campaign['total_recipients'] ?? 0;

            // Calculate percentages
            $avgOpenRate = ($totalRecipients > 0)
                ? round(($metrics['unique_opens'] / $totalRecipients) * 100, 2)
                : 0.00;

            $avgClickRate = ($totalRecipients > 0)
                ? round(($metrics['unique_clicks'] / $totalRecipients) * 100, 2)
                : 0.00;

            $avgCtr = ($metrics['unique_opens'] > 0)
                ? round(($metrics['unique_clicks'] / $metrics['unique_opens']) * 100, 2)
                : 0.00;

            // ENTERPRISE: Update newsletters table with aggregated metrics
            $stmt = $pdo->prepare("
                UPDATE newsletters
                SET unique_opens = ?,
                    total_opens = ?,
                    unique_clicks = ?,
                    total_clicks = ?,
                    bounce_count = ?,
                    unsubscribe_count = ?,
                    avg_open_rate = ?,
                    avg_click_rate = ?,
                    avg_ctr = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $metrics['unique_opens'],
                $metrics['total_opens'],
                $metrics['unique_clicks'],
                $metrics['total_clicks'],
                $metrics['bounce_count'],
                $metrics['unsubscribe_count'],
                $avgOpenRate,
                $avgClickRate,
                $avgCtr,
                $campaignId,
            ]);

            Logger::email('debug', 'NewsletterCampaignManager: Aggregated metrics', [
                'campaign_id' => $campaignId,
                'unique_opens' => $metrics['unique_opens'],
                'unique_clicks' => $metrics['unique_clicks'],
                'open_rate' => $avgOpenRate . '%',
                'click_rate' => $avgClickRate . '%',
                'ctr' => $avgCtr . '%',
            ]);

            return true;

        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterCampaignManager: Failed to aggregate metrics', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
