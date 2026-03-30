<?php

/**
 * Web Routes - need2talk
 * Route per interfaccia utente web (non API)
 */

// Controllers for public and auth routes
use Need2Talk\Controllers\AuthController;
use Need2Talk\Controllers\HomeController;

// Home e pagine pubbliche
$router->get('/', [HomeController::class, 'index']);
$router->get('/home', [HomeController::class, 'index']);

// Autenticazione (Login/Register - pubblici)
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
// Logout è in authenticated.php (richiede auth middleware)

// Password reset (con EmailRateLimitMiddleware per sicurezza)
$router->get('/forgot-password', [AuthController::class, 'showForgotPassword']);
$router->post('/forgot-password', [AuthController::class, 'sendResetLink'], ['EmailRateLimitMiddleware']);
$router->get('/reset-password', [AuthController::class, 'showResetPassword']);
$router->post('/reset-password', [AuthController::class, 'resetPassword']);

// ==================== AUTHENTICATED ROUTES ====================
// Tutte le rotte per utenti autenticati sono definite in routes/authenticated.php
// Include il file delle rotte autenticate
require_once __DIR__ . '/authenticated.php';

// Pagine statiche e di servizio
$router->get('/about', [HomeController::class, 'about']);
$router->get('/legal/privacy', [HomeController::class, 'privacy']);
$router->get('/legal/terms', [HomeController::class, 'terms']);
$router->get('/legal/contacts', [HomeController::class, 'contacts']);
$router->get('/legal/report', [HomeController::class, 'report']);
$router->get('/help/faq', [HomeController::class, 'faq']);
$router->get('/help/guide', [HomeController::class, 'guide']);
$router->get('/help/safety', [HomeController::class, 'safety']);

// PWA offline fallback page
$router->get('/offline', [HomeController::class, 'offline']);

// Rotte di autenticazione con percorsi /auth/ prefix (aliases)
$router->get('/auth/login', [AuthController::class, 'showLogin']);
$router->post('/auth/login', [AuthController::class, 'login']);
$router->get('/auth/register', [AuthController::class, 'showRegister']);
$router->post('/auth/register', [AuthController::class, 'register']);
// /auth/logout è in authenticated.php (richiede auth middleware)

// Email Verification Routes
$router->get('/auth/verify-email', [AuthController::class, 'verifyEmail']);
$router->get('/auth/verify-email-sent', [AuthController::class, 'showVerificationSent']);
$router->post('/auth/clear-verification-session', [AuthController::class, 'clearVerificationSession']);
$router->get('/auth/resend-verification-form', [AuthController::class, 'showResendVerification']);
$router->post('/auth/resend-verification', [AuthController::class, 'resendVerification']);

// Google OAuth 2.0 Routes (Social Login - ENTERPRISE GALAXY)
$router->get('/auth/google', [AuthController::class, 'googleOAuthRedirect']);
$router->get('/auth/google/callback', [AuthController::class, 'googleOAuthCallback']);

// Profile Completion Routes (Post-OAuth GDPR Compliance - ENTERPRISE GALAXY FIX 2025-01-17)
// NEW OAuth users (status='pending') MUST complete profile before accessing site
// Requires authentication but NOT profile completion (whitelist in ProfileCompletionMiddleware)
$router->get('/complete-profile', [\Need2Talk\Controllers\CompleteProfileController::class, 'show'], ['AuthMiddleware']);
$router->post('/complete-profile', [\Need2Talk\Controllers\CompleteProfileController::class, 'complete'], ['AuthMiddleware']);

// Newsletter Unsubscribe Routes (Public - GDPR Compliant)
$router->get('/newsletter/unsubscribe/{token}', [\Need2Talk\Controllers\NewsletterUnsubscribeController::class, 'showUnsubscribe']);
$router->post('/newsletter/unsubscribe/{token}', [\Need2Talk\Controllers\NewsletterUnsubscribeController::class, 'processUnsubscribe']);
$router->post('/newsletter/resubscribe/{token}', [\Need2Talk\Controllers\NewsletterUnsubscribeController::class, 'resubscribe']);

// ENTERPRISE GALAXY: Newsletter Tracking Endpoints (Open Pixel & Click Tracking)
$router->get('/newsletter/track/open/{campaignId}/{recipientHash}', [\Need2Talk\Controllers\NewsletterTrackingController::class, 'trackOpen']);
$router->get('/newsletter/track/click/{campaignId}/{recipientHash}/{linkHash}', [\Need2Talk\Controllers\NewsletterTrackingController::class, 'trackClick']);

// Error routes
$router->get('/404', function () {
    http_response_code(404);
    include __DIR__ . '/../app/Views/errors/404.php';
});

$router->get('/403', function () {
    http_response_code(403);
    include __DIR__ . '/../app/Views/errors/403.php';
});

$router->get('/500', function () {
    http_response_code(500);
    include __DIR__ . '/../app/Views/errors/500.php';
});
