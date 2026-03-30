<?php
/**
 * Chat Index - Main Chat Page (CONTENT ONLY)
 * Enterprise Galaxy Chat System
 *
 * LAYOUT V9.1 (2025-12-02):
 * - Emotion rooms in 2 rows of 5 (smaller boxes)
 * - User-created rooms section with skeleton
 * - DM/Friends section with online/offline status
 * - Chat area with proper height
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */

// ENTERPRISE V10.183: Hide FloatingRecorder on chat pages (has its own audio input)
$hideFloatingRecorder = true;

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// User data from controller
$userName = htmlspecialchars($user['nickname'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$userAvatar = htmlspecialchars($user['avatar_url'] ?? '/assets/img/default-avatar.png', ENT_QUOTES, 'UTF-8');
$userUuid = htmlspecialchars($user['uuid'] ?? '', ENT_QUOTES, 'UTF-8');

// ENTERPRISE V10.3: Server-side presence status for instant UI render (no flash)
$currentStatus = $userStatus ?? 'online';
$statusConfig = [
    'online' => ['color' => 'bg-green-500', 'label' => 'Online', 'animate' => true],
    'dnd' => ['color' => 'bg-yellow-500', 'label' => 'Occupato', 'animate' => false],
    'invisible' => ['color' => 'bg-gray-500', 'label' => 'Invisibile', 'animate' => false],
];
$status = $statusConfig[$currentStatus] ?? $statusConfig['online'];
$indicatorClass = $status['color'] . ($status['animate'] ? ' animate-pulse' : '');
$statusLabel = $status['label'];
?>

<!-- Main Chat Page - Enterprise Galaxy V9.1 Layout -->
<main class="min-h-screen max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 pt-20">

    <!-- Page Header with Presence Toggle -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-white flex items-center">
            <svg class="w-7 h-7 mr-3 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
            </svg>
            Emotion Chat
        </h1>

        <div class="flex items-center gap-4">
            <!-- PRESENCE STATUS TOGGLE (Semaforo) -->
            <div class="relative" id="presenceToggleContainer">
                <button id="presenceToggleBtn"
                        class="flex items-center gap-2 px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg transition-colors"
                        title="Cambia il tuo stato">
                    <!-- Status indicator (color changes based on status) - ENTERPRISE V10.3: Server-rendered -->
                    <span id="presenceIndicator" class="w-3 h-3 rounded-full <?= $indicatorClass ?>"></span>
                    <span id="presenceLabel" class="text-sm text-gray-300"><?= $statusLabel ?></span>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <!-- Dropdown Menu -->
                <div id="presenceDropdown" class="hidden absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-xl shadow-xl z-50 overflow-hidden">
                    <div class="py-1">
                        <button class="presence-option w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-700/50 transition-colors text-left"
                                data-status="online">
                            <span class="w-3 h-3 rounded-full bg-green-500"></span>
                            <div>
                                <p class="text-white font-medium">Online</p>
                                <p class="text-xs text-gray-400">Visibile a tutti</p>
                            </div>
                        </button>
                        <button class="presence-option w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-700/50 transition-colors text-left"
                                data-status="dnd">
                            <span class="w-3 h-3 rounded-full bg-yellow-500"></span>
                            <div>
                                <p class="text-white font-medium">Occupato</p>
                                <p class="text-xs text-gray-400">Non disturbare</p>
                            </div>
                        </button>
                        <button class="presence-option w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-700/50 transition-colors text-left"
                                data-status="invisible">
                            <span class="w-3 h-3 rounded-full bg-gray-500"></span>
                            <div>
                                <p class="text-white font-medium">Invisibile</p>
                                <p class="text-xs text-gray-400">Appari offline</p>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <button id="createRoomBtn"
                    class="flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 rounded-lg text-white text-sm font-medium transition-colors"
                    title="Crea nuova room">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Crea Room
            </button>
        </div>
    </div>

    <!-- =========================================================================
         ENTERPRISE V9.6 LAYOUT - Desktop 2 colonne, Mobile stack verticale
         DESKTOP: Colonna SX (amici + emotions) | Colonna DX (chat + user rooms)
         MOBILE: Amici → Emotions → Chat → User Rooms (tutto in colonna)
         ========================================================================= -->
    <div class="chat-layout">

        <!-- =====================================================================
             LEFT SIDEBAR (Desktop 280px fixed width)
             Contiene: Amici Online + Emotions Rooms (verticali)
             ===================================================================== -->
        <div class="chat-sidebar space-y-4">

            <!-- AMICI ONLINE - Compatto, scrollabile -->
            <div class="bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50">
                <div class="p-3 border-b border-gray-700/50">
                    <h2 class="text-sm font-semibold text-white flex items-center">
                        <svg class="w-4 h-4 mr-1.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Amici
                    </h2>
                </div>

                <div id="dmFriendsContainer" class="p-2 max-h-[200px] overflow-y-auto">
                    <!-- Loading skeleton - più compatto -->
                    <div class="dm-friends-skeleton">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="flex items-center gap-2 p-1.5 bg-gray-700/20 rounded-lg <?= $i > 0 ? 'mt-1' : '' ?>">
                            <div class="relative flex-shrink-0">
                                <div class="skeleton w-8 h-8 rounded-full"></div>
                                <div class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-gray-800 bg-gray-600"></div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="skeleton h-2.5 w-16 rounded"></div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Empty state -->
                    <div class="dm-friends-empty hidden text-center py-4">
                        <div class="w-10 h-10 bg-gray-700/50 rounded-full mx-auto mb-2 flex items-center justify-center">
                            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                        </div>
                        <p class="text-gray-400 text-xs">Nessun amico</p>
                    </div>

                    <!-- Friends list (populated by JS) -->
                    <div class="dm-friends-list hidden"></div>
                </div>
            </div>

            <!-- EMOTION ROOMS - 5 cols mobile, 2 cols desktop sidebar -->
            <div class="bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50">
                <div class="p-3 border-b border-gray-700/50">
                    <h2 class="text-sm font-semibold text-white">Emotions</h2>
                    <p class="text-xs text-gray-400">Come ti senti?</p>
                </div>

                <!-- Emotion Rooms Container -->
                <div id="emotionRoomsContainer" class="p-3">
                    <!-- Loading skeleton -->
                    <div class="emotion-grid">
                        <?php for ($i = 0; $i < 10; $i++): ?>
                        <div class="emotion-card opacity-50">
                            <div class="skeleton w-7 h-7 rounded-full mb-1"></div>
                            <div class="skeleton h-2 w-10 rounded"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- =====================================================================
             RIGHT MAIN AREA (Desktop flex-1)
             Contiene: Chat Area (grande) + User Rooms (sotto)
             ===================================================================== -->
        <div class="chat-main space-y-4">

            <!-- CHAT AREA - Grande, occupa tutta la larghezza -->
            <!-- Welcome State (when no room selected) -->
            <div id="chatWelcome" class="bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50 p-6 text-center h-[500px] flex items-center justify-center">
                <div class="max-w-md mx-auto">
                    <div class="w-20 h-20 bg-gradient-to-br from-purple-500/20 to-pink-500/20 rounded-full mx-auto mb-6 flex items-center justify-center">
                        <svg class="w-10 h-10 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-white mb-3">Benvenuto nella Chat</h2>
                    <p class="text-gray-400 mb-6">
                        Seleziona un'emozione dalla colonna a sinistra per entrare in una stanza,
                        oppure clicca su un amico per chattare in privato.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-3 justify-center text-sm">
                        <div class="flex items-center text-gray-500">
                            <span class="w-3 h-3 bg-purple-500 rounded-full mr-2"></span>
                            10 stanze emozionali
                        </div>
                        <div class="flex items-center text-gray-500">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                            Chat real-time
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Chat Container (hidden until room joined) -->
            <div id="chatActiveContainer" class="hidden bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50 flex flex-col h-[500px]">

                <!-- Chat Header -->
                <div id="chatHeader" class="p-4 border-b border-gray-700/50 flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center">
                        <button id="chatBackBtn" class="mr-3 p-2 hover:bg-gray-700/50 rounded-lg transition-colors" title="Torna alla selezione">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                        </button>
                        <div id="chatRoomIcon" class="w-10 h-10 rounded-full bg-gray-700/50 flex items-center justify-center text-2xl mr-3">
                            💬
                        </div>
                        <div>
                            <h3 id="chatRoomName" class="font-semibold text-white"></h3>
                            <p id="chatRoomStatus" class="text-xs text-gray-400"></p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <!-- ENTERPRISE V10.81: Clickable online count with visual indicator -->
                        <button id="chatOnlineCount"
                                class="group text-sm text-gray-400 flex items-center gap-2 hover:text-white bg-gray-700/30 hover:bg-gray-700/70 px-3 py-1.5 rounded-lg transition-all cursor-pointer border border-gray-600/50 hover:border-purple-500/50"
                                title="Clicca per vedere gli utenti online">
                            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                            <span>0 online</span>
                            <!-- Users icon indicator -->
                            <svg class="w-4 h-4 text-gray-500 group-hover:text-purple-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Messages Container -->
                <div id="chatMessagesContainer" class="flex-1 overflow-hidden relative min-h-0">
                    <!-- MessageList.js renders here -->
                </div>

                <!-- Typing Indicator -->
                <div id="chatTypingIndicator" class="px-4 py-2 flex-shrink-0">
                    <!-- TypingIndicator.js renders here -->
                </div>

                <!-- Message Input -->
                <div id="chatInputContainer" class="p-4 border-t border-gray-700/50 flex-shrink-0">
                    <!-- MessageInput.js renders here -->
                </div>

            </div>

            <!-- USER-CREATED ROOMS - Sotto la chat -->
            <div class="bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50">
                <div class="p-3 border-b border-gray-700/50 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-white">Room Utenti</h2>
                        <p class="text-[10px] text-gray-400">Room create dalla community</p>
                    </div>
                    <button id="refreshUserRoomsBtn" class="p-1.5 hover:bg-gray-700/50 rounded-lg transition-colors" title="Aggiorna">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    </button>
                </div>

                <div id="userRoomsContainer" class="p-3">
                    <!-- Loading skeleton -->
                    <div class="user-rooms-skeleton">
                        <div class="flex flex-wrap gap-2">
                            <?php for ($i = 0; $i < 4; $i++): ?>
                            <div class="flex items-center gap-2 p-2 bg-gray-700/30 rounded-lg flex-1 min-w-[200px] max-w-[300px]">
                                <div class="skeleton w-8 h-8 rounded-full flex-shrink-0"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="skeleton h-2.5 w-20 rounded mb-1"></div>
                                    <div class="skeleton h-2 w-12 rounded"></div>
                                </div>
                                <div class="skeleton h-4 w-6 rounded-full flex-shrink-0"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div class="user-rooms-empty hidden text-center py-6">
                        <div class="w-12 h-12 bg-gray-700/50 rounded-full mx-auto mb-3 flex items-center justify-center">
                            <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z"></path>
                            </svg>
                        </div>
                        <p class="text-gray-400 text-sm">Nessuna room attiva</p>
                        <p class="text-gray-500 text-xs mt-1">Clicca "Crea Room" per iniziare!</p>
                    </div>

                    <!-- Room list (populated by JS) -->
                    <div class="user-rooms-list hidden">
                        <div class="flex flex-wrap gap-2">
                            <!-- JS populates this -->
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

</main>

<!-- Create Room Modal -->
<div id="createRoomModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-md w-full p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold text-white">Crea nuova Room</h2>
                <button id="closeCreateRoomModal" class="p-2 hover:bg-gray-700 rounded-lg transition-colors">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form id="createRoomForm" class="space-y-4">
                <!-- Emotion Selector -->
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Scegli l'emozione</label>
                    <div class="grid grid-cols-5 gap-2" id="emotionSelector">
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="1">
                            <span class="text-2xl mb-1">😊</span>
                            <span class="text-[10px] text-gray-400">Gioia</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="2">
                            <span class="text-2xl mb-1">✨</span>
                            <span class="text-[10px] text-gray-400">Meraviglia</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="3">
                            <span class="text-2xl mb-1">❤️</span>
                            <span class="text-[10px] text-gray-400">Amore</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="4">
                            <span class="text-2xl mb-1">🙏</span>
                            <span class="text-[10px] text-gray-400">Gratitudine</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="5">
                            <span class="text-2xl mb-1">🌟</span>
                            <span class="text-[10px] text-gray-400">Speranza</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="6">
                            <span class="text-2xl mb-1">😢</span>
                            <span class="text-[10px] text-gray-400">Tristezza</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="7">
                            <span class="text-2xl mb-1">😠</span>
                            <span class="text-[10px] text-gray-400">Rabbia</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="8">
                            <span class="text-2xl mb-1">😰</span>
                            <span class="text-[10px] text-gray-400">Ansia</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="9">
                            <span class="text-2xl mb-1">😨</span>
                            <span class="text-[10px] text-gray-400">Paura</span>
                        </button>
                        <button type="button" class="emotion-option p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 border-2 border-transparent transition-all flex flex-col items-center" data-emotion-id="10">
                            <span class="text-2xl mb-1">😔</span>
                            <span class="text-[10px] text-gray-400">Solitudine</span>
                        </button>
                    </div>
                    <input type="hidden" id="roomEmotionId" name="emotion_id" required>
                    <p id="emotionError" class="text-red-400 text-xs mt-1 hidden">Seleziona un'emozione</p>
                </div>

                <div>
                    <label for="roomName" class="block text-sm font-medium text-gray-300 mb-1">Nome Room</label>
                    <input type="text" id="roomName" name="name" required
                           class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                           placeholder="Es: Parliamo di ansia"
                           maxlength="100">
                </div>

                <div>
                    <label for="roomDescription" class="block text-sm font-medium text-gray-300 mb-1">Descrizione (opzionale)</label>
                    <textarea id="roomDescription" name="description" rows="2"
                              class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none"
                              placeholder="Di cosa si parlerà in questa room?"
                              maxlength="500"></textarea>
                </div>

                <div class="pt-4">
                    <button type="submit" id="createRoomSubmitBtn"
                            class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition-colors flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Crea Room
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Report Message Modal Container -->
<div id="reportModalContainer"></div>

<!-- ENTERPRISE V10.54: Online Users Modal -->
<div id="onlineUsersModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-sm w-full overflow-hidden">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b border-gray-700">
                <div class="flex items-center">
                    <span id="onlineUsersRoomIcon" class="text-2xl mr-3">💬</span>
                    <div>
                        <h2 id="onlineUsersRoomName" class="text-lg font-bold text-white">Utenti Online</h2>
                        <p id="onlineUsersCount" class="text-xs text-gray-400">0 utenti connessi</p>
                        <p id="onlineUsersCreatedAt" class="text-[10px] text-gray-500 hidden">Creata: -</p>
                    </div>
                </div>
                <button id="closeOnlineUsersModal" class="p-2 hover:bg-gray-700 rounded-lg transition-colors">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Users List (max 20, scrollable) -->
            <div id="onlineUsersList" class="p-4 max-h-[400px] overflow-y-auto space-y-2">
                <!-- Loading skeleton -->
                <div class="online-users-skeleton space-y-2">
                    <?php for ($i = 0; $i < 5; $i++): ?>
                    <div class="flex items-center gap-3 p-2 bg-gray-700/30 rounded-lg animate-pulse">
                        <div class="w-10 h-10 rounded-full bg-gray-600"></div>
                        <div class="flex-1">
                            <div class="h-3 w-24 bg-gray-600 rounded mb-1"></div>
                            <div class="h-2 w-16 bg-gray-700 rounded"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Empty state -->
                <div class="online-users-empty hidden text-center py-6">
                    <div class="w-12 h-12 bg-gray-700/50 rounded-full mx-auto mb-3 flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <p class="text-gray-400 text-sm">Nessun utente online</p>
                </div>

                <!-- Users container (populated by JS) -->
                <div class="online-users-container hidden space-y-2"></div>
            </div>

            <!-- Footer note -->
            <div class="px-4 py-3 border-t border-gray-700 bg-gray-800/50">
                <p class="text-[10px] text-gray-500 text-center">Mostra max 20 utenti • Aggiornamento in tempo reale</p>
            </div>
        </div>
    </div>
</div>

<!-- Chat Initialization Script -->
<script>
// Set APP_USER for ChatManager
window.APP_USER = {
    uuid: '<?= $userUuid ?>',
    name: '<?= addslashes($userName) ?>',
    avatar: '<?= addslashes($userAvatar) ?>'
};

// ============================================================================
// FUNCTION DECLARATIONS (must be before DOMContentLoaded to be hoisted)
// ============================================================================

// ENTERPRISE V10.83: Toast notification system
function showToast(message, type = 'info', duration = 4000) {
    // Create toast container if not exists
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'fixed top-4 right-4 z-[9999] flex flex-col gap-2';
        document.body.appendChild(container);
    }

    // Create toast element
    const toast = document.createElement('div');
    const colors = {
        error: 'bg-red-600 border-red-500',
        success: 'bg-green-600 border-green-500',
        warning: 'bg-yellow-600 border-yellow-500',
        info: 'bg-blue-600 border-blue-500'
    };
    const icons = {
        error: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',
        info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    };

    toast.className = `flex items-center gap-3 px-4 py-3 rounded-lg border shadow-lg text-white transform transition-all duration-300 ${colors[type] || colors.info}`;
    toast.style.cssText = 'opacity: 0; transform: translateX(100%);';
    toast.innerHTML = `
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            ${icons[type] || icons.info}
        </svg>
        <span class="text-sm">${message}</span>
        <button class="ml-auto p-1 hover:bg-white/20 rounded" onclick="this.parentElement.remove()">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    `;

    container.appendChild(toast);

    // Animate in
    requestAnimationFrame(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateX(0)';
    });

    // Auto-remove after duration
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// Load ALL public user-created rooms (visible to everyone)
// ENTERPRISE V10.52: Uses /api/chat/rooms endpoint which returns ALL active public rooms
// All rooms are public and visible to all users - no private rooms concept
async function loadUserRooms() {
    const container = document.getElementById('userRoomsContainer');
    const skeleton = container?.querySelector('.user-rooms-skeleton');
    const empty = container?.querySelector('.user-rooms-empty');
    const list = container?.querySelector('.user-rooms-list');

    if (!container) {
        console.error('[UserRooms] Container not found!');
        return;
    }

    try {
        // ENTERPRISE V10.52: Use /rooms endpoint for ALL public rooms (not just user's own)
        const response = await fetch('/api/chat/rooms?limit=20');
        const data = await response.json();

        skeleton?.classList.add('hidden');

        // API returns { success, data: { rooms: [...] } }
        const rooms = data.data?.rooms || [];

        if (!data.success || rooms.length === 0) {
            empty?.classList.remove('hidden');
            list?.classList.add('hidden');
            return;
        }

        empty?.classList.add('hidden');
        list?.classList.remove('hidden');

        // ENTERPRISE V10.52: Structure optimized for real-time counter updates
        // - data-room-uuid: Used by WebSocket handler to find room element
        // - .room-online-counter: Primary counter updated by WebSocket
        // - .room-online-dot: Visual indicator updated by WebSocket
        // ENTERPRISE V10.83: Added created_at display and data attribute
        list.innerHTML = rooms.map(room => {
            // Format creation date for display
            let createdAtFormatted = '';
            if (room.created_at) {
                const date = new Date(room.created_at);
                createdAtFormatted = date.toLocaleString('it-IT', {
                    day: '2-digit',
                    month: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }
            return `
            <button class="user-room-card w-full flex items-center gap-3 p-3 bg-gray-700/30 hover:bg-gray-700/50 rounded-lg transition-colors text-left"
                    data-room-uuid="${room.uuid}"
                    data-room-name="${room.name}"
                    data-online-count="${room.online_count || 0}"
                    data-created-at="${room.created_at || ''}">
                <div class="w-10 h-10 rounded-full flex items-center justify-center text-lg"
                     style="background-color: ${room.emotion_color || '#9333ea'}20;">
                    ${room.emotion_icon || '💬'}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-white truncate">${room.name}</p>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="${room.online_count > 0 ? 'text-green-400' : 'text-gray-400'} flex items-center room-online-counter">
                            <span class="w-2 h-2 rounded-full ${room.online_count > 0 ? 'bg-green-400 animate-pulse' : 'bg-gray-500'} mr-1 room-online-dot"></span>
                            <span class="room-online-count">${room.online_count || 0}</span>&nbsp;online
                        </span>
                        ${createdAtFormatted ? `<span class="text-gray-500">• ${createdAtFormatted}</span>` : ''}
                    </div>
                </div>
            </button>
        `}).join('');

        // Add click handlers
        list.querySelectorAll('.user-room-card').forEach(card => {
            card.addEventListener('click', () => {
                const roomUuid = card.dataset.roomUuid;
                if (window.chatManager) {
                    window.chatManager.joinRoom(roomUuid, 'user_created');
                }
            });
        });

    } catch (error) {
        console.error('[UserRooms] Failed to load:', error);
        skeleton?.classList.add('hidden');
        empty?.classList.remove('hidden');
    }
}

// ============================================================================
// DOM CONTENT LOADED HANDLER
// ============================================================================

document.addEventListener('DOMContentLoaded', async function() {
    try {
        // Initialize ChatManager (singleton)
        if (typeof ChatManager !== 'undefined') {
            window.chatManager = ChatManager.getInstance();
            await window.chatManager.initialize();
        } else {
            console.warn('[Chat] ChatManager not loaded');
        }

        // Initialize EmotionRoomSelector with 5-column grid
        if (typeof EmotionRoomSelector !== 'undefined') {
            window.emotionRoomSelector = new EmotionRoomSelector('#emotionRoomsContainer', {
                chatManager: window.chatManager,
                gridCols: 2,      // 2 columns = 5 rows of 2
                compactMode: true // Smaller boxes
            });
        } else {
            console.warn('[Chat] EmotionRoomSelector not loaded');
        }

        // ENTERPRISE V11.6: Check URL for room parameter (room invite auto-join)
        // MUST be called AFTER EmotionRoomSelector is initialized!
        // Otherwise chat:room_joined fires before getRoomInfo() works
        if (window.chatManager) {
            window.chatManager.checkUrlRoomParameter();
        }

        // Initialize TypingIndicator
        if (typeof TypingIndicator !== 'undefined') {
            window.typingIndicator = new TypingIndicator('#chatTypingIndicator');
        }

        // Initialize MessageInput (TEXT ONLY - no audio/images in emotion rooms)
        if (typeof MessageInput !== 'undefined') {
            window.messageInput = new MessageInput('#chatInputContainer', {
                placeholder: 'Scrivi un messaggio...',
                enableAudio: false,   // DISABLED for emotion rooms
                enableImages: false,  // DISABLED for emotion rooms
                enableEmoji: true
            });
            // Register callbacks using on() method
            window.messageInput.on({
                send: async (message) => {
                    if (window.chatManager?.currentRoom) {
                        await window.chatManager.sendRoomMessage(message.content || '');
                    }
                },
                typingStart: () => {
                    window.chatManager?.startTyping('room');
                },
                typingStop: () => {
                    window.chatManager?.stopTyping('room');
                },
                error: (errorMsg) => {
                    console.error('[Chat] Input error:', errorMsg);
                }
            });
        }

        // Initialize MessageList
        if (typeof MessageList !== 'undefined') {
            window.messageList = new MessageList('#chatMessagesContainer', {
                currentUserUuid: window.APP_USER.uuid
            });
        }

        // Initialize ReportModal (singleton)
        if (typeof ReportModal !== 'undefined') {
            window.reportModal = new ReportModal();
        }

        // Setup UI event listeners
        setupChatUIListeners();

        // ENTERPRISE V10.54: Setup online users modal listeners
        setupOnlineUsersModalListeners();

        // ENTERPRISE V9.0: Setup real-time presence listener
        setupPresenceListener();

        // Load user rooms and friends
        loadUserRooms();
        loadDMFriends();

        // ENTERPRISE V9.1: Refresh friends list every 5 minutes for presence updates
        // This ensures status changes are visible even without WebSocket real-time updates
        const FRIENDS_REFRESH_INTERVAL = 5 * 60 * 1000; // 5 minutes
        setInterval(() => {
            loadDMFriends(true); // silent refresh (no skeleton)
        }, FRIENDS_REFRESH_INTERVAL);

        // ENTERPRISE V12.3: Immediate refresh when friend is removed (unfriend)
        // This ensures the removed friend disappears from the list immediately
        document.addEventListener('friendship:ended', () => {
            console.log('[Chat] Friendship ended, refreshing friends list');
            loadDMFriends(true); // silent refresh
        });
        document.addEventListener('friendship:ended_self', () => {
            console.log('[Chat] Unfriended someone, refreshing friends list');
            loadDMFriends(true); // silent refresh
        });

        // ENTERPRISE V10.82: Sync friends list unread badges when conversation is marked as read
        // This handles the case where user opens widget, types/focuses, and conversation is marked read
        // The badge in the friends sidebar should update immediately
        window.addEventListener('n2t:conversationMarkedRead', (e) => {
            const { conversationUuid } = e.detail;

            // Strategy 1: Find card by conversation UUID (if set)
            let friendCard = document.querySelector(`.dm-friend-card[data-conversation-uuid="${conversationUuid}"]`);

            // Strategy 2: If not found, check open widgets to get friend UUID
            if (!friendCard && window.chatWidgetManager) {
                const widget = window.chatWidgetManager.getWidgetByConversation?.(conversationUuid);
                if (widget?.friendUuid) {
                    friendCard = document.querySelector(`.dm-friend-card[data-friend-uuid="${widget.friendUuid}"]`);
                    // Also store the conversation UUID on this card for future lookups
                    if (friendCard) {
                        friendCard.dataset.conversationUuid = conversationUuid;
                    }
                }
            }

            if (friendCard) {
                // Remove the unread badge
                const badge = friendCard.querySelector('.bg-purple-500');
                if (badge) {
                    badge.remove();
                }
            }
        });

    } catch (error) {
        console.error('[Chat] Initialization failed:', error);
    }
});

/**
 * ENTERPRISE V9.1: Decrypt DM messages using ChatEncryptionService
 * @param {Array} messages - Encrypted messages from API
 * @param {string} conversationUuid - Conversation UUID for key derivation
 * @returns {Promise<Array>} Decrypted messages with 'content' field populated
 */
async function decryptDMMessages(messages, conversationUuid) {
    if (!messages || messages.length === 0) {
        return [];
    }

    // Check if encryption service is available
    if (!window.chatEncryption || !window.chatEncryption.isInitialized()) {
        console.warn('[Chat] ChatEncryptionService not initialized, showing encrypted placeholders');
        // Return messages with placeholder content
        return messages.map(msg => ({
            ...msg,
            content: '[Messaggio crittografato - chiave non disponibile]',
            decrypted: false
        }));
    }

    // Decrypt each message in parallel for performance
    const decryptedMessages = await Promise.all(messages.map(async (msg) => {
        // Skip if already has content (non-encrypted) or is system message
        if (msg.content || msg.message_type === 'system') {
            return { ...msg, decrypted: false };
        }

        // Skip if missing encryption data
        if (!msg.encrypted_content) {
            return { ...msg, content: '', decrypted: false };
        }

        try {
            const decryptedContent = await window.chatEncryption.decryptMessage(
                msg.encrypted_content,
                msg.content_iv,
                msg.content_tag,
                conversationUuid
            );

            return {
                ...msg,
                content: decryptedContent,
                decrypted: true
            };
        } catch (error) {
            console.error('[Chat] Failed to decrypt message:', msg.uuid, error);
            return {
                ...msg,
                content: '[Impossibile decrittare il messaggio]',
                decrypted: false,
                decryptError: true
            };
        }
    }));

    return decryptedMessages;
}

// Load DM friends list
// @param {boolean} silent - If true, don't show skeleton (for auto-refresh)
async function loadDMFriends(silent = false) {
    const container = document.getElementById('dmFriendsContainer');
    const skeleton = container?.querySelector('.dm-friends-skeleton');
    const empty = container?.querySelector('.dm-friends-empty');
    const list = container?.querySelector('.dm-friends-list');

    if (!container) {
        console.warn('[DM Friends] Container not found, returning early');
        return;
    }

    try {
        const response = await fetch('/api/friends?with_status=1');
        const data = await response.json();

        // ENTERPRISE V5.6 FIX: ALWAYS hide skeleton when data arrives (even on silent refresh)
        // Previously only hid on initial load, causing spacing bug when friends list refreshed
        skeleton?.classList.add('hidden');

        if (!data.success || !data.friends || data.friends.length === 0) {
            empty?.classList.remove('hidden');
            list?.classList.add('hidden');
            return;
        }

        empty?.classList.add('hidden');
        list?.classList.remove('hidden');

        // ENTERPRISE V10.150: Sort by "active" status first, then by name
        // Active = online OR dnd/busy (occupato counts as active, just busy)
        // This ensures "Occupato" users appear at the top alongside "Online" users
        const isActive = (friend) => {
            if (friend.is_online) return true;
            const status = friend.presence_status;
            return status === 'dnd' || status === 'busy';
        };

        const friends = data.friends.sort((a, b) => {
            const aActive = isActive(a);
            const bActive = isActive(b);
            if (aActive && !bActive) return -1;
            if (!aActive && bActive) return 1;
            return (a.nickname || '').localeCompare(b.nickname || '');
        });

        // ENTERPRISE V9.3: Map presence status to UI colors/labels
        const getPresenceUI = (friend) => {
            // Check explicit status first (dnd/busy = occupied)
            if (friend.presence_status === 'dnd' || friend.presence_status === 'busy') {
                return { color: 'bg-yellow-500', label: 'Occupato', textColor: 'text-yellow-400' };
            }
            if (friend.presence_status === 'away') {
                return { color: 'bg-orange-500', label: 'Assente', textColor: 'text-orange-400' };
            }
            if (friend.presence_status === 'invisible' || !friend.is_online) {
                return { color: 'bg-gray-500', label: 'Offline', textColor: 'text-gray-500' };
            }
            return { color: 'bg-green-500', label: 'Online', textColor: 'text-green-400' };
        };

        // ENTERPRISE V9.5: Compact friend cards for sidebar layout
        // ENTERPRISE V5.6: Added invite button (always visible, enabled when in user room)
        list.innerHTML = friends.map((friend, index) => {
            const presence = getPresenceUI(friend);
            return `
            <div class="dm-friend-card-wrapper flex items-center gap-1${index > 0 ? ' mt-1' : ''}">
                <button class="dm-friend-card flex-1 flex items-center gap-2 p-1.5 hover:bg-gray-700/30 rounded-lg transition-colors text-left"
                        data-friend-uuid="${friend.uuid}"
                        data-friend-name="${friend.nickname || 'Utente'}"
                        data-presence-status="${friend.presence_status || 'offline'}"
                        title="${friend.nickname || 'Utente'} - ${presence.label}">
                    <div class="relative flex-shrink-0">
                        <img src="${friend.avatar_url || '/assets/img/default-avatar.png'}"
                             alt="${friend.nickname || 'Utente'}"
                             class="w-8 h-8 rounded-full object-cover"
                             onerror="this.src='/assets/img/default-avatar.png'">
                        <span class="presence-dot absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-gray-800 ${presence.color}"></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-white truncate">${friend.nickname || 'Utente'}</p>
                    </div>
                    ${friend.unread_count ? `
                        <span class="bg-purple-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full flex-shrink-0">
                            ${friend.unread_count}
                        </span>
                    ` : ''}
                </button>
                <button class="invite-friend-btn p-1.5 text-gray-500 hover:text-purple-300 hover:bg-purple-500/20 rounded-lg transition-colors flex-shrink-0"
                        data-friend-uuid="${friend.uuid}"
                        data-friend-name="${friend.nickname || 'Utente'}"
                        title="Invita ${friend.nickname || 'Utente'} in una stanza">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                </button>
            </div>
        `}).join('');

        // Add click handlers for DM
        list.querySelectorAll('.dm-friend-card').forEach(card => {
            card.addEventListener('click', () => {
                openDMConversation(card);
            });
        });

        // ENTERPRISE V5.6: Add click handlers for invite buttons
        // Uses global showToast() defined at line ~498
        list.querySelectorAll('.invite-friend-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const friendUuid = btn.dataset.friendUuid;
                const friendName = btn.dataset.friendName;
                const currentRoom = window.chatManager?.currentRoom;

                // Check if friend is online (from parent card's presence status)
                const wrapper = btn.closest('.dm-friend-card-wrapper');
                const card = wrapper?.querySelector('.dm-friend-card');
                const presenceStatus = card?.dataset.presenceStatus;
                const isOnline = presenceStatus && presenceStatus !== 'offline' && presenceStatus !== 'invisible';

                if (!isOnline) {
                    showToast(`${friendName} è offline`, 'warning');
                    return;
                }

                if (!currentRoom) {
                    showToast('Entra in una stanza per invitare amici', 'warning');
                    return;
                }

                btn.disabled = true;
                btn.classList.add('opacity-50');

                try {
                    const response = await fetch(`/api/chat/rooms/${encodeURIComponent(currentRoom)}/invite`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ friend_uuid: friendUuid })
                    });
                    const result = await response.json();

                    if (result.success) {
                        showToast(`Invito inviato a ${friendName}!`, 'success');
                    } else {
                        showToast(result.error || 'Errore invio invito', 'error');
                    }
                } catch (error) {
                    console.error('[Chat] Invite error:', error);
                    showToast('Errore di connessione', 'error');
                } finally {
                    btn.disabled = false;
                    btn.classList.remove('opacity-50');
                }
            });
        });

        // ENTERPRISE V5.5: Show/hide invite buttons based on current room
        updateInviteButtonsVisibility();

    } catch (error) {
        console.error('[Chat] Failed to load friends:', error);
        skeleton?.classList.add('hidden');
        empty?.classList.remove('hidden');
    }
}

