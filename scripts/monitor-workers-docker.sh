#!/bin/sh

# 🔍 MONITOR DOCKER ENTERPRISE WORKERS - NEED2TALK
# Real-time monitoring of Docker workers status
#
# Usage:
#   ./scripts/monitor-workers-docker.sh           # One-time status check
#   ./scripts/monitor-workers-docker.sh --watch   # Continuous monitoring (every 5s)

# ENTERPRISE GALAXY: Use full path to docker for PHP-FPM compatibility
DOCKER_CMD="/usr/local/bin/docker"
if [ ! -x "$DOCKER_CMD" ]; then
    DOCKER_CMD=$(which docker 2>/dev/null || echo "docker")
fi

WATCH_MODE=false

# Check for --watch flag
if [ "$1" = "--watch" ]; then
    WATCH_MODE=true
fi

monitor_status() {
    clear
    echo "═══════════════════════════════════════════════════════"
    echo "🔍 DOCKER WORKERS STATUS - need2talk"
    echo "═══════════════════════════════════════════════════════"
    echo ""

    # Get worker processes
    WORKER_PROCESSES=$($DOCKER_CMD exec need2talk_worker ps aux | grep "email-worker.php" | grep -v grep)
    WORKER_COUNT=$(echo "$WORKER_PROCESSES" | grep -v '^$' | wc -l | tr -d ' ')

    echo "📊 Active Workers: $WORKER_COUNT"
    echo ""

    if [ "$WORKER_COUNT" -gt 0 ]; then
        echo "🟢 Running Workers (Docker Container PIDs):"
        echo "────────────────────────────────────────────────────────"
        printf "%-10s %-10s %-10s %s\n" "PID" "USER" "TIME" "COMMAND"
        echo "────────────────────────────────────────────────────────"
        echo "$WORKER_PROCESSES" | while read -r line; do
            if [ -n "$line" ]; then
                # ENTERPRISE GALAXY FIX: Container ps aux format is: PID USER TIME COMMAND
                PID=$(echo "$line" | awk '{print $1}')      # Column 1: PID
                USER=$(echo "$line" | awk '{print $2}')     # Column 2: USER (www)
                TIME=$(echo "$line" | awk '{print $3}')     # Column 3: TIME
                CMD=$(echo "$line" | awk '{for(i=4;i<=NF;i++) printf "%s ", $i}')  # Rest: COMMAND

                printf "%-10s %-10s %-10s %s\n" "$PID" "$USER" "$TIME" "$CMD"
            fi
        done
        echo ""
    else
        echo "⚠️  No workers running"
        echo ""
    fi

    # Check Redis queue
    echo "📬 Email Queue Status:"
    echo "────────────────────────────────────────────────────────"
    # pending and failed are ZSET (use ZCARD), processing is HASH (use HLEN)
    PENDING=$($DOCKER_CMD exec need2talk_redis redis-cli -n 2 ZCARD email_queue:pending 2>/dev/null || echo "N/A")
    PROCESSING=$($DOCKER_CMD exec need2talk_redis redis-cli -n 2 HLEN email_queue:processing 2>/dev/null || echo "N/A")
    FAILED=$($DOCKER_CMD exec need2talk_redis redis-cli -n 2 ZCARD email_queue:failed 2>/dev/null || echo "N/A")

    echo "   Pending:    $PENDING"
    echo "   Processing: $PROCESSING"
    echo "   Failed:     $FAILED"
    echo ""

    # Check MailHog
    echo "📧 MailHog Status:"
    echo "────────────────────────────────────────────────────────"
    MAILHOG_COUNT=$(curl -s http://localhost:8025/api/v2/messages 2>/dev/null | grep -o '"total":[0-9]*' | cut -d':' -f2)
    if [ -n "$MAILHOG_COUNT" ]; then
        echo "   Total emails: $MAILHOG_COUNT"
    else
        echo "   ⚠️  MailHog not accessible"
    fi
    echo ""

    # Recent worker logs
    echo "📋 Recent Worker Activity:"
    echo "────────────────────────────────────────────────────────"
    LATEST_LOG=$(ls -t storage/logs/worker_*.log 2>/dev/null | head -1)
    if [ -n "$LATEST_LOG" ] && [ -f "$LATEST_LOG" ]; then
        tail -5 "$LATEST_LOG" 2>/dev/null || echo "   No recent activity"
    else
        echo "   No worker logs found"
    fi
    echo ""

    echo "═══════════════════════════════════════════════════════"
    echo "$(date '+%Y-%m-%d %H:%M:%S')"

    if [ "$WATCH_MODE" = false ]; then
        echo ""
        echo "💡 Tip: Use --watch for continuous monitoring"
    fi
}

# Run monitoring
if [ "$WATCH_MODE" = true ]; then
    echo "🔄 Starting continuous monitoring (Ctrl+C to exit)..."
    sleep 2
    while true; do
        monitor_status
        sleep 5
    done
else
    monitor_status
fi
