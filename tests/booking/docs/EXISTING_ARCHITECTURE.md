# Existing Bookingfrontend Slim4 Architecture

**Analysis Date:** 2026-01-20
**Purpose:** Understand existing patterns before implementing freetime endpoint

---

## Directory Structure

```
src/modules/bookingfrontend/
├── controllers/               # Slim4 controllers
│   ├── BuildingController.php
│   ├── ResourceController.php
│   ├── EventController.php
│   ├── ScheduleEntityController.php
│   ├── OrganizationController.php
│   ├── BookingUserController.php
│   ├── DocumentController.php
│   ├── applications/
│   │   ├── ApplicationController.php
│   │   ├── CheckoutController.php
│   │   └── CommentsController.php
│   └── ...
│
├── models/                    # Data models with OpenAPI annotations
│   ├── Resource.php
│   ├── Event.php
│   ├── Allocation.php
│   ├── Booking.php
│   ├── Building.php
│   ├── Application.php
│   ├── Organization.php
│   └── helper/
│
├── repositories/              # Data access layer
│   ├── ResourceRepository.php
│   ├── EventRepository.php
│   ├── ApplicationRepository.php
│   ├── ArticleRepository.php
│   ├── DocumentRepository.php
│   └── OrganizationRepository.php
│
├── services/                  # Business logic layer
│   ├── EventService.php
│   ├── ScheduleEntityService.php
│   ├── OrganizationService.php
│   ├── DocumentService.php
│   ├── CompletedReservationService.php
│   └── applications/
│
├── helpers/                   # Utility classes
│   ├── ResponseHelper.php
│   ├── UserHelper.php
│   ├── LoginHelper.php
│   ├── LogoutHelper.php
│   ├── WebSocketHelper.php
│   ├── ApplicationHelper.php
│   └── LangHelper.php
│
├── routes/
│   └── Routes.php             # All route definitions
│
└── inc/                       # ⚠️ LEGACY - DO NOT USE
    └── class.uibooking.inc.php  # Old phpgw API
```

---

## Architecture Pattern

### Layer Architecture

```
┌─────────────────────────────────────┐
│         HTTP Request                │
└─────────────────────────────────────┘
                 ↓
┌─────────────────────────────────────┐
│         Routes (Slim4)              │
│   /bookingfrontend/resources/{id}   │
└─────────────────────────────────────┘
                 ↓
┌─────────────────────────────────────┐
│         Controller                  │
│   - Extract parameters              │
│   - Validate input                  │
│   - Call service/repository         │
│   - Format response                 │
└─────────────────────────────────────┘
                 ↓
┌─────────────────────────────────────┐
│      Service (Optional)             │
│   - Complex business logic          │
│   - Orchestrates multiple repos     │
│   - Calls legacy SO when needed     │
└─────────────────────────────────────┘
                 ↓
┌─────────────────────────────────────┐
│         Repository                  │
│   - Database queries                │
│   - Create model instances          │
│   - Simple data operations          │
└─────────────────────────────────────┘
                 ↓
┌─────────────────────────────────────┐
│         Model                       │
│   - Data structure                  │
│   - Serialization (SerializableTrait)│
│   - OpenAPI annotations             │
└─────────────────────────────────────┘
                 ↓
┌─────────────────────────────────────┐
│      JSON Response                  │
└─────────────────────────────────────┘
```

---

## Controller Pattern

### Standard Controller Structure

```php
namespace App\modules\bookingfrontend\controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\repositories\XxxRepository;
use App\Database\Db;
use App\modules\phpgwapi\services\Settings;

class XxxController
{
    private $db;
    private $userSettings;
    private $repository;

    public function __construct(ContainerInterface $container)
    {
        $this->db = Db::getInstance();
        $this->userSettings = Settings::getInstance()->get('user');
        $this->repository = new XxxRepository();
    }

    public function index(Request $request, Response $response): Response
    {
        try {
            // Extract query parameters
            $params = $request->getQueryParams();

            // Call repository/service
            $data = $this->repository->getAll($params);

            // Return JSON response
            return ResponseHelper::sendJSONResponse($data);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            $data = $this->repository->getById($id);

            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Not found'],
                    404
                );
            }

            return ResponseHelper::sendJSONResponse($data);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }
}
```

### Key Patterns Observed

1. **Dependency Injection:**
   - Constructor receives `ContainerInterface`
   - Initializes `Db::getInstance()`
   - Initializes `Settings::getInstance()->get('user')`
   - Creates repositories as needed

2. **Response Handling:**
   - Use `ResponseHelper::sendJSONResponse($data)` for success
   - Use `ResponseHelper::sendErrorResponse($error, $code)` for errors
   - Always try/catch for error handling

3. **Parameter Extraction:**
   - Path params: `$args['id']`
   - Query params: `$request->getQueryParams()`
   - Body params: `$request->getParsedBody()`

