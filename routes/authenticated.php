<?php

/**
 * Authenticated Routes - need2talk
 *
 * Rotte dedicate per utenti autenticati (post-login)
 * Tutte le rotte in questo file richiedono DOPPIO middleware:
 * 1. 'AuthMiddleware' - Verifica autenticazione
 * 2. 'ProfileCompletionMiddleware' - Verifica profilo completato (GDPR FIX 2025-01-17)
 *
 * ENTERPRISE GALAXY GDPR COMPLIANCE:
 * - OAuth users (Google, etc.) con status='pending' vengono bloccati
 * - DEVONO completare profilo (/complete-profile) prima di accedere al sito
 * - MANDATORY: Birth date (18+), GDPR consent, Newsletter opt-in
 * - Previene attaccanti da registrazione OAuth + scan immediato /admin
 *
 * Organizzazione:
 * - Feed & Social
 * - Profile Management
 * - Friends & Social Interactions
 * - Audio Management
 * - Settings & Preferences
 * - Notifications
 */

use Need2Talk\Controllers\FeedController;
use Need2Talk\Controllers\ProfileController;
use Need2Talk\Controllers\SettingsController;
use Need2Talk\Controllers\SocialController;
use Need2Talk\Controllers\AdminController;

// use Need2Talk\Controllers\NotificationsController; // TODO: Create NotificationsController

// ==================== DEBUGBAR AJAX HANDLER ====================
// ENTERPRISE: Required for DebugBar to fetch AJAX request data on authenticated pages
// Without this, DebugBar shows "loading..." for AJAX calls on /profile, /feed, etc.
$router->get('/debugbar/open', [AdminController::class, 'debugbarOpen'], ['AuthMiddleware']);
$router->post('/debugbar/open', [AdminController::class, 'debugbarOpen'], ['AuthMiddleware']);

// ==================== FEED & SOCIAL ====================

