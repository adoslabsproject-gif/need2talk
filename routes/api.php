<?php

/**
 * API Routes - need2talk
 * Route per API endpoints (JSON responses)
 */

use Need2Talk\Controllers\Api\AnalyticsController;
// use Need2Talk\Controllers\Api\AudioApiController;  // REMOVED: Legacy stub, replaced with enterprise audio system
use Need2Talk\Controllers\Api\AuthApiController;
use Need2Talk\Controllers\Api\CookieConsentController;
use Need2Talk\Controllers\Api\NotificationController;
use Need2Talk\Controllers\Api\ProfileApiController;
use Need2Talk\Controllers\Api\SessionController;
use Need2Talk\Controllers\Api\StatsController;
use Need2Talk\Controllers\Api\SystemMetricsController;
use Need2Talk\Controllers\Api\UserSearchController;
use Need2Talk\Controllers\Api\ValidationController;
use Need2Talk\Controllers\ReportController;

// Prefisso API per tutte le route
$apiPrefix = '/api';

// ===== AUTH API =====
$router->post("$apiPrefix/auth/login", [AuthApiController::class, 'login']);
$router->post("$apiPrefix/auth/register", [AuthApiController::class, 'register']);
$router->post("$apiPrefix/auth/logout", [AuthApiController::class, 'logout']);
$router->post("$apiPrefix/auth/refresh", [AuthApiController::class, 'refresh']);
$router->get("$apiPrefix/auth/user", [AuthApiController::class, 'user']);

// Password reset API
$router->post("$apiPrefix/auth/forgot-password", [AuthApiController::class, 'forgotPassword']);
$router->post("$apiPrefix/auth/reset-password", [AuthApiController::class, 'resetPassword']);

// ENTERPRISE GALAXY: WebSocket JWT token generation
$router->get("$apiPrefix/auth/websocket-token", [AuthApiController::class, 'getWebSocketToken'], ['auth']);

// ENTERPRISE V11.9: Auth check for PWA back-button security
// Returns 200 if authenticated, 401 if not (used by pageshow event listener)
$router->get("$apiPrefix/auth/check", [AuthApiController::class, 'checkAuth']);

// ===== ONBOARDING API ===== (Enterprise Galaxy v1.0 - 2026-01-19)
use Need2Talk\Controllers\Api\OnboardingController;
$router->get("$apiPrefix/onboarding/status", [OnboardingController::class, 'status'], ['auth']);
$router->post("$apiPrefix/onboarding/progress", [OnboardingController::class, 'progress'], ['auth']);

// ===== AUDIO API ===== (Enterprise Galaxy)
use Need2Talk\Controllers\Api\Audio\AudioController;
use Need2Talk\Controllers\Api\Audio\AudioSocialController;

// ===== EMOTIONAL REACTIONS API ===== (Enterprise Galaxy - 10 emotions)
// IMPORTANT: These routes MUST come BEFORE generic /audio/{id} routes to avoid conflicts!
// e.g., DELETE /api/audio/reaction/2 must NOT match DELETE /api/audio/{id}
use Need2Talk\Controllers\Api\AudioReactionController;

// Reaction management (replaces simple likes)
$router->post("$apiPrefix/audio/reaction", [AudioReactionController::class, 'addReaction']);
$router->delete("$apiPrefix/audio/reaction/{audioPostId}", [AudioReactionController::class, 'removeReaction']);
$router->get("$apiPrefix/audio/reactions/{audioPostId}", [AudioReactionController::class, 'getPostReactionStats']);

// Audio management
$router->post("$apiPrefix/audio/upload", [AudioController::class, 'upload']);
$router->get("$apiPrefix/audio/feed", [AudioController::class, 'feed']);
$router->get("$apiPrefix/audio/rate-limit-check", [AudioController::class, 'rateLimitCheck']);
$router->get("$apiPrefix/audio/photos/recent", [AudioController::class, 'getRecentPhotos']);
$router->get("$apiPrefix/audio/{id}", [AudioController::class, 'show']);
$router->get("$apiPrefix/audio/{id}/stream", [AudioController::class, 'stream']);
$router->delete("$apiPrefix/audio/{id}", [AudioController::class, 'delete']);

// ENTERPRISE GALAXY (2025-11-21): Audio post editing (privacy + content)
// Used by AudioDayModal.js for in-place editing without page reload
$router->patch("$apiPrefix/audio/{id}/privacy", [AudioController::class, 'updatePrivacy']);
$router->patch("$apiPrefix/audio/{id}", [AudioController::class, 'update']);

