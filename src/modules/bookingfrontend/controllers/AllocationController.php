<?php

namespace App\modules\bookingfrontend\controllers;

use App\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\AllocationAccessHelper;
use App\modules\bookingfrontend\helpers\ApplicationHelper;
use App\modules\bookingfrontend\services\AllocationCancellationService;
use App\modules\bookingfrontend\services\AllocationWithdrawalService;
use App\modules\bookingfrontend\services\applications\ApplicationService;
use App\modules\phpgwapi\models\ServerSettings;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * @OA\Tag(
 *     name="Allocations",
 *     description="Cancel-preview and scoped cancellation for allocation series"
 * )
 */
class AllocationController
{
	private AllocationCancellationService $cancellationService;
	private AllocationAccessHelper $accessHelper;
	private AllocationWithdrawalService $withdrawalService;
	private ApplicationService $applicationService;
	private ApplicationHelper $applicationHelper;

	public function __construct(ContainerInterface $container)
	{
		$this->cancellationService = new AllocationCancellationService();
		$this->accessHelper = new AllocationAccessHelper();
		$this->withdrawalService = new AllocationWithdrawalService();
		$this->applicationService = new ApplicationService();
		$this->applicationHelper = new ApplicationHelper();
	}

	/**
	 * @OA\Post(
	 *     path="/bookingfrontend/allocations/{id}/cancel-preview",
	 *     summary="Walk an allocation series and report what a cancellation would do",
	 *     tags={"Allocations"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="secret", in="query", required=false, @OA\Schema(type="string"),
	 *         description="The owning application's secret, for a caller authorised by the emailed link"),
	 *     @OA\RequestBody(
	 *         @OA\JsonContent(
	 *             @OA\Property(property="scope", type="string", enum={"occurrence","season","until"}),
	 *             @OA\Property(property="repeat_until", type="string", format="date"),
	 *             @OA\Property(property="field_interval", type="integer", default=1)
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="Per-occurrence preview of the series"),
	 *     @OA\Response(response=400, description="Unusable scope, repeat_until or field_interval"),
	 *     @OA\Response(response=403, description="Caller may not manage this allocation"),
	 *     @OA\Response(response=404, description="No such allocation")
	 * )
	 *
	 * Read-only, and guarded exactly as the destructive call is. It emits the whole schedule of
	 * a series and the identity of the bookings under it, so an unguarded preview would be a
	 * disclosure of the same data #1210 was raised about.
	 */
	public function cancelPreview(Request $request, Response $response, array $args): Response
	{
		try
		{
			$bodyParams = $this->readBody($request);

			$allocation = $this->requireManageableAllocation($request, (int)$args['id'], $bodyParams, $error);
			if ($allocation === null)
			{
				return $error;
			}

			$options = $this->readOptions($request, $bodyParams);

			return ResponseHelper::sendJSONResponse(
				$this->cancellationService->preview($allocation, $options)
			);
		}
		catch (InvalidArgumentException $e)
		{
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 400);
		}
		catch (Exception $e)
		{
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error previewing allocation cancellation: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/bookingfrontend/allocations/{id}/cancel",
	 *     summary="Cancel the occurrences of an allocation series within a scope",
	 *     tags={"Allocations"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="secret", in="query", required=false, @OA\Schema(type="string")),
	 *     @OA\RequestBody(
	 *         @OA\JsonContent(
	 *             @OA\Property(property="scope", type="string", enum={"occurrence","season","until"}),
	 *             @OA\Property(property="repeat_until", type="string", format="date"),
	 *             @OA\Property(property="field_interval", type="integer", default=1),
	 *             @OA\Property(property="confirm_token", type="string",
	 *                 description="The token returned by cancel-preview for this exact set")
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="What was deleted and what was spared"),
	 *     @OA\Response(response=400, description="Unusable scope or missing confirm_token"),
	 *     @OA\Response(response=403, description="Caller may not manage this allocation"),
	 *     @OA\Response(response=404, description="No such allocation"),
	 *     @OA\Response(response=409, description="confirm_token is stale, or the installation is in request mode")
	 * )
	 *
	 * Hard delete: the rows are removed, not flagged.
	 */
	public function cancel(Request $request, Response $response, array $args): Response
	{
		try
		{
			$bodyParams = $this->readBody($request);

			$allocation = $this->requireManageableAllocation($request, (int)$args['id'], $bodyParams, $error);
			if ($allocation === null)
			{
				return $error;
			}

			if (!$this->userCanDeleteAllocations())
			{
				// Legacy's other branch turns this into a REQUEST to a case worker - an admin
				// notification plus a bb_system_message row carrying a link to
				// booking.uiallocation.delete. That branch is deliberately not ported here:
				// it is currently unreachable from the Next client, so exposing it would be new
				// behaviour rather than a port, and it sends mail. Refused explicitly rather
				// than silently deleting nothing.
				return ResponseHelper::sendErrorResponse([
					'error' => 'This installation does not let users delete allocations directly.',
					'is_request_mode' => true,
					'user_can_delete_allocations' => false,
				], 409);
			}

			$options = $this->readOptions($request, $bodyParams);

			return ResponseHelper::sendJSONResponse(
				$this->cancellationService->cancel($allocation, $options)
			);
		}
		catch (InvalidArgumentException $e)
		{
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 400);
		}
		catch (RuntimeException $e)
		{
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 409);
		}
		catch (Exception $e)
		{
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error cancelling allocation: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/bookingfrontend/allocations/{id}/withdraw",
	 *     summary="Withdraw a single allocation occurrence from Min side, with an optional comment",
	 *     tags={"Allocations"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="secret", in="query", required=false, @OA\Schema(type="string"),
	 *         description="The owning application's secret, for a caller authorised by the emailed link"),
	 *     @OA\RequestBody(
	 *         @OA\JsonContent(
	 *             @OA\Property(property="comment", type="string",
	 *                 description="Optional message to the case officer, travels into the staff mail")
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="Withdrawn: the allocation is deactivated and the case officer notified"),
	 *     @OA\Response(response=400, description="Comment too long"),
	 *     @OA\Response(response=403, description="Caller may not manage this allocation"),
	 *     @OA\Response(response=404, description="No such allocation"),
	 *     @OA\Response(response=409, description="Already withdrawn, already started, or has no owning application")
	 * )
	 *
	 * GH #1393 criteria 5+6+7+8. Always exactly ONE occurrence - unlike cancel()/cancelPreview()
	 * this carries no scope selector and no confirm_token: those exist for the admin/calendar
	 * multi-occurrence HARD DELETE flow (AllocationCancellationService), which this deliberately
	 * does not reuse. See AllocationWithdrawalService's docblock for the reuse-hard-delete-vs-trace
	 * tradeoff. Also NOT gated on userCanDeleteAllocations(): that flag governs the destructive
	 * delete flow specifically, and a soft withdrawal that leaves the row in place and notifies
	 * the case officer is not the behaviour it was written to guard.
	 */
	public function withdraw(Request $request, Response $response, array $args): Response
	{
		try
		{
			$bodyParams = $this->readBody($request);

			$allocation = $this->requireManageableAllocation($request, (int)$args['id'], $bodyParams, $error);
			if ($allocation === null)
			{
				return $error;
			}

			if ((int)$allocation['active'] !== 1)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'This timeslot has already been withdrawn'], 409);
			}

