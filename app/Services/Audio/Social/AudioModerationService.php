<?php

namespace Need2Talk\Services\Audio\Social;

use Need2Talk\Core\EnterpriseRedisManager;
use Need2Talk\Repositories\Audio\AudioPostRepository;
use Need2Talk\Repositories\Audio\AudioReportRepository;
use Need2Talk\Services\Logger;

/**
 * Audio Moderation Service - Enterprise Galaxy
 *
 * Business logic for content moderation
 * Report-based moderation (NO AI for now)
 * Auto-flags content at 3+ reports (via PostgreSQL trigger)
 *
 * @package Need2Talk\Services\Audio\Social
 */
class AudioModerationService
{
    private AudioReportRepository $reportRepo;
    private AudioPostRepository $postRepo;

    public function __construct()
    {
        $this->reportRepo = new AudioReportRepository();
        $this->postRepo = new AudioPostRepository();
    }

    /**
     * ENTERPRISE GALAXY: Rate limit for reports
     * Max 5 reports per hour per user
     */
    private const REPORT_RATE_LIMIT = 5;
    private const REPORT_RATE_WINDOW = 3600; // 1 hour in seconds

    /**
     * Report an audio post
     *
     * @param int $userId User ID (reporter)
     * @param int $audioId Audio ID
     * @param string $reason Report reason
     * @param string|null $description Optional description
     * @return array Result
     */
    public function reportAudio(
        int $userId,
        int $audioId,
        string $reason,
        ?string $description = null
    ): array {
        try {
            // ENTERPRISE GALAXY: Check rate limit (5 reports/hour)
            $rateLimitResult = $this->checkReportRateLimit($userId);
            if (!$rateLimitResult['allowed']) {
                return [
                    'success' => false,
                    'error' => 'rate_limited',
                    'message' => 'Hai raggiunto il limite di segnalazioni. Riprova tra ' . $rateLimitResult['retry_after'] . ' minuti.',
                    'retry_after' => $rateLimitResult['retry_after'],
                ];
            }

            // Validate reason
            $validReasons = [
                'spam',
                'harassment',
                'hate_speech',
                'violence',
                'sexual_content',
                'misinformation',
                'copyright',
                'other',
            ];

            if (!in_array($reason, $validReasons, true)) {
                return [
                    'success' => false,
                    'error' => 'invalid_reason',
                    'message' => 'Motivo di segnalazione non valido',
                ];
            }

            // Check if already reported
            if ($this->reportRepo->hasReported($userId, $audioId)) {
                return [
                    'success' => false,
                    'error' => 'already_reported',
                    'message' => 'Hai già segnalato questo audio',
                ];
            }

            // Check if user is reporting their own content
            if ($this->postRepo->isOwner($audioId, $userId)) {
                return [
                    'success' => false,
                    'error' => 'own_content',
                    'message' => 'Non puoi segnalare i tuoi contenuti',
                ];
            }

            $reportData = [
                'audio_file_id' => $audioId,
                'reporter_user_id' => $userId,
                'reason' => $reason,
                'description' => $description,
                'status' => 'pending',
            ];

            $reportId = $this->reportRepo->create($reportData);

            if (!$reportId) {
                return [
                    'success' => false,
                    'error' => 'report_failed',
                    'message' => 'Errore durante la segnalazione',
                ];
            }

            // ENTERPRISE GALAXY: Increment rate limit counter on successful report
            $this->incrementReportCounter($userId);

            Logger::security('warning', 'Audio reported', [
                'report_id' => $reportId,
                'audio_id' => $audioId,
                'reporter_id' => $userId,
                'reason' => $reason,
            ]);

            // Get updated report count (trigger auto-increments)
            $post = $this->postRepo->findById($audioId);
            $reportCount = $post['report_count'] ?? 0;

            // Check if auto-flagged (3+ reports)
            $autoFlagged = $reportCount >= 3;

            return [
                'success' => true,
                'report_id' => $reportId,
                'message' => 'Segnalazione inviata con successo',
                'auto_flagged' => $autoFlagged,
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to report audio', [
                'user_id' => $userId,
                'audio_id' => $audioId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ];
        }
    }

