<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\repositories\ResourceRepository;
use App\modules\phpgwapi\models\ServerSettings;
use DateTime;
use DateInterval;
use DatePeriod;
use Exception;

/**
 * Service for calculating free time slots for resources
 * Handles time slot generation, overlap detection, and response formatting
 */
class FreetimeService
{
    private $db;
    private $scheduleService;
    private $resourceRepository;
    private $config;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->scheduleService = new ScheduleEntityService();
        $this->resourceRepository = new ResourceRepository();

        // Load booking configuration
        $serverSettings = ServerSettings::getInstance(true);
        $this->config = $serverSettings->booking_config;
    }

    /**
     * Get freetime slots for a single resource
     *
     * @param int $resourceId Resource ID
     * @param DateTime $startDate Start date
     * @param DateTime $endDate End date
     * @param bool $detailedOverlap Include detailed overlap information
     * @param bool $stopOnEndDate Stop on end date (affects boundary calculation)
     * @return array Array of time slots
     * @throws Exception
     */
    public function getFreetimeForResource(
        int $resourceId,
        DateTime $startDate,
        DateTime $endDate,
        bool $detailedOverlap = false,
        bool $stopOnEndDate = false
    ): array {
        // Get resource configuration
        $resource = $this->resourceRepository->getById($resourceId);
        if (!$resource) {
            throw new Exception("Resource not found: {$resourceId}");
        }

        // Calculate the effective date range for slot generation
        // Always generate slots through the end of the end date
        $slotEndDate = clone $endDate;
        $slotEndDate->setTime(23, 59, 59);

        // Get all scheduled items for this resource (wider range to catch all overlaps)
        $queryFrom = clone $startDate;
        $queryFrom->setTime(0, 0, 0);
        $queryTo = clone $slotEndDate;
        $queryTo->modify('+1 day');

        $scheduledItems = $this->scheduleService->getScheduledItemsForFreetime(
            [$resourceId],
            $queryFrom,
            $queryTo
        );

        // Generate time slots for the requested date range
        $slots = $this->generateTimeSlots($resource, $startDate, $slotEndDate);

        // Calculate booking boundaries for availability checking
        $bookingBoundaries = $this->calculateBookingBoundaries($resource);

        // Check overlaps and format response
        $result = [];
        foreach ($slots as $slot) {
            $overlap = $this->checkOverlap($slot, $scheduledItems, $resourceId, $bookingBoundaries);
            $formattedSlot = $this->formatSlot($slot, $overlap, $detailedOverlap, $resourceId);
            $result[] = $formattedSlot;
        }

        return $result;
    }

    /**
     * Get freetime slots for all resources in a building
     * Note: Building endpoint may be slow with many resources (N+1 query issue)
     * Future optimization: Implement batch queries for scheduled items
     *
     * @param int $buildingId Building ID
     * @param DateTime $startDate Start date
     * @param DateTime $endDate End date
     * @param bool $detailedOverlap Include detailed overlap information
     * @param bool $stopOnEndDate Stop on end date
     * @return array Array of time slots grouped by resource ID
     * @throws Exception
     */
    public function getFreetimeForBuilding(
        int $buildingId,
        DateTime $startDate,
        DateTime $endDate,
        bool $detailedOverlap = false,
        bool $stopOnEndDate = false
    ): array {
        // Get all resources for building that support timeslot booking
        $resources = $this->getResourcesForBuilding($buildingId);

        if (empty($resources)) {
            return [];
        }

        $result = [];
        foreach ($resources as $resource) {
            $slots = $this->getFreetimeForResource(
                $resource->id,
                $startDate,
                $endDate,
                $detailedOverlap,
                $stopOnEndDate
            );
            $result[$resource->id] = $slots;
        }

        return $result;
    }

    /**
     * Calculate booking boundaries based on resource horizons and limits
     * These boundaries determine which slots are bookable vs disabled
     *
     * @param object $resource Resource object
     * @return array ['max' => DateTime|null]
     */
    private function calculateBookingBoundaries($resource): array
    {
        $now = new DateTime();
        $boundaries = ['max' => null];

        // Maximum booking time (booking day horizon)
        // Counts FULL days from START of current day
        if (isset($resource->booking_day_horizon) && $resource->booking_day_horizon > 0) {
            $boundaries['max'] = clone $now;
            $boundaries['max']->setTime(0, 0, 0); // Start of current day
            $boundaries['max']->modify("+{$resource->booking_day_horizon} days");
            $boundaries['max']->setTime(23, 59, 59); // End of that day
        }

        // Month horizon would overwrite day horizon (per frontend logic)
        if (isset($resource->booking_month_horizon) && $resource->booking_month_horizon > 0) {
            // TODO: Implement month_shifter logic if needed
        }

        return $boundaries;
    }

    /**
     * Generate time slots based on resource configuration
     *
     * @param object $resource Resource object
     * @param DateTime $startDate Start date
     * @param DateTime $endDate End date (exclusive)
     * @return array Array of slots with start and end DateTime objects
     */
    private function generateTimeSlots($resource, DateTime $startDate, DateTime $endDate): array
    {
        $slots = [];

        // Get slot configuration
        $slotDuration = (int)($resource->booking_time_minutes ?? 120); // Default 2 hours
        $dayStart = (int)($resource->booking_time_default_start ?? 8); // Default 8 AM
        $dayEnd = (int)($resource->booking_time_default_end ?? 22); // Default 10 PM

        // Start from the beginning of startDate's day
        $current = clone $startDate;
        $current->setTime(0, 0, 0);

        // Create end boundary
        $endBoundary = clone $endDate;

        while ($current <= $endBoundary) {
            // Generate slots for this day
            $currentHour = $dayStart;

            while ($currentHour < $dayEnd) {
                $slotStart = clone $current;
                $slotStart->setTime((int)$currentHour, 0, 0);

                $slotEnd = clone $slotStart;
                $slotEnd->modify("+{$slotDuration} minutes");

                // Only add slot if start is within requested date range
                if ($slotStart >= $startDate && $slotStart <= $endBoundary) {
                    // Only add slot if end time is within operating hours
                    $slotEndHour = (int)$slotEnd->format('H') + ((int)$slotEnd->format('i') / 60);
                    if ($slotEndHour <= $dayEnd) {
                        $slots[] = [
                            'start' => $slotStart,
                            'end' => $slotEnd
                        ];
                    }
                }

                // Move to next slot
                $currentHour += ($slotDuration / 60);
            }

            // Move to next day
            $current->modify('+1 day');
        }

        return $slots;
    }

    /**
     * Check if a slot overlaps with any scheduled items or is outside booking boundaries
     *
     * @param array $slot Slot with start and end DateTime
     * @param array $scheduledItems All scheduled items
     * @param int $resourceId Current resource ID
     * @param array $boundaries Booking boundaries ['max' => DateTime|null]
     * @return array|null Overlap information or null if no overlap
     */
    private function checkOverlap(array $slot, array $scheduledItems, int $resourceId, array $boundaries): ?array
    {
        $now = new DateTime();

        // Check if slot is in the past
        if ($slot['start'] <= $now) {
            return [
                'status' => 3,
                'reason' => 'time_in_past',
                'type' => 'disabled'
            ];
        }

        // Check if slot is beyond maximum booking horizon
        if ($boundaries['max'] && $slot['start'] > $boundaries['max']) {
            return [
                'status' => 3,
                'reason' => 'beyond_booking_horizon',
                'type' => 'disabled'
            ];
        }

        // Check for overlaps with scheduled items
        foreach ($scheduledItems as $item) {
            // Skip if item doesn't affect this resource
            if (!in_array($resourceId, $item['resources'])) {
                continue;
            }

            // Parse item times
            $itemFrom = new DateTime($item['from_']);
            $itemTo = new DateTime($item['to_']);

            // Check for complete overlap (item completely covers slot)
            if ($itemFrom <= $slot['start'] && $itemTo >= $slot['end']) {
                return [
                    'status' => 1,
                    'reason' => 'complete_overlap',
                    'type' => 'complete',
                    'event' => $item
                ];
            }

            // Check for partial overlap
            if (($itemFrom < $slot['end'] && $itemTo > $slot['start'])) {
                return [
                    'status' => 2,
                    'reason' => 'partial_overlap',
                    'type' => 'partial',
                    'event' => $item
                ];
            }
        }

        // No overlap
        return null;
    }

    /**
     * Format a time slot for response
     *
     * @param array $slot Slot with start and end DateTime
     * @param array|null $overlap Overlap information
     * @param bool $detailedOverlap Include detailed information
     * @param int $resourceId Resource ID
     * @return array Formatted slot
     */
    private function formatSlot(
        array $slot,
        ?array $overlap,
        bool $detailedOverlap,
        int $resourceId
    ): array {
        $result = [
            'when' => $this->formatWhen($slot['start'], $slot['end']),
            'start' => (string)($slot['start']->getTimestamp() * 1000),
            'end' => (string)($slot['end']->getTimestamp() * 1000),
            'overlap' => $overlap ? $overlap['status'] : false
        ];

        // Add detailed information if requested
        if ($detailedOverlap) {
            $result['resource_id'] = $resourceId;
            $result['start_iso'] = $slot['start']->format('c');
            $result['end_iso'] = $slot['end']->format('c');
        }

        // Add overlap details if present
        if ($overlap) {
            $result['overlap_reason'] = $overlap['reason'];
            $result['overlap_type'] = $overlap['type'];

            // Add event details if available
            if (isset($overlap['event'])) {
                $event = $overlap['event'];
                $result['overlap_event'] = [
                    'id' => (int)$event['id'],
                    'type' => $event['type'],
                    'from_' => $event['from_'],
                    'to_' => $event['to_']
                ];
            }
        }

        return $result;
    }

    /**
     * Format the 'when' field: "21/01-2026 08:00 - 21/01-2026 10:00"
     *
     * @param DateTime $start Start time
     * @param DateTime $end End time
     * @return string Formatted when string
     */
    private function formatWhen(DateTime $start, DateTime $end): string
    {
        $startStr = $start->format('d/m-Y H:i');
        $endStr = $end->format('d/m-Y H:i');
        return "{$startStr} - {$endStr}";
    }

    /**
     * Get all resources for a building that support timeslot booking
     * Uses modern filters: simple_booking enabled and calendar not deactivated
     *
     * @param int $buildingId Building ID
     * @return array Array of Resource objects
     * @throws Exception
     */
    private function getResourcesForBuilding(int $buildingId): array
    {
        try {
            $sql = "SELECT r.*
                    FROM bb_resource r
                    JOIN bb_building_resource br ON r.id = br.resource_id
                    WHERE br.building_id = :building_id
                    AND r.active = 1
                    AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)
                    AND (r.deactivate_calendar = 0 OR r.deactivate_calendar IS NULL)
                    AND r.simple_booking = 1
                    ORDER BY r.sort, r.name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':building_id' => $buildingId]);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return array_map(function($data) {
                return $this->resourceRepository->createResource($data);
            }, $results);
        } catch (Exception $e) {
            throw new Exception("Error fetching resources for building {$buildingId}: " . $e->getMessage());
        }
    }
}
