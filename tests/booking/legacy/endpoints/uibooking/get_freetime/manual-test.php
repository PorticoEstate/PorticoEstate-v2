#!/usr/bin/env php
<?php
/**
 * Manual Test Script for get_freetime endpoint
 *
 * Run this script to quickly test the freetime endpoint without PHPUnit
 * Usage: php test_freetime_manual.php
 */

// Configuration - can be overridden via environment variables
define('BASE_URL', getenv('FREETIME_TEST_BASE_URL') ?: 'https://pe-api.test');
define('RESOURCE_ID', (int)(getenv('FREETIME_TEST_RESOURCE_ID') ?: 106));
define('BUILDING_ID', (int)(getenv('FREETIME_TEST_BUILDING_ID') ?: 10));

class ManualTest
{
    private $baseUrl;
    private $resourceId;
    private $buildingId;
    private $testsPassed = 0;
    private $testsFailed = 0;
    private $testsSkipped = 0;

    public function __construct(string $baseUrl, int $resourceId, int $buildingId)
    {
        $this->baseUrl = $baseUrl;
        $this->resourceId = $resourceId;
        $this->buildingId = $buildingId;
    }

    public function runAllTests(): void
    {
        echo "\n========================================\n";
        echo "   FREETIME ENDPOINT MANUAL TEST\n";
        echo "========================================\n";
        echo "Base URL: {$this->baseUrl}\n";
        echo "Resource ID: {$this->resourceId}\n";
        echo "Building ID: {$this->buildingId}\n";
        echo "========================================\n\n";

        $this->testBasicEndpoint();
        $this->testTimeSlotGeneration();
        $this->testTimePastRestriction();
        $this->testDetailedOverlapParameter();
        $this->testBuildingVsResourceQuery();
        $this->testOverlapDetection();
        $this->testResponseStructure();
        $this->testBookingHorizon();

        $this->printSummary();
    }

