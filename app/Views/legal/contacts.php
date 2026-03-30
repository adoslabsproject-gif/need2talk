<?php
/**
 * NEED2TALK - CONTACTS PAGE (TAILWIND + FONTAWESOME)
 *
 * FLUSSO ARCHITETTURA:
 * 1. Pagina Contatti con Tailwind design
 * 2. Stile con tema purple/pink
 * 3. Responsive design ottimizzato
 * 4. Performance ottimizzata per migliaia di utenti
 * 5. Informazioni di contatto complete e aggiornate
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

$title = 'Contatti - need2talk';
$description = 'Contatta need2talk per supporto, collaborazioni business, questioni legali. Team dedicato per ogni esigenza. Risposte rapide garantite.';

// CONTENT START
ob_start();
?>

<div class="pt-20">
    <div class="max-w-6xl mx-auto px-4 py-12">

        <!-- Header -->
        <div class="text-center mb-16">
            <!-- Logo -->
            <div class="flex justify-center mb-8">
                <div class="relative w-20 h-20">
                    <div class="absolute inset-0 bg-gradient-to-br from-purple-600 to-pink-600 rounded-2xl shadow-xl"></div>
                    <picture>
                        <source srcset="<?php echo asset('img/logo-160-retina.webp'); ?>" type="image/webp">
                        <img src="<?php echo asset('img/logo-160-retina.png'); ?>"
                             alt="need2talk Logo"
                             class="absolute inset-0 w-full h-full rounded-2xl object-cover"
                             loading="lazy"
                             decoding="async"
                             width="80"
                             height="80">
                    </picture>

                    <!-- Cerchi concentrici pulsanti -->
                    <div class="absolute inset-0 rounded-2xl border-2 border-pink-500/60 animate-ping"></div>
                    <div class="absolute -inset-2 rounded-2xl border border-purple-500/40 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                </div>
            </div>

            <h1 class="text-5xl md:text-6xl font-bold mb-6 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                Contatti
            </h1>
            <p class="text-xl text-neutral-silver max-w-3xl mx-auto leading-relaxed">
                Il nostro team è qui per aiutarti. Trova il modo migliore per contattarci
            </p>
        </div>

        <!-- Contact Cards Grid -->
        <div class="grid lg:grid-cols-2 gap-8 mb-16">

            <!-- Supporto Generale -->
            <div class="bg-brand-charcoal backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <div class="flex items-start">
                    <div class="w-16 h-16 bg-accent-purple/20 rounded-xl flex items-center justify-center mr-4 flex-shrink-0">
                        <?= icon('headset', 'w-8 h-8 text-accent-purple') ?>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-2xl font-bold text-accent-violet mb-3">Supporto Generale</h3>
                        <p class="text-neutral-silver mb-6">Per domande generali, supporto tecnico e assistenza utilizzo</p>
                        <div class="space-y-3 text-sm">
                            <div class="flex items-center">
                                <?= icon('envelope', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Email:</strong> support@need2talk.it</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('phone', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Telefono:</strong> 059/361164</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('clock', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Orari:</strong> Lun-Ven 9:00-18:00</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('reply', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Risposta:</strong> Entro 24 ore</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Privacy & Legale -->
            <div class="bg-brand-charcoal backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <div class="flex items-start">
                    <div class="w-16 h-16 bg-cool-cyan/20 rounded-xl flex items-center justify-center mr-4 flex-shrink-0">
                        <?= icon('shield', 'w-8 h-8 text-cool-cyan') ?>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-2xl font-bold text-accent-violet mb-3">Privacy & Legale</h3>
                        <p class="text-neutral-silver mb-6">Questioni privacy e legali</p>
                        <div class="space-y-3 text-sm">
                            <div class="flex items-center">
                                <?= icon('envelope', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Email:</strong> support@need2talk.it</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('phone', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Telefono:</strong> 059/361164</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('user', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Responsabile:</strong> Nicola Cucurachi</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('reply', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Risposta:</strong> Entro 48 ore</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Business -->
            <div class="bg-brand-charcoal backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <div class="flex items-start">
                    <div class="w-16 h-16 bg-accent-lavender/20 rounded-xl flex items-center justify-center mr-4 flex-shrink-0">
                        <?= icon('briefcase', 'w-8 h-8 text-accent-lavender') ?>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-2xl font-bold text-accent-violet mb-3">Business & Partnership</h3>
                        <p class="text-neutral-silver mb-6">Collaborazioni, partnership e opportunità business</p>
                        <div class="space-y-3 text-sm">
                            <div class="flex items-center">
                                <?= icon('envelope', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Email:</strong> support@need2talk.it</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('phone', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Telefono:</strong> 059/361164</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('reply', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Risposta:</strong> Entro 48 ore</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tecnico -->
            <div class="bg-brand-charcoal backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <div class="flex items-start">
                    <div class="w-16 h-16 bg-energy-pink/20 rounded-xl flex items-center justify-center mr-4 flex-shrink-0">
                        <?= icon('tools', 'w-8 h-8 text-energy-pink') ?>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-2xl font-bold text-accent-violet mb-3">Supporto Tecnico</h3>
                        <p class="text-neutral-silver mb-6">Problemi tecnici, bug report e sviluppatori</p>
                        <div class="space-y-3 text-sm">
                            <div class="flex items-center">
                                <?= icon('envelope', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Email:</strong> support@need2talk.it</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('phone', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Telefono:</strong> 059/361164</span>
                            </div>
                            <div class="flex items-center">
                                <?= icon('tachometer-alt', 'text-accent-purple w-5 h-5 mr-3') ?>
                                <span><strong class="text-accent-violet">Urgenza:</strong> 2-6 ore per problemi critici</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Company Info -->
        <div class="bg-brand-charcoal backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10 mb-12">
            <h3 class="text-2xl font-bold text-accent-violet mb-6 flex items-center">
                <?= icon('building', 'w-5 h-5 text-accent-purple mr-3') ?>
                Informazioni Aziendali
            </h3>

            <div class="grid md:grid-cols-2 gap-8">
                <div>
                    <h4 class="font-semibold text-accent-violet mb-3 text-lg">Sede</h4>
                    <div class="text-neutral-silver space-y-2">
                        <p class="font-semibold text-neutral-white">need2talk</p>
                        <p>Via Pancaldi, 59</p>
                        <p>41122 Modena - MO - Italy</p>
                    </div>
                </div>

                <div>
                    <h4 class="font-semibold text-accent-violet mb-3 text-lg">Contatti Diretti</h4>
                    <div class="text-neutral-silver space-y-2">
                        <p><strong>Titolare:</strong> Nicola Cucurachi</p>
                        <p><strong>Email:</strong> support@need2talk.it</p>
                        <p><strong>Telefono:</strong> 059/361164</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Social & Links -->
        <div class="text-center">
            <div class="bg-brand-charcoal backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <h3 class="text-2xl font-bold text-accent-violet mb-6">Seguici sui Social</h3>
                <div class="flex justify-center space-x-6 mb-8">
                    <!-- Twitter/X -->
                    <a href="https://x.com/Yxneed2talkxY" target="_blank" rel="noopener noreferrer" class="w-14 h-14 bg-blue-600/20 border border-blue-600/30 rounded-xl flex items-center justify-center hover:bg-blue-600/30 hover:border-blue-600/50 transition-all duration-300 group" aria-label="Seguici su X (Twitter)">
                        <?= icon('twitter', 'w-8 h-8 text-blue-400 group-hover:scale-110 transition-transform', 'solid') ?>
                    </a>
                    <!-- Facebook -->
                    <a href="https://www.facebook.com/people/Need2talk/61582668675756/" target="_blank" rel="noopener noreferrer" class="w-14 h-14 bg-blue-800/20 border border-blue-800/30 rounded-xl flex items-center justify-center hover:bg-blue-800/30 hover:border-blue-800/50 transition-all duration-300 group" aria-label="Seguici su Facebook">
                        <?= icon('facebook', 'w-8 h-8 text-blue-500 group-hover:scale-110 transition-transform', 'solid') ?>
                    </a>
                    <!-- Instagram -->
                    <a href="https://www.instagram.com/yxxneed2talkxxy/" target="_blank" rel="noopener noreferrer" class="w-14 h-14 bg-pink-600/20 border border-pink-600/30 rounded-xl flex items-center justify-center hover:bg-pink-600/30 hover:border-pink-600/50 transition-all duration-300 group" aria-label="Seguici su Instagram">
                        <?= icon('instagram', 'w-8 h-8 text-pink-400 group-hover:scale-110 transition-transform', 'solid') ?>
                    </a>
                    <!-- LinkedIn -->
                    <a href="https://linkedin.com/company/need2talk" target="_blank" rel="noopener noreferrer" class="w-14 h-14 bg-blue-700/20 border border-blue-700/30 rounded-xl flex items-center justify-center hover:bg-blue-700/30 hover:border-blue-700/50 transition-all duration-300 group" aria-label="Seguici su LinkedIn">
                        <?= icon('users', 'w-8 h-8 text-blue-400 group-hover:scale-110 transition-transform', 'solid') ?>
                    </a>
                </div>

                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                    <a href="<?= url('auth/register') ?>"
                       class="px-8 py-4 bg-energy-pink hover:bg-energy-pink/90 text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-xl shadow-energy-pink/25 group">
                        <?= icon('user-plus', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                        Registrati Gratis
                    </a>

                    <a href="<?= url('/') ?>"
                       class="px-8 py-4 border-2 border-accent-violet text-accent-violet hover:bg-accent-violet hover:text-white font-medium rounded-xl transition-all duration-300 group">
                        <?= icon('home', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                        Torna alla Home
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
// CONTENT END

// Render usando layout unificato
include APP_ROOT . '/app/Views/layouts/guest.php';
