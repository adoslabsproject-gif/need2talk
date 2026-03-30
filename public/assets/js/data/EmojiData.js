/**
 * ================================================================================
 * EMOJI DATA - CENTRALIZED EMOJI PICKER DATA (ENTERPRISE GALAXY)
 * ================================================================================
 *
 * Single source of truth for all emoji picker implementations
 * Used by: FloatingRecorder, AudioDayModal edit, Comments, etc.
 *
 * @version 1.0.0
 * @author need2talk.it - AI-Orchestrated Development
 * ================================================================================
 */

const EmojiData = {
    // Category definitions with icons for tabs
    categories: [
        { id: 'smileys', label: 'Smileys & People', icon: '😊' },
        { id: 'hearts', label: 'Hearts & Love', icon: '❤️' },
        { id: 'celebration', label: 'Celebration', icon: '🎉' },
        { id: 'animals', label: 'Animals & Nature', icon: '🐶' },
        { id: 'food', label: 'Food & Drink', icon: '🍕' },
        { id: 'activities', label: 'Activities', icon: '⚽' },
        { id: 'symbols', label: 'Symbols', icon: '⭐' }
    ],

    // Emoji data by category (exact order from FloatingRecorder)
    emojis: {
        smileys: [
            '😊', '😂', '🤣', '😁', '😄', '😃', '😀', '😅',
            '😆', '🥰', '😍', '😘', '😗', '😙', '😚', '🤗',
            '🤩', '😎', '🥳', '😏', '😌', '😉', '🤭', '😔',
            '😢', '😭', '😩', '😓', '😞', '😖', '🥺', '😱',
            '😨', '😰', '😳', '😡', '😠', '🤬', '😈', '👿',
            '💀', '☠️', '💩', '🤡', '👻', '👽', '🤖', '🤔', '🤫'
        ],
        hearts: [
            '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍',
            '🤎', '💔', '❣️', '💕', '💞', '💓', '💗', '💖',
            '💘', '💝', '💟', '🌹', '🌺', '🌸', '🌼', '🌻'
        ],
        celebration: [
            '🎉', '🎊', '🎈', '🎁', '🎀', '🎂', '🍰', '🧁',
            '🥳', '🎃', '🎄', '🎆', '🎇', '✨', '🎸', '🎵'
        ],
        animals: [
            '🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼',
            '🐨', '🐯', '🦁', '🐮', '🐷', '🐸', '🐵', '🙈',
            '🙉', '🙊', '🐔', '🐧', '🐦', '🦄', '🐝', '🦋'
        ],
        food: [
            '🍕', '🍔', '🍟', '🌭', '🍿', '🍩', '🍪', '🍫',
            '🍬', '🍭', '🍰', '🧁', '🍎', '🍊', '🍋', '🍌',
            '🍉', '🍇', '🍓', '🍑', '☕', '🍵', '🥤', '🍺'
        ],
        activities: [
            '⚽', '🏀', '🏈', '⚾', '🎾', '🏐', '🏓', '🏸',
            '🥊', '🏋️', '🚴', '🏊', '🎮', '🎯', '🎲', '♟️'
        ],
        symbols: [
            '⭐', '🌟', '✨', '💫', '🔥', '💥', '💢', '💪',
            '👍', '👎', '👊', '✌️', '🤞', '🙏', '👏', '🌈',
            '☀️', '🌙', '⚡', '⛅', '🌱', '🌷', '⏰', '⏱️'
        ]
    },

    /**
     * Get all emojis for a category
     * @param {string} categoryId
     * @returns {string[]}
     */
    getCategory(categoryId) {
        return this.emojis[categoryId] || [];
    },

    /**
     * Get all categories info
     * @returns {Array}
     */
    getCategories() {
        return this.categories;
    },

    /**
     * Get flat array of all emojis
     * @returns {string[]}
     */
    getAllEmojis() {
        return Object.values(this.emojis).flat();
    },

    /**
     * Generate HTML for emoji picker popup
     * Used by both FloatingRecorder and AudioDayModal
     * @param {string} targetInputId - ID of input field to insert emoji into
     * @returns {string} HTML string
     */
    generatePickerHTML(targetInputId = null) {
        const categoriesHTML = this.categories.map((cat, index) => `
            <button type="button"
                    class="emoji-category-tab flex-shrink-0 px-3 py-2 text-2xl rounded-lg hover:bg-gray-700 transition-all ${index === 0 ? 'active bg-gray-700' : ''}"
                    data-category="${cat.id}"
                    title="${cat.label}">
                ${cat.icon}
            </button>
        `).join('');

        const contentHTML = this.categories.map((cat, index) => `
            <div class="emoji-category-content ${index > 0 ? 'hidden' : ''}" data-category="${cat.id}">
                <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">${cat.label}</h5>
                <div style="display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 0.25rem; margin-bottom: 1rem;">
                    ${this.emojis[cat.id].map(emoji => `
                        <button type="button"
                                class="emoji-btn text-2xl hover:scale-125 transition-transform p-2 hover:bg-gray-800 rounded"
                                data-emoji="${emoji}">${emoji}</button>
                    `).join('')}
                </div>
            </div>
        `).join('');

        return `
            <div class="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center" style="z-index: 9999999;">
                <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md mx-4 max-h-[80vh] flex flex-col">
                    <!-- Header -->
                    <div class="flex items-center justify-between p-4 border-b border-gray-700">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">😊</span>
                            <span class="font-semibold text-white">Emoji</span>
                        </div>
                        <button type="button" class="emoji-picker-close text-gray-400 hover:text-white transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <!-- Category Tabs -->
                    <div class="flex gap-1 p-2 border-b border-gray-700 overflow-x-auto">
                        ${categoriesHTML}
                    </div>

                    <!-- Emoji Grid -->
                    <div class="p-4 overflow-y-auto flex-1">
                        ${contentHTML}
                    </div>
                </div>
            </div>
        `;
    },

    /**
     * Initialize emoji picker event handlers
     * Call this after inserting picker HTML into DOM
     * @param {HTMLElement} pickerElement - The picker container
     * @param {function} onEmojiSelect - Callback when emoji is selected (receives emoji string)
     * @param {function} onClose - Callback when picker is closed
     */
    initPickerEvents(pickerElement, onEmojiSelect, onClose) {
        if (!pickerElement) return;

        // Category tab switching
        const tabs = pickerElement.querySelectorAll('.emoji-category-tab');
        const contents = pickerElement.querySelectorAll('.emoji-category-content');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const category = tab.dataset.category;

                // Update tab styles
                tabs.forEach(t => t.classList.remove('active', 'bg-gray-700'));
                tab.classList.add('active', 'bg-gray-700');

                // Show/hide content
                contents.forEach(content => {
                    content.classList.toggle('hidden', content.dataset.category !== category);
                });
            });
        });

        // Emoji selection
        pickerElement.querySelectorAll('.emoji-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const emoji = btn.dataset.emoji;
                if (onEmojiSelect && emoji) {
                    onEmojiSelect(emoji);
                }
            });
        });

        // Close button
        const closeBtn = pickerElement.querySelector('.emoji-picker-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                if (onClose) onClose();
            });
        }

        // Click outside to close
        pickerElement.addEventListener('click', (e) => {
            if (e.target === pickerElement) {
                if (onClose) onClose();
            }
        });
    }
};

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = EmojiData;
}

// Also expose globally for non-module scripts
window.EmojiData = EmojiData;
