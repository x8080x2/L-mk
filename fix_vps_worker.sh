#!/bin/bash
# Fix VPS worker configuration issues

VPS_PROJECT_ROOT="/var/www/html"

echo "🔧 Fixing VPS worker configuration..."

# 1. Fix supervisor worker configuration
echo "📋 Creating corrected supervisor config..."
cat > /etc/supervisor/conf.d/worker_fixed.conf <<EOF
[program:worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${VPS_PROJECT_ROOT}/index.php worker
directory=${VPS_PROJECT_ROOT}
autostart=true
autorestart=true
user=www-data
numprocs=10
redirect_stderr=true
stdout_logfile=${VPS_PROJECT_ROOT}/worker.log
stopwaitsecs=3600
EOF

# 2. Stop existing workers
echo "🛑 Stopping existing workers..."
supervisorctl stop worker:* 2>/dev/null || true
pkill -f 'index.php worker' || true

# 3. Reload supervisor with new config
echo "🔄 Reloading supervisor..."
supervisorctl reread
supervisorctl update

# 4. Test worker execution manually
echo "🧪 Testing worker execution..."
cd ${VPS_PROJECT_ROOT}
sudo -u www-data php index.php worker > /tmp/worker_test.log 2>&1 &
TEST_PID=$!
sleep 3

if ps -p $TEST_PID > /dev/null; then
    echo "✅ Worker test started successfully (PID: $TEST_PID)"
    kill $TEST_PID
else
    echo "❌ Worker test failed - checking log..."
    cat /tmp/worker_test.log
fi

# 5. Start new workers
echo "🚀 Starting new workers..."
supervisorctl start worker:*

echo "✅ VPS worker fix completed!"
echo "📊 Check worker status: supervisorctl status"
echo "📋 Check worker logs: tail -f ${VPS_PROJECT_ROOT}/worker.log"