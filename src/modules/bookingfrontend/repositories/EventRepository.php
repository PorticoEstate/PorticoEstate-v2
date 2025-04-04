<?php
namespace App\modules\bookingfrontend\repositories;

use PDO;
use App\Database\Db;
use App\modules\bookingfrontend\models\Event;
use App\modules\bookingfrontend\models\Resource;

class EventRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function getEventById(int $id): Event
    {
        $sql = "SELECT ev.*, act.name as activity_name,
        (
            SELECT jsonb_object_agg(res.id, res.name) from bb_event_resource as evres
            JOIN bb_resource as res
            ON res.id = evres.resource_id
            WHERE evres.event_id = ev.id
        ) as resources
        FROM public.bb_event ev
        JOIN bb_activity act
        ON ev.activity_id = act.id
        WHERE ev.id = :id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) return null;

        $entity = new Event($data);
        $resources = [];
        foreach (json_decode($data['resources'], true) as $id => $name) {
            $resourceEntity = new Resource(array('id' => $id, 'name' => $name));
            array_push($resources, $resourceEntity);
        }
        $entity->resources = $resources;
        return $entity;
    }

    public function patchMainData(int $id, array $data, array $lookupFields)
    {
        $params = [':id' => $id];
        $updateFields = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $lookupFields)) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        $sql = "UPDATE bb_event SET " . implode(', ', $updateFields) .
            " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function resourceIds(int $id)
    {
        $sql =
            "SELECT array_to_json(ARRAY_AGG(resource_id)) as event_resources from bb_event_resource
            WHERE event_id = :event_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':event_id' => $id]);
        return json_decode($stmt->fetch()['event_resources']);
    }

    public function deleteResources(array $resourceIds)
    {
        $deleteSql =
        "DELETE FROM bb_event_resource
        WHERE resource_id IN (" . implode(', ', $resourceIds) . ")";
        $insertStmt = $this->db->prepare($deleteSql);
        $insertStmt->execute();
    }

    public function insertResources(int $id, array $resourceIds)
    {
        $insertSql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES ";
        $insertSql .= implode(', ', array_map(function($value) {
            return "($value[id], $value[resourceId])";
        }, $resourceIds));

        $insertStmt = $this->db->prepare($insertSql);
        $insertStmt->execute();
    }

    public function currentParticipants(int $id, $registeredIn = false): int
    {
        $filtermethod = '';
        if ($registeredIn) {
            $filtermethod .= 'AND from_ IS NOT NULL AND to_ IS NULL';
        } else {
            $filtermethod .= 'AND to_ IS NULL';
        }

        $sql = "SELECT sum(quantity) as cnt"
        . " FROM bb_participant"
        . " WHERE reservation_type='event'"
        . " AND reservation_id=:eventId"
        . " {$filtermethod}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['eventId' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data['cnt'] ? $data['cnt'] : 0;
    }

    public function findRegistration(int $id, string $phone)
    {
        $sql = "SELECT id, email, from_, to_, quantity"
        . " FROM bb_participant"
        . " WHERE reservation_type='event'"
        . " AND reservation_id=:id"
        . " AND phone LIKE '%{$phone}'"
        . " ORDER BY id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateDates($id, $data)
    {
        $sql = "UPDATE bb_event_date SET " ;
        ['from_' => $from, 'to_' => $to] = $data;
        $params = ['eventId' => $id];
        if ($from) {
            $sql .= "from_ = :from, ";
            $params['from'] = $from;
        }
        if ($to) {
            $sql .= "to_ = :to ";
            $params['to'] = $to;
        }
        $sql .= "WHERE event_id = :eventId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function addPreregistration($id, $data)
    {
        $fields = [
            'reservation_type',
            'reservation_id',
            'phone',
            'quantity'
        ];
        $sql =
        "INSERT INTO bb_participant(" . implode(', ', $fields) .
        ") VALUES('event', :eventId, :phone, :quantity)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'eventId' => $id,
            'phone' => $data['phone'],
            'quantity' => $data['quantity']
        ]);
    }

    public function insertInRegistration($id, $data)
    {
        $insertFields = [
            'reservation_type',
            'reservation_id',
            'from_',
            'phone',
            'quantity'
        ];
        $sql = "INSERT INTO bb_participant(" . implode(', ', $insertFields)
        . ") VALUES('event', :eventId, :from, :phone, :quantity)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'eventId' => $id,
            'phone' => $data['phone'],
            'from' => $data['from_'],
            'quantity' => $data['quantity']
        ]);
    }

    private function updateRegistration(int $id, string $phone, string $fieldName, $value)
    {
        $sql = "UPDATE bb_participant SET $fieldName=:$fieldName"
        . " WHERE reservation_type='event'"
        . " AND reservation_id=:eventId"
        . " AND phone LIKE :phone";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'eventId' => $id,
            'phone' => $phone,
            $fieldName =>  $value
        ]);
    }
    public function outRegistration($id, $data)
    {
       return $this->updateRegistration($id, $data['phone'], 'to_', $data['to_']);
    }
    public function inRegistration($id, $data)
    {
        return $this->updateRegistration($id, $data['phone'], 'from_', $data['from_']);
    }


	/**
	 * Create an event from application data
	 */
	public function createEvent(array $eventData): int
	{
		$columns = array_keys($eventData);
		$placeholders = array_fill(0, count($columns), '?');

		$sql = "INSERT INTO bb_event (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $placeholders) . ")";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(array_values($eventData));

		return $this->db->lastInsertId();
	}

	/**
	 * Associate resources with an event
	 */
	public function saveEventResources(int $eventId, array $resources): void
	{
		$sql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES (?, ?)";
		$stmt = $this->db->prepare($sql);

		foreach ($resources as $resourceId) {
			if (is_array($resourceId) && isset($resourceId['id'])) {
				$stmt->execute([$eventId, $resourceId['id']]);
			} else {
				$stmt->execute([$eventId, $resourceId]);
			}
		}
	}

	/**
	 * Associate target audience with an event
	 */
	public function saveEventAudience(int $eventId, array $audience): void
	{
		$sql = "INSERT INTO bb_event_targetaudience (event_id, targetaudience_id) VALUES (?, ?)";
		$stmt = $this->db->prepare($sql);

		foreach ($audience as $audienceId) {
			$stmt->execute([$eventId, $audienceId]);
		}
	}

	/**
	 * Associate age groups with an event
	 */
	public function saveEventAgeGroups(int $eventId, array $agegroups): void
	{
		$sql = "INSERT INTO bb_event_agegroup (event_id, agegroup_id, male, female) VALUES (?, ?, ?, ?)";
		$stmt = $this->db->prepare($sql);

		foreach ($agegroups as $agegroup) {
			// Skip if agegroup_id is not set or is null
			if (empty($agegroup['agegroup_id'])) {
				continue;
			}
			
			$stmt->execute([
				$eventId,
				$agegroup['agegroup_id'],
				$agegroup['male'] ?? 0,
				$agegroup['female'] ?? 0
			]);
		}
	}

	/**
	 * Update ID string (legacy format support)
	 */
	public function updateIdString(): void
	{
		$sql = "UPDATE bb_event SET id_string = cast(id AS varchar)";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
	}
	
	/**
	 * Get upcoming events from a given date
	 *
	 * @param string|null $fromDate Start date filter
	 * @param string|null $toDate End date filter
	 * @param array|null $orgInfo Optional organization info to filter by
	 * @param int|null $buildingId Optional building ID to filter by
	 * @param int|null $facilityTypeId Optional facility type ID to filter by
	 * @param bool|null $filterByOrganization Whether to filter by organization
	 * @param string|null $loggedInAs Organization number of logged in user
	 * @param int $start Pagination start
	 * @param int|null $limit Pagination limit (null means no limit)
	 * @return array Array of events matching the criteria
	 */
	public function getUpcomingEvents(
		?string $fromDate = null,
		?string $toDate = null,
		?array $orgInfo = null,
		?int $buildingId = null,
		?int $facilityTypeId = null,
		?bool $filterByOrganization = false,
		?string $loggedInAs = null,
		int $start = 0,
		?int $limit = null
	): array {
		$params = [];
		$now = date('Y-m-d');
		$conditions = ["bb_event.active = 1"];
		
		// If from date is specified, add it to conditions
		if ($fromDate) {
			$conditions[] = "bb_event.from_ >= :from_date";
			$params[':from_date'] = $fromDate;
		} else {
			$conditions[] = "bb_event.from_ >= :now";
			$params[':now'] = $now;
		}
		
		// If to date is specified, add it to conditions
		if ($toDate) {
			$conditions[] = "bb_event.to_ <= :to_date";
			$params[':to_date'] = $toDate;
		}
		
		// Handle organization filtering
		if (!empty($orgInfo)) {
			$orgConditions = [];
			
			// Filter by organization number if provided
			if (!empty($orgInfo['organization_number'])) {
				$orgConditions[] = "bb_event.customer_organization_number = :org_number";
				$params[':org_number'] = $orgInfo['organization_number'];
			}
			
			// Filter by organization name if provided
			if (!empty($orgInfo['name'])) {
				$orgConditions[] = "bb_event.organizer = :org_name";
				$params[':org_name'] = $orgInfo['name'];
			}
			
			if (!empty($orgConditions)) {
				$conditions[] = "(" . implode(" OR ", $orgConditions) . ")";
			}
		}
		
		// If building ID is specified, add it to conditions
		if ($buildingId) {
			$conditions[] = "bb_event.building_id = :building_id";
			$params[':building_id'] = $buildingId;
		}
		
		// If facility type ID is specified, add it to conditions
		if ($facilityTypeId) {
			$conditions[] = "bb_rescategory.id = :facility_type_id";
			$params[':facility_type_id'] = $facilityTypeId;
		}
		
		// Handle visibility and logged-in filtering exactly like the original
		if ($filterByOrganization && $loggedInAs) {
			// Only show user's own events if explicitly filtering by organization only
			$conditions[] = "bb_event.customer_organization_number = :logged_in_org";
			$params[':logged_in_org'] = $loggedInAs;
		} else if ($loggedInAs) {
			// Show public events AND user's own events (most common case)
			$conditions[] = "(bb_event.include_in_list = 1 OR bb_event.customer_organization_number = :logged_in_org)";
			$params[':logged_in_org'] = $loggedInAs;
		} else {
			// Not logged in, only show public events
			$conditions[] = "bb_event.include_in_list = 1";
		}
		
		$conditionsString = implode(' AND ', $conditions);
		
		// Base SQL with full event information to allow proper serialization
		$baseSelectSql = "SELECT DISTINCT ON (bb_event.id, bb_event.from_)
			bb_event.*,
			act.name as activity_name,
			bb_resource.name as resource_name,
			bb_rescategory.name as resource_type,
			(
				SELECT jsonb_object_agg(res.id, res.name) from bb_event_resource as evres
				JOIN bb_resource as res
				ON res.id = evres.resource_id
				WHERE evres.event_id = bb_event.id
			) as resources";
		
		// Query with appropriate joins based on conditions
		// Always join resource tables to match the original implementation
		$sql = "{$baseSelectSql}
			FROM bb_event
			INNER JOIN bb_building ON bb_event.building_id = bb_building.id
			INNER JOIN bb_event_resource ON bb_event.id = bb_event_resource.event_id
			INNER JOIN bb_resource ON bb_event_resource.resource_id = bb_resource.id
			INNER JOIN bb_rescategory ON bb_resource.rescategory_id = bb_rescategory.id
            LEFT JOIN bb_activity act ON bb_event.activity_id = act.id
			WHERE {$conditionsString}
			ORDER BY bb_event.from_ ASC";
		
		
		// Add limit clause only if limit is specified
		if ($limit !== null) {
			$sql .= " LIMIT :limit OFFSET :start";
		} else {
			$sql .= " OFFSET :start";
		}
		
		$stmt = $this->db->prepare($sql);
		
		// Bind parameters
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}
		
		$stmt->bindValue(':start', $start, PDO::PARAM_INT);
		if ($limit !== null) {
			$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		}
		
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
}