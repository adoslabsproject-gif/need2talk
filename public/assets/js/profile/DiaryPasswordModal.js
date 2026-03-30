/**
 * DiaryPasswordModal - Setup & Unlock UI for E2E Diary
 *
 * SCREENS:
 * 1. Setup (first time): Create diary password + confirm + login password
 * 2. Unlock (every session): Enter diary password + remember checkbox
 * 3. Locked state: Show lock icon, click to unlock
 *
 * CSS: Uses BEM scoped classes (.diary-password-modal__*)
 * NO GLOBAL CSS - all styles scoped to prevent conflicts
 *
 * @version 4.2 - True E2E Diary
 */
class DiaryPasswordModal {
    /**
     * @param {DiaryEncryptionService} encryptionService - Encryption service instance
     * @param {Function} onUnlock - Callback when diary is unlocked
     */
    constructor(encryptionService, onUnlock = null) {
        this.encryptionService = encryptionService;
        this.onUnlock = onUnlock;
        this.modalElement = null;
        this.isShowing = false;
    }

    /**
     * Show setup modal (first time diary password creation)
     */
    showSetup() {
        if (this.isShowing) return;

        const html = `
            <div class="diary-password-modal" id="diaryPasswordModal">
                <div class="diary-password-modal__backdrop"></div>
                <div class="diary-password-modal__content">
                    <button type="button" class="diary-password-modal__close" id="diaryModalClose" aria-label="Chiudi">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                    <div class="diary-password-modal__header">
                        <div class="diary-password-modal__icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </div>
                        <h2 class="diary-password-modal__title">Proteggi il tuo Diario</h2>
                        <p class="diary-password-modal__subtitle">Crea una password per proteggere i tuoi pensieri pi&ugrave; intimi</p>
                    </div>

                    <div class="diary-password-modal__body">
                        <div class="diary-password-modal__warning">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                                <line x1="12" y1="9" x2="12" y2="13"></line>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                            <span><strong>ATTENZIONE:</strong> Se dimentichi questa password, i tuoi dati del diario saranno persi PER SEMPRE. Non c'&egrave; modo di recuperarli.</span>
                        </div>

                        <form id="diarySetupForm" class="diary-password-modal__form">
                            <div class="diary-password-modal__field">
                                <label class="diary-password-modal__label" for="diaryPassword">Password Diario</label>
                                <div class="diary-password-modal__input-wrapper">
                                    <input
                                        type="password"
                                        id="diaryPassword"
                                        class="diary-password-modal__input"
                                        placeholder="Minimo 8 caratteri"
                                        minlength="8"
                                        required
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="diary-password-modal__toggle-password" data-target="diaryPassword" aria-label="Mostra password">
                                        <svg class="diary-password-modal__eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg class="diary-password-modal__eye-off-icon" style="display:none;" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                                <div class="diary-password-modal__strength" id="passwordStrength"></div>
                            </div>

                            <div class="diary-password-modal__field">
                                <label class="diary-password-modal__label" for="diaryPasswordConfirm">Conferma Password</label>
                                <div class="diary-password-modal__input-wrapper">
                                    <input
                                        type="password"
                                        id="diaryPasswordConfirm"
                                        class="diary-password-modal__input"
                                        placeholder="Ripeti la password"
                                        minlength="8"
                                        required
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="diary-password-modal__toggle-password" data-target="diaryPasswordConfirm" aria-label="Mostra password">
                                        <svg class="diary-password-modal__eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg class="diary-password-modal__eye-off-icon" style="display:none;" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="diary-password-modal__divider">
                                <span>Verifica identit&agrave;</span>
                            </div>

                            <div class="diary-password-modal__field">
                                <label class="diary-password-modal__label" for="loginPassword">Password di Login</label>
                                <div class="diary-password-modal__input-wrapper">
                                    <input
                                        type="password"
                                        id="loginPassword"
                                        class="diary-password-modal__input"
                                        placeholder="La tua password di accesso"
                                        required
                                        autocomplete="current-password"
                                    >
                                    <button type="button" class="diary-password-modal__toggle-password" data-target="loginPassword" aria-label="Mostra password">
                                        <svg class="diary-password-modal__eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg class="diary-password-modal__eye-off-icon" style="display:none;" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                                <p class="diary-password-modal__hint">Inserisci la password con cui accedi a need2talk</p>
                            </div>

                            <div class="diary-password-modal__error" id="setupError"></div>

                            <div class="diary-password-modal__footer">
                                <button type="submit" class="diary-password-modal__btn diary-password-modal__btn--primary" id="setupBtn">
                                    <span class="diary-password-modal__btn-text">Proteggi Diario</span>
                                    <span class="diary-password-modal__btn-loading" style="display:none;">
                                        <svg class="diary-password-modal__spinner" width="20" height="20" viewBox="0 0 24 24">
                                            <circle class="diary-password-modal__spinner-path" cx="12" cy="12" r="10" fill="none" stroke-width="3"></circle>
                                        </svg>
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        this._injectModal(html);
        this._bindSetupEvents();
    }

    /**
     * Show unlock modal (returning user)
     */
    showUnlock() {
        if (this.isShowing) return;

        const html = `
            <div class="diary-password-modal" id="diaryPasswordModal">
                <div class="diary-password-modal__backdrop"></div>
                <div class="diary-password-modal__content diary-password-modal__content--unlock">
                    <button type="button" class="diary-password-modal__close" id="diaryModalClose" aria-label="Chiudi">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                    <div class="diary-password-modal__header">
                        <div class="diary-password-modal__icon diary-password-modal__icon--locked">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </div>
                        <h2 class="diary-password-modal__title">Sblocca Diario</h2>
                        <p class="diary-password-modal__subtitle">Inserisci la password per accedere ai tuoi pensieri</p>
                    </div>

                    <div class="diary-password-modal__body">
                        <form id="diaryUnlockForm" class="diary-password-modal__form">
                            <div class="diary-password-modal__field">
                                <label class="diary-password-modal__label" for="unlockPassword">Password Diario</label>
                                <div class="diary-password-modal__input-wrapper">
                                    <input
                                        type="password"
                                        id="unlockPassword"
                                        class="diary-password-modal__input"
                                        placeholder="Inserisci la password del diario"
                                        required
                                        autocomplete="current-password"
                                        autofocus
                                    >
                                    <button type="button" class="diary-password-modal__toggle-password" data-target="unlockPassword" aria-label="Mostra password">
                                        <svg class="diary-password-modal__eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg class="diary-password-modal__eye-off-icon" style="display:none;" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="diary-password-modal__remember">
                                <label class="diary-password-modal__checkbox-label">
                                    <input
                                        type="checkbox"
                                        id="rememberDevice"
                                        class="diary-password-modal__checkbox"
                                    >
                                    <span class="diary-password-modal__checkbox-custom"></span>
                                    <span>Ricorda questo dispositivo per 30 giorni</span>
                                </label>
                            </div>

                            <div class="diary-password-modal__error" id="unlockError"></div>

                            <div class="diary-password-modal__footer">
                                <button type="submit" class="diary-password-modal__btn diary-password-modal__btn--primary" id="unlockBtn">
                                    <span class="diary-password-modal__btn-text">Sblocca</span>
                                    <span class="diary-password-modal__btn-loading" style="display:none;">
                                        <svg class="diary-password-modal__spinner" width="20" height="20" viewBox="0 0 24 24">
                                            <circle class="diary-password-modal__spinner-path" cx="12" cy="12" r="10" fill="none" stroke-width="3"></circle>
                                        </svg>
                                    </span>
                                </button>
                            </div>
                        </form>

                        <div class="diary-password-modal__forgot">
                            <p class="diary-password-modal__forgot-text">Password dimenticata?</p>
                            <p class="diary-password-modal__forgot-warning">Purtroppo non c'&egrave; modo di recuperarla. I dati crittografati sono persi per sempre.</p>
                        </div>
                    </div>
                </div>
            </div>
        `;

        this._injectModal(html);
        this._bindUnlockEvents();
    }

    /**
     * Show locked state placeholder in container
     * @param {HTMLElement} container - Container to show locked state in
     */
    showLockedState(container) {
        const html = `
            <div class="diary-password-modal__locked-state" id="diaryLockedState">
                <div class="diary-password-modal__locked-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <h3 class="diary-password-modal__locked-title">Diario Protetto</h3>
                <p class="diary-password-modal__locked-text">Inserisci la password per accedere ai tuoi pensieri</p>
                <button type="button" class="diary-password-modal__btn diary-password-modal__btn--primary" id="unlockDiaryBtn">
                    Sblocca Diario
                </button>
            </div>
        `;

        container.innerHTML = html;

        const unlockBtn = document.getElementById('unlockDiaryBtn');
        if (unlockBtn) {
            unlockBtn.addEventListener('click', () => this.showUnlock());
        }
    }

    /**
     * Check diary status and show appropriate UI
     * @param {HTMLElement} container - Container for journal content
     * @returns {Promise<boolean>} - True if diary is unlocked
     */
    async checkAndShow(container) {
        try {
            // Check if already unlocked in session
            if (this.encryptionService.isUnlocked()) {
                return true;
            }

            // Check server status
            const status = await this.encryptionService.checkSetupStatus();

            if (!status.success) {
                console.error('[DiaryModal] Status check failed:', status.error);
                return false;
            }

            // v4.2 ENTERPRISE: OAuth users must set account password first
            // This is MANDATORY - they cannot proceed without it
            if (status.requires_account_password) {
                console.log('[DiaryModal] OAuth user needs to set account password first');
                this.showAccountPasswordSetup(container);
                return false;
            }

            if (status.setup_required) {
                // First time - show setup modal
                this.showSetup();
                return false;
            }

            // Try auto-unlock with device token
            const autoResult = await this.encryptionService.tryAutoUnlock(null);

            if (autoResult.success) {
                return true;
            }

            // Need password - show unlock modal or locked state
            if (container) {
                this.showLockedState(container);
            } else {
                this.showUnlock();
            }

            return false;

        } catch (e) {
            console.error('[DiaryModal] Check failed:', e);
            return false;
        }
    }

    /**
     * Show account password setup modal (for OAuth users)
     *
     * MANDATORY for OAuth users (Google) who don't have an account password.
     * They must set one before creating diary password to enable identity verification.
     *
     * @param {HTMLElement} container - Container to show locked state if needed
     */
    showAccountPasswordSetup(container = null) {
        if (this.isShowing) return;

        // Store container reference for retry after password setup
        this._pendingContainer = container;

        const html = `
            <div class="diary-password-modal" id="diaryPasswordModal">
                <div class="diary-password-modal__backdrop"></div>
                <div class="diary-password-modal__content">
                    <button type="button" class="diary-password-modal__close" id="diaryModalClose" aria-label="Chiudi">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                    <div class="diary-password-modal__header">
                        <div class="diary-password-modal__icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                <path d="M9 12l2 2 4-4"></path>
                            </svg>
                        </div>
                        <h2 class="diary-password-modal__title">Imposta Password Account</h2>
                        <p class="diary-password-modal__subtitle">Per proteggere il tuo diario, devi prima impostare una password per il tuo account</p>
                    </div>

                    <div class="diary-password-modal__body">
                        <div class="diary-password-modal__warning" style="background: rgba(102, 126, 234, 0.15); border-color: rgba(102, 126, 234, 0.3); color: #a5b4fc;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="16" x2="12" y2="12"></line>
                                <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                            <span>Hai effettuato l'accesso con Google. Per una maggiore sicurezza, imposta una password per il tuo account need2talk.</span>
                        </div>

                        <form id="accountPasswordSetupForm" class="diary-password-modal__form">
                            <div class="diary-password-modal__field">
                                <label class="diary-password-modal__label" for="newAccountPassword">Nuova Password</label>
                                <div class="diary-password-modal__input-wrapper">
                                    <input
                                        type="password"
                                        id="newAccountPassword"
                                        class="diary-password-modal__input"
                                        placeholder="Minimo 8 caratteri"
                                        minlength="8"
                                        required
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="diary-password-modal__toggle-password" data-target="newAccountPassword" aria-label="Mostra password">
                                        <svg class="diary-password-modal__eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg class="diary-password-modal__eye-off-icon" style="display:none;" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                                <div class="diary-password-modal__strength" id="accountPasswordStrength"></div>
                            </div>

                            <div class="diary-password-modal__field">
                                <label class="diary-password-modal__label" for="confirmAccountPassword">Conferma Password</label>
                                <div class="diary-password-modal__input-wrapper">
                                    <input
                                        type="password"
                                        id="confirmAccountPassword"
                                        class="diary-password-modal__input"
                                        placeholder="Ripeti la password"
                                        minlength="8"
                                        required
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="diary-password-modal__toggle-password" data-target="confirmAccountPassword" aria-label="Mostra password">
                                        <svg class="diary-password-modal__eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg class="diary-password-modal__eye-off-icon" style="display:none;" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="diary-password-modal__error" id="accountSetupError"></div>

                            <div class="diary-password-modal__footer">
                                <button type="submit" class="diary-password-modal__btn diary-password-modal__btn--primary" id="accountSetupBtn">
                                    <span class="diary-password-modal__btn-text">Imposta Password</span>
                                    <span class="diary-password-modal__btn-loading" style="display:none;">
                                        <svg class="diary-password-modal__spinner" width="20" height="20" viewBox="0 0 24 24">
                                            <circle class="diary-password-modal__spinner-path" cx="12" cy="12" r="10" fill="none" stroke-width="3"></circle>
                                        </svg>
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        `;

        this._injectModal(html);
        this._bindAccountPasswordSetupEvents();
    }

    /**
     * Bind account password setup form events
     * @private
     */
    _bindAccountPasswordSetupEvents() {
        const form = document.getElementById('accountPasswordSetupForm');
        const passwordInput = document.getElementById('newAccountPassword');
        const confirmInput = document.getElementById('confirmAccountPassword');
        const strengthDiv = document.getElementById('accountPasswordStrength');
        const errorDiv = document.getElementById('accountSetupError');
        const submitBtn = document.getElementById('accountSetupBtn');

        // Password strength indicator
        if (passwordInput && strengthDiv) {
            passwordInput.addEventListener('input', () => {
                const strength = this._calculatePasswordStrength(passwordInput.value);
                strengthDiv.className = 'diary-password-modal__strength';
                strengthDiv.classList.add(`diary-password-modal__strength--${strength.level}`);
                strengthDiv.textContent = strength.text;
            });
        }

        // Form submission
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const newPassword = passwordInput.value;
                const confirmPassword = confirmInput.value;

                // Validate
                if (newPassword.length < 8) {
                    this._showError(errorDiv, 'La password deve essere di almeno 8 caratteri');
                    return;
                }

                if (newPassword !== confirmPassword) {
                    this._showError(errorDiv, 'Le password non coincidono');
                    return;
                }

                // Show loading
                this._setLoading(submitBtn, true);
                this._hideError(errorDiv);

                try {
                    // Call existing endpoint: POST /settings/security/password
                    const response = await fetch('/settings/security/password', {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-Token': this._getCsrfToken(),
                        },
                        body: JSON.stringify({
                            current_password: '', // OAuth users don't have one
                            new_password: newPassword,
                            confirm_password: confirmPassword,
                        }),
                    });

                    const data = await response.json();

                    this._setLoading(submitBtn, false);

                    if (!data.success) {
                        const errorMsg = data.errors?.join(', ') || data.message || 'Errore durante il salvataggio';
                        this._showError(errorDiv, errorMsg);
                        return;
                    }

                    // Success - close modal and restart diary flow
                    console.log('[DiaryModal] Account password set successfully, restarting diary flow');
                    this.close();

                    // Re-run checkAndShow to continue with diary setup
                    setTimeout(async () => {
                        const unlocked = await this.checkAndShow(this._pendingContainer);
                        if (unlocked && this.onUnlock) {
                            this.onUnlock();
                        }
                    }, 300);

                } catch (err) {
                    this._setLoading(submitBtn, false);
                    console.error('[DiaryModal] Account password setup failed:', err);
                    this._showError(errorDiv, 'Errore di connessione. Riprova.');
                }
            });
        }

        // Focus password input
        if (passwordInput) {
            setTimeout(() => passwordInput.focus(), 100);
        }
    }

    /**
     * Get CSRF token from meta tag or cookie
     * @private
     */
    _getCsrfToken() {
        // Try meta tag first
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            return meta.getAttribute('content');
        }

        // Try cookie
        const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        if (match) {
            return decodeURIComponent(match[1]);
        }

        return '';
    }

    /**
     * Close modal
     */
    close() {
        if (this.modalElement) {
            this.modalElement.classList.add('diary-password-modal--closing');
            setTimeout(() => {
                this.modalElement.remove();
                this.modalElement = null;
                this.isShowing = false;
            }, 200);
        }
    }

    // ===== PRIVATE METHODS =====

    /**
     * Inject modal HTML into DOM
     * @private
     */
    _injectModal(html) {
        // Remove existing modal if any
        const existing = document.getElementById('diaryPasswordModal');
        if (existing) {
            existing.remove();
        }

        // Inject toggle password styles (once)
        this._injectToggleStyles();

        // Create and inject
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        this.modalElement = wrapper.firstElementChild;
        document.body.appendChild(this.modalElement);
        this.isShowing = true;

        // Trigger animation
        requestAnimationFrame(() => {
            this.modalElement.classList.add('diary-password-modal--visible');
        });

        // Bind password toggle events
        this._bindPasswordToggleEvents();

        // Bind close button (X)
        const closeBtn = this.modalElement.querySelector('.diary-password-modal__close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.close());
        }

        // Close on backdrop click
        const backdrop = this.modalElement.querySelector('.diary-password-modal__backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop) {
                    this.close();
                }
            });
        }

        // Close on Escape key
        document.addEventListener('keydown', this._handleEscape.bind(this));
    }

    /**
     * Inject CSS styles for password toggle (once)
     * @private
     */
    _injectToggleStyles() {
        if (document.getElementById('diary-password-toggle-styles')) {
            return; // Already injected
        }

        const styles = document.createElement('style');
        styles.id = 'diary-password-toggle-styles';
        styles.textContent = `
            .diary-password-modal__content {
                position: relative;
            }
            .diary-password-modal__close {
                position: absolute;
                top: 16px;
                right: 16px;
                background: rgba(255, 255, 255, 0.1);
                border: none;
                border-radius: 50%;
                width: 36px;
                height: 36px;
                cursor: pointer;
                color: rgba(255, 255, 255, 0.6);
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10;
            }
            .diary-password-modal__close:hover {
                background: rgba(255, 255, 255, 0.2);
                color: #fff;
            }
            .diary-password-modal__close:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.5);
            }
            .diary-password-modal__close svg {
                width: 20px;
                height: 20px;
            }
            .diary-password-modal__input-wrapper {
                position: relative;
                display: flex;
                align-items: center;
            }
            .diary-password-modal__input-wrapper .diary-password-modal__input {
                padding-right: 44px;
            }
            .diary-password-modal__toggle-password {
                position: absolute;
                right: 12px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                padding: 4px;
                cursor: pointer;
                color: rgba(255, 255, 255, 0.5);
                transition: color 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .diary-password-modal__toggle-password:hover {
                color: rgba(255, 255, 255, 0.8);
            }
            .diary-password-modal__toggle-password:focus {
                outline: none;
                color: rgba(255, 255, 255, 0.8);
            }
            .diary-password-modal__toggle-password svg {
                width: 20px;
                height: 20px;
            }
        `;
        document.head.appendChild(styles);
    }

    /**
     * Bind password toggle events
     * @private
     */
    _bindPasswordToggleEvents() {
        const toggleButtons = this.modalElement.querySelectorAll('.diary-password-modal__toggle-password');

        toggleButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = btn.getAttribute('data-target');
                const input = document.getElementById(targetId);

                if (!input) return;

                const eyeIcon = btn.querySelector('.diary-password-modal__eye-icon');
                const eyeOffIcon = btn.querySelector('.diary-password-modal__eye-off-icon');

                if (input.type === 'password') {
                    input.type = 'text';
                    if (eyeIcon) eyeIcon.style.display = 'none';
                    if (eyeOffIcon) eyeOffIcon.style.display = 'block';
                    btn.setAttribute('aria-label', 'Nascondi password');
                } else {
                    input.type = 'password';
                    if (eyeIcon) eyeIcon.style.display = 'block';
                    if (eyeOffIcon) eyeOffIcon.style.display = 'none';
                    btn.setAttribute('aria-label', 'Mostra password');
                }
            });
        });
    }

    /**
     * Handle Escape key
     * @private
     */
    _handleEscape(e) {
        if (e.key === 'Escape' && this.isShowing) {
            this.close();
            document.removeEventListener('keydown', this._handleEscape);
        }
    }

    /**
     * Bind setup form events
     * @private
     */
    _bindSetupEvents() {
        const form = document.getElementById('diarySetupForm');
        const passwordInput = document.getElementById('diaryPassword');
        const confirmInput = document.getElementById('diaryPasswordConfirm');
        const strengthDiv = document.getElementById('passwordStrength');
        const errorDiv = document.getElementById('setupError');
        const submitBtn = document.getElementById('setupBtn');

        // Password strength indicator
        if (passwordInput && strengthDiv) {
            passwordInput.addEventListener('input', () => {
                const strength = this._calculatePasswordStrength(passwordInput.value);
                strengthDiv.className = 'diary-password-modal__strength';
                strengthDiv.classList.add(`diary-password-modal__strength--${strength.level}`);
                strengthDiv.textContent = strength.text;
            });
        }

        // Form submission
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const diaryPassword = passwordInput.value;
                const confirmPassword = confirmInput.value;
                const loginPassword = document.getElementById('loginPassword').value;

                // Validate
                if (diaryPassword.length < 8) {
                    this._showError(errorDiv, 'La password deve essere di almeno 8 caratteri');
                    return;
                }

                if (diaryPassword !== confirmPassword) {
                    this._showError(errorDiv, 'Le password non coincidono');
                    return;
                }

                // Show loading
                this._setLoading(submitBtn, true);
                this._hideError(errorDiv);

                // Setup password
                const result = await this.encryptionService.setupPassword(diaryPassword, loginPassword);

                this._setLoading(submitBtn, false);

                if (!result.success) {
                    this._showError(errorDiv, result.message || 'Errore durante la configurazione');
                    return;
                }

                // Success - close modal and callback
                this.close();
                if (this.onUnlock) {
                    this.onUnlock();
                }
            });
        }
    }

    /**
     * Bind unlock form events
     * @private
     */
    _bindUnlockEvents() {
        const form = document.getElementById('diaryUnlockForm');
        const passwordInput = document.getElementById('unlockPassword');
        const rememberCheckbox = document.getElementById('rememberDevice');
        const errorDiv = document.getElementById('unlockError');
        const submitBtn = document.getElementById('unlockBtn');

        // Form submission
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const password = passwordInput.value;
                const rememberDevice = rememberCheckbox?.checked || false;

                if (!password) {
                    this._showError(errorDiv, 'Inserisci la password');
                    return;
                }

                // Show loading
                this._setLoading(submitBtn, true);
                this._hideError(errorDiv);

                // Unlock
                const result = await this.encryptionService.unlock(password, rememberDevice);

                this._setLoading(submitBtn, false);

                if (!result.success) {
                    this._showError(errorDiv, result.message || 'Password non corretta');
                    passwordInput.value = '';
                    passwordInput.focus();
                    return;
                }

                // Success - close modal and callback
                this.close();
                if (this.onUnlock) {
                    this.onUnlock();
                }
            });
        }

        // Focus password input
        if (passwordInput) {
            setTimeout(() => passwordInput.focus(), 100);
        }
    }

    /**
     * Calculate password strength
     * @private
     */
    _calculatePasswordStrength(password) {
        if (!password) {
            return { level: 'none', text: '' };
        }

        let score = 0;

        // Length
        if (password.length >= 8) score += 1;
        if (password.length >= 12) score += 1;
        if (password.length >= 16) score += 1;

        // Complexity
        if (/[a-z]/.test(password)) score += 1;
        if (/[A-Z]/.test(password)) score += 1;
        if (/[0-9]/.test(password)) score += 1;
        if (/[^a-zA-Z0-9]/.test(password)) score += 1;

        if (score <= 2) {
            return { level: 'weak', text: 'Debole' };
        } else if (score <= 4) {
            return { level: 'medium', text: 'Media' };
        } else if (score <= 6) {
            return { level: 'strong', text: 'Forte' };
        } else {
            return { level: 'very-strong', text: 'Molto forte' };
        }
    }

    /**
     * Show error message
     * @private
     */
    _showError(element, message) {
        if (element) {
            element.textContent = message;
            element.style.display = 'block';
        }
    }

    /**
     * Hide error message
     * @private
     */
    _hideError(element) {
        if (element) {
            element.textContent = '';
            element.style.display = 'none';
        }
    }

    /**
     * Set button loading state
     * @private
     */
    _setLoading(button, isLoading) {
        if (!button) return;

        const textSpan = button.querySelector('.diary-password-modal__btn-text');
        const loadingSpan = button.querySelector('.diary-password-modal__btn-loading');

        if (isLoading) {
            button.disabled = true;
            if (textSpan) textSpan.style.display = 'none';
            if (loadingSpan) loadingSpan.style.display = 'inline-flex';
        } else {
            button.disabled = false;
            if (textSpan) textSpan.style.display = 'inline';
            if (loadingSpan) loadingSpan.style.display = 'none';
        }
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DiaryPasswordModal;
}
