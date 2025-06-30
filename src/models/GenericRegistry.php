<?php

namespace App\models;

use App\models\BaseModel;

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

	// Dynamic properties based on registry type
	public ?int $id = null;
	public string $name = '';
	public string $description = '';
	public int $active = 1;
	public ?int $parent_id = null;
	public ?int $sort_order = null;

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
	 */
	protected static function getTableName(): string
	{
		throw new \Exception("Use forType() to create instances with specific registry types");
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
	 * Override BaseModel methods to use instance table name
	 * We'll handle this through method overrides rather than dynamic table names
	 */

	/**
	 * Get field map based on registry type
	 */
	protected static function getFieldMap(): array
	{
		// For static calls, return empty array
		// Instance calls will use getInstanceFieldMap()
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

		return $fieldMap;
	}

	/**
	 * Get field map for instance (protected method for internal use)
	 */
	protected function getPrivateInstanceFieldMap(): array
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
		return $instance->find($id);
	}

	public static function findWhereByType(string $type, array $conditions = [], array $options = []): array
	{
		$instance = static::forType($type);
		return $instance->findWhere($conditions, $options);
	}

	public static function createForType(string $type, array $data = []): static
	{
		return static::forType($type, $data);
	}

	/**
	 * Override getCompleteFieldMap to return registry-specific fields
	 * This is tricky because BaseModel expects static methods but we need instance data
	 * We'll use a workaround with thread-local storage
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
	 * Thread-local storage for current instance
	 */
	private static ?GenericRegistry $currentInstance = null;

	/**
	 * Set current instance for static method context
	 */
	private static function setCurrentInstance(?GenericRegistry $instance): void
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
	 * Custom fields support - based on registry type
	 */
	protected static function getCustomFieldsLocationId(): ?int
	{
		// This would need to be called on instances, not statically
		return null;
	}

	/**
	 * Instance method for custom fields
	 */
	protected function getInstanceCustomFieldsLocationId(): ?int
	{
		if (!$this->registryConfig)
		{
			return null;
		}

		$app = $this->registryConfig['acl_app'] ?? 'booking';
		$location = $this->registryConfig['acl_location'] ?? '.admin';

		return static::getLocationId($app, $location);
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
}
