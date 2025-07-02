<?php

namespace App\models;

use App\traits\SerializableTrait;
use App\traits\ValidatorTrait;
use App\Database\Db;
use App\Database\Db2;
use PDO;
use Exception;
use InvalidArgumentException;

/**
 * Generic base model for CRUD operations
 * Inspired by booking_socommon but modernized with FieldMap and RelationshipMap
 * 
 * Child classes should implement:
 * - getFieldMap(): array - Define field validation and sanitization rules
 * - getRelationshipMap(): array - Define entity relationships (optional)
 * - getTableName(): string - Define the main database table
 */
abstract class BaseModel
{
	use SerializableTrait;
	use ValidatorTrait;

	protected Db $db;
	protected ?int $id = null;
	
	// Relationship properties for lazy loading (populated by child classes)
	protected array $_relationshipCache = [];
	
	/**
	 * Valid field types for validation and marshaling
	 */
	protected static array $validFieldTypes = [
		'int' => true,
		'string' => true,
		'float' => true,
		'decimal' => true,
		'datetime' => true,
		'timestamp' => true,
		'date' => true,
		'time' => true,
		'array' => true,
		'json' => true,
		'bool' => true,
		'email' => true,
		'url' => true,
		'html' => true,
		'intarray' => true,
		'text' => true
	];

	public function __construct(?array $data = null)
	{
		$this->db = Db::getInstance();

		if ($data) {
			$this->populate($data);
		}
	}

	/**
	 * Abstract methods that child classes must implement
	 */
	abstract protected static function getFieldMap(): array;
	abstract protected static function getTableName(): string;
	
	/**
	 * Optional method for child classes to define custom fields location
	 * Return the location_id for custom fields integration
	 * If null, no custom fields will be loaded
	 */
	protected static function getCustomFieldsLocationId(): ?int
	{
		return null;
	}

	/**
	 * Optional method for child classes to enable JSON storage of custom fields
	 * Return the field name to store custom fields as JSON
	 * If null, custom fields will be stored in individual columns
	 */
	protected static function getCustomFieldsJsonField(): ?string
	{
		return null;
	}

	/**
	 * Get the default JSON field name for custom fields storage
	 */
	protected static function getDefaultJsonFieldName(): string
	{
		return 'json_representation';
	}

	/**
	 * Optional method for child classes to define relationships
	 */
	protected static function getRelationshipMap(): array
	{
		return [];
	}

	/**
	 * Get sanitization rules from field map
	 */
	public static function getSanitizationRules(): array
	{
		$rules = [];
		foreach (static::getCompleteFieldMap() as $field => $config) {
			if (isset($config['sanitize'])) {
				$rules[$field] = $config['sanitize'];
			}
		}
		return $rules;
	}

	/**
	 * Get array element types from field map (for array sanitization)
	 */
	public static function getArrayElementTypes(): array
	{
		$types = [];
		foreach (static::getCompleteFieldMap() as $field => $config) {
			if (isset($config['sanitize']) && str_starts_with($config['sanitize'], 'array_')) {
				$elementType = substr($config['sanitize'], 6); // Remove 'array_' prefix
				$types[$field] = $elementType;
			}
		}
		return $types;
	}

	/**
	 * Populate model with data
	 */
	public function populate(array $data): self
	{
		$fieldMap = static::getCompleteFieldMap();
		
		foreach ($data as $key => $value) {
			// Check if this is a valid field (either a property or in field map)
			if (property_exists($this, $key) || isset($fieldMap[$key])) {
				$this->$key = $value;
			}
		}
		return $this;
	}

	/**
	 * Validate the model data using the field map
	 */
	public function validate(): array
	{
		$errors = [];
		foreach (static::getCompleteFieldMap() as $field => $meta) {
			$value = $this->$field ?? null;

			// Required check
			if (($meta['required'] ?? false) && $this->isEmpty($value)) {
				$errors[] = $this->formatFieldName($field) . ' is required';
				continue;
			}

			// Type check
			if (!is_null($value) && !$this->isEmpty($value)) {
				$typeError = $this->validateFieldType($field, $value, $meta);
				if ($typeError) {
					$errors[] = $typeError;
				}
			}

			// Max length check
			if (isset($meta['maxLength']) && is_string($value) && strlen($value) > $meta['maxLength']) {
				$errors[] = $this->formatFieldName($field) . " must be {$meta['maxLength']} characters or less";
			}

			// Custom validator
			if (isset($meta['validator']) && is_callable($meta['validator'])) {
				$err = call_user_func($meta['validator'], $value, $this);
				if ($err) {
					$errors[] = $err;
				}
			}
		}

		// Call child class custom validation
		$customErrors = $this->doCustomValidation();
		if (!empty($customErrors)) {
			$errors = array_merge($errors, $customErrors);
		}

		return $errors;
	}

	/**
	 * Check if a value is considered empty for validation purposes
	 */
	protected function isEmpty($value): bool
	{
		return is_null($value) || $value === '' || (is_array($value) && count($value) === 0);
	}

