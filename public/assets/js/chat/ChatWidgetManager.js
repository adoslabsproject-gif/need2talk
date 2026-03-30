/**
 * ChatWidgetManager.js - Facebook-style Chat Widget System
 * Enterprise Galaxy Chat System
 *
 * Creates minimizable, draggable chat popups for desktop users.
 * Mobile users continue to use fullscreen chat.
 *
 * Features:
 * - Multiple simultaneous chat windows
 * - Minimize/maximize/close
 * - Position persistence (localStorage)
 * - Stacking order management
 * - Real-time message delivery
 * - Typing indicators
 * - Unread count badges
 * - Integrated EmojiData.js picker
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-03
 * @updated 2025-12-03 - Fixed status display, integrated EmojiData, removed phone/video icons
 */

class ChatWidgetManager {
    // Singleton instance
    static #instance = null;

    // Active chat widgets
    #widgets = new Map(); // conversationUuid -> ChatWidget

    // Widget positioning
    #baseRight = 20; // px from right edge
    #baseBottom = 0; // px from bottom
    #widgetWidth = 328; // Facebook standard
    #widgetHeight = 455; // Facebook standard
    #widgetGap = 10; // px between widgets
    #maxWidgets = 5; // Max open widgets

    // Minimized bar
    #minimizedWidgets = [];

    // ENTERPRISE V10.23: Track active DM page to avoid widget/page conflict
    #activeDmPageUuid = null;

    // Z-index management
    #baseZIndex = 9000;
    #topZIndex = 9000;

