<?php
/**
 * NEED2TALK - FOOTER LAYOUT (TAILWIND + FONTAWESOME)
 *
 * FLUSSO ARCHITETTURA:
 * 1. Footer responsive con design moderno
 * 2. Links legali (terms, privacy, contatti)
 * 3. Informazioni copyright e branding need2talk
 * 4. Social links con animazioni hover
 * 5. Age restriction reminder con badge
 * 6. Performance ottimizzata per migliaia di utenti
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}
?>

<!-- Footer - Mediterranean Calm -->
<footer class="relative z-10 bg-brand-charcoal border-t border-neutral-darkGray shadow-inner">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">

            <!-- Brand Column -->
            <div class="lg:col-span-2">
                <!-- Logo e Brand -->
                <div class="flex items-center space-x-3 mb-6">
                    <div class="relative w-10 h-10">
                        <div class="absolute inset-0 bg-gradient-to-br from-purple-600 to-pink-600 rounded-xl shadow-lg shadow-purple-500/20"></div>
                        <picture>
                            <source srcset="<?php echo asset('img/logo-80-retina.webp'); ?>" type="image/webp">
                            <img src="<?php echo asset('img/logo-80-retina.png'); ?>"
                                 alt="need2talk Logo"
                                 class="absolute inset-0 w-full h-full rounded-xl object-cover"
                                 loading="lazy"
                                 decoding="async"
                                 width="40"
                                 height="40">
                        </picture>

                        <!-- ENTERPRISE v12.1: Glow statico invece di animate-ping (risparmio GPU + TBT) -->
                        <div class="absolute inset-0 rounded-full border-2 border-pink-500/40 shadow-lg shadow-pink-500/20"></div>
                    </div>
                    <span class="text-xl font-bold bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent">
                        need2talk
                    </span>
                </div>

                <p class="text-neutral-silver text-sm leading-relaxed mb-4">
                    La piattaforma italiana per condividere le tue emozioni attraverso la voce.
                    Autentica, sicura e riservata ai maggiorenni.
                </p>

                <p class="italic text-accent-purple mb-6 font-medium">
                    <?= icon('quote', 'w-3 h-3 inline-block mr-2') ?>
                    "Fai parlare la tua anima"
                    <?= icon('quote', 'w-3 h-3 inline-block ml-2') ?>
                </p>

                <!-- Age Restriction Badge -->
                <div class="inline-flex items-center bg-brand-charcoal border-2 border-accent-violet rounded-full px-3 py-2 text-accent-violet">
                    <?= icon('exclamation-triangle', 'w-3 h-3 mr-2') ?>
                    <span class="text-xs font-semibold">Solo maggiorenni (18+)</span>
                </div>
            </div>

            <!-- Legal Links -->
            <div>
                <h2 class="text-lg font-semibold text-accent-violet mb-4 flex items-center">
                    <?= icon('gavel', 'w-5 h-5 text-accent-purple mr-2') ?>
                    Informazioni Legali
                </h2>
                <ul class="space-y-3">
                    <li>
                        <a href="<?= url('legal/terms') ?>" class="text-neutral-silver hover:text-accent-purple flex items-center group transition-colors duration-200">
                            <?= icon('document', 'w-3 h-3 mr-2') ?>
                            Termini di Servizio
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('legal/privacy') ?>" class="text-neutral-silver hover:text-accent-purple flex items-center group transition-colors duration-200">
                            <?= icon('shield', 'w-3 h-3 mr-2') ?>
                            Privacy Policy
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('legal/contacts') ?>" class="text-neutral-silver hover:text-accent-purple flex items-center group transition-colors duration-200">
                            <?= icon('envelope', 'w-3 h-3 mr-2') ?>
                            Contatti
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('about') ?>" class="text-neutral-silver hover:text-accent-purple flex items-center group transition-colors duration-200">
                            <?= icon('info-circle', 'w-3 h-3 mr-2') ?>
                            Chi Siamo
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Support -->
            <div>
                <h2 class="text-lg font-semibold text-accent-violet mb-4 flex items-center">
                    <?= icon('life-ring', 'w-5 h-5 text-accent-purple mr-2') ?>
                    Supporto
                </h2>
                <ul class="space-y-3">
                    <li>
                        <a href="<?= url('help/faq') ?>" class="text-neutral-silver hover:text-accent-purple flex items-center group transition-colors duration-200">
                            <?= icon('question-circle', 'w-3 h-3 mr-2') ?>
                            Domande Frequenti
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('help/guide') ?>" class="text-neutral-silver hover:text-accent-purple flex items-center group transition-colors duration-200">
                            <?= icon('book', 'w-3 h-3 mr-2') ?>
                            Guida all'uso
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('help/safety') ?>" class="text-neutral-silver hover:text-accent-purple flex items-center group transition-colors duration-200">
                            <?= icon('user-shield', 'w-3 h-3 mr-2') ?>
                            Sicurezza
                        </a>
                    </li>
                    <li>
                        <a href="<?= url('legal/report') ?>" class="text-neutral-silver hover:text-accent-purple flex items-center group transition-colors duration-200">
                            <?= icon('flag', 'w-3 h-3 mr-2') ?>
                            Segnala Problema
                        </a>
                    </li>
                </ul>
            </div>
        </div>


        <!-- Social & Community -->
        <div class="mt-8 pt-8 border-t border-neutral-darkGray">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-4 md:mb-0">
                    <h2 class="text-lg font-semibold text-accent-violet mb-2 flex items-center">
                        <?= icon('users', 'w-5 h-5 text-accent-purple mr-2') ?>
                        Community
                    </h2>
                    <p class="text-neutral-silver text-sm">Seguici sui social per aggiornamenti</p>
                </div>

                <div class="flex space-x-4">
                    <a href="https://www.facebook.com/people/Need2talk/61582668675756/" target="_blank" rel="noopener noreferrer" title="Seguici su Facebook" class="w-10 h-10 bg-brand-midnight hover:bg-accent-purple/10 border border-neutral-darkGray rounded-full flex items-center justify-center transition-all duration-200 hover:border-accent-violet group">
                        <?= icon('facebook', 'w-5 h-5 text-neutral-silver group-hover:text-accent-purple', 'solid') ?>
                    </a>
                    <a href="https://x.com/Yxneed2talkxY" target="_blank" rel="noopener noreferrer" title="Seguici su X (Twitter)" class="w-10 h-10 bg-brand-midnight hover:bg-accent-purple/10 border border-neutral-darkGray rounded-full flex items-center justify-center transition-all duration-200 hover:border-accent-violet group">
                        <?= icon('twitter', 'w-5 h-5 text-neutral-silver group-hover:text-accent-purple', 'solid') ?>
                    </a>
                    <a href="https://www.instagram.com/need2talk_italia/" target="_blank" rel="noopener noreferrer" title="Seguici su Instagram" class="w-10 h-10 bg-brand-midnight hover:bg-accent-purple/10 border border-neutral-darkGray rounded-full flex items-center justify-center transition-all duration-200 hover:border-accent-violet group">
                        <?= icon('instagram', 'w-5 h-5 text-neutral-silver group-hover:text-accent-purple', 'solid') ?>
                    </a>
                    <a href="https://www.tiktok.com/@yx_need2talk_xy" target="_blank" rel="noopener noreferrer" title="Seguici su TikTok" class="w-10 h-10 bg-brand-midnight hover:bg-accent-purple/10 border border-neutral-darkGray rounded-full flex items-center justify-center transition-all duration-200 hover:border-accent-violet group">
                        <?= icon('tiktok', 'w-5 h-5 text-neutral-silver group-hover:text-accent-purple', 'solid') ?>
                    </a>
                </div>
            </div>
        </div>


        <!-- Bottom Footer -->
        <div class="mt-8 pt-8 border-t border-neutral-darkGray">
            <div class="flex flex-col md:flex-row justify-between items-center text-center md:text-left">
                <!-- Copyright -->
                <div class="mb-4 md:mb-0">
                    <p class="text-neutral-silver text-sm">
                        &copy; <?= date('Y') ?> need2talk. Tutti i diritti riservati.
                    </p>
                    <p class="text-neutral-silver text-xs mt-1">
                        Piattaforma italiana per maggiorenni • Creato con la mente AI e il cuore Umano
                        <?= icon('heart', 'w-3 h-3 text-energy-pink inline-block mx-1', 'solid') ?>
                        <span class="inline-block">🇮🇹</span>
                    </p>
                </div>

                <!-- Trust Indicators -->
                <div class="flex flex-wrap justify-center md:justify-end space-x-6 text-xs text-neutral-silver">
                    <div class="flex items-center space-x-1 hover:text-cool-cyan transition-colors duration-200">
                        <?= icon('shield', 'w-3 h-3') ?>
                        <span>SSL Sicuro</span>
                    </div>

                    <div class="flex items-center space-x-1 hover:text-cool-cyan transition-colors duration-200">
                        <?= icon('user-shield', 'w-3 h-3') ?>
                        <span>GDPR Compliant</span>
                    </div>

                    <div class="flex items-center space-x-1 hover:text-energy-pink transition-colors duration-200">
                        <?= icon('heart', 'w-3 h-3', 'solid') ?>
                        <span>Made in Italy</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</footer>