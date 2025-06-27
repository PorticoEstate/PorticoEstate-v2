<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\booking\models\Event;
use App\modules\phpgwapi\security\Acl;
use App\Database\Db;
use Sanitizer;

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

			// Sanitize incoming data
			$eventData = $this->sanitizeEventData($eventData);

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
	 * Sanitize incoming event data for security and consistency
	 */
	private function sanitizeEventData(array $eventData): array
	{
		$sanitizedData = [];

		// Get sanitization rules from the Event model
		$sanitizationRules = Event::getSanitizationRules();
		$arrayElementTypes = Event::getArrayElementTypes();

		foreach ($eventData as $key => $value)
		{
			// Skip null/empty values unless it's an explicit false/0
			if ($value === null || ($value === '' && $value !== '0' && $value !== 0 && $value !== false))
			{
				continue;
			}

			$sanitizationType = $sanitizationRules[$key] ?? 'string';

			try
			{
				switch ($sanitizationType)
				{
					case 'html':
						// Allow some HTML but sanitize it thoroughly
						$sanitizedData[$key] = Sanitizer::clean_html($value);
						break;

					case 'array_int':
						// Handle arrays of integers (like resource_ids)
						$sanitizedData[$key] = $this->sanitizeIntegerArray($value);
						break;

					case 'array_string':
						// Handle arrays of strings
						$sanitizedData[$key] = $this->sanitizeStringArray($value);
						break;

					case 'array':
						// Generic array handling (fallback)
						if (is_array($value))
						{
							$sanitizedData[$key] = array_map(function ($v)
							{
								return Sanitizer::clean_value($v, 'string');
							}, $value);
						}
						break;

					default:
						// Check if it's a typed array
						if (isset($arrayElementTypes[$sanitizationType]))
						{
							$elementType = $arrayElementTypes[$sanitizationType];
							$sanitizedData[$key] = $this->sanitizeTypedArray($value, $elementType);
						}
						else
						{
							// Use the existing Sanitizer clean_value method
							$sanitizedData[$key] = Sanitizer::clean_value($value, $sanitizationType);
						}
						break;
				}
			}
			catch (Exception $e)
			{
				// Log sanitization errors but don't fail the request
				error_log("Sanitization error for field '$key': " . $e->getMessage());

				// Apply basic string sanitization as fallback
				$sanitizedData[$key] = Sanitizer::clean_value($value, 'string');
			}
		}

		return $sanitizedData;
	}

	/**
	 * Sanitize array of integers
	 */
	private function sanitizeIntegerArray($value): array
	{
		if (is_array($value))
		{
			return array_map('intval', array_filter($value, function ($v)
			{
				return is_numeric($v) && $v > 0;
			}));
		}
		elseif (is_string($value))
		{
			// Handle comma-separated strings
			return array_map('intval', array_filter(
				explode(',', $value),
				function ($v)
				{
					return is_numeric(trim($v)) && intval(trim($v)) > 0;
				}
			));
		}
		return [];
	}

	/**
	 * Sanitize array of strings
	 */
	private function sanitizeStringArray($value): array
	{
		if (is_array($value))
		{
			return array_map(function ($v)
			{
				return Sanitizer::clean_value($v, 'string');
			}, array_filter($value, function ($v)
			{
				return !empty(trim($v ?? ''));
			}));
		}
		elseif (is_string($value))
		{
			// Handle comma-separated strings
			return array_map(function ($v)
			{
				return Sanitizer::clean_value(trim($v), 'string');
			}, array_filter(explode(',', $value), function ($v)
			{
				return !empty(trim($v));
			}));
		}
		return [];
	}

	/**
	 * Sanitize array with specific element type
	 */
	private function sanitizeTypedArray($value, string $elementType): array
	{
		if (is_array($value))
		{
			return array_map(function ($v) use ($elementType)
			{
				return Sanitizer::clean_value($v, $elementType);
			}, array_filter($value, function ($v)
			{
				return $v !== null && $v !== '';
			}));
		}
		elseif (is_string($value))
		{
			// Handle comma-separated strings
			return array_map(function ($v) use ($elementType)
			{
				return Sanitizer::clean_value(trim($v), $elementType);
			}, array_filter(explode(',', $value), function ($v)
			{
				return !empty(trim($v));
			}));
		}
		return [];
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

			// Sanitize update data
			$updateData = $this->sanitizeEventData($updateData);

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

			// Sanitize request data if any
			if (!empty($requestData))
			{
				$requestData = $this->sanitizeEventData($requestData);
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

			// Sanitize input data
			$eventData = $this->sanitizeEventData($eventData);

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
	 * @OA\Get(
	 *     path="/booking/events/{id}/audience",
	 *     summary="Get audience for an event",
	 *     description="Retrieves the target audience associated with a specific event",
	 *     tags={"Events", "Relationships"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="Event ID",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Event audience retrieved successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="event_id", type="integer", example=123),
	 *             @OA\Property(property="audience", type="array", @OA\Items(
	 *                 @OA\Property(property="id", type="integer", example=1),
	 *                 @OA\Property(property="name", type="string", example="Adults"),
	 *                 @OA\Property(property="description", type="string", example="Adult participants")
	 *             ))
	 *         )
	 *     ),
	 *     @OA\Response(response=404, description="Event not found"),
	 *     @OA\Response(response=500, description="Internal server error")
	 * )
	 */
	public function getEventAudience(Request $request, Response $response, array $args): Response
	{
		try
		{
			$eventId = (int) $args['id'];
			$event = Event::find($eventId);

			if (!$event)
			{
				$response->getBody()->write(json_encode(['error' => 'Event not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$audience = $event->getAudience();
			$responseData = [
				'event_id' => $eventId,
				'audience' => $audience
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
	 * @OA\Get(
	 *     path="/booking/events/{id}/comments",
	 *     summary="Get comments for an event",
	 *     description="Retrieves all comments associated with a specific event",
	 *     tags={"Events", "Relationships"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="Event ID",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Event comments retrieved successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="event_id", type="integer", example=123),
	 *             @OA\Property(property="comments", type="array", @OA\Items(
	 *                 @OA\Property(property="id", type="integer", example=1),
	 *                 @OA\Property(property="comment", type="string", example="Great event!"),
	 *                 @OA\Property(property="type", type="string", example="user"),
	 *                 @OA\Property(property="time", type="integer", example=1656846631),
	 *                 @OA\Property(property="author", type="string", example="johndoe"),
	 *                 @OA\Property(property="author_name", type="string", example="John Doe")
	 *             ))
	 *         )
	 *     ),
	 *     @OA\Response(response=404, description="Event not found"),
	 *     @OA\Response(response=500, description="Internal server error")
	 * )
	 */
	public function getEventComments(Request $request, Response $response, array $args): Response
	{
		try
		{
			$eventId = (int) $args['id'];
			$event = Event::find($eventId);

			if (!$event)
			{
				$response->getBody()->write(json_encode(['error' => 'Event not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$comments = $event->getComments();
			$responseData = [
				'event_id' => $eventId,
				'comments' => $comments
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
	 * @OA\Post(
	 *     path="/booking/events/{id}/comments",
	 *     summary="Add a comment to an event",
	 *     description="Adds a new comment to a specific event",
	 *     tags={"Events", "Relationships"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="Event ID",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(
	 *             required={"comment"},
	 *             @OA\Property(property="comment", type="string", description="Comment text", example="This event was excellent!"),
	 *             @OA\Property(property="type", type="string", description="Comment type", example="user", default="user"),
	 *             @OA\Property(property="author", type="string", description="Author username", example="johndoe"),
	 *             @OA\Property(property="author_name", type="string", description="Author display name", example="John Doe")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Comment added successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="message", type="string", example="Comment added successfully")
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Invalid input"),
	 *     @OA\Response(response=404, description="Event not found"),
	 *     @OA\Response(response=500, description="Internal server error")
	 * )
	 */
	public function addEventComment(Request $request, Response $response, array $args): Response
	{
		try
		{
			$eventId = (int) $args['id'];
			$event = Event::find($eventId);

			if (!$event)
			{
				$response->getBody()->write(json_encode(['error' => 'Event not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$data = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (empty($data['comment']))
			{
				$response->getBody()->write(json_encode(['error' => 'Comment text is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$comment = Sanitizer::clean_value($data['comment'], 'string');
			$type = Sanitizer::clean_value($data['type'] ?? 'user', 'string');
			$author = Sanitizer::clean_value($data['author'] ?? null, 'string');
			$authorName = Sanitizer::clean_value($data['author_name'] ?? null, 'string');

			$success = $event->addComment($comment, $type, $author, $authorName);

			if (!$success)
			{
				$response->getBody()->write(json_encode(['error' => 'Failed to add comment']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			$responseData = [
				'success' => true,
				'message' => 'Comment added successfully'
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
	 * @OA\Get(
	 *     path="/booking/events/{id}/dates",
	 *     summary="Get dates for an event",
	 *     description="Retrieves all dates associated with a specific event",
	 *     tags={"Events", "Relationships"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="Event ID",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Event dates retrieved successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="event_id", type="integer", example=123),
	 *             @OA\Property(property="dates", type="array", @OA\Items(
	 *                 @OA\Property(property="id", type="integer", example=1),
	 *                 @OA\Property(property="from_", type="string", format="date-time", example="2025-06-25T15:30:00+02:00"),
	 *                 @OA\Property(property="to_", type="string", format="date-time", example="2025-06-25T17:00:00+02:00")
	 *             ))
	 *         )
	 *     ),
	 *     @OA\Response(response=404, description="Event not found"),
	 *     @OA\Response(response=500, description="Internal server error")
	 * )
	 */
	public function getEventDates(Request $request, Response $response, array $args): Response
	{
		try
		{
			$eventId = (int) $args['id'];
			$event = Event::find($eventId);

			if (!$event)
			{
				$response->getBody()->write(json_encode(['error' => 'Event not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$dates = $event->getDates();
			$responseData = [
				'event_id' => $eventId,
				'dates' => $dates
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
	 * @OA\Get(
	 *     path="/booking/events/{id}/building-info",
	 *     summary="Get building information for an event",
	 *     description="Retrieves building information associated with the event's resources",
	 *     tags={"Events", "Relationships"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="Event ID",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Building information retrieved successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="event_id", type="integer", example=123),
	 *             @OA\Property(property="building_info", type="object",
	 *                 @OA\Property(property="building_id", type="integer", example=5),
	 *                 @OA\Property(property="building_name", type="string", example="Main Building")
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(response=404, description="Event not found"),
	 *     @OA\Response(response=500, description="Internal server error")
	 * )
	 */
	public function getEventBuildingInfo(Request $request, Response $response, array $args): Response
	{
		try
		{
			$eventId = (int) $args['id'];
			$event = Event::find($eventId);

			if (!$event)
			{
				$response->getBody()->write(json_encode(['error' => 'Event not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$buildingInfo = $event->getBuildingInfo();
			$responseData = [
				'event_id' => $eventId,
				'building_info' => $buildingInfo
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
	 * @OA\Put(
	 *     path="/booking/events/{id}/audience",
	 *     summary="Update event audience relationships",
	 *     description="Updates the many-to-many relationship between an event and audience groups",
	 *     tags={"Events", "Relationships"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="Event ID",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(
	 *             required={"audience_ids"},
	 *             @OA\Property(property="audience_ids", type="array", @OA\Items(type="integer"), description="Array of audience IDs", example=[1, 2, 3])
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Audience relationships updated successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="success", type="boolean", example=true),
	 *             @OA\Property(property="message", type="string", example="Audience relationships updated successfully")
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Invalid input"),
	 *     @OA\Response(response=404, description="Event not found"),
	 *     @OA\Response(response=500, description="Internal server error")
	 * )
	 */
	public function updateEventAudience(Request $request, Response $response, array $args): Response
	{
		try
		{
			$eventId = (int) $args['id'];
			$event = Event::find($eventId);

			if (!$event)
			{
				$response->getBody()->write(json_encode(['error' => 'Event not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$data = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (!isset($data['audience_ids']) || !is_array($data['audience_ids']))
			{
				$response->getBody()->write(json_encode(['error' => 'audience_ids must be an array']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Sanitize audience IDs
			$audienceIds = array_map(function ($id)
			{
				return Sanitizer::clean_value($id, 'int');
			}, $data['audience_ids']);

			$success = $event->saveRelationship('audience', $audienceIds);

			if (!$success)
			{
				$response->getBody()->write(json_encode(['error' => 'Failed to update audience relationships']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
			}

			$responseData = [
				'success' => true,
				'message' => 'Audience relationships updated successfully'
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
}
