/**
 * Enterprise JavaScript Error Monitor
 * 
 * Sistema avanzato per catturare, analizzare e reportare errori JavaScript
 * Progettato per migliaia di utenti concorrenti con performance elevate
 * 
 * Features:
 * - Real-time error capture
 * - Performance monitoring
 * - Console.log interception
 * - Automatic error categorization
 * - Enterprise logging with context
 * - Memory leak detection
 */

window.Need2Talk = window.Need2Talk || {};

Need2Talk.EnterpriseErrorMonitor = {
    version: '1.0.0',
    errors: [],
    warnings: [],
    performanceMetrics: {},
    isInitialized: false,
    isDeferredInitialized: false, // PERFORMANCE: Track deferred initialization
    isPageUnloading: false, // ENTERPRISE: Track if page is being unloaded/refreshed
    pendingRequests: new Set(), // ENTERPRISE: Track active fetch requests
    sendQueue: [], // ENTERPRISE: Queue for debouncing log sends
    sendTimer: null, // ENTERPRISE: Debounce timer
    allowedMethods: {}, // PERFORMANCE: Pre-calculated allowed methods (fast lookup)
    // GALAXY LEVEL: Verbose mode (auto-detect from environment)
    verbose: false,
    // ENTERPRISE PSR-3: Current logging level for js_errors channel
    // Synchronized with backend PHP configuration (LoggingConfigService)
    currentLogLevel: 'info', // Default: info (maps to PSR-3)
    // ENTERPRISE: Enable/disable browser console output (separate from file logging)
    // When false: logs sent to server (file .log) but NOT shown in browser console
    // When true: logs shown in browser console AND sent to server
    enableBrowserConsole: true, // Default: enabled
    // ENTERPRISE PSR-3: W3C Console API → PSR-3 Level Mapping
    consoleToPsr3Map: {
        'error': 'error',      // console.error() → PSR-3 error
        'warn': 'warning',     // console.warn() → PSR-3 warning
        'info': 'info',        // console.info() → PSR-3 info
        'log': 'info',         // console.log() → PSR-3 info (default logs are informational)
        'debug': 'debug',      // console.debug() → PSR-3 debug
        'trace': 'debug'       // console.trace() → PSR-3 debug
    },
    // ENTERPRISE PSR-3: Level hierarchy (for filtering)
    psr3Levels: {
        'emergency': 0,
        'alert': 1,
        'critical': 2,
        'error': 3,
        'warning': 4,
        'notice': 5,
        'info': 6,
        'debug': 7
    },
    // ENTERPRISE: Store native console BEFORE wrapping to avoid infinite loops
    nativeConsole: {
        log: console.log.bind(console),
        warn: console.warn.bind(console),
        error: console.error.bind(console),
        info: console.info.bind(console),
        debug: console.debug.bind(console),
        trace: console.trace.bind(console)
    },

    /**
     * GALAXY LEVEL: Internal logging (respects verbose mode)
     */
    internalLog(...args) {
        if (this.verbose && this.enableBrowserConsole) {
            this.nativeConsole.log(...args);
        }
    },

    /**
     * ENTERPRISE PSR-3: Check if log should be displayed (like PHP should_log())
     *
     * @param {string} level - PSR-3 level (error, warning, info, debug, etc.)
     * @returns {boolean} - True if log should be displayed
     *
     * Architecture:
     * - Maps W3C Console API (console.error/warn/info/log/debug) to PSR-3 levels
     * - Filters based on currentLogLevel (set from backend js_errors channel)
     * - Uses same hierarchy as PHP: error (3) < warning (4) < info (6) < debug (7)
     *
     * Examples:
     * - currentLogLevel = 'info': error, warning, info shown; debug hidden
     * - currentLogLevel = 'warning': error, warning shown; info, debug hidden
     * - currentLogLevel = 'error': only error shown; everything else hidden
     * - currentLogLevel = 'debug': ALL logs shown (verbose mode)
     */
    shouldLog(level) {
        // Convert to PSR-3 level if it's a console method
        const psr3Level = this.consoleToPsr3Map[level] || level;

        // Get numeric priorities
        const currentPriority = this.psr3Levels[this.currentLogLevel];
        const messagePriority = this.psr3Levels[psr3Level];

        // If level not recognized, allow by default (safety)
        if (currentPriority === undefined || messagePriority === undefined) {
            return true;
        }

        // Show if message priority is HIGHER or EQUAL to current threshold
        // Lower number = higher priority (emergency=0, debug=7)
        // Example: currentLogLevel='info' (6), message='error' (3) → 3 <= 6 → SHOW
        // Example: currentLogLevel='info' (6), message='debug' (7) → 7 <= 6 → HIDE
        return messagePriority <= currentPriority;
    },

    /**
     * PERFORMANCE: Fast initialization - Console interception ONLY
     * This runs SYNCHRONOUSLY to intercept console before any other scripts
     */
    initFast() {
        if (this.isInitialized) {
            return;
        }

        const startTime = performance.now();

        // GALAXY LEVEL: Auto-detect verbose mode from environment
        const env = window.APP_ENV || 'production';
        const debug = window.APP_DEBUG || false;
        this.verbose = window.VERBOSE_LOGGING || false;

        // ENTERPRISE PSR-3: Read log level from backend (synchronized with PHP LoggingConfigService)
        // Falls back to verbose detection if not explicitly set
        this.currentLogLevel = window.JS_ERRORS_LOG_LEVEL || (this.verbose ? 'debug' : 'info');

        // ENTERPRISE: Read browser console enable/disable flag from backend
        this.enableBrowserConsole = window.BROWSER_CONSOLE_ENABLED !== undefined
            ? window.BROWSER_CONSOLE_ENABLED
            : true;

        // CRITICAL: Intercept console methods immediately (FAST!)
        // No filtering during bootstrap for maximum speed
        this.interceptConsole();

        this.isInitialized = true;
    },

    /**
     * PERFORMANCE: Deferred initialization - Heavy operations run async
     * Error handlers, performance monitoring, etc. (doesn't block page load)
     */
    initDeferred() {
        if (this.isDeferredInitialized) {
            return;
        }

        this.internalLog('🚀 Enterprise Error Monitor deferred init...');
        this.internalLog('📊 Current log level:', this.currentLogLevel);
        this.internalLog('🖥️  Browser console:', this.enableBrowserConsole ? 'ENABLED' : 'DISABLED');

        // Global error handler
        this.setupGlobalErrorHandler();

        // Unhandled promise rejection handler
        this.setupPromiseRejectionHandler();

        // Resource error handler
        this.setupResourceErrorHandler();

        // Performance monitoring
        this.initPerformanceMonitoring();

        // Memory leak detection
        this.initMemoryMonitoring();

        // ENTERPRISE: Detect page unload/refresh to prevent CORS errors
        this.setupPageUnloadDetection();

        this.isDeferredInitialized = true;
        this.internalLog('✅ Enterprise Error Monitor fully initialized');
    },

    /**
     * Initialize enterprise error monitoring (LEGACY - kept for compatibility)
     */
    init() {
        this.initFast();
        this.initDeferred();
    },

    /**
     * Setup page unload detection
     * ENTERPRISE: Prevents fetch() errors during page refresh
     */
    setupPageUnloadDetection() {
        // Detect when page is being unloaded
        window.addEventListener('beforeunload', () => {
            this.isPageUnloading = true;
            this.internalLog('🔄 Page unloading detected, halting log sends');

            // Abort all pending requests to prevent CORS errors
            this.pendingRequests.forEach(controller => {
                try {
                    controller.abort();
                } catch (e) {
                    // Silent - expected during unload
                }
            });
            this.pendingRequests.clear();
        });

        // Detect visibility change (tab switch, minimize)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Flush pending logs when user leaves page
                this.flushQueue();
            }
        });
    },
    
    /**
     * PERFORMANCE: Lightweight console interception with minimal overhead
     * Heavy operations (PSR-3 filtering, server logging) deferred if not ready
     */
    interceptConsole() {
        const self = this;
        const originalMethods = {};
        const methods = ['log', 'warn', 'error', 'info', 'debug', 'trace'];

        methods.forEach(method => {
            originalMethods[method] = console[method];

            console[method] = (...args) => {
                // PERFORMANCE: Ultra-fast path during bootstrap (NO filtering, NO logic)
                if (!self.isDeferredInitialized) {
                    // Phase 1 (bootstrap): Pass everything through if browser console enabled
                    // This is FASTEST possible code path - zero overhead
                    if (self.enableBrowserConsole) {
                        originalMethods[method].apply(console, args);
                    }
                    return;
                }

                // Phase 2 (after deferred init): PSR-3 filtering + enterprise logging
                // Check PSR-3 level filtering
                const psr3Level = self.consoleToPsr3Map[method] || method;
                const currentPriority = self.psr3Levels[self.currentLogLevel];
                const messagePriority = self.psr3Levels[psr3Level];

                // Filter out if priority too low (inline for speed)
                if (currentPriority !== undefined && messagePriority !== undefined && messagePriority > currentPriority) {
                    return;
                }

                // Show in browser console if enabled
                if (self.enableBrowserConsole) {
                    originalMethods[method].apply(console, args);
                }

                // Send to enterprise logging
                self.logToEnterprise(method, args);
            };
        });

        // Store original methods for internal use
        this.originalConsole = originalMethods;
    },
    
    /**
     * Log to enterprise system
     */
    logToEnterprise(level, args) {
        const logEntry = {
            timestamp: new Date().toISOString(),
            level: level,
            message: args.map(arg => 
                typeof arg === 'object' ? JSON.stringify(arg) : String(arg)
            ).join(' '),
            url: window.location.href,
            userAgent: navigator.userAgent,
            stack: new Error().stack
        };
        
        if (level === 'error') {
            this.errors.push(logEntry);
            this.reportError(logEntry);
        } else if (level === 'warn') {
            this.warnings.push(logEntry);
        }
        
        // Send to server for enterprise analysis
        this.sendToServer(logEntry);
    },
    
    /**
     * Setup global error handler
     */
    setupGlobalErrorHandler() {
        window.addEventListener('error', (event) => {
            // Skip resource errors - handled by setupResourceErrorHandler
            if (event.target && event.target !== window) {
                return;
            }

            const errorInfo = {
                type: 'javascript_error',
                message: event.message,
                filename: event.filename,
                lineno: event.lineno,
                colno: event.colno,
                error: event.error,
                stack: event.error ? event.error.stack : null,
                timestamp: new Date().toISOString(),
                url: window.location.href
            };

            this.errors.push(errorInfo);
            this.reportError(errorInfo);

            // ENTERPRISE: Respect enableBrowserConsole setting
            if (this.enableBrowserConsole) {
                this.nativeConsole.error('🚨 Enterprise Error Detected:', errorInfo);
            }
        });
    },
    
    /**
     * Setup promise rejection handler
     */
    setupPromiseRejectionHandler() {
        window.addEventListener('unhandledrejection', (event) => {
            // Extract message from reason
            let message = 'Unhandled promise rejection';
            if (event.reason) {
                if (typeof event.reason === 'string') {
                    message = event.reason;
                } else if (event.reason.message) {
                    message = event.reason.message;
                } else if (event.reason.toString) {
                    message = event.reason.toString();
                }
            }

            const errorInfo = {
                type: 'unhandled_promise_rejection',
                message: message,
                reason: event.reason,
                promise: event.promise,
                timestamp: new Date().toISOString(),
                url: window.location.href,
                stack: event.reason ? event.reason.stack : null
            };

            this.errors.push(errorInfo);
            this.reportError(errorInfo);

            // ENTERPRISE: Respect enableBrowserConsole setting
            if (this.enableBrowserConsole) {
                this.nativeConsole.error('🚨 Unhandled Promise Rejection:', errorInfo);
            }
        });
    },
    
    /**
     * Setup resource error handler
     */
    setupResourceErrorHandler() {
        window.addEventListener('error', (event) => {
            if (event.target !== window) {
                const element = event.target.tagName?.toLowerCase() || 'unknown';
                const source = event.target.src || event.target.href || 'unknown';

                const errorInfo = {
                    type: 'resource_error',
                    message: `Failed to load ${element}: ${source}`,
                    element: event.target.tagName,
                    source: source,
                    timestamp: new Date().toISOString(),
                    url: window.location.href
                };

                this.errors.push(errorInfo);
                this.reportError(errorInfo);

                // ENTERPRISE: Respect enableBrowserConsole setting
                if (this.enableBrowserConsole) {
                    this.nativeConsole.error('🚨 Resource Load Error:', errorInfo);
                }
            }
        }, true);
    },
    
    /**
     * Initialize performance monitoring
     */
    initPerformanceMonitoring() {
        if ('performance' in window) {
            this.performanceMetrics.navigationStart = performance.timing.navigationStart;
            this.performanceMetrics.loadStart = performance.timing.loadEventStart;
            
            // Monitor page load performance
            window.addEventListener('load', () => {
                setTimeout(() => {
                    const perfData = performance.timing;

                    // ENTERPRISE FIX: Validate timing data to prevent negative values
                    // When page is cached, some timing values may be 0 or undefined
                    const calculateTiming = (start, end) => {
                        if (!start || !end || start === 0 || end === 0) return 0;
                        const result = end - start;
                        return result >= 0 ? result : 0;
                    };

                    const metrics = {
                        pageLoadTime: calculateTiming(perfData.navigationStart, perfData.loadEventEnd),
                        domReadyTime: calculateTiming(perfData.navigationStart, perfData.domContentLoadedEventEnd),
                        firstByteTime: calculateTiming(perfData.navigationStart, perfData.responseStart),
                        dnsLookupTime: calculateTiming(perfData.domainLookupStart, perfData.domainLookupEnd),
                        connectTime: calculateTiming(perfData.connectStart, perfData.connectEnd),
                        serverResponseTime: calculateTiming(perfData.requestStart, perfData.responseEnd)
                    };

                    this.performanceMetrics = { ...this.performanceMetrics, ...metrics };

                    this.internalLog('📊 Enterprise Performance Metrics:', metrics);

                    // Alert if performance is poor (respect browser console setting)
                    if (metrics.pageLoadTime > 3000 && this.enableBrowserConsole) {
                        this.nativeConsole.warn('⚠️  Slow page load detected:', metrics.pageLoadTime + 'ms');
                    }

                    this.sendPerformanceMetrics(metrics);
                }, 100);
            });
        }
    },
    
    /**
     * Initialize memory monitoring
     */
    initMemoryMonitoring() {
        if ('memory' in performance) {
            setInterval(() => {
                const memory = performance.memory;
                const memoryInfo = {
                    usedJSHeapSize: memory.usedJSHeapSize,
                    totalJSHeapSize: memory.totalJSHeapSize,
                    jsHeapSizeLimit: memory.jsHeapSizeLimit,
                    timestamp: new Date().toISOString()
                };
                
                // Warning if memory usage is high (respect browser console setting)
                const memoryUsagePercent = (memory.usedJSHeapSize / memory.jsHeapSizeLimit) * 100;
                if (memoryUsagePercent > 80 && this.enableBrowserConsole) {
                    this.nativeConsole.warn('⚠️  High memory usage detected:', memoryUsagePercent.toFixed(2) + '%');
                }

                this.performanceMetrics.memory = memoryInfo;
            }, 30000); // Check every 30 seconds
        }
    },
    
    /**
     * Report error to enterprise system
     */
    reportError(errorInfo) {
        // Create visual error indicator for development
        if (window.Need2Talk && Need2Talk.debug) {
            this.createErrorNotification(errorInfo);
        }
        
        // Send to enterprise logging endpoint
        this.sendToServer({
            type: 'error_report',
            data: errorInfo,
            context: this.getContextInfo()
        });
    },
    
    /**
     * Get context information
     */
    getContextInfo() {
        return {
            url: window.location.href,
            userAgent: navigator.userAgent,
            viewport: {
                width: window.innerWidth,
                height: window.innerHeight
            },
            localStorage: this.getLocalStorageInfo(),
            sessionStorage: this.getSessionStorageInfo(),
            cookies: document.cookie,
            timestamp: new Date().toISOString(),
            // ENTERPRISE SECURITY: Use UUID instead of numeric ID (prevent user enumeration)
            userUuid: window.Need2Talk && Need2Talk.userUuid || null
        };
    },
    
    /**
     * Get localStorage info safely
     */
    getLocalStorageInfo() {
        try {
            const storage = {};
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                storage[key] = localStorage.getItem(key);
            }
            return storage;
        } catch (e) {
            return { error: 'localStorage access denied' };
        }
    },
    
    /**
     * Get sessionStorage info safely
     */
    getSessionStorageInfo() {
        try {
            const storage = {};
            for (let i = 0; i < sessionStorage.length; i++) {
                const key = sessionStorage.key(i);
                storage[key] = sessionStorage.getItem(key);
            }
            return storage;
        } catch (e) {
            return { error: 'sessionStorage access denied' };
        }
    },
    
    /**
     * Send data to server (UNIFIED ARCHITECTURE)
     */
    sendToServer(data) {
        // ENTERPRISE: Send to both endpoints for complete coverage
        
        // 1. Send to existing LogController for general logging
        this.sendToGeneralLogging(data);
        
        // 2. Send to EnterpriseLogging for critical analysis
        this.sendToEnterpriseLogging(data);
    },
    
    /**
     * Send to existing general logging system
     * ENTERPRISE: Uses debouncing to prevent flooding during fast refresh
     */
    sendToGeneralLogging(data) {
        // CRITICAL: Don't send if page is being unloaded (prevents CORS errors)
        if (this.isPageUnloading) {
            return;
        }

        // Format for existing LogController
        const generalLog = {
            logs: [{
                level: data.type === 'error_report' ? 'error' : 'info',
                message: data.data?.message || JSON.stringify(data),
                context: data.data || {},
                timestamp: data.data?.timestamp || new Date().toISOString(),
                url: data.context?.url,
                user_agent: data.context?.userAgent,
                viewport: data.context?.viewport
            }],
            client_info: {
                session_id: window.Need2Talk?.sessionId || null,
                // ENTERPRISE SECURITY: Use UUID instead of numeric ID
                user_uuid: window.Need2Talk?.userUuid || null
            }
        };

        // ENTERPRISE: Add to queue and debounce sends (batch multiple logs)
        this.sendQueue.push(generalLog);

        // Clear existing timer
        if (this.sendTimer) {
            clearTimeout(this.sendTimer);
        }

        // Debounce: Send after 500ms of inactivity or when queue reaches 5 items
        if (this.sendQueue.length >= 5) {
            this.flushQueue();
        } else {
            this.sendTimer = setTimeout(() => this.flushQueue(), 500);
        }
    },

    /**
     * Flush queued logs to server
     * ENTERPRISE: Batch sends to reduce network requests
     */
    flushQueue() {
        if (this.sendQueue.length === 0 || this.isPageUnloading) {
            return;
        }

        // Take all queued logs
        const queuedLogs = [...this.sendQueue];
        this.sendQueue = [];

        // Merge all logs into single request
        const mergedLogs = {
            logs: queuedLogs.flatMap(log => log.logs),
            client_info: queuedLogs[0]?.client_info || {}
        };

        this.fetchWithRetry('/api/logs/client', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(mergedLogs)
        }).catch(err => {
            // Silently fail if page is unloading (expected behavior)
            if (!this.isPageUnloading && this.enableBrowserConsole) {
                // Use original console to avoid infinite loop (respect browser console setting)
                if (this.originalConsole && this.originalConsole.warn) {
                    this.originalConsole.warn('General logging failed:', err);
                }
            }
        });
    },
    
    /**
     * Send to enterprise logging for critical analysis
     */
    sendToEnterpriseLogging(data) {
        // CRITICAL: Don't send if page is being unloaded (prevents CORS errors)
        if (this.isPageUnloading) {
            return;
        }

        // Only send critical data to enterprise endpoint
        if (data.type === 'error_report' || data.type === 'performance_metrics') {
            this.fetchWithRetry('/api/enterprise-logging', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Enterprise-Monitor': 'true'
                },
                body: JSON.stringify(data)
            }).catch(err => {
                // Fallback: store in localStorage for later sync (only if not unloading)
                if (!this.isPageUnloading) {
                    this.storeForLaterSync(data);
                }
            });
        }
    },
    
    /**
     * Fetch with retry logic
     * ENTERPRISE: Automatically includes CSRF token for security
     * ENTERPRISE: Uses AbortController to track and cancel pending requests
     */
    async fetchWithRetry(url, options, retries = 3) {
        // CRITICAL: Don't even try if page is unloading
        if (this.isPageUnloading) {
            return Promise.reject(new Error('Page unloading, fetch cancelled'));
        }

        // ENTERPRISE: Add CSRF token if available
        if (!options.headers) {
            options.headers = {};
        }

        // Get CSRF token from meta tag or CSRF manager
        const csrfToken = window.Need2Talk?.CSRF?.token ||
                         document.querySelector('meta[name="csrf-token"]')?.content;

        if (csrfToken && !options.headers['X-CSRF-TOKEN']) {
            options.headers['X-CSRF-TOKEN'] = csrfToken;
        }

        // ENTERPRISE: Create AbortController to track this request
        const controller = new AbortController();
        this.pendingRequests.add(controller);
        options.signal = controller.signal;

        try {
            for (let i = 0; i < retries; i++) {
                try {
                    // Check again before each attempt
                    if (this.isPageUnloading) {
                        controller.abort();
                        throw new Error('Page unloading, fetch cancelled');
                    }

                    const response = await fetch(url, options);

                    // Success - remove from pending requests
                    this.pendingRequests.delete(controller);

                    if (response.ok) {
                        return response;
                    }
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);

                } catch (error) {
                    // Check if abort was intentional (page unload)
                    if (error.name === 'AbortError' || this.isPageUnloading) {
                        // Silent fail during page unload (expected behavior)
                        this.pendingRequests.delete(controller);
                        throw new Error('Request aborted due to page unload');
                    }

                    // CORS errors during fast refresh - don't retry immediately
                    if (error.message && error.message.includes('Load failed')) {
                        // Wait longer before retry (CORS preflight may need time)
                        if (i < retries - 1) {
                            await new Promise(resolve => setTimeout(resolve, 1500 * Math.pow(2, i)));
                            continue;
                        }
                    }

                    // Last attempt or unrecoverable error
                    if (i === retries - 1) {
                        this.pendingRequests.delete(controller);
                        throw error;
                    }

                    // Standard exponential backoff for other errors
                    await new Promise(resolve => setTimeout(resolve, 1000 * Math.pow(2, i)));
                }
            }
        } catch (error) {
            // Clean up
            this.pendingRequests.delete(controller);
            throw error;
        }
    },
    
    /**
     * Store data for later sync when server is unavailable
     */
    storeForLaterSync(data) {
        try {
            const stored = JSON.parse(localStorage.getItem('enterprise_error_queue') || '[]');
            stored.push(data);
            localStorage.setItem('enterprise_error_queue', JSON.stringify(stored));
        } catch (e) {
            // ENTERPRISE: Respect enableBrowserConsole setting
            if (this.enableBrowserConsole) {
                this.nativeConsole.warn('Could not store error data for later sync');
            }
        }
    },
    
    /**
     * Send performance metrics
     */
    sendPerformanceMetrics(metrics) {
        this.sendToServer({
            type: 'performance_metrics',
            data: metrics,
            context: this.getContextInfo()
        });
    },
    
    /**
     * Create visual error notification (development only)
     */
    createErrorNotification(errorInfo) {
        if (document.querySelector('#enterprise-error-notification')) return;
        
        const notification = document.createElement('div');
        notification.id = 'enterprise-error-notification';
        notification.innerHTML = `
            <div style="
                position: fixed;
                top: 10px;
                right: 10px;
                background: #ff4444;
                color: white;
                padding: 10px 15px;
                border-radius: 5px;
                z-index: 10000;
                font-family: monospace;
                font-size: 12px;
                max-width: 300px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            ">
                <strong>🚨 Enterprise Error Detected</strong><br>
                <small>${errorInfo.type}: ${errorInfo.message || errorInfo.reason}</small>
                <div style="margin-top: 5px;">
                    <button onclick="this.parentElement.parentElement.remove()" style="
                        background: rgba(255,255,255,0.2);
                        border: none;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 3px;
                        cursor: pointer;
                        font-size: 10px;
                    ">Close</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 10 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 10000);
    },
    
    /**
     * Get error summary for enterprise dashboard
     */
    getErrorSummary() {
        return {
            totalErrors: this.errors.length,
            totalWarnings: this.warnings.length,
            recentErrors: this.errors.slice(-10),
            recentWarnings: this.warnings.slice(-10),
            performanceMetrics: this.performanceMetrics,
            browserInfo: {
                userAgent: navigator.userAgent,
                language: navigator.language,
                platform: navigator.platform,
                cookieEnabled: navigator.cookieEnabled,
                onLine: navigator.onLine
            }
        };
    },
    
    /**
     * Clear error logs
     */
    clearLogs() {
        this.errors = [];
        this.warnings = [];
        this.internalLog('✅ Enterprise error logs cleared');
    }
};

// Global access for debugging
window.EnterpriseErrorMonitor = Need2Talk.EnterpriseErrorMonitor;

// PERFORMANCE: Hybrid initialization for zero performance impact
// 1. Fast init (SYNCHRONOUS): Intercept console immediately (~1-2ms)
// 2. Deferred init (ASYNC): Setup error handlers, monitoring, etc. (~20-30ms but non-blocking)
if (Need2Talk && Need2Talk.EnterpriseErrorMonitor && !Need2Talk.EnterpriseErrorMonitor.isInitialized) {
    // Phase 1: Fast console interception (CRITICAL - must be sync)
    Need2Talk.EnterpriseErrorMonitor.initFast();

    // Phase 2: Heavy operations deferred to next tick (NON-BLOCKING)
    // Save reference to avoid scope issues in setTimeout
    const monitor = Need2Talk.EnterpriseErrorMonitor;
    setTimeout(() => {
        if (monitor && monitor.initDeferred) {
            monitor.initDeferred();
        }
    }, 0);
}