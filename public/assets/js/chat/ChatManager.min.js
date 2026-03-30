/**
 * ChatManager.js - Enterprise Galaxy Chat Orchestrator
 *
 * Main controller for the chat system. Manages:
 * - WebSocket connection for real-time messaging
 * - Emotion rooms and user rooms
 * - Direct messages with E2E encryption
 * - Presence and typing indicators
 * - Message queue for offline resilience
 *
 * ARCHITECTURE:
 * - Singleton pattern for global state
 * - Event-driven with CustomEvent dispatch
 * - Graceful degradation (works without WebSocket)
 * - Memory-efficient message virtualization
 *
 * USAGE:
 * const chat = ChatManager.getInstance();
 * await chat.initialize();
 * chat.joinRoom('emotion:joy');
 * chat.sendMessage('Hello!');
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @version 1.0.0
 */

class ChatManager {
    static instance = null;

    // Configuration
    static CONFIG = {
        API_BASE: '/api/chat',
        HEARTBEAT_INTERVAL: 30000,      // 30 seconds
        TYPING_TIMEOUT: 3000,            // 3 seconds
        RECONNECT_DELAY: 1000,           // Start with 1 second
        RECONNECT_MAX_DELAY: 30000,      // Max 30 seconds
        MESSAGE_BATCH_SIZE: 50,          // Messages per page
        MAX_CACHED_MESSAGES: 200,        // Per room/conversation
        VIRTUAL_SCROLL_BUFFER: 20,       // Extra items for smooth scroll
    };

    // State
    #initialized = false;
    #currentRoom = null;
    #currentConversation = null;
    #messages = new Map();              // roomId/convId -> messages[]
    #onlineUsers = new Map();           // roomId -> Set<uuid>
    #typingUsers = new Map();           // roomId/convId -> Map<uuid, timeout>
    #pendingMessages = [];              // Offline queue
    #messageListeners = new Map();      // event -> Set<callback>
    #heartbeatTimer = null;
    #typingTimer = null;
    #reconnectAttempts = 0;

    // Services (lazy loaded)
    #encryption = null;
    #websocket = null;

    constructor() {
        if (ChatManager.instance) {
            return ChatManager.instance;
        }
        ChatManager.instance = this;
        // Note: Private methods are bound via arrow function properties instead
    }

    /**
     * Get singleton instance
     * @returns {ChatManager}
     */
    static getInstance() {
        if (!ChatManager.instance) {
            ChatManager.instance = new ChatManager();
        }
        return ChatManager.instance;
    }

