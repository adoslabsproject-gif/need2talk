<?php
/**
 * NEED2TALK - CHI SIAMO (SEO OPTIMIZED FOR PSYCHOLOGY KEYWORDS)
 * Ottimizzato per: supporto emotivo, autoconsapevolezza, benessere psicologico
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

$title = 'Chi Siamo - Supporto Emotivo e Autoconsapevolezza | need2talk';
$description = 'need2talk: piattaforma di supporto emotivo reciproco per espressione delle emozioni, autoconsapevolezza e benessere psicologico. Fai parlare la tua anima attraverso l\'ascolto attivo.';

// CONTENT START
ob_start();
?>

<div class="min-h-screen bg-gradient-to-b from-brand-midnight via-brand-slate to-brand-midnight py-20">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-12">

        <!-- Header -->
        <div class="text-center mb-16">
            <div class="flex justify-center mb-6">
                <div class="relative w-24 h-24">
                    <div class="absolute inset-0 bg-gradient-to-br from-purple-600 to-pink-600 rounded-3xl shadow-xl shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300"></div>
                    <picture>
                        <source srcset="<?php echo asset('img/logo-192.webp'); ?>" type="image/webp">
                        <img src="<?php echo asset('img/logo-192.png'); ?>"
                             alt="need2talk Logo - Supporto Emotivo"
                             class="absolute inset-0 w-full h-full rounded-3xl object-cover"
                             loading="eager"
                             width="96"
                             height="96">
                    </picture>

                    <!-- Cerchi concentrici pulsanti -->
                    <div class="absolute inset-0 rounded-full border-2 border-pink-500/60 animate-ping"></div>
                    <div class="absolute -inset-2 rounded-full border border-purple-500/40 animate-ping" style="animation-delay: 0.5s; animation-duration: 2s;"></div>
                </div>
            </div>

            <h1 class="text-4xl md:text-5xl font-bold mb-6 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                Fai Parlare la Tua Anima:<br>need2talk
            </h1>
            <p class="text-xl text-neutral-silver max-w-3xl mx-auto">
                Uno spazio di <strong class="text-accent-purple">supporto emotivo reciproco</strong> per l'<strong class="text-accent-violet">espressione delle emozioni</strong> e il <strong class="text-accent-purple">benessere psicologico</strong>
            </p>
        </div>

        <!-- La Missione Psicologica -->
        <section class="mb-12">
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-8 border border-accent-lavender/30 shadow-lg">
                <div class="flex items-center gap-3 mb-6">
                    <?= icon('heart', 'w-8 h-8 text-accent-lavender') ?>
                    <h2 class="text-2xl font-bold text-accent-violet">La Missione: Benessere Emotivo Accessibile</h2>
                </div>

                <p class="text-neutral-silver text-lg leading-relaxed mb-4">
                    need2talk nasce nel <strong class="text-accent-purple">2025</strong> con una visione rivoluzionaria: creare uno spazio digitale dove <strong>l'espressione delle emozioni</strong> e il <strong>supporto emotivo reciproco</strong> siano accessibili a tutti, gratuitamente.
                </p>

                <p class="text-neutral-silver text-lg leading-relaxed mb-4">
                    In un'epoca dove il <strong>benessere psicologico</strong> è sempre più trascurato, offriamo una piattaforma che valorizza l'<strong>ascolto attivo</strong>, l'<strong>autoconsapevolezza emotiva</strong> e la <strong>guarigione attraverso la condivisione</strong>.
                </p>

                <div class="bg-accent-purple/10 border-l-4 border-accent-purple rounded-r-lg p-5 mt-6">
                    <p class="text-neutral-silver italic">
                        "<strong>Fai parlare la tua anima</strong>: ogni voce merita di essere ascoltata, ogni emozione ha diritto di esistere, ogni storia può aiutare qualcun altro nel proprio percorso di <strong>autoconsapevolezza</strong> e <strong>crescita emotiva</strong>."
                    </p>
                    <p class="text-accent-purple font-semibold mt-3">— La Filosofia di need2talk</p>
                </div>
            </div>
        </section>

        <!-- La Storia: Come È Nato need2talk -->
        <section class="mb-12">
            <div class="bg-gradient-to-br from-cool-cyan/10 via-accent-violet/10 to-energy-pink/10 backdrop-blur-sm rounded-2xl p-8 border border-cool-cyan/40 shadow-lg">
                <div class="flex items-center gap-3 mb-6">
                    <?= icon('rocket', 'w-8 h-8 text-cool-cyan') ?>
                    <h2 class="text-2xl font-bold text-cool-cyan">La Storia: Come È Nato need2talk</h2>
                </div>

                <p class="text-neutral-silver text-lg leading-relaxed mb-4">
                    need2talk nasce da un'esperienza personale. Lavoro come <strong class="text-cool-cyan">operations manager</strong> nel settore industriale delle valvole - un mondo lontanissimo dalla programmazione. Ma ho sempre sentito che mancava qualcosa: <strong>uno spazio dove le persone potessero davvero esprimersi</strong>, senza filtri, senza giudizi.
                </p>

                <p class="text-neutral-silver text-lg leading-relaxed mb-4">
                    Ho visto colleghi, amici, familiari che avevano bisogno di parlare ma non sapevano con chi. <strong class="text-energy-pink">Quel bisogno di essere ascoltati</strong> che tutti abbiamo, ma che spesso resta inespresso.
                </p>

                <p class="text-neutral-silver text-lg leading-relaxed mb-6">
                    Così ho deciso di provare. Zero esperienza di programmazione. Nessun team. Solo la voglia di costruire qualcosa che mi stava a cuore, imparando a <strong>orchestrare l'intelligenza artificiale</strong> (Claude Code di Anthropic) come strumento.
                </p>

                <!-- Il Processo Reale -->
                <div class="bg-brand-midnight/50 rounded-xl p-6 border border-accent-violet/30 mb-6">
                    <h3 class="text-xl font-bold text-accent-violet mb-4">📝 Il Processo Reale (Senza Filtri)</h3>
                    <div class="space-y-4 text-neutral-silver">
                        <div class="flex items-start gap-3">
                            <span class="text-energy-pink text-xl">✗</span>
                            <p><strong>Ho sbagliato centinaia di volte.</strong> Funzionalità riscritte da zero, bug che non capivo, notti a cercare di capire perché qualcosa non funzionava.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-energy-pink text-xl">✗</span>
                            <p><strong>L'AI non è magica.</strong> Claude Code è potente, ma va guidato, corretto, a volte "bastonato". Senza supervisione costante, produce codice mediocre.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-energy-pink text-xl">✗</span>
                            <p><strong>Ho dovuto imparare tutto.</strong> Database, sicurezza, server, deploy, performance... Un crash course forzato, con tanti fallimenti.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-400 text-xl">✓</span>
                            <p><strong>Ma alla fine, funziona.</strong> Ogni errore mi ha insegnato qualcosa. Ogni iterazione ha migliorato il prodotto.</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Il Mio Ruolo -->
                    <div class="bg-energy-pink/10 rounded-xl p-6 border border-energy-pink/30">
                        <h3 class="text-lg font-bold text-energy-pink mb-4 flex items-center gap-2">
                            <?= icon('user', 'w-5 h-5') ?>
                            Il Mio Ruolo
                        </h3>
                        <ul class="space-y-2 text-neutral-silver text-sm">
                            <li>• <strong>Visione del prodotto</strong> - cosa costruire e perché</li>
                            <li>• <strong>Decisioni di design</strong> - UX centrata sull'utente</li>
                            <li>• <strong>Direzione e correzione</strong> - guidare l'AI</li>
                            <li>• <strong>Testing ossessivo</strong> - provare tutto, rompere tutto</li>
                            <li>• <strong>Imparare dagli errori</strong> - iterare continuamente</li>
                        </ul>
                    </div>

                    <!-- Claude Code -->
                    <div class="bg-accent-violet/10 rounded-xl p-6 border border-accent-violet/30">
                        <h3 class="text-lg font-bold text-accent-violet mb-4 flex items-center gap-2">
                            <?= icon('robot', 'w-5 h-5') ?>
                            Claude Code (AI)
                        </h3>
                        <ul class="space-y-2 text-neutral-silver text-sm">
                            <li>• <strong>Scrittura del codice</strong> - sotto mia supervisione</li>
                            <li>• <strong>Conoscenza tecnica</strong> - quello che non so, lo chiedo</li>
                            <li>• <strong>Debugging</strong> - trovare e correggere bug</li>
                            <li>• <strong>Implementazione</strong> - trasformare idee in codice</li>
                            <li>• <strong>Strumento, non cervello</strong> - esegue, non decide</li>
                        </ul>
                    </div>
                </div>

                <div class="bg-gradient-to-r from-accent-violet/20 to-energy-pink/20 rounded-xl p-6 border border-accent-violet/40">
                    <h3 class="text-xl font-bold text-accent-violet mb-4">💡 Cosa Ho Imparato</h3>
                    <ul class="space-y-2 text-neutral-silver">
                        <li>✅ L'<strong>AI è uno strumento potente</strong>, ma richiede direzione umana costante</li>
                        <li>✅ <strong>Non serve essere programmatori</strong> per costruire software, ma serve capire cosa vuoi costruire</li>
                        <li>✅ <strong>Gli errori sono il miglior insegnante</strong> - ogni fallimento ti avvicina alla soluzione</li>
                        <li>✅ <strong>La passione batte l'esperienza</strong> - se ti sta a cuore, trovi il modo</li>
                    </ul>
                </div>

                <div class="bg-energy-magenta/10 border-l-4 border-energy-magenta rounded-r-lg p-5 mt-6">
                    <p class="text-neutral-silver italic">
                        "Non sono un programmatore, non sono un genio. Sono solo una persona che aveva un'idea e ha trovato un modo per realizzarla. <strong class="text-energy-magenta">Se l'ho fatto io, partendo da zero, può farlo chiunque abbia la pazienza di provarci.</strong>"
                    </p>
                    <p class="text-energy-magenta font-semibold mt-3">— Il Fondatore di need2talk</p>
                </div>
            </div>
        </section>

        <!-- Supporto Emotivo -->
        <section class="mb-12">
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-8 border border-accent-purple/30 shadow-lg">
                <div class="flex items-center gap-3 mb-6">
                    <?= icon('users', 'w-8 h-8 text-accent-purple') ?>
                    <h2 class="text-2xl font-bold text-accent-violet">Supporto Emotivo Reciproco: Come Funziona</h2>
                </div>

                <p class="text-neutral-silver text-lg leading-relaxed mb-8">
                    need2talk non è terapia psicologica professionale, ma uno <strong>spazio di sostegno reciproco</strong> dove condividere <strong>emozioni</strong>, esperienze e percorsi di <strong>autoguarigione</strong>.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Espressione Emotiva -->
                    <div class="bg-energy-pink/10 rounded-xl p-6 border border-energy-pink/30">
                        <h3 class="text-lg font-bold text-accent-violet mb-4 flex items-center gap-2">
                            <?= icon('microphone', 'w-5 h-5 text-accent-violet') ?>
                            Esprimi le Tue Emozioni
                        </h3>
                        <ul class="space-y-2 text-neutral-silver text-sm">
                            <li>✓ <strong>Sfogo emotivo sano</strong> attraverso la voce</li>
                            <li>✓ <strong>Elaborazione</strong> di pensieri e sentimenti</li>
                            <li>✓ <strong>Autoconsapevolezza</strong> emotiva</li>
                            <li>✓ <strong>Validazione</strong> delle proprie emozioni</li>
                        </ul>
                    </div>

                    <!-- Ascolto Empatico -->
                    <div class="bg-accent-purple/10 rounded-xl p-6 border border-accent-purple/30">
                        <h3 class="text-lg font-bold text-accent-violet mb-4 flex items-center gap-2">
                            <?= icon('heart', 'w-5 h-5 text-accent-purple') ?>
                            Ascolto Attivo ed Empatico
                        </h3>
                        <ul class="space-y-2 text-neutral-silver text-sm">
                            <li>✓ <strong>Supporto emotivo</strong> dalla community</li>
                            <li>✓ <strong>Connessione umana</strong> autentica</li>
                            <li>✓ <strong>Empatia</strong> e comprensione</li>
                            <li>✓ <strong>Riduzione dell'isolamento</strong> emotivo</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- Benefici Psicologici -->
        <section class="mb-12">
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-8 border border-accent-lavender/30 shadow-lg">
                <div class="flex items-center gap-3 mb-6">
                    <?= icon('star', 'w-8 h-8 text-accent-lavender') ?>
                    <h2 class="text-2xl font-bold text-accent-violet">Benefici per il Benessere Psicologico</h2>
                </div>

                <p class="text-neutral-silver text-lg leading-relaxed mb-6">
                    La ricerca scientifica conferma che l'<strong>espressione delle emozioni</strong> e il <strong>supporto sociale</strong> sono fondamentali per la <strong>salute mentale</strong>. need2talk facilita:
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-brand-midnight/50 rounded-xl p-5 border border-neutral-darkGray">
                        <h3 class="font-bold text-accent-purple mb-3">🧠 Autoconsapevolezza</h3>
                        <ul class="space-y-1 text-neutral-silver text-sm">
                            <li>• <strong>Riconoscimento emotivo</strong></li>
                            <li>• <strong>Mindfulness</strong> e presenza</li>
                            <li>• <strong>Introspezione</strong> guidata</li>
                        </ul>
                    </div>

                    <div class="bg-brand-midnight/50 rounded-xl p-5 border border-neutral-darkGray">
                        <h3 class="font-bold text-accent-purple mb-3">💪 Resilienza Emotiva</h3>
                        <ul class="space-y-1 text-neutral-silver text-sm">
                            <li>• <strong>Gestione dello stress</strong></li>
                            <li>• <strong>Elaborazione del trauma</strong></li>
                            <li>• <strong>Crescita post-traumatica</strong></li>
                        </ul>
                    </div>

                    <div class="bg-brand-midnight/50 rounded-xl p-5 border border-neutral-darkGray">
                        <h3 class="font-bold text-accent-purple mb-3">❤️ Connessione Sociale</h3>
                        <ul class="space-y-1 text-neutral-silver text-sm">
                            <li>• <strong>Riduzione della solitudine</strong></li>
                            <li>• <strong>Senso di appartenenza</strong></li>
                            <li>• <strong>Empatia reciproca</strong></li>
                        </ul>
                    </div>

                    <div class="bg-brand-midnight/50 rounded-xl p-5 border border-neutral-darkGray">
                        <h3 class="font-bold text-accent-purple mb-3">🌱 Autoguarigione</h3>
                        <ul class="space-y-1 text-neutral-silver text-sm">
                            <li>• <strong>Elaborazione emotiva</strong></li>
                            <li>• <strong>Catarsi</strong> attraverso la voce</li>
                            <li>• <strong>Integrazione</strong> delle esperienze</li>
                        </ul>
                    </div>
                </div>

                <div class="bg-energy-magenta/10 border-l-4 border-energy-magenta rounded-r-lg p-5 mt-6">
                    <p class="text-neutral-silver text-sm">
                        <strong>⚠️ Importante:</strong> need2talk è uno strumento di <strong>supporto emotivo reciproco</strong>, non sostituisce la <strong>terapia psicologica professionale</strong>. Se stai affrontando problemi di salute mentale gravi, consulta un professionista.
                    </p>
                </div>
            </div>
        </section>

        <!-- 🐕 CERBERO - La Sicurezza al Centro -->
        <section class="mb-12">
            <div class="bg-gradient-to-br from-brand-charcoal via-accent-purple/10 to-brand-charcoal backdrop-blur-sm rounded-2xl p-8 border-2 border-accent-purple/50 shadow-2xl shadow-accent-purple/20">
                <div class="flex flex-col md:flex-row items-center gap-6 mb-8">
                    <!-- Cerbero: Il Cane a TRE TESTE -->
                    <div class="relative flex items-center justify-center">
                        <img src="<?= asset('img/cerbero-small.png') ?>"
                             alt="Cerbero - Il Guardiano di need2talk"
                             class="w-24 h-auto drop-shadow-2xl"
                             loading="lazy"
                             width="200"
                             height="300">
                        <!-- Glow effect -->
                        <div class="absolute inset-0 bg-accent-purple/30 blur-3xl rounded-full -z-10"></div>
                    </div>
                    <div class="text-center md:text-left">
                        <h2 class="text-3xl md:text-4xl font-bold text-accent-purple mb-2">CERBERO</h2>
                        <p class="text-xl text-accent-lavender">Il Guardiano dei Tuoi Dati</p>
                        <div class="flex items-center justify-center md:justify-start gap-3 mt-3">
                            <span class="bg-green-500/20 border border-green-500/50 text-green-400 px-3 py-1 rounded-full text-sm font-bold">
                                Voto A - Security Headers
                            </span>
                            <span class="bg-accent-purple/20 border border-accent-purple/50 text-accent-purple px-3 py-1 rounded-full text-sm font-bold">
                                Enterprise Grade
                            </span>
                        </div>
                    </div>
                </div>

                <p class="text-neutral-silver text-lg leading-relaxed mb-6 text-center">
                    Come il <strong class="text-accent-purple">cane a tre teste</strong> della mitologia greca che custodiva l'ingresso degli Inferi,
                    <strong>Cerbero</strong> protegge need2talk da ogni minaccia. I tuoi dati sono <strong class="text-energy-pink">sacri e inviolabili</strong>.
                </p>

                <!-- Le Tre Teste -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <!-- Testa 1: WAF -->
                    <div class="bg-accent-purple/15 rounded-xl p-6 border border-accent-purple/40 text-center hover:scale-105 transition-transform">
                        <div class="text-5xl mb-4">🛡️</div>
                        <h3 class="text-xl font-bold text-accent-purple mb-2">WAF</h3>
                        <p class="text-accent-lavender text-sm mb-3">Web Application Firewall</p>
                        <p class="text-neutral-silver text-sm">
                            Blocca SQL Injection, XSS, CSRF e ogni attacco web conosciuto. Nessuna vulnerabilità passa.
                        </p>
                    </div>

                    <!-- Testa 2: Firewall -->
                    <div class="bg-cool-cyan/15 rounded-xl p-6 border border-cool-cyan/40 text-center hover:scale-105 transition-transform">
                        <div class="text-5xl mb-4">🔥</div>
                        <h3 class="text-xl font-bold text-cool-cyan mb-2">Firewall</h3>
                        <p class="text-cool-ice text-sm mb-3">Rate Limiting & DDoS Protection</p>
                        <p class="text-neutral-silver text-sm">
                            Limita automaticamente richieste sospette, blocca attacchi DDoS e geo-blocca paesi ostili.
                        </p>
                    </div>

                    <!-- Testa 3: Anti-Scan -->
                    <div class="bg-energy-pink/15 rounded-xl p-6 border border-energy-pink/40 text-center hover:scale-105 transition-transform">
                        <div class="text-5xl mb-4">🤖</div>
                        <h3 class="text-xl font-bold text-energy-pink mb-2">Anti-Scan Bot</h3>
                        <p class="text-energy-magenta text-sm mb-3">Honeypot & AI Detection</p>
                        <p class="text-neutral-silver text-sm">
                            Rileva e banna scanner, bot malevoli e fake user-agent prima che possano fare danni.
                        </p>
                    </div>
                </div>

                <!-- Garanzie di Sicurezza -->
                <div class="bg-brand-midnight/50 rounded-xl p-6 border border-neutral-darkGray">
                    <h3 class="text-xl font-bold text-green-400 mb-4 flex items-center justify-center gap-2">
                        🔐 Le Nostre Garanzie di Sicurezza
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3">
                            <span class="text-green-400 text-xl">✓</span>
                            <div>
                                <p class="text-neutral-white font-semibold">Password Impenetrabili</p>
                                <p class="text-neutral-silver text-sm">Hash Argon2id - neanche noi possiamo leggerle</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-400 text-xl">✓</span>
                            <div>
                                <p class="text-neutral-white font-semibold">Zero Dati in Chiaro</p>
                                <p class="text-neutral-silver text-sm">Tutto crittografato, nulla è accessibile</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-400 text-xl">✓</span>
                            <div>
                                <p class="text-neutral-white font-semibold">Audio Post Protetti</p>
                                <p class="text-neutral-silver text-sm">ACL privato + Signed URL - impossibile scaricarli</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-400 text-xl">✓</span>
                            <div>
                                <p class="text-neutral-white font-semibold">Anonimato Garantito</p>
                                <p class="text-neutral-silver text-sm">Nessuno può risalire alla tua identità</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-400 text-xl">✓</span>
                            <div>
                                <p class="text-neutral-white font-semibold">HTTPS/TLS 1.3</p>
                                <p class="text-neutral-silver text-sm">Comunicazioni crittografate end-to-end</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-green-400 text-xl">✓</span>
                            <div>
                                <p class="text-neutral-white font-semibold">Server in Europa</p>
                                <p class="text-neutral-silver text-sm">GDPR compliant, dati mai fuori dall'UE</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-accent-purple/20 border-l-4 border-accent-purple rounded-r-lg p-5 mt-6">
                    <p class="text-neutral-silver italic">
                        "<strong class="text-accent-purple">La tua privacy non è negoziabile.</strong>
                        Abbiamo costruito need2talk con la sicurezza al centro, non come afterthought.
                        Cerbero veglia 24/7 perché tu possa esprimerti liberamente, senza paura."
                    </p>
                    <p class="text-accent-purple font-semibold mt-3">— La Filosofia Cerbero</p>
                </div>
            </div>
        </section>

        <!-- Tecnologia al Servizio dell'Anima -->
        <section class="mb-12">
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-8 border border-cool-cyan/30 shadow-lg">
                <div class="flex items-center gap-3 mb-6">
                    <?= icon('bolt', 'w-8 h-8 text-cool-cyan') ?>
                    <h2 class="text-2xl font-bold text-accent-violet">Tecnologia al Servizio dell'Anima</h2>
                </div>

                <p class="text-neutral-silver text-lg leading-relaxed mb-6">
                    need2talk unisce <strong>innovazione tecnologica</strong> e <strong>sensibilità psicologica</strong>. La piattaforma è stata costruita da un italiano senza background tecnico, orchestrando Claude AI (Anthropic), per creare un'esperienza che valorizza l'<strong>autenticità emotiva</strong> e il <strong>benessere psicologico</strong>.
                </p>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-cool-cyan/20 rounded-xl p-5 text-center border-2 border-cool-cyan/50">
                        <div class="text-4xl font-bold text-cool-cyan mb-2">100%</div>
                        <div class="text-neutral-white font-medium text-sm">Gratuito</div>
                    </div>
                    <div class="bg-cool-cyan/20 rounded-xl p-5 text-center border-2 border-cool-cyan/50">
                        <div class="text-4xl font-bold text-cool-cyan mb-2">24/7</div>
                        <div class="text-neutral-white font-medium text-sm">Disponibile</div>
                    </div>
                    <div class="bg-cool-cyan/20 rounded-xl p-5 text-center border-2 border-cool-cyan/50">
                        <div class="text-4xl font-bold text-cool-cyan mb-2">2025</div>
                        <div class="text-neutral-white font-medium text-sm">Appena Nato</div>
                    </div>
                    <div class="bg-cool-cyan/20 rounded-xl p-5 text-center border-2 border-cool-cyan/50">
                        <div class="text-4xl font-bold text-cool-cyan mb-2">🇮🇹</div>
                        <div class="text-neutral-white font-medium text-sm">Made in Italy</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="text-center">
            <div class="bg-gradient-to-br from-accent-violet/20 via-accent-purple/20 to-energy-pink/20 backdrop-blur-sm rounded-2xl p-8 border border-accent-purple/50 shadow-xl">
                <h2 class="text-2xl font-bold text-accent-violet mb-4">Fai Parlare la Tua Anima</h2>
                <p class="text-neutral-silver mb-6 max-w-2xl mx-auto">
                    Unisciti a una community di <strong>supporto emotivo reciproco</strong>.
                    Inizia il tuo percorso di <strong>autoconsapevolezza</strong> e <strong>benessere psicologico</strong> oggi, gratuitamente.
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <a href="<?= url('auth/register') ?>"
                       class="inline-flex items-center justify-center gap-2 px-8 py-3 bg-energy-pink hover:bg-energy-pink/90 text-white font-semibold rounded-xl transition-all shadow-lg shadow-energy-pink/30 hover:scale-105 transform">
                        <?= icon('user-plus', 'w-5 h-5') ?>
                        Inizia Ora - Gratis
                    </a>
                    <a href="<?= url('/') ?>"
                       class="inline-flex items-center justify-center gap-2 px-8 py-3 border-2 border-accent-violet text-accent-violet hover:bg-accent-violet hover:text-white font-medium rounded-xl transition-all">
                        <?= icon('home', 'w-5 h-5') ?>
                        Torna alla Home
                    </a>
                </div>
            </div>
        </section>

    </div>
</div>

<?php
$content = ob_get_clean();
// Render usando layout unificato
include APP_ROOT . '/app/Views/layouts/guest.php';
