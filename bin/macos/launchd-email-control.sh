#!/bin/bash
# LAUNCHD EMAIL CONTROL SCRIPT - ENTERPRISE MANAGEMENT (macOS)
#
# Script di controllo unificato per gestire i workers email via launchd su macOS
# - Start/Stop/Restart tutti i workers
# - Status monitoring e health checks
# - Log management e debugging
# - Plist management e configuration

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BIN_MACOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLIST_PREFIX="com.need2talk.email-worker"
MAX_WORKERS=${WORKER_COUNT:-2}  # Default 2 workers, override with WORKER_COUNT env var
PLIST_DIR="$HOME/Library/LaunchAgents"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
}

info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] INFO:${NC} $1"
}

# Check if launchctl is available
check_launchd() {
    if ! command -v launchctl &> /dev/null; then
        error "launchctl not found. This script requires macOS."
        exit 1
    fi
}

# Create plist file for worker
create_plist() {
    local worker_id=$1
    local plist_file="$PLIST_DIR/${PLIST_PREFIX}.${worker_id}.plist"

    cat > "$plist_file" << EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>${PLIST_PREFIX}.${worker_id}</string>

    <key>ProgramArguments</key>
    <array>
        <string>${BIN_MACOS_DIR}/php-clean</string>
        <string>${BIN_MACOS_DIR}/email-worker.php</string>
        <string>--worker-id=${worker_id}</string>
        <string>--batch-size=150</string>
        <string>--memory-limit=512M</string>
        <string>--sleep-seconds=2</string>
    </array>

    <key>WorkingDirectory</key>
    <string>${APP_ROOT}</string>

    <key>StandardOutPath</key>
    <string>${APP_ROOT}/storage/logs/worker_${worker_id}.log</string>

    <key>StandardErrorPath</key>
    <string>${APP_ROOT}/storage/logs/worker_${worker_id}_error.log</string>

    <key>KeepAlive</key>
    <dict>
        <key>SuccessfulExit</key>
        <false/>
        <key>Crashed</key>
        <true/>
    </dict>

    <key>RunAtLoad</key>
    <false/>

    <key>ThrottleInterval</key>
    <integer>10</integer>

    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>/usr/local/bin:/usr/bin:/bin</string>
        <key>WORKER_ID</key>
        <string>${worker_id}</string>
    </dict>
</dict>
</plist>
EOF

    info "Created plist: $plist_file"
}

# Install plist files for all workers
install_plists() {
    log "📋 Installing launchd plist files..."

    mkdir -p "$PLIST_DIR"

    for ((i=1; i<=MAX_WORKERS; i++)); do
        create_plist $i
    done

    log "✅ All plist files installed"
}

# Start all email workers
start_workers() {
    log "🚀 Starting launchd email workers..."

    for ((i=1; i<=MAX_WORKERS; i++)); do
        local label="${PLIST_PREFIX}.${i}"

        if launchctl load "$PLIST_DIR/${label}.plist" 2>/dev/null; then
            info "Started worker $i"
        else
            warn "Worker $i already loaded or failed to start"
        fi
    done

    log "✅ Email workers start completed"
}

# Stop all email workers
stop_workers() {
    log "🛑 Stopping launchd email workers..."

    for ((i=1; i<=MAX_WORKERS; i++)); do
        local label="${PLIST_PREFIX}.${i}"

        if launchctl unload "$PLIST_DIR/${label}.plist" 2>/dev/null; then
            info "Stopped worker $i"
        else
            warn "Worker $i not loaded or failed to stop"
        fi
    done

    log "✅ Email workers stop completed"
}

# Restart all workers
restart_workers() {
    log "🔄 Restarting launchd email workers..."
    stop_workers
    sleep 2
    start_workers
}

# Show status of all workers
status_workers() {
    log "📊 Email workers status:"
    echo

    local active_count=0
    local total_count=0

    for ((i=1; i<=MAX_WORKERS; i++)); do
        local label="${PLIST_PREFIX}.${i}"
        total_count=$((total_count + 1))

        if launchctl list | grep -q "$label" 2>/dev/null; then
            local pid=$(launchctl list | grep "$label" | awk '{print $1}')
            if [[ "$pid" != "-" ]]; then
                echo -e "  Worker $i: ${GREEN}●${NC} active (PID: $pid)"
                active_count=$((active_count + 1))
            else
                echo -e "  Worker $i: ${YELLOW}●${NC} loaded but not running"
            fi
        else
            echo -e "  Worker $i: ${RED}●${NC} inactive"
        fi
    done

    echo
    echo -e "Status: $active_count/$total_count workers active"

    if [[ $active_count -eq $total_count ]]; then
        echo -e "${GREEN}✅ All workers running${NC}"
    elif [[ $active_count -gt 0 ]]; then
        echo -e "${YELLOW}⚠️  Some workers down${NC}"
    else
        echo -e "${RED}❌ All workers down${NC}"
    fi
}

