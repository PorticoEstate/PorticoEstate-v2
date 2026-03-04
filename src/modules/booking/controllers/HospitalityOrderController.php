<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\repositories\HospitalityArticleRepository;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\models\HospitalityOrder;
use App\modules\booking\models\HospitalityOrderLine;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class HospitalityOrderController
{
	protected array $userSettings;
	protected Acl $acl;
	protected HospitalityRepository $hospitalityRepository;
	protected HospitalityArticleRepository $articleRepository;
	protected HospitalityOrderRepository $repository;

	private const ALLOWED_TRANSITIONS = [
		HospitalityOrder::STATUS_PENDING => [HospitalityOrder::STATUS_CONFIRMED, HospitalityOrder::STATUS_CANCELLED],
		HospitalityOrder::STATUS_CONFIRMED => [HospitalityOrder::STATUS_DELIVERED, HospitalityOrder::STATUS_CANCELLED],
		HospitalityOrder::STATUS_CANCELLED => [],
		HospitalityOrder::STATUS_DELIVERED => [],
	];

	public function __construct(ContainerInterface $container)
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->acl = Acl::getInstance();
		$this->hospitalityRepository = new HospitalityRepository();
		$this->articleRepository = new HospitalityArticleRepository();
		$this->repository = new HospitalityOrderRepository();
	}

	private function orderToJson(int $orderId): array
	{
		$data = $this->repository->getOrderWithLines($orderId);
		$model = new HospitalityOrder($data);
		return $model->serialize();
	}

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality-orders",
	 *     summary="List hospitality orders",
	 *     tags={"Hospitality Orders"},
	 *     @OA\Parameter(name="application_id", in="query", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="hospitality_id", in="query", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="List of orders")
	 * )
	 */
	public function index(Request $request, Response $response): Response
	{
		try {
			$params = $request->getQueryParams();

			if (!empty($params['application_id'])) {
				$orders = $this->repository->getOrdersWithLinesByApplication((int)$params['application_id']);
			} elseif (!empty($params['hospitality_id'])) {
				$status = $params['status'] ?? null;
				$rows = $this->repository->getByHospitalityId((int)$params['hospitality_id'], $status);
				$orders = [];
				foreach ($rows as $row) {
					$row['lines'] = $this->repository->getOrderLines((int)$row['id']);
					$row['total_amount'] = $this->repository->calculateOrderTotal((int)$row['id']);
					$orders[] = $row;
				}
			} else {
				$response->getBody()->write(json_encode(['error' => 'application_id or hospitality_id query parameter is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$results = array_map(function ($row) {
				return (new HospitalityOrder($row))->serialize();
			}, $orders);

			$response->getBody()->write(json_encode($results));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/booking/hospitality-orders",
	 *     summary="Create a hospitality order",
	 *     tags={"Hospitality Orders"},
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"application_id", "hospitality_id", "location_resource_id"},
	 *         @OA\Property(property="application_id", type="integer"),
	 *         @OA\Property(property="hospitality_id", type="integer"),
	 *         @OA\Property(property="location_resource_id", type="integer"),
	 *         @OA\Property(property="comment", type="string"),
	 *         @OA\Property(property="special_requirements", type="string")
	 *     )),
	 *     @OA\Response(response=201, description="Order created"),
	 *     @OA\Response(response=400, description="Validation error"),
	 *     @OA\Response(response=403, description="Permission denied")
	 * )
	 */
	public function store(Request $request, Response $response): Response
	{
		if (!$this->acl->check('.application', Acl::ADD, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (empty($body['application_id']) || empty($body['hospitality_id']) || empty($body['location_resource_id'])) {
				$response->getBody()->write(json_encode([
					'error' => 'application_id, hospitality_id, and location_resource_id are required',
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Validate hospitality exists and is active
			$hospitality = $this->hospitalityRepository->getById((int)$body['hospitality_id']);
			if (!$hospitality) {
				$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}
			if (!(int)$hospitality['active']) {
				$response->getBody()->write(json_encode(['error' => 'Hospitality is not active']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Validate location is a valid delivery location
			$validLocations = $this->hospitalityRepository->getDeliveryLocations((int)$body['hospitality_id']);
			$locationIds = array_column($validLocations, 'id');
			if (!in_array((int)$body['location_resource_id'], array_map('intval', $locationIds))) {
				$response->getBody()->write(json_encode(['error' => 'Invalid delivery location for this hospitality']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$body['created_by'] = (int)$this->userSettings['account_id'];

			$orderId = $this->repository->create($body);

			$response->getBody()->write(json_encode($this->orderToJson($orderId)));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality-orders/{id}",
	 *     summary="Get a hospitality order with lines",
	 *     tags={"Hospitality Orders"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Order details"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int)$args['id'];
			$data = $this->repository->getOrderWithLines($id);

			if (!$data) {
				$response->getBody()->write(json_encode(['error' => 'Order not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$model = new HospitalityOrder($data);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Put(
	 *     path="/booking/hospitality-orders/{id}",
	 *     summary="Update a hospitality order",
	 *     tags={"Hospitality Orders"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Order updated"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function update(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$existing = $this->repository->getById($id);

			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Order not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];
			$body['modified_by'] = (int)$this->userSettings['account_id'];

			// Validate location if being changed
			if (!empty($body['location_resource_id'])) {
				$validLocations = $this->hospitalityRepository->getDeliveryLocations((int)$existing['hospitality_id']);
				$locationIds = array_column($validLocations, 'id');
				if (!in_array((int)$body['location_resource_id'], array_map('intval', $locationIds))) {
					$response->getBody()->write(json_encode(['error' => 'Invalid delivery location for this hospitality']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}

			$this->repository->update($id, $body);

			$response->getBody()->write(json_encode($this->orderToJson($id)));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Patch(
	 *     path="/booking/hospitality-orders/{id}/status",
	 *     summary="Update order status with transition validation",
	 *     tags={"Hospitality Orders"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"status"},
	 *         @OA\Property(property="status", type="string", enum={"pending", "confirmed", "cancelled", "delivered"})
	 *     )),
	 *     @OA\Response(response=200, description="Status updated"),
	 *     @OA\Response(response=400, description="Invalid transition"),
	 *     @OA\Response(response=403, description="Permission denied")
	 * )
	 */
	public function updateStatus(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$existing = $this->repository->getById($id);

			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Order not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (empty($body['status'])) {
				$response->getBody()->write(json_encode(['error' => 'status is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$newStatus = $body['status'];
			if (!HospitalityOrder::isValidStatus($newStatus)) {
				$response->getBody()->write(json_encode([
					'error' => 'Invalid status. Must be one of: ' . implode(', ', HospitalityOrder::VALID_STATUSES),
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$currentStatus = $existing['status'];
			$allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
			if (!in_array($newStatus, $allowed, true)) {
				$response->getBody()->write(json_encode([
					'error' => "Cannot transition from '{$currentStatus}' to '{$newStatus}'",
					'allowed_transitions' => $allowed,
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$this->repository->updateStatus($id, $newStatus, (int)$this->userSettings['account_id']);

			$response->getBody()->write(json_encode($this->orderToJson($id)));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/booking/hospitality-orders/{id}",
	 *     summary="Delete a hospitality order and its lines",
	 *     tags={"Hospitality Orders"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Order deleted"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function destroy(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::DELETE, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$existing = $this->repository->getById($id);

			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Order not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$this->repository->delete($id, (int)$this->userSettings['account_id']);

			$response->getBody()->write(json_encode(['message' => 'Order cancelled']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	// -- Order Lines --

	/**
	 * @OA\Post(
	 *     path="/booking/hospitality-orders/{id}/lines",
	 *     summary="Add a line to an order (snapshots current effective price)",
	 *     tags={"Hospitality Orders"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"hospitality_article_id"},
	 *         @OA\Property(property="hospitality_article_id", type="integer"),
	 *         @OA\Property(property="quantity", type="number", format="float")
	 *     )),
	 *     @OA\Response(response=201, description="Line added"),
	 *     @OA\Response(response=400, description="Validation error"),
	 *     @OA\Response(response=403, description="Permission denied")
	 * )
	 */
	public function addLine(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::ADD, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$orderId = (int)$args['id'];
			$existing = $this->repository->getById($orderId);

			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Order not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (empty($body['hospitality_article_id'])) {
				$response->getBody()->write(json_encode(['error' => 'hospitality_article_id is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Snapshot effective pricing from the article system
			$pricing = $this->articleRepository->resolveEffectivePricing((int)$body['hospitality_article_id']);
			if (!$pricing) {
				$response->getBody()->write(json_encode(['error' => 'Hospitality article not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			if ($pricing['effective_price'] === null) {
				$response->getBody()->write(json_encode(['error' => 'Article has no price configured']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$lineData = [
				'order_id' => $orderId,
				'hospitality_article_id' => (int)$body['hospitality_article_id'],
				'quantity' => $body['quantity'] ?? 1,
				'unit_price' => $pricing['effective_price'],
				'tax_code' => $pricing['effective_tax_code'],
			];

			$this->repository->addOrderLine($lineData);

			$response->getBody()->write(json_encode($this->orderToJson($orderId)));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Put(
	 *     path="/booking/hospitality-orders/{id}/lines/{lineId}",
	 *     summary="Update an order line (quantity)",
	 *     tags={"Hospitality Orders"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="lineId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         @OA\Property(property="quantity", type="number", format="float")
	 *     )),
	 *     @OA\Response(response=200, description="Line updated"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function updateLine(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$orderId = (int)$args['id'];
			$lineId = (int)$args['lineId'];

			$existing = $this->repository->getById($orderId);
			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Order not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Verify line belongs to this order
			$lines = $this->repository->getOrderLines($orderId);
			$lineExists = false;
			foreach ($lines as $line) {
				if ((int)$line['id'] === $lineId) {
					$lineExists = true;
					break;
				}
			}
			if (!$lineExists) {
				$response->getBody()->write(json_encode(['error' => 'Order line not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];
			$this->repository->updateOrderLine($lineId, $body);

			$response->getBody()->write(json_encode($this->orderToJson($orderId)));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/booking/hospitality-orders/{id}/lines/{lineId}",
	 *     summary="Delete an order line",
	 *     tags={"Hospitality Orders"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="lineId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Line deleted"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function destroyLine(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::DELETE, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$orderId = (int)$args['id'];
			$lineId = (int)$args['lineId'];

			$existing = $this->repository->getById($orderId);
			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Order not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Verify line belongs to this order
			$lines = $this->repository->getOrderLines($orderId);
			$lineExists = false;
			foreach ($lines as $line) {
				if ((int)$line['id'] === $lineId) {
					$lineExists = true;
					break;
				}
			}
			if (!$lineExists) {
				$response->getBody()->write(json_encode(['error' => 'Order line not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$this->repository->deleteOrderLine($lineId);

			$response->getBody()->write(json_encode($this->orderToJson($orderId)));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}
}
