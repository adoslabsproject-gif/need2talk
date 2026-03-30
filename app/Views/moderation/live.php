<?php
/**
 * Moderation Portal Live Monitoring - need2talk Enterprise
 *
 * Live monitoring delle chat rooms con azioni dirette
 *
 * @package Need2Talk\Views\Moderation
 */

use Need2Talk\Services\Moderation\ModerationSecurityService;

$modBaseUrl = ModerationSecurityService::generateModerationUrl();
$rooms = $rooms ?? [];
$selectedRoom = $selectedRoom ?? null;
$messages = $messages ?? [];
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-size: 1.75rem; font-weight: 700; color: #ffffff;">
        Live Monitoring
    </h1>

    <div style="display: flex; align-items: center; gap: 1rem;">
        <span id="connectionStatus" class="mod-badge mod-badge-warning">
            Connecting...
        </span>
        <button onclick="refreshRooms()" class="mod-btn mod-btn-secondary" id="refreshBtn">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
            </svg>
            Refresh
        </button>
    </div>
</div>

<!-- Room Selection Grid -->
<div class="mod-card" id="roomSelector">
    <div class="mod-card-header">
        <h2 class="mod-card-title">Select a Room to Monitor</h2>
        <div id="totalOnline" style="color: #d946ef; font-size: 0.875rem;">
            <span id="totalOnlineCount">0</span> users online
        </div>
    </div>

    <!-- Emotion Rooms -->
    <div style="margin-bottom: 1.5rem;">
        <h3 style="font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">
            Emotion Rooms
        </h3>
        <div class="mod-room-grid" id="emotionRooms">
            <?php
            // ENTERPRISE GALAXY v4.7 (2025-12-24): Uniformate con tabella emotions
            $emotionRooms = [
                ['id' => 'emotion:joy', 'slug' => 'gioia', 'name' => 'Gioia', 'emoji' => '😊', 'color' => '#FFD700'],
                ['id' => 'emotion:wonder', 'slug' => 'meraviglia', 'name' => 'Meraviglia', 'emoji' => '✨', 'color' => '#FF6B35'],
                ['id' => 'emotion:love', 'slug' => 'amore', 'name' => 'Amore', 'emoji' => '❤️', 'color' => '#FF1493'],
                ['id' => 'emotion:gratitude', 'slug' => 'gratitudine', 'name' => 'Gratitudine', 'emoji' => '🙏', 'color' => '#32CD32'],
                ['id' => 'emotion:hope', 'slug' => 'speranza', 'name' => 'Speranza', 'emoji' => '🌟', 'color' => '#87CEEB'],
                ['id' => 'emotion:sadness', 'slug' => 'tristezza', 'name' => 'Tristezza', 'emoji' => '😢', 'color' => '#4682B4'],
                ['id' => 'emotion:anger', 'slug' => 'rabbia', 'name' => 'Rabbia', 'emoji' => '😠', 'color' => '#DC143C'],
                ['id' => 'emotion:anxiety', 'slug' => 'ansia', 'name' => 'Ansia', 'emoji' => '😰', 'color' => '#FF8C00'],
                ['id' => 'emotion:fear', 'slug' => 'paura', 'name' => 'Paura', 'emoji' => '😨', 'color' => '#8B008B'],
                ['id' => 'emotion:loneliness', 'slug' => 'solitudine', 'name' => 'Solitudine', 'emoji' => '😔', 'color' => '#696969'],
            ];

            foreach ($emotionRooms as $room):
            ?>
            <div class="mod-room-card" data-room-type="emotion" data-room-id="<?= $room['id'] ?>" data-room-slug="<?= $room['slug'] ?>"
                 onclick="selectRoom('<?= $room['id'] ?>', '<?= $room['name'] ?>', '<?= $room['emoji'] ?>')"
                 style="border-color: <?= $room['color'] ?>40;">
                <div class="mod-room-emoji"><?= $room['emoji'] ?></div>
                <div class="mod-room-name"><?= $room['name'] ?></div>
                <div class="mod-room-users">
                    <span class="mod-room-online" id="room-online-<?= $room['id'] ?>">0 online</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- User Created Rooms -->
    <div>
        <h3 style="font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">
            User Created Rooms <span id="userRoomsCount" style="color: #d946ef;">(0 active)</span>
        </h3>
        <div class="mod-room-grid" id="userRooms">
            <div style="text-align: center; padding: 2rem; color: #6b7280; grid-column: 1 / -1;">
                Loading user rooms...
            </div>
        </div>
    </div>
