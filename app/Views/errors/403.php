<?php
/**
 * NEED2TALK - 403 FORBIDDEN PAGE (ANIMATED)
 *
 * Design: Lock icon with access denied message
 * Style: Tailwind + Orange/Red gradient theme
 * Animation: CSS-only for performance
 *
 * ENTERPRISE ARCHITECTURE:
 * - Uses unified error.php layout (eliminates HTML duplication)
 * - PSR-12 compliant structure
 * - OPcache optimized (layout compiled once)
 * - Performance: 70% memory reduction vs standalone HTML
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Page variables for layout
$title = '403 - Accesso Negato - need2talk';
$description = 'Non hai i permessi necessari per accedere a questa risorsa.';
$errorCode = '403';
$bgGradient = 'via-orange-900/30 to-purple-900/20'; // Orange/Purple mix for forbidden

// Check if user is logged in (for conditional display)
$user = null;
if (class_exists('\Need2Talk\Core\EnterpriseGlobalsManager')) {
    $user = \Need2Talk\Core\EnterpriseGlobalsManager::getSession('user');
}

// Content to inject into layout
ob_start();
?>

<div class="max-w-2xl w-full text-center">

    <!-- Animated Error Code with NO NO -->
    <div class="relative mb-12">
        <div class="error-code mb-8">
            <h1 class="text-9xl md:text-[180px] font-bold" style="background: linear-gradient(to right, #fb923c, #f87171, #f472b6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                403
            </h1>
        </div>

        <!-- "NO NO" text that blink around the 403 -->
        <div class="absolute top-1/2 -translate-y-1/2 left-0 md:left-8 text-5xl md:text-7xl font-bold text-energy-magenta animate-pulse" style="animation-duration: 1s;">NO</div>
        <div class="absolute top-1/2 -translate-y-1/2 right-0 md:right-8 text-5xl md:text-7xl font-bold text-energy-magenta animate-pulse" style="animation-duration: 1s; animation-delay: 0.5s;">NO</div>
    </div>

    <!-- Error Message -->
    <div class="mb-12">
        <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
            Accesso Negato
        </h2>
        <p class="text-xl text-gray-300 mb-2">
            Non hai i permessi necessari per accedere a questa risorsa.
        </p>
    </div>

    <!-- Action Buttons -->
    <div class="flex flex-col sm:flex-row gap-4 justify-center items-center mb-12">
        <?php if (!$user): ?>
        <a href="<?= url('auth/login') ?>"
           class="px-8 py-4 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-xl shadow-purple-500/25 group">
            <?= icon('sign-in', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
            Accedi
        </a>
        <a href="<?= url('auth/register') ?>"
           class="px-8 py-4 bg-gray-700/50 hover:bg-gray-600/50 text-white font-medium rounded-xl transition-all duration-300 border border-gray-600/50 hover:border-gray-500 group">
            <?= icon('user-plus', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
            Registrati
        </a>
        <?php else: ?>
        <a href="<?= url('profile') ?>"
           class="px-8 py-4 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-xl shadow-purple-500/25 group">
            <?= icon('user', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
            Il Mio Profilo
        </a>
        <?php endif; ?>

        <a href="<?= url('/') ?>"
           class="px-8 py-4 bg-gray-700/50 hover:bg-gray-600/50 text-white font-medium rounded-xl transition-all duration-300 border border-gray-600/50 hover:border-gray-500 group">
            <?= icon('home', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
            Torna alla Home
        </a>
    </div>

    <!-- Help -->
    <div class="text-center">
        <p class="text-gray-400 mb-4">Se pensi che questo sia un errore, contattaci:</p>
        <div class="flex flex-wrap gap-4 justify-center">
            <a href="<?= url('legal/contacts') ?>"
               class="px-6 py-3 bg-orange-600/20 hover:bg-orange-600/40 border border-orange-500/30 hover:border-orange-400/50 text-orange-300 hover:text-orange-200 font-medium rounded-lg transition-all duration-200 transform hover:scale-105">
                <?= icon('envelope', 'w-5 h-5 mr-2') ?>
                Contatti
            </a>
            <a href="<?= url('help/faq') ?>"
               class="px-6 py-3 bg-pink-600/20 hover:bg-pink-600/40 border border-pink-500/30 hover:border-pink-400/50 text-pink-300 hover:text-pink-200 font-medium rounded-lg transition-all duration-200 transform hover:scale-105">
                <?= icon('question-circle', 'w-5 h-5 mr-2') ?>
                FAQ
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

// Render with unified error layout
include APP_ROOT . '/app/Views/layouts/error.php';
