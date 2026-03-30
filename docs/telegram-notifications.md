# Telegram Notifications - Enterprise Galaxy Edition

## Overview

Il sistema di notifiche Telegram permette di ricevere alert in tempo reale su:
- Sicurezza (login sospetti, attacchi, admin URL)
- Errori critici (500, eccezioni non gestite)
- Performance (slow queries, high memory)
- Sistema (backup, deploy, cron)
- Report giornalieri (log, statistiche)

## Configurazione

### 1. Credenziali Bot (.env)

```bash
# Bot token da @BotFather
TELEGRAM_BOT_TOKEN=your_telegram_bot_token_here

# Il TUO chat ID (da @userinfobot)
TELEGRAM_ADMIN_CHAT_ID=your_chat_id_here
```

### 2. Bot Info

- **Username**: @need2talk_bot
- **Nome**: need2talk
- **Creato**: 2025-11-28

## Sicurezza

### Chi può leggere i messaggi?

**SOLO TU** (il proprietario del `TELEGRAM_ADMIN_CHAT_ID`).

Anche se altri utenti:
- Trovano il bot su Telegram
- Avviano una chat con `/start`
- Scrivono messaggi al bot

**NON riceveranno nulla** perché:
1. Il bot non ha handler per messaggi in arrivo
2. Invia SOLO quando il codice PHP lo chiama
3. Invia SOLO al chat_id configurato nel .env
4. Non esiste modo per altri di "iscriversi" alle notifiche

### Sicurezza del Token

- Il token è nel `.env` (non in git)
- Solo il server può leggere il token
- Anche con il token, serve il chat_id corretto per ricevere

## Utilizzo

### Servizio PHP

```php
use Need2Talk\Services\TelegramNotificationService;

// Verifica se configurato
if (TelegramNotificationService::isConfigured()) {
    // ...
}

// Messaggio admin generico
TelegramNotificationService::sendAdmin('Messaggio', ['context' => 'data']);

// Alert sicurezza (critical, warning, info, success)
TelegramNotificationService::sendSecurityAlert('critical', 'Brute force detected', [
    'ip' => '1.2.3.4',
    'attempts' => 50,
]);

// Errore critico
TelegramNotificationService::sendError('Database connection failed', [
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString(),
]);

// Alert sistema
TelegramNotificationService::sendSystemAlert('CPU Usage', '95%', '80%');

// Admin URL notification
TelegramNotificationService::sendAdminUrlNotification($newUrl, $executorInfo);

// Invio file (log, backup, report)
TelegramNotificationService::sendDocument('/path/to/file.log', 'Descrizione opzionale');

// Test connessione
TelegramNotificationService::test();
```

### Invio Messaggi Silenziosi

```php
// Il terzo parametro = silent (senza suono notifica)
TelegramNotificationService::sendAdmin('Messaggio', [], true);
```

## Eventi Consigliati

### Sicurezza (CRITICI - sempre attivi)

| Evento | Priorità | Descrizione |
|--------|----------|-------------|
| Admin URL generato | Alta | Nuovo URL admin con info esecutore |
| Brute force detected | Alta | >10 tentativi login falliti da stesso IP |
| WAF attack blocked | Alta | SQL injection, XSS, path traversal |
| Honeypot triggered | Alta | Accesso a endpoint trappola |
| Emergency access used | Critica | Codice emergenza 2FA usato |
| New admin login | Media | Login admin da nuovo IP |
| Rate limit exceeded | Media | Utente supera rate limit |

### Errori (CRITICI)

| Evento | Priorità | Descrizione |
|--------|----------|-------------|
| HTTP 500 | Alta | Errore server non gestito |
| Database down | Critica | Connessione DB fallita |
| Redis down | Alta | Cache non disponibile |
| Email queue failed | Media | Email non inviata dopo 3 retry |
| Exception uncaught | Alta | Eccezione non gestita |

### Performance (WARNING)

| Evento | Priorità | Descrizione |
|--------|----------|-------------|
| Slow query | Media | Query > 1 secondo |
| High memory | Alta | PHP > 80% memoria |
| High CPU | Alta | Container > 85% CPU |
| Disk space low | Critica | < 10% spazio disco |

### Sistema (INFO)

| Evento | Priorità | Descrizione |
|--------|----------|-------------|
| Deploy completed | Bassa | Nuovo deploy andato a buon fine |
| Backup completed | Bassa | Backup notturno completato |
| Cron job failed | Media | Cron fallito |
| SSL expiring | Alta | Certificato scade in < 7 giorni |

### Report Giornalieri (DIGEST)

