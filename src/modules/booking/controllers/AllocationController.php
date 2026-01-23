<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\booking\models\Allocation;
use App\modules\phpgwapi\security\Acl;
use App\Database\Db;
use Sanitizer;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class AllocationController
{
	protected array $userSettings;
	protected Db $db;
	protected Acl $acl;

	public function __construct(ContainerInterface $container)
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->acl = Acl::getInstance();
	}

	/**
	 * @OA\Post(
	 *     path="/booking/allocations",
	 *     summary="Create a new allocation",
	 *     description="Creates a new allocation for an organization within a season.",
	 *     tags={"Allocations"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         description="Allocation data",
	 *         @OA\JsonContent(
	 *             required={"organization_id", "season_id", "from_", "to_", "resource_ids"},
	 *             @OA\Property(property="organization_id", type="integer", description="Organization ID", example=10),
	 *             @OA\Property(property="season_id", type="integer", description="Season ID", example=5),
	 *             @OA\Property(property="from_", type="string", format="date-time", description="Start time (ISO 8601)", example="2025-06-25T15:30:00+02:00"),
	 *             @OA\Property(property="to_", type="string", format="date-time", description="End time (ISO 8601)", example="2025-06-25T17:00:00+02:00"),
	 *             @OA\Property(property="cost", type="number", format="float", description="Cost of allocation", example=100.00),
	 *             @OA\Property(
	 *                 property="resource_ids",
	 *                 type="array",
	 *                 description="Array of resource IDs",
	 *                 @OA\Items(type="integer"),
	 *                 example={1, 2}
	 *             ),
	 *             @OA\Property(property="skip_bas", type="integer", description="Skip BAS export", example=0),
	 *             @OA\Property(property="additional_invoice_information", type="string", description="Invoice info", example="PO#12345")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Allocation created successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="id", type="integer", description="Created allocation ID"),
	 *             @OA\Property(property="allocation", type="object", ref="#/components/schemas/Allocation")
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Invalid input"),
	 *     @OA\Response(response=409, description="Conflict"),
	 *     @OA\Response(response=500, description="Internal server error")
	 * )
	 */
	public function createAllocation(Request $request, Response $response): Response
	{
		if (!$this->acl->check('.application', Acl::ADD, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$data = $this->parseRequestData($request);
			
			// Sanitize
			$data = $this->sanitizeAllocationData($data);

			// Validate required fields
			$required = ['organization_id', 'season_id', 'from_', 'to_', 'resource_ids'];
			foreach ($required as $field) {
				if (empty($data[$field])) {
					$response->getBody()->write(json_encode(['error' => "Missing required field: $field"]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}

			// Prepare data for model
			$modelData = [
				'organization_id' => $data['organization_id'],
				'season_id' => $data['season_id'],
				'from_' => $data['from_'],
				'to_' => $data['to_'],
				'cost' => $data['cost'] ?? 0,
				'active' => 1,
				'completed' => 0,
				'skip_bas' => $data['skip_bas'] ?? 0,
				'additional_invoice_information' => $data['additional_invoice_information'] ?? '',
				'resources' => $data['resource_ids'],
				// building_name is required by model but usually derived. 
				// For now, let's try to fetch it or set a placeholder if not provided.
				// In legacy code, it seemed to be a query field, but also required in schema.
				'building_name' => $data['building_name'] ?? 'Unknown' 
			];

			// If building_name is not provided, try to get it from season -> building
			if ($modelData['building_name'] === 'Unknown') {
				// Logic to fetch building name could go here if needed
			}

			$allocation = new Allocation($modelData);

			// Validate
			$validationErrors = $allocation->validate();
			if (!empty($validationErrors)) {
				$response->getBody()->write(json_encode(['error' => 'Validation failed', 'details' => $validationErrors]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Check conflicts
			$conflicts = $allocation->checkConflicts();
			if (!empty($conflicts)) {
				$response->getBody()->write(json_encode(['error' => 'Time conflict detected', 'details' => $conflicts]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
			}

			// Save
			if (!$allocation->save()) {
				$response->getBody()->write(json_encode(['error' => 'Failed to create allocation']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			$responseData = [
				'success' => true,
				'id' => $allocation->id,
				'allocation' => $allocation->serialize()
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/booking/allocations/{id}",
	 *     summary="Get allocation details",
	 *     tags={"Allocations"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Success"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function getAllocation(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::READ, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		$id = (int)$args['id'];
		$allocation = Allocation::find($id);

		if (!$allocation) {
			$response->getBody()->write(json_encode(['error' => 'Allocation not found']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
		}

		$response->getBody()->write(json_encode($allocation->serialize()));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
	}

	/**
	 * @OA\Put(
	 *     path="/booking/allocations/{id}",
	 *     summary="Update an allocation",
	 *     tags={"Allocations"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/Allocation")),
	 *     @OA\Response(response=200, description="Success"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function updateAllocation(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$allocation = Allocation::find($id);

			if (!$allocation) {
				$response->getBody()->write(json_encode(['error' => 'Allocation not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$data = $this->parseRequestData($request);
			$data = $this->sanitizeAllocationData($data);

			if (empty($data)) {
				$response->getBody()->write(json_encode(['error' => 'No update data provided']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$allocation->populate($data);

			$validationErrors = $allocation->validate();
			if (!empty($validationErrors)) {
				$response->getBody()->write(json_encode(['error' => 'Validation failed', 'details' => $validationErrors]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			if (isset($data['from_']) || isset($data['to_']) || isset($data['resources'])) {
				$conflicts = $allocation->checkConflicts($id);
				if (!empty($conflicts)) {
					$response->getBody()->write(json_encode(['error' => 'Time conflict detected', 'details' => $conflicts]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
				}
			}

			if (!$allocation->save()) {
				$response->getBody()->write(json_encode(['error' => 'Failed to update allocation']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			$responseData = [
				'success' => true,
				'allocation' => $allocation->serialize()
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/booking/allocations/{id}",
	 *     summary="Delete an allocation",
	 *     tags={"Allocations"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Success"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function deleteAllocation(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::DELETE, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$allocation = Allocation::find($id);

			if (!$allocation) {
				$response->getBody()->write(json_encode(['error' => 'Allocation not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			if (!$allocation->delete()) {
				$response->getBody()->write(json_encode(['error' => 'Failed to delete allocation']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			$response->getBody()->write(json_encode(['success' => true, 'message' => 'Allocation deleted successfully']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	private function parseRequestData(Request $request): array
	{
		$contentType = $request->getHeaderLine('Content-Type');
		if (strpos($contentType, 'application/json') !== false) {
			$body = $request->getBody()->getContents();
			$data = json_decode($body, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new Exception('Invalid JSON format');
			}
			return $data;
		} else {
			$data = $request->getParsedBody() ?: [];
			if (empty($data)) {
				$body = $request->getBody()->getContents();
				if (!empty($body)) {
					parse_str($body, $data);
				}
			}
			return $data;
		}
	}

	private function sanitizeAllocationData(array $data): array
	{
		$sanitized = [];
		$rules = Allocation::getSanitizationRules();

		foreach ($data as $key => $value) {
			if ($value === null) continue;

			$type = $rules[$key] ?? 'string';
			
			if ($key === 'resource_ids' && is_array($value)) {
				$sanitized[$key] = array_map('intval', $value);
			} elseif ($type === 'array_int') {
				// Handle array_int if passed as comma separated string or array
				if (is_array($value)) {
					$sanitized[$key] = array_map('intval', $value);
				} else {
					$sanitized[$key] = array_map('intval', explode(',', $value));
				}
			} else {
				$sanitized[$key] = Sanitizer::clean_value($value, $type);
			}
		}
		return $sanitized;
	}
}
