#!/bin/sh

# ============================================================================
# ENTERPRISE GALAXY: Real-Time Docker Container Monitoring
# ============================================================================
# Monitors CPU, RAM, Network I/O for all need2talk containers
# Optimized for 4-core AMD + 16GB RAM environment
#
# POSIX-COMPLIANT: Works with Alpine BusyBox sh (no bash required)
# WEB-COMPATIBLE: Auto-detects TTY and disables colors/clear for web terminal
#
# Usage:
#   sh ./scripts/docker-stats-monitor.sh           # Continuous monitoring
#   sh ./scripts/docker-stats-monitor.sh 10        # Monitor for 10 iterations
#
# Author: Claude Code (AI-Orchestrated Development)
# Date: 2025-01-10 (Updated for Alpine/BusyBox + Web Terminal compatibility)
# ============================================================================

# Colors for output (disable if not TTY - for web terminal compatibility)
if [ -t 1 ]; then
    # Running in terminal with TTY
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    PURPLE='\033[0;35m'
    CYAN='\033[0;36m'
    NC='\033[0m' # No Color
else
    # Running in web/non-TTY context (e.g., shell_exec)
    RED=''
    GREEN=''
    YELLOW=''
    BLUE=''
    PURPLE=''
    CYAN=''
    NC=''
fi

# Configuration
REFRESH_INTERVAL=2  # seconds
MAX_ITERATIONS=${1:-0}  # 0 = infinite

# Warning thresholds (optimized for 4-core + 16GB)
CPU_WARNING=70
CPU_CRITICAL=85
RAM_WARNING=80
RAM_CRITICAL=90

# Function to format bytes to human-readable (POSIX-compliant, no 'local')
format_bytes() {
    _bytes=$1
    if [ "$_bytes" -lt 1024 ]; then
        echo "${_bytes}B"
    elif [ "$_bytes" -lt 1048576 ]; then
        echo "$(awk "BEGIN {printf \"%.1f\", $_bytes/1024}")KB"
    elif [ "$_bytes" -lt 1073741824 ]; then
        echo "$(awk "BEGIN {printf \"%.1f\", $_bytes/1048576}")MB"
    else
        echo "$(awk "BEGIN {printf \"%.2f\", $_bytes/1073741824}")GB"
    fi
}

# Function to get container metrics
get_container_stats() {
    docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.NetIO}}\t{{.BlockIO}}"
}

# Function to check PHP-FPM worker status (POSIX-compliant)
get_php_fpm_status() {
    _container_id=$(docker ps -qf "name=need2talk_php")
    if [ -n "$_container_id" ]; then
        docker exec "$_container_id" sh -c "ps aux | grep php-fpm | grep -v grep | wc -l" 2>/dev/null || echo "N/A"
    else
        echo "N/A"
    fi
}

# Function to get PostgreSQL connections (ENTERPRISE: migrated from MySQL)
get_postgres_connections() {
    docker exec need2talk_postgres psql -U need2talk -d need2talk -t \
        -c "SELECT COUNT(*) FROM pg_stat_activity WHERE state = 'active';" 2>/dev/null | tr -d ' ' || echo "N/A"
}

# Function to get Redis memory
get_redis_memory() {
    docker exec need2talk_redis_master redis-cli -a YOUR_DB_PASSWORD \
        INFO memory 2>&1 | grep "used_memory_human" | cut -d: -f2 | tr -d '\r' || echo "N/A"
}

# Function to check alert level (POSIX-compliant)
check_alert_level() {
    _value=$1
    _warning=$2
    _critical=$3

    if [ "$_value" -ge "$_critical" ]; then
        printf "%b" "${RED}CRITICAL${NC}\n"
    elif [ "$_value" -ge "$_warning" ]; then
        printf "%b" "${YELLOW}WARNING${NC}\n"
    else
        printf "%b" "${GREEN}OK${NC}\n"
    fi
}

