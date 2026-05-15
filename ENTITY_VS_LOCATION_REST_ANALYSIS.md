# Entity vs Location REST Client Migration Approaches
## Comparative Architecture Analysis

**Date:** May 15, 2026  
**Scope:** REST controller design patterns, state management, security, and complexity trade-offs

---

## Executive Summary

Entity and Location modules use **fundamentally different architectural approaches** despite both being REST client migrations:

| Aspect | Location | Entity |
|--------|----------|--------|
| **Pattern** | Strangler Fig (minimal wrapper) | Strangler Fig + Complex Orchestration |
| **Abstraction Layers** | 1 (Controller → Legacy UI) | 4+ (Controller → FormHelper → Legacy BO → DB) |
| **State Management** | Implicit (session-based) | Explicit (FormHelper carries state across steps) |
| **Validation Strategy** | Inline in legacy code | Consolidated in FormHelper |
| **Payload Transformation** | None (direct passthrough) | Extensive (mapping, normalization, rehydration) |
| **File Handling** | Legacy UI handles inline | Dedicated FormHelper method |
| **Error Recovery** | Redirect back to edit form | Error context + rehydration helpers |
| **Type Safety** | Good (integer type casting) | Excellent (Sanitizer + Zod-like schema) |
| **Testability** | Easy (direct method calls) | Moderate (multi-step workflows) |
| **Maintenance Risk** | Low (thin wrapper) | Medium (orchestration logic) |

**Verdict:** Location = "Thin Adapter"; Entity = "Orchestrator Pattern"

---

## 1. Architectural Patterns

### 1.1 Location Approach: The "Thin Adapter"

**Philosophy:** Keep REST controller minimal; delegate directly to proven legacy UI classes.

```
Request → LocationController 
  → hydrateRequestGlobals() 
  → this->ui()->legacy_method() 
  → Response
```

**Key Characteristics:**
- **Single responsibility:** Controller bridges HTTP ↔ legacy method signature
- **Minimal state handling:** Request globals (`$_GET`, `$_POST`) carry state
- **Direct legacy invocation:** No intermediate transformation layer
- **Transaction handling:** Inherited from legacy code
- **Consistency:** All business logic remains in proven legacy classes

**Examples from LocationController:**
```php
public function delete(Request $request, Response $response, array $args): Response
{
    $this->hydrateRequestGlobals($request, array('location_code' => $args['location_code']));
    return $this->jsonResponse($response, $this->ui()->delete());
}

public function responsibilityRoleSave(Request $request, Response $response): Response
{
    $this->hydrateRequestGlobals($request);
    return $this->jsonResponse($response, $this->ui()->responsiblility_role_save());
}
```

**Strengths:**
- Proven code paths unchanged
- Rapid migration (minimal new code)
- Easy debugging (direct traceability)
- Low regression risk
- High confidence in behavior parity

**Weaknesses:**
- Tight coupling to legacy UI class structure
- Hard to extend with new REST-specific features
- Difficult to refactor UI layer later
- Global state dependency (`$_GET`/`$_POST`)
- Legacy code smell remains visible

---

### 1.2 Entity Approach: The "Orchestrator Pattern"

**Philosophy:** Separate concerns explicitly; build new form workflows that coordinate validation, persistence, and state recovery.

```
Request → EntityController 
  → resolveAclContext() 
  → FormHelper.mapInput() 
  → FormHelper.validate() 
  → FormHelper.persistSave() 
  → FormHelper.handleFiles() 
  → buildSaveResponse() 
  → Response
```

**Key Characteristics:**
- **Multi-layer responsibility chain:** Each layer has specific concerns
- **Explicit state objects:** FormHelper tracks validation, values, rehydration context
- **Payload transformation:** Input normalization, location enrichment, checklist integration
- **Transaction management:** Explicit `transaction_begin/commit` in FormHelper
- **Error recovery:** Built-in rehydration to restore form state after validation fails

**Examples from EntityFormHelper:**

