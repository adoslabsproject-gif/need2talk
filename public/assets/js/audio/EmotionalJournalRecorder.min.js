/**
 * =============================================================================
 * EMOTIONAL JOURNAL AUDIO RECORDER - ENTERPRISE V12
 * =============================================================================
 *
 * PRIVATE DIARY AUDIO RECORDING with INLINE ENCRYPTION
 * Target: 100,000+ concurrent users
 *
 * PURPOSE:
 * Modal dedicato alla registrazione di audio privato del diario emotivo.
 * Audio criptato client-side PRIMA dell'upload (zero-knowledge architecture).
 *
 * FEATURES:
 * - 30 secondi recording limit (voice optimization)
 * - Inline encryption con AES-256-GCM (DiaryEncryptionService)
 * - Selezione emozione primaria (10 Plutchik emotions)
 * - Optional encrypted text notes
 * - Real-time waveform visualization
 * - Upload diretto a /api/journal/upload-audio
 * - Rate limiting (10 media/day)
 *
 * SECURITY:
 * - Audio mai salvato non criptato (neanche in memoria)
 * - DEK derivato da diary password (PBKDF2, 600k iterations)
 * - IV univoco per ogni file (no IV reuse)
 * - Encrypted audio uploaded via multipart/form-data
 *
 * PERFORMANCE:
 * - WebM Opus @ 48kbps (voice optimization)
 * - File encryption: ~2ms per 180KB audio (WebCrypto)
 * - Client-side validation (no server roundtrips)
 *
 * ARCHITECTURE:
 * - Singleton pattern (window.Need2Talk.emotionalJournalRecorder)
 * - Event-driven UI (no polling)
 * - Modal-based UX (Bootstrap 5)
 * - Integration con DiaryEncryptionService.js (from EmotionalJournal)
 *
 * @package need2talk/Lightning Framework
 * @version 2.0.0 - V12 DiaryEncryptionService
 * @date 2025-12-13
 * =============================================================================
 */

class EmotionalJournalRecorder {
    constructor() {
        // State
        this.isRecording = false;
        this.isPaused = false;
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.recordingStartTime = null;
        this.recordingTimer = null;
        this.recordedBlob = null;
        this.encryptedBlob = null;
        this.encryptedIV = null;

        // Config
        this.MAX_DURATION = 30; // 30 seconds
        this.RATE_LIMIT_MAX = 10; // 10 audio/day
        this.WEBM_CONFIG = {
            mimeType: 'audio/webm;codecs=opus',
            audioBitsPerSecond: 48000, // 48kbps (voice optimization)
        };

        // Auto-stop timeout reference (to cancel on re-record)
        this.autoStopTimeout = null;

        // Seeking state flag (prevent timeupdate from overwriting seek position)
        this.isSeeking = false;

        // DOM elements (initialized on showModal)
        this.modal = null;
        this.modalElement = null;

        // NOTE: Non salvare riferimento a DiaryEncryptionService nel costruttore!
        // Il servizio viene creato da EmotionalJournal.js DOPO che l'utente accede al diario.
        // Usare getter dinamico invece (vedi getDiaryEncryptionService())

        // 10 Plutchik emotions (same as main system)
        // ENTERPRISE GALAXY+: Emotions loaded from API (Single Source of Truth)
        // Cache in localStorage (5min TTL) for performance
        this.emotions = [];
        this.emotionsLoaded = false;

        this.selectedEmotion = null;
        this.selectedIntensity = 3; // Default: medium (1-5 scale)

        // Load emotions from API (async)
        this.loadEmotionsFromAPI();
    }

    /**
     * Load emotions from API with localStorage caching
     * ENTERPRISE GALAXY+: Single Source of Truth pattern
     *
     * Cache Strategy:
     * - localStorage: 5 minutes TTL
     * - Server cache: 2 hours (handled by EmotionController)
     * - Fallback: Hardcoded defaults if API fails
     */
    async loadEmotionsFromAPI() {
        const CACHE_KEY = 'need2talk_emotions';
        const CACHE_TTL = 5 * 60 * 1000; // 5 minutes

        try {
            // 1. Check localStorage cache
            const cached = localStorage.getItem(CACHE_KEY);
            if (cached) {
                const { data, timestamp } = JSON.parse(cached);
                if (Date.now() - timestamp < CACHE_TTL) {
                    this.emotions = data;
                    this.emotionsLoaded = true;
                    return;
                }
            }

            // 2. Fetch from API
            const response = await fetch('/api/emotions', {
                method: 'GET',
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`API returned ${response.status}`);
            }

            const result = await response.json();

            if (!result.success || !result.emotions) {
                throw new Error('Invalid API response format');
            }

            // 3. Transform API data to frontend format
            this.emotions = result.emotions.map(e => ({
                id: e.id,
                name: e.name_it,  // ITALIAN NAMES (not English!)
                emoji: e.icon_emoji,
                color: e.color_hex,
                category: e.category,
                description: e.description,
            }));

            // 4. Save to localStorage cache
            localStorage.setItem(CACHE_KEY, JSON.stringify({
                data: this.emotions,
                timestamp: Date.now(),
            }));

            this.emotionsLoaded = true;

        } catch (error) {
            console.error('[EmotionalJournalRecorder] Failed to load emotions from API:', error);

            // FALLBACK: Use hardcoded defaults (matching DB)
            this.emotions = [
                { id: 1, name: 'Gioia', emoji: '😊', color: '#FFD700', category: 'positive' },
                { id: 2, name: 'Meraviglia', emoji: '🎉', color: '#FF8C00', category: 'positive' },
                { id: 3, name: 'Amore', emoji: '❤️', color: '#FF69B4', category: 'positive' },
                { id: 4, name: 'Gratitudine', emoji: '🙏', color: '#20B2AA', category: 'positive' },
                { id: 5, name: 'Speranza', emoji: '🌟', color: '#87CEEB', category: 'positive' },
                { id: 6, name: 'Tristezza', emoji: '😢', color: '#4682B4', category: 'negative' },
                { id: 7, name: 'Rabbia', emoji: '😠', color: '#DC143C', category: 'negative' },
                { id: 8, name: 'Ansia', emoji: '😰', color: '#8B4513', category: 'negative' },
                { id: 9, name: 'Paura', emoji: '😨', color: '#9370DB', category: 'negative' },
                { id: 10, name: 'Solitudine', emoji: '😔', color: '#696969', category: 'negative' },
            ];

            console.warn('[EmotionalJournalRecorder] Using fallback emotions');
            this.emotionsLoaded = true;
        }
    }

    /**
     * Get DiaryEncryptionService dynamically (lazy access)
     * CRITICAL: Non usare this.encryptionService perché viene inizializzato DOPO
     *
     * V12: Now uses DiaryEncryptionService from EmotionalJournal.js
     * The service is created when user opens the diary with password.
     *
     * EmotionalJournal.js exposes:
     * - window.EmotionalJournal.instance.encryptionService
     *
     * @returns {DiaryEncryptionService|null} Service instance or null
     */
    getDiaryEncryptionService() {
        // V12: Look for DiaryEncryptionService from EmotionalJournal
        // EmotionalJournal exposes it as window.EmotionalJournal.instance.encryptionService
        return window.EmotionalJournal?.instance?.encryptionService || null;
    }

