#!/bin/sh
###############################################################################
# Email Workers Systemd Spawner - Enterprise Galaxy
#
# Spawns 2 simultaneous email verification workers inside Docker container
# Called by systemd service: need2talk-email-workers.service
#
# Workers Configuration:
# - Count: 2 workers
# - Batch size: 50 emails each (ENTERPRISE: Optimized batch size)
# - Max runtime: 4 hours (14400s)
# - Sleep: 2s between batches
# - Auto-restart: handled by systemd (Restart=always)
#
# ENTERPRISE STANDARDS:
# - PSR-12 compliant scripts
# - Professional logging
# - Zero downtime email processing
###############################################################################

WORKER_COUNT=2
BATCH_SIZE=50
MAX_RUNTIME=14400  # 4 hours in seconds
SLEEP_SECONDS=2

# ENTERPRISE V12.4: Log to file (readable by admin panel) + stdout (Docker)
LOG_DIR="/var/www/html/storage/logs"
LOG_FILE="$LOG_DIR/email-worker-docker.log"
mkdir -p "$LOG_DIR"

# Log function (writes to both stdout and file)
log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo "$msg"
    echo "$msg" >> "$LOG_FILE"
}

log "═══════════════════════════════════════════════════════"
log "🚀 ENTERPRISE GALAXY EMAIL WORKERS SPAWNER"
log "═══════════════════════════════════════════════════════"
log "Workers to spawn: $WORKER_COUNT"
log "Batch size: $BATCH_SIZE"
log "Max runtime: $MAX_RUNTIME seconds ($(($MAX_RUNTIME / 3600))h)"
log "Sleep: $SLEEP_SECONDS seconds"
log "Log file: $LOG_FILE"
log "═══════════════════════════════════════════════════════"
log ""

# Spawn workers in background
for i in $(seq 1 $WORKER_COUNT); do
    log "✅ Spawning worker #$i..."
    php /var/www/html/scripts/email-worker.php \
        --batch-size=$BATCH_SIZE \
        --max-runtime=$MAX_RUNTIME \
        --sleep-seconds=$SLEEP_SECONDS >> "$LOG_FILE" 2>&1 &

    WORKER_PID=$!
    log "   Worker #$i started with PID: $WORKER_PID"

    # Small delay between spawns to avoid race conditions
    sleep 1
done

log ""
log "═══════════════════════════════════════════════════════"
log "✨ All $WORKER_COUNT workers spawned successfully!"
log "🔄 Systemd will auto-restart after $MAX_RUNTIME seconds"
log "═══════════════════════════════════════════════════════"

# Wait for all background workers to finish
# When ANY worker exits (after 4h), systemd will restart this entire script
wait