```php
public function mapInput(string $typeApp, string $type, string $aclLocation, object $bocommon): array
{
    // Normalize request state
    $values = (array) Sanitizer::get_var('values');
    $values_attribute = Sanitizer::get_var('values_attribute');
    $bypass = (bool) Sanitizer::get_var('bypass', 'bool');
    
    // Enrich with location context
    if (!$bypass) {
        $insert_record = Cache::session_get('property', 'insert_record');
        $values = $bocommon->collect_locationdata($values, $insert_record);
    }
    
    return ['values' => $values, 'values_attribute' => $values_attribute, 'bypass' => $bypass];
}

public function validate(array $values, $valuesAttribute, int $catId, int $entityId, 
                         object $soadminEntity, object $bo): array
{
    $errors = [];
    
    // Structural validation
    if (!$catId) {
        $errors[] = ['msg' => lang('Please select entity type !')];
        return ['values' => $values, 'values_attribute' => $valuesAttribute, 'errors' => $errors];
    }
    
    // Category-specific validation
    $category = $soadminEntity->read_single_category($entityId, $catId);
    if (!empty($category['org_unit'])) {
        // Org unit handling...
    }
    
    // Type-specific validation
    if (isset($valuesAttribute) && is_array($valuesAttribute)) {
        foreach ($valuesAttribute as $attribute) {
            if (($attribute['nullable'] ?? null) != 1 && empty($attribute['value'])) {
                $errors[] = ['msg' => lang('Please enter value for attribute %1', $attribute['input_text'])];
            }
        }
    }
    
    return ['values' => $values, 'values_attribute' => $valuesAttribute, 'errors' => $errors];
}

public function persistSave(array $values, $attributes, string $action, int $entityId, 
                            int $catId, object $bo, $valuesChecklistStage = null): array
{
    // Explicit transaction management
    Db::getInstance()->transaction_begin();
    
    $receipt = $bo->save($values, $attributes, $action, $entityId, $catId);
    $values['id'] = $receipt['id'];
    
    // Nested save of checklists within same transaction
    if ($valuesChecklistStage) {
        $bo->save_checklist($receipt['id'], $valuesChecklistStage, $receipt);
    }
    
    Db::getInstance()->transaction_commit();
    return ['receipt' => $receipt, 'values' => $values];
}
```

**Strengths:**
- **Separation of concerns:** Each helper method has clear responsibility
- **Stateful workflows:** Can recover from validation errors with full context
- **Extensibility:** Easy to add pre/post hooks, custom validation, cross-field logic
- **Testing:** Each step can be tested independently with mocked dependencies
- **Type safety:** Explicit parameter types and return structures
- **Transaction safety:** Clear transaction boundaries with explicit commit/rollback
- **Reusability:** FormHelper can be shared between legacy UI and REST controllers

**Weaknesses:**
- **Higher complexity:** More code to understand and maintain
- **Multiple transformation layers:** Harder to trace data flow
- **Abstraction overhead:** Each layer adds function calls and state copying
- **Documentation burden:** Complex workflows require clear documentation
- **Testing investment:** Need comprehensive test coverage for all paths
- **Performance:** Extra layer of mapping/validation may have cost

---

## 2. Route Structure & ACL Design

### 2.1 Location Routes: Flat and Functional

```php
$app->group('/property/location', function (RouteCollectorProxy $group) use ($container) {
    $controller = new LocationController($container);

    // Read surfaces (data)
    $group->get('', [$controller, 'index']);
    $group->post('', [$controller, 'index']);
    $group->get('/summary', [$controller, 'summary']);
    
    // Write surfaces (side-effects)
    $group->post('/responsibility-role/save', [$controller, 'responsibilityRoleSave']);
    $group->map(['GET', 'POST'], '/delete', [$controller, 'deleteByLocationCode']);
    $group->delete('/{location_code:[^/]+}', [$controller, 'delete']);
})
->addMiddleware(new AccessVerifier($container));
```

**Characteristics:**
- Flat namespace under `/property/location`
- Mixed HTTP methods (GET, POST, PUT, DELETE) for convenience
- `location_code` as primary identifier
- Direct inheritance of legacy ACL checks

**Scalability:** Good for small surfaces; becomes cluttered with 15+ endpoints

---

### 2.2 Entity Routes: Hierarchical and Context-Aware

