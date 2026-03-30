#\!/bin/sh
# Session Sync Worker - Wrapper with output for cron logging

export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

echo "🔄 SESSION-SYNC: Starting worker (max 10s runtime)..."

# ENTERPRISE: Detect if running inside Docker container or on host
if [ -f /.dockerenv ] || grep -q docker /proc/1/cgroup 2>/dev/null; then
    # Inside Docker container - execute PHP directly
    php "$PROJECT_ROOT/scripts/session-sync-worker.php" --max-runtime=10 2>&1 | tail -20
else
    # On host system - use docker exec
    docker exec need2talk_php php /var/www/html/scripts/session-sync-worker.php --max-runtime=10 2>&1 | tail -20
fi

echo "✅ SESSION-SYNC: Worker completed"
exit 0
