/**
 * WebSocket Real-Time Handlers (ENTERPRISE GALAXY)
 *
 * Handles real-time WebSocket events for:
 * - Avatar updates (user's own + followers)
 * - New audio posts (from followed users)
 * - Friend requests (accept/reject/cancel)
 *
 * ARCHITECTURE:
 * - Hybrid System: Session flag (30s force_refresh) + WebSocket (real-time)
 * - Performance: <5ms per event processing
 * - Scalability: ~50 messages for 10% followers online with feed open
 *
 * Events:
 * - avatar_updated → Update user's own avatar (all tabs/devices)
 * - user_avatar_updated → Update follower's avatar in feed
 * - new_audiopost → Add new post to feed (real-time)
 * - friend_request_* → Handled by websocket-friendship-handler.js
 *
 * @package need2talk/Lightning
 * @version 1.0.0 - ENTERPRISE GALAXY
 */

(function() {
    'use strict';

    // Wait for WebSocketManager to be available
    if (!window.WebSocketManager) {
        setTimeout(() => {
            if (window.WebSocketManager) {
                initWebSocketHandlers();
            }
        }, 1000);
        return;
    }

    initWebSocketHandlers();

    /**
     * Initialize all WebSocket event handlers (ENTERPRISE GALAXY)
     */
    function initWebSocketHandlers() {
        // Avatar update handlers
        registerAvatarHandlers();

        // Audio post handlers
        registerAudioPostHandlers();

        // ENTERPRISE GALAXY: Cache invalidation handlers
        registerCacheInvalidationHandlers();

        // ENTERPRISE GALAXY V7 (2025-11-30): Real-time feed updates
        registerFeedRealtimeHandlers();

        // NOTE: Room invites now use standard NotificationService (shows in bell)
        // Click on notification navigates to /chat?room=xxx (handled in websocket-manager.js)
    }

    /**
     * Register avatar update handlers (ENTERPRISE GALAXY)
     *
     * Handles two events:
     * 1. avatar_updated → User's own avatar (all tabs/devices)
     * 2. user_avatar_updated → Follower's avatar (if feed open)
     */
    function registerAvatarHandlers() {
        // Event 1: User's own avatar updated (all devices)
        window.WebSocketManager.onMessage('avatar_updated', (data) => {
            const newAvatarUrl = data.avatar_url;
            const thumbnailSmall = data.thumbnail_small;

            if (!newAvatarUrl) {
                console.warn('[WS] avatar_updated: missing avatar_url');
                return;
            }

            // Update all avatar instances in DOM
            updateAllAvatars(newAvatarUrl, thumbnailSmall);
        });

        // Event 2: Follower's avatar updated (if user has feed open)
        window.WebSocketManager.onMessage('user_avatar_updated', (data) => {
            const userUuid = data.user_uuid;
            const newAvatarUrl = data.avatar_url;

            if (!userUuid || !newAvatarUrl) {
                console.warn('[WS] user_avatar_updated: missing userUuid or avatar_url');
                return;
            }

            // Update specific user's avatars in feed
            updateUserAvatarsInFeed(userUuid, newAvatarUrl);

            // ENTERPRISE V12.1: Update avatars in chat rooms and DMs
            updateUserAvatarsInChat(userUuid, newAvatarUrl);
        });
    }

    /**
     * Update all avatar instances for current user (ENTERPRISE GALAXY)
     *
     * Updates:
     * - Navbar avatar
     * - Sidebar profile avatar
     * - Feed post avatars (if viewing own posts)
     * - Settings page avatar preview
     *
     * @param {string} newAvatarUrl New avatar URL
     * @param {string} thumbnailSmall Thumbnail URL (optional)
     */
    function updateAllAvatars(newAvatarUrl, thumbnailSmall) {
        // Use thumbnail if available (smaller, faster load)
        const avatarUrl = thumbnailSmall || newAvatarUrl;

        // 1. Update navbar avatar (top-right user menu)
        const navbarAvatar = document.querySelector('nav img[alt*="avatar"], nav img.rounded-full');
        if (navbarAvatar) {
            navbarAvatar.src = avatarUrl;
        }

        // 2. Update sidebar profile avatar (left sidebar on feed page)
        const sidebarAvatar = document.querySelector('aside img.rounded-full, aside img[alt*="avatar"]');
        if (sidebarAvatar) {
            sidebarAvatar.src = avatarUrl;
        }

        // 3. Update settings page avatar preview
        const settingsAvatar = document.getElementById('currentAvatarPreview');
        if (settingsAvatar) {
            settingsAvatar.src = avatarUrl;
        }

        // 4. Update own posts in feed (if visible)
        if (window.currentUser && window.currentUser.uuid) {
            updateUserAvatarsInFeed(window.currentUser.uuid, avatarUrl);
        }
    }

    /**
     * Update specific user's avatars in feed posts (ENTERPRISE GALAXY)
     *
     * @param {string} userUuid User UUID
     * @param {string} newAvatarUrl New avatar URL
     */
    function updateUserAvatarsInFeed(userUuid, newAvatarUrl) {
        // Find all feed posts from this user (by data-author-uuid or similar)
        const feedPosts = document.querySelectorAll(`[data-author-uuid="${userUuid}"], [data-user-uuid="${userUuid}"]`);

        if (feedPosts.length === 0) {
            return;
        }

        feedPosts.forEach(post => {
            const avatar = post.querySelector('img.rounded-full, img[alt*="avatar"]');
            if (avatar) {
                avatar.src = newAvatarUrl;
            }
        });
    }

    /**
     * ENTERPRISE V12.1: Update user's avatars in chat rooms and DMs
     *
     * Updates avatars in:
     * - Chat room message list
     * - Chat room user list / presence
     * - DM chat widgets
     * - DM chat headers
     *
     * @param {string} userUuid User UUID
     * @param {string} newAvatarUrl New avatar URL
     */
    function updateUserAvatarsInChat(userUuid, newAvatarUrl) {
        // 1. Update avatars in chat messages (by sender UUID)
        const chatMessages = document.querySelectorAll(`[data-sender-uuid="${userUuid}"]`);
        chatMessages.forEach(msg => {
            const avatar = msg.querySelector('.n2t-message-avatar, img.rounded-full');
            if (avatar && avatar.tagName === 'IMG') {
                avatar.src = newAvatarUrl;
            }
        });

        // 2. Update avatars in user list / presence panel
        const userListItems = document.querySelectorAll(`[data-uuid="${userUuid}"], [data-user-uuid="${userUuid}"]`);
        userListItems.forEach(item => {
            const avatar = item.querySelector('img.rounded-full, img[alt*="avatar"]');
            if (avatar) {
                avatar.src = newAvatarUrl;
            }
        });

        // 3. Update DM chat widget headers (if user has DM open with this person)
        const dmWidgets = document.querySelectorAll('.chat-widget');
        dmWidgets.forEach(widget => {
            const otherUserUuid = widget.dataset?.otherUserUuid;
            if (otherUserUuid === userUuid) {
                const headerAvatar = widget.querySelector('.chat-widget-header img, .chat-widget__header img');
                if (headerAvatar) {
                    headerAvatar.src = newAvatarUrl;
                }
            }
        });

        // 4. Update ChatWidgetManager's internal data (if available)
        if (window.ChatWidgetManager && typeof window.ChatWidgetManager.updateUserAvatar === 'function') {
            window.ChatWidgetManager.updateUserAvatar(userUuid, newAvatarUrl);
        }

        // 5. Update ChatManager's user presence cache (if available)
        // Use updateUser() to properly update cache and trigger UI refresh
        if (window.n2tChatManager && window.n2tChatManager.userPresence) {
            window.n2tChatManager.userPresence.updateUser(userUuid, {
                avatar: newAvatarUrl
            });
        }
    }

    /**
     * Register audio post handlers (ENTERPRISE GALAXY)
     *
     * Handles:
     * - new_audiopost → Add new post to feed (real-time)
     */
    function registerAudioPostHandlers() {
        window.WebSocketManager.onMessage('new_audiopost', (data) => {
            // Only add to feed if on feed page
            if (!isFeedPage()) {
                return;
            }

            // ENTERPRISE FIX (2025-12-20): Don't notify author of their own post
            const currentUserUuid = window.Need2Talk?.userUuid;
            if (currentUserUuid && data.author && data.author.uuid === currentUserUuid) {
                console.log('[WS] Skipping self-notification for own audiopost');
                return;
            }

            // Show notification banner
            showNewPostNotification(data);
        });
    }

    /**
     * Check if user is on feed page
     */
    function isFeedPage() {
        return window.location.pathname === '/feed' || window.location.pathname === '/home';
    }

    /**
     * Show "New post available" notification banner (ENTERPRISE UX)
     *
     * Instead of auto-inserting (jarring), show banner:
     * "Nuovo post da @nickname - Clicca per aggiornare feed"
     *
     * @param {object} postData New post data from WebSocket
     */
    function showNewPostNotification(postData) {
        // Check if banner already exists
        let banner = document.getElementById('newPostBanner');

        if (!banner) {
            // Create banner
            banner = document.createElement('div');
            banner.id = 'newPostBanner';
            banner.className = 'fixed top-20 left-1/2 transform -translate-x-1/2 z-50 bg-purple-600 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-3 cursor-pointer hover:bg-purple-700 transition-all';
            banner.innerHTML = `
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
                <span id="newPostBannerText">Nuovo post disponibile</span>
                <button class="ml-4 px-3 py-1 bg-white/20 rounded hover:bg-white/30 transition-colors">
                    Aggiorna
                </button>
            `;

            // Click to reload feed
            banner.addEventListener('click', () => {
                if (window.feedManager && typeof window.feedManager.refreshFeed === 'function') {
                    window.feedManager.refreshFeed();
                } else {
                    // Fallback: reload page
                    window.location.reload();
                }
            });

            document.body.appendChild(banner);
        }

        // Update banner text with author
        const textElement = document.getElementById('newPostBannerText');
        if (textElement && postData.author && postData.author.nickname) {
            textElement.textContent = `Nuovo post da @${postData.author.nickname}`;
        }

        // Auto-hide after 10 seconds
        setTimeout(() => {
            if (banner && banner.parentNode) {
                banner.style.transition = 'opacity 0.5s';
                banner.style.opacity = '0';
                setTimeout(() => banner.remove(), 500);
            }
        }, 10000);
    }

    /**
     * ========================================================================
     * CACHE INVALIDATION HANDLERS (ENTERPRISE GALAXY)
     * ========================================================================
     *
     * Handles browser-side cache invalidation when audio is deleted/updated
     * Works with Service Worker (sw-audio-cache.js) to clear IndexedDB cache
     */
    function registerCacheInvalidationHandlers() {
        // Event: cache:invalidate → Invalidate browser cache for audio
        window.WebSocketManager.onMessage('cache:invalidate', async (data) => {
            if (data.type !== 'audio') {
                return;
            }

            // Check if Service Worker Manager is available
            if (!window.swManager) {
                return;
            }

            try {
                let result;

                switch (data.action) {
                    case 'delete':
                        // Audio was deleted - remove from browser cache
                        result = await window.swManager.invalidateAudio(
                            data.cdn_url,
                            data.audio_uuid
                        );
                        break;

                    case 'privacy_change':
                        // Privacy changed - invalidate for consistency
                        result = await window.swManager.invalidateAudio(
                            data.cdn_url,
                            data.audio_uuid
                        );
                        break;

                    case 'user_bulk':
                        // User bulk invalidation (privacy settings change, account deletion)
                        result = await window.swManager.invalidateUserAudios(data.user_uuid);
                        break;
                }

                // Optionally refresh feed if viewing deleted content
                if (data.action === 'delete' && isFeedPage()) {
                    removeDeletedPostFromDOM(data.post_id);
                }

            } catch (error) {
                console.error('[WS] Cache invalidation error:', error.message);
            }
        });
    }

    /**
     * Remove deleted post from DOM (immediate visual feedback)
     *
     * @param {number} postId Post ID to remove
     */
    function removeDeletedPostFromDOM(postId) {
        if (!postId) return;

        const postElement = document.querySelector(`[data-post-id="${postId}"]`);

        if (postElement) {
            // Fade out animation
            postElement.style.transition = 'opacity 0.3s, transform 0.3s';
            postElement.style.opacity = '0';
            postElement.style.transform = 'scale(0.95)';

            setTimeout(() => {
                postElement.remove();
            }, 300);
        }
    }

    /**
     * ========================================================================
     * REAL-TIME FEED UPDATES (ENTERPRISE GALAXY V7 - 2025-11-30)
     * ========================================================================
     *
     * Handles real-time updates for reactions and comments in the feed.
     *
     * ARCHITECTURE:
     * - Backend: NotificationService sends 'notification' WebSocket event
     * - Event payload includes: type (new_reaction/new_comment), target_id (post_id)
     * - Frontend: Fetch fresh stats from API, update ReactionPicker/CommentManager
     *
     * PERFORMANCE CONSIDERATIONS:
     * - Debounce API calls (multiple rapid reactions → single fetch)
     * - Only fetch if post is visible in DOM
     * - Use existing component update methods (no full re-render)
     *
     * NOTIFICATION TYPES HANDLED:
     * - new_reaction: Someone reacted to a post you authored
     * - new_comment: Someone commented on a post you authored
     * - comment_liked: Someone liked your comment
     * - comment_reply: Someone replied to your comment
     */
    function registerFeedRealtimeHandlers() {
        // Debounce map: postId → timeoutId (prevents rapid-fire API calls)
        const reactionDebounceMap = new Map();
        const commentDebounceMap = new Map();
        const DEBOUNCE_DELAY = 500; // 500ms debounce for multiple rapid events

        /**
         * Handle 'notification' WebSocket events for feed updates
         * ENTERPRISE: Only process notification types that affect feed display
         */
        window.WebSocketManager.onMessage('notification', async (wsMessage) => {
            // ENTERPRISE V10.86: Extract notification from payload wrapper
            // handleNotification() wraps in { payload: notification, timestamp }
            const notification = wsMessage.payload || wsMessage;

            // Extract notification type from payload
            const notifType = notification.type;
            const targetId = notification.target_id;
            const targetType = notification.target_type;

            // Only process relevant notification types
            const feedUpdateTypes = ['new_reaction', 'new_comment', 'comment_liked', 'comment_reply'];
            if (!feedUpdateTypes.includes(notifType)) {
                return; // Not a feed-related notification, ignore
            }

            // Must have target_id (post_id) to update
            if (!targetId) {
                return;
            }

            // Route to appropriate handler
            switch (notifType) {
                case 'new_reaction':
                    handleReactionUpdate(targetId, reactionDebounceMap, DEBOUNCE_DELAY);
                    break;

                case 'new_comment':
                case 'comment_reply':
                    handleCommentUpdate(targetId, commentDebounceMap, DEBOUNCE_DELAY);
                    break;

                case 'comment_liked':
                    // Comment likes don't affect post-level stats
                    break;
            }
        });
    }

    /**
     * Handle reaction update for a post
     * ENTERPRISE: Debounced to prevent rapid-fire API calls
     *
     * @param {number} postId Post ID to update
     * @param {Map} debounceMap Debounce timeout map
     * @param {number} debounceDelay Debounce delay in ms
     */
    function handleReactionUpdate(postId, debounceMap, debounceDelay) {
        // Clear existing debounce timeout for this post
        if (debounceMap.has(postId)) {
            clearTimeout(debounceMap.get(postId));
        }

        // Set new debounced fetch
        const timeoutId = setTimeout(async () => {
            debounceMap.delete(postId);

            // Check if post exists in DOM (user may have scrolled away)
            if (!isPostInDOM(postId)) {
                return;
            }

            // Check if ReactionPicker exists for this post
            const picker = getReactionPicker(postId);
            if (!picker) {
                return;
            }

            try {
                // Fetch fresh stats from API
                const response = await fetch(`/api/audio/reactions/${postId}`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const result = await response.json();

                if (result.success && result.data) {
                    // Transform API response to ReactionPicker format
                    // API returns: { emotion_distribution: [{emotion_id, count}, ...], user_reaction: {...} }
                    // ReactionPicker expects: { emotion_id: count, ... }
                    const newStats = {};
                    if (result.data.emotion_distribution) {
                        result.data.emotion_distribution.forEach(e => {
                            if (e.count > 0) {
                                newStats[e.emotion_id] = e.count;
                            }
                        });
                    }

                    // Get user's reaction (null if none)
                    const userReaction = result.data.user_reaction?.emotion_id || null;

                    // Update ReactionPicker
                    picker.updateStats(newStats, userReaction);

                    // ENTERPRISE: Visual feedback - subtle pulse animation on reaction bar
                    animateReactionUpdate(postId);
                }

            } catch (error) {
                console.error('[WS] Failed to fetch reaction stats:', error.message);
            }
        }, debounceDelay);

        debounceMap.set(postId, timeoutId);
    }

    /**
     * Handle comment update for a post
     * ENTERPRISE: Updates comment count badge in feed
     *
     * @param {number} postId Post ID to update
     * @param {Map} debounceMap Debounce timeout map
     * @param {number} debounceDelay Debounce delay in ms
     */
    function handleCommentUpdate(postId, debounceMap, debounceDelay) {
        // Clear existing debounce timeout for this post
        if (debounceMap.has(postId)) {
            clearTimeout(debounceMap.get(postId));
        }

        // Set new debounced update
        const timeoutId = setTimeout(async () => {
            debounceMap.delete(postId);

            // Check if post exists in DOM
            if (!isPostInDOM(postId)) {
                return;
            }

            // Find comment count element in DOM
            const commentCountEl = document.getElementById(`commentCount-${postId}`);
            if (!commentCountEl) {
                return;
            }

            try {
                // Fetch fresh comment count from API
                const response = await fetch(`/api/audio/${postId}/comments?limit=1`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const result = await response.json();

                if (result.success && typeof result.total !== 'undefined') {
                    // Update comment count in DOM
                    const currentCount = parseInt(commentCountEl.textContent, 10) || 0;
                    const newCount = result.total;

                    if (newCount !== currentCount) {
                        commentCountEl.textContent = formatNumber(newCount);

                        // ENTERPRISE: Visual feedback - pulse animation
                        animateCommentUpdate(postId);
                    }
                }

            } catch (error) {
                console.error('[WS] Failed to fetch comment count:', error.message);
            }
        }, debounceDelay);

        debounceMap.set(postId, timeoutId);
    }

    /**
     * Check if post exists in DOM (for both feed and lightbox)
     *
     * @param {number} postId Post ID
     * @returns {boolean} True if post is in DOM
     */
    function isPostInDOM(postId) {
        // Check feed container
        const feedPost = document.querySelector(`#feedContainer [data-post-id="${postId}"]`);
        if (feedPost) return true;

        // Check lightbox (PhotoLightbox may show post reactions)
        const lightboxReactions = document.getElementById(`reactions-${postId}`);
        if (lightboxReactions) return true;

        // Check modal (AudioDayModal)
        const modalReactions = document.querySelector(`[id^="modal-reactions-${postId}"]`);
        if (modalReactions) return true;

        return false;
    }

    /**
     * Get ReactionPicker instance for a post
     * ENTERPRISE: Supports multiple contexts (feed, lightbox, modal)
     *
     * @param {number} postId Post ID
     * @returns {ReactionPicker|null} ReactionPicker instance or null
     */
    function getReactionPicker(postId) {
        // 1. Try FeedManager's reactionPickers
        if (window.feedManager && window.feedManager.reactionPickers) {
            const picker = window.feedManager.reactionPickers[postId];
            if (picker) return picker;
        }

        // 2. Try global registry (if exists)
        if (window.reactionPickers && window.reactionPickers[postId]) {
            return window.reactionPickers[postId];
        }

        // 3. Try PhotoLightbox's picker (if lightbox is open)
        if (window.photoLightbox && window.photoLightbox.reactionPicker) {
            // Check if lightbox is showing this post
            if (window.photoLightbox.currentPostId === postId) {
                return window.photoLightbox.reactionPicker;
            }
        }

        return null;
    }

    /**
     * Animate reaction update (subtle visual feedback)
     * ENTERPRISE: Non-intrusive feedback to indicate fresh data
     *
     * @param {number} postId Post ID
     */
    function animateReactionUpdate(postId) {
        const container = document.getElementById(`reactions-${postId}`);
        if (!container) return;

        // Add pulse animation class
        container.classList.add('realtime-update-pulse');

        // Remove after animation completes
        setTimeout(() => {
            container.classList.remove('realtime-update-pulse');
        }, 600);
    }

    /**
     * Animate comment count update
     *
     * @param {number} postId Post ID
     */
    function animateCommentUpdate(postId) {
        const countEl = document.getElementById(`commentCount-${postId}`);
        if (!countEl) return;

        // Scale animation
        countEl.style.transition = 'transform 0.15s ease-out';
        countEl.style.transform = 'scale(1.3)';

        setTimeout(() => {
            countEl.style.transform = 'scale(1)';
        }, 150);
    }

    /**
     * Format number for display (handles thousands)
     * ENTERPRISE: Consistent number formatting across feed
     *
     * @param {number} num Number to format
     * @returns {string} Formatted number string
     */
    function formatNumber(num) {
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        }
        if (num >= 1000) {
            return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        }
        return String(num);
    }

})();
