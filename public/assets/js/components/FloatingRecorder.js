/**
 * need2talk - Floating Recorder Component
 * Enterprise Galaxy - Global Audio Recording Button
 *
 * Purpose: Floating Action Button (FAB) for audio recording
 * Visibility: ALL pages (feed, profile, journal, etc.)
 * Integration: Uses AudioRecorder.js component
 *
 * Performance: <20ms render time, <50ms modal open
 * Scalability: 1M+ concurrent users
 */

class FloatingRecorder {
    /**
     * Initialize Floating Recorder
     */
    constructor() {
        this.fabButton = null;
        this.modal = null;
        this.audioRecorder = null;
        this.isModalOpen = false;
        this.isRecording = false;
        this.uploadedPhoto = null; // Store uploaded photo blob

        // ENTERPRISE V4 (2025-11-30): @mention autocomplete state
        this.friendsList = [];              // Cached friends for autocomplete
        this.friendsLoaded = false;         // Flag to avoid reloading
        this.mentionDropdown = null;        // Active dropdown element
        this.mentionStartPos = -1;          // Position where @ was typed
        this.mentionQuery = '';             // Current search query
        this.selectedMentionIndex = 0;      // Keyboard navigation index
        this.selectedMentions = [];         // Array of selected mentions for this post

        // Config
        this.config = {
            uploadEndpoint: window.need2talk?.api?.audioUpload || '/api/audio/upload',
            fabPosition: 'bottom-left', // bottom-right, bottom-left
        };

        this.init();
    }

    /**
     * Initialize component
     */
    init() {
        // ENTERPRISE V9.3 (2025-12-02): Skip initialization on chat page
        // The chat page has its own message input with audio recording
        if (window.location.pathname === '/chat' ||
            window.location.pathname.startsWith('/chat/')) {
            return;
        }

        // Create FAB button
        this.createFabButton();

        // Create modal overlay
        this.createModal();

        // Attach event listeners
        this.attachEventListeners();

        // ENTERPRISE V6.7 (2025-11-30): Listen for friends cache updates (WebSocket)
        this.setupFriendsCacheListener();
    }

    /**
     * ENTERPRISE V6.7 (2025-11-30): Setup listener for friends cache updates
     * When a friendship is accepted via WebSocket, the new friend is added to our cache
     */
    setupFriendsCacheListener() {
        document.addEventListener('friends:cache_updated', (event) => {
            const { action, friend } = event.detail || {};

            if (action === 'add' && friend) {
                // Add to our local friends list if loaded
                if (this.friendsLoaded && this.friendsList) {
                    // Check if already exists (prevent duplicates)
                    const exists = this.friendsList.some(f => f.uuid === friend.uuid);
                    if (!exists) {
                        this.friendsList.push({
                            uuid: friend.uuid,
                            nickname: friend.nickname,
                            avatar_url: friend.avatar_url
                        });
                    }
                }
            }
        });
    }

    /**
     * Create Floating Action Button
     *
     * Position: Fixed bottom-right (20px from edges)
     * Z-index: 50 (above most content, below toasts)
     * Icon: Microphone + Label
     * Animation: Pulse continuous, scale on hover
     * UX: Clear call-to-action for users
     */
    createFabButton() {
        const fab = document.createElement('button');
        fab.id = 'floating-recorder-button';

        // CRITICAL: Use inline styles for positioning (Tailwind classes fail on dynamic elements)
        // ENTERPRISE V10.35: Moved to left side for better UX
        fab.style.cssText = `
            position: fixed;
            bottom: 30px;
            left: 30px;
            z-index: 9999;
            width: 95px;
            height: 95px;
            background: linear-gradient(135deg, #ec4899 0%, #9333ea 50%, #7c3aed 100%);
            border-radius: 50%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            outline: none;
            padding: 10px;
        `;

        fab.setAttribute('aria-label', 'Registra un audio messaggio');
        fab.setAttribute('title', 'Clicca per registrare il tuo messaggio vocale');

        // Content: Microphone icon + label (inline styles for reliability)
        fab.innerHTML = `
            <!-- Pulse animation (stops after 10s) -->
            <span class="fab-pulse-ring-1" style="position: absolute; display: inline-flex; height: 100%; width: 100%; border-radius: 50%; background: #f472b6; opacity: 0.6; animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite; pointer-events: none; transition: opacity 0.5s ease;"></span>
            <span class="fab-pulse-ring-2" style="position: absolute; display: inline-flex; height: 90%; width: 90%; border-radius: 50%; background: #c084fc; opacity: 0.4; animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; pointer-events: none; transition: opacity 0.5s ease;"></span>

            <!-- Microphone icon -->
            <svg style="width: 34px; height: 34px; color: white; position: relative; z-index: 10; margin-bottom: 2px;"
                 fill="none"
                 stroke="currentColor"
                 viewBox="0 0 24 24"
                 stroke-width="2.5">
                <path stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z">
                </path>
            </svg>

            <!-- Label "Registra" -->
            <span style="font-size: 9px; font-weight: bold; color: white; text-transform: uppercase; letter-spacing: 0.05em; position: relative; z-index: 10;">
                REGISTRA
            </span>
        `;

        document.body.appendChild(fab);
        this.fabButton = fab;

        // Stop pulse animation after 10 seconds (per user request)
        setTimeout(() => {
            const pulseRing1 = fab.querySelector('.fab-pulse-ring-1');
            const pulseRing2 = fab.querySelector('.fab-pulse-ring-2');

            if (pulseRing1) {
                pulseRing1.style.animation = 'none';
                pulseRing1.style.opacity = '0';
            }
            if (pulseRing2) {
                pulseRing2.style.animation = 'none';
                pulseRing2.style.opacity = '0';
            }
        }, 10000);

        // Add hover effects (compensate for removed Tailwind hover classes)
        fab.addEventListener('mouseenter', () => {
            fab.style.transform = 'scale(1.15)';
            fab.style.boxShadow = '0 0 40px rgba(236, 72, 153, 0.6), 0 20px 25px -5px rgba(0, 0, 0, 0.3)';
        });

        fab.addEventListener('mouseleave', () => {
            fab.style.transform = 'scale(1)';
            fab.style.boxShadow = '0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2)';
        });
    }