    /**
     * Get pending reports (admin only)
     *
     * @param int $page Page number
     * @param int $perPage Reports per page
     * @return array Reports
     */
    public function getPendingReports(int $page = 1, int $perPage = 50): array
    {
        try {
            $offset = ($page - 1) * $perPage;
            $reports = $this->reportRepo->getPendingReports($perPage, $offset);

            return [
                'success' => true,
                'reports' => $reports,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'has_more' => count($reports) === $perPage,
                ],
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get pending reports', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'reports' => [],
            ];
        }
    }

    /**
     * Get flagged audios (admin only)
     *
     * @param int $limit Max audios to return
     * @return array Flagged audios
     */
    public function getFlaggedAudios(int $limit = 50): array
    {
        try {
            $flagged = $this->reportRepo->getFlaggedAudios($limit);

            return [
                'success' => true,
                'audios' => $flagged,
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get flagged audios', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'audios' => [],
            ];
        }
    }

    /**
     * Review report (admin action)
     *
     * @param int $reportId Report ID
     * @param int $adminId Admin user ID
     * @param string $action Action (approve, reject, delete_content)
     * @param string|null $adminNotes Admin notes
     * @return array Result
     */
    public function reviewReport(
        int $reportId,
        int $adminId,
        string $action,
        ?string $adminNotes = null
    ): array {
        try {
            $report = $this->reportRepo->findById($reportId);
            if (!$report) {
                return [
                    'success' => false,
                    'error' => 'report_not_found',
                    'message' => 'Segnalazione non trovata',
                ];
            }

            $audioId = $report['audio_file_id'];

            // Process action
            switch ($action) {
                case 'dismiss':
                    // Dismiss report (no action)
                    $reviewStatus = 'dismissed';
                    $moderationStatus = null; // Don't change audio status
                    break;

                case 'approve_content':
                    // Approve audio (dismiss report)
                    $reviewStatus = 'dismissed';
                    $moderationStatus = 'approved';
                    break;

                case 'reject_content':
                    // Reject audio (hide from feed)
                    $reviewStatus = 'action_taken';
                    $moderationStatus = 'rejected';
                    break;

                case 'delete_content':
                    // Delete audio
                    $reviewStatus = 'action_taken';
                    $moderationStatus = null; // Will delete instead
                    $this->postRepo->delete($audioId);
                    break;

                default:
                    return [
                        'success' => false,
                        'error' => 'invalid_action',
                        'message' => 'Azione non valida',
                    ];
            }

            // Update report status
            $result = $this->reportRepo->review($reportId, $adminId, $reviewStatus, $adminNotes);

            if (!$result) {
                return [
                    'success' => false,
                    'error' => 'review_failed',
                    'message' => 'Errore durante la revisione',
                ];
            }

            // Update audio moderation status if needed
            if ($moderationStatus !== null) {
                $this->postRepo->updateModerationStatus($audioId, $moderationStatus);
            }

            Logger::security('info', 'Report reviewed', [
                'report_id' => $reportId,
                'audio_id' => $audioId,
                'admin_id' => $adminId,
                'action' => $action,
                'review_status' => $reviewStatus,
            ]);

            return [
                'success' => true,
                'message' => 'Segnalazione revisionata con successo',
                'action_taken' => $action,
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to review report', [
                'report_id' => $reportId,
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'message' => 'Errore del server',
            ];
        }
    }

    /**
     * Get moderation statistics
     *
     * @return array Stats
     */
    public function getStats(): array
    {
        try {
            $stats = $this->reportRepo->getStats();

            return [
                'success' => true,
                'stats' => $stats,
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get moderation stats', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'stats' => [],
            ];
        }
    }

    /**
     * Get reports for specific audio (admin only)
     *
     * @param int $audioId Audio ID
     * @return array Reports
     */
    public function getAudioReports(int $audioId): array
    {
        try {
            $reports = $this->reportRepo->getAudioReports($audioId);

            return [
                'success' => true,
                'reports' => $reports,
            ];

        } catch (\Exception $e) {
            Logger::error('Failed to get audio reports', [
                'audio_id' => $audioId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'server_error',
                'reports' => [],
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY: Check report rate limit
     *
     * Uses Redis to track reports per user (5/hour max)
     *
     * @param int $userId User ID
     * @return array ['allowed' => bool, 'remaining' => int, 'retry_after' => int]
     */
    private function checkReportRateLimit(int $userId): array
    {
        try {
            $redis = EnterpriseRedisManager::getInstance()->getConnection('rate_limit');
            $key = "report_limit:user:{$userId}";

            // Get current count
            $count = (int) $redis->get($key);

            if ($count >= self::REPORT_RATE_LIMIT) {
                // Get TTL to calculate retry_after
                $ttl = $redis->ttl($key);
                $retryAfter = $ttl > 0 ? ceil($ttl / 60) : 60; // in minutes

                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'retry_after' => (int) $retryAfter,
                ];
            }

            return [
                'allowed' => true,
                'remaining' => self::REPORT_RATE_LIMIT - $count,
                'retry_after' => 0,
            ];
        } catch (\Exception $e) {
            // If Redis fails, allow the report (fail open for UX)
            Logger::warning('Report rate limit check failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'allowed' => true,
                'remaining' => self::REPORT_RATE_LIMIT,
                'retry_after' => 0,
            ];
        }
    }

    /**
     * ENTERPRISE GALAXY: Increment report counter
     *
     * @param int $userId User ID
     */
    private function incrementReportCounter(int $userId): void
    {
        try {
            $redis = EnterpriseRedisManager::getInstance()->getConnection('rate_limit');
            $key = "report_limit:user:{$userId}";

            // Increment and set expiry if new key
            $count = $redis->incr($key);

            // Set expiry only on first report (when count becomes 1)
            if ($count === 1) {
                $redis->expire($key, self::REPORT_RATE_WINDOW);
            }
        } catch (\Exception $e) {
            // Log but don't fail the report
            Logger::warning('Report rate limit increment failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
