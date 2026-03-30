#!/bin/sh

# ============================================================================
# ENTERPRISE GALAXY V11.7: Notification Workers Startup Script (AUTO-RESTART)
# ============================================================================
#
# PURPOSE:
# - Start notification queue workers with INFINITE AUTO-RESTART
# - Self-healing: workers restart automatically when they die
# - Memory-safe: max_runtime=2h forces periodic clean restarts
#
# USAGE:
# - Default (2 workers):  ./start-notification-workers.sh
# - Custom count:         ./start-notification-workers.sh 4
#
# ARCHITECTURE:
# - Workers run in dedicated container (isolated from PHP-FPM)
# - Process notification queue from Redis DB 7
# - Batch processing with deduplication
# - Progressive backoff when queue empty
# - Auto-restart loop prevents service interruption
#
# ENTERPRISE FEATURES:
# - Infinite restart loop (like email workers)
# - 2h max_runtime for connection recycling
# - 5s cooldown between restarts (prevents rapid crash loops)
# - Graceful shutdown on SIGTERM/SIGINT
#
# ============================================================================

# Configuration
WORKER_SCRIPT="/var/www/html/scripts/notification-worker.php"
DEFAULT_COUNT=2
MAX_COUNT=4
BATCH_SIZE=50
MAX_RUNTIME=7200   # 2 hours (reduced from 4h for faster recycling)
SLEEP_MS=100       # 100ms between batches
RESTART_DELAY=5    # 5s delay between restarts

# Get worker count from argument or use default
WORKER_COUNT=${1:-$DEFAULT_COUNT}

# Validate worker count
if [ "$WORKER_COUNT" -gt "$MAX_COUNT" ]; then
    echo "WARNING: Requested $WORKER_COUNT workers, max is $MAX_COUNT. Using $MAX_COUNT."
    WORKER_COUNT=$MAX_COUNT
fi

if [ "$WORKER_COUNT" -lt 1 ]; then
    echo "WARNING: Requested $WORKER_COUNT workers, min is 1. Using 1."
    WORKER_COUNT=1
fi

# Signal handler for graceful shutdown
SHUTDOWN=0
trap 'SHUTDOWN=1; echo "Received shutdown signal, stopping workers..."; kill $(jobs -p) 2>/dev/null' SIGTERM SIGINT

echo "═══════════════════════════════════════════════════════"
echo " ENTERPRISE GALAXY V11.7: Notification Workers"
echo "═══════════════════════════════════════════════════════"
echo " Workers to spawn: $WORKER_COUNT"
echo " Batch size: $BATCH_SIZE"
echo " Max runtime: $MAX_RUNTIME seconds ($(($MAX_RUNTIME / 3600))h)"
echo " Auto-restart: ENABLED (infinite loop)"
echo " Restart delay: ${RESTART_DELAY}s"
echo "═══════════════════════════════════════════════════════"
echo ""

# Check if worker script exists
if [ ! -f "$WORKER_SCRIPT" ]; then
    echo "ERROR: Worker script not found: $WORKER_SCRIPT"
    exit 1
fi

# Function to start a single worker
start_worker() {
    local worker_num=$1
    local worker_id="notif_worker_${worker_num}_$(date +%s)"

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting notification worker #$worker_num ($worker_id)..."

    php "$WORKER_SCRIPT" \
        --batch-size="$BATCH_SIZE" \
        --max-runtime="$MAX_RUNTIME" \
        --sleep="$SLEEP_MS" \
        --worker-id="$worker_id" &

    local pid=$!
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Worker #$worker_num started with PID: $pid"
    return $pid
}

# ENTERPRISE: Infinite restart loop with self-healing
# When workers die (max_runtime, memory, errors), they restart automatically
run_workers_forever() {
    local restart_count=0

    while [ $SHUTDOWN -eq 0 ]; do
        restart_count=$((restart_count + 1))

        if [ $restart_count -gt 1 ]; then
            echo ""
            echo "═══════════════════════════════════════════════════════"
            echo " RESTART #$restart_count - $(date '+%Y-%m-%d %H:%M:%S')"
            echo "═══════════════════════════════════════════════════════"
        fi

        # Start all workers
        for i in $(seq 1 $WORKER_COUNT); do
            start_worker $i
            sleep 0.5  # Small delay between starts
        done

        echo ""
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] All $WORKER_COUNT workers running. Waiting for any to exit..."

        # Wait for ANY worker to exit (not all)
        # This triggers restart when first worker dies
        wait -n 2>/dev/null || wait

        # If shutdown was requested, exit cleanly
        if [ $SHUTDOWN -eq 1 ]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Shutdown requested, exiting restart loop..."
            break
        fi

        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Worker(s) exited. Restarting in ${RESTART_DELAY}s..."
        sleep $RESTART_DELAY

        # Kill any remaining workers before restart (clean slate)
        kill $(jobs -p) 2>/dev/null
        sleep 1
    done

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Notification workers stopped. Total restarts: $restart_count"
}

# Main execution
if [ -n "$WORKER_MODE" ] && [ "$WORKER_MODE" = "1" ]; then
    echo "Running in Docker mode with infinite restart loop..."
    run_workers_forever
else
    # Non-Docker mode: start workers and exit (backward compatible)
    echo "Running in standalone mode (no auto-restart)..."
    for i in $(seq 1 $WORKER_COUNT); do
        start_worker $i
        sleep 0.5
    done
    echo ""
    echo "All $WORKER_COUNT notification workers started!"
fi
