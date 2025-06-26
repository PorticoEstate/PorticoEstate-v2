<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\booking\models\Event;
use App\modules\phpgwapi\security\Acl;
use App\Database\Db;
use PDO;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class EventController
{
	protected array $userSettings;
	protected Db $db;
	protected Acl $acl;

	public function __construct(ContainerInterface $container)
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->db = Db::getInstance();
		$this->acl = Acl::getInstance();
	}

	/**
	 * @OA\Post(
	 *     path="/booking/resources/{resource_id}/events",
	 *     summary="Create a new event for a specific resource (Outlook integration)",
	 *     description="Creates a new event from external calendar integration (e.g., Outlook). This endpoint is designed for calendar bridge services.",
	 *     tags={"Events"},
	 *     @OA\Parameter(
	 *         name="resource_id",
	 *         in="path",
	 *         description="ID of the resource",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         description="Event data from calendar bridge",
	 *         @OA\JsonContent(
	 *             required={"title", "from_", "to_", "source"},
	 *             @OA\Property(property="title", type="string", description="Event title", example="Team Meeting"),
	 *             @OA\Property(property="from_", type="string", format="date-time", description="Event start time (ISO 8601)", example="2025-06-25T15:30:00+02:00"),
	 *             @OA\Property(property="to_", type="string", format="date-time", description="Event end time (ISO 8601)", example="2025-06-25T16:00:00+02:00"),
	 *             @OA\Property(property="description", type="string", description="Event description", example="Weekly team sync meeting"),
	 *             @OA\Property(property="contact_name", type="string", description="Contact name", example="john.doe@company.com"),
	 *             @OA\Property(property="contact_email", type="string", description="Contact email", example="attendee@company.com"),
	 *             @OA\Property(property="source", type="string", description="Source of the event", example="calendar_bridge"),
	 *             @OA\Property(property="bridge_import", type="boolean", description="Indicates if this is a bridge import", example=true),
	 *             @OA\Property(property="type", type="string", description="Event type", example="event")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Event created successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="id", type="integer", description="Created event ID"),
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="event", type="object", ref="#/components/schemas/Event")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - Invalid input data",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Invalid date format or missing required fields")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Resource not found",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Resource not found")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=409,
	 *         description="Conflict - Duplicate or overlapping event",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Time conflict: Overlaps with existing event #123")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Failed to create event")
	 *         )
	 *     )
	 * )
	 */
	public function createForResource(Request $request, Response $response, array $args): Response
	{
		// Check permissions
		if (!$this->acl->check('.application', Acl::ADD, 'booking'))
		{
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try
		{
			$resourceId = (int)$args['resource_id'];

			// Validate resource ID
			if ($resourceId <= 0)
			{
				$response->getBody()->write(json_encode(['error' => 'Invalid resource ID']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Verify resource exists
			if (!Event::resourceExists($resourceId))
			{
				$response->getBody()->write(json_encode(['error' => 'Resource not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Parse request data (handle both JSON and form-encoded data)
			$eventData = [];
			$contentType = $request->getHeaderLine('Content-Type');

			if (strpos($contentType, 'application/json') !== false)
			{
				// Handle JSON data
				$body = $request->getBody()->getContents();
				$eventData = json_decode($body, true);

				if (json_last_error() !== JSON_ERROR_NONE)
				{
					$response->getBody()->write(json_encode(['error' => 'Invalid JSON format']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}
			else
			{
				// Handle form-encoded data ($_POST)
				$eventData = $request->getParsedBody() ?: [];

				if (empty($eventData))
				{
					$response->getBody()->write(json_encode(['error' => 'No data received']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}

			// Validate required fields
			$requiredFields = ['title', 'from_', 'to_', 'source'];
			foreach ($requiredFields as $field)
			{
				if (!isset($eventData[$field]) || empty($eventData[$field]))
				{
					$response->getBody()->write(json_encode(['error' => "Missing required field: $field"]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}

			// Prepare event data for model
			$modelData = $this->prepareEventDataForModel($resourceId, $eventData);

			// Create new event instance
			$event = new Event($modelData);

			// Ensure secret is set before validation
			if (empty($event->secret))
			{
				$event->secret = bin2hex(random_bytes(16));
			}

			// Validate the event
			$validationErrors = $event->validate();
			if (!empty($validationErrors))
			{
				$response->getBody()->write(json_encode(['error' => 'Validation failed', 'details' => $validationErrors]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Check for conflicts
			$conflictErrors = $event->checkConflicts();
			if (!empty($conflictErrors))
			{
				$response->getBody()->write(json_encode(['error' => 'Time conflict detected', 'details' => $conflictErrors]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
			}

			// Save the event
			$saveSuccess = $event->save();
			if (!$saveSuccess)
			{
				$response->getBody()->write(json_encode(['error' => 'Failed to create event']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			// Return success response with serialized event
			$responseData = [
				'id' => $event->id,
				'event_id' => $event->id,
				'success' => true,
				'event' => $event->serialize()
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		}
		catch (Exception $e)
		{
			$response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Prepare event data for the Event model
	 */
	private function prepareEventDataForModel(int $resourceId, array $eventData): array
	{
		// Get building information for the resource
		$buildingInfo = Event::getBuildingInfoForResource($resourceId);
		if (!$buildingInfo)
		{
			throw new Exception('Could not find building information for resource');
		}

		// Map incoming data to Event model structure
		return [
			// Basic event information
			'name' => $eventData['title'],
			'from_' => $eventData['from_'],
			'to_' => $eventData['to_'],
			'description' => $eventData['description'] ?? '',
			'contact_name' => $eventData['contact_name'] ?? '',
			'contact_email' => $eventData['contact_email'] ?? '',
			'organizer' => $eventData['contact_name'] ?? 'Calendar Bridge',

			// Required fields
			'activity_id' => 1, // Default activity - configurable
			'building_id' => $buildingInfo['id'],
			'building_name' => $buildingInfo['name'],
			'cost' => 0.00, // Default to 0 for bridge imports
			'customer_internal' => 0, // External by default for bridge imports
			'include_in_list' => 0, // Don't include in public lists by default
			'reminder' => 1, // Default reminder setting

			// Status fields
			'active' => 1,
			'is_public' => 0, // Private by default for bridge imports
			'completed' => 0,

			// Optional fields with defaults
			'contact_phone' => $eventData['contact_phone'] ?? '',
			'homepage' => $eventData['homepage'] ?? '',
			'equipment' => $eventData['equipment'] ?? '',
			'access_requested' => 0,
			'participant_limit' => $eventData['participant_limit'] ?? null,
			'customer_identifier_type' => null,
			'customer_ssn' => null,
			'customer_organization_number' => null,
			'customer_organization_id' => null,
			'customer_organization_name' => null,
			'additional_invoice_information' => null,
			'sms_total' => null,
			'skip_bas' => 0,

			// Resource association
			'resources' => [$resourceId],
		];
	}


	/**
	 * Create event record in the database
	 */
	private function createEventInDatabase(int $resourceId, array $eventData, \DateTime $fromDate, \DateTime $toDate): ?int
	{
		try
		{
			$this->db->beginTransaction();

			// Get building information for the resource
			$buildingInfo = $this->getBuildingInfoForResource($resourceId);
			if (!$buildingInfo)
			{
				throw new Exception('Could not find building information for resource');
			}

			// Generate a secret for the event
			$secret = $this->generateEventSecret();

			// Prepare event data for database insertion with ALL required fields
			$dbEventData = [
				// Basic event information
				'name' => $eventData['title'],
				'from_' => $fromDate->format('Y-m-d H:i:s'),
				'to_' => $toDate->format('Y-m-d H:i:s'),
				'description' => $eventData['description'] ?? '',
				'contact_name' => $eventData['contact_name'] ?? '',
				'contact_email' => $eventData['contact_email'] ?? '',
				'organizer' => $eventData['contact_name'] ?? 'Calendar Bridge',

				// Required fields from soevent constructor
				'activity_id' => 1, // Default activity - you may want to make this configurable
				'building_id' => $buildingInfo['id'],
				'building_name' => $buildingInfo['name'],
				'cost' => 0.00, // Default to 0 for bridge imports
				'secret' => $secret,
				'customer_internal' => 0, // External by default for bridge imports
				'include_in_list' => 0, // Don't include in public lists by default
				'reminder' => 1, // Default reminder setting

				// Status fields
				'active' => 1,
				'is_public' => 0, // Private by default for bridge imports
				'completed' => 0,

				// Optional fields with defaults
				'contact_phone' => $eventData['contact_phone'] ?? '',
				'homepage' => $eventData['homepage'] ?? '',
				'equipment' => $eventData['equipment'] ?? '',
				'access_requested' => 0,
				'participant_limit' => $eventData['participant_limit'] ?? null,
				'customer_identifier_type' => null,
				'customer_ssn' => null,
				'customer_organization_number' => null,
				'customer_organization_id' => null,
				'customer_organization_name' => null,
				'additional_invoice_information' => null,
				'sms_total' => null,
				'skip_bas' => 0,
				'application_id' => null,

				// Metadata fields
				'id_string' => 0, // Will be set after insert
			];

			// Insert event
			$eventColumns = array_keys($dbEventData);
			$eventPlaceholders = ':' . implode(', :', $eventColumns);

			$eventSql = "INSERT INTO bb_event (" . implode(', ', $eventColumns) . ") VALUES (" . $eventPlaceholders . ") RETURNING id";
			$eventStmt = $this->db->prepare($eventSql);

			// Bind parameters
			foreach ($dbEventData as $key => $value)
			{
				$eventStmt->bindValue(":$key", $value);
			}

			$eventStmt->execute();
			$eventId = $eventStmt->fetchColumn();

			if (!$eventId)
			{
				throw new Exception('Failed to get event ID after insertion');
			}

			// Update id_string to match the ID
			$updateIdStringSql = "UPDATE bb_event SET id_string = :id_string WHERE id = :id";
			$updateIdStringStmt = $this->db->prepare($updateIdStringSql);
			$updateIdStringStmt->execute([
				':id_string' => (string)$eventId,
				':id' => $eventId
			]);

			// Link event to resource
			$resourceSql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES (:event_id, :resource_id)";
			$resourceStmt = $this->db->prepare($resourceSql);
			$resourceStmt->execute([
				':event_id' => $eventId,
				':resource_id' => $resourceId
			]);

			// Insert event date record
			$eventDateSql = "INSERT INTO bb_event_date (event_id, from_, to_) VALUES (:event_id, :from_, :to_)";
			$eventDateStmt = $this->db->prepare($eventDateSql);
			$eventDateStmt->execute([
				':event_id' => $eventId,
				':from_' => $fromDate->format('Y-m-d H:i:s'),
				':to_' => $toDate->format('Y-m-d H:i:s')
			]);

			// Insert default age group (use first available)
			$ageGroupSql = "SELECT id FROM bb_agegroup ORDER BY id LIMIT 1";
			$ageGroupStmt = $this->db->prepare($ageGroupSql);
			$ageGroupStmt->execute();
			$ageGroupId = $ageGroupStmt->fetchColumn();

			if ($ageGroupId)
			{
				$eventAgeGroupSql = "INSERT INTO bb_event_agegroup (event_id, agegroup_id, male, female) VALUES (:event_id, :agegroup_id, :male, :female)";
				$eventAgeGroupStmt = $this->db->prepare($eventAgeGroupSql);
				$eventAgeGroupStmt->execute([
					':event_id' => $eventId,
					':agegroup_id' => $ageGroupId,
					':male' => 0, // Default values for bridge imports
					':female' => 0
				]);
			}

			// Insert default target audience (use first available)
			$targetAudienceSql = "SELECT id FROM bb_targetaudience ORDER BY id LIMIT 1";
			$targetAudienceStmt = $this->db->prepare($targetAudienceSql);
			$targetAudienceStmt->execute();
			$targetAudienceId = $targetAudienceStmt->fetchColumn();

			if ($targetAudienceId)
			{
				$eventTargetAudienceSql = "INSERT INTO bb_event_targetaudience (event_id, targetaudience_id) VALUES (:event_id, :targetaudience_id)";
				$eventTargetAudienceStmt = $this->db->prepare($eventTargetAudienceSql);
				$eventTargetAudienceStmt->execute([
					':event_id' => $eventId,
					':targetaudience_id' => $targetAudienceId
				]);
			}

			$this->db->commit();
			return (int)$eventId;
		}
		catch (Exception $e)
		{
			$this->db->rollback();
			error_log("Error creating event: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Get building information for a resource
	 */
	private function getBuildingInfoForResource(int $resourceId): ?array
	{
		$sql = "SELECT bb_building.id, bb_building.name 
				FROM bb_building 
				JOIN bb_building_resource ON bb_building.id = bb_building_resource.building_id 
				WHERE bb_building_resource.resource_id = :resource_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':resource_id' => $resourceId]);
		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return $result ?: null;
	}

	/**
	 * Generate a unique secret for the event
	 */
	private function generateEventSecret(): string
	{
		return bin2hex(random_bytes(16)); // 32 character hex string
	}

	/**
	 * Check for event conflicts (duplicates and overlaps)
	 */
	private function checkEventConflicts(int $resourceId, \DateTime $fromDate, \DateTime $toDate, array $eventData): ?string
	{
		$start = $fromDate->format('Y-m-d H:i:s');
		$end = $toDate->format('Y-m-d H:i:s');

		// Check for exact duplicates first (same resource, same time, same title)
		$duplicateSql = "SELECT e.id FROM bb_event e
			JOIN bb_event_resource er ON e.id = er.event_id
			WHERE er.resource_id = :resource_id 
			AND e.active = 1
			AND e.from_ = :from_date
			AND e.to_ = :to_date
			AND e.name = :event_name";

		$duplicateStmt = $this->db->prepare($duplicateSql);
		$duplicateStmt->execute([
			':resource_id' => $resourceId,
			':from_date' => $start,
			':to_date' => $end,
			':event_name' => $eventData['title']
		]);

		if ($duplicateStmt->fetch())
		{
			return 'Duplicate event detected: An identical event already exists for this resource at the same time';
		}

		// Check for overlapping events (based on soevent validation logic)
		$overlapSql = "SELECT e.id, e.name FROM bb_event e
			WHERE e.active = 1 
			AND e.id IN (SELECT event_id FROM bb_event_resource WHERE resource_id = :resource_id)
			AND ((e.from_ >= :start AND e.from_ < :end) OR
				 (e.to_ > :start AND e.to_ <= :end) OR
				 (e.from_ < :start AND e.to_ > :end))";

		$overlapStmt = $this->db->prepare($overlapSql);
		$overlapStmt->execute([
			':resource_id' => $resourceId,
			':start' => $start,
			':end' => $end
		]);

		if ($overlapResult = $overlapStmt->fetch(PDO::FETCH_ASSOC))
		{
			return "Time conflict: Overlaps with existing event #{$overlapResult['id']} - {$overlapResult['name']}";
		}

		// Check for overlapping allocations
		$allocationOverlapSql = "SELECT a.id FROM bb_allocation a
			WHERE a.active = 1 
			AND a.id IN (SELECT allocation_id FROM bb_allocation_resource WHERE resource_id = :resource_id)
			AND ((a.from_ >= :start AND a.from_ < :end) OR
				 (a.to_ > :start AND a.to_ <= :end) OR
				 (a.from_ < :start AND a.to_ > :end))";

		$allocationStmt = $this->db->prepare($allocationOverlapSql);
		$allocationStmt->execute([
			':resource_id' => $resourceId,
			':start' => $start,
			':end' => $end
		]);

		if ($allocationResult = $allocationStmt->fetch(PDO::FETCH_ASSOC))
		{
			return "Time conflict: Overlaps with existing allocation #{$allocationResult['id']}";
		}

		// Check for overlapping bookings
		$bookingOverlapSql = "SELECT b.id FROM bb_booking b
			WHERE b.active = 1 
			AND b.id IN (SELECT booking_id FROM bb_booking_resource WHERE resource_id = :resource_id)
			AND ((b.from_ >= :start AND b.from_ < :end) OR
				 (b.to_ > :start AND b.to_ <= :end) OR
				 (b.from_ < :start AND b.to_ > :end))";

		$bookingStmt = $this->db->prepare($bookingOverlapSql);
		$bookingStmt->execute([
			':resource_id' => $resourceId,
			':start' => $start,
			':end' => $end
		]);

		if ($bookingResult = $bookingStmt->fetch(PDO::FETCH_ASSOC))
		{
			return "Time conflict: Overlaps with existing booking #{$bookingResult['id']}";
		}

		// No conflicts found
		return null;
	}

	/**
	 * @OA\Put(
	 *     path="/booking/events/{event_id}",
	 *     summary="Update an existing event",
	 *     description="Updates an existing event. Can be used for calendar bridge synchronization or admin updates.",
	 *     tags={"Events"},
	 *     @OA\Parameter(
	 *         name="event_id",
	 *         in="path",
	 *         description="ID of the event to update",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         description="Updated event data",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="title", type="string", description="Event title", example="Updated Team Meeting"),
	 *             @OA\Property(property="from_", type="string", format="date-time", description="Event start time (ISO 8601)", example="2025-06-25T15:30:00+02:00"),
	 *             @OA\Property(property="to_", type="string", format="date-time", description="Event end time (ISO 8601)", example="2025-06-25T16:30:00+02:00"),
	 *             @OA\Property(property="description", type="string", description="Event description", example="Updated weekly team sync meeting"),
	 *             @OA\Property(property="contact_name", type="string", description="Contact name", example="john.doe@company.com"),
	 *             @OA\Property(property="contact_email", type="string", description="Contact email", example="attendee@company.com"),
	 *             @OA\Property(property="contact_phone", type="string", description="Contact phone", example="+47 123 45 678"),
	 *             @OA\Property(property="equipment", type="string", description="Required equipment", example="Projector, Whiteboard"),
	 *             @OA\Property(property="participant_limit", type="integer", description="Maximum participants", example=20)
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Event updated successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="message", type="string", example="Event updated successfully"),
	 *             @OA\Property(property="event", type="object", ref="#/components/schemas/Event")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - Invalid input data",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Invalid date format or data")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Event not found",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Event not found")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=409,
	 *         description="Conflict - Time conflict with other events",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Time conflict: Overlaps with existing event #123")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Failed to update event")
	 *         )
	 *     )
	 * )
	 */
	public function updateEvent(Request $request, Response $response, array $args): Response
	{
		// Check permissions
		if (!$this->acl->check('.application', Acl::EDIT, 'booking'))
		{
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try
		{
			$eventId = (int)$args['event_id'];

			// Validate event ID
			if ($eventId <= 0)
			{
				$response->getBody()->write(json_encode(['error' => 'Invalid event ID']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Load the existing event
			$event = Event::find($eventId);
			if (!$event)
			{
				$response->getBody()->write(json_encode(['error' => 'Event not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Parse request data (handle both JSON and form-encoded data)
			$updateData = [];
			$contentType = $request->getHeaderLine('Content-Type');

			if (strpos($contentType, 'application/json') !== false)
			{
				// Handle JSON data
				$body = $request->getBody()->getContents();
				$updateData = json_decode($body, true);

				if (json_last_error() !== JSON_ERROR_NONE)
				{
					$response->getBody()->write(json_encode(['error' => 'Invalid JSON format']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}
			else
			{
				// Handle form-encoded data - for PUT requests, we need to read raw body
				$updateData = $request->getParsedBody() ?: [];

				// If no parsed body (common with PUT requests), read raw input and parse manually
				if (empty($updateData))
				{
					$body = $request->getBody()->getContents();
					if (!empty($body))
					{
						parse_str($body, $updateData);
					}
				}
			}

			if (empty($updateData))
			{
				$response->getBody()->write(json_encode(['error' => 'No update data provided']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Update the event with new data
			$event->populate($updateData);

			// Validate the updated event
			$validationErrors = $event->validate();
			if (!empty($validationErrors))
			{
				$response->getBody()->write(json_encode(['error' => 'Validation failed', 'details' => $validationErrors]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Check for conflicts if dates changed
			if (isset($updateData['from_']) || isset($updateData['to_']))
			{
				$conflictErrors = $event->checkConflicts($eventId);
				if (!empty($conflictErrors))
				{
					$response->getBody()->write(json_encode(['error' => 'Time conflict detected', 'details' => $conflictErrors]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
				}
			}

			// Save the updated event
			$saveSuccess = $event->save();
			if (!$saveSuccess)
			{
				$response->getBody()->write(json_encode(['error' => 'Failed to update event']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			// Return success response with updated event
			$responseData = [
				'success' => true,
				'event' => $event->serialize()
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Get event by ID
	 */
	private function getEventById(int $eventId): ?array
	{
		$sql = "SELECT e.*, 
				   ARRAY_AGG(DISTINCT er.resource_id) as resource_ids,
				   bb_building.name as building_name
				FROM bb_event e
				LEFT JOIN bb_event_resource er ON e.id = er.event_id
				LEFT JOIN bb_building ON e.building_id = bb_building.id
				WHERE e.id = :event_id AND e.active = 1
				GROUP BY e.id, bb_building.name";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':event_id' => $eventId]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Get resource IDs associated with an event
	 */
	private function getEventResourceIds(int $eventId): array
	{
		$sql = "SELECT resource_id FROM bb_event_resource WHERE event_id = :event_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':event_id' => $eventId]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * Check for event conflicts during update (excludes the current event)
	 */
	private function checkEventConflictsForUpdate(int $eventId, int $resourceId, \DateTime $fromDate, \DateTime $toDate, array $eventData): ?string
	{
		$start = $fromDate->format('Y-m-d H:i:s');
		$end = $toDate->format('Y-m-d H:i:s');

		// Check for overlapping events (excluding the current event)
		$overlapSql = "SELECT e.id, e.name FROM bb_event e
			WHERE e.active = 1 
			AND e.id != :event_id
			AND e.id IN (SELECT event_id FROM bb_event_resource WHERE resource_id = :resource_id)
			AND ((e.from_ >= :start AND e.from_ < :end) OR
				 (e.to_ > :start AND e.to_ <= :end) OR
				 (e.from_ < :start AND e.to_ > :end))";

		$overlapStmt = $this->db->prepare($overlapSql);
		$overlapStmt->execute([
			':event_id' => $eventId,
			':resource_id' => $resourceId,
			':start' => $start,
			':end' => $end
		]);

		if ($overlapResult = $overlapStmt->fetch(PDO::FETCH_ASSOC))
		{
			return "Time conflict: Overlaps with existing event #{$overlapResult['id']} - {$overlapResult['name']}";
		}

		// Check for overlapping allocations
		$allocationOverlapSql = "SELECT a.id FROM bb_allocation a
			WHERE a.active = 1 
			AND a.id IN (SELECT allocation_id FROM bb_allocation_resource WHERE resource_id = :resource_id)
			AND ((a.from_ >= :start AND a.from_ < :end) OR
				 (a.to_ > :start AND a.to_ <= :end) OR
				 (a.from_ < :start AND a.to_ > :end))";

		$allocationStmt = $this->db->prepare($allocationOverlapSql);
		$allocationStmt->execute([
			':resource_id' => $resourceId,
			':start' => $start,
			':end' => $end
		]);

		if ($allocationResult = $allocationStmt->fetch(PDO::FETCH_ASSOC))
		{
			return "Time conflict: Overlaps with existing allocation #{$allocationResult['id']}";
		}

		// Check for overlapping bookings
		$bookingOverlapSql = "SELECT b.id FROM bb_booking b
			WHERE b.active = 1 
			AND b.id IN (SELECT booking_id FROM bb_booking_resource WHERE resource_id = :resource_id)
			AND ((b.from_ >= :start AND b.from_ < :end) OR
				 (b.to_ > :start AND b.to_ <= :end) OR
				 (b.from_ < :start AND b.to_ > :end))";

		$bookingStmt = $this->db->prepare($bookingOverlapSql);
		$bookingStmt->execute([
			':resource_id' => $resourceId,
			':start' => $start,
			':end' => $end
		]);

		if ($bookingResult = $bookingStmt->fetch(PDO::FETCH_ASSOC))
		{
			return "Time conflict: Overlaps with existing booking #{$bookingResult['id']}";
		}

		// No conflicts found
		return null;
	}

	/**
	 * @OA\Patch(
	 *     path="/booking/events/{event_id}/toggle-active",
	 *     summary="Toggle the active status of an event",
	 *     description="Toggles the active flag of an event (soft delete/activate). When active=0, the event is effectively deleted but preserved in the database.",
	 *     tags={"Events"},
	 *     @OA\Parameter(
	 *         name="event_id",
	 *         in="path",
	 *         description="ID of the event to toggle",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\RequestBody(
	 *         required=false,
	 *         description="Optional: explicitly set active status",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="active", type="boolean", description="Set active status explicitly (if not provided, it will be toggled)", example=false)
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Event active status toggled successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="message", type="string", example="Event activated successfully"),
	 *             @OA\Property(property="event_id", type="integer", example=123),
	 *             @OA\Property(property="active", type="boolean", example=true)
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - Invalid event ID",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Invalid event ID")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Event not found",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Event not found")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Failed to update event status")
	 *         )
	 *     )
	 * )
	 */
	public function toggleActiveStatus(Request $request, Response $response, array $args): Response
	{
		// Check permissions
		if (!$this->acl->check('.application', Acl::EDIT, 'booking'))
		{
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try
		{
			$eventId = (int)$args['event_id'];

			// Validate event ID
			if ($eventId <= 0)
			{
				$response->getBody()->write(json_encode(['error' => 'Invalid event ID']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Load the event (including inactive ones for this operation)
			$event = Event::find($eventId);
			if (!$event)
			{
				$response->getBody()->write(json_encode(['error' => 'Event not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Parse request data to check if active status is explicitly provided
			$requestData = [];
			$contentType = $request->getHeaderLine('Content-Type');

			if (strpos($contentType, 'application/json') !== false)
			{
				$body = $request->getBody()->getContents();
				if (!empty($body))
				{
					$requestData = json_decode($body, true);
					if (json_last_error() !== JSON_ERROR_NONE)
					{
						$response->getBody()->write(json_encode(['error' => 'Invalid JSON format']));
						return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
					}
				}
			}
			else
			{
				// Handle form-encoded data - for PATCH requests, we need to read raw body
				$requestData = $request->getParsedBody() ?: [];

				// If no parsed body (common with PATCH requests), read raw input and parse manually
				if (empty($requestData))
				{
					$body = $request->getBody()->getContents();
					if (!empty($body))
					{
						parse_str($body, $requestData);
					}
				}
			}

			// Determine new active status
			$newActiveStatus = isset($requestData['active'])
				? (bool)$requestData['active']
				: !((bool)$event->active); // Toggle if not explicitly set

			// Update the event's active status
			$event->active = $newActiveStatus ? 1 : 0;

			// Save the event
			$saveSuccess = $event->save();
			if (!$saveSuccess)
			{
				$response->getBody()->write(json_encode(['error' => 'Failed to update event status']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			// Prepare response message
			$message = $newActiveStatus ? 'Event activated successfully' : 'Event deactivated successfully';

			// Return success response
			$responseData = [
				'success' => true,
				'message' => $message,
				'event_id' => $eventId,
				'active' => $newActiveStatus,
				'event' => $event->serialize()
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Get event by ID including inactive events
	 */
	private function getEventByIdIncludingInactive(int $eventId): ?array
	{
		$sql = "SELECT e.*, 
				   ARRAY_AGG(DISTINCT er.resource_id) as resource_ids,
				   bb_building.name as building_name
				FROM bb_event e
				LEFT JOIN bb_event_resource er ON e.id = er.event_id
				LEFT JOIN bb_building ON e.building_id = bb_building.id
				WHERE e.id = :event_id
				GROUP BY e.id, bb_building.name";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':event_id' => $eventId]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Update event active status in database
	 */
	private function updateEventActiveStatus(int $eventId, bool $active): bool
	{
		try
		{
			$sql = "UPDATE bb_event SET active = :active WHERE id = :event_id";
			$stmt = $this->db->prepare($sql);
			return $stmt->execute([
				':active' => $active ? 1 : 0,
				':event_id' => $eventId
			]);
		}
		catch (Exception $e)
		{
			error_log("Error updating event active status: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Update event record in the database
	 */
	private function updateEventInDatabase(int $eventId, array $updateData, ?\DateTime $fromDate, ?\DateTime $toDate): bool
	{
		try
		{
			$this->db->beginTransaction();

			// Build update query dynamically based on provided data
			$updateFields = [];
			$updateParams = [':event_id' => $eventId];

			// Map of allowed fields to update
			$allowedFields = [
				'title' => 'name',
				'name' => 'name',
				'description' => 'description',
				'contact_name' => 'contact_name',
				'contact_email' => 'contact_email',
				'contact_phone' => 'contact_phone',
				'organizer' => 'organizer',
				'equipment' => 'equipment',
				'participant_limit' => 'participant_limit',
				'homepage' => 'homepage',
				'is_public' => 'is_public',
				'completed' => 'completed'
			];

			// Add fields to update
			foreach ($allowedFields as $inputField => $dbField)
			{
				if (isset($updateData[$inputField]))
				{
					$updateFields[] = "$dbField = :$dbField";
					$updateParams[":$dbField"] = $updateData[$inputField];
				}
			}

			// Add date fields if provided
			if ($fromDate && $toDate)
			{
				$updateFields[] = "from_ = :from_";
				$updateFields[] = "to_ = :to_";
				$updateParams[':from_'] = $fromDate->format('Y-m-d H:i:s');
				$updateParams[':to_'] = $toDate->format('Y-m-d H:i:s');
			}

			if (empty($updateFields))
			{
				// No valid fields to update
				$this->db->rollback();
				return false;
			}

			// Update bb_event table
			$updateSql = "UPDATE bb_event SET " . implode(', ', $updateFields) . " WHERE id = :event_id";
			$updateStmt = $this->db->prepare($updateSql);
			$updateStmt->execute($updateParams);

			// Update bb_event_date table if dates changed
			if ($fromDate && $toDate)
			{
				$updateDateSql = "UPDATE bb_event_date SET from_ = :from_, to_ = :to_ WHERE event_id = :event_id";
				$updateDateStmt = $this->db->prepare($updateDateSql);
				$updateDateStmt->execute([
					':event_id' => $eventId,
					':from_' => $fromDate->format('Y-m-d H:i:s'),
					':to_' => $toDate->format('Y-m-d H:i:s')
				]);
			}

			$this->db->commit();
			return true;
		}
		catch (Exception $e)
		{
			$this->db->rollback();
			error_log("Error updating event: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * @OA\Post(
	 *     path="/booking/events",
	 *     summary="Create a new event for multiple resources",
	 *     description="Creates a new event that can be associated with multiple resources. This endpoint reuses the single resource creation logic and adds additional resource associations.",
	 *     tags={"Events"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         description="Event data with array of resources",
	 *         @OA\JsonContent(
	 *             required={"title", "from_", "to_", "resource_ids"},
	 *             @OA\Property(property="title", type="string", description="Event title", example="Multi-Room Conference"),
	 *             @OA\Property(property="from_", type="string", format="date-time", description="Event start time (ISO 8601)", example="2025-06-25T15:30:00+02:00"),
	 *             @OA\Property(property="to_", type="string", format="date-time", description="Event end time (ISO 8601)", example="2025-06-25T17:00:00+02:00"),
	 *             @OA\Property(
	 *                 property="resource_ids",
	 *                 type="array",
	 *                 description="Array of resource IDs to associate with the event",
	 *                 @OA\Items(type="integer"),
	 *                 example={1, 2, 5}
	 *             ),
	 *             @OA\Property(property="description", type="string", description="Event description", example="Large conference requiring multiple rooms"),
	 *             @OA\Property(property="contact_name", type="string", description="Contact name", example="john.doe@company.com"),
	 *             @OA\Property(property="contact_email", type="string", description="Contact email", example="attendee@company.com"),
	 *             @OA\Property(property="contact_phone", type="string", description="Contact phone", example="+47 123 45 678"),
	 *             @OA\Property(property="source", type="string", description="Source of the event", example="admin_panel"),
	 *             @OA\Property(property="equipment", type="string", description="Required equipment", example="Projector, Microphones"),
	 *             @OA\Property(property="participant_limit", type="integer", description="Maximum participants", example=50),
	 *             @OA\Property(property="is_public", type="boolean", description="Whether event is public", example=true)
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Event created successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="id", type="integer", description="Created event ID"),
	 *             @OA\Property(property="event_id", type="integer", description="Created event ID"),
	 *             @OA\Property(property="message", type="string", example="Event created successfully for 3 resources"),
	 *             @OA\Property(property="event", type="object", ref="#/components/schemas/Event"),
	 *             @OA\Property(
	 *                 property="resources",
	 *                 type="array",
	 *                 description="Resources associated with the event",
	 *                 @OA\Items(type="integer")
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - Invalid input data",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Invalid date format or missing required fields")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="One or more resources not found",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Resource not found: 123, 456")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=409,
	 *         description="Conflict - Time conflict with existing events/bookings",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Time conflict on resource 123: Overlaps with existing event #456")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Failed to create event")
	 *         )
	 *     )
	 * )
	 */
	public function createEvent(Request $request, Response $response): Response
	{
		// Check permissions
		if (!$this->acl->check('.application', Acl::ADD, 'booking'))
		{
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try
		{
			// Parse request data (handle both JSON and form-encoded data)
			$eventData = [];
			$contentType = $request->getHeaderLine('Content-Type');

			if (strpos($contentType, 'application/json') !== false)
			{
				// Handle JSON data
				$body = $request->getBody()->getContents();
				$eventData = json_decode($body, true);

				if (json_last_error() !== JSON_ERROR_NONE)
				{
					$response->getBody()->write(json_encode(['error' => 'Invalid JSON format']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}
			else
			{
				// Handle form-encoded data
				$eventData = $request->getParsedBody() ?: [];

				// Convert comma-separated resource_ids string to array if needed
				if (isset($eventData['resource_ids']) && is_string($eventData['resource_ids']))
				{
					$eventData['resource_ids'] = array_map('intval', explode(',', $eventData['resource_ids']));
				}

				if (empty($eventData))
				{
					$response->getBody()->write(json_encode(['error' => 'No data received']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}

			// Validate required fields
			$requiredFields = ['title', 'from_', 'to_', 'resource_ids'];
			foreach ($requiredFields as $field)
			{
				if (!isset($eventData[$field]) || empty($eventData[$field]))
				{
					$response->getBody()->write(json_encode(['error' => "Missing required field: $field"]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}

			// Validate resource_ids is an array
			if (!is_array($eventData['resource_ids']))
			{
				$response->getBody()->write(json_encode(['error' => 'resource_ids must be an array of integers']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Convert to integers and validate
			$resourceIds = array_map('intval', $eventData['resource_ids']);
			$resourceIds = array_filter($resourceIds, function ($id)
			{
				return $id > 0;
			});

			if (empty($resourceIds))
			{
				$response->getBody()->write(json_encode(['error' => 'At least one valid resource_id is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Verify all resources exist
			$nonExistentResources = [];
			foreach ($resourceIds as $resourceId)
			{
				if (!Event::resourceExists($resourceId))
				{
					$nonExistentResources[] = $resourceId;
				}
			}

			if (!empty($nonExistentResources))
			{
				$response->getBody()->write(json_encode([
					'error' => 'Resource not found: ' . implode(', ', $nonExistentResources)
				]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Prepare event data for model (using first resource as primary)
			$primaryResourceId = $resourceIds[0];
			$modelData = $this->prepareEventDataForModel($primaryResourceId, $eventData);
			$modelData['resources'] = $resourceIds; // Set all resources

			// Create new event instance
			$event = new Event($modelData);

			// Ensure secret is set before validation
			if (empty($event->secret))
			{
				$event->secret = bin2hex(random_bytes(16));
			}

			// Validate the event
			$validationErrors = $event->validate();
			if (!empty($validationErrors))
			{
				$response->getBody()->write(json_encode(['error' => 'Validation failed', 'details' => $validationErrors]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Check for conflicts on all resources
			$conflictErrors = $event->checkConflicts();
			if (!empty($conflictErrors))
			{
				$response->getBody()->write(json_encode(['error' => 'Time conflict detected', 'details' => $conflictErrors]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
			}

			// Save the event
			$saveSuccess = $event->save();
			if (!$saveSuccess)
			{
				$response->getBody()->write(json_encode(['error' => 'Failed to create event']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			// Return success response
			$responseData = [
				'success' => true,
				'id' => $event->id,
				'event_id' => $event->id,
				'message' => 'Event created successfully for ' . count($resourceIds) . ' resource' . (count($resourceIds) > 1 ? 's' : ''),
				'event' => $event->serialize(),
				'resources' => $resourceIds
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		}
		catch (Exception $e)
		{
			$response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Add additional resources to an existing event
	 */
	private function addAdditionalResourcesToEvent(int $eventId, array $resourceIds): bool
	{
		try
		{
			$this->db->beginTransaction();

			// Insert additional resource associations
			$resourceSql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES (:event_id, :resource_id)";
			$resourceStmt = $this->db->prepare($resourceSql);

			foreach ($resourceIds as $resourceId)
			{
				$resourceStmt->execute([
					':event_id' => $eventId,
					':resource_id' => $resourceId
				]);
			}

			$this->db->commit();
			return true;
		}
		catch (Exception $e)
		{
			$this->db->rollback();
			error_log("Error adding additional resources to event $eventId: " . $e->getMessage());
			return false;
		}
	}
}
