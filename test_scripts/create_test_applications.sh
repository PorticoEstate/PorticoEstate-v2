#!/bin/bash

# Script to create test applications with partials and checkout
# Usage:
#   ./create_test_applications.sh <session> [options]
#   ./create_test_applications.sh <session> -s    # Create single application
#   ./create_test_applications.sh <session> -c    # Create combined application
#   ./create_test_applications.sh <session> -r    # Create recurring application
#   ./create_test_applications.sh <session> -a    # Create all types
#   ./create_test_applications.sh <session>       # Interactive menu

# Configuration
BASE_URL="https://pe-api.test"
BUILDING_ID=10
BUILDING_NAME="Fana kulturhus"
ACTIVITY_ID=1
AVAILABLE_RESOURCES=(92 106 123 125 482 576 590 779 780 781 782)

# Parse command line arguments
BOOKING_SESSION=""
CREATE_SINGLE=false
CREATE_COMBINED=false
CREATE_RECURRING=false
CREATE_ALL=false
INTERACTIVE=false

# Check for help first
if [[ "$1" == "-h" ]] || [[ "$1" == "--help" ]]; then
    echo "Usage: $0 <bookingfrontendsession> [options]"
    echo ""
    echo "Options:"
    echo "  -s, --single      Create single application"
    echo "  -c, --combined    Create combined application (3 parts)"
    echo "  -r, --recurring   Create recurring application"
    echo "  -a, --all         Create all types"
    echo "  -h, --help        Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 <session>              # Interactive menu"
    echo "  $0 <session> -s           # Create single application"
    echo "  $0 <session> -c           # Create combined application"
    echo "  $0 <session> -r           # Create recurring application"
    echo "  $0 <session> -a           # Create all types"
    echo "  $0 <session> -s -c        # Create single and combined"
    exit 0
fi

# First argument should be the session
if [[ $# -gt 0 ]]; then
    BOOKING_SESSION="$1"
    shift
fi

# Parse options
while [[ $# -gt 0 ]]; do
    case $1 in
        -s|--single)
            CREATE_SINGLE=true
            shift
            ;;
        -c|--combined)
            CREATE_COMBINED=true
            shift
            ;;
        -r|--recurring)
            CREATE_RECURRING=true
            shift
            ;;
        -a|--all)
            CREATE_ALL=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use -h or --help for usage information"
            exit 1
            ;;
    esac
done

# Check if session is provided
if [[ -z "$BOOKING_SESSION" ]]; then
    echo "Error: bookingfrontendsession is required"
    echo "Usage: $0 <bookingfrontendsession> [options]"
    echo "Use -h or --help for more information"
    exit 1
fi

# If no options specified, go interactive
if [[ "$CREATE_ALL" == "false" ]] && [[ "$CREATE_SINGLE" == "false" ]] && [[ "$CREATE_COMBINED" == "false" ]] && [[ "$CREATE_RECURRING" == "false" ]]; then
    INTERACTIVE=true
fi

# Cookie string
COOKIE_STRING="selected_lang=no; last_loginid=henning; last_domain=default; template_set=bookingfrontend_2; domain=default; login_as_organization=1; after=%22%5C%2Fclient%5C%2Fno%22; ConfigPW=%242y%2412%24hbOZizfkwIjzSd2npuCG.OOeOqHIST%2FeFlSDtnUM7V9WtGgd45%2Fqy; ConfigDomain=default; ConfigLang=en; login_second_pass=1; bookingfrontendsession=${BOOKING_SESSION}"

# Common headers
HEADERS=(
    -k
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

# Checkout data (for SSN/personal)
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

# Checkout data for organization
CHECKOUT_DATA_ORG='{
    "organizerName":"Henning Berge",
    "customerType":"organization",
    "contactName":"Henning Berge",
    "contactEmail":"henning@grensesnitt.no",
    "contactPhone":"91113518",
    "street":"Rødslyngvegen 14",
    "zipCode":"4344",
    "city":"Bryne",
    "documentsRead":true,
    "customerOrganizationId":79,
    "customerOrganizationNumber":"994239929",
    "customerOrganizationName":"Bølleball forening"
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

    # Build base JSON object
    local base_data=$(jq -n \
        --arg building_name "$BUILDING_NAME" \
        --argjson building_id "$BUILDING_ID" \
        --arg date_from "$date_from" \
        --arg date_to "$date_to" \
        --argjson agegroups "$AGEGROUPS" \
        --argjson articles "$ARTICLES" \
        --arg name "$name" \
        --argjson activity_id "$ACTIVITY_ID" \
        '{
            building_name: $building_name,
            building_id: $building_id,
            dates: [{from_: $date_from, to_: $date_to}],
            audience: [1],
            agegroups: $agegroups,
            articles: $articles,
            organizer: "",
            name: $name,
            resources: [],
            activity_id: $activity_id
        }')

    # Add resources array (resources is a string like "92,106")
    local data=$(echo "$base_data" | jq --argjson res "[$resources]" '.resources = $res')

    # Add parent_id if provided
    if [[ -n "$parent_id" ]]; then
        data=$(echo "$data" | jq --argjson pid "$parent_id" '. + {parent_id: $pid}')
    fi

    echo "Creating partial: $name" >&2
    echo "Resources: $resources" >&2
    echo "Date: $date_from to $date_to" >&2
    if [[ -n "$parent_id" ]]; then
        echo "Parent ID: $parent_id" >&2
    fi

    local response=$(curl -s "${HEADERS[@]}" \
        --data-raw "$data" \
        "${BASE_URL}/bookingfrontend/applications/partials")

    echo "Response: $response" >&2
    echo "---" >&2

    # Extract application ID from response and output ONLY the ID to stdout
    local app_id=$(echo "$response" | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)
    echo "$app_id"
}

