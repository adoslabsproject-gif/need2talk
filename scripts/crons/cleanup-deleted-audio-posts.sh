#!/bin/sh
# ================================================================================
# ENTERPRISE GALAXY: Cleanup Deleted Audio Posts (30-day retention)
# ================================================================================
#
# PURPOSE:
# Permanently delete soft-deleted audio posts after 30 days
#
# SCHEDULE: Daily at 3:30 AM
#
# ================================================================================

export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting audio posts cleanup job..."

php "$PROJECT_ROOT/scripts/crons/cleanup-deleted-audio-posts.php" 2>&1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Audio posts cleanup completed."