</div>

<!-- Room Monitor Panel (Hidden until room selected) -->
<div class="mod-card" id="roomMonitor" style="display: none;">
    <div class="mod-card-header">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <span id="monitorRoomEmoji" style="font-size: 1.5rem;"></span>
            <div>
                <h2 class="mod-card-title" id="monitorRoomName">Room Name</h2>
                <div style="font-size: 0.75rem; color: #9ca3af;">
                    <span class="mod-room-online" id="monitorOnlineCount">0 online</span>
                </div>
            </div>
        </div>
        <button onclick="closeRoom()" class="mod-btn mod-btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8 2.146 2.854Z"/>
            </svg>
            Close
        </button>
    </div>

    <!-- Messages Container -->
    <div id="messagesContainer" style="max-height: 400px; overflow-y: auto; padding: 1rem; background: rgba(15, 15, 15, 0.5); border-radius: 0.5rem; margin-bottom: 1rem;">
        <div id="messagesList">
            <div style="text-align: center; padding: 2rem; color: #6b7280;">
                Connessione alla room...
            </div>
        </div>
    </div>

    <!-- Moderator Chat Input Bar -->
    <div id="chatInputContainer" style="margin-bottom: 1rem; padding: 1rem; background: rgba(217, 70, 239, 0.05); border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem;">
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
            <span style="font-size: 1.25rem;">🛡️</span>
            <span style="font-size: 0.875rem; color: #d946ef; font-weight: 500;">
                Partecipa come: <?= htmlspecialchars($session['display_name'] ?? $session['username'] ?? 'Moderatore') ?>
            </span>
        </div>
        <form id="chatInputForm" onsubmit="sendModeratorMessage(event)" style="display: flex; gap: 0.5rem;">
            <input type="text"
                   id="chatInput"
                   placeholder="Scrivi un messaggio come moderatore..."
                   maxlength="500"
                   autocomplete="off"
                   style="flex: 1; padding: 0.75rem 1rem; background: #0f0f0f; border: 1px solid rgba(217, 70, 239, 0.3); border-radius: 0.5rem; color: #ffffff; font-size: 0.875rem;"
                   class="mod-input">
            <button type="submit"
                    id="chatSendBtn"
                    class="mod-btn mod-btn-primary"
                    style="padding: 0.75rem 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576 6.636 10.07Zm6.787-8.201L1.591 6.602l4.339 2.76 7.494-7.493Z"/>
                </svg>
                Invia
            </button>
        </form>
        <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.5rem;">
            I tuoi messaggi appariranno con il badge 🛡️ Moderatore
        </div>
    </div>

    <!-- Online Users -->
    <div style="border-top: 1px solid rgba(217, 70, 239, 0.2); padding-top: 1rem;">
        <h4 style="font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Users in Room</h4>
        <div id="onlineUsersList" style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <span style="color: #6b7280; font-size: 0.875rem;">Loading...</span>
        </div>
    </div>
</div>

