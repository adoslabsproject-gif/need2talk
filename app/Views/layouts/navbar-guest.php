<?php
/**
 * NEED2TALK - NAVBAR GUEST
 *
 * FLUSSO ARCHITETTIRA:
 * 1. Navigation per utenti non autenticati (login, register, home)
 * 2. Stile - Glass-morphism design
 * 3. Responsive design ottimizzato per migliaia di utenti
 * 4. Performance GPU-accelerated animations
 * 5. Security: CSRF protection e URL sanitization
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// CURRENT PAGE DETECTION per navigation dinamica
$currentUrl = $_SERVER['REQUEST_URI'] ?? '';
$isLoginPage = strpos($currentUrl, '/auth/login') !== false;
$isRegisterPage = strpos($currentUrl, '/auth/register') !== false;
$isForgotPasswordPage = strpos($currentUrl, '/auth/forgot-password') !== false;
$isResetPasswordPage = strpos($currentUrl, '/auth/reset-password') !== false;
?>

<!-- NAVBAR GUEST - Mediterranean Calm -->
<header class="fixed top-0 left-0 right-0 z-50 bg-brand-charcoal/95 backdrop-blur-lg border-b border-neutral-darkGray shadow-md" x-data="navbarData()">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">

            <!-- LOGO SECTION -->
            <div class="flex items-center space-x-3">
                <a href="<?php echo url(); ?>" class="group flex items-center space-x-3">
                    <!-- Logo Container -->
                    <div class="relative w-10 h-10">
                        <div class="absolute inset-0 bg-gradient-to-br from-purple-600 to-pink-600 rounded-xl shadow-md group-hover:shadow-lg group-hover:shadow-purple-500/30 transition-all duration-300"></div>
                        <picture>
                            <source srcset="<?php echo asset('img/logo-80-retina.webp'); ?>" type="image/webp">
                            <img src="<?php echo asset('img/logo-80-retina.png'); ?>"
                                 alt="need2talk Logo"
                                 class="absolute inset-0 w-full h-full rounded-xl object-cover"
                                 fetchpriority="high"
                                 width="40"
                                 height="40">
                        </picture>

                        <!-- ENTERPRISE v12.1: Glow statico invece di animate-ping (risparmio GPU + TBT) -->
                        <div class="absolute inset-0 rounded-full border-2 border-pink-500/40 shadow-lg shadow-pink-500/20"></div>
                    </div>

                    <!-- Brand Text -->
                    <span class="text-xl font-bold bg-gradient-to-r from-purple-600 to-pink-600 bg-clip-text text-transparent">
                        need2talk
                    </span>
                </a>
            </div>

            <!-- DESKTOP NAVIGATION -->
            <nav class="hidden md:flex items-center space-x-8">
                <a href="<?php echo url(); ?>"
                   class="group flex items-center space-x-2 text-neutral-silver hover:text-accent-purple py-2 px-3 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                    <?= icon('home', 'w-4 h-4') ?>
                    <span>Home</span>
                </a>

                <a href="<?php echo url('about'); ?>"
                   class="group flex items-center space-x-2 text-neutral-silver hover:text-accent-purple py-2 px-3 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                    <?= icon('info-circle', 'w-4 h-4') ?>
                    <span>Chi Siamo</span>
                </a>

                <a href="<?php echo url('legal/privacy'); ?>"
                   class="group flex items-center space-x-2 text-neutral-silver hover:text-accent-purple py-2 px-3 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                    <?= icon('shield', 'w-4 h-4') ?>
                    <span>Privacy</span>
                </a>

                <a href="<?php echo url('legal/terms'); ?>"
                   class="group flex items-center space-x-2 text-neutral-silver hover:text-accent-purple py-2 px-3 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                    <?= icon('document', 'w-4 h-4') ?>
                    <span>Termini</span>
                </a>

                <a href="<?php echo url('legal/contacts'); ?>"
                   class="group flex items-center space-x-2 text-neutral-silver hover:text-accent-purple py-2 px-3 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                    <?= icon('envelope', 'w-4 h-4') ?>
                    <span>Contatti</span>
                </a>
            </nav>

            <!-- AUTH BUTTONS DESKTOP -->
            <div class="hidden md:flex items-center space-x-4">
                <?php if ($isForgotPasswordPage || $isResetPasswordPage) { ?>
                <!-- FORGOT/RESET PASSWORD: Link a Login -->
                <a href="<?php echo url('auth/login'); ?>"
                   class="group relative flex items-center space-x-2 px-4 py-2 border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105 hover:-translate-y-0.5">
                    <?= icon('arrow-left', 'w-4 h-4') ?>
                    <span>Torna al Login</span>
                </a>

                <?php } elseif (!$isLoginPage && !$isRegisterPage) { ?>
                <!-- NON-LOGIN E NON-REGISTER PAGES: Mostra Login -->
                <a href="<?php echo url('auth/login'); ?>"
                   class="group relative flex items-center space-x-2 px-4 py-2 border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105 hover:-translate-y-0.5">
                    <?= icon('sign-in', 'w-4 h-4') ?>
                    <span>Login</span>
                </a>
                <?php } ?>

                <?php if (!$isRegisterPage) { ?>
                <!-- NON-REGISTER PAGES: Mostra Registrati -->
                <a href="<?php echo url('auth/register'); ?>"
                   class="group flex items-center space-x-2 px-6 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-semibold rounded-lg shadow-lg shadow-purple-500/25 hover:shadow-purple-500/40 transition-all duration-300 transform hover:scale-105">
                    <?= icon('user-plus', 'w-4 h-4') ?>
                    <span>Registrati Gratis</span>
                </a>
                <?php } else { ?>
                <!-- REGISTER PAGE: Link a Login -->
                <a href="<?php echo url('auth/login'); ?>"
                   class="group relative flex items-center space-x-2 px-4 py-2 border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105 hover:-translate-y-0.5">
                    <?= icon('sign-in', 'w-4 h-4') ?>
                    <span class="hidden lg:inline">Hai già un account?</span>
                    <span>Accedi</span>
                </a>
                <?php } ?>
            </div>

            <!-- MOBILE MENU BUTTON -->
            <button @click="mobileMenuOpen = !mobileMenuOpen"
                    aria-label="Menu di navigazione"
                    :aria-expanded="mobileMenuOpen"
                    class="md:hidden relative w-8 h-8 p-1 rounded-lg text-neutral-silver hover:text-accent-purple hover:bg-brand-midnight/50 transition-colors duration-200"
                    :class="{ 'text-accent-violet': mobileMenuOpen }">
                <div class="absolute top-1.5 left-1 w-6 h-0.5 bg-current rounded transition-transform duration-200"
                     :class="mobileMenuOpen ? 'rotate-45 top-3.5' : ''"></div>
                <div class="absolute top-3.5 left-1 w-6 h-0.5 bg-current rounded transition-opacity duration-200"
                     :class="mobileMenuOpen ? 'opacity-0' : ''"></div>
                <div class="absolute top-5.5 left-1 w-6 h-0.5 bg-current rounded transition-transform duration-200"
                     :class="mobileMenuOpen ? '-rotate-45 top-3.5' : ''"></div>
            </button>
        </div>

        <!-- MOBILE MENU -->
        <div x-show="mobileMenuOpen"
             role="dialog"
             aria-label="Menu di navigazione mobile"
             aria-modal="true"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 transform -translate-y-2"
             x-transition:enter-end="opacity-100 transform translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 transform translate-y-0"
             x-transition:leave-end="opacity-0 transform -translate-y-2"
             class="md:hidden border-t border-neutral-darkGray bg-brand-charcoal/95 backdrop-blur-lg shadow-lg"
             x-cloak
             @click.outside="mobileMenuOpen = false">

            <div class="px-4 py-6 space-y-4">
                <!-- Mobile Navigation Links -->
                <div class="space-y-2">
                    <a href="<?php echo url(); ?>"
                       @click="mobileMenuOpen = false"
                       class="flex items-center space-x-3 text-neutral-silver hover:text-accent-purple py-3 px-4 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                        <?= icon('home', 'w-5 h-5') ?>
                        <span>Home</span>
                    </a>

                    <a href="<?php echo url('about'); ?>"
                       @click="mobileMenuOpen = false"
                       class="flex items-center space-x-3 text-neutral-silver hover:text-accent-purple py-3 px-4 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                        <?= icon('info-circle', 'w-5 h-5') ?>
                        <span>Chi Siamo</span>
                    </a>

                    <a href="<?php echo url('legal/privacy'); ?>"
                       @click="mobileMenuOpen = false"
                       class="flex items-center space-x-3 text-neutral-silver hover:text-accent-purple py-3 px-4 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                        <?= icon('shield', 'w-5 h-5') ?>
                        <span>Privacy</span>
                    </a>

                    <a href="<?php echo url('legal/terms'); ?>"
                       @click="mobileMenuOpen = false"
                       class="flex items-center space-x-3 text-neutral-silver hover:text-accent-purple py-3 px-4 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                        <?= icon('document', 'w-5 h-5') ?>
                        <span>Termini</span>
                    </a>

                    <a href="<?php echo url('legal/contacts'); ?>"
                       @click="mobileMenuOpen = false"
                       class="flex items-center space-x-3 text-neutral-silver hover:text-accent-purple py-3 px-4 rounded-lg hover:bg-brand-midnight/50 transition-all duration-200">
                        <?= icon('envelope', 'w-5 h-5') ?>
                        <span>Contatti</span>
                    </a>
                </div>

                <!-- Mobile Auth Buttons -->
                <div class="pt-4 border-t border-neutral-darkGray space-y-3">
                    <!-- Age Notice -->
                    <div class="flex items-center justify-center space-x-2 bg-brand-charcoal border-2 border-accent-violet rounded-full px-4 py-2 text-accent-violet">
                        <?= icon('exclamation-triangle', 'w-4 h-4') ?>
                        <span class="text-sm font-semibold">Solo maggiorenni (18+)</span>
                    </div>

                    <?php if (!$isLoginPage) { ?>
                    <a href="<?php echo url('auth/login'); ?>"
                       @click="mobileMenuOpen = false"
                       class="flex items-center justify-center space-x-2 w-full px-4 py-3 border-2 border-purple-500 text-purple-400 hover:bg-purple-500/10 hover:text-purple-300 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105">
                        <?= icon('sign-in', 'w-4 h-4') ?>
                        <span>Login</span>
                    </a>
                    <?php } ?>

                    <?php if (!$isRegisterPage) { ?>
                    <a href="<?php echo url('auth/register'); ?>"
                       @click="mobileMenuOpen = false"
                       class="flex items-center justify-center space-x-2 w-full px-4 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-semibold rounded-lg shadow-lg shadow-purple-500/25 hover:shadow-purple-500/40 transition-all duration-300 transform hover:scale-105">
                        <?= icon('user-plus', 'w-4 h-4') ?>
                        <span>Registrati Gratis</span>
                    </a>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Alpine.js Navbar Data -->
<script nonce="<?= csp_nonce() ?>">
function navbarData() {
    return {
        mobileMenuOpen: false,

        init() {
            // Security: Close mobile menu on window resize for better UX
            window.addEventListener('resize', () => {
                if (window.innerWidth >= 768) {
                    this.mobileMenuOpen = false;
                }
            });

            // PREFETCH: Handled via declarative <link rel="prefetch"> in guest.php <head>
            // No JavaScript prefetch = no NS_BINDING_ABORTED issues
        },

        // Security: Throttled navigation with analytics
        navigateAuth(url, action) {
            // Analytics tracking for auth conversions
            if (window.gtag) {
                gtag('event', 'click', {
                    event_category: 'auth_navigation',
                    event_label: action,
                    value: 1
                });
            }

            // Close mobile menu if open
            this.mobileMenuOpen = false;

            // Navigate with slight delay for better UX
            setTimeout(() => {
                window.location.href = url;
            }, 100);
        }
    }
}
</script>