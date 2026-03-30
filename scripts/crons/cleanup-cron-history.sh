#!/bin/bash

# ============================================================================
# ENTERPRISE GALAXY: Cron Execution History Cleanup
# ============================================================================
# Deletes cron_executions records older than 7 days
# Schedule: Daily at 12:50 (after other maintenance jobs)
#
# RATIONALE:
# - cron_executions table grows ~200+ records/day (31 jobs × ~7 runs each)
# - After 7 days: ~1,400+ records (enough for troubleshooting)
# - Without cleanup: 10,000+ records/month (performance degradation)
# ============================================================================

set -e

# Configuration
RETENTION_DAYS=7
SCRIPT_NAME="cleanup-cron-history"

# PostgreSQL connection (via Docker)
PSQL="docker exec need2talk_postgres psql -U need2talk -d need2talk -t -A"

# Get count before cleanup
COUNT_BEFORE=$($PSQL -c "SELECT COUNT(*) FROM cron_executions WHERE executed_at < NOW() - INTERVAL '$RETENTION_DAYS days';")

if [ "$COUNT_BEFORE" -gt 0 ]; then
    # Delete old records
    $PSQL -c "DELETE FROM cron_executions WHERE executed_at < NOW() - INTERVAL '$RETENTION_DAYS days';"

    echo "✅ Deleted $COUNT_BEFORE cron execution records older than $RETENTION_DAYS days"
else
    echo "✓ No old cron execution records to delete"
fi

# Show current table size
TOTAL=$($PSQL -c "SELECT COUNT(*) FROM cron_executions;")
echo "📊 Current cron_executions table size: $TOTAL records"
