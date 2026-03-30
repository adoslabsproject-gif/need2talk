#!/bin/bash
# SYSTEMD SERVICE INSTALLATION SCRIPT
#
# Script per installare i file di servizio systemd per Need2Talk Email Workers
# - Crea i file .service e .target necessari
# - Configura le dipendenze corrette
# - Abilita i servizi per l'auto-start
# - Valida l'installazione

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYSTEMD_DIR="/etc/systemd/system"
PHP_CLEAN="$BIN_DIR/php-clean"
WORKER_SCRIPT="$BIN_DIR/email-worker.php"

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

# Check if running as root
check_root() {
    if [[ $EUID -ne 0 ]]; then
        error "This script must be run as root (use sudo)"
        exit 1
    fi
}

# Check prerequisites
check_prerequisites() {
    log "🔍 Checking prerequisites..."

    # Check if php-clean script exists
    if [[ ! -f "$PHP_CLEAN" ]]; then
        error "php-clean script not found at: $PHP_CLEAN"
        exit 1
    fi

    # Check if email worker script exists
    if [[ ! -f "$WORKER_SCRIPT" ]]; then
        error "Email worker script not found at: $WORKER_SCRIPT"
        exit 1
    fi

    # Check if systemd directory exists
    if [[ ! -d "$SYSTEMD_DIR" ]]; then
        error "Systemd directory not found: $SYSTEMD_DIR"
        exit 1
    fi

    log "✅ Prerequisites check passed"
}

# Create email worker service template
create_worker_service() {
    local service_file="$SYSTEMD_DIR/need2talk-email-worker@.service"

    log "📝 Creating email worker service template..."

    cat > "$service_file" << EOF
[Unit]
Description=Need2Talk Email Worker %i
Documentation=file://$APP_ROOT/WORKER_SYSTEM_GUIDE.md
After=network.target mysql.service redis.service
Wants=network.target
Requires=mysql.service redis.service
PartOf=need2talk-email-workers.target

[Service]
Type=exec
User=www-data
Group=www-data
WorkingDirectory=$APP_ROOT

# Command to run
ExecStart=$PHP_CLEAN $WORKER_SCRIPT --worker-id=systemd_worker_%i_\${RANDOM} --batch-size=150 --memory-limit=512M --sleep-seconds=2

# Resource limits
MemoryMax=512M
MemorySwapMax=0
CPUQuota=100%
TasksMax=100

# Restart policy
Restart=always
RestartSec=5s
StartLimitInterval=60s
StartLimitBurst=3

# Environment
Environment=APP_ENV=production
Environment=PHP_MEMORY_LIMIT=512M

# Process management
KillMode=mixed
KillSignal=SIGTERM
TimeoutStopSec=30s

# Security settings
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=$APP_ROOT/storage/logs $APP_ROOT/storage/temp
ProtectHome=true
PrivateDevices=true

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=need2talk-email-worker-%i

# Watchdog (optional)
WatchdogSec=60s

[Install]
WantedBy=need2talk-email-workers.target
EOF

    log "✅ Email worker service template created: $service_file"
}

# Create email workers target
create_workers_target() {
    local target_file="$SYSTEMD_DIR/need2talk-email-workers.target"

    log "📝 Creating email workers target..."

    cat > "$target_file" << EOF
[Unit]
Description=Need2Talk Email Workers Target
Documentation=file://$APP_ROOT/WORKER_SYSTEM_GUIDE.md
After=network.target mysql.service redis.service
Wants=need2talk-email-worker@1.service need2talk-email-worker@2.service need2talk-email-worker@3.service need2talk-email-worker@4.service need2talk-email-worker@5.service need2talk-email-worker@6.service need2talk-email-worker@7.service need2talk-email-worker@8.service

[Install]
WantedBy=multi-user.target
EOF

    log "✅ Email workers target created: $target_file"
}

# Create Redis service (if needed)
create_redis_service() {
    local redis_service="$SYSTEMD_DIR/need2talk-redis.service"

    if [[ -f "$redis_service" ]]; then
        info "Redis service already exists: $redis_service"
        return
    fi

    log "📝 Creating Redis service..."

    cat > "$redis_service" << EOF
[Unit]
Description=Need2Talk Redis Server
Documentation=https://redis.io/documentation
After=network.target

[Service]
Type=notify
User=redis
Group=redis
ExecStart=/usr/bin/redis-server /etc/redis/redis.conf
ExecReload=/bin/kill -USR2 \$MAINPID
TimeoutStopSec=0
Restart=always

# Security settings
NoNewPrivileges=true
PrivateTmp=true
PrivateDevices=true
ProtectHome=true
ReadWritePaths=/var/lib/redis /var/log/redis

[Install]
WantedBy=multi-user.target
EOF

    log "✅ Redis service created: $redis_service"
}

# Install services
install_services() {
    log "🚀 Installing systemd services..."

    create_worker_service
    create_workers_target

    # Ask if user wants Redis service
    read -p "Do you want to create a Redis service? (y/N): " create_redis
    if [[ "$create_redis" =~ ^[Yy]$ ]]; then
        create_redis_service
    fi

    # Reload systemd
    log "🔄 Reloading systemd daemon..."
    systemctl daemon-reload

    log "✅ Services installed successfully"
}

