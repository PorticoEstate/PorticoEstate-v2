<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\Event;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\repositories\EventRepository;
use DateTime;
use Exception;
use PDO;

class EventService
{
    private $db;
    private $bouser;
    public $repository;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->bouser = new UserHelper();
        $this->repository = new EventRepository();
    }

    private function patchEventMainData(array $data, array $existingEvent)
    {
        $allowedFields = [
            'name',
            'organizer',
            'from_',
            'to_',
            'participant_limit'
        ];

        //Check if this a diff between existing record and new data
        $shouldUpdate = false;
        foreach ($data as $field => $value) {
            $existingField = $existingEvent[$field];
            if ($existingField != $value && in_array($field, $allowedFields)) {
                $shouldUpdate = true;
            }
        }
        if (!$shouldUpdate) {
            return null;
        }

        $this->repository->patchMainData($existingEvent['id'], $data, $allowedFields);
    }

    private function saveNewResourcesList(array $data, array $existingEvent)
    {
        if (!isset($data['resource_ids'])) return null;
        $resourceIds = $this->repository->resourceIds($existingEvent['id']);
        $resourceIds = $resourceIds ? $resourceIds : [];

        //Delete removed resources
        $toDelete = [];
        foreach ($resourceIds as $resourceId) {
            if (!in_array($resourceId, $data['resource_ids'])) {
                array_push($toDelete, $resourceId);
            }
        }
        if (count($toDelete) > 0) {
            $this->repository->deleteResources($toDelete);
        }

        //Set new resources
        $shouldInsert = false;
        $toInsert = [];
        foreach ($data['resource_ids'] as $newResource) {
            if (!in_array($newResource, $resourceIds)) {
                $shouldInsert = true;
                array_push($toInsert,
                    [
                        'id' => $existingEvent['id'],
                        'resourceId' => $newResource
                    ]
                );
            }
        }
        if ($shouldInsert) {
            $this->repository->insertResources($existingEvent['id'], $toInsert);
        }
    }

    private function saveNewDates(int $id, array $data)
    {
        if (!$data['from_'] && !$data['to_']) return null;

        $this->repository->updateDates($id, $data);
    }

    public function getPartialEventObjectById(int $id)
    {
        $fields = ['id', 'customer_ssn', 'customer_organization_number', 'from_', 'to_', 'participant_limit'];
        $sql = "SELECT " . implode(', ', $fields) . " FROM bb_event WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function checkEventOwnerShip(array $existingEvent)
    {
        $ownerSsn = $existingEvent['customer_ssn'];
        $ownerOrgNum = $existingEvent['customer_organization_number'];
        $ssn = $this->bouser->ssn;
        $userOrgs = $this->bouser->organizations
            ? array_column($this->bouser->organizations, 'orgnr')
            : [];
        return
            $ssn === $ownerSsn ||
            in_array($ownerOrgNum, $userOrgs);

    }
    public function updateEvent(array $data, array $existingEvent)
    {
        try {
            $this->db->beginTransaction();

            $this->patchEventMainData($data, $existingEvent);
            $this->saveNewResourcesList($data, $existingEvent);
            $this->saveNewDates($existingEvent['id'], $data);

            $this->db->commit();
            return $existingEvent['id'];
        } catch (Exception $e) {
            $this->db->rollBack();
            var_dump($e);
            throw $e;
        }
    }

    public function getEventById(int $id)
    {
        $entity = $this->repository->getEventById($id);

        $userOrgs = $this->bouser->organizations
            ? array_column($this->bouser->organizations, 'orgnr')
            : null;
        $participants = $this->repository->currentParticipants($id);
        return [
            'event' => $entity->serialize(
                ['user_ssn' => $this->bouser->ssn, "organization_number" => $userOrgs]
            ),
            'numberOfParticipants' => $participants
        ];
    }

    public function preRegister(array $data, array $event)
    {
        $now = new DateTime();
        $from = new DateTime($event['from_']);
        if ($from < $now) {
            return false;
        }
        $preRegistration = $this->repository->findRegistration($event['id'], $data['phone']);
        //If user already pre-registered
        if ($preRegistration['id']) {
            return null;
        }

        $numberOfParticipants = $this->repository->currentParticipants($event['id']);
        $newAllPeoplesQuantity = $numberOfParticipants + $data['quantity'];
        if ($newAllPeoplesQuantity > (int) $event['participant_limit']) {
            return null;
        }
        $this->repository->addPreregistration($event['id'], $data);
        return $event['id'];
    }

    public function inRegister(array $data, array $event)
    {
        $now = new DateTime();
        $from = new DateTime($event['from_']);
        $to = new DateTime($event['to_']);
        if (!($from < $now && $to > $now)) {
            return null;
        }

        $registration = $this->repository->findRegistration($event['id'], $data['phone']);
        // If user already registered in
        if ($registration['from_']) {
            return null;
        }
        // If user have pre-registration
        if ($registration) {
            $acceptedQuantity = $registration['quantity'];
            $data['quantity'] = $acceptedQuantity > $data['quantity'] ? $data['quantity'] : $acceptedQuantity;
        }

        $numberOfParticipants = $this->repository->currentParticipants($event['id'], true);
        $newAllPeoplesQuantity = $numberOfParticipants + $data['quantity'];
        if ($newAllPeoplesQuantity > (int) $event['participant_limit']) {
            return null;
        }

        $data['from_'] = date('Y-m-d H:i:s');
        $registration
            ? $this->repository->inRegistration($event['id'], $data)
            : $this->repository->insertInRegistration($event['id'], $data);
        return $event['id'];
    }

    public function outRegistration(string $phone, array $event)
    {
        $now = new DateTime();
        $from = new DateTime($event['from_']);
        $to = new DateTime($event['to_']);

        $pre = $from < $now;
        $in = $from < $now && $to > $now;
        if (!($pre || $in)) return null;

        $inRegistration = $this->repository->findRegistration($event['id'], $phone);

        // If user didnt register in
        if (!$inRegistration['from_']) {
            return null;
        }
        //If user already registered out
        if ($inRegistration['to_']) {
            return null;
        }

        $data = ['phone' => $phone, 'to_' => date('Y-m-d H:i:s')];
        $this->repository->outRegistration($event['id'], $data);
        return $event['id'];
    }


	/**
	 * Create an event from an application
	 */
	public function createFromApplication(array $application, array $date): int
	{
		try {
			// Create the base event data
			$eventData = [
				'active' => 1,
				'application_id' => $application['id'],
				'completed' => 0,
				'is_public' => 0,
				'include_in_list' => 0,
				'reminder' => 0,
				'customer_internal' => 0,
				'from_' => $this->formatDateForDatabase($date['from_']),
				'to_' => $this->formatDateForDatabase($date['to_']),
				'name' => $application['name'],
				'activity_id' => $application['activity_id'],
				'building_id' => $application['building_id'],
				'building_name' => $application['building_name'],
				'contact_name' => $application['contact_name'],
				'contact_email' => $application['contact_email'],
				'contact_phone' => $application['contact_phone'],
				'customer_organization_name' => $application['customer_organization_name'] ?? null,
				'customer_organization_id' => $application['customer_organization_id'] ?? null,
				'customer_identifier_type' => $application['customer_identifier_type'],
				'customer_ssn' => $application['customer_ssn'] ?? null,
				'customer_organization_number' => $application['customer_organization_number'] ?? null,
				'organizer' => $application['organizer'],
				'description' => $application['description'] ?? null,
				'equipment' => $application['equipment'] ?? null,
				'cost' => 0,
				'secret' => $this->generateSecret()
			];

			// Create the event
			$eventId = $this->repository->createEvent($eventData);

			// Associate resources, audience, and age groups
			if (!empty($application['resources'])) {
				$this->repository->saveEventResources($eventId, $application['resources']);
			}

			if (!empty($application['audience'])) {
				$this->repository->saveEventAudience($eventId, $application['audience']);
			}

			// Only associate age groups if they exist and are valid
			if (!empty($application['agegroups']) && is_array($application['agegroups'])) {
				$validAgegroups = array_filter($application['agegroups'], function($ag) {
					return !empty($ag['agegroup_id']);
				});

				if (!empty($validAgegroups)) {
					$this->repository->saveEventAgeGroups($eventId, $validAgegroups);
				}
			}

			return $eventId;
		} catch (Exception $e) {
			throw new Exception("Failed to create event: " . $e->getMessage());
		}
	}

	/**
	 * Generate a secret for the event
	 */
	private function generateSecret(int $length = 16): string
	{
		return bin2hex(random_bytes($length));
	}


	/**
	 * Normalize date format for database storage
	 */
	private function formatDateForDatabase($dateString): string
	{
		// Handle ISO format with timezone (e.g. "2004-09-21T08:00:00+02:00")
		if (strpos($dateString, 'T') !== false) {
			$dateTime = new DateTime($dateString);
			// Convert to UTC if needed
			return $dateTime->format('Y-m-d H:i:s');
		}

		// Handle timestamp in milliseconds (e.g. 1741777200000)
		if (is_numeric($dateString) && strlen($dateString) > 10) {
			return date('Y-m-d H:i:s', (int)$dateString / 1000);
		}

		// If already in SQL format (e.g. "2000-03-14 08:00:00")
		return $dateString;
	}

	/**
	 * Get upcoming events with organization details
	 *
	 * @param string|null $fromDate Start date filter
	 * @param string|null $toDate End date filter
	 * @param int|null $orgId Optional organization ID to filter by
	 * @param int|null $buildingId Optional building ID to filter by
	 * @param int|null $facilityTypeId Optional facility type ID to filter by
	 * @param bool $loggedInOnly When true, shows only events for logged-in organization.
	 *                           When false, shows both public events and logged-in organization's events.
	 * @param int $start Pagination start
	 * @param int|null $limit Pagination limit (null means no limit)
	 * @return array Array of events with organization details
	 */
	public function getUpcomingEvents(
		?string $fromDate = null,
		?string $toDate = null,
		?int $orgId = null,
		?int $buildingId = null,
		?int $facilityTypeId = null,
		bool $loggedInOnly = false,
		int $start = 0,
		?int $limit = null
	): array {
		$orgInfo = null;
		if ($orgId) {
			// Get organization repository instance
			$organizationRepository = new \App\modules\bookingfrontend\repositories\OrganizationRepository();
			$orgInfo = $organizationRepository->getOrganizationById($orgId);
		}

		$loggedInAs = null;
		$filterByOrganization = false;

		// If user is logged in, get their organization number
		if ($this->bouser->is_logged_in()) {
			$loggedInAs = $this->bouser->orgnr;

			// If loggedInOnly is true, only show events for logged in organization
			if ($loggedInOnly) {
				$filterByOrganization = true;
			}
		}

		// Get events from repository
		$eventsData = $this->repository->getUpcomingEvents(
			$fromDate,
			$toDate,
			$orgInfo,
			$buildingId,
			$facilityTypeId,
			$filterByOrganization,
			$loggedInAs,
			$start,
			$limit
		);

		// Get organization repository to fetch organization details
		$orgRepository = new \App\modules\bookingfrontend\repositories\OrganizationRepository();

		// Cache for organization info to avoid repeated lookups
		$organizations = [];

		// Convert raw database records to Event model instances
		$formattedEvents = [];

		// Get user context for serialization - using same format as BuildingScheduleService
		$userOrgs = $this->bouser->organizations
			? array_column($this->bouser->organizations, 'orgnr')
			: null;

		// Process each event
		foreach ($eventsData as $eventData) {
			// Normalize keys if needed (from the SQL query)
			if (isset($eventData['event_id'])) {
				$eventData['id'] = $eventData['event_id'];
				unset($eventData['event_id']);
			}

			// Map location_name to building_name if needed
			if (isset($eventData['location_name']) && !isset($eventData['building_name'])) {
				$eventData['building_name'] = $eventData['location_name'];
				unset($eventData['location_name']);
			}

			// Map event_name to name if needed
			if (isset($eventData['event_name']) && !isset($eventData['name'])) {
				$eventData['name'] = $eventData['event_name'];
				unset($eventData['event_name']);
			}

			// Get organization info using org_num (customer_organization_number)
			$orgNum = $eventData['customer_organization_number'];
			if (isset($organizations[$orgNum])) {
				$organizationInfo = $organizations[$orgNum];
			} else {
				$organizationInfo = $orgRepository->getOrganizationByNumber($orgNum);
				$organizations[$orgNum] = $organizationInfo;
			}

			// Add organization name to event data
			if (empty($organizationInfo) || empty($organizationInfo['name'])) {
				$eventData['customer_organization_name'] = ($eventData['organizer'] === '' ? 'Ingen' : $eventData['organizer']);
			} else {
				$eventData['customer_organization_name'] = $organizationInfo['name'];
			}

			// Process resources if available with complete data for proper serialization
			$resources = [];
			if (isset($eventData['resources']) && $eventData['resources']) {
				$resourceData = json_decode($eventData['resources'], true);
				if ($resourceData) {
					foreach ($resourceData as $id => $name) {
						$resourceEntity = new Resource([
							'id' => $id,
							'name' => $name,
							'activity_id' => $eventData['activity_id'] ?? null,
							'activity_name' => $eventData['activity_name'] ?? null,
							'building_id' => $eventData['building_id'] ?? null
						]);
						$resources[] = $resourceEntity;
					}
				}
			}

			// Create Event model instance with data
			$event = new Event($eventData);
			$event->resources = $resources;

			// Add to results using serialize method with consistent parameters matching BuildingScheduleService
			$formattedEvents[] = $event->serialize(['user_ssn' => $this->bouser->ssn, "organization_number" => $userOrgs], true);
		}

		return $formattedEvents;
	}
}