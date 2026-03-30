<?php
/**
 * ================================================================================
 * ONBOARDING API CONTROLLER - ENTERPRISE GALAXY
 * ================================================================================
 *
 * PURPOSE:
 * Handle onboarding tour progress tracking and status checks
 *
 * ENDPOINTS:
 * - GET  /api/onboarding/status   - Check if user needs tour
 * - POST /api/onboarding/progress - Track tour progress
 *
 * @version 1.0.0
 * @date 2026-01-19
 * @author Claude Code + zelistore
 */

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

class OnboardingController extends BaseController
{
    /**
     * Get onboarding status for current user
     *
     * @return Response JSON response
     */
    public function status(): void
    {
        try {
            // Check authentication (throws exception if not logged in)
            $user = $this->requireAuth();
            $userId = (int) $user['id'];
            $db = db();

            // Get onboarding progress (NO CACHE - must be real-time)
            $progress = $db->findOne(
                "SELECT
                    tour_started_at,
                    tour_completed_at,
                    tour_skipped_at,
                    current_step,
                    tour_version
                FROM user_onboarding_progress
                WHERE user_id = ?",
                [$userId],
                ['cache' => false] // CRITICAL: No cache for onboarding status
            );

            // If no record, create one (with ON CONFLICT to prevent duplicate key errors)
            if (!$progress) {
                $db->execute(
                    "INSERT INTO user_onboarding_progress (user_id, device_type, browser, user_agent)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT (user_id) DO NOTHING",
                    [
                        $userId,
                        $this->detectDevice(),
                        $this->detectBrowser(),
                        get_server('HTTP_USER_AGENT') ?? ''
                    ]
                );

                // Re-fetch after insert
                $progress = $db->findOne(
                    "SELECT tour_started_at, tour_completed_at, tour_skipped_at, current_step, tour_version
                    FROM user_onboarding_progress
                    WHERE user_id = ?",
                    [$userId]
                );

                // If still not found (race condition), return defaults
                if (!$progress) {
                    $progress = [
                        'tour_started_at' => null,
                        'tour_completed_at' => null,
                        'tour_skipped_at' => null,
                        'current_step' => 0,
                        'tour_version' => 'v1.0'
                    ];
                }
            }

            $this->json([
                'tour_completed' => !empty($progress['tour_completed_at']),
                'tour_skipped' => !empty($progress['tour_skipped_at']),
                'tour_started' => !empty($progress['tour_started_at']),
                'current_step' => $progress['current_step'] ?? 0,
                'tour_version' => $progress['tour_version'] ?? 'v1.0'
            ]);

        } catch (\Exception $e) {
            Logger::error('Onboarding status error', [
                'error' => $e->getMessage(),
                'user_id' => $userId ?? null
            ]);

            $this->json([
                'error' => 'Server error',
                'message' => 'Failed to get onboarding status'
            ], 500);
        }
    }

    /**
     * Track onboarding progress
     *
     * @return void
     */
    public function progress(): void
    {
        try {
            // Check authentication (throws exception if not logged in)
            $user = $this->requireAuth();
            $userId = (int) $user['id'];
            $data = json_decode(file_get_contents('php://input'), true) ?? [];

            // Validate action
            $action = $data['action'] ?? null;
            if (!in_array($action, ['start', 'step', 'skip', 'complete'])) {
                $this->json([
                    'error' => 'Invalid action',
                    'message' => 'Action must be: start, step, skip, or complete'
                ], 400);
            }

            $db = db();

            // Handle different actions
            switch ($action) {
                case 'start':
                    $db->execute(
                        "UPDATE user_onboarding_progress
                        SET tour_started_at = CURRENT_TIMESTAMP,
                            current_step = 1,
                            device_type = ?,
                            browser = ?,
                            user_agent = ?,
                            tour_version = ?
                        WHERE user_id = ?",
                        [
                            $data['device_type'] ?? $this->detectDevice(),
                            $data['browser'] ?? $this->detectBrowser(),
                            get_server('HTTP_USER_AGENT') ?? '',
                            $data['tour_version'] ?? 'v1.0',
                            $userId
                        ]
                    );

                    Logger::info('Onboarding tour started', [
                        'user_id' => $userId,
                        'device' => $data['device_type'] ?? null
                    ]);
                    break;

                case 'step':
                    $step = (int)($data['step'] ?? 1);

                    $db->execute(
                        "UPDATE user_onboarding_progress
                        SET current_step = ?,
                            completed_steps = array_append(completed_steps, ?),
                            interactions_count = ?
                        WHERE user_id = ?",
                        [
                            $step,
                            $step,
                            $data['interactions_count'] ?? 0,
                            $userId
                        ]
                    );
                    break;

                case 'skip':
                    $db->execute(
                        "UPDATE user_onboarding_progress
                        SET tour_skipped_at = CURRENT_TIMESTAMP,
                            current_step = ?,
                            total_time_seconds = ?,
                            interactions_count = ?
                        WHERE user_id = ?",
                        [
                            $data['step'] ?? 0,
                            $data['total_time_seconds'] ?? 0,
                            $data['interactions_count'] ?? 0,
                            $userId
                        ]
                    );

                    Logger::info('Onboarding tour skipped', [
                        'user_id' => $userId,
                        'step_skipped_at' => $data['step'] ?? 0,
                        'time_seconds' => $data['total_time_seconds'] ?? 0
                    ]);
                    break;

                case 'complete':
                    $db->execute(
                        "UPDATE user_onboarding_progress
                        SET tour_completed_at = CURRENT_TIMESTAMP,
                            current_step = ?,
                            total_time_seconds = ?,
                            interactions_count = ?
                        WHERE user_id = ?",
                        [
                            $data['step'] ?? 5,
                            $data['total_time_seconds'] ?? 0,
                            $data['interactions_count'] ?? 0,
                            $userId
                        ]
                    );

                    Logger::info('Onboarding tour completed', [
                        'user_id' => $userId,
                        'time_seconds' => $data['total_time_seconds'] ?? 0,
                        'interactions' => $data['interactions_count'] ?? 0
                    ]);
                    break;
            }

            $this->json([
                'success' => true,
                'action' => $action
            ]);

        } catch (\Exception $e) {
            Logger::error('Onboarding progress error', [
                'error' => $e->getMessage(),
                'user_id' => $userId ?? null,
                'action' => $action ?? null
            ]);

            $this->json([
                'error' => 'Server error',
                'message' => 'Failed to save onboarding progress'
            ], 500);
        }
    }

    /**
     * Detect device type from user agent
     */
    private function detectDevice(): string
    {
        $ua = get_server('HTTP_USER_AGENT') ?? '';

        if (preg_match('/(mobile|android|iphone|ipad|tablet)/i', $ua)) {
            if (preg_match('/(ipad|tablet)/i', $ua)) {
                return 'tablet';
            }
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Detect browser from user agent
     */
    private function detectBrowser(): string
    {
        $ua = get_server('HTTP_USER_AGENT') ?? '';

        if (stripos($ua, 'Samsung') !== false) {
            return 'Samsung Browser';
        }
        if (stripos($ua, 'Chrome') !== false) {
            return 'Chrome';
        }
        if (stripos($ua, 'Safari') !== false) {
            return 'Safari';
        }
        if (stripos($ua, 'Firefox') !== false) {
            return 'Firefox';
        }
        if (stripos($ua, 'Edge') !== false) {
            return 'Edge';
        }

        return 'Unknown';
    }
}
