<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Settings;

/**
 * Port of legacy get_free_events from booking.bobooking
 *
 * This service replicates the exact behavior of the legacy endpoint,
 * including all quirks and known bugs, to ensure backward compatibility.
 */
class FreeTimeService
{
	private $db;
	private string $timezone;
	private \DateTimeZone $dateTimeZone;
	private string $dateformat;
	private bool $debug = false;
	private array $timings = [];
	private float $startTime;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$userSettings = Settings::getInstance()->get('user');
		$this->timezone = !empty($userSettings['preferences']['common']['timezone'])
			? $userSettings['preferences']['common']['timezone'] : 'UTC';
		$this->dateTimeZone = new \DateTimeZone($this->timezone);
		$this->dateformat = !empty($userSettings['preferences']['common']['dateformat'])
			? $userSettings['preferences']['common']['dateformat'] : 'd/m-Y';
	}

	public function setDebug(bool $debug): void
	{
		$this->debug = $debug;
		if ($debug) {
			$this->startTime = microtime(true);
			$this->timings = [];
		}
	}

	public function getDebugInfo(): array
	{
		return [
			'total_ms' => round((microtime(true) - $this->startTime) * 1000, 2),
			'timings' => $this->timings,
			'timezone' => $this->timezone,
		];
	}

	private function tick(string $label): void
	{
		if ($this->debug) {
			$this->timings[] = [
				'label' => $label,
				'elapsed_ms' => round((microtime(true) - $this->startTime) * 1000, 2),
			];
		}
	}

	/**
	 * Calculate the maximum booking horizon end date.
	 * Port of bookingfrontend_uibooking::calculate_booking_horizon_end_date
	 */
	public function calculateBookingHorizonEndDate(int $buildingId, ?int $resourceId = null): int
	{
		$bookingMonthHorizon = 2; // default

		if ($resourceId) {
			$sql = "SELECT booking_month_horizon FROM bb_resource WHERE id = ? AND active = 1";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$resourceId]);
			$row = $stmt->fetch(\PDO::FETCH_ASSOC);
			if ($row && !empty($row['booking_month_horizon'])) {
				if ($row['booking_month_horizon'] > ($bookingMonthHorizon + 1)) {
					$bookingMonthHorizon = $row['booking_month_horizon'] + 1;
				}
			}
		} else {
			$sql = "SELECT r.booking_month_horizon FROM bb_resource r
                    JOIN bb_building_resource br ON br.resource_id = r.id
                    WHERE br.building_id = ? AND r.active = 1";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$buildingId]);
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				if (!empty($row['booking_month_horizon']) && $row['booking_month_horizon'] > ($bookingMonthHorizon + 1)) {
					$bookingMonthHorizon = $row['booking_month_horizon'] + 1;
				}
			}
		}

		$endDate = new \DateTime();
		$endDate->add(new \DateInterval("P{$bookingMonthHorizon}M"));
		$endDate->modify('last day of this month');
		return $endDate->getTimestamp();
	}

	/**
	 * Main entry point - replaces uibooking::get_freetime + bobooking::get_free_events
	 */
	public function getFreeTime(
		int    $buildingId,
		?int   $resourceId,
		string $startDateStr,
		string $endDateStr,
		bool   $detailedOverlap = false,
		bool   $stopOnEndDate = false
	): array
	{
		// Parse dates
		$startTimestamp = strtotime($startDateStr);
		$endTimestamp = strtotime($endDateStr);
		if ($startTimestamp === false || $endTimestamp === false) {
			return [];
		}

		// Apply booking horizon limitations (port of calculate_booking_horizon_end_date)
		$maxEndDate = $this->calculateBookingHorizonEndDate($buildingId, $resourceId);
		if ($startTimestamp > $maxEndDate) {
			return [];
		}
		if ($endTimestamp > $maxEndDate) {
			$endTimestamp = $maxEndDate;
		}

		$startDate = new \DateTime(date('Y-m-d', $startTimestamp), $this->dateTimeZone);
		$endDate = new \DateTime(date('Y-m-d', $endTimestamp), $this->dateTimeZone);

		$this->tick('horizon_check_done');
		return $this->getFreeEvents($buildingId, $resourceId, $startDate, $endDate, $stopOnEndDate, $detailedOverlap);
	}

	/**
	 * Port of bobooking::get_free_events
	 * Replicates every quirk/feature of the original
	 */
	private function getFreeEvents(
		int       $buildingId,
		?int      $resourceId,
		\DateTime $startDate,
		\DateTime $endDate,
		bool      $stopOnEndDate,
		bool      $detailedOverlap
	): array
	{
		$_from = clone $startDate;
		$_from->setTime(0, 0, 0);
		$_to = clone $endDate;
		$_to->setTime(23, 59, 59);

		// Fetch resources
		$this->tick('fetch_resources_start');
		$resources = $this->fetchResources($buildingId, $resourceId);
		$this->tick('fetch_resources_done (' . count($resources) . ' resources)');

		$resourceIds = [];
		$eventIds = [];
		$allocationIds = [];
		$bookingIds = [];

		// Per-resource: calculate date windows and fetch overlapping entity IDs
		foreach ($resources as &$resource) {
			$resourceIds[] = $resource['id'];
			$from = clone $_from;

			// simple_booking_start_date handling
			if ($resource['simple_booking_start_date']) {
				$simpleBookingStartDate = new \DateTime(
					date('Y-m-d H:i', $resource['simple_booking_start_date']),
					$this->dateTimeZone
				);
				$now = new \DateTime('now', $this->dateTimeZone);

				if ($simpleBookingStartDate > $now) {
					$resource['skip_timeslot'] = true;
				}
				if ($simpleBookingStartDate > $_from) {
					$from = clone $simpleBookingStartDate;
				} else {
					$from->setTime(
						(int)$simpleBookingStartDate->format('H'),
						(int)$simpleBookingStartDate->format('i'),
						0
					);
				}
			}

			$to = clone $_to;

			// booking_day_horizon
			if ($resource['booking_day_horizon']) {
				if (!$resource['booking_month_horizon']) {
					$__to = clone $from;
				} else {
					$__to = clone $to;
				}
				$__to->modify("+{$resource['booking_day_horizon']} days");
				$to = clone $__to;
			}

			// booking_month_horizon
			if ($resource['booking_month_horizon']) {
				$__to = $this->monthShifter($from, $resource['booking_month_horizon'], $this->dateTimeZone);
				$to = clone $__to;
				$to->setTime(23, 59, 59);
			}

			// simple_booking_end_date
			if ($resource['simple_booking_end_date']) {
				$simpleBookingEndDate = new \DateTime(date('Y-m-d', $resource['simple_booking_end_date']));
				$simpleBookingEndDate->setTimezone($this->dateTimeZone);
				if ($simpleBookingEndDate < $to) {
					$to = clone $simpleBookingEndDate;
				}
				$to->setTime(23, 59, 59);
			}

			// Fetch overlapping entity IDs (only for simple_booking resources)
			if ($resource['simple_booking'] && empty($resource['skip_timeslot'])) {
				$eventIds = array_merge($eventIds, $this->eventIdsForResource($resource['id'], $_from, $to));
				$allocationIds = array_merge($allocationIds, $this->allocationIdsForResource($resource['id'], $from, $to));
				$bookingIds = array_merge($bookingIds, $this->bookingIdsForResource($resource['id'], $from, $to));
			}

			$resource['from'] = $from;
			if ($resource['booking_time_default_end'] > -1) {
				$to->setTime($resource['booking_time_default_end'], 0, 0);
			}
			$resource['to'] = $to;
		}
		unset($resource);

		// Fetch full entity data
		$this->tick('fetch_entities_start (events=' . count($eventIds) . ' alloc=' . count($allocationIds) . ' book=' . count($bookingIds) . ')');
		$events = $this->fetchEntities('event', $eventIds);
		$allocations = $this->fetchEntities('allocation', $allocationIds);
		$bookings = $this->fetchEntities('booking', $bookingIds);
		$this->tick('fetch_entities_done');

		// Get partials and blocks
		$this->tick('fetch_partials_start');
		$this->getPartials($events, $resourceIds);
		$this->tick('fetch_partials_done');

		// Combine all into events
		$events['results'] = array_merge(
			(array)($events['results'] ?? []),
			(array)($allocations['results'] ?? []),
			(array)($bookings['results'] ?? [])
		);

		// Generate timeslots
		$this->tick('generate_slots_start');
		$result = $this->generateTimeSlots($resources, $events, $buildingId, $_to, $stopOnEndDate, $detailedOverlap);
		$this->tick('generate_slots_done (' . array_sum(array_map('count', $result)) . ' total slots)');
		return $result;
	}

	/**
	 * Fetch active simple_booking resources for a building or specific resource
	 */
	private function fetchResources(int $buildingId, ?int $resourceId): array
	{
		if ($resourceId) {
			$sql = "SELECT r.* FROM bb_resource r
                    JOIN bb_rescategory rc ON rc.id = r.rescategory_id
                    WHERE r.id = ? AND r.active = 1 AND rc.active = 1";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$resourceId]);
		} else {
			$sql = "SELECT r.* FROM bb_resource r
                    JOIN bb_building_resource br ON br.resource_id = r.id
                    JOIN bb_rescategory rc ON rc.id = r.rescategory_id
                    WHERE br.building_id = ? AND r.active = 1 AND rc.active = 1
                    ORDER BY r.sort";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$buildingId]);
		}
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Port of sobooking::event_ids_for_resource
	 */
	private function eventIdsForResource(int $resourceId, \DateTime $start, \DateTime $end): array
	{
		$startStr = $start->format('Y-m-d H:i');
		$endStr = $end->format('Y-m-d H:i');

		$sql = "SELECT id FROM bb_event
                JOIN bb_event_resource ON (event_id = id AND resource_id = ?)
                WHERE active = 1
                AND ((from_ >= ? AND from_ < ?) OR (to_ > ? AND to_ <= ?) OR (from_ < ? AND to_ > ?))";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([$resourceId, $startStr, $endStr, $startStr, $endStr, $startStr, $endStr]);

		return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id');
	}

	/**
	 * Port of sobooking::allocation_ids_for_resource
	 */
	private function allocationIdsForResource(int $resourceId, \DateTime $start, \DateTime $end): array
	{
		$startStr = $start->format('Y-m-d H:i');
		$endStr = $end->format('Y-m-d H:i');

		$sql = "SELECT DISTINCT bb_allocation.id AS id
                FROM bb_allocation
                JOIN bb_allocation_resource ON (allocation_id = bb_allocation.id AND resource_id = ?)
                JOIN bb_resource AS res ON (res.id = ?)
                JOIN bb_season ON (bb_allocation.season_id = bb_season.id AND bb_allocation.active = 1)
                JOIN bb_building_resource ON bb_building_resource.resource_id = res.id
                WHERE bb_season.building_id = bb_building_resource.building_id
                AND bb_season.active = 1
                AND bb_season.status = 'PUBLISHED'
                AND ((bb_allocation.from_ >= ? AND bb_allocation.from_ < ?)
                    OR (bb_allocation.to_ > ? AND bb_allocation.to_ <= ?)
                    OR (bb_allocation.from_ < ? AND bb_allocation.to_ > ?))";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([$resourceId, $resourceId, $startStr, $endStr, $startStr, $endStr, $startStr, $endStr]);

		return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id');
	}

	/**
	 * Port of sobooking::booking_ids_for_resource
	 */
	private function bookingIdsForResource(int $resourceId, \DateTime $start, \DateTime $end): array
	{
		$startStr = $start->format('Y-m-d H:i');
		$endStr = $end->format('Y-m-d H:i');

		$sql = "SELECT bb_booking.id AS id
                FROM bb_booking
                JOIN bb_booking_resource ON (booking_id = bb_booking.id AND resource_id = ?)
                JOIN bb_resource AS res ON (res.id = ?)
                JOIN bb_season ON (bb_booking.season_id = bb_season.id AND bb_booking.active = 1)
                JOIN bb_building_resource ON bb_building_resource.resource_id = res.id
                WHERE bb_season.building_id = bb_building_resource.building_id
                AND bb_season.active = 1
                AND bb_season.status = 'PUBLISHED'
                AND ((bb_booking.from_ >= ? AND bb_booking.from_ < ?)
                    OR (bb_booking.to_ > ? AND bb_booking.to_ <= ?)
                    OR (bb_booking.from_ < ? AND bb_booking.to_ > ?))";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([$resourceId, $resourceId, $startStr, $endStr, $startStr, $endStr, $startStr, $endStr]);

		return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id');
	}

	/**
	 * Fetch full entity data (events, allocations, or bookings) by IDs
	 * and normalize the resources field to simple ID arrays.
	 */
	private function fetchEntities(string $type, array $ids): array
	{
		if (empty($ids)) {
			return ['results' => []];
		}

		$ids = array_unique(array_map('intval', $ids));
		$placeholders = implode(',', array_fill(0, count($ids), '?'));

		switch ($type) {
			case 'event':
				$sql = "SELECT e.id, e.from_, e.to_, e.active, e.name,
                        er.resource_id
                        FROM bb_event e
                        JOIN bb_event_resource er ON er.event_id = e.id
                        WHERE e.id IN ($placeholders)";
				break;
			case 'allocation':
				$sql = "SELECT a.id, a.from_, a.to_, a.active,
                        ar.resource_id
                        FROM bb_allocation a
                        JOIN bb_allocation_resource ar ON ar.allocation_id = a.id
                        WHERE a.id IN ($placeholders)";
				break;
			case 'booking':
				$sql = "SELECT b.id, b.from_, b.to_, b.active,
                        br.resource_id
                        FROM bb_booking b
                        JOIN bb_booking_resource br ON br.booking_id = b.id
                        WHERE b.id IN ($placeholders)";
				break;
			default:
				return ['results' => []];
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute(array_values($ids));
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		// Group by entity ID and build results with resource ID arrays
		$grouped = [];
		foreach ($rows as $row) {
			$id = $row['id'];
			if (!isset($grouped[$id])) {
				$grouped[$id] = [
					'id' => (int)$row['id'],
					'from_' => $row['from_'],
					'to_' => $row['to_'],
					'type' => $type,
					'resources' => [],
				];
			}
			$grouped[$id]['resources'][] = (int)$row['resource_id'];
		}

		return ['results' => array_values($grouped)];
	}

	/**
	 * Port of bobooking::get_partials
	 * Fetches partial applications for current session and active blocks.
	 */
	private function getPartials(array &$events, array $resourceIds): void
	{
		$sessions = Sessions::getInstance();
		$sessionId = $sessions->get_session_id();

		// Fetch partial applications for current session
		if (!empty($sessionId)) {
			$sql = "SELECT a.id, a.status,
                    ad.from_, ad.to_,
                    ar.resource_id
                    FROM bb_application a
                    JOIN bb_application_date ad ON ad.application_id = a.id
                    JOIN bb_application_resource ar ON ar.application_id = a.id
                    WHERE a.status = 'NEWPARTIAL1'
                    AND a.session_id = ?";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$sessionId]);
			$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			$grouped = [];
			foreach ($rows as $row) {
				$id = $row['id'];
				if (!isset($grouped[$id])) {
					$grouped[$id] = [
						'id' => (int)$row['id'],
						'from_' => $row['from_'],
						'to_' => $row['to_'],
						'type' => 'application',
						'status' => $row['status'],
						'resources' => [],
					];
				}
				$grouped[$id]['resources'][] = (int)$row['resource_id'];
			}

			foreach ($grouped as $app) {
				$events['results'][] = $app;
			}
		}

		// Fetch active blocks for these resources
		if (!empty($resourceIds)) {
			$placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
			$sql = "SELECT id, from_, to_, resource_id, session_id
                    FROM bb_block
                    WHERE active = 1 AND resource_id IN ($placeholders)";
			$stmt = $this->db->prepare($sql);
			$stmt->execute(array_values($resourceIds));
			$blocks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			foreach ($blocks as $block) {
				// Skip blocks from current session (same as legacy)
				if ($block['session_id'] === $sessionId) {
					continue;
				}
				$events['results'][] = [
					'id' => (int)$block['id'],
					'from_' => $block['from_'],
					'to_' => $block['to_'],
					'type' => 'block',
					'resources' => [(int)$block['resource_id']],
				];
			}
		}
	}

	/**
	 * Port of bobooking::month_shifter
	 * Complex month calculation with "wait for desired time" logic.
	 */
	private function monthShifter(\DateTime $aDate, int $months, \DateTimeZone $tz): \DateTime
	{
		$now = new \DateTime('now', $tz);

		// Wait for desired time within day
		$startOfMonth = clone $aDate;
		$startOfMonth->modify('first day of this month');

		if ($startOfMonth > $now && $months > 0) {
			$months -= 1;
		}

		$checkLimit = clone $aDate;
		$checkLimit->setTime(23, 59, 59);
		$checkLimit->modify('last day of this month');
		if ($checkLimit > $now && $months > 0) {
			$months -= 1;
		}

		$dateA = clone $aDate;
		$dateB = clone $aDate;
		$plusMonths = clone $dateA->modify($months . ' Month');

		// Check whether reversing the month addition gives us the original day back
		if ($dateB != $dateA->modify($months * -1 . ' Month')) {
			$result = $plusMonths->modify('last day of last month');
		} else if ($aDate == $dateB->modify('last day of this month')) {
			$result = $plusMonths->modify('last day of this month');
		} else {
			$result = $plusMonths->modify('last day of this month');
		}

		$result->setTime(23, 59, 59);
		return $result;
	}

	/**
	 * Get active seasons for a resource within a date range.
	 * Port of soseason::get_resource_seasons (with static cache)
	 */
	private array $seasonCache = [];

	private function getResourceSeasons(int $resourceId, string $from, string $to): array
	{
		$cacheKey = "{$resourceId}_{$from}_{$to}";
		if (isset($this->seasonCache[$cacheKey])) {
			return $this->seasonCache[$cacheKey];
		}

		$sql = "SELECT season_id FROM bb_season_resource
                JOIN bb_season ON bb_season.id = bb_season_resource.season_id
                WHERE status = 'PUBLISHED'
                AND ((from_ >= ? AND from_ < ?) OR (to_ > ? AND to_ <= ?) OR (from_ < ? AND to_ > ?))
                AND resource_id = ?";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([$from, $to, $from, $to, $from, $to, $resourceId]);

		$seasons = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$seasons[] = (int)$row['season_id'];
		}

		$this->seasonCache[$cacheKey] = $seasons;
		return $seasons;
	}

	/**
	 * Check if a timespan falls within a season's boundaries.
	 * Port of soseason::timespan_within_season
	 */
	private array $seasonDataCache = [];
	private array $seasonBoundaryCache = [];

	private function timespanWithinSeason(int $seasonId, \DateTime $from, \DateTime $to): bool
	{
		// Load season data (cached)
		if (!isset($this->seasonDataCache[$seasonId])) {
			$sql = "SELECT id, from_, to_ FROM bb_season WHERE id = ?";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([$seasonId]);
			$this->seasonDataCache[$seasonId] = $stmt->fetch(\PDO::FETCH_ASSOC);
		}
		$season = $this->seasonDataCache[$seasonId];
		if (!$season) {
			return false;
		}

		// Check date range
		if (strtotime($season['from_']) > strtotime($from->format('Y-m-d'))
			|| strtotime($season['to_']) < strtotime($to->format('Y-m-d'))) {
			return false;
		}

		$secondsInADay = 86400;
		$daysInPeriod = abs(strtotime('+1 day', strtotime($to->format('Y-m-d'))) - strtotime($from->format('Y-m-d'))) / $secondsInADay;

		if ($daysInPeriod <= 7) {
			$fromWeekDay = (int)$from->format('N');
			$toWeekDay = (int)$to->format('N');
			$fromTime = $from->format('H:i:s');
			$toTime = $to->format('H:i:s');
		} else {
			$fromWeekDay = 1;
			$toWeekDay = 7;
			$fromTime = '00:00:00';
			$toTime = '23:59:00';
		}

		if ($fromWeekDay > $toWeekDay) {
			// Booking wraps around week boundary - split and validate each half
			$endOfWeek = strtotime('+' . (7 - $fromWeekDay) . ' days 23:59:00', strtotime($from->format('Y-m-d')));
			$endOfWeekDt = new \DateTime(date('Y-m-d H:i:s', $endOfWeek));
			$startOfWeek = strtotime('-' . ($toWeekDay - 1) . ' days 00:00:00', strtotime($to->format('Y-m-d')));
			$startOfWeekDt = new \DateTime(date('Y-m-d H:i:s', $startOfWeek));

			return $this->timespanWithinSeason($seasonId, $from, $endOfWeekDt)
				&& $this->timespanWithinSeason($seasonId, $startOfWeekDt, $to);
		}

		$fromWdayTs = strtotime(date('Y-m-d', 86400 * ($fromWeekDay - 1)) . ' ' . $fromTime);
		$toWdayTs = strtotime(date('Y-m-d', 86400 * ($toWeekDay - 1)) . ' ' . $toTime);

		$boundaries = $this->retrieveSeasonBoundaries($seasonId);

		foreach ($boundaries as $b) {
			if (strtotime($b['from_']) <= $fromWdayTs && strtotime($b['to_']) >= $toWdayTs) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Port of soseason::retrieve_season_boundaries (coalesced)
	 * Uses temp VIEW to coalesce overlapping boundaries.
	 */
	private function retrieveSeasonBoundaries(int $seasonId): array
	{
		if (isset($this->seasonBoundaryCache[$seasonId])) {
			return $this->seasonBoundaryCache[$seasonId];
		}

		// Create temp view for boundary coalescing (same as legacy)
		// Note: Cannot use prepared statement parameter in DDL (CREATE VIEW), so we cast to int for safety
		$safeSeasonId = (int)$seasonId;
		$viewSql = "CREATE OR REPLACE TEMP VIEW bsbt AS SELECT
            TIMESTAMP 'epoch ' + (EXTRACT(EPOCH FROM from_)+86400*(wday-1)) * INTERVAL '1 second' as from_,
            TIMESTAMP 'epoch ' + (EXTRACT(EPOCH FROM to_)+86400*(wday-1)) * INTERVAL '1 second' as to_
            FROM bb_season_boundary WHERE season_id={$safeSeasonId}";
		$this->db->exec($viewSql);

		$rangesSql = "SELECT from_,
            (SELECT MIN(to_) FROM bsbt AS C WHERE NOT EXISTS
                (SELECT * FROM bsbt AS D WHERE C.to_ >= D.from_ AND C.to_ < D.to_)
                AND C.to_ >= A.from_
            ) AS to_
            FROM bsbt AS A WHERE NOT EXISTS
            (SELECT * FROM bsbt AS B WHERE A.from_ > B.from_ AND A.from_ <= B.to_)
            ORDER BY from_, to_";
		$stmt = $this->db->prepare($rangesSql);
		$stmt->execute();
		$boundaries = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		// Coalesce boundaries over days (port of coalesce_season_boundaries_over_days)
		// When a boundary ends at 23:59 and the next starts at 00:00, merge them
		$boundaries = $this->coalesceBoundariesOverDays($boundaries);

		$this->seasonBoundaryCache[$seasonId] = $boundaries;
		return $boundaries;
	}

	/**
	 * Port of booking_soseason_boundary::coalesce_season_boundaries_over_days
	 * Merges consecutive day boundaries (e.g. Mon 00:00-23:59 + Tue 00:00-23:59 → Mon 00:00 - Tue 23:59)
	 */
	private function coalesceBoundariesOverDays(array $boundaries): array
	{
		$result = [];
		while ($record = array_shift($boundaries)) {
			$this->coalesceBoundary($record, $boundaries);
			$result[] = $record;
		}
		return $result;
	}

	/**
	 * Port of booking_soseason_boundary::coalesce_boundary
	 * Recursively merges a boundary with the next one if they're adjacent (23:59 → 00:00)
	 */
	private function coalesceBoundary(array &$r, array &$remaining): void
	{
		$tsTo = strtotime($r['to_']);

		// Note: legacy has `if (!$ts_to >= strtotime('23:59:00', $ts_to))` which due to PHP
		// operator precedence is `if ((!$ts_to) >= ...)` i.e. `if (false >= ...)` which is
		// always false. So this check never triggers and the function always continues.
		// BUG PRESERVED: the check is effectively a no-op
		if (!$tsTo >= strtotime('23:59:00', $tsTo)) {
			return;
		}

		if (!$record = array_shift($remaining)) {
			return;
		}

		$tsFrom = strtotime($record['from_']);
		if ($tsFrom <= strtotime('00:00:59', $tsFrom) && $tsTo >= strtotime('23:59:00', $tsTo)) {
			$r['to_'] = $record['to_'];
			$this->coalesceBoundary($r, $remaining);
		} else {
			array_unshift($remaining, $record);
		}
	}

	/**
	 * Port of bobooking::check_if_resurce_is_taken
	 * Note: method name preserves original typo "resurce"
	 */
	private function checkIfResourceIsTaken(array $resource, \DateTime $startTime, \DateTime $endTime, array $events): mixed
	{
		$now = new \DateTime("now", $this->dateTimeZone);
		$resourceId = $resource['id'];
		$bufferDeadline = $resource['booking_buffer_deadline'];

		if ($bufferDeadline) {
			$now->modify($bufferDeadline . ' Minute');
		}

		if ($startTime <= $now) {
			return ['status' => 3, 'reason' => 'time_in_past', 'type' => 'disabled'];
		}

		foreach (($events['results'] ?? []) as $event) {
			if (!in_array($resourceId, $event['resources'])) {
				continue;
			}

			$eventStart = new \DateTime($event['from_'], $this->dateTimeZone);
			$eventEnd = new \DateTime($event['to_'], $this->dateTimeZone);

			$overlapBase = ($event['type'] == 'block' || ($event['status'] ?? null) == 'NEWPARTIAL1') ? 2 : 1;
			$eventInfo = [
				'id' => $event['id'] ?? null,
				'type' => $event['type'],
				'status' => $event['status'] ?? null,
				'from' => $eventStart->format('Y-m-d H:i:s'),
				'to' => $eventEnd->format('Y-m-d H:i:s'),
			];

			// Complete overlap or exact match
			if (($eventStart <= $startTime && $eventEnd >= $endTime)
				|| ($eventStart->format('Y-m-d H:i:s') === $startTime->format('Y-m-d H:i:s')
					&& $eventEnd->format('Y-m-d H:i:s') === $endTime->format('Y-m-d H:i:s'))) {
				return ['status' => $overlapBase, 'reason' => 'complete_overlap', 'type' => 'complete', 'event' => $eventInfo];
			}
			// Complete containment
			if ($eventStart > $startTime && $eventEnd < $endTime) {
				return ['status' => $overlapBase, 'reason' => 'complete_containment', 'type' => 'complete', 'event' => $eventInfo];
			}
			// Start overlap
			if ($eventStart <= $startTime && $eventEnd > $startTime && $eventEnd < $endTime) {
				return ['status' => $overlapBase, 'reason' => 'start_overlap', 'type' => 'partial', 'event' => $eventInfo];
			}
			// End overlap
			if ($eventStart > $startTime && $eventStart < $endTime && $eventEnd >= $endTime) {
				return ['status' => $overlapBase, 'reason' => 'end_overlap', 'type' => 'partial', 'event' => $eventInfo];
			}
		}

		return false;
	}

	/**
	 * Generate time slots for all resources.
	 * Port of the main do...while loop in bobooking::get_free_events
	 */
	private function generateTimeSlots(
		array     $resources,
		array     $events,
		int       $buildingId,
		\DateTime $_to,
		bool      $stopOnEndDate,
		bool      $detailedOverlap
	): array
	{
		$days = [
			0 => "Sunday", 1 => "Monday", 2 => "Tuesday", 3 => "Wednesday",
			4 => "Thursday", 5 => "Friday", 6 => "Saturday", 7 => "Sunday",
		];

		$datetimeformat = "{$this->dateformat} H:i";
		$availableTimeSlots = [];

		foreach ($resources as $resource) {
			if (!empty($resource['skip_timeslot'])) {
				continue;
			}

			$availableTimeSlots[$resource['id']] = [];

			if (!$resource['simple_booking'] || !$resource['simple_booking_start_date']) {
				continue;
			}

			$dowStart = $resource['booking_dow_default_start'];
			$bookingLength = $resource['booking_day_default_lenght']; // preserves original typo
			$bookingStart = $resource['booking_time_default_start'];
			$bookingEnd = $resource['booking_time_default_end'];
			$bookingTimeMinutes = $resource['booking_time_minutes'] > 0 ? $resource['booking_time_minutes'] : 60;

			$defaultStartHour = 8;
			$defaultStartMinute = 0;
			$defaultStartHourFallback = 8;
			$defaultEndHour = 23;
			$defaultEndHourFallback = 23;

			// BUG PRESERVED: for same-day bookings (lenght == -1 or 0), swap start/end if start > end
			if ($bookingLength == -1 || $bookingLength == 0) {
				if ($resource['booking_time_default_start'] > -1) {
					$bookingStart = min($resource['booking_time_default_start'], $resource['booking_time_default_end']);
				}
				if ($resource['booking_time_default_end'] > -1) {
					$bookingEnd = max($resource['booking_time_default_start'], $resource['booking_time_default_end']);
				}
			}

			if ($bookingStart > -1) {
				$defaultStartHour = $bookingStart;
				$defaultStartHourFallback = $bookingStart;
			}
			if ($bookingEnd > -1) {
				$defaultEndHour = $bookingEnd;
				$defaultEndHourFallback = $bookingEnd;
			}

			if ($bookingLength == -1) {
				$defaultEndHour--;
			}

			$checkDate = clone $resource['from'];
			$checkDate->setTimezone($this->dateTimeZone);
			$checkDate->setTime($defaultStartHour, 0, 0);

			$limitDate = clone $resource['to'];
			$limitDate->setTimezone($this->dateTimeZone);

			if ($stopOnEndDate) {
				$limitDate = clone $_to;
			}

			$this->tick("resource_{$resource['id']}: limitDate={$limitDate->format('Y-m-d H:i')} checkDate={$checkDate->format('Y-m-d H:i')}");

			$activeSeasons = $this->getResourceSeasons(
				$resource['id'],
				$checkDate->format('Y-m-d'),
				$limitDate->format('Y-m-d')
			);

			do {
				$startTime = clone $checkDate;

				if ($defaultStartHour > $defaultEndHour && ($bookingLength > -1 || $resource['booking_time_default_end'] == -1)) {
					$defaultStartHour = $defaultStartHourFallback;
				}

				if ((int)$startTime->format('H') > $defaultEndHour) {
					$startTime->modify("+1 days");
					$defaultStartHour = $defaultStartHourFallback;
				}

				// Day-of-week filter
				if ($dowStart > -1) {
					$currentDow = (int)$startTime->format('w');
					if ($dowStart != $currentDow || ($dowStart == 7 && $currentDow == 0)) {
						$modifier = "next " . $days[$dowStart];
						$startTime->modify($modifier);
					}
				}

				$startTime->setTime($defaultStartHour, $defaultStartMinute, 0);

				$endTime = clone $startTime;

				// Calculate end time based on booking length
				if ($bookingLength > -1) {
					$endTime->modify("+{$bookingLength} days");
				}

				if ($bookingEnd > -1 && $bookingLength > -1) {
					$endTime->setTime($bookingEnd, 0, 0);
				} else if ($bookingEnd > -1 && !($bookingLength > -1)) {
					$endTime->setTime(
						min($bookingEnd, (int)$startTime->format('H')),
						(int)$endTime->format('i') + $bookingTimeMinutes,
						0
					);
				} else {
					$endTime->setTime(
						(int)$startTime->format('H'),
						(int)$endTime->format('i') + $bookingTimeMinutes,
						0
					);
				}

				$checkDate = clone $endTime;

				// Season validation
				$withinSeason = false;
				foreach ($activeSeasons as $seasonId) {
					$withinSeason = $this->timespanWithinSeason($seasonId, $startTime, $endTime);
					if ($withinSeason) {
						break;
					}
				}

				if ($this->debug && !$withinSeason) {
					$this->tick("slot_skipped_season: {$startTime->format('Y-m-d H:i')} -> {$endTime->format('Y-m-d H:i')} seasons=" . json_encode($activeSeasons));
				}

				// BUG PRESERVED: $simple_booking_start_date variable is undefined in original code,
				// uses !empty() which evaluates to false for undefined, so this block rarely executes
				// In the original: if(!empty($simple_booking_start_date)) - but $simple_booking_start_date
				// is never defined in the loop scope (only $simpleBookingStartDate exists outside loop)
				// We preserve this by NOT executing this block.

				if ($withinSeason) {
					$overlapResult = $this->checkIfResourceIsTaken($resource, $startTime, $endTime, $events);

					$overlapStatus = is_array($overlapResult) ? $overlapResult['status'] : $overlapResult;
					$overlapReason = is_array($overlapResult) ? $overlapResult['reason'] : null;
					$overlapType = is_array($overlapResult) ? $overlapResult['type'] : null;
					$overlapEvent = is_array($overlapResult) ? $overlapResult['event'] : null;

					$timeslot = [
						'when' => $startTime->format($datetimeformat) . ' - ' . $endTime->format($datetimeformat),
						'start' => $startTime->getTimestamp() . '000',
						'end' => $endTime->getTimestamp() . '000',
						'overlap' => $overlapStatus,
						'start_iso' => $startTime->format('c'),
						'end_iso' => $endTime->format('c'),
					];

					if ($detailedOverlap) {
						$timeslot['resource_id'] = $resource['id'];
						if ($overlapReason) {
							$timeslot['overlap_reason'] = $overlapReason;
						}
						if ($overlapType) {
							$timeslot['overlap_type'] = $overlapType;
						}
						if ($overlapEvent) {
							$timeslot['overlap_event'] = $overlapEvent;
						}
					} else {
						$dateTimeZoneUtc = new \DateTimeZone('UTC');
						$fromUtc = clone $startTime;
						$toUtc = clone $endTime;
						$fromUtc->setTimezone($dateTimeZoneUtc);
						$toUtc->setTimezone($dateTimeZoneUtc);

						$timeslot['applicationLink'] = [
							'menuaction' => 'bookingfrontend.uiapplication.add',
							'resource_id' => $resource['id'],
							'building_id' => $buildingId,
							'from_[]' => $fromUtc->format('Y-m-d H:i:s'),
							'to_[]' => $toUtc->format('Y-m-d H:i:s'),
							'simple' => true,
						];
					}

					$availableTimeSlots[$resource['id']][] = $timeslot;
				}

				// Update start hour/minute for next iteration (same-day mode)
				if ($bookingLength == -1 || $resource['booking_time_default_end'] == -1) {
					$defaultStartHour = (int)$endTime->format('H');
					$defaultStartMinute = (int)$endTime->format('i');

					if ($defaultStartHour > $defaultEndHourFallback) {
						$defaultStartHour = $defaultStartHourFallback;
					}
				}
			} while ($checkDate < $limitDate);
		}

		return $availableTimeSlots;
	}
}
