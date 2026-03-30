#!/bin/bash

# Database Manager - Script unificato per gestire il database need2talk (PostgreSQL)
# Uso: ./db-manager.sh [comando]

set -e

# Colori
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Funzione banner
banner() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}  🗄️  Need2Talk DB Manager${NC}"
    echo -e "${BLUE}  (PostgreSQL)${NC}"
    echo -e "${BLUE}================================${NC}"
    echo ""
}

# Funzione help
show_help() {
    banner
    echo "Comandi disponibili:"
    echo ""
    echo "  ${GREEN}backup${NC}              Crea backup completo del database"
    echo "  ${GREEN}backup-structure${NC}    Crea backup solo struttura (senza dati)"
    echo "  ${GREEN}restore <file>${NC}      Ripristina database da file SQL"
    echo "  ${GREEN}reset${NC}               Reset completo (cancella volume e ricrea)"
    echo "  ${GREEN}update-init${NC}         Aggiorna il file init con l'ultimo backup"
    echo "  ${GREEN}list-backups${NC}        Lista tutti i backup disponibili"
    echo "  ${GREEN}status${NC}              Mostra status del database"
    echo "  ${GREEN}shell${NC}               Apri shell PostgreSQL (psql)"
    echo ""
    echo "Esempi:"
    echo "  ./db-manager.sh backup"
    echo "  ./db-manager.sh restore database/backups/backup.sql"
    echo "  ./db-manager.sh reset"
    echo ""
}

# Status database
show_status() {
    echo -e "${BLUE}📊 Status Database${NC}"
    echo ""

    if ! docker compose ps postgres | grep -q "Up"; then
        echo -e "${RED}❌ PostgreSQL non è attivo${NC}"
        echo "   Avvialo con: docker compose up -d postgres"
        exit 1
    fi

    echo -e "${GREEN}✅ PostgreSQL attivo${NC}"

    # Conta tabelle
    TABLE_COUNT=$(docker compose exec -T postgres psql -U need2talk -d need2talk -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';" 2>/dev/null | tr -d ' \r')
    echo "📋 Tabelle: $TABLE_COUNT"

    # Dimensione database
    DB_SIZE=$(docker compose exec -T postgres psql -U need2talk -d need2talk -t -c "SELECT pg_size_pretty(pg_database_size('need2talk'));" 2>/dev/null | tr -d ' \r')
    echo "💾 Dimensione: ${DB_SIZE}"

    # Connessioni attive
    CONN_COUNT=$(docker compose exec -T postgres psql -U need2talk -d need2talk -t -c "SELECT COUNT(*) FROM pg_stat_activity WHERE state = 'active';" 2>/dev/null | tr -d ' \r')
    echo "🔌 Connessioni attive: $CONN_COUNT"

    echo ""
}

# Backup
do_backup() {
    banner
    ./database-backup.sh
}

# Backup structure only
do_backup_structure() {
    banner
    ./database-backup.sh --structure-only
}

# Restore
do_restore() {
    if [ -z "$1" ]; then
        echo -e "${RED}❌ Specifica il file SQL da ripristinare${NC}"
        echo "   Uso: $0 restore <file.sql>"
        exit 1
    fi
    banner
    ./database-restore.sh "$1"
}

# Reset completo
do_reset() {
    banner
    echo -e "${YELLOW}⚠️  ATTENZIONE: Reset completo del database!${NC}"
    echo "   Questo cancellerà tutti i dati e ricrea dal file init."
    echo ""
    read -p "   Vuoi continuare? (s/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Ss]$ ]]; then
        echo -e "${RED}❌ Operazione annullata${NC}"
        exit 0
    fi

    echo ""
    echo -e "${BLUE}1️⃣  Stop container PostgreSQL...${NC}"
    docker compose stop postgres

    echo -e "${BLUE}2️⃣  Rimuovi volume database...${NC}"
    docker volume rm need2talk_postgres_data

    echo -e "${BLUE}3️⃣  Riavvio PostgreSQL...${NC}"
    docker compose up -d postgres

    echo -e "${BLUE}4️⃣  Attendo inizializzazione...${NC}"
    sleep 10

    echo ""
    echo -e "${GREEN}✅ Database resettato!${NC}"
    echo ""
    show_status
}

# Aggiorna file init
update_init() {
    banner
    echo -e "${BLUE}🔄 Aggiorna file init${NC}"
    echo ""

    # Lista backup disponibili
    echo "Backup disponibili:"
    ls -lht database/backups/*.sql 2>/dev/null | head -10 | awk '{print "  " $9 " (" $5 ")"}'
    echo ""

    read -p "Inserisci il nome del file da usare: " BACKUP_FILE

    if [ ! -f "$BACKUP_FILE" ]; then
        echo -e "${RED}❌ File non trovato: $BACKUP_FILE${NC}"
        exit 1
    fi

    # Copia il file
    cp "$BACKUP_FILE" database/init/01-init.sql
    echo -e "${GREEN}✅ File init aggiornato!${NC}"
    echo ""
    echo "Per usarlo:"
    echo "  1. docker compose down"
    echo "  2. docker volume rm need2talk_postgres_data"
    echo "  3. docker compose up -d"
    echo ""
}

# Lista backup
list_backups() {
    banner
    echo -e "${BLUE}📦 Backup disponibili:${NC}"
    echo ""

    if [ ! -d "database/backups" ] || [ -z "$(ls -A database/backups/*.sql 2>/dev/null)" ]; then
        echo -e "${YELLOW}⚠️  Nessun backup trovato${NC}"
        echo "   Crea il primo con: $0 backup"
        exit 0
    fi

    ls -lht database/backups/*.sql 2>/dev/null | awk '{print "  📄 " $9 " (" $5 ") - " $6 " " $7 " " $8}'
    echo ""

    BACKUP_COUNT=$(ls -1 database/backups/*.sql 2>/dev/null | wc -l)
    echo "Totale: $BACKUP_COUNT backup"
    echo ""
}

# Shell PostgreSQL (psql)
open_shell() {
    banner
    echo -e "${BLUE}🐚 Apertura shell PostgreSQL (psql)...${NC}"
    echo ""
    docker compose exec postgres psql -U need2talk -d need2talk
}

# Main
case "$1" in
    backup)
        do_backup
        ;;
    backup-structure)
        do_backup_structure
        ;;
    restore)
        do_restore "$2"
        ;;
    reset)
        do_reset
        ;;
    update-init)
        update_init
        ;;
    list-backups)
        list_backups
        ;;
    status)
        show_status
        ;;
    shell)
        open_shell
        ;;
    help|--help|-h|"")
        show_help
        ;;
    *)
        echo -e "${RED}❌ Comando sconosciuto: $1${NC}"
        echo ""
        show_help
        exit 1
        ;;
esac
