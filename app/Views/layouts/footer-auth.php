<?php
/**
 * Footer Auth - Authenticated users footer
 * Style simile al footer pubblico ma compatto
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}
?>

<!-- Footer Auth -->
<footer class="relative z-10 bg-gray-900 border-t border-purple-500/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <!-- Top Section: Slogan + Social -->
        <div class="flex flex-col md:flex-row justify-between items-center mb-6 pb-6 border-b border-purple-500/20">
            <!-- Brand + Slogan -->
            <div class="mb-4 md:mb-0 text-center md:text-left">
                <p class="italic text-purple-400 mb-2 font-medium">
                    <?= icon('quote', 'w-3 h-3 inline-block mr-2') ?>
                    "Fai parlare la tua anima"
                    <?= icon('quote', 'w-3 h-3 inline-block ml-2') ?>
                </p>
                <p class="text-gray-400 text-xs">
                    Il primo social italiano, scritto da una AI con il cuore di un umano
                    <?= icon('heart', 'w-3 h-3 text-energy-magenta inline-block mx-1', 'solid') ?>
                    <span class="inline-block">🇮🇹</span>
                </p>
            </div>

            <!-- Social Links -->
            <div class="flex space-x-3">
                <a href="https://www.facebook.com/people/Need2talk/61582668675756/" target="_blank" rel="noopener noreferrer" title="Facebook" class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center hover:bg-purple-600 transition-colors">
                    <?= icon('facebook', 'w-4 h-4 text-gray-400', 'solid') ?>
                </a>
                <a href="https://x.com/Yxneed2talkxY" target="_blank" rel="noopener noreferrer" title="X (Twitter)" class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center hover:bg-purple-600 transition-colors">
                    <?= icon('twitter', 'w-4 h-4 text-gray-400', 'solid') ?>
                </a>
                <a href="https://www.instagram.com/need2talk_italia/" target="_blank" rel="noopener noreferrer" title="Instagram" class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center hover:bg-purple-600 transition-colors">
                    <?= icon('instagram', 'w-4 h-4 text-gray-400', 'solid') ?>
                </a>
                <a href="https://www.tiktok.com/@yx_need2talk_xy" target="_blank" rel="noopener noreferrer" title="TikTok" class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center hover:bg-purple-600 transition-colors">
                    <?= icon('tiktok', 'w-4 h-4 text-gray-400', 'solid') ?>
                </a>
            </div>
        </div>

        <!-- Bottom Section: Copyright + Links -->
        <div class="flex flex-col md:flex-row justify-between items-center text-center md:text-left">
            <!-- Copyright -->
            <div class="mb-4 md:mb-0">
                <p class="text-gray-400 text-xs">
                    &copy; <?= date('Y') ?> need2talk. Tutti i diritti riservati.
                </p>
            </div>

            <!-- Legal Links -->
            <div class="flex flex-wrap justify-center md:justify-end gap-4 text-xs text-gray-400">
                <a href="<?= url('/help/faq') ?>" class="hover:text-purple-400 transition-colors">FAQ</a>
                <a href="<?= url('/legal/privacy') ?>" class="hover:text-purple-400 transition-colors">Privacy</a>
                <a href="<?= url('/legal/terms') ?>" class="hover:text-purple-400 transition-colors">Termini</a>
                <a href="<?= url('/help/safety') ?>" class="hover:text-purple-400 transition-colors">Sicurezza</a>
            </div>
        </div>
    </div>
</footer>
