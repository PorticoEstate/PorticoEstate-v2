# Global Models Documentation Index

Welcome to the global models documentation. This directory contains the documentation for the global model architecture that provides reusable foundation classes for all modules.

## 📁 Documentation Files

### 📖 [README.md](README.md)
**Overview and basic usage guide**
- Introduction to BaseModel and GenericRegistry
- Basic usage examples
- Architecture explanation
- Field type mapping reference

### 🚀 [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)  
**Step-by-step guide for creating new implementations**
- How to create your own GenericRegistry extension
- Complete code examples
- Controller integration
- Routes setup
- Testing your implementation

### 🔄 [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)
**Guide for migrating existing modules**
- How to migrate from booking-specific models to global models
- Before/after examples
- Common migration patterns
- Troubleshooting tips

### 📚 [API_REFERENCE.md](API_REFERENCE.md)
**Complete API reference**
- All available methods and their signatures
- Parameter descriptions
- Return values
- Usage examples
- Registry definition structure

## 🏗️ Architecture Overview

```
src/models/
├── BaseModel.php          # Global base model for CRUD operations
├── GenericRegistry.php    # Abstract registry system
└── docs/                  # This documentation directory
    ├── README.md
    ├── IMPLEMENTATION_GUIDE.md
    ├── MIGRATION_GUIDE.md
    └── API_REFERENCE.md
```

## 🎯 Quick Start

### For New Implementations
1. Read [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)
2. Create your module-specific GenericRegistry class
3. Define your registry types
4. Test your implementation

### For Existing Modules
1. Read [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)
2. Update BaseModel imports
3. Create module-specific GenericRegistry
4. Update controller references
5. Test migration

### For API Reference
1. Check [API_REFERENCE.md](API_REFERENCE.md) for method signatures
2. Look at registry definition structure
3. Review field type mapping

## 🔍 Example Implementation

The booking module provides a complete example of the new architecture:

- **Registry Class**: `src/modules/booking/models/BookingGenericRegistry.php`
- **Controller**: `src/modules/booking/controllers/GenericRegistryController.php`  
- **Routes**: `src/routes/generic_registry.php`

Registry types supported:
- `office` - Office management
- `office_user` - Office user assignments  
- `article_category` - Article categories
- `article_service` - Service definitions
- `vendor` - Vendor management
- `document_vendor` - Document vendors
- `permission_root` - Permission subjects
- `permission_role` - Permission roles
- `e_lock_system` - Electronic lock systems
- `multi_domain` - Multi-domain configurations

## 🧪 Testing Your Implementation

### Basic Test
```php
// Test class loading
$registry = YourModuleGenericRegistry::forType('your_type');

// Test registry types
$types = YourModuleGenericRegistry::getAvailableTypes();

// Test configuration
$config = YourModuleGenericRegistry::getRegistryConfig('your_type');
```

### Complete Test
```php
// Create, save, and retrieve
$item = YourModuleGenericRegistry::createForType('your_type', [
    'name' => 'Test Item'
]);

if ($item->save()) {
    $retrieved = YourModuleGenericRegistry::findByType('your_type', $item->id);
    echo "Success: " . $retrieved->name;
}
```

## 🔧 Key Benefits

1. **Reusability** - Use BaseModel and GenericRegistry across all modules
2. **Consistency** - Same patterns and APIs everywhere
3. **Maintainability** - Centralized common functionality  
4. **Extensibility** - Easy to add new registry types
5. **Documentation** - Well-documented architecture and usage

## 📞 Getting Help

1. **Start with the guides** - Check README.md for overview
2. **Follow examples** - Look at booking module implementation
3. **Test incrementally** - Implement one registry type at a time
4. **Check API reference** - Use API_REFERENCE.md for method details
5. **Migration issues** - Follow MIGRATION_GUIDE.md troubleshooting section

## 🎉 Success Stories

The global models architecture has been successfully implemented for:

- ✅ **Booking Module** - Complete migration with 10 registry types
- ✅ **Global Routes** - Centralized registry API endpoints
- ✅ **Middleware Integration** - Proper ACL and session handling
- ✅ **Documentation** - Comprehensive guides and references

Ready to implement your own? Start with the [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)!