// ENTERPRISE GALAXY: Listen tracking with 80% threshold + 60-sec cooldown
$router->post("$apiPrefix/audio/{id}/track-listen", [AudioController::class, 'trackListen']);

// Audio social interactions
// DEPRECATED: Replaced with Emotional Reactions System (10 emotions)
// $router->post("$apiPrefix/audio/{id}/like", [AudioSocialController::class, 'like']);
// $router->delete("$apiPrefix/audio/{id}/like", [AudioSocialController::class, 'unlike']);
// $router->get("$apiPrefix/audio/{id}/likers", [AudioSocialController::class, 'likers']);

// Audio comments (Legacy - AudioSocialController)
// DEPRECATED: Replaced by Enterprise Comment System (CommentController) - see below
// $router->get("$apiPrefix/audio/{id}/comments", [AudioSocialController::class, 'comments']);
// $router->post("$apiPrefix/audio/{id}/comments", [AudioSocialController::class, 'addComment']);
// $router->put("$apiPrefix/audio/comments/{commentId}", [AudioSocialController::class, 'editComment']);
// $router->delete("$apiPrefix/audio/comments/{commentId}", [AudioSocialController::class, 'deleteComment']);
// $router->get("$apiPrefix/audio/comments/{commentId}/replies", [AudioSocialController::class, 'replies']);

// Audio reports
$router->post("$apiPrefix/audio/{id}/report", [AudioSocialController::class, 'report']);

// ENTERPRISE GALAXY: Hidden posts (OVERLAY CACHE ARCHITECTURE)
$router->post("$apiPrefix/audio/{id}/hide", [AudioSocialController::class, 'hidePost']);
$router->delete("$apiPrefix/audio/{id}/hide", [AudioSocialController::class, 'unhidePost']);

// Audio cache metrics (Service Worker tracking - ENTERPRISE GALAXY)
use Need2Talk\Controllers\Api\AudioCacheMetricsController;

// ===== COMMENTS API ===== (Enterprise Galaxy V4 - 2025-11-28)
// Enterprise comment system with:
// - 1-level replies (no deep nesting)
// - Like system with overlay cache (write-behind)
// - Optimistic UI updates
use Need2Talk\Controllers\Api\CommentController;

// Comment CRUD operations
$router->post("$apiPrefix/comments", [CommentController::class, 'create'], ['auth']);
$router->get("$apiPrefix/comments/post/{postId}", [CommentController::class, 'getPostComments'], ['auth']);
$router->get("$apiPrefix/comments/{commentId}/replies", [CommentController::class, 'getReplies'], ['auth']);
$router->put("$apiPrefix/comments/{commentId}", [CommentController::class, 'edit'], ['auth']);
$router->delete("$apiPrefix/comments/{commentId}", [CommentController::class, 'delete'], ['auth']);

// Comment likes (overlay cache for instant feedback)
$router->post("$apiPrefix/comments/{commentId}/like", [CommentController::class, 'like'], ['auth']);
$router->delete("$apiPrefix/comments/{commentId}/like", [CommentController::class, 'unlike'], ['auth']);

// ENTERPRISE V4 (2025-11-28): Comment edit history (transparency - shows previous versions)
$router->get("$apiPrefix/comments/{commentId}/history", [CommentController::class, 'getEditHistory'], ['auth']);

// Comment count for feed (quick endpoint)
$router->get("$apiPrefix/comments/post/{postId}/count", [CommentController::class, 'getCount'], ['auth']);

// POST: Public endpoint (Service Worker sends metrics from any browser, even guests)
$router->post("$apiPrefix/audio/cache-metrics", [AudioCacheMetricsController::class, 'receiveMetrics']);
// GET: Protected endpoints (dashboard requires authentication)
$router->get("$apiPrefix/audio/cache-metrics/daily", [AudioCacheMetricsController::class, 'getDailyMetrics'], ['auth']);
$router->get("$apiPrefix/audio/cache-metrics/summary", [AudioCacheMetricsController::class, 'getSummary'], ['auth']);

// ===== EMOTION API ===== (Enterprise Galaxy)
use Need2Talk\Controllers\Api\EmotionController;
use Need2Talk\Controllers\Api\EmotionsController;

// IMPORTANT: Specific routes MUST come before parameterized routes to avoid conflicts
// Emotions list (10 Plutchik emotions - NO AUTH, cacheable) - used by EmotionalJournal.js
$router->get("$apiPrefix/emotions/list", [EmotionsController::class, 'list']);

