#!/bin/bash
# Worker Wrapper - Single Source of Truth for Worker Execution
# This ensures workers always run from the correct directory

PROJECT_ROOT="/var/www/html"
cd "$PROJECT_ROOT" || exit 1

# Execute worker from correct directory
exec php index.php worker "$@"