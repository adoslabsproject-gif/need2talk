<?php

/**
 * Admin Routes - need2talk Enterprise
 * Tutte le rotte admin centralizzate per architettura enterprise
 */

use Need2Talk\Controllers\AdminAccountDeletionsController;
use Need2Talk\Controllers\AdminAntiScanController;
use Need2Talk\Controllers\AdminAudioWorkerController;
use Need2Talk\Controllers\AdminDMAudioWorkerController;
use Need2Talk\Controllers\AdminOverlayWorkerController;
use Need2Talk\Controllers\AdminAuditLogController;
use Need2Talk\Controllers\AdminController;
use Need2Talk\Controllers\AdminEmailMetricsController;
use Need2Talk\Controllers\AdminEmotionalAnalyticsController;
use Need2Talk\Controllers\AdminJsErrorController;
use Need2Talk\Controllers\AdminPerformanceController;
use Need2Talk\Controllers\AdminSecurityEventsController;
use Need2Talk\Controllers\Api\StatsController;

// Authentication Routes (Public)
$router->get('/', [AdminController::class, 'showLogin']);
$router->get('/login', [AdminController::class, 'showLogin']);
$router->post('/login', [AdminController::class, 'login']);

// 2FA Routes
$router->get('/2fa', [AdminController::class, 'show2FA']);
$router->post('/verify-2fa', [AdminController::class, 'verify2FA']);

// Logout
$router->post('/logout', [AdminController::class, 'logout']);

// Session Check API - ENTERPRISE GALAXY: AdminSessionGuard heartbeat
$router->get('/api/session/check', [\Need2Talk\Controllers\Api\SessionController::class, 'check']);

// Emergency Access
$router->post('/emergency-login', [AdminController::class, 'emergencyLogin']);

// Admin Dashboard (Protected)
$router->get('/dashboard', [AdminController::class, 'dashboard']);

// Admin Management (Protected)
$router->get('/users', [AdminController::class, 'users']);
$router->get('/audio', [AdminController::class, 'audio']);
$router->get('/stats', [AdminController::class, 'stats']);
$router->post('/api/stats/invalidate-cache', [StatsController::class, 'invalidateCache']); // ENTERPRISE GALAXY: Granular cache invalidation
$router->get('/security', [AdminController::class, 'security']);
$router->get('/logs', [AdminController::class, 'logs']);
$router->get('/js-errors', [AdminController::class, 'jsErrors']);
$router->get('/js-error-test', [AdminController::class, 'jsErrorTest']);
$router->get('/security-test', [AdminController::class, 'securityTest']);
$router->get('/anti-scan', [AdminController::class, 'antiScan']);
$router->get('/legitimate-bots', [AdminController::class, 'legitimateBots']);
$router->get('/cookies', [AdminController::class, 'cookies']);
$router->get('/audit', [AdminController::class, 'audit']);
$router->get('/email-metrics', [AdminController::class, 'emailMetrics']);
$router->get('/emotional-analytics', [AdminController::class, 'emotionalAnalytics']); // 🧠 ENTERPRISE GALAXY: Emotional Analytics Dashboard
$router->get('/audio-workers', [AdminController::class, 'audioWorkers']); // ENTERPRISE GALAXY: Audio Workers Monitoring & Control
$router->get('/dm-audio-workers', [AdminController::class, 'dmAudioWorkers']); // ENTERPRISE GALAXY V4.3: DM Audio E2E Workers
$router->get('/overlay-workers', [AdminController::class, 'overlayWorkers']); // ENTERPRISE GALAXY V4.3: Overlay Flush Workers
$router->get('/notification-workers', [AdminController::class, 'notificationWorkers']); // ENTERPRISE GALAXY V11.6: Notification Workers
$router->get('/newsletter', [AdminController::class, 'newsletter']); // ENTERPRISE GALAXY: Newsletter Management
$router->get('/performance', [AdminController::class, 'performance']); // ENTERPRISE GALAXY: Performance Metrics
$router->get('/cron', [AdminController::class, 'cron']); // ENTERPRISE GALAXY: Cron Jobs Management
$router->get('/enterprise', [AdminController::class, 'enterprise']); // ENTERPRISE GALAXY V8.0: Distributed Workers & Feed Monitor

