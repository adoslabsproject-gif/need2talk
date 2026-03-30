#!/bin/bash
# ENTERPRISE GALAXY: Telegram Tables Cleanup
# Cleans telegram_messages and telegram_log_deliveries older than 30 days

php /var/www/html/scripts/crons/cleanup-telegram.php