/**
 * ENTERPRISE V5.6: Update invite buttons visual state
 * Buttons are ALWAYS clickable - shows message if not in a room
 * Visual feedback: purple when in ANY room, gray when not in a room
 */
function updateInviteButtonsVisibility() {
    const currentRoom = window.chatManager?.currentRoom;
    const isInAnyRoom = !!currentRoom;

    document.querySelectorAll('.invite-friend-btn').forEach(btn => {
        const friendName = btn.dataset.friendName || 'Utente';

        if (isInAnyRoom) {
            // In a room (any type) - button is "active" (purple)
            btn.classList.remove('text-gray-500');
            btn.classList.add('text-purple-400');
            btn.title = `Invita ${friendName} in questa stanza`;
        } else {
            // Not in a room - button is "inactive" (gray) but still clickable
            btn.classList.remove('text-purple-400');
            btn.classList.add('text-gray-500');
            btn.title = `Invita ${friendName} in una stanza`;
        }
    });
}

/**
 * ENTERPRISE V10.0: Open DM conversation with friend
 *
 * ARCHITETTURA DESKTOP vs MOBILE:
 * - Desktop (≥768px): Opens Facebook-style widget popup (ChatWidgetManager)
 * - Mobile (<768px): Redirect to fullscreen DM page at /chat/dm/{uuid}
 *
 * Widget features (desktop):
 * - Minimizable/maximizable popup
 * - Multiple simultaneous conversations
 * - Position persistence (localStorage)
 * - Real-time via WebSocket
 *
 * Fullscreen features (mobile):
 * - E2E encryption
 * - Typing indicators
 * - Read receipts
 * - Touch-optimized UI
 */
