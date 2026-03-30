#!/bin/sh
# GDPR Account Deletion - Wrapper for Docker/Local compatibility
# ENTERPRISE: Auto-detects container vs host execution

export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

# ENTERPRISE: Detect if running inside Docker container or on host
if [ -f /.dockerenv ] || grep -q docker /proc/1/cgroup 2>/dev/null; then
    # Inside Docker container - execute PHP directly
    php "$PROJECT_ROOT/scripts/crons/cleanup-scheduled-account-deletions.php" 2>&1
else
    # On host system - use docker exec
    docker exec need2talk_php php /var/www/html/scripts/crons/cleanup-scheduled-account-deletions.php 2>&1
fi