// Settings (Protected)
$router->get('/settings', [AdminController::class, 'settings']);

// CLI Terminal (Protected)
$router->get('/terminal', [AdminController::class, 'terminal']);
$router->post('/terminal-exec', [AdminController::class, 'terminalExec']);
$router->post('/settings', [AdminController::class, 'settings']);

// JS Errors Database API (Protected) - ENTERPRISE REAL-TIME
$router->get('/api/js-errors/database', [AdminJsErrorController::class, 'getDatabaseErrors']);

// NOTE: JS Errors Database Filter settings now handled by same-page POST in AdminController::settings()
// via AdminSettingsController - no dedicated API route needed

// Security Events Database API (Protected) - ENTERPRISE GALAXY REAL-TIME
$router->get('/api/security-events/database', [AdminSecurityEventsController::class, 'getDatabaseEvents']);

// Security Test API (Protected) - ENTERPRISE GALAXY SECURITY TESTING
// SECURITY FIX 2025-02-01: Moved from api.php to admin_routes.php for proper URL-based protection
$router->post('/api/security-test/generate', [\Need2Talk\Controllers\Api\SecurityTestController::class, 'generateTestEvent']);
$router->get('/api/security-test/recent', [\Need2Talk\Controllers\Api\SecurityTestController::class, 'getRecentEvents']);

// Anti-Scan System Database API (Protected) - ENTERPRISE GALAXY ANTI-BOT
$router->get('/api/anti-scan/database', [AdminAntiScanController::class, 'getDatabaseBans']);

// Legitimate Bots API (Protected) - ENTERPRISE GALAXY BOT PROTECTION
$router->get('/api/legitimate-bots/stats', [\Need2Talk\Controllers\AdminLegitimateBotController::class, 'getBotStats']);

// Admin Audit Log API (Protected) - ENTERPRISE AUDIT TRAIL
$router->get('/api/audit/database', [AdminAuditLogController::class, 'getDatabaseLogs']);
$router->get('/api/audit/export', [AdminAuditLogController::class, 'exportAuditLogs']);

// Performance Metrics API (Protected) - ENTERPRISE STATS & EXPORT
$router->get('/api/metrics/export', [\Need2Talk\Controllers\Api\SystemMetricsController::class, 'export']);

// Users and Rate Limiting API (Protected) - ENTERPRISE GALAXY RATE LIMIT MANAGEMENT
$router->get('/api/users/database', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'getUsersDataAPI']);
$router->post('/api/users/export-csv', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'exportUsersCSV']);
$router->get('/api/users/rate-limit-bans', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'getRateLimitBansAPI']);
$router->get('/api/users/rate-limit-log', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'getRateLimitLogAPI']);
$router->get('/api/users/rate-limit-violations', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'getRateLimitViolationsAPI']);
$router->get('/api/users/rate-limit-monitor', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'getRateLimitMonitorAPI']);
$router->get('/api/users/rate-limit-alerts', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'getRateLimitAlertsAPI']);
$router->post('/api/users/remove-ban', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'removeBan']);