4. **OpenAPI Documentation:**
   - `@OA\Get`, `@OA\Post`, etc. annotations
   - Full parameter and response documentation
   - Grouped by tags

---

## Repository Pattern

### Standard Repository Structure

```php
namespace App\modules\bookingfrontend\repositories;

use PDO;
use App\Database\Db;
use App\modules\bookingfrontend\models\Xxx;

class XxxRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function createXxx(array $data): Xxx
    {
        return new Xxx($data);
    }

    public function getById(int $id): ?Xxx
    {
        $sql = "SELECT * FROM bb_xxx WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return $data ? $this->createXxx($data) : null;
    }

    public function getByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "SELECT * FROM bb_xxx WHERE id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'createXxx'], $results);
    }
}
```

### Key Patterns

- Initialize `Db::getInstance()` in constructor
- Factory methods like `createXxx(array $data)`
- Return model instances or arrays of models
- Simple, focused data access methods

---

## Service Pattern

### Standard Service Structure

```php
namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\repositories\XxxRepository;

class XxxService
{
    private $db;
    private $repository;
    private $userSettings;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->repository = new XxxRepository();
        $this->userSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
    }

    public function complexOperation($params)
    {
        // Orchestrate multiple repositories
        // Apply business logic
        // Transform data
        return $result;
    }
}
```

### When to Use Services

**Use Service when:**
- Complex business logic spanning multiple entities
- Need to orchestrate multiple repositories
- Legacy business object integration
- Data transformation/aggregation

**Use Repository directly when:**
- Simple CRUD operations
- Direct model retrieval
- Straightforward queries

---

## Model Pattern

### Standard Model Structure

```php
namespace App\modules\bookingfrontend\models;

use App\traits\SerializableTrait;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *      schema="Resource",
 *      type="object",
 *      title="Resource"
 * )
 */
class Resource
{
    use SerializableTrait;

    /**
     * @Expose
     * @Short
     * @OA\Property(description="ID", type="integer")
     */
    public $id;

    /**
     * @Expose
     * @Short
     * @OA\Property(description="Name", type="string")
     */
    public $name;

    // ... more properties
}
```

### Key Patterns

- Use `SerializableTrait` for JSON serialization
- `@Expose` annotation: field included in JSON
- `@Short` annotation: field included in short response
- OpenAPI annotations for documentation
- Public properties (not private with getters/setters)

---

## Routing Pattern

### Routes.php Structure

```php
$app->group('/bookingfrontend', function (RouteCollectorProxy $group) {

    // Buildings routes
    $group->group('/buildings', function (RouteCollectorProxy $group) {
        $group->get('', BuildingController::class . ':index');
        $group->get('/{id}', BuildingController::class . ':show');
        $group->get('/{id}/resources', ResourceController::class . ':getResourcesByBuilding');
        $group->get('/{id}/schedule', ScheduleEntityController::class . ':getBuildingSchedule');
    });

    // Resources routes
    $group->group('/resources', function (RouteCollectorProxy $group) {
        $group->get('', ResourceController::class . ':index');
        $group->get('/{id}', ResourceController::class . ':getResource');
        $group->get('/{id}/schedule', ScheduleEntityController::class . ':getResourceSchedule');
    });

})->add(new SessionsMiddleware($app->getContainer()));
```

### Patterns Observed

1. **Nested groups** for logical organization
2. **Consistent naming:** `/{id}` for resource ID in path
3. **SessionsMiddleware** added to all bookingfrontend routes
4. **Controller::method** syntax for route handlers
5. **RESTful conventions:**
   - `index()` for GET collection
   - `show()` for GET single item
   - Sub-resources use nested paths: `/{id}/schedule`

---

## Existing Freetime-Related Code

### Current Schedule Endpoints

**Building Schedule:**
```
GET /bookingfrontend/buildings/{id}/schedule?date=2026-01-20
```
- Controller: `ScheduleEntityController::getBuildingSchedule()`
- Returns: Events, allocations, bookings for a date
- Service: `ScheduleEntityService`

**Resource Schedule:**
```
GET /bookingfrontend/resources/{id}/schedule?date=2026-01-20
```
- Controller: `ScheduleEntityController::getResourceSchedule()`
- Returns: Events, allocations, bookings for a resource on a date

**Organization Schedule:**
```
GET /bookingfrontend/organizations/{id}/schedule
```
- Controller: `ScheduleEntityController::getOrganizationSchedule()`

### Observations

- **Schedule endpoints exist** but only return scheduled items
- **No freetime endpoint** in Slim4 yet
- **ScheduleEntityService** already handles events/allocations/bookings
- **Pattern to follow:** Similar to schedule but return availability

---

## Integration with Legacy

