#!/bin/bash
# SYSTEMD EMAIL CONTROL SCRIPT - ENTERPRISE MANAGEMENT
#
# Script di controllo unificato per gestire i workers email via systemd
# - Start/Stop/Restart tutti i workers
# - Status monitoring e health checks
# - Log management e debugging
# - Service installation e configuration

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICE_NAME="need2talk-email-worker@"
TARGET_NAME="need2talk-email-workers.target"
MAX_WORKERS=${WORKER_COUNT:-2}  # Default 2 workers, override with WORKER_COUNT env var

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

# Check if systemctl is available
check_systemd() {
    if ! command -v systemctl &> /dev/null; then
        error "systemctl not found. This script requires systemd."
        exit 1
    fi
}

# Start all email workers
start_workers() {
    log "🚀 Starting systemd email workers..."

    if sudo systemctl start "$TARGET_NAME"; then
        log "✅ Email workers target started successfully"
        sleep 3
        show_status
    else
        error "❌ Failed to start email workers target"
        return 1
    fi
}

# Stop all email workers
stop_workers() {
    log "🛑 Stopping systemd email workers..."

    if sudo systemctl stop "$TARGET_NAME"; then
        log "✅ Email workers target stopped successfully"
        sleep 2
        show_status
    else
        error "❌ Failed to stop email workers target"
        return 1
    fi
}

# Restart all email workers
restart_workers() {
    log "🔄 Restarting systemd email workers..."

    if sudo systemctl restart "$TARGET_NAME"; then
        log "✅ Email workers target restarted successfully"
        sleep 3
        show_status
    else
        error "❌ Failed to restart email workers target"
        return 1
    fi
}

# Show status of all workers
show_status() {
    log "📊 Checking systemd email workers status..."
    echo

    # Show target status
    info "🎯 Target Status:"
    sudo systemctl status "$TARGET_NAME" --no-pager -l || true
    echo

    # Show individual worker status
    info "👥 Individual Worker Status:"
    for i in $(seq 1 $MAX_WORKERS); do
        service_name="${SERVICE_NAME}${i}.service"
        status=$(sudo systemctl is-active "$service_name" 2>/dev/null || echo "inactive")

        if [ "$status" = "active" ]; then
            echo -e "  Worker $i: ${GREEN}✅ ACTIVE${NC}"
        else
            echo -e "  Worker $i: ${RED}❌ $status${NC}"
        fi
    done
    echo

    # Show summary
    active_count=$(sudo systemctl list-units "${SERVICE_NAME}*.service" --state=active --no-legend | wc -l)
    echo -e "📈 Summary: ${GREEN}$active_count${NC}/$MAX_WORKERS workers active"
}

# Show detailed status with logs
show_detailed_status() {
    log "🔍 Detailed systemd email workers status..."
    echo

    for i in $(seq 1 $MAX_WORKERS); do
        service_name="${SERVICE_NAME}${i}.service"
        echo -e "${BLUE}=== Worker $i ($service_name) ===${NC}"

        # Service status
        sudo systemctl status "$service_name" --no-pager -l || true

        # Memory usage
        memory=$(sudo systemctl show "$service_name" --property=MemoryCurrent --value 2>/dev/null || echo "0")
        if [ "$memory" != "0" ] && [ "$memory" != "[not set]" ]; then
            memory_mb=$((memory / 1024 / 1024))
            echo "💾 Memory Usage: ${memory_mb}MB"
        fi

        # Restart count
        restarts=$(sudo systemctl show "$service_name" --property=NRestarts --value 2>/dev/null || echo "0")
        echo "🔄 Restart Count: $restarts"

        echo
    done
}

# Follow logs for all workers
follow_logs() {
    log "📋 Following systemd email workers logs..."
    echo "Press Ctrl+C to stop following logs"
    echo

    sudo journalctl -f -u "${SERVICE_NAME}*.service"
}

# Show recent logs
show_logs() {
    local lines=${1:-50}

    log "📋 Showing last $lines lines of systemd email workers logs..."
    echo

    sudo journalctl -u "${SERVICE_NAME}*.service" --no-pager -n "$lines"
}

# Health check
health_check() {
    log "🏥 Performing health check..."

    if [ -f "$APP_ROOT/bin/systemd-email-monitor.php" ]; then
        php "$APP_ROOT/bin/systemd-email-monitor.php" --check-only
    else
        warn "systemd-email-monitor.php not found, performing basic health check"
        show_status

        # Check Redis connection
        if command -v redis-cli &> /dev/null; then
            if redis-cli -p 6379 ping &>/dev/null; then
                info "✅ Redis connection: OK"
            else
                error "❌ Redis connection: FAILED"
            fi
        fi

        # Check email queue
        info "📧 Checking email queue..."
        if redis-cli -p 6379 -n 2 zcard email_queue:pending &>/dev/null; then
            pending=$(redis-cli -p 6379 -n 2 zcard email_queue:pending)
            info "📊 Pending emails: $pending"
        fi
    fi
}

