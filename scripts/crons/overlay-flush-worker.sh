#!/bin/sh
# Overlay Flush Worker - Enterprise Galaxy V4
#
# Cron wrapper for adaptive overlay flush worker.
# Runs every 5 minutes via cron.
#
# ADAPTIVE BEHAVIOR:
# - IDLE: Worker exits immediately (no DB connections opened)
# - LOW: Single flush, exit
# - NORMAL/HIGH/PEAK: Continuous flushing for 2-5 minutes
#
# CRONTAB ENTRY (every 5 minutes):
# */5 * * * * /var/www/need2talk/scripts/crons/overlay-flush-worker.sh >> /var/www/need2talk/storage/logs/overlay-flush.log 2>&1

export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

# Timestamp for logging
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

echo "[$TIMESTAMP] 🔄 OVERLAY-FLUSH: Starting worker..."

# ENTERPRISE: Detect if running inside Docker container or on host
if [ -f /.dockerenv ] || grep -q docker /proc/1/cgroup 2>/dev/null; then
    # Inside Docker container - execute PHP directly
    php "$PROJECT_ROOT/scripts/overlay-flush-worker.php" --max-runtime=120 2>&1 | tail -30
else
    # On host system - use docker exec
    docker exec need2talk_php php /var/www/html/scripts/overlay-flush-worker.php --max-runtime=120 2>&1 | tail -30
fi

EXIT_CODE=$?

TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
echo "[$TIMESTAMP] ✅ OVERLAY-FLUSH: Worker completed (exit code: $EXIT_CODE)"

exit $EXIT_CODE
