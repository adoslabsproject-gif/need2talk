/**
 * UserPresence.js - Enterprise Presence & Online Status System
 *
 * Manages real-time user presence with status badges, online counts,
 * and user list updates for rooms.
 *
 * FEATURES:
 * - Real-time presence updates via WebSocket
 * - Status badges (online, away, busy, offline)
 * - Online user count tracking per room
 * - User list management with virtual scrolling
 * - Last seen timestamps
 * - Heartbeat coordination
 *
 * STATUS TYPES:
 * - online: Actively using chat
 * - away: Idle (no activity for 5 min)
 * - busy: Do not disturb
 * - offline: Not connected
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @version 1.0.0
 */

class UserPresence {
    static STATUSES = {
        ONLINE: 'online',
        AWAY: 'away',
        BUSY: 'busy',
        OFFLINE: 'offline',
    };

    static STATUS_CONFIG = {
        online: { label: 'Online', color: '#22c55e', priority: 1 },
        away: { label: 'Assente', color: '#eab308', priority: 2 },
        busy: { label: 'Occupato', color: '#ef4444', priority: 3 },
        offline: { label: 'Offline', color: '#6b7280', priority: 4 },
    };

    static HEARTBEAT_INTERVAL = 30000;  // 30s
    static STALE_THRESHOLD = 60000;     // 1 min without heartbeat = offline

    #users = new Map();  // userUuid -> { status, lastSeen, username, avatar, ... }
    #rooms = new Map();  // roomId -> Set<userUuid>
    #callbacks = new Map();
    #heartbeatTimer = null;
    #currentUserUuid = null;
    #currentStatus = 'online';

    constructor(config = {}) {
        this.#currentUserUuid = config.userUuid || null;

        if (config.autoHeartbeat !== false) {
            this.#startHeartbeat();
        }
    }

    // ========================================================================
    // HEARTBEAT MANAGEMENT
    // ========================================================================

