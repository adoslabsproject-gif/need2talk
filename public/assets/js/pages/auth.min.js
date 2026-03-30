/**
 * need2talk - Authentication Pages JavaScript
 * Sicuro, scalabile, performante per migliaia di utenti
 */

// Login Page Controller
function loginPageData() {
    return {
        // Form state
        form: {
            email: '',
            password: '',
            remember: false
        },
        
        // Validation state
        errors: {},
        valid: {},
        
        // UI state
        showPassword: false,
        isSubmitting: false,
        
        /**
         * Initialize login page
         */
        init() {
            // Security: Clear any previous auth attempts from memory
            this.clearSensitiveData();
            
            // Performance: Preload common resources
            this.preloadResources();
            
            // Setup real-time validation
            this.setupValidation();
        },
        
        /**
         * Validate email field
         */
        validateEmail() {
            const email = this.form.email.trim();
            
            if (!email) {
                this.errors.email = 'Email richiesta';
                this.valid.email = false;
                return false;
            }
            
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                this.errors.email = 'Formato email non valido';
                this.valid.email = false;
                return false;
            }
            
            // Success
            delete this.errors.email;
            this.valid.email = true;
            return true;
        },
        
        /**
         * Validate password field
         */
        validatePassword() {
            const password = this.form.password;
            
            if (!password) {
                this.errors.password = 'Password richiesta';
                return false;
            }
            
            if (password.length < 6) {
                this.errors.password = 'Password troppo corta (minimo 6 caratteri)';
                return false;
            }
            
            // Success
            delete this.errors.password;
            return true;
        },
        
        /**
         * Clear specific field error
         */
        clearError(field) {
            if (this.errors[field]) {
                delete this.errors[field];
            }
        },
        
        /**
         * Submit login form - SECURITY HARDENED
         */
        async submitLogin() {
            // Prevent double submission
            if (this.isSubmitting) return;
            
            // Validate all fields
            const emailValid = this.validateEmail();
            const passwordValid = this.validatePassword();
            
            if (!emailValid || !passwordValid) {
                Need2Talk.showError('Correggi gli errori nel form prima di continuare');
                return;
            }
            
            this.isSubmitting = true;
            
            try {
                // Security: Use CSRF-protected fetch
                const response = await fetchWithCSRF('/auth/login', {
                    method: 'POST',
                    body: JSON.stringify({
                        email: this.form.email.trim(),
                        password: this.form.password,
                        remember: this.form.remember,
                        redirect: new URLSearchParams(window.location.search).get('redirect') || '/profile'
                    })
                });
                
                const data = await response.json();
                
                if (response.ok && data.success) {
                    // Success - redirect to appropriate page
                    Need2Talk.showSuccess('Accesso effettuato con successo!');
                    
                    // Security: Clear form data
                    this.clearSensitiveData();
                    
                    // Small delay for UX then redirect
                    setTimeout(() => {
                        window.location.href = data.redirect || '/profile';
                    }, 1000);
                    
                } else {
                    // Handle login errors
                    if (data.errors) {
                        this.errors = data.errors;
                    }
                    
                    Need2Talk.showError(data.message || 'Errore durante il login');
                    
                    // Security: Rate limiting feedback
                    if (data.rate_limited) {
                        const waitTime = Math.ceil(data.retry_after / 60);
                        Need2Talk.showWarning(`Troppi tentativi. Riprova tra ${waitTime} minuti.`);
                        
                        // Disable form temporarily
                        setTimeout(() => {
                            this.isSubmitting = false;
                        }, data.retry_after * 1000);
                        return;
                    }
                }
                
            } catch (error) {
                Need2Talk.Logger.error('AuthLogin', 'Login error', error);
                Need2Talk.showError('Errore di connessione. Riprova.');
                Need2Talk.handleError(error, 'Login Form');
                
            } finally {
                if (!this.errors.rate_limited) {
                    this.isSubmitting = false;
                }
            }
        },
        
        /**
         * Setup real-time validation
         */
        setupValidation() {
            // Debounced validation for better UX
            const debouncedEmailValidation = Need2Talk.debounce(() => {
                this.validateEmail();
            }, 500);
            
            const debouncedPasswordValidation = Need2Talk.debounce(() => {
                this.validatePassword();
            }, 500);
            
            // Watch for form changes
            this.$watch('form.email', () => {
                if (this.form.email.length > 0) {
                    debouncedEmailValidation();
                }
            });
            
            this.$watch('form.password', () => {
                if (this.form.password.length > 0) {
                    debouncedPasswordValidation();
                }
            });
        },
        
        /**
         * Clear sensitive data from memory
         */
        clearSensitiveData() {
            // Clear password from memory for security
            if (this.form.password) {
                this.form.password = '';
            }
            
            // Clear any cached data
            this.errors = {};
            this.valid = {};
        },
        
        /**
         * Preload resources for performance
         */
        preloadResources() {
            // Preload register page for faster navigation
            const link = document.createElement('link');
            link.rel = 'prefetch';
            link.href = '/auth/register';
            document.head.appendChild(link);
        }
    };
}