	/**
	 * Validate field type
	 */
	protected function validateFieldType(string $field, $value, array $meta): ?string
	{
		$type = $meta['type'] ?? 'string';
		$fieldName = $this->formatFieldName($field);

		switch ($type) {
			case 'int':
				if (!is_int($value) && !is_numeric($value)) {
					return $fieldName . ' must be an integer';
				}
				break;
			case 'float':
			case 'decimal':
				if (!is_numeric($value)) {
					return $fieldName . ' must be a number';
				}
				break;
			case 'string':
				if (!is_string($value)) {
					return $fieldName . ' must be a string';
				}
				break;
			case 'array':
				if (!is_array($value)) {
					return $fieldName . ' must be an array';
				}
				break;
			case 'datetime':
			case 'timestamp':
			case 'date':
			case 'time':
				try {
					new \DateTime($value);
				} catch (\Exception $e) {
					return $fieldName . ' must be a valid date/time';
				}
				break;
			case 'bool':
				if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'])) {
					return $fieldName . ' must be a boolean value';
				}
				break;
			case 'email':
				if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
					return $fieldName . ' must be a valid email address';
				}
				break;
			case 'url':
				if (!filter_var($value, FILTER_VALIDATE_URL)) {
					return $fieldName . ' must be a valid URL';
				}
				break;
		}

		return null;
	}

	/**
	 * Format field name for user-friendly error messages
	 */
	protected function formatFieldName(string $field): string
	{
		return ucfirst(str_replace('_', ' ', $field));
	}

	/**
	 * Hook for child classes to add custom validation
	 */
	protected function doCustomValidation(): array
	{
		return [];
	}

	/**
	 * Save the model to database (create or update)
	 */
	public function save(): bool
	{
		try {
			$this->db->transaction_begin();

			if ($this->id) {
				$result = $this->update();
			} else {
				$result = $this->create();
			}

			if ($result) {
				$this->db->transaction_commit();
				return true;
			} else {
				$this->db->transaction_abort();
				return false;
			}
		} catch (Exception $e) {
			$this->db->transaction_abort();
			error_log("Error saving " . static::class . ": " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Create new record in database
	 */
	protected function create(): bool
	{
		$tableName = static::getTableName();
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
		if (isset(static::getCompleteFieldMap()['id'])) {
			$sql .= " RETURNING id";
		}

		$stmt = $this->db->prepare($sql);

		// Bind parameters
		foreach ($data as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}

		$stmt->execute();

		// Get the ID if available
		if (isset(static::getCompleteFieldMap()['id'])) {
			$this->id = (int)$stmt->fetchColumn();
		}

		// Save relationships
		$this->saveRelationships();

		return true;
	}

	/**
	 * Update existing record in database
	 */
	protected function update(): bool
	{
		$tableName = static::getTableName();
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
	 * Get custom fields data as array for JSON storage
	 */
	protected function getCustomFieldsData(): array
	{
		$customFieldsData = [];
		$fieldMap = static::getCompleteFieldMap();
		
		foreach ($fieldMap as $field => $meta) {
			if (isset($meta['custom_field']) && $meta['custom_field'] && isset($this->$field)) {
				$customFieldsData[$field] = $this->$field;
			}
		}
		
		return $customFieldsData;
	}

	/**
	 * Set custom fields data from JSON
	 */
	protected function setCustomFieldsData(array $customFieldsData): void
	{
		foreach ($customFieldsData as $field => $value) {
			$this->$field = $value;
		}
	}

	/**
	 * Get data formatted for database operations
	 */
	protected function getDbData(): array
	{
		$data = [];
		$fieldMap = static::getCompleteFieldMap();
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
	 * Marshal a value for database storage
	 */
	protected function marshalValue($value, string $type)
	{
		if ($value === null) {
			return null;
		}

		switch (strtolower($type)) {
			case 'int':
				return (int)$value;
			case 'float':
			case 'decimal':
				return (float)$value;
			case 'bool':
				return $value ? 1 : 0;
			case 'array':
			case 'json':
				return is_string($value) ? $value : json_encode($value);
			case 'datetime':
			case 'timestamp':
			case 'date':
			case 'time':
				return is_string($value) ? $value : $value->format('Y-m-d H:i:s');
			default:
				return (string)$value;
		}
	}

	/**
	 * Unmarshal a value from database
	 */
	protected function unmarshalValue($value, string $type)
	{
		if ($value === null) {
			return null;
		}

		switch (strtolower($type)) {
			case 'int':
				return (int)$value;
			case 'float':
			case 'decimal':
				return (float)$value;
			case 'bool':
				return (bool)$value;
			case 'array':
			case 'json':
				return is_string($value) ? json_decode($value, true) : $value;
			default:
				return $value;
		}
	}

	/**
	 * Save relationships (hook for child classes)
	 */
	protected function saveRelationships(): void
	{
		// Child classes can override this to save specific relationships
	}

	/**
	 * Delete the model from database
	 */
	public function delete(): bool
	{
		if (!$this->id) {
			return false;
		}

		try {
			$this->db->beginTransaction();

			// Delete relationships first
			$this->deleteRelationships();

			// Delete main record
			$tableName = static::getTableName();
			$sql = "DELETE FROM {$tableName} WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':id' => $this->id]);

			$this->db->commit();
			return true;
		} catch (Exception $e) {
			$this->db->rollback();
			error_log("Error deleting " . static::class . ": " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Delete relationships (hook for child classes)
	 */
	protected function deleteRelationships(): void
	{
		// Child classes can override this to delete specific relationships
	}

	/**
	 * Find a model by ID
	 */
	public static function find(int $id): ?static
	{
		$db = Db::getInstance();
		$tableName = static::getTableName();

		$sql = "SELECT * FROM {$tableName} WHERE id = :id";
		$stmt = $db->prepare($sql);
		$stmt->execute([':id' => $id]);
		$data = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$data) {
			return null;
		}

		// Handle JSON representation of custom fields
		$data = static::mergeJsonCustomFields($data);

		// Unmarshal data based on field types
		$fieldMap = static::getCompleteFieldMap();
		foreach ($data as $field => $value) {
			if (isset($fieldMap[$field]['type'])) {
				$data[$field] = (new static())->unmarshalValue($value, $fieldMap[$field]['type']);
			}
		}

		return new static($data);
	}

	/**
	 * Merge JSON custom fields data back into main data array
	 */
	protected static function mergeJsonCustomFields(array $data): array
	{
		$jsonField = static::getCustomFieldsJsonField();
		
		if ($jsonField && isset($data[$jsonField]) && !empty($data[$jsonField])) {
			$jsonData = json_decode($data[$jsonField], true);
			if (is_array($jsonData)) {
				// Merge JSON data into main data array
				$data = array_merge($data, $jsonData);
			}
			// Remove the JSON field from data since it's been expanded
			unset($data[$jsonField]);
		}
		
		return $data;
	}

	/**
	 * Find multiple models with conditions
	 */
	public static function findWhere(array $conditions = [], array $options = []): array
	{
		$db = Db::getInstance();
		$tableName = static::getTableName();

		// Build WHERE clause
		$whereParts = [];
		$params = [];
		foreach ($conditions as $field => $value) {
			$whereParts[] = "$field = :$field";
			$params[":$field"] = $value;
		}
		$whereClause = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

		// Build ORDER BY clause
		$orderClause = '';
		if (isset($options['order_by'])) {
			$direction = $options['direction'] ?? 'ASC';
			$orderClause = "ORDER BY {$options['order_by']} $direction";
		}

		// Build LIMIT clause
		$limitClause = '';
		if (isset($options['limit'])) {
			$limitClause = "LIMIT {$options['limit']}";
			if (isset($options['offset'])) {
				$limitClause .= " OFFSET {$options['offset']}";
			}
		}

		$sql = "SELECT * FROM {$tableName} $whereClause $orderClause $limitClause";
		$stmt = $db->prepare($sql);
		$stmt->execute($params);
		$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$models = [];
		$fieldMap = static::getCompleteFieldMap();
		
		foreach ($results as $data) {
			// Handle JSON representation of custom fields
			$data = static::mergeJsonCustomFields($data);
			
			// Unmarshal data based on field types
			foreach ($data as $field => $value) {
				if (isset($fieldMap[$field]['type'])) {
					$data[$field] = (new static())->unmarshalValue($value, $fieldMap[$field]['type']);
				}
			}
			$models[] = new static($data);
		}

		return $models;
	}

	/**
	 * Generic relationship loading
	 */
	public function loadRelationship(string $relationshipName): ?array
	{
		if (!$this->id) {
			return null;
		}

		// Check cache first
		if (isset($this->_relationshipCache[$relationshipName])) {
			return $this->_relationshipCache[$relationshipName];
		}

		$relationships = static::getRelationshipMap();
		if (!isset($relationships[$relationshipName])) {
			return null;
		}

		$config = $relationships[$relationshipName];
		$result = null;

		switch ($config['type']) {
			case 'many_to_many':
				$result = $this->loadManyToManyRelationship($config);
				break;
			case 'one_to_many':
				$result = $this->loadOneToManyRelationship($config);
				break;
			case 'belongs_to':
				$result = $this->loadBelongsToRelationship($config);
				break;
			case 'has_one':
				$result = $this->loadHasOneRelationship($config);
				break;
		}

		// Cache the result
		$this->_relationshipCache[$relationshipName] = $result;
		return $result;
	}

	/**
	 * Load many-to-many relationship
	 */
	protected function loadManyToManyRelationship(array $config): array
	{
		$fields = implode(', ', array_map(function($field) {
			return "t.$field";
		}, $config['select_fields']));

		$sql = "
			SELECT {$fields}
			FROM {$config['target_table']} t
			JOIN {$config['table']} jt ON t.{$config['target_key']} = jt.{$config['foreign_key']}
			WHERE jt.{$config['local_key']} = :id
		";

		if (isset($config['order_by'])) {
			$sql .= " ORDER BY {$config['order_by']}";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $this->id]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Load one-to-many relationship
	 */
	protected function loadOneToManyRelationship(array $config): array
	{
		$fields = implode(', ', $config['select_fields']);
		$sql = "SELECT {$fields} FROM {$config['table']} WHERE {$config['foreign_key']} = :id";

		if (isset($config['order_by'])) {
			$sql .= " ORDER BY {$config['order_by']}";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $this->id]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Load belongs-to relationship
	 */
	protected function loadBelongsToRelationship(array $config): ?array
	{
		$foreignKey = $config['foreign_key'];
		$foreignId = $this->$foreignKey;

		if (!$foreignId) {
			return null;
		}

		$fields = implode(', ', $config['select_fields']);
		$sql = "SELECT {$fields} FROM {$config['table']} WHERE {$config['owner_key']} = :id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $foreignId]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Load has-one relationship
	 */
	protected function loadHasOneRelationship(array $config): ?array
	{
		$fields = implode(', ', $config['select_fields']);
		$sql = "SELECT {$fields} FROM {$config['table']} WHERE {$config['foreign_key']} = :id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $this->id]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Save many-to-many relationship
	 */
	public function saveRelationship(string $relationshipName, array $ids): bool
	{
		if (!$this->id) {
			return false;
		}

		$relationships = static::getRelationshipMap();
		if (!isset($relationships[$relationshipName]) || $relationships[$relationshipName]['type'] !== 'many_to_many') {
			return false;
		}

		$config = $relationships[$relationshipName];

		try {
			$this->db->beginTransaction();

			// Delete existing relationships
			$deleteSql = "DELETE FROM {$config['table']} WHERE {$config['local_key']} = :id";
			$deleteStmt = $this->db->prepare($deleteSql);
			$deleteStmt->execute([':id' => $this->id]);

			// Insert new relationships
			if (!empty($ids)) {
				$insertSql = "INSERT INTO {$config['table']} ({$config['local_key']}, {$config['foreign_key']}) VALUES (:local_id, :foreign_id)";
				$insertStmt = $this->db->prepare($insertSql);

				foreach ($ids as $foreignId) {
					$insertStmt->execute([
						':local_id' => $this->id,
						':foreign_id' => $foreignId
					]);
				}
			}

			$this->db->commit();
			
			// Clear cached relationship data
			unset($this->_relationshipCache[$relationshipName]);
			return true;
		} catch (Exception $e) {
			$this->db->rollback();
			error_log("Error saving relationship {$relationshipName}: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get all field definitions (for compatibility with legacy code)
	 */
	public function getFieldDefs(): array
	{
		return static::getCompleteFieldMap();
	}

	/**
	 * Get table name (for compatibility with legacy code)
	 */
	public function getTableNameProperty(): string
	{
		return static::getTableName();
	}

	/**
	 * Check if a field type is valid
	 */
	public static function isValidFieldType(string $type): bool
	{
		return isset(static::$validFieldTypes[$type]);
	}

	/**
	 * Advanced CRUD operations inspired by booking_socommon
	 */

	/**
	 * Read entities with advanced filtering and relationship loading
	 * Compatible with legacy booking_socommon::read() signature
	 */
	public static function read(array $params = []): array
	{
		$db = Db::getInstance();
		$tableName = static::getTableName();
		$fieldMap = static::getCompleteFieldMap();

		// Extract parameters
		$query = $params['query'] ?? null;
		$filters = $params['filters'] ?? [];
		$sort = $params['sort'] ?? 'id';
		$dir = strtoupper($params['dir'] ?? 'ASC');
		$start = (int)($params['start'] ?? 0);
		$results = isset($params['results']) ? (int)$params['results'] : 100;

		// Build conditions
		$conditions = static::buildConditions($query, $filters, $fieldMap);
		
		// Get columns and joins
		[$columns, $joins] = static::getColumnsAndJoins($fieldMap, $tableName);

		// Count query
		$countSql = "SELECT COUNT(*) as total FROM {$tableName} " . 
				   implode(' ', $joins) . " WHERE {$conditions}";
		$countStmt = $db->prepare($countSql);
		$countStmt->execute();
		$totalRecords = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

		// Data query
		$dataSql = "SELECT " . implode(',', $columns) . 
				   " FROM {$tableName} " . 
				   implode(' ', $joins) . 
				   " WHERE {$conditions} ORDER BY {$sort} {$dir} LIMIT {$start}, {$results}";
		
		$dataStmt = $db->prepare($dataSql);
		$dataStmt->execute();
		
		$entities = [];
		while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
			$entity = [];
			foreach ($fieldMap as $field => $fieldParams) {
				if (isset($fieldParams['manytomany']) || isset($fieldParams['onetomany'])) {
					// Skip relationships in list queries for performance
					continue;
				} else {
					$modifier = $fieldParams['read_callback'] ?? '';
					$entity[$field] = static::unmarshalValueWithModifier($row[$field] ?? null, $fieldParams['type'], $modifier);
				}
			}
			$entities[] = $entity;
		}

		return [
			'results' => $entities,
			'total_records' => $totalRecords,
			'start' => $start,
			'sort' => $sort,
			'dir' => $dir
		];
	}

	/**
	 * Read single entity with full relationship loading
	 * Compatible with legacy booking_socommon::read_single() signature
	 */
	public static function readSingle($id): array
	{
		if (!$id) {
			return [];
		}

		$db = Db::getInstance();
		$tableName = static::getTableName();
		$fieldMap = static::getCompleteFieldMap();

		$pkConditions = static::buildPrimaryKeyConditions($id, $fieldMap, $tableName);
		[$columns, $joins] = static::getColumnsAndJoins($fieldMap, $tableName);
		
		$sql = "SELECT " . implode(',', $columns) . 
			   " FROM {$tableName} " . 
			   implode(' ', $joins) . 
			   " WHERE {$pkConditions}";

		$stmt = $db->prepare($sql);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) {
			return [];
		}

		// Create secondary db connection for relationships
		$db2 = new Db2();

		// Unmarshal field values and load relationships
		$entity = [];
		foreach ($fieldMap as $field => $params) {
			$modifier = $params['read_callback'] ?? '';

			if (isset($params['manytomany']) && $params['manytomany']) {
				// Load many-to-many relationship
				$entity[$field] = static::loadManyToManyRelationshipData($db2, $id, $field, $params);
			} elseif (isset($params['onetomany']) && $params['onetomany']) {
				// Load one-to-many relationship
				$entity[$field] = static::loadOneToManyRelationshipData($db2, $id, $field, $params);
			} else {
				$entity[$field] = static::unmarshalValueWithModifier($row[$field] ?? null, $params['type'], $modifier);
			}
		}

		return $entity;
	}

	/**
	 * Add new entity with relationship handling
	 * Compatible with legacy booking_socommon::add() signature
	 */
	public static function add(array $entry): array
	{
		$db = Db::getInstance();
		$tableName = static::getTableName();
		$fieldMap = static::getCompleteFieldMap();

		// Get table values (exclude relationships)
		$values = static::getTableValues($entry, $fieldMap, 'add');
		$marshaledValues = static::marshalFieldValues($values, $fieldMap);

		$db->beginTransaction();

		try {
			// Insert main record
			$fields = array_keys($marshaledValues);
			$placeholders = array_fill(0, count($fields), '?');
			
			$sql = "INSERT INTO {$tableName} (" . implode(',', $fields) . 
				   ") VALUES (" . implode(',', $placeholders) . ")";
			
			$stmt = $db->prepare($sql);
			$stmt->execute(array_values($marshaledValues));
			$id = $db->lastInsertId();

			// Save relationships
			static::saveAllRelationships($db, $entry, $id, $fieldMap);

			$db->commit();

			return [
				'id' => $id,
				'message' => [['msg' => "Entity {$id} has been saved"]]
			];
		} catch (Exception $e) {
			$db->rollback();
			throw $e;
		}
	}

	/**
	 * Update existing entity with relationship handling
	 * Compatible with legacy booking_socommon::update() signature
	 */
	public static function updateEntity(array $entry): array
	{
		if (!isset($entry['id'])) {
			throw new InvalidArgumentException('Entity ID is required for update');
		}

		$db = Db::getInstance();
		$tableName = static::getTableName();
		$fieldMap = static::getCompleteFieldMap();

		$id = $entry['id'];
		$values = static::getTableValues($entry, $fieldMap, 'update');
		$marshaledValues = static::marshalFieldValues($values, $fieldMap);

		$db->beginTransaction();

		try {
			// Update main record
			$setPairs = [];
			$params = [];
			foreach ($marshaledValues as $field => $value) {
				if ($field !== 'id') {
					$setPairs[] = "{$field} = ?";
					$params[] = $value;
				}
			}
			$params[] = $id;

			$sql = "UPDATE {$tableName} SET " . implode(', ', $setPairs) . " WHERE id = ?";
			$stmt = $db->prepare($sql);
			$stmt->execute($params);

			// Update relationships
			static::saveAllRelationships($db, $entry, $id, $fieldMap, true);

			$db->commit();

			return [
				'id' => $id,
				'message' => [['msg' => "Entity {$id} has been updated"]]
			];
		} catch (Exception $e) {
			$db->rollback();
			throw $e;
		}
	}

	/**
	 * Delete entity with relationship cleanup
	 */
	public static function deleteEntity($id): array
	{
		$db = Db::getInstance();
		$tableName = static::getTableName();
		$fieldMap = static::getCompleteFieldMap();

		$db->beginTransaction();

		try {
			// Delete relationships first
			static::deleteAllRelationships($db, $id, $fieldMap);

			// Delete main record
			$sql = "DELETE FROM {$tableName} WHERE id = ?";
			$stmt = $db->prepare($sql);
			$stmt->execute([$id]);

			$db->commit();

			return [
				'id' => $id,
				'message' => [['msg' => "Entity {$id} has been deleted"]]
			];
		} catch (Exception $e) {
			$db->rollback();
			throw $e;
		}
	}

	/**
	 * Build WHERE conditions from query and filters
	 */
	protected static function buildConditions(?string $query, array $filters, array $fieldMap): string
	{
		$db = Db::getInstance();
		$tableName = static::getTableName();
		$clauses = ['1=1'];

		// Add search query conditions
		if ($query) {
			$likePattern = "'%" . $db->quote($query) . "%'";
			$likeClauses = [];
			
			foreach ($fieldMap as $field => $params) {
				if (!empty($params['query']) && $params['query']) {
					$table = $tableName;
					$column = $field;
					
					if ($params['type'] === 'int') {
						if (!(int)$query) {
							continue;
						}
						$likeClauses[] = "{$table}.{$column} = " . (int)$query;
					} else {
						$likeClauses[] = "{$table}.{$column} LIKE {$likePattern}";
					}
				}
			}
			
			if (count($likeClauses)) {
				$clauses[] = '(' . implode(' OR ', $likeClauses) . ')';
			}
		}

		// Add filter conditions
		foreach ($filters as $key => $val) {
			if (isset($fieldMap[$key])) {
				$fieldDef = $fieldMap[$key];
				$table = $tableName;

				if (is_array($val) && count($val) === 0) {
					$clauses[] = '1=0';
				} elseif (is_array($val)) {
					$vals = [];
					foreach ($val as $v) {
						$vals[] = static::marshalValue($v, $fieldDef['type']);
					}
					$clauses[] = "({$table}.{$key} IN (" . implode(',', $vals) . '))';
				} elseif ($val === null) {
					$clauses[] = "{$table}.{$key} IS NULL";
				} else {
					$clauses[] = "{$table}.{$key} = " . static::marshalValue($val, $fieldDef['type']);
				}
			} elseif ($key === 'where') {
				// Custom where clauses
				$whereClauses = (array)$val;
				if (count($whereClauses) > 0) {
					$clauses[] = str_replace('%%table%%', $tableName, implode(' AND ', $whereClauses));
				}
			}
		}

		return implode(' AND ', $clauses);
	}

	/**
	 * Build primary key conditions
	 */
	protected static function buildPrimaryKeyConditions($id, array $fieldMap, string $tableName): string
	{
		if (is_array($id)) {
			return static::buildConditions(null, $id, $fieldMap);
		}

		// Use id field type if defined, otherwise assume int
		$idType = $fieldMap['id']['type'] ?? 'int';
		$idValue = static::marshalValue($id, $idType);
		
		return "{$tableName}.id = {$idValue}";
	}

	/**
	 * Get columns and joins for SELECT queries
	 */
	protected static function getColumnsAndJoins(array $fieldMap, string $tableName): array
	{
		$columns = [];
		$joins = [];

		foreach ($fieldMap as $field => $params) {
			// Skip relationship fields in main query
			if (isset($params['manytomany']) || isset($params['onetomany'])) {
				continue;
			}

			if (isset($params['join'])) {
				// Handle join fields
				$joinTable = $params['join']['table'];
				$joinAlias = $params['join']['alias'] ?? $joinTable;
				$joinColumn = $params['join']['column'];
				$joinCondition = $params['join']['condition'];
				
				$columns[] = "{$joinAlias}.{$joinColumn} AS {$field}";
				$joins[] = "LEFT JOIN {$joinTable} {$joinAlias} ON {$joinCondition}";
			} else {
				$columns[] = "{$tableName}.{$field}";
			}
		}

		return [$columns, $joins];
	}

	/**
	 * Get table values for CRUD operations (excludes relationship fields)
	 */
	protected static function getTableValues(array $entity, array $fieldMap, string $action = 'add'): array
	{
		$values = [];
		foreach ($fieldMap as $field => $params) {
			// Skip relationship fields and auto fields for certain actions
			if (isset($params['manytomany']) || isset($params['onetomany']) || isset($params['join'])) {
				continue;
			}

			// Skip auto fields for add operations
			if ($action === 'add' && isset($params['auto']) && in_array('add', $params['auto'])) {
				continue;
			}

			// Include field if it exists in entity or has a default value
			if (array_key_exists($field, $entity)) {
				$values[$field] = $entity[$field];
			} elseif (isset($params['default'])) {
				$values[$field] = $params['default'];
			}
		}
		return $values;
	}

	/**
	 * Marshal all field values in an entity
	 */
	protected static function marshalFieldValues(array $entity, array $fieldMap): array
	{
		$marshaled = [];
		foreach ($entity as $field => $value) {
			if (isset($fieldMap[$field])) {
				$fieldDef = $fieldMap[$field];
				$modifier = $fieldDef['write_callback'] ?? '';
				$marshaled[$field] = static::marshalValueForDb($value, $fieldDef['type'], $modifier);
			}
		}
		return $marshaled;
	}

	/**
	 * Enhanced marshal value for database storage with modifier support
	 */
	protected static function marshalValueForDb($value, string $type, string $modifier = '')
	{
		// Apply modifier callback if exists and is a method
		if ($modifier && method_exists(static::class, $modifier)) {
			call_user_func_array([static::class, $modifier], [&$value, true]);
		}

		// Handle null values
		if ($value === null) {
			return null;
		}

		// Handle different types
		switch (strtolower($type)) {
			case 'int':
			case 'integer':
				if (is_string($value) && strlen(trim($value)) === 0) {
					return null;
				}
				return (int) $value;

			case 'decimal':
			case 'float':
				if (is_string($value) && strlen(trim($value)) === 0) {
					return null;
				}
				return (float) $value;

			case 'bool':
			case 'boolean':
				return $value ? 1 : 0;

			case 'timestamp':
			case 'datetime':
			case 'date':
			case 'time':
				if (is_string($value) && strlen(trim($value)) === 0) {
					return null;
				}
				return is_string($value) ? $value : $value->format('Y-m-d H:i:s');

			case 'json':
				return is_string($value) ? $value : json_encode($value);

			case 'array':
			case 'intarray':
				if (!is_array($value)) {
					return null;
				}
				return json_encode($value);

			case 'string':
			case 'text':
			default:
				return (string) $value;
		}
	}

	/**
	 * Enhanced unmarshal value from database with modifier support
	 */
	protected static function unmarshalValueWithModifier($value, string $type, string $modifier = '')
	{
		// Apply modifier callback if exists and is a method
		if ($modifier && method_exists(static::class, $modifier)) {
			call_user_func_array([static::class, $modifier], [&$value]);
		}

		// Handle null or empty values
		if ($value === null || ($type !== 'string' && strlen(trim($value)) === 0)) {
			return null;
		}

		// Handle different types
		switch (strtolower($type)) {
			case 'int':
			case 'integer':
				return (int) $value;

			case 'decimal':
			case 'float':
				return (float) $value;

			case 'bool':
			case 'boolean':
				return (bool) $value;

			case 'json':
				$decoded = json_decode($value, true);
				if (!is_array($decoded)) {
					return trim($decoded, '"');
				}
				return $decoded;

			case 'array':
			case 'intarray':
				if (is_string($value)) {
					$decoded = json_decode($value, true);
					if (is_array($decoded)) {
						return $type === 'intarray' ? array_map('intval', $decoded) : $decoded;
					}
				}
				return is_array($value) ? $value : [];

			case 'string':
			case 'text':
			case 'timestamp':
			case 'datetime':
			case 'date':
			case 'time':
			default:
				return $value;
		}
	}

	/**
	 * Legacy compatibility bridge - uses Db::unmarshal for basic types if available
	 * This method provides backward compatibility with legacy socommon::_unmarshal behavior
	 * 
	 * @param mixed $value The value to unmarshal
	 * @param string $type The target type
	 * @param string $modifier Optional modifier callback
	 * @param bool $useLegacy Whether to use legacy Db::unmarshal for basic types
	 * @return mixed The unmarshaled value
	 */
	protected static function unmarshalValueLegacyCompat($value, string $type, string $modifier = '', bool $useLegacy = false)
	{
		// Apply modifier callback if exists and is a method
		if ($modifier && method_exists(static::class, $modifier)) {
			call_user_func_array([static::class, $modifier], [&$value]);
		}

		// For legacy compatibility, use Db::unmarshal for basic types if requested
		if ($useLegacy && class_exists('\App\Database\Db')) {
			$legacyTypes = ['int', 'decimal', 'string', 'json'];
			if (in_array(strtolower($type), $legacyTypes)) {
				try {
					$db = \App\Database\Db::getInstance();
					return $db->unmarshal($value, $type);
				} catch (Exception $e) {
					// Fall back to modern implementation if legacy fails
					error_log("Legacy unmarshal failed, using modern implementation: " . $e->getMessage());
				}
			}
		}

		// Use modern implementation (default behavior)
		return static::unmarshalValueWithModifier($value, $type, $modifier);
	}

	/**
	 * Load many-to-many relationship data (inspired by booking_socommon)
	 */
	protected static function loadManyToManyRelationshipData($db, $id, string $field, array $params): array
	{
		$table = $params['manytomany']['table'];
		$key = $params['manytomany']['key'];
		$column = $params['manytomany']['column'];
		
		$result = [];

		if (is_array($column)) {
			// Multiple columns
			$columns = [];
			foreach ($column as $fieldOrInt => $paramsOrFieldName) {
				$columns[] = is_array($paramsOrFieldName) ? $fieldOrInt : $paramsOrFieldName;
			}
			$columnsList = implode(',', $columns);
			
			$orderClause = '';
			if (isset($params['manytomany']['order']) && is_array($params['manytomany']['order'])) {
				$sort = $params['manytomany']['order']['sort'];
				$dir = $params['manytomany']['order']['dir'];
				$orderClause = "ORDER BY {$sort} {$dir}";
			}

			$sql = "SELECT {$columnsList} FROM {$table} WHERE {$key} = ? {$orderClause}";
			$stmt = $db->prepare($sql);
			$stmt->execute([$id]);
			
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$data = [];
				foreach ($column as $intOrCol => $paramsOrCol) {
					if (is_array($paramsOrCol)) {
						$col = $intOrCol;
						$type = $paramsOrCol['type'] ?? $params['type'];
						$modifier = $paramsOrCol['read_callback'] ?? '';
					} else {
						$col = $paramsOrCol;
						$type = $params['type'];
						$modifier = $params['read_callback'] ?? '';
					}
					
					$data[$col] = static::unmarshalValueWithModifier($row[$col] ?? null, $type, $modifier);
				}
				$result[] = $data;
			}
		} else {
			// Single column
			$sql = "SELECT {$column} FROM {$table} WHERE {$key} = ?";
			$stmt = $db->prepare($sql);
			$stmt->execute([$id]);
			
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$modifier = $params['read_callback'] ?? '';
				$result[] = static::unmarshalValueWithModifier($row[$column], $params['type'], $modifier);
			}
		}

		return $result;
	}

	/**
	 * Load one-to-many relationship data
	 */
	protected static function loadOneToManyRelationshipData($db, $id, string $field, array $params): array
	{
		$table = $params['onetomany']['table'];
		$key = $params['onetomany']['key'];
		$columns = $params['onetomany']['columns'] ?? '*';
		
		$orderClause = '';
		if (isset($params['onetomany']['order'])) {
			$sort = $params['onetomany']['order']['sort'];
			$dir = $params['onetomany']['order']['dir'] ?? 'ASC';
			$orderClause = "ORDER BY {$sort} {$dir}";
		}

		$sql = "SELECT {$columns} FROM {$table} WHERE {$key} = ? {$orderClause}";
		$stmt = $db->prepare($sql);
		$stmt->execute([$id]);
		
		$result = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Save all relationships for an entity
	 */
	protected static function saveAllRelationships($db, array $entity, $id, array $fieldMap, bool $isUpdate = false): void
	{
		foreach ($fieldMap as $field => $params) {
			if ((isset($params['manytomany']) || isset($params['onetomany'])) && 
				array_key_exists($field, $entity)) {
				static::saveRelationshipData($db, $field, $entity[$field], $id, $params, $isUpdate);
			}
		}
	}

	/**
	 * Save a specific relationship
	 */
	protected static function saveRelationshipData($db, string $field, $data, $id, array $params, bool $isUpdate = false): void
	{
		if (isset($params['manytomany'])) {
			static::saveManyToManyRelationshipData($db, $field, $data, $id, $params, $isUpdate);
		} elseif (isset($params['onetomany'])) {
			static::saveOneToManyRelationshipData($db, $field, $data, $id, $params, $isUpdate);
		}
	}

	/**
	 * Save many-to-many relationship (inspired by booking_socommon)
	 */
	protected static function saveManyToManyRelationshipData($db, string $field, $data, $id, array $params, bool $isUpdate): void
	{
		$table = $params['manytomany']['table'];
		$key = $params['manytomany']['key'];
		$column = $params['manytomany']['column'];

		// Clear existing relationships if updating
		if ($isUpdate) {
			$deleteSql = "DELETE FROM {$table} WHERE {$key} = ?";
			$deleteStmt = $db->prepare($deleteSql);
			$deleteStmt->execute([$id]);
		}

		if (!is_array($data) || empty($data)) {
			return;
		}

		if (is_array($column)) {
			// Multiple columns
			$columns = [];
			foreach ($column as $intOrCol => $paramsOrCol) {
				$colName = is_array($paramsOrCol) ? $intOrCol : $paramsOrCol;
				if ($colName !== 'id') {
					$columns[] = $colName;
				}
			}
			$columnsList = implode(',', $columns);

			foreach ($data as $item) {
				$values = [$id];
				foreach ($column as $intOrCol => $paramsOrCol) {
					if (is_array($paramsOrCol)) {
						$col = $intOrCol;
						$type = $paramsOrCol['type'] ?? $params['type'];
						$modifier = $paramsOrCol['write_callback'] ?? '';
					} else {
						$col = $paramsOrCol;
						$type = $params['type'];
						$modifier = $params['write_callback'] ?? '';
					}

					if ($col === 'id') {
						continue;
					}

					$values[] = static::marshalValueForDb($item[$col] ?? null, $type, $modifier);
				}

				$placeholders = array_fill(0, count($values), '?');
				$sql = "INSERT INTO {$table} ({$key}, {$columnsList}) VALUES (" . implode(',', $placeholders) . ")";
				$stmt = $db->prepare($sql);
				$stmt->execute($values);
			}
		} else {
			// Single column
			foreach ($data as $value) {
				$marshaledValue = static::marshalValueForDb($value, $params['type']);
				$sql = "INSERT INTO {$table} ({$key}, {$column}) VALUES (?, ?)";
				$stmt = $db->prepare($sql);
				$stmt->execute([$id, $marshaledValue]);
			}
		}
	}

	/**
	 * Save one-to-many relationship
	 */
	protected static function saveOneToManyRelationshipData($db, string $field, $data, $id, array $params, bool $isUpdate): void
	{
		$table = $params['onetomany']['table'];
		$key = $params['onetomany']['key'];

		// Clear existing relationships if updating
		if ($isUpdate) {
			$deleteSql = "DELETE FROM {$table} WHERE {$key} = ?";
			$deleteStmt = $db->prepare($deleteSql);
			$deleteStmt->execute([$id]);
		}

		if (!is_array($data) || empty($data)) {
			return;
		}

		foreach ($data as $item) {
			$item[$key] = $id; // Set parent ID
			$fields = array_keys($item);
			$placeholders = array_fill(0, count($fields), '?');
			
			$sql = "INSERT INTO {$table} (" . implode(',', $fields) . 
				   ") VALUES (" . implode(',', $placeholders) . ")";
			$stmt = $db->prepare($sql);
			$stmt->execute(array_values($item));
		}
	}

	/**
	 * Delete all relationships for an entity
	 */
	protected static function deleteAllRelationships($db, $id, array $fieldMap): void
	{
		foreach ($fieldMap as $field => $params) {
			if (isset($params['manytomany'])) {
				$table = $params['manytomany']['table'];
				$key = $params['manytomany']['key'];
				$sql = "DELETE FROM {$table} WHERE {$key} = ?";
				$stmt = $db->prepare($sql);
				$stmt->execute([$id]);
			} elseif (isset($params['onetomany'])) {
				$table = $params['onetomany']['table'];
				$key = $params['onetomany']['key'];
				$sql = "DELETE FROM {$table} WHERE {$key} = ?";
				$stmt = $db->prepare($sql);
				$stmt->execute([$id]);
			}
		}
	}

	/**
	 * Get complete field map including custom fields
	 * This merges static field definitions with dynamic custom fields
	 */
	public static function getCompleteFieldMap(): array
	{
		$fieldMap = static::getFieldMap();
		$customFields = static::getCustomFields();
		
		// Add JSON field for custom fields storage if enabled
		$jsonField = static::getCustomFieldsJsonField();
		if ($jsonField) {
			$fieldMap[$jsonField] = [
				'type' => 'json',
				'required' => false,
				'sanitize' => 'string',
				'virtual' => true, // Mark as virtual so it's not included in individual field processing
				'json_storage' => true
			];
		}
		
		// Merge custom fields into the field map (only if not using JSON storage)
		if (!$jsonField) {
			foreach ($customFields as $customField) {
				$fieldName = $customField['column_name'];
				
				// Skip if field already exists in static definition
				if (isset($fieldMap[$fieldName])) {
					continue;
				}
				
				// Convert custom field definition to field map format
				$fieldConfig = static::convertCustomFieldToFieldConfig($customField);
				$fieldMap[$fieldName] = $fieldConfig;
			}
		}
		
		return $fieldMap;
	}

	/**
	 * Helper method to get location_id from app and location name
	 * This can be used by child classes to find their custom fields location_id
	 */
	protected static function getLocationId(string $appName, string $location): ?int
	{
		try {
			$db = new Db2();

		//	$sql = "SELECT location_id FROM phpgw_locations WHERE app_name = ? AND location = ?";
			$sql = "SELECT location_id FROM phpgw_applications join phpgw_locations ON phpgw_applications.app_id = phpgw_locations.app_id WHERE app_name = ? AND phpgw_locations.name = ?";
			$stmt = $db->prepare($sql);
			
			if ($stmt === false) {
				error_log("Failed to prepare location_id query - table phpgw_locations may not exist");
				return null;
			}
			
			$stmt->execute([$appName, $location]);
			
			$result = $stmt->fetch(PDO::FETCH_ASSOC);
			return $result ? (int)$result['location_id'] : null;
		} catch (Exception $e) {
			throw $e;
		}
	}

	/**
	 * Get custom fields for this model based on location_id
	 */
	public static function getCustomFields(): array
	{
		$locationId = static::getCustomFieldsLocationId();
		if ($locationId === null) {
			return [];
		}

		try {
			// Create instance of phpgwapi_custom_fields
			$customFields = new \App\modules\phpgwapi\services\CustomFields();

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
		} catch (Exception $e) {
			error_log("Error loading custom fields for location_id {$locationId}: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * Convert custom field definition to BaseModel field configuration
	 */
	protected static function convertCustomFieldToFieldConfig(array $customField): array
	{
		$config = [
			'type' => static::mapCustomFieldDataType($customField['datatype']),
			'required' => !$customField['nullable'],
			'sanitize' => static::mapCustomFieldSanitization($customField['datatype']),
			'custom_field' => true, // Mark as custom field
			'custom_field_id' => $customField['id'],
			'custom_field_meta' => $customField
		];

		// Add default value if specified
		if (!empty($customField['default_value'])) {
			$config['default'] = $customField['default_value'];
		}

		// Add size constraints for strings
		if ($customField['datatype'] === 'V' && !empty($customField['size'])) {
			$config['maxLength'] = (int)$customField['size'];
		}

		// Add precision for numeric types
		if (in_array($customField['datatype'], ['N', 'I']) && !empty($customField['precision'])) {
			$config['precision'] = (int)$customField['precision'];
		}

		// Add validation for choice fields
		if (in_array($customField['datatype'], ['R', 'CH', 'LB']) && !empty($customField['choice'])) {
			$validChoices = array_column($customField['choice'], 'id');
			$config['validator'] = function ($value) use ($validChoices, $customField) {
				if (empty($value)) return null; // Let required validation handle empty values
				
				if ($customField['datatype'] === 'CH') {
					// Multiple choice - can be array
					$values = is_array($value) ? $value : [$value];
					foreach ($values as $v) {
						if (!in_array($v, $validChoices)) {
							return "Invalid choice for " . $customField['input_text'];
						}
					}
				} else {
					// Single choice
					if (!in_array($value, $validChoices)) {
						return "Invalid choice for " . $customField['input_text'];
					}
				}
				return null;
			};
		}

		return $config;
	}

	/**
	 * Map custom field data types to BaseModel types
	 */
	protected static function mapCustomFieldDataType(string $datatype): string
	{
		switch ($datatype) {
			case 'I': // Integer
				return 'int';
			case 'N': // Numeric/Float
				return 'float';
			case 'V': // Varchar/String
			case 'T': // Text
			case 'R': // Radio/Select
			case 'LB': // Listbox
				return 'string';
			case 'CH': // Checkbox (multiple choice)
				return 'array';
			case 'D': // Date
			case 'DT': // DateTime
				return 'string'; // Will be validated as date format
			case 'B': // Boolean
				return 'int'; // Usually stored as 0/1
			default:
				return 'string';
		}
	}

	/**
	 * Map custom field data types to sanitization types
	 */
	protected static function mapCustomFieldSanitization(string $datatype): string
	{
		switch ($datatype) {
			case 'I': // Integer
				return 'int';
			case 'N': // Numeric/Float
				return 'float';
			case 'V': // Varchar/String
			case 'R': // Radio/Select
			case 'LB': // Listbox
				return 'string';
			case 'T': // Text (may contain HTML)
				return 'html';
			case 'CH': // Checkbox (multiple choice)
				return 'array_string';
			case 'D': // Date
			case 'DT': // DateTime
				return 'string';
			case 'B': // Boolean
				return 'int';
			default:
				return 'string';
		}
	}
}
