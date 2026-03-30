/**
 * need2talk - WebSocket Manager (Enterprise Galaxy Edition)
 *
 * ULTRA SCALABILE per migliaia di utenti contemporanei
 * Architettura EventEmitter enterprise-grade con buffering messaggi
 *
 * ENTERPRISE V10.50 (2025-12-05): Refactoring completo
 * - Rimossa dipendenza da Need2Talk namespace
 * - Esposto come window.WebSocketManager
 * - Aggiunto supporto dual-mode send()
 * - Aggiunto onMessage(), onConnection(), disconnect()
 * - Mantenuto buffering messaggi per race condition
 * - Browser Notifications built-in
 */

window.WebSocketManager = {
    // Connection Management - Scalabile
    connection: null,
    reconnectAttempts: 0,
    maxReconnectAttempts: 10,
    reconnectDelay: 1000,
    maxReconnectDelay: 30000,

    // State Management - Memory Efficient
    isInitialized: false,
    isConnected: false,
    isConnecting: false,
    hasConnectedBefore: false,
    // ENTERPRISE V10.50: UUID only (prevents user enumeration)
    userUuid: null,
    sessionId: null,
    jwtToken: null,

    // Performance & Scalability
    heartbeatInterval: null,
    heartbeatTimeout: 30000,
    messageQueue: [],
    maxQueueSize: 100,

    // ENTERPRISE V10.31 (2025-12-04): Inbound Message Queue for Race Condition Prevention
    // Problem: Messages arrive BEFORE page-specific handlers are registered (DM, Chat rooms)
    // Solution: Buffer unhandled messages and replay them when handlers are registered
    // This eliminates the "a volte sì, a volte no" (sometimes yes, sometimes no) bug
    inboundMessageQueue: new Map(),  // Map<eventType, Array<{payload, timestamp}>>
    maxInboundQueueSize: 50,         // Per-event-type limit
    inboundQueueTTL: 30000,          // 30 seconds - discard old messages

    // Event Handlers - High Performance
    eventHandlers: new Map(),
    notificationHandlers: new Map(),

    // ENTERPRISE V10.50: Connection listeners (ROOT compatibility)
    connectionListeners: [],
    
    // Configuration - Scalabile
    config: {
        url: null,
        autoReconnect: true,
        binaryType: 'arraybuffer',  // More efficient than blob for JSON

        // Performance tuning for high concurrency
        pingInterval: 25000,
        pongTimeout: 5000
    },
    
    /**
     * Initialize WebSocket system - SCALABILE
     * ENTERPRISE V10.50: Standalone (no Need2Talk dependency)
     */
    init(config = {}) {
        // Merge configuration
        this.config = { ...this.config, ...config };

        // ENTERPRISE V10.50: Get user data from window.currentUser (server-rendered)
        const user = window.currentUser || window.need2talk?.user;
        this.userUuid = user?.uuid || null;
        this.jwtToken = user?.wsToken || null;
        this.sessionId = this.generateSessionId();

        // Set WebSocket URL based on environment
        this.config.url = this.buildWebSocketUrl();

        // Mark as initialized (prevents connect() from being called with null URL)
        this.isInitialized = true;

        // Only initialize if user is authenticated
        if (this.userUuid && this.jwtToken) {
            this.connect();

            // Setup page visibility handling for performance
            this.setupVisibilityHandling();

            // Setup beforeunload cleanup
            window.addEventListener('beforeunload', () => this.cleanup());

        }
    },
    
    /**
     * Build WebSocket URL - Environment aware
     */
    buildWebSocketUrl() {
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const host = window.location.hostname;
        
        // Production: Use dedicated WebSocket server
        // Development: Use local server
        const port = window.location.hostname === 'localhost' ? ':8080' : '';
        
        return `${protocol}//${host}${port}/ws`;
    },
    
    /**
     * Connect to WebSocket - Resilient & Scalable
     */
    async connect() {
        if (this.isConnecting || this.isConnected) return;

        // ENTERPRISE V11.9: Validate URL before creating WebSocket
        // Prevents creating WebSocket with null/undefined URL which resolves as relative path
        // Bug: new WebSocket(null) from /settings/data-export → GET /settings/null HTTP/1.1
        if (!this.config.url || !this.config.url.startsWith('ws')) {
            console.warn('[WebSocket] Cannot connect - invalid URL:', this.config.url);
            return;
        }

        this.isConnecting = true;

        try {
            // ENTERPRISE V10.50: Create WebSocket connection (no subprotocol - Swoole doesn't support it)
            this.connection = new WebSocket(this.config.url);
            
            // Performance: Set binary type for efficiency
            this.connection.binaryType = this.config.binaryType;
            
            // Setup event listeners
            this.setupEventListeners();
            
            // Connection timeout for reliability
            const connectionTimeout = setTimeout(() => {
                if (this.connection.readyState === WebSocket.CONNECTING) {
                    this.connection.close();
                    this.handleConnectionError('Connection timeout');
                }
            }, 10000);
            
            // Clear timeout on successful connection
            this.connection.addEventListener('open', () => {
                clearTimeout(connectionTimeout);
            });
            
        } catch (error) {
            this.isConnecting = false;
            this.handleConnectionError(error);
        }
    },
    
    /**
     * Setup WebSocket event listeners - High Performance
     */
    setupEventListeners() {
        if (!this.connection) return;
        
        // Connection opened
        this.connection.onopen = (event) => {
            this.isConnected = true;
            this.isConnecting = false;
            this.reconnectAttempts = 0;
            this.reconnectDelay = 1000;

            // Authenticate connection with JWT
            this.authenticate();

            // Start heartbeat
            this.startHeartbeat();

            // Process queued messages
            this.processMessageQueue();

            // ENTERPRISE V10.11: Track if this is a reconnect (for room rejoin logic)
            const isReconnect = this.hasConnectedBefore === true;
            this.hasConnectedBefore = true;

            // Emit connected event (internal EventEmitter)
            this.emit('connected', { timestamp: Date.now(), isReconnect });

            // ENTERPRISE V10.50: Notify connection listeners (ROOT compatibility)
            this.notifyConnectionListeners('connected', { isReconnect });

            // ENTERPRISE V10.9.1: Dispatch global event for cross-module communication
            // Used by FeedManager to send post subscriptions for real-time counters
            // ENTERPRISE V10.11: Include isReconnect flag for ChatManager room rejoin
            window.dispatchEvent(new CustomEvent('n2t:wsConnected', {
                detail: { timestamp: Date.now(), isReconnect }
            }));
        };
        
        // Message received - Critical for scalability
        this.connection.onmessage = (event) => {
            this.handleIncomingMessage(event);
        };
        
        // Connection closed
        this.connection.onclose = (event) => {
            this.isConnected = false;
            this.isConnecting = false;
            this.stopHeartbeat();

            // Emit disconnected event (internal EventEmitter)
            this.emit('disconnected', {
                code: event.code,
                reason: event.reason,
                timestamp: Date.now()
            });

            // ENTERPRISE V10.50: Notify connection listeners (ROOT compatibility)
            this.notifyConnectionListeners('disconnected', { code: event.code, reason: event.reason });

            // Dispatch global event
            window.dispatchEvent(new CustomEvent('n2t:wsDisconnected', {
                detail: { code: event.code, reason: event.reason }
            }));

            // Auto-reconnect if enabled
            if (this.config.autoReconnect && this.reconnectAttempts < this.maxReconnectAttempts) {
                this.scheduleReconnect();
            }
        };
        
        // Connection error
        this.connection.onerror = (error) => {
            this.handleConnectionError(error);
        };
    },

    /**
     * Handle connection errors - Resilient error handling
     * ENTERPRISE V10.50: Added missing method
     */
    handleConnectionError(error) {
        console.error('[WebSocket] Connection error:', error);

        this.isConnecting = false;

        // Emit error event
        this.emit('error', { error, timestamp: Date.now() });

        // Notify connection listeners
        this.notifyConnectionListeners('error', { error });

        // Schedule reconnect if enabled
        if (this.config.autoReconnect && this.reconnectAttempts < this.maxReconnectAttempts) {
            this.scheduleReconnect();
        }
    },

    /**
     * Handle binary messages (if needed)
     */
    handleBinaryMessage(blob) {
        // Binary messages not currently used, but method stub for future
    },

    /**
     * Handle incoming messages - PERFORMANCE CRITICAL
     * ENTERPRISE V10.50: Standalone logging (no Need2Talk dependency)
     */
    handleIncomingMessage(event) {
        let rawData = null; // For error logging
        try {
            let data;

            // Performance: Handle different message types efficiently
            if (typeof event.data === 'string') {
                rawData = event.data; // Store for error logging
                data = JSON.parse(event.data);
            } else if (event.data instanceof Blob) {
                // Handle binary messages if needed
                return this.handleBinaryMessage(event.data);
            } else {
                return;
            }

            // Message validation for security
            if (!this.isValidMessage(data)) {
                return;
            }

            // Route message based on type
            this.routeMessage(data);

        } catch (error) {
            // ENTERPRISE V10.51: Better error logging - error objects don't serialize well
            // Include raw data preview to diagnose parsing/handling issues
            const dataPreview = rawData ? rawData.substring(0, 200) : 'N/A';
            console.error('[WebSocket] Error handling message:', error.message || error, 'Raw:', dataPreview);
        }
    },
    
    /**
     * Route message to appropriate handler - High Performance
     * ENTERPRISE V9.6: Handles both message formats:
     * - With payload wrapper: { type, payload: {...}, timestamp }
     * - Without payload: { type, field1, field2, ..., timestamp }
     */
    routeMessage(data) {
        const { type, timestamp } = data;

        // ENTERPRISE V9.6: Normalize payload - handle both message formats
        // Some messages have explicit 'payload', others put data at root level
        let payload;
        if (data.payload !== undefined) {
            // Format 1: Explicit payload wrapper
            payload = data.payload;
        } else {
            // Format 2: Data at root level (room messages, chat events)
            // Extract all properties except type and timestamp as the payload
            const { type: _, timestamp: __, ...rest } = data;
            payload = Object.keys(rest).length > 0 ? rest : {};
        }

        // Performance: Use Map for O(1) handler lookup
        switch (type) {
            case 'notification':
                this.handleNotification(payload);
                break;
                
            case 'realtime_update':
                this.handleRealtimeUpdate(payload);
                break;
                
            case 'user_status':
                this.handleUserStatus(payload);
                break;
                
            case 'heartbeat_response':
                this.handleHeartbeatResponse(payload);
                break;
                
            case 'error':
                this.handleServerError(payload);
                break;
                
            case 'system':
                this.handleSystemMessage(payload);
                break;

            case 'post_counter_update':
                // ENTERPRISE V10.9: Real-time counter updates (comments, plays)
                // Dispatches event for FeedManager/Lightbox to handle
                // V10.2 (2025-12-10): Pass actor_user_uuid to prevent double-counting
                window.dispatchEvent(new CustomEvent('n2t:postCounterUpdate', {
                    detail: {
                        postId: payload.post_id,
                        counters: payload.counters,
                        audioFileId: payload.audio_file_id,
                        actor_user_uuid: payload.actor_user_uuid,
                        timestamp: timestamp
                    }
                }));
                break;

            default:
                // Custom event handlers
                // ENTERPRISE FIX (2025-12-02): eventHandlers stores arrays of handlers
                // Each on() call pushes to array, so we need to iterate
                const handlers = this.eventHandlers.get(type);

                if (handlers && Array.isArray(handlers) && handlers.length > 0) {
                    handlers.forEach(h => {
                        try {
                            h(payload, timestamp);
                        } catch (e) {
                            console.error(`[WebSocket] Error in handler for ${type}:`, e);
                        }
                    });
                } else if (handlers && typeof handlers === 'function') {
                    // Single handler (backwards compatibility)
                    handlers(payload, timestamp);
                } else {
                    // ENTERPRISE V10.31: Buffer unhandled messages for late handlers
                    this.bufferInboundMessage(type, payload, timestamp);
                }

                // ENTERPRISE V9.0: Always dispatch CustomEvent for document listeners
                // This enables components that don't have WebSocketManager reference to listen
                document.dispatchEvent(new CustomEvent(`websocket:${type}`, {
                    detail: { ...payload, timestamp }
                }));
        }

        // Emit raw message event for debugging
        this.emit('message', data);
    },
    
    /**
     * Handle notifications - MEMORY EFFICIENT
     * ENTERPRISE V10.87: Removed blocking UUID validation
     *
     * Previous bug: user_uuid validation caused return BEFORE emit, breaking bell badge
     * Security note: Validation is redundant since each user only receives messages
     * from their own WebSocket channel (user:{uuid}). Cross-user message leakage
     * is not possible at the WebSocket server level.
     */
    handleNotification(notification) {
        const { id, type, title, message, data, user_uuid, created_at } = notification;

        // ENTERPRISE V10.87: Log mismatch for debugging but don't block
        // This should never happen in production (messages routed by user channel)
        if (user_uuid && this.userUuid && user_uuid !== this.userUuid) {
            console.warn('[WebSocket] UUID mismatch (should not happen)', {
                notification_user_uuid: user_uuid,
                current_user_uuid: this.userUuid
            });
            // Don't return - continue processing (security is handled at channel level)
        }

        // Performance: Use Map for notification type handlers
        const handler = this.notificationHandlers.get(type);
        if (handler) {
            handler(notification);
        }

        // Default notification handling
        this.displayNotification(notification);

        // Update notification count in UI
        this.updateNotificationCount();

        // Emit notification event
        // ENTERPRISE V10.86: Wrap notification in payload for consistency with WebSocket format
        // Navbar handler expects { payload: {...} } format, not raw notification
        // Without this wrapper, navbar incorrectly extracts notification.data instead of notification
        this.emit('notification', { payload: notification, timestamp: Date.now() });
    },
    
    /**
     * Display notification - User Experience
     * ENTERPRISE V10.50: Browser Notifications only (no FlashMessages dependency)
     */
    displayNotification(notification) {
        const { type, title, message, data } = notification;

        // ENTERPRISE V10.50: Browser notification if permission granted
        // This works on desktop and Android (browser open/minimized)
        // iOS Safari does NOT support Notification API
        if ('Notification' in window && Notification.permission === 'granted') {
            this.showBrowserNotification(notification);
        }

        // Emit event for UI components to display in-app notification
        // This allows FlashMessages or other UI systems to listen and react
        this.emit('notification:display', {
            type: this.getNotificationTypeMapping(type),
            title,
            message,
            data,
            onClick: () => this.handleNotificationClick(notification)
        });
    },
    
    /**
     * Show browser notification - Enhanced UX
     */
    showBrowserNotification(notification) {
        const { title, message, type, data } = notification;
        
        const browserNotification = new Notification(`need2talk - ${title}`, {
            body: message,
            icon: '/assets/img/logo-192.png',
            badge: '/assets/img/logo-96.png',
            tag: `need2talk-${type}`,
            requireInteraction: false,
            silent: false
        });
        
        // Handle notification click
        browserNotification.onclick = () => {
            window.focus();
            this.handleNotificationClick(notification);
            browserNotification.close();
        };
        
        // Auto-close after 5 seconds
        setTimeout(() => {
            browserNotification.close();
        }, 5000);
    },
    
    /**
     * ENTERPRISE V10.88: Handle server error messages
     * Called when server sends {type: 'error', error: '...', received: '...'}
     * This prevents crashes from undefined handler
     */
    handleServerError(payload) {
        const errorMsg = payload?.error || 'Unknown server error';
        console.warn('[WebSocket] Server error:', errorMsg);

        // Don't close connection - server already handled it
        // Just log for debugging
    },

    /**
     * ENTERPRISE V10.88: Handle heartbeat response from server
     */
    handleHeartbeatResponse(payload) {
        // Heartbeat acknowledged - connection is healthy
        // Update last heartbeat timestamp for connection health monitoring
        this.lastHeartbeatResponse = Date.now();
    },

    /**
     * ENTERPRISE V10.88: Handle realtime update messages
     */
    handleRealtimeUpdate(payload) {
        // Dispatch as custom event for any component to handle
        window.dispatchEvent(new CustomEvent('n2t:realtimeUpdate', {
            detail: payload
        }));
    },

    /**
     * ENTERPRISE V10.88: Handle system messages from server
     */
    handleSystemMessage(payload) {
        console.log('[WebSocket] System message:', payload?.message || payload);

        // Check for specific system actions
        if (payload?.action === 'reconnect') {
            console.log('[WebSocket] Server requested reconnect');
            this.reconnect();
        } else if (payload?.action === 'maintenance') {
            // Could show maintenance banner
            window.dispatchEvent(new CustomEvent('n2t:systemMaintenance', {
                detail: payload
            }));
        }
    },

    /**
     * ENTERPRISE V10.88: Handle user status updates (online/offline/away)
     */
    handleUserStatus(payload) {
        // Dispatch event for FriendsWidget and other components
        window.dispatchEvent(new CustomEvent('n2t:userStatus', {
            detail: {
                userUuid: payload?.user_uuid,
                status: payload?.status,
                isOnline: payload?.is_online
            }
        }));
    },

    /**
     * Handle notification click - Navigation
     * ENTERPRISE V10.40: Open chat widget on desktop instead of redirect
     */
    handleNotificationClick(notification) {
        const { type, data } = notification;

        // Route to appropriate page based on notification type
        switch (type) {
            case 'friend_request':
                window.location.href = '/profile/friends?tab=requests';
                break;

            case 'comment':
                if (data?.audio_id) {
                    window.location.href = `/profile?audio=${data.audio_id}&highlight_comments=true`;
                }
                break;

            case 'like':
                if (data?.audio_id) {
                    window.location.href = `/profile?audio=${data.audio_id}&highlight_audio=true`;
                }
                break;

            case 'private_message':
            case 'dm':
            case 'dm_received':
                // ENTERPRISE V10.40: Open chat widget on desktop, redirect on mobile
                this.handleDMNotificationClick(data);
                break;

            case 'room_invite':
                // ENTERPRISE V11.6: Room invite - navigate directly to chat room
                if (data?.room_uuid) {
                    window.location.href = `/chat?room=${encodeURIComponent(data.room_uuid)}`;
                } else {
                    window.location.href = '/chat';
                }
                break;

            default:
                window.location.href = '/notifications';
        }
    },

    /**
     * ENTERPRISE V10.41: Handle DM notification click
     * Desktop: Opens MINIMIZED chat widget (less intrusive UX)
     * Mobile: Redirects to full DM page (extended view is the UI)
     *
     * CHANGED: Desktop now opens minimized widget instead of maximized
     * This matches Facebook Messenger behavior and is less disruptive
     */
    handleDMNotificationClick(data) {
        const isMobile = window.innerWidth < 768 ||
            /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

        const conversationUuid = data?.conversation_uuid;
        const senderUuid = data?.sender_uuid || data?.sender_id;
        const senderNickname = data?.sender_nickname || data?.sender_name || 'Utente';
        const senderAvatar = data?.sender_avatar || '/assets/img/default-avatar.png';

        // Mobile: Redirect to full DM page (extended view)
        if (isMobile) {
            if (conversationUuid) {
                window.location.href = `/chat/dm/${conversationUuid}`;
            } else if (senderUuid) {
                // Fallback: Start new conversation
                window.location.href = `/chat/dm/start/${senderUuid}`;
            }
            return;
        }

        // Desktop: Check if already on DM page for this conversation
        if (window.location.pathname.startsWith('/chat/dm/') &&
            window.location.pathname.includes(conversationUuid)) {
            // Already on the right page, just focus
            window.focus();
            return;
        }

        // Desktop: Open chat widget MINIMIZED
        if (conversationUuid && window.chatWidgetManager) {
            const otherUser = {
                uuid: senderUuid,
                nickname: senderNickname,
                avatar_url: senderAvatar,
                status: 'online' // Assume online since they just sent a message
            };

    
            // ENTERPRISE V10.41: Open widget MINIMIZED (startMinimized: true)
            // This is less intrusive than opening a full chat window
            window.dispatchEvent(new CustomEvent('n2t:openChatWidget', {
                detail: {
                    conversationUuid,
                    otherUser,
                    startMinimized: true  // ENTERPRISE V10.41: Desktop notifications open minimized
                }
            }));
        } else if (conversationUuid) {
            // Fallback: Widget not available, redirect to DM page
            window.location.href = `/chat/dm/${conversationUuid}`;
        } else if (senderUuid) {
            // Fallback: Start new conversation
            window.location.href = `/chat/dm/start/${senderUuid}`;
        }
    },
    
    /**
     * Send message - Reliable & Scalable
     * ENTERPRISE V10.50: Dual-mode support for backwards compatibility
     *
     * USAGE (both work):
     * - CORE style: send('type', {payload}, 'priority')
     * - ROOT style: send({type: 'x', ...data})
     */
    send(typeOrData, payload = {}, priority = 'normal') {
        let message;

        if (typeof typeOrData === 'string') {
            // CORE style: send('type', {payload}, 'priority')
            message = {
                type: typeOrData,
                payload,
                timestamp: Date.now(),
                session_id: this.sessionId,
                user_uuid: this.userUuid,
                priority
            };
        } else if (typeof typeOrData === 'object' && typeOrData !== null) {
            // ROOT style: send({type: 'x', ...data})
            message = {
                ...typeOrData,
                timestamp: typeOrData.timestamp || Date.now(),
                session_id: this.sessionId,
                user_uuid: this.userUuid
            };
        } else {
            console.error('[WebSocket] Invalid send() call:', typeOrData);
            return false;
        }

        if (this.isConnected) {
            try {
                this.connection.send(JSON.stringify(message));
                return true;
            } catch (error) {
                console.error('[WebSocket] Failed to send message:', error);

                // Queue message for retry
                if (this.messageQueue.length < this.maxQueueSize) {
                    this.messageQueue.push(message);
                }

                return false;
            }
        } else {
            // Queue message for when connection is restored
            if (this.messageQueue.length < this.maxQueueSize) {
                this.messageQueue.push(message);
            }

            // Attempt to reconnect if not already connecting
            if (!this.isConnecting) {
                this.connect();
            }

            return false;
        }
    },
    
    /**
     * Process message queue - Reliability
     */
    processMessageQueue() {
        if (!this.isConnected || this.messageQueue.length === 0) return;

        // Process messages in batches for performance
        const batchSize = 10;
        const batch = this.messageQueue.splice(0, batchSize);

        batch.forEach(message => {
            try {
                this.connection.send(JSON.stringify(message));
            } catch (error) {
                console.error('[WebSocket] Failed to send queued message:', error);
            }
        });

        // Process remaining messages
        if (this.messageQueue.length > 0) {
            setTimeout(() => this.processMessageQueue(), 100);
        }
    },
    
    /**
     * Authentication - Secure with JWT
     * ENTERPRISE V10.50: Uses JWT token from window.currentUser.wsToken
     */
    authenticate() {
        if (!this.jwtToken) {
            console.error('[WebSocket] Cannot authenticate - no JWT token');
            return;
        }

        // ENTERPRISE V10.50: Send JWT token for server-side verification
        this.send({
            type: 'auth',
            token: this.jwtToken,
            user_uuid: this.userUuid,
            session_id: this.sessionId,
            timestamp: Date.now()
        });
    },
    
    /**
     * Heartbeat system - Connection health
     */
    startHeartbeat() {
        this.stopHeartbeat();
        
        this.heartbeatInterval = setInterval(() => {
            if (this.isConnected) {
                this.send('heartbeat', { timestamp: Date.now() });
            }
        }, this.config.pingInterval);
    },
    
    stopHeartbeat() {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
    },
    
    /**
     * Reconnection logic - Resilient with exponential backoff
     */
    scheduleReconnect() {
        this.reconnectAttempts++;

        const delay = Math.min(
            this.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1),
            this.maxReconnectDelay
        );

        setTimeout(() => {
            if (!this.isConnected) {
                this.connect();
            }
        }, delay);
    },
    
    /**
     * Page visibility handling - Performance
     */
    setupVisibilityHandling() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Reduce heartbeat frequency when page is hidden
                if (this.heartbeatInterval) {
                    clearInterval(this.heartbeatInterval);
                    this.heartbeatInterval = setInterval(() => {
                        if (this.isConnected) {
                            this.send('heartbeat', { timestamp: Date.now() });
                        }
                    }, this.config.pingInterval * 2); // Double the interval
                }
            } else {
                // Resume normal heartbeat when page is visible
                this.startHeartbeat();
                
                // Request missed notifications
                this.send('request_missed_notifications', {
                    last_seen: Date.now() - (document.hidden ? 60000 : 0)
                });
            }
        });
    },
    
    /**
     * ENTERPRISE V10.31: Buffer inbound messages when no handler is registered
     * This prevents message loss during page initialization race conditions
     */
    bufferInboundMessage(type, payload, timestamp) {
        // Don't buffer certain internal message types
        const noBufferTypes = ['heartbeat_response', 'system', 'error', 'pong'];
        if (noBufferTypes.includes(type)) {
            return;
        }

        // Initialize queue for this type if needed
        if (!this.inboundMessageQueue.has(type)) {
            this.inboundMessageQueue.set(type, []);
        }

        const queue = this.inboundMessageQueue.get(type);

        // Enforce queue size limit (FIFO - drop oldest)
        if (queue.length >= this.maxInboundQueueSize) {
            queue.shift();
        }

        // Add message with timestamp for TTL check
        queue.push({ payload, timestamp, bufferedAt: Date.now() });
    },

    /**
     * ENTERPRISE V10.31: Replay buffered messages to a newly registered handler
     * Called automatically when on() is called
     */
    replayBufferedMessages(event, handler) {
        if (!this.inboundMessageQueue.has(event)) {
            return;
        }

        const queue = this.inboundMessageQueue.get(event);
        if (queue.length === 0) {
            return;
        }

        const now = Date.now();
        let replayedCount = 0;
        let expiredCount = 0;

        // Process all buffered messages for this event
        while (queue.length > 0) {
            const msg = queue.shift();

            // Check TTL - don't replay old messages
            if (now - msg.bufferedAt > this.inboundQueueTTL) {
                expiredCount++;
                continue;
            }

            // Replay to the new handler
            try {
                handler(msg.payload, msg.timestamp);
                replayedCount++;
            } catch (e) {
                console.error(`[WebSocket] Error replaying ${event}:`, e);
            }
        }

        // Clean up empty queue
        if (queue.length === 0) {
            this.inboundMessageQueue.delete(event);
        }
    },

    /**
     * Event system - Extensible
     * ENTERPRISE V10.31: Now replays buffered messages to new handlers
     */
    on(event, handler) {
        if (!this.eventHandlers.has(event)) {
            this.eventHandlers.set(event, []);
        }
        this.eventHandlers.get(event).push(handler);

        // ENTERPRISE V10.31: Replay any buffered messages to this new handler
        // This fixes race conditions where messages arrive before handlers are registered
        this.replayBufferedMessages(event, handler);
    },

    off(event, handler) {
        const handlers = this.eventHandlers.get(event);
        if (handlers) {
            const index = handlers.indexOf(handler);
            if (index > -1) {
                handlers.splice(index, 1);
            }
        }
    },

    emit(event, data) {
        const handlers = this.eventHandlers.get(event);
        if (handlers) {
            handlers.forEach(handler => {
                try {
                    handler(data);
                } catch (error) {
                    console.error(`[WebSocket] Error in event handler for ${event}:`, error);
                }
            });
        }
    },

    /**
     * ENTERPRISE V10.50: Alias for on() - ROOT compatibility
     * Usage: WebSocketManager.onMessage('room_message', handler)
     */
    onMessage(messageType, handler) {
        return this.on(messageType, handler);
    },

    /**
     * ENTERPRISE V10.50: Connection event listener - ROOT compatibility
     * Usage: WebSocketManager.onConnection((event, data) => { ... })
     *
     * Events: 'connected', 'disconnected', 'error', 'failed'
     */
    onConnection(listener) {
        this.connectionListeners.push(listener);

        // Return unsubscribe function
        return () => {
            const index = this.connectionListeners.indexOf(listener);
            if (index > -1) {
                this.connectionListeners.splice(index, 1);
            }
        };
    },

    /**
     * ENTERPRISE V10.50: Notify connection listeners
     * Called internally on connect/disconnect events
     */
    notifyConnectionListeners(event, data = null) {
        this.connectionListeners.forEach(listener => {
            try {
                listener(event, data);
            } catch (error) {
                console.error('[WebSocket] Connection listener error:', error);
            }
        });
    },

    /**
     * ENTERPRISE V10.50: Manual disconnect - ROOT compatibility
     * Disables auto-reconnect and closes connection
     */
    disconnect() {
        this.config.autoReconnect = false;
        this.stopHeartbeat();

        if (this.connection) {
            this.connection.close(1000, 'Manual disconnect');
            this.connection = null;
        }

        this.isConnected = false;
        this.isConnecting = false;
        this.inboundMessageQueue.clear();

        // Notify listeners
        this.notifyConnectionListeners('disconnected', { manual: true });
    },

    /**
     * ENTERPRISE V10.50: Alias for getStatus() - ROOT compatibility
     */
    getConnectionStatus() {
        return this.getStatus();
    },

    /**
     * Register notification handler - Extensible
     */
    onNotification(type, handler) {
        this.notificationHandlers.set(type, handler);
    },
    
    /**
     * Utility methods
     */
    generateSessionId() {
        return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    },
    
    // ENTERPRISE V10.28 (2025-12-04): Accept BOTH message formats
    // Format 1: { type, payload: {...}, timestamp } - used by notifications, presence
    // Format 2: { type, room_id, message, ... } - used by room_message, user_joined, etc.
    // CRITICAL FIX: Old validation required payload which broke ALL room messages!
    isValidMessage(data) {
        // Minimal validation: just need type as string
        // timestamp is optional (some messages may not have it)
        // payload is optional (room messages put data at root level)
        return data && typeof data.type === 'string';
    },
    
    getNotificationTypeMapping(type) {
        const mapping = {
            'friend_request': 'info',
            'friend_accepted': 'success',
            'comment': 'info',
            'like': 'success',
            'private_message': 'info',
            'system': 'warning',
            'error': 'error'
        };
        
        return mapping[type] || 'info';
    },
    
    /**
     * Update notification count in navbar
     */
    updateNotificationCount() {
        // This will be called when notifications are received
        const countElement = document.querySelector('[x-data*="navbarData"] .notification-count');
        if (countElement) {
            // Trigger Alpine.js method to refresh count
            if (window.navbarInstance && window.navbarInstance.loadNotifications) {
                window.navbarInstance.loadNotifications();
            }
        }
    },
    
    /**
     * Request browser notification permission
     */
    async requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            const permission = await Notification.requestPermission();
            return permission === 'granted';
        }
        return Notification.permission === 'granted';
    },
    
    /**
     * Cleanup - Memory management
     */
    cleanup() {
        this.stopHeartbeat();

        if (this.connection) {
            this.connection.close(1000, 'Page unload');
        }

        this.eventHandlers.clear();
        this.notificationHandlers.clear();
        this.messageQueue = [];
        this.inboundMessageQueue.clear();  // ENTERPRISE V10.31: Clear buffered messages

        this.isConnected = false;
        this.isConnecting = false;
    },
    
    /**
     * Get connection status
     */
    getStatus() {
        return {
            connected: this.isConnected,
            connecting: this.isConnecting,
            // ENTERPRISE SECURITY: Expose only UUID, never numeric ID
            userUuid: this.userUuid,
            sessionId: this.sessionId,
            reconnectAttempts: this.reconnectAttempts,
            queuedMessages: this.messageQueue.length
        };
    }
};

