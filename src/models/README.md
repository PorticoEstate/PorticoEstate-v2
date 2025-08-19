# Global Models Documentation

This directory contains the global model classes that can be used across all modules in the project.

## BaseModel

The `BaseModel` class provides a foundation for all data models with:
- CRUD operations (Create, Read, Update, Delete)
- Field validation and sanitization
- Relationship mapping
- Database abstraction
- Serialization support

### Usage Example
```php
use App\models\BaseModel;

class YourModel extends BaseModel
{
    protected static function getTableName(): string
    {
        return 'your_table';
    }
    
    protected static function getFieldMap(): array
    {
        return [
            'id' => ['type' => 'int', 'required' => false],
            'name' => ['type' => 'string', 'required' => true, 'maxLength' => 255],
            'email' => ['type' => 'email', 'required' => true],
            'active' => ['type' => 'bool', 'default' => true],
        ];
    }
}
```

## GenericRegistry (Abstract)

The `GenericRegistry` class provides a configuration-driven approach for managing simple lookup tables and registries. It's abstract and must be extended by module-specific implementations.

### Key Features
- Configuration-driven field definitions
- Dynamic table and field mapping
- Built-in ACL support
- Type-safe operations
- Consistent API across all registry types

### Implementation Pattern
Each module should create its own GenericRegistry extension:

```php
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
                'name' => 'Your Registry Type',
                'acl_app' => 'yourmodule',
                'acl_location' => '.admin',
            ]
        ];
    }
}
```

### Usage Example
```php
// Create instance for specific registry type
$registry = YourModuleGenericRegistry::forType('your_registry_type');

// Find records
$items = YourModuleGenericRegistry::findWhereByType('your_registry_type', ['active' => 1]);

// Create new record
$newItem = YourModuleGenericRegistry::createForType('your_registry_type', [
    'name' => 'New Item',
    'description' => 'Item description'
]);
$newItem->save();

// Get available types
$types = YourModuleGenericRegistry::getAvailableTypes();

// Get registry configuration
$config = YourModuleGenericRegistry::getRegistryConfig('your_registry_type');
```

## Field Type Mapping

The GenericRegistry automatically maps legacy field types to BaseModel types:

| Legacy Type | BaseModel Type | Description |
|-------------|----------------|-------------|
| `varchar` | `string` | Short text field |
| `text` | `string` | Long text field |
| `int` | `int` | Integer field |
| `decimal`, `float` | `float` | Floating point number |
| `checkbox` | `bool` | Boolean field |
| `date` | `date` | Date field |
| `datetime`, `timestamp` | `datetime` | Date and time field |
| `html` | `html` | HTML content field |
| `select` | `string` | Select dropdown field |

## Registry Configuration Structure

Each registry type definition should include:

```php
'registry_type_name' => [
    'table' => 'database_table_name',           // Required: Database table
    'id' => ['name' => 'id', 'type' => 'auto'], // Required: Primary key config
    'fields' => [                               // Required: Field definitions
        [
            'name' => 'field_name',             // Required: Database column name
            'descr' => 'Field Description',     // Required: Human-readable description
            'type' => 'varchar',                // Required: Field type
            'required' => true,                 // Optional: Is field required?
            'nullable' => false,                // Optional: Can field be null?
            'maxlength' => 255,                 // Optional: Maximum length
            'default' => 'default_value',       // Optional: Default value
            'filter' => true,                   // Optional: Can be used in filters?
            'values_def' => [                   // Optional: For select fields
                'method' => 'module.class.method',
                'method_input' => ['param' => 'value']
            ]
        ]
    ],
    'name' => 'Human Readable Name',            // Required: Display name
    'acl_app' => 'module_name',                 // Required: ACL application
    'acl_location' => '.admin',                 // Required: ACL location
    'menu_selection' => 'menu::path::here',     // Optional: Menu selection path
]
```

## Integration with Controllers

Generic registries are typically used with a controller that handles CRUD operations:

```php
use App\modules\yourmodule\models\YourModuleGenericRegistry;

class GenericRegistryController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'];
        
        // Validate type exists
        if (!in_array($type, YourModuleGenericRegistry::getAvailableTypes())) {
            throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
        }
        
        // Get items
        $items = YourModuleGenericRegistry::findWhereByType($type, $conditions, $options);
        
        return $response->withJson(['items' => $items]);
    }
}
```

## Best Practices

1. **One Registry Class per Module**: Each module should have its own GenericRegistry extension
2. **Consistent Naming**: Use clear, descriptive names for registry types and fields
3. **Proper ACL Configuration**: Always specify appropriate ACL settings
4. **Field Validation**: Use appropriate field types and validation rules
5. **Documentation**: Document your registry types and their purpose

## Example: Booking Module Implementation

The booking module provides a complete example of how to implement module-specific registries:

- **File**: `src/modules/booking/models/BookingGenericRegistry.php`
- **Registry Types**: office, office_user, article_category, article_service, vendor, etc.
- **Controller**: `src/modules/booking/controllers/GenericRegistryController.php`
- **Routes**: Defined in `src/routes/generic_registry.php`
