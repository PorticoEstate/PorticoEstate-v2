# Global GenericRegistryController Architecture

## Overview

The `GenericRegistryController` has been moved from module-specific location to a global location at `src/controllers/GenericRegistryController.php`. This enables all modules across the project to use the same registry controller while maintaining their own registry definitions.

## Architecture

### Global Controller Location
- **File**: `src/controllers/GenericRegistryController.php`
- **Namespace**: `App\controllers\GenericRegistryController`
- **Purpose**: Handles CRUD operations for registry types across all modules

### Key Features

1. **Module Agnostic**: Can work with any module's GenericRegistry implementation
2. **Auto-Detection**: Automatically detects the module from the request URL
3. **Explicit Configuration**: Can be explicitly configured with a specific registry class
4. **Backward Compatibility**: Maintains support for legacy route patterns

## Usage Patterns

### 1. Explicit Configuration (Recommended)
```php
// In route files
use App\controllers\GenericRegistryController;
use App\modules\booking\models\BookingGenericRegistry;

$controller = new GenericRegistryController(BookingGenericRegistry::class);
```

### 2. Auto-Detection
```php
// Controller detects module from URL patterns like:
// /api/booking/registry/{type}
// /api/property/registry/{type}
$controller = new GenericRegistryController();
```

### 3. Runtime Configuration
```php
$controller = new GenericRegistryController();
$controller->setRegistryClass(BookingGenericRegistry::class);
```

## Route Patterns

### Module-Specific Routes (Recommended)
```
/api/{module}/registry/types           # Get available types
/api/{module}/registry/{type}          # List items
/api/{module}/registry/{type}/schema   # Get field schema
/api/{module}/registry/{type}/list     # Get dropdown list
/api/{module}/registry/{type}/{id}     # CRUD operations
```

### Legacy Routes (Backward Compatibility)
```
/api/registry/types                    # Falls back to booking module
/api/registry/{type}                   # Falls back to booking module
/api/registry/{type}/{id}              # Falls back to booking module
```

## Route Organization Update

**✅ IMPORTANT**: As of the latest update, registry routes have been moved from the global location to module-specific route files.

### Old Structure (Deprecated)
```
src/routes/generic_registry.php  # ❌ REMOVED
```

### New Structure (Current)
```
src/modules/booking/routes/Routes.php  # ✅ Contains booking registry routes
src/modules/property/routes/Routes.php # ✅ Would contain property registry routes
src/modules/admin/routes/Routes.php    # ✅ Would contain admin registry routes
```

### Benefits of Module-Specific Routes
- **Better Organization**: Routes are organized by module
- **Clearer Responsibility**: Each module manages its own routes
- **Easier Maintenance**: Route changes are contained within modules
- **Scalability**: New modules can independently add registry routes
- **Global Controller**: Still uses the global GenericRegistryController

## Module Implementation

### Step 1: Create Module Registry
Each module creates its own GenericRegistry extension:

```php
<?php
namespace App\modules\property\models;

use App\models\GenericRegistry;

class PropertyGenericRegistry extends GenericRegistry
{
    protected static function loadRegistryDefinitions(): void
    {
        static::$registryDefinitions = [
            'building' => [
                'table' => 'fm_building',
                'name' => 'Buildings',
                'acl_location' => '.property.building',
                'fields' => [
                    'name' => ['type' => 'string', 'required' => true],
                    'address' => ['type' => 'string'],
                    // ...
                ],
            ],
            'room' => [
                'table' => 'fm_room',
                'name' => 'Rooms',
                'acl_location' => '.property.room',
                'fields' => [
                    'name' => ['type' => 'string', 'required' => true],
                    'building_id' => ['type' => 'int', 'required' => true],
                    // ...
                ],
            ],
        ];
    }
}
```

