#!/bin/bash

# Telegram Poller Startup Script
# This script runs the Telegram polling system

echo "Starting Telegram Poller..."
cd "$(dirname "$0")"

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "Error: PHP is not installed or not in PATH"
    exit 1
fi

# Run the poller
echo "Telegram Poller started. Polling every 3 seconds..."
echo "Press Ctrl+C to stop"

echo "[$(date)]-- START-TELEGRAM-POLLER.SH: EXECUTED" >> project.log

php telegram-poller.php