# Booking Freetime API v1 - Implementation Overview

**Project:** Migrate legacy phpgw freetime endpoint to modern Slim4 REST API
**Date:** 2026-01-20
**Status:** Planning Phase

---

## Executive Summary

### Goal
Create modern REST API endpoints that replace the legacy `?menuaction=bookingfrontend.uibooking.get_freetime` endpoint while maintaining 100% functional compatibility.

### Approach
1. **Test-Driven**: Copy and adapt all legacy tests to validate new API
2. **Incremental**: Reuse existing business logic, wrap in Slim4 controllers
3. **Validated**: Continuous comparison against legacy to ensure parity
4. **No Breaking Changes**: Same response format, same business rules

### Success Metrics
- ✅ All 24 legacy tests pass on new API
- ✅ 100% response parity with legacy
- ✅ Zero phpgw dependencies in new code
- ✅ Clean, maintainable Slim4 architecture

---

## Current State Analysis

### Legacy API

**Endpoint:**
```
GET /bookingfrontend/?menuaction=bookingfrontend.uibooking.get_freetime
    &building_id=10
    &resource_id=106
    &start_date=20/01-2026
    &end_date=27/01-2026
    &detailed_overlap=true
    &phpgw_return_as=json
```

**Code Path:**
1. `src/modules/bookingfrontend/inc/class.uibooking.inc.php::get_freetime()`
2. Sanitizes inputs
3. Calls `booking_bobooking::get_free_events()`
4. Returns JSON

**Issues Found & Fixed:**
- ✅ `overlap_event.type` was NULL → **Fixed**
- ✅ Resources array format wrong → **Fixed**
- ✅ Building queries used wrong variable → **Fixed**
- ⚠️ Booking horizon not enforced → **Not fixed** (design decision needed)
- ⚠️ Bookings hidden by allocations/blocks → **By design** (break statement)

**Test Coverage:**
- ✅ 24 comprehensive tests created
- ✅ Schema validation (8 tests)
- ✅ Functional validation (6 tests)
- ✅ General endpoint tests (10 tests)
- ✅ Manual test script

---

## New API Design

### Endpoints

#### 1. Resource Freetime
```
GET /bookingfrontend/resources/{resource_id}/freetime
```

**Purpose:** Get available time slots for a single resource

**Parameters:**
- `start_date` (YYYY-MM-DD, required)
- `end_date` (YYYY-MM-DD, required)
- `detailed_overlap` (boolean, optional)
- `stop_on_end_date` (boolean, optional)

**Response:** Array of time slot objects
```json
[
  {"when": "...", "start": "...", "end": "...", "overlap": false},
  {"when": "...", "start": "...", "end": "...", "overlap": 1, "overlap_event": {...}}
]
```

#### 2. Building Freetime
```
GET /bookingfrontend/building/{building_id}/freetime
```

**Purpose:** Get available time slots for ALL resources in a building

**Parameters:** Same as resource endpoint

**Response:** Object with resource IDs as keys
```json
{
  "106": [{...}, {...}],
  "107": [{...}, {...}]
}
```

### Key Improvements

| Aspect | Legacy | New |
|--------|--------|-----|
| **URL Style** | Query param `?menuaction=...` | RESTful `/resources/106/freetime` |
| **Date Format** | `DD/MM-YYYY` | `YYYY-MM-DD` (ISO 8601) |
| **Resource Query** | Returns `{106: [...]}` | Returns `[...]` directly |
| **Building Query** | Same | Same |
| **Dependencies** | phpgw framework | Pure Slim4 |

---

## Implementation Phases

### Phase 1: Test Preparation ✅ COMPLETE

**Objective:** Create test foundation before implementing

**Completed:**
- ✅ Organized test structure (`legacy/` vs `api/`)
- ✅ Copied and adapted validation tests
- ✅ Created JSON schemas
- ✅ Test runner supports both API styles
- ✅ Documented test structure

**Deliverables:**
```
tests/booking/api/v1/
├── building/freetime/
│   ├── ValidationTest.php
│   └── schemas/response.schema.json
└── resource/freetime/
    ├── ValidationTest.php
    └── schemas/response.schema.json
```

### Phase 2: Specification ⏳ IN PROGRESS

**Objective:** Define complete API specification

