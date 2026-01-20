# Freetime API v1 - Implementation Plan

**Version:** 1.0
**Date:** 2026-01-20
**Status:** Ready for Approval

---

## Executive Summary

### Goal
Implement two REST API endpoints that replace legacy freetime functionality while maintaining 100% compatibility.

### Endpoints to Create
1. `GET /bookingfrontend/resources/{id}/freetime` - Single resource freetime
2. `GET /bookingfrontend/building/{id}/freetime` - All resources in building

### Approach
- **Minimal initial implementation** - Call legacy business logic directly
- **Test-driven** - Run tests continuously during development
- **Incremental** - Build resource endpoint first, then building endpoint
- **Validated** - Compare against legacy at every step

### Estimated Complexity
**Low-Medium** - Mostly wrapping existing logic in Slim4 controllers

---

## Prerequisites (Already Complete)

✅ **Legacy bugs fixed** in `class.bobooking.inc.php`:
- Type field assignment
- Resource format conversion
- Query variable fix

✅ **Tests created** for both APIs:
- Legacy baseline tests (24 tests)
- Slim4 validation tests (7 tests)
- Schema validation
- Parity checks

✅ **Specifications written:**
- SPECIFICATION.md
- OVERVIEW.md
- EXISTING_ARCHITECTURE.md

---

## Implementation Steps

### Step 1: Create FreetimeController

**File:** `src/modules/bookingfrontend/controllers/FreetimeController.php`

**Tasks:**
1. Create controller class
2. Add constructor with dependency injection
3. Implement `resourceFreetime()` method
4. Implement `buildingFreetime()` method
5. Add OpenAPI annotations
6. Add error handling

