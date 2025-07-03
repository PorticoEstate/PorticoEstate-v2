# GenericRegistry API Reference

## Class: App\models\GenericRegistry (Abstract)

Abstract base class for configuration-driven registry systems. Must be extended by module-specific implementations.

### Constructor

```php
public function __construct(string $registryType = '', array $data = [])
```

Creates a new registry instance for a specific type.

**Parameters:**
- `$registryType` - The registry type identifier
- `$data` - Initial data for the instance

### Static Factory Methods

#### forType()
```php
public static function forType(string $registryType, array $data = []): static
```

Creates a new instance for a specific registry type.

**Parameters:**
- `$registryType` - Registry type identifier
- `$data` - Initial data array

**Returns:** New instance of the registry class

**Example:**
```php
$office = BookingGenericRegistry::forType('office', ['name' => 'Main Office']);
```

#### createForType()
```php
public static function createForType(string $type, array $data = []): static
```

Alias for `forType()` - creates a new instance for a specific registry type.

### Registry Configuration Methods

#### getRegistryConfig()
```php
public static function getRegistryConfig(string $type): array
```

Gets the configuration for a specific registry type.

**Parameters:**
- `$type` - Registry type identifier

**Returns:** Registry configuration array

**Example:**
```php
$config = BookingGenericRegistry::getRegistryConfig('office');
// Returns: ['table' => 'bb_office', 'fields' => [...], ...]
```

#### getAvailableTypes()
```php
public static function getAvailableTypes(): array
```

Gets list of all available registry types for this implementation.

**Returns:** Array of registry type identifiers

**Example:**
```php
$types = BookingGenericRegistry::getAvailableTypes();
// Returns: ['office', 'office_user', 'article_category', ...]
```

#### getTypeName()
```php
public static function getTypeName(string $type): string
```

Gets the human-readable display name for a registry type.

**Parameters:**
- `$type` - Registry type identifier

**Returns:** Human-readable name

**Example:**
```php
$name = BookingGenericRegistry::getTypeName('office');
// Returns: "Office"
```

### CRUD Methods

#### findByType()
```php
public static function findByType(string $type, int $id): ?static
```

Finds a single record by type and ID.

**Parameters:**
- `$type` - Registry type identifier  
- `$id` - Record ID

**Returns:** Registry instance or null if not found

**Example:**
```php
$office = BookingGenericRegistry::findByType('office', 123);
```

#### findWhereByType()
```php
public static function findWhereByType(string $type, array $conditions = [], array $options = []): array
```

Finds multiple records by type with conditions.

**Parameters:**
- `$type` - Registry type identifier
- `$conditions` - Search conditions array
- `$options` - Query options (limit, offset, order, etc.)

**Returns:** Array of registry instances

**Example:**
```php
$activeOffices = BookingGenericRegistry::findWhereByType('office', 
    ['active' => 1], 
    ['order' => 'name ASC', 'limit' => 10]
);
```

### Instance Methods

#### save()
```php
public function save(): bool
```

Saves the registry instance to the database.

**Returns:** True if successful, false otherwise

**Example:**
```php
$office = BookingGenericRegistry::forType('office');
$office->name = 'New Office';
$office->description = 'Office description';
if ($office->save()) {
    echo "Office saved successfully";
}
```

#### validate()
```php
public function validate(): array
```

Validates the instance data against field definitions.

**Returns:** Array of validation errors (empty if valid)

**Example:**
```php
$office = BookingGenericRegistry::forType('office');
$errors = $office->validate();
if (empty($errors)) {
    $office->save();
} else {
    print_r($errors);
}
```

#### getInstanceFieldMap()
```php
public function getInstanceFieldMap(): array
```

Gets the field map for this registry instance.

**Returns:** Field map array with validation rules

**Example:**
```php
$office = BookingGenericRegistry::forType('office');
$fieldMap = $office->getInstanceFieldMap();
// Returns: ['id' => ['type' => 'int', ...], 'name' => ['type' => 'string', ...]]
```

