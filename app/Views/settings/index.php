<?php
/**
 * Settings Index - Enterprise Galaxy
 *
 * Settings landing page with sidebar navigation (like Facebook/Twitter settings)
 *
 * FEATURES:
 * - Responsive sidebar (desktop: left sidebar, mobile: top tabs)
 * - Settings overview cards
 * - Quick actions
 * - Account status indicators (deletion pending, etc.)
 * - Modern dark theme with glassmorphism
 */

// ENTERPRISE V10.183: Hide FloatingRecorder on settings pages
$hideFloatingRecorder = true;

// Security check
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Variables from SettingsController
$title = $page_title ?? 'Impostazioni';
$description = 'Gestisca le impostazioni del suo account need2talk, privacy e preferenze';
$pageCSS = [];
$pageJS = []; // Index page doesn't need specific JS

// Render using post-login layout
ob_start();
?>

<!-- ENTERPRISE SETTINGS LAYOUT -->
<div class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 py-8">
    <div class="container mx-auto px-4 max-w-7xl">

        <!-- Page Header -->
        <div class="mb-8 pt-8">
            <h1 class="text-4xl font-bold text-white mb-2">Impostazioni</h1>
            <p class="text-gray-400">Gestisca il suo account, privacy e preferenze</p>
        </div>

        <!-- Settings Grid with Sidebar -->
        <div class="grid grid-cols-1 lg:grid-cols-6 gap-6">

            <!-- Sidebar Navigation (Desktop: Left sidebar, Mobile: Top tabs) -->
            <div class="lg:col-span-1">
                <nav class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-4 sticky top-24">
                    <ul class="space-y-2">
                        <li>
                            <a href="<?= url('/settings') ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-500/25 font-medium transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                                </svg>
                                <span>Panoramica</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= url('/settings/account') ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-gray-700/50 hover:text-white transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                <span>Account</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= url('/settings/privacy') ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-gray-700/50 hover:text-white transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                <span>Privacy</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= url('/settings/notifications') ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-gray-700/50 hover:text-white transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                </svg>
                                <span>Notifiche</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= url('/settings/security') ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-gray-700/50 hover:text-white transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                </svg>
                                <span>Sicurezza</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= url('/settings/data-export') ?>" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-gray-700/50 hover:text-white transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <span>Dati e Privacy</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>

            <!-- Main Content Area -->
            <div class="lg:col-span-5 space-y-6">

                <!-- Account Deletion Warning (if pending) -->
                <?php if (!empty($pending_deletion)): ?>
                <div class="bg-red-500/10 border border-red-500/50 rounded-2xl p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0">
                            <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-red-500 mb-2">Eliminazione Account Programmata</h3>
                            <p class="text-gray-300 mb-4">
                                Il suo account è programmato per l'eliminazione in data
                                <strong><?= date('j F Y', strtotime($pending_deletion['scheduled_deletion_at'])) ?></strong>.
                                Può annullare questa operazione in qualsiasi momento prima di tale data.
                            </p>
                            <a href="<?= url('/settings/data-export') ?>" class="inline-flex items-center gap-2 px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-xl transition-colors">
                                <span>Rivedi Eliminazione</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Settings Overview Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Account Card -->
                    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6 hover:border-purple-500/50 transition-all group">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-purple-600/20 rounded-xl flex items-center justify-center group-hover:bg-purple-600/30 transition-colors">
                                    <svg class="w-6 h-6 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-white">Account</h3>
                                    <p class="text-sm text-gray-400">Impostazioni profilo</p>
                                </div>
                            </div>
                        </div>
                        <p class="text-gray-300 text-sm mb-4">
                            Gestisca il suo nickname, email, avatar e dettagli account.
                        </p>
                        <a href="<?= url('/settings/account') ?>" class="inline-flex items-center gap-2 text-purple-500 hover:text-purple-400 font-medium transition-colors">
                            <span>Gestisci Account</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    </div>

                    <!-- Privacy Card -->
                    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6 hover:border-green-500/50 transition-all group">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-green-600/20 rounded-xl flex items-center justify-center group-hover:bg-green-600/30 transition-colors">
                                    <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-white">Privacy</h3>
                                    <p class="text-sm text-gray-400">Controllo visibilità</p>
                                </div>
                            </div>
                        </div>
                        <p class="text-gray-300 text-sm mb-4">
                            Controlli chi può vedere il suo profilo, post e attività. Visibilità granulare delle sezioni.
                        </p>
                        <a href="<?= url('/settings/privacy') ?>" class="inline-flex items-center gap-2 text-green-500 hover:text-green-400 font-medium transition-colors">
                            <span>Impostazioni Privacy</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    </div>

                    <!-- Notifications Card -->
                    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6 hover:border-purple-500/50 transition-all group">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-purple-600/20 rounded-xl flex items-center justify-center group-hover:bg-purple-600/30 transition-colors">
                                    <svg class="w-6 h-6 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-white">Notifiche</h3>
                                    <p class="text-sm text-gray-400">Email e push</p>
                                </div>
                            </div>
                        </div>
                        <p class="text-gray-300 text-sm mb-4">
                            Gestisca le preferenze per le notifiche email e push per richieste di amicizia, commenti e altro.
                        </p>
                        <a href="<?= url('/settings/notifications') ?>" class="inline-flex items-center gap-2 text-purple-500 hover:text-purple-400 font-medium transition-colors">
                            <span>Impostazioni Notifiche</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    </div>

                    <!-- Security Card -->
                    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6 hover:border-yellow-500/50 transition-all group">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-yellow-600/20 rounded-xl flex items-center justify-center group-hover:bg-yellow-600/30 transition-colors">
                                    <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-white">Sicurezza</h3>
                                    <p class="text-sm text-gray-400">Password e 2FA</p>
                                </div>
                            </div>
                        </div>
                        <p class="text-gray-300 text-sm mb-4">
                            Modifichi la sua password, abiliti l'autenticazione a due fattori e riveda le impostazioni di sicurezza.
                        </p>
                        <a href="<?= url('/settings/security') ?>" class="inline-flex items-center gap-2 text-yellow-500 hover:text-yellow-400 font-medium transition-colors">
                            <span>Impostazioni Sicurezza</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    </div>

                </div>

                <!-- GDPR Section -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <div class="flex items-start gap-4 mb-6">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 bg-red-600/20 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-xl font-semibold text-white mb-2">Diritti su Dati e Privacy (GDPR)</h3>
                            <p class="text-gray-300 mb-4">
                                Esporti i suoi dati o elimini permanentemente il suo account. Ha il controllo completo sulle sue informazioni.
                            </p>
                            <div class="flex flex-wrap gap-3">
                                <a href="<?= url('/settings/data-export') ?>" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-medium rounded-xl transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"></path>
                                    </svg>
                                    <span>Esporta i Miei Dati</span>
                                </a>
                                <a href="<?= url('/settings/data-export') ?>#delete-account" class="inline-flex items-center gap-2 px-6 py-3 bg-gray-700 hover:bg-gray-600 text-white font-medium rounded-xl transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                    <span>Elimina Account</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Info Summary -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Informazioni Account</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex justify-between items-center py-3 border-b border-gray-700">
                            <span class="text-gray-400">Nickname</span>
                            <span class="text-white font-medium"><?= htmlspecialchars($user['nickname']) ?></span>
                        </div>
                        <div class="flex justify-between items-center py-3 border-b border-gray-700">
                            <span class="text-gray-400">Email</span>
                            <span class="text-white font-medium"><?= htmlspecialchars($user['email']) ?></span>
                        </div>
                        <div class="flex justify-between items-center py-3 border-b border-gray-700">
                            <span class="text-gray-400">Tipo Account</span>
                            <span class="text-white font-medium">
                                <?= !empty($user['oauth_provider']) ? 'Google OAuth' : 'Standard' ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center py-3 border-b border-gray-700">
                            <span class="text-gray-400">Modifiche Nickname</span>
                            <span class="text-white font-medium">
                                <?= $nickname_change_info['change_count'] ?>
                                <?= $nickname_change_info['is_oauth_user'] ? '/ 1 (max)' : '' ?>
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require APP_ROOT . '/app/Views/layouts/app-post-login.php';
?>
