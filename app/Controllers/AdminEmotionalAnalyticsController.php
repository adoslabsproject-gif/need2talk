<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Analytics\EmotionalAnalyticsService;
use Need2Talk\Services\Logger;

/**
 * Admin Emotional Analytics Controller
 *
 * ENTERPRISE: Internal emotional insights dashboard (NOT data sales!)
 *
 * Visualizes:
 * - Emotions EXPRESSED (what users register)
 * - Emotions EVOKED (reactions from others)
 * - Sentiment Gap Analysis (key product insight!)
 * - Engagement Metrics
 * - Consent Statistics (GDPR compliance)
 *
 * PERFORMANCE: Redis-cached aggregations, real-time override possible
 *
 * @package Need2Talk\Controllers
 */
class AdminEmotionalAnalyticsController extends BaseController
{
    private EmotionalAnalyticsService $analyticsService;

    public function __construct()
    {
        parent::__construct();
        $this->analyticsService = new EmotionalAnalyticsService();
    }

    /**
     * Get all data for Emotional Analytics admin page
     *
     * @return array Page data for rendering
     */
    public function getPageData(): array
    {
        // ENTERPRISE: No-cache headers for admin real-time insights
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        try {
            // Get platform insights (last 30 days)
            $insights = $this->analyticsService->getPlatformInsights(30);

            // Get consent statistics (GDPR transparency)
            $consentStats = $this->analyticsService->getConsentStatistics();

            // Return data for rendering
            return [
                'title' => 'Emotional Analytics Dashboard',
                'subtitle' => 'Internal Insights - Product Improvement (GDPR Compliant)',
                'insights' => $insights,
                'consent_stats' => $consentStats,
                'period_days' => 30,
            ];

        } catch (\Exception $e) {
            Logger::error('AdminEmotionalAnalytics: Failed to load page data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'title' => 'Emotional Analytics Dashboard',
                'error' => 'Failed to load emotional analytics data',
            ];
        }
    }

    /**
     * API: Get insights for different period (AJAX)
     *
     * @return void JSON response
     */
    public function getInsightsByPeriod(): void
    {
        try {
            $days = (int) ($_GET['days'] ?? 30);
            $days = max(1, min(365, $days)); // Limit 1-365 days

            $insights = $this->analyticsService->getPlatformInsights($days);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $insights,
            ]);

        } catch (\Exception $e) {
            Logger::error('AdminEmotionalAnalytics: Failed to get insights by period', [
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Failed to load insights',
            ]);
        }
    }

    /**
     * API: Export emotional analytics data (CSV)
     *
     * @return void CSV download
     */
    public function exportInsights(): void
    {
        try {
            $days = (int) ($_GET['days'] ?? 30);
            $insights = $this->analyticsService->getPlatformInsights($days);

            // Generate CSV
            $filename = 'emotional_analytics_' . date('Y-m-d_H-i-s') . '.csv';

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');

            $output = fopen('php://output', 'w');

            // CSV Header
            fputcsv($output, ['Emotional Analytics Report - need2talk.it']);
            fputcsv($output, ['Period (days)', $insights['period_days'] ?? 0]);
            fputcsv($output, ['Users with consent', $insights['total_users_with_consent'] ?? 0]);
            fputcsv($output, ['Generated at', date('Y-m-d H:i:s')]);
            fputcsv($output, []); // Empty line

            // Expressed Emotions
            fputcsv($output, ['EXPRESSED EMOTIONS (What users register)']);
            fputcsv($output, ['Emotion', 'Count', 'Percentage', 'Category']);
            if (isset($insights['expressed_emotions']['distribution'])) {
                foreach ($insights['expressed_emotions']['distribution'] as $emotion) {
                    fputcsv($output, [
                        $emotion['name_it'],
                        $emotion['count'],
                        $emotion['percentage'] . '%',
                        $emotion['category'],
                    ]);
                }
            }
            fputcsv($output, []); // Empty line

            // Evoked Emotions
            fputcsv($output, ['EVOKED EMOTIONS (Reactions from others)']);
            fputcsv($output, ['Emotion', 'Count', 'Percentage', 'Category']);
            if (isset($insights['evoked_emotions']['distribution'])) {
                foreach ($insights['evoked_emotions']['distribution'] as $emotion) {
                    fputcsv($output, [
                        $emotion['name_it'],
                        $emotion['count'],
                        $emotion['percentage'] . '%',
                        $emotion['category'],
                    ]);
                }
            }
            fputcsv($output, []); // Empty line

            // Sentiment Gap
            if (isset($insights['sentiment_gap'])) {
                fputcsv($output, ['SENTIMENT GAP ANALYSIS']);
                fputcsv($output, ['Expressed Positive %', $insights['sentiment_gap']['expressed_positive_percent']]);
                fputcsv($output, ['Evoked Positive %', $insights['sentiment_gap']['evoked_positive_percent']]);
                fputcsv($output, ['Gap %', $insights['sentiment_gap']['gap_percent']]);
                fputcsv($output, ['Interpretation', $insights['sentiment_gap']['interpretation']]);
            }

            fclose($output);
            exit;

        } catch (\Exception $e) {
            Logger::error('AdminEmotionalAnalytics: Failed to export insights', [
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            echo 'Failed to export data';
            exit;
        }
    }
}