async function openDMConversation(card) {
    const friendUuid = card.dataset.friendUuid;
    const friendName = card.dataset.friendName;

    // Validate UUID before proceeding
    if (!friendUuid || friendUuid === 'undefined' || friendUuid === 'null') {
        console.error('[Chat] Invalid friend UUID:', friendUuid);
        Need2Talk.FlashMessages?.show('Errore: impossibile aprire la chat', 'error', 3000);
        return;
    }

    // ENTERPRISE V10.0: Detect desktop vs mobile
    const isDesktop = window.innerWidth >= 768 &&
        !/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

    // Show loading indicator on the card
    card.classList.add('opacity-50', 'pointer-events-none');

    try {
        // First, get or create the conversation UUID via API
        const response = await fetch('/api/chat/dm', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ recipient_uuid: friendUuid })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Impossibile aprire la conversazione');
        }

        const conversationUuid = data.data?.conversation?.uuid;
        if (!conversationUuid) {
            throw new Error('Errore: conversazione non trovata');
        }

        // ENTERPRISE V10.80: Store conversation UUID on card for badge sync
        // This allows n2t:conversationMarkedRead event to find and update this card
        card.dataset.conversationUuid = conversationUuid;

        // ENTERPRISE V10.80: Immediately remove unread badge when opening conversation
        // Don't wait for typing/focus trigger - opening = intending to read
        const existingBadge = card.querySelector('.bg-purple-500');
        if (existingBadge) {
            existingBadge.remove();
        }

        // Restore card state
        card.classList.remove('opacity-50', 'pointer-events-none');

        if (isDesktop && window.chatWidgetManager) {
            // DESKTOP: Open Facebook-style widget popup

            // ENTERPRISE V9.6: Fetch fresh presence status before opening widget
            // Card's data-presence-status may be stale from initial page load
            let presenceStatus = card.dataset.presenceStatus || 'offline';
            try {
                const presenceResp = await fetch(`/api/chat/presence/${friendUuid}`);
                const presenceData = await presenceResp.json();
                if (presenceData.success && presenceData.data) {
                    presenceStatus = presenceData.data.status || 'offline';
                    // Update card's dataset for future reference
                    card.dataset.presenceStatus = presenceStatus;
                }
            } catch (e) {
                console.warn('[Chat] Failed to fetch fresh presence, using cached:', e);
            }

            // Get friend's full data for widget with fresh presence
            const otherUser = {
                uuid: friendUuid,
                nickname: friendName,
                avatar_url: card.querySelector('img')?.src || '/assets/img/default-avatar.png',
                status: presenceStatus
            };

            // Open widget via ChatWidgetManager
            window.chatWidgetManager.openWidget(conversationUuid, otherUser);

        } else {
            // MOBILE: Redirect to fullscreen DM page
            window.location.href = `/chat/dm/${conversationUuid}`;
        }

    } catch (error) {
        // ENTERPRISE: Log full error details (Error objects don't serialize to JSON)
        console.error('[Chat] DM error:', error?.message || error?.toString() || 'Unknown error', {
            name: error?.name,
            stack: error?.stack?.split('\n').slice(0, 3).join('\n')
        });
        Need2Talk.FlashMessages?.show(error.message || 'Errore di connessione', 'error', 5000);

        // Restore card
        card.classList.remove('opacity-50', 'pointer-events-none');
    }
}

