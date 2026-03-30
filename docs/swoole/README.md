# Swoole Server Architecture - Enterprise Documentation

**Project**: need2talk.it WebSocket Server
**Swoole Version**: 5.1+ (via Docker php:8.3-cli + pecl install swoole)
**Last Updated**: 2025-12-04

---

## Table of Contents

1. [Why Swoole](#why-swoole)
2. [Process Model](#process-model)
3. [Shared Memory (Swoole\Table)](#shared-memory-swooletable)
4. [Redis Connection Pooling](#redis-connection-pooling)
5. [Inter-Worker Communication](#inter-worker-communication)
6. [Configuration Reference](#configuration-reference)
7. [Performance Tuning](#performance-tuning)

---

## Why Swoole

### Comparison with Alternatives

| Feature | Swoole | Ratchet (ReactPHP) | Node.js |
|---------|--------|-------------------|---------|
| **Performance** | 100k+ conn/instance | ~10k conn/instance | ~50k conn/instance |
| **Memory** | ~20KB/conn | ~100KB/conn | ~50KB/conn |
| **Latency** | <5ms | 10-50ms | 5-10ms |
| **Language** | PHP (C extension) | PHP (pure) | JavaScript |
| **Multi-process** | Built-in | Manual | cluster module |
| **Coroutines** | Native | Generator-based | async/await |
| **Codebase reuse** | ✅ Same PHP | ✅ Same PHP | ❌ New codebase |

### Decision: Swoole

For need2talk.it:
- **Same PHP codebase** as application (reuse JWT, Redis, validation)
- **10x performance** over Ratchet (C extension vs pure PHP)
- **Built-in multi-process** with shared memory
- **Native coroutines** for async I/O without callbacks
- **Production-proven** (used by Hyperf, Simps frameworks)

---

## Process Model

### Current Configuration

```
need2talk_websocket container
│
├── Master Process (PID 1)
│   └── Manages workers, signal handling
│
├── Worker 0 (PID varies)
│   ├── Handles FDs: 0, 4, 8, 12, 16...  (fd % 4 == 0)
│   ├── Own Redis connection pool (5 per DB × 5 DBs = 25 connections)
│   └── Coroutine-enabled (SWOOLE_HOOK_ALL)
│
├── Worker 1 (PID varies)
│   ├── Handles FDs: 1, 5, 9, 13, 17...  (fd % 4 == 1)
│   └── [Same as Worker 0]
│
├── Worker 2 (PID varies)
│   ├── Handles FDs: 2, 6, 10, 14, 18... (fd % 4 == 2)
│   └── [Same as Worker 0]
│
├── Worker 3 (PID varies)
│   ├── Handles FDs: 3, 7, 11, 15, 19... (fd % 4 == 3)
│   └── [Same as Worker 0]
│
└── Task Worker (PID varies)
    ├── NOT coroutine-enabled (blocking OK)
    ├── Runs infinite Redis psubscribe loop
    └── Broadcasts to workers via pipeMessage
```

### FD Distribution (dispatch_mode=2)

```
fd=100 → worker_id = 100 % 4 = 0 → Worker 0
fd=101 → worker_id = 101 % 4 = 1 → Worker 1
fd=102 → worker_id = 102 % 4 = 2 → Worker 2
fd=103 → worker_id = 103 % 4 = 3 → Worker 3
fd=104 → worker_id = 104 % 4 = 0 → Worker 0
```

**CRITICAL**: Worker can ONLY push to FDs it owns! Pushing to fd=101 from Worker 0 fails silently.

---

## Shared Memory (Swoole\Table)

### Why Swoole\Table?

PHP arrays are **process-local memory**. With 4 workers:
- Worker 0 has its own `$userConnections` array
- Worker 1 has its own `$userConnections` array
- They do NOT share data!

**Swoole\Table** uses **shared memory** (mmap) visible to ALL workers.

### Current Tables

#### $authenticatedClients

Maps FD → User data (for routing messages to users)

```php
$authenticatedClients = new Swoole\Table(100000);
$authenticatedClients->column('uuid', Swoole\Table::TYPE_STRING, 64);
$authenticatedClients->column('nickname', Swoole\Table::TYPE_STRING, 100);
$authenticatedClients->column('avatar_url', Swoole\Table::TYPE_STRING, 255);
$authenticatedClients->column('auth_time', Swoole\Table::TYPE_INT);
$authenticatedClients->create();

// Set on authentication
$authenticatedClients->set((string)$fd, [
    'uuid' => $uuid,
    'nickname' => $nickname,
    'avatar_url' => $avatarUrl,
    'auth_time' => time(),
]);

// Lookup all FDs for a UUID (multi-device support)
foreach ($authenticatedClients as $fdStr => $clientData) {
    if ($clientData['uuid'] === $targetUuid) {
        $fds[] = (int)$fdStr;
    }
}
```

#### $fdToRoom

Maps FD → Current room (for room broadcasts)

```php
$fdToRoom = new Swoole\Table(100000);
$fdToRoom->column('room_id', Swoole\Table::TYPE_STRING, 64);
$fdToRoom->column('room_type', Swoole\Table::TYPE_STRING, 16);
$fdToRoom->column('joined_at', Swoole\Table::TYPE_INT);
$fdToRoom->create();

// Set on join_room
$fdToRoom->set((string)$fd, [
    'room_id' => 'emotion:joy',
    'room_type' => 'emotion',
    'joined_at' => time(),
]);

// Find all FDs in a room
foreach ($fdToRoom as $fdStr => $roomData) {
    if ($roomData['room_id'] === $targetRoomId) {
        $fds[] = (int)$fdStr;
    }
}
```

#### $fdToPostSubscriptions

Maps FD → Subscribed post IDs (for counter broadcasts)

```php
$fdToPostSubscriptions = new Swoole\Table(100000);
$fdToPostSubscriptions->column('post_ids', Swoole\Table::TYPE_STRING, 1024);
$fdToPostSubscriptions->column('updated_at', Swoole\Table::TYPE_INT);
$fdToPostSubscriptions->create();

// Set when client sends subscribe_posts
$fdToPostSubscriptions->set((string)$fd, [
    'post_ids' => json_encode([123, 456, 789]),
    'updated_at' => time(),
]);
```

### Table Size Calculation

```
100,000 rows × ~400 bytes/row ≈ 40MB shared memory

Per-row memory:
- uuid: 64 bytes
- nickname: 100 bytes
- avatar_url: 255 bytes
- auth_time: 8 bytes
- Swoole overhead: ~50 bytes
- Total: ~477 bytes → rounded to 512 bytes
```

---

## Redis Connection Pooling

### Problem Without Pooling

```php
// BAD: Creates new connection per call (slow, exhausts connections)
function getRedis() {
    $redis = new Redis();
    $redis->connect('redis', 6379);
    return $redis;
}
```

### Solution: Swoole\Coroutine\Channel Pool

```php
// websocket-bootstrap.php
const REDIS_POOL_SIZE_PER_DB = 5;
$redisPool = [];  // [workerId][db] => Channel

function swoole_redis_init_pool(int $workerId, int $db, int $size): void
{
    global $redisPool;

    // Create Channel (bounded queue) for this DB
    $channel = new Swoole\Coroutine\Channel($size);

    // Pre-create connections
    for ($i = 0; $i < $size; $i++) {
        $redis = createRedisConnection($db);
        $channel->push($redis);
    }

    $redisPool[$workerId][$db] = $channel;
}

function swoole_redis(int $db = 0): ?Redis
{
    global $redisPool;
    $workerId = Swoole\Coroutine::getCid() >= 0
        ? (Co::getContext()['worker_id'] ?? 0)
        : 0;

    $channel = $redisPool[$workerId][$db] ?? null;
    if (!$channel) return null;

    // Borrow connection (blocks if pool empty)
    return $channel->pop(3.0);  // 3s timeout
}

function swoole_redis_release(Redis $redis, int $db): void
{
    global $redisPool;
    $workerId = ...;

    // Return connection to pool
    $redisPool[$workerId][$db]->push($redis);
}
```

### Usage Pattern

```php
function handleSomething(): void
{
    $redis = swoole_redis(4);  // Borrow from pool
    if (!$redis) {
        ws_debug('Redis unavailable');
        return;
    }

    try {
        $redis->set('key', 'value');
        $redis->expire('key', 300);
    } finally {
        // CRITICAL: Always return to pool!
        swoole_redis_release($redis, 4);
    }
}
```

### DB Allocation

| DB | Purpose | Preloaded |
|----|---------|-----------|
| 0 | L1 Cache | ✅ |
| 1 | Sessions | ✅ |
| 4 | WebSocket PubSub | ✅ |
| 5 | Overlay/Posts | ✅ |
| 6 | Chat data | ✅ |

---

## Inter-Worker Communication

### Task Worker → Workers (pipeMessage)

```
Task Worker receives Redis event
    ↓
for (workerId = 0; workerId < 4; workerId++) {
    $server->sendMessage($json, $workerId);
}
    ↓
Each Worker receives via on('pipeMessage')
    ↓
Each Worker pushes ONLY to FDs it owns
```

### Code Flow

```php
// Task Worker (in psubscribe callback)
$redis->psubscribe(['websocket:events:*'], function ($redis, $pattern, $channel, $data) use ($server) {
    $event = json_decode($data, true);

    $message = json_encode([
        'type' => 'redis_event',
        'target_type' => 'user',
        'target_id' => $uuid,
        'event' => $event,
    ]);

    // Send to ALL workers
    for ($workerId = 0; $workerId < 4; $workerId++) {
        $server->sendMessage($message, $workerId);
    }
});

// Regular Worker (pipeMessage handler)
$server->on('pipeMessage', function ($server, $srcWorkerId, $message) {
    $data = json_decode($message, true);

    // Find FDs for target user
    foreach ($authenticatedClients as $fdStr => $clientData) {
        if ($clientData['uuid'] === $targetUuid) {
            $fd = (int)$fdStr;

            // CRITICAL: Only push to FDs owned by THIS worker
            if ($fd % 4 !== $server->worker_id) {
                continue;  // Not my FD, skip
            }

            $server->push($fd, $clientMessage);
        }
    }
});
```

---

## Configuration Reference

### Server Settings (websocket-server.php)

```php
$config = [
    // Workers
    'worker_num' => 4,              // Regular workers (CPU cores)
    'task_worker_num' => 1,         // Task workers (Redis subscriber)
    'task_enable_coroutine' => false, // Blocking OK in task worker

    // Connections
    'max_connection' => 65535,      // Max concurrent connections
    'max_coroutine' => 65535,       // Max coroutines per worker
    'enable_coroutine' => true,     // Enable coroutines in workers

    // Memory
    'package_max_length' => 2 * 1024 * 1024,  // 2MB max message
    'buffer_output_size' => 32 * 1024 * 1024, // 32MB output buffer

    // Heartbeat
    'heartbeat_check_interval' => 60,   // Check every 60s
    'heartbeat_idle_time' => 600,       // Close after 10min idle

    // Performance
    'open_tcp_nodelay' => true,         // Disable Nagle (low latency)
    'open_websocket_close_frame' => true,

    // Logging
    'log_file' => '/var/www/need2talk/storage/logs/swoole_websocket.log',
    'log_level' => SWOOLE_LOG_WARNING,  // WARNING+ in production
];
```

### Docker Settings (docker-compose.yml)

```yaml
websocket:
  build:
    context: .
    dockerfile: docker/swoole/Dockerfile
  container_name: need2talk_websocket
  ports:
    - "8090:8090"
  ulimits:
    nofile:
      soft: 65535
      hard: 65535
  environment:
    - WEBSOCKET_DEBUG=0
    - REDIS_HOST=redis
    - REDIS_PASSWORD=${REDIS_PASSWORD}
    - REDIS_DB=4
```

---

## Performance Tuning

### Scaling Guidelines

| Concurrent Users | Workers | Connections/Worker | Server Spec |
|-----------------|---------|-------------------|-------------|
| 10,000 | 4 | 2,500 | 4 CPU, 8GB RAM |
| 50,000 | 8 | 6,250 | 8 CPU, 16GB RAM |
| 100,000 | 16 | 6,250 | 16 CPU, 32GB RAM |

### Monitoring Commands

```bash
# View Swoole stats
docker exec need2talk_websocket cat /proc/$(pgrep -f websocket-server)/status

# Connection count
docker exec need2talk_websocket \
  php -r 'echo shell_exec("netstat -an | grep :8090 | wc -l");'

# Memory usage
docker stats need2talk_websocket --no-stream
```

### Common Bottlenecks

1. **Too many Swoole\Table iterations**: O(n) per message
   - Solution: Add UUID→FDs lookup table

2. **Redis pool exhaustion**: All connections borrowed
   - Solution: Increase REDIS_POOL_SIZE_PER_DB

3. **pipeMessage queue full**: Task worker sending faster than workers process
   - Solution: sendMessageWithBackpressure() with retry

---

## Related Documentation

- [WebSocket System](../websocket/README.md) - Message flows and handlers
- [Overlay System](../overlay/README.md) - Real-time feed updates
