#!/bin/bash

# Script to update/install language files in PorticoEstate
# Usage: ./update_language_files.sh [password] [languages]
# Example: ./update_language_files.sh changeme "en,no,nn"

set -e

# Get script directory and project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
CONFIG_FILE="$PROJECT_ROOT/config/header.inc.php"

# Try to read password from config file
CONFIG_PASSWORD=""
if [ -f "$CONFIG_FILE" ]; then
    # Extract password from PHP config file
    CONFIG_PASSWORD=$(grep "^\$phpgw_info\['server'\]\['header_admin_password'\]" "$CONFIG_FILE" | cut -d"'" -f6 2>/dev/null || true)
fi

# Configuration
BASE_URL="${BASE_URL:-https://pe-api.test}"
PASSWORD="${1:-${CONFIG_PASSWORD:-changeme}}"
LANGUAGES="${2:-en,no,nn}"
DOMAIN="${DOMAIN:-default}"
LANG="${LANG:-en}"

# Convert comma-separated languages to URL-encoded format
IFS=',' read -ra LANG_ARRAY <<< "$LANGUAGES"
LANG_PARAMS=""
for lang in "${LANG_ARRAY[@]}"; do
    LANG_PARAMS="${LANG_PARAMS}lang_selected%5B%5D=${lang}&"
done
# Remove trailing &
LANG_PARAMS="${LANG_PARAMS%&}"

echo "=== Language File Update Script ==="
echo "Base URL: $BASE_URL"
echo "Domain: $DOMAIN"
echo "Languages: $LANGUAGES"
if [ -n "$CONFIG_PASSWORD" ] && [ -z "$1" ]; then
    echo "Password: (read from config file)"
elif [ -n "$1" ]; then
    echo "Password: (from parameter)"
else
    echo "Password: (using default)"
fi
echo ""

# Create temporary files for cookie storage
COOKIE_FILE=$(mktemp)
trap "rm -f $COOKIE_FILE" EXIT

echo "Step 1: Logging in to setup interface..."
LOGIN_RESPONSE=$(curl "${BASE_URL}/setup" \
  --insecure \
  --silent \
  --compressed \
  -X POST \
  -c "$COOKIE_FILE" \
  -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:147.0) Gecko/20100101 Firefox/147.0' \
  -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
  -H 'Accept-Language: en-US,en;q=0.9' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -H "Origin: ${BASE_URL}" \
  -H "Referer: ${BASE_URL}/setup" \
  --data-raw "FormPW=${PASSWORD}&FormDomain=${DOMAIN}&ConfigLang=${LANG}&ConfigLogin=Login&submit=Login")

# Check if login was successful by looking for cookies
if [ ! -s "$COOKIE_FILE" ]; then
    echo "ERROR: Login failed - no cookies received"
    exit 1
fi

echo "✓ Login successful"
echo ""

echo "Step 2: Installing language files..."
INSTALL_RESPONSE=$(curl "${BASE_URL}/setup/lang" \
  --insecure \
  --silent \
  --compressed \
  -X POST \
  -b "$COOKIE_FILE" \
  -w "\nHTTP_CODE:%{http_code}\n" \
  -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:147.0) Gecko/20100101 Firefox/147.0' \
  -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
  -H 'Accept-Language: en-US,en;q=0.9' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -H "Origin: ${BASE_URL}" \
  -H "Referer: ${BASE_URL}/setup/lang" \
  --data-raw "${LANG_PARAMS}&upgrademethod=dumpold&submit=Install")

# Extract HTTP code from response
HTTP_CODE=$(echo "$INSTALL_RESPONSE" | grep "HTTP_CODE:" | cut -d':' -f2)

if [ "$HTTP_CODE" = "302" ] || [ "$HTTP_CODE" = "200" ]; then
    echo "✓ Language installation completed successfully"
    echo ""
    echo "Languages installed: $LANGUAGES"
else
    echo "ERROR: Language installation may have failed (HTTP $HTTP_CODE)"
    exit 1
fi

echo ""
echo "=== Done ==="
