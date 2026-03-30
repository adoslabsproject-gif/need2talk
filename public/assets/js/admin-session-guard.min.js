/**
 * ENTERPRISE GALAXY: Admin Session Guard
 *
 * Automatic session timeout detection and graceful logout
 * Prevents security log spam from expired sessions
 *
 * Features:
 * - Heartbeat check every 60 seconds
 * - Elegant modal warning before logout
 * - Stops all polling/intervals
 * - Auto-redirect to login
 *
 * @version 1.0.0
 * @package Need2Talk
 */

class AdminSessionGuard {
    constructor() {
        this.heartbeatInterval = null;
        this.sessionCheckUrl = '/api/session/check';
        this.heartbeatFrequency = 60000; // 60 seconds
        this.lastActivityTime = Date.now();
        this.isSessionExpired = false;
        this.modalShown = false;

        // ENTERPRISE: Track all intervals/timeouts to stop them on session expire
        this.trackedIntervals = new Set();
        this.trackedTimeouts = new Set();

        this.init();
    }

    /**
     * ENTERPRISE: Initialize session guard
     */
    init() {
        console.debug('[Session Guard] Initialized - Heartbeat every 60s');

        // Start heartbeat check
        this.startHeartbeat();

        // Track user activity to update last activity time
        this.trackUserActivity();

        // Prevent bfcache from showing stale pages
        this.preventBfcache();

        // Monkey-patch setInterval/setTimeout to track them
        this.patchTimerFunctions();

        // Create modal HTML (hidden by default)
        this.createSessionExpiredModal();
    }

    /**
     * ENTERPRISE: Start heartbeat check
     */
    startHeartbeat() {
        this.heartbeatInterval = setInterval(() => {
            if (!this.isSessionExpired) {
                this.checkSession();
            }
        }, this.heartbeatFrequency);

        // Track this interval
        this.trackedIntervals.add(this.heartbeatInterval);
    }

    /**
     * ENTERPRISE GALAXY: Check if session is still valid with smart extension support
     */
    async checkSession() {
        try {
            // ENTERPRISE: Send time since last activity to backend for smart extension
            const timeSinceActivity = Math.floor((Date.now() - this.lastActivityTime) / 1000); // seconds
            const urlWithActivity = `${this.sessionCheckUrl}?activity=${timeSinceActivity}`;

            const response = await fetch(urlWithActivity, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            // ENTERPRISE: Check response status before parsing
            if (!response.ok) {
                console.debug(`[Session Guard] Heartbeat check HTTP ${response.status} - network issue (ignored)`);
                return; // Don't trigger logout on HTTP errors
            }

            // ENTERPRISE: Safe JSON parsing
            let data;
            try {
                data = await response.json();
            } catch (jsonError) {
                console.debug('[Session Guard] Heartbeat check response not JSON - network issue (ignored)');
                return; // Don't trigger logout on malformed responses
            }

            // 🚀 ENTERPRISE GALAXY: Log to js_errors channel via Logger::jsError()
            try {
                await fetch('/api/logs/client', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        logs: [{
                            level: 'info',
                            message: `[Session Guard] Heartbeat response: status=${response.status}, authenticated=${data.authenticated}, time_remaining=${data.time_remaining}, admin_url_valid=${data.admin_url_valid}`,
                            context: {
                                heartbeat_url: this.sessionCheckUrl,
                                response_status: response.status,
                                response_authenticated: data.authenticated,
                                response_time_remaining: data.time_remaining,
                                response_admin_url_valid: data.admin_url_valid
                            },
                            timestamp: Date.now(),
                            url: location.href,
                            user_agent: navigator.userAgent
                        }]
                    })
                });
            } catch (logError) {
                // Ignore logging errors
            }

