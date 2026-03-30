/**
 * =================================================================
 * REGISTRATION FORM COMPONENT - Alpine.js Component
 * =================================================================
 * Main form component with validation and security features
 * =================================================================
 */

import { 
    validateEmail, 
    validateNickname, 
    calculatePasswordStrength, 
    validatePassword, 
    validatePasswordConfirmation, 
    validateAge 
} from '../utils/validation.js';

import { 
    generateDeviceFingerprint, 
    MouseTracker, 
    KeystrokeTracker, 
    RateLimiter, 
    FormTimer, 
    detectReducedMotion, 
    showNotification 
} from '../utils/security.js';

import { registrationApi } from '../services/api.js';

/**
 * Alpine.js Registration Form Component
 */
export function createRegistrationComponent() {
    return {
        // Form Data
        form: {
            email: '',
            nickname: '',
            password: '',
            password_confirmation: '',
            birth_month: '',
            birth_year: '',
            gender: '',
            accept_terms: false,
            accept_emails: false
        },

        // Validation States
        errors: {},
        valid: {},
        
        // UI States
        isSubmitting: false,
        showPassword: false,
        emailChecking: false,
        nicknameChecking: false,
        reducedMotion: false,
        
        // Password Strength
        passwordStrengthScore: 0,
        passwordRequirements: {
            minLength: false,
            hasUpper: false,
            hasLower: false,
            hasNumber: false,
            hasSpecial: false
        },

        // Age Calculation
        calculatedAge: 0,

        // Security Trackers
        mouseTracker: null,
        keystrokeTracker: null,
        rateLimiter: null,
        formTimer: null,

        /**
         * Initialize registration component
         */
        initializeRegistration() {
            this.setupSecurity();
            this.setupValidation();
            this.detectAccessibilityPreferences();
        },

        /**
         * Setup security measures
         */
        setupSecurity() {
            this.mouseTracker = new MouseTracker();
            this.keystrokeTracker = new KeystrokeTracker();
            this.rateLimiter = new RateLimiter();
            this.formTimer = new FormTimer();
            
            // Set device fingerprint
            const fingerprintField = document.getElementById('device_fingerprint');
            if (fingerprintField) {
                fingerprintField.value = generateDeviceFingerprint();
            }

            // Check rate limiting
            this.checkRateLimit();
        },

        /**
         * Setup form validation
         */
        setupValidation() {
            // Form validation is handled reactively by Alpine.js
        },

        /**
         * Detect accessibility preferences
         */
        detectAccessibilityPreferences() {
            this.reducedMotion = detectReducedMotion();
        },

        /**
         * Check rate limiting
         */
        checkRateLimit() {
            if (this.rateLimiter.shouldBlock()) {
                const timeLeft = this.rateLimiter.getTimeLeft();
                showNotification(
                    `Troppi tentativi di registrazione. Riprova tra ${timeLeft} minuti.`,
                    'warning'
                );
                
                const submitButton = document.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                }
            }
        },

        /**
         * Email validation with availability check
         */
        async validateEmail() {
            const result = validateEmail(this.form.email);
            
            if (!result.valid) {
                this.errors.email = result.error;
                this.valid.email = false;
                return;
            }

            await this.checkEmailAvailable();
        },

        /**
         * Check email availability
         */
        async checkEmailAvailable() {
            if (!this.form.email || this.errors.email) return;

            this.emailChecking = true;
            
            try {
                const result = await registrationApi.checkEmailAvailability(this.form.email);
                
                if (result.available) {
                    this.valid.email = true;
                    delete this.errors.email;
                } else {
                    this.errors.email = 'Email già registrata';
                    this.valid.email = false;
                }
            } catch (error) {
                // Don't block registration if check fails
                this.valid.email = true;
                delete this.errors.email;
            } finally {
                this.emailChecking = false;
            }
        },

        /**
         * Nickname validation with availability check
         */
        async validateNickname() {
            const result = validateNickname(this.form.nickname);
            
            if (!result.valid) {
                this.errors.nickname = result.error;
                this.valid.nickname = false;
                return;
            }

            await this.checkNicknameAvailable();
        },

        /**
         * Check nickname availability
         */
        async checkNicknameAvailable() {
            if (!this.form.nickname || this.errors.nickname) return;

            this.nicknameChecking = true;
            
            try {
                const result = await registrationApi.checkNicknameAvailability(this.form.nickname);
                
                if (result.available) {
                    this.valid.nickname = true;
                    delete this.errors.nickname;
                } else {
                    this.errors.nickname = 'Nickname già in uso';
                    this.valid.nickname = false;
                }
            } catch (error) {
                // Don't block registration if check fails
                this.valid.nickname = true;
                delete this.errors.nickname;
            } finally {
                this.nicknameChecking = false;
            }
        },

        /**
         * Password strength validation
         */
        validatePasswordStrength() {
            const strength = calculatePasswordStrength(this.form.password);
            
            this.passwordStrengthScore = strength.score;
            this.passwordRequirements = strength.requirements;
        },

        /**
         * Password validation
         */
        validatePassword() {
            const result = validatePassword(this.form.password, this.passwordStrengthScore);
            
            if (!result.valid) {
                this.errors.password = result.error;
                this.valid.password = false;
                return;
            }

            this.valid.password = true;
            delete this.errors.password;
        },

        /**
         * Password confirmation validation
         */
        validatePasswordConfirmation() {
            const result = validatePasswordConfirmation(this.form.password, this.form.password_confirmation);
            
            if (!result.valid) {
                this.errors.password_confirmation = result.error;
                this.valid.password_confirmation = false;
                return;
            }

            this.valid.password_confirmation = true;
            delete this.errors.password_confirmation;
        },

        /**
         * Age validation
         */
        validateAge() {
            const result = validateAge(this.form.birth_month, this.form.birth_year);
            
            this.calculatedAge = result.age;
            
            if (!result.valid) {
                this.errors.age = result.error;
                this.valid.age = false;
                return;
            }

            this.valid.age = true;
            delete this.errors.age;
        },

        /**
         * Clear specific field error
         */
        clearError(field) {
            delete this.errors[field];
        },

        /**
         * Get CSS class for form field based on validation state
         */
        getFieldClass(field) {
            if (this.errors[field]) return 'invalid';
            if (this.valid[field]) return 'valid';
            if (this[field + 'Checking']) return 'checking';
            return '';
        },

        /**
         * Check if form can be submitted
         */
        get canSubmit() {
            const requiredFields = ['email', 'nickname', 'password', 'password_confirmation', 'age'];
            const hasValidFields = requiredFields.every(field => this.valid[field]);
            const hasNoErrors = Object.keys(this.errors).length === 0;
            const hasAcceptedTerms = this.form.accept_terms;
            const hasGender = this.form.gender;
            
            return hasValidFields && hasNoErrors && hasAcceptedTerms && hasGender && !this.isSubmitting;
        },

        /**
         * Password strength computed properties
         */
        get passwordStrengthText() {
            if (this.passwordStrengthScore === 0) return 'Inserisci password';
            if (this.passwordStrengthScore < 40) return 'Molto debole';
            if (this.passwordStrengthScore < 60) return 'Debole';
            if (this.passwordStrengthScore < 80) return 'Buona';
            return 'Molto sicura';
        },

        get passwordStrengthColor() {
            if (this.passwordStrengthScore === 0) return '';
            if (this.passwordStrengthScore < 40) return '';
            if (this.passwordStrengthScore < 60) return '';
            if (this.passwordStrengthScore < 80) return '';
            return '';
        },

        get passwordStrengthClass() {
            if (this.passwordStrengthScore === 0) return '';
            if (this.passwordStrengthScore < 40) return 'strength-weak';
            if (this.passwordStrengthScore < 60) return 'strength-medium';
            if (this.passwordStrengthScore < 80) return 'strength-good';
            return 'strength-strong';
        },

        /**
         * Submit registration form
         */
        async submitRegistration() {
            if (!this.canSubmit) return;

            this.isSubmitting = true;

            try {
                // Increment rate limit counter
                this.rateLimiter.incrementAttempts();

                // Final validation
                await this.validateAll();

                if (!this.canSubmit) {
                    this.isSubmitting = false;
                    return;
                }

                // Prepare form data with security information
                const formData = this.prepareFormData();

                // Submit to server
                const result = await registrationApi.submitRegistration(formData);

                if (result.success) {
                    showNotification('Registrazione completata con successo!', 'success');
                    window.location.href = result.redirect || '/profile';
                } else {
                    this.handleSubmissionErrors(result);
                }

            } catch (error) {
                Need2Talk.Logger.error('RegistrationForm', 'Registration error', error);
                showNotification('Errore durante la registrazione. Riprova.', 'error');
            } finally {
                this.isSubmitting = false;
            }
        },

        /**
         * Validate all fields before submission
         */
        async validateAll() {
            await Promise.all([
                this.validateEmail(),
                this.validateNickname(),
                this.validatePassword(),
                this.validatePasswordConfirmation(),
                this.validateAge()
            ]);

            // Validate gender
            if (!this.form.gender) {
                this.errors.gender = 'Seleziona il tuo genere';
                this.valid.gender = false;
            } else {
                this.valid.gender = true;
                delete this.errors.gender;
            }

            // Validate terms acceptance
            if (!this.form.accept_terms) {
                this.errors.accept_terms = 'Devi accettare i termini di servizio';
            } else {
                delete this.errors.accept_terms;
            }
        },

        /**
         * Prepare form data for submission
         */
        prepareFormData() {
            const formData = new FormData();
            
            // Add form fields
            Object.keys(this.form).forEach(key => {
                formData.append(key, this.form[key]);
            });

            // Add security data
            formData.append('mouse_movements', this.mouseTracker.getMovements());
            formData.append('keystrokes', this.keystrokeTracker.getKeystrokes());
            formData.append('form_duration', this.formTimer.getDuration());
            
            // Add CSRF token
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            return formData;
        },

        /**
         * Handle submission errors
         */
        handleSubmissionErrors(result) {
            this.errors = result.errors || {};
            
            if (result.message) {
                showNotification(result.message, 'error');
            }
        }
    };
}