# Show logs from all workers
logs_workers() {
    log "📝 Recent worker logs:"
    echo

    for ((i=1; i<=MAX_WORKERS; i++)); do
        local log_file="${APP_ROOT}/storage/logs/worker_${i}.log"
        if [[ -f "$log_file" ]]; then
            echo -e "${BLUE}=== Worker $i ===${NC}"
            tail -n 5 "$log_file"
            echo
        fi
    done
}

# Clean up old log files
cleanup_logs() {
    log "🧹 Cleaning up old worker logs..."

    find "${APP_ROOT}/storage/logs" -name "worker_*.log" -mtime +7 -delete
    find "${APP_ROOT}/storage/logs" -name "worker_*_error.log" -mtime +7 -delete

    log "✅ Log cleanup completed"
}

# Health check for workers
health_check() {
    log "🏥 Performing health check..."

    local healthy_count=0
    local total_count=0

    for ((i=1; i<=MAX_WORKERS; i++)); do
        total_count=$((total_count + 1))
        local label="${PLIST_PREFIX}.${i}"

        if launchctl list | grep -q "$label" 2>/dev/null; then
            local pid=$(launchctl list | grep "$label" | awk '{print $1}')
            if [[ "$pid" != "-" ]] && kill -0 "$pid" 2>/dev/null; then
                healthy_count=$((healthy_count + 1))
                info "Worker $i: healthy (PID: $pid)"
            else
                warn "Worker $i: unhealthy or dead"
            fi
        else
            warn "Worker $i: not loaded"
        fi
    done

    echo
    if [[ $healthy_count -eq $total_count ]]; then
        log "✅ All workers healthy ($healthy_count/$total_count)"
        return 0
    else
        error "❌ Workers unhealthy ($healthy_count/$total_count)"
        return 1
    fi
}

# Show help
show_help() {
    echo "NEED2TALK EMAIL WORKERS - LAUNCHD CONTROL (macOS)"
    echo
    echo "Usage: $0 [COMMAND]"
    echo "       WORKER_COUNT=N $0 [COMMAND]  # Override worker count"
    echo
    echo "Commands:"
    echo "  install     Install launchd plist files"
    echo "  start       Start all email workers"
    echo "  stop        Stop all email workers"
    echo "  restart     Restart all email workers"
    echo "  status      Show worker status"
    echo "  logs        Show recent worker logs"
    echo "  health      Perform health check"
    echo "  cleanup     Clean up old log files"
    echo "  help        Show this help"
    echo
    echo "Worker Count:"
    echo "  Default: 2 workers (~800 emails capacity)"
    echo "  Each worker handles up to 400 emails in batch"
    echo
    echo "Examples:"
    echo "  $0 install              # First time setup (2 workers)"
    echo "  $0 start                # Start 2 workers (default)"
    echo "  WORKER_COUNT=4 $0 start # Start 4 workers (~1,600 emails)"
    echo "  WORKER_COUNT=8 $0 start # Start 8 workers (~3,200 emails)"
    echo "  $0 status               # Check status"
    echo "  $0 health               # Health check"
    echo
    echo "Current Configuration:"
    echo "  Workers: $MAX_WORKERS"
    echo "  Capacity: ~$((MAX_WORKERS * 400)) emails/batch"
}

# Main script logic
main() {
    check_launchd

    case "${1:-help}" in
        install)
            install_plists
            ;;
        start)
            start_workers
            ;;
        stop)
            stop_workers
            ;;
        restart)
            restart_workers
            ;;
        status)
            status_workers
            ;;
        logs)
            logs_workers
            ;;
        health)
            health_check
            ;;
        cleanup)
            cleanup_logs
            ;;
        help|--help|-h)
            show_help
            ;;
        *)
            error "Unknown command: $1"
            echo
            show_help
            exit 1
            ;;
    esac
}

# Run main function with all arguments
main "$@"