// Emotion data for EmotionSelector component
$router->get("$apiPrefix/emotions", [EmotionController::class, 'index']);
$router->get("$apiPrefix/emotions/category/{category}", [EmotionController::class, 'byCategory']);
$router->get("$apiPrefix/emotions/{id}", [EmotionController::class, 'show']); // MUST be last (catches everything)

// User evoked emotions (Profile Dashboard) - AudioReactionController already imported above
$router->get("$apiPrefix/user/evoked-emotions/{userId}", [AudioReactionController::class, 'getUserEvokedEmotions']);

// ===== EMOTIONAL HEALTH API ===== (Psychological Profile - Enterprise Galaxy)
use Need2Talk\Controllers\Api\EmotionalHealthController;

// Emotional health dashboard - "Mirror of the Soul" (REQUIRES AUTH)
$router->get("$apiPrefix/emotional-health/dashboard", [EmotionalHealthController::class, 'dashboard'], ['auth']);

// ===== EMOTIONAL JOURNAL API ===== (Daily Emotional Diary - Enterprise Galaxy)
use Need2Talk\Controllers\Api\EmotionalJournalController;

// Journal entry management (REQUIRES AUTH)
$router->post("$apiPrefix/journal/entry", [EmotionalJournalController::class, 'createOrUpdate'], ['auth']);
$router->get("$apiPrefix/journal/entry/{date}", [EmotionalJournalController::class, 'show'], ['auth']);
$router->delete("$apiPrefix/journal/entry/{date}", [EmotionalJournalController::class, 'delete'], ['auth']);

// Journal timeline & analytics (REQUIRES AUTH)
$router->get("$apiPrefix/journal/timeline", [EmotionalJournalController::class, 'timeline'], ['auth']);
$router->get("$apiPrefix/journal/stats", [EmotionalJournalController::class, 'stats'], ['auth']);
// REMOVED: /api/journal/calendar - old calendar tab eliminated (ENTERPRISE cleanup)

// Journal audio upload (ENTERPRISE GALAXY+ v4.2 - True E2E Diary)
$router->post("$apiPrefix/journal/upload-audio", [EmotionalJournalController::class, 'uploadAudio'], ['auth']);
// REMOVED: shareEntry - breaks E2E encryption (server cannot decrypt for sharing)
$router->get("$apiPrefix/journal/rate-limit-check", [EmotionalJournalController::class, 'rateLimitCheck'], ['auth']);

// Diary Password Management (v4.2 - True E2E - Zero Knowledge)
use Need2Talk\Controllers\Api\DiaryPasswordController;
$router->get("$apiPrefix/journal/password/status", [DiaryPasswordController::class, 'status'], ['auth']);
$router->post("$apiPrefix/journal/password/setup", [DiaryPasswordController::class, 'setup'], ['auth']);
$router->post("$apiPrefix/journal/password/verify", [DiaryPasswordController::class, 'verify'], ['auth']);
$router->post("$apiPrefix/journal/password/check-device", [DiaryPasswordController::class, 'checkDevice'], ['auth']);
$router->delete("$apiPrefix/journal/password/forget-device", [DiaryPasswordController::class, 'forgetDevice'], ['auth']);

// ENTERPRISE V12: Journal media streaming (audio and photos)
// Streams encrypted media blob for client-side decryption (zero-knowledge)
$router->get("$apiPrefix/journal/media/{uuid}/stream", [EmotionalJournalController::class, 'streamMedia'], ['auth']);
// Legacy alias for audio (backward compatibility)
$router->get("$apiPrefix/journal/audio/{uuid}/stream", [EmotionalJournalController::class, 'streamAudio'], ['auth']);

// ENTERPRISE GALAXY+ Phase 2.0: Calendar sidebar with emotion heatmap + trash system
// Calendar data for 30-day sidebar (aggregated emotions per day)
$router->get("$apiPrefix/journal/calendar-emotions", [EmotionalJournalController::class, 'calendarEmotions'], ['auth']);

// Trash management (soft delete with 30-day retention)
$router->get("$apiPrefix/journal/trash", [EmotionalJournalController::class, 'getTrash'], ['auth']);
$router->post("$apiPrefix/journal/{uuid}/restore", [EmotionalJournalController::class, 'restoreEntry'], ['auth']);
$router->delete("$apiPrefix/journal/{uuid}/soft-delete", [EmotionalJournalController::class, 'softDelete'], ['auth']);
$router->delete("$apiPrefix/journal/{uuid}/permanent-delete", [EmotionalJournalController::class, 'permanentDelete'], ['auth']);

