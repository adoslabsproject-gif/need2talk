/**
 * NEED2TALK - Profile Manager
 * Enterprise JavaScript for user profile management
 *
 * Manages user profile interactions, statistics updates,
 * and integration with Need2Talk ecosystem
 */

'use strict';

// Namespace protection
window.Need2Talk = window.Need2Talk || {};

/**
 * Profile Manager Class
 * Handles all profile-related functionality
 */
class ProfileManager {
    constructor(options = {}) {
        this.user = options.user || {};
        this.stats = options.stats || {};
        this.config = {
            apiBase: '/api/v1',
            updateInterval: 30000, // 30 seconds for real-time stats
            ...options.config
        };

        this.init();
    }

    /**
     * Initialize Profile Manager
     */
    init() {
        this.bindEvents();
        this.startStatsUpdater();
        this.logProfileAccess();

        console.info('Need2Talk ProfileManager: Initialized for enterprise experience! 🚀');
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Quick action cards
        document.querySelectorAll('.profile-action-card').forEach(card => {
            card.addEventListener('click', this.handleActionCard.bind(this));
            card.addEventListener('keydown', this.handleKeyboardNav.bind(this));
        });

        // Stats card interactions
        document.querySelectorAll('.profile-stats-card').forEach(card => {
            card.addEventListener('click', this.handleStatsCard.bind(this));
        });

        // Performance monitoring
        this.trackUserInteractions();
    }

    /**
     * Handle action card clicks
     */
    handleActionCard(event) {
        const card = event.currentTarget;
        const actionType = this.getActionType(card);

        // Security: Prevent rapid clicking
        if (card.dataset.processing === 'true') {
            return;
        }

        card.dataset.processing = 'true';

        setTimeout(() => {
            card.dataset.processing = 'false';
        }, 1000);

        switch (actionType) {
            case 'create-audio':
                this.createAudioPost();
                break;
            case 'listen-community':
                this.openCommunityPlayer();
                break;
            case 'manage-settings':
                this.openSettings();
                break;
            case 'view-stats':
                this.openStatistics();
                break;
            default:
                console.warn('⚠️ Unknown action type:', actionType);
        }

        // Analytics - ENTERPRISE SECURITY: Use UUID for tracking
        this.trackUserAction('profile_action', {
            action: actionType,
            user_uuid: this.user.uuid,
            timestamp: new Date().toISOString()
        });
    }

    /**
     * Get action type from card classes
     */
    getActionType(card) {
        if (card.classList.contains('create-audio')) return 'create-audio';
        if (card.classList.contains('listen-community')) return 'listen-community';
        if (card.classList.contains('manage-settings')) return 'manage-settings';
        if (card.classList.contains('view-stats')) return 'view-stats';
        return 'unknown';
    }

