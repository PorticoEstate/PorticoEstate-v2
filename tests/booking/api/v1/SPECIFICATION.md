# Booking Freetime API v1 Specification

**Version:** 1.0
**Date:** 2026-01-20
**Status:** Planning
**Based on:** Legacy `?menuaction=bookingfrontend.uibooking.get_freetime`

---

## Overview

This specification defines the new Slim4 REST API endpoints for querying available time slots (freetime) for bookings. It replaces the legacy phpgw-style API while maintaining 100% functional compatibility.

### Design Goals

1. ✅ **100% Backward Compatible** - Same response format as legacy API
2. ✅ **RESTful Design** - Clean URL structure, proper HTTP methods
3. ✅ **No phpgw Dependencies** - Pure Slim4 implementation
4. ✅ **Validated by Tests** - All tests from legacy must pass
5. ✅ **Same Business Logic** - Reuses existing `booking_bobooking` class

---

## API Endpoints

### 1. Resource Freetime

**Get available time slots for a specific resource**

```
GET /bookingfrontend/resources/{resource_id}/freetime
```

**Path Parameters:**
- `resource_id` (integer, required) - The resource ID

**Query Parameters:**
- `start_date` (string, required) - Start date in format `YYYY-MM-DD`
- `end_date` (string, required) - End date in format `YYYY-MM-DD`
- `detailed_overlap` (boolean, optional) - Include detailed overlap information (default: false)
- `stop_on_end_date` (boolean, optional) - Stop on end date (default: false)

**Example:**
```
GET /bookingfrontend/resources/106/freetime?start_date=2026-01-20&end_date=2026-01-27&detailed_overlap=true
```

**Response:** Array of time slots (see Response Format below)

**Legacy Equivalent:**
```
GET /bookingfrontend/?menuaction=bookingfrontend.uibooking.get_freetime&resource_id=106&start_date=20/01-2026&end_date=27/01-2026&detailed_overlap=true&phpgw_return_as=json
```

---

### 2. Building Freetime

**Get available time slots for ALL resources in a building**

```
GET /bookingfrontend/building/{building_id}/freetime
```

**Path Parameters:**
- `building_id` (integer, required) - The building ID

**Query Parameters:**
- `start_date` (string, required) - Start date in format `YYYY-MM-DD`
- `end_date` (string, required) - End date in format `YYYY-MM-DD`
- `detailed_overlap` (boolean, optional) - Include detailed overlap information (default: false)
- `stop_on_end_date` (boolean, optional) - Stop on end date (default: false)

**Example:**
```
GET /bookingfrontend/building/10/freetime?start_date=2026-01-20&end_date=2026-01-27&detailed_overlap=true
```

**Response:** Object with resource IDs as keys, time slot arrays as values

**Legacy Equivalent:**
```
GET /bookingfrontend/?menuaction=bookingfrontend.uibooking.get_freetime&building_id=10&start_date=20/01-2026&end_date=27/01-2026&detailed_overlap=true&phpgw_return_as=json
```

---

## Response Format

### Resource Endpoint Response

**Structure:** Array of time slot objects

```json
[
  {
    "when": "21/01-2026 08:00 - 21/01-2026 10:00",
    "start": "1737442800000",
    "end": "1737450000000",
    "overlap": false,
    "start_iso": "2026-01-21T08:00:00+01:00",
    "end_iso": "2026-01-21T10:00:00+01:00",
    "resource_id": 106
  },
  {
    "when": "21/01-2026 10:00 - 21/01-2026 12:00",
    "start": "1737450000000",
    "end": "1737457200000",
    "overlap": 1,
    "start_iso": "2026-01-21T10:00:00+01:00",
    "end_iso": "2026-01-21T12:00:00+01:00",
    "resource_id": 106,
    "overlap_reason": "complete_overlap",
    "overlap_type": "complete",
    "overlap_event": {
      "id": 60944,
      "type": "allocation",
      "status": null,
      "from": "2026-01-21 10:00:00",
      "to": "2026-01-21 12:00:00"
    }
  }
]
```

