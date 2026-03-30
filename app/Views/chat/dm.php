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

// ENTERPRISE V10.183: Hide FloatingRecorder on DM pages (has its own audio input)
$hideFloatingRecorder = true;

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
$otherUserHasE2eKey = (bool) ($otherUser['has_e2e_key'] ?? false);

// Current user data
$userName = htmlspecialchars($user['nickname'] ?? 'Utente', ENT_QUOTES, 'UTF-8');
$userAvatar = htmlspecialchars($user['avatar_url'] ?? '/assets/img/default-avatar.png', ENT_QUOTES, 'UTF-8');
$userUuid = htmlspecialchars($user['uuid'] ?? '', ENT_QUOTES, 'UTF-8');

// =========================================================================
// ENTERPRISE V10.64: Robust Last Seen Formatting
// =========================================================================
// DATA CONTRACT:
// - PresenceService returns last_seen as Unix timestamp (int) or null
// - Database last_login_at returns ISO 8601 datetime string
// - Controller may pass either type as fallback
//
// This logic handles both types with proper validation to prevent
// "01/01/1970" bug (Unix epoch displayed when timestamp is 0 or invalid)
// =========================================================================

$lastSeenText = 'Offline';

if ($otherUserStatus === 'online') {
    $lastSeenText = 'Online';
} elseif ($otherUserLastSeen !== null) {
    // Normalize to Unix timestamp (handles both int and string datetime)
    $lastSeenTimestamp = is_int($otherUserLastSeen) || is_numeric($otherUserLastSeen)
        ? (int) $otherUserLastSeen
        : strtotime($otherUserLastSeen);

    // Validate timestamp is reasonable (after year 2020, not in future)
    // Unix timestamp for 2020-01-01 = 1577836800
    $minValidTimestamp = 1577836800;
    $maxValidTimestamp = time() + 86400; // Allow 1 day future for timezone issues

    if ($lastSeenTimestamp > $minValidTimestamp && $lastSeenTimestamp < $maxValidTimestamp) {
        $diff = time() - $lastSeenTimestamp;

        if ($diff < 300) {
            // Within 5 minutes = effectively online
            $lastSeenText = 'Online';
        } elseif ($diff < 3600) {
            // Less than 1 hour
            $minutes = floor($diff / 60);
            $lastSeenText = $minutes . ' min fa';
        } elseif ($diff < 86400) {
            // Less than 24 hours
            $hours = floor($diff / 3600);
            $lastSeenText = $hours . ($hours === 1 ? ' ora fa' : ' ore fa');
        } elseif ($diff < 604800) {
            // Less than 7 days
            $days = floor($diff / 86400);
            $lastSeenText = $days . ($days === 1 ? ' giorno fa' : ' giorni fa');
        } else {
            // More than 7 days - show date
            $lastSeenText = date('d/m/Y', $lastSeenTimestamp);
        }
    }
    // If timestamp is invalid, keep default 'Offline'
}
?>

<!-- DM View - Full Height -->
<!-- ENTERPRISE v10.2: Mobile-safe viewport height using dvh (dynamic viewport height)
     - 100dvh accounts for mobile browser URL bar
     - Fallback to 100vh for older browsers
     - pb-safe accounts for iOS safe area (notch/home indicator) -->
