#!/bin/bash

# ============================================================================
# ENTERPRISE GALAXY: Cron Worker Startup Script
# ============================================================================
#
# ARCHITECTURE:
# - Dedicated container for ALL cron jobs (isolated from PHP-FPM)
# - Internal scheduler runs every minute, checks cron_jobs table
# - Redis-based locking prevents duplicate executions
# - Self-healing: auto-restart on failure
# - Zero impact on HTTP request handling
#
# FEATURES:
# - All crons managed via database (cron_jobs table)
# - Admin panel control (enable/disable, run now, view history)
# - Execution logging to cron_executions table
# - Health monitoring via heartbeat
#
# DEPLOYMENT:
# - Single instance (cron jobs don't benefit from parallelism)
# - Max runtime: 300s per cycle (5 minutes)
# - Memory limit: 256MB (cron jobs are lightweight)
#
# LOGGING:
# - Uses stdout/stderr (captured by Docker json-file driver)
# - View logs: docker logs need2talk_cron_worker
# - Rotate via Docker: max-size 10m, max-file 3
#
# ============================================================================

set -e

PROJECT_ROOT="/var/www/html"
HEARTBEAT_FILE="/tmp/cron_worker_heartbeat"
MAX_RUNTIME=300  # 5 minutes max per cycle
SLEEP_INTERVAL=20  # Run scheduler every 20 seconds (ENTERPRISE: sub-minute scheduling)

# ENTERPRISE V12.4: Log to file (readable by admin panel) + stdout (Docker)
LOG_DIR="$PROJECT_ROOT/storage/logs"
LOG_FILE="$LOG_DIR/cron-worker-docker.log"
mkdir -p "$LOG_DIR"

# Colors for output (Docker logs support ANSI colors)
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Log to both file and stdout (tee pattern)
log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo -e "$msg"
    # Strip ANSI colors for file output (use printf to avoid escape interpretation)
    printf '%s\n' "$msg" | sed 's/\x1b\[[0-9;]*m//g; s/\\033\[[0-9;]*m//g' >> "$LOG_FILE"
}

log_info() {
    log "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    log "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    log "${RED}[ERROR]${NC} $1"
}

# Update heartbeat for health check
update_heartbeat() {
    echo "$(date +%s)" > "$HEARTBEAT_FILE"
}

# Cleanup on exit
cleanup() {
    log_warn "Cron worker shutting down..."
    rm -f "$HEARTBEAT_FILE"
    exit 0
}

trap cleanup SIGTERM SIGINT SIGQUIT

# Banner
echo ""
echo "============================================================================"
echo "  ENTERPRISE GALAXY: Cron Worker v1.0"
echo "============================================================================"
echo ""
log_info "Starting Cron Worker..."
log_info "Project Root: $PROJECT_ROOT"
log_info "Log File: $LOG_FILE"
log_info "Max Runtime per cycle: ${MAX_RUNTIME}s"
log_info "Scheduler Interval: ${SLEEP_INTERVAL}s"
echo ""

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

# Initial heartbeat
update_heartbeat

# Main loop
CYCLE=0
while true; do
    CYCLE=$((CYCLE + 1))
    CYCLE_START=$(date +%s)

    log_info "=== Cycle #$CYCLE starting ==="
    update_heartbeat

    # Run the cron scheduler
    cd "$PROJECT_ROOT"

    # Execute scheduler with timeout (output to both stdout and file)
    if timeout "$MAX_RUNTIME" php "$PROJECT_ROOT/scripts/crons/cron-scheduler.php" 2>&1 | tee -a "$LOG_FILE"; then
        log_info "Scheduler completed successfully"
    else
        EXIT_CODE=$?
        if [ $EXIT_CODE -eq 124 ]; then
            log_error "Scheduler timed out after ${MAX_RUNTIME}s"
        else
            log_error "Scheduler failed with exit code: $EXIT_CODE"
        fi
    fi

    CYCLE_END=$(date +%s)
    CYCLE_DURATION=$((CYCLE_END - CYCLE_START))
    log_info "Cycle #$CYCLE completed in ${CYCLE_DURATION}s"

    # Update heartbeat after cycle
    update_heartbeat

    # Sleep until next cycle
    log_info "Sleeping for ${SLEEP_INTERVAL}s..."
    sleep "$SLEEP_INTERVAL"
done
