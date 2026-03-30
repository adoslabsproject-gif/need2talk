/**
 * NEED2TALK - User Activity Tracker
 * Enterprise JavaScript for user activity monitoring and analytics
 *
 * Tracks user behavior, engagement metrics, and provides
 * real-time activity updates for the profile system
 */

'use strict';

// Namespace protection
window.Need2Talk = window.Need2Talk || {};

/**
 * User Activity Tracker Class
 * Handles activity monitoring and analytics
 */
class UserActivity {
    constructor(options = {}) {
        this.user = options.user || {};
        this.config = {
            trackingEnabled: true,
            batchSize: 10,
            flushInterval: 30000, // 30 seconds
            maxRetries: 3,
            ...options.config
        };

        this.activityQueue = [];
        this.sessionData = {
            startTime: Date.now(),
            pageViews: 0,
            interactions: 0,
            timeOnPage: 0
        };

        this.init();
    }

    /**
     * Initialize Activity Tracker
     */
    init() {
        if (!this.config.trackingEnabled) {
            console.log('User activity tracking disabled');
            return;
        }

        this.setupEventListeners();
        this.startSessionTracking();
        this.startActivityBatcher();
        this.trackPageLoad();

        console.log('Need2Talk UserActivity: Enterprise tracking initialized! 📊');
    }

