<?php

namespace App\modules\bookingfrontend\helpers;

use App\Database\Db;
use PDO;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who may see or cancel an allocation.
 *
 * This is the guard added for #1210 (commit 34b4c9da2, bookingfrontend_uiallocation::cancel()),
 * restated for the Slim4 allocation endpoints. It is deliberately a single implementation:
 * the preview and the destructive call must not be able to drift apart, because they disclose
 * the same series.
 *
 * TWO callers are accepted, because two different pages offer this same affordance:
 *
 * 1. An organization admin of the organization that owns the allocation. Unlike a booking -
 *    whose ownership is indirect, through bb_booking.group_id -> bb_group.organization_id -
 *    an allocation carries bb_allocation.organization_id directly, so is_organization_admin()
 *    is applied to that column with no intermediate resolution.
 *
 * 2. The holder of the emailed application secret, for an allocation of THAT application.
 *    uiapplication::show() authenticates its whole page on the secret alone and offers the
 *    cancel affordance with no ownership predicate. A secret holder is not logged in, and
 *    is_organization_admin() returns false on its first line when nobody is logged in, so
 *    check 1 alone would refuse the very citizen the link was mailed to. The secret is
 *    therefore accepted, but only as narrowly as uiapplication::show() accepts it: compared
 *    against bb_application.secret, and SCOPED to the allocation's own application -
 *    bb_allocation.application_id is read from the ALLOCATION ROW, never from the request.
 *    A valid secret can never reach another application's allocation.
 *
 * Neither check can be delegated to ACL: every bookingfrontend request runs as the shared
 * bookingguest account, so an ACL grant here would apply to every visitor or to none.
 * These two checks are the guard.
 *
 * The PREVIEW needs this as much as the destructive call does. On the legacy page an
 * unguarded read was masked by a crash; a JSON endpoint has nothing to hide behind, and the
 * preview emits the whole schedule of a series, the identity of the bookings blocking it,
 * and the notification recipient set.
 */
class AllocationAccessHelper
{
	private $db;
	private UserHelper $userHelper;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->userHelper = new UserHelper();
	}

	/**
	 * Whether this caller may see and cancel this allocation.
	 *
	 * @param array $allocation The allocation row as read from bb_allocation. Must carry
	 *                          organization_id and application_id as stored.
	 * @param ServerRequestInterface $request The request whose credentials are being judged.
	 * @return bool
	 */
	public function canManageAllocation(
		array $allocation,
		ServerRequestInterface $request,
		array $bodyParams = []
	): bool
	{
		return $this->resolveGrant($allocation, $request, $bodyParams) !== null;
	}

	/**
	 * Why the caller was accepted. Returns null when the caller was refused.
	 *
	 * canManageAllocation is expressed in terms of this so the decision and its attribution
	 * cannot disagree: there is exactly one place where acceptance is decided.
	 */
	public function resolveGrant(
		array $allocation,
		ServerRequestInterface $request,
		array $bodyParams = []
	): ?string
	{
		if ($this->userHelper->is_organization_admin($allocation['organization_id'] ?? null))
		{
			return 'organization_admin';
		}

		if ($this->hasOwningApplicationSecret($allocation, $request, $bodyParams))
		{
			return 'application_secret';
		}

		return null;
	}

	/**
	 * The secret arm, scoped to the allocation's own application.
	 *
	 * The application id compared here is taken from $allocation, which the caller must have
	 * read from bb_allocation by id. A request-supplied application_id is never consulted, so
	 * forging one cannot move the decision.
	 */
	private function hasOwningApplicationSecret(
		array $allocation,
		ServerRequestInterface $request,
		array $bodyParams
	): bool
	{
		$owningApplicationId = $allocation['application_id'] ?? null;
		if (empty($owningApplicationId))
		{
			// An allocation with no application has no secret that could authorise it.
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
	 * The secret as presented by this request, from the query string or the JSON body.
	 *
	 * Both are accepted because the preview and cancel endpoints are POSTs carrying a scope
	 * body, while the secret reaches the client as a query parameter on the emailed link.
	 * Neither source is trusted for anything but the secret itself.
	 *
	 * The body arrives already decoded: this application registers no body-parsing middleware,
	 * so the request body is a stream that can only be read once, and the controller reads it.
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
