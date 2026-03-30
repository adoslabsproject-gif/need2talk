<?php
/**
 * Chat Room View - Single Room/Conversation (CONTENT ONLY)
 * Enterprise Galaxy Chat System
 *
 * ARCHITETTURA ENTERPRISE:
 * - Content-only (wrapped by layouts/app-post-login.php)
 * - Real-time via Swoole WebSocket
 * - Virtual scroll for 10k+ messages
 * - Typing indicators + presence
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */

// ENTERPRISE V10.183: Hide FloatingRecorder on chat room pages
$hideFloatingRecorder = true;

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Room data from controller
$roomId = htmlspecialchars($room['uuid'] ?? $room['id'] ?? '', ENT_QUOTES, 'UTF-8');
$roomName = htmlspecialchars($room['name'] ?? 'Chat', ENT_QUOTES, 'UTF-8');
$roomType = htmlspecialchars($room['type'] ?? 'emotion', ENT_QUOTES, 'UTF-8');
$roomEmoji = htmlspecialchars($room['emoji'] ?? '💬', ENT_QUOTES, 'UTF-8');
$roomDescription = htmlspecialchars($room['description'] ?? '', ENT_QUOTES, 'UTF-8');
$onlineCount = (int) ($room['online_count'] ?? 0);
$isCreator = isset($room['creator_uuid']) && $room['creator_uuid'] === ($user['uuid'] ?? '');

