<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\helpers\ApplicationHelper;
use App\modules\bookingfrontend\repositories\ApplicationRepository;
use App\modules\bookingfrontend\models\Event;
use App\modules\bookingfrontend\models\Booking;
use App\modules\bookingfrontend\models\Allocation;
use App\modules\phpgwapi\models\ServerSettings;
use GuzzleHttp\Psr7\ServerRequest;
use PDO;
use DateTime;
use Exception;

/**
 * Service for handling schedule entities (events, allocations, bookings)
 */
class ScheduleEntityService
{
    private $db;
    private $bouser;
    private $applicationHelper;
    private $applicationRepository;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->bouser = new UserHelper();
        $this->applicationHelper = new ApplicationHelper();
        $this->applicationRepository = new ApplicationRepository();
    }

    /**
     * Get events created from a specific application with resources and serialization
     * @param int $applicationId The application ID
     * @return array Array of serialized events
     * @throws Exception
     */
    public function getEventsByApplicationId(int $applicationId): array
    {
        try {
            $sql = "SELECT e.*,
                        r.id as resource_id,
                        r.name as resource_name,
                        r.activity_id,
                        act.name as activity_name
                    FROM bb_event e
                    JOIN bb_event_resource er ON e.id = er.event_id
                    JOIN bb_resource r ON er.resource_id = r.id
                    LEFT JOIN bb_activity act ON r.activity_id = act.id
                    WHERE e.application_id = ? AND e.active = 1
                    ORDER BY e.from_ ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$applicationId]);

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get application data for permission checking
            $application = $this->applicationRepository->getApplicationById($applicationId);

            // Load booking configuration
            $config = $this->loadBookingConfig();

            // Get User orgs for serialization
            $userOrgs = $this->bouser->organizations ? array_column($this->bouser->organizations, 'orgnr') : null;

            $results = [];
            foreach ($this->groupByEntity($rows) as $eventGroup) {
                $event = new Event($eventGroup[0]);
                $event->resources = array_map([$this, 'formatResource'], $eventGroup);
                
                // Add edit/cancel links
                $eventData = $event->serialize(['user_ssn' => $this->bouser->ssn, "organization_number" => $userOrgs]);
                $this->addEditCancelLinks($eventData, $config, 'event', $application);
                
                $results[] = $eventData;
            }

            return $results;
        } catch (Exception $e) {
            throw new Exception("Error fetching events for application {$applicationId}: " . $e->getMessage());
        }
    }

    /**
     * Get allocations created from a specific application with resources and serialization
     * @param int $applicationId The application ID
     * @return array Array of serialized allocations
     * @throws Exception
     */
    public function getAllocationsByApplicationId(int $applicationId): array
    {
        try {
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
                    WHERE a.application_id = ? AND a.active = 1
                    ORDER BY a.from_ ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$applicationId]);

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get application data for permission checking
            $application = $this->applicationRepository->getApplicationById($applicationId);

            // Load booking configuration
            $config = $this->loadBookingConfig();

            $results = [];
            foreach ($this->groupByEntity($rows) as $allocationGroup) {
                $allocation = new Allocation($allocationGroup[0]);
                $allocation->resources = array_map([$this, 'formatResource'], $allocationGroup);
                
                // Add edit/cancel links
                $allocationData = $allocation->serialize();
                $this->addEditCancelLinks($allocationData, $config, 'allocation', $application);
                
                $results[] = $allocationData;
            }

            return $results;
        } catch (Exception $e) {
            throw new Exception("Error fetching allocations for application {$applicationId}: " . $e->getMessage());
        }
    }

    /**
     * Get bookings created from a specific application with resources and serialization
     * @param int $applicationId The application ID
     * @return array Array of serialized bookings
     * @throws Exception
     */
    public function getBookingsByApplicationId(int $applicationId): array
    {
        try {
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
                    WHERE b.application_id = ? AND b.active = 1
                    ORDER BY b.from_ ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$applicationId]);

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get application data for permission checking
            $application = $this->applicationRepository->getApplicationById($applicationId);

            // Load booking configuration
            $config = $this->loadBookingConfig();

            // Get User groups for serialization
            $userGroups = $this->bouser->getUserGroups();
            $userGroupIds = $userGroups ? array_column($userGroups, 'id') : null;

            $results = [];
            foreach ($this->groupByEntity($rows) as $bookingGroup) {
                $booking = new Booking($bookingGroup[0]);
                $booking->resources = array_map([$this, 'formatResource'], $bookingGroup);
                
                // Add edit/cancel links
                $bookingData = $booking->serialize(['user_ssn' => $this->bouser->ssn, "user_group_id" => $userGroupIds]);
                $this->addEditCancelLinks($bookingData, $config, 'booking', $application);
                
                $results[] = $bookingData;
            }

            return $results;
        } catch (Exception $e) {
            throw new Exception("Error fetching bookings for application {$applicationId}: " . $e->getMessage());
        }
    }

    /**
     * Get all schedule entities (events, allocations, bookings) created from a specific application
     * @param int $applicationId The application ID
     * @return array Array with events, allocations, and bookings
     * @throws Exception
     */
    public function getScheduleEntitiesByApplicationId(int $applicationId): array
    {
        try {
            return [
                'events' => $this->getEventsByApplicationId($applicationId),
                'allocations' => $this->getAllocationsByApplicationId($applicationId),
                'bookings' => $this->getBookingsByApplicationId($applicationId)
            ];
        } catch (Exception $e) {
            throw new Exception("Error fetching schedule entities for application {$applicationId}: " . $e->getMessage());
        }
    }


    /**
     * Get weekly schedules for multiple dates for a building
     * @param int $building_id The building ID
     * @param array $dates Array of DateTime objects
     * @return array Array of schedules keyed by week start date
     * @throws Exception
     */
    public function getBuildingWeeklySchedules(int $building_id, array $dates): array
    {
        // Verify building exists first
        $sql = "SELECT id FROM bb_building WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$building_id]);

        if (!$stmt->fetch()) {
            throw new Exception("Building not found");
        }

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

    /**
     * Get schedule for a specific resource within date range
     * @param int $resource_id The resource ID
     * @param DateTime $start_date Start date
     * @param DateTime $end_date End date
     * @return array Array of schedule entities
     * @throws Exception
     */
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

    /**
     * Get schedule for a specific week for a building
     * @param int $building_id The building ID
     * @param DateTime $weekStart Start of the week (Monday)
     * @return array Array of schedule entities
     * @throws Exception
     */
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

    /**
     * Group database rows by entity ID
     * @param array $rows Database rows
     * @return array Grouped rows
     */
    private function groupByEntity(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['id']][] = $row;
        }
        return $grouped;
    }

    /**
     * Format resource data for output
     * @param array $data Resource data
     * @return array Formatted resource
     */
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

    /**
     * Get all resources for a building
     * @param int $building_id The building ID
     * @return array Array of resources
     * @throws Exception
     */
    private function getResourcesForBuilding(int $building_id): array
    {
        try {
            $sql = "SELECT r.*, a.name as activity_name
                    FROM bb_resource r
                    JOIN bb_building_resource br ON r.id = br.resource_id
                    LEFT JOIN bb_activity a ON r.activity_id = a.id
                    WHERE br.building_id = ?
                    AND r.active = 1
                    AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$building_id]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching resources for building {$building_id}: " . $e->getMessage());
        }
    }

    /**
     * Get allocations for a building and resources within date range
     * @param int $building_id The building ID
     * @param array $resource_ids Array of resource IDs
     * @param DateTime $from Start date
     * @param DateTime $to End date
     * @return array Array of allocations
     * @throws Exception
     */
    private function getAllocations(int $building_id, array $resource_ids, DateTime $from, DateTime $to): array
    {
        try {
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
                    WHERE r.id IN (" . implode(',', array_map('intval', $resource_ids)) . ")
                    AND a.active = 1
                    AND s.active = 1
                    AND s.status = 'PUBLISHED'
                    AND ((a.from_ >= ? AND a.from_ < ?)
                    OR (a.to_ > ? AND a.to_ <= ?)
                    OR (a.from_ < ? AND a.to_ > ?))
                    ORDER BY a.id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching allocations: " . $e->getMessage());
        }
    }

    /**
     * Get bookings for a building and resources within date range
     * @param int $building_id The building ID
     * @param array $resource_ids Array of resource IDs
     * @param DateTime $from Start date
     * @param DateTime $to End date
     * @return array Array of bookings
     * @throws Exception
     */
    private function getBookings(int $building_id, array $resource_ids, DateTime $from, DateTime $to): array
    {
        try {
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
                    WHERE r.id IN (" . implode(',', array_map('intval', $resource_ids)) . ")
                    AND b.active = 1
                    AND s.active = 1
                    AND s.status = 'PUBLISHED'
                    AND ((b.from_ >= ? AND b.from_ < ?)
                    OR (b.to_ > ? AND b.to_ <= ?)
                    OR (b.from_ < ? AND b.to_ > ?))
                    ORDER BY b.id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching bookings: " . $e->getMessage());
        }
    }

    /**
     * Get events for a building and resources within date range
     * @param int $building_id The building ID
     * @param array $resource_ids Array of resource IDs
     * @param DateTime $from Start date
     * @param DateTime $to End date
     * @return array Array of events
     * @throws Exception
     */
    private function getEvents(int $building_id, array $resource_ids, DateTime $from, DateTime $to): array
    {
        try {
            $sql = "SELECT e.*,
                        r.id as resource_id,
                        r.name as resource_name,
                        r.activity_id,
                        act.name as activity_name
                    FROM bb_event e
                    JOIN bb_event_resource er ON e.id = er.event_id
                    JOIN bb_resource r ON er.resource_id = r.id
                    LEFT JOIN bb_activity act ON r.activity_id = act.id
                    WHERE r.id IN (" . implode(',', array_map('intval', $resource_ids)) . ")
                    AND e.active = 1
                    AND ((e.from_ >= ? AND e.from_ < ?)
                    OR (e.to_ > ? AND e.to_ <= ?)
                    OR (e.from_ < ? AND e.to_ > ?))
                    ORDER BY e.id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching events: " . $e->getMessage());
        }
    }

    /**
     * Get resource by ID
     * @param int $resource_id The resource ID
     * @return array|null Resource data or null if not found
     * @throws Exception
     */
    private function getResourceById(int $resource_id): ?array
    {
        try {
            $sql = "SELECT r.*, a.name as activity_name, br.building_id
                    FROM bb_resource r
                    LEFT JOIN bb_activity a ON r.activity_id = a.id
                    LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                    WHERE r.id = ?
                    AND r.active = 1
                    AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$resource_id]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (Exception $e) {
            throw new Exception("Error fetching resource {$resource_id}: " . $e->getMessage());
        }
    }

    /**
     * Get allocations for a specific resource within date range
     * @param int $resource_id The resource ID
     * @param DateTime $from Start date
     * @param DateTime $to End date
     * @return array Array of allocations
     * @throws Exception
     */
    private function getAllocationsForResource(int $resource_id, DateTime $from, DateTime $to): array
    {
        try {
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
                    WHERE r.id = ?
                    AND a.active = 1
                    AND s.active = 1
                    AND s.status = 'PUBLISHED'
                    AND ((a.from_ >= ? AND a.from_ < ?)
                    OR (a.to_ > ? AND a.to_ <= ?)
                    OR (a.from_ < ? AND a.to_ > ?))
                    ORDER BY a.id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $resource_id,
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching allocations for resource {$resource_id}: " . $e->getMessage());
        }
    }

    /**
     * Get bookings for a specific resource within date range
     * @param int $resource_id The resource ID
     * @param DateTime $from Start date
     * @param DateTime $to End date
     * @return array Array of bookings
     * @throws Exception
     */
    private function getBookingsForResource(int $resource_id, DateTime $from, DateTime $to): array
    {
        try {
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
                    WHERE r.id = ?
                    AND b.active = 1
                    AND s.active = 1
                    AND s.status = 'PUBLISHED'
                    AND ((b.from_ >= ? AND b.from_ < ?)
                    OR (b.to_ > ? AND b.to_ <= ?)
                    OR (b.from_ < ? AND b.to_ > ?))
                    ORDER BY b.id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $resource_id,
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching bookings for resource {$resource_id}: " . $e->getMessage());
        }
    }

    /**
     * Get events for a specific resource within date range
     * @param int $resource_id The resource ID
     * @param DateTime $from Start date
     * @param DateTime $to End date
     * @return array Array of events
     * @throws Exception
     */
    private function getEventsForResource(int $resource_id, DateTime $from, DateTime $to): array
    {
        try {
            $sql = "SELECT e.*,
                        r.id as resource_id,
                        r.name as resource_name,
                        r.activity_id,
                        act.name as activity_name
                    FROM bb_event e
                    JOIN bb_event_resource er ON e.id = er.event_id
                    JOIN bb_resource r ON er.resource_id = r.id
                    LEFT JOIN bb_activity act ON r.activity_id = act.id
                    WHERE r.id = ?
                    AND e.active = 1
                    AND ((e.from_ >= ? AND e.from_ < ?)
                    OR (e.to_ > ? AND e.to_ <= ?)
                    OR (e.from_ < ? AND e.to_ > ?))
                    ORDER BY e.id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $resource_id,
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching events for resource {$resource_id}: " . $e->getMessage());
        }
    }

    /**
     * Load booking configuration using ServerSettings
     * @return \App\modules\booking\models\BookingConfig
     */
    private function loadBookingConfig()
    {
        $serverSettings = ServerSettings::getInstance(true);
        return $serverSettings->booking_config;
    }

    /**
     * Add edit/cancel links to schedule entity data
     * @param array &$entityData Reference to entity data array
     * @param \App\modules\booking\models\BookingConfig $config Configuration data
     * @param string $entityType Type of entity (booking, allocation, event)
     * @param array|null $application Application data for permission checking
     * @return void
     */
    private function addEditCancelLinks(array &$entityData, $config, string $entityType, ?array $application = null): void
    {
        $entityData['edit_link'] = null;
        $entityData['cancel_link'] = null;

        // Check if user is logged in
        if (!$this->bouser->is_logged_in()) {
            return;
        }
        
        // Check application access if we have application data
        $hasApplicationAccess = true;
        if ($application) {
            $mockRequest = new ServerRequest('GET', '');
            $hasApplicationAccess = $this->applicationHelper->canModifyApplication($application, $mockRequest);
        }
        
        if (!$hasApplicationAccess) {
            return;
        }

        $hasPermission = false;
        $canDelete = false;

        switch ($entityType) {
            case 'booking':
                $groupId = $entityData['group_id'] ?? 0;
                $hasPermission = $this->bouser->is_group_admin($groupId);
                $canDelete = ($config->user_can_delete_bookings ?? 'no') === 'yes';
                break;
            case 'allocation':
                $orgId = $entityData['organization_id'] ?? 0;
                $hasPermission = $this->bouser->is_organization_admin($orgId);
                $canDelete = ($config->user_can_delete_allocations ?? 'no') === 'yes';
                break;
            case 'event':
                // For events created from applications, use application modify permission
                if ($application) {
                    $mockRequest = new ServerRequest('GET', '');
                    $hasPermission = $this->applicationHelper->canModifyApplication($application, $mockRequest);
                } else {
                    $hasPermission = false;
                }
                $canDelete = ($config->user_can_delete_events ?? 'no') === 'yes';
                break;
        }

        if (!$hasPermission) {
            return;
        }

        $fromDate = $entityData['from_'] ?? null;
        if (!$fromDate || $fromDate <= date('Y-m-d H:i:s')) {
            return;
        }

        $resourceIds = isset($entityData['resources']) ? array_column($entityData['resources'], 'id') : [];

        // Generate edit link
        $entityData['edit_link'] = $this->generateLink([
            'menuaction' => "bookingfrontend.ui{$entityType}.edit",
            'id' => $entityData['id'],
            'resource_ids' => $resourceIds
        ]);

        // Generate cancel link if allowed
        if ($canDelete) {
            $entityData['cancel_link'] = $this->generateLink([
                'menuaction' => "bookingfrontend.ui{$entityType}.cancel",
                'id' => $entityData['id'],
                'resource_ids' => $resourceIds
            ]);
        }
    }

    /**
     * Generate link URL for frontend actions
     * @param array $params URL parameters
     * @return string Generated URL
     */
    private function generateLink(array $params): string
    {
        // Handle resource_ids array properly
        if (isset($params['resource_ids']) && is_array($params['resource_ids'])) {
            $query = '';
            $otherParams = $params;
            $resourceIds = $otherParams['resource_ids'];
            unset($otherParams['resource_ids']);
            
            // Add non-array parameters
            if (!empty($otherParams)) {
                $query = http_build_query($otherParams);
            }
            
            // Add resource_ids as array parameters
            foreach ($resourceIds as $resourceId) {
                $query .= ($query ? '&' : '') . 'resource_ids[]=' . urlencode($resourceId);
            }
            
            return "/bookingfrontend/?{$query}";
        }
        
        $query = http_build_query($params);
        return "/bookingfrontend/?{$query}";
    }

}