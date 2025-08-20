# GenericRegistry Implementation Guide

This guide walks you through creating a new GenericRegistry implementation for your module.

## Step 1: Create Your Registry Class

Create a new file in your module's models directory:
`src/modules/yourmodule/models/YourModuleGenericRegistry.php`

```php
<?php

namespace App\modules\yourmodule\models;

use App\models\GenericRegistry;

/**
 * Your Module Generic Registry Model
 * Provides registry definitions for the [module name] module
 */
class YourModuleGenericRegistry extends GenericRegistry
{
    /**
     * Load module-specific registry definitions
     */
    protected static function loadRegistryDefinitions(): void
    {
        static::$registryDefinitions = [
            // Your registry definitions go here
        ];
    }
}
```

## Step 2: Define Your Registry Types

Add registry type definitions to the `loadRegistryDefinitions()` method:

```php
protected static function loadRegistryDefinitions(): void
{
    static::$registryDefinitions = [
        'category' => [
            'table' => 'yourmodule_category',
            'id' => ['name' => 'id', 'type' => 'auto'],
            'fields' => [
                [
                    'name' => 'name',
                    'descr' => 'Category Name',
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
            'name' => 'Category',
            'acl_app' => 'yourmodule',
            'acl_location' => '.admin',
        ],
        
        'priority' => [
            'table' => 'yourmodule_priority',
            'id' => ['name' => 'id', 'type' => 'auto'],
            'fields' => [
                [
                    'name' => 'name',
                    'descr' => 'Priority Name',
                    'type' => 'varchar',
                    'required' => true,
                    'maxlength' => 100
                ],
                [
                    'name' => 'level',
                    'descr' => 'Priority Level',
                    'type' => 'int',
                    'required' => true,
                    'default' => 1
                ],
                [
                    'name' => 'color',
                    'descr' => 'Color Code',
                    'type' => 'varchar',
                    'maxlength' => 7,
                    'nullable' => true
                ]
            ],
            'name' => 'Priority',
            'acl_app' => 'yourmodule',
            'acl_location' => '.admin',
        ]
    ];
}
```

## Step 3: Create Controller (Optional)

If you need a controller for your registries, create:
`src/modules/yourmodule/controllers/GenericRegistryController.php`

```php
<?php

namespace App\modules\yourmodule\controllers;

use App\modules\yourmodule\models\YourModuleGenericRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class GenericRegistryController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'] ?? '';
        
        if (!$type) {
            throw new HttpBadRequestException($request, 'Registry type is required');
        }
        
        if (!in_array($type, YourModuleGenericRegistry::getAvailableTypes())) {
            throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
        }
        
        // Get query parameters
        $params = $request->getQueryParams();
        $conditions = [];
        
        // Add search logic as needed
        if ($query = $params['query'] ?? '') {
            $conditions[] = ['name', 'LIKE', "%{$query}%"];
        }
        
        $items = YourModuleGenericRegistry::findWhereByType($type, $conditions);
        
        return $response->withJson([
            'success' => true,
            'data' => $items,
            'total' => count($items)
        ]);
    }
    
    public function show(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'];
        $id = (int)$args['id'];
        
        $item = YourModuleGenericRegistry::findByType($type, $id);
        
        if (!$item) {
            throw new HttpNotFoundException($request, "Item not found");
        }
        
        return $response->withJson([
            'success' => true,
            'data' => $item
        ]);
    }
    
    public function store(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'];
        $data = $request->getParsedBody();
        
        $item = YourModuleGenericRegistry::createForType($type, $data);
        
        if ($item->save()) {
            return $response->withJson([
                'success' => true,
                'data' => $item,
                'message' => 'Item created successfully'
            ], 201);
        }
        
        return $response->withJson([
            'success' => false,
            'errors' => $item->validate(),
            'message' => 'Validation failed'
        ], 400);
    }
}
```

## Step 4: Add Routes (Optional)

Create or update your module's routes file to include registry routes:

