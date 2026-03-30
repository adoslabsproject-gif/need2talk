/**
 * Friend Request Actions - Profile Page
 *
 * ENTERPRISE: Gestisce azioni amicizia/blocco sulla pagina profilo
 * - Invia richiesta amicizia
 * - Rimuovi amicizia
 * - Blocca utente
 * - Sblocca utente
 *
 * @package need2talk/Social
 * @version 1.0.0
 */

class FriendRequestActions {
    constructor() {
        this.targetUserUuid = null;
        this.currentFriendshipStatus = null;
        this.actionButton = null;

        this.init();
    }

    /**
     * Inizializza il modulo
     */
    init() {
        // Estrai UUID utente target dalla URL
        const urlMatch = window.location.pathname.match(/\/u\/([a-f0-9\-]+)/);
        if (!urlMatch) {
            console.warn('[FriendRequestActions] No user UUID found in URL');
            return;
        }

        this.targetUserUuid = urlMatch[1];

        // ENTERPRISE FIX: Trova il bottone azione principale tramite ID univoco
        this.actionButton = document.getElementById('friendActionButton');

        if (this.actionButton) {
            // Aggiungi listener click per mostrare dropdown menu
            this.actionButton.addEventListener('click', (e) => this.handleButtonClick(e));
            console.log('[FriendRequestActions] Friend action button found and initialized');
        } else {
            console.warn('[FriendRequestActions] Friend action button NOT found (user might not be friend yet)');
        }

        // Se il bottone non esiste, cerca bottoni alternativi (Add Friend, ecc)
        this.setupAlternativeButtons();
    }

