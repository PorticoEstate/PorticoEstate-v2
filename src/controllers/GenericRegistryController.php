<?php

namespace App\controllers;

use App\models\GenericRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * Generic Registry Controller
 * Handles CRUD operations for multiple registry types through a single controller
 * Can be used by any module that implements GenericRegistry
 * Similar to property_uigeneric but modernized with Slim 4 and BaseModel
 */
class GenericRegistryController
{
	/**
	 * The GenericRegistry class to use for this controller instance
	 * Should be set by the module that uses this controller
	 */
	protected string $registryClass;

	/**
	 * Constructor
	 */
	public function __construct(?string $registryClass = null)
	{
		if ($registryClass && class_exists($registryClass)) {
			$this->registryClass = $registryClass;
		}
	}

	/**
	 * Set the registry class to use
	 */
	public function setRegistryClass(string $registryClass): void
	{
		if (!class_exists($registryClass)) {
			throw new \InvalidArgumentException("Registry class {$registryClass} does not exist");
		}

		if (!is_subclass_of($registryClass, GenericRegistry::class)) {
			throw new \InvalidArgumentException("Registry class {$registryClass} must extend GenericRegistry");
		}

		$this->registryClass = $registryClass;
	}

	/**
	 * Get the registry class, with fallback detection
	 */
	protected function getRegistryClass(Request $request): string
	{
		if (isset($this->registryClass)) {
			return $this->registryClass;
		}

		// Try to detect from route or headers
		$module = $this->detectModuleFromRequest($request);
		if ($module) {
			$registryClass = "App\\modules\\{$module}\\models\\" . ucfirst($module) . "GenericRegistry";
			if (class_exists($registryClass)) {
				return $registryClass;
			}
		}

		throw new \RuntimeException("No registry class configured and unable to auto-detect");
	}

	/**
	 * Detect module from request (can be overridden by specific implementations)
	 */
	protected function detectModuleFromRequest(Request $request): ?string
	{
		// Detect from /{module}/registry pattern at the start of path
		// Matches: /property/registry, /booking/registry, etc.
		$path = $request->getUri()->getPath();
		if (preg_match('/^\/([^\/]+)\/registry/', $path, $matches)) {
			$module = $matches[1];
			// Only return if it's a known module
			if (in_array($module, ['property', 'booking', 'rental', 'admin'])) {
				return $module;
			}
		}

		// Try to detect from custom header as fallback
		$moduleHeader = $request->getHeaderLine('X-Module');
		if ($moduleHeader) {
			return $moduleHeader;
		}

		return null;
	}

