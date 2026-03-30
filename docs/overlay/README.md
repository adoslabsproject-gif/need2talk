# Overlay System - Enterprise Documentation

**Project**: need2talk.it Real-Time Feed Overlays
**Version**: 1.0.0 (Enterprise Galaxy)
**Last Updated**: 2025-12-04

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Overlay Types](#overlay-types)
4. [Data Flow](#data-flow)
5. [Frontend Components](#frontend-components)
6. [Backend Services](#backend-services)
7. [WebSocket Integration](#websocket-integration)
8. [Caching Strategy](#caching-strategy)

---

## Overview

The Overlay System provides real-time updates for feed counters and user interactions:

- **Play counters**: Real-time audio play count updates
- **Comment counters**: Live comment count on posts
- **Reaction counters**: Real-time reaction counts
- **Presence indicators**: Online/offline status
- **Typing indicators**: Real-time typing status

### Design Goals

1. **<100ms latency**: Counter updates appear instantly
2. **No polling**: All updates via WebSocket push
3. **Optimistic UI**: Show changes immediately, reconcile later
4. **Bandwidth efficient**: Only send deltas, not full data
5. **Scalable**: Handle 100k+ concurrent viewers

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         FEED PAGE (Browser)                             │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │                        Post Cards                                │   │
│  │  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐                │   │
│  │  │ Post #123   │ │ Post #456   │ │ Post #789   │                │   │
│  │  │ ▶ 47 plays  │ │ ▶ 123 plays │ │ ▶ 89 plays  │                │   │
│  │  │ 💬 5 comm   │ │ 💬 12 comm  │ │ 💬 3 comm   │                │   │
│  │  └─────────────┘ └─────────────┘ └─────────────┘                │   │
│  │         ↓                ↓               ↓                       │   │
│  │    data-post-id     Intersection Observer tracks visibility      │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                               ↓                                         │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ OverlayManager.js                                                │   │
│  │ - Tracks visible post IDs via IntersectionObserver              │   │
│  │ - Sends subscribe_posts to WebSocket every 2s (debounced)       │   │
│  │ - Handles post_counter_update events                            │   │
│  │ - Updates DOM counters in real-time                             │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                               ↓ subscribe_posts                         │
├─────────────────────────────────────────────────────────────────────────┤
│                      SWOOLE WebSocket Server                            │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ handlePostSubscription()                                         │   │
│  │ - Updates $fdToPostSubscriptions (Swoole\Table)                 │   │
│  │ - Maintains Redis SET ws:post_viewers:{postId}                  │   │
│  │ - TTL: 5 minutes (auto-expire stale subscriptions)              │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                               ↑                                         │
├─────────────────────────────────────────────────────────────────────────┤
│                          Redis (DB 5)                                   │
│  ws:post_viewers:123 → [fd1, fd2, fd3, ...]  TTL: 300s                │
│  ws:post_viewers:456 → [fd4, fd5, ...]       TTL: 300s                │
├─────────────────────────────────────────────────────────────────────────┤
│                    PHP Application (Counter Update)                     │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ When play/comment/reaction occurs:                               │   │
│  │ 1. Update PostgreSQL counter                                     │   │
│  │ 2. WebSocketPublisher::publishToPost($postId, 'counter_update') │   │
│  │    → Redis PUBLISH websocket:events:post:{postId}               │   │
│  └─────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Overlay Types

### 1. Play Counter

Tracks audio play count per post.

```javascript
// WebSocket message
{
    "type": "post_counter_update",
    "payload": {
        "post_id": 123,
        "counter_type": "plays",
        "value": 48,
        "delta": 1
    },
    "timestamp": 1733300000
}
```

### 2. Comment Counter

Tracks comment count per post.

```javascript
// WebSocket message
{
    "type": "post_counter_update",
    "payload": {
        "post_id": 123,
        "counter_type": "comments",
        "value": 6,
        "delta": 1
    },
    "timestamp": 1733300000
}
```

### 3. Reaction Counter

Tracks reaction counts per post.

```javascript
// WebSocket message
{
    "type": "post_counter_update",
    "payload": {
        "post_id": 123,
        "counter_type": "reactions",
        "reaction_type": "love",
        "value": 15,
        "delta": 1
    },
    "timestamp": 1733300000
}
```

### 4. Emotion Room Counter

Tracks online users per emotion room.

```javascript
// WebSocket message
{
    "type": "emotion_counter_update",
    "payload": {
        "room_id": "emotion:joy",
        "online_count": 42
    },
    "timestamp": 1733300000
}
```

---

## Data Flow

### Play Event Flow

```
1. User plays audio on Post #123
   ↓
2. Frontend: fetch('/api/audio/{postId}/play', {method:'POST'})
   ↓
3. AudioController::recordPlay()
   ├── UPDATE posts SET play_count = play_count + 1 WHERE id = 123
   └── WebSocketPublisher::publishToPost(123, 'counter_update', [
           'counter_type' => 'plays',
           'value' => $newCount,
           'delta' => 1
       ])
   ↓
4. Redis PUBLISH websocket:events:post:123
   ↓
5. Task Worker (psubscribe)
   └── Broadcasts to all 4 workers via pipeMessage
   ↓
6. broadcastToPostViewers(123, $message)
   ├── $redis->smembers('ws:post_viewers:123') → [fd1, fd2, fd3]
   └── For each FD owned by this worker: $server->push($fd, $message)
   ↓
7. Frontend: OverlayManager.handleCounterUpdate()
   └── Updates DOM: document.querySelector('[data-post-id="123"] .play-count')
```

### Subscription Flow

```
1. User scrolls feed, posts #123, #456, #789 become visible
   ↓
2. IntersectionObserver callback fires
   ├── Adds IDs to visiblePosts Set
   └── Debounced (2s): sendSubscriptionUpdate()
   ↓
3. WebSocket send: { type: 'subscribe_posts', post_ids: [123, 456, 789] }
   ↓
4. handlePostSubscription(server, fd, userUuid, [123, 456, 789])
   ├── Calculate diff: toAdd = [789], toRemove = []
   ├── Redis SADD ws:post_viewers:789 → fd
   ├── Redis EXPIRE ws:post_viewers:789 300
   └── Update $fdToPostSubscriptions
   ↓
5. User now receives updates for posts #123, #456, #789
```

---

## Frontend Components

### OverlayManager.js

Main orchestrator for overlay updates.

```javascript
class OverlayManager {
    constructor() {
        this.visiblePosts = new Set();
        this.observer = new IntersectionObserver(this.handleIntersection.bind(this), {
            rootMargin: '100px',  // Pre-subscribe 100px before visible
            threshold: 0.1
        });

        this.setupWebSocket();
        this.observeAllPosts();
    }

    handleIntersection(entries) {
        entries.forEach(entry => {
            const postId = entry.target.dataset.postId;
            if (entry.isIntersecting) {
                this.visiblePosts.add(postId);
            } else {
                this.visiblePosts.delete(postId);
            }
        });

        this.debouncedSubscriptionUpdate();
    }

    sendSubscriptionUpdate() {
        if (window.WebSocketManager?.isConnected) {
            window.WebSocketManager.send({
                type: 'subscribe_posts',
                post_ids: Array.from(this.visiblePosts)
            });
        }
    }

    handleCounterUpdate(data) {
        const { post_id, counter_type, value, delta } = data.payload;

        const selector = `[data-post-id="${post_id}"] .${counter_type}-count`;
        const element = document.querySelector(selector);

        if (element) {
            // Animate the update
            element.classList.add('counter-updating');
            element.textContent = this.formatCount(value);

            setTimeout(() => {
                element.classList.remove('counter-updating');
            }, 300);
        }
    }
}
```

### CSS Animation

```css
.counter-updating {
    animation: counter-pulse 0.3s ease-out;
}

@keyframes counter-pulse {
    0% { transform: scale(1); color: inherit; }
    50% { transform: scale(1.2); color: #a855f7; }
    100% { transform: scale(1); color: inherit; }
}
```

---

## Backend Services

### WebSocketPublisher::publishToPost()

```php
public static function publishToPost(int $postId, string $event, array $data = []): bool
{
    return self::publish("post:{$postId}", $event, $data);
}

// Usage
WebSocketPublisher::publishToPost(123, 'counter_update', [
    'counter_type' => 'plays',
    'value' => 48,
    'delta' => 1
]);
```

### Post Counter Update (in controller)

```php
// AudioController.php
public function recordPlay(int $postId): JsonResponse
{
    // Increment in database
    $db = db();
    $db->execute(
        "UPDATE posts SET play_count = play_count + 1 WHERE id = :id",
        ['id' => $postId],
        ['invalidate_cache' => ['table:posts']]
    );

    // Get new count
    $newCount = $db->findOne(
        "SELECT play_count FROM posts WHERE id = :id",
        ['id' => $postId]
    )['play_count'];

    // Push to viewers
    WebSocketPublisher::publishToPost($postId, 'counter_update', [
        'counter_type' => 'plays',
        'value' => $newCount,
        'delta' => 1
    ]);

    return json(['success' => true]);
}
```

---

## WebSocket Integration

### Server-side Handling (websocket-server.php)

```php
// In on('message') handler
case 'subscribe_posts':
    $postIds = $data['post_ids'] ?? [];
    if (!is_array($postIds)) {
        $postIds = [];
    }
    // Limit to 50 posts max
    $postIds = array_slice($postIds, 0, 50);
    handlePostSubscription($server, $fd, $uuid, $postIds);
    break;
```

### Redis Data Structures

```
# Per-post viewer set (Redis DB 5)
KEY: ws:post_viewers:123
TYPE: SET
VALUES: {12, 45, 78, 91}  # FDs currently viewing post #123
TTL: 300 seconds

# Per-FD subscription list (Swoole\Table)
$fdToPostSubscriptions[fd] = {
    'post_ids': '[123, 456, 789]',  # JSON array
    'updated_at': 1733300000
}
```

---

## Caching Strategy

### Counter Caching (Multi-Level)

```php
// L1 (Enterprise Redis): 30s TTL - hot counters
// L2 (Memcached): 5min TTL - warm counters
// L3 (Redis): 1hr TTL - cold counters
// PostgreSQL: Source of truth

// Read path (optimized)
$counter = cache_get("post:counter:{$postId}:{$type}");
if ($counter === null) {
    $counter = $db->findOne("SELECT {$type}_count FROM posts WHERE id = ?", [$postId]);
    cache_set("post:counter:{$postId}:{$type}", $counter, 30);  // L1 only
}

// Write path (write-through)
$db->execute("UPDATE posts SET {$type}_count = {$type}_count + 1 WHERE id = ?", [$postId]);
cache_invalidate("post:counter:{$postId}:*");  // Invalidate all layers
```

### WebSocket Subscription TTL

```php
// 5-minute TTL prevents stale subscriptions
// Refresh on every subscribe_posts message
$redis->expire("ws:post_viewers:{$postId}", 300);

// Cleanup on disconnect
function cleanupPostSubscriptions(int $fd): void
{
    $postIds = getPostSubscriptions($fd);
    foreach ($postIds as $postId) {
        $redis->srem("ws:post_viewers:{$postId}", $fd);
    }
}
```

---

## Performance Considerations

### Scalability Limits

| Metric | Current | Max Recommended |
|--------|---------|-----------------|
| Posts per subscription | 50 | 100 |
| Subscription updates/s | 0.5 (debounced) | 1 |
| Counter updates/post/s | ~10 | 100 |
| Viewers per post | 10,000 | 50,000 |

### Optimization Opportunities

1. **Batch counter updates**: Aggregate multiple plays into single update
2. **Eventual consistency**: Allow 1-2s delay for non-critical counters
3. **Fan-out limiting**: If post has 50k+ viewers, sample or throttle
4. **CDN edge caching**: Cache counter values at edge for read-heavy posts

---

## Related Documentation

- [WebSocket System](../websocket/README.md) - Core WebSocket architecture
- [Swoole Architecture](../swoole/README.md) - Server process model
- [Caching System](../../config/app.php) - Multi-level cache config