```php
$app->group('/property/entity', function (RouteCollectorProxy $group) use ($container) {
    $controller = new EntityController($container);

    $group->group('/{type}/{entity_id:[0-9]+}/{cat_id:[0-9]+}', function (RouteCollectorProxy $g) use ($controller) {
        // CRUD
        $g->get('',                [$controller, 'index']);
        $g->post('',               [$controller, 'index']);
        $g->post('/create',        [$controller, 'store']);
        $g->get('/{id:[0-9]+}',    [$controller, 'show']);
        $g->put('/{id:[0-9]+}',    [$controller, 'update']);
        $g->delete('/{id:[0-9]+}', [$controller, 'destroy']);
        
        // Item sub-resources
        $g->post('/{id:[0-9]+}/files',     [$controller, 'getFiles']);
        $g->post('/{id:[0-9]+}/related',   [$controller, 'getRelated']);
        
        // Category-level queries
        $g->get('/items-per-qr',        [$controller, 'getItemsPerQr']);
        $g->get('/cases',               [$controller, 'getCases']);
    });
})
->addMiddleware(new AccessVerifier($container));
```

**Characteristics:**
- Hierarchical: `/entity/{type}/{entity_id}/{cat_id}/{action}`
- Context is part of the route (type, entity_id, cat_id)
- Supports resource sub-resources (`/{id}/files`, `/{id}/related`)
- RESTful conventions (CRUD operations at category level)
- ACL context resolved from route parameters

**Scalability:** Excellent for complex EAV systems; provides explicit data context

---

## 3. State Management & Data Flow

### 3.1 Location: Global State (Request Globals)

**State Carrier:** `$_GET`, `$_POST`, `$_REQUEST`

```php
private function hydrateRequestGlobals(Request $request, array $extra = array(), bool $json = true): void
{
    $queryParams  = $request->getQueryParams();
    $bodyParams   = $request->getParsedBody();
    $bodyParams   = is_array($bodyParams) ? $bodyParams : array();
    $commonExtra  = $json ? array('phpgw_return_as' => 'json') : array();
    $extra        = array_merge($commonExtra, $extra);

    $_GET = array_merge($_GET, $queryParams, $extra);
    $_POST = array_merge($_POST, $bodyParams, $extra);
    $_REQUEST = array_merge($_REQUEST, $queryParams, $bodyParams, $extra);
}
```

**Flow:**
1. Controller receives PSR-7 Request object
2. Extract query params & parsed body
3. Merge into global `$_GET`, `$_POST` superglobals
4. Call legacy UI method (which reads from globals)
5. Legacy code is unaware of REST origin

**Advantages:**
- No parameter passing needed
- Legacy code unchanged
- Works with existing validation/filters

**Disadvantages:**
- Global state is hard to trace
- Difficult to test in isolation
- Can have cross-request pollution in tests
- Implicit dependencies on global state

---

### 3.2 Entity: Explicit State Objects (FormHelper)

**State Carrier:** Structured return arrays from FormHelper methods

```php
// Input mapping
['values' => $values, 'values_attribute' => $valuesAttribute, 'bypass' => $bypass]

// Validation result
['values' => $values, 'values_attribute' => $valuesAttribute, 'errors' => $errors]

// Persistence result  
['receipt' => $receipt, 'values' => $values]

// Save response decision
['type' => $type, 'payload' => $payload, 'values' => $values]
```

**Flow:**
1. Controller receives PSR-7 Request + route args
2. Controller calls `normalizedSavePayload()` to extract and sanitize
3. Controller calls `FormHelper.mapInput()` → returns structured state
4. Controller calls `FormHelper.validate()` → returns state + errors
5. If errors, controller calls `FormHelper.rehydrate()` → restore form state
6. If valid, controller calls `FormHelper.persistSave()` → transaction + response
7. Controller calls `buildSaveResponse()` → decide outcome (JSON/redirect/edit)

**Advantages:**
- Explicit data flow (easy to trace)
- Immutable step results (no side effects)
- State can be logged/debugged at each step
- Easy to unit test (pass state objects)
- Clear validation → persistence separation
- Recovery paths are explicit

**Disadvantages:**
- More boilerplate (multiple method calls)
- More state object allocations
- Requires understanding of state flow
- Harder to modify legacy BO behavior

---

## 4. Validation Strategy

### 4.1 Location: Distributed Validation

**Where:** Spread across legacy UI and BO classes

```php
// In class.uilocation.inc.php save() method:
if ($location_code) {
    $action = 'edit';
}

$values = $result['values'];

if (!$values['name']) {
    $this->receipt['error'][] = array('msg' => lang('location name not entered!'));
}

if ($this->receipt['error']) {
    $this->edit($values);
} else {
    // Persist...
}
```