#### getAclInfo()
```php
public function getAclInfo(): array
```

Gets ACL information for this registry type.

**Returns:** Array with ACL application, location, and menu selection

**Example:**
```php
$office = BookingGenericRegistry::forType('office');
$acl = $office->getAclInfo();
// Returns: ['app' => 'booking', 'location' => '.office', 'menu_selection' => '...']
```

#### getRegistryName()
```php
public function getRegistryName(): string
```

Gets the human-readable name for this registry instance.

**Returns:** Registry display name

**Example:**
```php
$office = BookingGenericRegistry::forType('office');
$name = $office->getRegistryName();
// Returns: "Office"
```

### Abstract Methods (Must be Implemented)

#### loadRegistryDefinitions()
```php
protected static abstract function loadRegistryDefinitions(): void
```

Must be implemented by child classes to load their specific registry definitions.

**Example Implementation:**
```php
protected static function loadRegistryDefinitions(): void
{
    static::$registryDefinitions = [
        'my_type' => [
            'table' => 'my_table',
            'id' => ['name' => 'id', 'type' => 'auto'],
            'fields' => [
                // Field definitions
            ],
            'name' => 'My Type',
            'acl_app' => 'mymodule',
            'acl_location' => '.admin',
        ]
    ];
}
```

## Registry Definition Structure

Each registry type definition must include:

```php
'registry_type_name' => [
    'table' => 'database_table_name',           // Required
    'id' => ['name' => 'id', 'type' => 'auto'], // Required
    'fields' => [                               // Required
        [
            'name' => 'field_name',             // Required
            'descr' => 'Field Description',     // Required
            'type' => 'varchar',                // Required
            'required' => true,                 // Optional
            'nullable' => false,                // Optional
            'maxlength' => 255,                 // Optional
            'default' => 'default_value',       // Optional
            'filter' => true,                   // Optional
            'values_def' => [...]               // Optional (for select fields)
        ]
    ],
    'name' => 'Human Readable Name',            // Required
    'acl_app' => 'module_name',                 // Required
    'acl_location' => '.admin',                 // Required
    'menu_selection' => 'menu::path::here',     // Optional
]
```

## Field Type Mapping

| Registry Type | BaseModel Type | PHP Type | Description |
|---------------|----------------|----------|-------------|
| `varchar` | `string` | `string` | Short text field |
| `text` | `string` | `string` | Long text field |
| `int` | `int` | `int` | Integer number |
| `decimal` | `float` | `float` | Decimal number |
| `float` | `float` | `float` | Floating point number |
| `checkbox` | `bool` | `bool` | Boolean field |
| `date` | `date` | `string` | Date field (Y-m-d) |
| `datetime` | `datetime` | `string` | Date/time field |
| `timestamp` | `datetime` | `string` | Timestamp field |
| `html` | `html` | `string` | HTML content |
| `select` | `string` | `string` | Select dropdown |

## Usage Examples

### Basic Usage
```php
// Create new item
$item = YourModuleGenericRegistry::createForType('category', [
    'name' => 'New Category',
    'description' => 'Category description'
]);
$item->save();

// Find item
$item = YourModuleGenericRegistry::findByType('category', 1);

// Update item
$item->name = 'Updated Name';
$item->save();

// Search items
$items = YourModuleGenericRegistry::findWhereByType('category', [
    'active' => 1
]);
```

### Advanced Usage
```php
// Get all available types
$types = YourModuleGenericRegistry::getAvailableTypes();

// Get field configuration
$config = YourModuleGenericRegistry::getRegistryConfig('category');
$fields = $config['fields'];

// Validate before saving
$item = YourModuleGenericRegistry::forType('category');
$item->name = 'Test';
$errors = $item->validate();
if (empty($errors)) {
    $item->save();
}

// Get field map for forms
$fieldMap = $item->getInstanceFieldMap();
foreach ($fieldMap as $field => $config) {
    echo "Field: {$field}, Type: {$config['type']}\n";
}
```
