<?php

/**
 * Moderation Portal Routes - need2talk Enterprise
 *
 * Routes for the Moderation Portal (completely separate from Admin Panel)
 * URL pattern: /mod_[16-char-hex]/...
 *
 * @package Need2Talk\Routes
 */

use Need2Talk\Controllers\Moderation\ModerationController;

// ============================================================================
// AUTHENTICATION ROUTES (No middleware - public)
// ============================================================================

$router->get('/login', [ModerationController::class, 'showLogin']);
$router->post('/login', [ModerationController::class, 'login']);
$router->get('/verify-2fa', [ModerationController::class, 'show2FA']);
$router->post('/verify-2fa', [ModerationController::class, 'verify2FA']);
$router->post('/logout', [ModerationController::class, 'logout']);

// ============================================================================
// PROTECTED ROUTES (Require mod_auth middleware)
// ============================================================================

// Dashboard
$router->get('/', [ModerationController::class, 'dashboard']);
$router->get('/dashboard', [ModerationController::class, 'dashboard']);

// Live Monitoring
$router->get('/live', [ModerationController::class, 'liveRooms']);
$router->get('/api/rooms/counts', [ModerationController::class, 'getRoomCounts']);
$router->get('/api/rooms/user-created', [ModerationController::class, 'getUserCreatedRooms']);
$router->post('/api/rooms/heartbeat', [ModerationController::class, 'heartbeat']);
$router->get('/api/rooms/{uuid}/messages', [ModerationController::class, 'getRoomMessages']);
$router->get('/api/rooms/{uuid}/online', [ModerationController::class, 'getOnlineUsers']);
$router->post('/api/rooms/{uuid}/messages', [ModerationController::class, 'sendMessage']);
$router->post('/api/messages/delete', [ModerationController::class, 'deleteMessage']);

// Ban Management
$router->get('/bans', [ModerationController::class, 'banManagement']);
$router->post('/api/users/ban', [ModerationController::class, 'banUser']);
$router->post('/api/users/unban', [ModerationController::class, 'unbanUser']);
$router->get('/api/users/banned', [ModerationController::class, 'getBannedUsers']);

// Keyword Management
$router->get('/keywords', [ModerationController::class, 'keywords']);
$router->post('/api/keywords', [ModerationController::class, 'addKeyword']);
$router->delete('/api/keywords/{id}', [ModerationController::class, 'deleteKeyword']);

// Reports
$router->get('/reports', [ModerationController::class, 'reports']);
$router->post('/api/reports/resolve', [ModerationController::class, 'resolveReport']);
$router->post('/api/reports/escalate', [ModerationController::class, 'escalateReport']);
$router->post('/api/reports/dismiss', [ModerationController::class, 'dismissReport']);
$router->post('/api/reports/send-warning', [ModerationController::class, 'sendWarning']);
$router->post('/api/reports/hide-content', [ModerationController::class, 'hideContent']);
$router->post('/api/reports/unhide-content', [ModerationController::class, 'unhideContent']);

// User Moderation History
$router->get('/api/users/{id}/moderation-history', [ModerationController::class, 'getUserModerationHistory']);

// Audit Log
$router->get('/log', [ModerationController::class, 'actionLog']);

// Team Management
$router->get('/team', [ModerationController::class, 'team']);
$router->post('/api/team/create', [ModerationController::class, 'createModerator']);
$router->post('/api/team/toggle-status', [ModerationController::class, 'toggleModeratorStatus']);
$router->post('/api/team/send-url-email', [ModerationController::class, 'sendUrlEmail']);
