<?php

namespace Need2Talk\Controllers;

use Need2Talk\Services\Logger;
use Need2Talk\Services\Security\AdvancedMLThreatEngine;
use Need2Talk\Services\Security\DDoSProtection;
use Need2Talk\Services\Security\TrustedProxyValidator;

/**
 * ENTERPRISE GALAXY: ML Security Admin Controller
 *
 * Gestisce le API per il pannello admin ML Security:
 * - Configurazione ML Engine
 * - Retraining modello
 * - Status DDoS Protection
 * - Gestione ban IP
 *
 * @version 1.0.0
 */
class AdminMLSecurityController
{
    /**
     * Get current ML and DDoS status
     */
    public function getStatus(): void
    {
        try {
            $mlEngine = new AdvancedMLThreatEngine();
            $ddosProtection = DDoSProtection::getInstance();

            $response = [
                'success' => true,
                'ml' => $mlEngine->getStats(),
                'ddos' => $ddosProtection->getStatus(),
                'endpoints' => $ddosProtection->getEndpointStats(),
                'proxy' => TrustedProxyValidator::getDiagnostics(),
            ];

            $this->jsonResponse($response);
        } catch (\Exception $e) {
            Logger::error('ML Security Status Error', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update ML configuration
     */
    public function updateConfig(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                $this->jsonResponse(['success' => false, 'error' => 'Invalid JSON input'], 400);
                return;
            }

            $mlEngine = new AdvancedMLThreatEngine();

            $allowedKeys = ['ml_enabled', 'auto_learn', 'ml_weight', 'block_threshold', 'ban_threshold'];
            $updated = [];

            foreach ($allowedKeys as $key) {
                if (isset($input[$key])) {
                    $value = $input[$key];

                    // Validate value types
                    if (in_array($key, ['ml_enabled', 'auto_learn'])) {
                        $value = (bool) $value;
                    } elseif (in_array($key, ['ml_weight', 'block_threshold', 'ban_threshold'])) {
                        $value = (float) $value;
                        if ($value < 0 || $value > 1) {
                            continue; // Skip invalid values
                        }
                    }

                    if ($mlEngine->setConfig($key, $value)) {
                        $updated[$key] = $value;
                    }
                }
            }

            Logger::security('warning', 'ML Security Config Updated', [
                'updated' => $updated,
                'admin_ip' => TrustedProxyValidator::getClientIp(),
            ]);

            $this->jsonResponse([
                'success' => true,
                'updated' => $updated,
                'config' => $mlEngine->getConfig(),
            ]);
        } catch (\Exception $e) {
            Logger::error('ML Config Update Error', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retrain ML model with historical data
     */
    public function retrain(): void
    {
        try {
            $mlEngine = new AdvancedMLThreatEngine();
            $pdo = db_pdo();
            $logDir = dirname(__DIR__, 2) . '/storage/logs';

            // Reset model before retraining
            $mlEngine->resetModel();

            // Full training from database + logs
            $results = $mlEngine->fullTraining($pdo, $logDir);

            Logger::security('warning', 'ML Model Retrained', [
                'total_samples' => $results['total_trained'],
                'status' => $mlEngine->getLearningStatus(),
                'admin_ip' => TrustedProxyValidator::getClientIp(),
            ]);

            $this->jsonResponse([
                'success' => true,
                'total_samples' => $results['total_trained'],
                'database' => $results['database'] ?? null,
                'logs' => $results['logs'] ?? null,
                'model_status' => $mlEngine->getLearningStatus(),
            ]);
        } catch (\Exception $e) {
            Logger::error('ML Retrain Error', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Unban an IP address
     */
    public function unbanIP(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (empty($input['ip'])) {
                $this->jsonResponse(['success' => false, 'error' => 'IP address required'], 400);
                return;
            }

            $ip = filter_var($input['ip'], FILTER_VALIDATE_IP);
            if (!$ip) {
                $this->jsonResponse(['success' => false, 'error' => 'Invalid IP address'], 400);
                return;
            }

            $pdo = db_pdo();

            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM vulnerability_scan_bans WHERE ip_address = ?");
            $stmt->execute([$ip]);
            $deleted = $stmt->rowCount();

            // Also remove from Redis ban cache
            try {
                $redis = new \Redis();
                $redis->connect($_ENV['REDIS_HOST'] ?? 'redis', (int) ($_ENV['REDIS_PORT'] ?? 6379));
                if ($_ENV['REDIS_PASSWORD'] ?? null) {
                    $redis->auth($_ENV['REDIS_PASSWORD']);
                }
                $redis->select(3); // Rate limiting DB
                $redis->del("ban:{$ip}");
                $redis->del("anti_scan:score:{$ip}");
            } catch (\Exception $e) {
                // Redis cleanup failed, but DB deletion succeeded
            }

            Logger::security('warning', 'IP Unbanned by Admin', [
                'ip' => $ip,
                'admin_ip' => TrustedProxyValidator::getClientIp(),
            ]);

            $this->jsonResponse([
                'success' => true,
                'ip' => $ip,
                'deleted_from_db' => $deleted > 0,
            ]);
        } catch (\Exception $e) {
            Logger::error('Unban IP Error', ['error' => $e->getMessage()]);
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Send JSON response
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