### Building Endpoint Response

**Structure:** Object with resource IDs as keys

```json
{
  "106": [
    {
      "when": "21/01-2026 08:00 - 21/01-2026 10:00",
      "start": "1737442800000",
      "end": "1737450000000",
      "overlap": false,
      "start_iso": "2026-01-21T08:00:00+01:00",
      "end_iso": "2026-01-21T10:00:00+01:00",
      "resource_id": 106
    }
  ],
  "107": [
    {
      "when": "21/01-2026 08:00 - 21/01-2026 10:00",
      ...
    }
  ]
}
```

**Note:** This is identical to the legacy format.

---

## Time Slot Object Schema

See `schemas/response.schema.json` for formal JSON Schema.

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `when` | string | Human-readable time range (DD/MM-YYYY HH:MM - DD/MM-YYYY HH:MM) |
| `start` | string | Start timestamp in milliseconds (13 digits) |
| `end` | string | End timestamp in milliseconds (13 digits) |
| `overlap` | boolean\|integer | Overlap status: false/0 (available), 1 (complete), 2 (partial/block), 3 (disabled) |

### Optional Fields (Always Present with detailed_overlap=true)

| Field | Type | Description |
|-------|------|-------------|
| `start_iso` | string | Start time in ISO 8601 format with timezone |
| `end_iso` | string | End time in ISO 8601 format with timezone |
| `resource_id` | integer | Resource ID |

### Conditional Fields (Present when overlap ≠ false/0)

| Field | Type | Description |
|-------|------|-------------|
| `overlap_reason` | string | Reason for overlap (see Overlap Reasons below) |
| `overlap_type` | string | Type of overlap: "disabled", "complete", "partial" |
| `overlap_event` | object | Details about the overlapping event (when overlap=1 or 2) |

### overlap_event Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | ID of the overlapping event/booking/allocation/block |
| `type` | string | Type: "event", "booking", "allocation", "block", "application" |
| `status` | string\|null | Status of the item (can be null) |
| `from` | string | Start datetime (YYYY-MM-DD HH:MM:SS) |
| `to` | string | End datetime (YYYY-MM-DD HH:MM:SS) |

---

## Overlap Status Codes

| Code | Meaning | Description |
|------|---------|-------------|
| `false` or `0` | Available | No overlap, slot is bookable |
| `1` | Complete Overlap | Existing reservation covers the slot |
| `2` | Partial/Block | Partial overlap or resource block |
| `3` | Disabled | Slot is in the past (time_in_past) |

## Overlap Reasons

| Reason | Description |
|--------|-------------|
| `time_in_past` | Slot is in the past and cannot be booked |
| `complete_overlap` | Existing reservation has identical time boundaries or completely covers the slot |
| `complete_containment` | Existing reservation is contained within the requested slot |
| `start_overlap` | Partial overlap at the start of the slot |
| `end_overlap` | Partial overlap at the end of the slot |

## Overlap Event Types

| Type | Source | Description |
|------|--------|-------------|
| `event` | `bb_event` | Event from events table |
| `booking` | `bb_booking` | Booking from bookings table |
| `allocation` | `bb_allocation` | Allocation from allocations table |
| `block` | `bb_block` | Resource block |
| `application` | `bb_application` | Partial application (temp reservation) |

**Note:** Due to `break` in overlap detection, only the first matching overlap is returned.

---

## Business Logic

### Time Slot Generation

1. **Based on Resource Configuration:**
   - Slot duration: `booking_time_minutes` (e.g., 120 for 2-hour slots)
   - Operating hours: `booking_time_default_start` to `booking_time_default_end`
   - Start date: Respects `simple_booking_start_date` if configured
   - End date: Limited by `simple_booking_end_date` if configured

2. **Booking Horizon:**
   - Day-based: `booking_day_horizon` (e.g., 8 days)
   - Month-based: `booking_month_horizon` (e.g., 3 months)
   - Slots beyond horizon should not be returned (⚠️ currently buggy)

