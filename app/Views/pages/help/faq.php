<?php
/**
 * NEED2TALK - FAQ PAGE (DOMANDE FREQUENTI)
 * FAQ vere: brevi, pratiche, dirette - SEO-friendly ma NO guida psicologica
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

$title = 'Domande Frequenti (FAQ) | need2talk';
$description = 'Risposte rapide alle domande più frequenti su need2talk: come funziona, costi, privacy, registrazione audio, limiti tecnici e supporto emotivo reciproco.';

// CONTENT START
ob_start();
?>

<div class="min-h-screen bg-gradient-to-b from-brand-midnight via-brand-slate to-brand-midnight py-20">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-12">

        <!-- Header -->
        <div class="text-center mb-12">
            <h1 class="text-4xl md:text-5xl font-bold mb-6 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-violet bg-clip-text text-transparent">
                <?= icon('question-circle', 'w-12 h-12 inline-block mb-2') ?>
                <br>Domande Frequenti
            </h1>
            <p class="text-xl text-neutral-silver max-w-3xl mx-auto">
                Risposte rapide alle domande più comuni su <strong class="text-accent-purple">need2talk</strong>
            </p>
        </div>

        <!-- FAQ Items -->
        <div class="space-y-6">

            <!-- FAQ 1: Come funziona -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('play-circle', 'w-6 h-6') ?>
                    Come funziona need2talk?
                </h2>
                <p class="text-neutral-silver leading-relaxed">
                    need2talk è una piattaforma di <strong>supporto emotivo reciproco</strong> basata su messaggi vocali di 30 secondi. Registri le tue <strong>emozioni</strong>, le condividi con la community, ascolti gli altri e offri <strong>ascolto attivo</strong>. Semplice, gratuito, accessibile 24/7.
                </p>
            </div>

            <!-- FAQ 2: Quanto costa -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('euro-sign', 'w-6 h-6') ?>
                    Quanto costa?
                </h2>
                <p class="text-neutral-silver leading-relaxed">
                    <strong class="text-accent-violet text-xl">Gratis. Sempre.</strong> Nessun abbonamento, nessun acquisto in-app, nessun contenuto a pagamento. Il <strong>benessere psicologico</strong> è un diritto, non un privilegio.
                </p>
            </div>

            <!-- FAQ 3: Come registro audio -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('microphone', 'w-6 h-6') ?>
                    Come registro un audio?
                </h2>
                <div class="text-neutral-silver space-y-3">
                    <p><strong>3 step semplicissimi:</strong></p>
                    <ol class="space-y-2 ml-6 list-decimal">
                        <li>Seleziona un'<strong>emozione</strong> dalla home</li>
                        <li>Clicca sul pulsante microfono e <strong>parla</strong> (max 30 secondi)</li>
                        <li>Clicca "Stop" e poi "Pubblica"</li>
                    </ol>
                    <p class="text-sm italic mt-3">💡 Serve dare il permesso al browser di usare il microfono al primo utilizzo</p>
                </div>
            </div>

            <!-- FAQ 4: È sicuro/privacy - CERBERO -->
            <div class="bg-gradient-to-br from-brand-charcoal via-accent-purple/5 to-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border-2 border-accent-purple/40 shadow-lg hover:border-accent-purple/60 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('shield', 'w-6 h-6') ?>
                    È sicuro? Come proteggo la mia privacy?
                </h2>
                <div class="text-neutral-silver">
                    <p class="mb-4"><strong class="text-accent-violet">Sicurissimo.</strong> need2talk è protetto da <strong class="text-accent-purple">CERBERO</strong>, il nostro sistema di sicurezza enterprise:</p>

                    <!-- Cerbero Badge -->
                    <div class="bg-brand-midnight/60 rounded-xl p-4 mb-4 border border-accent-purple/30">
                        <div class="flex items-center gap-3 mb-3">
                            <img src="<?= asset('img/cerbero-xs.png') ?>"
                                 alt="Cerbero"
                                 class="w-10 h-auto"
                                 loading="lazy">
                            <span class="font-bold text-accent-purple">CERBERO</span>
                            <span class="ml-auto bg-green-500/20 border border-green-500/50 text-green-400 px-2 py-0.5 rounded text-xs font-bold">Voto A</span>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-xs text-center">
                            <div class="bg-accent-purple/10 rounded p-2">
                                <span class="text-accent-purple font-bold">WAF</span>
                                <p class="text-neutral-silver">Anti-attacchi web</p>
                            </div>
                            <div class="bg-cool-cyan/10 rounded p-2">
                                <span class="text-cool-cyan font-bold">Firewall</span>
                                <p class="text-neutral-silver">Anti-DDoS</p>
                            </div>
                            <div class="bg-energy-pink/10 rounded p-2">
                                <span class="text-energy-pink font-bold">Anti-Bot</span>
                                <p class="text-neutral-silver">Scanner bloccati</p>
                            </div>
                        </div>
                    </div>

                    <p class="font-semibold text-accent-lavender mb-2">Protezioni tecniche:</p>
                    <ul class="space-y-2 ml-6 list-disc mb-4">
                        <li><strong>Password Argon2id</strong> - standard bancario, impossibili da decifrare</li>
                        <li><strong>TLS 1.3</strong> - crittografia militare su tutte le connessioni</li>
                        <li><strong>Zero dati in chiaro</strong> - tutto è crittografato nel database</li>
                        <li><strong>Audio non scaricabili</strong> - ACL privato + URL firmati temporanei</li>
                    </ul>

                    <p class="font-semibold text-accent-lavender mb-2">Controllo utente:</p>
                    <ul class="space-y-2 ml-6 list-disc">
                        <li>Profilo <strong>privato</strong>: controlli chi vede i tuoi contenuti</li>
                        <li><strong>Blocco utenti</strong> indesiderati in 1 click</li>
                        <li><strong>Elimina audio</strong> in qualsiasi momento (permanente)</li>
                        <li><strong>Report & moderazione</strong>: community sicura</li>
                        <li><strong>Nessun tracking</strong> pubblicitario (no ads)</li>
                    </ul>
                </div>
            </div>

            <!-- FAQ 5: Posso eliminare audio -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('trash', 'w-6 h-6') ?>
                    Posso eliminare i miei audio?
                </h2>
                <p class="text-neutral-silver leading-relaxed">
                    <strong class="text-accent-violet">Sì, sempre.</strong> Vai sul tuo profilo, seleziona l'audio e clicca "Elimina". <span class="text-energy-magenta font-semibold">Attenzione:</span> l'eliminazione è <strong>permanente</strong> e non può essere annullata.
                </p>
            </div>

            <!-- FAQ 6: Limiti tecnici -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('stopwatch', 'w-6 h-6') ?>
                    Quali sono i limiti?
                </h2>
                <div class="text-neutral-silver">
                    <ul class="space-y-2 ml-6 list-disc">
                        <li><strong>Durata:</strong> Max 30 secondi per audio (sufficiente per <strong>espressione emotiva</strong> concisa)</li>
                        <li><strong>Frequenza:</strong> Max 10 audio al giorno (previene spam, favorisce <strong>riflessione</strong>)</li>
                        <li><strong>Formato:</strong> WebM Opus (ottimizzato per voce umana)</li>
                        <li><strong>Età:</strong> Solo maggiorenni (18+)</li>
                    </ul>
                </div>
            </div>

            <!-- FAQ 7: È terapia? -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('user-md', 'w-6 h-6') ?>
                    need2talk è terapia psicologica?
                </h2>
                <div class="text-neutral-silver">
                    <p class="mb-4"><strong class="text-energy-magenta text-lg">NO.</strong> need2talk è <strong>supporto emotivo reciproco</strong>, NON terapia professionale.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-cool-cyan/10 rounded-lg p-4 border border-cool-cyan/30">
                            <p class="font-bold text-cool-cyan mb-2">✅ need2talk È:</p>
                            <ul class="text-sm space-y-1">
                                <li>• <strong>Supporto reciproco</strong></li>
                                <li>• <strong>Ascolto attivo</strong></li>
                                <li>• <strong>Autoconsapevolezza</strong></li>
                                <li>• <strong>Community</strong> empatica</li>
                            </ul>
                        </div>
                        <div class="bg-energy-magenta/10 rounded-lg p-4 border border-energy-magenta/30">
                            <p class="font-bold text-energy-magenta mb-2">❌ NON È:</p>
                            <ul class="text-sm space-y-1">
                                <li>• Terapia professionale</li>
                                <li>• Diagnosi clinica</li>
                                <li>• Prescrizione farmaci</li>
                                <li>• Sostituto psicologo</li>
                            </ul>
                        </div>
                    </div>
                    <p class="mt-4 text-sm italic">
                        ⚠️ Se hai <strong>crisi psicologiche acute</strong>, <strong>depressione grave</strong> o <strong>pensieri suicidi</strong>, contatta immediatamente un <strong>professionista</strong>.
                    </p>
                </div>
            </div>

            <!-- FAQ 8: Il microfono non funziona -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('exclamation-triangle', 'w-6 h-6') ?>
                    Il microfono non funziona, cosa faccio?
                </h2>
                <div class="text-neutral-silver">
                    <p class="mb-3"><strong>Checklist rapida:</strong></p>
                    <ul class="space-y-2 ml-6 list-disc">
                        <li>✓ Hai dato il <strong>permesso</strong> al browser? (popup all'avvio)</li>
                        <li>✓ Il microfono è <strong>collegato</strong> e funzionante?</li>
                        <li>✓ Nessun'altra app sta usando il microfono?</li>
                        <li>✓ Stai usando un <strong>browser moderno</strong>? (Chrome, Safari, Firefox, Edge)</li>
                        <li>✓ <strong>NON</strong> sei in modalità incognito/privata? (alcuni browser bloccano microfono)</li>
                    </ul>
                    <p class="mt-4 text-sm">
                        Se il problema persiste: <a href="<?= url('legal/contacts') ?>" class="text-accent-purple underline hover:text-accent-violet font-semibold">Contattaci →</a>
                    </p>
                </div>
            </div>

            <!-- FAQ 9: Posso usarlo da mobile -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('mobile', 'w-6 h-6') ?>
                    Funziona su smartphone?
                </h2>
                <p class="text-neutral-silver leading-relaxed">
                    <strong class="text-accent-violet">Sì, perfettamente.</strong> need2talk è <strong>responsive</strong> e ottimizzato per mobile. Funziona su iOS (Safari) e Android (Chrome, Firefox). Nessuna app da scaricare: apri il browser e vai su <strong>need2talk.it</strong>.
                </p>
            </div>

            <!-- FAQ 10: Posso essere anonimo -->
            <div class="bg-brand-charcoal backdrop-blur-sm rounded-2xl p-6 border border-accent-purple/30 shadow-lg hover:border-accent-purple/50 transition-all duration-300">
                <h2 class="text-2xl font-semibold text-accent-purple mb-4 flex items-center gap-3">
                    <?= icon('user-secret', 'w-6 h-6') ?>
                    Posso rimanere anonimo?
                </h2>
                <p class="text-neutral-silver leading-relaxed">
                    <strong class="text-accent-violet">Sì.</strong> Puoi usare uno <strong>pseudonimo</strong>, non caricare foto profilo, e impostare il profilo come <strong>privato</strong>. La tua email non è mai visibile pubblicamente. Decidi tu quanto rivelare di te nel tuo percorso di <strong>espressione emotiva</strong>.
                </p>
            </div>

        </div>

        <!-- Contact Support -->
        <div class="mt-16">
            <div class="bg-gradient-to-br from-accent-violet/20 via-accent-purple/20 to-energy-pink/20 backdrop-blur-sm rounded-2xl p-8 border border-accent-purple/50 shadow-xl text-center">
                <h2 class="text-3xl font-bold text-accent-violet mb-4">
                    Non hai trovato la risposta?
                </h2>
                <p class="text-neutral-silver mb-6 max-w-2xl mx-auto">
                    Siamo qui per aiutarti. Contattaci o leggi la guida completa.
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                    <a href="<?= url('legal/contacts') ?>"
                       class="inline-flex items-center justify-center gap-2 px-8 py-3 bg-energy-pink hover:bg-energy-pink/90 text-white font-semibold rounded-xl transition-all shadow-lg shadow-energy-pink/30 hover:scale-105 transform">
                        <?= icon('envelope', 'w-5 h-5') ?>
                        Contattaci
                    </a>

                    <a href="<?= url('help/guide') ?>"
                       class="inline-flex items-center justify-center gap-2 px-8 py-3 border-2 border-accent-violet text-accent-violet hover:bg-accent-violet hover:text-white font-medium rounded-xl transition-all">
                        <?= icon('book', 'w-5 h-5') ?>
                        Guida Completa
                    </a>

                    <a href="<?= url('about') ?>"
                       class="inline-flex items-center justify-center gap-2 px-8 py-3 border-2 border-cool-cyan text-cool-cyan hover:bg-cool-cyan hover:text-white font-medium rounded-xl transition-all">
                        <?= icon('info-circle', 'w-5 h-5') ?>
                        Chi Siamo
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
$content = ob_get_clean();
// Render usando layout unificato
include APP_ROOT . '/app/Views/layouts/guest.php';
