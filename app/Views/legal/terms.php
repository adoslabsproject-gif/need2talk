<?php
/**
 * NEED2TALK - TERMS OF SERVICE PAGE (REFACTORED ENTERPRISE GALAXY LEVEL)
 *
 * ARCHITETTURA REFACTORED:
 * - Usa layout guest.php unificato
 * - SOLO contenuto della pagina (ZERO duplicazione HTML)
 * - Mantiene TUTTE le variabili e dati originali
 * - Performance: OPcache compile 1 layout invece di 337 righe
 * - Cookie banner: funziona automaticamente dal layout
 * - Enterprise monitoring: automatico dal layout
 *
 * PRIMA: 337 righe standalone con duplicazione
 * DOPO: Solo content + variables (layout injection)
 */

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// 📅 LEGAL: Data ultima modifica (CRITICA per GDPR compliance)
$lastUpdated = '10 Settembre 2024';
$effectiveDate = '10 Settembre 2024';

// Page metadata for layout
$title = 'Termini di Servizio - need2talk';
$description = 'Termini di Servizio completi per need2talk. Condizioni d\'uso, diritti utenti, GDPR compliance. Piattaforma sicura per condivisione audio emotivo.';

// ENTERPRISE: Start output buffering for content injection
ob_start();
?>

<!-- Background Animato -->
<div class="fixed inset-0 bg-gradient-to-br from-brand-midnight via-brand-slate to-brand-midnight pointer-events-none">
    <div class="absolute inset-0 bg-gradient-to-t from-brand-midnight/80 via-accent-violet/5 to-transparent"></div>

    <!-- Aurora effect overlay -->
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-accent-violet/30 rounded-full blur-3xl animate-pulse"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-cool-cyan/20 rounded-full blur-3xl animate-pulse" style="animation-delay: 1s;"></div>
    </div>
</div>