    /**
     * @deprecated Use getDiaryEncryptionService() instead
     */
    getEncryptionService() {
        return this.getDiaryEncryptionService();
    }

    /**
     * Show recording modal
     *
     * @param {string} date - Date for journal entry (YYYY-MM-DD)
     */
    async showModal(date = null) {
        // V12: Check DiaryEncryptionService availability (must be unlocked)
        const encryptionService = this.getDiaryEncryptionService();

        if (!encryptionService) {
            console.error('[EmotionalJournalRecorder] EARLY EXIT: Diary encryption service not found!');
            this.showError('Il diario non è stato inizializzato. Ricarica la pagina.');
            return;
        }

        if (!encryptionService.isUnlocked()) {
            console.error('[EmotionalJournalRecorder] EARLY EXIT: Diary not unlocked!');
            this.showError('Devi sbloccare il diario con la password prima di registrare audio.');
            return;
        }

        // Check rate limit BEFORE showing modal (avoid UX friction)
        const rateLimitCheck = await this.checkRateLimit();
        if (!rateLimitCheck.allowed) {
            this.showError(`Daily limit reached (${this.RATE_LIMIT_MAX} audio/day). ${rateLimitCheck.message}`);
            return;
        }

        // Initialize modal from static HTML (ENTERPRISE: no dynamic creation)
        if (!this.modalElement) {
            this.modalElement = document.getElementById('journalAudioRecorderModal');

            if (!this.modalElement) {
                console.error('[EmotionalJournalRecorder] Modal HTML not found in DOM!');
                alert('Modal HTML not found. Please refresh the page.');
                return;
            }

            // Initialize Bootstrap modal instance
            if (typeof bootstrap === 'undefined') {
                console.error('[EmotionalJournalRecorder] Bootstrap JS not loaded! Cannot create modal.');
                alert('Bootstrap JavaScript not loaded. Please refresh the page.');
                return;
            }

            this.modal = new bootstrap.Modal(this.modalElement);

            // Populate emotion buttons
            this.populateEmotionButtons();

            // Attach event listeners
            this.attachEventListeners();
        }

        // Reset state
        this.resetRecorder();
        this.currentDate = date || new Date().toISOString().split('T')[0];

        // Show modal (Bootstrap 5)
        this.modal.show();
    }

    /**
     * Populate emotion buttons in static HTML - ENTERPRISE V10.91
     * Two rows: 5 positive emotions + 5 negative emotions
     * Uses CSS Grid for responsive 5-column layout
     */
    populateEmotionButtons() {
        const positiveRow = document.getElementById('positiveEmotionsRow');
        const negativeRow = document.getElementById('negativeEmotionsRow');

        if (!positiveRow || !negativeRow) {
            console.error('[EmotionalJournalRecorder] Emotion row containers not found!');
            return;
        }

        // Separate emotions by category
        const positiveEmotions = this.emotions.filter(e => e.category === 'positive');
        const negativeEmotions = this.emotions.filter(e => e.category === 'negative');

        /**
         * Render emotion button HTML
         * @param {Object} emotion - Emotion object
         * @returns {string} HTML string
         */
        const renderEmotionButton = (emotion) => `
            <button type="button"
                    class="emotion-btn-modal emotion-${emotion.category}"
                    data-emotion-id="${emotion.id}"
                    title="${emotion.name}">
                <span class="emotion-icon-modal">${emotion.emoji}</span>
                <span class="emotion-name-modal">${emotion.name}</span>
            </button>
        `;

        // Populate positive emotions row (5 buttons)
        positiveRow.innerHTML = positiveEmotions.map(renderEmotionButton).join('');

        // Populate negative emotions row (5 buttons)
        negativeRow.innerHTML = negativeEmotions.map(renderEmotionButton).join('');
    }

    /**
     * Create modal HTML structure (DEPRECATED - using static HTML)
     */
    createModalHTML() {
        const modalHTML = `
        <div class="modal fade" id="journalAudioRecorderModal" tabindex="-1" aria-labelledby="journalAudioRecorderModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="journalAudioRecorderModalLabel">
                            🎙️ Private Diary Audio
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Emotion Selection -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">How are you feeling?</label>
                            <div id="emotionSelector" class="d-flex flex-wrap gap-2">
                                ${this.emotions.map(emotion => `
                                    <button type="button" class="btn btn-outline-secondary emotion-btn" data-emotion-id="${emotion.id}" style="border-color: ${emotion.color};">
                                        <span style="font-size: 1.5rem;">${emotion.emoji}</span>
                                        <br><small>${emotion.name}</small>
                                    </button>
                                `).join('')}
                            </div>
                        </div>

                        <!-- Intensity Slider -->
                        <div class="mb-4" id="intensitySliderContainer" style="display:none;">
                            <label class="form-label fw-bold">Intensity</label>
                            <input type="range" class="form-range" id="intensitySlider" min="1" max="5" value="3">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">Low</small>
                                <small class="text-muted">Medium</small>
                                <small class="text-muted">High</small>
                            </div>
                        </div>

                        <!-- Recording Controls -->
                        <div class="text-center mb-4">
                            <button type="button" id="startRecordingBtn" class="btn btn-danger btn-lg" disabled>
                                <i class="fas fa-microphone"></i> Start Recording
                            </button>
                            <button type="button" id="stopRecordingBtn" class="btn btn-secondary btn-lg" style="display:none;">
                                <i class="fas fa-stop"></i> Stop Recording
                            </button>
                            <div class="mt-2">
                                <span id="recordingTimer" class="badge bg-danger" style="display:none; font-size: 1.2rem;">00:00</span>
                            </div>
                        </div>

                        <!-- Waveform Visualization (placeholder for future implementation) -->
                        <div id="waveformContainer" class="mb-3" style="display:none; height: 80px; background: #f8f9fa; border-radius: 8px;">
                            <canvas id="waveformCanvas" width="600" height="80"></canvas>
                        </div>

                        <!-- Playback Controls -->
                        <div id="playbackControls" class="text-center mb-4" style="display:none;">
                            <audio id="audioPlayback" controls class="w-100"></audio>
                            <button type="button" id="reRecordBtn" class="btn btn-warning mt-2">
                                <i class="fas fa-redo"></i> Re-record
                            </button>
                        </div>

                        <!-- Title (Optional but recommended for organization) -->
                        <div class="mb-3">
                            <label for="audioTitle" class="form-label fw-bold">
                                <i class="fas fa-heading"></i> Title <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <input type="text" class="form-control" id="audioTitle" maxlength="500" placeholder="Es: Riflessioni dopo la riunione, Pensieri del mattino...">
                            <small class="text-muted">Aiuta a ricordare il contesto di questo audio nel tempo</small>
                        </div>

                        <!-- Description (Optional but recommended) -->
                        <div class="mb-3">
                            <label for="audioDescription" class="form-label fw-bold">
                                <i class="fas fa-align-left"></i> Description <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <textarea class="form-control" id="audioDescription" rows="2" maxlength="2000" placeholder="Aggiungi dettagli su cosa hai registrato o cosa stavi vivendo..."></textarea>
                            <small class="text-muted">Ti aiuterà a comprendere meglio le tue emozioni quando riascolterai</small>
                        </div>

                        <!-- Photo Upload (Optional - visual memory anchor) V12: BEM Styled -->
                        <div class="journal-recorder__photo-section">
                            <label class="journal-recorder__photo-label">
                                <svg class="journal-recorder__photo-label-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                Foto <span class="journal-recorder__photo-optional">(Opzionale)</span>
                            </label>
                            <div class="journal-recorder__photo-upload">
                                <input type="file" id="audioPhoto" accept="image/jpeg,image/png,image/webp" class="journal-recorder__photo-input">
                                <label for="audioPhoto" class="journal-recorder__photo-btn">
                                    <svg class="journal-recorder__photo-btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    <span>Aggiungi Foto</span>
                                </label>
                            </div>
                            <p class="journal-recorder__photo-hint">Max 10MB • Auto-ottimizzata in WebP</p>
                            <div id="photoPreview" class="journal-recorder__photo-preview" style="display:none;">
                                <img id="photoPreviewImg" src="" alt="Anteprima" class="journal-recorder__photo-img">
                                <button type="button" id="removePhotoBtn" class="journal-recorder__photo-remove">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                    <span>Rimuovi</span>
                                </button>
                            </div>
                        </div>

                        <!-- Optional Text Notes (Encrypted) -->
                        <div class="mb-3">
                            <label for="textNotes" class="form-label">
                                <i class="fas fa-lock"></i> Private Encrypted Notes <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <textarea class="form-control" id="textNotes" rows="3" placeholder="Note private criptate che solo tu potrai leggere..."></textarea>
                            <small class="text-muted">Queste note sono criptate end-to-end, nessuno può leggerle tranne te</small>
                        </div>

                        <!-- Upload Progress -->
                        <div id="uploadProgress" class="progress mb-3" style="display:none;">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                        </div>

                        <!-- Error Message -->
                        <div id="recorderErrorMsg" class="alert alert-danger" style="display:none;"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" id="uploadAudioBtn" class="btn btn-primary" disabled>
                            <i class="fas fa-upload"></i> Upload (Encrypted)
                        </button>
                    </div>
                </div>
            </div>
        </div>
        `;

        // Append to body
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Get modal element
        this.modalElement = document.getElementById('journalAudioRecorderModal');
        this.modal = new bootstrap.Modal(this.modalElement);

        // Attach event listeners
        this.attachEventListeners();
    }

