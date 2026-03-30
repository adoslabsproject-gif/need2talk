<?php
/**
 * NEED2TALK - RESEND VERIFICATION PAGE (TAILWIND + FONTAWESOME)
 *
 * FLUSSO ARCHITETTURA:
 * 1. Vista per richiedere nuova email di verifica quando token scaduto
 * 2. Form con validazione client-side e rate limiting
 * 3. Stile con tema purple/pink
 * 4. Responsive design ottimizzato
 * 5. Performance ottimizzata per migliaia di utenti
 *
 * ARCHITECTURE: Uses guest.php layout (no HTML duplication)
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Page data for guest.php layout
$title = 'Richiedi Nuova Verifica - need2talk';
$description = 'Richiedi una nuova email di verifica per il tuo account need2talk. Il link precedente potrebbe essere scaduto.';

// Gestione errori e messaggi
$errors = $_SESSION['errors'] ?? [];
$oldInput = $_SESSION['old_input'] ?? [];
$success = $_SESSION['success'] ?? '';
unset($_SESSION['errors'], $_SESSION['old_input'], $_SESSION['success']);

// Start content buffer
ob_start();
?>

<!-- CONTENT START (injected in guest.php layout via $content variable) -->
<!-- Background Animato - Midnight Aurora Theme -->
<div class="fixed inset-0 bg-gradient-to-br from-brand-midnight via-brand-slate to-brand-midnight pointer-events-none">
    <!-- Floating particles -->
    <div class="absolute inset-0 overflow-hidden">
        <div class="absolute w-2 h-2 bg-accent-purple/30 rounded-full animate-bounce" style="top: 20%; left: 10%; animation-delay: 0s; animation-duration: 3s;"></div>
        <div class="absolute w-1 h-1 bg-energy-pink/40 rounded-full animate-bounce" style="top: 40%; left: 80%; animation-delay: 1s; animation-duration: 4s;"></div>
        <div class="absolute w-3 h-3 bg-accent-violet/20 rounded-full animate-bounce" style="top: 60%; left: 20%; animation-delay: 2s; animation-duration: 5s;"></div>
        <div class="absolute w-1 h-1 bg-cool-cyan/30 rounded-full animate-bounce" style="top: 80%; left: 70%; animation-delay: 1.5s; animation-duration: 3.5s;"></div>
        <div class="absolute w-2 h-2 bg-accent-purple/25 rounded-full animate-bounce" style="top: 30%; left: 60%; animation-delay: 0.5s; animation-duration: 4.5s;"></div>
    </div>

    <!-- Gradient overlay -->
    <div class="absolute inset-0 bg-gradient-to-t from-brand-midnight/50 to-transparent"></div>
</div>

<div class="relative min-h-screen flex items-center justify-center px-4 py-20" x-data="resendVerificationData()">
    <div class="w-full max-w-md space-y-8">

            <!-- Header -->
            <div class="text-center">
                <!-- Logo animato -->
                <div class="flex justify-center mb-8">
                    <div class="relative w-24 h-24">
                        <div class="absolute inset-0 bg-gradient-to-br from-purple-600 to-pink-600 rounded-3xl shadow-xl shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300"></div>
                        <picture>
                            <source srcset="<?php echo asset('img/logo-192.webp'); ?>" type="image/webp">
                            <img src="<?php echo asset('img/logo-192.png'); ?>"
                                 alt="need2talk Logo"
                                 class="absolute inset-0 w-full h-full rounded-3xl object-cover"
                                 loading="lazy"
                                 decoding="async"
                                 width="96"
                                 height="96">
                        </picture>

                        <!-- Cerchi concentrici pulsanti -->
                        <div class="absolute inset-0 rounded-full border-2 border-pink-500/60 animate-ping"></div>
                        <div class="absolute -inset-2 rounded-full border border-purple-500/40 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                    </div>
                </div>

                <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                    <?= icon('redo', 'w-5 h-5 mr-3') ?>
                    Nuova Verifica
                </h1>
                <p class="text-xl text-neutral-silver mb-8">
                    Richiedi una nuova email di verifica
                </p>
            </div>

            <!-- Success Message -->
            <?php if ($success) { ?>
            <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mb-6 animate-fade-in">
                <div class="flex items-center">
                    <?= icon('check-circle', 'w-5 h-5 text-green-400 mr-3') ?>
                    <p class="text-green-200"><?= htmlspecialchars($success) ?></p>
                </div>
            </div>
            <?php } ?>

            <!-- Main Form - Midnight Aurora Theme -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">

                <!-- Info Section -->
                <div class="mb-6">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-yellow-500/20 rounded-full flex items-center justify-center mr-4">
                            <?= icon('info-circle', 'text-yellow-400 text-xl') ?>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-neutral-white">Link Scaduto?</h2>
                            <p class="text-neutral-silver text-sm">Nessun problema, richiedine uno nuovo</p>
                        </div>
                    </div>

                    <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4">
                        <p class="text-blue-300 text-sm">
                            <?= icon('lightbulb', 'w-5 h-5 mr-2') ?>
                            Inserisci l'email utilizzata per la registrazione.
                            Ti invieremo un nuovo link di verifica valido per 1 ora.
                        </p>
                    </div>

                    <!-- ENTERPRISE v6.7: Spam Folder Warning -->
                    <div class="mt-4 bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4">
                        <div class="flex items-start">
                            <?= icon('exclamation-triangle', 'w-5 h-5 text-yellow-400 mr-3 mt-0.5 flex-shrink-0') ?>
                            <div class="text-sm">
                                <p class="text-yellow-300 font-semibold mb-1">Controlla la cartella Spam!</p>
                                <p class="text-yellow-200/80">
                                    L'email potrebbe finire nella cartella <strong class="text-white">Spam</strong> o <strong class="text-white">Posta Indesiderata</strong>.
                                    Se non la trovi, cerca "<strong class="text-white">need2talk</strong>" nella barra di ricerca della tua casella email.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <form method="POST" action="<?= url('auth/resend-verification') ?>" @submit="handleSubmit" x-ref="resendForm">

                    <!-- CSRF Token -->
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                    <!-- Email Field -->
                    <div class="mb-6">
                        <label for="email" class="block text-sm font-medium text-neutral-silver mb-2">
                            <?= icon('envelope', 'w-5 h-5 mr-1') ?>
                            Indirizzo Email
                        </label>
                        <div class="relative">
                            <input type="email"
                                   id="email"
                                   name="email"
                                   value="<?= htmlspecialchars($oldInput['email'] ?? '') ?>"
                                   x-model="formData.email"
                                   @input="validateEmail"
                                   required
                                   autocomplete="email"
                                   placeholder="Inserisci la tua email"
                                   class="w-full px-4 py-3 bg-brand-midnight/50 border border-gray-600 rounded-lg text-white placeholder-neutral-silver focus:ring-2 focus:ring-accent-purple focus:border-transparent focus:text-white transition-all duration-300"
                                   :class="{'border-energy-magenta': emailError, 'border-green-500': emailValid && formData.email}">
                            <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                                <span x-show="emailValid && formData.email" x-cloak><?= icon('check', 'w-5 h-5 text-green-400') ?></span>
                                <span x-show="emailError" x-cloak><?= icon('times', 'w-5 h-5 text-energy-magenta') ?></span>
                            </div>
                        </div>

                        <!-- Email Error -->
                        <p x-show="emailError" x-text="emailError" class="mt-2 text-sm text-energy-magenta"></p>

                        <!-- Server Errors -->
                        <?php if (isset($errors['email'])) { ?>
                        <p class="mt-2 text-sm text-energy-magenta">
                            <?= icon('exclamation-circle', 'w-5 h-5 mr-1') ?>
                            <?= htmlspecialchars($errors['email']) ?>
                        </p>
                        <?php } ?>
                    </div>

                    <!-- General Errors -->
                    <?php if (isset($errors['general'])) { ?>
                    <div class="mb-6 bg-energy-magenta/100/10 border border-energy-magenta/30 rounded-lg p-4">
                        <p class="text-red-200">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <?= htmlspecialchars($errors['general']) ?>
                        </p>
                    </div>
                    <?php } ?>

                    <!-- Submit Button -->
                    <button type="submit"
                            :disabled="!isFormValid || isSubmitting"
                            class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-purple-500 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none group"
                            :class="{'animate-pulse': isSubmitting}">

                        <!-- Loading State -->
                        <span x-show="isSubmitting" class="flex items-center justify-center">
                            <?= icon('spinner', 'w-5 h-5 animate-spin mr-2') ?>
                            Invio in corso...
                        </span>

                        <!-- Normal State -->
                        <span x-show="!isSubmitting" class="flex items-center justify-center">
                            <?= icon('paper-plane', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                            Invia Nuova Email
                        </span>
                    </button>
                </form>

                <!-- Alternative Actions -->
                <div class="mt-6 pt-6 border-t border-neutral-darkGray">
                    <div class="space-y-3">
                        <a href="<?= url('auth/login') ?>"
                           class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 flex items-center justify-center group">
                            <?= icon('sign-in', 'w-5 h-5 mr-2 group-hover:-translate-x-1 transition-transform') ?>
                            Torna al Login
                        </a>

                        <a href="<?= url('auth/register') ?>"
                           class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 flex items-center justify-center group">
                            <?= icon('user-plus', 'w-5 h-5 mr-2 group-hover:scale-110 transition-transform') ?>
                            Crea Nuovo Account
                        </a>
                    </div>
                </div>
            </div>

            <!-- Help Section -->
            <div class="text-center text-sm text-neutral-silver">
                <p class="mb-4">Hai ancora problemi?</p>
                <a href="<?= url('legal/report') ?>"
                   class="inline-flex items-center text-accent-purple hover:text-accent-lavender transition-colors group">
                    <?= icon('life-ring', 'w-5 h-5 mr-2') ?>
                    Contatta il supporto
                    <?= icon('arrow-right', 'w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform') ?>
                </a>
            </div>
    </div>
</div>

<!-- ENTERPRISE: Anti-Back-Button Protection (inline script - page-specific) -->
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    console.info('[Resend Verification] Page loaded - CSRF and logging systems initialized');

    // Double-submit protection (non-blocking)
    if (sessionStorage.getItem('resend_email_submitted')) {
        console.info('[Security] Form already submitted - clearing flag');
        sessionStorage.removeItem('resend_email_submitted');
    }
});
</script>

<!-- Alpine.js Data (inline script - page-specific) -->
<script nonce="<?= csp_nonce() ?>">
function resendVerificationData() {
    return {
        formData: {
            email: ''
        },
        emailError: '',
        emailValid: false,
        isSubmitting: false,

        get isFormValid() {
            return this.emailValid && !this.emailError;
        },

        validateEmail() {
            const email = this.formData.email.trim();

            if (!email) {
                this.emailError = '';
                this.emailValid = false;
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                this.emailError = 'Formato email non valido';
                this.emailValid = false;
                return;
            }

            const suspiciousDomains = [
                '10minutemail.com', 'guerrillamail.com', 'mailinator.com',
                'tempmail.org', 'yopmail.com', 'throwaway.email'
            ];

            const domain = email.split('@')[1];
            if (suspiciousDomains.includes(domain)) {
                this.emailError = 'Utilizza un indirizzo email permanente';
                this.emailValid = false;
                return;
            }

            this.emailError = '';
            this.emailValid = true;
        },

        async handleSubmit(event) {
            if (!this.isFormValid || this.isSubmitting) {
                event.preventDefault();
                return;
            }

            this.isSubmitting = true;
            sessionStorage.setItem('resend_email_submitted', 'true');

            try {
                if (window.Need2Talk && window.Need2Talk.CSRF) {
                    const freshToken = window.Need2Talk.CSRF.getToken();
                    const tokenInput = this.$refs.resendForm.querySelector('input[name="_csrf_token"]');
                    if (tokenInput && freshToken) {
                        tokenInput.value = freshToken;
                        console.debug('[CSRF] Token refreshed in form');
                    }
                }

                console.info('[Security] Form submission started');
            } catch (error) {
                console.warn('Could not refresh CSRF token:', error);
            }
        }
    }
}
</script>
<!-- CONTENT END -->

<?php
// Capture content and inject into layout
$content = ob_get_clean();
require APP_ROOT . '/app/Views/layouts/guest.php';
?>