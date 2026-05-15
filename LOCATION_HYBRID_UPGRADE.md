# Location Module: Hybrid Approach Upgrade

**Date:** May 15, 2026  
**Status:** ✅ Complete  
**Branch:** `sigurd/client_migration`  
**Commits:** (see git log for details)

---

## Overview

Location module has been upgraded from pure **Thin Adapter** pattern to a **Hybrid Approach** that combines:
- ✅ Lightweight REST controller (clean, minimal)
- ✅ Dedicated form helper for explicit orchestration
- ✅ Parameterized database access (security hardened)
- ✅ Consolidated validation and error handling
- ✅ Clear state management (no global superglobals for writes)

---

## What Changed

### 1. New: LocationFormHelper Class

**File:** `src/modules/property/helpers/LocationFormHelper.php` (525 lines)

Orchestrates add/edit/save workflows with explicit state management:

```
Request Data
    ↓
mapInput()          → Normalize + enrich with location context
    ↓
validate()          → Consolidated field + structural validation
    ↓
persistSave()       → Explicit transaction management (transaction_begin/commit)
    ↓
buildSaveResponse() → JSON response with error recovery data
```

**Key Methods:**
- `mapInput()` - Normalize request data, load existing location for edits
- `validate()` - Type-safe validation (required fields, formats, constraints)
- `persistSave()` - Parameterized insert/update with transaction safety
- `buildSaveResponse()` - Return response payload with errors for retry

**Features:**
- No global state mutation (`$_GET`/`$_POST`)
- Parameterized SQL with `:placeholder` syntax
- Type casting for numeric fields (int)
- Validated columns via fieldMap metadata
- Transaction rollback on errors
- Error accumulation and recovery data in response

---

### 2. Updated: LocationController

**File:** `src/modules/property/controllers/LocationController.php` (modified)

**Changes:**
- ✅ Inject `LocationFormHelper` in constructor from container
- ✅ Add new public method `add()` - Create location with form helper
- ✅ Add new public method `save(int $location_id)` - Update location with form helper
- ✅ Keep existing thin adapter methods (index, summary, etc.)
- ✅ Support HTTP status codes in `jsonResponse()` for error responses

**Architecture Layers:**

| Operation | Pattern | Method | Flow |
|-----------|---------|--------|------|
| GET index | Thin Adapter | `index()` | hydrateRequestGlobals() → legacy uilocation |
| GET summary | Thin Adapter | `summary()` | hydrateRequestGlobals() → legacy uilocation |
| POST add | Hybrid | `add()` | mapInput() → validate() → persistSave() → buildResponse() |
| PUT /id | Hybrid | `save()` | mapInput() → validate() → persistSave() → buildResponse() |
| DELETE | Thin Adapter | `delete()` | hydrateRequestGlobals() → legacy uilocation |

**Benefits:**
- Read-only operations still use proven legacy code (low risk)
- Write operations use explicit form helper (testable, secure, maintainable)
- No breaking changes to existing API consumers
- Gradual evolution: reads can migrate later when legacy code is retired

---

### 3. New Routes

**File:** `src/modules/property/routes/Routes.php` (modified)

Added two new hybrid approach routes:

```php
$group->post('/add', [$controller, 'add']);
$group->put('/{location_id:[0-9]+}', [$controller, 'save']);
```

**Usage Examples:**

Create new location:
```
POST /property/location/add
Content-Type: application/json

{
  "loc_code": "LOC-001",
  "loc1": "Building A",
  "loc2": "Floor 2",
  "loc3": "Office",
  "street_name": "Main St",
  "street_number": "123",
  "zip_code": "12345",
  "city": "Boston"
}
```

Update existing location:
```
PUT /property/location/42
Content-Type: application/json

{
  "loc_code": "LOC-001-UPD",
  "loc1": "Building A",
  "loc2": "Floor 3",
  "street_name": "Main St",
  "street_number": "123"
}
```

