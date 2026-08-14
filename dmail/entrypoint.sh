#!/bin/sh
set -e

if [ "$DMAIL_MODE" = "prod" ]; then
  echo "Dmail starting in production mode"
  npm run build
  node server.js
else
  echo "Dmail starting in development mode"
  export API_PORT=3099
  # Start Express API in background
  node server.js &
  # Start Vite dev server in foreground (serves on $PORT)
  npx vite --host 0.0.0.0 --port "$PORT"
fi
