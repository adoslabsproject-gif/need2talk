<?php

namespace Need2Talk\Services;

/**
 * ENTERPRISE GALAXY: Newsletter Link Wrapper Service
 *
 * Wraps all links in newsletter HTML with tracking URLs
 * Maintains original link text and styling
 * Stores mapping in newsletter_metrics for click tracking
 *
 * @package Need2Talk\Services
 * @version 1.0.0
 */
class NewsletterLinkWrapperService
{
    /**
     * @var \AutoReleasePDO Database connection (AutoReleasePDO wrapper from db_pdo())
     */
    private $pdo;

    private string $baseUrl;

    public function __construct()
    {
        $this->pdo = db_pdo();
        $this->baseUrl = rtrim($_ENV['APP_URL'] ?? 'https://need2talk.it', '/');
    }

    /**
     * ENTERPRISE: Wrap all links in HTML with tracking URLs
     *
     * @param string $html Original HTML content
     * @param int $newsletterId Newsletter campaign ID
     * @param string $recipientEmail Recipient email for personalized tracking
     * @return string HTML with wrapped links
     */
    public function wrapLinks(string $html, int $newsletterId, string $recipientEmail): string
    {
        // ENTERPRISE: Generate recipient hash (SHA256 for privacy + uniqueness)
        $recipientHash = hash('sha256', $recipientEmail . ':' . $newsletterId);

        // ENTERPRISE: Find all <a href="..."> tags with regex
        // Matches: <a href="URL" ...> or <a href='URL' ...>
        $pattern = '/<a\s+([^>]*?)href=(["\'])(.*?)\2([^>]*?)>/i';

        $wrappedHtml = preg_replace_callback($pattern, function ($matches) use ($newsletterId, $recipientHash) {
            $beforeHref = $matches[1]; // Attributes before href
            $quoteChar = $matches[2];  // " or '
            $originalUrl = $matches[3]; // Original URL
            $afterHref = $matches[4];   // Attributes after href

            // ENTERPRISE: Skip if already wrapped or mailto/tel/anchor links
            if (
                strpos($originalUrl, '/newsletter/track/click/') !== false ||
                strpos($originalUrl, 'mailto:') === 0 ||
                strpos($originalUrl, 'tel:') === 0 ||
                strpos($originalUrl, '#') === 0 ||
                empty($originalUrl)
            ) {
                return $matches[0]; // Return original
            }

            // ENTERPRISE: Generate link hash for this specific URL
            $linkHash = substr(hash('sha256', $originalUrl . ':' . $newsletterId), 0, 16);

            // ENTERPRISE: Build tracking URL
            $trackingUrl = $this->baseUrl . "/newsletter/track/click/{$newsletterId}/{$recipientHash}/{$linkHash}";

            // ENTERPRISE: Reconstruct <a> tag with tracking URL
            return "<a {$beforeHref}href={$quoteChar}{$trackingUrl}{$quoteChar}{$afterHref}>";

        }, $html);

        Logger::email('debug', 'NewsletterLinkWrapper: Links wrapped', [
            'newsletter_id' => $newsletterId,
            'recipient' => substr($recipientEmail, 0, 3) . '***',
            'links_found' => substr_count($html, '<a '),
        ]);

        return $wrappedHtml;
    }

    /**
     * ENTERPRISE: Store link mapping for tracking
     *
     * Called when newsletter is sent to save URL → hash mapping
     * Stored in newsletter_metrics.clicked_links as JSON
     *
     * @param int $newsletterId Newsletter campaign ID
     * @param string $recipientEmail Recipient email
     * @param string $html HTML content (to extract links)
     * @return void
     */
    public function storeLinkMappings(int $newsletterId, string $recipientEmail, string $html): void
    {
        // ENTERPRISE: Extract all links from HTML
        $links = [];
        preg_match_all('/<a\s+[^>]*?href=(["\'])(.*?)\1[^>]*?>/i', $html, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $url) {
                // Skip tracking URLs, mailto, tel, anchors
                if (
                    strpos($url, '/newsletter/track/click/') !== false ||
                    strpos($url, 'mailto:') === 0 ||
                    strpos($url, 'tel:') === 0 ||
                    strpos($url, '#') === 0 ||
                    empty($url)
                ) {
                    continue;
                }

                $linkHash = substr(hash('sha256', $url . ':' . $newsletterId), 0, 16);
                $links[$linkHash] = $url;
            }
        }

        // ENTERPRISE: Store in newsletter_metrics.clicked_links as JSON
        try {
            $stmt = $this->pdo->prepare("
                UPDATE newsletter_metrics
                SET clicked_links = ?
                WHERE newsletter_id = ?
                  AND recipient_email = ?
            ");

            $linksJson = json_encode($links);
            $stmt->execute([$linksJson, $newsletterId, $recipientEmail]);

            Logger::email('debug', 'NewsletterLinkWrapper: Link mappings stored', [
                'newsletter_id' => $newsletterId,
                'recipient' => substr($recipientEmail, 0, 3) . '***',
                'links_count' => count($links),
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterLinkWrapper: Failed to store mappings', [
                'newsletter_id' => $newsletterId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ENTERPRISE: Get original URL from hash
     *
     * Used by tracking controller to redirect after click
     *
     * @param int $newsletterId Newsletter campaign ID
     * @param string $recipientHash Hashed recipient email
     * @param string $linkHash Hash of original URL
     * @return string|null Original URL or null if not found
     */
    public function getOriginalUrl(int $newsletterId, string $recipientHash, string $linkHash): ?string
    {
        try {
            // ENTERPRISE: Get clicked_links JSON from newsletter_metrics
            // CRITICAL FIX: Hash format MUST match NewsletterTrackingController
            // Format: SHA256(email:newsletterId) - consistent with worker generation
            // PostgreSQL: encode(sha256(...::bytea), 'hex') instead of MySQL's SHA2()
            $stmt = $this->pdo->prepare("
                SELECT clicked_links
                FROM newsletter_metrics
                WHERE newsletter_id = ?
                  AND encode(sha256((recipient_email || ':' || newsletter_id::text)::bytea), 'hex') = ?
                LIMIT 1
            ");
            $stmt->execute([$newsletterId, $recipientHash]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result && !empty($result['clicked_links'])) {
                $links = json_decode($result['clicked_links'], true);

                if (isset($links[$linkHash])) {
                    return $links[$linkHash];
                }
            }

        } catch (\Exception $e) {
            Logger::email('error', 'NewsletterLinkWrapper: Failed to get original URL', [
                'newsletter_id' => $newsletterId,
                'link_hash' => $linkHash,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
