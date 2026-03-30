/**
 * need2talk API Client
 * Handles all API communication with CSRF and error handling
 */

// Global Need2Talk object
window.Need2Talk = window.Need2Talk || {};

/**
 * API Client for making requests
 */
Need2Talk.ApiClient = {
    
    /**
     * Base URL for API calls
     */
    baseUrl: '/api',

    /**
     * Default headers for all requests
     */
    getHeaders() {
        const headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };

        // Add CSRF token if available
        const csrfToken = Need2Talk.utils?.getCSRFToken?.();
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        return headers;
    },

    /**
     * Generic request method
     */
    async request(endpoint, options = {}) {
        try {
            const url = endpoint.startsWith('/') ? endpoint : `${this.baseUrl}/${endpoint}`;
            
            const config = {
                headers: this.getHeaders(),
                ...options
            };

            const response = await fetch(url, config);
            
            // Handle non-JSON responses
            const contentType = response.headers.get('content-type');
            let data;
            
            if (contentType && contentType.includes('application/json')) {
                data = await response.json();
            } else {
                data = await response.text();
            }

            if (!response.ok) {
                throw new Error(data.message || `HTTP ${response.status}: ${response.statusText}`);
            }

            return data;
            
        } catch (error) {
            console.error('API Request failed:', error);
            throw error;
        }
    },

    /**
     * GET request
     */
    async get(endpoint, params = {}) {
        const url = new URL(endpoint.startsWith('/') ? endpoint : `${this.baseUrl}/${endpoint}`, window.location.origin);
        
        // Add query parameters
        Object.keys(params).forEach(key => {
            if (params[key] !== null && params[key] !== undefined) {
                url.searchParams.append(key, params[key]);
            }
        });

        return this.request(url.pathname + url.search, {
            method: 'GET'
        });
    },

    /**
     * POST request
     */
    async post(endpoint, data = {}) {
        return this.request(endpoint, {
            method: 'POST',
            body: JSON.stringify(data)
        });
    },

    /**
     * PUT request
     */
    async put(endpoint, data = {}) {
        return this.request(endpoint, {
            method: 'PUT',
            body: JSON.stringify(data)
        });
    },

    /**
     * DELETE request
     */
    async delete(endpoint) {
        return this.request(endpoint, {
            method: 'DELETE'
        });
    },

    /**
     * Upload file with progress
     */
    async uploadFile(endpoint, file, onProgress = null) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('file', file);

            const xhr = new XMLHttpRequest();

            // Handle progress
            if (onProgress && xhr.upload) {
                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) {
                        const progress = (e.loaded / e.total) * 100;
                        onProgress(progress);
                    }
                };
            }

            // Handle completion
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        resolve(response);
                    } catch (error) {
                        resolve(xhr.responseText);
                    }
                } else {
                    reject(new Error(`Upload failed: ${xhr.status} ${xhr.statusText}`));
                }
            };

            xhr.onerror = () => reject(new Error('Upload failed'));

            // Set headers (don't set Content-Type for FormData)
            const headers = this.getHeaders();
            delete headers['Content-Type']; // Let browser set boundary for FormData
            
            Object.keys(headers).forEach(key => {
                xhr.setRequestHeader(key, headers[key]);
            });

            const url = endpoint.startsWith('/') ? endpoint : `${this.baseUrl}/${endpoint}`;
            xhr.open('POST', url);
            xhr.send(formData);
        });
    }
};

// Export for modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Need2Talk.ApiClient;
}