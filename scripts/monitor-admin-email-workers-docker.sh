#!/bin/bash

###############################################################################
# ENTERPRISE GALAXY: Admin Email Workers Monitor Script (Docker)
#
# Real-time monitoring of admin email workers in Docker container
# Shows queue stats, worker status, and recent log entries
#
# Usage:
#   ./scripts/monitor-admin-email-workers-docker.sh [refresh_seconds]
#
# Default: Refresh every 5 seconds. Press Ctrl+C to exit.
###############################################################################

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Configuration
CONTAINER_NAME="need2talk_php"
LOG_DIR="$PROJECT_ROOT/storage/logs"
REFRESH_INTERVAL="${1:-5}"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Check if Docker container is running
if ! docker ps | grep -q "$CONTAINER_NAME"; then
    echo -e "${RED}ERROR: Docker container '$CONTAINER_NAME' is not running${NC}"
    exit 1
fi

# Monitor loop
while true; do
    clear

    echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║     ENTERPRISE GALAXY: Admin Email Workers Monitor            ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${BLUE}Timestamp: $(date '+%Y-%m-%d %H:%M:%S')${NC}"
    echo -e "${BLUE}Container: $CONTAINER_NAME${NC}"
    echo -e "${BLUE}Redis DB: 4 (admin emails)${NC}"
    echo ""

    # Worker Status
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}Worker Status${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

    WORKER_PIDS=$(docker exec "$CONTAINER_NAME" pgrep -f "admin-email-worker.php" || echo "")

    if [ -z "$WORKER_PIDS" ]; then
        echo -e "${RED}❌ No workers running${NC}"
        echo ""
        echo "Start workers with: ./scripts/start-admin-email-workers-docker.sh"
    else
        NUM_WORKERS=$(echo "$WORKER_PIDS" | wc -w)
        echo -e "${GREEN}✅ Active workers: $NUM_WORKERS${NC}"
        echo ""

        # Show worker details
        echo "$WORKER_PIDS" | while read -r PID; do
            if [ -n "$PID" ]; then
                WORKER_INFO=$(docker exec "$CONTAINER_NAME" ps -p "$PID" -o pid,etime,rss --no-headers 2>/dev/null || echo "")

                if [ -n "$WORKER_INFO" ]; then
                    PID_NUM=$(echo "$WORKER_INFO" | awk '{print $1}')
                    UPTIME=$(echo "$WORKER_INFO" | awk '{print $2}')
                    MEMORY_KB=$(echo "$WORKER_INFO" | awk '{print $3}')
                    MEMORY_MB=$(echo "scale=1; $MEMORY_KB / 1024" | bc)

                    echo -e "  ${GREEN}●${NC} PID: $PID_NUM | Uptime: $UPTIME | Memory: ${MEMORY_MB} MB"
                fi
            fi
        done
    fi

    echo ""

    # Queue Statistics
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}Queue Statistics${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

    QUEUE_STATS=$(docker exec "$CONTAINER_NAME" php -r "
    require_once '/var/www/html/app/bootstrap.php';
    try {
        \$queue = new \Need2Talk\Services\AdminEmailQueue();
        \$stats = \$queue->getStats();
        echo json_encode(\$stats);
    } catch (Exception \$e) {
        echo json_encode(['error' => \$e->getMessage()]);
    }
    " 2>/dev/null || echo '{"error":"Failed to get stats"}')

    if echo "$QUEUE_STATS" | grep -q '"error"'; then
        ERROR_MSG=$(echo "$QUEUE_STATS" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["error"] ?? "Unknown error";')
        echo -e "${RED}ERROR: $ERROR_MSG${NC}"
    else
        URGENT=$(echo "$QUEUE_STATS" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["urgent"] ?? 0;')
        HIGH=$(echo "$QUEUE_STATS" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["high"] ?? 0;')
        NORMAL=$(echo "$QUEUE_STATS" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["normal"] ?? 0;')
        LOW=$(echo "$QUEUE_STATS" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["low"] ?? 0;')
        TOTAL=$(echo "$QUEUE_STATS" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["total_queued"] ?? 0;')
        PROCESSING=$(echo "$QUEUE_STATS" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["processing"] ?? 0;')
        FAILED=$(echo "$QUEUE_STATS" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["failed"] ?? 0;')

        echo -e "  ${RED}🔥 Urgent:${NC}     $URGENT"
        echo -e "  ${YELLOW}⚡ High:${NC}       $HIGH"
        echo -e "  ${CYAN}📋 Normal:${NC}     $NORMAL"
        echo -e "  ${GREEN}📦 Low:${NC}        $LOW"
        echo ""
        echo -e "  ${BLUE}📊 Total Queued:${NC}  ${YELLOW}$TOTAL${NC}"
        echo -e "  ${YELLOW}⚙️  Processing:${NC}   $PROCESSING"

        if [ "$FAILED" -gt 0 ]; then
            echo -e "  ${RED}❌ Failed:${NC}       $FAILED"
        else
            echo -e "  ${GREEN}✅ Failed:${NC}       $FAILED"
        fi
    fi

    echo ""

    # Recent Activity (from audit table)
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}Recent Activity (Last 5)${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

    docker exec "$CONTAINER_NAME" php -r "
    require_once '/var/www/html/app/bootstrap.php';
    try {
        \$pdo = db_pdo();
        \$stmt = \$pdo->prepare('
            SELECT email_type, status, recipient_email, subject,
                   processing_time_ms, created_at
            FROM admin_email_audit
            ORDER BY created_at DESC
            LIMIT 5
        ');
        \$stmt->execute();
        \$results = \$stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty(\$results)) {
            echo '  No recent activity' . PHP_EOL;
        } else {
            foreach (\$results as \$row) {
                \$status_icon = match(\$row['status']) {
                    'sent' => '✅',
                    'failed' => '❌',
                    'processing' => '⚙️',
                    'queued' => '📬',
                    default => '❓'
                };

                \$time = \$row['processing_time_ms'] ? \$row['processing_time_ms'] . 'ms' : 'N/A';

                echo sprintf(
                    '  %s %s | %s | %s | %s' . PHP_EOL,
                    \$status_icon,
                    \$row['status'],
                    \$row['email_type'],
                    substr(\$row['recipient_email'], 0, 25),
                    \$time
                );
            }
        }
    } catch (Exception \$e) {
        echo '  ERROR: ' . \$e->getMessage() . PHP_EOL;
    }
    " 2>/dev/null || echo "  ERROR: Failed to fetch recent activity"

    echo ""

    # Recent Log Entries
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}Recent Log Entries (Last 3)${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

    if [ -f "$LOG_DIR/admin-email-worker-1.log" ]; then
        docker exec "$CONTAINER_NAME" tail -n 3 "/var/www/html/storage/logs/admin-email-worker-1.log" 2>/dev/null | while IFS= read -r line; do
            echo "  $line"
        done
    else
        echo "  No log file found"
    fi

    echo ""
    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "Refreshing in $REFRESH_INTERVAL seconds... (Press Ctrl+C to exit)"
    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

    sleep "$REFRESH_INTERVAL"
done
