<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\repositories\HospitalityArticleRepository;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\services\HospitalityDirectBookingGuard;
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
	protected HospitalityOrderRepository $orderRepository;
	protected HospitalityDirectBookingGuard $directBookingGuard;

	public function __construct(ContainerInterface $container)
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->acl = Acl::getInstance();
		$this->repository = new HospitalityRepository();
		$this->articleRepository = new HospitalityArticleRepository();
		$this->orderRepository = new HospitalityOrderRepository();
		$this->directBookingGuard = new HospitalityDirectBookingGuard();
	}

	/**
	 * 422 for a save that would link a resource with direct booking to hospitality. 422, not 409:
	 * 409 + error=CONFLICT is the stale-version answer the admin UI special-cases.
	 *
	 * @param array{code: string, error: string, resource_ids: int[]} $refusal from HospitalityDirectBookingGuard
	 */
	private function refuseDirectBookingConflict(Response $response, array $refusal): Response
	{
		$response->getBody()->write(json_encode($refusal));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
	}

	/**
	 * Reads `active` ONCE, to 0|1, and writes that back into the body, so the guard and the repository
	 * both get the same value: the guard has to judge what the row will hold. (bb_hospitality.active is
	 * a smallint, and Postgres stores the raw string "01" or "+1" as 1.) Only true/false, 1/0 and "1"/"0"
	 * are accepted (HospitalityDirectBookingGuard::normaliseActive); anything else is refused, not guessed.
	 *
	 * An absent or null `active` is dropped from the body: create then takes the column default (1) and
	 * update leaves the column alone, so a rename never switches a hospitality off.
	 *
	 * @param array<string, mixed> $body
	 * @return bool false when `active` is present but is not a flag ($body is then left as it was)
	 */
	private function normaliseActive(array &$body): bool
	{
		if (($body['active'] ?? null) === null) {
			unset($body['active']);
			return true;
		}
		$active = HospitalityDirectBookingGuard::normaliseActive($body['active']);
		if ($active === null) {
			return false;
		}
		$body['active'] = $active;
		return true;
	}

	/**
	 * 422 for an `active` that is not a flag. The generic «Field %1: Invalid format» text, already
	 * translated in no/nn/en. Its key has spaces, so it cannot take a `booking.` prefix and is looked up
	 * in the current module, like the legacy forms do.
	 */
	private function refuseInvalidActive(Response $response): Response
	{
		$response->getBody()->write(json_encode([
			'code' => 'invalid_active',
			'error' => lang('Field %1: Invalid format', lang('booking.active')),
		]));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
	}

	/**
	 * Does the body carry a description that is too long (Hospitality::descriptionTooLong)? Judged only when
	 * the body has the key: the admin saves one field per request, so an offer whose stored text predates
	 * the limit still gets every other field saved, and the limit bites when the description itself is edited.
	 *
	 * @param array<string, mixed> $body
	 */
	public static function descriptionRefused(array $body): bool
	{
		return array_key_exists('description', $body) && Hospitality::descriptionTooLong($body['description']);
	}

	/**
	 * 422 for a description over Hospitality::DESCRIPTION_MAX_LENGTH, in the admin's language.
	 */
	private function refuseDescriptionTooLong(Response $response): Response
	{
		$response->getBody()->write(json_encode([
			'code' => 'description_too_long',
			'error' => lang('booking.hospitality_description_too_long', Hospitality::DESCRIPTION_MAX_LENGTH),
		]));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
	}

	/**
	 * Reads `resource_id` ONCE, to an int, and writes that back into the body, so the guard and the repository
	 * both get the same value: the guard has to judge the resource the row will hold. (bb_hospitality.resource_id
	 * is an integer column, and Postgres 16 and later read the string "0x5D" or "9_3" as 93 where PHP's (int)
	 * reads 0 and 9.) Only a plain decimal integer is accepted (HospitalityDirectBookingGuard::normaliseResourceId);
	 * anything else is refused, not guessed.
	 *
	 * An absent key stays absent: update then leaves the resource alone (store() has already answered 400 for it).
	 * A key that is PRESENT is never treated as absent: null is refused too, because the column is NOT NULL and a
	 * null is a client bug, not a request for a default (unlike `active`, which has a column default).
	 *
	 * @param array<string, mixed> $body
	 * @return bool false when `resource_id` is present but is not a resource id ($body is then left as it was)
	 */
	private function normaliseResourceId(array &$body): bool
	{
		if (!array_key_exists('resource_id', $body)) {
			return true;
		}
		$resourceId = HospitalityDirectBookingGuard::normaliseResourceId($body['resource_id']);
		if ($resourceId === null) {
			return false;
		}
		$body['resource_id'] = $resourceId;
		return true;
	}

	/**
	 * 422 for a `resource_id` that is not a resource id (invalid_resource_id) or names no resource
	 * (unknown_resource: without this a positive id that is no resource reaches the foreign key and comes back as a
	 * 500 carrying the raw database message). The generic «Field %1: Invalid format» text, already translated in
	 * no/nn/en, labelled with the main resource; its key has spaces, so it cannot take a `booking.` prefix and is
	 * looked up in the current module, like the legacy forms do.
	 */
	private function refuseResourceId(Response $response, string $code): Response
	{
		return $this->refuse($response, [
			'code' => $code,
			'error' => lang('Field %1: Invalid format', lang('booking.main_resource')),
		]);
	}

	/** 422 with a JSON refusal. 422, not 409: 409 + error=CONFLICT is the stale-version answer the admin UI special-cases. */
	private function refuse(Response $response, array $refusal): Response
	{
		$response->getBody()->write(json_encode($refusal));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
	}

	/**
	 * RULING Q1 (operator): moving the main resource while the hospitality has OPEN orders served at the current one
	 * is refused. Such an order would be left on a location that is no longer a delivery location, and the applicant's
	 * next save of it (Min side) would fail with «Invalid delivery location». What "open" means is decided in ONE
	 * place, HospitalityOrderRepository::countOpenAtLocation. To rule otherwise, change this method only.
	 *
	 * @param array $existing the stored hospitality row
	 * @return array{code: string, error: string, order_count: int}|null null = allowed
	 */
	private function openOrdersAtMainRefusal(array $existing): ?array
	{
		$open = $this->orderRepository->countOpenAtLocation((int)$existing['id'], (int)$existing['resource_id']);
		if ($open === 0) {
			return null;
		}
		return [
			'code' => 'main_resource_has_open_orders',
			'error' => lang('booking.main_resource_has_open_orders', $open),
			'order_count' => $open,
		];
	}

	/**
	 * RULING Q2 (operator): the new main resource must not be an ACTIVE delivery location (remote location) of this
	 * same hospitality. The Resources tab leaves the main resource out of the delivery locations, so the row would
	 * turn invisible while it stays active, and the resource would be listed twice as a delivery location
	 * (as main and as remote). The admin removes it from the delivery locations first. To rule otherwise,
	 * change this method only.
	 *
	 * @param array $existing the stored hospitality row
	 * @param int $newResourceId the (normalised) new main resource
	 * @return array{code: string, error: string, resource_ids: int[]}|null null = allowed
	 */
	private function mainIsRemoteLocationRefusal(array $existing, int $newResourceId): ?array
	{
		if (!$this->directBookingGuard->remoteRowActive((int)$existing['id'], $newResourceId)) {
			return null;
		}
		$name = $this->directBookingGuard->resourcesById([$newResourceId])[$newResourceId]['name'] ?? (string)$newResourceId;
		return [
			'code' => 'main_resource_is_remote_location',
			'error' => lang('booking.main_resource_is_remote_location', $name),
			'resource_ids' => [$newResourceId],
		];
	}

	/**
	 * Has the row been modified after the version the client holds (X-If-Modified-Since)?
	 *
	 * `modified` is stored as naive UTC, so it is read as UTC here instead of in whatever zone PHP
	 * happens to default to. The client's value is what the API sent it (ISO-8601 with an offset)
	 * and carries its own zone; one without an offset is read as UTC too. Anything unparseable or
	 * missing is not a conflict.
	 */
	public static function isStaleVersion(string $ifModifiedSince, ?string $storedModified): bool
	{
		if ($ifModifiedSince === '' || $storedModified === null || $storedModified === '') {
			return false;
		}
		try {
			$utc = new \DateTimeZone('UTC');
			$clientTs = (new \DateTimeImmutable($ifModifiedSince, $utc))->getTimestamp();
			$serverTs = (new \DateTimeImmutable($storedModified, $utc))->getTimestamp();
		} catch (\Exception $e) {
			return false;
		}
		return $clientTs < $serverTs;
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
	 *         @OA\Property(property="description", type="string", maxLength=70, description="At most 70 Unicode code points"),
	 *         @OA\Property(property="active", type="integer"),
	 *         @OA\Property(property="remote_serving_enabled", type="integer"),
	 *         @OA\Property(property="include_in_checkout_payment", type="integer"),
	 *         @OA\Property(property="order_by_time_value", type="integer"),
	 *         @OA\Property(property="order_by_time_unit", type="string", enum={"hours", "days"})
	 *     )),
	 *     @OA\Response(response=201, description="Created"),
	 *     @OA\Response(response=400, description="Bad request"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=422, description="Description over 70 code points (code=description_too_long)")
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

			// resource_id and active are each read ONCE, before the guard and before the repository, so the
			// value the guard judges is the value the row holds.
			if (!$this->normaliseResourceId($body)) {
				return $this->refuseResourceId($response, 'invalid_resource_id');
			}
			if (!$this->normaliseActive($body)) {
				return $this->refuseInvalidActive($response);
			}
			if (!$this->repository->resourceExists($body['resource_id'])) {
				return $this->refuseResourceId($response, 'unknown_resource');
			}

			if (self::descriptionRefused($body)) {
				return $this->refuseDescriptionTooLong($response);
			}

			// A resource with direct booking cannot be linked to hospitality (operator 2026-11-11).
			$refusal = $this->directBookingGuard->checkHospitalityCreate($body);
			if ($refusal !== null) {
				return $this->refuseDirectBookingConflict($response, $refusal);
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

			// Enrich with remote locations, and mark the links that are existing direct-booking conflicts
			$remoteRows = $this->repository->getRemoteLocations($id);
			$conflicts = $this->directBookingGuard->conflictFlagsForHospitality($row, $remoteRows);
			$model->direct_booking_conflict = $conflicts['main'];
			$model->remote_locations = array_map(function ($r) use ($conflicts) {
				$r['direct_booking_conflict'] = $conflicts['remote'][(int)$r['resource_id']] ?? false;
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
	 *     @OA\Response(response=404, description="Not found"),
	 *     @OA\Response(response=422, description="Description over 70 code points (code=description_too_long); judged only when the body carries description")
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
			if (self::isStaleVersion($ifModifiedSince, $existing['modified'] ?? null)) {
				$freshRow = $this->repository->getById($id);
				$freshModel = new Hospitality($freshRow);
				$response->getBody()->write(json_encode([
					'error' => 'CONFLICT',
					'message' => 'Entity was modified by another user',
					'current' => $freshModel->serialize()
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			// resource_id and active are each read ONCE, before the guard and before the repository, so the
			// value the guard judges is the value the row holds.
			if (!$this->normaliseResourceId($body)) {
				return $this->refuseResourceId($response, 'invalid_resource_id');
			}
			if (!$this->normaliseActive($body)) {
				return $this->refuseInvalidActive($response);
			}
			if (array_key_exists('resource_id', $body) && !$this->repository->resourceExists($body['resource_id'])) {
				return $this->refuseResourceId($response, 'unknown_resource');
			}

			if (self::descriptionRefused($body)) {
				return $this->refuseDescriptionTooLong($response);
			}

			// Moving the hospitality to a resource, or re-activating it, links resources to it again:
			// refuse when one of them has direct booking. Any other edit (name, deadlines, flags)
			// of a hospitality that is already in conflict still saves.
			$refusal = $this->directBookingGuard->checkHospitalityUpdate($existing, $body);
			if ($refusal !== null) {
				return $this->refuseDirectBookingConflict($response, $refusal);
			}

			// A real move of the main resource (the key may also arrive unchanged, from a client that sends the whole
			// row). Two rulings by the operator, each in one method: see openOrdersAtMainRefusal() and
			// mainIsRemoteLocationRefusal().
			$mainResourceChanged = array_key_exists('resource_id', $body) && $body['resource_id'] !== (int)$existing['resource_id'];
			if ($mainResourceChanged) {
				$refusal = $this->mainIsRemoteLocationRefusal($existing, $body['resource_id'])
					?? $this->openOrdersAtMainRefusal($existing);
				if ($refusal !== null) {
					return $this->refuse($response, $refusal);
				}
			}

			$accountId = (int)$this->userSettings['account_id'];
			$body['modified_by'] = $accountId;

			$this->repository->update($id, $body);
			$row = $this->repository->getById($id);
			$model = new Hospitality($row);
			// The UI merges this response over what it holds: keep the warning flag in it.
			$model->direct_booking_conflict = $this->directBookingGuard->conflictFlagsForHospitality($row, [])['main'];

			// Broadcast update via WebSocket. A moved main resource is also the Resources tab's main section and
			// its leave-the-main-out filter, so the other admins' pages are told to rebuild that tab too.
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Hospitality updated', 'updated',
				['section' => $mainResourceChanged ? 'main_resource' : 'details', 'changedFields' => array_keys($body), 'modifiedBy' => $accountId]
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

			// A delivery location with direct booking cannot be attached (operator 2026-11-11).
			$refusal = $this->directBookingGuard->checkRemoteLocation($existing, (int)$body['resource_id'], true);
			if ($refusal !== null) {
				return $this->refuseDirectBookingConflict($response, $refusal);
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

			// Re-activating a delivery location attaches it again; switching it off is the remedy and is never refused.
			$refusal = $this->directBookingGuard->checkRemoteLocation($this->repository->getById($id), $resourceId, (bool)$body['active']);
			if ($refusal !== null) {
				return $this->refuseDirectBookingConflict($response, $refusal);
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
