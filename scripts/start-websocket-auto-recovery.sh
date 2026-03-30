#!/bin/bash

# WebSocket Auto-Recovery + Health Check
# Monitors websocket container and restarts if unhealthy
# Cron: 0 */4 * * * (every 4 hours, like newsletter)
#
# Usage:
#   ./start-websocket-auto-recovery.sh       # Quiet mode (cron)
#   ./start-websocket-auto-recovery.sh -v    # Verbose mode (interactive)

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
WEBSOCKET_CONTAINER="need2talk_websocket"
REDIS_CONTAINER="need2talk_redis_master"
LOG_FILE="$PROJECT_ROOT/storage/logs/websocket_recovery.log"
ADMIN_TOGGLE_FILE="$PROJECT_ROOT/storage/websocket_auto_restart_disabled.flag"

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

# Check Redis health (websocket depends on Redis for pub/sub)
check_redis_health() {
    docker exec "$REDIS_CONTAINER" redis-cli -a "${REDIS_PASSWORD:-YOUR_REDIS_PASSWORD}" ping &>/dev/null
}

# Check if websocket container is running and process is alive
check_websocket_container() {
    # Check container is running
    if ! docker ps --filter "name=$WEBSOCKET_CONTAINER" --filter "status=running" | grep -q "$WEBSOCKET_CONTAINER"; then
        return 1
    fi

    # Check websocket-server.php process is running inside container
    if ! docker exec "$WEBSOCKET_CONTAINER" pgrep -f 'websocket-server.php' > /dev/null 2>&1; then
        return 1
    fi

    return 0
}

# Get websocket connection count
get_connection_count() {
    # Try to get active connections from websocket stats
    docker exec "$WEBSOCKET_CONTAINER" php -r "
        \$redis = new Redis();
        \$redis->connect('redis', 6379);
        \$redis->auth('${REDIS_PASSWORD:-YOUR_REDIS_PASSWORD}');
        \$redis->select(4);
        echo \$redis->sCard('websocket:connections') ?: '0';
    " 2>/dev/null || echo "0"
}

# Redis health check
check_redis() {
    out "${CYAN}Checking Redis (WebSocket dependency)...${NC}"
    if check_redis_health; then
        out "${GREEN}✓ Redis OK${NC}"
        return 0
    else
        out "${RED}✗ Redis unhealthy - WebSocket cannot function${NC}"
        log "[ERROR] Redis unhealthy - WebSocket recovery blocked"
        return 1
    fi
}

# WebSocket container check and recovery
check_websocket() {
    out "${CYAN}Checking WebSocket server...${NC}"

    if check_websocket_container; then
        local conns=$(get_connection_count)
        out "${GREEN}✓ WebSocket OK ($conns connections)${NC}"
        return 0
    else
        out "${YELLOW}! WebSocket container unhealthy${NC}"

        if is_auto_restart_disabled; then
            out "${YELLOW}  Auto-restart disabled by admin${NC}"
            log "[INFO] WebSocket unhealthy but auto-restart disabled"
            return 0
        fi

        out "${CYAN}  Restarting container...${NC}"
        log "[WARNING] WebSocket unhealthy, attempting restart"

        if docker-compose -f "$PROJECT_ROOT/docker-compose.yml" restart websocket &>/dev/null; then
            sleep 10  # WebSocket needs more time to initialize than newsletter

            if check_websocket_container; then
                out "${GREEN}✓ WebSocket recovered${NC}"
                log "[RECOVERY] WebSocket container restarted successfully"
                RECOVERY_PERFORMED=true
                return 0
            fi
        fi

        out "${RED}✗ WebSocket restart failed${NC}"
        log "[ERROR] WebSocket container restart failed - manual intervention needed"
        return 1
    fi
}

# Memory check - WebSocket can leak memory over time
check_memory() {
    out "${CYAN}Checking WebSocket memory...${NC}"

    local mem_usage=$(docker stats "$WEBSOCKET_CONTAINER" --no-stream --format "{{.MemPerc}}" 2>/dev/null | tr -d '%')

    if [ -z "$mem_usage" ]; then
        out "${YELLOW}! Cannot read memory stats${NC}"
        return 0
    fi

    # Convert to integer for comparison
    local mem_int=${mem_usage%.*}

    if [ "$mem_int" -gt 80 ]; then
        out "${YELLOW}! High memory usage: ${mem_usage}%${NC}"
        log "[WARNING] WebSocket high memory: ${mem_usage}% - consider restart"

        # If over 90%, force restart
        if [ "$mem_int" -gt 90 ]; then
            out "${RED}! Critical memory: ${mem_usage}% - forcing restart${NC}"
            log "[CRITICAL] WebSocket memory critical: ${mem_usage}% - forcing restart"

            if ! is_auto_restart_disabled; then
                docker-compose -f "$PROJECT_ROOT/docker-compose.yml" restart websocket &>/dev/null
                sleep 10
                log "[RECOVERY] WebSocket restarted due to memory pressure"
                RECOVERY_PERFORMED=true
            fi
        fi
    else
        out "${GREEN}✓ Memory OK (${mem_usage}%)${NC}"
    fi

    return 0
}

# Main execution
main() {
    cd "$PROJECT_ROOT"

    # Pre-check: Redis container must be running
    if ! docker ps | grep -q "$REDIS_CONTAINER"; then
        log "[ERROR] Redis container not running - WebSocket cannot function"
        out "${RED}✗ Redis container not running${NC}"
        exit 1
    fi

    out "${CYAN}WebSocket auto-recovery check...${NC}"

    # Run health checks
    check_redis || exit 1
    check_websocket || exit 1
    check_memory || true  # Memory check is non-fatal

    if $VERBOSE; then
        out "${GREEN}All checks passed${NC}"
    fi

    # Only log if recovery was performed (keeps logs clean)
}

main "$@"
