# BaseModel Usage Guide

The `BaseModel` class provides a generic foundation for CRUD operations in the booking system, inspired by the legacy `booking_socommon` class but modernized with PHP 8+ features and a clean FieldMap/RelationshipMap architecture.

## Overview

The `BaseModel` class offers:
- **Automatic CRUD operations** based on field definitions
- **Relationship management** (many-to-many, one-to-many, belongs-to, has-one)
- **Type-safe marshaling/unmarshaling** for database operations
- **Validation and sanitization** based on field maps
- **Query building** with filtering and pagination
- **Transaction support** for complex operations

## Creating a Model

To create a new model, extend `BaseModel` and implement the required abstract methods:

```php
<?php

namespace App\modules\booking\models;

use App\Database\Db;

class MyModel extends BaseModel
{
    // Override BaseModel properties to make them accessible
    protected array $fieldMap = [];
    protected array $relationshipMap = [];

    // Define model properties
    public ?int $id = null;
    public string $name;
    public string $description = '';
    public int $active = 1;

    /**
     * Get table name for the model
     */
    protected static function getTableName(): string
    {
        return 'bb_my_table';
    }

    /**
     * Get field map - required by BaseModel
     */
    protected static function getFieldMap(): array
    {
        return [
            'id' => [
                'type' => 'int',
                'required' => false,
            ],
            'name' => [
                'type' => 'string',
                'required' => true,
                'maxLength' => 255,
                'query' => true, // Allow searching by this field
            ],
            'description' => [
                'type' => 'text',
                'required' => false,
                'query' => true,
            ],
            'active' => [
                'type' => 'int',
                'required' => true,
                'default' => 1,
            ],
        ];
    }

    /**
     * Initialize field map - required by BaseModel
     */
    protected function initializeFieldMap(): void
    {
        $this->fieldMap = static::getFieldMap();
    }

    /**
     * Initialize relationship map - optional
     */
    protected function initializeRelationshipMap(): void
    {
        $this->relationshipMap = static::getRelationshipMap();
    }

    /**
     * Get relationship map - optional override
     */
    protected static function getRelationshipMap(): array
    {
        return [
            // Define relationships here
        ];
    }

    /**
     * Get the location_id for custom fields (optional)
     * Return null to disable custom fields for this model
     */
    protected static function getCustomFieldsLocationId(): ?int
    {
        // Example: return static::getLocationId('booking', 'my_model');
        return null; // Disable custom fields by default
    }
}
```

## Field Map Configuration

The field map defines validation, sanitization, and metadata for each field:

```php
protected static function getFieldMap(): array
{
    return [
        'field_name' => [
            'type' => 'string',           // Field type: int, string, float, datetime, array, etc.
            'required' => true,           // Whether field is required
            'maxLength' => 255,           // Maximum length for strings
            'default' => 'default_value', // Default value
            'query' => true,              // Allow searching by this field
            'sanitize' => 'string',       // Sanitization type
            'validator' => function($value) { // Custom validation
                return $value ? null : 'Field is invalid';
            },
            'read_callback' => 'method',  // Callback for reading from DB
            'write_callback' => 'method', // Callback for writing to DB
        ],
    ];
}
```

### Supported Field Types
- `int` - Integer values
- `string` - String values
- `text` - Long text (like description)
- `float`/`decimal` - Floating point numbers
- `datetime`/`timestamp`/`date`/`time` - Date/time values
- `bool`/`boolean` - Boolean values
- `json` - JSON encoded data
- `array`/`intarray` - Array values
- `email` - Email addresses (with validation)
- `url` - URLs (with validation)

## Relationship Configuration

Define relationships in the relationship map:

```php
protected static function getRelationshipMap(): array
{
    return [
        'many_to_many_example' => [
            'type' => 'many_to_many',
            'table' => 'junction_table',        // Junction table
            'local_key' => 'local_id',          // Local key in junction table
            'foreign_key' => 'foreign_id',      // Foreign key in junction table
            'target_table' => 'target_table',   // Target table
            'target_key' => 'id',               // Target table key
            'select_fields' => ['id', 'name'],  // Fields to select from target
        ],
        'one_to_many_example' => [
            'type' => 'one_to_many',
            'table' => 'child_table',           // Child table
            'foreign_key' => 'parent_id',       // Foreign key in child table
            'select_fields' => ['id', 'name'],  // Fields to select
            'order_by' => 'name ASC',           // Optional ordering
        ],
        'belongs_to_example' => [
            'type' => 'belongs_to',
            'table' => 'parent_table',          // Parent table
            'foreign_key' => 'parent_id',       // Foreign key in this table
            'owner_key' => 'id',                // Key in parent table
            'select_fields' => ['id', 'name'],  // Fields to select
        ],
        'has_one_example' => [
            'type' => 'has_one',
            'table' => 'related_table',         // Related table
            'foreign_key' => 'this_id',         // Foreign key in related table
            'select_fields' => ['id', 'value'], // Fields to select
        ],
    ];
}
```

