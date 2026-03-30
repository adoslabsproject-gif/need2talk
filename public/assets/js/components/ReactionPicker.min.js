/**
 * need2talk - ReactionPicker Component
 * Enterprise Galaxy V4 - Emotional Reactions System
 *
 * Purpose: Interactive 10-emotion reaction picker for audio posts
 * Features: Optimistic UI, silent rate limiting (backend), accessibility
 *
 * V4 Design (2025-11-26):
 * - Inline emotions: Top 4 popular + user's reaction with individual counters
 * - Dropdown [+]: Grid of all 10 emotions (icons only, no counters)
 * - Silent abuse detection: No UI warning, backend handles rate limiting
 *
 * Performance: <50ms render, <100ms API response
 * Scalability: 100,000+ concurrent users, 1M+ reactions/day
 */

class ReactionPicker {
    /**
     * @param {Object} options Configuration options
     * @param {string} options.audioId Audio post ID
     * @param {string} options.containerId Container element ID
     * @param {Object} options.initialStats Initial reaction stats {emotion_id: count}
     * @param {number|null} options.userReaction User's current reaction (emotion_id or null)
     * @param {Function} options.onReactionChange Callback when reaction changes
     */
    constructor(options) {
        this.audioId = options.audioId;
        this.containerId = options.containerId;
        this.stats = options.initialStats || {};
        this.userReaction = options.userReaction || null;
        this.onReactionChange = options.onReactionChange || (() => {});

        // State
        this.isProcessing = false;
        this.recentClicks = []; // Track recent clicks for abuse detection
        // ENTERPRISE GALAXY (2025-11-21): Intelligent rate limiting
        // Detects REAL abuse (5+ clicks in 2 seconds) vs normal usage (1 click every 1-2s)
        this.abuseThreshold = 5; // Max clicks in window
        this.abuseWindow = 2000; // Time window (2 seconds)

        // Emotion definitions (10 emotions: 5 positive, 5 negative)
        this.emotions = {
            // Positive emotions (1-5)
            1: { name: 'Gioia', icon: '😊', color: 'text-yellow-400', bgHover: 'hover:bg-yellow-400/10' },
            2: { name: 'Meraviglia', icon: '✨', color: 'text-orange-400', bgHover: 'hover:bg-orange-400/10' },
            3: { name: 'Amore', icon: '❤️', color: 'text-red-400', bgHover: 'hover:bg-red-400/10' },
            4: { name: 'Gratitudine', icon: '🙏', color: 'text-green-400', bgHover: 'hover:bg-green-400/10' },
            5: { name: 'Speranza', icon: '🌟', color: 'text-blue-400', bgHover: 'hover:bg-blue-400/10' },

            // Negative emotions (6-10)
            6: { name: 'Tristezza', icon: '😢', color: 'text-blue-300', bgHover: 'hover:bg-blue-300/10' },
            7: { name: 'Rabbia', icon: '😠', color: 'text-red-500', bgHover: 'hover:bg-red-500/10' },
            8: { name: 'Ansia', icon: '😰', color: 'text-purple-400', bgHover: 'hover:bg-purple-400/10' },
            9: { name: 'Paura', icon: '😨', color: 'text-gray-400', bgHover: 'hover:bg-gray-400/10' },
            10: { name: 'Solitudine', icon: '😔', color: 'text-indigo-400', bgHover: 'hover:bg-indigo-400/10' },
        };

        this.init();
    }

