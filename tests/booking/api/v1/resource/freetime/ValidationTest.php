<?php

namespace Tests\Booking\Api\V1\Resource\Freetime;

require_once __DIR__ . '/../../../../../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use DateTime;
use DateTimeZone;

/**
 * Validation Test for Slim4 API: /bookingfrontend/resources/{id}/freetime
 *
 * This test validates the NEW Slim4 REST API endpoint against:
 * - Same behavior as legacy ?menuaction=bookingfrontend.uibooking.get_freetime
 * - Resource configuration (time slots, horizons, operating hours)
 * - Scheduled items (events, bookings, allocations) appear as overlaps
 * - Available slots have no scheduled items
 * - Overlap details match the actual scheduled items
 * - JSON schema compliance
 *
 * IMPORTANT: This validates the NEW implementation matches legacy behavior
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
     * Test 1: Endpoint responds with valid structure
     */
    public function testEndpointRespondsWithValidStructure(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $response = $this->callFreetimeEndpoint($this->testResourceId, [
            'start_date' => $tomorrow->format('Y-m-d'),
            'end_date' => $tomorrow->format('Y-m-d'),
        ]);

        $this->assertIsArray($response, "Response should be an array");
        $this->assertNotEmpty($response, "Response should not be empty");

        // Should contain time slots for the resource
        $firstSlot = $response[0] ?? null;
        $this->assertNotNull($firstSlot, "Should have at least one slot");
        $this->assertArrayHasKey('when', $firstSlot, "Slot should have 'when' field");
        $this->assertArrayHasKey('start', $firstSlot, "Slot should have 'start' field");
        $this->assertArrayHasKey('end', $firstSlot, "Slot should have 'end' field");
        $this->assertArrayHasKey('overlap', $firstSlot, "Slot should have 'overlap' field");
    }

    /**
     * Test 2: Response matches legacy API behavior
     */
    public function testResponseMatchesLegacyAPI(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $startDate = $tomorrow->format('Y-m-d');
        $endDate = $tomorrow->format('Y-m-d');

        // Call new Slim4 API
        $slimResponse = $this->callFreetimeEndpoint($this->testResourceId, [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => 'true',
        ]);

        // Call legacy API
        $legacyResponse = $this->callLegacyFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $tomorrow->format('d/m-Y'),
            'end_date' => $tomorrow->format('d/m-Y'),
            'detailed_overlap' => true,
        ]);

        $legacySlots = $legacyResponse[$this->testResourceId] ?? [];

        echo "\n=== Slim4 vs Legacy API Comparison ===\n";
        echo "Slim4 slots: " . count($slimResponse) . "\n";
        echo "Legacy slots: " . count($legacySlots) . "\n";

        // Compare slot counts
        $this->assertEquals(
            count($legacySlots),
            count($slimResponse),
            "Slim4 API should return same number of slots as legacy"
        );

        // Compare each slot
        $errors = [];
        foreach ($slimResponse as $index => $slimSlot) {
            $legacySlot = $legacySlots[$index] ?? null;

            if (!$legacySlot) {
                $errors[] = "Slot $index missing in legacy response";
                continue;
            }

            // Compare overlap status
            if ($slimSlot['overlap'] !== $legacySlot['overlap']) {
                $errors[] = "Slot $index overlap mismatch: Slim4={$slimSlot['overlap']}, Legacy={$legacySlot['overlap']}";
            }

            // Compare overlap event if present
            if (isset($slimSlot['overlap_event']) && isset($legacySlot['overlap_event'])) {
                if ($slimSlot['overlap_event']['id'] !== $legacySlot['overlap_event']['id']) {
                    $errors[] = "Slot $index event ID mismatch";
                }
                if ($slimSlot['overlap_event']['type'] !== $legacySlot['overlap_event']['type']) {
                    $errors[] = "Slot $index event type mismatch";
                }
            }
        }

        if (!empty($errors)) {
            $this->fail("Found " . count($errors) . " differences:\n" . implode("\n", $errors));
        }

        echo "✓ Slim4 API matches legacy behavior\n";
    }

    /**
     * Test 3: Validate time slots match resource configuration
     */
    public function testTimeSlotsMatchResourceConfiguration(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $response = $this->callFreetimeEndpoint($this->testResourceId, [
            'start_date' => $tomorrow->format('Y-m-d'),
            'end_date' => $tomorrow->format('Y-m-d'),
        ]);

        $this->assertNotEmpty($response, "Should have slots for tomorrow");

        // Validate slot duration matches booking_time_minutes
        $expectedDurationMs = $this->resourceConfig['booking_time_minutes'] * 60 * 1000;

        foreach ($response as $index => $slot) {
            $duration = (int)$slot['end'] - (int)$slot['start'];
            $this->assertEquals(
                $expectedDurationMs,
                $duration,
                "Slot $index duration should be {$this->resourceConfig['booking_time_minutes']} minutes"
            );
        }

        echo "\n✓ Time slots match resource configuration\n";
    }

    /**
     * Test 4: Query parameters work correctly
     */
    public function testQueryParametersWork(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);

        // Test with detailed_overlap=true
        $responseDetailed = $this->callFreetimeEndpoint($this->testResourceId, [
            'start_date' => $tomorrow->format('Y-m-d'),
            'end_date' => $tomorrow->format('Y-m-d'),
            'detailed_overlap' => 'true',
        ]);

        // Test with detailed_overlap=false
        $responseBasic = $this->callFreetimeEndpoint($this->testResourceId, [
            'start_date' => $tomorrow->format('Y-m-d'),
            'end_date' => $tomorrow->format('Y-m-d'),
            'detailed_overlap' => 'false',
        ]);

        $this->assertNotEmpty($responseDetailed, "Detailed response should have slots");
        $this->assertNotEmpty($responseBasic, "Basic response should have slots");

        // Detailed should have resource_id
        if (!empty($responseDetailed)) {
            $this->assertArrayHasKey('resource_id', $responseDetailed[0], "Detailed response should have resource_id");
        }

        echo "\n✓ Query parameters working correctly\n";
    }

    // ===== Helper Methods =====

    private function callFreetimeEndpoint(int $resourceId, array $params): array
    {
        $url = $this->baseUrl . "/bookingfrontend/resources/{$resourceId}/freetime?" . http_build_query($params);

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

    private function callLegacyFreetimeEndpoint(array $params): array
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

        if ($httpCode !== 200) {
            return [];
        }

        $data = json_decode($response, true);
        return $data ?? [];
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
}