## Custom Fields Integration

BaseModel supports automatic integration with the phpGroupWare custom fields system. This allows your models to dynamically include user-defined custom fields without manual configuration.

### Enabling Custom Fields

To enable custom fields for your model, override the `getCustomFieldsLocationId()` method:

```php
protected static function getCustomFieldsLocationId(): ?int
{
    return static::getLocationId('booking', 'my_model');
}
```

### Setting up the Database Location

Ensure your model has a location in the `phpgw_locations` table:

```sql
INSERT INTO phpgw_locations (app_name, location, descr) 
VALUES ('booking', 'my_model', 'My Model Description');
```

### How Custom Fields Work

Once configured, custom fields are automatically:
- **Loaded** from the database using `phpgwapi_custom_fields::find2()`
- **Merged** with your static field map
- **Validated** and **sanitized** using appropriate type mappings
- **Included** in all CRUD operations

### Example Usage

```php
// Custom fields work automatically once configured
$model = new MyModel([
    'name' => 'Regular Field',
    'custom_priority' => 'high',     // Custom field
    'custom_category' => 'business'   // Custom field
]);

// Validation includes custom fields
$errors = $model->validate();

// Save includes custom fields
$model->save();

// Get complete field map (static + custom)
$allFields = MyModel::getCompleteFieldMap();
```

### JSON Storage for Custom Fields

For models with many custom fields, you can enable JSON storage to store all custom fields in a single database column:

```php
protected static function getCustomFieldsJsonField(): ?string
{
    return 'json_representation'; // Store custom fields as JSON
}
```

This is useful when you want to avoid database schema changes or have many dynamic custom fields.

For detailed information about custom fields, see `CustomFields_Integration_Guide.md`.

## CRUD Operations

### Create (Add)
```php
// Using static method (compatible with legacy booking_socommon)
$result = MyModel::add([
    'name' => 'Test Name',
    'description' => 'Test Description',
    'active' => 1
]);

// Using instance method
$model = new MyModel();
$model->name = 'Test Name';
$model->description = 'Test Description';
$model->active = 1;
$success = $model->save();
```

### Read (Find)
```php
// Read single record
$entity = MyModel::readSingle(1);

// Read with filters and pagination
$result = MyModel::read([
    'query' => 'search term',        // Search in queryable fields
    'filters' => [                   // Exact filters
        'active' => 1,
        'category_id' => [1, 2, 3],  // IN clause
    ],
    'sort' => 'name',               // Sort field
    'dir' => 'ASC',                 // Sort direction
    'start' => 0,                   // Offset
    'results' => 50,                // Limit
]);

// Using new methods
$entities = MyModel::findWhere(['active' => 1], [
    'order_by' => 'name',
    'direction' => 'ASC',
    'limit' => 10
]);

$entity = MyModel::find(1);
```

### Update
```php
// Using static method (compatible with legacy booking_socommon)
$result = MyModel::updateEntity([
    'id' => 1,
    'name' => 'Updated Name',
    'description' => 'Updated Description'
]);

// Using instance method
$model = MyModel::find(1);
$model->name = 'Updated Name';
$success = $model->save();
```

### Delete
```php
// Using static method
$result = MyModel::deleteEntity(1);

// Using instance method
$model = MyModel::find(1);
$success = $model->delete();
```

## Working with Relationships

### Loading Relationships
```php
// Load individual relationship
$model = new MyModel(['id' => 1]);
$relatedData = $model->loadRelationship('relationship_name');

// Load using the enhanced BaseModel
$entity = MyModel::readSingle(1); // Automatically loads relationships defined in field map

// Manual relationship loading for specific needs
$children = $model->loadRelationship('children');
$parent = $model->loadRelationship('parent');
```

### Saving Relationships
```php
// Save many-to-many relationships
$model = new MyModel(['id' => 1]);
$success = $model->saveRelationship('tags', [1, 2, 3, 4]); // Array of IDs

// Save relationships during create/update
$result = MyModel::add([
    'name' => 'Test',
    'tags' => [1, 2, 3],        // Many-to-many
    'comments' => [             // One-to-many
        ['comment' => 'First comment'],
        ['comment' => 'Second comment']
    ]
]);
```

## Advanced Features

### Custom Validation
```php
protected function doCustomValidation(): array
{
    $errors = [];
    
    // Custom cross-field validation
    if ($this->start_date && $this->end_date && $this->start_date > $this->end_date) {
        $errors[] = 'Start date must be before end date';
    }
    
    return $errors;
}
```

