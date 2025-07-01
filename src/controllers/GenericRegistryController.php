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
 * 
 * @OA\Tag(
 *     name="Generic Registry",
 *     description="Generic registry operations for all modules (property, booking, rental, admin)"
 * )
 * 
 * @OA\Schema(
 *     schema="RegistryItem",
 *     type="object",
 *     description="Registry item with dynamic fields based on registry type configuration. Only the 'id' field is guaranteed to be present.",
 *     @OA\Property(property="id", type="integer", description="Registry item ID (always present)"),
 *     @OA\AdditionalProperties(
 *         description="Additional fields are defined per registry type. See /registry/{type}/schema endpoint for specific field definitions.",
 *         anyOf={
 *             @OA\Schema(type="string"),
 *             @OA\Schema(type="integer"),
 *             @OA\Schema(type="number"),
 *             @OA\Schema(type="boolean"),
 *             @OA\Schema(type="null")
 *         }
 *     )
 * )
 * 
 * @OA\Schema(
 *     schema="RegistryType",
 *     type="object",
 *     @OA\Property(property="type", type="string", description="Registry type key"),
 *     @OA\Property(property="name", type="string", description="Human readable name"),
 *     @OA\Property(property="table", type="string", description="Database table name"),
 *     @OA\Property(property="acl_location", type="string", description="ACL location string"),
 *     @OA\Property(property="fields", type="array", @OA\Items(type="object"), description="Field definitions")
 * )
 * 
 * @OA\Schema(
 *     schema="RegistryListResponse",
 *     type="object",
 *     @OA\Property(property="success", type="boolean"),
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/RegistryItem")),
 *     @OA\Property(property="total", type="integer", description="Total number of items"),
 *     @OA\Property(property="start", type="integer", description="Starting offset"),
 *     @OA\Property(property="limit", type="integer", description="Items per page"),
 *     @OA\Property(property="registry_type", type="string", description="Registry type"),
 *     @OA\Property(property="registry_name", type="string", description="Registry display name")
 * )
 * 
 * @OA\Schema(
 *     schema="RegistryItemResponse",
 *     type="object",
 *     @OA\Property(property="success", type="boolean"),
 *     @OA\Property(property="data", ref="#/components/schemas/RegistryItem"),
 *     @OA\Property(property="registry_type", type="string"),
 *     @OA\Property(property="registry_name", type="string")
 * )
 * 
 * @OA\Schema(
 *     schema="RegistryTypesResponse",
 *     type="object",
 *     @OA\Property(property="success", type="boolean"),
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/RegistryType"))
 * )
 * 
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     type="object",
 *     @OA\Property(property="success", type="boolean", example=false),
 *     @OA\Property(property="message", type="string", description="Error message"),
 *     @OA\Property(property="errors", type="array", @OA\Items(type="string"), description="Validation errors (if applicable)")
 * )
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
	 * 
	 * @OA\Get(
	 *     path="/{module}/registry/{type}",
	 *     summary="Get paginated list of registry items",
	 *     description="Retrieve a list of registry items with pagination, filtering, and sorting",
	 *     tags={"Generic Registry"},
	 *     @OA\Parameter(
	 *         name="module",
	 *         in="path",
	 *         required=true,
	 *         description="Module name (property, booking, rental, admin)",
	 *         @OA\Schema(type="string", enum={"property", "booking", "rental", "admin"})
	 *     ),
	 *     @OA\Parameter(
	 *         name="type",
	 *         in="path",
	 *         required=true,
	 *         description="Registry type (e.g., building, office, category)",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Parameter(
	 *         name="start",
	 *         in="query",
	 *         description="Starting offset for pagination",
	 *         @OA\Schema(type="integer", default=0)
	 *     ),
	 *     @OA\Parameter(
	 *         name="limit",
	 *         in="query",
	 *         description="Number of items per page",
	 *         @OA\Schema(type="integer", default=50, maximum=100)
	 *     ),
	 *     @OA\Parameter(
	 *         name="query",
	 *         in="query",
	 *         description="Search query for item names",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Parameter(
	 *         name="sort",
	 *         in="query",
	 *         description="Sort field",
	 *         @OA\Schema(type="string", default="id")
	 *     ),
	 *     @OA\Parameter(
	 *         name="dir",
	 *         in="query",
	 *         description="Sort direction",
	 *         @OA\Schema(type="string", enum={"ASC", "DESC"}, default="ASC")
	 *     ),
	 *     @OA\Parameter(
	 *         name="{field_name}",
	 *         in="query",
	 *         description="Filter by any field marked as filterable in the registry configuration. Use /registry/{type}/schema to see available filter fields.",
	 *         @OA\Schema(
	 *             anyOf={
	 *                 @OA\Schema(type="string"),
	 *                 @OA\Schema(type="integer"),
	 *                 @OA\Schema(type="number")
	 *             }
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Success",
	 *         @OA\JsonContent(ref="#/components/schemas/RegistryListResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - invalid parameters",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Registry type not found",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     )
	 * )
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
			// Search in 'name' field if it exists in the registry config, otherwise search in first text field
			$searchField = 'name'; // Default fallback
			$config = $registryClass::getRegistryConfig($type);
			if (!empty($config['fields'])) {
				foreach ($config['fields'] as $field) {
					if ($field['name'] === 'name') {
						$searchField = 'name';
						break;
					} elseif (in_array($field['type'], ['varchar', 'text']) && !isset($searchField)) {
						$searchField = $field['name'];
					}
				}
			}
			$conditions[] = [$searchField, 'LIKE', "%{$query}%"];
		}

		// Add filters from query params - only for fields that exist in the registry configuration
		$config = $registryClass::getRegistryConfig($type);
		$allowedFilterFields = [];
		if (!empty($config['fields'])) {
			foreach ($config['fields'] as $field) {
				if (isset($field['filter']) && $field['filter']) {
					$allowedFilterFields[] = $field['name'];
				}
			}
		}

		foreach ($params as $key => $value) {
			if (in_array($key, $allowedFilterFields) && $value !== '') {
				$conditions[$key] = $value;
			}
		}

		// Get results using static method with type parameter
		$results = $registryClass::findWhereByType($type, $conditions, [
			'order_by' => $sort,
			'direction' => $dir,
			'limit' => $limit,
			'offset' => $start
		]);

		// Get total count for pagination
		// For now, we'll count the results (BaseModel doesn't have count method yet)
		$allResults = $registryClass::findWhereByType($type, $conditions);
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
	 * 
	 * @OA\Get(
	 *     path="/{module}/registry/{type}/{id}",
	 *     summary="Get single registry item by ID",
	 *     description="Retrieve a specific registry item by its ID",
	 *     tags={"Generic Registry"},
	 *     @OA\Parameter(
	 *         name="module",
	 *         in="path",
	 *         required=true,
	 *         description="Module name",
	 *         @OA\Schema(type="string", enum={"property", "booking", "rental", "admin"})
	 *     ),
	 *     @OA\Parameter(
	 *         name="type",
	 *         in="path",
	 *         required=true,
	 *         description="Registry type",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         required=true,
	 *         description="Registry item ID",
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Success",
	 *         @OA\JsonContent(ref="#/components/schemas/RegistryItemResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - invalid parameters",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Registry type or item not found",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     )
	 * )
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

		// Use static method to find item by type and ID
		$item = $registryClass::findByType($type, $id);
//		_debug_array($item);

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
	 * 
	 * @OA\Post(
	 *     path="/{module}/registry/{type}",
	 *     summary="Create new registry item",
	 *     description="Create a new registry item of the specified type",
	 *     tags={"Generic Registry"},
	 *     @OA\Parameter(
	 *         name="module",
	 *         in="path",
	 *         required=true,
	 *         description="Module name",
	 *         @OA\Schema(type="string", enum={"property", "booking", "rental", "admin"})
	 *     ),
	 *     @OA\Parameter(
	 *         name="type",
	 *         in="path",
	 *         required=true,
	 *         description="Registry type",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         description="Registry item data - fields vary by registry type. See /registry/{type}/schema for field definitions.",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             description="Dynamic object with fields specific to the registry type",
	 *             @OA\AdditionalProperties(
	 *                 anyOf={
	 *                     @OA\Schema(type="string"),
	 *                     @OA\Schema(type="integer"),
	 *                     @OA\Schema(type="number"),
	 *                     @OA\Schema(type="boolean"),
	 *                     @OA\Schema(type="null")
	 *                 }
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Item created successfully",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="data", ref="#/components/schemas/RegistryItem"),
	 *             @OA\Property(property="message", type="string", example="Item created successfully"),
	 *             @OA\Property(property="registry_type", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Validation failed or bad request",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Registry type not found",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     )
	 * )
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
		
		// Handle JSON request body for POST requests
		if ($data === null || !is_array($data)) {
			$body = (string) $request->getBody();
			if (!empty($body)) {
				$data = json_decode($body, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					throw new HttpBadRequestException($request, 'Invalid JSON data');
				}
			}
		}

		if (!is_array($data) || empty($data)) {
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
	 * 
	 * @OA\Put(
	 *     path="/{module}/registry/{type}/{id}",
	 *     summary="Update existing registry item",
	 *     description="Update an existing registry item by its ID",
	 *     tags={"Generic Registry"},
	 *     @OA\Parameter(
	 *         name="module",
	 *         in="path",
	 *         required=true,
	 *         description="Module name",
	 *         @OA\Schema(type="string", enum={"property", "booking", "rental", "admin"})
	 *     ),
	 *     @OA\Parameter(
	 *         name="type",
	 *         in="path",
	 *         required=true,
	 *         description="Registry type",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         required=true,
	 *         description="Registry item ID",
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         description="Updated registry item data - fields vary by registry type. See /registry/{type}/schema for field definitions.",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             description="Dynamic object with fields specific to the registry type",
	 *             @OA\AdditionalProperties(
	 *                 anyOf={
	 *                     @OA\Schema(type="string"),
	 *                     @OA\Schema(type="integer"),
	 *                     @OA\Schema(type="number"),
	 *                     @OA\Schema(type="boolean"),
	 *                     @OA\Schema(type="null")
	 *                 }
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Item updated successfully",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="data", ref="#/components/schemas/RegistryItem"),
	 *             @OA\Property(property="message", type="string", example="Item updated successfully"),
	 *             @OA\Property(property="registry_type", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Validation failed or bad request",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Registry type or item not found",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     )
	 * )
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

		// Use static method to find item by type and ID
		$item = $registryClass::findByType($type, $id);

		if (!$item) {
			throw new HttpNotFoundException($request, "Item not found");
		}

		$data = $request->getParsedBody();
		
		// Handle JSON request body for PUT requests
		if ($data === null || !is_array($data)) {
			$body = (string) $request->getBody();
			if (!empty($body)) {
				$data = json_decode($body, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					throw new HttpBadRequestException($request, 'Invalid JSON data');
				}
			}
		}

		if (!is_array($data) || empty($data)) {
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
	 * 
	 * @OA\Delete(
	 *     path="/{module}/registry/{type}/{id}",
	 *     summary="Delete registry item",
	 *     description="Delete an existing registry item by its ID",
	 *     tags={"Generic Registry"},
	 *     @OA\Parameter(
	 *         name="module",
	 *         in="path",
	 *         required=true,
	 *         description="Module name",
	 *         @OA\Schema(type="string", enum={"property", "booking", "rental", "admin"})
	 *     ),
	 *     @OA\Parameter(
	 *         name="type",
	 *         in="path",
	 *         required=true,
	 *         description="Registry type",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         required=true,
	 *         description="Registry item ID to delete",
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Item deleted successfully",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="message", type="string", example="Item deleted successfully"),
	 *             @OA\Property(property="registry_type", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - invalid parameters",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Registry type or item not found",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     )
	 * )
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

		// Use static method to find item by type and ID
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
	 * 
	 * @OA\Get(
	 *     path="/{module}/registry/types",
	 *     summary="Get available registry types for module",
	 *     description="Retrieve all available registry types for the specified module",
	 *     tags={"Generic Registry"},
	 *     @OA\Parameter(
	 *         name="module",
	 *         in="path",
	 *         required=true,
	 *         description="Module name",
	 *         @OA\Schema(type="string", enum={"property", "booking", "rental", "admin"})
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Success",
	 *         @OA\JsonContent(ref="#/components/schemas/RegistryTypesResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Unable to detect registry class",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     )
	 * )
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
	 * 
	 * @OA\Get(
	 *     path="/{module}/registry/{type}/schema",
	 *     summary="Get registry type schema",
	 *     description="Retrieve field definitions, validation rules, and metadata for a registry type",
	 *     tags={"Generic Registry"},
	 *     @OA\Parameter(
	 *         name="module",
	 *         in="path",
	 *         required=true,
	 *         description="Module name",
	 *         @OA\Schema(type="string", enum={"property", "booking", "rental", "admin"})
	 *     ),
	 *     @OA\Parameter(
	 *         name="type",
	 *         in="path",
	 *         required=true,
	 *         description="Registry type",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Success",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(
	 *                 property="data",
	 *                 type="object",
	 *                 @OA\Property(property="type", type="string", description="Registry type key"),
	 *                 @OA\Property(property="name", type="string", description="Human readable name"),
	 *                 @OA\Property(property="table", type="string", description="Database table"),
	 *                 @OA\Property(property="id_field", type="array", @OA\Items(type="string"), description="ID field configuration"),
	 *                 @OA\Property(property="fields", type="array", @OA\Items(type="object"), description="Field definitions"),
	 *                 @OA\Property(property="field_map", type="object", description="Complete field mapping"),
	 *                 @OA\Property(property="acl_info", type="object", description="Access control information")
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Registry type is required",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Registry type not found",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     )
	 * )
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
		// Get field map using static method
		$fieldMap = $registryClass::getCompleteFieldMap();
		// Create instance to get ACL info
		$registry = $registryClass::forType($type);

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
	 * 
	 * @OA\Get(
	 *     path="/{module}/registry/{type}/list",
	 *     summary="Get registry items for dropdown/select",
	 *     description="Retrieve a simplified list of registry items suitable for dropdowns and select controls",
	 *     tags={"Generic Registry"},
	 *     @OA\Parameter(
	 *         name="module",
	 *         in="path",
	 *         required=true,
	 *         description="Module name",
	 *         @OA\Schema(type="string", enum={"property", "booking", "rental", "admin"})
	 *     ),
	 *     @OA\Parameter(
	 *         name="type",
	 *         in="path",
	 *         required=true,
	 *         description="Registry type",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Parameter(
	 *         name="add_empty",
	 *         in="query",
	 *         description="Add empty '-- Select --' option",
	 *         @OA\Schema(type="boolean", default=false)
	 *     ),
	 *     @OA\Parameter(
	 *         name="selected",
	 *         in="query",
	 *         description="Mark specific item as selected",
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Parameter(
	 *         name="{field_name}",
	 *         in="query",
	 *         description="Filter by any field marked as filterable in the registry configuration. Use /registry/{type}/schema to see available filter fields.",
	 *         @OA\Schema(
	 *             anyOf={
	 *                 @OA\Schema(type="string"),
	 *                 @OA\Schema(type="integer"),
	 *                 @OA\Schema(type="number")
	 *             }
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Success",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(
	 *                 property="data",
	 *                 type="array",
	 *                 @OA\Items(
	 *                     type="object",
	 *                     @OA\Property(property="id", type="integer", description="Item ID"),
	 *                     @OA\Property(property="name", type="string", description="Item name"),
	 *                     @OA\Property(property="selected", type="boolean", description="Whether item is selected")
	 *                 )
	 *             ),
	 *             @OA\Property(property="registry_type", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Registry type is required",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Registry type not found",
	 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
	 *     )
	 * )
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

		// Apply filters based on registry configuration
		$conditions = [];
		$config = $registryClass::getRegistryConfig($type);
		$allowedFilterFields = ['id']; // Always allow ID filtering
		if (!empty($config['fields'])) {
			foreach ($config['fields'] as $field) {
				if (isset($field['filter']) && $field['filter']) {
					$allowedFilterFields[] = $field['name'];
				}
			}
		}

		foreach ($params as $key => $value) {
			if (in_array($key, $allowedFilterFields) && $value !== '' && !in_array($key, ['add_empty', 'selected'])) {
				$conditions[$key] = $value;
			}
		}

		// Determine sort field - prefer 'name' if available, otherwise use first available field
		$sortField = 'id'; // Fallback
		if (!empty($config['fields'])) {
			foreach ($config['fields'] as $field) {
				if ($field['name'] === 'name') {
					$sortField = 'name';
					break;
				} elseif (isset($field['sortable']) && $field['sortable'] && $sortField === 'id') {
					$sortField = $field['name'];
				}
			}
		}

		$items = $registryClass::findWhereByType($type, $conditions, [
			'order_by' => $sortField,
			'direction' => 'ASC'
		]);

		// Format for dropdown
		$list = [];

		if ($addEmpty) {
			$list[] = ['id' => '', 'name' => '-- Select --'];
		}

		// Determine display field - prefer 'name' if available, otherwise use first text field
		$displayField = 'id'; // Fallback
		if (!empty($config['fields'])) {
			foreach ($config['fields'] as $field) {
				if ($field['name'] === 'name') {
					$displayField = 'name';
					break;
				} elseif (in_array($field['type'], ['varchar', 'text']) && $displayField === 'id') {
					$displayField = $field['name'];
				}
			}
		}

		foreach ($items as $item) {
			$displayValue = property_exists($item, $displayField) && isset($item->$displayField) 
				? $item->$displayField 
				: "Item #{$item->id}";

			$listItem = [
				'id' => $item->id,
				'name' => $displayValue
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
