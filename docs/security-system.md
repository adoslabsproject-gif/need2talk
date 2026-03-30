# Enterprise Security System Documentation

## Overview

Need2Talk implements a multi-layer security architecture that provides enterprise-grade protection against various attack vectors. This document describes all security mechanisms, how they work, and how to manage them.

---

## Table of Contents

1. [Security Architecture](#security-architecture)
2. [IP Ban System](#ip-ban-system)
3. [Rate Limiting](#rate-limiting)
4. [Anti-Scanning Protection](#anti-scanning-protection)
5. [ML Threat Detection](#ml-threat-detection)
6. [Trusted Proxy Validation](#trusted-proxy-validation)
7. [DDoS Protection](#ddos-protection)
8. [How to Unban IPs](#how-to-unban-ips)
9. [Security Logs](#security-logs)
10. [Attack Simulation Scripts](#attack-simulation-scripts)

---

## Security Architecture

```
                    ┌─────────────────────────────────────────────────────────┐
                    │                   NGINX LAYER                           │
                    │  • Rate Limiting (auth, api, websocket, general)        │
                    │  • Path Blocking (.env, .git, wp-admin, etc.)           │
                    │  • Scanner UA Detection (Nikto, SQLMap, WPScan)         │
                    │  • TLS 1.2/1.3 Termination                              │
                    └─────────────────────────────────────────────────────────┘
                                              │
                                              ▼
                    ┌─────────────────────────────────────────────────────────┐
                    │                 EARLY BLOCK (index.php)                  │
                    │  • Redis DB 3 ban check BEFORE bootstrap                │
                    │  • Whitelist bypass for admin IPs                       │
                    │  • Zero session overhead for banned IPs                 │
                    └─────────────────────────────────────────────────────────┘
                                              │
                                              ▼
                    ┌─────────────────────────────────────────────────────────┐
                    │               PHP APPLICATION LAYER                      │
                    │  • AntiScanningMiddleware (404 tracking, score system)  │
                    │  • TrustedProxyValidator (IP spoofing prevention)       │
                    │  • DDoSProtection (global rate limits)                  │
                    │  • EnterpriseRedisRateLimitManager (per-action limits)  │
                    │  • ML Threat Detection (AdvancedMLThreatEngine)         │
                    │  • CSRF Protection                                       │
                    │  • SecurityShield (request validation)                  │
                    └─────────────────────────────────────────────────────────┘
                                              │
                                              ▼
                    ┌─────────────────────────────────────────────────────────┐
                    │                    REDIS DB LAYOUT                       │
                    │  • DB 0: Cache (L3)                                      │
                    │  • DB 1: Sessions + L1 Cache                            │
                    │  • DB 2: Queue                                           │
                    │  • DB 3: Rate Limiting + IP Bans (anti_scan:banned:*)   │
                    └─────────────────────────────────────────────────────────┘
```

---

## IP Ban System

### How IPs Get Banned

IPs are automatically banned when they trigger security thresholds:

| Trigger | Score Added | Threshold | Ban Duration |
|---------|-------------|-----------|--------------|
| Multiple 404s (5+) | +5 per 404 | 50 | 24 hours |
| High 404 rate (10+) | +10 per 404 | 50 | 24 hours |
| CSRF failures | +15 per failure | 50 | 24 hours |
| Vulnerability scan paths | +20 | 50 | 24 hours |
| Scanner User-Agent | +30 | 50 | 24 hours |

### Where Bans Are Stored

**Primary Storage (Redis DB 3):**
```
anti_scan:banned:{IP_ADDRESS}
```
- TTL: 86400 seconds (24 hours)
- Checked by Early Block in `public/index.php`

**Secondary Storage (PostgreSQL):**
```sql
vulnerability_scan_bans (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(45),
    banned_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    paths_accessed JSONB,
    user_agents TEXT[],
    total_score INTEGER
)
```

### Early Block System

The Early Block system in `public/index.php` checks banned IPs **before** loading the application:

```php
// Redis DB 3 is used for rate limiting/bans
$earlyRedis->select(3);

// Check if IP is whitelisted first
$whitelistKey = "ip_whitelist:active:{$clientIP}";
if ($earlyRedis->exists($whitelistKey)) {
    // Whitelisted - continue
} else {
    // Check ban
    $banKey = "anti_scan:banned:{$clientIP}";
    if ($earlyRedis->exists($banKey)) {
        http_response_code(403);
        header('X-Block-Reason: IP_BANNED_EARLY');
        exit;
    }
}
```

**Benefits:**
- Zero session creation for banned IPs
- No application bootstrap overhead
- Minimal resource usage

---

## Rate Limiting

### Nginx Layer Rate Limits

Defined in `docker/nginx/nginx.conf`:

| Zone | Rate | Burst | Purpose |
|------|------|-------|---------|
| `auth` | 5 req/min | 2 | Login, register, password reset |
| `api_read` | 600 req/min | 50 | Feed, profile, GET endpoints |
| `api_write` | 120 req/min | 10 | POST, PUT, DELETE endpoints |
| `upload` | 1 req/sec | 2 | Audio/avatar uploads |
| `websocket` | 10 req/min | 5 | WebSocket connections |
| `general` | 1000 req/min | 100 | HTML pages |

### PHP Application Rate Limits

Defined in `app/Services/EnterpriseRedisRateLimitManager.php`:

```php
'audio_upload' => [
    'user' => ['window' => 86400, 'max_attempts' => 10, 'block_duration' => 3600],
    'ip' => ['window' => 86400, 'max_attempts' => 30, 'block_duration' => 3600],
],
'avatar_upload' => [
    'user' => ['window' => 3600, 'max_attempts' => 3, 'block_duration' => 7200],
    'ip' => ['window' => 3600, 'max_attempts' => 10, 'block_duration' => 7200],
],
'email_verify_token' => [
    'email' => ['window' => 3600, 'max_attempts' => 5, 'block_duration' => 86400],
    'ip' => ['window' => 3600, 'max_attempts' => 20, 'block_duration' => 14400],
],
'login' => [
    'requests' => 5,
    'window' => 300,  // 5 minutes
],
'register' => [
    'requests' => 3,
    'window' => 3600,  // 1 hour
],
```

---

## Anti-Scanning Protection

### AntiScanningMiddleware

Located: `app/Middleware/AntiScanningMiddleware.php`

**Tracked Behaviors:**
- 404 error accumulation
- Suspicious paths (/.env, /.git, /wp-admin, etc.)
- Scanner User-Agents
- CSRF failures
- Rapid request patterns

**Scoring System:**
```php
// Score thresholds
const BAN_THRESHOLD = 50;
const WARNING_THRESHOLD = 30;

// Score additions
'404_error' => 5,
'high_404_rate' => 10,
'suspicious_path' => 20,
'scanner_ua' => 30,
'csrf_failure' => 15,
```

### Blocked Paths (Nginx)

Defined in `docker/nginx/conf.d/need2talk.conf`:

```nginx
# Block sensitive files
location ~ /\.(env|git|svn|htaccess|htpasswd) { deny all; }
location ~ /\.(sql|bak|old|orig|tmp)$ { deny all; }
location ~ /(wp-admin|wp-login|wp-config|xmlrpc) { deny all; }
location ~ /(phpmyadmin|adminer|mysql|pma) { deny all; }
location ~ /\.(aws|config|credentials) { deny all; }
```

---

## ML Threat Detection

### AdvancedMLThreatEngine

Located: `app/Services/Security/AdvancedMLThreatEngine.php`

**Features:**
- Naive Bayes classifier with 25 features
- Real-time threat scoring
- Automatic model training from historical data
- Persistent model storage in Redis

**Feature Extraction:**
```php
- Request frequency (per minute/hour)
- 404 error rate
- Unique paths accessed
- Time patterns (hour, day, weekend)
- User-Agent analysis
- Path depth and special characters
- POST data analysis
- Geographic anomalies
```

**Training:**
```bash
# Run initial training
php /var/www/need2talk/scripts/ml-initial-training.php

# Training sources:
# 1. Database: vulnerability_scan_bans, security_events
# 2. Log files: storage/logs/security-*.log
```

---

## Trusted Proxy Validation

### TrustedProxyValidator

Located: `app/Services/Security/TrustedProxyValidator.php`

**Purpose:** Prevents IP spoofing via X-Forwarded-For header injection.

**Trusted Networks:**
```php
// Only these networks can set proxy headers
private const TRUSTED_RANGES = [
    // Docker internal
    '172.16.0.0/12',
    '10.0.0.0/8',
    '192.168.0.0/16',
    // Localhost
    '127.0.0.0/8',
    '::1',
    // Cloudflare
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    // ... (full list in source)
];
```

**How It Works:**
1. Check if `REMOTE_ADDR` is from trusted proxy
2. If trusted → parse `X-Forwarded-For` header
3. If NOT trusted → use `REMOTE_ADDR` directly (cannot be spoofed)

---

## DDoS Protection

### DDoSProtection Service

Located: `app/Services/Security/DDoSProtection.php`

**Global Limits:**
```php
private const GLOBAL_LIMITS = [
    'requests_per_second' => 500,
    'requests_per_minute' => 20000,
    'spike_threshold' => 3.0,  // 3x normal = alarm
    'spike_window' => 10,      // seconds
];
```

**Endpoint-Specific Limits:**
```php
private const ENDPOINT_LIMITS = [
    '/auth/login' => ['per_second' => 20, 'per_minute' => 300],
    '/auth/register' => ['per_second' => 10, 'per_minute' => 100],
    '/api/audio/upload' => ['per_second' => 5, 'per_minute' => 60],
];
```

**Progressive Throttling Levels:**
1. `normal` - No throttling
2. `elevated` - 10% request rejection
3. `high` - 25% request rejection
4. `severe` - 50% request rejection
5. `critical` - 75% request rejection
6. `emergency` - 90% request rejection

---

## How to Unban IPs

### Quick Unban Commands

**1. Unban from Redis (Primary - Required):**
```bash
# SSH to server
ssh root@YOUR_SERVER_IP

# Unban specific IP from Redis DB 3
docker exec need2talk_redis_master redis-cli \
  -a $(grep REDIS_PASSWORD /var/www/need2talk/.env | cut -d= -f2) \
  -n 3 \
  DEL "anti_scan:banned:IP_ADDRESS"

# Example:
docker exec need2talk_redis_master redis-cli \
  -a $(grep REDIS_PASSWORD /var/www/need2talk/.env | cut -d= -f2) \
  -n 3 \
  DEL "anti_scan:banned:93.71.164.36"
```

**2. Unban from Database (Secondary - Recommended):**
```bash
docker exec need2talk_postgres psql -U need2talk -d need2talk \
  -c "DELETE FROM vulnerability_scan_bans WHERE ip_address = 'IP_ADDRESS';"

# Example:
docker exec need2talk_postgres psql -U need2talk -d need2talk \
  -c "DELETE FROM vulnerability_scan_bans WHERE ip_address = '93.71.164.36';"
```

**3. Clear Rate Limit Counters (Optional):**
```bash
# Clear 404 counter for IP
docker exec need2talk_redis_master redis-cli \
  -a $(grep REDIS_PASSWORD /var/www/need2talk/.env | cut -d= -f2) \
  -n 3 \
  DEL "anti_scan:404_count:IP_ADDRESS"

# Clear score for IP
docker exec need2talk_redis_master redis-cli \
  -a $(grep REDIS_PASSWORD /var/www/need2talk/.env | cut -d= -f2) \
  -n 3 \
  DEL "anti_scan:score:IP_ADDRESS"
```

### List All Banned IPs

**From Redis:**
```bash
docker exec need2talk_redis_master redis-cli \
  -a $(grep REDIS_PASSWORD /var/www/need2talk/.env | cut -d= -f2) \
  -n 3 \
  KEYS "anti_scan:banned:*"
```

**From Database:**
```bash
docker exec need2talk_postgres psql -U need2talk -d need2talk \
  -c "SELECT ip_address, banned_at, expires_at FROM vulnerability_scan_bans ORDER BY banned_at DESC;"
```

### Whitelist an IP

To permanently whitelist an IP (e.g., admin IP):
```bash
docker exec need2talk_redis_master redis-cli \
  -a $(grep REDIS_PASSWORD /var/www/need2talk/.env | cut -d= -f2) \
  -n 3 \
  SET "ip_whitelist:active:IP_ADDRESS" "admin" EX 31536000  # 1 year
```

### Unban Script (One-Liner)

```bash
# Complete unban - Redis + Database
IP="93.71.164.36" && \
ssh root@YOUR_SERVER_IP "
  docker exec need2talk_redis_master redis-cli -a \$(grep REDIS_PASSWORD /var/www/need2talk/.env | cut -d= -f2) -n 3 DEL 'anti_scan:banned:$IP' && \
  docker exec need2talk_postgres psql -U need2talk -d need2talk -c \"DELETE FROM vulnerability_scan_bans WHERE ip_address = '$IP';\" && \
  echo 'IP $IP unbanned successfully'
"
```

---

## Security Logs

### Log Locations

| Log File | Purpose | Location |
|----------|---------|----------|
| `security-YYYY-MM-DD.log` | Security events | `storage/logs/` |
| `need2talk_ssl_access.log` | HTTPS traffic | `/var/log/nginx/` |
| `need2talk_ssl_error.log` | HTTPS errors | `/var/log/nginx/` |
| `blocked_domains.log` | WAF blocks | `/var/log/nginx/` |

### Reading Security Logs

```bash
# Recent security events
ssh root@YOUR_SERVER_IP 'docker exec need2talk_php tail -100 /var/www/html/storage/logs/security-$(date +%Y-%m-%d).log'

# Search for specific IP
ssh root@YOUR_SERVER_IP 'docker exec need2talk_php grep "93.71.164.36" /var/www/html/storage/logs/security-$(date +%Y-%m-%d).log'

# Ban events only
ssh root@YOUR_SERVER_IP 'docker exec need2talk_php grep "CRITICAL.*banned" /var/www/html/storage/logs/security-$(date +%Y-%m-%d).log'
```

### Log Format

```
[2026-02-01 21:10:18] SECURITY.CRITICAL: ANTI-SCAN: IP automatically banned for excessive 404 scanning {
    "ip": "93.71.164.36",
    "total_score": 55,
    "404_count": 12,
    "reasons": ["excessive_404_scanning"],
    "ban_duration": 86400,
    "threshold": 50
}
```

---

## Attack Simulation Scripts

### Location

Attack simulation scripts are stored **locally only** (NOT on server):
```
/var/www/need2talk/scripts/professional-attack-test.sh
/var/www/need2talk/scripts/security-attack-test.sh
```

### Running Tests

```bash
# Run from LOCAL Mac (NOT from server!)
/var/www/need2talk/scripts/professional-attack-test.sh
```

### What Tests Cover

1. **IP Spoofing** - X-Forwarded-For header injection
2. **Email Verification Brute Force** - Rapid token guessing
3. **WebSocket Flood** - Connection exhaustion
4. **HTTP Flood** - Parallel request attack
5. **Vulnerability Scanner Simulation** - Nikto/SQLMap patterns
6. **Login Brute Force** - Credential stuffing
7. **Path Traversal** - LFI/RFI attempts

### After Running Tests

**IMPORTANT:** Tests will likely ban your IP. To unban:
```bash
IP="YOUR_IP" && ssh root@YOUR_SERVER_IP "
  docker exec need2talk_redis_master redis-cli -a \$(grep REDIS_PASSWORD /var/www/need2talk/.env | cut -d= -f2) -n 3 DEL 'anti_scan:banned:$IP' && \
  docker exec need2talk_postgres psql -U need2talk -d need2talk -c \"DELETE FROM vulnerability_scan_bans WHERE ip_address = '$IP';\"
"
```

---

## Admin Panel

### ML Security Dashboard

Access: Admin Panel → ML Security & DDoS

Features:
- Real-time ML model status
- Banned IPs list with unban functionality
- DDoS protection status
- Threat level visualization
- Manual retraining trigger

### API Endpoints

```
GET  /admin/api/ml-security/status  - Get ML & security status
POST /admin/api/ml-security/config  - Update configuration
POST /admin/api/ml-security/retrain - Trigger ML retraining
POST /admin/api/ml-security/unban   - Unban specific IP
```

---

## Troubleshooting

### Site Returns 403 for Valid Users

1. Check if IP is banned:
   ```bash
   docker exec need2talk_redis_master redis-cli -a PASSWORD -n 3 EXISTS "anti_scan:banned:IP"
   ```

2. Check response headers for `X-Block-Reason: IP_BANNED_EARLY`

3. Unban using commands above

### Rate Limiting Too Aggressive

1. Check Nginx rate limit zones in `docker/nginx/nginx.conf`
2. Check PHP rate limits in `EnterpriseRedisRateLimitManager.php`
3. Adjust thresholds as needed

### ML Model Not Working

1. Check model status: `redis-cli -n 0 GET "ml:threat:model:status"`
2. Retrain: `php scripts/ml-initial-training.php`
3. Check training samples: minimum 100 required

---

## Version History

| Date | Version | Changes |
|------|---------|---------|
| 2026-02-01 | 1.0 | Initial documentation |
| 2026-02-01 | 1.1 | Added TrustedProxyValidator, DDoSProtection |
| 2026-02-01 | 1.2 | Added WebSocket rate limiting |
| 2026-02-01 | 1.3 | Added Email verification brute force protection |
