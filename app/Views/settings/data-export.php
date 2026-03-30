<?php
/**
 * Data Export & GDPR Compliance - Enterprise Galaxy
 * GDPR Article 17 (Right to be Forgotten) + Article 20 (Data Portability)
 */

// ENTERPRISE V10.183: Hide FloatingRecorder on settings pages
$hideFloatingRecorder = true;

if (!defined('APP_ROOT')) exit('Accesso negato');

$title = $page_title ?? 'Dati e Diritti sulla Privacy';
$description = 'Esporta i tuoi dati o elimina il tuo account (conforme al GDPR)';
$pageCSS = [];
$pageJS = ['pages/settings/data-export'];

ob_start();
?>

<div class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 py-8">
    <div class="container mx-auto px-4 max-w-7xl pt-8">
        <nav class="mb-6"><ol class="flex items-center gap-2 text-sm"><li><a href="<?= url('/settings') ?>" class="text-gray-400 hover:text-white">Impostazioni</a></li><li class="text-gray-600">/</li><li class="text-white font-medium">Dati e Privacy</li></ol></nav>

        <div class="grid grid-cols-1 lg:grid-cols-6 gap-6">
            <?php include APP_ROOT . '/app/Views/settings/_sidebar.php'; ?>

            <div class="lg:col-span-5 space-y-6">
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h1 class="text-3xl font-bold text-white mb-2">Dati e Diritti sulla Privacy</h1>
                    <p class="text-gray-400">Esportazione dati ed eliminazione account conforme al GDPR</p>
                </div>

                <div id="message-container"></div>

                <!-- GDPR Info Banner -->
                <div class="bg-purple-500/10 border border-purple-500/50 rounded-2xl p-6">
                    <div class="flex items-start gap-4">
                        <svg class="w-6 h-6 text-blue-400 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-blue-300 mb-2">I Tuoi Diritti sui Dati (GDPR)</h3>
                            <p class="text-gray-300 text-sm mb-2">Secondo il GDPR, hai il diritto di:</p>
                            <ul class="space-y-1 text-sm text-gray-300">
                                <li class="flex items-center gap-2"><svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Eliminare il tuo account in modo permanente (Articolo 17 - Diritto all'Oblio)</li>
                                <li class="flex items-center gap-2"><svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Periodo di grazia di 30 giorni per annullare l'eliminazione</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Delete Account Section -->
                <div id="delete-account" class="bg-red-500/10 backdrop-blur-lg rounded-2xl border border-red-500/50 p-6">
                    <div class="flex items-start gap-4 mb-6">
                        <div class="w-12 h-12 bg-red-600/20 rounded-xl flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-xl font-semibold text-red-500 mb-2">Elimina Account</h3>
                            <p class="text-gray-300 mb-4">Elimina permanentemente il tuo account e tutti i dati associati</p>
                        </div>
                    </div>

                    <?php if (!empty($pending_deletion)): ?>
                    <!-- Deletion Pending -->
                    <div class="bg-red-600/20 border border-red-500/50 rounded-xl p-6 mb-6">
                        <div class="flex items-start gap-3 mb-4">
                            <svg class="w-6 h-6 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-red-400 mb-2">Eliminazione Account Programmata</h4>
                                <p class="text-gray-300 mb-3">
                                    Il tuo account verrà eliminato permanentemente il:<br>
                                    <strong class="text-white text-lg"><?= date('l, F j, Y \a\t g:i A', strtotime($pending_deletion['scheduled_deletion_at'])) ?></strong>
                                </p>
                                <p class="text-gray-400 text-sm mb-4">
                                    Durante il periodo di grazia di 30 giorni, puoi annullare questa richiesta e ripristinare il tuo account.
                                </p>
                            </div>
                        </div>
                        <button id="cancel-deletion-btn" class="inline-flex items-center gap-2 px-6 py-3 bg-green-600 hover:bg-green-700 text-white font-medium rounded-xl transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <span>Annulla Eliminazione e Ripristina Account</span>
                        </button>
                    </div>
                    <?php else: ?>
                    <!-- No Deletion Pending -->
                    <div class="bg-gray-700/30 rounded-xl p-6 mb-6">
                        <h4 class="text-white font-medium mb-3">Cosa succede quando elimini il tuo account:</h4>
                        <ul class="space-y-2 text-sm text-gray-300 mb-4">
                            <li class="flex items-start gap-2"><svg class="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Il tuo profilo verrà immediatamente nascosto</li>
                            <li class="flex items-start gap-2"><svg class="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Tutti i post e i contenuti verranno rimossi</li>
                            <li class="flex items-start gap-2"><svg class="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Le amicizie e le connessioni verranno eliminate</li>
                            <li class="flex items-start gap-2"><svg class="w-4 h-4 text-yellow-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>Periodo di grazia di 30 giorni prima dell'eliminazione permanente</li>
                            <li class="flex items-start gap-2"><svg class="w-4 h-4 text-green-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Puoi annullare in qualsiasi momento durante il periodo di grazia</li>
                        </ul>
                        <p class="text-sm text-red-400 font-medium">⚠️ Questa azione non può essere annullata dopo il periodo di grazia</p>
                    </div>

                    <form id="delete-account-form" class="space-y-4">
                        <!-- Hidden username field for accessibility (password managers) -->
                        <input type="text" name="username" autocomplete="username" value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="sr-only" aria-hidden="true" tabindex="-1" readonly>

                        <div>
                            <label for="deletion-reason" class="block text-gray-300 mb-2">Motivo dell'abbandono (facoltativo)</label>
                            <textarea id="deletion-reason" name="reason" rows="3" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white focus:outline-none focus:border-red-500" placeholder="Aiutaci a migliorare dicendoci perché te ne vai..."></textarea>
                        </div>

                        <?php if ($has_password): ?>
                        <div>
                            <label for="deletion-password" class="block text-gray-300 mb-2">Conferma Password</label>
                            <input type="password" id="deletion-password" name="confirm_password" autocomplete="current-password" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white focus:outline-none focus:border-red-500" required placeholder="Inserisci la tua password per confermare">
                        </div>
                        <?php endif; ?>

                        <button type="submit" id="delete-account-btn" class="inline-flex items-center gap-2 px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-xl transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            <span>Elimina il Mio Account</span>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrf-token" value="<?= $csrfToken ?? '' ?>">

<?php
$content = ob_get_clean();
require APP_ROOT . '/app/Views/layouts/app-post-login.php';
?>
