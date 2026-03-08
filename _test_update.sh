#!/bin/bash
KEY=$(python3 -c "import sqlite3; print(sqlite3.connect('/var/www/trading-desk/trader.db').execute('SELECT api_key FROM users LIMIT 1').fetchone()[0])")
echo "API key: $KEY"
echo "Server HEAD: $(git -C /var/www/trading-desk rev-parse HEAD)"
rm -f /tmp/td_update_check
echo "--- update check response ---"
curl -s -X POST http://localhost:8080/api/update.php \
  -H 'Host: desk.arrissa.trade' \
  -d "api_key=$KEY&action=check"
echo ""