    private function testBasicEndpoint(): void
    {
        echo "TEST 1: Basic Endpoint Response\n";
        echo str_repeat("-", 40) . "\n";

        $startDate = date('d/m-Y');
        $endDate = date('d/m-Y', strtotime('+7 days'));

        $response = $this->callFreetime([
            'building_id' => $this->buildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        if (!is_array($response)) {
            $this->fail("Response should be an array");
            return;
        }

        if (!isset($response[$this->resourceId])) {
            $this->fail("Response should include resource {$this->resourceId}");
            return;
        }

        if (!is_array($response[$this->resourceId])) {
            $this->fail("Resource slots should be an array");
            return;
        }

        $slotCount = count($response[$this->resourceId]);
        $this->pass("Response structure is valid ($slotCount slots returned)");
    }

    private function testTimeSlotGeneration(): void
    {
        echo "\nTEST 2: Time Slot Generation\n";
        echo str_repeat("-", 40) . "\n";

        // Fetch resource configuration
        $resourceConfig = $this->fetchResourceConfig($this->resourceId);

        if (!$resourceConfig) {
            $this->fail("Could not fetch resource configuration");
            return;
        }

        $tomorrow = date('d/m-Y', strtotime('+1 day'));

        $response = $this->callFreetime([
            'building_id' => $this->buildingId,
            'start_date' => $tomorrow,
            'end_date' => $tomorrow,
        ]);

        $slots = $response[$this->resourceId] ?? [];

        if (empty($slots)) {
            $this->fail("No slots generated for tomorrow");
            return;
        }

        $firstSlot = $slots[0];

        // Check required fields
        $requiredFields = ['when', 'start', 'end', 'overlap'];
        foreach ($requiredFields as $field) {
            if (!isset($firstSlot[$field])) {
                $this->fail("Missing required field: $field");
                return;
            }
        }

        // Calculate actual duration
        $actualDuration = (int)$firstSlot['end'] - (int)$firstSlot['start'];
        $actualDurationHours = $actualDuration / 3600000;

        // Get expected duration from resource config
        $expectedMinutes = $resourceConfig['booking_time_minutes'] ?? -1;

        if ($expectedMinutes > 0) {
            $expectedDuration = $expectedMinutes * 60 * 1000; // Convert to milliseconds

            if ($actualDuration !== $expectedDuration) {
                $this->fail("Duration mismatch: expected {$expectedMinutes} minutes, got " . ($actualDuration / 60000) . " minutes");
                return;
            }

            $this->pass("Time slots generated correctly (" . count($slots) . " slots, {$expectedMinutes}-minute duration)");
        } else {
            // Variable slot duration - just validate structure
            echo "  Note: Resource has variable slot duration (booking_time_minutes = $expectedMinutes)\n";
            echo "  Actual slot duration: " . round($actualDurationHours, 2) . " hours\n";
            $this->pass("Time slots generated (" . count($slots) . " slots, variable duration)");
        }
    }

    private function testTimePastRestriction(): void
    {
        echo "\nTEST 3: Time in Past Restriction\n";
        echo str_repeat("-", 40) . "\n";

        $today = date('d/m-Y');

        $response = $this->callFreetime([
            'building_id' => $this->buildingId,
            'start_date' => $today,
            'end_date' => $today,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->resourceId] ?? [];

        $pastSlots = array_filter($slots, function($slot) {
            return isset($slot['overlap']) && $slot['overlap'] === 3;
        });

        if (empty($pastSlots)) {
            $this->skip("No past slots found (might be early in the day)");
            return;
        }

        $pastSlot = array_values($pastSlots)[0];

        if (!isset($pastSlot['overlap_reason']) || $pastSlot['overlap_reason'] !== 'time_in_past') {
            $this->fail("Past slots should have 'time_in_past' reason");
            return;
        }

        if (!isset($pastSlot['overlap_type']) || $pastSlot['overlap_type'] !== 'disabled') {
            $this->fail("Past slots should have 'disabled' type");
            return;
        }

        $this->pass("Past time slots correctly marked as disabled (" . count($pastSlots) . " past slots found)");
    }

    private function testDetailedOverlapParameter(): void
    {
        echo "\nTEST 4: Detailed Overlap Parameter\n";
        echo str_repeat("-", 40) . "\n";

        $tomorrow = date('d/m-Y', strtotime('+1 day'));

        // Without detailed_overlap
        $responseBasic = $this->callFreetime([
            'building_id' => $this->buildingId,
            'start_date' => $tomorrow,
            'end_date' => $tomorrow,
            'detailed_overlap' => false,
        ]);

        // With detailed_overlap
        $responseDetailed = $this->callFreetime([
            'building_id' => $this->buildingId,
            'start_date' => $tomorrow,
            'end_date' => $tomorrow,
            'detailed_overlap' => true,
        ]);

        $slotsBasic = $responseBasic[$this->resourceId] ?? [];
        $slotsDetailed = $responseDetailed[$this->resourceId] ?? [];

        if (empty($slotsDetailed)) {
            $this->fail("No slots returned with detailed_overlap");
            return;
        }

        $firstDetailed = $slotsDetailed[0];

        if (!isset($firstDetailed['resource_id'])) {
            $this->fail("Detailed response should include resource_id");
            return;
        }

        if ($firstDetailed['resource_id'] != $this->resourceId) {
            $this->fail("resource_id mismatch");
            return;
        }

        $this->pass("detailed_overlap parameter working correctly");
    }

    private function testBuildingVsResourceQuery(): void
    {
        echo "\nTEST 5: Building vs Resource Query Consistency\n";
        echo str_repeat("-", 40) . "\n";

        $tomorrow = date('d/m-Y', strtotime('+1 day'));

        // Query with building_id AND resource_id
        $responseWithResource = $this->callFreetime([
            'building_id' => $this->buildingId,
            'resource_id' => $this->resourceId,
            'start_date' => $tomorrow,
            'end_date' => $tomorrow,
            'detailed_overlap' => true,
        ]);

        // Query with building_id ONLY
        $responseWithoutResource = $this->callFreetime([
            'building_id' => $this->buildingId,
            'start_date' => $tomorrow,
            'end_date' => $tomorrow,
            'detailed_overlap' => true,
        ]);

        $slotsWithResource = $responseWithResource[$this->resourceId] ?? [];
        $slotsWithoutResource = $responseWithoutResource[$this->resourceId] ?? [];

        if (empty($slotsWithResource) || empty($slotsWithoutResource)) {
            $this->fail("One or both queries returned no slots");
            return;
        }

        echo "  With resource_id: " . count($slotsWithResource) . " slots\n";
        echo "  Without resource_id: " . count($slotsWithoutResource) . " slots\n";

        // Compare overlap counts
        $overlapsWithResource = count(array_filter($slotsWithResource, function($s) {
            return isset($s['overlap']) && $s['overlap'] !== false && $s['overlap'] !== 0;
        }));

        $overlapsWithoutResource = count(array_filter($slotsWithoutResource, function($s) {
            return isset($s['overlap']) && $s['overlap'] !== false && $s['overlap'] !== 0;
        }));

        echo "  Overlaps with resource_id: $overlapsWithResource\n";
        echo "  Overlaps without resource_id: $overlapsWithoutResource\n";

        // Check for mismatches
        $mismatches = 0;
        foreach ($slotsWithResource as $index => $slotWith) {
            $slotWithout = $slotsWithoutResource[$index] ?? null;

            if (!$slotWithout) {
                continue;
            }

            if ($slotWith['overlap'] !== $slotWithout['overlap']) {
                $mismatches++;
                echo "  MISMATCH at {$slotWith['when']}: ";
                echo "with={$slotWith['overlap']}, without={$slotWithout['overlap']}\n";
            }
        }

        if ($mismatches > 0) {
            $this->fail("Found $mismatches mismatches between query methods");
        } else {
            $this->pass("Both query methods return identical results");
        }
    }

    private function testOverlapDetection(): void
    {
        echo "\nTEST 6: Overlap Detection\n";
        echo str_repeat("-", 40) . "\n";

        $startDate = date('d/m-Y');
        $endDate = date('d/m-Y', strtotime('+3 days'));

        $response = $this->callFreetime([
            'building_id' => $this->buildingId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->resourceId] ?? [];

        // Collect overlap statistics
        $overlapTypes = [];
        $overlapReasons = [];
        $eventTypes = [];

        foreach ($slots as $slot) {
            if (isset($slot['overlap']) && $slot['overlap'] !== false && $slot['overlap'] !== 0) {
                $overlapTypes[$slot['overlap']] = ($overlapTypes[$slot['overlap']] ?? 0) + 1;

                if (isset($slot['overlap_reason'])) {
                    $overlapReasons[$slot['overlap_reason']] = ($overlapReasons[$slot['overlap_reason']] ?? 0) + 1;
                }

                if (isset($slot['overlap_event']['type'])) {
                    $type = $slot['overlap_event']['type'];
                    $eventTypes[$type] = ($eventTypes[$type] ?? 0) + 1;
                }
            }
        }

        echo "  Overlap Statistics:\n";

        if (!empty($overlapTypes)) {
            echo "  - Overlap Statuses:\n";
            foreach ($overlapTypes as $type => $count) {
                $label = $this->getOverlapLabel($type);
                echo "    * Status $type ($label): $count slots\n";
            }
        }

        if (!empty($overlapReasons)) {
            echo "  - Overlap Reasons:\n";
            foreach ($overlapReasons as $reason => $count) {
                echo "    * $reason: $count slots\n";
            }
        }

        if (!empty($eventTypes)) {
            echo "  - Event Types Detected:\n";
            foreach ($eventTypes as $type => $count) {
                echo "    * $type: $count occurrences\n";
            }

            // Check if all three main types are detected
            $mainTypes = ['event', 'booking', 'allocation'];
            $detectedMainTypes = array_intersect($mainTypes, array_keys($eventTypes));

            if (count($detectedMainTypes) === 3) {
                $this->pass("All overlap types (events, bookings, allocations) detected ✓");
            } elseif (!empty($detectedMainTypes)) {
                $this->pass("Overlap detection working (detected: " . implode(', ', $detectedMainTypes) . ")");
            } else {
                $this->skip("No overlaps found (create test data to validate)");
            }
        } else {
            $this->skip("No overlaps detected (create test events/bookings/allocations)");
        }
    }

    private function testResponseStructure(): void
    {
        echo "\nTEST 7: Response Structure Validation\n";
        echo str_repeat("-", 40) . "\n";

        $tomorrow = date('d/m-Y', strtotime('+1 day'));

        $response = $this->callFreetime([
            'building_id' => $this->buildingId,
            'start_date' => $tomorrow,
            'end_date' => $tomorrow,
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->resourceId] ?? [];

        if (empty($slots)) {
            $this->fail("No slots to validate");
            return;
        }

        $errors = [];

        foreach ($slots as $index => $slot) {
            // Required fields
            if (!isset($slot['when'])) $errors[] = "Slot $index missing 'when'";
            if (!isset($slot['start'])) $errors[] = "Slot $index missing 'start'";
            if (!isset($slot['end'])) $errors[] = "Slot $index missing 'end'";
            if (!isset($slot['overlap'])) $errors[] = "Slot $index missing 'overlap'";

            // Validate timestamps
            if (isset($slot['start']) && (int)$slot['start'] <= 0) {
                $errors[] = "Slot $index has invalid start timestamp";
            }
            if (isset($slot['end']) && (int)$slot['end'] <= 0) {
                $errors[] = "Slot $index has invalid end timestamp";
            }

            // Validate overlap structure if present
            if (isset($slot['overlap']) && $slot['overlap'] !== false && $slot['overlap'] !== 0) {
                if (!isset($slot['overlap_reason'])) {
                    $errors[] = "Slot $index has overlap but no overlap_reason";
                }
                if (!isset($slot['overlap_type'])) {
                    $errors[] = "Slot $index has overlap but no overlap_type";
                }
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo "  ✗ $error\n";
            }
            $this->fail(count($errors) . " validation errors found");
        } else {
            $this->pass("All " . count($slots) . " slots have valid structure");
        }
    }

    private function testBookingHorizon(): void
    {
        echo "\nTEST 8: Booking Horizon Limitation\n";
        echo str_repeat("-", 40) . "\n";

        // Fetch resource configuration
        $resourceConfig = $this->fetchResourceConfig($this->resourceId);

        if (!$resourceConfig) {
            $this->fail("Could not fetch resource configuration");
            return;
        }

        $dayHorizon = $resourceConfig['booking_day_horizon'] ?? 0;
        $monthHorizon = $resourceConfig['booking_month_horizon'] ?? 0;

        echo "  Configured horizon: ";
        if ($dayHorizon > 0) {
            echo "{$dayHorizon} days";
        } elseif ($monthHorizon > 0) {
            echo "{$monthHorizon} months";
        } else {
            echo "none (unlimited)";
        }
        echo "\n";

        if ($dayHorizon <= 0 && $monthHorizon <= 0) {
            $this->skip("Resource has no booking horizon configured");
            return;
        }

        // Test within and beyond horizon based on configuration
        if ($dayHorizon > 0) {
            // Day-based horizon
            $dayWithin = date('d/m-Y', strtotime("+{$dayHorizon} days"));
            $dayBeyond = date('d/m-Y', strtotime("+{$dayHorizon} days +1 day"));

            $responseWithin = $this->callFreetime([
                'building_id' => $this->buildingId,
                'start_date' => $dayWithin,
                'end_date' => $dayWithin,
            ]);

            $responseBeyond = $this->callFreetime([
                'building_id' => $this->buildingId,
                'start_date' => $dayBeyond,
                'end_date' => $dayBeyond,
            ]);

            $slotsWithin = $responseWithin[$this->resourceId] ?? [];
            $slotsBeyond = $responseBeyond[$this->resourceId] ?? [];

            echo "  Day +{$dayHorizon} (at horizon): " . count($slotsWithin) . " slots\n";
            echo "  Day +{$dayHorizon}+1 (beyond horizon): " . count($slotsBeyond) . " slots\n";

            if (count($slotsWithin) > 0 && count($slotsBeyond) === 0) {
                $this->pass("Booking horizon correctly limits availability");
            } elseif (count($slotsBeyond) > 0) {
                $this->fail("Slots available beyond {$dayHorizon}-day horizon (should be blocked)");
            } else {
                $this->skip("Could not verify horizon (no slots in either range)");
            }
        } else {
            // Month-based horizon
            $monthWithin = date('d/m-Y', strtotime("+{$monthHorizon} months"));
            $monthBeyond = date('d/m-Y', strtotime("+{$monthHorizon} months +1 day"));

            $responseWithin = $this->callFreetime([
                'building_id' => $this->buildingId,
                'start_date' => $monthWithin,
                'end_date' => $monthWithin,
            ]);

            $responseBeyond = $this->callFreetime([
                'building_id' => $this->buildingId,
                'start_date' => $monthBeyond,
                'end_date' => $monthBeyond,
            ]);

            $slotsWithin = $responseWithin[$this->resourceId] ?? [];
            $slotsBeyond = $responseBeyond[$this->resourceId] ?? [];

            echo "  Month +{$monthHorizon} (at horizon): " . count($slotsWithin) . " slots\n";
            echo "  Month +{$monthHorizon}+1 (beyond horizon): " . count($slotsBeyond) . " slots\n";

            if (count($slotsWithin) > 0 && count($slotsBeyond) === 0) {
                $this->pass("Booking horizon correctly limits availability");
            } elseif (count($slotsBeyond) > 0) {
                $this->fail("Slots available beyond {$monthHorizon}-month horizon (should be blocked)");
            } else {
                $this->skip("Could not verify horizon (no slots in either range)");
            }
        }
    }

    // ===== Helper Methods =====

    private function callFreetime(array $params): array
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
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo "  ✗ HTTP Error: $httpCode\n";
            if ($error) echo "  ✗ cURL Error: $error\n";
            return [];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "  ✗ JSON Error: " . json_last_error_msg() . "\n";
            return [];
        }

        return $data ?? [];
    }

    private function fetchResourceConfig(int $resourceId): ?array
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

    private function getOverlapLabel($status): string
    {
        $labels = [
            0 => 'Available',
            1 => 'Complete Overlap',
            2 => 'Partial/Block',
            3 => 'Disabled (past)',
        ];

        return $labels[$status] ?? 'Unknown';
    }

    private function pass(string $message): void
    {
        echo "  ✓ PASS: $message\n";
        $this->testsPassed++;
    }

    private function fail(string $message): void
    {
        echo "  ✗ FAIL: $message\n";
        $this->testsFailed++;
    }

    private function skip(string $message): void
    {
        echo "  ⊘ SKIP: $message\n";
        $this->testsSkipped++;
    }

    private function printSummary(): void
    {
        $total = $this->testsPassed + $this->testsFailed + $this->testsSkipped;

        echo "\n========================================\n";
        echo "   TEST SUMMARY\n";
        echo "========================================\n";
        echo "Total Tests: $total\n";
        echo "✓ Passed: {$this->testsPassed}\n";
        echo "✗ Failed: {$this->testsFailed}\n";
        echo "⊘ Skipped: {$this->testsSkipped}\n";
        echo "========================================\n";

        if ($this->testsFailed === 0) {
            echo "\n🎉 All tests passed!\n\n";
            exit(0);
        } else {
            echo "\n❌ Some tests failed. Please review.\n\n";
            exit(1);
        }
    }
}

// Run tests
$test = new ManualTest(BASE_URL, RESOURCE_ID, BUILDING_ID);
$test->runAllTests();