# Enable services for auto-start
enable_services() {
    log "🔧 Enabling systemd email workers for auto-start..."

    if sudo systemctl enable "$TARGET_NAME"; then
        log "✅ Email workers target enabled for auto-start"

        # Show enabled status
        if sudo systemctl is-enabled "$TARGET_NAME" &>/dev/null; then
            info "📋 Auto-start status: ENABLED"
        fi
    else
        error "❌ Failed to enable email workers target"
        return 1
    fi
}

# Disable services from auto-start
disable_services() {
    log "🔧 Disabling systemd email workers from auto-start..."

    if sudo systemctl disable "$TARGET_NAME"; then
        log "✅ Email workers target disabled from auto-start"
    else
        error "❌ Failed to disable email workers target"
        return 1
    fi
}

# Reset failed states
reset_failed() {
    log "🧹 Resetting failed systemd service states..."

    for i in $(seq 1 $MAX_WORKERS); do
        service_name="${SERVICE_NAME}${i}.service"
        sudo systemctl reset-failed "$service_name" 2>/dev/null || true
    done

    sudo systemctl reset-failed "$TARGET_NAME" 2>/dev/null || true
    log "✅ Failed states reset"
}

# Reload systemd configuration
reload_config() {
    log "🔄 Reloading systemd configuration..."

    if sudo systemctl daemon-reload; then
        log "✅ Systemd configuration reloaded"
    else
        error "❌ Failed to reload systemd configuration"
        return 1
    fi
}

# Install systemd service files (placeholder - would need actual files)
install_services() {
    warn "⚠️  Service installation requires systemd service files to be present in /etc/systemd/system/"
    echo "Required files:"
    echo "  - /etc/systemd/system/need2talk-email-worker@.service"
    echo "  - /etc/systemd/system/need2talk-email-workers.target"
    echo
    echo "After placing the files, run:"
    echo "  sudo systemctl daemon-reload"
    echo "  sudo systemctl enable need2talk-email-workers.target"
}

# Show usage
show_help() {
    echo "🚀 SYSTEMD EMAIL CONTROL - ENTERPRISE MANAGEMENT"
    echo "================================================="
    echo
    echo "Usage: $0 <command> [options]"
    echo "       WORKER_COUNT=N $0 <command>  # Override worker count"
    echo
    echo "Commands:"
    echo "  start              Start all email workers"
    echo "  stop               Stop all email workers"
    echo "  restart            Restart all email workers"
    echo "  status             Show workers status"
    echo "  detailed-status    Show detailed status with logs"
    echo "  logs [lines]       Show recent logs (default: 50 lines)"
    echo "  follow-logs        Follow logs in real-time"
    echo "  health-check       Perform comprehensive health check"
    echo "  enable             Enable auto-start on boot"
    echo "  disable            Disable auto-start on boot"
    echo "  reset-failed       Reset failed service states"
    echo "  reload-config      Reload systemd configuration"
    echo "  install            Show service installation instructions"
    echo "  help               Show this help message"
    echo
    echo "Worker Count:"
    echo "  Default: 2 workers (~800 emails capacity)"
    echo "  Each worker handles up to 400 emails in batch"
    echo
    echo "Examples:"
    echo "  $0 start                # Start 2 workers (default)"
    echo "  WORKER_COUNT=4 $0 start # Start 4 workers (~1,600 emails)"
    echo "  WORKER_COUNT=8 $0 start # Start 8 workers (~3,200 emails)"
    echo "  $0 status               # Check status"
    echo "  $0 logs 100             # View 100 log lines"
    echo "  $0 health-check         # Health check"
    echo
    echo "Current Configuration:"
    echo "  APP_ROOT: $APP_ROOT"
    echo "  Workers: $MAX_WORKERS"
    echo "  Capacity: ~$((MAX_WORKERS * 400)) emails/batch"
    echo "  TARGET: $TARGET_NAME"
}

# Main command dispatcher
main() {
    check_systemd

    case "${1:-help}" in
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
            show_status
            ;;
        detailed-status)
            show_detailed_status
            ;;
        logs)
            show_logs "${2:-50}"
            ;;
        follow-logs)
            follow_logs
            ;;
        health-check)
            health_check
            ;;
        enable)
            enable_services
            ;;
        disable)
            disable_services
            ;;
        reset-failed)
            reset_failed
            ;;
        reload-config)
            reload_config
            ;;
        install)
            install_services
            ;;
        help|--help|-h)
            show_help
            ;;
        *)
            error "Unknown command: ${1:-}"
            echo
            show_help
            exit 1
            ;;
    esac
}

main "$@"