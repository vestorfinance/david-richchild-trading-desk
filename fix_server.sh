#!/bin/bash
set -e

PHP_BIN=$(command -v php8.3 2>/dev/null || command -v php8.2 2>/dev/null || command -v php8.1 2>/dev/null || command -v php)
echo "PHP binary: $PHP_BIN"

cd /var/www/trading-desk
git pull origin main

cat > /etc/caddy/Caddyfile << 'EOF'
{
    email admin@arrissa.trade
}

desk.arrissa.trade {
    reverse_proxy localhost:8080
    encode gzip
}
EOF
echo "Caddyfile written"

cat > /etc/systemd/system/trading-desk.service << EOF
[Unit]
Description=Trading Desk PHP Server
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/trading-desk
ExecStart=$PHP_BIN -S localhost:8080 router.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
echo "Service file written"

systemctl daemon-reload
systemctl enable trading-desk
systemctl restart trading-desk
sleep 1
echo "trading-desk: $(systemctl is-active trading-desk)"

caddy validate --config /etc/caddy/Caddyfile
systemctl restart caddy
sleep 2
echo "caddy: $(systemctl is-active caddy)"

echo ""
echo "=== Testing ==="
curl -sk https://desk.arrissa.trade/login.php | head -c 300
echo ""
echo "=== Done ==="
