/**
 * TypingIndicator.js - Enterprise Typing Status Component
 *
 * Displays real-time typing indicators for chat rooms and DMs.
 * Handles multiple concurrent typers with graceful UI.
 *
 * FEATURES:
 * - Auto-expire indicators (3s timeout, synced with Redis TTL)
 * - Multiple typers support (up to 3 names, then "X people typing")
 * - Animated dot indicator
 * - Smooth transitions
 * - Memory efficient (Map-based tracking)
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @version 1.0.0
 */

class TypingIndicator {
    static TYPING_TIMEOUT = 3500;  // 3.5s (slightly longer than server TTL for smooth UX)
    static MAX_NAMES_SHOWN = 3;

    #container = null;
    #typers = new Map();  // userUuid -> { username, displayName, expireTimer }
    #elements = {};
    #config = {};

    /**
     * Create TypingIndicator instance
     * @param {HTMLElement|string} container
     * @param {Object} config
     */
    constructor(container, config = {}) {
        this.#container = typeof container === 'string'
            ? document.querySelector(container)
            : container;

        if (!this.#container) {
            throw new Error('TypingIndicator: Container not found');
        }

        this.#config = {
            showAvatar: config.showAvatar !== false,
            maxUsers: config.maxUsers || TypingIndicator.MAX_NAMES_SHOWN,
            locale: config.locale || 'it',
            ...config,
        };

        this.#render();
    }

    // ========================================================================
    // RENDERING
    // ========================================================================

    #render() {
        this.#container.innerHTML = `
            <div class="n2t-typing-indicator" style="display: none;" aria-live="polite" aria-atomic="true">
                <div class="n2t-typing-dots">
                    <span class="n2t-typing-dot"></span>
                    <span class="n2t-typing-dot"></span>
                    <span class="n2t-typing-dot"></span>
                </div>
                <span class="n2t-typing-text"></span>
            </div>
        `;

        this.#elements = {
            root: this.#container.querySelector('.n2t-typing-indicator'),
            dots: this.#container.querySelector('.n2t-typing-dots'),
            text: this.#container.querySelector('.n2t-typing-text'),
        };
    }

    #updateDisplay() {
        const count = this.#typers.size;

        if (count === 0) {
            this.#elements.root.style.display = 'none';
            this.#elements.root.setAttribute('aria-hidden', 'true');
            return;
        }

        // Build text
        const names = Array.from(this.#typers.values())
            .map(t => t.displayName || t.username || 'Utente')
            .slice(0, this.#config.maxUsers);

        let text;

        if (count === 1) {
            text = `${names[0]} sta scrivendo...`;
        } else if (count === 2) {
            text = `${names[0]} e ${names[1]} stanno scrivendo...`;
        } else if (count === 3) {
            text = `${names[0]}, ${names[1]} e ${names[2]} stanno scrivendo...`;
        } else {
            text = `${names.slice(0, 2).join(', ')} e altri ${count - 2} stanno scrivendo...`;
        }

        this.#elements.text.textContent = text;
        this.#elements.root.style.display = 'flex';
        this.#elements.root.setAttribute('aria-hidden', 'false');
    }

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    /**
     * Add or refresh a typing user
     * @param {string} userUuid
     * @param {Object} userData - { username, displayName, avatar }
     */
    addTyper(userUuid, userData = {}) {
        // Clear existing timer if present
        const existing = this.#typers.get(userUuid);
        if (existing?.expireTimer) {
            clearTimeout(existing.expireTimer);
        }

        // Set new timer
        const expireTimer = setTimeout(() => {
            this.removeTyper(userUuid);
        }, TypingIndicator.TYPING_TIMEOUT);

        this.#typers.set(userUuid, {
            username: userData.username || userData.display_name || 'Utente',
            displayName: userData.displayName || userData.display_name || userData.username,
            avatar: userData.avatar || null,
            expireTimer,
            timestamp: Date.now(),
        });

        this.#updateDisplay();
    }

    /**
     * Remove a typing user
     * @param {string} userUuid
     */
    removeTyper(userUuid) {
        const typer = this.#typers.get(userUuid);
        if (typer) {
            if (typer.expireTimer) {
                clearTimeout(typer.expireTimer);
            }
            this.#typers.delete(userUuid);
            this.#updateDisplay();
        }
    }

    /**
     * Clear all typers
     */
    clear() {
        for (const [uuid, typer] of this.#typers) {
            if (typer.expireTimer) {
                clearTimeout(typer.expireTimer);
            }
        }
        this.#typers.clear();
        this.#updateDisplay();
    }

    /**
     * Get count of active typers
     * @returns {number}
     */
    getCount() {
        return this.#typers.size;
    }

    /**
     * Check if specific user is typing
     * @param {string} userUuid
     * @returns {boolean}
     */
    isTyping(userUuid) {
        return this.#typers.has(userUuid);
    }

    /**
     * Get all typing users
     * @returns {Array}
     */
    getTypers() {
        return Array.from(this.#typers.entries()).map(([uuid, data]) => ({
            uuid,
            username: data.username,
            displayName: data.displayName,
            avatar: data.avatar,
        }));
    }

    /**
     * Set custom text formatter
     * @param {Function} formatter - (names, count) => string
     */
    setFormatter(formatter) {
        this.#customFormatter = formatter;
    }

    #customFormatter = null;

    /**
     * Update from WebSocket event
     * @param {Object} event - { user_uuid, username, display_name, is_typing }
     */
    handleEvent(event) {
        if (event.is_typing) {
            this.addTyper(event.user_uuid, {
                username: event.username,
                displayName: event.display_name,
                avatar: event.avatar,
            });
        } else {
            this.removeTyper(event.user_uuid);
        }
    }

    /**
     * Set multiple typers at once (replaces current list)
     * ENTERPRISE V9.2: Used by chat:typing_update event
     * @param {Array} typers - [{ uuid, username, display_name, avatar }]
     */
    setTypers(typers = []) {
        // Clear all existing typers first
        this.clear();

        // Add new typers
        for (const typer of typers) {
            if (typer.uuid) {
                this.addTyper(typer.uuid, {
                    username: typer.username || typer.display_name,
                    displayName: typer.display_name || typer.username,
                    avatar: typer.avatar,
                });
            }
        }
    }

    /**
     * Destroy component and clean up
     */
    destroy() {
        this.clear();
        this.#container.innerHTML = '';
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = TypingIndicator;
}

window.TypingIndicator = TypingIndicator;