    /**
     * Initialize component
     */
    init() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.error(`ReactionPicker: Container #${this.containerId} not found`);
            return;
        }

        this.render(container);
        this.attachEventListeners(container);
    }

    /**
     * Render reaction picker UI
     *
     * ENTERPRISE GALAXY V4 (2025-11-26): Inline emotions with counters
     * - Shows top 4 emotions inline with individual counters below each
     * - User's reaction always shown (even if not in top 4)
     * - [+] button opens dropdown with ALL 10 emotions (icons only, no counters)
     * - Clean, Instagram-like design
     */
    render(container) {
        // Get emotions with counts, sorted by count descending
        const emotionsWithCounts = Object.entries(this.stats)
            .map(([id, count]) => ({ id: parseInt(id, 10), count }))
            .filter(e => e.count > 0)
            .sort((a, b) => b.count - a.count);

        // Get top 4 emotions to show inline
        let inlineEmotions = emotionsWithCounts.slice(0, 4);

        // Ensure user's reaction is always visible (add if not in top 4)
        if (this.userReaction && !inlineEmotions.find(e => e.id === this.userReaction)) {
            const userCount = this.stats[this.userReaction] || 1;
            inlineEmotions = inlineEmotions.slice(0, 3); // Make room
            inlineEmotions.push({ id: this.userReaction, count: userCount });
        }

        // Build inline emotions HTML
        // ENTERPRISE V4: Counters are only clickable if user has that reaction (to remove it)
        // Otherwise, use [+] button to add a new reaction
        const inlineEmotionsHtml = inlineEmotions.map(({ id, count }) => {
            const emotion = this.emotions[id];
            const isActive = this.userReaction === id;

            // Only clickable if it's the user's reaction (to remove it)
            const isClickable = isActive;
            const activeClasses = isActive
                ? 'ring-2 ring-purple-500 bg-purple-500/20 cursor-pointer'
                : 'cursor-default opacity-80';
            const hoverClasses = isActive ? 'hover:bg-purple-500/30' : '';

            return `
                <div
                    class="reaction-inline-btn flex flex-col items-center px-2 py-1 rounded-lg transition-all duration-150 ${activeClasses} ${hoverClasses}"
                    data-emotion-id="${id}"
                    data-clickable="${isClickable}"
                    title="${isActive ? 'Rimuovi ' + emotion.name : emotion.name + ': ' + count + ' reazioni'}"
                    aria-label="${emotion.name}: ${count} reazioni${isActive ? ' (clicca per rimuovere)' : ''}">
                    <span class="text-xl">${emotion.icon}</span>
                    <span class="text-xs ${emotion.color} font-medium mt-0.5">${count}</span>
                </div>
            `;
        }).join('');

        // Build dropdown items (all 10 emotions, icons only - no counters)
        const dropdownItems = Object.entries(this.emotions)
            .map(([emotionId, emotion]) => {
                const isActive = this.userReaction == emotionId;
                const activeClass = isActive ? 'bg-purple-600/30 ring-1 ring-purple-500' : 'hover:bg-white/10';

                return `
                    <button
                        type="button"
                        class="reaction-dropdown-item flex items-center justify-center p-2 rounded-lg transition-all duration-150 ${activeClass}"
                        data-emotion-id="${emotionId}"
                        title="${emotion.name}"
                        aria-label="${emotion.name}">
                        <span class="text-2xl">${emotion.icon}</span>
                    </button>
                `;
            })
            .join('');

        container.innerHTML = `
            <!-- ENTERPRISE V11.6: Removed isolation:isolate - using position:fixed for dropdown instead -->
            <div class="reaction-picker-wrapper relative">
                <!-- ENTERPRISE GALAXY V4: Inline emotions bar -->
                <div class="flex items-center space-x-1">
                    ${inlineEmotionsHtml}

                    <!-- [+] More button - opens dropdown (HIDDEN if user already has a reaction) -->
                    ${!this.userReaction ? `
                    <button
                        type="button"
                        id="reaction-trigger-${this.audioId}"
                        class="reaction-more-btn flex flex-col items-center px-2 py-1 rounded-lg transition-all duration-150 hover:bg-white/5 text-gray-400 hover:text-gray-200"
                        aria-label="Altre emozioni"
                        title="Mostra tutte le emozioni">
                        <span class="text-xl">➕</span>
                    </button>
                    ` : ''}
                </div>

                <!-- ENTERPRISE V11.6: Dropdown is PORTALED to body via JS (see attachEventListeners) -->
                <!-- This completely escapes backdrop-blur stacking context issues in PWA/Safari -->
            </div>
        `;
    }

    /**
     * Attach event listeners to dropdown and reaction buttons
     *
     * ENTERPRISE V11.6 (2025-12-13): Portal pattern for PWA/Safari fix
     * - Dropdown is created in document.body (escapes ALL stacking contexts)
     * - Position calculated dynamically relative to trigger button
     * - Cleanup handled in destroy() method
     */
    attachEventListeners(container) {
        const trigger = container.querySelector(`#reaction-trigger-${this.audioId}`);

        // ENTERPRISE: If user already has a reaction, trigger doesn't exist (by design)
        // Only inline emotion buttons (with user's reaction) are shown

        // 1. Inline emotion buttons - only clickable if user has that reaction (to remove it)
        const inlineButtons = container.querySelectorAll('.reaction-inline-btn');
        inlineButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                // ENTERPRISE V4: Only handle click if it's the user's own reaction
                const isClickable = btn.dataset.clickable === 'true';
                if (!isClickable) {
                    return; // Ignore click on non-user reactions
                }

                const emotionId = parseInt(btn.dataset.emotionId, 10);
                this.handleReactionClick(emotionId); // Will trigger removal since it's the same reaction
            });
        });

        // 2. [+] Trigger button - create and show PORTAL dropdown
        if (trigger && !this.userReaction) {
            // ENTERPRISE V11.6: Create dropdown in body (PORTAL pattern)
            // This completely escapes backdrop-blur stacking context issues
            this._createPortalDropdown();

            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const dialog = document.getElementById(`reaction-dropdown-portal-${this.audioId}`);
                if (!dialog) return;

                // ENTERPRISE V11.8: Use showModal() for top-layer rendering
                if (!dialog.open) {
                    // Position dialog relative to trigger
                    const triggerRect = trigger.getBoundingClientRect();
                    const dropdownWidth = 220;
                    const dropdownHeight = 160;

                    let top = triggerRect.bottom + 8;
                    let left = triggerRect.left;

                    const viewportWidth = window.innerWidth;
                    const viewportHeight = window.innerHeight;

                    if (left + dropdownWidth > viewportWidth - 16) {
                        left = viewportWidth - dropdownWidth - 16;
                    }
                    if (top + dropdownHeight > viewportHeight - 16) {
                        top = triggerRect.top - dropdownHeight - 8;
                    }
                    if (left < 16) left = 16;

                    // Set position before showing
                    dialog.style.position = 'fixed';
                    dialog.style.top = `${top}px`;
                    dialog.style.left = `${left}px`;
                    dialog.style.margin = '0'; // Override default dialog centering

                    dialog.showModal();
                } else {
                    dialog.close();
                }
            });

            // 3. Close dialog on outside click (handled by dialog backdrop click)
            // Note: <dialog> handles this natively via backdrop click in _createPortalDropdown

            // 4. ENTERPRISE V11.7: Close dialog on scroll
            const scrollHandler = () => {
                const dialog = document.getElementById(`reaction-dropdown-portal-${this.audioId}`);
                if (dialog && dialog.open) {
                    dialog.close();
                }
            };

            // Remove previous scroll handler if exists
            if (this._scrollHandler) {
                window.removeEventListener('scroll', this._scrollHandler, true);
            }

            this._scrollHandler = scrollHandler;
            // Use capture phase to catch scroll on any scrollable container
            window.addEventListener('scroll', scrollHandler, true);
        }
    }

    /**
     * Create portal dropdown using <dialog> element
     * ENTERPRISE V11.8: Uses native <dialog> top-layer API
     * This renders ABOVE everything, even backdrop-filter elements in PWA/Safari
     */
    _createPortalDropdown() {
        // Remove existing dropdown if any
        const existingDropdown = document.getElementById(`reaction-dropdown-portal-${this.audioId}`);
        if (existingDropdown) {
            existingDropdown.remove();
        }

        // Build dropdown items HTML
        const dropdownItems = Object.entries(this.emotions)
            .map(([emotionId, emotion]) => {
                const isActive = this.userReaction == emotionId;
                const activeClass = isActive ? 'bg-purple-600/30 ring-1 ring-purple-500' : 'hover:bg-white/10';

                return `
                    <button
                        type="button"
                        class="reaction-dropdown-item flex items-center justify-center p-2 rounded-lg transition-all duration-150 ${activeClass}"
                        data-emotion-id="${emotionId}"
                        title="${emotion.name}"
                        aria-label="${emotion.name}">
                        <span class="text-2xl">${emotion.icon}</span>
                    </button>
                `;
            })
            .join('');

        // ENTERPRISE V11.8: Use <dialog> element for top-layer rendering
        // <dialog> is rendered in browser's "top layer" - above ALL stacking contexts
        const dialog = document.createElement('dialog');
        dialog.id = `reaction-dropdown-portal-${this.audioId}`;
        dialog.className = 'reaction-dialog-portal';
        dialog.style.cssText = `
            padding: 0;
            border: none;
            background: transparent;
            max-width: none;
            max-height: none;
            overflow: visible;
        `;

        // Inject backdrop style if not already present
        if (!document.getElementById('reaction-dialog-style')) {
            const style = document.createElement('style');
            style.id = 'reaction-dialog-style';
            style.textContent = `
                .reaction-dialog-portal::backdrop {
                    background: transparent;
                }
            `;
            document.head.appendChild(style);
        }
        dialog.innerHTML = `
            <div class="p-3 bg-gray-800 rounded-xl border border-purple-500/30 shadow-2xl" style="min-width: 220px;">
                <p class="text-xs text-gray-400 uppercase tracking-wider mb-2 text-center">Come ti fa sentire?</p>
                <div class="grid grid-cols-5 gap-1">
                    ${dropdownItems}
                </div>
            </div>
        `;

        // Append to body
        document.body.appendChild(dialog);

        // Attach click handlers to dropdown items
        dialog.querySelectorAll('.reaction-dropdown-item').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const emotionId = parseInt(btn.dataset.emotionId, 10);
                this.handleReactionClick(emotionId);

                // Close dialog
                dialog.close();
            });
        });

        // Close on click outside (on backdrop)
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog) {
                dialog.close();
            }
        });

        // Close on Escape key (native dialog behavior)
        // Already handled by <dialog> natively
    }

    /**
     * Handle reaction button click
     *
     * @param {number} emotionId Emotion ID (1-10)
     */
    async handleReactionClick(emotionId) {
        // ENTERPRISE: Intelligent abuse detection
        const now = Date.now();

        // Add current click to history
        this.recentClicks.push(now);

        // Remove clicks older than abuse window
        this.recentClicks = this.recentClicks.filter(time => now - time < this.abuseWindow);

        // ENTERPRISE GALAXY V4: Silent abuse detection - block but no UI warning
        // Backend handles rate limiting, frontend just silently ignores rapid clicks
        if (this.recentClicks.length > this.abuseThreshold) {
            return; // Silently ignore - no warning shown
        }

        // Prevent double-clicking (API in progress)
        if (this.isProcessing) {
            return;
        }

        // If clicking same emotion, remove reaction
        if (this.userReaction === emotionId) {
            this.handleRemoveReaction();
            return;
        }

        this.isProcessing = true;

        // Optimistic UI update
        const previousReaction = this.userReaction;
        const previousStats = { ...this.stats };

        this.updateUIOptimistic(emotionId);

        // API call
        try {
            const result = await this.reactToAudio(emotionId);

            if (result.success) {
                // ENTERPRISE V4: REPLACE stats entirely (server is source of truth)
                // Server returns complete overlay stats - no merge needed
                if (result.stats) {
                    // Filter out 0 counts and use server stats directly
                    this.stats = {};
                    Object.keys(result.stats).forEach(key => {
                        if (result.stats[key] > 0) {
                            this.stats[key] = result.stats[key];
                        }
                    });
                }
                this.userReaction = emotionId;

                // Re-render to sync with server
                this.refreshUI();

                // Callback
                this.onReactionChange(emotionId, this.stats);

            } else {
                // Revert optimistic update
                this.userReaction = previousReaction;
                this.stats = previousStats;
                this.refreshUI();

                this.showErrorNotification(result.message || 'Errore durante la reazione');
            }

        } catch (error) {
            console.error('ReactionPicker: API error', error);

            // Revert optimistic update
            this.userReaction = previousReaction;
            this.stats = previousStats;
            this.refreshUI();

            this.showErrorNotification('Errore di connessione. Riprova.');

        } finally {
            this.isProcessing = false;
        }
    }

    /**
     * Handle remove reaction
     */
    async handleRemoveReaction() {
        if (!this.userReaction) return;

        // ENTERPRISE: Intelligent abuse detection (same as handleReactionClick)
        const now = Date.now();

        this.recentClicks.push(now);
        this.recentClicks = this.recentClicks.filter(time => now - time < this.abuseWindow);

        // ENTERPRISE GALAXY V4: Silent abuse detection
        if (this.recentClicks.length > this.abuseThreshold) {
            return; // Silently ignore
        }

        if (this.isProcessing) return;

        this.isProcessing = true;

        // Optimistic UI update
        const previousReaction = this.userReaction;
        const previousStats = { ...this.stats };

        this.updateUIOptimisticRemove();

        // API call
        try {
            const result = await this.unreactToAudio();

            if (result.success) {
                // ENTERPRISE V4: REPLACE stats entirely (server is source of truth)
                if (result.stats) {
                    // Filter out 0 counts and use server stats directly
                    this.stats = {};
                    Object.keys(result.stats).forEach(key => {
                        if (result.stats[key] > 0) {
                            this.stats[key] = result.stats[key];
                        }
                    });
                }
                this.userReaction = null;
                this.refreshUI();
                this.onReactionChange(null, this.stats);

            } else {
                // Revert
                this.userReaction = previousReaction;
                this.stats = previousStats;
                this.refreshUI();

                this.showErrorNotification(result.message || 'Errore durante la rimozione');
            }

        } catch (error) {
            console.error('ReactionPicker: Unreact error', error);

            // Revert
            this.userReaction = previousReaction;
            this.stats = previousStats;
            this.refreshUI();

            this.showErrorNotification('Errore di connessione. Riprova.');

        } finally {
            this.isProcessing = false;
        }
    }

    /**
     * Optimistic UI update when reacting
     *
     * @param {number} emotionId New emotion ID
     */
    updateUIOptimistic(emotionId) {
        // Decrease count on old reaction
        if (this.userReaction) {
            this.stats[this.userReaction] = Math.max(0, (this.stats[this.userReaction] || 0) - 1);
        }

        // Increase count on new reaction
        this.stats[emotionId] = (this.stats[emotionId] || 0) + 1;
        this.userReaction = emotionId;

        this.refreshUI();
    }

    /**
     * Optimistic UI update when removing reaction
     */
    updateUIOptimisticRemove() {
        if (this.userReaction) {
            this.stats[this.userReaction] = Math.max(0, (this.stats[this.userReaction] || 0) - 1);
        }
        this.userReaction = null;

        this.refreshUI();
    }

    /**
     * Refresh UI (re-render)
     */
    refreshUI() {
        const container = document.getElementById(this.containerId);
        if (container) {
            this.render(container);
            this.attachEventListeners(container);
        }
    }

    /**
     * API: React to audio
     *
     * @param {number} emotionId Emotion ID (1-10)
     * @returns {Promise<Object>} API response
     */
    async reactToAudio(emotionId) {
        // ENTERPRISE FIX: Use correct backend route
        const endpoint = `/api/audio/reaction`;

        const response = await api.post(endpoint, {
            audio_post_id: this.audioId,
            emotion_id: emotionId,
        });

        return response;
    }

    /**
     * API: Unreact to audio
     *
     * @returns {Promise<Object>} API response
     */
    async unreactToAudio() {
        // ENTERPRISE FIX: Use correct backend route (DELETE method)
        const endpoint = `/api/audio/reaction/${this.audioId}`;

        const response = await api.delete(endpoint);

        return response;
    }

    /**
     * Show error notification
     *
     * @param {string} message Error message
     */
    showErrorNotification(message) {
        // TODO: Use toast notification system when implemented
        if (window.GetLoud && typeof window.GetLoud.showToast === 'function') {
            window.GetLoud.showToast(message, 'error');
        } else {
            console.error('❌ ReactionPicker Error:', message);
        }
    }

    /**
     * Update stats from external source (e.g., WebSocket)
     *
     * @param {Object} newStats New reaction stats {emotion_id: count}
     * @param {number|null} newUserReaction New user reaction
     */
    updateStats(newStats, newUserReaction = null) {
        this.stats = newStats;
        if (newUserReaction !== null) {
            this.userReaction = newUserReaction;
        }
        this.refreshUI();
    }

    /**
     * Get total reaction count
     *
     * @returns {number} Total reactions
     */
    getTotalReactions() {
        return Object.values(this.stats).reduce((sum, count) => sum + count, 0);
    }

    /**
     * Get dominant emotion (most reactions)
     *
     * @returns {Object|null} {emotionId, count, emotion} or null
     */
    getDominantEmotion() {
        let maxCount = 0;
        let dominantId = null;

        for (const [emotionId, count] of Object.entries(this.stats)) {
            if (count > maxCount) {
                maxCount = count;
                dominantId = parseInt(emotionId, 10);
            }
        }

        if (!dominantId) return null;

        return {
            emotionId: dominantId,
            count: maxCount,
            emotion: this.emotions[dominantId],
        };
    }

    /**
     * Destroy component (cleanup)
     *
     * ENTERPRISE V11.8: Removes dialog and scroll handler
     */
    destroy() {
        // ENTERPRISE V11.7: Remove scroll handler
        if (this._scrollHandler) {
            window.removeEventListener('scroll', this._scrollHandler, true);
            this._scrollHandler = null;
        }

        // ENTERPRISE V11.8: Remove dialog from body
        const dialog = document.getElementById(`reaction-dropdown-portal-${this.audioId}`);
        if (dialog) {
            if (dialog.open) dialog.close();
            dialog.remove();
        }

        const container = document.getElementById(this.containerId);
        if (container) {
            container.innerHTML = '';
        }
    }
}

/**
 * Factory function to create ReactionPicker instances
 *
 * @param {Object} options Configuration options
 * @returns {ReactionPicker} ReactionPicker instance
 */
function createReactionPicker(options) {
    return new ReactionPicker(options);
}

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ReactionPicker, createReactionPicker };
}
