<?php

namespace Need2Talk\Repositories\Audio;

use Need2Talk\Core\Database;

/**
 * Audio Report Repository - Enterprise Galaxy
 *
 * Database layer for audio_reports table
 * Community moderation: auto-flags content at 3+ reports (via trigger)
 *
 * @package Need2Talk\Repositories\Audio
 */
class AudioReportRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * Create new report
     *
     * @param array $data Report data
     * @return int|false Report ID or false (duplicate)
     */
    public function create(array $data): int|false
    {
        try {
            $sql = "INSERT INTO audio_reports (
                audio_file_id,
                reporter_user_id,
                reason,
                description,
                status,
                created_at
            ) VALUES (
                :audio_file_id,
                :reporter_user_id,
                :reason,
                :description,
                :status,
                NOW()
            )";

            $result = $this->db->execute($sql, [
                'audio_file_id' => $data['audio_file_id'],
                'reporter_user_id' => $data['reporter_user_id'],
                'reason' => $data['reason'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'pending',
            ], [
                'return_id' => true,  // ENTERPRISE FIX: PostgreSQL requires RETURNING id
                'invalidate_cache' => [
                    "audio:{$data['audio_file_id']}",
                    "audio_reports:pending",
                ],
            ]);

            return $result ? $this->db->lastInsertId() : false;
        } catch (\PDOException $e) {
            // Duplicate entry (user already reported this audio)
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Get report by ID
     *
     * @param int $reportId Report ID
     * @return array|null Report data
     */
    public function findById(int $reportId): ?array
    {
        $sql = "SELECT
            id,
            audio_file_id,
            reporter_user_id,
            reason,
            description,
            status,
            reviewed_by,
            reviewed_at,
            admin_notes,
            created_at
        FROM audio_reports
        WHERE id = :id";

        return $this->db->findOne($sql, ['id' => $reportId]);
    }

    /**
     * Get pending reports (admin moderation queue)
     *
     * @param int $limit Reports per page
     * @param int $offset Pagination offset
     * @return array Reports
     */
    public function getPendingReports(int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT
            ar.id,
            ar.audio_file_id,
            ar.reporter_user_id,
            ar.reason,
            ar.description,
            ar.created_at,
            af.title as audio_title,
            af.user_id as audio_author_id,
            af.report_count,
            af.moderation_status,
            reporter.nickname as reporter_nickname,
            author.nickname as author_nickname
        FROM audio_reports ar
        INNER JOIN audio_files af ON ar.audio_file_id = af.id
        INNER JOIN users reporter ON ar.reporter_user_id = reporter.id
        INNER JOIN users author ON af.user_id = author.id
        WHERE ar.status = 'pending'
        ORDER BY ar.created_at ASC
        LIMIT :limit OFFSET :offset";

        return $this->db->query($sql, [
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'cache' => true,
            'cache_ttl' => 'short', // 5 min
        ]);
    }

    /**
     * Get reports for specific audio
     *
     * @param int $audioId Audio ID
     * @return array Reports
     */
    public function getAudioReports(int $audioId): array
    {
        $sql = "SELECT
            ar.id,
            ar.reporter_user_id,
            ar.reason,
            ar.description,
            ar.status,
            ar.reviewed_by,
            ar.reviewed_at,
            ar.admin_notes,
            ar.created_at,
            u.nickname as reporter_nickname
        FROM audio_reports ar
        INNER JOIN users u ON ar.reporter_user_id = u.id
        WHERE ar.audio_file_id = :audio_id
        ORDER BY ar.created_at DESC";

        return $this->db->query($sql, ['audio_id' => $audioId]);
    }

    /**
     * Review report (admin action)
     *
     * @param int $reportId Report ID
     * @param int $adminId Admin user ID
     * @param string $status New status (reviewed, action_taken, dismissed)
     * @param string|null $adminNotes Admin notes
     * @return bool Success
     */
    public function review(int $reportId, int $adminId, string $status, ?string $adminNotes = null): bool
    {
        $validStatuses = ['reviewed', 'action_taken', 'dismissed'];
        if (!in_array($status, $validStatuses, true)) {
            return false;
        }

        $sql = "UPDATE audio_reports
                SET status = :status,
                    reviewed_by = :admin_id,
                    reviewed_at = NOW(),
                    admin_notes = :admin_notes
                WHERE id = :id";

        $report = $this->findById($reportId);
        if (!$report) {
            return false;
        }

        return $this->db->execute($sql, [
            'id' => $reportId,
            'status' => $status,
            'admin_id' => $adminId,
            'admin_notes' => $adminNotes,
        ], [
            'invalidate_cache' => [
                "audio:{$report['audio_file_id']}",
                "audio_reports:pending",
                "report:{$reportId}",
            ],
        ]);
    }

    /**
     * Check if user already reported audio
     *
     * ENTERPRISE GALAXY: This is a user-state mutation check.
     * MUST bypass cache - always hit database for accurate state.
     * Caching user actions leads to duplicate submissions and constraint violations.
     *
     * @param int $userId User ID
     * @param int $audioId Audio ID
     * @return bool Already reported
     */
    public function hasReported(int $userId, int $audioId): bool
    {
        $sql = "SELECT 1 FROM audio_reports
                WHERE reporter_user_id = :user_id
                  AND audio_file_id = :audio_id";

        // CRITICAL: cache => false - user state checks MUST hit database
        return (bool) $this->db->findOne($sql, [
            'user_id' => $userId,
            'audio_id' => $audioId,
        ], ['cache' => false]);
    }

    /**
     * Get report statistics
     *
     * @return array Stats
     */
    public function getStats(): array
    {
        $sql = "SELECT
            COUNT(*) as total_reports,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_reports,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_reports,
            SUM(CASE WHEN status = 'action_taken' THEN 1 ELSE 0 END) as action_taken,
            SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed
        FROM audio_reports
        WHERE created_at >= NOW() - INTERVAL '30 days'";

        return $this->db->findOne($sql) ?? [
            'total_reports' => 0,
            'pending_reports' => 0,
            'reviewed_reports' => 0,
            'action_taken' => 0,
            'dismissed' => 0,
        ];
    }

    /**
     * Get report count for audio
     *
     * @param int $audioId Audio ID
     * @return int Report count
     */
    public function count(int $audioId): int
    {
        return $this->db->count('audio_reports', 'audio_file_id = ?', [$audioId]);
    }

    /**
     * Get most reported audios (flagged content)
     *
     * @param int $limit Audios per page
     * @return array Flagged audios
     */
    public function getFlaggedAudios(int $limit = 50): array
    {
        $sql = "SELECT
            af.id,
            af.user_id,
            af.title,
            af.report_count,
            af.moderation_status,
            af.created_at,
            u.nickname as author_nickname,
            COUNT(ar.id) as pending_reports
        FROM audio_files af
        INNER JOIN users u ON af.user_id = u.id
        LEFT JOIN audio_reports ar ON af.id = ar.audio_file_id AND ar.status = 'pending'
        WHERE af.moderation_status = 'flagged'
          AND af.deleted_at IS NULL
        GROUP BY af.id
        ORDER BY af.report_count DESC, af.created_at DESC
        LIMIT :limit";

        return $this->db->query($sql, ['limit' => $limit], [
            'cache' => true,
            'cache_ttl' => 'short',
        ]);
    }
}
