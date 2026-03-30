<?php
/**
 * NEED2TALK - PWA OFFLINE PAGE
 * Shown when user is offline and page is not cached
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

$title = 'Sei Offline - need2talk';
$description = 'Sembra che tu sia offline. Riconnettiti per continuare a usare need2talk.';

// CONTENT START
ob_start();
?>

<div class="min-h-screen bg-gradient-to-b from-brand-midnight via-brand-slate to-brand-midnight flex items-center justify-center px-4">
    <div class="max-w-md w-full text-center">

        <!-- Offline Icon -->
        <div class="mb-8">
            <div class="relative inline-block">
                <div class="w-32 h-32 mx-auto bg-gradient-to-br from-gray-700 to-gray-800 rounded-full flex items-center justify-center shadow-xl">
                    <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 2.829a5 5 0 01-1.414-7.072m0 0L9.879 5.636m-2.829 2.828a9 9 0 0112.728 0M3 3l3.586 3.586m0 0a9 9 0 000 12.728l2.829-2.829"/>
                    </svg>
                </div>
                <!-- Pulsing ring -->
                <div class="absolute inset-0 rounded-full border-2 border-gray-500/30 animate-ping"></div>
            </div>
        </div>

        <!-- Title -->
        <h1 class="text-3xl font-bold text-white mb-4">
            Sei Offline
        </h1>

        <!-- Description -->
        <p class="text-gray-400 mb-8 leading-relaxed">
            Sembra che tu non sia connesso a Internet.
            <br>Controlla la tua connessione e riprova.
        </p>

        <!-- Connection Status -->
        <div id="connection-status" class="mb-8 p-4 rounded-xl bg-gray-800/50 border border-gray-700">
            <div class="flex items-center justify-center gap-3">
                <div id="status-indicator" class="w-3 h-3 rounded-full bg-red-500 animate-pulse"></div>
                <span id="status-text" class="text-gray-300">Non connesso</span>
            </div>
        </div>

        <!-- Actions -->
        <div class="space-y-4">
            <button onclick="location.reload()"
                    class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 bg-accent-violet hover:bg-accent-violet/90 text-white font-semibold rounded-xl transition-all shadow-lg hover:scale-105 transform">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Riprova
            </button>

            <a href="/"
               class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 border-2 border-gray-600 text-gray-300 hover:bg-gray-800 font-medium rounded-xl transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Torna alla Home
            </a>
        </div>

        <!-- Tips -->
        <div class="mt-12 text-left">
            <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Suggerimenti</h2>
            <ul class="space-y-3 text-sm text-gray-500">
                <li class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-gray-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Controlla che il Wi-Fi o i dati mobili siano attivi
                </li>
                <li class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-gray-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Prova a disattivare e riattivare la modalità aereo
                </li>
                <li class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-gray-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Se usi VPN, prova a disconnetterla
                </li>
            </ul>
        </div>

    </div>
</div>

<script>
// Monitor connection status
function updateConnectionStatus() {
    const indicator = document.getElementById('status-indicator');
    const text = document.getElementById('status-text');

    if (navigator.onLine) {
        indicator.classList.remove('bg-red-500');
        indicator.classList.add('bg-green-500');
        text.textContent = 'Connessione ripristinata!';

        // Auto-reload after connection restored
        setTimeout(() => {
            location.reload();
        }, 1500);
    } else {
        indicator.classList.remove('bg-green-500');
        indicator.classList.add('bg-red-500');
        text.textContent = 'Non connesso';
    }
}

window.addEventListener('online', updateConnectionStatus);
window.addEventListener('offline', updateConnectionStatus);

// Initial check
updateConnectionStatus();
</script>

<?php
$content = ob_get_clean();
// Render usando layout unificato
include APP_ROOT . '/app/Views/layouts/guest.php';
