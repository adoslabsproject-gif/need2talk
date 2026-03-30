#!/bin/sh
################################################################################
# ENTERPRISE GALAXY: Monthly Partition Rotation for security_events
#
# SCHEDULE: Monthly on 1st at 03:00 AM
# PURPOSE: Drops oldest partition (6 months old) and adds new partition
# OPERATION: Direct PHP execution inside container (cron runs inside container)
# RETENTION: 6 months rolling window (GDPR compliant)
#
# @author Claude Code (Enterprise Galaxy Initiative)
# @since 2025-10-27
################################################################################

export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

php "$SCRIPT_DIR/rotate-security-partitions.php" 2>&1
