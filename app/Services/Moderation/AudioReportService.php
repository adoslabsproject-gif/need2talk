<?php

namespace Need2Talk\Services\Moderation;

use Need2Talk\Services\Logger;
use Need2Talk\Services\Email\SendGridMailer;

/**
 * AudioReportService - Enterprise Audio Report Management
 *
 * Handles the complete lifecycle of audio post reports:
 * - View pending reports with author risk scoring
 * - Review reports with multiple resolution actions
 * - Send warning emails directly (no queue for immediate delivery)
 * - Soft delete (hide) content
 * - Track user warning history
 * - Automatic risk scoring based on report history
 *
 * ENTERPRISE FEATURES:
 * - Direct email sending (no worker queue) for immediate warnings
 * - Risk-based prioritization (high-risk users surfaced first)
 * - Complete audit trail with moderation_actions_log
 * - User report stats tracking (warnings, reports, bans)
 *
 * @package Need2Talk\Services\Moderation
 */
class AudioReportService
{
    // Warning email templates
    private const WARNING_TEMPLATES = [
        'content_report' => [
            'subject' => '[need2talk] Avviso sul tuo contenuto',
            'severity' => 'warning',
        ],
        'spam' => [
            'subject' => '[need2talk] Avviso: Contenuto spam rilevato',
            'severity' => 'warning',
        ],
        'harassment' => [
            'subject' => '[need2talk] Avviso importante: Comportamento inappropriato',
            'severity' => 'final_warning',
        ],
        'hate_speech' => [
            'subject' => '[need2talk] Violazione grave delle linee guida',
            'severity' => 'final_warning',
        ],
        'copyright' => [
            'subject' => '[need2talk] Violazione copyright sul tuo contenuto',
            'severity' => 'warning',
        ],
        'sexual_content' => [
            'subject' => '[need2talk] Contenuto inappropriato rimosso',
            'severity' => 'final_warning',
        ],
        'violence' => [
            'subject' => '[need2talk] Contenuto violento rimosso',
            'severity' => 'final_warning',
        ],
    ];