3. **Time Restrictions:**
   - Slots in the past marked as `overlap: 3`, `reason: time_in_past`
   - Buffer deadline: `booking_buffer_deadline` minutes before current time

### Overlap Detection

1. **Fetch Scheduled Items:**
   - Events from `bb_event` + `bb_event_resource`
   - Allocations from `bb_allocation` + `bb_allocation_resource`
   - Bookings from `bb_booking` + `bb_booking_resource`
   - Blocks from `bb_block`
   - Partial applications (status: NEWPARTIAL1)

2. **Merge All Items:**
   - Add `type` field to each item
   - Convert `resources` from object arrays to simple ID arrays
   - Merge into single array

3. **Check Each Slot:**
   - Loop through all scheduled items
   - Check if item's time overlaps with slot time
   - Return first match (due to `break` statement)
   - Set overlap status based on item type (block=2, others=1)

---

## Parameter Handling

### Date Format Conversion

**Legacy API:**
- Input: `DD/MM-YYYY` (e.g., `20/01-2026`)
- Parsed by: `Sanitizer::get_var('start_date', 'date')`

**Slim4 API:**
- Input: `YYYY-MM-DD` (e.g., `2026-01-20`) - ISO 8601 standard
- Must convert to timestamp for legacy business logic

### Boolean Parameters

**Legacy API:**
- `detailed_overlap` as boolean via Sanitizer
- `stop_on_end_date` as boolean via Sanitizer

**Slim4 API:**
- `detailed_overlap=true|false` as string, convert to boolean
- `stop_on_end_date=true|false` as string, convert to boolean

---

## Implementation Requirements

### Must Use

✅ **Existing Business Logic:**
- `booking_bobooking::get_free_events()` - Core freetime logic
- `booking_sobooking::*_ids_for_resource()` - Fetch IDs
- `booking_soresource::read()` - Fetch resource config

✅ **Fixed Issues:**
- Type field assignment for all entity types
- Resource array format conversion (objects → IDs)
- Correct loop variable usage (`$resource['id']` not `$resource_id`)

### Must NOT Use

❌ **phpgw Components:**
- No `Sanitizer` - Use Slim request methods
- No `CreateObject()` - Use dependency injection
- No `$GLOBALS['phpgw']` - Pure Slim4 approach

### Must Implement

📋 **Slim4 Controller:**
- `BookingFreetimeController` or similar
- Two action methods: `resource()` and `building()`
- Proper dependency injection
- Clean separation of concerns

---

## Route Definitions

### routes.php or similar

```php
$app->get('/bookingfrontend/resources/{resource_id:\d+}/freetime',
    [BookingFreetimeController::class, 'resource']);

$app->get('/bookingfrontend/building/{building_id:\d+}/freetime',
    [BookingFreetimeController::class, 'building']);
```

---

## Controller Structure (Proposed)