    /**
     * Create Modal Overlay
     *
     * Contains AudioRecorder component
     * Full-screen overlay with backdrop blur
     * Close button and ESC key support
     */
    createModal() {
        const modal = document.createElement('div');
        modal.id = 'floating-recorder-modal';
        modal.className = 'hidden'; // Hidden by default
        modal.innerHTML = `
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity" style="z-index: 9998;"></div>

            <!-- Modal Container -->
            <div class="fixed inset-0 overflow-y-auto" style="z-index: 9999;">
                <div class="flex min-h-full items-center justify-center p-4">

                    <!-- Modal Content -->
                    <div class="relative bg-gradient-to-b from-gray-900 via-gray-900 to-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl transform transition-all border border-purple-500/20">

                        <!-- Header -->
                        <div class="flex items-center justify-between p-6 border-b border-gray-700/50 bg-gradient-to-r from-purple-500/10 to-pink-500/10">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-r from-pink-500 to-purple-600 rounded-full flex items-center justify-center shadow-lg shadow-purple-500/30">
                                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Registra il tuo messaggio</h3>
                                    <p class="text-sm text-gray-400 flex items-center">
                                        <svg class="w-4 h-4 mr-1 text-purple-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                                        </svg>
                                        Massimo 30 secondi
                                    </p>
                                </div>
                            </div>
                            <button id="close-recorder-modal"
                                    class="text-gray-400 hover:text-white transition-colors">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>

                        <!-- Privacy Selector (ENTERPRISE REDESIGN: Elegant Dropdown) -->
                        <div class="px-6 pt-4 pb-4 border-b border-gray-700/50">
                            <label class="block text-sm font-semibold text-white mb-3 flex items-center">
                                <svg class="w-5 h-5 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                Chi può vedere questo post?
                            </label>

                            <!-- Privacy Dropdown Button (Single Elegant Button) -->
                            <div class="relative">
                                <button type="button" id="privacy-dropdown-btn" class="w-full px-4 py-3 bg-gray-800 border-2 border-purple-500/50 rounded-lg hover:border-purple-500 transition-all flex items-center justify-between text-left">
                                    <div class="flex items-center gap-3">
                                        <span id="privacy-selected-icon" class="text-2xl">🌍</span>
                                        <div>
                                            <span id="privacy-selected-label" class="text-sm font-semibold text-white block">Pubblico</span>
                                            <span id="privacy-selected-description" class="text-xs text-gray-400">Chiunque può vedere questo post</span>
                                        </div>
                                    </div>
                                    <svg class="w-5 h-5 text-gray-400 transition-transform" id="privacy-dropdown-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </button>

                                <!-- Dropdown Menu (Hidden by default) -->
                                <div id="privacy-dropdown-menu" class="hidden absolute z-50 mt-2 w-full bg-gray-900 border-2 border-purple-500 rounded-lg shadow-2xl overflow-hidden">
                                    <button type="button" class="privacy-option w-full px-4 py-3 hover:bg-gray-800 transition-all flex items-center gap-3 border-b border-gray-700 bg-purple-500/10" data-privacy="public" data-icon="🌍" data-label="Pubblico" data-description="Chiunque può vedere questo post">
                                        <span class="text-2xl">🌍</span>
                                        <div class="flex-1 text-left">
                                            <span class="text-sm font-semibold text-white block">Pubblico</span>
                                            <span class="text-xs text-gray-400">Chiunque può vedere</span>
                                        </div>
                                        <svg class="w-5 h-5 text-purple-400 privacy-checkmark" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </button>
                                    <button type="button" class="privacy-option w-full px-4 py-3 hover:bg-gray-800 transition-all flex items-center gap-3 border-b border-gray-700" data-privacy="friends" data-icon="👥" data-label="Amici" data-description="Solo i tuoi amici possono vedere">
                                        <span class="text-2xl">👥</span>
                                        <div class="flex-1 text-left">
                                            <span class="text-sm font-semibold text-white block">Amici</span>
                                            <span class="text-xs text-gray-400">Solo amici</span>
                                        </div>
                                        <svg class="w-5 h-5 text-purple-400 privacy-checkmark hidden" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </button>
                                    <button type="button" class="privacy-option w-full px-4 py-3 hover:bg-gray-800 transition-all flex items-center gap-3 border-b border-gray-700" data-privacy="friends_of_friends" data-icon="🤝" data-label="Amici di amici" data-description="Amici e amici di amici">
                                        <span class="text-2xl">🤝</span>
                                        <div class="flex-1 text-left">
                                            <span class="text-sm font-semibold text-white block">Amici di amici</span>
                                            <span class="text-xs text-gray-400">Amici + amici di amici</span>
                                        </div>
                                        <svg class="w-5 h-5 text-purple-400 privacy-checkmark hidden" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </button>
                                    <button type="button" class="privacy-option w-full px-4 py-3 hover:bg-gray-800 transition-all flex items-center gap-3" data-privacy="private" data-icon="🔒" data-label="Privato" data-description="Solo tu puoi vedere questo post">
                                        <span class="text-2xl">🔒</span>
                                        <div class="flex-1 text-left">
                                            <span class="text-sm font-semibold text-white block">Privato</span>
                                            <span class="text-xs text-gray-400">Solo tu</span>
                                        </div>
                                        <svg class="w-5 h-5 text-purple-400 privacy-checkmark hidden" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </button>
                                </div>
                                <input type="hidden" id="post-privacy" value="public">
                            </div>
                        </div>

                        <!-- Emotion Selector -->
                        <div class="px-6 pt-4">
                            <label class="block text-sm font-semibold text-white mb-3 flex items-center">
                                <svg class="w-5 h-5 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Come ti senti? *
                            </label>
                            <div id="floating-emotion-selector" class="grid grid-cols-5 gap-3">
                                <!-- Positive emotions (1-5) -->
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="1" data-emotion-name="Gioia">
                                    <span class="text-3xl mb-1">😊</span>
                                    <span class="text-xs text-gray-400">Gioia</span>
                                </button>
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="2" data-emotion-name="Meraviglia">
                                    <span class="text-3xl mb-1">✨</span>
                                    <span class="text-xs text-gray-400">Meraviglia</span>
                                </button>
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="3" data-emotion-name="Amore">
                                    <span class="text-3xl mb-1">❤️</span>
                                    <span class="text-xs text-gray-400">Amore</span>
                                </button>
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="4" data-emotion-name="Gratitudine">
                                    <span class="text-3xl mb-1">🙏</span>
                                    <span class="text-xs text-gray-400">Gratitudine</span>
                                </button>
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="5" data-emotion-name="Speranza">
                                    <span class="text-3xl mb-1">🌟</span>
                                    <span class="text-xs text-gray-400">Speranza</span>
                                </button>
                                <!-- Negative emotions (6-10) -->
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="6" data-emotion-name="Tristezza">
                                    <span class="text-3xl mb-1">😢</span>
                                    <span class="text-xs text-gray-400">Tristezza</span>
                                </button>
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="7" data-emotion-name="Rabbia">
                                    <span class="text-3xl mb-1">😠</span>
                                    <span class="text-xs text-gray-400">Rabbia</span>
                                </button>
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="8" data-emotion-name="Ansia">
                                    <span class="text-3xl mb-1">😰</span>
                                    <span class="text-xs text-gray-400">Ansia</span>
                                </button>
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="9" data-emotion-name="Paura">
                                    <span class="text-3xl mb-1">😨</span>
                                    <span class="text-xs text-gray-400">Paura</span>
                                </button>
                                <button type="button" class="emotion-btn flex flex-col items-center p-3 rounded-lg border-2 border-gray-700 hover:border-purple-500 hover:bg-purple-500/10 hover:scale-105 transition-all duration-200" data-emotion-id="10" data-emotion-name="Solitudine">
                                    <span class="text-3xl mb-1">😔</span>
                                    <span class="text-xs text-gray-400">Solitudine</span>
                                </button>
                            </div>

                            <!-- ENTERPRISE V11.9 (2026-01-18): Emotion Required Notice -->
                            <div class="mt-3 flex items-start gap-2 text-sm text-gray-400 bg-purple-500/10 border border-purple-500/30 rounded-lg p-3">
                                <svg class="w-5 h-5 text-purple-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <div>
                                    <span class="font-semibold text-purple-300">Attenzione:</span>
                                    Selezionare un'<strong class="text-white">emozione è obbligatorio</strong> per poter registrare.
                                    <span class="text-gray-500">Aiuta gli altri a connettersi con te emotivamente.</span>
                                </div>
                            </div>
                        </div>

                        <!-- Metadata Section (Title, Description, Photo, Emoji, Tags) -->
                        <div class="px-6 pt-4 border-t border-gray-700/50 mt-4">
                            <label class="block text-sm font-semibold text-white mb-3 flex items-center">
                                <svg class="w-5 h-5 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                Dettagli Post (Opzionali)
                            </label>

                            <!-- Title with Inline Emoji Picker (WhatsApp Style) + @mention Support -->
                            <div class="mb-4">
                                <div class="flex items-center gap-2">
                                    <!-- ENTERPRISE V6.7 (2025-11-30): Relative wrapper for @mention dropdown positioning -->
                                    <div class="relative flex-1">
                                        <input type="text"
                                               id="post-title"
                                               placeholder="Titolo del tuo messaggio... (usa @nickname per taggare)"
                                               maxlength="100"
                                               class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-colors">
                                        <!-- @mention dropdown will be injected here by JS -->
                                    </div>
                                    <!-- Emoji Picker Button (Inline) -->
                                    <button type="button" id="emoji-picker-title-btn" class="w-10 h-10 flex items-center justify-center bg-gray-800 border border-gray-700 rounded-lg hover:border-purple-500 hover:bg-purple-500/10 transition-all" title="Aggiungi emoji">
                                        <span class="text-xl">😊</span>
                                    </button>
                                </div>
                                <div class="text-xs text-gray-500 mt-1 text-right">
                                    <span id="title-char-count">0</span>/100
                                </div>
                            </div>

                            <!-- Description with @Mention Support + Emoji Picker -->
                            <div class="mb-4">
                                <div class="flex items-start gap-2">
                                    <!-- ENTERPRISE V4 (2025-11-30): Relative wrapper for @mention dropdown positioning -->
                                    <div class="relative flex-1">
                                        <textarea id="post-description"
                                                  placeholder="Descrizione... (usa @nickname per taggare amici)"
                                                  maxlength="500"
                                                  rows="3"
                                                  class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-colors resize-none"></textarea>
                                        <!-- @mention dropdown will be injected here by JS -->
                                    </div>
                                    <!-- Emoji Picker Button (Inline) -->
                                    <button type="button" id="emoji-picker-description-btn" class="w-10 h-10 flex items-center justify-center bg-gray-800 border border-gray-700 rounded-lg hover:border-purple-500 hover:bg-purple-500/10 transition-all shrink-0" title="Aggiungi emoji">
                                        <span class="text-xl">😊</span>
                                    </button>
                                </div>
                                <div class="flex items-center justify-end text-xs text-gray-500 mt-1">
                                    <span><span id="description-char-count">0</span>/500</span>
                                </div>
                            </div>

                            <!-- Photo Upload with Preview -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-400 mb-2">
                                    📷 Foto di Copertina
                                </label>
                                <div class="flex items-center gap-3">
                                    <!-- File Input (Hidden) -->
                                    <input type="file"
                                           id="post-photo-input"
                                           accept="image/jpeg,image/png,image/webp,image/gif"
                                           class="hidden">

                                    <!-- Upload Button -->
                                    <button type="button"
                                            id="post-photo-btn"
                                            class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white text-sm font-medium transition-colors flex items-center">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        Scegli Foto
                                    </button>

                                    <!-- Preview Container -->
                                    <div id="post-photo-preview" class="hidden relative">
                                        <img id="post-photo-preview-img"
                                             src=""
                                             alt="Preview"
                                             class="w-16 h-16 rounded-lg object-cover border-2 border-purple-500">
                                        <button type="button"
                                                id="post-photo-remove"
                                                class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 hover:bg-red-600 rounded-full text-white flex items-center justify-center transition-colors">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- File Info -->
                                    <span id="post-photo-info" class="text-xs text-gray-500"></span>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Max 2MB • Auto-resize 800x800px • JPEG/PNG/WebP/GIF</p>
                            </div>

                            <!-- WhatsApp-Style Emoji Picker Popup (ENTERPRISE REDESIGN) -->
                            <!-- Fixed Fullscreen Overlay (Hidden by default) -->
                            <div id="emoji-picker-popup" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center" style="z-index: 10000;">
                                <div class="bg-gray-900 border-2 border-purple-500 rounded-2xl shadow-2xl w-full max-w-lg max-h-[600px] overflow-hidden">
                                    <!-- Header -->
                                    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-700 bg-gradient-to-r from-purple-500/10 to-pink-500/10">
                                        <h4 class="text-white font-semibold flex items-center gap-2">
                                            <span class="text-2xl">😊</span>
                                            Scegli Emoji
                                        </h4>
                                        <button type="button" id="emoji-picker-close-btn" class="text-gray-400 hover:text-white transition-colors">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Category Tabs (WhatsApp Style) -->
                                    <div class="flex items-center gap-1 px-3 py-2 border-b border-gray-700 bg-gray-800/50 overflow-x-auto">
                                        <button type="button" class="emoji-category-tab flex-shrink-0 px-3 py-2 text-2xl rounded-lg hover:bg-gray-700 transition-all active" data-category="smileys" title="Smileys & People">
                                            😊
                                        </button>
                                        <button type="button" class="emoji-category-tab flex-shrink-0 px-3 py-2 text-2xl rounded-lg hover:bg-gray-700 transition-all" data-category="hearts" title="Hearts & Love">
                                            ❤️
                                        </button>
                                        <button type="button" class="emoji-category-tab flex-shrink-0 px-3 py-2 text-2xl rounded-lg hover:bg-gray-700 transition-all" data-category="celebration" title="Celebration">
                                            🎉
                                        </button>
                                        <button type="button" class="emoji-category-tab flex-shrink-0 px-3 py-2 text-2xl rounded-lg hover:bg-gray-700 transition-all" data-category="animals" title="Animals & Nature">
                                            🐱
                                        </button>
                                        <button type="button" class="emoji-category-tab flex-shrink-0 px-3 py-2 text-2xl rounded-lg hover:bg-gray-700 transition-all" data-category="food" title="Food & Drink">
                                            🍕
                                        </button>
                                        <button type="button" class="emoji-category-tab flex-shrink-0 px-3 py-2 text-2xl rounded-lg hover:bg-gray-700 transition-all" data-category="activities" title="Activities">
                                            ⚽
                                        </button>
                                        <button type="button" class="emoji-category-tab flex-shrink-0 px-3 py-2 text-2xl rounded-lg hover:bg-gray-700 transition-all" data-category="symbols" title="Symbols">
                                            ✨
                                        </button>
                                    </div>

                                    <!-- Emoji Grid (Scrollable) -->
                                    <div class="p-4 overflow-y-auto" style="max-height: 450px;">
                                        <!-- Smileys & People (Active by default) -->
                                        <!-- Smileys & People Category -->
                                        <div class="emoji-category-content" data-category="smileys">
                                            <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">Smileys & People</h5>
                                            <div style="display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 0.25rem; margin-bottom: 1rem;">
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😊">😊</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😂">😂</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤣">🤣</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😁">😁</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😄">😄</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😃">😃</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😀">😀</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😅">😅</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😆">😆</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🥰">🥰</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😍">😍</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😘">😘</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😗">😗</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😙">😙</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😚">😚</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤗">🤗</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤩">🤩</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😎">😎</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🥳">🥳</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😏">😏</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😌">😌</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😉">😉</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤭">🤭</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😔">😔</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😢">😢</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😭">😭</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😩">😩</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😓">😓</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😞">😞</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😖">😖</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🥺">🥺</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😱">😱</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😨">😨</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😰">😰</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😳">😳</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😡">😡</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😠">😠</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤬">🤬</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="😈">😈</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="👿">👿</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💀">💀</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="☠️">☠️</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💩">💩</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤡">🤡</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="👻">👻</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="👽">👽</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤖">🤖</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤔">🤔</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤫">🤫</button>
                                            </div>
                                        </div>

                                        <!-- Hearts & Love Category (Hidden) -->
                                        <div class="emoji-category-content hidden" data-category="hearts">
                                            <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">Hearts & Love</h5>
                                            <div style="display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 0.25rem; margin-bottom: 1rem;">
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="❤️">❤️</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🧡">🧡</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💛">💛</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💚">💚</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💙">💙</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💜">💜</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🖤">🖤</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤍">🤍</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤎">🤎</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💔">💔</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="❣️">❣️</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💕">💕</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💞">💞</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💓">💓</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💗">💗</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💖">💖</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💘">💘</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💝">💝</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💟">💟</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌹">🌹</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌺">🌺</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌸">🌸</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌼">🌼</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌻">🌻</button>
                                            </div>
                                        </div>

                                        <!-- Celebration Category (Hidden) -->
                                        <div class="emoji-category-content hidden" data-category="celebration">
                                            <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">Celebration</h5>
                                            <div style="display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 0.25rem; margin-bottom: 1rem;">
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎉">🎉</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎊">🎊</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎈">🎈</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎁">🎁</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎀">🎀</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎂">🎂</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍰">🍰</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🧁">🧁</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🥳">🥳</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎃">🎃</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎄">🎄</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎆">🎆</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎇">🎇</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="✨">✨</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎸">🎸</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎵">🎵</button>
                                            </div>
                                        </div>

                                        <!-- Animals Category (Hidden) -->
                                        <div class="emoji-category-content hidden" data-category="animals">
                                            <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">Animals & Nature</h5>
                                            <div style="display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 0.25rem; margin-bottom: 1rem;">
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐶">🐶</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐱">🐱</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐭">🐭</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐹">🐹</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐰">🐰</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🦊">🦊</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐻">🐻</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐼">🐼</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐨">🐨</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐯">🐯</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🦁">🦁</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐮">🐮</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐷">🐷</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐸">🐸</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐵">🐵</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🙈">🙈</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🙉">🙉</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🙊">🙊</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐔">🐔</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐧">🐧</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐦">🐦</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🦄">🦄</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🐝">🐝</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🦋">🦋</button>
                                            </div>
                                        </div>

                                        <!-- Food Category (Hidden) -->
                                        <div class="emoji-category-content hidden" data-category="food">
                                            <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">Food & Drink</h5>
                                            <div style="display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 0.25rem; margin-bottom: 1rem;">
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍕">🍕</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍔">🍔</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍟">🍟</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌭">🌭</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍿">🍿</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍩">🍩</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍪">🍪</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍫">🍫</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍬">🍬</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍭">🍭</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍰">🍰</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🧁">🧁</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍎">🍎</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍊">🍊</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍋">🍋</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍌">🍌</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍉">🍉</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍇">🍇</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍓">🍓</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍑">🍑</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="☕">☕</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍵">🍵</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🥤">🥤</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🍺">🍺</button>
                                            </div>
                                        </div>

                                        <!-- Activities Category (Hidden) -->
                                        <div class="emoji-category-content hidden" data-category="activities">
                                            <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">Activities</h5>
                                            <div style="display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 0.25rem; margin-bottom: 1rem;">
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="⚽">⚽</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🏀">🏀</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🏈">🏈</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="⚾">⚾</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎾">🎾</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🏐">🏐</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🏓">🏓</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🏸">🏸</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🥊">🥊</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🏋️">🏋️</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🚴">🚴</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🏊">🏊</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎮">🎮</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎯">🎯</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🎲">🎲</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="♟️">♟️</button>
                                            </div>
                                        </div>

                                        <!-- Symbols Category (Hidden) -->
                                        <div class="emoji-category-content hidden" data-category="symbols">
                                            <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">Symbols</h5>
                                            <div style="display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 0.25rem; margin-bottom: 1rem;">
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="⭐">⭐</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌟">🌟</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="✨">✨</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💫">💫</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🔥">🔥</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💥">💥</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💢">💢</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="💪">💪</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="👍">👍</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="👎">👎</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="👊">👊</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="✌️">✌️</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🤞">🤞</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🙏">🙏</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="👏">👏</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌈">🌈</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="☀️">☀️</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌙">🌙</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="⚡">⚡</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="⛅">⛅</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌱">🌱</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="🌷">🌷</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="⏰">⏰</button>
                                                <button type="button" class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded" data-emoji="⏱️">⏱️</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- AudioRecorder Container -->
                        <div class="p-6">
                            <div id="floating-audio-recorder">
                                <!-- AudioRecorder.js renders here -->
                            </div>
                        </div>

                        <!-- Footer Info -->
                        <div class="px-6 py-4 bg-gray-800/50 rounded-b-2xl">
                            <div class="flex items-center justify-center text-sm">
                                <span class="text-gray-400 italic">Fai parlare la tua anima</span>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
        `;

        document.body.appendChild(modal);
        this.modal = modal;
    }

