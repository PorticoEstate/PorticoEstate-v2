<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use PDO;

/**
 * bb_application_change_request: an applicant's request for a new time on one
 * element (event, later allocation) of an application, decided by the case
 * officer on that element.
 *
 * Times are stored as the element tables store them: from_/to_ naive local,
 * created/modified/resolved_at naive UTC.
 */
class ApplicationChangeRequestRepository
{
	public const OPEN = 'OPEN';
	public const ACCEPTED = 'ACCEPTED';
	public const REJECTED = 'REJECTED';
	public const SUPERSEDED = 'SUPERSEDED';

	private Db $db;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	/**
	 * Record a request for a new time on an element. An open request on the
	 * same element is superseded first, so the case officer decides on the
	 * newest one; the partial unique index allows only one open request per
	 * element. Joins a transaction that is already open, otherwise runs its own.
	 *
	 * @return int the request id
	 */
	public function open(int $applicationId, string $elementType, int $elementId, string $oldFrom, string $oldTo, string $newFrom, string $newTo, ?string $requestedBy): int
	{
		$ownTransaction = !$this->db->get_transaction();
		if ($ownTransaction) {
			$this->db->transaction_begin();
		}

		try {
			$this->supersedeOpen($elementType, $elementId);

			$now = gmdate('Y-m-d H:i:s');
			$stmt = $this->db->prepare(
				"INSERT INTO bb_application_change_request
				 (application_id, element_type, element_id, old_from_, old_to_, new_from_, new_to_, status, requested_by, created, modified)
				 VALUES (:application_id, :element_type, :element_id, :old_from, :old_to, :new_from, :new_to, :status, :requested_by, :created, :modified)"
			);
			$stmt->execute([
				':application_id' => $applicationId,
				':element_type'   => $elementType,
				':element_id'     => $elementId,
				':old_from'       => $oldFrom,
				':old_to'         => $oldTo,
				':new_from'       => $newFrom,
				':new_to'         => $newTo,
				':status'         => self::OPEN,
				':requested_by'   => $requestedBy !== null ? mb_substr($requestedBy, 0, 150) : null,
				':created'        => $now,
				':modified'       => $now,
			]);
			$id = (int) $this->db->lastInsertId();

			if ($ownTransaction) {
				$this->db->transaction_commit();
			}
			return $id;
		} catch (\Throwable $e) {
			if ($ownTransaction) {
				$this->db->transaction_abort();
			}
			throw $e;
		}
	}

