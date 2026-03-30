#!/bin/bash
################################################################################
# NEED2TALK - SCALE AUDIO WORKERS
################################################################################
#
# Scale audio workers up or down dynamically
#
# USAGE:
#   ./scripts/scale-audio-workers.sh <worker_count>
#
# EXAMPLES:
#   ./scripts/scale-audio-workers.sh 12   # Scale to 12 workers (max)
#   ./scripts/scale-audio-workers.sh 1    # Scale down to 1 worker (idle)
#
################################################################################

set -e

WORKER_COUNT=$1
MAX_WORKERS=12

if [ -z "$WORKER_COUNT" ]; then
    echo "❌ Error: worker_count required"
    echo ""
    echo "Usage: $0 <worker_count>"
    echo "Example: $0 12"
    exit 1
fi

# Validation
if [ "$WORKER_COUNT" -lt 1 ] || [ "$WORKER_COUNT" -gt "$MAX_WORKERS" ]; then
    echo "❌ Error: worker_count must be between 1 and $MAX_WORKERS"
    exit 1
fi

echo "⚖️  Scaling audio workers to $WORKER_COUNT..."

# Scale workers
docker compose up -d --scale audio_worker=$WORKER_COUNT audio_worker

# Wait for changes
sleep 2

echo ""
echo "✅ Workers scaled successfully!"
echo ""
echo "📊 Current status:"
docker compose ps audio_worker

echo ""
echo "📈 Throughput capacity: $(($WORKER_COUNT * 100)) files/min"