// Bulk Operations API (Protected) - ENTERPRISE GALAXY BULK USER MANAGEMENT
$router->post('/api/users/bulk-suspend', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkSuspendUsers']);
$router->post('/api/users/bulk-activate', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkActivateUsers']);
$router->post('/api/users/bulk-deactivate', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkDeactivateUsers']);
$router->post('/api/users/bulk-delete', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkDeleteUsers']);
$router->post('/api/users/bulk-restore', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkRestoreUsers']); // ENTERPRISE V4.7: Restore soft-deleted users
$router->post('/api/users/bulk-force-verify', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkForceEmailVerification']);
$router->post('/api/users/bulk-ban', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkBanUsers']);
$router->post('/api/users/bulk-password-reset', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkSendPasswordReset']); // ENTERPRISE: AdminEmailWorker
$router->post('/api/users/bulk-email', [\Need2Talk\Controllers\AdminUsersAndRateLimitTabsController::class, 'bulkSendEmail']); // Temporary - will be replaced by AdminEmailWorker

// Email Metrics API (Protected) - ENTERPRISE EMAIL ANALYTICS
$router->get('/api/email-metrics/verification', [AdminEmailMetricsController::class, 'getVerificationMetrics']);
$router->get('/api/email-metrics/password-reset', [AdminEmailMetricsController::class, 'getPasswordResetMetrics']);
$router->get('/api/email-metrics/hourly', [AdminEmailMetricsController::class, 'getHourlyMetrics']);
$router->get('/api/email-metrics/daily', [AdminEmailMetricsController::class, 'getDailyMetrics']);
$router->get('/api/email-metrics/idempotency', [AdminEmailMetricsController::class, 'getIdempotencyLog']);
$router->get('/api/email-metrics/export', [AdminEmailMetricsController::class, 'exportMetrics']);

// 🧠 Emotional Analytics API Routes (ENTERPRISE GALAXY)
$router->get('/api/emotional-analytics/insights', [AdminEmotionalAnalyticsController::class, 'getInsightsByPeriod']);
$router->get('/api/emotional-analytics/export', [AdminEmotionalAnalyticsController::class, 'exportInsights']);

// Email Verification Workers Systemd Control (Protected) - ENTERPRISE GALAXY AUTO-RESTART
// NOTA: Questi sono per verification workers (email-worker.php con systemd auto-restart)
// AdminEmailWorkerController è SOLO per newsletter workers (stand-alone, manuale, 8h runtime)
$router->get('/api/email-workers/status', [AdminEmailMetricsController::class, 'getSystemdWorkerStatus']);
$router->post('/api/email-workers/start', [AdminEmailMetricsController::class, 'startSystemdWorkers']);
$router->post('/api/email-workers/stop', [AdminEmailMetricsController::class, 'stopSystemdWorkers']);
$router->post('/api/email-workers/restart', [AdminEmailMetricsController::class, 'restartSystemdWorkers']);
$router->post('/api/email-workers/enable', [AdminEmailMetricsController::class, 'enableSystemdWorkers']);
$router->post('/api/email-workers/disable', [AdminEmailMetricsController::class, 'disableSystemdWorkers']);
$router->get('/api/email-workers/logs', [AdminEmailMetricsController::class, 'getSystemdWorkerLogs']);

// Enterprise Galaxy V8.0 - Distributed Workers & Feed Pre-Computation Monitoring
$router->get('/api/enterprise/status', [\Need2Talk\Controllers\AdminEnterpriseMonitorController::class, 'getStatus']);
$router->post('/api/enterprise/scale-overlay-workers', [\Need2Talk\Controllers\AdminEnterpriseMonitorController::class, 'scaleOverlayWorkers']);
$router->post('/api/enterprise/scale-feed-workers', [\Need2Talk\Controllers\AdminEnterpriseMonitorController::class, 'scaleFeedWorkers']);
$router->get('/api/enterprise/worker-logs', [\Need2Talk\Controllers\AdminEnterpriseMonitorController::class, 'getWorkerLogs']);
$router->post('/api/enterprise/reset-metrics', [\Need2Talk\Controllers\AdminEnterpriseMonitorController::class, 'resetMetrics']);

// Performance Metrics API (Protected) - ENTERPRISE GALAXY PERFORMANCE MONITORING
$router->get('/api/performance/detailed', [AdminPerformanceController::class, 'getDetailedMetrics']);
$router->get('/api/performance/page', [AdminPerformanceController::class, 'getPageMetrics']);
$router->get('/api/performance/export', [AdminPerformanceController::class, 'exportMetrics']);
$router->post('/api/performance/cleanup', [AdminPerformanceController::class, 'clearOldMetrics']);

// Cron Jobs API (Protected) - ENTERPRISE GALAXY CRON MANAGEMENT
$router->get('/api/cron/jobs', [\Need2Talk\Controllers\AdminCronController::class, 'getAllJobs']);
$router->post('/api/cron/toggle', [\Need2Talk\Controllers\AdminCronController::class, 'toggleJob']);
$router->post('/api/cron/execute', [\Need2Talk\Controllers\AdminCronController::class, 'executeJob']);
$router->get('/api/cron/history', [\Need2Talk\Controllers\AdminCronController::class, 'getJobHistory']);
$router->get('/api/cron/health', [\Need2Talk\Controllers\AdminCronController::class, 'getHealthStatus']);
// ENTERPRISE GALAXY V4.7: Cron Worker Container Control
$router->get('/api/cron/worker-status', [\Need2Talk\Controllers\AdminCronController::class, 'getWorkerStatus']);
$router->get('/api/cron/worker-logs', [\Need2Talk\Controllers\AdminCronController::class, 'getWorkerLogs']);
$router->post('/api/cron/worker-control', [\Need2Talk\Controllers\AdminCronController::class, 'workerControl']);

// Newsletter API (Protected) - ENTERPRISE GALAXY NEWSLETTER MANAGEMENT
$router->post('/api/newsletter/create', [\Need2Talk\Controllers\AdminNewsletterController::class, 'createCampaign']);
$router->post('/api/newsletter/send', [\Need2Talk\Controllers\AdminNewsletterController::class, 'sendCampaign']);
$router->get('/api/newsletter/stats', [\Need2Talk\Controllers\AdminNewsletterController::class, 'getStats']);
$router->post('/api/newsletter/upload-image', [\Need2Talk\Controllers\AdminNewsletterController::class, 'uploadImage']); // ENTERPRISE: TinyMCE image upload

// Newsletter Worker API (Protected) - ENTERPRISE GALAXY DEDICATED CONTAINER CONTROL
$router->get('/api/newsletter-worker/status', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'getStatus']);
$router->post('/api/newsletter-worker/start', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'start']);
$router->post('/api/newsletter-worker/stop', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'stop']);
$router->post('/api/newsletter-worker/restart', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'restart']);
$router->post('/api/newsletter-worker/stop-clean', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'stopAndClean']);
$router->get('/api/newsletter-worker/health', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'getHealth']);
$router->post('/api/newsletter-worker/enable-autostart', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'enableAutostart']);
$router->post('/api/newsletter-worker/disable-autostart', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'disableAutostart']);
$router->get('/api/newsletter-worker/logs', [\Need2Talk\Controllers\AdminNewsletterWorkerApiController::class, 'getLogs']);