<!-- Ban Modal -->
<div id="banModal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); z-index: 100; align-items: center; justify-content: center;">
    <div style="background: #171717; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.75rem; padding: 1.5rem; max-width: 400px; width: 90%;">
        <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #ef4444;">
            Ban User
        </h3>

        <form id="banForm">
            <input type="hidden" id="banUserId" name="user_id">
            <input type="hidden" id="banMessageUuid" name="message_uuid">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">User</label>
                <div id="banUserName" style="font-weight: 600; color: #ffffff;"></div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Ban Scope</label>
                <select id="banScope" name="scope" style="width: 100%; padding: 0.5rem; background: #0f0f0f; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem; color: #ffffff;">
                    <option value="chat">Chat Only</option>
                    <option value="posts">Posts Only</option>
                    <option value="comments">Comments Only</option>
                    <option value="global">Global (All Features)</option>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Duration</label>
                <select id="banDuration" name="duration" style="width: 100%; padding: 0.5rem; background: #0f0f0f; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem; color: #ffffff;">
                    <option value="60">1 hour</option>
                    <option value="1440">24 hours</option>
                    <option value="10080">7 days</option>
                    <option value="43200">30 days</option>
                    <option value="">Permanent</option>
                </select>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.875rem; color: #9ca3af; margin-bottom: 0.5rem;">Reason</label>
                <textarea id="banReason" name="reason" rows="3" required
                          style="width: 100%; padding: 0.5rem; background: #0f0f0f; border: 1px solid rgba(217, 70, 239, 0.2); border-radius: 0.5rem; color: #ffffff; resize: none;"
                          placeholder="Enter ban reason..."></textarea>
            </div>

            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                <button type="button" onclick="closeBanModal()" class="mod-btn mod-btn-secondary">Cancel</button>
                <button type="submit" class="mod-btn mod-btn-danger">Ban User</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    const modBaseUrl = '<?= htmlspecialchars($modBaseUrl) ?>';
    let currentRoom = null;
    let ws = null;
    let messagePollingInterval = null;
    let roomCountsInterval = null;
    let lastMessageId = null;

    // Emotion rooms configuration (matching PHP)
    // ENTERPRISE GALAXY v4.7 (2025-12-24): Uniformate con tabella emotions
    const emotionRooms = {
        'emotion:joy': { name: 'Gioia', emoji: '😊', color: '#FFD700' },
        'emotion:wonder': { name: 'Meraviglia', emoji: '✨', color: '#FF6B35' },
        'emotion:love': { name: 'Amore', emoji: '❤️', color: '#FF1493' },
        'emotion:gratitude': { name: 'Gratitudine', emoji: '🙏', color: '#32CD32' },
        'emotion:hope': { name: 'Speranza', emoji: '🌟', color: '#87CEEB' },
        'emotion:sadness': { name: 'Tristezza', emoji: '😢', color: '#4682B4' },
        'emotion:anger': { name: 'Rabbia', emoji: '😠', color: '#DC143C' },
        'emotion:anxiety': { name: 'Ansia', emoji: '😰', color: '#FF8C00' },
        'emotion:fear': { name: 'Paura', emoji: '😨', color: '#8B008B' },
        'emotion:loneliness': { name: 'Solitudine', emoji: '😔', color: '#696969' },
    };

    /**
     * Select a room to monitor
     * @param {string} roomId - Room ID (e.g., 'emotion:joy')
     * @param {string} name - Room name
     * @param {string} emoji - Room emoji
     */
    function selectRoom(roomId, name, emoji) {
        currentRoom = { id: roomId, name, emoji };

        // Hide room selector, show monitor
        document.getElementById('roomSelector').style.display = 'none';
        document.getElementById('roomMonitor').style.display = 'block';

        // Update room header
        document.getElementById('monitorRoomEmoji').textContent = emoji;
        document.getElementById('monitorRoomName').textContent = name;

        // Update status
        updateConnectionStatus('connecting');

        // Load messages immediately
        loadMessages();

        // Start polling messages every 3 seconds
        messagePollingInterval = setInterval(loadMessages, 3000);

        // Load online users
        loadOnlineUsers();
    }

    /**
     * Close room monitor
     */
    function closeRoom() {
        currentRoom = null;
        document.getElementById('roomSelector').style.display = 'block';
        document.getElementById('roomMonitor').style.display = 'none';

        if (messagePollingInterval) {
            clearInterval(messagePollingInterval);
            messagePollingInterval = null;
        }

        if (ws) {
            ws.close();
            ws = null;
        }

        lastMessageId = null;
        updateConnectionStatus('disconnected');
    }

    /**
     * Load all room online counts
     */
    async function loadAllRoomCounts() {
        try {
            const response = await fetch(`${modBaseUrl}/api/rooms/counts`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                }
            });

            const result = await response.json();

            if (result.success && result.counts) {
                let totalOnline = 0;
                for (const [roomId, count] of Object.entries(result.counts)) {
                    const el = document.getElementById(`room-online-${roomId}`);
                    if (el) {
                        el.textContent = `${count} online`;
                    }
                    totalOnline += count;
                }

                // Also add user room counts to total
                if (result.user_room_counts) {
                    for (const [roomId, count] of Object.entries(result.user_room_counts)) {
                        totalOnline += count;
                        // Update individual room card if exists
                        const el = document.getElementById(`room-online-${roomId}`);
                        if (el) {
                            el.textContent = `${count} online`;
                        }
                    }
                }

                document.getElementById('totalOnlineCount').textContent = totalOnline;
            }
        } catch (error) {
            console.error('Failed to load room counts:', error);
        }
    }

    /**
     * Load user-created rooms
     */
    async function loadUserRooms() {
        try {
            const response = await fetch(`${modBaseUrl}/api/rooms/user-created`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                }
            });

            const result = await response.json();
            const container = document.getElementById('userRooms');
            const countEl = document.getElementById('userRoomsCount');

            if (result.success && result.rooms) {
                countEl.textContent = `(${result.rooms.length} attive)`;

                if (result.rooms.length === 0) {
                    container.innerHTML = `
                        <div style="text-align: center; padding: 2rem; color: #6b7280; grid-column: 1 / -1;">
                            Nessuna stanza utente attiva
                        </div>
                    `;
                    return;
                }

                container.innerHTML = result.rooms.map(room => {
                    const isPrivate = room.is_private;
                    const lockIcon = isPrivate ? '🔒' : '🔓';
                    const createdAt = new Date(room.created_at).toLocaleString('it-IT', {
                        day: '2-digit',
                        month: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    });

                    return `
                        <div class="mod-room-card" data-room-type="user" data-room-id="${room.room_id}"
                             onclick="selectRoom('${room.room_id}', '${escapeHtml(room.name)}', '${lockIcon}')"
                             style="border-color: #d946ef40;">
                            <div class="mod-room-emoji">${lockIcon}</div>
                            <div class="mod-room-name" style="font-size: 0.875rem;">${escapeHtml(room.name)}</div>
                            <div style="font-size: 0.65rem; color: #9ca3af; margin-top: 0.25rem;">
                                Creatore: ${escapeHtml(room.creator)}
                            </div>
                            <div class="mod-room-users">
                                <span class="mod-room-online" id="room-online-${room.room_id}">${room.online_count} online</span>
                            </div>
                            <div style="font-size: 0.6rem; color: #6b7280; margin-top: 0.25rem;">
                                ${createdAt}
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                container.innerHTML = `
                    <div style="text-align: center; padding: 2rem; color: #ef4444; grid-column: 1 / -1;">
                        Errore caricamento stanze
                    </div>
                `;
            }
        } catch (error) {
            console.error('Failed to load user rooms:', error);
            document.getElementById('userRooms').innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #ef4444; grid-column: 1 / -1;">
                    Errore di connessione
                </div>
            `;
        }
    }

    /**
     * Load messages via API
     */
    async function loadMessages() {
        if (!currentRoom) return;

        // Use the room ID directly (e.g., 'emotion:joy')
        const roomId = encodeURIComponent(currentRoom.id);

        const url = lastMessageId
            ? `${modBaseUrl}/api/rooms/${roomId}/messages?after=${lastMessageId}`
            : `${modBaseUrl}/api/rooms/${roomId}/messages`;

        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                }
            });

            const result = await response.json();

            if (result.success) {
                updateConnectionStatus('connected');
                // DEBUG: Log first message to see created_at format
                if (result.messages && result.messages.length > 0) {
                    console.log('[DEBUG] First message raw data:', result.messages[0]);
                    console.log('[DEBUG] created_at value:', result.messages[0].created_at, 'type:', typeof result.messages[0].created_at);
                }

                if (result.messages && result.messages.length > 0) {
                    if (!lastMessageId) {
                        // Initial load - replace all
                        renderMessages(result.messages);
                    } else {
                        // Append new messages
                        result.messages.forEach(msg => appendMessage(msg));
                    }

                    // Update last message ID for pagination
                    const lastMsg = result.messages[result.messages.length - 1];
                    if (lastMsg && lastMsg.uuid) {
                        lastMessageId = lastMsg.uuid;
                    }
                } else if (!lastMessageId) {
                    // No messages at all
                    renderMessages([]);
                }

                // Update online count
                if (typeof result.online_count !== 'undefined') {
                    updateOnlineCount(result.online_count);
                }
            }
        } catch (error) {
            console.error('Failed to load messages:', error);
            updateConnectionStatus('error');
        }
    }

    /**
     * Render messages list
     */
    function renderMessages(messages) {
        const container = document.getElementById('messagesList');

        if (!messages || messages.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #6b7280;">
                    No messages yet in this room
                </div>
            `;
            return;
        }

        container.innerHTML = messages.map(msg => createMessageHtml(msg)).join('');
        scrollToBottom();
    }

    /**
     * Append single message with DEDUPLICATION
     * ENTERPRISE: Prevents duplicate messages from optimistic UI + polling race condition
     */
    function appendMessage(msg) {
        const container = document.getElementById('messagesList');
        const msgId = msg.uuid || msg.id;

        // ENTERPRISE: Check for duplicate message by ID
        if (msgId && document.getElementById(`msg-${msgId}`)) {
            console.log('[MOD] Skipping duplicate message:', msgId);
            return; // Already exists, skip
        }

        const emptyMessage = container.querySelector('div[style*="text-align: center"]');
        if (emptyMessage) {
            container.innerHTML = '';
        }

        container.insertAdjacentHTML('beforeend', createMessageHtml(msg));
        scrollToBottom();
    }

    /**
     * Create message HTML
     */
    function createMessageHtml(msg) {
        // ENTERPRISE FIX: created_at is Unix timestamp in SECONDS, JS needs MILLISECONDS
        // Before: new Date(1734012386) → 1970-01-21 11:26 (wrong - interpreted as ms)
        // After: new Date(1734012386 * 1000) → 2024-12-12 correct time
        // Also supports pre-formatted time from server (created_at_formatted)
        let time;
        if (msg.created_at_formatted) {
            // Server already formatted it as 'H:i' (e.g., "14:35")
            time = msg.created_at_formatted;
        } else if (msg.created_at) {
            // Convert Unix seconds to milliseconds for JS Date
            const timestamp = msg.created_at * 1000;
            time = new Date(timestamp).toLocaleTimeString('it-IT', {
                hour: '2-digit',
                minute: '2-digit'
            });
        } else {
            time = new Date().toLocaleTimeString('it-IT', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        const isModerator = msg.is_moderator === true;
        const isDeleted = msg.deleted === true;
        const moderatorBadge = isModerator ? '<span style="margin-right: 0.25rem;">🛡️</span>' : '';
        const nameColor = isModerator ? '#d946ef' : '#e5e7eb';

        // ENTERPRISE V10.87: Style for deleted messages
        let bgColor = isModerator ? 'rgba(217, 70, 239, 0.1)' : 'transparent';
        let extraStyles = '';
        let deletedBadge = '';

        if (isDeleted) {
            bgColor = 'rgba(239, 68, 68, 0.1)';
            extraStyles = 'opacity: 0.6; border-left: 3px solid #ef4444;';
            deletedBadge = '<span style="background: #ef4444; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: bold; margin-left: 8px;">🗑️ DELETED</span>';
        }

        // Only show ban button for non-moderator messages
        const banButton = isModerator ? '' : `
            <button onclick="openBanModal(${msg.user_id || 0}, '${msg.uuid || msg.id}', '${escapeHtml(msg.nickname || msg.sender_nickname || 'User')}')" class="mod-btn mod-btn-danger" style="padding: 0.25rem 0.5rem;" title="Ban User">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                    <path d="M11.354 4.646a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708l6-6a.5.5 0 0 1 .708 0z"/>
                </svg>
            </button>
        `;

        // ENTERPRISE V10.87: Disable delete button if already deleted
        const deleteButton = isDeleted ? `
            <button disabled class="mod-btn mod-btn-secondary" style="padding: 0.25rem 0.5rem; opacity: 0.3; cursor: not-allowed;" title="Already Deleted">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                    <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                </svg>
            </button>
        ` : `
            <button onclick="deleteMessage('${msg.uuid || msg.id}')" class="mod-btn mod-btn-secondary" style="padding: 0.25rem 0.5rem;" title="Delete Message">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                    <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                </svg>
            </button>
        `;

        return `
            <div class="mod-message" id="msg-${msg.uuid || msg.id}" style="padding: 0.75rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); display: flex; gap: 1rem; align-items: flex-start; background: ${bgColor}; ${extraStyles}">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                        ${moderatorBadge}
                        <span style="font-weight: 600; color: ${nameColor};">${escapeHtml(msg.nickname || msg.sender_nickname || 'Anonimo')}</span>
                        ${isModerator ? '<span style="font-size: 0.65rem; color: #d946ef; background: rgba(217, 70, 239, 0.2); padding: 0.1rem 0.4rem; border-radius: 9999px;">MOD</span>' : ''}
                        <span style="font-size: 0.75rem; color: #6b7280;">${time}</span>
                        ${deletedBadge}
                    </div>
                    <div style="color: #d1d5db; word-break: break-word;">${escapeHtml(msg.content || msg.message || '')}</div>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-shrink: 0;">
                    ${deleteButton}
                    ${banButton}
                </div>
            </div>
        `;
    }

    /**
     * Remove message from UI
     */
    /**
     * Mark message as deleted (for moderator view)
     * ENTERPRISE: Moderator keeps seeing deleted messages with visual indicator
     */
    function markMessageAsDeleted(uuid) {
        const msgEl = document.getElementById(`msg-${uuid}`);
        if (msgEl) {
            // Add deleted visual indicator
            msgEl.style.opacity = '0.6';
            msgEl.style.background = 'rgba(239, 68, 68, 0.1)';
            msgEl.style.borderLeft = '3px solid #ef4444';

            // Add DELETED badge if not already present
            if (!msgEl.querySelector('.deleted-badge')) {
                const bubble = msgEl.querySelector('.mod-message-bubble') || msgEl.querySelector('div:last-child');
                if (bubble) {
                    const badge = document.createElement('div');
                    badge.className = 'deleted-badge';
                    badge.innerHTML = '<span style="background: #ef4444; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: bold; margin-top: 4px; display: inline-block;">🗑️ DELETED BY MODERATOR</span>';
                    bubble.appendChild(badge);
                }
            }

            // Disable delete button (already deleted)
            const deleteBtn = msgEl.querySelector('button[onclick*="deleteMessage"]');
            if (deleteBtn) {
                deleteBtn.disabled = true;
                deleteBtn.style.opacity = '0.3';
                deleteBtn.style.cursor = 'not-allowed';
            }
        }
    }

    // Alias for backward compatibility
    function removeMessage(uuid) {
        markMessageAsDeleted(uuid);
    }

    /**
     * Delete message
     */
    async function deleteMessage(uuid) {
        if (!confirm('Delete this message?')) return;

        if (!currentRoom) {
            showToast('No room selected', 'error');
            return;
        }

        try {
            const response = await fetch(`${modBaseUrl}/api/messages/delete`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    room_uuid: currentRoom.id,
                    message_uuid: uuid
                })
            });

            const result = await response.json();

            if (result.success) {
                removeMessage(uuid);
                showToast('Message deleted', 'success');
            } else {
                showToast(result.error || 'Failed to delete message', 'error');
            }
        } catch (error) {
            showToast('Request failed', 'error');
        }
    }

    /**
     * Open ban modal
     */
    function openBanModal(userId, messageUuid, nickname) {
        document.getElementById('banUserId').value = userId;
        document.getElementById('banMessageUuid').value = messageUuid;
        document.getElementById('banUserName').textContent = nickname;
        document.getElementById('banReason').value = '';
        document.getElementById('banModal').style.display = 'flex';
    }

    /**
     * Close ban modal
     */
    function closeBanModal() {
        document.getElementById('banModal').style.display = 'none';
    }

    /**
     * Submit ban form
     */
    document.getElementById('banForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const data = {
            user_id: document.getElementById('banUserId').value,
            scope: document.getElementById('banScope').value,
            duration: document.getElementById('banDuration').value || null,
            reason: document.getElementById('banReason').value,
            message_uuid: document.getElementById('banMessageUuid').value
        };

        try {
            const response = await fetch(`${modBaseUrl}/api/users/ban`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                closeBanModal();
                showToast('User banned successfully', 'success');
            } else {
                showToast(result.error || 'Failed to ban user', 'error');
            }
        } catch (error) {
            showToast('Request failed', 'error');
        }
    });

    /**
     * Load online users for current room
     */
    async function loadOnlineUsers() {
        if (!currentRoom) return;

        const roomId = encodeURIComponent(currentRoom.id);

        try {
            const response = await fetch(`${modBaseUrl}/api/rooms/${roomId}/online`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                }
            });

            const result = await response.json();

            if (result.success) {
                const container = document.getElementById('onlineUsersList');
                if (!result.users || result.users.length === 0) {
                    container.innerHTML = '<span style="color: #6b7280; font-size: 0.875rem;">Nessun utente online</span>';
                } else {
                    container.innerHTML = result.users.map(user => `
                        <span style="padding: 0.25rem 0.5rem; background: rgba(217, 70, 239, 0.1); border-radius: 9999px; font-size: 0.75rem; color: #e5e7eb;">
                            ${escapeHtml(user.nickname || user.user_id || 'Anonimo')}
                        </span>
                    `).join('');
                }
                updateOnlineCount(result.count || result.users.length);
            }
        } catch (error) {
            console.error('Failed to load online users:', error);
        }
    }

    /**
     * Update online count display
     */
    function updateOnlineCount(count) {
        document.getElementById('monitorOnlineCount').textContent = `${count} online`;
    }

    /**
     * Update connection status
     */
    function updateConnectionStatus(status) {
        const el = document.getElementById('connectionStatus');
        switch (status) {
            case 'connected':
                el.className = 'mod-badge mod-badge-success';
                el.textContent = 'Connesso';
                break;
            case 'disconnected':
                el.className = 'mod-badge mod-badge-warning';
                el.textContent = 'Disconnesso';
                break;
            case 'error':
                el.className = 'mod-badge mod-badge-danger';
                el.textContent = 'Errore Connessione';
                break;
            case 'connecting':
                el.className = 'mod-badge mod-badge-warning';
                el.textContent = 'Connessione...';
                break;
            default:
                el.className = 'mod-badge mod-badge-warning';
                el.textContent = 'Connessione...';
        }
    }

    /**
     * Scroll messages to bottom
     */
    function scrollToBottom() {
        const container = document.getElementById('messagesContainer');
        container.scrollTop = container.scrollHeight;
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Send moderator message to current room
     */
    async function sendModeratorMessage(event) {
        event.preventDefault();

        if (!currentRoom) {
            showToast('Seleziona prima una room', 'error');
            return;
        }

        const input = document.getElementById('chatInput');
        const sendBtn = document.getElementById('chatSendBtn');
        const content = input.value.trim();

        if (!content) {
            return;
        }

        // Disable input while sending
        input.disabled = true;
        sendBtn.disabled = true;

        try {
            const roomId = encodeURIComponent(currentRoom.id);
            const response = await fetch(`${modBaseUrl}/api/rooms/${roomId}/messages`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ content: content })
            });

            const result = await response.json();

            if (result.success) {
                // Clear input
                input.value = '';

                // Append message immediately (optimistic UI)
                appendMessage(result.message);

                // ENTERPRISE: Update lastMessageId to prevent duplicate from polling
                if (result.message && result.message.uuid) {
                    lastMessageId = result.message.uuid;
                }

                // No toast needed - message appears in chat instantly
            } else {
                showToast(result.error || 'Invio fallito', 'error');
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            showToast('Errore di connessione', 'error');
        } finally {
            // Re-enable input
            input.disabled = false;
            sendBtn.disabled = false;
            input.focus();
        }
    }

    /**
     * Refresh rooms list and counts
     */
    function refreshRooms() {
        const btn = document.getElementById('refreshBtn');
        btn.disabled = true;
        btn.innerHTML = '<svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/></svg> Updating...';

        Promise.all([loadAllRoomCounts(), loadUserRooms()]).finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/><path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/></svg> Refresh';
        });
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        // Load room counts and user rooms immediately
        loadAllRoomCounts();
        loadUserRooms();

        // Refresh counts every 10 seconds
        roomCountsInterval = setInterval(loadAllRoomCounts, 10000);

        // Refresh user rooms every 30 seconds (less frequent as they change less often)
        setInterval(loadUserRooms, 30000);

        // Check for room parameter in URL
        const urlParams = new URLSearchParams(window.location.search);
        const roomParam = urlParams.get('room');
        if (roomParam && emotionRooms[roomParam]) {
            const room = emotionRooms[roomParam];
            selectRoom(roomParam, room.name, room.emoji);
        }
    });
</script>
