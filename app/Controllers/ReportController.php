<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;
use Need2Talk\Services\ReportEmailService;

/**
 * NEED2TALK - REPORT CONTROLLER
 *
 * ENTERPRISE GALAXY ARCHITECTURE:
 * - Handles site-wide report submissions
 * - CSRF protection via middleware
 * - Rate limiting per email (1/day)
 * - Input sanitization and validation
 * - Async email processing via ReportEmailService
 * - Comprehensive error handling
 *
 * SECURITY:
 * - CSRF token validation
 * - XSS prevention via htmlspecialchars
 * - SQL injection prevention via prepared statements
 * - Rate limiting abuse prevention
 * - IP tracking for forensics
 *
 * @package Need2Talk\Controllers
 * @author Claude AI (Anthropic)
 * @version 1.0.0
 */
class ReportController extends BaseController
{
    /**
     * @var ReportEmailService Report email service
     */
    private ReportEmailService $reportService;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->reportService = new ReportEmailService();
    }

    /**
     * Display report form page
     *
     * @return void
     */
    public function showForm(): void
    {
        $this->view('pages.legal.report', [
            'title' => 'Segnala un Problema - need2talk',
            'description' => 'Segnala problemi tecnici, bug o suggerimenti per migliorare need2talk.',
        ]);
    }

    /**
     * Handle report form submission
     *
     * POST /api/report/submit
     *
     * EXPECTED PAYLOAD:
     * {
     *   "report_type": "technical|bug|security|abuse|content|...",
     *   "description": "Detailed description...",
     *   "reporter_email": "user@example.com",
     *   "content_url": "https://... (optional)",
     *   "evidence": "Additional evidence (optional)"
     * }
     *
     * @return void JSON response
     */
    public function submit(): void
    {
        try {
            // Validate HTTP method
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->json([
                    'success' => false,
                    'error' => 'method_not_allowed',
                    'message' => 'Solo richieste POST sono accettate',
                ], 405);

                return;
            }

            // CSRF validation handled by global CsrfMiddleware
            // No manual validation needed

            // Get and sanitize POST data
            $reportData = $this->sanitizeInput($_POST);

            // Validate required fields
            $requiredFields = ['report_type', 'description', 'reporter_email'];
            foreach ($requiredFields as $field) {
                if (empty($reportData[$field])) {
                    $this->json([
                        'success' => false,
                        'error' => 'missing_field',
                        'message' => "Campo obbligatorio mancante: {$field}",
                    ], 400);

                    return;
                }
            }

            // Add IP address for rate limiting and forensics
            $reportData['ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            // Add user agent for tracking
            $reportData['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

            // Map report_type to type for service compatibility
            $reportData['type'] = $reportData['report_type'];
            $reportData['email'] = $reportData['reporter_email'];

            // Submit report via service
            $result = $this->reportService->submitReport($reportData);

            // Log submission attempt
            Logger::info('Report submission attempt', [
                'success' => $result['success'],
                'email' => $reportData['reporter_email'],
                'type' => $reportData['report_type'],
                'ip' => $reportData['ip'],
                'report_id' => $result['report_id'] ?? null,
            ]);

            // Return JSON response
            $statusCode = $result['success'] ? 200 : 400;
            $this->json($result, $statusCode);

        } catch (\Exception $e) {
            // Log unexpected error
            Logger::error('Report submission exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Return generic error to user (don't expose internals)
            $this->json([
                'success' => false,
                'error' => 'server_error',
                'message' => 'Si è verificato un errore imprevisto. Riprova più tardi.',
            ], 500);
        }
    }

    /**
     * Sanitize input data to prevent XSS
     *
     * @param array $data Raw input data
     * @return array Sanitized data
     */
    private function sanitizeInput(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                // Trim whitespace
                $value = trim($value);

                // For email field, use filter_var
                if ($key === 'reporter_email') {
                    $sanitized[$key] = filter_var($value, FILTER_SANITIZE_EMAIL);
                }
                // For URL fields, use filter_var
                elseif ($key === 'content_url') {
                    $sanitized[$key] = filter_var($value, FILTER_SANITIZE_URL);
                }
                // For other fields, use htmlspecialchars
                else {
                    $sanitized[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                }
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
