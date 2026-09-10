<?php

namespace App\modules\bookingfrontend\helpers;

use App\Database\Db;
use PDO;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who may see or cancel a booking.
 *
 * This is the #1210 guard (bookingfrontend_uibooking::cancel(), :1303-1318 of
 * bookingfrontend/inc/class.uibooking.inc.php) restated for the Slim4 booking-cancellation
 * endpoints. Sibling of AllocationAccessHelper, deliberately not reused: a booking carries no
 * organization_id of its own, so its admin check resolves through bb_booking.group_id ->
 * bb_group.organization_id instead of a direct column. Substituting AllocationAccessHelper here
 * would read a column bookings do not have.
 *
 * TWO callers are accepted, matching AllocationAccessHelper's two:
 *
 * 1. An organization admin of the group that owns the booking - is_group_admin($booking['group_id'])
 *    in UserHelper, which looks the group's organization up and delegates to
 *    is_organization_admin(). This is the predicate legacy's cancel() applies at :1314.
 *
 * 2. The holder of the emailed application secret, for a booking of THAT application. Compared
 *    against bb_application.secret and SCOPED to the booking's own application -
 *    bb_booking.application_id is read from the BOOKING ROW, never from the request. A valid
 *    secret can never reach another application's booking.
 *
 * The PREVIEW needs this as much as the destructive call does, for the same reason
 * AllocationAccessHelper's does: it discloses the whole series and the cascade a delete would
 * cause, which is exactly the disclosure #1210 was raised about.
 */
class BookingAccessHelper
{
	private $db;
	private UserHelper $userHelper;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->userHelper = new UserHelper();
	}

	/**
	 * Whether this caller may see and cancel this booking.
	 *
	 * @param array $booking The row from BookingCancellationRepository::getBooking(). Must carry
	 *                       group_id and application_id as stored.
	 */
	public function canManageBooking(
		array $booking,
		ServerRequestInterface $request,
		array $bodyParams = []
	): bool
	{
		return $this->resolveGrant($booking, $request, $bodyParams) !== null;
	}

	/**
	 * Why the caller was accepted. Returns null when the caller was refused.
	 *
	 * canManageBooking is expressed in terms of this so the decision and its attribution cannot
	 * disagree: there is exactly one place where acceptance is decided.
	 */
	public function resolveGrant(
		array $booking,
		ServerRequestInterface $request,
		array $bodyParams = []
	): ?string
	{
		if ($this->userHelper->is_group_admin($booking['group_id'] ?? null))
		{
			return 'group_admin';
		}

		if ($this->hasOwningApplicationSecret($booking, $request, $bodyParams))
		{
			return 'application_secret';
		}

		return null;
	}

	/**
	 * The secret arm, scoped to the booking's own application.
	 *
	 * The application id compared here is taken from $booking, which the caller must have read
	 * from bb_booking by id. A request-supplied application_id is never consulted, so forging one
	 * cannot move the decision.
	 */
	private function hasOwningApplicationSecret(
		array $booking,
		ServerRequestInterface $request,
		array $bodyParams
	): bool
	{
		$owningApplicationId = $booking['application_id'] ?? null;
		if (empty($owningApplicationId))
		{
			// A booking with no application has no secret that could authorise it.
			return false;
		}

		$presented = $this->presentedSecret($request, $bodyParams);
		if ($presented === null || $presented === '')
		{
			return false;
		}

		$sql = "SELECT secret FROM bb_application WHERE id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => (int)$owningApplicationId]);
		$stored = $stmt->fetchColumn();

		if ($stored === false || $stored === null || $stored === '')
		{
			return false;
		}

		return hash_equals((string)$stored, $presented);
	}

	/**
	 * The secret as presented by this request, from the query string or the JSON body. Same
	 * dual-source acceptance as AllocationAccessHelper::presentedSecret, for the same reason: the
	 * emailed link carries it as a query parameter, while these endpoints are POSTs with a scope
	 * body.
	 */
	private function presentedSecret(ServerRequestInterface $request, array $bodyParams): ?string
	{
		$queryParams = $request->getQueryParams();
		if (!empty($queryParams['secret']) && is_string($queryParams['secret']))
		{
			return $queryParams['secret'];
		}

		if (!empty($bodyParams['secret']) && is_string($bodyParams['secret']))
		{
			return $bodyParams['secret'];
		}

		return null;
	}
}
