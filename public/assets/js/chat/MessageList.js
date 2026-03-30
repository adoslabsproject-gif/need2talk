/**
 * MessageList.js - Enterprise Virtual Scroll Message List
 *
 * High-performance message list with virtual scrolling.
 * Handles 10,000+ messages smoothly by only rendering visible items.
 *
 * Features:
 * - Virtual scrolling (renders only visible messages)
 * - Infinite scroll (load older messages on scroll up)
 * - Auto-scroll to bottom on new messages
 * - Message grouping by date
 * - Read receipts visualization
 * - System messages support
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 * @version 1.0.0
 */

class MessageList {
    static CONFIG = {
        ITEM_HEIGHT: 72,            // Average message height (px) - used for spacer estimation
        BUFFER_SIZE: 15,            // Extra items to render above/below viewport
        SCROLL_THRESHOLD: 100,      // Distance from bottom to show "new messages" button
        DATE_HEADER_HEIGHT: 40,     // Height of date separator
        LOAD_MORE_THRESHOLD: 200,   // Distance from top to trigger load more
        // ENTERPRISE V10.32: Increased threshold to 200 messages
        // Virtual scroll with fixed ITEM_HEIGHT causes scroll instability
        // Modern browsers handle 200 DOM elements efficiently
        // Only enable virtual scroll for very large lists (200+)
        VIRTUAL_SCROLL_THRESHOLD: 200,
    };

    #container = null;
    #scrollContainer = null;
    #messagesContainer = null;
    #messages = [];
    #visibleRange = { start: -1, end: -1 }; // ENTERPRISE V11.8: Use -1 to ensure first message triggers render
    #isLoadingMore = false;
    #hasMore = true;
    #autoScroll = true;
    #chatId = null;              // roomId or conversationUuid
    #chatType = 'room';          // 'room' or 'dm'
    #currentUserUuid = null;
    #onLoadMore = null;
    #resizeObserver = null;
    #scrollRAF = null;
    // ENTERPRISE V3.1: Audio player state
    #currentAudio = null;        // Currently playing Audio element
    #currentAudioId = null;      // ID of currently playing audio message

    /**
     * Create message list
     * @param {HTMLElement|string} container
     * @param {Object} options
     */
    constructor(container, options = {}) {
        this.#container = typeof container === 'string'
            ? document.querySelector(container)
            : container;

        if (!this.#container) {
            throw new Error('MessageList: Container not found');
        }

        this.#chatType = options.chatType || 'room';
        this.#currentUserUuid = options.currentUserUuid || window.APP_USER?.uuid;
        this.#onLoadMore = options.onLoadMore || null;

        this.#init();
    }

