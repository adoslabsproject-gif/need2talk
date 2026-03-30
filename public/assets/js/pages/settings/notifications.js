/**
 * need2talk - Settings: Notifications Page
 * Enterprise Galaxy - Email notification preferences
 *
 * Purpose: Handle email notification settings (master toggle + granular controls)
 * Security: CSRF protection, XSS prevention
 * Performance: Optimistic UI updates, batch form submission
 */

(function() {
    'use strict';

    /**
     * Notification Settings Manager
     */
    const NotificationSettings = {
        /**
         * Initialize notification settings page
         */
        init() {
            console.log('[NotificationSettings] Initializing...');

            // Initialize notifications form
            this.initNotificationsForm();

            // Initialize master toggle logic
            this.initMasterToggle();

            console.log('[NotificationSettings] Initialization complete');
        },

        /**
         * Initialize notifications form
         */
        initNotificationsForm() {
            const form = document.getElementById('notifications-form');

            if (!form) {
                console.warn('[NotificationSettings] Notifications form not found');
                return;
            }

            // Form submit
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleNotificationsSubmit(form);
            });

            // Visual feedback on checkbox change
            const checkboxes = form.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    console.log('[NotificationSettings] Notification setting changed:', checkbox.name, '=', checkbox.checked);

                    // Visual feedback
                    const label = checkbox.closest('label');
                    if (label) {
                        if (checkbox.checked) {
                            label.classList.add('bg-purple-500/10', 'border-purple-500/30');
                        } else {
                            label.classList.remove('bg-purple-500/10', 'border-purple-500/30');
                        }
                    }
                });
            });

            console.log('[NotificationSettings] Notifications form initialized');
        },

        /**
         * Initialize master toggle logic
         */
        initMasterToggle() {
            const masterToggle = document.querySelector('input[name="email_notifications"]');
            const granularCheckboxes = document.querySelectorAll(
                'input[name="email_friend_requests"], ' +
                'input[name="email_comments"], ' +
                'input[name="email_reactions"], ' +
                'input[name="email_newsletter"]'
            );

            if (!masterToggle) {
                // Master toggle is optional - silently skip if not present
                return;
            }

            // Master toggle change event
            masterToggle.addEventListener('change', () => {
                console.log('[NotificationSettings] Master toggle changed:', masterToggle.checked);

                // Enable/disable granular checkboxes
                granularCheckboxes.forEach(checkbox => {
                    checkbox.disabled = !masterToggle.checked;

                    // Visual feedback
                    const label = checkbox.closest('label');
                    if (label) {
                        if (masterToggle.checked) {
                            label.classList.remove('opacity-50', 'cursor-not-allowed');
                            label.classList.add('cursor-pointer');
                        } else {
                            label.classList.add('opacity-50', 'cursor-not-allowed');
                            label.classList.remove('cursor-pointer');
                        }
                    }
                });
            });

            // Trigger initial state
            masterToggle.dispatchEvent(new Event('change'));

            console.log('[NotificationSettings] Master toggle initialized');
        },

        /**
         * Handle notifications form submission
         */
        async handleNotificationsSubmit(form) {
            const submitButton = form.querySelector('button[type="submit"]');
            const messageContainer = document.getElementById('message-container');

            // Collect form data - field names must match PHP controller expectations
            const formData = new FormData(form);
            const notificationData = {
                // In-app notification preferences (campanella)
                notify_comments: formData.has('notify_comments'),
                notify_replies: formData.has('notify_replies'),
                notify_reactions: formData.has('notify_reactions'),
                notify_comment_likes: formData.has('notify_comment_likes'),
                notify_mentions: formData.has('notify_mentions'),
                notify_friend_requests: formData.has('notify_friend_requests'),
                notify_friend_accepted: formData.has('notify_friend_accepted'),
                // Chat/DM notifications
                notify_dm_received: formData.has('notify_dm_received'),
                // Newsletter (GDPR opt-in)
                email_newsletter: formData.has('email_newsletter')
            };

            console.log('[NotificationSettings] Submitting notification preferences:', notificationData);

            // Disable form
            this.setFormLoading(form, submitButton, true);
            this.clearMessages(messageContainer);

            try {
                // API call
                const response = await api.post('/settings/notifications', notificationData);

                console.log('[NotificationSettings] Notification preferences updated successfully', response);

                // Show success message
                this.showSuccessMessage(messageContainer, response.message || 'Notification preferences updated!');

                // Show flash notification
                if (window.showSuccess) {
                    window.showSuccess('Notification preferences updated!');
                }

            } catch (error) {
                console.error('[NotificationSettings] Notification update failed', error);

                // Show error message
                const errorMessage = error.data?.errors?.join(', ') || error.message || 'Failed to update notification preferences';
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
                button.textContent = button.dataset.originalText || 'Save Preferences';
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
        document.addEventListener('DOMContentLoaded', () => NotificationSettings.init());
    } else {
        NotificationSettings.init();
    }

})();
