<?php

namespace Need2Talk\Controllers\Api;

use Need2Talk\Core\BaseController;
use Need2Talk\Services\Logger;

/**
 * Log API Controller
 *
 * Riceve e processa log dal client JavaScript
 */
class LogController extends BaseController
{
    /**
     * Riceve log client JavaScript
     */
    public function receiveClientLogs(): void
    {
        // ENTERPRISE: CORS headers per permettere chiamate da JavaScript
        // CRITICAL: Imposta header PRIMA di qualsiasi output
        $this->setCorsHeaders();

        // Handle OPTIONS preflight request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            // ENTERPRISE: Risposta preflight veloce (no body, no processing)
            http_response_code(204); // No Content
            header('Content-Length: 0');
            flush(); // Force send headers immediately
            exit;
        }

        // ENTERPRISE SECURITY: Block logs from unauthorized domains (cache attacks, scrapers)
        $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        $allowedDomains = [
            'https://need2talk.it',
            'https://www.need2talk.it',
            'http://localhost',
            'http://127.0.0.1',
        ];

        $isAllowed = false;
        foreach ($allowedDomains as $domain) {
            if (str_starts_with($origin, $domain)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed && !empty($origin)) {
            // Log unauthorized domain attempts for security monitoring
            Logger::security('warning', 'Blocked JS log from unauthorized domain', [
                'origin' => $origin,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);

            $this->json([
                'success' => false,
                'error' => 'Unauthorized domain',
            ], 403);

            return;
        }

        try {
            $input = $this->getJsonInput();

            if (!isset($input['logs']) || !is_array($input['logs'])) {
                $this->json([
                    'success' => false,
                    'error' => 'Invalid logs data',
                ], 400);

                return;
            }

            $clientInfo = $input['client_info'] ?? [];
            $logs = $input['logs'];

            // Processa ogni log
            foreach ($logs as $logEntry) {
                $this->processClientLog($logEntry, $clientInfo);
            }

            $this->json([
                'success' => true,
                'processed' => count($logs),
            ]);

        } catch (\Exception $e) {
            // ENTERPRISE GALAXY: Log client log processing failures to js_errors channel
            Logger::jsError('error', 'Error processing client logs', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->json([
                'success' => false,
                'error' => 'Error processing logs',
            ], 500);
        }
    }

    /**
     * Processa singolo log entry dal client
     */
    private function processClientLog(array $logEntry, array $clientInfo): void
    {
        $level = $logEntry['level'] ?? 'info';
        $message = $logEntry['message'] ?? 'Client log';
        $context = $logEntry['context'] ?? [];

        // ENTERPRISE TIPS: Normalize JavaScript W3C console levels to PSR-3 levels
        // JavaScript uses 'warn' but PSR-3 uses 'warning'
        if ($level === 'warn') {
            $level = 'warning';
        }

        // Aggiungi metadati client
        $fullContext = array_merge($context, [
            'source' => 'javascript',
            'client_session_id' => $clientInfo['session_id'] ?? null,
            'client_user_id' => $clientInfo['user_id'] ?? null,
            'client_timestamp' => $logEntry['timestamp'] ?? null,
            'client_url' => $logEntry['url'] ?? null,
            'client_user_agent' => $logEntry['user_agent'] ?? null,
            'viewport' => $logEntry['viewport'] ?? null,
            'connection_type' => $logEntry['connection'] ?? null,
        ]);

        // ENTERPRISE GALAXY: Log to dedicated js_errors channel respecting PSR-3 levels
        // All 8 PSR-3 levels supported: debug, info, notice, warning, error, critical, alert, emergency
        switch ($level) {
            case 'emergency':
                Logger::jsError('emergency', $message, $fullContext);
                break;

            case 'alert':
                Logger::jsError('alert', $message, $fullContext);
                break;

            case 'critical':
                Logger::jsError('critical', $message, $fullContext);
                break;

            case 'error':
                Logger::jsError('error', $message, $fullContext);
                break;

            case 'warning':
                Logger::jsError('warning', $message, $fullContext);
                break;

            case 'notice':
                Logger::jsError('notice', $message, $fullContext);
                break;

            case 'info':
                Logger::jsError('info', $message, $fullContext);
                break;

            case 'debug':
                Logger::jsError('debug', $message, $fullContext);
                break;

            case 'performance':
            case 'user_action':
            case 'websocket':
            case 'api_request':
            case 'log':
            default:
                // Silently ignore non-PSR-3 levels and generic 'log' (too verbose)
                break;
        }

        // Log eventi di sicurezza
        if ($this->isSecurityEvent($logEntry)) {
            Logger::security('warning', "Client Security Event: $message", $fullContext);
        }
    }

    /**
     * Verifica se è un evento di sicurezza
     */
    private function isSecurityEvent(array $logEntry): bool
    {
        $message = strtolower($logEntry['message'] ?? '');
        $level = $logEntry['level'] ?? '';

        // Eventi considerati di sicurezza
        $securityKeywords = [
            'xss',
            'csrf',
            'injection',
            'unauthorized',
            'forbidden',
            'security',
            'hack',
            'exploit',
            'malicious',
        ];

        // Errori JavaScript possono indicare attacchi
        if ($level === 'error') {
            foreach ($securityKeywords as $keyword) {
                if (strpos($message, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Imposta header CORS per permettere chiamate AJAX cross-origin
     *
     * ENTERPRISE GALAXY: Intelligente gestione CORS
     * - Same-origin requests: NO CORS headers needed (automatic browser allow)
     * - Cross-origin requests: Strict whitelist validation
     */
    private function setCorsHeaders(): void
    {
        // Get origin header
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // ENTERPRISE GALAXY FIX: If NO origin header, it's a same-origin request
        // Same-origin requests DON'T need CORS headers (browser automatically allows them)
        if (empty($origin)) {
            // Same-origin request - no CORS headers needed
            return;
        }

        // For cross-origin requests, validate against whitelist
        $allowedOrigins = [
            // Production domains
            'https://need2talk.it',
            'https://www.need2talk.it',
            'http://need2talk.it',      // HTTP redirect
            'http://www.need2talk.it',  // HTTP redirect
            // Server IP (development/testing)
            'http://YOUR_SERVER_IP',
            'https://YOUR_SERVER_IP',
            // Local development
            'https://localhost',
            'http://localhost',
        ];

        // Check if origin is allowed
        $originAllowed = false;
        foreach ($allowedOrigins as $allowedOrigin) {
            if ($origin === $allowedOrigin || strpos($origin, $allowedOrigin . ':') === 0) {
                $originAllowed = true;
                break;
            }
        }

        // Set CORS headers ONLY for allowed cross-origin requests
        if ($originAllowed) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
            header('Access-Control-Max-Age: 86400'); // Cache preflight for 24h
        } else {
            // ENTERPRISE SECURITY: Log rejected cross-origin attempt
            Logger::security('warning', 'CORS: Rejected cross-origin request', [
                'origin' => $origin,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'endpoint' => '/api/logs/client',
            ]);
        }
    }
}
