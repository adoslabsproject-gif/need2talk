/**
 * ReadReceipts.js - Enterprise Message Read Status System
 *
 * Tracks and displays message delivery and read status for DMs.
 * Integrates with WebSocket for real-time updates.
 *
 * STATUS ICONS:
 * - Pending: Clock icon (message sending)
 * - Sent: Single check (delivered to server)
 * - Delivered: Double check gray (delivered to recipient)
 * - Read: Double check blue (read by recipient)
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @version 1.0.0
 */

class ReadReceipts {
    static STATUS = {
        PENDING: 'pending',
        SENT: 'sent',
        DELIVERED: 'delivered',
        READ: 'read',
    };

    static ICONS = {
        pending: `<svg class="n2t-receipt-icon n2t-receipt-pending" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
        </svg>`,

        sent: `<svg class="n2t-receipt-icon n2t-receipt-sent" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>`,

        delivered: `<svg class="n2t-receipt-icon n2t-receipt-delivered" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="18 6 7 17 2 12"></polyline>
            <polyline points="22 6 11 17 8 14"></polyline>
        </svg>`,

        read: `<svg class="n2t-receipt-icon n2t-receipt-read" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2">
            <polyline points="18 6 7 17 2 12"></polyline>
            <polyline points="22 6 11 17 8 14"></polyline>
        </svg>`,
    };

    static LABELS = {
        pending: 'Invio in corso...',
        sent: 'Inviato',
        delivered: 'Consegnato',
        read: 'Letto',
    };

    #messages = new Map();  // messageUuid -> { status, timestamp, readAt, readBy }
    #callbacks = new Map();

    constructor() {
        // Initialize
    }

    // ========================================================================
    // STATUS MANAGEMENT
    // ========================================================================

    /**
     * Set message status
     * @param {string} messageUuid
     * @param {string} status
     * @param {Object} metadata - { timestamp, readBy }
     */
    setStatus(messageUuid, status, metadata = {}) {
        if (!Object.values(ReadReceipts.STATUS).includes(status)) {
            console.warn('[ReadReceipts] Invalid status:', status);
            return;
        }

        const existing = this.#messages.get(messageUuid) || {};
        const statusPriority = {
            pending: 0,
            sent: 1,
            delivered: 2,
            read: 3,
        };

        // Only update if new status is "higher"
        if (statusPriority[status] >= (statusPriority[existing.status] || 0)) {
            const updated = {
                ...existing,
                status,
                updatedAt: Date.now(),
            };

            if (status === 'read' && metadata.readAt) {
                updated.readAt = metadata.readAt;
            }
            if (status === 'delivered' && metadata.deliveredAt) {
                updated.deliveredAt = metadata.deliveredAt;
            }
            if (metadata.readBy) {
                updated.readBy = metadata.readBy;
            }

            this.#messages.set(messageUuid, updated);

            // Emit status change
            this.#emit('statusChange', { messageUuid, status, data: updated });

            // Update DOM if element exists
            this.#updateDOMElement(messageUuid, status);
        }
    }

    /**
     * Get message status
     * @param {string} messageUuid
     * @returns {string}
     */
    getStatus(messageUuid) {
        return this.#messages.get(messageUuid)?.status || 'pending';
    }

    /**
     * Get full message receipt data
     * @param {string} messageUuid
     * @returns {Object|null}
     */
    getReceipt(messageUuid) {
        return this.#messages.get(messageUuid) || null;
    }

    /**
     * Mark multiple messages as read (batch)
     * @param {Array} messageUuids
     * @param {string} readerUuid
     */
    markManyAsRead(messageUuids, readerUuid = null) {
        const readAt = Date.now();

        for (const uuid of messageUuids) {
            this.setStatus(uuid, 'read', { readAt, readBy: readerUuid });
        }

        this.#emit('batchRead', { messageUuids, readerUuid, readAt });
    }

    /**
     * Mark all messages in conversation as read
     * @param {string} conversationUuid
     */
    markConversationAsRead(conversationUuid) {
        // This will be called when entering a conversation
        // The actual API call should be made by the caller
        this.#emit('conversationRead', { conversationUuid });
    }

    // ========================================================================
    // DOM UPDATES
    // ========================================================================

