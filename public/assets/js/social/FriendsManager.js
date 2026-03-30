/**
 * Friends Manager - Enterprise Galaxy
 *
 * ARCHITETTURA ENTERPRISE:
 * - Class-based JavaScript (ES6+)
 * - API-driven data loading
 * - Optimistic UI updates
 * - Error handling & retry logic
 * - Performance: Debounced search, efficient DOM manipulation
 *
 * FEATURES:
 * - Tab navigation (Pending, Sent, Friends, Search)
 * - Real-time friend search
 * - Accept/Reject friend requests
 * - Cancel sent requests
 * - Unfriend & Block actions
 * - ENTERPRISE: Clickable usernames → /u/{uuid}
 *
 * @package need2talk/Social
 * @version 1.0.0
 */

class FriendsManager {
    constructor() {
        this.currentTab = 'pending';
        this.searchTimeout = null;
        this.searchDebounceMs = 300; // 300ms debounce for search

        this.init();
    }

    /**
     * Initialize the Friends Manager
     * ENTERPRISE: Setup event listeners, load initial data
     *
     * ENTERPRISE V4.9 (2025-11-30): Read ?tab= from URL for notification navigation
     */
    init() {
        // ENTERPRISE V4.9: Check URL for tab parameter (from notification navigation)
        const urlParams = new URLSearchParams(window.location.search);
        const tabFromUrl = urlParams.get('tab');

        // Map URL tab values to internal tab names
        const tabMapping = {
            'requests': 'pending',  // friend_request notification
            'pending': 'pending',
            'friends': 'friends',   // friend_accepted notification
            'sent': 'sent',
            'search': 'search',
            'blocked': 'blocked'
        };

        const initialTab = tabMapping[tabFromUrl] || 'pending';
        this.switchTab(initialTab);
    }

