<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\AdminEmailQueue;
use Need2Talk\Services\Logger;

/**
 * Admin Newsletter Controller
 *
 * ENTERPRISE GALAXY: Handles ALL newsletter management for admin panel
 * - Campaign creation with TinyMCE editor
 * - User targeting and segmentation
 * - Scheduling and sending
 * - Real-time tracking metrics
 * - Worker control integration
 * - Performance testing
 *
 * PERFORMANCE OPTIMIZED: Uses fresh PDO connections for real-time data
 */
class AdminNewsletterController extends BaseController
{
    /**
     * Get all data for Newsletter admin page
     * Returns data array for AdminController to render
     *
     * ENTERPRISE GALAXY: Real-time newsletter management with no caching
     */
    public function getPageData(): array
    {
        // ENTERPRISE: No-cache headers for real-time data
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');

        // Get dashboard stats
        $dashboardStats = $this->getNewsletterDashboard();

        // Get campaigns list
        $campaigns = $this->getCampaignsList();

        // Get worker status
        $workerStatus = $this->getWorkerStatus();

        // Return data for rendering
        return [
            'title' => 'Newsletter Management',
            'dashboard' => $dashboardStats,
            'campaigns' => $campaigns,
            'worker_status' => $workerStatus,
        ];
    }

    /**
     * ENTERPRISE GALAXY: Get comprehensive newsletter dashboard
     * Aggregates data from newsletters and newsletter_metrics tables
     */
    private function getNewsletterDashboard(): array
    {
        try {
            $pdo = $this->getFreshPDO();

            // Get overall stats
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as total_campaigns,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_campaigns,
                    SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END) as sending_campaigns,
                    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_campaigns,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_campaigns,
                    SUM(sent_count) as total_sent,
                    SUM(unique_opens) as total_opens,
                    SUM(unique_clicks) as total_clicks,
                    AVG(avg_open_rate) as overall_open_rate,
                    AVG(avg_ctr) as overall_ctr
                FROM newsletters
            ");
            $overallStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Get recent campaigns (last 10)
            $stmt = $pdo->query("
                SELECT
                    id, campaign_name, subject, status, total_recipients,
                    sent_count, unique_opens, unique_clicks, avg_open_rate,
                    avg_click_rate, created_at, scheduled_for
                FROM newsletters
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $recentCampaigns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get user opt-in stats
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as total_users,
                    SUM(CASE WHEN newsletter_opt_in = TRUE THEN 1 ELSE 0 END) as opted_in,
                    SUM(CASE WHEN newsletter_opt_in = FALSE THEN 1 ELSE 0 END) as opted_out,
                    SUM(CASE WHEN email_verified = TRUE AND newsletter_opt_in = TRUE THEN 1 ELSE 0 END) as verified_opted_in
                FROM users
                WHERE deleted_at IS NULL
            ");
            $userStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Get top performing campaigns
            $stmt = $pdo->query("
                SELECT
                    id, campaign_name, avg_open_rate, avg_click_rate, avg_ctr,
                    sent_count, unique_opens, unique_clicks
                FROM newsletters
                WHERE status = 'sent' AND sent_count > 0
                ORDER BY avg_open_rate DESC
                LIMIT 5
            ");
            $topCampaigns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'overall' => $overallStats,
                'recent_campaigns' => $recentCampaigns,
                'users' => $userStats,
                'top_campaigns' => $topCampaigns,
                'timestamp' => date('c'),
            ];

        } catch (\Exception $e) {
            Logger::email('error', 'AdminNewsletterController: Failed to get newsletter dashboard', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'overall' => [],
                'recent_campaigns' => [],
                'users' => [],
                'top_campaigns' => [],
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY: Get campaigns list with pagination
     */
    private function getCampaignsList(int $limit = 50, int $offset = 0): array
    {
        try {
            $pdo = $this->getFreshPDO();

            // Get total count
            $stmt = $pdo->query('SELECT COUNT(*) as total FROM newsletters');
            $total = (int) $stmt->fetchColumn();

            // Get campaigns
            $stmt = $pdo->prepare("
                SELECT
                    id, campaign_name, subject, status, total_recipients,
                    sent_count, failed_count, unique_opens, unique_clicks,
                    avg_open_rate, avg_click_rate, avg_ctr,
                    created_by_email, scheduled_for, created_at
                FROM newsletters
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $campaigns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'campaigns' => $campaigns,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ];

        } catch (\Exception $e) {
            Logger::email('error', 'AdminNewsletterController: Failed to get campaigns list', [
                'error' => $e->getMessage(),
            ]);

            return [
                'campaigns' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY: Get worker status
     * Checks if admin email workers are running
     */
    private function getWorkerStatus(): array
    {
        try {
            // Check if running in Docker
            $container = env('DOCKER_PHP_CONTAINER', 'need2talk_php');

            // Try to get worker PIDs from Docker container
            $command = "docker exec {$container} pgrep -f 'admin-email-worker.php' 2>/dev/null";
            exec($command, $output, $returnCode);

            $running = !empty($output);
            $workerCount = count($output);
            $pids = $output;

            // Get queue stats from AdminEmailQueue
            try {
                $queue = new AdminEmailQueue();
                $queueStats = $queue->getStats();
            } catch (\Exception $e) {
                $queueStats = [
                    'total_queued' => 0,
                    'urgent' => 0,
                    'high' => 0,
                    'normal' => 0,
                    'low' => 0,
                    'processing' => 0,
                    'failed' => 0,
                ];
            }

            return [
                'running' => $running,
                'worker_count' => $workerCount,
                'pids' => $pids,
                'queue' => $queueStats,
                'container' => $container,
                'timestamp' => date('c'),
            ];

        } catch (\Exception $e) {
            Logger::email('error', 'AdminNewsletterController: Failed to get worker status', [
                'error' => $e->getMessage(),
            ]);

            return [
                'running' => false,
                'worker_count' => 0,
                'pids' => [],
                'queue' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * API Endpoint: Create new newsletter campaign
     * POST /api/admin/newsletter/create
     */
    public function createCampaign(): void
    {
        $this->disableHttpCache();

        try {
            // Validate required fields
            $campaignName = trim($_POST['campaign_name'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $htmlBody = $_POST['html_body'] ?? '';
            $previewText = trim($_POST['preview_text'] ?? '');

            if (empty($campaignName) || empty($subject) || empty($htmlBody)) {
                $this->json(['success' => false, 'message' => 'Missing required fields'], 400);

                return;
            }

            // Get admin info
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            if (!$adminId || !$adminEmail) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 401);

                return;
            }

            // Get targeting options
            $targetAll = isset($_POST['target_all_users']) ? (int) $_POST['target_all_users'] : 0;
            $targetFilter = !empty($_POST['target_filter']) ? json_decode($_POST['target_filter'], true) : null;

            // Get priority
            $priority = $_POST['priority'] ?? 'normal';
            if (!in_array($priority, ['low', 'normal', 'high', 'urgent'])) {
                $priority = 'normal';
            }

            $pdo = $this->getFreshPDO();

            // Insert campaign
            $stmt = $pdo->prepare("
                INSERT INTO newsletters (
                    campaign_name, subject, preview_text, html_body,
                    created_by, created_by_email, target_all_users, target_filter,
                    status, priority, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW())
            ");

            $stmt->execute([
                $campaignName,
                $subject,
                $previewText,
                $htmlBody,
                $adminId,
                $adminEmail,
                $targetAll,
                $targetFilter ? json_encode($targetFilter) : null,
                $priority,
            ]);

            $campaignId = $pdo->lastInsertId();

            // Security audit log
            Logger::security('info', 'ADMIN: Newsletter campaign created', [
                'admin_id' => $adminId,
                'admin_email' => $adminEmail,
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'message' => 'Campaign created successfully',
                'campaign_id' => $campaignId,
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminNewsletterController: Failed to create campaign', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed to create campaign'], 500);
        }
    }

    /**
     * API Endpoint: Send or schedule newsletter
     * POST /api/admin/newsletter/send
     */
    public function sendCampaign(): void
    {
        $this->disableHttpCache();

        try {
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            $scheduledFor = $_POST['scheduled_for'] ?? null;

            if (!$campaignId) {
                $this->json(['success' => false, 'message' => 'Invalid campaign ID'], 400);

                return;
            }

            // Get admin info
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            if (!$adminId || !$adminEmail) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 401);

                return;
            }

            $pdo = $this->getFreshPDO();

            // Get campaign
            $stmt = $pdo->prepare("SELECT * FROM newsletters WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$campaign) {
                $this->json(['success' => false, 'message' => 'Campaign not found'], 404);

                return;
            }

            // Validate status
            if ($campaign['status'] !== 'draft' && $campaign['status'] !== 'scheduled') {
                $this->json(['success' => false, 'message' => 'Campaign cannot be sent (status: ' . $campaign['status'] . ')'], 400);

                return;
            }

            // Get target users
            $targetUsers = $this->getTargetUsers($campaign, $pdo);

            if (empty($targetUsers)) {
                $this->json(['success' => false, 'message' => 'No target users found'], 400);

                return;
            }

            // Update campaign status
            if ($scheduledFor) {
                // Schedule for later
                $stmt = $pdo->prepare("
                    UPDATE newsletters
                    SET status = 'scheduled', scheduled_for = ?, total_recipients = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$scheduledFor, count($targetUsers), $campaignId]);

                $this->json([
                    'success' => true,
                    'message' => 'Campaign scheduled successfully',
                    'scheduled_for' => $scheduledFor,
                    'total_recipients' => count($targetUsers),
                ]);
            } else {
                // Send immediately

                // ENTERPRISE GALAXY FIX: Reset metrics for re-send
                // When re-sending a campaign, delete old metrics to reset sent/failed counters
                // This ensures clean slate for accurate progress tracking
                $deleteMetrics = $pdo->prepare("DELETE FROM newsletter_metrics WHERE newsletter_id = ?");
                $deleteMetrics->execute([$campaignId]);

                Logger::email('info', 'AdminNewsletterController: Cleared old metrics for campaign re-send', [
                    'campaign_id' => $campaignId,
                    'deleted_rows' => $deleteMetrics->rowCount(),
                ]);

                // Reset campaign counters and update status
                $stmt = $pdo->prepare("
                    UPDATE newsletters
                    SET status = 'sending',
                        started_sending_at = NOW(),
                        completed_sending_at = NULL,
                        total_recipients = ?,
                        sent_count = 0,
                        failed_count = 0,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([count($targetUsers), $campaignId]);

                // ENTERPRISE GALAXY: Reset Redis atomic counters for re-send
                $campaignManager = new \Need2Talk\Services\NewsletterCampaignManager();
                $campaignManager->resetCounters($campaignId);

                // Queue emails via AdminEmailQueue
                $queue = new AdminEmailQueue();

                foreach ($targetUsers as $user) {
                    // ENTERPRISE GALAXY FIX: Generate unsubscribe token if missing (GDPR-compliant unsubscribe links)
                    // Token generation on-the-fly ensures every newsletter email has a valid unsubscribe link
                    // without modifying registration flow (avoids regressions)
                    if (empty($user['newsletter_unsubscribe_token'])) {
                        $unsubscribeToken = bin2hex(random_bytes(32)); // 64-char hexadecimal token

                        try {
                            $tokenStmt = $pdo->prepare("
                                UPDATE users
                                SET newsletter_unsubscribe_token = ?
                                WHERE id = ?
                            ");
                            $tokenStmt->execute([$unsubscribeToken, $user['id']]);
                            $user['newsletter_unsubscribe_token'] = $unsubscribeToken; // Update local array

                            Logger::email('info', 'AdminNewsletterController: Generated missing unsubscribe token', [
                                'user_id' => $user['id'],
                                'user_email' => $user['email'],
                            ]);
                        } catch (\PDOException $e) {
                            Logger::email('error', 'AdminNewsletterController: Failed to generate unsubscribe token', [
                                'user_id' => $user['id'],
                                'error' => $e->getMessage(),
                            ]);
                            continue; // Skip this user if token generation fails
                        }
                    }

                    // ENTERPRISE GALAXY: Create metrics entry FIRST (idempotent tracking)
                    // This ensures sent_count reflects ACTUAL emails, not queue artifacts
                    // ENTERPRISE FIX: Use 'sent' status (ENUM: sent, opened, clicked, bounced, unsubscribed, failed)
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO newsletter_metrics (
                                newsletter_id, user_id, recipient_email, sent_at, status, created_at
                            ) VALUES (?, ?, ?, NOW(), 'sent', NOW())
                            ON CONFLICT (newsletter_id, user_id) DO UPDATE SET sent_at = NOW(), status = 'sent'
                        ");
                        $stmt->execute([$campaignId, $user['id'], $user['email']]);

                        // ENTERPRISE GALAXY: Metrics row created/updated (idempotent)
                        // We'll count actual metrics from database after loop (rowCount unreliable)

                    } catch (\PDOException $e) {
                        // Skip duplicate/error users - LOG ONLY if not duplicate key error
                        if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                            Logger::email('error', 'AdminNewsletterController: Failed to create metrics', [
                                'campaign_id' => $campaignId,
                                'user_id' => $user['id'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                        continue;
                    }

                    // ENTERPRISE GALAXY: Generate tracking pixel URL (SHA256 hash for security)
                    // Hash format: SHA256(email:campaignId) - consistent with NewsletterTrackingController
                    $recipientHash = hash('sha256', $user['email'] . ':' . $campaignId);
                    $trackingPixelUrl = 'https://need2talk.it/newsletter/track/open/' . $campaignId . '/' . $recipientHash;

                    // Queue email job (queue count may differ from metrics due to Redis artifacts)
                    $jobId = $queue->enqueue([
                        'email_type' => 'newsletter',
                        'newsletter_id' => $campaignId, // ENTERPRISE GALAXY: Campaign tracking
                        'admin_id' => $adminId,
                        'admin_email' => $adminEmail, // ENTERPRISE FIX: AdminEmailQueue requires admin_email
                        'recipient_email' => $user['email'],
                        'recipient_user_id' => $user['id'],
                        'subject' => $campaign['subject'],
                        'template' => 'newsletter_enterprise_v2', // ENTERPRISE GALAXY V2: Deep purple gradient template
                        'template_data' => [
                            'campaign_id' => $campaignId,
                            'campaign_name' => $campaign['campaign_name'],
                            'subject' => $campaign['subject'],
                            'user_id' => $user['id'],
                            'user_email' => $user['email'],
                            'user_nickname' => $user['nickname'] ?? 'User',
                            'html_content' => $campaign['html_body'],
                            'unsubscribe_token' => $user['newsletter_unsubscribe_token'] ?? '',
                            'tracking_pixel_url' => $trackingPixelUrl, // ENTERPRISE GALAXY: Open tracking
                        ],
                        'priority' => $campaign['priority'] ?? 'normal',
                        'idempotency_key' => 'newsletter_' . $campaignId . '_user_' . $user['id'],
                        'additional_data' => [
                            'newsletter_id' => $campaignId,
                        ],
                    ]);
                }

                // ENTERPRISE GALAXY FIX: Query actual metrics count from database (for logging only)
                // ON CONFLICT (hour, email_type, action) DO UPDATE SET can return rowCount() = 2 even for INSERT!
                // ONLY trust direct COUNT query from database for accuracy
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as actual_count
                    FROM newsletter_metrics
                    WHERE newsletter_id = ?
                ");
                $stmt->execute([$campaignId]);
                $actualMetricsCount = (int) $stmt->fetchColumn();

                // ENTERPRISE GALAXY: DO NOT update sent_count here - it causes DOUBLE COUNTING!
                // NewsletterCampaignManager already increments sent_count for each email sent
                // This COUNT is only for logging/verification purposes

                // Security audit log
                Logger::security('warn', 'ADMIN: Newsletter sent', [
                    'admin_id' => $adminId,
                    'admin_email' => $adminEmail,
                    'campaign_id' => $campaignId,
                    'campaign_name' => $campaign['campaign_name'],
                    'recipients' => count($targetUsers),
                    'metrics_created' => $actualMetricsCount, // ENTERPRISE: Actual metrics count from database
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                $this->json([
                    'success' => true,
                    'message' => 'Newsletter queued successfully',
                    'total_recipients' => count($targetUsers),
                    'sent' => $actualMetricsCount, // ENTERPRISE GALAXY: Accurate sent count from database
                ]);
            }

        } catch (\Exception $e) {
            Logger::email('error', 'AdminNewsletterController: Failed to send campaign', [
                'campaign_id' => $campaignId ?? 0,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed to send campaign'], 500);
        }
    }

    /**
     * Get target users based on campaign filters
     */
    private function getTargetUsers(array $campaign, \PDO $pdo): array
    {
        if ($campaign['target_all_users']) {
            // Get all opted-in users
            // ENTERPRISE: PostgreSQL uses TRUE/FALSE for booleans, not 1/0
            $stmt = $pdo->query("
                SELECT id, email, nickname, newsletter_unsubscribe_token
                FROM users
                WHERE deleted_at IS NULL
                  AND newsletter_opt_in = TRUE
                  AND email_verified = TRUE
                ORDER BY id ASC
            ");
        } else {
            // Apply filters
            $filters = json_decode($campaign['target_filter'], true);
            // ENTERPRISE: PostgreSQL uses TRUE/FALSE for booleans, not 1/0
            $where = ["deleted_at IS NULL", "newsletter_opt_in = TRUE", "email_verified = TRUE"];

            if (!empty($filters['status'])) {
                $where[] = "status = '" . $pdo->quote($filters['status']) . "'";
            }

            if (!empty($filters['verified_only'])) {
                $where[] = "email_verified = TRUE";
            }

            $sql = "
                SELECT id, email, nickname, newsletter_unsubscribe_token
                FROM users
                WHERE " . implode(' AND ', $where) . "
                ORDER BY id ASC
            ";

            $stmt = $pdo->query($sql);
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * API Endpoint: Get newsletter statistics
     * GET /api/admin/newsletter/stats?campaign_id={id|all}
     *
     * ENTERPRISE GALAXY: Handles both individual campaign and aggregate stats
     */
    public function getStats(): void
    {
        $this->disableHttpCache();

        try {
            $campaignIdParam = $_GET['campaign_id'] ?? '';

            // ENTERPRISE TIPS: Handle campaign_id=all for aggregate stats
            if ($campaignIdParam === 'all') {
                $this->json([
                    'success' => true,
                    'mode' => 'all',
                    'dashboard' => $this->getNewsletterDashboard(),
                    'campaigns' => $this->getCampaignsList(),
                    'timestamp' => date('c'),
                ]);

                return;
            }

            $campaignId = (int) $campaignIdParam;

            if (!$campaignId) {
                $this->json(['success' => false, 'message' => 'Invalid campaign ID (use numeric ID or "all")'], 400);

                return;
            }

            $pdo = $this->getFreshPDO();

            // Get campaign
            $stmt = $pdo->prepare("SELECT * FROM newsletters WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$campaign) {
                $this->json(['success' => false, 'message' => 'Campaign not found'], 404);

                return;
            }

            // ENTERPRISE GALAXY: Get detailed metrics from tracking system
            // opened_at/clicked_at populated by NewsletterTrackingController (pixel/click tracking)
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) as total_metrics,
                    SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened_count,
                    SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count,
                    SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced_count,
                    SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) as unsubscribed_count,
                    SUM(open_count) as total_opens,
                    SUM(click_count) as total_clicks,
                    COUNT(DISTINCT CASE WHEN device_type IS NOT NULL THEN device_type END) as unique_devices
                FROM newsletter_metrics
                WHERE newsletter_id = ?
            ");
            $stmt->execute([$campaignId]);
            $metrics = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Device breakdown
            $stmt = $pdo->prepare("
                SELECT device_type, COUNT(*) as count
                FROM newsletter_metrics
                WHERE newsletter_id = ? AND device_type IS NOT NULL
                GROUP BY device_type
            ");
            $stmt->execute([$campaignId]);
            $deviceBreakdown = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json([
                'success' => true,
                'campaign' => $campaign,
                'metrics' => $metrics,
                'device_breakdown' => $deviceBreakdown,
                'timestamp' => date('c'),
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminNewsletterController: Failed to get stats', [
                'campaign_id' => $campaignId ?? 0,
                'error' => $e->getMessage(),
            ]);

            $this->json(['success' => false, 'message' => 'Failed to get statistics'], 500);
        }
    }

    /**
     * API Endpoint: Upload image for newsletter
     * POST /api/newsletter/upload-image
     *
     * ENTERPRISE GALAXY: Image upload for TinyMCE editor
     * - Validates admin session
     * - Validates file type (jpg, png, gif, webp)
     * - Validates file size (max 2MB)
     * - Stores in /public/assets/uploads/newsletter/
     * - Returns JSON with image URL
     */
    public function uploadImage(): void
    {
        $this->disableHttpCache();

        try {
            // Validate admin session
            $adminId = $_SESSION['admin_user_id'] ?? 0;
            $adminEmail = $_SESSION['admin_email'] ?? '';

            if (!$adminId || !$adminEmail) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 401);

                return;
            }

            // Check if file was uploaded
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $this->json(['success' => false, 'message' => 'No file uploaded or upload error'], 400);

                return;
            }

            $file = $_FILES['image'];

            // Validate file size (max 2MB)
            $maxSize = 2 * 1024 * 1024; // 2MB in bytes
            if ($file['size'] > $maxSize) {
                $this->json(['success' => false, 'message' => 'File too large. Maximum size is 2MB'], 400);

                return;
            }

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes)) {
                $this->json(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, GIF, WebP'], 400);

                return;
            }

            // SECURITY FIX V6.1: Validate image content with getimagesize()
            // Prevents PHP code embedded in EXIF/comments
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                Logger::security('warning', 'ADMIN: Newsletter image upload rejected - invalid image content', [
                    'admin_id' => $adminId,
                    'original_filename' => $file['name'],
                    'mime_type' => $mimeType,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                $this->json(['success' => false, 'message' => 'Invalid image file - content validation failed'], 400);

                return;
            }

            // SECURITY FIX V6.1: Force safe extension based on MIME type (prevents double extension attacks)
            // NEVER trust user-provided filename extension
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            $safeExtension = $mimeToExt[$mimeType] ?? 'jpg';
            $filename = 'newsletter_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $safeExtension;

            // Create upload directory if it doesn't exist
            $uploadDir = dirname(__DIR__, 2) . '/public/assets/uploads/newsletter';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // SECURITY FIX V6.1: Path traversal prevention with realpath validation
            $uploadPath = $uploadDir . '/' . $filename;
            $realUploadDir = realpath($uploadDir);
            $realUploadPath = $realUploadDir . '/' . $filename;

            // Verify path is within expected directory
            if ($realUploadDir === false || strpos($realUploadPath, $realUploadDir) !== 0) {
                Logger::security('critical', 'ADMIN: Path traversal attempt in newsletter image upload', [
                    'admin_id' => $adminId,
                    'attempted_path' => $uploadPath,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                $this->json(['success' => false, 'message' => 'Security error - invalid path'], 400);

                return;
            }

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $realUploadPath)) {
                $this->json(['success' => false, 'message' => 'Failed to save uploaded file'], 500);

                return;
            }

            // ENTERPRISE TIPS: Generate ABSOLUTE URL for TinyMCE compatibility
            // TinyMCE may not render relative URLs in some configurations
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            // 12 FACTOR APP: Use environment config instead of hardcoded IP
            $host = $_SERVER['HTTP_HOST'] ?? env('SERVER_IP', 'YOUR_SERVER_IP');
            $imageUrl = $protocol . '://' . $host . '/assets/uploads/newsletter/' . $filename;

            // Security audit log
            Logger::security('info', 'ADMIN: Newsletter image uploaded', [
                'admin_id' => $adminId,
                'admin_email' => $adminEmail,
                'filename' => $filename,
                'size' => $file['size'],
                'mime_type' => $mimeType,
                'url' => $imageUrl,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $this->json([
                'success' => true,
                'image_url' => $imageUrl,
                'filename' => $filename,
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'AdminNewsletterController: Failed to upload image', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->json(['success' => false, 'message' => 'Upload failed'], 500);
        }
    }

    /**
     * Create fresh PDO connection bypassing all cache layers
     * ENTERPRISE: Guarantees real-time data
     */
    private function getFreshPDO(): \PDO
    {
        $dsn = 'pgsql:host=' . env('DB_HOST', 'postgres') . ';port=' . env('DB_PORT', '5432') .
               ';dbname=' . env('DB_DATABASE', 'need2talk');

        return new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', 'root'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Disable HTTP caching for real-time data
     */
    private function disableHttpCache(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
