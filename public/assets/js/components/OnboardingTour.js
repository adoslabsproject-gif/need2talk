/**
 * ================================================================================
 * ONBOARDING TOUR - ENTERPRISE GALAXY V2.0
 * ================================================================================
 *
 * Interactive onboarding tour for new users
 * Simple, centered modals with clear navigation
 *
 * FEATURES:
 * - 4-step guided tour (no element highlighting - too buggy)
 * - Always centered modals (better UX on all devices)
 * - Persistent completion tracking
 * - ESC key + overlay click to dismiss
 * - Progress bar + step counter
 *
 * @version 2.0.0 (Simplified)
 * @date 2026-01-19
 * @author Claude Code + zelistore
 */

class OnboardingTour {
    constructor() {
        this.currentStep = 0;
        this.startTime = null;
        this.interactions = 0;
        this.tourVersion = 'v2.0';

        // Simplified tour steps - NO highlighting, only centered modals
        this.steps = [
            {
                id: 1,
                emoji: '🎤',
                title: 'Benvenuto su need2talk!',
                description: 'Qui la tua voce conta.<br>Registra 30 secondi di come ti senti, senza filtri, senza giudizi.<br><br><strong>Il microfono fluttuante è sempre disponibile in basso a destra.</strong>',
                ctaText: 'Avanti'
            },
            {
                id: 2,
                emoji: '📻',
                title: 'Ascolta voci vere',
                description: 'Scorri il feed e ascolta persone vere.<br>Reagisci con emoji, commenta, scopri chi risuona con te.<br><br><strong>Niente foto, niente fake. Solo autenticità.</strong>',
                ctaText: 'Avanti'
            },
            {
                id: 3,
                emoji: '💜',
                title: 'Trova la tua Anima Affine',
                description: 'Non solo amicizie casuali.<br>Vai su <strong>"Anime Affini"</strong> nel menu e scopri chi sente come te.<br><br>Matching basato su consapevolezza emotiva, non su foto filtrate.',
                ctaText: 'Avanti'
            },
            {
                id: 4,
                emoji: '✨',
                title: 'Sei pronto!',
                description: '<div style="font-size: 18px; margin-bottom: 20px;">Ora tocca a te.<br>Registra il tuo primo audio e inizia a connetterti con persone vere.</div><div style="color: #00d9ff; font-size: 14px;">💡 Suggerimento: Gli utenti attivi trovano amicizie 5x più facilmente</div>',
                ctaText: 'Chiudi e inizia! 🚀'
            }
        ];

        this.isActive = false;
        this.overlay = null;
        this.modal = null;

        // Bind methods
        this.start = this.start.bind(this);
        this.nextStep = this.nextStep.bind(this);
        this.previousStep = this.previousStep.bind(this);
        this.skipTour = this.skipTour.bind(this);
        this.completeTour = this.completeTour.bind(this);
    }

    /**
     * Initialize tour (check if user needs it)
     */
    async init() {
        try {
            const response = await fetch('/api/onboarding/status', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrf_token || ''
                }
            });

            if (!response.ok) {
                console.warn('[Onboarding] Failed to check status');
                return;
            }

            const data = await response.json();

