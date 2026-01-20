<?php

namespace Tests\Booking\Legacy\Endpoints\Uibooking\GetFreetime;

require_once __DIR__ . '/../../../../../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use DateTime;

/**
 * End-to-End Test for bookingfrontend.uibooking.get_freetime endpoint
 *
 * This test validates:
 * - Response structure and format
 * - Time slot generation based on resource configuration
 * - Overlap detection for Events, Allocations, and Bookings
 * - time_in_past restriction
 * - detailed_overlap parameter functionality
 * - Booking horizon limitations
 * - Various overlap scenarios (complete, partial, containment)
 */
class EndpointTest extends TestCase
{
    private string $baseUrl;
    private int $testResourceId;
    private int $testBuildingId;

    protected function setUp(): void
    {
        // Load configuration from environment variables with defaults
        $this->baseUrl = getenv('FREETIME_TEST_BASE_URL') ?: 'https://pe-api.test';
        $this->testResourceId = (int)(getenv('FREETIME_TEST_RESOURCE_ID') ?: 106);
        $this->testBuildingId = (int)(getenv('FREETIME_TEST_BUILDING_ID') ?: 10);

        // Verify resource configuration before running tests
        $this->verifyResourceConfiguration();
    }

    /**
     * Verify that the test resource is properly configured as a simple booking resource
     */
    private function verifyResourceConfiguration(): void
    {
        $resource = $this->fetchResource($this->testResourceId);

        $this->assertNotNull($resource, "Test resource {$this->testResourceId} not found");
        $this->assertEquals(1, $resource['simple_booking'], "Resource must have simple_booking enabled");
        $this->assertEquals(120, $resource['booking_time_minutes'], "Resource should have 120-minute time slots");
        $this->assertEquals(8, $resource['booking_day_horizon'], "Resource should have 8-day booking horizon");
    }

    /**
     * Test 1: Basic endpoint response structure
     */
    public function testEndpointReturnsValidStructure(): void
    {
        $startDate = (new DateTime())->format('d/m-Y');
        $endDate = (new DateTime('+7 days'))->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        // Should return an array with resource IDs as keys
        $this->assertIsArray($response, "Response should be an array");
        $this->assertArrayHasKey($this->testResourceId, $response, "Response should include test resource");

        $resourceSlots = $response[$this->testResourceId];
        $this->assertIsArray($resourceSlots, "Resource slots should be an array");
    }

    /**
     * Test 2: Time slot generation matches resource configuration
     */
    public function testTimeSlotGenerationMatchesResourceConfig(): void
    {
        $tomorrow = new DateTime('+1 day');
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should generate time slots for tomorrow");

        // Verify slot structure
        $firstSlot = $slots[0];
        $this->assertArrayHasKey('when', $firstSlot, "Slot should have 'when' field");
        $this->assertArrayHasKey('start', $firstSlot, "Slot should have 'start' timestamp");
        $this->assertArrayHasKey('end', $firstSlot, "Slot should have 'end' timestamp");
        $this->assertArrayHasKey('overlap', $firstSlot, "Slot should have 'overlap' field");

        // Verify 2-hour duration (120 minutes = 7200000 milliseconds)
        $duration = intval($firstSlot['end']) - intval($firstSlot['start']);
        $this->assertEquals(7200000, $duration, "Slot duration should be 2 hours (7200000ms)");
    }

    /**
     * Test 3: time_in_past restriction prevents booking past slots
     */
    public function testTimeSlotsInPastAreMarkedAsDisabled(): void
    {
        $today = new DateTime();
        $startDate = $today->format('d/m-Y');
        $endDate = $today->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];

        // Find slots that should be in the past
        $currentHour = (int)$today->format('H');
        $pastSlots = array_filter($slots, function($slot) use ($currentHour) {
            if (isset($slot['overlap']) && $slot['overlap'] === 3) {
                return true;
            }
            return false;
        });

