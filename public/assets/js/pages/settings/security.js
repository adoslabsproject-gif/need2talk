/**
 * need2talk - Settings: Security Page
 * Enterprise Galaxy - Password change with strength meter
 *
 * Purpose: Handle password change with real-time strength validation
 * Security: CSRF protection, XSS prevention, password strength enforcement
 * Performance: Debounced validation, visual feedback
 */

(function() {
    'use strict';

    /**
     * Security Settings Manager
     */
    const SecuritySettings = {
        // Configuration
        passwordDebounceTimer: null,

        /**
         * Initialize security settings page
         */
        init() {
            console.log('[SecuritySettings] Initializing...');

            // Initialize password form
            this.initPasswordForm();

            // Initialize password strength meter
            this.initPasswordStrengthMeter();

            console.log('[SecuritySettings] Initialization complete');
        },

        /**
         * Initialize password change form
         */
        initPasswordForm() {
            const form = document.getElementById('password-form');

            if (!form) {
                console.warn('[SecuritySettings] Password form not found');
                return;
            }

            // Form submit
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handlePasswordChange(form);
            });

            console.log('[SecuritySettings] Password form initialized');
        },

        /**
         * Initialize password strength meter
         */
        initPasswordStrengthMeter() {
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const strengthContainer = document.getElementById('password-strength');
            const strengthBar = document.getElementById('strength-bar');
            const strengthText = document.getElementById('strength-text');

            if (!newPasswordInput || !strengthContainer || !strengthBar || !strengthText) {
                console.warn('[SecuritySettings] Password strength elements not found');
                return;
            }

            // Real-time strength validation (debounced)
            newPasswordInput.addEventListener('input', () => {
                clearTimeout(this.passwordDebounceTimer);

                const password = newPasswordInput.value;

                if (!password) {
                    strengthContainer.classList.add('hidden');
                    return;
                }

                // Show strength meter
                strengthContainer.classList.remove('hidden');

                // Debounced strength calculation
                this.passwordDebounceTimer = setTimeout(() => {
                    const strength = this.calculatePasswordStrength(password);
                    this.updateStrengthMeter(strengthBar, strengthText, strength);
                }, 150); // 150ms debounce for smooth UX
            });

            // Confirm password validation
            confirmPasswordInput.addEventListener('input', () => {
                this.validatePasswordMatch(newPasswordInput.value, confirmPasswordInput.value);
            });

            console.log('[SecuritySettings] Password strength meter initialized');
        },

        /**
         * Calculate password strength
         */
        calculatePasswordStrength(password) {
            let score = 0;
            let feedback = [];

            // Length check
            if (password.length >= 8) {
                score += 25;
            } else {
                feedback.push('At least 8 characters');
            }

            // Uppercase check
            if (/[A-Z]/.test(password)) {
                score += 25;
            } else {
                feedback.push('One uppercase letter');
            }

            // Lowercase check
            if (/[a-z]/.test(password)) {
                score += 25;
            } else {
                feedback.push('One lowercase letter');
            }

            // Number check
            if (/[0-9]/.test(password)) {
                score += 25;
            } else {
                feedback.push('One number');
            }

            // Bonus: Special characters
            if (/[^A-Za-z0-9]/.test(password)) {
                score += 10;
            }

            // Bonus: Length > 12
            if (password.length >= 12) {
                score += 10;
            }

            // Determine strength level
            let level = 'weak';
            if (score >= 90) level = 'very-strong';
            else if (score >= 75) level = 'strong';
            else if (score >= 50) level = 'medium';

            return {
                score: Math.min(score, 100),
                level: level,
                feedback: feedback
            };
        },

        /**
         * Update strength meter UI
         */
        updateStrengthMeter(barElement, textElement, strength) {
            const { score, level, feedback } = strength;

            // Update bar width
            barElement.style.width = `${score}%`;

            // Update bar color
            if (level === 'very-strong') {
                barElement.className = 'h-full rounded-full transition-all bg-green-500';
                textElement.className = 'text-xs font-medium text-green-400';
                textElement.textContent = 'Very Strong';
            } else if (level === 'strong') {
                barElement.className = 'h-full rounded-full transition-all bg-blue-500';
                textElement.className = 'text-xs font-medium text-blue-400';
                textElement.textContent = 'Strong';
            } else if (level === 'medium') {
                barElement.className = 'h-full rounded-full transition-all bg-yellow-500';
                textElement.className = 'text-xs font-medium text-yellow-400';
                textElement.textContent = 'Medium';
            } else {
                barElement.className = 'h-full rounded-full transition-all bg-red-500';
                textElement.className = 'text-xs font-medium text-red-400';
                textElement.textContent = 'Weak';
            }

            console.log('[SecuritySettings] Password strength:', level, `(${score}/100)`);
        },

        /**
         * Validate password match
         */
        validatePasswordMatch(password, confirmPassword) {
            const confirmInput = document.getElementById('confirm_password');
            const confirmError = document.getElementById('confirm-password-error');

            if (!confirmPassword) {
                if (confirmError) confirmError.classList.add('hidden');
                return false;
            }

            if (password !== confirmPassword) {
                if (confirmError) {
                    confirmError.textContent = 'Passwords do not match';
                    confirmError.classList.remove('hidden');
                }
                confirmInput.classList.add('border-red-500');
                return false;
            }

            if (confirmError) confirmError.classList.add('hidden');
            confirmInput.classList.remove('border-red-500');
            confirmInput.classList.add('border-green-500');
            return true;
        },

        /**
         * Handle password change submission
         */
        async handlePasswordChange(form) {
            const currentPasswordInput = document.getElementById('current_password');
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const submitButton = form.querySelector('button[type="submit"]');
            const messageContainer = document.getElementById('message-container');

            const currentPassword = currentPasswordInput ? currentPasswordInput.value.trim() : '';
            const newPassword = newPasswordInput.value.trim();
            const confirmPassword = confirmPasswordInput.value.trim();

            // Client-side validation
            if (currentPasswordInput && !currentPassword) {
                this.showErrorMessage(messageContainer, 'Current password is required');
                return;
            }

            if (!newPassword) {
                this.showErrorMessage(messageContainer, 'New password is required');
                return;
            }

            // Check password strength
            const strength = this.calculatePasswordStrength(newPassword);
            if (strength.score < 50) {
                this.showErrorMessage(messageContainer, 'Password is too weak. Requirements: ' + strength.feedback.join(', '));
                return;
            }

            // Check password match
            if (!this.validatePasswordMatch(newPassword, confirmPassword)) {
                this.showErrorMessage(messageContainer, 'Passwords do not match');
                return;
            }

            // Disable form
            this.setFormLoading(form, submitButton, true);
            this.clearMessages(messageContainer);

            // Prepare data
            const data = {
                new_password: newPassword,
                confirm_password: confirmPassword
            };

            if (currentPasswordInput) {
                data.current_password = currentPassword;
            }

            try {
                console.log('[SecuritySettings] Submitting password change');

                // API call
                const response = await api.post('/settings/security/password', data);

                console.log('[SecuritySettings] Password changed successfully', response);

                // Show success message
                this.showSuccessMessage(messageContainer, response.message || 'Password changed successfully!');

                // Show flash notification
                if (window.showSuccess) {
                    window.showSuccess('Password changed successfully!');
                }

                // Clear form
                form.reset();
                document.getElementById('password-strength').classList.add('hidden');

            } catch (error) {
                console.error('[SecuritySettings] Password change failed', error);

                // Show error message
                const errorMessage = error.data?.errors?.join(', ') || error.message || 'Failed to change password';
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
                    Changing Password...
                `;
            } else {
                inputs.forEach(input => input.disabled = false);
                button.disabled = false;
                button.textContent = button.dataset.originalText || 'Change Password';
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
        document.addEventListener('DOMContentLoaded', () => SecuritySettings.init());
    } else {
        SecuritySettings.init();
    }

})();
