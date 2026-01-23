<?php

namespace App\modules\booking\models;

use App\models\BaseModel;
use App\Database\Db;
use Exception;
use DateTime;

class Allocation extends BaseModel
{
	protected static function getTableName(): string
	{
		return 'bb_allocation';
	}

	/**
	 * @Expose
	 */
	public ?int $id = null;

	/**
	 * @Expose
	 */
	public int $active = 1;

	/**
	 * @Expose
	 */
	public ?int $application_id = null;

	/**
	 * @Expose
	 */
	public int $organization_id;

	/**
	 * @Expose
	 */
	public int $season_id;

	/**
	 * @Expose
	 */
	public string $from_;

	/**
	 * @Expose
	 */
	public string $to_;

	/**
	 * @Expose
	 */
	public float $cost = 0.00;

	/**
	 * @Expose
	 */
	public int $completed = 0;

	/**
	 * @Expose
	 */
	public string $additional_invoice_information = '';

	/**
	 * @Expose
	 */
	public int $skip_bas = 0;

	/**
	 * @Expose
	 */
	public string $building_name = '';

	/**
	 * @Expose
	 */
	public array $resources = [];

	protected static function getFieldMap(): array
	{
		return [
			'id' => [
				'type' => 'int',
				'required' => false,
				'sanitize' => 'int',
			],
			'active' => [
				'type' => 'int',
				'required' => true,
				'default' => 1,
				'sanitize' => 'int',
			],
			'application_id' => [
				'type' => 'int',
				'required' => false,
				'sanitize' => 'int',
			],
			'organization_id' => [
				'type' => 'int',
				'required' => true,
				'sanitize' => 'int',
			],
			'season_id' => [
				'type' => 'int',
				'required' => true,
				'sanitize' => 'int',
			],
			'from_' => [
				'type' => 'datetime',
				'required' => true,
				'sanitize' => 'string',
			],
			'to_' => [
				'type' => 'datetime',
				'required' => true,
				'sanitize' => 'string',
			],
			'cost' => [
				'type' => 'float',
				'required' => true,
				'default' => 0.00,
				'sanitize' => 'float',
			],
			'completed' => [
				'type' => 'int',
				'required' => true,
				'default' => 0,
				'sanitize' => 'int',
			],
			'additional_invoice_information' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'string',
			],
			'skip_bas' => [
				'type' => 'int',
				'required' => true,
				'default' => 0,
				'sanitize' => 'int',
			],
			'building_name' => [
				'type' => 'string',
				'required' => true,
				'sanitize' => 'string',
			]
		];
	}

	protected static function getRelationshipMap(): array
	{
		return [
			'resources' => [
				'type' => 'int',
				'required' => true,
				'sanitize' => 'array_int',
				'manytomany' => [
					'table' => 'bb_allocation_resource',
					'key' => 'allocation_id',
					'column' => 'resource_id'
				]
			],
			'costs' => [
				'type' => 'string',
				'manytomany' => [
					'table' => 'bb_allocation_cost',
					'key' => 'allocation_id',
					'column' => ['time' => ['type' => 'timestamp', 'read_callback' => 'modify_by_timezone'], 'author', 'comment', 'cost'],
					'order' => ['sort' => 'time', 'dir' => 'ASC']
				]
			]
		];
	}

	public function __construct(array $data = [])
	{
		parent::__construct($data);
	}

	/**
	 * Validate the allocation
	 */
	public function validate(): array
	{
		$errors = [];

		if (empty($this->organization_id)) {
			$errors['organization_id'] = 'Organization ID is required';
		}

		if (empty($this->season_id)) {
			$errors['season_id'] = 'Season ID is required';
		}

		if (empty($this->from_)) {
			$errors['from_'] = 'Start time is required';
		}

		if (empty($this->to_)) {
			$errors['to_'] = 'End time is required';
		}

		if (!empty($this->from_) && !empty($this->to_)) {
			$start = strtotime($this->from_);
			$end = strtotime($this->to_);

			if ($start >= $end) {
				$errors['time'] = 'Start time must be before end time';
			}
		}

		// Validate season boundary
		if (!empty($this->season_id) && !empty($this->from_) && !empty($this->to_)) {
			if (!$this->checkSeasonBoundary()) {
				$errors['season_boundary'] = 'This allocation is not within the selected season';
			}
		}

		return $errors;
	}

	/**
	 * Check if allocation is within season boundaries
	 */
	protected function checkSeasonBoundary(): bool
	{
		// This logic was in legacy: CreateObject('booking.soseason')->timespan_within_season
		// We should implement a direct DB check here to avoid legacy dependency if possible,
		// or replicate the logic.
		
		$db = Db::getInstance();
		$sql = "SELECT from_, to_ FROM bb_season WHERE id = :id";
		$season = $db->query($sql, [':id' => $this->season_id])->fetch();

		if (!$season) {
			return false;
		}

		$seasonStart = strtotime($season['from_']);
		$seasonEnd = strtotime($season['to_']);
		$allocStart = strtotime($this->from_);
		$allocEnd = strtotime($this->to_);

		// Check if allocation is within season dates (ignoring time for season usually, but let's be safe)
		// Legacy timespan_within_season logic might be more complex (checking active periods), 
		// but for now let's check date range.
		// Actually, seasons usually define a range of dates.
		
		return ($allocStart >= $seasonStart && $allocEnd <= $seasonEnd);
	}

	/**
	 * Check for conflicts with other events, allocations, or bookings
	 */
	public function checkConflicts(int $excludeId = -1): array
	{
		$errors = [];
		
		if (empty($this->resources) || empty($this->from_) || empty($this->to_)) {
			return $errors;
		}

		$db = Db::getInstance();
		$start = $this->from_;
		$end = $this->to_;
		$resourceIds = implode(',', array_map('intval', $this->resources));

		if (empty($resourceIds)) {
			return $errors;
		}

		// Check overlap with Events
		$sql = "SELECT e.id FROM bb_event e
				WHERE e.active = 1 
				AND e.id IN (SELECT event_id FROM bb_event_resource WHERE resource_id IN ($resourceIds))
				AND ((e.from_ >= :start1 AND e.from_ < :end1) OR
					 (e.to_ > :start2 AND e.to_ <= :end2) OR
					 (e.from_ < :start3 AND e.to_ > :end3))";
		
		$params = [
			':start1' => $start, ':end1' => $end,
			':start2' => $start, ':end2' => $end,
			':start3' => $start, ':end3' => $end
		];

		$stmt = $db->query($sql, $params);
		if ($row = $stmt->fetch()) {
			$errors['conflict_event'] = "Overlaps with existing event #{$row['id']}";
		}

		// Check overlap with Allocations
		$sql = "SELECT a.id FROM bb_allocation a
				WHERE a.active = 1 AND a.id <> :excludeId
				AND a.id IN (SELECT allocation_id FROM bb_allocation_resource WHERE resource_id IN ($resourceIds))
				AND ((a.from_ >= :start1 AND a.from_ < :end1) OR
					 (a.to_ > :start2 AND a.to_ <= :end2) OR
					 (a.from_ < :start3 AND a.to_ > :end3))";
		
		$params[':excludeId'] = $excludeId;

		$stmt = $db->query($sql, $params);
		if ($row = $stmt->fetch()) {
			$errors['conflict_allocation'] = "Overlaps with existing allocation #{$row['id']}";
		}

		// Check overlap with Bookings
		$sql = "SELECT b.id FROM bb_booking b
				WHERE b.active = 1 AND b.allocation_id <> :excludeId
				AND b.id IN (SELECT booking_id FROM bb_booking_resource WHERE resource_id IN ($resourceIds))
				AND ((b.from_ >= :start1 AND b.from_ < :end1) OR
					 (b.to_ > :start2 AND b.to_ <= :end2) OR
					 (b.from_ < :start3 AND b.to_ > :end3))";

		$stmt = $db->query($sql, $params);
		if ($row = $stmt->fetch()) {
			$errors['conflict_booking'] = "Overlaps with existing booking #{$row['id']}";
		}

		return $errors;
	}

	/**
	 * Get resources relationship data
	 */
	public function getResources(): array
	{
		return $this->loadRelationship('resources') ?? [];
	}

	/**
	 * Get costs relationship data
	 */
	public function getCosts(): array
	{
		return $this->loadRelationship('costs') ?? [];
	}

	/**
	 * Override save to handle additional logic
	 */
	public function save(): bool
	{
		$isNew = empty($this->id);
		
		if (parent::save()) {
			// Handle relationships
			if (isset($this->resources)) {
				$this->saveRelationship('resources', $this->resources);
			}
			
			// Update bb_completed_reservation if needed (legacy logic)
			if (!$isNew) {
				$this->updateCompletedReservation();
			}

			return true;
		}
		return false;
	}

	protected function updateCompletedReservation()
	{
		$db = Db::getInstance();
		$description = mb_substr($this->from_, 0, -3, 'UTF-8') . ' - ' . mb_substr($this->to_, 0, -3, 'UTF-8');
		
		$sql = "UPDATE bb_completed_reservation SET 
				cost = :cost, 
				from_ = :from, 
				to_ = :to, 
				description = :description
				WHERE reservation_type = 'allocation'
				AND reservation_id = :id
				AND export_file_id IS NULL";
		
		$db->query($sql, [
			':cost' => $this->cost,
			':from' => $this->from_,
			':to' => $this->to_,
			':description' => $description,
			':id' => $this->id
		]);
	}

	/**
	 * Delete allocation and related data
	 */
	public function delete(): bool
	{
		$db = Db::getInstance();
		$db->beginTransaction();

		try {
			$id = $this->id;

			// Delete costs
			$db->query("DELETE FROM bb_allocation_cost WHERE allocation_id = :id", [':id' => $id]);

			// Delete resources
			$db->query("DELETE FROM bb_allocation_resource WHERE allocation_id = :id", [':id' => $id]);

			// Handle completed reservations
			$stmt = $db->query("SELECT id FROM bb_completed_reservation WHERE reservation_id = :id AND reservation_type = 'allocation' AND export_file_id IS NULL", [':id' => $id]);
			if ($row = $stmt->fetch()) {
				$completedId = $row['id'];
				$db->query("DELETE FROM bb_completed_reservation_resource WHERE completed_reservation_id = :id", [':id' => $completedId]);
				$db->query("DELETE FROM bb_completed_reservation WHERE id = :id", [':id' => $completedId]);
			}

			// Delete the allocation itself
			$db->query("DELETE FROM bb_allocation WHERE id = :id", [':id' => $id]);

			$db->commit();
			return true;
		} catch (Exception $e) {
			$db->rollBack();
			return false;
		}
	}
}
