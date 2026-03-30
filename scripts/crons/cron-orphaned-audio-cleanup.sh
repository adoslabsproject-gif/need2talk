#!/bin/sh
# ================================================================================
# ENTERPRISE GALAXY: Orphaned Audio Cleanup Wrapper
# ================================================================================
#
# PURPOSE: Clean up orphaned audio files not linked to any audio_posts record
# SCHEDULE: Daily at 3:00 AM
# CRON: 0 3 * * * docker exec need2talk_php /var/www/html/scripts/crons/cron-orphaned-audio-cleanup.sh
#
# ================================================================================

export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"

# The PHP script is in the parent scripts/ folder
php "$PROJECT_ROOT/scripts/cron-orphaned-audio-cleanup.php" 2>&1
