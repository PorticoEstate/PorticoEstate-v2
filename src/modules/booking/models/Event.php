<?php

namespace App\modules\booking\models;

use App\models\BaseModel;
use App\traits\SerializableTrait;
use App\Database\Db;
use PDO;
use DateTime;
use Exception;

/**
 * Event model for booking system
 * Extends BaseModel for CRUD operations, validation, and relationship management
 * Based on booking_soevent legacy class but modernized and streamlined
 * 
 * @Expose
 */
class Event extends BaseModel
{
	use SerializableTrait;

	// Override BaseModel properties to make them accessible
	protected array $fieldMap = [];
	protected array $relationshipMap = [];

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
	 */
	public array $resources = [];
	/**
	 * @Expose
	 */
	public array $agegroups = [];
	/**
	 * @Expose
	 */
	public array $audience = [];


	// Relationship properties for lazy loading
	protected ?array $_audience = null;
	protected ?array $_agegroups = null;
	protected ?array $_comments = null;
	protected ?array $_costs = null;
	protected ?array $_dates = null;
	protected ?array $_building_info = null;
	protected ?array $_activity_info = null;
	protected ?array $_application_info = null;

	/**
	 * Get table name for the Event model
	 */
	protected static function getTableName(): string
	{
		return 'bb_event';
	}

	/**
	 * Get the location_id for custom fields
	 * This corresponds to the location in the phpgw_locations table
	 * For events, this should be the location_id for 'booking.event' or similar
	 */
	protected static function getCustomFieldsLocationId(): ?int
	{
		// Get the location_id for booking events from the database
		return static::getLocationId('booking', 'event');
	}

