#!/bin/bash

##############################################################################
# NEED2TALK - Clear Rate Limits Script (PostgreSQL Docker)
#
# This script clears all rate limiting data from Redis and PostgreSQL
# Use this for testing or when rate limits need to be reset
#
# Usage: ./scripts/clear-rate-limits.sh
##############################################################################

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${YELLOW}🧹 NEED2TALK - Rate Limit Cleanup Script (PostgreSQL Docker)${NC}"
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# Docker containers
REDIS_CONTAINER="need2talk_redis_master"
POSTGRES_CONTAINER="need2talk_postgres"

# Redis settings
REDIS_RATELIMIT_DB="3"

# PostgreSQL settings (from .env)
DB_NAME="${DB_NAME:-need2talk}"
DB_USER="need2talk"

echo -e "${GREEN}📊 Checking rate limit keys...${NC}"

# Count keys in rate limit DB
KEYS_COUNT=$(docker exec "$REDIS_CONTAINER" redis-cli -n $REDIS_RATELIMIT_DB DBSIZE | grep -oE '[0-9]+' || echo "0")
echo -e "   Found ${YELLOW}$KEYS_COUNT${NC} keys in rate limit database (DB $REDIS_RATELIMIT_DB)"

# Show some rate limit keys if present
if [ "$KEYS_COUNT" -gt 0 ]; then
    echo ""
    echo -e "${GREEN}📋 Sample rate limit keys:${NC}"
    docker exec "$REDIS_CONTAINER" redis-cli -n $REDIS_RATELIMIT_DB KEYS "*rate*" | head -10
fi

echo ""
echo -e "${GREEN}📊 Checking PostgreSQL rate limit tables...${NC}"

# Check PostgreSQL bans
BANS_COUNT=$(docker exec "$POSTGRES_CONTAINER" psql -U "$DB_USER" "$DB_NAME" -t -c "SELECT COUNT(*) FROM user_rate_limit_bans" 2>/dev/null | tr -d ' ' || echo "0")
VIOLATIONS_COUNT=$(docker exec "$POSTGRES_CONTAINER" psql -U "$DB_USER" "$DB_NAME" -t -c "SELECT COUNT(*) FROM user_rate_limit_violations" 2>/dev/null | tr -d ' ' || echo "0")
LOG_COUNT=$(docker exec "$POSTGRES_CONTAINER" psql -U "$DB_USER" "$DB_NAME" -t -c "SELECT COUNT(*) FROM user_rate_limit_log" 2>/dev/null | tr -d ' ' || echo "0")

echo -e "   Found ${YELLOW}$BANS_COUNT${NC} active bans"
echo -e "   Found ${YELLOW}$VIOLATIONS_COUNT${NC} violations"
echo -e "   Found ${YELLOW}$LOG_COUNT${NC} log entries"

echo ""
echo -e "${YELLOW}⚠️  This will clear ALL rate limiting data!${NC}"
echo -e "   - Redis: Email verification rate limits"
echo -e "   - Redis: IP-based rate limits"
echo -e "   - PostgreSQL: Active bans"
echo -e "   - PostgreSQL: Violation records"
echo -e "   - PostgreSQL: Rate limit logs"
echo ""

# Ask for confirmation
read -p "Are you sure you want to proceed? (y/N): " -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}❌ Aborted${NC}"
    exit 0
fi

echo ""
echo -e "${GREEN}🧹 Clearing rate limits...${NC}"

# Clear rate limit database
docker exec "$REDIS_CONTAINER" redis-cli -n $REDIS_RATELIMIT_DB FLUSHDB > /dev/null 2>&1

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Rate limit database cleared successfully!${NC}"
else
    echo -e "${RED}❌ Error clearing rate limit database${NC}"
    exit 1
fi

# Also clear any rate limit keys in default DB (DB 0)
echo -e "${GREEN}🧹 Checking default database (DB 0) for rate limit keys...${NC}"
RATE_KEYS_DB0=$(docker exec "$REDIS_CONTAINER" redis-cli -n 0 KEYS "*rate*" | wc -l | tr -d ' ')

if [ "$RATE_KEYS_DB0" -gt 0 ]; then
    echo -e "   Found ${YELLOW}$RATE_KEYS_DB0${NC} rate limit keys in DB 0"
    docker exec "$REDIS_CONTAINER" redis-cli -n 0 KEYS "*rate*" | while read key; do
        docker exec "$REDIS_CONTAINER" redis-cli -n 0 DEL "$key" > /dev/null 2>&1
    done
    echo -e "${GREEN}✅ Removed rate limit keys from DB 0${NC}"
else
    echo -e "   No rate limit keys found in DB 0"
fi

# Clear PostgreSQL rate limit tables
echo ""
echo -e "${GREEN}🧹 Clearing PostgreSQL rate limit tables...${NC}"

docker exec "$POSTGRES_CONTAINER" psql -U "$DB_USER" "$DB_NAME" -c "DELETE FROM user_rate_limit_bans" 2>/dev/null
[ $? -eq 0 ] && echo -e "${GREEN}✅ Cleared user_rate_limit_bans${NC}" || echo -e "${YELLOW}⚠️  Could not clear user_rate_limit_bans${NC}"

docker exec "$POSTGRES_CONTAINER" psql -U "$DB_USER" "$DB_NAME" -c "DELETE FROM user_rate_limit_violations" 2>/dev/null
[ $? -eq 0 ] && echo -e "${GREEN}✅ Cleared user_rate_limit_violations${NC}" || echo -e "${YELLOW}⚠️  Could not clear user_rate_limit_violations${NC}"

docker exec "$POSTGRES_CONTAINER" psql -U "$DB_USER" "$DB_NAME" -c "DELETE FROM user_rate_limit_log WHERE action_type = 'email_verification'" 2>/dev/null
[ $? -eq 0 ] && echo -e "${GREEN}✅ Cleared user_rate_limit_log (email_verification only)${NC}" || echo -e "${YELLOW}⚠️  Could not clear user_rate_limit_log${NC}"

docker exec "$POSTGRES_CONTAINER" psql -U "$DB_USER" "$DB_NAME" -c "DELETE FROM user_rate_limit_alerts" 2>/dev/null
[ $? -eq 0 ] && echo -e "${GREEN}✅ Cleared user_rate_limit_alerts${NC}" || echo -e "${YELLOW}⚠️  Could not clear user_rate_limit_alerts${NC}"

docker exec "$POSTGRES_CONTAINER" psql -U "$DB_USER" "$DB_NAME" -c "DELETE FROM user_rate_limit_monitor" 2>/dev/null
[ $? -eq 0 ] && echo -e "${GREEN}✅ Cleared user_rate_limit_monitor${NC}" || echo -e "${YELLOW}⚠️  Could not clear user_rate_limit_monitor${NC}"

echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ Rate limit cleanup completed!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "${YELLOW}📝 Summary:${NC}"
echo -e "   - Redis rate limit DB (DB $REDIS_RATELIMIT_DB): ${GREEN}cleared${NC}"
echo -e "   - Redis default DB (DB 0) rate keys: ${GREEN}removed${NC}"
echo -e "   - PostgreSQL rate limit tables: ${GREEN}cleared${NC}"
echo ""
echo -e "${GREEN}You can now test email sending without rate limit restrictions.${NC}"
echo ""