**Code Structure:**
```php
<?php

namespace App\modules\bookingfrontend\controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\phpgwapi\services\Settings;
use DateTime;
use Exception;

/**
 * @OA\Tag(
 *     name="Freetime",
 *     description="API Endpoints for checking resource availability"
 * )
 */
class FreetimeController
{
    private $userSettings;
    private $bobooking;  // Legacy business object

    public function __construct(ContainerInterface $container)
    {
        $this->userSettings = Settings::getInstance()->get('user');
        // Initialize legacy business object
        $this->bobooking = \CreateObject('booking.bobooking');
    }

    /**
     * Get freetime for a specific resource
     *
     * @OA\Get(
     *     path="/bookingfrontend/resources/{id}/freetime",
     *     summary="Get available time slots for a resource",
     *     tags={"Freetime"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Resource ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=true,
     *         description="Start date (YYYY-MM-DD)",
     *         @OA\Schema(type="string", format="date", example="2026-01-20")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=true,
     *         description="End date (YYYY-MM-DD)",
     *         @OA\Schema(type="string", format="date", example="2026-01-27")
     *     ),
     *     @OA\Parameter(
     *         name="detailed_overlap",
     *         in="query",
     *         required=false,
     *         description="Include detailed overlap information",
     *         @OA\Schema(type="boolean", default=false)
     *     ),
     *     @OA\Parameter(
     *         name="stop_on_end_date",
     *         in="query",
     *         required=false,
     *         description="Stop on end date",
     *         @OA\Schema(type="boolean", default=false)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Array of available time slots",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/TimeSlot")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Invalid parameters"),
     *     @OA\Response(response=404, description="Resource not found"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function resourceFreetime(Request $request, Response $response, array $args): Response
    {
        try {
            // 1. Extract and validate parameters
            $resourceId = (int)$args['id'];
            $params = $request->getQueryParams();

            if (!isset($params['start_date']) || !isset($params['end_date'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'start_date and end_date are required'],
                    400
                );
            }

            // 2. Parse and convert date format
            $startDate = $this->parseDate($params['start_date']);
            $endDate = $this->parseDate($params['end_date']);

            if (!$startDate || !$endDate) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid date format. Expected YYYY-MM-DD'],
                    400
                );
            }

            // 3. Parse boolean parameters
            $detailedOverlap = filter_var(
                $params['detailed_overlap'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            $stopOnEndDate = filter_var(
                $params['stop_on_end_date'] ?? $detailedOverlap,  // Backward compat
                FILTER_VALIDATE_BOOLEAN
            );

            // 4. Call legacy business logic
            $result = $this->bobooking->get_free_events(
                null,              // building_id (not needed for resource query)
                $resourceId,
                $startDate,
                $endDate,
                [],                // weekdays (empty = all days)
                $stopOnEndDate,
                false,             // all_simple_bookings
                $detailedOverlap
            );

            // 5. Extract single resource's slots
            $slots = $result[$resourceId] ?? [];

            // 6. Return JSON response
            return ResponseHelper::sendJSONResponse($slots);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching freetime: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * Get freetime for all resources in a building
     *
     * @OA\Get(
     *     path="/bookingfrontend/building/{id}/freetime",
     *     summary="Get available time slots for all resources in a building",
     *     tags={"Freetime"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Building ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=true,
     *         description="Start date (YYYY-MM-DD)",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=true,
     *         description="End date (YYYY-MM-DD)",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="detailed_overlap",
     *         in="query",
     *         required=false,
     *         description="Include detailed overlap information",
     *         @OA\Schema(type="boolean", default=false)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Object with resource IDs as keys, time slot arrays as values",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\AdditionalProperties(
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/TimeSlot")
     *             )
     *         )
     *     )
     * )
     */
    public function buildingFreetime(Request $request, Response $response, array $args): Response
    {
        try {
            // 1. Extract and validate parameters
            $buildingId = (int)$args['id'];
            $params = $request->getQueryParams();

            if (!isset($params['start_date']) || !isset($params['end_date'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'start_date and end_date are required'],
                    400
                );
            }

            // 2. Parse dates
            $startDate = $this->parseDate($params['start_date']);
            $endDate = $this->parseDate($params['end_date']);

            if (!$startDate || !$endDate) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid date format. Expected YYYY-MM-DD'],
                    400
                );
            }

            // 3. Parse boolean parameters
            $detailedOverlap = filter_var(
                $params['detailed_overlap'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            $stopOnEndDate = filter_var(
                $params['stop_on_end_date'] ?? $detailedOverlap,
                FILTER_VALIDATE_BOOLEAN
            );

            // 4. Call legacy business logic
            $result = $this->bobooking->get_free_events(
                $buildingId,
                null,              // resource_id (null = all resources in building)
                $startDate,
                $endDate,
                [],                // weekdays
                $stopOnEndDate,
                false,             // all_simple_bookings
                $detailedOverlap
            );

            // 5. Return JSON response (object with resource IDs as keys)
            return ResponseHelper::sendJSONResponse($result);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching freetime: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * Parse date from YYYY-MM-DD format
     */
    private function parseDate(string $dateString): ?DateTime
    {
        try {
            $date = new DateTime($dateString);
            return $date;
        } catch (Exception $e) {
            return null;
        }
    }
}
```

**Lines of Code:** ~200 lines total

---

### Step 2: Add Routes

**File:** `src/modules/bookingfrontend/routes/Routes.php`

**Location:** Add to existing route groups

**Code to Add:**

```php
// In the /resources group (around line 51-59)
$group->group('/resources', function (RouteCollectorProxy $group)
{
    $group->get('', ResourceController::class . ':index');
    $group->get('/{id}', ResourceController::class . ':getResource');
    $group->get('/{id}/documents', ResourceController::class . ':getDocuments');
    $group->get('/{id}/schedule', ScheduleEntityController::class . ':getResourceSchedule');
    $group->get('/document/{id}/download', ResourceController::class . ':downloadDocument');

    // NEW: Freetime endpoint
    $group->get('/{id}/freetime', FreetimeController::class . ':resourceFreetime');
});

// After the /buildings group (around line 49), add new /building group
$group->get('/building/{id}/freetime', FreetimeController::class . ':buildingFreetime');
```

**Also add use statement at top:**
```php
use App\modules\bookingfrontend\controllers\FreetimeController;
```

---

### Step 3: Run Tests Continuously

**After creating controller:**

```bash
# Test resource endpoint
vendor/bin/phpunit tests/booking/api/v1/resource/freetime/ValidationTest.php

# Test building endpoint
vendor/bin/phpunit tests/booking/api/v1/building/freetime/ValidationTest.php

# Compare against legacy
./tests/booking/scripts/run-tests.sh local
```