    /**
     * Initialize component
     */
    #init() {
        this.#render();
        this.#setupEventListeners();
        this.#setupResizeObserver();
    }

    /**
     * Render component structure
     */
    #render() {
        this.#container.innerHTML = `
            <div class="message-list-wrapper">
                <div class="message-list-scroll" role="log" aria-live="polite" aria-label="Messaggi chat">
                    <div class="message-list-spacer-top"></div>
                    <div class="message-list-content"></div>
                    <div class="message-list-spacer-bottom"></div>
                </div>

                <button class="message-list-scroll-btn hidden" aria-label="Scorri in basso">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                    <span class="new-count"></span>
                </button>

                <div class="message-list-loading hidden">
                    <div class="loading-spinner"></div>
                    <span>Caricamento messaggi...</span>
                </div>

                <!-- Skeleton messages while loading -->
                <div class="message-list-skeleton hidden">
                    <div class="message-skeleton">
                        <div class="skeleton-avatar"></div>
                        <div class="skeleton-content">
                            <div class="skeleton-header"></div>
                            <div class="skeleton-bubble"></div>
                        </div>
                    </div>
                    <div class="message-skeleton own">
                        <div class="skeleton-content">
                            <div class="skeleton-header"></div>
                            <div class="skeleton-bubble short"></div>
                        </div>
                        <div class="skeleton-avatar"></div>
                    </div>
                    <div class="message-skeleton">
                        <div class="skeleton-avatar"></div>
                        <div class="skeleton-content">
                            <div class="skeleton-header"></div>
                            <div class="skeleton-bubble long"></div>
                        </div>
                    </div>
                </div>

                <div class="message-list-empty hidden">
                    <div class="empty-icon">💬</div>
                    <p>Nessun messaggio ancora</p>
                    <p class="hint">Inizia la conversazione!</p>
                </div>
            </div>

            <style>
                .message-list-wrapper {
                    position: relative;
                    height: 100%;
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                }

                .message-list-scroll {
                    flex: 1;
                    overflow-y: auto;
                    overflow-x: hidden;
                    overscroll-behavior: contain;
                    scroll-behavior: auto;
                    position: relative;
                }

                .message-list-content {
                    position: relative;
                    min-height: 100%;
                }

                .message-list-spacer-top,
                .message-list-spacer-bottom {
                    width: 100%;
                    pointer-events: none;
                }

                /* Message item - ENTERPRISE v9.9: Using n2t- prefix for namespace isolation */
                .n2t-message {
                    display: flex;
                    padding: 0.5rem 1rem;
                    gap: 0.75rem;
                    animation: n2tMessageSlideIn 0.2s ease-out;
                }

                @keyframes n2tMessageSlideIn {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                .n2t-message.own {
                    flex-direction: row-reverse;
                }

                .n2t-message-avatar {
                    width: 36px;
                    height: 36px;
                    border-radius: 50%;
                    background: var(--bg-tertiary, #374151);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 1rem;
                    flex-shrink: 0;
                    color: #9ca3af;
                }

                .n2t-message-avatar.n2t-avatar-letter {
                    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
                    color: white;
                    font-weight: 600;
                }

                img.n2t-message-avatar {
                    width: 36px;
                    height: 36px;
                    border-radius: 50%;
                    object-fit: cover;
                }

                .n2t-message-content {
                    max-width: 70%;
                    display: flex;
                    flex-direction: column;
                    gap: 0.25rem;
                }

                .n2t-message.own .n2t-message-content {
                    align-items: flex-end;
                }

                .n2t-message-header {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    font-size: 0.75rem;
                }

                .n2t-message-username {
                    font-weight: 600;
                    color: #e5e7eb;
                }

                .n2t-message-time {
                    color: #9ca3af;
                }

                .n2t-message-bubble {
                    background: #374151;
                    padding: 0.625rem 0.875rem;
                    border-radius: 1rem;
                    border-top-left-radius: 0.25rem;
                    word-wrap: break-word;
                    overflow-wrap: break-word;
                    max-width: 100%;
                    color: #e5e7eb;
                }

                .n2t-message.own .n2t-message-bubble {
                    background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 100%);
                    color: white;
                    border-top-left-radius: 1rem;
                    border-top-right-radius: 0.25rem;
                }

                .n2t-message-text {
                    font-size: 0.9375rem;
                    line-height: 1.4;
                    white-space: pre-wrap;
                }

                /* Message status - ENTERPRISE v9.9: Using n2t- prefix */
                .n2t-message-status {
                    display: flex;
                    align-items: center;
                    gap: 0.25rem;
                    font-size: 0.6875rem;
                    color: #9ca3af;
                }

                .n2t-message.own .n2t-message-status {
                    color: rgba(255,255,255,0.7);
                }

                .n2t-message-status svg {
                    width: 14px;
                    height: 14px;
                }

                .n2t-message-status.read svg {
                    color: #22c55e;
                }

                /* System message - ENTERPRISE v9.9: Using n2t- prefix */
                .n2t-message.system {
                    justify-content: center;
                    padding: 0.25rem 1rem;
                }

                .n2t-message.system .n2t-message-bubble {
                    background: transparent;
                    color: #9ca3af;
                    font-size: 0.8125rem;
                    text-align: center;
                    max-width: 80%;
                    padding: 0.375rem 0.75rem;
                }

                /* Date separator */
                .date-separator {
                    display: flex;
                    align-items: center;
                    gap: 1rem;
                    padding: 0.75rem 1rem;
                    color: var(--text-tertiary, #999);
                    font-size: 0.75rem;
                    font-weight: 500;
                }

                .date-separator::before,
                .date-separator::after {
                    content: '';
                    flex: 1;
                    height: 1px;
                    background: var(--border-color, #e0e0e0);
                }

                /* Scroll button */
                .message-list-scroll-btn {
                    position: absolute;
                    bottom: 1rem;
                    right: 1rem;
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    background: var(--bg-primary, #fff);
                    border: 1px solid var(--border-color, #e0e0e0);
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: var(--text-secondary, #666);
                    transition: all 0.2s ease;
                    z-index: 10;
                }

                .message-list-scroll-btn:hover {
                    background: var(--bg-secondary, #f5f5f5);
                }

                .message-list-scroll-btn.hidden {
                    display: none;
                }

                .message-list-scroll-btn .new-count {
                    position: absolute;
                    top: -4px;
                    right: -4px;
                    background: var(--danger, #ef4444);
                    color: white;
                    font-size: 0.6875rem;
                    font-weight: 600;
                    min-width: 18px;
                    height: 18px;
                    border-radius: 9px;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    padding: 0 4px;
                }

                .message-list-scroll-btn .new-count.visible {
                    display: flex;
                }

                /* Loading */
                .message-list-loading {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    padding: 1rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 0.5rem;
                    background: linear-gradient(to bottom, var(--bg-primary, #fff) 0%, transparent 100%);
                    color: var(--text-secondary, #666);
                    font-size: 0.875rem;
                    z-index: 5;
                }

                .message-list-loading.hidden {
                    display: none;
                }

                .loading-spinner {
                    width: 20px;
                    height: 20px;
                    border: 2px solid var(--border-color, #e0e0e0);
                    border-top-color: var(--primary, #3b82f6);
                    border-radius: 50%;
                    animation: spin 0.8s linear infinite;
                }

                @keyframes spin {
                    to { transform: rotate(360deg); }
                }

                /* Empty state */
                .message-list-empty {
                    position: absolute;
                    inset: 0;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    color: var(--text-tertiary, #999);
                    padding: 2rem;
                }

                .message-list-empty.hidden {
                    display: none;
                }

                .message-list-empty .empty-icon {
                    font-size: 3rem;
                    margin-bottom: 1rem;
                    opacity: 0.5;
                }

                .message-list-empty p {
                    margin: 0;
                    font-size: 1rem;
                }

                .message-list-empty .hint {
                    font-size: 0.875rem;
                    margin-top: 0.5rem;
                }

                /* Skeleton messages */
                .message-list-skeleton {
                    padding: 1rem;
                    display: flex;
                    flex-direction: column;
                    gap: 1rem;
                }

                .message-list-skeleton.hidden {
                    display: none;
                }

                .message-skeleton {
                    display: flex;
                    gap: 0.75rem;
                    animation: skeleton-fade 1.5s ease-in-out infinite;
                }

                .message-skeleton.own {
                    flex-direction: row-reverse;
                }

                .skeleton-avatar {
                    width: 36px;
                    height: 36px;
                    border-radius: 50%;
                    background: linear-gradient(90deg, #374151 25%, #4B5563 50%, #374151 75%);
                    background-size: 200% 100%;
                    animation: skeleton-shimmer 1.5s ease-in-out infinite;
                    flex-shrink: 0;
                }

                .skeleton-content {
                    display: flex;
                    flex-direction: column;
                    gap: 0.5rem;
                    max-width: 70%;
                }

                .skeleton-header {
                    width: 100px;
                    height: 12px;
                    border-radius: 4px;
                    background: linear-gradient(90deg, #374151 25%, #4B5563 50%, #374151 75%);
                    background-size: 200% 100%;
                    animation: skeleton-shimmer 1.5s ease-in-out infinite;
                }

                .skeleton-bubble {
                    width: 200px;
                    height: 40px;
                    border-radius: 1rem;
                    background: linear-gradient(90deg, #374151 25%, #4B5563 50%, #374151 75%);
                    background-size: 200% 100%;
                    animation: skeleton-shimmer 1.5s ease-in-out infinite;
                }

                .skeleton-bubble.short {
                    width: 120px;
                }

                .skeleton-bubble.long {
                    width: 280px;
                }

                @keyframes skeleton-shimmer {
                    0% { background-position: 200% 0; }
                    100% { background-position: -200% 0; }
                }

                @keyframes skeleton-fade {
                    0%, 100% { opacity: 0.7; }
                    50% { opacity: 1; }
                }

                /* Encrypted message indicator */
                .encrypted-indicator {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.25rem;
                    font-size: 0.6875rem;
                    color: var(--success, #22c55e);
                    margin-top: 0.25rem;
                }

                .encrypted-indicator svg {
                    width: 12px;
                    height: 12px;
                }

                /* ================================================================
                   ENTERPRISE V3.1: Audio Message Player (DM Chat)
                   ================================================================ */
                .n2t-audio-player {
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                    padding: 0.5rem;
                    min-width: 220px;
                    max-width: 280px;
                }

                .n2t-audio-play-btn {
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    border: none;
                    background: rgba(255,255,255,0.2);
                    color: white;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-shrink: 0;
                    transition: all 0.2s ease;
                }

                .n2t-audio-play-btn:hover {
                    background: rgba(255,255,255,0.3);
                    transform: scale(1.05);
                }

                .n2t-audio-play-btn:active {
                    transform: scale(0.95);
                }

                .n2t-audio-play-btn svg {
                    width: 18px;
                    height: 18px;
                }

                /* Non-own messages have different button style */
                .n2t-message:not(.own) .n2t-audio-play-btn {
                    background: rgba(124, 58, 237, 0.2);
                    color: #8b5cf6;
                }

                .n2t-message:not(.own) .n2t-audio-play-btn:hover {
                    background: rgba(124, 58, 237, 0.3);
                }

                .n2t-audio-waveform {
                    flex: 1;
                    display: flex;
                    flex-direction: column;
                    gap: 0.25rem;
                }

                .n2t-audio-bars {
                    display: flex;
                    align-items: center;
                    gap: 2px;
                    height: 28px;
                    cursor: pointer;
                }

                .n2t-audio-bar {
                    width: 3px;
                    border-radius: 1.5px;
                    background: rgba(255,255,255,0.3);
                    transition: height 0.1s ease, background 0.2s ease;
                }

                .n2t-audio-bar.played {
                    background: rgba(255,255,255,0.9);
                }

                .n2t-message:not(.own) .n2t-audio-bar {
                    background: rgba(107, 114, 128, 0.3);
                }

                .n2t-message:not(.own) .n2t-audio-bar.played {
                    background: #8b5cf6;
                }

                .n2t-audio-time {
                    font-size: 0.6875rem;
                    color: rgba(255,255,255,0.7);
                    display: flex;
                    justify-content: space-between;
                }

                .n2t-message:not(.own) .n2t-audio-time {
                    color: #9ca3af;
                }

                .n2t-audio-loading {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    color: rgba(255,255,255,0.7);
                    font-size: 0.8125rem;
                }

                .n2t-audio-loading .loading-spinner {
                    width: 16px;
                    height: 16px;
                }

                .n2t-message:not(.own) .n2t-audio-loading {
                    color: #9ca3af;
                }

                .n2t-audio-error {
                    color: #f87171;
                    font-size: 0.8125rem;
                    display: flex;
                    align-items: center;
                    gap: 0.25rem;
                }

                /* ENTERPRISE V10.70: E2E Encryption Badge */
                .n2t-audio-e2e {
                    display: inline-flex;
                    align-items: center;
                    margin-left: 0.25rem;
                    color: #22c55e;
                    opacity: 0.8;
                }

                .n2t-message:not(.own) .n2t-audio-e2e {
                    color: #22c55e;
                }

                /* ENTERPRISE V10.70: Loading state during E2E decryption */
                .n2t-audio-player.loading .n2t-audio-play-btn {
                    opacity: 0.6;
                    cursor: wait;
                }

                .n2t-audio-player.loading .n2t-audio-play-btn svg {
                    animation: pulse 1s ease-in-out infinite;
                }

                @keyframes pulse {
                    0%, 100% { opacity: 0.6; }
                    50% { opacity: 1; }
                }
            </style>
        `;

        this.#scrollContainer = this.#container.querySelector('.message-list-scroll');
        this.#messagesContainer = this.#container.querySelector('.message-list-content');
    }

    /**
     * ENTERPRISE V3.1: Setup audio player event listeners
     *
     * Uses event delegation for dynamically rendered audio players.
     * Handles:
     * - Play/pause toggle
     * - Seek on waveform click
     * - Progress updates during playback
     * - Auto-pause when another audio starts
     */
    #setupAudioPlayerListeners() {
        // Track currently playing audio
        this.#currentAudio = null;
        this.#currentAudioId = null;

        // Event delegation for play buttons
        this.#messagesContainer.addEventListener('click', (e) => {
            // Play button click
            const playBtn = e.target.closest('.n2t-audio-play-btn');
            if (playBtn) {
                const player = playBtn.closest('.n2t-audio-player');
                if (player) {
                    this.#toggleAudioPlayback(player);
                }
                return;
            }

            // Waveform click for seek
            const bars = e.target.closest('.n2t-audio-bars');
            if (bars) {
                const player = bars.closest('.n2t-audio-player');
                if (player && this.#currentAudio) {
                    const rect = bars.getBoundingClientRect();
                    const clickX = e.clientX - rect.left;
                    const progress = clickX / rect.width;
                    this.#seekAudio(player, progress);
                }
                return;
            }
        });
    }

    /**
     * Toggle audio playback
     *
     * ENTERPRISE V10.100 (2025-12-09): TRUE E2E Encryption Support
     * - Uses ChatEncryptionService with ECDH-derived keys
     * - Private key NEVER leaves browser - server cannot decrypt
     * - Requires both IV and TAG for AES-256-GCM decryption
     *
     * @param {HTMLElement} player Audio player container
     */
    async #toggleAudioPlayback(player) {
        const audioId = player.dataset.audioId;
        const audioUrl = player.dataset.audioUrl;
        const isEncrypted = player.dataset.encrypted === 'true';
        const encryptionIv = player.dataset.encryptionIv || null;
        const encryptionTag = player.dataset.encryptionTag || null;

        // If this audio is already playing, pause it
        if (this.#currentAudioId === audioId && this.#currentAudio) {
            if (this.#currentAudio.paused) {
                this.#currentAudio.play();
                this.#updatePlayButton(player, true);
            } else {
                this.#currentAudio.pause();
                this.#updatePlayButton(player, false);
            }
            return;
        }

        // Stop any currently playing audio
        if (this.#currentAudio) {
            this.#currentAudio.pause();
            this.#currentAudio.currentTime = 0;
            // Revoke previous blob URL if exists
            if (this.#currentAudio.src?.startsWith('blob:')) {
                URL.revokeObjectURL(this.#currentAudio.src);
            }
            // Reset previous player UI
            const prevPlayer = this.#messagesContainer.querySelector(`[data-audio-id="${this.#currentAudioId}"]`);
            if (prevPlayer) {
                this.#updatePlayButton(prevPlayer, false);
                this.#updateWaveformProgress(prevPlayer, 0);
            }
        }

        // ENTERPRISE V10.100: Handle TRUE E2E encrypted audio with ChatEncryptionService
        let audioSource = audioUrl;
        if (isEncrypted && encryptionIv && encryptionTag) {
            // ENTERPRISE V10.179: Use awaitable singleton promise for proper initialization
            const chatEncryption = await window.chatEncryptionReady;
            if (!chatEncryption || !chatEncryption.isInitialized) {
                console.error('[MessageList] TRUE E2E audio requires ChatEncryptionService but it\'s not available or not initialized');
                return;
            }

            // For DM, we need the conversationId to derive the ECDH shared key
            const conversationId = this.#chatType === 'dm' ? this.#chatId : null;
            if (this.#chatType === 'dm' && !conversationId) {
                console.error('[MessageList] TRUE E2E decryption requires conversationId for DM');
                return;
            }

            try {

                // Show loading state
                this.#updatePlayButton(player, true);
                player.classList.add('loading');

                // Fetch encrypted audio
                const response = await fetch(audioUrl);
                if (!response.ok) {
                    throw new Error(`Failed to fetch audio: ${response.status}`);
                }

                const encryptedBlob = await response.blob();

                // TRUE E2E Decrypt with ECDH-derived conversation key
                const decryptedBlob = await chatEncryption.decryptFile(
                    encryptedBlob,
                    encryptionIv,
                    encryptionTag,
                    conversationId,
                    'audio/webm'
                );

                // Create blob URL for playback
                audioSource = URL.createObjectURL(decryptedBlob);

                player.classList.remove('loading');
            } catch (error) {
                console.error('[MessageList] E2E audio decryption failed:', error);
                player.classList.remove('loading');
                this.#updatePlayButton(player, false);
                // TODO: Show error state in UI
                return;
            }
        }

        // Create and play audio
        this.#currentAudio = new Audio(audioSource);
        this.#currentAudioId = audioId;

        // Event listeners
        this.#currentAudio.addEventListener('play', () => {
            this.#updatePlayButton(player, true);
        });

        this.#currentAudio.addEventListener('pause', () => {
            this.#updatePlayButton(player, false);
        });

        this.#currentAudio.addEventListener('ended', () => {
            this.#updatePlayButton(player, false);
            this.#updateWaveformProgress(player, 0);
            this.#updateCurrentTime(player, 0);
            // Revoke blob URL to free memory
            if (this.#currentAudio.src?.startsWith('blob:')) {
                URL.revokeObjectURL(this.#currentAudio.src);
            }
            this.#currentAudio = null;
            this.#currentAudioId = null;
        });

        this.#currentAudio.addEventListener('timeupdate', () => {
            if (this.#currentAudio.duration) {
                const progress = this.#currentAudio.currentTime / this.#currentAudio.duration;
                this.#updateWaveformProgress(player, progress);
                this.#updateCurrentTime(player, this.#currentAudio.currentTime);
            }
        });

        this.#currentAudio.addEventListener('error', (e) => {
            console.error('[MessageList] Audio playback error:', e);
            this.#updatePlayButton(player, false);
            // Revoke blob URL on error
            if (this.#currentAudio.src?.startsWith('blob:')) {
                URL.revokeObjectURL(this.#currentAudio.src);
            }
            this.#currentAudio = null;
            this.#currentAudioId = null;
        });

        // Start playback
        this.#currentAudio.play().catch(err => {
            console.error('[MessageList] Failed to play audio:', err);
            if (this.#currentAudio.src?.startsWith('blob:')) {
                URL.revokeObjectURL(this.#currentAudio.src);
            }
            this.#currentAudio = null;
            this.#currentAudioId = null;
        });
    }

    /**
     * Seek audio to position
     * @param {HTMLElement} player Audio player container
     * @param {number} progress 0-1 progress value
     */
    #seekAudio(player, progress) {
        if (!this.#currentAudio || this.#currentAudioId !== player.dataset.audioId) return;

        const duration = this.#currentAudio.duration || parseFloat(player.dataset.duration);
        if (duration) {
            this.#currentAudio.currentTime = progress * duration;
        }
    }

    /**
     * Update play/pause button UI
     * @param {HTMLElement} player Audio player container
     * @param {boolean} isPlaying
     */
    #updatePlayButton(player, isPlaying) {
        const playIcon = player.querySelector('.play-icon');
        const pauseIcon = player.querySelector('.pause-icon');

        if (playIcon && pauseIcon) {
            playIcon.style.display = isPlaying ? 'none' : 'block';
            pauseIcon.style.display = isPlaying ? 'block' : 'none';
        }
    }

    /**
     * Update waveform progress visualization
     * @param {HTMLElement} player Audio player container
     * @param {number} progress 0-1 progress value
     */
    #updateWaveformProgress(player, progress) {
        const bars = player.querySelectorAll('.n2t-audio-bar');
        const playedCount = Math.floor(progress * bars.length);

        bars.forEach((bar, index) => {
            bar.classList.toggle('played', index < playedCount);
        });
    }

    /**
     * Update current time display
     * @param {HTMLElement} player Audio player container
     * @param {number} seconds Current time in seconds
     */
    #updateCurrentTime(player, seconds) {
        const timeEl = player.querySelector('.current-time');
        if (timeEl) {
            timeEl.textContent = this.#formatDuration(seconds);
        }
    }

    /**
     * Setup event listeners
     */
    #setupEventListeners() {
        // Scroll handling with RAF for performance
        this.#scrollContainer.addEventListener('scroll', () => {
            if (this.#scrollRAF) return;

            this.#scrollRAF = requestAnimationFrame(() => {
                this.#handleScroll();
                this.#scrollRAF = null;
            });
        }, { passive: true });

        // Scroll to bottom button
        const scrollBtn = this.#container.querySelector('.message-list-scroll-btn');
        scrollBtn?.addEventListener('click', () => {
            this.scrollToBottom(true);
        });

        // ENTERPRISE V3.1: Audio player event delegation
        // Handle play/pause and seek for dynamically rendered audio players
        this.#setupAudioPlayerListeners();

        // ENTERPRISE V10.26 (2025-12-04): Set chatId EARLY on room_loading
        // CRITICAL FIX: chat:room_loading fires BEFORE loadRoomMessages() async call
        // This ensures #chatId is set before user can send messages
        // Without this, first message sent immediately after entering room would be lost
        // because chat:message_sending checks e.detail.roomId === this.#chatId
        document.addEventListener('chat:room_loading', (e) => {
            if (e.detail?.roomId) {
                this.#chatId = e.detail.roomId;
                this.#chatType = 'room';
                this.showLoading();
            }
        });

        // Same for DM loading
        document.addEventListener('chat:dm_loading', (e) => {
            if (e.detail?.conversationUuid) {
                this.#chatId = e.detail.conversationUuid;
                this.#chatType = 'dm';
                this.showLoading();
            }
        });

        // Listen for new messages
        document.addEventListener('chat:message_received', (e) => {
            if (e.detail.roomId === this.#chatId) {
                this.addMessage(e.detail.message);
            }
        });

        // ENTERPRISE FIX: Also handle optimistic messages (chat:message_sending)
        // This ensures messages appear immediately when sent, before server confirmation
        document.addEventListener('chat:message_sending', (e) => {
            if (e.detail.roomId === this.#chatId && e.detail.message) {
                // Add optimistic message with pending status
                const msg = {
                    ...e.detail.message,
                    status: 'sending',
                    // Use current user's avatar for own messages
                    sender_avatar: window.APP_USER?.avatar || null,
                    sender_nickname: window.APP_USER?.name || 'Tu'
                };
                this.addMessage(msg);
            }
        });

        // ENTERPRISE V10.19: Handle optimistic DM messages (chat:dm_sending)
        // This ensures DM messages appear immediately when sent, before server confirmation
        // Without this, the message would only appear after the second send (when server confirms)
        // ENTERPRISE V11.8 (2025-12-12): MOBILE FIX - Set chatId if not set yet
        // On mobile, user can send message before setMessages() is called (race condition)
        document.addEventListener('chat:dm_sending', (e) => {
            if (!e.detail.message) return;

            // If chatId not set yet, set it now from the event (mobile race condition fix)
            if (!this.#chatId && e.detail.conversationUuid) {
                this.#chatId = e.detail.conversationUuid;
                this.#chatType = 'dm';
            }

            if (e.detail.conversationUuid === this.#chatId) {
                // Add optimistic message with pending status
                const msg = {
                    ...e.detail.message,
                    status: 'sending',
                    // Use current user's avatar for own messages
                    sender_avatar: window.APP_USER?.avatar || null,
                    sender_nickname: window.APP_USER?.name || 'Tu'
                };
                this.addMessage(msg);
            }
        });

        document.addEventListener('chat:dm_received', (e) => {
            // If chatId not set yet, set it now (mobile race condition fix)
            if (!this.#chatId && e.detail.conversationUuid) {
                this.#chatId = e.detail.conversationUuid;
                this.#chatType = 'dm';
            }

            if (e.detail.conversationUuid === this.#chatId) {
                this.addMessage(e.detail.message);
            }
        });

        document.addEventListener('chat:read_receipt', (e) => {
            this.#updateReadStatus(e.detail);
        });

        // Listen for message deletion events (moderation)
        document.addEventListener('chat:message_deleted', (e) => {
            if (e.detail.roomId === this.#chatId) {
                this.removeMessage(e.detail.messageUuid);
            }
        });

        // ENTERPRISE V10.27 (2025-12-04): Handle message confirmation with FALLBACK
        // CRITICAL FIX: If optimistic UI failed (race condition with #chatId),
        // we MUST still show the message when server confirms it.
        // This implements "defense in depth" - never lose a message!
        document.addEventListener('chat:message_confirmed', (e) => {
            if (e.detail.roomId === this.#chatId) {
                // Find the last pending message from current user and update it
                const pendingIdx = this.#messages.findIndex(m =>
                    m.sender_uuid === window.APP_USER?.uuid &&
                    m.status === 'sending'
                );
                if (pendingIdx !== -1) {
                    // Normal path: Update optimistic message with server data
                    this.#messages[pendingIdx] = {
                        ...this.#messages[pendingIdx],
                        ...e.detail.message,
                        id: e.detail.message.id, // Use real ID from server
                        status: 'sent'
                    };
                    this.#renderVisibleMessages();
                } else {
                    // ENTERPRISE FALLBACK: Optimistic UI failed (race condition)
                    // The message was sent but chat:message_sending was not processed
                    // (likely because #chatId was not yet set when user sent message)
                    // Solution: Add the message now from server confirmation
                    console.warn('[MessageList] No pending message found for confirmation, adding from server (race condition recovery)');

                    // Check if message already exists (avoid duplicates)
                    const msgId = e.detail.message.id || e.detail.message.uuid;
                    const exists = this.#messages.some(m =>
                        (m.id && m.id === msgId) || (m.uuid && m.uuid === msgId)
                    );

                    if (!exists) {
                        // Add the server-confirmed message
                        const serverMessage = {
                            ...e.detail.message,
                            status: 'sent',
                            sender_avatar: window.APP_USER?.avatar || null,
                            sender_nickname: window.APP_USER?.name || 'Tu'
                        };
                        this.#messages.push(serverMessage);
                        this.#renderVisibleMessages();
                        this.scrollToBottom(true);
                    }
                }
            }
        });

        // Handle DM confirmation with fallback (same pattern as room messages)
        document.addEventListener('chat:dm_sent', (e) => {
            if (e.detail.conversationUuid === this.#chatId) {
                // Find the last pending message from current user and update it
                const pendingIdx = this.#messages.findIndex(m =>
                    m.sender_uuid === window.APP_USER?.uuid &&
                    m.status === 'sending'
                );
                if (pendingIdx !== -1) {
                    // Normal path: Update optimistic message with server data
                    this.#messages[pendingIdx] = {
                        ...this.#messages[pendingIdx],
                        ...e.detail.message,
                        id: e.detail.message.id || e.detail.message.uuid,
                        status: 'sent'
                    };
                    this.#renderVisibleMessages();
                } else {
                    // Fallback: Optimistic UI failed (race condition)
                    console.warn('[MessageList] No pending DM found, adding from server');

                    const msgId = e.detail.message.id || e.detail.message.uuid;
                    const exists = this.#messages.some(m =>
                        (m.id && m.id === msgId) || (m.uuid && m.uuid === msgId)
                    );

                    if (!exists) {
                        const serverMessage = {
                            ...e.detail.message,
                            status: 'sent',
                            sender_avatar: window.APP_USER?.avatar || null,
                            sender_nickname: window.APP_USER?.name || 'Tu'
                        };
                        this.#messages.push(serverMessage);
                        this.#renderVisibleMessages();
                        this.scrollToBottom(true);
                    }
                }
            }
        });
    }

    /**
     * Setup resize observer
     */
    #setupResizeObserver() {
        this.#resizeObserver = new ResizeObserver(() => {
            this.#updateVirtualScroll();
        });
        this.#resizeObserver.observe(this.#scrollContainer);
    }

    /**
     * Handle scroll events
     */
    #handleScroll() {
        const { scrollTop, scrollHeight, clientHeight } = this.#scrollContainer;

        // Check if at bottom (for auto-scroll)
        const distanceFromBottom = scrollHeight - scrollTop - clientHeight;
        this.#autoScroll = distanceFromBottom < MessageList.CONFIG.SCROLL_THRESHOLD;

        // Update scroll button visibility
        const scrollBtn = this.#container.querySelector('.message-list-scroll-btn');
        scrollBtn?.classList.toggle('hidden', this.#autoScroll);

        // Check if near top (load more)
        if (scrollTop < MessageList.CONFIG.LOAD_MORE_THRESHOLD && !this.#isLoadingMore && this.#hasMore) {
            this.#loadMore();
        }

        // Update virtual scroll
        this.#updateVirtualScroll();
    }

    /**
     * Update virtual scroll rendering
     *
     * ENTERPRISE v10.32 (2025-12-04): Improved Virtual Scroll Stability
     * - Raised threshold to 200 messages (was 50)
     * - Virtual scroll with fixed ITEM_HEIGHT causes scroll instability
     * - Modern browsers handle 200 DOM nodes efficiently
     * - Only enable virtual scroll for very large lists (200+)
     * - This fixes the "scroll impazzito" bug with long conversations
     */
    #updateVirtualScroll() {
        // ENTERPRISE V11.9 FIX: Clear container when no messages
        // Previously, returning early left old messages in DOM when switching rooms
        if (this.#messages.length === 0) {
            this.#messagesContainer.innerHTML = '';
            return;
        }

        const { scrollTop, clientHeight } = this.#scrollContainer;
        const itemHeight = MessageList.CONFIG.ITEM_HEIGHT;
        const bufferSize = MessageList.CONFIG.BUFFER_SIZE;
        const virtualScrollThreshold = MessageList.CONFIG.VIRTUAL_SCROLL_THRESHOLD;

        let startIndex, endIndex;

        // ENTERPRISE V11.8: DM chats NEVER use virtual scroll (messages have 1hr TTL)
        // Virtual scroll causes disappearing messages on scroll - not worth it for DM
        const isDM = this.#chatType === 'dm';

        // ENTERPRISE v10.32: Disable virtual scroll for message lists under threshold
        // Virtual scroll with estimated heights causes scroll jumping
        // Modern browsers handle 200+ DOM elements without performance issues
        if (isDM || this.#messages.length < virtualScrollThreshold || clientHeight < 100) {
            // Render ALL messages - no virtual scroll
            startIndex = 0;
            endIndex = this.#messages.length - 1;
        } else {
            // Virtual scroll for very large lists only (200+ messages in rooms)
            startIndex = Math.max(0, Math.floor(scrollTop / itemHeight) - bufferSize);
            endIndex = Math.min(
                this.#messages.length - 1,
                Math.ceil((scrollTop + clientHeight) / itemHeight) + bufferSize
            );
        }

        // Only re-render if range changed (optimization)
        const rangeUnchanged = startIndex === this.#visibleRange.start && endIndex === this.#visibleRange.end;
        if (rangeUnchanged) {
            return;
        }

        this.#visibleRange = { start: startIndex, end: endIndex };
        this.#renderVisibleMessages();
    }

    /**
     * Render only visible messages
     *
     * ENTERPRISE FIX v9.9: When rendering all messages (small list or initial load),
     * don't use spacers to prevent first message cut-off issue
     */
    #renderVisibleMessages() {
        const { start, end } = this.#visibleRange;
        const itemHeight = MessageList.CONFIG.ITEM_HEIGHT;

        // Update spacers
        const topSpacer = this.#container.querySelector('.message-list-spacer-top');
        const bottomSpacer = this.#container.querySelector('.message-list-spacer-bottom');

        // ENTERPRISE FIX v9.9: If rendering all messages (start=0), no top spacer needed
        // This prevents the first message from being cut off
        const isRenderingAll = start === 0;
        const topSpacerHeight = isRenderingAll ? 0 : start * itemHeight;
        const bottomSpacerHeight = Math.max(0, (this.#messages.length - end - 1) * itemHeight);

        topSpacer.style.height = `${topSpacerHeight}px`;
        bottomSpacer.style.height = `${bottomSpacerHeight}px`;

        // Render visible messages
        const visibleMessages = this.#messages.slice(start, end + 1);
        let html = '';
        let lastDate = null;

        visibleMessages.forEach((msg, index) => {
            const actualIndex = start + index;
            const msgDate = new Date(msg.created_at).toDateString();

            // Add date separator if new day
            if (msgDate !== lastDate) {
                html += this.#renderDateSeparator(msg.created_at);
                lastDate = msgDate;
            }

            html += this.#renderMessage(msg, actualIndex);
        });

        this.#messagesContainer.innerHTML = html;
    }

    /**
     * Render a single message
     * @param {Object} msg
     * @param {number} index
     * @returns {string}
     */
    #renderMessage(msg, index) {
        const isOwn = msg.sender_uuid === this.#currentUserUuid;
        const isSystem = msg.message_type === 'system' || msg.isSystemMessage === true;
        const isAudio = msg.message_type === 'audio';
        // ENTERPRISE GALAXY: Moderator detection
        const isModerator = msg.is_moderator === true;
        // ENTERPRISE V11.9: New device warning (decryption key regenerated)
        const isNewDeviceWarning = msg.newDeviceWarning === true;

        if (isSystem) {
            // ENTERPRISE V11.9: Special styling for new device warning
            const warningClass = isNewDeviceWarning ? 'n2t-new-device-warning' : '';
            return `
                <div class="n2t-message system ${warningClass}" data-index="${index}" data-id="${msg.id || msg.uuid}">
                    <div class="n2t-message-bubble">
                        <span class="n2t-message-text">${this.#escapeHtml(msg.content)}</span>
                    </div>
                </div>
            `;
        }

        // ENTERPRISE GALAXY: Moderator avatar with shield icon
        // For moderators: use special SVG avatar with shield icon
        // For own messages: use current user's avatar
        // For other users: use sender_avatar if available, otherwise first letter
        let avatarHtml;
        if (isModerator) {
            // Moderator special avatar: shield icon with purple gradient
            avatarHtml = `
                <div class="n2t-message-avatar n2t-moderator-avatar" title="Moderatore">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="modGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#d946ef"/>
                                <stop offset="100%" style="stop-color:#a855f7"/>
                            </linearGradient>
                        </defs>
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" fill="url(#modGradient)"/>
                        <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            `;
        } else {
            let avatarUrl = msg.sender_avatar;
            if (isOwn && window.APP_USER?.avatar) {
                avatarUrl = window.APP_USER.avatar;
            }
            const avatarLetter = msg.sender_nickname?.[0]?.toUpperCase() || '?';
            avatarHtml = avatarUrl
                ? `<img class="n2t-message-avatar" src="${avatarUrl}" alt="${this.#escapeHtml(msg.sender_nickname || 'Utente')}" onerror="this.outerHTML='<div class=\\'n2t-message-avatar n2t-avatar-letter\\'>${avatarLetter}</div>'">`
                : `<div class="n2t-message-avatar n2t-avatar-letter">${avatarLetter}</div>`;
        }

        const time = this.#formatTime(msg.created_at);
        const statusHtml = isOwn ? this.#renderStatus(msg.status) : '';
        const encryptedHtml = msg.decrypted ? this.#renderEncryptedBadge() : '';

        // ENTERPRISE GALAXY: Moderator badge
        const moderatorBadgeHtml = isModerator
            ? `<span class="n2t-mod-badge">MOD</span>`
            : '';

        // Username with optional moderator styling
        const usernameClass = isModerator ? 'n2t-message-username n2t-moderator-name' : 'n2t-message-username';

        // ENTERPRISE V10.88: Deleted message rendering
        // Security: deleted messages show notice, original NEVER exposed
        const isDeleted = msg.deleted === true;
        const isDM = this.#chatType === 'dm';

        // ENTERPRISE V3.1: Audio message rendering
        let bubbleContent;
        if (isDeleted) {
            // ENTERPRISE V11.8: DM = only TTL expiration (NO moderation in private chats!)
            // Rooms = moderation possible
            const deletionIcon = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:4px;">
                    <circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/>
                   </svg>`;
            const deletionText = isDM
                ? 'Messaggio scaduto'
                : (msg.deleted_by === 'moderator' ? 'Messaggio rimosso da un moderatore' : 'Messaggio non disponibile');

            bubbleContent = `<span class="n2t-message-text n2t-censored-message">
                ${deletionIcon}
                ${deletionText}
            </span>`;
        } else if (isAudio) {
            bubbleContent = this.#renderAudioPlayer(msg, index);
        } else {
            bubbleContent = `<span class="n2t-message-text">${this.#formatMessageContent(msg.content)}</span>`;
        }

        // ENTERPRISE GALAXY: Moderator messages have special styling
        let messageClass = isModerator ? 'n2t-message n2t-moderator-message' : 'n2t-message';
        if (isDeleted) {
            messageClass += ' n2t-deleted-message';
        }

        return `
            <div class="${messageClass} ${isOwn ? 'own' : ''}" data-index="${index}" data-id="${msg.id || msg.uuid}" data-sender-uuid="${msg.sender_uuid || ''}" ${isModerator ? 'data-moderator="true"' : ''} ${isDeleted ? 'data-deleted="true"' : ''}>
                ${avatarHtml}
                <div class="n2t-message-content">
                    <div class="n2t-message-header">
                        ${!isOwn ? `<span class="${usernameClass}">${this.#escapeHtml(msg.sender_nickname || 'Moderatore')}</span>${moderatorBadgeHtml}` : ''}
                        <span class="n2t-message-time">${time}</span>
                    </div>
                    <div class="n2t-message-bubble${isModerator ? ' n2t-moderator-bubble' : ''}${isDeleted ? ' n2t-censored-bubble' : ''}">
                        ${bubbleContent}
                    </div>
                    ${encryptedHtml}
                    ${statusHtml}
                </div>
            </div>
        `;
    }

    /**
     * ENTERPRISE V3.1: Render audio player for DM audio messages
     *
     * Features:
     * - Play/pause button with loading state
     * - Waveform visualization (fake bars, updated on playback)
     * - Progress tracking with seek capability
     * - Duration display (current/total)
     *
     * ENTERPRISE V10.70 (2025-12-07): E2E Encryption Support
     * - Includes data attributes for encrypted audio (is_encrypted, encryption_iv)
     * - Player will fetch, decrypt, then play encrypted audio
     *
     * @param {Object} msg Message object with audio_url and duration_seconds
     * @param {number} index Message index for unique IDs
     * @returns {string} HTML for audio player
     */
    #renderAudioPlayer(msg, index) {
        const audioUrl = msg.audio_url;
        const duration = msg.duration_seconds || msg.duration || 0;
        const msgId = msg.id || msg.uuid || `audio-${index}`;

        // ENTERPRISE V10.100: TRUE E2E encryption metadata (iv + tag)
        const isE2EEncrypted = msg.audio_is_encrypted === true;
        const encryptionIv = msg.audio_encryption_iv || null;
        const encryptionTag = msg.audio_encryption_tag || null;

        // No URL - show error or loading
        if (!audioUrl) {
            if (msg.status === 'sending') {
                return `
                    <div class="n2t-audio-player">
                        <div class="n2t-audio-loading">
                            <div class="loading-spinner"></div>
                            <span>Caricamento audio...</span>
                        </div>
                    </div>
                `;
            }
            return `
                <div class="n2t-audio-player">
                    <div class="n2t-audio-error">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <span>Audio non disponibile</span>
                    </div>
                </div>
            `;
        }

        // Generate waveform bars (30 bars for visual representation)
        const barCount = 30;
        let barsHtml = '';
        for (let i = 0; i < barCount; i++) {
            // Random heights for visual effect
            const height = 6 + Math.floor(Math.random() * 18);
            barsHtml += `<div class="n2t-audio-bar" style="height: ${height}px" data-index="${i}"></div>`;
        }

        const durationFormatted = this.#formatDuration(duration);

        // ENTERPRISE V10.100: Build data attributes with TRUE E2E encryption info (iv + tag)
        let dataAttrs = `data-audio-id="${msgId}" data-audio-url="${this.#escapeHtml(audioUrl)}" data-duration="${duration}"`;
        if (isE2EEncrypted && encryptionIv && encryptionTag) {
            dataAttrs += ` data-encrypted="true" data-encryption-iv="${this.#escapeHtml(encryptionIv)}" data-encryption-tag="${this.#escapeHtml(encryptionTag)}"`;
        }

        // ENTERPRISE V10.70: Show lock icon for E2E encrypted audio
        const e2eBadge = isE2EEncrypted ? `
            <span class="n2t-audio-e2e" title="Audio crittografato end-to-end">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </span>
        ` : '';

        return `
            <div class="n2t-audio-player" ${dataAttrs}>
                <button class="n2t-audio-play-btn" aria-label="Riproduci audio">
                    <svg class="play-icon" viewBox="0 0 24 24" fill="currentColor">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                    <svg class="pause-icon" viewBox="0 0 24 24" fill="currentColor" style="display: none;">
                        <rect x="6" y="4" width="4" height="16"/>
                        <rect x="14" y="4" width="4" height="16"/>
                    </svg>
                </button>
                <div class="n2t-audio-waveform">
                    <div class="n2t-audio-bars" data-bar-count="${barCount}">
                        ${barsHtml}
                    </div>
                    <div class="n2t-audio-time">
                        <span class="current-time">0:00</span>
                        <span class="total-time">${durationFormatted}</span>
                        ${e2eBadge}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Format duration in seconds to M:SS format
     * @param {number} seconds
     * @returns {string}
     */
    #formatDuration(seconds) {
        if (!seconds || isNaN(seconds)) return '0:00';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    /**
     * Render message status (sent/delivered/read)
     * @param {string} status
     * @returns {string}
     */
    #renderStatus(status) {
        const icons = {
            sending: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>`,
            sent: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>`,
            delivered: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 6 9 17 4 12"/><polyline points="22 6 13 17"/></svg>`,
            read: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 6 9 17 4 12"/><polyline points="22 6 13 17"/></svg>`
        };

        const labels = {
            sending: 'Invio...',
            sent: 'Inviato',
            delivered: 'Consegnato',
            read: 'Letto'
        };

        return `
            <div class="n2t-message-status ${status || 'sent'}">
                ${icons[status] || icons.sent}
                <span>${labels[status] || labels.sent}</span>
            </div>
        `;
    }

    /**
     * Render encrypted badge
     * @returns {string}
     */
    #renderEncryptedBadge() {
        return `
            <div class="encrypted-indicator">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <span>Crittografato</span>
            </div>
        `;
    }

    /**
     * Render date separator
     * @param {string} dateStr
     * @returns {string}
     */
    #renderDateSeparator(dateStr) {
        const date = new Date(dateStr);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);

        let label;
        if (date.toDateString() === today.toDateString()) {
            label = 'Oggi';
        } else if (date.toDateString() === yesterday.toDateString()) {
            label = 'Ieri';
        } else {
            label = date.toLocaleDateString('it-IT', {
                weekday: 'long',
                day: 'numeric',
                month: 'long'
            });
        }

        return `<div class="date-separator">${label}</div>`;
    }

    /**
     * Load more messages (older)
     */
    async #loadMore() {
        if (this.#isLoadingMore || !this.#hasMore || !this.#onLoadMore) return;

        this.#isLoadingMore = true;
        const loading = this.#container.querySelector('.message-list-loading');
        loading?.classList.remove('hidden');

        try {
            const oldestMessage = this.#messages[0];
            const beforeId = oldestMessage?.id || oldestMessage?.uuid;

            const result = await this.#onLoadMore(beforeId);

            if (result?.messages?.length > 0) {
                // Prepend messages
                this.#messages = [...result.messages, ...this.#messages];

                // Maintain scroll position
                const scrollHeight = this.#scrollContainer.scrollHeight;
                this.#updateVirtualScroll();
                const newScrollHeight = this.#scrollContainer.scrollHeight;
                this.#scrollContainer.scrollTop += (newScrollHeight - scrollHeight);
            }

            this.#hasMore = result?.hasMore ?? false;

        } catch (error) {
            console.error('[MessageList] Failed to load more:', error);
        } finally {
            this.#isLoadingMore = false;
            loading?.classList.add('hidden');
        }
    }

    /**
     * Update read status for messages
     * @param {Object} data
     */
    #updateReadStatus(data) {
        const { conversation_uuid, reader_uuid } = data;
        if (conversation_uuid !== this.#chatId) return;

        // Update status for own messages
        this.#messages.forEach(msg => {
            if (msg.sender_uuid === this.#currentUserUuid && msg.status !== 'read') {
                msg.status = 'read';
            }
        });

        this.#renderVisibleMessages();
    }

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    /**
     * Show loading skeleton
     */
    showLoading() {
        const skeleton = this.#container.querySelector('.message-list-skeleton');
        const emptyState = this.#container.querySelector('.message-list-empty');
        skeleton?.classList.remove('hidden');
        emptyState?.classList.add('hidden');
        this.#messagesContainer.innerHTML = '';
    }

    /**
     * Hide loading skeleton
     */
    hideLoading() {
        const skeleton = this.#container.querySelector('.message-list-skeleton');
        skeleton?.classList.add('hidden');
    }

    /**
     * Set messages and chat context
     * @param {Array} messages
     * @param {string} chatId - Room ID or conversation UUID
     * @param {string} chatType - 'room' or 'dm'
     *
     * ENTERPRISE FIX v9.9: Force render and handle layout timing
     */
    setMessages(messages, chatId, chatType = 'room') {
        this.#messages = [...messages];
        this.#chatId = chatId;
        this.#chatType = chatType;
        this.#hasMore = true;

        // Hide loading skeleton
        this.hideLoading();

        // Update empty state
        const emptyState = this.#container.querySelector('.message-list-empty');
        emptyState?.classList.toggle('hidden', messages.length > 0);

        // ENTERPRISE FIX v9.9: Force initial render of ALL messages
        // Reset visible range to force full re-render
        this.#visibleRange = { start: -1, end: -1 };
        this.#updateVirtualScroll();

        // Auto-scroll to bottom
        this.scrollToBottom(false);

        // Re-render after layout is complete (ensures proper virtual scroll calculation)
        requestAnimationFrame(() => {
            setTimeout(() => {
                const { clientHeight } = this.#scrollContainer;
                if (clientHeight > 100 && this.#messages.length > 0) {
                    this.#visibleRange = { start: -1, end: -1 };
                    this.#updateVirtualScroll();
                    this.scrollToBottom(false);
                }
            }, 50);
        });
    }

    /**
     * Add a new message
     * @param {Object} message
     *
     * ENTERPRISE v10.32: Improved duplicate detection
     * - Checks both id and uuid
     * - Also checks for temp_ prefix to prevent duplicates during optimistic UI
     * - Handles case where server returns different ID format
     */
    addMessage(message) {
        // ENTERPRISE v10.32: Enhanced duplicate detection
        const msgId = message.id || message.uuid;
        const exists = this.#messages.some(m => {
            // Exact match on id or uuid
            if ((m.id && m.id === message.id) || (m.uuid && m.uuid === message.uuid)) {
                return true;
            }
            // Check if this is a server confirmation of a temp message
            // Temp messages have id like "temp_1234567890"
            // Don't add server message if we already have a pending temp from same user with similar content
            if (message.sender_uuid === window.APP_USER?.uuid &&
                m.sender_uuid === window.APP_USER?.uuid &&
                m.status === 'sending' &&
                m.content === message.content) {
                return true;
            }
            return false;
        });

        if (exists) return;

        this.#messages.push(message);

        // Update empty state
        const emptyState = this.#container.querySelector('.message-list-empty');
        emptyState?.classList.add('hidden');

        // Update render
        this.#updateVirtualScroll();

        // ENTERPRISE V6.1 FIX: Auto-scroll AFTER DOM update
        // Using requestAnimationFrame ensures the new message is rendered before scrolling
        // This fixes the bug where scroll went to top instead of bottom
        requestAnimationFrame(() => {
            if (this.#autoScroll) {
                this.scrollToBottom(true);
            } else {
                // Show new message indicator
                const scrollBtn = this.#container.querySelector('.message-list-scroll-btn');
                const countBadge = scrollBtn?.querySelector('.new-count');
                if (countBadge) {
                    const current = parseInt(countBadge.textContent) || 0;
                    countBadge.textContent = current + 1;
                    countBadge.classList.add('visible');
                }
            }
        });
    }

    /**
     * Update a message (e.g., temp message with server response)
     * @param {string} tempId
     * @param {Object} message
     */
    updateMessage(tempId, message) {
        const index = this.#messages.findIndex(m => m.id === tempId || m.uuid === tempId);
        if (index !== -1) {
            this.#messages[index] = { ...this.#messages[index], ...message };
            this.#renderVisibleMessages();
        }
    }

    /**
     * ENTERPRISE V10.84: Remove a message from the list (moderation deletion)
     *
     * Removes message from internal array and re-renders the visible messages.
     * Used when moderator deletes a message in real-time.
     *
     * @param {string} messageId Message ID or UUID to remove
     * @returns {boolean} True if message was found and censored
     */
    removeMessage(messageId, options = {}) {
        // ENTERPRISE V11.8: Support both censoring and full removal
        // - options.fullRemove = true: Actually remove from array (for temp messages)
        // - options.fullRemove = false/undefined: Censor only (for moderated messages)

        const index = this.#messages.findIndex(m =>
            (m.id && m.id === messageId) ||
            (m.uuid && m.uuid === messageId)
        );

        if (index === -1) return false;

        if (options.fullRemove) {
            // Full removal for temp/optimistic messages
            this.#messages.splice(index, 1);
            this.#renderVisibleMessages();
            return true;
        }

        // Censor message instead of removing (moderation)
        const msg = this.#messages[index];
        delete msg.content;
        delete msg.audio_url;
        msg.deleted = true;
        msg.deleted_by = 'moderator';

        this.#renderVisibleMessages();
        return true;
    }

    /**
     * Scroll to bottom
     * @param {boolean} smooth
     * ENTERPRISE FIX V2: Mobile-robust scrolling
     */
    scrollToBottom(smooth = true) {
        const doScroll = () => {
            // MOBILE FIX: Use scrollTop = scrollHeight (more reliable than scrollTo on mobile)
            if (smooth) {
                this.#scrollContainer.scrollTo({
                    top: this.#scrollContainer.scrollHeight,
                    behavior: 'smooth'
                });
            } else {
                // Instant scroll - most reliable method for mobile
                this.#scrollContainer.scrollTop = this.#scrollContainer.scrollHeight;
            }
        };

        // Execute scroll
        doScroll();

        // MOBILE FIX: Also scroll after next frame to handle timing issues
        requestAnimationFrame(() => {
            doScroll();
            // Double RAF for iOS Safari quirks
            requestAnimationFrame(doScroll);
        });

        this.#autoScroll = true;

        // Clear new message badge
        const countBadge = this.#container.querySelector('.new-count');
        if (countBadge) {
            countBadge.textContent = '';
            countBadge.classList.remove('visible');
        }
    }

    /**
     * Clear all messages
     */
    clear() {
        this.#messages = [];
        this.#chatId = null;
        this.#hasMore = true;

        this.#messagesContainer.innerHTML = '';

        const emptyState = this.#container.querySelector('.message-list-empty');
        emptyState?.classList.remove('hidden');
    }

    /**
     * Show empty state with custom message
     *
     * ENTERPRISE v9.9: Used for expired message scenarios
     * - When user arrives from notification but messages expired
     * - When all messages expire while user is viewing
     *
     * @param {string} message Custom message to display
     */
    showEmpty(message = 'Nessun messaggio ancora', chatId = null, chatType = null) {
        this.#messages = [];
        this.#messagesContainer.innerHTML = '';

        // ENTERPRISE V11.8: Set chatId/chatType even for empty state
        // This ensures addMessage() works correctly when first message is sent
        if (chatId) this.#chatId = chatId;
        if (chatType) this.#chatType = chatType;

        // Reset visible range to allow first message to render
        this.#visibleRange = { start: -1, end: -1 };

        const emptyState = this.#container.querySelector('.message-list-empty');
        if (emptyState) {
            // Update the message text
            const messageP = emptyState.querySelector('p:not(.hint)');
            if (messageP) {
                messageP.textContent = message;
            }
            // Hide the hint if custom message is about expiration
            const hintP = emptyState.querySelector('.hint');
            if (hintP && message.includes('scadut')) {
                hintP.textContent = '';
            }
            emptyState.classList.remove('hidden');
        }

        // Hide skeleton if visible
        const skeleton = this.#container.querySelector('.message-list-skeleton');
        skeleton?.classList.add('hidden');
    }

    /**
     * Destroy component
     */
    destroy() {
        // ENTERPRISE V3.1: Stop any playing audio
        if (this.#currentAudio) {
            this.#currentAudio.pause();
            this.#currentAudio = null;
            this.#currentAudioId = null;
        }
        if (this.#resizeObserver) {
            this.#resizeObserver.disconnect();
        }
        if (this.#scrollRAF) {
            cancelAnimationFrame(this.#scrollRAF);
        }
        this.#container.innerHTML = '';
    }

    // ========================================================================
    // UTILITIES
    // ========================================================================

    /**
     * Format time
     * ENTERPRISE: Handles multiple formats:
     * - ISO string: "2025-12-02T15:30:00.000Z"
     * - Unix timestamp (seconds): 1733156523
     * - Microtime float: 1733156523.456789
     * - Already formatted: "15:30"
     *
     * @param {string|number} dateStr
     * @returns {string}
     */
    #formatTime(dateStr) {
        if (!dateStr) return '';

        // If already formatted (HH:MM), return as-is
        if (typeof dateStr === 'string' && /^\d{1,2}:\d{2}$/.test(dateStr)) {
            return dateStr;
        }

        let date;

        // Handle Unix timestamp (seconds or microtime float)
        if (typeof dateStr === 'number' || /^\d+\.?\d*$/.test(dateStr)) {
            const timestamp = parseFloat(dateStr);
            // If timestamp is in seconds (< year 3000 in seconds = ~32503680000)
            // Multiply by 1000 to get milliseconds
            if (timestamp < 32503680000) {
                date = new Date(timestamp * 1000);
            } else {
                date = new Date(timestamp);
            }
        } else {
            // ISO string or other format
            date = new Date(dateStr);
        }

        // Validate date
        if (isNaN(date.getTime())) {
            console.warn('[MessageList] Invalid date:', dateStr);
            return '';
        }

        // Check if today
        const now = new Date();
        const isToday = date.toDateString() === now.toDateString();

        if (isToday) {
            return date.toLocaleTimeString('it-IT', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Not today - show date and time
        return date.toLocaleDateString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    /**
     * Format message content (linkify, emoticons, etc.)
     * @param {string} content
     * @returns {string}
     */
    #formatMessageContent(content) {
        if (!content) return '';

        // Escape HTML first
        let formatted = this.#escapeHtml(content);

        // ENTERPRISE V11.9: Convert MSN-style shortcodes to animated emoticons
        // Only for chat rooms (not DM) - AnimatedEmoticonData must be loaded
        if (this.#chatType === 'room' && window.AnimatedEmoticonData) {
            formatted = window.AnimatedEmoticonData.parseShortcodes(formatted);
        }

        // Convert URLs to links
        const urlRegex = /(https?:\/\/[^\s<]+)/g;
        formatted = formatted.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');

        return formatted;
    }

    /**
     * Escape HTML
     * @param {string} str
     * @returns {string}
     */
    #escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Getters
    get messages() { return [...this.#messages]; }
    get chatId() { return this.#chatId; }
    get messageCount() { return this.#messages.length; }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = MessageList;
}

window.MessageList = MessageList;
