#!/bin/bash
#
# ENTERPRISE GALAXY: Fix All Permissions Script
# need2talk.it - v10.183
#
# Usage:
#   ./scripts/fix-permissions.sh          # Fix all permissions
#   ./scripts/fix-permissions.sh --check  # Only check, don't fix
#   ./scripts/fix-permissions.sh --verbose # Verbose output
#
# IMPORTANT: Run this script from the project root or on the server at /var/www/need2talk
#
# Docker UID Mapping:
#   - Host www-data: uid=33
#   - Container www (PHP-FPM): uid=1000
#   - Files need to be owned by 1000:1000 for PHP container to write
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PROJECT_ROOT="${PROJECT_ROOT:-/var/www/need2talk}"
PHP_USER="1000"      # Container www user UID
PHP_GROUP="1000"     # Container www group GID
WEB_USER="www-data"  # Host web user (for reference)

# Flags
CHECK_ONLY=false
VERBOSE=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --check)
            CHECK_ONLY=true
            ;;
        --verbose|-v)
            VERBOSE=true
            ;;
        --help|-h)
            echo "Usage: $0 [--check] [--verbose]"
            echo "  --check    Only check permissions, don't fix"
            echo "  --verbose  Show detailed output"
            exit 0
            ;;
    esac
done

echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}  ENTERPRISE GALAXY: Permissions Fixer${NC}"
echo -e "${BLUE}  need2talk.it - $(date '+%Y-%m-%d %H:%M:%S')${NC}"
echo -e "${BLUE}============================================${NC}"
echo ""

if [ ! -d "$PROJECT_ROOT" ]; then
    echo -e "${RED}ERROR: Project root not found: $PROJECT_ROOT${NC}"
    exit 1
fi

cd "$PROJECT_ROOT"

# Counter for issues
ISSUES=0

log_info() {
    if [ "$VERBOSE" = true ]; then
        echo -e "${BLUE}[INFO]${NC} $1"
    fi
}

log_fix() {
    echo -e "${GREEN}[FIX]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
    ISSUES=$((ISSUES + 1))
}

log_ok() {
    if [ "$VERBOSE" = true ]; then
        echo -e "${GREEN}[OK]${NC} $1"
    fi
}

fix_ownership() {
    local path="$1"
    local owner="$2"
    local recursive="${3:-false}"

    if [ ! -e "$path" ]; then
        log_warn "Path not found: $path"
        return
    fi

    current_owner=$(stat -c '%u:%g' "$path" 2>/dev/null || stat -f '%u:%g' "$path" 2>/dev/null)

    if [ "$current_owner" != "$owner" ]; then
        if [ "$CHECK_ONLY" = true ]; then
            log_warn "$path has wrong owner: $current_owner (expected $owner)"
        else
            if [ "$recursive" = true ]; then
                chown -R "$owner" "$path"
            else
                chown "$owner" "$path"
            fi
            log_fix "Fixed ownership: $path -> $owner"
        fi
    else
        log_ok "$path ownership OK ($owner)"
    fi
}

fix_permissions() {
    local path="$1"
    local perms="$2"
    local recursive="${3:-false}"

    if [ ! -e "$path" ]; then
        log_warn "Path not found: $path"
        return
    fi

    if [ "$CHECK_ONLY" = true ]; then
        current_perms=$(stat -c '%a' "$path" 2>/dev/null || stat -f '%Lp' "$path" 2>/dev/null)
        if [ "$current_perms" != "$perms" ]; then
            log_warn "$path has wrong permissions: $current_perms (expected $perms)"
        else
            log_ok "$path permissions OK ($perms)"
        fi
    else
        if [ "$recursive" = true ]; then
            chmod -R "$perms" "$path"
        else
            chmod "$perms" "$path"
        fi
        log_info "Set permissions: $path -> $perms"
    fi
}

echo -e "${YELLOW}1. Fixing directory structure...${NC}"
echo ""

# ============================================================================
# DIRECTORIES - Must be writable by PHP (uid 1000)
# ============================================================================

# Storage directories (PHP writes here)
WRITABLE_DIRS=(
    "storage"
    "storage/cache"
    "storage/logs"
    "storage/locks"
    "storage/debugbar"
    "storage/sessions"
    "storage/temp"
    "storage/temp/dm_audio_queue"
    "storage/uploads"
    "storage/uploads/audio"
    "storage/uploads/avatars"
    "storage/uploads/temp"
    "storage/newsletter_queue"
    "storage/geoip"
    "public/uploads"
    "public/uploads/audio"
    "public/uploads/avatars"
)

for dir in "${WRITABLE_DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        if [ "$CHECK_ONLY" = false ]; then
            mkdir -p "$dir"
            log_fix "Created directory: $dir"
        else
            log_warn "Directory missing: $dir"
        fi
    fi
    fix_ownership "$dir" "$PHP_USER:$PHP_GROUP"
    fix_permissions "$dir" "775"
done

# Special directories that need 777 (shared between multiple containers)
SHARED_DIRS=(
    "storage/temp/dm_audio_queue"
    "storage/locks"
)

for dir in "${SHARED_DIRS[@]}"; do
    if [ -d "$dir" ]; then
        fix_permissions "$dir" "777"
    fi
done

echo ""
echo -e "${YELLOW}2. Fixing file permissions...${NC}"
echo ""

# ============================================================================
# FILES - PHP Code (readable, not writable in production)
# ============================================================================

