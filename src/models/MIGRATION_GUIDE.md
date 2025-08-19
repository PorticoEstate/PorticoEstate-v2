# Migration Guide: Moving to Global Models

This guide helps you migrate existing modules to use the new global BaseModel and GenericRegistry architecture.

## Overview

The global models architecture provides:
- **BaseModel**: Common CRUD operations and validation
- **GenericRegistry**: Abstract registry system for simple lookup tables

## Migration Steps

### Step 1: Update BaseModel Usage

If your module currently uses the booking-specific BaseModel, update imports:

**Before:**
```php
use App\modules\booking\models\BaseModel;
```

**After:**
```php
use App\models\BaseModel;
```

### Step 2: Create Module-Specific GenericRegistry

If your module needs registry functionality, create your own GenericRegistry extension:

1. Create file: `src/modules/yourmodule/models/YourModuleGenericRegistry.php`

```php
<?php

namespace App\modules\yourmodule\models;

use App\models\GenericRegistry;

class YourModuleGenericRegistry extends GenericRegistry
{
    protected static function loadRegistryDefinitions(): void
    {
        static::$registryDefinitions = [
            // Move your registry definitions here
        ];
    }
}
```

### Step 3: Migrate Registry Definitions

If you have existing registry definitions, move them to your new GenericRegistry class:

**Before (in controller or model):**
```php
$registryTypes = [
    'status' => [
        'table' => 'module_status',
        'fields' => [...]
    ]
];
```

**After (in YourModuleGenericRegistry):**
```php
protected static function loadRegistryDefinitions(): void
{
    static::$registryDefinitions = [
        'status' => [
            'table' => 'module_status',
            'id' => ['name' => 'id', 'type' => 'auto'],
            'fields' => [
                // Your field definitions
            ],
            'name' => 'Status',
            'acl_app' => 'yourmodule',
            'acl_location' => '.admin',
        ]
    ];
}
```

### Step 4: Update Controller References

Update your controllers to use your module-specific GenericRegistry:

**Before:**
```php
use App\modules\booking\models\GenericRegistry;

// In controller methods
$items = GenericRegistry::findWhereByType('status', $conditions);
```

**After:**
```php
use App\modules\yourmodule\models\YourModuleGenericRegistry;

// In controller methods  
$items = YourModuleGenericRegistry::findWhereByType('status', $conditions);
```

### Step 5: Update Static Method Calls

Replace all static method calls:

| Old Method | New Method |
|------------|------------|
| `GenericRegistry::forType()` | `YourModuleGenericRegistry::forType()` |
| `GenericRegistry::getAvailableTypes()` | `YourModuleGenericRegistry::getAvailableTypes()` |
| `GenericRegistry::findByType()` | `YourModuleGenericRegistry::findByType()` |
| `GenericRegistry::createForType()` | `YourModuleGenericRegistry::createForType()` |
| `GenericRegistry::getRegistryConfig()` | `YourModuleGenericRegistry::getRegistryConfig()` |

## Example Migration: Property Module

Let's walk through migrating a hypothetical property module:

### Before Migration

```php
// src/modules/property/controllers/RegistryController.php
namespace App\modules\property\controllers;

use App\modules\booking\models\GenericRegistry; // OLD

class RegistryController 
{
    public function getTypes()
    {
        // Hardcoded registry types
        return ['property_type', 'property_status'];
    }
    
    public function index($type)
    {
        // Manual registry handling
        switch($type) {
            case 'property_type':
                // Custom logic for property types
                break;
            case 'property_status':  
                // Custom logic for property status
                break;
        }
    }
}
```

### After Migration

