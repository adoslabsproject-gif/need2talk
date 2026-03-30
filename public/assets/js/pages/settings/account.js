/**
 * need2talk - Settings: Account Page
 * Enterprise Galaxy - Avatar upload, nickname change, email change, account deletion
 *
 * Purpose: Handle all account-related settings operations
 * Security: CSRF protection, XSS prevention, client-side validation
 * Performance: Debounced validation, optimistic UI updates
 */

(function() {
    'use strict';

    /**
     * Account Settings Manager
     */
    const AccountSettings = {
        // Configuration
        avatarUploader: null,
        nicknameDebounceTimer: null,
        emailDebounceTimer: null,

        /**
         * Initialize account settings page
         */
        init() {
            console.log('[AccountSettings] Initializing...');

            // Initialize avatar uploader
            this.initAvatarUploader();

            // Initialize nickname form
            this.initNicknameForm();

            // Initialize email form
            this.initEmailForm();

            // Initialize delete account button
            this.initDeleteAccount();

            console.log('[AccountSettings] Initialization complete');
        },

        /**
         * Initialize avatar uploader
         */
        initAvatarUploader() {
            const avatarInput = document.getElementById('avatar-input');
            const avatarPreview = document.getElementById('avatar-preview');
            const uploadButton = document.getElementById('upload-avatar-btn');

            if (!avatarInput || !avatarPreview) {
                console.warn('[AccountSettings] Avatar elements not found');
                return;
            }

            // Create AvatarUploader instance (MANUAL mode with upload button)
            this.avatarUploader = new AvatarUploader({
                inputElement: avatarInput,
                previewElement: avatarPreview,
                uploadButton: uploadButton,  // User clicks "Seleziona Foto" → picks file → clicks "Salva" to upload
                endpoint: '/settings/account/avatar',
                maxFileSize: 2 * 1024 * 1024, // 2MB for avatars
                allowedTypes: ['image/jpeg', 'image/png', 'image/webp'],
                onSuccess: (response) => {
                    console.log('[AccountSettings] Avatar uploaded successfully', response);

                    // ENTERPRISE: Add cache busting to prevent browser cache issues
                    const cacheBuster = '?t=' + Date.now();
                    const avatarUrlWithCache = response.avatar_url + cacheBuster;

                    // Update navbar avatar (if exists)
                    const navbarAvatar = document.querySelector('nav img[alt*="avatar"]');
                    if (navbarAvatar && response.avatar_url) {
                        navbarAvatar.src = avatarUrlWithCache;
                    }

                    // Update all profile avatars on page
                    document.querySelectorAll('img[src*="avatar"]').forEach(img => {
                        if (response.avatar_url) {
                            img.src = avatarUrlWithCache;
                        }
                    });

                    // Update preview element with cache buster
                    if (avatarPreview) {
                        avatarPreview.src = avatarUrlWithCache;
                    }

                    // Update global user avatar (for consistency)
                    if (window.need2talk && window.need2talk.user && response.avatar_url) {
                        window.need2talk.user.avatar = response.avatar_url;
                    }

                    // Reset button text to original
                    if (uploadButton) {
                        uploadButton.innerHTML = `
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                            </svg>
                            <span>Seleziona Foto</span>
                        `;
                    }
                },
                onError: (error) => {
                    console.error('[AccountSettings] Avatar upload failed', error);
                }
            });

            console.log('[AccountSettings] Avatar uploader initialized');
        },

        /**
         * Initialize nickname change form
         * ENTERPRISE V11.11: Anti-tampering protection against console manipulation
         */
        initNicknameForm() {
            const form = document.getElementById('nickname-form');
            const nicknameInput = document.getElementById('nickname');
            const nicknameError = document.getElementById('nickname-error');
            const nicknameSuccess = document.getElementById('nickname-success');
            const submitButton = form?.querySelector('button[type="submit"]');

            if (!form || !nicknameInput) {
                console.log('[AccountSettings] Nickname form elements not found (limit reached or not editable)');
                return;
            }

            // Store original nickname FIRST (needed for tampering protection)
            this.originalNickname = nicknameInput.value.trim();

            // ENTERPRISE V11.11: Check if nickname is locked (user already changed it)
            // This flag is set server-side and cannot be bypassed
            this.nicknameLocked = nicknameInput.hasAttribute('data-locked');

            if (this.nicknameLocked) {
                console.log('[AccountSettings] Nickname is LOCKED - change limit reached');

                // Store reference for closure
                const originalNickname = this.originalNickname;

                // ENTERPRISE: MutationObserver to detect tampering via DevTools
                // If someone tries to remove disabled/readonly, we re-lock immediately
                const observer = new MutationObserver((mutations) => {
                    for (const mutation of mutations) {
                        if (mutation.type === 'attributes') {
                            const input = mutation.target;
                            // Re-apply protection if someone removes it
                            if (!input.disabled || !input.readOnly) {
                                console.warn('[AccountSettings] SECURITY: Tampering detected on locked nickname input!');
                                input.disabled = true;
                                input.readOnly = true;
                                input.value = originalNickname; // Reset value
                            }
                        }
                    }
                });

                observer.observe(nicknameInput, {
                    attributes: true,
                    attributeFilter: ['disabled', 'readonly', 'data-locked']
                });

                // Also block any input events on locked field
                nicknameInput.addEventListener('input', (e) => {
                    if (this.nicknameLocked) {
                        e.preventDefault();
                        e.stopPropagation();
                        nicknameInput.value = this.originalNickname;
                    }
                }, true);

                nicknameInput.addEventListener('keydown', (e) => {
                    if (this.nicknameLocked) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }, true);

                nicknameInput.addEventListener('paste', (e) => {
                    if (this.nicknameLocked) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }, true);

                // Exit early - no form functionality needed for locked input
                return;
            }

            // Initially disable submit button (nickname hasn't changed yet)
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.classList.add('opacity-50', 'cursor-not-allowed');
            }

            // Real-time validation (debounced)
            nicknameInput.addEventListener('input', () => {
                clearTimeout(this.nicknameDebounceTimer);
                const currentValue = nicknameInput.value.trim();

                // If nickname is same as original, disable button immediately
                if (currentValue === this.originalNickname) {
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                    }
                    this.hideElement(nicknameError);
                    this.hideElement(nicknameSuccess);
                    this.removeValidationFeedback(nicknameInput);
                    return;
                }

                this.nicknameDebounceTimer = setTimeout(() => {
                    this.validateNickname(currentValue, submitButton);
                }, 500); // 500ms debounce
            });

            // Form submit
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                // Double-check: don't submit if nickname unchanged
                if (nicknameInput.value.trim() === this.originalNickname) {
                    this.showError(nicknameError, 'Il nickname è uguale a quello attuale');
                    return;
                }

                await this.handleNicknameChange(form, nicknameInput, nicknameError, nicknameSuccess);
            });

            console.log('[AccountSettings] Nickname form initialized');
        },

        /**
         * Validate nickname (client-side + server-side availability check)
         * @param {string} nickname - The nickname to validate
         * @param {HTMLButtonElement} submitButton - Optional submit button to enable/disable
         */
        async validateNickname(nickname, submitButton = null) {
            const nicknameError = document.getElementById('nickname-error');
            const nicknameSuccess = document.getElementById('nickname-success');
            const nicknameInput = document.getElementById('nickname');

            // Helper to update button state
            const setButtonEnabled = (enabled) => {
                if (submitButton) {
                    submitButton.disabled = !enabled;
                    if (enabled) {
                        submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
                    } else {
                        submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                    }
                }
            };

            if (!nickname) {
                this.hideElement(nicknameError);
                this.hideElement(nicknameSuccess);
                this.removeValidationFeedback(nicknameInput);
                setButtonEnabled(false);
                return false;
            }

            // Length check
            if (nickname.length < 3 || nickname.length > 50) {
                this.showError(nicknameError, '❌ Il nickname deve essere tra 3 e 50 caratteri');
                this.hideElement(nicknameSuccess);
                this.setValidationFeedback(nicknameInput, false);
                setButtonEnabled(false);
                return false;
            }

            // Format check (alphanumeric + underscore + hyphen)
            const nicknameRegex = /^[a-zA-Z0-9_\-]+$/;
            if (!nicknameRegex.test(nickname)) {
                this.showError(nicknameError, '❌ Il nickname può contenere solo lettere, numeri, underscore e trattini');
                this.hideElement(nicknameSuccess);
                this.setValidationFeedback(nicknameInput, false);
                setButtonEnabled(false);
                return false;
            }

            // ENTERPRISE: Server-side availability check
            try {
                console.log('[AccountSettings] Checking nickname availability:', nickname);

                const response = await fetch(`/api/settings/check-nickname?nickname=${encodeURIComponent(nickname)}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error('Failed to check nickname availability');
                }

                const data = await response.json();

                if (data.available) {
                    // Nickname available - enable button
                    this.hideElement(nicknameError);
                    this.showSuccess(nicknameSuccess, `✅ ${data.message || 'Nickname disponibile!'}`);
                    this.setValidationFeedback(nicknameInput, true);
                    setButtonEnabled(true);
                    return true;
                } else {
                    // Nickname already taken - disable button
                    this.showError(nicknameError, `❌ ${data.message || 'Nickname già in uso'}`);
                    this.hideElement(nicknameSuccess);
                    this.setValidationFeedback(nicknameInput, false);
                    setButtonEnabled(false);
                    return false;
                }

            } catch (error) {
                console.error('[AccountSettings] Nickname availability check failed', error);
                // On network error, allow submit but disable button for safety
                this.hideElement(nicknameError);
                this.hideElement(nicknameSuccess);
                this.removeValidationFeedback(nicknameInput);
                setButtonEnabled(false);
                return false;
            }
        },

        /**
         * Set validation feedback icon on input
         */
        setValidationFeedback(input, isValid) {
            if (!input) return;

            // Remove existing feedback
            this.removeValidationFeedback(input);

            // Add border color
            if (isValid) {
                input.classList.remove('border-red-500');
                input.classList.add('border-green-500');
            } else {
                input.classList.remove('border-green-500');
                input.classList.add('border-red-500');
            }
        },

        /**
         * Remove validation feedback from input
         */
        removeValidationFeedback(input) {
            if (!input) return;
            input.classList.remove('border-green-500', 'border-red-500');
        },

        /**
         * Show success message
         */
        showSuccess(element, message) {
            if (!element) return;

            // Check if element has a text span (new structure)
            const textSpan = element.querySelector('span');
            if (textSpan) {
                textSpan.textContent = message;
            } else {
                element.textContent = message;
            }

            element.classList.remove('hidden');
            fadeIn(element, 200);
        },

        /**
         * Handle nickname change submission
         */
        async handleNicknameChange(form, nicknameInput, errorElement, successElement) {
            const nickname = nicknameInput.value.trim();
            const submitButton = form.querySelector('button[type="submit"]');

            // ENTERPRISE: Async validation with availability check
            const isValid = await this.validateNickname(nickname);
            if (!isValid) {
                console.warn('[AccountSettings] Nickname validation failed');
                return;
            }

            // Disable form
            this.setFormLoading(form, submitButton, true);
            this.hideElement(errorElement);

            try {
                console.log('[AccountSettings] Submitting nickname change:', nickname);

                // ENTERPRISE V8.2: CSRF auto-injected by csrf.js wrapper
                // No need to pass csrf_token in body - header is sufficient
                const response = await fetch('/settings/account/nickname', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ nickname: nickname })
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Failed to update nickname');
                }

                console.log('[AccountSettings] Nickname changed successfully', data);

                // Show success message
                this.hideElement(errorElement);
                this.showSuccess(successElement, `✅ ${data.message || 'Nickname updated successfully!'}`);

                // Update global user data
                if (window.need2talk && window.need2talk.user) {
                    window.need2talk.user.nickname = data.new_nickname || nickname;
                }

                // Update navbar nickname (if exists)
                const navbarNickname = document.querySelector('nav .user-nickname');
                if (navbarNickname) {
                    navbarNickname.textContent = data.new_nickname || nickname;
                }

                // ENTERPRISE: ALWAYS disable nickname change after success (1 cambio lifetime for ALL users)
                // This prevents any further attempts without page reload
                nicknameInput.disabled = true;
                nicknameInput.classList.add('cursor-not-allowed', 'opacity-60');
                submitButton.disabled = true;
                submitButton.classList.add('cursor-not-allowed', 'opacity-50');
                submitButton.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <span>Limite Raggiunto</span>
                `;

                // Update the warning banner to show limit reached
                const warningBanner = document.querySelector('.bg-yellow-500\\/10');
                if (warningBanner) {
                    warningBanner.classList.remove('bg-yellow-500/10', 'border-yellow-500/50');
                    warningBanner.classList.add('bg-red-500/10', 'border-red-500/50');
                    const warningText = warningBanner.querySelector('.text-gray-300');
                    if (warningText) {
                        warningText.innerHTML = `
                            Puoi cambiare il tuo nickname <strong>una volta sola</strong> per motivi di sicurezza e tracciabilità.
                            <span class="text-red-400 font-semibold">✗ Hai già usato il tuo cambio nickname.</span>
                        `;
                    }
                }

                // Show success message with limit info
                this.showSuccess(successElement, '✅ Nickname aggiornato! Hai usato il tuo unico cambio disponibile.');

            } catch (error) {
                console.error('[AccountSettings] Nickname change failed', error);

                // ENTERPRISE: Show detailed error message
                let errorMessage = error.message || 'Failed to update nickname';

                // Handle specific errors
                if (error.message.includes('already taken')) {
                    errorMessage = '❌ This nickname is already taken. Please choose another.';
                } else if (error.message.includes('invalid')) {
                    errorMessage = '❌ Invalid nickname format. Use only letters, numbers, and underscores.';
                } else if (error.message.includes('limit')) {
                    errorMessage = '❌ You have already used your one-time nickname change.';
                }

                this.showError(errorElement, errorMessage);
                this.hideElement(successElement);

            } finally {
                this.setFormLoading(form, submitButton, false);
            }
        },

        /**
         * Initialize email change form
         * NOTE: Email form is hidden for OAuth users (Google, etc.) - this is expected
         */
        initEmailForm() {
            const form = document.getElementById('email-form');
            const emailInput = document.getElementById('new_email');
            const emailError = document.getElementById('email-error');
            const emailSuccess = document.getElementById('email-success');

            if (!form || !emailInput) {
                // Expected for OAuth users - email managed by provider
                console.log('[AccountSettings] Email form not present (OAuth user or not editable)');
                return;
            }

            // Real-time validation (debounced)
            emailInput.addEventListener('input', () => {
                clearTimeout(this.emailDebounceTimer);
                this.emailDebounceTimer = setTimeout(() => {
                    this.validateEmail(emailInput.value.trim());
                }, 500);
            });

            // Form submit
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleEmailChange(form, emailInput, emailError, emailSuccess);
            });

            console.log('[AccountSettings] Email form initialized');
        },

        /**
         * Validate email (client-side)
         */
        validateEmail(email) {
            const emailError = document.getElementById('email-error');

            if (!email) {
                this.hideElement(emailError);
                return false;
            }

            // Use helper function
            if (!isValidEmail(email)) {
                this.showError(emailError, 'Please enter a valid email address');
                return false;
            }

            this.hideElement(emailError);
            return true;
        },

        /**
         * Handle email change submission
         */
        async handleEmailChange(form, emailInput, errorElement, successElement) {
            const newEmail = emailInput.value.trim();
            const submitButton = form.querySelector('button[type="submit"]');

            // Client-side validation
            if (!this.validateEmail(newEmail)) {
                return;
            }

            // Disable form
            this.setFormLoading(form, submitButton, true);
            this.hideElement(errorElement);
            this.hideElement(successElement);

            try {
                console.log('[AccountSettings] Requesting email change:', newEmail);

                // API call
                const response = await api.post('/settings/account/email/request', {
                    new_email: newEmail
                });

                console.log('[AccountSettings] Email change request sent', response);

                // Show success message
                if (successElement) {
                    successElement.textContent = response.message || 'Verification email sent! Check your inbox.';
                    this.showElement(successElement);
                }

                // Show flash notification
                if (window.showSuccess) {
                    window.showSuccess('Verification email sent! Please check your inbox.');
                }

                // Clear form
                emailInput.value = '';

            } catch (error) {
                console.error('[AccountSettings] Email change request failed', error);

                // Show error message
                const errorMessage = error.data?.errors?.join(', ') || error.message || 'Failed to request email change';
                this.showError(errorElement, errorMessage);

                if (window.showError) {
                    window.showError(errorMessage);
                }

            } finally {
                this.setFormLoading(form, submitButton, false);
            }
        },

        /**
         * Initialize delete account button
         * NOTE: Delete button is on separate page (/settings/data-export) - link exists instead
         */
        initDeleteAccount() {
            const deleteButton = document.getElementById('delete-account-btn');

            if (!deleteButton) {
                // Expected - delete is handled via link to /settings/data-export
                console.log('[AccountSettings] Delete button not on this page (uses link to data-export)');
                return;
            }

            deleteButton.addEventListener('click', (e) => {
                e.preventDefault();

                // Confirm deletion
                const confirmed = confirm(
                    'Are you sure you want to delete your account?\n\n' +
                    'This will:\n' +
                    '- Schedule your account for deletion in 30 days\n' +
                    '- You can cancel within 30 days\n' +
                    '- After 30 days, all data will be permanently deleted\n\n' +
                    'This action cannot be undone after the grace period!'
                );

                if (confirmed) {
                    // Redirect to data-export page for deletion
                    window.location.href = '/settings/data-export?action=delete';
                }
            });

            console.log('[AccountSettings] Delete account button initialized');
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
         * Show error message in element
         */
        showError(element, message) {
            if (!element) return;

            // Check if element has a text span (new structure)
            const textSpan = element.querySelector('span');
            if (textSpan) {
                textSpan.textContent = message;
            } else {
                element.textContent = message;
            }

            element.classList.remove('hidden');
            fadeIn(element, 200);
        },

        /**
         * Show element (fade in)
         */
        showElement(element) {
            if (!element) return;
            element.classList.remove('hidden');
            fadeIn(element, 200);
        },

        /**
         * Hide element
         */
        hideElement(element) {
            if (!element) return;
            element.classList.add('hidden');
        }
    };

    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => AccountSettings.init());
    } else {
        AccountSettings.init();
    }

})();
