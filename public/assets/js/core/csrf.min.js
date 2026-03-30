/**
 * need2talk - CSRF Protection Module
 * Gestione sicura dei token CSRF per migliaia di utenti
 */

Need2Talk.CSRF = {
    token: null,
    refreshEndpoint: '/api/csrf/refresh',
    initialized: false,
    
    /**
     * Initialize CSRF protection
     */
    init() {
        // Prevent double initialization
        if (this.initialized) {
            Need2Talk.Logger.warn('CSRF', 'CSRF already initialized, skipping...');
            return;
        }
        
        Need2Talk.Logger.info('CSRF', 'Starting CSRF initialization...');
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        this.token = metaTag?.getAttribute('content') || '';
        
        Need2Talk.Logger.info('CSRF', `CSRF meta tag found: ${!!metaTag}, token length: ${this.token.length}`);
        
        if (!this.token) {
            Need2Talk.Logger.warn('CSRF', 'CSRF token not found in meta tag');
            return;
        }
        
        // Auto-refresh token with jittered interval (prevents thundering herd)
        const refreshInterval = (30 * 60 * 1000) + (Math.random() * 10 * 60 * 1000); // 30-40 minutes
        setTimeout(() => this.scheduleNextRefresh(), refreshInterval);
        
        // Setup automatic CSRF inclusion
        this.setupAutomaticInclusion();

        this.initialized = true;
        Need2Talk.Logger.info('CSRF', 'CSRF protection initialized successfully');
        // ENTERPRISE V8.2: Single source of truth - all components use Need2Talk.CSRF.getToken()
        // No more window.need2talk.csrf sync needed
    },
    
    /**
     * Get current CSRF token
     */
    getToken() {
        return this.token;
    },
    
    /**
     * Refresh CSRF token - ENTERPRISE TIPS
     */
    async refreshToken() {
        try {
            const response = await fetch(this.refreshEndpoint, {
                method: 'POST',
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            // Handle both success (200) and CSRF mismatch (419) responses
            if (response.ok && data.csrf_token) {
                // Success response from refreshCsrfToken()
                this.token = data.csrf_token;
                Need2Talk.Logger.info('CSRF', 'CSRF token refreshed successfully');
                
            } else if (response.status === 419 && data.new_token) {
                // CSRF mismatch response - use the new token provided
                this.token = data.new_token;
                Need2Talk.Logger.info('CSRF', 'CSRF token updated from 419 response');
                
            } else {
                Need2Talk.Logger.warn('CSRF', 'Unexpected refresh response', data);
                return;
            }
            
            // Update meta tag with new token
            const metaTag = document.querySelector('meta[name="csrf-token"]');
            if (metaTag) {
                metaTag.setAttribute('content', this.token);
            }
            // ENTERPRISE V8.2: No more window.need2talk.csrf - single source of truth

        } catch (error) {
            Need2Talk.Logger.error('CSRF', 'Failed to refresh CSRF token', error);
        }
    },
    
    /**
     * Schedule next token refresh with jitter (prevents thundering herd)
     */
    scheduleNextRefresh() {
        // Refresh token immediately
        this.refreshToken();
        
        // Schedule next refresh with jitter (30-40 minutes)
        const nextRefreshInterval = (30 * 60 * 1000) + (Math.random() * 10 * 60 * 1000);
        setTimeout(() => this.scheduleNextRefresh(), nextRefreshInterval);
        
        Need2Talk.Logger.debug('CSRF', `Next refresh scheduled in ${Math.round(nextRefreshInterval/60000)} minutes`);
    },
    
    /**
     * Setup automatic CSRF inclusion for forms and AJAX
     */
    setupAutomaticInclusion() {
        // Intercept all form submissions
        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (form.method.toLowerCase() !== 'get') {
                this.addTokenToForm(form);
            }
        });
        
        // Intercept all fetch requests - Check if already wrapped
        if (!window.fetch._csrfWrapped) {
            const originalFetch = window.fetch.bind(window);
            const csrfModule = this; // Capture 'this' context

            window.fetch = (url, options = {}) => {
                // ENTERPRISE FIX: Bypass wrapper entirely for external requests
                if (!csrfModule.shouldAddToken(url, options.method)) {
                    // External URL or safe method - don't touch it at all
                    return originalFetch(url, options);
                }


                // CRITICAL FIX: Handle both Headers objects and plain objects
                if (!options.headers) {
                    options.headers = {};
                }

                // Convert Headers object to plain object if needed
                if (options.headers instanceof Headers) {
                    const plainHeaders = {};
                    options.headers.forEach((value, key) => {
                        plainHeaders[key] = value;
                    });
                    options.headers = plainHeaders;
                }

                // CRITICAL FIX (2025-12-02): Only add X-CSRF-TOKEN if token exists and is non-empty
                // Empty/null tokens cause ", TOKEN" prefix bug when browser concatenates headers
                const currentToken = csrfModule.token || csrfModule.getToken();
                if (currentToken && currentToken.length > 0) {
                    options.headers = {
                        ...options.headers,
                        'X-CSRF-TOKEN': currentToken
                    };
                } else {
                    // Try to get token from meta tag as fallback
                    const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    if (metaToken && metaToken.length > 0) {
                        options.headers = {
                            ...options.headers,
                            'X-CSRF-TOKEN': metaToken
                        };
                        // Also update the module token for future requests
                        csrfModule.token = metaToken;
                    } else {
                        console.warn('[CSRF Wrapper] No CSRF token available, request may fail with 419');
                    }
                }

                return originalFetch(url, options);
            };
            window.fetch._csrfWrapped = true;
        }
    },
    
    /**
     * Add CSRF token to form
     */
    addTokenToForm(form) {
        // Check if token already exists
        let tokenInput = form.querySelector('input[name="_csrf_token"]');
        
        if (!tokenInput) {
            tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = '_csrf_token';
            form.appendChild(tokenInput);
        }
        
        tokenInput.value = this.token;
    },
    
    /**
     * Check if should add token to request
     */
    shouldAddToken(url, method = 'GET') {
        // Skip for GET, HEAD, OPTIONS
        const safeMethods = ['GET', 'HEAD', 'OPTIONS'];
        if (safeMethods.includes(method?.toUpperCase())) {
            return false;
        }
        
        // Skip for external URLs
        try {
            const requestURL = new URL(url, window.location.origin);
            if (requestURL.origin !== window.location.origin) {
                return false;
            }
        } catch {
            // If URL parsing fails, assume it's a relative URL
        }
        
        return true;
    },
    
    /**
     * Create CSRF-protected fetch function
     */
    fetch(url, options = {}) {
        const headers = options.headers || {};
        
        if (this.shouldAddToken(url, options.method)) {
            headers['X-CSRF-TOKEN'] = this.token;
        }
        
        return fetch(url, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...headers
            }
        }).then(async response => {
            // Handle CSRF token mismatch
            if (response.status === 419) {
                const errorData = await response.json();
                
                // Update token if provided
                if (errorData.new_token) {
                    this.token = errorData.new_token;
                    const metaTag = document.querySelector('meta[name="csrf-token"]');
                    if (metaTag) {
                        metaTag.setAttribute('content', this.token);
                    }
                    // ENTERPRISE V8.2: Single source of truth - no more window.need2talk.csrf
                }
                
                // Show error message
                Need2Talk.showNotification(
                    errorData.message || 'Sessione scaduta. Ricarica la pagina.',
                    'error'
                );
                
                throw new Error('CSRF token mismatch');
            }
            
            return response;
        });
    },
    
    /**
     * Utility for creating forms with CSRF protection
     */
    createProtectedForm(action, method = 'POST') {
        const form = document.createElement('form');
        form.action = action;
        form.method = method;
        
        // Add CSRF token
        const tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = '_csrf_token';
        tokenInput.value = this.token;
        form.appendChild(tokenInput);
        
        return form;
    }
};

// Initialize CSRF protection when app is ready OR immediately if app is already initialized
// FIX: Handle race condition where app.js might emit 'app:ready' before csrf.js loads
if (window.Need2Talk && window.Need2Talk.initialized) {
    // App is already initialized, init CSRF immediately
    Need2Talk.CSRF.init();
} else if (window.Need2Talk && window.Need2Talk.events) {
    // App not yet initialized, listen for ready event
    Need2Talk.events.addEventListener('app:ready', () => {
        Need2Talk.CSRF.init();
    });
} else {
    // Need2Talk not loaded yet, wait for it
    console.warn('[CSRF] Need2Talk not loaded, CSRF protection may not work correctly');
}

// Expose global CSRF-protected fetch
window.fetchWithCSRF = (url, options) => Need2Talk.CSRF.fetch(url, options);