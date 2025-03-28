#!/bin/bash

# WebSocket Troubleshooting Script
echo "WebSocket Troubleshooting"
echo "========================="

# Check if WebSocket server is running
echo "Checking for running WebSocket server process..."
ps aux | grep "server.php" | grep -v grep
if [ $? -eq 0 ]; then
    echo "[✓] WebSocket server is running"
else
    echo "[✗] WebSocket server is NOT running!"
    echo "Restarting WebSocket server..."
    /var/www/html/src/WebSocket/run_websocket.sh
fi

# Check open ports
echo -e "\nChecking if port 8080 is listening..."
netstat -an | grep 8080
if [ $? -eq 0 ]; then
    echo "[✓] Port 8080 is open and listening"
else
    echo "[✗] Port 8080 is NOT listening!"
    echo "Checking for port conflicts..."
    netstat -lnp | grep 8080
fi

# Check WebSocket server logs
echo -e "\nChecking WebSocket server logs..."
if [ -f /var/log/apache2/websocket.log ]; then
    echo "Last 10 lines of WebSocket log:"
    tail -n 10 /var/log/apache2/websocket.log
else
    echo "[✗] WebSocket log file not found!"
fi

# Check Apache modules
echo -e "\nChecking Apache modules..."
a2query -m proxy
a2query -m proxy_http
a2query -m proxy_wstunnel
a2query -m rewrite

# If any module is not enabled, enable it
if [ $? -ne 0 ]; then
    echo "Enabling required Apache modules..."
    a2enmod proxy proxy_http proxy_wstunnel rewrite
    echo "Restarting Apache..."
    service apache2 restart
fi

# Test WebSocket connectivity
echo -e "\nTesting WebSocket connectivity..."
curl --include \
     --no-buffer \
     --header "Connection: Upgrade" \
     --header "Upgrade: websocket" \
     --header "Host: localhost:8080" \
     --header "Origin: http://localhost" \
     --header "Sec-WebSocket-Key: SGVsbG8sIHdvcmxkIQ==" \
     --header "Sec-WebSocket-Version: 13" \
     http://localhost:8080/ 2>/dev/null

# Check Apache config
echo -e "\nChecking Apache configuration..."
grep -r "ProxyPass.*ws" /etc/apache2/

echo -e "\nTroubleshooting complete. If issues persist, check the detailed logs."
echo "You can manually start the WebSocket server with: php /var/www/html/src/WebSocket/server.php"