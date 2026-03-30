/**
 * =================================================================
 * PASSWORD VISIBILITY TOGGLE COMPONENT
 * =================================================================
 * Reusable component for password show/hide functionality
 * Follows need2talk modular system design patterns
 * =================================================================
 */

/**
 * Password Visibility Toggle Utility
 * Can be used in any form with password fields
 */
export class PasswordVisibilityToggle {
    constructor(options = {}) {
        this.options = {
            passwordSelector: '[type="password"], [data-password-field]',
            toggleSelector: '.password-toggle, [data-password-toggle]',
            showClass: 'fa-eye',
            hideClass: 'fa-eye-slash',
            showTitle: 'Mostra password',
            hideTitle: 'Nascondi password',
            ...options
        };
        
        this.init();
    }

    /**
     * Initialize password visibility toggles
     */
    init() {
        this.bindToggleEvents();
        this.setupKeyboardAccessibility();
    }

    /**
     * Bind click events to toggle buttons
     */
    bindToggleEvents() {
        document.addEventListener('click', (e) => {
            const toggleButton = e.target.closest(this.options.toggleSelector);
            if (!toggleButton) return;

            e.preventDefault();
            this.togglePasswordVisibility(toggleButton);
        });
    }

    /**
     * Setup keyboard accessibility for password toggles
     */
    setupKeyboardAccessibility() {
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            
            const toggleButton = e.target.closest(this.options.toggleSelector);
            if (!toggleButton) return;

            e.preventDefault();
            this.togglePasswordVisibility(toggleButton);
        });
    }

    /**
     * Toggle password visibility for a specific field
     */
    togglePasswordVisibility(toggleButton) {
        const passwordField = this.getPasswordField(toggleButton);
        if (!passwordField) return;

        const isCurrentlyVisible = passwordField.type === 'text';
        const newType = isCurrentlyVisible ? 'password' : 'text';
        
        // Update field type
        passwordField.type = newType;
        
        // Update toggle button appearance
        this.updateToggleButton(toggleButton, !isCurrentlyVisible);
        
        // Maintain focus on password field if it was focused
        if (document.activeElement === passwordField) {
            passwordField.focus();
        }

        // Trigger custom event for other components to listen
        this.dispatchToggleEvent(passwordField, !isCurrentlyVisible);
    }

    /**
     * Get the password field associated with a toggle button
     */
    getPasswordField(toggleButton) {
        // First, try to find by data attribute
        const fieldId = toggleButton.dataset.passwordField;
        if (fieldId) {
            return document.getElementById(fieldId);
        }

        // Then, try to find in same parent container
        const container = toggleButton.closest('.relative, .form-group, .input-group');
        if (container) {
            return container.querySelector(this.options.passwordSelector);
        }

        // Finally, try to find the previous input field
        return toggleButton.previousElementSibling?.matches(this.options.passwordSelector) 
            ? toggleButton.previousElementSibling 
            : null;
    }

    /**
     * Update the toggle button's appearance
     */
    updateToggleButton(toggleButton, isVisible) {
        const icon = toggleButton.querySelector('i, .fa, [data-icon]');
        if (!icon) return;

        // Update icon classes
        icon.classList.remove(this.options.showClass, this.options.hideClass);
        icon.classList.add(isVisible ? this.options.hideClass : this.options.showClass);

        // Update button title/aria-label
        const newTitle = isVisible ? this.options.hideTitle : this.options.showTitle;
        toggleButton.title = newTitle;
        toggleButton.setAttribute('aria-label', newTitle);
    }

    /**
     * Dispatch custom event when password visibility changes
     */
    dispatchToggleEvent(passwordField, isVisible) {
        const event = new CustomEvent('passwordVisibilityToggle', {
            detail: {
                field: passwordField,
                visible: isVisible,
                fieldId: passwordField.id || passwordField.name
            },
            bubbles: true
        });
        
        passwordField.dispatchEvent(event);
    }

    /**
     * Programmatically toggle password visibility by field ID
     */
    toggleById(fieldId, forceVisible = null) {
        const passwordField = document.getElementById(fieldId);
        if (!passwordField) return;

        const container = passwordField.closest('.relative, .form-group, .input-group');
        const toggleButton = container?.querySelector(this.options.toggleSelector);
        
        if (toggleButton) {
            if (forceVisible !== null) {
                const isCurrentlyVisible = passwordField.type === 'text';
                if ((forceVisible && !isCurrentlyVisible) || (!forceVisible && isCurrentlyVisible)) {
                    this.togglePasswordVisibility(toggleButton);
                }
            } else {
                this.togglePasswordVisibility(toggleButton);
            }
        }
    }

    /**
     * Show password for a specific field
     */
    showPassword(fieldId) {
        this.toggleById(fieldId, true);
    }

    /**
     * Hide password for a specific field
     */
    hidePassword(fieldId) {
        this.toggleById(fieldId, false);
    }

    /**
     * Check if password is currently visible for a field
     */
    isPasswordVisible(fieldId) {
        const passwordField = document.getElementById(fieldId);
        return passwordField ? passwordField.type === 'text' : false;
    }
}

/**
 * Alpine.js Password Visibility Mixin
 * For use with Alpine.js components
 */
export const passwordVisibilityMixin = {
    showPassword: false,
    showPasswordConfirmation: false,
    
    togglePassword(fieldType = 'password') {
        if (fieldType === 'confirmation') {
            this.showPasswordConfirmation = !this.showPasswordConfirmation;
        } else {
            this.showPassword = !this.showPassword;
        }
    },
    
    getPasswordType(fieldType = 'password') {
        if (fieldType === 'confirmation') {
            return this.showPasswordConfirmation ? 'text' : 'password';
        }
        return this.showPassword ? 'text' : 'password';
    },
    
    getPasswordIcon(fieldType = 'password') {
        if (fieldType === 'confirmation') {
            return this.showPasswordConfirmation ? 'fa-eye-slash' : 'fa-eye';
        }
        return this.showPassword ? 'fa-eye-slash' : 'fa-eye';
    },
    
    getPasswordTitle(fieldType = 'password') {
        if (fieldType === 'confirmation') {
            return this.showPasswordConfirmation ? 'Nascondi password' : 'Mostra password';
        }
        return this.showPassword ? 'Nascondi password' : 'Mostra password';
    }
};

/**
 * Auto-initialize password visibility toggles when DOM is ready
 */
function initializePasswordVisibility() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            new PasswordVisibilityToggle();
        });
    } else {
        new PasswordVisibilityToggle();
    }
}

// Export for global use
window.PasswordVisibilityToggle = PasswordVisibilityToggle;
window.passwordVisibilityMixin = passwordVisibilityMixin;

// Auto-initialize if not in module environment
if (typeof module === 'undefined') {
    initializePasswordVisibility();
}

export default PasswordVisibilityToggle;