<?php

namespace App\models;

use App\models\BaseModel;
use App\Database\Db;
use PDO;

/**
 * Generic Registry Model (Abstract)
 * Handles multiple simple entity types through configuration-driven approach
 * Similar to property_sogeneric_ but modernized with BaseModel architecture
 * 
 * Child classes must implement loadRegistryDefinitions() to provide their specific registry definitions
 */
abstract class GenericRegistry extends BaseModel
{
	protected static array $registryDefinitions = [];

	// Only the ID field is always present - all other fields are dynamically defined per registry type
	public ?int $id = null;

	// Registry type and metadata
	protected string $registryType;
	protected array $registryConfig = [];

	/**
	 * Cache for custom fields to avoid repeated lookups during a single request
	 */
	protected ?array $customFieldsCache = null;

	/**
	 * Static cache for custom fields by registry type to avoid repeated database calls
	 * Key format: "ClassName:registryType"
	 */
	protected static array $staticCustomFieldsCache = [];

	/**
	 * Constructor
	 */
	public function __construct(string $registryType = '', array $data = [])
	{
		$this->registryType = $registryType;
		$this->registryConfig = static::getRegistryConfig($registryType);

		// Clear custom fields cache since we're setting up a new registry type
		$this->clearCustomFieldsCache();

		parent::__construct($data);
	}

	/**
	 * Create a new instance for a specific registry type
	 */
	public static function forType(string $registryType, array $data = []): static
	{
		return new static($registryType, $data);
	}

	/**
	 * Get table name based on registry type
	 * Static method throws exception - use instance methods instead
	 */
	protected static function getTableName(): string
	{
		throw new \Exception("GenericRegistry cannot determine table name statically. Use forType() to create instances with specific registry types, then use instance methods.");
	}

	/**
	 * Get table name for instance
	 */
	protected function getInstanceTableName(): string
	{
		if (!$this->registryConfig)
		{
			throw new \Exception("Registry type not configured: {$this->registryType}");
		}
		return $this->registryConfig['table'];
	}

	/**
	 * Get field map based on registry type - static version returns empty array
	 */
	protected static function getFieldMap(): array
	{
		// For static calls, return empty array - instance calls will use getInstanceFieldMap()
		return [];
	}

	/**
	 * Get field map for instance (public method for controller access)
	 */
	public function getInstanceFieldMap(): array
	{
		if (!$this->registryConfig)
		{
			return [];
		}

		$fieldMap = [];

		// Add ID field
		$idConfig = $this->registryConfig['id'];
		$fieldMap['id'] = [
			'type' => $idConfig['type'] === 'auto' ? 'int' : $idConfig['type'],
			'required' => false,
		];

		// Add configured fields
		foreach ($this->registryConfig['fields'] as $field)
		{
			$fieldMap[$field['name']] = [
				'type' => $this->mapFieldType($field['type']),
				'required' => isset($field['nullable']) && $field['nullable'] === false,
				'default' => $field['default'] ?? null,
			];

			// Add additional field properties
			if (isset($field['maxlength']))
			{
				$fieldMap[$field['name']]['maxLength'] = $field['maxlength'];
			}
			if (isset($field['filter']) && $field['filter'])
			{
				$fieldMap[$field['name']]['query'] = true;
			}
			if (isset($field['values_def']))
			{
				$fieldMap[$field['name']]['values_def'] = $field['values_def'];
			}
			if (isset($field['validator']) && $field['validator'])
			{
				$fieldMap[$field['name']]['validator'] = $field['validator'];
			}
		}

		// Add custom fields if location_id is configured
		$customFields = $this->getInstanceCustomFields();
		foreach ($customFields as $customField)
		{
			$fieldName = $customField['column_name'];

			// Skip if field already exists in static definition
			if (isset($fieldMap[$fieldName]))
			{
				continue;
			}

			// Use BaseModel's method to convert custom field to field config
			$fieldConfig = parent::convertCustomFieldToFieldConfig($customField);
			$fieldMap[$fieldName] = $fieldConfig;
		}

		return $fieldMap;
	}