### How Legacy is Accessed

**Example from ScheduleEntityService:**
```php
// Services can still use CreateObject() for legacy business objects
$soEvent = \CreateObject('booking.soevent');
$events = $soEvent->read($filters);

// Or use direct database queries and wrap in new models
```

### Patterns for Legacy Integration

**Option 1: Direct CreateObject (Current Pattern)**
```php
class FreetimeService
{
    public function getFreetime($buildingId, $resourceId, $start, $end)
    {
        $bobooking = \CreateObject('booking.bobooking');
        $result = $bobooking->get_free_events(...);
        return $result;
    }
}
```

**Option 2: Wrap in Service Layer**
```php
class FreetimeService
{
    public function getFreetime($buildingId, $resourceId, $start, $end)
    {
        $bobooking = \CreateObject('booking.bobooking');
        $rawResult = $bobooking->get_free_events(...);

        // Transform to modern format
        return $this->transformResponse($rawResult);
    }
}
```

**Recommendation:** Option 1 for initial implementation (simpler, proven pattern)

---

## Common Utilities

### Response Helpers

**File:** `src/modules/bookingfrontend/helpers/ResponseHelper.php`

```php
// Success response
ResponseHelper::sendJSONResponse($data, 200);

// Error response
ResponseHelper::sendErrorResponse(['error' => 'message'], 500);
```

### Database Access

```php
use App\Database\Db;

$db = Db::getInstance();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
```

### User Context

```php
use App\modules\phpgwapi\services\Settings;

$userSettings = Settings::getInstance()->get('user');
$timezone = $userSettings['preferences']['common']['timezone'] ?? 'UTC';
```

---

## Existing Related Endpoints

### Resources

| Endpoint | Method | Controller | Purpose |
|----------|--------|------------|---------|
| `/resources` | GET | ResourceController::index | List resources |
| `/resources/{id}` | GET | ResourceController::getResource | Get resource details |
| `/resources/{id}/schedule` | GET | ScheduleEntityController | Get resource schedule |
| `/resources/{id}/documents` | GET | ResourceController | Get resource documents |

**Missing:** `/resources/{id}/freetime` ← **To implement**

### Buildings

| Endpoint | Method | Controller | Purpose |
|----------|--------|------------|---------|
| `/buildings` | GET | BuildingController::index | List buildings |
| `/buildings/{id}` | GET | BuildingController::show | Get building details |
| `/buildings/{id}/resources` | GET | ResourceController | Get building resources |
| `/buildings/{id}/schedule` | GET | ScheduleEntityController | Get building schedule |

**Missing:** `/buildings/{id}/freetime` ← **To implement**

---

## Dependencies & Injection

### What Controllers Typically Inject

```php
public function __construct(ContainerInterface $container)
{
    // Database connection (always)
    $this->db = Db::getInstance();

    // User settings (usually)
    $this->userSettings = Settings::getInstance()->get('user');

    // Repositories (as needed)
    $this->xxxRepository = new XxxRepository();

    // Services (for complex logic)
    $this->xxxService = new XxxService();
}
```

### Available from Container

- Database connection via `Db::getInstance()`
- Settings via `Settings::getInstance()`
- Session data via `Sessions::getInstance()`

---

## Error Handling Pattern

### Standard Try/Catch

```php
public function methodName(Request $request, Response $response, array $args): Response
{
    try {
        // Extract parameters
        $id = (int)$args['id'];
        $params = $request->getQueryParams();

        // Validate
        if (!$id) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Invalid ID'],
                400
            );
        }

        // Business logic
        $result = $this->service->doSomething($id, $params);

        // Success response
        return ResponseHelper::sendJSONResponse($result);

    } catch (Exception $e) {
        return ResponseHelper::sendErrorResponse(
            ['error' => $e->getMessage()],
            500
        );
    }
}
```

### HTTP Status Codes Used

- `200` - Success
- `400` - Bad Request (invalid parameters)
- `404` - Not Found
- `500` - Internal Server Error

---

## OpenAPI Documentation

### Controller Annotations

```php
/**
 * @OA\Get(
 *     path="/bookingfrontend/resources/{id}/freetime",
 *     summary="Get available time slots for a resource",
 *     tags={"Resources"},
 *     @OA\Parameter(
 *         name="id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Parameter(
 *         name="start_date",
 *         in="query",
 *         required=true,
 *         @OA\Schema(type="string", format="date", example="2026-01-20")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Available time slots",
 *         @OA\JsonContent(type="array")
 *     )
 * )
 */
public function getFreetime(Request $request, Response $response, array $args): Response
{
    // ...
}
```

### Model Annotations

```php
/**
 * @OA\Schema(
 *      schema="TimeSlot",
 *      type="object",
 *      title="Time Slot"
 * )
 */
class TimeSlot
{
    /**
     * @OA\Property(description="Start time", type="string")
     */
    public $start;
}
```

