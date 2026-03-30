#!/bin/sh
# ENTERPRISE GALAXY: Telegram Alerts Cron Wrapper
# Send real-time alerts to Telegram for security/error events
cd /var/www/html
php /var/www/html/scripts/crons/telegram-alerts.php
