#!/bin/sh
# ENTERPRISE GALAXY: Telegram Daily Logs Cron Wrapper
# Send daily log summary report to Telegram
cd /var/www/html
php /var/www/html/scripts/crons/telegram-daily-logs.php
