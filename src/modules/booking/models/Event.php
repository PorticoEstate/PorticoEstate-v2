<?php

namespace App\modules\booking\models;

use App\traits\SerializableTrait;
use App\traits\ValidatorTrait;
use App\Database\Db;
use PDO;
use DateTime;
use Exception;

/**
 * Event model for booking system
 * Based on booking_soevent legacy class
 * 
 * @Expose
 */
class Event
{
	use SerializableTrait;
	use ValidatorTrait;

	protected Db $db;

	// Field definitions based on booking_soevent constructor

	/**
	 * @Expose
	 */
	public ?int $id = null;

	/**
	 * @Expose
	 * @Default("0")
	 */
	public string $id_string = '0';

	/**
	 * @Expose
	 * @ParseBool
	 */
	public int $active = 1;

	/**
	 * @Expose
	 */
	public int $skip_bas = 0;

	/**
	 * @Expose
	 */
	public int $activity_id;

	/**
	 * @Expose
	 */
	public ?int $application_id = null;

	/**
	 * @Expose
	 */
	public string $name;

	/**
	 * @Expose
	 */
	public string $organizer;

	/**
	 * @Expose
	 */
	public string $homepage = '';

	/**
	 * @Expose
	 */
	public string $description = '';

	/**
	 * @Expose
	 */
	public string $equipment = '';

	/**
	 * @Expose
	 */
	public int $building_id;

	/**
	 * @Expose
	 */
	public string $building_name;

	/**
	 * @Expose
	 * @Timestamp(format="c", sourceTimezone="Europe/Oslo")
	 */
	public string $from_;

	/**
	 * @Expose
	 * @Timestamp(format="c", sourceTimezone="Europe/Oslo")
	 */
	public string $to_;

	/**
	 * @Expose
	 */
	public float $cost = 0.00;

	// Additional fields from actual database schema
	public string $contact_name = '';
	public string $contact_email = '';
	public string $contact_phone = '';
	public string $secret = '';
	public int $customer_internal = 0;
	public int $include_in_list = 0;
	public int $reminder = 1;
	public int $is_public = 0;
	public int $completed = 0;
	public int $access_requested = 0;
	public ?int $participant_limit = null;
	public ?string $customer_identifier_type = null;
	public ?string $customer_ssn = null;
	public ?string $customer_organization_number = null;
	public ?int $customer_organization_id = null;
	public ?string $customer_organization_name = null;
	public ?string $additional_invoice_information = null;
	public ?int $sms_total = null;

	/**
	 * @Expose
	 * @SerializeAs(type="array", of="int")
	 */
	public array $resources = [];

