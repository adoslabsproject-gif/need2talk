# WebSocket Real-Time System - Enterprise Documentation

**Project**: need2talk.it Real-Time Communication
**Version**: 2.0.0 (Swoole Edition)
**Last Updated**: 2025-12-04

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Data Flow](#data-flow)
3. [Message Types](#message-types)
4. [Channel Patterns](#channel-patterns)
5. [File Structure](#file-structure)
6. [Debugging Guide](#debugging-guide)

---

## Architecture Overview

### Technology Stack

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           CLIENT (Browser)                               │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ WebSocketManager.js → ChatManager.js → FriendsWidget.js          │  │
│  │        ↓                    ↓                   ↓                  │  │
│  │   onMessage()         #handleDMReceived    updateFriendPresence   │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                               ↓ wss://                                  │
├─────────────────────────────────────────────────────────────────────────┤
│                          NGINX (Reverse Proxy)                          │
│  location /wss/ → proxy_pass http://websocket:8090                      │
├─────────────────────────────────────────────────────────────────────────┤
│                    SWOOLE WebSocket Server (Port 8090)                   │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  Master Process                                                    │ │
│  │  ├── Worker 0 → FDs: 0,4,8,12... (fd % 4 == 0)                   │ │
│  │  ├── Worker 1 → FDs: 1,5,9,13... (fd % 4 == 1)                   │ │
│  │  ├── Worker 2 → FDs: 2,6,10,14... (fd % 4 == 2)                  │ │
│  │  ├── Worker 3 → FDs: 3,7,11,15... (fd % 4 == 3)                  │ │
│  │  └── Task Worker → Redis PubSub Subscriber (blocking OK)          │ │
│  │                                                                    │ │
│  │  Shared Memory (Swoole\Table):                                    │ │
│  │  - $authenticatedClients: fd → {uuid, nickname, avatar_url}       │ │
│  │  - $fdToRoom: fd → {room_id, room_type, joined_at}               │ │
│  │  - $fdToPostSubscriptions: fd → {post_ids JSON}                  │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                               ↓ psubscribe                              │
├─────────────────────────────────────────────────────────────────────────┤
│                          REDIS PubSub (DB 4)                            │
│  Channels: websocket:events:{uuid|room:*|conv:*|global|post:*}         │
│                               ↑ PUBLISH                                 │
├─────────────────────────────────────────────────────────────────────────┤
│                        PHP Application (FPM)                            │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │ WebSocketPublisher::publishToUser($uuid, 'dm_received', $data)    │ │
│  │ WebSocketPublisher::publishToRoom($roomId, 'room_message', $data) │ │
│  │ NotificationService::pushNotification($uuid, $notification)       │ │
│  └────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Swoole over Ratchet** | 10x performance (C extension), built-in multi-process, 100k+ connections |
| **4 Workers + 1 Task** | 4 workers for WS push (CPU-bound), 1 task for Redis sub (blocking OK) |
| **Swoole\Table** | Cross-worker shared memory for user tracking (no Redis overhead) |
| **Redis PubSub** | PHP-FPM → Swoole communication bridge (different processes) |
| **fd % worker_num** | Swoole's dispatch_mode=2 for deterministic FD→Worker routing |

---

## Data Flow

### DM Message Flow (A sends to B)

```
1. User A types message in chat
   ↓
2. Frontend: fetch('/api/chat/dm/{convUuid}/messages', {method:'POST'})
   ↓
3. DMController::sendMessage()
   ├── Validates, encrypts, stores in PostgreSQL
   └── DirectMessageService::sendMessage()
       ├── Creates message in dm_messages table
       └── publishToUser($recipientUuid, 'dm_received', $messageData)
           ↓
4. WebSocketPublisher::publishToUser()
   └── Redis PUBLISH websocket:events:{recipientUuid}
       ↓
5. Task Worker (Redis psubscribe callback)
   └── Decodes message, broadcasts to ALL 4 workers via pipeMessage
       ↓
6. pipeMessage handler (on each worker)
   ├── Scans $authenticatedClients for FDs with uuid === recipientUuid
   ├── Filters to FDs owned by THIS worker (fd % worker_num === worker_id)
   └── $server->push($fd, $clientMessage)
       ↓
7. Frontend: websocket.onmessage → handleMessage()
   ├── WebSocketManager routes by type → registered handlers
   └── Handler for 'dm_received' → ChatWidgetManager.#handleIncomingMessage()
       ↓
8. Message appears in chat UI
```

### Notification Flow (Bell Icon)

```
1. Backend event (friend request, DM, etc.)
   ↓
2. NotificationService::create()
   ├── INSERT into notifications table
   └── pushNotification($userUuid, $notificationData)
       ↓
3. WebSocketPublisher::publishToUser($userUuid, 'notification', $data)
   ↓
4. [Same Redis → Swoole → Client flow as DM]
   ↓
5. Frontend: handleMessage() → case 'notification'
   └── navbar-auth.php: handleNewNotification()
       └── Updates badge count, shows toast
```

---

## Message Types

### Server → Client Messages

| Type | Trigger | Frontend Handler |
|------|---------|------------------|
| `auth_success` | JWT validated | WebSocketManager.handleAuthSuccess |
| `notification` | New notification | navbar-auth.php.handleNewNotification |
| `dm_received` | DM message received | ChatWidgetManager.#handleIncomingMessage |
| `dm_sent` | DM confirmed (sender) | ChatManager.#handleDMSent |
| `dm_read_receipt` | Message read by recipient | ChatManager.#handleReadReceipt |
| `room_message` | Chat room message | ChatManager.#handleRoomMessage |
| `room_joined` | User joined room | ChatManager.#handleRoomJoined |
| `user_joined` | Another user joined | ChatManager.#handleUserJoined |
| `user_left` | User left room | ChatManager.#handleUserLeft |
| `typing_indicator` | Typing started/stopped | ChatManager.#handleTypingIndicator |
| `presence_update` | Online/offline status | ChatManager.#handlePresenceUpdate |
| `emotion_counter_update` | Emotion room count changed | ChatManager.#handleEmotionCounterUpdate |
| `user_room_counter_update` | User room count changed | ChatManager.#handleUserRoomCounterUpdate |
| `friend_presence_changed` | Friend online/offline | FriendsWidget.updateFriendPresence |
| `friend_request_accepted` | Friend added | FriendsWidget (reloads) |

### Client → Server Messages

| Type | Purpose |
|------|---------|
| `authenticate` | Send JWT for validation |
| `join_room` | Join emotion/user room |
| `leave_room` | Leave current room |
| `room_message` | Send message to room |
| `dm_message` | Send direct message |
| `typing_start` | Start typing indicator |
| `typing_stop` | Stop typing indicator |
| `dm_read` | Mark DM as read |
| `subscribe_posts` | Subscribe to post counters |
| `pong` | Heartbeat response |

---

## Channel Patterns

### Redis PubSub Channels

| Pattern | Example | Target |
|---------|---------|--------|
| `websocket:events:{uuid}` | `websocket:events:abc-123-def` | Single user (all devices) |
| `websocket:events:room:{roomId}` | `websocket:events:room:emotion:joy` | All users in room |
| `websocket:events:conv:{convUuid}` | `websocket:events:conv:conv-456` | Conversation participants |
| `websocket:events:global` | `websocket:events:global` | All connected users |
| `websocket:events:post:{postId}` | `websocket:events:post:123` | Users viewing post |

### Message Transformation (Redis → Client)

```
Redis PUBLISH:
{
    "channel": "user:abc-123",
    "event": "notification",
    "data": { "id": 1, "type": "dm_received", ... },
    "timestamp": 1733300000.123
}
         ↓
Client receives:
{
    "type": "notification",
    "payload": { "id": 1, "type": "dm_received", ... },
    "timestamp": 1733300000
}
```

**CRITICAL**: Frontend expects `payload`, NOT `data`!

---

## File Structure

### Backend (PHP)

```
scripts/
├── websocket-server.php      # Main Swoole server (1500+ lines)
├── websocket-bootstrap.php   # Minimal bootstrap (Redis pools, JWT)
└── websocket-health-check.php

app/Services/
├── WebSocketPublisher.php    # Redis PUBLISH helper (publish, publishToUser, publishToRoom)
├── NotificationService.php   # Creates notifications + pushes via WebSocket
├── Chat/
│   ├── DirectMessageService.php  # DM business logic
│   ├── EmotionRoomService.php    # Emotion room management
│   ├── ChatRoomService.php       # User room management
│   └── PresenceService.php       # Online/offline tracking
```

### Frontend (JavaScript)

```
public/assets/js/
├── websocket-manager.js          # Global WebSocket singleton (OLD - window.WebSocketManager)
├── core/
│   └── websocket-manager.js      # Enterprise WebSocket (Need2Talk.WebSocketManager)
├── chat/
│   ├── ChatManager.js            # Chat orchestrator
│   ├── ChatWidgetManager.js      # Minimized chat widgets
│   ├── MessageList.js            # Message rendering
│   └── MessageInput.js           # Input handling
├── components/
│   └── FriendsWidget.js          # Sidebar friends + presence
```

---

## Debugging Guide

### Enable Debug Logging

```bash
# Server-side: Set in .env
WEBSOCKET_DEBUG=1

# Then restart WebSocket container:
docker compose restart websocket

# View logs:
docker logs need2talk_websocket -f
```

### Debug Points (ws_debug)

The `ws_debug()` function outputs to STDERR when `WEBSOCKET_DEBUG=1`:

1. **Server start**: `Server starting`
2. **Auth success**: `Client authenticated` with uuid/fd
3. **Room join**: `User joined room` with room_id
4. **Redis event**: `Redis PubSub received` (in task worker)
5. **pipeMessage**: `Room broadcast received by worker`
6. **Push success**: `Room broadcast (worker-local)` with sent count

### Common Issues

| Symptom | Likely Cause | Debug Step |
|---------|--------------|------------|
| Message never arrives | User not authenticated | Check `$authenticatedClients->count()` |
| Message arrives late | Redis PubSub delay | Check Redis MONITOR |
| Message arrives to wrong user | UUID mismatch | Log target_id in pipeMessage |
| Push fails silently | FD not on this worker | Check `fd % worker_num` |
| Bell not updating | Frontend handler wrong | Check browser console for 'notification' type |
| Chat requires refresh | `join_room` not sent | Check console for "[ChatManager] join_room sent" |
| "Invio..." stuck | User not in room | Verify `n2t:wsConnected` event received |

### Critical Timing Issues (Fixed V10.21-V10.22)

**Problem**: Real-time chat messages not appearing without page refresh.

**Root Cause**: Race condition between WebSocket connection and room join:
1. `WebSocketManager` loads with `defer` and connects immediately
2. Emits `n2t:wsConnected` DOM event on connection
3. `ChatManager` initializes later in `DOMContentLoaded`
4. May miss the `n2t:wsConnected` event if it fired before listener registered

**Solution** (V10.21-V10.22):
1. `WebSocketManager.notifyConnectionListeners()` now emits `n2t:wsConnected` CustomEvent
2. `ChatManager.joinRoom()` tracks if `join_room` was sent via WebSocket
3. After HTTP join completes, if WebSocket connected during request, sends `join_room` immediately
4. Handles all three timing scenarios:
   - User joined before WS connected
   - WS reconnected after disconnect
   - ChatManager initialized after WS already connected

### Log Locations

| Log | Path | Content |
|-----|------|---------|
| Swoole errors | `/var/www/need2talk/storage/logs/swoole_websocket.log` | SWOOLE_LOG_WARNING+ |
| PHP errors | `/var/www/need2talk/storage/logs/php_errors.log` | PHP-FPM errors |
| WebSocket debug | STDERR (docker logs) | ws_debug() output |
| Application | `/var/www/need2talk/storage/logs/debug_general-*.log` | Logger::info/debug |

---

## Related Documentation

- [Swoole Architecture](../swoole/README.md) - Swoole internals and configuration
- [Overlay System](../overlay/README.md) - Feed overlay real-time updates
- [Logging System](../logging-system.md) - PSR-3 logging channels