# PHP files - 664 (owner+group read/write, others read)
if [ "$CHECK_ONLY" = false ]; then
    find app -name "*.php" -type f -exec chmod 664 {} \; 2>/dev/null || true
    find config -name "*.php" -type f -exec chmod 664 {} \; 2>/dev/null || true
    find routes -name "*.php" -type f -exec chmod 664 {} \; 2>/dev/null || true
    find public -name "*.php" -type f -exec chmod 664 {} \; 2>/dev/null || true
    log_fix "Set PHP files to 664"
fi

# Scripts - 755 (executable)
if [ "$CHECK_ONLY" = false ]; then
    find scripts -name "*.sh" -type f -exec chmod 755 {} \; 2>/dev/null || true
    find scripts -name "*.php" -type f -exec chmod 755 {} \; 2>/dev/null || true
    log_fix "Set scripts to 755"
fi

# ============================================================================
# SPECIAL FILES
# ============================================================================

echo ""
echo -e "${YELLOW}3. Fixing special files...${NC}"
echo ""

# .env file - sensitive, restricted access
if [ -f ".env" ]; then
    fix_ownership ".env" "$PHP_USER:$PHP_GROUP"
    fix_permissions ".env" "640"
fi

# docker-compose.yml
if [ -f "docker-compose.yml" ]; then
    fix_ownership "docker-compose.yml" "$PHP_USER:$PHP_GROUP"
    fix_permissions "docker-compose.yml" "664"
fi

# composer files
for file in composer.json composer.lock; do
    if [ -f "$file" ]; then
        fix_ownership "$file" "$PHP_USER:$PHP_GROUP"
        fix_permissions "$file" "664"
    fi
done

# Flag files (autostart controls)
FLAG_FILES=(
    "storage/newsletter_auto_restart_disabled.flag"
    "storage/dm_audio_auto_restart_disabled.flag"
    "storage/overlay_auto_restart_disabled.flag"
)

for flag in "${FLAG_FILES[@]}"; do
    if [ -f "$flag" ]; then
        fix_ownership "$flag" "$PHP_USER:$PHP_GROUP"
        fix_permissions "$flag" "666"
    fi
done

# ============================================================================
# ASSETS (public readable)
# ============================================================================

echo ""
echo -e "${YELLOW}4. Fixing assets permissions...${NC}"
echo ""

# CSS/JS files - 644 (readable by all)
if [ "$CHECK_ONLY" = false ]; then
    find public/assets -name "*.css" -type f -exec chmod 644 {} \; 2>/dev/null || true
    find public/assets -name "*.js" -type f -exec chmod 644 {} \; 2>/dev/null || true
    find public/assets -name "*.png" -type f -exec chmod 644 {} \; 2>/dev/null || true
    find public/assets -name "*.jpg" -type f -exec chmod 644 {} \; 2>/dev/null || true
    find public/assets -name "*.svg" -type f -exec chmod 644 {} \; 2>/dev/null || true
    find public/assets -name "*.ico" -type f -exec chmod 644 {} \; 2>/dev/null || true
    find public/assets -name "*.woff*" -type f -exec chmod 644 {} \; 2>/dev/null || true
    log_fix "Set assets to 644"
fi

# Asset directories - 755
if [ "$CHECK_ONLY" = false ]; then
    find public/assets -type d -exec chmod 755 {} \; 2>/dev/null || true
    log_fix "Set asset directories to 755"
fi

# ============================================================================
# VENDOR (Composer dependencies)
# ============================================================================

echo ""
echo -e "${YELLOW}5. Fixing vendor permissions...${NC}"
echo ""

if [ -d "vendor" ]; then
    fix_ownership "vendor" "$PHP_USER:$PHP_GROUP" true
    if [ "$CHECK_ONLY" = false ]; then
        find vendor -type d -exec chmod 755 {} \; 2>/dev/null || true
        find vendor -type f -exec chmod 644 {} \; 2>/dev/null || true
        # Executable binaries
        if [ -d "vendor/bin" ]; then
            chmod 755 vendor/bin/* 2>/dev/null || true
        fi
        log_fix "Set vendor permissions"
    fi
fi

# ============================================================================
# SUMMARY
# ============================================================================

echo ""
echo -e "${BLUE}============================================${NC}"

if [ "$CHECK_ONLY" = true ]; then
    if [ $ISSUES -gt 0 ]; then
        echo -e "${YELLOW}  Check complete: $ISSUES issues found${NC}"
        echo -e "${YELLOW}  Run without --check to fix${NC}"
    else
        echo -e "${GREEN}  Check complete: All permissions OK!${NC}"
    fi
else
    echo -e "${GREEN}  Permissions fixed successfully!${NC}"
fi

echo -e "${BLUE}============================================${NC}"
echo ""

# ============================================================================
# PERMISSION REFERENCE
# ============================================================================

if [ "$VERBOSE" = true ]; then
    echo -e "${BLUE}Permission Reference:${NC}"
    echo ""
    echo "  Directories:"
    echo "    775 = rwxrwxr-x (owner+group full, others read+execute)"
    echo "    777 = rwxrwxrwx (all full - shared between containers)"
    echo "    755 = rwxr-xr-x (owner full, others read+execute)"
    echo ""
    echo "  Files:"
    echo "    664 = rw-rw-r-- (owner+group read/write, others read)"
    echo "    666 = rw-rw-rw- (all read/write - flag files)"
    echo "    644 = rw-r--r-- (owner read/write, others read)"
    echo "    640 = rw-r----- (owner read/write, group read - .env)"
    echo "    755 = rwxr-xr-x (executable scripts)"
    echo ""
    echo "  Docker UID Mapping:"
    echo "    Container www user: uid=1000"
    echo "    Host www-data:      uid=33"
    echo "    Files need uid 1000 for PHP container to write"
    echo ""
fi

exit $ISSUES
