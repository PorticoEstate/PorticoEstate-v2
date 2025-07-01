<?php

namespace App\models;

use App\models\BaseModel;
use App\Database\Db;

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
	 * Constructor
	 */
	public function __construct(string $registryType = '', array $data = [])
	{
		$this->registryType = $registryType;
		$this->registryConfig = static::getRegistryConfig($registryType);

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
				'required' => !isset($field['nullable']) || !$field['nullable'],
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
			'select' => 'string',
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
		$data = $this->getDbData();
		
		// Remove ID for insert
		unset($data['id']);

		if (empty($data)) {
			return false;
		}

		$columns = array_keys($data);
		$placeholders = ':' . implode(', :', $columns);

		$sql = "INSERT INTO {$tableName} (" . implode(', ', $columns) . ") VALUES (" . $placeholders . ")";
		
		// For PostgreSQL, add RETURNING id if id field exists
		$fieldMap = $this->getInstanceFieldMap();
		if (isset($fieldMap['id'])) {
			$sql .= " RETURNING id";
		}

		$stmt = $this->db->prepare($sql);

		// Bind parameters
		foreach ($data as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}

		$stmt->execute();

		// Get the ID if available
		if (isset($fieldMap['id'])) {
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

		if (empty($data)) {
			return false;
		}

		$setParts = [];
		foreach (array_keys($data) as $column) {
			$setParts[] = "$column = :$column";
		}

		$sql = "UPDATE {$tableName} SET " . implode(', ', $setParts) . " WHERE id = :id";
		$stmt = $this->db->prepare($sql);

		// Bind parameters
		foreach ($data as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}
		$stmt->bindValue(':id', $id);

		$stmt->execute();

		// Save relationships
		$this->saveRelationships();

		return true;
	}

	/**
	 * Override delete() to use instance table name instead of static getTableName()
	 */
	public function delete(): bool
	{
		if (!$this->id) {
			return false;
		}

		$tableName = $this->getInstanceTableName();

		try {
			$this->db->beginTransaction();

			// Delete relationships first
			$this->deleteRelationships();

			// Delete main record
			$sql = "DELETE FROM {$tableName} WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':id' => $this->id]);

			$this->db->commit();
			return true;
		} catch (\Exception $e) {
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

		foreach ($fieldMap as $field => $meta) {
			// Skip fields that are not stored in the main table
			if (isset($meta['relationship']) || isset($meta['virtual'])) {
				continue;
			}

			if (property_exists($this, $field)) {
				$value = $this->$field;
				
				// Marshal the value based on type
				if (isset($meta['type'])) {
					$value = $this->marshalValue($value, $meta['type']);
				}
				
				// Handle custom fields JSON storage
				if ($jsonField && isset($meta['custom_field']) && $meta['custom_field']) {
					// Store custom field in JSON object instead of individual columns
					$customFieldsData[$field] = $value;
				} else {
					$data[$field] = $value;
				}
			}
		}

		// Add JSON representation of custom fields if enabled
		if ($jsonField && !empty($customFieldsData)) {
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
	 * Custom fields support - override BaseModel method to work with instance context
	 */
	protected static function getCustomFieldsLocationId(): ?int
	{
		$instance = static::getCurrentInstance();
		if ($instance)
		{
			return $instance->getInstanceCustomFieldsLocationId();
		}
		return null;
	}

	/**
	 * Instance method for custom fields (public for testing)
	 */
	public function getInstanceCustomFieldsLocationId(): ?int
	{
		if (!$this->registryConfig)
		{
			return null;
		}

		$app = $this->registryConfig['acl_app'] ?? 'booking';
		$location = $this->registryConfig['acl_location'] ?? '.admin';

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
	 * Get custom fields for this registry instance (public for testing)
	 */
	public function getInstanceCustomFields(): array
	{
		$locationId = $this->getInstanceCustomFieldsLocationId();
		if ($locationId === null)
		{
			return [];
		}

		try
		{
			// Create instance of phpgwapi_custom_fields
			$customFields = new \phpgwapi_custom_fields();

			// Get custom fields for this location
			$fields = $customFields->find2(
				$locationId,
				0,        // start
				'',       // query 
				'ASC',    // sort
				'attrib_sort', // order
				true,     // allrows
				true      // inc_choices
			);

			return $fields ?: [];
		}
		catch (\Exception $e)
		{
			error_log("Error loading custom fields for registry type {$this->registryType}, location_id {$locationId}: " . $e->getMessage());
			return [];
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
		if (!empty($this->registryConfig['fields'])) {
			foreach ($this->registryConfig['fields'] as $field) {
				$fieldName = $field['name'];
				
				// Try to get the field value - first check if it exists as a property
				if (property_exists($this, $fieldName)) {
					$value = $this->$fieldName;
					// Include the field even if it's null - the API consumer decides how to handle nulls
					$data[$fieldName] = $value;
				} else {
					// Check if the property exists but is dynamically set
					if (isset($this->$fieldName)) {
						$data[$fieldName] = $this->$fieldName;
					} else {
						// Field is defined in config but not set - include as null
						$data[$fieldName] = null;
					}
				}
			}
		}

		// Add custom fields if available
		try {
			$customFields = $this->getInstanceCustomFields();
			foreach ($customFields as $customField) {
				$fieldName = $customField['column_name'];
				
				// Skip if field already exists in static definition
				if (isset($data[$fieldName])) {
					continue;
				}
				
				// Get custom field value
				if (property_exists($this, $fieldName)) {
					$data[$fieldName] = $this->$fieldName;
				} else if (isset($this->$fieldName)) {
					$data[$fieldName] = $this->$fieldName;
				}
			}
		} catch (\Exception $e) {
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

		try {
			// Populate the ID field (always present in registry config)
			if (isset($data['id'])) {
				$this->id = (int)$data['id'];
			}

			// Only populate fields that are explicitly defined in the registry configuration
			if (!empty($this->registryConfig['fields'])) {
				foreach ($this->registryConfig['fields'] as $field) {
					$fieldName = $field['name'];
					if (isset($data[$fieldName])) {
						// Type conversion based on field configuration
						$value = $this->convertFieldValue($data[$fieldName], $field);
						// Create the property dynamically and set its value
						$this->$fieldName = $value;
					}
				}
			}

			// Also call the parent populate method to handle any other BaseModel functionality (like custom fields)
			parent::populate($data);
		} finally {
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
		if ($value === null) {
			return null;
		}

		$type = $fieldConfig['type'];
		
		return match ($type) {
			'int' => (int)$value,
			'float', 'decimal' => (float)$value,
			'checkbox' => (bool)$value,
			'varchar', 'text', 'html', 'select' => (string)$value,
			default => $value
		};
	}
}