<!-- Main Content -->
<div class="relative pt-20">
    <div class="max-w-4xl mx-auto px-4 py-12">

        <!-- Header -->
        <div class="text-center mb-12">
            <!-- Logo -->
            <div class="flex justify-center mb-6">
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

            <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                Termini di Servizio
            </h1>
            <p class="text-gray-300 text-lg mb-6">
                Condizioni d'uso della piattaforma need2talk
            </p>

            <!-- Document Info -->
            <div class="inline-flex items-center bg-accent-purple/10 border border-accent-purple/30 rounded-full px-6 py-3">
                <?= icon('calendar', 'w-5 h-5 text-accent-purple mr-2') ?>
                <span class="text-accent-purple">
                    Ultima modifica: <strong><?= $lastUpdated ?></strong>
                </span>
            </div>
        </div>

        <!-- Content -->
        <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">

            <!-- 1. Accettazione -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('handshake', 'w-5 h-5 text-accent-purple mr-3') ?>
                    1. Accettazione dei Termini
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>
                        Utilizzando need2talk, accetti integralmente questi Termini di Servizio e la nostra
                        <a href="<?= url('legal/privacy') ?>" class="text-accent-purple hover:text-accent-purple/80 underline">Informativa Privacy</a>.
                    </p>
                    <p>
                        Se non accetti questi termini, non puoi utilizzare il servizio.
                    </p>
                </div>
            </section>

            <!-- 2. Descrizione Servizio -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('microphone', 'w-5 h-5 text-accent-purple mr-3') ?>
                    2. Descrizione del Servizio
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>
                        need2talk è una piattaforma italiana per condividere emozioni attraverso messaggi vocali anonimi.
                    </p>
                    <div class="bg-accent-purple/10 border border-accent-purple/30 rounded-lg p-4">
                        <h3 class="font-semibold text-accent-purple mb-2">Funzionalità principali:</h3>
                        <ul class="list-disc list-inside space-y-1 text-sm">
                            <li>Registrazione e condivisione messaggi vocali</li>
                            <li>Ascolto anonimo di contenuti emotivi</li>
                            <li>Sistema di categorizzazione per emozioni</li>
                            <li>Community rispettosa e moderata</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- 3. Età Minima -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('exclamation-triangle', 'w-5 h-5 text-red-400 mr-3') ?>
                    3. Età Minima - Solo Maggiorenni
                </h2>
                <div class="bg-energy-pink/10 border border-energy-magenta/40 rounded-lg p-6">
                    <p class="text-energy-pink font-semibold mb-2">
                        <?= icon('ban', 'w-5 h-5 mr-2') ?>
                        IMPORTANTE: need2talk è riservato esclusivamente ai maggiorenni
                    </p>
                    <p class="text-energy-pink text-sm">
                        Devi avere almeno 18 anni per registrarti e utilizzare il servizio.
                        La verifica dell'età è obbligatoria e sarà controllata.
                    </p>
                </div>
            </section>

            <!-- 4. Contenuti e Comportamento -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('shield', 'w-5 h-5 text-accent-purple mr-3') ?>
                    4. Contenuti e Comportamento
                </h2>
                <div class="text-gray-300 space-y-4">
                    <h3 class="text-xl font-semibold text-accent-purple">Contenuti Vietati</h3>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div class="bg-energy-pink/10 border border-energy-pink/30 rounded-lg p-4">
                            <ul class="list-disc list-inside text-sm space-y-1">
                                <li>Contenuti illegali o dannosi</li>
                                <li>Incitamento all'odio o discriminazione</li>
                                <li>Contenuti violenti o minacciosi</li>
                                <li>Spam o contenuti commerciali non autorizzati</li>
                            </ul>
                        </div>
                        <div class="bg-energy-pink/10 border border-energy-pink/30 rounded-lg p-4">
                            <ul class="list-disc list-inside text-sm space-y-1">
                                <li>Violazione privacy di terzi</li>
                                <li>Contenuti falsi o ingannevoli</li>
                                <li>Molestie o bullismo</li>
                                <li>Contenuti che violano copyright</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 5. Privacy e Dati -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('user-shield', 'w-5 h-5 text-accent-purple mr-3') ?>
                    5. Privacy e Protezione Dati
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>
                        La tua privacy è fondamentale. Consulta la nostra
                        <a href="<?= url('legal/privacy') ?>" class="text-accent-purple hover:text-accent-purple/80 underline">Informativa Privacy</a>
                        per dettagli completi su come proteggiamo i tuoi dati.
                    </p>

                    <!-- CERBERO Security Badge -->
                    <div class="bg-gradient-to-r from-accent-purple/10 via-energy-pink/10 to-cool-cyan/10 border border-accent-purple/40 rounded-xl p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <!-- Cerbero -->
                            <img src="<?= asset('img/cerbero-xs.png') ?>"
                                 alt="Cerbero"
                                 class="w-10 h-auto"
                                 loading="lazy">
                            <div>
                                <span class="text-accent-purple font-bold">CERBERO</span>
                                <span class="text-gray-400 text-sm ml-2">Il Guardiano dei Tuoi Dati</span>
                            </div>
                            <span class="ml-auto bg-green-500/20 border border-green-500/50 text-green-400 px-2 py-1 rounded text-xs font-bold">
                                Voto A
                            </span>
                        </div>
                        <p class="text-gray-300 text-sm">
                            I tuoi dati sono protetti da <strong class="text-accent-purple">Cerbero</strong>, il nostro sistema di sicurezza enterprise a tre teste:
                            <strong class="text-accent-purple">WAF</strong>, <strong class="text-cool-cyan">Firewall</strong> e <strong class="text-energy-pink">Anti-Scan Bot</strong>.
                        </p>
                    </div>

                    <div class="bg-cool-cyan/10 border border-cool-cyan/30 rounded-lg p-4">
                        <p class="text-cool-cyan font-semibold mb-2">
                            <?= icon('check-shield', 'w-5 h-5 mr-2') ?>
                            Garanzie Privacy
                        </p>
                        <ul class="text-cool-ice text-sm list-disc list-inside space-y-1">
                            <li>Conformità GDPR completa</li>
                            <li>Password protette con Argon2id (standard bancario)</li>
                            <li>Zero dati in chiaro - tutto è crittografato</li>
                            <li>Audio post inaccessibili e non scaricabili</li>
                            <li>Controllo completo sui tuoi dati</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- 6. Sospensione e Terminazione -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('user-times', 'w-5 h-5 text-accent-purple mr-3') ?>
                    6. Sospensione e Terminazione
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>
                        Ci riserviamo il diritto di sospendere o terminare account che violano questi termini.
                    </p>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div class="bg-energy-magenta/10 border border-energy-magenta/30 rounded-lg p-4">
                            <h4 class="font-semibold text-energy-magenta mb-2">Sospensione Temporanea</h4>
                            <p class="text-energy-magenta text-sm">
                                Per violazioni minori, con possibilità di appello e reinstaurazione.
                            </p>
                        </div>
                        <div class="bg-energy-pink/10 border border-energy-pink/30 rounded-lg p-4">
                            <h4 class="font-semibold text-energy-pink mb-2">Terminazione Permanente</h4>
                            <p class="text-energy-pink text-sm">
                                Per violazioni gravi o ripetute, con cancellazione completa dati.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 7. Contatti -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('envelope', 'w-5 h-5 text-accent-purple mr-3') ?>
                    7. Contatti e Supporto
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>Per domande sui termini o supporto:</p>
                    <div class="bg-accent-purple/10 border border-accent-purple/30 rounded-lg p-4">
                        <div class="grid md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p><strong>Email:</strong> support@need2talk.it</p>
                                <p><strong>Telefono:</strong> 059/361164</p>
                            </div>
                            <div>
                                <p><strong>Orari:</strong> Lun-Ven 9:00-18:00</p>
                                <p><strong>Risposta:</strong> Entro 48 ore</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Footer Legale -->
            <footer class="border-t border-accent-purple/20 pt-6 mt-8">
                <div class="flex flex-col md:flex-row justify-between items-center text-sm">
                    <div class="text-accent-purple mb-4 md:mb-0">
                        <p>
                            <strong>Effettivo dal:</strong> <?= $effectiveDate ?><br>
                            <strong>Versione:</strong> 1.0
                        </p>
                    </div>
                    <div class="flex space-x-6">
                        <a href="<?= url('legal/privacy') ?>"
                           class="text-accent-purple hover:text-accent-purple/80 hover:underline">
                            Privacy Policy
                        </a>
                        <a href="<?= url('/') ?>"
                           class="text-accent-purple hover:text-accent-purple/80 hover:underline">
                            Torna a need2talk
                        </a>
                    </div>
                </div>
            </footer>
        </div>
    </div>
</div>

<?php
// ENTERPRISE: Capture content and inject into layout
$content = ob_get_clean();

// Load guest layout (handles ALL HTML structure, CSS, JS, monitoring)
require APP_ROOT . '/app/Views/layouts/guest.php';
?>
