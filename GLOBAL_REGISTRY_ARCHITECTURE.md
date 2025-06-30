# Global Registry Architecture Refactoring

## Overview

This document describes the refactoring of the booking module's `BaseModel` and `GenericRegistry` classes to create a truly global registry system that can be used across all modules in the project.

## Architecture Changes

### 1. Global Base Classes (src/models/)

#### BaseModel (App\models\BaseModel)
- **Location**: `/var/www/html/src/models/BaseModel.php`
- **Namespace**: `App\models\BaseModel`
- **Purpose**: Global base model class for CRUD operations, field validation, and relationships
- **Features**: 
  - Field validation and sanitization
  - Relationship mapping
  - Serialization traits
  - Database abstraction

#### GenericRegistry (App\models\GenericRegistry) - ABSTRACT
- **Location**: `/var/www/html/src/models/GenericRegistry.php`
- **Namespace**: `App\models\GenericRegistry`
- **Purpose**: Abstract base class for configuration-driven registry systems
- **Key Features**:
  - Abstract `loadRegistryDefinitions()` method - must be implemented by child classes
  - Dynamic field mapping based on registry configuration
  - Type-safe registry operations
  - Built-in ACL support
  - Configuration-driven table and field management

### 2. Module-Specific Implementation

#### BookingGenericRegistry (App\modules\booking\models\BookingGenericRegistry)
- **Location**: `/var/www/html/src/modules/booking/models/BookingGenericRegistry.php`
- **Namespace**: `App\modules\booking\models\BookingGenericRegistry`
- **Purpose**: Booking-specific implementation of GenericRegistry
- **Registry Types Supported**:
  - `office` - Office management
  - `office_user` - Office user assignments
  - `article_category` - Article categories
  - `article_service` - Service definitions
  - `vendor` - Vendor management
  - `document_vendor` - Document vendors
  - `permission_root` - Permission subjects
  - `permission_role` - Permission roles
  - `e_lock_system` - Electronic lock systems
  - `multi_domain` - Multi-domain configurations

## Usage Pattern

### For Booking Module
```php
use App\modules\booking\models\BookingGenericRegistry;

// Create registry instance for a specific type
$registry = BookingGenericRegistry::forType('office');

// Find records
$offices = BookingGenericRegistry::findWhereByType('office', ['active' => 1]);

// Create new record
$newOffice = BookingGenericRegistry::createForType('office', [
    'name' => 'Main Office',
    'description' => 'Primary office location'
]);
$newOffice->save();
```

### For Other Modules
Other modules can now create their own registry classes by extending `App\models\GenericRegistry`:

```php
namespace App\modules\yourmodule\models;

use App\models\GenericRegistry;

class YourModuleGenericRegistry extends GenericRegistry
{
    protected static function loadRegistryDefinitions(): void
    {
        static::$registryDefinitions = [
            'your_registry_type' => [
                'table' => 'your_table',
                'id' => ['name' => 'id', 'type' => 'auto'],
                'fields' => [
                    // Your field definitions
                ],
                'name' => 'Your Registry Type',
                'acl_app' => 'yourmodule',
                'acl_location' => '.admin',
            ]
        ];
    }
}
```

## Route Configuration

The generic registry routes are configured in `/var/www/html/src/routes/generic_registry.php` and include:

- **GET** `/api/registry/types` - Get available registry types
- **GET** `/api/registry/{type}` - List items for a registry type
- **POST** `/api/registry/{type}` - Create new item
- **GET** `/api/registry/{type}/{id}` - Get single item
- **PUT** `/api/registry/{type}/{id}` - Update item
- **DELETE** `/api/registry/{type}/{id}` - Delete item
- **GET** `/api/registry/{type}/schema` - Get field schema
- **GET** `/api/registry/{type}/list` - Get simple list for dropdowns

All routes are protected by:
- `SessionsMiddleware` - Session management
- `AccessVerifier` - ACL-based access control (can be enabled by uncommenting)

## Controller Updates

The `GenericRegistryController` has been updated to use `BookingGenericRegistry` instead of the old `GenericRegistry`. This maintains backward compatibility while using the new architecture.

## Migration Benefits

1. **Reusability**: BaseModel and GenericRegistry can now be used by any module
2. **Consistency**: All modules will use the same pattern for simple registries
3. **Maintainability**: Core registry logic is centralized
4. **Extensibility**: Easy to add new registry types per module
5. **Type Safety**: Abstract class ensures proper implementation
6. **ACL Integration**: Built-in support for access control

## File Structure Summary

```
src/
├── models/                           # Global models
│   ├── BaseModel.php                # Global base model (App\models\BaseModel)
│   └── GenericRegistry.php          # Abstract registry base (App\models\GenericRegistry)
├── modules/
│   └── booking/
│       ├── models/
│       │   ├── BookingGenericRegistry.php  # Booking registries
│       │   └── GenericRegistry.php.old     # Old file (backed up)
│       └── controllers/
│           └── GenericRegistryController.php  # Updated to use BookingGenericRegistry
└── routes/
    └── generic_registry.php         # Global registry routes
```

## Next Steps

1. **Test the new architecture** end-to-end
2. **Create registry classes for other modules** as needed
3. **Update documentation** for developers
4. **Consider migrating other common patterns** to global locations
5. **Enable AccessVerifier middleware** if needed for additional security

## Breaking Changes

- The old `App\modules\booking\models\GenericRegistry` is no longer available
- Code using the old path must be updated to use `App\modules\booking\models\BookingGenericRegistry`
- The new `App\models\GenericRegistry` is abstract and cannot be instantiated directly

## Backward Compatibility

- All existing API endpoints continue to work
- All existing registry types are preserved
- Controller behavior remains the same
- Database operations are unchanged
