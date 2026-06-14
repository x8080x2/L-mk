#!/bin/bash
# setup.sh - Robust one-file setup for L1mk VPS (Render-only mode: NO Chrome, NO worker, NO Node)
PROJECT_ROOT=$(pwd)
echo "🚀 Starting setup in $PROJECT_ROOT"

# 1. PHP-only system dependencies
if command -v apt-get >/dev/null; then
    echo "📦 Installing PHP runtime dependencies..."
    apt-get update || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        php-cli php-fpm php-sqlite3 php-curl php-mbstring php-xml php-pgsql \
        sqlite3 unzip || true
fi

# 2. PHP Composer dependencies
if [ -d "php" ] && [ -f "php/composer.json" ]; then
    echo "🐘 Installing PHP dependencies..."
    cd php
    export COMPOSER_ALLOW_SUPERUSER=1
    if [ -f "composer.phar" ]; then
        php composer.phar install --no-dev --ignore-platform-reqs || true
    else
        composer install --no-dev --ignore-platform-reqs || true
    fi
    cd ..
fi

# 3. Project files & directories
echo "📁 Creating missing logs and directories..."
touch database.sqlite project.log .env config.json license_bot.db
mkdir -p session_data uploads

# Truncate project.log on each update
: > project.log

# 4. Permissions
echo "🔧 Applying directory structure and permissions..."
chown -R www-data:www-data . || true
chmod -R 755 .
chmod 777 uploads
chmod 666 project.log database.sqlite || true
php apply_structure.php || true

# 5. Clean up sensitive files that should not be publicly accessible
echo "🧹 Cleaning up leftover plain files..."
rm -rf templates/plain 2>/dev/null || true
rm -f config.json structure.json 2>/dev/null || true

echo "✨ Deployment Finished Successfully! (VPS proxy mode — Chrome runs on Render)"
