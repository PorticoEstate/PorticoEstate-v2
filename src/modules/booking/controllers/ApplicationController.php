<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\booking\models\Application;
use App\modules\booking\models\ApplicationComment;
use App\modules\booking\models\Date;
use App\modules\booking\models\Document;
use App\modules\booking\models\Order;
use App\modules\booking\repositories\ApplicationRepository;
use App\modules\booking\services\ApplicationService;
use App\helpers\ResponseHelper;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Exception;

/**
 * REST API controller for booking applications (admin).
 *
 * Core application data is returned by show().
 * Sub-resources each have their own endpoint.
 */
class ApplicationController
{
	protected int $currentAccountId;
	protected ApplicationRepository $repo;
	protected ApplicationService $service;

	public function __construct(ContainerInterface $container)
	{
		$userSettings = Settings::getInstance()->get('user');
		$this->currentAccountId = (int) ($userSettings['account_id'] ?? 0);
		$this->repo = new ApplicationRepository();
		$this->service = new ApplicationService($this->repo);
	}

	private function getApplicationId(array $args): int
	{
		return (int) ($args['id'] ?? 0);
	}

	private function httpCode(\Throwable $e): int
	{
		$code = (int) $e->getCode();
		return ($code >= 400 && $code < 600) ? $code : 500;
	}

