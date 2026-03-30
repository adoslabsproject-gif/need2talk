/**
 * need2talk Core Utilities
 * Utility functions used throughout the application
 */

// Global Need2Talk object
window.Need2Talk = window.Need2Talk || {};

/**
 * Utility functions
 */
Need2Talk.utils = {
    
    /**
     * Format duration in seconds to MM:SS format
     */
    formatDuration(seconds) {
        if (!seconds || isNaN(seconds)) return '00:00';
        
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    },

    /**
     * Format time for display
     */
    formatTime(seconds) {
        return this.formatDuration(seconds);
    },

    /**
     * Debounce function calls
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
    },

    /**
     * Throttle function calls
     */
    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        }
    },

    /**
     * Get CSRF token from meta tag
     */
    getCSRFToken() {
        const token = document.querySelector('meta[name="csrf-token"]');
        return token ? token.getAttribute('content') : null;
    },

    /**
     * Show toast notification
     */
    showToast(message, type = 'info', duration = 3000) {
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg text-white transition-all transform translate-x-full`;
        
        // Set color based on type
        switch(type) {
            case 'success':
                toast.classList.add('bg-green-600');
                break;
            case 'error':
                toast.classList.add('bg-red-600');
                break;
            case 'warning':
                toast.classList.add('bg-yellow-600');
                break;
            default:
                toast.classList.add('bg-purple-600');
        }
        
        toast.textContent = message;
        document.body.appendChild(toast);
        
        // Animate in
        setTimeout(() => {
            toast.classList.remove('translate-x-full');
        }, 100);
        
        // Remove after duration
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => {
                document.body.removeChild(toast);
            }, 300);
        }, duration);
    },

    /**
     * Scroll to element smoothly
     */
    scrollTo(elementOrSelector) {
        const element = typeof elementOrSelector === 'string'
            ? document.querySelector(elementOrSelector)
            : elementOrSelector;

        if (element) {
            element.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    },

    /**
     * ENTERPRISE: UTF-8 safe Base64 encoding
     * Standard btoa() only supports Latin1 (0-255). This handles full Unicode including emoji.
     * Uses TextEncoder to convert string to UTF-8 bytes, then encodes as base64.
     * @param {string} str - The UTF-8 string to encode
     * @returns {string} Base64 encoded string
     */
    utf8ToBase64(str) {
        const bytes = new TextEncoder().encode(str);
        const binString = Array.from(bytes, byte => String.fromCodePoint(byte)).join('');
        return btoa(binString);
    },

    /**
     * ENTERPRISE: UTF-8 safe Base64 decoding
     * Standard atob() returns Latin1. This properly decodes UTF-8 encoded base64.
     * Uses TextDecoder to convert bytes back to UTF-8 string.
     * @param {string} base64 - The base64 string to decode
     * @returns {string} UTF-8 decoded string
     */
    base64ToUtf8(base64) {
        const binString = atob(base64);
        const bytes = Uint8Array.from(binString, char => char.codePointAt(0));
        return new TextDecoder().decode(bytes);
    }
};

// ENTERPRISE GALAXY: Global window.showToast alias for cross-module compatibility
// FeedManager and other modules use window.showToast for toast notifications
window.showToast = (message, type, duration) => Need2Talk.utils.showToast(message, type, duration);

// Export for modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Need2Talk.utils;
}