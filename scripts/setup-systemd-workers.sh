#!/bin/bash

###############################################################################
# NEED2TALK - SYSTEMD EMAIL WORKERS SETUP
# Setup enterprise-grade systemd service for email workers with auto-restart
###############################################################################

set -e  # Exit on error

echo "🚀 Need2Talk - Systemd Email Workers Setup"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "❌ Please run as root (sudo)"
    exit 1
fi

# Variables
SERVICE_NAME="need2talk-email-workers"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
SOURCE_FILE="/var/www/need2talk/docker/systemd/${SERVICE_NAME}.service"
LOG_DIR="/var/www/need2talk/storage/logs"

echo "📋 Configuration:"
echo "   Service: $SERVICE_NAME"
echo "   File: $SERVICE_FILE"
echo ""

# Stop old workers if running
echo "🛑 Stopping old Docker workers..."
cd /var/www/need2talk
if [ -f scripts/stop-workers-docker.sh ]; then
    bash scripts/stop-workers-docker.sh || true
fi

# Create log directory
echo "📁 Creating log directory..."
mkdir -p "$LOG_DIR"
chown -R 1000:1000 "$LOG_DIR"

# Copy service file
echo "📄 Installing systemd service..."
if [ ! -f "$SOURCE_FILE" ]; then
    echo "❌ Source file not found: $SOURCE_FILE"
    exit 1
fi

cp "$SOURCE_FILE" "$SERVICE_FILE"
chmod 644 "$SERVICE_FILE"

# Reload systemd
echo "🔄 Reloading systemd daemon..."
systemctl daemon-reload

# Enable service (auto-start on boot)
echo "✅ Enabling service (auto-start on boot)..."
systemctl enable "$SERVICE_NAME"

# Start service
echo "▶️  Starting service..."
systemctl start "$SERVICE_NAME"

# Wait a moment
sleep 2

# Check status
echo ""
echo "📊 Service Status:"
systemctl status "$SERVICE_NAME" --no-pager || true

echo ""
echo "✅ SETUP COMPLETE!"
echo ""
echo "📌 Useful Commands:"
echo "   Status:  systemctl status $SERVICE_NAME"
echo "   Start:   systemctl start $SERVICE_NAME"
echo "   Stop:    systemctl stop $SERVICE_NAME"
echo "   Restart: systemctl restart $SERVICE_NAME"
echo "   Logs:    journalctl -u $SERVICE_NAME -f"
echo "   Disable: systemctl disable $SERVICE_NAME"
echo ""
echo "📝 Log file: $LOG_DIR/systemd-email-workers.log"
echo ""
