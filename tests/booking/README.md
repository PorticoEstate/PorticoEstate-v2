# Booking Freetime Endpoint Tests

Comprehensive end-to-end tests for the `bookingfrontend.uibooking.get_freetime` endpoint.

**✨ Tests are fully dynamic and resource-aware** - they validate against actual resource configuration rather than hardcoded expectations. This means they work correctly with:
- Any slot duration (2-hour, 21-hour, variable, etc.)
- Any booking horizon (days or months)
- Any operating hours configuration
- Any timezone

## Quick Start

### Using the Helper Script (Easiest)

```bash
# Run all tests locally
./tests/booking/run-tests.sh local

# Run against Kristiansand production
./tests/booking/run-tests.sh kristiansand

# Run against Bergen production
./tests/booking/run-tests.sh bergen

# Run specific test suite
./tests/booking/run-tests.sh local schema      # Schema validation only
./tests/booking/run-tests.sh local validation  # Validation tests only
./tests/booking/run-tests.sh local manual      # Manual test script

# Run with custom configuration
FREETIME_TEST_BASE_URL=https://your-server.com \
FREETIME_TEST_RESOURCE_ID=123 \
FREETIME_TEST_BUILDING_ID=45 \
./tests/booking/run-tests.sh custom
```

### Direct PHPUnit/Manual Execution

```bash
# Run all tests locally (default config)
vendor/bin/phpunit tests/booking/

# Run against Kristiansand production
export $(cat tests/booking/config/kristiansand.env | xargs) && vendor/bin/phpunit tests/booking/

# Run against Bergen production
export $(cat tests/booking/config/bergen.env | xargs) && vendor/bin/phpunit tests/booking/

# Run against custom URL
FREETIME_TEST_BASE_URL=https://your-server.com \
FREETIME_TEST_RESOURCE_ID=123 \
FREETIME_TEST_BUILDING_ID=45 \
vendor/bin/phpunit tests/booking/

# Quick manual test
php tests/booking/test_freetime_manual.php
```

## Overview

These tests validate:
- ✅ Response structure and format
- ✅ Time slot generation based on resource configuration
- ✅ Overlap detection for Events, Allocations, and Bookings
- ✅ `time_in_past` restriction
- ✅ `detailed_overlap` parameter functionality
- ✅ Booking horizon limitations (8-day limit)
- ✅ Various overlap scenarios (complete, partial, containment)

## Test Files

### 1. `FreetimeEndpointTest.php` - PHPUnit Test Suite
Full PHPUnit test suite with 10 comprehensive test cases.

**Run with PHPUnit:**
```bash
cd /Users/henningberge/Documents/Grensesnitt/aktivkommune/pe-api
vendor/bin/phpunit tests/booking/FreetimeEndpointTest.php
```

**Run specific test:**
```bash
vendor/bin/phpunit tests/booking/FreetimeEndpointTest.php --filter testEventOverlapDetection
```

### 2. `test_freetime_manual.php` - Standalone Test Script
Quick manual test script that can be run directly without PHPUnit. Useful for debugging and quick validation.

**Run directly:**
```bash
php tests/booking/test_freetime_manual.php
```

or

```bash
./tests/booking/test_freetime_manual.php
```

## Configuration

All tests use environment variables for configuration with sensible defaults:
- **Base URL:** `https://pe-api.test`
- **Resource ID:** `106` (Småsalen in local environment)
- **Building ID:** `10` (Fana Kulturhus)
- **Timezone:** `Europe/Oslo`

### Environment Variables

The following environment variables control test configuration:

| Variable | Description | Default |
|----------|-------------|---------|
| `FREETIME_TEST_BASE_URL` | API base URL | `https://pe-api.test` |
| `FREETIME_TEST_RESOURCE_ID` | Resource ID to test | `106` |
| `FREETIME_TEST_BUILDING_ID` | Building ID | `10` |
| `FREETIME_TEST_TIMEZONE` | Timezone for date calculations | `Europe/Oslo` |

### Ways to Configure

#### 1. Command Line (Quick Override)
Override for a single test run:
```bash
FREETIME_TEST_BASE_URL=https://kristiansand.aktiv-kommune.no \
FREETIME_TEST_RESOURCE_ID=6 \
FREETIME_TEST_BUILDING_ID=1 \
vendor/bin/phpunit tests/booking/FreetimeValidationTest.php
```

#### 2. Using Preset Configurations
Use one of the preset environment files:

**Kristiansand Production:**
```bash
export $(cat tests/booking/config/kristiansand.env | xargs) && \
vendor/bin/phpunit tests/booking/
```

**Bergen Production:**
```bash
export $(cat tests/booking/config/bergen.env | xargs) && \
vendor/bin/phpunit tests/booking/
```

**Local Development:**
```bash
export $(cat tests/booking/config/local.env | xargs) && \
vendor/bin/phpunit tests/booking/
```

**Localhost with Port:**
```bash
export $(cat tests/booking/config/localhost.env | xargs) && \
vendor/bin/phpunit tests/booking/
```

