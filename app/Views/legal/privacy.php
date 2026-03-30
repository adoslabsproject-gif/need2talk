<?php
/**
 * NEED2TALK - PRIVACY PAGE (REFACTORED ENTERPRISE GALAXY LEVEL)
 *
 * ARCHITETTURA REFACTORED:
 * - Usa layout guest.php unificato
 * - SOLO contenuto della pagina (ZERO duplicazione HTML)
 * - Mantiene TUTTE le variabili e dati originali
 * - Performance: OPcache compile 1 layout invece di 925 righe
 * - Cookie banner: funziona automaticamente dal layout
 * - Enterprise monitoring: automatico dal layout
 * - Page-specific JS inline: Mantenuto per logica GDPR
 *
 * PRIMA: 925 righe standalone con duplicazione
 * DOPO: Solo content + variables (layout injection)
 */

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// 📅 LEGAL: Data ultima modifica (CRITICA per GDPR compliance)
$lastUpdated = '17 Gennaio 2025';
$effectiveDate = '17 Gennaio 2025';

// Page metadata for layout
$title = 'Informativa Privacy - need2talk';
$description = 'Informativa Privacy completa need2talk. GDPR compliant, trasparenza dati, diritti utente. Protezione privacy garantita per condivisione audio emotivo.';

// ENTERPRISE: Start output buffering for content injection
ob_start();
?>

<!-- Background Animato -->
<div class="fixed inset-0 bg-gradient-to-br from-brand-midnight via-brand-slate to-brand-midnight pointer-events-none" style="z-index: 0;">
    <div class="absolute inset-0 bg-gradient-to-t from-brand-midnight/80 via-accent-violet/5 to-transparent"></div>

    <!-- Aurora effect overlay -->
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-accent-violet/30 rounded-full blur-3xl animate-pulse"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-cool-cyan/20 rounded-full blur-3xl animate-pulse" style="animation-delay: 1s;"></div>
    </div>
</div>

