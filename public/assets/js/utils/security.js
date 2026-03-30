/**
 * =================================================================
 * SECURITY UTILITIES - Anti-bot and Fraud Detection
 * =================================================================
 * Client-side security measures for form protection
 * =================================================================
 */

/**
 * Device fingerprinting for fraud detection
 */
export const generateDeviceFingerprint = () => {
    const fingerprint = {
        screen: `${screen.width}x${screen.height}`,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        language: navigator.language,
        platform: navigator.platform,
        userAgent: navigator.userAgent.substring(0, 100), // Truncated for privacy
        timestamp: Date.now()
    };
    
    return btoa(JSON.stringify(fingerprint));
};

/**
 * Mouse movement tracking for bot detection
 */
export class MouseTracker {
    constructor() {
        this.movements = 0;
        this.init();
    }

    init() {
        document.addEventListener('mousemove', () => {
            this.movements++;
            this.updateHiddenField();
        });
    }

    updateHiddenField() {
        const field = document.getElementById('mouse_movements');
        if (field) {
            field.value = this.movements;
        }
    }

    getMovements() {
        return this.movements;
    }
}

/**
 * Keystroke tracking for human behavior detection
 */
export class KeystrokeTracker {
    constructor() {
        this.keystrokes = 0;
        this.init();
    }

    init() {
        document.addEventListener('keydown', () => {
            this.keystrokes++;
        });
    }

    getKeystrokes() {
        return this.keystrokes;
    }
}

/**
 * Rate limiting for registration attempts
 */
export class RateLimiter {
    constructor() {
        this.storageKey = 'registrationAttempts';
        this.lastAttemptKey = 'lastRegistrationAttempt';
        this.maxAttempts = 3;
        this.cooldownPeriod = 3600000; // 1 hour
    }

    getAttemptCount() {
        return parseInt(localStorage.getItem(this.storageKey) || '0');
    }

    getLastAttempt() {
        return parseInt(localStorage.getItem(this.lastAttemptKey) || '0');
    }

    incrementAttempts() {
        const currentCount = this.getAttemptCount();
        localStorage.setItem(this.storageKey, (currentCount + 1).toString());
        localStorage.setItem(this.lastAttemptKey, Date.now().toString());
    }

    shouldBlock() {
        const attemptCount = this.getAttemptCount();
        const lastAttempt = this.getLastAttempt();
        
        // Reset if cooldown period has passed
        if (Date.now() - lastAttempt > this.cooldownPeriod) {
            this.reset();
            return false;
        }

        return attemptCount >= this.maxAttempts;
    }

    getTimeLeft() {
        const lastAttempt = this.getLastAttempt();
        const timeLeft = this.cooldownPeriod - (Date.now() - lastAttempt);
        return Math.max(0, Math.ceil(timeLeft / 60000)); // Minutes
    }

    reset() {
        localStorage.removeItem(this.storageKey);
        localStorage.removeItem(this.lastAttemptKey);
    }
}

/**
 * Form timing analysis for bot detection
 */
export class FormTimer {
    constructor() {
        this.startTime = Date.now();
    }

    getDuration() {
        return Date.now() - this.startTime;
    }

    isTooFast(minSeconds = 5) {
        return this.getDuration() < (minSeconds * 1000);
    }
}

/**
 * Accessibility detection
 */
export const detectReducedMotion = () => {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
};

/**
 * Show warning notifications
 */
export const showWarning = (message, duration = 5000) => {
    const warning = document.createElement('div');
    warning.className = 'fixed top-4 right-4 bg-yellow-600 text-white px-4 py-2 rounded-lg shadow-lg z-50 fade-in';
    warning.textContent = message;
    document.body.appendChild(warning);
    
    setTimeout(() => {
        warning.classList.add('fade-out');
        setTimeout(() => {
            if (warning.parentNode) {
                warning.parentNode.removeChild(warning);
            }
        }, 300);
    }, duration);
};

/**
 * Show notification messages
 */
export const showNotification = (message, type = 'info', duration = 5000) => {
    const notification = document.createElement('div');
    const typeClasses = {
        error: 'bg-red-600 text-white',
        success: 'bg-green-600 text-white',
        info: 'bg-blue-600 text-white',
        warning: 'bg-yellow-600 text-white'
    };
    
    notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 fade-in ${typeClasses[type] || typeClasses.info}`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, duration);
};