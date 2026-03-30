<?php
/**
 * Security Settings - Enterprise Galaxy
 * Password change, 2FA (future)
 */

// ENTERPRISE V10.183: Hide FloatingRecorder on settings pages
$hideFloatingRecorder = true;

if (!defined('APP_ROOT')) exit('Accesso negato');

$title = $page_title ?? 'Impostazioni di Sicurezza';
$description = 'Modifica la password e gestisci le impostazioni di sicurezza';
$pageCSS = [];
$pageJS = ['pages/settings/security'];

ob_start();
?>

<div class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 py-8">
    <div class="container mx-auto px-4 max-w-7xl pt-8">
        <nav class="mb-6"><ol class="flex items-center gap-2 text-sm"><li><a href="<?= url('/settings') ?>" class="text-gray-400 hover:text-white">Impostazioni</a></li><li class="text-gray-600">/</li><li class="text-white font-medium">Sicurezza</li></ol></nav>

        <div class="grid grid-cols-1 lg:grid-cols-6 gap-6">
            <?php include APP_ROOT . '/app/Views/settings/_sidebar.php'; ?>

            <div class="lg:col-span-5 space-y-6">
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h1 class="text-3xl font-bold text-white mb-2">Impostazioni di Sicurezza</h1>
                    <p class="text-gray-400">Gestisci la tua password e le preferenze di sicurezza</p>
                </div>

                <div id="message-container"></div>

                <!-- Change Password -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <div class="flex items-start gap-4 mb-6">
                        <div class="w-12 h-12 bg-yellow-600/20 rounded-xl flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-xl font-semibold text-white mb-2">Cambia Password</h3>
                            <p class="text-gray-400">Aggiorna regolarmente la tua password per mantenere il tuo account sicuro</p>
                        </div>
                    </div>

                    <?php if (!$has_password): ?>
                    <div class="p-4 bg-purple-500/10 border border-purple-500/50 rounded-xl mb-6">
                        <p class="text-blue-300 text-sm">Stai utilizzando Google OAuth. Imposta una password per abilitare il login con password come backup.</p>
                    </div>
                    <?php endif; ?>

                    <form id="password-form" class="space-y-4">
                        <!-- Hidden username field for accessibility (password managers) -->
                        <input type="text" name="username" autocomplete="username" value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="sr-only" aria-hidden="true" tabindex="-1" readonly>

                        <?php if ($has_password): ?>
                        <div>
                            <label for="current_password" class="block text-gray-300 mb-2">Password Attuale</label>
                            <input type="password" id="current_password" name="current_password" autocomplete="current-password" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white focus:outline-none focus:border-purple-500" required>
                        </div>
                        <?php endif; ?>

                        <div>
                            <label for="new_password" class="block text-gray-300 mb-2">Nuova Password</label>
                            <input type="password" id="new_password" name="new_password" autocomplete="new-password" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white focus:outline-none focus:border-purple-500" required>
                            <p class="text-xs text-gray-400 mt-2">Minimo 8 caratteri, includi maiuscole, minuscole e un numero</p>
                            <div id="password-strength" class="mt-2 hidden">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-gray-700 rounded-full h-2"><div id="strength-bar" class="h-full rounded-full transition-all" style="width: 0%"></div></div>
                                    <span id="strength-text" class="text-xs font-medium"></span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label for="confirm_password" class="block text-gray-300 mb-2">Conferma Nuova Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white focus:outline-none focus:border-purple-500" required>
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 bg-yellow-600 hover:bg-yellow-700 text-white font-medium rounded-xl transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                <span><?= $has_password ? 'Cambia Password' : 'Imposta Password' ?></span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- 2FA (Future Feature) -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6 opacity-60">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="w-12 h-12 bg-green-600/20 rounded-xl flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-xl font-semibold text-white mb-2">Autenticazione a Due Fattori (2FA)</h3>
                            <p class="text-gray-400 mb-4">Aggiungi un livello extra di sicurezza al tuo account (Prossimamente)</p>
                            <div class="flex items-center gap-2 text-sm">
                                <span class="px-3 py-1 bg-gray-700 rounded-full text-gray-400">Stato: Non Disponibile</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Login History (Future Feature) -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6 opacity-60">
                    <h3 class="text-lg font-semibold text-white mb-2">Cronologia Accessi</h3>
                    <p class="text-gray-400 text-sm">Visualizza l'attività di accesso recente (Prossimamente)</p>
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
