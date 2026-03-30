#!/bin/sh
# ENTERPRISE GALAXY: Chat Room Cleanup Cron Wrapper
# Auto-close inactive rooms (4h TTL), clean stale presence, trim messages
cd /var/www/html
php /var/www/html/scripts/cron-chat-room-cleanup.php
