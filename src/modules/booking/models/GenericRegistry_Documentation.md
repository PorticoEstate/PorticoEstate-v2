# Generic Registry System

## Overview

The Generic Registry System provides a single controller and model architecture that can handle multiple simple entity types (registries) through configuration-driven definitions. This system is inspired by the property module's `sogeneric_`, `bogeneric`, and `uigeneric` pattern but modernized with our BaseModel architecture and Slim 4.

## Key Components

### 1. GenericRegistry Model (`/src/modules/booking/models/GenericRegistry.php`)

A specialized BaseModel that can handle multiple entity types through configuration:

- **Configuration-Driven**: Entity definitions stored in static arrays
- **Dynamic Field Maps**: Field validation and sanitization based on entity type
- **BaseModel Integration**: Inherits all BaseModel features (validation, sanitization, relationships, custom fields)
- **Type Safety**: Full type validation and sanitization per entity type

### 2. GenericRegistryController (`/src/modules/booking/controllers/GenericRegistryController.php`)

A single controller that handles CRUD operations for all registry types:

- **RESTful API**: Standard REST endpoints for all entity types
- **Type Validation**: Ensures registry types exist before operations
- **Error Handling**: Comprehensive error responses
- **Flexible Querying**: Search, filtering, sorting, and pagination

### 3. Route Configuration (`/src/routes/generic_registry.php`)

Defines the API endpoints for the generic registry system.

## Supported Registry Types

The system currently supports these registry types:

| Type | Table | Description |
|------|-------|-------------|
| `office` | `bb_office` | Office locations |
| `office_user` | `bb_office_user` | Office user assignments |
| `article_category` | `bb_article_category` | Article categories |
| `article_service` | `bb_service` | Article services |
| `e_lock_system` | `bb_e_lock_system` | Electronic lock systems |
| `multi_domain` | `bb_multi_domain` | Multi-domain configurations |

## Usage Examples

### Basic CRUD Operations

```php
// Create a new office
$office = GenericRegistry::createForType('office', [
    'name' => 'Main Office',
    'description' => 'Primary office location'
]);
$office->save();

// Find office by ID
$office = GenericRegistry::findByType('office', 1);

// Update office
$office->name = 'Updated Office Name';
$office->save();

// Delete office
$office->delete();

// Find all active services
$services = GenericRegistry::findWhereByType('article_service', ['active' => 1]);
```

### Controller Usage (REST API)

```bash
# Get all registry types
GET /api/registry/types

# Get office schema
GET /api/registry/office/schema

# List all offices
GET /api/registry/office

# Get specific office
GET /api/registry/office/1

# Create new office
POST /api/registry/office
{
    "name": "New Office",
    "description": "Office description"
}

# Update office
PUT /api/registry/office/1
{
    "name": "Updated Office Name"
}

# Delete office
DELETE /api/registry/office/1

# Get office list for dropdown
GET /api/registry/office/list?add_empty=1
```

## Adding New Registry Types

To add a new registry type, update the `loadRegistryDefinitions()` method in `GenericRegistry.php`:

```php
'new_registry_type' => [
    'table' => 'bb_new_table',
    'id' => ['name' => 'id', 'type' => 'auto'],
    'fields' => [
        [
            'name' => 'name',
            'descr' => 'Name',
            'type' => 'varchar',
            'required' => true,
            'maxlength' => 255
        ],
        [
            'name' => 'description',
            'descr' => 'Description',
            'type' => 'text',
            'nullable' => true
        ],
        [
            'name' => 'active',
            'descr' => 'Active',
            'type' => 'checkbox',
            'default' => 1,
            'filter' => true
        ]
    ],
    'name' => 'New Registry Type',
    'acl_app' => 'booking',
    'acl_location' => '.admin',
    'menu_selection' => 'booking::admin::new_registry'
]
```

## Field Type Mapping

Legacy field types are automatically mapped to BaseModel types:

| Legacy Type | BaseModel Type | Description |
|-------------|----------------|-------------|
| `varchar` | `string` | Variable-length string |
| `text` | `string` | Long text |
| `int` | `int` | Integer |
| `decimal`, `float` | `float` | Floating point number |
| `checkbox` | `bool` | Boolean (checkbox) |
| `date` | `date` | Date field |
| `datetime`, `timestamp` | `datetime` | Date and time |
| `html` | `html` | HTML content |
| `select` | `string` | Select dropdown |

## Field Configuration Options

Each field definition supports these options:

