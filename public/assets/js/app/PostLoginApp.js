/**
 * need2talk - Post-Login Application
 * Enterprise Galaxy - Main orchestrator for authenticated area
 *
 * Purpose: Initialize and coordinate all components
 * Pattern: Singleton orchestrator with lazy component initialization
 */

class PostLoginApp {
    constructor() {
        this.feedManager = null;
        this.userMenuDropdown = null;
    }

    /**
     * Initialize application
     * @public
     */
    init() {
        // Initialize UI components
        this.initUserMenu();
        this.initFeedManager();
        this.loadUserStats();
    }

    /**
     * Initialize user menu dropdown
     * @private
     */
    initUserMenu() {
        const menuButton = document.getElementById('userMenuButton');
        const dropdown = document.getElementById('userDropdown');

        if (!menuButton || !dropdown) return;

        this.userMenuDropdown = dropdown;

        menuButton.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('hidden');

            // Add slide-down animation if showing
            if (!dropdown.classList.contains('hidden')) {
                dropdown.classList.add('slide-down');
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!menuButton.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });
    }

    /**
     * Initialize feed manager
     * @private
     */
    initFeedManager() {
        this.feedManager = new FeedManager({
            containerId: 'feedContainer',
            loadMoreBtnId: 'loadMoreBtn',
            loadingId: 'loadingMore',
            sortSelectId: 'feedSortSelect',
            apiEndpoint: window.need2talk.api.audioFeed,
            perPage: 10,
            infiniteScroll: true
        });
    }

    /**
     * Load user stats from API
     * Only runs on pages with stats elements (feed sidebar)
     * @private
     */
    async loadUserStats() {
        // ENTERPRISE FIX: Only load stats if elements exist (feed page only)
        const postCountEl = document.getElementById('userPostCount');
        const likesCountEl = document.getElementById('userLikesCount');
        const playsCountEl = document.getElementById('userPlaysCount');

        // Skip if not on feed page (elements don't exist)
        if (!postCountEl || !likesCountEl || !playsCountEl) {
            return;
        }

        try {
            const response = await api.get('/api/profile/stats');

            if (response.success && response.stats) {
                postCountEl.textContent = formatNumber(response.stats.posts_count || 0);
                likesCountEl.textContent = formatNumber(response.stats.likes_received || 0);
                playsCountEl.textContent = formatNumber(response.stats.total_plays || 0);
            }

        } catch (error) {
            console.error('PostLoginApp: Failed to load user stats', error);
        }
    }
}

// Auto-initialize on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    window.postLoginApp = new PostLoginApp();
    window.postLoginApp.init();

    // Expose feedManager globally for button onclick handlers
    window.feedManager = window.postLoginApp.feedManager;
});

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PostLoginApp;
}