    // Configuration
    #config = {
        enableSound: true,
        enableDesktopNotifications: true,
        persistState: true, // Remember open chats
    };

    constructor() {
        if (ChatWidgetManager.#instance) {
            return ChatWidgetManager.#instance;
        }
        ChatWidgetManager.#instance = this;

        this.#init();
    }

    static getInstance() {
        if (!ChatWidgetManager.#instance) {
            ChatWidgetManager.#instance = new ChatWidgetManager();
        }
        return ChatWidgetManager.#instance;
    }

    #init() {
        // Only enable on desktop
        if (this.#isMobile()) {
            return;
        }

        // ENTERPRISE V10.40: Only disable widgets on specific DM page (/chat/dm/{uuid})
        // On DM page, user is in focused conversation mode - extended view is the UI
        if (this.#isOnDmPage()) return;

        // Create widget container
        this.#createWidgetContainer();

        // Restore persisted state
        if (this.#config.persistState) {
            this.#restoreState();
        }

        // ENTERPRISE V10.41: Check for widget restore from DM back button
        // This opens the minimized widget when user comes back from extended DM view
        this.#checkWidgetRestore();

        // Listen for chat open requests
        this.#setupEventListeners();
    }

    /**
     * ENTERPRISE V10.41: Check for widget restore request from DM page back button
     * When user presses back in extended DM view, we store the conversation data
     * in sessionStorage and restore the widget minimized here on /chat
     */
    #checkWidgetRestore() {
        try {
            const restoreJson = sessionStorage.getItem('n2t_widget_restore');
            if (!restoreJson) return;

            // Clear immediately to prevent re-restore on refresh
            sessionStorage.removeItem('n2t_widget_restore');

            const restoreData = JSON.parse(restoreJson);

            // Validate data (must be recent - within 30 seconds)
            if (!restoreData.conversationUuid || !restoreData.otherUser ||
                (Date.now() - restoreData.timestamp > 30000)) {
                return;
            }

            // Open widget minimized
            // Small delay to ensure container is ready
            setTimeout(() => {
                this.openWidget(restoreData.conversationUuid, restoreData.otherUser, {
                    startMinimized: true
                });
            }, 100);

        } catch (e) {
            console.warn('[ChatWidget] Failed to restore widget:', e);
            sessionStorage.removeItem('n2t_widget_restore');
        }
    }

    /**
     * ENTERPRISE V10.40: Check if we're on a specific DM page (/chat/dm/{uuid})
     * Widget mode should be disabled ONLY on DM pages where extended view is shown
     *
     * Pages breakdown:
     * - /chat           → Lobby/rooms list → Widgets ENABLED
     * - /chat/dm/{uuid} → Extended DM view → Widgets DISABLED
     * - /chat/room/*    → Chat room view   → Widgets ENABLED
     * - /feed, /profile → Other pages      → Widgets ENABLED
     */
    #isOnDmPage() {
        return window.location.pathname.startsWith('/chat/dm/');
    }

    #isMobile() {
        return window.innerWidth < 768 ||
            /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }

    #createWidgetContainer() {
        // Container for all chat widgets
        let container = document.getElementById('chatWidgetContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'chatWidgetContainer';
            container.className = 'chat-widget-container';
            document.body.appendChild(container);
        }
        this.container = container;

        // Minimized bar at bottom
        let bar = document.getElementById('chatWidgetBar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'chatWidgetBar';
            bar.className = 'chat-widget-bar';
            document.body.appendChild(bar);
        }
        this.bar = bar;
    }

    #setupEventListeners() {
        // Listen for widget open requests
        // ENTERPRISE V10.41: Support startMinimized option from notification clicks
        window.addEventListener('n2t:openChatWidget', (e) => {
            const { conversationUuid, otherUser, startMinimized } = e.detail;
            this.openWidget(conversationUuid, otherUser, {
                startMinimized: startMinimized === true
            });
        });

        // =========================================================================
        // ENTERPRISE V10.23: DM Page ↔ Widget Coordination
        // =========================================================================
        // When user navigates to full DM page, close the widget for that conversation
        // This prevents duplicate UI (widget + page showing same conversation)
        window.addEventListener('n2t:dmPageOpened', (e) => {
            const { conversationUuid } = e.detail;
            if (this.#widgets.has(conversationUuid)) {
                this.closeWidget(conversationUuid);
            }
            this.#activeDmPageUuid = conversationUuid;
        });

        window.addEventListener('n2t:dmPageClosed', (e) => {
            this.#activeDmPageUuid = null;
        });

        window.addEventListener('n2t:conversationMarkedRead', (e) => {
            const { conversationUuid } = e.detail;
            const widget = this.#widgets.get(conversationUuid);
            if (widget) {
                widget.unreadCount = 0;
                this.#updateMinimizedBar();
            }
        });

        this.#registerWebSocketHandlers();

        window.addEventListener('n2t:wsConnected', () => {
            this.#registerWebSocketHandlers();
        });

        if (window.userPresence) {
            window.userPresence.on('statusChange', (data) => {
                this.#handlePresenceUpdate({
                    user_uuid: data.userUuid,
                    status: data.status,
                    is_online: data.status === 'online'
                });
            });
        }

        // Handle window resize
        window.addEventListener('resize', () => {
            if (this.#isMobile()) {
                // Close all widgets and redirect to mobile view
                this.#closeAllWidgets();
            }
        });
    }

    /**
     * ENTERPRISE V10.35: Register WebSocket event handlers
     * Called on init AND when n2t:wsConnected fires (handles late initialization)
     * Uses a flag to prevent duplicate handler registration
     */
    #wsHandlersRegistered = false;
    #wsRetryCount = 0;  // ENTERPRISE V10.201: Retry counter for WebSocket registration

    #registerWebSocketHandlers() {
        // ENTERPRISE V11.9: Multiple fallback paths to find WebSocketManager
        // The object may be at different locations depending on script load order
        const wsManager = window.WebSocketManager ||
                          (typeof Need2Talk !== 'undefined' && Need2Talk?.WebSocketManager) ||
                          (typeof window.Need2Talk !== 'undefined' && window.Need2Talk?.WebSocketManager);

        // ENTERPRISE V11.9: If no wsManager at all, retry - it may not be loaded yet
        if (!wsManager) {
            if (!this.#wsRetryCount) this.#wsRetryCount = 0;
            if (this.#wsRetryCount < 10) {
                this.#wsRetryCount++;
                setTimeout(() => this.#registerWebSocketHandlers(), 500);
            }
            return;
        }

        // ENTERPRISE V11.9: Verify it has the .on() method we need
        // WebSocketManager is an object literal, .on() should always exist if it loaded correctly
        if (typeof wsManager.on !== 'function') {
            if (!this.#wsRetryCount) this.#wsRetryCount = 0;
            if (this.#wsRetryCount < 10) {
                this.#wsRetryCount++;
                setTimeout(() => this.#registerWebSocketHandlers(), 500);
            } else {
                // ENTERPRISE V11.9: Log detailed info for debugging
                console.error('[ChatWidget] WebSocketManager.on() missing after 10 retries', {
                    wsManagerExists: !!wsManager,
                    wsManagerType: typeof wsManager,
                    wsManagerKeys: wsManager ? Object.keys(wsManager).slice(0, 10) : [],
                    onType: typeof wsManager?.on
                });
            }
            return;
        }
        this.#wsRetryCount = 0;  // Reset on success

        if (this.#wsHandlersRegistered) return;
        this.#wsHandlersRegistered = true;

        wsManager.on('dm_received', (data) => {
            this.#handleIncomingMessage(data);
        });

        // Handle audio upload completion for sender
        wsManager.on('dm_audio_uploaded', (data) => {
            this.#handleIncomingMessage(data);
        });

        wsManager.on('typing_indicator', (data) => {
            this.#handleTypingIndicator(data);
        });

        wsManager.on('friend_presence_changed', (data) => {
            const presencePayload = data.payload || data;
            this.#handlePresenceUpdate(presencePayload);
        });

        wsManager.on('dm_read_receipt', (data) => {
            this.#handleReadReceipt(data);
        });
    }

    /**
     * ENTERPRISE V10.8: Handle presence update for other users
     * Updates all widgets showing the user whose status changed
     *
     * ENTERPRISE V10.39: Use is_online flag as primary indicator
     * - is_online=true → user is connected, use their status
     * - is_online=false → user is disconnected, show as offline
     *
     * @param {Object} payload - { user_uuid, status, is_online }
     */
    #handlePresenceUpdate(payload) {
        const userUuid = payload.user_uuid || payload.userUuid;
        const isOnline = payload.is_online === true;
        let rawStatus = payload.status;

        // ENTERPRISE V10.38: Normalize status - server sometimes sends object {status: 'online', ...}
        if (typeof rawStatus === 'object' && rawStatus !== null) {
            rawStatus = rawStatus.status || 'online';
        }

        if (!userUuid) {
            console.warn('[ChatWidget] Invalid presence update payload:', payload);
            return;
        }

        // ENTERPRISE V10.39: Determine effective status based on is_online flag
        // ENTERPRISE V10.200: Pass object with last_seen for offline users
        const presenceData = {
            status: isOnline ? (rawStatus || 'online') : 'offline',
            last_seen: payload.last_seen || null
        };

        // Find all widgets showing this user and update their status
        for (const [conversationUuid, widget] of this.#widgets) {
            if (widget.otherUser?.uuid === userUuid) {
                widget.updatePresence(presenceData);
            }
        }

        // Also update minimized bar if affected
        this.#updateMinimizedBar();
    }

    /**
     * ENTERPRISE V10.8: Public method to update presence from external sources
     * Can be called by other components that detect presence changes
     *
     * @param {string} userUuid - UUID of user whose status changed
     * @param {string} newStatus - New status ('online', 'busy', 'dnd', 'away', 'offline')
     */
    updateUserPresence(userUuid, newStatus) {
        this.#handlePresenceUpdate({ user_uuid: userUuid, status: newStatus });
    }

    /**
     * ENTERPRISE V10.90: Fetch presence for a specific user and update widget
     * Used when opening widget from notification (status unknown)
     *
     * @param {string} userUuid - UUID of user to fetch presence for
     * @param {ChatWidget} widget - Widget to update
     */
    async #fetchAndUpdatePresence(userUuid, widget) {
        try {
            // ENTERPRISE V10.90: Use correct presence endpoint GET /api/chat/presence/{uuid}
            const response = await fetch(`/api/chat/presence/${userUuid}`);

            if (!response.ok) return;

            const data = await response.json();
            if (data.success && data.data) {
                const presence = data.data;
                const isOnline = presence.is_online === true;

                let status = presence.status;
                if (typeof status === 'object' && status !== null) {
                    status = status.status || 'online';
                }

                // ENTERPRISE V10.200: Pass object with last_seen for offline users
                const presenceData = {
                    status: isOnline ? (status || 'online') : 'offline',
                    last_seen: presence.last_seen || null
                };
                widget.updatePresence(presenceData);
                this.#updateMinimizedBar();
            }
        } catch (e) {
            // Silent fail - presence will update via WebSocket
        }
    }

    /**
     * Open a chat widget
     *
     * ENTERPRISE V10.20: Extended to support options for auto-open functionality
     *
     * @param {string} conversationUuid - The conversation UUID
     * @param {object} otherUser - The other user in the conversation
     * @param {object} options - Optional parameters
     * @param {boolean} options.startMinimized - Start widget minimized
     * @param {object} options.initialMessage - Initial message to display
     */
    openWidget(conversationUuid, otherUser, options = {}) {
        if (this.#isMobile()) {
            // Redirect to fullscreen DM on mobile
            window.location.href = `/chat/dm/${conversationUuid}`;
            return;
        }

        // ENTERPRISE V10.40: Prevent opening widget if conversation is in extended view
        if (this.#activeDmPageUuid === conversationUuid) return;

        // ENTERPRISE V10.40: Safety check - ensure container exists
        // This should never happen if #init() ran correctly, but defense-in-depth
        if (!this.container) {
            console.warn('[ChatWidget] Container not initialized, redirecting to DM page');
            window.location.href = `/chat/dm/${conversationUuid}`;
            return;
        }

        // Check if already open
        if (this.#widgets.has(conversationUuid)) {
            const widget = this.#widgets.get(conversationUuid);

            // ENTERPRISE V10.20: If there's an initial message, add it
            if (options.initialMessage) {
                widget.addMessage(options.initialMessage);
            }

            widget.focus();
            if (widget.isMinimized && !options.startMinimized) {
                // ENTERPRISE V10.36 FIX: Call manager's maximizeWidget() to update minimized bar
                // Previously called widget.maximize() directly which didn't remove from bar
                this.maximizeWidget(conversationUuid);
            }
            return;
        }

        // Check max widgets
        if (this.#widgets.size >= this.#maxWidgets) {
            // Close oldest widget
            const oldest = this.#widgets.values().next().value;
            oldest.close();
        }

        // ENTERPRISE V10.60: Pass startMinimized to constructor so loadMessages() knows
        // the widget state BEFORE it runs. This fixes the timing issue where
        // loadMessages() was called with isMinimized=false, then minimize() was called after.
        const widget = new ChatWidget(this, conversationUuid, otherUser, {
            startMinimized: options.startMinimized === true
        });
        this.#widgets.set(conversationUuid, widget);

        // ENTERPRISE V10.90: Fetch real-time presence if status is unknown
        // When opening from notification, status defaults to 'offline' - fetch actual status
        // ENTERPRISE V10.202: Skip fetch if we already have last_seen (from batch API)
        // This prevents overwriting last_seen with a new fetch that returns 'offline' without it
        const needsPresenceFetch = otherUser.uuid &&
            (!otherUser.status || (otherUser.status === 'offline' && !otherUser.last_seen));
        if (needsPresenceFetch) {
            this.#fetchAndUpdatePresence(otherUser.uuid, widget);
        }

        // ENTERPRISE V10.20: Add initial message if provided
        if (options.initialMessage) {
            widget.addMessage(options.initialMessage);
        }

        // ENTERPRISE V10.89: Restore unread count BEFORE saving state
        // This prevents the race condition where saveState() was called with unreadCount=0
        // before restoreState() could set the correct value
        if (options.restoreUnreadCount > 0) {
            widget.unreadCount = options.restoreUnreadCount;
        }

        // ENTERPRISE V10.60: If starting minimized, add to minimized list and update bar
        if (options.startMinimized) {
            if (!this.#minimizedWidgets.includes(conversationUuid)) {
                this.#minimizedWidgets.push(conversationUuid);
            }
            this.#updateMinimizedBar();
        }

        // Position it
        this.#positionWidget(widget);

        // Save state
        this.#saveState();
    }

    /**
     * Close a widget
     */
    closeWidget(conversationUuid) {
        const widget = this.#widgets.get(conversationUuid);
        if (widget) {
            widget.destroy();
            this.#widgets.delete(conversationUuid);

            // Remove from minimized list if present
            const idx = this.#minimizedWidgets.indexOf(conversationUuid);
            if (idx > -1) {
                this.#minimizedWidgets.splice(idx, 1);
            }

            this.#updateMinimizedBar();
            this.#repositionWidgets();
            this.#saveState();
        }
    }

    /**
     * Minimize a widget to the bar
     */
    minimizeWidget(conversationUuid) {
        const widget = this.#widgets.get(conversationUuid);
        if (widget) {
            widget.minimize();
            if (!this.#minimizedWidgets.includes(conversationUuid)) {
                this.#minimizedWidgets.push(conversationUuid);
            }
            this.#updateMinimizedBar();
            this.#repositionWidgets();
            this.#saveState();
        }
    }

    /**
     * Maximize a widget from the bar
     */
    maximizeWidget(conversationUuid) {
        const widget = this.#widgets.get(conversationUuid);
        if (widget) {
            widget.maximize();
            const idx = this.#minimizedWidgets.indexOf(conversationUuid);
            if (idx > -1) {
                this.#minimizedWidgets.splice(idx, 1);
            }
            this.#updateMinimizedBar();
            this.#repositionWidgets();
            this.#saveState();
        }
    }

    /**
     * Bring widget to front
     */
    focusWidget(conversationUuid) {
        this.#topZIndex++;
        const widget = this.#widgets.get(conversationUuid);
        if (widget) {
            widget.setZIndex(this.#topZIndex);
        }
    }

    /**
     * ENTERPRISE V10.82: Get widget data by conversation UUID
     * Used for cross-view badge synchronization
     * @param {string} conversationUuid
     * @returns {Object|null} Widget data including friendUuid
     */
    getWidgetByConversation(conversationUuid) {
        const widget = this.#widgets.get(conversationUuid);
        if (widget) {
            return {
                conversationUuid: widget.conversationUuid,
                friendUuid: widget.otherUser?.uuid,
                friendName: widget.otherUser?.nickname,
                isMinimized: widget.isMinimized,
                unreadCount: widget.unreadCount
            };
        }
        return null;
    }

    /**
     * Position widget based on open count
     */
    #positionWidget(widget) {
        // Count non-minimized widgets
        let openCount = 0;
        for (const [uuid, w] of this.#widgets) {
            if (!w.isMinimized && uuid !== widget.conversationUuid) {
                openCount++;
            }
        }

        // Position from right
        const right = this.#baseRight + (openCount * (this.#widgetWidth + this.#widgetGap));
        widget.setPosition({ right, bottom: this.#baseBottom });
        widget.setZIndex(++this.#topZIndex);
    }

    /**
     * Reposition all widgets after close/minimize
     */
    #repositionWidgets() {
        let index = 0;
        for (const [uuid, widget] of this.#widgets) {
            if (!widget.isMinimized) {
                const right = this.#baseRight + (index * (this.#widgetWidth + this.#widgetGap));
                widget.setPosition({ right, bottom: this.#baseBottom });
                index++;
            }
        }
    }

    /**
     * Update minimized bar with avatars
     */
    #updateMinimizedBar() {
        this.bar.innerHTML = '';

        for (const uuid of this.#minimizedWidgets) {
            const widget = this.#widgets.get(uuid);
            if (!widget) continue;

            // ENTERPRISE FIX: Get correct status color
            const statusColor = this.#getStatusColor(widget.otherUser.status);

            const item = document.createElement('div');
            item.className = 'chat-widget-bar-item';
            item.innerHTML = `
                <div class="chat-widget-bar-avatar">
                    <img src="${widget.otherUser.avatar_url || '/assets/img/default-avatar.png'}"
                         alt="${widget.otherUser.nickname}">
                    ${widget.unreadCount > 0 ? `<span class="chat-widget-bar-badge">${widget.unreadCount}</span>` : ''}
                    <span class="chat-widget-bar-status ${statusColor}"></span>
                </div>
                <a href="/u/${widget.otherUser.uuid}"
                   class="chat-widget-bar-name chat-widget-bar-link"
                   title="Vai al profilo di ${widget.otherUser.nickname}"
                   onclick="event.stopPropagation();">${widget.otherUser.nickname}</a>
            `;

            item.addEventListener('click', () => {
                this.maximizeWidget(uuid);
            });

            this.bar.appendChild(item);
        }
    }

    /**
     * Get CSS class for status color (minimized bar)
     * Uses BEM modifier classes defined in chat-widget.css
     */
    #getStatusColor(status) {
        switch (status) {
            case 'online':
                return 'chat-widget-bar-status--online';
            case 'busy':
            case 'dnd':
                return 'chat-widget-bar-status--busy';
            case 'away':
                return 'chat-widget-bar-status--away';
            default:
                return 'chat-widget-bar-status--offline';
        }
    }

    /**
     * Handle incoming message
     *
     * ENTERPRISE GALAXY V10.20: Auto-open widget on new DM
     * When a DM arrives and the widget doesn't exist, create it automatically.
     * This mimics Facebook Messenger behavior - messages always appear in real-time.
     *
     * ENTERPRISE V10.23: DM Page coordination
     * If the DM page is open for this conversation, DON'T create a widget.
     * The DM page has its own WebSocket listener and will handle the message.
     */
    #handleIncomingMessage(data) {
        const payload = data.payload || data;
        const convUuid = payload.conversation_uuid;
        const message = payload.message;

        // Skip if DM page is open for this conversation
        if (this.#activeDmPageUuid === convUuid) return;

        // Find or create widget
        let widget = this.#widgets.get(convUuid);

        if (widget) {
            // Widget exists - add message
            widget.addMessage(message);

            // If minimized, increment unread
            if (widget.isMinimized) {
                widget.incrementUnread();
                this.#updateMinimizedBar();
                // ENTERPRISE V10.90: Save state to persist unread count across page changes
                this.#saveState();
            }
        } else {
            // ENTERPRISE V10.20: Widget doesn't exist - create it automatically
            // Build otherUser from message sender info
            // ENTERPRISE V10.68 (2025-12-07): Include status='online' - sender is actively messaging
            // Anyone sending a message has an active WebSocket connection, so they're online by definition
            const otherUser = {
                uuid: message.sender_uuid,
                nickname: message.sender_nickname,
                avatar_url: message.sender_avatar,
                status: 'online',
            };

            // Create widget minimized (less intrusive)
            this.openWidget(convUuid, otherUser, {
                startMinimized: true,
                initialMessage: message
            });

            // Get the newly created widget
            widget = this.#widgets.get(convUuid);
            if (widget) {
                widget.incrementUnread();
                this.#updateMinimizedBar();
                // ENTERPRISE V10.90: Save state to persist unread count across page changes
                this.#saveState();
            }

            // Play notification sound
            this.#playNotificationSound();
        }
    }

    /**
     * Play notification sound for new messages
     */
    #playNotificationSound() {
        if (!this.#config.enableSound) return;

        try {
            // Use the standard notification sound
            const audio = new Audio('/assets/sounds/notification.mp3');
            audio.volume = 0.5;
            audio.play().catch(() => {
                // Autoplay blocked - ignore silently
            });
        } catch (e) {
            // Sound not available - ignore
        }
    }

    /**
     * Handle typing indicator
     */
    #handleTypingIndicator(data) {
        const payload = data.payload || data;
        const widget = this.#widgets.get(payload.target_id);

        if (widget) {
            widget.showTyping(payload.is_typing, payload.user_name);
        }
    }

    /**
     * ENTERPRISE V10.40: Handle read receipt from WebSocket
     * Updates message status from "inviato" to "letto"
     */
    #handleReadReceipt(data) {
        const payload = data.payload || data;
        const conversationUuid = payload.conversation_uuid;

        if (!conversationUuid) {
            console.warn('[ChatWidget] dm_read_receipt missing conversation_uuid:', payload);
            return;
        }

        const widget = this.#widgets.get(conversationUuid);
        if (widget) {
            widget.markAllMessagesAsRead();
        }
    }

    #getStateStorageKey() {
        const userUuid = window.APP_USER?.uuid || window.currentUser?.uuid;
        if (!userUuid) return null;
        return `n2t_chat_widgets_${userUuid}`;
    }

    #saveState() {
        const storageKey = this.#getStateStorageKey();
        if (!storageKey) return;

        const state = {
            widgets: [],
            minimized: this.#minimizedWidgets,
        };

        for (const [uuid, widget] of this.#widgets) {
            state.widgets.push({
                conversationUuid: uuid,
                otherUser: widget.otherUser,
                isMinimized: widget.isMinimized,
                unreadCount: widget.unreadCount || 0, // ENTERPRISE V10.85: Persist unread count
            });
        }

        localStorage.setItem(storageKey, JSON.stringify(state));

        // Clean up old global key if exists (migration)
        if (localStorage.getItem('n2t_chat_widgets')) {
            localStorage.removeItem('n2t_chat_widgets');
        }
    }

    async #restoreState() {
        try {
            const storageKey = this.#getStateStorageKey();
            if (!storageKey) return;

            const stateJson = localStorage.getItem(storageKey);
            if (!stateJson) return;

            const state = JSON.parse(stateJson);
            const widgetsToRestore = state.widgets || [];

            if (widgetsToRestore.length === 0) return;

            // Collect user UUIDs for batch presence check
            const userUuids = widgetsToRestore
                .map(item => item.otherUser?.uuid)
                .filter(uuid => uuid);

            // Fetch current presence for all users (batch API call)
            // ENTERPRISE V10.38: Direct API call fallback when chatManager doesn't exist (non-chat pages)
            let presenceMap = {};
            if (userUuids.length > 0) {
                try {
                    if (window.chatManager) {
                        // Use chatManager if available (chat page)
                        presenceMap = await window.chatManager.getBatchPresence(userUuids) || {};
                    } else {
                        // Direct API call fallback (feed, profile, etc.)
                        const response = await fetch(`/api/chat/presence/batch?uuids=${userUuids.join(',')}`);
                        if (response.ok) {
                            const data = await response.json();
                            presenceMap = data.data || {};
                        }
                    }
                } catch (e) {
                    // Presence fetch failed - continue with cached status
                }
            }

            // Restore widgets with updated presence
            for (const item of widgetsToRestore) {
                const otherUser = { ...item.otherUser };

                // Update status from fresh presence data
                // ENTERPRISE V10.39: Use is_online flag as primary indicator
                // The API returns: { status: 'online', is_online: true/false }
                // - is_online=true means WebSocket connected NOW
                // - status='online' could be just preferred_status (user between page loads)
                if (otherUser.uuid && presenceMap[otherUser.uuid]) {
                    const presence = presenceMap[otherUser.uuid];
                    const isActuallyOnline = presence.is_online === true;

                    if (isActuallyOnline) {
                        let rawStatus = presence.status;
                        if (typeof rawStatus === 'object' && rawStatus !== null) {
                            rawStatus = rawStatus.status || 'online';
                        }
                        otherUser.status = rawStatus || 'online';
                        otherUser.last_seen = null;  // Online users don't need last_seen
                    } else {
                        otherUser.status = 'offline';
                        // ENTERPRISE V10.200: Store last_seen for offline status display
                        otherUser.last_seen = presence.last_seen || null;
                        otherUser.last_seen_timestamp = presence.last_seen_timestamp || null;
                    }
                } else {
                    otherUser.status = 'offline';
                    otherUser.last_seen = null;
                }

                // ENTERPRISE V10.60: Pass startMinimized to openWidget so constructor
                // sets isMinimized BEFORE loadMessages() runs
                // ENTERPRISE V10.89: Pass unreadCount to prevent save-before-restore race condition
                // Previously: openWidget saved state with unreadCount=0, then we set it after
                // Now: openWidget receives unreadCount and sets it BEFORE saveState()
                this.openWidget(item.conversationUuid, otherUser, {
                    startMinimized: item.isMinimized === true,
                    restoreUnreadCount: item.unreadCount || 0
                });

                // ENTERPRISE V10.60: No need to call minimizeWidget() separately anymore
                // The constructor now handles isMinimized state and CSS class
            }
        } catch (e) {
            console.warn('[ChatWidget] Failed to restore state:', e);
        }
    }

    /**
     * Close all widgets
     */
    #closeAllWidgets() {
        for (const uuid of this.#widgets.keys()) {
            this.closeWidget(uuid);
        }
    }

    /**
     * Get widget dimensions
     */
    get widgetDimensions() {
        return {
            width: this.#widgetWidth,
            height: this.#widgetHeight,
        };
    }
}