	/**
	 * Close the element's open request because a newer change replaced it.
	 *
	 * @return int the number of requests closed (0 or 1)
	 */
	public function supersedeOpen(string $elementType, int $elementId): int
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_application_change_request
			 SET status = :superseded, modified = :modified
			 WHERE element_type = :element_type AND element_id = :element_id AND status = :open"
		);
		$stmt->execute([
			':superseded'   => self::SUPERSEDED,
			':modified'     => gmdate('Y-m-d H:i:s'),
			':element_type' => $elementType,
			':element_id'   => $elementId,
			':open'         => self::OPEN,
		]);
		return $stmt->rowCount();
	}

	/**
	 * Close an open request with the case officer's decision.
	 *
	 * @return bool false when the request was no longer open
	 */
	public function resolve(int $id, string $status, int $accountId): bool
	{
		$now = gmdate('Y-m-d H:i:s');
		$stmt = $this->db->prepare(
			"UPDATE bb_application_change_request
			 SET status = :status, resolved_by = :resolved_by, resolved_at = :resolved_at, modified = :modified
			 WHERE id = :id AND status = :open"
		);
		$stmt->execute([
			':status'      => $status,
			':resolved_by' => $accountId,
			':resolved_at' => $now,
			':modified'    => $now,
			':id'          => $id,
			':open'        => self::OPEN,
		]);
		return $stmt->rowCount() === 1;
	}

	/**
	 * One request, locked for the rest of the transaction.
	 */
	public function lockById(int $id): ?array
	{
		$stmt = $this->db->prepare("SELECT * FROM bb_application_change_request WHERE id = :id FOR UPDATE");
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	/**
	 * The open requests of the given applications, oldest first.
	 *
	 * @param int[] $applicationIds
	 */
	public function findOpenForApplications(array $applicationIds): array
	{
		if (empty($applicationIds)) {
			return [];
		}
		$placeholders = implode(',', array_map('intval', $applicationIds));
		$stmt = $this->db->prepare(
			"SELECT * FROM bb_application_change_request
			 WHERE application_id IN ({$placeholders}) AND status = :open
			 ORDER BY id"
		);
		$stmt->execute([':open' => self::OPEN]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * The open request of each of the given elements of one type, keyed by element id.
	 *
	 * @param int[] $elementIds
	 * @return array<int, array>
	 */
	public function findOpenForElements(string $elementType, array $elementIds): array
	{
		if (empty($elementIds)) {
			return [];
		}
		$placeholders = implode(',', array_map('intval', $elementIds));
		$stmt = $this->db->prepare(
			"SELECT * FROM bb_application_change_request
			 WHERE element_type = :element_type AND element_id IN ({$placeholders}) AND status = :open"
		);
		$stmt->execute([':element_type' => $elementType, ':open' => self::OPEN]);

		$requests = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$requests[(int) $row['element_id']] = $row;
		}
		return $requests;
	}

	// ── The events requests are made on ─────────────────────────────────

	/**
	 * Current time and state of the given events, keyed by id.
	 *
	 * @param int[] $eventIds
	 * @return array<int, array{id:int, application_id:?int, from_:string, to_:string, active:int}>
	 */
	public function fetchEvents(array $eventIds): array
	{
		if (empty($eventIds)) {
			return [];
		}
		$placeholders = implode(',', array_map('intval', $eventIds));
		$stmt = $this->db->prepare(
			"SELECT id, application_id, from_, to_, active FROM bb_event WHERE id IN ({$placeholders})"
		);
		$stmt->execute();

		$events = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$events[(int) $row['id']] = $row;
		}
		return $events;
	}

	/**
	 * One event, locked for the rest of the transaction.
	 */
	public function lockEvent(int $eventId): ?array
	{
		$stmt = $this->db->prepare(
			"SELECT id, application_id, from_, to_, active FROM bb_event WHERE id = :id FOR UPDATE"
		);
		$stmt->execute([':id' => $eventId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	/**
	 * The given events that carry an order line priced per hour. Such a price
	 * depends on the event's length, and a change of time does not recalculate it.
	 *
	 * @param int[] $eventIds
	 * @return int[]
	 */
	public function findEventsWithHourlyPrice(array $eventIds): array
	{
		if (empty($eventIds)) {
			return [];
		}
		$placeholders = implode(',', array_map('intval', $eventIds));
		$stmt = $this->db->prepare(
			"SELECT DISTINCT po.reservation_id
			 FROM bb_purchase_order po
			 JOIN bb_purchase_order_line l ON l.order_id = po.id
			 JOIN bb_article_mapping m ON m.id = l.article_mapping_id
			 WHERE po.reservation_type = 'event' AND po.reservation_id IN ({$placeholders}) AND m.unit = 'hour'"
		);
		$stmt->execute();
		return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'reservation_id'));
	}

	public function updateEventTime(int $eventId, string $from, string $to): void
	{
		$stmt = $this->db->prepare("UPDATE bb_event SET from_ = :from, to_ = :to WHERE id = :id");
		$stmt->execute([':from' => $from, ':to' => $to, ':id' => $eventId]);
	}

	/**
	 * Move the event's date row from the old time to the new one: the row
	 * holding the old time, or, when none does, the event's only row. Events
	 * made from a multi-date application can carry a copy of every date, so a
	 * row is never picked by position among several.
	 *
	 * @return int|null the rows moved (0 for an event without date rows), or
	 *                  null when it has several and none holds the old time
	 */
	public function moveEventDate(int $eventId, string $oldFrom, string $oldTo, string $newFrom, string $newTo): ?int
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_event_date SET from_ = :new_from, to_ = :new_to
			 WHERE event_id = :id AND from_ = :old_from AND to_ = :old_to"
		);
		$stmt->execute([
			':new_from' => $newFrom,
			':new_to'   => $newTo,
			':id'       => $eventId,
			':old_from' => $oldFrom,
			':old_to'   => $oldTo,
		]);
		if ($stmt->rowCount() > 0) {
			return $stmt->rowCount();
		}

		$stmt = $this->db->prepare("SELECT COUNT(*) FROM bb_event_date WHERE event_id = :id");
		$stmt->execute([':id' => $eventId]);
		$rows = (int) $stmt->fetchColumn();
		if ($rows !== 1) {
			return $rows === 0 ? 0 : null;
		}

		$stmt = $this->db->prepare("UPDATE bb_event_date SET from_ = :new_from, to_ = :new_to WHERE event_id = :id");
		$stmt->execute([':new_from' => $newFrom, ':new_to' => $newTo, ':id' => $eventId]);
		return $stmt->rowCount();
	}

	public function updateApplicationDate(int $dateId, int $applicationId, string $from, string $to): void
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_application_date SET from_ = :from, to_ = :to WHERE id = :id AND application_id = :application_id"
		);
		$stmt->execute([':from' => $from, ':to' => $to, ':id' => $dateId, ':application_id' => $applicationId]);
	}

	/**
	 * Keep a not yet exported invoicing row on the event's time, as
	 * soevent::update() does on every update of an event.
	 */
	public function updateCompletedReservationTime(int $eventId, string $from, string $to): void
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_completed_reservation SET from_ = :from, to_ = :to, description = :description
			 WHERE reservation_type = 'event' AND reservation_id = :id AND export_file_id IS NULL"
		);
		$stmt->execute([
			':from'        => $from,
			':to'          => $to,
			':description' => mb_substr($from, 0, -3) . ' - ' . mb_substr($to, 0, -3),
			':id'          => $eventId,
		]);
	}
}