**Expected initial state:**
- ❌ Tests fail (endpoint doesn't exist yet - 404)
- Then as we implement:
- ⚠️ Tests fail (endpoint exists but returns wrong data)
- Then after fixes:
- ✅ Tests pass (parity achieved)

---

### Step 4: Validate and Iterate

**Process:**
1. Write controller method
2. Run tests
3. Fix issues revealed by tests
4. Re-run tests
5. Repeat until all pass

**Key validations:**
- Response structure matches schema
- Overlaps detected correctly
- Type fields populated
- Building query vs resource query parity
- Legacy API comparison (100% match)

---

## Files to Create/Modify

### New Files

1. ✅ `tests/booking/api/v1/resource/freetime/ValidationTest.php` (created)
2. ✅ `tests/booking/api/v1/building/freetime/ValidationTest.php` (created)
3. ✅ `tests/booking/api/v1/SPECIFICATION.md` (created)
4. ✅ `tests/booking/api/v1/OVERVIEW.md` (created)
5. ✅ `tests/booking/docs/EXISTING_ARCHITECTURE.md` (created)
6. ⬜ `src/modules/bookingfrontend/controllers/FreetimeController.php` (to create)

### Files to Modify

7. ⬜ `src/modules/bookingfrontend/routes/Routes.php` (add 2 routes)

### Optional Files

8. ⬜ `src/modules/bookingfrontend/models/TimeSlot.php` (if we want a model - can use arrays)
9. ⬜ `src/modules/bookingfrontend/services/FreetimeService.php` (if extracting business logic - can skip initially)

---

## Detailed Task Breakdown

### Task 1.1: Create Controller File

**File:** `src/modules/bookingfrontend/controllers/FreetimeController.php`

```bash
# Create file
touch src/modules/bookingfrontend/controllers/FreetimeController.php
```

**Content:** See Step 1 above (full controller code provided)

**Validation:**
```bash
# Check syntax
php -l src/modules/bookingfrontend/controllers/FreetimeController.php
```

---

### Task 1.2: Implement resourceFreetime() Method

**Requirements:**
- Extract `resource_id` from path: `$args['id']`
- Extract `start_date`, `end_date` from query params
- Convert YYYY-MM-DD to DateTime objects
- Call `booking_bobooking::get_free_events()`
- Extract single resource from result: `$result[$resourceId]`
- Return as JSON array

**Date Conversion:**
```php
// Input: "2026-01-20" (YYYY-MM-DD)
// Convert to: DateTime object
$startDate = new DateTime($params['start_date']);

// Legacy expects DateTime, so this works directly
$bobooking->get_free_events(..., $startDate, $endDate, ...);
```

**Response Extraction:**
```php
// Legacy returns: {106: [...], 107: [...]}
// Extract only our resource: [...]
$result = $bobooking->get_free_events(...);
$slots = $result[$resourceId] ?? [];  // Get just the array

return ResponseHelper::sendJSONResponse($slots);  // Return array directly
```

**Testing:**
```bash
# Should pass after implementation
vendor/bin/phpunit tests/booking/api/v1/resource/freetime/ValidationTest.php

# Test manually
curl -k "https://pe-api.test/bookingfrontend/resources/106/freetime?start_date=2026-01-21&end_date=2026-01-21&detailed_overlap=true"
```

---

### Task 1.3: Implement buildingFreetime() Method

**Requirements:**
- Extract `building_id` from path: `$args['id']`
- Same query parameter handling as resource
- Call `booking_bobooking::get_free_events()` with building_id
- Return full result (object with resource IDs as keys)

**Key Difference from Resource:**
```php
// Resource endpoint
$result = $bobooking->get_free_events(null, $resourceId, ...);
return $result[$resourceId];  // Return array

// Building endpoint
$result = $bobooking->get_free_events($buildingId, null, ...);
return $result;  // Return whole object
```

**Testing:**
```bash
vendor/bin/phpunit tests/booking/api/v1/building/freetime/ValidationTest.php

# Test manually
curl -k "https://pe-api.test/bookingfrontend/building/10/freetime?start_date=2026-01-21&end_date=2026-01-21"
```

---

### Task 2: Update Routes

**File:** `src/modules/bookingfrontend/routes/Routes.php`

**Changes:**

**2.1: Add use statement (line ~16):**
```php
use App\modules\bookingfrontend\controllers\FreetimeController;
```

**2.2: Add resource freetime route (in /resources group, line ~58):**
```php
$group->get('/{id}/freetime', FreetimeController::class . ':resourceFreetime');
```

**2.3: Add building freetime route (after /buildings group, line ~49):**
```php
$group->get('/building/{id}/freetime', FreetimeController::class . ':buildingFreetime');
```

**Testing:**
```bash
# Test routes are registered
curl -k https://pe-api.test/bookingfrontend/resources/106/freetime?start_date=2026-01-20&end_date=2026-01-20

# Should get JSON response (not 404)
```

---

### Task 3: Error Handling

**Scenarios to Handle:**

**3.1: Missing Parameters**
```php
if (!isset($params['start_date']) || !isset($params['end_date'])) {
    return ResponseHelper::sendErrorResponse(
        ['error' => 'start_date and end_date are required'],
        400
    );
}
```

**3.2: Invalid Date Format**
```php
if (!$startDate || !$endDate) {
    return ResponseHelper::sendErrorResponse(
        ['error' => 'Invalid date format. Expected YYYY-MM-DD, got: ' . ($params['start_date'] ?? 'null')],
        400
    );
}
```

**3.3: Resource Not Found**
```php
// Legacy returns empty array for invalid resource
// We could optionally check first:
if (empty($slots) && $resourceId > 0) {
    // Might be invalid resource - but legacy just returns []
    // So we should too for compatibility
}
```

**3.4: Business Logic Exceptions**
```php
catch (Exception $e) {
    return ResponseHelper::sendErrorResponse(
        ['error' => 'Error fetching freetime: ' . $e->getMessage()],
        500
    );
}
```

---

### Task 4: Testing & Validation

**4.1: Unit Test Each Method**

```bash
# Resource endpoint
vendor/bin/phpunit tests/booking/api/v1/resource/freetime/ValidationTest.php --filter testEndpointRespondsWithValidStructure

# Building endpoint
vendor/bin/phpunit tests/booking/api/v1/building/freetime/ValidationTest.php --filter testEndpointRespondsWithMultipleResources
```

**4.2: Parity Tests (Critical!)**

```bash
# These compare Slim4 vs legacy
vendor/bin/phpunit tests/booking/api/v1/resource/freetime/ValidationTest.php --filter testResponseMatchesLegacyAPI

vendor/bin/phpunit tests/booking/api/v1/building/freetime/ValidationTest.php --filter testResponseMatchesLegacyBuildingQuery
```

**4.3: Schema Validation**

```bash
# If we create schema tests for Slim4
vendor/bin/phpunit tests/booking/api/v1/resource/freetime/SchemaValidationTest.php
```

**4.4: Manual Testing**

```bash
# Resource endpoint
curl -k "https://pe-api.test/bookingfrontend/resources/106/freetime?start_date=2026-01-21&end_date=2026-01-21&detailed_overlap=true" | jq

# Building endpoint
curl -k "https://pe-api.test/bookingfrontend/building/10/freetime?start_date=2026-01-21&end_date=2026-01-21&detailed_overlap=true" | jq

# Compare with legacy
curl -k "https://pe-api.test/bookingfrontend/?menuaction=bookingfrontend.uibooking.get_freetime&resource_id=106&start_date=21/01-2026&end_date=21/01-2026&detailed_overlap=true&phpgw_return_as=json" | jq
```

---

## Success Criteria

### Functional Requirements

✅ **Resource endpoint returns correct data:**
- Array of time slots
- Matches legacy format exactly
- All overlaps detected
- Type fields populated

✅ **Building endpoint returns correct data:**
- Object with resource IDs as keys
- Each resource has time slot array
- Matches legacy format exactly

✅ **100% parity with legacy:**
- Same slot count
- Same overlap detection
- Same overlap_event details
- Same type fields

### Technical Requirements

✅ **No phpgw dependencies in controller:**
- No Sanitizer
- No direct $GLOBALS usage
- Pure Slim4 code

✅ **Follows existing patterns:**
- Uses ResponseHelper
- Uses try/catch error handling
- Uses proper type hints
- Has OpenAPI annotations

✅ **Tests pass:**
- Resource ValidationTest: 4/4 tests
- Building ValidationTest: 3/3 tests
- Schema validation
- Legacy comparison tests

---

## Risk Mitigation

### Low-Risk Approach

**What we're NOT doing:**
- ❌ Rewriting business logic (reuse existing)
- ❌ Changing response format (100% compatible)
- ❌ Changing database structure
- ❌ Removing legacy API (parallel operation)

**What we ARE doing:**
- ✅ Wrapping proven logic in modern API
- ✅ Following existing Slim4 patterns
- ✅ Comprehensive testing at every step
- ✅ Easy rollback (just remove routes)

### Testing Safety Net

**24+ tests validate:**
- Correct response structure
- Correct overlap detection
- Correct type fields
- Correct business logic
- Parity with legacy

**If anything breaks:** Tests immediately fail

---

## Timeline Breakdown

| Task | Complexity | Dependencies |
|------|------------|--------------|
| 1.1 Create controller file | Trivial | None |
| 1.2 Implement resourceFreetime() | Low | Task 1.1 |
| 1.3 Implement buildingFreetime() | Low | Task 1.2 (copy pattern) |
| 2. Add routes | Trivial | Task 1.3 |
| 3. Error handling | Low | Task 1.2, 1.3 |
| 4. Testing | Medium | All above |

**Critical Path:** 1.1 → 1.2 → Tests → 1.3 → Tests → 2 → Final tests

---

## Post-Implementation

### Documentation

- ⬜ Update API documentation
- ⬜ Add examples to README
- ⬜ Document any differences from legacy
- ⬜ Migration guide for frontend

### Monitoring

- ⬜ Add logging for errors
- ⬜ Monitor usage metrics
- ⬜ Compare performance vs legacy

### Future Improvements

- ⬜ Extract to FreetimeService
- ⬜ Add caching layer
- ⬜ Fix booking horizon bug
- ⬜ Consider returning all overlaps (not just first)

---

## Checklist

### Before Starting Implementation

- [x] Legacy bugs fixed and validated
- [x] Tests created for new API
- [x] Specifications written
- [x] Existing architecture analyzed
- [x] Implementation plan created
- [ ] Plan approved by user

### During Implementation

- [ ] Controller created
- [ ] resourceFreetime() implemented
- [ ] Resource tests passing
- [ ] buildingFreetime() implemented
- [ ] Building tests passing
- [ ] Routes added
- [ ] Error handling complete
- [ ] OpenAPI annotations added

### Before Deployment

- [ ] All tests passing
- [ ] Parity validated against legacy
- [ ] Manual testing complete
- [ ] Code review
- [ ] Documentation updated

---

## Questions for Approval

### 1. Response Format for Resource Endpoint

**Option A (Recommended):** Return array directly
```json
GET /resources/106/freetime
→ [{...}, {...}, {...}]
```

**Option B:** Wrap in object (legacy compatible)
```json
GET /resources/106/freetime
→ {106: [{...}, {...}, {...}]}
```

**Recommendation:** Option A (cleaner REST API, but tests will need adjustment)

### 2. Error Response Format

**Proposed:**
```json
{
  "error": {
    "code": "INVALID_DATE_FORMAT",
    "message": "Invalid date format. Expected YYYY-MM-DD",
    "field": "start_date"
  }
}
```

**Or simpler:**
```json
{
  "error": "Invalid date format. Expected YYYY-MM-DD"
}
```

**Recommendation:** Simpler format (matches existing ResponseHelper pattern)

### 3. Booking Horizon Bug

**Current state:** Not enforced (Day +9 returns slots)

**Options:**
- A) Fix now (might break existing behavior)
- B) Keep legacy behavior (defer to later)

**Recommendation:** Keep legacy behavior for v1.0, fix in v1.1

---

**Plan Status:** Ready for approval
**Next Action:** User approval to proceed with implementation
