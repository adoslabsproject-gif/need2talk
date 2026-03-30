#!/bin/sh
# ENTERPRISE GALAXY: DM Cleanup Cron Wrapper
# Clean up soft-deleted direct messages and related data
cd /var/www/html
php /var/www/html/scripts/cron-dm-cleanup.php