/**
 * Show DM conversation UI
 * Replaces the emotion room chat with DM chat
 */
async function showDMConversationUI(friendUuid, friendName, card) {
    // Update UI to show DM mode
    document.getElementById('chatWelcome')?.classList.add('hidden');
    document.getElementById('chatActiveContainer')?.classList.remove('hidden');

    // Update header for DM
    const avatar = card.querySelector('img')?.src || '/assets/img/default-avatar.png';
    const statusIndicator = card.querySelector('.rounded-full.border-2');
    const isOnline = statusIndicator?.classList.contains('bg-green-500');

    document.getElementById('chatRoomIcon').innerHTML = `
        <img src="${avatar}" alt="${friendName}" class="w-8 h-8 rounded-full object-cover">
    `;
    document.getElementById('chatRoomName').textContent = friendName;
    document.getElementById('chatRoomStatus').textContent = isOnline ? 'Online' : 'Offline';
    document.getElementById('chatOnlineCount').innerHTML = `
        <span class="w-2 h-2 rounded-full ${isOnline ? 'bg-green-500 animate-pulse' : 'bg-gray-500'}"></span>
        <span>${isOnline ? 'Online' : 'Offline'}</span>
    `;

    // Show loading in message list
    if (window.messageList) {
        window.messageList.showLoading();
    }

    // Highlight selected friend
    document.querySelectorAll('.dm-friend-card').forEach(c => {
        c.classList.remove('bg-purple-600/20', 'ring-1', 'ring-purple-500/50');
    });
    card.classList.add('bg-purple-600/20', 'ring-1', 'ring-purple-500/50');

    try {
        // Get or create DM conversation
        // BUGFIX: Backend expects 'recipient_uuid' not 'friend_uuid'
        const response = await fetch('/api/chat/dm', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ recipient_uuid: friendUuid })
        });

        const data = await response.json();

        if (!data.success) {
            console.error('[Chat] Failed to open DM:', data.error);
            showDMError(data.error || 'Impossibile aprire la conversazione');
            return;
        }

        // API response structure: { success: true, data: { conversation: { uuid: "..." } } }
        const conversationUuid = data.data?.conversation?.uuid;

        if (!conversationUuid) {
            console.error('[Chat] No conversation UUID in response:', data);
            showDMError('Errore: conversazione non trovata');
            return;
        }

        // Store current DM context
        window.currentDMConversation = {
            uuid: conversationUuid,
            friendUuid: friendUuid,
            friendName: friendName
        };

        // Load messages
        const messagesResponse = await fetch(`/api/chat/dm/${conversationUuid}/messages?limit=50`);
        const messagesData = await messagesResponse.json();

        if (window.messageList && messagesData.success) {
            // API response structure: { success: true, data: { messages: [...] } }
            const encryptedMessages = messagesData.data?.messages || [];

            // ENTERPRISE V9.1: Decrypt messages before displaying
            // DM messages are E2E encrypted with encrypted_content, content_iv, content_tag
            const decryptedMessages = await decryptDMMessages(encryptedMessages, conversationUuid);

            // ENTERPRISE V9.2: Messages from API are DESC (newest first)
            // Reverse to show oldest first (top) → newest last (bottom)
            const sortedMessages = decryptedMessages.reverse();
            window.messageList.setMessages(sortedMessages, conversationUuid, 'dm');
        }

        // Update message input for DM mode
        if (window.messageInput) {
            window.messageInput.setContext({
                conversationId: conversationUuid,
                roomId: null,
                roomType: 'dm'
            });
        }

    } catch (error) {
        // ENTERPRISE: Log full error details (Error objects don't serialize to JSON)
        console.error('[Chat] DM error:', error?.message || error?.toString() || 'Unknown error', {
            name: error?.name,
            stack: error?.stack?.split('\n').slice(0, 3).join('\n')
        });
        showDMError('Errore di connessione');
    }
}

