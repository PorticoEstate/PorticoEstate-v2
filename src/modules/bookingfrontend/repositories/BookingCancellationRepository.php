<?php

namespace App\modules\bookingfrontend\repositories;

use App\Database\Db;
use PDO;

/**
 * Data access for the booking cancel-preview and scoped-cancel endpoints.
 *
 * Every query here is a port of a legacy counterpart, reached from
 * bookingfrontend_uibooking::cancel() (bookingfrontend/inc/class.uibooking.inc.php:1265) through
 * $this->bo->so (booking_sobooking) and $this->allocation_bo->so (booking_soallocation). Where
 * this class departs from the legacy SQL the departure is named on the method.
 *
 * Sibling of AllocationCancellationRepository - deliberately not shared with it. A booking's
 * ownership and its match-query both key off group_id/organization_id, not the allocation's own
 * organization_id column, and the two repositories stay separate so that difference can never be
 * papered over by a shared query.
 */
class BookingCancellationRepository
{
	private $db;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	/**
	 * The booking row, by id, with its resource ids and the organization its group belongs to.
	 *
	 * The guard reads group_id and application_id off this row, so this is the only place either
	 * value may come from. organization_id is resolved through bb_group - a booking carries no
	 * organization_id column of its own.
	 *
	 * @return array|null null when no such booking exists
	 */
	public function getBooking(int $id): ?array
	{
		$sql = "SELECT b.id, b.group_id, b.application_id, b.allocation_id, b.from_, b.to_,
					   b.season_id, b.active, b.completed, b.building_name,
					   g.organization_id, g.name AS group_name,
					   o.name AS organization_name,
					   s.name AS season_name, s.from_ AS season_from, s.to_ AS season_to,
					   s.building_id AS building_id
				FROM bb_booking b
				LEFT JOIN bb_group g ON g.id = b.group_id
				LEFT JOIN bb_organization o ON o.id = g.organization_id
				LEFT JOIN bb_season s ON s.id = b.season_id
				WHERE b.id = :id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row)
		{
			return null;
		}

		$row['resources'] = $this->getResourceIds($id);

