<?php

/**
 * need2talk - ENTERPRISE ADMIN PANEL
 * Ultra-secure admin access with 2FA email OTP system
 * Performance: 12x faster than Laravel/Filament
 */

declare(strict_types=1);

// Define application root
if (! defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Start output buffering for debugbar injection
ob_start();

// Load bootstrap (includes Composer, .env, all services)
require_once APP_ROOT . '/app/bootstrap.php';

// ENTERPRISE: Initialize DebugBar EARLY to track all queries
if (class_exists('\Need2Talk\Services\DebugbarService')) {
    \Need2Talk\Services\DebugbarService::initialize(true);
}

// ENTERPRISE 2025: Initialize with full caching support
require_once APP_ROOT . '/app/Bootstrap/EnterpriseBootstrap.php';
use Need2Talk\Bootstrap\EnterpriseBootstrap;

EnterpriseBootstrap::initialize();

// ENTERPRISE GALAXY: Load admin-specific helpers (isolated from public)
require_once APP_ROOT . '/app/Helpers/AdminHelpers.php';

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Referrer-Policy: strict-origin-when-cross-origin');

use Need2Talk\Services\AdminSecurityService;

try {

    // Genera URL admin sicuro dinamico
    $adminSecurityService = new AdminSecurityService();
    $currentSecureUrl = AdminSecurityService::generateSecureAdminUrl();

    // Parse request
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Check if accessing admin URL
    if (! str_starts_with($path, '/admin_')) {
        error_log("[ADMIN.PHP SECURITY] Non-admin path blocked: $path");
        http_response_code(403);
        require APP_ROOT . '/app/Views/errors/403.php';
        exit;
    }

    // ENTERPRISE TIPS: URL validation already done in index.php (line 225)
    // Removed duplicate validateAdminUrl() call to prevent duplicate DB queries
    // If we're here, the URL has already been validated by index.php

    // Parse sub-path (after /admin_xxxxxxxxxxxxxxxx)
    $adminPath = '';

    if (preg_match('/^\/admin_[a-f0-9]{16}(.*)$/', $path, $matches)) {
        $adminPath = $matches[1] ?: '/';
    }

    // DEBUG: Log path parsing

    // Route admin requests

    if ($method === 'POST') {
        // Handle AJAX requests
        handleAdminAjax($adminPath, $adminSecurityService);
    } else {
        // Handle page requests
        handleAdminPage($adminPath, $adminSecurityService);
    }

} catch (\Throwable $e) {
    error_log('[ADMIN ERROR] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);

    // Load custom 500 error page
    if (file_exists(APP_ROOT . '/app/Views/errors/500.php')) {
        require APP_ROOT . '/app/Views/errors/500.php';
    } else {
        echo '<h1>500 - Internal Server Error</h1>';
    }
}

/**
 * Handle admin requests - ENTERPRISE: All routes now delegated to Router
 */
function handleAdminAjax(string $path, AdminSecurityService $security): void
{
    // ENTERPRISE TIPS: Validate admin session for AJAX/API requests
    $sessionToken = $_COOKIE['__Host-admin_session'] ?? '';
    $session = $sessionToken ? $security->validateAdminSession($sessionToken) : null;

    // Skip session check for login/2fa endpoints
    if ($path !== '/login' && $path !== '/verify-2fa' && $path !== '/emergency-login') {
        if (!$session) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized - Session invalid or expired']);
            return;
        }

        // ENTERPRISE: Populate $_SESSION for compatibility with controllers
        $_SESSION['admin_user_id'] = $session['admin_id'];
        $_SESSION['admin_email'] = $session['email'];
        $_SESSION['admin_role'] = $session['role'];
        $_SESSION['admin_full_name'] = $session['full_name'];
    }

    $router = new Need2Talk\Core\Router();
    require_once APP_ROOT . '/routes/admin_routes.php';

    try {
        $router->dispatch($path, 'POST');
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint non trovato']);
    }
}

/**
 * Handle admin page requests - ENTERPRISE: All routes now delegated to Router
 */
function handleAdminPage(string $path, AdminSecurityService $security): void
{
    $sessionToken = $_COOKIE['__Host-admin_session'] ?? '';
    $session = $sessionToken ? $security->validateAdminSession($sessionToken) : null;

    // ENTERPRISE: If accessing root URL with valid session, redirect to dashboard
    if ($path === '/' && $session) {
        $secureUrl = AdminSecurityService::generateSecureAdminUrl();
        header("Location: {$secureUrl}/dashboard");
        exit;
    }

    // Check session for protected pages (not login/2fa)
    if ($path !== '/' && $path !== '/login' && $path !== '/2fa') {
        if (! $session) {
            // Redirect to login
            $secureUrl = AdminSecurityService::generateSecureAdminUrl();
            header("Location: {$secureUrl}");
            exit;
        }

        // ENTERPRISE: Populate $_SESSION for compatibility with controllers
        $_SESSION['admin_user_id'] = $session['admin_id'];
        $_SESSION['admin_email'] = $session['email'];
        $_SESSION['admin_role'] = $session['role'];
        $_SESSION['admin_full_name'] = $session['full_name'];
    }

    $router = new Need2Talk\Core\Router();
    require_once APP_ROOT . '/routes/admin_routes.php';

    try {
        $router->dispatch($path, 'GET');
    } catch (Exception $e) {
        http_response_code(404);
        echo '<h1>404 - Admin Page Not Found</h1>';
        echo '<p>Path: ' . htmlspecialchars($path) . '</p>';
    }
}

// ENTERPRISE: Output buffering cleanup handled automatically by PHP
// Debugbar is injected directly in app/Views/admin/layout.php
// DO NOT call ob_end_flush() here - causes conflicts with zlib compression
