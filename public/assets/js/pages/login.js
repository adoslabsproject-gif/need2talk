/**
 * =================================================================
 * LOGIN PAGE - JAVASCRIPT FUNCTIONALITY
 * =================================================================
 * Sicuro per migliaia di utenti simultanei
 * Performance ottimizzate e anti-malicious
 * =================================================================
 */

'use strict';

// Login Page Controller
class LoginPage {
    constructor() {
        this.isInitialized = false;
        this.form = null;
        this.submitButton = null;
        this.isSubmitting = false;
        this.validators = {};
        
        this.init();
    }

    /**
     * Initialize login page functionality
     */
    init() {
        if (this.isInitialized) return;
        
        this.form = document.getElementById('login-form');
        this.submitButton = document.getElementById('submit-button');
        
        if (!this.form) {
            Need2Talk.Logger.warn('LoginPage', 'Login form not found');
            return;
        }
        
        this.bindEvents();
        this.setupValidation();
        this.setupPasswordToggle();
        this.setupAnimations();
        this.isInitialized = true;
        
        Need2Talk.Logger.info('LoginPage', 'Login page initialized successfully');
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Form submission
        this.form.addEventListener('submit', this.handleSubmit.bind(this));
        
        // Real-time validation
        const inputs = this.form.querySelectorAll('.form-input');
        inputs.forEach(input => {
            input.addEventListener('input', this.debounce(this.validateField.bind(this, input), 300));
            input.addEventListener('blur', this.validateField.bind(this, input));
        });

        // Enter key handling
        inputs.forEach(input => {
            input.addEventListener('keypress', (event) => {
                if (event.key === 'Enter' && !this.isSubmitting) {
                    event.preventDefault();
                    this.handleSubmit(event);
                }
            });
        });

        // Remember me checkbox
        const rememberCheckbox = document.getElementById('remember-me');
        if (rememberCheckbox) {
            rememberCheckbox.addEventListener('change', this.handleRememberMe.bind(this));
        }

        // Links
        const registerLink = document.querySelector('.register-link');
        if (registerLink) {
            registerLink.addEventListener('click', this.handleRegisterLink.bind(this));
        }

        const forgotPasswordLink = document.querySelector('.forgot-password');
        if (forgotPasswordLink) {
            forgotPasswordLink.addEventListener('click', this.handleForgotPassword.bind(this));
        }
    }

    /**
     * Setup form validation
     */
    setupValidation() {
        this.validators = {
            email: {
                required: true,
                pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                message: 'Inserisci un indirizzo email valido'
            },
            password: {
                required: true,
                minLength: 1, // For login, we don't enforce complexity
                message: 'La password è richiesta'
            }
        };
    }

