#!/bin/bash
# setup.sh - Robust one-file setup for L1mk VPS (Render-only mode: NO Chrome, NO worker, NO Node)
PROJECT_ROOT=$(pwd)
echo "🚀 Starting setup in $PROJECT_ROOT"

# Worker is no longer hosted on the VPS. The --worker-only flag is a no-op.
if [ "$1" == "--worker-only" ]; then
    echo "ℹ️ Worker is hosted on Render. Nothing to start on the VPS."
    exit 0
fi

# Make sure no legacy worker is still running on this VPS.
echo "🛑 Stopping any legacy worker..."
pkill -f 'index.php worker' || true

# Remove legacy supervisor worker config if it lingers from a previous deploy.
if [ -f /etc/supervisor/conf.d/worker.conf ] || [ -f /etc/supervisor/conf.d/worker_fixed.conf ]; then
    echo "🧹 Removing legacy supervisor worker configs..."
    rm -f /etc/supervisor/conf.d/worker.conf /etc/supervisor/conf.d/worker_fixed.conf /etc/supervisor/conf.d/worker.conf.bak
    if command -v supervisorctl >/dev/null 2>&1; then
        supervisorctl reread || true
        supervisorctl update || true
    fi
fi

# 1. PHP-only system dependencies
if command -v apt-get >/dev/null; then
    echo "📦 Installing PHP runtime dependencies..."
    apt-get update || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        php-cli php-fpm php-sqlite3 php-curl php-mbstring php-xml \
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

echo "✨ Deployment Finished Successfully! (VPS proxy mode — Chrome runs on Render)"
