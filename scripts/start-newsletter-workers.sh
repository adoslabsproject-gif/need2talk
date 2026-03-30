#!/bin/sh
###############################################################################
# Newsletter Workers Container Spawner - ENTERPRISE GALAXY
#
# Spawns newsletter email workers inside dedicated Docker container
# Called automatically by docker-compose as container command
#
# Workers Configuration:
# - Count: 1 worker (default), max 2 workers (configurable via env NEWSLETTER_WORKER_COUNT)
# - Batch size: 50 emails per batch (ENTERPRISE: Optimized for tracking system)
# - Max runtime: Unlimited (container lifecycle managed by Docker)
# - Sleep: 3s between batches (rate limit friendly)
# - Auto-restart: handled by Docker (restart: unless-stopped)
#
# ENTERPRISE GALAXY STANDARDS:
# - PSR-12 compliant scripts
# - Professional logging with rotation
# - Zero downtime email processing
# - Integrated with NewsletterLinkWrapperService (tracking)
# - SHA256 privacy-aware recipient hashing
# - Idempotent tracking operations
# - Memory leak prevention
# - Health monitoring via Docker healthcheck
#
# Architecture:
# - Dedicated container: need2talk_newsletter_worker
# - Isolated from PHP-FPM (no HTTP request impact)
# - Scalable: docker-compose up -d --scale newsletter_worker=3
# - Resource limits: 2 CPU cores, 1GB RAM
# - Auto-recovery: start-newsletter-auto-recovery.sh (cron)
###############################################################################

# ENTERPRISE: Worker Configuration (can be overridden by env vars)
WORKER_COUNT=${NEWSLETTER_WORKER_COUNT:-1}  # Default: 1, Max: 2
BATCH_SIZE=50                                # ENTERPRISE: Optimized batch size
SLEEP_SECONDS=3                              # 3s between batches
LOG_DIR="/var/www/html/storage/logs"

# ENTERPRISE: Logging function (NO ANSI colors for cleaner Docker logs)
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [NEWSLETTER] $1"
}

# ENTERPRISE: Validation
if [ "$WORKER_COUNT" -gt 2 ]; then
    log "ERROR: WORKER_COUNT cannot exceed 2 (requested: $WORKER_COUNT)"
    log "Falling back to maximum allowed: 2"
    WORKER_COUNT=2
fi

# ENTERPRISE: Startup Banner (plain text)
echo ""
echo "========================================================"
echo "  NEWSLETTER WORKER CONTAINER"
echo "========================================================"
echo "  Container: need2talk_newsletter_worker"
echo "  Workers:   $WORKER_COUNT"
echo "  Batch:     $BATCH_SIZE emails/batch"
echo "  Sleep:     ${SLEEP_SECONDS}s between batches"
echo "  Tracking:  NewsletterLinkWrapperService enabled"
echo "========================================================"
echo ""

log "Starting $WORKER_COUNT newsletter worker(s)..."

# ENTERPRISE: Ensure log directory exists
mkdir -p "$LOG_DIR"

# ENTERPRISE: Spawn workers in background
for i in $(seq 1 $WORKER_COUNT); do
    log "[WORKER $i] Spawning..."

    # Start admin-email-worker.php with optimized parameters
    php /var/www/html/scripts/admin-email-worker.php \
        --batch-size=$BATCH_SIZE \
        --sleep-seconds=$SLEEP_SECONDS \
        --worker-id=$i \
        > /dev/null 2>&1 &

    WORKER_PID=$!
    log "[WORKER $i] Started with PID: $WORKER_PID"

    # Save PID for monitoring
    echo "$WORKER_PID" >> "$LOG_DIR/newsletter-workers.pid"
    sleep 1
done

log "All $WORKER_COUNT worker(s) spawned successfully"
log "Logs: storage/logs/email-*.log"
log "Entering keep-alive loop (health check every 60s)"

# ENTERPRISE: Infinite loop to keep container running
while true; do
    sleep 60

    # Check if any workers have died
    ACTIVE_WORKERS=$(pgrep -f 'admin-email-worker.php' | wc -l)
    if [ "$ACTIVE_WORKERS" -eq 0 ]; then
        log "ERROR: All workers died - restarting container"
        exit 1
    fi
done
