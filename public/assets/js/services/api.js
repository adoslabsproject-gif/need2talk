/**
 * =================================================================
 * API SERVICE - HTTP Request Utilities
 * =================================================================
 * Centralized API communication functions
 * ENTERPRISE: Includes CSRF token automatically for security
 * =================================================================
 */

/**
 * Custom API Error class for better error handling
 */
export class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
    }
}

/**
 * Base API class for making HTTP requests
 * ENTERPRISE: Includes CSRF token automatically for security
 */
export class ApiService {
    constructor() {
        this.baseHeaders = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
    }

    /**
     * Get CSRF token from meta tag
     * @returns {string|null} CSRF token or null if not found
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    /**
     * Get headers with CSRF token included
     * @param {object} additionalHeaders Additional headers to merge
     * @returns {object} Complete headers object
     */
    getHeaders(additionalHeaders = {}) {
        const headers = { ...this.baseHeaders, ...additionalHeaders };

        // SECURITY: Add CSRF token for POST/PUT/DELETE requests
        const csrfToken = this.getCsrfToken();
        if (csrfToken) {
            headers['X-CSRF-TOKEN'] = csrfToken;
        }

        return headers;
    }

    /**
     * Make a GET request
     */
    async get(url, options = {}) {
        return this.request(url, {
            method: 'GET',
            ...options
        });
    }

    /**
     * Make a POST request
     */
    async post(url, data = null, options = {}) {
        return this.request(url, {
            method: 'POST',
            body: data ? JSON.stringify(data) : null,
            ...options
        });
    }

    /**
     * Make a PUT request
     */
    async put(url, data = null, options = {}) {
        return this.request(url, {
            method: 'PUT',
            body: data ? JSON.stringify(data) : null,
            ...options
        });
    }

    /**
     * Make a DELETE request
     */
    async delete(url, options = {}) {
        return this.request(url, {
            method: 'DELETE',
            ...options
        });
    }

    /**
     * Make a generic request
     * ENTERPRISE: Automatically includes CSRF token for security
     */
    async request(url, options = {}) {
        const config = {
            headers: this.getHeaders(options.headers),
            ...options
        };

        try {
            const response = await fetch(url, config);

            if (!response.ok) {
                // ENTERPRISE V12.9 (2026-01-18): Parse JSON body for all errors to get backend details
                // CONTEXT: 400 errors (PASSWORD_INCORRECT, etc.) need backend error data
                let errorData = null;
                const contentType = response.headers.get('content-type');

                try {
                    if (contentType && contentType.includes('application/json')) {
                        errorData = await response.json();
                    }
                } catch (parseError) {
                    // JSON parse failed, proceed without error data
                }

                // ENTERPRISE: Better error handling for common HTTP errors
                if (response.status === 419) {
                    const apiError = new ApiError('La tua sessione è scaduta. Ricarica la pagina e riprova.', response.status);
                    apiError.data = errorData;
                    throw apiError;
                }
                if (response.status === 403) {
                    const apiError = new ApiError('Accesso negato. Non hai i permessi necessari.', response.status);
                    apiError.data = errorData;
                    throw apiError;
                }
                if (response.status === 401) {
                    const apiError = new ApiError('Non autenticato. Effettua il login e riprova.', response.status);
                    apiError.data = errorData;
                    throw apiError;
                }
                if (response.status === 400) {
                    // Validation error - include backend error details
                    const message = errorData?.message || `Errore di validazione`;
                    const apiError = new ApiError(message, response.status, 'VALIDATION_ERROR');
                    apiError.data = errorData;
                    throw apiError;
                }

                // Generic HTTP error
                const apiError = new ApiError(`Errore HTTP ${response.status}`, response.status);
                apiError.data = errorData;
                throw apiError;
            }

            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return await response.json();
            }

            return await response.text();
        } catch (error) {
            if (!(error instanceof ApiError)) {
                Need2Talk.Logger.error('ApiService', 'API request failed', error);
            }
            throw error;
        }
    }

    /**
     * Submit form data
     */
    async submitForm(url, formData, options = {}) {
        const config = {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...options.headers
            },
            ...options
        };

        return this.request(url, config);
    }
}

/**
 * Registration API service
 */
export class RegistrationApiService extends ApiService {
    /**
     * Check if email is available
     */
    async checkEmailAvailability(email) {
        try {
            const data = await this.post('/api/validate/email', { email });
            return {
                available: data.available,
                error: null
            };
        } catch (error) {
            Need2Talk.Logger.warn('ApiService', 'Email availability check failed', error);
            // Don't block registration if check fails
            return {
                available: true,
                error: 'Unable to verify email availability'
            };
        }
    }

    /**
     * Check if nickname is available
     */
    async checkNicknameAvailability(nickname) {
        try {
            const data = await this.post('/api/validate/nickname', { nickname });
            return {
                available: data.available,
                error: null
            };
        } catch (error) {
            Need2Talk.Logger.warn('ApiService', 'Nickname availability check failed', error);
            // Don't block registration if check fails
            return {
                available: true,
                error: 'Unable to verify nickname availability'
            };
        }
    }

    /**
     * Submit registration form
     */
    async submitRegistration(formData) {
        try {
            const result = await this.submitForm('/auth/register', formData);
            return {
                success: result.success || false,
                errors: result.errors || {},
                message: result.message || null,
                redirect: result.redirect || null
            };
        } catch (error) {
            Need2Talk.Logger.error('ApiService', 'Registration submission failed', error);
            return {
                success: false,
                errors: {},
                message: 'Errore durante la registrazione. Riprova.'
            };
        }
    }
}

// Create singleton instance
export const registrationApi = new RegistrationApiService();