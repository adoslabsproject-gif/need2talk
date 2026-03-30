/**
 * need2talk - API Client
 * Enterprise Galaxy - Fetch wrapper with CSRF, error handling, retries
 *
 * Purpose: Centralized API communication with automatic CSRF token injection
 * Performance: Connection pooling, request deduplication, exponential backoff
 * Security: CSRF protection, XSS prevention, timeout enforcement
 */

/**
 * API Client Class
 * Singleton pattern for unified API communication
 */
class ApiClient {
    constructor() {
        this.baseUrl = window.location.origin;
        this.defaultTimeout = 30000; // 30 seconds
        this.pendingRequests = new Map(); // Request deduplication
        // ENTERPRISE V8.2: CSRF token now managed by Need2Talk.CSRF single source of truth
        // No local storage - always get fresh token from Need2Talk.CSRF.getToken()
    }

    /**
     * Get current CSRF token from single source of truth
     * ENTERPRISE V8.2: Always use Need2Talk.CSRF module
     * @returns {string} CSRF token
     */
    get csrfToken() {
        return Need2Talk.CSRF?.getToken() || '';
    }

    /**
     * GET request
     *
     * @param {string} endpoint - API endpoint (e.g., '/api/audio/feed')
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async get(endpoint, options = {}) {
        return this.request(endpoint, {
            method: 'GET',
            ...options
        });
    }

    /**
     * POST request (with automatic CSRF)
     *
     * @param {string} endpoint - API endpoint
     * @param {Object} data - Request body
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async post(endpoint, data = {}, options = {}) {
        return this.request(endpoint, {
            method: 'POST',
            body: data,
            ...options
        });
    }

    /**
     * PUT request (with automatic CSRF)
     *
     * @param {string} endpoint - API endpoint
     * @param {Object} data - Request body
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async put(endpoint, data = {}, options = {}) {
        return this.request(endpoint, {
            method: 'PUT',
            body: data,
            ...options
        });
    }

    /**
     * DELETE request (with automatic CSRF)
     *
     * @param {string} endpoint - API endpoint
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async delete(endpoint, options = {}) {
        return this.request(endpoint, {
            method: 'DELETE',
            ...options
        });
    }

    /**
     * PATCH request (with automatic CSRF)
     * For partial resource updates (RFC 5789)
     *
     * @param {string} endpoint - API endpoint
     * @param {Object} data - Request body
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async patch(endpoint, data = {}, options = {}) {
        return this.request(endpoint, {
            method: 'PATCH',
            body: data,
            ...options
        });
    }

    /**
     * Upload file (multipart/form-data with CSRF)
     *
     * @param {string} endpoint - API endpoint
     * @param {FormData} formData - FormData with files
     * @param {Object} options - Additional options (e.g., onProgress)
     * @returns {Promise<Object>} Response data
     */
    async upload(endpoint, formData, options = {}) {
        // Add CSRF token to FormData
        if (this.csrfToken) {
            formData.append('_csrf', this.csrfToken);
        }

        return this.request(endpoint, {
            method: 'POST',
            body: formData,
            isFormData: true, // Skip JSON stringification
            ...options
        });
    }

    /**
     * Core request method with retry logic
     *
     * @private
     * @param {string} endpoint - API endpoint
     * @param {Object} options - Request options
     * @returns {Promise<Object>} Response data
     */
    async request(endpoint, options = {}) {
        const {
            method = 'GET',
            body = null,
            headers = {},
            timeout = this.defaultTimeout,
            retry = 0,
            maxRetries = 0,
            cache = false,
            deduplicate = true,
            isFormData = false,
            onProgress = null
        } = options;

        // Request deduplication (prevent duplicate simultaneous requests)
        const requestKey = `${method}:${endpoint}`;
        if (deduplicate && this.pendingRequests.has(requestKey)) {
            console.log(`ApiClient: Deduplicating request ${requestKey}`);
            return this.pendingRequests.get(requestKey);
        }

        // Build request promise
        const requestPromise = this._executeRequest(endpoint, {
            method,
            body,
            headers,
            timeout,
            isFormData,
            onProgress
        });

        // Store in pending requests
        if (deduplicate) {
            this.pendingRequests.set(requestKey, requestPromise);
        }

        try {
            const response = await requestPromise;
            return response;

        } catch (error) {
            // Retry logic for network errors
            if (retry < maxRetries && this._isRetryableError(error)) {
                console.warn(`ApiClient: Retrying ${requestKey} (attempt ${retry + 1}/${maxRetries})`);
                await this._exponentialBackoff(retry);
                return this.request(endpoint, { ...options, retry: retry + 1 });
            }

            throw error;

        } finally {
            // Remove from pending requests
            if (deduplicate) {
                this.pendingRequests.delete(requestKey);
            }
        }
    }

