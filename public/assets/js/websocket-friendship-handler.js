/**
 * WebSocket Friendship Event Handler - Enterprise Galaxy
 *
 * Gestisce eventi real-time per il sistema amicizie:
 * - friend_request_received: Notifica ricevimento richiesta amicizia
 * - friend_request_accepted: Notifica accettazione richiesta (sender)
 * - friend_request_rejected: Notifica rifiuto richiesta (sender)
 * - friend_request_cancelled: Notifica annullamento richiesta (receiver)
 * - friend_request_accepted_self: Sync multi-device (acceptor)
 * - friend_request_rejected_self: Sync multi-device (rejecter)
 *
 * ARCHITECTURE:
 * - Signal-based approach: Client riceve segnale → fetch updated data
 * - NO full data in WebSocket payload (privacy + performance)
 * - Invalidates local caches on events
 * - Shows toast notifications for user feedback
 *
 * USAGE:
 * - Auto-initialized when WebSocket connects
 * - Registers event handlers on window.WebSocketManager
 * - Integrates with existing friendship UI components
 */

(function() {
    'use strict';

    /**
     * Initialize friendship event handlers
     */
    function initFriendshipHandlers() {
        if (!window.WebSocketManager) {
            console.warn('WebSocketManager non disponibile - friendship handlers non registrati');
            return;
        }

        // ===== FRIEND REQUEST RECEIVED =====
        // User B riceve notifica che User A ha inviato richiesta
        window.WebSocketManager.onMessage('friend_request_received', (data) => {

            // Show toast notification
            showNotification('Nuova richiesta di amicizia', 'info');

            // Invalidate pending requests cache
            invalidateFriendshipCache('pending_requests');

            // Update UI - increment pending requests badge
            updatePendingRequestsBadge();

            // Trigger custom event for other UI components
            document.dispatchEvent(new CustomEvent('friendship:request_received', {
                detail: data
            }));
        });

        // ===== FRIEND REQUEST ACCEPTED =====
        // ENTERPRISE V6.9 (2025-11-30): Unified notification handling
        // User A (sender) receives 'notification' with type='friend_accepted' from NotificationService
        // This replaces the old 'friend_request_accepted' duplicate event
        window.WebSocketManager.onMessage('notification', (wsMessage) => {
            // ENTERPRISE V10.86: Extract notification from payload wrapper
            const data = wsMessage.payload || wsMessage;

            // Only handle friend_accepted notifications here
            if (data.type !== 'friend_accepted') return;

            // Toast NOT shown here - navbar-auth.php shows the toast via showNotificationToast()

            // Invalidate caches
            invalidateFriendshipCache('sent_requests');
            invalidateFriendshipCache('friends_list');

            // ENTERPRISE V6.9: Extract new_friend from notification data
            const newFriend = data.data?.new_friend || data.actor;
            if (newFriend) {
                addFriendToGlobalCache(newFriend);
            }

            // Update UI
            updateSentRequestsUI();
            updateFriendsCount();

            // Trigger custom event
            document.dispatchEvent(new CustomEvent('friendship:request_accepted', {
                detail: { ...data, new_friend: newFriend }
            }));
        });

        // ===== FRIEND REQUEST REJECTED =====
        // User A (sender) riceve notifica che User B ha rifiutato
        window.WebSocketManager.onMessage('friend_request_rejected', (data) => {
            // Show toast notification (subtle, no loud failure message)
            showNotification('Richiesta di amicizia rifiutata', 'info');

            // Invalidate caches
            invalidateFriendshipCache('sent_requests');

            // Update UI
            updateSentRequestsUI();

            // Trigger custom event
            document.dispatchEvent(new CustomEvent('friendship:request_rejected', {
                detail: data
            }));
        });

        // ===== FRIEND REQUEST CANCELLED =====
        // User B (receiver) riceve notifica che User A ha annullato la richiesta
        window.WebSocketManager.onMessage('friend_request_cancelled', (data) => {
            // Show toast notification (subtle)
            showNotification('Una richiesta di amicizia è stata annullata', 'info');

            // Invalidate caches
            invalidateFriendshipCache('pending_requests');

            // Update UI - remove cancelled request from pending list
            updatePendingRequestsUI();
            updatePendingRequestsBadge();

            // Trigger custom event
            document.dispatchEvent(new CustomEvent('friendship:request_cancelled', {
                detail: data
            }));
        });

        // ===== MULTI-DEVICE SYNC EVENTS =====
        // Accepted self: User B accepted request on another device → sync this device
        window.WebSocketManager.onMessage('friend_request_accepted_self', (data) => {
            // Invalidate caches
            invalidateFriendshipCache('pending_requests');
            invalidateFriendshipCache('friends_list');

            // ENTERPRISE V6.7 (2025-11-30): Add new friend to global cache
            if (data.new_friend) {
                addFriendToGlobalCache(data.new_friend);
            }

            // Update UI (silent, no toast notification)
            updatePendingRequestsUI();
            updateFriendsCount();

            // Trigger custom event
            document.dispatchEvent(new CustomEvent('friendship:sync_accepted_self', {
                detail: data
            }));
        });

        // Rejected self: User B rejected request on another device → sync this device
        window.WebSocketManager.onMessage('friend_request_rejected_self', (data) => {
            // Invalidate caches
            invalidateFriendshipCache('pending_requests');

            // Update UI (silent)
            updatePendingRequestsUI();

            // Trigger custom event
            document.dispatchEvent(new CustomEvent('friendship:sync_rejected_self', {
                detail: data
            }));
        });

        // ===== FRIENDSHIP ENDED (UNFRIEND) =====
        // ENTERPRISE V12.3: User receives notification that they were unfriended
        window.WebSocketManager.onMessage('friendship_ended', (data) => {
            // Invalidate all friendship caches
            invalidateFriendshipCache('friends_list');
            invalidateFriendshipCache('friends_online');

            // Remove from global cache
            if (data.ended_by_uuid) {
                removeFriendFromGlobalCache(data.ended_by_uuid);
            }

            // Update UI
            updateFriendsCount();
            refreshFriendsOnlineWidget();

            // Trigger custom event for other components
            document.dispatchEvent(new CustomEvent('friendship:ended', {
                detail: data
            }));
        });

        // ===== FRIENDSHIP ENDED SELF (MULTI-DEVICE SYNC) =====
        // ENTERPRISE V12.3: User unfriended someone on another device → sync this device
        window.WebSocketManager.onMessage('friendship_ended_self', (data) => {
            // Invalidate all friendship caches
            invalidateFriendshipCache('friends_list');
            invalidateFriendshipCache('friends_online');

            // Remove from global cache
            if (data.friend_uuid) {
                removeFriendFromGlobalCache(data.friend_uuid);
            }

            // Update UI (silent, no toast)
            updateFriendsCount();
            refreshFriendsOnlineWidget();

            // Trigger custom event
            document.dispatchEvent(new CustomEvent('friendship:ended_self', {
                detail: data
            }));
        });

    }

    /**
     * Show toast notification (uses existing notification system if available)
     */
    function showNotification(message, type = 'info') {
        // ENTERPRISE: Integrate with existing notification system
        if (window.NotificationManager && window.NotificationManager.showToast) {
            window.NotificationManager.showToast(message, type);
            return;
        }

        // Fallback: Simple browser notification or console log
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('need2talk', {
                body: message,
                icon: '/assets/images/logo.png'
            });
        }
    }

    /**
     * Invalidate friendship-related caches
     */
    function invalidateFriendshipCache(cacheKey) {
        // ENTERPRISE: Clear localStorage caches if used
        if (window.localStorage) {
            const keysToRemove = [
                `friendship_${cacheKey}`,
                `friendship_data_${cacheKey}`,
                `friends_count`,
                `pending_count`
            ];

            keysToRemove.forEach(key => {
                window.localStorage.removeItem(key);
            });
        }

    }

    /**
     * Update pending requests badge (count)
     */
    function updatePendingRequestsBadge() {
        // ENTERPRISE: Update UI badge showing pending requests count
        // This is a placeholder - integrate with actual UI components
        const badge = document.querySelector('[data-pending-requests-badge]');
        if (badge) {
            // Fetch updated count from API
            fetch('/api/friends/pending-requests/count')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.count !== undefined) {
                        badge.textContent = data.count;
                        badge.style.display = data.count > 0 ? 'inline' : 'none';
                    }
                })
                .catch(err => console.error('Error updating pending badge:', err));
        }
    }

    /**
     * Update sent requests UI
     */
    function updateSentRequestsUI() {
        // ENTERPRISE: Reload sent requests list if visible
        const sentRequestsContainer = document.querySelector('[data-sent-requests-container]');
        if (sentRequestsContainer && window.FriendshipManager) {
            window.FriendshipManager.refreshSentRequests();
        }
    }

    /**
     * Update pending requests UI
     */
    function updatePendingRequestsUI() {
        // ENTERPRISE: Reload pending requests list if visible
        const pendingContainer = document.querySelector('[data-pending-requests-container]');
        if (pendingContainer && window.FriendshipManager) {
            window.FriendshipManager.refreshPendingRequests();
        }
    }

    /**
     * Update friends count display
     */
    function updateFriendsCount() {
        // ENTERPRISE: Update friends count in UI
        const countElement = document.querySelector('[data-friends-count]');
        if (countElement) {
            fetch('/api/friends/count')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.count !== undefined) {
                        countElement.textContent = data.count;
                    }
                })
                .catch(err => console.error('Error updating friends count:', err));
        }
    }

    /**
     * ENTERPRISE V12.3: Refresh friends online widget in chat
     * Triggers a refresh of the online friends list
     */
    function refreshFriendsOnlineWidget() {
        // Dispatch event for FriendsWidget to refresh
        document.dispatchEvent(new CustomEvent('friends:refresh_online'));

        // Also try direct refresh if widget instance exists
        if (window.FriendsWidget && typeof window.FriendsWidget.refresh === 'function') {
            window.FriendsWidget.refresh();
        }
    }

    /**
     * ENTERPRISE V12.3: Remove friend from global cache
     * Called when unfriending to immediately update all components
     *
     * @param {string} friendUuid - Friend UUID to remove
     */
    function removeFriendFromGlobalCache(friendUuid) {
        if (!friendUuid) return;

        // Remove from global friends list
        if (window.globalFriendsList && Array.isArray(window.globalFriendsList)) {
            window.globalFriendsList = window.globalFriendsList.filter(f => f.uuid !== friendUuid);
        }

        // Dispatch event for components to update their local caches
        document.dispatchEvent(new CustomEvent('friends:cache_updated', {
            detail: {
                action: 'remove',
                friend_uuid: friendUuid
            }
        }));
    }

    /**
     * ENTERPRISE V6.7 (2025-11-30): Add new friend to global cache
     * This updates ALL components that cache friends list (recorder, comments, etc.)
     *
     * @param {Object} friend - Friend data { uuid, nickname, avatar_url }
     */
    function addFriendToGlobalCache(friend) {
        if (!friend || !friend.uuid) {
            console.warn('[FriendshipHandler] Invalid friend data:', friend);
            return;
        }


        // Initialize global friends cache if not exists
        if (!window.globalFriendsList) {
            window.globalFriendsList = [];
        }

        // Check if already in list (prevent duplicates)
        const exists = window.globalFriendsList.some(f => f.uuid === friend.uuid);
        if (!exists) {
            window.globalFriendsList.push({
                uuid: friend.uuid,
                nickname: friend.nickname,
                avatar_url: friend.avatar_url
            });
        }

        // Dispatch event for components to update their local caches
        // FloatingRecorder will listen to this and add to its friendsList
        document.dispatchEvent(new CustomEvent('friends:cache_updated', {
            detail: {
                action: 'add',
                friend: friend
            }
        }));
    }

    // ===== AUTO-INITIALIZATION =====
    // Wait for WebSocketManager to be ready, then register handlers
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFriendshipHandlers);
    } else {
        // DOM already loaded
        initFriendshipHandlers();
    }

    // Also listen for WebSocket connection event (in case WebSocket connects later)
    window.addEventListener('load', () => {
        if (window.WebSocketManager) {
            window.WebSocketManager.onConnection((event) => {
            });
        }
    });

})();
