<?php

namespace App\modules\bookingfrontend\repositories;

use App\Database\Db;
use PDO;

/**
 * Data access for the allocation cancel-preview and scoped-cancel endpoints.
 *
 * Every query here is a port of a legacy counterpart in booking_soallocation, reached from
 * bookingfrontend_uiallocation::cancel() through $this->bo->so. Where this class departs from
 * the legacy SQL the departure is named on the method.
 */
class AllocationCancellationRepository
{
	private $db;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	/**
	 * The allocation row, by id, with its resource ids and its season's boundaries.
	 *
	 * The guard reads organization_id and application_id off this row, so this is the only
	 * place either value may come from.
	 *
	 * @return array|null null when no such allocation exists
	 */
	public function getAllocation(int $id): ?array
	{
		$sql = "SELECT a.id, a.organization_id, a.application_id, a.from_, a.to_, a.season_id,
					   a.active, a.completed, a.building_name, a.allocation_group_id,
					   o.name AS organization_name,
					   s.name AS season_name, s.from_ AS season_from, s.to_ AS season_to,
					   s.building_id AS building_id
				FROM bb_allocation a
				LEFT JOIN bb_organization o ON o.id = a.organization_id
				LEFT JOIN bb_season s ON s.id = a.season_id
				WHERE a.id = :id";

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
	 * The resource ids attached to an allocation, ascending so the set is order-stable.
	 *
	 * @return int[]
	 */
	public function getResourceIds(int $allocationId): array
	{
		$sql = "SELECT resource_id FROM bb_allocation_resource
				WHERE allocation_id = :id ORDER BY resource_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $allocationId]);

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
	 * The allocation occupying one occurrence slot of the series, or null.
	 *
	 * Port of booking_soallocation::get_allocation_id: exact equality on from_, to_,
	 * organization_id and season_id, joined to bb_allocation_resource, LIMIT 1.
	 *
	 * TWO DEPARTURES FROM THE LEGACY SQL, both deliberate and neither changing which rows are
	 * eligible:
	 *
	 *  - The legacy query interpolates its values into the SQL string. This one binds them.
	 *  - The legacy query has `resource_id IN (...) LIMIT 1` with no ORDER BY, so on a
	 *    multi-resource allocation the matched row is order-undefined (the same shape as the
	 *    standing open item on soallocation:292). This one adds `ORDER BY ba2.id` so a preview
	 *    and the cancel that follows it resolve the same occurrence to the same row. It picks
	 *    from the same candidate set; it only makes the pick deterministic.
	 *
	 * @param int[] $resourceIds
	 */
	public function findOccurrenceAllocationId(
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

		$sql = "SELECT ba2.id
				FROM bb_allocation ba2
				JOIN bb_allocation_resource bar2 ON ba2.id = bar2.allocation_id
				WHERE ba2.from_ = :from_
				  AND ba2.to_ = :to_
				  AND ba2.organization_id = :organization_id
				  AND ba2.season_id = :season_id
				  AND bar2.resource_id IN (" . implode(',', $placeholders) . ")
				ORDER BY ba2.id
				LIMIT 1";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		$id = $stmt->fetchColumn();

		return $id === false ? null : (int)$id;
	}

	/**
	 * The bookings sitting under an allocation, which is what blocks its cancellation.
	 *
	 * booking_soallocation::check_for_booking is `SELECT id FROM bb_booking WHERE
	 * allocation_id = ($id)` under limit_query(..., 1). It has NO `active` predicate, so an
	 * allocation whose only bookings are inactive still reports blocked - 1,339 allocations
	 * are in that class today.
	 *
	 * THE RULE IS NOT CHANGED HERE. This method returns every booking row the legacy predicate
	 * would have matched, so anything non-empty means blocked exactly as legacy means it. What
	 * it adds is `active` (and the group name the design asks for) per blocker, so a caller can
	 * see that a block is dead without the server having decided that for it. Whether an
	 * inactive booking should stop blocking is a ruling nobody has made; surfacing the column
	 * lets the UI tell the truth under either answer.
	 *
	 * @return array[] one entry per blocking booking, empty when cancellable
	 */
	public function getBlockingBookings(int $allocationId): array
	{
		$sql = "SELECT b.id, b.active, b.group_id, b.from_, b.to_, g.name AS group_name
				FROM bb_booking b
				LEFT JOIN bb_group g ON g.id = b.group_id
				WHERE b.allocation_id = :id
				ORDER BY b.id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $allocationId]);

		$blockers = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$blockers[] = [
				'id' => (int)$row['id'],
				'active' => (int)$row['active'],
				'group_id' => $row['group_id'] === null ? null : (int)$row['group_id'],
				'group_name' => $row['group_name'],
				'from_' => $row['from_'],
				'to_' => $row['to_'],
			];
		}

		return $blockers;
	}

	/**
	 * Hard-delete one allocation and everything that hangs off it.
	 *
	 * Port of booking_soallocation::delete_allocation, same tables in the same order:
	 * bb_allocation_cost, bb_allocation_resource, the bb_completed_reservation (+ _resource)
	 * whose export_file_id IS NULL, then bb_allocation itself.
	 *
	 * ONE DEPARTURE: legacy opens and commits a transaction PER allocation, inside the loop
	 * that is still deciding which occurrences to delete, so a scoped cancel that fails halfway
	 * leaves the series partly deleted. This method does NOT manage a transaction; the caller
	 * wraps the whole scope in one. See AllocationCancellationService::cancel.
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