**Tasks:**
- ✅ API endpoint definitions
- ✅ Request/response formats
- ✅ Parameter specifications
- ⬜ Error handling details
- ⬜ Business logic mapping
- ⬜ Architecture decisions

**Deliverables:**
- `SPECIFICATION.md` - Detailed API spec
- `OVERVIEW.md` - This document
- Architecture diagrams (if needed)

### Phase 3: Planning

**Objective:** Plan implementation approach

**Tasks:**
- ⬜ Controller architecture
- ⬜ Dependency injection strategy
- ⬜ Route definitions
- ⬜ Error handling approach
- ⬜ Legacy business logic integration
- ⬜ Testing strategy (TDD approach)

**Deliverables:**
- Implementation plan document
- Code structure outline
- Step-by-step implementation tasks

### Phase 4: Implementation

**Objective:** Build Slim4 controllers with TDD

**Approach:**
1. Create controller skeleton
2. Implement resource endpoint
3. Run tests → Fix issues → Repeat
4. Implement building endpoint
5. Run tests → Fix issues → Repeat
6. Validate against legacy
7. Performance testing

**Success Criteria:**
- All 24+ tests pass
- 100% parity with legacy
- Clean code (no phpgw dependencies)

### Phase 5: Validation & Deployment

**Objective:** Validate in production environments

**Tasks:**
- ⬜ Test against Kristiansand production
- ⬜ Test against Bergen production
- ⬜ Performance comparison
- ⬜ Deploy to staging
- ⬜ Frontend integration testing
- ⬜ Deploy to production

---

## Architecture Overview

### Current Architecture (Legacy)

```
HTTP Request
  ↓
phpgw Framework (index.php)
  ↓
class.uibooking.inc.php::get_freetime()
  ├─ Sanitize inputs
  ├─ Convert date formats
  ├─ Call business logic ↓
  └→ booking_bobooking::get_free_events()
      ├─ Fetch resources
      ├─ Calculate horizons
      ├─ Fetch events/allocations/bookings
      ├─ Merge & add type fields ← Fixed
      ├─ Convert resource arrays ← Fixed
      ├─ Generate time slots
      ├─ Check overlaps
      └─ Return results
```

### Proposed Architecture (Slim4)

```
HTTP Request
  ↓
Slim4 Framework (routes)
  ↓
FreetimeController::resource() or ::building()
  ├─ Extract path parameters (resource_id/building_id)
  ├─ Parse query parameters
  ├─ Validate inputs
  ├─ Convert date format (YYYY-MM-DD → DateTime)
  ├─ Call business logic ↓
  └→ booking_bobooking::get_free_events()
      └─ [Same as legacy - reuse existing logic]
  ↓
Format response (resource endpoint: extract single resource)
  ↓
Return JSON response
```

### Key Differences

| Aspect | Legacy | Slim4 |
|--------|--------|-------|
| **Entry Point** | phpgw index.php | Slim4 route handler |
| **Input Parsing** | Sanitizer class | Slim Request object |
| **Date Format** | DD/MM-YYYY | YYYY-MM-DD |
| **Business Logic** | booking_bobooking | Same (reused) |
| **Response** | Direct return | PSR-7 Response object |

---

## Dependencies & Integration

### Reusing from Legacy

✅ **Business Logic Layer:**
- `booking_bobooking::get_free_events()`
- `booking_sobooking::*_ids_for_resource()`
- `booking_soresource::read()`
- All database queries
- Overlap detection logic

✅ **Fixes Applied:**
- Type field assignment
- Resource format conversion
- Correct variable usage

### New Slim4 Components

📋 **To Create:**
- `FreetimeController.php`
- Route definitions
- Request validation middleware (optional)
- Error handling
- Date format conversion utility

### Integration Points

```php
// In FreetimeController

private function callBusinessLogic($buildingId, $resourceId, $startDate, $endDate, $detailedOverlap, $stopOnEndDate)
{
    // Initialize legacy business object
    $bobooking = CreateObject('booking.bobooking');

    // Call with converted parameters
    return $bobooking->get_free_events(
        $buildingId,
        $resourceId,
        $startDate,  // DateTime object
        $endDate,    // DateTime object
        [],          // weekdays (empty for all)
        $stopOnEndDate,
        false,       // all_simple_bookings
        $detailedOverlap
    );
}
```

