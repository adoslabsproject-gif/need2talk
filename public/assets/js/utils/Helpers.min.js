/**
 * need2talk - Helper Utilities
 * Enterprise Galaxy - Common utility functions
 *
 * Purpose: Reusable helper functions for DOM, time, formatting
 * Performance: Pure functions, no side effects, optimized
 */

/**
 * Format timestamp to human-readable "time ago" string (Italian)
 *
 * @param {string|Date} timestamp - ISO timestamp or Date object
 * @returns {string} Formatted time ago string
 */
function formatTimeAgo(timestamp) {
    const now = new Date();
    const past = new Date(timestamp);
    const diffMs = now - past;

    // Handle invalid dates
    if (isNaN(past.getTime())) {
        console.warn('formatTimeAgo: Invalid timestamp', timestamp);
        return 'Data non valida';
    }

    const diffSecs = Math.floor(diffMs / 1000);
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    const diffWeeks = Math.floor(diffMs / 604800000);
    const diffMonths = Math.floor(diffMs / 2592000000);
    const diffYears = Math.floor(diffMs / 31536000000);

    if (diffSecs < 30) return 'Ora';
    if (diffMins < 1) return `${diffSecs} secondi fa`;
    if (diffMins === 1) return '1 minuto fa';
    if (diffMins < 60) return `${diffMins} minuti fa`;
    if (diffHours === 1) return '1 ora fa';
    if (diffHours < 24) return `${diffHours} ore fa`;
    if (diffDays === 1) return 'Ieri';
    if (diffDays < 7) return `${diffDays} giorni fa`;
    if (diffWeeks === 1) return '1 settimana fa';
    if (diffWeeks < 4) return `${diffWeeks} settimane fa`;
    if (diffMonths === 1) return '1 mese fa';
    if (diffMonths < 12) return `${diffMonths} mesi fa`;
    if (diffYears === 1) return '1 anno fa';
    return `${diffYears} anni fa`;
}

/**
 * Escape HTML to prevent XSS attacks
 *
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML-safe text
 */
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }

    // Convert to string if not already
    text = String(text);

    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Convert @mentions to clickable profile links
 * ENTERPRISE V7.0 (2025-11-30): Clickable @username tags
 *
 * @param {string} text - Text with potential @mentions (already HTML escaped)
 * @param {Array} mentionedUsers - Array of mentioned users [{uuid, nickname}] from API
 * @returns {string} Text with @mentions converted to clickable links
 */
function linkifyMentions(text, mentionedUsers = []) {
    if (!text) return '';

    // If no mentioned users provided, just return escaped text
    if (!mentionedUsers || mentionedUsers.length === 0) {
        return escapeHtml(text);
    }

    // First escape the text
    let escapedText = escapeHtml(text);

    // Replace each @mention with a clickable link
    // Sort by nickname length (longest first) to avoid partial replacements
    const sortedUsers = [...mentionedUsers].sort((a, b) =>
        (b.nickname?.length || 0) - (a.nickname?.length || 0)
    );

    for (const user of sortedUsers) {
        if (!user.nickname || !user.uuid) continue;

        // Create regex to match @nickname (case insensitive, word boundary)
        const regex = new RegExp(`@(${escapeRegExp(user.nickname)})\\b`, 'gi');

        // Replace with clickable link
        escapedText = escapedText.replace(regex,
            `<a href="/u/${user.uuid}" class="text-purple-400 hover:text-purple-300 hover:underline font-medium transition-colors" onclick="event.stopPropagation()">@$1</a>`
        );
    }

    return escapedText;
}

/**
 * Escape special regex characters
 * @param {string} string - String to escape
 * @returns {string} Escaped string safe for regex
 */
function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Format number with thousands separator (Italian format)
 *
 * @param {number} num - Number to format
 * @returns {string} Formatted number (e.g., 1.234.567)
 */
function formatNumber(num) {
    if (typeof num !== 'number' || isNaN(num)) {
        return '0';
    }

    return new Intl.NumberFormat('it-IT').format(num);
}

/**
 * Format file size to human-readable string
 *
 * @param {number} bytes - Size in bytes
 * @param {number} decimals - Number of decimal places (default: 1)
 * @returns {string} Formatted size (e.g., "1.5 MB")
 */
function formatFileSize(bytes, decimals = 1) {
    if (bytes === 0) return '0 Bytes';
    if (bytes < 0) return 'Invalid';

    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];

    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

/**
 * Format duration in seconds to MM:SS or HH:MM:SS
 *
 * @param {number} seconds - Duration in seconds
 * @returns {string} Formatted duration
 */
function formatDuration(seconds) {
    if (typeof seconds !== 'number' || isNaN(seconds) || seconds < 0) {
        return '0:00';
    }

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    return `${minutes}:${String(secs).padStart(2, '0')}`;
}

