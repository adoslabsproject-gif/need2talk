#!/bin/bash
################################################################################
# NEED2TALK - ENTERPRISE JS MINIFICATION (COMPLETE)
################################################################################
#
# PURPOSE: Minify ALL JavaScript files with Terser (industry standard)
#
# USAGE:
#   ./scripts/minify-all-js.sh           # Minify all
#   ./scripts/minify-all-js.sh --force   # Force re-minify all
#
################################################################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Config
ASSETS_DIR="public/assets/js"
FORCE=false
VERBOSE=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -f|--force) FORCE=true; shift ;;
        -v|--verbose) VERBOSE=true; shift ;;
        *) shift ;;
    esac
done

# Check terser
if ! command -v npx &> /dev/null; then
    echo -e "${RED}ERROR: npx not found${NC}"
    exit 1
fi

# Statistics
files_processed=0
files_minified=0
files_skipped=0
files_failed=0
total_original=0
total_minified=0

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}🚀 NEED2TALK - TERSER MINIFICATION (ALL FILES)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

################################################################################
# Minify single file
################################################################################
minify_file() {
    local src="$1"
    local min="${src%.js}.min.js"
    local map="${min}.map"

    ((files_processed++))

    # Skip if not newer (unless force)
    if [[ "$FORCE" == false ]] && [[ -f "$min" ]] && [[ "$min" -nt "$src" ]]; then
        ((files_skipped++))
        [[ "$VERBOSE" == true ]] && echo -e "${YELLOW}SKIP${NC} $(basename $src)"
        return 0
    fi

    # Get original size
    local orig_size=$(stat -f%z "$src" 2>/dev/null || stat -c%s "$src" 2>/dev/null)

    # Minify with Terser
    if npx terser "$src" \
        --compress "drop_console=false,drop_debugger=true,pure_funcs=['console.debug']" \
        --mangle \
        --source-map "url='$(basename $map)'" \
        --output "$min" 2>/dev/null; then

        local min_size=$(stat -f%z "$min" 2>/dev/null || stat -c%s "$min" 2>/dev/null)
        local saved=$((orig_size - min_size))
        local percent=$((saved * 100 / orig_size))

        ((files_minified++))
        total_original=$((total_original + orig_size))
        total_minified=$((total_minified + min_size))

        echo -e "${GREEN}✓${NC} $(basename $src) (${orig_size}B → ${min_size}B, -${percent}%)"
    else
        ((files_failed++))
        echo -e "${RED}✗${NC} $(basename $src) - FAILED"
    fi
}

################################################################################
# Find and minify all JS files
################################################################################
cd "$ASSETS_DIR"

# Find all .js files (not .min.js, not in tinymce/node_modules)
echo -e "${BLUE}Finding JavaScript files...${NC}"
echo ""

find . -name "*.js" \
    -not -name "*.min.js" \
    -not -path "./tinymce/*" \
    -not -path "./node_modules/*" \
    -type f | sort | while read file; do
    minify_file "$file"
done

cd - > /dev/null

################################################################################
# Summary
################################################################################
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ MINIFICATION COMPLETE${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "Files processed: $files_processed"
echo -e "Files minified:  ${GREEN}$files_minified${NC}"
echo -e "Files skipped:   ${YELLOW}$files_skipped${NC}"
echo -e "Files failed:    ${RED}$files_failed${NC}"

if [[ $total_original -gt 0 ]]; then
    local saved=$((total_original - total_minified))
    local percent=$((saved * 100 / total_original))
    echo ""
    echo -e "Total original:  $((total_original / 1024)) KB"
    echo -e "Total minified:  $((total_minified / 1024)) KB"
    echo -e "Total saved:     ${GREEN}$((saved / 1024)) KB (-${percent}%)${NC}"
fi
echo ""
