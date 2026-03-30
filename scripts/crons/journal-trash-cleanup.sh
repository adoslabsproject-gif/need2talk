#!/bin/sh
# ENTERPRISE GALAXY: Journal Trash Cleanup Cron Wrapper
# Permanently delete soft-deleted journal entries after 30-day retention
cd /var/www/html
php /var/www/html/scripts/cron-journal-trash-cleanup.php
