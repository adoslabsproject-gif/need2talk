<?php
/**
 * NEED2TALK - NAVBAR AUTHENTICATED
 *
 * Navigation per utenti autenticati
 * Stile identico a navbar-guest (Glass-morphism design)
 * NO Alpine.js - Vanilla JavaScript
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

$currentUser = current_user() ?? null;
if (!$currentUser) {
    return;
}

// Current page detection
$currentUrl = $_SERVER['REQUEST_URI'] ?? '';
$isInFeed = strpos($currentUrl, '/feed') !== false || strpos($currentUrl, '/home') !== false;
// ENTERPRISE FIX: Match EXACT /profile or /me, NOT /u/{uuid} (friend profiles)
$isInProfile = (strpos($currentUrl, '/profile') === 0 || strpos($currentUrl, '/me') === 0);

// User data
$userName = htmlspecialchars($currentUser['nickname'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$userAvatar = htmlspecialchars(get_avatar_url($currentUser['avatar_url'] ?? null), ENT_QUOTES, 'UTF-8');
?>

<!-- NAVBAR AUTHENTICATED - Tailwind + FontAwesome -->
<header class="fixed top-0 left-0 right-0 z-50 bg-gray-900/90 backdrop-blur-lg border-b border-purple-500/20 shadow-lg shadow-purple-500/10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">

            <!-- LOGO SECTION -->
            <div class="flex items-center space-x-3">
                <a href="<?php echo url('/feed'); ?>" class="group flex items-center space-x-3">
                    <!-- Logo Container -->
                    <div class="relative w-10 h-10">
                        <div class="absolute inset-0 bg-gradient-to-br from-purple-600 to-pink-600 rounded-xl shadow-lg shadow-purple-500/30 group-hover:shadow-purple-500/50 transition-all duration-300"></div>
                        <picture>
                            <source srcset="<?php echo asset('img/logo-80-retina.webp'); ?>" type="image/webp">
                            <img src="<?php echo asset('img/logo-80-retina.png'); ?>"
                                 alt="need2talk Logo"
                                 class="absolute inset-0 w-full h-full rounded-xl object-cover"
                                 fetchpriority="high"
                                 width="40"
                                 height="40">
                        </picture>

                        <!-- Cerchi concentrici pulsanti -->
                        <div class="absolute inset-0 rounded-full border-2 border-pink-500/60 animate-ping"></div>
                        <div class="absolute -inset-1 rounded-full border border-purple-500/40 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                    </div>

                    <!-- Brand Text -->
                    <span class="text-xl font-bold bg-gradient-to-r from-purple-600 to-pink-600 bg-clip-text text-transparent">
                        need2talk
                    </span>
                </a>
            </div>

            <!-- DESKTOP NAVIGATION -->
            <nav class="hidden md:flex items-center space-x-4">
                <!-- Feed/Home -->
                <a href="<?php echo url('/feed'); ?>"
                   class="group flex items-center space-x-2 <?php echo $isInFeed ? 'text-purple-400' : 'text-gray-300 hover:text-purple-400'; ?> py-2 px-3 rounded-lg hover:bg-white/5 transition-all duration-200">
                    <?= icon('home', 'w-4 h-4') ?>
                    <span>Feed</span>
                </a>

                <!-- Profilo -->
                <a href="<?php echo url('/profile'); ?>"
                   class="group flex items-center space-x-2 <?php echo $isInProfile ? 'text-purple-400' : 'text-gray-300 hover:text-purple-400'; ?> py-2 px-3 rounded-lg hover:bg-white/5 transition-all duration-200">
                    <?= icon('user', 'w-4 h-4') ?>
                    <span>Profilo</span>
                </a>

                <!-- Amici -->
                <a href="<?php echo url('/friends'); ?>"
                   class="group flex items-center space-x-2 text-gray-300 hover:text-purple-400 py-2 px-3 rounded-lg hover:bg-white/5 transition-all duration-200">
                    <?= icon('users', 'w-4 h-4') ?>
                    <span>Amici</span>
                </a>

                <!-- Chat -->
                <a href="<?php echo url('/chat'); ?>"
                   class="group flex items-center space-x-2 <?php echo strpos($currentUrl, '/chat') !== false ? 'text-purple-400' : 'text-gray-300 hover:text-purple-400'; ?> py-2 px-3 rounded-lg hover:bg-white/5 transition-all duration-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                    </svg>
                    <span>Chat</span>
                </a>

                <!-- Anime Affini (EmoFriendly) -->
                <a href="<?php echo url('/emofriendly'); ?>"
                   class="group flex items-center space-x-2 <?php echo strpos($currentUrl, '/emofriendly') !== false ? 'text-purple-400' : 'text-gray-300 hover:text-purple-400'; ?> py-2 px-3 rounded-lg hover:bg-white/5 transition-all duration-200">
                    <span class="text-base">💜</span>
                    <span>Anime Affini</span>
                </a>

                <!-- ENTERPRISE V6.9: User Search (widened input + dropdown) -->
                <div class="relative" id="userSearchContainer">
                    <div class="relative">
                        <input type="text"
                               id="navbarSearchInput"
                               placeholder="Cerca utenti..."
                               autocomplete="off"
                               class="w-56 lg:w-80 py-2.5 pl-10 pr-4 bg-gray-800/50 border border-gray-700 rounded-full text-sm text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 focus:outline-none transition-all duration-200">
                        <!-- Search Icon -->
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <!-- Loading Spinner (hidden by default) -->
                        <div id="searchSpinner" class="absolute inset-y-0 right-0 pr-3 flex items-center hidden">
                            <div class="w-4 h-4 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
                        </div>
                    </div>

                    <!-- Search Results Dropdown (wider than input for better readability) -->
                    <div id="searchResultsDropdown"
                         class="absolute top-full left-0 mt-2 w-80 lg:w-96 bg-gray-800/95 backdrop-blur-lg rounded-xl shadow-xl shadow-purple-500/10 border border-purple-500/20 overflow-hidden z-50 hidden">
                        <!-- Results will be injected here -->
                        <div id="searchResultsList" class="max-h-96 overflow-y-auto"></div>
                        <!-- Empty state -->
                        <div id="searchEmptyState" class="px-4 py-8 text-center hidden">
                            <svg class="w-12 h-12 mx-auto text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <p class="text-gray-400 text-sm">Nessun utente trovato</p>
                        </div>
                    </div>
                </div>

            </nav>

            <!-- RIGHT SECTION: Notification Bell + User Menu -->
            <div class="flex items-center space-x-6">
                <!-- ENTERPRISE GALAXY V4: Notification Bell with Dropdown -->
                <div class="relative" id="notificationContainer">
                    <button type="button" id="notificationBellBtn"
                            class="group relative flex items-center justify-center text-gray-300 hover:text-purple-400 p-2 rounded-lg hover:bg-white/5 transition-all duration-200">
                        <?= icon('bell', 'w-5 h-5') ?>
                        <!-- Badge notifiche non lette (dinamico via JS) -->
                        <span id="notificationBadge"
                              class="absolute -top-1 -right-1 min-w-[20px] h-5 bg-red-500 rounded-full flex items-center justify-center text-xs font-bold text-white px-1 hidden">
                            0
                        </span>
                    </button>

                    <!-- Notification Dropdown Panel -->
                    <!-- ENTERPRISE V8.5: Inline styles to override any CSS conflicts (Bootstrap, etc.) -->
                    <div id="notificationDropdown"
                         style="display: none; position: fixed !important; top: 64px !important; right: 16px !important; width: 560px !important; max-width: calc(100vw - 32px) !important; z-index: 9999 !important;"
                         class="bg-gray-800/95 backdrop-blur-lg rounded-lg shadow-xl shadow-purple-500/10 border border-purple-500/20 overflow-hidden max-h-[70vh] sm:max-h-[50vh] flex flex-col">

                        <!-- Header -->
                        <div class="px-4 py-3 border-b border-purple-500/20 flex items-center justify-between bg-gray-800/50">
                            <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                                <?= icon('bell', 'w-4 h-4 text-purple-400') ?>
                                Notifiche
                            </h3>
                            <button id="markAllReadBtn"
                                    class="text-xs text-purple-400 hover:text-purple-300 transition-colors hidden">
                                Segna tutte lette
                            </button>
                        </div>

                        <!-- Notification List (scrollable) -->
                        <div id="notificationList" class="overflow-y-auto flex-1 max-h-[50vh]">
                            <!-- Loading state -->
                            <div id="notificationLoading" class="px-6 py-12 text-center">
                                <div class="inline-block w-8 h-8 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
                                <p class="text-gray-400 text-sm mt-3">Caricamento...</p>
                            </div>

                            <!-- Empty state (hidden by default) -->
                            <div id="notificationEmpty" class="px-6 py-12 text-center hidden">
                                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gradient-to-br from-purple-500/20 to-pink-500/20 flex items-center justify-center">
                                    <svg class="w-8 h-8 text-purple-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                    </svg>
                                </div>
                                <h4 class="text-white font-medium mb-1">Tutto tranquillo</h4>
                                <p class="text-gray-400 text-sm leading-relaxed">Non hai nuove notifiche.<br>Ti avviseremo quando succede qualcosa!</p>
                            </div>

                            <!-- Notifications will be inserted here -->
                        </div>

                        <!-- Footer (hidden when empty) -->
                        <div id="notificationFooter" class="px-4 py-2 border-t border-purple-500/20 bg-gray-800/50 hidden">
                            <a href="<?= url('/settings/notifications') ?>"
                               class="text-xs text-gray-400 hover:text-purple-400 transition-colors flex items-center gap-1">
                                <?= icon('cog', 'w-3 h-3') ?>
                                Impostazioni notifiche
                            </a>
                        </div>
                    </div>
                </div>

                <!-- User Menu Dropdown -->
                <div class="relative" id="userMenuContainer">
                    <button id="userMenuButton"
                            type="button"
                            class="flex items-center space-x-3 text-gray-300 hover:text-purple-400 py-2 px-3 rounded-lg hover:bg-white/5 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-purple-500/50">
                        <!-- Avatar -->
                        <img src="<?php echo $userAvatar; ?>"
                             alt="<?php echo $userName; ?>"
                             class="w-8 h-8 rounded-full object-cover ring-2 ring-purple-500/30"
                             onerror="this.src='/assets/img/default-avatar.png'">

                        <!-- Username -->
                        <span class="hidden sm:inline font-medium"><?php echo $userName; ?></span>

                        <!-- Chevron -->
                        <?= icon('chevron-down', 'w-4 h-4') ?>
                    </button>

                    <!-- Dropdown Menu -->
                    <div id="userDropdown"
                         style="display: none;"
                         class="absolute right-0 mt-2 w-56 bg-gray-800/95 backdrop-blur-lg rounded-lg shadow-xl shadow-purple-500/10 border border-purple-500/20 overflow-hidden z-50">

                        <!-- User Info Header -->
                        <div class="px-4 py-3 border-b border-purple-500/20">
                            <p class="text-sm font-medium text-white"><?php echo $userName; ?></p>
                            <p class="text-xs text-gray-400 truncate"><?php echo htmlspecialchars($currentUser['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>

                        <!-- Menu Items -->
                        <div class="py-2">
                            <?php if (!$isInProfile): ?>
                            <a href="<?php echo url('/profile'); ?>"
                               class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-white/5 hover:text-purple-400 transition-colors duration-200">
                                <?= icon('user', 'w-4 h-4') ?>
                                <span>Il Mio Profilo</span>
                            </a>
                            <?php endif; ?>

                            <?php if (!strpos($currentUrl, '/settings')): ?>
                            <a href="<?php echo url('/settings'); ?>"
                               class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-white/5 hover:text-purple-400 transition-colors duration-200">
                                <?= icon('cog', 'w-4 h-4') ?>
                                <span>Impostazioni</span>
                            </a>
                            <?php endif; ?>

                            <?php if (!strpos($currentUrl, '/friends')): ?>
                            <a href="<?php echo url('/friends'); ?>"
                               class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-white/5 hover:text-purple-400 transition-colors duration-200">
                                <?= icon('users', 'w-4 h-4') ?>
                                <span>Amici</span>
                            </a>
                            <?php endif; ?>

                            <?php if (strpos($currentUrl, '/chat') === false): ?>
                            <a href="<?php echo url('/chat'); ?>"
                               class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-white/5 hover:text-purple-400 transition-colors duration-200">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                </svg>
                                <span>Chat</span>
                            </a>
                            <?php endif; ?>

                            <?php if (strpos($currentUrl, '/emofriendly') === false): ?>
                            <a href="<?php echo url('/emofriendly'); ?>"
                               class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-white/5 hover:text-purple-400 transition-colors duration-200">
                                <span class="text-base">💜</span>
                                <span>Anime Affini</span>
                            </a>
                            <?php endif; ?>

                            <?php if (!$isInFeed): ?>
                            <a href="<?php echo url('/feed'); ?>"
                               class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-white/5 hover:text-purple-400 transition-colors duration-200">
                                <?= icon('home', 'w-4 h-4') ?>
                                <span>Feed</span>
                            </a>
                            <?php endif; ?>
                        </div>

                        <!-- Logout Section -->
                        <div class="border-t border-purple-500/20 py-2">
                            <a href="<?php echo url('/auth/logout'); ?>"
                               class="flex items-center space-x-3 px-4 py-2 text-energy-magenta hover:bg-energy-magenta/10 transition-colors duration-200">
                                <?= icon('sign-out', 'w-4 h-4') ?>
                                <span>Esci</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Vanilla JavaScript for Dropdown & Mobile Menu -->
<script nonce="<?= csp_nonce() ?>">
(function() {
    'use strict';

    /**
     * ENTERPRISE V10.8: Navbar Dropdown Manager
     * Centralized management of all navbar dropdowns with mutual exclusion
     *
     * Features:
     * - Mutual exclusion: opening one dropdown closes all others
     * - Single click-outside handler for all dropdowns
     * - Escape key to close all dropdowns
     * - Consistent state management
     */
    const NavbarDropdownManager = {
        dropdowns: new Map(), // name -> { button, panel, isOpen, onOpen?, onClose? }

        /**
         * Register a dropdown with the manager
         * @param {string} name - Unique identifier
         * @param {HTMLElement} button - Toggle button
         * @param {HTMLElement} panel - Dropdown panel
         * @param {Object} options - Optional callbacks { onOpen, onClose }
         */
        register(name, button, panel, options = {}) {
            if (!button || !panel) {
                console.warn(`[NavbarDropdownManager] Cannot register '${name}': missing elements`);
                return;
            }

            this.dropdowns.set(name, {
                button,
                panel,
                isOpen: false,
                onOpen: options.onOpen || null,
                onClose: options.onClose || null
            });

            button.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggle(name);
            });
        },

        /**
         * Toggle a dropdown (close others, toggle this one)
         * @param {string} name - Dropdown to toggle
         */
        toggle(name) {
            const dropdown = this.dropdowns.get(name);
            if (!dropdown) return;

            const wasOpen = dropdown.isOpen;

            // Close ALL dropdowns first (mutual exclusion)
            this.closeAll();

            // If it was closed, open it now
            if (!wasOpen) {
                this.open(name);
            }
        },

        /**
         * Open a specific dropdown
         * @param {string} name - Dropdown to open
         */
        open(name) {
            const dropdown = this.dropdowns.get(name);
            if (!dropdown) return;

            dropdown.isOpen = true;

            // Handle notification dropdown (uses flex display)
            if (name === 'notifications') {
                dropdown.panel.style.display = 'flex';
            } else {
                dropdown.panel.style.display = 'block';
            }

            if (dropdown.onOpen) {
                dropdown.onOpen();
            }
        },

        /**
         * Close a specific dropdown
         * @param {string} name - Dropdown to close
         */
        close(name) {
            const dropdown = this.dropdowns.get(name);
            if (!dropdown) return;

            const wasOpen = dropdown.isOpen;
            dropdown.isOpen = false;
            dropdown.panel.style.display = 'none';

            // Call onClose callback if registered and was open
            if (wasOpen && dropdown.onClose) {
                dropdown.onClose();
            }
        },

        /**
         * Check if a specific dropdown is open
         * @param {string} name - Dropdown name
         * @returns {boolean}
         */
        isOpen(name) {
            const dropdown = this.dropdowns.get(name);
            return dropdown ? dropdown.isOpen : false;
        },

        /**
         * Close all dropdowns
         */
        closeAll() {
            for (const [name, dropdown] of this.dropdowns) {
                const wasOpen = dropdown.isOpen;
                dropdown.isOpen = false;
                dropdown.panel.style.display = 'none';

                // Call onClose callback if was open
                if (wasOpen && dropdown.onClose) {
                    dropdown.onClose();
                }
            }
        },

        /**
         * Check if click is inside any dropdown
         * @param {Event} e - Click event
         * @returns {boolean}
         */
        isClickInside(e) {
            for (const [name, dropdown] of this.dropdowns) {
                if (dropdown.button.contains(e.target) || dropdown.panel.contains(e.target)) {
                    return true;
                }
            }
            return false;
        },

        /**
         * Initialize global handlers (click outside, escape key)
         */
        initGlobalHandlers() {
            // Click outside to close all
            document.addEventListener('click', (e) => {
                if (!this.isClickInside(e)) {
                    this.closeAll();
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.closeAll();
                }
            });
        }
    };

    window.NavbarDropdownManager = NavbarDropdownManager;

    function initNavbar() {
        NavbarDropdownManager.initGlobalHandlers();

        // Register User Menu Dropdown
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');
        NavbarDropdownManager.register('userMenu', userMenuButton, userDropdown);

        // Register Notification Dropdown
        const notificationBellBtn = document.getElementById('notificationBellBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        NavbarDropdownManager.register('notifications', notificationBellBtn, notificationDropdown);

        // Register Search Dropdown
        const searchInput = document.getElementById('navbarSearchInput');
        const searchDropdown = document.getElementById('searchResultsDropdown');
        if (searchInput && searchDropdown) {
            // Search uses focus/input handlers, but we register it for closeAll() to work
            NavbarDropdownManager.dropdowns.set('search', {
                button: searchInput,
                panel: searchDropdown,
                isOpen: false
            });
        }

        initNotificationSystem();
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavbar);
    } else {
        // DOM is already ready
        initNavbar();
    }

    /**
     * ENTERPRISE GALAXY V4: Notification System
     * - Fetches notifications from API
     * - Updates badge count
     * - Handles real-time WebSocket updates
     * - Renders notification list
     *
     * ENTERPRISE V10.8: Integrated with NavbarDropdownManager for mutual exclusion
     */
    function initNotificationSystem() {
        const bellBtn = document.getElementById('notificationBellBtn');
        const dropdown = document.getElementById('notificationDropdown');
        const badge = document.getElementById('notificationBadge');
        const list = document.getElementById('notificationList');
        const loading = document.getElementById('notificationLoading');
        const empty = document.getElementById('notificationEmpty');
        const footer = document.getElementById('notificationFooter');
        const markAllBtn = document.getElementById('markAllReadBtn');

        if (!bellBtn || !dropdown || !badge) return;

        let notifications = [];
        let unreadCount = 0;
        let isLoaded = false;

        // ENTERPRISE V10.8: Register callbacks with NavbarDropdownManager
        // The click handlers are managed by the manager, we just respond to open/close events
        const notifDropdown = NavbarDropdownManager.dropdowns.get('notifications');
        if (notifDropdown) {
            notifDropdown.onOpen = function() {
                if (!isLoaded) {
                    loadNotifications();
                } else {
                    // Re-render with in-memory data to reflect read status changes
                    renderNotifications();
                }
            };
            notifDropdown.onClose = function() {
                // Can add cleanup logic here if needed
            };
        }

        // Mark all as read
        if (markAllBtn) {
            markAllBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                e.stopPropagation();
                await markAllAsRead();
            });
        }

        // Fetch unread count on page load (lightweight)
        fetchUnreadCount();

        // Setup WebSocket listeners for real-time updates
        setupWebSocketListeners();

        // Polling fallback (every 60s regardless - WebSocket provides real-time, polling ensures consistency)
        setInterval(() => {
            fetchUnreadCount();
        }, 60000); // Every 60 seconds

        async function fetchUnreadCount() {
            try {
                const response = await fetch('/api/notifications/unread-count', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });

                if (response.ok) {
                    const data = await response.json();
                    if (data.success) {
                        updateBadge(data.data.unread_count);
                    }
                } else if (response.status === 401) {
                    window.location.href = '/login?expired=1';
                }
            } catch (error) {
                // Silent fail - will retry on next poll
            }
        }

        /**
         * Load full notification list
         */
        async function loadNotifications() {
            try {
                loading.classList.remove('hidden');
                empty.classList.add('hidden');

                const response = await fetch('/api/notifications?limit=20', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });

                if (response.status === 401) {
                    window.location.href = '/login?expired=1';
                    return;
                }

                if (!response.ok) throw new Error('Failed to load notifications');

                const data = await response.json();
                if (data.success) {
                    notifications = data.data.notifications || [];
                    unreadCount = data.data.unread_count || 0;
                    isLoaded = true;

                    renderNotifications();
                    updateBadge(unreadCount);

                    // Show/hide mark all button
                    if (markAllBtn) {
                        markAllBtn.classList.toggle('hidden', unreadCount === 0);
                    }
                }
            } catch (error) {
                console.warn('[Notifications] Failed to load notifications:', error.message);
                loading.innerHTML = '<p class="text-red-400 text-sm px-4 py-4">Errore caricamento</p>';
            } finally {
                loading.classList.add('hidden');
            }
        }

        /**
         * Render notifications in list
         */
        function renderNotifications() {
            // Remove old notification items (keep loading and empty)
            list.querySelectorAll('.notification-item').forEach(el => el.remove());

            if (notifications.length === 0) {
                empty.classList.remove('hidden');
                if (footer) footer.classList.add('hidden');
                if (markAllBtn) markAllBtn.classList.add('hidden');
                return;
            }

            empty.classList.add('hidden');
            if (footer) footer.classList.remove('hidden');

            // ENTERPRISE V7 FIX: Calculate actual unread count from in-memory data
            const actualUnreadCount = notifications.filter(n => !n.read_at).length;
            if (markAllBtn) {
                markAllBtn.classList.toggle('hidden', actualUnreadCount === 0);
            }

            notifications.forEach(notif => {
                const item = createNotificationItem(notif);
                list.appendChild(item);
            });
        }

        /**
         * Create notification DOM element
         */
        function createNotificationItem(notif) {
            const div = document.createElement('div');
            div.className = `notification-item px-4 py-3 border-b border-purple-500/10 hover:bg-white/5 cursor-pointer transition-colors ${notif.read_at ? 'opacity-60' : ''}`;
            div.dataset.notificationId = notif.id;

            const icon = getNotificationIcon(notif.type);
            const message = getNotificationMessage(notif);
            const timeAgo = getTimeAgo(notif.created_at);

            // ENTERPRISE V4.10: Format avatar URL correctly (handles Google OAuth URLs)
            const avatarUrl = formatAvatarUrl(notif.actor_avatar);

            div.innerHTML = `
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0">
                        ${notif.actor_avatar
                            ? `<img src="${escapeHtml(avatarUrl)}" class="w-10 h-10 rounded-full object-cover" alt="" onerror="this.src='/assets/img/default-avatar.png'">`
                            : `<div class="w-10 h-10 rounded-full bg-purple-500/20 flex items-center justify-center text-purple-400">${icon}</div>`
                        }
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-200 leading-snug">${message}</p>
                        <p class="text-xs text-gray-500 mt-1">${timeAgo}</p>
                    </div>
                    ${!notif.read_at ? '<div class="unread-indicator w-2 h-2 bg-purple-500 rounded-full flex-shrink-0 mt-2"></div>' : ''}
                </div>
            `;

            div.addEventListener('click', async () => {
                if (!notif.read_at) {
                    await markAsRead(notif.id);
                }

                NavbarDropdownManager.close('notifications');

                // ENTERPRISE V4.14 (2025-11-30): Determine action based on notification type
                // Post-related notifications → open lightbox (if on feed) or redirect to feed
                // Friend notifications → navigate to page
                const postRelatedTypes = ['new_comment', 'comment_reply', 'new_reaction', 'comment_liked', 'mentioned'];

                if (postRelatedTypes.includes(notif.type)) {
                    // Get post ID based on notification type
                    let postId = null;
                    let commentId = null;

                    switch (notif.type) {
                        case 'new_reaction':
                            // target_type = 'post', target_id = post_id
                            postId = notif.target_id;
                            break;

                        case 'new_comment':
                            // target_type = 'post', target_id = post_id, data.comment_id = comment
                            postId = notif.target_id;
                            commentId = notif.data?.comment_id;
                            break;

                        case 'comment_reply':
                            // target_type = 'comment', target_id = reply_id, data.post_id = post
                            postId = notif.data?.post_id;
                            commentId = notif.target_id;
                            break;

                        case 'comment_liked':
                            // target_type = 'comment', target_id = comment_id, data.post_id = post
                            postId = notif.data?.post_id;
                            commentId = notif.target_id;
                            break;

                        case 'mentioned':
                            // Can be in post or comment
                            if (notif.target_type === 'comment') {
                                postId = notif.data?.post_id;
                                commentId = notif.target_id;
                            } else {
                                postId = notif.target_id;
                            }
                            break;
                    }

                    if (postId) {
                        const isOnFeedPage = window.location.pathname === '/feed' || window.location.pathname === '/feed/';

                        if (isOnFeedPage && window.photoLightbox) {
                            window.photoLightbox.openByPostId(postId, { commentId: commentId });
                        } else {
                            let feedUrl = `/feed?post=${postId}`;
                            if (commentId) {
                                feedUrl += `#comment-${commentId}`;
                            }
                            window.location.href = feedUrl;
                        }
                    } else {
                        const url = getNotificationUrl(notif);
                        if (url) window.location.href = url;
                    }
                } else if (notif.type === 'dm_received' || notif.type === 'dm_mi_ha_cercato') {
                    const isMobile = window.innerWidth < 768 ||
                        /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

                    const conversationUuid = notif.data?.conversation_uuid;
                    // ENTERPRISE V10.89: Extract sender info from nested message object
                    const msgData = notif.data?.message || {};
                    const senderUuid = notif.actor_uuid || notif.data?.sender_uuid || msgData.sender_uuid;
                    const senderNickname = notif.actor_nickname || msgData.sender_nickname || 'Utente';
                    // ENTERPRISE V10.90: Format avatar URL to prevent relative path resolution issues
                    // Without formatAvatarUrl(), a path like "avatars/UUID/..." would be resolved
                    // relative to current page (e.g., /u/UUID), resulting in /u/avatars/... (404)
                    const rawAvatar = notif.actor_avatar || msgData.sender_avatar || null;
                    const senderAvatar = formatAvatarUrl(rawAvatar);

                    if (isMobile) {
                        window.location.href = conversationUuid ? `/chat/dm/${conversationUuid}?from=notif` : '/chat';
                    } else {
                        if (conversationUuid && window.chatWidgetManager) {
                            // ENTERPRISE V10.90: Don't hardcode status to 'online'
                            // Default to 'offline' and let the presence system update it via WebSocket
                            // The ChatWidget will receive presence updates and reflect the real status
                            window.dispatchEvent(new CustomEvent('n2t:openChatWidget', {
                                detail: {
                                    conversationUuid,
                                    otherUser: { uuid: senderUuid, nickname: senderNickname, avatar_url: senderAvatar, status: 'offline' },
                                    startMinimized: true
                                }
                            }));
                        } else {
                            window.location.href = conversationUuid ? `/chat/dm/${conversationUuid}?from=notif` : '/chat';
                        }
                    }
                } else {
                    const url = getNotificationUrl(notif);
                    if (url) {
                        window.location.href = url;
                    }
                }
            });

            return div;
        }

        /**
         * Get notification icon based on type
         */
        function getNotificationIcon(type) {
            const icons = {
                'new_comment': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>',
                'comment_reply': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>',
                'new_reaction': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>',
                'comment_liked': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/></svg>',
                'mentioned': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/></svg>',
                'friend_request': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>',
                'friend_accepted': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
                // ENTERPRISE GALAXY v9.5: DM notification icons
                'dm_received': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>',
                'dm_mi_ha_cercato': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>',
                // ENTERPRISE V11.6: Room invite notification icon
                'room_invite': '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>'
            };
            return icons[type] || icons['new_comment'];
        }

        /**
         * Get notification message based on type
         */
        function getNotificationMessage(notif) {
            const actor = notif.actor_nickname ? `<strong class="text-purple-400">@${escapeHtml(notif.actor_nickname)}</strong>` : 'Qualcuno';
            const preview = notif.data?.preview ? `"${escapeHtml(notif.data.preview.substring(0, 50))}..."` : '';

            // ENTERPRISE V11.6: Handle room_invite separately (needs room_name from data)
            if (notif.type === 'room_invite') {
                const roomName = notif.data?.room_name;
                const roomEmoji = notif.data?.room_emoji || '💬';
                if (roomName) {
                    return `${actor} ti ha invitato nella stanza ${roomEmoji} <strong>${escapeHtml(roomName)}</strong>`;
                }
                return `${actor} ti ha invitato in una stanza chat`;
            }

            const messages = {
                'new_comment': `${actor} ha commentato il tuo post`,
                'comment_reply': `${actor} ha risposto al tuo commento`,
                'new_reaction': `${actor} ha reagito al tuo post`,
                'comment_liked': `${actor} ha messo like al tuo commento`,
                'mentioned': `${actor} ti ha menzionato`,
                'friend_request': `${actor} ti ha inviato una richiesta di amicizia`,
                'friend_accepted': `${actor} ha accettato la tua richiesta di amicizia`,
                // ENTERPRISE GALAXY v9.5: DM notification messages
                'dm_received': `${actor} ti ha inviato un messaggio`,
                'dm_mi_ha_cercato': `${actor} ti ha cercato`
            };

            return messages[notif.type] || 'Nuova notifica';
        }

        /**
         * Get URL to navigate when clicking notification
         *
         * ENTERPRISE V4.9 (2025-11-30): Precise navigation with comment anchors and friend tabs
         */
        function getNotificationUrl(notif) {
            switch (notif.type) {
                case 'new_comment':
                    // Navigate to post with comment anchor
                    // target_type = 'post', target_id = post_id, data.comment_id = comment
                    if (notif.target_id) {
                        const commentAnchor = notif.data?.comment_id ? `#comment-${notif.data.comment_id}` : '';
                        return `/feed?post=${notif.target_id}${commentAnchor}`;
                    }
                    return '/feed';

                case 'comment_reply':
                    // Navigate to post with reply anchor
                    // target_type = 'comment', target_id = reply_id, data.post_id = post
                    const replyPostId = notif.data?.post_id;
                    if (replyPostId) {
                        return `/feed?post=${replyPostId}#comment-${notif.target_id}`;
                    }
                    return '/feed';

                case 'comment_liked':
                    // Navigate to post with comment anchor
                    // target_type = 'comment', target_id = comment_id
                    const likedCommentPostId = notif.data?.post_id;
                    if (likedCommentPostId) {
                        return `/feed?post=${likedCommentPostId}#comment-${notif.target_id}`;
                    }
                    return '/feed';

                case 'new_reaction':
                    // Navigate to post
                    // target_type = 'post', target_id = post_id
                    return notif.target_id ? `/feed?post=${notif.target_id}` : '/feed';

                case 'mentioned':
                    // Navigate to where mentioned (post or comment)
                    if (notif.target_type === 'comment') {
                        const mentionPostId = notif.data?.post_id;
                        return mentionPostId ? `/feed?post=${mentionPostId}#comment-${notif.target_id}` : '/feed';
                    }
                    return notif.target_id ? `/feed?post=${notif.target_id}` : '/feed';

                case 'friend_request':
                    // Navigate to friends page - pending requests tab
                    return '/friends?tab=requests';

                case 'friend_accepted':
                    // Navigate to friends page - friends list tab
                    return '/friends?tab=friends';

                // ENTERPRISE GALAXY v9.5: DM notification navigation
                case 'dm_received':
                case 'dm_mi_ha_cercato':
                    // Navigate directly to DM conversation page
                    // Add ?from=notif param to detect expired messages scenario
                    const convUuid = notif.data?.conversation_uuid;
                    if (convUuid) {
                        return `/chat/dm/${convUuid}?from=notif`;
                    }
                    return '/chat';

                // ENTERPRISE V11.6: Room invite notification navigation
                case 'room_invite':
                    // Navigate to chat with room parameter for auto-join
                    const roomUuid = notif.data?.room_uuid;
                    if (roomUuid) {
                        return `/chat?room=${encodeURIComponent(roomUuid)}`;
                    }
                    return '/chat';

                default:
                    return '/feed';
            }
        }

        /**
         * Update badge count
         */
        function updateBadge(count) {
            unreadCount = count;
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
                badge.textContent = '0';
            }
        }

        async function markAsRead(notificationId) {
            const numId = Number(notificationId);
            if (numId <= 0 || numId > 2147483647) {
                const foundNotif = notifications.find(n => String(n.id) === String(notificationId));
                if (foundNotif) foundNotif.read_at = new Date().toISOString();
                return;
            }

            try {
                const response = await fetch(`/api/notifications/${notificationId}/read`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });

                if (response.ok) {
                    const foundNotif = notifications.find(n => String(n.id) === String(notificationId));
                    if (foundNotif) foundNotif.read_at = new Date().toISOString();

                    await fetchUnreadCount();

                    const item = list.querySelector(`[data-notification-id="${notificationId}"]`);
                    if (item) {
                        item.classList.add('opacity-60');
                        item.querySelector('.unread-indicator')?.remove();
                    }

                    const actualUnreadCount = notifications.filter(n => !n.read_at).length;
                    if (markAllBtn) markAllBtn.classList.toggle('hidden', actualUnreadCount === 0);
                }
            } catch (error) {
                // Silent fail
            }
        }

        /**
         * Mark all notifications as read
         * ENTERPRISE V8.6: Use .unread-indicator class
         */
        async function markAllAsRead() {
            try {
                const response = await fetch('/api/notifications/read-all', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });

                if (response.ok) {
                    // Update local state
                    notifications.forEach(n => n.read_at = new Date().toISOString());
                    updateBadge(0);

                    // Update UI - use .unread-indicator class
                    list.querySelectorAll('.notification-item').forEach(item => {
                        item.classList.add('opacity-60');
                        item.querySelector('.unread-indicator')?.remove();
                    });

                    if (markAllBtn) markAllBtn.classList.add('hidden');
                }
            } catch (error) {
                // Silent fail
            }
        }

        function setupWebSocketListeners() {
            if (!window.WebSocketManager) return;

            // =========================================================================
            // ENTERPRISE V6.9 (2025-11-30): WebSocket Notification Events
            // =========================================================================
            // CRITICAL: Only listen to 'notification' generic event for badge updates!
            //
            // Backend sends notifications via NotificationService::create() which:
            // 1. Persists to DB
            // 2. Pushes 'notification' WebSocket event with notification_id
            //
            // The specific events (friend_request_received, etc.) are SIGNAL events
            // used by other components (e.g., pending requests list refresh).
            // They should NOT increment the badge - that's handled by 'notification'.
            //
            // Previous bug: Listening to both 'notification' AND 'friend_request_received'
            // caused badge to show 2 instead of 1 for friend requests.
            // =========================================================================
            const notificationEvents = [
                'notification',              // Generic notification event (BADGE SOURCE)
                // Signal events removed from badge handling:
                // 'friend_request_received' - handled by 'notification' + signal handler
                // 'friend_request_accepted' - handled by 'notification' + signal handler
            ];

            notificationEvents.forEach(eventType => {
                window.WebSocketManager.onMessage(eventType, (wsMessage) => {
                    handleNewNotification(wsMessage, eventType);
                });
            });

            window.WebSocketManager.onMessage('notifications_read_all', () => {
                notifications.forEach(n => n.read_at = new Date().toISOString());
                updateBadge(0);
                renderNotifications();
                if (markAllBtn) markAllBtn.classList.add('hidden');
            });
        }

        /**
         * ENTERPRISE V6.9: Handle incoming notification from WebSocket
         * Unified handler for all notification types
         *
         * CRITICAL FIX (2025-11-30): Deduplicate BEFORE incrementing badge
         * Previous bug: Badge incremented even for duplicate notifications
         *
         * ENTERPRISE V10.20 (2025-12-04): Fixed payload extraction
         * WebSocket server sends { type, payload, timestamp }
         * Previous bug: Used wsMessage.data (undefined) instead of wsMessage.payload
         */
        function handleNewNotification(wsMessage, eventType) {
            // Extract notification data from WebSocket message
            // ENTERPRISE V10.20: Server sends 'payload', not 'data'
            const data = wsMessage.payload || wsMessage.data || wsMessage;

            // Build notification object for display
            // ENTERPRISE V8.9 FIX: Use negative timestamp for temporary IDs (won't hit DB)
            // This prevents "out of range for INTEGER" error when clicking on SSE notifications
            // that arrive without proper notification_id (edge case in WebSocket wrapper)
            // ENTERPRISE V10.89: Extract sender info from nested message object for dm_received events
            // The dm_received payload structure is: { conversation_uuid, message: { sender_uuid, sender_nickname, sender_avatar, ... } }
            const messageData = data.message || {};

            const notif = {
                id: data.notification_id || data.id || -Date.now(),
                type: eventType === 'notification' ? (data.type || 'unknown') : eventType,
                actor_uuid: data.actor?.uuid || data.from_user?.uuid || data.sender_uuid || messageData.sender_uuid,
                actor_nickname: data.actor?.nickname || data.from_user?.nickname || data.sender_nickname || messageData.sender_nickname,
                actor_avatar: data.actor?.avatar_url || data.from_user?.avatar_url || data.sender_avatar || messageData.sender_avatar,
                target_type: data.target_type,
                target_id: data.target_id,
                data: data,
                read_at: null,
                created_at: data.created_at || new Date().toISOString()
            };

            // =========================================================================
            // ENTERPRISE V6.9 (2025-11-30): DEDUPLICATE BEFORE INCREMENTING BADGE
            // =========================================================================
            // Previous bug: Badge was incremented even if notification was duplicate
            // This caused badge to show 2 when only 1 notification existed
            //
            // Fix: Create a deduplication key based on type + actor + target
            // This handles cases where:
            // 1. Same notification_id arrives twice (WebSocket retry)
            // 2. Same logical event arrives (e.g., friend_request from same user)
            // =========================================================================
            const dedupeKey = `${notif.type}:${notif.actor_nickname}:${notif.target_id || 'none'}`;

            // Check if we already have this notification (by ID or dedupe key)
            const isDuplicate = notifications.some(n => {
                // Check by ID first
                if (n.id === notif.id) return true;

                // Check by dedupe key for same type + actor + target
                const existingKey = `${n.type}:${n.actor_nickname}:${n.target_id || 'none'}`;
                return existingKey === dedupeKey;
            });

            if (isDuplicate) return;

            // Add to list (we now track even if dropdown not loaded, to prevent duplicates)
            notifications.unshift(notif);

            // Re-render if dropdown is loaded
            if (isLoaded) {
                renderNotifications();
            }

            // CRITICAL: Update badge count immediately (only for non-duplicates)
            updateBadge(unreadCount + 1);

            // Show toast notification
            showNotificationToast(notif);

            if (markAllBtn) markAllBtn.classList.remove('hidden');
        }

        /**
         * Show toast notification for new notification
         */
        function showNotificationToast(data) {
            const toastContainer = document.getElementById('toast-container');
            if (!toastContainer) return;

            const message = getNotificationMessage({
                type: data.type,
                actor_nickname: data.actor?.nickname,
                data: data.data
            });

            const toast = document.createElement('div');
            toast.className = 'bg-purple-600/90 backdrop-blur-lg text-white px-6 py-3 rounded-lg shadow-xl border border-purple-500/30 transform transition-all duration-300 ease-out opacity-0 translate-y-2';
            toast.innerHTML = `
                <div class="flex items-center space-x-3">
                    ${getNotificationIcon(data.type)}
                    <span class="text-sm">${message}</span>
                </div>
            `;
            toastContainer.appendChild(toast);

            // Animate in
            requestAnimationFrame(() => {
                toast.classList.remove('opacity-0', 'translate-y-2');
            });

            // Remove after 5 seconds
            setTimeout(() => {
                toast.classList.add('opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        /**
         * Get relative time string
         */
        function getTimeAgo(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const seconds = Math.floor((now - date) / 1000);

            if (seconds < 60) return 'Adesso';
            if (seconds < 3600) return `${Math.floor(seconds / 60)}m fa`;
            if (seconds < 86400) return `${Math.floor(seconds / 3600)}h fa`;
            if (seconds < 604800) return `${Math.floor(seconds / 86400)}g fa`;
            return date.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' });
        }

        /**
         * Escape HTML to prevent XSS
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * ENTERPRISE V4.10: Format avatar URL correctly
         * Handles both external URLs (Google OAuth) and local paths
         *
         * @param {string} avatarUrl - Avatar URL from API
         * @returns {string} Full URL to avatar image
         */
        function formatAvatarUrl(avatarUrl) {
            if (!avatarUrl) {
                return '/assets/img/default-avatar.png';
            }

            // If it's already a full URL (Google, Facebook, etc), use as-is
            if (avatarUrl.startsWith('http://') || avatarUrl.startsWith('https://')) {
                return avatarUrl;
            }

            // If it starts with /storage/, it's already a valid path
            if (avatarUrl.startsWith('/storage/')) {
                return avatarUrl;
            }

            // Otherwise, prepend the storage path
            return `/storage/uploads/${avatarUrl}`;
        }

    }

    /**
     * ENTERPRISE V6.7: Navbar User Search
     * Real-time search with debounce and keyboard navigation
     */
    function initUserSearch() {
        const input = document.getElementById('navbarSearchInput');
        const dropdown = document.getElementById('searchResultsDropdown');
        const resultsList = document.getElementById('searchResultsList');
        const emptyState = document.getElementById('searchEmptyState');
        const spinner = document.getElementById('searchSpinner');

        if (!input || !dropdown) {
            return;
        }

        let debounceTimer = null;
        let selectedIndex = -1;
        let currentResults = [];

        // Debounced search on input
        input.addEventListener('input', function() {
            const query = this.value.trim();

            // Clear previous timer
            if (debounceTimer) clearTimeout(debounceTimer);

            // Hide dropdown if query too short
            if (query.length < 2) {
                hideDropdown();
                return;
            }

            // Show loading
            spinner.classList.remove('hidden');

            // Debounce: wait 300ms after user stops typing
            debounceTimer = setTimeout(() => {
                performSearch(query);
            }, 300);
        });

        // Keyboard navigation
        input.addEventListener('keydown', function(e) {
            if (!dropdown.classList.contains('hidden') && currentResults.length > 0) {
                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, currentResults.length - 1);
                        updateSelection();
                        break;

                    case 'ArrowUp':
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, 0);
                        updateSelection();
                        break;

                    case 'Enter':
                        e.preventDefault();
                        if (selectedIndex >= 0 && currentResults[selectedIndex]) {
                            // ENTERPRISE V6.7: Use uuid for profile URL
                            const user = currentResults[selectedIndex];
                            navigateToProfile(`/u/${user.uuid}`);
                        }
                        break;

                    case 'Escape':
                        hideDropdown();
                        input.blur();
                        break;
                }
            }
        });

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                hideDropdown();
            }
        });

        // Focus: show dropdown if has results
        input.addEventListener('focus', function() {
            if (currentResults.length > 0) {
                // ENTERPRISE V10.51 FIX: Clear inline style set by NavbarDropdownManager.closeAll()
                dropdown.style.display = '';
                dropdown.classList.remove('hidden');
            }
        });

        /**
         * Perform API search
         * ENTERPRISE V6.7: Uses SocialController::searchUsers which includes friendship status
         */
        async function performSearch(query) {
            try {
                const response = await fetch(`/api/users/search?q=${encodeURIComponent(query)}`, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) throw new Error('Search failed');

                const data = await response.json();

                // ENTERPRISE V6.7: API returns 'results' not 'users'
                // Sort: friends first (friendship_status = 'accepted'), then others
                let results = data.results || [];
                results = results.sort((a, b) => {
                    const aIsFriend = a.friendship_status === 'accepted' ? 0 : 1;
                    const bIsFriend = b.friendship_status === 'accepted' ? 0 : 1;
                    return aIsFriend - bIsFriend;
                });

                currentResults = results;
                currentQuery = query;
                selectedIndex = -1;

                renderResults(currentResults);

            } catch (error) {
                console.error('[UserSearch] Search failed:', error);
                currentResults = [];
                renderResults([]);
            } finally {
                spinner.classList.add('hidden');
            }
        }

        let currentQuery = '';

        /**
         * Render search results
         * ENTERPRISE V6.9 (2025-11-30): Larger, more readable result items
         */
        function renderResults(users) {
            resultsList.innerHTML = '';

            if (users.length === 0) {
                emptyState.classList.remove('hidden');
                // ENTERPRISE V10.51 FIX: Clear inline style set by NavbarDropdownManager.closeAll()
                dropdown.style.display = '';
                dropdown.classList.remove('hidden');
                return;
            }

            emptyState.classList.add('hidden');

            // Header
            const header = document.createElement('div');
            header.className = 'px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wide bg-gray-900/50';
            header.textContent = `${users.length} risultat${users.length === 1 ? 'o' : 'i'}`;
            resultsList.appendChild(header);

            users.forEach((user, index) => {
                const item = document.createElement('a');
                item.href = `/u/${user.uuid}`;
                item.className = 'search-result-item flex items-center gap-4 px-4 py-3.5 hover:bg-purple-500/10 transition-colors border-b border-gray-700/30 last:border-b-0';
                item.dataset.index = index;

                // ENTERPRISE V6.9: Improved badges
                const isFriend = user.friendship_status === 'accepted';
                const isPending = user.friendship_status === 'pending';
                let statusBadge = '';
                if (isFriend) {
                    statusBadge = '<span class="px-2 py-0.5 text-xs font-medium bg-green-500/20 text-green-400 rounded-full">Amico</span>';
                } else if (isPending) {
                    statusBadge = '<span class="px-2 py-0.5 text-xs font-medium bg-yellow-500/20 text-yellow-400 rounded-full">In attesa</span>';
                }

                // Format avatar with default fallback
                const avatarUrl = user.avatar_url || '/assets/img/default-avatar.png';

                item.innerHTML = `
                    <img src="${escapeHtml(avatarUrl)}"
                         alt="${escapeHtml(user.nickname)}"
                         class="w-12 h-12 rounded-full object-cover flex-shrink-0 ring-2 ring-gray-700"
                         onerror="this.src='/assets/img/default-avatar.png'">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="text-white font-semibold text-base truncate">@${escapeHtml(user.nickname)}</p>
                            ${statusBadge}
                        </div>
                        <p class="text-gray-400 text-sm mt-0.5">Visualizza profilo</p>
                    </div>
                    <svg class="w-5 h-5 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                `;

                resultsList.appendChild(item);
            });

            // ENTERPRISE V6.9: Better "search more" button
            const moreButton = document.createElement('a');
            moreButton.href = `/friends?search=${encodeURIComponent(currentQuery)}`;
            moreButton.className = 'block px-4 py-4 text-center text-sm font-medium text-purple-400 hover:text-purple-300 hover:bg-purple-500/10 transition-colors bg-gray-900/30';
            moreButton.innerHTML = `
                <span class="flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Cerca altri utenti...
                </span>
            `;
            resultsList.appendChild(moreButton);

            // ENTERPRISE V10.51 FIX: Clear inline style set by NavbarDropdownManager.closeAll()
            // closeAll() sets style.display='none' which takes priority over CSS class
            dropdown.style.display = '';
            dropdown.classList.remove('hidden');
        }

        /**
         * Update selection highlight
         */
        function updateSelection() {
            const items = resultsList.querySelectorAll('.search-result-item');
            items.forEach((item, idx) => {
                if (idx === selectedIndex) {
                    item.classList.add('bg-purple-600/30');
                } else {
                    item.classList.remove('bg-purple-600/30');
                }
            });
        }

        /**
         * Navigate to user profile
         */
        function navigateToProfile(url) {
            if (url) {
                window.location.href = url;
            }
        }

        /**
         * Hide dropdown
         */
        function hideDropdown() {
            dropdown.classList.add('hidden');
            spinner.classList.add('hidden');
            selectedIndex = -1;
        }

        /**
         * Escape HTML
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

    }

    // Initialize search when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUserSearch);
    } else {
        initUserSearch();
    }
})();
</script>