    /**
     * Attach Event Listeners
     */
    attachEventListeners() {
        // FAB button click -> open modal
        this.fabButton.addEventListener('click', () => this.openModal());

        // Close button click
        const closeBtn = document.getElementById('close-recorder-modal');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.closeModal());
        }

        // Backdrop click -> close modal
        const backdrop = this.modal.querySelector('.fixed.inset-0.bg-black\\/60');
        if (backdrop) {
            backdrop.addEventListener('click', () => this.closeModal());
        }

        // ESC key -> close modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isModalOpen) {
                this.closeModal();
            }
        });
    }

    /**
     * Open Modal
     *
     * Initialize AudioRecorder component if needed
     * Show modal with fade-in animation
     */
    openModal() {
        if (this.isModalOpen) return;

        // Initialize AudioRecorder if not exists
        if (!this.audioRecorder) {
            this.initAudioRecorder();
        }

        // Show modal
        this.modal.classList.remove('hidden');
        this.isModalOpen = true;

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        // Animate in
        setTimeout(() => {
            const backdrop = this.modal.querySelector('.fixed.inset-0.bg-black\\/60');
            const modalContent = this.modal.querySelector('.relative.rounded-2xl');

            if (backdrop) backdrop.style.opacity = '1';
            if (modalContent) modalContent.style.transform = 'scale(1)';
        }, 10);

        // Hide FAB while modal open
        this.fabButton.style.opacity = '0';
        this.fabButton.style.pointerEvents = 'none';
    }

    /**
     * Close Modal
     *
     * Stop recording if active
     * Reset AudioRecorder state
     * Hide modal with fade-out animation
     */
    closeModal() {
        if (!this.isModalOpen) return;

        // ENTERPRISE V11.5 (2025-12-13): Full reset when closing modal
        // This resets everything (audio + emotion) for clean slate on reopen
        if (this.audioRecorder) {
            this.audioRecorder.fullReset();
        }

        // Animate out
        const backdrop = this.modal.querySelector('.fixed.inset-0.bg-black\\/60');
        const modalContent = this.modal.querySelector('.relative.rounded-2xl');

        if (backdrop) backdrop.style.opacity = '0';
        if (modalContent) modalContent.style.transform = 'scale(0.95)';

        // Hide after animation
        setTimeout(() => {
            this.modal.classList.add('hidden');
            this.isModalOpen = false;

            // Restore body scroll
            document.body.style.overflow = '';

            // Show FAB
            this.fabButton.style.opacity = '1';
            this.fabButton.style.pointerEvents = 'auto';

            // ENTERPRISE V11.5 (2025-12-13): Reset all form state when modal closes
            this.resetModalState();
        }, 200);
    }

    /**
     * Reset all modal form state
     * ENTERPRISE V11.5 (2025-12-13): Clean slate for next recording session
     * @private
     */
    resetModalState() {
        // Reset title and description inputs
        const titleInput = this.modal.querySelector('#post-title');
        const descriptionInput = this.modal.querySelector('#post-description');
        if (titleInput) {
            titleInput.value = '';
            const charCount = this.modal.querySelector('#title-char-count');
            if (charCount) charCount.textContent = '0';
        }
        if (descriptionInput) {
            descriptionInput.value = '';
            const charCount = this.modal.querySelector('#description-char-count');
            if (charCount) charCount.textContent = '0';
        }

        // Reset emotion selector buttons
        const emotionButtons = this.modal.querySelectorAll('.emotion-btn');
        emotionButtons.forEach(btn => {
            btn.classList.remove('border-purple-500', 'bg-purple-500/20', 'scale-110', 'shadow-lg', 'shadow-purple-500/50', 'ring-2', 'ring-purple-400', 'ring-offset-2', 'ring-offset-gray-900');
            btn.classList.add('border-gray-700');
            btn.style.transform = '';
        });

        // Reset privacy selector to default (public)
        const privacyInput = this.modal.querySelector('#post-privacy');
        if (privacyInput) privacyInput.value = 'public';

        const privacyIcon = this.modal.querySelector('#privacy-selected-icon');
        const privacyLabel = this.modal.querySelector('#privacy-selected-label');
        const privacyDesc = this.modal.querySelector('#privacy-selected-description');
        if (privacyIcon) privacyIcon.textContent = '🌍';
        if (privacyLabel) privacyLabel.textContent = 'Pubblico';
        if (privacyDesc) privacyDesc.textContent = 'Chiunque può vedere questo post';

        // Reset photo upload
        this.uploadedPhoto = null;
        const photoPreview = this.modal.querySelector('#photo-preview-container');
        if (photoPreview) photoPreview.classList.add('hidden');
        const photoInput = this.modal.querySelector('#post-photo-input');
        if (photoInput) photoInput.value = '';

        // Reset mentions
        this.selectedMentions = [];
        const mentionsContainer = this.modal.querySelector('#selected-mentions-container');
        if (mentionsContainer) mentionsContainer.innerHTML = '';

        // Reset tags input if exists
        const tagsInput = this.modal.querySelector('#post-tags');
        if (tagsInput) tagsInput.value = '';

        console.log('[FloatingRecorder] Modal state reset');
    }

    /**
     * Initialize AudioRecorder Component
     *
     * Render AudioRecorder inside modal
     * Handle recording completion callback
     */
    initAudioRecorder() {
        try {
            // ENTERPRISE V11.8: Check if AudioRecorder class is loaded
            if (typeof AudioRecorder === 'undefined') {
                throw new Error('AudioRecorder class not loaded - check script order');
            }

            this.audioRecorder = new AudioRecorder({
                containerId: 'floating-audio-recorder',
                uploadEndpoint: this.config.uploadEndpoint,
                maxDuration: 30,
                maxFileSize: 500 * 1024,

                // Callback to collect metadata before upload
                onBeforeUpload: () => {
                    return this.getMetadata();
                },

                onRecordingComplete: (result) => {
                    // Close modal
                    this.closeModal();

                    // Show success notification
                    this.showSuccessNotification('Audio caricato con successo!');

                    // ENTERPRISE GALAXY: Instant UI update (pass response data)
                    this.reloadPageContent(result);
                },

                onError: (error) => {
                    console.error('FloatingRecorder: Recording error', error);
                    this.showErrorNotification(error.message || 'Errore durante la registrazione');
                }
            });

            // Attach emotion selector event listeners
            this.attachEmotionSelectorListeners();

            // Attach metadata input listeners
            this.attachMetadataListeners();

        } catch (error) {
            // ENTERPRISE V11.8: Better error logging for debugging
            const errorMsg = error?.message || String(error) || 'Unknown error';
            const errorStack = error?.stack || '';
            console.error('FloatingRecorder: Failed to init AudioRecorder:', errorMsg, errorStack);
            this.showErrorNotification('Errore durante l\'inizializzazione del registratore');
        }
    }

    /**
     * Attach event listeners to emotion selector buttons
     * @private
     */
    attachEmotionSelectorListeners() {
        const emotionButtons = this.modal.querySelectorAll('.emotion-btn');

        emotionButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const emotionId = parseInt(btn.dataset.emotionId, 10);
                const emotionName = btn.dataset.emotionName;
                const emotionEmoji = btn.querySelector('.text-3xl')?.textContent?.trim() || '😊';

                // Remove active class from all buttons
                emotionButtons.forEach(b => {
                    b.classList.remove('border-purple-500', 'bg-purple-500/20', 'scale-110', 'shadow-lg', 'shadow-purple-500/50', 'ring-2', 'ring-purple-400', 'ring-offset-2', 'ring-offset-gray-900');
                    b.classList.add('border-gray-700');
                    b.style.transform = '';
                });

                // Add active class to clicked button (ENTERPRISE: Multiple visual cues)
                btn.classList.remove('border-gray-700');
                btn.classList.add('border-purple-500', 'bg-purple-500/20', 'shadow-lg', 'shadow-purple-500/50', 'ring-2', 'ring-purple-400', 'ring-offset-2', 'ring-offset-gray-900');
                btn.style.transform = 'scale(1.15)'; // Force transform with inline style

                // Set emotion in AudioRecorder (pass emoji and name)
                if (this.audioRecorder) {
                    this.audioRecorder.setEmotion(emotionId, emotionName, emotionEmoji);
                }
            });
        });
    }

    /**
     * Attach event listeners to metadata input fields
     * @private
     */
    attachMetadataListeners() {
        // Title character counter + @mention autocomplete
        // ENTERPRISE V6.7 (2025-11-30): Added @mention support to title field (same as description)
        const titleInput = this.modal.querySelector('#post-title');
        const titleCharCount = this.modal.querySelector('#title-char-count');
        if (titleInput && titleCharCount) {
            // Load friends list on focus for @mention autocomplete
            titleInput.addEventListener('focus', () => {
                this.loadFriendsList();
            });

            titleInput.addEventListener('input', () => {
                titleCharCount.textContent = titleInput.value.length;
                // ENTERPRISE V6.7: Handle @mention autocomplete in title
                this.handleMentionInput(titleInput);
            });

            // ENTERPRISE V6.7: Keyboard navigation for @mention dropdown
            titleInput.addEventListener('keydown', (e) => {
                if (this.handleMentionKeydown(e, titleInput)) {
                    e.preventDefault();
                }
            });
        }

        // Description character counter + @mention autocomplete
        const descriptionInput = this.modal.querySelector('#post-description');
        const descriptionCharCount = this.modal.querySelector('#description-char-count');
        if (descriptionInput && descriptionCharCount) {
            // ENTERPRISE V4 (2025-11-30): Load friends list on focus for @mention autocomplete
            descriptionInput.addEventListener('focus', () => {
                this.loadFriendsList();
            });

            descriptionInput.addEventListener('input', () => {
                descriptionCharCount.textContent = descriptionInput.value.length;

                // ENTERPRISE V4 (2025-11-30): Handle @mention autocomplete
                this.handleMentionInput(descriptionInput);
            });

            // ENTERPRISE V4 (2025-11-30): Keyboard navigation for @mention dropdown
            descriptionInput.addEventListener('keydown', (e) => {
                if (this.handleMentionKeydown(e, descriptionInput)) {
                    e.preventDefault();
                }
            });
        }

        // Photo upload button
        const photoBtn = this.modal.querySelector('#post-photo-btn');
        const photoInput = this.modal.querySelector('#post-photo-input');
        if (photoBtn && photoInput) {
            photoBtn.addEventListener('click', () => photoInput.click());

            photoInput.addEventListener('change', async (e) => {
                const file = e.target.files[0];
                if (file) {
                    await this.handlePhotoUpload(file);
                }
            });
        }

        // Photo remove button
        const photoRemoveBtn = this.modal.querySelector('#post-photo-remove');
        if (photoRemoveBtn) {
            photoRemoveBtn.addEventListener('click', () => {
                this.removePhoto();
            });
        }

        // ENTERPRISE REDESIGN: Privacy Dropdown (Single Elegant Button)
        const privacyDropdownBtn = this.modal.querySelector('#privacy-dropdown-btn');
        const privacyDropdownMenu = this.modal.querySelector('#privacy-dropdown-menu');
        const privacyDropdownArrow = this.modal.querySelector('#privacy-dropdown-arrow');
        const privacyOptions = this.modal.querySelectorAll('.privacy-option');
        const privacyInput = this.modal.querySelector('#post-privacy');
        const privacySelectedIcon = this.modal.querySelector('#privacy-selected-icon');
        const privacySelectedLabel = this.modal.querySelector('#privacy-selected-label');
        const privacySelectedDescription = this.modal.querySelector('#privacy-selected-description');

        // Toggle dropdown menu
        if (privacyDropdownBtn) {
            privacyDropdownBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                privacyDropdownMenu.classList.toggle('hidden');
                privacyDropdownArrow.classList.toggle('rotate-180');
            });
        }

        // Handle privacy option selection
        privacyOptions.forEach(option => {
            option.addEventListener('click', () => {
                const privacy = option.dataset.privacy;
                const icon = option.dataset.icon;
                const label = option.dataset.label;
                const description = option.dataset.description;

                // Update button display
                if (privacySelectedIcon) privacySelectedIcon.textContent = icon;
                if (privacySelectedLabel) privacySelectedLabel.textContent = label;
                if (privacySelectedDescription) privacySelectedDescription.textContent = description;

                // Update hidden input
                if (privacyInput) privacyInput.value = privacy;

                // Update checkmarks
                this.modal.querySelectorAll('.privacy-checkmark').forEach(check => {
                    check.classList.add('hidden');
                });
                option.querySelector('.privacy-checkmark')?.classList.remove('hidden');

                // Update active background
                privacyOptions.forEach(opt => opt.classList.remove('bg-purple-500/10'));
                option.classList.add('bg-purple-500/10');

                // Close dropdown
                privacyDropdownMenu.classList.add('hidden');
                privacyDropdownArrow.classList.remove('rotate-180');
            });
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (privacyDropdownMenu && !privacyDropdownMenu.contains(e.target) &&
                e.target !== privacyDropdownBtn && !privacyDropdownBtn.contains(e.target)) {
                privacyDropdownMenu?.classList.add('hidden');
                privacyDropdownArrow?.classList.remove('rotate-180');
            }
        });

        // ENTERPRISE REDESIGN: WhatsApp-Style Emoji Picker (Category Tabs + 100+ Emojis)
        const emojiPickerTitleBtn = this.modal.querySelector('#emoji-picker-title-btn');
        const emojiPickerDescriptionBtn = this.modal.querySelector('#emoji-picker-description-btn');
        const emojiPickerPopup = this.modal.querySelector('#emoji-picker-popup');
        const emojiPickerCloseBtn = this.modal.querySelector('#emoji-picker-close-btn');
        const emojiCategoryTabs = this.modal.querySelectorAll('.emoji-category-tab');
        const emojiCategoryContents = this.modal.querySelectorAll('.emoji-category-content');
        const emojiButtons = this.modal.querySelectorAll('.emoji-btn');
        let currentEmojiTarget = null; // 'title' or 'description'

        // Toggle emoji picker for title
        if (emojiPickerTitleBtn) {
            emojiPickerTitleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                currentEmojiTarget = 'title';
                emojiPickerPopup.classList.remove('hidden');
            });
        }

        // Toggle emoji picker for description
        if (emojiPickerDescriptionBtn) {
            emojiPickerDescriptionBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                currentEmojiTarget = 'description';
                emojiPickerPopup.classList.remove('hidden');
            });
        }

        // Close button
        if (emojiPickerCloseBtn) {
            emojiPickerCloseBtn.addEventListener('click', () => {
                emojiPickerPopup.classList.add('hidden');
            });
        }

        // Category tab switching
        emojiCategoryTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const category = tab.dataset.category;

                // Update active tab
                emojiCategoryTabs.forEach(t => {
                    t.classList.remove('active', 'bg-purple-500/20');
                });
                tab.classList.add('active', 'bg-purple-500/20');

                // Show corresponding category content
                emojiCategoryContents.forEach(content => {
                    if (content.dataset.category === category) {
                        content.classList.remove('hidden');
                    } else {
                        content.classList.add('hidden');
                    }
                });
            });
        });

        // Insert emoji on click
        emojiButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const emoji = btn.dataset.emoji;
                let targetInput = null;
                let charCountEl = null;

                if (currentEmojiTarget === 'title' && titleInput) {
                    targetInput = titleInput;
                    charCountEl = titleCharCount;
                } else if (currentEmojiTarget === 'description' && descriptionInput) {
                    targetInput = descriptionInput;
                    charCountEl = descriptionCharCount;
                }

                if (targetInput) {
                    // Insert emoji at cursor position
                    const cursorPos = targetInput.selectionStart || targetInput.value.length;
                    const textBefore = targetInput.value.substring(0, cursorPos);
                    const textAfter = targetInput.value.substring(cursorPos);
                    targetInput.value = textBefore + emoji + textAfter;
                    targetInput.focus();
                    targetInput.setSelectionRange(cursorPos + emoji.length, cursorPos + emoji.length);

                    // Update char count
                    if (charCountEl) {
                        charCountEl.textContent = targetInput.value.length;
                    }

                    // Hide popup after selection
                    emojiPickerPopup.classList.add('hidden');
                }
            });
        });

        // Close emoji picker when clicking outside (on backdrop)
        if (emojiPickerPopup) {
            emojiPickerPopup.addEventListener('click', (e) => {
                // Close only if clicking on the backdrop (not on the modal content)
                if (e.target === emojiPickerPopup) {
                    emojiPickerPopup.classList.add('hidden');
                }
            });
        }
    }

    /**
     * Handle photo upload with client-side resize
     * @private
     * @param {File} file Image file
     */
    async handlePhotoUpload(file) {
        try {
            // Validate file type
            if (!file.type.match(/image\/(jpeg|png|webp|gif)/)) {
                this.showErrorNotification('Formato non supportato. Usa JPEG, PNG, WebP o GIF');
                return;
            }

            // Validate file size (max 5MB before resize)
            if (file.size > 5 * 1024 * 1024) {
                this.showErrorNotification('Foto troppo grande (max 5MB)');
                return;
            }

            // Show loading state
            const photoInfo = this.modal.querySelector('#post-photo-info');
            if (photoInfo) {
                photoInfo.textContent = 'Elaborazione...';
            }

            // ENTERPRISE GALAXY: Client-side resize to server target dimensions
            // - Max 1920x1920 (same as PhotoOptimizationService::MAX_WIDTH/HEIGHT)
            // - Quality 0.92 (high quality to preserve detail before WebP conversion)
            // - Reduces upload bandwidth (1-2MB vs 5-10MB original)
            // - Server receives optimal dimensions (no degrading double compression)
            const resizedBlob = await this.resizeImage(file, 1920, 1920, 0.92);

            // Store resized photo
            this.uploadedPhoto = resizedBlob;

            // Show preview
            const previewContainer = this.modal.querySelector('#post-photo-preview');
            const previewImg = this.modal.querySelector('#post-photo-preview-img');
            if (previewContainer && previewImg) {
                const previewUrl = URL.createObjectURL(resizedBlob);
                previewImg.src = previewUrl;
                previewContainer.classList.remove('hidden');
            }

            // Update info (show actual dimensions after resize)
            if (photoInfo) {
                const sizeKB = Math.round(resizedBlob.size / 1024);
                photoInfo.textContent = `${sizeKB}KB (max 1920x1920, quality 92%)`;
            }

        } catch (error) {
            console.error('FloatingRecorder: Photo upload failed', error);
            this.showErrorNotification('Errore durante l\'elaborazione della foto');
        }
    }

    /**
     * Remove uploaded photo
     * @private
     */
    removePhoto() {
        this.uploadedPhoto = null;

        const previewContainer = this.modal.querySelector('#post-photo-preview');
        const previewImg = this.modal.querySelector('#post-photo-preview-img');
        const photoInput = this.modal.querySelector('#post-photo-input');
        const photoInfo = this.modal.querySelector('#post-photo-info');

        if (previewContainer) previewContainer.classList.add('hidden');
        if (previewImg) {
            URL.revokeObjectURL(previewImg.src);
            previewImg.src = '';
        }
        if (photoInput) photoInput.value = '';
        if (photoInfo) photoInfo.textContent = '';
    }

    /**
     * Resize image using Canvas API (client-side)
     *
     * ENTERPRISE PATTERN (Instagram/Facebook/Twitter):
     * - Client pre-processing: Reduce upload bandwidth + server load
     * - Server post-processing: WebP conversion + thumbnails + security validation
     * - Defense in depth: Client optimization + server quality control
     *
     * @private
     * @param {File} file Image file
     * @param {number} maxWidth Max width (1920 = PhotoOptimizationService::MAX_WIDTH)
     * @param {number} maxHeight Max height (1920 = PhotoOptimizationService::MAX_HEIGHT)
     * @param {number} quality JPEG quality 0-1 (0.92 = high quality before WebP conversion)
     * @returns {Promise<Blob>} Resized image blob
     */
    resizeImage(file, maxWidth, maxHeight, quality) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = (e) => {
                const img = new Image();

                img.onload = () => {
                    // Calculate new dimensions (maintain aspect ratio)
                    let width = img.width;
                    let height = img.height;

                    if (width > height) {
                        if (width > maxWidth) {
                            height = Math.round((height * maxWidth) / width);
                            width = maxWidth;
                        }
                    } else {
                        if (height > maxHeight) {
                            width = Math.round((width * maxHeight) / height);
                            height = maxHeight;
                        }
                    }

                    // Create canvas
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;

                    // Draw resized image
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);

                    // Convert to blob
                    canvas.toBlob(
                        (blob) => {
                            if (blob) {
                                resolve(blob);
                            } else {
                                reject(new Error('Failed to create blob'));
                            }
                        },
                        'image/jpeg',
                        quality
                    );
                };

                img.onerror = () => reject(new Error('Failed to load image'));
                img.src = e.target.result;
            };

            reader.onerror = () => reject(new Error('Failed to read file'));
            reader.readAsDataURL(file);
        });
    }

    /**
     * Get metadata from input fields
     * @private
     * @returns {object} Metadata object
     */
    getMetadata() {
        // Safety check: modal might be destroyed during async operations
        if (!this.modal) {
            return {
                title: null,
                description: null,
                photo: this.uploadedPhoto || null,
                visibility: 'public',
                mentioned_users: null
            };
        }

        const titleInput = this.modal.querySelector('#post-title');
        const descriptionInput = this.modal.querySelector('#post-description');
        const privacySelect = this.modal.querySelector('#post-privacy');

        const title = titleInput ? titleInput.value.trim() : '';
        const description = descriptionInput ? descriptionInput.value.trim() : '';
        const visibility = privacySelect ? privacySelect.value : 'public'; // Default to 'public'

        // ENTERPRISE V6.7 (2025-11-30): Extract mentioned users from BOTH title AND description
        const titleMentions = this.extractMentionsFromText(title);
        const descriptionMentions = this.extractMentionsFromText(description);

        // Merge and deduplicate by uuid
        const allMentions = [...titleMentions];
        for (const mention of descriptionMentions) {
            if (!allMentions.find(m => m.uuid === mention.uuid)) {
                allMentions.push(mention);
            }
        }

        return {
            title: title || null,
            description: description || null,
            photo: this.uploadedPhoto || null,
            visibility: visibility, // 'private', 'friends', 'friends_of_friends', 'public' (matches AudioController.php)
            mentioned_users: allMentions.length > 0 ? allMentions : null // ENTERPRISE V6.7: @mention from title+description
        };
    }

    // =========================================================================
    // @MENTION AUTOCOMPLETE - ENTERPRISE V4 (2025-11-30)
    // =========================================================================

    /**
     * Load friends list for @mention autocomplete
     * Called lazily on description textarea focus
     */
    async loadFriendsList() {
        if (this.friendsLoaded) return;

        try {
            const response = await fetch('/api/friends', {
                credentials: 'include'
            });

            if (response.ok) {
                const data = await response.json();
                this.friendsList = data.friends || data || [];
                this.friendsLoaded = true;
            }
        } catch (error) {
            console.error('[FloatingRecorder] Failed to load friends:', error);
        }
    }

    /**
     * Handle input in description textarea for @mention detection
     * @param {HTMLTextAreaElement} textarea - The textarea element
     */
    handleMentionInput(textarea) {
        const text = textarea.value;
        const cursorPos = textarea.selectionStart;

        // Find if we're in a @mention context
        const textBefore = text.substring(0, cursorPos);
        const atMatch = textBefore.match(/@([a-zA-Z0-9_]{0,20})$/);

        if (atMatch) {
            this.mentionStartPos = cursorPos - atMatch[0].length;
            this.mentionQuery = atMatch[1].toLowerCase();
            this.showMentionDropdown(textarea);
        } else {
            this.hideMentionDropdown();
        }
    }

    /**
     * Show @mention autocomplete dropdown
     * @param {HTMLTextAreaElement} textarea - The textarea
     */
    showMentionDropdown(textarea) {
        // Filter friends by query
        const filtered = this.friendsList.filter(friend =>
            friend.nickname.toLowerCase().includes(this.mentionQuery)
        ).slice(0, 5); // Max 5 suggestions

        if (filtered.length === 0) {
            this.hideMentionDropdown();
            return;
        }

        // Create or update dropdown
        this.hideMentionDropdown();

        this.mentionDropdown = document.createElement('div');
        this.mentionDropdown.className = 'mention-dropdown absolute bg-gray-800 border border-gray-600 rounded-lg shadow-xl max-h-48 overflow-y-auto';
        this.mentionDropdown.style.zIndex = '10001'; // Above modal (z-index: 9999)
        this.mentionDropdown.style.minWidth = '200px';

        filtered.forEach((friend, index) => {
            const option = document.createElement('div');
            option.className = `flex items-center space-x-2 px-3 py-2 cursor-pointer transition-colors ${
                index === this.selectedMentionIndex ? 'bg-purple-600' : 'hover:bg-gray-700'
            }`;
            option.innerHTML = `
                <img src="${friend.avatar_url || '/assets/img/default-avatar.png'}"
                     alt="${this.escapeHtml(friend.nickname)}"
                     class="w-6 h-6 rounded-full"
                     onerror="this.src='/assets/img/default-avatar.png'">
                <span class="text-white text-sm">${this.escapeHtml(friend.nickname)}</span>
            `;
            option.onclick = () => this.insertMention(textarea, friend);
            this.mentionDropdown.appendChild(option);
        });

        // Position dropdown below textarea
        const parent = textarea.closest('.relative');
        if (parent) {
            parent.appendChild(this.mentionDropdown);
            this.mentionDropdown.style.top = `${textarea.offsetHeight + 4}px`;
            this.mentionDropdown.style.left = '0';
            this.mentionDropdown.style.right = '0';
        }

        // Store filtered list for keyboard navigation
        this.mentionDropdown._filtered = filtered;
    }

    /**
     * Hide @mention dropdown
     */
    hideMentionDropdown() {
        if (this.mentionDropdown) {
            this.mentionDropdown.remove();
            this.mentionDropdown = null;
        }
        this.selectedMentionIndex = 0;
    }

    /**
     * Insert selected @mention into textarea
     * @param {HTMLTextAreaElement} textarea - The textarea
     * @param {Object} friend - Friend object with nickname, uuid, avatar_url
     */
    insertMention(textarea, friend) {
        const text = textarea.value;
        const before = text.substring(0, this.mentionStartPos);
        const after = text.substring(textarea.selectionStart);

        textarea.value = `${before}@${friend.nickname} ${after}`;

        // Position cursor after mention
        const newPos = this.mentionStartPos + friend.nickname.length + 2;
        textarea.setSelectionRange(newPos, newPos);
        textarea.focus();

        // Track this mention (for getMetadata)
        if (!this.selectedMentions.find(m => m.uuid === friend.uuid)) {
            this.selectedMentions.push({
                uuid: friend.uuid,
                nickname: friend.nickname,
                avatar_url: friend.avatar_url
            });
        }

        this.hideMentionDropdown();

        // Trigger input event to update character count
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * Handle keyboard navigation in @mention dropdown
     * @param {KeyboardEvent} event - Keyboard event
     * @param {HTMLTextAreaElement} textarea - The textarea
     * @returns {boolean} True if event was handled
     */
    handleMentionKeydown(event, textarea) {
        if (!this.mentionDropdown) return false;

        const filtered = this.mentionDropdown._filtered || [];
        if (filtered.length === 0) return false;

        switch (event.key) {
            case 'ArrowDown':
                this.selectedMentionIndex = Math.min(this.selectedMentionIndex + 1, filtered.length - 1);
                this.updateDropdownSelection();
                return true;

            case 'ArrowUp':
                this.selectedMentionIndex = Math.max(this.selectedMentionIndex - 1, 0);
                this.updateDropdownSelection();
                return true;

            case 'Enter':
            case 'Tab':
                if (filtered[this.selectedMentionIndex]) {
                    this.insertMention(textarea, filtered[this.selectedMentionIndex]);
                }
                return true;

            case 'Escape':
                this.hideMentionDropdown();
                return true;
        }

        return false;
    }

    /**
     * Update dropdown selection highlight
     */
    updateDropdownSelection() {
        if (!this.mentionDropdown) return;

        const options = this.mentionDropdown.querySelectorAll('div');
        options.forEach((opt, idx) => {
            if (idx === this.selectedMentionIndex) {
                opt.classList.add('bg-purple-600');
                opt.classList.remove('hover:bg-gray-700');
            } else {
                opt.classList.remove('bg-purple-600');
                opt.classList.add('hover:bg-gray-700');
            }
        });
    }

    /**
     * Escape HTML special characters to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Extract mentioned users from text
     * Matches @nicknames that are in the selectedMentions list
     * @param {string} text - Text to parse
     * @returns {Array} Array of mentioned user objects
     */
    extractMentionsFromText(text) {
        if (!text || !this.friendsList.length) return [];

        // Find all @mentions in text
        const mentions = text.match(/@([a-zA-Z0-9_]{3,20})/g);
        if (!mentions) return [];

        // Get unique nicknames
        const nicknames = [...new Set(mentions.map(m => m.substring(1).toLowerCase()))];

        // Match against friends list
        const matched = [];
        for (const nickname of nicknames) {
            const friend = this.friendsList.find(f =>
                f.nickname.toLowerCase() === nickname
            );
            if (friend) {
                matched.push({
                    uuid: friend.uuid,
                    nickname: friend.nickname,
                    avatar_url: friend.avatar_url
                });
            }
        }

        return matched;
    }

    /**
     * Reload Page Content (ENTERPRISE GALAXY: Instant Update)
     *
     * Twitter/Instagram pattern: Prepend new post to UI instantly
     * NO page reload, NO cache invalidation, ZERO performance impact
     *
     * @param {Object} uploadResult - Upload response from server
     * @param {Object} uploadResult.post - Full post data
     */
    reloadPageContent(uploadResult = null) {
        // ENTERPRISE: Check if post data available for instant prepend
        const postData = uploadResult?.post;

        // Feed page: Prepend post instantly (Twitter/Instagram pattern)
        // ENTERPRISE FIX (2025-11-30): Check if feedManager has active container (not on profile page)
        if (window.feedManager && window.feedManager.container) {
            if (postData && typeof window.feedManager.prependPost === 'function') {
                window.feedManager.prependPost(postData);
            } else {
                window.feedManager.resetFeed();
            }
            return;
        }

        // Profile page: Increment counter + prepend to list (instant)
        const profileContainer = document.querySelector('[data-page="profile"]');
        if (profileContainer && postData) {
            // Increment post count in stats
            const statsCountElement = document.querySelector('[data-stat="posts"]');
            if (statsCountElement) {
                const currentCount = parseInt(statsCountElement.textContent) || 0;
                statsCountElement.textContent = currentCount + 1;
            }
            return;
        }

        // Fallback: Full page reload (only if no better option)
        window.location.reload();
    }

    /**
     * Show Success Notification
     *
     * @param {string} message Success message
     */
    showSuccessNotification(message) {
        // TODO: Use toast notification system when implemented
        // For now, use window.GetLoud if available
        if (window.GetLoud && typeof window.GetLoud.showToast === 'function') {
            window.GetLoud.showToast(message, 'success');
        } else {
            alert(message); // Fallback
        }
    }

    /**
     * Show Error Notification
     *
     * @param {string} message Error message
     */
    showErrorNotification(message) {
        // TODO: Use toast notification system when implemented
        if (window.GetLoud && typeof window.GetLoud.showToast === 'function') {
            window.GetLoud.showToast(message, 'error');
        } else {
            console.error('❌ Error:', message);
            alert(message); // Fallback
        }
    }

    /**
     * Hide FAB (useful for specific pages)
     */
    hide() {
        if (this.fabButton) {
            this.fabButton.style.display = 'none';
        }
    }

    /**
     * Show FAB
     */
    show() {
        if (this.fabButton) {
            this.fabButton.style.display = 'flex';
        }
    }

    /**
     * Destroy component (cleanup)
     */
    destroy() {
        if (this.audioRecorder) {
            // Stop recording if active
            if (this.audioRecorder.isRecording) {
                this.audioRecorder.stopRecording();
            }
            this.audioRecorder = null;
        }

        if (this.fabButton) {
            this.fabButton.remove();
            this.fabButton = null;
        }

        if (this.modal) {
            this.modal.remove();
            this.modal = null;
        }
    }
}

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.floatingRecorder = new FloatingRecorder();
    });
} else {
    // DOM already loaded
    window.floatingRecorder = new FloatingRecorder();
}