```php
namespace App\Controllers\Booking;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use DateTime;

class FreetimeController
{
    private $bobooking;  // booking_bobooking instance

    public function __construct()
    {
        // Inject or create booking_bobooking instance
        $this->bobooking = CreateObject('booking.bobooking');
    }

    /**
     * GET /bookingfrontend/resources/{resource_id}/freetime
     */
    public function resource(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        // Extract parameters
        $resourceId = (int)$args['resource_id'];
        $queryParams = $request->getQueryParams();

        $startDate = $this->parseDate($queryParams['start_date'] ?? null);
        $endDate = $this->parseDate($queryParams['end_date'] ?? null);
        $detailedOverlap = filter_var($queryParams['detailed_overlap'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $stopOnEndDate = filter_var($queryParams['stop_on_end_date'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Call legacy business logic
        $result = $this->bobooking->get_free_events(
            null,  // building_id (not needed for resource query)
            $resourceId,
            $startDate,
            $endDate,
            [],  // weekdays
            $stopOnEndDate,
            false,  // all_simple_bookings
            $detailedOverlap
        );

        // Extract just this resource's slots
        $slots = $result[$resourceId] ?? [];

        // Return JSON response
        $response->getBody()->write(json_encode($slots));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /bookingfrontend/building/{building_id}/freetime
     */
    public function building(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        // Extract parameters
        $buildingId = (int)$args['building_id'];
        $queryParams = $request->getQueryParams();

        $startDate = $this->parseDate($queryParams['start_date'] ?? null);
        $endDate = $this->parseDate($queryParams['end_date'] ?? null);
        $detailedOverlap = filter_var($queryParams['detailed_overlap'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $stopOnEndDate = filter_var($queryParams['stop_on_end_date'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Call legacy business logic (without resource_id)
        $result = $this->bobooking->get_free_events(
            $buildingId,
            null,  // resource_id (returns all resources in building)
            $startDate,
            $endDate,
            [],  // weekdays
            $stopOnEndDate,
            false,  // all_simple_bookings
            $detailedOverlap
        );

        // Return JSON response (object with resource IDs as keys)
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Parse date from YYYY-MM-DD to DateTime
     */
    private function parseDate(?string $dateString): ?DateTime
    {
        if (!$dateString) {
            return null;
        }

        try {
            return new DateTime($dateString);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid date format. Expected YYYY-MM-DD, got: {$dateString}");
        }
    }
}
```

---

## Differences from Legacy API

### URL Structure

| Aspect | Legacy | Slim4 |
|--------|--------|-------|
| **Pattern** | `/?menuaction=X` | `/resources/{id}/freetime` |
| **Resource query** | `?menuaction=...&resource_id=106` | `/resources/106/freetime` |
| **Building query** | `?menuaction=...&building_id=10` | `/building/10/freetime` |
| **HTTP Method** | GET | GET |

### Date Format

| Parameter | Legacy | Slim4 |
|-----------|--------|-------|
| `start_date` | `DD/MM-YYYY` (20/01-2026) | `YYYY-MM-DD` (2026-01-20) |
| `end_date` | `DD/MM-YYYY` (27/01-2026) | `YYYY-MM-DD` (2026-01-27) |

**Rationale:** ISO 8601 is the international standard

### Response Format

| Aspect | Legacy | Slim4 |
|--------|--------|-------|
| **Resource query** | `{106: [...]}` | `[...]` (just the array) |
| **Building query** | `{106: [...], 107: [...]}` | Same |
| **Slot structure** | Identical | Identical |

**Note:** Resource endpoint returns array directly, not wrapped in object with resource ID as key.

---

## Test Coverage Requirements

All legacy tests must pass for the new endpoints:

### Schema Validation
- ✅ Response structure matches schema
- ✅ All required fields present
- ✅ Field types correct
- ✅ Timestamp formats valid
- ✅ Enum values valid
- ✅ Conditional fields present when needed

### Functional Validation
- ✅ Time slots match resource configuration
- ✅ Scheduled items appear as overlaps
- ✅ Available slots have no scheduled items
- ✅ Overlap event details accurate
- ✅ Type field populated correctly
- ✅ Resource format conversion working

### Parity Validation
- ✅ Resource endpoint matches legacy resource query
- ✅ Building endpoint matches legacy building query
- ✅ Same overlap detection logic
- ✅ Same time slot generation
- ✅ Same error handling

---

## Error Handling

### HTTP Status Codes

| Code | Condition |
|------|-----------|
| `200 OK` | Successful response |
| `400 Bad Request` | Invalid parameters (missing dates, invalid format) |
| `404 Not Found` | Resource/building not found |
| `500 Internal Server Error` | Server error |

### Error Response Format

```json
{
  "error": {
    "code": "INVALID_DATE_FORMAT",
    "message": "Invalid date format. Expected YYYY-MM-DD, got: 20/01-2026",
    "field": "start_date"
  }
}
```

---

## Known Issues to Fix

### Must Fix in New Implementation

