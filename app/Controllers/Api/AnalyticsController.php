<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * AnalyticsController - User activity tracking API
 *
 * Handles user activity analytics for enterprise monitoring
 */
class AnalyticsController extends BaseController
{
    private Logger $logger;

    public function __construct()
    {
        parent::__construct();
        $this->logger = new Logger();
    }

    /**
     * Store user activity data
     * POST /api/v1/analytics/activities
     */
    public function activities(): void
    {
        // Security: Require authentication
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Authentication required'], 401);

            return;
        }

        try {
            $input = $this->getJsonInput();
            $activities = $input['activities'] ?? [];

            if (empty($activities) || !is_array($activities)) {
                $this->json(['error' => 'Invalid activities data'], 400);

                return;
            }

            // Validate and store activities
            $processed = 0;

            foreach ($activities as $activity) {
                if ($this->validateActivity($activity)) {
                    $this->storeActivity($activity);
                    $processed++;
                }
            }

            $this->logger->info('Activities stored', [
                'user_id' => $this->user['id'],
                'total_activities' => count($activities),
                'processed' => $processed,
            ]);

            $this->json([
                'success' => true,
                'processed' => $processed,
                'total' => count($activities),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Activity storage failed', [
                'user_id' => $this->user['id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->json(['error' => 'Failed to store activities'], 500);
        }
    }

    /**
     * Validate activity data
     */
    private function validateActivity(array $activity): bool
    {
        $required = ['type', 'timestamp'];

        foreach ($required as $field) {
            if (!isset($activity[$field])) {
                return false;
            }
        }

        // Validate timestamp
        if (!is_numeric($activity['timestamp'])) {
            return false;
        }

        // Validate activity type
        $validTypes = ['page_view', 'click', 'scroll', 'mouse_move', 'key_press', 'form_interaction'];

        if (!in_array($activity['type'], $validTypes, true)) {
            return false;
        }

        return true;
    }

    /**
     * Store activity in database
     */
    private function storeActivity(array $activity): void
    {
        try {
            $stmt = db_pdo()->prepare('
                INSERT INTO user_activities
                (user_id, activity_type, data, created_at)
                VALUES (?, ?, ?, TO_TIMESTAMP(?))
            ');

            $stmt->execute([
                $this->user['id'],
                $activity['type'],
                json_encode($activity),
                $activity['timestamp'] / 1000, // Convert milliseconds to seconds
            ]);

        } catch (\Exception $e) {
            // Log but don't fail - analytics should be non-critical
            $this->logger->warning('Single activity storage failed', [
                'user_id' => $this->user['id'],
                'activity_type' => $activity['type'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Removed: isAuthenticated() now inherited from BaseController (protected)
}
