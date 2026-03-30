/**
 * ReportModal.js - Enterprise Message Report Dialog
 *
 * Modal component for reporting inappropriate messages.
 * Supports multiple report types with optional details.
 *
 * REPORT TYPES:
 * - harassment: Molestie o bullismo
 * - spam: Spam o pubblicità
 * - inappropriate: Contenuto inappropriato
 * - hate_speech: Incitamento all'odio
 * - other: Altro
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @version 1.0.0
 */

class ReportModal {
    static REPORT_TYPES = {
        harassment: {
            label: 'Molestie o bullismo',
            description: 'Comportamento aggressivo, minacce o intimidazioni',
            icon: '😤',
        },
        spam: {
            label: 'Spam o pubblicità',
            description: 'Messaggi non richiesti o promozionali',
            icon: '📢',
        },
        inappropriate: {
            label: 'Contenuto inappropriato',
            description: 'Contenuto sessuale, violento o disturbante',
            icon: '⚠️',
        },
        hate_speech: {
            label: 'Incitamento all\'odio',
            description: 'Discriminazione basata su razza, religione, orientamento, ecc.',
            icon: '🚫',
        },
        other: {
            label: 'Altro',
            description: 'Un altro tipo di violazione',
            icon: '📝',
        },
    };

    #overlay = null;
    #modal = null;
    #callbacks = {
        onSubmit: null,
        onCancel: null,
    };
    #currentReport = null;

    constructor() {
        this.#createModal();
    }

    // ========================================================================
    // MODAL CREATION
    // ========================================================================

    #createModal() {
        // Create overlay
        this.#overlay = document.createElement('div');
        this.#overlay.className = 'n2t-modal-overlay';
        this.#overlay.style.display = 'none';
        this.#overlay.setAttribute('role', 'dialog');
        this.#overlay.setAttribute('aria-modal', 'true');
        this.#overlay.setAttribute('aria-labelledby', 'n2t-report-title');

        this.#overlay.innerHTML = `
            <div class="n2t-modal n2t-report-modal">
                <div class="n2t-modal-header">
                    <h2 id="n2t-report-title" class="n2t-modal-title">Segnala messaggio</h2>
                    <button type="button" class="n2t-modal-close" aria-label="Chiudi">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <div class="n2t-modal-body">
                    <!-- Message Preview -->
                    <div class="n2t-report-message-preview">
                        <div class="n2t-report-preview-label">Messaggio segnalato:</div>
                        <div class="n2t-report-preview-content"></div>
                    </div>

                    <!-- Report Type Selection -->
                    <div class="n2t-report-types">
                        <div class="n2t-report-types-label">Motivo della segnalazione:</div>
                        <div class="n2t-report-type-list">
                            ${Object.entries(ReportModal.REPORT_TYPES).map(([type, config]) => `
                                <label class="n2t-report-type-option">
                                    <input type="radio" name="report_type" value="${type}" class="n2t-report-radio">
                                    <span class="n2t-report-type-card">
                                        <span class="n2t-report-type-icon">${config.icon}</span>
                                        <span class="n2t-report-type-info">
                                            <span class="n2t-report-type-label">${config.label}</span>
                                            <span class="n2t-report-type-desc">${config.description}</span>
                                        </span>
                                    </span>
                                </label>
                            `).join('')}
                        </div>
                    </div>

                    <!-- Additional Details -->
                    <div class="n2t-report-details">
                        <label for="n2t-report-reason" class="n2t-report-details-label">
                            Dettagli aggiuntivi (opzionale):
                        </label>
                        <textarea
                            id="n2t-report-reason"
                            class="n2t-report-textarea"
                            placeholder="Fornisci ulteriori dettagli sulla segnalazione..."
                            rows="3"
                            maxlength="500"
                        ></textarea>
                        <div class="n2t-report-char-count">
                            <span class="n2t-char-current">0</span>/500
                        </div>
                    </div>

                    <!-- Warning Notice -->
                    <div class="n2t-report-notice">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <span>Le segnalazioni false possono comportare azioni sul tuo account.</span>
                    </div>
                </div>

                <div class="n2t-modal-footer">
                    <button type="button" class="n2t-btn n2t-btn-secondary n2t-report-cancel">
                        Annulla
                    </button>
                    <button type="button" class="n2t-btn n2t-btn-danger n2t-report-submit" disabled>
                        Invia segnalazione
                    </button>
                </div>

                <!-- Loading State -->
                <div class="n2t-modal-loading" style="display: none;">
                    <div class="n2t-spinner"></div>
                    <span>Invio segnalazione...</span>
                </div>

                <!-- Success State -->
                <div class="n2t-modal-success" style="display: none;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="16 10 11 15 8 12"></polyline>
                    </svg>
                    <h3>Segnalazione inviata</h3>
                    <p>Grazie per aver contribuito a mantenere sicura la community. Il nostro team esaminerà la segnalazione.</p>
                    <button type="button" class="n2t-btn n2t-btn-primary n2t-success-close">Chiudi</button>
                </div>
            </div>
        `;

        // Append to body
        document.body.appendChild(this.#overlay);

        // Cache elements
        this.#modal = this.#overlay.querySelector('.n2t-modal');

        // Bind events
        this.#bindEvents();
    }

    #bindEvents() {
        // Close button
        this.#overlay.querySelector('.n2t-modal-close').addEventListener('click', () => this.close());

        // Cancel button
        this.#overlay.querySelector('.n2t-report-cancel').addEventListener('click', () => this.close());

        // Click outside to close
        this.#overlay.addEventListener('click', (e) => {
            if (e.target === this.#overlay) {
                this.close();
            }
        });

        // ESC key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.#overlay.style.display !== 'none') {
                this.close();
            }
        });

        // Report type selection
        const radios = this.#overlay.querySelectorAll('.n2t-report-radio');
        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                this.#updateSubmitButton();

                // Highlight selected
                this.#overlay.querySelectorAll('.n2t-report-type-card').forEach(card => {
                    card.classList.remove('selected');
                });
                radio.closest('.n2t-report-type-option').querySelector('.n2t-report-type-card').classList.add('selected');
            });
        });

        // Textarea character count
        const textarea = this.#overlay.querySelector('.n2t-report-textarea');
        const charCount = this.#overlay.querySelector('.n2t-char-current');
        textarea.addEventListener('input', () => {
            charCount.textContent = textarea.value.length;
        });

        // Submit button
        this.#overlay.querySelector('.n2t-report-submit').addEventListener('click', () => this.#submit());

        // Success close button
        this.#overlay.querySelector('.n2t-success-close').addEventListener('click', () => this.close());
    }

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    /**
     * Open report modal for a message
     * @param {Object} message - { uuid, content, senderName, senderAvatar }
     * @param {Object} callbacks - { onSubmit, onCancel }
     */
    open(message, callbacks = {}) {
        this.#currentReport = {
            messageUuid: message.uuid,
            messageContent: message.content,
            senderName: message.senderName,
        };

        this.#callbacks = {
            onSubmit: callbacks.onSubmit || null,
            onCancel: callbacks.onCancel || null,
        };

        // Set message preview
        const previewContent = this.#overlay.querySelector('.n2t-report-preview-content');
        previewContent.innerHTML = `
            <div class="n2t-report-sender">${this.#escapeHtml(message.senderName || 'Utente')}</div>
            <div class="n2t-report-message">${this.#escapeHtml(this.#truncate(message.content, 200))}</div>
        `;

        // Reset form
        this.#reset();

        // Show modal
        this.#overlay.style.display = 'flex';
        this.#modal.classList.add('n2t-modal-entering');

        // Focus first radio
        setTimeout(() => {
            this.#modal.classList.remove('n2t-modal-entering');
            this.#overlay.querySelector('.n2t-report-radio')?.focus();
        }, 300);

        // Prevent body scroll
        document.body.style.overflow = 'hidden';
    }

    /**
     * Close the modal
     */
    close() {
        this.#modal.classList.add('n2t-modal-leaving');

        setTimeout(() => {
            this.#overlay.style.display = 'none';
            this.#modal.classList.remove('n2t-modal-leaving');
            document.body.style.overflow = '';

            this.#callbacks.onCancel?.();
        }, 200);
    }

    /**
     * Set event callbacks
     * @param {Object} callbacks
     */
    on(callbacks) {
        if (callbacks.submit) this.#callbacks.onSubmit = callbacks.submit;
        if (callbacks.cancel) this.#callbacks.onCancel = callbacks.cancel;
        return this;
    }

    // ========================================================================
    // FORM HANDLING
    // ========================================================================

    #reset() {
        // Clear radio selection
        this.#overlay.querySelectorAll('.n2t-report-radio').forEach(r => r.checked = false);
        this.#overlay.querySelectorAll('.n2t-report-type-card').forEach(c => c.classList.remove('selected'));

        // Clear textarea
        this.#overlay.querySelector('.n2t-report-textarea').value = '';
        this.#overlay.querySelector('.n2t-char-current').textContent = '0';

        // Disable submit
        this.#overlay.querySelector('.n2t-report-submit').disabled = true;

        // Reset states
        this.#showState('form');
    }

    #updateSubmitButton() {
        const selectedType = this.#overlay.querySelector('.n2t-report-radio:checked');
        this.#overlay.querySelector('.n2t-report-submit').disabled = !selectedType;
    }

    #showState(state) {
        const body = this.#overlay.querySelector('.n2t-modal-body');
        const footer = this.#overlay.querySelector('.n2t-modal-footer');
        const loading = this.#overlay.querySelector('.n2t-modal-loading');
        const success = this.#overlay.querySelector('.n2t-modal-success');

        switch (state) {
            case 'form':
                body.style.display = 'block';
                footer.style.display = 'flex';
                loading.style.display = 'none';
                success.style.display = 'none';
                break;

            case 'loading':
                body.style.display = 'none';
                footer.style.display = 'none';
                loading.style.display = 'flex';
                success.style.display = 'none';
                break;

            case 'success':
                body.style.display = 'none';
                footer.style.display = 'none';
                loading.style.display = 'none';
                success.style.display = 'flex';
                break;
        }
    }

    async #submit() {
        const selectedRadio = this.#overlay.querySelector('.n2t-report-radio:checked');
        if (!selectedRadio) return;

        const reportType = selectedRadio.value;
        const reason = this.#overlay.querySelector('.n2t-report-textarea').value.trim();

        this.#showState('loading');

        try {
            // CSRF token is automatically added by csrf.js fetch wrapper - DO NOT add manually
            const response = await fetch(`/api/chat/messages/${this.#currentReport.messageUuid}/report`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    report_type: reportType,
                    report_reason: reason || null,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                this.#showState('success');
                this.#callbacks.onSubmit?.({
                    messageUuid: this.#currentReport.messageUuid,
                    reportType,
                    reason,
                });
            } else {
                throw new Error(data.error || 'Errore durante l\'invio della segnalazione');
            }
        } catch (error) {
            console.error('[ReportModal] Submit error:', error);

            // Show error and return to form
            this.#showState('form');
            this.#showError(error.message || 'Errore durante l\'invio. Riprova.');
        }
    }

    #showError(message) {
        // Create temporary error message
        const existingError = this.#overlay.querySelector('.n2t-report-error');
        if (existingError) existingError.remove();

        const errorEl = document.createElement('div');
        errorEl.className = 'n2t-report-error';
        errorEl.innerHTML = `
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>
            <span>${this.#escapeHtml(message)}</span>
        `;

        const notice = this.#overlay.querySelector('.n2t-report-notice');
        notice.parentNode.insertBefore(errorEl, notice);

        // Auto-remove after 5s
        setTimeout(() => errorEl.remove(), 5000);
    }

    // ========================================================================
    // UTILITIES
    // ========================================================================

    #escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    #truncate(text, maxLength) {
        if (!text) return '';
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }

    /**
     * Destroy modal and cleanup
     */
    destroy() {
        if (this.#overlay && this.#overlay.parentNode) {
            this.#overlay.parentNode.removeChild(this.#overlay);
        }
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ReportModal;
}

window.ReportModal = ReportModal;

