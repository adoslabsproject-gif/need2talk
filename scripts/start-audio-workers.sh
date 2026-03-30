#!/bin/bash
################################################################################
# NEED2TALK - START AUDIO WORKERS
################################################################################
#
# Start audio upload workers with scaling support
#
# USAGE:
#   ./scripts/start-audio-workers.sh [worker_count]
#
# EXAMPLES:
#   ./scripts/start-audio-workers.sh      # Start 1 worker (default)
#   ./scripts/start-audio-workers.sh 12   # Start 12 workers (max throughput)
#
################################################################################

set -e

WORKER_COUNT=${1:-1}
MAX_WORKERS=12

# Validation
if [ "$WORKER_COUNT" -lt 1 ] || [ "$WORKER_COUNT" -gt "$MAX_WORKERS" ]; then
    echo "❌ Error: worker_count must be between 1 and $MAX_WORKERS"
    exit 1
fi

echo "🚀 Starting audio workers..."
echo "   Workers: $WORKER_COUNT"
echo ""

# Build audio-worker image
echo "📦 Building audio-worker Docker image..."
docker compose build audio_worker

# Start workers with scaling
echo "🐳 Starting $WORKER_COUNT audio worker container(s)..."
docker compose up -d --scale audio_worker=$WORKER_COUNT audio_worker

# Wait for startup
sleep 3

# Check status
echo ""
echo "✅ Audio workers started successfully!"
echo ""
echo "📊 Status:"
docker compose ps audio_worker

echo ""
echo "📝 View logs:"
echo "   docker compose logs -f audio_worker"
echo ""
echo "🔍 Monitor workers:"
echo "   ./scripts/monitor-audio-workers.sh"
