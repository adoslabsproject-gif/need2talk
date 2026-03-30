<?php
/**
 * Privacy Settings - Enterprise Galaxy V5.7
 *
 * Simplified privacy control page:
 * - Show online status
 * - Allow friend requests
 * - Allow direct messages from non-friends
 *
 * NOTE: Profile tabs (panoramica, diario, timeline, etc.) are ALWAYS private.
 * Audio gallery visibility is controlled per-post, not globally.
 */

// ENTERPRISE V10.183: Hide FloatingRecorder on settings pages
$hideFloatingRecorder = true;

// Security check
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Variables from SettingsController
$title = $page_title ?? 'Impostazioni Privacy';
$description = 'Controlli le impostazioni di privacy del Suo account';
$pageCSS = [];
$pageJS = ['pages/settings/privacy'];

// Render using post-login layout
ob_start();
?>

<div class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 py-8">
    <div class="container mx-auto px-4 max-w-7xl pt-8">

        <!-- Breadcrumb -->
        <nav class="mb-6" aria-label="Breadcrumb">
            <ol class="flex items-center gap-2 text-sm">
                <li><a href="<?= url('/settings') ?>" class="text-gray-400 hover:text-white transition-colors">Impostazioni</a></li>
                <li class="text-gray-600">/</li>
                <li class="text-white font-medium">Privacy</li>
            </ol>
        </nav>

        <div class="grid grid-cols-1 lg:grid-cols-6 gap-6">

            <!-- Sidebar Navigation -->
            <?php include APP_ROOT . '/app/Views/settings/_sidebar.php'; ?>

            <!-- Main Content -->
            <div class="lg:col-span-5 space-y-6">

                <!-- Page Header -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h1 class="text-3xl font-bold text-white mb-2">Impostazioni Privacy</h1>
                    <p class="text-gray-400">Controlli le impostazioni di privacy del Suo account</p>
                </div>

                <!-- Success/Error Messages -->
                <div id="message-container"></div>

                <!-- Privacy Settings -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h3 class="text-xl font-semibold text-white mb-6">Privacy Generale</h3>

                    <form id="privacy-form">
                        <div class="space-y-4">

                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer">
                                <div class="flex-1">
                                    <div class="text-white font-medium">Mostra Stato Online</div>
                                    <div class="text-sm text-gray-400">Permette agli altri di vedere quando è online</div>
                                </div>
                                <input type="checkbox" name="show_online_status" <?= ($settings['show_online_status'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer">
                                <div class="flex-1">
                                    <div class="text-white font-medium">Consenti Richieste di Amicizia</div>
                                    <div class="text-sm text-gray-400">Permette agli altri di inviarLe richieste di amicizia</div>
                                </div>
                                <input type="checkbox" name="allow_friend_requests" <?= ($settings['allow_friend_requests'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer">
                                <div class="flex-1">
                                    <div class="text-white font-medium">Consenti Messaggi Diretti</div>
                                    <div class="text-sm text-gray-400">Permette ai non-amici di inviarLe messaggi</div>
                                </div>
                                <input type="checkbox" name="allow_direct_messages" <?= ($settings['allow_direct_messages'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                        </div>

                        <div class="mt-6 pt-6 border-t border-gray-700">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-medium rounded-xl transition-colors"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <span>Salva Impostazioni</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Crittografia e Protezione Dati -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-green-500/30 p-6">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-10 h-10 rounded-xl bg-green-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-white">Crittografia End-to-End</h3>
                    </div>

                    <div class="space-y-4">
                        <!-- Diario Emotivo -->
                        <div class="p-4 bg-green-500/10 rounded-xl border border-green-500/20">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-green-400 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <div>
                                    <div class="text-white font-medium">Diario Emotivo - Crittografia E2E</div>
                                    <div class="text-sm text-gray-400 mt-1">Le Sue note personali sono crittografate end-to-end. Solo Lei possiede la chiave di decrittazione. Nemmeno noi possiamo leggerle.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Messaggi Diretti -->
                        <div class="p-4 bg-green-500/10 rounded-xl border border-green-500/20">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-green-400 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <div>
                                    <div class="text-white font-medium">Messaggi Diretti - Crittografia E2E</div>
                                    <div class="text-sm text-gray-400 mt-1">Le conversazioni private sono crittografate end-to-end. I messaggi scadono automaticamente dopo 1 ora e vengono eliminati definitivamente entro 7 giorni.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Chat Room -->
                        <div class="p-4 bg-blue-500/10 rounded-xl border border-blue-500/20">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-blue-400 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                                <div>
                                    <div class="text-white font-medium">Chat Room - Messaggi Temporanei</div>
                                    <div class="text-sm text-gray-400 mt-1">I messaggi nelle stanze pubbliche sono temporanei e non vengono conservati a lungo termine. Le conversazioni di gruppo sono pensate per lo scambio emotivo in tempo reale.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 p-4 bg-gray-700/30 rounded-xl">
                        <p class="text-sm text-gray-400">
                            <strong class="text-white">La Sua privacy è la nostra priorità.</strong> Non conserviamo le conversazioni private per più di 7 giorni e, grazie alla crittografia end-to-end, anche durante questo periodo i contenuti risultano illeggibili senza la Sua chiave personale.
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Hidden CSRF Token -->
<input type="hidden" id="csrf-token" value="<?= $csrfToken ?? '' ?>">

<?php
$content = ob_get_clean();
require APP_ROOT . '/app/Views/layouts/app-post-login.php';
?>
