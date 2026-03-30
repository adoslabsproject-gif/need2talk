/**
 * Profile Tabs System - Enterprise Galaxy
 *
 * Manages tab navigation for psychological profile dashboard with:
 * - URL hash-based state management (#panoramica, #diario, etc.)
 * - Browser back/forward support (popstate)
 * - Smooth transitions with CSS animations
 * - Lazy content loading (load tab content only when first accessed)
 * - Performance optimized (event delegation)
 * - Mobile-responsive design
 *
 * Tabs (ENTERPRISE v2.0 - Reduced to 4):
 * 1. panoramica - Health score, emotion wheel, mood timeline, audio posts (ProfileDashboard.js)
 * 2. diario - Emotional journal form (EmotionalJournal.js)
 * 3. timeline - Chronological history with sidebar calendar (JournalTimeline.js + JournalCalendarSidebar.js)
 * 4. emozioni - Deep analytics by emotion
 *
 * Architecture:
 * - Singleton pattern (only one instance per page)
 * - Namespace: window.ProfileTabs
 * - State persistence: URL hash + sessionStorage
 * - Auto-initialization on DOMContentLoaded
 *
 * @package need2talk/Lightning
 * @version 1.0.0
 * @author Claude Code (AI-Orchestrated Development)
 */

(function() {
    'use strict';

    // Prevent duplicate initialization
    if (window.ProfileTabs) {
        console.warn('[ProfileTabs] Already initialized, skipping...');
        return;
    }

    /**
     * ProfileTabs Class - Singleton
     */
    class ProfileTabs {
        constructor() {
            // Configuration
            this.config = {
                tabsContainer: '.profile-tabs-container',
                tabButtons: '.profile-tab-btn',
                tabPanels: '.profile-tab-panel',
                activeClass: 'active',
                transitionDuration: 300, // ms
                defaultTab: 'panoramica',
            };

            // State
            this.currentTab = null;
            this.loadedTabs = new Set(); // Track which tabs have been lazy-loaded
            this.isTransitioning = false;

            // DOM references (initialized in init())
            this.container = null;
            this.tabButtons = [];
            this.tabPanels = {};

            // Bind methods
            this.handleTabClick = this.handleTabClick.bind(this);
            this.handlePopState = this.handlePopState.bind(this);
        }

        /**
         * Initialize tabs system
         * Called automatically on DOMContentLoaded
         */
        init() {
            // Find DOM elements
            this.container = document.querySelector(this.config.tabsContainer);
            if (!this.container) {
                console.warn('[ProfileTabs] Container not found, skipping initialization');
                return;
            }

            this.tabButtons = Array.from(this.container.querySelectorAll(this.config.tabButtons));
            this.config.tabPanels.split(',').forEach(selector => {
                const panels = document.querySelectorAll(selector.trim());
                panels.forEach(panel => {
                    const tabName = panel.id.replace('tab-', '');
                    this.tabPanels[tabName] = panel;
                });
            });

            // Simpler approach: Find all tab panels by class
            const panels = this.container.querySelectorAll('.profile-tab-panel');
            panels.forEach(panel => {
                const tabName = panel.id.replace('tab-', '');
                this.tabPanels[tabName] = panel;
            });

            // Attach event listeners
            this.attachEventListeners();

            // ENTERPRISE: Determine initial tab with 3-tier fallback strategy
            // Priority: 1) URL hash (#diario) → 2) sessionStorage (UI persistence) → 3) default
            const hashTab = this.getTabFromHash();
            const sessionTab = sessionStorage.getItem('profile_active_tab');

            // SECURITY: Validate sessionStorage tab exists in available tabs
            const validSessionTab = sessionTab && this.tabPanels[sessionTab] ? sessionTab : null;

            const initialTab = hashTab || validSessionTab || this.config.defaultTab;

            this.switchTab(initialTab, false); // false = no history push on init

            // Mark first tab as loaded (panoramica with ProfileDashboard.js)
            this.loadedTabs.add('panoramica');
        }

        /**
         * Attach event listeners
         */
        attachEventListeners() {
            // Tab button clicks (event delegation for performance)
            this.tabButtons.forEach(btn => {
                btn.addEventListener('click', this.handleTabClick);
            });

            // Browser back/forward buttons (popstate)
            window.addEventListener('popstate', this.handlePopState);
        }

        /**
         * Handle tab button click
         * @param {Event} event - Click event
         */
        handleTabClick(event) {
            event.preventDefault();

            // Prevent clicks during transition
            if (this.isTransitioning) {
                return;
            }

            const button = event.currentTarget;
            const tabName = button.dataset.tab;

            if (!tabName) {
                console.error('[ProfileTabs] Tab name missing in data-tab attribute');
                return;
            }

            // Switch tab
            this.switchTab(tabName, true); // true = push to history
        }

        /**
         * Handle browser back/forward (popstate event)
         * ENTERPRISE: Sync sessionStorage with URL hash on navigation
         * @param {PopStateEvent} event - Popstate event
         */
        handlePopState(event) {
            const tabName = this.getTabFromHash() || this.config.defaultTab;
            this.switchTab(tabName, false); // false = already in history
        }

        /**
         * Switch to specified tab
         * @param {string} tabName - Tab identifier (e.g., 'panoramica', 'diario')
         * @param {boolean} pushState - Whether to push to browser history
         */
        switchTab(tabName, pushState = true) {
            // Validate tab exists
            if (!this.tabPanels[tabName]) {
                console.error(`[ProfileTabs] Tab "${tabName}" not found`);
                return;
            }

            // Already on this tab
            if (this.currentTab === tabName) {
                return;
            }

            // Set transitioning flag
            this.isTransitioning = true;

            // Update button states
            this.updateButtonStates(tabName);

            // ENTERPRISE FIX: Remove 'active' from ALL panels (handles hardcoded 'active' in HTML)
            // Before: Only removed from this.currentTab (failed on first switchTab when currentTab=null)
            // Problem: tab-panoramica has class="active" hardcoded in show.php line 281
            // Solution: Remove from ALL panels before adding to chosen panel
            Object.values(this.tabPanels).forEach(panel => {
                panel.classList.remove(this.config.activeClass);
            });

            // Fade in new panel (after brief delay for smooth transition)
            setTimeout(() => {
                this.tabPanels[tabName].classList.add(this.config.activeClass);

                // Lazy load tab content if not yet loaded
                if (!this.loadedTabs.has(tabName)) {
                    this.loadTabContent(tabName);
                    this.loadedTabs.add(tabName);
                }

                // Update state
                this.currentTab = tabName;
                this.isTransitioning = false;

                // ENTERPRISE: Bidirectional sync (URL hash ↔ sessionStorage)
                if (pushState) {
                    // User clicked tab → update BOTH hash and storage
                    history.pushState({ tab: tabName }, '', `#${tabName}`);
                    sessionStorage.setItem('profile_active_tab', tabName);
                } else {
                    // Navigation (back/forward/init) → sync storage with hash
                    sessionStorage.setItem('profile_active_tab', tabName);

                    // ENTERPRISE: If hash is missing but we have a tab, set it (edge case)
                    if (!window.location.hash && tabName !== this.config.defaultTab) {
                        history.replaceState({ tab: tabName }, '', `#${tabName}`);
                    }
                }
            }, 50);
        }

        /**
         * Update button active states
         * @param {string} activeTabName - Active tab name
         */
        updateButtonStates(activeTabName) {
            this.tabButtons.forEach(btn => {
                if (btn.dataset.tab === activeTabName) {
                    btn.classList.add(this.config.activeClass);
                    btn.setAttribute('aria-selected', 'true');
                } else {
                    btn.classList.remove(this.config.activeClass);
                    btn.setAttribute('aria-selected', 'false');
                }
            });
        }

        /**
         * Lazy load tab content
         * @param {string} tabName - Tab name
         */
        loadTabContent(tabName) {
            switch (tabName) {
                case 'panoramica':
                    // Already loaded by ProfileDashboard.js
                    break;

                case 'diario':
                    // TODO: Initialize EmotionalJournal.js
                    this.loadDiarioEmotivo();
                    break;

                case 'timeline':
                    // TODO: Initialize JournalTimeline.js
                    this.loadTimeline();
                    break;

                case 'emozioni':
                    // TODO: Load emotion analytics
                    this.loadEmozioniAnalytics();
                    break;

                default:
                    console.warn(`[ProfileTabs] Unknown tab "${tabName}" for lazy loading`);
            }
        }

        /**
         * Load Diario Emotivo tab content
         */
        loadDiarioEmotivo() {
            const container = document.getElementById('emotional-journal-container');
            if (!container) return;

            // Check if EmotionalJournal module is loaded
            if (window.EmotionalJournal && typeof window.EmotionalJournal.init === 'function') {
                window.EmotionalJournal.init();
            } else {
                // Fallback if module not loaded yet
                console.warn('[ProfileTabs] EmotionalJournal module not loaded, retrying...');
                setTimeout(() => this.loadDiarioEmotivo(), 100);
            }
        }

        /**
         * Load Timeline tab content (ENTERPRISE GALAXY - Psychology-Optimized)
         */
        loadTimeline() {
            const container = document.getElementById('journal-timeline-container');
            if (!container) return;

            // Check if JournalTimeline module is loaded
            if (window.JournalTimeline && typeof window.JournalTimeline.init === 'function') {
                window.JournalTimeline.init();
            } else {
                // Fallback if module not loaded yet
                console.warn('[ProfileTabs] JournalTimeline module not loaded, retrying...');
                setTimeout(() => this.loadTimeline(), 100);
            }
        }

        /**
         * Load Emozioni Analytics tab content
         * ENTERPRISE GALAXY V11.6: Full emotional analytics based on reactions
         */
        loadEmozioniAnalytics() {
            const container = document.getElementById('emotions-analytics-container');
            if (!container) return;

            // Check if EmotionalAnalytics module is loaded
            if (window.EmotionalAnalytics && typeof window.EmotionalAnalytics.init === 'function') {
                window.EmotionalAnalytics.init();
            } else {
                // Fallback if module not loaded yet
                console.warn('[ProfileTabs] EmotionalAnalytics module not loaded, retrying...');
                setTimeout(() => this.loadEmozioniAnalytics(), 100);
            }
        }

        /**
         * Get tab name from URL hash
         * @returns {string|null} Tab name or null
         */
        getTabFromHash() {
            const hash = window.location.hash.substring(1); // Remove '#'
            return hash && this.tabPanels[hash] ? hash : null;
        }

        /**
         * Public API: Programmatically switch to tab
         * @param {string} tabName - Tab name
         */
        switchToTab(tabName) {
            this.switchTab(tabName, true);
        }

        /**
         * Public API: Get current active tab
         * @returns {string|null} Current tab name
         */
        getCurrentTab() {
            return this.currentTab;
        }

        /**
         * ENTERPRISE: Reset tab state (clear sessionStorage)
         * Useful on logout or when user wants fresh state
         */
        resetTabState() {
            sessionStorage.removeItem('profile_active_tab');
            this.switchTab(this.config.defaultTab, true);
        }

        /**
         * ENTERPRISE: Get tab persistence status for debugging
         * @returns {Object} Persistence state
         */
        getTabPersistenceState() {
            return {
                currentTab: this.currentTab,
                urlHash: window.location.hash,
                sessionStorage: sessionStorage.getItem('profile_active_tab'),
                loadedTabs: Array.from(this.loadedTabs),
                isTransitioning: this.isTransitioning,
            };
        }
    }

    // Create singleton instance
    const profileTabs = new ProfileTabs();

    // Auto-initialize on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => profileTabs.init());
    } else {
        // Already loaded, initialize immediately
        profileTabs.init();
    }

    // Expose to global scope for external access
    window.ProfileTabs = profileTabs;
})();