# Enable services
enable_services() {
    log "🔧 Enabling systemd services..."

    # Enable individual worker services
    for i in {1..8}; do
        systemctl enable "need2talk-email-worker@$i.service"
        info "Enabled worker service: need2talk-email-worker@$i.service"
    done

    # Enable target
    systemctl enable need2talk-email-workers.target
    log "✅ Email workers target enabled"

    # Enable Redis if it was created
    if [[ -f "$SYSTEMD_DIR/need2talk-redis.service" ]]; then
        systemctl enable need2talk-redis.service
        log "✅ Redis service enabled"
    fi
}

# Validate installation
validate_installation() {
    log "🔍 Validating installation..."
    local errors=0

    # Check service files exist
    local required_files=(
        "$SYSTEMD_DIR/need2talk-email-worker@.service"
        "$SYSTEMD_DIR/need2talk-email-workers.target"
    )

    for file in "${required_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            error "Missing required file: $file"
            ((errors++))
        else
            info "✅ Found: $(basename "$file")"
        fi
    done

    # Check if services are enabled
    for i in {1..8}; do
        if systemctl is-enabled "need2talk-email-worker@$i.service" &>/dev/null; then
            info "✅ Enabled: need2talk-email-worker@$i.service"
        else
            warn "Not enabled: need2talk-email-worker@$i.service"
        fi
    done

    if systemctl is-enabled need2talk-email-workers.target &>/dev/null; then
        info "✅ Enabled: need2talk-email-workers.target"
    else
        error "Not enabled: need2talk-email-workers.target"
        ((errors++))
    fi

    # Test syntax
    log "🔍 Testing service syntax..."
    if systemctl daemon-reload; then
        info "✅ Systemd configuration syntax is valid"
    else
        error "❌ Systemd configuration syntax error"
        ((errors++))
    fi

    if [[ $errors -eq 0 ]]; then
        log "✅ Installation validation passed"
        return 0
    else
        error "❌ Installation validation failed with $errors errors"
        return 1
    fi
}

# Show post-installation instructions
show_instructions() {
    echo
    log "🎉 Installation completed successfully!"
    echo
    echo "Next steps:"
    echo "1. Start the services:"
    echo "   systemctl start need2talk-email-workers.target"
    echo
    echo "2. Check status:"
    echo "   systemctl status need2talk-email-workers.target"
    echo
    echo "3. Monitor logs:"
    echo "   journalctl -f -u 'need2talk-email-worker@*.service'"
    echo
    echo "4. Use the control script:"
    echo "   $APP_ROOT/bin/systemd-email-control.sh status"
    echo
    echo "5. Set up monitoring (optional):"
    echo "   $APP_ROOT/bin/systemd-email-monitor.php &"
    echo
}

# Uninstall services
uninstall_services() {
    log "🗑️ Uninstalling systemd services..."

    # Stop services
    systemctl stop need2talk-email-workers.target 2>/dev/null || true

    # Disable services
    for i in {1..8}; do
        systemctl disable "need2talk-email-worker@$i.service" 2>/dev/null || true
    done
    systemctl disable need2talk-email-workers.target 2>/dev/null || true

    # Remove service files
    local files_to_remove=(
        "$SYSTEMD_DIR/need2talk-email-worker@.service"
        "$SYSTEMD_DIR/need2talk-email-workers.target"
    )

    for file in "${files_to_remove[@]}"; do
        if [[ -f "$file" ]]; then
            rm -f "$file"
            info "Removed: $file"
        fi
    done

    # Ask about Redis service
    if [[ -f "$SYSTEMD_DIR/need2talk-redis.service" ]]; then
        read -p "Remove Redis service too? (y/N): " remove_redis
        if [[ "$remove_redis" =~ ^[Yy]$ ]]; then
            systemctl stop need2talk-redis.service 2>/dev/null || true
            systemctl disable need2talk-redis.service 2>/dev/null || true
            rm -f "$SYSTEMD_DIR/need2talk-redis.service"
            info "Removed: need2talk-redis.service"
        fi
    fi

    # Reload systemd
    systemctl daemon-reload

    log "✅ Uninstallation completed"
}

# Show help
show_help() {
    echo "🚀 SYSTEMD SERVICE INSTALLATION - ENTERPRISE EMAIL WORKERS"
    echo "==========================================================="
    echo
    echo "Usage: $0 <command>"
    echo
    echo "Commands:"
    echo "  install      Install systemd service files and enable them"
    echo "  uninstall    Remove systemd service files and disable them"
    echo "  validate     Validate current installation"
    echo "  help         Show this help message"
    echo
    echo "Examples:"
    echo "  sudo $0 install"
    echo "  sudo $0 validate"
    echo "  sudo $0 uninstall"
    echo
    echo "Environment:"
    echo "  APP_ROOT: $APP_ROOT"
    echo "  SYSTEMD_DIR: $SYSTEMD_DIR"
    echo "  WORKER_SCRIPT: $WORKER_SCRIPT"
}

# Main function
main() {
    case "${1:-help}" in
        install)
            check_root
            check_prerequisites
            install_services
            enable_services
            validate_installation && show_instructions
            ;;
        uninstall)
            check_root
            uninstall_services
            ;;
        validate)
            validate_installation
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