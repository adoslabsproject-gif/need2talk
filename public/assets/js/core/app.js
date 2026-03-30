/**
 * need2talk - Core App JavaScript
 * Sistema principale scalabile per migliaia di utenti
 */

// CRITICAL: Preserve existing properties (e.g. encryptionService)
// Object.assign(defaults, existing) → existing properties WIN ✅
window.Need2Talk = Object.assign(
    // 1st argument: defaults (will be overwritten if existing properties exist)
    {
        version: '1.0.0',
        debug: false,
        userId: null,

        // Performance Monitoring
        perf: {
            startTime: performance.now(),
            marks: new Map(),
            measures: new Map()
        },

        // Memory Management
        cache: new Map(),
        maxCacheSize: 100,

        // Event System - Scalabile
        events: new EventTarget(),

        // Modules Registry
        modules: new Map(),

        // Configuration
        config: {
            maxRetries: 3,
            timeout: 10000,
            batchSize: 50,
            debounceDelay: 300,
            throttleDelay: 100,
            logLevel: null // Auto-detect from environment (set window.VERBOSE_LOGGING=true for debug)
        }
    },
    // 2nd argument: existing properties (these WIN and preserve encryptionService)
    window.Need2Talk || {}
);

/**
 * Performance Utilities - Per scalabilità
 */
Need2Talk.perf.mark = function(name) {
    performance.mark(name);
    this.marks.set(name, performance.now());
};

Need2Talk.perf.measure = function(name, start, end) {
    performance.measure(name, start, end);
    const measure = performance.getEntriesByName(name, 'measure')[0];
    this.measures.set(name, measure.duration);

    // ENTERPRISE: Log via centralized system (respects browser console settings)
    if (Need2Talk.debug && Need2Talk.Logger) {
        Need2Talk.Logger.debug('Performance', `⏱️ ${name}: ${measure.duration.toFixed(2)}ms`);
    }
};

/**
 * ================================================================================
 * CENTRALIZED LOGGING SYSTEM - need2talk
 * ================================================================================
 * Sistema di logging scalabile per tutti i moduli JS
 */
