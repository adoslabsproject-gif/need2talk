#!/bin/sh
export PATH="/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_ROOT"
php "$SCRIPT_DIR/cleanup-redis.php" 2>&1
