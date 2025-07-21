# BaseModel Documentation Index

This directory contains comprehensive documentation for the BaseModel system in the booking module. The BaseModel provides a modern, generic foundation for CRUD operations with robust validation, sanitization, relationship management, and custom fields integration.

## 📚 Documentation Overview

### Getting Started
- **[README_BaseModel.md](README_BaseModel.md)** - Complete usage guide and tutorial
  - How to create models using BaseModel
  - Field map configuration
  - Relationship setup
  - Custom fields integration
  - CRUD operations examples

### Feature Summaries
- **[BaseModel_Summary.md](BaseModel_Summary.md)** - Feature overview and benefits
  - Key capabilities implemented
  - Legacy compatibility
  - Code examples
  - Performance benefits

### Custom Fields System
- **[CustomFields_Integration_Guide.md](CustomFields_Integration_Guide.md)** - User guide for custom fields
  - How to enable custom fields
  - Usage examples
  - Configuration options
  - JSON storage setup
  - Troubleshooting

- **[CustomFields_Implementation_Summary.md](CustomFields_Implementation_Summary.md)** - Technical implementation details
  - How custom fields work internally
  - Data type mappings
  - Database integration
  - Performance considerations

- **[GenericRegistry_Documentation.md](GenericRegistry_Documentation.md)** - Generic Registry System guide
  - Configuration-driven multi-entity handling
  - Single controller/model for multiple registry types
  - REST API for simple CRUD operations
  - Migration from legacy sogeneric pattern

### Migration and Refactoring
- **[Event_Refactoring_Summary.md](Event_Refactoring_Summary.md)** - Event model refactoring details
  - What was changed
  - Backward compatibility
  - Testing results

- **[Unmarshal_Investigation_Summary.md](Unmarshal_Investigation_Summary.md)** - Legacy integration research
  - Comparison with db-object's unmarshal
  - Compatibility decisions
  - Migration strategy

### Project Status
- **[Project_Completion_Summary.md](Project_Completion_Summary.md)** - Overall project completion status
  - All completed tasks
  - Benefits achieved
  - Ready for production checklist

## 🎯 Quick Start Guide

### 1. **Creating a New Model**
```php
class MyModel extends BaseModel
{
    public ?int $id = null;
    public string $name;
    
    protected static function getTableName(): string
    {
        return 'my_table';
    }
    
    protected static function getFieldMap(): array
    {
        return [
            'id' => ['type' => 'int', 'required' => false],
            'name' => ['type' => 'string', 'required' => true],
        ];
    }
    
    protected function initializeFieldMap(): void
    {
        $this->fieldMap = static::getFieldMap();
    }
}
```

### 2. **Enabling Custom Fields**
```php
protected static function getCustomFieldsLocationId(): ?int
{
    return static::getLocationId('booking', 'my_model');
}
```

### 3. **Using the Model**
```php
// Create
$model = new MyModel(['name' => 'Test']);
$model->save();

// Read
$model = MyModel::find(1);
$models = MyModel::findWhere(['active' => 1]);

// Update
$model->name = 'Updated';
$model->save();

// Delete
$model->delete();
```

## 🔧 Key Features

### ✅ **Implemented Features**
- **Generic CRUD Operations** - Create, read, update, delete with consistent API
- **Field-based Validation** - Type-safe validation with custom rules
- **Relationship Management** - Many-to-many, one-to-many, belongs-to, has-one
- **Custom Fields Integration** - Automatic phpGroupWare custom fields support
- **JSON Storage** - Optional JSON storage for custom fields
- **Legacy Compatibility** - Works with existing booking_socommon patterns
- **Transaction Support** - Built-in transaction handling
- **Query Building** - Flexible filtering, sorting, and pagination

### 🎯 **Benefits**
- **Reduced Code Duplication** - Common logic centralized in BaseModel
- **Type Safety** - Full PHP 8+ typing prevents runtime errors
- **Maintainability** - Clear separation of concerns
- **Extensibility** - Easy to add new models and field types
- **Performance** - Optimized queries and relationship loading
- **User-Friendly** - Custom fields configurable through admin interface

## 🚀 Migration Guide

### From Legacy Models
1. Extend `BaseModel` instead of `booking_socommon`
2. Define your field map in `getFieldMap()`
3. Implement required abstract methods
4. Optional: Enable custom fields with `getCustomFieldsLocationId()`
5. Remove duplicated CRUD code

### From Manual Custom Fields
1. Remove manual field definitions
2. Implement `getCustomFieldsLocationId()`
3. Optional: Enable JSON storage with `getCustomFieldsJsonField()`
4. Test validation and sanitization

## 📈 Production Ready

The BaseModel system is ready for production use:
- ✅ All syntax validated
- ✅ Comprehensive testing completed
- ✅ Backward compatibility maintained
- ✅ Documentation provided
- ✅ Custom fields integration working
- ✅ Performance optimized

## 🔗 Related Files

### Core Implementation
- `BaseModel.php` - The main BaseModel class
- `Event.php` - Refactored Event model example
- `ResourceExample.php` - Example resource model

### Testing
- Various test files were created during development and have been cleaned up
- The system has been thoroughly tested for compatibility and functionality

## 📞 Support

For questions or issues:
1. Check the relevant documentation file above
2. Look at the Event model implementation as an example
3. Review the troubleshooting sections in the custom fields guide
4. Check the migration guides for common patterns

---

*This documentation was created as part of the Event/Application Management Refactoring project completed in June 2025.*
