/**
 * Enterprise Audio Player - need2talk Galaxy
 *
 * Professional custom audio player with cross-browser progress bar
 * Replaces ugly default HTML5 player controls
 *
 * Features:
 * - Custom UI matching need2talk design system
 * - Progress bar that works in ALL browsers (Safari, Chrome, Firefox, mobile)
 * - Play/pause, seek, volume controls
 * - Time display (current / total)
 * - Smooth animations
 * - Accessibility support (ARIA labels, keyboard navigation)
 * - Performance: <10ms UI updates, no jank
 *
 * Browser Support: Chrome, Firefox, Safari, Edge, Mobile Safari, Mobile Chrome
 *
 * @author Claude Code (AI-Orchestrated Development)
 * @version 2.0.0 Enterprise Galaxy
 */

class EnterpriseAudioPlayer {
    /**
     * @param {HTMLAudioElement} audioElement - Native audio element to enhance
     * @param {Object} options - Configuration options
     * @param {number} options.postId - Audio post ID (for tracking)
     * @param {boolean} options.enableTracking - Enable 80% tracking (default: true)
     */
    constructor(audioElement, options = {}) {
        this.audio = audioElement;
        this.options = {
            showVolume: true,
            showDownload: false,
            accentColor: '#8B5CF6', // Purple accent
            postId: null, // Audio post ID for tracking
            enableTracking: true, // Enable 80% listen tracking
            ...options
        };

        this.isPlaying = false;
        this.isDragging = false;
        this.isTransitioning = false; // Prevent double-clicks during play/pause
        this.container = null;

        // Volume control state (ENTERPRISE GALAXY)
        this.isMuted = false;
        this.volumeBeforeMute = 1.0; // Store volume before muting

        // ENTERPRISE GALAXY: 80% listen tracking
        this.hasTracked80Percent = false;
        this.lastTrackingUpdate = 0;
        this.trackingCooldown = 5000; // 5 seconds between tracking updates (prevent spam)

        // ENTERPRISE FIX: Read duration from DB (data-duration attribute)
        // WebM metadata loading is unreliable, use DB value instead
        this.durationFromDB = parseFloat(this.audio.getAttribute('data-duration')) || null;

        // ENTERPRISE 2025-12-13: Safari/Chrome compatibility
        // Safari needs special handling for WebM/Opus playback
        this.isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        this.isChrome = /chrome/i.test(navigator.userAgent) && !/edge/i.test(navigator.userAgent);

        this.init();
    }

    /**
     * Initialize custom player UI
     */
    init() {
        // Hide native controls
        this.audio.controls = false;

        // Create custom UI
        this.createPlayerUI();

        // Bind events
        this.bindAudioEvents();
        this.bindUIEvents();

        // Initial state
        this.updateTime();
        this.updateProgress();
    }

    /**
     * Create custom player UI (enterprise design)
     */
    createPlayerUI() {
        // Create container
        this.container = document.createElement('div');
        this.container.className = 'enterprise-audio-player';
        this.container.innerHTML = this.getPlayerHTML();

        // Replace audio element with container
        this.audio.parentNode.insertBefore(this.container, this.audio);
        this.container.appendChild(this.audio);

        // Cache DOM references
        this.playBtn = this.container.querySelector('.player-play-btn');
        this.progressBar = this.container.querySelector('.player-progress-bar');
        this.progressFill = this.container.querySelector('.player-progress-fill');
        this.progressHandle = this.container.querySelector('.player-progress-handle');
        this.currentTimeEl = this.container.querySelector('.player-time-current');
        this.totalTimeEl = this.container.querySelector('.player-time-total');
        this.volumeBtn = this.container.querySelector('.player-volume-btn');
        this.volumeSlider = this.container.querySelector('.player-volume-slider');
        this.volumeIconHigh = this.container.querySelector('.player-icon-volume-high');
        this.volumeIconMuted = this.container.querySelector('.player-icon-volume-muted');
    }

