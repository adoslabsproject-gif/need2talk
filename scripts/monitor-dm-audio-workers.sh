#!/bin/bash
################################################################################
# NEED2TALK - MONITOR DM AUDIO E2E WORKERS (ENTERPRISE GALAXY)
################################################################################
#
# Monitor DM Audio E2E workers status and queue depth
#
# USAGE:
#   ./scripts/monitor-dm-audio-workers.sh           # One-time status
#   ./scripts/monitor-dm-audio-workers.sh --watch   # Continuous monitoring
#
################################################################################

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
PID_DIR="$PROJECT_DIR/storage/pids"
LOG_DIR="$PROJECT_DIR/storage/logs"

# Load environment
if [ -f "$PROJECT_DIR/.env" ]; then
    export $(grep -v '^#' "$PROJECT_DIR/.env" | xargs)
fi

REDIS_HOST=${REDIS_HOST:-redis}
REDIS_PORT=${REDIS_PORT:-6379}
REDIS_PASSWORD=${REDIS_PASSWORD:-}
REDIS_DB=${REDIS_DB_QUEUE:-2}

# Function to get Redis value
redis_get() {
    local key=$1
    if [ -n "$REDIS_PASSWORD" ]; then
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -a "$REDIS_PASSWORD" \
            -n "$REDIS_DB" GET "$key" 2>/dev/null || echo ""
    else
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" \
            -n "$REDIS_DB" GET "$key" 2>/dev/null || echo ""
    fi
}

# Function to get Redis list length
redis_llen() {
    local key=$1
    if [ -n "$REDIS_PASSWORD" ]; then
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -a "$REDIS_PASSWORD" \
            -n "$REDIS_DB" LLEN "$key" 2>/dev/null || echo "0"
    else
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" \
            -n "$REDIS_DB" LLEN "$key" 2>/dev/null || echo "0"
    fi
}

# Function to get Redis sorted set count
redis_zcard() {
    local key=$1
    if [ -n "$REDIS_PASSWORD" ]; then
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -a "$REDIS_PASSWORD" \
            -n "$REDIS_DB" ZCARD "$key" 2>/dev/null || echo "0"
    else
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" \
            -n "$REDIS_DB" ZCARD "$key" 2>/dev/null || echo "0"
    fi
}

# Function to get Redis set count
redis_scard() {
    local key=$1
    if [ -n "$REDIS_PASSWORD" ]; then
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -a "$REDIS_PASSWORD" \
            -n "$REDIS_DB" SCARD "$key" 2>/dev/null || echo "0"
    else
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" \
            -n "$REDIS_DB" SCARD "$key" 2>/dev/null || echo "0"
    fi
}

# Function to display status
show_status() {
    clear

    echo "==============================================="
    echo "  DM AUDIO E2E WORKERS - ENTERPRISE MONITOR"
    echo "  $(date '+%Y-%m-%d %H:%M:%S')"
    echo "==============================================="
    echo ""

    # Queue statistics
    PENDING=$(redis_llen "need2talk:queue:dm_audio")
    PROCESSING=$(redis_scard "need2talk:queue:dm_audio:processing")
    DELAYED=$(redis_zcard "need2talk:queue:dm_audio:delayed")
    FAILED=$(redis_llen "need2talk:queue:dm_audio:failed")

    echo "QUEUE STATUS:"
    echo "  Pending:     $PENDING jobs"
    echo "  Processing:  $PROCESSING jobs"
    echo "  Delayed:     $DELAYED jobs (retry)"
    echo "  Failed:      $FAILED jobs (dead letter)"
    echo ""

    # Worker status
    echo "WORKER STATUS:"
    RUNNING_WORKERS=0
    for PID_FILE in "$PID_DIR"/dm-audio-worker-*.pid 2>/dev/null; do
        if [ -f "$PID_FILE" ]; then
            PID=$(cat "$PID_FILE")
            WORKER_NAME=$(basename "$PID_FILE" .pid)

            if kill -0 "$PID" 2>/dev/null; then
                # Get memory usage
                MEM=$(ps -o rss= -p "$PID" 2>/dev/null | awk '{print int($1/1024)}')
                MEM=${MEM:-0}

                echo "  $WORKER_NAME: RUNNING (PID: $PID, MEM: ${MEM}MB)"
                RUNNING_WORKERS=$((RUNNING_WORKERS + 1))
            else
                echo "  $WORKER_NAME: STOPPED (stale PID file)"
            fi
        fi
    done

    if [ $RUNNING_WORKERS -eq 0 ]; then
        # Check for workers without PID files
        ORPHAN_COUNT=$(pgrep -f "dm-audio-worker.php" | wc -l | tr -d ' ')
        if [ "$ORPHAN_COUNT" -gt 0 ]; then
            echo "  $ORPHAN_COUNT orphan worker(s) running (no PID file)"
            RUNNING_WORKERS=$ORPHAN_COUNT
        else
            echo "  No workers running"
        fi
    fi

    echo ""
    echo "TOTALS:"
    echo "  Running workers: $RUNNING_WORKERS"

    # Recommended scaling
    if [ "$PENDING" -lt 10 ]; then
        RECOMMENDED=1
    elif [ "$PENDING" -lt 50 ]; then
        RECOMMENDED=2
    elif [ "$PENDING" -lt 100 ]; then
        RECOMMENDED=3
    else
        RECOMMENDED=4
    fi

    echo "  Recommended:     $RECOMMENDED"

    if [ "$RUNNING_WORKERS" -lt "$RECOMMENDED" ]; then
        echo ""
        echo "  SCALE UP recommended!"
        echo "  Run: ./scripts/scale-dm-audio-workers.sh $RECOMMENDED"
    elif [ "$RUNNING_WORKERS" -gt "$RECOMMENDED" ] && [ "$RUNNING_WORKERS" -gt 1 ]; then
        echo ""
        echo "  Consider scaling DOWN to $RECOMMENDED worker(s)"
    fi

    echo ""
    echo "==============================================="
    echo "Press Ctrl+C to exit"
}

# Main
if [ "$1" == "--watch" ]; then
    # Continuous monitoring
    while true; do
        show_status
        sleep 5
    done
else
    # One-time status
    show_status
fi
