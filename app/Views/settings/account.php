<?php
/**
 * Account Settings - Enterprise Galaxy
 *
 * Account management page:
 * - Change nickname (OAuth users: max 1 cambio lifetime)
 * - Change email (with verification)
 * - Upload avatar
 * - Delete account link
 */

// ENTERPRISE V10.183: Hide FloatingRecorder on settings pages
$hideFloatingRecorder = true;

// Security check
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Variables from SettingsController
$title = $page_title ?? 'Account Settings';
$description = 'Manage your account details, nickname, email, and avatar';
$pageCSS = [];
$pageJS = [
    'utils/AvatarUploader',
    'pages/settings/account'
];

// Render using post-login layout
ob_start();
?>

<div class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 py-8">
    <div class="container mx-auto px-4 max-w-7xl pt-8">

        <!-- Breadcrumb -->
        <nav class="mb-6" aria-label="Breadcrumb">
            <ol class="flex items-center gap-2 text-sm">
                <li><a href="<?= url('/settings') ?>" class="text-gray-400 hover:text-white transition-colors">Settings</a></li>
                <li class="text-gray-600">/</li>
                <li class="text-white font-medium">Account</li>
            </ol>
        </nav>

        <div class="grid grid-cols-1 lg:grid-cols-6 gap-6">

            <!-- Sidebar Navigation -->
            <?php include APP_ROOT . '/app/Views/settings/_sidebar.php'; ?>

            <!-- Main Content -->
            <div class="lg:col-span-5 space-y-6">

                <!-- Page Header -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h1 class="text-3xl font-bold text-white mb-2">Account Settings</h1>
                    <p class="text-gray-400">Manage your nickname, email, avatar, and account details</p>
                </div>

                <!-- Success/Error Messages -->
                <div id="message-container"></div>

                <!-- Avatar Upload Section -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <div class="flex items-start gap-6">
                        <div class="flex-shrink-0">
                            <div class="relative group">
                                <img
                                    id="avatar-preview"
                                    src="<?= $avatar_url ?>"
                                    alt="Avatar"
                                    class="w-32 h-32 rounded-full object-cover border-4 border-gray-700 group-hover:border-purple-500 transition-colors"
                                >
                                <div class="absolute inset-0 bg-black/50 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer" onclick="document.getElementById('avatar-input').click()">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-xl font-semibold text-white mb-2">Immagine del Profilo</h3>
                            <?php if ($is_google_avatar): ?>
                            <p class="text-gray-400 mb-4 text-sm">
                                <span class="inline-flex items-center gap-1 text-purple-400">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z"/>
                                    </svg>
                                    <span>Stai usando l'avatar di Google</span>
                                </span>
                            </p>
                            <?php endif; ?>
                            <p class="text-gray-400 mb-4">
                                <strong>Clicca sulla foto</strong>, carica una nuova immagine (JPG, PNG o WebP. Max 2MB) e <strong>premi "Salva"</strong> per confermare. La foto precedente verrà rimossa.
                            </p>
                            <form id="avatar-upload-form" enctype="multipart/form-data">
                                <input
                                    type="file"
                                    id="avatar-input"
                                    name="avatar"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="hidden"
                                >
                                <button
                                    type="button"
                                    id="upload-avatar-btn"
                                    class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg shadow-purple-500/25 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                    </svg>
                                    <span>Seleziona Foto</span>
                                </button>
                            </form>
                            <div id="avatar-upload-progress" class="hidden mt-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 bg-gray-700 rounded-full h-2 overflow-hidden">
                                        <div id="avatar-progress-bar" class="bg-gradient-to-r from-purple-600 to-pink-600 h-full transition-all" style="width: 0%"></div>
                                    </div>
                                    <span id="avatar-progress-text" class="text-sm text-gray-400">0%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Nickname Section -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h3 class="text-xl font-semibold text-white mb-4">Nickname</h3>

                    <!-- ENTERPRISE: Max 1 cambio nickname per TUTTI gli utenti (policy anti-abuse) -->
                    <div class="mb-4 p-4 bg-yellow-500/10 border border-yellow-500/50 rounded-xl">
                        <div class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-yellow-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div class="flex-1 text-sm">
                                <p class="text-yellow-300 font-medium mb-1">Limite Cambio Nickname</p>
                                <p class="text-gray-300">
                                    Puoi cambiare il tuo nickname <strong>una volta sola</strong> per motivi di sicurezza e tracciabilità.
                                    <?php if ($nickname_change_info['change_count'] >= 1): ?>
                                        <span class="text-red-400 font-semibold">✗ Hai già usato il tuo cambio nickname.</span>
                                    <?php else: ?>
                                        <span class="text-green-400 font-semibold">✓ Ti rimane 1 cambio disponibile.</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <form id="nickname-form">
                        <div class="mb-4">
                            <label for="nickname" class="block text-gray-300 mb-2">Nickname</label>
                            <div class="relative">
                                <input
                                    type="text"
                                    id="nickname"
                                    name="nickname"
                                    value="<?= htmlspecialchars($user['nickname']) ?>"
                                    class="w-full px-4 py-3 <?= !$nickname_change_info['can_change'] ? 'bg-gray-700/30 text-gray-400 cursor-not-allowed' : 'bg-gray-700/50 text-white' ?> border border-gray-600 rounded-xl placeholder-gray-400 focus:outline-none focus:border-purple-500 transition-colors"
                                    placeholder="Inserisci nickname"
                                    maxlength="50"
                                    <?= !$nickname_change_info['can_change'] ? 'readonly disabled data-locked="true"' : '' ?>
                                >
                                <?php if (!$nickname_change_info['can_change']): ?>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-4">
                                    <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                </div>
                                <?php endif; ?>
                            </div>
                            <p class="text-xs text-gray-400 mt-2">
                                3-50 caratteri, solo lettere, numeri e underscore
                            </p>

                            <!-- Success Message (hidden by default) -->
                            <div id="nickname-success" class="hidden mt-2 p-3 bg-green-500/10 border border-green-500/50 rounded-lg">
                                <p class="text-sm text-green-400 flex items-center gap-2">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    <span id="nickname-success-text"></span>
                                </p>
                            </div>

                            <!-- Error Message (hidden by default) -->
                            <div id="nickname-error" class="hidden mt-2 p-3 bg-red-500/10 border border-red-500/50 rounded-lg">
                                <p class="text-sm text-red-400 flex items-center gap-2">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                    </svg>
                                    <span id="nickname-error-text"></span>
                                </p>
                            </div>
                        </div>

                        <?php if ($nickname_change_info['can_change']): ?>
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg shadow-purple-500/25 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Salva Nickname</span>
                        </button>
                        <?php else: ?>
                        <p class="text-red-400 text-sm">
                            Non puoi più cambiare il nickname (limite raggiunto).
                        </p>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Email Section -->
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h3 class="text-xl font-semibold text-white mb-4">Indirizzo Email</h3>

                    <?php if ($nickname_change_info['is_oauth_user']): ?>
                    <!-- OAuth User: Email managed by provider (Google, Facebook, etc.) -->
                    <div class="mb-4 p-4 bg-blue-500/10 border border-blue-500/50 rounded-xl">
                        <div class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z"/>
                            </svg>
                            <div class="flex-1 text-sm">
                                <p class="text-blue-300 font-medium mb-1">Email Gestita da <?= htmlspecialchars(ucfirst($nickname_change_info['oauth_provider'] ?? 'Provider')) ?></p>
                                <p class="text-gray-300">
                                    Stai usando accesso con <strong><?= htmlspecialchars(ucfirst($nickname_change_info['oauth_provider'] ?? 'OAuth')) ?></strong>.
                                    L'indirizzo email è gestito dal tuo account <?= htmlspecialchars(ucfirst($nickname_change_info['oauth_provider'] ?? 'provider')) ?> e non può essere modificato qui.
                                </p>
                                <p class="text-gray-400 mt-2">
                                    Per cambiare email, aggiorna il tuo profilo su <?= htmlspecialchars(ucfirst($nickname_change_info['oauth_provider'] ?? 'provider')) ?>.com.
                                    Al prossimo login, l'email verrà aggiornata automaticamente.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Disabled Email Input (Read-Only) -->
                    <div class="mb-4">
                        <label for="email" class="block text-gray-300 mb-2">Email Attuale</label>
                        <div class="relative">
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= htmlspecialchars($user['email']) ?>"
                                class="w-full px-4 py-3 bg-gray-700/30 border border-gray-600/50 rounded-xl text-gray-400 cursor-not-allowed"
                                readonly
                                disabled
                            >
                            <div class="absolute inset-y-0 right-0 flex items-center pr-4">
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
                            </svg>
                            Gestita da <?= htmlspecialchars(ucfirst($nickname_change_info['oauth_provider'] ?? 'OAuth Provider')) ?>
                        </p>
                    </div>

                    <?php else: ?>
                    <!-- Local User: Email change enabled -->
                    <form id="email-form">
                        <div class="mb-4">
                            <label for="email" class="block text-gray-300 mb-2">Nuova Email</label>
                            <input
                                type="email"
                                id="email"
                                name="new_email"
                                value=""
                                class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 transition-colors"
                                placeholder="nuova.email@esempio.it"
                            >
                            <p class="text-xs text-gray-400 mt-2">
                                Email attuale: <strong><?= htmlspecialchars($user['email']) ?></strong>
                            </p>
                            <p class="text-xs text-gray-400 mt-1">
                                Verrà inviata un'email di verifica al nuovo indirizzo. Puoi cambiare email <strong>una volta ogni 30 giorni</strong>.
                            </p>
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-semibold rounded-xl transition-all duration-300 transform hover:scale-105 shadow-lg shadow-purple-500/25"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            <span>Cambia Email</span>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Danger Zone -->
                <div class="bg-red-500/10 backdrop-blur-lg rounded-2xl border border-red-500/50 p-6">
                    <h3 class="text-xl font-semibold text-red-500 mb-2">Zona Pericolosa</h3>
                    <p class="text-gray-300 mb-4">
                        Elimina permanentemente il tuo account e tutti i dati associati. Questa azione non può essere annullata.
                    </p>
                    <a
                        href="<?= url('/settings/data-export') ?>#delete-account"
                        class="inline-flex items-center gap-2 px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-xl transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                        <span>Elimina Account</span>
                    </a>
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
