#!/bin/bash

# Newsletter Auto-Recovery + Docker Worker Starter
# Health checks for Redis and newsletter worker container
# Cron: */15 * * * * (silent when OK, logs only errors/recovery)
#
# Usage:
#   ./start-newsletter-auto-recovery.sh       # Quiet mode (cron)
#   ./start-newsletter-auto-recovery.sh -v    # Verbose mode (interactive)

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
REDIS_CONTAINER="need2talk_redis_master"
NEWSLETTER_CONTAINER="need2talk_newsletter_worker"
REDIS_DB=4  # Newsletter queue Redis DB
LOG_FILE="$PROJECT_ROOT/storage/logs/newsletter_recovery.log"
ADMIN_TOGGLE_FILE="$PROJECT_ROOT/storage/newsletter_auto_restart_disabled.flag"

# Verbose mode: -v flag or TTY detected
VERBOSE=false
if [[ "$1" == "-v" ]] || [[ "$1" == "--verbose" ]] || [[ -t 1 ]]; then
    VERBOSE=true
fi

# Colors (only in verbose mode)
if $VERBOSE; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    CYAN='\033[0;36m'
    NC='\033[0m'
else
    RED='' GREEN='' YELLOW='' CYAN='' NC=''
fi

# Track if any recovery happened
RECOVERY_PERFORMED=false

# Output function (only in verbose mode)
out() {
    $VERBOSE && echo -e "$1"
}

# Log to file (always, for errors/recovery only)
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_FILE"
}

# Check if auto-restart is disabled via admin panel
is_auto_restart_disabled() {
    [ -f "$ADMIN_TOGGLE_FILE" ]
}

# Check Redis health
check_redis_health() {
    docker exec "$REDIS_CONTAINER" redis-cli -a "${REDIS_PASSWORD:-YOUR_REDIS_PASSWORD}" ping &>/dev/null
}

# Restart Redis container
restart_redis() {
    out "${YELLOW}Restarting Redis...${NC}"
    if docker restart "$REDIS_CONTAINER" &>/dev/null; then
        sleep 3
        if check_redis_health; then
            out "${GREEN}✓ Redis restarted${NC}"
            log "[RECOVERY] Redis restarted successfully"
            RECOVERY_PERFORMED=true
            return 0
        fi
    fi
    out "${RED}✗ Redis restart failed${NC}"
    log "[ERROR] Redis restart failed"
    return 1
}

# Check if newsletter container is running and healthy
check_newsletter_container() {
    docker ps --filter "name=$NEWSLETTER_CONTAINER" --filter "status=running" | grep -q "$NEWSLETTER_CONTAINER" && \
    docker exec "$NEWSLETTER_CONTAINER" pgrep -f 'admin-email-worker.php' > /dev/null 2>&1
}

# Redis health check and recovery
check_redis() {
    out "${CYAN}Checking Redis...${NC}"
    if check_redis_health; then
        out "${GREEN}✓ Redis OK${NC}"
        return 0
    else
        out "${YELLOW}! Redis unhealthy, attempting recovery...${NC}"
        log "[WARNING] Redis unhealthy, attempting restart"
        if ! restart_redis; then
            log "[CRITICAL] Redis restart failed - manual intervention needed"
            return 1
        fi
    fi
}

# Newsletter container check and recovery
check_newsletter() {
    out "${CYAN}Checking newsletter worker...${NC}"
    if check_newsletter_container; then
        local workers=$(docker exec "$NEWSLETTER_CONTAINER" pgrep -f 'admin-email-worker.php' 2>/dev/null | wc -l | tr -d ' ')
        out "${GREEN}✓ Newsletter OK ($workers workers)${NC}"
        return 0
    else
        out "${YELLOW}! Newsletter container unhealthy${NC}"

        if is_auto_restart_disabled; then
            out "${YELLOW}  Auto-restart disabled by admin${NC}"
            log "[INFO] Newsletter unhealthy but auto-restart disabled"
            return 0
        fi

        out "${CYAN}  Restarting container...${NC}"
        if docker-compose -f "$PROJECT_ROOT/docker-compose.yml" restart newsletter_worker &>/dev/null; then
            sleep 5
            if check_newsletter_container; then
                out "${GREEN}✓ Newsletter recovered${NC}"
                log "[RECOVERY] Newsletter container restarted successfully"
                RECOVERY_PERFORMED=true
                return 0
            fi
        fi

        out "${RED}✗ Newsletter restart failed${NC}"
        log "[ERROR] Newsletter container restart failed"
        return 1
    fi
}

# Main execution
main() {
    cd "$PROJECT_ROOT"

    # Pre-check: Redis container must be running
    if ! docker ps | grep -q "$REDIS_CONTAINER"; then
        log "[ERROR] Redis container not running"
        out "${RED}✗ Redis container not running${NC}"
        exit 1
    fi

    out "${CYAN}Newsletter auto-recovery check...${NC}"

    # Run health checks
    check_redis || exit 1
    check_newsletter || exit 1

    # Queue status (verbose only)
    if $VERBOSE; then
        local queue=$(docker exec "$REDIS_CONTAINER" redis-cli -a "${REDIS_PASSWORD:-YOUR_REDIS_PASSWORD}" -n $REDIS_DB ZCARD newsletter_queue:pending 2>/dev/null || echo "0")
        out "${GREEN}✓ Queue: $queue pending${NC}"
        out "${GREEN}All checks passed${NC}"
    fi

    # Only log if recovery was performed (not every 15 minutes when OK)
    # This keeps logs clean - only errors and recoveries are logged
}

main "$@"