---

## Response Examples

### Success Response (200 OK)

```json
{
  "status": "success",
  "message": "Location created successfully",
  "location_id": 42,
  "errors": {}
}
```

### Validation Error Response (400 Bad Request)

```json
{
  "status": "error",
  "message": "Validation failed",
  "location_id": null,
  "errors": {
    "loc_code": "Location code is required",
    "loc1": "Location level 1 is required",
    "street_number": "Street number must be numeric"
  },
  "values": {
    "loc_code": "",
    "loc1": "",
    "street_name": "Main St",
    "street_number": "abc"
  },
  "location_data": null
}
```

Error responses include `values` and `location_data` for client-side form re-population.

---

## Validation Rules

### Required Fields
- `loc_code` - Must not be empty
- `loc1` - Must not be empty (location hierarchy level 1)

### Format Validation
- `loc_code` - Alphanumeric, underscore, hyphen only (`^[A-Za-z0-9_-]+$`)
- `street_number` - Numeric only (if provided)
- `zip_code` - Numeric, spaces, hyphens only (`^[0-9\s\-]+$`)

### Type Safety
- Numeric fields cast to `(int)`
- String fields passed through `Sanitizer::sanitize()`
- Array bounds protection via fieldMap metadata

---

## Database Access Pattern

All write operations now use **parameterized queries**:

```php
// INSERT example
$sql = 'INSERT INTO phpgw_property_location (loc_code, loc1, ...) VALUES (:loc_code, :loc1, ...)';
$db->prepare($sql);
$db->bind_param(':loc_code', $locationCode);
$db->execute();

// UPDATE example
$sql = 'UPDATE phpgw_property_location SET loc_code = :loc_code, loc1 = :loc1 WHERE loc_id = :loc_id';
$db->prepare($sql);
$db->bind_param(':loc_code', $locationCode);
$db->bind_param(':loc_id', $locationId);
$db->execute();
```

**Security Features:**
- ✅ Named parameter placeholders (`:param_name`)
- ✅ Type-safe binding (int/string detection)
- ✅ No string interpolation in WHERE/SET clauses
- ✅ Transaction safety (rollback on errors)
- ✅ Input sanitization via Sanitizer class

---

## Backward Compatibility

✅ **No breaking changes:**
- All existing thin adapter routes unchanged (index, summary, etc.)
- Legacy UI methods still callable via hydrateRequestGlobals pattern
- New hybrid routes are *additions*, not replacements
- Existing API consumers unaffected

---

## Migration Path

**Phase 1 (Current):** Hybrid routes for location add/edit
- ✅ New `POST /property/location/add` and `PUT /property/location/{id}` routes
- ✅ LocationFormHelper handles validation/persistence
- ✅ Clients can migrate to hybrid routes at their own pace

**Phase 2 (Future):** Migrate read operations to form helper
- Query optimization in LocationFormHelper
- Add filtering/sorting to form helper
- Gradually replace legacy UI read methods

**Phase 3 (Future):** Full modernization
- Remove hybrid layer once all clients migrated
- Clean deprecation of legacy thin adapter routes

---

## Testing Recommendations

### Unit Tests (FormHelper)
```php
// Test mapInput normalization
$state = $helper->mapInput(['loc_code' => 'TEST', 'loc1' => 'Building A']);
assert($state['values']['loc_code'] === 'TEST');

// Test validation
$state = $helper->validate(['values' => ['loc_code' => '']]);
assert(!empty($state['errors']['loc_code']));

// Test persistSave success
$state = $helper->persistSave([...validated state...]);
assert($state['receipt']['status'] === 'success');
```

### Integration Tests (Controller + FormHelper)
```php
// Test add location
$response = $controller->add($request, $response);
assert($response->getStatusCode() === 200);
assert(json_decode($response->getBody())['location_id'] > 0);

// Test save with validation error
$response = $controller->save($request, $response, ['location_id' => 42]);
assert($response->getStatusCode() === 400);
assert(!empty(json_decode($response->getBody())['errors']));
```

