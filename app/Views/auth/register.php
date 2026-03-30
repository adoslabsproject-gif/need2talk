<?php
/**
 * NEED2TALK - REGISTER PAGE (TAILWIND + FONTAWESOME + ALPINE)
 *
 * FLUSSO ARCHITETTIRA:
 * 1. Registrazione form per nuovi utenti con sicurezza avanzata
 * 2. Stile - Glass-morphism + Purple theme
 * 3. Responsive design ottimizzato per migliaia di utenti
 * 4. Performance GPU-accelerated animations con Alpine.js
 * 5. Security: CSRF protection, rate limiting, form validation, honeypot
 * 6. Real-time validation: email/nickname availability, password strength
 * 7. Age verification 18+ con controlli multipli
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Ensure bootstrap is loaded for helper functions
if (!function_exists('url')) {
    require_once APP_ROOT . '/app/bootstrap.php';
}

// ENTERPRISE GALAXY: Session ALWAYS active (industry standard)
// Bootstrap always starts session for ALL requests (no conditional logic)
// GDPR: Session cookies are "strictly necessary" (no consent required)
$_SESSION['form_start_time'] = $_SESSION['form_start_time'] ?? time();

// SECURITY: Anti-bot honeypot fields
$honeypotFields = [
    'hp_email' => '', 'hp_name' => '', 'hp_phone' => '',
    'website' => '', 'url' => '', 'homepage' => '',
];

// SECURITY: Redirect se già autenticato
if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
    header('Location: ' . url('profile'));
    exit;
}

// DATA: Form configuration
$title = 'Registrati su need2talk - La tua voce alle emozioni';
$description = 'Crea il tuo account gratuito su need2talk per condividere le tue emozioni attraverso la voce. Piattaforma sicura per maggiorenni.';

// ENTERPRISE: Page-specific CSS
$pageCSS = ['pages/register'];

// PERFORMANCE: Gestione errori con auto-cleanup
$errors = $_SESSION['errors'] ?? [];
$oldInput = $_SESSION['old_input'] ?? [];
unset($_SESSION['errors'], $_SESSION['old_input']);

// AGE VERIFICATION: Configurazione validazione età
$currentYear = date('Y');
$minAge = 18;
$maxBirthYear = $currentYear - $minAge;
$minBirthYear = 1920;

// LOCALIZATION: Mesi per dropdown
$months = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
];

$redirectTo = $_GET['redirect'] ?? null;

// ENTERPRISE: Start output buffering for content injection
ob_start();
?>

<!-- PAGE-SPECIFIC inline CSS -->
<style nonce="<?= csp_nonce() ?>">
.honeypot {
    position: absolute !important;
    left: -9999px !important;
    opacity: 0 !important;
    pointer-events: none !important;
    visibility: hidden !important;
}
</style>

<!-- CONTENT ONLY (Alpine.js reactive wrapper) -->
<div class="min-h-screen" x-data="registerData()">

    <!-- Background Animato -->
    <div class="fixed inset-0 bg-gradient-to-br from-brand-midnight via-brand-slate to-brand-midnight pointer-events-none">
        <!-- Floating particles -->
        <div class="absolute inset-0 overflow-hidden">
            <div class="absolute w-2 h-2 bg-accent-purple/30 rounded-full animate-bounce" style="top: 20%; left: 10%; animation-delay: 0s; animation-duration: 3s;"></div>
            <div class="absolute w-1 h-1 bg-energy-pink/40 rounded-full animate-bounce" style="top: 40%; left: 80%; animation-delay: 1s; animation-duration: 4s;"></div>
            <div class="absolute w-3 h-3 bg-accent-violet/20 rounded-full animate-bounce" style="top: 60%; left: 20%; animation-delay: 2s; animation-duration: 5s;"></div>
            <div class="absolute w-1 h-1 bg-energy-pink/30 rounded-full animate-bounce" style="top: 80%; left: 70%; animation-delay: 1.5s; animation-duration: 3.5s;"></div>
            <div class="absolute w-2 h-2 bg-accent-purple/25 rounded-full animate-bounce" style="top: 30%; left: 60%; animation-delay: 0.5s; animation-duration: 4.5s;"></div>
        </div>

        <!-- Gradient overlay -->
        <div class="absolute inset-0 bg-gradient-to-t from-brand-midnight/50 to-transparent"></div>
    </div>

    <!-- Main Content -->
    <div class="relative min-h-screen flex items-center justify-center px-4 py-20">
        <div class="w-full max-w-md space-y-6">

            <!-- Header -->
            <div class="text-center">
                <!-- Logo animato -->
                <div class="flex justify-center mb-6">
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

                <h1 style="color: #ffffff; -webkit-text-fill-color: transparent; -webkit-background-clip: text; background-clip: text;" class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet animate-pulse">
                    Unisciti a need2talk
                </h1>
                <p class="text-neutral-silver text-lg mb-6">
                    Crea il tuo account gratuito
                </p>
            </div>


            <!-- ENTERPRISE V12.5: Friendly tip for in-app browsers and best experience -->
            <div id="browserTip" class="hidden bg-purple-900/30 border border-purple-500/40 rounded-xl p-4 mb-6">
                <div class="flex items-start gap-3">
                    <div class="text-purple-400 text-xl flex-shrink-0">💡</div>
                    <div>
                        <div class="text-purple-300 font-medium mb-1" id="tipTitle">
                            Consiglio per la migliore esperienza
                        </div>
                        <p class="text-purple-200/80 text-sm" id="tipMessage">
                            Per accedere a tutte le funzionalità (chat privata, diario crittografato),
                            ti consigliamo di aprire need2talk in <strong class="text-white">Safari</strong> o <strong class="text-white">Chrome</strong>
                            e <strong class="text-white">aggiungerlo alla schermata Home</strong> come app.
                        </p>
                        <div id="tipInstructions" class="hidden mt-3 bg-purple-950/50 rounded-lg p-3 text-xs text-purple-200">
                            <strong class="text-white">Come fare:</strong><br>
                            <span class="text-purple-300">1.</span> Tocca i <strong class="text-white">tre puntini</strong> ⋮ in alto<br>
                            <span class="text-purple-300">2.</span> Seleziona "<strong class="text-white">Apri in Safari</strong>" o "<strong class="text-white">Apri nel browser</strong>"
                        </div>
                    </div>
                </div>
            </div>
            <script nonce="<?= csp_nonce() ?>">
                // ENTERPRISE V12.5: Unified friendly browser detection (no scary warnings)
                (function() {
                    var ua = navigator.userAgent || navigator.vendor || window.opera;
                    var tip = document.getElementById('browserTip');
                    var tipTitle = document.getElementById('tipTitle');
                    var tipMessage = document.getElementById('tipMessage');
                    var tipInstructions = document.getElementById('tipInstructions');

                    // Detect specific in-app browsers
                    var inAppPatterns = {
                        'Facebook': /FBAN|FBAV|FB_IAB|FB4A|FBIOS|FBSS/i,
                        'Instagram': /Instagram/i,
                        'TikTok': /musical_ly|TikTok|BytedanceWebview/i,
                        'Snapchat': /Snapchat/i,
                        'Twitter': /Twitter/i,
                        'LinkedIn': /LinkedIn/i,
                        'Pinterest': /Pinterest/i,
                        'Telegram': /TelegramBot/i,
                        'WhatsApp': /WhatsApp/i
                    };

                    var detectedApp = null;
                    for (var app in inAppPatterns) {
                        if (inAppPatterns[app].test(ua)) {
                            detectedApp = app;
                            break;
                        }
                    }

                    // Show tip for in-app browsers with customized message
                    if (detectedApp && tip) {
                        tipTitle.textContent = 'Installa need2talk come app';
                        tipMessage.innerHTML = 'Stai navigando da <strong class="text-white">' + detectedApp + '</strong>. ' +
                            'Per la migliore esperienza, apri need2talk in <strong class="text-white">Safari</strong> o <strong class="text-white">Chrome</strong> ' +
                            'e <strong class="text-white">aggiungilo alla schermata Home</strong> come app!';
                        tipInstructions.classList.remove('hidden');
                        tip.classList.remove('hidden');
                        return; // Don't check crypto if already showing in-app tip
                    }

                    // Test Web Crypto API availability for non-in-app browsers
                    if (typeof window.crypto === 'undefined' || typeof window.crypto.subtle === 'undefined') {
                        if (tip) tip.classList.remove('hidden');
                    } else {
                        try {
                            window.crypto.subtle.generateKey(
                                { name: 'AES-GCM', length: 256 },
                                true,
                                ['encrypt', 'decrypt']
                            ).then(function(key) {
                                // Success - crypto works, tip stays hidden
                            }).catch(function(err) {
                                if (tip) tip.classList.remove('hidden');
                            });
                        } catch (e) {
                            if (tip) tip.classList.remove('hidden');
                        }
                    }
                })();
            </script>

            <!-- Registration Form -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">

                <!-- ENTERPRISE GALAXY: Google OAuth Register Button -->
                <div class="mb-6">
                    <a href="<?php echo url('auth/google'); ?>"
                       class="w-full bg-gray-900 hover:bg-gray-800 font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg border border-gray-700 hover:border-gray-600 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:ring-offset-2 focus:ring-offset-brand-charcoal group flex items-center justify-center"
                       style="color: #ffffff !important;">
                        <!-- Google Logo (Official Colors) -->
                        <svg class="w-5 h-5 mr-3" viewBox="0 0 24 24">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        <span style="color: #ffffff !important;">Registrati con Google</span>
                    </a>
                </div>

                <!-- Divider -->
                <div class="relative mb-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-600"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-4 bg-brand-charcoal text-gray-400">oppure</span>
                    </div>
                </div>

                <form method="POST"
                      action="<?= url('/auth/register') ?>"
                      @submit="handleSubmit"
                      class="space-y-6"
                      id="registerForm"
                      novalidate>

                    <!-- CSRF Protection -->
                    <?php if (class_exists('Need2Talk\\Middleware\\CsrfMiddleware')) { ?>
                        <?= Need2Talk\Middleware\CsrfMiddleware::tokenInput() ?>
                    <?php } ?>

                    <!-- ENTERPRISE GALAXY: reCAPTCHA v3 token (invisible, ALWAYS required) -->
                    <input type="hidden" name="recaptcha_token" id="recaptchaToken">

                    <!-- Redirect Field -->
                    <?php if ($redirectTo) { ?>
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo) ?>">
                    <?php } ?>

                    <!-- Honeypot Fields -->
                    <?php foreach ($honeypotFields as $fieldName => $value) { ?>
                        <input type="text"
                               name="<?= $fieldName ?>"
                               value="<?= $value ?>"
                               class="honeypot"
                               tabindex="-1"
                               autocomplete="off">
                    <?php } ?>

                    <!-- Form Timing -->
                    <input type="hidden" name="form_start_time" value="<?= $_SESSION['form_start_time'] ?>">
                    <input type="hidden" name="mouse_movements" value="0" id="mouse_movements">
                    <input type="hidden" name="device_fingerprint" value="" id="device_fingerprint">

                    <!-- Error Messages -->
                    <?php if (!empty($errors)) { ?>
                        <div class="bg-energy-magenta/10 border border-energy-magenta/50 rounded-xl p-4 mb-6 animate-pulse">
                            <div class="flex items-center mb-2">
                                <?= icon('exclamation-triangle', 'w-5 h-5 text-energy-magenta mr-3') ?>
                                <h3 class="font-semibold text-red-300">Errori di registrazione</h3>
                            </div>
                            <ul class="text-sm text-red-200 space-y-1 ml-2">
                                <?php foreach ($errors as $error) { ?>
                                    <li class="flex items-center">
                                        <?= icon('arrow-right', 'w-4 h-4 text-energy-magenta mr-2') ?>
                                        <?php echo htmlspecialchars($error) ?>
                                    </li>
                                <?php } ?>
                            </ul>
                        </div>
                    <?php } ?>

                    <!-- Email Field -->
                    <div class="space-y-2">
                        <label for="email" class="block text-sm font-semibold text-accent-violet flex items-center">
                            <?= icon('envelope', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Email
                        </label>
                        <div class="relative group">
                            <input type="email"
                                   id="email"
                                   name="email"
                                   class="w-full px-4 py-4 pl-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-white focus:text-gray-900 transition-all duration-300 group-hover:border-accent-purple/50"
                                   placeholder="la-tua-email@esempio.it"
                                   value="<?= htmlspecialchars($oldInput['email'] ?? '') ?>"
                                   x-model="formData.email"
                                   @blur="validateEmail"
                                   @input="checkEmailAvailable"
                                   required
                                   autocomplete="email"
                                   autofocus>
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('envelope', 'w-5 h-5 text-accent-purple group-focus-within:text-gray-900 transition-colors') ?>
                            </div>
                        </div>

                        <!-- Email Error -->
                        <div x-show="errors.email" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.email"></span>
                        </div>

                        <!-- Email Provider Notice (ENTERPRISE: Deliverability Info) -->
                        <div class="mt-3 bg-accent-violet/10 border border-accent-violet/30 rounded-lg p-3 text-xs text-neutral-silver">
                            <div class="flex items-start">
                                <?= icon('info-circle', 'w-4 h-4 text-accent-violet mr-2 mt-0.5 flex-shrink-0') ?>
                                <p>
                                    <span class="font-semibold text-accent-violet">Nota:</span>
                                    Alcuni provider email esteri (es. mail.ru, yandex.ru) potrebbero filtrare automaticamente le nostre email di verifica.
                                    Per garantire la ricezione, consigliamo Gmail, Outlook, Yahoo o provider italiani.
                                </p>
                            </div>
                        </div>

                        <!-- ENTERPRISE v6.7: Spam Folder Warning -->
                        <div class="mt-3 bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-3 text-xs text-neutral-silver">
                            <div class="flex items-start">
                                <?= icon('exclamation-triangle', 'w-4 h-4 text-yellow-400 mr-2 mt-0.5 flex-shrink-0') ?>
                                <p>
                                    <span class="font-semibold text-yellow-400">Importante:</span>
                                    Dopo la registrazione, controlla la <strong class="text-white">cartella Spam/Posta Indesiderata</strong>.
                                    Se non trovi l'email, cerca "<strong class="text-white">need2talk</strong>" nella barra di ricerca della tua casella email.
                                </p>
                            </div>
                        </div>

                        <!-- Email Available -->
                        <div x-show="!emailChecking && availabilityStatus.email === 'available'" class="text-cool-cyan text-sm flex items-center mt-2">
                            <?= icon('check-circle', 'w-5 h-5 mr-2') ?>
                            Email disponibile
                        </div>

                        <!-- Email Unavailable -->
                        <div x-show="!emailChecking && availabilityStatus.email === 'unavailable'" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('times-circle', 'w-5 h-5 mr-2') ?>
                            Questa email è già registrata
                        </div>

                        <!-- Email Checking -->
                        <div x-show="emailChecking" class="text-neutral-silver text-sm flex items-center mt-2">
                            <?= icon('spinner', 'w-5 h-5 animate-spin mr-2') ?>
                            Controllo disponibilità...
                        </div>
                    </div>

                    <!-- Name Field -->
                    <div class="space-y-2">
                        <label for="name" class="block text-sm font-semibold text-accent-violet flex items-center">
                            <?= icon('signature', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Nome
                        </label>
                        <div class="relative group">
                            <input type="text"
                                   id="name"
                                   name="name"
                                   class="w-full px-4 py-4 pl-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-white focus:text-gray-900 transition-all duration-300 group-hover:border-accent-purple/50"
                                   placeholder="Il tuo nome"
                                   value="<?= htmlspecialchars($oldInput['name'] ?? '') ?>"
                                   x-model="formData.name"
                                   @blur="validateName"
                                   required
                                   autocomplete="given-name"
                                   minlength="2"
                                   maxlength="100">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('signature', 'w-5 h-5 text-accent-purple group-focus-within:text-gray-900 transition-colors') ?>
                            </div>
                        </div>

                        <!-- Name Error -->
                        <div x-show="errors.name" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.name"></span>
                        </div>
                    </div>

                    <!-- Surname Field -->
                    <div class="space-y-2">
                        <label for="surname" class="block text-sm font-semibold text-accent-violet flex items-center">
                            <?= icon('id-card', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Cognome
                        </label>
                        <div class="relative group">
                            <input type="text"
                                   id="surname"
                                   name="surname"
                                   class="w-full px-4 py-4 pl-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-white focus:text-gray-900 transition-all duration-300 group-hover:border-accent-purple/50"
                                   placeholder="Il tuo cognome"
                                   value="<?= htmlspecialchars($oldInput['surname'] ?? '') ?>"
                                   x-model="formData.surname"
                                   @blur="validateSurname"
                                   required
                                   autocomplete="family-name"
                                   minlength="2"
                                   maxlength="100">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('id-card', 'w-5 h-5 text-accent-purple group-focus-within:text-gray-900 transition-colors') ?>
                            </div>
                        </div>

                        <!-- Surname Error -->
                        <div x-show="errors.surname" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.surname"></span>
                        </div>
                    </div>

                    <!-- Nickname Field -->
                    <div class="space-y-2">
                        <label for="nickname" class="block text-sm font-semibold text-accent-violet flex items-center">
                            <?= icon('user', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Nome Utente
                        </label>
                        <div class="relative group">
                            <input type="text"
                                   id="nickname"
                                   name="nickname"
                                   class="w-full px-4 py-4 pl-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-white focus:text-gray-900 transition-all duration-300 group-hover:border-accent-purple/50"
                                   placeholder="il-tuo-nickname"
                                   value="<?= htmlspecialchars($oldInput['nickname'] ?? '') ?>"
                                   x-model="formData.nickname"
                                   @blur="validateNickname"
                                   @input="checkNicknameAvailable"
                                   required
                                   autocomplete="username"
                                   minlength="3"
                                   maxlength="30"
                                   pattern="[a-zA-Z0-9_\-àáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿ]+">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('user', 'w-5 h-5 text-accent-purple group-focus-within:text-gray-900 transition-colors') ?>
                            </div>
                        </div>

                        <!-- Nickname Error -->
                        <div x-show="errors.nickname" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.nickname"></span>
                        </div>

                        <!-- Nickname Available -->
                        <div x-show="!nicknameChecking && availabilityStatus.nickname === 'available'" class="text-cool-cyan text-sm flex items-center mt-2">
                            <?= icon('check-circle', 'w-5 h-5 mr-2') ?>
                            Nickname disponibile
                        </div>

                        <!-- Nickname Unavailable -->
                        <div x-show="!nicknameChecking && availabilityStatus.nickname === 'unavailable'" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('times-circle', 'w-5 h-5 mr-2') ?>
                            Questo nickname è già in uso
                        </div>

                        <!-- Nickname Checking -->
                        <div x-show="nicknameChecking" class="text-neutral-silver text-sm flex items-center mt-2">
                            <?= icon('spinner', 'w-5 h-5 animate-spin mr-2') ?>
                            Controllo disponibilità...
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="space-y-2">
                        <label for="password" class="block text-sm font-semibold text-accent-violet flex items-center">
                            <?= icon('lock', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Password
                        </label>
                        <div class="relative group">
                            <input :type="showPassword ? 'text' : 'password'"
                                   id="password"
                                   name="password"
                                   class="w-full px-4 py-4 pl-12 pr-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-white focus:text-gray-900 transition-all duration-300 group-hover:border-accent-purple/50"
                                   placeholder="Crea una password sicura"
                                   x-model="formData.password"
                                   @input="validatePasswordStrength"
                                   @blur="validatePassword"
                                   required
                                   autocomplete="new-password"
                                   minlength="8">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('lock', 'w-5 h-5 text-accent-purple group-focus-within:text-gray-900 transition-colors') ?>
                            </div>
                            <button type="button"
                                    @click="showPassword = !showPassword"
                                    class="password-toggle absolute inset-y-0 right-0 pr-3 flex items-center text-neutral-gray group-focus-within:text-gray-900 hover:text-accent-purple focus:outline-none transition-colors duration-200"
                                    :title="showPassword ? 'Nascondi password' : 'Mostra password'">
                                <span x-show="showPassword" x-cloak><?= icon('eye-slash', 'w-5 h-5') ?></span><span x-show="!showPassword" x-cloak><?= icon('eye', 'w-5 h-5') ?></span>
                            </button>
                        </div>

                        <!-- Password Strength -->
                        <div x-show="formData.password.length > 0" class="mt-3">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs text-neutral-silver">Sicurezza password:</span>
                                <span x-text="passwordStrengthText" :class="passwordStrengthColor" class="text-xs font-semibold"></span>
                            </div>
                            <div class="w-full bg-neutral-darkGray rounded-full h-2">
                                <div class="h-2 rounded-full transition-all duration-300" :class="passwordStrengthClass" :style="`width: ${passwordStrength}%`"></div>
                            </div>
                        </div>

                        <!-- Password Requirements -->
                        <div x-show="formData.password.length > 0" class="grid grid-cols-2 gap-2 mt-3 text-xs">
                            <div class="flex items-center" :class="passwordRequirements.minLength ? 'text-green-400' : 'text-energy-magenta'">
                                <span x-show="passwordRequirements.minLength" x-cloak><?= icon('check-circle', 'w-5 h-5 mr-2 text-green-400') ?></span><span x-show="!passwordRequirements.minLength" x-cloak><?= icon('times-circle', 'w-5 h-5 mr-2 text-energy-magenta') ?></span>
                                8+ caratteri
                            </div>
                            <div class="flex items-center" :class="passwordRequirements.hasUpper ? 'text-green-400' : 'text-energy-magenta'">
                                <span x-show="passwordRequirements.hasUpper" x-cloak><?= icon('check-circle', 'w-5 h-5 mr-2 text-green-400') ?></span><span x-show="!passwordRequirements.hasUpper" x-cloak><?= icon('times-circle', 'w-5 h-5 mr-2 text-energy-magenta') ?></span>
                                Maiuscola
                            </div>
                            <div class="flex items-center" :class="passwordRequirements.hasLower ? 'text-green-400' : 'text-energy-magenta'">
                                <span x-show="passwordRequirements.hasLower" x-cloak><?= icon('check-circle', 'w-5 h-5 mr-2 text-green-400') ?></span><span x-show="!passwordRequirements.hasLower" x-cloak><?= icon('times-circle', 'w-5 h-5 mr-2 text-energy-magenta') ?></span>
                                Minuscola
                            </div>
                            <div class="flex items-center" :class="passwordRequirements.hasNumber ? 'text-green-400' : 'text-energy-magenta'">
                                <span x-show="passwordRequirements.hasNumber" x-cloak><?= icon('check-circle', 'w-5 h-5 mr-2 text-green-400') ?></span><span x-show="!passwordRequirements.hasNumber" x-cloak><?= icon('times-circle', 'w-5 h-5 mr-2 text-energy-magenta') ?></span>
                                Numero
                            </div>
                            <div class="flex items-center" :class="passwordRequirements.hasSpecial ? 'text-green-400' : 'text-energy-magenta'">
                                <span x-show="passwordRequirements.hasSpecial" x-cloak><?= icon('check-circle', 'w-5 h-5 mr-2 text-green-400') ?></span><span x-show="!passwordRequirements.hasSpecial" x-cloak><?= icon('times-circle', 'w-5 h-5 mr-2 text-energy-magenta') ?></span>
                                Carattere speciale
                            </div>
                        </div>

                        <!-- Password Error -->
                        <div x-show="errors.password" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.password"></span>
                        </div>
                    </div>

                    <!-- Confirm Password Field -->
                    <div class="space-y-2">
                        <label for="password_confirmation" class="block text-sm font-semibold text-accent-violet flex items-center">
                            <?= icon('check-double', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Conferma Password
                        </label>
                        <div class="relative group">
                            <input :type="showPasswordConfirmation ? 'text' : 'password'"
                                   id="password_confirmation"
                                   name="password_confirmation"
                                   class="w-full px-4 py-4 pl-12 pr-12 bg-brand-midnight/50 border border-neutral-darkGray rounded-xl text-neutral-white placeholder-neutral-silver focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 focus:bg-white focus:text-gray-900 transition-all duration-300 group-hover:border-accent-purple/50"
                                   placeholder="Ripeti la password"
                                   x-model="formData.password_confirmation"
                                   @input="validatePasswordConfirmation"
                                   @blur="validatePasswordConfirmation"
                                   required
                                   autocomplete="new-password">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <?= icon('check-double', 'w-5 h-5 text-accent-purple group-focus-within:text-gray-900 transition-colors') ?>
                            </div>
                            <button type="button"
                                    @click="showPasswordConfirmation = !showPasswordConfirmation"
                                    class="password-toggle absolute inset-y-0 right-0 pr-3 flex items-center text-neutral-gray group-focus-within:text-gray-900 hover:text-accent-purple focus:outline-none transition-colors duration-200"
                                    :title="showPasswordConfirmation ? 'Nascondi password' : 'Mostra password'">
                                <span x-show="showPasswordConfirmation" x-cloak><?= icon('eye-slash', 'w-5 h-5') ?></span><span x-show="!showPasswordConfirmation" x-cloak><?= icon('eye', 'w-5 h-5') ?></span>
                            </button>
                        </div>

                        <!-- Password Confirmation Error -->
                        <div x-show="errors.password_confirmation" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.password_confirmation"></span>
                        </div>

                        <!-- Password Match -->
                        <div x-show="validations.password_confirmation && formData.password_confirmation.length > 0" class="text-cool-cyan text-sm flex items-center mt-2">
                            <?= icon('check-circle', 'w-5 h-5 mr-2') ?>
                            Le password corrispondono
                        </div>
                    </div>

                    <!-- Birth Date Fields -->
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-accent-violet flex items-center">
                            <?= icon('birthday-cake', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Data di Nascita (per verificare che tu abbia almeno 18 anni)
                        </label>

                        <div class="grid grid-cols-2 gap-4">
                            <!-- Birth Month -->
                            <div>
                                <label for="birth_month" class="sr-only">Mese di nascita</label>
                                <select id="birth_month"
                                        name="birth_month"
                                        class="w-full px-4 py-4 border border-neutral-darkGray rounded-xl focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 transition-all duration-300"
                                        style="background-color: rgba(15, 23, 42, 0.5); color: #ffffff;"
                                        x-model="formData.birth_month"
                                        @change="validateAge"
                                        required>
                                    <option value="" style="background-color: #1f2937; color: #ffffff;">Mese</option>
                                    <?php foreach ($months as $num => $name) { ?>
                                        <option value="<?= $num ?>" style="background-color: #1f2937; color: #ffffff;" <?= ($oldInput['birth_month'] ?? '') === $num ? 'selected' : '' ?>><?= $name ?></option>
                                    <?php } ?>
                                </select>
                            </div>

                            <!-- Birth Year -->
                            <div>
                                <label for="birth_year" class="sr-only">Anno di nascita</label>
                                <select id="birth_year"
                                        name="birth_year"
                                        class="w-full px-4 py-4 border border-neutral-darkGray rounded-xl focus:border-accent-purple focus:ring-2 focus:ring-accent-purple/30 transition-all duration-300"
                                        style="background-color: rgba(15, 23, 42, 0.5); color: #ffffff;"
                                        x-model="formData.birth_year"
                                        @change="validateAge"
                                        required>
                                    <option value="" style="background-color: #1f2937; color: #ffffff;">Anno</option>
                                    <?php for ($year = $maxBirthYear; $year >= $minBirthYear; $year--) { ?>
                                        <option value="<?= $year ?>" style="background-color: #1f2937; color: #ffffff;" <?= ($oldInput['birth_year'] ?? '') === $year ? 'selected' : '' ?>><?= $year ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>

                        <!-- Birth Date Error -->
                        <div x-show="errors.birth_month || errors.birth_year || errors.age" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.birth_month || errors.birth_year || errors.age || 'Seleziona una data di nascita valida'"></span>
                        </div>

                        <!-- Age Valid -->
                        <div x-show="validations.age && calculatedAge >= 18" class="text-cool-cyan text-sm flex items-center mt-2">
                            <?= icon('check-circle', 'w-5 h-5 mr-2') ?>
                            Età verificata: <span x-text="calculatedAge"></span> anni
                        </div>

                        <!-- Underage Warning -->
                        <div x-show="calculatedAge > 0 && calculatedAge < 18" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            Devi avere almeno 18 anni per registrarti
                        </div>
                    </div>

                    <!-- Gender Field -->
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-accent-violet flex items-center">
                            <?= icon('venus-mars', 'w-5 h-5 text-accent-purple mr-2') ?>
                            Genere
                        </label>

                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center p-3 bg-gray-700/30 border border-neutral-darkGray rounded-xl cursor-pointer hover:bg-brand-midnight/50 transition-all duration-300" :class="formData.gender === 'male' ? 'border-accent-purple bg-accent-purple/10' : ''">
                                <input type="radio"
                                       name="gender"
                                       value="male"
                                       x-model="formData.gender"
                                       @change="clearError('gender')"
                                       class="text-accent-purple bg-gray-700 border-gray-600 focus:ring-accent-purple focus:ring-2"
                                       <?= ($oldInput['gender'] ?? '') === 'male' ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm">Maschio</span>
                            </label>

                            <label class="flex items-center p-3 bg-gray-700/30 border border-neutral-darkGray rounded-xl cursor-pointer hover:bg-brand-midnight/50 transition-all duration-300" :class="formData.gender === 'female' ? 'border-accent-purple bg-accent-purple/10' : ''">
                                <input type="radio"
                                       name="gender"
                                       value="female"
                                       x-model="formData.gender"
                                       @change="clearError('gender')"
                                       class="text-accent-purple bg-gray-700 border-gray-600 focus:ring-accent-purple focus:ring-2"
                                       <?= ($oldInput['gender'] ?? '') === 'female' ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm">Femmina</span>
                            </label>

                            <label class="flex items-center p-3 bg-gray-700/30 border border-neutral-darkGray rounded-xl cursor-pointer hover:bg-brand-midnight/50 transition-all duration-300" :class="formData.gender === 'other' ? 'border-accent-purple bg-accent-purple/10' : ''">
                                <input type="radio"
                                       name="gender"
                                       value="other"
                                       x-model="formData.gender"
                                       @change="clearError('gender')"
                                       class="text-accent-purple bg-gray-700 border-gray-600 focus:ring-accent-purple focus:ring-2"
                                       <?= ($oldInput['gender'] ?? '') === 'other' ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm">Altro</span>
                            </label>

                            <label class="flex items-center p-3 bg-gray-700/30 border border-neutral-darkGray rounded-xl cursor-pointer hover:bg-brand-midnight/50 transition-all duration-300" :class="formData.gender === 'prefer_not_to_say' ? 'border-accent-purple bg-accent-purple/10' : ''">
                                <input type="radio"
                                       name="gender"
                                       value="prefer_not_to_say"
                                       x-model="formData.gender"
                                       @change="clearError('gender')"
                                       class="text-accent-purple bg-gray-700 border-gray-600 focus:ring-accent-purple focus:ring-2"
                                       <?= ($oldInput['gender'] ?? '') === 'prefer_not_to_say' ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm">Preferisco non dirlo</span>
                            </label>
                        </div>

                        <!-- Gender Error -->
                        <div x-show="errors.gender" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.gender"></span>
                        </div>
                    </div>

                    <!-- Terms and Privacy -->
                    <div class="space-y-2">
                        <div class="flex items-start space-x-3">
                            <input id="accept_terms"
                                   name="accept_terms"
                                   type="checkbox"
                                   class="mt-1 w-4 h-4 text-accent-purple bg-gray-700 border-gray-600 rounded focus:ring-accent-purple focus:ring-2"
                                   x-model="formData.accept_terms"
                                   @change="clearError('accept_terms')"
                                   required>
                            <label for="accept_terms" class="text-sm text-neutral-silver leading-relaxed">
                                Accetto i
                                <a href="<?= url('/legal/terms') ?>" target="_blank" class="text-accent-purple hover:text-accent-violet underline">Termini di Servizio</a>
                                e l'<a href="<?= url('/legal/privacy') ?>" target="_blank" class="text-accent-purple hover:text-accent-violet underline">Informativa sulla Privacy</a>
                                <span class="text-energy-magenta font-bold">*</span>
                            </label>
                        </div>

                        <!-- Terms Error -->
                        <div x-show="errors.accept_terms" class="text-energy-magenta text-sm flex items-center mt-2">
                            <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                            <span x-text="errors.accept_terms"></span>
                        </div>
                    </div>

                    <!-- Email Notifications -->
                    <div class="space-y-2">
                        <div class="flex items-start space-x-3">
                            <input id="accept_emails"
                                   name="accept_emails"
                                   type="checkbox"
                                   class="mt-1 w-4 h-4 text-accent-purple bg-gray-700 border-gray-600 rounded focus:ring-accent-purple focus:ring-2"
                                   x-model="formData.accept_emails">
                            <label for="accept_emails" class="text-sm text-neutral-silver leading-relaxed">
                                Desidero ricevere aggiornamenti via email (opzionale)
                            </label>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit"
                            class="w-full bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-semibold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg shadow-purple-500/25 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:ring-offset-2 focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none group"
                            :disabled="isSubmitting || !canSubmit">

                        <!-- Loading State -->
                        <span x-show="isSubmitting" class="flex items-center justify-center">
                            <?= icon('spinner', 'w-5 h-5 animate-spin mr-2') ?>
                            Registrazione in corso...
                        </span>

                        <!-- Normal State -->
                        <span x-show="!isSubmitting" class="flex items-center justify-center">
                            <?= icon('user-plus', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                            Crea Account Gratuito
                        </span>
                    </button>
                </form>

                <!-- Alternative Actions -->
                <div class="text-center space-y-4 mt-6">
                    <!-- Divider -->
                    <div class="relative py-4">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-neutral-darkGray"></div>
                        </div>
                        <div class="relative flex justify-center">
                            <span class="px-4 bg-gray-900 text-gray-400 text-sm">Hai già un account?</span>
                        </div>
                    </div>

                    <!-- Login Link -->
                    <a href="<?= url('/auth/login') ?>" class="block w-full bg-gray-700/30 hover:bg-brand-midnight/50 text-white font-medium py-4 px-6 rounded-xl transition-all duration-300 border border-neutral-darkGray hover:border-gray-500 group">
                        <span class="flex items-center justify-center">
                            <?= icon('sign-in', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                            Accedi al tuo Account
                        </span>
                    </a>

                    <!-- Home Link -->
                    <a href="<?= url('/') ?>" class="block text-gray-400 hover:text-accent-purple transition-colors text-sm">
                        <?= icon('arrow-left', 'w-5 h-5 mr-2') ?>
                        Torna alla Homepage
                    </a>
                </div>
            </div>
        </div>
    </div>

    </div> <!-- End Alpine.js reactive wrapper -->

</div> <!-- End min-h-screen -->

<!-- PAGE-SPECIFIC Alpine.js Register Data -->
<script nonce="<?= csp_nonce() ?>">
function registerData() {
    return {
        formData: {
            email: '<?php echo htmlspecialchars($oldInput['email'] ?? ''); ?>',
            name: '<?php echo htmlspecialchars($oldInput['name'] ?? ''); ?>',
            surname: '<?php echo htmlspecialchars($oldInput['surname'] ?? ''); ?>',
            nickname: '<?php echo htmlspecialchars($oldInput['nickname'] ?? ''); ?>',
            password: '',
            password_confirmation: '',
            birth_month: '<?php echo htmlspecialchars($oldInput['birth_month'] ?? ''); ?>',
            birth_year: '<?php echo htmlspecialchars($oldInput['birth_year'] ?? ''); ?>',
            gender: '<?php echo htmlspecialchars($oldInput['gender'] ?? ''); ?>',
            accept_terms: false,
            accept_emails: false
        },

        errors: {},
        validations: {},
        isSubmitting: false,
        canSubmit: false,
        showPassword: false,
        showPasswordConfirmation: false,

        emailChecking: false,
        nicknameChecking: false,

        // ENTERPRISE: Availability status for validation results
        availabilityStatus: {
            email: '',
            nickname: ''
        },

        // ENTERPRISE: Debouncing timers for API calls
        emailTimeout: null,
        nicknameTimeout: null,
        emailSpinnerTimeout: null,
        nicknameSpinnerTimeout: null,

        // ENTERPRISE: Client-side cache for validation results (session-scoped)
        validationCache: {
            email: {},
            nickname: {}
        },

        passwordStrength: 0,
        passwordStrengthText: '',
        passwordStrengthColor: '',
        passwordStrengthClass: '',
        passwordRequirements: {
            minLength: false,
            hasUpper: false,
            hasLower: false,
            hasNumber: false,
            hasSpecial: false
        },

        calculatedAge: 0,

        init() {
            this.trackMouseMovements();
            this.generateDeviceFingerprint();
            // PREFETCH: Handled centrally by navbar-guest.php (no duplicates)
            this.$watch('formData', () => this.updateCanSubmit());

            // ENTERPRISE: Validate age on initialization if birth date is pre-filled
            if (this.formData.birth_month && this.formData.birth_year) {
                this.validateAge();
            }

            // ENTERPRISE SECURITY: Prevent viewing cached registration page after submit
            // When user navigates back with browser back button after successful registration
            window.addEventListener('pageshow', (event) => {
                // Check if form was successfully submitted (marked in sessionStorage)
                const wasSubmitted = sessionStorage.getItem('registration_submitted');

                if (event.persisted || wasSubmitted === 'true') {
                    // Page was loaded from browser cache (back/forward cache)
                    // OR user is navigating back after successful registration
                    const formElement = document.getElementById('registerForm');

                    if (wasSubmitted === 'true') {
                        // ENTERPRISE: Clear submission marker and redirect to home
                        sessionStorage.removeItem('registration_submitted');

                        // Clear all form data to prevent re-registration
                        this.formData = {
                            email: '',
                            name: '',
                            surname: '',
                            nickname: '',
                            password: '',
                            password_confirmation: '',
                            birth_month: '',
                            birth_year: '',
                            gender: '',
                            accept_terms: false,
                            accept_emails: false
                        };
                        this.errors = {};
                        this.validations = {};
                        this.availabilityStatus = { email: '', nickname: '' };

                        if (formElement) {
                            formElement.reset();
                        }

                        // ENTERPRISE: Redirect to home to prevent confusion
                        window.location.replace('<?= url('/') ?>');
                    }
                }
            });
        },

        // Form validation methods
        validateEmail() {
            const email = this.formData.email.trim();
            // ENTERPRISE: Strict email pattern (no emoji, no special chars)
            const emailPattern = /^[a-zA-Z0-9._\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/;

            if (!email) {
                this.errors.email = 'Email richiesta';
                this.validations.email = false;
                this.availabilityStatus.email = '';
            } else if (!emailPattern.test(email)) {
                this.errors.email = 'Email non valida (solo caratteri alfanumerici, punto, trattino e underscore)';
                this.validations.email = false;
                this.availabilityStatus.email = '';
            } else if (this.containsEmoji(email)) {
                this.errors.email = 'L\'email non può contenere emoji';
                this.validations.email = false;
                this.availabilityStatus.email = '';
            } else {
                this.clearError('email');
                // ENTERPRISE: Don't override API availability check if already performed
                // Only set validation to true if format is valid, keep API availability status
                if (this.availabilityStatus.email === '') {
                    // Format validation only - availability still needs to be checked
                    this.validations.email = true;
                } else {
                    // Keep the API validation result (available/unavailable)
                    this.validations.email = this.availabilityStatus.email === 'available';
                }
            }
        },

        validateName() {
            const name = this.formData.name.trim();

            if (!name) {
                this.errors.name = 'Nome richiesto';
                this.validations.name = false;
            } else if (name.length < 2) {
                this.errors.name = 'Minimo 2 caratteri';
                this.validations.name = false;
            } else if (name.length > 100) {
                this.errors.name = 'Massimo 100 caratteri';
                this.validations.name = false;
            } else if (!this.validateNameFormat(name)) {
                this.errors.name = 'Il nome contiene caratteri non validi (solo lettere, spazi e apostrofi)';
                this.validations.name = false;
            } else if (this.containsEmoji(name)) {
                this.errors.name = 'Il nome non può contenere emoji';
                this.validations.name = false;
            } else {
                this.clearError('name');
                this.validations.name = true;
            }
        },

        validateSurname() {
            const surname = this.formData.surname.trim();

            if (!surname) {
                this.errors.surname = 'Cognome richiesto';
                this.validations.surname = false;
            } else if (surname.length < 2) {
                this.errors.surname = 'Minimo 2 caratteri';
                this.validations.surname = false;
            } else if (surname.length > 100) {
                this.errors.surname = 'Massimo 100 caratteri';
                this.validations.surname = false;
            } else if (!this.validateNameFormat(surname)) {
                this.errors.surname = 'Il cognome contiene caratteri non validi (solo lettere, spazi e apostrofi)';
                this.validations.surname = false;
            } else if (this.containsEmoji(surname)) {
                this.errors.surname = 'Il cognome non può contenere emoji';
                this.validations.surname = false;
            } else {
                this.clearError('surname');
                this.validations.surname = true;
            }
        },

        validateNickname() {
            const nickname = this.formData.nickname.trim();
            // ENTERPRISE: Strict nickname pattern (NO accents, NO emoji)
            const nicknamePattern = /^[a-zA-Z0-9_\-]+$/;

            // ENTERPRISE: Parole riservate (sync con backend)
            const reserved = ['admin', 'system', 'need2talk', 'support', 'help', 'api', 'www', 'mail'];

            if (!nickname) {
                this.errors.nickname = 'Nome utente richiesto';
                this.validations.nickname = false;
                this.availabilityStatus.nickname = '';
            } else if (nickname.length < 3) {
                this.errors.nickname = 'Minimo 3 caratteri';
                this.validations.nickname = false;
                this.availabilityStatus.nickname = '';
            } else if (nickname.length > 30) {
                this.errors.nickname = 'Massimo 30 caratteri';
                this.validations.nickname = false;
                this.availabilityStatus.nickname = '';
            } else if (!nicknamePattern.test(nickname)) {
                this.errors.nickname = 'Solo lettere (senza accenti), numeri, underscore e trattini';
                this.validations.nickname = false;
                this.availabilityStatus.nickname = '';
            } else if (this.containsEmoji(nickname)) {
                this.errors.nickname = 'Il nickname non può contenere emoji';
                this.validations.nickname = false;
                this.availabilityStatus.nickname = '';
            } else if (reserved.includes(nickname.toLowerCase())) {
                this.errors.nickname = 'Questo nickname è riservato';
                this.validations.nickname = false;
                this.availabilityStatus.nickname = '';
            } else {
                this.clearError('nickname');
                // ENTERPRISE: Don't override API availability check if already performed
                // Only set validation to true if format is valid, keep API availability status
                if (this.availabilityStatus.nickname === '') {
                    // Format validation only - availability still needs to be checked
                    this.validations.nickname = true;
                } else {
                    // Keep the API validation result (available/unavailable)
                    this.validations.nickname = this.availabilityStatus.nickname === 'available';
                }
            }
        },

        validatePassword() {
            const password = this.formData.password;

            if (!password) {
                this.errors.password = 'Password richiesta';
            } else if (password.length < 8) {
                this.errors.password = 'Minimo 8 caratteri';
            } else {
                this.clearError('password');
            }
        },

        // Helper per verificare che CSRF sia pronto (sicurezza)
        async waitForCSRF() {
            let attempts = 0;
            const maxAttempts = 10;

            while (attempts < maxAttempts) {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                if (csrfToken && csrfToken.length > 10) {
                    return true;
                }

                // Attendi 100ms e riprova
                await new Promise(resolve => setTimeout(resolve, 100));
                attempts++;
            }

            // ENTERPRISE: Log via centralized system (respects browser console settings)
            console.warn('[CSRF] Token not available after waiting', { attempts });
            return false;
        },

        validatePasswordConfirmation() {
            const password = this.formData.password;
            const confirmation = this.formData.password_confirmation;

            if (!confirmation) {
                this.errors.password_confirmation = 'Conferma password richiesta';
                this.validations.password_confirmation = false;
            } else if (password !== confirmation) {
                this.errors.password_confirmation = 'Le password non corrispondono';
                this.validations.password_confirmation = false;
            } else {
                this.clearError('password_confirmation');
                this.validations.password_confirmation = true;
            }
        },

        validatePasswordStrength() {
            const password = this.formData.password;

            // ENTERPRISE: Requirements aligned with backend RegistrationService
            this.passwordRequirements = {
                minLength: password.length >= 8,
                hasUpper: /[A-Z]/.test(password),
                hasLower: /[a-z]/.test(password),
                hasNumber: /[0-9]/.test(password),
                hasSpecial: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
            };

            // Calculate strength (5 criteri invece di 4)
            let strength = 0;
            if (this.passwordRequirements.minLength) strength += 20;
            if (this.passwordRequirements.hasUpper) strength += 20;
            if (this.passwordRequirements.hasLower) strength += 20;
            if (this.passwordRequirements.hasNumber) strength += 20;
            if (this.passwordRequirements.hasSpecial) strength += 20;

            this.passwordStrength = strength;

            // Set text and colors
            if (strength < 25) {
                this.passwordStrengthText = 'Molto debole';
                this.passwordStrengthColor = 'text-energy-magenta';
                this.passwordStrengthClass = 'bg-energy-magenta/100';
            } else if (strength < 50) {
                this.passwordStrengthText = 'Debole';
                this.passwordStrengthColor = 'text-orange-400';
                this.passwordStrengthClass = 'bg-orange-500';
            } else if (strength < 75) {
                this.passwordStrengthText = 'Buona';
                this.passwordStrengthColor = 'text-yellow-400';
                this.passwordStrengthClass = 'bg-yellow-500';
            } else {
                this.passwordStrengthText = 'Forte';
                this.passwordStrengthColor = 'text-green-400';
                this.passwordStrengthClass = 'bg-green-500';
            }
        },

        validateAge() {
            const month = parseInt(this.formData.birth_month);
            const year = parseInt(this.formData.birth_year);

            if (month && year) {
                const today = new Date();
                const birthDate = new Date(year, month - 1, 1);
                const age = Math.floor((today - birthDate) / (365.25 * 24 * 60 * 60 * 1000));

                this.calculatedAge = age;

                if (age >= 18) {
                    this.validations.age = true;
                    this.clearError('age');
                } else {
                    this.validations.age = false;
                    this.errors.age = 'Devi avere almeno 18 anni';
                }
            } else {
                // ENTERPRISE: Reset age validation if month or year is missing
                this.validations.age = false;
                this.calculatedAge = 0;
                if (month || year) {
                    this.errors.age = 'Seleziona mese e anno di nascita';
                }
            }

            // ENTERPRISE: Update submit button state after age validation
            this.updateCanSubmit();
        },

        // Utility methods
        clearError(field) {
            delete this.errors[field];
        },

        updateCanSubmit() {
            const requiredValidations = [
                this.validations.email,
                this.validations.name,
                this.validations.surname,
                this.validations.nickname,
                this.formData.password.length >= 8,
                this.validations.password_confirmation,
                this.validations.age,
                this.formData.gender && this.formData.gender.trim() !== '',
                this.formData.accept_terms
            ];

            this.canSubmit = requiredValidations.every(v => v);
        },

        // API methods
        async checkEmailAvailable() {
            const email = this.formData.email.trim();
            if (!email) {
                this.availabilityStatus.email = '';
                return;
            }

            // Basic validation first
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(email)) {
                this.availabilityStatus.email = '';
                return; // Skip API call for invalid format
            }

            // ENTERPRISE: Check client-side cache first (in-memory session cache)
            const cacheKey = email.toLowerCase();
            if (this.validationCache.email[cacheKey]) {
                const cached = this.validationCache.email[cacheKey];
                this.availabilityStatus.email = cached.available ? 'available' : 'unavailable';
                this.validations.email = cached.available;
                // ENTERPRISE: Log via centralized system (respects browser console settings)
                console.debug('[Email Validation] From client cache', { email, cached });
                return;
            }

            // ENTERPRISE: Reset availability status when user types
            this.availabilityStatus.email = '';

            // ENTERPRISE: Debouncing - clear previous timeouts
            if (this.emailTimeout) {
                clearTimeout(this.emailTimeout);
            }
            if (this.emailSpinnerTimeout) {
                clearTimeout(this.emailSpinnerTimeout);
            }

            // ENTERPRISE: Reset spinner (don't show immediately)
            this.emailChecking = false;

            // Set new timeout for debounced API call (1.2s = 1200ms)
            this.emailTimeout = setTimeout(async () => {
                if (this.emailChecking) return;

                // ENTERPRISE: Verifica sicurezza CSRF prima della chiamata
                const csrfReady = await this.waitForCSRF();
                if (!csrfReady) {
                    // ENTERPRISE: Log via centralized system (respects browser console settings)
                    console.error('[CSRF] Not ready, skipping email validation');
                    return;
                }

                // ENTERPRISE: Show spinner only after 300ms (avoid flickering for fast responses)
                this.emailSpinnerTimeout = setTimeout(() => {
                    this.emailChecking = true;
                }, 300);

                this.clearError('email');

            try {
                const response = await fetch('<?= url('/api/validate/email') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({ email: email })
                });

                const result = await response.json();

                // ENTERPRISE: Clear spinner timeout since we got response
                if (this.emailSpinnerTimeout) {
                    clearTimeout(this.emailSpinnerTimeout);
                }

                // ENTERPRISE: Log via centralized system (respects browser console settings)
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                console.debug('[Email Validation] API result', {
                    email: email,
                    result: result,
                    response_status: response.status,
                    csrf_token_length: csrfToken ? csrfToken.length : 0,
                    csrf_available: !!csrfToken,
                    from_cache: result.cached || false
                });

                // ENTERPRISE: Save to client-side cache
                this.validationCache.email[cacheKey] = {
                    available: result.available,
                    timestamp: Date.now()
                };

                if (!result.available) {
                    this.validations.email = false;
                    this.availabilityStatus.email = 'unavailable';
                } else {
                    this.validations.email = true;
                    this.availabilityStatus.email = 'available';
                }
            } catch (error) {
                console.error('Error checking email:', error);
                // ENTERPRISE: Clear spinner timeout on error
                if (this.emailSpinnerTimeout) {
                    clearTimeout(this.emailSpinnerTimeout);
                }
                // In caso di errore, non bloccare l'utente
                this.validations.email = true;
            } finally {
                this.emailChecking = false;
            }
            }, 1200); // ENTERPRISE: 1.2s debounce delay (increased from 500ms)
        },

        async checkNicknameAvailable() {
            const nickname = this.formData.nickname.trim();
            if (!nickname || nickname.length < 3) {
                this.availabilityStatus.nickname = '';
                return;
            }

            // ENTERPRISE: Check client-side cache first (in-memory session cache)
            const cacheKey = nickname.toLowerCase();
            if (this.validationCache.nickname[cacheKey]) {
                const cached = this.validationCache.nickname[cacheKey];
                this.availabilityStatus.nickname = cached.available ? 'available' : 'unavailable';
                this.validations.nickname = cached.available;
                // ENTERPRISE: Log via centralized system (respects browser console settings)
                console.debug('[Nickname Validation] From client cache', { nickname, cached });
                return;
            }

            // ENTERPRISE: Reset availability status when user types
            this.availabilityStatus.nickname = '';

            // ENTERPRISE: Debouncing - clear previous timeouts
            if (this.nicknameTimeout) {
                clearTimeout(this.nicknameTimeout);
            }
            if (this.nicknameSpinnerTimeout) {
                clearTimeout(this.nicknameSpinnerTimeout);
            }

            // ENTERPRISE: Reset spinner (don't show immediately)
            this.nicknameChecking = false;

            // Set new timeout for debounced API call (1.2s = 1200ms)
            this.nicknameTimeout = setTimeout(async () => {
                if (this.nicknameChecking) return;

                // ENTERPRISE: Verifica sicurezza CSRF prima della chiamata
                const csrfReady = await this.waitForCSRF();
                if (!csrfReady) {
                    // ENTERPRISE: Log via centralized system (respects browser console settings)
                    console.error('[CSRF] Not ready, skipping nickname validation');
                    return;
                }

                // ENTERPRISE: Show spinner only after 300ms (avoid flickering for fast responses)
                this.nicknameSpinnerTimeout = setTimeout(() => {
                    this.nicknameChecking = true;
                }, 300);

                this.clearError('nickname');

            try {
                const response = await fetch('<?= url('/api/validate/nickname') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({ nickname: nickname })
                });

                const result = await response.json();

                // ENTERPRISE: Clear spinner timeout since we got response
                if (this.nicknameSpinnerTimeout) {
                    clearTimeout(this.nicknameSpinnerTimeout);
                }

                // ENTERPRISE: Log via centralized system (respects browser console settings)
                console.debug('[Nickname Validation] API result', {
                    nickname: nickname,
                    result: result,
                    response_status: response.status,
                    from_cache: result.cached || false
                });

                // ENTERPRISE: Save to client-side cache
                this.validationCache.nickname[cacheKey] = {
                    available: result.available,
                    timestamp: Date.now()
                };

                if (!result.available) {
                    this.validations.nickname = false;
                    this.availabilityStatus.nickname = 'unavailable';
                } else {
                    this.validations.nickname = true;
                    this.availabilityStatus.nickname = 'available';
                }
            } catch (error) {
                console.error('Error checking nickname:', error);
                // ENTERPRISE: Clear spinner timeout on error
                if (this.nicknameSpinnerTimeout) {
                    clearTimeout(this.nicknameSpinnerTimeout);
                }
                // In caso di errore, non bloccare l'utente
                this.validations.nickname = true;
            } finally {
                this.nicknameChecking = false;
            }
            }, 1200); // ENTERPRISE: 1.2s debounce delay (increased from 500ms)
        },

        // Security methods
        trackMouseMovements() {
            let movements = 0;
            document.addEventListener('mousemove', () => {
                movements++;
                document.getElementById('mouse_movements').value = movements;
            });
        },

        generateDeviceFingerprint() {
            const fingerprint = {
                userAgent: navigator.userAgent,
                language: navigator.language,
                platform: navigator.platform,
                cookieEnabled: navigator.cookieEnabled,
                screen: screen.width + 'x' + screen.height,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                timestamp: Date.now()
            };

            document.getElementById('device_fingerprint').value = btoa(JSON.stringify(fingerprint));
        },

        // Form submission
        handleSubmit(event) {
            // Validate all fields
            this.validateEmail();
            this.validateName();
            this.validateSurname();
            this.validateNickname();
            this.validatePassword();
            this.validatePasswordConfirmation();
            this.validateAge();

            // Check if there are errors
            if (Object.keys(this.errors).length > 0) {
                event.preventDefault();
                return false;
            }

            // Check required fields
            if (!this.canSubmit) {
                event.preventDefault();
                alert('Completa tutti i campi obbligatori');
                return false;
            }

            // Set loading state
            this.isSubmitting = true;

            // ENTERPRISE FIX: Do NOT set 'registration_submitted' here!
            // It was causing redirect to home when server returns validation errors.
            // The flag is now set server-side only on successful registration.
            // See: pageshow event handler for the check logic.

            // ENTERPRISE SECURITY: Clear form data from history after successful submit
            // This prevents users from using back button to return to filled registration form
            if (window.history && window.history.replaceState) {
                // Replace current state to clear form data from history
                window.history.replaceState(null, null, window.location.href);
            }

            // Re-enable after timeout in case of error
            setTimeout(() => {
                this.isSubmitting = false;
            }, 10000);
        },

        // ENTERPRISE: Helper method to validate name format (nome/cognome)
        // Allowed: letters (with Italian accents), spaces, apostrophes
        validateNameFormat(name) {
            // Pattern: lettere con accenti italiani, spazi e apostrofi
            const pattern = /^[a-zA-ZàáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞŸ'\s]+$/;
            return pattern.test(name);
        },

        // ENTERPRISE: Helper method to detect emoji and emoticons
        containsEmoji(text) {
            // Comprehensive emoji detection
            const emojiPattern = /[\u{1F600}-\u{1F64F}]|[\u{1F300}-\u{1F5FF}]|[\u{1F680}-\u{1F6FF}]|[\u{1F1E0}-\u{1F1FF}]|[\u{2600}-\u{26FF}]|[\u{2700}-\u{27BF}]|[\u{1F900}-\u{1F9FF}]|[\u{1FA70}-\u{1FAFF}]/u;

            // Detect suspicious Unicode characters
            const suspiciousPattern = /[\u{200B}-\u{200F}\u{202A}-\u{202E}\u{2060}-\u{206F}\u{FFF0}-\u{FFFF}]/u;

            return emojiPattern.test(text) || suspiciousPattern.test(text);
        }
    }
}
</script>

<!-- ENTERPRISE GALAXY: reCAPTCHA v3 Script (ALWAYS active for registration) -->
<?php
$recaptchaService = new \Need2Talk\Services\RecaptchaService();
$recaptchaSiteKey = $recaptchaService->getSiteKey();
?>
<script src="https://www.google.com/recaptcha/api.js?render=<?= $recaptchaSiteKey ?>"></script>
<script nonce="<?= csp_nonce() ?>">
// ENTERPRISE FIX: Select focus styling (native JS, not Tailwind)
document.addEventListener('DOMContentLoaded', function() {
    const birthSelects = document.querySelectorAll('#birth_month, #birth_year');
    birthSelects.forEach(function(select) {
        select.addEventListener('focus', function() {
            this.style.backgroundColor = '#ffffff';
            this.style.color = '#111827';
        });
        select.addEventListener('blur', function() {
            this.style.backgroundColor = '';
            this.style.color = '#ffffff';
        });
    });
});

// ENTERPRISE GALAXY: reCAPTCHA v3 Integration (Always Required for Registration)
document.addEventListener('DOMContentLoaded', function() {
    const registerForm = document.getElementById('registerForm');
    if (!registerForm) return;

    registerForm.addEventListener('submit', function(e) {
        e.preventDefault();

        grecaptcha.ready(function() {
            grecaptcha.execute('<?= $recaptchaSiteKey ?>', {action: 'register'})
                .then(function(token) {
                    // Populate hidden field
                    document.getElementById('recaptchaToken').value = token;
                    // Submit form
                    registerForm.submit();
                })
                .catch(function(error) {
                    console.error('reCAPTCHA error:', error);
                    alert('Errore di sicurezza. Ricarica la pagina e riprova.');
                });
        });
    });
});
</script>

<?php
$content = ob_get_clean();
require APP_ROOT . '/app/Views/layouts/guest.php';
?>
