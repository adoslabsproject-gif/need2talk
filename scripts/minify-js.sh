#!/bin/bash
# Enterprise JS Minification Script
# Minifies all JS files (excluding already minified, tinymce, external, admin)

BASE_DIR="/var/www/need2talk/public/assets/js"
COUNT=0

echo "Starting JS minification..."

find "$BASE_DIR" -name "*.js" -type f | while read file; do
    # Skip already minified files
    if [[ "$file" == *".min.js" ]]; then
        continue
    fi

    # Skip tinymce, external, admin directories
    if [[ "$file" == *"/tinymce/"* ]] || [[ "$file" == *"/external/"* ]] || [[ "$file" == *"/admin/"* ]]; then
        continue
    fi

    # Create minified filename
    minfile="${file%.js}.min.js"

    # Skip if minified version already exists
    if [ -f "$minfile" ]; then
        echo "⏭ Already exists: $(basename $minfile)"
        continue
    fi

    # Minify with terser
    if npx terser "$file" -o "$minfile" -c -m 2>/dev/null; then
        original_size=$(wc -c < "$file")
        minified_size=$(wc -c < "$minfile")
        savings=$((100 - (minified_size * 100 / original_size)))
        echo "✓ $(basename $file) → $(basename $minfile) (-${savings}%)"
        ((COUNT++))
    else
        echo "✗ Failed: $(basename $file)"
    fi
done

echo ""
echo "Minification complete!"
