<?php

namespace Need2Talk\Controllers;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * ENTERPRISE GALAXY: Admin Newsletter Worker API Controller
 *
 * API endpoints for newsletter worker control panel
 * Wraps AdminNewsletterWorkerController and returns JSON responses
 *
 * @package Need2Talk\Controllers
 * @version 1.0.0 - ENTERPRISE GALAXY
 */
class AdminNewsletterWorkerApiController extends BaseController
{
    private AdminNewsletterWorkerController $workerController;

    public function __construct()
    {
        parent::__construct();
        $this->workerController = new AdminNewsletterWorkerController();
    }

    /**
     * API Endpoint: Get newsletter worker status
     * GET /api/newsletter-worker/status
     */
    public function getStatus(): void
    {
        $this->disableHttpCache();

        try {
            // Use getStatusData() which returns array (not getStatus() which echoes JSON)
            $status = $this->workerController->getStatusData();

            Logger::security('info', 'ADMIN: Newsletter worker status checked', [
                'admin_user' => $_SESSION['admin_user_id'] ?? 'unknown',
                'status' => $status['status'] ?? 'unknown',
                'workers' => $status['workers'] ?? 0,
            ]);

            $this->json($status);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get newsletter worker status', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Start newsletter worker container
     * POST /api/newsletter-worker/start
     */
    public function start(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->workerController->start();
            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to start newsletter workers', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Stop newsletter worker container
     * POST /api/newsletter-worker/stop
     */
    public function stop(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->workerController->stop();
            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to stop newsletter workers', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Restart newsletter worker container
     * POST /api/newsletter-worker/restart
     */
    public function restart(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->workerController->restart();
            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to restart newsletter workers', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Enable auto-restart
     * POST /api/newsletter-worker/enable-autostart
     */
    public function enableAutostart(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->workerController->enable();
            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to enable newsletter autostart', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Disable auto-restart
     * POST /api/newsletter-worker/disable-autostart
     */
    public function disableAutostart(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->workerController->disable();
            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to disable newsletter autostart', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Get recent logs
     * GET /api/newsletter-worker/logs
     */
    public function getLogs(): void
    {
        $this->disableHttpCache();

        try {
            $lines = (int) ($_GET['lines'] ?? 50);
            $result = $this->workerController->getLogs($lines);
            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get newsletter worker logs', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Stop and clean logs
     * POST /api/newsletter-worker/stop-clean
     */
    public function stopAndClean(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->workerController->stopAndClean();
            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to stop and clean newsletter workers', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API Endpoint: Get health status
     * GET /api/newsletter-worker/health
     */
    public function getHealth(): void
    {
        $this->disableHttpCache();

        try {
            $result = $this->workerController->getHealth();
            $this->json($result);

        } catch (\Exception $e) {
            Logger::error('ADMIN: Failed to get newsletter worker health', [
                'error' => $e->getMessage(),
            ]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
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