    /**
     * Initialize chat system
     * @returns {Promise<boolean>}
     */
    async initialize() {
        if (this.#initialized) {
            return true;
        }

        try {

            // Check if user is authenticated
            if (!window.APP_USER?.uuid) {
                console.warn('[ChatManager] User not authenticated, chat disabled');
                return false;
            }

            // ENTERPRISE V10.179: Use global singleton for encryption
            // CRITICAL: All components MUST use the same instance to avoid key mismatches
            if (window.chatEncryptionReady) {
                try {
                    this.#encryption = await window.chatEncryptionReady;
                } catch (encError) {
                    console.error('[ChatManager] Encryption initialization failed:', encError);
                    this.#encryption = null;
                }
            }

            // Get WebSocket manager (may exist on Need2Talk namespace or as global)
            this.#connectWebSocket();

            // ENTERPRISE V10.31 (2025-12-04): Removed websocket:ready listener - that event never existed!
            // WebSocketManager now buffers messages until handlers are registered, eliminating race conditions.
            // The n2t:wsConnected event (below) handles WebSocket reconnection scenarios.
            if (!this.#websocket) {
                // Check again after a short delay (WebSocket may initialize after ChatManager)
                setTimeout(() => this.#connectWebSocket(), 500);
            }

            // Setup event listeners
            document.addEventListener('visibilitychange', this.#handleVisibilityChange);
            window.addEventListener('beforeunload', this.#handleBeforeUnload);

            // ENTERPRISE V10.21: Auto-rejoin room after WebSocket (re)connect
            // Handles THREE critical scenarios:
            // 1. User joined room via HTTP before WebSocket connected → join_room never sent
            // 2. WebSocket disconnected and reconnected → user removed from server's fdToRoom
            // 3. ChatManager initialized AFTER WebSocket already connected → missed the event
            // In all cases, we must send join_room to ensure real-time message delivery
            window.addEventListener('n2t:wsConnected', (e) => {
                if (this.#currentRoom && this.#websocket?.isConnected) {
                    // Send join_room to server to establish/restore room membership
                    this.#websocket.send({
                        type: 'join_room',
                        room_id: this.#currentRoom,
                        room_type: this.#currentRoom.startsWith('emotion:') ? 'emotion' : 'user'
                    });
                }
            });

            // ENTERPRISE V10.78: Cross-view unread badge synchronization
            // When ReadReceiptManager marks a conversation as read (from any view: widget or extended),
            // sync the sidebar unread badge to show 0
            window.addEventListener('n2t:conversationMarkedRead', (e) => {
                const { conversationUuid } = e.detail;
                this.#updateConversationUnreadBadge(conversationUuid, 0);
            });

            // ENTERPRISE V10.22: Handle case where WebSocket was already connected before ChatManager initialized

            // Start heartbeat
            this.#startHeartbeat();

            // Load all data in parallel for enterprise performance
            // Using Promise.allSettled to ensure one failure doesn't block others
            const loadPromises = await Promise.allSettled([
                this.loadEmotionRooms(),
                this.loadUserRooms(),
                this.loadDMInbox()
            ]);

            this.#initialized = true;

            // Dispatch ready event
            this.#dispatch('chat:ready', { manager: this });

            // NOTE: URL room parameter handling moved to public method checkUrlRoomParameter()
            // Must be called AFTER EmotionRoomSelector is initialized (see chat/index.php)

            return true;

        } catch (error) {
            console.error('[ChatManager] Initialization failed:', error);
            return false;
        }
    }

    /**
     * Connect to WebSocket manager (handles late initialization)
     */
    #connectWebSocket() {
        if (this.#websocket) return; // Already connected

        // Try multiple sources for WebSocket manager
        const wsManager = window.Need2Talk?.WebSocketManager
            || window.WebSocketManager
            || window.wsManager;

        if (wsManager) {
            this.#websocket = wsManager;
            this.#setupWebSocketHandlers();
        }
    }

    /**
     * Setup WebSocket message handlers
     * Enterprise Galaxy: Graceful degradation if WebSocket interface differs
     *
     * ENTERPRISE V9.0 (2025-12-02): Defensive function type checking
     * Optional chaining with .bind() fails if property exists but isn't a function
     * Now explicitly checks typeof before calling bind()
     */
    #setupWebSocketHandlers() {
        if (!this.#websocket) return;

        // ENTERPRISE FIX: Explicit function type check before .bind()
        // The previous code: this.#websocket.on?.bind(this.#websocket)
        // fails if .on exists but is not a function (throws "is not a function")
        let registerHandler = null;

        if (typeof this.#websocket.on === 'function') {
            registerHandler = this.#websocket.on.bind(this.#websocket);
        } else if (typeof this.#websocket.onMessage === 'function') {
            registerHandler = this.#websocket.onMessage.bind(this.#websocket);
        }

        if (!registerHandler) {
            return;
        }

        // Register chat message types
        const messageTypes = [
            'room_joined', 'room_left', 'room_message', 'message_deleted',
            'user_joined', 'user_left',
            'dm_received', 'dm_sent', 'dm_read_receipt',
            'dm_audio_uploaded',  // ENTERPRISE V4.3: Async audio upload completed
            'typing_indicator', 'presence_update',
            'room_announcement', 'kicked_from_room',
            'moderation_action', 'error',
            'dnd_missed_message',  // ENTERPRISE V10.4: DND notification
            'emotion_counter_update',  // ENTERPRISE V10.17: Real-time emotion room counters
            'user_room_counter_update'  // ENTERPRISE V10.50: Real-time user room counters
        ];

        messageTypes.forEach(type => {
            registerHandler(type, (data) => this.#handleWebSocketMessage({ type, ...data }));
        });
    }

    /**
     * Handle incoming WebSocket messages (arrow function for correct this binding)
     * @param {Object} message
     */
    #handleWebSocketMessage = (message) => {
        const { type, ...data } = message;

        switch (type) {
            case 'room_joined':
                this.#handleRoomJoined(data);
                break;

            case 'room_left':
                this.#handleRoomLeft(data);
                break;

            case 'room_message':
                this.#handleRoomMessage(data);
                break;

            case 'message_deleted':
                this.#handleMessageDeleted(data);
                break;

            case 'user_joined':
                this.#handleUserJoined(data);
                break;

            case 'user_left':
                this.#handleUserLeft(data);
                break;

            case 'dm_received':
                this.#handleDMReceived(data);
                break;

            case 'dm_sent':
                this.#handleDMSent(data);
                break;

            case 'dm_read_receipt':
                this.#handleReadReceipt(data);
                break;

            // ENTERPRISE V4.3: Async audio upload completed (sender notification)
            case 'dm_audio_uploaded':
                this.#handleDMAudioUploaded(data);
                break;

            case 'typing_indicator':
                this.#handleTypingIndicator(data);
                break;

            case 'presence_update':
                this.#handlePresenceUpdate(data);
                break;

            case 'room_announcement':
                this.#handleRoomAnnouncement(data);
                break;

            case 'kicked_from_room':
                this.#handleKicked(data);
                break;

            case 'error':
                this.#handleError(data);
                break;

            case 'dnd_missed_message':
                this.#handleDNDMissedMessage(data);
                break;

            case 'emotion_counter_update':
                this.#handleEmotionCounterUpdate(data);
                break;

            case 'user_room_counter_update':
                this.#handleUserRoomCounterUpdate(data);
                break;
        }
    }

    // ========================================================================
    // ROOM MANAGEMENT
    // ========================================================================

    /**
     * Load emotion rooms list
     * @returns {Promise<Array>}
     */
    async loadEmotionRooms() {
        try {
            const response = await this.#apiRequest('GET', '/rooms/emotions');
            const rooms = response.data?.rooms || [];

            this.#dispatch('chat:emotion_rooms_loaded', { rooms });
            return rooms;

        } catch (error) {
            console.error('[ChatManager] Failed to load emotion rooms:', error);
            return [];
        }
    }

    /**
     * Load user-created rooms list
     * Enterprise-grade with proper error handling and UI updates
     * @returns {Promise<Array>}
     */
    async loadUserRooms() {
        const container = document.getElementById('userRoomsContainer');
        const skeleton = container?.querySelector('.skeleton-rooms');
        const emptyState = document.getElementById('userRoomsEmpty');
        const roomsList = document.getElementById('userRoomsList');
        const countEl = document.getElementById('userRoomsCount');

        try {
            const response = await this.#apiRequest('GET', '/rooms');
            const rooms = response.data?.rooms || [];

            // Hide skeleton
            if (skeleton) skeleton.classList.add('hidden');

            if (rooms.length === 0) {
                // Show empty state
                if (emptyState) emptyState.classList.remove('hidden');
                if (roomsList) roomsList.classList.add('hidden');
            } else {
                // Render rooms
                if (emptyState) emptyState.classList.add('hidden');
                if (roomsList) {
                    roomsList.innerHTML = rooms.map(room => this.#renderUserRoomItem(room)).join('');
                    roomsList.classList.remove('hidden');

                    // Bind click events
                    roomsList.querySelectorAll('[data-room-uuid]').forEach(el => {
                        el.addEventListener('click', () => this.joinRoom(el.dataset.roomUuid, 'user'));
                    });
                }
            }

            // Update count
            if (countEl) countEl.textContent = rooms.length;

            this.#dispatch('chat:user_rooms_loaded', { rooms });
            return rooms;

        } catch (error) {
            console.error('[ChatManager] Failed to load user rooms:', error);

            // Show empty state on error
            if (skeleton) skeleton.classList.add('hidden');
            if (emptyState) emptyState.classList.remove('hidden');

            return [];
        }
    }

    /**
     * Load DM inbox (conversations list)
     * Enterprise-grade with unread counts and real-time updates
     * @returns {Promise<Array>}
     */
    async loadDMInbox() {
        const container = document.getElementById('dmInboxContainer');
        const skeleton = container?.querySelector('.skeleton-dm');
        const emptyState = document.getElementById('dmInboxEmpty');
        const inboxList = document.getElementById('dmInboxList');
        const unreadBadge = document.getElementById('dmUnreadCount');

        try {
            const response = await this.#apiRequest('GET', '/dm');
            const conversations = response.data?.conversations || [];

            // Hide skeleton
            if (skeleton) skeleton.classList.add('hidden');

            if (conversations.length === 0) {
                // Show empty state
                if (emptyState) emptyState.classList.remove('hidden');
                if (inboxList) inboxList.classList.add('hidden');
            } else {
                // Render conversations
                if (emptyState) emptyState.classList.add('hidden');
                if (inboxList) {
                    inboxList.innerHTML = conversations.map(conv => this.#renderDMInboxItem(conv)).join('');
                    inboxList.classList.remove('hidden');

                    // Bind click events
                    inboxList.querySelectorAll('[data-conversation-uuid]').forEach(el => {
                        el.addEventListener('click', () => this.openConversation(el.dataset.conversationUuid));
                    });
                }
            }

            // Calculate total unread
            const totalUnread = conversations.reduce((sum, c) => sum + (c.unread_count || 0), 0);
            if (unreadBadge) {
                unreadBadge.textContent = totalUnread;
                unreadBadge.classList.toggle('hidden', totalUnread === 0);
            }

            this.#dispatch('chat:dm_inbox_loaded', { conversations, totalUnread });
            return conversations;

        } catch (error) {
            console.error('[ChatManager] Failed to load DM inbox:', error);

            // Show empty state on error
            if (skeleton) skeleton.classList.add('hidden');
            if (emptyState) emptyState.classList.remove('hidden');

            return [];
        }
    }

    /**
     * Render a user room item for the sidebar
     * @private
     */
    #renderUserRoomItem(room) {
        const onlineClass = room.online_count > 0 ? 'text-green-400' : 'text-gray-500';
        return `
            <div data-room-uuid="${room.uuid}"
                 class="flex items-center p-3 rounded-lg cursor-pointer transition-all duration-200
                        hover:bg-gray-700/50 group">
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full
                            flex items-center justify-center mr-3 text-white text-sm font-bold">
                    ${room.name.charAt(0).toUpperCase()}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-white truncate">${this.#escapeHtml(room.name)}</div>
                    <div class="text-xs ${onlineClass} flex items-center">
                        <span class="w-2 h-2 rounded-full ${room.online_count > 0 ? 'bg-green-400' : 'bg-gray-500'} mr-1"></span>
                        ${room.online_count} online
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render a DM inbox item
     * @private
     */
    #renderDMInboxItem(conversation) {
        const unreadClass = conversation.unread_count > 0 ? 'bg-gray-700/70 border-l-2 border-purple-500' : '';
        const timeAgo = this.#formatTimeAgo(conversation.last_message_at);
        const preview = conversation.last_message_preview
            ? this.#truncate(conversation.last_message_preview, 30)
            : 'Nessun messaggio';

        return `
            <div data-conversation-uuid="${conversation.uuid}"
                 class="flex items-center p-3 rounded-lg cursor-pointer transition-all duration-200
                        hover:bg-gray-700/50 ${unreadClass}">
                <div class="relative">
                    <img src="${conversation.other_user?.avatar || '/assets/images/default-avatar.png'}"
                         alt="${this.#escapeHtml(conversation.other_user?.name || 'User')}"
                         class="w-10 h-10 rounded-full object-cover">
                    ${conversation.other_user?.is_online ? `
                        <span class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 border-2 border-gray-800 rounded-full"></span>
                    ` : ''}
                </div>
                <div class="flex-1 min-w-0 ml-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-white truncate">
                            ${this.#escapeHtml(conversation.other_user?.name || 'Utente')}
                        </span>
                        <span class="text-xs text-gray-500">${timeAgo}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-gray-400 truncate">${this.#escapeHtml(preview)}</span>
                        ${conversation.unread_count > 0 ? `
                            <span class="ml-2 px-1.5 py-0.5 bg-purple-600 text-white text-xs rounded-full min-w-[18px] text-center">
                                ${conversation.unread_count > 99 ? '99+' : conversation.unread_count}
                            </span>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Format time ago (enterprise-ready with i18n support)
     * @private
     */
    #formatTimeAgo(dateString) {
        if (!dateString) return '';

        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'ora';
        if (diffMins < 60) return `${diffMins}m`;
        if (diffHours < 24) return `${diffHours}h`;
        if (diffDays < 7) return `${diffDays}g`;

        return date.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' });
    }

    /**
     * Truncate text
     * @private
     */
    #truncate(text, maxLength) {
        if (!text || text.length <= maxLength) return text || '';
        return text.substring(0, maxLength) + '...';
    }

    /**
     * Join a chat room
     * @param {string} roomId - Room identifier (e.g., 'emotion:joy' or UUID)
     * @param {string} roomType - 'emotion' or 'user'
     * @returns {Promise<Object>}
     */
    async joinRoom(roomId, roomType = 'emotion') {
        try {

            // Leave current room if any (but don't dispatch chat:room_left yet)
            const previousRoom = this.#currentRoom;
            if (previousRoom && previousRoom !== roomId) {
                // Silently leave - we'll only dispatch chat:room_left if join fails
                if (this.#websocket?.isConnected) {
                    this.#websocket.send({
                        type: 'leave_room',
                        room_id: previousRoom
                    });
                }
                try {
                    await this.#apiRequest('POST', `/rooms/${encodeURIComponent(previousRoom)}/leave`);
                } catch (e) {
                    console.warn('[ChatManager] Error leaving previous room:', e);
                }
            }

            // ENTERPRISE V10.22: Track pending room join for WebSocket reconnection
            // If WebSocket is not connected now, we'll send join_room when it connects
            // via the n2t:wsConnected event listener
            const wsConnected = this.#websocket?.isConnected;

            // Send via WebSocket if available
            if (wsConnected) {
                this.#websocket.send({
                    type: 'join_room',
                    room_id: roomId,
                    room_type: roomType
                });
            }

            // Call HTTP API for persistence and get room data
            const response = await this.#apiRequest('POST', `/rooms/${encodeURIComponent(roomId)}/join`);

            if (response.success) {
                this.#currentRoom = roomId;

                // ENTERPRISE V10.22: If WebSocket connected during HTTP request but join_room not sent yet
                if (!wsConnected && this.#websocket?.isConnected) {
                    this.#websocket.send({
                        type: 'join_room',
                        room_id: roomId,
                        room_type: roomType
                    });
                }

                // Load initial messages
                const messages = await this.loadRoomMessages(roomId);

                // ENTERPRISE V9.3: Include room data for UI display (name, description, emotion_icon)
                this.#dispatch('chat:room_joined', {
                    roomId,
                    roomType,
                    room: response.data?.room || null,
                    onlineCount: response.data?.online_count || 0,
                    messages
                });

                return response;
            } else {
                // Join failed - restore previous room state
                this.#currentRoom = previousRoom;

                // ENTERPRISE V10.83: Dispatch error event with specific error code
                const errorCode = response.error || 'join_failed';
                const errorMessages = {
                    'room_archived': 'Questa stanza è stata chiusa e non è più disponibile.',
                    'room_not_found': 'Stanza non trovata. Potrebbe essere stata eliminata.',
                    'room_full': 'La stanza è piena.',
                    'user_banned': 'Sei stato bannato da questa stanza.',
                    'join_failed': 'Impossibile entrare nella stanza.'
                };
                const userMessage = errorMessages[errorCode] || errorMessages['join_failed'];

                this.#dispatch('chat:room_join_error', {
                    roomId,
                    errorCode,
                    message: userMessage
                });

                throw new Error(userMessage);
            }

        } catch (error) {
            console.error('[ChatManager] Failed to join room:', error);
            throw error;
        }
    }

    /**
     * Leave current room (explicit leave, e.g., back button)
     * Note: When switching rooms, joinRoom() handles leaving silently
     * @param {string} roomIdOverride - Optional room ID to leave (used by joinRoom)
     * @returns {Promise<void>}
     */
    async leaveRoom(roomIdOverride = null) {
        const roomId = roomIdOverride || this.#currentRoom;
        if (!roomId) return;

        try {
            // Send via WebSocket
            if (this.#websocket?.isConnected) {
                this.#websocket.send({
                    type: 'leave_room',
                    room_id: roomId
                });
            }

            // HTTP API
            await this.#apiRequest('POST', `/rooms/${encodeURIComponent(roomId)}/leave`);

        } catch (error) {
            console.warn('[ChatManager] Error leaving room:', error);
        }

        // Only clear current room if leaving the current room (not when switching)
        if (!roomIdOverride || roomIdOverride === this.#currentRoom) {
            this.#currentRoom = null;
            this.#dispatch('chat:room_left', { roomId });
        }
    }

    /**
     * Load room messages
     * @param {string} roomId
     * @param {number} limit
     * @param {string} before - Message ID for pagination
     * @returns {Promise<Array>}
     */
    async loadRoomMessages(roomId, limit = 50, before = null) {
        try {
            let url = `/rooms/${encodeURIComponent(roomId)}/messages?limit=${limit}`;
            if (before) url += `&before=${before}`;

            const response = await this.#apiRequest('GET', url);
            const messages = response.data?.messages || [];

            // Cache messages
            if (!this.#messages.has(roomId)) {
                this.#messages.set(roomId, []);
            }
            const cached = this.#messages.get(roomId);
            cached.push(...messages);

            // Limit cache size
            if (cached.length > ChatManager.CONFIG.MAX_CACHED_MESSAGES) {
                cached.splice(0, cached.length - ChatManager.CONFIG.MAX_CACHED_MESSAGES);
            }

            return messages;

        } catch (error) {
            console.error('[ChatManager] Failed to load messages:', error);
            return [];
        }
    }

    /**
     * Send message to current room
     * @param {string} content - Message content
     * @returns {Promise<Object>}
     */
    async sendRoomMessage(content) {
        if (!this.#currentRoom) {
            throw new Error('Not in a room');
        }

        content = content.trim();
        if (!content) {
            throw new Error('Message cannot be empty');
        }

        // Clear typing indicator
        this.stopTyping();

        // Send via WebSocket for real-time
        if (this.#websocket?.isConnected) {
            const wsMessage = {
                type: 'room_message',
                room_id: this.#currentRoom,
                content
            };

            this.#websocket.send(wsMessage);

            // Optimistic UI update
            const tempMessage = {
                id: `temp_${Date.now()}`,
                content,
                sender_uuid: window.APP_USER.uuid,
                created_at: new Date().toISOString(),
                status: 'sending'
            };

            this.#dispatch('chat:message_sending', {
                roomId: this.#currentRoom,
                message: tempMessage
            });

            return { success: true, message: tempMessage };
        }

        // Fallback to HTTP
        const response = await this.#apiRequest('POST', `/rooms/${encodeURIComponent(this.#currentRoom)}/messages`, {
            content
        });

        // Show the message in UI immediately (HTTP mode doesn't have WebSocket push)
        if (response.success && response.data?.message) {
            this.#dispatch('chat:message_received', {
                roomId: this.#currentRoom,
                message: response.data.message
            });
        }

        return response;
    }

    // ========================================================================
    // DIRECT MESSAGES
    // ========================================================================

    /**
     * Get DM inbox (conversations list)
     * @param {number} limit
     * @param {number} offset
     * @returns {Promise<Object>}
     */
    async getInbox(limit = 20, offset = 0) {
        try {
            const response = await this.#apiRequest('GET', `/dm?limit=${limit}&offset=${offset}`);
            this.#dispatch('chat:inbox_loaded', response.data);
            return response.data;

        } catch (error) {
            console.error('[ChatManager] Failed to load inbox:', error);
            throw error;
        }
    }

    /**
     * Start or get conversation with a user
     * @param {string} recipientUuid
     * @returns {Promise<Object>}
     */
    async startConversation(recipientUuid) {
        try {
            const response = await this.#apiRequest('POST', '/dm', {
                recipient_uuid: recipientUuid
            });

            if (response.success) {
                this.#currentConversation = response.data.conversation;
                this.#dispatch('chat:conversation_started', response.data);
            }

            return response;

        } catch (error) {
            console.error('[ChatManager] Failed to start conversation:', error);
            throw error;
        }
    }