	/**
	 * Central field map for validation and metadata
	 */
	protected static function getFieldMap(): array
	{
		return [
			'id' => [
				'type' => 'int',
				'required' => false,
				'sanitize' => 'int',
			],
			'id_string' => [
				'type' => 'string',
				'required' => false,
				'default' => '0',
				'sanitize' => 'string',
			],
			'active' => [
				'type' => 'int',
				'required' => true,
				'sanitize' => 'int',
			],
			'skip_bas' => [
				'type' => 'int',
				'required' => false,
				'sanitize' => 'int',
			],
			'activity_id' => [
				'type' => 'int',
				'required' => true,
				'sanitize' => 'int',
				'validator' => function ($value) {
					return self::validatePositive($value, 'Activity ID');
				},
			],
			'application_id' => [
				'type' => 'int',
				'required' => false,
				'sanitize' => 'int',
			],
			'name' => [
				'type' => 'string',
				'required' => true,
				'maxLength' => 255,
				'sanitize' => 'string',
			],
			'organizer' => [
				'type' => 'string',
				'required' => true,
				'maxLength' => 255,
				'sanitize' => 'string',
			],
			'homepage' => [
				'type' => 'string',
				'required' => false,
				'maxLength' => 255,
				'sanitize' => 'string',
				'validator' => function ($value) {
					return self::validateUrl($value, 'Homepage');
				},
			],
			'description' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'html', // Allow some HTML but sanitize it
			],
			'equipment' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'string',
			],
			'building_id' => [
				'type' => 'int',
				'required' => true,
				'sanitize' => 'int',
				'validator' => function ($value) {
					return self::validatePositive($value, 'Building ID');
				},
			],
			'building_name' => [
				'type' => 'string',
				'required' => true,
				'maxLength' => 255,
				'sanitize' => 'string',
			],
			'from_' => [
				'type' => 'datetime',
				'required' => true,
				'sanitize' => 'string', // Will be validated by datetime validation
			],
			'to_' => [
				'type' => 'datetime',
				'required' => true,
				'sanitize' => 'string', // Will be validated by datetime validation
			],
			'cost' => [
				'type' => 'float',
				'required' => true,
				'sanitize' => 'float',
				'validator' => function ($value) {
					return self::validateNonNegative($value, 'Cost');
				},
			],
			'contact_name' => [
				'type' => 'string',
				'required' => true,
				'maxLength' => 50,
				'sanitize' => 'string',
			],
			'contact_email' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'email',
				'validator' => function ($value) {
					return self::validateEmail($value, 'Contact email');
				},
			],
			'contact_phone' => [
				'type' => 'string',
				'required' => false,
				'maxLength' => 50,
				'sanitize' => 'string',
				'validator' => function ($value) {
					return self::validatePhone($value, 'Contact phone');
				},
			],
			'completed' => [
				'type' => 'int',
				'required' => true,
				'default' => 0,
				'sanitize' => 'int',
			],
			'access_requested' => [
				'type' => 'int',
				'required' => false,
				'default' => 0,
				'sanitize' => 'int',
			],
			'reminder' => [
				'type' => 'int',
				'required' => true,
				'default' => 1,
				'sanitize' => 'int',
			],
			'is_public' => [
				'type' => 'int',
				'required' => true,
				'default' => 1,
				'sanitize' => 'int',
			],
			'secret' => [
				'type' => 'string',
				'required' => true,
				'sanitize' => 'string',
			],
			'sms_total' => [
				'type' => 'int',
				'required' => false,
				'sanitize' => 'int',
			],
			'participant_limit' => [
				'type' => 'int',
				'required' => false,
				'sanitize' => 'int',
				'validator' => function ($value) {
					if (is_null($value)) return null;
					return self::validateNonNegative($value, 'Participant limit');
				},
			],
			'customer_organization_name' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'string',
			],
			'customer_organization_id' => [
				'type' => 'int',
				'required' => false,
				'sanitize' => 'int',
			],
			'customer_identifier_type' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'string',
			],
			'customer_ssn' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'string',
				'validator' => function ($value) {
					return self::validateNorwegianSSN($value, 'Customer SSN');
				},
			],
			'customer_organization_number' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'string',
				'validator' => function ($value) {
					return self::validateNorwegianOrSwedishOrgNumber($value, 'Customer organization number');
				},
			],
			'customer_internal' => [
				'type' => 'int',
				'required' => true,
				'sanitize' => 'int',
			],
			'include_in_list' => [
				'type' => 'int',
				'required' => true,
				'default' => 0,
				'sanitize' => 'int',
			],
			'additional_invoice_information' => [
				'type' => 'string',
				'sanitize' => 'string',
				'required' => false,
			],
			'resources' => [
				'type' => 'array',
				'required' => true,
				'sanitize' => 'array_int', // Array of integers
				'validator' => function ($value) {
					return self::validateNonEmptyArray($value, 'Resources');
				},
			],
			// Additional fields for controller compatibility
			'title' => [
				'type' => 'string',
				'required' => false, // Maps to 'name' field
				'sanitize' => 'string',
			],
			'source' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'string',
			],
			'bridge_import' => [
				'type' => 'bool',
				'required' => false,
				'sanitize' => 'bool',
			],
			'resource_ids' => [
				'type' => 'array',
				'required' => false, // Maps to 'resources' field
				'sanitize' => 'array_int', // Array of integers
			],
			// You can add more fields as needed, e.g. for agegroups, audience, comments, costs, dates, etc.
		];
	}

	/**
	 * Get sanitization rules from field map
	 */
	public static function getSanitizationRules(): array
	{
		$rules = [];
		foreach (static::getFieldMap() as $field => $config) {
			if (isset($config['sanitize'])) {
				$rules[$field] = $config['sanitize'];
			}
		}
		return $rules;
	}

	/**
	 * Get array element sanitization info
	 * Maps array sanitization types to their element types
	 */
	public static function getArrayElementTypes(): array
	{
		return [
			'array_int' => 'int',
			'array_string' => 'string',
			'array_email' => 'email',
			'array_float' => 'float',
			// Add more as needed
		];
	}

	public function __construct(?array $data = null)
	{
		$this->db = Db::getInstance();

		if ($data)
		{
			$this->populate($data);
		}
	}

	/**
	 * Populate model with data
	 */
	public function populate(array $data): self
	{
		foreach ($data as $key => $value)
		{
			if (property_exists($this, $key))
			{
				$this->$key = $value;
			}
		}
		return $this;
	}

	/**
	 * Validate the event data using the field map
	 */
	public function validate(): array
	{
		$errors = [];
		foreach (self::getFieldMap() as $field => $meta)
		{
			$value = $this->$field ?? null;
			// Required check
			if (($meta['required'] ?? false) && (is_null($value) || $value === '' || (is_array($value) && count($value) === 0)))
			{
				$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
				continue;
			}
			// Type check (basic)
			if (!is_null($value))
			{
				switch ($meta['type'])
				{
					case 'int':
						if (!is_int($value))
						{
							$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be an integer';
						}
						break;
					case 'string':
						if (!is_string($value))
						{
							$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a string';
						}
						break;
					case 'array':
						if (!is_array($value))
						{
							$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be an array';
						}
						break;
					case 'datetime':
						try
						{
							new \DateTime($value);
						}
						catch (\Exception $e)
						{
							$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a valid date/time';
						}
						break;
				}
			}
			// Max length check
			if (isset($meta['maxLength']) && is_string($value) && strlen($value) > $meta['maxLength'])
			{
				$errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be {$meta['maxLength']} characters or less";
			}
			// Custom validator
			if (isset($meta['validator']) && is_callable($meta['validator']))
			{
				$err = call_user_func($meta['validator'], $value, $this);
				if ($err)
				{
					$errors[] = $err;
				}
			}
		}
		// Custom cross-field validation: from_ < to_
		if (!empty($this->from_) && !empty($this->to_))
		{
			try
			{
				$from = new \DateTime($this->from_);
				$to = new \DateTime($this->to_);
				if ($from >= $to)
				{
					$errors[] = 'End time must be after start time';
				}
			}
			catch (\Exception $e)
			{
				// Already handled above
			}
		}
		return $errors;
	}

	/**
	 * Save the event to database
	 */
	public function save(): bool
	{
		try
		{
			$this->db->beginTransaction();

			if ($this->id)
			{
				return $this->update();
			}
			else
			{
				return $this->create();
			}
		}
		catch (Exception $e)
		{
			$this->db->rollback();
			error_log("Error saving event: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Create new event in database
	 */
	protected function create(): bool
	{
		// Generate secret if not set
		if (empty($this->secret))
		{
			$this->secret = bin2hex(random_bytes(16));
		}

		// Prepare data for insertion
		$eventData = $this->getDbData();
		unset($eventData['id']); // Remove ID for insert

		$columns = array_keys($eventData);
		$placeholders = ':' . implode(', :', $columns);

		$sql = "INSERT INTO bb_event (" . implode(', ', $columns) . ") VALUES (" . $placeholders . ") RETURNING id";
		$stmt = $this->db->prepare($sql);

		// Bind parameters
		foreach ($eventData as $key => $value)
		{
			$stmt->bindValue(":$key", $value);
		}

		$stmt->execute();
		$this->id = (int)$stmt->fetchColumn();

		if (!$this->id)
		{
			throw new Exception('Failed to get event ID after insertion');
		}

		// Update id_string to match the ID
		$this->id_string = (string)$this->id;
		$updateSql = "UPDATE bb_event SET id_string = :id_string WHERE id = :id";
		$updateStmt = $this->db->prepare($updateSql);
		$updateStmt->execute([
			':id_string' => $this->id_string,
			':id' => $this->id
		]);

		// Create resource associations
		$this->saveResourceAssociations();

		// Create event dates
		$this->saveEventDates();

		// Create default age group and target audience
		$this->saveDefaultAgeGroup();
		$this->saveDefaultTargetAudience();

		$this->db->commit();
		return true;
	}

	/**
	 * Update existing event in database
	 * Based on update method from booking_soevent
	 */
	protected function update(): bool
	{
		$eventData = $this->getDbData();
		$id = $eventData['id'];
		unset($eventData['id']);

		$setParts = [];
		foreach (array_keys($eventData) as $column)
		{
			$setParts[] = "$column = :$column";
		}

		$sql = "UPDATE bb_event SET " . implode(', ', $setParts) . " WHERE id = :id";
		$stmt = $this->db->prepare($sql);

		// Bind parameters
		foreach ($eventData as $key => $value)
		{
			$stmt->bindValue(":$key", $value);
		}
		$stmt->bindValue(':id', $id);

		$stmt->execute();

		// Update bb_completed_reservation if exists (from booking_soevent logic)
		$cost = $this->cost;
		$description = mb_substr($this->from_, 0, -3, 'UTF-8') . ' - ' . mb_substr($this->to_, 0, -3, 'UTF-8');

		$completedResSql = "UPDATE bb_completed_reservation SET cost = :cost, from_ = :from_, to_ = :to_, description = :description
                           WHERE reservation_type = 'event' AND reservation_id = :id AND export_file_id IS NULL";
		$completedResStmt = $this->db->prepare($completedResSql);
		$completedResStmt->execute([
			':cost' => $cost,
			':from_' => $this->from_,
			':to_' => $this->to_,
			':description' => $description,
			':id' => $id
		]);

		// Update resource associations
		$this->saveResourceAssociations();

		// Update event dates
		$this->saveEventDates();

		$this->db->commit();
		return true;
	}

	/**
	 * Get data formatted for database operations
	 */
	protected function getDbData(): array
	{
		return [
			'id' => $this->id,
			'id_string' => $this->id_string,
			'active' => $this->active,
			'skip_bas' => $this->skip_bas,
			'activity_id' => $this->activity_id,
			'application_id' => $this->application_id,
			'name' => $this->name,
			'organizer' => $this->organizer,
			'homepage' => $this->homepage,
			'description' => $this->description,
			'equipment' => $this->equipment,
			'building_id' => $this->building_id,
			'building_name' => $this->building_name,
			'from_' => $this->from_,
			'to_' => $this->to_,
			'cost' => $this->cost,
			'contact_name' => $this->contact_name,
			'contact_email' => $this->contact_email,
			'contact_phone' => $this->contact_phone,
			'secret' => $this->secret,
			'customer_internal' => $this->customer_internal,
			'include_in_list' => $this->include_in_list,
			'reminder' => $this->reminder,
			'is_public' => $this->is_public,
			'completed' => $this->completed,
			'access_requested' => $this->access_requested,
			'participant_limit' => $this->participant_limit,
			'customer_identifier_type' => $this->customer_identifier_type,
			'customer_ssn' => $this->customer_ssn,
			'customer_organization_number' => $this->customer_organization_number,
			'customer_organization_id' => $this->customer_organization_id,
			'customer_organization_name' => $this->customer_organization_name,
			'additional_invoice_information' => $this->additional_invoice_information,
			'sms_total' => $this->sms_total
		];
	}

	/**
	 * Save resource associations
	 */
	protected function saveResourceAssociations(): void
	{
		// Delete existing associations
		$deleteSql = "DELETE FROM bb_event_resource WHERE event_id = :event_id";
		$deleteStmt = $this->db->prepare($deleteSql);
		$deleteStmt->execute([':event_id' => $this->id]);

		// Insert new associations
		if (!empty($this->resources))
		{
			$insertSql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES (:event_id, :resource_id)";
			$insertStmt = $this->db->prepare($insertSql);

			foreach ($this->resources as $resourceId)
			{
				$insertStmt->execute([
					':event_id' => $this->id,
					':resource_id' => (int)$resourceId
				]);
			}
		}
	}

	/**
	 * Save event dates
	 */
	protected function saveEventDates(): void
	{
		// Delete existing dates
		$deleteSql = "DELETE FROM bb_event_date WHERE event_id = :event_id";
		$deleteStmt = $this->db->prepare($deleteSql);
		$deleteStmt->execute([':event_id' => $this->id]);

		// Insert new date
		$insertSql = "INSERT INTO bb_event_date (event_id, from_, to_) VALUES (:event_id, :from_, :to_)";
		$insertStmt = $this->db->prepare($insertSql);
		$insertStmt->execute([
			':event_id' => $this->id,
			':from_' => $this->from_,
			':to_' => $this->to_
		]);
	}

	/**
	 * Save default age group
	 */
	protected function saveDefaultAgeGroup(): void
	{
		$ageGroupSql = "SELECT id FROM bb_agegroup ORDER BY id LIMIT 1";
		$ageGroupStmt = $this->db->prepare($ageGroupSql);
		$ageGroupStmt->execute();
		$ageGroupId = $ageGroupStmt->fetchColumn();

		if ($ageGroupId)
		{
			$insertSql = "INSERT INTO bb_event_agegroup (event_id, agegroup_id, male, female) VALUES (:event_id, :agegroup_id, :male, :female)";
			$insertStmt = $this->db->prepare($insertSql);
			$insertStmt->execute([
				':event_id' => $this->id,
				':agegroup_id' => $ageGroupId,
				':male' => 0,
				':female' => 0
			]);
		}
	}

	/**
	 * Save default target audience
	 */
	protected function saveDefaultTargetAudience(): void
	{
		$targetAudienceSql = "SELECT id FROM bb_targetaudience ORDER BY id LIMIT 1";
		$targetAudienceStmt = $this->db->prepare($targetAudienceSql);
		$targetAudienceStmt->execute();
		$targetAudienceId = $targetAudienceStmt->fetchColumn();

		if ($targetAudienceId)
		{
			$insertSql = "INSERT INTO bb_event_targetaudience (event_id, targetaudience_id) VALUES (:event_id, :targetaudience_id)";
			$insertStmt = $this->db->prepare($insertSql);
			$insertStmt->execute([
				':event_id' => $this->id,
				':targetaudience_id' => $targetAudienceId
			]);
		}
	}

	/**
	 * Check for event conflicts
	 */
	public function checkConflicts(?int $excludeEventId = null): ?string
	{
		if (empty($this->resources))
		{
			return 'No resources specified for conflict check';
		}

		$resourceIds = implode(',', array_map('intval', $this->resources));
		$excludeClause = $excludeEventId ? " AND e.id != :exclude_id" : "";

		// Check for exact duplicates
		$duplicateSql = "SELECT e.id FROM bb_event e
                        JOIN bb_event_resource er ON e.id = er.event_id
                        WHERE e.name = :name 
                        AND e.from_ = :from_ 
                        AND e.to_ = :to_
                        AND er.resource_id IN ($resourceIds)
                        AND e.active = 1
                        $excludeClause";

		$duplicateStmt = $this->db->prepare($duplicateSql);
		$duplicateStmt->bindValue(':name', $this->name);
		$duplicateStmt->bindValue(':from_', $this->from_);
		$duplicateStmt->bindValue(':to_', $this->to_);

		if ($excludeEventId)
		{
			$duplicateStmt->bindValue(':exclude_id', $excludeEventId);
		}

		$duplicateStmt->execute();
		if ($duplicateStmt->fetch())
		{
			return 'Duplicate event: An identical event already exists for this resource and time period';
		}

		// Check for overlapping events
		$overlapSql = "SELECT e.id, e.name FROM bb_event e
                      JOIN bb_event_resource er ON e.id = er.event_id
                      WHERE er.resource_id IN ($resourceIds)
                      AND e.active = 1
                      AND (
                          (e.from_ < :to_ AND e.to_ > :from_)
                      )
                      $excludeClause";

		$overlapStmt = $this->db->prepare($overlapSql);
		$overlapStmt->bindValue(':from_', $this->from_);
		$overlapStmt->bindValue(':to_', $this->to_);

		if ($excludeEventId)
		{
			$overlapStmt->bindValue(':exclude_id', $excludeEventId);
		}

		$overlapStmt->execute();
		$conflict = $overlapStmt->fetch(PDO::FETCH_ASSOC);

		if ($conflict)
		{
			return "Time conflict: Overlaps with existing event #{$conflict['id']} ({$conflict['name']})";
		}

		return null;
	}

	/**
	 * Delete event and all related data
	 * Based on delete_event method from booking_soevent
	 */
	public function delete(): bool
	{
		if (!$this->id)
		{
			return false;
		}

		try
		{
			$this->db->beginTransaction();

			$id = $this->id;

			// Delete related tables (order matters due to foreign keys)
			$relatedTables = [
				'bb_event_cost',
				'bb_event_comment',
				'bb_event_agegroup',
				'bb_event_targetaudience',
				'bb_event_date',
				'bb_event_resource'
			];

			foreach ($relatedTables as $table)
			{
				$sql = "DELETE FROM $table WHERE event_id = :event_id";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([':event_id' => $id]);
			}

			// Handle purchase orders
			$orderSql = "SELECT id, parent_id FROM bb_purchase_order WHERE reservation_type = 'event' AND reservation_id = :id";
			$orderStmt = $this->db->prepare($orderSql);
			$orderStmt->execute([':id' => $id]);
			$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

			if ($order)
			{
				if ($order['parent_id'])
				{
					// Delete child order
					$deleteOrderSql = "DELETE FROM bb_purchase_order WHERE id = :order_id";
					$deleteOrderStmt = $this->db->prepare($deleteOrderSql);
					$deleteOrderStmt->execute([':order_id' => $order['id']]);
				}
				else
				{
					// Handle parent order - delete children first
					$deleteChildOrdersSql = "DELETE FROM bb_purchase_order WHERE parent_id = :parent_id";
					$deleteChildOrdersStmt = $this->db->prepare($deleteChildOrdersSql);
					$deleteChildOrdersStmt->execute([':parent_id' => $order['id']]);

					// Then delete parent
					$deleteOrderSql = "DELETE FROM bb_purchase_order WHERE id = :order_id";
					$deleteOrderStmt = $this->db->prepare($deleteOrderSql);
					$deleteOrderStmt->execute([':order_id' => $order['id']]);
				}
			}

			// Handle completed reservations
			$completedResSql = "SELECT id FROM bb_completed_reservation WHERE reservation_id = :id AND reservation_type = 'event' AND export_file_id IS NULL";
			$completedResStmt = $this->db->prepare($completedResSql);
			$completedResStmt->execute([':id' => $id]);
			$completedRes = $completedResStmt->fetch(PDO::FETCH_ASSOC);

			if ($completedRes)
			{
				$deleteCompResResourceSql = "DELETE FROM bb_completed_reservation_resource WHERE completed_reservation_id = :comp_res_id";
				$deleteCompResResourceStmt = $this->db->prepare($deleteCompResResourceSql);
				$deleteCompResResourceStmt->execute([':comp_res_id' => $completedRes['id']]);

				$deleteCompResSql = "DELETE FROM bb_completed_reservation WHERE id = :comp_res_id";
				$deleteCompResStmt = $this->db->prepare($deleteCompResSql);
				$deleteCompResStmt->execute([':comp_res_id' => $completedRes['id']]);
			}

			// Finally delete the event itself
			$deleteEventSql = "DELETE FROM bb_event WHERE id = :id";
			$deleteEventStmt = $this->db->prepare($deleteEventSql);
			$deleteEventStmt->execute([':id' => $id]);

			$this->db->commit();
			return true;
		}
		catch (Exception $e)
		{
			$this->db->rollback();
			error_log("Error deleting event: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Load event by ID
	 */
	public static function find(int $id): ?self
	{
		$db = Db::getInstance();

		$sql = "SELECT * FROM bb_event WHERE id = :id";
		$stmt = $db->prepare($sql);
		$stmt->execute([':id' => $id]);
		$data = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$data)
		{
			return null;
		}

		$event = new self($data);

		// Load associated resources
		$resourceSql = "SELECT resource_id FROM bb_event_resource WHERE event_id = :event_id";
		$resourceStmt = $db->prepare($resourceSql);
		$resourceStmt->execute([':event_id' => $id]);
		$event->resources = $resourceStmt->fetchAll(PDO::FETCH_COLUMN);

		return $event;
	}

	/**
	 * Get building information for a resource
	 */
	public static function getBuildingInfoForResource(int $resourceId): ?array
	{
		$db = Db::getInstance();

		$sql = "SELECT bb_building.id, bb_building.name 
                FROM bb_building 
                JOIN bb_building_resource ON bb_building.id = bb_building_resource.building_id 
                WHERE bb_building_resource.resource_id = :resource_id";

		$stmt = $db->prepare($sql);
		$stmt->execute([':resource_id' => $resourceId]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Check if a resource exists
	 */
	public static function resourceExists(int $resourceId): bool
	{
		$db = Db::getInstance();

		$sql = "SELECT id FROM bb_resource WHERE id = :id AND active = 1";
		$stmt = $db->prepare($sql);
		$stmt->execute([':id' => $resourceId]);
		return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
	}
}