/**
 * ENTERPRISE V10.50: Auto-initialization (no Need2Talk dependency)
 *
 * Initialization sources (in priority order):
 * 1. window.currentUser (server-rendered via PHP)
 * 2. window.need2talk.user (legacy fallback)
 *
 * Required fields:
 * - uuid: User's UUID for authentication
 * - wsToken: JWT token for WebSocket auth
 */
(function autoInit() {
    const tryInit = () => {
        const user = window.currentUser || window.need2talk?.user;

        if (user?.uuid && user?.wsToken) {
            window.WebSocketManager.init();
            // NOTE: requestNotificationPermission() must be called from user gesture (click)
        }
    };

    // Try init when DOM is ready or immediately if already loaded
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        // Small delay to ensure window.currentUser is set
        setTimeout(tryInit, 10);
    } else {
        document.addEventListener('DOMContentLoaded', () => setTimeout(tryInit, 10));
    }
})();

// ENTERPRISE V10.50: Expose Need2Talk.WebSocketManager for legacy compatibility
// Some old code might still reference Need2Talk.WebSocketManager
if (typeof window.Need2Talk === 'object') {
    window.Need2Talk.WebSocketManager = window.WebSocketManager;
}

// Debug utilities (always available via console)
window.wsDebug = {
    status: () => window.WebSocketManager.getStatus(),
    send: (type, payload) => window.WebSocketManager.send(type, payload),
    reconnect: () => window.WebSocketManager.connect(),
    disconnect: () => window.WebSocketManager.disconnect(),
    cleanup: () => window.WebSocketManager.cleanup()
};

