# Global Registry Architecture

## Overview

This document describes the global registry system that provides a unified, configuration-driven approach for managing simple lookup tables and registry entities across all modules in the application. The system includes robust input sanitization, type-safe operations, and consistent API endpoints.

## Architecture Components

### 1. Global Base Classes (src/models/)

#### BaseModel (App\models\BaseModel)
- **Location**: `/var/www/html/src/models/BaseModel.php`
- **Namespace**: `App\models\BaseModel`
- **Purpose**: Global base model class providing CRUD operations, field validation, and relationships
- **Features**: 
  - Field validation and sanitization
  - Relationship mapping
  - Serialization traits
  - Database abstraction
  - Type-safe operations

#### GenericRegistry (App\models\GenericRegistry) - ABSTRACT
- **Location**: `/var/www/html/src/models/GenericRegistry.php`
- **Namespace**: `App\models\GenericRegistry`
- **Purpose**: Abstract base class for configuration-driven registry systems
- **Key Features**:
  - Abstract `loadRegistryDefinitions()` method - must be implemented by child classes
  - Dynamic field mapping based on registry configuration
  - Type-safe registry operations with ID field handling (auto, int, varchar)
  - Built-in ACL support
  - Configuration-driven table and field management
  - Custom fields support with caching
  - Static factory methods for type-specific instances

### 2. Module-Specific Implementations

#### PropertyGenericRegistry (App\modules\property\models\PropertyGenericRegistry)
- **Location**: `/var/www/html/src/modules/property/models/PropertyGenericRegistry.php`
- **Namespace**: `App\modules\property\models\PropertyGenericRegistry`
- **Purpose**: Property-specific implementation of GenericRegistry
- **Registry Types Supported**: 100+ registry types including:
  - `tenant_category` - Tenant categorization
  - `vendor_category` - Vendor categories
  - `location_category` - Location types
  - `project_status` - Project status values
  - `building_part` - Building components
  - `agreement_group` - Agreement groupings
  - `responsibility_role` - Responsibility assignments
  - `invoice_instruction` - Invoice handling instructions
  - And many more property-specific lookup tables

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

## Usage Patterns

### For Property Module

```php
use App\modules\property\models\PropertyGenericRegistry;

// Create registry instance for a specific type
$registry = PropertyGenericRegistry::forType('tenant_category');

// Find records
$categories = PropertyGenericRegistry::findWhereByType('tenant_category', ['active' => 1]);

// Create new record
$newCategory = PropertyGenericRegistry::createForType('tenant_category', [
    'name' => 'Commercial',
    'description' => 'Commercial tenant category'
]);
$newCategory->save();
```

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