/**
 * Individual Chat Widget
 */
class ChatWidget {
    #manager;
    #element;
    #headerEl;
    #bodyEl;
    #inputEl;
    #messagesEl;
    #typingEl;
    #emojiPickerEl = null;

    conversationUuid;
    otherUser;
    isMinimized = false;
    unreadCount = 0;

    #messages = [];
    #position = { right: 20, bottom: 0 };
    #zIndex = 9000;

    /**
     * ENTERPRISE V10.60: Constructor now accepts options to fix timing issue
     * startMinimized must be set BEFORE loadMessages() runs, so that
     * onConversationOpened() knows not to mark as read
     */
    constructor(manager, conversationUuid, otherUser, options = {}) {
        this.#manager = manager;
        this.conversationUuid = conversationUuid;
        this.otherUser = otherUser;

        // ENTERPRISE V10.60: Set isMinimized BEFORE createElement and loadMessages
        // This fixes the timing issue where loadMessages() was called with isMinimized=false
        this.isMinimized = options.startMinimized === true;

        this.#createElement();

        // ENTERPRISE V10.60: If starting minimized, apply minimized class immediately
        if (this.isMinimized) {
            this.#element.classList.add('chat-widget--minimized');
        }

        this.#loadMessages();
        this.#setupEventListeners();
    }

    #createElement() {
        const { width, height } = this.#manager.widgetDimensions;

        this.#element = document.createElement('div');
        this.#element.className = 'chat-widget';
        this.#element.style.width = `${width}px`;
        this.#element.style.height = `${height}px`;
        this.#element.dataset.conversationUuid = this.conversationUuid;

        // ENTERPRISE FIX: Correct status color based on actual status
        const statusColor = this.#getStatusColor(this.otherUser.status);