**Characteristics:**
- Ad-hoc validation scattered in multiple methods
- `$this->receipt` accumulates errors
- Validation mixed with business logic
- Hard to compose validation rules
- Hard to reuse validation in different contexts

---

### 4.2 Entity: Consolidated Validation in FormHelper

**Where:** Single `FormHelper.validate()` method

```php
public function validate(array $values, $valuesAttribute, int $catId, int $entityId,
                        object $soadminEntity, object $bo): array
{
    $errors = [];

    // Structural checks
    if (!$catId) {
        $errors[] = ['msg' => lang('Please select entity type !')];
        return ['values' => $values, 'values_attribute' => $valuesAttribute, 'errors' => $errors];
    }

    // Category-specific rules
    $category = $soadminEntity->read_single_category($entityId, $catId);
    
    if (!empty($category['org_unit'])) {
        $orgUnitId = $values['extra']['org_unit_id'] ?? Sanitizer::get_var('org_unit_id', 'int');
        $values['extra']['org_unit_id'] = $orgUnitId;
        $values['org_unit_name'] = $orgUnitName;
    }

    // Repost check
    if (phpgw::is_repost()) {
        $errors[] = ['msg' => lang('Hmm... looks like a repost!')];
    }

    // Location requirement
    if (empty($values['location']) && empty($values['p']) && !empty($category['location_level'])) {
        $errors[] = ['msg' => lang('Please select a location !')];
    }

    // Attribute-level validation
    if (isset($valuesAttribute) && is_array($valuesAttribute)) {
        // ... iterate and validate each attribute ...
    }

    return ['values' => $values, 'values_attribute' => $valuesAttribute, 'errors' => $errors];
}
```

**Characteristics:**
- Single method responsible for all validation
- Clear separation of rule types (structural, category-specific, attribute-level)
- Consistent error message format
- Easy to add new rules
- Testable in isolation
- Can be reused by multiple clients (legacy UI + REST)

---

## 5. File Handling

### 5.1 Location: Implicit in Legacy Code

Files are handled by legacy UI methods; REST controller just delegates.

```php
// LocationController doesn't have file-specific logic
// It relies on legacy uilocation methods to handle file operations
// Files are part of the normal save flow
```

**Trade-off:** Works but obscures file handling logic in REST context

---

### 5.2 Entity: Explicit Dedicated Method

```php
public function handleFiles(array $values, string $categoryDir, string $typeApp, array &$errors): void
{
    $id = (int) $values['id'];
    if (empty($id)) {
        throw new \Exception('uientity::_handle_files() - missing id');
    }

    $loc1 = isset($values['location']['loc1']) && $values['location']['loc1'] ? $values['location']['loc1'] : 'dummy';
    if ($typeApp == 'catch') {
        $loc1 = 'dummy';
    }

    $bofiles = CreateObject('property.bofiles');
    
    // Handle deletions
    if (isset($values['file_action']) && is_array($values['file_action'])) {
        $bofiles->delete_file("/{$categoryDir}/{$loc1}/{$id}/", $values);
    }

    // Handle uploads
    if (isset($_FILES['file']['name']) && $_FILES['file']['name']) {
        $fileName = str_replace(' ', '_', $_FILES['file']['name']);
        $toFile = "{$bofiles->fakebase}/{$categoryDir}/{$loc1}/{$id}/{$fileName}";

        if ($bofiles->vfs->file_exists([
            'string' => $toFile,
            'relatives' => [RELATIVE_NONE],
        ])) {
            $errors[] = ['msg' => lang('This file already exists !')];
        } else {
            // Upload logic...
        }
    }
}
```

**Advantages:**
- Clear separation from form logic
- Can be skipped or customized independently
- Error handling is explicit
- Easy to add pre/post upload hooks

---

## 6. Security & Input Validation

### 6.1 Location: Type Casting + Safe Escaping

**Strategy:** Defensive type casting at entry point + legacy db_addslashes escaping