function showDMError(message) {
    console.error('[Chat] DM Error:', message);

    // Show error in chat container
    const messagesContainer = document.getElementById('chatMessagesContainer');
    if (messagesContainer) {
        messagesContainer.innerHTML = `
            <div class="flex flex-col items-center justify-center h-full text-center p-6">
                <div class="w-16 h-16 bg-red-500/20 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <p class="text-red-400 font-medium">${message}</p>
                <p class="text-gray-500 text-sm mt-2">Prova a ricaricare la pagina</p>
            </div>
        `;
    }

    // Also show flash message
    Need2Talk.FlashMessages?.show(message, 'error', 5000);
}

/**
 * ENTERPRISE V10.50: Setup real-time presence listener
 * Updates friend list when any friend changes status
 *
 * ARCHITECTURE NOTE (V10.50):
 * Single unified WebSocketManager (core/websocket-manager.js)
 * Exposed as window.WebSocketManager (primary) and Need2Talk.WebSocketManager (legacy alias)
 */
// ENTERPRISE V10.63: Flag to prevent duplicate handler registration
let presenceHandlerRegistered = false;

function setupPresenceListener() {
    // Try to register immediately
    registerPresenceHandler();

    // ENTERPRISE V10.63: Also listen for WebSocket connection event
    // This handles the case where WebSocket connects AFTER this function runs
    window.addEventListener('n2t:wsConnected', () => {
        registerPresenceHandler();
    });

    // ENTERPRISE V10.41: Also listen via document event for components that dispatch there
    // This works regardless of WebSocketManager timing
    document.addEventListener('websocket:friend_presence_changed', (e) => {
        const { user_uuid, status, is_online } = e.detail;
        updateFriendPresenceUI(user_uuid, status, is_online);
    });
}

/**
 * ENTERPRISE V10.63: Register the presence handler on WebSocketManager
 * Separated from setupPresenceListener for retry logic
 */