    /**
     * Switch between tabs (Pending, Sent, Friends, Search)
     * ENTERPRISE: Optimistic UI update with loading states
     *
     * @param {string} tabName - Tab name: pending|sent|friends|search
     */
    switchTab(tabName) {

        // Update tab buttons state
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('active', 'bg-purple-600', 'text-white');
            btn.classList.add('bg-gray-800', 'text-gray-400', 'hover:bg-gray-700');
        });

        const activeButton = document.querySelector(`[onclick="FriendsManager.switchTab('${tabName}')"]`);
        if (activeButton) {
            activeButton.classList.remove('bg-gray-800', 'text-gray-400', 'hover:bg-gray-700');
            activeButton.classList.add('active', 'bg-purple-600', 'text-white');
        }

        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });

        // Show selected tab content
        const selectedContent = document.getElementById(`content-${tabName}`);
        if (selectedContent) {
            selectedContent.classList.remove('hidden');
        }

        // Load data for the selected tab
        this.currentTab = tabName;
        this.loadTabData(tabName);
    }

    /**
     * Load data for a specific tab
     * ENTERPRISE: API call with error handling & retry
     *
     * @param {string} tabName - Tab name
     */
    async loadTabData(tabName) {
        try {
            // Show loading state
            const containerId = `${tabName}-list`;
            const container = document.getElementById(containerId);

            if (container) {
                container.innerHTML = '<div class="text-center py-8 text-gray-400">Caricamento...</div>';
            }

            // API endpoint mapping
            const endpoints = {
                'pending': '/social/friend-requests/pending',
                'sent': '/social/friend-requests/sent',
                'friends': '/social/friends/list'
            };

            // Search tab doesn't auto-load
            if (tabName === 'search') {
                const searchContainer = document.getElementById('search-results-container');
                if (searchContainer) {
                    searchContainer.innerHTML = '<div class="text-center py-8 text-gray-500">Cerca un utente per nickname</div>';
                }
                return;
            }

            const endpoint = endpoints[tabName];
            if (!endpoint) {
                console.warn(`[FriendsManager] No endpoint for tab: ${tabName}`);
                return;
            }

            // Fetch data from API
            const response = await fetch(endpoint, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.success) {
                this.renderTabData(tabName, data.data || []);
            } else {
                throw new Error(data.errors?.join(', ') || 'Failed to load data');
            }

        } catch (error) {
            console.error(`[FriendsManager] Error loading ${tabName} data:`, error);

            const containerId = `${tabName}-list`;
            const container = document.getElementById(containerId);

            if (container) {
                container.innerHTML = `
                    <div class="text-center py-8 text-red-400">
                        <p class="mb-4">Errore nel caricamento dei dati</p>
                        <button onclick="FriendsManager.loadTabData('${tabName}')"
                                class="px-4 py-2 bg-purple-600 hover:bg-purple-700 rounded-lg transition-colors">
                            Riprova
                        </button>
                    </div>
                `;
            }
        }
    }

    /**
     * Render data for a specific tab
     * ENTERPRISE: Efficient DOM manipulation, clickable usernames
     *
     * @param {string} tabName - Tab name
     * @param {Array} data - Array of users/requests
     */
    renderTabData(tabName, data) {
        const containerId = `${tabName}-list`;
        const container = document.getElementById(containerId);

        if (!container) {
            console.warn(`[FriendsManager] Container not found: ${containerId}`);
            return;
        }

        // Empty state
        if (!data || data.length === 0) {
            const emptyMessages = {
                'pending': 'Nessuna richiesta di amicizia in arrivo',
                'sent': 'Nessuna richiesta inviata',
                'friends': 'Non hai ancora amici'
            };

            container.innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <svg class="w-16 h-16 mx-auto mb-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <p>${emptyMessages[tabName] || 'Nessun risultato'}</p>
                </div>
            `;
            return;
        }

        // Render based on tab type
        let html = '';

        data.forEach(item => {
            if (tabName === 'pending') {
                html += this.renderPendingRequest(item);
            } else if (tabName === 'sent') {
                html += this.renderSentRequest(item);
            } else if (tabName === 'friends') {
                html += this.renderFriend(item);
            }
        });

        container.innerHTML = html;
    }

    /**
     * Render a pending friend request
     * ENTERPRISE: Clickable username, accept/reject buttons
     *
     * @param {Object} request - Request data {id, uuid, nickname, avatar_url, created_at}
     * @return {string} HTML string
     */
    renderPendingRequest(request) {
        const avatar = request.avatar_url || '/assets/img/default-avatar.png';
        const nickname = this.escapeHtml(request.nickname || 'Utente');
        const uuid = request.uuid;
        const requestId = request.id;

        return `
            <div class="flex items-center justify-between p-4 bg-gray-800/50 rounded-lg hover:bg-gray-800 transition-colors">
                <div class="flex items-center space-x-4">
                    <img src="${avatar}"
                         alt="${nickname}"
                         class="w-12 h-12 rounded-full object-cover"
                         onerror="this.src='/assets/img/default-avatar.png'">
                    <div>
                        <!-- ENTERPRISE: Clickable username (underlined to indicate link) -->
                        <a href="/u/${uuid}" class="font-medium text-white hover:text-purple-400 transition-colors underline">
                            ${nickname}
                        </a>
                        <p class="text-sm text-gray-500">Richiesta ricevuta</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button onclick="FriendsManager.acceptRequest(${requestId})"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                        Accetta
                    </button>
                    <button onclick="FriendsManager.rejectRequest(${requestId})"
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                        Rifiuta
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Render a sent friend request
     * ENTERPRISE: Clickable username, cancel button
     *
     * @param {Object} request - Request data
     * @return {string} HTML string
     */
    renderSentRequest(request) {
        const avatar = request.avatar_url || '/assets/img/default-avatar.png';
        const nickname = this.escapeHtml(request.nickname || 'Utente');
        const uuid = request.uuid;
        const requestId = request.id;

        return `
            <div class="flex items-center justify-between p-4 bg-gray-800/50 rounded-lg hover:bg-gray-800 transition-colors">
                <div class="flex items-center space-x-4">
                    <img src="${avatar}"
                         alt="${nickname}"
                         class="w-12 h-12 rounded-full object-cover"
                         onerror="this.src='/assets/img/default-avatar.png'">
                    <div>
                        <!-- ENTERPRISE: Clickable username (underlined to indicate link) -->
                        <a href="/u/${uuid}" class="font-medium text-white hover:text-purple-400 transition-colors underline">
                            ${nickname}
                        </a>
                        <p class="text-sm text-gray-500">In attesa di risposta</p>
                    </div>
                </div>
                <button onclick="FriendsManager.cancelRequest(${requestId})"
                        class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">
                    Annulla
                </button>
            </div>
        `;
    }

    /**
     * Render a friend
     * ENTERPRISE: Clickable username, message button
     *
     * @param {Object} friend - Friend data
     * @return {string} HTML string
     */
    renderFriend(friend) {
        const avatar = friend.avatar_url || '/assets/img/default-avatar.png';
        const nickname = this.escapeHtml(friend.nickname || 'Utente');
        const uuid = friend.uuid;

        return `
            <div class="flex items-center justify-between p-4 bg-gray-800/50 rounded-lg hover:bg-gray-800 transition-colors">
                <div class="flex items-center space-x-4">
                    <img src="${avatar}"
                         alt="${nickname}"
                         class="w-12 h-12 rounded-full object-cover"
                         onerror="this.src='/assets/img/default-avatar.png'">
                    <div>
                        <!-- ENTERPRISE: Clickable username (underlined to indicate link) -->
                        <a href="/u/${uuid}" class="font-medium text-white hover:text-purple-400 transition-colors underline">
                            ${nickname}
                        </a>
                        <p class="text-sm text-gray-500">Amico</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <a href="/u/${uuid}"
                       class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors">
                        Profilo
                    </a>
                </div>
            </div>
        `;
    }

    /**
     * Handle search input with debouncing
     * ENTERPRISE: Debounced search (300ms) for performance
     *
     * @param {Event} event - Keyboard event
     */
    handleSearchInput(event) {
        const query = event.target.value.trim();

        // Clear previous timeout
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }

        // Debounce search (wait 300ms after user stops typing)
        this.searchTimeout = setTimeout(() => {
            this.performSearch(query);
        }, this.searchDebounceMs);
    }

    /**
     * Perform user search
     * ENTERPRISE: API call with error handling
     *
     * @param {string} query - Search query (nickname)
     */
    async performSearch(query) {
        const container = document.getElementById('search-results-container');

        if (!query || query.length < 2) {
            container.innerHTML = '<div class="text-center py-8 text-gray-500">Inserisci almeno 2 caratteri per cercare</div>';
            return;
        }

        try {
            // Show loading
            container.innerHTML = '<div class="text-center py-8 text-gray-400">Ricerca in corso...</div>';

            // API call
            const response = await fetch(`/social/search?q=${encodeURIComponent(query)}`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.success) {
                this.renderSearchResults(data.data || []);
            } else {
                throw new Error(data.errors?.join(', ') || 'Search failed');
            }

        } catch (error) {
            console.error('[FriendsManager] Search error:', error);
            container.innerHTML = `
                <div class="text-center py-8 text-red-400">
                    Errore nella ricerca. Riprova.
                </div>
            `;
        }
    }

    /**
     * Render search results
     * ENTERPRISE: Clickable usernames, add friend button
     *
     * @param {Array} results - Search results
     */
    renderSearchResults(results) {
        const container = document.getElementById('search-results-container');

        if (!results || results.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <svg class="w-16 h-16 mx-auto mb-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <p>Nessun utente trovato</p>
                </div>
            `;
            return;
        }

        let html = '';

        results.forEach(user => {
            const avatar = user.avatar_url || '/assets/img/default-avatar.png';
            const nickname = this.escapeHtml(user.nickname || 'Utente');
            const uuid = user.uuid;
            const friendshipStatus = user.friendship_status || 'none'; // none|pending|sent|accepted

            // Determine button based on status
            let actionButton = '';

            if (friendshipStatus === 'accepted') {
                actionButton = `<span class="px-4 py-2 bg-gray-700 text-gray-400 rounded-lg">Già amico</span>`;
            } else if (friendshipStatus === 'pending') {
                actionButton = `<span class="px-4 py-2 bg-yellow-600 text-white rounded-lg">Richiesta ricevuta</span>`;
            } else if (friendshipStatus === 'sent') {
                actionButton = `<span class="px-4 py-2 bg-gray-700 text-gray-400 rounded-lg">Richiesta inviata</span>`;
            } else {
                actionButton = `
                    <button onclick="FriendsManager.sendFriendRequest('${uuid}')"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors">
                        Aggiungi
                    </button>
                `;
            }

            html += `
                <div class="flex items-center justify-between p-4 bg-gray-800/50 rounded-lg hover:bg-gray-800 transition-colors">
                    <div class="flex items-center space-x-4">
                        <img src="${avatar}"
                             alt="${nickname}"
                             class="w-12 h-12 rounded-full object-cover"
                             onerror="this.src='/assets/img/default-avatar.png'">
                        <div>
                            <!-- ENTERPRISE: Clickable username (underlined to indicate link) -->
                            <a href="/u/${uuid}" class="font-medium text-white hover:text-purple-400 transition-colors underline">
                                ${nickname}
                            </a>
                        </div>
                    </div>
                    <div class="flex space-x-2">
                        ${actionButton}
                        <a href="/u/${uuid}"
                           class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">
                            Profilo
                        </a>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    /**
     * Accept friend request
     * ENTERPRISE: Optimistic UI update
     *
     * @param {number} requestId - Request ID
     */
    async acceptRequest(requestId) {
        try {
            const response = await fetch('/social/friend-request/accept', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ request_id: requestId })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Richiesta accettata!', 'success');
                // Reload current tab
                this.loadTabData(this.currentTab);
            } else {
                throw new Error(data.errors?.join(', ') || 'Failed to accept');
            }

        } catch (error) {
            console.error('[FriendsManager] Accept error:', error);
            this.showToast('Errore nell\'accettare la richiesta', 'error');
        }
    }

    /**
     * Reject friend request
     *
     * @param {number} requestId - Request ID
     */
    async rejectRequest(requestId) {
        try {
            const response = await fetch('/social/friend-request/reject', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ request_id: requestId })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Richiesta rifiutata', 'success');
                this.loadTabData(this.currentTab);
            } else {
                throw new Error(data.errors?.join(', ') || 'Failed to reject');
            }

        } catch (error) {
            console.error('[FriendsManager] Reject error:', error);
            this.showToast('Errore nel rifiutare la richiesta', 'error');
        }
    }

    /**
     * Cancel sent friend request
     *
     * @param {number} requestId - Request ID
     */
    async cancelRequest(requestId) {
        try {
            const response = await fetch('/social/friend-request/cancel', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ request_id: requestId })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Richiesta annullata', 'success');
                this.loadTabData(this.currentTab);
            } else {
                throw new Error(data.errors?.join(', ') || 'Failed to cancel');
            }

        } catch (error) {
            console.error('[FriendsManager] Cancel error:', error);
            this.showToast('Errore nell\'annullare la richiesta', 'error');
        }
    }

    /**
     * Send friend request from search
     *
     * @param {string} uuid - User UUID
     */
    async sendFriendRequest(uuid) {
        try {
            const response = await fetch('/social/friend-request/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ friend_uuid: uuid })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Richiesta inviata!', 'success');
                // Re-perform search to update UI
                const searchInput = document.getElementById('search-input');
                if (searchInput) {
                    this.performSearch(searchInput.value.trim());
                }
            } else {
                throw new Error(data.errors?.join(', ') || 'Failed to send');
            }

        } catch (error) {
            console.error('[FriendsManager] Send request error:', error);
            this.showToast('Errore nell\'invio della richiesta', 'error');
        }
    }

    /**
     * Show toast notification
     * ENTERPRISE: Accessible toast with auto-dismiss
     *
     * @param {string} message - Message to show
     * @param {string} type - Type: success|error|info
     */
    showToast(message, type = 'info') {
        const existingToast = document.querySelector('.friends-toast');
        if (existingToast) {
            existingToast.remove();
        }

        const colors = {
            success: 'bg-green-600 text-white',
            error: 'bg-red-600 text-white',
            info: 'bg-blue-600 text-white'
        };

        const toast = document.createElement('div');
        toast.className = `friends-toast fixed top-20 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 ${colors[type] || colors.info}`;
        toast.textContent = message;

        document.body.appendChild(toast);

        // Auto-remove after 3s
        setTimeout(() => {
            toast.style.transform = 'translateX(400px)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * Escape HTML to prevent XSS
     * ENTERPRISE: Security best practice
     *
     * @param {string} text - Text to escape
     * @return {string} Escaped text
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// ENTERPRISE: Initialize as singleton on DOM ready
const FriendsManagerInstance = new FriendsManager();
// Global alias for backward compatibility
window.FriendsManager = FriendsManagerInstance;
