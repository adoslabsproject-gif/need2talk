#!/bin/bash
################################################################################
# NEED2TALK - AUTO-SCALE DM AUDIO E2E WORKERS (ENTERPRISE GALAXY)
################################################################################
#
# Automatically scale DM Audio workers based on queue depth
#
# USAGE:
#   ./scripts/scale-dm-audio-workers.sh [--auto|<count>]
#
# EXAMPLES:
#   ./scripts/scale-dm-audio-workers.sh --auto   # Auto-scale based on queue
#   ./scripts/scale-dm-audio-workers.sh 2        # Scale to 2 workers
#   ./scripts/scale-dm-audio-workers.sh 4        # Scale to 4 workers (max)
#
# AUTO-SCALING RULES:
#   Queue < 10:   1 worker
#   Queue 10-50:  2 workers
#   Queue 50-100: 3 workers
#   Queue > 100:  4 workers (max)
#
################################################################################

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
MAX_WORKERS=4

# Load environment
if [ -f "$PROJECT_DIR/.env" ]; then
    export $(grep -v '^#' "$PROJECT_DIR/.env" | xargs)
fi

REDIS_HOST=${REDIS_HOST:-redis}
REDIS_PORT=${REDIS_PORT:-6379}
REDIS_PASSWORD=${REDIS_PASSWORD:-}
REDIS_DB=${REDIS_DB_QUEUE:-2}

# Function to get queue size
get_queue_size() {
    if [ -n "$REDIS_PASSWORD" ]; then
        QUEUE_SIZE=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -a "$REDIS_PASSWORD" \
            -n "$REDIS_DB" LLEN "need2talk:queue:dm_audio" 2>/dev/null || echo "0")
    else
        QUEUE_SIZE=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" \
            -n "$REDIS_DB" LLEN "need2talk:queue:dm_audio" 2>/dev/null || echo "0")
    fi
    echo "$QUEUE_SIZE"
}

# Function to get recommended worker count
get_recommended_workers() {
    local queue_size=$1

    if [ "$queue_size" -lt 10 ]; then
        echo 1
    elif [ "$queue_size" -lt 50 ]; then
        echo 2
    elif [ "$queue_size" -lt 100 ]; then
        echo 3
    else
        echo 4
    fi
}

# Function to count running workers
count_running_workers() {
    pgrep -f "dm-audio-worker.php" | wc -l | tr -d ' '
}

# Parse arguments
if [ "$1" == "--auto" ]; then
    # Auto-scale mode
    QUEUE_SIZE=$(get_queue_size)
    RECOMMENDED=$(get_recommended_workers "$QUEUE_SIZE")
    CURRENT=$(count_running_workers)

    echo "==============================================="
    echo "  DM AUDIO WORKER AUTO-SCALING"
    echo "==============================================="
    echo ""
    echo "Queue size:         $QUEUE_SIZE jobs"
    echo "Current workers:    $CURRENT"
    echo "Recommended:        $RECOMMENDED"
    echo ""

    if [ "$RECOMMENDED" -eq "$CURRENT" ]; then
        echo "No scaling needed. Workers at optimal count."
    elif [ "$RECOMMENDED" -gt "$CURRENT" ]; then
        echo "Scaling UP to $RECOMMENDED workers..."
        "$SCRIPT_DIR/start-dm-audio-workers.sh" "$RECOMMENDED"
    else
        echo "Scaling DOWN to $RECOMMENDED workers..."
        "$SCRIPT_DIR/start-dm-audio-workers.sh" "$RECOMMENDED"
    fi

elif [ -n "$1" ]; then
    # Manual scale to specific count
    WORKER_COUNT=$1

    if [ "$WORKER_COUNT" -lt 1 ] || [ "$WORKER_COUNT" -gt "$MAX_WORKERS" ]; then
        echo "Error: worker_count must be between 1 and $MAX_WORKERS"
        exit 1
    fi

    echo "Scaling to $WORKER_COUNT DM Audio worker(s)..."
    "$SCRIPT_DIR/start-dm-audio-workers.sh" "$WORKER_COUNT"

else
    # Show help
    echo "Usage: $0 [--auto|<count>]"
    echo ""
    echo "Options:"
    echo "  --auto     Auto-scale based on queue depth"
    echo "  <count>    Scale to specific number of workers (1-$MAX_WORKERS)"
    echo ""
    echo "Current status:"
    QUEUE_SIZE=$(get_queue_size)
    CURRENT=$(count_running_workers)
    RECOMMENDED=$(get_recommended_workers "$QUEUE_SIZE")
    echo "  Queue size:      $QUEUE_SIZE jobs"
    echo "  Running workers: $CURRENT"
    echo "  Recommended:     $RECOMMENDED"
fi