// Personal Feed (homepage post-login - shows friends' posts)
$router->get('/feed', [FeedController::class, 'index'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->get('/home', [FeedController::class, 'index'], ['AuthMiddleware', 'ProfileCompletionMiddleware']); // Alias

// ==================== PROFILE ROUTES ====================

// Own Profile Shortcuts (redirect to /u/{own_uuid})
$router->get('/profile', [ProfileController::class, 'showOwnProfile'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->get('/me', [ProfileController::class, 'showOwnProfile'], ['AuthMiddleware', 'ProfileCompletionMiddleware']); // Modern alias

// Public User Profiles (UUID-based, privacy-controlled)
$router->get('/u/{uuid}', [ProfileController::class, 'showUserProfile'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Profile Settings
$router->get('/profile/settings', [ProfileController::class, 'settings'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/profile/settings', [ProfileController::class, 'updateSettings'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Profile Photo/Avatar Upload
$router->post('/profile/upload-avatar', [ProfileController::class, 'uploadAvatar'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/profile/upload-cover', [ProfileController::class, 'uploadCover'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// ==================== FRIENDS & SOCIAL ====================

// Friends Page (HTML View)
$router->get('/friends', [SocialController::class, 'showFriendsPage'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Friends API Endpoints (JSON)
$router->get('/api/friends', [SocialController::class, 'getFriends'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->get('/api/friends/count', [SocialController::class, 'getFriendsCount'], ['AuthMiddleware', 'ProfileCompletionMiddleware']); // ENTERPRISE V11.8: Badge count
$router->get('/api/friends/widget', [SocialController::class, 'getFriendsWidget'], ['AuthMiddleware', 'ProfileCompletionMiddleware']); // ENTERPRISE GALAXY: Random friends widget (feed sidebar)
$router->get('/api/friends/search', [SocialController::class, 'searchFriends'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// User Search (Global - by email, nickname, user_id)
$router->get('/api/users/search', [SocialController::class, 'searchUsers'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Friend Requests Management
$router->get('/social/friend-requests', [SocialController::class, 'getPendingRequests'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->get('/social/friend-requests/sent', [SocialController::class, 'getSentRequests'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->get('/social/friend-requests/count', [SocialController::class, 'getFriendRequestCount'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->get('/social/friend-requests/sent/count', [SocialController::class, 'getSentRequestCount'], ['AuthMiddleware', 'ProfileCompletionMiddleware']); // ENTERPRISE V11.8: Badge count
$router->post('/social/friend-request/send', [SocialController::class, 'sendFriendRequest'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/social/friend-request/accept', [SocialController::class, 'acceptFriendRequest'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/social/friend-request/reject', [SocialController::class, 'rejectFriendRequest'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/social/friend-request/cancel', [SocialController::class, 'cancelFriendRequest'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Friendship Actions
$router->post('/social/unfriend', [SocialController::class, 'unfriend'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/social/block', [SocialController::class, 'blockUser'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/social/unblock', [SocialController::class, 'unblockUser'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Blocked Users API (ENTERPRISE V4)
$router->get('/api/blocked-users', [SocialController::class, 'getBlockedUsers'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Friendship Status Checks
$router->get('/social/friendship-status/{user_id}', [SocialController::class, 'getFriendshipStatus'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->get('/social/are-friends/{user_id}', [SocialController::class, 'areFriends'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Social Feed (community-wide posts) - TODO: Implement in FeedController
// $router->get('/social', [FeedController::class, 'social'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
// $router->get('/social/feed', [FeedController::class, 'socialFeed'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// ==================== AUDIO MANAGEMENT ====================

// Audio Recording
// $router->get('/record', [AudioController::class, 'record'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// My Audio Posts
// $router->get('/my-audio', [AudioController::class, 'myAudio'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Saved Audio (liked/bookmarked)
// $router->get('/saved-audio', [AudioController::class, 'saved'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Audio Streaming (public but tracked for analytics)
// $router->get('/stream/{id}', [AudioController::class, 'stream']); // No auth required for streaming

// ==================== NOTIFICATIONS ====================

// Notifications Page
// $router->get('/notifications', [NotificationsController::class, 'index'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Mark Notifications as Read
// $router->post('/notifications/mark-read', [NotificationsController::class, 'markRead'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
// $router->post('/notifications/mark-all-read', [NotificationsController::class, 'markAllRead'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// ==================== SETTINGS & PREFERENCES ====================

// Settings Index (SettingsController - Enterprise Galaxy)
$router->get('/settings', [SettingsController::class, 'index'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Account Settings (nickname, email, avatar, delete account)
$router->get('/settings/account', [SettingsController::class, 'account'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->get('/api/settings/check-nickname', [SettingsController::class, 'checkNicknameAvailability'], ['AuthMiddleware', 'ProfileCompletionMiddleware']); // Real-time validation
$router->post('/settings/account/nickname', [SettingsController::class, 'updateNickname'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/account/email/request', [SettingsController::class, 'requestEmailChange'], ['AuthMiddleware', 'ProfileCompletionMiddleware']); // Email change request
$router->get('/settings/account/email/confirm/{token}', [SettingsController::class, 'confirmEmailChange'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/account/avatar', [SettingsController::class, 'uploadAvatar'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Privacy Settings (profile visibility, tabs visibility)
$router->get('/settings/privacy', [SettingsController::class, 'privacy'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/privacy', [SettingsController::class, 'updatePrivacy'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/privacy/tabs', [SettingsController::class, 'updateTabsVisibility'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Notification Preferences (email, push)
$router->get('/settings/notifications', [SettingsController::class, 'notifications'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/notifications', [SettingsController::class, 'updateNotifications'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Security Settings (password change, 2FA)
$router->get('/settings/security', [SettingsController::class, 'security'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/security/password', [SettingsController::class, 'changePassword'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// GDPR: Data Export & Account Deletion (Article 17 & 20)
$router->get('/settings/data-export', [SettingsController::class, 'dataExport'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/data-export/export', [SettingsController::class, 'exportData'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/data-export/delete-account', [SettingsController::class, 'scheduleAccountDeletion'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/settings/data-export/cancel-deletion', [SettingsController::class, 'cancelAccountDeletion'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// ==================== LOGOUT ====================

// Logout (GET and POST for flexibility)
$router->get('/auth/logout', [\Need2Talk\Controllers\AuthController::class, 'logout'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/auth/logout', [\Need2Talk\Controllers\AuthController::class, 'logout'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Alternative logout routes (backwards compatibility)
$router->get('/logout', [\Need2Talk\Controllers\AuthController::class, 'logout'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/logout', [\Need2Talk\Controllers\AuthController::class, 'logout'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// ==================== CHAT SYSTEM (ENTERPRISE GALAXY 2025-12-02) ====================

use Need2Talk\Controllers\ChatController;

// Main Chat Page (shows emotion rooms, user rooms, DM inbox)
$router->get('/chat', [ChatController::class, 'index'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Emotion Rooms (predefined ephemeral rooms)
$router->get('/chat/emotion/{emotionId}', [ChatController::class, 'emotionRoom'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// User-Created Rooms
$router->get('/chat/room/{uuid}', [ChatController::class, 'room'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Direct Messages (1:1 with E2E encryption)
$router->get('/chat/dm/{uuid}', [ChatController::class, 'dm'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// Admin Moderation Dashboard
$router->get('/chat/moderation', [ChatController::class, 'moderation'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// ==================== EMOFRIENDLY - ANIME AFFINI (ENTERPRISE GALAXY 2025-12-16) ====================

use Need2Talk\Controllers\EmoFriendlyController;
use Need2Talk\Controllers\Api\EmoFriendlyApiController;

// Main EmoFriendly Page (suggests friends based on emotional compatibility)
$router->get('/emofriendly', [EmoFriendlyController::class, 'index'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);

// EmoFriendly API Endpoints
$router->get('/api/emofriendly/suggestions', [EmoFriendlyApiController::class, 'getSuggestions'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/api/emofriendly/dismiss', [EmoFriendlyApiController::class, 'dismiss'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
$router->post('/api/emofriendly/friend-request', [EmoFriendlyApiController::class, 'sendFriendRequest'], ['AuthMiddleware', 'ProfileCompletionMiddleware']);
