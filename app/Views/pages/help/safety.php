<?php
/**
 * NEED2TALK - SAFETY PAGE (TAILWIND + FONTAWESOME)
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

$title = 'Sicurezza e Regole - need2talk';
$description = 'Linee guida per la sicurezza su need2talk. Scopri come proteggere il tuo account, segnalare contenuti inappropriati e rispettare le regole della community.';

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
                <?= icon('shield', 'w-5 h-5 mr-3') ?>
                Sicurezza e Regole
            </h1>
            <p class="text-xl text-neutral-white max-w-3xl mx-auto leading-relaxed">
                La tua sicurezza è la nostra priorità. Ecco come proteggerti e rispettare la community.
            </p>
        </div>

        <!-- Safety Sections -->
        <div class="space-y-8">

            <!-- Age Restriction -->
            <div class="bg-gradient-to-r from-red-600/20 to-orange-600/20 backdrop-blur-lg rounded-2xl p-8 border border-energy-magenta/30 shadow-2xl shadow-red-500/10">
                <div class="flex items-start gap-4">
                    <span class="text-5xl text-energy-magenta">🔞</span>
                    <div>
                        <h2 class="text-2xl font-bold text-white mb-3">
                            Solo Maggiorenni (18+)
                        </h2>
                        <p class="text-accent-violet leading-relaxed mb-3">
                            need2talk è una piattaforma <strong>riservata esclusivamente a utenti maggiorenni</strong>.
                            Devi avere almeno 18 anni per registrarti e utilizzare il servizio.
                        </p>
                        <p class="text-neutral-white text-sm">
                            La registrazione di account da parte di minori è severamente vietata e comporterà
                            la cancellazione immediata dell'account e la segnalazione alle autorità competenti.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Community Guidelines -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <h2 class="text-3xl font-bold text-accent-purple mb-6 flex items-center">
                    <?= icon('users', 'w-5 h-5 mr-3') ?>
                    Regole della Community
                </h2>

                <div class="space-y-4 text-neutral-white">
                    <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-accent-purple/10">
                        <?= icon('check-circle', 'w-6 h-6 text-green-400 mt-1') ?>
                        <div>
                            <h3 class="font-semibold text-white mb-1">Rispetta gli Altri</h3>
                            <p class="text-sm">Tratta tutti con gentilezza e rispetto. Niente bullismo, molestie o discriminazioni di alcun tipo.</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-accent-purple/10">
                        <?= icon('ban', 'w-6 h-6 text-energy-magenta mt-1') ?>
                        <div>
                            <h3 class="font-semibold text-white mb-1">No a Contenuti Inappropriati</h3>
                            <p class="text-sm">Sono vietati contenuti violenti, sessualmente espliciti, discriminatori, illegali o che incitano all'odio.</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-accent-purple/10">
                        <?= icon('shield', 'w-6 h-6 text-blue-400 mt-1') ?>
                        <div>
                            <h3 class="font-semibold text-white mb-1">Proteggi la Tua Privacy</h3>
                            <p class="text-sm">Non condividere informazioni personali sensibili come indirizzi, numeri di telefono o dati finanziari.</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-accent-purple/10">
                        <?= icon('copyright', 'w-6 h-6 text-yellow-400 mt-1') ?>
                        <div>
                            <h3 class="font-semibold text-white mb-1">Rispetta i Diritti d'Autore</h3>
                            <p class="text-sm">Carica solo contenuti di cui possiedi i diritti o per cui hai il permesso di utilizzo.</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-accent-purple/10">
                        <?= icon('robot', 'w-6 h-6 text-accent-purple mt-1') ?>
                        <div>
                            <h3 class="font-semibold text-white mb-1">No a Spam e Bot</h3>
                            <p class="text-sm">Niente spam, pubblicità non richiesta, automazione o tentativi di manipolazione del sistema.</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 bg-brand-midnight/50 rounded-lg p-4 border border-accent-purple/10">
                        <?= icon('balance-scale', 'w-6 h-6 text-pink-400 mt-1') ?>
                        <div>
                            <h3 class="font-semibold text-white mb-1">Rispetta la Legge</h3>
                            <p class="text-sm">Tutte le attività devono essere conformi alle leggi italiane ed europee vigenti.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Features -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-energy-pink/20 shadow-2xl shadow-energy-pink/10">
                <h2 class="text-3xl font-bold text-pink-400 mb-6 flex items-center">
                    <?= icon('lock', 'w-5 h-5 mr-3') ?>
                    Strumenti di Sicurezza
                </h2>

                <div class="grid md:grid-cols-2 gap-6">
                    <div class="bg-brand-midnight/50 rounded-xl p-6 border border-energy-pink/10">
                        <div class="flex items-center gap-3 mb-3">
                            <?= icon('user-lock', 'w-8 h-8  text-pink-400') ?>
                            <h3 class="font-semibold text-white text-lg">Profilo Privato</h3>
                        </div>
                        <p class="text-neutral-white text-sm leading-relaxed">
                            Imposta il tuo profilo come privato per controllare chi può vedere i tuoi contenuti.
                            Solo gli utenti che approvi potranno ascoltare i tuoi audio.
                        </p>
                    </div>

                    <div class="bg-brand-midnight/50 rounded-xl p-6 border border-energy-pink/10">
                        <div class="flex items-center gap-3 mb-3">
                            <?= icon('ban', 'w-8 h-8  text-energy-magenta') ?>
                            <h3 class="font-semibold text-white text-lg">Blocco Utenti</h3>
                        </div>
                        <p class="text-neutral-white text-sm leading-relaxed">
                            Blocca gli utenti che non vuoi che interagiscano con te.
                            Gli utenti bloccati non potranno vedere i tuoi contenuti né contattarti.
                        </p>
                    </div>

                    <div class="bg-brand-midnight/50 rounded-xl p-6 border border-energy-pink/10">
                        <div class="flex items-center gap-3 mb-3">
                            <?= icon('flag', 'w-8 h-8  text-orange-400') ?>
                            <h3 class="font-semibold text-white text-lg">Segnalazioni</h3>
                        </div>
                        <p class="text-neutral-white text-sm leading-relaxed">
                            Segnala contenuti inappropriati o comportamenti che violano le regole.
                            Il nostro team esaminerà ogni segnalazione entro 24-48 ore.
                        </p>
                    </div>

                    <div class="bg-brand-midnight/50 rounded-xl p-6 border border-energy-pink/10">
                        <div class="flex items-center gap-3 mb-3">
                            <?= icon('trash', 'w-8 h-8  text-neutral-silver') ?>
                            <h3 class="font-semibold text-white text-lg">Controllo Contenuti</h3>
                        </div>
                        <p class="text-neutral-white text-sm leading-relaxed">
                            Hai il pieno controllo sui tuoi contenuti. Puoi modificare o eliminare
                            i tuoi audio in qualsiasi momento dal tuo profilo.
                        </p>
                    </div>
                </div>
            </div>

            <!-- CERBERO - Enterprise Security -->
            <div class="bg-gradient-to-br from-brand-charcoal via-accent-purple/10 to-brand-charcoal backdrop-blur-lg rounded-2xl p-8 border-2 border-accent-purple/50 shadow-2xl shadow-accent-purple/20">
                <div class="flex flex-col md:flex-row items-center gap-6 mb-6">
                    <!-- Cerbero -->
                    <div class="relative flex items-center justify-center">
                        <img src="<?= asset('img/cerbero-small.png') ?>"
                             alt="Cerbero - Il Guardiano di need2talk"
                             class="w-20 h-auto drop-shadow-2xl"
                             loading="lazy">
                        <div class="absolute inset-0 bg-accent-purple/20 blur-2xl rounded-full -z-10"></div>
                    </div>
                    <div class="text-center md:text-left">
                        <h2 class="text-3xl font-bold text-accent-purple mb-1">CERBERO</h2>
                        <p class="text-accent-lavender">Il Guardiano della Piattaforma</p>
                        <div class="flex items-center justify-center md:justify-start gap-2 mt-2">
                            <span class="bg-green-500/20 border border-green-500/50 text-green-400 px-3 py-1 rounded-full text-sm font-bold">
                                Voto A - Security Headers
                            </span>
                        </div>
                    </div>
                </div>

                <p class="text-neutral-silver text-center md:text-left mb-6">
                    Come il <strong class="text-accent-purple">cane a tre teste</strong> della mitologia greca,
                    <strong>Cerbero</strong> protegge need2talk da ogni minaccia esterna 24 ore su 24.
                </p>

                <!-- Le Tre Teste -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-accent-purple/15 rounded-xl p-5 border border-accent-purple/40 text-center">
                        <div class="text-4xl mb-2">🛡️</div>
                        <h3 class="font-bold text-accent-purple mb-1">WAF</h3>
                        <p class="text-neutral-silver text-xs">Blocca SQL Injection, XSS e ogni attacco web</p>
                    </div>
                    <div class="bg-cool-cyan/15 rounded-xl p-5 border border-cool-cyan/40 text-center">
                        <div class="text-4xl mb-2">🔥</div>
                        <h3 class="font-bold text-cool-cyan mb-1">Firewall</h3>
                        <p class="text-neutral-silver text-xs">Rate limiting e protezione DDoS automatica</p>
                    </div>
                    <div class="bg-energy-pink/15 rounded-xl p-5 border border-energy-pink/40 text-center">
                        <div class="text-4xl mb-2">🤖</div>
                        <h3 class="font-bold text-energy-pink mb-1">Anti-Scan Bot</h3>
                        <p class="text-neutral-silver text-xs">Rileva e blocca scanner e bot malevoli</p>
                    </div>
                </div>

                <!-- Garanzie Sicurezza -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="bg-brand-midnight/60 rounded-lg p-3 text-center border border-accent-purple/20">
                        <?= icon('key', 'w-6 h-6 text-accent-purple mx-auto mb-1') ?>
                        <p class="text-xs text-neutral-silver">Password Argon2id</p>
                    </div>
                    <div class="bg-brand-midnight/60 rounded-lg p-3 text-center border border-accent-purple/20">
                        <?= icon('lock', 'w-6 h-6 text-cool-cyan mx-auto mb-1') ?>
                        <p class="text-xs text-neutral-silver">Zero Dati in Chiaro</p>
                    </div>
                    <div class="bg-brand-midnight/60 rounded-lg p-3 text-center border border-accent-purple/20">
                        <?= icon('headphones', 'w-6 h-6 text-energy-pink mx-auto mb-1') ?>
                        <p class="text-xs text-neutral-silver">Audio Non Scaricabili</p>
                    </div>
                    <div class="bg-brand-midnight/60 rounded-lg p-3 text-center border border-accent-purple/20">
                        <?= icon('shield', 'w-6 h-6 text-green-400 mx-auto mb-1') ?>
                        <p class="text-xs text-neutral-silver">TLS 1.3 Encryption</p>
                    </div>
                </div>
            </div>

            <!-- How to Report -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <h2 class="text-3xl font-bold text-accent-purple mb-6 flex items-center">
                    <?= icon('exclamation-triangle', 'w-5 h-5 mr-3') ?>
                    Come Segnalare un Problema
                </h2>

                <div class="space-y-6 text-neutral-white">
                    <p class="leading-relaxed">
                        Se noti contenuti inappropriati, comportamenti sospetti o violazioni delle regole,
                        puoi segnalare il problema in diversi modi:
                    </p>

                    <div class="bg-brand-midnight/50 rounded-xl p-6 border border-accent-purple/20">
                        <h3 class="font-semibold text-white mb-4 flex items-center">
                            <span class="w-8 h-8 bg-accent-purple text-white rounded-full flex items-center justify-center text-sm mr-3">1</span>
                            Segnala direttamente il contenuto
                        </h3>
                        <p class="text-sm ml-11">
                            Ogni audio ha un pulsante "Segnala". Cliccalo, seleziona il motivo della segnalazione
                            e aggiungi eventuali dettagli. La segnalazione è anonima e verrà esaminata dal nostro team.
                        </p>
                    </div>

                    <div class="bg-brand-midnight/50 rounded-xl p-6 border border-accent-purple/20">
                        <h3 class="font-semibold text-white mb-4 flex items-center">
                            <span class="w-8 h-8 bg-accent-purple text-white rounded-full flex items-center justify-center text-sm mr-3">2</span>
                            Usa il modulo di segnalazione
                        </h3>
                        <p class="text-sm ml-11 mb-3">
                            Per problemi più complessi o segnalazioni dettagliate, usa il modulo dedicato:
                        </p>
                        <div class="ml-11">
                            <a href="<?= url('legal/report') ?>"
                               class="inline-flex items-center px-6 py-3 bg-accent-violet hover:bg-accent-violet/90 text-white font-medium rounded-lg transition-all duration-200">
                                <?= icon('file-alt', 'w-5 h-5 mr-2') ?>
                                Modulo di Segnalazione
                            </a>
                        </div>
                    </div>

                    <div class="bg-brand-midnight/50 rounded-xl p-6 border border-accent-purple/20">
                        <h3 class="font-semibold text-white mb-4 flex items-center">
                            <span class="w-8 h-8 bg-accent-purple text-white rounded-full flex items-center justify-center text-sm mr-3">3</span>
                            Contatta il supporto
                        </h3>
                        <p class="text-sm ml-11 mb-3">
                            Per emergenze o questioni urgenti, contattaci direttamente:
                        </p>
                        <div class="ml-11">
                            <a href="<?= url('legal/contacts') ?>"
                               class="inline-flex items-center px-6 py-3 bg-energy-pink hover:bg-energy-pink/90 text-white font-medium rounded-lg transition-all duration-200">
                                <?= icon('envelope', 'w-5 h-5 mr-2') ?>
                                Contatti
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Consequences -->
            <div class="bg-gradient-to-r from-orange-600/20 to-red-600/20 backdrop-blur-lg rounded-2xl p-8 border border-orange-500/30 shadow-2xl shadow-orange-500/10">
                <h2 class="text-2xl font-bold text-white mb-4 flex items-center">
                    <?= icon('gavel', 'w-5 h-5 mr-3 text-orange-400') ?>
                    Conseguenze delle Violazioni
                </h2>

                <div class="space-y-3 text-accent-violet">
                    <p class="leading-relaxed">
                        Le violazioni delle regole della community possono comportare le seguenti sanzioni:
                    </p>

                    <ul class="space-y-2 ml-6">
                        <li class="flex items-start gap-2">
                            <?= icon('exclamation-circle', 'w-5 h-5 text-yellow-400 mt-1') ?>
                            <span><strong>Primo avviso:</strong> Notifica e rimozione del contenuto inappropriato</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <?= icon('pause-circle', 'w-5 h-5 text-orange-400 mt-1') ?>
                            <span><strong>Sospensione temporanea:</strong> Blocco dell'account per un periodo limitato (da 7 a 30 giorni)</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <?= icon('ban', 'w-5 h-5 text-energy-magenta mt-1') ?>
                            <span><strong>Ban permanente:</strong> Cancellazione definitiva dell'account per violazioni gravi o ripetute</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <?= icon('balance-scale', 'w-5 h-5 text-accent-purple mt-1') ?>
                            <span><strong>Azioni legali:</strong> Segnalazione alle autorità competenti per attività illegali</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Privacy Reminder -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('user-secret', 'w-5 h-5 mr-3') ?>
                    Privacy e Protezione Dati
                </h2>

                <div class="space-y-4 text-neutral-white">
                    <p class="leading-relaxed">
                        La tua privacy è sacra. Tutti i tuoi dati sono protetti da <strong class="text-accent-purple">Cerbero</strong>
                        e conformi alle normative GDPR europee.
                    </p>

                    <div class="grid md:grid-cols-2 gap-3">
                        <div class="flex items-start gap-3 bg-brand-midnight/40 rounded-lg p-3">
                            <?= icon('check', 'w-5 h-5 text-green-400 mt-0.5') ?>
                            <p class="text-sm">I tuoi dati <strong>non vengono mai venduti</strong> a terze parti</p>
                        </div>
                        <div class="flex items-start gap-3 bg-brand-midnight/40 rounded-lg p-3">
                            <?= icon('check', 'w-5 h-5 text-green-400 mt-0.5') ?>
                            <p class="text-sm">Crittografia <strong>TLS 1.3</strong> su tutte le connessioni</p>
                        </div>
                        <div class="flex items-start gap-3 bg-brand-midnight/40 rounded-lg p-3">
                            <?= icon('check', 'w-5 h-5 text-green-400 mt-0.5') ?>
                            <p class="text-sm">Password protette con <strong>Argon2id</strong> (standard bancario)</p>
                        </div>
                        <div class="flex items-start gap-3 bg-brand-midnight/40 rounded-lg p-3">
                            <?= icon('check', 'w-5 h-5 text-green-400 mt-0.5') ?>
                            <p class="text-sm"><strong>Zero dati in chiaro</strong> - tutto è crittografato</p>
                        </div>
                        <div class="flex items-start gap-3 bg-brand-midnight/40 rounded-lg p-3">
                            <?= icon('check', 'w-5 h-5 text-green-400 mt-0.5') ?>
                            <p class="text-sm">Audio post <strong>inaccessibili</strong> e non scaricabili</p>
                        </div>
                        <div class="flex items-start gap-3 bg-brand-midnight/40 rounded-lg p-3">
                            <?= icon('check', 'w-5 h-5 text-green-400 mt-0.5') ?>
                            <p class="text-sm"><strong>Cancellazione completa</strong> dati su richiesta</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-gray-700">
                        <a href="<?= url('legal/privacy') ?>"
                           class="text-accent-purple hover:text-accent-purple/80 transition-colors inline-flex items-center">
                            <?= icon('document', 'w-5 h-5 mr-2') ?>
                            Leggi la Privacy Policy completa
                        </a>
                    </div>
                </div>
            </div>

        </div>

        <!-- Contact CTA -->
        <div class="mt-16 text-center">
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <h2 class="text-2xl font-bold text-white mb-4">Hai Dubbi sulla Sicurezza?</h2>
                <p class="text-neutral-white mb-6">
                    Siamo qui per aiutarti. Non esitare a contattarci per qualsiasi domanda o preoccupazione.
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                    <a href="<?= url('legal/contacts') ?>"
                       class="px-8 py-4 bg-energy-pink hover:bg-energy-pink/80 text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-xl shadow-energy-pink/25 group">
                        <?= icon('envelope', 'w-5 h-5 mr-2 group-hover:animate-bounce') ?>
                        Contattaci
                    </a>

                    <a href="<?= url('help/faq') ?>"
                       class="px-8 py-4 border-2 border-accent-violet text-accent-violet hover:bg-accent-violet hover:text-white font-medium rounded-xl transition-all duration-300 group">
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