Need2Talk.Logger = {
    // Log levels
    levels: {
        DEBUG: 0,
        INFO: 1,
        WARN: 2,
        ERROR: 3
    },

    // Current log level
    currentLevel: 0, // DEBUG by default

    // Log storage for batch sending
    logBuffer: [],
    maxBufferSize: 100,

    // Initialize logger
    init(level = null) {
        // GALAXY LEVEL: Auto-detect log level from environment
        if (!level) {
            const env = window.APP_ENV || 'production';
            const debug = window.APP_DEBUG || false;

            // GALAXY LEVEL: Default to INFO for performance (shows only important logs)
            // Set window.VERBOSE_LOGGING=true for DEBUG level if needed
            if (window.VERBOSE_LOGGING) {
                level = 'debug'; // All logs (slow but useful for debugging)
            } else if (env === 'production' && !debug) {
                level = 'warn';  // Production: only warnings/errors
            } else {
                level = 'info';  // Development: important logs only (FAST!)
            }
        }

        this.setLevel(level);
        this.setupBatchSending();

        // Only log init message if level allows (not in production/warn)
        if (this.currentLevel <= this.levels.INFO) {
            this.log('info', 'Logger', '📊 Centralized logging system initialized');
        }
    },

    // Set log level
    setLevel(level) {
        const levelMap = {
            debug: this.levels.DEBUG,
            info: this.levels.INFO,
            warn: this.levels.WARN,
            error: this.levels.ERROR
        };
        this.currentLevel = levelMap[level.toLowerCase()] || this.levels.DEBUG;
        Need2Talk.config.logLevel = level;
    },

    // Core logging method
    log(level, module, message, data = null) {
        const levelNum = this.levels[level.toUpperCase()];
        if (levelNum < this.currentLevel) return;

        const timestamp = new Date().toISOString();
        const logEntry = {
            timestamp,
            level: level.toUpperCase(),
            module,
            message,
            data,
            url: window.location.href,
            userAgent: navigator.userAgent
        };

        // Console output with emojis
        const emoji = this.getEmoji(level);
        const consoleMethod = this.getConsoleMethod(level);
        
        if (data) {
            consoleMethod(`${emoji} [${module}] ${message}`, data);
        } else {
            consoleMethod(`${emoji} [${module}] ${message}`);
        }

        // Add to buffer for batch sending
        this.addToBuffer(logEntry);
    },

    // Convenience methods
    debug(module, message, data) {
        this.log('debug', module, message, data);
    },

    info(module, message, data) {
        this.log('info', module, message, data);
    },

    warn(module, message, data) {
        this.log('warn', module, message, data);
    },

    error(module, message, data) {
        this.log('error', module, message, data);
    },

    // Performance logging
    perf(module, operation, duration) {
        this.info(module, `⏱️ ${operation} completed in ${duration.toFixed(2)}ms`);
    },

    // Security logging - for security events and audit trails
    security(module, message, data) {
        this.log('warn', module, `🔒 ${message}`, data);
    },

    // Get emoji for log level
    getEmoji(level) {
        const emojis = {
            debug: '🔍',
            info: '📋',
            warn: '⚠️',
            error: '❌'
        };
        return emojis[level.toLowerCase()] || '📝';
    },

    // Get console method for log level
    getConsoleMethod(level) {
        const methods = {
            debug: console.debug,
            info: console.info,
            warn: console.warn,
            error: console.error
        };
        return methods[level.toLowerCase()] || console.log;
    },

    // Add to buffer for batch sending
    addToBuffer(logEntry) {
        this.logBuffer.push(logEntry);
        
        // Keep buffer size manageable
        if (this.logBuffer.length > this.maxBufferSize) {
            this.logBuffer.shift(); // Remove oldest entry
        }

        // Send immediately for errors
        if (logEntry.level === 'ERROR') {
            this.sendLogs([logEntry]);
        }
    },

    // Setup batch log sending
    setupBatchSending() {
        // Send logs every 30 seconds if there are any
        setInterval(() => {
            if (this.logBuffer.length > 0) {
                this.flushLogs();
            }
        }, 30000);

        // Send logs on page unload
        window.addEventListener('beforeunload', () => {
            this.flushLogs(true);
        });
    },

    // Flush logs to server
    flushLogs(sync = false) {
        if (this.logBuffer.length === 0) return;

        const logsToSend = [...this.logBuffer];
        this.logBuffer = [];

        this.sendLogs(logsToSend, sync);
    },

    // Send logs to server
    async sendLogs(logs, sync = false) {
        try {
            const payload = {
                logs: logs,
                // SECURITY: Use hashed identifiers (set in enterprise-monitoring.php)
                session_id: Need2Talk._sid || null,
                user_id: Need2Talk._uid || null
            };

            if (sync && navigator.sendBeacon) {
                // Use sendBeacon for synchronous sending on page unload
                navigator.sendBeacon('/api/logs/client', JSON.stringify(payload));
            } else {
                // Regular fetch for async sending
                await fetch('/api/logs/client', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                });
            }
        } catch (error) {
            console.warn('Failed to send logs to server:', error);
        }
    }
};

/**
 * Memory Management - Critico per scalabilità
 */
Need2Talk.cache.set = function(key, value) {
    // Auto-cleanup when cache gets too large
    if (this.size >= Need2Talk.maxCacheSize) {
        const firstKey = this.keys().next().value;
        this.delete(firstKey);
    }
    
    Map.prototype.set.call(this, key, {
        value: value,
        timestamp: Date.now()
    });
};

Need2Talk.cache.get = function(key, maxAge = 300000) { // 5 minutes default
    const item = Map.prototype.get.call(this, key);
    if (!item) return null;
    
    if (Date.now() - item.timestamp > maxAge) {
        this.delete(key);
        return null;
    }
    
    return item.value;
};

/**
 * Debounce - Performance critical per high-volume
 */
Need2Talk.debounce = function(func, delay = Need2Talk.config.debounceDelay) {
    let timeoutId;
    return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
    };
};

