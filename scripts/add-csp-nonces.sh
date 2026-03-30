#!/bin/bash

# ==============================================================================
# CSP Nonce Addition Script - Enterprise Galaxy
# ==============================================================================
# Adds nonce="<?= csp_nonce() ?>" to all inline <script> and <style> tags
#
# SAFETY FEATURES:
# - Only modifies files in app/Views
# - Creates .backup files before modification
# - Skips tags that already have nonce
# - Skips external scripts (<script src="">)
# - Skips external styles (<link href="">)
#
# USAGE:
#   ./scripts/add-csp-nonces.sh
# ==============================================================================

set -e  # Exit on error

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}🚀 CSP Nonce Addition - Enterprise Galaxy${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# Project root
PROJECT_ROOT="/var/www/need2talk"
VIEWS_DIR="${PROJECT_ROOT}/app/Views"

# Counters
FILES_MODIFIED=0
SCRIPTS_MODIFIED=0
STYLES_MODIFIED=0

# Find all PHP files in Views directory
find "${VIEWS_DIR}" -name "*.php" -type f | while read -r file; do
    echo -e "${YELLOW}Processing: ${file}${NC}"

    # Create backup
    cp "$file" "${file}.backup"

    MODIFIED=false

    # ============================================
    # 1. Add nonce to INLINE <script> tags ONLY
    # ============================================
    # Pattern: <script> or <script type="..."> but NOT <script src="...">
    # Skip if already has nonce or if external script (src=)

    if grep -q '<script[^>]*>' "$file"; then
        # Use Perl for more sophisticated regex (BSD sed is limited)
        # Match: <script not containing src= and not containing nonce=
        perl -i.tmp -pe 's|<script(?![^>]*src=)(?![^>]*nonce=)([^>]*)>|<script nonce="<?= csp_nonce() ?>"\1>|g' "$file"

        # Check if file changed
        if ! cmp -s "$file" "$file.tmp"; then
            SCRIPT_COUNT=$(grep -o '<script nonce=' "$file" | wc -l | tr -d ' ')
            SCRIPTS_MODIFIED=$((SCRIPTS_MODIFIED + SCRIPT_COUNT))
            MODIFIED=true
            echo -e "  ✅ Added nonce to ${SCRIPT_COUNT} <script> tags"
        fi
        rm -f "$file.tmp"
    fi

    # ============================================
    # 2. Add nonce to INLINE <style> tags ONLY
    # ============================================
    # Skip if already has nonce

    if grep -q '<style[^>]*>' "$file"; then
        # Use Perl for more sophisticated regex
        # Match: <style not containing nonce=
        perl -i.tmp -pe 's|<style(?![^>]*nonce=)([^>]*)>|<style nonce="<?= csp_nonce() ?>"\1>|g' "$file"

        # Check if file changed
        if ! cmp -s "$file" "$file.tmp"; then
            STYLE_COUNT=$(grep -o '<style nonce=' "$file" | wc -l | tr -d ' ')
            STYLES_MODIFIED=$((STYLES_MODIFIED + STYLE_COUNT))
            MODIFIED=true
            echo -e "  ✅ Added nonce to ${STYLE_COUNT} <style> tags"
        fi
        rm -f "$file.tmp"
    fi

    # ============================================
    # 3. Remove backup if nothing changed
    # ============================================

    if [ "$MODIFIED" = true ]; then
        FILES_MODIFIED=$((FILES_MODIFIED + 1))
    else
        rm "${file}.backup"
        echo -e "  ⏭️  No changes needed"
    fi

    echo ""
done

# ==============================================================================
# Summary
# ==============================================================================

echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ CSP Nonce Addition Complete!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "  Files modified:   ${FILES_MODIFIED}"
echo -e "  Scripts updated:  ${SCRIPTS_MODIFIED}"
echo -e "  Styles updated:   ${STYLES_MODIFIED}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "${YELLOW}📋 Backup files created with .backup extension${NC}"
echo -e "${YELLOW}   Review changes and delete backups when satisfied${NC}"
echo ""