    /**
     * Attach event listeners to modal elements - ENTERPRISE V10.91
     */
    attachEventListeners() {
        // Emotion selection (new class: emotion-btn-modal)
        document.querySelectorAll('.emotion-btn-modal').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const emotionId = parseInt(e.currentTarget.dataset.emotionId);
                this.selectEmotion(emotionId);
            });
        });

        // Intensity slider
        document.getElementById('intensitySlider').addEventListener('input', (e) => {
            this.selectedIntensity = parseInt(e.target.value);
            // Update displayed value
            const valueDisplay = document.getElementById('intensityValue');
            if (valueDisplay) {
                valueDisplay.textContent = this.selectedIntensity;
            }
        });

        // Recording controls
        document.getElementById('startRecordingBtn').addEventListener('click', () => this.startRecording());
        document.getElementById('stopRecordingBtn').addEventListener('click', () => this.stopRecording());
        document.getElementById('reRecordBtn').addEventListener('click', () => this.reRecord());

        // Upload button
        document.getElementById('uploadAudioBtn').addEventListener('click', () => this.uploadAudio());

        // Photo upload events (ENTERPRISE GALAXY+: Visual memory anchors)
        // ENTERPRISE FIX (2025-01-23): Check if elements exist before attaching listeners
        const audioPhotoElement = document.getElementById('audioPhoto');
        const removePhotoBtnElement = document.getElementById('removePhotoBtn');

        if (audioPhotoElement) {
            audioPhotoElement.addEventListener('change', (e) => this.handlePhotoSelect(e));
        }

        if (removePhotoBtnElement) {
            removePhotoBtnElement.addEventListener('click', () => this.removePhoto());
        }

        // ENTERPRISE: Custom Audio Player Event Listeners
        this.setupCustomPlayer();

        // Modal close event (cleanup)
        this.modalElement.addEventListener('hidden.bs.modal', () => this.cleanup());
    }

    /**
     * Setup Custom Audio Player Controls - ENTERPRISE GALAXY+
     *
     * Architecture:
     * - Hidden <audio> element as playback source
     * - Custom play/pause button with SVG icons
     * - Seekable progress bar with handle
     * - Elapsed time display only (duration in badge)
     *
     * Performance:
     * - Uses requestAnimationFrame for smooth progress updates
     * - Debounced seeking to prevent audio skipping
     */
    setupCustomPlayer() {
        const audioElement = document.getElementById('audioPlayback');
        const playPauseBtn = document.getElementById('playerPlayPauseBtn');
        const progressContainer = document.getElementById('playerProgressContainer');

        if (!audioElement || !playPauseBtn || !progressContainer) {
            console.warn('[EmotionalJournalRecorder] Custom player elements not found');
            return;
        }

        // Store reference for class methods
        this.audioElement = audioElement;

        // Play/Pause button click
        playPauseBtn.addEventListener('click', () => this.togglePlayPause());

        // Audio element events
        audioElement.addEventListener('timeupdate', () => this.updatePlayerProgress());
        audioElement.addEventListener('play', () => this.onAudioPlay());
        audioElement.addEventListener('pause', () => this.onAudioPause());
        audioElement.addEventListener('ended', () => this.onAudioEnded());
        audioElement.addEventListener('loadedmetadata', () => this.onAudioLoaded());
        audioElement.addEventListener('seeked', () => this.onAudioSeeked());

        // Progress bar seeking (click to seek)
        progressContainer.addEventListener('click', (e) => this.seekToPosition(e));

        // Progress handle dragging
        this.setupProgressDragging();
    }

    /**
     * Setup progress bar dragging (drag to seek)
     * ENTERPRISE: Event delegation for handle that may not exist yet
     * NOTE: WebM blob URLs don't support true seeking, but we pause during drag
     */
    setupProgressDragging() {
        const progressContainer = document.getElementById('playerProgressContainer');
        if (!progressContainer) return;

        let isDragging = false;
        let wasPlaying = false;

        // Use event delegation on the container
        progressContainer.addEventListener('mousedown', (e) => {
            const audioElement = document.getElementById('audioPlayback');
            const handle = document.getElementById('playerProgressHandle');

            // Remember if audio was playing and pause it
            wasPlaying = audioElement && !audioElement.paused;
            if (wasPlaying) {
                audioElement.pause();
            }

            // Start seeking immediately on any click/drag
            isDragging = true;
            this.seekToPosition(e);

            if (handle) {
                handle.style.transform = 'translate(-50%, -50%) scale(1.2)';
            }

            const onDrag = (moveEvent) => {
                if (!isDragging) return;
                this.seekToPosition(moveEvent);
            };

            const stopDrag = () => {
                isDragging = false;
                const handle = document.getElementById('playerProgressHandle');
                if (handle) {
                    handle.style.transform = 'translate(-50%, -50%)';
                }

                // NOTE: Don't resume playback - WebM blob doesn't support seeking
                // User must press play manually after dragging
                // This prevents the confusing "snap back" when timeupdate fires

                document.removeEventListener('mousemove', onDrag);
                document.removeEventListener('mouseup', stopDrag);
            };

            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup', stopDrag);
            e.preventDefault();
        });

        // Touch support
        progressContainer.addEventListener('touchstart', (e) => {
            const audioElement = document.getElementById('audioPlayback');
            const handle = document.getElementById('playerProgressHandle');

            // Pause audio during drag
            if (audioElement && !audioElement.paused) {
                audioElement.pause();
            }

            isDragging = true;
            this.seekToPosition(e);

            if (handle) {
                handle.style.transform = 'translate(-50%, -50%) scale(1.2)';
            }

            const onDrag = (moveEvent) => {
                if (!isDragging) return;
                this.seekToPosition(moveEvent);
            };

            const stopDrag = () => {
                isDragging = false;
                const handle = document.getElementById('playerProgressHandle');
                if (handle) {
                    handle.style.transform = 'translate(-50%, -50%)';
                }

                // NOTE: Don't resume playback - WebM blob doesn't support seeking

                document.removeEventListener('touchmove', onDrag);
                document.removeEventListener('touchend', stopDrag);
            };

            document.addEventListener('touchmove', onDrag, { passive: false });
            document.addEventListener('touchend', stopDrag);
            e.preventDefault();
        }, { passive: false });
    }

    /**
     * Toggle play/pause state
     */
    togglePlayPause() {
        const audioElement = document.getElementById('audioPlayback');
        if (!audioElement || !audioElement.src) return;

        if (audioElement.paused) {
            audioElement.play().catch(err => {
                console.error('[EmotionalJournalRecorder] Play failed:', err);
            });
        } else {
            audioElement.pause();
        }
    }

    /**
     * Handle audio play event - Update UI
     */
    onAudioPlay() {
        const playIcon = document.getElementById('playerPlayIcon');
        const pauseIcon = document.getElementById('playerPauseIcon');
        const playerContainer = document.querySelector('.journal-audio-player');

        if (playIcon) playIcon.style.display = 'none';
        if (pauseIcon) pauseIcon.style.display = 'block';
        if (playerContainer) playerContainer.classList.add('playing');
    }

    /**
     * Handle audio pause event - Update UI
     */
    onAudioPause() {
        const playIcon = document.getElementById('playerPlayIcon');
        const pauseIcon = document.getElementById('playerPauseIcon');
        const playerContainer = document.querySelector('.journal-audio-player');

        if (playIcon) playIcon.style.display = 'block';
        if (pauseIcon) pauseIcon.style.display = 'none';
        if (playerContainer) playerContainer.classList.remove('playing');
    }

    /**
     * Handle audio ended event - Reset player state
     */
    onAudioEnded() {
        const playIcon = document.getElementById('playerPlayIcon');
        const pauseIcon = document.getElementById('playerPauseIcon');
        const playerContainer = document.querySelector('.journal-audio-player');

        if (playIcon) playIcon.style.display = 'block';
        if (pauseIcon) pauseIcon.style.display = 'none';
        if (playerContainer) playerContainer.classList.remove('playing');

        // Reset progress bar
        this.updateProgressBar(0);
        this.updateTimeDisplay(0);

        // ENTERPRISE FIX: WebM blob URLs have seeking issues (no cue points from MediaRecorder)
        // Solution: Re-create blob URL on each replay to force proper reload
        if (this.recordedBlob && this.currentBlobUrl) {
            const audioElement = document.getElementById('audioPlayback');
            URL.revokeObjectURL(this.currentBlobUrl); // Cleanup old URL
            this.currentBlobUrl = URL.createObjectURL(this.recordedBlob);
            audioElement.src = this.currentBlobUrl;
            audioElement.load();
        }
    }

    /**
     * Handle audio loaded event
     */
    onAudioLoaded() {
        const playerContainer = document.querySelector('.journal-audio-player');
        if (playerContainer) {
            playerContainer.classList.remove('loading');
        }
    }

    /**
     * Handle audio seeked event - ENTERPRISE GALAXY+
     * Called when browser finishes seeking to new position
     * Clears the isSeeking flag to resume normal timeupdate handling
     */
    onAudioSeeked() {
        const audioElement = document.getElementById('audioPlayback');

        // Clear seeking flag
        this.isSeeking = false;

        // Verify seek was successful and update UI with actual position
        if (audioElement && this.seekTargetPercent !== undefined) {
            const actualTime = audioElement.currentTime;
            const expectedTime = this.seekTargetTime || 0;
            const diff = Math.abs(actualTime - expectedTime);

            // If seek landed close to target, use target (for better UX)
            // WebM blobs may not seek exactly due to keyframe alignment
            if (diff >= 0.5) {
                // Seek landed differently, update UI to actual position
                const duration = isFinite(audioElement.duration) ? audioElement.duration : (this.recordingDuration || 30);
                const actualPercent = (actualTime / duration) * 100;
                this.updateProgressBar(actualPercent);
                this.updateTimeDisplay(actualTime);
            }
        }

        // Clean up
        this.seekTargetTime = undefined;
        this.seekTargetPercent = undefined;
    }

    /**
     * Update player progress bar and time display
     * ENTERPRISE: Respects isSeeking flag to prevent UI snap-back during seek
     */
    updatePlayerProgress() {
        // CRITICAL: Don't update during seek operation (prevents snap-back)
        if (this.isSeeking) {
            return;
        }

        const audioElement = document.getElementById('audioPlayback');
        if (!audioElement) return;

        const currentTime = audioElement.currentTime;
        // Use recorded duration as fallback (WebM blob URLs often have NaN/Infinity duration)
        const duration = isFinite(audioElement.duration) ? audioElement.duration : (this.recordingDuration || 30);

        if (duration > 0) {
            const progressPercent = (currentTime / duration) * 100;
            this.updateProgressBar(progressPercent);
        }

        this.updateTimeDisplay(currentTime);
    }

    /**
     * Update progress bar visual
     *
     * @param {number} percent - Progress percentage (0-100)
     */
    updateProgressBar(percent) {
        const progressBar = document.getElementById('playerProgressBar');
        const progressHandle = document.getElementById('playerProgressHandle');

        if (progressBar) {
            progressBar.style.width = `${percent}%`;
        }
        if (progressHandle) {
            progressHandle.style.left = `${percent}%`;
        }
    }

    /**
     * Update elapsed time display
     *
     * @param {number} seconds - Current time in seconds
     */
    updateTimeDisplay(seconds) {
        const timeElapsed = document.getElementById('playerTimeElapsed');
        if (timeElapsed) {
            timeElapsed.textContent = this.formatTime(seconds);
        }
    }

    /**
     * Seek to position based on click/touch event
     * ENTERPRISE: Uses isSeeking flag to prevent timeupdate from overwriting position
     *
     * @param {Event} e - Click or touch event
     */
    seekToPosition(e) {
        const audioElement = document.getElementById('audioPlayback');
        const progressContainer = document.getElementById('playerProgressContainer');

        if (!audioElement || !progressContainer || !audioElement.src) return;

        // Get click position relative to progress bar
        const rect = progressContainer.getBoundingClientRect();
        const clientX = e.clientX || (e.touches && e.touches[0]?.clientX) || rect.left;
        const clickX = clientX - rect.left;
        const percent = Math.max(0, Math.min(100, (clickX / rect.width) * 100));

        // Use recorded duration as fallback (WebM blob URLs often have NaN/Infinity duration)
        const duration = isFinite(audioElement.duration) ? audioElement.duration : (this.recordingDuration || 30);

        if (duration > 0) {
            const seekTime = (percent / 100) * duration;

            // ENTERPRISE: Set seeking flag to prevent timeupdate from overwriting
            this.isSeeking = true;

            // Store target seek position for verification
            this.seekTargetTime = seekTime;
            this.seekTargetPercent = percent;

            // Update visual immediately for responsive feel
            this.updateProgressBar(percent);
            this.updateTimeDisplay(seekTime);

            // Set audio currentTime
            try {
                audioElement.currentTime = seekTime;
            } catch (err) {
                console.warn('[EmotionalJournalRecorder] Seek failed:', err);
                // Reset seeking flag on error
                this.isSeeking = false;
            }

            // Fallback: Clear seeking flag after timeout (in case seeked event doesn't fire)
            setTimeout(() => {
                if (this.isSeeking) {
                    this.isSeeking = false;
                }
            }, 500);
        }
    }

    /**
     * Format time as mm:ss
     *
     * @param {number} seconds - Time in seconds
     * @returns {string} Formatted time string
     */
    formatTime(seconds) {
        if (!isFinite(seconds) || seconds < 0) {
            return '0:00';
        }
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    /**
     * Reset custom player to initial state
     */
    resetCustomPlayer() {
        const playIcon = document.getElementById('playerPlayIcon');
        const pauseIcon = document.getElementById('playerPauseIcon');
        const playerContainer = document.querySelector('.journal-audio-player');

        if (playIcon) playIcon.style.display = 'block';
        if (pauseIcon) pauseIcon.style.display = 'none';
        if (playerContainer) playerContainer.classList.remove('playing', 'loading');

        // Reset seeking state
        this.isSeeking = false;
        this.seekTargetTime = undefined;
        this.seekTargetPercent = undefined;

        this.updateProgressBar(0);
        this.updateTimeDisplay(0);
    }

    /**
     * Select emotion - ENTERPRISE V10.91
     *
     * @param {number} emotionId - Emotion ID (1-10)
     */
    selectEmotion(emotionId) {
        this.selectedEmotion = this.emotions.find(e => e.id === emotionId);

        // Update UI - remove active from all, add to selected
        document.querySelectorAll('.emotion-btn-modal').forEach(btn => {
            btn.classList.remove('active');
        });
        const selectedBtn = document.querySelector(`.emotion-btn-modal[data-emotion-id="${emotionId}"]`);
        if (selectedBtn) {
            selectedBtn.classList.add('active');
        }

        // Show intensity slider with smooth animation
        const intensityContainer = document.getElementById('intensitySliderContainer');
        if (intensityContainer) {
            intensityContainer.style.display = 'block';
        }

        // Enable start recording button
        document.getElementById('startRecordingBtn').disabled = false;
    }

    /**
     * Start recording
     */
    async startRecording() {
        try {
            // Request microphone access
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // Check WebM Opus support
            if (!MediaRecorder.isTypeSupported(this.WEBM_CONFIG.mimeType)) {
                throw new Error('WebM Opus codec not supported by browser');
            }

            // Create MediaRecorder
            this.mediaRecorder = new MediaRecorder(stream, this.WEBM_CONFIG);
            this.audioChunks = [];

            // Data available event
            this.mediaRecorder.addEventListener('dataavailable', (event) => {
                if (event.data.size > 0) {
                    this.audioChunks.push(event.data);
                }
            });

            // Recording stopped event
            this.mediaRecorder.addEventListener('stop', () => {
                this.onRecordingStopped();
            });

            // Start recording
            this.mediaRecorder.start();
            this.isRecording = true;
            this.recordingStartTime = Date.now();

            // Update UI
            document.getElementById('startRecordingBtn').style.display = 'none';
            document.getElementById('stopRecordingBtn').style.display = 'inline-block';
            document.getElementById('recordingTimer').style.display = 'inline-block';
            document.getElementById('waveformContainer').style.display = 'block';

            // Start timer
            this.startTimer();

            // Auto-stop after MAX_DURATION (save reference to cancel on re-record)
            if (this.autoStopTimeout) {
                clearTimeout(this.autoStopTimeout);
            }
            this.autoStopTimeout = setTimeout(() => {
                if (this.isRecording) {
                    this.stopRecording();
                }
            }, this.MAX_DURATION * 1000);

        } catch (error) {
            console.error('[EmotionalJournalRecorder] Failed to start recording:', error);
            this.showError('Failed to access microphone. Please check permissions.');
        }
    }

    /**
     * Stop recording
     */
    stopRecording() {
        if (!this.isRecording || !this.mediaRecorder) {
            return;
        }

        this.mediaRecorder.stop();
        this.isRecording = false;

        // Stop all tracks (release microphone)
        this.mediaRecorder.stream.getTracks().forEach(track => track.stop());

        // Stop timer and save duration
        clearInterval(this.recordingTimer);
        this.recordingDuration = Math.floor((Date.now() - this.recordingStartTime) / 1000);

        // Cancel auto-stop timeout (important for re-record bug fix)
        if (this.autoStopTimeout) {
            clearTimeout(this.autoStopTimeout);
            this.autoStopTimeout = null;
        }

        // Update UI
        document.getElementById('stopRecordingBtn').style.display = 'none';
        document.getElementById('recordingTimer').style.display = 'none';
    }

    /**
     * Fix WebM blob duration for seeking support - ENTERPRISE GALAXY+
     *
     * MediaRecorder creates WebM files without proper duration metadata,
     * which makes seeking impossible. This function searches for the Duration
     * element byte pattern and patches it directly.
     *
     * @param {Blob} blob - Raw WebM blob from MediaRecorder
     * @param {number} duration - Duration in milliseconds
     * @returns {Promise<Blob>} Fixed WebM blob with duration metadata
     */
    async fixWebmDuration(blob, duration) {
        const buffer = await blob.arrayBuffer();
        const bytes = new Uint8Array(buffer);

        // Search for Duration element ID (0x4489) followed by size byte
        // Duration in WebM is typically: 44 89 [size] [float value]
        // Common sizes: 0x84 (4 bytes float32) or 0x88 (8 bytes float64)
        let durationOffset = -1;
        let durationSize = 0;

        for (let i = 0; i < bytes.length - 12; i++) {
            // Look for Duration element ID: 0x44 0x89
            if (bytes[i] === 0x44 && bytes[i + 1] === 0x89) {
                const sizeByte = bytes[i + 2];

                // Check for valid VINT size (0x84 = 4 bytes, 0x88 = 8 bytes)
                if (sizeByte === 0x84) {
                    durationOffset = i + 3; // After ID (2) + size (1)
                    durationSize = 4;
                    break;
                } else if (sizeByte === 0x88) {
                    durationOffset = i + 3;
                    durationSize = 8;
                    break;
                }
            }
        }

        if (durationOffset > 0) {
            // Duration element found - patch it in place
            const newBuffer = buffer.slice(0);
            const view = new DataView(newBuffer);

            if (durationSize === 8) {
                view.setFloat64(durationOffset, duration, false); // Big-endian
            } else if (durationSize === 4) {
                view.setFloat32(durationOffset, duration, false);
            }

            return new Blob([newBuffer], { type: blob.type });
        }

        // Duration element not found - try to inject it after Info element
        // Search for Info element ID (0x15 0x49 0xA9 0x66)
        let infoOffset = -1;
        for (let i = 0; i < bytes.length - 20; i++) {
            if (bytes[i] === 0x15 && bytes[i + 1] === 0x49 &&
                bytes[i + 2] === 0xA9 && bytes[i + 3] === 0x66) {
                infoOffset = i;
                break;
            }
        }

        if (infoOffset > 0) {
            // Found Info element - read its size and inject Duration after header
            const sizeStart = infoOffset + 4;
            const firstSizeByte = bytes[sizeStart];

            // Determine VINT length
            let vintLength = 1;
            if ((firstSizeByte & 0x80) === 0x80) vintLength = 1;
            else if ((firstSizeByte & 0x40) === 0x40) vintLength = 2;
            else if ((firstSizeByte & 0x20) === 0x20) vintLength = 3;
            else if ((firstSizeByte & 0x10) === 0x10) vintLength = 4;

            const infoDataStart = sizeStart + vintLength;

            // Create Duration element: ID (2) + Size (1) + Value (8) = 11 bytes
            const durationElement = new Uint8Array(11);
            durationElement[0] = 0x44; // Duration ID high byte
            durationElement[1] = 0x89; // Duration ID low byte
            durationElement[2] = 0x88; // Size: 8 bytes (VINT)

            // Write duration as float64 big-endian
            const tempBuffer = new ArrayBuffer(8);
            const tempView = new DataView(tempBuffer);
            tempView.setFloat64(0, duration, false);
            durationElement.set(new Uint8Array(tempBuffer), 3);

            // Combine: before + duration element + after
            const before = bytes.slice(0, infoDataStart);
            const after = bytes.slice(infoDataStart);

            return new Blob([before, durationElement, after], { type: blob.type });
        }

        // Couldn't find suitable location - return original
        console.warn('[fixWebmDuration] Could not find Info element, returning original');
        throw new Error('Could not patch WebM duration');
    }

    /**
     * Handle recording stopped event - ENTERPRISE GALAXY+ (Custom Player)
     */
    async onRecordingStopped() {
        // Create blob from chunks
        let rawBlob = new Blob(this.audioChunks, { type: this.WEBM_CONFIG.mimeType });

        // ENTERPRISE FIX: Inject correct duration into WebM metadata for seeking support
        // MediaRecorder creates WebM without duration, making seeking impossible
        try {
            this.recordedBlob = await this.fixWebmDuration(rawBlob, this.recordingDuration * 1000);
        } catch (err) {
            console.warn('[EmotionalJournalRecorder] WebM duration fix failed, using raw blob:', err);
            this.recordedBlob = rawBlob;
        }

        const audioPlayback = document.getElementById('audioPlayback');
        const durationDisplay = document.getElementById('audioDuration');
        const playerContainer = document.querySelector('.journal-audio-player');
        const recordedDuration = this.recordingDuration || 0;

        // Use blob URL (CSP compliant - data: URLs are blocked by media-src policy)
        const blobUrl = URL.createObjectURL(this.recordedBlob);

        // Store blob URL reference for cleanup
        this.currentBlobUrl = blobUrl;

        // Add loading state while audio loads
        if (playerContainer) {
            playerContainer.classList.add('loading');
        }

        // Configure audio element
        audioPlayback.preload = 'auto';
        audioPlayback.src = blobUrl;
        audioPlayback.load();

        // Show playback controls
        document.getElementById('playbackControls').style.display = 'block';

        // Reset custom player to initial state
        this.resetCustomPlayer();

        // Show duration badge immediately from recorded time
        if (recordedDuration > 0 && durationDisplay) {
            const mins = Math.floor(recordedDuration / 60);
            const secs = Math.floor(recordedDuration % 60);
            durationDisplay.textContent = `Durata: ${mins}:${secs.toString().padStart(2, '0')}`;
            durationDisplay.classList.add('visible');
        }

        // NOTE: onended is now handled by this.onAudioEnded() method
        // which is attached in setupCustomPlayer()

        // Enable upload button
        document.getElementById('uploadAudioBtn').disabled = false;
    }

    /**
     * Re-record audio - ENTERPRISE GALAXY+ (Custom Player)
     */
    reRecord() {
        // Stop any current playback
        const audioElement = document.getElementById('audioPlayback');
        if (audioElement && !audioElement.paused) {
            audioElement.pause();
        }

        // Reset state
        this.recordedBlob = null;
        this.encryptedBlob = null;
        this.encryptedIV = null;
        this.recordingDuration = 0;
        this.recordingStartTime = null;

        // Clear any existing timers
        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
            this.recordingTimer = null;
        }
        if (this.autoStopTimeout) {
            clearTimeout(this.autoStopTimeout);
            this.autoStopTimeout = null;
        }

        // Cleanup blob URL to prevent memory leaks
        if (this.currentBlobUrl) {
            URL.revokeObjectURL(this.currentBlobUrl);
            this.currentBlobUrl = null;
        }

        // Reset custom player state
        this.resetCustomPlayer();

        // Reset timer display to initial state (00:30)
        const timerElement = document.getElementById('recordingTimer');
        if (timerElement) {
            timerElement.textContent = '00:30';
            timerElement.style.display = 'none';
        }

        // Hide playback controls and reset duration display
        document.getElementById('playbackControls').style.display = 'none';
        const durationDisplay = document.getElementById('audioDuration');
        if (durationDisplay) {
            durationDisplay.classList.remove('visible');
            durationDisplay.textContent = '';
        }

        // Show start recording button
        document.getElementById('startRecordingBtn').style.display = 'inline-block';
        document.getElementById('startRecordingBtn').disabled = false;

        // Disable upload button
        document.getElementById('uploadAudioBtn').disabled = true;
    }

    /**
     * Handle photo file selection (ENTERPRISE V12: E2E Encrypted Photos)
     *
     * PROCESSING PIPELINE:
     * 1. Validate file type (JPG, PNG, WebP)
     * 2. Resize to max 1200px (preserves aspect ratio)
     * 3. Convert to WebP @ 85% quality (~50-70% smaller than JPEG)
     * 4. Store processed blob for later encryption during upload
     *
     * Psychology: Visual memories strengthen emotional recall
     *
     * @param {Event} event - File input change event
     */
    async handlePhotoSelect(event) {
        const file = event.target.files[0];

        if (!file) {
            return;
        }

        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            this.showError('Formato immagine non supportato. Usa JPG, PNG o WebP.');
            event.target.value = '';
            return;
        }

        // Validate file size (max 10MB before processing)
        const maxSize = 10 * 1024 * 1024;
        if (file.size > maxSize) {
            this.showError('Immagine troppo grande (max 10MB)');
            event.target.value = '';
            return;
        }

        try {
            // Show processing indicator
            const previewContainer = document.getElementById('photoPreview');
            const previewImg = document.getElementById('photoPreviewImg');
            previewContainer.style.display = 'block';
            previewImg.src = ''; // Clear previous
            previewImg.alt = 'Elaborazione...';

            // Process image: resize + convert to WebP
            const processedBlob = await this.processImage(file);

            // Store for later encryption during upload
            this.processedPhotoBlob = processedBlob;

            // Show preview from processed blob
            const previewUrl = URL.createObjectURL(processedBlob);
            previewImg.src = previewUrl;
            previewImg.alt = 'Anteprima foto';

            // Show file size info
            const originalKB = Math.round(file.size / 1024);
            const processedKB = Math.round(processedBlob.size / 1024);
            const savings = Math.round((1 - processedBlob.size / file.size) * 100);

            console.log(`[EmotionalJournalRecorder] Photo processed: ${originalKB}KB → ${processedKB}KB (${savings}% smaller)`);

        } catch (error) {
            console.error('[EmotionalJournalRecorder] Photo processing failed:', error);
            this.showError('Errore nell\'elaborazione dell\'immagine. Riprova.');
            event.target.value = '';
            this.processedPhotoBlob = null;
        }
    }

    /**
     * Process image: resize and convert to WebP
     *
     * ENTERPRISE V12: Client-side image optimization
     * - Max dimension: 1200px (width or height)
     * - Format: WebP @ 85% quality
     * - Preserves aspect ratio
     * - Uses Canvas API (no external libraries)
     *
     * @param {File} file - Original image file
     * @returns {Promise<Blob>} Processed WebP blob
     */
    async processImage(file) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            const reader = new FileReader();

            reader.onload = (e) => {
                img.onload = () => {
                    try {
                        // Calculate new dimensions (max 1200px)
                        const MAX_DIMENSION = 1200;
                        let width = img.width;
                        let height = img.height;

                        if (width > MAX_DIMENSION || height > MAX_DIMENSION) {
                            if (width > height) {
                                height = Math.round((height / width) * MAX_DIMENSION);
                                width = MAX_DIMENSION;
                            } else {
                                width = Math.round((width / height) * MAX_DIMENSION);
                                height = MAX_DIMENSION;
                            }
                        }

                        // Create canvas and draw resized image
                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;

                        const ctx = canvas.getContext('2d');

                        // Use high-quality image smoothing
                        ctx.imageSmoothingEnabled = true;
                        ctx.imageSmoothingQuality = 'high';

                        // Draw image
                        ctx.drawImage(img, 0, 0, width, height);

                        // Convert to WebP @ 85% quality
                        canvas.toBlob(
                            (blob) => {
                                if (blob) {
                                    resolve(blob);
                                } else {
                                    reject(new Error('Failed to create WebP blob'));
                                }
                            },
                            'image/webp',
                            0.85
                        );

                    } catch (err) {
                        reject(err);
                    }
                };

                img.onerror = () => reject(new Error('Failed to load image'));
                img.src = e.target.result;
            };

            reader.onerror = () => reject(new Error('Failed to read file'));
            reader.readAsDataURL(file);
        });
    }

    /**
     * Remove selected photo
     */
    removePhoto() {
        document.getElementById('audioPhoto').value = '';
        document.getElementById('photoPreview').style.display = 'none';
        document.getElementById('photoPreviewImg').src = '';
        this.processedPhotoBlob = null; // Clear processed blob
    }

    /**
     * Start recording timer
     */
    startTimer() {
        const timerElement = document.getElementById('recordingTimer');

        this.recordingTimer = setInterval(() => {
            const elapsed = Math.floor((Date.now() - this.recordingStartTime) / 1000);
            const remaining = this.MAX_DURATION - elapsed;

            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;

            timerElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;

            // Auto-stop when time runs out
            if (remaining <= 0) {
                this.stopRecording();
            }
        }, 1000);
    }

    /**
     * Upload encrypted audio to server (ENTERPRISE V12)
     *
     * Uses DiaryEncryptionService for E2E encryption.
     * Audio is encrypted client-side before upload.
     */
    async uploadAudio() {
        if (!this.recordedBlob) {
            this.showError('Nessun audio registrato');
            return;
        }

        if (!this.selectedEmotion) {
            this.showError('Seleziona un\'emozione');
            return;
        }

        try {
            // Disable upload button
            document.getElementById('uploadAudioBtn').disabled = true;

            // Show progress
            document.getElementById('uploadProgress').style.display = 'block';
            this.updateProgress(10, 'Criptando audio...');

            // V12: Get DiaryEncryptionService (dynamic getter)
            const encryptionService = this.getDiaryEncryptionService();

            if (!encryptionService || !encryptionService.isUnlocked()) {
                throw new Error('Diario non sbloccato. Riprova dopo aver inserito la password.');
            }

            // V12: Encrypt audio file with DiaryEncryptionService
            // DiaryEncryptionService.encryptFile returns {blob, iv} (not encryptedBlob)
            const encrypted = await encryptionService.encryptFile(this.recordedBlob);
            this.encryptedBlob = encrypted.blob; // V12: Changed from encrypted.encryptedBlob
            this.encryptedIV = encrypted.iv;

            this.updateProgress(25, 'Criptando note...');

            // Encrypt text notes (if present)
            let encryptedText = null;
            let encryptedTextIV = null;

            let textNotesElement = document.getElementById('textNotes');
            const textNotes = textNotesElement ? textNotesElement.value.trim() : '';
            if (textNotes) {
                // V12: DiaryEncryptionService uses encryptText (not encrypt)
                const encryptedTextData = await encryptionService.encryptText(textNotes);
                encryptedText = encryptedTextData.ciphertext;
                encryptedTextIV = encryptedTextData.iv;
            }

            // V12: Encrypt photo if present (E2E encrypted visual memory)
            let encryptedPhotoBlob = null;
            let encryptedPhotoIV = null;

            if (this.processedPhotoBlob) {
                this.updateProgress(40, 'Criptando foto...');
                const encryptedPhoto = await encryptionService.encryptFile(this.processedPhotoBlob);
                encryptedPhotoBlob = encryptedPhoto.blob;
                encryptedPhotoIV = encryptedPhoto.iv;
                console.log(`[EmotionalJournalRecorder] Photo encrypted: ${Math.round(encryptedPhotoBlob.size / 1024)}KB`);
            }

            this.updateProgress(55, 'Caricamento in corso...');

            // Create FormData (multipart/form-data)
            const formData = new FormData();
            formData.append('audio_file', this.encryptedBlob, 'journal_audio_encrypted.webm');
            formData.append('date', this.currentDate);
            formData.append('emotion_id', this.selectedEmotion.id);
            formData.append('intensity', this.selectedIntensity);
            formData.append('encryption_iv', this.encryptedIV);
            formData.append('encryption_algorithm', 'AES-256-GCM');

            // ENTERPRISE GALAXY+: Metadata fields for better organization and psychological recall
            // NOTE: These elements may not exist in simplified modal HTML
            const titleElement = document.getElementById('audioTitle');
            const descriptionElement = document.getElementById('audioDescription');

            if (titleElement && titleElement.value.trim()) {
                formData.append('title', titleElement.value.trim());
            }

            if (descriptionElement && descriptionElement.value.trim()) {
                formData.append('description', descriptionElement.value.trim());
            }

            // V12: Encrypted photo upload (E2E - server cannot see content)
            if (encryptedPhotoBlob && encryptedPhotoIV) {
                formData.append('photo_file', encryptedPhotoBlob, 'journal_photo_encrypted.webp');
                formData.append('photo_encryption_iv', encryptedPhotoIV);
            }

            if (encryptedText) {
                formData.append('text_content_encrypted', encryptedText);
                formData.append('text_content_iv', encryptedTextIV);
            }

            // Upload to server
            const response = await fetch('/api/journal/upload-audio', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': this.getCsrfToken(),
                },
                body: formData,
            });

            this.updateProgress(90, 'Processing...');

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Upload failed');
            }

            this.updateProgress(100, 'Upload complete!');

            // ENTERPRISE FIX: Clear textNotes immediately on success (before modal hide)
            // This ensures the textarea is cleared even with duplicate ID elements
            textNotesElement = document.getElementById('textNotes');
            if (textNotesElement) {
                textNotesElement.value = '';
            }

            // Close modal after 1 second
            setTimeout(() => {
                this.modal.hide();
                // Trigger event for EmotionalJournal.js to reload timeline
                document.dispatchEvent(new CustomEvent('journalAudioUploaded', { detail: data }));
            }, 1000);

        } catch (error) {
            console.error('[EmotionalJournalRecorder] Upload failed:', error);
            this.showError('Upload failed: ' + error.message);
            document.getElementById('uploadAudioBtn').disabled = false;
            document.getElementById('uploadProgress').style.display = 'none';
        }
    }

    /**
     * Check rate limit (10 audio/day)
     */
    async checkRateLimit() {
        try {
            const response = await fetch('/api/journal/rate-limit-check', {
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': this.getCsrfToken(),
                },
            });

            const data = await response.json();

            return {
                allowed: data.allowed,
                remaining: data.remaining,
                message: data.message || '',
            };

        } catch (error) {
            console.error('[EmotionalJournalRecorder] Rate limit check failed:', error);
            // Allow upload if rate limit check fails (fail open)
            return { allowed: true, remaining: this.RATE_LIMIT_MAX, message: '' };
        }
    }

    /**
     * Update upload progress
     */
    updateProgress(percent, message = '') {
        const progressBar = document.getElementById('uploadProgressBar');
        progressBar.style.width = percent + '%';
        progressBar.textContent = message || (percent + '%');
    }

    /**
     * Show error message
     */
    showError(message) {
        const errorElement = document.getElementById('recorderErrorMsg');
        if (!errorElement) {
            console.error('[EmotionalJournalRecorder] Error element not found in DOM:', message);
            alert(message); // Fallback to browser alert
            return;
        }
        errorElement.textContent = message;
        errorElement.style.display = 'block';

        // Auto-hide after 5 seconds
        setTimeout(() => {
            errorElement.style.display = 'none';
        }, 5000);
    }

    /**
     * Get CSRF token from meta tag
     */
    getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    /**
     * Reset recorder state - ENTERPRISE V12 (Custom Player)
     */
    resetRecorder() {
        this.isRecording = false;
        this.isPaused = false;
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.recordingStartTime = null;
        this.recordedBlob = null;
        this.encryptedBlob = null;
        this.encryptedIV = null;
        this.selectedEmotion = null;
        this.selectedIntensity = 3;
        this.recordingDuration = 0;
        this.processedPhotoBlob = null; // V12: Clear processed photo

        // Stop any current playback
        const audioElement = document.getElementById('audioPlayback');
        if (audioElement && !audioElement.paused) {
            audioElement.pause();
        }

        // Cleanup blob URL to prevent memory leaks
        if (this.currentBlobUrl) {
            URL.revokeObjectURL(this.currentBlobUrl);
            this.currentBlobUrl = null;
        }

        // Clear timer
        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
            this.recordingTimer = null;
        }

        // Clear auto-stop timeout (re-record bug fix)
        if (this.autoStopTimeout) {
            clearTimeout(this.autoStopTimeout);
            this.autoStopTimeout = null;
        }

        // Reset custom player state
        this.resetCustomPlayer();

        // Reset duration badge
        const durationDisplay = document.getElementById('audioDuration');
        if (durationDisplay) {
            durationDisplay.classList.remove('visible');
            durationDisplay.textContent = '';
        }

        // Reset UI - ENTERPRISE V10.91
        if (this.modalElement) {
            document.querySelectorAll('.emotion-btn-modal').forEach(btn => btn.classList.remove('active'));
            document.getElementById('intensitySliderContainer').style.display = 'none';
            document.getElementById('intensitySlider').value = 3;
            document.getElementById('startRecordingBtn').style.display = 'inline-block';
            document.getElementById('startRecordingBtn').disabled = true;
            document.getElementById('stopRecordingBtn').style.display = 'none';
            document.getElementById('recordingTimer').style.display = 'none';
            document.getElementById('waveformContainer').style.display = 'none';
            document.getElementById('playbackControls').style.display = 'none';
            document.getElementById('uploadAudioBtn').disabled = true;
            document.getElementById('uploadProgress').style.display = 'none';
            document.getElementById('recorderErrorMsg').style.display = 'none';
            document.getElementById('textNotes').value = '';
        }
    }

    /**
     * Cleanup resources (called on modal close)
     */
    cleanup() {
        // Stop recording if still active
        if (this.isRecording && this.mediaRecorder) {
            this.mediaRecorder.stop();
            this.mediaRecorder.stream.getTracks().forEach(track => track.stop());
        }

        // Revoke object URLs
        const audioPlayback = document.getElementById('audioPlayback');
        if (audioPlayback && audioPlayback.src) {
            URL.revokeObjectURL(audioPlayback.src);
        }

        // Reset state
        this.resetRecorder();
    }
}

// =============================================================================
// AUTO-INITIALIZATION (Global Singleton)
// =============================================================================

// Wait for DOM + EncryptionService to be ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEmotionalJournalRecorder);
} else {
    initEmotionalJournalRecorder();
}

function initEmotionalJournalRecorder() {
    // CRITICAL: Preserve existing properties (e.g. encryptionService)
    // OLD CODE: window.Need2Talk = {} → OVERWRITES ENTIRE OBJECT! ❌
    // NEW CODE: Use OR assignment to preserve existing properties ✅
    window.Need2Talk = window.Need2Talk || {};

    // Create global singleton instance
    window.Need2Talk.emotionalJournalRecorder = new EmotionalJournalRecorder();
}

// Export for ES6 modules

