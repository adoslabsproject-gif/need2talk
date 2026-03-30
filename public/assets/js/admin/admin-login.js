/**
 * need2talk Admin Login - Enterprise JS Module
 * 
 * SICUREZZA ENTERPRISE:
 * - Nessun inline JavaScript 
 * - Integrato con sistema logging centralizzato
 * - Error monitoring completo
 * - Rate limiting client-side
 * - Input validation sicura
 */

class AdminLoginManager {
    constructor() {
        this.form = document.getElementById('adminLoginForm');
        this.button = document.getElementById('loginButton');
        this.buttonText = document.getElementById('loginButtonText');
        this.alertContainer = document.getElementById('alertContainer');
        this.adminUrl = document.querySelector('[data-admin-url]')?.dataset.adminUrl || '';
        
        this.init();
    }
    
    init() {
        if (!this.form) {
            Need2Talk.Logger.error('Admin Login', 'Login form not found');
            return;
        }
        
        // Bind events
        this.form.addEventListener('submit', this.handleSubmit.bind(this));
        
        // Focus first input
        const emailInput = document.getElementById('email');
        if (emailInput) {
            emailInput.focus();
        }
        
        // Log initialization
        Need2Talk.Logger.info('Admin Login', '🔐 Admin login system initialized');
    }
    
    async handleSubmit(event) {
        event.preventDefault();
        
        const email = document.getElementById('email')?.value?.trim();
        const password = document.getElementById('password')?.value;
        
        // Validation
        if (!this.validateInputs(email, password)) {
            return;
        }
        
        // Rate limiting check (client-side)
        if (!this.checkRateLimit()) {
            this.showAlert('⚠️ Troppi tentativi. Attendi prima di riprovare.', 'warning');
            return;
        }
        
        await this.performLogin(email, password);
    }
    
    validateInputs(email, password) {
        if (!email) {
            this.showAlert('📧 Email richiesta', 'error');
            document.getElementById('email')?.focus();
            return false;
        }
        
        if (!password) {
            this.showAlert('🔒 Password richiesta', 'error');
            document.getElementById('password')?.focus();
            return false;
        }
        
        // Email format validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            this.showAlert('📧 Formato email non valido', 'error');
            document.getElementById('email')?.focus();
            return false;
        }
        
        return true;
    }
    
    checkRateLimit() {
        const lastAttempt = localStorage.getItem('admin_last_attempt');
        const attemptCount = parseInt(localStorage.getItem('admin_attempt_count') || '0');
        const now = Date.now();
        
        if (lastAttempt) {
            const timeDiff = now - parseInt(lastAttempt);
            
            // Reset count after 15 minutes
            if (timeDiff > 15 * 60 * 1000) {
                localStorage.removeItem('admin_attempt_count');
                localStorage.removeItem('admin_last_attempt');
                return true;
            }
            
            // Check if too many attempts
            if (attemptCount >= 5 && timeDiff < 15 * 60 * 1000) {
                const waitTime = Math.ceil((15 * 60 * 1000 - timeDiff) / 60000);
                this.showAlert(`⏱️ Troppi tentativi. Riprova tra ${waitTime} minuti.`, 'error');
                return false;
            }
        }
        
        return true;
    }
    
    async performLogin(email, password) {
        // Update UI to loading state
        this.setLoadingState(true);
        
        // Log attempt
        Need2Talk.Logger.info('Admin Login', `🔐 Login attempt for ${email}`);
        
        try {
            const response = await this.makeLoginRequest(email, password);
            const result = await response.json();
            
            if (result.success) {
                this.handleLoginSuccess(result);
            } else {
                this.handleLoginError(result.error || 'Errore durante il login');
            }
            
        } catch (error) {
            Need2Talk.Logger.error('Admin Login', '🚨 Network error during login', { error: error.message });
            this.handleLoginError('Errore di connessione. Verifica la tua connessione internet.');
        } finally {
            this.setLoadingState(false);
        }
    }
    
    async makeLoginRequest(email, password) {
        const url = `${this.adminUrl}/login`;
        
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: this.encodeFormData({ email, password })
        });
    }
    
    handleLoginSuccess(result) {
        Need2Talk.Logger.info('Admin Login', '✅ Login successful, 2FA required');
        
        this.showAlert('✅ Codice 2FA inviato! Controlla la tua email.', 'success');
        this.buttonText.textContent = '📧 Codice inviato via email';
        
        // Clear rate limiting
        localStorage.removeItem('admin_attempt_count');
        localStorage.removeItem('admin_last_attempt');
        
        // Redirect to 2FA page
        setTimeout(() => {
            window.location.href = `${this.adminUrl}/2fa?token=${result.temp_token}`;
        }, 2000);
    }
    
    handleLoginError(errorMessage) {
        Need2Talk.Logger.warning('Admin Login', `❌ Login failed: ${errorMessage}`);
        
        this.showAlert(`❌ ${errorMessage}`, 'error');
        
        // Update rate limiting
        const attemptCount = parseInt(localStorage.getItem('admin_attempt_count') || '0') + 1;
        localStorage.setItem('admin_attempt_count', attemptCount.toString());
        localStorage.setItem('admin_last_attempt', Date.now().toString());
        
        // Clear password field
        const passwordInput = document.getElementById('password');
        if (passwordInput) {
            passwordInput.value = '';
            passwordInput.focus();
        }
    }
    
    setLoadingState(loading) {
        if (!this.button || !this.buttonText) return;
        
        this.button.disabled = loading;
        
        if (loading) {
            this.buttonText.innerHTML = '<span class="admin-loading"><span class="admin-spinner"></span> Verificando credenziali...</span>';
        } else {
            this.buttonText.textContent = 'Accedi - Step 1/2';
        }
    }
    
    showAlert(message, type) {
        if (!this.alertContainer) return;
        
        const alertClasses = {
            'success': 'admin-alert admin-alert-success',
            'error': 'admin-alert admin-alert-error',
            'warning': 'admin-alert admin-alert-warning'
        };
        
        const alertClass = alertClasses[type] || alertClasses.error;
        
        this.alertContainer.innerHTML = `
            <div class="${alertClass}">
                <p>${this.sanitizeHtml(message)}</p>
            </div>
        `;
        
        // Auto-hide after 8 seconds
        setTimeout(() => {
            if (this.alertContainer) {
                this.alertContainer.innerHTML = '';
            }
        }, 8000);
        
        // Scroll to alert if not visible
        this.alertContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    
    encodeFormData(data) {
        return Object.keys(data)
            .map(key => `${encodeURIComponent(key)}=${encodeURIComponent(data[key])}`)
            .join('&');
    }
    
    sanitizeHtml(str) {
        const temp = document.createElement('div');
        temp.textContent = str;
        return temp.innerHTML;
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Verifica se siamo nella pagina di login admin
    if (document.getElementById('adminLoginForm')) {
        new AdminLoginManager();
    }
});

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdminLoginManager;
}