// ===== USER API ===== (ENTERPRISE GALAXY+ - Phase 1)
use Need2Talk\Controllers\Api\UserController;

// Encryption key management (REQUIRES AUTH)
$router->get("$apiPrefix/user/encryption-key", [UserController::class, 'getEncryptionKey'], ['auth']);
$router->post("$apiPrefix/user/encryption-key", [UserController::class, 'saveEncryptionKey'], ['auth']);
$router->delete("$apiPrefix/user/encryption-key", [UserController::class, 'deleteEncryptionKey'], ['auth']);

// REMOVED (V11.0): Legacy friendship encryption key routes
// Chat DM now uses ECDH (ChatEncryptionService.js) - TRUE E2E
// Server NEVER sees shared keys or message content

// Privacy settings (REQUIRES AUTH)
$router->get("$apiPrefix/user/privacy-settings", [UserController::class, 'getPrivacySettings'], ['auth']);
$router->post("$apiPrefix/user/privacy-settings", [UserController::class, 'updatePrivacySettings'], ['auth']);

// Encryption statistics (ADMIN ONLY - TODO: add admin middleware)
$router->get("$apiPrefix/user/encryption-stats", [UserController::class, 'getEncryptionStats'], ['auth']);

// ===== PROFILE API =====
$router->get("$apiPrefix/profile/stats", [ProfileApiController::class, 'stats']);
$router->get("$apiPrefix/profile/activity", [ProfileApiController::class, 'activity']);
$router->get("$apiPrefix/profile/evoked-emotions", [ProfileApiController::class, 'evokedEmotions']);
$router->post("$apiPrefix/profile/settings", [ProfileApiController::class, 'updateSettings']);
$router->post("$apiPrefix/profile/avatar", [ProfileApiController::class, 'uploadAvatar']);

// ===== EMOTIONAL ANALYTICS API ===== (Reaction-based - ENTERPRISE GALAXY V11.6)
// Comprehensive analytics based on REACTIONS (expressed + received)
// Different from emotional-health which is based on post emotions (self-reported)
use Need2Talk\Controllers\Api\EmotionalAnalyticsController;
$router->get("$apiPrefix/profile/emotional-analytics", [EmotionalAnalyticsController::class, 'getAnalytics'], ['auth']);
$router->get("$apiPrefix/profile/emotional-analytics/{userId}", [EmotionalAnalyticsController::class, 'getAnalyticsForUser'], ['auth']);

// ===== USER API (v1 compatibility) =====
$router->get("$apiPrefix/v1/user/stats", [ProfileApiController::class, 'stats']);

// ===== USER SEARCH API =====
// REMOVED: Route duplicata - usa authenticated.php -> SocialController::searchUsers
// che include friendship status, rate limiting, avatar overlay
// $router->get("$apiPrefix/users/search", [UserSearchController::class, 'search']);

// ===== ANALYTICS API =====
$router->post("$apiPrefix/v1/analytics/activities", [AnalyticsController::class, 'activities']);

// ===== CSRF API =====
$router->post("$apiPrefix/csrf/refresh", [SessionController::class, 'refreshCsrfToken']);

// ===== VALIDATION API =====
$router->post("$apiPrefix/validate/nickname", [ValidationController::class, 'validateNickname']);
$router->post("$apiPrefix/validate/email", [ValidationController::class, 'validateEmail']);
$router->post("$apiPrefix/validate/content", [ValidationController::class, 'validateContent']);
$router->post("$apiPrefix/validate/audio-description", [ValidationController::class, 'validateAudioDescription']);

// ===== SESSION API =====
$router->get("$apiPrefix/session/check", [SessionController::class, 'check']); // ENTERPRISE: Heartbeat check
$router->get("$apiPrefix/session/status", [SessionController::class, 'status']);
$router->post("$apiPrefix/session/extend", [SessionController::class, 'extend']);
$router->post("$apiPrefix/session/logout-all", [SessionController::class, 'logoutAll']);
$router->get("$apiPrefix/session/active", [SessionController::class, 'activeSessions']);

// ===== NOTIFICATION API =====
$router->get("$apiPrefix/notifications", [NotificationController::class, 'index']);
$router->get("$apiPrefix/notifications/unread-count", [NotificationController::class, 'unreadCount']);
$router->post("$apiPrefix/notifications/{id}/read", [NotificationController::class, 'markAsRead']);
$router->post("$apiPrefix/notifications/read-all", [NotificationController::class, 'markAllAsRead']);
$router->delete("$apiPrefix/notifications/{id}", [NotificationController::class, 'delete']);

