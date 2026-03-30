#!/bin/bash
################################################################################
# NEED2TALK - START DM AUDIO E2E WORKERS (ENTERPRISE GALAXY)
################################################################################
#
# Start DM Audio E2E workers for async message processing
# Frees PHP-FPM from S3 uploads during real-time chat
#
# USAGE:
#   ./scripts/start-dm-audio-workers.sh [worker_count]
#
# EXAMPLES:
#   ./scripts/start-dm-audio-workers.sh      # Start 1 worker (default)
#   ./scripts/start-dm-audio-workers.sh 2    # Start 2 workers
#   ./scripts/start-dm-audio-workers.sh 4    # Start 4 workers (max)
#
# AUTO-SCALING RECOMMENDATION:
#   Queue < 10:   1 worker
#   Queue 10-50:  2 workers
#   Queue 50-100: 3 workers
#   Queue > 100:  4 workers
#
################################################################################

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
WORKER_SCRIPT="$PROJECT_DIR/scripts/dm-audio-worker.php"
LOG_DIR="$PROJECT_DIR/storage/logs"
PID_DIR="$PROJECT_DIR/storage/pids"
WORKER_COUNT=${1:-1}
MAX_WORKERS=4

# Validation
if [ "$WORKER_COUNT" -lt 1 ] || [ "$WORKER_COUNT" -gt "$MAX_WORKERS" ]; then
    echo "Error: worker_count must be between 1 and $MAX_WORKERS"
    exit 1
fi

# Ensure directories exist
mkdir -p "$LOG_DIR" "$PID_DIR"

echo "==============================================="
echo "  NEED2TALK - DM AUDIO E2E WORKERS"
echo "==============================================="
echo ""
echo "Starting $WORKER_COUNT DM audio worker(s)..."
echo ""

# Stop any existing workers first
"$SCRIPT_DIR/stop-dm-audio-workers.sh" 2>/dev/null || true
sleep 1

# Start workers
for i in $(seq 1 $WORKER_COUNT); do
    WORKER_ID="dm_audio_worker_$i"
    LOG_FILE="$LOG_DIR/dm-audio-worker-$i.log"
    PID_FILE="$PID_DIR/dm-audio-worker-$i.pid"

    echo "Starting worker $i ($WORKER_ID)..."

    # Start worker in background with nohup
    nohup php "$WORKER_SCRIPT" \
        --worker-id="$WORKER_ID" \
        --max-runtime=3600 \
        >> "$LOG_FILE" 2>&1 &

    # Save PID
    echo $! > "$PID_FILE"

    echo "  PID: $(cat "$PID_FILE")"
    echo "  Log: $LOG_FILE"
done

echo ""
echo "==============================================="
echo "  $WORKER_COUNT DM Audio Worker(s) Started"
echo "==============================================="
echo ""
echo "Monitor with: $SCRIPT_DIR/monitor-dm-audio-workers.sh"
echo "Scale with:   $SCRIPT_DIR/scale-dm-audio-workers.sh <count>"
echo "Stop with:    $SCRIPT_DIR/stop-dm-audio-workers.sh"