---

## Middleware

### SessionsMiddleware

**Applied to all `/bookingfrontend` routes:**
```php
->add(new SessionsMiddleware($app->getContainer()))
```

**Purpose:**
- Initializes user session
- Loads user settings
- Provides authentication context

**Required for:** All endpoints that need user context

---

## Best Practices Observed

### 1. Consistent Structure

✅ Controllers focus on HTTP concerns
✅ Services handle complex business logic
✅ Repositories handle data access
✅ Models are simple data containers

### 2. Error Handling

✅ Always use try/catch
✅ Use ResponseHelper for consistent responses
✅ Appropriate HTTP status codes

### 3. Type Safety

✅ Type hints everywhere: `Request $request`, `Response $response`
✅ Cast IDs: `(int)$args['id']`
✅ Return type declarations: `: Response`

### 4. PSR Standards

✅ PSR-7 Request/Response
✅ PSR-11 Container
✅ PSR-4 Autoloading

---

## Comparison: Legacy vs Slim4

| Aspect | Legacy (inc/) | Slim4 (controllers/) |
|--------|--------------|---------------------|
| **Entry Point** | phpgw StartPoint | Slim4 Routes |
| **Input Handling** | Sanitizer class | Request object |
| **Class Pattern** | uixxx, boxxx, soxxx | Controller, Service, Repository |
| **Response** | Direct echo/print | PSR-7 Response |
| **Database** | $this->db (phpgw) | Db::getInstance() |
| **Settings** | $GLOBALS['phpgw_info'] | Settings::getInstance() |
| **Dependencies** | CreateObject() | Dependency injection |
| **Documentation** | Inline comments | OpenAPI annotations |

---

## Freetime Endpoint Requirements

Based on existing patterns, the new freetime endpoints should:

### Structure

```
src/modules/bookingfrontend/
├── controllers/
│   └── FreetimeController.php     ← NEW
├── services/
│   └── FreetimeService.php        ← NEW (optional, if needed)
├── routes/
│   └── Routes.php                 ← UPDATE (add routes)
└── models/
    └── TimeSlot.php               ← NEW (optional, or use arrays)
```

### Routes to Add

```php
// In Routes.php, add to /bookingfrontend group

// Building freetime
$group->get('/building/{id}/freetime',
    FreetimeController::class . ':buildingFreetime');

// Resource freetime (add to /resources group)
$group->get('/resources/{id}/freetime',
    FreetimeController::class . ':resourceFreetime');
```

### Controller Methods Needed

```php
class FreetimeController
{
    public function resourceFreetime(Request $request, Response $response, array $args): Response
    {
        // GET /bookingfrontend/resources/{id}/freetime
    }

    public function buildingFreetime(Request $request, Response $response, array $args): Response
    {
        // GET /bookingfrontend/building/{id}/freetime
    }
}
```

---

## Recommendations

### For Freetime Implementation

1. **Create FreetimeController** following ResourceController pattern
   - Inject Db, Settings
   - Use ResponseHelper for responses
   - Add OpenAPI annotations

2. **Create FreetimeService (optional)**
   - Wrap legacy `booking_bobooking::get_free_events()`
   - Handle date format conversion
   - Apply fixes (type fields, resource conversion)

   OR

   **Call legacy directly** in controller
   - Simpler initial approach
   - Less code to maintain
   - Easier to validate against legacy

3. **Add routes** to `Routes.php`
   - Add to existing `/resources` group
   - Add new `/building` route

4. **Reuse existing patterns**
   - Same error handling
   - Same response format
   - Same middleware
   - Same DI pattern

### Recommended Approach

**Phase 1:** Minimal controller calling legacy directly
```php
public function resourceFreetime(Request $request, Response $response, array $args): Response
{
    try {
        $resourceId = (int)$args['id'];
        $params = $request->getQueryParams();

        // Convert params
        $startDate = new \DateTime($params['start_date']);
        $endDate = new \DateTime($params['end_date']);

        // Call legacy
        $bobooking = \CreateObject('booking.bobooking');
        $result = $bobooking->get_free_events(null, $resourceId, $startDate, $endDate, [], false, false, true);

        // Extract single resource
        $slots = $result[$resourceId] ?? [];

        return ResponseHelper::sendJSONResponse($slots);

    } catch (\Exception $e) {
        return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
    }
}
```

**Phase 2 (later):** Extract to service for better organization

---

## Next Steps

1. ✅ Understand existing architecture (this document)
2. ⏭️ Create implementation plan
3. ⏭️ Implement FreetimeController
4. ⏭️ Add routes
5. ⏭️ Run tests continuously
6. ⏭️ Validate against legacy

---

**Analysis Complete**
**Ready For:** Implementation planning