### E2E Tests (REST API)
```bash
# Create new location
curl -X POST http://localhost/property/location/add \
  -H "Content-Type: application/json" \
  -d '{"loc_code":"LOC-001","loc1":"Building A"}'

# Update location
curl -X PUT http://localhost/property/location/42 \
  -H "Content-Type: application/json" \
  -d '{"loc_code":"LOC-001-UPD","loc1":"Building B"}'
```

---

## Files Modified

| File | Change | Lines |
|------|--------|-------|
| `src/modules/property/helpers/LocationFormHelper.php` | **Created** | 525 |
| `src/modules/property/controllers/LocationController.php` | Enhanced with FormHelper injection + add/save methods | +45 |
| `src/modules/property/routes/Routes.php` | Added 2 new hybrid routes | +2 |

**Total Lines Added:** ~572

---

## Comparison: Thin Adapter vs Hybrid

| Aspect | Thin Adapter | Hybrid |
|--------|--------------|--------|
| **State Management** | Global superglobals | Explicit state objects |
| **Validation** | Distributed in legacy code | Consolidated in FormHelper |
| **Error Recovery** | Implicit (legacy redirect) | Explicit (return values + errors) |
| **Testability** | Mock legacy UI | Test each step independently |
| **Code Clarity** | Legacy code obfuscated | Clear data flow |
| **Transaction Safety** | Implicit in legacy code | Explicit begin/commit |
| **Complexity** | Low | Medium |
| **Security** | Type casting + escaping | Parameterized + sanitization |
| **Best For** | Reads, proven legacy code | Writes, long-term modernization |

**Location Now Uses:** 
- Thin Adapter for reads (proven, low risk)
- Hybrid for writes (explicit, testable, secure)

---

## Lessons Learned

1. **Hybrid > Pure Patterns** - Combining both approaches gives flexibility:
   - Reads can use legacy code while it's stable
   - Writes can use modern patterns immediately
   - No "big bang" refactoring needed

2. **State Objects Aid Testability** - Explicit state through method chain makes debugging easier:
   - Each step (mapInput → validate → persist) is independent
   - Can unit test each step without mocking HTTP/DB
   - Errors propagate through state, not exceptions

3. **Parameterization is Non-Negotiable** - Every write operation now uses prepared statements:
   - FormHelper enforces this pattern
   - No string interpolation in SQL
   - Database layer is secure by default

4. **Gradual Migration** - Hybrid approach allows coexistence:
   - New clients use hybrid routes
   - Old clients keep using thin adapter
   - No forced cutover date

---

## Next Steps

**Optional Enhancements:**
1. Add file upload/delete handling to LocationFormHelper (currently in legacy UI)
2. Add history/audit logging to persistSave()
3. Add custom attribute handling (phpgw_cust_attribute)
4. Migrate read operations to form helper (optimize for REST)
5. Add batch operations (bulk create/update)

**Other Modules:**
- Request: Use this hybrid approach (simpler than Entity)
- Project: Assess complexity, choose thin adapter or hybrid
- Ticket/Helpdesk: Lower priority, use decision matrix

---

## Summary

Location module successfully upgraded to **Hybrid Approach**:
- ✅ Explicit form helper for orchestration (525 lines)
- ✅ Parameterized database access (security hardened)
- ✅ Consolidated validation and error handling
- ✅ Clear REST routes for add/save operations
- ✅ Full backward compatibility with existing thin adapter routes
- ✅ Ready for testing and gradual client migration
- ✅ Syntax validated on all modified files

**Code Quality:**
- ✅ No syntax errors
- ✅ PSR-12 code style
- ✅ Type hinting throughout
- ✅ Comprehensive docblocks
- ✅ Clear separation of concerns
