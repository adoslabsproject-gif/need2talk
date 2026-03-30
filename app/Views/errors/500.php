<?php
/**
 * NEED2TALK - 500 ERROR PAGE (ANIMATED)
 *
 * Design: Smoking server with error message
 * Style: Tailwind + Red/Orange gradient theme
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
$title = '500 - Errore del Server - need2talk';
$description = 'Si è verificato un errore interno del server. Stiamo lavorando per risolvere il problema.';
$errorCode = '500';
$bgGradient = 'via-red-900/20'; // Red theme for server error

// Content to inject into layout
ob_start();
?>

<div class="max-w-4xl w-full text-center">

            <!-- Animated Error Code -->
            <div class="error-code mb-8">
                <h1 class="text-9xl md:text-[200px] font-bold bg-gradient-to-r from-red-400 via-orange-400 to-red-400 bg-clip-text text-transparent">
                    500
                </h1>
            </div>

            <!-- Smoking Server Animation -->
            <div class="relative h-80 mb-12 flex items-center justify-center">
                <!-- Server Rack (CENTERED) -->
                <div class="server-shake relative">
                    <!-- Server Box -->
                    <div class="relative w-64 h-48 bg-gradient-to-b from-gray-700 to-gray-800 rounded-lg border-4 border-gray-600 shadow-2xl">

                        <!-- Server Lights -->
                        <div class="flex gap-2 p-4">
                            <div class="error-light w-3 h-3 rounded-full bg-energy-magenta/100 shadow-lg shadow-red-500/50"></div>
                            <div class="error-light w-3 h-3 rounded-full bg-energy-magenta/100 shadow-lg shadow-red-500/50" style="animation-delay: 0.2s;"></div>
                            <div class="w-3 h-3 rounded-full bg-gray-600"></div>
                            <div class="w-3 h-3 rounded-full bg-gray-600"></div>
                        </div>

                        <!-- Server Screen -->
                        <div class="mx-4 mb-4 p-3 bg-black/80 rounded border border-energy-magenta/50">
                            <div class="text-energy-magenta text-xs font-mono text-left space-y-1">
                                <div class="error-blink">ERROR: FATAL_EXCEPTION</div>
                                <div style="animation-delay: 0.3s;" class="error-blink">Stack trace:</div>
                                <div style="animation-delay: 0.6s;" class="error-blink">├── core.panic()</div>
                                <div style="animation-delay: 0.9s;" class="error-blink">└── system.halt()</div>
                            </div>
                        </div>

                        <!-- Server Vents (where smoke comes from) -->
                        <div class="absolute top-0 left-0 right-0 flex gap-2 p-2 justify-center">
                            <div class="w-12 h-1 bg-brand-midnight rounded"></div>
                            <div class="w-12 h-1 bg-brand-midnight rounded"></div>
                            <div class="w-12 h-1 bg-brand-midnight rounded"></div>
                        </div>

                        <!-- Sparks -->
                        <div class="spark absolute top-2 right-4 w-2 h-2 bg-yellow-400 rounded-full" style="animation-delay: 0s;"></div>
                        <div class="spark absolute top-8 right-8 w-1 h-1 bg-orange-400 rounded-full" style="animation-delay: 0.5s;"></div>
                        <div class="spark absolute top-4 left-8 w-1.5 h-1.5 bg-red-400 rounded-full" style="animation-delay: 1s;"></div>
                    </div>

                    <!-- HUGE FIRE at bottom of server (BASE) -->
                    <div class="absolute -bottom-2 left-1/2 -translate-x-1/2 flex items-end justify-center -space-x-4">
                        <!-- Multiple fire emojis overlapped for MASSIVE effect -->
                        <div class="text-8xl animate-pulse" style="animation-duration: 0.8s;">🔥</div>
                        <div class="text-9xl animate-pulse" style="animation-duration: 1s; animation-delay: 0.2s;">🔥</div>
                        <div class="text-8xl animate-pulse" style="animation-duration: 0.9s; animation-delay: 0.1s;">🔥</div>
                        <div class="text-7xl animate-pulse" style="animation-duration: 1.1s; animation-delay: 0.3s;">🔥</div>
                        <div class="text-8xl animate-pulse" style="animation-duration: 0.85s; animation-delay: 0.15s;">🔥</div>
                    </div>
                </div>

                <!-- Smoke particles - SAME AS ORIGINAL but WHITE instead of gray -->
                <div class="smoke absolute bottom-52 left-1/2 -translate-x-1/2 w-8 h-8 bg-brand-charcoal rounded-full blur-md" style="animation-delay: 0s;"></div>
                <div class="smoke absolute bottom-52 left-1/2 -translate-x-8 w-10 h-10 bg-gray-200 rounded-full blur-lg" style="animation-delay: 0.5s;"></div>
                <div class="smoke absolute bottom-52 left-1/2 translate-x-4 w-12 h-12 bg-brand-charcoal rounded-full blur-xl" style="animation-delay: 1s;"></div>
                <div class="smoke absolute bottom-52 left-1/2 -translate-x-4 w-9 h-9 bg-brand-charcoal rounded-full blur-md" style="animation-delay: 1.5s;"></div>
                <div class="smoke absolute bottom-52 left-1/2 translate-x-8 w-11 h-11 bg-gray-200 rounded-full blur-lg" style="animation-delay: 2s;"></div>
            </div>

            <!-- Error Message -->
            <div class="mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">
                    Errore Interno del Server
                </h2>
                <p class="text-xl text-gray-300 mb-2">
                    Ops! Qualcosa è andato storto nel nostro server.
                </p>
                <p class="text-gray-400">
                    Il nostro team è già al lavoro per spegnere l'incendio... 🧯
                </p>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <a href="<?= url('/') ?>"
                   class="px-8 py-4 bg-gradient-to-r from-red-600 to-orange-600 hover:from-red-700 hover:to-orange-700 text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-xl shadow-red-500/25 group">
                    <?= icon('home', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                    Torna alla Home
                </a>

                <button onclick="window.location.reload()"
                        class="px-8 py-4 bg-gray-700/50 hover:bg-gray-600/50 text-white font-medium rounded-xl transition-all duration-300 border border-gray-600/50 hover:border-gray-500 group">
                    <?= icon('redo', 'w-5 h-5 mr-2 group-hover:animate-spin') ?>
                    Riprova
                </button>
            </div>

            <!-- Technical Details (if available) -->
            <?php if (config('app.debug', false) && isset($error)): ?>
            <div class="mt-12 p-6 bg-brand-midnight/50 backdrop-blur-lg rounded-xl border border-energy-magenta/20 text-left">
                <h3 class="text-lg font-semibold text-energy-magenta mb-3 flex items-center">
                    <?= icon('bug', 'w-5 h-5 mr-2') ?>
                    Dettagli Tecnici (Solo Sviluppo)
                </h3>
                <pre class="text-xs text-gray-300 overflow-x-auto p-4 bg-black/30 rounded"><code><?= htmlspecialchars($error['message'] ?? 'Nessun dettaglio disponibile') ?>

File: <?= htmlspecialchars($error['file'] ?? 'Unknown') ?>
Line: <?= htmlspecialchars($error['line'] ?? 'Unknown') ?></code></pre>
            </div>
            <?php endif; ?>

            <!-- Helpful Links -->
            <div class="mt-12 pt-8 border-t border-gray-700/50">
                <p class="text-gray-400 mb-6">Se il problema persiste, contattaci:</p>
                <div class="flex flex-wrap gap-4 justify-center">
                    <a href="<?= url('legal/contacts') ?>"
                       class="px-6 py-3 bg-red-600/20 hover:bg-red-600/40 border border-energy-magenta/30 hover:border-energy-magenta/50 text-red-300 hover:text-red-200 font-medium rounded-lg transition-all duration-200 transform hover:scale-105">
                        <?= icon('envelope', 'w-5 h-5 mr-2') ?>
                        Contatti
                    </a>
                    <a href="<?= url('pages/about') ?>"
                       class="px-6 py-3 bg-orange-600/20 hover:bg-orange-600/40 border border-orange-500/30 hover:border-orange-400/50 text-orange-300 hover:text-orange-200 font-medium rounded-lg transition-all duration-200 transform hover:scale-105">
                        <?= icon('info-circle', 'w-5 h-5 mr-2') ?>
                        Chi Siamo
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

// Render with unified error layout
include APP_ROOT . '/app/Views/layouts/error.php';
