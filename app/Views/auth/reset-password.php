<?php
/**
 * NEED2TALK - RESET PASSWORD PAGE (TAILWIND + FONTAWESOME + ALPINE)
 *
 * ENTERPRISE PASSWORD RESET FORM
 * - Token validation via PasswordResetService
 * - Password strength validation
 * - Real-time feedback
 * - Security indicators
 *
 * STILE: Glass-morphism + Purple theme identico a login/forgot
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
$error = $_SESSION['error'] ?? ($error ?? null);
$errors = $_SESSION['errors'] ?? ($errors ?? []);
$success = $_SESSION['success'] ?? ($success ?? null);
$redirect_to_login = $redirect_to_login ?? false;
unset($_SESSION['error'], $_SESSION['errors'], $_SESSION['success']);

// Get token from view data
$token = $token ?? '';
$user = $user ?? null;

// Page data for guest.php layout
$title = 'Reimposta Password - need2talk';
$description = 'Crea una nuova password sicura per il tuo account need2talk.';

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

<div class="relative min-h-screen flex items-center justify-center px-4 py-20" x-data="resetPasswordData()">
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
                    Nuova Password
                </h1>
                <p class="text-neutral-silver text-lg mb-2">
                    Crea una password sicura e memorabile
                </p>
                <?php if ($user) { ?>
                    <p class="text-neutral-silver text-sm">
                        Account: <span class="text-accent-purple font-semibold"><?= htmlspecialchars($user['nickname']) ?></span>
                    </p>
                <?php } ?>
            </div>

            <!-- SUCCESS MESSAGE (quando password cambiata con successo) -->
            <?php if (!empty($success)) { ?>
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/50 shadow-2xl shadow-accent-purple/20 animate-pulse">
                    <div class="flex flex-col items-center text-center space-y-6">
                        <!-- Success Icon -->
                        <div class="relative">
                            <div class="w-24 h-24 bg-gradient-to-br from-accent-violet to-accent-purple rounded-full flex items-center justify-center shadow-xl shadow-accent-purple/50">
                                <?= icon('check', 'w-12 h-12 text-white') ?>
                            </div>
                            <div class="absolute inset-0 rounded-full border-4 border-accent-purple/50 animate-ping"></div>
                        </div>

                        <!-- Success Title -->
                        <h2 class="text-3xl font-bold text-accent-purple">
                            <?= icon('shield-check', 'w-8 h-8 mr-2') ?>
                            Password Aggiornata!
                        </h2>

                        <!-- Success Message -->
                        <p class="text-xl text-neutral-silver">
                            <?= htmlspecialchars($success) ?>
                        </p>

                        <!-- Redirect Countdown -->
                        <div class="bg-brand-midnight/70 rounded-xl p-4 border border-accent-purple/30">
                            <p class="text-neutral-silver mb-2">
                                <?= icon('hourglass-half', 'w-5 h-5 text-accent-purple mr-2') ?>
                                Reindirizzamento al login tra
                            </p>
                            <p class="text-5xl font-bold text-accent-purple" id="countdown">3</p>
                        </div>

                        <!-- Manual Login Link -->
                        <a
                            href="<?= url('login') ?>"
                            class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 flex items-center justify-center group"
                        >
                            <?= icon('sign-in', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                            Vai al Login Ora
                        </a>
                    </div>
                </div>
            <?php } else { ?>

            <!-- Form Reset Password (solo se non c'è successo) -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <form method="POST" action="<?php echo url('reset-password'); ?>" class="space-y-6" id="resetPasswordForm" @submit="handleSubmit">
                    <?php if (class_exists('Need2Talk\\Middleware\\CsrfMiddleware')) { ?>
                        <?= Need2Talk\Middleware\CsrfMiddleware::tokenInput() ?>
                    <?php } ?>

                    <!-- Hidden token field -->
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <!-- Error Messages -->
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

                    <?php if (!empty($errors)) { ?>
                        <div class="bg-energy-magenta/10 border border-energy-magenta/50 rounded-xl p-4">
                            <div class="flex items-center mb-2">
                                <?= icon('exclamation-triangle', 'w-5 h-5 text-energy-magenta mr-3') ?>
                                <h3 class="font-semibold text-red-300">Errori di validazione</h3>
                            </div>
                            <ul class="text-sm text-red-200 space-y-1 ml-2">
                                <?php foreach ($errors as $err) { ?>
                                    <li class="flex items-center">
                                        <?= icon('arrow-right', 'w-4 h-4 text-energy-magenta mr-2') ?>
                                        <?php echo htmlspecialchars($err) ?>
                                    </li>
                                <?php } ?>
                            </ul>
                        </div>
                    <?php } ?>

                    <!-- Nuova Password -->
                    <div class="space-y-2">
                        <label for="password" class="block text-sm font-semibold text-neutral-silver mb-2 flex items-center">
                            <?= icon('key', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Nuova Password
                        </label>
                        <div class="relative group">
                            <input
                                id="password"
                                name="password"
                                :type="showPassword ? 'text' : 'password'"
                                required
                                minlength="8"
                                class="w-full px-4 py-4 pl-12 pr-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-brand-slate/70 focus:text-white transition-all duration-300 group-hover:border-accent-purple/50"
                                placeholder="Minimo 8 caratteri"
                                autocomplete="new-password"
                                x-model="formData.password"
                                @input="checkPasswordStrength"
                            >
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('key', 'w-5 h-5 text-accent-purple') ?>
                            </div>
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-neutral-silver hover:text-white transition-colors"
                            >
                                <span x-show="showPassword" x-cloak><?= icon('eye-slash', 'w-5 h-5') ?></span><span x-show="!showPassword" x-cloak><?= icon('eye', 'w-5 h-5') ?></span>
                            </button>
                        </div>

                        <!-- Password Strength Indicator -->
                        <div class="mt-3" x-show="formData.password.length > 0">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs text-neutral-silver">Sicurezza password:</span>
                                <span class="text-xs font-semibold" :class="{
                                    'text-energy-magenta': passwordStrength < 2,
                                    'text-yellow-400': passwordStrength === 2,
                                    'text-green-400': passwordStrength >= 3
                                }" x-text="passwordStrengthLabel"></span>
                            </div>
                            <div class="h-2 bg-brand-midnight rounded-full overflow-hidden">
                                <div
                                    class="h-full transition-all duration-300"
                                    :class="{
                                        'bg-energy-magenta': passwordStrength < 2,
                                        'bg-yellow-500': passwordStrength === 2,
                                        'bg-green-500': passwordStrength >= 3
                                    }"
                                    :style="`width: ${passwordStrength * 25}%`"
                                ></div>
                            </div>
                        </div>

                        <p class="text-xs text-neutral-silver mt-2 ml-2">
                            <?= icon('info-circle', 'w-5 h-5 text-accent-purple mr-1') ?>
                            Usa lettere, numeri e simboli per maggiore sicurezza
                        </p>
                    </div>

                    <!-- Conferma Password -->
                    <div class="space-y-2">
                        <label for="confirm_password" class="block text-sm font-semibold text-neutral-silver mb-2 flex items-center">
                            <?= icon('check-double', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Conferma Password
                        </label>
                        <div class="relative group">
                            <input
                                id="confirm_password"
                                name="confirm_password"
                                :type="showConfirmPassword ? 'text' : 'password'"
                                required
                                minlength="8"
                                class="w-full px-4 py-4 pl-12 pr-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-brand-slate/70 focus:text-white transition-all duration-300 group-hover:border-accent-purple/50"
                                placeholder="Ripeti la password"
                                autocomplete="new-password"
                                x-model="formData.confirmPassword"
                                @input="checkPasswordMatch"
                            >
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('check-double', 'w-5 h-5 text-accent-purple') ?>
                            </div>
                            <button
                                type="button"
                                @click="showConfirmPassword = !showConfirmPassword"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-neutral-silver hover:text-white transition-colors"
                            >
                                <span x-show="showConfirmPassword" x-cloak><?= icon('eye-slash', 'w-5 h-5') ?></span><span x-show="!showConfirmPassword" x-cloak><?= icon('eye', 'w-5 h-5') ?></span>
                            </button>
                        </div>

                        <!-- Password Match Indicator -->
                        <div class="mt-2" x-show="formData.confirmPassword.length > 0">
                            <p class="text-xs flex items-center" :class="passwordsMatch ? 'text-green-400' : 'text-energy-magenta'">
                                <i class="fas mr-2" :class="passwordsMatch ? 'fa-check-circle' : 'fa-times-circle'"></i>
                                <span x-text="passwordsMatch ? 'Le password coincidono' : 'Le password non coincidono'"></span>
                            </p>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="space-y-4 pt-2">
                        <button
                            type="submit"
                            class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-purple-500 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none group"
                            :disabled="isSubmitting || !passwordsMatch || passwordStrength < 1"
                        >
                            <span class="flex items-center justify-center" x-show="!isSubmitting">
                                <?= icon('shield', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                                Conferma Nuova Password
                            </span>
                            <span class="flex items-center justify-center" x-show="isSubmitting">
                                <?= icon('spinner', 'w-5 h-5 animate-spin mr-2') ?>
                                Aggiornamento in corso...
                            </span>
                        </button>

                        <!-- Back to Login -->
                        <a
                            href="<?php echo url('login'); ?>"
                            class="w-full border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 flex items-center justify-center group"
                        >
                            <?= icon('arrow-left', 'w-5 h-5 mr-2 group-hover:-translate-x-1 transition-transform') ?>
                            Annulla e torna al Login
                        </a>
                    </div>
                </form>
            </div>

            <?php } // End if success?>

            <!-- Security Tips (solo se non c'è successo) -->
            <?php if (empty($success)) { ?>
            <div class="bg-brand-slate/30 rounded-xl p-6 border border-accent-purple/20">
                <h3 class="text-sm font-semibold text-neutral-white mb-4 flex items-center">
                    <?= icon('lightbulb', 'w-5 h-5 text-yellow-400 mr-2') ?>
                    Consigli per una password sicura
                </h3>
                <div class="space-y-3 text-sm text-neutral-silver">
                    <div class="flex items-start">
                        <?= icon('check', 'w-5 h-5 text-green-400 mr-3 mt-1 flex-shrink-0') ?>
                        <span>Almeno 8 caratteri (meglio 12+)</span>
                    </div>
                    <div class="flex items-start">
                        <?= icon('check', 'w-5 h-5 text-green-400 mr-3 mt-1 flex-shrink-0') ?>
                        <span>Mix di lettere maiuscole e minuscole</span>
                    </div>
                    <div class="flex items-start">
                        <?= icon('check', 'w-5 h-5 text-green-400 mr-3 mt-1 flex-shrink-0') ?>
                        <span>Almeno un numero e un simbolo (!@#$%)</span>
                    </div>
                    <div class="flex items-start">
                        <?= icon('times', 'w-5 h-5 text-energy-magenta mr-3 mt-1 flex-shrink-0') ?>
                        <span>Non usare informazioni personali ovvie</span>
                    </div>
                </div>
            </div>
            <?php } // End if not success?>

    </div>
</div>

<!-- ENTERPRISE SECURITY: Auto-redirect and prevent back button -->
<?php if (!empty($success) && $redirect_to_login) { ?>
<script nonce="<?= csp_nonce() ?>">
// ENTERPRISE UX: Auto-redirect countdown
let countdown = 3;
const countdownElement = document.getElementById('countdown');

const countdownInterval = setInterval(() => {
    countdown--;
    if (countdownElement) {
        countdownElement.textContent = countdown;
    }

    if (countdown <= 0) {
        clearInterval(countdownInterval);
        window.location.href = '<?= url('login') ?>';
    }
}, 1000);

// ENTERPRISE SECURITY: Prevent back button after successful password reset
(function() {
    if (window.history && window.history.pushState) {
        window.history.pushState('forward', null, window.location.href);

        window.addEventListener('popstate', function() {
            window.location.href = '<?= url('login') ?>';
        });
    }

    window.addEventListener('beforeunload', function() {
        if (document.body) {
            document.body.innerHTML = '';
        }
    });
})();
</script>
<?php } ?>

<!-- Alpine.js Data (inline script - page-specific) -->
<script nonce="<?= csp_nonce() ?>">
function resetPasswordData() {
    return {
        formData: {
            password: '',
            confirmPassword: ''
        },
        isSubmitting: false,
        showPassword: false,
        showConfirmPassword: false,
        passwordStrength: 0,
        passwordStrengthLabel: 'Troppo debole',
        passwordsMatch: false,

        handleSubmit(e) {
            if (!this.passwordsMatch) {
                e.preventDefault();
                alert('Le password non coincidono');
                return false;
            }

            if (this.formData.password.length < 8) {
                e.preventDefault();
                alert('La password deve essere di almeno 8 caratteri');
                return false;
            }

            if (this.passwordStrength < 1) {
                e.preventDefault();
                alert('La password è troppo debole. Usa lettere, numeri e simboli.');
                return false;
            }

            this.isSubmitting = true;
        },

        checkPasswordStrength() {
            const password = this.formData.password;
            let strength = 0;

            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;

            this.passwordStrength = Math.min(strength, 4);

            if (this.passwordStrength === 0) {
                this.passwordStrengthLabel = 'Troppo debole';
            } else if (this.passwordStrength === 1) {
                this.passwordStrengthLabel = 'Debole';
            } else if (this.passwordStrength === 2) {
                this.passwordStrengthLabel = 'Media';
            } else if (this.passwordStrength === 3) {
                this.passwordStrengthLabel = 'Forte';
            } else {
                this.passwordStrengthLabel = 'Molto forte';
            }

            if (this.formData.confirmPassword.length > 0) {
                this.checkPasswordMatch();
            }
        },

        checkPasswordMatch() {
            this.passwordsMatch = this.formData.password === this.formData.confirmPassword &&
                                 this.formData.password.length > 0;
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
