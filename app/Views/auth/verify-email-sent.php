<?php
/**
 * NEED2TALK - EMAIL VERIFICATION SENT PAGE (TAILWIND + FONTAWESOME)
 *
 * FLUSSO ARCHITETTURA:
 * 1. Pagina post-registrazione che invita a verificare email
 * 2. Stile con tema purple/pink
 * 3. Responsive design ottimizzato
 * 4. Performance ottimizzata per migliaia di utenti
 * 5. Sistema modulare CSS/JS con Tailwind compilato
 *
 * ARCHITECTURE: Uses guest.php layout (no HTML duplication)
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Page data for guest.php layout
$title = 'Verifica la tua Email - need2talk';
$description = 'Ti abbiamo inviato un\'email di verifica. Controlla la tua casella di posta e clicca sul link per attivare il tuo account need2talk.';
$userEmail = $_SESSION['verification_email'] ?? 'la tua email';
$userNickname = $_SESSION['verification_nickname'] ?? 'utente';

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

<div class="relative min-h-screen flex items-center justify-center px-4 py-20">
    <div class="w-full max-w-lg space-y-8">

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

                <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-green-400 via-accent-purple to-accent-violet bg-clip-text text-transparent">
                    <?= icon('envelope-open-text', 'w-5 h-5 mr-3') ?>
                    Email Inviata!
                </h1>
                <p class="text-xl text-neutral-silver mb-8">
                    Controlla la tua casella di posta
                </p>
            </div>

            <!-- Success Card - Midnight Aurora Theme -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-green-500/50 shadow-2xl shadow-green-500/20">

                <!-- Success Icon -->
                <div class="flex justify-center mb-6">
                    <div class="w-16 h-16 bg-green-500/20 rounded-full flex items-center justify-center">
                        <?= icon('check', 'w-8 h-8 text-green-400 animate-pulse') ?>
                    </div>
                </div>

                <!-- Main Message -->
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-neutral-white mb-4">Ciao <?= htmlspecialchars($userNickname) ?>!</h2>
                    <p class="text-neutral-silver leading-relaxed mb-6">
                        Ti abbiamo inviato un'email di verifica a:
                    </p>
                    <div class="bg-accent-violet/10 border border-accent-purple/30 rounded-lg p-4 mb-6">
                        <p class="text-purple-300 font-semibold">
                            <?= icon('envelope', 'w-5 h-5 mr-2') ?>
                            <?= htmlspecialchars($userEmail) ?>
                        </p>
                    </div>
                </div>

                <!-- Instructions -->
                <div class="space-y-4 mb-8">
                    <div class="flex items-start space-x-3">
                        <div class="w-8 h-8 bg-blue-500/20 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                            <span class="text-blue-400 font-bold text-sm">1</span>
                        </div>
                        <p class="text-neutral-silver">Apri la tua casella di posta elettronica</p>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="w-8 h-8 bg-blue-500/20 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                            <span class="text-blue-400 font-bold text-sm">2</span>
                        </div>
                        <p class="text-neutral-silver">Cerca l'email da <strong class="text-accent-purple">need2talk</strong></p>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="w-8 h-8 bg-blue-500/20 rounded-full flex items-center justify-center flex-shrink-0 mt-1">
                            <span class="text-blue-400 font-bold text-sm">3</span>
                        </div>
                        <p class="text-neutral-silver">Clicca sul pulsante <strong class="text-green-400">"Verifica Email"</strong></p>
                    </div>
                </div>

                <!-- Important Notes -->
                <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4 mb-6">
                    <h3 class="text-yellow-300 font-semibold mb-2 flex items-center">
                        <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                        Importante
                    </h3>
                    <ul class="text-yellow-200 text-sm space-y-1">
                        <li>• Il link scade tra <strong>1 ora</strong></li>
                        <li>• Controlla anche la cartella <strong>spam/junk</strong></li>
                        <li>• Se non trovi l'email, puoi richiederne una nuova</li>
                    </ul>
                </div>

                <!-- Actions -->
                <div class="space-y-4">
                    <!-- Back to Login - Navbar Style -->
                    <a href="<?= url('auth/login') ?>"
                       class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 flex items-center justify-center group">
                        <?= icon('arrow-left', 'w-5 h-5 mr-2 group-hover:-translate-x-1 transition-transform') ?>
                        Torna al Login
                    </a>

                    <!-- Support Note -->
                    <p class="text-center text-neutral-silver text-sm">
                        <?= icon('info-circle', 'w-5 h-5 mr-1') ?>
                        Non hai ricevuto l'email? Controlla la cartella spam o contatta il supporto.
                    </p>
                </div>
            </div>

            <!-- Help Section -->
            <div class="text-center">
                <p class="text-neutral-silver text-sm mb-4">Problemi con la verifica?</p>
                <a href="<?= url('legal/contacts') ?>"
                   class="text-accent-purple hover:text-accent-lavender transition-colors text-sm">
                    <?= icon('life-ring', 'w-5 h-5 mr-1') ?>
                    Contatta il supporto
                </a>
            </div>
    </div>
</div>

<!-- JavaScript: Auto-redirect and security (inline script - page-specific) -->
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    // ENTERPRISE FIX: Set registration_submitted flag HERE (after successful registration)
    // This prevents the register page from being accessible via back button
    sessionStorage.setItem('registration_submitted', 'true');

    // Clean URL (remove query params)
    if (window.history && window.history.replaceState) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState(null, null, cleanUrl);
    }

    const homeUrl = '<?= url('/') ?>';

    let timeRemaining = 10;

    // Create countdown display
    const countdownElement = document.createElement('div');
    countdownElement.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: rgba(139, 92, 246, 0.9);
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        font-weight: bold;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        z-index: 9999;
        border: 1px solid rgba(255, 255, 255, 0.2);
    `;
    countdownElement.innerHTML = `<?= icon('clock', 'w-5 h-5 mr-2') ?>Auto-redirect in: <span id="countdown">${timeRemaining}</span>s`;
    document.body.appendChild(countdownElement);

    const countdownInterval = setInterval(function() {
        timeRemaining--;
        const countdownSpan = document.getElementById('countdown');
        if (countdownSpan) {
            countdownSpan.textContent = timeRemaining;
        }

        if (timeRemaining <= 3) {
            countdownElement.style.background = 'rgba(239, 68, 68, 0.9)';
        }

        if (timeRemaining <= 0) {
            clearInterval(countdownInterval);

            fetch('<?= url('auth/clear-verification-session') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': window.Need2Talk?.CSRF?.getToken() || ''
                }
            }).finally(() => {
                window.location.replace(homeUrl);
            });
        }
    }, 1000);

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            fetch('<?= url('auth/clear-verification-session') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': window.Need2Talk?.CSRF?.getToken() || ''
                }
            });
        }
    });
});
</script>
<!-- CONTENT END -->

<?php
// Capture content and inject into layout
$content = ob_get_clean();
require APP_ROOT . '/app/Views/layouts/guest.php';
?>