#### 3. Custom .env File
Create your own configuration:
```bash
# Copy example
cp tests/booking/.env.example tests/booking/.env

# Edit with your values
vim tests/booking/.env

# Load and run
export $(cat tests/booking/.env | xargs) && \
vendor/bin/phpunit tests/booking/
```

#### 4. PHPUnit XML Configuration
Edit `tests/booking/phpunit.xml`:
```xml
<php>
    <env name="FREETIME_TEST_BASE_URL" value="https://your-server.com"/>
    <env name="FREETIME_TEST_RESOURCE_ID" value="123"/>
    <env name="FREETIME_TEST_BUILDING_ID" value="45"/>
    <env name="FREETIME_TEST_TIMEZONE" value="Europe/Oslo"/>
</php>
```

Then run with the local config:
```bash
cd tests/booking && phpunit
```

### Manual Test Script Configuration

The manual test script uses the same environment variables:

```bash
# Using preset config
export $(cat tests/booking/config/kristiansand.env | xargs) && \
php tests/booking/test_freetime_manual.php

# Or inline
FREETIME_TEST_BASE_URL=http://localhost:8080 \
FREETIME_TEST_RESOURCE_ID=1 \
FREETIME_TEST_BUILDING_ID=1 \
php tests/booking/test_freetime_manual.php
```

## Test Data Requirements

For complete test coverage, you need to create test data:

### 1. Resource Configuration (Required)
The test resource must be configured with:
- `simple_booking = 1` (enabled)
- `booking_time_minutes = 120` (2-hour slots)
- `booking_day_horizon = 8` (8 days advance)
- `booking_time_default_start = 8` (08:00)
- `booking_time_default_end = 22` (22:00)

### 2. Test Events (Optional, for overlap tests)
Create events in `bb_event` table:
```sql
INSERT INTO bb_event (
    id_string, active, activity_id, name, from_, to_,
    building_id, building_name, contact_name, contact_email, contact_phone,
    completed, reminder, customer_identifier_type
) VALUES (
    '1', 1, 1, 'Test Event',
    '2026-01-21 14:00:00', '2026-01-21 16:00:00',
    10, 'Fana Kulturhus', 'Test User', 'test@example.com', '12345678',
    0, 1, 'ssn'
);

INSERT INTO bb_event_resource (event_id, resource_id)
VALUES (LAST_INSERT_ID(), 106);
```

### 3. Test Allocations (Optional, for overlap tests)
Create allocations in `bb_allocation` table:
```sql
INSERT INTO bb_allocation (
    id_string, active, building_name, organization_id,
    from_, to_, season_id, completed
) VALUES (
    '1', 1, 'Fana Kulturhus', 1,
    '2026-01-21 18:00:00', '2026-01-21 21:00:00',
    1, 0
);

INSERT INTO bb_allocation_resource (allocation_id, resource_id)
VALUES (LAST_INSERT_ID(), 106);
```

### 4. Test Bookings (Optional, for overlap tests)
Create bookings in `bb_booking` table:
```sql
INSERT INTO bb_booking (
    group_id, from_, to_, building_name, allocation_id,
    season_id, active, activity_id, completed, reminder, secret
) VALUES (
    1, '2026-01-21 16:00:00', '2026-01-21 18:00:00',
    'Fana Kulturhus', 1,
    1, 1, 1, 0, 1, ''
);

INSERT INTO bb_booking_resource (booking_id, resource_id)
VALUES (LAST_INSERT_ID(), 106);
```

## Test Cases

### Test 1: Basic Endpoint Response
Validates that the endpoint returns a properly structured array with resource IDs as keys.

### Test 2: Time Slot Generation
Checks that time slots are generated according to resource configuration (2-hour slots, proper start/end times).

### Test 3: Time in Past Restriction
Verifies that slots in the past are marked with `overlap: 3`, `reason: 'time_in_past'`, and `type: 'disabled'`.

### Test 4: Event Overlap Detection
Tests that events are properly detected as overlaps with correct event details.

### Test 5: Booking Overlap Detection
Tests that bookings are properly detected as overlaps.

### Test 6: Allocation Overlap Detection
Tests that allocations are properly detected as overlaps.

### Test 7: Detailed Overlap Parameter
Validates that `detailed_overlap=true` includes additional information like `resource_id`, `overlap_reason`, `overlap_type`, and `overlap_event` details.

### Test 8: Booking Horizon Limitation
Checks that slots beyond the 8-day booking horizon are not available.

### Test 9: Multiple Overlap Types
Collects statistics on all overlap types found in a date range.

### Test 10: Response Format Validation
Validates the structure of every slot in the response including required fields, timestamp formats, and ISO dates.

## Expected Response Structure

### Basic Slot (Available):
```json
{
    "when": "21/01-2026 08:00 - 21/01-2026 10:00",
    "start": "1737442800000",
    "end": "1737450000000",
    "overlap": false,
    "start_iso": "2026-01-21T08:00:00+01:00",
    "end_iso": "2026-01-21T10:00:00+01:00"
}
```