	/**
	 * Optional: Enable JSON storage for custom fields
	 * Uncomment the line below to store all custom fields as JSON in a single field
	 * This is useful when you have many custom fields or want to avoid schema changes
	 */
	protected static function getCustomFieldsJsonField(): ?string
	{
		// return 'json_representation'; // Enable JSON storage
		return null; // Use individual columns (default)
	}

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
				'validator' => function ($value)
				{
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
				'validator' => function ($value)
				{
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
				'validator' => function ($value)
				{
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
				'validator' => function ($value)
				{
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
				'validator' => function ($value)
				{
					return self::validateEmail($value, 'Contact email');
				},
			],
			'contact_phone' => [
				'type' => 'string',
				'required' => false,
				'maxLength' => 50,
				'sanitize' => 'string',
				'validator' => function ($value)
				{
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
				'validator' => function ($value)
				{
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
				'validator' => function ($value)
				{
					return self::validateNorwegianSSN($value, 'Customer SSN');
				},
			],
			'customer_organization_number' => [
				'type' => 'string',
				'required' => false,
				'sanitize' => 'string',
				'validator' => function ($value)
				{
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
			// Additional fields for custom handling
			// 'source' => [
			// 	'type' => 'string',
			// 	'required' => false,
			// 	'sanitize' => 'string',
			// ],
			// 'bridge_import' => [
			// 	'type' => 'bool',
			// 	'required' => false,
			// 	'sanitize' => 'bool',
			// ],
		];
	}

	/**
	 * Initialize field map - required by BaseModel
	 */
	protected function initializeFieldMap(): void
	{
		$this->fieldMap = static::getFieldMap();
	}

	/**
	 * Initialize relationship map - optional
	 */
	protected function initializeRelationshipMap(): void
	{
		$this->relationshipMap = static::getRelationshipMap();
	}

	/**
	 * Get relationship map - optional override
	 */
	protected static function getRelationshipMap(): array
	{

		return [
			'activity_name' => array(
				'type' => 'string',
				'query' => true,
				'join' => array(
					'table' => 'bb_activity',
					'fkey' => 'activity_id',
					'key' => 'id',
					'column' => 'name'
				)
			),
			'audience' => array(
				'type' => 'int',
				'required' => true,
				'manytomany' => array(
					'table' => 'bb_event_targetaudience',
					'key' => 'event_id',
					'column' => 'targetaudience_id'
				)
			),
			'agegroups' => array(
				'type' => 'int',
				'required' => true,
				'manytomany' => array(
					'table' => 'bb_event_agegroup',
					'key' => 'event_id',
					'column' => array(
						'agegroup_id' => array('type' => 'int', 'required' => true),
						'male' => array('type' => 'int', 'required' => true),
						'female' => array(
							'type' => 'int',
							'required' => true
						)
					),
				)
			),
			'comments' => array(
				'type' => 'string',
				'manytomany' => array(
					'table' => 'bb_event_comment',
					'key' => 'event_id',
					'column' => array('time' => array('type' => 'timestamp', 'read_callback' => 'modify_by_timezone'), 'author', 'comment', 'type'),
					'order' => array('sort' => 'time', 'dir' => 'ASC')
				)
			),
			'costs' => array(
				'type' => 'string',
				'manytomany' => array(
					'table' => 'bb_event_cost',
					'key' => 'event_id',
					'column' => array('time' => array('type' => 'timestamp', 'read_callback' => 'modify_by_timezone'), 'author', 'comment', 'cost'),
					'order' => array('sort' => 'time', 'dir' => 'ASC')
				)
			),
			'resources' => array(
				'type' => 'int',
				'required' => true,
				'sanitize' => 'array_int', // Array of integers
				'validator' => function ($value)
				{
					return self::validateNonEmptyArray($value, 'Resources');
				},
				'manytomany' => array(
					'table' => 'bb_event_resource',
					'key' => 'event_id',
					'column' => 'resource_id'
				)
			),
			'dates' => array(
				'type' => 'timestamp',
				'manytomany' => array(
					'table' => 'bb_event_date',
					'key' => 'event_id',
					'column' => array('from_', 'to_', 'id')
				)
			),
		];
	}

	/**
	 * Override BaseModel's doCustomValidation for Event-specific validation
	 */
	protected function doCustomValidation(): array
	{
		$errors = [];

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
				// Already handled by field validation
			}
		}

		return $errors;
	}

	/**
	 * Override BaseModel's create method to handle Event-specific logic
	 */
	protected function create(): bool
	{
		// Generate secret if not set
		if (empty($this->secret))
		{
			$this->secret = bin2hex(random_bytes(16));
		}

		// Call parent create method
		if (!parent::create())
		{
			return false;
		}

		// Update id_string to match the ID
		$this->id_string = (string)$this->id;
		$updateSql = "UPDATE bb_event SET id_string = :id_string WHERE id = :id";
		$updateStmt = $this->db->prepare($updateSql);
		$updateStmt->execute([
			':id_string' => $this->id_string,
			':id' => $this->id
		]);

		return true;
	}

	/**
	 * Override BaseModel's update method to handle Event-specific logic
	 */
	protected function update(): bool
	{
		// Call parent update method
		if (!parent::update())
		{
			return false;
		}

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
			':id' => $this->id
		]);

		return true;
	}

	/**
	 * Override BaseModel's saveRelationships method to handle Event-specific relationships
	 */
	protected function saveRelationships(): void
	{
		// Use BaseModel's relationship handling for defined relationships
		parent::saveRelationships();
		
		// Handle event dates (main date is already in the main table, but we also store in bb_event_date)
		$this->saveEventDates();

		// Only save defaults on create
		if ($this->wasRecentlyCreated()) {
			$this->saveDefaultAgeGroup();
			$this->saveDefaultTargetAudience();
		}
	}

	/**
	 * Check if this event was recently created (no existing audience/agegroups)
	 */
	protected function wasRecentlyCreated(): bool
	{
		// If we don't have an ID, it's definitely new
		if (!$this->id) {
			return true;
		}

		// Check if we have existing audience data
		$existingAudience = $this->loadRelationship('audience');
		return empty($existingAudience);
	}

	/**
	 * Convenience methods for relationships (use BaseModel's loadRelationship)
	 */
	public function getAudience(): array
	{
		return $this->loadRelationship('audience') ?? [];
	}

	public function getAgegroups(): array
	{
		return $this->loadRelationship('agegroups') ?? [];
	}

	public function getComments(): array
	{
		return $this->loadRelationship('comments') ?? [];
	}

	public function getCosts(): array
	{
		return $this->loadRelationship('costs') ?? [];
	}

	public function getDates(): array
	{
		return $this->loadRelationship('dates') ?? [];
	}

	public function getActivityName(): ?string
	{
		return $this->loadRelationship('activity_name');
	}

	/**
	 * Get resources relationship data
	 * Note: This uses the manytomany relationship definition
	 */
	public function getResourcesRelationship(): array
	{
		return $this->loadRelationship('resources') ?? [];
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
	 * Override BaseModel's deleteRelationships method for Event-specific cleanup
	 */
	protected function deleteRelationships(): void
	{
		if (!$this->id)
		{
			return;
		}

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
	}

	/**
	 * Override BaseModel's find method to load Event-specific relationships
	 */
	public static function find(int $id): ?static
	{
		$event = parent::find($id);
		if ($event)
		{
			// Load associated resources
			$resourceSql = "SELECT resource_id FROM bb_event_resource WHERE event_id = :event_id";
			$resourceStmt = $event->db->prepare($resourceSql);
			$resourceStmt->execute([':event_id' => $id]);
			$event->resources = $resourceStmt->fetchAll(PDO::FETCH_COLUMN);
		}
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

	/**
	 * Add a comment to this event
	 */
	public function addComment(string $comment, string $type = 'user', ?string $author = null, ?string $authorName = null): bool
	{
		if (!$this->id)
		{
			return false;
		}

		$sql = "INSERT INTO bb_event_comment (event_id, comment, type, time, author, author_name) VALUES (:event_id, :comment, :type, :time, :author, :author_name)";
		$stmt = $this->db->prepare($sql);

		try
		{
			$result = $stmt->execute([
				':event_id' => $this->id,
				':comment' => $comment,
				':type' => $type,
				':time' => time(),
				':author' => $author,
				':author_name' => $authorName
			]);

			// Clear cached comments to force reload
			if (isset($this->_relationshipCache['comments']))
			{
				unset($this->_relationshipCache['comments']);
			}

			return $result;
		}
		catch (Exception $e)
		{
			error_log("Error adding comment: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Add a cost to this event
	 */
	public function addCost(float $cost, string $description = ''): bool
	{
		if (!$this->id)
		{
			return false;
		}

		$sql = "INSERT INTO bb_event_cost (event_id, cost, description) VALUES (:event_id, :cost, :description)";
		$stmt = $this->db->prepare($sql);

		try
		{
			$result = $stmt->execute([
				':event_id' => $this->id,
				':cost' => $cost,
				':description' => $description
			]);

			// Clear cached costs to force reload
			if (isset($this->_relationshipCache['costs']))
			{
				unset($this->_relationshipCache['costs']);
			}

			return $result;
		}
		catch (Exception $e)
		{
			error_log("Error adding cost: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Add a date to this event
	 */
	public function addDate(string $from, string $to): bool
	{
		if (!$this->id)
		{
			return false;
		}

		$sql = "INSERT INTO bb_event_date (event_id, from_, to_) VALUES (:event_id, :from_, :to_)";
		$stmt = $this->db->prepare($sql);

		try
		{
			$result = $stmt->execute([
				':event_id' => $this->id,
				':from_' => $from,
				':to_' => $to
			]);

			// Clear cached dates to force reload
			if (isset($this->_relationshipCache['dates']))
			{
				unset($this->_relationshipCache['dates']);
			}

			return $result;
		}
		catch (Exception $e)
		{
			error_log("Error adding date: " . $e->getMessage());
			return false;
		}
	}
}
