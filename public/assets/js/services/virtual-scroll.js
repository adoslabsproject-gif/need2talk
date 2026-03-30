/**
 * need2talk Virtual Scroll Service
 * Performance-optimized scrolling for large lists
 */

// Global Need2Talk object
window.Need2Talk = window.Need2Talk || {};

/**
 * Virtual Scroll Service for handling large datasets
 */
Need2Talk.VirtualScroll = {
    
    /**
     * Active scroll instances
     */
    instances: new Map(),

    /**
     * Create virtual scroll instance
     */
    create(container, options = {}) {
        if (typeof container === 'string') {
            container = document.querySelector(container);
        }
        
        if (!container) {
            console.error('[Need2Talk] Virtual scroll container not found');
            return null;
        }

        const instance = new VirtualScrollInstance(container, options);
        this.instances.set(container, instance);
        
        return instance;
    },

    /**
     * Destroy virtual scroll instance
     */
    destroy(container) {
        if (typeof container === 'string') {
            container = document.querySelector(container);
        }
        
        const instance = this.instances.get(container);
        if (instance) {
            instance.destroy();
            this.instances.delete(container);
        }
    },

    /**
     * Get instance for container
     */
    getInstance(container) {
        if (typeof container === 'string') {
            container = document.querySelector(container);
        }
        
        return this.instances.get(container);
    }
};

/**
 * Virtual Scroll Instance Class
 */
class VirtualScrollInstance {
    
    constructor(container, options = {}) {
        this.container = container;
        this.options = {
            itemHeight: 100,
            buffer: 5,
            threshold: 0.1,
            debounceMs: 16,
            ...options
        };
        
        // State
        this.items = [];
        this.visibleItems = [];
        this.startIndex = 0;
        this.endIndex = 0;
        this.scrollTop = 0;
        this.containerHeight = 0;
        this.totalHeight = 0;
        
        // DOM elements
        this.viewport = null;
        this.content = null;
        this.spacerTop = null;
        this.spacerBottom = null;
        
        // Callbacks
        this.onScroll = options.onScroll || null;
        this.onLoadMore = options.onLoadMore || null;
        this.renderItem = options.renderItem || this.defaultRenderItem;
        
        this.init();
    }

    /**
     * Initialize virtual scroll
     */
    init() {
        this.setupDOM();
        this.setupEventListeners();
        this.calculateDimensions();

        console.info('[Need2Talk] Virtual scroll initialized');
    }

    /**
     * Setup DOM structure
     */
    setupDOM() {
        // Create viewport
        this.viewport = document.createElement('div');
        this.viewport.className = 'virtual-scroll-viewport';
        this.viewport.style.cssText = `
            height: 100%;
            overflow-y: auto;
            position: relative;
        `;

        // Create content container
        this.content = document.createElement('div');
        this.content.className = 'virtual-scroll-content';
        this.content.style.cssText = `
            position: relative;
            min-height: 100%;
        `;

        // Create spacers
        this.spacerTop = document.createElement('div');
        this.spacerTop.className = 'virtual-scroll-spacer-top';
        
        this.spacerBottom = document.createElement('div');
        this.spacerBottom.className = 'virtual-scroll-spacer-bottom';

        // Assemble DOM
        this.content.appendChild(this.spacerTop);
        this.content.appendChild(this.spacerBottom);
        this.viewport.appendChild(this.content);
        
        // Replace container content
        this.container.innerHTML = '';
        this.container.appendChild(this.viewport);
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Throttled scroll handler
        this.scrollHandler = Need2Talk.utils?.throttle 
            ? Need2Talk.utils.throttle(() => this.handleScroll(), this.options.debounceMs)
            : () => this.handleScroll();

        this.viewport.addEventListener('scroll', this.scrollHandler);
        
        // Resize handler
        this.resizeHandler = Need2Talk.utils?.debounce 
            ? Need2Talk.utils.debounce(() => this.handleResize(), 250)
            : () => this.handleResize();
            
        window.addEventListener('resize', this.resizeHandler);
    }