### Step 2: Configure Routes
```php
<?php
// In src/routes/property_registry.php
use App\controllers\GenericRegistryController;
use App\modules\property\models\PropertyGenericRegistry;

return function (App $app) use ($container) {
    $app->group('/api/property/registry', function (RouteCollectorProxy $group) use ($container) {
        $controller = new GenericRegistryController(PropertyGenericRegistry::class);
        
        $group->get('/types', [$controller, 'types']);
        $group->group('/{type}', function (RouteCollectorProxy $typeGroup) use ($controller) {
            $typeGroup->get('/schema', [$controller, 'schema']);
            $typeGroup->get('/list', [$controller, 'getList']);
            $typeGroup->get('', [$controller, 'index']);
            $typeGroup->post('', [$controller, 'store']);
            $typeGroup->get('/{id:[0-9]+}', [$controller, 'show']);
            $typeGroup->put('/{id:[0-9]+}', [$controller, 'update']);
            $typeGroup->delete('/{id:[0-9]+}', [$controller, 'delete']);
        });
    });
};
```

## Benefits

### 1. Code Reuse
- Single controller implementation serves all modules
- No duplication of CRUD logic
- Consistent API patterns across modules

### 2. Scalability
- New modules can easily add registry functionality
- No need to implement custom controllers
- Standardized registry patterns

### 3. Maintainability
- Centralized controller logic
- Module-specific configurations kept separate
- Easy to update and enhance

### 4. Flexibility
- Supports both explicit and auto-detection modes
- Module-specific customizations possible
- Backward compatibility maintained

## File Structure

```
src/
├── controllers/                     # Global controllers
│   └── GenericRegistryController.php  # Registry controller for all modules
├── models/                         # Global models
│   ├── BaseModel.php              # Base model for all entities
│   └── GenericRegistry.php        # Abstract registry base class
├── routes/                        # Route definitions
│   ├── generic_registry.php      # Booking registry routes (legacy + new)
│   ├── property_registry.php     # Property registry routes (example)
│   └── admin_registry.php        # Admin registry routes (example)
└── modules/
    ├── booking/
    │   ├── controllers/           # Module-specific controllers
    │   └── models/
    │       ├── BookingGenericRegistry.php  # Booking registry definitions
    │       └── (other models...)
    ├── property/
    │   ├── controllers/
    │   └── models/
    │       ├── PropertyGenericRegistry.php  # Property registry definitions
    │       └── (other models...)
    └── admin/
        ├── controllers/
        └── models/
            ├── AdminGenericRegistry.php    # Admin registry definitions
            └── (other models...)
```

## Migration from Module-Specific Controllers

### Old Pattern (Deprecated)
```php
// src/modules/booking/controllers/GenericRegistryController.php
use App\modules\booking\models\BookingGenericRegistry;

class GenericRegistryController {
    // Hardcoded to use BookingGenericRegistry
}
```

### New Pattern (Current)
```php
// src/controllers/GenericRegistryController.php
use App\models\GenericRegistry;

class GenericRegistryController {
    // Configurable to use any module's registry
    protected string $registryClass;
}
```

## Testing

The global controller architecture includes comprehensive tests:

1. **Class Loading**: Verify all classes load correctly
2. **Instantiation**: Test both explicit and auto-detection modes
3. **Registry Configuration**: Validate registry class setting
4. **Route Detection**: Test module detection from URL patterns
5. **API Simulation**: Simulate actual API requests

Run tests with:
```bash
docker exec portico_api php /var/www/html/test_global_controller.php
docker exec portico_api php /var/www/html/test_complete_architecture.php
```

## Next Steps

1. **Other Modules**: Implement GenericRegistry extensions for other modules
2. **API Testing**: Test actual HTTP endpoints with the new controller
3. **Documentation**: Update API documentation to reflect new route patterns
4. **Migration**: Help other teams migrate to the new architecture

## Summary

The global GenericRegistryController architecture provides:
- ✅ Centralized, reusable controller logic
- ✅ Module-specific configurations
- ✅ Scalable design for multiple modules
- ✅ Backward compatibility
- ✅ Flexible configuration options
- ✅ Consistent API patterns

This architecture significantly reduces code duplication while maintaining module autonomy and enabling rapid development of new registry-based features across the entire project.
