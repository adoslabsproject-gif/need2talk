#!/bin/bash
# ENTERPRISE DAILY MAINTENANCE - Auto cleanup vecchi record (PostgreSQL Docker)

POSTGRES_CONTAINER="need2talk_postgres"
DB="${DB_NAME:-need2talk}"
POSTGRES_USER="need2talk"

echo "🧹 Need2Talk - Daily Maintenance $(date)"
echo "=========================================="

# Execute maintenance SQL inside PostgreSQL Docker container
docker exec "$POSTGRES_CONTAINER" psql -U "$POSTGRES_USER" "$DB" << 'EOFSQL'
-- ENTERPRISE GALAXY: PostgreSQL-compatible maintenance (migrated from MySQL)

-- Metriche email (mantieni 7 giorni)
DELETE FROM email_verification_metrics WHERE created_at < NOW() - INTERVAL '7 days';

-- Metriche orarie (mantieni 30 giorni)
DELETE FROM email_metrics_hourly WHERE hour < NOW() - INTERVAL '30 days';

-- Metriche giornaliere (mantieni 90 giorni)
DELETE FROM email_metrics_daily WHERE day < NOW() - INTERVAL '90 days';

-- Sessioni vecchie (mantieni 2 giorni)
DELETE FROM sessions WHERE last_activity < EXTRACT(EPOCH FROM (NOW() - INTERVAL '2 days'));
DELETE FROM user_sessions WHERE last_activity < NOW() - INTERVAL '2 days';

-- Attività sessioni (mantieni 7 giorni)
DELETE FROM session_activities WHERE created_at < NOW() - INTERVAL '7 days';

-- Rate limit log (mantieni 7 giorni)
DELETE FROM user_rate_limit_log WHERE created_at < NOW() - INTERVAL '7 days';

-- ENTERPRISE: PostgreSQL optimization (VACUUM + ANALYZE instead of MySQL OPTIMIZE TABLE)
VACUUM ANALYZE email_verification_metrics;
VACUUM ANALYZE email_metrics_hourly;
VACUUM ANALYZE email_metrics_daily;
VACUUM ANALYZE sessions;
VACUUM ANALYZE user_sessions;

SELECT 'Daily maintenance completata (PostgreSQL)' AS Status;
EOFSQL

echo ""
echo "✅ Maintenance completata - Database PostgreSQL pulito e ottimizzato"
