/**
 * GlobalAudioManager - Enterprise Audio Playback Controller
 *
 * Features:
 * - Only ONE audio playing at a time (auto-pause previous)
 * - Global pause/resume control
 * - Playback tracking
 *
 * @package need2talk/Lightning
 * @version 1.0.0
 */

class GlobalAudioManager {
    constructor() {
        this.currentAudio = null; // Currently playing audio element
        this.currentPostId = null; // Currently playing post ID

        this.init();
    }

    /**
     * Initialize global audio manager
     */
    init() {
        // Listen for ALL audio play events in the page
        document.addEventListener('play', (e) => {
            if (e.target.tagName === 'AUDIO') {
                this.handleAudioPlay(e.target);
            }
        }, true); // Use capture phase to intercept ALL audio elements
    }

    /**
     * Handle audio play event
     * Automatically pauses previous audio
     *
     * @param {HTMLAudioElement} audioElement - Audio element starting playback
     */
    handleAudioPlay(audioElement) {
        // If there's a currently playing audio AND it's different from this one
        if (this.currentAudio && this.currentAudio !== audioElement) {
            // Pause the previous audio
            this.currentAudio.pause();
            this.currentAudio.currentTime = 0; // Optional: reset to beginning
        }

        // Update current audio reference
        this.currentAudio = audioElement;
        this.currentPostId = audioElement.dataset.postId || null;
    }

    /**
     * Pause current audio
     */
    pauseCurrent() {
        if (this.currentAudio && !this.currentAudio.paused) {
            this.currentAudio.pause();
        }
    }

    /**
     * Stop current audio (pause + reset)
     */
    stopCurrent() {
        if (this.currentAudio) {
            this.currentAudio.pause();
            this.currentAudio.currentTime = 0;
            this.currentAudio = null;
            this.currentPostId = null;
        }
    }

    /**
     * Get currently playing audio info
     *
     * @returns {Object|null} Current audio info
     */
    getCurrentInfo() {
        if (!this.currentAudio) {
            return null;
        }

        return {
            postId: this.currentPostId,
            src: this.currentAudio.src,
            currentTime: this.currentAudio.currentTime,
            duration: this.currentAudio.duration,
            paused: this.currentAudio.paused
        };
    }
}

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.globalAudioManager = new GlobalAudioManager();
    });
} else {
    window.globalAudioManager = new GlobalAudioManager();
}

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = GlobalAudioManager;
}
