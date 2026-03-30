<?php
/**
 * NEED2TALK - USER GUIDE PAGE (TAILWIND + FONTAWESOME)
 *
 * ENTERPRISE OPTIMIZATIONS:
 * - Page transition support
 * - Performance monitoring
 * - Minimal JS footprint
 * - Lazy loading images
 * - GPU-accelerated animations
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

$title = 'Guida all\'uso - need2talk';
$description = 'Guida completa per utilizzare need2talk. Scopri come registrare audio, condividere emozioni e interagire con la community.';

// CONTENT START
ob_start();
?>

    <!-- Background Animato -->
    <div class="fixed inset-0 bg-gradient-to-br from-gray-900 via-accent-violet/10 to-gray-900 pointer-events-none">
        <div class="absolute inset-0 bg-gradient-to-t from-gray-900/50 to-transparent"></div>
    </div>

    <!-- Main Content -->
    <div class="relative pt-20">
        <div class="max-w-4xl mx-auto px-4 py-12">

            <!-- Header -->
            <div class="text-center mb-12">
                <h1 class="text-5xl md:text-6xl font-bold mb-6 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                    <?= icon('book', 'w-5 h-5 mr-3') ?>
                    Guida all'uso
                </h1>
                <p class="text-xl text-neutral-white max-w-3xl mx-auto leading-relaxed">
                    Tutto quello che devi sapere per usare need2talk al meglio
                </p>
            </div>

            <!-- Guide Sections -->
            <div class="space-y-12">

                <!-- 1. Getting Started -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                    <h2 class="text-3xl font-bold text-accent-purple mb-6 flex items-center">
                        <?= icon('rocket', 'w-5 h-5 mr-3') ?>
                        1. Come Iniziare
                    </h2>

                    <div class="space-y-6 text-neutral-white">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-accent-purple/20 rounded-full flex items-center justify-center">
                                <span class="text-accent-purple font-bold">1</span>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-semibold text-white mb-2">Registrati</h3>
                                <p class="leading-relaxed">
                                    Crea il tuo account gratuito compilando il form di registrazione.
                                    Ti servono solo un'email valida e una password sicura.
                                    <strong class="text-accent-purple">Devi avere almeno 18 anni</strong>.
                                </p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-accent-purple/20 rounded-full flex items-center justify-center">
                                <span class="text-accent-purple font-bold">2</span>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-semibold text-white mb-2">Verifica Email</h3>
                                <p class="leading-relaxed">
                                    Controlla la tua casella email e clicca sul link di verifica.
                                    Questo passaggio è necessario per attivare il tuo account.
                                </p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-accent-purple/20 rounded-full flex items-center justify-center">
                                <span class="text-accent-purple font-bold">3</span>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-semibold text-white mb-2">Completa il Profilo</h3>
                                <p class="leading-relaxed">
                                    Aggiungi una foto profilo, una bio e personalizza le tue impostazioni.
                                    Questo aiuta gli altri utenti a conoscerti meglio!
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Recording Audio -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-energy-pink/20 shadow-2xl shadow-energy-pink/10">
                    <h2 class="text-3xl font-bold text-pink-400 mb-6 flex items-center">
                        <?= icon('microphone', 'w-5 h-5 mr-3') ?>
                        2. Registrare Audio
                    </h2>

                    <div class="space-y-6 text-neutral-white">
                        <p class="leading-relaxed">
                            La registrazione audio è il cuore di need2talk. Segui questi semplici passi:
                        </p>

                        <div class="bg-brand-midnight/50 rounded-xl p-6 border border-energy-pink/20">
                            <ol class="space-y-4">
                                <li class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-6 h-6 bg-energy-pink text-white rounded-full flex items-center justify-center text-sm font-bold">1</span>
                                    <div>
                                        <strong class="text-white">Seleziona un'Emozione</strong>
                                        <p class="text-sm mt-1">Scegli l'emozione che vuoi esprimere: Felicità, Tristezza, Rabbia, Paura, Amore, ecc.</p>
                                    </div>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-6 h-6 bg-energy-pink text-white rounded-full flex items-center justify-center text-sm font-bold">2</span>
                                    <div>
                                        <strong class="text-white">Clicca sul Microfono</strong>
                                        <p class="text-sm mt-1">Premi il pulsante di registrazione e autorizza l'accesso al microfono quando richiesto.</p>
                                    </div>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-6 h-6 bg-energy-pink text-white rounded-full flex items-center justify-center text-sm font-bold">3</span>
                                    <div>
                                        <strong class="text-white">Inizia a Parlare</strong>
                                        <p class="text-sm mt-1">Hai <strong class="text-pink-400">30 secondi</strong> per registrare il tuo messaggio vocale.</p>
                                    </div>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-6 h-6 bg-energy-pink text-white rounded-full flex items-center justify-center text-sm font-bold">4</span>
                                    <div>
                                        <strong class="text-white">Ascolta l'Anteprima</strong>
                                        <p class="text-sm mt-1">Prima di pubblicare, puoi ascoltare la registrazione e rifarla se non ti soddisfa.</p>
                                    </div>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-6 h-6 bg-energy-pink text-white rounded-full flex items-center justify-center text-sm font-bold">5</span>
                                    <div>
                                        <strong class="text-white">Pubblica</strong>
                                        <p class="text-sm mt-1">Se sei soddisfatto, clicca "Pubblica" per condividere il tuo audio con la community.</p>
                                    </div>
                                </li>
                            </ol>
                        </div>

                        <div class="bg-energy-magenta/10 border border-energy-magenta/30 rounded-lg p-4">
                            <p class="text-energy-magenta text-sm">
                                <?= icon('exclamation-triangle', 'w-5 h-5 mr-2') ?>
                                <strong>Limiti:</strong> Puoi pubblicare massimo <strong>10 audio al giorno</strong>.
                                Ogni audio può durare massimo <strong>30 secondi</strong>.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- 3. Exploring Content -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                    <h2 class="text-3xl font-bold text-accent-purple mb-6 flex items-center">
                        <?= icon('compass', 'w-5 h-5 mr-3') ?>
                        3. Esplorare i Contenuti
                    </h2>

                    <div class="space-y-6 text-neutral-white">
                        <div class="grid md:grid-cols-2 gap-6">
                            <div class="bg-brand-midnight/50 rounded-xl p-6 border border-accent-purple/20">
                                <h3 class="font-semibold text-white mb-3 flex items-center">
                                    <?= icon('fire', 'w-5 h-5 text-orange-400 mr-2') ?>
                                    Feed Principale
                                </h3>
                                <p class="text-sm leading-relaxed">
                                    Nella home trovi tutti gli audio più recenti della community,
                                    organizzati per emozione. Scorri e ascolta ciò che ti ispira!
                                </p>
                            </div>

                            <div class="bg-brand-midnight/50 rounded-xl p-6 border border-accent-purple/20">
                                <h3 class="font-semibold text-white mb-3 flex items-center">
                                    <?= icon('search', 'w-5 h-5 text-blue-400 mr-2') ?>
                                    Ricerca
                                </h3>
                                <p class="text-sm leading-relaxed">
                                    Usa la barra di ricerca per trovare utenti specifici,
                                    audio per emozione o contenuti particolari.
                                </p>
                            </div>

                            <div class="bg-brand-midnight/50 rounded-xl p-6 border border-accent-purple/20">
                                <h3 class="font-semibold text-white mb-3 flex items-center">
                                    <?= icon('user-friends', 'w-5 h-5 text-green-400 mr-2') ?>
                                    Profili Utente
                                </h3>
                                <p class="text-sm leading-relaxed">
                                    Clicca su un utente per vedere il suo profilo completo,
                                    tutti i suoi audio pubblicati e le sue statistiche.
                                </p>
                            </div>

                            <div class="bg-brand-midnight/50 rounded-xl p-6 border border-accent-purple/20">
                                <h3 class="font-semibold text-white mb-3 flex items-center">
                                    <?= icon('heart', 'w-5 h-5 text-pink-400 mr-2') ?>
                                    Like & Commenti
                                </h3>
                                <p class="text-sm leading-relaxed">
                                    Apprezza gli audio che ti piacciono mettendo like
                                    e lascia commenti per interagire con la community.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Privacy & Security -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-energy-pink/20 shadow-2xl shadow-energy-pink/10">
                    <h2 class="text-3xl font-bold text-pink-400 mb-6 flex items-center">
                        <?= icon('shield', 'w-5 h-5 mr-3') ?>
                        4. Privacy e Sicurezza
                    </h2>

                    <div class="space-y-6 text-neutral-white">
                        <p class="leading-relaxed">
                            La tua sicurezza è la nostra priorità. Ecco cosa puoi fare per proteggere il tuo account:
                        </p>

                        <div class="grid md:grid-cols-2 gap-4">
                            <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-energy-pink/10">
                                <?= icon('lock', 'w-6 h-6 text-pink-400 mt-1') ?>
                                <div>
                                    <h3 class="font-semibold text-white mb-1">Profilo Privato</h3>
                                    <p class="text-sm">Rendi il tuo profilo privato dalle impostazioni per controllare chi può vedere i tuoi contenuti.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-energy-pink/10">
                                <?= icon('ban', 'w-6 h-6 text-energy-magenta mt-1') ?>
                                <div>
                                    <h3 class="font-semibold text-white mb-1">Blocca Utenti</h3>
                                    <p class="text-sm">Blocca utenti indesiderati per impedire loro di interagire con te.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-energy-pink/10">
                                <?= icon('flag', 'w-6 h-6 text-orange-400 mt-1') ?>
                                <div>
                                    <h3 class="font-semibold text-white mb-1">Segnala Contenuti</h3>
                                    <p class="text-sm">Segnala contenuti inappropriati o utenti che violano le regole della community.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-energy-pink/10">
                                <?= icon('trash', 'w-6 h-6 text-neutral-silver mt-1') ?>
                                <div>
                                    <h3 class="font-semibold text-white mb-1">Elimina Contenuti</h3>
                                    <p class="text-sm">Puoi eliminare i tuoi audio in qualsiasi momento dal tuo profilo.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5. Tips & Best Practices -->
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                    <h2 class="text-3xl font-bold text-accent-purple mb-6 flex items-center">
                        <?= icon('lightbulb', 'w-5 h-5 mr-3') ?>
                        5. Suggerimenti Utili
                    </h2>

                    <div class="space-y-4 text-neutral-white">
                        <div class="flex items-start gap-3">
                            <?= icon('check-circle', 'w-5 h-5 text-green-400 mt-1') ?>
                            <p><strong class="text-white">Usa un buon microfono:</strong> La qualità audio è importante per far arrivare il tuo messaggio.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <?= icon('check-circle', 'w-5 h-5 text-green-400 mt-1') ?>
                            <p><strong class="text-white">Sii autentico:</strong> need2talk è uno spazio sicuro per esprimere le tue emozioni reali.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <?= icon('check-circle', 'w-5 h-5 text-green-400 mt-1') ?>
                            <p><strong class="text-white">Rispetta gli altri:</strong> Ascolta con empatia e interagisci con gentilezza.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <?= icon('check-circle', 'w-5 h-5 text-green-400 mt-1') ?>
                            <p><strong class="text-white">Registra in un luogo silenzioso:</strong> Evita rumori di sottofondo per audio più chiari.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <?= icon('check-circle', 'w-5 h-5 text-green-400 mt-1') ?>
                            <p><strong class="text-white">Prova l'anteprima:</strong> Ascolta sempre l'audio prima di pubblicarlo.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <?= icon('check-circle', 'w-5 h-5 text-green-400 mt-1') ?>
                            <p><strong class="text-white">Scegli l'emozione giusta:</strong> Questo aiuta gli utenti a trovare il contenuto che rispecchia il loro stato d'animo.</p>
                        </div>
                    </div>
                </div>

            </div>

            <!-- CTA Section -->
            <div class="mt-16 text-center">
                <div class="bg-gradient-to-r from-accent-violet/20 to-accent-purple/20 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/30 shadow-2xl shadow-accent-purple/10">
                    <h2 class="text-3xl font-bold text-white mb-4">Pronto per Iniziare?</h2>
                    <p class="text-neutral-white mb-6 text-lg">
                        Unisciti alla community di need2talk e condividi la tua voce con il mondo.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                        <a href="<?= url('auth/register') ?>"
                           class="px-8 py-4 bg-gradient-to-r from-accent-violet to-accent-purple hover:from-accent-violet hover:to-accent-purple text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-xl shadow-accent-purple/25 group">
                            <?= icon('user-plus', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                            Registrati Ora
                        </a>

                        <a href="<?= url('help/faq') ?>"
                           class="px-8 py-4 bg-brand-midnight/50 hover:bg-gray-600/50 text-white font-medium rounded-xl transition-all duration-300 border border-neutral-darkGray/50 hover:border-gray-500 group">
                            <?= icon('question-circle', 'w-5 h-5 mr-2 group-hover:animate-pulse') ?>
                            Domande Frequenti
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