| Report | Orario | Contenuto |
|--------|--------|-----------|
| Security digest | 08:00 | Login, rate limits, attacchi |
| Error digest | 08:00 | Errori ultime 24h |
| Stats digest | 08:00 | Utenti, post, visite |
| Log files | 03:00 | File log compressi |

## Cron Jobs

### Daily Log Report (03:00)

Invia i file log del giorno precedente come allegati:

```bash
# Aggiungere a crontab
0 3 * * * /var/www/need2talk/scripts/cron-telegram-daily-logs.sh
```

### Daily Digest (08:00)

Invia riepilogo statistiche e sicurezza:

```bash
0 8 * * * docker exec need2talk_php php /var/www/html/scripts/cron-telegram-daily-digest.php
```

## Limiti Telegram API

- **Messaggi**: 30/secondo per bot
- **File**: max 50MB per documento
- **Testo**: max 4096 caratteri per messaggio
- **Rate limit**: Se superato, retry dopo 30 secondi

## Troubleshooting

### Bot non risponde

1. Verifica token nel .env
2. Verifica che hai avviato chat con il bot (`/start`)
3. Verifica chat_id corretto (usa @userinfobot)
4. Testa con: `TelegramNotificationService::test()`

### Messaggi non arrivano

```php
// Debug
$result = TelegramNotificationService::sendAdmin('Test');
var_dump($result); // true = OK, false = errore

// Controlla log errori
tail -f storage/logs/errors-*.log | grep -i telegram
```

### Errore "chat not found"

L'utente non ha mai avviato una chat con il bot.
Soluzione: Aprire Telegram, cercare @need2talk_bot, cliccare "Avvia".

## Enterprise Tracking (DEDUPLICATION)

### Tabelle Database

Il sistema usa due tabelle PostgreSQL per il tracking enterprise:

#### `telegram_messages` - Audit Log
Traccia TUTTI i messaggi inviati a Telegram:
```sql
-- Struttura principale
id BIGSERIAL PRIMARY KEY
message_type VARCHAR(50)    -- 'admin_url', 'security_alert', 'error', 'daily_logs', etc.
chat_id VARCHAR(50)
telegram_message_id BIGINT  -- ID restituito da Telegram
content_hash VARCHAR(64)    -- SHA256 per deduplicazione
message_preview VARCHAR(500)
file_name, file_path, file_size  -- Per documenti
success BOOLEAN
error_message TEXT
sent_at TIMESTAMPTZ
```

**Indici ottimizzati per:**
- Lookup per tipo e data
- Deduplicazione via content_hash
- Tracking errori
- Cleanup automatico (90 giorni)

#### `telegram_log_deliveries` - Deduplicazione Log
Previene l'invio duplicato di file log:
```sql
id BIGSERIAL PRIMARY KEY
log_date DATE              -- Data dei log (YYYY-MM-DD)
log_type VARCHAR(50)       -- 'errors', 'security', 'database', etc.
file_name VARCHAR(255)
original_size BIGINT
compressed_size BIGINT
is_compressed BOOLEAN
telegram_message_id BIGINT
success BOOLEAN
sent_at TIMESTAMPTZ

-- CRITICAL: Constraint che previene duplicati
UNIQUE (log_date, log_type)
```

### Come Funziona la Deduplicazione

1. Prima di inviare un log file, il sistema controlla `telegram_log_deliveries`
2. Se esiste già un record con `(log_date, log_type)` e `success=TRUE`, skip
3. Se non esiste o ha fallito, invia e registra il risultato
4. Il constraint UNIQUE garantisce l'integrità

### Esempio Output Cron

**Prima esecuzione:**
```
✅ errors: sent
✅ security: sent
✅ database: sent
❌ overlay: not_found
❌ api: not_found
Total sent: 3/5
```

**Seconda esecuzione (stesso giorno):**
```
✓ errors: already_sent
✓ security: already_sent
✓ database: already_sent
❌ overlay: not_found
❌ api: not_found
Total sent: 0/5
```

### API Statistiche

```php
// Ottieni statistiche messaggi (ultimi 30 giorni)
$stats = TelegramNotificationService::getStats(30);
// Returns: total_messages, by_type, log_deliveries, log_unique_days

// Ottieni messaggi recenti (per admin panel)
$recent = TelegramNotificationService::getRecentMessages(50);
```

## File Correlati

- `app/Services/TelegramNotificationService.php` - Servizio principale (v2.0 con tracking)
- `app/Services/AdminUrlNotificationService.php` - Notifiche admin URL
- `scripts/cron-telegram-daily-logs.php` - Invio log notturno (con deduplicazione)
- `database/migrations/2025_11_28_create_telegram_tracking_enterprise.sql` - Migrazione tabelle
- `.env` - Configurazione token e chat_id
