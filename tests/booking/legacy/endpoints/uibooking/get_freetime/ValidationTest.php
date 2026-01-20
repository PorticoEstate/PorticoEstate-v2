<?php

namespace Tests\Booking\Legacy\Endpoints\Uibooking\GetFreetime;

require_once __DIR__ . '/../../../../../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use DateTime;
use DateTimeZone;

/**
 * Validation Test for bookingfrontend.uibooking.get_freetime endpoint
 *
 * This test fetches actual resource configuration and schedule data,
 * then validates that the freetime API response correctly reflects:
 * - Resource configuration (time slots, horizons, operating hours)
 * - Scheduled items (events, bookings, allocations) appear as overlaps
 * - Available slots have no scheduled items
 * - Overlap details match the actual scheduled items
 */
class ValidationTest extends TestCase
{
    private string $baseUrl;
    private int $testResourceId;
    private int $testBuildingId;
    private ?array $resourceConfig = null;
    private DateTimeZone $timezone;

    protected function setUp(): void
    {
        // Load configuration from environment variables with defaults
        $this->baseUrl = getenv('FREETIME_TEST_BASE_URL') ?: 'https://pe-api.test';
        $this->testResourceId = (int)(getenv('FREETIME_TEST_RESOURCE_ID') ?: 106);
        $this->testBuildingId = (int)(getenv('FREETIME_TEST_BUILDING_ID') ?: 10);
        $timezone = getenv('FREETIME_TEST_TIMEZONE') ?: 'Europe/Oslo';
        $this->timezone = new DateTimeZone($timezone);

        // Fetch actual resource configuration
        $this->resourceConfig = $this->fetchResource($this->testResourceId);
        $this->assertNotNull($this->resourceConfig, "Could not fetch resource configuration");
    }

    /**
     * Test 1: Validate time slots match resource configuration
     */
    public function testTimeSlotsMatchResourceConfiguration(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        $freetime = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $slots = $freetime[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have slots for tomorrow");

        // Validate slot duration matches booking_time_minutes
        $expectedDurationMs = $this->resourceConfig['booking_time_minutes'] * 60 * 1000;

        foreach ($slots as $index => $slot) {
            $duration = (int)$slot['end'] - (int)$slot['start'];
            $this->assertEquals(
                $expectedDurationMs,
                $duration,
                "Slot $index duration should be {$this->resourceConfig['booking_time_minutes']} minutes"
            );
        }

        // Validate time range matches booking_time_default_start and booking_time_default_end
        $firstSlot = $slots[0];
        $lastSlot = $slots[count($slots) - 1];

        $firstStartTime = new DateTime();
        $firstStartTime->setTimestamp((int)$firstSlot['start'] / 1000);
        $firstStartTime->setTimezone($this->timezone);

        $lastEndTime = new DateTime();
        $lastEndTime->setTimestamp((int)$lastSlot['end'] / 1000);
        $lastEndTime->setTimezone($this->timezone);

        $expectedStartHour = $this->resourceConfig['booking_time_default_start'];
        $expectedEndHour = $this->resourceConfig['booking_time_default_end'];

        $this->assertEquals(
            $expectedStartHour,
            (int)$firstStartTime->format('H'),
            "First slot should start at {$expectedStartHour}:00"
        );

        $this->assertEquals(
            $expectedEndHour,
            (int)$lastEndTime->format('H'),
            "Last slot should end at {$expectedEndHour}:00"
        );

        echo "\n✓ Time slots correctly match resource configuration:\n";
        echo "  - Duration: {$this->resourceConfig['booking_time_minutes']} minutes\n";
        echo "  - Start hour: {$expectedStartHour}:00\n";
        echo "  - End hour: {$expectedEndHour}:00\n";
        echo "  - Total slots per day: " . count($slots) . "\n";
    }

    /**
     * Test 2: Validate all scheduled items appear as overlaps
     */
    public function testScheduledItemsAppearAsOverlaps(): void
    {
        $startDate = new DateTime('now', $this->timezone);
        $endDate = new DateTime('+3 days', $this->timezone);

        // Fetch actual schedule
        $schedule = $this->fetchScheduleForDateRange($this->testResourceId, $startDate, $endDate);

        // Fetch freetime response
        $freetime = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate->format('d/m-Y'),
            'end_date' => $endDate->format('d/m-Y'),
            'detailed_overlap' => true,
        ]);