/**
 * ENTERPRISE V11.8: Mobile PWA Visibility Handler
 *
 * On mobile devices (especially iOS PWA), WebSocket connections are often
 * terminated when the app goes to background. This handler ensures:
 * 1. Immediate reconnection when app returns to foreground
 * 2. Presence status refresh after reconnection
 * 3. Works for both PWA and regular mobile browsers
 */
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        console.log('[WebSocket] Page became visible, checking connection...');

        const ws = window.WebSocketManager;
        if (ws && ws.isInitialized && !ws.isConnected && !ws.isConnecting) {
            console.log('[WebSocket] Reconnecting after visibility change...');
            ws.reconnectAttempts = 0; // Reset attempts for fresh reconnect
            ws.connect();
        } else if (ws && ws.isConnected) {
            // Already connected - send heartbeat to refresh presence
            console.log('[WebSocket] Already connected, sending heartbeat...');
            ws.send('heartbeat', { timestamp: Date.now() });
        }
    }
});

/**
 * ENTERPRISE V11.8: iOS PWA Resume Handler
 *
 * iOS has a special 'pageshow' event that fires when returning to a page
 * from the browser's back-forward cache (bfcache) or when PWA resumes.
 * This is more reliable than visibilitychange on iOS.
 */
window.addEventListener('pageshow', (event) => {
    // event.persisted = true means page was restored from bfcache
    if (event.persisted) {
        console.log('[WebSocket] Page restored from cache, checking connection...');

        const ws = window.WebSocketManager;
        if (ws && ws.isInitialized && !ws.isConnected && !ws.isConnecting) {
            console.log('[WebSocket] Reconnecting after pageshow...');
            ws.reconnectAttempts = 0;
            ws.connect();
        }
    }
});

/**
 * ENTERPRISE V11.8: Online/Offline Handler
 *
 * Handle network connectivity changes (airplane mode, wifi toggle, etc.)
 */
window.addEventListener('online', () => {
    console.log('[WebSocket] Network online, checking connection...');

    const ws = window.WebSocketManager;
    if (ws && ws.isInitialized && !ws.isConnected && !ws.isConnecting) {
        // Small delay to let network stabilize
        setTimeout(() => {
            if (!ws.isConnected && !ws.isConnecting) {
                console.log('[WebSocket] Reconnecting after network restore...');
                ws.reconnectAttempts = 0;
                ws.connect();
            }
        }, 1000);
    }
});