```php
// From class.solocation.inc.php read() hardening
$start = isset($data['start']) && $data['start'] ? (int)$data['start'] : 0;
$filter = isset($data['filter']) ? (int)$data['filter'] : 0;
$type_id = isset($data['type_id']) ? (int)$data['type_id'] : 0;

// For string inputs: escape before interpolation
$location_code = db_addslashes($data['location_code'] ?? '');

// For array inputs: normalize and validate
$filter_item = isset($data['filter_item']) ? array_filter(
    array_map(function($f) { return (int)$f; }, (array)$data['filter_item'])
) : [];

// For dynamic SQL names: whitelist validation
if (!in_array($column, $allowed_columns, true)) {
    throw new \Exception('Invalid column');
}
```

**Strengths:**
- Simple to understand
- Works with existing query builder patterns
- Minimal performance overhead

**Weaknesses:**
- Type casting alone insufficient for complex types (arrays, objects)
- db_addslashes is legacy approach (parameterization preferred)
- Scattered validation across function entry points
- Hard to audit all entry points

---

### 6.2 Entity: Structured Sanitization + Type Enforcement

**Strategy:** Dedicated sanitizer for all inputs + structured payload validation

```php
public function normalizedSavePayload(Request $request): array
{
    // Extract from request
    $payload = $this->requestBodyArray($request);
    
    // Sanitize all scalars recursively
    foreach ($payload as $key => $value) {
        $payload[$key] = $this->sanitizePayloadValue($value);
    }
    
    return $payload;
}

private function sanitizePayloadValue(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map([$this, __FUNCTION__], $value);
    }

    if (is_string($value)) {
        return Sanitizer::clean_value($value, 'string');
    }

    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
        return $value;
    }

    return Sanitizer::clean_value((string)$value, 'string');
}
```

**Strengths:**
- Recursive sanitization of nested structures
- Consistent treatment of all scalar inputs
- Uses centralized `Sanitizer` class
- Can enforce type schemas

**Weaknesses:**
- Overhead of recursive traversal
- Sanitization happens before validation (can lose error context)

---

## 7. Error Recovery & Rehydration

### 7.1 Location: Implicit Redirect

On validation error, redirect back to edit form:

```php
public function save()
{
    // ... validation ...
    
    if ($this->receipt['error']) {
        $this->edit($values);  // Implicit: re-render form with values
        return;
    }
    
    // Persist...
}
```

**How it works:**
- Legacy UI's `edit()` method receives partially-filled `$values`
- XSL template re-renders form with previous values
- User sees form with errors and can correct

**Limitations:**
- Works for simple forms
- Complex relational data (parent entity context, org units) needs special handling
- Browser history polluted with back/forward navigation

---

### 7.2 Entity: Explicit Rehydration

```php
public function rehydrate(array $values): array
{
    // Restore location context
    if ($values['location']) {
        $bolocation = CreateObject('property.bolocation');
        $location_code = implode("-", $values['location']);
        $values['extra']['view'] = true;
        $values['location_data'] = $bolocation->read_single($location_code, $values['extra']);
    }

    // Restore parent entity references
    if ($values['extra']['p_num']) {
        $values['p'][$values['extra']['p_entity_id']]['p_num'] = $values['extra']['p_num'];
        $values['p'][$values['extra']['p_entity_id']]['p_entity_id'] = $values['extra']['p_entity_id'];
        $values['p'][$values['extra']['p_entity_id']]['p_cat_id'] = $values['extra']['p_cat_id'];
        $values['p'][$values['extra']['p_entity_id']]['p_cat_name'] = Sanitizer::get_var(
            'entity_cat_name_' . $values['extra']['p_entity_id']
        );
    }

    return $values;
}
```

**How it works:**
- After validation fails, explicitly reload related context
- Reconstruct complex data structures (location hierarchy, parent references)
- Return to edit form with full context restored

**Advantages:**
- Related data is fresh and accurate
- All context available for error message rendering
- Testable: can verify rehydration logic independently

---

## 8. Route Parameter Resolution

### 8.1 Location: Parameter Extraction from Path & Query

```php
public function delete(Request $request, Response $response, array $args): Response
{
    // Route param
    $locationCode = (string)($args['location_code'] ?? '');
    
    // Or from query string
    if ($locationCode === '') {
        $locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
    }
    
    // Or from parsed body
    if ($locationCode === '') {
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $locationCode = (string)($parsedBody['location_code'] ?? '');
        }
    }
    
    // Defensive parameter check
    if ($locationCode === '') {
        return $this->jsonResponse($response, ['error' => 'Missing location_code']);
    }
    
    $this->hydrateRequestGlobals($request, array('location_code' => $locationCode));
    return $this->jsonResponse($response, $this->ui()->delete());
}
```

