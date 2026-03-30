#!/bin/sh
# ENTERPRISE GALAXY: Room Inactivity Kick Cron Wrapper
# Kick inactive users from chat rooms
cd /var/www/html
php /var/www/html/scripts/cron-room-inactivity-kick.php
