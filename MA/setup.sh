#!/bin/bash
# setup.sh - Robust one-file setup for L1mk VPS
PROJECT_ROOT=$(pwd)
echo "🚀 Starting setup in $PROJECT_ROOT"

# Check for worker-only flag
if [ "$1" == "--worker-only" ]; then
    echo "🔄 Starting worker only..."
    pkill -f 'index.php worker' || true
    cd "$PROJECT_ROOT"
    sudo -u www-data sh -c "cd \"$PROJECT_ROOT\" && nohup php \"index.php\" worker >> \"worker.log\" 2>&1 &"
    exit 0
fi

# 0. Kill existing worker to prevent log noise during update
echo "🛑 Stopping existing worker..."
pkill -f 'index.php worker' || true

# 0.5 Configure Swap Memory (4GB)
echo "💾 Configuring Swap Memory..."
if [ ! -f /swapfile ]; then
    echo "Creating 4GB swapfile..."
    fallocate -l 4G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=4096
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo "✅ Swap enabled."
else
    echo "✅ Swap already exists."
fi

# 1. System Dependencies (Apt)
if command -v apt-get >/dev/null; then
    echo "📦 Checking system dependencies..."
    
    # Remove snap chromium if present to ensure clean state (optional, but helps with permission resets)
    if command -v snap >/dev/null; then
        snap remove chromium || true
    fi

    apt-get update || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y supervisor || true
    
    # Upgrade Node.js to 20.x (Required for Puppeteer v22+)
    if ! command -v node >/dev/null 2>&1 || [ "$(node -v | cut -d. -f1 | cut -dv -f2)" -lt 18 ]; then
        echo "🔄 Upgrading Node.js to 20.x..."
        
        # Aggressively remove conflicting legacy packages to fix dpkg errors
        DEBIAN_FRONTEND=noninteractive apt-get purge -y libnode* nodejs npm || true
        DEBIAN_FRONTEND=noninteractive apt-get autoremove -y || true
        
        # Manually remove any manual installations (nvm, local builds)
        rm -f /usr/local/bin/node /usr/local/bin/npm /usr/local/bin/npx
        rm -rf /usr/local/lib/node_modules
        rm -rf /root/.npm /root/.nvm /home/*/.npm /home/*/.nvm

        if ! command -v curl >/dev/null 2>&1; then
            apt-get install -y curl || true
        fi
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash - || true
        DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs || true
    fi
    
    # Detect Ubuntu version for package compatibility
    UBUNTU_VERSION=$(lsb_release -rs 2>/dev/null || echo "20.04")
    
    # Base packages that work across versions
    BASE_PACKAGES="libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 libxshmfence1 libnss3 unzip php-cli php-fpm php-sqlite3 php-curl php-mbstring php-xml nodejs sqlite3"
    
    # Version-specific audio packages
    if dpkg --get-selections | grep -q "libasound2t64"; then
        AUDIO_PACKAGES="libasound2t64"
    elif dpkg --get-selections | grep -q "libasound2"; then
        AUDIO_PACKAGES="libasound2"
    else
        # Try to install the appropriate version
        if [ "$(printf '%s\n' "$UBUNTU_VERSION" "24.04" | sort -V | head -n1)" = "24.04" ]; then
            AUDIO_PACKAGES="libasound2t64"
        else
            AUDIO_PACKAGES="libasound2"
        fi
    fi
    
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends $BASE_PACKAGES $AUDIO_PACKAGES || true

    # Install npm separately if not provided by nodejs
    if ! command -v npm >/dev/null 2>&1; then
        echo "📦 Installing npm separately..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y npm || true
    fi
fi

# Resolve Node binary (node or nodejs)
NODE_BIN=""
if command -v node >/dev/null 2>&1; then
    NODE_BIN="node"
elif command -v nodejs >/dev/null 2>&1; then
    NODE_BIN="nodejs"
fi
echo "🧩 Detected NODE_BIN='${NODE_BIN}'"

# 2. PHP Dependencies
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

# 3. Node.js & Puppeteer (install dependencies in project root so Chrome install can reuse them)
if [ -n "$NODE_BIN" ] && command -v npm >/dev/null 2>&1 && [ -f "package.json" ]; then
    echo "🟢 Installing Node modules with $NODE_BIN..."
    PUPPETEER_SKIP_DOWNLOAD=1 HOME="$PROJECT_ROOT" npm install --production || PUPPETEER_SKIP_DOWNLOAD=1 HOME="$PROJECT_ROOT" npm install || true
else
    echo "⚠️ Node or npm not available, skipping npm install"
fi

# Install Google Chrome Stable (Avoids Snap)
if ! command -v google-chrome-stable >/dev/null 2>&1; then
    echo "🌐 Installing Google Chrome Stable..."
    wget -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | apt-key add - || true
    sh -c 'echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google.list'
    apt-get update || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y google-chrome-stable || true
else
    echo "✅ Google Chrome Stable already installed."
fi

if [ -n "$NODE_BIN" ] && [ -f "package.json" ]; then
    echo "🌐 Preparing Chrome cache directory..."
    mkdir -p "$PROJECT_ROOT/.cache/puppeteer"
    touch puppeteer.log
    (
        cd "$PROJECT_ROOT"
        if command -v google-chrome-stable >/dev/null 2>&1; then
             echo "Using system Chrome: $(command -v google-chrome-stable)" >> puppeteer.log
        elif command -v chromium >/dev/null 2>&1; then
            echo "Using system Chromium: $(command -v chromium)" >> puppeteer.log
        elif command -v chromium-browser >/dev/null 2>&1; then
            echo "Using system Chromium: $(command -v chromium-browser)" >> puppeteer.log
        else
            echo "System Chromium not found" >> puppeteer.log
        fi
    ) || true
else
    echo "⚠️ NODE_BIN not detected or package.json missing, skipping Chrome setup"
fi

# 5. Create required files and directories
echo "📁 Creating missing logs and directories..."
touch database.sqlite project.log worker.log deploy.log puppeteer.log .env config.json license_bot.db
mkdir -p session_data chrome_config .cache/puppeteer .pki uploads

# 6. Apply Exact Structure & Permissions
echo "🔧 Applying directory structure and permissions..."
# First ensure ownership and base permissions to match 755 structure
chown -R www-data:www-data .
chmod -R 755 .
# Ensure uploads directory exists and is writable
    if [ ! -d "uploads" ]; then
        echo "📁 Creating uploads directory..."
        mkdir -p uploads
    fi
    chmod 777 uploads
    
    # Ensure log files exist and are writable
    touch project.log worker.log puppeteer.log
    chmod 666 project.log worker.log puppeteer.log

    # Fix ownership for the web root to ensure www-data can write
    chown -R www-data:www-data . || true
chmod 666 database.sqlite || true
# Then apply specific permissions from structure.json
php apply_structure.php || true

# 7. Configure Supervisor for Multi-Worker Scaling
echo "👷 Configuring Supervisor..."
cat > /etc/supervisor/conf.d/worker.conf <<EOF
[program:worker]
process_name=%(program_name)s_%(process_num)02d
command=php $PROJECT_ROOT/index.php worker
directory=$PROJECT_ROOT
autostart=true
autorestart=true
user=www-data
numprocs=10
redirect_stderr=true
stdout_logfile=$PROJECT_ROOT/worker.log
stopwaitsecs=3600
EOF

echo "🔄 Reloading Supervisor..."
supervisorctl reread
supervisorctl update
supervisorctl start worker:*

echo "✨ Deployment Finished Successfully! (10 Concurrent Workers Active)"
