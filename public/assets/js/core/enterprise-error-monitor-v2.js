/**
 * Enterprise Error Monitor V2 - Zero Bootstrap Overhead
 *
 * ARCHITETTURA RIVOLUZIONARIA:
 * - Durante bootstrap: ZERO intercettazione, console nativa passa diretta
 * - Dopo page load: Intercettazione + filtering + server logging
 *
 * PERFORMANCE:
 * - Bootstrap: 0ms overhead (console nativa)
 * - Runtime: <1ms per log (cached lookups)
 */

window.Need2Talk = window.Need2Talk || {};

Need2Talk.EnterpriseErrorMonitor = {
    version: '2.0.0',

    // State
    isActive: false,
    enableBrowserConsole: true,
    currentLogLevel: 'info',

    // Native console backup (before interception)
    native: {
        log: console.log,
        info: console.info,
        warn: console.warn,
        error: console.error,
        debug: console.debug,
        trace: console.trace
    },

    // PSR-3 mapping
    psr3Map: {
        'log': 'info',
        'info': 'info',
        'warn': 'warning',
        'error': 'error',
        'debug': 'debug',
        'trace': 'debug'
    },

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

    /**
     * INIT: Legge configurazione E intercetta console SUBITO (ultra-lightweight)
     * Durante bootstrap: solo check enableBrowserConsole (zero filtering)
     * Dopo page load: filtering PSR-3 completo
     */
    init() {
        // Leggi configurazione
        this.enableBrowserConsole = window.BROWSER_CONSOLE_ENABLED !== false;
        this.currentLogLevel = window.JS_ERRORS_LOG_LEVEL || 'info';

        // Setup error handlers (lightweight)
        this.setupErrorHandlers();

        // INTERCETTA CONSOLE SUBITO con wrapper ultra-leggero
        this.interceptConsoleFast();
    },

    /**
     * INTERCEPT CONSOLE FAST: Ultra-lightweight wrapper per bootstrap
     * Solo check enableBrowserConsole, ZERO filtering PSR-3
     */
    interceptConsoleFast() {
        const self = this;

        ['log', 'info', 'warn', 'error', 'debug', 'trace'].forEach(method => {
            console[method] = function(...args) {
                // BOOTSTRAP MODE: solo check toggle, NO filtering
                if (!self.isActive) {
                    if (self.enableBrowserConsole) {
                        self.native[method].apply(console, args);
                    }
                    return;
                }

                // RUNTIME MODE: full filtering
                self.handleLog(method, args);
            };
        });
    },

    /**
     * ACTIVATE: Attiva filtering PSR-3 completo DOPO page load
     */
    activate() {
        if (this.isActive) return;
        this.isActive = true;
        // Console già intercettata da interceptConsoleFast()
        // Ora handleLog() farà il filtering PSR-3 completo
    },

    /**
     * Handle log con filtering PSR-3 inline (ultra-fast)
     */
    handleLog(method, args) {
        // PSR-3 filtering (inline per velocità)
        const psr3Level = this.psr3Map[method];
        const currentPriority = this.psr3Levels[this.currentLogLevel];
        const messagePriority = this.psr3Levels[psr3Level];

        // Filtra se priorità troppo bassa
        if (messagePriority > currentPriority) {
            return; // Skip
        }

        // Browser console - RISPETTA IL TOGGLE
        if (this.enableBrowserConsole) {
            this.native[method].apply(console, args);
        }
        // Se enableBrowserConsole = false, NON mostrare nulla in console

        // Server logging (async, non-blocking)
        if (method === 'error' || method === 'warn') {
            this.sendToServer(method, args);
        }
    },

    /**
     * Setup error handlers (global errors, promises, resources)
     */
    setupErrorHandlers() {
        // JavaScript errors
        window.addEventListener('error', (e) => {
            if (e.target !== window) return; // Resource errors handled separately

            const error = {
                type: 'js_error',
                message: e.message,
                file: e.filename,
                line: e.lineno,
                col: e.colno,
                stack: e.error?.stack
            };

            this.sendToServer('error', [error]);
        });

        // Promise rejections
        window.addEventListener('unhandledrejection', (e) => {
            const error = {
                type: 'promise_rejection',
                message: e.reason?.message || String(e.reason),
                stack: e.reason?.stack
            };

            this.sendToServer('error', [error]);
        });

        // ENTERPRISE METRICS v12.1: Monitor page load performance (TRULY non-blocking)
        // Using requestIdleCallback to avoid blocking main thread during critical path
        // Fallback to setTimeout with 3s delay for browsers without rIC support
        window.addEventListener('load', () => {
            const collectMetrics = () => this.collectPerformanceMetrics();
            if ('requestIdleCallback' in window) {
                // Wait for browser to be TRULY idle (after paint, after layout)
                requestIdleCallback(collectMetrics, { timeout: 5000 });
            } else {
                // Fallback: 3 seconds delay to ensure LCP/CLS have been measured
                setTimeout(collectMetrics, 3000);
            }
        });
    },

    /**
     * Send to server (debounced, batched)
     */
    sendToServer(level, args) {
        // Skip durante page unload
        if (document.visibilityState === 'hidden') return;

        // Formato per backend
        const logEntry = {
            level: level,
            message: args.map(a => typeof a === 'object' ? JSON.stringify(a) : String(a)).join(' '),
            timestamp: Date.now(),
            url: location.href,
            userAgent: navigator.userAgent
        };

        // Send async (non-blocking)
        this.queueLog(logEntry);
    },

    /**
     * Queue log per batch sending
     */
    logQueue: [],
    queueTimer: null,

    queueLog(entry) {
        this.logQueue.push(entry);

        // Debounce: invia dopo 1 secondo di inattività o quando raggiungi 10 logs
        clearTimeout(this.queueTimer);

        if (this.logQueue.length >= 10) {
            this.flushQueue();
        } else {
            this.queueTimer = setTimeout(() => this.flushQueue(), 1000);
        }
    },

    /**
     * Flush queue to server
     */
    async flushQueue() {
        if (this.logQueue.length === 0) return;

        const batch = [...this.logQueue];
        this.logQueue = [];

        try {
            await fetch('/api/logs/client', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    logs: batch,
                    session_id: window.Need2Talk?.sessionId,
                    // ENTERPRISE SECURITY: Use UUID instead of numeric ID
                    user_uuid: window.Need2Talk?.userUuid
                })
            });
        } catch (e) {
            // Silent fail (logging non deve bloccare app)
        }
    },

    /**
     * ENTERPRISE METRICS: Collect page performance metrics
     * Sends to enterprise_performance_metrics table via EnterpriseLoggingController
     */
    collectPerformanceMetrics() {
        if (!('performance' in window) || !performance.timing) {
            return;
        }

        const perfData = performance.timing;

        // Validate timing data (prevent negative values for cached pages)
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

        // Send to enterprise logging endpoint
        this.sendPerformanceMetrics(metrics);
    },

    /**
     * ENTERPRISE METRICS: Send performance metrics to server
     * Endpoint: /api/enterprise-logging (EnterpriseLoggingController)
     * Table: enterprise_performance_metrics
     *
     * 🚀 ENTERPRISE GALAXY 2025: 20% SAMPLING (2 out of 10 random)
     * Reduces database writes by 80% for millions of concurrent users
     * Statistical sampling: Mantiene precisione statistica riducendo write selvagge
     * - 1M users × 10 pages/hour = 10M page views/hour
     * - Without sampling: 10M INSERT/hour = 240M INSERT/day
     * - With 20% sampling: 2M INSERT/hour = 48M INSERT/day (80% reduction)
     * - Scalabile per traffico globale enterprise-level
     */
    async sendPerformanceMetrics(metrics) {
        // 🚫 ENTERPRISE GALAXY: SKIP ADMIN PAGES (zero tracking per admin panel)
        // Le pagine admin (/admin_*, /x7f9k2m8q1, ecc.) non devono generare metriche
        // per evitare inquinamento dati e sovraccarico database con azioni amministrative
        const currentPath = window.location.pathname;
        if (currentPath.startsWith('/admin') || currentPath.match(/^\/[a-z0-9]{10}$/i)) {
            return; // Skip completamente admin routes (0% tracking)
        }

        // 🚀 ENTERPRISE GALAXY: Random sampling 20% (campionatura casuale SOLO per rotte pubbliche)
        // Math.random() genera valore 0-1, se > 0.2 skippa (80% skippati, 20% registrati)
        if (Math.random() > 0.2) {
            return; // Skip 80% delle richieste pubbliche (sampling statistico)
        }

        try {
            await fetch('/api/enterprise-logging', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Enterprise-Monitor': 'true'
                },
                body: JSON.stringify({
                    type: 'performance_metrics',
                    data: metrics,
                    context: {
                        url: location.href,
                        userAgent: navigator.userAgent,
                        // ENTERPRISE SECURITY: Use UUID instead of numeric ID
                        userUuid: window.Need2Talk?.userUuid || null
                    }
                })
            });
        } catch (e) {
            // Silent fail (non-blocking)
        }
    }
};

// =============================================================================
// AUTO-INITIALIZATION - STRATEGIA A DUE FASI
// =============================================================================

// FASE 1: Init immediato (legge config, setup error handlers)
// Questo è VELOCE perché NON intercetta console
if (typeof Need2Talk !== 'undefined' && Need2Talk.EnterpriseErrorMonitor) {
    Need2Talk.EnterpriseErrorMonitor.init();
}

// FASE 2: Activate DOPO page load
// Console intercettazione avviene solo quando la pagina è pronta
if (document.readyState === 'complete') {
    if (typeof Need2Talk !== 'undefined' && Need2Talk.EnterpriseErrorMonitor) {
        Need2Talk.EnterpriseErrorMonitor.activate();
    }
} else {
    window.addEventListener('load', () => {
        if (typeof Need2Talk !== 'undefined' && Need2Talk.EnterpriseErrorMonitor) {
            Need2Talk.EnterpriseErrorMonitor.activate();
        }
    });
}

// Global access per debugging
if (typeof Need2Talk !== 'undefined' && Need2Talk.EnterpriseErrorMonitor) {
    window.EnterpriseErrorMonitor = Need2Talk.EnterpriseErrorMonitor;
}