        $slots = $freetime[$this->testResourceId] ?? [];

        // Create a map of overlapped slots
        $overlappedSlots = [];
        foreach ($slots as $slot) {
            if (isset($slot['overlap']) && $slot['overlap'] !== false && $slot['overlap'] !== 0 && $slot['overlap'] !== 3) {
                $overlappedSlots[] = $slot;
            }
        }

        echo "\n=== Schedule vs Freetime Overlap Validation ===\n";
        echo "Date range: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}\n";
        echo "Scheduled items found: " . count($schedule) . "\n";
        echo "Overlapped slots found: " . count($overlappedSlots) . "\n\n";

        $validationResults = [];

        // Validate each scheduled item
        foreach ($schedule as $item) {
            $itemFrom = new DateTime($item['from_']);
            $itemTo = new DateTime($item['to_']);

            echo "Checking {$item['type']} ID {$item['id']}: ";
            echo "{$itemFrom->format('Y-m-d H:i')} - {$itemTo->format('Y-m-d H:i')}\n";

            // Find matching overlap(s)
            $matches = $this->findMatchingOverlaps($overlappedSlots, $itemFrom, $itemTo, $item['id']);

            if (empty($matches)) {
                echo "  ✗ NOT FOUND in freetime overlaps\n";
                $validationResults[] = [
                    'item' => $item,
                    'found' => false,
                    'matches' => []
                ];
            } else {
                echo "  ✓ Found in " . count($matches) . " slot(s)\n";
                foreach ($matches as $match) {
                    echo "    - Slot: {$match['when']}\n";
                    echo "      overlap_event.id: " . ($match['overlap_event']['id'] ?? 'NULL') . "\n";
                    echo "      overlap_event.type: " . ($match['overlap_event']['type'] ?? 'NULL') . "\n";

                    // Validate type matches
                    if (isset($match['overlap_event']['type']) && $match['overlap_event']['type'] !== $item['type']) {
                        echo "      ⚠ TYPE MISMATCH: expected '{$item['type']}', got '{$match['overlap_event']['type']}'\n";
                    } elseif (!isset($match['overlap_event']['type']) || $match['overlap_event']['type'] === null) {
                        echo "      ⚠ TYPE IS NULL (should be '{$item['type']}')\n";
                    }
                }
                $validationResults[] = [
                    'item' => $item,
                    'found' => true,
                    'matches' => $matches
                ];
            }
        }

        // Summary
        $found = count(array_filter($validationResults, fn($r) => $r['found']));
        $notFound = count($validationResults) - $found;

        echo "\n=== Validation Summary ===\n";
        echo "✓ Found: $found/" . count($schedule) . "\n";
        echo "✗ Not found: $notFound/" . count($schedule) . "\n";

        // Assert all scheduled items were found
        $this->assertEquals(
            count($schedule),
            $found,
            "All scheduled items should appear as overlaps in freetime response"
        );

        // Check for type field issues
        $nullTypes = 0;
        foreach ($overlappedSlots as $slot) {
            if (!isset($slot['overlap_event']['type']) || $slot['overlap_event']['type'] === null) {
                $nullTypes++;
            }
        }

