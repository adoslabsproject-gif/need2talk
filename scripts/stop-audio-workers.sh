#!/bin/bash
################################################################################
# NEED2TALK - STOP AUDIO WORKERS
################################################################################
#
# Stop all audio upload workers gracefully
#
# USAGE:
#   ./scripts/stop-audio-workers.sh
#
################################################################################

set -e

echo "🛑 Stopping audio workers..."

# Stop workers gracefully (SIGTERM for graceful shutdown)
docker compose stop audio_worker

echo ""
echo "✅ Audio workers stopped successfully!"
echo ""
echo "📊 Final status:"
docker compose ps audio_worker