            if (response.status === 401 || !data.authenticated) {
                console.warn('[Session Guard] Session expired - triggering logout');
                // ENTERPRISE GALAXY: Store URL validity for smart redirect
                this.adminUrlValid = data.admin_url_valid || false;
                this.currentAdminUrl = data.current_admin_url || null;
                this.handleSessionExpired();
            } else if (data.authenticated) {
                // ENTERPRISE GALAXY: Session valid - update state
                const timeRemaining = data.time_remaining || 3600;
                const inExtensionWindow = data.in_extension_window || false;
                this.adminUrlValid = data.admin_url_valid !== false; // Default true if not provided
                this.currentAdminUrl = data.current_admin_url || null;

                if (inExtensionWindow) {
                    console.info(`[Session Guard] ⏰ Session expiring in ${Math.floor(timeRemaining / 60)} minutes - will auto-extend if active`);
                } else {
                    console.debug(`[Session Guard] ✓ Session valid (${Math.floor(timeRemaining / 60)} minutes remaining)`);
                }
            }
        } catch (error) {
            // ENTERPRISE: Network errors are TEMPORARY, don't spam error logs
            console.debug('[Session Guard] Heartbeat check failed (network issue, ignored):', error.message);
            // Don't trigger logout on network errors - might be temporary
        }
    }

    /**
     * ENTERPRISE: Handle session expiration
     */
    handleSessionExpired() {
        if (this.isSessionExpired) return; // Already handled

        this.isSessionExpired = true;
        console.warn('[Session Guard] 🔒 Session expired - cleaning up');

        // CRITICAL: Stop ALL intervals and timeouts
        this.stopAllTimers();

        // Show modal to user
        this.showSessionExpiredModal();

        // Auto-logout after 10 seconds
        setTimeout(() => {
            this.performLogout();
        }, 10000);
    }

    /**
     * ENTERPRISE: Stop all tracked intervals/timeouts
     */
    stopAllTimers() {
        console.debug('[Session Guard] Stopping all timers...');

        // Stop all intervals
        this.trackedIntervals.forEach(id => {
            clearInterval(id);
        });
        this.trackedIntervals.clear();

        // Stop all timeouts
        this.trackedTimeouts.forEach(id => {
            clearTimeout(id);
        });
        this.trackedTimeouts.clear();

        // Stop specific known timers (backward compatibility)
        if (typeof stopAutoRefresh === 'function') {
            stopAutoRefresh();
        }
    }

    /**
     * ENTERPRISE: Create session expired modal HTML
     */
    createSessionExpiredModal() {
        const modalHtml = `
            <div id="session-expired-modal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.9); z-index: 99999; align-items: center; justify-content: center;">
                <div style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 1rem; max-width: 500px; padding: 2rem; box-shadow: 0 25px 50px rgba(239, 68, 68, 0.5); border: 2px solid rgba(239, 68, 68, 0.3); text-align: center;">
                    <div style="margin-bottom: 1.5rem;">
                        <i class="fas fa-clock" style="font-size: 4rem; color: #ef4444;"></i>
                    </div>
                    <h2 style="margin: 0 0 1rem 0; color: white; font-size: 1.75rem; font-weight: 700;">
                        Sessione Scaduta
                    </h2>
                    <p style="margin: 0 0 1.5rem 0; color: rgba(255, 255, 255, 0.8); font-size: 1rem; line-height: 1.6;">
                        La tua sessione amministratore è scaduta per motivi di sicurezza.
                        Sarai reindirizzato alla pagina di login tra <span id="logout-countdown" style="font-weight: 700; color: #ef4444;">10</span> secondi.
                    </p>
                    <button onclick="adminSessionGuard.performLogout()" style="background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%); color: white; border: none; padding: 0.75rem 2rem; border-radius: 0.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        <i class="fas fa-sign-out-alt" style="margin-right: 0.5rem;"></i>
                        Logout Ora
                    </button>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    /**
     * ENTERPRISE: Show session expired modal
     */
    showSessionExpiredModal() {
        if (this.modalShown) return;
        this.modalShown = true;

        const modal = document.getElementById('session-expired-modal');
        if (modal) {
            modal.style.display = 'flex';

            // Start countdown
            let seconds = 10;
            const countdownEl = document.getElementById('logout-countdown');

            const countdownInterval = setInterval(() => {
                seconds--;
                if (countdownEl) {
                    countdownEl.textContent = seconds;
                }

                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                }
            }, 1000);
        }
    }

    /**
     * ENTERPRISE GALAXY: Perform logout and smart redirect
     * Redirects to admin login if URL is still valid, otherwise to public home
     */
    performLogout() {
        console.log('[Session Guard] Performing logout...');

        // Clear session storage/local storage
        try {
            sessionStorage.clear();
            localStorage.removeItem('admin_session');
        } catch (e) {
            console.warn('[Session Guard] Could not clear storage:', e);
        }

        // ENTERPRISE GALAXY: Smart redirect based on URL validity
        if (this.adminUrlValid && this.currentAdminUrl) {
            // URL is still valid - redirect to admin login page
            console.log('[Session Guard] Admin URL still valid - redirecting to login');
            window.location.href = this.currentAdminUrl + '/login';
        } else {
            // URL expired or invalid - redirect to public home
            console.log('[Session Guard] Admin URL expired - redirecting to home');
            window.location.href = '/';
        }
    }

    /**
     * ENTERPRISE: Track user activity
     */
    trackUserActivity() {
        const events = ['mousedown', 'keydown', 'scroll', 'touchstart'];

        events.forEach(eventName => {
            document.addEventListener(eventName, () => {
                this.lastActivityTime = Date.now();
            }, { passive: true });
        });
    }

    /**
     * ENTERPRISE GALAXY: Prevent bfcache (back-forward cache) from showing stale pages
     * This forces browser to reload page when user presses Back button after logout
     */
    preventBfcache() {
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                // Page was restored from bfcache (browser back button)
                console.warn('[Session Guard] Page restored from bfcache - reloading to ensure fresh state');
                window.location.reload();
            }
        });
    }

    /**
     * ENTERPRISE: Monkey-patch timer functions to track them
     */
    patchTimerFunctions() {
        const originalSetInterval = window.setInterval;
        const originalSetTimeout = window.setTimeout;
        const self = this;

        window.setInterval = function(...args) {
            const id = originalSetInterval.apply(this, args);
            self.trackedIntervals.add(id);
            return id;
        };

        window.setTimeout = function(...args) {
            const id = originalSetTimeout.apply(this, args);
            self.trackedTimeouts.add(id);
            return id;
        };

        // Patch clear functions to remove from tracking
        const originalClearInterval = window.clearInterval;
        const originalClearTimeout = window.clearTimeout;

        window.clearInterval = function(id) {
            self.trackedIntervals.delete(id);
            return originalClearInterval.apply(this, arguments);
        };

        window.clearTimeout = function(id) {
            self.trackedTimeouts.delete(id);
            return originalClearTimeout.apply(this, arguments);
        };

        console.debug('[Session Guard] Timer functions patched for tracking');
    }
}

// ENTERPRISE: Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.adminSessionGuard = new AdminSessionGuard();
    });
} else {
    window.adminSessionGuard = new AdminSessionGuard();
}