    /**
     * Open a conversation
     * @param {string} conversationUuid
     * @returns {Promise<Object>}
     */
    async openConversation(conversationUuid) {
        try {
            const response = await this.#apiRequest('GET', `/dm/${conversationUuid}`);

            if (response.success) {
                this.#currentConversation = response.data.conversation;

                // ENTERPRISE V10.76: Immediately update sidebar badge to 0 (messages will be marked read)
                // This provides instant UI feedback before server confirms
                this.#updateConversationUnreadBadge(conversationUuid, 0);

                // ENTERPRISE V10.39: Notify ChatWidgetManager to close widget for this conversation
                // This prevents duplicate UI (widget + full page showing same conversation)
                window.dispatchEvent(new CustomEvent('n2t:dmPageOpened', {
                    detail: { conversationUuid }
                }));

                // ENTERPRISE V10.170: Emit dm_loading event BEFORE loading messages
                // This allows MessageList to set #chatId early for E2E decryption
                this.#dispatch('chat:dm_loading', { conversationUuid });

                // Load messages
                const messages = await this.loadDMMessages(conversationUuid);

                // ENTERPRISE V10.59: Use ReadReceiptManager for visibility-aware read receipts
                // Extended DM page = conversation is open (isWidgetOpen = true)
                if (window.readReceiptManager) {
                    window.readReceiptManager.onConversationOpened(conversationUuid, true);
                } else if (document.hasFocus() && document.visibilityState === 'visible') {
                    await this.markConversationRead(conversationUuid);
                }

                this.#dispatch('chat:conversation_opened', {
                    conversation: response.data.conversation,
                    otherUserPresence: response.data.other_user_presence,
                    otherUserHasE2eKey: response.data.other_user_has_e2e_key ?? true, // V10.170
                    messages
                });
            }