    /**
     * Setup bottoni alternativi (es. Add Friend, Accept Request, Block)
     */
    setupAlternativeButtons() {
        // Bottone "Aggiungi agli amici" (se esiste)
        const addFriendBtn = document.querySelector('[data-action="add-friend"]');
        if (addFriendBtn) {
            addFriendBtn.addEventListener('click', (e) => this.sendFriendRequest(e));
        }

        // Bottone "Accetta richiesta" (se esiste)
        const acceptBtn = document.querySelector('[data-action="accept-request"]');
        if (acceptBtn) {
            acceptBtn.addEventListener('click', (e) => this.acceptFriendRequest(e));
        }

        // ENTERPRISE V4: Bottone "Blocca utente" standalone (per non-amici)
        const blockBtn = document.getElementById('blockUserBtn');
        if (blockBtn) {
            blockBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.blockUser();
            });
            console.log('[FriendRequestActions] Block button found and initialized');
        }
    }

    /**
     * Gestisce il click sul bottone principale
     */
    async handleButtonClick(e) {
        e.preventDefault();

        // Mostra menu contestuale con opzioni
        this.showActionMenu(e.currentTarget);
    }

    /**
     * Mostra menu con opzioni "Rimuovi dalle amicizie" e "Blocca utente"
     * ENTERPRISE FIX V2: Mobile-first positioning
     */
    showActionMenu(button) {
        // Rimuovi menu esistente se presente
        const existingMenu = document.querySelector('.friend-action-menu');
        if (existingMenu) {
            existingMenu.remove();
        }

        // Crea menu (ENTERPRISE FIX: z-index 9999 + glassmorphism design)
        const menu = document.createElement('div');
        menu.className = 'friend-action-menu bg-gray-800/95 backdrop-blur-lg border border-purple-500/20 rounded-xl shadow-lg shadow-purple-500/10 overflow-hidden';
        menu.style.cssText = 'min-width: 220px; z-index: 9999;';

        // ENTERPRISE: Mostra sempre unfriend + block (glassmorphism style)
        menu.innerHTML = `
            <button class="w-full px-4 py-3 text-left hover:bg-purple-600/10 transition-all duration-200 text-gray-300 hover:text-white flex items-center group" data-action="unfriend">
                <svg class="w-5 h-5 mr-3 text-purple-400 group-hover:text-purple-300 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
                </svg>
                <span class="font-medium">Rimuovi dalle amicizie</span>
            </button>
            <div class="h-px bg-purple-500/20"></div>
            <button class="w-full px-4 py-3 text-left hover:bg-red-600/10 transition-all duration-200 text-gray-400 hover:text-red-400 flex items-center group" data-action="block">
                <svg class="w-5 h-5 mr-3 text-red-400 group-hover:text-red-300 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                </svg>
                <span class="font-medium">Blocca utente</span>
            </button>
        `;

        // ENTERPRISE FIX V2: Smart positioning (mobile-aware)
        const rect = button.getBoundingClientRect();
        const isMobile = window.innerWidth < 768;

        // Always use fixed positioning for predictable behavior
        menu.style.position = 'fixed';
        menu.style.top = (rect.bottom + 8) + 'px';

        if (isMobile) {
            // MOBILE: Center the menu horizontally
            const menuWidth = 220;
            const leftPos = Math.max(16, (window.innerWidth - menuWidth) / 2);
            menu.style.left = leftPos + 'px';
            menu.style.right = 'auto';
        } else {
            // DESKTOP: Align to right edge of button, but don't overflow left
            const rightPos = window.innerWidth - rect.right;
            const menuWidth = 220;
            if (rect.right < menuWidth) {
                // Button is too far left, align to left edge instead
                menu.style.left = rect.left + 'px';
                menu.style.right = 'auto';
            } else {
                menu.style.right = rightPos + 'px';
                menu.style.left = 'auto';
            }
        }

        document.body.appendChild(menu);

        // Event listeners per le opzioni
        menu.querySelector('[data-action="unfriend"]')?.addEventListener('click', () => {
            this.unfriendUser();
            menu.remove();
        });

        menu.querySelector('[data-action="block"]')?.addEventListener('click', () => {
            this.blockUser();
            menu.remove();
        });

        // Chiudi menu quando si clicca fuori
        setTimeout(() => {
            document.addEventListener('click', function closeMenu(e) {
                if (!menu.contains(e.target) && e.target !== button) {
                    menu.remove();
                    document.removeEventListener('click', closeMenu);
                }
            });
        }, 100);
    }

    /**
     * Verifica se l'utente target è già amico (da implementare con dati dal backend)
     */
    checkIfFriend() {
        // Placeholder: dovremmo avere questa info dal backend
        // Per ora controlliamo se il bottone dice "Amici" o simile
        const buttonText = this.actionButton?.textContent.toLowerCase() || '';
        return buttonText.includes('amici') || buttonText.includes('friends');
    }

    /**
     * Invia richiesta amicizia
     */
    async sendFriendRequest(e) {
        if (e) e.preventDefault();

        // ENTERPRISE FIX: Trova il bottone che ha scatenato l'evento (il vero bottone cliccato)
        const clickedButton = e ? e.currentTarget : document.querySelector('[data-action="add-friend"]');

        try {
            const response = await fetch('/social/friend-request/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    friend_uuid: this.targetUserUuid
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Richiesta di amicizia inviata!', 'success');

                // ENTERPRISE FIX: Aggiorna il bottone CORRETTO (quello cliccato, non this.actionButton)
                if (clickedButton) {
                    clickedButton.textContent = 'In attesa di risposta';
                    clickedButton.disabled = true;
                    clickedButton.classList.add('opacity-50', 'cursor-not-allowed');
                    clickedButton.classList.remove('hover:from-purple-600', 'hover:to-pink-600');
                }
            } else {
                this.showToast(data.errors?.join(', ') || 'Errore nell\'invio della richiesta', 'error');
            }
        } catch (error) {
            console.error('[FriendRequestActions] Send request failed:', error);
            this.showToast('Errore di rete. Riprova.', 'error');
        }
    }

    /**
     * Rimuovi amicizia (unfriend)
     */
    async unfriendUser() {
        if (!confirm('Vuoi davvero rimuovere questo utente dalle amicizie?')) {
            return;
        }

        try {
            const response = await fetch('/social/unfriend', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    friend_uuid: this.targetUserUuid
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Amicizia rimossa', 'success');
                // Ricarica pagina per aggiornare UI
                setTimeout(() => location.reload(), 1000);
            } else {
                this.showToast(data.errors?.join(', ') || 'Errore nella rimozione', 'error');
            }
        } catch (error) {
            console.error('[FriendRequestActions] Unfriend failed:', error);
            this.showToast('Errore di rete. Riprova.', 'error');
        }
    }

    /**
     * Blocca utente
     */
    async blockUser() {
        if (!confirm('Vuoi davvero bloccare questo utente? Non potrà più vedere i tuoi contenuti o interagire con te.')) {
            return;
        }

        try {
            const response = await fetch('/social/block', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    blocked_uuid: this.targetUserUuid
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Utente bloccato', 'success');
                // Redirect al feed dopo il blocco
                setTimeout(() => {
                    window.location.href = '/feed';
                }, 1500);
            } else {
                this.showToast(data.errors?.join(', ') || 'Errore nel blocco', 'error');
            }
        } catch (error) {
            console.error('[FriendRequestActions] Block user failed:', error);
            this.showToast('Errore di rete. Riprova.', 'error');
        }
    }

    /**
     * Mostra toast notification
     */
    showToast(message, type = 'info') {
        // Rimuovi toast esistente
        const existingToast = document.querySelector('.friend-action-toast');
        if (existingToast) {
            existingToast.remove();
        }

        const toast = document.createElement('div');
        toast.className = `friend-action-toast fixed top-20 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-0`;

        const colors = {
            success: 'bg-green-600 text-white',
            error: 'bg-red-600 text-white',
            info: 'bg-blue-600 text-white'
        };

        toast.className += ' ' + (colors[type] || colors.info);
        toast.textContent = message;

        document.body.appendChild(toast);

        // Auto-remove dopo 3 secondi
        setTimeout(() => {
            toast.style.transform = 'translateX(400px)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}

// Inizializza quando il DOM è pronto
document.addEventListener('DOMContentLoaded', () => {
    new FriendRequestActions();
});
