/**
 * need2talk Home Page Initialization
 * Separates initialization logic from view
 */

// Initialize home page when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    
    // Get data from PHP via data attributes
    const appData = document.body.dataset;
    
    // Initialize Need2Talk app (not a constructor)
    if (window.Need2TalkHomeApp) {
        window.Need2TalkHomeApp.init();
        window.Need2TalkApp = window.Need2TalkHomeApp;
        
        Need2Talk.Logger.info('HomeInit', 'Home app initialized');
    }
    
    // Store CSRF token globally
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        window.Need2TalkCSRF = csrfMeta.getAttribute('content');
    }
    
    // Initialize components
    if (window.emotionFilterData) {
        const filterElement = document.querySelector('[x-data*="emotionFilterData"]');
        if (filterElement && !window.Alpine) {
            // Fallback initialization without Alpine.js
            const filterInstance = window.emotionFilterData();
            filterInstance.init();
            window.emotionFilterInstance = filterInstance;
        }
    }
    
    // Initialize audio player
    if (window.audioPlayerData) {
        const playerElements = document.querySelectorAll('[x-data*="audioPlayerData"]');
        if (playerElements.length > 0 && !window.Alpine) {
            playerElements.forEach((element, index) => {
                const playerInstance = window.audioPlayerData();
                playerInstance.initPlayer();
                element.audioPlayerInstance = playerInstance;
            });
        }
    }
});

// Export for debugging
if (window.Need2Talk && window.Need2Talk.debug) {
    window.Need2Talk.homeInit = {
        version: '1.0.0',
        initialized: true
    };
}