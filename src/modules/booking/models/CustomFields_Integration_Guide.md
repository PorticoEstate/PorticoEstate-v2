# Custom Fields Integration with BaseModel

## Overview

The BaseModel now supports automatic integration with the phpGroupWare custom fields system (`phpgwapi_custom_fields`). This allows models to dynamically include user-defined custom fields without manual configuration.

## Key Features

- **Automatic Field Discovery**: Custom fields are automatically retrieved based on location_id
- **Type Safety**: Custom field data types are mapped to BaseModel validation types
- **Seamless Integration**: Custom fields work with all existing BaseModel features (validation, sanitization, CRUD)
- **Choice Validation**: Radio buttons, checkboxes, and listboxes are validated against their defined choices
- **Backward Compatibility**: Existing code continues to work unchanged

## Architecture

### New BaseModel Methods

1. **`getCustomFieldsLocationId(): ?int`** (Optional, Override in child classes)
   - Returns the location_id for this model's custom fields
   - Return `null` to disable custom fields (default behavior)

2. **`getCompleteFieldMap(): array`** (Public)
   - Returns merged static + custom field definitions
   - Use this instead of `getFieldMap()` when you need complete field information

3. **`getCustomFields(): array`** (Public Static)
   - Retrieves custom fields from database using `phpgwapi_custom_fields::find2()`
   - Returns empty array if no location_id or database error

4. **`getLocationId(string $appName, string $location): ?int`** (Protected Static)
   - Helper to lookup location_id from phpgw_locations table
   - Used by `getCustomFieldsLocationId()` implementations

## Data Type Mapping

Custom field data types are automatically mapped to BaseModel types:

| Custom Field Type | BaseModel Type | Sanitization | Description |
|-------------------|----------------|--------------|-------------|
| `I` | `int` | `int` | Integer |
| `N` | `float` | `float` | Numeric/Decimal |
| `V` | `string` | `string` | Varchar |
| `T` | `string` | `html` | Text (may contain HTML) |
| `R` | `string` | `string` | Radio/Select (single choice) |
| `LB` | `string` | `string` | Listbox (single choice) |
| `CH` | `array` | `array_string` | Checkbox (multiple choice) |
| `D` | `string` | `string` | Date |
| `DT` | `string` | `string` | DateTime |
| `B` | `int` | `int` | Boolean (stored as 0/1) |

## Implementation Guide

### Step 1: Setup Database Location

Ensure your model has a location in the `phpgw_locations` table:

```sql
-- Check if location exists
SELECT location_id FROM phpgw_locations 
WHERE app_name = 'booking' AND location = 'event';

-- If not exists, insert it
INSERT INTO phpgw_locations (app_name, location, descr) 
VALUES ('booking', 'event', 'Booking Events');
```

### Step 2: Implement Custom Fields Location in Your Model

```php
class Event extends BaseModel
{
    // ... existing code ...
    
    /**
     * Get the location_id for custom fields
     */
    protected static function getCustomFieldsLocationId(): ?int
    {
        return static::getLocationId('booking', 'event');
    }
}
```

### Step 3: Add Custom Fields (Optional)

Use the phpGroupWare admin interface or direct database insertion to add custom fields to the `phpgw_cust_attribute` table.

## Usage Examples

### Automatic Integration

Once configured, custom fields work automatically:

```php
// Create event - custom fields are included
$event = new Event([
    'name' => 'Conference',
    'from_' => '2025-07-01 09:00:00',
    'to_' => '2025-07-01 17:00:00',
    'custom_priority' => 'high',     // Custom field
    'custom_category' => 'business'   // Custom field
]);

// Validation includes custom fields
$errors = $event->validate(); // Validates custom fields too

// Save includes custom fields
$event->save(); // Saves custom fields to database

// Sanitization includes custom fields
$rules = Event::getSanitizationRules(); // Includes custom field rules
```