    /**
     * Setup password toggle visibility
     */
    setupPasswordToggle() {
        const passwordInput = document.getElementById('password');
        const toggleButton = document.getElementById('toggle-password');
        
        if (!passwordInput || !toggleButton) return;

        toggleButton.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            
            const icon = toggleButton.querySelector('i');
            if (icon) {
                icon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
            }
            
            // Security: Clear clipboard if password was visible
            if (!isPassword) {
                setTimeout(() => {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText('');
                    }
                }, 100);
            }
        });
    }

    /**
     * Setup page animations
     */
    setupAnimations() {
        // Fade in animation
        const container = document.querySelector('.login-container');
        if (container) {
            container.style.opacity = '0';
            container.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                container.style.transition = 'all 0.6s ease-out';
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
        }

        // Stagger input animations
        const formGroups = document.querySelectorAll('.form-group');
        formGroups.forEach((group, index) => {
            group.style.opacity = '0';
            group.style.transform = 'translateY(15px)';
            
            setTimeout(() => {
                group.style.transition = 'all 0.4s ease-out';
                group.style.opacity = '1';
                group.style.transform = 'translateY(0)';
            }, 200 + index * 100);
        });
    }

    /**
     * Validate individual field
     */
    validateField(input) {
        const fieldName = input.name;
        const value = input.value.trim();
        const validator = this.validators[fieldName];
        
        if (!validator) return true;

        // Clear previous validation state
        this.clearFieldValidation(input);

        // Required field validation
        if (validator.required && !value) {
            this.showFieldError(input, validator.message || 'Questo campo è obbligatorio');
            return false;
        }

        // Pattern validation
        if (value && validator.pattern && !validator.pattern.test(value)) {
            this.showFieldError(input, validator.message || 'Formato non valido');
            return false;
        }

        // Min length validation
        if (value && validator.minLength && value.length < validator.minLength) {
            this.showFieldError(input, `Minimo ${validator.minLength} caratteri`);
            return false;
        }

        // Show success state
        this.showFieldSuccess(input);
        return true;
    }

    /**
     * Show field error state
     */
    showFieldError(input, message) {
        input.classList.add('error');
        input.classList.remove('success');
        
        // Remove existing error message
        const existingError = input.parentNode.querySelector('.field-error');
        if (existingError) {
            existingError.remove();
        }

        // Add error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        input.parentNode.appendChild(errorDiv);
    }

    /**
     * Show field success state
     */
    showFieldSuccess(input) {
        input.classList.add('success');
        input.classList.remove('error');
        
        // Remove error message
        const existingError = input.parentNode.querySelector('.field-error');
        if (existingError) {
            existingError.remove();
        }
    }

    /**
     * Clear field validation state
     */
    clearFieldValidation(input) {
        input.classList.remove('error', 'success');
        const existingError = input.parentNode.querySelector('.field-error');
        if (existingError) {
            existingError.remove();
        }
    }

    /**
     * Validate entire form
     */
    validateForm() {
        let isValid = true;
        const inputs = this.form.querySelectorAll('.form-input');
        
        inputs.forEach(input => {
            if (!this.validateField(input)) {
                isValid = false;
            }
        });
        
        return isValid;
    }

    /**
     * Handle form submission
     */
    async handleSubmit(event) {
        event.preventDefault();
        
        if (this.isSubmitting) return;

        // Validate form
        if (!this.validateForm()) {
            this.showError('Correggi gli errori nel modulo prima di continuare');
            return;
        }

        try {
            this.isSubmitting = true;
            this.setSubmitButtonState(true);
            
            // Get form data
            const formData = new FormData(this.form);
            const loginData = {
                email: formData.get('email'),
                password: formData.get('password'),
                remember_me: formData.get('remember_me') === 'on'
            };

            // Security: Basic client-side checks
            if (!this.isValidEmail(loginData.email)) {
                throw new Error('Email non valida');
            }

            // Submit login
            const response = await this.submitLogin(loginData);
            
            if (response.success) {
                this.showSuccess('Login effettuato con successo!');
                
                // Redirect after short delay
                setTimeout(() => {
                    window.location.href = response.redirect || '/dashboard';
                }, 1500);
            } else {
                throw new Error(response.message || 'Errore durante il login');
            }
            
        } catch (error) {
            Need2Talk.Logger.error('LoginPage', 'Login error', error);
            this.showError(error.message || 'Errore durante il login. Riprova.');
        } finally {
            this.isSubmitting = false;
            this.setSubmitButtonState(false);
        }
    }

    /**
     * Submit login request
     */
    async submitLogin(loginData) {
        const response = await fetch('/auth/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': this.getCSRFToken()
            },
            body: JSON.stringify(loginData),
            credentials: 'same-origin'
        });

        if (!response.ok) {
            if (response.status === 429) {
                throw new Error('Troppi tentativi. Riprova tra qualche minuto.');
            }
            if (response.status === 401) {
                throw new Error('Email o password non corretti');
            }
            throw new Error('Errore del server. Riprova più tardi.');
        }

        return await response.json();
    }

    /**
     * Get CSRF token
     */
    getCSRFToken() {
        const token = document.querySelector('meta[name="csrf-token"]');
        return token ? token.getAttribute('content') : '';
    }

    /**
     * Set submit button state
     */
    setSubmitButtonState(loading) {
        if (!this.submitButton) return;

        if (loading) {
            this.submitButton.disabled = true;
            this.submitButton.innerHTML = '<div class="spinner"></div> Accesso in corso...';
        } else {
            this.submitButton.disabled = false;
            this.submitButton.innerHTML = 'Accedi';
        }
    }

    /**
     * Show error message
     */
    showError(message) {
        this.clearMessages();
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-container';
        errorDiv.innerHTML = `<p class="error-text"><i class="fas fa-exclamation-triangle"></i> ${message}</p>`;
        
        this.form.insertBefore(errorDiv, this.form.firstChild);
        
        // Remove error after 10 seconds
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 10000);
    }

    /**
     * Show success message
     */
    showSuccess(message) {
        this.clearMessages();
        
        const successDiv = document.createElement('div');
        successDiv.className = 'success-container';
        successDiv.style.cssText = `
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 1.5rem;
        `;
        successDiv.innerHTML = `<p style="color: #86efac; font-size: 0.875rem; margin: 0;"><i class="fas fa-check-circle"></i> ${message}</p>`;
        
        this.form.insertBefore(successDiv, this.form.firstChild);
    }

    /**
     * Clear all messages
     */
    clearMessages() {
        const messages = document.querySelectorAll('.error-container, .success-container');
        messages.forEach(msg => msg.remove());
    }

    /**
     * Handle remember me checkbox
     */
    handleRememberMe(event) {
        // Simple analytics tracking
        if (event.target.checked) {
            Need2Talk.Logger.info('LoginPage', 'User opted to be remembered');
        }
    }

    /**
     * Handle register link click
     */
    handleRegisterLink(event) {
        event.preventDefault();
        
        // Add click animation
        const link = event.target;
        link.style.transform = 'scale(0.95)';
        setTimeout(() => {
            link.style.transform = '';
            window.location.href = '/auth/register';
        }, 150);
    }

    /**
     * Handle forgot password link
     */
    handleForgotPassword(event) {
        event.preventDefault();
        
        // For now, show alert - implement proper forgot password flow
        alert('Funzionalità di recupero password in arrivo. Contatta il supporto per assistenza.');
    }

    /**
     * Utility: Email validation
     */
    isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    /**
     * Utility: Debounce function
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Cleanup when leaving page
     */
    cleanup() {
        // Clear sensitive data
        const passwordInput = document.getElementById('password');
        if (passwordInput) {
            passwordInput.value = '';
        }
        
        this.isInitialized = false;
        Need2Talk.Logger.info('LoginPage', 'Login page cleaned up');
    }
}

// CSS injection for dynamic styles
const loginPageStyles = `
    .form-input.error {
        border-color: #ef4444;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
    }
    
    .form-input.success {
        border-color: #22c55e;
        box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2);
    }
    
    .field-error {
        color: #fca5a5;
        font-size: 0.75rem;
        margin-top: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    
    .submit-button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
    }
    
    .spinner {
        display: inline-block;
        width: 1rem;
        height: 1rem;
        border: 2px solid rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        border-top-color: #ffffff;
        animation: spin 1s ease-in-out infinite;
        margin-right: 0.5rem;
    }
    
    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }
    
    .login-container {
        transition: all 0.6s ease-out;
    }
    
    .form-group {
        transition: all 0.4s ease-out;
    }
`;

// Inject styles
const styleSheet = document.createElement('style');
styleSheet.textContent = loginPageStyles;
document.head.appendChild(styleSheet);

// Initialize when DOM is ready
let loginPageInstance = null;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        loginPageInstance = new LoginPage();
    });
} else {
    loginPageInstance = new LoginPage();
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (loginPageInstance) {
        loginPageInstance.cleanup();
    }
});

// Export for potential module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = LoginPage;
}

// Make available globally for debugging
window.LoginPage = LoginPage;