```php
// 1. Create PropertyGenericRegistry
// src/modules/property/models/PropertyGenericRegistry.php
namespace App\modules\property\models;

use App\models\GenericRegistry;

class PropertyGenericRegistry extends GenericRegistry
{
    protected static function loadRegistryDefinitions(): void
    {
        static::$registryDefinitions = [
            'property_type' => [
                'table' => 'property_type',
                'id' => ['name' => 'id', 'type' => 'auto'],
                'fields' => [
                    [
                        'name' => 'name',
                        'descr' => 'Type Name',
                        'type' => 'varchar',
                        'required' => true,
                        'maxlength' => 255
                    ],
                    [
                        'name' => 'active',
                        'descr' => 'Active',
                        'type' => 'checkbox',
                        'default' => 1
                    ]
                ],
                'name' => 'Property Type',
                'acl_app' => 'property',
                'acl_location' => '.admin',
            ],
            
            'property_status' => [
                'table' => 'property_status',
                'id' => ['name' => 'id', 'type' => 'auto'],
                'fields' => [
                    [
                        'name' => 'name',
                        'descr' => 'Status Name',
                        'type' => 'varchar',
                        'required' => true,
                        'maxlength' => 100
                    ],
                    [
                        'name' => 'color',
                        'descr' => 'Color Code',
                        'type' => 'varchar',
                        'maxlength' => 7
                    ]
                ],
                'name' => 'Property Status',
                'acl_app' => 'property',
                'acl_location' => '.admin',
            ]
        ];
    }
}

// 2. Update Controller
// src/modules/property/controllers/RegistryController.php
namespace App\modules\property\controllers;

use App\modules\property\models\PropertyGenericRegistry; // NEW

class RegistryController 
{
    public function getTypes()
    {
        return PropertyGenericRegistry::getAvailableTypes();
    }
    
    public function index($type)
    {
        $items = PropertyGenericRegistry::findWhereByType($type, $conditions);
        return $items;
    }
    
    public function store($type, $data)
    {
        $item = PropertyGenericRegistry::createForType($type, $data);
        return $item->save();
    }
}
```

## Common Migration Patterns

### Pattern 1: Hardcoded Registry Types
**Before:**
```php
$registryTypes = ['type1', 'type2', 'type3'];
```

**After:**
```php
$registryTypes = YourModuleGenericRegistry::getAvailableTypes();
```

### Pattern 2: Manual Table Switching
**Before:**
```php
switch($type) {
    case 'category':
        $table = 'module_category';
        break;
    case 'status':
        $table = 'module_status';
        break;
}
```

**After:**
```php
$config = YourModuleGenericRegistry::getRegistryConfig($type);
$table = $config['table'];
```

### Pattern 3: Custom CRUD Logic
**Before:**
```php
function createCategory($data) {
    // Custom SQL for categories
}

function createStatus($data) {
    // Custom SQL for status
}
```

**After:**
```php
function create($type, $data) {
    $item = YourModuleGenericRegistry::createForType($type, $data);
    return $item->save();
}
```

## Testing Your Migration

### 1. Syntax Check
```bash
php -l src/modules/yourmodule/models/YourModuleGenericRegistry.php
```

### 2. Class Loading Test
```php
// Test script
require_once 'vendor/autoload.php';

$registry = new YourModuleGenericRegistry();
$types = YourModuleGenericRegistry::getAvailableTypes();
echo "Available types: " . implode(', ', $types);
```

### 3. Registry Operations Test
```php
// Test CRUD operations
$item = YourModuleGenericRegistry::createForType('your_type', [
    'name' => 'Test Item'
]);

if ($item->save()) {
    echo "Success!";
} else {
    print_r($item->validate());
}
```

## Troubleshooting

### Issue: Class Not Found
**Solution:** Check namespace and file location match PSR-4 standards

### Issue: Method Not Found  
**Solution:** Ensure you're calling methods on your module-specific class, not the abstract base

### Issue: Registry Type Not Found
**Solution:** Verify `loadRegistryDefinitions()` is implemented and definitions are correct

### Issue: Database Errors
**Solution:** Check table names and field definitions match your database schema

## Benefits After Migration

1. **Consistency**: All modules use the same patterns
2. **Reusability**: Can use global BaseModel features
3. **Maintainability**: Centralized common functionality
4. **Extensibility**: Easy to add new registry types
5. **Documentation**: Better documented patterns

## Need Help?

- Review the booking module as a complete example
- Check `src/models/README.md` for general documentation  
- Look at `src/models/IMPLEMENTATION_GUIDE.md` for new implementations
- Test incrementally - migrate one registry type at a time