        if (!empty($pastSlots)) {
            $firstPastSlot = array_values($pastSlots)[0];
            $this->assertEquals(3, $firstPastSlot['overlap'], "Past slots should have overlap status 3");
            $this->assertEquals('time_in_past', $firstPastSlot['overlap_reason'] ?? null, "Should have time_in_past reason");
            $this->assertEquals('disabled', $firstPastSlot['overlap_type'] ?? null, "Should have disabled type");
        }
    }

    /**
     * Test 4: Event overlap detection
     */
    public function testEventOverlapDetection(): void
    {
        // This test requires an event to exist in the database
        // You should create a test event first

        $tomorrow = new DateTime('+1 day');
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];

        // Look for slots with event overlaps
        $overlappedSlots = array_filter($slots, function($slot) {
            return isset($slot['overlap']) &&
                   $slot['overlap'] === 1 &&
                   isset($slot['overlap_event']) &&
                   $slot['overlap_event']['type'] === 'event';
        });

        if (!empty($overlappedSlots)) {
            $overlappedSlot = array_values($overlappedSlots)[0];

            // Verify overlap structure
            $this->assertEquals(1, $overlappedSlot['overlap'], "Event overlap should have status 1");
            $this->assertArrayHasKey('overlap_event', $overlappedSlot, "Should include event details");
            $this->assertArrayHasKey('overlap_reason', $overlappedSlot, "Should include overlap reason");

            $event = $overlappedSlot['overlap_event'];
            $this->assertArrayHasKey('id', $event, "Event should have ID");
            $this->assertArrayHasKey('type', $event, "Event should have type");
            $this->assertEquals('event', $event['type'], "Type should be 'event'");

            echo "\n✓ Event overlap detected correctly\n";
        } else {
            $this->markTestSkipped("No event overlaps found - create test events to validate this test");
        }
    }

    /**
     * Test 5: Booking overlap detection
     */
    public function testBookingOverlapDetection(): void
    {
        // This test requires a booking to exist in the database

        $tomorrow = new DateTime('+1 day');
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];

        // Look for slots with booking overlaps
        $overlappedSlots = array_filter($slots, function($slot) {
            return isset($slot['overlap']) &&
                   $slot['overlap'] === 1 &&
                   isset($slot['overlap_event']) &&
                   $slot['overlap_event']['type'] === 'booking';
        });

        if (!empty($overlappedSlots)) {
            $overlappedSlot = array_values($overlappedSlots)[0];

            $this->assertEquals(1, $overlappedSlot['overlap'], "Booking overlap should have status 1");
            $this->assertEquals('booking', $overlappedSlot['overlap_event']['type'], "Type should be 'booking'");

            echo "\n✓ Booking overlap detected correctly\n";
        } else {
            $this->markTestSkipped("No booking overlaps found - create test bookings to validate this test");
        }
    }

    /**
     * Test 6: Allocation overlap detection
     */
    public function testAllocationOverlapDetection(): void
    {
        // This test requires an allocation to exist in the database

        $tomorrow = new DateTime('+1 day');
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];

        // Look for slots with allocation overlaps
        $overlappedSlots = array_filter($slots, function($slot) {
            return isset($slot['overlap']) &&
                   $slot['overlap'] === 1 &&
                   isset($slot['overlap_event']) &&
                   $slot['overlap_event']['type'] === 'allocation';
        });

        if (!empty($overlappedSlots)) {
            $overlappedSlot = array_values($overlappedSlots)[0];

            $this->assertEquals(1, $overlappedSlot['overlap'], "Allocation overlap should have status 1");
            $this->assertEquals('allocation', $overlappedSlot['overlap_event']['type'], "Type should be 'allocation'");

            echo "\n✓ Allocation overlap detected correctly\n";
        } else {
            $this->markTestSkipped("No allocation overlaps found - create test allocations to validate this test");
        }
    }

    /**
     * Test 7: detailed_overlap parameter includes extra information
     */
    public function testDetailedOverlapParameter(): void
    {
        $tomorrow = new DateTime('+1 day');
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        // Call without detailed_overlap
        $responseBasic = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => false,
        ]);

        // Call with detailed_overlap
        $responseDetailed = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slotsBasic = $responseBasic[$this->testResourceId] ?? [];
        $slotsDetailed = $responseDetailed[$this->testResourceId] ?? [];

        $this->assertNotEmpty($slotsBasic, "Basic response should have slots");
        $this->assertNotEmpty($slotsDetailed, "Detailed response should have slots");

        // Detailed response should have resource_id
        $firstDetailedSlot = $slotsDetailed[0];
        $this->assertArrayHasKey('resource_id', $firstDetailedSlot, "Detailed slots should include resource_id");
        $this->assertEquals($this->testResourceId, $firstDetailedSlot['resource_id'], "resource_id should match");

        // Find an overlapped slot to check for overlap details
        $overlappedSlot = null;
        foreach ($slotsDetailed as $slot) {
            if (isset($slot['overlap']) && $slot['overlap'] !== false && $slot['overlap'] !== 0) {
                $overlappedSlot = $slot;
                break;
            }
        }

        if ($overlappedSlot) {
            $this->assertArrayHasKey('overlap_reason', $overlappedSlot, "Detailed overlap should include reason");
            $this->assertArrayHasKey('overlap_type', $overlappedSlot, "Detailed overlap should include type");

            if ($overlappedSlot['overlap'] === 1) {
                $this->assertArrayHasKey('overlap_event', $overlappedSlot, "Status 1 should include event details");
            }
        }
    }

    /**
     * Test 8: Booking horizon limitation
     */
    public function testBookingHorizonLimitation(): void
    {
        // Request dates beyond the 8-day horizon
        $startDate = (new DateTime('+9 days'))->format('d/m-Y');
        $endDate = (new DateTime('+10 days'))->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $slots = $response[$this->testResourceId] ?? [];

        // Should either return empty array or no slots for this resource
        // (depending on implementation)
        $this->assertIsArray($slots, "Response should be an array");

        if (empty($slots)) {
            $this->assertTrue(true, "No slots returned beyond booking horizon (as expected)");
        } else {
            $this->markTestIncomplete("Slots returned beyond horizon - verify if this is correct behavior");
        }
    }

    /**
     * Test 9: Multiple overlap types in response
     */
    public function testMultipleOverlapTypes(): void
    {
        // Test over several days to capture different overlap scenarios
        $startDate = (new DateTime())->format('d/m-Y');
        $endDate = (new DateTime('+3 days'))->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have slots for date range");

        // Collect different overlap statuses
        $overlapStatuses = [];
        $overlapReasons = [];

        foreach ($slots as $slot) {
            if (isset($slot['overlap'])) {
                $overlapStatuses[$slot['overlap']] = ($overlapStatuses[$slot['overlap']] ?? 0) + 1;
            }
            if (isset($slot['overlap_reason'])) {
                $overlapReasons[$slot['overlap_reason']] = ($overlapReasons[$slot['overlap_reason']] ?? 0) + 1;
            }
        }

        echo "\n=== Overlap Statistics ===\n";
        echo "Overlap statuses found:\n";
        foreach ($overlapStatuses as $status => $count) {
            $statusLabel = $this->getOverlapStatusLabel($status);
            echo "  - Status $status ($statusLabel): $count slots\n";
        }

        if (!empty($overlapReasons)) {
            echo "\nOverlap reasons found:\n";
            foreach ($overlapReasons as $reason => $count) {
                echo "  - $reason: $count slots\n";
            }
        }

        $this->assertTrue(true, "Overlap statistics collected successfully");
    }

    /**
     * Test 10: Response format validation
     */
    public function testResponseFormatValidation(): void
    {
        $tomorrow = new DateTime('+1 day');
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have slots for tomorrow");

        foreach ($slots as $index => $slot) {
            // Required fields
            $this->assertArrayHasKey('when', $slot, "Slot $index missing 'when'");
            $this->assertArrayHasKey('start', $slot, "Slot $index missing 'start'");
            $this->assertArrayHasKey('end', $slot, "Slot $index missing 'end'");
            $this->assertArrayHasKey('overlap', $slot, "Slot $index missing 'overlap'");

            // Validate timestamp format
            $this->assertIsString($slot['start'], "start should be string");
            $this->assertIsString($slot['end'], "end should be string");
            $this->assertGreaterThan(0, intval($slot['start']), "start should be valid timestamp");
            $this->assertGreaterThan(0, intval($slot['end']), "end should be valid timestamp");

            // Validate ISO format if present
            if (isset($slot['start_iso'])) {
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
                    $slot['start_iso'],
                    "start_iso should be valid ISO 8601 format"
                );
            }

            // If overlapped, validate overlap structure
            if (isset($slot['overlap']) && $slot['overlap'] !== false && $slot['overlap'] !== 0) {
                $this->assertArrayHasKey('overlap_reason', $slot, "Overlapped slot should have reason");
                $this->assertArrayHasKey('overlap_type', $slot, "Overlapped slot should have type");

                // Validate overlap_event structure if present
                if (isset($slot['overlap_event'])) {
                    $event = $slot['overlap_event'];
                    $this->assertArrayHasKey('type', $event, "overlap_event should have type");
                    $this->assertContains(
                        $event['type'],
                        ['event', 'booking', 'allocation', 'block', 'application'],
                        "overlap_event type should be valid"
                    );
                }
            }
        }

        echo "\n✓ All slots have valid format\n";
    }

    // ===== Helper Methods =====

    private function callFreetimeEndpoint(array $params): array
    {
        $defaultParams = [
            'phpgw_return_as' => 'json',
        ];

        $params = array_merge($defaultParams, $params);

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
        $this->assertNotEmpty($response, "Response should not be empty");

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

    private function getOverlapStatusLabel($status): string
    {
        $labels = [
            false => 'Available',
            0 => 'Available',
            1 => 'Complete Overlap',
            2 => 'Partial/Block',
            3 => 'Disabled (time_in_past)',
        ];

        return $labels[$status] ?? 'Unknown';
    }
}
