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
Marks a property for inclusion in serialization output. Can include conditions for when the property should be exposed.

```php
/**
 * @Expose
 */
public $basicProperty;

/**
 * @Expose(groups={"admin", "api"})
 */
public $restrictedProperty;

/**
 * @Expose(when={
 *   "is_public=1",
 *   "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
 *   "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
 * })
 */
public $conditionalProperty;
```

### @Default
Specifies a default value to use when a property is not exposed based on conditions, or when the value is null.

```php
/**
 * @Expose(when={"is_public=1"})
 * @Default("PRIVATE EVENT")
 */
public $name;
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
 * @SerializeAs(type="array", of="App\Models\Comment", short=true)
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

### @Timestamp
Controls the formatting of timestamp values.

```php
/**
 * @Expose
 * @Timestamp(format="Y-m-d H:i:s")
 */
public $created_at;
```

If no format is specified, defaults to ISO 8601 format ("c").

## Conditional Exposure

The @Expose decorator supports complex conditions to control when properties are exposed:

### Simple Conditions
```php
/**
 * @Expose(when={"is_public=1"})
 */
public $title;
```

### Context Variables
```php
/**
 * @Expose(when={"owner_id=$user_id"})
 */
public $privateData;
```

### Multiple Conditions (OR)
```php
/**
 * @Expose(when={
 *   "is_public=1",
 *   "owner_id=$user_id"
 * })
 */
public $content;
```

### Combined Conditions (AND)
```php
/**
 * @Expose(when={"type=personal&&owner_id=$user_id"})
 */
public $personalInfo;
```

### Array Context Values
```php
/**
 * @Expose(when={"organization_id=$allowed_organizations"})
 */
public $organizationData;
```

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

## Timestamp Handling

### Basic Usage
```php
/**
 * @Expose
 * @Timestamp
 */
public $created_at; // Will format as ISO 8601
```

### Custom Format
```php
/**
 * @Expose
 * @Timestamp(format="Y-m-d")
 */
public $date; // Will format as YYYY-MM-DD
```

### Input Handling
The serializer can handle various timestamp input formats:
- Unix timestamps (numeric values)
- Date strings
- 'now' keyword
- DateTime objects

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
$data = $object->serialize(['roles' => ['admin']]); // Include admin-only properties
```

## Complex Examples

### Event Model
```php
class Event {
    use SerializableTrait;

    /**
     * @Expose(when={
     *   "is_public=1",
     *   "customer_identifier_type=ssn&&customer_ssn=$user_ssn",
     *   "customer_identifier_type=organization_number&&customer_organization_number=$organization_number"
     * })
     * @Default("PRIVATE EVENT")
     * @EscapeString(mode="default")
     */
    public $name;

    /**
     * @Expose(when={"is_public=1"})
     * @Default("")
     */
    public $description;

    /**
     * @Expose
     * @Timestamp(format="c")
     */
    public $created_at;

    /**
     * @Expose
     */
    public $customer_identifier_type;
}
```

### Order Model
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
- Take advantage of the annotation cache

## Error Handling

The serializer handles several edge cases:
- Null values
- Missing properties in nested objects
- Invalid annotations
- Circular references (through careful object instantiation)
- Invalid conditions in @Expose(when)
- Missing context values

## Integration Examples

### API Response
```php
public function getUser(Request $request, Response $response): Response
{
    $user = new User();
    $context = [
        'user_id' => $request->getAttribute('user_id'),
        'roles' => $request->getAttribute('roles')
    ];
    return $response->withJson($user->serialize($context));
}
```

### Frontend Data with Conditions
```php
public function getEventData(string $userSsn, array $organizationNumbers): array
{
    $event = new Event();
    return $event->serialize([
        'user_ssn' => $userSsn,
        'organization_number' => $organizationNumbers,
        'roles' => ['user']
    ]);
}
```

## Best Practices

1. **Always Mark Properties**: Explicitly mark properties with `@Expose` or `@Exclude`

2. **Use Conditions Carefully**: Keep conditions simple and readable

3. **Provide Defaults**: Use `@Default` for conditional properties that should have a fallback value

4. **Document Context**: Document required context values for conditional exposure

5. **String Handling**: Use appropriate `@EscapeString` modes for different content types

6. **Security**: Use both groups and conditions for sensitive data

7. **Timestamps**: Use `@Timestamp` with appropriate formats for date/time fields

8. **Documentation**: Include example output in property documentation

## Common Issues and Solutions

### Missing Properties
Problem: Properties not appearing in output
Solutions:
- Check `@Expose` decorator and conditions
- Verify context values are being passed
- Check for typos in condition field names

### Default Values Not Working
Problem: Default values not showing up
Solutions:
- Ensure `@Default` annotation is properly formatted
- Verify conditions are evaluating as expected
- Check that the property isn't being excluded by other means

### Timestamp Formatting Issues
Problem: Dates not formatted correctly
Solutions:
- Check the format string syntax
- Ensure input data is in a recognized format
- Use appropriate format for your use case

### Circular References
Problem: Infinite recursion in nested objects
Solution: Use `@SerializeAs` with careful object structure and consider using `short=true` for nested objects