// ===== COOKIE CONSENT API =====
$router->get("$apiPrefix/cookie-consent/config", [CookieConsentController::class, 'getConfig']);
$router->post("$apiPrefix/cookie-consent/save", [CookieConsentController::class, 'saveConsent']);
$router->post("$apiPrefix/cookie-consent/withdraw", [CookieConsentController::class, 'withdrawConsent']);
$router->get("$apiPrefix/cookie-consent/check-service", [CookieConsentController::class, 'checkServiceConsent']);
$router->post("$apiPrefix/cookie-consent/banner-display", [CookieConsentController::class, 'logBannerDisplay']);
$router->post("$apiPrefix/cookie-consent/banner-response", [CookieConsentController::class, 'updateBannerResponse']); // ENTERPRISE FIX (2025-01-23): Fallback endpoint
$router->post("$apiPrefix/cookie-consent/track-emotion", [CookieConsentController::class, 'trackEmotionEvent']);
$router->get("$apiPrefix/cookie-consent/statistics", [CookieConsentController::class, 'getStatistics']);

// ===== REPORT API =====
// Site-wide reports for bugs, technical issues, suggestions (NOT user reports)
$router->post("$apiPrefix/report/submit", [ReportController::class, 'submit']);

// ===== STATS API =====
$router->get("$apiPrefix/stats/live", [StatsController::class, 'liveStats']);

// ===== ENTERPRISE SYSTEM METRICS API =====
// SECURITY FIX 2025-02-01: REMOVED from public API
// These endpoints exposed sensitive system metrics without proper admin URL protection
// Access metrics via admin panel only (/admin_xxx/api/metrics/export in admin_routes.php)

// ===== LOGGING API =====
// CORS: OPTIONS preflight per client logging
$router->options("$apiPrefix/logs/client", [\Need2Talk\Controllers\Api\LogController::class, 'receiveClientLogs']);
$router->post("$apiPrefix/logs/client", [\Need2Talk\Controllers\Api\LogController::class, 'receiveClientLogs']);
$router->get("$apiPrefix/logs/stats", [\Need2Talk\Controllers\Api\LogController::class, 'getClientLogStats']);

// ===== ENTERPRISE LOGGING API =====
$router->post("$apiPrefix/enterprise-logging", [\Need2Talk\Controllers\EnterpriseLoggingController::class, 'handleLogging']);
$router->get("$apiPrefix/enterprise-logging/stats", [\Need2Talk\Controllers\EnterpriseLoggingController::class, 'getErrorStats']);
$router->get("$apiPrefix/enterprise-logging/recent", [\Need2Talk\Controllers\EnterpriseLoggingController::class, 'getRecentErrors']);

// ===== WEBSOCKET BRIDGE API =====
// Endpoint per comunicazione PHP -> WebSocket Server
$router->post("$apiPrefix/bridge/notification", function () {
    // TODO: Implementare bridge per notifiche WebSocket
    echo json_encode(['success' => true, 'message' => 'WebSocket bridge not implemented yet']);
});

// ===== SECURITY TEST API =====
// SECURITY FIX 2025-02-01: REMOVED - Moved to admin_routes.php for proper URL-based admin protection
// These endpoints are now ONLY accessible via dynamic admin URL (/admin_xxx/api/security-test/*)

// NOTE: Admin API routes are in admin_routes.php, NOT here!

// ===== ENTERPRISE GALAXY CHAT API ===== (2025-12-02)
// Real-time chat system with emotion rooms, user rooms, DMs, and presence
use Need2Talk\Controllers\Chat\RoomController;
use Need2Talk\Controllers\Chat\DMController;
use Need2Talk\Controllers\Chat\PresenceController;
use Need2Talk\Controllers\Chat\ModerationController;

// --- EMOTION ROOMS (10 permanent rooms) ---
$router->get("$apiPrefix/chat/rooms/emotions", [RoomController::class, 'emotionRooms']); // Public

