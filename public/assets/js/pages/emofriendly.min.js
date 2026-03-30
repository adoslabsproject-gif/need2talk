/**
 * EMOFRIENDLY PAGE - ENTERPRISE GALAXY
 *
 * Gestisce la pagina Anime Affini:
 * - Caricamento suggerimenti via API
 * - Rendering cards nei carousel
 * - Azioni: Richiedi amicizia, Rimuovi, Blocca
 * - Optimistic UI updates
 * - Toast notifications
 *
 * @version 1.0.0
 */

const EmoFriendlyManager = (function() {
    'use strict';

    // ==================== CONFIGURATION ====================
    const CONFIG = {
        API_BASE: '/api/emofriendly',
        CSRF_TOKEN: document.querySelector('meta[name="csrf-token"]')?.content || '',
        DEFAULT_AVATAR: '/assets/img/default-avatar.png',
        TOAST_DURATION: 3000,
    };

    // ==================== STATE ====================
    let suggestions = { affine: [], complementary: [] };
    let isLoading = false;

    // ==================== INITIALIZATION ====================

    /**
     * Initialize the page
     */
    function init() {
        loadSuggestions();
    }

    // ==================== API METHODS ====================

    /**
     * Load suggestions from API
     */
    async function loadSuggestions() {
        if (isLoading) return;
        isLoading = true;

        try {
            const response = await fetch(`${CONFIG.API_BASE}/suggestions`, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                suggestions = data.data;
                renderCarousel('affine', suggestions.affine);
                renderCarousel('complementary', suggestions.complementary);
            } else {
                throw new Error(data.errors?.[0] || 'Errore sconosciuto');
            }

        } catch (error) {
            console.error('[EmoFriendly] Load failed:', error);
            showError('affine');
            showError('complementary');
        } finally {
            isLoading = false;
        }
    }

    /**
     * Send friend request
     * @param {string} userUuid - UUID dell'utente target
     */
    async function sendFriendRequest(userUuid) {
        const btn = document.querySelector(`[data-user-uuid="${userUuid}"] .emofriendly__btn--primary`);
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="emofriendly__loading-spinner" style="width:16px;height:16px;border-width:2px;"></span>';
        }

        try {
            const response = await fetch(`${CONFIG.API_BASE}/friend-request`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CONFIG.CSRF_TOKEN,
                },
                body: JSON.stringify({ user_uuid: userUuid })
            });

            const data = await response.json();

            if (data.success) {
                removeCard(userUuid);
                showToast('Richiesta inviata!', 'success');
            } else {
                showToast(data.errors?.[0] || 'Errore', 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = getSendButtonContent();
                }
            }

        } catch (error) {
            console.error('[EmoFriendly] Request failed:', error);
            showToast('Errore di connessione', 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = getSendButtonContent();
            }
        }
    }

    /**
     * Dismiss suggestion (remove or block)
     * @param {string} userUuid - UUID dell'utente
     * @param {string} type - 'remove' o 'block'
     */
    async function dismiss(userUuid, type) {
        try {
            const response = await fetch(`${CONFIG.API_BASE}/dismiss`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CONFIG.CSRF_TOKEN,
                },
                body: JSON.stringify({ user_uuid: userUuid, type: type })
            });

            if (response.ok) {
                removeCard(userUuid);
                showToast(type === 'block' ? 'Utente bloccato' : 'Rimosso', 'info');
            } else {
                const data = await response.json();
                showToast(data.errors?.[0] || 'Errore', 'error');
            }

        } catch (error) {
            console.error('[EmoFriendly] Dismiss failed:', error);
            showToast('Errore di connessione', 'error');
        }
    }

    // ==================== RENDERING ====================

    /**
     * Render carousel with user cards
     * @param {string} type - 'affine' o 'complementary'
     * @param {Array} users - Array di utenti
     */
    function renderCarousel(type, users) {
        const container = document.getElementById(`carousel-${type}`);
        const loading = document.getElementById(`loading-${type}`);

        if (!container) return;

        // Hide loading
        if (loading) {
            loading.style.display = 'none';
        }

        // Clear existing cards (keep loading element)
        container.querySelectorAll('.emofriendly__card, .emofriendly__empty').forEach(el => el.remove());

        // Show empty state if no users
        if (!users || users.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'emofriendly__empty';
            empty.innerHTML = `
                <div class="emofriendly__empty-icon">${type === 'affine' ? '🪞' : '☯️'}</div>
                <p class="emofriendly__empty-text">Nessun suggerimento disponibile</p>
                <p class="emofriendly__empty-subtext">Interagisci di più con i post per ricevere suggerimenti!</p>
            `;
            container.appendChild(empty);
            return;
        }

        // Create cards
        users.forEach(user => {
            const card = createCard(user);
            container.appendChild(card);
        });
    }

    /**
     * Create a user card element
     * @param {Object} user - User data
     * @returns {HTMLElement}
     */
    function createCard(user) {
        const card = document.createElement('div');
        card.className = 'emofriendly__card';
        card.dataset.userUuid = user.uuid;

        // Top emotions (max 3)
        const topEmotions = (user.top_emotions || []).slice(0, 3).map(e =>
            `<span class="emofriendly__card-emotion" title="${escapeHtml(e.name)}">${e.icon}</span>`
        ).join('');

        // Compatibility percentage
        const compatPercent = Math.round((user.similarity_score || 0) * 100);

        card.innerHTML = `
            <div class="emofriendly__card-header">
                <a href="/u/${escapeHtml(user.uuid)}" class="emofriendly__card-avatar-link">
                    <img src="${escapeHtml(user.avatar_url || CONFIG.DEFAULT_AVATAR)}"
                         alt="${escapeHtml(user.nickname)}"
                         class="emofriendly__card-avatar"
                         onerror="this.src='${CONFIG.DEFAULT_AVATAR}'">
                </a>
                <div class="emofriendly__card-info">
                    <a href="/u/${escapeHtml(user.uuid)}" class="emofriendly__card-nickname-link">
                        <div class="emofriendly__card-nickname">@${escapeHtml(user.nickname)}</div>
                    </a>
                    <div class="emofriendly__card-score">${compatPercent}% compatibilità</div>
                </div>
            </div>

            ${topEmotions ? `<div class="emofriendly__card-emotions">${topEmotions}</div>` : ''}

            <div class="emofriendly__card-actions">
                <button type="button"
                        class="emofriendly__btn emofriendly__btn--primary"
                        onclick="EmoFriendlyManager.sendRequest('${escapeHtml(user.uuid)}')"
                        title="Invia richiesta di amicizia">
                    ${getSendButtonContent()}
                </button>
                <button type="button"
                        class="emofriendly__btn emofriendly__btn--secondary"
                        onclick="EmoFriendlyManager.dismiss('${escapeHtml(user.uuid)}', 'remove')"
                        title="Rimuovi suggerimento">
                    <svg class="emofriendly__btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
                <button type="button"
                        class="emofriendly__btn emofriendly__btn--danger"
                        onclick="EmoFriendlyManager.dismiss('${escapeHtml(user.uuid)}', 'block')"
                        title="Blocca utente">
                    <svg class="emofriendly__btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                    </svg>
                </button>
            </div>
        `;

        return card;
    }

    /**
     * Get send button content (icon + optional text)
     */
    function getSendButtonContent() {
        return `
            <svg class="emofriendly__btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
            </svg>
            <span>Richiedi</span>
        `;
    }

    /**
     * Remove a card with animation
     * @param {string} userUuid
     */
    function removeCard(userUuid) {
        const card = document.querySelector(`[data-user-uuid="${userUuid}"]`);
        if (card) {
            card.classList.add('emofriendly__card--removing');
            setTimeout(() => {
                card.remove();

                // Check if carousel is now empty
                checkEmptyCarousels();
            }, 300);
        }
    }

    /**
     * Check if carousels are empty and show empty state
     */
    function checkEmptyCarousels() {
        ['affine', 'complementary'].forEach(type => {
            const container = document.getElementById(`carousel-${type}`);
            if (container) {
                const cards = container.querySelectorAll('.emofriendly__card');
                const empty = container.querySelector('.emofriendly__empty');

                if (cards.length === 0 && !empty) {
                    const emptyEl = document.createElement('div');
                    emptyEl.className = 'emofriendly__empty';
                    emptyEl.innerHTML = `
                        <div class="emofriendly__empty-icon">${type === 'affine' ? '🪞' : '☯️'}</div>
                        <p class="emofriendly__empty-text">Nessun altro suggerimento</p>
                    `;
                    container.appendChild(emptyEl);
                }
            }
        });
    }

    /**
     * Show error state in carousel
     * @param {string} type
     */
    function showError(type) {
        const container = document.getElementById(`carousel-${type}`);
        const loading = document.getElementById(`loading-${type}`);

        if (loading) loading.style.display = 'none';

        if (container) {
            container.querySelectorAll('.emofriendly__card, .emofriendly__empty').forEach(el => el.remove());

            const error = document.createElement('div');
            error.className = 'emofriendly__empty';
            error.innerHTML = `
                <div class="emofriendly__empty-icon">⚠️</div>
                <p class="emofriendly__empty-text">Errore nel caricamento</p>
                <button type="button"
                        class="emofriendly__btn emofriendly__btn--secondary mt-4"
                        onclick="EmoFriendlyManager.reload()"
                        style="display:inline-flex;width:auto;padding:0.5rem 1rem;">
                    Riprova
                </button>
            `;
            container.appendChild(error);
        }
    }

    // ==================== TOAST NOTIFICATIONS ====================

    /**
     * Show toast notification
     * @param {string} message
     * @param {string} type - 'success', 'error', 'info'
     */
    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `emofriendly__toast emofriendly__toast--${type}`;
        toast.textContent = message;

        container.appendChild(toast);

        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('emofriendly__toast--visible');
        });

        // Remove after duration
        setTimeout(() => {
            toast.classList.remove('emofriendly__toast--visible');
            setTimeout(() => toast.remove(), 300);
        }, CONFIG.TOAST_DURATION);
    }

    // ==================== UTILITIES ====================

    /**
     * Escape HTML to prevent XSS
     * @param {string} text
     * @returns {string}
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ==================== INITIALIZATION ====================

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ==================== PUBLIC API ====================
    return {
        sendRequest: sendFriendRequest,
        dismiss: dismiss,
        reload: loadSuggestions,
    };

})();