    #updateDOMElement(messageUuid, status) {
        const element = document.querySelector(`[data-message-uuid="${messageUuid}"] .n2t-read-receipt`);
        if (element) {
            element.innerHTML = this.createIcon(status);
            element.setAttribute('title', ReadReceipts.LABELS[status]);
            element.setAttribute('data-status', status);
        }
    }

    /**
     * Attach receipt tracking to message element
     * @param {HTMLElement} element
     * @param {string} messageUuid
     * @param {string} initialStatus
     */
    attachToElement(element, messageUuid, initialStatus = 'sent') {
        // Find or create receipt container
        let receiptEl = element.querySelector('.n2t-read-receipt');

        if (!receiptEl) {
            receiptEl = document.createElement('span');
            receiptEl.className = 'n2t-read-receipt';

            // Insert after timestamp or at end of message footer
            const footer = element.querySelector('.n2t-message-footer');
            if (footer) {
                footer.appendChild(receiptEl);
            } else {
                element.appendChild(receiptEl);
            }
        }

        // Set initial status
        const currentStatus = this.getStatus(messageUuid) || initialStatus;
        receiptEl.innerHTML = this.createIcon(currentStatus);
        receiptEl.setAttribute('title', ReadReceipts.LABELS[currentStatus]);
        receiptEl.setAttribute('data-status', currentStatus);
        element.setAttribute('data-message-uuid', messageUuid);

        // Track in our map
        if (!this.#messages.has(messageUuid)) {
            this.#messages.set(messageUuid, { status: currentStatus });
        }
    }

    // ========================================================================
    // HTML GENERATION
    // ========================================================================

    /**
     * Create receipt icon HTML
     * @param {string} status
     * @returns {string}
     */
    createIcon(status) {
        return ReadReceipts.ICONS[status] || ReadReceipts.ICONS.pending;
    }

    /**
     * Create full receipt HTML with tooltip
     * @param {string} status
     * @param {Object} options
     * @returns {string}
     */
    createReceipt(status, options = {}) {
        const label = ReadReceipts.LABELS[status] || 'Sconosciuto';
        const showLabel = options.showLabel === true;

        return `
            <span class="n2t-read-receipt" data-status="${status}" title="${label}">
                ${this.createIcon(status)}
                ${showLabel ? `<span class="n2t-receipt-label">${label}</span>` : ''}
            </span>
        `;
    }

    /**
     * Create receipt with timestamp
     * @param {string} status
     * @param {number} timestamp
     * @returns {string}
     */
    createReceiptWithTime(status, timestamp) {
        const timeStr = timestamp ? this.#formatTime(timestamp) : '';

        return `
            <span class="n2t-read-receipt-wrapper">
                ${timeStr ? `<span class="n2t-receipt-time">${timeStr}</span>` : ''}
                ${this.createReceipt(status)}
            </span>
        `;
    }

    // ========================================================================
    // EVENTS
    // ========================================================================

    /**
     * Handle WebSocket read receipt event
     * @param {Object} event
     */
    handleEvent(event) {
        switch (event.type) {
            case 'message_sent':
                this.setStatus(event.message_uuid, 'sent');
                break;

            case 'message_delivered':
                this.setStatus(event.message_uuid, 'delivered', {
                    deliveredAt: event.delivered_at,
                });
                break;

            case 'message_read':
            case 'dm_read':
                if (event.message_uuid) {
                    this.setStatus(event.message_uuid, 'read', {
                        readAt: event.read_at,
                        readBy: event.reader_uuid,
                    });
                }
                // Handle batch read
                if (event.message_uuids && Array.isArray(event.message_uuids)) {
                    this.markManyAsRead(event.message_uuids, event.reader_uuid);
                }
                // Handle "all messages up to X read"
                if (event.last_read_uuid) {
                    this.#markAllUpToAsRead(event.last_read_uuid, event.reader_uuid);
                }
                break;
        }
    }

    #markAllUpToAsRead(lastReadUuid, readerUuid) {
        // This requires knowing message order - typically handled by the message list
        this.#emit('readUpTo', { lastReadUuid, readerUuid });
    }

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
                    console.error(`[ReadReceipts] Event callback error (${event}):`, error);
                }
            }
        }
    }

    // ========================================================================
    // UTILITIES
    // ========================================================================

    #formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleTimeString('it-IT', {
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    /**
     * Clear all receipts
     */
    clear() {
        this.#messages.clear();
    }

    /**
     * Clear receipts for specific conversation
     * @param {string} conversationUuid
     */
    clearConversation(conversationUuid) {
        // This would require tracking which messages belong to which conversation
        // For now, emit event for external handling
        this.#emit('clearConversation', { conversationUuid });
    }

    /**
     * Get unread count (for sent messages)
     * @returns {number}
     */
    getUnreadCount() {
        let count = 0;
        for (const msg of this.#messages.values()) {
            if (msg.status !== 'read') {
                count++;
            }
        }
        return count;
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ReadReceipts;
}

window.ReadReceipts = ReadReceipts;

