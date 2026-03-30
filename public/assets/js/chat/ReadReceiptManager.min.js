/**
 * ReadReceiptManager.js - Enterprise Page Visibility Read Receipts
 *
 * ENTERPRISE GALAXY READ RECEIPT SYSTEM
 *
 * Problem solved:
 * - Messages were marked as "read" instantly even when browser tab was hidden
 * - User appeared to have read messages while away from keyboard
 * - No distinction between "delivered" and "actually read by human eyes"
 *
 * Solution:
 * This module uses the Page Visibility API + Document Focus to accurately track
 * when a user ACTUALLY views a message, not just when it arrives at their browser.
 *
 * VISIBILITY LOGIC:
 * A message is marked as read ONLY when ALL conditions are true:
 * 1. document.visibilityState === 'visible' (tab is active)
 * 2. document.hasFocus() (browser window is focused)
 * 3. The chat UI is visible (widget not minimized, or DM page active)
 *
 * When conditions are NOT met:
 * - Messages are queued as "pending reads"
 * - When user returns (visibility + focus), all pending reads are marked
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-06
 * @version 1.0.0
 */

class ReadReceiptManager {
    static #instance = null;

    // Pending reads by conversation UUID
    #pendingReads = new Map(); // conversationUuid -> Set of messageUuids

    // Track current conversation (for DM page)
    #activeConversationUuid = null;

    // Visibility state
    #isPageVisible = true;
    #hasWindowFocus = true;

    // Debounce timer for marking reads
    #markReadDebounce = null;
    #MARK_READ_DELAY = 500; // ms - small delay to batch multiple messages

    // Callbacks
    #onMarkRead = null;

    constructor() {
        if (ReadReceiptManager.#instance) {
            return ReadReceiptManager.#instance;
        }
        ReadReceiptManager.#instance = this;

        this.#init();
    }

    static getInstance() {
        if (!ReadReceiptManager.#instance) {
            ReadReceiptManager.#instance = new ReadReceiptManager();
        }
        return ReadReceiptManager.#instance;
    }

    #init() {

        // Initial state
        this.#isPageVisible = document.visibilityState === 'visible';
        this.#hasWindowFocus = document.hasFocus();

        // Page Visibility API
        document.addEventListener('visibilitychange', () => {
            this.#isPageVisible = document.visibilityState === 'visible';
            // ENTERPRISE V10.62: NO automatic processing of pending reads on visibility change
            // Pending reads are processed ONLY when user actively opens a specific widget
            // This is the correct Facebook Messenger behavior
        });

        // Window focus tracking
        window.addEventListener('focus', () => {
            this.#hasWindowFocus = true;
            // ENTERPRISE V10.62: NO automatic processing of pending reads on focus
            // Only the specific widget being opened should mark its messages as read
        });

        window.addEventListener('blur', () => {
            this.#hasWindowFocus = false;
        });