/**
 * Throttle - Per eventi ad alta frequenza
 */
Need2Talk.throttle = function(func, delay = Need2Talk.config.throttleDelay) {
    let lastCall = 0;
    return function (...args) {
        const now = Date.now();
        if (now - lastCall >= delay) {
            lastCall = now;
            return func.apply(this, args);
        }
    };
};

/**
 * Batch Processing - Per operazioni su larga scala
 */
Need2Talk.batch = function(items, processor, batchSize = Need2Talk.config.batchSize) {
    return new Promise((resolve) => {
        let index = 0;
        const results = [];
        
        function processBatch() {
            const batch = items.slice(index, index + batchSize);
            if (batch.length === 0) {
                resolve(results);
                return;
            }
            
            batch.forEach(item => {
                results.push(processor(item));
            });
            
            index += batchSize;
            
            // Non-blocking batch processing
            requestIdleCallback(() => processBatch());
        }
        
        processBatch();
    });
};

/**
 * Virtual Scrolling Support
 */
Need2Talk.VirtualList = class {
    constructor(container, itemHeight, renderItem) {
        this.container = container;
        this.itemHeight = itemHeight;
        this.renderItem = renderItem;
        this.items = [];
        this.visibleItems = new Map();
        this.startIndex = 0;
        this.endIndex = 0;
        
        this.init();
    }
    
    init() {
        this.container.addEventListener('scroll', 
            Need2Talk.throttle(() => this.updateVisible(), 16)
        );
        
        // Initial render
        this.updateVisible();
    }
    
    setItems(items) {
        this.items = items;
        this.container.style.height = `${items.length * this.itemHeight}px`;
        this.updateVisible();
    }
    
    updateVisible() {
        const scrollTop = this.container.scrollTop;
        const containerHeight = this.container.clientHeight;
        
        this.startIndex = Math.floor(scrollTop / this.itemHeight);
        this.endIndex = Math.min(
            this.items.length - 1,
            Math.ceil((scrollTop + containerHeight) / this.itemHeight)
        );
        
        // Remove items no longer visible
        for (const [index, element] of this.visibleItems) {
            if (index < this.startIndex || index > this.endIndex) {
                element.remove();
                this.visibleItems.delete(index);
            }
        }
        
        // Add newly visible items
        for (let i = this.startIndex; i <= this.endIndex; i++) {
            if (!this.visibleItems.has(i)) {
                const element = this.renderItem(this.items[i], i);
                element.style.position = 'absolute';
                element.style.top = `${i * this.itemHeight}px`;
                element.style.height = `${this.itemHeight}px`;
                
                this.container.appendChild(element);
                this.visibleItems.set(i, element);
            }
        }
    }
};

/**
 * WebWorker Management - Per operazioni intensive
 */
Need2Talk.Workers = {
    pool: [],
    maxWorkers: navigator.hardwareConcurrency || 4,
    
    getWorker(scriptPath) {
        const worker = this.pool.find(w => !w.busy);
        if (worker) {
            worker.busy = true;
            return worker;
        }
        
        if (this.pool.length < this.maxWorkers) {
            const newWorker = new Worker(scriptPath);
            newWorker.busy = true;
            this.pool.push(newWorker);
            return newWorker;
        }
        
        return null; // All workers busy
    },
    
    releaseWorker(worker) {
        worker.busy = false;
    }
};

/**
 * Network Utilities - Per high-volume requests
 */