        if ($nullTypes > 0) {
            $this->fail("$nullTypes overlapped slots have NULL type field (should be 'event', 'booking', or 'allocation')");
        }
    }

    /**
     * Test 3: Validate available slots have no scheduled items
     */
    public function testAvailableSlotsHaveNoScheduledItems(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        // Fetch schedule
        $schedule = $this->fetchScheduleForDateRange($this->testResourceId, $tomorrow, $tomorrow);

        // Fetch freetime
        $freetime = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slots = $freetime[$this->testResourceId] ?? [];

        // Filter available slots
        $availableSlots = array_filter($slots, function($slot) {
            return !isset($slot['overlap']) || $slot['overlap'] === false || $slot['overlap'] === 0;
        });

        echo "\n=== Available Slots Validation ===\n";
        echo "Total slots: " . count($slots) . "\n";
        echo "Available slots: " . count($availableSlots) . "\n";
        echo "Scheduled items: " . count($schedule) . "\n\n";

        $errors = [];

        foreach ($availableSlots as $slot) {
            $slotStart = new DateTime();
            $slotStart->setTimestamp((int)$slot['start'] / 1000);
            $slotStart->setTimezone($this->timezone);

            $slotEnd = new DateTime();
            $slotEnd->setTimestamp((int)$slot['end'] / 1000);
            $slotEnd->setTimezone($this->timezone);

            // Check if any scheduled item overlaps with this "available" slot
            foreach ($schedule as $item) {
                $itemFrom = new DateTime($item['from_']);
                $itemTo = new DateTime($item['to_']);

                if ($this->timeRangesOverlap($slotStart, $slotEnd, $itemFrom, $itemTo)) {
                    $errors[] = [
                        'slot' => $slot['when'],
                        'item_type' => $item['type'],
                        'item_id' => $item['id'],
                        'item_time' => "{$itemFrom->format('H:i')}-{$itemTo->format('H:i')}"
                    ];
                }
            }
        }

        if (!empty($errors)) {
            echo "✗ Found " . count($errors) . " available slots that have scheduled items:\n";
            foreach ($errors as $error) {
                echo "  - {$error['slot']}: has {$error['item_type']} #{$error['item_id']} ({$error['item_time']})\n";
            }
            $this->fail(count($errors) . " available slots incorrectly marked as available");
        } else {
            echo "✓ All available slots are correctly available (no scheduled items)\n";
        }
    }

    /**
     * Test 4: Validate query with building_id vs building_id+resource_id returns same results
     */
    public function testBuildingQueryVsResourceQueryReturnsSameResults(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        // Query 1: With building_id AND resource_id
        $responseWithResource = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        // Query 2: With building_id ONLY
        $responseWithoutResource = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        echo "\n=== Building Query vs Resource Query Validation ===\n";
        echo "Date: {$startDate}\n";

        $slotsWithResource = $responseWithResource[$this->testResourceId] ?? [];
        $slotsWithoutResource = $responseWithoutResource[$this->testResourceId] ?? [];

        $this->assertNotEmpty($slotsWithResource, "Query with resource_id should return slots");
        $this->assertNotEmpty($slotsWithoutResource, "Query with building_id only should return slots");

        echo "With resource_id: " . count($slotsWithResource) . " slots\n";
        echo "Without resource_id: " . count($slotsWithoutResource) . " slots\n";

        // Compare overlaps
        $overlapsWithResource = array_filter($slotsWithResource, function($s) {
            return isset($s['overlap']) && $s['overlap'] !== false && $s['overlap'] !== 0;
        });

        $overlapsWithoutResource = array_filter($slotsWithoutResource, function($s) {
            return isset($s['overlap']) && $s['overlap'] !== false && $s['overlap'] !== 0;
        });

        echo "Overlaps with resource_id: " . count($overlapsWithResource) . "\n";
        echo "Overlaps without resource_id: " . count($overlapsWithoutResource) . "\n\n";

        // Compare each overlapped slot
        $errors = [];

        foreach ($slotsWithResource as $index => $slotWith) {
            $slotWithout = $slotsWithoutResource[$index] ?? null;

            if (!$slotWithout) {
                $errors[] = "Slot {$slotWith['when']}: missing in building_id-only query";
                continue;
            }

            // Compare overlap status
            if ($slotWith['overlap'] !== $slotWithout['overlap']) {
                $errors[] = "Slot {$slotWith['when']}: overlap mismatch (with resource_id: {$slotWith['overlap']}, without: {$slotWithout['overlap']})";

                echo "MISMATCH at {$slotWith['when']}:\n";
                echo "  With resource_id:    overlap={$slotWith['overlap']}";
                if (isset($slotWith['overlap_event'])) {
                    echo " - {$slotWith['overlap_event']['type']} #{$slotWith['overlap_event']['id']}";
                }
                echo "\n";
                echo "  Without resource_id: overlap={$slotWithout['overlap']}";
                if (isset($slotWithout['overlap_event'])) {
                    echo " - {$slotWithout['overlap_event']['type']} #{$slotWithout['overlap_event']['id']}";
                }
                echo "\n";
            }

            // If both have overlaps, compare event IDs
            if (isset($slotWith['overlap_event']) && isset($slotWithout['overlap_event'])) {
                if ($slotWith['overlap_event']['id'] !== $slotWithout['overlap_event']['id']) {
                    $errors[] = "Slot {$slotWith['when']}: overlap_event ID mismatch";
                }
                if ($slotWith['overlap_event']['type'] !== $slotWithout['overlap_event']['type']) {
                    $errors[] = "Slot {$slotWith['when']}: overlap_event type mismatch";
                }
            }
        }

        if (!empty($errors)) {
            echo "✗ Found " . count($errors) . " differences:\n";
            foreach ($errors as $error) {
                echo "  - $error\n";
            }
            $this->fail("Query with building_id only returns different results than query with building_id+resource_id");
        } else {
            echo "✓ Both query methods return identical results\n";
        }
    }

    /**
     * Test 5: Validate booking horizon is enforced
     */
    public function testBookingHorizonIsEnforced(): void
    {
        $horizonDays = $this->resourceConfig['booking_day_horizon'];

        if ($horizonDays <= 0) {
            $this->markTestSkipped("Resource has no booking horizon configured");
            return;
        }

        echo "\n=== Booking Horizon Validation ===\n";
        echo "Configured horizon: $horizonDays days\n";

        // Test day before horizon limit
        $dayBeforeLimit = new DateTime("+{$horizonDays} days", $this->timezone);
        $freetimeWithin = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $dayBeforeLimit->format('d/m-Y'),
            'end_date' => $dayBeforeLimit->format('d/m-Y'),
        ]);

        $slotsWithin = $freetimeWithin[$this->testResourceId] ?? [];

        // Test day beyond horizon
        $dayBeyondLimit = new DateTime("+{$horizonDays} days +1 day", $this->timezone);
        $freetimeBeyond = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $dayBeyondLimit->format('d/m-Y'),
            'end_date' => $dayBeyondLimit->format('d/m-Y'),
        ]);

        $slotsBeyond = $freetimeBeyond[$this->testResourceId] ?? [];

        echo "Day +{$horizonDays} (at horizon): " . count($slotsWithin) . " slots\n";
        echo "Day +{$horizonDays}+1 (beyond horizon): " . count($slotsBeyond) . " slots\n";

        $this->assertGreaterThan(0, count($slotsWithin), "Should have slots within horizon");
        $this->assertEquals(0, count($slotsBeyond), "Should have NO slots beyond horizon");
    }

    /**
     * Test 5: Validate overlap event details are complete and accurate
     */
    public function testOverlapEventDetailsAreAccurate(): void
    {
        $startDate = new DateTime('now', $this->timezone);
        $endDate = new DateTime('+2 days', $this->timezone);

        $schedule = $this->fetchScheduleForDateRange($this->testResourceId, $startDate, $endDate);

        if (empty($schedule)) {
            $this->markTestSkipped("No scheduled items to validate");
            return;
        }

        $freetime = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate->format('d/m-Y'),
            'end_date' => $endDate->format('d/m-Y'),
            'detailed_overlap' => true,
        ]);

        $slots = $freetime[$this->testResourceId] ?? [];
        $overlappedSlots = array_filter($slots, function($slot) {
            return isset($slot['overlap']) && $slot['overlap'] === 1 && isset($slot['overlap_event']);
        });

        echo "\n=== Overlap Event Details Validation ===\n";

        $errors = [];

        foreach ($overlappedSlots as $slot) {
            $event = $slot['overlap_event'];
            $eventId = $event['id'] ?? null;

            // Find corresponding scheduled item
            $scheduledItem = null;
            foreach ($schedule as $item) {
                if ($item['id'] == $eventId) {
                    $scheduledItem = $item;
                    break;
                }
            }

            if (!$scheduledItem) {
                $errors[] = "Slot '{$slot['when']}' references unknown event ID: $eventId";
                continue;
            }

            echo "\nSlot: {$slot['when']}\n";
            echo "  Scheduled item: {$scheduledItem['type']} #{$scheduledItem['id']}\n";

            // Validate type
            if (!isset($event['type']) || $event['type'] === null) {
                echo "  ✗ overlap_event.type is NULL (should be '{$scheduledItem['type']}')\n";
                $errors[] = "Slot '{$slot['when']}': type is NULL";
            } elseif ($event['type'] !== $scheduledItem['type']) {
                echo "  ✗ overlap_event.type is '{$event['type']}' (should be '{$scheduledItem['type']}')\n";
                $errors[] = "Slot '{$slot['when']}': type mismatch";
            } else {
                echo "  ✓ Type matches: {$event['type']}\n";
            }

            // Validate time range
            $eventFrom = $event['from'] ?? null;
            $eventTo = $event['to'] ?? null;

            if ($eventFrom && $eventTo) {
                $scheduledFrom = (new DateTime($scheduledItem['from_']))->format('Y-m-d H:i:s');
                $scheduledTo = (new DateTime($scheduledItem['to_']))->format('Y-m-d H:i:s');

                if ($eventFrom === $scheduledFrom && $eventTo === $scheduledTo) {
                    echo "  ✓ Time range matches\n";
                } else {
                    echo "  ✗ Time range mismatch:\n";
                    echo "    API: $eventFrom - $eventTo\n";
                    echo "    Schedule: $scheduledFrom - $scheduledTo\n";
                    $errors[] = "Slot '{$slot['when']}': time range mismatch";
                }
            }
        }

        if (!empty($errors)) {
            $this->fail(count($errors) . " validation errors:\n" . implode("\n", $errors));
        }
    }

    // ===== Helper Methods =====

    private function callFreetimeEndpoint(array $params): array
    {
        $params['phpgw_return_as'] = 'json';
        $url = $this->baseUrl . '/bookingfrontend/?menuaction=bookingfrontend.uibooking.get_freetime&' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, "HTTP request should return 200");
        $data = json_decode($response, true);
        $this->assertNotNull($data, "Response should be valid JSON");

        return $data;
    }

    private function fetchResource(int $resourceId): ?array
    {
        $url = $this->baseUrl . "/bookingfrontend/resources/{$resourceId}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        return json_decode($response, true);
    }

    private function fetchScheduleForDateRange(int $resourceId, DateTime $startDate, DateTime $endDate): array
    {
        $schedule = [];
        $current = clone $startDate;

        while ($current <= $endDate) {
            $url = $this->baseUrl . "/bookingfrontend/resources/{$resourceId}/schedule?date=" . $current->format('Y-m-d');

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $daySchedule = json_decode($response, true);
                if (is_array($daySchedule)) {
                    $schedule = array_merge($schedule, $daySchedule);
                }
            }

            $current->modify('+1 day');
        }

        return $schedule;
    }

    private function findMatchingOverlaps(array $slots, DateTime $itemFrom, DateTime $itemTo, int $itemId): array
    {
        $matches = [];

        foreach ($slots as $slot) {
            if (!isset($slot['overlap_event']['id'])) {
                continue;
            }

            if ($slot['overlap_event']['id'] == $itemId) {
                $matches[] = $slot;
            }
        }

        return $matches;
    }

    private function timeRangesOverlap(DateTime $start1, DateTime $end1, DateTime $start2, DateTime $end2): bool
    {
        return $start1 < $end2 && $end1 > $start2;
    }
}
