<?php
/**
 * NEED2TALK - EMAIL VERIFICATION PAGE (TAILWIND + FONTAWESOME)
 *
 * FLUSSO ARCHITETTURA:
 * 1. Vista che intercetta e verifica il token di verifica email
 * 2. Gestisce token validi, scaduti e invalidi
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
$title = 'Verifica Email - need2talk';
$description = 'Verifica della tua email in corso. Attendi il completamento della procedura di attivazione account.';

// Gestione stato da controller
$verificationStatus = $_SESSION['verification_status'] ?? 'processing';
$verificationMessage = $_SESSION['verification_message'] ?? '';
$verificationError = $_SESSION['verification_error'] ?? '';
$userNickname = $_SESSION['verified_user_nickname'] ?? '';
$verificationCompletedAt = $_SESSION['verification_completed_at'] ?? null;

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

<div class="relative min-h-screen flex items-center justify-center px-4 py-20" x-data="verificationStatusData('<?= $verificationStatus ?>')">
    <div class="w-full max-w-lg space-y-8">

            <!-- Processing State -->
            <div x-show="status === 'processing'" class="text-center">
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
                    <?= icon('sync-alt', 'w-5 h-5 mr-3 animate-spin') ?>
                    Verifica in corso...
                </h1>
                <p class="text-xl text-neutral-silver mb-8">
                    Attendere il completamento della verifica
                </p>

                <!-- Loading Animation -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-cool-cyan/20 shadow-2xl shadow-cool-cyan/10">
                    <div class="flex justify-center mb-4">
                        <div class="w-16 h-16 border-4 border-cool-cyan/30 border-t-cool-cyan rounded-full animate-spin"></div>
                    </div>
                    <p class="text-neutral-silver">Verificando il tuo token di attivazione...</p>
                </div>
            </div>

            <!-- Success State -->
            <div x-show="status === 'success'" class="text-center">
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

                        <!-- Cerchi concentrici pulsanti - SUCCESS (green) -->
                        <div class="absolute inset-0 rounded-full border-2 border-green-400/50 animate-ping"></div>
                        <div class="absolute -inset-2 rounded-full border border-green-300/30 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                        <div class="absolute -inset-4 rounded-full border border-green-200/20 animate-ping" style="animation-delay: 1s; animation-duration: 3s;"></div>
                    </div>
                </div>

                <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-green-400 via-accent-purple to-accent-violet bg-clip-text text-transparent">
                    <?= icon('check-circle', 'w-5 h-5 mr-3 text-green-400') ?>
                    Email Verificata!
                </h1>
                <p class="text-xl text-neutral-silver mb-8">
                    Benvenuto <?= htmlspecialchars($userNickname) ?>!
                </p>

                <!-- Success Card - Midnight Aurora Theme -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-green-500/50 shadow-2xl shadow-green-500/20 mb-8">
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 bg-green-500/20 rounded-full flex items-center justify-center">
                            <?= icon('star', 'w-8 h-8 text-green-400 animate-pulse') ?>
                        </div>
                    </div>

                    <h2 class="text-2xl font-bold text-neutral-white mb-4">Account Attivato!</h2>
                    <p class="text-neutral-silver leading-relaxed mb-4">
                        La tua email è stata verificata con successo. Ora puoi accedere a tutte le funzionalità di need2talk.
                    </p>

                    <!-- Auto-redirect countdown -->
                    <div class="bg-blue-900/20 border border-blue-500/30 rounded-xl p-4 mb-6">
                        <p class="text-blue-300 text-sm flex items-center justify-center">
                            <?= icon('info-circle', 'w-5 h-5 mr-2') ?>
                            Verrai reindirizzato alla home tra <span class="font-bold mx-1" x-text="redirectCountdown"></span> secondi...
                        </p>
                    </div>

                    <div class="space-y-4">
                        <a href="<?= url('auth/login') ?>"
                           class="flex items-center justify-center w-full px-6 py-4 rounded-xl font-semibold transition-all duration-300 group shadow-lg"
                           style="background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%); color: #ffffff;">
                            <?= icon('sign-in', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                            Accedi al tuo Account
                        </a>

                        <a href="<?= url('/') ?>"
                           class="flex items-center justify-center w-full px-6 py-4 rounded-xl font-medium transition-all duration-300 group"
                           style="background: rgba(30, 30, 40, 0.8); color: #ffffff; border: 1px solid rgba(139, 92, 246, 0.5);">
                            <?= icon('home', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                            Torna alla Home
                        </a>
                    </div>
                </div>
            </div>

            <!-- Error State -->
            <div x-show="status === 'error'" class="text-center">
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

                        <!-- Cerchi concentrici pulsanti - ERROR (red) -->
                        <div class="absolute inset-0 rounded-full border-2 border-energy-magenta/50 animate-ping"></div>
                        <div class="absolute -inset-2 rounded-full border border-red-300/30 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                        <div class="absolute -inset-4 rounded-full border border-red-200/20 animate-ping" style="animation-delay: 1s; animation-duration: 3s;"></div>
                    </div>
                </div>

                <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-red-400 via-orange-400 to-yellow-400 bg-clip-text text-transparent">
                    <?= icon('exclamation-triangle', 'w-5 h-5 mr-3 text-energy-magenta') ?>
                    Verifica Fallita
                </h1>
                <p class="text-xl text-neutral-silver mb-8">
                    Si è verificato un problema
                </p>

                <!-- Error Card - Midnight Aurora Theme -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-energy-magenta/50 shadow-2xl shadow-energy-magenta/20 mb-8">
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 bg-energy-magenta/20 rounded-full flex items-center justify-center">
                            <?= icon('times', 'w-8 h-8 text-energy-magenta') ?>
                        </div>
                    </div>

                    <h2 class="text-2xl font-bold text-neutral-white mb-4">Errore di Verifica</h2>
                    <p class="text-neutral-silver leading-relaxed mb-6">
                        <?= htmlspecialchars($verificationError ?: 'Token di verifica non valido o scaduto.') ?>
                    </p>

                    <div class="space-y-4">
                        <a href="<?= url('auth/resend-verification-form') ?>"
                           class="flex items-center justify-center w-full px-6 py-4 rounded-xl font-semibold transition-all duration-300 group shadow-lg"
                           style="background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%); color: #ffffff;">
                            <?= icon('paper-plane', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                            Richiedi Nuova Email
                        </a>

                        <a href="<?= url('auth/register') ?>"
                           class="flex items-center justify-center w-full px-6 py-4 rounded-xl font-medium transition-all duration-300 group"
                           style="background: rgba(30, 30, 40, 0.8); color: #ffffff; border: 1px solid rgba(139, 92, 246, 0.5);">
                            <?= icon('user-plus', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                            Nuova Registrazione
                        </a>
                    </div>
                </div>
            </div>

            <!-- Already Verified State (token già usato o account già attivo) -->
            <div x-show="status === 'already_verified'" class="text-center">
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

                        <!-- Cerchi concentrici pulsanti - INFO (cyan) -->
                        <div class="absolute inset-0 rounded-full border-2 border-cool-cyan/60 animate-ping"></div>
                        <div class="absolute -inset-2 rounded-full border border-cool-teal/40 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                    </div>

                </div>

                <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-cool-cyan via-accent-purple to-cool-teal bg-clip-text text-transparent">
                    <?= icon('check-double', 'w-5 h-5 mr-3 text-cool-cyan') ?>
                    Account Già Attivo
                </h1>
                <p class="text-xl text-neutral-silver mb-8">
                    Questo account è già stato verificato
                </p>

                <!-- Already Verified Card - Midnight Aurora Theme -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-cool-cyan/30 shadow-2xl shadow-cool-cyan/10 mb-8">
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 bg-cool-cyan/20 rounded-full flex items-center justify-center">
                            <?= icon('user-check', 'w-8 h-8 text-cool-cyan') ?>
                        </div>
                    </div>

                    <h2 class="text-2xl font-bold text-neutral-white mb-4">Email Già Verificata</h2>
                    <p class="text-neutral-silver leading-relaxed mb-6">
                        <?= htmlspecialchars($verificationMessage ?: 'Questo account è già stato verificato. Puoi accedere normalmente.') ?>
                    </p>

                    <div class="space-y-4">
                        <a href="<?= url('auth/login') ?>"
                           class="flex items-center justify-center w-full px-6 py-4 rounded-xl font-semibold transition-all duration-300 group shadow-lg"
                           style="background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%); color: #ffffff;">
                            <?= icon('sign-in', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                            Accedi al tuo Account
                        </a>

                        <a href="<?= url('/') ?>"
                           class="flex items-center justify-center w-full px-6 py-4 rounded-xl font-medium transition-all duration-300 group"
                           style="background: rgba(30, 30, 40, 0.8); color: #ffffff; border: 1px solid rgba(139, 92, 246, 0.5);">
                            <?= icon('home', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                            Torna alla Home
                        </a>
                    </div>
                </div>
            </div>

            <!-- Expired State -->
            <div x-show="status === 'expired'" class="text-center">
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

                        <!-- Cerchi concentrici pulsanti - WARNING (yellow/orange) -->
                        <div class="absolute inset-0 rounded-full border-2 border-yellow-400/50 animate-ping"></div>
                        <div class="absolute -inset-2 rounded-full border border-yellow-300/30 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                        <div class="absolute -inset-4 rounded-full border border-yellow-200/20 animate-ping" style="animation-delay: 1s; animation-duration: 3s;"></div>
                    </div>
                </div>

                <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-yellow-400 via-orange-400 to-red-400 bg-clip-text text-transparent">
                    <?= icon('clock', 'mr-3 text-yellow-400') ?>
                    Token Scaduto
                </h1>
                <p class="text-xl text-neutral-silver mb-8">
                    Il link di verifica è scaduto
                </p>

                <!-- Expired Card - Midnight Aurora Theme -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-yellow-500/30 shadow-2xl shadow-yellow-500/10 mb-8">
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 bg-yellow-500/20 rounded-full flex items-center justify-center">
                            <?= icon('hourglass-end', 'text-yellow-400 text-2xl') ?>
                        </div>
                    </div>

                    <h2 class="text-2xl font-bold text-neutral-white mb-4">Link Scaduto</h2>
                    <p class="text-neutral-silver leading-relaxed mb-6">
                        Il tuo link di verifica è scaduto (1 ora di validità).
                        Inserisci la tua email per ricevere un nuovo link di attivazione.
                    </p>

                    <div class="space-y-4">
                        <a href="<?= url('auth/resend-verification-form') ?>"
                           class="block w-full px-6 py-3 bg-gradient-to-r from-accent-violet to-accent-purple hover:from-accent-purple hover:to-accent-lavender text-neutral-white font-semibold rounded-xl transition-all duration-300 text-center group shadow-lg shadow-accent-purple/30">
                            <?= icon('redo', 'w-5 h-5 mr-2 group-hover:animate-spin') ?>
                            Richiedi Nuovo Link
                        </a>

                        <a href="<?= url('legal/contacts') ?>"
                           class="block w-full px-6 py-3 bg-brand-midnight/50 hover:bg-brand-slate/50 text-neutral-white font-medium rounded-xl transition-all duration-300 text-center border border-neutral-darkGray hover:border-accent-purple/50 group">
                            <?= icon('life-ring', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                            Supporto
                        </a>
                    </div>
                </div>
            </div>
    </div>
</div>

<!-- Alpine.js Data (inline script - page-specific) -->
<script nonce="<?= csp_nonce() ?>">
function verificationStatusData(initialStatus) {
    return {
        status: initialStatus,
        redirectCountdown: 10,
        redirectTimer: null,

        init() {
            // ENTERPRISE SECURITY: Aggressive back button prevention
            if (window.history && window.history.replaceState) {
                const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                window.history.replaceState(null, null, cleanUrl);
            }

            if (this.status === 'success' || this.status === 'already_verified') {
                if (window.history && window.history.pushState) {
                    const homeUrl = '<?= url('/') ?>';
                    window.history.pushState({preventBack: true}, '', window.location.href);

                    window.addEventListener('popstate', (event) => {
                        window.location.replace(homeUrl);
                    }, {capture: true});
                }
            }

            if (this.status === 'processing') {
                setTimeout(() => {
                    this.checkVerificationResult();
                }, 2000);
            }

            if (this.status === 'success' || this.status === 'already_verified') {
                this.startRedirectCountdown();
            }
        },

        startRedirectCountdown() {
            this.redirectTimer = setInterval(() => {
                this.redirectCountdown--;

                if (this.redirectCountdown <= 0) {
                    clearInterval(this.redirectTimer);
                    window.location.replace('<?= url('/') ?>');
                }
            }, 1000);
        },

        checkVerificationResult() {
            const urlParams = new URLSearchParams(window.location.search);
            const result = urlParams.get('result');

            if (result === 'success') {
                this.status = 'success';
                this.startRedirectCountdown();
            } else if (result === 'expired') {
                this.status = 'expired';
            } else if (result === 'error') {
                this.status = 'error';
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