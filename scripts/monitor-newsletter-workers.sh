#!/bin/bash

# 📊 ENTERPRISE GALAXY: Monitor Newsletter Worker Container
# Real-time monitoring of dedicated newsletter workers
# Silicon Valley Level: ⭐⭐⭐ | ENTERPRISE GALAXY Level: ⭐⭐⭐⭐⭐⭐⭐⭐⭐⭐

set -e

# ENTERPRISE: Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m'

NEWSLETTER_CONTAINER="need2talk_newsletter_worker"
REDIS_CONTAINER="need2talk_redis_master"
REDIS_DB=4  # Newsletter queue Redis DB
REDIS_PASSWORD="${REDIS_PASSWORD:-YOUR_REDIS_PASSWORD}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
ADMIN_TOGGLE_FILE="$PROJECT_ROOT/storage/newsletter_auto_restart_disabled.flag"

# ENTERPRISE: Check if watch mode is requested
WATCH_MODE=false
if [ "$1" = "--watch" ] || [ "$1" = "-w" ]; then
    WATCH_MODE=true
fi

# ENTERPRISE: Banner
show_banner() {
    echo ""
    echo "${PURPLE}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo "${PURPLE}║${NC}  ${CYAN}📊 ENTERPRISE GALAXY NEWSLETTER WORKERS MONITORING 📊${NC}  ${PURPLE}║${NC}"
    echo "${PURPLE}╠══════════════════════════════════════════════════════════════╣${NC}"
    echo "${PURPLE}║${NC}  ${GREEN}Container:${NC} need2talk_newsletter_worker                   ${PURPLE}║${NC}"
    echo "${PURPLE}║${NC}  ${GREEN}Redis DB:${NC} 4 (Newsletter Queue)                          ${PURPLE}║${NC}"
    echo "${PURPLE}║${NC}  ${GREEN}Tracking:${NC} NewsletterLinkWrapperService + Metrics       ${PURPLE}║${NC}"
    echo "${PURPLE}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# ENTERPRISE: Collect monitoring data
collect_data() {
    # Container status
    if docker ps --filter "name=$NEWSLETTER_CONTAINER" --filter "status=running" | grep -q "$NEWSLETTER_CONTAINER"; then
        CONTAINER_STATUS="${GREEN}✅ RUNNING${NC}"
        CONTAINER_RUNNING=true
    else
        CONTAINER_STATUS="${RED}❌ STOPPED${NC}"
        CONTAINER_RUNNING=false
    fi

    # Worker count
    if [ "$CONTAINER_RUNNING" = true ]; then
        WORKER_COUNT=$(docker exec "$NEWSLETTER_CONTAINER" pgrep -f 'admin-email-worker.php' | wc -l | tr -d ' ')
        if [ "$WORKER_COUNT" -gt 0 ]; then
            WORKER_STATUS="${GREEN}✅ $WORKER_COUNT worker(s) active${NC}"
        else
            WORKER_STATUS="${RED}❌ NO WORKERS RUNNING${NC}"
        fi
    else
        WORKER_COUNT=0
        WORKER_STATUS="${RED}❌ Container not running${NC}"
    fi

    # Memory usage (container)
    if [ "$CONTAINER_RUNNING" = true ]; then
        MEMORY_USAGE=$(docker stats --no-stream --format "{{.MemUsage}}" "$NEWSLETTER_CONTAINER" 2>/dev/null || echo "N/A")
        CPU_USAGE=$(docker stats --no-stream --format "{{.CPUPerc}}" "$NEWSLETTER_CONTAINER" 2>/dev/null || echo "N/A")
    else
        MEMORY_USAGE="N/A"
        CPU_USAGE="N/A"
    fi

    # Queue size (Redis DB 4)
    QUEUE_SIZE=$(docker exec "$REDIS_CONTAINER" redis-cli -a "$REDIS_PASSWORD" -n $REDIS_DB ZCARD newsletter_queue:pending 2>/dev/null || echo "0")

    # Processed count (approximate from metrics table - requires PHP-FPM access)
    PROCESSED_COUNT=$(docker exec need2talk_php php -r "
        require '/var/www/html/app/helpers.php';
        \$pdo = db_pdo();
        \$stmt = \$pdo->query('SELECT COUNT(*) FROM newsletter_metrics WHERE sent_at IS NOT NULL');
        echo \$stmt->fetchColumn();
    " 2>/dev/null || echo "N/A")

    # Open/Click counts
    OPENED_COUNT=$(docker exec need2talk_php php -r "
        require '/var/www/html/app/helpers.php';
        \$pdo = db_pdo();
        \$stmt = \$pdo->query('SELECT COUNT(DISTINCT recipient_email) FROM newsletter_metrics WHERE opened_at IS NOT NULL');
        echo \$stmt->fetchColumn();
    " 2>/dev/null || echo "N/A")

    CLICKED_COUNT=$(docker exec need2talk_php php -r "
        require '/var/www/html/app/helpers.php';
        \$pdo = db_pdo();
        \$stmt = \$pdo->query('SELECT COUNT(DISTINCT recipient_email) FROM newsletter_metrics WHERE clicked_at IS NOT NULL');
        echo \$stmt->fetchColumn();
    " 2>/dev/null || echo "N/A")

    # Admin toggle status
    if [ -f "$ADMIN_TOGGLE_FILE" ]; then
        ADMIN_TOGGLE="${YELLOW}⚡ Auto-restart DISABLED${NC}"
    else
        ADMIN_TOGGLE="${GREEN}✅ Auto-restart ENABLED${NC}"
    fi

    # Container uptime
    if [ "$CONTAINER_RUNNING" = true ]; then
        CONTAINER_UPTIME=$(docker inspect --format='{{.State.StartedAt}}' "$NEWSLETTER_CONTAINER" 2>/dev/null | xargs -I {} date -d {} "+%Y-%m-%d %H:%M:%S" 2>/dev/null || echo "N/A")
    else
        CONTAINER_UPTIME="N/A"
    fi

    # Health status
    if [ "$CONTAINER_RUNNING" = true ]; then
        HEALTH_STATUS=$(docker inspect --format='{{.State.Health.Status}}' "$NEWSLETTER_CONTAINER" 2>/dev/null || echo "none")
        if [ "$HEALTH_STATUS" = "healthy" ]; then
            HEALTH_STATUS="${GREEN}✅ HEALTHY${NC}"
        elif [ "$HEALTH_STATUS" = "unhealthy" ]; then
            HEALTH_STATUS="${RED}❌ UNHEALTHY${NC}"
        elif [ "$HEALTH_STATUS" = "starting" ]; then
            HEALTH_STATUS="${YELLOW}⏳ STARTING${NC}"
        else
            HEALTH_STATUS="${YELLOW}⚠️  NO HEALTHCHECK${NC}"
        fi
    else
        HEALTH_STATUS="${RED}❌ N/A${NC}"
    fi
}

# ENTERPRISE: Display monitoring data
display_data() {
    clear
    show_banner

    echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo "${BLUE}                     📊 CONTAINER STATUS${NC}"
    echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  ${CYAN}Container:${NC} $CONTAINER_STATUS"
    echo -e "  ${CYAN}Health:${NC} $HEALTH_STATUS"
    echo -e "  ${CYAN}Started:${NC} $CONTAINER_UPTIME"
    echo -e "  ${CYAN}Admin Toggle:${NC} $ADMIN_TOGGLE"
    echo ""

    echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo "${BLUE}                     👷 WORKERS STATUS${NC}"
    echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  ${CYAN}Workers:${NC} $WORKER_STATUS"
    echo -e "  ${CYAN}Memory Usage:${NC} $MEMORY_USAGE"
    echo -e "  ${CYAN}CPU Usage:${NC} $CPU_USAGE"
    echo ""

    echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo "${BLUE}                  📧 NEWSLETTER QUEUE & METRICS${NC}"
    echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  ${CYAN}Queue Size:${NC} ${GREEN}$QUEUE_SIZE${NC} newsletters pending (Redis DB $REDIS_DB)"
    echo -e "  ${CYAN}Sent Total:${NC} ${GREEN}$PROCESSED_COUNT${NC} emails sent"
    echo -e "  ${CYAN}Unique Opens:${NC} ${GREEN}$OPENED_COUNT${NC} recipients opened"
    echo -e "  ${CYAN}Unique Clicks:${NC} ${GREEN}$CLICKED_COUNT${NC} recipients clicked"
    echo ""

    # Show last 10 log lines if container is running
    if [ "$CONTAINER_RUNNING" = true ]; then
        echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
        echo "${BLUE}                   📋 RECENT LOGS (Last 10 lines)${NC}"
        echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
        echo ""
        docker logs --tail 10 "$NEWSLETTER_CONTAINER" 2>&1 | sed 's/^/  /'
        echo ""
    fi

    echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo "${BLUE}                        ⚙️  ACTIONS${NC}"
    echo "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "  ${CYAN}📊 Full Logs:${NC} docker logs -f $NEWSLETTER_CONTAINER"
    echo "  ${CYAN}🛑 Stop:${NC} ./scripts/stop-newsletter-workers.sh"
    echo "  ${CYAN}🔄 Restart:${NC} ./scripts/start-newsletter-auto-recovery.sh"
    echo "  ${CYAN}⚙️  Toggle Auto-restart:${NC} Admin Panel → Newsletter Workers"
    echo ""

    if [ "$WATCH_MODE" = true ]; then
        echo "${YELLOW}⏱  Refreshing in 5 seconds... (Ctrl+C to exit)${NC}"
    fi

    echo ""
}

# ENTERPRISE: Main execution
main() {
    if [ "$WATCH_MODE" = true ]; then
        # Watch mode - refresh every 5 seconds
        while true; do
            collect_data
            display_data
            sleep 5
        done
    else
        # One-time snapshot
        collect_data
        display_data
    fi
}

# ENTERPRISE: Execute main function
main "$@"
