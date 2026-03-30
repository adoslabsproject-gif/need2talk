#!/bin/sh
###############################################################################
# Audio Workers Docker Spawner - Enterprise Galaxy
#
# Spawns audio upload workers inside Docker container
# Auto-scaling support: Spawn count controlled by docker-compose scale
#
# Workers Configuration:
# - Batch size: 50 files per cycle
# - Cycle interval: 30 seconds
# - Max retries: 3
# - No max runtime (infinite loop with graceful shutdown)
#
# ENTERPRISE STANDARDS:
# - PSR-12 compliant scripts
# - Professional logging
# - Zero downtime audio processing
###############################################################################

echo "═══════════════════════════════════════════════════════"
echo "🚀 ENTERPRISE GALAXY AUDIO WORKERS SPAWNER"
echo "═══════════════════════════════════════════════════════"
echo "Starting audio upload worker..."
echo "═══════════════════════════════════════════════════════"
echo ""

# Start worker in foreground (infinite loop handles runtime)
php /var/www/html/scripts/audio-upload-worker.php

# If worker exits, this script exits and Docker restarts it
echo "Worker exited, container will restart..."
