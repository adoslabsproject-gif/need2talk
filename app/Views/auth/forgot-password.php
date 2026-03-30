<?php
/**
 * NEED2TALK - FORGOT PASSWORD PAGE (TAILWIND + FONTAWESOME + ALPINE)
 *
 * ENTERPRISE PASSWORD RESET SYSTEM
 * - Rate limiting (3 tentativi/ora)
 * - Privacy-safe messaging
 * - Async email via 8 workers
 * - Token SHA-256 crittograficamente sicuro
 * - Expiry 1 ora
 *
 * STILE: Glass-morphism + Purple theme identico a login
 *
 * ARCHITECTURE: Uses guest.php layout (no HTML duplication)
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Security: Ensure user is not already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ' . url('/profile'));
    exit;
}

// Get messages
$success = $_SESSION['success'] ?? ($success ?? null);
$error = $_SESSION['error'] ?? ($error ?? null);
$oldInput = $_SESSION['old_input'] ?? ($old_input ?? []);
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['old_input']);

// Page data for guest.php layout
$title = 'Password Dimenticata - need2talk';
$description = 'Reimposta la tua password su need2talk in modo sicuro. Riceverai un\'email con le istruzioni.';

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

<div class="relative min-h-screen flex items-center justify-center px-4 py-20" x-data="forgotPasswordData()">
    <div class="w-full max-w-md space-y-8">

            <!-- Header -->
            <div class="text-center">
                <!-- Logo animato -->
                <div class="flex justify-center mb-6">
                    <div class="relative group">
                        <div class="w-20 h-20 bg-gradient-to-br from-accent-violet to-accent-purple rounded-2xl flex items-center justify-center shadow-xl shadow-accent-purple/30 group-hover:shadow-accent-purple/50 transition-all duration-300">
                            <?= icon('key', 'w-10 h-10 text-white') ?>
                        </div>

                        <!-- Cerchi concentrici pulsanti -->
                        <div class="absolute inset-0 rounded-2xl border-2 border-accent-purple/50 animate-ping"></div>
                        <div class="absolute -inset-2 rounded-2xl border border-purple-300/30 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                    </div>
                </div>

                <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                    Password Dimenticata?
                </h1>
                <p class="text-neutral-silver text-lg mb-2">
                    Nessun problema, ti aiutiamo noi
                </p>
                <p class="text-neutral-silver text-sm">
                    Inserisci la tua email e riceverai le istruzioni
                </p>
            </div>

            <!-- Form Password Dimenticata - Midnight Aurora Theme -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <form method="POST" action="<?php echo url('forgot-password'); ?>" class="space-y-6" id="forgotPasswordForm" @submit="handleSubmit">
                    <?php if (class_exists('Need2Talk\\Middleware\\CsrfMiddleware')) { ?>
                        <?= Need2Talk\Middleware\CsrfMiddleware::tokenInput() ?>
                    <?php } ?>

                    <!-- Success Message -->
                    <?php if (!empty($success)) { ?>
                        <div class="bg-green-900/20 border border-green-500/50 rounded-xl p-4 animate-pulse">
                            <div class="flex items-center mb-2">
                                <?= icon('check-circle', 'w-5 h-5 text-green-400 mr-3') ?>
                                <h3 class="font-semibold text-green-300">Email Inviata!</h3>
                            </div>
                            <p class="text-sm text-green-200 ml-2">
                                <?php echo htmlspecialchars($success) ?>
                            </p>
                            <div class="mt-4 ml-2 text-sm text-green-300">
                                <?= icon('info-circle', 'w-5 h-5 mr-2') ?>
                                Controlla anche la cartella spam/posta indesiderata
                            </div>
                        </div>
                    <?php } ?>

                    <!-- Error Message -->
                    <?php if (!empty($error)) { ?>
                        <div class="bg-energy-magenta/10 border border-energy-magenta/50 rounded-xl p-4">
                            <div class="flex items-center mb-2">
                                <?= icon('exclamation-triangle', 'w-5 h-5 text-energy-magenta mr-3') ?>
                                <h3 class="font-semibold text-red-300">Errore</h3>
                            </div>
                            <p class="text-sm text-red-200 ml-2">
                                <?php echo htmlspecialchars($error) ?>
                            </p>
                        </div>
                    <?php } ?>

                    <!-- Email -->
                    <div class="space-y-2">
                        <label for="email" class="block text-sm font-semibold text-neutral-silver mb-2 flex items-center">
                            <?= icon('envelope', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Indirizzo Email
                        </label>
                        <div class="relative group">
                            <input
                                id="email"
                                name="email"
                                type="email"
                                required
                                value="<?php echo htmlspecialchars($oldInput['email'] ?? ''); ?>"
                                class="w-full px-4 py-4 pl-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-brand-slate/70 focus:text-white transition-all duration-300 group-hover:border-accent-purple/50"
                                placeholder="la-tua-email@esempio.com"
                                autocomplete="email"
                                x-model="formData.email"
                                @blur="validateEmail"
                            >
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('envelope', 'w-5 h-5 text-accent-purple') ?>
                            </div>
                        </div>
                        <p class="text-xs text-neutral-silver mt-2 ml-2">
                            <?= icon('shield', 'w-5 h-5 text-accent-purple mr-1') ?>
                            Il link scadrà tra 1 ora per sicurezza
                        </p>
                    </div>

                    <!-- Submit Button -->
                    <div class="space-y-4">
                        <button
                            type="submit"
                            class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-purple-500 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none group"
                            :disabled="isSubmitting"
                        >
                            <span class="flex items-center justify-center" x-show="!isSubmitting">
                                <?= icon('paper-plane', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                                Invia Link di Reset
                            </span>
                            <span class="flex items-center justify-center" x-show="isSubmitting">
                                <?= icon('spinner', 'w-5 h-5 animate-spin mr-2') ?>
                                Invio in corso...
                            </span>
                        </button>

                        <!-- Divider -->
                        <div class="relative py-4">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-neutral-darkGray"></div>
                            </div>
                            <div class="relative flex justify-center">
                                <span class="px-4 bg-brand-charcoal text-neutral-silver text-sm">
                                    oppure
                                </span>
                            </div>
                        </div>

                        <!-- Back to Login -->
                        <a
                            href="<?php echo url('login'); ?>"
                            class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 flex items-center justify-center group"
                        >
                            <?= icon('arrow-left', 'w-5 h-5 mr-2 group-hover:-translate-x-1 transition-transform') ?>
                            Torna al Login
                        </a>
                    </div>
                </form>
            </div>

            <!-- Security Info -->
            <div class="bg-brand-slate/30 rounded-xl p-6 border border-accent-purple/20">
                <h3 class="text-sm font-semibold text-neutral-white mb-4 flex items-center">
                    <?= icon('shield', 'w-5 h-5 text-accent-purple mr-2') ?>
                    Sicurezza Enterprise
                </h3>
                <div class="space-y-3 text-sm text-neutral-silver">
                    <div class="flex items-start">
                        <?= icon('check', 'w-5 h-5 text-green-400 mr-3 mt-1 flex-shrink-0') ?>
                        <span>Link sicuro crittografato SHA-256</span>
                    </div>
                    <div class="flex items-start">
                        <?= icon('check', 'w-5 h-5 text-green-400 mr-3 mt-1 flex-shrink-0') ?>
                        <span>Scadenza automatica dopo 1 ora</span>
                    </div>
                    <div class="flex items-start">
                        <?= icon('check', 'w-5 h-5 text-green-400 mr-3 mt-1 flex-shrink-0') ?>
                        <span>Utilizzo singolo (non riutilizzabile)</span>
                    </div>
                    <div class="flex items-start">
                        <?= icon('check', 'w-5 h-5 text-green-400 mr-3 mt-1 flex-shrink-0') ?>
                        <span>Rate limiting per prevenire abusi</span>
                    </div>
                </div>
            </div>

            <!-- Help -->
            <div class="text-center text-sm text-neutral-silver">
                <p>Hai bisogno di aiuto?</p>
                <a href="<?php echo url('contact'); ?>" class="text-accent-purple hover:text-accent-lavender transition-colors inline-flex items-center mt-2 group">
                    <?= icon('life-ring', 'w-5 h-5 mr-2') ?>
                    Contatta il supporto
                    <?= icon('arrow-right', 'w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform') ?>
                </a>
            </div>

    </div>
</div>

<!-- Alpine.js Data (inline script - page-specific) -->
<script nonce="<?= csp_nonce() ?>">
function forgotPasswordData() {
    return {
        formData: {
            email: ''
        },
        isSubmitting: false,

        handleSubmit(e) {
            // Basic validation
            if (!this.formData.email || !this.validateEmail()) {
                e.preventDefault();
                alert('Inserisci un indirizzo email valido');
                return false;
            }

            this.isSubmitting = true;
            // Form will submit naturally
        },

        validateEmail() {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(this.formData.email);
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