    #startHeartbeat() {
        if (this.#heartbeatTimer) return;

        this.#heartbeatTimer = setInterval(() => {
            this.#sendHeartbeat();
            this.#cleanupStaleUsers();
        }, UserPresence.HEARTBEAT_INTERVAL);

        // Send initial heartbeat
        this.#sendHeartbeat();
    }

    #stopHeartbeat() {
        if (this.#heartbeatTimer) {
            clearInterval(this.#heartbeatTimer);
            this.#heartbeatTimer = null;
        }
    }

    async #sendHeartbeat() {
        try {
            // CSRF token is automatically added by csrf.js fetch wrapper - DO NOT add manually
            const response = await fetch('/api/chat/presence/heartbeat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    status: this.#currentStatus,
                }),
            });

            if (!response.ok) {
                console.warn('[UserPresence] Heartbeat failed:', response.status);
            }
        } catch (error) {
            console.warn('[UserPresence] Heartbeat error:', error);
        }
    }

    #cleanupStaleUsers() {
        const now = Date.now();
        const staleUuids = [];

        for (const [uuid, user] of this.#users) {
            if (uuid !== this.#currentUserUuid &&
                user.lastSeen &&
                now - user.lastSeen > UserPresence.STALE_THRESHOLD) {

                if (user.status !== 'offline') {
                    user.status = 'offline';
                    staleUuids.push(uuid);
                }
            }
        }

        // Emit updates for stale users
        for (const uuid of staleUuids) {
            this.#emit('statusChange', {
                userUuid: uuid,
                status: 'offline',
                user: this.#users.get(uuid),
            });
        }
    }

    // ========================================================================
    // USER MANAGEMENT
    // ========================================================================

    /**
     * Update user presence
     * @param {string} userUuid
     * @param {Object} data - { status, username, displayName, avatar, ... }
     */
    updateUser(userUuid, data = {}) {
        const existing = this.#users.get(userUuid) || {};
        const wasOnline = existing.status === 'online';
        const isNowOnline = (data.status || existing.status) === 'online';

        const user = {
            ...existing,
            uuid: userUuid,
            username: data.username || existing.username,
            displayName: data.displayName || data.display_name || existing.displayName,
            avatar: data.avatar || existing.avatar,
            status: data.status || existing.status || 'online',
            lastSeen: Date.now(),
        };

        this.#users.set(userUuid, user);

        // Emit status change
        if (existing.status !== user.status) {
            this.#emit('statusChange', { userUuid, status: user.status, user });
        }

        // Emit online/offline events
        if (!wasOnline && isNowOnline) {
            this.#emit('userOnline', { userUuid, user });
        } else if (wasOnline && !isNowOnline) {
            this.#emit('userOffline', { userUuid, user });
        }

        return user;
    }

    /**
     * Remove user from tracking
     * @param {string} userUuid
     */
    removeUser(userUuid) {
        const user = this.#users.get(userUuid);
        if (user) {
            this.#users.delete(userUuid);
            this.#emit('userOffline', { userUuid, user });

            // Remove from all rooms
            for (const [roomId, members] of this.#rooms) {
                members.delete(userUuid);
            }
        }
    }

    /**
     * Get user data
     * @param {string} userUuid
     * @returns {Object|null}
     */
    getUser(userUuid) {
        return this.#users.get(userUuid) || null;
    }

    /**
     * Get user status
     * @param {string} userUuid
     * @returns {string}
     */
    getStatus(userUuid) {
        return this.#users.get(userUuid)?.status || 'offline';
    }

    /**
     * Set current user's status
     * @param {string} status
     */
    setMyStatus(status) {
        if (!Object.values(UserPresence.STATUSES).includes(status)) {
            console.warn('[UserPresence] Invalid status:', status);
            return;
        }

        this.#currentStatus = status;

        if (this.#currentUserUuid) {
            this.updateUser(this.#currentUserUuid, { status });
        }

        // Send heartbeat immediately with new status
        this.#sendHeartbeat();
    }

    // ========================================================================
    // ROOM MANAGEMENT
    // ========================================================================

    /**
     * Add user to room
     * @param {string} roomId
     * @param {string} userUuid
     * @param {Object} userData
     */
    addToRoom(roomId, userUuid, userData = {}) {
        if (!this.#rooms.has(roomId)) {
            this.#rooms.set(roomId, new Set());
        }

        this.#rooms.get(roomId).add(userUuid);
        this.updateUser(userUuid, { ...userData, status: 'online' });

        this.#emit('roomMemberJoined', { roomId, userUuid, user: this.#users.get(userUuid) });
        this.#emit('roomCountChanged', { roomId, count: this.getRoomOnlineCount(roomId) });
    }

    /**
     * Remove user from room
     * @param {string} roomId
     * @param {string} userUuid
     */
    removeFromRoom(roomId, userUuid) {
        const members = this.#rooms.get(roomId);
        if (members) {
            members.delete(userUuid);

            if (members.size === 0) {
                this.#rooms.delete(roomId);
            }

            this.#emit('roomMemberLeft', { roomId, userUuid, user: this.#users.get(userUuid) });
            this.#emit('roomCountChanged', { roomId, count: this.getRoomOnlineCount(roomId) });
        }
    }

    /**
     * Get online count for room
     * @param {string} roomId
     * @returns {number}
     */
    getRoomOnlineCount(roomId) {
        const members = this.#rooms.get(roomId);
        if (!members) return 0;

        let count = 0;
        for (const uuid of members) {
            const user = this.#users.get(uuid);
            if (user && user.status === 'online') {
                count++;
            }
        }
        return count;
    }

    /**
     * Get all members of a room
     * @param {string} roomId
     * @param {boolean} onlineOnly
     * @returns {Array}
     */
    getRoomMembers(roomId, onlineOnly = false) {
        const members = this.#rooms.get(roomId);
        if (!members) return [];

        const result = [];
        for (const uuid of members) {
            const user = this.#users.get(uuid);
            if (user && (!onlineOnly || user.status === 'online')) {
                result.push(user);
            }
        }

        // Sort by status priority then by name
        return result.sort((a, b) => {
            const priorityA = UserPresence.STATUS_CONFIG[a.status]?.priority || 99;
            const priorityB = UserPresence.STATUS_CONFIG[b.status]?.priority || 99;

            if (priorityA !== priorityB) return priorityA - priorityB;
            return (a.displayName || a.username || '').localeCompare(b.displayName || b.username || '');
        });
    }

    /**
     * Set room members (bulk update)
     * @param {string} roomId
     * @param {Array} members - [{ uuid, username, displayName, avatar, status }]
     */
    setRoomMembers(roomId, members) {
        this.#rooms.set(roomId, new Set());

        for (const member of members) {
            this.#rooms.get(roomId).add(member.uuid);
            this.updateUser(member.uuid, member);
        }

        this.#emit('roomMembersUpdated', { roomId, members: this.getRoomMembers(roomId) });
        this.#emit('roomCountChanged', { roomId, count: this.getRoomOnlineCount(roomId) });
    }

    // ========================================================================
    // UI COMPONENTS
    // ========================================================================

    /**
     * Create status badge HTML
     * @param {string} status
     * @param {Object} options
     * @returns {string}
     */
    static createStatusBadge(status, options = {}) {
        const config = UserPresence.STATUS_CONFIG[status] || UserPresence.STATUS_CONFIG.offline;
        const size = options.size || 'sm';  // sm, md, lg
        const showLabel = options.showLabel === true;

        const sizeClasses = {
            sm: 'w-2 h-2',
            md: 'w-3 h-3',
            lg: 'w-4 h-4',
        };

        return `
            <span class="n2t-status-badge n2t-status-${status}"
                  title="${config.label}"
                  aria-label="Stato: ${config.label}">
                <span class="n2t-status-dot ${sizeClasses[size] || sizeClasses.sm}"
                      style="background-color: ${config.color}">
                </span>
                ${showLabel ? `<span class="n2t-status-label">${config.label}</span>` : ''}
            </span>
        `;
    }

    /**
     * Create user avatar with status
     * @param {Object} user
     * @param {Object} options
     * @returns {string}
     */
    static createUserAvatar(user, options = {}) {
        const size = options.size || 40;
        const showStatus = options.showStatus !== false;
        const status = user.status || 'offline';

        const initials = UserPresence.#getInitials(user.displayName || user.username || 'U');
        const avatarUrl = user.avatar;

        return `
            <div class="n2t-user-avatar" style="width: ${size}px; height: ${size}px;">
                ${avatarUrl
                    ? `<img src="${UserPresence.#escapeHtml(avatarUrl)}"
                           alt="${UserPresence.#escapeHtml(user.displayName || user.username)}"
                           class="n2t-avatar-img">`
                    : `<div class="n2t-avatar-initials">${initials}</div>`
                }
                ${showStatus ? UserPresence.createStatusBadge(status, { size: 'sm' }) : ''}
            </div>
        `;
    }

    /**
     * Create online users list HTML
     * @param {Array} users
     * @param {Object} options
     * @returns {string}
     */
    static createUserList(users, options = {}) {
        const maxVisible = options.maxVisible || 10;
        const showCount = options.showCount !== false;

        const visible = users.slice(0, maxVisible);
        const remaining = users.length - visible.length;

        return `
            <div class="n2t-user-list">
                ${showCount ? `
                    <div class="n2t-user-list-header">
                        <span class="n2t-online-count">${users.filter(u => u.status === 'online').length} online</span>
                    </div>
                ` : ''}
                <div class="n2t-user-list-items">
                    ${visible.map(user => `
                        <div class="n2t-user-list-item" data-uuid="${user.uuid}">
                            ${UserPresence.createUserAvatar(user, { size: 32 })}
                            <span class="n2t-user-name">${UserPresence.#escapeHtml(user.displayName || user.username)}</span>
                        </div>
                    `).join('')}
                    ${remaining > 0 ? `
                        <div class="n2t-user-list-more">
                            +${remaining} altri
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }

    /**
     * Create online count badge HTML
     * @param {number} count
     * @returns {string}
     */
    static createOnlineCountBadge(count) {
        return `
            <span class="n2t-online-badge" aria-label="${count} utenti online">
                <span class="n2t-online-dot"></span>
                <span class="n2t-online-count">${count}</span>
            </span>
        `;
    }

    /**
     * Format last seen timestamp
     * @param {number} timestamp
     * @returns {string}
     */
    static formatLastSeen(timestamp) {
        if (!timestamp) return 'Mai';

        const diff = Date.now() - timestamp;
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);

        if (seconds < 60) return 'Adesso';
        if (minutes < 60) return `${minutes} min fa`;
        if (hours < 24) return `${hours} ore fa`;
        if (days === 1) return 'Ieri';
        if (days < 7) return `${days} giorni fa`;

        return new Date(timestamp).toLocaleDateString('it-IT');
    }

    // ========================================================================
    // EVENTS
    // ========================================================================

    /**
     * Register event callback
     * @param {string} event
     * @param {Function} callback
     */
    on(event, callback) {
        if (!this.#callbacks.has(event)) {
            this.#callbacks.set(event, new Set());
        }
        this.#callbacks.get(event).add(callback);
        return this;
    }

    /**
     * Remove event callback
     * @param {string} event
     * @param {Function} callback
     */
    off(event, callback) {
        const callbacks = this.#callbacks.get(event);
        if (callbacks) {
            callbacks.delete(callback);
        }
        return this;
    }

    #emit(event, data) {
        const callbacks = this.#callbacks.get(event);
        if (callbacks) {
            for (const callback of callbacks) {
                try {
                    callback(data);
                } catch (error) {
                    console.error(`[UserPresence] Event callback error (${event}):`, error);
                }
            }
        }
    }

    /**
     * Handle WebSocket presence event
     * @param {Object} event
     */
    handleEvent(event) {
        switch (event.type) {
            case 'presence_update':
                this.updateUser(event.user_uuid, {
                    username: event.username,
                    displayName: event.display_name,
                    avatar: event.avatar,
                    status: event.status,
                });
                break;

            case 'user_joined':
                if (event.room_id) {
                    this.addToRoom(event.room_id, event.user_uuid, {
                        username: event.username,
                        displayName: event.display_name,
                        avatar: event.avatar,
                    });
                }
                break;

            case 'user_left':
                if (event.room_id) {
                    this.removeFromRoom(event.room_id, event.user_uuid);
                }
                break;

            case 'room_members':
                if (event.room_id && event.members) {
                    this.setRoomMembers(event.room_id, event.members);
                }
                break;
        }
    }

    // ========================================================================
    // UTILITIES
    // ========================================================================

    static #getInitials(name) {
        if (!name) return 'U';
        const parts = name.trim().split(/\s+/);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    }

    static #escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Get all online users count
     * @returns {number}
     */
    getOnlineCount() {
        let count = 0;
        for (const user of this.#users.values()) {
            if (user.status === 'online') count++;
        }
        return count;
    }

    /**
     * Destroy and cleanup
     */
    destroy() {
        this.#stopHeartbeat();
        this.#users.clear();
        this.#rooms.clear();
        this.#callbacks.clear();
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = UserPresence;
}

window.UserPresence = UserPresence;