# Function to create a recurring partial application
create_partial_recurring() {
    local name="$1"
    local resources="$2"
    local date_from="$3"
    local date_to="$4"
    local repeat_until="$5"
    local interval="${6:-1}"  # Default to 1 week
    local parent_id="$7"

    # Agegroups for organization (slightly different format)
    local agegroups_org='[
        {"agegroup_id":2,"male":0,"female":0},
        {"agegroup_id":4,"male":0,"female":0},
        {"agegroup_id":6,"male":2,"female":0},
        {"agegroup_id":5,"male":0,"female":0}
    ]'

    # Build base JSON object for recurring application
    local base_data=$(jq -n \
        --arg building_name "$BUILDING_NAME" \
        --argjson building_id "$BUILDING_ID" \
        --arg date_from "$date_from" \
        --arg date_to "$date_to" \
        --argjson agegroups "$agegroups_org" \
        --argjson articles "$ARTICLES" \
        --arg name "$name" \
        --argjson activity_id "$ACTIVITY_ID" \
        --arg repeat_until "$repeat_until" \
        --argjson interval "$interval" \
        '{
            building_name: $building_name,
            building_id: $building_id,
            dates: [{from_: $date_from, to_: $date_to}],
            audience: [7],
            agegroups: $agegroups,
            articles: $articles,
            organizer: "Henning Berge",
            name: $name,
            homepage: "",
            description: "Recurring test application",
            equipment: "",
            resources: [],
            activity_id: $activity_id,
            recurring_info: {
                repeat_until: $repeat_until,
                field_interval: $interval,
                outseason: false
            },
            customer_identifier_type: "organization_number",
            customer_organization_id: 79,
            customer_organization_number: "994239929",
            customer_organization_name: "Bølleball forening"
        }')

    # Add resources array (resources is a string like "92,106")
    local data=$(echo "$base_data" | jq --argjson res "[$resources]" '.resources = $res')

    # Add parent_id if provided
    if [[ -n "$parent_id" ]]; then
        data=$(echo "$data" | jq --argjson pid "$parent_id" '. + {parent_id: $pid}')
    fi

    echo "Creating recurring partial: $name" >&2
    echo "Resources: $resources" >&2
    echo "Date: $date_from to $date_to" >&2
    echo "Repeat until: $repeat_until (every $interval week(s))" >&2
    if [[ -n "$parent_id" ]]; then
        echo "Parent ID: $parent_id" >&2
    fi

    local response=$(curl -s "${HEADERS[@]}" \
        --data-raw "$data" \
        "${BASE_URL}/bookingfrontend/applications/partials")

    echo "Response: $response" >&2
    echo "---" >&2

    # Extract application ID from response and output ONLY the ID to stdout
    local app_id=$(echo "$response" | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)
    echo "$app_id"
}

# Function to get current partials
get_partials() {
    echo "Getting current partials..." >&2
    curl -s "${HEADERS[@]}" \
        "${BASE_URL}/bookingfrontend/applications/partials" >&2
    echo >&2
    echo "---" >&2
}

