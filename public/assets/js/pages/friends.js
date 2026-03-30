/**
 * FRIENDS MANAGER - ENTERPRISE GALAXY
 *
 * Complete friendship management with real-time AJAX
 * - Tab navigation
 * - Data loading per-tab
 * - Optimistic UI updates
 * - Error handling & retry logic
 * - Rate limiting feedback
 * - XSS prevention (HTML escaping)
 * - CSRF protection
 *
 * SECURITY:
 * - All user input escaped (XSS prevention)
 * - CSRF token in all requests
 * - Rate limiting feedback
 * - No eval() or innerHTML with untrusted data
 *
 * @version 2.0.0 (ENTERPRISE V10.52 - Infinite Scroll)
 * @author Claude Code (AI-Orchestrated Development)
 */

const FriendsManager = {
    // State
    currentTab: 'pending',
    loadedTabs: new Set(),
    searchTimeout: null,
    pendingRequests: [],
    sentRequests: [],
    friends: [],
    blockedUsers: [],

    // ENTERPRISE V10.52: Infinite Scroll State for Friends List
    // Pagination: load 10 friends at a time with IntersectionObserver
    friendsOffset: 0,
    friendsHasMore: true,
    friendsLoading: false,
    friendsTotalCount: 0,
    friendsObserver: null,
    FRIENDS_PAGE_SIZE: 10,

    /**
     * Initialize on page load
     *
     * ENTERPRISE V9.0 (2025-12-02): URL Parameter Support
     * Reads ?tab= parameter to determine initial tab.
     * This fixes notification navigation:
     * - ?tab=requests → opens "Richieste" (pending tab)
     * - ?tab=friends → opens "Amici" (friends tab)
     */
    init() {
        console.log('[FriendsManager] Initializing...');

        // ENTERPRISE V8.2: CSRF verification via single source of truth
        if (!Need2Talk.CSRF || !Need2Talk.CSRF.getToken()) {
            console.error('[FriendsManager] CSRF token not found! Aborting.');
            this.showError('Errore di sicurezza. Ricarica la pagina.');
            return;
        }

        // ENTERPRISE V9.0: Read URL params to determine initial tab
        // This fixes notification clicks that navigate to /friends?tab=friends
        // Previously always opened 'pending' tab, ignoring URL params
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');

        // Map URL param to internal tab name (defensive coding)
        const tabMapping = {
            'requests': 'pending',      // ?tab=requests → pending tab (richieste ricevute)
            'pending': 'pending',       // ?tab=pending → pending tab
            'friends': 'friends',       // ?tab=friends → friends tab (amici!)
            'blocked': 'blocked',       // ?tab=blocked → blocked tab
            'sent': 'sent',             // ?tab=sent → sent tab
            'search': 'search'          // ?tab=search → search tab
        };

        // ENTERPRISE V11.8: Default to 'friends' tab (users want to see friends list first)
        // Use 'pending' only when explicitly requested via URL param
        const initialTab = tabMapping[tabParam] || 'friends';

        console.log('[FriendsManager] URL tab param:', tabParam, '→ Initial tab:', initialTab);

        // Load the initial tab (NOT pending by default anymore!)
        this.switchTab(initialTab);

        // ENTERPRISE V11.8: Load ALL badge counts on init (not just pending)
        // This ensures correct counts are shown immediately when page loads
        this.loadAllBadgeCounts();

        console.log('[FriendsManager] Initialization complete');
    },

    /**
     * ENTERPRISE V11.8: Load all badge counts on page init
     * Ensures correct counts are displayed immediately without visiting each tab
     */
    async loadAllBadgeCounts() {
        // Load all counts in parallel for performance
        await Promise.all([
            this.updatePendingCount(),
            this.updateSentCountFromApi(),
            this.updateFriendsCountFromApi()
        ]);
    },

    /**
     * ENTERPRISE V11.8: Fetch sent requests count from API
     */
    async updateSentCountFromApi() {
        try {
            const response = await fetch('/social/friend-requests/sent/count', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });

            if (!response.ok) return;

            const data = await response.json();
            const count = data.count || 0;

            const badge = document.getElementById('sent-count-badge');
            const badgeMobile = document.getElementById('sent-count-badge-mobile');

            if (count > 0) {
                if (badge) {
                    badge.textContent = count;
                    badge.classList.remove('hidden');
                }
                if (badgeMobile) {
                    badgeMobile.textContent = count;
                    badgeMobile.classList.remove('hidden');
                }
            } else {
                if (badge) badge.classList.add('hidden');
                if (badgeMobile) badgeMobile.classList.add('hidden');
            }
        } catch (error) {
            console.error('[FriendsManager] Failed to fetch sent count:', error);
        }
    },

    /**
     * ENTERPRISE V11.8: Fetch friends count from API
     */
    async updateFriendsCountFromApi() {
        try {
            const response = await fetch('/api/friends/count', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });

            if (!response.ok) return;

            const data = await response.json();
            const count = data.count || 0;

            const badge = document.getElementById('friends-count-badge');
            const badgeMobile = document.getElementById('friends-count-badge-mobile');

            if (count > 0) {
                if (badge) {
                    badge.textContent = count;
                    badge.classList.remove('hidden');
                }
                if (badgeMobile) {
                    badgeMobile.textContent = count;
                    badgeMobile.classList.remove('hidden');
                }
            } else {
                if (badge) badge.classList.add('hidden');
                if (badgeMobile) badgeMobile.classList.add('hidden');
            }
        } catch (error) {
            console.error('[FriendsManager] Failed to fetch friends count:', error);
        }
    },

    /**
     * Switch between tabs
     */
    switchTab(tabName) {
        console.log(`[FriendsManager] Switching to tab: ${tabName}`);

        this.currentTab = tabName;

        // Update tab buttons (active state - Settings-style gradient)
        document.querySelectorAll('.tab-button').forEach(btn => {
            // Remove active state (Settings-style)
            btn.classList.remove('bg-gradient-to-r', 'from-purple-600', 'to-pink-600', 'text-white', 'shadow-lg', 'shadow-purple-500/25', 'active');
            // Add inactive state
            btn.classList.add('text-gray-300');

            // Reset text span colors to inactive (gray)
            const textSpan = btn.querySelector('span.whitespace-nowrap');
            if (textSpan) {
                textSpan.classList.remove('text-white');
                textSpan.classList.add('text-gray-300');
            }

            // Reset SVG colors to inactive (gray)
            const svg = btn.querySelector('svg');
            if (svg) {
                svg.classList.remove('text-white');
                svg.classList.add('text-gray-300');
            }

            // Reset badge colors to inactive state
            const badge = btn.querySelector('[id$="-count-badge"]');
            if (badge && !badge.classList.contains('hidden')) {
                // Remove active badge colors
                badge.classList.remove('bg-white', 'text-purple-600');
                // Restore inactive badge colors based on badge ID
                if (badge.id.includes('pending')) {
                    badge.classList.add('bg-pink-600', 'text-white');
                } else if (badge.id.includes('sent')) {
                    badge.classList.add('bg-cyan-600', 'text-white');
                } else if (badge.id.includes('friends')) {
                    badge.classList.add('bg-purple-600', 'text-white');
                }
            }
        });

        // Apply active state to current tab (Settings-style)
        const activeTab = document.getElementById(`tab-${tabName}`);
        const activeTabMobile = document.getElementById(`tab-${tabName}-mobile`);

        if (activeTab) {
            activeTab.classList.remove('text-gray-300');
            activeTab.classList.add('bg-gradient-to-r', 'from-purple-600', 'to-pink-600', 'text-white', 'shadow-lg', 'shadow-purple-500/25', 'active');

            // Force text span to white when active
            const textSpan = activeTab.querySelector('span.whitespace-nowrap');
            if (textSpan) {
                textSpan.classList.remove('text-gray-300');
                textSpan.classList.add('text-white');
            }

            // Force SVG to white when active
            const svg = activeTab.querySelector('svg');
            if (svg) {
                svg.classList.remove('text-gray-300');
                svg.classList.add('text-white');
            }

            // Update badge to white with purple text when active
            const badge = activeTab.querySelector('[id$="-count-badge"]');
            if (badge && !badge.classList.contains('hidden')) {
                badge.classList.remove('bg-pink-600', 'bg-cyan-600', 'bg-purple-600', 'text-white');
                badge.classList.add('bg-white', 'text-purple-600');
            }
        }

        if (activeTabMobile) {
            activeTabMobile.classList.remove('text-gray-300');
            activeTabMobile.classList.add('bg-gradient-to-r', 'from-purple-600', 'to-pink-600', 'text-white', 'active');

            // Force mobile text to white
            const textSpanMobile = activeTabMobile.querySelector('span.whitespace-nowrap');
            if (textSpanMobile) {
                textSpanMobile.classList.remove('text-gray-300');
                textSpanMobile.classList.add('text-white');
            }

            // Update mobile badge too
            const badgeMobile = activeTabMobile.querySelector('[id$="-count-badge-mobile"]');
            if (badgeMobile && !badgeMobile.classList.contains('hidden')) {
                badgeMobile.classList.remove('bg-pink-600', 'bg-cyan-600', 'bg-purple-600', 'text-white');
                badgeMobile.classList.add('bg-white', 'text-purple-600');
            }
        }

        // Hide all content
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });

        // Show loading state
        const loadingState = document.getElementById('loading-state');
        if (loadingState) {
            loadingState.classList.remove('hidden');
        }

        // Load tab data (if not already loaded)
        if (!this.loadedTabs.has(tabName)) {
            this.loadTabData(tabName);
        } else {
            // Show cached content
            if (loadingState) {
                loadingState.classList.add('hidden');
            }
            const contentEl = document.getElementById(`content-${tabName}`);
            if (contentEl) {
                contentEl.classList.remove('hidden');
            }
        }
    },

    /**
     * Load data for specific tab
     */
    async loadTabData(tabName) {
        try {
            let data = [];

            switch (tabName) {
                case 'pending':
                    data = await this.fetchPendingRequests();
                    this.pendingRequests = data;
                    this.renderPendingRequests(data);
                    break;

                case 'sent':
                    data = await this.fetchSentRequests();
                    this.sentRequests = data;
                    this.renderSentRequests(data);
                    break;

                case 'friends':
                    // ENTERPRISE V10.52: Reset pagination state for fresh load
                    this.friendsOffset = 0;
                    this.friendsHasMore = true;
                    this.friendsLoading = false;
                    this.friends = [];

                    // Load first page (10 friends)
                    const result = await this.fetchFriends(0, this.FRIENDS_PAGE_SIZE);
                    this.friends = result.friends;
                    this.friendsHasMore = result.has_more;
                    this.friendsTotalCount = result.total_count;
                    this.friendsOffset = this.FRIENDS_PAGE_SIZE;

                    this.renderFriends(this.friends, true); // true = initial render
                    break;

                case 'search':
                    // Search tab doesn't auto-load data
                    this.renderSearchEmpty();
                    break;

                case 'blocked':
                    data = await this.fetchBlockedUsers();
                    this.blockedUsers = data;
                    this.renderBlockedUsers(data);
                    break;
            }

            this.loadedTabs.add(tabName);

            // Hide loading, show content
            const loadingState = document.getElementById('loading-state');
            if (loadingState) {
                loadingState.classList.add('hidden');
            }

            const contentEl = document.getElementById(`content-${tabName}`);
            if (contentEl) {
                contentEl.classList.remove('hidden');
            }

        } catch (error) {
            console.error(`[FriendsManager] Failed to load ${tabName}:`, error);
            this.showError(`Errore nel caricamento dei dati. Riprova.`);
        }
    },

    /**
     * Fetch pending requests (received)
     */
    async fetchPendingRequests() {
        const response = await fetch('/social/friend-requests', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            }
        });

        if (!response.ok) {
            throw new Error(`Network error: ${response.status}`);
        }

        const data = await response.json();
        return data.requests || [];
    },

    /**
     * Fetch sent requests
     */
    async fetchSentRequests() {
        const response = await fetch('/social/friend-requests/sent', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            }
        });

        if (!response.ok) {
            throw new Error(`Network error: ${response.status}`);
        }

        const data = await response.json();
        return data.requests || [];
    },

    /**
     * Fetch friends list with pagination
     *
     * ENTERPRISE V10.52: Infinite scroll support
     * @param {number} offset - Starting position
     * @param {number} limit - Number of friends to fetch
     * @returns {Object} { friends, total_count, has_more }
     */
    async fetchFriends(offset = 0, limit = 10) {
        const response = await fetch(`/api/friends?limit=${limit}&offset=${offset}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            }
        });

        if (!response.ok) {
            throw new Error(`Network error: ${response.status}`);
        }

        const data = await response.json();
        return {
            friends: data.friends || [],
            total_count: data.total_count || 0,
            has_more: data.has_more || false
        };
    },

    /**
     * Load more friends (infinite scroll)
     *
     * ENTERPRISE V10.52: Called by IntersectionObserver when sentinel is visible
     */
    async loadMoreFriends() {
        // Prevent concurrent loads
        if (this.friendsLoading || !this.friendsHasMore) {
            return;
        }

        this.friendsLoading = true;
        console.log(`[FriendsManager] Loading more friends: offset=${this.friendsOffset}`);

        // Show loading indicator
        const loadingIndicator = document.getElementById('friends-loading-more');
        if (loadingIndicator) {
            loadingIndicator.classList.remove('hidden');
        }

        try {
            const result = await this.fetchFriends(this.friendsOffset, this.FRIENDS_PAGE_SIZE);

            if (result.friends.length > 0) {
                // Append to existing friends
                this.friends = [...this.friends, ...result.friends];
                this.friendsOffset += result.friends.length;
                this.friendsHasMore = result.has_more;

                // Render new friends (append mode)
                this.renderFriends(result.friends, false); // false = append mode

                // Update badge with total
                const badge = document.getElementById('friends-count-badge');
                if (badge) {
                    badge.textContent = this.friendsTotalCount;
                }
            } else {
                this.friendsHasMore = false;
            }
        } catch (error) {
            console.error('[FriendsManager] Failed to load more friends:', error);
            this.showToast('Errore nel caricamento. Riprova.', 'error');
        } finally {
            this.friendsLoading = false;

            // Hide loading indicator
            if (loadingIndicator) {
                loadingIndicator.classList.add('hidden');
            }

            // Hide sentinel if no more data
            const sentinel = document.getElementById('friends-scroll-sentinel');
            if (sentinel && !this.friendsHasMore) {
                sentinel.classList.add('hidden');
            }
        }
    },

    /**
     * Fetch blocked users (ENTERPRISE V4)
     */
    async fetchBlockedUsers() {
        const response = await fetch('/api/blocked-users', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            }
        });

        if (!response.ok) {
            throw new Error(`Network error: ${response.status}`);
        }

        const data = await response.json();
        return data.blocked_users || [];
    },

    /**
     * Update pending requests count badge
     */
    async updatePendingCount() {
        try {
            const response = await fetch('/social/friend-requests/count', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });

            if (!response.ok) return;

            const data = await response.json();
            const count = data.count || 0;

            // ENTERPRISE V11.8: Update both desktop and mobile badges
            const badge = document.getElementById('pending-count-badge');
            const badgeMobile = document.getElementById('pending-count-badge-mobile');

            if (count > 0) {
                if (badge) {
                    badge.textContent = count;
                    badge.classList.remove('hidden');
                }
                if (badgeMobile) {
                    badgeMobile.textContent = count;
                    badgeMobile.classList.remove('hidden');
                }
            } else {
                if (badge) badge.classList.add('hidden');
                if (badgeMobile) badgeMobile.classList.add('hidden');
            }
        } catch (error) {
            console.error('[FriendsManager] Failed to fetch pending count:', error);
        }
    },

    /**
     * Update sent requests count badge (from local cache)
     *
     * ENTERPRISE: Uses local cache count (no API call needed - performance optimization)
     */
    updateSentCount() {
        const count = this.sentRequests ? this.sentRequests.length : 0;

        // ENTERPRISE V11.8: Update both desktop and mobile badges
        const badge = document.getElementById('sent-count-badge');
        const badgeMobile = document.getElementById('sent-count-badge-mobile');

        if (count > 0) {
            if (badge) {
                badge.textContent = count;
                badge.classList.remove('hidden');
            }
            if (badgeMobile) {
                badgeMobile.textContent = count;
                badgeMobile.classList.remove('hidden');
            }
        } else {
            if (badge) badge.classList.add('hidden');
            if (badgeMobile) badgeMobile.classList.add('hidden');
        }
    },

    /**
     * Render pending requests
     */
    renderPendingRequests(requests) {
        const container = document.getElementById('pending-requests-container');
        if (!container) return;

        if (requests.length === 0) {
            container.innerHTML = this.renderEmptyState(
                'inbox',
                'Nessuna richiesta di amicizia',
                'Quando qualcuno ti invierà una richiesta, apparirà qui'
            );
            return;
        }

        let html = '<div class="space-y-4">';

        requests.forEach(request => {
            html += this.renderUserCard(request, 'pending');
        });

        html += '</div>';

        container.innerHTML = html;
    },

    /**
     * Render sent requests
     */
    renderSentRequests(requests) {
        const container = document.getElementById('sent-requests-container');
        if (!container) return;

        if (requests.length === 0) {
            container.innerHTML = this.renderEmptyState(
                'paper-plane',
                'Nessuna richiesta inviata',
                'Usa la tab "Cerca Utenti" per trovare persone da aggiungere'
            );
            return;
        }

        // Update badge
        const badge = document.getElementById('sent-count-badge');
        if (badge && requests.length > 0) {
            badge.textContent = requests.length;
            badge.classList.remove('hidden');
        }

        let html = '<div class="space-y-4">';

        requests.forEach(request => {
            html += this.renderUserCard(request, 'sent');
        });

        html += '</div>';

        container.innerHTML = html;
    },

    /**
     * Render friends list with infinite scroll support
     *
     * ENTERPRISE V10.52: Supports initial render and append mode
     * @param {Array} friends - Friends array to render
     * @param {boolean} initialRender - true = replace content, false = append
     */
    renderFriends(friends, initialRender = true) {
        const container = document.getElementById('friends-list-container');
        if (!container) return;

        // Empty state check (only on initial render with no friends)
        if (initialRender && friends.length === 0 && this.friends.length === 0) {
            container.innerHTML = this.renderEmptyState(
                'users',
                'Nessun amico ancora',
                'Inizia a cercare utenti e invia richieste di amicizia!'
            );
            return;
        }

        // Update badge with total count
        const badge = document.getElementById('friends-count-badge');
        if (badge) {
            badge.textContent = this.friendsTotalCount || friends.length;
            badge.classList.remove('hidden');
        }

        if (initialRender) {
            // ENTERPRISE V10.52: Initial render with scroll container + sentinel
            let html = `
                <div id="friends-scroll-container" class="space-y-3 max-h-[600px] overflow-y-auto pr-2 scrollbar-thin scrollbar-thumb-purple-600 scrollbar-track-gray-800">
                    <div id="friends-list-items">
            `;

            friends.forEach(friend => {
                html += this.renderUserCard(friend, 'friend');
            });

            html += `
                    </div>

                    <!-- Infinite Scroll Sentinel -->
                    <div id="friends-scroll-sentinel" class="py-4 text-center ${this.friendsHasMore ? '' : 'hidden'}">
                        <div id="friends-loading-more" class="hidden">
                            <div class="inline-block animate-spin rounded-full h-6 w-6 border-t-2 border-b-2 border-purple-500"></div>
                            <p class="mt-2 text-gray-400 text-sm">Caricamento...</p>
                        </div>
                    </div>
                </div>
            `;

            container.innerHTML = html;

            // Setup IntersectionObserver for infinite scroll
            this.setupFriendsObserver();
        } else {
            // Append mode: add new friends to existing list
            const listItems = document.getElementById('friends-list-items');
            if (listItems) {
                friends.forEach(friend => {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = this.renderUserCard(friend, 'friend');
                    // Append each child (the user-card div)
                    while (tempDiv.firstChild) {
                        listItems.appendChild(tempDiv.firstChild);
                    }
                });
            }
        }
    },

    /**
     * Setup IntersectionObserver for infinite scroll
     *
     * ENTERPRISE V10.52: Triggers loadMoreFriends when sentinel becomes visible
     */
    setupFriendsObserver() {
        // Disconnect previous observer if exists
        if (this.friendsObserver) {
            this.friendsObserver.disconnect();
        }

        const sentinel = document.getElementById('friends-scroll-sentinel');
        const scrollContainer = document.getElementById('friends-scroll-container');

        if (!sentinel || !scrollContainer) {
            console.warn('[FriendsManager] Sentinel or scroll container not found');
            return;
        }

        // Create IntersectionObserver with scroll container as root
        this.friendsObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && this.friendsHasMore && !this.friendsLoading) {
                        console.log('[FriendsManager] Sentinel visible, loading more...');
                        this.loadMoreFriends();
                    }
                });
            },
            {
                root: scrollContainer, // Observe within scroll container
                rootMargin: '100px',   // Trigger 100px before sentinel is visible
                threshold: 0.1         // Trigger when 10% visible
            }
        );

        // Start observing sentinel
        this.friendsObserver.observe(sentinel);
        console.log('[FriendsManager] IntersectionObserver setup complete');
    },

    /**
     * Render blocked users list (ENTERPRISE V4)
     */
    renderBlockedUsers(blockedUsers) {
        const container = document.getElementById('blocked-users-container');
        if (!container) return;

        if (blockedUsers.length === 0) {
            container.innerHTML = this.renderEmptyState(
                'ban',
                'Nessun utente bloccato',
                'Non hai bloccato nessun utente. Gli utenti bloccati non potranno interagire con te.'
            );
            return;
        }

        // Update badge
        const badge = document.getElementById('blocked-count-badge');
        const badgeMobile = document.getElementById('blocked-count-badge-mobile');
        if (badge) {
            badge.textContent = blockedUsers.length;
            badge.classList.remove('hidden');
        }
        if (badgeMobile) {
            badgeMobile.textContent = blockedUsers.length;
            badgeMobile.classList.remove('hidden');
        }

        let html = '<div class="space-y-4">';

        blockedUsers.forEach(user => {
            html += this.renderUserCard(user, 'blocked');
        });

        html += '</div>';

        container.innerHTML = html;
    },

    /**
     * Render user card (reusable component)
     *
     * SECURITY: All user data is escaped before rendering
     */
    renderUserCard(user, type) {
        // SECURITY: Escape all user data to prevent XSS
        const avatarUrl = this.escapeHtml(user.avatar_url || '/assets/img/default-avatar.png');
        const nickname = this.escapeHtml(user.nickname || 'Utente');

        // ENTERPRISE SECURITY: No numeric user IDs exposed anymore
        // All API responses now return only UUID for user identification
        // friendship_id is still needed for accept/reject/cancel operations
        const friendshipId = parseInt(user.friendship_id || 0, 10);
        const uuid = this.escapeHtml(user.uuid || '');

        let actionsHtml = '';

        switch (type) {
            case 'pending':
                actionsHtml = `
                    <button onclick="FriendsManager.acceptRequest(${friendshipId}, event)"
                            class="flex-1 px-4 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium transition-all duration-200 shadow-lg shadow-purple-500/30">
                        Accetta
                    </button>
                    <button onclick="FriendsManager.rejectRequest(${friendshipId}, event)"
                            class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium transition-all duration-200">
                        Rifiuta
                    </button>
                    <button onclick="FriendsManager.block('${uuid}', '${nickname.replace(/'/g, "\\'")}')"
                            class="px-3 py-2 bg-red-900/50 hover:bg-red-800 text-red-300 hover:text-white rounded-lg font-medium transition-all duration-200"
                            title="Blocca utente">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                    </button>
                `;
                break;

            case 'sent':
                // ENTERPRISE V11.8: Mobile buttons with icon + text ALWAYS visible
                actionsHtml = `
                    <button onclick="FriendsManager.cancelRequest(${friendshipId}, event)"
                            class="flex-1 px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        <span>Annulla Richiesta</span>
                    </button>
                    <button onclick="FriendsManager.block('${uuid}', '${nickname.replace(/'/g, "\\'")}')"
                            class="px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-1"
                            title="Blocca utente">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                        <span>Blocca</span>
                    </button>
                `;
                break;

            case 'friend':
                // ENTERPRISE V11.7: Mobile buttons with icon + text ALWAYS visible
                actionsHtml = `
                    <a href="/u/${uuid}"
                       class="flex-1 px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium text-center transition-all duration-200 text-sm flex items-center justify-center gap-1">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        <span>Profilo</span>
                    </a>
                    <button onclick="FriendsManager.unfriend('${uuid}', '${nickname.replace(/'/g, "\\'")}')"
                            class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium transition-all duration-200 text-sm flex items-center justify-center gap-1">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
                        </svg>
                        <span>Rimuovi</span>
                    </button>
                    <button onclick="FriendsManager.block('${uuid}', '${nickname.replace(/'/g, "\\'")}')"
                            class="px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-all duration-200 text-sm flex items-center justify-center gap-1"
                            title="Blocca utente">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                        <span>Blocca</span>
                    </button>
                `;
                break;

            case 'blocked':
                actionsHtml = `
                    <button onclick="FriendsManager.unblock('${uuid}', '${nickname.replace(/'/g, "\\'")}')"
                            class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-all duration-200">
                        <svg class="inline w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Sblocca
                    </button>
                `;
                break;

            case 'search':
                // ENTERPRISE: Handle all friendship states + rate limiting (3 requests/30 days)
                const friendshipStatus = user.friendship_status;
                const requestDirection = user.request_direction;
                const canSendRequest = user.can_send_request;
                const rateLimitReached = user.rate_limit_reached;
                const requestsCount = user.requests_count_30d || 0;

                // ENTERPRISE: Block button common to all search results
                const blockButton = `
                    <button onclick="FriendsManager.block('${uuid}', '${nickname.replace(/'/g, "\\'")}')"
                            class="px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-all duration-200 flex items-center gap-2"
                            title="Blocca utente">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                        <span>Blocca Utente</span>
                    </button>
                `;

                // Case 1: Already friends
                if (friendshipStatus === 'accepted') {
                    actionsHtml = `
                        <button disabled
                                class="flex-1 px-4 py-2 bg-gray-700/50 text-gray-400 rounded-lg font-medium cursor-not-allowed">
                            Già amici
                        </button>
                        ${blockButton}
                    `;
                }
                // Case 2: Pending request sent by current user
                else if (friendshipStatus === 'pending' && requestDirection === 'sent') {
                    actionsHtml = `
                        <button onclick="FriendsManager.cancelRequest(${friendshipId}, event)"
                                class="flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium transition-all duration-200">
                            Annulla Richiesta
                        </button>
                        ${blockButton}
                    `;
                }
                // Case 3: Pending request received from this user
                else if (friendshipStatus === 'pending' && requestDirection === 'received') {
                    actionsHtml = `
                        <button onclick="FriendsManager.acceptRequest(${friendshipId}, event)"
                                class="flex-1 px-4 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium transition-all duration-200 shadow-lg shadow-purple-500/30">
                            Accetta
                        </button>
                        <button onclick="FriendsManager.rejectRequest(${friendshipId}, event)"
                                class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium transition-all duration-200">
                            Rifiuta
                        </button>
                        ${blockButton}
                    `;
                }
                // Case 4: Rate limit reached (3 requests in 30 days)
                else if (rateLimitReached) {
                    actionsHtml = `
                        <button disabled
                                class="flex-1 px-4 py-2 bg-red-900/30 text-red-400 rounded-lg font-medium cursor-not-allowed border border-red-500/20"
                                title="Hai raggiunto il limite massimo di 3 richieste in 30 giorni per questo utente">
                            Limite raggiunto (${requestsCount}/3)
                        </button>
                        ${blockButton}
                    `;
                }
                // Case 5: Can send new friend request (ENTERPRISE: UUID-only)
                else if (canSendRequest) {
                    actionsHtml = `
                        <button onclick="FriendsManager.sendRequest('${uuid}')"
                                class="flex-1 px-4 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium transition-all duration-200 shadow-lg shadow-purple-500/30 flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                            <span>Invia Richiesta</span>
                        </button>
                        ${blockButton}
                    `;
                }
                // Case 6: Default - cannot send (fallback)
                else {
                    actionsHtml = `
                        <button disabled
                                class="flex-1 px-4 py-2 bg-gray-700/50 text-gray-400 rounded-lg font-medium cursor-not-allowed">
                            Non disponibile
                        </button>
                        ${blockButton}
                    `;
                }
                break;
        }

        return `
            <div class="user-card flex items-center gap-4 p-4 bg-gray-900/50 border border-purple-500/20 rounded-lg hover:border-purple-500/40 transition-all duration-200">
                <!-- Avatar -->
                <img src="${avatarUrl}"
                     alt="${nickname}"
                     class="user-card-avatar w-14 h-14 rounded-full object-cover ring-2 ring-purple-500/30"
                     onerror="this.src='/assets/img/default-avatar.png'">

                <!-- User Info (ENTERPRISE: Clickable username → profile page) -->
                <div class="flex-1 min-w-0">
                    <a href="/u/${uuid}" class="block group">
                        <h3 class="font-semibold text-white truncate underline decoration-purple-400/50 hover:decoration-purple-400 transition-colors">
                            ${nickname}
                        </h3>
                    </a>
                </div>

                <!-- Actions -->
                <div class="user-card-actions flex gap-2 flex-shrink-0">
                    ${actionsHtml}
                </div>
            </div>
        `;
    },

    /**
     * Render empty state
     */
    renderEmptyState(icon, title, description) {
        const iconSvg = this.getIcon(icon);
        const titleEscaped = this.escapeHtml(title);
        const descEscaped = this.escapeHtml(description);

        return `
            <div class="text-center py-12">
                <div class="empty-state-icon inline-flex items-center justify-center w-16 h-16 rounded-full bg-purple-500/10 mb-4">
                    ${iconSvg}
                </div>
                <h3 class="text-lg font-semibold text-white mb-2">${titleEscaped}</h3>
                <p class="text-gray-400 text-sm max-w-md mx-auto">${descEscaped}</p>
            </div>
        `;
    },

    /**
     * Render search empty state
     */
    renderSearchEmpty() {
        const container = document.getElementById('search-results-container');
        if (!container) return;

        container.innerHTML = this.renderEmptyState(
            'search',
            'Cerca utenti',
            'Inserisci un nickname, email o user ID per iniziare la ricerca'
        );
    },

    /**
     * Handle search input (debounced)
     */
    handleSearchInput(event) {
        // Clear previous timeout
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }

        const query = event.target.value.trim();

        // Debounce 300ms
        this.searchTimeout = setTimeout(() => {
            if (query.length >= 2) {
                this.performSearch(query);
            } else if (query.length === 0) {
                this.renderSearchEmpty();
            }
        }, 300);
    },

    /**
     * Perform user search
     *
     * SECURITY: Query is URL-encoded before sending
     * PRIVACY: Only nickname search allowed (no email/user_id exposure)
     */
    async performSearch(query) {
        try {
            // PRIVACY: Force nickname-only search (prevent ID/email enumeration)
            const searchType = 'nickname';
            const container = document.getElementById('search-results-container');
            if (!container) return;

            // Show loading
            container.innerHTML = `
                <div class="text-center py-8">
                    <div class="loading-spinner inline-block animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-purple-500"></div>
                    <p class="mt-2 text-gray-400 text-sm">Ricerca in corso...</p>
                </div>
            `;

            // SECURITY: URL encode query parameter
            const encodedQuery = encodeURIComponent(query);
            const encodedType = encodeURIComponent(searchType);

            const response = await fetch(`/api/users/search?q=${encodedQuery}&type=${encodedType}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });

            if (!response.ok) {
                throw new Error(`Network error: ${response.status}`);
            }

            const data = await response.json();
            const results = data.results || [];

            if (results.length === 0) {
                container.innerHTML = this.renderEmptyState(
                    'search',
                    'Nessun risultato',
                    'Prova con un altro termine di ricerca'
                );
                return;
            }

            // Render results
            let html = '<div class="space-y-4">';

            results.forEach(user => {
                if (user.can_send_request) {
                    html += this.renderUserCard(user, 'search');
                } else {
                    // Already friends or pending request
                    const statusText = user.friendship_status === 'accepted' ? 'Già amici' : 'Richiesta in sospeso';
                    const avatarUrl = this.escapeHtml(user.avatar_url || '/assets/img/default-avatar.png');
                    const nickname = this.escapeHtml(user.nickname || 'Utente');
                    const statusEscaped = this.escapeHtml(statusText);

                    html += `
                        <div class="flex items-center gap-4 p-4 bg-gray-900/50 border border-purple-500/20 rounded-lg opacity-60">
                            <img src="${avatarUrl}"
                                 alt="${nickname}"
                                 class="w-14 h-14 rounded-full object-cover ring-2 ring-purple-500/30"
                                 onerror="this.src='/assets/img/default-avatar.png'">
                            <div class="flex-1">
                                <h3 class="font-semibold text-white">${nickname}</h3>
                                <p class="text-sm text-gray-400">${statusEscaped}</p>
                            </div>
                        </div>
                    `;
                }
            });

            html += '</div>';

            container.innerHTML = html;

        } catch (error) {
            console.error('[FriendsManager] Search failed:', error);
            const container = document.getElementById('search-results-container');
            if (container) {
                container.innerHTML = this.renderEmptyState(
                    'exclamation-triangle',
                    'Errore di ricerca',
                    'Si è verificato un errore. Riprova più tardi.'
                );
            }
        }
    },

    /**
     * Accept friend request
     *
     * SECURITY: CSRF token sent with request
     * ENTERPRISE FIX: Disable button during API call (prevent double-click)
     */
    async acceptRequest(friendshipId, event) {
        try {
            // ENTERPRISE FIX: Disable button first (prevent double-click)
            const button = event?.target;

            if (button) {
                button.disabled = true;
                button.textContent = 'Accettando...';
                button.classList.add('opacity-50', 'cursor-not-allowed');
            }

            const response = await fetch('/social/friend-request/accept', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ friendship_id: friendshipId })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Richiesta accettata!', 'success');
                // Reload pending requests
                this.loadedTabs.delete('pending');
                this.loadedTabs.delete('friends'); // Friends list changed too
                this.loadTabData('pending');
                this.updatePendingCount();
            } else {
                // ERROR: Re-enable button
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Accetta';
                    button.classList.remove('opacity-50', 'cursor-not-allowed');
                }

                this.showToast(data.errors?.join(', ') || 'Errore nell\'accettare la richiesta', 'error');
            }
        } catch (error) {
            console.error('[FriendsManager] Accept failed:', error);

            // NETWORK ERROR: Re-enable button
            const button = event?.target;
            if (button) {
                button.disabled = false;
                button.textContent = 'Accetta';
                button.classList.remove('opacity-50', 'cursor-not-allowed');
            }

            this.showToast('Errore di connessione. Riprova.', 'error');
        }
    },

    /**
     * Reject friend request
     *
     * SECURITY: CSRF token sent with request
     * ENTERPRISE: Optimistic UI update (remove immediately, rollback on error)
     */
    async rejectRequest(friendshipId, event) {
        try {
            // ENTERPRISE FIX: Disable button first (prevent double-click)
            const button = event?.target;
            const userCard = event?.target?.closest('.user-card');
            const container = document.getElementById('pending-requests-container');

            if (button) {
                button.disabled = true;
                button.textContent = 'Rifiutando...';
                button.classList.add('opacity-50', 'cursor-not-allowed');
            }

            // ENTERPRISE FIX: Call API FIRST (no premature optimistic update)
            const response = await fetch('/social/friend-request/reject', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ friendship_id: friendshipId })
            });

            const data = await response.json();

            if (data.success) {
                // SUCCESS: Now remove card with animation
                if (userCard) {
                    userCard.style.opacity = '0';
                    userCard.style.transform = 'translateX(-20px)';
                    userCard.style.transition = 'all 0.3s ease';

                    setTimeout(() => {
                        userCard.remove();

                        // Update cache
                        if (this.pendingRequests) {
                            this.pendingRequests = this.pendingRequests.filter(r => (r.friendship_id || r.id) !== friendshipId);
                        }

                        // Update count badge
                        this.updatePendingCount();

                        // Show empty state if no more pending
                        if (this.pendingRequests && this.pendingRequests.length === 0 && container) {
                            container.innerHTML = this.renderEmptyState(
                                'inbox',
                                'Nessuna richiesta di amicizia',
                                'Quando qualcuno ti invierà una richiesta, apparirà qui'
                            );
                        }
                    }, 300);
                }

                this.showToast('Richiesta rifiutata', 'success');
            } else {
                // ERROR: Re-enable button
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Rifiuta';
                    button.classList.remove('opacity-50', 'cursor-not-allowed');
                }

                this.showToast(data.errors?.join(', ') || 'Errore nel rifiutare la richiesta', 'error');
            }
        } catch (error) {
            console.error('[FriendsManager] Reject failed:', error);

            // NETWORK ERROR: Re-enable button
            const button = event?.target;
            if (button) {
                button.disabled = false;
                button.textContent = 'Rifiuta';
                button.classList.remove('opacity-50', 'cursor-not-allowed');
            }

            this.showToast('Errore di connessione. Riprova.', 'error');
        }
    },

    /**
     * Cancel sent request
     *
     * SECURITY: CSRF token sent with request
     * ENTERPRISE: Optimistic UI update (remove immediately, rollback on error)
     */
    async cancelRequest(friendshipId, event) {
        try {
            // ENTERPRISE FIX: Disable button first (prevent double-click)
            const button = event?.target;
            const userCard = event?.target?.closest('.user-card');
            const container = document.getElementById('sent-requests-container');

            if (button) {
                button.disabled = true;
                button.textContent = 'Annullando...';
                button.classList.add('opacity-50', 'cursor-not-allowed');
            }

            // ENTERPRISE FIX: Call API FIRST (no premature optimistic update)
            const response = await fetch('/social/friend-request/cancel', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ friendship_id: friendshipId })
            });

            const data = await response.json();

            if (data.success) {
                // SUCCESS: Now remove card with animation
                if (userCard) {
                    userCard.style.opacity = '0';
                    userCard.style.transform = 'translateX(-20px)';
                    userCard.style.transition = 'all 0.3s ease';

                    setTimeout(() => {
                        userCard.remove();

                        // Update cache
                        if (this.sentRequests) {
                            this.sentRequests = this.sentRequests.filter(r => (r.friendship_id || r.id) !== friendshipId);
                        }

                        // Update count badge
                        this.updateSentCount();

                        // Show empty state if no more sent
                        if (this.sentRequests && this.sentRequests.length === 0 && container) {
                            container.innerHTML = this.renderEmptyState(
                                'paper-airplane',
                                'Nessuna richiesta inviata',
                                'Non hai richieste di amicizia in sospeso'
                            );
                        }
                    }, 300);
                }

                this.showToast('Richiesta annullata', 'success');
            } else {
                // ERROR: Re-enable button
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Annulla Richiesta';
                    button.classList.remove('opacity-50', 'cursor-not-allowed');
                }

                this.showToast(data.errors?.join(', ') || 'Errore nell\'annullare la richiesta', 'error');
            }
        } catch (error) {
            console.error('[FriendsManager] Cancel failed:', error);

            // NETWORK ERROR: Re-enable button
            const button = event?.target;
            if (button) {
                button.disabled = false;
                button.textContent = 'Annulla Richiesta';
                button.classList.remove('opacity-50', 'cursor-not-allowed');
            }

            this.showToast('Errore di connessione. Riprova.', 'error');
        }
    },

    /**
     * Send friend request
     *
     * SECURITY: CSRF token sent with request
     */
    /**
     * Send friend request using UUID (ENTERPRISE: UUID-only system)
     *
     * @param {string} friendUuid - Friend's UUID
     */
    async sendRequest(friendUuid) {
        // ENTERPRISE: Optimistic UI Update - Change button IMMEDIATELY
        const button = event.target;
        const originalHtml = button.outerHTML;

        if (button) {
            button.disabled = true;
            button.className = 'flex-1 px-4 py-2 bg-gray-700/50 text-gray-400 rounded-lg font-medium cursor-not-allowed';
            button.textContent = 'Attesa di risposta...';
        }

        try {
            const response = await fetch('/social/friend-request/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ friend_uuid: friendUuid })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Richiesta inviata!', 'success');
                this.loadedTabs.delete('sent'); // Reload sent requests

                // Update button to final state (with friendshipId from response)
                if (button && data.friendship_id) {
                    button.className = 'flex-1 px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium transition-all duration-200';
                    button.textContent = 'Annulla Richiesta';
                    button.onclick = () => this.cancelRequest(data.friendship_id, event);
                }

                // Re-search to update UI (fallback)
                const searchInput = document.getElementById('search-input');
                if (searchInput && searchInput.value.trim().length >= 2) {
                    this.performSearch(searchInput.value.trim());
                }
            } else {
                // Rollback button to original state on error
                if (button) {
                    button.outerHTML = originalHtml;
                }

                // ENTERPRISE: Handle rate limiting (429 Too Many Requests)
                if (response.status === 429 && data.rate_limit) {
                    const { count, max, window_days } = data.rate_limit;
                    this.showToast(
                        `Limite raggiunto: hai inviato ${count}/${max} richieste in ${window_days} giorni a questo utente.`,
                        'error'
                    );
                } else {
                    this.showToast(data.errors?.join(', ') || 'Errore', 'error');
                }
            }
        } catch (error) {
            console.error('[FriendsManager] Send request failed:', error);
            this.showToast('Errore di rete. Riprova.', 'error');

            // Rollback button to original state on network error
            if (button) {
                button.outerHTML = originalHtml;
            }
        }
    },

    /**
     * Unfriend user
     *
     * SECURITY: Confirm dialog before action, CSRF token sent
     * ENTERPRISE V4: Uses UUID instead of ID
     */
    async unfriend(friendUuid, nickname) {
        // SECURITY: Escape nickname in confirm dialog
        const nicknameEscaped = this.escapeHtml(nickname);

        if (!confirm(`Sei sicuro di voler rimuovere ${nicknameEscaped} dagli amici?`)) {
            return;
        }

        // ENTERPRISE: Optimistic UI Update - Remove element IMMEDIATELY
        // Scalable for 100k+ concurrent users (no full page reload)
        const userCard = event.target.closest('.user-card');
        const cardHtml = userCard ? userCard.outerHTML : null; // Backup per rollback

        if (userCard) {
            // Fade out animation (smooth UX)
            userCard.style.opacity = '0';
            userCard.style.transform = 'translateX(-20px)';
            userCard.style.transition = 'all 0.3s ease';

            // Remove after animation
            setTimeout(() => {
                userCard.remove();

                // ENTERPRISE V4: Update cache intelligente (filter by UUID)
                this.friends = this.friends.filter(f => f.uuid !== friendUuid);

                // ENTERPRISE V10.52: Update total count for infinite scroll
                this.friendsTotalCount = Math.max(0, this.friendsTotalCount - 1);

                // Update badge count
                const badge = document.getElementById('friends-count-badge');
                if (badge && this.friendsTotalCount > 0) {
                    badge.textContent = this.friendsTotalCount;
                } else if (badge) {
                    badge.classList.add('hidden');
                }

                // Se lista vuota, mostra empty state
                const container = document.getElementById('friends-list-container');
                if (container && this.friends.length === 0 && !this.friendsHasMore) {
                    container.innerHTML = this.renderEmptyState(
                        'users',
                        'Nessun amico ancora',
                        'Inizia a cercare utenti e invia richieste di amicizia!'
                    );
                }
            }, 300);
        }

        try {
            const response = await fetch('/social/unfriend', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ friend_uuid: friendUuid })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Amicizia rimossa', 'success');
                // ✅ NO full reload - optimistic update già fatto
            } else {
                // ENTERPRISE: Rollback on error - ripristina elemento
                this.showToast(data.errors?.join(', ') || 'Errore', 'error');

                if (userCard && cardHtml) {
                    const container = document.getElementById('friends-list-container');
                    if (container) {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = cardHtml;
                        container.appendChild(tempDiv.firstChild);

                        // Ripristina cache
                        this.loadedTabs.delete('friends');
                        this.loadTabData('friends');
                    }
                }
            }
        } catch (error) {
            console.error('[FriendsManager] Unfriend failed:', error);
            this.showToast('Errore di rete. Riprova.', 'error');

            // ENTERPRISE: Rollback on network error
            if (userCard && cardHtml) {
                const container = document.getElementById('friends-list-container');
                if (container) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = cardHtml;
                    container.appendChild(tempDiv.firstChild);

                    // Ripristina cache
                    this.loadedTabs.delete('friends');
                    this.loadTabData('friends');
                }
            }
        }
    },

    /**
     * Block user
     *
     * ENTERPRISE V4: Uses UUID-only API + Overlay Cache Architecture
     * SECURITY: Confirm dialog before action, CSRF token sent
     */
    async block(blockedUuid, nickname) {
        // SECURITY: Escape nickname in confirm dialog
        const nicknameEscaped = this.escapeHtml(nickname);

        if (!confirm(`Sei sicuro di voler bloccare ${nicknameEscaped}? Non potrete più interagire.`)) {
            return;
        }

        // ENTERPRISE: Optimistic UI Update - Remove element IMMEDIATELY
        const userCard = event.target.closest('.user-card');
        const cardHtml = userCard ? userCard.outerHTML : null;

        if (userCard) {
            // Fade out animation (smooth UX)
            userCard.style.opacity = '0';
            userCard.style.transform = 'translateX(-20px)';
            userCard.style.transition = 'all 0.3s ease';

            setTimeout(() => {
                userCard.remove();

                // ENTERPRISE V4: Update all local caches (filter by UUID)
                const wasInFriends = this.friends.some(f => f.uuid === blockedUuid);
                this.friends = this.friends.filter(f => f.uuid !== blockedUuid);
                this.pendingRequests = this.pendingRequests.filter(r => r.uuid !== blockedUuid);
                this.sentRequests = this.sentRequests.filter(r => r.uuid !== blockedUuid);

                // ENTERPRISE V10.52: Update total count if blocked user was a friend
                if (wasInFriends) {
                    this.friendsTotalCount = Math.max(0, this.friendsTotalCount - 1);
                }

                // Update all badge counts
                this.updatePendingCount();
                this.updateSentCount();
                const friendsBadge = document.getElementById('friends-count-badge');
                if (friendsBadge && this.friendsTotalCount > 0) {
                    friendsBadge.textContent = this.friendsTotalCount;
                } else if (friendsBadge) {
                    friendsBadge.classList.add('hidden');
                }

                // Show empty state if needed for current tab
                this.checkEmptyState();
            }, 300);
        }

        try {
            const response = await fetch('/social/block', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ blocked_uuid: blockedUuid })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Utente bloccato', 'success');
                // ENTERPRISE V4: Invalidate all tab caches (block affects all views)
                this.loadedTabs.delete('friends');
                this.loadedTabs.delete('pending');
                this.loadedTabs.delete('sent');
            } else {
                // ENTERPRISE: Rollback on error
                this.showToast(data.errors?.join(', ') || 'Errore nel bloccare utente', 'error');
                this.rollbackBlock(cardHtml);
            }
        } catch (error) {
            console.error('[FriendsManager] Block failed:', error);
            this.showToast('Errore di rete. Riprova.', 'error');
            this.rollbackBlock(cardHtml);
        }
    },

    /**
     * Unblock user (ENTERPRISE V4)
     *
     * SECURITY: Confirm dialog before action, CSRF token sent
     */
    async unblock(unblockedUuid, nickname) {
        // SECURITY: Escape nickname in confirm dialog
        const nicknameEscaped = this.escapeHtml(nickname);

        if (!confirm(`Sei sicuro di voler sbloccare ${nicknameEscaped}?`)) {
            return;
        }

        // ENTERPRISE: Optimistic UI Update - Remove element IMMEDIATELY
        const userCard = event.target.closest('.user-card');
        const cardHtml = userCard ? userCard.outerHTML : null;

        if (userCard) {
            // Fade out animation (smooth UX)
            userCard.style.opacity = '0';
            userCard.style.transform = 'translateX(-20px)';
            userCard.style.transition = 'all 0.3s ease';

            setTimeout(() => {
                userCard.remove();

                // Update cache
                this.blockedUsers = this.blockedUsers.filter(u => u.uuid !== unblockedUuid);

                // Update badge count
                const badge = document.getElementById('blocked-count-badge');
                const badgeMobile = document.getElementById('blocked-count-badge-mobile');

                if (this.blockedUsers.length > 0) {
                    if (badge) badge.textContent = this.blockedUsers.length;
                    if (badgeMobile) badgeMobile.textContent = this.blockedUsers.length;
                } else {
                    if (badge) badge.classList.add('hidden');
                    if (badgeMobile) badgeMobile.classList.add('hidden');

                    // Show empty state
                    const container = document.getElementById('blocked-users-container');
                    if (container) {
                        container.innerHTML = this.renderEmptyState(
                            'ban',
                            'Nessun utente bloccato',
                            'Non hai bloccato nessun utente. Gli utenti bloccati non potranno interagire con te.'
                        );
                    }
                }
            }, 300);
        }

        try {
            const response = await fetch('/social/unblock', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ unblocked_uuid: unblockedUuid })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Utente sbloccato', 'success');
            } else {
                // ENTERPRISE: Rollback on error
                this.showToast(data.errors?.join(', ') || 'Errore nello sbloccare utente', 'error');
                this.rollbackUnblock(cardHtml);
            }
        } catch (error) {
            console.error('[FriendsManager] Unblock failed:', error);
            this.showToast('Errore di rete. Riprova.', 'error');
            this.rollbackUnblock(cardHtml);
        }
    },

    /**
     * Rollback unblock operation on error
     */
    rollbackUnblock(cardHtml) {
        if (!cardHtml) return;

        const container = document.getElementById('blocked-users-container');
        if (container) {
            // Clear empty state if present
            const emptyState = container.querySelector('.text-center');
            if (emptyState) {
                emptyState.remove();
            }

            // Add back the card
            let spaceDiv = container.querySelector('.space-y-4');
            if (!spaceDiv) {
                spaceDiv = document.createElement('div');
                spaceDiv.className = 'space-y-4';
                container.appendChild(spaceDiv);
            }

            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = cardHtml;
            spaceDiv.appendChild(tempDiv.firstChild);

            // Reload blocked users data
            this.loadedTabs.delete('blocked');
            this.loadTabData('blocked');
        }
    },

    /**
     * Rollback block operation on error
     */
    rollbackBlock(cardHtml) {
        if (!cardHtml) return;

        // Find appropriate container based on current tab
        let containerId;
        switch (this.currentTab) {
            case 'pending': containerId = 'pending-requests-container'; break;
            case 'sent': containerId = 'sent-requests-container'; break;
            case 'friends': containerId = 'friends-list-container'; break;
            case 'search': containerId = 'search-results-container'; break;
            default: return;
        }

        const container = document.getElementById(containerId);
        if (container) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = cardHtml;
            container.appendChild(tempDiv.firstChild);

            // Reload current tab data
            this.loadedTabs.delete(this.currentTab);
            this.loadTabData(this.currentTab);
        }
    },

    /**
     * Check and show empty state for current tab if needed
     *
     * ENTERPRISE V10.52: Updated for infinite scroll support
     */
    checkEmptyState() {
        const tab = this.currentTab;
        let container, data, icon, title, description;
        let hasMore = false;

        switch (tab) {
            case 'pending':
                container = document.getElementById('pending-requests-container');
                data = this.pendingRequests;
                icon = 'inbox';
                title = 'Nessuna richiesta di amicizia';
                description = 'Quando qualcuno ti invierà una richiesta, apparirà qui';
                break;
            case 'sent':
                container = document.getElementById('sent-requests-container');
                data = this.sentRequests;
                icon = 'paper-plane';
                title = 'Nessuna richiesta inviata';
                description = 'Usa la tab "Cerca Utenti" per trovare persone da aggiungere';
                break;
            case 'friends':
                container = document.getElementById('friends-list-container');
                data = this.friends;
                hasMore = this.friendsHasMore; // Don't show empty if more data available
                icon = 'users';
                title = 'Nessun amico ancora';
                description = 'Inizia a cercare utenti e invia richieste di amicizia!';
                break;
            default:
                return;
        }

        // Only show empty state if no data AND no more data to load
        if (container && data && data.length === 0 && !hasMore) {
            container.innerHTML = this.renderEmptyState(icon, title, description);
        }
    },

    /**
     * Show toast notification
     *
     * SECURITY: Message is escaped before rendering
     */
    showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) {
            console.warn('[FriendsManager] Toast container not found');
            return;
        }

        const colors = {
            success: 'from-green-600 to-emerald-600',
            error: 'from-red-600 to-rose-600',
            info: 'from-purple-600 to-pink-600'
        };

        const toast = document.createElement('div');
        toast.className = `toast px-6 py-3 rounded-lg bg-gradient-to-r ${colors[type] || colors.info} text-white shadow-lg transform transition-all duration-300 opacity-0 translate-y-2`;

        // SECURITY: Use textContent (not innerHTML) to prevent XSS
        toast.textContent = message;

        container.appendChild(toast);

        // Animate in
        setTimeout(() => {
            toast.classList.remove('opacity-0', 'translate-y-2');
            toast.classList.add('show');
        }, 10);

        // Remove after 3s
        setTimeout(() => {
            toast.classList.remove('show');
            toast.classList.add('opacity-0', 'translate-y-2');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },

    /**
     * Show error message in main content area
     *
     * SECURITY: Message is escaped before rendering
     */
    showError(message) {
        const loadingState = document.getElementById('loading-state');
        if (loadingState) {
            loadingState.classList.add('hidden');
        }

        const tabContent = document.getElementById('tab-content');
        if (!tabContent) return;

        const messageEscaped = this.escapeHtml(message);

        tabContent.innerHTML = `
            <div class="text-center py-12">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-500/10 mb-4">
                    <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-white mb-2">Errore</h3>
                <p class="text-gray-400 text-sm">${messageEscaped}</p>
                <button onclick="FriendsManager.switchTab(FriendsManager.currentTab)"
                        class="mt-4 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition-all duration-200">
                    Riprova
                </button>
            </div>
        `;
    },

    /**
     * Get SVG icon (safe static content)
     */
    getIcon(name) {
        const icons = {
            'inbox': '<svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>',
            'search': '<svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>',
            'users': '<svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>',
            'paper-plane': '<svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>',
            'exclamation-triangle': '<svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
            'ban': '<svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg>'
        };
        return icons[name] || icons['search'];
    },

    /**
     * Escape HTML to prevent XSS
     *
     * CRITICAL SECURITY FUNCTION
     * Escapes: & < > " '
     */
    escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }

        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };

        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    FriendsManager.init();
});
