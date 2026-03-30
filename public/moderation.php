<?php

/**
 * need2talk - Moderation Portal Entry Point
 *
 * Enterprise Moderation Portal - Completely separate from Admin Panel
 * Handles all /mod_[hash]/... routes
 *
 * @package Need2Talk
 */

declare(strict_types=1);

use Need2Talk\Controllers\Moderation\ModerationController;
use Need2Talk\Middleware\ModerationAuthMiddleware;
use Need2Talk\Services\Moderation\ModerationSecurityService;

// ============================================================================
// MODERATION PORTAL ROUTING
// ============================================================================

// Get the full path and extract route after /mod_[hash]
$fullPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$modBasePath = ModerationSecurityService::generateModerationUrl();

// Extract the route part after /mod_[hash]
$route = '/';
if (strlen($fullPath) > strlen($modBasePath)) {
    $route = substr($fullPath, strlen($modBasePath));
}

// Ensure route starts with /
if (empty($route) || $route[0] !== '/') {
    $route = '/' . $route;
}

// ============================================================================
// ROUTE DEFINITIONS
// ============================================================================

$controller = new ModerationController();
$authMiddleware = new ModerationAuthMiddleware();

// PUBLIC ROUTES (No auth required)
$publicRoutes = [
    'GET' => [
        '/login' => 'showLogin',
        '/verify-2fa' => 'show2FA',
    ],
    'POST' => [
        '/login' => 'login',
        '/verify-2fa' => 'verify2FA',
    ],
];

// PROTECTED ROUTES (Require mod_auth)
$protectedRoutes = [
    'GET' => [
        '/' => 'dashboard',
        '/dashboard' => 'dashboard',
        '/live' => 'liveRooms',
        '/bans' => 'banManagement',
        '/keywords' => 'keywords',
        '/reports' => 'reports',
        '/log' => 'actionLog',
        '/team' => 'team',
        // API routes - Live Monitoring
        '/api/rooms/counts' => 'getRoomCounts',
        '/api/rooms/user-created' => 'getUserCreatedRooms',  // MUST be before {uuid} routes
        '/api/rooms/{uuid}/messages' => 'getRoomMessages',
        '/api/rooms/{uuid}/online' => 'getOnlineUsers',
        // API routes - Bans
        '/api/users/banned' => 'getBannedUsers',
        // API routes - User Moderation History (accepts UUID or numeric ID)
        '/api/users/{id}/moderation-history' => 'getUserModerationHistory',
    ],
    'POST' => [
        '/logout' => 'logout',
        // API routes - Live Monitoring
        '/api/rooms/heartbeat' => 'heartbeat',
        '/api/rooms/{uuid}/messages' => 'sendMessage',
        '/api/messages/delete' => 'deleteMessage',
        // API routes - Bans
        '/api/users/ban' => 'banUser',
        '/api/users/unban' => 'unbanUser',
        // API routes - Keywords
        '/api/keywords' => 'addKeyword',
        // API routes - Reports (Audio Posts)
        '/api/reports/dismiss' => 'dismissReport',
        '/api/reports/resolve' => 'resolveReport',
        '/api/reports/escalate' => 'escalateReport',
        '/api/reports/send-warning' => 'sendWarning',
        '/api/reports/hide-content' => 'hideContent',
        '/api/reports/unhide-content' => 'unhideContent',
        // API routes - Team
        '/api/team/create' => 'createModerator',
        '/api/team/toggle-status' => 'toggleModeratorStatus',
        '/api/team/send-url-email' => 'sendUrlEmail',
    ],
    'DELETE' => [
        '/api/keywords/{id}' => 'deleteKeyword',
    ],
];

// ============================================================================
// ROUTE MATCHING AND DISPATCH
// ============================================================================

$method = $_SERVER['REQUEST_METHOD'];
$matchedRoute = null;
$routeParams = [];

/**
 * Match route with parameters
 *
 * @param string $route The incoming route
 * @param string $pattern The route pattern with {param} placeholders
 * @return array|null Matched parameters or null
 */
function matchRoute(string $route, string $pattern): ?array
{
    // Convert {param} to regex capture groups
    $regex = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $pattern);
    $regex = '#^' . $regex . '$#';

    if (preg_match($regex, $route, $matches)) {
        // Filter only named groups (remove numeric keys)
        return array_filter($matches, function ($key) {
            return !is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);
    }

    return null;
}

// Check public routes first
if (isset($publicRoutes[$method])) {
    foreach ($publicRoutes[$method] as $pattern => $action) {
        $params = matchRoute($route, $pattern);
        if ($params !== null) {
            $matchedRoute = $action;
            $routeParams = $params;
            break;
        }
    }
}

// If not a public route, check protected routes
if ($matchedRoute === null && isset($protectedRoutes[$method])) {
    // Validate authentication FIRST
    if (!$authMiddleware->handle()) {
        // Middleware already handled the response (redirect or JSON error)
        exit;
    }

    foreach ($protectedRoutes[$method] as $pattern => $action) {
        $params = matchRoute($route, $pattern);
        if ($params !== null) {
            $matchedRoute = $action;
            $routeParams = $params;
            break;
        }
    }
}

// ============================================================================
// DISPATCH TO CONTROLLER
// ============================================================================

if ($matchedRoute === null) {
    // 404 - Route not found
    http_response_code(404);

    // Check if it's an API request
    if (strpos($route, '/api/') === 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Endpoint not found',
        ]);
    } else {
        echo '<!DOCTYPE html>';
        echo '<html><head><title>404 - Not Found</title>';
        echo '<style>body{background:#0f0f0f;color:#fff;font-family:system-ui;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}';
        echo 'h1{color:#ef4444;}.container{text-align:center;}a{color:#a855f7;}</style></head>';
        echo '<body><div class="container">';
        echo '<h1>404 - Page Not Found</h1>';
        echo '<p style="color:#9ca3af;">The requested moderation page does not exist.</p>';
        echo '<a href="' . htmlspecialchars($modBasePath) . '/dashboard">Return to Dashboard</a>';
        echo '</div></body></html>';
    }
    exit;
}

// Call the controller method
try {
    if (!empty($routeParams)) {
        // Pass route parameters as arguments
        $controller->$matchedRoute(...array_values($routeParams));
    } else {
        $controller->$matchedRoute();
    }
} catch (\Throwable $e) {
    // Log the error
    error_log('[MODERATION PORTAL] Error in ' . $matchedRoute . ': ' . $e->getMessage());
    error_log('[MODERATION PORTAL] Stack trace: ' . $e->getTraceAsString());

    http_response_code(500);

    if (strpos($route, '/api/') === 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
        ]);
    } else {
        echo '<!DOCTYPE html>';
        echo '<html><head><title>500 - Server Error</title>';
        echo '<style>body{background:#0f0f0f;color:#fff;font-family:system-ui;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}';
        echo 'h1{color:#ef4444;}.container{text-align:center;}a{color:#a855f7;}</style></head>';
        echo '<body><div class="container">';
        echo '<h1>500 - Server Error</h1>';
        echo '<p style="color:#9ca3af;">An internal error occurred. Please try again later.</p>';

        if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
            echo '<pre style="text-align:left;background:#1a1a1a;padding:20px;border-radius:8px;max-width:800px;overflow:auto;color:#f87171;">';
            echo htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString());
            echo '</pre>';
        }

        echo '<a href="' . htmlspecialchars($modBasePath) . '/dashboard">Return to Dashboard</a>';
        echo '</div></body></html>';
    }
}