**Characteristics:**
- Multiple fallback sources checked
- Defensive against missing parameters
- Simple string parameters

---

### 8.2 Entity: ACL Context Resolution from Route

```php
private function resolveAclContext(array $args): array
{
    $bo = $this->bo($args);  // Instantiate with route params

    $aclCheckLocation = $bo->acl_location;
    $config = CreateObject('phpgwapi.config', 'property')->read();
    
    if (
        isset($config->config_data['deny_remote_users']) 
        && $config->config_data['deny_remote_users'] 
        && isset($_SERVER['HTTP_UID'])
    ) {
        // Remote user check...
    }

    $app = $bo->type_app[$bo->type] ?? 'property';

    return [
        'bo' => $bo,
        'acl_check_location' => $aclCheckLocation,
        'app' => $app,
    ];
}

protected function assertEntityAcl(Request $request, array $args, int $aclType, 
                                   string $message): \property_boentity
{
    $context = $this->resolveAclContext($args);
    $bo = $context['bo'];

    if (!$bo->acl->check($context['acl_check_location'], $aclType, $context['app'])) {
        throw new HttpForbiddenException($request, $message);
    }

    return $bo;
}
```

**Characteristics:**
- ACL context derived from boentity
- Throws `HttpForbiddenException` on access denied
- Structured context returned for reuse

---

## 9. Testability & Debugging

### 9.1 Location: Direct Test Scenarios

**Test approach:** Mock legacy UI, verify correct method was called with right globals

```php
// Pseudocode
$controller = new LocationController($container);
$mockUi = Mockery::mock(\property_uilocation::class);
$mockUi->shouldReceive('delete')
    ->once()
    ->andReturn(['message' => ['msg' => 'Deleted']]);

$request = ServerRequestFactory::fromGlobals();
$response = new Response();

$result = $controller->delete($request, $response, ['location_code' => '5436-01-01-001']);
// Assert: $mockUi->delete() was called with correct globals
```

**Strengths:**
- Simple test setup
- Clear assertion points

**Weaknesses:**
- Must mock entire UI class
- Tests are coupled to legacy code
- Global state manipulation in tests is fragile

---

### 9.2 Entity: Multi-Layer Test Scenarios

**Test approach:** Test each FormHelper method independently

```php
// Pseudocode
$helper = new EntityFormHelper();

// Test input mapping
$input = $helper->mapInput('property', 'ticket', 'location_id:123', $bocommon);
assert(['values', 'values_attribute', 'bypass'] === array_keys($input));

// Test validation
$result = $helper->validate(
    ['id' => 0, 'cat_id' => 0],  // Missing cat_id
    null,
    0,
    1,
    $soadminEntity,
    $bo
);
assert(count($result['errors']) > 0);  // Should have error
assert(in_array('Please select entity type !', $result['errors'][0]['msg']));

// Test persistence
$result = $helper->persistSave(
    ['id' => 0, 'cat_id' => 15, 'title' => 'Test'],
    ['attr1' => 'value1'],
    'add',
    1,
    15,
    $bo,
    null
);
assert($result['receipt']['id'] > 0);  // Should have new ID
```

**Strengths:**
- Each method testable independently
- Clear input → output contracts
- No global state manipulation
- Easy to set up test fixtures

**Weaknesses:**
- Need to mock multiple objects (`boentity`, `soadminEntity`, `bocommon`)
- Multi-layer flow harder to trace through tests
- More test code to maintain

---

## 10. Complexity Comparison

| Factor | Location | Entity |
|--------|----------|--------|
| Controller methods | 15 | 10 |
| Lines of controller code | ~200 | ~1000 |
| Helper classes | 1 (Controller) | 3 (Controller, FormHelper, EditPagePresenter) |
| State management | Global (`$_GET`, `$_POST`) | Explicit (structured arrays) |
| Validation layers | 1 (legacy code) | 2 (FormHelper + legacy BO) |
| File handling | Implicit | Explicit |
| Error recovery | Redirect | Rehydration |
| ACL enforcement | Delegated to legacy | Explicit in controller |
| Average cognitive complexity | Low | Medium |

