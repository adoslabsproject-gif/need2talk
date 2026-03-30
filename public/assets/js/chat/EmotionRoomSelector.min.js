/**
 * EmotionRoomSelector.js - 10 Emotion Rooms UI Component
 *
 * ENTERPRISE V9.0 (2025-12-02):
 * - Compact 5x2 grid layout (5 positive on top, 5 negative below)
 * - Dark theme integrated with need2talk design
 * - Real-time online counts
 * - Accessibility support
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @version 2.0.0
 */

class EmotionRoomSelector {
    // Emotion rooms organized by category (positive first, then negative)
    // Synchronized with database `emotions` table (10 emotions)
    static EMOTION_ROOMS = [
        // ROW 1: Positive emotions (IDs 1-5)
        { id: 'emotion:joy', emoji: '😊', name: 'Gioia', color: '#FFD700', category: 'positive', description: 'Condividi i tuoi momenti felici' },
        { id: 'emotion:wonder', emoji: '✨', name: 'Meraviglia', color: '#FF6B35', category: 'positive', description: 'Stupore e incanto' },
        { id: 'emotion:love', emoji: '❤️', name: 'Amore', color: '#FF1493', category: 'positive', description: 'Celebra l\'amore' },
        { id: 'emotion:gratitude', emoji: '🙏', name: 'Gratitudine', color: '#32CD32', category: 'positive', description: 'Riconoscenza e apprezzamento' },
        { id: 'emotion:hope', emoji: '🌟', name: 'Speranza', color: '#87CEEB', category: 'positive', description: 'Trova luce nel domani' },

        // ROW 2: Negative emotions (IDs 6-10)
        { id: 'emotion:sadness', emoji: '😢', name: 'Tristezza', color: '#4682B4', category: 'negative', description: 'Non sei solo nel dolore' },
        { id: 'emotion:anger', emoji: '😠', name: 'Rabbia', color: '#DC143C', category: 'negative', description: 'Sfoga la tua frustrazione' },
        { id: 'emotion:anxiety', emoji: '😰', name: 'Ansia', color: '#FF8C00', category: 'negative', description: 'Parliamo delle tue preoccupazioni' },
        { id: 'emotion:fear', emoji: '😨', name: 'Paura', color: '#8B008B', category: 'negative', description: 'Affronta le tue paure' },
        { id: 'emotion:loneliness', emoji: '😔', name: 'Solitudine', color: '#696969', category: 'negative', description: 'Connettiti con altri' },
    ];

    #container = null;
    #rooms = [];
    #selectedRoom = null;
    #chatManager = null;
    #updateInterval = null;

    /**
     * Create emotion room selector
     * @param {HTMLElement|string} container - Container element or selector
     * @param {Object} options
     */
    constructor(container, options = {}) {
        this.#container = typeof container === 'string'
            ? document.querySelector(container)
            : container;

        if (!this.#container) {
            throw new Error('EmotionRoomSelector: Container not found');
        }

        this.#chatManager = options.chatManager || window.ChatManager?.getInstance();

        this.#init();
    }

    /**
     * Initialize component
     */
    async #init() {
        // Show loading state
        this.#renderLoading();

        // Get fresh room data from server
        await this.#loadRooms();

        // Render UI
        this.#render();

        // Setup event listeners
        this.#setupEventListeners();

