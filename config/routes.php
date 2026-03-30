<?php

/**
 * Routes Configuration - need2talk
 * Caricamento modularizzato delle route divise per contesto
 */

// ===== WEB ROUTES =====
// Route per interfaccia utente web (HTML responses)
require_once APP_ROOT . '/routes/web.php';

// ===== API ROUTES =====
// Route per API endpoints (JSON responses)
require_once APP_ROOT . '/routes/api.php';

// ===== ADMIN ROUTES ===== (ENTERPRISE GALAXY)
// Admin panel routes + Admin API endpoints
// ENTERPRISE SECURITY FIX (2025-11-12): Admin routes are loaded ONLY via admin.php
// Loading them here creates PUBLIC routes like /dashboard, /users, etc. (SECURITY BREACH!)
// admin.php loads admin_routes.php with proper authentication and URL prefix validation
// require_once APP_ROOT . '/routes/admin_routes.php'; // DISABLED - LOADED BY admin.php ONLY

// ===== INTERNAL ROUTES =====
// Route per comunicazione interna (WebSocket, Cron, AI services)
require_once APP_ROOT . '/routes/internal.php';

// ===== HONEYPOT ROUTES ===== (ENTERPRISE GALAXY)
// ANTI-SCAN SYSTEM: Fake vulnerable endpoints per catturare bot scanner
// Accesso = BAN IMMEDIATO (7 giorni) + log CRITICAL + alert security team
require_once APP_ROOT . '/routes/honeypot.php';

// ===== FALLBACK ROUTES =====
// ✅ IMPLEMENTED: Intelligent 404 handler with anti-scanning detection
// ENTERPRISE GALAXY ANTI-SCAN SYSTEM:
// - Rileva pattern di vulnerability scanning nei 404
// - Incrementa score IP per 404 multipli
// - Ban automatico per scanning behavior
// - Log centralizzato dual-write (DB + file)
// Note: Il Router già gestisce 404 automaticamente (Router.php:88-90)
// Il middleware AntiVulnerabilityScanningMiddleware traccia tutti i 404 sospetti
