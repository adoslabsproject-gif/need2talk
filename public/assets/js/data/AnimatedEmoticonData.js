/**
 * ================================================================================
 * ANIMATED EMOTICON DATA - MSN STYLE EMOTICONS (ENTERPRISE GALAXY)
 * ================================================================================
 *
 * Emoticon animate stile MSN Messenger per le chat rooms.
 * Supporta shortcode testuali (es. :) ;) :D) e GIF animate.
 *
 * USAGE:
 * - Chat rooms only (Emotion rooms + User rooms)
 * - NOT for DM, comments, or journal
 *
 * FILE STRUCTURE:
 * /public/assets/img/emoticons/msn/{filename}.gif
 *
 * @version 1.0.0
 * @author need2talk.it - AI-Orchestrated Development
 * ================================================================================
 */

const AnimatedEmoticonData = {
    // Base path for emoticon images
    basePath: '/assets/img/emoticons/msn/',

    // File extension (GIF for animated, can also use WebP)
    extension: '.gif',

    // Category definitions
    categories: [
        { id: 'faces', label: 'Faccine', icon: '😊' },
        { id: 'love', label: 'Amore', icon: '❤️' },
        { id: 'fun', label: 'Divertimento', icon: '🎉' },
        { id: 'actions', label: 'Azioni', icon: '👍' },
        { id: 'objects', label: 'Oggetti', icon: '🎁' },
    ],

    /**
     * Emoticon definitions
     * Each emoticon has:
     * - id: unique identifier (also filename without extension)
     * - shortcodes: array of text shortcuts that trigger this emoticon
     * - name: display name
     * - category: category id
     */
    emoticons: [
        // ========== FACES ==========
        { id: 'smile', shortcodes: [':)', ':-)', ':=)'], name: 'Sorriso', category: 'faces' },
        { id: 'bigsmile', shortcodes: [':D', ':-D', ':=D', ':d'], name: 'Risata', category: 'faces' },
        { id: 'laugh', shortcodes: [':^)', 'LOL'], name: 'Risata grande', category: 'faces' },
        { id: 'wink', shortcodes: [';)', ';-)', ';=)'], name: 'Occhiolino', category: 'faces' },
        { id: 'tongue', shortcodes: [':P', ':-P', ':=P', ':p'], name: 'Linguaccia', category: 'faces' },
        { id: 'surprised', shortcodes: [':O', ':-O', ':=O', ':o'], name: 'Sorpreso', category: 'faces' },
        { id: 'sad', shortcodes: [':(', ':-(', ':=('], name: 'Triste', category: 'faces' },
        { id: 'cry', shortcodes: [":'(", ":'-("], name: 'Pianto', category: 'faces' },
        { id: 'angry', shortcodes: [':@', ':-@'], name: 'Arrabbiato', category: 'faces' },
        { id: 'confused', shortcodes: [':S', ':-S', ':s'], name: 'Confuso', category: 'faces' },
        { id: 'cool', shortcodes: ['(H)', '(h)', '8-)'], name: 'Figo', category: 'faces' },
        { id: 'nerd', shortcodes: ['8-|', '8|'], name: 'Nerd', category: 'faces' },
        { id: 'neutral', shortcodes: [':|', ':-|'], name: 'Neutro', category: 'faces' },
        { id: 'sleepy', shortcodes: ['|-)', 'I-)'], name: 'Assonnato', category: 'faces' },
        { id: 'devil', shortcodes: ['(6)'], name: 'Diavoletto', category: 'faces' },
        { id: 'angel', shortcodes: ['(A)', '(a)'], name: 'Angioletto', category: 'faces' },
        { id: 'sick', shortcodes: ['+o(', ':&'], name: 'Malato', category: 'faces' },
        { id: 'sweat', shortcodes: ['(:|'], name: 'Sudato', category: 'faces' },
        { id: 'thinking', shortcodes: ['*-)'], name: 'Pensieroso', category: 'faces' },
        { id: 'shhh', shortcodes: [':-#'], name: 'Shh', category: 'faces' },

        // ========== LOVE ==========
        { id: 'heart', shortcodes: ['(L)', '(l)', '<3'], name: 'Cuore', category: 'love' },
        { id: 'brokenheart', shortcodes: ['(U)', '(u)', '</3'], name: 'Cuore spezzato', category: 'love' },
        { id: 'kiss', shortcodes: [':*', ':-*', ':=*'], name: 'Bacio', category: 'love' },
        { id: 'hug', shortcodes: ['(})', '({)'], name: 'Abbraccio', category: 'love' },
        { id: 'inlove', shortcodes: ['(I)', '(i)'], name: 'Innamorato', category: 'love' },
        { id: 'blush', shortcodes: [':$', ':-$'], name: 'Imbarazzato', category: 'love' },
        { id: 'rose', shortcodes: ['(F)', '(f)', '@}->--'], name: 'Rosa', category: 'love' },
        { id: 'rosewilted', shortcodes: ['(W)', '(w)'], name: 'Rosa appassita', category: 'love' },

        // ========== FUN ==========
        { id: 'party', shortcodes: ['<:o)', '<O)'], name: 'Festa', category: 'fun' },
        { id: 'dance', shortcodes: ['\\o/', '\\O/'], name: 'Ballo', category: 'fun' },
        { id: 'rock', shortcodes: ['\\m/'], name: 'Rock', category: 'fun' },
        { id: 'star', shortcodes: ['(*)'], name: 'Stella', category: 'fun' },
        { id: 'sun', shortcodes: ['(#)'], name: 'Sole', category: 'fun' },
        { id: 'moon', shortcodes: ['(S)', '(s)'], name: 'Luna', category: 'fun' },
        { id: 'rainbow', shortcodes: ['(R)', '(r)'], name: 'Arcobaleno', category: 'fun' },
        { id: 'music', shortcodes: ['(8)'], name: 'Musica', category: 'fun' },
        { id: 'beer', shortcodes: ['(B)', '(b)'], name: 'Birra', category: 'fun' },
        { id: 'drink', shortcodes: ['(D)', '(d)'], name: 'Drink', category: 'fun' },
        { id: 'pizza', shortcodes: ['(pi)'], name: 'Pizza', category: 'fun' },
        { id: 'cake', shortcodes: ['(^)'], name: 'Torta', category: 'fun' },
        { id: 'coffee', shortcodes: ['(C)', '(c)'], name: 'Caffe', category: 'fun' },

        // ========== ACTIONS ==========
        { id: 'thumbsup', shortcodes: ['(Y)', '(y)', '+1'], name: 'Pollice su', category: 'actions' },
        { id: 'thumbsdown', shortcodes: ['(N)', '(n)', '-1'], name: 'Pollice giu', category: 'actions' },
        { id: 'clap', shortcodes: ['(clap)'], name: 'Applauso', category: 'actions' },
        { id: 'pray', shortcodes: ['(pray)', '(_)'], name: 'Preghiera', category: 'actions' },
        { id: 'wave', shortcodes: ['(hi)', '(HI)'], name: 'Ciao', category: 'actions' },
        { id: 'punch', shortcodes: ['*|', '*-|'], name: 'Pugno', category: 'actions' },
        { id: 'flex', shortcodes: ['(flex)'], name: 'Muscoli', category: 'actions' },
        { id: 'facepalm', shortcodes: ['(facepalm)'], name: 'Facepalm', category: 'actions' },
        { id: 'shrug', shortcodes: ['(shrug)'], name: 'Boh', category: 'actions' },
        { id: 'waiting', shortcodes: ['(wait)'], name: 'Attesa', category: 'actions' },
        { id: 'fingerscrossed', shortcodes: ['(yn)'], name: 'Dita incrociate', category: 'actions' },

        // ========== OBJECTS ==========
        { id: 'gift', shortcodes: ['(G)', '(g)'], name: 'Regalo', category: 'objects' },
        { id: 'camera', shortcodes: ['(P)', '(p)'], name: 'Foto', category: 'objects' },
        { id: 'phone', shortcodes: ['(T)', '(t)'], name: 'Telefono', category: 'objects' },
        { id: 'mail', shortcodes: ['(E)', '(e)'], name: 'Email', category: 'objects' },
        { id: 'clock', shortcodes: ['(O)', '(o)'], name: 'Orologio', category: 'objects' },
        { id: 'lightbulb', shortcodes: ['(idea)', '*!', ':idea:'], name: 'Idea', category: 'objects' },
        { id: 'film', shortcodes: ['(~)', '(film)'], name: 'Film', category: 'objects' },
        { id: 'money', shortcodes: ['(mo)', '($)'], name: 'Soldi', category: 'objects' },
        { id: 'plane', shortcodes: ['(ap)'], name: 'Aereo', category: 'objects' },
        { id: 'car', shortcodes: ['(au)'], name: 'Auto', category: 'objects' },
        { id: 'computer', shortcodes: ['(co)'], name: 'Computer', category: 'objects' },
        { id: 'gamepad', shortcodes: ['(xx)', '(game)'], name: 'Gioco', category: 'objects' },
    ],

    /**
     * Build shortcode-to-emoticon lookup map
     * Called once on load for O(1) lookups
     */
    _shortcodeMap: null,

    getShortcodeMap() {
        if (!this._shortcodeMap) {
            this._shortcodeMap = new Map();
            for (const emoticon of this.emoticons) {
                for (const code of emoticon.shortcodes) {
                    this._shortcodeMap.set(code.toLowerCase(), emoticon);
                }
            }
        }
        return this._shortcodeMap;
    },

    /**
     * Get emoticon by shortcode
     * @param {string} shortcode - Text shortcode (e.g., ':)', '(Y)')
     * @returns {object|null} Emoticon data or null
     */
    getByShortcode(shortcode) {
        return this.getShortcodeMap().get(shortcode.toLowerCase()) || null;
    },

    /**
     * Get emoticon image URL
     * @param {string} id - Emoticon ID
     * @returns {string} Full URL path
     */
    getImageUrl(id) {
        return this.basePath + id + this.extension;
    },

    /**
     * Get all emoticons for a category
     * @param {string} categoryId
     * @returns {array}
     */
    getCategory(categoryId) {
        return this.emoticons.filter(e => e.category === categoryId);
    },

    /**
     * Get all categories
     * @returns {array}
     */
    getCategories() {
        return this.categories;
    },

    /**
     * Convert shortcodes in text to <img> tags
     * @param {string} text - Message text
     * @returns {string} Text with shortcodes replaced by images
     */
    parseShortcodes(text) {
        if (!text) return text;

        // Build regex pattern from all shortcodes (escaped)
        const allShortcodes = [];
        for (const emoticon of this.emoticons) {
            for (const code of emoticon.shortcodes) {
                // Escape special regex characters
                const escaped = code.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                allShortcodes.push(escaped);
            }
        }

        // Sort by length (longest first) to match longer codes before shorter
        allShortcodes.sort((a, b) => b.length - a.length);

        if (allShortcodes.length === 0) return text;

        // Create regex with word boundaries where appropriate
        const pattern = new RegExp('(' + allShortcodes.join('|') + ')', 'gi');

        return text.replace(pattern, (match) => {
            const emoticon = this.getByShortcode(match);
            if (emoticon) {
                const url = this.getImageUrl(emoticon.id);
                return `<img src="${url}" alt="${emoticon.name}" title="${emoticon.name}" class="chat-emoticon animated" loading="lazy">`;
            }
            return match;
        });
    },

    /**
     * Generate HTML for emoticon picker (chat rooms only)
     * @returns {string} HTML string
     */
    generatePickerHTML() {
        const categoriesHTML = this.categories.map((cat, index) => `
            <button type="button"
                    class="emoticon-category-tab flex-shrink-0 px-3 py-2 text-lg rounded-lg hover:bg-gray-700 transition-all ${index === 0 ? 'active bg-gray-700' : ''}"
                    data-category="${cat.id}"
                    title="${cat.label}">
                ${cat.icon}
            </button>
        `).join('');

        const contentHTML = this.categories.map((cat, index) => {
            const emoticons = this.getCategory(cat.id);
            return `
                <div class="emoticon-category-content ${index > 0 ? 'hidden' : ''}" data-category="${cat.id}">
                    <h5 class="text-xs font-semibold text-gray-400 uppercase mb-2">${cat.label}</h5>
                    <div class="emoticon-grid">
                        ${emoticons.map(e => `
                            <button type="button"
                                    class="emoticon-btn hover:scale-110 transition-transform p-1 hover:bg-gray-800 rounded"
                                    data-emoticon-id="${e.id}"
                                    data-shortcode="${e.shortcodes[0]}"
                                    title="${e.name} ${e.shortcodes[0]}">
                                <img src="${this.getImageUrl(e.id)}"
                                     alt="${e.name}"
                                     class="w-8 h-8 object-contain"
                                     loading="lazy"
                                     onerror="this.style.display='none'">
                            </button>
                        `).join('')}
                    </div>
                </div>
            `;
        }).join('');

        return `
            <div class="emoticon-picker-container">
                <!-- Category Tabs -->
                <div class="flex gap-1 p-2 border-b border-gray-700 overflow-x-auto">
                    ${categoriesHTML}
                </div>

                <!-- Emoticon Grid -->
                <div class="p-3 max-h-64 overflow-y-auto">
                    ${contentHTML}
                </div>

                <!-- Info Footer -->
                <div class="px-3 py-2 border-t border-gray-700 text-xs text-gray-500">
                    Clicca o digita shortcode (es. :) ;) :D)
                </div>
            </div>
        `;
    },

    /**
     * Initialize picker events
     * @param {HTMLElement} pickerElement
     * @param {function} onSelect - Callback (receives shortcode)
     */
    initPickerEvents(pickerElement, onSelect) {
        if (!pickerElement) return;

        // Category tab switching
        const tabs = pickerElement.querySelectorAll('.emoticon-category-tab');
        const contents = pickerElement.querySelectorAll('.emoticon-category-content');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const category = tab.dataset.category;
                tabs.forEach(t => t.classList.remove('active', 'bg-gray-700'));
                tab.classList.add('active', 'bg-gray-700');
                contents.forEach(content => {
                    content.classList.toggle('hidden', content.dataset.category !== category);
                });
            });
        });

        // Emoticon selection
        pickerElement.querySelectorAll('.emoticon-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const shortcode = btn.dataset.shortcode;
                if (onSelect && shortcode) {
                    onSelect(shortcode);
                }
            });
        });
    },

    /**
     * Check if any GIF files exist (for feature detection)
     * @returns {Promise<boolean>}
     */
    async checkAvailability() {
        try {
            const response = await fetch(this.getImageUrl('smile'), { method: 'HEAD' });
            return response.ok;
        } catch {
            return false;
        }
    }
};

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AnimatedEmoticonData;
}

window.AnimatedEmoticonData = AnimatedEmoticonData;
