#!/bin/sh
# ENTERPRISE GALAXY: Chat Partition Maintenance Cron Wrapper
# Create next month partition for direct_messages table (PostgreSQL)
cd /var/www/html
php /var/www/html/scripts/cron-chat-partition.php
