#!/bin/bash
################################################################################
# NEED2TALK - STOP DM AUDIO E2E WORKERS
################################################################################
#
# Gracefully stop all DM Audio E2E workers
#
# USAGE:
#   ./scripts/stop-dm-audio-workers.sh
#
################################################################################

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
PID_DIR="$PROJECT_DIR/storage/pids"

echo "Stopping DM Audio E2E Workers..."

STOPPED=0

# Stop workers using PID files
for PID_FILE in "$PID_DIR"/dm-audio-worker-*.pid; do
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        WORKER_NAME=$(basename "$PID_FILE" .pid)

        if kill -0 "$PID" 2>/dev/null; then
            echo "Stopping $WORKER_NAME (PID: $PID)..."
            kill -SIGTERM "$PID" 2>/dev/null || true
            STOPPED=$((STOPPED + 1))
        fi

        rm -f "$PID_FILE"
    fi
done

# Also kill any orphaned workers by process name
pkill -f "dm-audio-worker.php" 2>/dev/null || true

# Wait for graceful shutdown
if [ $STOPPED -gt 0 ]; then
    echo "Waiting for graceful shutdown..."
    sleep 3
fi

echo ""
echo "$STOPPED DM Audio Worker(s) stopped."
