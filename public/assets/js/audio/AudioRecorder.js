/**
 * need2talk - Audio Recorder Component
 * Enterprise Galaxy - Modular WebM Recording System
 *
 * Version: 1.0.0
 * Scalability: 100,000+ concurrent users
 * Performance: <50ms UI response time
 *
 * Purpose: Pure audio recording with waveform visualization
 * Autonomy: 100% independent from emotion selection
 * Format: WebM/Opus 48kbps mono 16kHz (optimized for voice)
 */

class AudioRecorder {
    /**
     * Initialize Audio Recorder
     *
     * @param {Object} config Configuration object
     * @param {string} config.containerId - Container element ID
     * @param {Function} config.onRecordingComplete - Callback when recording ready to upload
     * @param {Function} [config.onError] - Optional error callback
     * @param {number} [config.maxDuration=30] - Max recording duration in seconds
     * @param {number} [config.maxFileSize=500*1024] - Max file size in bytes (500KB)
     * @param {string} [config.uploadEndpoint='/api/audio/upload'] - Upload API endpoint
     */
    constructor(config) {
        this.config = {
            containerId: 'audio-recorder',
            maxDuration: 30,                    // 30 seconds
            maxFileSize: 500 * 1024,            // 500 KB
            uploadEndpoint: '/api/audio/upload',
            ...config
        };

        // Validate required config
        if (!this.config.onRecordingComplete || typeof this.config.onRecordingComplete !== 'function') {
            throw new Error('AudioRecorder: onRecordingComplete callback is required');
        }

        // Component state
        this.container = null;
        this.mediaRecorder = null;
        this.audioStream = null;
        this.audioContext = null;
        this.analyser = null;
        this.animationFrameId = null;
        this.audioChunks = [];
        this.recordedBlob = null;
        this.recordingStartTime = null;
        this.recordingDuration = 0;
        this.timerInterval = null;
        this.selectedEmotionId = null;
        this.selectedEmotionName = null;
        this.selectedEmotionEmoji = null;

        // Recording state
        this.isRecording = false;
        this.isPaused = false;
        this.hasRecording = false;

        // Audio playback references (for cleanup)
        this.audioBlobUrl = null;
        this.currentAudioElement = null;
        this._timeUpdateHandler = null;
        this._endedHandler = null;
        this._playPauseHandler = null;
        this._progressBarHandler = null;

        // Browser compatibility
        this.isSupported = this.checkBrowserSupport();

        // Initialize
        this.init();
    }

    /**
     * Check browser support for required APIs
     * @private
     * @returns {boolean} True if browser supports all required APIs
     */
    checkBrowserSupport() {
        const hasMediaDevices = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
        const hasMediaRecorder = !!window.MediaRecorder;
        const hasAudioContext = !!(window.AudioContext || window.webkitAudioContext);

        if (!hasMediaDevices || !hasMediaRecorder || !hasAudioContext) {
            console.error('AudioRecorder: Browser not supported', {
                mediaDevices: hasMediaDevices,
                mediaRecorder: hasMediaRecorder,
                audioContext: hasAudioContext
            });
            return false;
        }

        return true;
    }

    /**
     * Initialize component
     * @private
     */
    init() {
        // Get container element
        this.container = document.getElementById(this.config.containerId);
        if (!this.container) {
            console.error(`AudioRecorder: Container #${this.config.containerId} not found`);
            return;
        }

        if (!this.isSupported) {
            this.renderUnsupported();
            return;
        }

        // Render initial UI
        this.render();

        // Bind events
        this.bindEvents();
    }

    /**
     * Set emotion for recording
     * Called by EmotionSelector when emotion is selected
     *
     * @public
     * @param {number} emotionId Emotion ID
     * @param {string} emotionName Emotion name (e.g., "Gioia")
     * @param {string} emotionEmoji Emotion emoji (e.g., "😊")
     */
    setEmotion(emotionId, emotionName = null, emotionEmoji = null) {
        this.selectedEmotionId = emotionId;
        this.selectedEmotionName = emotionName;
        this.selectedEmotionEmoji = emotionEmoji;
        console.log('AudioRecorder: Emotion set', { id: emotionId, name: emotionName, emoji: emotionEmoji });

        // Update UI to show emotion selected
        this.updateEmotionUI();
    }

    /**
     * Update UI to reflect emotion selection
     * @private
     */
    updateEmotionUI() {
        if (!this.container) return;
        const emotionIndicator = this.container.querySelector('#emotionIndicator');
        if (emotionIndicator) {
            if (this.selectedEmotionId) {
                // Show indicator
                emotionIndicator.classList.remove('hidden');
                emotionIndicator.classList.add('flex');

                // Update text to show which emotion (emoji + name)
                const textSpan = emotionIndicator.querySelector('.emotion-indicator-text');
                if (textSpan) {
                    const emoji = this.selectedEmotionEmoji || '✓';
                    const name = this.selectedEmotionName || 'Emozione';
                    textSpan.textContent = `${emoji} ${name} selezionata`;
                }
            } else {
                emotionIndicator.classList.add('hidden');
                emotionIndicator.classList.remove('flex');
            }
        }
    }

