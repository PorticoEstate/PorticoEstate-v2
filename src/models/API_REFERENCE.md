# API Reference

## Class: App\models\BaseModel (Abstract)

Abstract base class for CRUD operations with field validation, sanitization, and relationship management.

### Constructor

```php
public function __construct(?array $data = null)
```

Creates a new model instance.

**Parameters:**
- `$data` - Optional initial data to populate#### Working with Relationships
```php
// Load relationship
$item = MyModel::find(1);
$tags = $item->loadRelationship('tags');

// Load multiple items with relationships - requires custom implementation
$items = MyModel::findWhere([], ['joins' => ['category']]);
```**Example:**
```php
$model = new MyModel(['name' => 'Test', 'active' => true]);
```

### Abstract Methods (Must be Implemented)

#### getFieldMap()
```php
protected static abstract function getFieldMap(): array
```

Must return field definitions for validation and marshaling.

**Example Implementation:**
```php
protected static function getFieldMap(): array
{
    return [
        'id' => ['type' => 'int', 'required' => false],
        'name' => ['type' => 'string', 'required' => true, 'maxLength' => 255],
        'email' => ['type' => 'email', 'required' => true],
        'active' => ['type' => 'bool', 'default' => true],
        'created_date' => ['type' => 'datetime'],
    ];
}
```

#### getTableName()
```php
protected static abstract function getTableName(): string
```

Must return the database table name.

**Example Implementation:**
```php
protected static function getTableName(): string
{
    return 'my_table';
}
```

### Optional Methods

#### getRelationshipMap()
```php
protected static function getRelationshipMap(): array
```

Define entity relationships (optional).

**Example Implementation:**
```php
protected static function getRelationshipMap(): array
{
    return [
        'category' => [
            'type' => 'belongs_to',
            'model' => Category::class,
            'foreign_key' => 'category_id',
        ],
        'tags' => [
            'type' => 'many_to_many',
            'model' => Tag::class,
            'junction_table' => 'item_tags',
            'local_key' => 'item_id',
            'foreign_key' => 'tag_id',
        ],
    ];
}
```

#### getCustomFieldsLocationId()
```php
protected static function getCustomFieldsLocationId(): ?int
```

Return location ID for custom fields integration.

#### getCustomFieldsJsonField()
```php
protected static function getCustomFieldsJsonField(): ?string
```

Return field name to store custom fields as JSON.

### Instance Methods

#### populate()
```php
public function populate(array $data): self
```

Populate model with data from array.

**Parameters:**
- `$data` - Data array to populate model with

**Returns:** Self for method chaining

**Example:**
```php
$model->populate(['name' => 'Updated Name', 'active' => false]);
```

#### validate()
```php
public function validate(): array
```

Validate model data against field definitions.

**Returns:** Array of validation errors (empty if valid)

**Example:**
```php
$model = new MyModel();
$model->name = ''; // Required field
$errors = $model->validate();
if (!empty($errors)) {
    foreach ($errors as $error) {
        echo $error . "\n";
    }
}
```

#### save()
```php
public function save(): bool
```

Save the model to database (create or update).

**Returns:** True if successful, false otherwise

**Example:**
```php
$model = new MyModel();
$model->name = 'New Item';
$model->email = 'test@example.com';

if ($model->save()) {
    echo "Saved successfully with ID: " . $model->id;
} else {
    echo "Save failed";
}
```

#### delete()
```php
public function delete(): bool
```

Delete the model from database.

**Returns:** True if successful, false otherwise

**Example:**
```php
$model = MyModel::findById(123);
if ($model && $model->delete()) {
    echo "Deleted successfully";
}
```

#### loadRelationship()
```php
public function loadRelationship(string $relationshipName): ?array
```

Load a specific relationship for this model.

**Parameters:**
- `$relationshipName` - Name of the relationship as defined in getRelationshipMap()

**Returns:** Array of related data or null if not found

**Example:**
```php
$item = MyModel::find(1);
$tags = $item->loadRelationship('tags');
$category = $item->loadRelationship('category');
```

### Static Methods

#### find()
```php
public static function find(int $id): ?static
```

Find model by ID.

**Parameters:**
- `$id` - Record ID

