#!/bin/bash

# Helper script to run freetime tests against different environments
# Usage: ./run-tests.sh [environment] [test-file]
#   environment: local, kristiansand, bergen, localhost, or custom URL
#   test-file: optional, specific test file to run

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/../../.."

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

function show_usage() {
    echo "Usage: $0 [environment] [test-file]"
    echo ""
    echo "Environments:"
    echo "  local         - Local development (https://pe-api.test, resource 106)"
    echo "  kristiansand  - Kristiansand production (resource 6)"
    echo "  bergen        - Bergen production (resource 452)"
    echo "  localhost     - Localhost with port (http://localhost:8080)"
    echo "  custom        - Use FREETIME_TEST_* environment variables"
    echo ""
    echo "Test files (optional):"
    echo "  schema        - Schema validation tests only"
    echo "  validation    - Validation tests only"
    echo "  endpoint      - Endpoint tests only"
    echo "  manual        - Run manual test script"
    echo "  all           - All tests (default)"
    echo ""
    echo "Examples:"
    echo "  $0 local                    # Run all tests locally"
    echo "  $0 kristiansand validation  # Run validation tests against Kristiansand"
    echo "  $0 bergen manual            # Run manual test against Bergen"
    echo ""
    echo "Custom environment:"
    echo "  FREETIME_TEST_BASE_URL=https://your-server.com \\"
    echo "  FREETIME_TEST_RESOURCE_ID=123 \\"
    echo "  FREETIME_TEST_BUILDING_ID=45 \\"
    echo "  $0 custom"
    exit 1
}

# Parse arguments
ENVIRONMENT="${1:-local}"
TEST_FILE="${2:-all}"

# Load environment config
case "$ENVIRONMENT" in
    local)
        echo -e "${BLUE}Loading local environment configuration...${NC}"
        export $(cat tests/booking/config/local.env | grep -v '^#' | xargs)
        ;;
    kristiansand)
        echo -e "${BLUE}Loading Kristiansand environment configuration...${NC}"
        export $(cat tests/booking/config/kristiansand.env | grep -v '^#' | xargs)
        ;;
    bergen)
        echo -e "${BLUE}Loading Bergen environment configuration...${NC}"
        export $(cat tests/booking/config/bergen.env | grep -v '^#' | xargs)
        ;;
    localhost)
        echo -e "${BLUE}Loading localhost environment configuration...${NC}"
        export $(cat tests/booking/config/localhost.env | grep -v '^#' | xargs)
        ;;
    custom)
        echo -e "${BLUE}Using custom environment variables...${NC}"
        if [ -z "$FREETIME_TEST_BASE_URL" ]; then
            echo -e "${YELLOW}Warning: FREETIME_TEST_BASE_URL not set, using default${NC}"
        fi
        ;;
    help|--help|-h)
        show_usage
        ;;
    *)
        echo -e "${YELLOW}Unknown environment: $ENVIRONMENT${NC}"
        show_usage
        ;;
esac

# Show configuration
echo ""
echo -e "${GREEN}Test Configuration:${NC}"
echo "  Base URL:    ${FREETIME_TEST_BASE_URL:-https://pe-api.test}"
echo "  Resource ID: ${FREETIME_TEST_RESOURCE_ID:-106}"
echo "  Building ID: ${FREETIME_TEST_BUILDING_ID:-10}"
echo "  Timezone:    ${FREETIME_TEST_TIMEZONE:-Europe/Oslo}"
echo ""

# Select test file
case "$TEST_FILE" in
    schema)
        echo -e "${BLUE}Running schema validation tests...${NC}"
        vendor/bin/phpunit tests/booking/legacy/endpoints/uibooking/get_freetime/SchemaValidationTest.php --testdox
        ;;
    validation)
        echo -e "${BLUE}Running validation tests...${NC}"
        vendor/bin/phpunit tests/booking/legacy/endpoints/uibooking/get_freetime/ValidationTest.php --testdox
        ;;
    endpoint)
        echo -e "${BLUE}Running endpoint tests...${NC}"
        vendor/bin/phpunit tests/booking/legacy/endpoints/uibooking/get_freetime/EndpointTest.php --testdox
        ;;
    manual)
        echo -e "${BLUE}Running manual test script...${NC}"
        php tests/booking/legacy/endpoints/uibooking/get_freetime/manual-test.php
        ;;
    all)
        echo -e "${BLUE}Running all tests...${NC}"
        vendor/bin/phpunit tests/booking/ --testdox
        ;;
    *)
        echo -e "${YELLOW}Unknown test file: $TEST_FILE${NC}"
        show_usage
        ;;
esac

EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Tests completed successfully${NC}"
else
    echo ""
    echo -e "${YELLOW}✗ Tests failed with exit code: $EXIT_CODE${NC}"
fi

exit $EXIT_CODE
