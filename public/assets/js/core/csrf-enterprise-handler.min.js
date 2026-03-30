/**
 * need2talk - Enterprise CSRF Error Handler
 * Gestione avanzata errori CSRF 419 con auto-recovery
 */

Need2Talk.CSRFEnterpriseHandler = {
    initialized: false,
    formDataBackup: null,
    retryAttempts: 0,
    maxRetries: 1,

    /**
     * Initialize enterprise CSRF error handling
     */
    init() {
        if (this.initialized) return;

        Need2Talk.Logger.info('CSRFHandler', '🛡️ Initializing enterprise CSRF error handler...');

        // Intercept ALL fetch requests globally
        this.interceptFetch();

        // Intercept form submissions
        this.interceptForms();

        // Restore form data if page was reloaded due to CSRF
        this.restoreFormData();

        this.initialized = true;
        Need2Talk.Logger.info('CSRFHandler', '✅ Enterprise CSRF handler initialized');
    },

    /**
     * Intercept ALL fetch requests to handle 419 globally
     */
    interceptFetch() {
        if (window.fetch._csrfHandlerWrapped) return;

        const originalFetch = window.fetch.bind(window);

        window.fetch = async (url, options = {}) => {
            try {
                const response = await originalFetch(url, options);

                // Check for 419 CSRF error
                if (response.status === 419) {
                    return await this.handle419Response(response, url, options);
                }

                return response;

            } catch (error) {
                Need2Talk.Logger.error('CSRFHandler', 'Fetch error', error);
                throw error;
            }
        };

        window.fetch._csrfHandlerWrapped = true;
        Need2Talk.Logger.debug('CSRFHandler', 'Fetch interceptor installed');
    },

    /**
     * Handle 419 CSRF error response - ENTERPRISE SOLUTION
     */
    async handle419Response(response, url, options) {
        Need2Talk.Logger.warn('CSRFHandler', '⚠️ CSRF token expired (419)', { url, method: options.method });

        try {
            const data = await response.clone().json();

            // Update CSRF token if provided
            if (data.new_token) {
                this.updateCSRFToken(data.new_token);
                Need2Talk.Logger.info('CSRFHandler', '🔄 CSRF token updated from 419 response');
            }

            // Save form data if this was a POST/PUT/PATCH request
            if (options.method && ['POST', 'PUT', 'PATCH'].includes(options.method.toUpperCase())) {
                this.saveFormDataToStorage(options);
            }

            // Show enterprise-grade message and reload
            this.showExpiredSessionMessage(data.message);

            // Auto-reload page after 2 seconds
            setTimeout(() => {
                this.reloadPageWithMessage(
                    'La pagina è stata ricaricata perché la tua sessione era scaduta. Riprova ora.',
                    'warning'
                );
            }, 2000);

        } catch (error) {
            Need2Talk.Logger.error('CSRFHandler', 'Failed to parse 419 response', error);

            // Fallback: just reload with message
            this.reloadPageWithMessage(
                'Sessione scaduta. La pagina è stata ricaricata automaticamente.',
                'warning'
            );
        }

        // Return the original response (won't be used, page will reload)
        return response;
    },

    /**
     * Update CSRF token in meta tag and Need2Talk.CSRF
     */
    updateCSRFToken(newToken) {
        // Update meta tag
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        if (metaTag) {
            metaTag.setAttribute('content', newToken);
        }

        // Update Need2Talk.CSRF module if available
        if (window.Need2Talk && window.Need2Talk.CSRF) {
            Need2Talk.CSRF.token = newToken;
        }

        Need2Talk.Logger.debug('CSRFHandler', 'CSRF token updated in DOM and memory', {
            tokenLength: newToken.length
        });
    },

    /**
     * Save form data to sessionStorage before reload
     */
    saveFormDataToStorage(fetchOptions) {
        try {
            const formData = {
                timestamp: Date.now(),
                url: window.location.href,
                body: fetchOptions.body ? JSON.parse(fetchOptions.body) : null,
                method: fetchOptions.method
            };

            // Remove sensitive fields
            if (formData.body) {
                delete formData.body.password;
                delete formData.body.password_confirmation;
                delete formData.body._csrf_token;
            }

            sessionStorage.setItem('need2talk_form_backup', JSON.stringify(formData));
            Need2Talk.Logger.info('CSRFHandler', '💾 Form data saved to sessionStorage');

        } catch (error) {
            Need2Talk.Logger.warn('CSRFHandler', 'Failed to save form data', error);
        }
    },

    /**
     * Restore form data after reload
     */
    restoreFormData() {
        try {
            const backup = sessionStorage.getItem('need2talk_form_backup');
            if (!backup) return;

            const formData = JSON.parse(backup);

            // Check if backup is recent (within 5 minutes)
            const age = Date.now() - formData.timestamp;
            if (age > 5 * 60 * 1000) {
                sessionStorage.removeItem('need2talk_form_backup');
                return;
            }

            // Check if we're on the same page
            if (formData.url !== window.location.href) {
                return;
            }

            // Restore form fields
            if (formData.body) {
                this.populateFormFields(formData.body);
                Need2Talk.Logger.info('CSRFHandler', '♻️ Form data restored from backup');
            }

            // Clear backup after restoration
            sessionStorage.removeItem('need2talk_form_backup');

        } catch (error) {
            Need2Talk.Logger.warn('CSRFHandler', 'Failed to restore form data', error);
            sessionStorage.removeItem('need2talk_form_backup');
        }
    },

    /**
     * Populate form fields with saved data
     */
    populateFormFields(data) {
        Object.keys(data).forEach(key => {
            // Find input by name
            const input = document.querySelector(`input[name="${key}"], textarea[name="${key}"], select[name="${key}"]`);

            if (input) {
                if (input.type === 'checkbox') {
                    input.checked = !!data[key];
                } else if (input.type === 'radio') {
                    const radio = document.querySelector(`input[name="${key}"][value="${data[key]}"]`);
                    if (radio) radio.checked = true;
                } else {
                    input.value = data[key];
                }

                // Trigger input event for reactive frameworks (Alpine.js)
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    },

    /**
     * Show expired session message (enterprise style)
     */
    showExpiredSessionMessage(message) {
        const defaultMessage = 'La tua sessione è scaduta. La pagina verrà ricaricata automaticamente...';

        if (window.Need2Talk && window.Need2Talk.FlashMessages) {
            Need2Talk.FlashMessages.warning(message || defaultMessage, 0, {
                details: 'I tuoi dati saranno ripristinati dopo il ricaricamento.',
                showClose: false
            });
        } else if (window.showWarning) {
            showWarning(message || defaultMessage);
        } else {
            // Fallback: console only
            console.warn('⚠️ ', message || defaultMessage);
        }
    },

    /**
     * Reload page with flash message in sessionStorage
     */
    reloadPageWithMessage(message, type = 'info') {
        try {
            const flashData = {
                message: message,
                type: type,
                timestamp: Date.now()
            };

            sessionStorage.setItem('need2talk_flash_after_reload', JSON.stringify(flashData));
            Need2Talk.Logger.info('CSRFHandler', '🔄 Reloading page with flash message');

            // Hard reload
            window.location.reload();

        } catch (error) {
            Need2Talk.Logger.error('CSRFHandler', 'Failed to reload with message', error);
            window.location.reload();
        }
    },

    /**
     * Show flash message after page reload
     */
    showFlashAfterReload() {
        try {
            const flashData = sessionStorage.getItem('need2talk_flash_after_reload');
            if (!flashData) return;

            const flash = JSON.parse(flashData);

            // Check if message is recent (within 10 seconds)
            const age = Date.now() - flash.timestamp;
            if (age > 10000) {
                sessionStorage.removeItem('need2talk_flash_after_reload');
                return;
            }

            // Show the flash message
            if (window.Need2Talk && window.Need2Talk.FlashMessages) {
                Need2Talk.FlashMessages.show(flash.message, flash.type, 8000);
            } else if (window.showNotification) {
                showNotification(flash.message, flash.type);
            }

            // Clear after showing
            sessionStorage.removeItem('need2talk_flash_after_reload');

        } catch (error) {
            Need2Talk.Logger.warn('CSRFHandler', 'Failed to show flash after reload', error);
            sessionStorage.removeItem('need2talk_flash_after_reload');
        }
    },

    /**
     * Intercept form submissions
     */
    interceptForms() {
        document.addEventListener('submit', (event) => {
            const form = event.target;

            // Save form data before submission (for potential 419 errors)
            if (form.method.toLowerCase() !== 'get') {
                this.backupFormData(form);
            }
        });
    },

    /**
     * Backup form data before submission
     */
    backupFormData(form) {
        try {
            const formData = new FormData(form);
            const data = {};

            for (const [key, value] of formData.entries()) {
                // Skip sensitive fields
                if (['password', 'password_confirmation', '_csrf_token'].includes(key)) {
                    continue;
                }
                data[key] = value;
            }

            this.formDataBackup = data;
            Need2Talk.Logger.debug('CSRFHandler', 'Form data backed up', { fields: Object.keys(data).length });

        } catch (error) {
            Need2Talk.Logger.warn('CSRFHandler', 'Failed to backup form data', error);
        }
    }
};

// Initialize when app is ready
if (window.Need2Talk && window.Need2Talk.events) {
    Need2Talk.events.addEventListener('app:ready', () => {
        Need2Talk.CSRFEnterpriseHandler.init();

        // Show flash message if reloaded due to CSRF
        setTimeout(() => {
            Need2Talk.CSRFEnterpriseHandler.showFlashAfterReload();
        }, 500);
    });
} else {
    // Fallback: init on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            if (window.Need2Talk && window.Need2Talk.CSRFEnterpriseHandler) {
                Need2Talk.CSRFEnterpriseHandler.init();
                Need2Talk.CSRFEnterpriseHandler.showFlashAfterReload();
            }
        }, 1000);
    });
}
