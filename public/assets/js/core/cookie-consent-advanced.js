/**
 * ================================================================================
 * need2talk - Advanced Cookie Consent System
 * ================================================================================
 * Sistema avanzato di gestione consensi GDPR con tracking emozioni granulare
 * Ottimizzato per migliaia di utenti simultanei con performance elevate
 * ================================================================================
 */

// ENTERPRISE V11.8: Guard against double loading
if (window._cookieConsentLoaded) {
    console.warn('[CookieConsent] Script already loaded, skipping re-initialization');
} else {
window._cookieConsentLoaded = true;

Need2Talk.AdvancedCookieConsent = {
    // Configurazione
    config: {
        apiEndpoint: '/api/cookie-consent',
        bannerElement: null,
        modalElement: null,
        displayLogId: null,
        consentVersion: '1.0',
        autoShowDelay: 2000, // 2 secondi prima di mostrare il banner
        categories: [],
        services: {},
        currentConsent: null
    },

    // Cache per performance
    cache: {
        userConsent: null,
        servicePreferences: {},
        lastFetchTime: null,
        cacheDuration: 5 * 60 * 1000 // 5 minuti
    },

    // ENTERPRISE: Saving state (deadlock protection)
    saving: false,

    // Tracking emozioni
    emotionTracking: {
        isEnabled: false,
        registrationEmotions: [],
        listeningEmotions: [],
        currentSession: null
    },

    /**
     * Inizializza sistema consent avanzato
     */
    async init() {
        Need2Talk.Logger.debug('CookieConsent', 'Initializing Advanced Cookie Consent System...');

        try {
            // Carica configurazione dal server
            await this.loadConfiguration();

            // Setup elementi DOM
            this.setupDOMElements();

            // Setup eventi
            this.setupEventListeners();

            // Verifica consenso esistente
            await this.checkExistingConsent();

            // Inizializza tracking emozioni se abilitato
            this.initializeEmotionTracking();

            Need2Talk.Logger.debug('CookieConsent', 'Advanced Cookie Consent System initialized');

        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Initialization failed', error);
            // Fallback al sistema base
            this.initializeFallbackSystem();
        }
    },

    /**
     * Carica configurazione dal server
     */
    async loadConfiguration() {
        // Se CSRF non è disponibile, usa configurazione di base
        if (!Need2Talk.CSRF || !Need2Talk.CSRF.getToken()) {
            Need2Talk.Logger.debug('CookieConsent', 'Using local configuration (CSRF not available)');
            this.loadDefaultConfiguration();
            return;
        }
        
        try {
            const response = await fetch(`${this.config.apiEndpoint}/config`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                const errorText = await response.text();

                // ENTERPRISE: Don't log entire HTML response (unreadable in logs)
                const isHTML = errorText.trim().startsWith('<!DOCTYPE') || errorText.trim().startsWith('<html');
                const responsePreview = isHTML && errorText.length > 200
                    ? `[HTML Error Page - ${errorText.length} chars] Status: ${response.status}`
                    : (errorText.length > 500 ? errorText.substring(0, 500) + '...' : errorText);

                Need2Talk.Logger.error('CookieConsent', `API Error ${response.status}`, {
                    status: response.status,
                    statusText: response.statusText,
                    response: responsePreview,
                    url: response.url,
                    responseLength: errorText.length,
                    isHTML: isHTML
                });
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            
            this.config.categories = data.categories || [];
            this.config.currentConsent = data.currentConsent;
            this.config.consentVersion = data.consentVersion || '1.0';
            
            // Organizza servizi per categoria per accesso rapido
            this.organizeServices();
            
        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Failed to load cookie consent configuration', error);
            throw error;
        }
    },

    /**
     * Carica configurazione di default locale
     */
    loadDefaultConfiguration() {
        // Configurazione base per il fallback
        this.config.categories = [
            {
                id: 1,
                category_key: 'necessary',
                category_name: 'Necessari',
                description: 'Cookie essenziali per il funzionamento del sito',
                is_required: true,
                services: [
                    {
                        id: 1,
                        service_key: 'session_management',
                        service_name: 'Gestione Sessioni',
                        description: 'Cookie per mantenere la sessione utente',
                        purpose: 'Autenticazione e sicurezza',
                        is_required: true
                    }
                ]
            },
            {
                id: 2,
                category_key: 'emotional_tracking',
                category_name: 'Tracking Emozioni',
                description: 'Raccolta dati emotivi per personalizzazione',
                is_required: false,
                services: [
                    {
                        id: 2,
                        service_key: 'emotion_registration',
                        service_name: 'Emozioni in Registrazione',
                        description: 'Tracciamento emozioni durante registrazione audio',
                        purpose: 'Personalizzazione esperienza',
                        is_required: false
                    }
                ]
            }
        ];
        
        this.config.currentConsent = null;
        this.config.consentVersion = '1.0';
        
        this.organizeServices();
    },

    /**
     * Organizza servizi per categoria per performance
     */
    organizeServices() {
        this.config.services = {};
        
        this.config.categories.forEach(category => {
            this.config.services[category.category_key] = category.services || [];
            
            // Crea lookup rapido per servizi individuali
            category.services?.forEach(service => {
                this.config.services[service.service_key] = service;
            });
        });
    },

    /**
     * Setup elementi DOM
     */
    setupDOMElements() {
        this.config.bannerElement = document.getElementById('cookie-consent-banner');
        Need2Talk.Logger.debug('CookieConsent', 'Banner element found', this.config.bannerElement ? 'YES' : 'NO');

        if (!this.config.bannerElement) {
            Need2Talk.Logger.debug('CookieConsent', 'Creating dynamic cookie consent banner');
            // Crea banner dinamicamente
            this.createConsentBanner();
        }
    },

    /**
     * Crea banner consenso dinamico
     */
    createConsentBanner() {
        const banner = document.createElement('div');
        banner.id = 'cookie-consent-banner';
        banner.className = 'cookie-banner hidden';
        banner.innerHTML = this.renderConsentBanner();
        
        document.body.appendChild(banner);
        this.config.bannerElement = banner;
    },

    /**
     * Renderizza HTML banner consenso
     */
    renderConsentBanner() {
        return `
            <div class="max-w-6xl mx-auto px-4 py-4 bg-gray-900 border border-purple-500/50 rounded-t-2xl shadow-2xl shadow-purple-500/30">
                <div class="flex items-start gap-4 mb-4">
                    <div class="flex-shrink-0 w-12 h-12 bg-gradient-to-br from-purple-500 to-pink-500 rounded-xl flex items-center justify-center">
                        <span class="text-3xl" role="img" aria-label="Cookie">🍪</span>
                    </div>
                    <div class="flex-1">
                        <h3 id="cookie-banner-title" class="text-xl font-bold text-white mb-2 bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent">
                            Il tuo controllo sui cookie
                        </h3>
                        <p class="text-gray-300 text-sm leading-relaxed">
                            Utilizziamo cookie per migliorare la tua esperienza, incluso il 
                            <strong class="text-purple-400">tracking delle emozioni</strong> durante registrazione e ascolto 
                            per personalizzare i contenuti. Scegli le tue preferenze.
                        </p>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-2 mb-4">
                    <div class="inline-flex items-center gap-2 bg-red-900/20 border border-red-500/50 rounded-full px-3 py-1.5 text-xs">
                        <span role="img" aria-label="Emozioni">❤️</span>
                        <span class="text-red-200">Tracking Emozioni</span>
                    </div>
                    <div class="inline-flex items-center gap-2 bg-blue-900/20 border border-blue-500/50 rounded-full px-3 py-1.5 text-xs">
                        <span role="img" aria-label="Analytics">📊</span>
                        <span class="text-blue-200">Analytics</span>
                    </div>
                    <div class="inline-flex items-center gap-2 bg-green-900/20 border border-green-500/50 rounded-full px-3 py-1.5 text-xs">
                        <span role="img" aria-label="Marketing">📢</span>
                        <span class="text-green-200">Marketing</span>
                    </div>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-3 mb-3">
                    <button id="cookie-decline-all" class="flex-1 sm:flex-none px-4 py-2 bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white rounded-lg font-medium flex items-center justify-center gap-2">
                        <span role="img" aria-label="Declina">❌</span>
                        Declina Tutto
                    </button>
                    <button id="cookie-settings-btn" class="flex-1 sm:flex-none px-4 py-2 border border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 rounded-lg font-medium flex items-center justify-center gap-2">
                        <span role="img" aria-label="Impostazioni">⚙️</span>
                        Personalizza
                    </button>
                    <button id="cookie-accept-all" class="flex-1 sm:flex-none px-4 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium flex items-center justify-center gap-2 shadow-lg shadow-purple-500/25">
                        <span role="img" aria-label="Accetta">✓</span>
                        Accetta Tutto
                    </button>
                </div>
                
                <div class="flex justify-center gap-4 text-xs">
                    <a href="/legal/privacy" target="_blank" class="text-purple-400 hover:text-purple-300 underline flex items-center gap-1">
                        <span role="img" aria-label="Link">🔗</span>
                        Privacy e Cookie Policy
                    </a>
                </div>
            </div>
        `;
    },

    /**
     * Setup eventi
     */
    setupEventListeners() {
        // Bottoni principali banner
        document.addEventListener('click', (e) => {
            if (e.target.id === 'cookie-accept-all' || e.target.closest('#cookie-accept-all')) {
                Need2Talk.Logger.debug('CookieConsent', 'Accept All button clicked');
                this.acceptAll();
            } else if (e.target.id === 'cookie-decline-all' || e.target.closest('#cookie-decline-all')) {
                Need2Talk.Logger.debug('CookieConsent', 'Decline All button clicked');
                this.declineAll();
            } else if (e.target.id === 'cookie-settings-btn' || e.target.closest('#cookie-settings-btn')) {
                Need2Talk.Logger.debug('CookieConsent', 'Customize button clicked');
                this.showGranularSettings();
            }
        });

        // Eventi personalizzati
        document.addEventListener('emotion:registered', (e) => {
            this.trackEmotionEvent('registration', e.detail);
        });

        document.addEventListener('emotion:listening', (e) => {
            this.trackEmotionEvent('listening', e.detail);
        });

        // Evento cambio pagina
        window.addEventListener('beforeunload', () => {
            this.flushEmotionData();
        });
    },

    /**
     * Verifica consenso esistente
     */
    async checkExistingConsent() {
        Need2Talk.Logger.debug('CookieConsent', 'Checking existing consent', {
            hasCurrentConsent: !!this.config.currentConsent,
            currentConsent: this.config.currentConsent
        });

        // ENTERPRISE: Se server ritorna currentConsent, è la SOURCE OF TRUTH
        // Usiamo quello e sincronizziamo localStorage
        if (this.config.currentConsent) {
            Need2Talk.Logger.debug('CookieConsent', `Existing consent found from server: ${this.config.currentConsent.consent_type}`);
            this.cache.userConsent = this.config.currentConsent;

            // ENTERPRISE: Sincronizza localStorage con server (single source of truth)
            localStorage.setItem('need2talk_cookie_consent', JSON.stringify(this.config.currentConsent));

            this.applyConsentPreferences();
            return;
        }

        // ENTERPRISE FIX (2025-01-23): localStorage è la SOURCE OF TRUTH per visitatori anonimi
        // NON cancellare mai localStorage solo perché utente non è autenticato
        // Il consenso cookie è INDIPENDENTE dall'autenticazione
        const localConsent = localStorage.getItem('need2talk_cookie_consent');

        // Se localStorage ha consenso valido, usalo (sia per utenti autenticati che anonimi)
        if (localConsent) {
            try {
                const consent = JSON.parse(localConsent);
                if (this.isConsentValid(consent)) {
                    Need2Talk.Logger.debug('CookieConsent', 'Using valid localStorage consent (fallback)');
                    this.cache.userConsent = consent;
                    this.applyConsentPreferences();

                    // ENTERPRISE FIX (2025-01-23): Sync localStorage → Server for authenticated users
                    // Se utente autenticato NON ha consenso su server MA ha localStorage valido,
                    // sincronizza automaticamente (es. accettato come anonimo, poi fatto login)
                    if (Need2Talk.CSRF?.getToken() && !this.config.currentConsent) {
                        Need2Talk.Logger.debug('CookieConsent', 'Syncing localStorage consent to server (user logged in)');
                        this.saveConsent(consent.consent_type, consent.service_preferences)
                            .catch(err => {
                                Need2Talk.Logger.error('CookieConsent', 'Failed to sync consent to server', err);
                                // Non bloccare l'applicazione se sync fallisce
                            });
                    }

                    return;
                }
            } catch (error) {
                Need2Talk.Logger.warn('CookieConsent', 'Invalid local consent data', error);
                localStorage.removeItem('need2talk_cookie_consent');
            }
        }

        // Nessun consenso valido - mostra banner
        Need2Talk.Logger.debug('CookieConsent', `No valid consent found - showing banner in ${this.config.autoShowDelay}ms`);
        setTimeout(() => {
            Need2Talk.Logger.debug('CookieConsent', 'Timeout reached, calling showConsentBanner...');
            this.showConsentBanner();
        }, this.config.autoShowDelay);
    },

    /**
     * Verifica validità consenso
     */
    isConsentValid(consent) {
        if (!consent || !consent.timestamp) return false;
        
        const sixMonths = 6 * 30 * 24 * 60 * 60 * 1000;
        return (Date.now() - consent.timestamp) < sixMonths;
    },

    /**
     * Mostra banner consenso
     *
     * ENTERPRISE GALAXY UX (2025-01-23): Made async with await logBannerDisplay()
     * FIX: Race condition where displayLogId was NULL when updateBannerResponse() called
     * displayLogId MUST be set BEFORE user clicks accept/decline buttons
     */
    async showConsentBanner() {
        Need2Talk.Logger.debug('CookieConsent', 'showConsentBanner called', {
            hasBannerElement: !!this.config.bannerElement
        });

        if (!this.config.bannerElement) {
            console.error('[CookieConsent] ❌ Cannot show banner: bannerElement NOT FOUND!');
            Need2Talk.Logger.error('CookieConsent', 'Cannot show banner: bannerElement not found!');
            return;
        }

        // ENTERPRISE GALAXY FIX (2025-01-23): AWAIT logBannerDisplay() to ensure displayLogId is set
        // CRITICAL: displayLogId MUST exist BEFORE user clicks accept/decline
        // Previous race condition: logBannerDisplay() async without await → displayLogId NULL
        await this.logBannerDisplay();

        this.config.bannerElement.classList.remove('hidden');
        this.config.bannerElement.classList.add('show', 'animate-slide-up');

        // Accessibility
        this.config.bannerElement.setAttribute('role', 'dialog');
        this.config.bannerElement.setAttribute('aria-labelledby', 'cookie-banner-title');

        Need2Talk.Logger.debug('CookieConsent', 'Cookie consent banner displayed');
    },

    /**
     * Nascondi banner
     */
    hideConsentBanner() {
        if (!this.config.bannerElement) return;
        
        this.config.bannerElement.classList.add('animate-fade-out');
        
        setTimeout(() => {
            this.config.bannerElement.classList.add('hidden');
            this.config.bannerElement.classList.remove('show', 'animate-slide-up', 'animate-fade-out');
        }, 300);
    },

    /**
     * Accetta tutti i cookie
     * ENTERPRISE: Deadlock protection - prevent multiple simultaneous saves
     */
    async acceptAll() {
        // CRITICAL: Prevent multiple simultaneous saves (database deadlock protection)
        if (this.saving) {
            Need2Talk.Logger.debug('CookieConsent', 'Save already in progress, ignoring click (deadlock protection)');
            return;
        }

        this.saving = true;
        this.setButtonsLoadingState(true);

        try {
            const preferences = this.createFullPreferences(true);
            await this.saveConsent('accepted_all', preferences);
            this.updateBannerResponse('accepted_all');
            this.hideConsentBanner();

            Need2Talk.showNotification('✅ Tutti i cookie accettati', 'success');
        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Failed to accept all cookies', error);
            Need2Talk.showNotification('❌ Errore nel salvare le preferenze. Riprova.', 'error');
        } finally {
            this.saving = false;
            this.setButtonsLoadingState(false);
        }
    },

    /**
     * Declina tutti i cookie non necessari
     * ENTERPRISE: Deadlock protection - prevent multiple simultaneous saves
     */
    async declineAll() {
        // CRITICAL: Prevent multiple simultaneous saves (database deadlock protection)
        if (this.saving) {
            Need2Talk.Logger.debug('CookieConsent', 'Save already in progress, ignoring click (deadlock protection)');
            return;
        }

        this.saving = true;
        this.setButtonsLoadingState(true);

        try {
            const preferences = this.createFullPreferences(false);
            await this.saveConsent('declined_all', preferences);
            this.updateBannerResponse('declined_all');
            this.hideConsentBanner();

            Need2Talk.showNotification('ℹ️ Solo cookie necessari attivati', 'info');
        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Failed to decline all cookies', error);
            Need2Talk.showNotification('❌ Errore nel salvare le preferenze. Riprova.', 'error');
        } finally {
            this.saving = false;
            this.setButtonsLoadingState(false);
        }
    },

    /**
     * Crea preferenze complete
     */
    createFullPreferences(acceptAll) {
        const preferences = {};
        
        this.config.categories.forEach(category => {
            category.services?.forEach(service => {
                if (service.is_required) {
                    preferences[service.service_key] = true;
                } else {
                    preferences[service.service_key] = acceptAll;
                }
            });
        });
        
        return preferences;
    },

    /**
     * Mostra impostazioni granulari
     */
    showGranularSettings() {
        Need2Talk.Logger.debug('CookieConsent', 'Opening settings modal');
        this.createSettingsModal();
        this.updateBannerResponse('custom_settings');
        Need2Talk.Logger.debug('CookieConsent', 'Settings modal created');
    },

    /**
     * Crea modal impostazioni granulari
     */
    createSettingsModal() {
        // Rimuovi modal esistente se presente
        const existingModal = document.getElementById('cookie-settings-modal');
        if (existingModal) {
            existingModal.remove();
        }

        const modal = document.createElement('div');
        modal.id = 'cookie-settings-modal';
        modal.className = 'cookie-settings-overlay';
        modal.innerHTML = this.renderSettingsModal();
        
        document.body.appendChild(modal);
        this.config.modalElement = modal;
        
        // Mostra modal con animazione
        requestAnimationFrame(() => {
            modal.classList.add('active');
        });
        
        // Setup eventi modal
        this.setupModalEvents();
    },

    /**
     * Renderizza modal impostazioni
     */
    renderSettingsModal() {
        let categoriesHTML = '';
        
        this.config.categories.forEach(category => {
            categoriesHTML += this.renderCategorySettings(category);
        });
        
        return `
            <div class="bg-gray-800 border border-purple-500/50 rounded-2xl shadow-2xl shadow-purple-500/30 max-w-4xl w-full max-h-[90vh] overflow-hidden">
                <div class="bg-gradient-to-r from-purple-600 to-pink-600 px-6 py-4 flex items-center justify-between">
                    <h2 class="text-xl font-bold text-white flex items-center gap-3">
                        <i class="fas fa-cookie-bite"></i>
                        Impostazioni Cookie Granulari
                    </h2>
                    <button class="text-white/80 hover:text-white text-2xl transition-colors" data-action="close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="p-6 max-h-[60vh] overflow-y-auto">
                    <div class="bg-purple-900/20 border border-purple-500/30 rounded-lg p-4 mb-6">
                        <p class="text-gray-300 mb-4">Personalizza le tue preferenze sui cookie. Puoi scegliere quali servizi abilitare per una esperienza su misura.</p>
                        
                        <div class="bg-gradient-to-r from-red-900/20 to-purple-900/20 border border-red-500/30 rounded-lg p-4 flex items-start gap-3">
                            <i class="fas fa-heart-pulse text-red-400 mt-1"></i>
                            <div>
                                <strong class="text-red-300 block mb-1">Focus: Tracking Emozioni</strong>
                                <p class="text-red-200 text-sm">Tracciamo le tue emozioni durante registrazione e ascolto per personalizzare la tua esperienza e migliorare i nostri algoritmi.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        ${categoriesHTML}
                    </div>
                </div>
                
                <div class="bg-gray-900/50 border-t border-purple-500/20 px-6 py-4 flex flex-col sm:flex-row justify-between items-center gap-4">
                    <small class="text-gray-400 flex items-center gap-2">
                        <i class="fas fa-info-circle"></i>
                        Le tue scelte saranno memorizzate per 6 mesi.
                        <a href="/legal/privacy" target="_blank" class="text-purple-400 hover:text-purple-300 underline">Privacy Policy</a>
                    </small>
                    <div class="flex gap-3">
                        <button class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-gray-300 hover:text-white rounded-lg font-medium transition-all duration-200 flex items-center gap-2" data-action="close">
                            <i class="fas fa-times"></i>
                            Annulla
                        </button>
                        <button class="px-4 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white rounded-lg font-medium transition-all duration-200 flex items-center gap-2 shadow-lg shadow-purple-500/25" data-action="save">
                            <i class="fas fa-save"></i>
                            Salva Preferenze
                        </button>
                    </div>
                </div>
            </div>
        `;
    },

    /**
     * Renderizza impostazioni categoria
     */
    renderCategorySettings(category) {
        const isEmotionCategory = category.category_key === 'emotional_tracking';
        const isRequired = category.is_required;
        
        let servicesHTML = '';
        category.services?.forEach(service => {
            servicesHTML += this.renderServiceSetting(service, isEmotionCategory);
        });
        
        // Stili specifici per categoria
        let categoryBg = 'bg-gray-700/50';
        let categoryBorder = 'border-gray-600/50';
        
        if (isEmotionCategory) {
            categoryBg = 'bg-gradient-to-r from-red-900/20 to-purple-900/20';
            categoryBorder = 'border-red-500/30';
        } else if (isRequired) {
            categoryBg = 'bg-blue-900/20';
            categoryBorder = 'border-blue-500/30';
        }
        
        return `
            <div class="${categoryBg} ${categoryBorder} border rounded-lg overflow-hidden hover:border-purple-500/50 transition-colors">
                <div class="p-4 cursor-pointer" onclick="this.parentElement.querySelector('.services-container').classList.toggle('hidden')">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <h3 class="text-white font-semibold mb-2 flex items-center gap-2 flex-wrap">
                                ${this.getCategoryIcon(category.category_key)}
                                ${category.category_name}
                                ${isRequired ? '<span class="bg-blue-500/20 text-blue-300 px-2 py-1 rounded-full text-xs border border-blue-500/50">Obbligatorio</span>' : ''}
                            </h3>
                            <p class="text-gray-400 text-sm">${category.description}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="relative inline-flex items-center ${isRequired ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'}">
                                <input type="checkbox"
                                       id="category-${category.category_key}"
                                       data-category="${category.category_key}"
                                       ${this.isCategoryEnabled(category.category_key) ? 'checked' : ''}
                                       ${isRequired ? 'disabled checked' : ''}
                                       class="sr-only peer">
                                <div class="relative w-11 h-6 bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-purple-600 peer-checked:to-pink-600"></div>
                            </label>
                            <i class="fas fa-chevron-down text-gray-400 transition-transform"></i>
                        </div>
                    </div>
                </div>
                
                <div class="services-container hidden border-t ${categoryBorder} bg-gray-800/30">
                    <div class="p-4 space-y-3">
                        ${servicesHTML}
                    </div>
                </div>
            </div>
        `;
    },

    /**
     * Renderizza impostazione singolo servizio
     */
    renderServiceSetting(service, isEmotionService = false) {
        const currentlyEnabled = this.isServiceEnabled(service.service_key);
        const isRequired = service.is_required;
        
        // Stili specifici per servizi emotion
        let serviceBg = 'bg-gray-600/30';
        let serviceBorder = 'border-gray-500/30';
        
        if (isEmotionService) {
            serviceBg = 'bg-gradient-to-r from-red-900/10 to-purple-900/10';
            serviceBorder = 'border-red-500/20';
        }
        
        return `
            <div class="${serviceBg} ${serviceBorder} border rounded-lg p-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <h4 class="text-white font-medium mb-2 flex items-center gap-2 flex-wrap">
                            ${service.service_name}
                            ${isRequired ? '<span class="bg-blue-500/20 text-blue-300 px-2 py-1 rounded-full text-xs border border-blue-500/50">Necessario</span>' : ''}
                            ${service.third_party_service ? '<span class="bg-orange-500/20 text-orange-300 px-2 py-1 rounded-full text-xs border border-orange-500/50">Terze Parti</span>' : ''}
                        </h4>
                        <p class="text-gray-400 text-sm mb-2 leading-relaxed">${service.description}</p>
                        <p class="text-purple-300 text-sm mb-1"><strong>Finalità:</strong> ${service.purpose}</p>
                        
                        <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                            ${service.data_retention_days ? 
                                `<span class="flex items-center gap-1">
                                    <i class="fas fa-clock"></i>
                                    Conservazione: ${service.data_retention_days} giorni
                                </span>` : ''
                            }
                            
                            ${service.privacy_policy_url ? 
                                `<a href="${service.privacy_policy_url}" target="_blank" class="text-purple-400 hover:text-purple-300 underline flex items-center gap-1">
                                    <i class="fas fa-external-link-alt"></i>
                                    Privacy Policy
                                </a>` : ''
                            }
                        </div>
                    </div>
                    
                    <div class="flex-shrink-0">
                        <label class="relative inline-flex items-center cursor-pointer ${isRequired ? 'opacity-60' : ''}">
                            <input type="checkbox"
                                   id="service-${service.service_key}"
                                   data-service="${service.service_key}"
                                   ${currentlyEnabled ? 'checked' : ''}
                                   ${isRequired ? 'disabled' : ''}
                                   class="sr-only peer">
                            <div class="relative w-11 h-6 bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-800 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-purple-600 peer-checked:to-pink-600"></div>
                        </label>
                    </div>
                </div>
            </div>
        `;
    },

    /**
     * Ottieni icona categoria
     */
    getCategoryIcon(categoryKey) {
        const icons = {
            'necessary': '<i class="fas fa-shield-alt text-green-400"></i>',
            'emotional_tracking': '<i class="fas fa-heart-pulse text-red-400"></i>',
            'functional': '<i class="fas fa-cogs text-blue-400"></i>',
            'analytics': '<i class="fas fa-chart-line text-purple-400"></i>',
            'marketing': '<i class="fas fa-bullhorn text-orange-400"></i>',
            'social_media': '<i class="fas fa-share-alt text-pink-400"></i>'
        };
        
        return icons[categoryKey] || '<i class="fas fa-cookie"></i>';
    },

    /**
     * Setup eventi modal
     */
    setupModalEvents() {
        if (!this.config.modalElement) return;
        
        this.config.modalElement.addEventListener('click', (e) => {
            const action = e.target.dataset.action || e.target.closest('[data-action]')?.dataset.action;
            
            if (action === 'close') {
                this.closeSettingsModal();
            } else if (action === 'save') {
                this.saveGranularSettings();
            }
        });
        
        // Toggle categoria attiva/disattiva tutti i servizi
        this.config.modalElement.addEventListener('change', (e) => {
            if (e.target.dataset.category) {
                this.toggleCategoryServices(e.target.dataset.category, e.target.checked);
            }
        });
        
        // Chiudi modal cliccando fuori
        this.config.modalElement.addEventListener('click', (e) => {
            if (e.target === this.config.modalElement) {
                this.closeSettingsModal();
            }
        });
    },

    /**
     * Toggle servizi categoria
     */
    toggleCategoryServices(categoryKey, enabled) {
        const category = this.config.categories.find(c => c.category_key === categoryKey);
        if (!category) return;
        
        category.services?.forEach(service => {
            if (!service.is_required) {
                const checkbox = document.getElementById(`service-${service.service_key}`);
                if (checkbox) {
                    checkbox.checked = enabled;
                }
            }
        });
    },

    /**
     * Salva impostazioni granulari
     * ENTERPRISE: Deadlock protection - prevent multiple simultaneous saves
     */
    async saveGranularSettings() {
        // CRITICAL: Prevent multiple simultaneous saves (database deadlock protection)
        if (this.saving) {
            Need2Talk.Logger.debug('CookieConsent', 'Save already in progress, ignoring click (deadlock protection)');
            return;
        }

        this.saving = true;

        // Disable save button in modal
        const saveButton = this.config.modalElement?.querySelector('[data-action="save"]');
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvataggio...';
        }

        try {
            const preferences = {};
            let hasNonRequiredEnabled = false;

            // Raccogli preferenze da tutti i checkbox
            this.config.modalElement.querySelectorAll('input[data-service]').forEach(checkbox => {
                const isEnabled = checkbox.checked;
                preferences[checkbox.dataset.service] = isEnabled;

                // Controlla se ci sono servizi non obbligatori abilitati
                const serviceKey = checkbox.dataset.service;
                const service = this.config.services[serviceKey];
                if (service && !service.is_required && isEnabled) {
                    hasNonRequiredEnabled = true;
                }
            });

            // Se nessun toggle non obbligatorio è selezionato, equivale a "declina tutto"
            let consentType = 'custom';
            let notificationMessage = '✅ Preferenze cookie personalizzate salvate';

            if (!hasNonRequiredEnabled) {
                // Nessun servizio non obbligatorio abilitato = decline all
                consentType = 'declined_all';
                notificationMessage = 'ℹ️ Solo cookie necessari attivati (equivalente a "Declina tutto")';

                // Assicurati che tutti i servizi non obbligatori siano disabilitati
                Object.keys(preferences).forEach(serviceKey => {
                    const service = this.config.services[serviceKey];
                    if (service && !service.is_required) {
                        preferences[serviceKey] = false;
                    }
                });
            }

            await this.saveConsent(consentType, preferences);
            this.closeSettingsModal();
            this.hideConsentBanner();

            Need2Talk.showNotification(notificationMessage, hasNonRequiredEnabled ? 'success' : 'info');
        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Failed to save granular settings', error);
            Need2Talk.showNotification('❌ Errore nel salvare le preferenze. Riprova.', 'error');

            // Re-enable save button on error
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.innerHTML = '<i class="fas fa-save"></i> Salva Preferenze';
            }
        } finally {
            this.saving = false;
        }
    },

    /**
     * Chiudi modal impostazioni
     */
    closeSettingsModal() {
        if (!this.config.modalElement) return;

        this.config.modalElement.classList.remove('active');

        setTimeout(() => {
            if (this.config.modalElement && this.config.modalElement.parentNode) {
                this.config.modalElement.remove();
            }
            this.config.modalElement = null;
        }, 300);
    },

    /**
     * ENTERPRISE: Set buttons loading state (deadlock protection)
     * Disables banner buttons during save to prevent multiple simultaneous requests
     * @param {boolean} isLoading - Whether to show loading state
     */
    setButtonsLoadingState(isLoading) {
        const acceptBtn = document.getElementById('cookie-accept-all');
        const declineBtn = document.getElementById('cookie-decline-all');
        const settingsBtn = document.getElementById('cookie-settings-btn');

        if (isLoading) {
            // Disable all buttons and show loading state
            if (acceptBtn) {
                acceptBtn.disabled = true;
                acceptBtn.dataset.originalHtml = acceptBtn.innerHTML;
                acceptBtn.innerHTML = '<span role="img" aria-label="Caricamento">⏳</span> Salvataggio...';
                acceptBtn.style.opacity = '0.6';
                acceptBtn.style.cursor = 'wait';
            }
            if (declineBtn) {
                declineBtn.disabled = true;
                declineBtn.style.opacity = '0.6';
                declineBtn.style.cursor = 'wait';
            }
            if (settingsBtn) {
                settingsBtn.disabled = true;
                settingsBtn.style.opacity = '0.6';
                settingsBtn.style.cursor = 'wait';
            }
        } else {
            // Re-enable all buttons and restore original state
            if (acceptBtn) {
                acceptBtn.disabled = false;
                if (acceptBtn.dataset.originalHtml) {
                    acceptBtn.innerHTML = acceptBtn.dataset.originalHtml;
                    delete acceptBtn.dataset.originalHtml;
                }
                acceptBtn.style.opacity = '';
                acceptBtn.style.cursor = '';
            }
            if (declineBtn) {
                declineBtn.disabled = false;
                declineBtn.style.opacity = '';
                declineBtn.style.cursor = '';
            }
            if (settingsBtn) {
                settingsBtn.disabled = false;
                settingsBtn.style.opacity = '';
                settingsBtn.style.cursor = '';
            }
        }

        Need2Talk.Logger.debug('CookieConsent', `Buttons loading state: ${isLoading ? 'ENABLED' : 'DISABLED'}`);
    },

    /**
     * Salva consenso sul server
     */
    async saveConsent(consentType, preferences) {
        try {
            // Salva sempre nel localStorage per backup/fallback
            this.saveConsentLocal(consentType, preferences);

            // Se CSRF non è disponibile, usa solo localStorage
            if (!Need2Talk.CSRF?.getToken()) {
                Need2Talk.Logger.debug('CookieConsent', 'Cookie consent saved locally (CSRF unavailable)', { consentType });
                this.applyConsentPreferences();
                return;
            }
            
            const response = await fetch(`${this.config.apiEndpoint}/save`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    consent_type: consentType,
                    service_preferences: preferences,  // FIX (2025-01-10): Changed from 'preferences' to match backend API
                    version: this.config.consentVersion
                })
            });

            if (!response.ok) {
                const errorText = await response.text();

                // ENTERPRISE: Don't log entire HTML response (unreadable in logs)
                const isHTML = errorText.trim().startsWith('<!DOCTYPE') || errorText.trim().startsWith('<html');
                const responsePreview = isHTML && errorText.length > 200
                    ? `[HTML Error Page - ${errorText.length} chars] Status: ${response.status}`
                    : (errorText.length > 500 ? errorText.substring(0, 500) + '...' : errorText);

                Need2Talk.Logger.error('CookieConsent', `API Error ${response.status}`, {
                    status: response.status,
                    statusText: response.statusText,
                    response: responsePreview,
                    url: response.url,
                    responseLength: errorText.length,
                    isHTML: isHTML
                });
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();
            
            // Aggiorna cache locale
            this.cache.userConsent = {
                consent_type: consentType,
                service_preferences: preferences,
                timestamp: Date.now()
            };
            
            // Applica preferenze
            this.applyConsentPreferences();
            
            // Salva in localStorage come backup
            localStorage.setItem('need2talk_cookie_consent', JSON.stringify(this.cache.userConsent));

            Need2Talk.Logger.debug('CookieConsent', 'Cookie consent saved', { consentType });
            return result;
            
        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Failed to save cookie consent', error);
            Need2Talk.showNotification('❌ Errore nel salvare le preferenze', 'error');
            throw error;
        }
    },

    /**
     * Salva consenso solo nel localStorage (fallback quando CSRF non disponibile)
     */
    saveConsentLocal(consentType, preferences) {
        try {
            const localConsent = {
                consent_type: consentType,
                service_preferences: preferences,
                timestamp: Date.now(),
                version: this.config.consentVersion || 'local'
            };
            
            // Aggiorna cache locale
            this.cache.userConsent = localConsent;
            
            // Salva in localStorage
            localStorage.setItem('need2talk_cookie_consent', JSON.stringify(localConsent));

            Need2Talk.Logger.debug('CookieConsent', 'Cookie consent saved locally', { consentType });

        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Failed to save cookie consent locally', error);
        }
    },

    /**
     * Applica preferenze consenso
     */
    applyConsentPreferences() {
        if (!this.cache.userConsent) return;
        
        const preferences = this.cache.userConsent.service_preferences || {};
        
        // Inizializza tracking emozioni
        this.emotionTracking.isEnabled = 
            preferences.emotion_registration || 
            preferences.emotion_listening || 
            preferences.emotion_analytics;
        
        if (this.emotionTracking.isEnabled) {
            this.initializeEmotionTracking();
        }
        
        // Applica altre preferenze
        this.applyAnalyticsPreferences(preferences);
        this.applyMarketingPreferences(preferences);
        this.applyFunctionalPreferences(preferences);
        
        // Dispatch evento per altri moduli
        document.dispatchEvent(new CustomEvent('cookie:preferences:applied', {
            detail: { preferences }
        }));

        Need2Talk.Logger.debug('CookieConsent', 'Cookie preferences applied', { enabledServices: Object.keys(preferences).filter(k => preferences[k]) });
    },

    /**
     * Verifica se categoria è abilitata
     */
    isCategoryEnabled(categoryKey) {
        if (!this.cache.userConsent) return false;
        
        const preferences = this.cache.userConsent.service_preferences || {};
        const category = this.config.categories.find(c => c.category_key === categoryKey);
        
        if (!category) return false;
        
        // Se categoria richiesta, sempre abilitata
        if (category.is_required) return true;
        
        // Verifica se almeno un servizio della categoria è abilitato
        return category.services?.some(service => preferences[service.service_key]) || false;
    },

    /**
     * Verifica se servizio è abilitato
     */
    isServiceEnabled(serviceKey) {
        if (!this.cache.userConsent) {
            // Se servizio richiesto, consideralo abilitato per default
            const service = this.config.services[serviceKey];
            return service?.is_required || false;
        }
        
        const preferences = this.cache.userConsent.service_preferences || {};
        return preferences[serviceKey] || false;
    },

    /**
     * Inizializza tracking emozioni
     */
    initializeEmotionTracking() {
        if (!this.emotionTracking.isEnabled) return;

        Need2Talk.Logger.debug('CookieConsent', 'Initializing emotion tracking...');

        // Crea sessione tracking
        this.emotionTracking.currentSession = {
            session_id: this.generateSessionId(),
            start_time: Date.now(),
            emotions_data: []
        };
        
        // Initialize flush scheduling flag
        this.emotionTracking.flushScheduled = false;
        
        // Setup eventi emotion tracking
        this.setupEmotionTrackingEvents();
    },

    /**
     * Setup eventi tracking emozioni
     */
    setupEmotionTrackingEvents() {
        // Ascolta eventi emozioni dal sistema di registrazione
        document.addEventListener('audio:recording:emotion', (e) => {
            if (this.isServiceEnabled('emotion_registration')) {
                this.trackEmotionEvent('registration', e.detail);
            }
        });
        
        // Ascolta eventi emozioni dal player
        document.addEventListener('audio:player:emotion', (e) => {
            if (this.isServiceEnabled('emotion_listening')) {
                this.trackEmotionEvent('listening', e.detail);
            }
        });
        
        // Ascolta cambio stati player per timing
        document.addEventListener('audio:player:state', (e) => {
            if (this.isServiceEnabled('emotion_listening')) {
                this.trackPlayerState(e.detail);
            }
        });
    },

    /**
     * Traccia evento emozione
     */
    trackEmotionEvent(type, data) {
        if (!this.emotionTracking.isEnabled || !this.emotionTracking.currentSession) return;
        
        const emotionEvent = {
            type: type, // 'registration' | 'listening'
            timestamp: Date.now(),
            emotion: data.emotion,
            intensity: data.intensity || null,
            audio_id: data.audio_id || null,
            duration: data.duration || null,
            metadata: data.metadata || {}
        };
        
        this.emotionTracking.currentSession.emotions_data.push(emotionEvent);
        
        Need2Talk.Logger.debug('CookieConsent', 'Emotion tracked', emotionEvent);
        
        // Flush dati con jitter per evitare thundering herd
        if (this.emotionTracking.currentSession.emotions_data.length >= 50) {
            this.scheduleJitteredFlush();
        }
    },

    /**
     * Invia dati emozioni al server
     */
    async flushEmotionData() {
        if (!this.emotionTracking.currentSession || 
            this.emotionTracking.currentSession.emotions_data.length === 0) {
            return;
        }
        
        try {
            const response = await fetch(`${this.config.apiEndpoint}/track-emotion`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    session_data: this.emotionTracking.currentSession
                })
            });

            if (response.ok) {
                Need2Talk.Logger.debug('CookieConsent', 'Emotion data flushed', { eventsCount: this.emotionTracking.currentSession.emotions_data.length });
                this.emotionTracking.currentSession.emotions_data = [];
            }
            
        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Failed to flush emotion data', error);
        }
    },

    /**
     * Schedule emotion data flush with jitter to prevent thundering herd
     */
    scheduleJitteredFlush() {
        // Prevent multiple scheduled flushes
        if (this.emotionTracking.flushScheduled) {
            return;
        }
        
        this.emotionTracking.flushScheduled = true;
        
        // Jittered delay: 0-60 seconds
        const jitteredDelay = Math.random() * 60 * 1000;
        
        setTimeout(() => {
            this.flushEmotionData();
            this.emotionTracking.flushScheduled = false;
        }, jitteredDelay);
        
        Need2Talk.Logger.debug('CookieConsent', `Emotion flush scheduled in ${Math.round(jitteredDelay/1000)}s to prevent thundering herd`);
    },

    /**
     * Applica preferenze analytics
     */
    applyAnalyticsPreferences(preferences) {
        if (preferences.google_analytics) {
            this.enableGoogleAnalytics();
        }

        if (preferences.internal_analytics) {
            this.enableInternalAnalytics();
        }

        if (preferences.performance_monitoring) {
            this.enablePerformanceMonitoring();
        }
    },

    /**
     * Applica preferenze marketing
     */
    applyMarketingPreferences(preferences) {
        if (preferences.google_ads) {
            this.enableGoogleAds();
        }
        
        if (preferences.facebook_pixel) {
            this.enableFacebookPixel();
        }
        
        if (preferences.email_marketing) {
            this.enableEmailMarketing();
        }
    },

    /**
     * Applica preferenze funzionali
     */
    applyFunctionalPreferences(preferences) {
        if (preferences.theme_preferences) {
            this.enableThemePreferences();
        }
        
        if (preferences.language_preferences) {
            this.enableLanguagePreferences();
        }
        
        if (preferences.player_settings) {
            this.enablePlayerSettings();
        }
    },

    /**
     * Log visualizzazione banner
     */
    async logBannerDisplay() {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/banner-display`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    page_url: window.location.href,
                    referrer: document.referrer || null
                })
            });
            
            if (response.ok) {
                const result = await response.json();
                this.config.displayLogId = result.display_log_id;
            }
            
        } catch (error) {
            Need2Talk.Logger.error('CookieConsent', 'Failed to log banner display', error);
        }
    },

    /**
     * Aggiorna risposta banner
     *
     * ENTERPRISE GALAXY UX (2025-01-23): Use dedicated /banner-response endpoint
     * Handles displayLogId NULL gracefully with session_id fallback
     */
    updateBannerResponse(responseType) {
        // ENTERPRISE FIX (2025-01-23): Send request even if displayLogId is NULL
        // Backend will find display log by session_id as fallback
        fetch(`${this.config.apiEndpoint}/banner-response`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                display_log_id: this.config.displayLogId || null,  // May be NULL (fallback to session_id)
                response_type: responseType
            })
        }).catch(error => {
            Need2Talk.Logger.error('CookieConsent', 'Failed to log banner response', error);
        });
    },

    /**
     * Genera session ID per tracking
     */
    generateSessionId() {
        return 'emo_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    },

    /**
     * Sistema fallback se API non disponibile
     */
    initializeFallbackSystem() {
        Need2Talk.Logger.debug('CookieConsent', 'Initializing fallback cookie consent system...');

        // Usa il sistema base esistente
        if (window.Need2Talk && Need2Talk.CookieConsent) {
            Need2Talk.CookieConsent.init();
        }
    },

    // Metodi per abilitare servizi specifici
    enableGoogleAnalytics() {
        // ENTERPRISE: Load Google Tag Manager (GTM) after consent
        // GTM ID: GTM-NJ4H75D3 (includes GA4 + all tracking)
        const gtmId = 'GTM-NJ4H75D3';

        // Verifica se GTM è già caricato
        if (window.dataLayer && window.dataLayer.find(item => item['gtm.start'])) {
            return;
        }

        // Inizializza dataLayer
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            'gtm.start': new Date().getTime(),
            event: 'gtm.js'
        });

        // Carica GTM script
        const script = document.createElement('script');
        script.async = true;
        script.src = `https://www.googletagmanager.com/gtm.js?id=${gtmId}`;
        document.head.appendChild(script);

        // Aggiungi noscript fallback al body (se non esiste già)
        if (!document.querySelector('iframe[src*="googletagmanager.com/ns.html"]')) {
            const noscript = document.createElement('noscript');
            const iframe = document.createElement('iframe');
            iframe.src = `https://www.googletagmanager.com/ns.html?id=${gtmId}`;
            iframe.height = '0';
            iframe.width = '0';
            iframe.style.display = 'none';
            iframe.style.visibility = 'hidden';
            noscript.appendChild(iframe);
            document.body.insertBefore(noscript, document.body.firstChild);
        }

        Need2Talk.Logger.info('CookieConsent', 'Google Tag Manager enabled (GDPR compliant)');
    },

    enableInternalAnalytics() {
        window.internalAnalyticsEnabled = true;
        Need2Talk.Logger.debug('CookieConsent', 'Internal Analytics enabled');
    },

    enablePerformanceMonitoring() {
        if ('PerformanceObserver' in window) {
            const observer = new PerformanceObserver((list) => {
                // Log metriche performance per internal analytics
                const entries = list.getEntries();
                entries.forEach(entry => {
                    Need2Talk.Logger.debug('PerformanceMetrics', `${entry.entryType}`, {
                        name: entry.name,
                        duration: entry.duration,
                        startTime: entry.startTime
                    });
                });
            });
            observer.observe({entryTypes: ['navigation', 'paint', 'largest-contentful-paint']});
        }
        Need2Talk.Logger.debug('CookieConsent', 'Performance Monitoring enabled');
    },

    enableGoogleAds() {
        if (typeof gtag !== 'undefined') {
            gtag('consent', 'update', {
                'ad_storage': 'granted'
            });
        }
        Need2Talk.Logger.debug('CookieConsent', 'Google Ads enabled');
    },

    enableFacebookPixel() {
        // Verifica se l'ID pixel è configurato
        const pixelId = window.FB_PIXEL_ID || document.querySelector('meta[name="fb-pixel-id"]')?.content;

        if (!pixelId || pixelId === 'FB_PIXEL_ID') {
            Need2Talk.Logger.debug('CookieConsent', 'Facebook Pixel ID not configured, skipping initialization');
            return;
        }
        
        if (window.fbq) return;
        
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        
        fbq('init', pixelId);
        fbq('track', 'PageView');

        Need2Talk.Logger.debug('CookieConsent', 'Facebook Pixel enabled', { pixelId });
    },

    enableEmailMarketing() {
        window.emailMarketingEnabled = true;
        Need2Talk.Logger.debug('CookieConsent', 'Email Marketing tracking enabled');
    },

    enableThemePreferences() {
        if (window.ThemeManager) {
            window.ThemeManager.enableSaving = true;
        }
        Need2Talk.Logger.debug('CookieConsent', 'Theme Preferences enabled');
    },

    enableLanguagePreferences() {
        if (window.LanguageManager) {
            window.LanguageManager.enableSaving = true;
        }
        Need2Talk.Logger.debug('CookieConsent', 'Language Preferences enabled');
    },

    enablePlayerSettings() {
        if (window.AudioPlayer) {
            window.AudioPlayer.enableSettingsSaving = true;
        }
        Need2Talk.Logger.debug('CookieConsent', 'Player Settings enabled');
    },

    /**
     * API pubblica
     */
    hasConsent(serviceKey = null) {
        if (!serviceKey) {
            return this.cache.userConsent !== null;
        }
        
        return this.isServiceEnabled(serviceKey);
    },

    /**
     * Ritira consenso utente
     *
     * ENTERPRISE GALAXY GDPR (2025-01-23): Delete __Host-N2T_CONSENT cookie
     * GDPR Art. 7.3: Withdrawal must be as easy as giving consent
     */
    withdrawConsent() {
        this.cache.userConsent = null;
        localStorage.removeItem('need2talk_cookie_consent');

        // ENTERPRISE FIX (2025-01-23): Delete __Host-N2T_CONSENT cookie
        // CRITICAL: Cookie must be deleted with same attributes as set (secure, path, etc.)
        // GDPR Art. 7.3 compliance: Full withdrawal of consent
        document.cookie = '__Host-N2T_CONSENT=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; secure; samesite=Lax';

        if (Need2Talk.CSRF?.getToken()) {
            fetch(`${this.config.apiEndpoint}/withdraw`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });
        }

        this.showConsentBanner();
        Need2Talk.showNotification('ℹ️ Consenso ritirato. Scegli nuove preferenze.', 'info');
    },

    showSettings() {
        this.showGranularSettings();
    },

    // Metodo per tracking emozioni esterno
    trackEmotion(type, emotion, intensity, metadata = {}) {
        if (!this.hasConsent('emotion_' + type)) return false;
        
        this.trackEmotionEvent(type, {
            emotion: emotion,
            intensity: intensity,
            metadata: metadata
        });
        
        return true;
    }
};