### Slot with Detailed Overlap:
```json
{
    "when": "21/01-2026 14:00 - 21/01-2026 16:00",
    "start": "1737464400000",
    "end": "1737471600000",
    "overlap": 1,
    "resource_id": 106,
    "overlap_reason": "complete_overlap",
    "overlap_type": "complete",
    "overlap_event": {
        "id": 26219,
        "type": "event",
        "status": null,
        "from": "2026-01-21 14:00:00",
        "to": "2026-01-21 16:00:00"
    },
    "start_iso": "2026-01-21T14:00:00+01:00",
    "end_iso": "2026-01-21T16:00:00+01:00"
}
```

### Slot in Past:
```json
{
    "when": "20/01-2026 08:00 - 20/01-2026 10:00",
    "start": "1737356400000",
    "end": "1737363600000",
    "overlap": 3,
    "resource_id": 106,
    "overlap_reason": "time_in_past",
    "overlap_type": "disabled",
    "start_iso": "2026-01-20T08:00:00+01:00",
    "end_iso": "2026-01-20T10:00:00+01:00"
}
```

## Overlap Status Codes

- `false` or `0`: Available (no overlap)
- `1`: Complete overlap (event/booking/allocation exists)
- `2`: Partial overlap or block
- `3`: Disabled (time_in_past)

## Overlap Reasons

- `time_in_past`: Slot is in the past
- `complete_overlap`: Existing reservation covers the entire slot
- `complete_containment`: Existing reservation is contained within the slot
- `start_overlap`: Partial overlap at the start
- `end_overlap`: Partial overlap at the end

## Overlap Event Types

- `event`: Event from bb_event table
- `booking`: Booking from bb_booking table
- `allocation`: Allocation from bb_allocation table
- `block`: Resource block from bb_block table
- `application`: Partial application (status: NEWPARTIAL1)

## Troubleshooting

### Configuration Issues

**Tests are using wrong environment:**
```bash
# Verify environment variables are set
echo $FREETIME_TEST_BASE_URL
echo $FREETIME_TEST_RESOURCE_ID
echo $FREETIME_TEST_BUILDING_ID

# Use the helper script to ensure config is loaded
./tests/booking/run-tests.sh local schema
```

**Environment variables not loading:**
```bash
# Make sure to use export with xargs
export $(cat tests/booking/config/local.env | xargs)

# Or use the helper script
./tests/booking/run-tests.sh local
```

**Testing against production but seeing local data:**
```bash
# Clear any existing environment variables first
unset FREETIME_TEST_BASE_URL FREETIME_TEST_RESOURCE_ID FREETIME_TEST_BUILDING_ID

# Then load the correct config
export $(cat tests/booking/config/kristiansand.env | xargs)
vendor/bin/phpunit tests/booking/
```

### Test Failures

**Tests are skipped:**
Some tests will be skipped if no test data exists. Create events, bookings, and allocations as described above.

**Resource not found:**
Make sure the `FREETIME_TEST_RESOURCE_ID` and `FREETIME_TEST_BUILDING_ID` exist in the target environment.

**SSL Certificate Errors:**
The tests use `CURLOPT_SSL_VERIFYPEER = false` for local testing. For production, enable SSL verification.

**No slots returned:**
Check that:
- The resource is configured correctly
- The resource has `simple_booking = 1`
- The date range is within the booking horizon
- The resource has operating hours configured

**All slots show as "time_in_past":**
Make sure you're testing with future dates. Use `+1 day` or `+2 days` in your test dates.

### Verifying Configuration

To verify which configuration is being used, check the test output:

```bash
./tests/booking/run-tests.sh kristiansand manual
```

The manual test script shows:
```
========================================
   FREETIME ENDPOINT MANUAL TEST
========================================
Base URL: https://kristiansand.aktiv-kommune.no
Resource ID: 6
Building ID: 1
========================================
```

## Output Example

```
========================================
   FREETIME ENDPOINT MANUAL TEST
========================================
Base URL: https://pe-api.test
Resource ID: 106
Building ID: 10
========================================

TEST 1: Basic Endpoint Response
----------------------------------------
  ✓ PASS: Response structure is valid (42 slots returned)

TEST 2: Time Slot Generation
----------------------------------------
  ✓ PASS: Time slots generated correctly (7 slots, 2-hour duration)

TEST 3: Time in Past Restriction
----------------------------------------
  ✓ PASS: Past time slots correctly marked as disabled (4 past slots found)

...

========================================
   TEST SUMMARY
========================================
Total Tests: 7
✓ Passed: 5
✗ Failed: 0
⊘ Skipped: 2
========================================

🎉 All tests passed!
```

## Contributing

When adding new tests:
1. Add the test case to `FreetimeEndpointTest.php` as a public method starting with `test`
2. Update the manual test script if needed
3. Document the test case in this README
4. Update the expected response structures if the API changes