Other modules can create their own registry classes by extending `App\models\GenericRegistry`:

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
                'id' => ['name' => 'id', 'type' => 'auto'], // or 'int', 'varchar'
                'fields' => [
                    ['name' => 'name', 'type' => 'varchar', 'nullable' => false],
                    ['name' => 'description', 'type' => 'text', 'nullable' => true],
                    ['name' => 'active', 'type' => 'int', 'nullable' => true],
                ],
                'name' => 'Your Registry Type',
                'acl_app' => 'yourmodule',
                'acl_location' => '.admin',
            ]
        ];
    }
}
```

## API Endpoints

The registry system exposes consistent API endpoints for all modules:

### Property Module Routes (`/property/registry/`)

- **GET** `/property/registry/types` - Get available registry types
- **GET** `/property/registry/{type}` - List items for a registry type  
- **POST** `/property/registry/{type}` - Create new item
- **GET** `/property/registry/{type}/{id}` - Get single item
- **PUT** `/property/registry/{type}/{id}` - Update item  
- **DELETE** `/property/registry/{type}/{id}` - Delete item
- **GET** `/property/registry/{type}/schema` - Get field schema
- **GET** `/property/registry/{type}/list` - Get simple list for dropdowns

### Booking Module Routes (`/booking/registry/`)

- **GET** `/booking/registry/types` - Get available registry types
- **GET** `/booking/registry/{type}` - List items for a registry type
- **POST** `/booking/registry/{type}` - Create new item  
- **GET** `/booking/registry/{type}/{id}` - Get single item
- **PUT** `/booking/registry/{type}/{id}` - Update item
- **DELETE** `/booking/registry/{type}/{id}` - Delete item
- **GET** `/booking/registry/{type}/schema` - Get field schema
- **GET** `/booking/registry/{type}/list` - Get simple list for dropdowns

All routes are protected by:

- `SessionsMiddleware` - Session management  
- `AccessVerifier` - ACL-based access control

## Input Sanitization & Security

The system includes comprehensive input sanitization handled by the `GenericRegistryController`:

### Sanitizer Integration

- **Location**: `/var/www/html/src/helpers/Sanitizer.php`
- **Purpose**: Sanitizes all incoming request data based on field type definitions
- **Features**:
  - Type-aware sanitization (int, float, bool, string, html, etc.)
  - Automatic filtering of undefined fields  
  - XSS protection for HTML content
  - SQL injection prevention

### Field Type Mapping

```php
// Field types are automatically mapped to sanitization methods:
'int' => 'sanitize_int'
'float' => 'sanitize_float'  
'bool' => 'sanitize_bool'
'varchar' => 'sanitize_string'
'text' => 'sanitize_string'
'html' => 'clean_html'
'date' => 'sanitize_string'
'datetime' => 'sanitize_string'
```

### ID Field Handling

The system supports three ID field types:

- **`auto`**: Database auto-generates ID (client cannot provide ID on create)
- **`int`**: Client must provide integer ID on create
- **`varchar`**: Client must provide string ID on create

## Controller Architecture

The `GenericRegistryController` provides a unified API interface:

**Location**: `/var/www/html/src/controllers/GenericRegistryController.php`

**Key Features**:

- Module-agnostic design (works with any GenericRegistry implementation)
- Automatic input sanitization based on field definitions
- Comprehensive error handling and validation
- Consistent JSON API responses
- OpenAPI/Swagger documentation integration
- ACL integration for access control

## Architecture Benefits

1. **Reusability**: BaseModel and GenericRegistry can be used by any module
2. **Consistency**: All modules use the same pattern for registry operations
3. **Maintainability**: Core registry logic is centralized
4. **Extensibility**: Easy to add new registry types per module
5. **Type Safety**: Abstract class ensures proper implementation
6. **Security**: Built-in input sanitization and validation
7. **ACL Integration**: Built-in support for access control
8. **API Consistency**: Uniform endpoints across all modules

## File Structure

```bash
src/
├── controllers/
│   └── GenericRegistryController.php    # Global registry controller
├── helpers/
│   └── Sanitizer.php                    # Input sanitization utilities
├── models/                              # Global models
│   ├── BaseModel.php                    # Global base model
│   └── GenericRegistry.php              # Abstract registry base
└── modules/
    ├── property/
    │   ├── models/
    │   │   └── PropertyGenericRegistry.php  # Property registries
    │   └── routes/
    │       └── Routes.php                    # Property registry routes
    └── booking/
        ├── models/
        │   └── BookingGenericRegistry.php    # Booking registries
        └── routes/
            └── Routes.php                    # Booking registry routes
```

## Implementation Status

### ✅ Completed

- ✅ Global base classes (BaseModel, GenericRegistry)
- ✅ PropertyGenericRegistry with 100+ registry types
- ✅ BookingGenericRegistry with core registry types
- ✅ GenericRegistryController with sanitization
- ✅ Module-specific route configuration
- ✅ Input sanitization and validation
- ✅ ID field handling (auto, int, varchar)
- ✅ Custom fields support with caching
- ✅ ACL integration
- ✅ OpenAPI documentation
- ✅ Error handling and validation

### 🔄 Available for Extension

- 🔄 Additional modules can implement their own registry classes
- 🔄 New registry types can be added to existing modules
- 🔄 Custom field types can be defined
- 🔄 Additional sanitization rules can be implemented

## Usage Examples

### Creating a New Registry Type

1. **Add to module's registry class**:

```php
// In PropertyGenericRegistry.php
'my_new_type' => [
    'table' => 'phpgw_property_my_table',
    'id' => ['name' => 'id', 'type' => 'auto'],
    'fields' => [
        ['name' => 'name', 'type' => 'varchar', 'nullable' => false],
        ['name' => 'description', 'type' => 'text', 'nullable' => true],
        ['name' => 'active', 'type' => 'int', 'nullable' => true],
    ],
    'name' => 'My New Registry Type',
    'acl_app' => 'property',
    'acl_location' => '.admin',
]
```

2. **Create database table** (if needed):

```sql
CREATE TABLE phpgw_property_my_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    active INT DEFAULT 1
);
```

3. **Use via API**:

```bash
# Get schema
GET /property/registry/my_new_type/schema

# List items  
GET /property/registry/my_new_type

# Create item
POST /property/registry/my_new_type
{
    "name": "Test Item",
    "description": "Test description",
    "active": 1
}
```

## Migration Guide

### From Legacy Registry Systems

1. **Identify existing registry tables** in your module
2. **Map field definitions** to the new format
3. **Add registry definitions** to your module's GenericRegistry class
4. **Update route configuration** to use GenericRegistryController
5. **Test API endpoints** for correct behavior
6. **Update frontend code** to use new API endpoints

### Breaking Changes from Legacy

- Old module-specific controller paths are replaced with unified `/registry/` endpoints
- Custom registry controllers should be replaced with GenericRegistryController
- Field validation is now enforced based on type definitions
- All input is automatically sanitized

### Backward Compatibility

- Existing database tables and data are preserved
- API response formats remain consistent
- ACL permissions are maintained
- Session management continues to work