            // Show tour if not completed AND not skipped
            if (!data.tour_completed && !data.tour_skipped) {
                setTimeout(() => {
                    this.showWelcomeModal();
                }, 2000);
            }
        } catch (error) {
            console.error('[Onboarding] Init error:', error);
        }
    }

    /**
     * Show welcome modal
     */
    showWelcomeModal() {
        const welcomeModal = document.createElement('div');
        welcomeModal.className = 'onboarding-overlay';
        welcomeModal.innerHTML = `
            <div class="onboarding-overlay-bg"></div>
            <div class="onboarding-modal" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 90%; width: 480px;">
                <div class="onboarding-modal-content">
                    <div class="text-center mb-6">
                        <div style="font-size: 64px; margin-bottom: 16px;">👋</div>
                        <h2 style="color: #ffffff; font-size: 28px; margin-bottom: 16px;">Benvenuto su need2talk!</h2>
                        <p style="color: #d1d5db; font-size: 16px; line-height: 1.6; margin-bottom: 24px;">
                            Facciamo un tour veloce di <strong>1 minuto</strong><br>
                            per scoprire come funziona
                        </p>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <button class="onboarding-btn-primary" id="onboarding-start-tour" style="width: 100%;">
                            Inizia Tour (1 min) 🚀
                        </button>
                        <button class="onboarding-btn-secondary" id="onboarding-skip-welcome" style="width: 100%;">
                            Lo farò dopo
                        </button>
                    </div>
                    <p style="color: #9ca3af; font-size: 12px; margin-top: 16px; text-align: center;">
                        💡 Puoi saltare in qualsiasi momento premendo ESC
                    </p>
                </div>
            </div>
        `;

        document.body.appendChild(welcomeModal);

        document.getElementById('onboarding-start-tour').addEventListener('click', () => {
            welcomeModal.remove();
            this.start();
        });

        document.getElementById('onboarding-skip-welcome').addEventListener('click', () => {
            welcomeModal.remove();
            this.skipTour();
        });

        // ESC to close welcome
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                welcomeModal.remove();
                this.skipTour();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    }

    /**
     * Start tour
     */
    async start() {
        if (this.isActive) return;

        this.isActive = true;
        this.startTime = Date.now();
        this.currentStep = 0;

        this.createOverlay();
        await this.trackProgress('start');
        this.showStep(0);
    }

    /**
     * Create overlay
     */
    createOverlay() {
        this.overlay = document.createElement('div');
        this.overlay.className = 'onboarding-overlay';
        this.overlay.innerHTML = '<div class="onboarding-overlay-bg"></div>';
        document.body.appendChild(this.overlay);

        this.modal = document.createElement('div');
        this.modal.className = 'onboarding-modal';
        this.modal.style.position = 'fixed';
        this.modal.style.top = '50%';
        this.modal.style.left = '50%';
        this.modal.style.transform = 'translate(-50%, -50%)';
        this.modal.style.maxWidth = '90%';
        this.modal.style.width = '480px';
        this.modal.style.zIndex = '10002';
        this.overlay.appendChild(this.modal);

        // Click overlay to dismiss
        const overlayBg = this.overlay.querySelector('.onboarding-overlay-bg');
        if (overlayBg) {
            overlayBg.addEventListener('click', () => this.skipTour());
        }

        // ESC to dismiss
        this.escHandler = (e) => {
            if (e.key === 'Escape' && this.isActive) {
                this.skipTour();
            }
        };
        document.addEventListener('keydown', this.escHandler);
    }

    /**
     * Show step
     */
    showStep(stepIndex) {
        if (stepIndex < 0 || stepIndex >= this.steps.length) return;

        this.currentStep = stepIndex;
        const step = this.steps[stepIndex];
        const isLastStep = stepIndex === this.steps.length - 1;
        const progress = Math.round(((stepIndex + 1) / this.steps.length) * 100);

        this.modal.innerHTML = `
            <div class="onboarding-modal-content">
                <!-- Progress bar -->
                <div class="onboarding-progress">
                    <div class="onboarding-progress-bar" style="width: ${progress}%"></div>
                </div>

                <!-- Step indicator -->
                <div class="onboarding-step-indicator">${stepIndex + 1} di ${this.steps.length}</div>

                <!-- Emoji -->
                <div class="text-center" style="font-size: 64px; margin-bottom: 20px;">${step.emoji}</div>

                <!-- Title -->
                <h3 class="onboarding-modal-title">${step.title}</h3>

                <!-- Description -->
                <div class="onboarding-modal-description">${step.description}</div>

                <!-- Actions -->
                <div class="onboarding-modal-actions">
                    ${stepIndex > 0 ? '<button class="onboarding-btn-back" id="onboarding-back">← Indietro</button>' : ''}
                    <button class="onboarding-btn-primary" id="onboarding-cta">${step.ctaText}</button>
                    <button class="onboarding-btn-skip" id="onboarding-skip">${isLastStep ? 'Chiudi' : 'Salta tour'}</button>
                </div>
            </div>
        `;

        // Track step view
        this.trackProgress('step', stepIndex + 1);

        // Event listeners
        const ctaBtn = document.getElementById('onboarding-cta');
        if (ctaBtn) {
            ctaBtn.addEventListener('click', () => {
                this.interactions++;
                if (isLastStep) {
                    this.completeTour();
                } else {
                    this.nextStep();
                }
            });
        }

        const backBtn = document.getElementById('onboarding-back');
        if (backBtn) {
            backBtn.addEventListener('click', () => {
                this.interactions++;
                this.previousStep();
            });
        }

        const skipBtn = document.getElementById('onboarding-skip');
        if (skipBtn) {
            skipBtn.addEventListener('click', () => {
                this.interactions++;
                if (isLastStep) {
                    this.completeTour();
                } else {
                    this.skipTour();
                }
            });
        }
    }

    /**
     * Next step
     */
    nextStep() {
        if (this.currentStep < this.steps.length - 1) {
            this.showStep(this.currentStep + 1);
        } else {
            this.completeTour();
        }
    }

    /**
     * Previous step
     */
    previousStep() {
        if (this.currentStep > 0) {
            this.showStep(this.currentStep - 1);
        }
    }

    /**
     * Skip tour
     */
    async skipTour() {
        const totalTime = this.startTime ? Math.round((Date.now() - this.startTime) / 1000) : 0;

        try {
            await this.trackProgress('skip', this.currentStep + 1, totalTime);
            console.log('[Onboarding] Tour skipped');
        } catch (error) {
            console.error('[Onboarding] Failed to track skip:', error);
        }

        this.cleanup();
    }

    /**
     * Complete tour
     */
    async completeTour() {
        const totalTime = this.startTime ? Math.round((Date.now() - this.startTime) / 1000) : 0;

        try {
            await this.trackProgress('complete', this.steps.length, totalTime);
            console.log('[Onboarding] Tour completed');
        } catch (error) {
            console.error('[Onboarding] Failed to track completion:', error);
        }

        this.cleanup();
    }

    /**
     * Cleanup
     */
    cleanup() {
        this.isActive = false;

        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }

        if (this.escHandler) {
            document.removeEventListener('keydown', this.escHandler);
        }
    }

    /**
     * Track progress to backend
     */
    async trackProgress(action, step = null, totalTime = null) {
        try {
            const response = await fetch('/api/onboarding/progress', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrf_token || ''
                },
                body: JSON.stringify({
                    action: action,
                    step: step,
                    total_time_seconds: totalTime,
                    interactions_count: this.interactions,
                    tour_version: this.tourVersion
                })
            });

            if (!response.ok) {
                console.warn('[Onboarding] Failed to track progress:', action);
            }
        } catch (error) {
            console.error('[Onboarding] Track error:', error);
        }
    }
}

// Auto-initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.onboardingTour = new OnboardingTour();
        window.onboardingTour.init();
    });
} else {
    window.onboardingTour = new OnboardingTour();
    window.onboardingTour.init();
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = OnboardingTour;
}