// Register Page Controller
function registerPageData() {
    return {
        // Form state
        form: {
            nickname: '',
            email: '',
            password: '',
            password_confirmation: '',
            birth_year: '',
            birth_month: '',
            terms_accepted: false,
            privacy_accepted: false,
            marketing_accepted: false
        },
        
        // Validation state
        errors: {},
        valid: {},
        
        // UI state
        showPassword: false,
        showPasswordConfirmation: false,
        isSubmitting: false,
        
        // Age calculation
        currentYear: new Date().getFullYear(),
        months: [
            { value: 1, name: 'Gennaio' },
            { value: 2, name: 'Febbraio' },
            { value: 3, name: 'Marzo' },
            { value: 4, name: 'Aprile' },
            { value: 5, name: 'Maggio' },
            { value: 6, name: 'Giugno' },
            { value: 7, name: 'Luglio' },
            { value: 8, name: 'Agosto' },
            { value: 9, name: 'Settembre' },
            { value: 10, name: 'Ottobre' },
            { value: 11, name: 'Novembre' },
            { value: 12, name: 'Dicembre' }
        ],
        
        /**
         * Initialize register page
         */
        init() {
            // Security: Clear any previous registration attempts
            this.clearSensitiveData();
            
            // Setup validation
            this.setupValidation();
            
            // Setup password strength meter
            this.setupPasswordStrength();
        },
        
        /**
         * Validate nickname
         */
        validateNickname() {
            const nickname = this.form.nickname.trim();
            
            if (!nickname) {
                this.errors.nickname = 'Nickname richiesto';
                this.valid.nickname = false;
                return false;
            }
            
            if (nickname.length < 3) {
                this.errors.nickname = 'Nickname troppo corto (minimo 3 caratteri)';
                this.valid.nickname = false;
                return false;
            }
            
            if (nickname.length > 50) {
                this.errors.nickname = 'Nickname troppo lungo (massimo 50 caratteri)';
                this.valid.nickname = false;
                return false;
            }
            
            // Check allowed characters
            const nicknameRegex = /^[a-zA-Z0-9_\-àáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿ]+$/;
            if (!nicknameRegex.test(nickname)) {
                this.errors.nickname = 'Nickname può contenere solo lettere, numeri, underscore e trattini';
                this.valid.nickname = false;
                return false;
            }
            
            // Success
            delete this.errors.nickname;
            this.valid.nickname = true;
            return true;
        },
        
        /**
         * Validate email
         */
        validateEmail() {
            const email = this.form.email.trim();
            
            if (!email) {
                this.errors.email = 'Email richiesta';
                this.valid.email = false;
                return false;
            }
            
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                this.errors.email = 'Formato email non valido';
                this.valid.email = false;
                return false;
            }
            
            // Success
            delete this.errors.email;
            this.valid.email = true;
            return true;
        },
        
        /**
         * Validate password with strength checking
         */
        validatePassword() {
            const password = this.form.password;
            
            if (!password) {
                this.errors.password = 'Password richiesta';
                this.valid.password = false;
                return false;
            }
            
            if (password.length < 8) {
                this.errors.password = 'Password deve essere di almeno 8 caratteri';
                this.valid.password = false;
                return false;
            }
            
            // Check password strength
            const strength = this.calculatePasswordStrength(password);
            if (strength < 3) {
                this.errors.password = 'Password troppo debole. Usa lettere, numeri e simboli.';
                this.valid.password = false;
                return false;
            }
            
            // Success
            delete this.errors.password;
            this.valid.password = true;
            return true;
        },
        
        /**
         * Validate password confirmation
         */
        validatePasswordConfirmation() {
            const confirmation = this.form.password_confirmation;
            const password = this.form.password;
            
            if (!confirmation) {
                this.errors.password_confirmation = 'Conferma password richiesta';
                this.valid.password_confirmation = false;
                return false;
            }
            
            if (confirmation !== password) {
                this.errors.password_confirmation = 'Le password non corrispondono';
                this.valid.password_confirmation = false;
                return false;
            }
            
            // Success
            delete this.errors.password_confirmation;
            this.valid.password_confirmation = true;
            return true;
        },
        
        /**
         * Validate age (18+)
         */
        validateAge() {
            const birthYear = parseInt(this.form.birth_year);
            const birthMonth = parseInt(this.form.birth_month);
            
            if (!birthYear || !birthMonth) {
                this.errors.age = 'Data di nascita richiesta';
                this.valid.age = false;
                return false;
            }
            
            // Calculate age
            const today = new Date();
            const currentYear = today.getFullYear();
            const currentMonth = today.getMonth() + 1;
            
            let age = currentYear - birthYear;
            if (currentMonth < birthMonth) {
                age--;
            }
            
            if (age < 18) {
                this.errors.age = 'Devi avere almeno 18 anni per registrarti';
                this.valid.age = false;
                return false;
            }
            
            // Success
            delete this.errors.age;
            this.valid.age = true;
            return true;
        },
        
        /**
         * Calculate password strength
         */
        calculatePasswordStrength(password) {
            let strength = 0;
            
            // Length
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            
            // Character types
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            return Math.min(strength, 5);
        },
        
        /**
         * Get password strength text and color
         */
        getPasswordStrengthInfo() {
            const strength = this.calculatePasswordStrength(this.form.password);
            
            const levels = [
                { text: 'Molto debole', color: 'text-red-500' },
                { text: 'Debole', color: 'text-red-400' },
                { text: 'Sufficiente', color: 'text-yellow-500' },
                { text: 'Buona', color: 'text-blue-400' },
                { text: 'Forte', color: 'text-green-400' },
                { text: 'Molto forte', color: 'text-green-500' }
            ];
            
            return levels[strength] || levels[0];
        },
        
        /**
         * Submit registration form
         */
        async submitRegistration() {
            if (this.isSubmitting) return;
            
            // Validate all fields
            const validations = [
                this.validateNickname(),
                this.validateEmail(),
                this.validatePassword(),
                this.validatePasswordConfirmation(),
                this.validateAge()
            ];
            
            // Check terms acceptance
            if (!this.form.terms_accepted) {
                this.errors.terms = 'Devi accettare i Termini di Servizio';
                validations.push(false);
            } else {
                delete this.errors.terms;
            }
            
            if (!this.form.privacy_accepted) {
                this.errors.privacy = 'Devi accettare la Privacy Policy';
                validations.push(false);
            } else {
                delete this.errors.privacy;
            }
            
            if (!validations.every(v => v)) {
                Need2Talk.showError('Correggi gli errori nel form prima di continuare');
                return;
            }
            
            this.isSubmitting = true;
            
            try {
                const response = await fetchWithCSRF('/auth/register', {
                    method: 'POST',
                    body: JSON.stringify({
                        nickname: this.form.nickname.trim(),
                        email: this.form.email.trim(),
                        password: this.form.password,
                        birth_year: parseInt(this.form.birth_year),
                        birth_month: parseInt(this.form.birth_month),
                        terms_accepted: this.form.terms_accepted,
                        privacy_accepted: this.form.privacy_accepted,
                        marketing_accepted: this.form.marketing_accepted
                    })
                });
                
                const data = await response.json();
                
                if (response.ok && data.success) {
                    // Success
                    Need2Talk.showSuccess('Registrazione completata! Controlla la tua email per verificare l\'account.');
                    
                    // Clear form
                    this.clearSensitiveData();
                    
                    // Redirect to login
                    setTimeout(() => {
                        window.location.href = '/auth/login?message=registration_complete';
                    }, 2000);
                    
                } else {
                    // Handle registration errors
                    if (data.errors) {
                        this.errors = data.errors;
                    }
                    
                    Need2Talk.showError(data.message || 'Errore durante la registrazione');
                }
                
            } catch (error) {
                Need2Talk.Logger.error('AuthRegister', 'Registration error', error);
                Need2Talk.showError('Errore di connessione. Riprova.');
                Need2Talk.handleError(error, 'Registration Form');
                
            } finally {
                this.isSubmitting = false;
            }
        },
        
        /**
         * Setup real-time validation
         */
        setupValidation() {
            // Debounced validation
            const validators = {
                nickname: Need2Talk.debounce(() => this.validateNickname(), 500),
                email: Need2Talk.debounce(() => this.validateEmail(), 500),
                password: Need2Talk.debounce(() => this.validatePassword(), 300),
                password_confirmation: Need2Talk.debounce(() => this.validatePasswordConfirmation(), 300)
            };
            
            // Watch for changes
            Object.keys(validators).forEach(field => {
                this.$watch(`form.${field}`, () => {
                    if (this.form[field].length > 0) {
                        validators[field]();
                    }
                });
            });
            
            // Age validation
            this.$watch('form.birth_year', () => this.validateAge());
            this.$watch('form.birth_month', () => this.validateAge());
        },
        
        /**
         * Setup password strength visualization
         */
        setupPasswordStrength() {
            this.$watch('form.password', () => {
                if (this.form.password.length > 0) {
                    // Update strength meter
                    const strength = this.calculatePasswordStrength(this.form.password);
                    const meter = document.getElementById('password-strength-meter');
                    if (meter) {
                        meter.style.width = `${(strength / 5) * 100}%`;
                        meter.className = `h-1 rounded-full transition-all duration-300 ${this.getStrengthColor(strength)}`;
                    }
                }
            });
        },
        
        /**
         * Get strength meter color
         */
        getStrengthColor(strength) {
            const colors = [
                'bg-red-500',
                'bg-red-400', 
                'bg-yellow-500',
                'bg-blue-400',
                'bg-green-400',
                'bg-green-500'
            ];
            return colors[strength] || colors[0];
        },
        
        /**
         * Clear sensitive data
         */
        clearSensitiveData() {
            this.form.password = '';
            this.form.password_confirmation = '';
            this.errors = {};
            this.valid = {};
        },
        
        /**
         * Clear field error
         */
        clearError(field) {
            if (this.errors[field]) {
                delete this.errors[field];
            }
        }
    };
}

// Global function for page detection
function getAuthPageData() {
    const path = window.location.pathname;
    
    if (path.includes('/login')) {
        return loginPageData();
    } else if (path.includes('/register')) {
        return registerPageData();
    }
    
    return {};
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Performance monitoring
    Need2Talk.perf.mark('auth-page-ready');
});