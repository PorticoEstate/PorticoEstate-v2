# Test Directory Structure

**Last Updated:** 2026-01-20

## Directory Organization

```
tests/booking/
├── README.md                          # Main documentation
├── phpunit.xml                        # PHPUnit configuration
│
├── config/                            # Environment configurations
│   ├── .env.example                   # Example configuration
│   ├── local.env                      # Local development
│   ├── kristiansand.env              # Kristiansand production
│   ├── bergen.env                     # Bergen production
│   └── localhost.env                  # Localhost with port
│
├── scripts/                           # Helper scripts
│   └── run-tests.sh                   # Test runner with env selection
│
├── docs/                              # Documentation & reports
│   └── STRUCTURE.md                   # This file
│
└── legacy/                            # Legacy API tests (?menuaction=xxx)
    └── endpoints/                     # Organized by controller
        └── uibooking/                 # bookingfrontend.uibooking.*
            └── get_freetime/          # Specific method tests
                ├── EndpointTest.php           # General endpoint tests
                ├── ValidationTest.php         # Data validation tests
                ├── SchemaValidationTest.php   # Schema compliance tests
                ├── manual-test.php            # Quick manual test script
                └── schemas/
                    └── response.schema.json   # JSON schema definition
```

## Design Principles

### 1. **Legacy vs Modern API**

Tests are organized into `legacy/` for the old phpgw-style API:
```
?menuaction=bookingfrontend.uibooking.get_freetime
```

Future REST API tests would go in `api/`:
```
tests/booking/api/v1/resources/{id}/freetime/
```

### 2. **Organized by Endpoint**

Each endpoint gets its own directory:
```
legacy/endpoints/uibooking/
├── get_freetime/
├── get_freetime_limit/      # Future
└── ...
```

This keeps related tests together and allows for endpoint-specific schemas.

### 3. **Consistent File Names**

Each endpoint directory follows the same pattern:
- `EndpointTest.php` - General endpoint functionality
- `ValidationTest.php` - Validates against actual data
- `SchemaValidationTest.php` - JSON schema compliance
- `manual-test.php` - Quick standalone test script
- `schemas/response.schema.json` - Formal schema definition

### 4. **Shared Configuration**

All tests share:
- `config/` - Environment configurations
- `scripts/` - Helper scripts (run-tests.sh)
- `phpunit.xml` - PHPUnit settings

## Namespaces

Tests use nested namespaces matching directory structure:

```php
namespace Tests\Booking\Legacy\Endpoints\Uibooking\GetFreetime;

class EndpointTest extends TestCase { ... }
class ValidationTest extends TestCase { ... }
class SchemaValidationTest extends TestCase { ... }
```

This prevents naming conflicts when adding tests for other endpoints.

## Running Tests

### Using Helper Script (Recommended)

```bash
# From anywhere in the project
./tests/booking/scripts/run-tests.sh local           # All tests locally
./tests/booking/scripts/run-tests.sh kristiansand   # Against production
./tests/booking/scripts/run-tests.sh local manual   # Quick manual test
```

### Using PHPUnit Directly

```bash
# Run all booking tests
vendor/bin/phpunit tests/booking/

# Run specific endpoint tests
vendor/bin/phpunit tests/booking/legacy/endpoints/uibooking/get_freetime/

# Run specific test file
vendor/bin/phpunit tests/booking/legacy/endpoints/uibooking/get_freetime/SchemaValidationTest.php

# With environment variables
export $(cat tests/booking/config/kristiansand.env | xargs)
vendor/bin/phpunit tests/booking/legacy/endpoints/uibooking/get_freetime/
```

### Running Manual Test

```bash
# With environment config
export $(cat tests/booking/config/local.env | xargs)
php tests/booking/legacy/endpoints/uibooking/get_freetime/manual-test.php

# Or use the helper script
./tests/booking/scripts/run-tests.sh local manual
```

## Adding New Tests

### For a New Legacy Endpoint Method

1. Create directory structure:
```bash
mkdir -p tests/booking/legacy/endpoints/uibooking/METHOD_NAME/schemas
```

2. Create test files:
```
tests/booking/legacy/endpoints/uibooking/METHOD_NAME/
├── EndpointTest.php
├── ValidationTest.php
├── SchemaValidationTest.php
├── manual-test.php
└── schemas/
    └── response.schema.json
```

3. Use namespace:
```php
namespace Tests\Booking\Legacy\Endpoints\Uibooking\MethodName;
```

4. Update `scripts/run-tests.sh` if needed for custom test types

### For a New Controller

```bash
mkdir -p tests/booking/legacy/endpoints/CONTROLLER_NAME/method_name/schemas
```

Example:
```
legacy/endpoints/uiresource/
└── schedule/
    ├── EndpointTest.php
    └── ...
```

## Benefits of This Structure

✅ **Scalable** - Easy to add new endpoints without cluttering
✅ **Organized** - Related tests grouped together
✅ **Clear separation** - Legacy vs modern API
✅ **Consistent** - Same pattern for all endpoints
✅ **Discoverable** - Directory structure mirrors API structure
✅ **Maintainable** - Easy to find and update specific endpoint tests

## Migration Notes

### What Changed

**Old structure:**
```
tests/booking/
├── FreetimeEndpointTest.php
├── FreetimeValidationTest.php
├── FreetimeSchemaValidationTest.php
└── ...
```

**New structure:**
```
tests/booking/
└── legacy/endpoints/uibooking/get_freetime/
    ├── EndpointTest.php
    ├── ValidationTest.php
    ├── SchemaValidationTest.php
    └── ...
```

### Namespace Changes

- **Old:** `Tests\Booking\FreetimeEndpointTest`
- **New:** `Tests\Booking\Legacy\Endpoints\Uibooking\GetFreetime\EndpointTest`

### Path Changes

- **Autoload:** Updated from `__DIR__ . '/../../vendor/autoload.php'` to `__DIR__ . '/../../../../../../vendor/autoload.php'`
- **Schema:** Updated from `__DIR__ . '/schemas/freetime-response.schema.json'` to `__DIR__ . '/schemas/response.schema.json'`

## Future Expansion

When adding REST API tests:

```
tests/booking/
├── legacy/              # Old phpgw API
│   └── endpoints/
│       └── uibooking/
└── api/                 # Modern REST API
    └── v1/
        └── resources/
            └── {id}/
                └── freetime/
```

This structure supports both API paradigms without conflict.
