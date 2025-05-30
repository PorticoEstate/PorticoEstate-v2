<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\Event;
use App\modules\bookingfrontend\models\Booking;
use App\modules\bookingfrontend\models\Allocation;
use PDO;
use DateTime;

class BuildingScheduleService
{
    private $db;
    private $bouser;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->bouser = new UserHelper();

    }

    public function getWeeklySchedules(int $building_id, array $dates): array
    {
        $schedules = [];

        foreach ($dates as $date) {
            $weekStart = clone $date;
            // Ensure we start from Monday
            if ($weekStart->format('w') != 1) {
                $weekStart->modify('last monday');
            }
            $weekStart->setTime(0, 0, 0);

            // Use the Monday date as the key in our response
            $key = $weekStart->format('Y-m-d');
            $schedules[$key] = $this->getScheduleForWeek($building_id, $weekStart);
        }

        return $schedules;
    }
    
    public function getResourceSchedule(int $resource_id, DateTime $start_date, DateTime $end_date): array
    {
        // Get resource info
        $resource = $this->getResourceById($resource_id);
        
        if (empty($resource)) {
            return [];
        }
        
        $building_id = $resource['building_id'];
        $results = [];
        
        // Get User orgs
        $userOrgs = $this->bouser->organizations ? array_column($this->bouser->organizations, 'orgnr') : null;
        $userOrgIds = $this->bouser->organizations ? array_column($this->bouser->organizations, 'org_id') : null;

        // Get User groups
        $userGroups = $this->bouser->getUserGroups();
        $userGroupIds = $userGroups ? array_column($userGroups, 'id') : null;

        // Get allocations for this resource
        $allocations = $this->getAllocationsForResource($resource_id, $start_date, $end_date);
        foreach ($this->groupByEntity($allocations) as $allocationGroup) {
            $allocation = new Allocation($allocationGroup[0]);
            $allocation->resources = array_map([$this, 'formatResource'], $allocationGroup);
            $results[] = $allocation->serialize();
        }

        // Get bookings for this resource
        $bookings = $this->getBookingsForResource($resource_id, $start_date, $end_date);
        foreach ($this->groupByEntity($bookings) as $bookingGroup) {
            $booking = new Booking($bookingGroup[0]);
            $booking->resources = array_map([$this, 'formatResource'], $bookingGroup);
            $results[] = $booking->serialize(['user_ssn' => $this->bouser->ssn, "user_group_id" => $userGroupIds]);
        }

        // Get events for this resource
        $events = $this->getEventsForResource($resource_id, $start_date, $end_date);
        foreach ($this->groupByEntity($events) as $eventGroup) {
            $event = new Event($eventGroup[0]);
            $event->resources = array_map([$this, 'formatResource'], $eventGroup);
            $results[] = $event->serialize(['user_ssn' => $this->bouser->ssn, "organization_number" => $userOrgs]);
        }

        return $results;
    }

    private function getScheduleForWeek(int $building_id, DateTime $weekStart): array
    {
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+7 days');

        // Get resources for the building
        $resources = $this->getResourcesForBuilding($building_id);
        $resource_ids = array_column($resources, 'id');

        if (empty($resource_ids)) {
            return [];
        }

        $results = [];
		// Get User orgs
		$userOrgs = $this->bouser->organizations ? array_column($this->bouser->organizations, 'orgnr') : null;
		$userOrgIds = $this->bouser->organizations ? array_column($this->bouser->organizations, 'org_id') : null;

		// Get User groups
		$userGroups = $this->bouser->getUserGroups();
		$userGroupIds = $userGroups ? array_column($userGroups, 'id') : null;

        // Get allocations with their resources
        $allocations = $this->getAllocations($building_id, $resource_ids, $weekStart, $weekEnd);
        foreach ($this->groupByEntity($allocations) as $allocationGroup) {
            $allocation = new Allocation($allocationGroup[0]);
            $allocation->resources = array_map([$this, 'formatResource'], $allocationGroup);
            $results[] = $allocation->serialize();
        }

        // Get bookings with their resources
        $bookings = $this->getBookings($building_id, $resource_ids, $weekStart, $weekEnd);
        foreach ($this->groupByEntity($bookings) as $bookingGroup) {
            $booking = new Booking($bookingGroup[0]);
            $booking->resources = array_map([$this, 'formatResource'], $bookingGroup);
			$results[] = $booking->serialize(['user_ssn' => $this->bouser->ssn, "user_group_id" => $userGroupIds]);

		}


        // Get events with their resources
        $events = $this->getEvents($building_id, $resource_ids, $weekStart, $weekEnd);
        foreach ($this->groupByEntity($events) as $eventGroup) {
            $event = new Event($eventGroup[0]);
            $event->resources = array_map([$this, 'formatResource'], $eventGroup);
            $results[] = $event->serialize(['user_ssn' => $this->bouser->ssn, "organization_number" => $userOrgs]);
        }

        return $results;
    }

    private function groupByEntity(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['id']][] = $row;
        }
        return $grouped;
    }

    private function formatResource(array $data): array
    {
        return [
            'id' => $data['resource_id'],
            'name' => $data['resource_name'],
            'activity_id' => $data['activity_id'] ?? null,
            'activity_name' => $data['activity_name'] ?? null,
            'building_id' => $data['building_id'] ?? null,
        ];
    }

    private function getResourcesForBuilding(int $building_id): array
    {
        $sql = "SELECT r.*, a.name as activity_name
                FROM bb_resource r
                JOIN bb_building_resource br ON r.id = br.resource_id
                LEFT JOIN bb_activity a ON r.activity_id = a.id
                WHERE br.building_id = :building_id
                AND r.active = 1
                AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':building_id' => $building_id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getAllocations(int $building_id, array $resource_ids, DateTime $from, DateTime $to): array
    {
        $sql = "SELECT a.*,
                    o.name as organization_name,
                    o.shortname as organization_shortname,
                    r.id as resource_id,
                    r.name as resource_name,
                    r.activity_id,
                    act.name as activity_name,
                    s.name as season_name
                FROM bb_allocation a
                JOIN bb_allocation_resource ar ON a.id = ar.allocation_id
                JOIN bb_resource r ON ar.resource_id = r.id
                JOIN bb_organization o ON a.organization_id = o.id
                JOIN bb_season s ON a.season_id = s.id
                LEFT JOIN bb_activity act ON r.activity_id = act.id
                WHERE r.id IN (" . implode(',', $resource_ids) . ")
                AND a.active = 1
                AND s.active = 1
                AND s.status = 'PUBLISHED'
                AND ((a.from_ >= :start AND a.from_ < :end)
                OR (a.to_ > :start AND a.to_ <= :end)
                OR (a.from_ < :start AND a.to_ > :end))
                ORDER BY a.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':start' => $from->format('Y-m-d H:i:s'),
            ':end' => $to->format('Y-m-d H:i:s')
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getBookings(int $building_id, array $resource_ids, DateTime $from, DateTime $to): array
    {
        $sql = "SELECT b.*,
                    g.name as group_name,
                    g.shortname as group_shortname,
                    r.id as resource_id,
                    r.name as resource_name,
                    r.activity_id,
                    act.name as activity_name,
                    s.name as season_name
                FROM bb_booking b
                JOIN bb_booking_resource br ON b.id = br.booking_id
                JOIN bb_resource r ON br.resource_id = r.id
                JOIN bb_group g ON b.group_id = g.id
                JOIN bb_season s ON b.season_id = s.id
                LEFT JOIN bb_activity act ON r.activity_id = act.id
                WHERE r.id IN (" . implode(',', $resource_ids) . ")
                AND b.active = 1
                AND s.active = 1
                AND s.status = 'PUBLISHED'
                AND ((b.from_ >= :start AND b.from_ < :end)
                OR (b.to_ > :start AND b.to_ <= :end)
                OR (b.from_ < :start AND b.to_ > :end))
                ORDER BY b.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':start' => $from->format('Y-m-d H:i:s'),
            ':end' => $to->format('Y-m-d H:i:s')
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getEvents(int $building_id, array $resource_ids, DateTime $from, DateTime $to): array
    {
        $sql = "SELECT e.*,
                    r.id as resource_id,
                    r.name as resource_name,
                    r.activity_id,
                    act.name as activity_name
                FROM bb_event e
                JOIN bb_event_resource er ON e.id = er.event_id
                JOIN bb_resource r ON er.resource_id = r.id
                LEFT JOIN bb_activity act ON r.activity_id = act.id
                WHERE r.id IN (" . implode(',', $resource_ids) . ")
                AND e.active = 1
                AND ((e.from_ >= :start AND e.from_ < :end)
                OR (e.to_ > :start AND e.to_ <= :end)
                OR (e.from_ < :start AND e.to_ > :end))
                ORDER BY e.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':start' => $from->format('Y-m-d H:i:s'),
            ':end' => $to->format('Y-m-d H:i:s')
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getResourceById(int $resource_id): ?array
    {
        $sql = "SELECT r.*, a.name as activity_name, br.building_id
                FROM bb_resource r
                LEFT JOIN bb_activity a ON r.activity_id = a.id
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                WHERE r.id = :resource_id
                AND r.active = 1
                AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':resource_id' => $resource_id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result : null;
    }
    
    private function getAllocationsForResource(int $resource_id, DateTime $from, DateTime $to): array
    {
        $sql = "SELECT a.*,
                    o.name as organization_name,
                    o.shortname as organization_shortname,
                    r.id as resource_id,
                    r.name as resource_name,
                    r.activity_id,
                    act.name as activity_name,
                    s.name as season_name
                FROM bb_allocation a
                JOIN bb_allocation_resource ar ON a.id = ar.allocation_id
                JOIN bb_resource r ON ar.resource_id = r.id
                JOIN bb_organization o ON a.organization_id = o.id
                JOIN bb_season s ON a.season_id = s.id
                LEFT JOIN bb_activity act ON r.activity_id = act.id
                WHERE r.id = :resource_id
                AND a.active = 1
                AND s.active = 1
                AND s.status = 'PUBLISHED'
                AND ((a.from_ >= :start AND a.from_ < :end)
                OR (a.to_ > :start AND a.to_ <= :end)
                OR (a.from_ < :start AND a.to_ > :end))
                ORDER BY a.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':resource_id' => $resource_id,
            ':start' => $from->format('Y-m-d H:i:s'),
            ':end' => $to->format('Y-m-d H:i:s')
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getBookingsForResource(int $resource_id, DateTime $from, DateTime $to): array
    {
        $sql = "SELECT b.*,
                    g.name as group_name,
                    g.shortname as group_shortname,
                    r.id as resource_id,
                    r.name as resource_name,
                    r.activity_id,
                    act.name as activity_name,
                    s.name as season_name
                FROM bb_booking b
                JOIN bb_booking_resource br ON b.id = br.booking_id
                JOIN bb_resource r ON br.resource_id = r.id
                JOIN bb_group g ON b.group_id = g.id
                JOIN bb_season s ON b.season_id = s.id
                LEFT JOIN bb_activity act ON r.activity_id = act.id
                WHERE r.id = :resource_id
                AND b.active = 1
                AND s.active = 1
                AND s.status = 'PUBLISHED'
                AND ((b.from_ >= :start AND b.from_ < :end)
                OR (b.to_ > :start AND b.to_ <= :end)
                OR (b.from_ < :start AND b.to_ > :end))
                ORDER BY b.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':resource_id' => $resource_id,
            ':start' => $from->format('Y-m-d H:i:s'),
            ':end' => $to->format('Y-m-d H:i:s')
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getEventsForResource(int $resource_id, DateTime $from, DateTime $to): array
    {
        $sql = "SELECT e.*,
                    r.id as resource_id,
                    r.name as resource_name,
                    r.activity_id,
                    act.name as activity_name
                FROM bb_event e
                JOIN bb_event_resource er ON e.id = er.event_id
                JOIN bb_resource r ON er.resource_id = r.id
                LEFT JOIN bb_activity act ON r.activity_id = act.id
                WHERE r.id = :resource_id
                AND e.active = 1
                AND ((e.from_ >= :start AND e.from_ < :end)
                OR (e.to_ > :start AND e.to_ <= :end)
                OR (e.from_ < :start AND e.to_ > :end))
                ORDER BY e.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':resource_id' => $resource_id,
            ':start' => $from->format('Y-m-d H:i:s'),
            ':end' => $to->format('Y-m-d H:i:s')
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}