		return $row;
	}

	/**
	 * The resource ids attached to a booking, ascending so the set is order-stable.
	 *
	 * @return int[]
	 */
	public function getResourceIds(int $bookingId): array
	{
		$sql = "SELECT resource_id FROM bb_booking_resource
				WHERE booking_id = :id ORDER BY resource_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $bookingId]);

		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * Resource names for the ids given, keyed by id. Presentation only.
	 *
	 * @param int[] $resourceIds
	 * @return array<int,string>
	 */
	public function getResourceNames(array $resourceIds): array
	{
		if (empty($resourceIds))
		{
			return [];
		}

		$placeholders = [];
		$params = [];
		foreach (array_values($resourceIds) as $i => $resourceId)
		{
			$placeholders[] = ':r' . $i;
			$params[':r' . $i] = (int)$resourceId;
		}

		$sql = "SELECT id, name FROM bb_resource WHERE id IN (" . implode(',', $placeholders) . ")";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		$names = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$names[(int)$row['id']] = $row['name'];
		}

		return $names;
	}

	/**
	 * The booking occupying one occurrence slot of the series, or null.
	 *
	 * Port of booking_sobooking::get_booking_id: exact equality on from_, to_, group_id and
	 * season_id, joined to bb_booking_resource on a resource-overlap EXISTS - not a subset match,
	 * so a booking is matched if ANY of its resources appears in the walked resource set. That is
	 * legacy's join shape, preserved as-is.
	 *
	 * Binds every value; the legacy query interpolates them into the SQL string. Adds
	 * `ORDER BY bb.id LIMIT 1` where legacy has an undefined-order LIMIT 1, for the same reason
	 * AllocationCancellationRepository::findOccurrenceAllocationId does: a deterministic pick from
	 * the same candidate set, not a change to which rows are eligible.
	 *
	 * @param int[] $resourceIds
	 */
	public function findOccurrenceBookingId(
		string $from,
		string $to,
		int $groupId,
		int $seasonId,
		array $resourceIds
	): ?int
	{
		if (empty($resourceIds))
		{
			return null;
		}

		$placeholders = [];
		$params = [
			':from_' => $from,
			':to_' => $to,
			':group_id' => $groupId,
			':season_id' => $seasonId,
		];
		foreach (array_values($resourceIds) as $i => $resourceId)
		{
			$placeholders[] = ':r' . $i;
			$params[':r' . $i] = (int)$resourceId;
		}

		$sql = "SELECT bb.id
				FROM bb_booking bb
				JOIN bb_booking_resource bbr ON bb.id = bbr.booking_id
				WHERE bb.from_ = :from_
				  AND bb.to_ = :to_
				  AND bb.group_id = :group_id
				  AND bb.season_id = :season_id
				  AND EXISTS (
					  SELECT 1 FROM bb_booking_resource bbr2
					  WHERE bbr2.booking_id = bb.id AND bbr2.resource_id IN (" . implode(',', $placeholders) . ")
				  )
				ORDER BY bb.id
				LIMIT 1";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		$id = $stmt->fetchColumn();

		return $id === false ? null : (int)$id;
	}

	/**
	 * A "bare" allocation matching this occurrence's slot that carries NO booking at all, or null.
	 *
	 * Port of booking_sobooking::check_for_booking($booking) - a DIFFERENT query than
	 * booking_soallocation::check_for_booking($id) (which AllocationCancellationRepository ports
	 * as getBlockingBookings). This one runs the OTHER direction: given a would-be booking's
	 * from_/to_/organization_id/season_id/resources, it finds an allocation that matches and has
	 * no booking under it yet. Legacy's recurring-delete loop calls this when no booking exists
	 * for an occurrence, so a scoped delete_allocation can still clean up a stray unbooked
	 * allocation left over in the scope.
	 */
	public function findBareAllocationId(
		string $from,
		string $to,
		int $organizationId,
		int $seasonId,
		array $resourceIds
	): ?int
	{
		if (empty($resourceIds))
		{
			return null;
		}

		$placeholders = [];
		$params = [
			':from_' => $from,
			':to_' => $to,
			':organization_id' => $organizationId,
			':season_id' => $seasonId,
		];
		foreach (array_values($resourceIds) as $i => $resourceId)
		{
			$placeholders[] = ':r' . $i;
			$params[':r' . $i] = (int)$resourceId;
		}

		$sql = "SELECT ba.id
				FROM bb_allocation ba
				WHERE ba.from_ = :from_
				  AND ba.to_ = :to_
				  AND ba.organization_id = :organization_id
				  AND ba.season_id = :season_id
				  AND EXISTS (
					  SELECT 1 FROM bb_allocation_resource bar
					  WHERE bar.allocation_id = ba.id AND bar.resource_id IN (" . implode(',', $placeholders) . ")
				  )
				  AND NOT EXISTS (SELECT 1 FROM bb_booking bk WHERE bk.allocation_id = ba.id)
				ORDER BY ba.id
				LIMIT 1";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		$id = $stmt->fetchColumn();

		return $id === false ? null : (int)$id;
	}

	/**
	 * The allocation_id a booking is currently filed against, or null.
	 *
	 * Kept separate from getBooking() so the series walk can look this up per matched occurrence
	 * without paying for the join to bb_group/bb_organization/bb_season it does not need there.
	 */
	public function getAllocationIdForBooking(int $bookingId): ?int
	{
		$stmt = $this->db->prepare("SELECT allocation_id FROM bb_booking WHERE id = :id");
		$stmt->execute([':id' => $bookingId]);
		$value = $stmt->fetchColumn();

		return ($value === false || $value === null) ? null : (int)$value;
	}

	/**
	 * How many bookings currently sit on this allocation - every one, not just the caller's own.
	 *
	 * Port of booking_sobooking::check_allocation's predicate in its counting form: legacy asks
	 * "does this allocation have fewer than 2 bookings" (i.e. is the booking being deleted the
	 * only one). This returns the count itself so the service can decide "0 remain after I delete
	 * mine" without re-deriving the legacy off-by-one; a cascade is eligible when this count is 1
	 * (the caller's own booking, about to be removed) or already 0.
	 */
	public function countBookingsOnAllocation(int $allocationId): int
	{
		$sql = "SELECT COUNT(*) FROM bb_booking WHERE allocation_id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $allocationId]);

		return (int)$stmt->fetchColumn();
	}

	/**
	 * Hard-delete one booking and everything that hangs off it.
	 *
	 * Port of booking_sobooking::delete_booking, same tables in the same order:
	 * bb_booking_cost, bb_booking_resource, bb_booking_targetaudience, bb_booking_agegroup, the
	 * bb_completed_reservation (+ _resource) whose export_file_id IS NULL, then bb_booking itself.
	 *
	 * Does NOT manage a transaction; the caller wraps the whole scope in one, exactly as
	 * AllocationCancellationRepository::deleteAllocation does.
	 */
	public function deleteBooking(int $id): void
	{
		$stmt = $this->db->prepare("DELETE FROM bb_booking_cost WHERE booking_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM bb_booking_resource WHERE booking_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM bb_booking_targetaudience WHERE booking_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM bb_booking_agegroup WHERE booking_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare(
			"SELECT id FROM bb_completed_reservation
			 WHERE reservation_id = :id AND reservation_type = 'booking' AND export_file_id IS NULL"
		);
		$stmt->execute([':id' => $id]);
		$completedReservationId = $stmt->fetchColumn();

		if ($completedReservationId)
		{
			$stmt = $this->db->prepare(
				"DELETE FROM bb_completed_reservation_resource WHERE completed_reservation_id = :id"
			);
			$stmt->execute([':id' => (int)$completedReservationId]);

			$stmt = $this->db->prepare("DELETE FROM bb_completed_reservation WHERE id = :id");
			$stmt->execute([':id' => (int)$completedReservationId]);
		}

		$stmt = $this->db->prepare("DELETE FROM bb_booking WHERE id = :id");
		$stmt->execute([':id' => $id]);
	}

	/**
	 * Hard-delete one allocation and everything that hangs off it.
	 *
	 * Identical port to AllocationCancellationRepository::deleteAllocation, duplicated rather than
	 * shared: this repository is the sibling that deletes bookings first, and keeping its own copy
	 * means neither class's cascade behaviour can shift by editing the other.
	 */
	public function deleteAllocation(int $id): void
	{
		$stmt = $this->db->prepare("DELETE FROM bb_allocation_cost WHERE allocation_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM bb_allocation_resource WHERE allocation_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare(
			"SELECT id FROM bb_completed_reservation
			 WHERE reservation_id = :id AND reservation_type = 'allocation' AND export_file_id IS NULL"
		);
		$stmt->execute([':id' => $id]);
		$completedReservationId = $stmt->fetchColumn();

		if ($completedReservationId)
		{
			$stmt = $this->db->prepare(
				"DELETE FROM bb_completed_reservation_resource WHERE completed_reservation_id = :id"
			);
			$stmt->execute([':id' => (int)$completedReservationId]);

			$stmt = $this->db->prepare("DELETE FROM bb_completed_reservation WHERE id = :id");
			$stmt->execute([':id' => (int)$completedReservationId]);
		}

		$stmt = $this->db->prepare("DELETE FROM bb_allocation WHERE id = :id");
		$stmt->execute([':id' => $id]);
	}

	public function beginTransaction(): void
	{
		if (!$this->db->inTransaction())
		{
			$this->db->beginTransaction();
		}
	}

	public function commit(): void
	{
		if ($this->db->inTransaction())
		{
			$this->db->commit();
		}
	}

	public function rollBack(): void
	{
		if ($this->db->inTransaction())
		{
			$this->db->rollBack();
		}
	}
}
