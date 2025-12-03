<?php

namespace App\modules\booking\models;

use App\models\BaseModel;
use App\Database\Db;

/**
 * Example model showing how to use BaseModel for CRUD operations
 * This is a simple example for a Resource model
 */
class Resource extends BaseModel
{
	// Override BaseModel properties to make them accessible
	protected array $fieldMap = [];
	protected array $relationshipMap = [];

	// Define model fields as properties
	public ?int $id = null;
	public string $name;
	public string $description = '';
	public int $building_id;
	public int $active = 1;
	public float $cost = 0.0;
	public ?int $parent_id = null;

	/**
	 * Get table name for the Resource model
	 */
	protected static function getTableName(): string
	{
		return 'bb_resource';
	}

	/**
	 * Get field map - required by BaseModel
	 */
	protected static function getFieldMap(): array
	{
		return [
			'id' => [
				'type' => 'int',
				'required' => false,
			],
			'name' => [
				'type' => 'string',
				'required' => true,
				'maxLength' => 255,
				'query' => true, // Allow searching by name
			],
			'description' => [
				'type' => 'text',
				'required' => false,
				'query' => true, // Allow searching by description
			],
			'building_id' => [
				'type' => 'int',
				'required' => true,
			],
			'active' => [
				'type' => 'int',
				'required' => true,
				'default' => 1,
			],
			'cost' => [
				'type' => 'decimal',
				'required' => false,
				'default' => 0.0,
			],
			'parent_id' => [
				'type' => 'int',
				'required' => false,
			],
		];
	}

	/**
	 * Initialize field map - required by BaseModel
	 */
	protected function initializeFieldMap(): void
	{
		$this->fieldMap = static::getFieldMap();
	}

	/**
	 * Initialize relationship map - optional
	 */
	protected function initializeRelationshipMap(): void
	{
		$this->relationshipMap = static::getRelationshipMap();
	}

	/**
	 * Get relationship map - optional override
	 */
	protected static function getRelationshipMap(): array
	{
		return [
			'building' => [
				'type' => 'belongs_to',
				'table' => 'bb_building',
				'foreign_key' => 'building_id',
				'owner_key' => 'id',
				'select_fields' => ['id', 'name', 'email', 'phone'],
			],
			'children' => [
				'type' => 'one_to_many',
				'table' => 'bb_resource',
				'foreign_key' => 'parent_id',
				'select_fields' => ['id', 'name', 'description', 'active'],
				'order_by' => 'name ASC',
			],
			'allocations' => [
				'type' => 'many_to_many',
				'table' => 'bb_allocation_resource',
				'local_key' => 'resource_id',
				'foreign_key' => 'allocation_id',
				'target_table' => 'bb_allocation',
				'target_key' => 'id',
				'select_fields' => ['id', 'name', 'from_', 'to_', 'active'],
			],
		];
	}

	/**
	 * Example of custom validation
	 */
	protected function doCustomValidation(): array
	{
		$errors = [];

		// Custom validation: check if building exists
		if ($this->building_id) {
			$building = BaseModel::readSingle($this->building_id);
			if (empty($building)) {
				$errors[] = 'Building does not exist';
			}
		}

		return $errors;
	}

	/**
	 * Example convenience methods using BaseModel functionality
	 */
	
	/**
	 * Get all active resources
	 */
	public static function getActiveResources(): array
	{
		return static::read([
			'filters' => ['active' => 1],
			'sort' => 'name',
			'dir' => 'ASC',
		]);
	}

	/**
	 * Get resources by building
	 */
	public static function getResourcesByBuilding(int $buildingId): array
	{
		return static::read([
			'filters' => ['building_id' => $buildingId, 'active' => 1],
			'sort' => 'name',
			'dir' => 'ASC',
		]);
	}

	/**
	 * Search resources by name or description
	 */
	public static function searchResources(string $query): array
	{
		return static::read([
			'query' => $query,
			'filters' => ['active' => 1],
			'sort' => 'name',
			'dir' => 'ASC',
		]);
	}

	/**
	 * Create a new resource with validation
	 */
	public static function createResource(array $data): array
	{
		$resource = new static($data);
		$errors = $resource->validate();
		
		if (!empty($errors)) {
			return ['success' => false, 'errors' => $errors];
		}

		$result = static::add($data);
		return ['success' => true, 'resource' => $result];
	}

	/**
	 * Update a resource with validation
	 */
	public static function updateResource(int $id, array $data): array
	{
		$data['id'] = $id;
		$resource = new static($data);
		$errors = $resource->validate();
		
		if (!empty($errors)) {
			return ['success' => false, 'errors' => $errors];
		}

		$result = static::updateEntity($data);
		return ['success' => true, 'resource' => $result];
	}

	/**
	 * Get resource with all relationships loaded
	 */
	public static function getResourceWithRelationships(int $id): ?array
	{
		$resource = static::readSingle($id);
		if (empty($resource)) {
			return null;
		}

		// Load relationships using BaseModel functionality
		$resourceModel = new static($resource);
		$resource['building'] = $resourceModel->loadRelationship('building');
		$resource['children'] = $resourceModel->loadRelationship('children');
		$resource['allocations'] = $resourceModel->loadRelationship('allocations');

		return $resource;
	}
}
