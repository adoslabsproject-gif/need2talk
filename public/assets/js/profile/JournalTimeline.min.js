/**
 * Journal Timeline - Enterprise Galaxy+ (Psychology-Optimized)
 *
 * ENTERPRISE REFACTOR: Audio diario è SEMPRE locale e criptato.
 * - NO share nel feed
 * - NO CDN upload
 * - Decryption client-side con WebCrypto API
 * - Player ENTERPRISE con progress bar e visualizzazione durata
 *
 * Features:
 * - Psychology-based organization (chronological with emotion clustering)
 * - Visual memory anchors (photos, emotion icons, intensity colors)
 * - Encrypted audio playback with client-side decryption
 * - Photo display with thumbnails
 * - Metadata display (title, description, timestamps)
 * - Soft delete with 30-day retention
 *
 * @package need2talk/Lightning
 * @version 2.0.0 - ENTERPRISE GALAXY+ (No Share, Local Audio Only)
 * @author Claude Code (AI-Orchestrated Development)
 * @scalability 100,000+ concurrent users
 */

(function() {
    'use strict';

    // Prevent duplicate initialization
    if (window.JournalTimeline) {
        console.warn('[JournalTimeline] Already initialized');
        return;
    }

    /**
     * JournalTimeline Class - Singleton
     */
    class JournalTimeline {
        constructor(containerId = 'journal-timeline-container') {
            // DOM
            this.container = document.getElementById(containerId);
            if (!this.container) {
                console.error('[JournalTimeline] Container not found');
                return;
            }

            // API endpoints (ENTERPRISE: Removed share/unshare - audio is ALWAYS private)
            this.apiEndpoints = {
                timeline: '/api/journal/timeline',
                softDelete: '/api/journal',
                trash: '/api/journal/trash',
                audioStream: '/api/journal/audio', // + /{uuid}/stream
            };

            // Calendar sidebar integration
            this.calendarSidebar = null;

            // State
            this.entries = [];
            this.isLoading = false;
            this.hasMore = true;
            this.currentPage = 1;
            this.pageSize = 10; // ENTERPRISE: 10 entries per batch
            this.currentDateFilter = null;

            // ENTERPRISE: Infinite scroll observer
            this.scrollObserver = null;
            this.scrollSentinel = null;

            // Audio player state (ENTERPRISE: Single active player pattern)
            this.activeAudioElement = null;
            this.activePlayButton = null;
            this.decryptedAudioCache = new Map(); // Cache decrypted blob URLs by UUID
            this.decryptedAudioDataCache = new Map(); // Cache raw ArrayBuffer for MSE seeking
            this.currentPlayPromise = null; // Track pending play() calls

            // Bind methods
            this.handlePlayAudio = this.handlePlayAudio.bind(this);
            this.handleDeleteClick = this.handleDeleteClick.bind(this);

            // Trash view state
            this.isTrashView = false;

            // Diary protection state (ENTERPRISE v4.6)
            this.diaryUnlocked = false;
            this.encryptionService = null;
        }

        /**
         * Initialize timeline
         * ENTERPRISE v4.6: Check diary unlock status before loading data
         */
        async init() {
            // Check if diary encryption is available and unlocked
            const unlockStatus = await this._checkDiaryUnlocked();

            if (!unlockStatus) {
                // Diary is locked - show protected overlay, don't load any data
                this.renderProtectedState();
                return;
            }

            this.diaryUnlocked = true;
            this.renderLoadingState();
            await this.initCalendarSidebar();
            await this.loadTimeline();

            // V12: Listen for entry save events to refresh timeline
            this._bindEntrySavedListener();
        }

        /**
         * V12: Bind listener for journal entry saved events
         * Refreshes both timeline and calendar when a new entry is saved
         */
        _bindEntrySavedListener() {
            window.addEventListener('journal:entry-saved', async (event) => {
                // Reset pagination and reload timeline from page 1
                this.entries = [];
                this.currentPage = 1;
                this.hasMore = true;

                // Reload timeline
                await this.loadTimeline(1);

                // Refresh calendar sidebar
                if (this.calendarSidebar) {
                    this.calendarSidebar.invalidateCache();
                    await this.calendarSidebar.loadCalendarData();
                    this.calendarSidebar.render();
                }
            });
        }

        /**
         * ENTERPRISE v4.6: Check if diary is unlocked
         * Returns true only if encryption service confirms unlock
         * @private
         */
        async _checkDiaryUnlocked() {
            // Check if DiaryEncryptionService is available
            if (typeof DiaryEncryptionService === 'undefined') {
                console.warn('[JournalTimeline] DiaryEncryptionService not available');
                return true; // Allow access if encryption not configured
            }

            // Get user UUID from page (same sources as EmotionalJournal)
            const userUuid = window.profileUserUuid ||
                             window.need2talk?.user?.uuid ||
                             window.APP_USER?.uuid ||
                             null;

            if (!userUuid) {
                console.warn('[JournalTimeline] User UUID not found - diary check skipped');
                return true; // Allow access if we can't determine user
            }

            try {
                this.encryptionService = new DiaryEncryptionService(userUuid);

                // Check if already unlocked in current session
                if (this.encryptionService.isUnlocked()) {
                    return true;
                }

                // Check server status - maybe auto-unlock is possible
                const status = await this.encryptionService.checkSetupStatus();

                if (!status.success) {
                    return false;
                }

                // If diary not set up yet, allow access (will prompt in Diario tab)
                if (status.setup_required) {
                    return true;
                }

                // Try auto-unlock with device token
                const autoResult = await this.encryptionService.tryAutoUnlock(null);
                return autoResult.success;

            } catch (error) {
                console.error('[JournalTimeline] Diary unlock check failed:', error);
                return false;
            }
        }

        /**
         * ENTERPRISE V11.6: Decrypt text entries that have E2E encrypted content
         * Processes entries in parallel for performance
         * @private
         * @param {Array} entries - Array of journal entries from API
         * @returns {Promise<Array>} Entries with decrypted text_content
         */
        async _decryptTextEntries(entries) {
            if (!entries || entries.length === 0) return entries;
            if (!this.encryptionService || !this.encryptionService.isUnlocked()) {
                console.warn('[JournalTimeline] Encryption service not available for decryption');
                return entries;
            }

            // Process entries in parallel
            const decryptedEntries = await Promise.all(entries.map(async (entry) => {
                // Skip if not encrypted or missing encryption data
                // ENTERPRISE V12: PostgreSQL returns 't'/'f' for boolean, convert to JS boolean
                const isTextEncrypted = entry.is_text_encrypted === true || entry.is_text_encrypted === 't' || entry.is_text_encrypted === '1';
                if (!isTextEncrypted || !entry.text_content_encrypted || !entry.text_content_iv) {
                    return entry;
                }

                try {
                    const decryptedText = await this.encryptionService.decryptText(
                        entry.text_content_encrypted,
                        entry.text_content_iv
                    );

                    // Return entry with decrypted text in text_content field
                    return {
                        ...entry,
                        text_content: decryptedText,
                        _decrypted: true // Flag for debugging
                    };
                } catch (decryptError) {
                    console.error('[JournalTimeline] Failed to decrypt entry:', entry.id, decryptError);
                    return {
                        ...entry,
                        text_content: '[🔒 Impossibile decifrare]',
                        _decrypt_error: true
                    };
                }
            }));

            return decryptedEntries;
        }

        /**
         * ENTERPRISE v4.6: Render protected state when diary is locked
         * Shows blur overlay with unlock message - NO DATA LOADED
         * Hides calendar sidebar and replaces entire layout
         */
        renderProtectedState() {
            // Hide calendar sidebar
            const calendarSidebar = document.getElementById('journal-calendar-sidebar');
            if (calendarSidebar) {
                calendarSidebar.style.display = 'none';
            }

            // Get parent layout container and replace entire content
            const layoutContainer = this.container.closest('.journal-timeline-layout');
            if (layoutContainer) {
                layoutContainer.innerHTML = `
                    <div class="timeline-protected-overlay" style="grid-column: 1 / -1;">
                        <div class="timeline-protected-blur">
                            <div class="blur-placeholder">
                                <div class="blur-card"></div>
                                <div class="blur-card"></div>
                                <div class="blur-card"></div>
                            </div>
                        </div>
                        <div class="timeline-protected-message">
                            <div class="protected-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </div>
                            <h3 class="protected-title">Timeline Protetta</h3>
                            <p class="protected-description">
                                Per visualizzare la cronologia delle tue emozioni,
                                devi prima sbloccare il diario.
                            </p>
                            <button type="button" class="protected-unlock-btn" onclick="window.ProfileTabs?.switchToTab('diario')">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                                    <polyline points="10 17 15 12 10 7"/>
                                    <line x1="15" y1="12" x2="3" y2="12"/>
                                </svg>
                                Vai al Diario per Sbloccare
                            </button>
                            <p class="protected-hint">
                                🔐 I tuoi dati sono crittografati end-to-end
                            </p>
                        </div>
                    </div>
                `;
            } else {
                // Fallback: just clear container
                this.container.innerHTML = `
                    <div class="timeline-protected-overlay">
                        <div class="timeline-protected-blur">
                            <div class="blur-placeholder">
                                <div class="blur-card"></div>
                                <div class="blur-card"></div>
                                <div class="blur-card"></div>
                            </div>
                        </div>
                        <div class="timeline-protected-message">
                            <div class="protected-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </div>
                            <h3 class="protected-title">Timeline Protetta</h3>
                            <p class="protected-description">
                                Per visualizzare la cronologia delle tue emozioni,
                                devi prima sbloccare il diario.
                            </p>
                            <button type="button" class="protected-unlock-btn" onclick="window.ProfileTabs?.switchToTab('diario')">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                                    <polyline points="10 17 15 12 10 7"/>
                                    <line x1="15" y1="12" x2="3" y2="12"/>
                                </svg>
                                Vai al Diario per Sbloccare
                            </button>
                            <p class="protected-hint">
                                🔐 I tuoi dati sono crittografati end-to-end
                            </p>
                        </div>
                    </div>
                `;
            }
        }

        /**
         * Initialize calendar sidebar
         */
        async initCalendarSidebar() {
            if (!window.JournalCalendarSidebar) {
                console.warn('[JournalTimeline] JournalCalendarSidebar not loaded');
                return;
            }

            const sidebarContainer = document.getElementById('journal-calendar-sidebar');
            if (!sidebarContainer) return;

            try {
                this.calendarSidebar = new window.JournalCalendarSidebar('journal-calendar-sidebar');
                await this.calendarSidebar.init(
                    sidebarContainer.parentElement,
                    (date) => this.filterByDate(date),
                    () => this.showTrashView()
                );
            } catch (error) {
                console.error('[JournalTimeline] Failed to init calendar sidebar:', error);
            }
        }

        /**
         * Show trash view
         */
        async showTrashView() {
            this.isTrashView = true;
            this.currentDateFilter = null;
            this.renderLoadingState();

            try {
                const response = await api.get(this.apiEndpoints.trash);
                if (response.success && response.entries) {
                    // ENTERPRISE V11.6: Decrypt encrypted text entries
                    this.entries = await this._decryptTextEntries(response.entries);
                    this.hasMore = false;
                    this.renderTrashView();
                } else {
                    this.renderEmptyTrashState();
                }
            } catch (error) {
                console.error('[JournalTimeline] Failed to load trash:', error);
                this.renderErrorState();
            }
        }

        /**
         * Render trash view
         */
        renderTrashView() {
            if (this.entries.length === 0) {
                this.renderEmptyTrashState();
                return;
            }

            this.container.innerHTML = `
                <div class="timeline-header mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-3xl font-bold text-white flex items-center">
                                <span class="text-4xl mr-3">🗑️</span>
                                Cestino
                            </h2>
                            <p class="text-gray-400 mt-2">
                                Entrate eliminate • Rimosse permanentemente dopo 30 giorni
                            </p>
                        </div>
                        <button
                            class="px-4 py-2 bg-purple-600/30 hover:bg-purple-600/50 border border-purple-500/50 rounded-lg text-purple-200 font-semibold transition-all"
                            onclick="window.JournalTimeline.instance.exitTrashView()">
                            ← Torna alla Timeline
                        </button>
                    </div>

                    <div class="bg-amber-900/30 border border-amber-500/40 rounded-lg p-4">
                        <p class="text-amber-300 text-sm flex items-center">
                            <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                            </svg>
                            Le entrate nel cestino verranno eliminate permanentemente dopo 30 giorni.
                        </p>
                    </div>
                </div>

                <div class="trash-entries space-y-4">
                    ${this.entries.map(entry => this.renderTrashEntry(entry)).join('')}
                </div>
            `;

            this.attachTrashEventListeners();
        }

        /**
         * Render single trash entry
         */
        renderTrashEntry(entry) {
            const daysRemaining = entry.days_until_permanent_deletion || 30;
            const urgencyClass = daysRemaining <= 7 ? 'text-red-400' :
                                 daysRemaining <= 14 ? 'text-amber-400' : 'text-gray-400';

            return `
                <div class="entry-card trash-entry" data-entry-uuid="${entry.uuid}">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="w-12 h-12 rounded-full bg-gray-700 flex items-center justify-center text-2xl">
                                ${entry.emotion_emoji || '📝'}
                            </div>
                            <div>
                                <div class="text-white font-semibold">${entry.emotion_name || 'Entrata'}</div>
                                <div class="text-sm text-gray-400">
                                    ${new Date(entry.date).toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' })}
                                </div>
                                <div class="text-xs ${urgencyClass} mt-1">
                                    ⏱ ${daysRemaining} giorni prima della rimozione
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center space-x-2">
                            <button class="restore-entry-btn px-4 py-2 bg-green-600/20 hover:bg-green-600/40 border border-green-500/40 rounded-lg text-green-400 text-sm font-medium transition-all" data-uuid="${entry.uuid}">
                                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                                </svg>
                                Ripristina
                            </button>
                            <button class="permanent-delete-btn px-4 py-2 bg-red-600/20 hover:bg-red-600/40 border border-red-500/40 rounded-lg text-red-400 text-sm font-medium transition-all" data-uuid="${entry.uuid}">
                                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                                Elimina
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        attachTrashEventListeners() {
            this.container.querySelectorAll('.restore-entry-btn').forEach(btn => {
                btn.addEventListener('click', (e) => this.handleRestoreEntry(e.currentTarget.dataset.uuid));
            });
            this.container.querySelectorAll('.permanent-delete-btn').forEach(btn => {
                btn.addEventListener('click', (e) => this.handlePermanentDelete(e.currentTarget.dataset.uuid));
            });
        }

        async handleRestoreEntry(uuid) {
            if (!confirm('Vuoi ripristinare questa entrata?')) return;

            try {
                const response = await api.post(`/api/journal/${uuid}/restore`, {});
                if (response.success) {
                    this.showToast('Entrata ripristinata!', 'success');
                    await this.showTrashView();
                    if (this.calendarSidebar) {
                        this.calendarSidebar.invalidateCache();
                        await this.calendarSidebar.loadCalendarData();
                        this.calendarSidebar.render();
                    }
                } else {
                    throw new Error(response.error || 'Errore');
                }
            } catch (error) {
                this.showToast(error.message, 'error');
            }
        }

        async handlePermanentDelete(uuid) {
            if (!confirm('Eliminare PERMANENTEMENTE questa entrata?\n\nQuesta azione NON può essere annullata!')) return;

            try {
                const response = await api.delete(`/api/journal/${uuid}/permanent-delete`);
                if (response.success) {
                    this.showToast('Eliminata permanentemente', 'success');
                    await this.showTrashView();
                    if (this.calendarSidebar) {
                        this.calendarSidebar.invalidateCache();
                        await this.calendarSidebar.loadCalendarData();
                        this.calendarSidebar.render();
                    }
                } else {
                    throw new Error(response.error || 'Errore');
                }
            } catch (error) {
                this.showToast(error.message, 'error');
            }
        }

        async exitTrashView() {
            this.isTrashView = false;
            this.currentDateFilter = null;
            this.entries = [];
            this.hasMore = true;
            this.currentPage = 1;
            if (this.calendarSidebar) this.calendarSidebar.clearSelection();
            this.renderLoadingState();
            await this.loadTimeline();
        }

        renderEmptyTrashState() {
            this.container.innerHTML = `
                <div class="text-center py-16">
                    <div class="text-8xl mb-6">✨</div>
                    <h3 class="text-2xl font-bold text-white mb-4">Il Cestino è Vuoto</h3>
                    <p class="text-gray-400 mb-8">Non ci sono entrate eliminate.</p>
                    <button onclick="window.JournalTimeline.instance.exitTrashView()" class="px-6 py-3 bg-purple-600 hover:bg-purple-500 text-white rounded-lg font-medium transition-all">
                        ← Torna alla Timeline
                    </button>
                </div>
            `;
        }

        /**
         * Load timeline entries
         */
        async loadTimeline(page = 1) {
            if (this.isLoading) return;
            this.isLoading = true;

            try {
                let url = `${this.apiEndpoints.timeline}?page=${page}&limit=${this.pageSize}`;
                if (this.currentDateFilter) {
                    url += `&date=${encodeURIComponent(this.currentDateFilter)}`;
                }

                const response = await api.get(url);

                if (response.success && response.entries) {
                    // ENTERPRISE V11.6: Decrypt encrypted text entries before rendering
                    const entries = await this._decryptTextEntries(response.entries);
                    this.entries = page === 1 ? entries : [...this.entries, ...entries];
                    this.hasMore = response.has_more || false;
                    this.currentPage = page;
                    this.render();
                } else {
                    if (page === 1) this.renderEmptyState();
                }
            } catch (error) {
                console.error('[JournalTimeline] Failed to load:', error);
                this.renderErrorState();
            } finally {
                this.isLoading = false;
            }
        }

        async filterByDate(date) {
            this.currentDateFilter = date;
            this.currentPage = 1;
            this.entries = [];
            this.hasMore = true;
            this.renderLoadingState();
            await this.loadTimeline(1);
            this.container?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        async clearDateFilter() {
            this.currentDateFilter = null;
            this.currentPage = 1;
            this.entries = [];
            this.hasMore = true;
            this.renderLoadingState();
            await this.loadTimeline(1);
        }

        /**
         * Render timeline
         */
        render() {
            if (this.entries.length === 0) {
                this.renderEmptyState();
                return;
            }

            const groupedEntries = this.groupEntriesByTimeProximity(this.entries);

            this.container.innerHTML = `
                <div class="timeline-header mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-3xl font-bold text-white flex items-center">
                                <span class="text-4xl mr-3">📋</span>
                                La Tua Storia Emotiva
                            </h2>
                            <p class="text-gray-400 mt-2">Un viaggio attraverso le tue emozioni</p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold text-purple-400">${this.entries.length}</div>
                            <div class="text-sm text-gray-400">Entrate</div>
                        </div>
                    </div>

                    ${this.currentDateFilter ? `
                        <div class="bg-blue-900/30 border border-blue-500/40 rounded-lg p-4 mb-4 flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                                </svg>
                                <div class="text-blue-300 font-semibold">
                                    Filtrato: ${this.formatDateForDisplay(new Date(this.currentDateFilter))}
                                </div>
                            </div>
                            <button class="px-4 py-2 bg-blue-600/30 hover:bg-blue-600/50 border border-blue-500/50 rounded-lg text-blue-200 font-semibold transition-all" onclick="window.JournalTimeline.instance.clearDateFilter()">
                                Mostra Tutto
                            </button>
                        </div>
                    ` : ''}

                    <div class="bg-purple-900/20 border border-purple-500/30 rounded-lg p-4">
                        <p class="text-purple-300 text-sm flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                            </svg>
                            <strong>🔒 Privato:</strong>&nbsp;Il tuo diario è crittografato. Solo tu puoi leggerlo o ascoltarlo.
                        </p>
                    </div>
                </div>

                <div class="timeline-entries space-y-6">
                    ${groupedEntries.map(group => this.renderTimeGroup(group)).join('')}
                </div>

                <!-- ENTERPRISE: Infinite scroll sentinel (hidden, triggers loading) -->
                ${this.hasMore ? `
                    <div id="scroll-sentinel" class="scroll-sentinel py-8 flex justify-center">
                        <div class="loading-indicator hidden">
                            <svg class="animate-spin w-8 h-8 text-purple-500" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                    </div>
                ` : `
                    <div class="text-center py-8 text-gray-500">
                        <span class="text-sm">— Tutte le entrate caricate —</span>
                    </div>
                `}
            `;

            this.attachEventListeners();
            this._setupInfiniteScroll();

            // V12: Decrypt encrypted photos after rendering
            this._decryptPhotosInView();
        }

        /**
         * V12: Decrypt encrypted photos in the timeline
         * Finds all encrypted photo placeholders and decrypts them
         */
        async _decryptPhotosInView() {
            if (!this.encryptionService || !this.encryptionService.isUnlocked()) {
                console.warn('[JournalTimeline] Cannot decrypt photos - diary not unlocked');
                return;
            }

            const photoContainers = this.container.querySelectorAll('[data-encrypted-photo="true"]');

            for (const container of photoContainers) {
                const mediaUuid = container.dataset.mediaUuid;
                const encryptionIv = container.dataset.encryptionIv;

                if (!mediaUuid || !encryptionIv) {
                    console.warn('[JournalTimeline] Missing photo data:', { mediaUuid, encryptionIv });
                    continue;
                }

                try {
                    // Fetch encrypted photo from server
                    const response = await fetch(`/api/journal/media/${mediaUuid}/stream`, {
                        credentials: 'include',
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const encryptedBlob = await response.blob();

                    // Decrypt photo using DiaryEncryptionService
                    const decryptedBlob = await this.encryptionService.decryptFile(
                        encryptedBlob,
                        encryptionIv,
                        'image/webp'
                    );

                    // Create object URL and show decrypted photo
                    const imageUrl = URL.createObjectURL(decryptedBlob);
                    const placeholder = container.querySelector('.encrypted-photo-placeholder');
                    const img = container.querySelector('.decrypted-photo');

                    if (img) {
                        img.src = imageUrl;
                        img.onload = () => {
                            if (placeholder) placeholder.style.display = 'none';
                            img.classList.remove('hidden');
                        };
                        img.onerror = () => {
                            console.error('[JournalTimeline] Failed to display decrypted photo');
                            if (placeholder) {
                                placeholder.innerHTML = `
                                    <div class="text-center text-red-400">
                                        <span class="text-sm">Errore nel caricamento</span>
                                    </div>
                                `;
                            }
                        };
                    }

                } catch (error) {
                    console.error('[JournalTimeline] Failed to decrypt photo:', mediaUuid, error);
                    const placeholder = container.querySelector('.encrypted-photo-placeholder');
                    if (placeholder) {
                        placeholder.innerHTML = `
                            <div class="text-center text-red-400">
                                <svg class="w-8 h-8 mx-auto mb-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="text-sm">Impossibile decifrare</span>
                            </div>
                        `;
                    }
                }
            }
        }

        groupEntriesByTimeProximity(entries) {
            const groups = [];
            let currentGroup = null;

            entries.forEach(entry => {
                const entryDate = new Date(entry.date);
                const dateKey = entryDate.toISOString().split('T')[0];

                if (!currentGroup || currentGroup.dateKey !== dateKey) {
                    currentGroup = {
                        dateKey,
                        displayDate: this.formatDateForDisplay(entryDate),
                        relativeTime: this.getRelativeTime(entryDate),
                        entries: []
                    };
                    groups.push(currentGroup);
                }
                currentGroup.entries.push(entry);
            });

            return groups;
        }

        renderTimeGroup(group) {
            return `
                <div class="time-group">
                    <div class="flex items-center mb-4">
                        <div class="flex-1 h-px bg-gray-700"></div>
                        <div class="px-4 py-2 bg-gray-800 rounded-lg border border-gray-700">
                            <div class="text-white font-semibold">${group.displayDate}</div>
                            <div class="text-gray-400 text-xs mt-1">${group.relativeTime}</div>
                        </div>
                        <div class="flex-1 h-px bg-gray-700"></div>
                    </div>
                    <div class="space-y-4">
                        ${group.entries.map(entry => this.renderEntry(entry)).join('')}
                    </div>
                </div>
            `;
        }

        /**
         * Render a single journal entry with ENTERPRISE audio player
         */
        renderEntry(entry) {
            const emotionColor = this.getEmotionColor(entry.emotion_category);
            const intensityGlow = this.getIntensityGlow(entry.intensity);

            // ENTERPRISE V12: Unified journal_media fields
            const hasMedia = entry.journal_media_id && entry.media_uuid;
            const isAudioMedia = entry.media_type === 'audio';
            const isPhotoMedia = entry.media_type === 'photo';
            const mediaIsEncrypted = entry.media_is_encrypted;
            const mediaEncryptionIV = entry.media_encryption_iv;
            const mediaLocalPath = entry.media_local_path;
            const mediaDuration = parseFloat(entry.media_duration) || 0;

            // V12: Determine what content types are present
            const hasAudio = hasMedia && isAudioMedia;
            const hasEncryptedPhoto = hasMedia && isPhotoMedia && mediaIsEncrypted;
            const hasPhoto = hasEncryptedPhoto; // V12: Photos are always encrypted
            const duration = mediaDuration;

            // V12.1: Detect feed audio posts (from unified timeline)
            const isFeedAudioPost = entry.source === 'feed';
            const hasAudioPostAudio = isFeedAudioPost && entry.media_s3_url;
            const audioPostUrl = entry.media_s3_url;
            const audioPostDurationNum = mediaDuration;
            const feedVisibility = entry.feed_visibility || 'public';
            const isAudioPostEntry = isFeedAudioPost;

            return `
                <div class="entry-card group" data-entry-uuid="${entry.uuid}">
                    <!-- Emotion Header -->
                    <div class="entry-header">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div class="emotion-indicator ${intensityGlow}" style="background: ${emotionColor};">
                                    <span class="text-2xl">${entry.emotion_icon || '😐'}</span>
                                </div>
                                <div>
                                    <div class="text-white font-semibold">${entry.emotion_name || 'Emozione'}</div>
                                    ${!isAudioPostEntry ? `
                                        <div class="text-gray-400 text-sm flex items-center">
                                            <span class="mr-2">Intensità:</span>
                                            ${this.renderIntensityBar(entry.intensity)}
                                            <span class="ml-2">${entry.intensity}/10</span>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>

                            <div class="flex items-center space-x-2">
                                ${isFeedAudioPost ? `
                                    ${feedVisibility === 'public' ? `
                                        <span class="badge badge-public" title="Audiopost pubblico">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                                            </svg>
                                            Pubblico
                                        </span>
                                    ` : feedVisibility === 'friends_only' ? `
                                        <span class="badge badge-friends" title="Visibile solo agli amici">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"></path>
                                            </svg>
                                            Solo Amici
                                        </span>
                                    ` : `
                                        <span class="badge badge-private" title="Audiopost privato">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                                            </svg>
                                            Privato
                                        </span>
                                    `}
                                ` : `
                                    <span class="badge badge-encrypted" title="Diario criptato (solo tu puoi leggerlo)">
                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                                        </svg>
                                        Privato
                                    </span>
                                `}
                                <span class="text-xs text-gray-500">${this.formatTime(entry.created_at)}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Entry Content -->
                    <div class="entry-content">
                        ${hasEncryptedPhoto ? `
                            <div class="entry-photo mb-4" data-encrypted-photo="true" data-media-uuid="${entry.media_uuid}" data-encryption-iv="${mediaEncryptionIV || ''}">
                                <div class="encrypted-photo-placeholder w-full h-48 bg-gray-800 rounded-lg flex items-center justify-center">
                                    <div class="text-center text-gray-400">
                                        <svg class="w-8 h-8 mx-auto mb-2 animate-pulse" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                                        </svg>
                                        <span class="text-sm">Decifrando foto...</span>
                                    </div>
                                </div>
                                <img class="decrypted-photo w-full h-48 object-cover rounded-lg cursor-pointer hover:opacity-90 transition-opacity hidden"
                                     alt="Foto diario"
                                     onclick="window.JournalTimeline.instance.openPhotoLightbox(this.src)">
                            </div>
                        ` : ''}

                        ${(entry.audio_title || entry.feed_audio_title) ? `
                            <h3 class="entry-title">${typeof linkifyMentions === 'function' ? linkifyMentions(entry.audio_title || entry.feed_audio_title, entry.tagged_users || []) : this.escapeHtml(entry.audio_title || entry.feed_audio_title)}</h3>
                        ` : ''}

                        ${(entry.audio_description || entry.feed_audio_description) ? `
                            <p class="entry-description">${typeof linkifyMentions === 'function' ? linkifyMentions(entry.audio_description || entry.feed_audio_description, entry.tagged_users || []) : this.escapeHtml(entry.audio_description || entry.feed_audio_description)}</p>
                        ` : ''}

                        ${entry.text_content ? `
                            <div class="entry-text mt-3">
                                <p class="text-gray-300 text-sm whitespace-pre-wrap">${this.escapeHtml(entry.text_content)}</p>
                            </div>
                        ` : ''}

                        <!-- ENTERPRISE V12 Audio Player (Local Encrypted) -->
                        ${hasAudio ? `
                            <div class="journal-audio-player mt-4" data-audio-uuid="${entry.media_uuid}" data-encryption-iv="${mediaEncryptionIV || ''}">
                                <div class="audio-player-container bg-gradient-to-r from-gray-800 via-gray-800 to-gray-700 rounded-xl p-4 border border-gray-700/50">
                                    <!-- Player Controls Row -->
                                    <div class="flex items-center space-x-4">
                                        <!-- Play/Pause Button -->
                                        <button class="play-audio-btn w-14 h-14 bg-gradient-to-br from-purple-600 to-purple-700 hover:from-purple-500 hover:to-purple-600 rounded-full flex items-center justify-center transition-all duration-200 shadow-lg shadow-purple-500/20 hover:shadow-purple-500/40"
                                                data-audio-uuid="${entry.media_uuid}"
                                                data-encryption-iv="${mediaEncryptionIV || ''}"
                                                title="Riproduci audio privato">
                                            <svg class="play-icon w-6 h-6 text-white ml-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path>
                                            </svg>
                                            <svg class="pause-icon w-6 h-6 text-white hidden" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                            </svg>
                                            <svg class="loading-icon w-6 h-6 text-white hidden animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </button>

                                        <!-- Progress & Info -->
                                        <div class="flex-1">
                                            <!-- Waveform/Progress Bar -->
                                            <div class="audio-progress-container relative h-10 bg-gray-900/50 rounded-lg overflow-hidden cursor-pointer" data-audio-uuid="${entry.media_uuid}">
                                                <!-- Progress Fill -->
                                                <div class="audio-progress-fill absolute left-0 top-0 h-full bg-gradient-to-r from-purple-500 to-pink-500 transition-all duration-100" style="width: 0%"></div>
                                                <!-- Waveform Visual (decorative) -->
                                                <div class="absolute inset-0 flex items-center justify-center px-2 opacity-50">
                                                    ${this.generateWaveformBars()}
                                                </div>
                                                <!-- Playhead -->
                                                <div class="audio-playhead absolute top-0 h-full w-1 bg-white/80 shadow-lg transition-all duration-100" style="left: 0%"></div>
                                            </div>

                                            <!-- Time Display -->
                                            <div class="flex items-center justify-between mt-2 text-xs">
                                                <span class="audio-current-time text-gray-400">0:00</span>
                                                <div class="flex items-center space-x-2">
                                                    <span class="text-gray-500">
                                                        <svg class="w-3 h-3 inline mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                                                        </svg>
                                                        AES-256
                                                    </span>
                                                    <span class="audio-total-time text-purple-400 font-medium">${this.formatDuration(duration)}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Hidden audio element -->
                                    <audio class="hidden-audio" preload="none"></audio>
                                </div>
                            </div>
                        ` : ''}

                        <!-- ENTERPRISE V12.1: Feed AudioPost Player (Non-Encrypted) -->
                        ${hasAudioPostAudio && audioPostUrl ? `
                            <div class="audiopost-player mt-4" data-post-id="${entry.id}">
                                <div class="audio-player-container bg-gradient-to-r from-gray-800 via-gray-800 to-gray-700 rounded-xl p-4 border border-gray-700/50">
                                    <!-- Player Controls Row -->
                                    <div class="flex items-center space-x-4">
                                        <!-- Play/Pause Button -->
                                        <button class="play-audiopost-btn w-14 h-14 bg-gradient-to-br from-purple-600 to-purple-700 hover:from-purple-500 hover:to-purple-600 rounded-full flex items-center justify-center transition-all duration-200 shadow-lg shadow-purple-500/20 hover:shadow-purple-500/40"
                                                data-audio-url="${audioPostUrl}"
                                                data-post-id="${entry.id}"
                                                title="Riproduci audiopost">
                                            <svg class="play-icon w-6 h-6 text-white ml-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"></path>
                                            </svg>
                                            <svg class="pause-icon w-6 h-6 text-white hidden" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                            </svg>
                                        </button>

                                        <!-- Progress & Info -->
                                        <div class="flex-1">
                                            <!-- Waveform/Progress Bar -->
                                            <div class="audiopost-progress-container relative h-10 bg-gray-900/50 rounded-lg overflow-hidden cursor-pointer" data-post-id="${entry.id}">
                                                <!-- Progress Fill -->
                                                <div class="audiopost-progress-fill absolute left-0 top-0 h-full bg-gradient-to-r from-purple-500 to-pink-500 transition-all duration-100" style="width: 0%"></div>
                                                <!-- Waveform Visual (decorative) -->
                                                <div class="absolute inset-0 flex items-center justify-center px-2 opacity-50">
                                                    ${this.generateWaveformBars()}
                                                </div>
                                                <!-- Playhead -->
                                                <div class="audiopost-playhead absolute top-0 h-full w-1 bg-white/80 shadow-lg transition-all duration-100" style="left: 0%"></div>
                                            </div>

                                            <!-- Time Display -->
                                            <div class="flex items-center justify-between mt-2 text-xs">
                                                <span class="audiopost-current-time text-gray-400">0:00</span>
                                                <div class="flex items-center space-x-2">
                                                    <span class="text-gray-500">
                                                        ${feedVisibility === 'public' ? `
                                                            <svg class="w-3 h-3 inline mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                                                            </svg>
                                                            Pubblico
                                                        ` : feedVisibility === 'friends_only' ? `
                                                            <svg class="w-3 h-3 inline mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                                <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"></path>
                                                            </svg>
                                                            Solo Amici
                                                        ` : `
                                                            <svg class="w-3 h-3 inline mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                                                            </svg>
                                                            Privato
                                                        `}
                                                    </span>
                                                    <span class="audiopost-total-time text-purple-400 font-medium">${this.formatDuration(audioPostDurationNum)}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Hidden audio element for audiopost -->
                                    <audio class="audiopost-audio hidden" preload="none" src="${audioPostUrl}"></audio>
                                </div>
                            </div>
                        ` : ''}
                    </div>

                    <!-- Entry Actions: Delete menu only for diary entries (not feed posts) -->
                    ${!isFeedAudioPost ? `
                        <div class="entry-actions mt-4 flex justify-end">
                            <div class="entry-dropdown-wrapper relative">
                                <button class="entry-dropdown-trigger" data-entry-uuid="${entry.uuid}" title="Opzioni">
                                    <svg class="dropdown-icon" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path>
                                    </svg>
                                </button>
                                <div class="entry-dropdown-menu hidden">
                                    <button class="dropdown-delete-btn" data-entry-uuid="${entry.uuid}">
                                        <svg class="delete-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                        Sposta nel cestino
                                    </button>
                                </div>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        }

        /**
         * Generate decorative waveform bars
         */
        generateWaveformBars() {
            const heights = [30, 50, 70, 40, 80, 60, 45, 75, 55, 65, 35, 85, 50, 70, 40, 60, 75, 45, 55, 80, 35, 65, 50, 70, 45];
            return heights.map(h =>
                `<div class="w-1 bg-purple-400/30 rounded-full mx-px" style="height: ${h}%"></div>`
            ).join('');
        }

        renderIntensityBar(intensity) {
            const bars = [];
            for (let i = 1; i <= 10; i++) {
                const filled = i <= intensity;
                const color = i <= 3 ? 'bg-green-500' : i <= 6 ? 'bg-yellow-500' : i <= 8 ? 'bg-orange-500' : 'bg-red-500';
                bars.push(`<div class="intensity-bar ${filled ? color : 'bg-gray-700'}" style="width: 4px; height: 12px; border-radius: 2px;"></div>`);
            }
            return `<div class="flex items-center space-x-0.5">${bars.join('')}</div>`;
        }

        /**
         * Attach event listeners
         */
        attachEventListeners() {
            // Play buttons (encrypted journal audio)
            this.container.querySelectorAll('.play-audio-btn').forEach(btn => {
                btn.addEventListener('click', this.handlePlayAudio);
            });

            // Progress bar seeking (encrypted)
            this.container.querySelectorAll('.audio-progress-container').forEach(container => {
                container.addEventListener('click', (e) => this.handleProgressSeek(e, container));
            });

            // ENTERPRISE V10.125: Play buttons (public audiopost - non-encrypted)
            this.container.querySelectorAll('.play-audiopost-btn').forEach(btn => {
                btn.addEventListener('click', (e) => this.handlePlayAudioPost(e));
            });

            // ENTERPRISE V10.125: Progress bar seeking (audiopost)
            this.container.querySelectorAll('.audiopost-progress-container').forEach(container => {
                container.addEventListener('click', (e) => this.handleAudioPostProgressSeek(e, container));
            });

            // ENTERPRISE V10.126: Click on audiopost entry-card opens PhotoLightbox
            // Extended to entire entry-card, not just player
            this.container.querySelectorAll('.entry-card').forEach(card => {
                const audiopostPlayer = card.querySelector('.audiopost-player');
                if (!audiopostPlayer) return; // Skip if not an audiopost entry

                const postId = audiopostPlayer.dataset.postId;
                if (!postId) return;

                // Add visual cue that clicking opens lightbox
                card.style.cursor = 'pointer';
                card.addEventListener('click', (e) => {
                    // Don't open lightbox if clicking on interactive elements
                    if (e.target.closest('.play-audiopost-btn') ||
                        e.target.closest('.audiopost-progress-container') ||
                        e.target.closest('.play-audio-btn') ||
                        e.target.closest('.audio-progress-container') ||
                        e.target.closest('.entry-dropdown-wrapper') ||
                        e.target.closest('.dropdown-delete-btn') ||
                        e.target.closest('button')) {
                        return;
                    }
                    if (window.photoLightbox) {
                        window.photoLightbox.openByPostId(parseInt(postId));
                    }
                });
            });

            // ENTERPRISE: Dropdown triggers (three dots menu)
            this.container.querySelectorAll('.entry-dropdown-trigger').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.toggleDropdown(btn);
                });
            });

            // ENTERPRISE: Dropdown delete buttons
            this.container.querySelectorAll('.dropdown-delete-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.handleDeleteClick(e);
                    // Close dropdown after action
                    const dropdown = btn.closest('.entry-dropdown-wrapper').querySelector('.entry-dropdown-menu');
                    dropdown?.classList.add('hidden');
                });
            });

            // ENTERPRISE: Close dropdowns when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.entry-dropdown-wrapper')) {
                    this.closeAllDropdowns();
                }
            });

        }

        /**
         * ENTERPRISE: Setup infinite scroll with Intersection Observer
         * Loads more entries when user scrolls near the bottom
         * Automatically stops when all entries are loaded
         */
        _setupInfiniteScroll() {
            // Disconnect previous observer if exists
            if (this.scrollObserver) {
                this.scrollObserver.disconnect();
                this.scrollObserver = null;
            }

            // Don't setup if no more entries
            if (!this.hasMore) {
                return;
            }

            const sentinel = document.getElementById('scroll-sentinel');
            if (!sentinel) return;

            this.scrollSentinel = sentinel;

            // Create Intersection Observer
            this.scrollObserver = new IntersectionObserver(
                (entries) => {
                    const [entry] = entries;
                    if (entry.isIntersecting && !this.isLoading && this.hasMore) {
                        this._loadMoreEntries();
                    }
                },
                {
                    root: null, // viewport
                    rootMargin: '200px', // Start loading 200px before reaching the sentinel
                    threshold: 0
                }
            );

            this.scrollObserver.observe(sentinel);
        }

        /**
         * ENTERPRISE: Load more entries (called by infinite scroll)
         */
        async _loadMoreEntries() {
            if (this.isLoading || !this.hasMore) return;

            // Show loading indicator
            const loadingIndicator = this.scrollSentinel?.querySelector('.loading-indicator');
            if (loadingIndicator) {
                loadingIndicator.classList.remove('hidden');
            }

            await this.loadTimeline(this.currentPage + 1);

            // Hide loading indicator (will be removed if hasMore=false after render)
            if (loadingIndicator) {
                loadingIndicator.classList.add('hidden');
            }
        }

        /**
         * Handle progress bar seeking (encrypted journal audio)
         * ENTERPRISE V10.140: MediaSource Extensions API for proper WebM seeking
         *
         * WebM blobs don't support efficient seeking because WebM is a streaming format
         * without a seek index. The enterprise solution is to use MSE (Media Source Extensions)
         * which is what YouTube, Netflix, and Spotify use.
         *
         * For short audio (30s), we store the raw ArrayBuffer and recreate the MediaSource
         * for each seek operation.
         */
        handleProgressSeek(e, container) {
            const playerContainer = container.closest('.journal-audio-player');
            const audioElement = playerContainer?.querySelector('.hidden-audio');
            const audioUuid = container.dataset.audioUuid;

            if (!audioElement || !audioElement.duration || !isFinite(audioElement.duration)) {
                return;
            }

            const wasPlaying = !audioElement.paused;

            // Calculate new time from click position
            const rect = container.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            const percentage = Math.max(0, Math.min(1, clickX / rect.width));
            const newTime = percentage * audioElement.duration;

            // Pause current playback
            audioElement.pause();

            // Get the raw audio data from cache
            const audioData = this.decryptedAudioDataCache?.get(audioUuid);

            if (audioData) {
                // Use MSE for proper seeking
                this._seekWithMSE(audioElement, audioData, newTime, wasPlaying, playerContainer);
            } else {
                // Fallback: direct seek (may not work perfectly)
                audioElement.currentTime = newTime;
                this.updateAudioProgress(playerContainer, audioElement);
                if (wasPlaying) {
                    this._tryPlayWithRetry(audioElement, playerContainer);
                }
            }
        }

        /**
         * ENTERPRISE V10.140: Seek using Media Source Extensions
         * This provides proper seeking support for WebM audio
         */
        async _seekWithMSE(audioElement, audioData, seekTime, shouldPlay, playerContainer) {
            const btn = this.activePlayButton;
            const playIcon = btn?.querySelector('.play-icon');
            const pauseIcon = btn?.querySelector('.pause-icon');

            try {
                // Check if MSE is supported
                if (!window.MediaSource || !MediaSource.isTypeSupported('audio/webm; codecs="opus"')) {
                    console.warn('[JournalTimeline] MSE not supported, falling back to direct seek');
                    audioElement.currentTime = seekTime;
                    this.updateAudioProgress(playerContainer, audioElement);
                    if (shouldPlay) {
                        this._tryPlayWithRetry(audioElement, playerContainer);
                    }
                    return;
                }

                // Create new MediaSource
                const mediaSource = new MediaSource();
                const objectUrl = URL.createObjectURL(mediaSource);

                // Store old src for cleanup
                const oldSrc = audioElement.src;

                await new Promise((resolve, reject) => {
                    mediaSource.addEventListener('sourceopen', async () => {
                        try {
                            const sourceBuffer = mediaSource.addSourceBuffer('audio/webm; codecs="opus"');

                            sourceBuffer.addEventListener('updateend', () => {
                                if (mediaSource.readyState === 'open') {
                                    mediaSource.endOfStream();
                                }
                                resolve();
                            }, { once: true });

                            sourceBuffer.addEventListener('error', (e) => {
                                reject(new Error('SourceBuffer error'));
                            }, { once: true });

                            // Append the audio data
                            sourceBuffer.appendBuffer(audioData);
                        } catch (e) {
                            reject(e);
                        }
                    }, { once: true });

                    mediaSource.addEventListener('error', () => {
                        reject(new Error('MediaSource error'));
                    }, { once: true });

                    // Set the new source
                    audioElement.src = objectUrl;
                });

                // Cleanup old blob URL
                if (oldSrc && oldSrc.startsWith('blob:')) {
                    URL.revokeObjectURL(oldSrc);
                }

                // Wait for audio to be ready
                await new Promise((resolve) => {
                    if (audioElement.readyState >= 2) {
                        resolve();
                    } else {
                        audioElement.addEventListener('canplay', resolve, { once: true });
                        setTimeout(resolve, 2000); // Timeout fallback
                    }
                });

                // Seek to desired position
                audioElement.currentTime = seekTime;

                // Update UI
                this.updateAudioProgress(playerContainer, audioElement);

                // Resume if was playing
                if (shouldPlay) {
                    this.currentPlayPromise = audioElement.play();
                    await this.currentPlayPromise;
                    this.currentPlayPromise = null;

                    if (playIcon && pauseIcon) {
                        playIcon.classList.add('hidden');
                        pauseIcon.classList.remove('hidden');
                    }
                }
            } catch (err) {
                console.error('[JournalTimeline] MSE seek failed:', err.message);
                this.currentPlayPromise = null;

                // Reset UI
                if (playIcon && pauseIcon) {
                    playIcon.classList.remove('hidden');
                    pauseIcon.classList.add('hidden');
                }
            }
        }

        /**
         * ENTERPRISE V10.140: Try to play with retry logic
         */
        async _tryPlayWithRetry(audioElement, playerContainer, retries = 3) {
            const btn = this.activePlayButton;
            const playIcon = btn?.querySelector('.play-icon');
            const pauseIcon = btn?.querySelector('.pause-icon');

            for (let i = 0; i < retries; i++) {
                try {
                    // Wait a bit for browser to stabilize
                    await new Promise(r => setTimeout(r, 100 * (i + 1)));

                    this.currentPlayPromise = audioElement.play();
                    await this.currentPlayPromise;
                    this.currentPlayPromise = null;

                    if (playIcon && pauseIcon) {
                        playIcon.classList.add('hidden');
                        pauseIcon.classList.remove('hidden');
                    }
                    return; // Success
                } catch (err) {
                    if (err.name === 'AbortError') {
                        return; // User action, stop retrying
                    }
                    if (i === retries - 1) {
                        console.warn('[JournalTimeline] Play failed after retries:', err.message);
                    }
                }
            }
        }

        /**
         * ENTERPRISE V10.128: Handle audiopost progress bar seeking (public, non-encrypted)
         * Fixed seek: pause before seek, then resume - prevents browser getting stuck
         */
        handleAudioPostProgressSeek(e, container) {
            const playerContainer = container.closest('.audiopost-player');
            const audioElement = playerContainer?.querySelector('.audiopost-audio');

            if (audioElement && audioElement.duration) {
                // ENTERPRISE V10.128: Save playing state BEFORE seek
                const wasPlaying = !audioElement.paused;

                const rect = container.getBoundingClientRect();
                const clickX = e.clientX - rect.left;
                const percentage = Math.max(0, Math.min(1, clickX / rect.width));
                const newTime = percentage * audioElement.duration;

                // ENTERPRISE V10.128: Pause before seek to prevent browser getting stuck
                if (wasPlaying) {
                    audioElement.pause();
                }

                // Set new position
                audioElement.currentTime = newTime;

                // Update UI immediately
                this.updateAudioPostProgress(playerContainer, audioElement);

                // ENTERPRISE V10.128: Resume if was playing
                if (wasPlaying) {
                    // Small delay to let browser buffer the new position
                    setTimeout(() => {
                        this.currentPlayPromise = audioElement.play();
                        this.currentPlayPromise
                            .then(() => {
                                this.currentPlayPromise = null;
                            })
                            .catch(err => {
                                this.currentPlayPromise = null;
                                if (err.name !== 'AbortError') {
                                    console.warn('[JournalTimeline] AudioPost resume after seek failed:', err);
                                }
                            });
                    }, 10);
                }
            }
        }

        /**
         * ENTERPRISE V10.127: Handle play audiopost (public, non-encrypted - direct streaming)
         */
        handlePlayAudioPost(event) {
            const btn = event.currentTarget;
            const audioUrl = btn.dataset.audioUrl;
            const postId = btn.dataset.postId;
            const playerContainer = btn.closest('.audiopost-player');
            const audioElement = playerContainer.querySelector('.audiopost-audio');

            const playIcon = btn.querySelector('.play-icon');
            const pauseIcon = btn.querySelector('.pause-icon');

            // If already playing this audio, toggle pause
            if (this.activeAudioElement === audioElement && !audioElement.paused) {
                audioElement.pause();
                playIcon.classList.remove('hidden');
                pauseIcon.classList.add('hidden');
                return;
            }

            // Stop any currently playing audio (including encrypted journal audio)
            if (this.activeAudioElement && this.activeAudioElement !== audioElement) {
                this.activeAudioElement.pause();
                if (this.activePlayButton) {
                    this.activePlayButton.querySelector('.play-icon')?.classList.remove('hidden');
                    this.activePlayButton.querySelector('.pause-icon')?.classList.add('hidden');
                }
            }

            // Set up event listeners for progress update
            // ENTERPRISE V10.141: Only update duration from audioElement if valid (not Infinity)
            // For streaming audio (S3 presigned URLs), browser may return Infinity until fully loaded
            // Database duration is always reliable, so prefer it as source of truth
            audioElement.onloadedmetadata = () => {
                const totalTimeEl = playerContainer.querySelector('.audiopost-total-time');
                if (totalTimeEl && audioElement.duration && isFinite(audioElement.duration) && audioElement.duration > 0) {
                    totalTimeEl.textContent = this.formatDuration(audioElement.duration);
                }
            };

            audioElement.ontimeupdate = () => {
                this.updateAudioPostProgress(playerContainer, audioElement);
            };

            audioElement.onended = () => {
                playIcon.classList.remove('hidden');
                pauseIcon.classList.add('hidden');
                this.updateAudioPostProgress(playerContainer, audioElement, true);
            };

            // ENTERPRISE V10.127: Set active BEFORE play to prevent race conditions
            this.activeAudioElement = audioElement;
            this.activePlayButton = btn;

            // Play directly (no decryption needed)
            audioElement.play()
                .then(() => {
                    playIcon.classList.add('hidden');
                    pauseIcon.classList.remove('hidden');
                })
                .catch(error => {
                    // AbortError is normal (user action), not a real failure
                    if (error.name === 'AbortError') return;
                    console.error('[JournalTimeline] AudioPost playback failed:', error);
                    this.showToast('Errore durante la riproduzione', 'error');
                });
        }

        /**
         * ENTERPRISE V10.141: Update audiopost progress UI
         * Fixed to handle Infinity duration from streaming audio
         */
        updateAudioPostProgress(playerContainer, audioElement, reset = false) {
            const progressFill = playerContainer.querySelector('.audiopost-progress-fill');
            const playhead = playerContainer.querySelector('.audiopost-playhead');
            const currentTimeEl = playerContainer.querySelector('.audiopost-current-time');

            if (reset || audioElement.currentTime === 0) {
                if (progressFill) progressFill.style.width = '0%';
                if (playhead) playhead.style.left = '0%';
                if (currentTimeEl) currentTimeEl.textContent = '0:00';
                return;
            }

            // ENTERPRISE V10.141: Handle Infinity duration gracefully
            // For streaming audio, duration may be Infinity until fully loaded
            // In this case, don't update progress bar (avoid NaN%)
            if (!isFinite(audioElement.duration) || audioElement.duration <= 0) {
                if (currentTimeEl) currentTimeEl.textContent = this.formatDuration(audioElement.currentTime);
                return;
            }

            const percentage = (audioElement.currentTime / audioElement.duration) * 100;

            if (progressFill) progressFill.style.width = `${percentage}%`;
            if (playhead) playhead.style.left = `${percentage}%`;
            if (currentTimeEl) currentTimeEl.textContent = this.formatDuration(audioElement.currentTime);
        }

        /**
         * Handle play audio (with decryption)
         * ENTERPRISE V10.132: Clean refactor with proper race condition handling
         */
        async handlePlayAudio(event) {
            const btn = event.currentTarget;
            const audioUuid = btn.dataset.audioUuid;
            const encryptionIv = btn.dataset.encryptionIv;
            const playerContainer = btn.closest('.journal-audio-player');
            const audioElement = playerContainer.querySelector('.hidden-audio');

            const playIcon = btn.querySelector('.play-icon');
            const pauseIcon = btn.querySelector('.pause-icon');
            const loadingIcon = btn.querySelector('.loading-icon');

            // Toggle pause if already playing this audio
            if (this.activeAudioElement === audioElement && !audioElement.paused) {
                audioElement.pause();
                playIcon.classList.remove('hidden');
                pauseIcon.classList.add('hidden');
                return;
            }

            // Stop any currently playing audio (from another entry)
            this._stopCurrentAudio();

            // Set this as active
            this.activeAudioElement = audioElement;
            this.activePlayButton = btn;

            // If audio is already loaded (has src), resume playback
            if (audioElement.src && audioElement.src !== '') {
                await this._playLoadedAudio(audioElement, playIcon, pauseIcon);
                return;
            }

            // Need to decrypt and load for first time
            await this._decryptAndPlay(audioUuid, encryptionIv, audioElement, playerContainer, playIcon, pauseIcon, loadingIcon);
        }

        /**
         * ENTERPRISE V10.132: Stop currently playing audio and reset its UI
         */
        _stopCurrentAudio() {
            if (this.activeAudioElement) {
                this.activeAudioElement.pause();
            }
            if (this.activePlayButton) {
                this.activePlayButton.querySelector('.play-icon')?.classList.remove('hidden');
                this.activePlayButton.querySelector('.pause-icon')?.classList.add('hidden');
            }
            this.currentPlayPromise = null;
        }

        /**
         * ENTERPRISE V10.134: Play already-loaded audio with proper state handling
         * Simplified: just play directly - blob URLs are always ready
         */
        async _playLoadedAudio(audioElement, playIcon, pauseIcon) {
            try {
                // Track promise to prevent race conditions
                this.currentPlayPromise = audioElement.play();
                await this.currentPlayPromise;
                this.currentPlayPromise = null;

                playIcon.classList.add('hidden');
                pauseIcon.classList.remove('hidden');
            } catch (e) {
                this.currentPlayPromise = null;
                if (e.name === 'AbortError') {
                    return; // Normal: user clicked elsewhere
                }
                console.warn('[JournalTimeline] Play failed:', e.message);
            }
        }

        /**
         * ENTERPRISE V10.140: Decrypt and play audio for first time
         * Also stores raw ArrayBuffer for MSE-based seeking
         */
        async _decryptAndPlay(audioUuid, encryptionIv, audioElement, playerContainer, playIcon, pauseIcon, loadingIcon) {
            playIcon.classList.add('hidden');
            loadingIcon.classList.remove('hidden');

            try {
                // Check cache first
                let blobUrl = this.decryptedAudioCache.get(audioUuid);

                if (!blobUrl) {
                    // Fetch encrypted audio
                    const response = await fetch(`/api/journal/audio/${audioUuid}/stream`);
                    if (!response.ok) throw new Error('Failed to fetch audio');

                    const encryptedBlob = await response.blob();

                    // Decrypt using DiaryEncryptionService (TRUE E2E - client-side only)
                    if (!this.encryptionService) {
                        throw new Error('DiaryEncryptionService not available');
                    }

                    const decryptedBlob = await this.encryptionService.decryptFile(
                        encryptedBlob,
                        encryptionIv,
                        'audio/webm'
                    );

                    // ENTERPRISE V10.140: Store both blob URL and raw ArrayBuffer
                    // ArrayBuffer is needed for MSE-based seeking
                    blobUrl = URL.createObjectURL(decryptedBlob);
                    this.decryptedAudioCache.set(audioUuid, blobUrl);

                    // Store raw ArrayBuffer for MSE seeking
                    const arrayBuffer = await decryptedBlob.arrayBuffer();
                    this.decryptedAudioDataCache.set(audioUuid, arrayBuffer);
                }

                audioElement.src = blobUrl;

                // Set up event listeners (only once per audio element)
                this._setupAudioListeners(audioElement, playerContainer, playIcon, pauseIcon);

                // Track promise to prevent race conditions
                this.currentPlayPromise = audioElement.play();
                await this.currentPlayPromise;
                this.currentPlayPromise = null;

                loadingIcon.classList.add('hidden');
                pauseIcon.classList.remove('hidden');

            } catch (error) {
                this.currentPlayPromise = null;
                loadingIcon.classList.add('hidden');
                playIcon.classList.remove('hidden');

                if (error.name === 'AbortError') {
                    // Normal: user clicked elsewhere during load
                    return;
                }
                console.error('[JournalTimeline] Audio playback failed:', error);
                this.showToast('Errore durante la riproduzione audio', 'error');
            }
        }

        /**
         * ENTERPRISE V10.132: Setup audio element event listeners
         */
        _setupAudioListeners(audioElement, playerContainer, playIcon, pauseIcon) {
            // Metadata loaded - update duration display
            audioElement.onloadedmetadata = () => {
                const totalTimeEl = playerContainer.querySelector('.audio-total-time');
                if (totalTimeEl) {
                    totalTimeEl.textContent = this.formatDuration(audioElement.duration);
                }
            };

            // Time update - update progress bar
            audioElement.ontimeupdate = () => {
                this.updateAudioProgress(playerContainer, audioElement);
            };

            // Audio ended - reset UI (with race condition prevention)
            audioElement.onended = () => {
                // Cancel any pending play promise before resetting UI
                this.currentPlayPromise = null;
                playIcon.classList.remove('hidden');
                pauseIcon.classList.add('hidden');
                this.updateAudioProgress(playerContainer, audioElement, true);
            };
        }

        /**
         * Update audio progress UI
         */
        updateAudioProgress(playerContainer, audioElement, reset = false) {
            const progressFill = playerContainer.querySelector('.audio-progress-fill');
            const playhead = playerContainer.querySelector('.audio-playhead');
            const currentTimeEl = playerContainer.querySelector('.audio-current-time');

            if (reset || audioElement.currentTime === 0) {
                if (progressFill) progressFill.style.width = '0%';
                if (playhead) playhead.style.left = '0%';
                if (currentTimeEl) currentTimeEl.textContent = '0:00';
                return;
            }

            const percentage = (audioElement.currentTime / audioElement.duration) * 100;

            if (progressFill) progressFill.style.width = `${percentage}%`;
            if (playhead) playhead.style.left = `${percentage}%`;
            if (currentTimeEl) currentTimeEl.textContent = this.formatDuration(audioElement.currentTime);
        }

        /**
         * ENTERPRISE: Toggle dropdown menu visibility
         * @param {HTMLElement} triggerBtn - The three dots button
         */
        toggleDropdown(triggerBtn) {
            const wrapper = triggerBtn.closest('.entry-dropdown-wrapper');
            const menu = wrapper?.querySelector('.entry-dropdown-menu');
            if (!menu) return;

            // Close all other dropdowns first
            this.closeAllDropdowns();

            // Toggle this dropdown
            menu.classList.toggle('hidden');
        }

        /**
         * ENTERPRISE: Close all open dropdowns
         */
        closeAllDropdowns() {
            this.container?.querySelectorAll('.entry-dropdown-menu').forEach(menu => {
                menu.classList.add('hidden');
            });
        }

        /**
         * Handle delete click (soft delete)
         */
        async handleDeleteClick(event) {
            const btn = event.currentTarget;
            const entryUuid = btn.dataset.entryUuid;

            if (!entryUuid) return;
            if (!confirm('Spostare questa entrata nel cestino?\n\nPotrai recuperarla entro 30 giorni.')) return;

            btn.disabled = true;
            const originalContent = btn.innerHTML;
            btn.innerHTML = `<svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;

            try {
                const response = await api.delete(`/api/journal/${entryUuid}/soft-delete`);

                if (response.success) {
                    this.showToast('Spostata nel cestino', 'success');
                    await this.loadTimeline(1);
                    if (this.calendarSidebar) {
                        this.calendarSidebar.invalidateCache();
                        await this.calendarSidebar.loadCalendarData();
                        this.calendarSidebar.render();
                    }
                } else {
                    throw new Error(response.error || 'Errore');
                }
            } catch (error) {
                this.showToast(error.message, 'error');
                btn.disabled = false;
                btn.innerHTML = originalContent;
            }
        }

        renderLoadingState() {
            this.container.innerHTML = `
                <div class="text-center py-12">
                    <svg class="animate-spin w-12 h-12 mx-auto text-purple-500 mb-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-gray-400">Caricamento...</p>
                </div>
            `;
        }

        renderEmptyState() {
            this.container.innerHTML = `
                <div class="text-center py-16">
                    <div class="text-8xl mb-6">📝</div>
                    <h3 class="text-2xl font-bold text-white mb-4">Il Tuo Diario È Vuoto</h3>
                    <p class="text-gray-400 mb-8 max-w-md mx-auto">
                        Inizia a registrare le tue emozioni per creare la tua storia emotiva.
                    </p>
                    <button onclick="window.ProfileTabs.switchToTab('diario')" class="px-6 py-3 bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 rounded-lg font-medium transition-all">
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Crea la Prima Entrata
                    </button>
                </div>
            `;
        }

        renderErrorState() {
            this.container.innerHTML = `
                <div class="text-center py-16">
                    <div class="text-6xl mb-6">⚠️</div>
                    <h3 class="text-2xl font-bold text-white mb-4">Errore di Caricamento</h3>
                    <p class="text-gray-400 mb-8">Non siamo riusciti a caricare la timeline.</p>
                    <button onclick="window.JournalTimeline.init()" class="px-6 py-3 bg-purple-600 hover:bg-purple-500 text-white rounded-lg font-medium transition-all">
                        Riprova
                    </button>
                </div>
            `;
        }

        showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<div class="toast-content">${type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ'} <span>${message}</span></div>`;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        /**
         * V12: Open photo in fullscreen lightbox
         * Shows only the photo on dark background, click to close
         */
        openPhotoLightbox(imageSrc) {
            if (!imageSrc) return;

            // Create lightbox overlay
            const lightbox = document.createElement('div');
            lightbox.className = 'journal-photo-lightbox';
            lightbox.innerHTML = `
                <div class="journal-photo-lightbox__backdrop"></div>
                <div class="journal-photo-lightbox__content">
                    <img src="${imageSrc}" alt="Foto diario" class="journal-photo-lightbox__image">
                </div>
                <button class="journal-photo-lightbox__close" aria-label="Chiudi">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            `;

            // Close on backdrop click
            lightbox.querySelector('.journal-photo-lightbox__backdrop').addEventListener('click', () => {
                this.closePhotoLightbox(lightbox);
            });

            // Close on button click
            lightbox.querySelector('.journal-photo-lightbox__close').addEventListener('click', () => {
                this.closePhotoLightbox(lightbox);
            });

            // Close on ESC key
            const handleEsc = (e) => {
                if (e.key === 'Escape') {
                    this.closePhotoLightbox(lightbox);
                    document.removeEventListener('keydown', handleEsc);
                }
            };
            document.addEventListener('keydown', handleEsc);

            // Add to DOM and animate in
            document.body.appendChild(lightbox);
            document.body.style.overflow = 'hidden';

            // Trigger animation
            requestAnimationFrame(() => {
                lightbox.classList.add('journal-photo-lightbox--visible');
            });
        }

        /**
         * V12: Close photo lightbox
         */
        closePhotoLightbox(lightbox) {
            lightbox.classList.remove('journal-photo-lightbox--visible');
            document.body.style.overflow = '';

            // Remove after animation
            setTimeout(() => {
                lightbox.remove();
            }, 300);
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        formatDateForDisplay(date) {
            return date.toLocaleDateString('it-IT', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        }

        getRelativeTime(date) {
            const diffDays = Math.floor((new Date() - date) / (1000 * 60 * 60 * 24));
            if (diffDays === 0) return 'Oggi';
            if (diffDays === 1) return 'Ieri';
            if (diffDays === 2) return "L'altro ieri";
            if (diffDays <= 7) return `${diffDays} giorni fa`;
            if (diffDays <= 30) return `${Math.floor(diffDays / 7)} settimane fa`;
            if (diffDays <= 365) return `${Math.floor(diffDays / 30)} mesi fa`;
            return `${Math.floor(diffDays / 365)} anni fa`;
        }

        formatTime(datetime) {
            return new Date(datetime).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
        }

        formatDuration(seconds) {
            if (!seconds || isNaN(seconds)) return '0:00';
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }

        getEmotionColor(category) {
            return category === 'positive' ? '#10b981' : category === 'negative' ? '#ef4444' : '#6b7280';
        }

        getIntensityGlow(intensity) {
            if (intensity >= 8) return 'intensity-high';
            if (intensity >= 5) return 'intensity-medium';
            return 'intensity-low';
        }
    }

    // Create singleton
    const journalTimeline = new JournalTimeline();

    // Expose to global scope
    window.JournalTimeline = {
        init: () => journalTimeline.init(),
        instance: journalTimeline,
    };
})();
