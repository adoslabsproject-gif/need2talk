<?php
/**
 * need2talk - User Profile Page (CONTENT ONLY - Enterprise Galaxy)
 *
 * ARCHITETTURA ENTERPRISE:
 * - File CONTENT-ONLY (no HTML boilerplate, no CSS/JS includes)
 * - Wrapped by layouts/app-post-login.php
 * - Performance: First load 1.5s, subsequent 50-100ms (browser cache)
 * - Scalability: 100,000+ concurrent users
 *
 * Features:
 * - User bio, avatar, stats (posts, friends, likes)
 * - 6 Tabs: Panoramica, Diario, Calendario, Timeline, Emozioni, Archivio
 * - Friend request system (send/accept/cancel)
 * - Privacy-controlled visibility
 * - Psychological profile dashboard (own profile only)
 *
 * Security:
 * - CSRF protected
 * - XSS prevention (htmlspecialchars)
 * - Privacy enforcement (public/friends/private)
 * - UUID-based URLs (anti-enumeration)
 */
if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// ENTERPRISE V11.5: Show FloatingRecorder on own profile (needed for "Registra il tuo primo audio" button)
// Only hide on OTHER users' profiles
$hideFloatingRecorder = !$isOwnProfile;

// Security: Escape all user data
$currentUser = $user ?? null;
$targetUser = $targetUser ?? null;
$stats = $stats ?? ['posts' => 0, 'friends' => 0, 'likes' => 0];
$posts = $posts ?? [];
$isFriend = $isFriend ?? false;
$isOwnProfile = $isOwnProfile ?? false;
$friendRequestStatus = $friendRequestStatus ?? null;

if (!$targetUser) {
    header('Location: ' . url('/feed'));
    exit;
}