    /**
     * Start recording
     * @public
     */
    async startRecording() {
        try {
            // Validate emotion selected
            if (!this.selectedEmotionId) {
                this.showError('Seleziona prima un\'emozione');
                return;
            }

            // Request microphone access
            // ENTERPRISE V11.8: Disabled noiseSuppression completely
            // Chrome/Safari noiseSuppression is too aggressive on some devices - users reported
            // "even shouting only gets fragments of words" when enabled
            // autoGainControl helps normalize volume without cutting audio
            this.audioStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    channelCount: 1,           // Mono
                    sampleRate: 16000,         // 16kHz (voice optimized)
                    echoCancellation: true,    // Still useful to prevent feedback
                    noiseSuppression: false,   // DISABLED: Too aggressive, cuts speech
                    autoGainControl: true      // Helps normalize volume
                }
            });

            // Setup Web Audio API for waveform visualization
            this.setupAudioAnalyser();

            // Setup MediaRecorder
            const mimeType = this.getSupportedMimeType();
            this.mediaRecorder = new MediaRecorder(this.audioStream, {
                mimeType: mimeType,
                audioBitsPerSecond: 48000  // 48 kbps
            });

            this.audioChunks = [];

            // MediaRecorder event handlers
            this.mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.audioChunks.push(event.data);
                }
            };

            this.mediaRecorder.onstop = () => {
                this.handleRecordingComplete();
            };

            this.mediaRecorder.onerror = (error) => {
                console.error('MediaRecorder error:', error);
                this.showError('Errore durante la registrazione');
                this.stopRecording();
            };

            // Start recording
            this.mediaRecorder.start(100); // Collect data every 100ms

            this.isRecording = true;
            this.recordingStartTime = Date.now();

            // Start timer
            this.startTimer();

            // Start waveform animation
            this.startWaveformAnimation();

            // Update UI
            this.updateRecordingUI();

            console.log('AudioRecorder: Recording started');

        } catch (error) {
            console.error('AudioRecorder: Failed to start recording', error);

            if (error.name === 'NotAllowedError') {
                this.showError('Permesso microfono negato. Abilita il microfono nelle impostazioni del browser.');
            } else if (error.name === 'NotFoundError') {
                this.showError('Microfono non trovato. Collega un microfono e riprova.');
            } else {
                this.showError('Impossibile avviare la registrazione');
            }

            if (this.config.onError) {
                this.config.onError(error);
            }
        }
    }

    /**
     * Get supported MIME type for MediaRecorder
     * @private
     * @returns {string} Supported MIME type
     */
    getSupportedMimeType() {
        const types = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus'
        ];

        for (const type of types) {
            if (MediaRecorder.isTypeSupported(type)) {
                console.log('AudioRecorder: Using MIME type', type);
                return type;
            }
        }

        console.warn('AudioRecorder: No preferred MIME type supported, using default');
        return '';
    }

    /**
     * Setup Web Audio API analyser for waveform
     * @private
     */
    setupAudioAnalyser() {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        this.audioContext = new AudioContextClass();
        this.analyser = this.audioContext.createAnalyser();

        const source = this.audioContext.createMediaStreamSource(this.audioStream);
        source.connect(this.analyser);

        this.analyser.fftSize = 256;
        this.analyser.smoothingTimeConstant = 0.8;
    }

    /**
     * Start waveform animation
     * @private
     */
    startWaveformAnimation() {
        if (!this.container) return;
        const canvas = this.container.querySelector('#waveformCanvas');
        if (!canvas) return;

        const canvasCtx = canvas.getContext('2d');
        const bufferLength = this.analyser.frequencyBinCount;
        const dataArray = new Uint8Array(bufferLength);

        const draw = () => {
            if (!this.isRecording || this.isPaused) {
                return;
            }

            this.animationFrameId = requestAnimationFrame(draw);

            this.analyser.getByteTimeDomainData(dataArray);

            // Clear canvas
            canvasCtx.fillStyle = 'rgb(17, 24, 39)'; // bg-gray-900
            canvasCtx.fillRect(0, 0, canvas.width, canvas.height);

            // Draw waveform
            canvasCtx.lineWidth = 2;
            canvasCtx.strokeStyle = 'rgb(168, 85, 247)'; // purple-500
            canvasCtx.beginPath();

            const sliceWidth = canvas.width / bufferLength;
            let x = 0;

            for (let i = 0; i < bufferLength; i++) {
                const v = dataArray[i] / 128.0;
                const y = (v * canvas.height) / 2;

                if (i === 0) {
                    canvasCtx.moveTo(x, y);
                } else {
                    canvasCtx.lineTo(x, y);
                }

                x += sliceWidth;
            }

            canvasCtx.lineTo(canvas.width, canvas.height / 2);
            canvasCtx.stroke();
        };

        draw();
    }

    /**
     * Start recording timer
     * @private
     */
    startTimer() {
        this.timerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - this.recordingStartTime) / 1000);
            this.recordingDuration = elapsed;

            // Update timer display
            this.updateTimerDisplay(elapsed);

            // Auto-stop at max duration
            if (elapsed >= this.config.maxDuration) {
                this.stopRecording();
            }
        }, 100); // Update every 100ms for smooth display
    }

    /**
     * Update timer display
     * @private
     * @param {number} seconds Elapsed seconds
     */
    updateTimerDisplay(seconds) {
        if (!this.container) return;
        const timerElement = this.container.querySelector('#recordingTimer');
        if (!timerElement) return;

        const remaining = this.config.maxDuration - seconds;
        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;

        timerElement.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;

        // Warning color at 5s remaining
        if (remaining <= 5) {
            timerElement.classList.add('text-red-400');
            timerElement.classList.remove('text-purple-400');
        }
    }

    /**
     * Stop recording
     * @public
     */
    stopRecording() {
        if (!this.isRecording) return;

        try {
            // Stop MediaRecorder
            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                this.mediaRecorder.stop();
            }

            // Stop all audio tracks
            if (this.audioStream) {
                this.audioStream.getTracks().forEach(track => track.stop());
            }

            // Stop timer
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }

            // Stop waveform animation
            if (this.animationFrameId) {
                cancelAnimationFrame(this.animationFrameId);
                this.animationFrameId = null;
            }

            // Close audio context
            if (this.audioContext && this.audioContext.state !== 'closed') {
                this.audioContext.close();
            }

            this.isRecording = false;
            this.isPaused = false;

            console.log('AudioRecorder: Recording stopped');

        } catch (error) {
            console.error('AudioRecorder: Error stopping recording', error);
        }
    }

    /**
     * Handle recording complete
     * ENTERPRISE: Async to support WebM duration metadata fix
     * @private
     */
    async handleRecordingComplete() {
        try {
            // Create blob from chunks
            const mimeType = this.mediaRecorder.mimeType || 'audio/webm';
            let blob = new Blob(this.audioChunks, { type: mimeType });

            // ENTERPRISE GALAXY: Fix WebM duration metadata (Chrome MediaRecorder bug)
            // Chrome creates WebM files without duration in EBML header, causing:
            // - audio.duration returning Infinity or NaN
            // - Seeking not working properly in some browsers
            // - Safari/Chrome refusing to play or playing only partial content
            if (window.WebMDurationFix && this.recordingDuration > 0) {
                try {
                    console.log('[AudioRecorder] Fixing WebM duration metadata...', {
                        originalSize: blob.size,
                        duration: this.recordingDuration
                    });

                    blob = await WebMDurationFix.fix(blob, this.recordingDuration);

                    console.log('[AudioRecorder] WebM duration fix applied', {
                        fixedSize: blob.size,
                        sizeDelta: blob.size - this.audioChunks.reduce((sum, c) => sum + c.size, 0)
                    });
                } catch (fixError) {
                    // Graceful degradation: use original blob if fix fails
                    console.warn('[AudioRecorder] WebM duration fix failed, using original:', fixError);
                }
            }

            this.recordedBlob = blob;

            console.log('AudioRecorder: Recording complete', {
                size: this.recordedBlob.size,
                duration: this.recordingDuration,
                mimeType: mimeType
            });

            // Validate file size
            if (this.recordedBlob.size > this.config.maxFileSize) {
                this.showError(`File troppo grande (${Math.round(this.recordedBlob.size / 1024)}KB). Max ${Math.round(this.config.maxFileSize / 1024)}KB`);
                this.resetRecording();
                return;
            }

            this.hasRecording = true;

            // Update UI to show preview controls
            this.updatePreviewUI();

        } catch (error) {
            console.error('AudioRecorder: Failed to process recording', error);
            this.showError('Errore durante il salvataggio della registrazione');
        }
    }

    /**
     * Play recorded audio
     * Uses the custom audio player (triggers play/pause button click)
     * @public
     */
    playRecording() {
        if (!this.hasRecording || !this.recordedBlob) {
            console.warn('AudioRecorder: No recording to play');
            return;
        }

        if (!this.container) return;

        // Use the custom audio player button (same as clicking play)
        const playPauseBtn = this.container.querySelector('#audioPlayPauseBtn');
        if (playPauseBtn && this._playPauseHandler) {
            // Trigger play via the custom player handler
            this._playPauseHandler();
        } else if (this.currentAudioElement) {
            // Fallback: direct play on audio element
            this.currentAudioElement.play().catch(err => {
                console.error('AudioRecorder: Playback failed', err);
            });
        }
    }

    /**
     * Reset recording (discard current recording)
     * @public
     */
    resetRecording() {
        // Stop if currently recording
        if (this.isRecording) {
            this.stopRecording();
        }

        // ENTERPRISE V4: Stop audio playback before cleanup
        // Must check audioBlobUrl BEFORE revoking it
        if (this.currentAudioElement) {
            // Only manipulate audio element if it has a valid blob URL source
            if (this.audioBlobUrl) {
                try {
                    this.currentAudioElement.pause();
                    this.currentAudioElement.currentTime = 0;
                    this.currentAudioElement.src = '';
                    this.currentAudioElement.load();
                } catch (e) {
                    // Silently ignore - element may already be in invalid state
                }
            }

            // Remove event listeners
            if (this._timeUpdateHandler) {
                this.currentAudioElement.removeEventListener('timeupdate', this._timeUpdateHandler);
                this._timeUpdateHandler = null;
            }
            if (this._endedHandler) {
                this.currentAudioElement.removeEventListener('ended', this._endedHandler);
                this._endedHandler = null;
            }
            this.currentAudioElement = null;
        }

        // Cleanup blob URL (AFTER audio element manipulation)
        if (this.audioBlobUrl) {
            URL.revokeObjectURL(this.audioBlobUrl);
            this.audioBlobUrl = null;
        }

        // Remove event listeners (custom controls)
        const playPauseBtn = this.container?.querySelector('#audioPlayPauseBtn');
        const progressBar = this.container?.querySelector('#audioProgressBar');
        if (playPauseBtn && this._playPauseHandler) {
            playPauseBtn.removeEventListener('click', this._playPauseHandler);
            this._playPauseHandler = null;
        }
        if (progressBar && this._progressBarHandler) {
            progressBar.removeEventListener('input', this._progressBarHandler);
            this._progressBarHandler = null;
        }

        // Clear recorded data (audio only - keep emotion/metadata for retry)
        this.audioChunks = [];
        this.recordedBlob = null;
        this.recordingDuration = 0;
        this.hasRecording = false;

        // Reset timer display to initial state
        const timerElement = this.container?.querySelector('#recordingTimer');
        if (timerElement) {
            timerElement.textContent = `0:${this.config.maxDuration.toString().padStart(2, '0')}`;
            timerElement.classList.remove('text-red-400');
            timerElement.classList.add('text-purple-400');
        }

        // Reset UI to idle state (ready to record again)
        this.updateRecordingUI();

        console.log('AudioRecorder: Recording discarded (emotion/metadata preserved)');
    }

    /**
     * Full reset - called when modal is closed completely
     * Resets everything including emotion selection
     * @public
     */
    fullReset() {
        // First reset the recording
        this.resetRecording();

        // Then reset emotion state
        this.selectedEmotionId = null;
        this.selectedEmotionName = null;
        this.selectedEmotionEmoji = null;

        // Update emotion indicator (hide it)
        this.updateEmotionUI();

        console.log('AudioRecorder: Full reset (modal closed)');
    }

    /**
     * Upload recording to server
     * @public
     */
    async uploadRecording() {
        if (!this.hasRecording || !this.recordedBlob) {
            console.warn('AudioRecorder: No recording to upload');
            return;
        }

        if (!this.selectedEmotionId) {
            this.showError('Seleziona un\'emozione prima di caricare');
            return;
        }

        try {
            // Show uploading state
            this.showUploading();

            // Get metadata from parent component (if callback provided)
            let metadata = {};
            if (this.config.onBeforeUpload && typeof this.config.onBeforeUpload === 'function') {
                metadata = this.config.onBeforeUpload() || {};
                console.log('AudioRecorder: Metadata collected', metadata);
            }

            // Prepare FormData
            const formData = new FormData();
            formData.append('audio_file', this.recordedBlob, `audio_${Date.now()}.webm`);
            formData.append('emotion_id', this.selectedEmotionId);
            formData.append('duration', this.recordingDuration);

            // Add metadata if present
            if (metadata.title) {
                formData.append('title', metadata.title);
            }
            if (metadata.description) {
                formData.append('description', metadata.description);
            }
            if (metadata.hashtags && metadata.hashtags.length > 0) {
                formData.append('hashtags', JSON.stringify(metadata.hashtags));
            }
            if (metadata.photo) {
                formData.append('photo', metadata.photo, 'cover_photo.jpg');
            }
            // ENTERPRISE FIX: Always append visibility (default to 'public' if not provided)
            // Visibility options: 'private', 'friends', 'friends_of_friends', 'public'
            formData.append('visibility', metadata.visibility || 'public');

            // ENTERPRISE V4 (2025-11-30): Append mentioned_users for @mention tagging
            if (metadata.mentioned_users && metadata.mentioned_users.length > 0) {
                formData.append('mentioned_users', JSON.stringify(metadata.mentioned_users));
            }

            // ENTERPRISE V8.2: CSRF token from single source of truth (Need2Talk.CSRF module)
            // The csrf.js wrapper auto-injects X-CSRF-TOKEN header on all POST requests
            const csrfToken = Need2Talk.CSRF?.getToken() || '';

            if (!csrfToken) {
                console.error('AudioRecorder: CSRF token not found - upload will fail');
                throw new Error('CSRF token mancante. Ricarica la pagina e riprova.');
            }

            // ENTERPRISE FIX: Also send CSRF in POST data as fallback (defense in depth)
            formData.append('_csrf_token', csrfToken);

            // ENTERPRISE V8.2: csrf.js wrapper auto-injects X-CSRF-TOKEN header
            // X-Requested-With needed for XMLHttpRequest detection
            const response = await fetch(this.config.uploadEndpoint, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: formData
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Upload failed');
            }

            // Upload successful - no debug logging in production

            // Trigger callback
            this.config.onRecordingComplete(result);

            // Reset recorder
            this.resetRecording();

            // Show success
            this.showSuccess('Audio caricato con successo!');

        } catch (error) {
            console.error('AudioRecorder: Upload failed', error);
            this.showError(error.message || 'Errore durante il caricamento');

            if (this.config.onError) {
                this.config.onError(error);
            }
        }
    }

    /**
     * Bind event handlers
     * @private
     */
    bindEvents() {
        if (!this.container) return;

        // Event delegation for all buttons
        this.container.addEventListener('click', (e) => {
            const startBtn = e.target.closest('#startRecordingBtn');
            const stopBtn = e.target.closest('#stopRecordingBtn');
            const playBtn = e.target.closest('#playRecordingBtn');
            const discardBtn = e.target.closest('#discardRecordingBtn');
            const uploadBtn = e.target.closest('#uploadRecordingBtn');

            if (startBtn) this.startRecording();
            if (stopBtn) this.stopRecording();
            if (playBtn) this.playRecording();
            if (discardBtn) this.resetRecording();
            if (uploadBtn) this.uploadRecording();
        });
    }

    /**
     * Update UI for recording state
     * @private
     */
    updateRecordingUI() {
        if (!this.container) return;

        const idleState = this.container.querySelector('#recorderIdleState');
        const recordingState = this.container.querySelector('#recorderRecordingState');
        const previewState = this.container.querySelector('#recorderPreviewState');

        if (!this.isRecording && !this.hasRecording) {
            // Idle state
            idleState?.classList.remove('hidden');
            recordingState?.classList.add('hidden');
            previewState?.classList.add('hidden');
        } else if (this.isRecording) {
            // Recording state
            idleState?.classList.add('hidden');
            recordingState?.classList.remove('hidden');
            previewState?.classList.add('hidden');
        }
    }

    /**
     * Update UI for preview state
     * @private
     */
    updatePreviewUI() {
        if (!this.container) return;
        const idleState = this.container.querySelector('#recorderIdleState');
        const recordingState = this.container.querySelector('#recorderRecordingState');
        const previewState = this.container.querySelector('#recorderPreviewState');

        idleState?.classList.add('hidden');
        recordingState?.classList.add('hidden');
        previewState?.classList.remove('hidden');

        // ENTERPRISE CUSTOM AUDIO PLAYER (WebM blob incompatible with native controls)
        const audioElement = this.container.querySelector('#previewAudio');
        if (!audioElement || !this.recordedBlob) return;

        // Create blob URL only if not already set
        if (!this.audioBlobUrl) {
            this.audioBlobUrl = URL.createObjectURL(this.recordedBlob);
        }
        audioElement.src = this.audioBlobUrl;
        this.currentAudioElement = audioElement;

        // Get custom player controls
        const playPauseBtn = this.container.querySelector('#audioPlayPauseBtn');
        const playIcon = this.container.querySelector('#audioPlayIcon');
        const pauseIcon = this.container.querySelector('#audioPauseIcon');
        const progressBar = this.container.querySelector('#audioProgressBar');
        const currentTimeEl = this.container.querySelector('#audioCurrentTime');
        const totalTimeEl = this.container.querySelector('#audioTotalTime');
        const durationEl = this.container.querySelector('#recordingDuration');

        if (!playPauseBtn || !progressBar) return;

        // Format time helper (seconds → MM:SS)
        const formatTime = (seconds) => {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        };

        // Initialize total time display (use manually tracked duration)
        const totalDuration = this.recordingDuration;
        if (totalTimeEl) totalTimeEl.textContent = formatTime(totalDuration);
        if (currentTimeEl) currentTimeEl.textContent = '0:00';
        if (durationEl) durationEl.textContent = `${totalDuration}s`;

        // Remove previous listeners (prevent duplicates)
        if (this._playPauseHandler) {
            playPauseBtn.removeEventListener('click', this._playPauseHandler);
        }
        if (this._timeUpdateHandler) {
            audioElement.removeEventListener('timeupdate', this._timeUpdateHandler);
        }
        if (this._endedHandler) {
            audioElement.removeEventListener('ended', this._endedHandler);
        }
        if (this._progressBarHandler) {
            progressBar.removeEventListener('input', this._progressBarHandler);
        }

        // Play/Pause button handler
        this._playPauseHandler = () => {
            if (audioElement.paused) {
                audioElement.play().catch(err => {
                    console.error('AudioRecorder: Playback failed', err);
                });
                playIcon?.classList.add('hidden');
                pauseIcon?.classList.remove('hidden');
            } else {
                audioElement.pause();
                playIcon?.classList.remove('hidden');
                pauseIcon?.classList.add('hidden');
            }
        };

        // Timeupdate handler (update progress bar during playback)
        this._timeUpdateHandler = () => {
            const currentTime = audioElement.currentTime;
            const duration = totalDuration; // Use manually tracked duration

            // Update progress bar (0-100%)
            const progress = (currentTime / duration) * 100;
            if (progressBar) {
                progressBar.value = progress;
                // Update CSS variable for visual gradient (enterprise styling)
                progressBar.style.setProperty('--progress', `${progress}%`);
            }

            // Update time displays
            if (currentTimeEl) currentTimeEl.textContent = formatTime(currentTime);
            if (durationEl) {
                durationEl.textContent = audioElement.paused
                    ? `${Math.floor(duration)}s`
                    : `${Math.floor(currentTime)}s / ${Math.floor(duration)}s`;
            }
        };

        // Ended handler (reset to play button)
        this._endedHandler = () => {
            playIcon?.classList.remove('hidden');
            pauseIcon?.classList.add('hidden');
            if (progressBar) {
                progressBar.value = 0;
                progressBar.style.setProperty('--progress', '0%');
            }
            if (currentTimeEl) currentTimeEl.textContent = '0:00';
            if (durationEl) durationEl.textContent = `${totalDuration}s`;
        };

        // Progress bar drag handler (seeking)
        this._progressBarHandler = (e) => {
            const duration = totalDuration;
            const seekTime = (e.target.value / 100) * duration;
            audioElement.currentTime = seekTime;
        };

        // Attach listeners
        playPauseBtn.addEventListener('click', this._playPauseHandler);
        audioElement.addEventListener('timeupdate', this._timeUpdateHandler);
        audioElement.addEventListener('ended', this._endedHandler);
        progressBar.addEventListener('input', this._progressBarHandler);

        console.log(`[AudioRecorder] Custom audio player initialized (${totalDuration}s)`);

        // Update file size display
        const sizeElement = this.container.querySelector('#recordingSize');
        if (sizeElement) {
            sizeElement.textContent = `${Math.round(this.recordedBlob.size / 1024)}KB`;
        }
    }

    /**
     * Show uploading state
     * @private
     */
    showUploading() {
        if (!this.container) return;
        const uploadBtn = this.container.querySelector('#uploadRecordingBtn');
        if (uploadBtn) {
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = `
                <svg class="animate-spin h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Caricamento...
            `;
        }
    }

    /**
     * Show error message
     * @private
     * @param {string} message Error message
     */
    showError(message) {
        if (!this.container) return;
        const errorElement = this.container.querySelector('#recorderError');
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.remove('hidden');

            // Auto-hide after 5 seconds
            setTimeout(() => {
                if (errorElement) errorElement.classList.add('hidden');
            }, 5000);
        }
    }

    /**
     * Show success message
     * @private
     * @param {string} message Success message
     */
    showSuccess(message) {
        if (!this.container) return;
        const successElement = this.container.querySelector('#recorderSuccess');
        if (successElement) {
            successElement.textContent = message;
            successElement.classList.remove('hidden');

            // Auto-hide after 3 seconds
            setTimeout(() => {
                if (successElement) successElement.classList.add('hidden');
            }, 3000);
        }
    }

    /**
     * Render recorder UI
     * @private
     */
    render() {
        if (!this.container) return;

        this.container.innerHTML = `
            <!-- Audio Recorder - Enterprise Galaxy Design -->
            <div class="bg-gray-800/50 rounded-xl p-6 border-2 border-purple-500/30">

                <!-- Emotion Indicator -->
                <div id="emotionIndicator" class="hidden items-center justify-center mb-4 p-3 bg-gradient-to-r from-purple-500/20 to-pink-500/20 rounded-lg border-2 border-purple-500/60 shadow-lg shadow-purple-500/20">
                    <svg class="w-5 h-5 text-purple-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="emotion-indicator-text text-purple-300 font-semibold text-lg">Emozione selezionata</span>
                </div>

                <!-- Error Message -->
                <div id="recorderError" class="hidden mb-4 p-3 bg-red-500/20 border border-red-500/40 rounded-lg text-red-400 text-sm"></div>

                <!-- Success Message -->
                <div id="recorderSuccess" class="hidden mb-4 p-3 bg-green-500/20 border border-green-500/40 rounded-lg text-green-400 text-sm"></div>

                <!-- Idle State (Before Recording) -->
                <div id="recorderIdleState">
                    <div class="text-center py-8">
                        <div class="mb-6">
                            <svg class="w-20 h-20 mx-auto text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Pronto per registrare</h3>
                        <p class="text-gray-400 mb-6">Massimo ${this.config.maxDuration} secondi</p>
                        <button id="startRecordingBtn" class="px-8 py-3 bg-purple-600 hover:bg-purple-700 rounded-lg text-white font-semibold transition-colors duration-200 flex items-center justify-center mx-auto">
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <circle cx="10" cy="10" r="8"></circle>
                            </svg>
                            Inizia Registrazione
                        </button>
                    </div>
                </div>

                <!-- Recording State -->
                <div id="recorderRecordingState" class="hidden">
                    <div class="text-center mb-6">
                        <!-- Timer -->
                        <div class="mb-4">
                            <div class="text-5xl font-bold text-purple-400" id="recordingTimer">0:30</div>
                            <div class="text-sm text-gray-400 mt-1">Tempo rimanente</div>
                        </div>

                        <!-- Waveform Visualization -->
                        <div class="mb-6 bg-gray-900 rounded-lg p-4">
                            <canvas id="waveformCanvas" width="600" height="100" class="w-full h-auto"></canvas>
                        </div>

                        <!-- Recording Indicator -->
                        <div class="flex items-center justify-center mb-6">
                            <div class="w-3 h-3 bg-red-500 rounded-full animate-pulse mr-2"></div>
                            <span class="text-red-400 font-medium">Registrazione in corso...</span>
                        </div>

                        <!-- Stop Button -->
                        <button id="stopRecordingBtn" class="px-8 py-3 bg-red-600 hover:bg-red-700 rounded-lg text-white font-semibold transition-colors duration-200 flex items-center justify-center mx-auto">
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <rect x="6" y="6" width="8" height="8"></rect>
                            </svg>
                            Ferma Registrazione
                        </button>
                    </div>
                </div>

                <!-- Preview State (After Recording) -->
                <div id="recorderPreviewState" class="hidden">
                    <div class="text-center mb-6">
                        <h3 class="text-xl font-bold text-white mb-4">Anteprima Registrazione</h3>

                        <!-- Custom Audio Player (ENTERPRISE: No native controls - WebM blob incompatible) -->
                        <div class="mb-6 bg-gray-900 rounded-lg p-6">
                            <!-- Hidden audio element (no controls) -->
                            <audio id="previewAudio" preload="metadata" class="hidden">
                                Il tuo browser non supporta la riproduzione audio.
                            </audio>

                            <!-- Custom Play/Pause Button -->
                            <div class="flex items-center justify-center mb-4">
                                <button id="audioPlayPauseBtn"
                                        class="w-16 h-16 bg-purple-600 hover:bg-purple-700 rounded-full flex items-center justify-center transition-all duration-200 shadow-lg hover:shadow-purple-500/50"
                                        aria-label="Play/Pause audio">
                                    <!-- Play Icon (default) -->
                                    <svg id="audioPlayIcon" class="w-8 h-8 text-white ml-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                    </svg>
                                    <!-- Pause Icon (hidden) -->
                                    <svg id="audioPauseIcon" class="w-8 h-8 text-white hidden" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M5.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75A.75.75 0 007.25 3h-1.5zM12.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75a.75.75 0 00-.75-.75h-1.5z"/>
                                    </svg>
                                </button>
                            </div>

                            <!-- Custom Progress Bar (ENTERPRISE: Cross-browser styled slider) -->
                            <div class="mb-2">
                                <style>
                                    /* Range slider custom styling (cross-browser) */
                                    #audioProgressBar {
                                        -webkit-appearance: none;
                                        appearance: none;
                                        width: 100%;
                                        height: 6px;
                                        background: linear-gradient(to right, #9333ea 0%, #9333ea var(--progress, 0%), #374151 var(--progress, 0%), #374151 100%);
                                        border-radius: 9999px;
                                        outline: none;
                                        cursor: pointer;
                                    }

                                    /* Chrome/Safari thumb */
                                    #audioProgressBar::-webkit-slider-thumb {
                                        -webkit-appearance: none;
                                        appearance: none;
                                        width: 16px;
                                        height: 16px;
                                        border-radius: 50%;
                                        background: #fff;
                                        cursor: pointer;
                                        box-shadow: 0 0 8px rgba(147, 51, 234, 0.5);
                                    }

                                    /* Firefox thumb */
                                    #audioProgressBar::-moz-range-thumb {
                                        width: 16px;
                                        height: 16px;
                                        border-radius: 50%;
                                        background: #fff;
                                        cursor: pointer;
                                        border: none;
                                        box-shadow: 0 0 8px rgba(147, 51, 234, 0.5);
                                    }

                                    /* Firefox track */
                                    #audioProgressBar::-moz-range-track {
                                        background: transparent;
                                        border: none;
                                    }
                                </style>
                                <input type="range"
                                       id="audioProgressBar"
                                       min="0"
                                       max="100"
                                       value="0"
                                       step="0.1"
                                       aria-label="Posizione audio">
                            </div>

                            <!-- Time Display -->
                            <div class="flex justify-between text-sm text-gray-400">
                                <span id="audioCurrentTime">0:00</span>
                                <span id="audioTotalTime">0:00</span>
                            </div>
                        </div>

                        <!-- Recording Info -->
                        <div class="grid grid-cols-2 gap-4 mb-6">
                            <div class="bg-gray-900 rounded-lg p-3">
                                <div class="text-gray-400 text-sm mb-1">Durata</div>
                                <div class="text-white font-bold" id="recordingDuration">0s</div>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-3">
                                <div class="text-gray-400 text-sm mb-1">Dimensione</div>
                                <div class="text-white font-bold" id="recordingSize">0KB</div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex gap-3 justify-center">
                            <button id="discardRecordingBtn" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg text-white font-semibold transition-colors duration-200">
                                Scarta
                            </button>
                            <button id="playRecordingBtn" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg text-white font-semibold transition-colors duration-200">
                                Riascolta
                            </button>
                            <button id="uploadRecordingBtn" class="px-6 py-3 bg-purple-600 hover:bg-purple-700 rounded-lg text-white font-semibold transition-colors duration-200 flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                </svg>
                                Carica
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render unsupported browser message
     * @private
     */
    renderUnsupported() {
        if (!this.container) return;

        this.container.innerHTML = `
            <div class="bg-red-500/20 border border-red-500/40 rounded-xl p-6 text-center">
                <svg class="w-16 h-16 mx-auto text-red-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <h3 class="text-xl font-bold text-white mb-2">Browser non supportato</h3>
                <p class="text-gray-300 mb-4">
                    Il tuo browser non supporta la registrazione audio.<br>
                    Usa Chrome, Firefox, Safari o Edge (versione recente).
                </p>
            </div>
        `;
    }

    /**
     * Destroy component and cleanup resources
     * @public
     */
    destroy() {
        // Stop recording if active
        if (this.isRecording) {
            this.stopRecording();
        }

        // Clear timer
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
        }

        // Clear animation frame
        if (this.animationFrameId) {
            cancelAnimationFrame(this.animationFrameId);
        }

        // Close audio context
        if (this.audioContext && this.audioContext.state !== 'closed') {
            this.audioContext.close();
        }

        // Cleanup blob URL
        if (this.audioBlobUrl) {
            URL.revokeObjectURL(this.audioBlobUrl);
            this.audioBlobUrl = null;
        }

        // Remove event listeners
        if (this.currentAudioElement) {
            if (this._timeUpdateHandler) {
                this.currentAudioElement.removeEventListener('timeupdate', this._timeUpdateHandler);
            }
            if (this._endedHandler) {
                this.currentAudioElement.removeEventListener('ended', this._endedHandler);
            }
        }

        // Clear container
        if (this.container) {
            this.container.innerHTML = '';
        }

        // Clear state
        this.audioChunks = [];
        this.recordedBlob = null;
        this.currentAudioElement = null;
        this._timeUpdateHandler = null;
        this._endedHandler = null;

        console.log('AudioRecorder: Destroyed');
    }
}

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AudioRecorder;
}