// Audio Workers API (Protected) - ENTERPRISE GALAXY AUDIO UPLOAD & S3 PROCESSING
$router->get('/api/audio-workers/status', [AdminAudioWorkerController::class, 'getStatus']);
$router->post('/api/audio-workers/start', [AdminAudioWorkerController::class, 'start']);
$router->post('/api/audio-workers/stop', [AdminAudioWorkerController::class, 'stop']);
$router->post('/api/audio-workers/scale', [AdminAudioWorkerController::class, 'scale']);
$router->get('/api/audio-workers/logs', [AdminAudioWorkerController::class, 'getLogs']);
$router->get('/api/audio-workers/autostart-status', [AdminAudioWorkerController::class, 'getAutostartStatus']);
$router->post('/api/audio-workers/set-autostart', [AdminAudioWorkerController::class, 'setAutostart']);

// DM Audio E2E Workers API (Protected) - ENTERPRISE GALAXY V4.3 DM AUDIO E2E PROCESSING
$router->get('/api/dm-audio-workers/status', [AdminDMAudioWorkerController::class, 'getStatus']);
$router->post('/api/dm-audio-workers/start', [AdminDMAudioWorkerController::class, 'start']);
$router->post('/api/dm-audio-workers/stop', [AdminDMAudioWorkerController::class, 'stop']);
$router->post('/api/dm-audio-workers/scale', [AdminDMAudioWorkerController::class, 'scale']);
$router->post('/api/dm-audio-workers/auto-scale', [AdminDMAudioWorkerController::class, 'autoScale']);
$router->get('/api/dm-audio-workers/logs', [AdminDMAudioWorkerController::class, 'getLogs']);
$router->get('/api/dm-audio-workers/autostart-status', [AdminDMAudioWorkerController::class, 'getAutostartStatus']);
$router->post('/api/dm-audio-workers/set-autostart', [AdminDMAudioWorkerController::class, 'setAutostart']);
$router->post('/api/dm-audio-workers/cleanup-failed', [AdminDMAudioWorkerController::class, 'cleanupFailed']);