function registerPresenceHandler() {
    // Prevent duplicate registration
    if (presenceHandlerRegistered) {
        return;
    }

    const wsManager = window.WebSocketManager;

    if (!wsManager || typeof wsManager.on !== 'function') {
        console.warn('[Chat] WebSocketManager not available yet for presence updates');
        return;
    }

    // Register handler for friend presence updates
    // ENTERPRISE V10.11 FIX: WebSocketManager passes { type, payload, timestamp }
    wsManager.on('friend_presence_changed', (data) => {
        const payload = data.payload || data;
        updateFriendPresenceUI(payload.user_uuid, payload.status, payload.is_online);
    });

    presenceHandlerRegistered = true;
}

/**
 * Update friend's presence in the UI
 * ENTERPRISE V9.3: Properly handles online/dnd/invisible statuses
 * @param {string} friendUuid
 * @param {string} status - 'online', 'dnd', 'invisible', 'offline'
 * @param {boolean} isOnline
 */
function updateFriendPresenceUI(friendUuid, status, isOnline) {
    const card = document.querySelector(`.dm-friend-card[data-friend-uuid="${friendUuid}"]`);
    if (!card) return;

    // ENTERPRISE V9.3: Determine presence colors based on status
    let dotColor, textColor, label;
    if (status === 'dnd') {
        dotColor = 'bg-yellow-500';
        textColor = 'text-yellow-400';
        label = 'Occupato';
    } else if (status === 'invisible' || !isOnline) {
        dotColor = 'bg-gray-500';
        textColor = 'text-gray-500';
        label = 'Offline';
    } else {
        dotColor = 'bg-green-500';
        textColor = 'text-green-400';
        label = 'Online';
    }

    // Update status indicator (colored dot)
    const statusDot = card.querySelector('.presence-dot');
    if (statusDot) {
        statusDot.className = `presence-dot absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-gray-800 ${dotColor}`;
    }

    // Update status text
    const statusText = card.querySelector('.presence-text');
    if (statusText) {
        statusText.className = `presence-text text-xs ${textColor}`;
        statusText.textContent = label;
    }

    // Update data attribute
    card.dataset.presenceStatus = status;

    // Re-sort the list (online friends first)
    resortFriendsList();
}

/**
 * Re-sort friends list: online first, then alphabetically
 * ENTERPRISE V5.6 FIX: Sort wrapper elements, not just cards (invite button is in wrapper)
 */
function resortFriendsList() {
    const list = document.querySelector('.dm-friends-list');
    if (!list) return;

    // Get wrappers (contain both dm-friend-card and invite button)
    const wrappers = Array.from(list.querySelectorAll('.dm-friend-card-wrapper'));

    wrappers.sort((a, b) => {
        const aCard = a.querySelector('.dm-friend-card');
        const bCard = b.querySelector('.dm-friend-card');
        if (!aCard || !bCard) return 0;

        const aOnline = aCard.querySelector('.bg-green-500') !== null;
        const bOnline = bCard.querySelector('.bg-green-500') !== null;

        if (aOnline && !bOnline) return -1;
        if (!aOnline && bOnline) return 1;

        const aName = aCard.dataset.friendName || '';
        const bName = bCard.dataset.friendName || '';
        return aName.localeCompare(bName);
    });

    // Re-append wrappers in sorted order (preserves invite buttons)
    wrappers.forEach((wrapper, index) => {
        // Fix margins after re-sort
        wrapper.classList.remove('mt-1');
        if (index > 0) wrapper.classList.add('mt-1');
        list.appendChild(wrapper);
    });
}

// ============================================================================
// ENTERPRISE V10.54: Online Users Modal Functions
// Real-time list of users connected to a room (max 20)
// ============================================================================

// State for online users modal
let onlineUsersModalState = {
    isOpen: false,
    roomId: null,
    roomName: '',
    roomIcon: '💬',
    users: []
};

/**
 * Show online users modal for a room
 * @param {string} roomId - Room UUID or emotion room ID
 * @param {string} roomName - Room display name
 * @param {string} roomIcon - Room emoji/icon
 */
async function showOnlineUsersModal(roomId, roomName, roomIcon = '💬', createdAt = null) {
    const modal = document.getElementById('onlineUsersModal');
    const skeleton = modal?.querySelector('.online-users-skeleton');
    const empty = modal?.querySelector('.online-users-empty');
    const container = modal?.querySelector('.online-users-container');
    const roomNameEl = document.getElementById('onlineUsersRoomName');
    const roomIconEl = document.getElementById('onlineUsersRoomIcon');
    const countEl = document.getElementById('onlineUsersCount');
    const createdAtEl = document.getElementById('onlineUsersCreatedAt');

    if (!modal) return;

    // Update state
    onlineUsersModalState = {
        isOpen: true,
        roomId,
        roomName,
        roomIcon,
        createdAt,
        users: []
    };

    // Update UI
    roomNameEl.textContent = roomName;
    roomIconEl.textContent = roomIcon;
    countEl.textContent = 'Caricamento...';

    // ENTERPRISE V10.83: Show creation datetime for user-created rooms
    if (createdAt && createdAtEl) {
        const date = new Date(createdAt);
        const formatted = date.toLocaleString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        createdAtEl.textContent = `Creata: ${formatted}`;
        createdAtEl.classList.remove('hidden');
    } else if (createdAtEl) {
        createdAtEl.classList.add('hidden');
    }

    // Show skeleton, hide others
    skeleton?.classList.remove('hidden');
    empty?.classList.add('hidden');
    container?.classList.add('hidden');

    // Show modal
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    // Fetch online users
    try {
        const encodedRoomId = encodeURIComponent(roomId);
        const response = await fetch(`/api/chat/rooms/${encodedRoomId}/online`);
        const data = await response.json();

        skeleton?.classList.add('hidden');

        const users = data.data?.users || [];
        const count = data.data?.count || users.length;

        // Update count
        countEl.textContent = `${count} utent${count === 1 ? 'e' : 'i'} conness${count === 1 ? 'o' : 'i'}`;

        if (users.length === 0) {
            empty?.classList.remove('hidden');
            container?.classList.add('hidden');
        } else {
            empty?.classList.add('hidden');
            container?.classList.remove('hidden');
            renderOnlineUsers(users.slice(0, 20)); // Max 20
        }

        onlineUsersModalState.users = users;

    } catch (error) {
        console.error('[OnlineUsers] Failed to fetch:', error);
        skeleton?.classList.add('hidden');
        empty?.classList.remove('hidden');
        countEl.textContent = 'Errore di caricamento';
    }
}

/**
 * Render online users list
 * @param {Array} users - Array of user objects {uuid, nickname, avatar_url}
 */
function renderOnlineUsers(users) {
    const container = document.querySelector('#onlineUsersModal .online-users-container');
    if (!container) return;

    container.innerHTML = users.map(user => `
        <div class="online-user-item flex items-center gap-3 p-2 bg-gray-700/30 hover:bg-gray-700/50 rounded-lg transition-colors"
             data-user-uuid="${user.uuid || user.user_uuid || ''}">
            <div class="relative flex-shrink-0">
                <img src="${user.avatar_url || user.avatar || '/assets/img/default-avatar.png'}"
                     alt="${user.nickname || 'Utente'}"
                     class="w-10 h-10 rounded-full object-cover"
                     onerror="this.src='/assets/img/default-avatar.png'">
                <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-500 rounded-full border-2 border-gray-800"></span>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-white truncate">${user.nickname || 'Utente'}</p>
                <p class="text-[10px] text-gray-500">Online ora</p>
            </div>
        </div>
    `).join('');
}

/**
 * Hide online users modal
 */