            return response;

        } catch (error) {
            console.error('[ChatManager] Failed to open conversation:', error);
            throw error;
        }
    }

    /**
     * Load DM messages
     * @param {string} conversationUuid
     * @param {number} limit
     * @param {string} before
     * @returns {Promise<Array>}
     */
    async loadDMMessages(conversationUuid, limit = 50, before = null) {
        try {
            let url = `/dm/${conversationUuid}/messages?limit=${limit}`;
            if (before) url += `&before=${before}`;

            const response = await this.#apiRequest('GET', url);
            const messages = response.data?.messages || [];

            // Decrypt messages
            const decryptedMessages = await Promise.all(
                messages.map(async (msg) => {
                    if (msg.encrypted_content && this.#encryption) {
                        try {
                            msg.content = await this.#encryption.decryptMessage(
                                msg.encrypted_content,
                                msg.content_iv,
                                msg.content_tag,
                                conversationUuid
                            );
                            msg.decrypted = true;
                        } catch (e) {
                            // ENTERPRISE V11.9: User-friendly message for decryption failure
                            msg.content = '🔒 Messaggio non decifrabile';
                            msg.decrypted = false;
                            msg.decryptionFailed = true;  // Flag for UI styling
                        }
                    }
                    return msg;
                })
            );

            // ENTERPRISE V11.9: If new device and some messages failed decryption,
            // prepend a system message explaining why (only shown to current user)
            const hasFailedDecryption = decryptedMessages.some(m => m.decryptionFailed);
            if (hasFailedDecryption && this.#encryption?.isNewDevice) {
                const systemMessage = {
                    id: 'system-new-device-warning',
                    type: 'system',
                    content: '⚠️ Stai usando un nuovo dispositivo. I messaggi precedenti non sono decifrabili perché la chiave di crittografia è stata rigenerata.',
                    created_at: new Date().toISOString(),
                    isSystemMessage: true,
                    newDeviceWarning: true
                };
                // Prepend system message (it should appear first/oldest)
                decryptedMessages.unshift(systemMessage);
            }

            // Cache messages
            if (!this.#messages.has(conversationUuid)) {
                this.#messages.set(conversationUuid, []);
            }
            this.#messages.get(conversationUuid).push(...decryptedMessages);

            return decryptedMessages;

        } catch (error) {
            console.error('[ChatManager] Failed to load DM messages:', error);
            return [];
        }
    }

    /**
     * Send encrypted DM
     * @param {string} content - Plaintext content
     * @param {string} recipientUuid - Recipient UUID
     * @returns {Promise<Object>}
     */
    async sendDM(content, recipientUuid = null) {
        if (!this.#currentConversation) {
            throw new Error('No conversation open');
        }

        content = content.trim();
        if (!content) {
            throw new Error('Message cannot be empty');
        }

        const conversationUuid = this.#currentConversation.uuid;
        recipientUuid = recipientUuid || this.#getOtherUserUuid();

        // Clear typing
        this.stopTyping('dm');

        try {
            // Encrypt message
            const encrypted = await this.#encryption.encryptMessage(content, conversationUuid);

            // Send via WebSocket
            if (this.#websocket?.isConnected) {
                this.#websocket.send({
                    type: 'dm_message',
                    conversation_uuid: conversationUuid,
                    recipient_uuid: recipientUuid,
                    encrypted_content: encrypted.ciphertext,
                    content_iv: encrypted.iv,
                    content_tag: encrypted.tag
                });

                // Optimistic UI
                const tempMessage = {
                    id: `temp_${Date.now()}`,
                    uuid: `temp_${Date.now()}`,
                    content,
                    sender_uuid: window.APP_USER.uuid,
                    created_at: new Date().toISOString(),
                    status: 'sending',
                    decrypted: true
                };

                this.#dispatch('chat:dm_sending', {
                    conversationUuid,
                    message: tempMessage
                });

                return { success: true, message: tempMessage };
            }

            // HTTP fallback
            return this.#apiRequest('POST', `/dm/${conversationUuid}/messages`, {
                encrypted_content: encrypted.ciphertext,
                content_iv: encrypted.iv,
                content_tag: encrypted.tag
            });

        } catch (error) {
            console.error('[ChatManager] Failed to send DM:', error);
            throw error;
        }
    }

    /**
     * Mark conversation as read
     * @param {string} conversationUuid
     * @returns {Promise<void>}
     */
    async markConversationRead(conversationUuid) {
        try {
            // Send via WebSocket
            if (this.#websocket?.isConnected) {
                const senderUuid = this.#getOtherUserUuid();
                this.#websocket.send({
                    type: 'dm_read',
                    conversation_uuid: conversationUuid,
                    sender_uuid: senderUuid
                });
            }

            // HTTP API
            await this.#apiRequest('POST', `/dm/${conversationUuid}/read`);

        } catch (error) {
            console.warn('[ChatManager] Failed to mark as read:', error);
        }
    }

    // ========================================================================
    // TYPING INDICATORS
    // ========================================================================

    /**
     * Start typing indicator
     * @param {string} targetType - 'room' or 'dm'
     */
    startTyping(targetType = 'room') {
        const targetId = targetType === 'room' ? this.#currentRoom : this.#currentConversation?.uuid;
        if (!targetId) return;

        // Debounce
        if (this.#typingTimer) {
            clearTimeout(this.#typingTimer);
        }

        // Send typing indicator
        if (this.#websocket?.isConnected) {
            this.#websocket.send({
                type: 'typing_start',
                target_type: targetType,
                target_id: targetId,
                recipient_uuid: targetType === 'dm' ? this.#getOtherUserUuid() : null
            });
        }

        // Auto-stop after timeout
        this.#typingTimer = setTimeout(() => {
            this.stopTyping(targetType);
        }, ChatManager.CONFIG.TYPING_TIMEOUT);
    }

    /**
     * Stop typing indicator
     * @param {string} targetType
     */
    stopTyping(targetType = 'room') {
        if (this.#typingTimer) {
            clearTimeout(this.#typingTimer);
            this.#typingTimer = null;
        }

        const targetId = targetType === 'room' ? this.#currentRoom : this.#currentConversation?.uuid;
        if (!targetId) return;

        if (this.#websocket?.isConnected) {
            this.#websocket.send({
                type: 'typing_stop',
                target_type: targetType,
                target_id: targetId,
                recipient_uuid: targetType === 'dm' ? this.#getOtherUserUuid() : null
            });
        }
    }

    // ========================================================================
    // PRESENCE
    // ========================================================================

    /**
     * Update user status
     * @param {string} status - 'online', 'away', 'dnd', 'invisible'
     * @returns {Promise<void>}
     */
    async setStatus(status) {
        const validStatuses = ['online', 'away', 'dnd', 'invisible'];
        if (!validStatuses.includes(status)) {
            throw new Error('Invalid status');
        }

        // WebSocket
        if (this.#websocket?.isConnected) {
            this.#websocket.send({
                type: 'presence_update',
                status
            });
        }

        // HTTP API
        await this.#apiRequest('POST', '/presence/status', { status });
    }

    /**
     * Get user presence
     * @param {string} userUuid
     * @returns {Promise<Object>}
     */
    async getPresence(userUuid) {
        try {
            const response = await this.#apiRequest('GET', `/presence/${userUuid}`);
            return response.data;
        } catch (error) {
            return { status: 'offline', is_online: false };
        }
    }

    /**
     * Get presence for multiple users
     * @param {string[]} userUuids
     * @returns {Promise<Object>}
     */
    async getBatchPresence(userUuids) {
        try {
            const response = await this.#apiRequest('GET', `/presence/batch?uuids=${userUuids.join(',')}`);
            return response.data;
        } catch (error) {
            return {};
        }
    }

    // ========================================================================
    // REPORTING
    // ========================================================================

    /**
     * Report a message
     * @param {string} messageUuid
     * @param {string} reportType - 'harassment', 'spam', 'inappropriate', 'hate_speech', 'other'
     * @param {string} reason
     * @returns {Promise<Object>}
     */
    async reportMessage(messageUuid, reportType, reason = '') {
        return this.#apiRequest('POST', `/messages/${messageUuid}/report`, {
            report_type: reportType,
            report_reason: reason
        });
    }

    // ========================================================================
    // EVENT HANDLERS
    // ========================================================================

    #handleRoomJoined(data) {
        // ENTERPRISE FIX: Only update internal state from WebSocket confirmation
        // The chat:room_joined event is dispatched by joinRoom() HTTP response
        // which includes the messages. This prevents showing empty chat when
        // WebSocket confirmation arrives before HTTP response with messages.
        this.#currentRoom = data.room_id;

        // Update online count if provided
        if (data.online_count !== undefined) {
            this.#dispatch('chat:online_count_updated', {
                roomId: data.room_id,
                count: data.online_count
            });
        }

        // DO NOT dispatch chat:room_joined here - let HTTP handler do it with messages
    }

    #handleRoomLeft(data) {
        if (this.#currentRoom === data.room_id) {
            this.#currentRoom = null;
        }
        this.#dispatch('chat:room_left', data);
    }

    #handleRoomMessage(data) {
        // ENTERPRISE V10.23: Handle both direct structure and payload wrapper (defense in depth)
        const payload = data?.payload || data || {};
        const { room_id, message } = payload;

        // Validate required fields
        if (!room_id || !message) {
            return;
        }

        // ENTERPRISE FIX: Skip own messages - already displayed via optimistic UI
        if (message.sender_uuid === window.APP_USER?.uuid) {

            // Update the optimistic message with server-confirmed data
            // This changes status from 'sending' to 'sent' and adds real ID
            this.#dispatch('chat:message_confirmed', {
                roomId: room_id,
                tempId: `temp_${message.sender_uuid}`, // Pattern to match temp messages
                message: { ...message, status: 'sent' }
            });
            return;
        }

        // Add to cache (only for other users' messages)
        if (!this.#messages.has(room_id)) {
            this.#messages.set(room_id, []);
        }
        this.#messages.get(room_id).push(message);

        this.#dispatch('chat:message_received', { roomId: room_id, message });
    }

    /**
     * ENTERPRISE V10.84: Handle message deleted by moderator
     *
     * Real-time message deletion for all connected users.
     * Message is removed from UI instantly when moderator deletes it.
     *
     * @param {Object} data - { room_id, message_uuid }
     */
    #handleMessageDeleted(data) {
        // Handle both direct structure and payload wrapper
        const payload = data?.payload || data || {};
        const { room_id, message_uuid } = payload;

        if (!room_id || !message_uuid) {
            return;
        }

        // Remove from local cache if exists
        if (this.#messages.has(room_id)) {
            const roomMessages = this.#messages.get(room_id);
            const index = roomMessages.findIndex(m =>
                (m.uuid && m.uuid === message_uuid) || (m.id && m.id === message_uuid)
            );
            if (index !== -1) {
                roomMessages.splice(index, 1);
            }
        }

        // Dispatch event for MessageList to remove from UI
        this.#dispatch('chat:message_deleted', { roomId: room_id, messageUuid: message_uuid });
    }

    #handleUserJoined(data) {
        const { room_id, user_uuid, online_count, nickname } = data;

        if (!this.#onlineUsers.has(room_id)) {
            this.#onlineUsers.set(room_id, new Set());
        }
        this.#onlineUsers.get(room_id).add(user_uuid);

        this.#dispatch('chat:user_joined', data);

        // ENTERPRISE: Real-time online count update
        if (online_count !== undefined) {
            this.#dispatch('chat:online_count_updated', {
                roomId: room_id,
                count: online_count
            });
        }
    }

    #handleUserLeft(data) {
        const { room_id, user_uuid, online_count, nickname } = data;

        if (this.#onlineUsers.has(room_id)) {
            this.#onlineUsers.get(room_id).delete(user_uuid);
        }

        // Clear typing
        if (this.#typingUsers.has(room_id)) {
            this.#typingUsers.get(room_id).delete(user_uuid);
        }

        this.#dispatch('chat:user_left', data);

        // ENTERPRISE: Real-time online count update
        if (online_count !== undefined) {
            this.#dispatch('chat:online_count_updated', {
                roomId: room_id,
                count: online_count
            });
        }
    }

    async #handleDMReceived(data) {
        // ENTERPRISE FIX: Handle both direct structure and payload wrapper
        const payload = data?.payload || data || {};
        const { conversation_uuid, message } = payload;

        // Guard: message must exist
        if (!message) {
            return;
        }

        // ENTERPRISE FIX: Handle encrypted content in nested 'encrypted' object
        const encryptedData = message.encrypted || {};
        const encryptedContent = message.encrypted_content || encryptedData.ciphertext;
        const contentIv = message.content_iv || encryptedData.iv;
        const contentTag = message.content_tag || encryptedData.tag;

        // Decrypt if needed
        if (encryptedContent && this.#encryption) {
            try {
                message.content = await this.#encryption.decryptMessage(
                    encryptedContent,
                    contentIv,
                    contentTag,
                    conversation_uuid
                );
                message.decrypted = true;
            } catch (e) {
                // Fallback: try base64 decode (simple encoding, not real encryption)
                try {
                    message.content = atob(encryptedContent);
                    message.decrypted = true;
                } catch (e2) {
                    message.content = '[Decryption failed]';
                    message.decrypted = false;
                }
            }
        } else if (encryptedContent) {
            // No encryption service - try simple base64 decode
            try {
                message.content = atob(encryptedContent);
            } catch (e) {
                message.content = message.preview || '[Encrypted message]';
            }
        }

        // Add to cache
        if (!this.#messages.has(conversation_uuid)) {
            this.#messages.set(conversation_uuid, []);
        }
        this.#messages.get(conversation_uuid).push(message);

        // Show notification if not in conversation
        if (this.#currentConversation?.uuid !== conversation_uuid) {
            this.#showNotification('New message', message.content?.substring(0, 50) + '...');

            // ENTERPRISE V10.76: Increment unread badge for this conversation
            // Find current badge count and increment
            const inboxItem = document.querySelector(`[data-conversation-uuid="${conversation_uuid}"]`);
            if (inboxItem) {
                const badgeEl = inboxItem.querySelector('.bg-purple-600');
                const currentCount = badgeEl ? parseInt(badgeEl.textContent, 10) || 0 : 0;
                this.#updateConversationUnreadBadge(conversation_uuid, currentCount + 1);
            } else {
                // Conversation not in sidebar yet - reload inbox
                this.loadDMInbox();
            }
        }

        this.#dispatch('chat:dm_received', { conversationUuid: conversation_uuid, message });
    }

    #handleDMSent(data) {
        const { conversation_uuid, message } = data;

        // Update temp message status
        this.#dispatch('chat:dm_sent', { conversationUuid: conversation_uuid, message });
    }

    /**
     * ENTERPRISE V4.3: Handle async audio upload completed (sender notification)
     *
     * Called when the DM audio worker has finished processing:
     * - S3 upload complete
     * - Message saved to database
     * - WebSocket notification sent
     *
     * The sender receives this event to confirm upload success and update UI.
     *
     * @param {Object} data - { conversation_uuid, job_id, status, message }
     */
    #handleDMAudioUploaded(data) {
        // Unwrap payload if WebSocket wrapped it
        const payload = data?.payload || data || {};
        const { conversation_uuid, job_id, status, message } = payload;

        console.log('[ChatManager] dm_audio_uploaded received', {
            conversation_uuid,
            job_id,
            status,
            hasMessage: !!message
        });

        if (status !== 'completed' || !message) {
            console.warn('[ChatManager] dm_audio_uploaded: invalid status or missing message');
            return;
        }

        // Dispatch event for UI to add/update the audio message
        // This is similar to dm_received but for the SENDER
        this.#dispatch('chat:dm_audio_uploaded', {
            conversationUuid: conversation_uuid,
            jobId: job_id,
            message
        });

        // Also dispatch as dm_received for consistency (message was "received" from worker)
        this.#dispatch('chat:dm_received', {
            conversationUuid: conversation_uuid,
            message
        });
    }

    #handleReadReceipt(data) {
        // ENTERPRISE V10.76: This is received by the SENDER when the recipient reads messages
        // Unwrap payload if WebSocket wrapped it (defense in depth)
        const payload = data?.payload || data || {};
        const { conversation_uuid, reader_uuid, read_at } = payload;

        if (!conversation_uuid) {
            return;
        }

        // Dispatch with normalized structure for MessageList.js and ChatWidgetManager.js
        this.#dispatch('chat:read_receipt', {
            conversation_uuid,
            reader_uuid,
            read_at
        });
    }

    /**
     * ENTERPRISE V10.76: Update unread badge for a specific conversation in sidebar
     *
     * Called when:
     * 1. User opens a conversation (set to 0)
     * 2. New message received in closed conversation (increment)
     * 3. DM inbox refresh (bulk update)
     *
     * @param {string} conversationUuid
     * @param {number} newCount
     */
    #updateConversationUnreadBadge(conversationUuid, newCount) {
        const inboxItem = document.querySelector(`[data-conversation-uuid="${conversationUuid}"]`);
        if (!inboxItem) return;

        const badgeEl = inboxItem.querySelector('.bg-purple-600');
        const previewContainer = inboxItem.querySelector('.flex.items-center.justify-between:last-child');

        if (newCount > 0) {
            // Show/update badge
            if (badgeEl) {
                badgeEl.textContent = newCount > 99 ? '99+' : newCount;
            } else if (previewContainer) {
                // Create new badge if doesn't exist
                const badge = document.createElement('span');
                badge.className = 'ml-2 px-1.5 py-0.5 bg-purple-600 text-white text-xs rounded-full min-w-[18px] text-center';
                badge.textContent = newCount > 99 ? '99+' : newCount;
                previewContainer.appendChild(badge);
            }
            // Add highlight styling
            inboxItem.classList.add('bg-gray-700/70', 'border-l-2', 'border-purple-500');
        } else {
            // Remove badge and highlight
            if (badgeEl) badgeEl.remove();
            inboxItem.classList.remove('bg-gray-700/70', 'border-l-2', 'border-purple-500');
        }

        // Update total unread count badge in header
        this.#updateTotalUnreadBadge();
    }

    /**
     * ENTERPRISE V10.76: Recalculate total unread count from all conversations
     */
    #updateTotalUnreadBadge() {
        const unreadBadge = document.getElementById('dmUnreadCount');
        if (!unreadBadge) return;

        // Count all visible badges
        const badges = document.querySelectorAll('#dmInboxList .bg-purple-600');
        let total = 0;
        badges.forEach(badge => {
            const count = parseInt(badge.textContent, 10);
            if (!isNaN(count)) total += count;
        });

        unreadBadge.textContent = total;
        unreadBadge.classList.toggle('hidden', total === 0);
    }

    #handleTypingIndicator(data) {
        const { target_id, user_uuid, is_typing } = data;

        if (!this.#typingUsers.has(target_id)) {
            this.#typingUsers.set(target_id, new Map());
        }

        const typingMap = this.#typingUsers.get(target_id);

        if (is_typing) {
            // Clear existing timeout
            if (typingMap.has(user_uuid)) {
                clearTimeout(typingMap.get(user_uuid));
            }

            // Set auto-clear timeout
            const timeout = setTimeout(() => {
                typingMap.delete(user_uuid);
                this.#dispatch('chat:typing_update', {
                    targetId: target_id,
                    typingUsers: Array.from(typingMap.keys())
                });
            }, ChatManager.CONFIG.TYPING_TIMEOUT + 1000);

            typingMap.set(user_uuid, timeout);
        } else {
            if (typingMap.has(user_uuid)) {
                clearTimeout(typingMap.get(user_uuid));
                typingMap.delete(user_uuid);
            }
        }

        this.#dispatch('chat:typing_update', {
            targetId: target_id,
            typingUsers: Array.from(typingMap.keys())
        });
    }

    #handlePresenceUpdate(data) {
        this.#dispatch('chat:presence_update', data);
    }

    #handleRoomAnnouncement(data) {
        this.#dispatch('chat:announcement', data);
    }

    #handleKicked(data) {
        if (this.#currentRoom === data.room_id) {
            this.#currentRoom = null;
        }
        this.#dispatch('chat:kicked', data);
    }

    #handleError(data) {
        console.error('[ChatManager] WebSocket error:', data);
        this.#dispatch('chat:error', data);
    }

    /**
     * ENTERPRISE V10.4: Handle DND missed message notification
     * Shows a toast notification when someone tries to contact user in DND mode
     * @param {Object} data - {sender_uuid, sender_nickname, sender_avatar, conversation_uuid, message, timestamp}
     */
    #handleDNDMissedMessage(data) {

        // Show toast notification
        this.#showToast(
            `💬 ${data.sender_nickname || 'Qualcuno'} ti ha cercato`,
            'info',
            5000
        );

        // Dispatch event for other components (e.g., to show badge on DM icon)
        this.#dispatch('chat:dnd_missed_message', {
            senderUuid: data.sender_uuid,
            senderNickname: data.sender_nickname,
            senderAvatar: data.sender_avatar,
            conversationUuid: data.conversation_uuid,
            timestamp: data.timestamp
        });
    }

    /**
     * ENTERPRISE V10.17: Handle emotion room counter update (broadcast to all users)
     * This allows users in lobby (viewing emotion rooms list) to see real-time counters
     * @param {Object} data - {room_id, online_count, timestamp}
     */
    #handleEmotionCounterUpdate(data) {
        // ENTERPRISE FIX: Handle both direct structure and payload wrapper
        const payload = data?.payload || data || {};
        const { room_id, online_count } = payload;

        if (!room_id || online_count === undefined) {
            return;
        }

        // Dispatch chat:online_count_updated event (listened by EmotionRoomSelector)
        this.#dispatch('chat:online_count_updated', {
            roomId: room_id,
            count: online_count
        });
    }

    /**
     * ENTERPRISE V10.50: Handle user room counter update (broadcast to all users)
     *
     * Enables real-time counter updates in the User Rooms sidebar panel.
     * Uses O(1) DOM lookup via data-room-uuid attribute for enterprise performance.
     *
     * Architecture:
     * - WebSocket broadcasts counter changes to ALL authenticated clients
     * - Clients update their local UI without re-fetching the room list
     * - No re-render of entire list - surgical update of counter element only
     *
     * @param {Object} data - {room_id, online_count, timestamp}
     */
    #handleUserRoomCounterUpdate(data) {
        // ENTERPRISE: Handle both direct structure and payload wrapper (defense in depth)
        const payload = data?.payload || data || {};
        const { room_id, online_count } = payload;

        if (!room_id || online_count === undefined) {
            return;
        }

        // ENTERPRISE V10.52: Direct DOM update for O(1) performance
        // Find the room element by UUID - avoids re-rendering entire list
        const roomElement = document.querySelector(`[data-room-uuid="${room_id}"]`);

        if (roomElement) {
            // Update data attribute for state tracking
            roomElement.dataset.onlineCount = online_count;

            // Find specific elements by class (ENTERPRISE V10.52 structure)
            const counterContainer = roomElement.querySelector('.room-online-counter');
            const dot = roomElement.querySelector('.room-online-dot');
            const countSpan = roomElement.querySelector('.room-online-count');

            if (counterContainer) {
                // Update container color based on online status
                counterContainer.className = `text-xs ${online_count > 0 ? 'text-green-400' : 'text-gray-400'} flex items-center room-online-counter`;
            }

            if (dot) {
                // Update dot color and animation
                dot.className = `w-2 h-2 rounded-full ${online_count > 0 ? 'bg-green-400 animate-pulse' : 'bg-gray-500'} mr-1 room-online-dot`;
            }

            if (countSpan) {
                // Update the count number
                countSpan.textContent = online_count;
            }
        }

        // Dispatch event for other components that may need to react
        this.#dispatch('chat:user_room_counter_updated', {
            roomId: room_id,
            count: online_count
        });
    }

    /**
     * ENTERPRISE V10.4: Show toast notification
     * @param {string} message - Message to display
     * @param {string} type - Type: 'info', 'success', 'error', 'warning'
     * @param {number} duration - Duration in ms (default 3000)
     */
    #showToast(message, type = 'info', duration = 3000) {
        // Remove existing chat toast
        const existingToast = document.querySelector('.chat-manager-toast');
        if (existingToast) {
            existingToast.remove();
        }

        const toast = document.createElement('div');
        toast.className = 'chat-manager-toast fixed top-20 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform';

        const colors = {
            success: 'bg-green-600 text-white',
            error: 'bg-red-600 text-white',
            warning: 'bg-yellow-600 text-white',
            info: 'bg-purple-600 text-white'  // Purple for chat notifications
        };

        toast.className += ' ' + (colors[type] || colors.info);
        toast.textContent = message;

        document.body.appendChild(toast);

        // Auto-remove after duration
        setTimeout(() => {
            toast.style.transform = 'translateX(400px)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    // ========================================================================
    // LIFECYCLE
    // ========================================================================

    #handleVisibilityChange = () => {
        if (document.hidden) {
            // ENTERPRISE V10.17: DON'T stop heartbeat when tab hidden!
            // The Redis SET has 90s TTL - if heartbeat stops, users get kicked from room
            // Discord/Slack keep heartbeat running even with tab hidden
            // Only reduce frequency (optional future optimization)
        } else {
            // Page visible - ensure heartbeat is running and send immediate pulse
            this.#startHeartbeat();
            this.#sendHeartbeat();
        }
    };

    #handleBeforeUnload = () => {
        // Mark offline
        if (this.#websocket?.isConnected) {
            this.#websocket.send({ type: 'presence_update', status: 'offline' });
        }
        this.#stopHeartbeat();
    };

    /**
     * ENTERPRISE V11.6: Handle URL room parameter for auto-join
     *
     * When user clicks a room invite notification, they're redirected to /chat?room=xxx
     * This method reads the URL parameter and automatically joins the specified room.
     *
     * Flow:
     * 1. NotificationService creates room_invite notification
     * 2. User clicks notification in bell → handleNotificationClick routes to /chat?room=xxx
     * 3. Page loads, EmotionRoomSelector initializes
     * 4. Page calls chatManager.checkUrlRoomParameter()
     * 5. This method reads ?room parameter and calls joinRoom()
     * 6. URL is cleaned (parameter removed) to prevent re-join on refresh
     *
     * IMPORTANT: Must be called AFTER EmotionRoomSelector is initialized!
     * Otherwise chat:room_joined event fires before emotionRoomSelector.getRoomInfo() works
     */
    checkUrlRoomParameter() {
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const roomUuid = urlParams.get('room');

            if (!roomUuid) {
                return; // No room parameter, nothing to do
            }

            // ENTERPRISE V11.6: Determine room type from UUID format
            // emotion:love, emotion:joy, etc. → 'emotion'
            // UUID format (user rooms) → 'user'
            const roomType = roomUuid.startsWith('emotion:') ? 'emotion' : 'user';

            console.log('[ChatManager] Auto-joining room from URL:', roomUuid, 'type:', roomType);

            // Clean URL (remove ?room parameter to prevent re-join on refresh)
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);

            // Join the room (async, but we don't need to await)
            // joinRoom() will dispatch chat:room_joined or chat:room_join_error events
            this.joinRoom(roomUuid, roomType).catch(error => {
                console.error('[ChatManager] Failed to auto-join room from URL:', error);
                // Error is already handled by joinRoom() which dispatches chat:room_join_error
            });

        } catch (error) {
            console.error('[ChatManager] Error handling URL room parameter:', error);
        }
    }

    #startHeartbeat() {
        if (this.#heartbeatTimer) return;

        this.#heartbeatTimer = setInterval(() => {
            this.#sendHeartbeat();
        }, ChatManager.CONFIG.HEARTBEAT_INTERVAL);
    }

    #stopHeartbeat() {
        if (this.#heartbeatTimer) {
            clearInterval(this.#heartbeatTimer);
            this.#heartbeatTimer = null;
        }
    }

    #sendHeartbeat() {
        if (this.#websocket?.isConnected) {
            this.#websocket.send({
                type: 'chat_heartbeat',
                current_room_id: this.#currentRoom
            });
        }
    }

    // ========================================================================
    // UTILITIES
    // ========================================================================

    /**
     * Make API request
     * @param {string} method
     * @param {string} endpoint
     * @param {Object} data
     * @returns {Promise<Object>}
     */
    async #apiRequest(method, endpoint, data = null) {
        const url = ChatManager.CONFIG.API_BASE + endpoint;
        // CSRF token is automatically added by csrf.js fetch wrapper - DO NOT add manually
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin'
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(url, options);
        const json = await response.json();

        if (!response.ok) {
            throw new Error(json.error || 'API request failed');
        }

        return json;
    }

    /**
     * Get other user's UUID in current conversation
     * @returns {string|null}
     */
    #getOtherUserUuid() {
        if (!this.#currentConversation) return null;

        const { user1_uuid, user2_uuid } = this.#currentConversation;
        return user1_uuid === window.APP_USER.uuid ? user2_uuid : user1_uuid;
    }

    /**
     * Show browser notification
     * @param {string} title
     * @param {string} body
     */
    #showNotification(title, body) {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(title, {
                body,
                icon: '/assets/img/logo-192.png',
                tag: 'chat-notification'
            });
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text
     * @returns {string}
     */
    #escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Dispatch custom event
     * @param {string} eventName
     * @param {Object} detail
     */
    #dispatch(eventName, detail = {}) {
        document.dispatchEvent(new CustomEvent(eventName, { detail }));

        // Also call registered listeners
        if (this.#messageListeners.has(eventName)) {
            this.#messageListeners.get(eventName).forEach(callback => {
                try {
                    callback(detail);
                } catch (e) {
                    console.error(`[ChatManager] Listener error for ${eventName}:`, e);
                }
            });
        }
    }

    /**
     * Register event listener
     * @param {string} eventName
     * @param {Function} callback
     * @returns {Function} Unsubscribe function
     */
    on(eventName, callback) {
        if (!this.#messageListeners.has(eventName)) {
            this.#messageListeners.set(eventName, new Set());
        }
        this.#messageListeners.get(eventName).add(callback);

        // Return unsubscribe function
        return () => {
            this.#messageListeners.get(eventName).delete(callback);
        };
    }

    /**
     * Remove event listener
     * @param {string} eventName
     * @param {Function} callback
     */
    off(eventName, callback) {
        if (this.#messageListeners.has(eventName)) {
            this.#messageListeners.get(eventName).delete(callback);
        }
    }

    // ========================================================================
    // GETTERS
    // ========================================================================

    get currentRoom() { return this.#currentRoom; }
    get currentConversation() { return this.#currentConversation; }
    get isInitialized() { return this.#initialized; }
    get encryptionService() { return this.#encryption; }

    /**
     * Get cached messages for a room/conversation
     * @param {string} id
     * @returns {Array}
     */
    getMessages(id) {
        return this.#messages.get(id) || [];
    }

    /**
     * Get online users in a room
     * @param {string} roomId
     * @returns {Set}
     */
    getOnlineUsers(roomId) {
        return this.#onlineUsers.get(roomId) || new Set();
    }

    /**
     * Get typing users in a room/conversation
     * @param {string} id
     * @returns {string[]}
     */
    getTypingUsers(id) {
        const map = this.#typingUsers.get(id);
        return map ? Array.from(map.keys()) : [];
    }

    /**
     * Destroy chat manager
     */
    destroy() {
        this.#stopHeartbeat();
        document.removeEventListener('visibilitychange', this.#handleVisibilityChange);
        window.removeEventListener('beforeunload', this.#handleBeforeUnload);
        this.#messageListeners.clear();
        this.#messages.clear();
        this.#onlineUsers.clear();
        this.#typingUsers.clear();
        this.#initialized = false;
        ChatManager.instance = null;
    }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ChatManager;
}

// Also make globally available
window.ChatManager = ChatManager;
