# PHP Object Serializer Documentation

## Overview

The SerializableTrait provides a flexible and powerful way to control how objects are serialized to arrays in PHP. It uses a decorator (annotation) based approach to configure serialization behavior at the property level.

## Basic Usage

```php
use App\traits\SerializableTrait;

class User {
    use SerializableTrait;

    /**
     * @Expose
     */
    public $username;

    /**
     * @Expose
     * @Short
     */
    public $id;

    /**
     * @Exclude
     */
    private $password;
}

$user = new User();
$serialized = $user->serialize(); // Converts to array
$shortVersion = $user->serialize([], true); // Short version with only @Short properties
```

## Core Decorators

### @Expose
Marks a property for inclusion in serialization output.

```php
/**
 * @Expose
 */
public $propertyName;

/**
 * @Expose(groups={"admin", "api"})
 */
public $restrictedProperty;
```

### @Exclude
Explicitly excludes a property from serialization.

```php
/**
 * @Exclude
 */
private $internalProperty;
```

### @Short
Marks properties for inclusion in shortened serialization output.

```php
/**
 * @Expose
 * @Short
 */
public $id;
```

### @SerializeAs
Defines how complex properties should be serialized.

```php
/**
 * @Expose
 * @SerializeAs(type="array", of="App\Models\Comment")
 */
public $comments;

/**
 * @Expose
 * @SerializeAs(type="object", of="App\Models\Address")
 */
public $address;
```

### @EscapeString
Controls string escaping behavior for text properties.

```php
/**
 * @Expose
 * @EscapeString(mode="default")
 */
public $name;
```

Available modes:
- `default`: Full sanitization of HTML entities and special characters
- `html`: Only decode HTML entities
- `preserve_newlines`: Decode entities while preserving newline characters
- `encode`: Encode special characters for HTML output
- `none`: No escaping performed

## String Handling

### Default Sanitization
By default, string properties are sanitized to handle:
- HTML entities
- Numeric character references
- Double-encoded entities
- UTF-8 encoding

Example:
```php
"Fl&oslash;yen friluftsomr&aring;de &#40;Tubakuba&#41;"
→ "Fløyen friluftsområde (Tubakuba)"
```

### Custom String Handling
```php
/**
 * @Expose
 * @EscapeString(mode="preserve_newlines")
 */
public $description;
```

## Nested Object Serialization

### Simple Objects
```php
/**
 * @Expose
 * @SerializeAs(type="object", of="App\Models\Address")
 */
public $address;
```

### Collections
```php
/**
 * @Expose
 * @SerializeAs(type="array", of="App\Models\Comment", short=true)
 */
public $comments;
```

## Role-Based Serialization

You can control access to properties based on user roles:

```php
/**
 * @Expose(groups={"admin"})
 */
public $sensitiveData;

// During serialization:
$data = $object->serialize(['admin']); // Include admin-only properties
```

## Configuration Options

### Global Configuration
```php
class YourModel {
    use SerializableTrait;

    // Disable string sanitization for all properties
    protected $sanitizeStrings = false;
}
```

### Property-Level Configuration
```php
/**
 * @Expose
 * @Short
 * @EscapeString(mode="html")
 * @SerializeAs(type="object", of="App\Models\SubModel")
 */
public $property;
```

## Best Practices

1. **Always Mark Properties**: Explicitly mark properties with `@Expose` or `@Exclude`

2. **Use Short Wisely**: Mark only essential properties with `@Short`

3. **String Handling**: Use appropriate `@EscapeString` modes for different content types

4. **Security**: Use `groups` for sensitive data

5. **Documentation**: Include example output in property documentation

## Examples

### Basic Model
```php
class Article {
    use SerializableTrait;

    /**
     * @Expose
     * @Short
     */
    public $id;

    /**
     * @Expose
     * @EscapeString(mode="default")
     */
    public $title;

    /**
     * @Expose
     * @EscapeString(mode="preserve_newlines")
     */
    public $content;

    /**
     * @Expose
     * @SerializeAs(type="array", of="App\Models\Comment")
     */
    public $comments;
}
```

### Complex Model
```php
class Order {
    use SerializableTrait;

    /**
     * @Expose
     * @Short
     */
    public $orderId;

    /**
     * @Expose(groups={"admin"})
     * @SerializeAs(type="object", of="App\Models\Customer")
     */
    public $customer;

    /**
     * @Expose
     * @SerializeAs(type="array", of="App\Models\OrderItem")
     */
    public $items;

    /**
     * @Exclude
     */
    private $internalNotes;
}
```

## Performance Considerations

The serializer uses reflection and annotation parsing, which are cached to improve performance:

```php
private static $annotationCache = [];
```

For best performance:
- Use `@Short` when possible to minimize data transfer
- Consider caching serialized output for static data
- Use appropriate string escaping modes

## Error Handling

The serializer handles several edge cases:
- Null values
- Missing properties in nested objects
- Invalid annotations
- Circular references (through careful object instantiation)

## Extending the Serializer

You can extend the functionality by:
1. Adding new decorators
2. Creating custom string handling modes
3. Implementing custom type handlers

Example of adding a custom decorator:
```php
private function parseCustomAnnotation(\ReflectionProperty $property): ?array
{
    // Implementation
}
```

## Common Issues and Solutions

### Double Encoding
Problem: HTML entities appear encoded multiple times
Solution: Use `@EscapeString(mode="html")`

### Missing Data
Problem: Properties not appearing in output
Solution: Check `@Expose` decorator and groups configuration

### Circular References
Problem: Infinite recursion in nested objects
Solution: Use `@SerializeAs` with careful object structure

## Integration Examples

### API Response
```php
public function getUser(Request $request, Response $response): Response
{
    $user = new User();
    return $response->withJson($user->serialize(['api']));
}
```

### Frontend Data
```php
public function getUserData(): array
{
    $user = new User();
    return $user->serialize([], true); // Short version
}
```