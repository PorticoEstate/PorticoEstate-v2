# PropertyGenericRegistry Documentation

## Overview

The **PropertyGenericRegistry** extends the global GenericRegistry architecture to provide property-specific registry definitions for managing various lookup tables in the property module.

## Features

### ✅ Implemented Registry Types

The PropertyGenericRegistry includes **30 comprehensive registry types** based on the existing `property_sogeneric` definitions:

#### **Location & Geography**
- `part_of_town` - Parts of town with delivery addresses and district references
- `district` - Geographic districts  
- `street` - Street addresses

#### **Accounting & Economics**
- `dimb` - Economic Dimension B (with org unit references)
- `dimd` - Economic Dimension D
- `periodization` - Accounting periodization settings
- `tax` - Tax rates and descriptions
- `voucher_cat` - Voucher categories
- `voucher_type` - Voucher types
- `b_account` - Budget accounts with categories
- `b_account_category` - Budget account categories
- `dimb_role` - Economic dimension B roles

#### **Entities & Relationships**
- `owner` - Property owners with categories and contact info
- `owner_cats` - Owner categories
- `tenant` - Property tenants with categories and contact info  
- `tenant_cats` - Tenant categories
- `vendor` - Vendors with categories and contact info
- `vendor_cats` - Vendor categories

#### **Service Management**
- `tender_chapter` - Tender chapters
- `s_agreement` - Service agreements
- `tenant_claim` - Tenant claims
- `wo_hours` - Work order hours

#### **Request & Risk Management**
- `r_condition_type` - Request condition types
- `r_probability` - Request probability levels
- `r_consequence` - Request consequence levels
- `authorities_demands` - Authority demands with location levels

#### **System Administration**
- `condition_survey_status` - Condition survey status values
- `request_responsible_unit` - Responsible units for requests
- `ticket_priority` - Ticket priority levels with rankings
- `external_com_type` - External communication types

## API Routes

All property registry routes are available under `/property/registry`:

```
GET    /property/registry/types              -> List all available registry types
GET    /property/registry/{type}/            -> List items for a registry type
GET    /property/registry/{type}/schema      -> Get field schema for a registry type
GET    /property/registry/{type}/list        -> Get dropdown/select list for a registry type
GET    /property/registry/{type}/{id}        -> Get specific item by ID (ID must be numeric)
POST   /property/registry/{type}/            -> Create new item
PUT    /property/registry/{type}/{id}        -> Update existing item (ID must be numeric)
DELETE /property/registry/{type}/{id}        -> Delete item (ID must be numeric)
```

### Example API Calls

```bash
# List all property registry types
curl -X GET /property/registry/types

# Get all districts (full data)
curl -X GET /property/registry/district/

# Get district dropdown list (simplified for selects)
curl -X GET /property/registry/district/list

# Get schema for vendor registry
curl -X GET /property/registry/vendor/schema

# Get specific vendor by ID (ID validated as numeric)
curl -X GET /property/registry/vendor/123

# Create new district
curl -X POST /property/registry/district/ \
  -H "Content-Type: application/json" \
  -d '{"name": "Downtown", "descr": "Downtown district"}'

# Update district (ID validated as numeric)
curl -X PUT /property/registry/district/5 \
  -H "Content-Type: application/json" \
  -d '{"name": "Downtown Updated", "descr": "Updated description"}'
```

### New Features

**Enhanced Route Structure:**
- **Explicit Registry Binding**: Controller is instantiated with PropertyGenericRegistry class
- **Input Validation**: ID parameters are validated as numeric using regex patterns
- **Nested Route Groups**: Better organization with shared controller instance
- **Additional Endpoints**: Added `/list` endpoint for dropdown/select use cases

**Dropdown/Select Support:**
```bash
# Get simplified list for dropdowns (typically just id/name pairs)
curl -X GET /property/registry/vendor/list
curl -X GET /property/registry/district/list
```

## Usage Examples

### PHP Usage

```php
use App\modules\property\models\PropertyGenericRegistry;

// Create instance for a specific registry type
$districtRegistry = PropertyGenericRegistry::forType('district');

// Get all districts
$districts = $districtRegistry->findWhere();

// Create new district
$newDistrict = PropertyGenericRegistry::createForType('district', [
    'name' => 'New District',
    'descr' => 'A new district'
]);
$newDistrict->save();

// Get field map for form generation
$fieldMap = $districtRegistry->getInstanceFieldMap();

// Get ACL information
$aclInfo = $districtRegistry->getAclInfo();
```

### Available Registry Types

```php
// Get all available types
$types = PropertyGenericRegistry::getAvailableTypes();
// Returns: ['part_of_town', 'district', 'street', 'dimb', ...]

// Get display name for a type
$name = PropertyGenericRegistry::getTypeName('part_of_town');
// Returns: "Part of Town"

// Get configuration for a type
$config = PropertyGenericRegistry::getRegistryConfig('vendor');
// Returns array with table, fields, ACL info, etc.
```

## Custom Fields Support

✅ **Full custom fields integration** is supported for all registry types:

- Custom fields are automatically loaded based on ACL location
- Each registry type has its own ACL app (`property`) and location
- Field maps include both static and custom fields
- Validation works for both types of fields

Example ACL locations:
- `vendor`: `property.vendor`
- `tenant`: `property.tenant` 
- `owner`: `property.owner`
- Most admin types: `property.admin`

## Field Types & Validation

The PropertyGenericRegistry supports comprehensive field types:

- **varchar** - Text input with optional max length
- **text** - Multi-line text areas
- **int** - Integer values
- **float** - Decimal numbers
- **checkbox** - Boolean values with value sets
- **select** - Dropdown selections with value definitions
- **date** - Date inputs
- **datetime** - Date and time inputs

### Advanced Features

- **Filter Support** - Fields marked with `filter: true` can be used in API queries
- **Sortable Fields** - Fields can be sorted in API responses
- **Required Validation** - Non-nullable fields are automatically validated
- **Value Definitions** - Select fields can reference other registries or static value sets
- **Relational Fields** - Support for cross-references between registry types

## Migration from Legacy

The PropertyGenericRegistry is designed to be **fully compatible** with the existing `property_sogeneric` system:

1. **Same table names** - Uses identical database tables
2. **Same field definitions** - Preserves all existing field configurations
3. **Same ACL integration** - Maintains access control locations
4. **Enhanced API** - Adds modern REST API on top of existing data

### Migration Steps

1. **No data migration needed** - Works with existing tables
2. **Update API calls** - Replace legacy SOAP/AJAX with REST endpoints
3. **Update forms** - Use new field schema API for dynamic form generation
4. **Update permissions** - Existing ACL permissions are preserved

## Testing

Run the test suite to verify functionality:

```bash
# Test PropertyGenericRegistry implementation
php test_property_registry.php

# Test route integration
php test_property_routes.php
```

Both tests are designed to run inside the Docker container for full database integration.

## Architecture Integration

The PropertyGenericRegistry integrates seamlessly with:

- **Global GenericRegistry** - Extends the abstract base class
- **Global GenericRegistryController** - Uses the module-agnostic controller
- **Custom Fields System** - Automatic integration with phpGroupWare custom fields
- **ACL System** - Preserves existing access control
- **BaseModel Architecture** - Full validation, field mapping, and ORM features

This provides a modern, maintainable, and extensible registry system for the property module while preserving all existing functionality and data.