    /**
     * Setup event listeners for various user interactions
     */
    setupEventListeners() {
        // Mouse movements and clicks
        let mouseTimer;
        document.addEventListener('mousemove', this.throttle(() => {
            this.trackActivity('mouse_movement');
        }, 5000));

        document.addEventListener('click', (event) => {
            this.trackActivity('click', {
                element: event.target.tagName,
                class: event.target.className,
                x: event.clientX,
                y: event.clientY
            });
        });

        // Scroll tracking
        let scrollTimer;
        window.addEventListener('scroll', this.throttle(() => {
            const scrollPercentage = Math.round(
                (window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100
            );

            this.trackActivity('scroll', {
                percentage: scrollPercentage,
                position: window.scrollY
            });
        }, 2000));

        // Keyboard interactions
        document.addEventListener('keydown', this.throttle(() => {
            this.trackActivity('keyboard_interaction');
        }, 3000));

        // Focus and blur events
        window.addEventListener('focus', () => {
            this.trackActivity('window_focus');
            this.sessionData.focusTime = Date.now();
        });

        window.addEventListener('blur', () => {
            this.trackActivity('window_blur');
            if (this.sessionData.focusTime) {
                const focusDuration = Date.now() - this.sessionData.focusTime;
                this.trackActivity('focus_duration', { duration: focusDuration });
            }
        });

        // Page visibility API
        document.addEventListener('visibilitychange', () => {
            this.trackActivity('visibility_change', {
                hidden: document.hidden
            });
        });

        // Before unload
        window.addEventListener('beforeunload', () => {
            this.flushActivityQueue();
            this.trackSessionEnd();
        });

        // Error tracking
        window.addEventListener('error', (event) => {
            this.trackActivity('javascript_error', {
                message: event.message,
                filename: event.filename,
                line: event.lineno,
                column: event.colno
            });
        });

        // Performance monitoring
        this.setupPerformanceMonitoring();
    }

    /**
     * Setup performance monitoring
     */
    setupPerformanceMonitoring() {
        // Monitor long tasks
        if ('PerformanceObserver' in window) {
            try {
                const observer = new PerformanceObserver((list) => {
                    for (const entry of list.getEntries()) {
                        if (entry.duration > 50) { // Tasks longer than 50ms
                            this.trackActivity('performance_issue', {
                                type: 'long_task',
                                duration: entry.duration,
                                startTime: entry.startTime
                            });
                        }
                    }
                });

                observer.observe({ entryTypes: ['longtask'] });
            } catch (e) {
                console.warn('Performance monitoring not supported');
            }
        }

        // Monitor memory usage (if available)
        if (performance.memory) {
            setInterval(() => {
                const memory = performance.memory;
                this.trackActivity('memory_usage', {
                    used: memory.usedJSHeapSize,
                    total: memory.totalJSHeapSize,
                    limit: memory.jsHeapSizeLimit
                });
            }, 60000); // Every minute
        }
    }

    /**
     * Track individual activity
     */
    trackActivity(type, data = {}) {
        if (!this.config.trackingEnabled) return;

        // ENTERPRISE SECURITY: Use UUID instead of numeric ID
        const activity = {
            type,
            timestamp: Date.now(),
            user_uuid: this.user.uuid,
            session_id: this.getSessionId(),
            page: window.location.pathname,
            url: window.location.href,
            user_agent: navigator.userAgent,
            viewport: {
                width: window.innerWidth,
                height: window.innerHeight
            },
            ...data
        };

        this.activityQueue.push(activity);
        this.sessionData.interactions++;

        // Flush if queue is full
        if (this.activityQueue.length >= this.config.batchSize) {
            this.flushActivityQueue();
        }
    }

    /**
     * Track page load metrics
     */
    trackPageLoad() {
        window.addEventListener('load', () => {
            const perfData = performance.getEntriesByType('navigation')[0];

            this.trackActivity('page_load', {
                load_time: perfData.loadEventEnd - perfData.loadEventStart,
                dom_ready: perfData.domContentLoadedEventEnd - perfData.domContentLoadedEventStart,
                total_time: perfData.loadEventEnd - perfData.fetchStart,
                dns_time: perfData.domainLookupEnd - perfData.domainLookupStart,
                tcp_time: perfData.connectEnd - perfData.connectStart,
                response_time: perfData.responseEnd - perfData.responseStart
            });

            this.sessionData.pageViews++;
        });
    }

    /**
     * Start session tracking
     */
    startSessionTracking() {
        this.sessionData.sessionId = this.generateSessionId();

        // Track session start
        this.trackActivity('session_start', {
            referrer: document.referrer,
            landing_page: window.location.pathname
        });

        // Update time on page every 30 seconds
        setInterval(() => {
            this.sessionData.timeOnPage = Date.now() - this.sessionData.startTime;
            this.trackActivity('time_update', {
                time_on_page: this.sessionData.timeOnPage
            });
        }, 30000);
    }

    /**
     * Track session end
     */
    trackSessionEnd() {
        const sessionDuration = Date.now() - this.sessionData.startTime;

        this.trackActivity('session_end', {
            duration: sessionDuration,
            page_views: this.sessionData.pageViews,
            interactions: this.sessionData.interactions,
            exit_page: window.location.pathname
        });
    }

    /**
     * Start activity batcher
     */
    startActivityBatcher() {
        setInterval(() => {
            if (this.activityQueue.length > 0) {
                this.flushActivityQueue();
            }
        }, this.config.flushInterval);
    }

    /**
     * Flush activity queue to server
     */
    async flushActivityQueue() {
        if (this.activityQueue.length === 0) return;

        const activities = [...this.activityQueue];
        this.activityQueue = [];

        try {
            await this.sendActivities(activities);
        } catch (error) {
            console.error('Failed to send activities:', error);

            // Re-queue failed activities for retry
            this.activityQueue.unshift(...activities);

            // Limit queue size to prevent memory issues
            if (this.activityQueue.length > 100) {
                this.activityQueue = this.activityQueue.slice(0, 100);
            }
        }
    }

    /**
     * Send activities to server
     */
    async sendActivities(activities, retryCount = 0) {
        try {
            const response = await fetch('/api/v1/analytics/activities', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': this.getCSRFToken()
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    activities,
                    batch_id: this.generateBatchId(),
                    timestamp: Date.now()
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            // Log successful batch - ENTERPRISE SECURITY: Use UUID
            if (window.Need2Talk && window.Need2Talk.Logger) {
                window.Need2Talk.Logger.debug('activity', 'Activity batch sent successfully', {
                    count: activities.length,
                    user_uuid: this.user.uuid
                });
            }

        } catch (error) {
            if (retryCount < this.config.maxRetries) {
                console.warn(`Activity send failed, retrying... (${retryCount + 1}/${this.config.maxRetries})`);

                // Exponential backoff
                const delay = Math.pow(2, retryCount) * 1000;
                setTimeout(() => {
                    this.sendActivities(activities, retryCount + 1);
                }, delay);
            } else {
                throw error;
            }
        }
    }

    /**
     * Throttle function to limit event frequency
     */
    throttle(func, delay) {
        let timeoutId;
        let lastExecTime = 0;

        return function (...args) {
            const currentTime = Date.now();

            if (currentTime - lastExecTime > delay) {
                func.apply(this, args);
                lastExecTime = currentTime;
            } else {
                clearTimeout(timeoutId);
                timeoutId = setTimeout(() => {
                    func.apply(this, args);
                    lastExecTime = Date.now();
                }, delay - (currentTime - lastExecTime));
            }
        };
    }

    /**
     * Generate unique session ID
     */
    generateSessionId() {
        return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    /**
     * Generate unique batch ID
     */
    generateBatchId() {
        return 'batch_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    /**
     * Get current session ID
     */
    getSessionId() {
        return this.sessionData.sessionId;
    }

    /**
     * Get CSRF token from meta tag or cookie
     */
    getCSRFToken() {
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        if (metaTag) {
            return metaTag.getAttribute('content');
        }

        // Fallback to cookie
        const match = document.cookie.match(/csrf_token=([^;]+)/);
        return match ? match[1] : '';
    }

    /**
     * Get activity summary for current session
     */
    getSessionSummary() {
        return {
            session_id: this.sessionData.sessionId,
            duration: Date.now() - this.sessionData.startTime,
            page_views: this.sessionData.pageViews,
            interactions: this.sessionData.interactions,
            queue_size: this.activityQueue.length
        };
    }

    /**
     * Enable or disable tracking
     */
    setTrackingEnabled(enabled) {
        this.config.trackingEnabled = enabled;

        if (!enabled) {
            this.flushActivityQueue();
        }

        console.log(`User activity tracking ${enabled ? 'enabled' : 'disabled'}`);
    }

    /**
     * Clear activity queue (for privacy compliance)
     */
    clearActivityQueue() {
        this.activityQueue = [];
        console.log('Activity queue cleared');
    }
}

// Singleton initialization
if (!window.Need2Talk.UserActivity) {
    const userData = window.profileData ? window.profileData().user : {};

    window.Need2Talk.UserActivity = new UserActivity({
        user: userData,
        config: {
            trackingEnabled: true,
            batchSize: 10,
            flushInterval: 30000
        }
    });
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = UserActivity;
}