#!/bin/sh

# 🛑 STOP DOCKER ENTERPRISE WORKERS - NEED2TALK
# Stops all Docker workers with optional log cleanup
#
# Usage:
#   ./scripts/stop-workers-docker.sh          # Stop workers only
#   ./scripts/stop-workers-docker.sh --clean  # Stop workers + delete all logs

# ENTERPRISE GALAXY: Use full path to docker for PHP-FPM compatibility
DOCKER_CMD="/usr/local/bin/docker"
if [ ! -x "$DOCKER_CMD" ]; then
    DOCKER_CMD=$(which docker 2>/dev/null || echo "docker")
fi

CLEAN_LOGS=false

# Check for --clean flag
if [ "$1" = "--clean" ]; then
    CLEAN_LOGS=true
    echo "🛑 Stopping Docker Workers + Cleaning Logs..."
else
    echo "🛑 Stopping Docker Workers..."
fi

# Get all email-worker processes inside container
# ENTERPRISE GALAXY FIX: Workers run in need2talk_worker container (BusyBox Alpine)
# Use pgrep instead of ps+awk (works on both GNU and BusyBox)
WORKER_PIDS=$($DOCKER_CMD exec need2talk_worker pgrep -f "email-worker.php" || echo "")

if [ -z "$WORKER_PIDS" ]; then
    echo "⚠️  No workers running in Docker container"
else
    WORKER_COUNT=$(echo "$WORKER_PIDS" | wc -l | tr -d ' ')
    echo "📊 Found $WORKER_COUNT worker(s) running"

    # Kill each worker
    for PID in $WORKER_PIDS; do
        echo "🔴 Stopping worker with PID: $PID"
        $DOCKER_CMD exec need2talk_worker kill $PID 2>/dev/null || \
        $DOCKER_CMD exec need2talk_worker kill -9 $PID 2>/dev/null
        sleep 0.2
    done
fi

# Remove PID file
rm -f storage/logs/docker-enterprise-workers.pids

# Verify all stopped
REMAINING=$($DOCKER_CMD exec need2talk_worker ps aux | grep email-worker | grep -v grep | wc -l | tr -d ' ')

if [ "$REMAINING" -gt 0 ]; then
    echo ""
    echo "⚠️  $REMAINING processes still running, force killing..."
    $DOCKER_CMD exec need2talk_worker pkill -9 -f email-worker.php 2>/dev/null
    sleep 1
    REMAINING=$($DOCKER_CMD exec need2talk_worker ps aux | grep email-worker | grep -v grep | wc -l | tr -d ' ')
fi

echo ""
if [ "$REMAINING" -eq 0 ]; then
    echo "🎯 All Docker Workers Stopped!"
else
    echo "❌ Warning: $REMAINING worker(s) still running"
fi

# Clean logs if requested
if [ "$CLEAN_LOGS" = true ]; then
    echo ""
    echo "🧹 Cleaning worker logs..."

    # Delete individual worker logs
    rm -f storage/logs/worker_*.log
    WORKER_LOGS_DELETED=$(ls storage/logs/worker_*.log 2>/dev/null | wc -l | tr -d ' ')

    # Delete PID files
    rm -f storage/logs/*-workers.pids

    # Delete main application logs (optional - commented out for safety)
    # rm -f storage/logs/need2talk.log
    # rm -f storage/logs/php_errors.log

    echo "✅ Deleted worker-specific logs"
    echo "ℹ️  Main logs (need2talk.log, php_errors.log) preserved"
fi

echo ""
echo "📊 Remaining workers: $REMAINING"
