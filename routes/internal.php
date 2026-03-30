<?php

/**
 * Internal Routes - need2talk
 * Route per comunicazione interna tra sistemi (WebSocket, Cron, etc.)
 *
 * SECURITY FIX 2025-02-01:
 * All internal endpoints require authentication via:
 * 1. Internal API token (X-Internal-Token header)
 * 2. OR localhost/Docker network origin
 */

use Need2Talk\Services\Logger;

// Prefisso per route interne
$internalPrefix = '/internal';

/**
 * ENTERPRISE SECURITY: Validate internal API access
 * Only allows:
 * - Requests from localhost/Docker network (172.x.x.x, 10.x.x.x)
 * - Requests with valid X-Internal-Token header
 */
$validateInternalAccess = function (): bool {
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';

    // Allow Docker internal network and localhost
    if (
        str_starts_with($clientIP, '172.') ||
        str_starts_with($clientIP, '10.') ||
        str_starts_with($clientIP, '192.168.') ||
        $clientIP === '127.0.0.1' ||
        $clientIP === '::1'
    ) {
        return true;
    }

    // Check for internal API token
    $token = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
    $expectedToken = $_ENV['INTERNAL_API_TOKEN'] ?? '';

    // If no token configured, deny all external access
    if (empty($expectedToken)) {
        Logger::security('warning', 'INTERNAL API: Access denied - no token configured', [
            'ip' => $clientIP,
            'path' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        return false;
    }

    if (hash_equals($expectedToken, $token)) {
        return true;
    }

    Logger::security('warning', 'INTERNAL API: Unauthorized access attempt', [
        'ip' => $clientIP,
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'has_token' => !empty($token),
    ]);

    return false;
};

// Apply security check to all internal routes
$internalMiddleware = function () use ($validateInternalAccess) {
    if (!$validateInternalAccess()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden', 'message' => 'Internal API access denied']);
        exit;
    }
};

// ===== WEBSOCKET BRIDGE =====
// Endpoint per WebSocket Server -> PHP communication
$router->post("$internalPrefix/websocket/notification", function () use ($internalMiddleware) {
    $internalMiddleware(); // SECURITY: Validate internal access

    // Riceve notifiche dal server WebSocket per processing
    $input = json_decode(file_get_contents('php://input'), true);

    // TODO: Implementare processing notifiche dal WebSocket
    echo json_encode([
        'success' => true,
        'message' => 'Notification received',
        // SECURITY FIX: Don't echo back untrusted input
        'received' => true,
    ]);
});

$router->post("$internalPrefix/websocket/user-event", function () use ($internalMiddleware) {
    $internalMiddleware(); // SECURITY: Validate internal access

    // Eventi utente dal WebSocket (login, logout, etc.)
    $input = json_decode(file_get_contents('php://input'), true);

    // TODO: Implementare processing eventi utente
    echo json_encode([
        'success' => true,
        'message' => 'User event processed',
        // SECURITY FIX: Don't expose event details
    ]);
});

// ===== CRON ENDPOINTS =====
// Endpoint per job cron che necessitano di accesso web
$router->post("$internalPrefix/cron/cleanup", function () use ($internalMiddleware) {
    $internalMiddleware(); // SECURITY: Validate internal access

    // Cleanup automatico
    // TODO: Implementare cleanup via endpoint
    echo json_encode([
        'success' => true,
        'message' => 'Cleanup executed',
        'timestamp' => date('c'),
    ]);
});

$router->post("$internalPrefix/cron/email-queue", function () use ($internalMiddleware) {
    $internalMiddleware(); // SECURITY: Validate internal access

    // Processing coda email
    // TODO: Implementare processing email queue
    echo json_encode([
        'success' => true,
        'message' => 'Email queue processed',
        'processed' => 0,
    ]);
});

// ===== AI SERVICE BRIDGE =====
$router->post("$internalPrefix/ai/moderation-result", function () use ($internalMiddleware) {
    $internalMiddleware(); // SECURITY: Validate internal access

    // Risultati moderazione AI
    $input = json_decode(file_get_contents('php://input'), true);

    // TODO: Implementare processing risultati AI
    echo json_encode([
        'success' => true,
        'message' => 'AI moderation result processed',
        // SECURITY FIX: Don't expose audio_id from untrusted input
    ]);
});

// ===== HEALTH CHECKS =====
$router->get("$internalPrefix/health/database", function () use ($internalMiddleware) {
    $internalMiddleware(); // SECURITY: Validate internal access

    try {
        // ENTERPRISE: Use new database pool instead of legacy singleton
        $pool = \Need2Talk\Services\EnterpriseSecureDatabasePool::getInstance();
        $db = $pool->getConnection();
        $stmt = $db->query('SELECT 1 as health_check');
        $result = $stmt->fetch();

        // Release connection back to pool
        $pool->releaseConnection($db);

        echo json_encode([
            'status' => 'healthy',
            'service' => 'database',
            'timestamp' => date('c'),
            // SECURITY FIX: Don't expose pool details
        ]);
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode([
            'status' => 'unhealthy',
            'service' => 'database',
            'timestamp' => date('c'),
            // SECURITY FIX: Don't expose error messages externally
        ]);
    }
});

$router->get("$internalPrefix/health/websocket", function () use ($internalMiddleware) {
    $internalMiddleware(); // SECURITY: Validate internal access

    // TODO: Check WebSocket server status
    echo json_encode([
        'status' => 'unknown',
        'service' => 'websocket',
        'timestamp' => date('c'),
    ]);
});