```php
[
    'name' => 'field_name',           // Field name (required)
    'descr' => 'Field Description',   // Human-readable description
    'type' => 'varchar',              // Field type (required)
    'required' => true,               // Whether field is required
    'nullable' => false,              // Whether field can be null
    'maxlength' => 255,               // Maximum length for strings
    'default' => 'default_value',     // Default value
    'filter' => true,                 // Allow filtering by this field
    'values_def' => [                 // For select fields
        'method' => 'some.method',
        'method_input' => ['param' => 'value']
    ]
]
```

## API Endpoints

### Registry Types
- `GET /api/registry/types` - Get all available registry types

### Schema Information
- `GET /api/registry/{type}/schema` - Get field schema for a registry type

### CRUD Operations
- `GET /api/registry/{type}` - List items (supports query, filters, pagination)
- `POST /api/registry/{type}` - Create new item
- `GET /api/registry/{type}/{id}` - Get specific item
- `PUT /api/registry/{type}/{id}` - Update item
- `DELETE /api/registry/{type}/{id}` - Delete item

### Utility Endpoints
- `GET /api/registry/{type}/list` - Get list for dropdowns (supports `add_empty`, `selected` params)

## Query Parameters

### List Endpoint (`GET /api/registry/{type}`)
- `start` - Offset for pagination (default: 0)
- `limit` - Number of items to return (default: 50)
- `query` - Search query (searches in name field)
- `sort` - Field to sort by (default: 'id')
- `dir` - Sort direction: 'ASC' or 'DESC' (default: 'ASC')
- `active` - Filter by active status (1 or 0)
- `parent_id` - Filter by parent ID

### List Utility Endpoint (`GET /api/registry/{type}/list`)
- `add_empty` - Add empty "-- Select --" option (true/false)
- `selected` - Mark item as selected by ID
- `active` - Only include active items (true/false)

## Benefits

### For Developers
- **Reduced Code Duplication**: One controller/model handles multiple entity types
- **Consistent API**: Same REST endpoints for all registry types
- **Type Safety**: Full validation and sanitization
- **Easy Extension**: Add new types by updating configuration

### For System Architecture
- **Maintainable**: Centralized logic for simple CRUD operations
- **Scalable**: Easy to add new registry types without new controllers
- **Standards Compliant**: RESTful API design
- **BaseModel Integration**: Inherits all BaseModel features (custom fields, relationships, etc.)

## Limitations

### Current Limitations
- **Simple Entities Only**: Best suited for basic CRUD operations
- **Static Configuration**: Registry definitions are hardcoded (could be moved to database)
- **Single Table**: Each registry type maps to one table
- **Limited Relationships**: Complex relationships better handled by dedicated models

### When NOT to Use
- **Complex Business Logic**: Use dedicated models for entities with complex operations
- **Multiple Tables**: Entities requiring joins across multiple tables
- **Complex Relationships**: Many-to-many relationships with additional logic
- **Heavy Customization**: Highly customized entities with unique behaviors

## Integration with BaseModel Features

The Generic Registry system inherits all BaseModel capabilities:

### Custom Fields
```php
// Enable custom fields for a registry type by adding location configuration
protected function getInstanceCustomFieldsLocationId(): ?int
{
    $app = $this->registryConfig['acl_app'] ?? 'booking';
    $location = $this->registryConfig['acl_location'] ?? '.admin';
    return static::getLocationId($app, $location);
}
```

### Validation and Sanitization
All fields are automatically validated and sanitized based on their type definitions.

### Error Handling
Comprehensive error responses with field-level validation messages.

## Future Enhancements

### Possible Improvements
1. **Database-Driven Configuration**: Move registry definitions to database tables
2. **UI Generator**: Automatically generate admin interfaces
3. **Advanced Relationships**: Support for complex relationships between registry types
4. **Bulk Operations**: Bulk create/update/delete operations
5. **Export/Import**: CSV/Excel export and import functionality
6. **Audit Trail**: Track changes to registry items
7. **Caching**: Cache registry configurations and frequently accessed items

## Migration from Legacy Generic Classes

If you're migrating from the property module's generic classes:

### From `property_sogeneric_`
- Replace `get_location_info()` with registry configuration
- Use `GenericRegistry::forType()` instead of type-specific instantiation
- Update field definitions to new format

### From `property_bogeneric`
- Replace session management with standard request parameters
- Use REST API endpoints instead of method calls
- Update filtering and querying to new parameter format

### From `property_uigeneric`
- Replace XSLT templates with modern frontend (React, Vue, etc.)
- Use REST API endpoints for data operations
- Implement frontend validation using schema endpoints

## Conclusion

The Generic Registry System provides a powerful, flexible way to handle multiple simple entity types with minimal code duplication. It leverages the BaseModel architecture while providing a clean, RESTful API for frontend consumption.

This system is ideal for administrative entities, lookup tables, and simple CRUD operations that don't require complex business logic or relationships.