// Overlay Flush Workers API (Protected) - ENTERPRISE GALAXY V4.3 OVERLAY FLUSH PROCESSING
$router->get('/api/overlay-workers/status', [AdminOverlayWorkerController::class, 'getStatus']);
$router->post('/api/overlay-workers/start', [AdminOverlayWorkerController::class, 'start']);
$router->post('/api/overlay-workers/stop', [AdminOverlayWorkerController::class, 'stop']);
$router->post('/api/overlay-workers/scale', [AdminOverlayWorkerController::class, 'scale']);
$router->post('/api/overlay-workers/auto-scale', [AdminOverlayWorkerController::class, 'autoScale']);
$router->post('/api/overlay-workers/force-flush', [AdminOverlayWorkerController::class, 'forceFlush']);
$router->get('/api/overlay-workers/autostart-status', [AdminOverlayWorkerController::class, 'getAutostartStatus']);
$router->post('/api/overlay-workers/set-autostart', [AdminOverlayWorkerController::class, 'setAutostart']);

// Notification Workers API (Protected) - ENTERPRISE GALAXY V11.6 ASYNC NOTIFICATION QUEUE
$router->get('/api/notification-workers/status', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'getStatus']);
$router->post('/api/notification-workers/start', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'start']);
$router->post('/api/notification-workers/stop', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'stop']);
$router->post('/api/notification-workers/restart', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'restart']);
$router->post('/api/notification-workers/scale', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'scale']);
$router->post('/api/notification-workers/enable-async', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'enableAsync']);
$router->post('/api/notification-workers/disable-async', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'disableAsync']);
$router->get('/api/notification-workers/queue-stats', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'getQueueStats']);
$router->post('/api/notification-workers/clear-queues', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'clearQueues']);
$router->post('/api/notification-workers/process-failed', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'processFailedQueue']);
$router->get('/api/notification-workers/monitoring', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'getMonitoringOutput']);
$router->get('/api/notification-workers/autostart-status', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'getAutostartStatus']);
$router->post('/api/notification-workers/set-autostart', [\Need2Talk\Controllers\AdminNotificationWorkerController::class, 'setAutostart']);