function hideOnlineUsersModal() {
    const modal = document.getElementById('onlineUsersModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }
    onlineUsersModalState.isOpen = false;
    onlineUsersModalState.roomId = null;
}

/**
 * Update online users list in real-time (called on user join/leave events)
 * @param {string} roomId - Room that changed
 * @param {number} newCount - New online count
 */
function updateOnlineUsersModalIfOpen(roomId, newCount) {
    if (!onlineUsersModalState.isOpen || onlineUsersModalState.roomId !== roomId) {
        return;
    }

    // Update count display
    const countEl = document.getElementById('onlineUsersCount');
    if (countEl) {
        countEl.textContent = `${newCount} utent${newCount === 1 ? 'e' : 'i'} conness${newCount === 1 ? 'o' : 'i'}`;
    }

    // Re-fetch the full list for accurate data
    showOnlineUsersModal(
        onlineUsersModalState.roomId,
        onlineUsersModalState.roomName,
        onlineUsersModalState.roomIcon,
        onlineUsersModalState.createdAt
    );
}

/**
 * ENTERPRISE V10.79: Current room context for the chat header user list
 * Tracked separately from ChatManager to ensure instant UI access
 */
let currentChatRoomContext = {
    roomId: null,
    roomName: '',
    roomIcon: '💬',
    createdAt: null
};

/**
 * Setup online users modal event listeners
 */
function setupOnlineUsersModalListeners() {
    const modal = document.getElementById('onlineUsersModal');
    const closeBtn = document.getElementById('closeOnlineUsersModal');

    // Close button
    closeBtn?.addEventListener('click', hideOnlineUsersModal);

    // Close on backdrop click
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) {
            hideOnlineUsersModal();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && onlineUsersModalState.isOpen) {
            hideOnlineUsersModal();
        }
    });

    // Listen for real-time updates from WebSocket
    document.addEventListener('chat:user_joined', (e) => {
        updateOnlineUsersModalIfOpen(e.detail.room_id, e.detail.online_count);
    });

    document.addEventListener('chat:user_left', (e) => {
        updateOnlineUsersModalIfOpen(e.detail.room_id, e.detail.online_count);
    });

    document.addEventListener('chat:online_count_updated', (e) => {
        updateOnlineUsersModalIfOpen(e.detail.roomId, e.detail.count);
    });

    // ENTERPRISE V10.79: Track current room context when joining
    document.addEventListener('chat:room_joined', (e) => {
        const { roomId, room } = e.detail;
        const emotionRoomInfo = window.emotionRoomSelector?.getRoomInfo(roomId);

        if (emotionRoomInfo) {
            // Emotion room
            currentChatRoomContext = {
                roomId: roomId,
                roomName: emotionRoomInfo.name || 'Room',
                roomIcon: emotionRoomInfo.emoji || '💬',
                createdAt: null // Emotion rooms don't have creation date
            };
        } else if (room?.name) {
            // User-created room
            currentChatRoomContext = {
                roomId: roomId,
                roomName: room.name,
                roomIcon: room.emotion_icon || '💬',
                createdAt: room.created_at || null
            };
        } else {
            // Fallback
            currentChatRoomContext = {
                roomId: roomId,
                roomName: 'Room',
                roomIcon: '💬',
                createdAt: null
            };
        }
    });

    // ENTERPRISE V10.79: Clear context when leaving room
    document.addEventListener('chat:room_left', () => {
        currentChatRoomContext = { roomId: null, roomName: '', roomIcon: '💬', createdAt: null };
    });

    // ENTERPRISE V10.79: Click handler for chat header online count
    // Shows user list for the CURRENT room you're in
    const chatOnlineCount = document.getElementById('chatOnlineCount');
    chatOnlineCount?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        if (currentChatRoomContext.roomId) {
            showOnlineUsersModal(
                currentChatRoomContext.roomId,
                currentChatRoomContext.roomName,
                currentChatRoomContext.roomIcon,
                currentChatRoomContext.createdAt
            );
        } else {
            console.warn('[OnlineUsers] No current room to show users for');
        }
    });

    // ENTERPRISE V10.54: Click handler for emotion room counters
    document.addEventListener('click', (e) => {
        // Check if clicked on emotion room counter (.count or .room-online-badge)
        const emotionCounter = e.target.closest('.emotion-card .count, .emotion-card .room-online-badge');
        if (emotionCounter) {
            e.preventDefault();
            e.stopPropagation();

            const card = emotionCounter.closest('.emotion-card');
            if (card) {
                const roomId = card.dataset.roomId;
                const roomName = card.dataset.roomName || 'Room';
                const roomEmoji = card.dataset.roomEmoji || '💬';
                showOnlineUsersModal(roomId, roomName, roomEmoji);
            }
            return;
        }

        // Check if clicked on user room counter (.room-online-counter)
        const userRoomCounter = e.target.closest('.user-room-card .room-online-counter');
        if (userRoomCounter) {
            e.preventDefault();
            e.stopPropagation();

            const card = userRoomCounter.closest('.user-room-card');
            if (card) {
                const roomId = card.dataset.roomUuid;
                const roomName = card.dataset.roomName || 'Room';
                showOnlineUsersModal(roomId, roomName, '💬');
            }
            return;
        }
    });
}