			if (new DateTimeImmutable($allocation['from_']) <= new DateTimeImmutable())
			{
				return ResponseHelper::sendErrorResponse(
					['error' => 'This timeslot has already started and can no longer be withdrawn'],
					409
				);
			}

			$applicationId = (int)($allocation['application_id'] ?? 0);
			if ($applicationId <= 0)
			{
				// Guarded already by AllocationAccessHelper: only an allocation WITH an
				// application can ever grant the application_secret arm, so this is reachable
				// only via the organization_admin arm on an application-less allocation - there
				// is nothing to notify.
				return ResponseHelper::sendErrorResponse(['error' => 'This allocation has no application to notify'], 409);
			}

			$comment = isset($bodyParams['comment']) && is_string($bodyParams['comment']) ? trim($bodyParams['comment']) : '';
			if (strlen($comment) > 10000)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Comment is too long (maximum 10000 characters)'], 400);
			}

			$application = $this->applicationService->getApplicationById($applicationId);
			$author = $application ? $this->applicationHelper->resolveCommentAuthor($application, $request) : null;

			$result = $this->withdrawalService->withdraw($allocation, $comment, $author);

			return ResponseHelper::sendJSONResponse($result);
		}
		catch (Exception $e)
		{
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error withdrawing timeslot: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * Resolve the allocation by id FIRST, then judge the caller against the resolved row.
	 *
	 * The guard runs before the scope walk, not inside it, so no timing or content difference
	 * between "blocked" and "no allocation" can leak set membership to a caller who was going
	 * to be refused. Returns null and fills $error when the request must not proceed.
	 */
	private function requireManageableAllocation(
		Request $request,
		int $id,
		array $bodyParams,
		?Response &$error
	): ?array
	{
		$error = null;

		$allocation = $this->cancellationService->getAllocation($id);
		if ($allocation === null)
		{
			$error = ResponseHelper::sendErrorResponse(['error' => 'Allocation not found'], 404);
			return null;
		}

		if (!$this->accessHelper->canManageAllocation($allocation, $request, $bodyParams))
		{
			$error = ResponseHelper::sendErrorResponse(
				['error' => 'Unauthorized to manage this allocation'],
				403
			);
			return null;
		}

		return $allocation;
	}

	/**
	 * The JSON request body, decoded.
	 *
	 * This application registers no body-parsing middleware, so $request->getParsedBody() is
	 * null for a JSON POST and the raw stream must be decoded by hand - the convention every
	 * other bookingfrontend POST controller follows. The stream can only be consumed once, so
	 * this is called exactly once per request and the result is threaded to everything that
	 * needs it, the guard included.
	 */
	private function readBody(Request $request): array
	{
		$raw = (string)$request->getBody();
		if (trim($raw) === '')
		{
			return [];
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * The scope options. Query parameters sit under the body so an emailed link's parameters
	 * still work, but the body wins where both are present.
	 */
	private function readOptions(Request $request, array $bodyParams): array
	{
		return array_merge($request->getQueryParams(), $bodyParams);
	}

	/**
	 * Read the same way ScheduleEntityService::addEditCancelLinks reads it, so the affordance
	 * the client is offered and the decision the server makes cannot disagree.
	 *
	 * Compared against the literal 'yes'. The admin setting has a third option, 'never', which
	 * is more restrictive than 'no'; both fail this test, which is the intended direction.
	 */
	private function userCanDeleteAllocations(): bool
	{
		$config = ServerSettings::getInstance(true)->booking_config;

		return ($config->user_can_delete_allocations ?? 'no') === 'yes';
	}
}
