<?php
/**
 * Direct Message View - 1:1 Private Chat (CONTENT ONLY)
 * Enterprise Galaxy Chat System
 *
 * ARCHITETTURA ENTERPRISE:
 * - Content-only (wrapped by layouts/app-post-login.php)
 * - E2E Encryption (AES-256-GCM + ECDH)
 * - Real-time via Swoole WebSocket
 * - Read receipts + typing indicators
 *
 * @package Need2Talk
 * @author Claude Code (AI-Orchestrated Development)
 * @since 2025-12-02
 */

if (!defined('APP_ROOT')) {
    exit('Accesso negato');
}

// Conversation data from controller
$conversationUuid = htmlspecialchars($conversation['uuid'] ?? '', ENT_QUOTES, 'UTF-8');
$isE2EEncrypted = (bool) ($conversation['is_e2e_encrypted'] ?? true);

// Other user data
$otherUser = $conversation['other_user'] ?? [];
$otherUserUuid = htmlspecialchars($otherUser['uuid'] ?? '', ENT_QUOTES, 'UTF-8');
$otherUserName = htmlspecialchars($otherUser['nickname'] ?? $otherUser['name'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$otherUserAvatar = htmlspecialchars($otherUser['avatar_url'] ?? '/assets/img/default-avatar.png', ENT_QUOTES, 'UTF-8');
$otherUserStatus = htmlspecialchars($otherUser['status'] ?? 'offline', ENT_QUOTES, 'UTF-8');
$otherUserLastSeen = $otherUser['last_seen'] ?? null;

// Current user data
$userName = htmlspecialchars($user['nickname'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$userAvatar = htmlspecialchars($user['avatar_url'] ?? '/assets/img/default-avatar.png', ENT_QUOTES, 'UTF-8');
$userUuid = htmlspecialchars($user['uuid'] ?? '', ENT_QUOTES, 'UTF-8');

// Format last seen
$lastSeenText = 'Offline';
if ($otherUserStatus === 'online') {
    $lastSeenText = 'Online';
} elseif ($otherUserLastSeen) {
    $lastSeenTimestamp = strtotime($otherUserLastSeen);
    $diff = time() - $lastSeenTimestamp;
    if ($diff < 300) {
        $lastSeenText = 'Online';
    } elseif ($diff < 3600) {
        $lastSeenText = floor($diff / 60) . ' min fa';
    } elseif ($diff < 86400) {
        $lastSeenText = floor($diff / 3600) . ' ore fa';
    } else {
        $lastSeenText = date('d/m/Y', $lastSeenTimestamp);
    }
}
?>

<!-- DM View - Full Height -->
<main class="min-h-screen max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-16">

    <div class="flex flex-col bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50 h-[calc(100vh-5rem)] overflow-hidden">

        <!-- Conversation Header -->
        <header class="px-4 sm:px-6 py-4 border-b border-gray-700/50 flex items-center justify-between shrink-0">
            <div class="flex items-center">
                <!-- Back Button -->
                <a href="/chat" class="mr-3 p-2 hover:bg-gray-700/50 rounded-lg transition-colors" title="Torna ai messaggi">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>

                <!-- User Avatar with Status -->
                <div class="relative mr-3">
                    <img src="<?= $otherUserAvatar ?>"
                         alt="<?= $otherUserName ?>"
                         class="w-10 h-10 rounded-full border-2 border-gray-600 object-cover"
                         loading="lazy">
                    <span id="userStatusDot"
                          class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-gray-800 <?= $otherUserStatus === 'online' ? 'bg-green-500' : 'bg-gray-500' ?>">
                    </span>
                </div>

                <!-- User Info -->
                <div>
                    <a href="/u/<?= $otherUserUuid ?>" class="font-semibold text-white hover:text-purple-400 transition-colors">
                        <?= $otherUserName ?>
                    </a>
                    <p id="userStatusText" class="text-xs text-gray-400">
                        <?= $lastSeenText ?>
                    </p>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center space-x-2">
                <!-- Encryption Badge -->
                <?php if ($isE2EEncrypted): ?>
                <div class="hidden sm:flex items-center text-green-400 text-xs mr-2" title="Crittografia end-to-end attiva">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <span>E2E</span>
                </div>
                <?php endif; ?>

                <!-- View Profile -->
                <a href="/u/<?= $otherUserUuid ?>"
                   class="p-2 hover:bg-gray-700/50 rounded-lg transition-colors"
                   title="Vedi profilo">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </a>

                <!-- Options Menu -->
                <div class="relative">
                    <button id="dmOptionsBtn" class="p-2 hover:bg-gray-700/50 rounded-lg transition-colors" title="Opzioni">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                        </svg>
                    </button>

                    <!-- Dropdown Menu -->
                    <div id="dmOptionsMenu" class="hidden absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-20">
                        <a href="/u/<?= $otherUserUuid ?>" class="flex items-center px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            Vedi profilo
                        </a>
                        <?php if ($isE2EEncrypted): ?>
                        <button id="verifyEncryptionBtn" class="w-full flex items-center px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                            Verifica crittografia
                        </button>
                        <?php endif; ?>
                        <button id="archiveConversationBtn" class="w-full flex items-center px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path>
                            </svg>
                            Archivia conversazione
                        </button>
                        <hr class="my-1 border-gray-700">
                        <button id="blockUserBtn" class="w-full flex items-center px-4 py-2 text-sm text-red-400 hover:bg-red-500/10">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                            </svg>
                            Blocca utente
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- E2E Encryption Notice (only shown once) -->
        <div id="e2eNotice" class="hidden px-4 py-3 bg-green-500/10 border-b border-green-500/20">
            <div class="flex items-center text-green-400 text-sm">
                <svg class="w-5 h-5 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
                <div class="flex-1">
                    <strong>Crittografia end-to-end attiva</strong>
                    <p class="text-xs text-green-300/70 mt-0.5">
                        I messaggi sono crittografati e possono essere letti solo da te e <?= $otherUserName ?>.
                    </p>
                </div>
                <button id="dismissE2ENotice" class="p-1 hover:bg-green-500/20 rounded">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Messages Container -->
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

</main>

<!-- Encryption Verification Modal -->
<div id="encryptionVerifyModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-md w-full p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-white flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    Verifica crittografia
                </h2>
                <button id="closeEncryptionModal" class="p-2 hover:bg-gray-700 rounded-lg">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <p class="text-sm text-gray-400 mb-4">
                Confronta queste impronte digitali con <?= $otherUserName ?> per verificare la sicurezza della conversazione.
            </p>

            <div class="space-y-4">
                <div>
                    <label class="text-xs text-gray-500 uppercase tracking-wider">La tua chiave</label>
                    <div id="myKeyFingerprint" class="mt-1 font-mono text-sm text-purple-400 bg-gray-900 p-3 rounded-lg break-all">
                        Caricamento...
                    </div>
                </div>

                <div>
                    <label class="text-xs text-gray-500 uppercase tracking-wider">Chiave di <?= $otherUserName ?></label>
                    <div id="theirKeyFingerprint" class="mt-1 font-mono text-sm text-blue-400 bg-gray-900 p-3 rounded-lg break-all">
                        Caricamento...
                    </div>
                </div>
            </div>

            <p class="text-xs text-gray-500 mt-4">
                Se le impronte corrispondono quando le confrontate (ad esempio di persona o tramite un canale sicuro), la crittografia è verificata.
            </p>
        </div>
    </div>
</div>

<!-- Block User Confirmation Modal -->
<div id="blockUserModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-sm w-full p-6">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-red-500/20 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                    </svg>
                </div>
                <h2 class="text-lg font-bold text-white mb-2">Blocca <?= $otherUserName ?>?</h2>
                <p class="text-sm text-gray-400">
                    Non potrai più ricevere messaggi da questo utente. Puoi sbloccarlo dalle impostazioni.
                </p>
            </div>
            <div class="flex space-x-3">
                <button id="cancelBlockUser" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">
                    Annulla
                </button>
                <button id="confirmBlockUser" class="flex-1 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                    Blocca
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Report Modal Container -->
<div id="reportModalContainer"></div>

<!-- DM Initialization -->
<script>
document.addEventListener('DOMContentLoaded', async function() {
    console.log('[DM] === DM PAGE INITIALIZATION START ===');

    const dmConfig = {
        conversationUuid: '<?= $conversationUuid ?>',
        otherUserUuid: '<?= $otherUserUuid ?>',
        otherUserName: '<?= addslashes($otherUserName) ?>',
        otherUserAvatar: '<?= addslashes($otherUserAvatar) ?>',
        otherUserStatus: '<?= $otherUserStatus ?>',
        isE2EEncrypted: <?= $isE2EEncrypted ? 'true' : 'false' ?>,
        userUuid: '<?= $userUuid ?>',
        userName: '<?= addslashes($userName) ?>',
        userAvatar: '<?= addslashes($userAvatar) ?>',
    };

    // Store config globally
    window.dmConfig = dmConfig;
    window.APP_USER = {
        uuid: dmConfig.userUuid,
        name: dmConfig.userName,
        avatar: dmConfig.userAvatar
    };

    console.log('[DM] Config:', dmConfig);

    // Initialize components
    try {
        // 0. Initialize UserPresence with HTTP heartbeat (ENTERPRISE CRITICAL)
        // This ensures our presence is saved in Redis, making us visible as "online" to others
        if (typeof UserPresence !== 'undefined') {
            window.userPresence = new UserPresence({
                userUuid: dmConfig.userUuid,
                autoHeartbeat: true  // Sends heartbeat every 30s to /api/chat/presence/heartbeat
            });
            console.log('[DM] UserPresence initialized with auto-heartbeat');

            // Listen for presence updates from WebSocket to update other user's status
            window.userPresence.on('statusChange', (data) => {
                if (data.userUuid === dmConfig.otherUserUuid) {
                    handleOtherUserStatus(data.status);
                }
            });
        }

        // 1. Initialize MessageList
        if (typeof MessageList !== 'undefined') {
            window.messageList = new MessageList('#messagesContainer', {
                currentUserUuid: dmConfig.userUuid
            });
            console.log('[DM] MessageList initialized');
        }

        // 2. Initialize MessageInput
        // ENTERPRISE V3.1: Audio messages enabled for DM (30s max, CDN storage)
        if (typeof MessageInput !== 'undefined') {
            window.messageInput = new MessageInput('#messageInputContainer', {
                placeholder: 'Scrivi un messaggio...',
                enableAudio: true,    // ENTERPRISE V3.1: Audio DM enabled
                enableImages: false,
                enableEmoji: true,
                conversationId: dmConfig.conversationUuid,  // Required for DM audio upload
                roomType: 'dm'
            });
            window.messageInput.on({
                send: async (message) => {
                    // ENTERPRISE V3.1: Handle both text and audio messages
                    if (message.type === 'audio' && message.attachment) {
                        // Audio message - already uploaded via MessageInput
                        console.log('[DM] Audio message sent:', message.attachment);
                    } else {
                        // Text message
                        await sendDMMessage(message.content || '');
                    }
                },
                typingStart: () => sendTypingIndicator(true),
                typingStop: () => sendTypingIndicator(false),
                error: (err) => console.error('[DM] Input error:', err)
            });
            console.log('[DM] MessageInput initialized');
        }

        // 3. Initialize TypingIndicator
        if (typeof TypingIndicator !== 'undefined') {
            window.typingIndicator = new TypingIndicator('#typingIndicator');
            console.log('[DM] TypingIndicator initialized');
        }

        // 4. Load messages
        await loadDMMessages();

        // 5. Handle initial status (from PHP/Redis) and fetch fresh status
        handleOtherUserStatus(dmConfig.otherUserStatus);
        fetchOtherUserPresence();

        // 6. Hide loading spinner
        const loadingEl = document.querySelector('.messages-loading');
        if (loadingEl) loadingEl.classList.add('hidden');

        console.log('[DM] === DM PAGE INITIALIZATION COMPLETE ===');

    } catch (error) {
        console.error('[DM] Initialization failed:', error);
        showDMError('Errore di inizializzazione: ' + error.message);
    }

    // =========================================================================
    // DM FUNCTIONS
    // =========================================================================

    async function loadDMMessages() {
        try {
            console.log('[DM] Loading messages for conversation:', dmConfig.conversationUuid);

            const response = await fetch(`/api/chat/dm/${dmConfig.conversationUuid}/messages?limit=50`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load messages');
            }

            const messages = data.data?.messages || [];
            console.log('[DM] Loaded', messages.length, 'messages');

            // Messages come newest-first, reverse for display (oldest at top)
            const sortedMessages = messages.reverse();

            // Set messages in MessageList
            if (window.messageList) {
                window.messageList.setMessages(sortedMessages, dmConfig.conversationUuid, 'dm');
            }

            // ENTERPRISE V10.59: Use ReadReceiptManager for visibility-aware read receipts
            // Extended DM page = conversation is open (isWidgetOpen = true)
            if (window.readReceiptManager) {
                window.readReceiptManager.onConversationOpened(dmConfig.conversationUuid, true);
            } else if (document.hasFocus() && document.visibilityState === 'visible') {
                fetch(`/api/chat/dm/${dmConfig.conversationUuid}/read`, { method: 'POST' });
            }

        } catch (error) {
            console.error('[DM] Failed to load messages:', error);
            if (window.messageList) {
                window.messageList.showEmpty('Nessun messaggio ancora');
            }
        }
    }

    async function sendDMMessage(content) {
        if (!content || !content.trim()) return;

        try {
            // Check if other user is busy - show notification instead
            if (dmConfig.otherUserStatus === 'dnd') {
                // Still send but show notice
                Need2Talk.FlashMessages?.show(
                    `${dmConfig.otherUserName} è occupato. Riceverà una notifica.`,
                    'info',
                    4000
                );
                // Send "mi ha cercato" notification
                await sendMiHaCercatoNotification();
            }

            // For E2E encryption - simplified for now, just send plain
            // TODO: Implement proper E2E encryption
            const response = await fetch(`/api/chat/dm/${dmConfig.conversationUuid}/messages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    encrypted_content: btoa(content), // Base64 encode (placeholder)
                    content_iv: btoa('0000000000000000'),
                    content_tag: btoa('0000000000000000'),
                    message_type: 'text'
                })
            });

            const data = await response.json();

            if (data.success && data.data?.message) {
                // Add message to list
                const msg = data.data.message;
                msg.content = content; // We know the content
                msg.sender_uuid = dmConfig.userUuid;
                if (window.messageList) {
                    window.messageList.addMessage(msg);
                }
            } else {
                throw new Error(data.error || 'Failed to send');
            }

        } catch (error) {
            console.error('[DM] Send failed:', error);
            Need2Talk.FlashMessages?.show('Errore invio messaggio', 'error', 3000);
        }
    }

    async function sendTypingIndicator(isTyping) {
        try {
            await fetch(`/api/chat/dm/${dmConfig.conversationUuid}/typing`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ is_typing: isTyping })
            });
        } catch (e) {
            // Ignore typing errors
        }
    }

    async function sendMiHaCercatoNotification() {
        try {
            await fetch('/api/notifications/mi-ha-cercato', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    recipient_uuid: dmConfig.otherUserUuid,
                    sender_name: dmConfig.userName
                })
            });
        } catch (e) {
            console.warn('[DM] Failed to send mi-ha-cercato notification:', e);
        }
    }

    function handleOtherUserStatus(status) {
        const statusText = document.getElementById('userStatusText');
        const statusDot = document.getElementById('userStatusDot');

        if (status === 'dnd' || status === 'busy') {
            // Busy/Occupied
            if (statusText) statusText.textContent = 'Occupato';
            if (statusDot) {
                statusDot.classList.remove('bg-green-500', 'bg-gray-500');
                statusDot.classList.add('bg-yellow-500');
            }
            // Show notice
            showStatusNotice('busy');
        } else if (status === 'offline' || status === 'invisible') {
            // Offline
            if (statusText) statusText.textContent = 'Offline';
            if (statusDot) {
                statusDot.classList.remove('bg-green-500', 'bg-yellow-500');
                statusDot.classList.add('bg-gray-500');
            }
            // Clear any status notice
            clearStatusNotice();
        } else if (status === 'away') {
            // Away
            if (statusText) statusText.textContent = 'Assente';
            if (statusDot) {
                statusDot.classList.remove('bg-green-500', 'bg-gray-500');
                statusDot.classList.add('bg-yellow-500');
            }
        } else {
            // Online (default)
            if (statusText) statusText.textContent = 'Online';
            if (statusDot) {
                statusDot.classList.remove('bg-gray-500', 'bg-yellow-500');
                statusDot.classList.add('bg-green-500');
            }
            // Clear any status notice
            clearStatusNotice();
        }
    }

    /**
     * Fetch fresh presence status from API (ENTERPRISE: Real-time accuracy)
     * This supplements the initial PHP-rendered status with live data
     */
    async function fetchOtherUserPresence() {
        try {
            const response = await fetch(`/api/chat/presence/${dmConfig.otherUserUuid}`);
            const data = await response.json();

            if (data.success && data.data) {
                console.log('[DM] Fetched other user presence:', data.data);

                // Update UI with fresh status
                handleOtherUserStatus(data.data.status);

                // Update UserPresence tracking if available
                if (window.userPresence) {
                    window.userPresence.updateUser(dmConfig.otherUserUuid, {
                        status: data.data.status,
                        username: dmConfig.otherUserName,
                        avatar: dmConfig.otherUserAvatar
                    });
                }
            }
        } catch (error) {
            console.warn('[DM] Failed to fetch other user presence:', error);
            // Keep the PHP-rendered status as fallback
        }
    }

    /**
     * Clear status notice when user comes online
     */
    function clearStatusNotice() {
        const notice = document.getElementById('statusNotice');
        if (notice) {
            notice.remove();
        }
    }

    function showStatusNotice(type) {
        const container = document.getElementById('messagesContainer');
        if (!container) return;

        let notice = document.getElementById('statusNotice');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'statusNotice';
            notice.className = 'px-4 py-3 mb-2 rounded-lg text-sm';
            container.insertBefore(notice, container.firstChild);
        }

        if (type === 'busy') {
            notice.className = 'px-4 py-3 mb-2 rounded-lg text-sm bg-yellow-500/10 border border-yellow-500/20 text-yellow-300';
            notice.innerHTML = `
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <span><strong>${dmConfig.otherUserName}</strong> è occupato. Puoi scrivere, riceverà una notifica che l'hai cercato.</span>
                </div>
            `;
        }
    }

    function showDMError(message) {
        const container = document.getElementById('messagesContainer');
        if (container) {
            container.innerHTML = `
                <div class="flex flex-col items-center justify-center h-full text-center p-6">
                    <div class="w-16 h-16 bg-red-500/20 rounded-full flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <p class="text-red-400 font-medium">${message}</p>
                </div>
            `;
        }
    }

    // Options menu toggle
    const optionsBtn = document.getElementById('dmOptionsBtn');
    const optionsMenu = document.getElementById('dmOptionsMenu');

    if (optionsBtn && optionsMenu) {
        optionsBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            optionsMenu.classList.toggle('hidden');
        });

        document.addEventListener('click', () => {
            optionsMenu.classList.add('hidden');
        });
    }

    // Encryption verification modal
    const verifyBtn = document.getElementById('verifyEncryptionBtn');
    const encryptionModal = document.getElementById('encryptionVerifyModal');
    const closeEncryptionModal = document.getElementById('closeEncryptionModal');

    if (verifyBtn && encryptionModal) {
        verifyBtn.addEventListener('click', async () => {
            optionsMenu.classList.add('hidden');
            encryptionModal.classList.remove('hidden');

            // Load fingerprints
            if (window.chatManager?.encryptionService) {
                try {
                    const myFingerprint = await window.chatManager.encryptionService.getKeyFingerprint();
                    document.getElementById('myKeyFingerprint').textContent = myFingerprint;

                    // Fetch other user's fingerprint
                    const response = await fetch(`/api/chat/dm/${dmConfig.conversationUuid}/key`);
                    const data = await response.json();
                    if (data.success && data.data?.other_user_fingerprint) {
                        document.getElementById('theirKeyFingerprint').textContent = data.data.other_user_fingerprint;
                    }
                } catch (e) {
                    console.error('[DM] Failed to load fingerprints:', e);
                }
            }
        });

        closeEncryptionModal.addEventListener('click', () => {
            encryptionModal.classList.add('hidden');
        });
    }

    // Block user modal
    const blockBtn = document.getElementById('blockUserBtn');
    const blockModal = document.getElementById('blockUserModal');
    const cancelBlock = document.getElementById('cancelBlockUser');
    const confirmBlock = document.getElementById('confirmBlockUser');

    if (blockBtn && blockModal) {
        blockBtn.addEventListener('click', () => {
            optionsMenu.classList.add('hidden');
            blockModal.classList.remove('hidden');
        });

        cancelBlock?.addEventListener('click', () => {
            blockModal.classList.add('hidden');
        });

        confirmBlock?.addEventListener('click', async () => {
            try {
                const response = await fetch('/api/user/block', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CSRF_TOKEN || '',
                    },
                    body: JSON.stringify({ user_uuid: dmConfig.otherUserUuid }),
                });

                if (response.ok) {
                    window.location.href = '/chat';
                }
            } catch (e) {
                console.error('[DM] Failed to block user:', e);
            }
        });
    }

    // E2E notice dismissal
    const dismissE2E = document.getElementById('dismissE2ENotice');
    const e2eNotice = document.getElementById('e2eNotice');

    if (dismissE2E && e2eNotice && dmConfig.isE2EEncrypted) {
        const dismissed = localStorage.getItem('e2e_notice_dismissed_' + dmConfig.conversationUuid);
        if (!dismissed) {
            e2eNotice.classList.remove('hidden');
        }

        dismissE2E.addEventListener('click', () => {
            e2eNotice.classList.add('hidden');
            localStorage.setItem('e2e_notice_dismissed_' + dmConfig.conversationUuid, '1');
        });
    }
});
</script>