```php
// In src/modules/yourmodule/routes/Routes.php
$app->group('/api/yourmodule/registry', function (RouteCollectorProxy $group) {
    $group->get('/types', [GenericRegistryController::class, 'types']);
    
    $group->group('/{type}', function (RouteCollectorProxy $typeGroup) {
        $typeGroup->get('', [GenericRegistryController::class, 'index']);
        $typeGroup->post('', [GenericRegistryController::class, 'store']);
        $typeGroup->get('/{id:[0-9]+}', [GenericRegistryController::class, 'show']);
        $typeGroup->put('/{id:[0-9]+}', [GenericRegistryController::class, 'update']);
        $typeGroup->delete('/{id:[0-9]+}', [GenericRegistryController::class, 'delete']);
    });
})
->addMiddleware(new SessionsMiddleware($container));
```

## Step 5: Usage in Your Code

Now you can use your registry throughout your module:

```php
// Get all categories
$categories = YourModuleGenericRegistry::findWhereByType('category', ['active' => 1]);

// Create a new priority
$priority = YourModuleGenericRegistry::createForType('priority', [
    'name' => 'High',
    'level' => 3,
    'color' => '#ff0000'
]);
$priority->save();

// Get available registry types
$types = YourModuleGenericRegistry::getAvailableTypes();

// Get registry configuration
$config = YourModuleGenericRegistry::getRegistryConfig('category');
```

## Field Type Reference

### Common Field Types

| Type | Description | Example |
|------|-------------|---------|
| `varchar` | Short text (becomes `string`) | Names, titles |
| `text` | Long text (becomes `string`) | Descriptions, notes |
| `int` | Integer number | IDs, counts, levels |
| `decimal`/`float` | Decimal number | Prices, percentages |
| `checkbox` | Boolean (becomes `bool`) | Active/inactive flags |
| `date` | Date only | Birth dates, deadlines |
| `datetime` | Date and time | Created/updated timestamps |
| `select` | Dropdown selection | Categories, statuses |
| `html` | HTML content | Rich text descriptions |

### Field Properties

| Property | Type | Description | Example |
|----------|------|-------------|---------|
| `name` | string | Database column name | `'category_id'` |
| `descr` | string | Human-readable description | `'Category'` |
| `type` | string | Field type | `'varchar'` |
| `required` | bool | Is field required? | `true` |
| `nullable` | bool | Can field be null? | `false` |
| `maxlength` | int | Maximum length | `255` |
| `default` | mixed | Default value | `1` |
| `filter` | bool | Can be used in filters? | `true` |
| `values_def` | array | For select fields | See below |

### Select Field Values Definition

For select fields, you can define the available options:

```php
'values_def' => [
    'method' => 'yourmodule.class.getOptions',
    'method_input' => ['param' => 'value']
]
```

Or for static options:

```php
'values_def' => [
    'values' => [
        1 => 'Option 1',
        2 => 'Option 2',
        3 => 'Option 3'
    ]
]
```

## ACL Configuration

Always configure proper ACL settings:

- `acl_app`: Your module name
- `acl_location`: Permission location (usually `.admin` for admin-only or `.user` for user access)
- `menu_selection`: Optional menu path for navigation

## Testing Your Implementation

1. Verify class autoloading works
2. Test that your registry types are returned by `getAvailableTypes()`
3. Test creating instances with `forType()`
4. Test CRUD operations
5. Verify ACL permissions work correctly

## Common Pitfalls

1. **Forgetting to implement `loadRegistryDefinitions()`** - This is required for abstract class
2. **Incorrect table names** - Make sure your database tables exist
3. **Missing ACL configuration** - Always specify `acl_app` and `acl_location`
4. **Wrong field types** - Use the correct field type mapping
5. **Namespace issues** - Make sure your namespace matches your file location

## Getting Help

- Check the booking module implementation for a complete example
- Look at `src/models/README.md` for general documentation
- Review the global `GenericRegistry` class for available methods
- Test your implementation with simple PHP scripts before integration
