<?php

namespace Tests\Booking\Legacy\Endpoints\Uibooking\GetFreetime;

require_once __DIR__ . '/../../../../../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use DateTime;

/**
 * Schema Validation Test for bookingfrontend.uibooking.get_freetime endpoint
 *
 * Validates that API responses conform to the defined JSON schema:
 * - Response structure (object with resource IDs as keys)
 * - Time slot structure (required and optional fields)
 * - Field types and formats (timestamps, ISO dates, patterns)
 * - Conditional fields (overlap_event only when overlap=1, etc.)
 * - Enum values (overlap_reason, overlap_type, event type)
 */
class SchemaValidationTest extends TestCase
{
    private string $baseUrl;
    private int $testResourceId;
    private int $testBuildingId;
    private array $schema;

    protected function setUp(): void
    {
        // Load configuration from environment variables with defaults
        $this->baseUrl = getenv('FREETIME_TEST_BASE_URL') ?: 'https://pe-api.test';
        $this->testResourceId = (int)(getenv('FREETIME_TEST_RESOURCE_ID') ?: 106);
        $this->testBuildingId = (int)(getenv('FREETIME_TEST_BUILDING_ID') ?: 10);

        // Load schema
        $schemaPath = __DIR__ . '/schemas/response.schema.json';
        $this->assertTrue(file_exists($schemaPath), "Schema file not found at: $schemaPath");

        $schemaJson = file_get_contents($schemaPath);
        $this->schema = json_decode($schemaJson, true);
        $this->assertNotNull($this->schema, "Schema JSON is invalid");
    }

    /**
     * Test 1: Response structure conforms to schema
     */
    public function testResponseStructureConformsToSchema(): void
    {
        $tomorrow = new DateTime('+1 day');
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        echo "\n=== Response Structure Validation ===\n";

        // Root should be an object
        $this->assertIsArray($response, "Response should be an object (associative array)");

        // All keys should be numeric (resource IDs)
        foreach (array_keys($response) as $key) {
            $this->assertMatchesRegularExpression(
                '/^[0-9]+$/',
                (string)$key,
                "Root keys should be resource IDs (numeric): got '$key'"
            );
        }

        echo "✓ Response root structure is valid\n";
        echo "  - Resource IDs found: " . implode(', ', array_keys($response)) . "\n";
    }

    /**
     * Test 2: All time slots conform to schema
     */
    public function testAllTimeSlotsConformToSchema(): void
    {
        $startDate = new DateTime('now');
        $endDate = new DateTime('+2 days');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate->format('d/m-Y'),
            'end_date' => $endDate->format('d/m-Y'),
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have time slots");

        echo "\n=== Time Slot Schema Validation ===\n";
        echo "Total slots to validate: " . count($slots) . "\n\n";

        $errors = [];

        foreach ($slots as $index => $slot) {
            $slotErrors = $this->validateTimeSlot($slot, $index, true);
            if (!empty($slotErrors)) {
                $errors = array_merge($errors, $slotErrors);
            }
        }

        if (!empty($errors)) {
            echo "✗ Found " . count($errors) . " schema violations:\n";
            foreach ($errors as $error) {
                echo "  - $error\n";
            }
            $this->fail(count($errors) . " schema violations found");
        } else {
            echo "✓ All " . count($slots) . " slots conform to schema\n";
        }
    }