### Manual Field Map Access

```php
// Get complete field map (static + custom)
$allFields = Event::getCompleteFieldMap();

// Get only custom fields
$customFields = Event::getCustomFields();

// Check if field is custom
if (isset($allFields['custom_priority']['custom_field'])) {
    echo "custom_priority is a custom field";
}
```

## Custom Field Configuration

### Field Configuration Structure

Custom fields are converted to BaseModel field configurations:

```php
// Custom field from database
[
    'id' => 123,
    'column_name' => 'event_priority',
    'datatype' => 'R',
    'nullable' => false,
    'size' => 50,
    'default_value' => 'medium',
    'choice' => [
        ['id' => 'low', 'value' => 'Low Priority'],
        ['id' => 'medium', 'value' => 'Medium Priority'],
        ['id' => 'high', 'value' => 'High Priority']
    ]
]

// Becomes BaseModel field config
'event_priority' => [
    'type' => 'string',
    'required' => true,
    'sanitize' => 'string',
    'default' => 'medium',
    'custom_field' => true,
    'custom_field_id' => 123,
    'custom_field_meta' => [...], // Full custom field data
    'validator' => function($value) {
        // Validates against choice options
    }
]
```

### Choice Validation

Radio buttons, checkboxes, and listboxes automatically get choice validation:

- **Radio/Listbox**: Single value must be in choices
- **Checkbox**: Array of values, each must be in choices
- **Validation Error**: "Invalid choice for [field_name]"

## Database Schema

### Required Tables

1. **`phpgw_locations`**: Maps app/location to location_id
2. **`phpgw_cust_attribute`**: Defines custom fields
3. **`phpgw_cust_choice`**: Defines choices for radio/checkbox fields

### Custom Field Storage

Custom fields are stored in the same table as the model (e.g., `phpgw_bb_event` for events). The custom fields system manages the database schema automatically.

## Benefits

1. **Dynamic Fields**: Add fields without code changes
2. **Type Safety**: Proper validation and sanitization
3. **User-Friendly**: Admins can configure fields through UI
4. **Consistent**: Same behavior as static fields
5. **Extensible**: Easy to add new data types
6. **Performance**: Fields are cached and only loaded when needed

## Troubleshooting

### Common Issues

1. **No custom fields loaded**
   - Check `getCustomFieldsLocationId()` returns correct location_id
   - Verify location exists in `phpgw_locations`
   - Check database connectivity

2. **Validation errors**
   - Ensure custom field data types are supported
   - Check choice validation for radio/checkbox fields
   - Verify required field configuration

3. **Sanitization issues**
   - Custom field sanitization uses mapped types
   - Check `mapCustomFieldSanitization()` for edge cases

### Debug Information

Enable debug logging to see custom field loading:

```php
// Check if custom fields are being loaded
$customFields = Event::getCustomFields();
error_log("Loaded " . count($customFields) . " custom fields");

// Check complete field map
$fieldMap = Event::getCompleteFieldMap();
$customCount = 0;
foreach ($fieldMap as $field => $config) {
    if (isset($config['custom_field'])) {
        $customCount++;
    }
}
error_log("Custom fields in field map: " . $customCount);
```

## Migration Guide

### From Manual Custom Fields

If you previously handled custom fields manually:

1. Remove manual field definitions from `getFieldMap()`
2. Implement `getCustomFieldsLocationId()`
3. Update any code that directly references custom fields
4. Test validation and sanitization

### Backward Compatibility

- Existing models continue to work unchanged
- `getFieldMap()` still returns static fields only
- New `getCompleteFieldMap()` includes custom fields
- All BaseModel internal methods use complete field map

## Performance Considerations

- Custom fields are loaded once per request and cached
- Database query is only made when `getCustomFields()` is called
- Consider implementing location_id caching for high-traffic applications
- Custom field loading can be disabled by returning `null` from `getCustomFieldsLocationId()`