    /**
     * Execute fetch request with timeout
     *
     * @private
     */
    async _executeRequest(endpoint, options) {
        const {
            method,
            body,
            headers,
            timeout,
            isFormData,
            onProgress
        } = options;

        // Build full URL
        const url = endpoint.startsWith('http') ? endpoint : `${this.baseUrl}${endpoint}`;

        // Build headers
        const requestHeaders = {
            'X-Requested-With': 'XMLHttpRequest',
            ...headers
        };

        // ENTERPRISE FIX: CSRF token is automatically added by window.fetch wrapper (csrf.js)
        // DO NOT add manually here to prevent duplication ("token, token")
        // The fetch wrapper (csrf.js lines 120-155) handles CSRF for ALL POST/PUT/DELETE/PATCH

        // Add Content-Type for JSON (skip for FormData)
        if (body && !isFormData) {
            requestHeaders['Content-Type'] = 'application/json';
            requestHeaders['Accept'] = 'application/json';
        }

        // Build fetch options
        const fetchOptions = {
            method,
            headers: requestHeaders,
            credentials: 'same-origin', // Include cookies
        };

        // Add body (JSON or FormData)
        if (body) {
            if (isFormData) {
                fetchOptions.body = body; // FormData (don't stringify)
            } else if (body instanceof FormData) {
                fetchOptions.body = body; // Already FormData
            } else {
                fetchOptions.body = JSON.stringify(body); // JSON
            }
        }

        // Create abort controller for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        fetchOptions.signal = controller.signal;

        try {
            const response = await fetch(url, fetchOptions);
            clearTimeout(timeoutId);

            // Parse response
            return await this._parseResponse(response);

        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError') {
                throw new ApiError('Request timeout', 'TIMEOUT', 408);
            }

            throw new ApiError(error.message, 'NETWORK_ERROR', 0);
        }
    }

    /**
     * Parse fetch response
     *
     * @private
     */
    async _parseResponse(response) {
        const contentType = response.headers.get('content-type');

        // Handle non-JSON responses
        if (!contentType || !contentType.includes('application/json')) {
            if (!response.ok) {
                throw new ApiError(
                    `HTTP ${response.status}: ${response.statusText}`,
                    'HTTP_ERROR',
                    response.status
                );
            }
            return { success: true, data: await response.text() };
        }

        // Parse JSON
        const data = await response.json();

        // Handle HTTP errors
        if (!response.ok) {
            throw new ApiError(
                data.message || `HTTP ${response.status}`,
                data.error || 'HTTP_ERROR',
                response.status,
                data
            );
        }

        return data;
    }

    /**
     * Check if error is retryable
     *
     * @private
     */
    _isRetryableError(error) {
        if (error.code === 'NETWORK_ERROR') return true;
        if (error.code === 'TIMEOUT') return true;
        if (error.status >= 500 && error.status < 600) return true; // Server errors
        return false;
    }

    /**
     * Exponential backoff delay
     *
     * @private
     */
    async _exponentialBackoff(retryCount) {
        const delay = Math.min(1000 * Math.pow(2, retryCount), 10000); // Max 10s
        await new Promise(resolve => setTimeout(resolve, delay));
    }

    /**
     * Update CSRF token (after refresh)
     * ENTERPRISE V8.2: Delegate to Need2Talk.CSRF single source of truth
     *
     * @param {string} token - New CSRF token
     */
    updateCsrfToken(token) {
        // Delegate to Need2Talk.CSRF - the single source of truth
        if (Need2Talk.CSRF) {
            Need2Talk.CSRF.token = token;
            // Also update meta tag for consistency
            const metaTag = document.querySelector('meta[name="csrf-token"]');
            if (metaTag) {
                metaTag.setAttribute('content', token);
            }
        }
        console.log('ApiClient: CSRF token updated via Need2Talk.CSRF');
    }

    /**
     * Refresh CSRF token from server
     *
     * @returns {Promise<string>} New CSRF token
     */
    async refreshCsrfToken() {
        try {
            const response = await this.post('/api/csrf/refresh');
            if (response.success && response.token) {
                this.updateCsrfToken(response.token);
                return response.token;
            }
            throw new Error('Failed to refresh CSRF token');
        } catch (error) {
            console.error('ApiClient: CSRF refresh failed', error);
            throw error;
        }
    }
}

/**
 * Custom API Error class
 */
class ApiError extends Error {
    constructor(message, code, status, data = null) {
        super(message);
        this.name = 'ApiError';
        this.code = code;
        this.status = status;
        this.data = data;
    }
}

/**
 * Global singleton instance
 */
const api = new ApiClient();

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ApiClient, ApiError, api };
}