        // ENTERPRISE V10.61: REMOVED beforeunload handler
        // The original idea was to save pending reads when user closes page.
        // BUT this caused a critical bug: pending reads from MINIMIZED widgets
        // (that user never actually opened/read) were being sent on page change.
        //
        // New behavior: Pending reads are ONLY processed when user actively
        // opens/maximizes a widget. If user never opens it, messages stay unread.
        // This is the correct Facebook Messenger behavior.

    }

    /**
     * Check if the page is currently being viewed by the user
     *
     * @returns {boolean} True if user is actively viewing the page
     */
    isActivelyViewing() {
        return this.#isPageVisible && this.#hasWindowFocus;
    }

    /**
     * Set the active conversation (for DM page)
     * Used to know which conversation to mark as read
     *
     * @param {string|null} conversationUuid
     */
    setActiveConversation(conversationUuid) {
        this.#activeConversationUuid = conversationUuid;

        // If we're actively viewing and have pending reads for this conversation, process them
        if (this.isActivelyViewing() && conversationUuid) {
            this.#markConversationRead(conversationUuid);
        }
    }

    /**
     * Called when a message arrives that should be marked as read
     * Decides whether to mark immediately or queue for later
     *
     * @param {string} conversationUuid - The conversation the message belongs to
     * @param {string} messageUuid - The message UUID (optional, for tracking)
     * @param {boolean} isWidgetOpen - For mini chat, whether widget is not minimized
     */
    onMessageReceived(conversationUuid, messageUuid = null, isWidgetOpen = true) {
        if (!conversationUuid) {
            return;
        }

        // If user is actively viewing the conversation, mark as read
        if (this.isActivelyViewing() && isWidgetOpen) {
            this.#scheduleMarkRead(conversationUuid);
        } else {
            // Queue for later
            this.#queuePendingRead(conversationUuid, messageUuid);
        }
    }

    /**
     * ENTERPRISE V10.59: Called when user opens/maximizes a chat widget or navigates to DM page
     * Should mark pending reads for that conversation ONLY if widget is actually open (not minimized)
     *
     * @param {string} conversationUuid
     * @param {boolean} isWidgetOpen - True if widget is open/maximized, false if minimized
     */
    onConversationOpened(conversationUuid, isWidgetOpen = true) {
        if (!conversationUuid) return;

        // ENTERPRISE V10.59: CRITICAL - Only mark as read if:
        // 1. User is actively viewing the page (visible + focused)
        // 2. Widget/chat is actually OPEN (not minimized/collapsed)
        // This prevents marking as read when widget is restored minimized on page navigation
        if (this.isActivelyViewing() && isWidgetOpen) {
            this.#markConversationRead(conversationUuid);
        }
    }

    /**
     * Queue a pending read
     */
    #queuePendingRead(conversationUuid, messageUuid) {
        if (!this.#pendingReads.has(conversationUuid)) {
            this.#pendingReads.set(conversationUuid, new Set());
        }
        if (messageUuid) {
            this.#pendingReads.get(conversationUuid).add(messageUuid);
        } else {
            // No specific message UUID, just flag the conversation has unread
            this.#pendingReads.get(conversationUuid).add('_unread_');
        }
    }

    /**
     * Schedule a mark read call (debounced to batch multiple messages)
     */
    #scheduleMarkRead(conversationUuid) {
        if (this.#markReadDebounce) {
            clearTimeout(this.#markReadDebounce);
        }

        this.#markReadDebounce = setTimeout(() => {
            this.#markConversationRead(conversationUuid);
        }, this.#MARK_READ_DELAY);
    }

    /**
     * Mark a conversation as read via API
     */
    async #markConversationRead(conversationUuid) {
        if (!conversationUuid) return;

        try {
            const response = await fetch(`/api/chat/dm/${conversationUuid}/read`, {
                method: 'POST',
                credentials: 'include'
            });

            if (!response.ok) {
                return;
            }

            // Clear pending reads for this conversation
            this.#pendingReads.delete(conversationUuid);

            // ENTERPRISE V10.78: Dispatch cross-view sync event
            // This allows ChatWidgetManager and ChatManager to sync their unread badges
            // When one view marks as read, all other views should update immediately
            window.dispatchEvent(new CustomEvent('n2t:conversationMarkedRead', {
                detail: { conversationUuid }
            }));

            // Trigger callback if set
            if (this.#onMarkRead) {
                this.#onMarkRead(conversationUuid);
            }

        } catch (e) {
            // Silent fail - non-critical operation
        }
    }

    /**
     * Process all pending reads when user returns
     */
    #processPendingReads() {
        const pendingCount = this.#pendingReads.size;
        if (pendingCount === 0) {
            return;
        }

        // Process each pending conversation
        for (const conversationUuid of this.#pendingReads.keys()) {
            this.#markConversationRead(conversationUuid);
        }
    }

    /**
     * Sync version for beforeunload - uses sendBeacon
     */
    #processPendingReadsSync() {
        if (this.#pendingReads.size === 0) return;

        for (const conversationUuid of this.#pendingReads.keys()) {
            // Use sendBeacon for reliable send during unload
            if (navigator.sendBeacon) {
                navigator.sendBeacon(`/api/chat/dm/${conversationUuid}/read`, '');
            }
        }
    }

    /**
     * Check if there are pending reads for a conversation
     */
    hasPendingReads(conversationUuid) {
        return this.#pendingReads.has(conversationUuid) &&
               this.#pendingReads.get(conversationUuid).size > 0;
    }

    /**
     * Get total pending read count across all conversations
     */
    getPendingReadCount() {
        let count = 0;
        for (const set of this.#pendingReads.values()) {
            count += set.size;
        }
        return count;
    }

    /**
     * Set callback for when reads are marked
     * Useful for updating UI
     */
    setOnMarkRead(callback) {
        this.#onMarkRead = callback;
    }

    /**
     * Expose visibility state for external components
     */
    get isPageVisible() {
        return this.#isPageVisible;
    }

    get hasWindowFocus() {
        return this.#hasWindowFocus;
    }
}

// Auto-initialize on DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {
    window.readReceiptManager = ReadReceiptManager.getInstance();
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ReadReceiptManager;
}

// Global export
window.ReadReceiptManager = ReadReceiptManager;