---

## Test Files Created

### API v1 Tests (New)

**Building Endpoint:**
```
tests/booking/api/v1/building/freetime/
├── ValidationTest.php          # Validates building endpoint
└── schemas/
    └── response.schema.json    # Same schema as legacy
```

**Resource Endpoint:**
```
tests/booking/api/v1/resource/freetime/
├── ValidationTest.php          # Validates resource endpoint
└── schemas/
    └── response.schema.json    # Same schema as legacy
```

**Key Tests:**
1. `testEndpointRespondsWithValidStructure()` - Basic structure
2. `testResponseMatchesLegacyAPI()` - **Parity check against legacy**
3. `testTimeSlotsMatchResourceConfiguration()` - Business logic
4. `testQueryParametersWork()` - Parameter handling

### Legacy Tests (Baseline)

Remain unchanged for comparison:
```
tests/booking/legacy/endpoints/uibooking/get_freetime/
├── EndpointTest.php
├── ValidationTest.php
├── SchemaValidationTest.php
└── manual-test.php
```

---

## Running Tests

### Test Legacy API (Baseline)
```bash
./tests/booking/scripts/run-tests.sh local
```

### Test New API (When implemented)
```bash
# Will need to update run-tests.sh to support api tests
vendor/bin/phpunit tests/booking/api/v1/resource/freetime/ValidationTest.php
vendor/bin/phpunit tests/booking/api/v1/building/freetime/ValidationTest.php
```

### Compare Legacy vs New
```bash
# Run both and compare results
./tests/booking/scripts/run-tests.sh local --api=both
# (To be implemented in run-tests.sh)
```

---

## Risk Assessment

### Low Risk

✅ **Reusing existing business logic** - No need to reimplement complex logic
✅ **Comprehensive test coverage** - 24+ tests validate behavior
✅ **Parallel deployment** - Can run alongside legacy
✅ **Proven fixes** - Already validated in legacy

### Medium Risk

⚠️ **phpgw dependencies** - `CreateObject()` still needed for business logic
⚠️ **Date format conversion** - Must correctly convert YYYY-MM-DD ↔ timestamps
⚠️ **Session/auth integration** - Must properly inject user context

### Mitigation

- Start with resource endpoint (simpler - single resource)
- Validate against legacy continuously
- Keep legacy as fallback during migration
- Extensive automated testing before deployment

---

## Timeline Estimate

| Phase | Complexity | Estimated Effort |
|-------|------------|------------------|
| Test Preparation | Low | ✅ Complete |
| Specification | Low | ⏳ In Progress |
| Planning | Medium | Next |
| Implementation | Medium | After plan approval |
| Validation | Low | Continuous |
| Deployment | Low | Final step |

**Note:** No time estimates per user preference - broken into discrete tasks

---

## Questions for Review

### Before Implementation

1. **Response format for resource endpoint:**
   - Option A: Array directly `[{...}, {...}]`
   - Option B: Wrapped `{106: [{...}, {...}]}` (same as legacy)
   - **Recommendation:** Option A (cleaner REST API)

2. **Booking horizon bug:**
   - Fix now or keep legacy behavior?
   - **Recommendation:** Keep legacy behavior initially, fix in v1.1

3. **Date format in response:**
   - Keep `when: "DD/MM-YYYY HH:MM - DD/MM-YYYY HH:MM"`?
   - **Recommendation:** Keep for compatibility

4. **Error response format:**
   - Use standard JSON error format?
   - **Recommendation:** Yes (documented in SPECIFICATION.md)

---

## Resources

### Documentation
- `SPECIFICATION.md` - Detailed API specification
- `OVERVIEW.md` - This document
- `tests/booking/docs/STRUCTURE.md` - Test organization

### Code
- Legacy: `src/modules/booking/inc/class.bobooking.inc.php`
- Legacy UI: `src/modules/bookingfrontend/inc/class.uibooking.inc.php`
- Fixes: See `tests/booking/docs/FIXES_APPLIED.md`

### Tests
- Legacy baseline: `tests/booking/legacy/endpoints/uibooking/get_freetime/`
- New API tests: `tests/booking/api/v1/{building,resource}/freetime/`

---

**Status:** Ready for planning phase
**Next Step:** Create detailed implementation plan