# Function to checkout
checkout() {
    local parent_id="$1"
    local use_org="${2:-false}"  # Default to personal/SSN
    local checkout_data_with_parent

    # Choose checkout data based on customer type
    local base_checkout_data
    if [[ "$use_org" == "true" ]]; then
        base_checkout_data="$CHECKOUT_DATA_ORG"
    else
        base_checkout_data="$CHECKOUT_DATA"
    fi

    if [[ -n "$parent_id" ]]; then
        # Use jq to properly add parent_id to JSON
        checkout_data_with_parent=$(echo "$base_checkout_data" | jq --arg pid "$parent_id" '. + {parent_id: ($pid | tonumber)}')
    else
        checkout_data_with_parent="$base_checkout_data"
    fi

    echo "Checking out applications..." >&2
    echo "Checkout data: $checkout_data_with_parent" >&2

    local response=$(curl -s "${HEADERS[@]}" \
        --data-raw "$checkout_data_with_parent" \
        "${BASE_URL}/bookingfrontend/applications/partials/checkout")

    echo "Checkout response: $response" >&2
    echo "---" >&2

    # Extract application ID from checkout response and output ONLY the ID to stdout
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

# Interactive menu function
show_menu() {
    while true; do
        echo ""
        echo "==================================="
        echo "  Test Application Creator"
        echo "==================================="
        echo "Session: $BOOKING_SESSION"
        echo "Building: $BUILDING_NAME (ID: $BUILDING_ID)"
        echo ""
        echo "What would you like to create?"
        echo ""
        echo "  1) Single application"
        echo "  2) Combined application (3 parts)"
        echo "  3) Recurring application"
        echo "  4) All of the above"
        echo "  5) Exit"
        echo ""
        read -p "Enter your choice [1-5]: " choice

        case $choice in
            1)
                CREATE_SINGLE=true
                break
                ;;
            2)
                CREATE_COMBINED=true
                break
                ;;
            3)
                CREATE_RECURRING=true
                break
                ;;
            4)
                CREATE_ALL=true
                break
                ;;
            5)
                echo "Exiting..."
                exit 0
                ;;
            *)
                echo "Invalid choice. Please try again."
                ;;
        esac
    done
}

# Function to create single application
create_single_application() {
    echo ""
    echo "TEST: Creating Single Application"
    echo "==================================="
    single_date_from=$(get_future_date 1 10 00)
    single_date_to=$(get_future_date 1 12 00)
    single_app_id=$(create_partial "Single Test Application" "92,106,123" "$single_date_from" "$single_date_to")
    get_partials
    single_app_id=$(checkout)
    echo "✅ Single application created with ID: $single_app_id"
    echo "URL: ${BASE_URL}/index.php?menuaction=booking.uiapplication.show&id=${single_app_id}"
    echo ""
}

# Function to create combined application
create_combined_application() {
    echo ""
    echo "TEST: Creating Combined Application"
    echo "===================================="

    # Create first partial for combined application
    combined_date1_from=$(get_future_date 2 9 00)
    combined_date1_to=$(get_future_date 2 11 00)
    parent_app_id=$(create_partial "Combined App - Part 1" "92,106" "$combined_date1_from" "$combined_date1_to")
    echo "Parent app ID: $parent_app_id" >&2

    sleep 1

    # Create second partial for combined application (as child)
    combined_date2_from=$(get_future_date 2 14 00)
    combined_date2_to=$(get_future_date 2 16 00)
    create_partial "Combined App - Part 2" "123,125,482" "$combined_date2_from" "$combined_date2_to" "$parent_app_id"

    sleep 1

    # Create third partial for combined application (as child)
    combined_date3_from=$(get_future_date 3 10 00)
    combined_date3_to=$(get_future_date 3 12 00)
    create_partial "Combined App - Part 3" "576,590" "$combined_date3_from" "$combined_date3_to" "$parent_app_id"

    get_partials
    combined_app_id=$(checkout "$parent_app_id")
    echo "✅ Combined application created with ID: $combined_app_id (3 parts)"
    echo "URL: ${BASE_URL}/index.php?menuaction=booking.uiapplication.show&id=${combined_app_id}"
    echo ""
}

# Function to create recurring application
create_recurring_application() {
    echo ""
    echo "TEST: Creating Recurring Application"
    echo "====================================="
    recurring_date_from=$(get_future_date 7 9 30)
    recurring_date_to=$(get_future_date 7 12 30)
    recurring_repeat_until=$(get_future_date 90 0 0 | cut -d'T' -f1)  # 90 days ahead, date only

    create_partial_recurring "Recurring Weekly Application" "482,576,590" "$recurring_date_from" "$recurring_date_to" "$recurring_repeat_until" 1
    get_partials
    recurring_app_id=$(checkout "" "true")  # Use organization checkout
    echo "✅ Recurring application created with ID: $recurring_app_id"
    echo "URL: ${BASE_URL}/index.php?menuaction=booking.uiapplication.show&id=${recurring_app_id}"
    echo ""
}

# Main execution
echo ""
echo "=== Test Application Creator ==="
echo "Booking Session: $BOOKING_SESSION"
echo "Building: $BUILDING_NAME (ID: $BUILDING_ID)"
echo "================================="

# Show interactive menu if no options specified
if [[ "$INTERACTIVE" == "true" ]]; then
    show_menu
fi

# Execute based on flags
if [[ "$CREATE_ALL" == "true" ]]; then
    create_single_application
    sleep 2
    create_combined_application
    sleep 2
    create_recurring_application
elif [[ "$CREATE_SINGLE" == "true" ]]; then
    create_single_application
elif [[ "$CREATE_COMBINED" == "true" ]]; then
    create_combined_application
elif [[ "$CREATE_RECURRING" == "true" ]]; then
    create_recurring_application
fi

echo ""
echo "✅ Done! Applications created successfully."
echo ""