    /**
     * Get player HTML template
     * @returns {string} HTML
     */
    getPlayerHTML() {
        return `
            <!-- Enterprise Audio Player UI -->
            <div class="player-controls">
                <!-- Play/Pause Button -->
                <button class="player-play-btn" aria-label="Play" title="Play">
                    <svg class="player-icon-play" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                    <svg class="player-icon-pause hidden" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>
                    </svg>
                </button>

                <!-- Progress Container -->
                <div class="player-progress-container">
                    <!-- Time Display -->
                    <div class="player-time-display">
                        <span class="player-time-current">0:00</span>
                        <span class="player-time-separator">/</span>
                        <span class="player-time-total">0:00</span>
                    </div>

                    <!-- Progress Bar (CROSS-BROWSER COMPATIBLE) -->
                    <div class="player-progress-bar" role="slider" aria-label="Seek audio" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" tabindex="0">
                        <div class="player-progress-track"></div>
                        <div class="player-progress-fill" style="width: 0%"></div>
                        <div class="player-progress-handle" style="left: 0%"></div>
                    </div>
                </div>

                <!-- Volume Button (optional) -->
                ${this.options.showVolume ? `
                    <button class="player-volume-btn" aria-label="Mute" title="Mute">
                        <!-- Volume High Icon (default) -->
                        <svg class="player-icon-volume-high" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/>
                        </svg>
                        <!-- Volume Muted Icon (hidden by default) -->
                        <svg class="player-icon-volume-muted hidden" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
                        </svg>
                    </button>
                    <input type="range" class="player-volume-slider" min="0" max="100" value="100" aria-label="Volume level">
                ` : ''}
            </div>
        `;
    }

    /**
     * Bind audio element events
     */
    bindAudioEvents() {
        // Update UI on audio events
        this.audio.addEventListener('loadedmetadata', () => {
            this.updateTime();
            this.updateProgress();
        });

        this.audio.addEventListener('timeupdate', () => {
            if (!this.isDragging) {
                this.updateProgress();
                this.updateTime();
            }

            // ENTERPRISE GALAXY: Track 80% listen threshold
            this.checkListenThreshold();
        });

        this.audio.addEventListener('play', () => {
            this.isPlaying = true;
            this.updatePlayButton();
        });

        this.audio.addEventListener('pause', () => {
            this.isPlaying = false;
            this.updatePlayButton();
        });

        this.audio.addEventListener('ended', () => {
            this.isPlaying = false;
            // ENTERPRISE FIX (2025-12-21): Reset currentTime to 0 so user can replay
            // Without this, clicking play after audio ends does nothing (already at end)
            this.audio.currentTime = 0;
            this.updatePlayButton();
            this.updateProgress();
        });

        // Volume change
        this.audio.addEventListener('volumechange', () => {
            if (this.volumeSlider) {
                this.volumeSlider.value = this.audio.volume * 100;
            }
        });
    }