	/**
	 * Map legacy field types to BaseModel types
	 */
	protected function mapFieldType(string $legacyType): string
	{
		return match ($legacyType)
		{
			'varchar', 'text' => 'string',
			'int' => 'int',
			'decimal', 'float' => 'float',
			'checkbox' => 'bool',
			'date' => 'date',
			'datetime', 'timestamp' => 'datetime',
			'html' => 'html',
			'select' => 'int', // Assuming select fields are stored as integers (foreign keys)
			default => 'string'
		};
	}

	/**
	 * Get registry configuration for a type
	 */
	public static function getRegistryConfig(string $type): array
	{
		if (!isset(static::$registryDefinitions[$type]))
		{
			static::loadRegistryDefinitions();
		}

		return static::$registryDefinitions[$type] ?? [];
	}

	/**
	 * Load all registry definitions
	 * Must be implemented by child classes to provide their specific registry definitions
	 */
	protected static abstract function loadRegistryDefinitions(): void;

	/**
	 * Get list of available registry types
	 */
	public static function getAvailableTypes(): array
	{
		static::loadRegistryDefinitions();
		return array_keys(static::$registryDefinitions);
	}

	/**
	 * Get display name for registry type
	 */
	public static function getTypeName(string $type): string
	{
		$config = static::getRegistryConfig($type);
		return $config['name'] ?? ucfirst(str_replace('_', ' ', $type));
	}

	/**
	 * Static methods that work with registry types
	 */
	public static function findByType(string $type, int $id): ?static
	{
		$instance = static::forType($type);
		return $instance->findInstance($id);
	}

	public static function findWhereByType(string $type, array $conditions = [], array $options = []): array
	{
		$instance = static::forType($type);
		return $instance->findWhereInstance($conditions, $options);
	}

	/**
	 * Instance-level findWhere that uses the registry type's table
	 */
	public function findWhereInstance(array $conditions = [], array $options = []): array
	{
		$db = Db::getInstance();
		$tableName = $this->getInstanceTableName();

		// Build WHERE clause
		$whereParts = [];
		$params = [];

		foreach ($conditions as $key => $value)
		{
			if (is_array($value))
			{
				// Handle array conditions like ['field', 'operator', 'value']
				if (count($value) === 3)
				{
					[$field, $operator, $val] = $value;
					$placeholder = ":{$field}_" . count($params);
					$whereParts[] = "{$field} {$operator} {$placeholder}";
					$params[$placeholder] = $val;
				}
			}
			else
			{
				// Simple equality condition
				$placeholder = ":{$key}_" . count($params);
				$whereParts[] = "{$key} = {$placeholder}";
				$params[$placeholder] = $value;
			}
		}

		// Build query
		$sql = "SELECT * FROM {$tableName}";
		if (!empty($whereParts))
		{
			$sql .= " WHERE " . implode(' AND ', $whereParts);
		}

		// Add ordering
		if (isset($options['order_by']))
		{
			$orderBy = $options['order_by'];
			$direction = $options['direction'] ?? 'ASC';

			// Sanitize direction to prevent SQL injection and corruption
			$direction = strtoupper(trim($direction));
			if (!in_array($direction, ['ASC', 'DESC']))
			{
				$direction = 'ASC';
			}

			// Sanitize order_by field (basic validation)
			$orderBy = preg_replace('/[^a-zA-Z0-9_]/', '', $orderBy);

			$sql .= " ORDER BY {$orderBy} {$direction}";
		}

		// Add limit/offset
		if (isset($options['limit']))
		{
			$sql .= " LIMIT " . (int)$options['limit'];
			if (isset($options['offset']))
			{
				$sql .= " OFFSET " . (int)$options['offset'];
			}
		}

		$stmt = $db->prepare($sql);
		$stmt->execute($params);

		$results = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			$results[] = static::forType($this->registryType, $row);
		}

