#!/bin/bash
#
# Migrate all public views from Evening Warmth to Midnight Aurora palette
# Single Source of Truth: tailwind.config.js colors
#

set -e

echo "🎨 Migrating public views to Midnight Aurora palette..."
echo ""

# Find all public views
FILES=$(find app/Views/auth app/Views/pages app/Views/legal -name "*.php" -type f 2>/dev/null)

# Counter
COUNT=0

for FILE in $FILES; do
    echo "Processing: $FILE"

    # Background colors
    sed -i '' 's/brand-beige/brand-midnight/g' "$FILE"
    sed -i '' 's/brand-white/brand-charcoal/g' "$FILE"

    # Brand colors (primary → violet, secondary → purple)
    sed -i '' 's/brand-teal/accent-violet/g' "$FILE"
    sed -i '' 's/brand-ocean/accent-purple/g' "$FILE"

    # Accent colors
    sed -i '' 's/accent-terracotta/energy-pink/g' "$FILE"
    sed -i '' 's/accent-sage/cool-cyan/g' "$FILE"
    sed -i '' 's/accent-honey/accent-lavender/g' "$FILE"

    # Neutrals (text colors - IMPORTANT for visibility)
    # neutral-charcoal was dark text on light bg → now neutral-white (light text on dark bg)
    sed -i '' 's/text-neutral-charcoal/text-neutral-white/g' "$FILE"
    sed -i '' 's/neutral-gray/neutral-silver/g' "$FILE"
    sed -i '' 's/neutral-light/neutral-darkGray/g' "$FILE"

    # Gradients
    sed -i '' 's/from-brand-beige via-white to-brand-beige/from-brand-midnight via-brand-slate to-brand-midnight/g' "$FILE"
    sed -i '' 's/from-brand-teal to-brand-ocean/from-accent-violet to-accent-purple/g' "$FILE"
    sed -i '' 's/from-brand-teal via-brand-ocean to-brand-teal/from-accent-violet via-cool-cyan to-accent-lavender/g' "$FILE"

    # Shadows
    sed -i '' 's/shadow-brand-teal/shadow-accent-violet/g' "$FILE"
    sed -i '' 's/shadow-brand-ocean/shadow-accent-purple/g' "$FILE"
    sed -i '' 's/shadow-accent-terracotta/shadow-energy-pink/g' "$FILE"

    # Borders
    sed -i '' 's/border-brand-ocean/border-accent-violet/g' "$FILE"
    sed -i '' 's/border-brand-teal/border-accent-purple/g' "$FILE"
    sed -i '' 's/border-neutral-light/border-neutral-darkGray/g' "$FILE"

    # Rings (focus states)
    sed -i '' 's/ring-brand-ocean/ring-accent-violet/g' "$FILE"
    sed -i '' 's/ring-brand-teal/ring-accent-purple/g' "$FILE"

    COUNT=$((COUNT + 1))
done

echo ""
echo "✅ Migrated $COUNT files to Midnight Aurora palette"
echo ""
echo "Next steps:"
echo "1. Review changes: git diff app/Views"
echo "2. Upload to server: scp -r app/Views root@YOUR_SERVER_IP:/var/www/need2talk/app/"
echo "3. Hard refresh browser: Cmd+Shift+R"