    /**
     * Get pending reports with full context
     *
     * @param int $limit Max reports to return
     * @param int $offset Pagination offset
     * @param array $filters Optional filters: status, is_escalated, min_risk_score
     * @return array Reports with audio and user info
     */
    public function getPendingReports(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        try {
            $pdo = db_pdo();

            $where = ['1=1'];
            $params = [];

            // Status filter (default: pending)
            $status = $filters['status'] ?? 'pending';
            if ($status !== 'all') {
                $where[] = 'ar.status = :status';
                $params['status'] = $status;
            }

            // Escalated filter
            if (isset($filters['is_escalated']) && $filters['is_escalated']) {
                $where[] = 'ar.is_escalated = TRUE';
            }

            // Min risk score filter
            if (isset($filters['min_risk_score'])) {
                $where[] = 'COALESCE(urs.risk_score, 0) >= :min_risk';
                $params['min_risk'] = (int) $filters['min_risk_score'];
            }

            $whereClause = implode(' AND ', $where);

            // ENTERPRISE GALAXY: LIMIT/OFFSET must be integers in PostgreSQL
            // Using direct interpolation for these safe integer values
            $sql = "
                SELECT
                    ar.id AS report_id,
                    ar.uuid AS report_uuid,
                    ar.reason,
                    ar.description AS report_description,
                    ar.status,
                    ar.is_escalated,
                    ar.created_at AS reported_at,
                    ar.reviewed_at,
                    ar.resolution_action,
                    ar.moderator_notes,

                    -- Audio info
                    af.id AS audio_id,
                    af.uuid AS audio_uuid,
                    af.title AS audio_title,
                    af.description AS audio_description,
                    af.file_path AS audio_file_path,
                    af.report_count,
                    af.is_hidden,
                    af.created_at AS audio_created_at,

                    -- Author info
                    author.id AS author_id,
                    author.uuid AS author_uuid,
                    author.nickname AS author_nickname,
                    author.email AS author_email,

                    -- Reporter info
                    reporter.id AS reporter_id,
                    reporter.uuid AS reporter_uuid,
                    reporter.nickname AS reporter_nickname,

                    -- Author risk scoring
                    COALESCE(urs.risk_score, 0) AS author_risk_score,
                    COALESCE(urs.warnings_sent, 0) AS author_warnings_count,
                    COALESCE(urs.total_reports_received, 0) AS author_total_reports,
                    COALESCE(urs.reports_actioned, 0) AS author_reports_actioned,

                    -- Reviewer info (if reviewed)
                    m.username AS reviewed_by_username

                FROM audio_reports ar
                JOIN audio_files af ON af.id = ar.audio_file_id
                JOIN users author ON author.id = af.user_id
                JOIN users reporter ON reporter.id = ar.reporter_user_id
                LEFT JOIN user_report_stats urs ON urs.user_id = author.id
                LEFT JOIN moderators m ON m.id = ar.reviewed_by_moderator_id
                WHERE {$whereClause}
                ORDER BY ar.is_escalated DESC, COALESCE(urs.risk_score, 0) DESC, ar.created_at ASC
                LIMIT " . (int) $limit . " OFFSET " . (int) $offset . "
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $reports = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get total count
            $countSql = "
                SELECT COUNT(*) as total
                FROM audio_reports ar
                LEFT JOIN user_report_stats urs ON urs.user_id = (
                    SELECT user_id FROM audio_files WHERE id = ar.audio_file_id
                )
                WHERE {$whereClause}
            ";
            unset($params['limit'], $params['offset']);
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            return [
                'success' => true,
                'reports' => $reports,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ];
        } catch (\Exception $e) {
            Logger::error('Failed to get pending reports', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get report statistics for dashboard
     *
     * @return array Stats: pending, reviewed, actioned, dismissed, escalated
     */
    public function getReportStats(): array
    {
        try {
            $pdo = db_pdo();

            $sql = "
                SELECT
                    COUNT(*) FILTER (WHERE status = 'pending') AS pending,
                    COUNT(*) FILTER (WHERE status = 'reviewed') AS reviewed,
                    COUNT(*) FILTER (WHERE status = 'action_taken') AS actioned,
                    COUNT(*) FILTER (WHERE status = 'dismissed') AS dismissed,
                    COUNT(*) FILTER (WHERE is_escalated = TRUE AND status = 'pending') AS escalated,
                    COUNT(*) AS total
                FROM audio_reports
                WHERE created_at > NOW() - INTERVAL '30 days'
            ";

            $stmt = $pdo->query($sql);
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Get high-risk users count
            $riskSql = "SELECT COUNT(*) FROM user_report_stats WHERE risk_score >= 50";
            $stats['high_risk_users'] = (int) $pdo->query($riskSql)->fetchColumn();

            return ['success' => true, 'stats' => $stats];
        } catch (\Exception $e) {
            Logger::error('Failed to get report stats', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Review a report with resolution action
     *
     * @param int $reportId Report ID
     * @param int $moderatorId Moderator performing the review
     * @param string $status New status: 'reviewed', 'action_taken', 'dismissed'
     * @param string $resolutionAction Action taken (from report_resolution_action enum)
     * @param string|null $moderatorNotes Internal notes
     * @return array Result
     */
    public function reviewReport(
        int $reportId,
        int $moderatorId,
        string $status,
        string $resolutionAction,
        ?string $moderatorNotes = null
    ): array {
        try {
            $pdo = db_pdo();
            $pdo->beginTransaction();

            // Get report details
            $stmt = $pdo->prepare("
                SELECT ar.*, af.user_id AS author_id, af.id AS audio_id, af.title AS audio_title,
                       u.email AS author_email, u.nickname AS author_nickname
                FROM audio_reports ar
                JOIN audio_files af ON af.id = ar.audio_file_id
                JOIN users u ON u.id = af.user_id
                WHERE ar.id = :id
                FOR UPDATE
            ");
            $stmt->execute(['id' => $reportId]);
            $report = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$report) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Report not found'];
            }

            // Update report
            $updateStmt = $pdo->prepare("
                UPDATE audio_reports
                SET status = :status,
                    resolution_action = :resolution_action,
                    moderator_notes = :notes,
                    reviewed_by_moderator_id = :moderator_id,
                    reviewed_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                'id' => $reportId,
                'status' => $status,
                'resolution_action' => $resolutionAction,
                'notes' => $moderatorNotes,
                'moderator_id' => $moderatorId,
            ]);

            // Log action
            ModerationSecurityService::logModerationAction(
                $moderatorId,
                'review_report',
                $report['author_id'],
                null,
                [
                    'report_id' => $reportId,
                    'status' => $status,
                    'resolution_action' => $resolutionAction,
                    'audio_id' => $report['audio_id'],
                ]
            );

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Report reviewed successfully',
                'report' => $report,
            ];
        } catch (\Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Failed to review report', ['error' => $e->getMessage(), 'report_id' => $reportId]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Hide (soft delete) an audio post
     *
     * @param int $audioId Audio file ID
     * @param int $moderatorId Moderator performing the action
     * @param string $reason Reason for hiding
     * @return array Result
     */
    public function hideAudioPost(int $audioId, int $moderatorId, string $reason): array
    {
        try {
            $pdo = db_pdo();

            // Get audio details
            $stmt = $pdo->prepare("
                SELECT af.*, u.id AS author_id, u.email AS author_email, u.nickname AS author_nickname
                FROM audio_files af
                JOIN users u ON u.id = af.user_id
                WHERE af.id = :id
            ");
            $stmt->execute(['id' => $audioId]);
            $audio = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$audio) {
                return ['success' => false, 'error' => 'Audio not found'];
            }

            // Soft delete
            $updateStmt = $pdo->prepare("
                UPDATE audio_files
                SET is_hidden = TRUE,
                    hidden_at = NOW(),
                    hidden_by_moderator_id = :moderator_id,
                    hidden_reason = :reason,
                    moderation_status = 'rejected'
                WHERE id = :id
            ");
            $updateStmt->execute([
                'id' => $audioId,
                'moderator_id' => $moderatorId,
                'reason' => $reason,
            ]);

            // Update user stats
            $pdo->prepare("
                UPDATE user_report_stats
                SET posts_hidden = posts_hidden + 1, updated_at = NOW()
                WHERE user_id = :user_id
            ")->execute(['user_id' => $audio['author_id']]);

            // Log action
            ModerationSecurityService::logModerationAction(
                $moderatorId,
                'hide_content',
                $audio['author_id'],
                null,
                [
                    'audio_id' => $audioId,
                    'audio_title' => $audio['title'],
                    'reason' => $reason,
                ]
            );

            Logger::security('info', 'Audio post hidden by moderator', [
                'audio_id' => $audioId,
                'moderator_id' => $moderatorId,
                'author_id' => $audio['author_id'],
            ]);

            return [
                'success' => true,
                'message' => 'Audio post hidden successfully',
                'audio' => $audio,
            ];
        } catch (\Exception $e) {
            Logger::error('Failed to hide audio post', ['error' => $e->getMessage(), 'audio_id' => $audioId]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Unhide (restore) an audio post
     *
     * @param int $audioId Audio file ID
     * @param int $moderatorId Moderator performing the action
     * @return array Result
     */
    public function unhideAudioPost(int $audioId, int $moderatorId): array
    {
        try {
            $pdo = db_pdo();

            $stmt = $pdo->prepare("
                UPDATE audio_files
                SET is_hidden = FALSE,
                    hidden_at = NULL,
                    hidden_by_moderator_id = NULL,
                    hidden_reason = NULL,
                    moderation_status = 'approved'
                WHERE id = :id
                RETURNING user_id, title
            ");
            $stmt->execute(['id' => $audioId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result) {
                return ['success' => false, 'error' => 'Audio not found'];
            }

            // Log action
            ModerationSecurityService::logModerationAction(
                $moderatorId,
                'hide_content', // Using same action type with details
                $result['user_id'],
                null,
                [
                    'audio_id' => $audioId,
                    'action' => 'unhide',
                    'audio_title' => $result['title'],
                ]
            );

            return ['success' => true, 'message' => 'Audio post restored successfully'];
        } catch (\Exception $e) {
            Logger::error('Failed to unhide audio post', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send warning email to user - DIRECT SEND (no queue)
     *
     * @param int $userId User to warn
     * @param string $warningType Type of warning (content_report, spam, harassment, etc.)
     * @param int $moderatorId Moderator sending the warning
     * @param array $context Additional context: audio_id, report_id, custom_message
     * @return array Result
     */
    public function sendWarningEmail(
        int $userId,
        string $warningType,
        int $moderatorId,
        array $context = []
    ): array {
        try {
            $pdo = db_pdo();

            // Get user details
            $stmt = $pdo->prepare("SELECT id, email, nickname FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                return ['success' => false, 'error' => 'User not found'];
            }

            // Get template
            $template = self::WARNING_TEMPLATES[$warningType] ?? self::WARNING_TEMPLATES['content_report'];
            $subject = $template['subject'];
            $severity = $template['severity'];

            // Build email body
            $body = $this->buildWarningEmailBody($user, $warningType, $severity, $context);

            // DIRECT SEND - No queue for immediate delivery
            $mailer = new SendGridMailer();
            $sent = $mailer->send(
                $user['email'],
                $subject,
                $body,
                [
                    'from_email' => 'moderation@need2talk.it',
                    'from_name' => 'need2talk Moderation Team',
                ]
            );

            if (!$sent) {
                Logger::error('Failed to send warning email', [
                    'user_id' => $userId,
                    'email' => $user['email'],
                    'warning_type' => $warningType,
                ]);
                return ['success' => false, 'error' => 'Failed to send email'];
            }

            // Record in user_warning_emails
            $insertStmt = $pdo->prepare("
                INSERT INTO user_warning_emails (
                    user_id, warning_type, severity, email_subject, email_body,
                    related_audio_id, related_report_id, sent_by_moderator_id, delivery_status
                ) VALUES (
                    :user_id, :warning_type, :severity, :subject, :body,
                    :audio_id, :report_id, :moderator_id, 'sent'
                )
            ");
            $insertStmt->execute([
                'user_id' => $userId,
                'warning_type' => $warningType,
                'severity' => $severity,
                'subject' => $subject,
                'body' => $body,
                'audio_id' => $context['audio_id'] ?? null,
                'report_id' => $context['report_id'] ?? null,
                'moderator_id' => $moderatorId,
            ]);

            // Update user_report_stats
            $pdo->prepare("
                INSERT INTO user_report_stats (user_id, warnings_sent, last_warning_at, last_warning_reason, updated_at)
                VALUES (:user_id, 1, NOW(), :reason, NOW())
                ON CONFLICT (user_id) DO UPDATE SET
                    warnings_sent = user_report_stats.warnings_sent + 1,
                    last_warning_at = NOW(),
                    last_warning_reason = :reason,
                    risk_score = LEAST(100, user_report_stats.risk_score + 10),
                    updated_at = NOW()
            ")->execute([
                'user_id' => $userId,
                'reason' => $warningType,
            ]);

            // Log action
            ModerationSecurityService::logModerationAction(
                $moderatorId,
                'send_warning',
                $userId,
                null,
                [
                    'warning_type' => $warningType,
                    'severity' => $severity,
                    'audio_id' => $context['audio_id'] ?? null,
                ]
            );

            Logger::security('info', 'Warning email sent to user', [
                'user_id' => $userId,
                'warning_type' => $warningType,
                'moderator_id' => $moderatorId,
            ]);

            return [
                'success' => true,
                'message' => 'Warning email sent successfully',
                'severity' => $severity,
            ];
        } catch (\Exception $e) {
            Logger::error('Failed to send warning email', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build warning email HTML body
     */
    private function buildWarningEmailBody(
        array $user,
        string $warningType,
        string $severity,
        array $context
    ): string {
        $nickname = htmlspecialchars($user['nickname']);
        $customMessage = htmlspecialchars($context['custom_message'] ?? '');
        $audioTitle = htmlspecialchars($context['audio_title'] ?? '');

        // Severity-specific styling
        $severityColors = [
            'notice' => '#3b82f6',      // Blue
            'warning' => '#f59e0b',     // Amber
            'final_warning' => '#ef4444', // Red
        ];
        $headerColor = $severityColors[$severity] ?? '#f59e0b';

        // Warning type specific messages
        $typeMessages = [
            'spam' => 'Il tuo contenuto è stato segnalato come spam da altri utenti.',
            'harassment' => 'Il tuo contenuto è stato segnalato per comportamento molesto o offensivo.',
            'hate_speech' => 'Il tuo contenuto è stato segnalato per linguaggio d\'odio o discriminatorio.',
            'copyright' => 'Il tuo contenuto potrebbe violare i diritti d\'autore di terzi.',
            'sexual_content' => 'Il tuo contenuto è stato segnalato per materiale sessuale inappropriato.',
            'violence' => 'Il tuo contenuto è stato segnalato per contenuti violenti.',
            'content_report' => 'Il tuo contenuto è stato segnalato da altri utenti per possibile violazione delle linee guida.',
        ];
        $typeMessage = $typeMessages[$warningType] ?? $typeMessages['content_report'];

        // Severity messages
        $severityMessages = [
            'notice' => 'Questo è un avviso informativo.',
            'warning' => 'Questa è un\'ammonizione ufficiale. Ulteriori violazioni potrebbero comportare restrizioni al tuo account.',
            'final_warning' => '<strong>ULTIMO AVVISO:</strong> Questo è l\'ultimo avviso prima di azioni disciplinari più severe, inclusa la possibile sospensione dell\'account.',
        ];
        $severityMessage = $severityMessages[$severity] ?? $severityMessages['warning'];

        $audioSection = '';
        if ($audioTitle) {
            $audioSection = "
                <div style=\"background: #1a1a2e; border-radius: 8px; padding: 15px; margin: 20px 0;\">
                    <strong style=\"color: #9ca3af;\">Contenuto interessato:</strong>
                    <p style=\"color: #e5e7eb; margin: 10px 0 0 0;\">\"{$audioTitle}\"</p>
                </div>
            ";
        }

        $customSection = '';
        if ($customMessage) {
            $customSection = "
                <div style=\"background: #1f2937; border-left: 4px solid {$headerColor}; padding: 15px; margin: 20px 0;\">
                    <strong style=\"color: #9ca3af;\">Messaggio dal team di moderazione:</strong>
                    <p style=\"color: #e5e7eb; margin: 10px 0 0 0;\">{$customMessage}</p>
                </div>
            ";
        }

        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
</head>
<body style=\"margin: 0; padding: 0; background-color: #0f0f0f; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;\">
    <div style=\"max-width: 600px; margin: 0 auto; padding: 20px;\">
        <!-- Header -->
        <div style=\"background: linear-gradient(135deg, {$headerColor} 0%, #7c3aed 100%); border-radius: 12px 12px 0 0; padding: 30px; text-align: center;\">
            <h1 style=\"color: #ffffff; margin: 0; font-size: 24px;\">⚠️ Avviso dalla Moderazione</h1>
        </div>

        <!-- Content -->
        <div style=\"background: #1a1a1a; padding: 30px; border-radius: 0 0 12px 12px;\">
            <p style=\"color: #e5e7eb; font-size: 16px; line-height: 1.6;\">
                Ciao <strong>{$nickname}</strong>,
            </p>

            <p style=\"color: #e5e7eb; font-size: 16px; line-height: 1.6;\">
                {$typeMessage}
            </p>

            {$audioSection}

            <div style=\"background: rgba(239, 68, 68, 0.1); border: 1px solid {$headerColor}; border-radius: 8px; padding: 15px; margin: 20px 0;\">
                <p style=\"color: {$headerColor}; margin: 0; font-size: 14px;\">
                    {$severityMessage}
                </p>
            </div>

            {$customSection}

            <p style=\"color: #9ca3af; font-size: 14px; line-height: 1.6; margin-top: 30px;\">
                Ti invitiamo a rivedere le nostre <a href=\"https://need2talk.it/guidelines\" style=\"color: #a855f7;\">Linee Guida della Community</a>
                per assicurarti che i tuoi contenuti futuri rispettino le nostre policy.
            </p>

            <p style=\"color: #9ca3af; font-size: 14px; line-height: 1.6;\">
                Se ritieni che questo avviso sia stato inviato per errore, puoi rispondere a questa email
                per richiedere una revisione.
            </p>

            <hr style=\"border: none; border-top: 1px solid #374151; margin: 30px 0;\">

            <p style=\"color: #6b7280; font-size: 12px; text-align: center;\">
                Questo messaggio è stato inviato automaticamente dal team di moderazione di need2talk.<br>
                Non rispondere se non necessario.
            </p>
        </div>

        <!-- Footer -->
        <div style=\"text-align: center; padding: 20px;\">
            <p style=\"color: #6b7280; font-size: 12px;\">
                © " . date('Y') . " need2talk.it - Tutti i diritti riservati
            </p>
        </div>
    </div>
</body>
</html>
";
    }

    /**
     * Escalate a report to senior moderators/admins
     *
     * @param int $reportId Report ID
     * @param int $moderatorId Moderator escalating
     * @param string|null $reason Escalation reason
     * @return array Result
     */
    public function escalateReport(int $reportId, int $moderatorId, ?string $reason = null): array
    {
        try {
            $pdo = db_pdo();

            $stmt = $pdo->prepare("
                UPDATE audio_reports
                SET is_escalated = TRUE,
                    escalated_at = NOW(),
                    escalated_by_moderator_id = :moderator_id,
                    moderator_notes = COALESCE(moderator_notes, '') || E'\\n[ESCALATED] ' || :reason
                WHERE id = :id
                RETURNING audio_file_id
            ");
            $stmt->execute([
                'id' => $reportId,
                'moderator_id' => $moderatorId,
                'reason' => $reason ?? 'Escalated for senior review',
            ]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result) {
                return ['success' => false, 'error' => 'Report not found'];
            }

            // Log action
            ModerationSecurityService::logModerationAction(
                $moderatorId,
                'escalate_report',
                null,
                null,
                ['report_id' => $reportId, 'reason' => $reason]
            );

            return ['success' => true, 'message' => 'Report escalated successfully'];
        } catch (\Exception $e) {
            Logger::error('Failed to escalate report', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get user moderation history
     *
     * @param int $userId User ID
     * @return array User's moderation history
     */
    public function getUserModerationHistory(int $userId): array
    {
        try {
            $pdo = db_pdo();

            // Get user stats
            $statsStmt = $pdo->prepare("
                SELECT * FROM user_report_stats WHERE user_id = :user_id
            ");
            $statsStmt->execute(['user_id' => $userId]);
            $stats = $statsStmt->fetch(\PDO::FETCH_ASSOC) ?: [
                'total_reports_received' => 0,
                'reports_actioned' => 0,
                'warnings_sent' => 0,
                'risk_score' => 0,
            ];

            // Get recent warnings
            $warningsStmt = $pdo->prepare("
                SELECT warning_type, severity, email_subject, sent_at
                FROM user_warning_emails
                WHERE user_id = :user_id
                ORDER BY sent_at DESC
                LIMIT 10
            ");
            $warningsStmt->execute(['user_id' => $userId]);
            $warnings = $warningsStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get active bans
            $bansStmt = $pdo->prepare("
                SELECT scope, reason, severity, expires_at, created_at
                FROM user_bans
                WHERE user_id = :user_id AND is_active = TRUE
            ");
            $bansStmt->execute(['user_id' => $userId]);
            $bans = $bansStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get recent reports against user's content
            $reportsStmt = $pdo->prepare("
                SELECT ar.reason, ar.status, ar.resolution_action, ar.created_at, af.title AS audio_title
                FROM audio_reports ar
                JOIN audio_files af ON af.id = ar.audio_file_id
                WHERE af.user_id = :user_id
                ORDER BY ar.created_at DESC
                LIMIT 10
            ");
            $reportsStmt->execute(['user_id' => $userId]);
            $reports = $reportsStmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'stats' => $stats,
                'warnings' => $warnings,
                'active_bans' => $bans,
                'recent_reports' => $reports,
            ];
        } catch (\Exception $e) {
            Logger::error('Failed to get user moderation history', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