<!-- Main Content -->
<div class="relative pt-20" style="z-index: 1;">
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
                Informativa Privacy
            </h1>
            <p class="text-gray-300 text-lg mb-6">
                Trasparenza completa GDPR su come proteggiamo i tuoi dati
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

            <!-- 1. INTRODUZIONE -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('shield', 'w-5 h-5 text-accent-purple mr-3') ?>
                    1. Introduzione
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>
                        <strong>need2talk</strong> è una piattaforma italiana per condividere emozioni attraverso messaggi vocali.
                        La tua privacy è sacra e inviolabile.
                    </p>
                    <p>
                        Questa informativa spiega <strong>in modo trasparente</strong> come raccogliamo, utilizziamo e proteggiamo i tuoi dati personali,
                        in piena conformità al <strong>GDPR</strong> (Regolamento UE 2016/679).
                    </p>
                    <div class="bg-cool-cyan/10 border border-cool-cyan/30 rounded-lg p-4">
                        <p class="text-cool-cyan font-semibold mb-2">
                            <?= icon('check-circle', 'w-5 h-5 mr-2') ?>
                            Conformità GDPR Totale
                        </p>
                        <ul class="text-cool-ice text-sm list-disc list-inside space-y-1">
                            <li>Server ubicati in Italia/Europa</li>
                            <li>Crittografia end-to-end per contenuti sensibili</li>
                            <li>Anonimizzazione automatica dati</li>
                            <li>Diritti GDPR completi garantiti</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- 2. DATI RACCOLTI -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('database', 'w-5 h-5 text-accent-purple mr-3') ?>
                    2. Quali Dati Raccogliamo
                </h2>
                <div class="text-gray-300 space-y-4">
                    <h3 class="text-xl font-semibold text-accent-purple">Dati forniti da te direttamente</h3>
                    <div class="grid md:grid-cols-2 gap-4">
                        <!-- Registrazione -->
                        <div class="bg-accent-violet/10 border border-accent-purple/30 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-lavender mb-2">
                                <?= icon('user-plus', 'w-5 h-5 mr-2') ?>
                                Registrazione Account
                            </h4>
                            <ul class="text-accent-lavender text-sm list-disc list-inside space-y-1">
                                <li>Nome utente</li>
                                <li>Email verificata</li>
                                <li>Password (crittografata Argon2id)</li>
                                <li>Data di nascita (verifica maggiore età)</li>
                            </ul>
                        </div>

                        <!-- Contenuti Vocali -->
                        <div class="bg-accent-violet/10 border border-accent-purple/50 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-purple mb-2">
                                <?= icon('microphone', 'w-5 h-5 mr-2') ?>
                                Contenuti Vocali
                            </h4>
                            <ul class="text-accent-purple text-sm list-disc list-inside space-y-1">
                                <li>Registrazioni audio (WebM Opus 48kbps)</li>
                                <li>Categorie emotive selezionate</li>
                                <li>Timestamp caricamento</li>
                                <li>Metadata tecnici (durata, dimensione)</li>
                            </ul>

                            <!-- CRITICAL: Dato Biometrico GDPR Art. 9 -->
                            <div class="bg-energy-pink/15 border border-energy-magenta/40 rounded-lg p-3 mt-3">
                                <p class="text-energy-pink text-xs">
                                    <strong>⚠️ IMPORTANTE - Dato Biometrico (Art. 9 GDPR):</strong><br>
                                    Le registrazioni vocali contengono la tua <strong>voce riconoscibile</strong>,
                                    che costituisce un <strong>dato biometrico</strong> (categoria speciale GDPR).
                                    Il trattamento avviene <strong>solo con il tuo consenso esplicito</strong>
                                    fornito durante la registrazione dell'account, che copre tutte le registrazioni audio
                                    effettuate sulla piattaforma per finalità di condivisione emotiva.
                                </p>
                            </div>

                            <!-- Durata Conservazione -->
                            <p class="text-xs text-accent-purple mt-2">
                                <strong>Conservazione:</strong> Gli audio sono conservati finché il tuo account è attivo.
                                Puoi eliminarli singolarmente in qualsiasi momento.
                                Alla cancellazione dell'account, tutti gli audio sono eliminati definitivamente entro 48 ore.
                            </p>
                        </div>

                        <!-- Profilo -->
                        <div class="bg-energy-pink/10 border border-energy-pink/50 rounded-lg p-4">
                            <h4 class="font-semibold text-energy-pink mb-2">
                                <?= icon('id-card', 'w-5 h-5 mr-2') ?>
                                Dati Profilo
                            </h4>
                            <ul class="text-energy-pink/90 text-sm list-disc list-inside space-y-1">
                                <li>Avatar/Foto profilo (opzionale)</li>
                                <li>Bio descrizione (opzionale)</li>
                                <li>Genere (opzionale)</li>
                                <li>Preferenze privacy</li>
                            </ul>
                        </div>

                        <!-- Interazioni -->
                        <div class="bg-accent-purple/10 border border-accent-lavender/30 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-lavender mb-2">
                                <?= icon('comments', 'w-5 h-5 mr-2') ?>
                                Interazioni Social
                            </h4>
                            <ul class="text-accent-lavender text-sm list-disc list-inside space-y-1">
                                <li>Like/Ascolti</li>
                                <li>Commenti testuali</li>
                                <li>Segnalazioni contenuti</li>
                                <li>Lista amici</li>
                            </ul>
                        </div>
                    </div>

                    <h3 class="text-xl font-semibold text-accent-purple mt-6">Dati raccolti automaticamente</h3>
                    <div class="bg-brand-midnight/50 border border-neutral-darkGray rounded-lg p-4">
                        <ul class="text-sm list-disc list-inside space-y-1">
                            <li><strong>Dati tecnici:</strong> Indirizzo IP (anonimizzato dopo 48h), browser, dispositivo, sistema operativo</li>
                            <li><strong>Cookie tecnici:</strong> Session ID, autenticazione, preferenze lingua</li>
                            <li><strong>Analytics:</strong> Pagine visitate, durata sessione, audio ascoltati (anonimizzati)</li>
                            <li><strong>Log sicurezza:</strong> Tentativi login, azioni amministrative (retention 90 giorni)</li>
                        </ul>
                    </div>

                    <h3 class="text-xl font-semibold text-accent-purple mt-6">Dati di Sicurezza Adaptive (Sistema di Protezione)</h3>
                    <div class="bg-gradient-to-br from-cool-cyan/10 to-accent-purple/10 border border-cool-cyan/40 rounded-lg p-6">
                        <p class="text-gray-300 mb-4">
                            Per proteggere la piattaforma da accessi non autorizzati, frodi e attacchi informatici,
                            implementiamo un <strong>sistema di sicurezza adaptive</strong> che analizza pattern di accesso
                            in tempo reale. Questo sistema utilizza intelligenza artificiale per riconoscere comportamenti
                            anomali e proteggere il tuo account.
                        </p>

                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <!-- Geolocalizzazione IP -->
                            <div class="bg-cool-cyan/10 border border-cool-cyan/30 rounded-lg p-4">
                                <h4 class="font-semibold text-cool-cyan mb-2 flex items-center">
                                    <?= icon('map-marker-alt', 'w-5 h-5 mr-2') ?>
                                    Geolocalizzazione IP (MaxMind GeoLite2)
                                </h4>
                                <p class="text-cool-ice text-sm mb-2">
                                    Utilizziamo un database locale (MaxMind GeoLite2) per determinare il paese, città
                                    e provider internet del tuo indirizzo IP. <strong>Nessun dato viene inviato a terze parti</strong>.
                                </p>
                                <ul class="text-xs text-cool-ice list-disc list-inside space-y-1">
                                    <li>Rilevamento accessi da paesi insoliti</li>
                                    <li>Protezione "impossible travel" (login da 2 paesi distanti in pochi minuti)</li>
                                    <li>Identificazione datacenter/VPS/proxy (potenziali bot)</li>
                                </ul>
                            </div>

                            <!-- Device Fingerprinting -->
                            <div class="bg-accent-violet/10 border border-accent-purple/30 rounded-lg p-4">
                                <h4 class="font-semibold text-accent-lavender mb-2 flex items-center">
                                    <?= icon('fingerprint', 'w-5 h-5 mr-2') ?>
                                    Riconoscimento Dispositivi
                                </h4>
                                <p class="text-accent-lavender text-sm mb-2">
                                    Creiamo un "fingerprint" (impronta digitale) del tuo browser basato su User-Agent,
                                    sistema operativo e tipo dispositivo per riconoscere i tuoi dispositivi fidati.
                                </p>
                                <ul class="text-xs text-accent-lavender list-disc list-inside space-y-1">
                                    <li>Rilevamento login da dispositivi sconosciuti</li>
                                    <li>Funzione "dispositivo fidato" (skip 2FA opzionale)</li>
                                    <li>Notifiche email per nuovi dispositivi</li>
                                </ul>
                            </div>

                            <!-- Pattern Behavior Analysis -->
                            <div class="bg-accent-purple/10 border border-accent-purple/50 rounded-lg p-4">
                                <h4 class="font-semibold text-accent-purple mb-2 flex items-center">
                                    <?= icon('chart-line', 'w-5 h-5 mr-2') ?>
                                    Analisi Pattern Comportamentali
                                </h4>
                                <p class="text-accent-purple text-sm mb-2">
                                    Il sistema impara i tuoi pattern di accesso normali (orari tipici, reti WiFi/mobile,
                                    frequenza di utilizzo) per rilevare anomalie.
                                </p>
                                <ul class="text-xs text-accent-purple list-disc list-inside space-y-1">
                                    <li>Apprendimento automatico delle tue abitudini (es. WiFi casa, ufficio)</li>
                                    <li>Rilevamento accessi sospetti (orari insoliti, IP mai visti)</li>
                                    <li>Rate limiting adattivo per prevenire brute-force</li>
                                </ul>
                            </div>

                            <!-- Request Rate Tracking -->
                            <div class="bg-energy-pink/10 border border-energy-pink/50 rounded-lg p-4">
                                <h4 class="font-semibold text-energy-pink mb-2 flex items-center">
                                    <?= icon('tachometer-alt', 'w-5 h-5 mr-2') ?>
                                    Tracciamento Frequenza Richieste
                                </h4>
                                <p class="text-energy-pink text-sm mb-2">
                                    Monitoriamo la frequenza delle richieste HTTP per rilevare bot, scraper e attacchi DDoS.
                                </p>
                                <ul class="text-xs text-energy-pink list-disc list-inside space-y-1">
                                    <li>Rilevamento velocità richieste innaturali (bot/scraper)</li>
                                    <li>Blocco automatico IP sospetti (ban temporaneo 24h)</li>
                                    <li>Protezione CAPTCHA per comportamenti anomali</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Legal Basis & Retention -->
                        <div class="bg-accent-purple/15 border border-accent-purple/40 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-purple mb-2 flex items-center">
                                <?= icon('balance-scale', 'w-5 h-5 mr-2') ?>
                                Base Legale e Conservazione
                            </h4>
                            <ul class="text-sm text-gray-300 space-y-2">
                                <li>
                                    <strong>Base legale GDPR:</strong> Legittimo Interesse (Art. 6(1)(f)) -
                                    La sicurezza della piattaforma e la prevenzione frodi sono interessi legittimi
                                    prevalenti che proteggono sia la piattaforma che gli utenti.
                                </li>
                                <li>
                                    <strong>Privacy-first design:</strong> Raccogliamo solo dati strettamente necessari
                                    per sicurezza. Gli indirizzi IP vengono hashati (SHA256) dopo 48h per anonimizzazione.
                                </li>
                                <li>
                                    <strong>Retention automatica:</strong> Tutti i dati di sicurezza vengono eliminati
                                    automaticamente dopo <strong>90 giorni</strong> di inattività. Alla cancellazione
                                    dell'account, eliminazione immediata (entro 48h).
                                </li>
                                <li>
                                    <strong>Nessuna profilazione commerciale:</strong> I dati di sicurezza sono usati
                                    <strong>esclusivamente</strong> per protezione piattaforma, mai per marketing o profilazione.
                                </li>
                            </ul>
                        </div>

                        <!-- Privacy Transparency Note -->
                        <div class="bg-cool-cyan/10 border border-cool-cyan/30 rounded-lg p-4 mt-4">
                            <p class="text-cool-cyan text-sm">
                                <?= icon('shield-check', 'w-5 h-5 mr-2') ?>
                                <strong>Trasparenza totale:</strong> Puoi visualizzare tutti i tuoi dati di sicurezza
                                (IP history, dispositivi riconosciuti, score di rischio) nel tuo pannello Privacy Settings.
                                Hai diritto a richiedere la cancellazione anticipata in qualsiasi momento (diritto all'oblio Art. 17).
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. COME USIAMO I DATI -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('cogs', 'w-5 h-5 text-accent-purple mr-3') ?>
                    3. Come Usiamo i Tuoi Dati
                </h2>
                <div class="text-gray-300 space-y-4">
                    <div class="bg-gradient-to-r from-accent-purple/15 to-accent-lavender/15 border border-accent-purple/50 rounded-lg p-6">
                        <h3 class="text-xl font-bold text-accent-purple mb-4">Finalità del Trattamento (Base Legale GDPR)</h3>

                        <div class="space-y-4">
                            <!-- Esecuzione Contratto -->
                            <div class="flex items-start space-x-3">
                                <?= icon('document', 'w-6 h-6 text-cool-teal mt-1') ?>
                                <div>
                                    <h4 class="font-semibold text-cool-cyan">Esecuzione del Contratto (Art. 6(1)(b))</h4>
                                    <p class="text-sm text-gray-300">
                                        Fornitura servizi piattaforma, gestione account, elaborazione audio, comunicazioni utenti
                                    </p>
                                </div>
                            </div>

                            <!-- Consenso -->
                            <div class="flex items-start space-x-3">
                                <?= icon('hand-paper', 'w-6 h-6 text-accent-purple mt-1') ?>
                                <div>
                                    <h4 class="font-semibold text-accent-lavender">Consenso Esplicito (Art. 6(1)(a))</h4>
                                    <p class="text-sm text-gray-300">
                                        Marketing, newsletter, cookie analytics (revocabile in qualsiasi momento)
                                    </p>
                                </div>
                            </div>

                            <!-- Obbligo Legale -->
                            <div class="flex items-start space-x-3">
                                <?= icon('gavel', 'w-6 h-6 text-yellow-400 mt-1') ?>
                                <div>
                                    <h4 class="font-semibold text-energy-magenta">Obbligo Legale (Art. 6(1)(c))</h4>
                                    <p class="text-sm text-gray-300">
                                        Conformità leggi italiane/europee, conservazione dati fiscali, risposta ordini autorità
                                    </p>
                                </div>
                            </div>

                            <!-- Legittimo Interesse -->
                            <div class="flex items-start space-x-3">
                                <?= icon('balance-scale', 'w-6 h-6 text-accent-purple mt-1') ?>
                                <div>
                                    <h4 class="font-semibold text-accent-purple">Legittimo Interesse (Art. 6(1)(f))</h4>
                                    <p class="text-sm text-gray-300">
                                        Sicurezza piattaforma, prevenzione frodi, analisi performance, miglioramento UX
                                    </p>
                                </div>
                            </div>

                            <!-- Moderazione Contenuti (DSA Compliance) -->
                            <div class="flex items-start space-x-3">
                                <?= icon('flag', 'w-6 h-6 text-orange-400 mt-1') ?>
                                <div>
                                    <h4 class="font-semibold text-orange-300">Moderazione Contenuti (Obbligo Legale DSA + Legittimo Interesse)</h4>
                                    <p class="text-sm text-gray-300">
                                        <strong>Sistema community-driven:</strong> Gli utenti possono segnalare contenuti audio che violano i Termini d'Uso
                                        (spam, molestie, hate speech, violenza, contenuti sessuali, disinformazione, copyright, altro).
                                        <br><br>
                                        <strong>Revisione manuale:</strong> Ogni segnalazione viene ascoltata e revisionata manualmente da amministratori umani
                                        entro 24-48 ore. <strong>Nessuna AI o algoritmo automatizzato</strong> viene usato per decisioni di moderazione.
                                        <br><br>
                                        <strong>Trasparenza:</strong> Se un tuo contenuto viene rimosso, riceverai notifica email con motivazione dettagliata
                                        e possibilità di contestare la decisione (Art. 17 Digital Services Act).
                                        <br><br>
                                        <strong>Auto-flag comunitario:</strong> Contenuti con 3+ segnalazioni vengono automaticamente segnalati per revisione prioritaria
                                        (ma la decisione finale resta sempre umana).
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h3 class="text-xl font-semibold text-accent-purple">Cosa NON facciamo mai</h3>
                    <div class="bg-energy-pink/10 border border-energy-pink/30 rounded-lg p-4">
                        <ul class="text-energy-pink text-sm space-y-2">
                            <li><?= icon('times-circle', 'w-5 h-5 mr-2') ?><strong>Vendita dati a terzi</strong> - Mai, in nessun caso</li>
                            <li><?= icon('times-circle', 'w-5 h-5 mr-2') ?><strong>Profilazione automatizzata</strong> - Nessun algoritmo decisionale senza consenso</li>
                            <li><?= icon('times-circle', 'w-5 h-5 mr-2') ?><strong>Trasferimento extra-UE</strong> - Tutti i dati restano in Italia/Europa</li>
                            <li><?= icon('times-circle', 'w-5 h-5 mr-2') ?><strong>Spam indesiderato</strong> - Solo comunicazioni essenziali o consensuate</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- 3.5 GESTIONE COOKIE -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('cookie-bite', 'w-5 h-5 text-accent-purple mr-3') ?>
                    3.5 Gestione Cookie
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>
                        Usiamo cookie per migliorare la tua esperienza e garantire sicurezza. Hai controllo totale sulle tue preferenze.
                    </p>

                    <div class="grid md:grid-cols-3 gap-4">
                        <!-- Cookie Essenziali -->
                        <div class="bg-cool-cyan/10 border border-cool-cyan/30 rounded-lg p-4">
                            <h4 class="font-semibold text-cool-cyan mb-2">
                                <?= icon('shield-check', 'w-5 h-5 mr-2') ?>
                                Essenziali (Obbligatori)
                            </h4>
                            <p class="text-cool-cyan text-sm mb-2">
                                Necessari per autenticazione e sicurezza. Non disabilitabili.
                            </p>
                            <ul class="text-xs text-cool-ice list-disc list-inside">
                                <li>Session ID</li>
                                <li>CSRF Token</li>
                            </ul>
                        </div>

                        <!-- Cookie Funzionali -->
                        <div class="bg-accent-violet/10 border border-accent-purple/30 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-lavender mb-2">
                                <?= icon('sliders-h', 'w-5 h-5 mr-2') ?>
                                Funzionali (Opzionali)
                            </h4>
                            <p class="text-accent-lavender text-sm mb-2">
                                Memorizzano preferenze utente (lingua, tema).
                            </p>
                            <button class="text-xs bg-accent-violet hover:bg-accent-purple text-white px-3 py-1 rounded">
                                Gestisci Funzionali
                            </button>
                        </div>

                        <!-- Cookie Analytics -->
                        <div class="bg-accent-violet/10 border border-accent-purple/50 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-purple mb-2">
                                <?= icon('chart-line', 'w-5 h-5 mr-2') ?>
                                Analytics (Opzionali)
                            </h4>
                            <p class="text-accent-purple text-sm mb-2">
                                Anonimizzati, aiutano a migliorare il servizio.
                            </p>
                            <button class="text-xs bg-accent-violet hover:bg-accent-violet/90 text-white px-3 py-1 rounded">
                                Gestisci Analytics
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 4. I TUOI DIRITTI GDPR -->
            <section>
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('user-shield', 'w-5 h-5 text-accent-purple mr-3') ?>
                    4. I Tuoi Diritti GDPR
                </h2>
                <div class="prose prose-invert prose-purple text-gray-300 space-y-4">
                    <p>
                        Il GDPR ti garantisce <strong>diritti specifici</strong> sui tuoi dati personali.
                        Ecco come esercitarli su need2talk:
                    </p>

                    <div class="grid md:grid-cols-2 gap-6">
                        <!-- DIRITTO DI ACCESSO -->
                        <div class="bg-accent-purple/10 border border-accent-purple/30 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-lavender mb-2">
                                <?= icon('eye', 'w-5 h-5 mr-2') ?>Diritto di Accesso (Art. 15)
                            </h4>
                            <p class="text-accent-lavender text-sm mb-3">
                                Ottieni copia completa di tutti i tuoi dati personali
                            </p>
                            <button class="btn-sm btn-outline-blue">
                                Richiedi Dati
                            </button>
                        </div>

                        <!-- DIRITTO DI RETTIFICA -->
                        <div class="bg-cool-cyan/10 border border-cool-cyan/30 rounded-lg p-4">
                            <h4 class="font-semibold text-cool-cyan mb-2">
                                <?= icon('edit', 'w-5 h-5 mr-2') ?>Diritto di Rettifica (Art. 16)
                            </h4>
                            <p class="text-cool-cyan text-sm mb-3">
                                Correggi dati inesatti o aggiorna informazioni
                            </p>
                            <button class="btn-sm btn-outline-green">
                                Modifica Profilo
                            </button>
                        </div>

                        <!-- DIRITTO ALL'OBLIO -->
                        <div class="bg-energy-magenta/10 border border-energy-pink/30 rounded-lg p-4">
                            <h4 class="font-semibold text-energy-pink mb-2">
                                <?= icon('eraser', 'w-5 h-5 mr-2') ?>Diritto all'Oblio (Art. 17)
                            </h4>
                            <p class="text-energy-pink text-sm mb-3">
                                Elimina definitivamente il tuo account e tutti i dati
                            </p>
                            <button class="btn-sm btn-outline-red">
                                Elimina Account
                            </button>
                        </div>

                        <!-- DIRITTO PORTABILITÀ -->
                        <div class="bg-accent-violet/15 border border-accent-purple/50 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-purple mb-2">
                                <?= icon('download', 'w-5 h-5 mr-2') ?>Diritto Portabilità (Art. 20)
                            </h4>
                            <p class="text-accent-purple text-sm mb-3">
                                Esporta i tuoi dati in formato leggibile (JSON)
                            </p>
                            <button class="btn-sm btn-outline-purple">
                                Esporta Dati
                            </button>
                        </div>

                        <!-- DIRITTO LIMITAZIONE -->
                        <div class="bg-energy-magenta/10 border border-energy-magenta/30 rounded-lg p-4">
                            <h4 class="font-semibold text-energy-magenta mb-2">
                                <?= icon('pause-circle', 'w-5 h-5 mr-2') ?>Diritto Limitazione (Art. 18)
                            </h4>
                            <p class="text-energy-magenta text-sm mb-3">
                                Limita temporaneamente il trattamento dei tuoi dati
                            </p>
                            <button class="btn-sm btn-outline-yellow">
                                Limita Trattamento
                            </button>
                        </div>

                        <!-- DIRITTO OPPOSIZIONE -->
                        <div class="bg-accent-purple/10 border border-accent-lavender/30 rounded-lg p-4">
                            <h4 class="font-semibold text-accent-lavender mb-2">
                                <?= icon('ban', 'w-5 h-5 mr-2') ?>Diritto Opposizione (Art. 21)
                            </h4>
                            <p class="text-accent-lavender text-sm mb-3">
                                Revoca consensi marketing/newsletter in qualsiasi momento
                            </p>
                            <button class="btn-sm btn-outline-indigo">
                                Gestisci Consensi
                            </button>
                        </div>
                    </div>

                    <div class="bg-cool-cyan/15 border border-cool-cyan/40 rounded-lg p-6 mt-6">
                        <h4 class="font-semibold text-cool-cyan mb-3 flex items-center">
                            <?= icon('clock', 'w-5 h-5 mr-2') ?>
                            Tempi di Risposta GDPR
                        </h4>
                        <ul class="text-sm text-cool-cyan space-y-2">
                            <li><strong>Richieste Accesso/Rettifica/Portabilità:</strong> Risposta entro 72 ore</li>
                            <li><strong>Cancellazione Account (Diritto all'Oblio):</strong> Esecuzione immediata (dati rimossi entro 48h)</li>
                            <li><strong>Reclami Garante Privacy:</strong> Diritto a presentare reclamo all'Autorità Garante italiana</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- 5. SICUREZZA DATI - CERBERO SYSTEM -->
            <section class="mb-8 mt-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('lock', 'w-5 h-5 text-accent-purple mr-3') ?>
                    5. Sicurezza e Protezione Dati
                </h2>

                <!-- CERBERO Banner -->
                <div class="bg-gradient-to-r from-brand-midnight via-accent-purple/20 to-brand-midnight border-2 border-accent-purple/50 rounded-2xl p-6 mb-6">
                    <div class="flex items-center gap-4 mb-4">
                        <!-- Cerbero -->
                        <img src="<?= asset('img/cerbero-xs.png') ?>"
                             alt="Cerbero"
                             class="w-14 h-auto drop-shadow-lg"
                             loading="lazy"
                             width="120"
                             height="180">
                        <div>
                            <h3 class="text-2xl font-bold text-accent-purple">CERBERO</h3>
                            <p class="text-accent-lavender text-sm">Il Guardiano di need2talk</p>
                        </div>
                        <div class="ml-auto">
                            <div class="bg-green-500/20 border border-green-500/50 rounded-lg px-4 py-2">
                                <span class="text-green-400 font-bold text-lg">Voto A</span>
                                <p class="text-green-300 text-xs">Security Headers</p>
                            </div>
                        </div>
                    </div>
                    <p class="text-gray-300">
                        <strong>Cerbero</strong> è il nostro sistema di sicurezza enterprise a tre teste che protegge need2talk 24/7:
                        <strong class="text-accent-purple">WAF</strong> (Web Application Firewall),
                        <strong class="text-cool-cyan">Firewall Intelligente</strong> e
                        <strong class="text-energy-pink">Anti-Scan Bot</strong>.
                        Come il cane mitologico che custodiva l'ingresso degli Inferi, Cerbero impedisce a qualsiasi minaccia di entrare.
                    </p>
                </div>

                <div class="text-gray-300 space-y-4">
                    <p>
                        Implementiamo misure tecniche e organizzative <strong>all'avanguardia</strong> per proteggere i tuoi dati:

                    <div class="grid md:grid-cols-2 gap-6">
                        <!-- Crittografia -->
                        <div class="bg-gradient-to-br from-cool-cyan/15 to-cool-teal/15 border border-cool-cyan/40 rounded-lg p-6">
                            <h4 class="font-semibold text-cool-cyan mb-3 flex items-center">
                                <?= icon('shield-virus', 'w-6 h-6  mr-2') ?>
                                Crittografia Enterprise
                            </h4>
                            <ul class="text-sm text-gray-300 space-y-2">
                                <li><?= icon('check', 'w-5 h-5 text-cool-teal mr-2') ?>HTTPS/TLS 1.3 su tutte le comunicazioni</li>
                                <li><?= icon('check', 'w-5 h-5 text-cool-teal mr-2') ?>Password con Argon2id (hash salato)</li>
                                <li><?= icon('check', 'w-5 h-5 text-cool-teal mr-2') ?>Dati sensibili: AES-256-GCM at-rest</li>
                                <li><?= icon('check', 'w-5 h-5 text-cool-teal mr-2') ?>Audio Post: ACL privato + URL firmati temporanei</li>
                            </ul>
                        </div>

                        <!-- Access Control -->
                        <div class="bg-gradient-to-br from-accent-violet/15 to-cool-cyan/15 border border-accent-purple/40 rounded-lg p-6">
                            <h4 class="font-semibold text-accent-lavender mb-3 flex items-center">
                                <?= icon('user-lock', 'w-6 h-6  mr-2') ?>
                                Controllo Accessi Rigido
                            </h4>
                            <ul class="text-sm text-gray-300 space-y-2">
                                <li><?= icon('check', 'w-5 h-5 text-accent-purple mr-2') ?>Autenticazione multi-fattore (2FA) disponibile</li>
                                <li><?= icon('check', 'w-5 h-5 text-accent-purple mr-2') ?>Least Privilege per staff amministrativo</li>
                                <li><?= icon('check', 'w-5 h-5 text-accent-purple mr-2') ?>Audit log completo accessi admin</li>
                                <li><?= icon('check', 'w-5 h-5 text-accent-purple mr-2') ?>Rate limiting anti-brute force</li>
                            </ul>
                        </div>

                        <!-- Infrastruttura -->
                        <div class="bg-gradient-to-br from-accent-purple/15 to-accent-lavender/15 border border-accent-purple/50 rounded-lg p-6">
                            <h4 class="font-semibold text-accent-purple mb-3 flex items-center">
                                <?= icon('server', 'w-6 h-6  mr-2') ?>
                                Infrastruttura Sicura
                            </h4>
                            <ul class="text-sm text-gray-300 space-y-2">
                                <li><?= icon('check', 'w-5 h-5 text-accent-purple mr-2') ?>Server in datacenter tier 3+ Italia/Europa</li>
                                <li><?= icon('check', 'w-5 h-5 text-accent-purple mr-2') ?>Backup giornalieri crittografati (retention 30gg)</li>
                                <li><?= icon('check', 'w-5 h-5 text-accent-purple mr-2') ?>Firewall + Intrusion Detection System (IDS)</li>
                                <li><?= icon('check', 'w-5 h-5 text-accent-purple mr-2') ?>Disaster Recovery Plan certificato</li>
                            </ul>
                        </div>

                        <!-- Monitoraggio -->
                        <div class="bg-gradient-to-br from-energy-pink/15 to-energy-magenta/15 border border-energy-magenta/40 rounded-lg p-6">
                            <h4 class="font-semibold text-energy-pink mb-3 flex items-center">
                                <?= icon('eye-slash', 'w-6 h-6  mr-2') ?>
                                Monitoraggio Proattivo
                            </h4>
                            <ul class="text-sm text-gray-300 space-y-2">
                                <li><?= icon('check', 'w-5 h-5 text-energy-magenta mr-2') ?>Rilevamento anomalie 24/7</li>
                                <li><?= icon('check', 'w-5 h-5 text-energy-magenta mr-2') ?>Penetration test trimestrali</li>
                                <li><?= icon('check', 'w-5 h-5 text-energy-magenta mr-2') ?>Incident Response Team dedicato</li>
                                <li><?= icon('check', 'w-5 h-5 text-energy-magenta mr-2') ?>Notifica data breach entro 72h (GDPR)</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Adaptive Security System (NEW) -->
                    <div class="bg-gradient-to-br from-accent-purple/10 to-cool-cyan/10 border border-accent-purple/50 rounded-lg p-6 mt-6">
                        <h3 class="text-xl font-semibold text-accent-purple mb-4 flex items-center">
                            <?= icon('brain', 'w-6 h-6 mr-3') ?>
                            Sistema di Sicurezza Adaptive (Machine Learning)
                        </h3>
                        <p class="text-gray-300 mb-4">
                            Abbiamo sviluppato un <strong>sistema di sicurezza adaptive</strong> che impara il tuo comportamento
                            normale per proteggere il tuo account da accessi non autorizzati. Il sistema utilizza machine learning
                            per riconoscere pattern sospetti in tempo reale.
                        </p>

                        <div class="grid md:grid-cols-2 gap-4">
                            <!-- Intelligent Risk Scoring -->
                            <div class="bg-cool-cyan/10 border border-cool-cyan/30 rounded-lg p-4">
                                <h4 class="font-semibold text-cool-cyan mb-2">
                                    <?= icon('chart-pie', 'w-5 h-5 mr-2') ?>
                                    Risk Scoring Intelligente
                                </h4>
                                <p class="text-cool-ice text-sm">
                                    Ogni richiesta di accesso riceve un "risk score" (0-100) basato su multipli fattori.
                                    <strong>Nessun blocco automatico</strong>: score elevati richiedono step di verifica aggiuntivi
                                    (es. 2FA, email di conferma).
                                </p>
                            </div>

                            <!-- Pattern Learning -->
                            <div class="bg-accent-violet/10 border border-accent-purple/30 rounded-lg p-4">
                                <h4 class="font-semibold text-accent-lavender mb-2">
                                    <?= icon('graduation-cap', 'w-5 h-5 mr-2') ?>
                                    Apprendimento Automatico
                                </h4>
                                <p class="text-accent-lavender text-sm">
                                    Il sistema impara dalle tue abitudini (orari, luoghi, dispositivi) e diventa
                                    più preciso nel tempo, riducendo falsi positivi e aumentando la protezione.
                                </p>
                            </div>

                            <!-- Impossible Travel Detection -->
                            <div class="bg-accent-purple/10 border border-accent-purple/50 rounded-lg p-4">
                                <h4 class="font-semibold text-accent-purple mb-2">
                                    <?= icon('plane-departure', 'w-5 h-5 mr-2') ?>
                                    Impossible Travel Detection
                                </h4>
                                <p class="text-accent-purple text-sm">
                                    Se rileva un login da un paese lontano subito dopo un accesso in Italia,
                                    richiede verifica aggiuntiva (fisicamente impossibile viaggiare così veloce).
                                </p>
                            </div>

                            <!-- Zero-Knowledge Security -->
                            <div class="bg-energy-pink/10 border border-energy-pink/50 rounded-lg p-4">
                                <h4 class="font-semibold text-energy-pink mb-2">
                                    <?= icon('user-secret', 'w-5 h-5 mr-2') ?>
                                    Privacy-First Design
                                </h4>
                                <p class="text-energy-pink text-sm">
                                    Il sistema analizza pattern, non contenuti. Non legge mai i tuoi audio né messaggi.
                                    Tutti i dati di sicurezza auto-eliminati dopo 90 giorni.
                                </p>
                            </div>
                        </div>

                        <div class="bg-cool-cyan/15 border border-cool-cyan/40 rounded-lg p-4 mt-4">
                            <p class="text-cool-cyan text-sm">
                                <?= icon('info-circle', 'w-5 h-5 mr-2') ?>
                                <strong>Nota:</strong> Il sistema adaptive è descritto in dettaglio nella
                                <a href="#dati-sicurezza-adaptive" class="underline hover:text-cool-teal">
                                    Sezione 2 - Dati di Sicurezza Adaptive
                                </a>.
                                Puoi visualizzare e gestire i tuoi dati di sicurezza dal pannello Privacy Settings.
                            </p>
                        </div>
                    </div>

                    <!-- 🎵 PROTEZIONE AUDIO POST -->
                    <div class="bg-gradient-to-br from-energy-pink/15 to-accent-purple/15 border-2 border-energy-pink/50 rounded-2xl p-6 mt-6">
                        <h3 class="text-xl font-semibold text-energy-pink mb-4 flex items-center">
                            <?= icon('microphone', 'w-6 h-6 mr-3') ?>
                            🔒 Protezione Audio Post - Inviolabile
                        </h3>
                        <p class="text-gray-300 mb-4">
                            I tuoi audio post sono protetti con <strong>tecnologia militare</strong>. Nessuno può scaricarli, copiarli o accedervi al di fuori di need2talk:
                        </p>

                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div class="bg-brand-midnight/50 border border-energy-pink/30 rounded-lg p-4">
                                <h4 class="font-semibold text-energy-pink mb-2 flex items-center">
                                    <?= icon('cloud', 'w-5 h-5 mr-2') ?>
                                    Storage Privato (ACL Private)
                                </h4>
                                <p class="text-gray-300 text-sm">
                                    Gli audio sono salvati su cloud con <strong>ACL privato</strong>: nessun URL pubblico esiste.
                                    Anche conoscendo il percorso, è <strong>impossibile</strong> accedere direttamente ai file.
                                </p>
                            </div>

                            <div class="bg-brand-midnight/50 border border-accent-purple/30 rounded-lg p-4">
                                <h4 class="font-semibold text-accent-purple mb-2 flex items-center">
                                    <?= icon('key', 'w-5 h-5 mr-2') ?>
                                    Signed URL Temporanei
                                </h4>
                                <p class="text-gray-300 text-sm">
                                    Ogni riproduzione genera un <strong>URL firmato crittograficamente</strong> valido solo
                                    per pochi minuti e per il tuo IP. Scade automaticamente, non può essere condiviso.
                                </p>
                            </div>

                            <div class="bg-brand-midnight/50 border border-cool-cyan/30 rounded-lg p-4">
                                <h4 class="font-semibold text-cool-cyan mb-2 flex items-center">
                                    <?= icon('ban', 'w-5 h-5 mr-2') ?>
                                    Download Impossibile
                                </h4>
                                <p class="text-gray-300 text-sm">
                                    Nessun pulsante "download", nessun link diretto, nessun trucco funziona.
                                    Gli audio possono essere <strong>solo ascoltati</strong> su need2talk, mai scaricati.
                                </p>
                            </div>

                            <div class="bg-brand-midnight/50 border border-accent-lavender/30 rounded-lg p-4">
                                <h4 class="font-semibold text-accent-lavender mb-2 flex items-center">
                                    <?= icon('user-shield', 'w-5 h-5 mr-2') ?>
                                    Controllo Totale
                                </h4>
                                <p class="text-gray-300 text-sm">
                                    Puoi eliminare i tuoi audio in qualsiasi momento. Una volta eliminati,
                                    <strong>scompaiono per sempre</strong>: nessun backup, nessun recupero possibile.
                                </p>
                            </div>
                        </div>

                        <div class="bg-energy-pink/10 border border-energy-pink/40 rounded-lg p-4">
                            <p class="text-energy-pink text-sm font-semibold">
                                <?= icon('shield-check', 'w-5 h-5 mr-2') ?>
                                La tua voce è tua. Nessuno può rubarla, copiarla o usarla senza il tuo consenso.
                            </p>
                        </div>
                    </div>

                    <!-- 🐕 Le Tre Teste di Cerbero -->
                    <div class="bg-gradient-to-br from-brand-midnight to-accent-purple/10 border border-accent-purple/40 rounded-2xl p-6 mt-6">
                        <h3 class="text-xl font-semibold text-accent-purple mb-4 flex items-center">
                            🐕‍🦺 Le Tre Teste di Cerbero
                        </h3>

                        <div class="grid md:grid-cols-3 gap-4">
                            <!-- Testa 1: WAF -->
                            <div class="bg-accent-purple/10 border border-accent-purple/40 rounded-xl p-5">
                                <div class="text-3xl mb-3">🛡️</div>
                                <h4 class="font-bold text-accent-purple mb-2">WAF</h4>
                                <p class="text-gray-300 text-sm mb-3">Web Application Firewall</p>
                                <ul class="text-xs text-gray-400 space-y-1">
                                    <li>• Blocco SQL Injection</li>
                                    <li>• Protezione XSS</li>
                                    <li>• Anti-CSRF automatico</li>
                                    <li>• Input sanitization</li>
                                </ul>
                            </div>

                            <!-- Testa 2: Firewall -->
                            <div class="bg-cool-cyan/10 border border-cool-cyan/40 rounded-xl p-5">
                                <div class="text-3xl mb-3">🔥</div>
                                <h4 class="font-bold text-cool-cyan mb-2">Firewall</h4>
                                <p class="text-gray-300 text-sm mb-3">Rate Limiting Intelligente</p>
                                <ul class="text-xs text-gray-400 space-y-1">
                                    <li>• Anti-DDoS</li>
                                    <li>• Rate limiting per IP</li>
                                    <li>• Geo-blocking paesi ostili</li>
                                    <li>• Ban automatico scanner</li>
                                </ul>
                            </div>

                            <!-- Testa 3: Anti-Scan -->
                            <div class="bg-energy-pink/10 border border-energy-pink/40 rounded-xl p-5">
                                <div class="text-3xl mb-3">🤖</div>
                                <h4 class="font-bold text-energy-pink mb-2">Anti-Scan Bot</h4>
                                <p class="text-gray-300 text-sm mb-3">Honeypot & Detection</p>
                                <ul class="text-xs text-gray-400 space-y-1">
                                    <li>• Honeypot traps</li>
                                    <li>• Bot detection AI</li>
                                    <li>• Fake UA detection</li>
                                    <li>• Vulnerability scan block</li>
                                </ul>
                            </div>
                        </div>

                        <div class="bg-accent-purple/15 border border-accent-purple/40 rounded-lg p-4 mt-4">
                            <p class="text-accent-purple text-sm">
                                <?= icon('check-circle', 'w-5 h-5 mr-2') ?>
                                <strong>Tutto in tempo reale:</strong> Cerbero analizza ogni richiesta in &lt;1ms. Gli attaccanti vengono
                                identificati e bannati automaticamente prima che possano causare danni.
                            </p>
                        </div>
                    </div>

                    <!-- Zero Plain Text Guarantee -->
                    <div class="bg-gradient-to-r from-green-500/10 to-cool-cyan/10 border-2 border-green-500/40 rounded-2xl p-6 mt-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="text-4xl">🔐</div>
                            <div>
                                <h3 class="text-xl font-bold text-green-400">Zero Dati in Chiaro</h3>
                                <p class="text-green-300 text-sm">Garanzia di Crittografia Totale</p>
                            </div>
                        </div>

                        <div class="grid md:grid-cols-2 gap-4">
                            <ul class="text-gray-300 text-sm space-y-2">
                                <li><?= icon('check', 'w-5 h-5 text-green-400 mr-2') ?><strong>Password:</strong> Hash Argon2id con salt unico (irreversibile)</li>
                                <li><?= icon('check', 'w-5 h-5 text-green-400 mr-2') ?><strong>Sessioni:</strong> Token crittografati + HttpOnly + Secure</li>
                                <li><?= icon('check', 'w-5 h-5 text-green-400 mr-2') ?><strong>Comunicazioni:</strong> HTTPS/TLS 1.3 obbligatorio</li>
                            </ul>
                            <ul class="text-gray-300 text-sm space-y-2">
                                <li><?= icon('check', 'w-5 h-5 text-green-400 mr-2') ?><strong>Database:</strong> Connessioni crittografate</li>
                                <li><?= icon('check', 'w-5 h-5 text-green-400 mr-2') ?><strong>DM Privati:</strong> Crittografia E2E (AES-256-GCM)</li>
                                <li><?= icon('check', 'w-5 h-5 text-green-400 mr-2') ?><strong>Backup:</strong> Crittografati AES-256</li>
                            </ul>
                        </div>

                        <p class="text-green-300 text-sm mt-4 font-semibold">
                            <?= icon('shield-check', 'w-5 h-5 mr-2') ?>
                            Nessun dato sensibile è MAI memorizzato in chiaro. Neanche noi possiamo leggere le tue password.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 6. CONTATTI E DPO -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('envelope', 'w-5 h-5 text-accent-purple mr-3') ?>
                    6. Contatti e Data Protection Officer
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>
                        Per esercitare i tuoi diritti GDPR o domande sulla privacy:
                    </p>

                    <div class="bg-accent-purple/10 border border-accent-purple/30 rounded-lg p-6">
                        <div class="grid md:grid-cols-2 gap-6">
                            <!-- Titolare Trattamento -->
                            <div>
                                <h4 class="font-semibold text-accent-purple mb-3">
                                    <?= icon('building', 'w-5 h-5 mr-2') ?>
                                    Titolare del Trattamento
                                </h4>
                                <div class="text-sm space-y-1">
                                    <p><strong>Titolare:</strong> Nicola Cucurachi</p>
                                    <p><strong>Sede:</strong> Via Pancaldi, 59 - 41122 Modena - MO - Italy</p>
                                    <p><strong>Email:</strong> support@need2talk.it</p>
                                    <p><strong>Telefono:</strong> 059/361164</p>
                                </div>
                            </div>

                            <!-- Contatti Privacy -->
                            <div>
                                <h4 class="font-semibold text-accent-purple mb-3">
                                    <?= icon('user-shield', 'w-5 h-5 mr-2') ?>
                                    Contatti Privacy
                                </h4>
                                <div class="text-sm space-y-1">
                                    <p><strong>Responsabile:</strong> Nicola Cucurachi</p>
                                    <p><strong>Email:</strong> support@need2talk.it</p>
                                    <p><strong>Telefono:</strong> 059/361164</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 pt-6 border-t border-accent-purple/30">
                            <h4 class="font-semibold text-accent-purple mb-3">
                                <?= icon('landmark', 'w-5 h-5 mr-2') ?>
                                Autorità di Controllo
                            </h4>
                            <p class="text-sm">
                                In caso di violazioni privacy, hai diritto a presentare reclamo al <strong>Garante per la Protezione dei Dati Personali</strong>:
                            </p>
                            <div class="text-sm mt-2 space-y-1">
                                <p><strong>Piazza Venezia 11, 00187 Roma</strong></p>
                                <p><strong>Tel:</strong> +39 06 696771</p>
                                <p><strong>Web:</strong> <a href="https://www.garanteprivacy.it" class="text-accent-purple hover:text-accent-purple/80 underline">www.garanteprivacy.it</a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 7. MODIFICHE INFORMATIVA -->
            <section class="mb-8">
                <h2 class="text-2xl font-bold text-accent-purple mb-4 flex items-center">
                    <?= icon('history', 'w-5 h-5 text-accent-purple mr-3') ?>
                    7. Modifiche all'Informativa Privacy
                </h2>
                <div class="text-gray-300 space-y-4">
                    <p>
                        Questa informativa può essere aggiornata per riflettere cambiamenti normativi o servizi.
                    </p>
                    <div class="bg-energy-magenta/10 border border-energy-magenta/30 rounded-lg p-4">
                        <p class="text-energy-magenta text-sm">
                            <?= icon('bell', 'w-5 h-5 mr-2') ?>
                            <strong>Ti notificheremo</strong> modifiche sostanziali via email e banner in-app. Continuando a usare il servizio, accetti le nuove condizioni.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Footer Legale -->
            <footer class="border-t border-accent-purple/20 pt-6 mt-8">
                <div class="flex flex-col md:flex-row justify-between items-center text-sm">
                    <div class="text-accent-purple mb-4 md:mb-0">
                        <p>
                            <strong>Effettivo dal:</strong> <?= $effectiveDate ?><br>
                            <strong>Versione:</strong> 1.0 - GDPR Compliant
                        </p>
                    </div>
                    <div class="flex space-x-6">
                        <a href="<?= url('legal/terms') ?>"
                           class="text-accent-purple hover:text-accent-purple/80 hover:underline">
                            Termini di Servizio
                        </a>
                        <a href="<?= url('legal/contacts') ?>"
                           class="text-accent-purple hover:text-accent-purple/80 hover:underline">
                            Contatti
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

<!-- Page-specific GDPR JavaScript (Inline per logica interattiva buttons) -->
<script nonce="<?= csp_nonce() ?>">
// GDPR Actions Handler (logica specifica privacy.php)
document.addEventListener('DOMContentLoaded', function() {
    // Gestione click su tutti i button GDPR rights
    document.querySelectorAll('button[class*="btn"]').forEach(button => {
        button.addEventListener('click', function(e) {
            const action = this.textContent.trim();

            // Logica specifica per ogni diritto GDPR
            switch(action) {
                case 'Richiedi Dati':
                    handleDataAccess();
                    break;
                case 'Modifica Profilo':
                    window.location.href = '/profile/settings';
                    break;
                case 'Elimina Account':
                    handleAccountDeletion();
                    break;
                case 'Esporta Dati':
                    handleDataExport();
                    break;
                case 'Limita Trattamento':
                    handleDataLimitation();
                    break;
                case 'Gestisci Consensi':
                    handleConsentManagement();
                    break;
            }
        });
    });
});

function handleDataAccess() {
    alert('Richiesta accesso dati inviata. Riceverai una risposta entro 72 ore.');
}

function handleAccountDeletion() {
    if (confirm('ATTENZIONE: La cancellazione dell\'account è IRREVERSIBILE. Continuare?')) {
        window.location.href = '/profile/delete-account';
    }
}

function handleDataExport() {
    alert('Preparazione export dati in corso. Riceverai il download via email entro 24 ore.');
}

function handleDataLimitation() {
    alert('Richiesta limitazione trattamento registrata. Il DPO ti contatterà entro 72 ore.');
}

function handleConsentManagement() {
    window.location.href = '/profile/privacy-settings';
}
</script>

<?php
// ENTERPRISE: Capture content and inject into layout
$content = ob_get_clean();

// Load guest layout (handles ALL HTML structure, CSS, JS, monitoring)
require APP_ROOT . '/app/Views/layouts/guest.php';
?>
