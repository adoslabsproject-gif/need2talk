#!/bin/bash

# 🛑 ENTERPRISE GALAXY: Stop Newsletter Worker Container
# Gracefully stops the dedicated newsletter worker Docker container
# Silicon Valley Level: ⭐⭐⭐ | ENTERPRISE GALAXY Level: ⭐⭐⭐⭐⭐⭐⭐⭐⭐⭐

set -e

# ENTERPRISE: Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m'

NEWSLETTER_CONTAINER="need2talk_newsletter_worker"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo ""
echo "${PURPLE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo "${PURPLE}║${NC}      ${CYAN}🛑 ENTERPRISE GALAXY: STOP NEWSLETTER WORKERS 🛑${NC}     ${PURPLE}║${NC}"
echo "${PURPLE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if container exists and is running
if ! docker ps --filter "name=$NEWSLETTER_CONTAINER" --filter "status=running" | grep -q "$NEWSLETTER_CONTAINER"; then
    echo "${YELLOW}⚠️  [INFO]${NC} Newsletter worker container is not running"
    echo "${GREEN}✅ Nothing to stop${NC}"
    echo ""
    exit 0
fi

echo "${CYAN}[STOPPING]${NC} Gracefully stopping newsletter worker container..."

# Graceful stop with 30s timeout
if docker-compose -f "$PROJECT_ROOT/docker-compose.yml" stop -t 30 newsletter_worker &>/dev/null; then
    echo "${GREEN}✅ [SUCCESS]${NC} Newsletter worker container stopped successfully"

    # Verify it's actually stopped
    sleep 2
    if ! docker ps --filter "name=$NEWSLETTER_CONTAINER" | grep -q "$NEWSLETTER_CONTAINER"; then
        echo "${GREEN}✅ [VERIFIED]${NC} Container is no longer running"
    else
        echo "${YELLOW}⚠️  [WARNING]${NC} Container still running (may be restarting)"
    fi
else
    echo "${RED}❌ [ERROR]${NC} Failed to stop newsletter worker container"
    exit 1
fi

echo ""
echo "${PURPLE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo "${PURPLE}║${NC}              ${GREEN}🎉 NEWSLETTER WORKERS STOPPED! 🎉${NC}             ${PURPLE}║${NC}"
echo "${PURPLE}╠══════════════════════════════════════════════════════════════╣${NC}"
echo "${PURPLE}║${NC}  ${CYAN}📋 Restart:${NC} ./scripts/start-newsletter-auto-recovery.sh    ${PURPLE}║${NC}"
echo "${PURPLE}║${NC}  ${CYAN}📊 Monitor:${NC} ./scripts/monitor-newsletter-workers.sh        ${PURPLE}║${NC}"
echo "${PURPLE}║${NC}  ${CYAN}🔄 Docker:${NC} docker-compose up -d newsletter_worker        ${PURPLE}║${NC}"
echo "${PURPLE}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