// Funzione di inizializzazione riutilizzabile
const initCookieConsent = () => {
    if (Need2Talk.CSRF && Need2Talk.CSRF.getToken()) {
        Need2Talk.Logger.debug('CookieConsent', 'Initializing with CSRF protection');
        Need2Talk.AdvancedCookieConsent.init();
    } else {
        // Retry una sola volta dopo 200ms per permettere a CSRF di inizializzarsi
        setTimeout(() => {
            if (Need2Talk.CSRF && Need2Talk.CSRF.getToken()) {
                Need2Talk.Logger.debug('CookieConsent', 'Initializing with CSRF protection (delayed)');
                Need2Talk.AdvancedCookieConsent.init();
            } else {
                Need2Talk.Logger.debug('CookieConsent', 'Initializing with localStorage fallback (CSRF unavailable)');
                Need2Talk.AdvancedCookieConsent.init();
            }
        }, 200);
    }
};

// Inizializzazione automatica quando l'app è pronta
Need2Talk.events.addEventListener('app:ready', () => {
    initCookieConsent();
});

// FIX: Se l'app è già inizializzata (evento già emesso), inizializza subito
// Questo risolve il timing issue quando questo script viene caricato dopo DOMContentLoaded
if (Need2Talk.initialized) {
    Need2Talk.Logger.debug('CookieConsent', 'App already initialized, initializing cookie consent immediately');
    initCookieConsent();
}

// API pubblica globale
window.cookieConsent = {
    hasConsent: (service) => Need2Talk.AdvancedCookieConsent.hasConsent(service),
    withdraw: () => Need2Talk.AdvancedCookieConsent.withdrawConsent(),
    showSettings: () => Need2Talk.AdvancedCookieConsent.showSettings(),
    trackEmotion: (type, emotion, intensity, metadata) =>
        Need2Talk.AdvancedCookieConsent.trackEmotion(type, emotion, intensity, metadata)
};

} // End of double-load guard