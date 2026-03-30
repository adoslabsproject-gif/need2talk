<?php

namespace Need2Talk\Services;

use Need2Talk\Core\Database;

/**
 * NEED2TALK - REPORT EMAIL SERVICE
 *
 * ENTERPRISE GALAXY ARCHITECTURE:
 * - Dedicated service for report email handling
 * - Integrates with AsyncEmailQueue for async processing
 * - Comprehensive metrics tracking (aligned with verification/password_reset pattern)
 * - Rate limiting (1 report/day per email)
 * - Duplicate detection via content hash
 * - Retry logic with exponential backoff
 *
 * METRICS TRACKING PHILOSOPHY:
 * - Track only FINAL states (sent/failed) in aggregate metrics (hourly/daily)
 * - Queue state tracked in report_email_performance table (real-time monitoring)
 * - Consistent with verification/password_reset email patterns
 * - Simplifies analytics: focus on delivery rate, not intermediate states
 *
 * SCALABILITY:
 * - Designed for 100k+ concurrent users
 * - Optimized database queries with indexes
 * - Async processing prevents blocking
 * - Connection pooling via Database singleton
 *
 * USAGE:
 * $service = new ReportEmailService();
 * $success = $service->submitReport([
 *     'type' => 'technical',
 *     'email' => 'user@example.com',
 *     'description' => '...',
 *     'ip' => $_SERVER['REMOTE_ADDR']
 * ]);
 *
 * @package Need2Talk\Services
 * @author Claude AI (Anthropic)
 * @version 2.0.0 - Aligned metrics with enterprise pattern (removed queued tracking)
 */
class ReportEmailService
{
    /**
     * @var Database Database instance for metrics
     */
    private Database $db;

    /**
     * @var AsyncEmailQueue Email queue service
     */
    private AsyncEmailQueue $emailQueue;

    /**
     * Rate limit: 1 report per day per email
     */
    private const RATE_LIMIT_HOURS = 24;