---

## 11. Maintenance & Future Evolution

### 11.1 Location: Low Maintenance, Limited Evolution

**Ideal for:**
- Stable modules where legacy code is well-tested
- Simple CRUD surfaces
- Quick migration to REST transport without code changes

**Evolution path:**
- Keep thin adapter indefinitely
- Gradually migrate legacy methods to REST-native implementations
- Can re-wrap refactored methods without breaking contract

**Risk:**
- UI layer remains legacy patterns (globals, side effects)
- Hard to add new REST-specific features without modifying legacy code
- ACL/security hardening requires changes in multiple places

---

### 11.2 Entity: High Maintenance, Excellent Evolution

**Ideal for:**
- Complex modules with intricate workflows
- Features that need validation, transaction safety, error recovery
- Long-term modernization plans

**Evolution path:**
- Keep FormHelper as stable abstraction
- Gradually replace legacy BO methods with new implementations
- Entity can grow new REST-specific features without touching legacy code
- Forms can be extended with new validation, pre/post hooks

**Risk:**
- FormHelper code needs careful maintenance (state flows)
- Documentation crucial for workflow understanding
- Testing investment required for confidence

---

## 12. Hybrid Approach: The Best of Both Worlds?

Could we use Location's thin adapter simplicity + Entity's orchestration safety?

**Proposed pattern:**
```php
public function store(Request $request, Response $response, array $args): Response
{
    // Simple parameter extraction (Location style)
    $payload = $this->normalizePayload($request, $args);
    
    // Dedicated helper for business logic (Entity style)
    $result = $this->formHelper->save($payload, $args);
    
    // Return appropriate response
    if ($result['errors']) {
        return $this->jsonResponse($response, ['errors' => $result['errors']], 400);
    }
    
    return $this->jsonResponse($response, $result['receipt']);
}
```

**Benefits:**
- ✅ Lightweight controller (Location style)
- ✅ Explicit validation/persistence logic (Entity style)
- ✅ Testable form helper
- ✅ No global state
- ✅ Clear error handling

**Drawbacks:**
- ❌ Slight overhead vs pure thin adapter
- ❌ Requires helper implementation
- ❌ New pattern to learn/document

---

## 13. Recommendations

### For the Location Module (Current)
**Status:** ✅ **Correct choice made**

- Thin adapter pattern appropriate for stable read/write surfaces
- Proven legacy code minimizes risk
- SQL hardening provides necessary security layer
- Ready for production

**Improvements:**
- Document `hydrateRequestGlobals()` as temporary pattern
- Plan future refactoring of `class.uilocation.inc.php` to reduce globals
- Add OpenAPI specs to endpoints

### For the Entity Module
**Status:** ✅ **Appropriate complexity level**

- Orchestrator pattern justified by EAV complexity
- FormHelper provides excellent abstraction
- Validation and error recovery patterns are necessary
- Architecture supports future enhancements

**Improvements:**
- Add comprehensive test suite for FormHelper
- Document data flow with sequence diagrams
- Create extension points for custom validation
- Consider schema validation library (Zod-like) for future

### For Future Modules (Request, Project, Ticket)

**Decision matrix:**

| Module Complexity | Proven Legacy Code | Recommended Pattern |
|-------------------|-------------------|---------------------|
| Low/Simple | Yes | Location (thin adapter) |
| Low/Simple | No | Hybrid (adapter + simple helper) |
| Medium/Complex | Yes | Location (thin adapter) |
| Medium/Complex | No | Hybrid or Entity pattern |
| High/Complex | Yes | Entity (orchestrator) |
| High/Complex | No | Entity (orchestrator) + comprehensive tests |

---

## 14. Conclusion

**Both approaches are valid**, but for different contexts:

1. **Location's Thin Adapter** = Fast migration + proven code paths
2. **Entity's Orchestrator** = Complex workflows + future extensibility

**The key difference:** Location leverages existing legacy code quality (mature `class.uilocation.inc.php`), while Entity anticipates the need for robust workflow management across add/edit/validate/persist/files steps.

**Future direction:** Gradually migrate from thin adapters toward hybrid/orchestrator patterns as REST layer matures and legacy code needs refactoring.