<main class="min-h-screen max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-safe">

    <div id="dmChatContainer" class="flex flex-col bg-gray-800/50 backdrop-blur-sm rounded-xl border border-gray-700/50 h-[calc(100dvh-5rem)] sm:h-[calc(100vh-5rem)] overflow-hidden">

        <!-- Conversation Header -->
        <header class="px-4 sm:px-6 py-4 border-b border-gray-700/50 flex items-center justify-between shrink-0">
            <div class="flex items-center">
                <!-- Back Button -->
                <!-- ENTERPRISE V10.41: Opens minimized widget on /chat page (desktop) or navigates directly (mobile) -->
                <button id="dmBackButton" class="mr-3 p-2 hover:bg-gray-700/50 rounded-lg transition-colors" title="Torna ai messaggi">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>

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

        <!-- ENTERPRISE V10.170: Warning when other user hasn't set up E2E encryption yet -->
        <?php if (!$otherUserHasE2eKey): ?>
        <div id="e2eWarning" class="px-4 py-3 bg-yellow-500/10 border-b border-yellow-500/20">
            <div class="flex items-start text-yellow-400 text-sm">
                <svg class="w-5 h-5 mr-2 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div class="flex-1">
                    <strong>Crittografia non ancora attiva</strong>
                    <p class="text-xs text-yellow-300/70 mt-0.5">
                        <?= $otherUserName ?> non ha ancora attivato la crittografia. Chiedigli di inviare un messaggio per abilitarla automaticamente.
                        Gli audio non potranno essere riprodotti fino all'attivazione.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Messages Container -->
        <!-- ENTERPRISE V10.39: min-h-0 required for flexbox to allow shrinking below content size -->
        <div id="messagesContainer" class="flex-1 overflow-hidden relative min-h-0" role="log" aria-live="polite">
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

    // =========================================================================
    // ENTERPRISE V10.23: Notify ChatWidgetManager that DM page is open
    // This closes any widget for the same conversation to prevent duplicates
    // =========================================================================
    window.dispatchEvent(new CustomEvent('n2t:dmPageOpened', {
        detail: { conversationUuid: '<?= $conversationUuid ?>' }
    }));
    console.log('[DM] Dispatched n2t:dmPageOpened for:', '<?= $conversationUuid ?>');

    const dmConfig = {
        conversationUuid: '<?= $conversationUuid ?>',
        otherUserUuid: '<?= $otherUserUuid ?>',
        otherUserName: '<?= addslashes($otherUserName) ?>',
        otherUserAvatar: '<?= addslashes($otherUserAvatar) ?>',
        otherUserStatus: '<?= $otherUserStatus ?>',
        isE2EEncrypted: <?= $isE2EEncrypted ? 'true' : 'false' ?>,
        otherUserHasE2eKey: <?= $otherUserHasE2eKey ? 'true' : 'false' ?>, // V10.170
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

    // =========================================================================
    // ENTERPRISE v9.9: Message Expiry Watcher (declared early for hoisting)
    // =========================================================================
    let expiryWatcherInterval = null;

    // =========================================================================
    // ENTERPRISE V10.63: WebSocket handler registration flag (declared early)
    // CRITICAL: Must be declared before setupWebSocketListeners() is called
    // to avoid JavaScript temporal dead zone error
    // =========================================================================
    let dmWsHandlersRegistered = false;

    function startExpiryWatcher() {
        if (expiryWatcherInterval) {
            clearInterval(expiryWatcherInterval);
        }
        expiryWatcherInterval = setInterval(() => {
            removeExpiredMessages();
        }, 30000);
        console.log('[DM] Expiry watcher started (30s interval)');
    }

    function removeExpiredMessages() {
        if (!window.messageList) return;
        const messages = window.messageList.messages;
        if (!messages || messages.length === 0) return;

        const now = Date.now();
        let expiredCount = 0;

        const validMessages = messages.filter(msg => {
            if (!msg.expires_at) return true;
            const expiresAtMs = new Date(msg.expires_at).getTime();
            if (expiresAtMs <= now) {
                expiredCount++;
                console.log('[DM] Message expired (live removal):', msg.uuid);
                return false;
            }
            return true;
        });

        if (expiredCount > 0) {
            console.log('[DM] Removing', expiredCount, 'expired messages');
            window.messageList.setMessages(validMessages, dmConfig.conversationUuid, 'dm');

            if (window.Need2Talk?.FlashMessages) {
                const msgText = expiredCount === 1
                    ? 'Un messaggio è scaduto e non è più visibile.'
                    : `${expiredCount} messaggi sono scaduti e non sono più visibili.`;
                window.Need2Talk.FlashMessages.show(msgText, 'info', 4000);
            }

            if (validMessages.length === 0 && window.messageList.showEmpty) {
                window.messageList.showEmpty(
                    'Tutti i messaggi di questa conversazione sono scaduti. ' +
                    'Per privacy, i messaggi DM vengono cancellati dopo 1 ora.'
                );
            }
        }
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', () => {
        if (expiryWatcherInterval) {
            clearInterval(expiryWatcherInterval);
        }

        // ENTERPRISE V10.23: Notify ChatWidgetManager that DM page is closing
        window.dispatchEvent(new CustomEvent('n2t:dmPageClosed', {
            detail: { conversationUuid: dmConfig.conversationUuid }
        }));
    });

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

        // 1. Initialize MessageList with infinite scroll callback
        if (typeof MessageList !== 'undefined') {
            window.messageList = new MessageList('#messagesContainer', {
                currentUserUuid: dmConfig.userUuid,
                chatType: 'dm',
                // ENTERPRISE V10.40: Infinite scroll - load older messages when scrolling up
                onLoadMore: async (beforeMessageUuid) => {
                    return await loadOlderMessages(beforeMessageUuid);
                }
            });
            console.log('[DM] MessageList initialized with infinite scroll');
        }

        // 2. Initialize MessageInput
        // ENTERPRISE V3.1: Audio messages enabled for DM (30s max, CDN storage)
        if (typeof MessageInput !== 'undefined') {
            window.messageInput = new MessageInput('#messageInputContainer', {
                placeholder: 'Scrivi un messaggio...',
                enableAudio: true,    // ENTERPRISE V3.1: Audio DM enabled
                enableImages: false,
                enableEmoji: true,
                conversationId: '<?= $conversationUuid ?>',  // Required for DM audio upload
                roomType: 'dm'
            });
            window.messageInput.on({
                send: async (message) => {
                    // ENTERPRISE V3.1: Handle both text and audio messages
                    if (message.type === 'audio' && message.attachment) {
                        // Audio message - already uploaded via MessageInput
                        // The attachment contains: { message, audio_url, duration }
                        console.log('[DM] Audio message sent:', message.attachment);

                        // ENTERPRISE V11.8 FIX: Create FULL optimistic audio message
                        // Server returns 202 Accepted with job_id (async), NOT a message object
                        // We create the optimistic message here for immediate UI feedback
                        if (window.messageList) {
                            const optimisticAudioMsg = {
                                id: `temp_audio_${Date.now()}`,
                                uuid: `temp_audio_${Date.now()}`,
                                message_type: 'audio',
                                deleted: false, // CRITICAL: Prevent "rimosso dal moderatore"
                                audio_url: message.attachment?.audio_url || null,
                                duration_seconds: message.attachment?.duration || 0,
                                sender_uuid: dmConfig.userUuid,
                                sender_nickname: '<?= htmlspecialchars($currentUser['nickname'] ?? 'Tu') ?>',
                                sender_avatar: '<?= htmlspecialchars($currentUser['avatar_url'] ?? '') ?>',
                                created_at: new Date().toISOString(),
                                status: 'sending',
                                // E2E encryption metadata (if available)
                                audio_is_encrypted: message.attachment?.audio_is_encrypted || false,
                                audio_encryption_iv: message.attachment?.audio_encryption_iv || null,
                                audio_encryption_tag: message.attachment?.audio_encryption_tag || null,
                            };
                            window.messageList.addMessage(optimisticAudioMsg);
                            console.log('[DM] V11.8: Optimistic audio message added to UI:', optimisticAudioMsg);
                        }
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

        // 4. ENTERPRISE CRITICAL: Subscribe to WebSocket events for real-time DM delivery
        // Without this, messages from other users won't appear!
        setupWebSocketListeners();

        // 5. Load messages
        await loadDMMessages();

        // 6. Handle initial status (from PHP/Redis) and fetch fresh status
        handleOtherUserStatus(dmConfig.otherUserStatus);
        fetchOtherUserPresence();

        // 7. Hide loading spinner
        const loadingEl = document.querySelector('.messages-loading');
        if (loadingEl) loadingEl.classList.add('hidden');

        // 8. ENTERPRISE v9.9: Start expiry watcher (removes expired messages live)
        startExpiryWatcher();

        // 9. ENTERPRISE v10.3: iOS Keyboard Fix
        // iOS Safari doesn't resize viewport when keyboard opens, causing input to be hidden
        setupIOSKeyboardFix();

        console.log('[DM] === DM PAGE INITIALIZATION COMPLETE ===');

    } catch (error) {
        console.error('[DM] Initialization failed:', error);
        showDMError('Errore di inizializzazione: ' + error.message);
    }

    // =========================================================================
    // WEBSOCKET EVENT LISTENERS (ENTERPRISE REAL-TIME)
    // =========================================================================

    /**
     * Setup WebSocket listeners for real-time DM delivery
     * ENTERPRISE CRITICAL: This enables:
     * - Instant message delivery (no refresh needed)
     * - Typing indicators
     * - Read receipts
     * - Presence updates
     *
     * ENTERPRISE FIX v9.8: Use window.WebSocketManager (the actual global instance)
     * WebSocket connection is async, so we need to retry until it's available
     */
    function setupWebSocketListeners() {
        // Try to register immediately
        tryRegisterDMHandlers();

        // ENTERPRISE V10.63: Also listen for WebSocket connection event
        // This handles the case where WebSocket connects AFTER retry loop completes
        window.addEventListener('n2t:wsConnected', () => {
            console.log('[DM] n2t:wsConnected event - attempting to register handlers');
            tryRegisterDMHandlers();
        });
    }

    function tryRegisterDMHandlers() {
        // Prevent duplicate registration
        if (dmWsHandlersRegistered) {
            console.log('[DM] WebSocket handlers already registered, skipping');
            return;
        }

        let ws = window.WebSocketManager;

        if (!ws || !ws.isConnected) {
            // ENTERPRISE FIX: WebSocket may not be ready yet, retry with exponential backoff
            let retryCount = 0;
            const maxRetries = 10;
            const retryInterval = setInterval(() => {
                ws = window.WebSocketManager;
                retryCount++;

                if (ws && ws.isConnected) {
                    clearInterval(retryInterval);
                    console.log('[DM] WebSocket manager found after', retryCount, 'retries');
                    registerDMWebSocketHandlers(ws);
                } else if (retryCount >= maxRetries) {
                    clearInterval(retryInterval);
                    console.warn('[DM] WebSocket manager not found after', maxRetries, 'retries - using polling fallback');
                    setInterval(pollForNewMessages, 10000);
                }
            }, 500); // Check every 500ms

            return;
        }

        registerDMWebSocketHandlers(ws);
    }

    /**
     * Register WebSocket event handlers for DM
     * Separated from setupWebSocketListeners for retry logic
     */
    function registerDMWebSocketHandlers(ws) {
        // ENTERPRISE V10.63: Mark as registered to prevent duplicates
        dmWsHandlersRegistered = true;
        console.log('[DM] Setting up WebSocket listeners for real-time DM');

        // Listen for incoming DM messages
        ws.on('dm_received', async (data) => {
            console.log('[DM] WebSocket: dm_received', data);

            const payload = data.payload || data;
            const convUuid = payload.conversation_uuid;
            const message = payload.message;

            // Only handle messages for THIS conversation
            if (convUuid !== dmConfig.conversationUuid) {
                console.log('[DM] Message for different conversation, ignoring');
                return;
            }

            // Don't show our own messages (already added on send)
            if (message.sender_uuid === dmConfig.userUuid) {
                console.log('[DM] Own message, ignoring');
                return;
            }

            // ENTERPRISE GALAXY v9.7: Decode encrypted content
            // ENTERPRISE V11.6: TRUE E2E decryption using ChatEncryptionService
            let content = message.content;
            if (!content && message.message_type === 'text') {
                try {
                    const encrypted = message.encrypted;
                    if (encrypted?.ciphertext && encrypted?.iv && encrypted?.tag) {
                        // Real E2E decryption with AES-256-GCM
                        const chatEncryption = await window.chatEncryptionReady;
                        if (chatEncryption && chatEncryption.isInitialized) {
                            content = await chatEncryption.decryptMessage(
                                encrypted.ciphertext,
                                encrypted.iv,
                                encrypted.tag,
                                dmConfig.conversationUuid
                            );
                            console.log('[DM] WebSocket message decrypted with E2E');
                        } else {
                            content = '[Crittografia non disponibile]';
                        }
                    } else if (message.encrypted_content) {
                        // Fallback for legacy non-E2E messages
                        content = Need2Talk.utils.base64ToUtf8(message.encrypted_content);
                    } else {
                        content = '[Messaggio crittografato]';
                    }
                } catch (e) {
                    console.error('[DM] WebSocket: Decryption failed:', e.message);
                    content = '[Messaggio non decifrabile]';
                }
            }
            message.content = content;
            message.decrypted = true;

            // ENTERPRISE v10.0: If sender is the other user in this conversation,
            // update their status to online (they're actively chatting)
            if (message.sender_uuid === dmConfig.otherUserUuid) {
                handleOtherUserStatus('online');
            }

            // Add message to the list
            if (window.messageList) {
                window.messageList.addMessage(message);

                // Scroll to bottom
                const container = document.getElementById('messagesContainer');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            }

            // Play notification sound (if available)
            playMessageSound();

            // ENTERPRISE V10.42: Use ReadReceiptManager for accurate visibility-based read receipts
            // Only marks as read if user is ACTUALLY viewing (page visible + focused)
            if (window.readReceiptManager) {
                window.readReceiptManager.onMessageReceived(
                    dmConfig.conversationUuid,
                    message.uuid,
                    true  // DM page is always "open" when on this page
                );
            } else if (document.hasFocus() && document.visibilityState === 'visible') {
                // Fallback without ReadReceiptManager
                fetch(`/api/chat/dm/${dmConfig.conversationUuid}/read`, { method: 'POST' });
            }
        });

        // ENTERPRISE V11.8: Listen for audio upload completion (SENDER only)
        // When worker finishes processing, update the optimistic message with real audio_url
        ws.on('dm_audio_uploaded', (data) => {
            console.log('[DM] WebSocket: dm_audio_uploaded', data);

            const payload = data.payload || data;
            const convUuid = payload.conversation_uuid;
            const message = payload.message;
            const status = payload.status;

            // Only handle for THIS conversation
            if (convUuid !== dmConfig.conversationUuid) {
                console.log('[DM] Audio upload for different conversation, ignoring');
                return;
            }

            if (status !== 'completed' || !message) {
                console.warn('[DM] Audio upload failed or no message:', status);
                return;
            }

            // Update optimistic message with real data
            if (window.messageList) {
                // ENTERPRISE V11.8: Find temp message and UPDATE in-place (no flicker)
                const tempMessages = window.messageList.messages.filter(m =>
                    m.id?.toString().startsWith('temp_audio_') && m.sender_uuid === dmConfig.userUuid
                );

                console.log('[DM] V11.8: Found temp audio messages to update:', tempMessages.length);

                if (tempMessages.length > 0) {
                    // Update the temp message with real data (single render, no flicker)
                    const updatedMsg = {
                        ...message,
                        id: message.id || message.uuid, // Ensure id is set
                        status: 'sent' // Mark as sent, not sending anymore
                    };
                    console.log('[DM] V11.8: Updating temp with:', {
                        tempId: tempMessages[0].id,
                        newId: updatedMsg.id,
                        audio_url: updatedMsg.audio_url,
                        status: updatedMsg.status,
                        audio_is_encrypted: updatedMsg.audio_is_encrypted
                    });
                    window.messageList.updateMessage(tempMessages[0].id, updatedMsg);
                    console.log('[DM] V11.8: Updated temp audio with real message:', message.uuid);
                } else {
                    // No temp message found, just add the real one
                    window.messageList.addMessage(message);
                    console.log('[DM] V11.8: Added real audio message (no temp found):', message.uuid);
                }
            }
        });

        // Listen for typing indicators
        ws.on('typing_indicator', (data) => {
            const payload = data.payload || data;

            // Only show typing for this conversation's other user
            if (payload.user_uuid === dmConfig.otherUserUuid &&
                payload.target_id === dmConfig.conversationUuid) {

                if (window.typingIndicator) {
                    if (payload.is_typing) {
                        window.typingIndicator.show(dmConfig.otherUserName);
                    } else {
                        window.typingIndicator.hide();
                    }
                }
            }
        });

        // ENTERPRISE V10.40: Listen for read receipts and dispatch to MessageList
        // DM page doesn't use ChatManager, so we dispatch chat:read_receipt directly
        ws.on('dm_read_receipt', (data) => {
            const payload = data.payload || data;

            if (payload.conversation_uuid === dmConfig.conversationUuid) {
                console.log('[DM] Read receipt received:', payload);
                // Dispatch to MessageList via chat:read_receipt event
                document.dispatchEvent(new CustomEvent('chat:read_receipt', {
                    detail: {
                        conversation_uuid: payload.conversation_uuid,
                        reader_uuid: payload.reader_uuid,
                        read_at: payload.read_at
                    }
                }));
            }
        });

        // Listen for presence changes
        ws.on('friend_presence_changed', (data) => {
            const payload = data.payload || data;

            if (payload.user_uuid === dmConfig.otherUserUuid) {
                console.log('[DM] Other user presence changed:', payload.status);
                handleOtherUserStatus(payload.status);
            }
        });

        // Listen for DM notifications (when recipient is not viewing chat)
        ws.on('dm_notification', (data) => {
            const payload = data.payload || data;

            // This is for the notification badge - update it
            if (window.Need2Talk?.updateNotificationBadge) {
                window.Need2Talk.updateNotificationBadge('dm', 1);
            }
        });

        // ENTERPRISE V11.8: Refresh presence on WebSocket reconnection (mobile PWA fix)
        // When WebSocket reconnects (after app goes to background), fetch fresh presence
        ws.on('connected', async (data) => {
            if (data?.isReconnect) {
                console.log('[DM] WebSocket reconnected, refreshing presence status...');

                // Fetch fresh presence status from API
                try {
                    const response = await fetch(`/api/chat/presence/${dmConfig.otherUserUuid}`);
                    const result = await response.json();

                    if (result.success && result.data?.status) {
                        console.log('[DM] Fresh presence:', result.data.status);
                        handleOtherUserStatus(result.data.status);
                    }
                } catch (e) {
                    console.warn('[DM] Failed to refresh presence:', e);
                }
            }
        });

        console.log('[DM] WebSocket listeners registered');
    }

    /**
     * Fallback polling for environments without WebSocket
     */
    let lastMessageTimestamp = 0;
    async function pollForNewMessages() {
        try {
            const response = await fetch(
                `/api/chat/dm/${dmConfig.conversationUuid}/messages?limit=10&since=${lastMessageTimestamp}`
            );
            const data = await response.json();

            if (data.success && data.data?.messages?.length > 0) {
                const messages = data.data.messages;

                for (const msg of messages) {
                    // Update timestamp
                    const msgTime = new Date(msg.created_at).getTime();
                    if (msgTime > lastMessageTimestamp) {
                        lastMessageTimestamp = msgTime;
                    }

                    // Skip own messages
                    if (msg.sender_uuid === dmConfig.userUuid) continue;

                    // Add to list
                    if (window.messageList) {
                        window.messageList.addMessage(msg);
                    }
                }
            }
        } catch (e) {
            console.warn('[DM] Poll failed:', e);
        }
    }

    /**
     * ENTERPRISE v10.2: Mobile-safe notification sound
     * Pre-loads audio on first user interaction to bypass autoplay restrictions
     */
    let messageAudio = null;
    let audioUnlocked = false;

    // Pre-load and unlock audio on first user interaction
    function unlockAudio() {
        if (audioUnlocked) return;

        try {
            messageAudio = new Audio('/assets/sounds/message.mp3');
            messageAudio.volume = 0.3;
            messageAudio.load();

            // Play silent to unlock (required on iOS/Android)
            const originalVolume = messageAudio.volume;
            messageAudio.volume = 0;
            messageAudio.play().then(() => {
                messageAudio.pause();
                messageAudio.currentTime = 0;
                messageAudio.volume = originalVolume;
                audioUnlocked = true;
                console.log('[DM] Audio unlocked for notifications');
            }).catch(() => {
                // Still set unlocked so we don't spam attempts
                audioUnlocked = true;
            });
        } catch (e) {
            audioUnlocked = true; // Prevent retries
        }
    }

    // Unlock audio on first touch/click
    document.addEventListener('touchstart', unlockAudio, { once: true, passive: true });
    document.addEventListener('click', unlockAudio, { once: true });

    function playMessageSound() {
        if (!messageAudio) {
            // Fallback: create on demand (won't work without prior interaction)
            messageAudio = new Audio('/assets/sounds/message.mp3');
            messageAudio.volume = 0.3;
        }

        try {
            messageAudio.currentTime = 0;
            messageAudio.play().catch(() => {}); // Ignore if still blocked
        } catch (e) {
            // Ignore sound errors
        }
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
            console.log('[DM] Loaded', messages.length, 'messages (raw)');

            // DEBUG: Log first message structure to see what API returns
            if (messages.length > 0) {
                console.log('[DM] RAW API RESPONSE - First message:', JSON.stringify(messages[0], null, 2));
            }

            // ENTERPRISE GALAXY v9.9: Filter expired messages FIRST (privacy protection)
            // Even if backend filters, messages could expire while user is viewing the chat
            // This ensures we NEVER display expired messages
            const now = Date.now();
            const validMessages = messages.filter(msg => {
                if (!msg.expires_at) return true; // No expiration = keep
                const expiresAtMs = new Date(msg.expires_at).getTime();
                const isValid = expiresAtMs > now;
                if (!isValid) {
                    console.log('[DM] Message expired, filtering out:', msg.uuid);
                }
                return isValid;
            });

            console.log('[DM] Valid messages after expiry filter:', validMessages.length, '/', messages.length);

            // ENTERPRISE V11.6: TRUE E2E decryption using ChatEncryptionService
            // Get encryption service once for all messages
            let chatEncryption = null;
            try {
                chatEncryption = await window.chatEncryptionReady;
            } catch (e) {
                console.warn('[DM] Encryption service not available');
            }

            // Decrypt all messages with Promise.all for async decryption
            const decryptedMessages = await Promise.all(validMessages.map(async msg => {
                // Skip non-text messages (audio has its own decryption)
                if (msg.message_type !== 'text') {
                    return msg;
                }

                // If message has encrypted data with IV and TAG, use real E2E decryption
                const encrypted = msg.encrypted;
                if (encrypted?.ciphertext && encrypted?.iv && encrypted?.tag) {
                    try {
                        if (chatEncryption && chatEncryption.isInitialized) {
                            const decoded = await chatEncryption.decryptMessage(
                                encrypted.ciphertext,
                                encrypted.iv,
                                encrypted.tag,
                                dmConfig.conversationUuid
                            );
                            msg.content = decoded;
                            msg.decrypted = true;
                            console.log('[DM] E2E decrypted message:', msg.uuid);
                        } else {
                            msg.content = '[Crittografia non disponibile]';
                        }
                    } catch (e) {
                        console.error('[DM] E2E decryption failed:', msg.uuid, e.message);
                        msg.content = '[Messaggio non decifrabile]';
                    }
                } else if (encrypted?.ciphertext) {
                    // Legacy fallback: base64 only (no IV/TAG means not real E2E)
                    try {
                        const decoded = Need2Talk.utils.base64ToUtf8(encrypted.ciphertext);
                        msg.content = decoded;
                        msg.decrypted = true;
                    } catch (e) {
                        msg.content = '[Messaggio non decifrabile]';
                    }
                } else if (!msg.content && msg.is_encrypted) {
                    msg.content = '[Messaggio crittografato]';
                }
                return msg;
            }));

            console.log('[DM] Decrypted', decryptedMessages.length, 'messages');

            // ENTERPRISE GALAXY v9.5: Check if user came from notification with ?from=notif param
            // If no messages and came from notification, messages may have expired (1h TTL)
            if (decryptedMessages.length === 0) {
                const urlParams = new URLSearchParams(window.location.search);
                const fromNotification = urlParams.get('from') === 'notif';

                if (window.messageList) {
                    if (fromNotification) {
                        // User clicked on notification but messages expired
                        window.messageList.showEmpty(
                            'I messaggi di questa conversazione sono scaduti. ' +
                            'Per privacy, i messaggi DM vengono cancellati dopo 1 ora.',
                            dmConfig.conversationUuid,
                            'dm'
                        );
                    } else {
                        // ENTERPRISE V11.8: Pass chatId and chatType for empty chats
                        // This ensures first message renders correctly
                        window.messageList.showEmpty(
                            'Inizia la conversazione con un messaggio!',
                            dmConfig.conversationUuid,
                            'dm'
                        );
                    }
                }
                return;
            }

            // Messages come newest-first, reverse for display (oldest at top)
            const sortedMessages = decryptedMessages.reverse();

            // Set messages in MessageList (with decrypted content)
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
                window.messageList.showEmpty('Errore nel caricamento dei messaggi');
            }
        }
    }

    /**
     * ENTERPRISE V10.40: Load older messages for infinite scroll
     * Called when user scrolls to the top of the message list
     *
     * @param {string} beforeMessageUuid - UUID of the oldest message currently displayed
     * @returns {Object} { messages: Array, hasMore: boolean }
     */
    async function loadOlderMessages(beforeMessageUuid) {
        try {
            console.log('[DM] Loading older messages before:', beforeMessageUuid);

            const url = `/api/chat/dm/${dmConfig.conversationUuid}/messages?limit=30&before=${encodeURIComponent(beforeMessageUuid)}`;
            const response = await fetch(url);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load older messages');
            }

            const messages = data.data?.messages || [];
            const hasMore = data.data?.has_more ?? messages.length === 30;

            console.log('[DM] Loaded', messages.length, 'older messages, hasMore:', hasMore);

            if (messages.length === 0) {
                return { messages: [], hasMore: false };
            }

            // Filter expired messages
            const now = Date.now();
            const validMessages = messages.filter(msg => {
                if (!msg.expires_at) return true;
                const expiresAtMs = new Date(msg.expires_at).getTime();
                return expiresAtMs > now;
            });

            // ENTERPRISE V11.6: TRUE E2E decryption (same logic as loadDMMessages)
            let chatEncryption = null;
            try {
                chatEncryption = await window.chatEncryptionReady;
            } catch (e) {
                console.warn('[DM] Encryption service not available for older messages');
            }

            const decryptedMessages = await Promise.all(validMessages.map(async msg => {
                if (msg.message_type !== 'text') return msg;

                const encrypted = msg.encrypted;
                if (encrypted?.ciphertext && encrypted?.iv && encrypted?.tag) {
                    try {
                        if (chatEncryption && chatEncryption.isInitialized) {
                            msg.content = await chatEncryption.decryptMessage(
                                encrypted.ciphertext,
                                encrypted.iv,
                                encrypted.tag,
                                dmConfig.conversationUuid
                            );
                            msg.decrypted = true;
                        } else {
                            msg.content = '[Crittografia non disponibile]';
                        }
                    } catch (e) {
                        console.warn('[DM] E2E decryption failed for older message:', msg.uuid, e.message);
                        msg.content = '[Messaggio non decifrabile]';
                    }
                } else if (encrypted?.ciphertext) {
                    // Legacy fallback
                    try {
                        msg.content = Need2Talk.utils.base64ToUtf8(encrypted.ciphertext);
                        msg.decrypted = true;
                    } catch (e) {
                        msg.content = '[Messaggio non decifrabile]';
                    }
                } else if (!msg.content && msg.is_encrypted) {
                    msg.content = '[Messaggio crittografato]';
                }
                return msg;
            }));

            // Messages come newest-first from API, reverse for display (oldest at top)
            const sortedMessages = decryptedMessages.reverse();

            console.log('[DM] Returning', sortedMessages.length, 'decrypted older messages');

            return {
                messages: sortedMessages,
                hasMore: hasMore
            };

        } catch (error) {
            console.error('[DM] Failed to load older messages:', error);
            return { messages: [], hasMore: false };
        }
    }

    async function sendDMMessage(content) {
        if (!content || !content.trim()) return;

        // ENTERPRISE V10.7: No client-side DND blocking
        // Messages are sent to DND users, server handles notification
        // If sender is DND, server auto-switches to online

        // ENTERPRISE V10.20 (2025-12-04): Optimistic UI - show message immediately
        // This fixes the "double send" bug where first message in empty chat wasn't visible
        // until the second message was sent (HTTP response was required before display)
        const tempId = `temp_${Date.now()}`;
        const tempMessage = {
            id: tempId,
            uuid: tempId,
            content: content,
            sender_uuid: dmConfig.userUuid,
            sender_nickname: dmConfig.userName,
            sender_avatar: dmConfig.userAvatar,
            created_at: new Date().toISOString(),
            status: 'sending',
            decrypted: true
        };

        // Add optimistic message immediately (before HTTP call)
        if (window.messageList) {
            window.messageList.addMessage(tempMessage);
        }

        try {
            // ENTERPRISE V11.6: TRUE E2E encryption using ChatEncryptionService
            let encryptedContent, contentIv, contentTag;

            try {
                // Wait for encryption service to be ready (awaitable promise)
                const chatEncryption = await window.chatEncryptionReady;
                // isInitialized is a GETTER, not a method!
                if (chatEncryption && chatEncryption.isInitialized) {
                    // Encrypt message with AES-256-GCM via ECDH-derived key
                    const encrypted = await chatEncryption.encryptMessage(content, dmConfig.conversationUuid);
                    encryptedContent = encrypted.ciphertext;
                    contentIv = encrypted.iv;
                    contentTag = encrypted.tag;
                    console.log('[DM] Message encrypted with E2E');
                } else {
                    throw new Error('Encryption service not initialized');
                }
            } catch (encryptError) {
                // Encryption failed - fallback to base64 (NOT secure, but allows messaging)
                console.error('[DM] E2E encryption failed:', encryptError.message);
                encryptedContent = Need2Talk.utils.utf8ToBase64(content);
                contentIv = btoa('0000000000000000');
                contentTag = btoa('0000000000000000');
            }

            const response = await fetch(`/api/chat/dm/${dmConfig.conversationUuid}/messages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    encrypted_content: encryptedContent,
                    content_iv: contentIv,
                    content_tag: contentTag,
                    message_type: 'text'
                })
            });

            const data = await response.json();

            if (data.success && data.data?.message) {
                // ENTERPRISE V10.20: Update optimistic message with server data
                // Find and update the temp message with real server ID
                const msg = data.data.message;
                msg.content = content; // We know the content
                msg.sender_uuid = dmConfig.userUuid;
                msg.status = 'sent';

                // Replace temp message with server-confirmed message
                if (window.messageList) {
                    window.messageList.updateMessage(tempId, msg);
                }

                // ENTERPRISE V10.7: Update UI if sender's status changed
                // This happens when sender was DND and auto-switched to online
                const newStatus = data.data.sender_status;
                if (newStatus) {
                    // Dispatch event for any listeners (chat/index.php presence indicator)
                    window.dispatchEvent(new CustomEvent('n2t:ownStatusChanged', {
                        detail: { status: newStatus, reason: 'message_sent' }
                    }));
                    console.log('[DM] Sender status after send:', newStatus);
                }
            } else {
                throw new Error(data.error || 'Failed to send');
            }

        } catch (error) {
            console.error('[DM] Send failed:', error);

            // ENTERPRISE V10.20: Mark optimistic message as failed (don't remove, show error state)
            if (window.messageList) {
                window.messageList.updateMessage(tempId, { status: 'failed' });
            }

            Need2Talk.FlashMessages?.show('Errore invio messaggio', 'error', 3000);
        }
    }

    // =========================================================================
    // ENTERPRISE DND BLOCKING HELPERS
    // =========================================================================

    function getDndCooldownKey() {
        return `dnd_cooldown_${dmConfig.conversationUuid}`;
    }

    function isDndBlocked() {
        const expiry = localStorage.getItem(getDndCooldownKey());
        if (!expiry) return false;
        return Date.now() < parseInt(expiry, 10);
    }

    function getDndCooldownRemaining() {
        const expiry = localStorage.getItem(getDndCooldownKey());
        if (!expiry) return 0;
        const remaining = parseInt(expiry, 10) - Date.now();
        return remaining > 0 ? Math.ceil(remaining / 1000) : 0;
    }

    function setDndCooldown() {
        const DND_COOLDOWN_MS = 30 * 60 * 1000; // 30 minutes
        const expiry = Date.now() + DND_COOLDOWN_MS;
        localStorage.setItem(getDndCooldownKey(), expiry.toString());
    }

    function clearDndCooldown() {
        localStorage.removeItem(getDndCooldownKey());
    }

    function showSystemMessage(text) {
        if (!window.messageList) return;

        // Create a system message element
        const container = document.getElementById('messagesContainer');
        const listEl = container?.querySelector('.message-list');
        if (!listEl) return;

        const msgDiv = document.createElement('div');
        msgDiv.className = 'dm-system-message flex justify-center my-3';
        msgDiv.innerHTML = `
            <div class="px-4 py-2 bg-purple-500/15 border border-purple-500/30 rounded-xl text-purple-300 text-sm text-center max-w-[90%]">
                ${escapeHtml(text)}
            </div>
        `;
        listEl.appendChild(msgDiv);

        // Scroll to bottom
        container.scrollTop = container.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
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
        const previousStatus = dmConfig.otherUserStatus;
        dmConfig.otherUserStatus = status; // ENTERPRISE: Keep config in sync!

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
            // Clear DND notice but not cooldown
            clearStatusNotice();
        } else {
            // Online (default)
            if (statusText) statusText.textContent = 'Online';
            if (statusDot) {
                statusDot.classList.remove('bg-gray-500', 'bg-yellow-500');
                statusDot.classList.add('bg-green-500');
            }
            // ENTERPRISE DND: Clear cooldown when user comes back online from DND
            if (previousStatus === 'dnd' || previousStatus === 'busy') {
                clearDndCooldown();
                showSystemMessage(`✅ ${dmConfig.otherUserName} è di nuovo disponibile. Puoi inviargli un messaggio.`);
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

    // =========================================================================
    // ENTERPRISE V10.41: Back Button Handler
    // On desktop: Navigate to /chat and open minimized widget
    // On mobile: Just navigate to /chat (extended view is already the UI)
    // =========================================================================
    const dmBackButton = document.getElementById('dmBackButton');
    if (dmBackButton) {
        dmBackButton.addEventListener('click', () => {
            const isMobile = window.innerWidth < 768 ||
                /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

            if (isMobile) {
                // Mobile: Just navigate to chat page
                window.location.href = '/chat';
                return;
            }

            // Desktop: Store conversation data for widget restoration
            // ChatWidgetManager on /chat will read this and open the widget
            const widgetRestoreData = {
                conversationUuid: dmConfig.conversationUuid,
                otherUser: {
                    uuid: dmConfig.otherUserUuid,
                    nickname: dmConfig.otherUserName,
                    avatar_url: dmConfig.otherUserAvatar,
                    status: dmConfig.otherUserStatus
                },
                timestamp: Date.now()
            };

            // Store in sessionStorage (cleared when browser closes, survives page navigation)
            sessionStorage.setItem('n2t_widget_restore', JSON.stringify(widgetRestoreData));
            console.log('[DM] Stored widget restore data:', widgetRestoreData);

            // Navigate to /chat - widget will be restored there
            window.location.href = '/chat';
        });
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

    /**
     * ENTERPRISE v10.6: iOS Keyboard Fix (Safari + Chrome Mobile)
     *
     * iOS WebKit Problem:
     * - Virtual keyboard pushes viewport but layout doesn't adjust
     * - CSS dvh/svh units have bugs on older iOS
     * - overflow:hidden blocks native scroll behavior
     *
     * SOLUTION: Position fixed + transform to move chat above keyboard
     */
    function setupIOSKeyboardFix() {
        // Detect iOS (all browsers on iOS use WebKit)
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
                      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        if (!isIOS) {
            console.log('[DM] Not iOS, skipping keyboard fix');
            return;
        }

        const chatContainer = document.getElementById('dmChatContainer');
        const inputContainer = document.getElementById('messageInputContainer');
        const messagesContainer = document.getElementById('messagesContainer');

        if (!chatContainer || !inputContainer) {
            console.warn('[DM] iOS keyboard fix: containers not found');
            return;
        }

        console.log('[DM] iOS keyboard fix v10.6 initialized');

        // Get the input element
        const inputEl = inputContainer.querySelector('textarea, input[type="text"]');
        if (!inputEl) {
            console.warn('[DM] iOS keyboard fix: input element not found');
            return;
        }

        let isKeyboardVisible = false;

        // Store original styles
        const originalStyles = {
            position: chatContainer.style.position,
            top: chatContainer.style.top,
            left: chatContainer.style.left,
            right: chatContainer.style.right,
            bottom: chatContainer.style.bottom,
            height: chatContainer.style.height,
            transform: chatContainer.style.transform
        };

        function onKeyboardShow() {
            if (isKeyboardVisible) return;
            isKeyboardVisible = true;

            console.log('[DM] iOS Keyboard SHOW');

            // Wait for keyboard to fully appear
            setTimeout(() => {
                if (!window.visualViewport) return;

                const vvHeight = window.visualViewport.height;
                const vvOffsetTop = window.visualViewport.offsetTop;

                console.log('[DM] visualViewport:', { height: vvHeight, offsetTop: vvOffsetTop });

                // Make container fixed and fit to visual viewport
                chatContainer.style.cssText = `
                    position: fixed !important;
                    top: ${vvOffsetTop}px !important;
                    left: 0 !important;
                    right: 0 !important;
                    bottom: auto !important;
                    height: ${vvHeight}px !important;
                    max-height: ${vvHeight}px !important;
                    z-index: 9999 !important;
                `;

                // Scroll messages to bottom
                requestAnimationFrame(() => {
                    if (messagesContainer) {
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                });
            }, 300);
        }

        function onKeyboardHide() {
            if (!isKeyboardVisible) return;
            isKeyboardVisible = false;

            console.log('[DM] iOS Keyboard HIDE');

            // Restore original styles
            chatContainer.style.cssText = '';
            Object.entries(originalStyles).forEach(([prop, value]) => {
                if (value) chatContainer.style[prop] = value;
            });
        }

        // Method 1: Focus/Blur on input (most reliable trigger)
        inputEl.addEventListener('focus', () => {
            console.log('[DM] Input FOCUS');
            onKeyboardShow();
        });

        inputEl.addEventListener('blur', () => {
            console.log('[DM] Input BLUR');
            // Delay to handle tap on send button
            setTimeout(onKeyboardHide, 100);
        });

        // Method 2: visualViewport events (backup for viewport changes)
        if (window.visualViewport) {
            let lastHeight = window.visualViewport.height;

            window.visualViewport.addEventListener('resize', () => {
                const newHeight = window.visualViewport.height;
                const diff = lastHeight - newHeight;

                console.log('[DM] Viewport resize:', { lastHeight, newHeight, diff });

                // Update container if keyboard is visible
                if (isKeyboardVisible && newHeight < lastHeight) {
                    chatContainer.style.height = `${newHeight}px`;
                    chatContainer.style.maxHeight = `${newHeight}px`;
                    chatContainer.style.top = `${window.visualViewport.offsetTop}px`;

                    requestAnimationFrame(() => {
                        if (messagesContainer) {
                            messagesContainer.scrollTop = messagesContainer.scrollHeight;
                        }
                    });
                }

                lastHeight = newHeight;
            });
        }

        // Method 3: Touchend on send button should keep keyboard open
        const sendBtn = inputContainer.querySelector('button[type="submit"], button');
        if (sendBtn) {
            sendBtn.addEventListener('touchend', (e) => {
                // Prevent blur when tapping send
                e.preventDefault();
                sendBtn.click();
                // Refocus input
                setTimeout(() => inputEl.focus(), 50);
            });
        }
    }
});
</script>
