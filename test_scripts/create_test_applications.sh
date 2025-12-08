#!/bin/bash

# Script to create test applications with partials and checkout
# Usage: ./create_test_applications.sh [session_id]

# Configuration
BASE_URL="http://pe-api.test"
BUILDING_ID=10
BUILDING_NAME="Fana kulturhus"
ACTIVITY_ID=1
AVAILABLE_RESOURCES=(92 106 123 125 482 576 590 779 780 781 782)

# Default session data - override with parameter or leave empty to force new session
DEFAULT_SESSION_ID=""
DEFAULT_BOOKING_SESSION=""

SESSION_ID="${1:-$DEFAULT_SESSION_ID}"
BOOKING_SESSION="${2:-$DEFAULT_BOOKING_SESSION}"

# Cookie string
COOKIE_STRING="selected_lang=no; last_loginid=henning; last_domain=default; template_set=bookingfrontend_2; domain=default; login_as_organization=1; after=%22%5C%2Fclient%5C%2Fno%22; ConfigPW=%242y%2412%24hbOZizfkwIjzSd2npuCG.OOeOqHIST%2FeFlSDtnUM7V9WtGgd45%2Fqy; ConfigDomain=default; ConfigLang=en; sessionphpgwsessid=${SESSION_ID}; login_second_pass=1; bookingfrontendsession=${BOOKING_SESSION}"

# Common headers
HEADERS=(
    -H "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:141.0) Gecko/20100101 Firefox/141.0"
    -H "Accept: */*"
    -H "Accept-Language: en-US,en;q=0.5"
    -H "Accept-Encoding: gzip, deflate"
    -H "Referer: ${BASE_URL}/bookingfrontend/client/no/building/${BUILDING_ID}"
    -H "Content-Type: application/json"
    -H "Origin: ${BASE_URL}"
    -H "Connection: keep-alive"
    -H "Cookie: ${COOKIE_STRING}"
    -H "Priority: u=0"
)

# Articles array for applications
ARTICLES='[
    {"id":129,"quantity":4,"parent_id":null},
    {"id":519,"quantity":4,"parent_id":null},
    {"id":119,"quantity":4,"parent_id":null},
    {"id":700,"quantity":4,"parent_id":null},
    {"id":227,"quantity":4,"parent_id":null},
    {"id":133,"quantity":4,"parent_id":null},
    {"id":288,"quantity":4,"parent_id":null},
    {"id":292,"quantity":4,"parent_id":null},
    {"id":304,"quantity":4,"parent_id":null},
    {"id":226,"quantity":4,"parent_id":null},
    {"id":271,"quantity":4,"parent_id":null}
]'

# Agegroups array
AGEGROUPS='[
    {"agegroup_id":2,"male":2,"female":0},
    {"agegroup_id":4,"male":0,"female":0},
    {"agegroup_id":6,"male":0,"female":0},
    {"agegroup_id":5,"male":0,"female":0}
]'

# Checkout data
CHECKOUT_DATA='{
    "organizerName":"Henning Berge",
    "customerType":"ssn",
    "contactName":"Henning Berge",
    "contactEmail":"henning@grensesnitt.no",
    "contactPhone":"91113518",
    "street":"Rødslyngvegen 14",
    "zipCode":"4344",
    "city":"Bryne",
    "documentsRead":true
}'

# Function to generate future date
get_future_date() {
    local days_ahead="$1"
    local hour="$2"
    local minute="${3:-00}"

    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS date command
        date -v+"${days_ahead}d" -v"${hour}H" -v"${minute}M" -v0S "+%Y-%m-%dT%H:%M:00.000Z"
    else
        # Linux date command
        date -d "+${days_ahead} days ${hour}:${minute}" "+%Y-%m-%dT%H:%M:00.000Z"
    fi
}

# Function to create a partial application
create_partial() {
    local name="$1"
    local resources="$2"
    local date_from="$3"
    local date_to="$4"
    local parent_id="$5"

    local data
    if [[ -n "$parent_id" ]]; then
        data="{
            \"building_name\":\"${BUILDING_NAME}\",
            \"building_id\":${BUILDING_ID},
            \"dates\":[{\"from_\":\"${date_from}\",\"to_\":\"${date_to}\"}],
            \"audience\":[1],
            \"agegroups\":${AGEGROUPS},
            \"articles\":${ARTICLES},
            \"organizer\":\"\",
            \"name\":\"${name}\",
            \"resources\":[${resources}],
            \"activity_id\":${ACTIVITY_ID},
            \"parent_id\":${parent_id}
        }"
    else
        data="{
            \"building_name\":\"${BUILDING_NAME}\",
            \"building_id\":${BUILDING_ID},
            \"dates\":[{\"from_\":\"${date_from}\",\"to_\":\"${date_to}\"}],
            \"audience\":[1],
            \"agegroups\":${AGEGROUPS},
            \"articles\":${ARTICLES},
            \"organizer\":\"\",
            \"name\":\"${name}\",
            \"resources\":[${resources}],
            \"activity_id\":${ACTIVITY_ID}
        }"
    fi

    echo "Creating partial: $name"
    echo "Resources: $resources"
    echo "Date: $date_from to $date_to"

    local response=$(curl -s "${HEADERS[@]}" \
        --data-raw "$data" \
        "${BASE_URL}/bookingfrontend/applications/partials")

    echo "Response: $response"
    echo "---"

    # Extract application ID from response if needed
    local app_id=$(echo "$response" | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)
    echo "$app_id"
}

