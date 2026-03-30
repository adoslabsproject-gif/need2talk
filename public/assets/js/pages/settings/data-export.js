/**
 * need2talk - Settings: Data Export & Account Deletion
 * Enterprise Galaxy - GDPR compliance (Article 17 + 20)
 *
 * Purpose: Handle data export and account deletion with 30-day grace period
 * Security: CSRF protection, XSS prevention, confirmation dialogs
 * Performance: Progress tracking, ZIP download handling
 */

(function() {
    'use strict';

    /**
     * Data Export & Deletion Manager
     */
    const DataExportSettings = {
        // Configuration
        exportPollInterval: null,
        deletionCountdownInterval: null,

        /**
         * Initialize data export page
         */
        init() {
            console.log('[DataExportSettings] Initializing...');

            // Initialize export button
            this.initExportButton();

            // Initialize delete account button
            this.initDeleteButton();

            // Initialize cancel deletion button
            this.initCancelDeletionButton();

            // Initialize deletion countdown (if pending)
            this.initDeletionCountdown();

            // Check URL params for action=delete
            this.checkUrlAction();

            console.log('[DataExportSettings] Initialization complete');
        },

        /**
         * Check URL parameters for auto-actions
         */
        checkUrlAction() {
            const urlParams = new URLSearchParams(window.location.search);
            const action = urlParams.get('action');

            if (action === 'delete') {
                // Scroll to delete section
                const deleteSection = document.getElementById('delete-account-section');
                if (deleteSection) {
                    deleteSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

                    // Highlight section
                    deleteSection.classList.add('ring-2', 'ring-red-500', 'ring-offset-2', 'ring-offset-gray-900');
                    setTimeout(() => {
                        deleteSection.classList.remove('ring-2', 'ring-red-500', 'ring-offset-2', 'ring-offset-gray-900');
                    }, 2000);
                }
            }
        },

        /**
         * Initialize export button
         */
        initExportButton() {
            const exportButton = document.getElementById('export-data-btn');

            if (!exportButton) {
                console.warn('[DataExportSettings] Export button not found');
                return;
            }

            exportButton.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.handleExportRequest();
            });

            console.log('[DataExportSettings] Export button initialized');
        },

        /**
         * Handle export data request
         */
        async handleExportRequest() {
            const exportButton = document.getElementById('export-data-btn');
            const messageContainer = document.getElementById('message-container');

            // Confirm export (ITALIAN)
            const confirmed = confirm(
                'Vuoi esportare tutti i tuoi dati?\n\n' +
                'Questo creerà un file ZIP contenente:\n' +
                '- Le informazioni del tuo profilo\n' +
                '- Tutti i tuoi post e file audio\n' +
                '- Commenti e reazioni\n' +
                '- Lista amici\n' +
                '- Impostazioni e preferenze\n\n' +
                'L\'esportazione sarà disponibile per il download per 7 giorni.\n\n' +
                'Continuare?'
            );

            if (!confirmed) {
                return;
            }

            // Disable button (ITALIAN)
            this.setButtonLoading(exportButton, true, 'Preparazione esportazione...');
            this.clearMessages(messageContainer);

            try {
                console.log('[DataExportSettings] Requesting data export');

                // API call
                const response = await api.post('/settings/data-export/request');

                console.log('[DataExportSettings] Export request submitted', response);

                // Show success message
                this.showSuccessMessage(messageContainer, response.message || 'Export is being prepared. You will receive a download link shortly.');

                // Show flash notification
                if (window.showSuccess) {
                    window.showSuccess('Data export started! This may take a few minutes.');
                }

                // If export is ready immediately (small account)
                if (response.download_url) {
                    // Trigger download
                    setTimeout(() => {
                        window.location.href = response.download_url;
                    }, 1000);
                }

                // Poll for export completion (if not ready)
                if (response.export_id) {
                    this.pollExportStatus(response.export_id);
                }

            } catch (error) {
                console.error('[DataExportSettings] Export request failed', error);

                // ENTERPRISE V11.9 (2026-01-18): Better error handling
                let errorMessage = 'Failed to start export';

                if (error.status === 400 || error.code === 'VALIDATION_ERROR') {
                    errorMessage = error.data?.message || error.data?.errors?.join(', ') || 'Errore di validazione. Verifica i dati inseriti.';
                } else if (error.status === 401 || error.code === 'UNAUTHORIZED') {
                    errorMessage = '⚠️ Sessione scaduta. Effettua nuovamente il login e riprova.';
                    setTimeout(() => {
                        window.location.href = '/auth/login?redirect=' + encodeURIComponent(window.location.pathname);
                    }, 2000);
                } else if (error.status === 0 || error.code === 'NETWORK_ERROR') {
                    errorMessage = '🌐 Errore di connessione. Verifica la tua connessione internet e riprova.';
                } else if (error.message) {
                    errorMessage = error.message;
                }

                this.showErrorMessage(messageContainer, errorMessage);

                if (window.showError) {
                    window.showError(errorMessage);
                }

            } finally {
                this.setButtonLoading(exportButton, false);
            }
        },

        /**
         * Poll export status (for large exports)
         */
        async pollExportStatus(exportId) {
            console.log('[DataExportSettings] Polling export status:', exportId);

            // Clear existing interval
            if (this.exportPollInterval) {
                clearInterval(this.exportPollInterval);
            }

            // Poll every 5 seconds
            this.exportPollInterval = setInterval(async () => {
                try {
                    const response = await api.get(`/settings/data-export/status/${exportId}`);

                    if (response.status === 'completed' && response.download_url) {
                        clearInterval(this.exportPollInterval);

                        if (window.showSuccess) {
                            window.showSuccess('Export ready! Download starting...');
                        }

                        // Trigger download
                        window.location.href = response.download_url;
                    } else if (response.status === 'failed') {
                        clearInterval(this.exportPollInterval);

                        if (window.showError) {
                            window.showError('Export failed. Please try again.');
                        }
                    }

                } catch (error) {
                    console.error('[DataExportSettings] Poll failed', error);
                }
            }, 5000); // Poll every 5 seconds
        },

        /**
         * Initialize delete account button
         */
        initDeleteButton() {
            const deleteButton = document.getElementById('delete-account-btn');
            const deleteForm = document.getElementById('delete-account-form');

            if (!deleteButton) {
                console.warn('[DataExportSettings] Delete account button not found');
                return;
            }

            // ENTERPRISE FIX: Intercept FORM submit (not just button click)
            // The button is type="submit" inside a <form>, so clicking it triggers
            // both the click event AND native form submission. Blocking only the click
            // event is unreliable — the native form submit aborts the in-flight fetch,
            // causing NETWORK_ERROR status 0 instead of the actual server response.
            if (deleteForm) {
                deleteForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    await this.handleDeleteAccount();
                });
            } else {
                // Fallback: no form wrapper, use click handler
                deleteButton.addEventListener('click', async (e) => {
                    e.preventDefault();
                    await this.handleDeleteAccount();
                });
            }

            console.log('[DataExportSettings] Delete account button initialized');
        },

        /**
         * Handle account deletion request
         */
        async handleDeleteAccount() {
            const deleteButton = document.getElementById('delete-account-btn');
            const messageContainer = document.getElementById('message-container');

            // Multi-step confirmation (ITALIAN)
            const confirmStep1 = confirm(
                '⚠️ ATTENZIONE: Stai per eliminare il tuo account!\n\n' +
                'Questo farà:\n' +
                '- Programmerà l\'eliminazione del tuo account tra 30 giorni\n' +
                '- Potrai annullare entro 30 giorni\n' +
                '- Dopo 30 giorni, TUTTI i tuoi dati verranno ELIMINATI PERMANENTEMENTE\n\n' +
                'Sei assolutamente sicuro?'
            );

            if (!confirmStep1) {
                return;
            }

            // Second confirmation (extra safety - ITALIAN)
            const confirmStep2 = confirm(
                'ATTENZIONE FINALE!\n\n' +
                'Dopo 30 giorni, i seguenti dati verranno ELIMINATI PERMANENTEMENTE:\n' +
                '- Il tuo profilo e account\n' +
                '- Tutti i tuoi post e file audio\n' +
                '- Tutti i tuoi commenti e reazioni\n' +
                '- La tua lista amici\n' +
                '- Tutte le impostazioni e preferenze\n\n' +
                'Questa azione NON PUÒ essere annullata dopo il periodo di grazia!\n\n' +
                'Digita "CANCELLA" nella prossima finestra per confermare.'
            );

            if (!confirmStep2) {
                return;
            }

            // Final type-to-confirm (ITALIAN - changed from "DELETE" to "CANCELLA")
            const typeConfirm = prompt('Digita "CANCELLA" (in maiuscolo) per confermare l\'eliminazione dell\'account:');
            if (typeConfirm !== 'CANCELLA') {
                alert('Eliminazione account annullata.');
                return;
            }

            // Get password from form (if present - for users with password)
            const passwordField = document.getElementById('deletion-password');
            const confirmPassword = passwordField ? passwordField.value : '';

            // Get reason from form (textarea)
            const reasonField = document.getElementById('deletion-reason');
            const reason = reasonField ? reasonField.value : '';

            // Disable button (ITALIAN)
            this.setButtonLoading(deleteButton, true, 'Programmazione eliminazione...');
            this.clearMessages(messageContainer);

            try {
                console.log('[DataExportSettings] Requesting account deletion');

                // API call - include confirm_password if present
                const requestData = {
                    reason: reason || null
                };

                if (confirmPassword) {
                    requestData.confirm_password = confirmPassword;
                }

                const response = await api.post('/settings/data-export/delete-account', requestData);

                console.log('[DataExportSettings] Account deletion scheduled', response);

                // Show success message with countdown
                this.showSuccessMessage(
                    messageContainer,
                    response.message || 'Account deletion scheduled. You have 30 days to cancel.'
                );

                // Show flash notification
                if (window.showSuccess) {
                    window.showSuccess('Account scheduled for deletion in 30 days. You can cancel anytime before then.');
                }

                // Reload page to show deletion pending section
                setTimeout(() => {
                    window.location.reload();
                }, 2000);

            } catch (error) {
                console.error('[DataExportSettings] Account deletion failed', error);

                // ENTERPRISE V11.9 (2026-01-18): Better error handling
                // Distinguish between validation errors (400) and network errors
                let errorMessage = 'Failed to schedule account deletion';

                if (error.status === 400 || error.code === 'VALIDATION_ERROR') {
                    // Validation error from backend
                    if (error.data?.error === 'PASSWORD_INCORRECT') {
                        errorMessage = '❌ Password errata. Verifica la password inserita e riprova.';
                    } else if (error.data?.message) {
                        errorMessage = error.data.message;
                    } else if (error.data?.errors?.length > 0) {
                        errorMessage = error.data.errors.join(', ');
                    } else {
                        errorMessage = 'Errore di validazione. Verifica i dati inseriti.';
                    }
                } else if (error.status === 401 || error.code === 'UNAUTHORIZED') {
                    // Session expired
                    errorMessage = '⚠️ Sessione scaduta. Effettua nuovamente il login e riprova.';
                    setTimeout(() => {
                        window.location.href = '/auth/login?redirect=' + encodeURIComponent(window.location.pathname);
                    }, 2000);
                } else if (error.status === 0 || error.code === 'NETWORK_ERROR') {
                    // Real network error (no connection, timeout, etc.)
                    errorMessage = '🌐 Errore di connessione. Verifica la tua connessione internet e riprova.';
                } else if (error.message) {
                    // Generic error with message
                    errorMessage = error.message;
                }

                this.showErrorMessage(messageContainer, errorMessage);

                if (window.showError) {
                    window.showError(errorMessage);
                }

            } finally {
                this.setButtonLoading(deleteButton, false);
            }
        },

        /**
         * Initialize cancel deletion button
         */
        initCancelDeletionButton() {
            const cancelButton = document.getElementById('cancel-deletion-btn');

            if (!cancelButton) {
                // Button doesn't exist if no pending deletion
                return;
            }

            cancelButton.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.handleCancelDeletion();
            });

            console.log('[DataExportSettings] Cancel deletion button initialized');
        },

        /**
         * Handle cancel deletion request
         */
        async handleCancelDeletion() {
            const cancelButton = document.getElementById('cancel-deletion-btn');
            const messageContainer = document.getElementById('message-container');

            // Confirm cancellation (ITALIAN)
            const confirmed = confirm(
                'Vuoi ripristinare il tuo account e annullare l\'eliminazione?\n\n' +
                'Il tuo account sarà completamente ripristinato e rimarrà attivo.'
            );

            if (!confirmed) {
                return;
            }

            // Disable button (ITALIAN)
            this.setButtonLoading(cancelButton, true, 'Ripristino account...');
            this.clearMessages(messageContainer);

            try {
                console.log('[DataExportSettings] Cancelling account deletion');

                // API call
                const response = await api.post('/settings/data-export/cancel-deletion');

                console.log('[DataExportSettings] Account deletion cancelled', response);

                // Show success message
                this.showSuccessMessage(messageContainer, response.message || 'Account restored successfully! Deletion cancelled.');

                // Show flash notification
                if (window.showSuccess) {
                    window.showSuccess('Welcome back! Your account has been restored.');
                }

                // Reload page to hide deletion pending section
                setTimeout(() => {
                    window.location.reload();
                }, 2000);

            } catch (error) {
                console.error('[DataExportSettings] Cancel deletion failed', error);

                // Show error message
                const errorMessage = error.data?.errors?.join(', ') || error.message || 'Failed to cancel deletion';
                this.showErrorMessage(messageContainer, errorMessage);

                if (window.showError) {
                    window.showError(errorMessage);
                }

            } finally {
                this.setButtonLoading(cancelButton, false);
            }
        },

        /**
         * Initialize deletion countdown (if pending)
         */
        initDeletionCountdown() {
            const countdownElement = document.getElementById('deletion-countdown');

            if (!countdownElement) {
                // No pending deletion
                return;
            }

            const scheduledDeletionAt = countdownElement.dataset.deletionDate;

            if (!scheduledDeletionAt) {
                return;
            }

            console.log('[DataExportSettings] Initializing deletion countdown:', scheduledDeletionAt);

            // Update countdown every second
            this.deletionCountdownInterval = setInterval(() => {
                const now = new Date();
                const deletionDate = new Date(scheduledDeletionAt);
                const diffMs = deletionDate - now;

                if (diffMs <= 0) {
                    clearInterval(this.deletionCountdownInterval);
                    countdownElement.textContent = 'Account will be deleted shortly...';
                    return;
                }

                // Calculate days, hours, minutes
                const days = Math.floor(diffMs / (1000 * 60 * 60 * 24));
                const hours = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));

                countdownElement.textContent = `${days} days, ${hours} hours, ${minutes} minutes remaining`;

            }, 1000); // Update every second

            console.log('[DataExportSettings] Deletion countdown initialized');
        },

        /**
         * Set button loading state
         */
        setButtonLoading(button, loading, loadingText = 'Processing...') {
            if (loading) {
                button.disabled = true;
                button.dataset.originalText = button.textContent;
                button.innerHTML = `
                    <svg class="animate-spin h-5 w-5 mr-2 inline-block" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    ${loadingText}
                `;
            } else {
                button.disabled = false;
                button.textContent = button.dataset.originalText || 'Submit';
            }
        },

        /**
         * Clear messages
         */
        clearMessages(container) {
            if (container) {
                container.innerHTML = '';
            }
        },

        /**
         * Show success message
         */
        showSuccessMessage(container, message) {
            if (!container) return;

            container.innerHTML = `
                <div class="bg-green-500/10 border border-green-500/50 rounded-xl p-4 mb-6 flex items-start">
                    <svg class="w-5 h-5 text-green-500 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <p class="text-green-300 text-sm">${escapeHtml(message)}</p>
                </div>
            `;
        },

        /**
         * Show error message
         */
        showErrorMessage(container, message) {
            if (!container) return;

            container.innerHTML = `
                <div class="bg-red-500/10 border border-red-500/50 rounded-xl p-4 mb-6 flex items-start">
                    <svg class="w-5 h-5 text-red-500 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <p class="text-red-300 text-sm">${escapeHtml(message)}</p>
                </div>
            `;
        },

        /**
         * Cleanup on page unload
         */
        cleanup() {
            if (this.exportPollInterval) {
                clearInterval(this.exportPollInterval);
            }
            if (this.deletionCountdownInterval) {
                clearInterval(this.deletionCountdownInterval);
            }
        }
    };

    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => DataExportSettings.init());
    } else {
        DataExportSettings.init();
    }

    /**
     * Cleanup on page unload
     */
    window.addEventListener('beforeunload', () => DataExportSettings.cleanup());

})();
