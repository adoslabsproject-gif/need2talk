# need2talk.it

**Enterprise-grade social audio platform built with a custom PHP framework.**

A real-time social network focused on voice messages and emotional expression, featuring enterprise-level architecture: multi-level caching, WebSocket real-time communication, ML-powered security, and auto-scaling background workers.

## Architecture

- **Framework**: Custom MVC ("Lightning Framework") - not Laravel
- **Language**: PHP 8.3 + Swoole (WebSocket server)
- **Database**: PostgreSQL 16 (partitioned tables, JSONB, ENUMs)
- **Cache**: 3-layer (L1 Redis, L2 Memcached, L3 Redis persistent)
- **Queue**: Redis-backed async email/audio/notification workers
- **Real-time**: Swoole WebSocket server with in-memory Swoole Tables
- **Security**: WAF + ML threat detection engine + progressive rate limiting
- **Storage**: AWS S3 (audio), PostgreSQL (data), Redis (sessions/cache/queue)

## Infrastructure (Docker)

11+ containers orchestrated via `docker-compose.yml`:

| Container | Role |
|-----------|------|
| `nginx` | HTTP/2, HTTP/3 (QUIC), TLS 1.3, FastCGI cache |
| `php` | PHP-FPM 8.3, OPcache preload, 50 workers |
| `postgres` | PostgreSQL 16, 4GB shared_buffers, partitioned tables |
| `redis` | Master, 2GB, 16 databases (cache/sessions/queue/rate-limit) |
| `redis-replica` | Read replica with automatic failover |
| `redis-sentinel` | HA monitoring + automatic master promotion |
| `memcached` | L2 cache, 512MB, 10k connections |
| `worker` | Email queue processor (isolated, auto-healing) |
| `newsletter_worker` | Campaign email processor |
| `websocket` | Swoole WebSocket server, 65k connections |
| `audio_worker` | Audio processing (ffmpeg) + S3 upload |
| `cron_worker` | Scheduled task executor (DB-driven) |

## Key Features

### Audio Social Network
- 30-second voice messages (Opus codec, 48kbps)
- Real-time audio streaming via signed S3 URLs
- Emotional tagging (10 emotions: joy, sadness, anger, etc.)
- Audio comments and reactions

### Real-Time Communication
- WebSocket-based chat rooms (emotion-themed + user-created)
- E2E encrypted direct messages (TweetNaCl)
- Presence tracking (online/away/offline)
- Real-time notifications (friend requests, comments, reactions)
- Live counter updates (play count, comment count)

### Enterprise Security
- ML threat detection engine (behavioral analysis + pattern matching)
- Anti-vulnerability scanner (50+ static rules + ML boost)
- Progressive rate limiting (per-user + per-IP)
- Honeypot traps (fake credentials, admin paths)
- WAF with DDoS protection (kernel-level tuning)
- Dynamic admin URLs (time-based hash, 2FA required)

### Admin Panel
- User management (suspend, ban, bulk actions)
- ML Security dashboard (thresholds, retraining, IP unbanning)
- Newsletter campaigns (HTML editor, tracking, metrics)
- Cron job manager (enable/disable, manual trigger, history)
- Performance monitoring (response times, slow queries)
- Audit log (immutable admin action tracking)

## Quick Start

### Prerequisites
- Docker + Docker Compose v2
- 4+ CPU cores, 16GB+ RAM

### Deploy
```bash
# 1. Clone
git clone https://github.com/YOUR_USERNAME/need2talk.git
cd need2talk

# 2. Configure
cp .env.example .env
# Edit .env with your credentials (database, Redis, SendGrid, S3, etc.)

# 3. Create encryption master key
mkdir -p /etc/need2talk
openssl rand -hex 32 > /etc/need2talk/master.key
chmod 440 /etc/need2talk/master.key

# 4. Build and start
docker compose build
docker compose up -d

# 5. Import database (if migrating)
docker cp backup.dump need2talk_postgres:/tmp/
docker exec need2talk_postgres pg_restore -U need2talk -d need2talk /tmp/backup.dump
```

### Development Commands
```bash
composer serve          # PHP dev server on localhost:8000
composer fix            # Fix code style (PSR-12)
composer stan           # Static analysis (PHPStan level 3)
composer quality        # Run both fix-dry and stan
npm run build:css       # Production Tailwind CSS build
```

## Documentation

- [Admin Session Management](docs/admin-session-management.md)
- [Security System](docs/security-system.md)
- [Layer 4 DDoS Protection](docs/layer4-ddos-protection.md)
- [Telegram Notifications](docs/telegram-notifications.md)
- [WebSocket System](docs/websocket/README.md)
- [Swoole Architecture](docs/swoole/README.md)
- [Overlay System](docs/overlay/README.md)
- [Admin Panel & WebSocket & Chat](docs/ARCHITECTURE.md)

## Project Structure

```
app/
  Bootstrap/          # Enterprise bootstrap sequence
  Controllers/        # MVC controllers (Admin, Auth, Chat, API, etc.)
  Core/               # Framework core (Cache, Database, Metrics, Router)
  Middleware/          # Security headers, CSRF, rate limiting, WAF
  Models/             # Database models
  Services/           # Business logic (Email, Auth, Security, Chat, etc.)
  Views/              # PHP templates (Tailwind CSS)
config/               # Application configuration
database/
  init/               # Database initialization SQL
  migrations/         # PostgreSQL migrations
docker/               # Docker configs (nginx, php, redis, websocket)
public/               # Web root (index.php, assets)
routes/               # Route definitions (web, api, admin, internal)
scripts/              # Workers, crons, utilities
storage/              # Logs, cache, uploads (gitignored)
```

## License

All rights reserved.

## Credits

Built with AI-assisted development (Claude Code) orchestrated by a human with zero programming background. Proof that AI + human collaboration can produce enterprise-grade software.