**Returns:** Model instance or null if not found

**Example:**
```php
$model = MyModel::find(123);
if ($model) {
    echo "Found: " . $model->name;
}
```

#### findWhere()
```php
public static function findWhere(array $conditions = [], array $options = []): array
```

Find multiple models with conditions.

**Parameters:**
- `$conditions` - Search conditions
- `$options` - Query options (limit, offset, order, joins)

**Returns:** Array of model instances

**Example:**
```php
$models = MyModel::findWhere(
    ['active' => true, 'category_id' => 5],
    ['order' => 'name ASC', 'limit' => 10]
);
```

#### getSanitizationRules()
```php
public static function getSanitizationRules(): array
```

Get sanitization rules from field map.

**Returns:** Array of field sanitization rules

### Field Map Configuration

Each field in the field map can have the following properties:

```php
'field_name' => [
    'type' => 'string',           // Required: Field type
    'required' => true,           // Optional: Is field required
    'nullable' => false,          // Optional: Can field be null
    'maxLength' => 255,           // Optional: Max length for strings
    'default' => 'default_value', // Optional: Default value
    'sanitize' => 'string',       // Optional: Sanitization type
    'validator' => function($value, $model) { // Optional: Custom validator
        return $value !== 'invalid' ? null : 'Invalid value';
    },
    'custom_field' => true,       // Optional: Is this a custom field
]
```

### Supported Field Types

| Type | Description | PHP Type | Validation |
|------|-------------|----------|------------|
| `int` | Integer number | `int` | Must be numeric |
| `string` | Short text | `string` | Basic string validation |
| `text` | Long text | `string` | Basic string validation |
| `float` | Floating point | `float` | Must be numeric |
| `decimal` | Decimal number | `float` | Must be numeric |
| `bool` | Boolean | `bool` | Must be boolean-like |
| `date` | Date | `string` | Must be valid date |
| `datetime` | Date/time | `string` | Must be valid datetime |
| `timestamp` | Timestamp | `string` | Must be valid datetime |
| `time` | Time | `string` | Must be valid time |
| `email` | Email address | `string` | Must be valid email |
| `url` | URL | `string` | Must be valid URL |
| `html` | HTML content | `string` | No additional validation |
| `array` | Array data | `array` | Must be array |
| `json` | JSON data | `string` | No additional validation |
| `intarray` | Array of integers | `array` | Each element must be int |

### Relationship Types

#### belongs_to
```php
'category' => [
    'type' => 'belongs_to',
    'model' => Category::class,
    'foreign_key' => 'category_id',
]
```

#### has_one
```php
'profile' => [
    'type' => 'has_one',
    'model' => Profile::class,
    'foreign_key' => 'user_id',
]
```

#### has_many
```php
'orders' => [
    'type' => 'has_many',
    'model' => Order::class,
    'foreign_key' => 'customer_id',
]
```

#### many_to_many
```php
'tags' => [
    'type' => 'many_to_many',
    'model' => Tag::class,
    'junction_table' => 'item_tags',
    'local_key' => 'item_id',
    'foreign_key' => 'tag_id',
]
```

### Usage Examples

#### Basic CRUD Operations
```php
// Create
$item = new MyModel();
$item->name = 'Test Item';
$item->description = 'Test description';
$item->save();

// Read
$item = MyModel::find(1);
$items = MyModel::findWhere(['active' => true]);

// Update
$item->name = 'Updated Name';
$item->save();

// Delete
$item->delete();
```

#### Working with Relationships
```php
// Load relationship
$item = MyModel::findById(1);
$category = $item->getRelated('category');

// Load multiple items with relationships
$items = MyModel::findWhere([], ['joins' => ['category']]);
foreach ($items as $item) {
    echo $item->name . ' - ' . $item->category->name;
}
```

#### Custom Validation
```php
class MyModel extends BaseModel
{
    // ...existing code...
    
    protected function doCustomValidation(): array
    {
        $errors = [];
        
        // Custom business logic validation
        if ($this->start_date && $this->end_date && $this->start_date > $this->end_date) {
            $errors[] = 'Start date must be before end date';
        }
        
        return $errors;
    }
}
```

---

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
