#!/bin/bash

###############################################################################
# Audio CDN Secret Key Generator - Enterprise Galaxy
#
# PURPOSE:
# Generate cryptographically secure secret key for signed URL generation
#
# USAGE:
# ./scripts/generate-audio-cdn-secret.sh
#
# OUTPUT:
# AUDIO_CDN_SECRET_KEY=base64:ABC123...
#
# INSTRUCTIONS:
# 1. Run this script
# 2. Copy output to .env file
# 3. Restart PHP-FPM: docker-compose restart php
###############################################################################

echo "=========================================="
echo "🔐 Audio CDN Secret Key Generator"
echo "=========================================="
echo ""

# Generate 64-byte random key (512-bit)
SECRET_KEY=$(openssl rand -base64 64 | tr -d '\n')

# Format for .env
ENV_LINE="AUDIO_CDN_SECRET_KEY=$SECRET_KEY"

echo "Generated secret key (copy to .env):"
echo ""
echo "$ENV_LINE"
echo ""

# Optionally append to .env automatically
read -p "Append to .env file automatically? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]
then
    # Check if .env exists
    if [ ! -f .env ]; then
        echo "❌ .env file not found. Copy .env.example to .env first."
        exit 1
    fi

    # Check if key already exists
    if grep -q "AUDIO_CDN_SECRET_KEY=" .env; then
        echo "⚠️  AUDIO_CDN_SECRET_KEY already exists in .env"
        read -p "Overwrite? (y/n) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            # Replace existing key
            if [[ "$OSTYPE" == "darwin"* ]]; then
                # macOS
                sed -i '' "s|^AUDIO_CDN_SECRET_KEY=.*|$ENV_LINE|" .env
            else
                # Linux
                sed -i "s|^AUDIO_CDN_SECRET_KEY=.*|$ENV_LINE|" .env
            fi
            echo "✅ Secret key updated in .env"
        else
            echo "❌ Cancelled"
        fi
    else
        # Append new key
        echo "" >> .env
        echo "# Audio CDN Secret Key (generated $(date +%Y-%m-%d))" >> .env
        echo "$ENV_LINE" >> .env
        echo "✅ Secret key added to .env"
    fi

    echo ""
    echo "⚠️  IMPORTANT: Restart PHP containers to load new key:"
    echo "   docker-compose restart php audio_worker"
fi

echo ""
echo "=========================================="
echo "🔒 Security Notes:"
echo "=========================================="
echo "1. Never commit this key to Git"
echo "2. Use different keys for dev/staging/prod"
echo "3. Rotate keys every 90 days"
echo "4. If key is compromised, regenerate immediately"
echo ""