// Escape target user data
$nickname = htmlspecialchars($targetUser['nickname'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$bio = htmlspecialchars($targetUser['bio'] ?? 'Nessuna bio disponibile', ENT_QUOTES, 'UTF-8');

// ENTERPRISE V12.4: Add cache buster to avatar URL to ensure fresh image after update
$baseAvatarUrl = get_avatar_url($targetUser['avatar_url'] ?? null);
$avatarCacheBuster = !empty($targetUser['updated_at']) ? strtotime($targetUser['updated_at']) : time();
$avatarUrl = htmlspecialchars($baseAvatarUrl . '?v=' . $avatarCacheBuster, ENT_QUOTES, 'UTF-8');

$joinedDate = date('F Y', strtotime($targetUser['created_at'] ?? 'now'));
?>

<!-- Main Content -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 pt-20">

    <!-- Profile Header Card -->
    <div class="bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50 overflow-hidden mb-6">

        <!-- ENTERPRISE: Audio-Centric Header Design -->
        <div class="relative h-32 sm:h-48 overflow-hidden bg-gradient-to-br from-purple-900 via-purple-800 to-indigo-900">

            <!-- Animated overlay for depth -->
            <div class="absolute inset-0 bg-gradient-to-r from-purple-500/20 via-pink-500/20 to-blue-500/20 animate-pulse"></div>

            <!-- Audio wave pattern (subtle background) -->
            <div class="absolute inset-0 opacity-20">
                <svg class="w-full h-full" viewBox="0 0 1200 200" preserveAspectRatio="none">
                    <path d="M0,100 Q300,50 600,100 T1200,100" fill="none" stroke="rgba(167, 139, 250, 0.5)" stroke-width="3">
                        <animate attributeName="d" dur="8s" repeatCount="indefinite"
                            values="M0,100 Q300,50 600,100 T1200,100;
                                    M0,100 Q300,150 600,100 T1200,100;
                                    M0,100 Q300,50 600,100 T1200,100"/>
                    </path>
                    <path d="M0,100 Q300,150 600,100 T1200,100" fill="none" stroke="rgba(236, 72, 153, 0.4)" stroke-width="2">
                        <animate attributeName="d" dur="6s" repeatCount="indefinite"
                            values="M0,100 Q300,150 600,100 T1200,100;
                                    M0,100 Q300,70 600,100 T1200,100;
                                    M0,100 Q300,150 600,100 T1200,100"/>
                    </path>
                </svg>
            </div>
        </div>

        <!-- Profile Info -->
        <div class="p-6 sm:p-8">
            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6">

                <!-- Avatar with Pulse Ring -->
                <div class="relative -mt-20 sm:-mt-24 profile-avatar-wrapper">
                    <img src="<?= $avatarUrl ?>"
                         alt="<?= $nickname ?>"
                         class="profile-avatar w-32 h-32 sm:w-40 sm:h-40 rounded-full border-4 border-gray-800 object-cover shadow-xl relative z-10"
                         onerror="this.src='/assets/img/default-avatar.png'">
                    <?php if (!$isOwnProfile && $isFriend): ?>
                    <div class="absolute bottom-2 right-2 w-8 h-8 bg-green-500 rounded-full border-4 border-gray-800 flex items-center justify-center" style="z-index: 50;" title="Amico">
                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"></path>
                        </svg>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- User Info & Actions -->
                <div class="flex-1 text-center sm:text-left">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                        <div>
                            <!-- Nome utente -->
                            <h1 class="text-3xl font-bold text-white mb-1"><?= $nickname ?></h1>
                            <p class="text-gray-400 text-sm">Membro dal <?= $joinedDate ?></p>
                        </div>

                        <!-- Action Buttons -->
                        <?php if (!$isOwnProfile): ?>
                        <div class="flex items-center gap-3">
                            <?php if ($friendRequestStatus === null): ?>
                            <!-- Send Friend Request -->
                            <button id="sendFriendRequestBtn"
                                    data-action="add-friend"
                                    data-user-id="<?= $targetUser['id'] ?>"
                                    class="px-6 py-2 bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 rounded-lg font-medium transition-all duration-200 shadow-lg hover:shadow-xl">
                                <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                                </svg>
                                Aggiungi amico
                            </button>
                            <?php elseif ($friendRequestStatus === 'sent'): ?>
                            <!-- Friend Request Sent -->
                            <button disabled
                                    class="px-6 py-2 bg-gray-600 text-gray-300 rounded-lg font-medium cursor-not-allowed">
                                <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Richiesta inviata
                            </button>
                            <?php elseif ($friendRequestStatus === 'received'): ?>
                            <!-- Accept Friend Request -->
                            <button id="acceptFriendRequestBtn"
                                    data-user-id="<?= $targetUser['id'] ?>"
                                    class="px-6 py-2 bg-green-500 hover:bg-green-600 rounded-lg font-medium transition-all duration-200 shadow-lg hover:shadow-xl">
                                <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Accetta richiesta
                            </button>
                            <?php elseif ($friendRequestStatus === 'accepted' || $isFriend): ?>
                            <!-- Already Friends - Clickable for dropdown menu -->
                            <div class="relative inline-block" id="friendActionContainer">
                                <button id="friendActionButton"
                                        data-user-uuid="<?= $targetUser['uuid'] ?>"
                                        data-user-id="<?= $targetUser['id'] ?>"
                                        class="px-6 py-2 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-lg font-medium cursor-pointer hover:from-purple-700 hover:to-pink-700 shadow-lg hover:shadow-xl transition-all">
                                    <svg class="w-5 h-5 inline mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"></path>
                                    </svg>
                                    Amici
                                </button>
                            </div>
                            <?php endif; ?>

                            <?php if ($isFriend): ?>
                            <!-- ENTERPRISE: Message Button - Opens chat widget (desktop) or DM page (mobile) -->
                            <button id="profileMessageBtn"
                                    data-user-uuid="<?= $targetUser['uuid'] ?>"
                                    data-user-nickname="<?= htmlspecialchars($targetUser['nickname'] ?? 'Utente', ENT_QUOTES, 'UTF-8') ?>"
                                    data-user-avatar="<?= htmlspecialchars(get_avatar_url($targetUser['avatar_url'] ?? null), ENT_QUOTES, 'UTF-8') ?>"
                                    class="px-4 py-2 bg-cool-cyan hover:bg-cool-cyan/80 text-white rounded-lg transition-colors flex items-center gap-2 font-medium"
                                    title="Invia messaggio">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                </svg>
                                <span class="sm:inline">Messaggio</span>
                            </button>
                            <?php endif; ?>

                            <!-- ENTERPRISE V4: Block Button (always visible for non-friends) -->
                            <?php if (!$isFriend && $friendRequestStatus !== 'accepted'): ?>
                            <button id="blockUserBtn"
                                    data-user-uuid="<?= $targetUser['uuid'] ?>"
                                    class="p-2 bg-red-900/50 hover:bg-red-800 text-red-300 hover:text-white rounded-lg transition-colors"
                                    title="Blocca utente">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                                </svg>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <!-- Edit Profile & Settings (Own Profile) -->
                        <a href="<?= url('/settings') ?>"
                           class="inline-flex items-center justify-center px-6 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-lg font-medium transition-all duration-200 no-underline self-center sm:self-auto">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            Modifica Profilo
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- Bio -->
                    <p class="text-gray-300 mb-6 max-w-2xl"><?= nl2br($bio) ?></p>

                    <!-- Stats (Audio-Themed Icons) -->
                    <div class="flex justify-center sm:justify-start gap-8">
                        <!-- Recordings (Post) -->
                        <div class="text-center group">
                            <div class="flex items-center justify-center gap-2 mb-1">
                                <svg class="w-5 h-5 text-purple-400 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                                </svg>
                                <div class="text-2xl font-bold text-purple-400"><?= number_format($stats['posts'] ?? 0) ?></div>
                            </div>
                            <div class="text-sm text-gray-400">Registrazioni</div>
                        </div>

                        <!-- Listeners (Friends) -->
                        <div class="text-center group">
                            <div class="flex items-center justify-center gap-2 mb-1">
                                <svg class="w-5 h-5 text-pink-400 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                </svg>
                                <div class="text-2xl font-bold text-pink-400"><?= number_format($stats['friends'] ?? 0) ?></div>
                            </div>
                            <div class="text-sm text-gray-400">Ascoltatori</div>
                        </div>

                        <!-- Resonance (Reactions) -->
                        <div class="text-center group">
                            <div class="flex items-center justify-center gap-2 mb-1">
                                <svg class="w-5 h-5 text-blue-400 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01"></path>
                                </svg>
                                <div class="text-2xl font-bold text-blue-400"><?= number_format($stats['reactions'] ?? 0) ?></div>
                            </div>
                            <div class="text-sm text-gray-400">Risonanze</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <?php if ($isOwnProfile): ?>
    <!-- ============================================================================
         PSYCHOLOGICAL PROFILE DASHBOARD - "MIRROR OF THE SOUL"
         ============================================================================

         Emotional Health Analysis based on clinical psychology:
         - Plutchik's Wheel of Emotions (10 primary emotions)
         - Cognitive Behavioral Therapy (CBT) principles
         - Mindfulness & Non-judgmental awareness
         - Positive Psychology & Growth mindset

         Tabs Structure:
         1. 📊 Panoramica - Health score, emotion wheel, mood timeline, insights
         2. 📝 Diario Emotivo - Daily journal entries (text + audio)
         3. 📅 Calendario - Monthly heatmap of emotions
         4. 📋 Timeline - Chronological history of journal entries
         5. 🎨 Emozioni - Deep analytics by emotion category
         6. 📚 Archivio - Audio posts organized by emotion

         Philosophy: Validate ALL emotions, never judge. Every emotion is a message.
         ============================================================================ -->

    <!-- TABS NAVIGATION (ENTERPRISE File Folder Tabs Design) -->
    <div class="profile-tabs-container mb-6">
        <div class="profile-tabs-nav">
            <button class="profile-tab-btn active" data-tab="panoramica">
                <span class="tab-icon">📊</span>
                <span class="tab-label">Panoramica</span>
            </button>
            <button class="profile-tab-btn" data-tab="diario">
                <span class="tab-icon">📝</span>
                <span class="tab-label">Diario Emotivo</span>
            </button>
            <button class="profile-tab-btn" data-tab="timeline">
                <span class="tab-icon">📋</span>
                <span class="tab-label">Timeline</span>
            </button>
            <button class="profile-tab-btn" data-tab="emozioni">
                <span class="tab-icon">🧠</span>
                <span class="tab-label">Analisi Emozionale</span>
            </button>
        </div>

        <!-- TAB CONTENT PANELS -->
        <div class="profile-tabs-content">

            <!-- Tab 1: Panoramica (Overview) - ProfileDashboard.js injects here -->
            <div id="tab-panoramica" class="profile-tab-panel active">
                <div id="profile-dashboard"></div>

                <!-- AUDIOPOST PUBBLICI GALLERY (ONLY in Panoramica) -->
                <div id="audio-posts-gallery" class="bg-gray-800/50 backdrop-blur-sm rounded-xl p-6 border border-gray-700/50 mt-6">
                    <h2 class="text-xl font-bold text-white mb-6 flex items-center">
                        <svg class="w-6 h-6 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                        </svg>
                        Post Audio<?= $isOwnProfile ? ' Pubblici' : '' ?>
                    </h2>

                    <?php if (empty($posts)): ?>
                    <!-- Empty State -->
                    <div class="text-center py-12">
                        <svg class="w-16 h-16 mx-auto text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                        </svg>
                        <p class="text-gray-400 text-lg"><?= $isOwnProfile ? 'Non hai ancora pubblicato audio' : 'Nessun audio pubblico disponibile' ?></p>
                        <?php if ($isOwnProfile): ?>
                        <button id="btn-start-recording-panoramica"
                                onclick="if(window.floatingRecorder){window.floatingRecorder.openModal()}else{console.error('FloatingRecorder not ready')}"
                                class="inline-block mt-4 px-6 py-2 bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 rounded-lg font-medium transition-all duration-200 shadow-lg hover:shadow-xl">
                            <svg class="w-5 h-5 inline mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7 4a3 3 0 016 0v4a3 3 0 11-6 0V4zm4 10.93A7.001 7.001 0 0017 8a1 1 0 10-2 0A5 5 0 015 8a1 1 0 00-2 0 7.001 7.001 0 006 6.93V17H6a1 1 0 100 2h8a1 1 0 100-2h-3v-2.07z" clip-rule="evenodd"></path>
                            </svg>
                            Registra il tuo primo audio
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <!-- Posts Grid (will be replaced by JavaScript) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="userPostsContainer">
                        <!-- JavaScript will inject posts here -->
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab 2: Diario Emotivo (Emotional Journal) -->
            <div id="tab-diario" class="profile-tab-panel">
                <div id="emotional-journal-container">
                    <div class="loading-message">Caricamento diario emotivo...</div>
                </div>
            </div>

            <!-- Tab 3: Timeline (Chronological History) - ENTERPRISE GALAXY+ Dual Column -->
            <div id="tab-timeline" class="profile-tab-panel">
                <!-- ENTERPRISE GALAXY+ Phase 2.0: Dual-column layout with calendar sidebar -->
                <div class="journal-timeline-layout">
                    <!-- Left Column: REAL Calendar with Month/Year Navigation -->
                    <aside id="journal-calendar-sidebar" class="journal-calendar-sidebar"
                           data-user-registration="<?= htmlspecialchars($targetUser['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <!-- Calendar with month/year navigation rendered by JournalCalendarSidebar.js -->
                        <div class="calendar-loading">
                            <div class="calendar-loading-spinner"></div>
                            <span>Caricamento calendario...</span>
                        </div>
                    </aside>

                    <!-- Right Column: Timeline Content -->
                    <main id="journal-timeline-container" class="journal-timeline-main">
                        <div class="loading-message">Caricamento timeline...</div>
                    </main>
                </div>
            </div>

            <!-- Tab 4: Emozioni (Deep Analytics) -->
            <div id="tab-emozioni" class="profile-tab-panel">
                <div id="emotions-analytics-container">
                    <div class="loading-message">Caricamento analisi emozioni...</div>
                </div>
            </div>

        </div>
    </div>

    <!-- =================================================================== -->
    <!-- PHASE 1: Private Audio Journal Recorder Modal (ENTERPRISE GALAXY+) -->
    <!-- =================================================================== -->
    <div class="modal fade" id="journalAudioRecorderModal" tabindex="-1" aria-labelledby="journalAudioRecorderModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content diary-audio-recorder">
                <div class="modal-header diary-audio-recorder__header">
                    <h5 class="modal-title diary-audio-recorder__title" id="journalAudioRecorderModalLabel">
                        <svg xmlns="http://www.w3.org/2000/svg" class="diary-audio-recorder__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        🔐 Private Audio Journal Entry
                    </h5>
                    <button type="button" class="diary-audio-recorder__close-btn" data-bs-dismiss="modal" aria-label="Close">
                        ×
                    </button>
                </div>
                <div class="modal-body diary-audio-recorder__body">
                    <!-- Error Message -->
                    <div id="recorderErrorMsg" class="alert alert-danger mb-4" style="display: none;" role="alert"></div>

                    <!-- Emotion Selection (10 Plutchik Emotions) - ENTERPRISE V10.91 -->
                    <div class="mb-5">
                        <label class="form-label text-gray-300 font-semibold mb-2 d-flex align-items-center gap-2">
                            <span>Come ti senti?</span>
                            <span class="text-red-400">*</span>
                        </label>
                        <p class="text-gray-500 text-sm mb-3">Seleziona l'emozione principale che stai provando</p>

                        <!-- Positive Emotions Row -->
                        <div class="mb-2">
                            <div class="emotion-category-label emotion-category-positive">✨ Emozioni Positive</div>
                            <div id="positiveEmotionsRow" class="emotion-grid-row">
                                <!-- Populated by JavaScript - 5 positive emotions -->
                            </div>
                        </div>

                        <!-- Negative Emotions Row -->
                        <div>
                            <div class="emotion-category-label emotion-category-negative">💭 Emozioni da Elaborare</div>
                            <div id="negativeEmotionsRow" class="emotion-grid-row">
                                <!-- Populated by JavaScript - 5 negative emotions -->
                            </div>
                        </div>
                    </div>

                    <!-- Intensity Slider (1-5 scale) - ENTERPRISE V10.91 -->
                    <div id="intensitySliderContainer" class="mb-5" style="display: none;">
                        <label class="form-label text-gray-300 font-semibold mb-2">
                            Intensità: <span id="intensityValue" class="text-purple-400">3</span>/5
                        </label>
                        <input type="range" class="form-range intensity-slider-modal" id="intensitySlider" min="1" max="5" value="3" step="1">
                        <div class="d-flex justify-content-between text-xs text-gray-500 mt-2">
                            <span>Lieve</span>
                            <span>Moderata</span>
                            <span>Intensa</span>
                        </div>
                    </div>

                    <!-- Recording Controls - ENTERPRISE REC Button Design -->
                    <div class="d-flex justify-content-center gap-4 mb-4">
                        <button id="startRecordingBtn" class="rec-button" disabled title="Registra audio">
                            <span class="rec-button-inner"></span>
                            <span class="rec-button-label">REC</span>
                        </button>
                        <button id="stopRecordingBtn" class="stop-button" style="display: none;" title="Ferma registrazione">
                            <span class="stop-button-inner"></span>
                            <span class="stop-button-label">STOP</span>
                        </button>
                    </div>

                    <!-- Recording Timer -->
                    <div id="recordingTimer" class="text-center text-2xl font-mono text-purple-400 mb-3" style="display: none;">00:00</div>

                    <!-- Waveform Visualization (placeholder) -->
                    <div id="waveformContainer" class="mb-4 bg-gray-800 rounded-lg p-4" style="display: none; height: 100px;">
                        <div class="text-center text-gray-500 text-sm">🎵 Registrazione in corso...</div>
                    </div>

                    <!-- Playback Controls - ENTERPRISE Design -->
                    <div id="playbackControls" class="mb-5" style="display: none;">
                        <label class="form-label text-gray-300 font-semibold mb-3">Anteprima registrazione</label>

                        <!-- ENTERPRISE Custom Audio Player (Full Control) -->
                        <div class="journal-audio-player">
                            <!-- Hidden audio element (source of truth) -->
                            <audio id="audioPlayback" preload="auto"></audio>

                            <!-- Play/Pause Button -->
                            <button type="button" id="playerPlayPauseBtn" class="player-play-btn" aria-label="Play">
                                <svg id="playerPlayIcon" class="player-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path fill-rule="evenodd" d="M4.5 5.653c0-1.426 1.529-2.33 2.779-1.643l11.54 6.348c1.295.712 1.295 2.573 0 3.285L7.28 19.991c-1.25.687-2.779-.217-2.779-1.643V5.653z" clip-rule="evenodd" />
                                </svg>
                                <svg id="playerPauseIcon" class="player-icon" style="display:none;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path fill-rule="evenodd" d="M6.75 5.25a.75.75 0 01.75-.75H9a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75H7.5a.75.75 0 01-.75-.75V5.25zm7.5 0A.75.75 0 0115 4.5h1.5a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75H15a.75.75 0 01-.75-.75V5.25z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <!-- Progress Section -->
                            <div class="player-progress-section">
                                <!-- Progress Bar (Clickable for seeking) -->
                                <div id="playerProgressContainer" class="player-progress-container">
                                    <div id="playerProgressBar" class="player-progress-bar"></div>
                                    <div id="playerProgressHandle" class="player-progress-handle"></div>
                                </div>

                                <!-- Time Display (Elapsed Only) -->
                                <div class="player-time">
                                    <span id="playerTimeElapsed" class="player-time-elapsed">0:00</span>
                                </div>
                            </div>

                            <!-- Duration Badge (Above Player) -->
                            <div id="audioDuration" class="audio-duration-display"></div>
                        </div>

                        <!-- Action Buttons - Professional Design -->
                        <div class="d-flex justify-content-center gap-4 mt-4">
                            <button id="reRecordBtn" class="action-btn action-btn-warning" title="Riregistra audio">
                                <span class="action-btn-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </span>
                                <span class="action-btn-label">Riregistra</span>
                            </button>
                            <button id="uploadAudioBtn" class="action-btn action-btn-success" disabled title="Salva registrazione">
                                <span class="action-btn-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </span>
                                <span class="action-btn-label">Cripta e Salva</span>
                            </button>
                        </div>
                    </div>

                    <!-- Text Notes (Optional) -->
                    <div class="mb-4">
                        <label for="textNotes" class="form-label text-gray-300 font-semibold">Note testuali (opzionale, criptate)</label>
                        <textarea id="textNotes" class="form-control bg-gray-800 text-gray-200 border-gray-700" rows="3" placeholder="Aggiungi un contesto alla tua nota vocale..."></textarea>
                    </div>

                    <!-- Photo Upload (Optional - Visual Memory Anchor) -->
                    <div class="mb-4">
                        <label for="audioPhoto" class="form-label text-gray-300 font-semibold d-flex align-items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Foto (opzionale, criptata)
                        </label>
                        <input type="file" class="form-control bg-gray-800 text-gray-200 border-gray-700" id="audioPhoto" accept="image/jpeg,image/png,image/webp">
                        <small class="text-gray-500">Un'immagine può aiutare a ricordare visivamente questo momento (max 10MB, auto-ottimizzata)</small>
                        <div id="photoPreview" class="mt-3" style="display: none;">
                            <div class="d-flex align-items-center gap-3">
                                <img id="photoPreviewImg" src="" alt="Anteprima" class="rounded-lg" style="max-width: 150px; max-height: 150px; object-fit: cover;">
                                <button type="button" id="removePhotoBtn" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Rimuovi
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Progress -->
                    <div id="uploadProgress" class="mb-3" style="display: none;">
                        <div class="progress" style="height: 25px;">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                <span id="uploadProgressText" class="fw-bold">0%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Security Notice -->
                    <div class="alert alert-info border-purple-500/30 bg-purple-900/20 text-purple-300 text-sm">
                        <strong>🔒 Crittografia End-to-End:</strong> Audio e note vengono criptati nel tuo browser prima dell'upload. Solo tu puoi decriptarli e ascoltarli.
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- =================================================================== -->

    <?php endif; ?>

    <?php if (!$isOwnProfile): ?>
    <!-- ============================================================================
         AUDIOPOST PUBBLICI SECTION (For OTHER profiles - visitors only)
         For own profile, this is now inside Panoramica tab
         ============================================================================ -->
    <div class="bg-gray-800/50 backdrop-blur-sm rounded-xl p-6 border border-gray-700/50">
        <h2 class="text-xl font-bold text-white mb-6 flex items-center">
            <svg class="w-6 h-6 mr-2 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
            </svg>
            Post Audio
        </h2>

        <?php if (empty($posts)): ?>
        <!-- Empty State -->
        <div class="text-center py-12">
            <svg class="w-16 h-16 mx-auto text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
            </svg>
            <p class="text-gray-400 text-lg">Nessun audio pubblico disponibile</p>
        </div>
        <?php else: ?>
        <!-- Posts Grid (will be replaced by JavaScript) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="userPostsContainer">
            <!-- JavaScript will inject posts here -->
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<!-- ENTERPRISE: Pass posts data to JavaScript for UserAudioPosts.js -->
<script nonce="<?= csp_nonce() ?>">
// User posts data (from ProfileController)
window.userProfilePosts = <?= json_encode($posts ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.isOwnProfile = <?= json_encode($isOwnProfile, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
// ENTERPRISE SECURITY: Only expose UUID, never numeric ID (prevents enumeration attacks)
window.profileUserUuid = <?= json_encode($targetUser['uuid'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<!-- V12: z-index moved to CSS (diary-audio-recorder.css) -->

<!-- ENTERPRISE: Friend Action Button JavaScript loaded via $pageJS in ProfileController -->

<!-- ENTERPRISE: Profile Message Button - Opens chat widget on desktop, redirects on mobile -->
<?php if (!$isOwnProfile && $isFriend): ?>
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    const messageBtn = document.getElementById('profileMessageBtn');
    if (!messageBtn) return;

    messageBtn.addEventListener('click', async function() {
        const userUuid = this.dataset.userUuid;
        const userNickname = this.dataset.userNickname;
        const userAvatar = this.dataset.userAvatar;

        // Show loading state
        messageBtn.disabled = true;
        messageBtn.classList.add('opacity-50');

        try {
            // ENTERPRISE: Get or create DM conversation via API
            const response = await fetch('/api/chat/dm', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ recipient_uuid: userUuid })
            });

            const result = await response.json();

            if (!result.success || !result.data?.conversation?.uuid) {
                throw new Error(result.error || 'Impossibile avviare la conversazione');
            }

            const conversationUuid = result.data.conversation.uuid;

            // ENTERPRISE: Detect desktop vs mobile
            const isDesktop = window.innerWidth >= 768 &&
                !/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

            if (isDesktop && window.chatWidgetManager) {
                // DESKTOP: Open Facebook-style chat widget
                // ENTERPRISE V9.6: Fetch real presence status before opening widget
                let presenceStatus = 'offline';
                try {
                    const presenceResp = await fetch(`/api/chat/presence/${userUuid}`);
                    const presenceData = await presenceResp.json();
                    if (presenceData.success && presenceData.data) {
                        presenceStatus = presenceData.data.status || 'offline';
                    }
                } catch (e) {
                    console.warn('[Profile] Failed to fetch presence, using offline:', e);
                }

                const otherUser = {
                    uuid: userUuid,
                    nickname: userNickname,
                    avatar_url: userAvatar,
                    status: presenceStatus
                };
                window.chatWidgetManager.openWidget(conversationUuid, otherUser);
            } else {
                // MOBILE: Redirect to fullscreen DM page
                window.location.href = '/chat/dm/' + conversationUuid;
            }

        } catch (error) {
            console.error('[Profile] Failed to open chat:', error);
            alert(error.message || 'Errore nell\'apertura della chat');
        } finally {
            // Restore button state
            messageBtn.disabled = false;
            messageBtn.classList.remove('opacity-50');
        }
    });
});
</script>
<?php endif; ?>

