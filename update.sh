#!/bin/bash
# Trading Desk — update script
# Run by api/update.php via PHP exec() as www-data (via sudo).
# Sudoers entry: www-data ALL=(ALL) NOPASSWD: /var/www/trading-desk/update.sh

export HOME=/tmp
export GIT_TERMINAL_PROMPT=0
export GIT_ASKPASS=echo

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=== Trading Desk Update ==="
echo "App dir : $REPO_DIR"

# Fix .git ownership so www-data can read/write it
chown -R www-data:www-data "$REPO_DIR/.git"
chmod -R g+rwX "$REPO_DIR/.git"

cd "$REPO_DIR" || exit 1

echo "Branch  : $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
echo "Before  : $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

# Discard local changes so pull always succeeds
git reset --hard HEAD 2>&1
git clean -fd 2>&1

# Pull latest code
git pull origin main 2>&1

echo "After   : $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

# Run PHP migrations
PHP_BIN=$(command -v php8.3 2>/dev/null \
       || command -v php8.2 2>/dev/null \
       || command -v php8.1 2>/dev/null \
       || command -v php    2>/dev/null \
       || echo "")

if [ -z "$PHP_BIN" ]; then
    echo "WARNING: No PHP binary found, skipping migration step."
else
    echo "Running migrations with: $PHP_BIN"
    "$PHP_BIN" -r "require '${REPO_DIR}/db.php'; get_db(); echo 'Migrations OK' . PHP_EOL;" 2>&1
fi

# Reload Apache so new code is live without dropping connections
if command -v apache2ctl >/dev/null 2>&1; then
    apache2ctl graceful 2>&1
    echo "Apache graceful reload triggered."
elif command -v systemctl >/dev/null 2>&1; then
    systemctl reload apache2 2>&1
    echo "Apache reloaded via systemctl."
fi

echo "=== Done ==="
