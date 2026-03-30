/**
 * need2talk - Flash Messages Module
 * Sistema di notifiche ottimizzato per alta scalabilità
 */

Need2Talk.FlashMessages = {
    container: null,
    queue: [],
    isProcessing: false,
    maxMessages: 5,
    
    /**
     * Initialize flash message system
     */
    init() {
        this.createContainer();
        this.processServerMessages();
        this.startQueueProcessor();
    },
    
    /**
     * Create messages container
     */
    createContainer() {
        this.container = document.createElement('div');
        this.container.id = 'flash-messages-container';
        this.container.className = 'fixed top-20 right-4 z-50 space-y-2';
        this.container.style.maxWidth = '400px';
        
        document.body.appendChild(this.container);
    },
    
    /**
     * Process server-side flash messages
     */
    processServerMessages() {
        const serverMessages = document.querySelectorAll('.flash-message');
        serverMessages.forEach(msg => {
            const type = msg.dataset.type || 'info';
            const text = msg.textContent.trim();
            const duration = parseInt(msg.dataset.duration) || 5000;
            
            this.show(text, type, duration);
            msg.remove();
        });
    },
    
    /**
     * Show flash message
     */
    show(message, type = 'info', duration = 5000, options = {}) {
        const messageObj = {
            id: Date.now() + Math.random(),
            message,
            type,
            duration,
            options,
            timestamp: Date.now()
        };
        
        this.queue.push(messageObj);
        
        // Limit queue size for memory efficiency
        if (this.queue.length > this.maxMessages) {
            this.queue.shift();
        }
        
        this.processQueue();
    },
    
    /**
     * Process message queue
     */
    processQueue() {
        if (this.isProcessing || this.queue.length === 0) return;
        
        this.isProcessing = true;
        
        requestAnimationFrame(() => {
            const message = this.queue.shift();
            if (message) {
                this.renderMessage(message);
            }
            this.isProcessing = false;
            
            // Process next message if queue not empty
            if (this.queue.length > 0) {
                setTimeout(() => this.processQueue(), 100);
            }
        });
    },
    
    /**
     * Render individual message
     */
    renderMessage(messageObj) {
        const { id, message, type, duration, options } = messageObj;
        
        const element = document.createElement('div');
        element.id = `flash-${id}`;
        element.className = `flash-message-item animate-slide-in-right ${this.getTypeClasses(type)}`;
        
        element.innerHTML = this.getMessageHTML(message, type, options);
        
        // Add to container
        this.container.appendChild(element);
        
        // Auto-remove after duration
        if (duration > 0) {
            setTimeout(() => {
                this.remove(id);
            }, duration);
        }
        
        // Setup close button
        const closeBtn = element.querySelector('.flash-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.remove(id));
        }
        
        // Cleanup old messages
        this.cleanupOldMessages();
    },
    
    /**
     * Get CSS classes for message type
     */
    getTypeClasses(type) {
        const baseClasses = 'mb-2 p-4 rounded-lg shadow-lg backdrop-blur-sm border transition-all duration-300';
        
        switch (type) {
            case 'success':
                return `${baseClasses} bg-green-600/90 border-green-500 text-white`;
            case 'error':
                return `${baseClasses} bg-red-600/90 border-red-500 text-white`;
            case 'warning':
                return `${baseClasses} bg-yellow-600/90 border-yellow-500 text-white`;
            case 'info':
            default:
                return `${baseClasses} bg-blue-600/90 border-blue-500 text-white`;
        }
    },
    
    /**
     * Get message HTML
     */
    getMessageHTML(message, type, options = {}) {
        const icon = this.getIcon(type);
        const showClose = options.showClose !== false;
        
        return `
            <div class="flex items-start justify-between">
                <div class="flex items-start">
                    <div class="flex-shrink-0 mr-3">
                        ${icon}
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium">${message}</p>
                        ${options.details ? `<p class="text-xs mt-1 opacity-90">${options.details}</p>` : ''}
                    </div>
                </div>
                ${showClose ? `
                    <button class="flash-close ml-4 text-white hover:text-gray-200 focus:outline-none focus:text-gray-200 transition-colors duration-200" aria-label="Chiudi">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                        </svg>
                    </button>
                ` : ''}
            </div>
        `;
    },
    
    /**
     * Get icon for message type
     */
    getIcon(type) {
        const iconClasses = 'w-5 h-5';
        
        switch (type) {
            case 'success':
                return `
                    <svg class="${iconClasses}" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                `;
            case 'error':
                return `
                    <svg class="${iconClasses}" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                `;
            case 'warning':
                return `
                    <svg class="${iconClasses}" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                `;
            case 'info':
            default:
                return `
                    <svg class="${iconClasses}" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                    </svg>
                `;
        }
    },
    
    /**
     * Remove message
     */
    remove(id) {
        const element = document.getElementById(`flash-${id}`);
        if (!element) return;
        
        element.classList.add('animate-fade-out');
        
        setTimeout(() => {
            if (element.parentNode) {
                element.remove();
            }
        }, 300);
    },
    
    /**
     * Remove all messages
     */
    clear() {
        const messages = this.container.querySelectorAll('.flash-message-item');
        messages.forEach(msg => {
            msg.classList.add('animate-fade-out');
            setTimeout(() => {
                if (msg.parentNode) {
                    msg.remove();
                }
            }, 300);
        });
        
        this.queue = [];
    },
    
    /**
     * Cleanup old messages for memory efficiency
     */
    cleanupOldMessages() {
        const messages = this.container.querySelectorAll('.flash-message-item');
        
        if (messages.length > this.maxMessages) {
            // Remove oldest messages
            const excessCount = messages.length - this.maxMessages;
            for (let i = 0; i < excessCount; i++) {
                messages[i].classList.add('animate-fade-out');
                setTimeout(() => {
                    if (messages[i].parentNode) {
                        messages[i].remove();
                    }
                }, 300);
            }
        }
    },
    
    /**
     * Start queue processor for high-volume scenarios
     */
    startQueueProcessor() {
        setInterval(() => {
            if (this.queue.length > 0 && !this.isProcessing) {
                this.processQueue();
            }
        }, 50); // Process queue every 50ms for responsiveness
    },
    
    // Convenience methods
    success(message, duration = 5000, options = {}) {
        this.show(message, 'success', duration, options);
    },
    
    error(message, duration = 8000, options = {}) {
        this.show(message, 'error', duration, options);
    },
    
    warning(message, duration = 6000, options = {}) {
        this.show(message, 'warning', duration, options);
    },
    
    info(message, duration = 5000, options = {}) {
        this.show(message, 'info', duration, options);
    }
};

// Initialize when app is ready
Need2Talk.events.addEventListener('app:ready', () => {
    Need2Talk.FlashMessages.init();
});

// Expose global methods for convenience
window.showNotification = (message, type, duration) => 
    Need2Talk.FlashMessages.show(message, type, duration);

window.showSuccess = (message, duration, options) => 
    Need2Talk.FlashMessages.success(message, duration, options);

window.showError = (message, duration, options) => 
    Need2Talk.FlashMessages.error(message, duration, options);

window.showWarning = (message, duration, options) => 
    Need2Talk.FlashMessages.warning(message, duration, options);

window.showInfo = (message, duration, options) => 
    Need2Talk.FlashMessages.info(message, duration, options);