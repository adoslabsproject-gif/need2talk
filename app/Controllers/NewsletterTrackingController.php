<?php

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: Newsletter Tracking Controller
 *
 * Tracks email opens (pixel) and clicks (link redirects)
 * Stores data in newsletter_metrics table
 *
 * @package Need2Talk\Controllers
 * @version 1.0.0
 */
class NewsletterTrackingController
{
    /**
     * ENTERPRISE: Track email open via 1x1 transparent pixel
     *
     * URL: /newsletter/track/open/{campaignId}/{recipientHash}
     *
     * @param int $campaignId Campaign ID
     * @param string $recipientHash Hashed recipient email (SHA256)
     * @return void (outputs 1x1 transparent GIF)
     */
    public function trackOpen(int $campaignId, string $recipientHash): void
    {
        try {
            $pdo = db_pdo();

            // ENTERPRISE GALAXY: Find recipient by hash in newsletter_metrics
            // Hash format: SHA256(email:campaignId) - consistent with worker generation
            // PostgreSQL: encode(sha256(...::bytea), 'hex') instead of MySQL's SHA2()
            $stmt = $pdo->prepare("
                SELECT id, recipient_email, opened_at, open_count
                FROM newsletter_metrics
                WHERE newsletter_id = ?
                  AND encode(sha256((recipient_email || ':' || newsletter_id::text)::bytea), 'hex') = ?
                LIMIT 1
            ");
            $stmt->execute([$campaignId, $recipientHash]);
            $metric = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($metric) {
                // ENTERPRISE GALAXY: Enrich tracking data with device/browser/geo intelligence
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

                // Parse User-Agent (device, browser, OS)
                $uaData = \Need2Talk\Services\EnterpriseUserAgentParser::parse($userAgent);

                // Lookup IP geolocation (cached 24h, multi-provider fallback)
                $geoData = \Need2Talk\Services\EnterpriseGeoIPService::lookup($ipAddress);

                // ENTERPRISE: Update open tracking (idempotent for first open)
                if (empty($metric['opened_at'])) {
                    // First open - FULL ENRICHMENT
                    $stmt = $pdo->prepare("
                        UPDATE newsletter_metrics
                        SET opened_at = NOW(),
                            last_opened_at = NOW(),
                            open_count = 1,
                            status = 'opened',
                            user_agent = ?,
                            ip_address = ?,
                            device_type = ?,
                            browser = ?,
                            os = ?,
                            country = ?,
                            city = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        substr($userAgent, 0, 500),
                        $ipAddress,
                        $uaData['device_type'],
                        $uaData['browser'],
                        $uaData['os'],
                        $geoData['country'],
                        $geoData['city'],
                        $metric['id'],
                    ]);

                    Logger::email('info', 'Newsletter: First open tracked', [
                        'campaign_id' => $campaignId,
                        'recipient' => substr($metric['recipient_email'], 0, 3) . '***',
                        'device' => $uaData['device_type'],
                        'browser' => $uaData['browser'],
                        'country' => $geoData['country'],
                        'geo_cached' => $geoData['cached'],
                    ]);
                } else {
                    // Subsequent open - light update (no geo lookup waste)
                    $stmt = $pdo->prepare("
                        UPDATE newsletter_metrics
                        SET last_opened_at = NOW(),
                            open_count = open_count + 1
                        WHERE id = ?
                    ");
                    $stmt->execute([$metric['id']]);
                }
            }

        } catch (\Exception $e) {
            Logger::email('error', 'Newsletter: Failed to track open', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }

        // ENTERPRISE GALAXY: Output 1x1 transparent GIF with CORS headers
        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Length: 43');

        // ENTERPRISE GALAXY: CORS headers for email client image proxies (Gmail, Outlook, etc.)
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Timing-Allow-Origin: *');

        // 1x1 transparent GIF (43 bytes)
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    /**
     * ENTERPRISE: Track link click and redirect
     *
     * URL: /newsletter/track/click/{campaignId}/{recipientHash}/{linkHash}
     *
     * @param int $campaignId Campaign ID
     * @param string $recipientHash Hashed recipient email
     * @param string $linkHash Hashed original URL
     * @return void (redirects to original URL)
     */
    public function trackClick(int $campaignId, string $recipientHash, string $linkHash): void
    {
        $linkWrapper = new \Need2Talk\Services\NewsletterLinkWrapperService();

        try {
            // ENTERPRISE: Get original URL from link wrapper service
            $originalUrl = $linkWrapper->getOriginalUrl($campaignId, $recipientHash, $linkHash);

            if ($originalUrl) {
                $pdo = db_pdo();

                // ENTERPRISE GALAXY: Update newsletter_metrics with click
                // Hash format: SHA256(email:campaignId) - consistent with worker generation
                // PostgreSQL: encode(sha256(...::bytea), 'hex') instead of MySQL's SHA2()
                $stmt = $pdo->prepare("
                    SELECT id, clicked_at, click_count
                    FROM newsletter_metrics
                    WHERE newsletter_id = ?
                      AND encode(sha256((recipient_email || ':' || newsletter_id::text)::bytea), 'hex') = ?
                    LIMIT 1
                ");
                $stmt->execute([$campaignId, $recipientHash]);
                $metric = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($metric) {
                    if (empty($metric['clicked_at'])) {
                        // First click
                        $stmt = $pdo->prepare("
                            UPDATE newsletter_metrics
                            SET clicked_at = NOW(),
                                last_clicked_at = NOW(),
                                click_count = 1,
                                status = 'clicked'
                            WHERE id = ?
                        ");
                        $stmt->execute([$metric['id']]);
                    } else {
                        // Subsequent click
                        $stmt = $pdo->prepare("
                            UPDATE newsletter_metrics
                            SET last_clicked_at = NOW(),
                                click_count = click_count + 1
                            WHERE id = ?
                        ");
                        $stmt->execute([$metric['id']]);
                    }

                    Logger::email('info', 'Newsletter: Link clicked', [
                        'campaign_id' => $campaignId,
                        'url' => $originalUrl,
                    ]);
                }

                // ENTERPRISE: Redirect to original URL
                header('Location: ' . $originalUrl, true, 302);
                exit;
            }

        } catch (\Exception $e) {
            Logger::email('error', 'Newsletter: Failed to track click', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }

        // ENTERPRISE: Fallback - redirect to homepage if error
        header('Location: https://need2talk.it', true, 302);
        exit;
    }
}