1. ✅ **Type field** - Already fixed in legacy, must implement
2. ✅ **Resource format** - Already fixed in legacy, must implement
3. ✅ **Query variable** - Already fixed in legacy, must implement
4. ⚠️ **Booking horizon** - Currently not enforced, decide if fixing

### Design Decisions Needed

1. **Bookings hidden by allocations/blocks**
   - Current: Only first overlap returned (due to `break`)
   - Options:
     - A) Keep current behavior (simplest)
     - B) Return all overlaps as array
     - C) Prioritize certain types (blocks > allocations > bookings)

2. **Date format in response**
   - Current: `when` field uses DD/MM-YYYY format
   - Options:
     - A) Keep for compatibility
     - B) Change to YYYY-MM-DD (breaking change)
     - C) Add both formats

---

## Migration Strategy

### Phase 1: Parallel Operation (Current Phase)
- ✅ Legacy API remains primary
- ✅ Slim4 API implemented alongside
- ✅ Both return identical data
- ✅ Tests validate parity

### Phase 2: Gradual Migration
- Update frontend to use new API endpoints
- Monitor for issues
- Keep legacy as fallback

### Phase 3: Legacy Deprecation
- Mark legacy endpoints as deprecated
- Redirect to new endpoints
- Eventually remove legacy code

---

## Testing Strategy

### Test Execution Order

1. **Run against legacy (baseline):**
   ```bash
   ./tests/booking/scripts/run-tests.sh local
   ```

2. **Implement Slim4 endpoint**

3. **Run against Slim4 (validate):**
   ```bash
   ./tests/booking/scripts/run-tests.sh local --api=slim4
   ```

4. **Compare results (parity check):**
   - Legacy vs Slim4 response comparison
   - Should be 100% identical

### Success Criteria

- ✅ All schema tests pass
- ✅ All validation tests pass
- ✅ Slim4 matches legacy 100%
- ✅ No performance regression
- ✅ Clean code (no phpgw dependencies)

---

## File Structure

```
src/modules/bookingfrontend/
├── controllers/
│   └── FreetimeController.php          # New Slim4 controller
├── routes/
│   └── freetime.php                     # Route definitions
└── ...

tests/booking/
├── legacy/endpoints/uibooking/get_freetime/  # Legacy tests (baseline)
│   ├── ValidationTest.php
│   ├── SchemaValidationTest.php
│   └── ...
└── api/v1/                                    # Slim4 tests
    ├── building/freetime/
    │   ├── ValidationTest.php           # Building endpoint tests
    │   └── schemas/response.schema.json
    └── resource/freetime/
        ├── ValidationTest.php           # Resource endpoint tests
        └── schemas/response.schema.json
```

---

## Dependencies

### Required Components

- ✅ `booking_bobooking` class (exists)
- ✅ `booking_sobooking` class (exists)
- ✅ `booking_soresource` class (exists)
- ✅ Resource/event/allocation/booking SO classes (exist)
- ✅ Slim4 framework (exists)

### Must Inject

- Database connection
- User session/auth
- Configuration/settings

---

## Next Steps

1. ✅ **Tests Created** - Validation tests for both endpoints
2. ⏭️ **Create Specs** - This document
3. ⏭️ **Plan Implementation** - Detailed architecture
4. ⏭️ **Implement** - Build controllers with TDD
5. ⏭️ **Validate** - Run all tests continuously
6. ⏭️ **Deploy** - Parallel with legacy

---

## References

- Legacy implementation: `src/modules/booking/inc/class.bobooking.inc.php::get_free_events()`
- Legacy endpoint: `src/modules/bookingfrontend/inc/class.uibooking.inc.php::get_freetime()`
- JSON Schema: `tests/booking/api/v1/*/freetime/schemas/response.schema.json`
- Test suite: `tests/booking/api/v1/*/freetime/*Test.php`

---

**Specification Status:** Draft
**Ready for Implementation:** Pending plan approval
**Estimated Complexity:** Medium (reuses existing business logic)