# Clear screen and print header (POSIX-compliant, printf instead of echo -e)
print_header() {
    # Clear only if running in TTY (not web terminal)
    if [ -t 1 ]; then
        clear
    fi
    printf "%b\n" "${PURPLE}╔════════════════════════════════════════════════════════════════════════════╗${NC}"
    printf "%b\n" "${PURPLE}║${NC}  ${CYAN}ENTERPRISE GALAXY: need2talk.it Real-Time Monitoring${NC}                  ${PURPLE}║${NC}"
    printf "%b\n" "${PURPLE}║${NC}  Server: DigitalOcean (4 core AMD + 16GB RAM)                             ${PURPLE}║${NC}"
    printf "%b\n" "${PURPLE}║${NC}  Optimized: 2025-01-10 (PHP-FPM 50 workers, PostgreSQL 4GB buffer)       ${PURPLE}║${NC}"
    printf "%b\n" "${PURPLE}╚════════════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    printf "%b" "${BLUE}Timestamp:${NC} $(date '+%Y-%m-%d %H:%M:%S %Z')\n"
    echo ""
}

# Main monitoring loop
iteration=0
while true; do
    print_header

    # System Overview
    printf "%b\n" "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    printf "%b\n" "${YELLOW}SYSTEM OVERVIEW${NC}"
    printf "%b\n" "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

    # System CPU & RAM
    printf "\n%b\n" "${GREEN}Host Resources:${NC}"
    free -h | head -2 | tail -1 | awk '{printf "  RAM: %s / %s (%.1f%% used)\n", $3, $2, ($3/$2)*100}'

    # CPU Load
    uptime | awk -F'load average:' '{print "  CPU Load (1/5/15min):" $2}'

    # PHP-FPM Workers
    php_workers=$(get_php_fpm_status)
    printf "  %b %s / 50 max\n" "${GREEN}PHP-FPM Workers Active:${NC}" "$php_workers"

    # PostgreSQL Connections (ENTERPRISE: migrated from MySQL)
    postgres_conn=$(get_postgres_connections)
    printf "  %b %s / 6000 max\n" "${GREEN}PostgreSQL Connections:${NC}" "$postgres_conn"

    # Redis Memory
    redis_mem=$(get_redis_memory)
    printf "  %b %s / 2GB max\n" "${GREEN}Redis Memory:${NC}" "$redis_mem"

    # Container Stats
    printf "\n%b\n" "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    printf "%b\n" "${YELLOW}CONTAINER METRICS${NC}"
    printf "%b\n" "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    get_container_stats

    # Alerts
    printf "\n%b\n" "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    printf "%b\n" "${YELLOW}THRESHOLDS & ALERTS${NC}"
    printf "%b\n" "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    printf "  CPU: %bWarning ≥%s%%%b, %bCritical ≥%s%%%b\n" "${YELLOW}" "$CPU_WARNING" "${NC}" "${RED}" "$CPU_CRITICAL" "${NC}"
    printf "  RAM: %bWarning ≥%s%%%b, %bCritical ≥%s%%%b\n" "${YELLOW}" "$RAM_WARNING" "${NC}" "${RED}" "$RAM_CRITICAL" "${NC}"
    printf "  PHP-FPM Workers: %bWarning ≥40/50%b, %bCritical ≥45/50%b\n" "${YELLOW}" "${NC}" "${RED}" "${NC}"

    # PHP-FPM Worker Alert
    if [ "$php_workers" != "N/A" ]; then
        if [ "$php_workers" -ge 45 ]; then
            printf "\n  %b⚠️  CRITICAL: PHP-FPM workers at capacity! Consider upgrading to 8-core.%b\n" "${RED}" "${NC}"
        elif [ "$php_workers" -ge 40 ]; then
            printf "\n  %b⚠️  WARNING: PHP-FPM workers high. Monitor for sustained load.%b\n" "${YELLOW}" "${NC}"
        fi
    fi

    echo ""

    # Check iteration limit
    iteration=$((iteration + 1))
    if [ "$MAX_ITERATIONS" -gt 0 ] && [ "$iteration" -ge "$MAX_ITERATIONS" ]; then
        printf "\n%bMonitoring completed (%s iterations)%b\n" "${GREEN}" "$MAX_ITERATIONS" "${NC}"
        exit 0
    fi

    # Show refresh message only in continuous mode (MAX_ITERATIONS = 0)
    printf "%bPress Ctrl+C to exit | Refresh every %ss%b\n" "${BLUE}" "$REFRESH_INTERVAL" "${NC}"
    sleep $REFRESH_INTERVAL
done
