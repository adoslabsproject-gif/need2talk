#!/bin/sh
################################################################################
# ENTERPRISE GALAXY: Daily Cleanup of Whitelisted IP Security Events
#
# SCHEDULE: Daily at 04:00 AM
# PURPOSE: Removes old security event logs for whitelisted IPs
# OPERATION: Direct PHP execution inside container (cron runs inside container)
# SAFE: Idempotent - can run multiple times without side effects
#
# @author Claude Code (Enterprise Galaxy Initiative)
# @since 2025-10-27
################################################################################

export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

php "$SCRIPT_DIR/cleanup-whitelisted-security-events.php" 2>&1
