#!/bin/sh
################################################################################
# NEED2TALK - START DM AUDIO E2E WORKERS (DOCKER VERSION)
################################################################################
#
# Start DM Audio E2E workers inside Docker container
# Used by docker-compose dm_audio_worker service
#
# USAGE:
#   Called automatically by docker-compose, or manually:
#   ./scripts/start-dm-audio-workers-docker.sh [worker_count]
#
# NOTE: Uses /bin/sh (POSIX) for Alpine Linux compatibility (no bash)
#
################################################################################

set -e

# Fixed paths for Docker container (working_dir: /var/www/html)
PROJECT_DIR="/var/www/html"
WORKER_SCRIPT="$PROJECT_DIR/scripts/dm-audio-worker.php"
LOG_DIR="$PROJECT_DIR/storage/logs"
WORKER_COUNT=${1:-1}
MAX_WORKERS=4

# Validation
if [ "$WORKER_COUNT" -lt 1 ] || [ "$WORKER_COUNT" -gt "$MAX_WORKERS" ]; then
    echo "Error: worker_count must be between 1 and $MAX_WORKERS"
    exit 1
fi

# Ensure log directory exists
mkdir -p "$LOG_DIR"

echo "==============================================="
echo "  DM AUDIO E2E WORKERS (DOCKER)"
echo "==============================================="
echo "  Workers: $WORKER_COUNT"
echo "  Mode: Docker Container"
echo "==============================================="
echo ""

# Trap signals for graceful shutdown
PIDS=""
cleanup() {
    echo ""
    echo "Received shutdown signal, stopping workers..."
    for PID in $PIDS; do
        kill -SIGTERM "$PID" 2>/dev/null || true
    done
    wait
    echo "All workers stopped."
    exit 0
}
trap cleanup SIGTERM SIGINT SIGHUP

# Start workers
for i in $(seq 1 $WORKER_COUNT); do
    WORKER_ID="dm_audio_docker_$i"
    LOG_FILE="$LOG_DIR/dm-audio-worker-docker-$i.log"

    echo "Starting worker $i ($WORKER_ID)..."

    php "$WORKER_SCRIPT" \
        --worker-id="$WORKER_ID" \
        --max-runtime=3600 \
        >> "$LOG_FILE" 2>&1 &

    PIDS="$PIDS $!"
    echo "  PID: $!"
done

echo ""
echo "$WORKER_COUNT DM Audio Worker(s) started."
echo "Waiting for workers..."

# Wait for all workers (this keeps the container running)
wait