Need2Talk.Network = {
    requestQueue: [],
    activeRequests: 0,
    maxConcurrentRequests: 6,
    
    async fetch(url, options = {}) {
        return new Promise((resolve, reject) => {
            this.requestQueue.push({ url, options, resolve, reject });
            this.processQueue();
        });
    },
    
    async processQueue() {
        if (this.activeRequests >= this.maxConcurrentRequests || this.requestQueue.length === 0) {
            return;
        }
        
        const { url, options, resolve, reject } = this.requestQueue.shift();
        this.activeRequests++;
        
        try {
            const response = await fetch(url, {
                ...options,
                signal: AbortSignal.timeout(Need2Talk.config.timeout)
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            resolve(response);
        } catch (error) {
            reject(error);
        } finally {
            this.activeRequests--;
            this.processQueue(); // Process next in queue
        }
    }
};

/**
 * Module System - Per code splitting
 */
Need2Talk.loadModule = async function(moduleName) {
    if (this.modules.has(moduleName)) {
        return this.modules.get(moduleName);
    }
    
    try {
        const module = await import(`/assets/js/modules/${moduleName}.js`);
        this.modules.set(moduleName, module);
        return module;
    } catch (error) {
        console.error(`Failed to load module ${moduleName}:`, error);
        return null;
    }
};

/**
 * Error Handling - Per stabilità su larga scala
 */
Need2Talk.handleError = function(error, context = 'Unknown') {
    console.error(`[${context}] Error:`, error);
    
    // Send error to monitoring service
    if (typeof gtag === 'function') {
        gtag('event', 'exception', {
            description: `${context}: ${error.message}`,
            fatal: false
        });
    }
    
    // Show user-friendly message
    this.showNotification('Si è verificato un errore. Riprova.', 'error');
};

/**
 * Notification System - High-performance
 */
Need2Talk.showNotification = function(message, type = 'info', duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type} animate-slide-in-right`;
    notification.innerHTML = `
        <div class="flex items-center justify-between p-4 rounded-lg shadow-lg">
            <span class="text-white">${message}</span>
            <button class="ml-4 text-white hover:text-gray-200" onclick="this.parentElement.parentElement.remove()">
                ✕
            </button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove
    setTimeout(() => {
        notification.classList.add('animate-fade-out');
        setTimeout(() => notification.remove(), 300);
    }, duration);
};

/**
 * Initialization - Load critical resources
 */
Need2Talk.init = function() {

    this.perf.mark('app-init-start');

    // Initialize centralized logging first
    this.Logger.init(this.config.logLevel);
    
    // CSRF protection will be initialized by csrf.js via app:ready event
    
    // Set user ID if logged in
    const userIdMeta = document.querySelector('meta[name="user-id"]');
    if (userIdMeta) {
        this.userId = parseInt(userIdMeta.content);
        this.Logger.info('App', `User authenticated: ${this.userId}`);
    }
    
    // Generate session ID if not exists
    if (!this.sessionId) {
        this.sessionId = 'sess_' + Math.random().toString(36).substr(2, 16);
        this.Logger.debug('App', `Session ID generated: ${this.sessionId}`);
    }
    
    // Initialize error handling
    window.addEventListener('error', (event) => {
        this.handleError(event.error, 'Global Error Handler');
    });
    
    window.addEventListener('unhandledrejection', (event) => {
        this.handleError(event.reason, 'Unhandled Promise Rejection');
    });
    
    // Performance monitoring in debug mode
    if (this.debug) {
        this.startPerformanceMonitoring();
    }
    
    this.perf.mark('app-init-end');
    this.perf.measure('app-init', 'app-init-start', 'app-init-end');

    // Mark as initialized - ENTERPRISE SECURITY
    this.initialized = true;

    // Emit ready event
    this.events.dispatchEvent(new CustomEvent('app:ready'));
};

/**
 * Performance Monitoring - Per debugging su larga scala
 */
Need2Talk.startPerformanceMonitoring = function() {
    // Monitor memory usage
    setInterval(() => {
        if (performance.memory) {
            console.log(`Memory: ${(performance.memory.usedJSHeapSize / 1048576).toFixed(2)}MB`);
        }
    }, 30000);
    
    // Monitor long tasks
    if ('PerformanceObserver' in window) {
        const observer = new PerformanceObserver((list) => {
            list.getEntries().forEach((entry) => {
                if (entry.duration > 50) {
                    console.warn(`Long task detected: ${entry.duration.toFixed(2)}ms`);
                }
            });
        });
        observer.observe({ entryTypes: ['longtask'] });
    }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Need2Talk.init());
} else {
    Need2Talk.init();
}