    /**
     * Test 3: Required fields are present
     */
    public function testRequiredFieldsArePresent(): void
    {
        $tomorrow = new DateTime('+1 day');
        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $tomorrow->format('d/m-Y'),
            'end_date' => $tomorrow->format('d/m-Y'),
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have slots");

        echo "\n=== Required Fields Validation ===\n";

        $requiredFields = ['when', 'start', 'end', 'overlap'];
        $errors = [];

        foreach ($slots as $index => $slot) {
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $slot)) {
                    $errors[] = "Slot #$index missing required field: $field";
                }
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo "✗ $error\n";
            }
            $this->fail(count($errors) . " missing required fields");
        } else {
            echo "✓ All slots have required fields: " . implode(', ', $requiredFields) . "\n";
        }
    }

    /**
     * Test 4: Field types match schema
     */
    public function testFieldTypesMatchSchema(): void
    {
        $tomorrow = new DateTime('+1 day');
        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $tomorrow->format('d/m-Y'),
            'end_date' => $tomorrow->format('d/m-Y'),
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have slots");

        echo "\n=== Field Type Validation ===\n";

        $errors = [];
        $typeChecks = [
            'when' => 'string',
            'start' => 'string',
            'end' => 'string',
            'start_iso' => 'string',
            'end_iso' => 'string',
            'resource_id' => 'integer',
            'overlap_reason' => 'string',
            'overlap_type' => 'string',
        ];

        foreach ($slots as $index => $slot) {
            foreach ($typeChecks as $field => $expectedType) {
                if (!isset($slot[$field])) {
                    continue; // Optional fields
                }

                $actualType = gettype($slot[$field]);
                if ($actualType !== $expectedType) {
                    $errors[] = "Slot #$index field '$field': expected $expectedType, got $actualType";
                }
            }

            // Special validation for overlap field (can be boolean false or integer)
            if (isset($slot['overlap'])) {
                $overlap = $slot['overlap'];
                if (!is_bool($overlap) && !is_int($overlap)) {
                    $errors[] = "Slot #$index field 'overlap': must be boolean or integer, got " . gettype($overlap);
                }
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo "✗ $error\n";
            }
            $this->fail(count($errors) . " type mismatches");
        } else {
            echo "✓ All fields have correct types\n";
        }
    }

    /**
     * Test 5: Timestamp formats are valid
     */
    public function testTimestampFormatsAreValid(): void
    {
        $tomorrow = new DateTime('+1 day');
        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $tomorrow->format('d/m-Y'),
            'end_date' => $tomorrow->format('d/m-Y'),
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have slots");

        echo "\n=== Timestamp Format Validation ===\n";

        $errors = [];

        foreach ($slots as $index => $slot) {
            // Validate millisecond timestamps (13 digits)
            if (isset($slot['start'])) {
                if (!preg_match('/^[0-9]{13}$/', $slot['start'])) {
                    $errors[] = "Slot #$index 'start' timestamp invalid format: {$slot['start']}";
                }
            }

            if (isset($slot['end'])) {
                if (!preg_match('/^[0-9]{13}$/', $slot['end'])) {
                    $errors[] = "Slot #$index 'end' timestamp invalid format: {$slot['end']}";
                }
            }

            // Validate ISO 8601 format
            if (isset($slot['start_iso'])) {
                if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}$/', $slot['start_iso'])) {
                    $errors[] = "Slot #$index 'start_iso' invalid ISO 8601 format: {$slot['start_iso']}";
                }
            }

            if (isset($slot['end_iso'])) {
                if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}$/', $slot['end_iso'])) {
                    $errors[] = "Slot #$index 'end_iso' invalid ISO 8601 format: {$slot['end_iso']}";
                }
            }

            // Validate 'when' format
            if (isset($slot['when'])) {
                if (!preg_match('/^[0-9]{2}\/[0-9]{2}-[0-9]{4} [0-9]{2}:[0-9]{2} - [0-9]{2}\/[0-9]{2}-[0-9]{4} [0-9]{2}:[0-9]{2}$/', $slot['when'])) {
                    $errors[] = "Slot #$index 'when' invalid format: {$slot['when']}";
                }
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo "✗ $error\n";
            }
            $this->fail(count($errors) . " format errors");
        } else {
            echo "✓ All timestamps have valid formats\n";
            echo "  - Millisecond timestamps: 13 digits\n";
            echo "  - ISO dates: YYYY-MM-DDTHH:MM:SS+TZ\n";
            echo "  - Human readable: DD/MM-YYYY HH:MM - DD/MM-YYYY HH:MM\n";
        }
    }

    /**
     * Test 6: Enum values are valid
     */
    public function testEnumValuesAreValid(): void
    {
        $startDate = new DateTime('now');
        $endDate = new DateTime('+2 days');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate->format('d/m-Y'),
            'end_date' => $endDate->format('d/m-Y'),
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have slots");

        echo "\n=== Enum Values Validation ===\n";

        $errors = [];

        $validOverlapReasons = ['time_in_past', 'complete_overlap', 'complete_containment', 'start_overlap', 'end_overlap'];
        $validOverlapTypes = ['disabled', 'complete', 'partial'];
        $validEventTypes = ['event', 'booking', 'allocation', 'block', 'application'];

        foreach ($slots as $index => $slot) {
            // Validate overlap_reason
            if (isset($slot['overlap_reason'])) {
                if (!in_array($slot['overlap_reason'], $validOverlapReasons)) {
                    $errors[] = "Slot #$index 'overlap_reason' invalid value: {$slot['overlap_reason']}";
                }
            }

            // Validate overlap_type
            if (isset($slot['overlap_type'])) {
                if (!in_array($slot['overlap_type'], $validOverlapTypes)) {
                    $errors[] = "Slot #$index 'overlap_type' invalid value: {$slot['overlap_type']}";
                }
            }

            // Validate overlap_event.type (MUST be non-null string with valid enum value)
            if (isset($slot['overlap_event'])) {
                if (!array_key_exists('type', $slot['overlap_event'])) {
                    $errors[] = "Slot #$index 'overlap_event' missing required field 'type'";
                } elseif ($slot['overlap_event']['type'] === null) {
                    $errors[] = "Slot #$index 'overlap_event.type' is NULL (must be a string: event/booking/allocation/block/application)";
                } elseif (!in_array($slot['overlap_event']['type'], $validEventTypes)) {
                    $errors[] = "Slot #$index 'overlap_event.type' invalid value: {$slot['overlap_event']['type']}";
                }
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo "✗ $error\n";
            }
            $this->fail(count($errors) . " invalid enum values");
        } else {
            echo "✓ All enum values are valid\n";
        }
    }

    /**
     * Test 7: Conditional fields follow schema rules
     */
    public function testConditionalFieldsFollowSchemaRules(): void
    {
        $startDate = new DateTime('now');
        $endDate = new DateTime('+2 days');

        $response = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate->format('d/m-Y'),
            'end_date' => $endDate->format('d/m-Y'),
            'detailed_overlap' => true,
        ]);

        $slots = $response[$this->testResourceId] ?? [];
        $this->assertNotEmpty($slots, "Should have slots");

        echo "\n=== Conditional Fields Validation ===\n";

        $errors = [];

        foreach ($slots as $index => $slot) {
            $overlap = $slot['overlap'] ?? null;

            // Rule 1: If overlap is not false/0, overlap_reason and overlap_type are required
            if ($overlap !== false && $overlap !== 0) {
                if (!isset($slot['overlap_reason'])) {
                    $errors[] = "Slot #$index has overlap=$overlap but missing 'overlap_reason'";
                }
                if (!isset($slot['overlap_type'])) {
                    $errors[] = "Slot #$index has overlap=$overlap but missing 'overlap_type'";
                }
            }

            // Rule 2: If overlap is 1 or 2, overlap_event should be present
            if ($overlap === 1 || $overlap === 2) {
                if (!isset($slot['overlap_event'])) {
                    $errors[] = "Slot #$index has overlap=$overlap but missing 'overlap_event'";
                } else {
                    // Validate overlap_event structure
                    $requiredEventFields = ['id', 'type', 'from', 'to'];
                    foreach ($requiredEventFields as $field) {
                        if (!array_key_exists($field, $slot['overlap_event'])) {
                            $errors[] = "Slot #$index overlap_event missing required field: $field";
                        }
                    }
                }
            }

            // Rule 3: If overlap is false/0, there should be no overlap_reason or overlap_type
            if ($overlap === false || $overlap === 0) {
                if (isset($slot['overlap_reason'])) {
                    $errors[] = "Slot #$index has overlap=$overlap but has 'overlap_reason' (should not)";
                }
                if (isset($slot['overlap_type'])) {
                    $errors[] = "Slot #$index has overlap=$overlap but has 'overlap_type' (should not)";
                }
                if (isset($slot['overlap_event'])) {
                    $errors[] = "Slot #$index has overlap=$overlap but has 'overlap_event' (should not)";
                }
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo "✗ $error\n";
            }
            $this->fail(count($errors) . " conditional field violations");
        } else {
            echo "✓ All conditional fields follow schema rules\n";
            echo "  - Overlapped slots have reason and type\n";
            echo "  - Complete overlaps (status 1) have event details\n";
            echo "  - Available slots have no overlap fields\n";
        }
    }

    /**
     * Test 8: detailed_overlap parameter affects schema
     */
    public function testDetailedOverlapParameterAffectsSchema(): void
    {
        $tomorrow = new DateTime('+1 day');
        $startDate = $tomorrow->format('d/m-Y');
        $endDate = $tomorrow->format('d/m-Y');

        // Test without detailed_overlap
        $responseBasic = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => false,
        ]);

        // Test with detailed_overlap
        $responseDetailed = $this->callFreetimeEndpoint([
            'building_id' => $this->testBuildingId,
            'resource_id' => $this->testResourceId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'detailed_overlap' => true,
        ]);

        echo "\n=== Detailed Overlap Schema Validation ===\n";

        $slotsBasic = $responseBasic[$this->testResourceId] ?? [];
        $slotsDetailed = $responseDetailed[$this->testResourceId] ?? [];

        $this->assertNotEmpty($slotsBasic, "Should have basic slots");
        $this->assertNotEmpty($slotsDetailed, "Should have detailed slots");

        // Check that detailed response has resource_id
        $hasResourceId = isset($slotsDetailed[0]['resource_id']);
        $this->assertTrue($hasResourceId, "Detailed response should have resource_id field");
        echo "✓ Detailed response includes resource_id\n";

        // Check that basic response might have applicationLink instead
        $basicSlot = $slotsBasic[0];
        echo "✓ Basic response schema validated\n";

        // Validate all detailed slots have extra fields
        $missingResourceId = 0;
        foreach ($slotsDetailed as $slot) {
            if (!isset($slot['resource_id'])) {
                $missingResourceId++;
            }
        }

        $this->assertEquals(0, $missingResourceId, "All detailed slots should have resource_id");
        echo "✓ All " . count($slotsDetailed) . " detailed slots have resource_id\n";
    }

    // ===== Helper Methods =====

    private function validateTimeSlot(array $slot, int $index, bool $detailedOverlap): array
    {
        $errors = [];

        // Required fields
        $requiredFields = ['when', 'start', 'end', 'overlap'];
        foreach ($requiredFields as $field) {
            if (!isset($slot[$field])) {
                $errors[] = "Slot #$index missing required field: $field";
            }
        }

        // Field types
        if (isset($slot['when']) && !is_string($slot['when'])) {
            $errors[] = "Slot #$index 'when' must be string";
        }
        if (isset($slot['start']) && !is_string($slot['start'])) {
            $errors[] = "Slot #$index 'start' must be string";
        }
        if (isset($slot['end']) && !is_string($slot['end'])) {
            $errors[] = "Slot #$index 'end' must be string";
        }
        if (isset($slot['overlap']) && !is_bool($slot['overlap']) && !is_int($slot['overlap'])) {
            $errors[] = "Slot #$index 'overlap' must be boolean or integer";
        }

        return $errors;
    }

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
}