    /**
     * Bind UI control events
     */
    bindUIEvents() {
        // Play/Pause button
        this.playBtn.addEventListener('click', () => this.togglePlay());

        // Progress bar - Click to seek
        this.progressBar.addEventListener('click', (e) => this.handleProgressClick(e));

        // Progress bar - Drag to seek (CROSS-BROWSER)
        this.progressBar.addEventListener('mousedown', (e) => this.startDrag(e));
        document.addEventListener('mousemove', (e) => this.handleDrag(e));
        document.addEventListener('mouseup', () => this.endDrag());

        // Touch support for mobile
        this.progressBar.addEventListener('touchstart', (e) => this.startDrag(e), { passive: false });
        document.addEventListener('touchmove', (e) => this.handleDrag(e), { passive: false });
        document.addEventListener('touchend', () => this.endDrag());

        // Keyboard navigation (accessibility)
        this.progressBar.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                this.seek(this.audio.currentTime - 5);
                e.preventDefault();
            } else if (e.key === 'ArrowRight') {
                this.seek(this.audio.currentTime + 5);
                e.preventDefault();
            }
        });

        // Volume controls
        if (this.volumeBtn && this.volumeSlider) {
            // Volume button - Toggle mute/unmute
            this.volumeBtn.addEventListener('click', () => this.toggleMute());

            // Volume slider - Change volume
            this.volumeSlider.addEventListener('input', (e) => {
                const volume = e.target.value / 100;
                this.audio.volume = volume;

                // Auto-unmute if user moves slider
                if (volume > 0 && this.isMuted) {
                    this.isMuted = false;
                    this.updateVolumeIcon();
                }
            });
        }
    }

    /**
     * Toggle play/pause
     * ENTERPRISE FIX: Pause all other players before playing (only one active)
     */
    togglePlay() {
        console.log('[EnterpriseAudioPlayer] togglePlay called, isPlaying:', this.isPlaying, 'isTransitioning:', this.isTransitioning);

        // Prevent double-clicks during transition
        if (this.isTransitioning) {
            console.log('[EnterpriseAudioPlayer] Blocked - isTransitioning');
            return;
        }

        this.isTransitioning = true;

        if (this.isPlaying) {
            this.audio.pause();
            this.isTransitioning = false;
        } else {
            // ENTERPRISE FIX: Pause ALL other audio players on the page
            // This ensures only ONE player is active at a time (like YouTube, Spotify, etc.)
            this.pauseOtherPlayers();

            // ENTERPRISE 2025-12-13: Safari/Chrome compatibility fix
            // Check if audio is ready before playing
            if (this.audio.readyState >= 2) {
                // Audio is ready, play directly
                this.playAudio();
            } else {
                // Audio not ready, wait for canplay event
                console.log('[EnterpriseAudioPlayer] Audio not ready (readyState:', this.audio.readyState, '), waiting for canplay...');

                const canplayHandler = () => {
                    this.audio.removeEventListener('canplay', canplayHandler);
                    this.playAudio();
                };
                this.audio.addEventListener('canplay', canplayHandler, { once: true });

                // Also try loading if not started
                if (this.audio.readyState === 0) {
                    this.audio.load();
                }

                // Timeout fallback for Safari (sometimes canplay never fires)
                setTimeout(() => {
                    if (!this.isPlaying && this.audio.readyState >= 1) {
                        this.audio.removeEventListener('canplay', canplayHandler);
                        this.playAudio();
                    }
                }, this.isSafari ? 500 : 200);
            }
        }
    }

    /**
     * ENTERPRISE 2025-12-13: Separated play logic for Safari/Chrome compatibility
     */
    playAudio() {
        this.audio.play().catch(err => {
            // ENTERPRISE GALAXY (2025-11-21): Ignore AbortError (normal when user clicks rapidly)
            if (err.name === 'AbortError') {
                console.debug('EnterpriseAudioPlayer: Play aborted (normal - user clicked pause quickly)');
                return;
            }

            // Handle Safari NotSupportedError
            if (err.name === 'NotSupportedError') {
                console.error('EnterpriseAudioPlayer: Format not supported (Safari WebM issue?)');
                // Could show user-friendly message here
            }

            // ENTERPRISE FIX: Log full error details (name + message)
            // Chrome autoplay policy returns DOMException with useful message
            console.error('EnterpriseAudioPlayer: Play failed', {
                error: err.name || 'UnknownError',
                message: err.message || 'No message',
                full_error: err
            });
        }).finally(() => {
            this.isTransitioning = false;
        });
    }

    /**
     * ENTERPRISE FIX: Pause all other audio players on the page
     * Ensures only one player is active at a time
     */
    pauseOtherPlayers() {
        const allAudioElements = document.querySelectorAll('audio');

        allAudioElements.forEach(audio => {
            // Skip this audio element
            if (audio === this.audio) {
                return;
            }

            // Pause if playing
            if (!audio.paused) {
                audio.pause();
            }
        });
    }

    /**
     * Update play button icon
     */
    updatePlayButton() {
        const playIcon = this.playBtn.querySelector('.player-icon-play');
        const pauseIcon = this.playBtn.querySelector('.player-icon-pause');

        if (this.isPlaying) {
            playIcon.classList.add('hidden');
            pauseIcon.classList.remove('hidden');
            this.playBtn.setAttribute('aria-label', 'Pause');
            this.playBtn.setAttribute('title', 'Pause');
        } else {
            playIcon.classList.remove('hidden');
            pauseIcon.classList.add('hidden');
            this.playBtn.setAttribute('aria-label', 'Play');
            this.playBtn.setAttribute('title', 'Play');
        }
    }

    /**
     * Toggle Mute/Unmute (ENTERPRISE GALAXY)
     */
    toggleMute() {
        if (this.isMuted) {
            // Unmute: restore previous volume
            this.audio.volume = this.volumeBeforeMute;
            if (this.volumeSlider) {
                this.volumeSlider.value = this.volumeBeforeMute * 100;
            }
            this.isMuted = false;
        } else {
            // Mute: save current volume and set to 0
            this.volumeBeforeMute = this.audio.volume;
            this.audio.volume = 0;
            if (this.volumeSlider) {
                this.volumeSlider.value = 0;
            }
            this.isMuted = true;
        }

        this.updateVolumeIcon();
    }

    /**
     * Update volume button icon based on mute state (ENTERPRISE GALAXY)
     */
    updateVolumeIcon() {
        if (!this.volumeIconHigh || !this.volumeIconMuted) return;

        if (this.isMuted || this.audio.volume === 0) {
            this.volumeIconHigh.classList.add('hidden');
            this.volumeIconMuted.classList.remove('hidden');
            this.volumeBtn.setAttribute('aria-label', 'Unmute');
            this.volumeBtn.setAttribute('title', 'Unmute');
        } else {
            this.volumeIconHigh.classList.remove('hidden');
            this.volumeIconMuted.classList.add('hidden');
            this.volumeBtn.setAttribute('aria-label', 'Mute');
            this.volumeBtn.setAttribute('title', 'Mute');
        }
    }

    /**
     * Update progress bar (CROSS-BROWSER) (ENTERPRISE FIX: Use DB duration)
     */
    updateProgress() {
        // ENTERPRISE FIX: Use durationFromDB if audio.duration is NaN/Infinity
        const duration = (isNaN(this.audio.duration) || this.audio.duration === Infinity) && this.durationFromDB
            ? this.durationFromDB
            : this.audio.duration;

        if (duration && !isNaN(duration)) {
            const progress = (this.audio.currentTime / duration) * 100;

            // Update fill width
            this.progressFill.style.width = `${progress}%`;

            // Update handle position
            this.progressHandle.style.left = `${progress}%`;

            // Update ARIA
            this.progressBar.setAttribute('aria-valuenow', Math.round(progress));
        }
    }

    /**
     * Update time display (ENTERPRISE FIX: Use DB duration if metadata not loaded)
     */
    updateTime() {
        const current = this.formatTime(this.audio.currentTime);

        // ENTERPRISE FIX: Use durationFromDB if audio.duration is NaN/Infinity
        // WebM metadata loading is unreliable, DB value is always correct
        const duration = (isNaN(this.audio.duration) || this.audio.duration === Infinity) && this.durationFromDB
            ? this.durationFromDB
            : this.audio.duration;

        const total = this.formatTime(duration);

        this.currentTimeEl.textContent = current;
        this.totalTimeEl.textContent = total;
    }

    /**
     * Format time (seconds to MM:SS)
     * @param {number} seconds
     * @returns {string} Formatted time
     */
    formatTime(seconds) {
        if (isNaN(seconds) || seconds === Infinity) {
            return '0:00';
        }

        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    /**
     * Handle progress bar click (jump to position)
     * @param {MouseEvent} e
     */
    handleProgressClick(e) {
        const rect = this.progressBar.getBoundingClientRect();
        const clickX = e.clientX - rect.left;
        const percentage = clickX / rect.width;
        const newTime = percentage * this.audio.duration;

        this.seek(newTime);
    }

    /**
     * Start dragging progress handle
     * @param {MouseEvent|TouchEvent} e
     */
    startDrag(e) {
        this.isDragging = true;
        this.progressBar.classList.add('dragging');

        // Prevent text selection during drag
        e.preventDefault();
    }

    /**
     * Handle dragging
     * @param {MouseEvent|TouchEvent} e
     */
    handleDrag(e) {
        if (!this.isDragging) return;

        const rect = this.progressBar.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const dragX = clientX - rect.left;
        const percentage = Math.max(0, Math.min(1, dragX / rect.width));
        const newTime = percentage * this.audio.duration;

        // Update UI immediately (optimistic)
        const progress = percentage * 100;
        this.progressFill.style.width = `${progress}%`;
        this.progressHandle.style.left = `${progress}%`;
        this.currentTimeEl.textContent = this.formatTime(newTime);

        // Prevent default to avoid scrolling on mobile
        e.preventDefault();
    }

    /**
     * End dragging and seek
     *
     * ENTERPRISE V8.1 (2025-12-01): Added validation to prevent non-finite currentTime errors
     */
    endDrag() {
        if (!this.isDragging) return;

        this.isDragging = false;
        this.progressBar.classList.remove('dragging');

        // ENTERPRISE V8.1: Validate duration before calculating seek position
        if (!this.audio.duration || !isFinite(this.audio.duration) || this.audio.duration <= 0) {
            console.warn('[EnterpriseAudioPlayer] endDrag: Invalid duration, skipping seek');
            return;
        }

        // Calculate final seek position with validation
        const widthStr = this.progressFill.style.width || '0%';
        const percentage = parseFloat(widthStr) / 100;

        // ENTERPRISE V8.1: Validate percentage is a finite number
        if (!isFinite(percentage) || percentage < 0 || percentage > 1) {
            console.warn('[EnterpriseAudioPlayer] endDrag: Invalid percentage', { widthStr, percentage });
            return;
        }

        const newTime = percentage * this.audio.duration;
        this.seek(newTime);
    }

    /**
     * Seek to specific time
     *
     * ENTERPRISE V8.1 (2025-12-01): Added isFinite() validation to prevent
     * "Value being assigned is not a finite floating-point value" error
     *
     * @param {number} time - Time in seconds
     */
    seek(time) {
        // ENTERPRISE V8.1: Full validation - both time and duration must be finite
        if (!isFinite(time) || !isFinite(this.audio.duration) || this.audio.duration <= 0) {
            console.warn('[EnterpriseAudioPlayer] seek: Invalid time or duration', {
                time,
                duration: this.audio.duration
            });
            return;
        }

        // Clamp time to valid range [0, duration]
        const clampedTime = Math.max(0, Math.min(time, this.audio.duration));

        // Final safety check before setting currentTime
        if (isFinite(clampedTime)) {
            this.audio.currentTime = clampedTime;
            this.updateProgress();
            this.updateTime();
        }
    }

    /**
     * ENTERPRISE GALAXY: Check 80% listen threshold and track
     */
    checkListenThreshold() {
        // Skip if tracking disabled or no postId
        if (!this.options.enableTracking || !this.options.postId) {
            return;
        }

        // Skip if already tracked 80%
        if (this.hasTracked80Percent) {
            return;
        }

        // Skip if audio not loaded or duration invalid
        if (!this.audio.duration || isNaN(this.audio.duration) || this.audio.duration === 0) {
            return;
        }

        // Calculate percentage
        const percentage = (this.audio.currentTime / this.audio.duration) * 100;

        // Check if reached 80%
        if (percentage >= 80) {
            // Cooldown check (prevent spam during same listen session)
            const now = Date.now();
            if (now - this.lastTrackingUpdate < this.trackingCooldown) {
                return;
            }

            this.lastTrackingUpdate = now;
            this.hasTracked80Percent = true;

            // Track to backend
            this.trackListenProgress(percentage, this.audio.currentTime);
        }
    }

    /**
     * =========================================================================
     * ENTERPRISE GALAXY V10: OPTIMISTIC UPDATE SYSTEM
     * =========================================================================
     *
     * Architecture inspired by Facebook/Instagram/Twitter:
     * 1. OPTIMISTIC: Increment UI immediately (before API response)
     * 2. CONFIRM: Server response validates the increment
     * 3. ROLLBACK: If server rejects, decrement with visual feedback
     * 4. DEDUPLICATION: Prevent double-counting via pending state
     *
     * UX Benefits:
     * - Zero perceived latency (instant feedback)
     * - User sees their action immediately
     * - Rollback is rare and graceful
     *
     * @param {number} percentage Listen percentage (0-100)
     * @param {number} durationPlayed Duration played in seconds
     */
    async trackListenProgress(percentage, durationPlayed) {
        const postId = this.options.postId;

        if (!postId) {
            console.warn('[EnterpriseAudioPlayer] Cannot track: No postId provided');
            return;
        }

        // =====================================================================
        // ENTERPRISE V10.2: Skip tracking entirely for own posts
        // Author listens are never counted, so don't show optimistic update either
        // =====================================================================
        if (this.options.isOwner) {
            console.log(`[EnterpriseAudioPlayer] Skipping tracking for own post ${postId}`);
            return;
        }

        // =====================================================================
        // DEDUPLICATION: Prevent multiple optimistic updates for same listen
        // =====================================================================
        if (this._pendingOptimisticUpdate) {
            console.log('[EnterpriseAudioPlayer] Optimistic update already pending, skipping');
            return;
        }

        const optimisticId = `${postId}-${Date.now()}`;
        this._pendingOptimisticUpdate = optimisticId;

        // =====================================================================
        // STEP 1: OPTIMISTIC INCREMENT (before API call)
        // =====================================================================
        const previousCount = this.optimisticIncrement(postId);

        console.log(`[EnterpriseAudioPlayer] 🚀 Optimistic +1 for post ${postId}`, {
            previousCount,
            percentage: percentage.toFixed(2),
            duration: durationPlayed.toFixed(2),
        });

        try {
            // =====================================================================
            // STEP 2: API CALL (runs in background while UI already updated)
            // =====================================================================
            const response = await fetch(`/api/audio/${postId}/track-listen`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    percentage: percentage,
                    duration_played: durationPlayed,
                }),
            });

            const data = await response.json();

            // =====================================================================
            // STEP 3: CONFIRM or ROLLBACK
            // =====================================================================
            if (data.counted) {
                // SUCCESS: Optimistic update confirmed
                console.log(`[EnterpriseAudioPlayer] ✅ Server confirmed! Reason: ${data.reason}`);

                // Confirm animation (subtle pulse)
                this.showOptimisticConfirmation(postId);

                // Dispatch custom event for other components
                document.dispatchEvent(new CustomEvent('audio:listenCounted', {
                    detail: {
                        postId: postId,
                        optimisticId: optimisticId,
                        percentage: percentage,
                        confirmed: true,
                    },
                }));

            } else {
                // REJECTED: Rollback optimistic update
                console.log(`[EnterpriseAudioPlayer] ⚠️ Server rejected: ${data.reason}`, {
                    cooldown_remaining: data.cooldown_remaining,
                });

                // Rollback with visual feedback
                this.optimisticRollback(postId, previousCount, data.reason);

                // Dispatch rejection event
                document.dispatchEvent(new CustomEvent('audio:listenRejected', {
                    detail: {
                        postId: postId,
                        reason: data.reason,
                        cooldown_remaining: data.cooldown_remaining,
                    },
                }));
            }

        } catch (error) {
            // =====================================================================
            // NETWORK ERROR: Rollback with retry hint
            // =====================================================================
            console.error('[EnterpriseAudioPlayer] ❌ Network error:', error);

            // Rollback on network failure
            this.optimisticRollback(postId, previousCount, 'network_error');

        } finally {
            // Clear pending state
            this._pendingOptimisticUpdate = null;
        }
    }

    /**
     * ENTERPRISE V10: Optimistic increment (+1 before server confirms)
     *
     * @param {string|number} postId Post ID
     * @returns {number} Previous count (for rollback)
     */
    optimisticIncrement(postId) {
        // ENTERPRISE V9.6: Use listenCount-{postId} to match FeedManager.js element IDs
        const element = document.getElementById(`listenCount-${postId}`);
        if (!element) return 0;

        // Parse current value (handles "1.2k" format)
        const currentText = element.textContent.trim();
        let currentCount = this.parseFormattedCount(currentText);
        const previousCount = currentCount;

        // Increment
        currentCount += 1;

        // Update UI immediately
        element.textContent = this.formatCount(currentCount);

        // Store for potential rollback
        element.dataset.previousCount = previousCount;
        element.dataset.optimisticCount = currentCount;

        // ENTERPRISE V9.6: Mark as optimistic pending to prevent double-counting
        // FeedManager.handleCounterUpdate will skip WebSocket delta for this element
        element.dataset.optimisticPending = 'true';

        // Visual feedback: Optimistic animation (green pulse)
        element.classList.add('optimistic-pending');
        element.style.transition = 'transform 0.2s ease, color 0.2s ease';
        element.style.transform = 'scale(1.15)';
        element.style.color = '#10B981'; // Green (optimistic)

        setTimeout(() => {
            element.style.transform = 'scale(1)';
        }, 200);

        return previousCount;
    }

    /**
     * ENTERPRISE V10: Confirm optimistic update (server validated)
     *
     * @param {string|number} postId Post ID
     */
    showOptimisticConfirmation(postId) {
        // ENTERPRISE V9.6: Use listenCount-{postId} to match FeedManager.js element IDs
        const element = document.getElementById(`listenCount-${postId}`);
        if (!element) return;

        // Remove pending state
        element.classList.remove('optimistic-pending');
        element.classList.add('optimistic-confirmed');

        // Confirmation animation (purple pulse)
        element.style.transition = 'transform 0.3s ease, color 0.3s ease';
        element.style.transform = 'scale(1.2)';
        element.style.color = '#8B5CF6'; // Purple (confirmed)

        setTimeout(() => {
            element.style.transform = 'scale(1)';
            element.style.color = '';
            element.classList.remove('optimistic-confirmed');
        }, 400);

        // Clean up data attributes
        delete element.dataset.previousCount;
        delete element.dataset.optimisticCount;
        delete element.dataset.optimisticPending;
    }

    /**
     * ENTERPRISE V10: Rollback optimistic update (server rejected)
     *
     * @param {string|number} postId Post ID
     * @param {number} previousCount Count to restore
     * @param {string} reason Rejection reason
     */
    optimisticRollback(postId, previousCount, reason) {
        // ENTERPRISE V9.6: Use listenCount-{postId} to match FeedManager.js element IDs
        const element = document.getElementById(`listenCount-${postId}`);
        if (!element) return;

        // Remove pending state
        element.classList.remove('optimistic-pending');
        element.classList.add('optimistic-rollback');

        // Rollback animation (red shake)
        element.style.transition = 'transform 0.1s ease, color 0.3s ease';
        element.style.color = '#EF4444'; // Red (rollback)

        // Shake animation
        const shake = [
            { transform: 'translateX(-2px)' },
            { transform: 'translateX(2px)' },
            { transform: 'translateX(-2px)' },
            { transform: 'translateX(0)' },
        ];
        element.animate(shake, { duration: 200, iterations: 1 });

        // Restore previous count
        setTimeout(() => {
            element.textContent = this.formatCount(previousCount);
            element.style.color = '';
            element.classList.remove('optimistic-rollback');
        }, 300);

        // Show toast for user feedback (only for cooldown, not author_listen)
        if (reason === 'cooldown') {
            this.showRollbackToast('Attendi prima di riascoltare');
        }

        // Clean up data attributes
        delete element.dataset.previousCount;
        delete element.dataset.optimisticCount;
        delete element.dataset.optimisticPending;
    }

    /**
     * ENTERPRISE V10: Parse formatted count ("1.2k" → 1200)
     *
     * @param {string} text Formatted count text
     * @returns {number} Numeric value
     */
    parseFormattedCount(text) {
        if (!text) return 0;

        text = text.toLowerCase().trim();

        if (text.endsWith('k')) {
            return Math.round(parseFloat(text) * 1000);
        }
        if (text.endsWith('m')) {
            return Math.round(parseFloat(text) * 1000000);
        }

        return parseInt(text, 10) || 0;
    }

    /**
     * ENTERPRISE V10: Format count for display (1200 → "1.2k")
     *
     * @param {number} count Numeric count
     * @returns {string} Formatted string
     */
    formatCount(count) {
        if (count >= 1000000) {
            return (count / 1000000).toFixed(1) + 'M';
        }
        if (count >= 1000) {
            return (count / 1000).toFixed(1) + 'k';
        }
        return count.toString();
    }

    /**
     * ENTERPRISE V10: Show rollback toast notification
     *
     * @param {string} message Toast message
     */
    showRollbackToast(message) {
        // Use Need2Talk notification system if available
        if (typeof Need2Talk !== 'undefined' && Need2Talk.showNotification) {
            Need2Talk.showNotification(message, 'info');
            return;
        }

        // Fallback: Simple toast
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-gray-800 text-white px-4 py-2 rounded-lg shadow-lg z-50 text-sm';
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.transition = 'opacity 0.3s ease';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 2000);
    }

    /**
     * Update listen/play count UI
     * ENTERPRISE V5.3: Renamed from updateViewCountUI - AUDIO platform uses listens!
     *
     * @param {number} newCount New listen/play count
     */
    updateListenCountUI(newCount) {
        if (!newCount) return;

        const postId = this.options.postId;
        const playCountElement = document.getElementById(`playCount-${postId}`);

        if (playCountElement) {
            // Format number (1234 → 1.2k)
            const formatted = newCount >= 1000
                ? (newCount / 1000).toFixed(1) + 'k'
                : newCount.toString();

            playCountElement.textContent = formatted;

            // Add animation
            playCountElement.style.transition = 'transform 0.3s ease, color 0.3s ease';
            playCountElement.style.transform = 'scale(1.2)';
            playCountElement.style.color = '#8B5CF6'; // Purple

            setTimeout(() => {
                playCountElement.style.transform = 'scale(1)';
                playCountElement.style.color = '';
            }, 300);
        }
    }

    /**
     * @deprecated Use updateListenCountUI() - AUDIO files use listens, not views!
     */
    updateViewCountUI(newCount) {
        return this.updateListenCountUI(newCount);
    }

    /**
     * Destroy player (cleanup)
     */
    destroy() {
        // Restore native controls
        this.audio.controls = true;

        // Remove custom UI
        if (this.container && this.container.parentNode) {
            this.container.parentNode.insertBefore(this.audio, this.container);
            this.container.remove();
        }
    }
}

/**
 * Auto-initialize all audio elements on page
 * Converts default <audio controls> to EnterpriseAudioPlayer
 */
function initEnterpriseAudioPlayers() {
    const audioElements = document.querySelectorAll('audio[controls]');

    audioElements.forEach(audio => {
        // Skip if already initialized
        if (audio.classList.contains('enterprise-player-initialized')) {
            return;
        }

        new EnterpriseAudioPlayer(audio);
        audio.classList.add('enterprise-player-initialized');
    });
}

// Auto-init on DOMContentLoaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEnterpriseAudioPlayers);
} else {
    initEnterpriseAudioPlayers();
}

// Export for manual initialization
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { EnterpriseAudioPlayer, initEnterpriseAudioPlayers };
}