    /**
     * Maximum reports per IP per day (abuse prevention)
     */
    private const MAX_REPORTS_PER_IP = 5;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = db();
        $this->emailQueue = new AsyncEmailQueue();
    }

    /**
     * Submit a report and queue email notification
     *
     * @param array $reportData Report data with keys: type, email, description, ip, etc.
     * @return array ['success' => bool, 'message' => string, 'report_id' => int|null]
     */
    public function submitReport(array $reportData): array
    {
        try {
            // Validate required fields
            $validation = $this->validateReportData($reportData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['error'],
                    'report_id' => null,
                ];
            }

            // Check rate limiting
            $rateLimitCheck = $this->checkRateLimit($reportData['email'], $reportData['ip']);
            if (!$rateLimitCheck['allowed']) {
                Logger::security('warning', 'Report rate limit exceeded', [
                    'email' => $reportData['email'],
                    'ip' => $reportData['ip'],
                    'reason' => $rateLimitCheck['reason'],
                ]);

                return [
                    'success' => false,
                    'message' => $rateLimitCheck['message'],
                    'report_id' => null,
                ];
            }

            // Generate content hash for duplicate detection
            $contentHash = $this->generateContentHash($reportData);

            // Check for duplicate in last 24 hours
            if ($this->isDuplicateReport($contentHash, $reportData['email'])) {
                Logger::info('Duplicate report detected', [
                    'email' => $reportData['email'],
                    'content_hash' => $contentHash,
                ]);

                return [
                    'success' => false,
                    'message' => 'Hai già inviato una segnalazione identica nelle ultime 24 ore.',
                    'report_id' => null,
                ];
            }

            // Create metrics entry
            $reportId = $this->createMetricsEntry($reportData, $contentHash);

            if (!$reportId) {
                throw new \Exception('Failed to create metrics entry');
            }

            // Queue email notification (status updated inside)
            $this->queueEmailNotification($reportData, $reportId);

            Logger::info('Report submitted successfully', [
                'report_id' => $reportId,
                'type' => $reportData['type'],
                'email' => $reportData['email'],
            ]);

            return [
                'success' => true,
                'message' => 'Segnalazione inviata con successo. Riceverai una conferma via email.',
                'report_id' => $reportId,
            ];

        } catch (\Exception $e) {
            Logger::error('Report submission failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Errore durante l\'invio della segnalazione. Riprova più tardi.',
                'report_id' => null,
            ];
        }
    }

    /**
     * Validate report data
     *
     * @param array $data Report data
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private function validateReportData(array $data): array
    {
        // Required fields
        $required = ['type', 'email', 'description', 'ip'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return [
                    'valid' => false,
                    'error' => "Campo obbligatorio mancante: {$field}",
                ];
            }
        }

        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'valid' => false,
                'error' => 'Email non valida',
            ];
        }

        // Validate report type
        $validTypes = [
            'technical', 'bug', 'security', 'abuse', 'content',
            'performance', 'feature_request', 'general', 'other',
        ];

        if (!in_array($data['type'], $validTypes)) {
            return [
                'valid' => false,
                'error' => 'Tipo di segnalazione non valido',
            ];
        }

        // Validate description length
        $description = trim($data['description']);
        if (strlen($description) < 20) {
            return [
                'valid' => false,
                'error' => 'La descrizione deve contenere almeno 20 caratteri',
            ];
        }

        if (strlen($description) > 2000) {
            return [
                'valid' => false,
                'error' => 'La descrizione non può superare 2000 caratteri',
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Check rate limiting
     *
     * @param string $email Reporter email
     * @param string $ip Reporter IP
     * @return array ['allowed' => bool, 'reason' => string|null, 'message' => string|null]
     */
    private function checkRateLimit(string $email, string $ip): array
    {
        $db = db_pdo();

        // Check email rate limit (1 per day)
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM report_email_performance
            WHERE reporter_email = :email
            AND created_at > NOW() - INTERVAL '1 hour' * :hours
        ");
        $stmt->execute([
            'email' => $email,
            'hours' => self::RATE_LIMIT_HOURS,
        ]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return [
                'allowed' => false,
                'reason' => 'email_rate_limit',
                'message' => 'Puoi inviare solo 1 segnalazione ogni 24 ore. Riprova domani.',
            ];
        }

        // Check IP rate limit (max 5 per day - abuse prevention)
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM report_email_performance
            WHERE reporter_ip = :ip
            AND created_at > NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute(['ip' => $ip]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result['count'] >= self::MAX_REPORTS_PER_IP) {
            return [
                'allowed' => false,
                'reason' => 'ip_rate_limit',
                'message' => 'Limite giornaliero di segnalazioni raggiunto per questo IP.',
            ];
        }

        return ['allowed' => true, 'reason' => null, 'message' => null];
    }

    /**
     * Generate content hash for duplicate detection
     *
     * @param array $data Report data
     * @return string SHA256 hash
     */
    private function generateContentHash(array $data): string
    {
        $content = implode('|', [
            $data['type'],
            $data['email'],
            trim(strtolower($data['description'])),
        ]);

        return hash('sha256', $content);
    }

    /**
     * Check if report is duplicate
     *
     * @param string $contentHash Content hash
     * @param string $email Reporter email
     * @return bool True if duplicate found
     */
    private function isDuplicateReport(string $contentHash, string $email): bool
    {
        $db = db_pdo();

        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM report_email_performance
            WHERE content_hash = :hash
            AND reporter_email = :email
            AND created_at > NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute([
            'hash' => $contentHash,
            'email' => $email,
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result['count'] > 0;
    }

    /**
     * Create metrics entry in database
     *
     * @param array $data Report data
     * @param string $contentHash Content hash
     * @return int|false Report ID or false on failure
     */
    private function createMetricsEntry(array $data, string $contentHash)
    {
        $db = db_pdo();

        $stmt = $db->prepare("
            INSERT INTO report_email_performance (
                report_type,
                reporter_email,
                reporter_ip,
                content_hash,
                status,
                attempts,
                created_at
            ) VALUES (
                :type,
                :email,
                :ip,
                :hash,
                'pending',
                0,
                NOW()
            )
        ");

        $success = $stmt->execute([
            'type' => $data['type'],
            'email' => $data['email'],
            'ip' => $data['ip'],
            'hash' => $contentHash,
        ]);

        return $success ? $db->lastInsertId() : false;
    }

    /**
     * Queue email notification via AsyncEmailQueue
     *
     * @param array $data Report data
     * @param int $reportId Report ID
     * @return void
     */
    private function queueEmailNotification(array $data, int $reportId): void
    {
        // Prepare email template data
        $templateData = [
            'report_id' => $reportId,
            'report_type' => $data['type'],
            'reporter_email' => $data['email'],
            'description' => $data['description'],
            'content_url' => $data['content_url'] ?? null,
            'evidence' => $data['evidence'] ?? null,
            'submitted_at' => date('d/m/Y H:i:s'),
        ];

        // Admin notification email
        $adminEmail = env('ADMIN_EMAIL', 'support@need2talk.it');
        $subject = "[REPORT #{$reportId}] Nuova segnalazione: " . ucfirst($data['type']);

        // Queue email to admin
        $result = $this->emailQueue->queueEmail([
            'email' => $adminEmail,
            'subject' => $subject,
            'type' => 'report',  // Normalized for metrics
            'user_id' => null,  // No user ID for site-wide reports
            'template_data' => [
                'body' => $this->renderEmailTemplate($templateData),
            ],
            'metadata' => [
                'report_id' => $reportId,
                'report_email_type' => 'admin_notification',
                'skip_verification_metrics' => true,  // Don't try to record in email_verification_metrics
            ],
        ]);

        // ENTERPRISE: Update status to 'queued' (tracked in report_email_performance only)
        // Aggregate metrics (hourly/daily) track only FINAL states (sent/failed)
        if ($result) {
            $this->updateMetricsStatus($reportId, 'queued');

            Logger::email('info', 'Report notification email queued', [
                'report_id' => $reportId,
                'to' => $adminEmail,
                'type' => $data['type'],
                'reporter' => $data['email'],
                'metrics_note' => 'Queued state in report_email_performance, will update aggregate metrics on send/fail',
            ]);
        }

        // Queue confirmation email to reporter
        $this->queueConfirmationEmail($data['email'], $reportId, $data['type']);
    }

    /**
     * Queue confirmation email to reporter
     *
     * @param string $email Reporter email
     * @param int $reportId Report ID
     * @param string $type Report type
     * @return void
     */
    private function queueConfirmationEmail(string $email, int $reportId, string $type): void
    {
        $subject = "Conferma ricezione segnalazione #{$reportId}";
        $body = $this->renderConfirmationTemplate($reportId, $type);

        $result = $this->emailQueue->queueEmail([
            'email' => $email,
            'subject' => $subject,
            'type' => 'report',  // Normalized for metrics
            'user_id' => null,  // No user ID for site-wide reports
            'template_data' => [
                'body' => $body,
            ],
            'metadata' => [
                'report_id' => $reportId,
                'report_type' => $type,
                'report_email_type' => 'user_confirmation',
                'skip_verification_metrics' => true,  // Don't try to record in email_verification_metrics
            ],
        ]);

        // ENTERPRISE: No aggregate metrics update on queue (only on send/fail)
        if ($result) {
            Logger::email('info', 'Report confirmation email queued', [
                'report_id' => $reportId,
                'to' => $email,
                'type' => $type,
                'metrics_note' => 'Aggregate metrics will be updated by worker on send/fail',
            ]);
        }
    }

    /**
     * Update metrics status (PUBLIC for worker callback)
     *
     * @param int $reportId Report ID
     * @param string $status New status
     * @param string|null $errorMessage Error message if failed
     * @return void
     */
    public function updateMetricsStatus(int $reportId, string $status, ?string $errorMessage = null): void
    {
        $db = db_pdo();

        $fields = ['status = :status', 'updated_at = NOW()'];
        $params = ['id' => $reportId, 'status' => $status];

        if ($status === 'sent') {
            $fields[] = 'sent_at = NOW()';
        } elseif ($status === 'failed') {
            $fields[] = 'failed_at = NOW()';
            $fields[] = 'error_message = :error';
            $params['error'] = $errorMessage;
        }

        $sql = "UPDATE report_email_performance SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Update email metrics (hourly/daily) after email send attempt
     * PUBLIC STATIC for worker callback
     *
     * ENTERPRISE PATTERN: Track only FINAL states (sent/failed)
     * - Consistent with verification/password_reset email metrics
     * - Simplifies analytics: delivery rate = sent / (sent + failed)
     * - Queue state tracked in report_email_performance table
     *
     * @param int $reportId Report ID
     * @param float $sendDurationMs Send duration in milliseconds
     * @param string $workerId Worker ID that processed the email
     * @return void
     */
    public static function updateEmailMetrics(int $reportId, float $sendDurationMs, string $workerId): void
    {
        try {
            $db = db_pdo();

            // ENTERPRISE: Calculate queue_duration_ms (time from created_at to NOW)
            $stmt = $db->prepare("
                SELECT EXTRACT(EPOCH FROM (NOW() - created_at)) * 1000 AS queue_duration_ms
                FROM report_email_performance
                WHERE id = :id
            ");
            $stmt->execute(['id' => $reportId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $queueDurationMs = $result ? (int)round($result['queue_duration_ms']) : 0;

            // Update report_email_performance with FULL enterprise metrics
            $stmt = $db->prepare("
                UPDATE report_email_performance
                SET status = 'sent',
                    sent_at = NOW(),
                    queue_duration_ms = :queue_duration,
                    send_duration_ms = :send_duration,
                    worker_id = :worker_id,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $reportId,
                'queue_duration' => $queueDurationMs,
                'send_duration' => (int)round($sendDurationMs),  // Cast to INT for PostgreSQL
                'worker_id' => $workerId,
            ]);

            // ENTERPRISE: Update aggregate metrics - HOURLY
            $hour = date('Y-m-d H:00:00');
            $stmt = $db->prepare("
                INSERT INTO email_metrics_hourly (hour, email_type, action, count, created_at, updated_at)
                VALUES (:hour, 'report', 'sent', 1, NOW(), NOW())
                ON CONFLICT (hour, email_type, action) DO UPDATE SET count = email_metrics_hourly.count + 1, updated_at = NOW()
            ");
            $stmt->execute(['hour' => $hour]);

            // ENTERPRISE: Update aggregate metrics - DAILY
            $day = date('Y-m-d');
            $stmt = $db->prepare("
                INSERT INTO email_metrics_daily (day, email_type, action, count, created_at, updated_at)
                VALUES (:day, 'report', 'sent', 1, NOW(), NOW())
                ON CONFLICT (day, email_type, action) DO UPDATE SET count = email_metrics_daily.count + 1, updated_at = NOW()
            ");
            $stmt->execute(['day' => $day]);

            Logger::email('info', 'ENTERPRISE: Report email metrics updated (sent)', [
                'report_id' => $reportId,
                'worker_id' => $workerId,
                'queue_duration_ms' => $queueDurationMs,
                'send_duration_ms' => $sendDurationMs,
                'hourly_metric' => $hour,
                'daily_metric' => $day,
                'pattern' => 'Final state tracking (consistent with verification/password_reset)',
            ]);

        } catch (\Exception $e) {
            Logger::email('error', 'Failed to update report email metrics', [
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Render email template for admin notification
     *
     * @param array $data Template data
     * @return string Rendered HTML
     */
    private function renderEmailTemplate(array $data): string
    {
        ob_start();
        extract($data);
        require APP_ROOT . '/app/Views/emails/report-notification.php';

        return ob_get_clean();
    }

    /**
     * Render confirmation email template
     *
     * @param int $reportId Report ID
     * @param string $type Report type
     * @return string Rendered HTML
     */
    private function renderConfirmationTemplate(int $reportId, string $type): string
    {
        ob_start();
        require APP_ROOT . '/app/Views/emails/report-confirmation.php';

        return ob_get_clean();
    }
}