    /**
     * Handle keyboard navigation
     */
    handleKeyboardNav(event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.handleActionCard(event);
        }
    }

    /**
     * Handle stats card interactions
     */
    handleStatsCard(event) {
        const card = event.currentTarget;

        // Add visual feedback
        card.style.transform = 'scale(0.98)';
        setTimeout(() => {
            card.style.transform = '';
        }, 150);

        // Track interaction - ENTERPRISE SECURITY: Use UUID
        this.trackUserAction('stats_view', {
            card_type: this.getStatsType(card),
            user_uuid: this.user.uuid
        });
    }

    /**
     * Get stats card type
     */
    getStatsType(card) {
        if (card.classList.contains('audio-card')) return 'audio_posts';
        if (card.classList.contains('listens-card')) return 'total_listens';
        if (card.classList.contains('rank-card')) return 'community_rank';
        return 'unknown';
    }

    /**
     * Create Audio Post - Placeholder for future implementation
     */
    createAudioPost() {
        console.debug('Creating audio post...');

        // Future: Implement audio recording interface
        // For now, show development notice
        this.showNotification('Audio post creation coming soon!', 'info');

        // Log the action - ENTERPRISE SECURITY: Use UUID
        if (window.Need2Talk && window.Need2Talk.Logger) {
            window.Need2Talk.Logger.info('profile', 'User attempted audio post creation', {
                user_uuid: this.user.uuid,
                feature: 'audio_post_create'
            });
        }
    }

    /**
     * Open Community Player - Placeholder
     */
    openCommunityPlayer() {
        console.debug('Opening community player...');

        // Future: Navigate to community audio feed
        this.showNotification('Community player coming soon!', 'info');

        this.trackUserAction('community_access_attempt', {
            source: 'profile_quick_action'
        });
    }

    /**
     * Open Settings - Placeholder
     */
    openSettings() {
        console.debug('Opening settings...');

        // Future: Navigate to user settings
        this.showNotification('Profile settings coming soon!', 'info');

        this.trackUserAction('settings_access_attempt', {
            source: 'profile_quick_action'
        });
    }

    /**
     * Open Statistics - Placeholder
     */
    openStatistics() {
        console.debug('Opening statistics...');

        // Future: Navigate to detailed statistics
        this.showNotification('Detailed statistics coming soon!', 'info');

        this.trackUserAction('stats_access_attempt', {
            source: 'profile_quick_action'
        });
    }

    /**
     * Start real-time stats updater
     */
    startStatsUpdater() {
        // Update stats every 30 seconds for enterprise real-time experience
        setInterval(async () => {
            try {
                await this.updateUserStats();
            } catch (error) {
                console.error('Stats update failed:', error);
            }
        }, this.config.updateInterval);
    }

    /**
     * Update user statistics from API
     */
    async updateUserStats() {
        try {
            const response = await fetch(`${this.config.apiBase}/user/stats`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin'
            });

            if (response.ok) {
                const newStats = await response.json();
                this.updateStatsDisplay(newStats);
                this.stats = { ...this.stats, ...newStats };
            }
        } catch (error) {
            console.error('Failed to update stats:', error);
        }
    }

    /**
     * Update stats display in DOM
     */
    updateStatsDisplay(newStats) {
        // Update audio posts count
        const audioCountEl = document.querySelector('.audio-card .text-3xl');
        if (audioCountEl && newStats.audio_count !== undefined) {
            this.animateNumber(audioCountEl, newStats.audio_count);
        }

        // Update listens count
        const listensCountEl = document.querySelector('.listens-card .text-3xl');
        if (listensCountEl && newStats.total_listens !== undefined) {
            this.animateNumber(listensCountEl, newStats.total_listens);
        }

        // Update community rank
        const rankEl = document.querySelector('.rank-card .text-3xl');
        if (rankEl && newStats.community_rank !== undefined) {
            rankEl.textContent = `#${this.formatNumber(newStats.community_rank)}`;
        }
    }

    /**
     * Animate number changes
     */
    animateNumber(element, newValue) {
        const currentValue = parseInt(element.textContent.replace(/[^\d]/g, '')) || 0;
        const difference = newValue - currentValue;

        if (difference === 0) return;

        const duration = 1000;
        const startTime = Date.now();

        const animate = () => {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);

            const current = Math.round(currentValue + (difference * progress));
            element.textContent = this.formatNumber(current);

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    }

    /**
     * Format numbers with proper thousands separators
     */
    formatNumber(num) {
        return new Intl.NumberFormat('it-IT').format(num);
    }

    /**
     * Show notification to user
     */
    showNotification(message, type = 'info') {
        // Future: Integrate with notification system
        console.info(`[${type.toUpperCase()}] ${message}`);

        // For now, use browser notification if available
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('need2talk', {
                body: message,
                icon: '/assets/img/logo-192.png'
            });
        }
    }

    /**
     * Track user actions for analytics
     */
    trackUserAction(action, data = {}) {
        if (window.Need2Talk && window.Need2Talk.Logger) {
            window.Need2Talk.Logger.info('profile', `User action: ${action}`, {
                ...data,
                page: 'profile',
                timestamp: new Date().toISOString()
            });
        }
    }

    /**
     * Track user interactions for performance monitoring
     */
    trackUserInteractions() {
        let interactionCount = 0;

        document.addEventListener('click', () => {
            interactionCount++;

            // Report every 10 interactions
            if (interactionCount % 10 === 0) {
                this.trackUserAction('interaction_batch', {
                    count: interactionCount,
                    page_load_time: performance.now()
                });
            }
        });
    }

    /**
     * Log profile page access - ENTERPRISE SECURITY: Use UUID
     */
    logProfileAccess() {
        if (window.Need2Talk && window.Need2Talk.Logger) {
            window.Need2Talk.Logger.info('profile', 'Profile page accessed', {
                user_uuid: this.user.uuid,
                user_agent: navigator.userAgent,
                viewport: `${window.innerWidth}x${window.innerHeight}`,
                timestamp: new Date().toISOString()
            });
        }
    }

    /**
     * Performance tracking
     */
    trackPagePerformance() {
        // Track page load performance
        window.addEventListener('load', () => {
            const perfData = performance.getEntriesByType('navigation')[0];

            this.trackUserAction('page_performance', {
                load_time: perfData.loadEventEnd - perfData.loadEventStart,
                dom_ready: perfData.domContentLoadedEventEnd - perfData.domContentLoadedEventStart,
                total_time: perfData.loadEventEnd - perfData.fetchStart
            });
        });
    }
}

// Singleton initialization
if (!window.Need2Talk.ProfileManager) {
    const userData = window.profileData ? window.profileData().user : {};
    const statsData = window.profileData ? window.profileData().stats : {};

    window.Need2Talk.ProfileManager = new ProfileManager({
        user: userData,
        stats: statsData,
        config: {
            apiBase: '/api/v1',
            updateInterval: 30000
        }
    });
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ProfileManager;
}