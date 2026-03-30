#!/bin/bash
# LAUNCHD SERVICE INSTALLATION SCRIPT (macOS)
#
# Script per installare i file di servizio launchd per Need2Talk Email Workers
# - Crea i file .plist necessari
# - Configura le dipendenze corrette
# - Carica i servizi per l'auto-start
# - Valida l'installazione

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BIN_MACOS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LAUNCHD_DIR="$HOME/Library/LaunchAgents"
PHP_CLEAN="$BIN_MACOS_DIR/php-clean"
WORKER_SCRIPT="$BIN_MACOS_DIR/email-worker.php"
PLIST_PREFIX="com.need2talk.email-worker"

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

# Check if launchctl is available (macOS only)
check_launchd() {
    if ! command -v launchctl &> /dev/null; then
        error "launchctl not found. This script requires macOS."
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

    # Check if LaunchAgents directory exists, create if not
    if [[ ! -d "$LAUNCHD_DIR" ]]; then
        mkdir -p "$LAUNCHD_DIR"
        info "Created LaunchAgents directory: $LAUNCHD_DIR"
    fi

    log "✅ Prerequisites check passed"
}

# Create email worker plist file
create_worker_plist() {
    local worker_id=$1
    local plist_file="$LAUNCHD_DIR/${PLIST_PREFIX}.${worker_id}.plist"

    log "📝 Creating email worker plist $worker_id..."

    cat > "$plist_file" << EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>${PLIST_PREFIX}.${worker_id}</string>

    <key>ProgramArguments</key>
    <array>
        <string>$PHP_CLEAN</string>
        <string>$WORKER_SCRIPT</string>
        <string>--worker-id=launchd_worker_${worker_id}_\${RANDOM}</string>
        <string>--batch-size=150</string>
        <string>--memory-limit=512M</string>
        <string>--sleep-seconds=2</string>
    </array>

    <key>WorkingDirectory</key>
    <string>$APP_ROOT</string>

    <key>StandardOutPath</key>
    <string>$APP_ROOT/storage/logs/worker_${worker_id}.log</string>

    <key>StandardErrorPath</key>
    <string>$APP_ROOT/storage/logs/worker_${worker_id}_error.log</string>

    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>/usr/local/bin:/usr/bin:/bin</string>
        <key>APP_ENV</key>
        <string>production</string>
        <key>PHP_MEMORY_LIMIT</key>
        <string>512M</string>
        <key>WORKER_ID</key>
        <string>${worker_id}</string>
    </dict>

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

    <key>ProcessType</key>
    <string>Background</string>

    <key>Nice</key>
    <integer>1</integer>
</dict>
</plist>
EOF

    info "✅ Created plist: $plist_file"
}

# Install plist files
install_plists() {
    log "🚀 Installing launchd plist files..."

    for i in {1..8}; do
        create_worker_plist $i
    done

    log "✅ All plist files installed"
}

# Load services (equivalent to systemctl enable/start)
load_services() {
    log "🔧 Loading launchd services..."

    for i in {1..8}; do
        local plist_file="$LAUNCHD_DIR/${PLIST_PREFIX}.${i}.plist"

        if launchctl load "$plist_file" 2>/dev/null; then
            info "✅ Loaded: ${PLIST_PREFIX}.${i}"
        else
            warn "Failed to load or already loaded: ${PLIST_PREFIX}.${i}"
        fi
    done

    log "✅ Service loading completed"
}

# Validate installation
validate_installation() {
    log "🔍 Validating installation..."
    local errors=0

    # Check plist files exist
    for i in {1..8}; do
        local plist_file="$LAUNCHD_DIR/${PLIST_PREFIX}.${i}.plist"

        if [[ ! -f "$plist_file" ]]; then
            error "Missing required file: $plist_file"
            ((errors++))
        else
            info "✅ Found: $(basename "$plist_file")"
        fi
    done

    # Check if services are loaded
    local loaded_services=0
    for i in {1..8}; do
        if launchctl list | grep -q "${PLIST_PREFIX}.${i}" 2>/dev/null; then
            info "✅ Loaded: ${PLIST_PREFIX}.${i}"
            ((loaded_services++))
        else
            warn "Not loaded: ${PLIST_PREFIX}.${i}"
        fi
    done

    info "Services loaded: $loaded_services/8"

    if [[ $errors -eq 0 ]]; then
        log "✅ Installation validation passed"
        return 0
    else
        error "❌ Installation validation failed with $errors errors"
        return 1
    fi
}

# Unload and remove services
uninstall_services() {
    log "🗑️ Uninstalling launchd services..."

    # Unload services
    for i in {1..8}; do
        local plist_file="$LAUNCHD_DIR/${PLIST_PREFIX}.${i}.plist"

        if [[ -f "$plist_file" ]]; then
            launchctl unload "$plist_file" 2>/dev/null || true
            rm -f "$plist_file"
            info "Removed: ${PLIST_PREFIX}.${i}"
        fi
    done

    log "✅ Uninstallation completed"
}

# Show post-installation instructions
show_instructions() {
    echo
    log "🎉 Installation completed successfully!"
    echo
    echo "Next steps:"
    echo "1. Start the services:"
    echo "   $APP_ROOT/bin/macos/launchd-email-control.sh start"
    echo
    echo "2. Check status:"
    echo "   $APP_ROOT/bin/macos/launchd-email-control.sh status"
    echo
    echo "3. Monitor logs:"
    echo "   tail -f $APP_ROOT/storage/logs/worker_*.log"
    echo
    echo "4. Set up monitoring (optional):"
    echo "   $APP_ROOT/bin/macos/launchd-email-monitor.php &"
    echo
    echo "5. Health check:"
    echo "   $APP_ROOT/bin/macos/launchd-email-monitor.php --check-only"
    echo
    echo "Services created:"
    for i in {1..8}; do
        echo "  - ${PLIST_PREFIX}.${i}"
    done
    echo
}

# Show help
show_help() {
    echo "🚀 LAUNCHD SERVICE INSTALLATION - ENTERPRISE EMAIL WORKERS (macOS)"
    echo "=================================================================="
    echo
    echo "Usage: $0 <command>"
    echo
    echo "Commands:"
    echo "  install      Install launchd plist files and load them"
    echo "  uninstall    Remove launchd plist files and unload them"
    echo "  validate     Validate current installation"
    echo "  help         Show this help message"
    echo
    echo "Examples:"
    echo "  $0 install"
    echo "  $0 validate"
    echo "  $0 uninstall"
    echo
    echo "Environment:"
    echo "  APP_ROOT: $APP_ROOT"
    echo "  LAUNCHD_DIR: $LAUNCHD_DIR"
    echo "  WORKER_SCRIPT: $WORKER_SCRIPT"
    echo "  PLIST_PREFIX: $PLIST_PREFIX"
}

# Main function
main() {
    check_launchd

    case "${1:-help}" in
        install)
            check_prerequisites
            install_plists
            load_services
            validate_installation && show_instructions
            ;;
        uninstall)
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