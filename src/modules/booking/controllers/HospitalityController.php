<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\repositories\HospitalityArticleRepository;
use App\modules\booking\models\Hospitality;
use App\modules\booking\models\HospitalityRemoteLocation;
use App\modules\booking\models\HospitalityArticleGroup;
use App\modules\booking\models\HospitalityArticle;
use App\modules\bookingfrontend\helpers\WebSocketHelper;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class HospitalityController
{
	protected array $userSettings;
	protected Acl $acl;
	protected HospitalityRepository $repository;
	protected HospitalityArticleRepository $articleRepository;

	public function __construct(ContainerInterface $container)
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->acl = Acl::getInstance();
		$this->repository = new HospitalityRepository();
		$this->articleRepository = new HospitalityArticleRepository();
	}

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality",
	 *     summary="List all hospitality entities",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="active_only", in="query", @OA\Schema(type="boolean")),
	 *     @OA\Response(response=200, description="List of hospitality entities")
	 * )
	 */
	public function index(Request $request, Response $response): Response
	{
		try {
			$params = $request->getQueryParams();
			$activeOnly = !empty($params['active_only']);

			$rows = $this->repository->getAll($activeOnly);
			$results = array_map(function ($row) {
				$model = new Hospitality($row);
				return $model->serialize();
			}, $rows);

			$response->getBody()->write(json_encode($results));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/booking/hospitality",
	 *     summary="Create a new hospitality entity",
	 *     tags={"Hospitality"},
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"resource_id", "name"},
	 *         @OA\Property(property="resource_id", type="integer"),
	 *         @OA\Property(property="name", type="string"),
	 *         @OA\Property(property="description", type="string"),
	 *         @OA\Property(property="active", type="integer"),
	 *         @OA\Property(property="remote_serving_enabled", type="integer"),
	 *         @OA\Property(property="include_in_checkout_payment", type="integer"),
	 *         @OA\Property(property="order_by_time_value", type="integer"),
	 *         @OA\Property(property="order_by_time_unit", type="string", enum={"hours", "days"})
	 *     )),
	 *     @OA\Response(response=201, description="Created"),
	 *     @OA\Response(response=400, description="Bad request"),
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

			if (empty($body['resource_id']) || empty($body['name'])) {
				$response->getBody()->write(json_encode(['error' => 'resource_id and name are required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$body['created_by'] = (int)$this->userSettings['account_id'];

			$id = $this->repository->create($body);
			$row = $this->repository->getById($id);
			$model = new Hospitality($row);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality/{id}",
	 *     summary="Get a hospitality entity with full details",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Hospitality details"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int)$args['id'];
			$row = $this->repository->getById($id);

			if (!$row) {
				$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$model = new Hospitality($row);

			// Enrich with remote locations
			$remoteRows = $this->repository->getRemoteLocations($id);
			$model->remote_locations = array_map(function ($r) {
				return (new HospitalityRemoteLocation($r))->serialize();
			}, $remoteRows);

			// Enrich with article groups
			$groupRows = $this->articleRepository->getGroupsByHospitality($id);
			$model->article_groups = array_map(function ($g) {
				$group = new HospitalityArticleGroup($g);
				$articleRows = $this->articleRepository->getArticlesByGroup((int)$g['id']);
				$group->articles = array_map(function ($a) {
					return (new HospitalityArticle($a))->serialize();
				}, $articleRows);
				return $group->serialize();
			}, $groupRows);

			// Enrich with all articles
			$articleRows = $this->articleRepository->getArticlesByHospitality($id);
			$model->articles = array_map(function ($a) {
				return (new HospitalityArticle($a))->serialize();
			}, $articleRows);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Put(
	 *     path="/booking/hospitality/{id}",
	 *     summary="Update a hospitality entity",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Updated"),
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
				$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Conflict detection via X-If-Modified-Since header
			$ifModifiedSince = $request->getHeaderLine('X-If-Modified-Since');
			if ($ifModifiedSince && !empty($existing['modified'])) {
				$clientTs = strtotime($ifModifiedSince);
				$serverTs = strtotime($existing['modified']);
				if ($clientTs && $serverTs && $clientTs < $serverTs) {
					$freshRow = $this->repository->getById($id);
					$freshModel = new Hospitality($freshRow);
					$response->getBody()->write(json_encode([
						'error' => 'CONFLICT',
						'message' => 'Entity was modified by another user',
						'current' => $freshModel->serialize()
					]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
				}
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];
			$accountId = (int)$this->userSettings['account_id'];
			$body['modified_by'] = $accountId;

			$this->repository->update($id, $body);
			$row = $this->repository->getById($id);
			$model = new Hospitality($row);

			// Broadcast update via WebSocket
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Hospitality updated', 'updated',
				['section' => 'details', 'changedFields' => array_keys($body), 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/booking/hospitality/{id}",
	 *     summary="Delete a hospitality entity",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Deleted"),
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
				$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$this->repository->delete($id);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Hospitality deleted', 'deleted',
				['modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode(['message' => 'Hospitality deleted']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	// -- Remote Locations --

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality/{id}/remote-locations",
	 *     summary="List remote locations for a hospitality",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="List of remote locations")
	 * )
	 */
	public function remoteLocations(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int)$args['id'];
			$existing = $this->repository->getById($id);

			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$rows = $this->repository->getRemoteLocations($id);
			$results = array_map(function ($r) {
				return (new HospitalityRemoteLocation($r))->serialize();
			}, $rows);

			$response->getBody()->write(json_encode($results));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/booking/hospitality/{id}/remote-locations",
	 *     summary="Add a remote location to a hospitality",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"resource_id"},
	 *         @OA\Property(property="resource_id", type="integer")
	 *     )),
	 *     @OA\Response(response=201, description="Remote location added"),
	 *     @OA\Response(response=403, description="Permission denied")
	 * )
	 */
	public function addRemoteLocation(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::ADD, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$existing = $this->repository->getById($id);

			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (empty($body['resource_id'])) {
				$response->getBody()->write(json_encode(['error' => 'resource_id is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$this->repository->addRemoteLocation($id, (int)$body['resource_id']);

			$rows = $this->repository->getRemoteLocations($id);
			$results = array_map(function ($r) {
				return (new HospitalityRemoteLocation($r))->serialize();
			}, $rows);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Remote location added', 'updated',
				['section' => 'resources', 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode($results));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/booking/hospitality/{id}/remote-locations/{resourceId}",
	 *     summary="Remove a remote location from a hospitality",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="resourceId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Remote location removed"),
	 *     @OA\Response(response=403, description="Permission denied")
	 * )
	 */
	public function removeRemoteLocation(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::DELETE, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$resourceId = (int)$args['resourceId'];

			$this->repository->removeRemoteLocation($id, $resourceId);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Remote location removed', 'updated',
				['section' => 'resources', 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode(['message' => 'Remote location removed']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Patch(
	 *     path="/booking/hospitality/{id}/remote-locations/{resourceId}",
	 *     summary="Toggle active status of a remote location",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="resourceId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"active"},
	 *         @OA\Property(property="active", type="boolean")
	 *     )),
	 *     @OA\Response(response=200, description="Remote location toggled"),
	 *     @OA\Response(response=403, description="Permission denied")
	 * )
	 */
	public function toggleRemoteLocation(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$resourceId = (int)$args['resourceId'];

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (!array_key_exists('active', $body)) {
				$response->getBody()->write(json_encode(['error' => 'active field is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$this->repository->toggleRemoteLocation($id, $resourceId, (bool)$body['active']);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Remote location toggled', 'updated',
				['section' => 'resources', 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode(['message' => 'Remote location updated']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality/{id}/relevant-applications",
	 *     summary="Get applications with bookings on delivery locations",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="List of relevant applications"),
	 *     @OA\Response(response=404, description="Hospitality not found")
	 * )
	 */
	public function relevantApplications(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int)$args['id'];
			$existing = $this->repository->getById($id);

			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$applications = $this->repository->getRelevantApplications($id);

			$response->getBody()->write(json_encode($applications));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality/{id}/delivery-locations",
	 *     summary="Get valid delivery locations (main + active remotes)",
	 *     tags={"Hospitality"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="List of delivery locations")
	 * )
	 */
	public function deliveryLocations(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int)$args['id'];
			$existing = $this->repository->getById($id);

			if (!$existing) {
				$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$locations = $this->repository->getDeliveryLocations($id);

			$response->getBody()->write(json_encode($locations));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}
}
