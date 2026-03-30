/**
 * ================================================================================
 * SERVICE WORKER REGISTRATION - ENTERPRISE GALAXY LEVEL
 * ================================================================================
 *
 * PURPOSE:
 * Register audio cache Service Worker with intelligent lifecycle management
 *
 * FEATURES:
 * - Progressive enhancement (graceful degradation if SW not supported)
 * - Automatic SW updates detection
 * - User prompts for SW updates
 * - Registration error handling
 * - Update notifications
 * - Cache management UI integration
 *
 * BROWSER SUPPORT CHECK:
 * - Checks if browser supports Service Workers
 * - Falls back to normal network requests if not supported
 * - No breaking changes for non-supporting browsers
 *
 * LIFECYCLE STATES:
 * - Installing: SW being installed
 * - Installed: SW installed, waiting to activate
 * - Activating: SW being activated
 * - Activated: SW active and controlling pages
 * - Redundant: SW replaced by newer version
 *
 * ================================================================================
 */

class ServiceWorkerManager {
    constructor() {
        this.registration = null;
        this.isSupported = 'serviceWorker' in navigator;
        this.updateCheckInterval = null;

        // Configuration
        this.SW_PATH = '/assets/js/audio/sw-audio-cache.js';
        this.SW_SCOPE = '/';
        this.UPDATE_CHECK_INTERVAL = 60000; // 1 minute

        // UI elements (optional)
        this.updateNotificationEl = null;

        // Metrics
        this.metrics = {
            registration_time: 0,
            update_checks: 0,
            updates_found: 0,
        };

        this.init();
    }

    /**
     * Initialize Service Worker Manager
     */
    async init() {
        if (!this.isSupported) {
            this.showBrowserNotSupported();
            return;
        }

        // Wait for page load before registering SW
        if (document.readyState === 'loading') {
            window.addEventListener('DOMContentLoaded', () => this.register());
        } else {
            this.register();
        }

        // Check for updates periodically
        this.scheduleUpdateChecks();
    }

    /**
     * Register Service Worker
     */
    async register() {
        const startTime = performance.now();

        try {
            this.registration = await navigator.serviceWorker.register(this.SW_PATH, {
                scope: this.SW_SCOPE
            });

            this.metrics.registration_time = performance.now() - startTime;

            // Listen for updates
            this.registration.addEventListener('updatefound', () => {
                this.handleUpdateFound();
            });

            // Handle existing SW states
            if (this.registration.installing) {
                this.trackSWState(this.registration.installing, 'installing');
            } else if (this.registration.waiting) {
                this.showUpdateNotification();
            }

            // Listen for messages from SW
            navigator.serviceWorker.addEventListener('message', (event) => {
                this.handleSWMessage(event);
            });

        } catch (error) {
            console.error('[SW Manager] ❌ Service Worker registration failed:', error);
            this.showRegistrationError(error);
        }
    }

