<?php

namespace App\modules\bookingfrontend\controllers\applications;

use App\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\ApplicationHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\services\applications\ApplicationService;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\repositories\HospitalityArticleRepository;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\models\Hospitality;
use App\modules\booking\models\HospitalityArticleGroup;
use App\modules\booking\models\HospitalityArticle;
use App\modules\booking\models\HospitalityOrder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Exception;

class HospitalityOrderController
{
	private ApplicationHelper $applicationHelper;
	private ApplicationService $applicationService;
	private UserHelper $userHelper;
	private HospitalityRepository $hospitalityRepo;
	private HospitalityArticleRepository $articleRepo;
	private HospitalityOrderRepository $orderRepo;

	public function __construct()
	{
		$this->applicationHelper = new ApplicationHelper();
		$this->applicationService = new ApplicationService();
		$this->userHelper = new UserHelper();
		$this->hospitalityRepo = new HospitalityRepository();
		$this->articleRepo = new HospitalityArticleRepository();
		$this->orderRepo = new HospitalityOrderRepository();
	}

	/**
	 * GET /bookingfrontend/applications/{id}/hospitalities
	 * Returns active hospitalities serving the application's booked resources.
	 */
	public function getAvailableHospitalities(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$applicationId = (int)$args['id'];

			$application = $this->applicationService->getApplicationById($applicationId);
			if (!$application) {
				return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404, $response);
			}

			if (!$this->applicationHelper->canViewApplication($application, $request)) {
				return ResponseHelper::sendErrorResponse(['error' => 'Unauthorized'], 403, $response);
			}

			// Get resource IDs from the application
			$resourceIds = $this->getApplicationResourceIds($applicationId);
			if (empty($resourceIds)) {
				return ResponseHelper::sendJSONResponse([], 200, $response);
			}

			$hospitalities = $this->hospitalityRepo->getActiveByResourceIds($resourceIds);

			// Enrich each hospitality with delivery locations relevant to this application
			foreach ($hospitalities as &$h) {
				$h['delivery_locations'] = $this->hospitalityRepo->getDeliveryLocations((int)$h['id']);
			}

