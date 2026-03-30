<?php
/**
 * NEED2TALK - HOME PAGE (ENTERPRISE GALAXY v5.1)
 *
 * Landing page - Fun social vibe, not a depression helpline
 * Focus: voce come modo diverso di socializzare
 *
 * @version 5.1.0 Enterprise Galaxy - Fun & Engaging
 */

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

$title = 'need2talk - Il social dove parli davvero';
$description = 'Stanco di scrollare in silenzio? Social vocale italiano con matching emotivo. Trova la tua anima affine basandoti su consapevolezza emotiva, non su foto. Community 18+ autentica.';

$pageCSS = ['pages/home'];
$pageJS = ['pages/home'];

ob_start();
?>

<div class="relative z-10 min-h-screen py-16 md:py-24">
    <div class="max-w-4xl mx-auto px-6">

        <!-- ==================== HERO SECTION ==================== -->
        <section class="text-center pt-8 pb-16 md:pb-20">

            <!-- Logo - ENTERPRISE: WebP 168px ottimizzato (8KB vs 47KB PNG = -83%) -->
            <div class="flex justify-center mb-8">
                <div class="relative w-24 h-24">
                    <picture>
                        <source srcset="<?= asset('img/logo-168.webp') ?>" type="image/webp">
                        <img src="<?= asset('img/logo-168.png') ?>"
                             alt="need2talk"
                             class="w-full h-full rounded-2xl object-cover"
                             loading="eager"
                             fetchpriority="high"
                             width="96"
                             height="96"
                             decoding="async">
                    </picture>
                    <!-- ENTERPRISE: Animazione ridotta - solo bordo statico con glow, no animate-ping (risparmio GPU) -->
                    <div class="absolute inset-0 rounded-full border-2 border-accent-purple/60 shadow-lg shadow-accent-purple/30"></div>
                </div>
            </div>

            <!-- Hook - DIVERTENTE, NON DISPERATO -->
            <h1 class="text-3xl md:text-5xl font-black mb-8 leading-tight">
                <span style="color: #ffffff;">Stanco di scrollare in silenzio?</span><br>
                <span style="color: #ffffff; -webkit-text-fill-color: transparent; -webkit-background-clip: text; background-clip: text;" class="bg-gradient-to-r from-accent-purple via-energy-pink to-accent-purple">Qui si parla davvero.</span>
            </h1>

            <!-- Value prop - SOCIAL, NON TERAPIA -->
            <p class="text-xl md:text-2xl mb-12 max-w-2xl mx-auto leading-relaxed" style="color: #d1d5db;">
                Il primo social vocale italiano.<br class="hidden md:block">
                <span style="color: #00d9ff;">Meno testo, più personalità.</span><br class="hidden md:block">
                <span style="color: #a855f7; font-weight: 600;">Trova la tua anima gemella emotiva. 💜</span>
            </p>

            <!-- CTA Principale -->
            <div class="flex flex-col gap-6 justify-center items-center mb-12">
                <a href="<?= url('auth/register') ?>"
                   class="group flex items-center justify-center space-x-3 rounded-2xl transition-all duration-300 transform hover:scale-105"
                   style="background: linear-gradient(135deg, #7b2cbf 0%, #e91e8c 100%); box-shadow: 0 10px 30px rgba(123, 44, 191, 0.4); padding: 20px 48px; font-size: 20px; font-weight: 700; color: #ffffff;">
                    <span>Entra nella community</span>
                    <svg class="w-6 h-6 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                </a>

                <p class="text-base" style="color: #d1d5db;">
                    Gratis per sempre. Solo nickname ed email.
                </p>
            </div>

            <!-- Badge -->
            <div class="flex flex-wrap justify-center gap-4 text-base mt-4">
                <span class="flex items-center gap-2 px-4 py-2 rounded-full" style="background: rgba(0, 217, 255, 0.15); border: 1px solid rgba(0, 217, 255, 0.4);">
                    <span style="color: #00d9ff;">🎙️</span>
                    <span style="color: #ffffff;">100% Vocale</span>
                </span>
                <span class="flex items-center gap-2 px-4 py-2 rounded-full" style="background: rgba(168, 85, 247, 0.15); border: 1px solid rgba(168, 85, 247, 0.4);">
                    <span style="color: #a855f7;">💜</span>
                    <span style="color: #ffffff;">Anime Affini</span>
                </span>
                <span class="flex items-center gap-2 px-4 py-2 rounded-full" style="background: rgba(233, 30, 140, 0.15); border: 1px solid rgba(233, 30, 140, 0.4);">
                    <span style="color: #e91e8c;">🎭</span>
                    <span style="color: #ffffff;">Anonimo</span>
                </span>
                <span class="flex items-center gap-2 px-4 py-2 rounded-full" style="background: rgba(123, 44, 191, 0.15); border: 1px solid rgba(123, 44, 191, 0.4);">
                    <span style="color: #7b2cbf;">🔞</span>
                    <span style="color: #ffffff;">Solo 18+</span>
                </span>
            </div>
        </section>

        <!-- ==================== ANIME AFFINI - HERO FEATURE ==================== -->
        <section class="mb-16 md:mb-24">
            <div class="relative rounded-3xl p-10 md:p-16 overflow-hidden" style="background: linear-gradient(135deg, rgba(168, 85, 247, 0.3) 0%, rgba(236, 72, 153, 0.3) 50%, rgba(233, 30, 140, 0.3) 100%); border: 2px solid rgba(168, 85, 247, 0.4); box-shadow: 0 20px 60px rgba(168, 85, 247, 0.3);">
                <!-- Decorative background elements -->
                <div class="absolute top-0 right-0 w-64 h-64 bg-gradient-to-br from-purple-500/20 to-transparent rounded-full blur-3xl"></div>
                <div class="absolute bottom-0 left-0 w-64 h-64 bg-gradient-to-tr from-pink-500/20 to-transparent rounded-full blur-3xl"></div>

                <div class="relative z-10 text-center">
                    <div class="text-6xl mb-6">💜</div>
                    <h2 class="text-3xl md:text-4xl font-black mb-6" style="color: #ffffff;">
                        Trova la tua <span style="background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Anima Affine</span>
                    </h2>
                    <p class="text-xl md:text-2xl mb-8 max-w-2xl mx-auto leading-relaxed" style="color: #e0e0e0;">
                        Non solo amicizie. Qui trovi chi <strong style="color: #ffffff;">sente come te</strong>.<br>
                        Matching basato su <span style="color: #a855f7; font-weight: 600;">consapevolezza emotiva</span>, non su foto filtrate.
                    </p>

                    <!-- Features grid -->
                    <div class="grid md:grid-cols-3 gap-6 mt-10 mb-8">
                        <div class="bg-white/5 backdrop-blur-sm rounded-2xl p-6 border border-white/10">
                            <div class="text-3xl mb-3">🎭</div>
                            <h3 class="text-lg font-bold mb-2" style="color: #ffffff;">Profilo Emotivo</h3>
                            <p class="text-sm" style="color: #d1d5db;">Rispondi a 10 domande. Scopri chi sei davvero.</p>
                        </div>
                        <div class="bg-white/5 backdrop-blur-sm rounded-2xl p-6 border border-white/10">
                            <div class="text-3xl mb-3">🔮</div>
                            <h3 class="text-lg font-bold mb-2" style="color: #ffffff;">Matching Intelligente</h3>
                            <p class="text-sm" style="color: #d1d5db;">Algoritmo trova chi ha energia compatibile con te.</p>
                        </div>
                        <div class="bg-white/5 backdrop-blur-sm rounded-2xl p-6 border border-white/10">
                            <div class="text-3xl mb-3">💕</div>
                            <h3 class="text-lg font-bold mb-2" style="color: #ffffff;">Connessioni Vere</h3>
                            <p class="text-sm" style="color: #d1d5db;">Non swipe a caso. Solo persone davvero affini.</p>
                        </div>
                    </div>

                    <div class="inline-block px-6 py-3 rounded-full text-base font-semibold" style="background: rgba(168, 85, 247, 0.2); border: 1px solid rgba(168, 85, 247, 0.5); color: #ffffff;">
                        ✨ Disponibile dopo la registrazione
                    </div>
                </div>
            </div>
        </section>

        <!-- ==================== COSA RENDE DIVERSO ==================== -->
        <section class="mb-16 md:mb-24">
            <div class="card p-8 md:p-12">
                <h2 class="text-2xl md:text-3xl font-bold text-center mb-10" style="color: #ffffff;">
                    Perché la voce cambia tutto
                </h2>

                <div class="grid md:grid-cols-3 gap-8 text-center">
                    <div class="p-6">
                        <div class="text-4xl mb-4">🎤</div>
                        <h3 class="text-lg font-bold mb-3" style="color: #ffffff;">30 secondi, zero filtri</h3>
                        <p class="text-base leading-relaxed" style="color: #d1d5db;">
                            Niente post curati per ore. Parla e basta.
                        </p>
                    </div>
                    <div class="p-6">
                        <div class="text-4xl mb-4">🎧</div>
                        <h3 class="text-lg font-bold mb-3" style="color: #ffffff;">Ascolta voci vere</h3>
                        <p class="text-base leading-relaxed" style="color: #d1d5db;">
                            Scopri persone dalla voce, non dalle foto.
                        </p>
                    </div>
                    <div class="p-6">
                        <div class="text-4xl mb-4">💬</div>
                        <h3 class="text-lg font-bold mb-3" style="color: #ffffff;">Chat private</h3>
                        <p class="text-base leading-relaxed" style="color: #d1d5db;">
                            Trovato qualcuno interessante? Scrivetegli.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ==================== COME FUNZIONA (3 STEP) ==================== -->
        <section class="mb-16 md:mb-24">
            <h2 class="text-2xl md:text-3xl font-bold text-center mb-12" style="color: #ffffff;">
                Semplice come parlare
            </h2>

            <div class="grid md:grid-cols-3 gap-10">
                <div class="text-center p-6">
                    <div class="w-16 h-16 bg-gradient-to-br from-accent-purple to-accent-violet rounded-2xl flex items-center justify-center mx-auto mb-5 text-2xl font-bold shadow-lg" style="color: #ffffff;">
                        1
                    </div>
                    <h3 class="text-xl font-bold mb-3" style="color: #ffffff;">Registra</h3>
                    <p class="text-base" style="color: #d1d5db;">Racconta qualcosa. Un pensiero, una storia, una cazzata.</p>
                </div>

                <div class="text-center p-6">
                    <div class="w-16 h-16 bg-gradient-to-br from-energy-pink to-accent-purple rounded-2xl flex items-center justify-center mx-auto mb-5 text-2xl font-bold shadow-lg" style="color: #ffffff;">
                        2
                    </div>
                    <h3 class="text-xl font-bold mb-3" style="color: #ffffff;">Esplora</h3>
                    <p class="text-base" style="color: #d1d5db;">Scorri il feed vocale. Trova chi ti incuriosisce.</p>
                </div>

                <div class="text-center p-6">
                    <div class="w-16 h-16 bg-gradient-to-br from-cool-cyan to-accent-violet rounded-2xl flex items-center justify-center mx-auto mb-5 text-2xl font-bold shadow-lg" style="color: #ffffff;">
                        3
                    </div>
                    <h3 class="text-xl font-bold mb-3" style="color: #ffffff;">Connettiti</h3>
                    <p class="text-base" style="color: #d1d5db;">Reagisci, commenta, chatta. Fai amicizia.</p>
                </div>
            </div>
        </section>

        <!-- ==================== COMMUNITY ==================== -->
        <section class="mb-16 md:mb-24">
            <div class="rounded-2xl p-8 md:p-12 text-center" style="background: linear-gradient(135deg, rgba(123, 44, 191, 0.2) 0%, rgba(233, 30, 140, 0.2) 100%); border: 1px solid rgba(123, 44, 191, 0.3);">
                <h3 class="text-2xl font-bold mb-5" style="color: #ffffff;">
                    Una community che sta nascendo
                </h3>
                <p class="text-lg leading-relaxed max-w-2xl mx-auto mb-6" style="color: #d1d5db;">
                    need2talk è nuovo. Siamo pochi ma buoni.<br>
                    <span style="color: #00d9ff;">Entrare ora significa essere tra i primi.</span>
                </p>
                <p class="text-base font-medium" style="color: #a855f7;">
                    Zero algoritmi che decidono cosa vedi. Zero pubblicità. Zero cazzate.
                </p>
            </div>
        </section>

        <!-- ==================== FINAL CTA ==================== -->
        <section class="text-center pb-16">
            <h2 class="text-3xl md:text-4xl font-bold mb-8" style="color: #ffffff;">
                Pronto a farti sentire?
            </h2>
            <p class="text-lg mb-12 max-w-lg mx-auto" style="color: #d1d5db;">
                La tua voce merita di essere ascoltata.
            </p>

            <a href="<?= url('auth/register') ?>"
               class="group inline-flex items-center justify-center space-x-3 rounded-2xl transition-all duration-300 transform hover:scale-105"
               style="background: linear-gradient(135deg, #7b2cbf 0%, #e91e8c 100%); box-shadow: 0 10px 30px rgba(123, 44, 191, 0.4); padding: 20px 48px; font-size: 20px; font-weight: 700; color: #ffffff;">
                <span>Inizia gratis</span>
                <svg class="w-6 h-6 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
            </a>

            <p class="mt-10 text-base" style="color: #d1d5db;">
                Gratuito per sempre • Anonimo • Italiano • Solo adulti
            </p>

            <a href="<?= url('auth/login') ?>"
               class="inline-block mt-6 text-base font-medium transition-colors"
               style="color: #00d9ff;">
                Hai già un account? Accedi
            </a>
        </section>

    </div>
</div>

<?php
$content = ob_get_clean();
require APP_ROOT . '/app/Views/layouts/guest.php';
?>