    /**
     * Handle SW update found
     */
    handleUpdateFound() {
        this.metrics.updates_found++;

        const newWorker = this.registration.installing;

        this.trackSWState(newWorker, 'installing');

        newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                // New SW installed, waiting to activate
                this.showUpdateNotification();
            }
        });
    }

    /**
     * Track SW lifecycle state
     */
    trackSWState(sw, initialState) {
        sw.addEventListener('statechange', () => {
        });
    }

    /**
     * Show update notification to user
     */
    showUpdateNotification() {
        // Check if notification element exists
        this.updateNotificationEl = document.getElementById('sw-update-notification');

        if (this.updateNotificationEl) {
            this.updateNotificationEl.style.display = 'block';
        } else {
            // Create notification element dynamically
            this.createUpdateNotification();
        }
    }

    /**
     * Create update notification element
     */
    createUpdateNotification() {
        const notification = document.createElement('div');
        notification.id = 'sw-update-notification';
        notification.className = 'sw-update-notification';
        notification.innerHTML = `
            <div class="sw-update-content">
                <span class="sw-update-icon">🔄</span>
                <span class="sw-update-text">New version available!</span>
                <button id="sw-update-reload" class="sw-update-btn">Update Now</button>
                <button id="sw-update-dismiss" class="sw-update-btn-secondary">Later</button>
            </div>
        `;

        document.body.appendChild(notification);

        // Add event listeners
        document.getElementById('sw-update-reload').addEventListener('click', () => {
            this.activateWaitingSW();
        });

        document.getElementById('sw-update-dismiss').addEventListener('click', () => {
            notification.style.display = 'none';
        });

        this.updateNotificationEl = notification;
    }

    /**
     * Activate waiting Service Worker
     */
    activateWaitingSW() {
        if (!this.registration || !this.registration.waiting) {
            return;
        }

        // Send SKIP_WAITING message to SW
        this.registration.waiting.postMessage({ type: 'SKIP_WAITING' });

        // Reload page when new SW takes control
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            window.location.reload();
        });
    }

    /**
     * Schedule periodic update checks
     */
    scheduleUpdateChecks() {
        this.updateCheckInterval = setInterval(async () => {
            if (this.registration) {
                this.metrics.update_checks++;

                try {
                    await this.registration.update();
                } catch (error) {
                    // Update check failed silently
                }
            }
        }, this.UPDATE_CHECK_INTERVAL);
    }

    /**
     * Handle messages from Service Worker
     */
    handleSWMessage(event) {
        const { type, data } = event.data;

        switch (type) {
            case 'SW_ACTIVATED':
                // ENTERPRISE V5.0.2 (2026-01-19): Auto-reload when new SW activates
                // CONTEXT: PWA cache fix - new SW clears caches, need fresh HTML
                // This ensures users get updated CSS fixes (title colors, etc.)
                console.log('[SW Manager] ✅ New Service Worker activated, reloading page...');
                window.location.reload();
                break;

            case 'CACHE_UPDATED':
                break;

            case 'CACHE_SIZE_UPDATE':
                this.updateCacheSizeUI(data.size);
                break;
        }
    }

    /**
     * Get cache size from SW
     */
    async getCacheSize() {
        if (!this.registration || !this.registration.active) {
            return 0;
        }

        return new Promise((resolve) => {
            const messageChannel = new MessageChannel();

            messageChannel.port1.onmessage = (event) => {
                resolve(event.data.size || 0);
            };

            this.registration.active.postMessage(
                { type: 'GET_CACHE_SIZE' },
                [messageChannel.port2]
            );

            // Timeout after 5 seconds
            setTimeout(() => resolve(0), 5000);
        });
    }

    /**
     * Clear cache via SW
     */
    async clearCache() {
        if (!this.registration || !this.registration.active) {
            return false;
        }

        return new Promise((resolve) => {
            const messageChannel = new MessageChannel();

            messageChannel.port1.onmessage = (event) => {
                resolve(event.data.success || false);
            };

            this.registration.active.postMessage(
                { type: 'CLEAR_CACHE' },
                [messageChannel.port2]
            );

            setTimeout(() => resolve(false), 5000);
        });
    }

    /**
     * ========================================================================
     * CACHE INVALIDATION METHODS (ENTERPRISE GALAXY)
     * ========================================================================
     */

    /**
     * Invalidate specific audio from browser cache
     * Called when audio is deleted or privacy changed
     *
     * @param {string} audioUrl - Full CDN URL of the audio
     * @param {string} audioUuid - UUID of the audio file (optional, for pattern match)
     * @returns {Promise<{success: boolean, deleted?: number, urls?: string[]}>}
     */
    async invalidateAudio(audioUrl, audioUuid = null) {
        if (!this.registration?.active) {
            return { success: false, reason: 'sw_not_active' };
        }

        return new Promise((resolve) => {
            const messageChannel = new MessageChannel();

            messageChannel.port1.onmessage = (event) => {
                const result = event.data;
                resolve(result);
            };

            this.registration.active.postMessage(
                { type: 'INVALIDATE_AUDIO', audioUrl, audioUuid },
                [messageChannel.port2]
            );

            // Timeout after 5 seconds
            setTimeout(() => {
                resolve({ success: false, reason: 'timeout' });
            }, 5000);
        });
    }

    /**
     * Invalidate all audios from a specific user
     * Called when user changes privacy settings in bulk
     *
     * @param {string} userUuid - UUID of the user
     * @returns {Promise<{success: boolean, deleted?: number, urls?: string[]}>}
     */
    async invalidateUserAudios(userUuid) {
        if (!this.registration?.active) {
            return { success: false, reason: 'sw_not_active' };
        }

        if (!userUuid) {
            return { success: false, reason: 'missing_user_uuid' };
        }

        return new Promise((resolve) => {
            const messageChannel = new MessageChannel();

            messageChannel.port1.onmessage = (event) => {
                const result = event.data;
                resolve(result);
            };

            this.registration.active.postMessage(
                { type: 'INVALIDATE_USER_AUDIOS', userUuid },
                [messageChannel.port2]
            );

            // Timeout after 10 seconds (user audios may be many)
            setTimeout(() => {
                resolve({ success: false, reason: 'timeout' });
            }, 10000);
        });
    }

    /**
     * Get cache version info from Service Worker
     * Useful for checking if cache is stale
     *
     * @returns {Promise<{version: string, cache_name: string, idb_name: string, idb_version: number}>}
     */
    async getCacheVersion() {
        if (!this.registration?.active) {
            return { version: null, reason: 'sw_not_active' };
        }

        return new Promise((resolve) => {
            const messageChannel = new MessageChannel();

            messageChannel.port1.onmessage = (event) => {
                resolve(event.data);
            };

            this.registration.active.postMessage(
                { type: 'CACHE_VERSION_CHECK' },
                [messageChannel.port2]
            );

            setTimeout(() => {
                resolve({ version: null, reason: 'timeout' });
            }, 5000);
        });
    }

    /**
     * Update cache size UI
     */
    updateCacheSizeUI(sizeBytes) {
        const sizeMB = (sizeBytes / (1024 * 1024)).toFixed(2);

        const cacheStatsEl = document.getElementById('cache-stats');
        if (cacheStatsEl) {
            cacheStatsEl.innerHTML = `Cache size: ${sizeMB} MB`;
        }
    }

    /**
     * Show browser not supported message
     */
    showBrowserNotSupported() {
        // Optional: Show user notification
        const notificationEl = document.getElementById('sw-not-supported');
        if (notificationEl) {
            notificationEl.style.display = 'block';
        }
    }

    /**
     * Show registration error
     */
    showRegistrationError(error) {
        console.error('[SW Manager] Registration error:', error);

        // Optional: Show user notification
        const errorEl = document.getElementById('sw-registration-error');
        if (errorEl) {
            errorEl.textContent = `Service Worker error: ${error.message}`;
            errorEl.style.display = 'block';
        }
    }

    /**
     * Get metrics
     */
    getMetrics() {
        return {
            ...this.metrics,
            is_supported: this.isSupported,
            is_registered: this.registration !== null,
            is_active: this.registration?.active !== null,
        };
    }

    /**
     * Unregister Service Worker (for debugging)
     */
    async unregister() {
        if (!this.registration) {
            return false;
        }

        try {
            const unregistered = await this.registration.unregister();
            return unregistered;
        } catch (error) {
            return false;
        }
    }
}

// Initialize Service Worker Manager on page load
const swManager = new ServiceWorkerManager();

// Expose to global scope for debugging
if (typeof window !== 'undefined') {
    window.swManager = swManager;
}
