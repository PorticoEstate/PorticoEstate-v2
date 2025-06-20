<?php

namespace App\modules\booking\controllers;

use App\modules\bookingfrontend\services\ScheduleEntityService;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use App\modules\bookingfrontend\models\Resource;
use PDO;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class ResourceController
{
	protected ScheduleEntityService $scheduleEntityService;
	protected array $userSettings;
	protected Db $db;

	public function __construct(ContainerInterface $container)
	{
		$this->scheduleEntityService = new ScheduleEntityService($container);
		$this->userSettings = Settings::getInstance()->get('user');
		$this->db = Db::getInstance();
	}

	public function index(Request $request, Response $response): Response
	{
		$maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;
		$queryParams = $request->getQueryParams();
		$start = isset($queryParams['start']) ? (int)$queryParams['start'] : 0;
		$short = isset($queryParams['short']) && $queryParams['short'] == '1';
		$perPage = isset($queryParams['results']) ? (int)$queryParams['results'] : $maxMatches;
		$sort = $queryParams['sort'] ?? 'id';
		$dir = $queryParams['dir'] ?? 'asc';

		// Validate and sanitize the sort field to prevent SQL injection
		$allowedSortFields = ['id', 'name', 'activity_id', 'sort'];
		$sort = in_array($sort, $allowedSortFields) ? $sort : 'id';
		$dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

		$sql = "SELECT r.*, br.building_id
                FROM bb_resource r
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                WHERE r.active = 1 AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)
                ORDER BY r.$sort $dir";

		if ($perPage > 0)
		{
			$sql .= " LIMIT :limit OFFSET :start";
		}

		try
		{
			$stmt = $this->db->prepare($sql);
			if ($perPage > 0)
			{
				$stmt->bindParam(':limit', $perPage, \PDO::PARAM_INT);
				$stmt->bindParam(':start', $start, \PDO::PARAM_INT);
			}
			$stmt->execute();
			$results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			$resources = array_map(function ($data) use ($short)
			{
				$resource = new Resource($data);
				return $resource->serialize([], $short);
			}, $results);

			$totalCount = $this->getTotalCount();

			$responseData = [
				'total_records' => $totalCount,
				'start' => $start,
				'sort' => $sort,
				'dir' => $dir,
				'results' => $resources
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$error = "Error fetching resources: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	private function getTotalCount(): int
	{
		$sql = "SELECT COUNT(DISTINCT r.id)
                FROM bb_resource r
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                WHERE r.active = 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		return $stmt->fetchColumn();
	}

	/**
	 * @OA\Get(
	 *     path="/booking/resources/{id}/schedule",
	 *     summary="Get schedule for a specific resource within a date range",
	 *     description="Retrieves the schedule for a specific resource. Requires a start_date parameter. If end_date is not provided, it defaults to one month from the start_date.",
	 *     tags={"Resources"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="ID of the resource",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\Parameter(
	 *         name="start_date",
	 *         in="query",
	 *         description="Start date for the schedule (YYYY-MM-DD format)",
	 *         required=true,
	 *         @OA\Schema(type="string", format="date", example="2025-06-19")
	 *     ),
	 *     @OA\Parameter(
	 *         name="end_date",
	 *         in="query",
	 *         description="End date for the schedule (YYYY-MM-DD format). If not provided, defaults to one month from start_date.",
	 *         required=false,
	 *         @OA\Schema(type="string", format="date", example="2025-07-19")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Resource schedule retrieved successfully",
	 *         @OA\JsonContent(type="object", ref="#/components/schemas/Schedule")
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - Invalid parameters",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="error", type="string", example="Both start_date and end_date parameters are required")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="error", type="string", example="Error message")
	 *         )
	 *     )
	 * )
	 */
	public function getResourceSchedule(Request $request, Response $response, array $args): Response
	{
		try
		{
			$resourceId = (int)$args['id'];
			if ($resourceId <= 0)
			{
				throw new Exception('Invalid resource ID provided.');
			}

			// Verify resource exists
			if (!$this->resourceExists($resourceId))
			{
				$response->getBody()->write(json_encode(['error' => 'Resource not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}


			$queryParams = $request->getQueryParams();


			if (!isset($queryParams['start_date']))
			{
				$response->getBody()->write(json_encode(['error' => 'Both start_date and end_date parameters are required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}
			// Convert dates to DateTime objects
			try
			{
				$startDate = new \DateTime($queryParams['start_date']);
				$startDate->setTime(0, 0, 0);

				if (!isset($queryParams['end_date']))
				{
					// If end_date is not provided, set it to the same day as start_date
					$endDate = clone $startDate;
					$endDate->modify('+1 month');
					$endDate->setTime(23, 59, 59);
				}
				else
				{
					// If end_date is provided, parse it
					$endDate = new \DateTime($queryParams['end_date']);
					$endDate->setTime(23, 59, 59);
				}
			}
			catch (\Exception $e)
			{
				$response->getBody()->write(json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD format.']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$schedule = $this->scheduleEntityService->getResourceSchedule($resourceId, $startDate, $endDate);

			$response->getBody()->write(json_encode($schedule));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/booking/resources/{id}/events",
	 *     summary="Create a new event for a specific resource (Outlook integration)",
	 *     description="Creates a new event from external calendar integration (e.g., Outlook). This endpoint is designed for calendar bridge services.",
	 *     tags={"Resources"},
	 *     @OA\Parameter(
	 *         name="id",
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
	 *             @OA\Property(property="message", type="string", example="Event created successfully"),
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
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Failed to create event")
	 *         )
	 *     )
	 * )
	 */
	public function createEvent(Request $request, Response $response, array $args): Response
	{
		try {
			$resourceId = (int)$args['id'];
			
			// Validate resource ID
			if ($resourceId <= 0) {
				$response->getBody()->write(json_encode(['error' => 'Invalid resource ID']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Verify resource exists
			if (!$this->resourceExists($resourceId)) {
				$response->getBody()->write(json_encode(['error' => 'Resource not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			// Parse request body
			$body = $request->getBody()->getContents();
			$eventData = json_decode($body, true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				$response->getBody()->write(json_encode(['error' => 'Invalid JSON format']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Validate required fields
			$requiredFields = ['title', 'from_', 'to_', 'source'];
			foreach ($requiredFields as $field) {
				if (!isset($eventData[$field]) || empty($eventData[$field])) {
					$response->getBody()->write(json_encode(['error' => "Missing required field: $field"]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			}

			// Validate and parse dates
			try {
				$fromDate = new \DateTime($eventData['from_']);
				$toDate = new \DateTime($eventData['to_']);
				
				if ($fromDate >= $toDate) {
					$response->getBody()->write(json_encode(['error' => 'End time must be after start time']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
				}
			} catch (\Exception $e) {
				$response->getBody()->write(json_encode(['error' => 'Invalid date format. Use ISO 8601 format (YYYY-MM-DDTHH:MM:SS±HH:MM)']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Create event in database
			$eventId = $this->createEventInDatabase($resourceId, $eventData, $fromDate, $toDate);

			if (!$eventId) {
				$response->getBody()->write(json_encode(['error' => 'Failed to create event']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			// Return success response
			$responseData = [
				'id' => $eventId,
				'event_id' => $eventId,
				'success' => true,
				'event' => [
					'id' => $eventId,
					'resource_id' => $resourceId,
					'name' => $eventData['title'],
					'from_' => $eventData['from_'],
					'to_' => $eventData['to_'],
					'source' => $eventData['source']
				]
			];

			$response->getBody()->write(json_encode($responseData));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Check if a resource exists in the database
	 */
	private function resourceExists(int $resourceId): bool
	{
		$sql = "SELECT id FROM bb_resource WHERE id = :id AND active = 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $resourceId]);
		return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
	}

	/**
	 * Create event record in the database
	 */
	private function createEventInDatabase(int $resourceId, array $eventData, \DateTime $fromDate, \DateTime $toDate): ?int
	{
		try {
			$this->db->beginTransaction();

			// Get building information for the resource
			$buildingInfo = $this->getBuildingInfoForResource($resourceId);
			if (!$buildingInfo) {
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
				'id_string' => null, // Will be set after insert
			];

			// Insert event
			$eventColumns = array_keys($dbEventData);
			$eventPlaceholders = ':' . implode(', :', $eventColumns);
			
			$eventSql = "INSERT INTO bb_event (" . implode(', ', $eventColumns) . ") VALUES (" . $eventPlaceholders . ") RETURNING id";
			$eventStmt = $this->db->prepare($eventSql);

			// Bind parameters
			foreach ($dbEventData as $key => $value) {
				$eventStmt->bindValue(":$key", $value);
			}

			$eventStmt->execute();
			$eventId = $eventStmt->fetchColumn();

			if (!$eventId) {
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

			$this->db->commit();
			return (int)$eventId;

		} catch (Exception $e) {
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
}
