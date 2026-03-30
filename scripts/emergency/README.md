# 🆘 Enterprise Emergency Access System

Scripts di accesso emergenza con **security enterprise-grade**.

## 🔒 Security Features

- ✅ **3-Layer CLI Protection** (SAPI check, HTTP vars check, IP whitelist)
- ✅ **Rate Limiting** (max 3 tentativi password ogni 10 minuti)
- ✅ **IP Whitelist** (solo localhost/SSH tunnel)
- ✅ **Mandatory .env Password** (blocca se usa default)
- ✅ **Complete Audit Logging** (ogni azione tracciata)
- ✅ **Redis Authentication** (connessione autenticata)
- ✅ **Outside Document Root** (non accessibile via web)

## 📁 Scripts

### 1. `admin-url-notifier.php`
Genera nuovo URL admin e invia notifiche.

**Uso**:
```bash
ssh root@YOUR_SERVER_IP "docker exec need2talk_php php /var/www/html/scripts/emergency/admin-url-notifier.php"
```

### 2. `emergency-admin-access.php`
Accesso emergenza completo con menu interattivo.

**Uso ENTERPRISE (con parametri CLI - consigliato per SSH)**:
```bash
# Con password e opzione come parametri (nessun input interattivo richiesto)
ssh root@YOUR_SERVER_IP "docker exec need2talk_php php /var/www/html/scripts/emergency/emergency-admin-access.php 'PASSWORD' '1'"

# Password = YOUR_DB_PASSWORD
# Opzioni: 1=Get URL, 2=Resend notifications, 3=Generate code, 4=List users, 6=Health check, 7=Exit

# Esempi:
# Get current admin URL
ssh root@YOUR_SERVER_IP "docker exec need2talk_php php /var/www/html/scripts/emergency/emergency-admin-access.php 'YOUR_DB_PASSWORD' '1'"

# System health check
ssh root@YOUR_SERVER_IP "docker exec need2talk_php php /var/www/html/scripts/emergency/emergency-admin-access.php 'YOUR_DB_PASSWORD' '6'"
```

**Uso tradizionale (con input interattivo - solo console locale)**:
```bash
# Richiede stdin interattivo (solo da console DigitalOcean o accesso diretto)
docker exec -it need2talk_php php /var/www/html/scripts/emergency/emergency-admin-access.php
```

**Funzionalità**:
1. Get current admin URL
2. Resend all notifications
3. Generate emergency access code (24h)
4. List all admin users
5. Create new admin user
6. System health check

## ⚙️ Setup (OBBLIGATORIO)

### Step 1: Genera Master Password Hash

```bash
# Genera hash della password
php -r "echo password_hash('TUA_PASSWORD_SICURA', PASSWORD_DEFAULT) . PHP_EOL;"
```

### Step 2: Aggiungi al .env

```bash
# Apri .env
nano /var/www/need2talk/.env

# Aggiungi questa riga (sostituisci con il tuo hash):
EMERGENCY_MASTER_PASSWORD='$2y$10$...'
```

**IMPORTANTE**: La password usata per questo progetto è:
```
YOUR_DB_PASSWORD
```

Hash da aggiungere al `.env`:
```bash
# In .env
EMERGENCY_MASTER_PASSWORD='$2y$10$YOUR_BCRYPT_HASH_HERE'
```

### Step 3: Test

```bash
# Test admin-url-notifier
docker exec need2talk_php php /var/www/html/scripts/emergency/admin-url-notifier.php

# Test emergency access (richiede password)
docker exec need2talk_php php /var/www/html/scripts/emergency/emergency-admin-access.php
```

## 🔐 Password Management

**Genera nuovo hash** per la password esistente:
```bash
docker exec need2talk_php php -r "echo password_hash('YOUR_DB_PASSWORD', PASSWORD_DEFAULT) . PHP_EOL;"
```

**Output**:
```
$2y$10$[hash_generato_dinamicamente]
```

Copia l'hash nel `.env` sotto `EMERGENCY_MASTER_PASSWORD`.

## 🚨 Troubleshooting

### Error: "EMERGENCY_MASTER_PASSWORD not set in .env file!"
**Soluzione**: Segui Step 1 e 2 sopra.

### Error: "Too many failed attempts"
**Soluzione**: Aspetta 10 minuti. Il rate limiting protegge da brute force.

### Error: "Access denied. Only localhost/SSH access permitted"
**Soluzione**: Gli script funzionano SOLO via SSH/CLI, non via web.

## 📊 Audit Logging

Tutte le azioni sono loggat in:
- **Database**: `admin_emergency_access_log` table
- **Log files**: `storage/logs/security-*.log`

**Query per vedere log**:
```sql
SELECT * FROM admin_emergency_access_log
ORDER BY created_at DESC
LIMIT 50;
```

## 🔥 Emergency Access via DigitalOcean Console

Se SSH non funziona, puoi accedere via **DigitalOcean Console**:

1. Login su DigitalOcean
2. Droplet → Access → "Console"
3. Login come `root`
4. Esegui:
   ```bash
   docker exec need2talk_php php /var/www/html/scripts/emergency/emergency-admin-access.php
   ```

## ⚠️ Security Warning

**MAI** eseguire questi script:
- ❌ Via browser (sono protetti, ma comunque...)
- ❌ Da macchine non fidate
- ❌ Senza logging abilitato

**SEMPRE**:
- ✅ Via SSH da macchina fidata
- ✅ Con password master sicura
- ✅ Controlla audit log dopo uso
- ✅ Ruota password regolarmente

## 📝 Changelog

### v2.0.0 - Enterprise Galaxy Edition (2025-10-27)
- ✅ 3-layer CLI protection
- ✅ Rate limiting (max 3 attempts/10min)
- ✅ IP whitelist
- ✅ Mandatory .env password
- ✅ Redis authentication
- ✅ Complete audit logging
- ✅ Moved outside document root

### v1.0.0 - Initial Release
- Basic CLI protection
- Menu interattivo
