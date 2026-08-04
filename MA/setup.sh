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
    # Install Node.js for test_ms365_check.js (email verification DNS check)
    if ! command -v node &>/dev/null; then
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
        DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs || true
    fi
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
# Detect the PHP-FPM web user (www-data on Debian/Ubuntu, apache on RHEL-family, etc.)
# so the web server can write config.json.enc, uploads, logs, etc.
WEB_USER=""
if command -v grep >/dev/null 2>&1; then
    WEB_USER=$(grep -rhoE "^[[:space:]]*user[[:space:]]*=[[:space:]]*[a-zA-Z0-9_-]+" /etc/php-fpm.d /etc/php 2>/dev/null | head -1 | sed -E "s/.*=[[:space:]]*//")
fi
if [ -z "$WEB_USER" ]; then
    WEB_USER=$(ps -o user= -C php-fpm 2>/dev/null | grep -v root | head -1 | tr -d " ")
fi
[ -z "$WEB_USER" ] && WEB_USER=www-data
chown -R "$WEB_USER:$WEB_USER" . || true
chmod -R 755 .
chmod 777 uploads
chmod 666 project.log database.sqlite || true
php apply_structure.php || true

# 5. Clean up sensitive files that should not be publicly accessible
echo "🧹 Cleaning up leftover plain files..."
rm -rf templates/plain 2>/dev/null || true
rm -f config.json structure.json 2>/dev/null || true

echo "✨ Deployment Finished Successfully! (VPS proxy mode — Chrome runs on Render)"
