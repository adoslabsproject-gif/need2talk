<?php
/**
 * Notification Settings - Enterprise Galaxy V4
 *
 * Notification preferences:
 * - In-app notifications (campanella) - granular controls
 * - Newsletter email (opt-in GDPR compliant)
 *
 * NOTA: Le notifiche email per attività social (commenti, like, amicizie)
 * sono state rimosse intenzionalmente - sono spam e non GDPR-friendly.
 */

// ENTERPRISE V10.183: Hide FloatingRecorder on settings pages
$hideFloatingRecorder = true;

/*
 * Le notifiche in-app via WebSocket sono istantanee e più rispettose.
 */

if (!defined('APP_ROOT')) exit('Accesso negato');

$title = $page_title ?? 'Impostazioni Notifiche';
$description = 'Gestisci le preferenze di notifica';
$pageCSS = [];
$pageJS = ['pages/settings/notifications'];

ob_start();
?>

<div class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 py-8">
    <div class="container mx-auto px-4 max-w-7xl pt-8">

        <nav class="mb-6"><ol class="flex items-center gap-2 text-sm"><li><a href="<?= url('/settings') ?>" class="text-gray-400 hover:text-white">Impostazioni</a></li><li class="text-gray-600">/</li><li class="text-white font-medium">Notifiche</li></ol></nav>

        <div class="grid grid-cols-1 lg:grid-cols-6 gap-6">
            <?php include APP_ROOT . '/app/Views/settings/_sidebar.php'; ?>

            <div class="lg:col-span-5 space-y-6">
                <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6">
                    <h1 class="text-3xl font-bold text-white mb-2">Impostazioni Notifiche</h1>
                    <p class="text-gray-400">Scegli quali notifiche ricevere nella campanella</p>
                </div>

                <div id="message-container"></div>

                <form id="notifications-form">
                    <!-- In-App Notifications (Campanella) -->
                    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6 space-y-6">

                        <!-- Header -->
                        <div class="flex items-start gap-4 pb-6 border-b border-gray-700">
                            <div class="w-14 h-14 bg-gradient-to-br from-purple-600/30 to-pink-600/30 rounded-2xl flex items-center justify-center flex-shrink-0">
                                <svg class="w-7 h-7 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h2 class="text-xl font-bold text-white mb-1">Notifiche In-App</h2>
                                <p class="text-gray-400 text-sm">Ricevi notifiche istantanee nella campanella quando accadono cose interessanti</p>
                            </div>
                        </div>

                        <!-- Granular In-App Controls -->
                        <div class="space-y-3">

                            <!-- Comments -->
                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-blue-500/20 rounded-lg flex items-center justify-center group-hover:bg-blue-500/30 transition-colors">
                                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">Commenti</div>
                                        <div class="text-sm text-gray-400">Quando qualcuno commenta i tuoi post</div>
                                    </div>
                                </div>
                                <input type="checkbox" name="notify_comments" <?= ($settings['notify_comments'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <!-- Replies -->
                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-cyan-500/20 rounded-lg flex items-center justify-center group-hover:bg-cyan-500/30 transition-colors">
                                        <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">Risposte</div>
                                        <div class="text-sm text-gray-400">Quando qualcuno risponde ai tuoi commenti</div>
                                    </div>
                                </div>
                                <input type="checkbox" name="notify_replies" <?= ($settings['notify_replies'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <!-- Reactions -->
                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-pink-500/20 rounded-lg flex items-center justify-center group-hover:bg-pink-500/30 transition-colors">
                                        <svg class="w-5 h-5 text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">Reazioni</div>
                                        <div class="text-sm text-gray-400">Quando qualcuno reagisce ai tuoi post</div>
                                    </div>
                                </div>
                                <input type="checkbox" name="notify_reactions" <?= ($settings['notify_reactions'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <!-- Comment Likes -->
                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-yellow-500/20 rounded-lg flex items-center justify-center group-hover:bg-yellow-500/30 transition-colors">
                                        <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">Like ai Commenti</div>
                                        <div class="text-sm text-gray-400">Quando qualcuno mette like ai tuoi commenti</div>
                                    </div>
                                </div>
                                <input type="checkbox" name="notify_comment_likes" <?= ($settings['notify_comment_likes'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <!-- Mentions -->
                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-indigo-500/20 rounded-lg flex items-center justify-center group-hover:bg-indigo-500/30 transition-colors">
                                        <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">Menzioni</div>
                                        <div class="text-sm text-gray-400">Quando qualcuno ti menziona con @</div>
                                    </div>
                                </div>
                                <input type="checkbox" name="notify_mentions" <?= ($settings['notify_mentions'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <!-- Friend Requests -->
                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-green-500/20 rounded-lg flex items-center justify-center group-hover:bg-green-500/30 transition-colors">
                                        <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">Richieste di Amicizia</div>
                                        <div class="text-sm text-gray-400">Quando qualcuno ti invia una richiesta di amicizia</div>
                                    </div>
                                </div>
                                <input type="checkbox" name="notify_friend_requests" <?= ($settings['notify_friend_requests'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <!-- Friend Accepted -->
                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-emerald-500/20 rounded-lg flex items-center justify-center group-hover:bg-emerald-500/30 transition-colors">
                                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">Amicizie Accettate</div>
                                        <div class="text-sm text-gray-400">Quando qualcuno accetta la tua richiesta di amicizia</div>
                                    </div>
                                </div>
                                <input type="checkbox" name="notify_friend_accepted" <?= ($settings['notify_friend_accepted'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                            <!-- Chat / Messaggi Privati -->
                            <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-violet-500/20 rounded-lg flex items-center justify-center group-hover:bg-violet-500/30 transition-colors">
                                        <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">Messaggi Chat</div>
                                        <div class="text-sm text-gray-400">Quando ricevi un nuovo messaggio privato</div>
                                    </div>
                                </div>
                                <input type="checkbox" name="notify_dm_received" <?= ($settings['notify_dm_received'] ?? true) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-purple-600 checked:to-pink-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                            </label>

                        </div>
                    </div>

                    <!-- Newsletter Section (Separate - GDPR compliant) -->
                    <div class="bg-gray-800/50 backdrop-blur-lg rounded-2xl border border-gray-700/50 p-6 mt-6">
                        <div class="flex items-start gap-4">
                            <div class="w-14 h-14 bg-gradient-to-br from-blue-600/30 to-cyan-600/30 rounded-2xl flex items-center justify-center flex-shrink-0">
                                <svg class="w-7 h-7 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h2 class="text-xl font-bold text-white mb-1">Newsletter</h2>
                                <p class="text-gray-400 text-sm mb-4">Ricevi aggiornamenti su nuove funzionalità, novità e suggerimenti (max 1-2 email al mese)</p>

                                <label class="flex items-center justify-between p-4 bg-gray-700/30 rounded-xl hover:bg-gray-700/50 transition-all cursor-pointer">
                                    <div>
                                        <div class="text-white font-medium">Iscriviti alla Newsletter</div>
                                        <div class="text-sm text-gray-400">Puoi cancellarti in qualsiasi momento</div>
                                    </div>
                                    <input type="checkbox" name="email_newsletter" <?= ($settings['email_newsletter'] ?? false) ? 'checked' : '' ?> class="w-12 h-6 rounded-full bg-gray-600 appearance-none cursor-pointer transition-colors checked:bg-gradient-to-r checked:from-blue-600 checked:to-cyan-600 relative after:content-[''] after:absolute after:w-5 after:h-5 after:rounded-full after:bg-white after:top-0.5 after:left-0.5 after:transition-transform checked:after:translate-x-6">
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="mt-6">
                        <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-medium rounded-xl transition-all hover:shadow-lg hover:shadow-purple-500/25">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Salva Preferenze</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrf-token" value="<?= $csrfToken ?? '' ?>">

<?php
$content = ob_get_clean();
require APP_ROOT . '/app/Views/layouts/app-post-login.php';
?>
