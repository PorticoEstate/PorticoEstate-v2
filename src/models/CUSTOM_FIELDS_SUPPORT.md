# GenericRegistry Custom Fields Support

## Overview

**YES, the GenericRegistry now has full custom fields support!** It integrates seamlessly with the phpGroupWare custom fields system to provide dynamic field definitions based on ACL locations.

## Features

### ✅ Implemented Custom Fields Features

1. **Automatic Loading**: Custom fields are automatically loaded based on the registry's ACL location
2. **Field Map Integration**: Custom fields are merged into the registry's field map
3. **Type Mapping**: Supports all phpGroupWare custom field types (text, int, select, date, etc.)
4. **Validation**: Custom field validation rules are automatically applied
5. **Choice Validation**: Select/radio fields validate against their defined choices
6. **JSON Storage**: Optional JSON storage for custom fields data
7. **ACL Integration**: Uses the registry's ACL app and location to find custom fields

### 🔧 How It Works

#### 1. ACL Location Detection
Each registry type defines its ACL location:
```php
'office' => [
    'table' => 'bb_office',
    'acl_app' => 'booking',
    'acl_location' => '.office',
    // ...
]
```

#### 2. Automatic Field Loading
Custom fields are automatically loaded from `phpgwapi_custom_fields` based on:
- **App Name**: From `acl_app` (e.g., 'booking')
- **Location**: From `acl_location` (e.g., '.office')
- **Location ID**: Looked up in `phpgw_locations` table

#### 3. Field Map Integration
Custom fields are merged into the registry's field map:
```php
$registry = BookingGenericRegistry::forType('office');
$fieldMap = $registry->getInstanceFieldMap();

// Contains both static fields and custom fields:
// - id: int
// - name: string (static)
// - description: string (static) 
// - custom_field_1: string (custom)
// - custom_field_2: int (custom)
```

## Custom Field Types Support

### Supported phpGroupWare Field Types

| phpGW Type | BaseModel Type | Description |
|------------|----------------|-------------|
| `I` | `int` | Integer |
| `N` | `float` | Numeric/Float |
| `V` | `string` | Varchar/String |
| `T` | `string` | Text (HTML) |
| `R` | `string` | Radio/Select (single choice) |
| `LB` | `string` | Listbox (single choice) |
| `CH` | `array` | Checkbox (multiple choice) |
| `D` | `string` | Date |
| `DT` | `string` | DateTime |
| `B` | `int` | Boolean (0/1) |

### Validation Features

- **Required Fields**: Based on `nullable` property
- **Length Validation**: For varchar fields with `size` property
- **Choice Validation**: For select/radio/checkbox fields
- **Type Validation**: Automatic type checking
- **Custom Validators**: Support for custom validation functions

## API Usage

### Getting Field Map with Custom Fields
```php
$registry = BookingGenericRegistry::forType('office');
$fieldMap = $registry->getInstanceFieldMap(); // Includes custom fields
```

### ACL Information
```php
$aclInfo = $registry->getAclInfo();
// Returns:
// [
//     'app' => 'booking',
//     'location' => '.office',
//     'menu_selection' => 'booking::settings::office::office'
// ]
```

### Custom Fields Location ID
```php
$locationId = $registry->getInstanceCustomFieldsLocationId();
// Returns the location_id from phpgw_locations table
```

### Raw Custom Fields Data
```php
$customFields = $registry->getInstanceCustomFields();
// Returns array of custom field definitions from phpgwapi_custom_fields
```

## Configuration

### Registry Type Configuration
To enable custom fields for a registry type, simply define the ACL location:

```php
'office' => [
    'table' => 'bb_office',
    'id' => ['name' => 'id', 'type' => 'auto'],
    'fields' => [
        // Static fields
    ],
    'acl_app' => 'booking',              // ✅ Required for custom fields
    'acl_location' => '.office',         // ✅ Required for custom fields
    'custom_fields_json_field' => 'json_representation', // Optional
]
```

### JSON Storage (Optional)
You can enable JSON storage for custom fields by adding:
```php
'custom_fields_json_field' => 'json_representation'
```

This will store all custom fields as JSON in a single database column instead of individual columns.

## Database Requirements

### Required Tables
For custom fields to work, these phpGroupWare tables must exist:

1. **`phpgw_locations`**: Maps app/location to location_id
2. **`phpgw_config2_attrib`**: Stores custom field definitions  
3. **`phpgw_config2_choice`**: Stores choice options for select fields

### Graceful Degradation
If these tables don't exist, the system gracefully degrades:
- No custom fields are loaded
- Static registry fields continue to work normally
- No errors are thrown

## Implementation Example

### Complete Registry with Custom Fields
```php
class PropertyGenericRegistry extends GenericRegistry
{
    protected static function loadRegistryDefinitions(): void
    {
        static::$registryDefinitions = [
            'building' => [
                'table' => 'fm_building',
                'id' => ['name' => 'id', 'type' => 'auto'],
                'fields' => [
                    [
                        'name' => 'name',
                        'descr' => 'Building Name',
                        'type' => 'varchar',
                        'required' => true
                    ],
                    [
                        'name' => 'address',
                        'descr' => 'Address',
                        'type' => 'text',
                        'nullable' => true
                    ]
                ],
                'acl_app' => 'property',           // ✅ Custom fields enabled
                'acl_location' => '.building',     // ✅ Custom fields enabled
                'name' => 'Buildings',
            ]
        ];
    }
}
```

### Using in Controller
```php
$registry = PropertyGenericRegistry::forType('building');
$fieldMap = $registry->getInstanceFieldMap();

// Now includes both static and custom fields:
foreach ($fieldMap as $field => $config) {
    $isCustom = $config['custom_field'] ?? false;
    echo "$field: {$config['type']}" . ($isCustom ? ' (custom)' : '') . "\n";
}
```

## Benefits

### 1. **Dynamic Configuration**
- Add fields without code changes
- Configure via phpGroupWare admin interface
- Different field sets per registry type

### 2. **Validation Integration**
- Automatic validation based on field types
- Choice validation for select fields
- Length limits and required field checks

### 3. **API Consistency**
- Custom fields appear in field maps
- Same validation and serialization as static fields
- Transparent to API consumers

### 4. **Legacy Compatibility**
- Works with existing phpGroupWare custom fields
- No migration needed for existing custom fields
- Backward compatible with registries without custom fields

## Testing

Custom fields support includes comprehensive tests:

```bash
# Test custom fields integration
docker exec portico_api php /var/www/html/test_registry_custom_fields.php
```

The tests verify:
- ✅ Field map integration
- ✅ ACL location detection  
- ✅ Custom fields loading
- ✅ Multiple registry types
- ✅ Static method integration
- ✅ Graceful degradation

## Summary

**GenericRegistry now provides complete custom fields support** that:

- ✅ **Integrates seamlessly** with phpGroupWare custom fields
- ✅ **Automatically loads** custom fields based on ACL locations  
- ✅ **Validates and sanitizes** custom field data
- ✅ **Supports all field types** (text, int, select, date, etc.)
- ✅ **Provides JSON storage** option for custom fields
- ✅ **Degrades gracefully** when custom fields system isn't available
- ✅ **Maintains API consistency** with static fields

This makes the GenericRegistry a truly dynamic, configuration-driven system that can adapt to different module needs without code changes.
