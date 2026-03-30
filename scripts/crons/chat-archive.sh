#!/bin/sh
# ENTERPRISE GALAXY: Chat Archive Cron Wrapper
# Archive old DMs (1 year retention), cleanup resolved reports, VACUUM tables
cd /var/www/html
php /var/www/html/scripts/cron-chat-archive.php
