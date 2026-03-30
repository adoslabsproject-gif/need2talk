<?php
/**
 * NEED2TALK - REPORT PROBLEM PAGE (TAILWIND + FONTAWESOME)
 *
 * ENTERPRISE OPTIMIZATIONS:
 * - Page transition support
 * - Performance monitoring
 * - Minimal JS footprint
 * - Form validation
 * - GPU-accelerated animations
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

$title = 'Segnala un Problema - need2talk';
$description = 'Segnala contenuti inappropriati, comportamenti sospetti o violazioni delle regole della community su need2talk.';

// CONTENT START
ob_start();
?>

    <!-- Midnight Aurora Background -->
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
        <div class="max-w-3xl mx-auto px-4 py-12">

            <!-- Header -->
            <div class="text-center mb-12">
                <div class="w-20 h-20 bg-gradient-to-r from-energy-pink to-energy-rose rounded-full flex items-center justify-center mx-auto mb-6 shadow-2xl shadow-energy-pink/50 border-2 border-energy-pink/30">
                    <?= icon('flag', 'w-10 h-10 text-white') ?>
                </div>
                <h1 class="text-5xl md:text-6xl font-bold mb-6 bg-gradient-to-r from-accent-violet via-accent-purple to-accent-lavender bg-clip-text text-transparent">
                    Segnala un Problema
                </h1>
                <p class="text-xl text-gray-300 max-w-2xl mx-auto leading-relaxed">
                    Aiutaci a migliorare need2talk: segnala bug, problemi tecnici o invia suggerimenti
                </p>
            </div>

            <!-- Info Box -->
            <div class="bg-accent-violet/10 border border-accent-violet/30 rounded-xl p-6 mb-8">
                <div class="flex items-start gap-4">
                    <?= icon('info-circle', 'w-8 h-8 text-cool-cyan flex-shrink-0 mt-1') ?>
                    <div class="text-gray-200">
                        <h3 class="font-semibold text-white mb-2">La Tua Segnalazione è Importante</h3>
                        <p class="text-sm leading-relaxed">
                            Tutte le segnalazioni vengono esaminate dal nostro team entro <strong>24-48 ore</strong>.
                            Riceverai una conferma immediata e aggiornamenti sull'esito all'email indicata nel form.
                            Puoi segnalare problemi tecnici, bug, suggerimenti o qualsiasi difficoltà riscontrata sul sito.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Report Form -->
            <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20 shadow-2xl shadow-accent-purple/10">
                <form action="<?= url('api/report/submit') ?>" method="POST" id="reportForm" class="space-y-6">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                    <!-- Report Type -->
                    <div>
                        <label for="report_type" class="block text-sm font-semibold text-white mb-2">
                            <?= icon('list-ul', 'w-5 h-5 mr-2 text-accent-purple') ?>
                            Tipo di Segnalazione *
                        </label>
                        <select id="report_type" name="report_type" required
                                class="w-full px-4 py-3 bg-brand-midnight/50 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-accent-purple focus:border-transparent transition-all">
                            <option value="">Seleziona il tipo di problema...</option>
                            <option value="bug">🐛 Bug o Errore</option>
                            <option value="technical">⚙️ Problema Tecnico</option>
                            <option value="performance">🚀 Performance / Lentezza</option>
                            <option value="security">🔒 Problema di Sicurezza</option>
                            <option value="feature_request">💡 Richiesta Funzionalità</option>
                            <option value="content">📝 Problema con Contenuto</option>
                            <option value="abuse">🚫 Segnalazione Abuso</option>
                            <option value="general">💬 Segnalazione Generica</option>
                            <option value="other">🤔 Altro</option>
                        </select>
                    </div>

                    <!-- Page/Feature URL (Optional) -->
                    <div>
                        <label for="content_url" class="block text-sm font-semibold text-white mb-2">
                            <?= icon('link', 'w-5 h-5 mr-2 text-accent-purple') ?>
                            URL Pagina con Problema (Opzionale)
                        </label>
                        <input type="url" id="content_url" name="content_url"
                               placeholder="https://need2talk.it/pagina-con-problema"
                               class="w-full px-4 py-3 bg-brand-midnight/50 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:border-transparent transition-all">
                        <p class="text-xs text-gray-400 mt-2">
                            <?= icon('info-circle', 'w-5 h-5 mr-1') ?>
                            Copia e incolla l'URL della pagina dove hai riscontrato il problema (se applicabile)
                        </p>
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" class="block text-sm font-semibold text-white mb-2">
                            <?= icon('align-left', 'w-5 h-5 mr-2 text-accent-purple') ?>
                            Descrizione del Problema *
                        </label>
                        <textarea id="description" name="description" rows="6" required
                                  placeholder="Descrivi in dettaglio il problema che stai segnalando. Più informazioni fornisci, più rapidamente potremo intervenire."
                                  class="w-full px-4 py-3 bg-brand-midnight/50 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:border-transparent transition-all resize-none"></textarea>
                        <p class="text-xs text-gray-400 mt-2">
                            Minimo 20 caratteri - Massimo 2000 caratteri
                        </p>
                    </div>

                    <!-- Your Email -->
                    <div>
                        <label for="reporter_email" class="block text-sm font-semibold text-white mb-2">
                            <?= icon('envelope', 'w-5 h-5 mr-2 text-accent-purple') ?>
                            La Tua Email *
                        </label>
                        <input type="email" id="reporter_email" name="reporter_email" required
                               placeholder="tua@email.com"
                               class="w-full px-4 py-3 bg-brand-midnight/50 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-accent-purple focus:border-transparent transition-all">
                        <p class="text-xs text-gray-400 mt-2">
                            <?= icon('shield', 'w-5 h-5 mr-1') ?>
                            Useremo questa email solo per aggiornarti sulla tua segnalazione. Non verrà condivisa con nessuno.
                        </p>
                    </div>

                    <!-- Privacy Notice -->
                    <div class="bg-brand-midnight/50 border border-gray-600 rounded-lg p-4">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" id="privacy_accept" name="privacy_accept" required
                                   class="mt-1 w-5 h-5 text-accent-violet bg-gray-700 border-gray-600 rounded focus:ring-accent-purple focus:ring-2">
                            <span class="text-sm text-gray-300 leading-relaxed">
                                Accetto che i miei dati vengano elaborati secondo la
                                <a href="<?= url('legal/privacy') ?>" class="text-accent-purple hover:text-accent-purple underline">Privacy Policy</a>.
                                Comprendo che questa segnalazione verrà esaminata dal team di need2talk e che
                                potrebbe essere necessario contattarmi per ulteriori informazioni.
                            </span>
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex flex-col sm:flex-row gap-4 pt-4">
                        <button type="submit"
                                class="flex-1 flex items-center justify-center gap-2 px-8 py-4 bg-gradient-to-r from-energy-pink to-energy-rose hover:from-energy-rose hover:to-energy-pink text-white font-semibold text-base rounded-xl transition-all duration-300 transform hover:scale-105 shadow-xl shadow-energy-pink/30 border-2 border-energy-pink/40 hover:border-energy-pink/60 group">
                            <?= icon('paper-plane', 'w-5 h-5 group-hover:animate-pulse') ?>
                            <span>Invia Segnalazione</span>
                        </button>

                        <button type="reset"
                                class="flex-1 flex items-center justify-center gap-2 px-8 py-4 bg-gradient-to-r from-energy-pink/10 to-energy-rose/10 hover:from-energy-pink/20 hover:to-energy-rose/20 text-energy-pink hover:text-energy-rose font-medium text-base rounded-xl transition-all duration-300 border-2 border-energy-pink/40 hover:border-energy-pink/60">
                            <?= icon('redo', 'w-5 h-5') ?>
                            <span>Reset Form</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Alternative Contact Methods -->
            <div class="mt-12 grid md:grid-cols-2 gap-6">
                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-xl p-6 border border-accent-purple/20">
                    <h3 class="font-semibold text-white mb-3 flex items-center">
                        <?= icon('envelope', 'w-6 h-6 text-accent-purple mr-3') ?>
                        Contatto Diretto
                    </h3>
                    <p class="text-gray-300 text-sm mb-4 leading-relaxed">
                        Per questioni urgenti o segnalazioni che richiedono attenzione immediata:
                    </p>
                    <a href="<?= url('legal/contacts') ?>"
                       class="inline-flex items-center text-accent-purple hover:text-accent-purple transition-colors font-medium">
                        <?= icon('arrow-right', 'w-5 h-5 mr-2') ?>
                        Vai ai Contatti
                    </a>
                </div>

                <div class="bg-brand-charcoal/95 backdrop-blur-lg rounded-xl p-6 border border-energy-pink/20">
                    <h3 class="font-semibold text-white mb-3 flex items-center">
                        <?= icon('book', 'w-6 h-6 text-pink-400 mr-3') ?>
                        Regole della Community
                    </h3>
                    <p class="text-gray-300 text-sm mb-4 leading-relaxed">
                        Consulta le linee guida complete per capire cosa è consentito e cosa no:
                    </p>
                    <a href="<?= url('help/safety') ?>"
                       class="inline-flex items-center text-pink-400 hover:text-pink-300 transition-colors font-medium">
                        <?= icon('arrow-right', 'w-5 h-5 mr-2') ?>
                        Leggi le Regole
                    </a>
                </div>
            </div>

            <!-- What Happens Next -->
            <div class="mt-12 bg-brand-charcoal/95 backdrop-blur-lg rounded-2xl p-8 border border-accent-purple/20">
                <h2 class="text-2xl font-bold text-white mb-6 flex items-center">
                    <?= icon('question-circle', 'w-5 h-5 text-accent-purple mr-3') ?>
                    Cosa Succede Dopo la Segnalazione?
                </h2>

                <div class="space-y-6">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-10 h-10 bg-accent-violet/20 rounded-full flex items-center justify-center">
                            <span class="text-accent-purple font-bold">1</span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white mb-2">Ricezione e Conferma</h3>
                            <p class="text-gray-300 text-sm leading-relaxed">
                                Riceverai una conferma immediata via email che la tua segnalazione è stata ricevuta
                                e registrata nel nostro sistema.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-10 h-10 bg-accent-violet/20 rounded-full flex items-center justify-center">
                            <span class="text-accent-purple font-bold">2</span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white mb-2">Esame da Parte del Team</h3>
                            <p class="text-gray-300 text-sm leading-relaxed">
                                Un membro del nostro team di moderazione esaminerà la segnalazione entro 24-48 ore.
                                Casi urgenti vengono trattati con priorità assoluta.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-10 h-10 bg-accent-violet/20 rounded-full flex items-center justify-center">
                            <span class="text-accent-purple font-bold">3</span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white mb-2">Azione e Risposta</h3>
                            <p class="text-gray-300 text-sm leading-relaxed">
                                Se la segnalazione è fondata, prenderemo le misure appropriate (rimozione contenuto,
                                avviso, sospensione o ban). Ti aggiorneremo via email sull'esito della segnalazione.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Form Handler Script -->
    <script nonce="<?= csp_nonce() ?>">
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('reportForm');
        const submitBtn = form.querySelector('button[type="submit"]');

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Client-side validation
            const reportType = document.getElementById('report_type').value;
            const description = document.getElementById('description').value;
            const email = document.getElementById('reporter_email').value;
            const privacyAccept = document.getElementById('privacy_accept').checked;

            // Validate description length
            if (description.trim().length < 20) {
                showError('La descrizione deve contenere almeno 20 caratteri');
                return;
            }

            if (description.trim().length > 2000) {
                showError('La descrizione non può superare 2000 caratteri');
                return;
            }

            if (!reportType) {
                showError('Seleziona il tipo di segnalazione');
                return;
            }

            if (!email) {
                showError('Inserisci la tua email');
                return;
            }

            if (!privacyAccept) {
                showError('Devi accettare la Privacy Policy per continuare');
                return;
            }

            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2 inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Invio in corso...';

            try {
                const formData = new FormData(form);
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess(data.message || 'Segnalazione inviata con successo! Riceverai una conferma via email.');
                    form.reset();
                } else {
                    showError(data.message || 'Si è verificato un errore. Riprova.');
                }
            } catch (error) {
                showError('Errore di connessione. Verifica la tua connessione internet e riprova.');
            } finally {
                // Re-enable button
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<svg class="w-5 h-5 group-hover:animate-pulse" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M498.1 5.6c10.1 7 15.4 19.1 13.5 31.2l-64 416c-1.5 9.7-7.4 18.2-16 23s-18.9 5.4-28 1.6L284 427.7l-68.5 74.1c-8.9 9.7-22.9 12.9-35.2 8.1S160 493.2 160 480V396.4c0-4 1.5-7.8 4.2-10.7L331.8 202.8c5.8-6.3 5.6-16-.4-22s-15.7-6.4-22-.7L106 360.8 17.7 316.6C7.1 311.3 .3 300.7 0 288.9s5.9-22.8 16.1-28.7l448-256c10.7-6.1 23.9-5.5 34 1.4z"/></svg><span>Invia Segnalazione</span>';
            }
        });

        function showError(message) {
            // Remove existing alerts
            const existingAlert = document.querySelector('.alert-message');
            if (existingAlert) existingAlert.remove();

            // Create error alert
            const alert = document.createElement('div');
            alert.className = 'alert-message fixed top-24 right-4 z-50 max-w-md bg-red-500/90 backdrop-blur-lg text-white px-6 py-4 rounded-xl shadow-2xl border-2 border-red-400 animate-fade-in-down';
            alert.innerHTML = `
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 flex-shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c13.3 0 24 10.7 24 24V264c0 13.3-10.7 24-24 24s-24-10.7-24-24V152c0-13.3 10.7-24 24-24zM224 352a32 32 0 1 1 64 0 32 32 0 1 1 -64 0z"/></svg>
                    <div class="flex-1">
                        <div class="font-semibold mb-1">Errore</div>
                        <div class="text-sm">${message}</div>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="text-white/80 hover:text-white transition-colors">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512" fill="currentColor"><path d="M342.6 150.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L192 210.7 86.6 105.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L146.7 256 41.4 361.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L192 301.3 297.4 406.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L237.3 256 342.6 150.6z"/></svg>
                    </button>
                </div>
            `;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }

        function showSuccess(message) {
            // Remove existing alerts
            const existingAlert = document.querySelector('.alert-message');
            if (existingAlert) existingAlert.remove();

            // Create success alert
            const alert = document.createElement('div');
            alert.className = 'alert-message fixed top-24 right-4 z-50 max-w-md bg-gradient-to-r from-energy-pink to-energy-rose backdrop-blur-lg text-white px-6 py-4 rounded-xl shadow-2xl border-2 border-energy-pink/60 animate-fade-in-down';
            alert.innerHTML = `
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 flex-shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM369 209L241 337c-9.4 9.4-24.6 9.4-33.9 0l-64-64c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l47 47L335 175c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9z"/></svg>
                    <div class="flex-1">
                        <div class="font-semibold mb-1">Successo!</div>
                        <div class="text-sm">${message}</div>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="text-white/80 hover:text-white transition-colors">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512" fill="currentColor"><path d="M342.6 150.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L192 210.7 86.6 105.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L146.7 256 41.4 361.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L192 301.3 297.4 406.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L237.3 256 342.6 150.6z"/></svg>
                    </button>
                </div>
            `;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 7000);
        }
    });
    </script>

    <style nonce="<?= csp_nonce() ?>">
    @keyframes fade-in-down {
        0% {
            opacity: 0;
            transform: translateY(-20px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .animate-fade-in-down {
        animation: fade-in-down 0.3s ease-out;
    }
    </style>


<?php
$content = ob_get_clean();
// CONTENT END

// Render usando layout unificato
include APP_ROOT . '/app/Views/layouts/guest.php';