// Newsletter Worker Control API (Protected) - ENTERPRISE GALAXY NEWSLETTER WORKER MANAGEMENT
$router->post('/api/worker/start', [\Need2Talk\Controllers\NewsletterWorkerApiController::class, 'startWorker']);
$router->post('/api/worker/stop', [\Need2Talk\Controllers\NewsletterWorkerApiController::class, 'stopWorker']);
$router->post('/api/worker/stop-clean', [\Need2Talk\Controllers\NewsletterWorkerApiController::class, 'stopAndClean']);
$router->get('/api/worker/status', [\Need2Talk\Controllers\NewsletterWorkerApiController::class, 'getStatus']);
$router->get('/api/worker/health', [\Need2Talk\Controllers\NewsletterWorkerApiController::class, 'getHealth']); // ENTERPRISE GALAXY: Health monitoring
$router->post('/api/worker/performance-test', [\Need2Talk\Controllers\NewsletterWorkerApiController::class, 'performanceTest']);
$router->get('/api/worker/monitor', [\Need2Talk\Controllers\NewsletterWorkerApiController::class, 'getMonitorOutput']);

// 🛡️ ENTERPRISE GALAXY: Moderators Management
$router->get('/moderators', [AdminController::class, 'moderators']);
$router->get('/api/moderators/list', [\Need2Talk\Controllers\AdminModeratorsController::class, 'getList']);
$router->post('/api/moderators/create', [\Need2Talk\Controllers\AdminModeratorsController::class, 'create']);
$router->post('/api/moderators/update', [\Need2Talk\Controllers\AdminModeratorsController::class, 'update']);
$router->post('/api/moderators/toggle-status', [\Need2Talk\Controllers\AdminModeratorsController::class, 'toggleStatus']);
$router->post('/api/moderators/delete', [\Need2Talk\Controllers\AdminModeratorsController::class, 'delete']);
$router->post('/api/moderators/reset-password', [\Need2Talk\Controllers\AdminModeratorsController::class, 'resetPassword']);
$router->get('/api/moderators/{id}/activity', [\Need2Talk\Controllers\AdminModeratorsController::class, 'getActivity']);
$router->get('/api/moderators/portal-url', [\Need2Talk\Controllers\AdminModeratorsController::class, 'getPortalUrl']);
$router->post('/api/moderators/send-portal-url', [\Need2Talk\Controllers\AdminModeratorsController::class, 'sendPortalUrl']);

// 🗑️ ENTERPRISE GALAXY: Account Deletions Dashboard (GDPR Article 17)
$router->get('/account-deletions', [AdminAccountDeletionsController::class, 'dashboard']);

// Account Deletions API (Protected) - ENTERPRISE GDPR COMPLIANCE MONITORING
$router->get('/api/account-deletions/timeline', [AdminAccountDeletionsController::class, 'getTimelineData']);
$router->get('/api/account-deletions/details/{id}', [AdminAccountDeletionsController::class, 'getDeletionDetails']);
$router->get('/api/account-deletions/export-csv', [AdminAccountDeletionsController::class, 'exportCsv']);
$router->post('/api/account-deletions/refresh-dashboard', [AdminAccountDeletionsController::class, 'refreshDashboard']);
$router->post('/api/account-deletions/clear-cache', [AdminAccountDeletionsController::class, 'clearCache']);

// 🧠 ML Security & DDoS Protection API (Protected) - ENTERPRISE GALAXY AI SECURITY
$router->get('/ml-security', [AdminController::class, 'mlSecurity']);
$router->get('/api/ml-security/status', [\Need2Talk\Controllers\AdminMLSecurityController::class, 'getStatus']);
$router->post('/api/ml-security/config', [\Need2Talk\Controllers\AdminMLSecurityController::class, 'updateConfig']);
$router->post('/api/ml-security/retrain', [\Need2Talk\Controllers\AdminMLSecurityController::class, 'retrain']);
$router->post('/api/ml-security/unban', [\Need2Talk\Controllers\AdminMLSecurityController::class, 'unbanIP']);

// System Actions (Protected)
$router->get('/system-action', [AdminController::class, 'systemAction']); // Read-only actions (view_log, search_log)
$router->post('/system-action', [AdminController::class, 'systemAction']); // Write actions

// Debugbar (Development)
$router->get('/debugbar/open', [AdminController::class, 'debugbarOpen']);
$router->post('/debugbar/open', [AdminController::class, 'debugbarOpen']);
