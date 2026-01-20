<?php

namespace Tests\Booking\Api\V1\Building\Freetime;

require_once __DIR__ . '/../../../../../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use DateTime;
use DateTimeZone;

/**
 * Validation Test for Slim4 API: /bookingfrontend/building/{id}/freetime
 *
 * This test validates the NEW Slim4 REST API endpoint for building-level freetime queries.
 * It should return freetime data for ALL resources in the building.
 *
 * Validates against:
 * - Legacy ?menuaction=bookingfrontend.uibooking.get_freetime&building_id=X (without resource_id)
 * - Returns all resources in building
 * - Each resource has correct time slots
 * - Overlap detection works for all resources
 */
class ValidationTest extends TestCase
{
    private string $baseUrl;
    private int $testBuildingId;
    private int $testResourceId;  // For comparison
    private DateTimeZone $timezone;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('FREETIME_TEST_BASE_URL') ?: 'https://pe-api.test';
        $this->testBuildingId = (int)(getenv('FREETIME_TEST_BUILDING_ID') ?: 10);
        $this->testResourceId = (int)(getenv('FREETIME_TEST_RESOURCE_ID') ?: 106);
        $timezone = getenv('FREETIME_TEST_TIMEZONE') ?: 'Europe/Oslo';
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * Test 1: Endpoint responds with multiple resources
     */
    public function testEndpointRespondsWithMultipleResources(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $response = $this->callBuildingFreetimeEndpoint($this->testBuildingId, [
            'start_date' => $tomorrow->format('Y-m-d'),
            'end_date' => $tomorrow->format('Y-m-d'),
        ]);

        $this->assertIsArray($response, "Response should be an array/object");
        $this->assertNotEmpty($response, "Response should contain resources");

        // Should have resource IDs as keys (same as legacy)
        $keys = array_keys($response);
        $this->assertNotEmpty($keys, "Should have resource keys");

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^[0-9]+$/', (string)$key, "Keys should be resource IDs");
        }

        echo "\n✓ Building endpoint returns multiple resources\n";
        echo "  Resources found: " . implode(', ', $keys) . "\n";
    }

    /**
     * Test 2: Response matches legacy building-level query
     */
    public function testResponseMatchesLegacyBuildingQuery(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $startDate = $tomorrow->format('Y-m-d');
        $endDate = $tomorrow->format('Y-m-d');

        // Call new Slim4 building API
        $slimResponse = $this->callBuildingFreetimeEndpoint($this->testBuildingId, [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => 'true',
        ]);

        // Call legacy building-level API (without resource_id)
        $legacyResponse = $this->callLegacyFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'start_date' => $tomorrow->format('d/m-Y'),
            'end_date' => $tomorrow->format('d/m-Y'),
            'detailed_overlap' => true,
        ]);

        echo "\n=== Slim4 Building API vs Legacy Building Query ===\n";
        echo "Slim4 resources: " . count($slimResponse) . "\n";
        echo "Legacy resources: " . count($legacyResponse) . "\n";

        // Should have same resource IDs
        $slimResourceIds = array_keys($slimResponse);
        $legacyResourceIds = array_keys($legacyResponse);

        sort($slimResourceIds);
        sort($legacyResourceIds);

        $this->assertEquals(
            $legacyResourceIds,
            $slimResourceIds,
            "Should return same resource IDs as legacy"
        );

        // Compare each resource's slots
        foreach ($slimResourceIds as $resourceId) {
            $slimSlots = $slimResponse[$resourceId] ?? [];
            $legacySlots = $legacyResponse[$resourceId] ?? [];

            $this->assertEquals(
                count($legacySlots),
                count($slimSlots),
                "Resource $resourceId should have same slot count"
            );
        }

        echo "✓ Slim4 building API matches legacy behavior\n";
    }

    /**
     * Test 3: Resource-specific data matches between endpoints
     */
    public function testResourceDataMatchesBetweenEndpoints(): void
    {
        $tomorrow = new DateTime('+1 day', $this->timezone);
        $startDate = $tomorrow->format('Y-m-d');
        $endDate = $tomorrow->format('Y-m-d');

        // Get data from building endpoint
        $buildingResponse = $this->callBuildingFreetimeEndpoint($this->testBuildingId, [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => 'true',
        ]);

        // Get data from resource endpoint
        $resourceResponse = $this->callResourceFreetimeEndpoint($this->testResourceId, [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => 'true',
        ]);

        // Extract the test resource from building response
        $resourceFromBuilding = $buildingResponse[$this->testResourceId] ?? [];
        $this->assertNotEmpty($resourceFromBuilding, "Test resource should be in building response");

        echo "\n=== Resource Endpoint vs Building Endpoint ===\n";
        echo "Building endpoint (resource {$this->testResourceId}): " . count($resourceFromBuilding) . " slots\n";
        echo "Resource endpoint: " . count($resourceResponse) . " slots\n";

        // Should be identical
        $this->assertEquals(
            count($resourceFromBuilding),
            count($resourceResponse),
            "Same resource should have same slots from both endpoints"
        );

        // Compare each slot
        foreach ($resourceResponse as $index => $resourceSlot) {
            $buildingSlot = $resourceFromBuilding[$index] ?? null;
            $this->assertNotNull($buildingSlot, "Slot $index should exist in building response");

            $this->assertEquals(
                $resourceSlot['overlap'],
                $buildingSlot['overlap'],
                "Slot $index should have same overlap status"
            );
        }

        echo "✓ Resource data matches between endpoints\n";
    }

    // ===== Helper Methods =====

    private function callBuildingFreetimeEndpoint(int $buildingId, array $params): array
    {
        $url = $this->baseUrl . "/bookingfrontend/building/{$buildingId}/freetime?" . http_build_query($params);

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

    private function callResourceFreetimeEndpoint(int $resourceId, array $params): array
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

        return json_decode($response, true) ?? [];
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