        this.#element.innerHTML = `
            <div class="chat-widget-header">
                <div class="chat-widget-header-left">
                    <div class="chat-widget-avatar">
                        <img src="${this.otherUser.avatar_url || '/assets/img/default-avatar.png'}"
                             alt="${this.otherUser.nickname}">
                        <span class="chat-widget-status ${statusColor}"></span>
                    </div>
                    <div class="chat-widget-user-info">
                        <a href="/u/${this.otherUser.uuid}"
                           class="chat-widget-username"
                           title="Vai al profilo di ${this.otherUser.nickname}">${this.otherUser.nickname}</a>
                        <span class="chat-widget-user-status">${this.#getStatusText()}</span>
                    </div>
                </div>
                <div class="chat-widget-header-actions">
                    <button class="chat-widget-btn chat-widget-btn-minimize" title="Riduci">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                        </svg>
                    </button>
                    <button class="chat-widget-btn chat-widget-btn-expand" title="Apri in pagina intera">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                        </svg>
                    </button>
                    <button class="chat-widget-btn chat-widget-btn-close" title="Chiudi">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="chat-widget-body">
                <div class="chat-widget-messages">
                    <div class="chat-widget-loading">
                        <div class="chat-widget-spinner"></div>
                    </div>
                </div>
                <div class="chat-widget-typing"></div>
            </div>
            <div class="chat-widget-footer">
                <div class="chat-widget-input-container">
                    <button class="chat-widget-input-btn chat-widget-emoji-btn" title="Emoji">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </button>
                    <button class="chat-widget-input-btn chat-widget-mic-btn" title="Messaggio vocale">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                        </svg>
                    </button>
                    <textarea class="chat-widget-input"
                              placeholder="Aa"
                              rows="1"
                              maxlength="2000"></textarea>
                    <button class="chat-widget-send-btn" title="Invia" disabled>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                    </button>
                </div>
            </div>
        `;

        // Cache elements
        this.#headerEl = this.#element.querySelector('.chat-widget-header');
        this.#bodyEl = this.#element.querySelector('.chat-widget-body');
        this.#messagesEl = this.#element.querySelector('.chat-widget-messages');
        this.#typingEl = this.#element.querySelector('.chat-widget-typing');
        this.#inputEl = this.#element.querySelector('.chat-widget-input');

        // Append to container
        this.#manager.container.appendChild(this.#element);
    }

    #setupEventListeners() {
        // Focus on click
        this.#element.addEventListener('mousedown', () => {
            this.focus();
        });

        // Header buttons
        this.#element.querySelector('.chat-widget-btn-minimize').addEventListener('click', () => {
            this.#manager.minimizeWidget(this.conversationUuid);
        });

        this.#element.querySelector('.chat-widget-btn-expand').addEventListener('click', () => {
            // ENTERPRISE FIX: Close widget first, then redirect
            this.#manager.closeWidget(this.conversationUuid);
            window.location.href = `/chat/dm/${this.conversationUuid}`;
        });

        this.#element.querySelector('.chat-widget-btn-close').addEventListener('click', () => {
            this.#manager.closeWidget(this.conversationUuid);
        });

        // Input handling
        const sendBtn = this.#element.querySelector('.chat-widget-send-btn');

        this.#inputEl.addEventListener('input', () => {
            // Auto-resize
            this.#inputEl.style.height = 'auto';
            this.#inputEl.style.height = Math.min(this.#inputEl.scrollHeight, 100) + 'px';

            // Enable/disable send button
            sendBtn.disabled = !this.#inputEl.value.trim();

            // Send typing indicator
            this.#sendTypingIndicator(true);

            // ENTERPRISE V10.78: Trigger read receipt on first input
            // When user starts typing, mark incoming messages as read
            if (!this.isMinimized && window.readReceiptManager) {
                window.readReceiptManager.onConversationOpened(this.conversationUuid, true);
            }
        });

        this.#inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.#sendMessage();
            }
        });

        this.#inputEl.addEventListener('blur', () => {
            this.#sendTypingIndicator(false);
        });

        // ENTERPRISE V10.78: Trigger read receipt when user focuses input
        // This marks incoming messages as read immediately when user clicks on the widget input
        this.#inputEl.addEventListener('focus', () => {
            if (!this.isMinimized && window.readReceiptManager) {
                window.readReceiptManager.onConversationOpened(this.conversationUuid, true);
            }
        });

        sendBtn.addEventListener('click', () => {
            this.#sendMessage();
        });

        // ENTERPRISE FIX: Proper emoji picker using EmojiData.js
        const emojiBtn = this.#element.querySelector('.chat-widget-emoji-btn');
        emojiBtn.addEventListener('click', () => {
            this.#openEmojiPicker();
        });

        // ENTERPRISE V3.1: Audio recording button
        const micBtn = this.#element.querySelector('.chat-widget-mic-btn');
        if (micBtn) {
            micBtn.addEventListener('click', () => {
                this.#toggleAudioRecording();
            });
        }

        // ENTERPRISE V3.1: Audio playback delegation (click on play buttons)
        this.#messagesEl.addEventListener('click', (e) => {
            const playBtn = e.target.closest('.chat-widget-audio-play');
            if (playBtn) {
                const audioEl = playBtn.closest('.chat-widget-audio');
                if (audioEl) {
                    this.#toggleWidgetAudioPlayback(audioEl);
                }
            }
        });
    }

    // ========================================================================
    // ENTERPRISE V3.2: WIDGET AUDIO RECORDING WITH CONTROLS
    // ========================================================================

    #widgetMediaRecorder = null;
    #widgetAudioChunks = [];
    #widgetRecordingStart = null;
    #widgetCurrentAudio = null;
    #widgetRecordingStream = null;
    #widgetRecordingCancelled = false;
    #widgetRecordingTimerInterval = null;
    #widgetRecordingPending = false; // ENTERPRISE V10.72: Prevent multiple recordings while waiting for mic permission

    /**
     * ENTERPRISE V3.2: Toggle audio recording with full controls
     * - Click mic to start recording → shows recording UI with pause/cancel/send
     * - Pause button: pauses/resumes recording
     * - Cancel (X): discards recording without sending
     * - Send (checkmark): stops and sends the recording
     */
    async #toggleAudioRecording() {
        const micBtn = this.#element.querySelector('.chat-widget-mic-btn');
        const footer = this.#element.querySelector('.chat-widget-footer');

        // ENTERPRISE V10.72: Block if already waiting for mic permission
        if (this.#widgetRecordingPending) {
            return;
        }

        // If recording UI is shown, handle differently
        if (this.#widgetMediaRecorder && this.#widgetMediaRecorder.state !== 'inactive') {
            // Don't toggle here - use the recording UI buttons
            return;
        }

        // Start recording
        this.#widgetRecordingPending = true; // Lock before getUserMedia
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.#widgetRecordingStream = stream;
            this.#widgetMediaRecorder = new MediaRecorder(stream, {
                mimeType: 'audio/webm;codecs=opus'
            });
            this.#widgetAudioChunks = [];
            this.#widgetRecordingStart = Date.now();
            this.#widgetRecordingCancelled = false;

            this.#widgetMediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    this.#widgetAudioChunks.push(e.data);
                }
            };

            this.#widgetMediaRecorder.onstop = async () => {
                // Stop all tracks
                this.#widgetRecordingStream?.getTracks().forEach(t => t.stop());
                this.#widgetRecordingStream = null;

                // Clear timer
                if (this.#widgetRecordingTimerInterval) {
                    clearInterval(this.#widgetRecordingTimerInterval);
                    this.#widgetRecordingTimerInterval = null;
                }

                // Hide recording UI, show normal input
                this.#hideRecordingUI();

                if (this.#widgetRecordingCancelled) return;

                const duration = (Date.now() - this.#widgetRecordingStart) / 1000;
                if (duration < 0.5) return;

                const audioBlob = new Blob(this.#widgetAudioChunks, { type: 'audio/webm' });
                await this.#uploadWidgetAudio(audioBlob, duration);
            };

            // ENTERPRISE V10.176: Use timeslice (1000ms) to collect chunks incrementally
            // This is more robust than collecting all data on stop()
            this.#widgetMediaRecorder.start(1000);
            this.#widgetRecordingPending = false; // ENTERPRISE V10.72: Recording started, unlock

            // Show recording UI
            this.#showRecordingUI();

            // ENTERPRISE V3.2: Auto-PAUSE after 30s (not auto-send)
            // Recording stops but waits for user to confirm send or cancel
            setTimeout(() => {
                if (this.#widgetMediaRecorder?.state === 'recording') {
                    // Pause instead of stop - let user decide
                    this.#widgetMediaRecorder.pause();
                    this.#widgetPausedAt = Date.now();

                    // Update UI to show paused state
                    const pauseBtn = this.#element?.querySelector('.chat-widget-rec-pause');
                    const dot = this.#element?.querySelector('.chat-widget-rec-dot');

                    if (pauseBtn) {
                        pauseBtn.innerHTML = `
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        `;
                        pauseBtn.title = 'Riprendi';
                        pauseBtn.disabled = true; // Can't resume past 30s
                        pauseBtn.style.opacity = '0.5';
                    }
                    dot?.classList.add('paused');

                    // Show "30s max reached" indicator
                    const timerEl = this.#element?.querySelector('.chat-widget-rec-timer');
                    if (timerEl) {
                        timerEl.textContent = '0:30 MAX';
                        timerEl.style.color = '#fbbf24';
                    }
                }
            }, 30000);

        } catch (err) {
            this.#widgetRecordingPending = false; // ENTERPRISE V10.72: Unlock on error
            console.error('[ChatWidget] Microphone access denied:', err);
            this.#showMicError();
        }
    }

    /**
     * Show recording UI with timer and controls
     */
    #showRecordingUI() {
        const footer = this.#element.querySelector('.chat-widget-footer');
        const inputContainer = footer.querySelector('.chat-widget-input-container');

        // Hide normal input
        inputContainer.style.display = 'none';

        // Create recording UI
        const recordingUI = document.createElement('div');
        recordingUI.className = 'chat-widget-recording-ui';
        recordingUI.innerHTML = `
            <button class="chat-widget-rec-btn chat-widget-rec-cancel" title="Annulla">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
            <div class="chat-widget-rec-indicator">
                <span class="chat-widget-rec-dot"></span>
                <span class="chat-widget-rec-timer">0:00</span>
            </div>
            <button class="chat-widget-rec-btn chat-widget-rec-pause" title="Pausa">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="4" width="4" height="16"/>
                    <rect x="14" y="4" width="4" height="16"/>
                </svg>
            </button>
            <button class="chat-widget-rec-btn chat-widget-rec-send" title="Invia">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 6L9 17l-5-5"/>
                </svg>
            </button>
        `;

        footer.appendChild(recordingUI);

        // Setup event listeners
        recordingUI.querySelector('.chat-widget-rec-cancel').addEventListener('click', () => {
            this.#cancelRecording();
        });

        recordingUI.querySelector('.chat-widget-rec-pause').addEventListener('click', () => {
            this.#togglePauseRecording();
        });

        recordingUI.querySelector('.chat-widget-rec-send').addEventListener('click', () => {
            this.#stopAndSendRecording();
        });

        // Start timer
        this.#widgetRecordingTimerInterval = setInterval(() => {
            this.#updateRecordingTimer();
        }, 1000);
    }

    /**
     * Hide recording UI and restore normal input
     */
    #hideRecordingUI() {
        const footer = this.#element.querySelector('.chat-widget-footer');
        const recordingUI = footer.querySelector('.chat-widget-recording-ui');
        const inputContainer = footer.querySelector('.chat-widget-input-container');

        if (recordingUI) {
            recordingUI.remove();
        }
        if (inputContainer) {
            inputContainer.style.display = '';
        }
    }

    /**
     * Update recording timer display
     */
    #updateRecordingTimer() {
        const timerEl = this.#element.querySelector('.chat-widget-rec-timer');
        if (!timerEl || !this.#widgetRecordingStart) return;

        // Account for paused time
        let elapsed;
        if (this.#widgetMediaRecorder?.state === 'paused') {
            // Show paused time (don't increment)
            elapsed = (this.#widgetPausedAt - this.#widgetRecordingStart) / 1000;
        } else {
            elapsed = (Date.now() - this.#widgetRecordingStart) / 1000;
        }

        const mins = Math.floor(elapsed / 60);
        const secs = Math.floor(elapsed % 60);
        timerEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    #widgetPausedAt = null;

    /**
     * Toggle pause/resume recording
     */
    #togglePauseRecording() {
        if (!this.#widgetMediaRecorder) return;

        const pauseBtn = this.#element.querySelector('.chat-widget-rec-pause');
        const dot = this.#element.querySelector('.chat-widget-rec-dot');

        if (this.#widgetMediaRecorder.state === 'recording') {
            // Pause
            this.#widgetMediaRecorder.pause();
            this.#widgetPausedAt = Date.now();

            pauseBtn.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M8 5v14l11-7z"/>
                </svg>
            `;
            pauseBtn.title = 'Riprendi';
            dot?.classList.add('paused');

        } else if (this.#widgetMediaRecorder.state === 'paused') {
            // Resume - adjust start time to account for pause
            const pauseDuration = Date.now() - this.#widgetPausedAt;
            this.#widgetRecordingStart += pauseDuration;
            this.#widgetMediaRecorder.resume();

            pauseBtn.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="4" width="4" height="16"/>
                    <rect x="14" y="4" width="4" height="16"/>
                </svg>
            `;
            pauseBtn.title = 'Pausa';
            dot?.classList.remove('paused');
        }
    }

    /**
     * Cancel recording without sending
     */
    #cancelRecording() {
        this.#widgetRecordingCancelled = true;

        if (this.#widgetMediaRecorder && this.#widgetMediaRecorder.state !== 'inactive') {
            this.#widgetMediaRecorder.stop();
        }

        // Cleanup stream immediately
        this.#widgetRecordingStream?.getTracks().forEach(t => t.stop());
        this.#widgetRecordingStream = null;

        // Clear timer
        if (this.#widgetRecordingTimerInterval) {
            clearInterval(this.#widgetRecordingTimerInterval);
            this.#widgetRecordingTimerInterval = null;
        }

        this.#hideRecordingUI();
    }

    /**
     * Stop recording and send
     *
     * ENTERPRISE V10.175: Fix for paused state - MediaRecorder in 'paused' state
     * doesn't properly flush all data on stop(). We need to resume briefly first.
     */
    #stopAndSendRecording() {
        this.#widgetRecordingCancelled = false;

        if (this.#widgetMediaRecorder && this.#widgetMediaRecorder.state !== 'inactive') {
            // ENTERPRISE V10.175: If paused (30s max reached), resume briefly to ensure
            // all chunks are flushed before stop(). Without this, the last chunk may be lost.
            if (this.#widgetMediaRecorder.state === 'paused') {
                this.#widgetMediaRecorder.resume();
                // Give it a tick to process, then stop
                setTimeout(() => {
                    if (this.#widgetMediaRecorder?.state === 'recording') {
                        this.#widgetMediaRecorder.stop();
                    }
                }, 50);
            } else {
                this.#widgetMediaRecorder.stop();
            }
        }
    }

    /**
     * Show microphone error message
     */
    #showMicError() {
        const footer = this.#element.querySelector('.chat-widget-footer');

        const errorEl = document.createElement('div');
        errorEl.className = 'chat-widget-mic-error';
        errorEl.textContent = '🎤 Accesso al microfono negato';
        errorEl.style.cssText = `
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(239, 68, 68, 0.9);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 12px;
            margin-bottom: 8px;
            white-space: nowrap;
        `;

        footer.style.position = 'relative';
        footer.appendChild(errorEl);

        setTimeout(() => errorEl.remove(), 3000);
    }

    async #uploadWidgetAudio(blob, duration) {
        // ENTERPRISE V10.100 (2025-12-09): TRUE E2E Encryption for Widget Audio
        // Uses ChatEncryptionService with ECDH key exchange
        // Private key NEVER leaves browser - server CANNOT decrypt
        // NO FALLBACK: If encryption fails, audio is NOT sent
        let blobToUpload = blob;
        let encryptionIv = null;
        let encryptionTag = null;

        try {
            // ENTERPRISE V10.179: Use awaitable singleton promise for proper initialization
            const chatEncryption = await window.chatEncryptionReady;
            if (!chatEncryption || !chatEncryption.isInitialized) {
                throw new Error('ChatEncryptionService initialization failed');
            }

            if (!this.conversationUuid) {
                throw new Error('Conversation UUID required for E2E encryption');
            }

            // TRUE E2E: Encrypt with ECDH-derived conversation key
            const result = await chatEncryption.encryptFile(blob, this.conversationUuid);

            if (!result || !result.encryptedBlob || !result.iv || !result.tag) {
                throw new Error('Encryption returned incomplete result');
            }

            blobToUpload = result.encryptedBlob;
            encryptionIv = result.iv;
            encryptionTag = result.tag;
        } catch (encryptError) {
            console.error('[ChatWidget] E2E encryption failed:', encryptError);
            throw new Error('Crittografia E2E fallita: ' + encryptError.message);
        }

        const formData = new FormData();
        formData.append('audio', blobToUpload, 'recording.webm');
        formData.append('duration', duration.toString());

        // ENTERPRISE V10.100: TRUE E2E encryption metadata
        formData.append('is_encrypted', '1');
        formData.append('encryption_iv', encryptionIv);
        formData.append('encryption_tag', encryptionTag);
        formData.append('encryption_algorithm', 'AES-256-GCM');

        try {
            const response = await fetch(`/api/chat/dm/${this.conversationUuid}/audio`, {
                method: 'POST',
                body: formData,
                credentials: 'include',
                headers: {
                    'X-CSRF-Token': window.Need2Talk?.CSRF?.getToken() ||
                        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                }
            });

            const data = await response.json();

            // ENTERPRISE V10.182: Handle async audio processing (DM Audio Worker)
            // When status='queued', audio is being processed in background
            // The message will appear via WebSocket notification (dm_audio_uploaded)
            if (data.success && data.data?.status === 'queued') {
                this.#showAudioProcessingIndicator();
                return; // Audio will appear via WebSocket when worker completes
            }

            // Sync response with full message object (legacy/fallback)
            if (data.success && data.data?.message && typeof data.data.message === 'object') {
                const msg = data.data.message;
                msg.audio_url = data.data.audio_url;
                msg.duration_seconds = data.data.duration;
                msg.message_type = 'audio';
                msg.sender_uuid = window.APP_USER?.uuid;
                this.addMessage(msg);
            }
        } catch (err) {
            console.error('[ChatWidget] Audio upload failed:', err);
        }
    }

    /**
     * ENTERPRISE V10.74: Audio playback with E2E decryption support
     *
     * For encrypted audio:
     * 1. Fetch encrypted bytes from S3
     * 2. Decrypt with friendship shared key
     * 3. Create Blob URL for playback
     *
     * For unencrypted audio:
     * - Play directly from URL
     */
    async #toggleWidgetAudioPlayback(audioEl) {
        const audioUrl = audioEl.dataset.audioUrl;
        const playBtn = audioEl.querySelector('.chat-widget-audio-play');
        const progressBar = audioEl.querySelector('.chat-widget-audio-progress');
        const timeEl = audioEl.querySelector('.chat-widget-audio-time');
        const isEncrypted = audioEl.dataset.encrypted === 'true';
        const encryptionIv = audioEl.dataset.encryptionIv;
        const encryptionTag = audioEl.dataset.encryptionTag;

        // Stop any playing audio
        if (this.#widgetCurrentAudio) {
            this.#widgetCurrentAudio.pause();
            this.#widgetCurrentAudio = null;
        }

        // If same audio was playing, just stop
        if (audioEl.classList.contains('playing')) {
            audioEl.classList.remove('playing');
            playBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
            return;
        }

        // Remove playing state from all
        this.#messagesEl.querySelectorAll('.chat-widget-audio.playing').forEach(el => {
            el.classList.remove('playing');
            el.querySelector('.chat-widget-audio-play').innerHTML =
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
        });

        // ENTERPRISE V10.100: TRUE E2E Decryption using ChatEncryptionService
        let playableUrl = audioUrl;

        if (isEncrypted && encryptionIv && encryptionTag) {
            try {
                // ENTERPRISE V10.179: Use awaitable singleton promise for proper initialization
                // This prevents race conditions where initialize() is called multiple times
                const chatEncryption = await window.chatEncryptionReady;
                if (!chatEncryption || !chatEncryption.isInitialized) {
                    throw new Error('ChatEncryptionService initialization failed');
                }

                // Show loading state
                playBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="animate-spin"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none" stroke-dasharray="60" stroke-dashoffset="15"/></svg>';
                audioEl.classList.add('loading');

                // Fetch encrypted audio
                const response = await fetch(audioUrl);
                if (!response.ok) {
                    throw new Error(`Failed to fetch audio: ${response.status}`);
                }
                const encryptedBlob = await response.blob();

                // TRUE E2E Decrypt using ECDH-derived conversation key
                const decryptedBlob = await chatEncryption.decryptFile(
                    encryptedBlob,
                    encryptionIv,
                    encryptionTag,
                    this.conversationUuid,
                    'audio/webm'
                );

                // Create Blob URL for playback
                playableUrl = URL.createObjectURL(decryptedBlob);

                // Cache decrypted URL on element for re-play
                audioEl.dataset.decryptedUrl = playableUrl;

            } catch (error) {
                // ENTERPRISE V11.8: Better error logging for E2E debugging
                console.error('[ChatWidget] TRUE E2E audio decryption failed:', {
                    message: error?.message || 'Unknown error',
                    name: error?.name || 'Unknown',
                    conversationUuid: this.conversationUuid,
                    audioUrl: audioUrl,
                    hasIv: !!encryptionIv,
                    hasTag: !!encryptionTag,
                    error: error
                });
                audioEl.classList.remove('loading');
                playBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';

                // Show user-friendly error
                const e2eBadge = audioEl.querySelector('.chat-widget-audio-e2e');
                if (e2eBadge) {
                    e2eBadge.style.color = '#ef4444';
                    e2eBadge.title = `Decryption failed: ${error?.message || 'unknown'}`;
                }
                return;
            }

            audioEl.classList.remove('loading');
        } else if (audioEl.dataset.decryptedUrl) {
            // Use cached decrypted URL
            playableUrl = audioEl.dataset.decryptedUrl;
        }

        // Play this audio
        const audio = new Audio(playableUrl);
        this.#widgetCurrentAudio = audio;
        audioEl.classList.add('playing');
        playBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg>';

        // ENTERPRISE V10.76: Save E2E badge HTML BEFORE playback to preserve it
        // The ontimeupdate handler would otherwise destroy it with textContent
        const e2eBadgeEl = timeEl.querySelector('.chat-widget-audio-e2e');
        const e2eBadgeHtml = e2eBadgeEl ? e2eBadgeEl.outerHTML : '';

        audio.ontimeupdate = () => {
            // ENTERPRISE FIX: Guard against NaN/Infinity before metadata loads
            const duration = audio.duration;
            if (!isFinite(duration) || duration <= 0) {
                return; // Wait for metadata to load
            }
            const pct = (audio.currentTime / duration) * 100;
            progressBar.style.width = pct + '%';
            const remaining = duration - audio.currentTime;
            const m = Math.floor(remaining / 60);
            const s = Math.floor(remaining % 60);
            // ENTERPRISE V10.76: Use innerHTML to preserve E2E badge
            timeEl.innerHTML = `${m}:${s.toString().padStart(2, '0')}${e2eBadgeHtml}`;
        };

        audio.onended = () => {
            audioEl.classList.remove('playing');
            playBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
            progressBar.style.width = '0%';
            // Restore original duration display (preserve E2E badge)
            const originalDuration = parseFloat(audioEl.dataset.duration) || 0;
            const m = Math.floor(originalDuration / 60);
            const s = Math.floor(originalDuration % 60);
            // ENTERPRISE V10.76: Use saved badge HTML
            timeEl.innerHTML = `${m}:${s.toString().padStart(2, '0')}${e2eBadgeHtml}`;
        };

        audio.onerror = () => {
            console.error('[ChatWidget] Audio playback error');
            audioEl.classList.remove('playing');
        };

        audio.play().catch(err => console.error('[ChatWidget] Audio play failed:', err));
    }

    /**
     * Open emoji picker using EmojiData.js
     */
    #openEmojiPicker() {
        // Check if EmojiData is available
        if (typeof EmojiData === 'undefined') {
            console.warn('[ChatWidget] EmojiData not loaded, fallback to simple emojis');
            this.#openSimpleEmojiPicker();
            return;
        }

        // Close existing picker if open
        if (this.#emojiPickerEl) {
            this.#closeEmojiPicker();
            return;
        }

        // Create picker container
        this.#emojiPickerEl = document.createElement('div');
        this.#emojiPickerEl.innerHTML = EmojiData.generatePickerHTML();

        // Append to body (uses fixed positioning)
        document.body.appendChild(this.#emojiPickerEl.firstElementChild);
        this.#emojiPickerEl = document.body.lastElementChild;

        // Initialize picker events
        EmojiData.initPickerEvents(
            this.#emojiPickerEl,
            (emoji) => {
                // Insert emoji into input
                const cursorPos = this.#inputEl.selectionStart;
                const textBefore = this.#inputEl.value.substring(0, cursorPos);
                const textAfter = this.#inputEl.value.substring(cursorPos);
                this.#inputEl.value = textBefore + emoji + textAfter;

                // Trigger input event to update send button
                this.#inputEl.dispatchEvent(new Event('input'));

                // Focus input and set cursor after emoji
                this.#inputEl.focus();
                const newPos = cursorPos + emoji.length;
                this.#inputEl.setSelectionRange(newPos, newPos);
            },
            () => {
                // Close picker
                this.#closeEmojiPicker();
            }
        );
    }

    /**
     * Close emoji picker
     */
    #closeEmojiPicker() {
        if (this.#emojiPickerEl) {
            this.#emojiPickerEl.remove();
            this.#emojiPickerEl = null;
        }
    }

    /**
     * Fallback simple emoji picker if EmojiData not available
     */
    #openSimpleEmojiPicker() {
        const emojis = ['😊', '😂', '❤️', '👍', '🔥', '😍', '🤗', '😢', '🙏', '💪', '😎', '🥳', '😘', '🤔', '👏'];

        // Create simple picker
        const picker = document.createElement('div');
        picker.className = 'chat-widget-simple-emoji-picker';
        picker.style.cssText = `
            position: absolute;
            bottom: 100%;
            left: 0;
            margin-bottom: 8px;
            background: rgba(31, 41, 55, 0.98);
            border: 1px solid rgba(75, 85, 99, 0.5);
            border-radius: 12px;
            padding: 8px;
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 4px;
            z-index: 10000;
        `;

        emojis.forEach(emoji => {
            const btn = document.createElement('button');
            btn.textContent = emoji;
            btn.style.cssText = `
                font-size: 20px;
                padding: 6px;
                border: none;
                background: transparent;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.15s;
            `;
            btn.addEventListener('mouseenter', () => {
                btn.style.background = 'rgba(139, 92, 246, 0.2)';
                btn.style.transform = 'scale(1.15)';
            });
            btn.addEventListener('mouseleave', () => {
                btn.style.background = 'transparent';
                btn.style.transform = 'scale(1)';
            });
            btn.addEventListener('click', () => {
                this.#inputEl.value += emoji;
                this.#inputEl.dispatchEvent(new Event('input'));
                this.#inputEl.focus();
                picker.remove();
            });
            picker.appendChild(btn);
        });

        // Position relative to footer
        const footer = this.#element.querySelector('.chat-widget-footer');
        footer.style.position = 'relative';
        footer.appendChild(picker);

        // Close on outside click
        const closeHandler = (e) => {
            if (!picker.contains(e.target) && !e.target.closest('.chat-widget-emoji-btn')) {
                picker.remove();
                document.removeEventListener('click', closeHandler);
            }
        };
        setTimeout(() => document.addEventListener('click', closeHandler), 0);
    }

    async #loadMessages() {
        try {
            const response = await fetch(`/api/chat/dm/${this.conversationUuid}/messages?limit=30`);
            const data = await response.json();

            if (data.success && data.data?.messages) {
                this.#messages = data.data.messages.reverse();
                await this.#renderMessages();
            }

            // ENTERPRISE V10.59: Use ReadReceiptManager for visibility-aware read receipts
            // CRITICAL: Pass !this.isMinimized to prevent marking as read when widget is minimized
            // This is the FIX for messages being marked as read on page navigation
            if (window.readReceiptManager) {
                window.readReceiptManager.onConversationOpened(this.conversationUuid, !this.isMinimized);
            } else if (!this.isMinimized && document.hasFocus() && document.visibilityState === 'visible') {
                // Fallback: only mark if not minimized AND page has focus
                fetch(`/api/chat/dm/${this.conversationUuid}/read`, { method: 'POST' })
                    .catch(e => console.warn('[ChatWidget] Failed to mark as read:', e));
            }

        } catch (e) {
            console.error('[ChatWidget] Failed to load messages:', e);
            this.#messagesEl.innerHTML = `
                <div class="chat-widget-error">
                    Errore nel caricamento dei messaggi
                </div>
            `;
        }
    }

    async #renderMessages() {
        const currentUserUuid = window.APP_USER?.uuid;

        if (this.#messages.length === 0) {
            this.#messagesEl.innerHTML = `
                <div class="chat-widget-empty">
                    <p>Inizia la conversazione!</p>
                </div>
            `;
            return;
        }

        // ENTERPRISE V11.6: Get encryption service once for all messages
        let chatEncryption = null;
        try {
            chatEncryption = await window.chatEncryptionReady;
        } catch (e) {
            console.warn('[ChatWidget] Encryption service not available for rendering');
        }

        let html = '';
        for (const msg of this.#messages) {
            const isOwn = msg.sender_uuid === currentUserUuid;

            // ENTERPRISE V11.6: TRUE E2E decryption
            let content = msg.content;
            if (!content && msg.message_type === 'text') {
                try {
                    const encrypted = msg.encrypted;
                    if (encrypted?.ciphertext && encrypted?.iv && encrypted?.tag && chatEncryption?.isInitialized) {
                        // Real E2E decryption with AES-256-GCM
                        content = await chatEncryption.decryptMessage(
                            encrypted.ciphertext,
                            encrypted.iv,
                            encrypted.tag,
                            this.conversationUuid
                        );
                    } else if (msg.encrypted_content) {
                        // Fallback for legacy non-E2E messages
                        content = Need2Talk.utils.base64ToUtf8(msg.encrypted_content);
                    } else if (encrypted?.ciphertext) {
                        // E2E message but encryption not ready
                        content = '[Caricamento crittografia...]';
                    } else {
                        content = '[Messaggio crittografato]';
                    }
                } catch (e) {
                    console.error('[ChatWidget] Decryption failed:', e.message);
                    // ENTERPRISE V11.9: User-friendly message for decryption failure
                    content = '🔒 Messaggio non decifrabile';
                }
            }

            // ENTERPRISE V10.0: Show avatar for ALL messages (both own and other)
            // For own messages: use APP_USER.avatar
            // For other messages: use otherUser.avatar_url
            const avatarUrl = isOwn
                ? (window.APP_USER?.avatar || '/assets/img/default-avatar.png')
                : (this.otherUser.avatar_url || '/assets/img/default-avatar.png');

            // ENTERPRISE V10.40: Status indicator for own messages (inviato/letto)
            const statusHtml = isOwn ? this.#renderMessageStatus(msg) : '';

            // ENTERPRISE V3.1: Handle audio messages
            const isAudio = msg.message_type === 'audio';
            const bubbleContent = isAudio
                ? this.#renderWidgetAudioPlayer(msg)
                : this.#escapeHtml(content || '');

            html += `
                <div class="chat-widget-message ${isOwn ? 'chat-widget-message--own' : ''}" data-message-uuid="${msg.uuid || ''}">
                    <img class="chat-widget-message-avatar"
                         src="${avatarUrl}"
                         alt=""
                         onerror="this.src='/assets/img/default-avatar.png'">
                    <div class="chat-widget-message-content">
                        <div class="chat-widget-message-bubble${isAudio ? ' chat-widget-audio-bubble' : ''}">
                            ${bubbleContent}
                        </div>
                        ${statusHtml}
                    </div>
                </div>
            `;
        }

        this.#messagesEl.innerHTML = html;
        this.#scrollToBottom();
    }

    async addMessage(message) {
        // ENTERPRISE V10.182: Remove processing indicator when audio message arrives
        if (message.message_type === 'audio') {
            const processingIndicator = this.#messagesEl?.querySelector('.chat-widget-audio-processing');
            if (processingIndicator) {
                processingIndicator.remove();
            }
        }

        // ENTERPRISE V11.6: TRUE E2E decryption using ChatEncryptionService
        let content = message.content;
        if (!content && message.message_type === 'text') {
            try {
                const encrypted = message.encrypted;
                if (encrypted?.ciphertext && encrypted?.iv && encrypted?.tag) {
                    // Real E2E decryption with AES-256-GCM
                    const chatEncryption = await window.chatEncryptionReady;
                    if (chatEncryption && chatEncryption.isInitialized) {
                        content = await chatEncryption.decryptMessage(
                            encrypted.ciphertext,
                            encrypted.iv,
                            encrypted.tag,
                            this.conversationUuid
                        );
                    } else {
                        content = '[Crittografia non disponibile]';
                    }
                } else if (message.encrypted_content) {
                    // Fallback for legacy non-E2E messages (base64 only)
                    content = Need2Talk.utils.base64ToUtf8(message.encrypted_content);
                } else {
                    content = '[Messaggio crittografato]';
                }
            } catch (e) {
                console.error('[ChatWidget] Decryption failed:', e.message);
                // ENTERPRISE V11.9: User-friendly message for decryption failure
                content = '🔒 Messaggio non decifrabile';
            }
        }
        message.content = content;

        this.#messages.push(message);

        const isOwn = message.sender_uuid === window.APP_USER?.uuid;

        // ENTERPRISE V10.0: Show avatar for ALL messages
        const avatarUrl = isOwn
            ? (window.APP_USER?.avatar || '/assets/img/default-avatar.png')
            : (this.otherUser.avatar_url || '/assets/img/default-avatar.png');

        // ENTERPRISE V10.40: Status indicator for own messages (inviato/letto)
        const statusHtml = isOwn ? this.#renderMessageStatus(message) : '';

        // ENTERPRISE V3.1: Handle audio messages
        const isAudio = message.message_type === 'audio';
        const bubbleContent = isAudio
            ? this.#renderWidgetAudioPlayer(message)
            : this.#escapeHtml(content);

        const msgEl = document.createElement('div');
        msgEl.className = `chat-widget-message ${isOwn ? 'chat-widget-message--own' : ''}`;
        msgEl.dataset.messageUuid = message.uuid || '';
        msgEl.innerHTML = `
            <img class="chat-widget-message-avatar"
                 src="${avatarUrl}"
                 alt=""
                 onerror="this.src='/assets/img/default-avatar.png'">
            <div class="chat-widget-message-content">
                <div class="chat-widget-message-bubble${isAudio ? ' chat-widget-audio-bubble' : ''}">
                    ${bubbleContent}
                </div>
                ${statusHtml}
            </div>
        `;

        // Remove empty state if present
        const emptyEl = this.#messagesEl.querySelector('.chat-widget-empty');
        if (emptyEl) emptyEl.remove();

        this.#messagesEl.appendChild(msgEl);
        this.#scrollToBottom();

        // Play sound if not own message and widget is not focused
        if (!isOwn && !document.hasFocus()) {
            this.#playSound();
        }

        // ENTERPRISE V10.42: Use ReadReceiptManager for accurate visibility-based read receipts
        // Only marks as read if user is ACTUALLY viewing (page visible + focused + widget open)
        if (!isOwn) {
            if (window.readReceiptManager) {
                window.readReceiptManager.onMessageReceived(
                    this.conversationUuid,
                    message.uuid,
                    !this.isMinimized  // isWidgetOpen = not minimized
                );
            } else {
                // Fallback: only mark if not minimized AND page has focus
                if (!this.isMinimized && document.hasFocus() && document.visibilityState === 'visible') {
                    fetch(`/api/chat/dm/${this.conversationUuid}/read`, { method: 'POST' })
                        .catch(e => console.warn('[ChatWidget] Failed to mark as read:', e));
                }
            }
        }
    }

    async #sendMessage() {
        const content = this.#inputEl.value.trim();
        if (!content) return;

        // Clear input immediately for responsiveness
        this.#inputEl.value = '';
        this.#inputEl.style.height = 'auto';
        this.#element.querySelector('.chat-widget-send-btn').disabled = true;

        // Stop typing indicator
        this.#sendTypingIndicator(false);

        try {
            // ENTERPRISE V11.6: TRUE E2E encryption using ChatEncryptionService
            let encryptedContent, contentIv, contentTag;

            try {
                // Wait for encryption service to be ready (awaitable promise)
                const chatEncryption = await window.chatEncryptionReady;
                // isInitialized is a GETTER, not a method!
                if (chatEncryption && chatEncryption.isInitialized) {
                    // Encrypt message with AES-256-GCM via ECDH-derived key
                    const encrypted = await chatEncryption.encryptMessage(content, this.conversationUuid);
                    encryptedContent = encrypted.ciphertext;
                    contentIv = encrypted.iv;
                    contentTag = encrypted.tag;
                } else {
                    throw new Error('Encryption service not initialized');
                }
            } catch (encryptError) {
                // Encryption failed - fallback to base64 (NOT secure, but allows messaging)
                console.error('[ChatWidget] E2E encryption failed:', encryptError.message);
                encryptedContent = Need2Talk.utils.utf8ToBase64(content);
                contentIv = btoa('0000000000000000');
                contentTag = btoa('0000000000000000');
            }

            const response = await fetch(`/api/chat/dm/${this.conversationUuid}/messages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    encrypted_content: encryptedContent,
                    content_iv: contentIv,
                    content_tag: contentTag,
                    message_type: 'text'
                })
            });

            const data = await response.json();

            // ENTERPRISE V10.145: Handle DND blocking
            if (data.blocked && data.reason === 'dnd') {
                // Message was blocked because recipient is DND and this isn't the first message
                this.#showDndBlocked();
                // Restore content so user doesn't lose their message
                this.#inputEl.value = content;
                this.#element.querySelector('.chat-widget-send-btn').disabled = false;
                return;
            }

            if (data.success && data.data?.message) {
                const msg = data.data.message;
                msg.content = content;
                msg.sender_uuid = window.APP_USER?.uuid;
                this.addMessage(msg);

                // ENTERPRISE V10.145: Show DND warning if recipient is busy (first message allowed)
                if (data.data.recipient_dnd) {
                    this.#showDndWarning();
                }

                // ENTERPRISE V10.7: Update UI if sender's status changed
                const newStatus = data.data.sender_status;
                if (newStatus) {
                    window.dispatchEvent(new CustomEvent('n2t:ownStatusChanged', {
                        detail: { status: newStatus, reason: 'message_sent' }
                    }));
                }
            } else if (!data.success) {
                // Generic error - restore content
                this.#inputEl.value = content;
                this.#element.querySelector('.chat-widget-send-btn').disabled = false;
            }

        } catch (e) {
            console.error('[ChatWidget] Send failed:', e);
            // Restore content on error
            this.#inputEl.value = content;
            this.#element.querySelector('.chat-widget-send-btn').disabled = false;
        }
    }

    // =========================================================================
    // ENTERPRISE DND BLOCKING HELPERS
    // =========================================================================

    /**
     * Get localStorage key for DND cooldown
     */
    #getDndCooldownKey() {
        return `dnd_cooldown_${this.conversationUuid}`;
    }

    /**
     * Check if currently in DND cooldown
     */
    #isDndBlocked() {
        const expiry = localStorage.getItem(this.#getDndCooldownKey());
        if (!expiry) return false;
        return Date.now() < parseInt(expiry, 10);
    }

    /**
     * Get remaining cooldown time in seconds
     */
    #getDndCooldownRemaining() {
        const expiry = localStorage.getItem(this.#getDndCooldownKey());
        if (!expiry) return 0;
        const remaining = parseInt(expiry, 10) - Date.now();
        return remaining > 0 ? Math.ceil(remaining / 1000) : 0;
    }

    /**
     * Set 30 minute DND cooldown
     */
    #setDndCooldown() {
        const DND_COOLDOWN_MS = 30 * 60 * 1000; // 30 minutes
        const expiry = Date.now() + DND_COOLDOWN_MS;
        localStorage.setItem(this.#getDndCooldownKey(), expiry.toString());
    }

    /**
     * Clear DND cooldown (called when user comes back online)
     */
    #clearDndCooldown() {
        localStorage.removeItem(this.#getDndCooldownKey());
    }

    /**
     * ENTERPRISE V10.145: Show DND warning to sender (first message allowed)
     * Displays a visible message that the recipient is busy but message was sent
     */
    #showDndWarning() {
        const name = this.otherUser?.nickname || 'L\'utente';
        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-widget-system-message chat-widget-dnd-warning';
        msgDiv.innerHTML = `
            <div class="chat-widget-dnd-bubble">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span><strong>${this.#escapeHtml(name)}</strong> è occupato/a. Il messaggio è stato inviato, ma non potrai inviarne altri finché non cambia stato.</span>
            </div>
        `;
        this.#messagesEl.appendChild(msgDiv);
        this.#scrollToBottom();
    }

    /**
     * ENTERPRISE V10.145: Show DND blocked message (subsequent messages blocked)
     * Displays a visible error that the message was NOT sent
     */
    #showDndBlocked() {
        const name = this.otherUser?.nickname || 'L\'utente';
        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-widget-system-message chat-widget-dnd-blocked';
        msgDiv.innerHTML = `
            <div class="chat-widget-dnd-blocked-bubble">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                </svg>
                <span><strong>${this.#escapeHtml(name)}</strong> è ancora occupato/a. Il messaggio NON è stato inviato. Riprova quando cambia stato.</span>
            </div>
        `;
        this.#messagesEl.appendChild(msgDiv);
        this.#scrollToBottom();

        // Auto-remove after 8 seconds
        setTimeout(() => {
            msgDiv.classList.add('fade-out');
            setTimeout(() => msgDiv.remove(), 300);
        }, 8000);
    }

    /**
     * Show a system message in the chat (not sent to server)
     */
    #showSystemMessage(text) {
        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-widget-system-message';
        msgDiv.innerHTML = `
            <div class="chat-widget-system-bubble">
                ${this.#escapeHtml(text)}
            </div>
        `;
        this.#messagesEl.appendChild(msgDiv);
        this.#scrollToBottom();
    }

    /**
     * ENTERPRISE V10.182: Show audio processing indicator
     * Displayed when audio is queued for async processing by DM Audio Worker
     * This indicator will be replaced when WebSocket notification arrives
     */
    #showAudioProcessingIndicator() {
        // Remove any existing processing indicator
        const existingIndicator = this.#messagesEl?.querySelector('.chat-widget-audio-processing');
        if (existingIndicator) {
            existingIndicator.remove();
        }

        const indicatorDiv = document.createElement('div');
        indicatorDiv.className = 'chat-widget-audio-processing chat-widget-message sent';
        indicatorDiv.innerHTML = `
            <div class="chat-widget-bubble sent" style="display: flex; align-items: center; gap: 8px; opacity: 0.7;">
                <svg class="animate-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                </svg>
                <span style="font-size: 12px;">Audio in elaborazione...</span>
            </div>
        `;
        this.#messagesEl?.appendChild(indicatorDiv);
        this.#scrollToBottom();

        // Auto-remove after 30 seconds (failsafe if WebSocket doesn't arrive)
        setTimeout(() => {
            indicatorDiv.remove();
        }, 30000);
    }

    async #sendTypingIndicator(isTyping) {
        try {
            await fetch(`/api/chat/dm/${this.conversationUuid}/typing`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ is_typing: isTyping })
            });
        } catch (e) {
            // Ignore
        }
    }

    showTyping(isTyping, userName) {
        if (isTyping) {
            this.#typingEl.innerHTML = `
                <div class="chat-widget-typing-indicator">
                    <span class="chat-widget-typing-dot"></span>
                    <span class="chat-widget-typing-dot"></span>
                    <span class="chat-widget-typing-dot"></span>
                </div>
            `;
        } else {
            this.#typingEl.innerHTML = '';
        }
    }

    /**
     * ENTERPRISE V10.8: Update presence status of the other user
     * Called when WebSocket delivers friend_presence_changed event
     *
     * @param {string} newStatus - New status ('online', 'busy', 'dnd', 'away', 'offline')
     */
    updatePresence(newStatus) {
        // ENTERPRISE V10.38: Normalize status if it's an object
        let normalizedStatus = newStatus;
        let lastSeen = null;
        if (typeof newStatus === 'object' && newStatus !== null) {
            normalizedStatus = newStatus.status || 'online';
            // ENTERPRISE V10.200: Preserve last_seen from presence object
            lastSeen = newStatus.last_seen || null;
        }

        this.otherUser.status = normalizedStatus;

        // ENTERPRISE V10.200: Update last_seen if provided, otherwise preserve existing
        if (lastSeen) {
            this.otherUser.last_seen = lastSeen;
        }
        // If going online, clear last_seen (not needed)
        if (normalizedStatus === 'online') {
            this.otherUser.last_seen = null;
        }

        // Update status indicator dot in header
        const statusDot = this.#element?.querySelector('.chat-widget-status');
        if (statusDot) {
            // Remove all status modifier classes
            statusDot.classList.remove(
                'chat-widget-status--online',
                'chat-widget-status--busy',
                'chat-widget-status--away',
                'chat-widget-status--offline'
            );
            // Add new status class
            statusDot.classList.add(this.#getStatusColor(normalizedStatus));
        }

        // Update status text
        const statusText = this.#element?.querySelector('.chat-widget-user-status');
        if (statusText) {
            statusText.textContent = this.#getStatusText();
        }
    }

    #scrollToBottom() {
        this.#messagesEl.scrollTop = this.#messagesEl.scrollHeight;
    }

    /**
     * ENTERPRISE V10.40: Render message status indicator (inviato/letto)
     * Only for own messages
     *
     * @param {Object} msg - Message object with is_read flag
     * @returns {string} HTML for status indicator
     */
    /**
     * ENTERPRISE V3.1: Render compact audio player for widget
     * ENTERPRISE V10.72: Added E2E encryption badge
     */
    #renderWidgetAudioPlayer(msg) {
        const audioUrl = msg.audio_url;
        const duration = msg.duration_seconds || 0;

        // ENTERPRISE V10.100: TRUE E2E encryption metadata (iv + tag)
        const isE2EEncrypted = msg.audio_is_encrypted === true;
        const encryptionIv = msg.audio_encryption_iv || null;
        const encryptionTag = msg.audio_encryption_tag || null;

        if (!audioUrl) {
            return '<span style="color:#999;font-size:12px;">🎤 Audio non disponibile</span>';
        }

        const formatTime = (s) => {
            const m = Math.floor(s / 60);
            const sec = Math.floor(s % 60);
            return `${m}:${sec.toString().padStart(2, '0')}`;
        };

        // ENTERPRISE V10.100: E2E badge for encrypted audio
        const e2eBadge = isE2EEncrypted ? `
            <span class="chat-widget-audio-e2e" title="Audio crittografato end-to-end">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </span>
        ` : '';

        // Data attributes for TRUE E2E decryption (iv + tag required)
        let dataAttrs = `data-audio-url="${audioUrl}" data-duration="${duration}"`;
        if (isE2EEncrypted && encryptionIv && encryptionTag) {
            dataAttrs += ` data-encrypted="true" data-encryption-iv="${encryptionIv}" data-encryption-tag="${encryptionTag}"`;
        }

        return `
            <div class="chat-widget-audio" ${dataAttrs}>
                <button class="chat-widget-audio-play" title="Riproduci">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                </button>
                <div class="chat-widget-audio-wave">
                    <div class="chat-widget-audio-progress"></div>
                </div>
                <span class="chat-widget-audio-time">${formatTime(duration)}${e2eBadge}</span>
            </div>
        `;
    }

    #renderMessageStatus(msg) {
        const isRead = msg.is_read === true || msg.is_read === 1 || msg.read_at;
        const statusClass = isRead ? 'chat-widget-message-status--read' : 'chat-widget-message-status--sent';
        const statusText = isRead ? 'Letto' : 'Inviato';
        const checkIcon = isRead
            ? `<svg class="chat-widget-status-icon" viewBox="0 0 16 16" fill="currentColor">
                   <path d="M12.354 4.354a.5.5 0 0 0-.708-.708L5 10.293 1.854 7.146a.5.5 0 1 0-.708.708l3.5 3.5a.5.5 0 0 0 .708 0l7-7z"/>
                   <path d="M10.354 4.354a.5.5 0 0 0-.708-.708L5 8.293 3.854 7.146a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0l5-5z" transform="translate(4, 0)"/>
               </svg>`
            : `<svg class="chat-widget-status-icon" viewBox="0 0 16 16" fill="currentColor">
                   <path d="M12.354 4.354a.5.5 0 0 0-.708-.708L5 10.293 1.854 7.146a.5.5 0 1 0-.708.708l3.5 3.5a.5.5 0 0 0 .708 0l7-7z"/>
               </svg>`;

        return `<div class="chat-widget-message-status ${statusClass}">${checkIcon}<span>${statusText}</span></div>`;
    }

    /**
     * ENTERPRISE V10.40: Update all own messages in this conversation as "read"
     * Called when dm_read_receipt WebSocket event is received
     */
    markAllMessagesAsRead() {
        const currentUserUuid = window.APP_USER?.uuid;

        // Update internal message state
        for (const msg of this.#messages) {
            if (msg.sender_uuid === currentUserUuid) {
                msg.is_read = true;
                msg.read_at = Date.now();
            }
        }

        // Update DOM
        const ownMessages = this.#messagesEl.querySelectorAll('.chat-widget-message--own');
        ownMessages.forEach(msgEl => {
            const statusEl = msgEl.querySelector('.chat-widget-message-status');
            if (statusEl && !statusEl.classList.contains('chat-widget-message-status--read')) {
                statusEl.classList.remove('chat-widget-message-status--sent');
                statusEl.classList.add('chat-widget-message-status--read');

                // Update icon to double check
                const iconEl = statusEl.querySelector('.chat-widget-status-icon');
                if (iconEl) {
                    iconEl.innerHTML = `
                        <path d="M12.354 4.354a.5.5 0 0 0-.708-.708L5 10.293 1.854 7.146a.5.5 0 1 0-.708.708l3.5 3.5a.5.5 0 0 0 .708 0l7-7z"/>
                        <path d="M10.354 4.354a.5.5 0 0 0-.708-.708L5 8.293 3.854 7.146a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0l5-5z" transform="translate(4, 0)"/>
                    `;
                }

                // Update text
                const textEl = statusEl.querySelector('span');
                if (textEl) {
                    textEl.textContent = 'Letto';
                }
            }
        });
    }

    #escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    #playSound() {
        try {
            const audio = new Audio('/assets/sounds/message.mp3');
            audio.volume = 0.3;
            audio.play().catch(() => {});
        } catch (e) {}
    }

    #getStatusText() {
        switch (this.otherUser.status) {
            case 'online': return 'Attivo ora';
            case 'busy':
            case 'dnd': return 'Occupato';
            case 'away': return 'Assente';
            default:
                // ENTERPRISE V10.200: Show last seen for offline users
                if (this.otherUser.last_seen) {
                    return `Ultimo accesso: ${this.otherUser.last_seen}`;
                }
                return 'Offline';
        }
    }

    /**
     * Get CSS class for status color (widget header)
     * Uses BEM modifier classes defined in chat-widget.css
     */
    #getStatusColor(status) {
        switch (status) {
            case 'online':
                return 'chat-widget-status--online';
            case 'busy':
            case 'dnd':
                return 'chat-widget-status--busy';
            case 'away':
                return 'chat-widget-status--away';
            default:
                return 'chat-widget-status--offline';
        }
    }

    // Public methods

    setPosition({ right, bottom }) {
        this.#position = { right, bottom };
        this.#element.style.right = `${right}px`;
        this.#element.style.bottom = `${bottom}px`;
    }

    setZIndex(z) {
        this.#zIndex = z;
        this.#element.style.zIndex = z;
    }

    focus() {
        this.#manager.focusWidget(this.conversationUuid);
        this.#inputEl.focus();
    }

    minimize() {
        this.isMinimized = true;
        this.#element.classList.add('chat-widget--minimized');
    }

    maximize() {
        this.isMinimized = false;
        this.unreadCount = 0;
        this.#element.classList.remove('chat-widget--minimized');
        this.#scrollToBottom();
        this.#inputEl.focus();

        // ENTERPRISE V10.59: Mark messages as read when maximizing
        // Widget is being opened by user, so isWidgetOpen = true
        if (window.readReceiptManager) {
            window.readReceiptManager.onConversationOpened(this.conversationUuid, true);
        } else if (document.hasFocus() && document.visibilityState === 'visible') {
            fetch(`/api/chat/dm/${this.conversationUuid}/read`, { method: 'POST' })
                .catch(e => console.warn('[ChatWidget] Failed to mark as read on maximize:', e));
        }
    }

    incrementUnread() {
        this.unreadCount++;
    }

    /**
     * Update user status (called from WebSocket presence events)
     */
    updateStatus(status) {
        // ENTERPRISE V10.38: Normalize status if it's an object
        let normalizedStatus = status;
        if (typeof status === 'object' && status !== null) {
            normalizedStatus = status.status || 'online';
        }

        const previousStatus = this.otherUser.status;
        this.otherUser.status = normalizedStatus;

        // ENTERPRISE DND: Clear cooldown if user comes back online from DND/busy
        if ((previousStatus === 'dnd' || previousStatus === 'busy') &&
            (normalizedStatus === 'online' || normalizedStatus === 'away')) {
            this.#clearDndCooldown();
            this.#showSystemMessage(
                `✅ ${this.otherUser.nickname} è di nuovo disponibile. Puoi inviargli un messaggio.`
            );
        }

        // Update status indicator
        const statusEl = this.#element.querySelector('.chat-widget-status');
        if (statusEl) {
            statusEl.className = `chat-widget-status ${this.#getStatusColor(normalizedStatus)}`;
        }

        // Update status text
        const statusTextEl = this.#element.querySelector('.chat-widget-user-status');
        if (statusTextEl) {
            statusTextEl.textContent = this.#getStatusText();
        }
    }

    destroy() {
        // Close emoji picker if open
        this.#closeEmojiPicker();
        this.#element.remove();
    }
}

// ENTERPRISE V11.8: Initialize when DOM is ready OR immediately if already loaded
// With defer scripts, DOMContentLoaded may have already fired
function initChatWidgetManager() {
    // Small delay to ensure WebSocketManager is fully initialized
    // This fixes the ".on() method not found" race condition
    setTimeout(() => {
        window.chatWidgetManager = ChatWidgetManager.getInstance();
    }, 100);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initChatWidgetManager);
} else {
    // DOM already ready, initialize now
    initChatWidgetManager();
}

// Export for manual access
window.ChatWidgetManager = ChatWidgetManager;