		return $results;
	}

	/**
	 * Instance-level find that uses the registry type's table
	 */
	public function findInstance(int $id): ?static
	{
		$db = Db::getInstance();
		$tableName = $this->getInstanceTableName();

		$sql = "SELECT * FROM {$tableName} WHERE id = :id";

		try
		{
			$stmt = $db->prepare($sql);
			if ($stmt === false)
			{
				throw new \Exception("Failed to prepare SQL query: {$sql}");
			}

			$result = $stmt->execute([':id' => $id]);
			if ($result === false)
			{
				throw new \Exception("Failed to execute SQL query: {$sql}");
			}

			$data = $stmt->fetch(\PDO::FETCH_ASSOC);

			if (!$data)
			{
				return null;
			}

			// Populate the current instance with the fetched data
			$this->populate($data);
			return $this;
		}
		catch (\Exception $e)
		{
			error_log("Database error in findInstance: " . $e->getMessage() . " SQL: {$sql}");
			throw $e;
		}
	}

	public static function createForType(string $type, array $data = []): static
	{
		return static::forType($type, $data);
	}

	/**
	 * Override find() to prevent static usage without registry type
	 */
	public static function find(int $id): ?static
	{
		throw new \Exception("Use findByType(\$type, \$id) instead of find(\$id) for GenericRegistry");
	}

	/**
	 * Override findWhere() to prevent static usage without registry type
	 */
	public static function findWhere(array $conditions = [], array $options = []): array
	{
		throw new \Exception("Use findWhereByType(\$type, \$conditions, \$options) instead of findWhere() for GenericRegistry");
	}

	/**
	 * Override create() to use instance table name instead of static getTableName()
	 */
	protected function create(): bool
	{
		$tableName = $this->getInstanceTableName();
		$fieldMap = $this->getInstanceFieldMap();
		$data = $this->getDbData();

		// Validate ID field requirements based on registry type
		if (isset($fieldMap['id']))
		{
			$idType = $fieldMap['id']['type'] ?? 'auto';

			if ($idType === 'auto')
			{
				// For auto type, ID must NOT be provided by client
				if (isset($data['id']) && $data['id'] !== null)
				{
					throw new \Exception("ID must not be provided for auto-incrementing fields");
				}
			}
			else if ($idType === 'int' || $idType === 'varchar')
			{
				// For int/varchar type, ID MUST be provided by client
				if (!isset($data['id']) || $data['id'] === null || $data['id'] === '')
				{
					throw new \Exception("ID is required for registry type '{$this->registryType}' with ID type '{$idType}'");
				}
			}
		}

		// For auto type, remove ID for insert (it will be auto-generated)
		if (isset($fieldMap['id']) && ($fieldMap['id']['type'] ?? 'auto') === 'auto')
		{
			unset($data['id']);
		}

		if (empty($data))
		{
			return false;
		}

		$columns = array_keys($data);
		$placeholders = ':' . implode(', :', $columns);

		$sql = "INSERT INTO {$tableName} (" . implode(', ', $columns) . ") VALUES (" . $placeholders . ")";

		// For PostgreSQL, add RETURNING id if id field exists and is auto type
		if (isset($fieldMap['id']) && ($fieldMap['id']['type'] ?? 'auto') === 'auto')
		{
			$sql .= " RETURNING id";
		}

		$stmt = $this->db->prepare($sql);

		// Bind parameters
		foreach ($data as $key => $value)
		{
			$stmt->bindValue(":$key", $value);
		}

		$stmt->execute();

		// Get the ID if available (for auto type)
		if (isset($fieldMap['id']) && ($fieldMap['id']['type'] ?? 'auto') === 'auto')
		{
			$this->id = (int)$stmt->fetchColumn();
		}

		// Save relationships
		$this->saveRelationships();

		return true;
	}

	/**
	 * Override update() to use instance table name instead of static getTableName()
	 */
	protected function update(): bool
	{
		$tableName = $this->getInstanceTableName();
		$data = $this->getDbData();
		$id = $data['id'];
		unset($data['id']);

		if (empty($data))
		{
			return false;
		}

		$setParts = [];
		foreach (array_keys($data) as $column)
		{
			$setParts[] = "$column = :$column";
		}

		$sql = "UPDATE {$tableName} SET " . implode(', ', $setParts) . " WHERE id = :id";

		try
		{
			$stmt = $this->db->prepare($sql);
			if (!$stmt)
			{
				throw new \Exception("Failed to prepare statement: " . implode(', ', $this->db->errorInfo()));
			}

			// Bind parameters with proper types
			foreach ($data as $key => $value)
			{
				if (is_null($value))
				{
					$stmt->bindValue(":$key", null, PDO::PARAM_NULL);
				}
				elseif (is_bool($value))
				{
					$stmt->bindValue(":$key", $value ? 1 : 0, PDO::PARAM_INT);
				}
				elseif (is_int($value))
				{
					$stmt->bindValue(":$key", $value, PDO::PARAM_INT);
				}
				else
				{
					$stmt->bindValue(":$key", (string)$value, PDO::PARAM_STR);
				}
			}

			// Bind ID parameter with proper type
			$stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);

			$result = $stmt->execute();
			if (!$result)
			{
				throw new \Exception("Execute failed: " . implode(', ', $stmt->errorInfo()));
			}

			// Save relationships
			$this->saveRelationships();

			return true;
		}
		catch (\Exception $e)
		{
			error_log("Update failed: " . $e->getMessage() . " SQL: $sql");
			throw $e; // Re-throw to let the calling transaction handler deal with it
		}
	}

	/**
	 * Override delete() to use instance table name instead of static getTableName()
	 */
	public function delete(): bool
	{
		if (!$this->id)
		{
			return false;
		}

		$tableName = $this->getInstanceTableName();

		try
		{
			$this->db->beginTransaction();

			// Delete relationships first
			$this->deleteRelationships();

			// Delete main record
			$sql = "DELETE FROM {$tableName} WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':id' => $this->id]);

			$this->db->commit();
			return true;
		}
		catch (\Exception $e)
		{
			$this->db->rollback();
			error_log("Error deleting GenericRegistry: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Override getDbData() to use instance field map instead of static getCompleteFieldMap()
	 */
	protected function getDbData(): array
	{
		$data = [];
		$fieldMap = $this->getInstanceFieldMap();
		$jsonField = static::getCustomFieldsJsonField();
		$customFieldsData = [];

		foreach ($fieldMap as $field => $meta)
		{
			// Skip fields that are not stored in the main table
			if (isset($meta['relationship']) || isset($meta['virtual']))
			{
				continue;
			}

			if (property_exists($this, $field))
			{
				$value = $this->$field;

				// Marshal the value based on type
				if (isset($meta['type']))
				{
					$value = $this->marshalValue($value, $meta['type']);
				}

				// Handle custom fields JSON storage
				if ($jsonField && isset($meta['custom_field']) && $meta['custom_field'])
				{
					// Store custom field in JSON object instead of individual columns
					$customFieldsData[$field] = $value;
				}
				else
				{
					$data[$field] = $value;
				}
			}
		}

		// Add JSON representation of custom fields if enabled
		if ($jsonField && !empty($customFieldsData))
		{
			$data[$jsonField] = json_encode($customFieldsData);
		}

		return $data;
	}

	/**
	 * Override getCompleteFieldMap to return registry-specific fields including custom fields
	 */
	public static function getCompleteFieldMap(): array
	{
		// Try to get current instance context
		$instance = static::getCurrentInstance();
		if ($instance && $instance->registryConfig)
		{
			return $instance->getInstanceFieldMap();
		}

		// Fallback to base behavior
		return parent::getCompleteFieldMap();
	}

	/**
	 * Override getCustomFieldsJsonField for registry-specific JSON storage
	 */
	protected static function getCustomFieldsJsonField(): ?string
	{
		$instance = static::getCurrentInstance();
		if ($instance && $instance->registryConfig)
		{
			// Check if the registry config specifies JSON storage for custom fields
			return $instance->registryConfig['custom_fields_json_field'] ?? 'json_representation';
		}
		return null;
	}

	/**
	 * Thread-local storage for current instance
	 */
	private static ?GenericRegistry $currentInstance = null;

	/**
	 * Set current instance for static method context (public for testing)
	 */
	public static function setCurrentInstance(?GenericRegistry $instance): void
	{
		static::$currentInstance = $instance;
	}

	/**
	 * Get current instance
	 */
	private static function getCurrentInstance(): ?GenericRegistry
	{
		return static::$currentInstance;
	}

	/**
	 * Override save to set instance context
	 */
	public function save(): bool
	{
		$this->validateRegistryType();

		// Set instance context for static methods
		static::setCurrentInstance($this);

		try
		{
			$result = parent::save();
		}
		finally
		{
			// Clear instance context
			static::setCurrentInstance(null);
		}

		return $result;
	}

	/**
	 * Override validate to set instance context
	 */
	public function validate(): array
	{
		static::setCurrentInstance($this);

		try
		{
			$result = parent::validate();
		}
		finally
		{
			static::setCurrentInstance(null);
		}

		return $result;
	}

	/**
	 * Override BaseModel method to provide registry-specific custom fields location
	 */
	protected static function getCustomFieldsLocationId(): ?int
	{
		$instance = static::getCurrentInstance();
		if (!$instance || !$instance->registryConfig)
		{
			return null;
		}

		$app = $instance->registryConfig['acl_app'] ?? 'booking';
		$location = $instance->registryConfig['acl_location'] ?? '.admin';

		try
		{
			return static::getLocationId($app, $location);
		}
		catch (\Exception $e)
		{
			error_log("Error getting location_id for {$app}.{$location}: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Get ACL information for this registry type
	 */
	public function getAclInfo(): array
	{
		return [
			'app' => $this->registryConfig['acl_app'] ?? 'booking',
			'location' => $this->registryConfig['acl_location'] ?? '.admin',
			'menu_selection' => $this->registryConfig['menu_selection'] ?? null
		];
	}

	/**
	 * Get human-readable name for this registry instance
	 */
	public function getRegistryName(): string
	{
		return $this->registryConfig['name'] ?? 'Unknown Registry';
	}

	/**
	 * Validate that registry type is configured
	 */
	protected function validateRegistryType(): void
	{
		if (!$this->registryType)
		{
			throw new \Exception("Registry type not specified");
		}

		if (!$this->registryConfig)
		{
			throw new \Exception("Registry type not configured: {$this->registryType}");
		}
	}

	/**
	 * Get custom fields for this registry instance (delegates to BaseModel)
	 * Uses both instance-level and static-level caching to avoid repeated database lookups
	 */
	public function getInstanceCustomFields(): array
	{
		// Return cached result if available at instance level
		if ($this->customFieldsCache !== null)
		{
			return $this->customFieldsCache;
		}

		// Check static cache by registry type
		$cacheKey = static::class . ':' . $this->registryType;
		if (isset(static::$staticCustomFieldsCache[$cacheKey]))
		{
			$this->customFieldsCache = static::$staticCustomFieldsCache[$cacheKey];
			return $this->customFieldsCache;
		}

		// Set instance context and delegate to BaseModel method
		static::setCurrentInstance($this);

		try
		{
			$this->customFieldsCache = static::getCustomFields();
			// Also cache at static level for other instances of the same type
			static::$staticCustomFieldsCache[$cacheKey] = $this->customFieldsCache;
			return $this->customFieldsCache;
		}
		finally
		{
			static::setCurrentInstance(null);
		}
	}

	/**
	 * Clear the custom fields cache
	 * Should be called when the registry type or configuration changes
	 */
	protected function clearCustomFieldsCache(bool $clearStaticCache = false): void
	{
		$this->customFieldsCache = null;

		if ($clearStaticCache)
		{
			$cacheKey = static::class . ':' . $this->registryType;
			unset(static::$staticCustomFieldsCache[$cacheKey]);
		}
	}

	/**
	 * Override toArray to return only registry fields defined in configuration
	 * NO hardcoded fields - only fields defined in the per-type registry configuration
	 */
	public function toArray(array $context = [], bool $short = false): ?array
	{
		$data = [];

		// Always include the ID field (defined in registry config)
		$data['id'] = $this->id;

		// Only add fields that are explicitly defined in the registry configuration
		if (!empty($this->registryConfig['fields']))
		{
			foreach ($this->registryConfig['fields'] as $field)
			{
				$fieldName = $field['name'];

				// Try to get the field value - first check if it exists as a property
				if (property_exists($this, $fieldName))
				{
					$value = $this->$fieldName;
					// Include the field even if it's null - the API consumer decides how to handle nulls
					$data[$fieldName] = $value;
				}
				else
				{
					// Check if the property exists but is dynamically set
					if (isset($this->$fieldName))
					{
						$data[$fieldName] = $this->$fieldName;
					}
					else
					{
						// Field is defined in config but not set - include as null
						$data[$fieldName] = null;
					}
				}
			}
		}

		// Add custom fields if available
		try
		{
			$customFields = $this->getInstanceCustomFields();
			foreach ($customFields as $customField)
			{
				$fieldName = $customField['column_name'];

				// Skip if field already exists in static definition
				if (isset($data[$fieldName]))
				{
					continue;
				}

				// Get custom field value
				if (property_exists($this, $fieldName))
				{
					$data[$fieldName] = $this->$fieldName;
				}
				else if (isset($this->$fieldName))
				{
					$data[$fieldName] = $this->$fieldName;
				}
			}
		}
		catch (\Exception $e)
		{
			// Don't break the response if custom fields fail
			error_log("Error loading custom fields in toArray(): " . $e->getMessage());
		}

		return $data;
	}

	/**
	 * Override populate to handle only registry-configured fields
	 * NO hardcoded fields - only fields defined in the per-type registry configuration
	 */
	public function populate(array $data): self
	{
		// Set instance context for field map generation
		static::setCurrentInstance($this);

		try
		{
			// Populate the ID field (always present in registry config)
			if (isset($data['id']))
			{
				$this->id = (int)$data['id'];
			}

			// Only populate fields that are explicitly defined in the registry configuration
			if (!empty($this->registryConfig['fields']))
			{
				foreach ($this->registryConfig['fields'] as $field)
				{
					$fieldName = $field['name'];
					if (isset($data[$fieldName]))
					{
						// Type conversion based on field configuration
						$value = $this->convertFieldValue($data[$fieldName], $field);
						// Create the property dynamically and set its value
						$this->$fieldName = $value;
					}
				}
			}

			// Also call the parent populate method to handle any other BaseModel functionality (like custom fields)
			parent::populate($data);
		}
		finally
		{
			// Clear instance context
			static::setCurrentInstance(null);
		}

		return $this;
	}

	/**
	 * Convert field value based on field configuration
	 */
	protected function convertFieldValue($value, array $fieldConfig)
	{
		if ($value === null)
		{
			return null;
		}

		$type = $fieldConfig['type'];

		return match ($type)
		{
			'int' => (int)$value,
			'float', 'decimal' => (float)$value,
			'checkbox' => (bool)$value,
			'varchar', 'text', 'html', 'select' => (string)$value,
			default => $value
		};
	}

	/**
	 * Force save as new record (create), even if ID is present
	 * This is needed for registry types with int/varchar ID where client provides the ID
	 */
	public function saveAsNew(): bool
	{
		$this->validateRegistryType();

		// Set instance context for static methods
		static::setCurrentInstance($this);

		try
		{
			$this->db->transaction_begin();

			// Always call create(), regardless of ID presence
			$result = $this->create();

			if ($result)
			{
				$this->db->transaction_commit();
			}
			else
			{
				$this->db->transaction_abort();
			}
		}
		catch (\Exception $e)
		{
			$this->db->transaction_abort();
			error_log("Error creating " . static::class . ": " . $e->getMessage());
			$result = false;
		}
		finally
		{
			// Clear instance context
			static::setCurrentInstance(null);
		}

		return $result;
	}
}
