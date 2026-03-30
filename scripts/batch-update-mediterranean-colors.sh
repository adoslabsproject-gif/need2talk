#!/bin/bash
# BATCH UPDATE: Mediterranean Calm Colors
# Aggiorna TUTTI i file PHP con la nuova palette

FILES=(
    "app/Views/pages/about.php"
    "app/Views/legal/terms.php"
    "app/Views/legal/privacy.php"
    "app/Views/auth/login.php"
    "app/Views/auth/register.php"
    "app/Views/auth/forgot-password.php"
    "app/Views/auth/reset-password.php"
    "app/Views/auth/verify-email-sent.php"
    "app/Views/auth/resend-verification.php"
    "app/Views/auth/verify-email.php"
    "app/Views/pages/help/faq.php"
    "app/Views/pages/help/guide.php"
    "app/Views/pages/help/safety.php"
    "app/Views/pages/legal/report.php"
)

for file in "${FILES[@]}"; do
    if [ -f "$file" ]; then
        echo "🔄 Updating: $file"

        # Background colors
        sed -i.bak 's/bg-gray-800\/50/bg-brand-white/g' "$file"
        sed -i.bak 's/bg-gray-900\/95/bg-brand-white\/95/g' "$file"
        sed -i.bak 's/bg-gray-900/bg-brand-beige/g' "$file"

        # Borders
        sed -i.bak 's/border-purple-500\/20/border-brand-ocean\/20/g' "$file"
        sed -i.bak 's/border-purple-500\/30/border-brand-ocean\/30/g' "$file"
        sed -i.bak 's/border-purple-400\/50/border-brand-ocean\/50/g' "$file"
        sed -i.bak 's/border-purple-400/border-brand-ocean/g' "$file"

        # Shadows
        sed -i.bak 's/shadow-purple-500\/10/shadow-brand-ocean\/10/g' "$file"
        sed -i.bak 's/shadow-purple-500\/25/shadow-accent-terracotta\/25/g' "$file"
        sed -i.bak 's/shadow-purple-500\/30/shadow-accent-terracotta\/30/g' "$file"

        # Text colors
        sed -i.bak 's/text-purple-400/text-brand-ocean/g' "$file"
        sed -i.bak 's/text-purple-300/text-brand-teal/g' "$file"
        sed -i.bak 's/text-purple-200/text-brand-teal/g' "$file"
        sed -i.bak 's/text-gray-300/text-neutral-gray/g' "$file"
        sed -i.bak 's/text-white/text-neutral-charcoal/g' "$file"  # Headings only

        # Gradients
        sed -i.bak 's/from-purple-400 via-pink-400 to-purple-400/from-brand-teal via-brand-ocean to-brand-teal/g' "$file"
        sed -i.bak 's/from-purple-600 to-pink-600/from-accent-terracotta to-accent-terracotta\/90/g' "$file"
        sed -i.bak 's/hover:from-purple-700 hover:to-pink-700/hover:from-accent-terracotta\/90 hover:to-accent-terracotta\/80/g' "$file"

        # Focus states
        sed -i.bak 's/focus:border-purple-400/focus:border-brand-ocean/g' "$file"
        sed -i.bak 's/focus:ring-purple-400\/30/focus:ring-brand-ocean\/30/g' "$file"
        sed -i.bak 's/ring-purple-200/ring-brand-ocean\/20/g' "$file"

        # Hover states
        sed -i.bak 's/hover:text-purple-400/hover:text-brand-ocean/g' "$file"
        sed -i.bak 's/hover:bg-purple-500\/10/hover:bg-brand-ocean\/10/g' "$file"

        # Primary/Secondary colors (OLD - should not exist but just in case)
        sed -i.bak 's/primary-500/brand-ocean/g' "$file"
        sed -i.bak 's/primary-600/brand-teal/g' "$file"
        sed -i.bak 's/secondary-500/brand-ocean/g' "$file"

        # Remove backup files
        rm -f "${file}.bak"

        echo "✅ Updated: $file"
    else
        echo "⚠️  File not found: $file"
    fi
done

echo ""
echo "🎨 Mediterranean Calm color update complete!"
echo "📊 Updated ${#FILES[@]} files"