	// ── GET /booking/applications/{id} ─────────────────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}",
	 *     summary="Get full application data with toolbar metadata",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Application data including computed fields, organization, recurring data, and toolbar metadata",
	 *         @OA\JsonContent(type="object")
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=404, description="Application not found"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$row = $this->repo->getById($id);
			if (!$row) {
				return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);
			}

			// Hydrate model with table columns
			$app = new Application($row);

			// Computed fields
			if (!empty($row['activity_id'])) {
				$app->activity_name = $this->repo->fetchActivityName((int) $row['activity_id']);
			}
			if (!empty($row['case_officer_id'])) {
				$app->case_officer_name = $this->repo->fetchAccountName((int) $row['case_officer_id']);
				$app->case_officer_is_current_user = ((int) $row['case_officer_id'] === $this->currentAccountId);
			}

			$app->num_associations = $this->repo->countAssociations($id);

			$relatedInfo = $this->repo->getRelatedApplications($id);
			$app->parent_id = $relatedInfo['parent_id'];
			$app->related_application_count = $relatedInfo['total_count'];

			// Is simple booking?
			$resources = $this->repo->fetchResources($id);
			$simple = true;
			foreach ($resources as $r) {
				if (empty($r['simple_booking'])) {
					$simple = false;
					break;
				}
			}

			// Organization
			$organization = null;
			if (!empty($row['customer_organization_number'])) {
				$organization = $this->repo->fetchOrganizationByNumber($row['customer_organization_number']);
			}

			// Recurring data
			$recurringData = null;
			$seasonInfo = null;
			$rawRecurring = $row['recurring_info'] ?? null;
			if (!empty($rawRecurring) && is_string($rawRecurring)) {
				$recurringData = json_decode($rawRecurring, true);
			} elseif (is_array($rawRecurring)) {
				$recurringData = $rawRecurring;
			}
			if (is_array($recurringData) && !empty($row['building_id'])) {
				$dates = $this->repo->fetchDates($id);
				if (!empty($dates)) {
					$season = $this->repo->fetchSeasonInfo((int) $row['building_id'], $dates[0]['from_']);
					if ($season) {
						$seasonInfo = [
							'name'           => $season['name'],
							'from_'          => $season['from_'],
							'to_'            => $season['to_'],
							'is_outseason'   => !empty($recurringData['outseason']),
							'has_custom_end' => !empty($recurringData['repeat_until']),
						];
					}
				}
			}

			$result = $app->serialize();
			$result['simple'] = $simple;
			$result['organization'] = $organization;
			$result['recurring_data'] = $recurringData;
			$result['season_info'] = $seasonInfo;

			// System-wide terms config
			$bookingConfig = $this->repo->fetchBookingConfig();
			$result['application_terms'] = $bookingConfig['application_terms'] ?? '';
			$result['activate_application_articles'] = !empty($bookingConfig['activate_application_articles']);

			// Regulation documents for terms tab
			$resourceIds = array_column($resources, 'id');
			$result['regulation_documents'] = !empty($row['building_id'])
				? $this->repo->fetchRegulationDocuments((int) $row['building_id'], $resourceIds)
				: [];

			// Toolbar metadata
			$isCaseOfficer = (int) ($row['case_officer_id'] ?? 0) === $this->currentAccountId;
			$hasCaseOfficer = !empty($row['case_officer_id']);
			$userSettings = Settings::getInstance()->get('user');
			$messengerEnabled = !empty($userSettings['apps']['messenger']['enabled']);
			$status = $row['status'] ?? '';

			// External archive: check config + user preference
			$archiveMethod = $this->repo->fetchExternalArchiveMethod();
			$archiveUserId = $userSettings['preferences']['common']['archive_user_id'] ?? '';
			$externalArchive = !empty($archiveUserId) ? $archiveMethod : '';

			// Edit URL: always uses current app's own ID (matches legacy edit_link)
			$editUrl = '/?menuaction=booking.uiapplication.edit&id=' . $id;

			$result['toolbar'] = [
				'case_officer_is_current_user' => $isCaseOfficer,
				'has_case_officer'             => $hasCaseOfficer,
				'messenger_enabled'            => $messengerEnabled,
				'show_accept'                  => in_array($status, ['PENDING', 'REJECTED', 'NEWPARTIAL1']),
				'num_associations'             => $app->num_associations,
				'show_reject'                  => $status !== 'REJECTED',
				'display_in_dashboard'         => (int) ($row['display_in_dashboard'] ?? 1),
				'show_edit_selection'           => $relatedInfo['total_count'] > 1,
				'parent_id'                    => $relatedInfo['parent_id'] ?? $id,
				'external_archive'             => $externalArchive,
				'external_archive_key'         => $row['external_archive_key'] ?? '',
				'export_pdf_url'               => '/?menuaction=booking.uiapplication.export_pdf&id=' . $id,
				'edit_url'                     => $editUrl,
				'edit_invoicing_url'           => '/?menuaction=booking.uiapplication.edit&id=' . $id
					. '&selected_app_id=' . ($relatedInfo['parent_id'] ?? $id) . '&only_invoicing=1',
				'dashboard_url'                => '/?menuaction=booking.uidashboard.index',
				'applications_url'             => '/?menuaction=booking.uiapplication.index',
			];

			return ResponseHelper::sendJSONResponse($result, 200, $response);

		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading application: ' . $e->getMessage()],
				500
			);
		}
	}

	// ── POST /booking/applications/{id}/assign ──────────────────────────

	/**
	 * @OA\Post(
	 *     path="/booking/applications/{id}/assign",
	 *     summary="Assign current user as case officer",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Assignment successful",
	 *         @OA\JsonContent(@OA\Property(property="status", type="string", example="ok"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=404, description="Application not found"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function assign(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$this->service->assignCurrentUser($id, $this->currentAccountId);
			return ResponseHelper::sendJSONResponse(['status' => 'ok'], 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/toggle-dashboard ─────────────────

	/**
	 * @OA\Post(
	 *     path="/booking/applications/{id}/toggle-dashboard",
	 *     summary="Toggle display_in_dashboard flag",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Toggle successful",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="status", type="string", example="ok"),
	 *             @OA\Property(property="display_in_dashboard", type="integer", example=0)
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=403, description="Not the assigned case officer"),
	 *     @OA\Response(response=404, description="Application not found"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function toggleDashboard(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$newValue = $this->service->toggleDashboard($id, $this->currentAccountId);
			return ResponseHelper::sendJSONResponse([
				'status' => 'ok',
				'display_in_dashboard' => $newValue,
			], 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/unassign ────────────────────────

	/**
	 * @OA\Post(
	 *     path="/booking/applications/{id}/unassign",
	 *     summary="Unassign current user as case officer",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Unassignment successful",
	 *         @OA\JsonContent(@OA\Property(property="status", type="string", example="ok"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=403, description="Not the assigned case officer"),
	 *     @OA\Response(response=404, description="Application not found"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function unassign(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$this->service->unassignCurrentUser($id, $this->currentAccountId);
			return ResponseHelper::sendJSONResponse(['status' => 'ok'], 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/dates ───────────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/dates",
	 *     summary="Get application dates with collision info",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of dates with resource and collision data",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showDates(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$relatedInfo = $this->repo->getRelatedApplications($id);
			$combinedDates = [];

			foreach ($relatedInfo['application_ids'] as $appId) {
				$app = $this->repo->getById($appId);
				if (!$app) continue;

				$dates = $this->repo->fetchDates($appId);
				$resources = $this->repo->fetchResources($appId);
				$resourceIds = array_column($resources, 'id');
				$resourceNameMap = $this->repo->getResourceNames($resourceIds);

				foreach ($dates as $date) {
					$collision = $this->repo->hasCollision($resourceIds, $date['from_'], $date['to_']);
					$dateModel = new Date($date);
					$serialized = $dateModel->serialize();
					$serialized['resources'] = $resourceIds;
					$serialized['resource_names'] = implode(', ', array_intersect_key($resourceNameMap, array_flip($resourceIds)));
					$serialized['application_id'] = $appId;
					$serialized['application_name'] = $app['name'] ?? '';
					$serialized['collision'] = $collision;
					$combinedDates[] = $serialized;
				}
			}

			usort($combinedDates, fn($a, $b) => strtotime($a['from_']) - strtotime($b['from_']));

			return ResponseHelper::sendJSONResponse($combinedDates, 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/resources ───────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/resources",
	 *     summary="Get application resources",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of resources",
	 *         @OA\JsonContent(
	 *             type="array",
	 *             @OA\Items(
	 *                 @OA\Property(property="id", type="integer"),
	 *                 @OA\Property(property="name", type="string")
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showResources(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$relatedInfo = $this->repo->getRelatedApplications($id);
			$allResourceIds = [];
			foreach ($relatedInfo['application_ids'] as $appId) {
				$resources = $this->repo->fetchResources($appId);
				foreach ($resources as $r) {
					if (!in_array((int) $r['id'], $allResourceIds)) {
						$allResourceIds[] = (int) $r['id'];
					}
				}
			}
			$nameMap = $this->repo->getResourceNames($allResourceIds);
			$result = array_map(fn($rid) => ['id' => $rid, 'name' => $nameMap[$rid] ?? ''], $allResourceIds);

			return ResponseHelper::sendJSONResponse($result, 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/agegroups ───────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/agegroups",
	 *     summary="Get application agegroups",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of agegroups",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showAgegroups(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			return ResponseHelper::sendJSONResponse($this->repo->fetchAgegroups($id), 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/audience ────────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/audience",
	 *     summary="Get application target audience data",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Selected and available audiences",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="selected", type="array", @OA\Items(type="integer")),
	 *             @OA\Property(property="available", type="array", @OA\Items(type="object"))
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showAudience(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$audienceIds = $this->repo->fetchTargetAudienceIds($id);
			$allAudiences = $this->repo->fetchAllAudiences();
			return ResponseHelper::sendJSONResponse([
				'selected'  => $audienceIds,
				'available' => $allAudiences,
			], 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/comments ────────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/comments",
	 *     summary="Get application comments",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of comments",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showComments(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$rows = $this->repo->fetchComments($id);
			$comments = array_map(fn($row) => (new ApplicationComment($row))->serialize(), $rows);
			return ResponseHelper::sendJSONResponse($comments, 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/internal-notes ──────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/internal-notes",
	 *     summary="Get application internal notes",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of internal notes",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showInternalNotes(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			return ResponseHelper::sendJSONResponse($this->repo->fetchInternalNotes($id), 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/documents ───────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/documents",
	 *     summary="Get application documents",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of documents",
	 *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Document"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showDocuments(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$rows = $this->repo->fetchDocuments($id);
			$documents = array_map(function ($row) {
				$doc = (new Document($row, Document::OWNER_APPLICATION))->serialize();
				$doc['download_url'] = '/?menuaction=booking.uidocument_application.download&id=' . $row['id'];
				return $doc;
			}, $rows);
			return ResponseHelper::sendJSONResponse($documents, 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/orders ──────────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/orders",
	 *     summary="Get application purchase orders",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of purchase orders",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showOrders(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$relatedInfo = $this->repo->getRelatedApplications($id);
			$allOrders = [];

			foreach ($relatedInfo['application_ids'] as $appId) {
				$rows = $this->repo->fetchOrders($appId);
				$orders = array_map(fn($row) => (new Order($row))->serialize(), $rows);
				foreach ($orders as &$order) {
					$order['application_id'] = $appId;
				}
				unset($order);
				$allOrders = array_merge($allOrders, $orders);
			}

			return ResponseHelper::sendJSONResponse($allOrders, 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/associations ────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/associations",
	 *     summary="Get application associations",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of associations",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showAssociations(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			// Aggregate associations across all related applications (matches legacy)
			$relatedInfo = $this->repo->getRelatedApplications($id);
			$allAssociations = [];
			foreach ($relatedInfo['application_ids'] as $appId) {
				$assocs = $this->repo->fetchAssociations($appId);
				foreach ($assocs as $a) {
					$allAssociations[] = $a;
				}
			}
			return ResponseHelper::sendJSONResponse($allAssociations, 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── DELETE /booking/applications/{id}/associations/{assocId} ─────────

	public function deleteAssociation(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		$assocId = (int) ($args['assocId'] ?? 0);
		if (!$id || !$assocId) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application or association ID'], 400);
		}

		// Verify case officer
		$app = $this->repo->getById($id);
		if (!$app) {
			return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);
		}
		if ((int) ($app['case_officer_id'] ?? 0) !== $this->currentAccountId) {
			return ResponseHelper::sendErrorResponse(['error' => 'Only the case officer can delete associations'], 403);
		}

		$body = json_decode((string) $request->getBody(), true) ?: [];
		$type = $body['type'] ?? '';
		if (!in_array($type, ['allocation', 'booking', 'event'])) {
			return ResponseHelper::sendErrorResponse(['error' => 'Invalid association type'], 400);
		}

		try {
			$success = $this->repo->deactivateAssociation($type, $assocId);
			if (!$success) {
				return ResponseHelper::sendErrorResponse(['error' => 'Association not found'], 404);
			}
			return ResponseHelper::sendJSONResponse(['status' => 'ok'], 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/recurring-preview ────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/recurring-preview",
	 *     summary="Get recurring allocation preview with conflict detection",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Recurring preview data with items, counts, season info",
	 *         @OA\JsonContent(type="object")
	 *     ),
	 *     @OA\Response(response=400, description="Application has no recurring data"),
	 *     @OA\Response(response=404, description="Application not found"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function recurringPreview(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$preview = $this->service->generateRecurringPreview($id);
			return ResponseHelper::sendJSONResponse($preview, 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/create-recurring-allocations ──

	/**
	 * @OA\Post(
	 *     path="/booking/applications/{id}/create-recurring-allocations",
	 *     summary="Create allocations for recurring application dates",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Creation summary with created/failed lists",
	 *         @OA\JsonContent(type="object")
	 *     ),
	 *     @OA\Response(response=403, description="Not the case officer"),
	 *     @OA\Response(response=404, description="Application not found"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function createRecurringAllocations(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		// Verify the current user is the case officer
		$app = $this->repo->getById($id);
		if (!$app) {
			return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);
		}
		if ((int) ($app['case_officer_id'] ?? 0) !== $this->currentAccountId) {
			return ResponseHelper::sendErrorResponse(['error' => 'Only the case officer can create recurring allocations'], 403);
		}

		try {
			$result = $this->service->createRecurringAllocations($id);
			return ResponseHelper::sendJSONResponse($result, 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/comment ────────────────────────

	public function addComment(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		$body = json_decode((string) $request->getBody(), true) ?: [];
		$comment = trim($body['comment'] ?? '');
		if ($comment === '') {
			return ResponseHelper::sendErrorResponse(['error' => 'Comment is required'], 400);
		}

		// Verify case officer
		$app = $this->repo->getById($id);
		if (!$app) {
			return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);
		}
		if ((int) ($app['case_officer_id'] ?? 0) !== $this->currentAccountId) {
			return ResponseHelper::sendErrorResponse(['error' => 'Only the case officer can reply'], 403);
		}

		try {
			$this->service->addComment($id, $this->currentAccountId, $comment);
			return ResponseHelper::sendJSONResponse(['status' => 'ok'], 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/internal-note ───────────────────

	public function addInternalNote(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		$body = json_decode((string) $request->getBody(), true) ?: [];
		$content = trim($body['content'] ?? '');
		if ($content === '') {
			return ResponseHelper::sendErrorResponse(['error' => 'Content is required'], 400);
		}

		// Verify case officer
		$app = $this->repo->getById($id);
		if (!$app) {
			return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);
		}
		if ((int) ($app['case_officer_id'] ?? 0) !== $this->currentAccountId) {
			return ResponseHelper::sendErrorResponse(['error' => 'Only the case officer can add notes'], 403);
		}

		try {
			$this->service->addInternalNote($id, $this->currentAccountId, $content);
			return ResponseHelper::sendJSONResponse(['status' => 'ok'], 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/accept ──────────────────────────

	public function accept(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		// Verify case officer
		$app = $this->repo->getById($id);
		if (!$app) {
			return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);
		}
		if ((int) ($app['case_officer_id'] ?? 0) !== $this->currentAccountId) {
			return ResponseHelper::sendErrorResponse(['error' => 'Only the case officer can accept'], 403);
		}

		$body = json_decode((string) $request->getBody(), true) ?: [];
		$message = trim($body['message'] ?? '');
		$sendEmail = !isset($body['send_email']) || $body['send_email'] === true;

		try {
			$result = $this->service->acceptApplication($id, $this->currentAccountId, $message, $sendEmail);
			return ResponseHelper::sendJSONResponse($result, 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/reject ──────────────────────────

	public function reject(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		// Verify case officer
		$app = $this->repo->getById($id);
		if (!$app) {
			return ResponseHelper::sendErrorResponse(['error' => 'Application not found'], 404);
		}
		if ((int) ($app['case_officer_id'] ?? 0) !== $this->currentAccountId) {
			return ResponseHelper::sendErrorResponse(['error' => 'Only the case officer can reject'], 403);
		}

		$body = json_decode((string) $request->getBody(), true) ?: [];
		$reason = trim($body['reason'] ?? '');
		if ($reason === '') {
			return ResponseHelper::sendErrorResponse(['error' => 'Rejection reason is required'], 400);
		}
		$sendEmail = !isset($body['send_email']) || $body['send_email'] === true;

		try {
			$this->service->rejectApplication($id, $this->currentAccountId, $reason, $sendEmail);
			return ResponseHelper::sendJSONResponse(['status' => 'ok'], 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/message ─────────────────────────

	public function sendMessage(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		$body = json_decode((string) $request->getBody(), true) ?: [];
		$subject = trim($body['subject'] ?? '');
		$content = trim($body['content'] ?? '');
		if ($content === '') {
			return ResponseHelper::sendErrorResponse(['error' => 'Message content is required'], 400);
		}

		try {
			$this->service->sendMessage($id, $this->currentAccountId, $subject, $content);
			return ResponseHelper::sendJSONResponse(['status' => 'ok'], 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── POST /booking/applications/{id}/reassign ────────────────────────

	public function reassign(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		$body = json_decode((string) $request->getBody(), true) ?: [];
		$newUserId = (int) ($body['user_id'] ?? 0);
		if ($newUserId === 0) {
			return ResponseHelper::sendErrorResponse(['error' => 'User ID is required'], 400);
		}

		try {
			$this->service->reassignCaseOfficer($id, $this->currentAccountId, $newUserId);
			return ResponseHelper::sendJSONResponse(['status' => 'ok'], 200, $response);
		} catch (RuntimeException $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], $this->httpCode($e));
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/related ─────────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/related",
	 *     summary="Get related (combined) applications",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of related applications with dates and resources",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     ),
	 *     @OA\Response(response=400, description="Missing application ID"),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showRelated(Request $request, Response $response, array $args): Response
	{
		$id = $this->getApplicationId($args);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing application ID'], 400);
		}

		try {
			$relatedInfo = $this->repo->getRelatedApplications($id);
			if ($relatedInfo['total_count'] <= 1) {
				return ResponseHelper::sendJSONResponse([], 200, $response);
			}

			$result = [];
			foreach ($relatedInfo['application_ids'] as $appId) {
				$row = $this->repo->getById($appId);
				if (!$row) continue;

				$app = new Application($row);
				$appDates = $this->repo->fetchDates($appId);
				$appResources = $this->repo->fetchResources($appId);

				$serialized = $app->serialize([], true); // short serialization
				$serialized['date_ranges'] = array_map(
					fn($d) => (new Date($d))->serialize(),
					$appDates
				);
				$serialized['resource_names'] = array_column($appResources, 'name');
				$serialized['equipment'] = $row['equipment'] ?? '';
				$serialized['description'] = $row['description'] ?? '';

				$result[] = $serialized;
			}

			return ResponseHelper::sendJSONResponse($result, 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}

	// ── GET /booking/applications/{id}/user-list ───────────────────────

	/**
	 * @OA\Get(
	 *     path="/booking/applications/{id}/user-list",
	 *     summary="Get users available for case officer assignment",
	 *     tags={"Applications"},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Application ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of users",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     ),
	 *     @OA\Response(response=500, description="Server error")
	 * )
	 */
	public function showUserList(Request $request, Response $response, array $args): Response
	{
		try {
			return ResponseHelper::sendJSONResponse($this->repo->fetchUserList(), 200, $response);
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 500);
		}
	}
}