    /**
     * Handle scroll events
     */
    handleScroll() {
        this.scrollTop = this.viewport.scrollTop;
        this.updateVisibleRange();
        this.renderVisibleItems();
        
        // Check if need to load more
        if (this.onLoadMore && this.shouldLoadMore()) {
            this.onLoadMore();
        }
        
        // Call custom scroll handler
        if (this.onScroll) {
            this.onScroll(this.scrollTop, this.viewport.scrollHeight);
        }
    }

    /**
     * Handle resize events
     */
    handleResize() {
        this.calculateDimensions();
        this.updateVisibleRange();
        this.renderVisibleItems();
    }

    /**
     * Calculate dimensions
     */
    calculateDimensions() {
        this.containerHeight = this.viewport.clientHeight;
        this.totalHeight = this.items.length * this.options.itemHeight;
        
        // Update content height
        this.content.style.height = `${this.totalHeight}px`;
    }

    /**
     * Update visible range
     */
    updateVisibleRange() {
        const itemsPerPage = Math.ceil(this.containerHeight / this.options.itemHeight);
        const buffer = this.options.buffer;
        
        this.startIndex = Math.max(0, 
            Math.floor(this.scrollTop / this.options.itemHeight) - buffer
        );
        
        this.endIndex = Math.min(this.items.length - 1, 
            this.startIndex + itemsPerPage + (buffer * 2)
        );
    }

    /**
     * Render visible items
     */
    renderVisibleItems() {
        // Clear existing visible items
        this.visibleItems.forEach(item => {
            if (item.element && item.element.parentNode) {
                item.element.parentNode.removeChild(item.element);
            }
        });
        
        this.visibleItems = [];
        
        // Render new visible items
        for (let i = this.startIndex; i <= this.endIndex; i++) {
            const item = this.items[i];
            if (!item) continue;
            
            const element = this.renderItem(item, i);
            if (element) {
                // Position element
                element.style.position = 'absolute';
                element.style.top = `${i * this.options.itemHeight}px`;
                element.style.width = '100%';
                element.style.height = `${this.options.itemHeight}px`;
                
                this.content.appendChild(element);
                
                this.visibleItems.push({
                    index: i,
                    data: item,
                    element: element
                });
            }
        }
        
        // Update spacers
        this.updateSpacers();
    }

    /**
     * Update spacer heights
     */
    updateSpacers() {
        const topHeight = this.startIndex * this.options.itemHeight;
        const bottomHeight = (this.items.length - this.endIndex - 1) * this.options.itemHeight;
        
        this.spacerTop.style.height = `${topHeight}px`;
        this.spacerBottom.style.height = `${Math.max(0, bottomHeight)}px`;
    }

    /**
     * Default item renderer
     */
    defaultRenderItem(item, index) {
        const element = document.createElement('div');
        element.className = 'virtual-scroll-item';
        element.style.cssText = `
            padding: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
        `;
        element.textContent = `Item ${index}: ${JSON.stringify(item)}`;
        
        return element;
    }

    /**
     * Check if should load more items
     */
    shouldLoadMore() {
        const scrollBottom = this.scrollTop + this.containerHeight;
        const threshold = this.totalHeight * (1 - this.options.threshold);
        
        return scrollBottom >= threshold;
    }

    /**
     * Set items data
     */
    setItems(items) {
        this.items = items || [];
        this.calculateDimensions();
        this.updateVisibleRange();
        this.renderVisibleItems();
    }

    /**
     * Add items to list
     */
    addItems(newItems) {
        this.items = this.items.concat(newItems || []);
        this.calculateDimensions();
        
        // Only re-render if new items are in visible range
        if (this.endIndex >= this.items.length - newItems.length) {
            this.updateVisibleRange();
            this.renderVisibleItems();
        }
    }

    /**
     * Scroll to specific item
     */
    scrollToItem(index) {
        if (index < 0 || index >= this.items.length) return;
        
        const targetScrollTop = index * this.options.itemHeight;
        this.viewport.scrollTop = targetScrollTop;
    }

    /**
     * Destroy instance
     */
    destroy() {
        // Remove event listeners
        this.viewport.removeEventListener('scroll', this.scrollHandler);
        window.removeEventListener('resize', this.resizeHandler);

        // Clear data
        this.items = [];
        this.visibleItems = [];

        console.info('[Need2Talk] Virtual scroll instance destroyed');
    }
}

// Export for modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Need2Talk.VirtualScroll;
}