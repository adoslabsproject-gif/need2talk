#!/bin/bash
################################################################################
# NEED2TALK - MONITOR AUDIO WORKERS
################################################################################
#
# Real-time monitoring of audio workers status and metrics
#
# USAGE:
#   ./scripts/monitor-audio-workers.sh
#
################################################################################

set -e

echo "═══════════════════════════════════════════════════════════════"
echo "  NEED2TALK - AUDIO WORKERS MONITORING"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Docker status
echo "🐳 Docker Containers Status:"
docker compose ps audio_worker
echo ""

# Count active workers
ACTIVE_WORKERS=$(docker compose ps audio_worker | grep -c "Up" || echo "0")
echo "📊 Active Workers: $ACTIVE_WORKERS"
echo ""

# Redis heartbeats
echo "💓 Worker Heartbeats (Redis):"
docker exec need2talk_redis_master redis-cli -a YOUR_REDIS_PASSWORD --no-auth-warning KEYS "worker:audio:*:heartbeat" | while read -r key; do
    if [ ! -z "$key" ]; then
        HEARTBEAT=$(docker exec need2talk_redis_master redis-cli -a YOUR_REDIS_PASSWORD --no-auth-warning GET "$key")
        echo "   $key"
        echo "   $HEARTBEAT" | jq '.' 2>/dev/null || echo "   $HEARTBEAT"
        echo ""
    fi
done

# Queue status (ENTERPRISE: PostgreSQL)
echo "📦 Queue Status:"
QUEUE_COUNT=$(docker exec need2talk_postgres psql -U need2talk -d need2talk -t -c "SELECT COUNT(*) FROM audio_files WHERE status='processing'" 2>/dev/null | tr -d ' ' || echo "N/A")
ACTIVE_COUNT=$(docker exec need2talk_postgres psql -U need2talk -d need2talk -t -c "SELECT COUNT(*) FROM audio_files WHERE status='active'" 2>/dev/null | tr -d ' ' || echo "N/A")
FAILED_COUNT=$(docker exec need2talk_postgres psql -U need2talk -d need2talk -t -c "SELECT COUNT(*) FROM audio_files WHERE status='failed'" 2>/dev/null | tr -d ' ' || echo "N/A")

echo "   Processing: $QUEUE_COUNT files"
echo "   Active:     $ACTIVE_COUNT files"
echo "   Failed:     $FAILED_COUNT files"
echo ""

# Health status
echo "🏥 Health Status:"
docker compose ps audio_worker --format "table {{.Name}}\t{{.Status}}\t{{.Health}}"
echo ""

# Resource usage
echo "💻 Resource Usage:"
docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}" $(docker compose ps -q audio_worker 2>/dev/null) 2>/dev/null || echo "   No workers running"
echo ""

echo "═══════════════════════════════════════════════════════════════"
echo ""
echo "📝 View live logs:"
echo "   docker compose logs -f audio_worker"
echo ""
echo "⚖️  Scale workers:"
echo "   ./scripts/scale-audio-workers.sh <count>"
echo ""
echo "🔄 Refresh monitoring:"
echo "   watch -n 5 ./scripts/monitor-audio-workers.sh"
echo ""