	/**
	 * Get list of items for a registry type
	 * GET /{module}/registry/{type}
	 */
	public function index(Request $request, Response $response, array $args): Response
	{
		$registryClass = $this->getRegistryClass($request);
		$type = $args['type'] ?? '';

		if (!$type) {
			throw new HttpBadRequestException($request, 'Registry type is required');
		}

		// Validate registry type exists
		if (!in_array($type, $registryClass::getAvailableTypes())) {
			throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
		}

		// Get query parameters
		$params = $request->getQueryParams();
		$start = (int)($params['start'] ?? 0);
		$limit = (int)($params['limit'] ?? 50);
		$query = $params['query'] ?? '';
		$sort = $params['sort'] ?? 'id';
		$dir = $params['dir'] ?? 'ASC';

		// Build search conditions
		$conditions = [];
		if ($query) {
			$conditions[] = ['name', 'LIKE', "%{$query}%"];
		}

		// Add filters from query params
		foreach ($params as $key => $value) {
			if (in_array($key, ['active', 'parent_id']) && $value !== '') {
				$conditions[$key] = $value;
			}
		}

		// Get results
		$registry = $registryClass::forType($type);
		$results = $registry->findWhere($conditions, [
			'order_by' => $sort,
			'direction' => $dir,
			'limit' => $limit,
			'offset' => $start
		]);

		// Get total count for pagination
		// For now, we'll count the results (BaseModel doesn't have count method yet)
		$allResults = $registry->findWhere($conditions);
		$totalCount = count($allResults);

		$responseData = [
			'success' => true,
			'data' => $results,
			'total' => $totalCount,
			'start' => $start,
			'limit' => $limit,
			'registry_type' => $type,
			'registry_name' => $registryClass::getTypeName($type)
		];

		$response->getBody()->write(json_encode($responseData));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Get single item by ID
	 * GET /{module}/registry/{type}/{id}
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		$registryClass = $this->getRegistryClass($request);
		$type = $args['type'] ?? '';
		$id = (int)($args['id'] ?? 0);

		if (!$type) {
			throw new HttpBadRequestException($request, 'Registry type is required');
		}

		if (!$id) {
			throw new HttpBadRequestException($request, 'ID is required');
		}

		if (!in_array($type, $registryClass::getAvailableTypes())) {
			throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
		}

		$item = $registryClass::findByType($type, $id);

		if (!$item) {
			throw new HttpNotFoundException($request, "Item not found");
		}

		$responseData = [
			'success' => true,
			'data' => $item->toArray(),
			'registry_type' => $type,
			'registry_name' => $item->getRegistryName()
		];

		$response->getBody()->write(json_encode($responseData));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Create new item
	 * POST /{module}/registry/{type}
	 */
	public function store(Request $request, Response $response, array $args): Response
	{
		$registryClass = $this->getRegistryClass($request);
		$type = $args['type'] ?? '';

		if (!$type) {
			throw new HttpBadRequestException($request, 'Registry type is required');
		}

		if (!in_array($type, $registryClass::getAvailableTypes())) {
			throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
		}

		$data = $request->getParsedBody();

		if (!is_array($data)) {
			throw new HttpBadRequestException($request, 'Invalid request data');
		}

		try {
			$item = $registryClass::createForType($type, $data);

			// Validate the data
			$errors = $item->validate();
			if (!empty($errors)) {
				$response->getBody()->write(json_encode([
					'success' => false,
					'errors' => $errors,
					'message' => 'Validation failed'
				]));
				return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
			}

			// Save the item
			$success = $item->save();

			if (!$success) {
				throw new \Exception('Failed to save item');
			}

			$responseData = [
				'success' => true,
				'data' => $item->toArray(),
				'message' => 'Item created successfully',
				'registry_type' => $type
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
		} catch (\Exception $e) {
			$responseData = [
				'success' => false,
				'message' => 'Failed to create item: ' . $e->getMessage()
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}

	/**
	 * Update existing item
	 * PUT /{module}/registry/{type}/{id}
	 */
	public function update(Request $request, Response $response, array $args): Response
	{
		$registryClass = $this->getRegistryClass($request);
		$type = $args['type'] ?? '';
		$id = (int)($args['id'] ?? 0);

		if (!$type) {
			throw new HttpBadRequestException($request, 'Registry type is required');
		}

		if (!$id) {
			throw new HttpBadRequestException($request, 'ID is required');
		}

		if (!in_array($type, $registryClass::getAvailableTypes())) {
			throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
		}

		$item = $registryClass::findByType($type, $id);

		if (!$item) {
			throw new HttpNotFoundException($request, "Item not found");
		}

		$data = $request->getParsedBody();

		if (!is_array($data)) {
			throw new HttpBadRequestException($request, 'Invalid request data');
		}

		try {
			// Update the item with new data
			$item->populate($data);

			// Validate the data
			$errors = $item->validate();
			if (!empty($errors)) {
				$response->getBody()->write(json_encode([
					'success' => false,
					'errors' => $errors,
					'message' => 'Validation failed'
				]));
				return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
			}

			// Save the changes
			$success = $item->save();

			if (!$success) {
				throw new \Exception('Failed to update item');
			}

			$responseData = [
				'success' => true,
				'data' => $item->toArray(),
				'message' => 'Item updated successfully',
				'registry_type' => $type
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json');
		} catch (\Exception $e) {
			$responseData = [
				'success' => false,
				'message' => 'Failed to update item: ' . $e->getMessage()
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}

	/**
	 * Delete item
	 * DELETE /{module}/registry/{type}/{id}
	 */
	public function delete(Request $request, Response $response, array $args): Response
	{
		$registryClass = $this->getRegistryClass($request);
		$type = $args['type'] ?? '';
		$id = (int)($args['id'] ?? 0);

		if (!$type) {
			throw new HttpBadRequestException($request, 'Registry type is required');
		}

		if (!$id) {
			throw new HttpBadRequestException($request, 'ID is required');
		}

		if (!in_array($type, $registryClass::getAvailableTypes())) {
			throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
		}

		$item = $registryClass::findByType($type, $id);

		if (!$item) {
			throw new HttpNotFoundException($request, "Item not found");
		}

		try {
			$success = $item->delete();

			if (!$success) {
				throw new \Exception('Failed to delete item');
			}

			$responseData = [
				'success' => true,
				'message' => 'Item deleted successfully',
				'registry_type' => $type
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json');
		} catch (\Exception $e) {
			$responseData = [
				'success' => false,
				'message' => 'Failed to delete item: ' . $e->getMessage()
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
		}
	}

	/**
	 * Get available registry types
	 * GET /{module}/registry/types
	 */
	public function types(Request $request, Response $response): Response
	{
		$registryClass = $this->getRegistryClass($request);
		$types = [];

		foreach ($registryClass::getAvailableTypes() as $type) {
			$config = $registryClass::getRegistryConfig($type);
			$types[] = [
				'type' => $type,
				'name' => $config['name'] ?? ucfirst(str_replace('_', ' ', $type)),
				'table' => $config['table'] ?? '',
				'acl_location' => $config['acl_location'] ?? '',
				'fields' => $config['fields'] ?? []
			];
		}

		$responseData = [
			'success' => true,
			'data' => $types
		];

		$response->getBody()->write(json_encode($responseData));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Get schema/field information for a registry type
	 * GET /{module}/registry/{type}/schema
	 */
	public function schema(Request $request, Response $response, array $args): Response
	{
		$registryClass = $this->getRegistryClass($request);
		$type = $args['type'] ?? '';

		if (!$type) {
			throw new HttpBadRequestException($request, 'Registry type is required');
		}

		if (!in_array($type, $registryClass::getAvailableTypes())) {
			throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
		}

		$config = $registryClass::getRegistryConfig($type);
		$registry = $registryClass::forType($type);
		// Get field map through reflection or create a public method
		$fieldMap = $registry->getCompleteFieldMap();

		$responseData = [
			'success' => true,
			'data' => [
				'type' => $type,
				'name' => $config['name'] ?? '',
				'table' => $config['table'] ?? '',
				'id_field' => $config['id'] ?? [],
				'fields' => $config['fields'] ?? [],
				'field_map' => $fieldMap,
				'acl_info' => $registry->getAclInfo()
			]
		];

		$response->getBody()->write(json_encode($responseData));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Get list for dropdowns/selects
	 * GET /{module}/registry/{type}/list
	 */
	public function getList(Request $request, Response $response, array $args): Response
	{
		$registryClass = $this->getRegistryClass($request);
		$type = $args['type'] ?? '';

		if (!$type) {
			throw new HttpBadRequestException($request, 'Registry type is required');
		}

		if (!in_array($type, $registryClass::getAvailableTypes())) {
			throw new HttpNotFoundException($request, "Registry type '{$type}' not found");
		}

		$params = $request->getQueryParams();
		$addEmpty = isset($params['add_empty']) && $params['add_empty'];
		$selected = $params['selected'] ?? null;

		// Get all active items
		$conditions = [];
		if (isset($params['active'])) {
			$conditions['active'] = 1;
		}

		$items = $registryClass::findWhereByType($type, $conditions, [
			'order_by' => 'name',
			'direction' => 'ASC'
		]);

		// Format for dropdown
		$list = [];

		if ($addEmpty) {
			$list[] = ['id' => '', 'name' => '-- Select --'];
		}

		foreach ($items as $item) {
			$listItem = [
				'id' => $item->id,
				'name' => $item->name
			];

			if ($selected && $item->id == $selected) {
				$listItem['selected'] = true;
			}

			$list[] = $listItem;
		}

		$responseData = [
			'success' => true,
			'data' => $list,
			'registry_type' => $type
		];

		$response->getBody()->write(json_encode($responseData));
		return $response->withHeader('Content-Type', 'application/json');
	}
}