function setupChatUIListeners() {
    // ===================================================================
    // PRESENCE STATUS TOGGLE (Semaforo) - ENTERPRISE V9.0
    // ===================================================================
    const presenceToggleBtn = document.getElementById('presenceToggleBtn');
    const presenceDropdown = document.getElementById('presenceDropdown');
    const presenceIndicator = document.getElementById('presenceIndicator');
    const presenceLabel = document.getElementById('presenceLabel');

    // Presence status config
    const presenceConfig = {
        online: { color: 'bg-green-500', label: 'Online', animate: true },
        dnd: { color: 'bg-yellow-500', label: 'Occupato', animate: false },
        invisible: { color: 'bg-gray-500', label: 'Invisibile', animate: false }
    };

    // ENTERPRISE V10.3: Initialize from server-side status (no flash)
    let currentPresenceStatus = '<?= $currentStatus ?>';

    // Toggle dropdown
    presenceToggleBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        presenceDropdown?.classList.toggle('hidden');
    });

    // Close dropdown on outside click
    document.addEventListener('click', () => {
        presenceDropdown?.classList.add('hidden');
    });

    // Handle presence option selection
    document.querySelectorAll('.presence-option').forEach(option => {
        option.addEventListener('click', async () => {
            const newStatus = option.dataset.status;
            if (newStatus === currentPresenceStatus) {
                presenceDropdown?.classList.add('hidden');
                return;
            }

            // Optimistic UI update
            const config = presenceConfig[newStatus];
            if (config && presenceIndicator && presenceLabel) {
                // Update indicator color
                presenceIndicator.className = `w-3 h-3 rounded-full ${config.color}`;
                if (config.animate) {
                    presenceIndicator.classList.add('animate-pulse');
                }
                presenceLabel.textContent = config.label;
            }

            presenceDropdown?.classList.add('hidden');

            // Call API to update presence
            try {
                const response = await fetch('/api/chat/presence/status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ status: newStatus })
                });

                const data = await response.json();
                if (data.success) {
                    currentPresenceStatus = newStatus;

                    // Dispatch event for other components
                    document.dispatchEvent(new CustomEvent('chat:presence_changed', {
                        detail: { status: newStatus }
                    }));
                } else {
                    // Revert UI on failure
                    const prevConfig = presenceConfig[currentPresenceStatus];
                    if (prevConfig && presenceIndicator && presenceLabel) {
                        presenceIndicator.className = `w-3 h-3 rounded-full ${prevConfig.color}`;
                        if (prevConfig.animate) {
                            presenceIndicator.classList.add('animate-pulse');
                        }
                        presenceLabel.textContent = prevConfig.label;
                    }
                    console.error('[Chat] Failed to update presence:', data.error);
                }
            } catch (error) {
                console.error('[Chat] Presence API error:', error);
            }
        });
    });

    // ENTERPRISE V10.7: Listen for status changes from other components (DM, Widget, etc.)
    // This updates the presence indicator when sender auto-switches from DND to online
    window.addEventListener('n2t:ownStatusChanged', (event) => {
        const newStatus = event.detail?.status;
        if (newStatus && newStatus !== currentPresenceStatus) {
            // Update UI
            const config = presenceConfig[newStatus];
            if (config && presenceIndicator && presenceLabel) {
                presenceIndicator.className = `w-3 h-3 rounded-full ${config.color}`;
                if (config.animate) {
                    presenceIndicator.classList.add('animate-pulse');
                } else {
                    presenceIndicator.classList.remove('animate-pulse');
                }
                presenceLabel.textContent = config.label;
            }

            // Update internal state
            currentPresenceStatus = newStatus;

            // Dispatch for other components
            document.dispatchEvent(new CustomEvent('chat:presence_changed', {
                detail: { status: newStatus, source: 'external' }
            }));
        }
    });

    // ENTERPRISE V10.6: Send initial heartbeat WITH status (belt-and-suspenders with backend fix)
    (async function initPresence() {
        try {
            // Status is already server-rendered in PHP (no API fetch needed = no flash)
            // Send heartbeat to activate presence - include status for redundancy
            await fetch('/api/chat/presence/heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status: currentPresenceStatus })
            });
        } catch (e) {
            console.warn('[Chat] Failed to initialize presence:', e);
        }
    })();

    // Periodic heartbeat (every 2 minutes)
    setInterval(async () => {
        if (currentPresenceStatus !== 'invisible') {
            try {
                const currentRoom = window.chatManager?.currentRoom || null;
                await fetch('/api/chat/presence/heartbeat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        current_room_id: currentRoom,
                        status: currentPresenceStatus  // Always include status
                    })
                });
            } catch (e) {
                // Silent fail
            }
        }
    }, 120000); // 2 minutes

    // ===================================================================
    // Create room modal with emotion selector
    // ===================================================================
    const createRoomBtn = document.getElementById('createRoomBtn');
    const createRoomModal = document.getElementById('createRoomModal');
    const closeCreateRoomModal = document.getElementById('closeCreateRoomModal');
    const createRoomForm = document.getElementById('createRoomForm');
    const emotionSelector = document.getElementById('emotionSelector');
    const roomEmotionId = document.getElementById('roomEmotionId');
    const emotionError = document.getElementById('emotionError');

    // Track selected emotion
    let selectedEmotionId = null;

    if (createRoomBtn && createRoomModal) {
        createRoomBtn.addEventListener('click', () => {
            createRoomModal.classList.remove('hidden');
            // Reset form
            createRoomForm?.reset();
            selectedEmotionId = null;
            roomEmotionId.value = '';
            emotionError?.classList.add('hidden');
            // Reset emotion buttons
            document.querySelectorAll('.emotion-option').forEach(btn => {
                btn.classList.remove('border-purple-500', 'bg-purple-600/20');
                btn.classList.add('border-transparent');
            });
        });

        closeCreateRoomModal?.addEventListener('click', () => {
            createRoomModal.classList.add('hidden');
        });

        // Close on backdrop click
        createRoomModal.addEventListener('click', (e) => {
            if (e.target === createRoomModal) {
                createRoomModal.classList.add('hidden');
            }
        });
    }

    // Emotion selector click handler
    if (emotionSelector) {
        emotionSelector.addEventListener('click', (e) => {
            const btn = e.target.closest('.emotion-option');
            if (!btn) return;

            // Deselect all
            document.querySelectorAll('.emotion-option').forEach(b => {
                b.classList.remove('border-purple-500', 'bg-purple-600/20');
                b.classList.add('border-transparent');
            });

            // Select clicked
            btn.classList.remove('border-transparent');
            btn.classList.add('border-purple-500', 'bg-purple-600/20');

            selectedEmotionId = parseInt(btn.dataset.emotionId);
            roomEmotionId.value = selectedEmotionId;
            emotionError?.classList.add('hidden');
        });
    }

    // Form submission
    if (createRoomForm) {
        createRoomForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Validate emotion selected
            if (!selectedEmotionId) {
                emotionError?.classList.remove('hidden');
                return;
            }

            const submitBtn = document.getElementById('createRoomSubmitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<svg class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Creazione...';

            try {
                const formData = {
                    name: document.getElementById('roomName').value.trim(),
                    description: document.getElementById('roomDescription').value.trim(),
                    emotion_id: selectedEmotionId
                };

                const response = await fetch('/api/chat/rooms', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });

                const data = await response.json();

                if (data.success) {
                    // Close modal and reset form
                    createRoomModal.classList.add('hidden');
                    createRoomForm.reset();
                    selectedEmotionId = null;
                    roomEmotionId.value = '';
                    document.querySelectorAll('.emotion-option').forEach(btn => {
                        btn.classList.remove('border-purple-500', 'bg-purple-600/20');
                        btn.classList.add('border-transparent');
                    });

                    // Show success message
                    Need2Talk.FlashMessages?.show('Room creata con successo!', 'success', 3000);

                    // Refresh user rooms list
                    loadUserRooms();

                    // API returns { success, data: { room: {...} } }
                    const newRoom = data.data?.room;
                    if (newRoom?.uuid && window.chatManager) {
                        window.chatManager.joinRoom(newRoom.uuid, 'user_created');
                    }
                } else {
                    Need2Talk.FlashMessages?.show(data.error || 'Errore nella creazione della room', 'error', 5000);
                }
            } catch (error) {
                console.error('[Chat] Create room error:', error);
                Need2Talk.FlashMessages?.show('Errore di connessione', 'error', 5000);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    }

    // Refresh user rooms button
    document.getElementById('refreshUserRoomsBtn')?.addEventListener('click', () => {
        const container = document.getElementById('userRoomsContainer');
        const skeleton = container?.querySelector('.user-rooms-skeleton');
        const list = container?.querySelector('.user-rooms-list');
        skeleton?.classList.remove('hidden');
        list?.classList.add('hidden');
        loadUserRooms();
    });

    // Back button - leave room and show welcome
    const chatBackBtn = document.getElementById('chatBackBtn');
    if (chatBackBtn) {
        chatBackBtn.addEventListener('click', async () => {
            if (window.chatManager?.currentRoom) {
                await window.chatManager.leaveRoom(window.chatManager.currentRoom);
            }
            document.getElementById('chatWelcome')?.classList.remove('hidden');
            document.getElementById('chatActiveContainer')?.classList.add('hidden');
        });
    }

    // ENTERPRISE: Show skeleton while room is loading
    document.addEventListener('chat:room_loading', (e) => {
        // Show chat area immediately with loading skeleton
        document.getElementById('chatWelcome')?.classList.add('hidden');
        document.getElementById('chatActiveContainer')?.classList.remove('hidden');

        // Update header with room info (optimistic UI)
        const { room } = e.detail;
        if (room) {
            document.getElementById('chatRoomIcon').textContent = room.emoji || '💬';
            document.getElementById('chatRoomName').textContent = room.name || 'Chat Room';
            document.getElementById('chatRoomStatus').textContent = room.description || '';
        }

        // Show loading skeleton in message list
        if (window.messageList) {
            window.messageList.showLoading();
        }
    });

    // Handle join failure - revert UI
    document.addEventListener('chat:room_join_failed', (e) => {
        console.error('[Chat] Failed to join room:', e.detail.error);
        document.getElementById('chatWelcome')?.classList.remove('hidden');
        document.getElementById('chatActiveContainer')?.classList.add('hidden');
    });

    // ENTERPRISE V10.83: Handle room join error with user notification
    document.addEventListener('chat:room_join_error', (e) => {
        const { errorCode, message } = e.detail;
        console.error('[Chat] Room join error:', errorCode, message);

        // Show welcome area, hide chat
        document.getElementById('chatWelcome')?.classList.remove('hidden');
        document.getElementById('chatActiveContainer')?.classList.add('hidden');

        // Show toast notification
        showToast(message, 'error');

        // If room was archived, refresh the room list
        if (errorCode === 'room_archived' || errorCode === 'room_not_found') {
            loadUserRooms();
        }
    });

    // Listen for room join events to show active chat
    document.addEventListener('chat:room_joined', (e) => {
        document.getElementById('chatWelcome')?.classList.add('hidden');
        document.getElementById('chatActiveContainer')?.classList.remove('hidden');

        // ENTERPRISE V9.3: Extract room data from event (includes name, description, emotion_icon for user rooms)
        const { roomId, room, onlineCount, messages } = e.detail;

        // Try emotion rooms first (for emotion:* rooms)
        const emotionRoomInfo = window.emotionRoomSelector?.getRoomInfo(roomId);

        if (emotionRoomInfo) {
            // Emotion room - use EmotionRoomSelector data
            document.getElementById('chatRoomIcon').textContent = emotionRoomInfo.emoji || '💬';
            document.getElementById('chatRoomName').textContent = emotionRoomInfo.name || 'Chat Room';
            document.getElementById('chatRoomStatus').textContent = emotionRoomInfo.description || '';
        } else if (room?.name) {
            // User-created room - use room data from API response
            // ENTERPRISE V9.3: Display user room name and description correctly
            document.getElementById('chatRoomIcon').textContent = room.emotion_icon || '💬';
            document.getElementById('chatRoomName').textContent = room.name;
            document.getElementById('chatRoomStatus').textContent = room.description || room.emotion_name || '';
        } else {
            // Fallback for unknown room types
            document.getElementById('chatRoomIcon').textContent = '💬';
            document.getElementById('chatRoomName').textContent = 'Chat Room';
            document.getElementById('chatRoomStatus').textContent = '';
        }

        // ENTERPRISE V10.82: Fixed selector - span:nth-of-type(2) because SVG icon is now last child
        const onlineCountEl = document.getElementById('chatOnlineCount')?.querySelector('span:nth-of-type(2)');
        if (onlineCountEl) {
            onlineCountEl.textContent = `${onlineCount || 0} online`;
        }

        // ENTERPRISE FIX: Use setMessages() to properly set chatId context
        // clear() resets chatId to null, which breaks the event listener for new messages
        if (window.messageList) {
            // ENTERPRISE V9.2: Messages from API are DESC (newest first)
            // Reverse to show oldest first (top) → newest last (bottom)
            const sortedMessages = (messages || []).reverse();
            window.messageList.setMessages(sortedMessages, roomId, 'room');
        }

        // ENTERPRISE V5.5: Show/hide invite buttons based on room type
        updateInviteButtonsVisibility();
    });

    document.addEventListener('chat:room_left', () => {
        document.getElementById('chatWelcome')?.classList.remove('hidden');
        document.getElementById('chatActiveContainer')?.classList.add('hidden');

        // ENTERPRISE V5.5: Hide invite buttons when leaving room
        updateInviteButtonsVisibility();
    });

    // Listen for new messages
    document.addEventListener('chat:message_received', (e) => {
        if (window.messageList && e.detail?.message) {
            window.messageList.addMessage(e.detail.message);
        }
    });

    // Listen for online count updates
    // ENTERPRISE V10.82: Fixed selector - span:nth-of-type(2) because SVG icon is now last child
    document.addEventListener('chat:online_count_updated', (e) => {
        const countEl = document.getElementById('chatOnlineCount')?.querySelector('span:nth-of-type(2)');
        if (countEl && e.detail?.count !== undefined) {
            countEl.textContent = `${e.detail.count} online`;
        }
    });

    // Listen for typing updates
    document.addEventListener('chat:typing_update', (e) => {
        if (window.typingIndicator && e.detail?.typingUsers) {
            window.typingIndicator.setTypers(e.detail.typingUsers);
        }
    });
}
</script>