# Function to get current partials
get_partials() {
    echo "Getting current partials..."
    curl -s "${HEADERS[@]}" \
        "${BASE_URL}/bookingfrontend/applications/partials"
    echo
    echo "---"
}

# Function to checkout
checkout() {
    local parent_id="$1"
    local checkout_data_with_parent="$CHECKOUT_DATA"

    if [[ -n "$parent_id" ]]; then
        checkout_data_with_parent=$(echo "$CHECKOUT_DATA" | sed "s/}$/,\"parent_id\":${parent_id}}/")
    fi

    echo "Checking out applications..."
    echo "Checkout data: $checkout_data_with_parent"

    local response=$(curl -s "${HEADERS[@]}" \
        --data-raw "$checkout_data_with_parent" \
        "${BASE_URL}/bookingfrontend/applications/partials/checkout")

    echo "Checkout response: $response"
    echo "---"

    # Extract application ID from checkout response if needed
    local app_id=$(echo "$response" | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)
    echo "$app_id"
}

# Function to clear partials (optional)
clear_partials() {
    echo "Clearing existing partials..."
    # You may need to implement this based on your API
    # curl -X DELETE "${BASE_URL}/bookingfrontend/applications/partials" "${HEADERS[@]}"
    echo "Note: Manual clearing may be required"
    echo "---"
}

# Main execution
echo "=== Creating Test Applications ==="
echo "Session ID: $SESSION_ID"
echo "Booking Session: $BOOKING_SESSION"
echo "Building ID: $BUILDING_ID"
echo "Available Resources: ${AVAILABLE_RESOURCES[*]}"
echo "==================================="

# Test 1: Create a single application
echo
echo "TEST 1: Creating Single Application"
echo "==================================="
single_date_from=$(get_future_date 1 10 00)
single_date_to=$(get_future_date 1 12 00)
create_partial "Single Test Application" "92,106,123" "$single_date_from" "$single_date_to"
get_partials
single_app_id=$(checkout)
echo "Single application created with ID: $single_app_id"

echo
echo "Waiting 2 seconds..."
sleep 2

# Test 2: Create combined applications
echo
echo "TEST 2: Creating Combined Applications"
echo "====================================="

# Create first partial for combined application
combined_date1_from=$(get_future_date 2 9 00)
combined_date1_to=$(get_future_date 2 11 00)
parent_app_id=$(create_partial "Combined App - Part 1" "92,106" "$combined_date1_from" "$combined_date1_to")
echo "Parent app ID: $parent_app_id"

echo "Waiting 1 second..."
sleep 1

# Create second partial for combined application (as child)
combined_date2_from=$(get_future_date 2 14 00)
combined_date2_to=$(get_future_date 2 16 00)
create_partial "Combined App - Part 2" "123,125,482" "$combined_date2_from" "$combined_date2_to" "$parent_app_id"

echo "Waiting 1 second..."
sleep 1

# Create third partial for combined application (as child)
combined_date3_from=$(get_future_date 3 10 00)
combined_date3_to=$(get_future_date 3 12 00)
create_partial "Combined App - Part 3" "576,590" "$combined_date3_from" "$combined_date3_to" "$parent_app_id"

get_partials
combined_app_id=$(checkout "$parent_app_id")
echo "Combined application created with ID: $combined_app_id"

echo
echo "Waiting 2 seconds..."
sleep 2

# Test 3: Create another single application with different resources
echo
echo "TEST 3: Creating Another Single Application"
echo "=========================================="
another_date_from=$(get_future_date 4 15 00)
another_date_to=$(get_future_date 4 17 00)
create_partial "Another Single App" "779,780,781,782" "$another_date_from" "$another_date_to"
get_partials
another_single_id=$(checkout)
echo "Another single application created with ID: $another_single_id"

echo
echo "=== Test Applications Created ==="
echo "Single App 1 ID: $single_app_id"
echo "Combined App ID: $combined_app_id"
echo "Single App 2 ID: $another_single_id"
echo "================================="

echo
echo "You can now test the applications in the booking system!"
echo "URLs to check:"
echo "- Single App 1: ${BASE_URL}/index.php?menuaction=booking.uiapplication.show&id=${single_app_id}"
echo "- Combined App: ${BASE_URL}/index.php?menuaction=booking.uiapplication.show&id=${combined_app_id}"
echo "- Single App 2: ${BASE_URL}/index.php?menuaction=booking.uiapplication.show&id=${another_single_id}"
echo
echo "To disable combined view, add: &disable_combined=1"