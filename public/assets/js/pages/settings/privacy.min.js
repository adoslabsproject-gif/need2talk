/**
 * need2talk - Settings: Privacy Page
 * Enterprise Galaxy V5.7 - Simplified privacy settings
 *
 * Purpose: Handle privacy settings (gallery visibility, online status, friend requests, DMs)
 * Security: CSRF protection, XSS prevention
 * Performance: Optimistic UI updates
 *
 * NOTE: Profile tabs are ALWAYS private. Other users can ONLY see audio gallery.
 */

(function() {
    'use strict';

    /**
     * Privacy Settings Manager
     */
    const PrivacySettings = {

        /**
         * Initialize privacy settings page
         */
        init() {
            console.log('[PrivacySettings] Initializing...');

            // Initialize privacy form
            this.initPrivacyForm();

            console.log('[PrivacySettings] Initialization complete');
        },

        /**
         * Initialize privacy settings form
         */
        initPrivacyForm() {
            const form = document.getElementById('privacy-form');

            if (!form) {
                console.warn('[PrivacySettings] Privacy form not found');
                return;
            }

            // Form submit
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handlePrivacySubmit(form);
            });

            // Real-time preview for radio button changes
            const radioButtons = form.querySelectorAll('input[type="radio"]');
            radioButtons.forEach(radio => {
                radio.addEventListener('change', () => {
                    console.log('[PrivacySettings] Privacy setting changed:', radio.name, '=', radio.value);
                    // Could add visual feedback here
                });
            });

            console.log('[PrivacySettings] Privacy form initialized');
        },

        /**
         * Handle privacy form submission
         */
        async handlePrivacySubmit(form) {
            const submitButton = form.querySelector('button[type="submit"]');
            const messageContainer = document.getElementById('message-container');

            // Collect form data - only 3 boolean settings
            const formData = new FormData(form);
            const privacyData = {
                show_online_status: formData.has('show_online_status'),
                allow_friend_requests: formData.has('allow_friend_requests'),
                allow_direct_messages: formData.has('allow_direct_messages')
            };

            console.log('[PrivacySettings] Submitting privacy settings:', privacyData);

            // Disable form
            this.setFormLoading(form, submitButton, true);
            this.clearMessages(messageContainer);

            try {
                // API call - route is POST /settings/privacy
                const response = await api.post('/settings/privacy', privacyData);

                console.log('[PrivacySettings] Privacy settings updated successfully', response);

                // Show success message
                this.showSuccessMessage(messageContainer, response.message || 'Privacy settings updated successfully!');

                // Show flash notification
                if (window.showSuccess) {
                    window.showSuccess('Privacy settings updated!');
                }

            } catch (error) {
                console.error('[PrivacySettings] Privacy update failed', error);

                // Show error message
                const errorMessage = error.data?.errors?.join(', ') || error.message || 'Failed to update privacy settings';
                this.showErrorMessage(messageContainer, errorMessage);

                if (window.showError) {
                    window.showError(errorMessage);
                }

            } finally {
                this.setFormLoading(form, submitButton, false);
            }
        },

        /**
         * Set form loading state
         */
        setFormLoading(form, button, loading) {
            const inputs = form.querySelectorAll('input, textarea, select');

            if (loading) {
                inputs.forEach(input => input.disabled = true);
                button.disabled = true;
                button.dataset.originalText = button.textContent;
                button.innerHTML = `
                    <svg class="animate-spin h-5 w-5 mr-2 inline-block" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Saving...
                `;
            } else {
                inputs.forEach(input => input.disabled = false);
                button.disabled = false;
                button.textContent = button.dataset.originalText || 'Save Changes';
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

            // Auto-hide after 5 seconds
            setTimeout(() => {
                fadeOut(container.firstElementChild, 300);
            }, 5000);
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
        }
    };

    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => PrivacySettings.init());
    } else {
        PrivacySettings.init();
    }

})();