			return ResponseHelper::sendJSONResponse($hospitalities, 200, $response);
		} catch (Exception $e) {
			error_log("Error in getAvailableHospitalities: " . $e->getMessage());
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to retrieve hospitalities'], 500, $response);
		}
	}

	/**
	 * GET /bookingfrontend/hospitality/{id}/menu
	 * Returns article groups with nested articles (active only), effective pricing.
	 */
	public function getMenu(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$hospitalityId = (int)$args['id'];

			$hospitality = $this->hospitalityRepo->getById($hospitalityId);
			if (!$hospitality || !$hospitality['active']) {
				return ResponseHelper::sendErrorResponse(['error' => 'Hospitality not found'], 404, $response);
			}

			// Get article groups with nested articles (active only)
			$groupRows = $this->articleRepo->getGroupsByHospitality($hospitalityId);
			$groups = [];
			foreach ($groupRows as $g) {
				if (!(int)$g['active']) {
					continue;
				}
				$group = (new HospitalityArticleGroup($g))->serialize();
				$articleRows = $this->articleRepo->getArticlesByGroup((int)$g['id']);
				$group['articles'] = [];
				foreach ($articleRows as $a) {
					if (!(int)$a['active']) {
						continue;
					}
					$article = (new HospitalityArticle($a))->serialize();
					$pricing = $this->articleRepo->resolveEffectivePricing((int)$a['id']);
					if ($pricing) {
						$article['effective_price'] = $pricing['effective_price'];
						$article['effective_tax_code'] = $pricing['effective_tax_code'];
					}
					$group['articles'][] = $article;
				}
				$groups[] = $group;
			}

			// Also get ungrouped articles (active only)
			$allArticles = $this->articleRepo->getArticlesByHospitality($hospitalityId, true);
			$ungroupedArticles = [];
			foreach ($allArticles as $a) {
				if (empty($a['article_group_id'])) {
					$article = (new HospitalityArticle($a))->serialize();
					$pricing = $this->articleRepo->resolveEffectivePricing((int)$a['id']);
					if ($pricing) {
						$article['effective_price'] = $pricing['effective_price'];
						$article['effective_tax_code'] = $pricing['effective_tax_code'];
					}
					$ungroupedArticles[] = $article;
				}
			}

			return ResponseHelper::sendJSONResponse([
				'hospitality_id' => $hospitalityId,
				'hospitality_name' => $hospitality['name'],
				'groups' => $groups,
				'ungrouped_articles' => $ungroupedArticles,
			], 200, $response);
		} catch (Exception $e) {
			error_log("Error in getMenu: " . $e->getMessage());
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to retrieve menu'], 500, $response);
		}
	}

	/**
	 * GET /bookingfrontend/applications/{id}/hospitality-orders
	 * Returns all hospitality orders for this application (with lines, totals).
	 */
	public function getOrders(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$applicationId = (int)$args['id'];

			$application = $this->applicationService->getApplicationById($applicationId);
			if (!$application) {
				return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404, $response);
			}

			if (!$this->applicationHelper->canViewApplication($application, $request)) {
				return ResponseHelper::sendErrorResponse(['error' => 'Unauthorized'], 403, $response);
			}

			$orders = $this->orderRepo->getOrdersWithLinesByApplication($applicationId);

			return ResponseHelper::sendJSONResponse($orders, 200, $response);
		} catch (Exception $e) {
			error_log("Error in getOrders: " . $e->getMessage());
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to retrieve orders'], 500, $response);
		}
	}

	/**
	 * POST /bookingfrontend/applications/{id}/hospitality-orders
	 * Creates an order with lines in one request.
	 */
	public function createOrder(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$applicationId = (int)$args['id'];

			$application = $this->applicationService->getApplicationById($applicationId);
			if (!$application) {
				return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404, $response);
			}

			if (!$this->applicationHelper->canModifyApplication($application, $request)) {
				return ResponseHelper::sendErrorResponse(['error' => 'Unauthorized'], 403, $response);
			}

			$body = json_decode($request->getBody()->getContents(), true);
			if (!$body) {
				return ResponseHelper::sendErrorResponse(['error' => 'Invalid JSON'], 400, $response);
			}

			// Validate required fields
			if (empty($body['hospitality_id'])) {
				return ResponseHelper::sendErrorResponse(['error' => 'hospitality_id is required'], 400, $response);
			}
			if (empty($body['location_resource_id'])) {
				return ResponseHelper::sendErrorResponse(['error' => 'location_resource_id is required'], 400, $response);
			}
			if (empty($body['lines']) || !is_array($body['lines'])) {
				return ResponseHelper::sendErrorResponse(['error' => 'lines array is required and must not be empty'], 400, $response);
			}

			$hospitalityId = (int)$body['hospitality_id'];
			$locationResourceId = (int)$body['location_resource_id'];

			// Verify hospitality exists and is active
			$hospitality = $this->hospitalityRepo->getById($hospitalityId);
			if (!$hospitality || !$hospitality['active']) {
				return ResponseHelper::sendErrorResponse(['error' => 'Hospitality not found or inactive'], 404, $response);
			}

			// Verify location_resource_id is a valid delivery location
			$deliveryLocations = $this->hospitalityRepo->getDeliveryLocations($hospitalityId);
			$validLocationIds = array_map('intval', array_column($deliveryLocations, 'id'));
			if (!in_array($locationResourceId, $validLocationIds, true)) {
				return ResponseHelper::sendErrorResponse(['error' => 'Invalid delivery location for this hospitality'], 400, $response);
			}

			// Validate serving_time_iso if provided
			if (!empty($body['serving_time_iso'])) {
				$servingTime = strtotime($body['serving_time_iso']);
				if ($servingTime === false) {
					return ResponseHelper::sendErrorResponse(['error' => 'Invalid serving_time_iso format'], 400, $response);
				}
			}

			// Validate each line and resolve pricing
			$resolvedLines = [];
			foreach ($body['lines'] as $i => $line) {
				if (empty($line['hospitality_article_id'])) {
					return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: hospitality_article_id is required"], 400, $response);
				}
				$quantity = isset($line['quantity']) ? (float)$line['quantity'] : 1;
				if ($quantity <= 0) {
					return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: quantity must be positive"], 400, $response);
				}

				$articleId = (int)$line['hospitality_article_id'];
				$pricing = $this->articleRepo->resolveEffectivePricing($articleId);
				if (!$pricing) {
					return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: article not found"], 400, $response);
				}

				// Verify article belongs to this hospitality
				$article = $this->articleRepo->getArticleById($articleId);
				if (!$article || (int)$article['hospitality_id'] !== $hospitalityId) {
					return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: article does not belong to this hospitality"], 400, $response);
				}
				if (!(int)$article['active']) {
					return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: article is inactive"], 400, $response);
				}

				$resolvedLines[] = [
					'hospitality_article_id' => $articleId,
					'quantity' => $quantity,
					'unit_price' => (float)$pricing['effective_price'],
					'tax_code' => (int)$pricing['effective_tax_code'],
					'comment' => $line['comment'] ?? null,
				];
			}

			// Get booking user ID for changelog
			$bookingUserId = $this->getBookingUserId();

			// Create the order
			$orderId = $this->orderRepo->create([
				'application_id' => $applicationId,
				'hospitality_id' => $hospitalityId,
				'location_resource_id' => $locationResourceId,
				'status' => HospitalityOrder::STATUS_PENDING,
				'comment' => $body['comment'] ?? null,
				'special_requirements' => $body['special_requirements'] ?? null,
				'serving_time_iso' => $body['serving_time_iso'] ?? null,
				'created_by' => $bookingUserId,
			]);

			// Add lines
			foreach ($resolvedLines as $lineData) {
				$lineData['order_id'] = $orderId;
				$this->orderRepo->addOrderLine($lineData);
			}

			// Add changelog entry (only if we have a user ID)
			if ($bookingUserId) {
				$this->orderRepo->addChangelogEntry([
					'order_id' => $orderId,
					'booking_user_id' => $bookingUserId,
					'change_type' => 'created',
					'new_value' => [
						'hospitality_id' => $hospitalityId,
						'location_resource_id' => $locationResourceId,
						'lines_count' => count($resolvedLines),
					],
					'comment' => 'Order created via checkout',
				]);
			}

			$order = $this->orderRepo->getOrderWithLines($orderId);

			return ResponseHelper::sendJSONResponse($order, 201, $response);
		} catch (Exception $e) {
			error_log("Error in createOrder: " . $e->getMessage());
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to create order'], 500, $response);
		}
	}

	/**
	 * PUT /bookingfrontend/applications/{id}/hospitality-orders/{orderId}
	 * Update order details and/or replace lines.
	 */
	public function updateOrder(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$applicationId = (int)$args['id'];
			$orderId = (int)$args['orderId'];

			$application = $this->applicationService->getApplicationById($applicationId);
			if (!$application) {
				return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404, $response);
			}

			if (!$this->applicationHelper->canModifyApplication($application, $request)) {
				return ResponseHelper::sendErrorResponse(['error' => 'Unauthorized'], 403, $response);
			}

			$order = $this->orderRepo->getById($orderId);
			if (!$order || (int)$order['application_id'] !== $applicationId) {
				return ResponseHelper::sendErrorResponse(['error' => 'Order not found'], 404, $response);
			}

			if ($order['status'] === HospitalityOrder::STATUS_CANCELLED) {
				return ResponseHelper::sendErrorResponse(['error' => 'Cannot update a cancelled order'], 400, $response);
			}

			$body = json_decode($request->getBody()->getContents(), true);
			if (!$body) {
				return ResponseHelper::sendErrorResponse(['error' => 'Invalid JSON'], 400, $response);
			}

			$bookingUserId = $this->getBookingUserId();

			// Update order fields
			$updateData = [];
			if (array_key_exists('comment', $body)) {
				$updateData['comment'] = $body['comment'];
			}
			if (array_key_exists('special_requirements', $body)) {
				$updateData['special_requirements'] = $body['special_requirements'];
			}
			if (array_key_exists('serving_time_iso', $body)) {
				if (!empty($body['serving_time_iso']) && strtotime($body['serving_time_iso']) === false) {
					return ResponseHelper::sendErrorResponse(['error' => 'Invalid serving_time_iso format'], 400, $response);
				}
				$updateData['serving_time_iso'] = $body['serving_time_iso'];
			}
			if (array_key_exists('location_resource_id', $body)) {
				$locationResourceId = (int)$body['location_resource_id'];
				$deliveryLocations = $this->hospitalityRepo->getDeliveryLocations((int)$order['hospitality_id']);
				$validLocationIds = array_column($deliveryLocations, 'id');
				if (!in_array($locationResourceId, $validLocationIds)) {
					return ResponseHelper::sendErrorResponse(['error' => 'Invalid delivery location'], 400, $response);
				}
				$updateData['location_resource_id'] = $locationResourceId;
			}

			if (!empty($updateData)) {
				$updateData['modified_by'] = $bookingUserId;
				$this->orderRepo->update($orderId, $updateData);
			}

			// Replace lines if provided
			if (isset($body['lines']) && is_array($body['lines'])) {
				if (empty($body['lines'])) {
					return ResponseHelper::sendErrorResponse(['error' => 'lines array must not be empty'], 400, $response);
				}

				$hospitalityId = (int)$order['hospitality_id'];

				// Validate and resolve pricing for new lines
				$resolvedLines = [];
				foreach ($body['lines'] as $i => $line) {
					if (empty($line['hospitality_article_id'])) {
						return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: hospitality_article_id is required"], 400, $response);
					}
					$quantity = isset($line['quantity']) ? (float)$line['quantity'] : 1;
					if ($quantity <= 0) {
						return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: quantity must be positive"], 400, $response);
					}

					$articleId = (int)$line['hospitality_article_id'];
					$pricing = $this->articleRepo->resolveEffectivePricing($articleId);
					if (!$pricing) {
						return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: article not found"], 400, $response);
					}

					$article = $this->articleRepo->getArticleById($articleId);
					if (!$article || (int)$article['hospitality_id'] !== $hospitalityId) {
						return ResponseHelper::sendErrorResponse(['error' => "Line {$i}: article does not belong to this hospitality"], 400, $response);
					}

					$resolvedLines[] = [
						'hospitality_article_id' => $articleId,
						'quantity' => $quantity,
						'unit_price' => (float)$pricing['effective_price'],
						'tax_code' => (int)$pricing['effective_tax_code'],
						'comment' => $line['comment'] ?? null,
					];
				}

				// Delete existing lines
				$existingLines = $this->orderRepo->getOrderLines($orderId);
				foreach ($existingLines as $existingLine) {
					$this->orderRepo->deleteOrderLine((int)$existingLine['id']);
				}

				// Add new lines
				foreach ($resolvedLines as $lineData) {
					$lineData['order_id'] = $orderId;
					$this->orderRepo->addOrderLine($lineData);
				}
			}

			// Add changelog entry (only if we have a user ID)
			if ($bookingUserId) {
				$this->orderRepo->addChangelogEntry([
					'order_id' => $orderId,
					'booking_user_id' => $bookingUserId,
					'change_type' => 'updated',
					'new_value' => array_keys($body),
					'comment' => 'Order updated via checkout',
				]);
			}

			$updatedOrder = $this->orderRepo->getOrderWithLines($orderId);

			return ResponseHelper::sendJSONResponse($updatedOrder, 200, $response);
		} catch (Exception $e) {
			error_log("Error in updateOrder: " . $e->getMessage());
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to update order'], 500, $response);
		}
	}

	/**
	 * DELETE /bookingfrontend/applications/{id}/hospitality-orders/{orderId}
	 * Cancel/remove the order.
	 */
	public function deleteOrder(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$applicationId = (int)$args['id'];
			$orderId = (int)$args['orderId'];

			$application = $this->applicationService->getApplicationById($applicationId);
			if (!$application) {
				return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404, $response);
			}

			if (!$this->applicationHelper->canModifyApplication($application, $request)) {
				return ResponseHelper::sendErrorResponse(['error' => 'Unauthorized'], 403, $response);
			}

			$order = $this->orderRepo->getById($orderId);
			if (!$order || (int)$order['application_id'] !== $applicationId) {
				return ResponseHelper::sendErrorResponse(['error' => 'Order not found'], 404, $response);
			}

			if ($order['status'] === HospitalityOrder::STATUS_CANCELLED) {
				return ResponseHelper::sendErrorResponse(['error' => 'Order is already cancelled'], 400, $response);
			}

			$bookingUserId = $this->getBookingUserId();

			// For NEWPARTIAL1 apps, hard-delete. For finalized apps, soft-cancel.
			if ($application['status'] === 'NEWPARTIAL1') {
				// Hard delete: lines first, then order
				$lines = $this->orderRepo->getOrderLines($orderId);
				foreach ($lines as $line) {
					$this->orderRepo->deleteOrderLine((int)$line['id']);
				}
				// Delete changelog entries
				$this->hardDeleteOrder($orderId);
			} else {
				// Soft cancel
				$this->orderRepo->updateStatus($orderId, HospitalityOrder::STATUS_CANCELLED, $bookingUserId);

				if ($bookingUserId) {
					$this->orderRepo->addChangelogEntry([
						'order_id' => $orderId,
						'booking_user_id' => $bookingUserId,
						'change_type' => 'cancelled',
						'old_value' => ['status' => $order['status']],
						'new_value' => ['status' => HospitalityOrder::STATUS_CANCELLED],
						'comment' => 'Order cancelled via checkout',
					]);
				}
			}

			return ResponseHelper::sendJSONResponse(['message' => 'Order deleted'], 200, $response);
		} catch (Exception $e) {
			error_log("Error in deleteOrder: " . $e->getMessage());
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to delete order'], 500, $response);
		}
	}

	/**
	 * Get resource IDs for an application.
	 */
	private function getApplicationResourceIds(int $applicationId): array
	{
		$db = \App\Database\Db::getInstance();
		$sql = "SELECT resource_id FROM bb_application_resource WHERE application_id = :id";
		$stmt = $db->prepare($sql);
		$stmt->execute([':id' => $applicationId]);
		return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'resource_id');
	}

	/**
	 * Get the booking user ID from the current session SSN.
	 */
	private function getBookingUserId(): ?int
	{
		if ($this->userHelper->ssn) {
			return $this->userHelper->get_user_id($this->userHelper->ssn);
		}
		return null;
	}

	/**
	 * Hard delete an order and its related records (for NEWPARTIAL1 applications).
	 */
	private function hardDeleteOrder(int $orderId): void
	{
		$db = \App\Database\Db::getInstance();

		// Delete changelog entries
		$stmt = $db->prepare("DELETE FROM bb_hospitality_order_changelog WHERE order_id = :id");
		$stmt->execute([':id' => $orderId]);

		// Delete document records
		$stmt = $db->prepare("DELETE FROM bb_hospitality_order_document WHERE owner_id = :id");
		$stmt->execute([':id' => $orderId]);

		// Delete the order itself
		$stmt = $db->prepare("DELETE FROM bb_hospitality_order WHERE id = :id");
		$stmt->execute([':id' => $orderId]);
	}
}