// User data
$userName = htmlspecialchars($user['nickname'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$userAvatar = htmlspecialchars($user['avatar_url'] ?? '/assets/img/default-avatar.png', ENT_QUOTES, 'UTF-8');
$userUuid = htmlspecialchars($user['uuid'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<!-- Room View - Full Height -->
<main class="min-h-screen max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-16">

    <div class="flex flex-col lg:flex-row gap-6 h-[calc(100vh-5rem)]">

        <!-- Main Chat Area -->
        <div class="flex-1 flex flex-col bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50 overflow-hidden">

            <!-- Room Header -->
            <header class="px-6 py-4 border-b border-gray-700/50 flex items-center justify-between shrink-0">
                <div class="flex items-center">
                    <!-- Back Button -->
                    <a href="/chat" class="mr-4 p-2 hover:bg-gray-700/50 rounded-lg transition-colors" title="Torna alla chat">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </a>

                    <!-- Room Icon -->
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-2xl mr-4 shadow-lg">
                        <?= $roomEmoji ?>
                    </div>

                    <!-- Room Info -->
                    <div>
                        <h1 class="text-lg font-bold text-white flex items-center">
                            <?= $roomName ?>
                            <?php if ($roomType === 'emotion'): ?>
                            <span class="ml-2 px-2 py-0.5 bg-purple-600/30 text-purple-300 text-xs rounded-full">Emotion</span>
                            <?php elseif ($roomType === 'private'): ?>
                            <span class="ml-2 px-2 py-0.5 bg-gray-600/30 text-gray-300 text-xs rounded-full flex items-center">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                Privata
                            </span>
                            <?php endif; ?>
                        </h1>
                        <div class="text-sm text-gray-400 flex items-center flex-wrap gap-2">
                            <!-- ENTERPRISE V10.81: Clickable online count with visual indicator -->
                            <button id="roomOnlineCountBtn"
                                    class="group flex items-center gap-2 hover:text-white bg-gray-700/30 hover:bg-gray-700/70 px-2.5 py-1 rounded-lg transition-all cursor-pointer border border-gray-600/50 hover:border-purple-500/50"
                                    title="Clicca per vedere gli utenti online">
                                <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                                <span><span id="roomOnlineCount"><?= $onlineCount ?></span> online</span>
                                <!-- Users icon indicator -->
                                <svg class="w-4 h-4 text-gray-500 group-hover:text-purple-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </button>
                            <?php if ($roomDescription): ?>
                            <span class="text-gray-600">•</span>
                            <span class="truncate max-w-xs"><?= $roomDescription ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Room Actions -->
                <div class="flex items-center space-x-2">
                    <?php if ($isCreator): ?>
                    <button id="roomSettingsBtn" class="p-2 hover:bg-gray-700/50 rounded-lg transition-colors" title="Impostazioni room">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </button>
                    <?php endif; ?>

                    <button id="toggleUsersPanel" class="p-2 hover:bg-gray-700/50 rounded-lg transition-colors lg:hidden" title="Mostra utenti">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                    </button>

                    <button id="leaveRoomBtn" class="p-2 hover:bg-red-500/20 rounded-lg transition-colors text-red-400 hover:text-red-300" title="Esci dalla room">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Messages Container (Virtual Scroll) -->
            <div id="messagesContainer" class="flex-1 overflow-hidden relative" role="log" aria-live="polite">
                <!-- MessageList.js renders here -->
                <div class="messages-loading absolute inset-0 flex items-center justify-center">
                    <div class="text-center">
                        <div class="inline-block w-8 h-8 border-4 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
                        <p class="text-gray-400 text-sm mt-3">Caricamento messaggi...</p>
                    </div>
                </div>
            </div>

            <!-- Typing Indicator -->
            <div id="typingIndicator" class="px-4 py-2 shrink-0">
                <!-- TypingIndicator.js renders here -->
            </div>

            <!-- Message Input -->
            <div id="messageInputContainer" class="p-4 border-t border-gray-700/50 shrink-0">
                <!-- MessageInput.js renders here -->
            </div>

        </div>

        <!-- Right Sidebar: Online Users -->
        <aside id="usersPanel" class="hidden lg:block w-72 shrink-0">
            <div class="bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50 h-full flex flex-col">
                <div class="p-4 border-b border-gray-700/50">
                    <h2 class="text-sm font-semibold text-gray-300 uppercase tracking-wider flex items-center justify-between">
                        <span>Utenti nella room</span>
                        <span id="usersPanelCount" class="text-gray-500"><?= $onlineCount ?></span>
                    </h2>
                </div>

                <div id="usersListContainer" class="flex-1 overflow-y-auto p-2">
                    <!-- UserPresence.js renders user list here -->
                    <div class="users-loading space-y-2">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="flex items-center p-2 bg-gray-700/30 rounded-lg animate-pulse">
                            <div class="w-8 h-8 bg-gray-600 rounded-full mr-3"></div>
                            <div class="flex-1">
                                <div class="h-3 bg-gray-600 rounded w-20"></div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </aside>

    </div>

</main>

<!-- Mobile Users Panel (Slide-over) -->
<div id="mobileUsersPanel" class="fixed inset-0 z-50 hidden lg:hidden">
    <div class="absolute inset-0 bg-black/50" id="mobileUsersPanelOverlay"></div>
    <div class="absolute right-0 top-0 bottom-0 w-80 bg-gray-800 border-l border-gray-700 transform translate-x-full transition-transform duration-300" id="mobileUsersPanelContent">
        <div class="p-4 border-b border-gray-700 flex items-center justify-between">
            <h2 class="font-semibold text-white">Utenti nella room</h2>
            <button id="closeMobileUsersPanel" class="p-2 hover:bg-gray-700 rounded-lg">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div id="mobileUsersListContainer" class="p-4 overflow-y-auto h-[calc(100%-4rem)]">
            <!-- Cloned from usersListContainer -->
        </div>
    </div>
</div>

<!-- Report Modal Container -->
<div id="reportModalContainer"></div>

<!-- ENTERPRISE V10.79: Online Users Modal (same as index.php for consistency) -->
<div id="onlineUsersModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-sm w-full overflow-hidden">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b border-gray-700">
                <div class="flex items-center">
                    <span id="onlineUsersRoomIcon" class="text-2xl mr-3"><?= $roomEmoji ?></span>
                    <div>
                        <h2 id="onlineUsersRoomName" class="text-lg font-bold text-white"><?= $roomName ?></h2>
                        <p id="onlineUsersCount" class="text-xs text-gray-400">0 utenti connessi</p>
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

<!-- Room Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize room-specific chat
    const roomConfig = {
        roomId: '<?= $roomId ?>',
        roomName: '<?= addslashes($roomName) ?>',
        roomType: '<?= $roomType ?>',
        roomEmoji: '<?= $roomEmoji ?>',
        isCreator: <?= $isCreator ? 'true' : 'false' ?>,
        userUuid: '<?= $userUuid ?>',
        userName: '<?= addslashes($userName) ?>',
        userAvatar: '<?= addslashes($userAvatar) ?>',
    };

    // If ChatManager exists and is initialized
    if (window.chatManager) {
        window.chatManager.joinRoom(roomConfig.roomId, roomConfig.roomType);
    } else {
        console.log('[Room] Waiting for ChatManager...');
        window.pendingRoomConfig = roomConfig;
    }

    // Mobile users panel toggle
    const toggleBtn = document.getElementById('toggleUsersPanel');
    const mobilePanel = document.getElementById('mobileUsersPanel');
    const mobilePanelContent = document.getElementById('mobileUsersPanelContent');
    const closePanelBtn = document.getElementById('closeMobileUsersPanel');
    const overlay = document.getElementById('mobileUsersPanelOverlay');

    if (toggleBtn && mobilePanel) {
        toggleBtn.addEventListener('click', () => {
            mobilePanel.classList.remove('hidden');
            setTimeout(() => {
                mobilePanelContent.classList.remove('translate-x-full');
            }, 10);
        });

        const closePanel = () => {
            mobilePanelContent.classList.add('translate-x-full');
            setTimeout(() => {
                mobilePanel.classList.add('hidden');
            }, 300);
        };

        closePanelBtn?.addEventListener('click', closePanel);
        overlay?.addEventListener('click', closePanel);
    }

    // Leave room button
    document.getElementById('leaveRoomBtn')?.addEventListener('click', () => {
        if (confirm('Vuoi uscire dalla room?')) {
            if (window.chatManager) {
                window.chatManager.leaveRoom(roomConfig.roomId);
            }
            window.location.href = '/chat';
        }
    });

    // =========================================================================
    // ENTERPRISE V10.79: Online Users Modal (click on online count in header)
    // =========================================================================
    const onlineUsersModal = document.getElementById('onlineUsersModal');
    const closeOnlineUsersModal = document.getElementById('closeOnlineUsersModal');
    const roomOnlineCountBtn = document.getElementById('roomOnlineCountBtn');

    // Show modal on click
    roomOnlineCountBtn?.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();

        if (!onlineUsersModal) return;

        // Show modal
        onlineUsersModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        // Show skeleton, hide others
        const skeleton = onlineUsersModal.querySelector('.online-users-skeleton');
        const empty = onlineUsersModal.querySelector('.online-users-empty');
        const container = onlineUsersModal.querySelector('.online-users-container');
        const countEl = document.getElementById('onlineUsersCount');

        skeleton?.classList.remove('hidden');
        empty?.classList.add('hidden');
        container?.classList.add('hidden');
        if (countEl) countEl.textContent = 'Caricamento...';

        // Fetch online users
        try {
            const response = await fetch(`/api/chat/rooms/${encodeURIComponent(roomConfig.roomId)}/online`);
            const data = await response.json();

            skeleton?.classList.add('hidden');

            const users = data.data?.users || [];
            const count = data.data?.count || users.length;

            if (countEl) {
                countEl.textContent = `${count} utent${count === 1 ? 'e' : 'i'} conness${count === 1 ? 'o' : 'i'}`;
            }

            if (users.length === 0) {
                empty?.classList.remove('hidden');
                container?.classList.add('hidden');
            } else {
                empty?.classList.add('hidden');
                container?.classList.remove('hidden');

                // Render users (max 20)
                container.innerHTML = users.slice(0, 20).map(user => `
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

        } catch (error) {
            console.error('[OnlineUsers] Failed to fetch:', error);
            skeleton?.classList.add('hidden');
            empty?.classList.remove('hidden');
            if (countEl) countEl.textContent = 'Errore di caricamento';
        }
    });

    // Close modal handlers
    const hideModal = () => {
        onlineUsersModal?.classList.add('hidden');
        document.body.style.overflow = '';
    };

    closeOnlineUsersModal?.addEventListener('click', hideModal);

    onlineUsersModal?.addEventListener('click', (e) => {
        if (e.target === onlineUsersModal) {
            hideModal();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !onlineUsersModal?.classList.contains('hidden')) {
            hideModal();
        }
    });
});
</script>
