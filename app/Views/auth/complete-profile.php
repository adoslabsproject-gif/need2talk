<?php
/**
 * NEED2TALK - COMPLETE PROFILE PAGE (TAILWIND + FONTAWESOME + ALPINE)
 *
 * ENTERPRISE GALAXY - GDPR COMPLIANCE FIX (2025-01-17)
 *
 * FLUSSO ARCHITETTURA:
 * 1. Post-OAuth profile completion (Google, etc.)
 * 2. MANDATORY fields: Birth date (18+), GDPR consent, Newsletter opt-in
 * 3. Security: CSRF protection, age verification, atomic transaction
 * 4. Stile: Glass-morphism + Purple theme (consistent con register.php)
 * 5. Responsive design ottimizzato
 * 6. Performance: GPU-accelerated animations con Alpine.js
 *
 * SECURITY:
 * - Only accessible by authenticated users with status='pending'
 * - ProfileCompletionMiddleware enforces this for all routes
 * - Cannot access site without completing profile
 *
 * GDPR COMPLIANCE:
 * - GDPR consent MANDATORY (EU/UK legal requirement)
 * - Consent timestamp recorded in database
 * - Newsletter opt-in OPTIONAL (separate from GDPR)
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Ensure bootstrap is loaded for helper functions
if (!function_exists('url')) {
    require_once APP_ROOT . '/app/bootstrap.php';
}

// ENTERPRISE: Session ALWAYS active
$_SESSION['form_start_time'] = $_SESSION['form_start_time'] ?? time();

// SECURITY: Redirect se non autenticato
$currentUser = current_user();
if (!$currentUser) {
    header('Location: ' . url('auth/login'));
    exit;
}

// SECURITY: Redirect se già completato
if (isset($currentUser['status']) && $currentUser['status'] === 'active') {
    header('Location: ' . url('profile'));
    exit;
}

// DATA: Form configuration
$title = 'Completa il tuo profilo - need2talk';
$description = 'Completa la registrazione per iniziare a usare need2talk';

// PERFORMANCE: Gestione errori con auto-cleanup
$errors = $_SESSION['errors'] ?? [];
$oldInput = $_SESSION['old_input'] ?? [];
$success = $_SESSION['success'] ?? null;
unset($_SESSION['errors'], $_SESSION['old_input'], $_SESSION['success']);

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

// User info from session
$userName = $currentUser['name'] ?? $currentUser['nickname'] ?? 'User';
$userEmail = $currentUser['email'] ?? '';

// ENTERPRISE V10.36: Disable FloatingRecorder on profile completion page
$hideFloatingRecorder = true;

// ENTERPRISE: Start output buffering for content injection
ob_start();
?>

<!-- PAGE-SPECIFIC inline CSS -->
<style nonce="<?= csp_nonce() ?>">
.pulse-slow {
    animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>

<!-- CONTENT ONLY (Alpine.js reactive wrapper) -->
<div class="min-h-screen" x-data="completeProfileData()">

    <!-- Background Animato -->
    <div class="fixed inset-0 bg-gradient-to-br from-brand-midnight via-brand-slate to-brand-midnight pointer-events-none">
        <!-- Floating particles -->
        <div class="absolute inset-0 overflow-hidden">
            <div class="absolute w-2 h-2 bg-accent-purple/30 rounded-full animate-bounce" style="top: 20%; left: 10%; animation-delay: 0s; animation-duration: 3s;"></div>
            <div class="absolute w-1 h-1 bg-energy-pink/40 rounded-full animate-bounce" style="top: 40%; left: 80%; animation-delay: 1s; animation-duration: 4s;"></div>
            <div class="absolute w-3 h-3 bg-accent-violet/20 rounded-full animate-bounce" style="top: 60%; left: 20%; animation-delay: 2s; animation-duration: 5s;"></div>
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
                    <div class="relative w-20 h-20">
                        <div class="absolute inset-0 bg-gradient-to-br from-purple-600 to-pink-600 rounded-3xl shadow-xl shadow-purple-500/30"></div>
                        <picture>
                            <source srcset="<?php echo asset('img/logo-192.webp'); ?>" type="image/webp">
                            <img src="<?php echo asset('img/logo-192.png'); ?>"
                                 alt="need2talk Logo"
                                 class="absolute inset-0 w-full h-full rounded-3xl object-cover"
                                 loading="lazy"
                                 width="80"
                                 height="80">
                        </picture>
                    </div>
                </div>

                <h1 class="text-3xl md:text-4xl font-bold mb-3 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                    Completa il tuo profilo
                </h1>
                <p class="text-neutral-silver text-base mb-2">
                    Ciao <span class="text-accent-purple font-semibold"><?= htmlspecialchars($userName) ?></span>!
                </p>
                <p class="text-neutral-silver/70 text-sm">
                    Ultimi passaggi per iniziare
                </p>
            </div>

            <!-- GDPR Notice -->
            <div class="bg-accent-purple/10 border border-accent-purple/50 rounded-xl p-4">
                <p class="text-accent-purple text-sm text-center flex items-center justify-center">
                    <?= icon('shield-alt', 'w-5 h-5 mr-2') ?>
                    Conformità GDPR - Privacy garantita
                </p>
            </div>

            <!-- Profile Completion Form -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">

                <!-- Errors Display -->
                <?php if (!empty($errors)): ?>
                <div class="mb-6 bg-red-500/10 border border-red-500/50 rounded-xl p-4">
                    <div class="flex items-start">
                        <?= icon('exclamation-circle', 'w-5 h-5 text-red-400 mr-3 mt-0.5 flex-shrink-0') ?>
                        <div class="flex-1">
                            <h3 class="text-red-400 font-semibold mb-2">Correggi i seguenti errori:</h3>
                            <ul class="list-disc list-inside text-red-300 text-sm space-y-1">
                                <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Success Message -->
                <?php if ($success): ?>
                <div class="mb-6 bg-green-500/10 border border-green-500/50 rounded-xl p-4">
                    <div class="flex items-center">
                        <?= icon('check-circle', 'w-5 h-5 text-green-400 mr-3') ?>
                        <p class="text-green-400 font-medium"><?= htmlspecialchars($success) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST" action="<?php echo url('complete-profile'); ?>" class="space-y-6">
                    <!-- CSRF Token -->
                    <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken ?? ''; ?>">

                    <!-- Birth Date Section -->
                    <div class="space-y-4">
                        <div class="flex items-center mb-3">
                            <?= icon('calendar', 'w-5 h-5 text-accent-purple mr-2') ?>
                            <h3 class="text-lg font-semibold text-white">Data di nascita</h3>
                        </div>
                        <p class="text-neutral-silver text-sm -mt-2 mb-4">
                            Richiesto per la verifica dell'età (18+)
                        </p>

                        <div class="grid grid-cols-2 gap-4">
                            <!-- Birth Month -->
                            <div>
                                <label for="birth_month" class="block text-sm font-medium text-neutral-silver mb-2">
                                    Mese
                                </label>
                                <select
                                    id="birth_month"
                                    name="birth_month"
                                    required
                                    class="w-full bg-brand-midnight/50 border border-accent-purple/30 rounded-xl px-4 py-3 text-white placeholder-neutral-silver/50 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:border-transparent transition-all"
                                    x-model="birthMonth">
                                    <option value="">Seleziona</option>
                                    <?php foreach ($months as $num => $name): ?>
                                    <option value="<?= $num ?>" <?= isset($oldInput['birth_month']) && $oldInput['birth_month'] == $num ? 'selected' : '' ?>>
                                        <?= $name ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Birth Year -->
                            <div>
                                <label for="birth_year" class="block text-sm font-medium text-neutral-silver mb-2">
                                    Anno
                                </label>
                                <select
                                    id="birth_year"
                                    name="birth_year"
                                    required
                                    class="w-full bg-brand-midnight/50 border border-accent-purple/30 rounded-xl px-4 py-3 text-white placeholder-neutral-silver/50 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:border-transparent transition-all"
                                    x-model="birthYear">
                                    <option value="">Anno</option>
                                    <?php for ($year = $maxBirthYear; $year >= $minBirthYear; $year--): ?>
                                    <option value="<?= $year ?>" <?= isset($oldInput['birth_year']) && $oldInput['birth_year'] == $year ? 'selected' : '' ?>>
                                        <?= $year ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Divider -->
                    <div class="border-t border-accent-purple/20 my-6"></div>

                    <!-- GDPR Consent Section -->
                    <div class="space-y-4">
                        <div class="flex items-center mb-3">
                            <?= icon('user-shield', 'w-5 h-5 text-accent-purple mr-2') ?>
                            <h3 class="text-lg font-semibold text-white">Privacy e consenso</h3>
                        </div>

                        <!-- GDPR Consent (MANDATORY) -->
                        <div class="bg-brand-midnight/30 border border-accent-purple/20 rounded-xl p-4">
                            <label class="flex items-start cursor-pointer group">
                                <input
                                    type="checkbox"
                                    name="gdpr_consent"
                                    value="1"
                                    required
                                    class="mt-1 w-5 h-5 rounded border-accent-purple/50 bg-brand-midnight/50 text-accent-purple focus:ring-2 focus:ring-accent-purple focus:ring-offset-0 cursor-pointer"
                                    x-model="gdprConsent">
                                <span class="ml-3 text-sm text-neutral-silver group-hover:text-white transition-colors">
                                    Accetto la
                                    <a href="<?= url('legal/privacy') ?>" target="_blank" class="text-accent-purple hover:text-accent-violet font-medium underline">Privacy Policy</a>
                                    e il trattamento dei miei dati personali secondo il GDPR
                                    <span class="text-red-400 font-semibold ml-1">*</span>
                                </span>
                            </label>
                        </div>

                        <!-- Newsletter Opt-in (OPTIONAL) -->
                        <div class="bg-brand-midnight/20 border border-neutral-silver/10 rounded-xl p-4">
                            <label class="flex items-start cursor-pointer group">
                                <input
                                    type="checkbox"
                                    name="newsletter_opt_in"
                                    value="1"
                                    <?= isset($oldInput['newsletter_opt_in']) && $oldInput['newsletter_opt_in'] ? 'checked' : '' ?>
                                    class="mt-1 w-5 h-5 rounded border-neutral-silver/50 bg-brand-midnight/50 text-accent-purple focus:ring-2 focus:ring-accent-purple focus:ring-offset-0 cursor-pointer"
                                    x-model="newsletterOptIn">
                                <span class="ml-3 text-sm text-neutral-silver group-hover:text-white transition-colors">
                                    Voglio ricevere aggiornamenti e novità via email
                                    <span class="text-neutral-silver/60 text-xs block mt-1">(Opzionale - puoi disiscriverti in qualsiasi momento)</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-4">
                        <button
                            type="submit"
                            class="w-full bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-bold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg shadow-purple-500/25 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:ring-offset-2 focus:ring-offset-brand-charcoal group"
                            :disabled="!isFormValid"
                            :class="{ 'opacity-50 cursor-not-allowed': !isFormValid }">
                            <span class="flex items-center justify-center">
                                <?= icon('check-circle', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                                Completa registrazione
                            </span>
                        </button>
                    </div>

                    <!-- Required Fields Notice -->
                    <p class="text-center text-neutral-silver/60 text-xs mt-4">
                        <span class="text-red-400">*</span> Campo obbligatorio
                    </p>
                </form>

            </div>

            <!-- Security Notice -->
            <div class="text-center text-neutral-silver/50 text-xs">
                <p class="flex items-center justify-center">
                    <?= icon('lock', 'w-4 h-4 mr-1') ?>
                    I tuoi dati sono protetti e criptati
                </p>
            </div>

        </div>
    </div>
</div>

<!-- Alpine.js Data Component -->
<script nonce="<?= csp_nonce() ?>">
function completeProfileData() {
    return {
        birthYear: '',
        birthMonth: '',
        gdprConsent: false,
        newsletterOptIn: false,

        get isFormValid() {
            return this.birthYear &&
                   this.birthMonth &&
                   this.gdprConsent;
        }
    }
}
</script>

<?php
// Capture buffered content
$content = ob_get_clean();

// Include post-login layout with content (user is authenticated but pending profile completion)
require APP_ROOT . '/app/Views/layouts/app-post-login.php';
?>
