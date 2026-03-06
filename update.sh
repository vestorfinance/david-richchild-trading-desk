#!/bin/bash
# Trading Desk — update script
# Run by api/update.php via PHP exec() as www-data.
# 1. git pull latest code
# 2. Run PHP migrations (calls get_db() which runs all ALTER/CREATE statements)

set -e

# Ensure git has a writable HOME when run as www-data via PHP exec()
export HOME=/tmp
export GIT_CONFIG_NOSYSTEM=1
export GIT_TERMINAL_PROMPT=0
export GIT_ASKPASS=echo

APPDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APPDIR"

echo "=== Trading Desk Update ==="
echo "App dir : $APPDIR"
echo "Branch  : $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
echo "Before  : $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

# Pull latest code
git pull origin main 2>&1

echo "After   : $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

# Run migrations — find whichever php binary is available
PHP_BIN=$(command -v php8.3 2>/dev/null \
       || command -v php8.2 2>/dev/null \
       || command -v php8.1 2>/dev/null \
       || command -v php    2>/dev/null \
       || echo "")

if [ -z "$PHP_BIN" ]; then
    echo "WARNING: No PHP binary found, skipping migration step."
else
    echo "Running migrations with: $PHP_BIN"
    "$PHP_BIN" -r "require '${APPDIR}/db.php'; get_db(); echo 'Migrations OK' . PHP_EOL;" 2>&1
fi

echo "=== Done ==="
