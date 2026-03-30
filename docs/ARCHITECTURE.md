# need2talk.it - Architecture Documentation

## Table of Contents
1. [Admin Panel](#admin-panel)
2. [WebSocket Real-Time System](#websocket-real-time-system)
3. [Swoole Tables & Chat System](#swoole-tables--chat-system)

---

## Admin Panel

### Security Architecture

The admin panel uses a **dynamic URL system** combined with **2FA** for enterprise-grade protection.

**Admin URL Generation** (`AdminSecurityService`):
- Format: `/admin_[16-char-hex-hash]`
- Hash includes: environment, 60-minute time window, server boot time, process ID
- URLs stored in `admin_url_whitelist` table with 8-hour expiration
- Failed access attempts tracked (max 5/hour before permanent block)

**Authentication Flow (3-step)**:
1. Email + Password login → triggers 2FA code generation
2. 6-digit code sent via email → 5-minute expiration
3. JWT session token created → 60-minute session with activity-based extension

### Admin Controllers

| Controller | Purpose |
|-----------|---------|
| `AdminController` | Main dashboard, users, security, settings, terminal, moderators |
| `AdminMLSecurityController` | ML threat model status, config, retraining, IP unbanning |
| `AdminNewsletterController` | Campaign creation (TinyMCE), sending, analytics (opens/clicks/devices) |
| `AdminAudioWorkerController` | Audio worker monitoring, start/stop/scale |
| `AdminSecurityEventsController` | Real-time security event log (WAF, scanners, bots) |
| `AdminAuditLogController` | Immutable admin action history, CSV export |
| `AdminUsersAndRateLimitTabsController` | User bulk actions (suspend/ban/delete), rate limit bans |
| `AdminPerformanceController` | Response time charts, slow queries, memory trends |
| `AdminCronController` | Cron job management: list, toggle, manual trigger, health check |
| `AdminNewsletterWorkerApiController` | Newsletter worker Docker control (start/stop/restart/autostart) |
| `AdminEnterpriseMonitorController` | All worker monitoring, scaling (overlay/feed workers) |

### Key Admin Routes (`routes/admin_routes.php`)

```
GET  /admin_{hash}/dashboard          → Main dashboard with stats
GET  /admin_{hash}/users              → User management
GET  /admin_{hash}/security           → WAF + security events
GET  /admin_{hash}/ml-security        → ML threat model dashboard
GET  /admin_{hash}/audit              → Admin action audit log
GET  /admin_{hash}/newsletter         → Newsletter campaigns
GET  /admin_{hash}/performance        → Performance metrics
GET  /admin_{hash}/cron               → Cron job manager
POST /admin_{hash}/api/users/bulk     → Bulk user actions
POST /admin_{hash}/api/ml/retrain     → Retrain ML model
POST /admin_{hash}/api/ml/unban       → Unban IP address
POST /admin_{hash}/api/newsletter/send → Queue newsletter campaign
```

### Admin Panel Views

- **Dashboard**: Active users, new signups, verification status, security overview, worker status
- **Users**: Search/filter by email/uuid/status, bulk suspend/ban/delete/restore
- **Security**: WAF events (SQL injection, XSS, path traversal), bot whitelist, DDoS status
- **ML Security**: Model accuracy, training progress, threshold sliders, IP unban interface
- **Newsletter**: TinyMCE editor, user targeting, send progress, analytics (opens/clicks/devices)
- **Performance**: Response time chart by endpoint, slow query log, memory usage trends
- **Audit**: All admin actions with timestamps, affected resources, CSV export
- **Cron**: Job list with enable/disable toggle, manual trigger, execution history

---

## WebSocket Real-Time System

### Architecture

```
Client (Browser)
    │
    │ wss://domain.com/ws (TLS 1.3)
    ▼
Nginx (port 443)
    │
    │ proxy_pass http://websocket:8090
    ▼
Swoole WebSocket Server (port 8090, internal only)
    │
    ├── Swoole Tables (shared memory state)
    │     ├── authenticatedClients (fd → uuid, nickname, avatar)
    │     ├── fdToRoom (fd → room_id)
    │     ├── uuidFdIndex (uuid:fd → lookup)
    │     └── roomMeta (room_id → member count)
    │
    └── Redis PubSub (DB 4)
          ├── websocket:events:{uuid}        → personal notifications
          ├── websocket:events:conv:{uuid}   → DM messages
          ├── websocket:events:room:{uuid}   → chat room messages
          └── websocket:events:post:{id}     → live counter updates
```

**Container**: `need2talk_websocket` (Swoole 5.1+, 4 workers, 65k max connections)

**Security**: Port 8090 is NOT exposed to host — only reachable via Docker network through Nginx proxy. All traffic is TLS-encrypted.

### Authentication Flow

1. PHP-FPM renders page with JWT token in `window.currentUser.wsToken`
2. Client opens WebSocket: `new WebSocket('wss://domain.com/ws')`
3. Client sends: `{ type: 'authenticate', token: 'JWT_TOKEN' }`
4. Server verifies JWT (HS256, 24h expiration)
5. On success: client registered in `authenticatedClients` Swoole Table
6. UUID indexed in `uuidFdIndex` for O(1) lookup

### Channel System (PubSub)

| Channel | Subscribers | Events |
|---------|------------|--------|
| `user:{uuid}` | Single user (all devices) | Friend requests, notifications, badge updates |
| `conversation:{uuid}` | 2 DM participants | DM messages, read receipts, typing |
| `room:{uuid}` | Room members | Chat messages, member join/leave, typing |
| `post:{id}` | Users viewing post | Comment count, play count (live) |
| `global` | All connected | Maintenance, announcements |

**PubSub Flow**:
1. PHP-FPM calls `WebSocketPublisher::publish('user:{uuid}', 'event_name', $data)`
2. Redis `PUBLISH` on channel `websocket:events:{uuid}`
3. Swoole task worker receives via `psubscribe('websocket:events:*')`
4. Server broadcasts to matching connected clients

### Event Types

**Social**: `friend_request_received`, `friend_request_accepted`, `friend_removed`
**Chat**: `message_sent`, `member_joined`, `member_left`, `member_kicked`
**DM**: `dm_received`, `dm_read_receipt`, `dm_typing`, `dm_audio_received`
**Presence**: `presence_update`, `user_typing`, `user_stopped_typing`
**Counters**: `post_comment_count`, `post_play_count`, `room_member_count`

### Client Manager (`websocket-manager.js`)

```javascript
// Initialization (called on page load)
WebSocketManager.init({
    url: 'wss://domain.com/ws',
    autoReconnect: true,
    pingInterval: 25000
});

// Listen for events
WebSocketManager.on('friend_request_received', (data) => {
    showNotification(data.from_user.nickname);
});

// Send messages
WebSocketManager.send({
    type: 'room_message',
    room_id: 'uuid',
    text: 'Hello!'
});
```

**Reconnection**: Exponential backoff (1s → 2s → 4s → ... → 30s max), 10 attempts max
**Heartbeat**: PING every 25s, expect PONG within 5s, reconnect on timeout
**Message Queue**: Messages sent before connection is ready are buffered and sent on connect

---

## Swoole Tables & Chat System

### Swoole Tables (Shared Memory)

The WebSocket server uses **Swoole Tables** for zero-latency shared state between worker processes. No Redis/DB lookup needed for connection routing.

#### Table Definitions

```php
// authenticatedClients (100k rows) - Maps connection → user
Key: $fd (file descriptor)
├── uuid       STRING(36)
├── nickname   STRING(100)
├── avatar_url STRING(255)
└── auth_time  INT

// fdToRoom (100k rows) - Which room each connection is viewing
Key: $fd
├── room_id   STRING(64)   // UUID or 'emotion:{id}'
├── room_type STRING(16)   // 'emotion' or 'user'
└── joined_at INT

// fdToPostSubscriptions (100k rows) - Visible posts per connection
Key: $fd
├── post_ids   STRING(1024) // JSON array of post IDs
└── updated_at INT

// uuidFdIndex (150k rows) - O(1) "is user online?" lookup
Key: "{uuid}:{fd}"
├── uuid       STRING(36)
├── fd         INT
└── created_at INT

// uuidMeta (50k rows) - Fast device count per user
Key: $uuid
├── fd_count   INT    // Number of connected tabs/devices
├── first_fd   INT    // Single-device fast path
└── updated_at INT

// roomFdIndex (100k rows) - O(1) "who's in room?" lookup
Key: "{room_id}:{fd}"
├── room_id  STRING(64)
├── fd       INT
└── joined_at INT

// roomMeta (1k rows) - Room member count
Key: $room_id
├── fd_count   INT
└── updated_at INT
```

**Why Swoole Tables?** Regular PHP variables are not shared between Swoole worker processes. Swoole Tables provide lock-free, shared-memory data structures accessible from any worker — enabling O(1) lookups for "is user online?", "who's in this room?", "how many connections does this user have?".

### Database Schema

#### `chat_rooms`
```sql
id              SERIAL PRIMARY KEY
uuid            UUID UNIQUE
name            VARCHAR(100)
description     TEXT
room_type       ENUM('emotion', 'user_created', 'private')
status          ENUM('active', 'archived', 'deleted')
creator_id      INT REFERENCES users(id)
creator_uuid    UUID
max_members     INT DEFAULT 50
member_count    INT DEFAULT 0
emotion_id      INT REFERENCES emotions(id)  -- NULL for non-emotion rooms
is_ephemeral    BOOLEAN DEFAULT true          -- Messages in Redis only
last_activity_at TIMESTAMP
auto_close_at    TIMESTAMP
```

#### `direct_messages` (Partitioned by month)
```sql
uuid             UUID PRIMARY KEY
conversation_id  UUID REFERENCES direct_conversations(uuid)
sender_uuid      UUID
recipient_uuid   UUID
message_type     ENUM('text', 'audio', 'image', 'file')
content          TEXT              -- Encrypted E2E (TweetNaCl)
is_encrypted     BOOLEAN DEFAULT true
status           ENUM('sent', 'delivered', 'read', 'failed')
read_at          TIMESTAMP
created_at       TIMESTAMP        -- PARTITION KEY
deleted_at       TIMESTAMP        -- Soft delete
```

**Partitioning**: One partition per month (`direct_messages_2026_01`, `_2026_02`, etc.). Created automatically by `cron-chat-partition.php` on the 25th of each month. Enables query optimization via partition elimination and easy archival of old data.

### Chat Room Lifecycle

```
1. CREATE  → User creates room (POST /api/chat/rooms)
             └── Insert into chat_rooms + Redis metadata

2. JOIN    → User joins room (POST /api/chat/rooms/{uuid}/join)
             └── Insert chat_room_members
             └── WebSocket: subscribe to room channel
             └── Broadcast: member_joined event

3. MESSAGE → User sends message
             └── Moderation check (keyword blacklist)
             └── Store in Redis LIST (ephemeral rooms)
             └── Broadcast via WebSocket to all room members

4. LEAVE   → User leaves room
             └── Remove from chat_room_members
             └── Broadcast: member_left event

5. CLEANUP → Cron (every 5 minutes)
             └── Auto-close rooms with no activity for 4 hours
             └── Sync Redis online count with DB member_count
             └── Trim Redis message lists (keep last 100)
             └── Hard delete rooms archived >24 hours ago
```

### Direct Message Flow (E2E Encrypted)

```
1. SENDER encrypts message with recipient's ECDH public key (TweetNaCl box)
2. Encrypted blob sent via WebSocket: { type: 'dm', conversation_uuid, content }
3. Server stores encrypted content in PostgreSQL (partitioned direct_messages table)
4. Server publishes to Redis: websocket:events:conv:{conversation_uuid}
5. Recipient's WebSocket connection receives event
6. RECIPIENT decrypts with their ECDH private key (stored in browser IndexedDB)
```

**Key Exchange**: Each user generates an ECDH keypair on registration. Public key stored in `user_encryption_keys` table. Private key never leaves the browser.

### Redis Keys for Chat (DB 6)

```
chat:presence:{uuid}                    → Online status (TTL: 5 min)
chat:room:private:{roomUuid}:online     → SET of online FDs (TTL: 10 min)
chat:room:private:{roomUuid}:messages   → LIST of messages (TTL: 4 hours)
chat:room:private:{roomUuid}:meta       → HASH with room metadata
chat:typing:{roomUuid}:{userUuid}       → Typing indicator (TTL: 5 sec)
ws:post_viewers:{postId}                → SET of FDs viewing post (TTL: 5 min)
```

### Cleanup Crons

| Cron | Schedule | Purpose |
|------|----------|---------|
| `cron-chat-room-cleanup.php` | Every 5 min | Auto-close empty rooms, sync counts, trim messages |
| `cron-chat-partition.php` | 25th monthly | Create next month's DM partition |
| `cron-chat-archive.php` | 1st monthly | Archive messages >1 year, VACUUM tables |
| `cron-dm-cleanup.php` | Every 15 min | Delete expired DM messages (1h TTL) |

### Capacity

- **Per WebSocket container**: 15,000-20,000 concurrent users (4 Swoole workers)
- **Swoole Table memory**: ~30MB for 100k rows (300 bytes/row)
- **Message latency**: <50ms (Redis PubSub → client)
- **Auth latency**: <100ms (JWT verification)
- **Horizontal scaling**: `docker compose up -d --scale websocket=3` with Nginx upstream
