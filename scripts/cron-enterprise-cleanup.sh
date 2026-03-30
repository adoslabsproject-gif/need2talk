#!/bin/bash
# Enterprise Monitoring Cleanup - Cron Script (OrbStack/Docker)
# Add to crontab: 0 2 * * * /path/to/scripts/cron-enterprise-cleanup.sh

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LOG_FILE="$SCRIPT_DIR/../storage/logs/enterprise_cleanup.log"

echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting enterprise cleanup (Docker)" >> "$LOG_FILE"

# Execute cleanup script inside PHP Docker container
docker exec need2talk_php php /var/www/html/scripts/cleanup-enterprise-logs.php >> "$LOG_FILE" 2>&1

echo "$(date '+%Y-%m-%d %H:%M:%S') - Cleanup completed" >> "$LOG_FILE"
echo "---" >> "$LOG_FILE"