### Transaction Support
```php
$model = new MyModel();
$model->beginTransaction();

try {
    $model->save();
    $model->saveRelationship('tags', [1, 2, 3]);
    $model->commit();
} catch (Exception $e) {
    $model->rollBack();
    throw $e;
}
```

### Custom Marshaling/Unmarshaling
```php
// Add to field map
'custom_field' => [
    'type' => 'string',
    'read_callback' => 'decryptValue',
    'write_callback' => 'encryptValue',
]

// Define callbacks in model
protected function encryptValue(&$value, $reverse = false)
{
    if (!$reverse) {
        $value = encrypt($value);
    }
}

protected function decryptValue(&$value, $reverse = false)
{
    if ($reverse) {
        $value = decrypt($value);
    }
}
```

## Data Marshaling and Unmarshaling

### Modern vs Legacy Unmarshal

BaseModel implements its own modern unmarshaling logic rather than using the legacy `Db::unmarshal` method for several important reasons:

#### BaseModel Advantages

- **Enhanced Type Support**: Supports modern types like `bool`, `array`, `intarray`, etc.
- **Better JSON Handling**: More robust JSON/array parsing and validation
- **No Legacy Dependencies**: Doesn't rely on legacy `stripslashes` behavior
- **Modifier Support**: Built-in support for field modifier callbacks
- **Performance**: Direct processing without extra method calls
- **Extensibility**: Easier to extend for future type requirements

#### Legacy Compatibility

For scenarios requiring legacy compatibility, BaseModel provides `unmarshalValueLegacyCompat()` method that can optionally use `Db::unmarshal` for basic types (`int`, `decimal`, `string`, `json`) while falling back to the modern implementation for advanced types or if legacy processing fails.

```php
// Use modern unmarshaling (default, recommended)
$value = static::unmarshalValueWithModifier($dbValue, 'array', 'trimStrings');

// Use legacy compatibility mode (only if needed)
$value = static::unmarshalValueLegacyCompat($dbValue, 'string', 'trimStrings', true);
```

#### Type Comparison

| Type | Legacy Db::unmarshal | BaseModel::unmarshal |
|------|---------------------|---------------------|
| int | ✓ | ✓ |
| decimal | ✓ | ✓ (+ float) |
| string | ✓ (with stripslashes) | ✓ (clean) |
| json | ✓ (basic) | ✓ (enhanced) |
| bool | ✗ | ✓ |
| array | ✗ | ✓ |
| intarray | ✗ | ✓ |
| datetime/timestamp | ✗ | ✓ |

### Field Modifiers

Both legacy and modern implementations support field modifiers - callback methods that can transform values during unmarshaling:

```php
protected static function trimStrings(&$value)
{
    if (is_string($value)) {
        $value = trim($value);
    }
}

// In FieldMap:
'description' => [
    'type' => 'string',
    'modifier' => 'trimStrings'
]
```

## Event Model Example

The `Event` model has been updated to use `BaseModel`:

```php
// Create event with relationships
$result = Event::add([
    'name' => 'Test Event',
    'organizer' => 'Test Organizer',
    'building_id' => 1,
    'activity_id' => 1,
    'from_' => '2025-01-01 10:00:00',
    'to_' => '2025-01-01 12:00:00',
    'resources' => [1, 2, 3],           // Many-to-many
    'audience' => [1, 2],               // Many-to-many
    'comments' => [                     // One-to-many
        ['comment' => 'Initial comment', 'type' => 'system']
    ]
]);

// Read event with all relationships
$event = Event::readSingle(1);

// Search events
$events = Event::read([
    'query' => 'meeting',
    'filters' => ['active' => 1],
    'sort' => 'from_',
    'dir' => 'DESC'
]);
```

## Migration from Legacy Code

To migrate existing models from `booking_socommon`:

1. **Extend BaseModel** instead of `booking_socommon`
2. **Implement required methods**: `getTableName()`, `getFieldMap()`, `initializeFieldMap()`
3. **Convert field definitions** to the new field map format
4. **Define relationships** in the relationship map
5. **Update method calls**:
   - `$so->read()` → `Model::read()`
   - `$so->read_single()` → `Model::readSingle()`
   - `$so->add()` → `Model::add()`
   - `$so->update()` → `Model::updateEntity()`
   - `$so->delete()` → `Model::deleteEntity()`

## Benefits over Legacy booking_socommon

- **Type Safety**: Full PHP 8+ type hints and strict typing
- **Modern Architecture**: Clean separation of concerns with FieldMap/RelationshipMap
- **Better Performance**: Optimized queries and lazy loading
- **Extensibility**: Easy to extend and customize for specific needs
- **Maintainability**: Clear, documented code with modern patterns
- **Backward Compatibility**: Maintains compatibility with legacy method signatures

The BaseModel provides a solid foundation for all booking system entities while maintaining compatibility with existing legacy code patterns.
