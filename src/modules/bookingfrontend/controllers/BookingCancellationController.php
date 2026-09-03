<?php

namespace App\modules\bookingfrontend\controllers;

use App\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\BookingAccessHelper;
use App\modules\bookingfrontend\services\BookingCancellationService;
use App\modules\phpgwapi\models\ServerSettings;
use Exception;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * @OA\Tag(
 *     name="Bookings",
 *     description="Cancel-preview and scoped cancellation for a booking series"
 * )
 *
 * Sibling of AllocationController, built the same way. Ported from
 * bookingfrontend_uibooking::cancel() (bookingfrontend/inc/class.uibooking.inc.php:1265) - the
 * legacy method the client's `cancelMode` already models both branches of (see
 * BookingCancellationService's class docblock).
 */
class BookingCancellationController
{
	private BookingCancellationService $cancellationService;
	private BookingAccessHelper $accessHelper;

	public function __construct(ContainerInterface $container)
	{
		$this->cancellationService = new BookingCancellationService();
		$this->accessHelper = new BookingAccessHelper();
	}

	/**
	 * @OA\Post(
	 *     path="/bookingfrontend/bookings/{id}/cancel-preview",
	 *     summary="Walk a booking series and report what a cancellation would do, including any delete_allocation cascade",
	 *     tags={"Bookings"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="secret", in="query", required=false, @OA\Schema(type="string"),
	 *         description="The owning application's secret, for a caller authorised by the emailed link"),
	 *     @OA\RequestBody(
	 *         @OA\JsonContent(
	 *             @OA\Property(property="scope", type="string", enum={"occurrence","season","until"}),
	 *             @OA\Property(property="repeat_until", type="string", format="date"),
	 *             @OA\Property(property="field_interval", type="integer", default=1),
	 *             @OA\Property(property="delete_allocation", type="boolean", default=false,
	 *                 description="Whether a cancel with this same body would also delete each occurrence's parent allocation, where eligible. The preview discloses the cascade either way.")
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="Per-occurrence preview of the series, plus the delete_allocation cascade"),
	 *     @OA\Response(response=400, description="Unusable scope, repeat_until or field_interval"),
	 *     @OA\Response(response=403, description="Caller may not manage this booking"),
	 *     @OA\Response(response=404, description="No such booking")
	 * )
	 *
	 * Read-only, and guarded exactly as the destructive call is. It emits the whole schedule of a
	 * series, the identity of the bookings under it, and which allocations a cascade would remove -
	 * an unguarded preview would be a disclosure of exactly the kind #1210 was raised about.
	 */
	public function cancelPreview(Request $request, Response $response, array $args): Response
	{
		try
		{
			$bodyParams = $this->readBody($request);

			$booking = $this->requireManageableBooking($request, (int)$args['id'], $bodyParams, $error);
			if ($booking === null)
			{
				return $error;
			}

			$options = $this->readOptions($request, $bodyParams);

			return ResponseHelper::sendJSONResponse(
				$this->cancellationService->preview($booking, $options)
			);
		}
		catch (InvalidArgumentException $e)
		{
			return ResponseHelper::sendErrorResponse(['error' => $e->getMessage()], 400);
		}
		catch (Exception $e)
		{
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error previewing booking cancellation: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/bookingfrontend/bookings/{id}/cancel",
	 *     summary="Cancel the occurrences of a booking series within a scope",
	 *     tags={"Bookings"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="secret", in="query", required=false, @OA\Schema(type="string")),
	 *     @OA\RequestBody(
	 *         @OA\JsonContent(
	 *             @OA\Property(property="scope", type="string", enum={"occurrence","season","until"}),
	 *             @OA\Property(property="repeat_until", type="string", format="date"),
	 *             @OA\Property(property="field_interval", type="integer", default=1),
	 *             @OA\Property(property="delete_allocation", type="boolean", default=false),
	 *             @OA\Property(property="confirm_token", type="string",
	 *                 description="The token returned by cancel-preview for this exact set, INCLUDING delete_allocation")
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="What was deleted (bookings and, where cascaded, allocations) and what was spared"),
	 *     @OA\Response(response=400, description="Unusable scope or missing confirm_token"),
	 *     @OA\Response(response=403, description="Caller may not manage this booking"),
	 *     @OA\Response(response=404, description="No such booking"),
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

			$booking = $this->requireManageableBooking($request, (int)$args['id'], $bodyParams, $error);
			if ($booking === null)
			{
				return $error;
			}

			if (!$this->userCanDeleteBookings())
			{
				// Legacy's other branch (:1320-1412) turns this into a REQUEST to a case worker -
				// an admin notification plus a bb_system_message row carrying a mailed link
				// (menuaction=booking.uibooking.delete) that is itself a pre-authorised scoped
				// delete capability. That branch is deliberately not ported: it sends mail this
				// port must not send, and the capability URL is a security-adjacent surface this
				// task discloses rather than rebuilds. Refused explicitly rather than silently
				// deleting nothing.
				//
				// This IS a regression against legacy for the citizen: today, someone who cannot
				// delete a booking directly can still ASK a case worker to (legacy sends that
				// request); this 409 removes that path entirely until a request-mode endpoint is
				// built. See this task's handoff/summary for the explicit call-out.
				return ResponseHelper::sendErrorResponse([
					'error' => 'This installation does not let users delete bookings directly.',
					'is_request_mode' => true,
					'user_can_delete_bookings' => false,
				], 409);
			}

			$options = $this->readOptions($request, $bodyParams);

			return ResponseHelper::sendJSONResponse(
				$this->cancellationService->cancel($booking, $options)
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
				['error' => 'Error cancelling booking: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * Resolve the booking by id FIRST, then judge the caller against the resolved row - same
	 * ordering discipline as AllocationController::requireManageableAllocation and for the same
	 * reason: no timing or content difference between "blocked" and "no booking" may leak set
	 * membership to a caller who was going to be refused.
	 */
	private function requireManageableBooking(
		Request $request,
		int $id,
		array $bodyParams,
		?Response &$error
	): ?array
	{
		$error = null;

		$booking = $this->cancellationService->getBooking($id);
		if ($booking === null)
		{
			$error = ResponseHelper::sendErrorResponse(['error' => 'Booking not found'], 404);
			return null;
		}

		if (!$this->accessHelper->canManageBooking($booking, $request, $bodyParams))
		{
			$error = ResponseHelper::sendErrorResponse(
				['error' => 'Unauthorized to manage this booking'],
				403
			);
			return null;
		}

		return $booking;
	}

	/**
	 * The JSON request body, decoded. Same convention as AllocationController::readBody: this
	 * application registers no body-parsing middleware, so the raw stream is read once here and
	 * threaded to everything that needs it, the guard included.
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
	 * Read the same way ScheduleEntityService::addEditCancelLinks reads its allocation
	 * counterpart, so the affordance the client is offered and the decision the server makes
	 * cannot disagree. Compared against the literal 'yes'; the admin setting's other value 'never'
	 * is more restrictive than 'no' and both fail this test, which is the intended direction.
	 */
	private function userCanDeleteBookings(): bool
	{
		$config = ServerSettings::getInstance(true)->booking_config;

		return ($config->user_can_delete_bookings ?? 'no') === 'yes';
	}
}