// --- USER ROOMS (user-created, 4h TTL) ---
$router->get("$apiPrefix/chat/rooms", [RoomController::class, 'index'], ['auth']);            // Public discovery
$router->get("$apiPrefix/chat/rooms/mine", [RoomController::class, 'mine'], ['auth']);        // User's own rooms (MUST be before {uuid})
$router->get("$apiPrefix/chat/rooms/invites", [RoomController::class, 'getPendingInvites'], ['auth']); // ENTERPRISE V5.5 (MUST be before {uuid})
$router->post("$apiPrefix/chat/rooms/invites/{room_uuid}/respond", [RoomController::class, 'respondToInvite'], ['auth']); // V5.5
$router->post("$apiPrefix/chat/rooms", [RoomController::class, 'create'], ['auth']);
$router->get("$apiPrefix/chat/rooms/{uuid}", [RoomController::class, 'show'], ['auth']);
$router->delete("$apiPrefix/chat/rooms/{uuid}", [RoomController::class, 'delete'], ['auth']);
$router->post("$apiPrefix/chat/rooms/{uuid}/join", [RoomController::class, 'join'], ['auth']);
$router->post("$apiPrefix/chat/rooms/{uuid}/leave", [RoomController::class, 'leave'], ['auth']);
$router->get("$apiPrefix/chat/rooms/{uuid}/messages", [RoomController::class, 'messages'], ['auth']);
$router->post("$apiPrefix/chat/rooms/{uuid}/messages", [RoomController::class, 'sendMessage'], ['auth']);
$router->get("$apiPrefix/chat/rooms/{uuid}/online", [RoomController::class, 'onlineUsers'], ['auth']);
$router->post("$apiPrefix/chat/rooms/{uuid}/invite", [RoomController::class, 'invite'], ['auth']); // ENTERPRISE V5.5

// --- DIRECT MESSAGES (1:1 E2E encrypted) ---
$router->get("$apiPrefix/chat/dm", [DMController::class, 'inbox'], ['auth']);
$router->post("$apiPrefix/chat/dm", [DMController::class, 'create'], ['auth']);
$router->get("$apiPrefix/chat/dm/{uuid}", [DMController::class, 'conversation'], ['auth']);
$router->get("$apiPrefix/chat/dm/{uuid}/messages", [DMController::class, 'messages'], ['auth']);
$router->post("$apiPrefix/chat/dm/{uuid}/messages", [DMController::class, 'send'], ['auth']);
$router->post("$apiPrefix/chat/dm/{uuid}/audio", [DMController::class, 'uploadAudio'], ['auth']); // ENTERPRISE V3.1: Audio messages
$router->post("$apiPrefix/chat/dm/{uuid}/read", [DMController::class, 'markRead'], ['auth']);
$router->post("$apiPrefix/chat/dm/{uuid}/typing", [DMController::class, 'typing'], ['auth']);
$router->get("$apiPrefix/chat/dm/{uuid}/key", [DMController::class, 'getKey'], ['auth']);

// --- PRESENCE (online status, heartbeat) ---
$router->post("$apiPrefix/chat/presence/heartbeat", [PresenceController::class, 'heartbeat'], ['auth']);
$router->post("$apiPrefix/chat/presence/status", [PresenceController::class, 'updateStatus'], ['auth']);
$router->post("$apiPrefix/chat/presence/offline", [PresenceController::class, 'setOffline'], ['auth']);
$router->get("$apiPrefix/chat/presence/batch", [PresenceController::class, 'batch'], ['auth']);
$router->get("$apiPrefix/chat/presence/{uuid}", [PresenceController::class, 'show'], ['auth']);

// --- MODERATION (reports, admin) ---
$router->post("$apiPrefix/chat/messages/{uuid}/report", [ModerationController::class, 'report'], ['auth']);
$router->get("$apiPrefix/chat/moderation/queue", [ModerationController::class, 'queue'], ['auth']);
$router->post("$apiPrefix/chat/moderation/review", [ModerationController::class, 'review'], ['auth']);
$router->get("$apiPrefix/chat/moderation/keywords", [ModerationController::class, 'keywords'], ['auth']);
$router->post("$apiPrefix/chat/moderation/keywords", [ModerationController::class, 'addKeyword'], ['auth']);
$router->delete("$apiPrefix/chat/moderation/keywords/{id}", [ModerationController::class, 'deleteKeyword'], ['auth']);
$router->post("$apiPrefix/chat/moderation/escrow/release", [ModerationController::class, 'releaseEscrow'], ['auth']);

// ===== HEALTH CHECK =====
$router->get("$apiPrefix/health", function () {
    echo json_encode([
        'status' => 'ok',
        'timestamp' => date('c'),
        'version' => '1.0.0',
        'service' => 'need2talk-api',
    ]);
});