/**
 * Debounce function execution (performance optimization)
 *
 * @param {Function} func - Function to debounce
 * @param {number} wait - Wait time in milliseconds
 * @returns {Function} Debounced function
 */
function debounce(func, wait) {
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
 * Throttle function execution (performance optimization)
 *
 * @param {Function} func - Function to throttle
 * @param {number} limit - Time limit in milliseconds
 * @returns {Function} Throttled function
 */
function throttle(func, limit) {
    let inThrottle;
    return function executedFunction(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Check if element is in viewport (for lazy loading)
 *
 * @param {HTMLElement} element - Element to check
 * @param {number} offset - Offset in pixels (default: 0)
 * @returns {boolean} True if in viewport
 */
function isInViewport(element, offset = 0) {
    if (!element) return false;

    const rect = element.getBoundingClientRect();
    return (
        rect.top >= -offset &&
        rect.left >= -offset &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) + offset &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth) + offset
    );
}

/**
 * Get query parameter from URL
 *
 * @param {string} param - Parameter name
 * @returns {string|null} Parameter value or null
 */
function getQueryParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}

/**
 * Set query parameter in URL (without reload)
 *
 * @param {string} param - Parameter name
 * @param {string} value - Parameter value
 */
function setQueryParam(param, value) {
    const url = new URL(window.location.href);
    url.searchParams.set(param, value);
    window.history.pushState({}, '', url);
}

/**
 * Copy text to clipboard
 *
 * @param {string} text - Text to copy
 * @returns {Promise<boolean>} True if successful
 */
async function copyToClipboard(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        } else {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.select();
            const successful = document.execCommand('copy');
            document.body.removeChild(textArea);
            return successful;
        }
    } catch (error) {
        console.error('Failed to copy to clipboard:', error);
        return false;
    }
}

/**
 * Generate random ID (for temporary elements)
 *
 * @param {number} length - Length of ID (default: 8)
 * @returns {string} Random ID
 */
function generateId(length = 8) {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < length; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return result;
}

/**
 * Sleep/delay function (Promise-based)
 *
 * @param {number} ms - Milliseconds to sleep
 * @returns {Promise<void>}
 */
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Truncate text with ellipsis
 *
 * @param {string} text - Text to truncate
 * @param {number} maxLength - Maximum length
 * @returns {string} Truncated text
 */
function truncate(text, maxLength) {
    if (!text || text.length <= maxLength) {
        return text;
    }
    return text.substring(0, maxLength) + '...';
}

/**
 * Validate email format
 *
 * @param {string} email - Email to validate
 * @returns {boolean} True if valid
 */
function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

/**
 * Show element with fade-in animation
 *
 * @param {HTMLElement} element - Element to show
 * @param {number} duration - Animation duration in ms (default: 300)
 */
function fadeIn(element, duration = 300) {
    if (!element) return;

    element.style.opacity = '0';
    element.style.display = '';
    element.classList.remove('hidden');

    let start = null;
    function animate(timestamp) {
        if (!start) start = timestamp;
        const progress = timestamp - start;
        element.style.opacity = Math.min(progress / duration, 1);

        if (progress < duration) {
            requestAnimationFrame(animate);
        }
    }
    requestAnimationFrame(animate);
}

/**
 * Hide element with fade-out animation
 *
 * @param {HTMLElement} element - Element to hide
 * @param {number} duration - Animation duration in ms (default: 300)
 */
function fadeOut(element, duration = 300) {
    if (!element) return;

    let start = null;
    function animate(timestamp) {
        if (!start) start = timestamp;
        const progress = timestamp - start;
        element.style.opacity = Math.max(1 - progress / duration, 0);

        if (progress < duration) {
            requestAnimationFrame(animate);
        } else {
            element.style.display = 'none';
            element.classList.add('hidden');
        }
    }
    requestAnimationFrame(animate);
}

/**
 * Local storage wrapper with error handling
 */
const Storage = {
    get(key, defaultValue = null) {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : defaultValue;
        } catch (error) {
            console.error('Storage.get error:', error);
            return defaultValue;
        }
    },

    set(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (error) {
            console.error('Storage.set error:', error);
            return false;
        }
    },

    remove(key) {
        try {
            localStorage.removeItem(key);
            return true;
        } catch (error) {
            console.error('Storage.remove error:', error);
            return false;
        }
    },

    clear() {
        try {
            localStorage.clear();
            return true;
        } catch (error) {
            console.error('Storage.clear error:', error);
            return false;
        }
    }
};

// Export for ES6 modules (if supported)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        formatTimeAgo,
        escapeHtml,
        formatNumber,
        formatFileSize,
        formatDuration,
        debounce,
        throttle,
        isInViewport,
        getQueryParam,
        setQueryParam,
        copyToClipboard,
        generateId,
        sleep,
        truncate,
        isValidEmail,
        fadeIn,
        fadeOut,
        Storage
    };
}