        // Start polling for online counts (every 30s)
        this.#startOnlineCountUpdates();
    }

    /**
     * Render loading skeleton
     * ENTERPRISE V9.6: Uses .emotion-grid CSS class for responsive layout
     */
    #renderLoading() {
        this.#container.innerHTML = `
            <div class="emotion-grid">
                ${Array(10).fill(0).map(() => `
                    <div class="emotion-card opacity-50 animate-pulse">
                        <div class="w-7 h-7 bg-gray-600 rounded-full mb-1"></div>
                        <div class="h-2 w-10 bg-gray-600 rounded"></div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Load rooms from server
     */
    async #loadRooms() {
        try {
            const response = await fetch('/api/chat/rooms/emotions');
            const data = await response.json();

            if (data.success && data.data?.rooms) {
                // Merge server data with static config (preserving order)
                this.#rooms = EmotionRoomSelector.EMOTION_ROOMS.map(room => {
                    const serverRoom = data.data.rooms.find(r => r.id === room.id);
                    return {
                        ...room,
                        online_count: serverRoom?.online_count || 0
                    };
                });
            } else {
                this.#rooms = EmotionRoomSelector.EMOTION_ROOMS.map(r => ({ ...r, online_count: 0 }));
            }
        } catch (error) {
            console.error('[EmotionRoomSelector] Failed to load rooms:', error);
            this.#rooms = EmotionRoomSelector.EMOTION_ROOMS.map(r => ({ ...r, online_count: 0 }));
        }
    }

    /**
     * Render the component
     * ENTERPRISE V9.6: Uses .emotion-grid CSS class for responsive layout
     */
    #render() {
        this.#container.innerHTML = `
            <div class="emotion-grid">
                ${this.#rooms.map(room => this.#renderRoomCard(room)).join('')}
            </div>
        `;
    }

    /**
     * Render single room card
     * ENTERPRISE V9.6: Uses .emotion-card CSS class with emoji, name, count
     * @param {Object} room
     * @returns {string}
     */
    #renderRoomCard(room) {
        const isSelected = this.#selectedRoom === room.id;

        return `
            <button class="emotion-card ${isSelected ? 'selected' : ''}"
                    data-room-id="${room.id}"
                    data-room-emoji="${room.emoji}"
                    data-room-name="${room.name}"
                    data-room-description="${room.description}"
                    aria-pressed="${isSelected}"
                    aria-label="Entra nella stanza ${room.name}"
                    title="${room.name} - ${room.description}">
                <span class="emoji">${room.emoji}</span>
                <span class="name">${room.name}</span>
                <span class="count room-online-badge" data-room-id="${room.id}">
                    <span class="dot ${room.online_count > 0 ? 'active' : ''}"></span>
                    ${room.online_count}
                </span>
            </button>
        `;
    }

    /**
     * Setup event listeners
     */
    #setupEventListeners() {
        // Room card clicks
        this.#container.addEventListener('click', (e) => {
            const card = e.target.closest('.emotion-card');
            if (card) {
                const roomId = card.dataset.roomId;
                this.#selectRoom(roomId);
            }
        });

        // Keyboard support
        this.#container.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                const card = e.target.closest('.emotion-card');
                if (card) {
                    e.preventDefault();
                    this.#selectRoom(card.dataset.roomId);
                }
            }
        });

        // Listen for external room join/leave events
        document.addEventListener('chat:room_joined', (e) => {
            this.#updateSelectedRoom(e.detail.roomId);
        });

        document.addEventListener('chat:room_left', () => {
            this.#updateSelectedRoom(null);
        });

        document.addEventListener('chat:user_joined', (e) => {
            this.#updateOnlineCount(e.detail.room_id, e.detail.online_count);
        });

        document.addEventListener('chat:user_left', (e) => {
            this.#updateOnlineCount(e.detail.room_id, e.detail.online_count);
        });

        // ENTERPRISE V9.0 (2025-12-02): Dedicated online count update event
        // This is the primary event for real-time online count updates
        // More reliable than user_joined/left since it's explicitly for count updates
        document.addEventListener('chat:online_count_updated', (e) => {
            this.#updateOnlineCount(e.detail.roomId, e.detail.count);
        });
    }

    /**
     * Select a room
     * @param {string} roomId
     */
    async #selectRoom(roomId) {
        if (this.#selectedRoom === roomId) return;

        const previousRoom = this.#selectedRoom;
        this.#selectedRoom = roomId;

        // Update UI immediately
        this.#updateSelectedRoom(roomId);

        // Get room data for event
        const room = this.#rooms.find(r => r.id === roomId);

        // ENTERPRISE: Dispatch event BEFORE joining to show loading skeleton
        document.dispatchEvent(new CustomEvent('chat:room_loading', {
            detail: {
                roomId,
                room,
                previousRoom
            }
        }));

        // Join room via ChatManager
        if (this.#chatManager) {
            try {
                await this.#chatManager.joinRoom(roomId, 'emotion');
            } catch (error) {
                console.error('[EmotionRoomSelector] Failed to join room:', error);
                this.#selectedRoom = previousRoom;
                this.#updateSelectedRoom(previousRoom);

                // Dispatch error event
                document.dispatchEvent(new CustomEvent('chat:room_join_failed', {
                    detail: { roomId, error: error.message }
                }));
            }
        }
    }

    /**
     * Update selected room UI
     * @param {string|null} roomId
     */
    #updateSelectedRoom(roomId) {
        this.#selectedRoom = roomId;

        // Update card classes
        const cards = this.#container.querySelectorAll('.emotion-card');
        cards.forEach(card => {
            const isSelected = card.dataset.roomId === roomId;
            card.classList.toggle('selected', isSelected);
            card.setAttribute('aria-pressed', isSelected);
        });
    }

    /**
     * Update online count for a room
     * @param {string} roomId
     * @param {number} count
     */
    #updateOnlineCount(roomId, count) {
        const badge = this.#container.querySelector(`.room-online-badge[data-room-id="${roomId}"]`);
        if (badge) {
            badge.innerHTML = `
                <span class="dot ${count > 0 ? 'active' : ''}"></span>
                ${count}
            `;
        }

        // Update cached room data
        const room = this.#rooms.find(r => r.id === roomId);
        if (room) {
            room.online_count = count;
        }
    }

    /**
     * Start polling for online counts
     */
    #startOnlineCountUpdates() {
        this.#updateInterval = setInterval(async () => {
            await this.#loadRooms();

            // Update all badges
            this.#rooms.forEach(room => {
                this.#updateOnlineCount(room.id, room.online_count);
            });
        }, 30000);
    }

    /**
     * Get room info by ID
     * @param {string} roomId
     * @returns {Object|null}
     */
    getRoomInfo(roomId) {
        return this.#rooms.find(r => r.id === roomId) || null;
    }

    /**
     * Destroy component
     */
    destroy() {
        if (this.#updateInterval) {
            clearInterval(this.#updateInterval);
        }
        this.#container.innerHTML = '';
    }

    // Getters
    get selectedRoom() { return this.#selectedRoom; }
    get rooms() { return [...this.#rooms]; }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = EmotionRoomSelector;
}

window.EmotionRoomSelector = EmotionRoomSelector;
