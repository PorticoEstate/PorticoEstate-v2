<?php

namespace App\Property\Helpers;

use App\Database\Db;
use App\Traits\DbRowTrait;
use Exception;

/**
 * LocationFormHelper - Orchestrator for location form workflows
 * 
 * Replaces inline validation/persistence in legacy UI with explicit state management.
 * Supports add/edit operations with clear error handling and recovery.
 * 
 * Pattern: Hybrid approach combining thin adapter clarity with explicit orchestration
 */
class LocationFormHelper
{
    use DbRowTrait;

    private Db $db;
    private string $module = 'property';

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * Normalize and enrich request state for location save
     * 
     * @param array $requestData Raw request data from controller
     * @param int|null $locationId Location ID if editing (null for new)
     * @return array Normalized state with structure: [values, errors, location_data]
     */
    public function mapInput(array $requestData, ?int $locationId = null): array
    {
        $normalized = [
            'values' => [],
            'errors' => [],
            'location_id' => (int)($locationId ?? 0),
            'location_data' => null,
        ];

        // Extract and normalize location fields
        $fieldsToMap = [
            'loc_code' => 'loc_code',
            'loc1' => 'loc1',
            'loc2' => 'loc2',
            'loc3' => 'loc3',
            'loc4' => 'loc4',
            'loc5' => 'loc5',
            'street_name' => 'street_name',
            'street_number' => 'street_number',
            'zip_code' => 'zip_code',
            'city' => 'city',
            'district' => 'district',
            'part_of_town' => 'part_of_town',
            'delivery_address' => 'delivery_address',
            'location_type' => 'location_type',
            'loc_date' => 'loc_date',
        ];

        foreach ($fieldsToMap as $key => $field) {
            if (isset($requestData[$key])) {
                $normalized['values'][$field] = \Sanitizer::sanitize($requestData[$key], 'string');
            }
        }

        // Load existing location data if editing
        if ($locationId && $locationId > 0) {
            $normalized['location_data'] = $this->loadLocationData($locationId);
            if (!$normalized['location_data']) {
                $normalized['errors']['location_id'] = 'Location not found';
            }
        }

        return $normalized;
    }

    /**
     * Validate location data before persistence
     * 
     * @param array $state Normalized state from mapInput()
     * @return array State with accumulated errors
     */
    public function validate(array $state): array
    {
        $values = $state['values'] ?? [];
        $errors = $state['errors'] ?? [];

        // Required field validation
        if (!isset($values['loc_code']) || trim($values['loc_code']) === '') {
            $errors['loc_code'] = 'Location code is required';
        }

        if (!isset($values['loc1']) || trim($values['loc1']) === '') {
            $errors['loc1'] = 'Location level 1 is required';
        }

        // Validate location code format (alphanumeric, underscore, hyphen)
        if (isset($values['loc_code']) && !preg_match('/^[A-Za-z0-9_-]+$/', $values['loc_code'])) {
            $errors['loc_code'] = 'Location code must contain only alphanumeric characters, underscore, or hyphen';
        }

        // Validate numeric fields if provided
        if (isset($values['street_number']) && trim($values['street_number']) !== '') {
            if (!is_numeric($values['street_number'])) {
                $errors['street_number'] = 'Street number must be numeric';
            }
        }

        if (isset($values['zip_code']) && trim($values['zip_code']) !== '') {
            if (!preg_match('/^[0-9\s\-]+$/', $values['zip_code'])) {
                $errors['zip_code'] = 'Invalid zip code format';
            }
        }

        $state['errors'] = $errors;
        return $state;
    }

    /**
     * Persist location save to database
     * 
     * @param array $state Validated state from validate()
     * @return array State with receipt/status data
     */
    public function persistSave(array $state): array
    {
        if (!empty($state['errors'])) {
            $state['receipt'] = ['status' => 'error', 'message' => 'Validation failed'];
            return $state;
        }

        try {
            $locationId = $state['location_id'] ?? 0;
            $values = $state['values'] ?? [];

            // Begin transaction for save operation
            $this->db->transaction_begin();

            if ($locationId > 0) {
                // Update existing location
                $this->updateLocation((int)$locationId, $values);
                $state['receipt'] = [
                    'status' => 'success',
                    'message' => 'Location updated successfully',
                    'location_id' => $locationId,
                ];
            } else {
                // Insert new location
                $newLocationId = $this->insertLocation($values);
                $state['receipt'] = [
                    'status' => 'success',
                    'message' => 'Location created successfully',
                    'location_id' => $newLocationId,
                ];
                $state['location_id'] = $newLocationId;
            }

            $this->db->transaction_commit();
        } catch (Exception $e) {
            $this->db->transaction_rollback();
            $state['errors']['save'] = $e->getMessage();
            $state['receipt'] = [
                'status' => 'error',
                'message' => 'Failed to save location: ' . $e->getMessage(),
            ];
        }

        return $state;
    }

    /**
     * Insert new location record
     * 
     * @param array $values Normalized values
     * @return int New location ID
     */
    private function insertLocation(array $values): int
    {
        $table = 'phpgw_property_location';
        $columns = [];
        $placeholders = [];
        $params = [];

        // Map values to table columns with type safety
        $fieldMap = [
            'loc_code' => ['column' => 'loc_code', 'type' => 'string'],
            'loc1' => ['column' => 'loc1', 'type' => 'string'],
            'loc2' => ['column' => 'loc2', 'type' => 'string'],
            'loc3' => ['column' => 'loc3', 'type' => 'string'],
            'loc4' => ['column' => 'loc4', 'type' => 'string'],
            'loc5' => ['column' => 'loc5', 'type' => 'string'],
            'street_name' => ['column' => 'street_name', 'type' => 'string'],
            'street_number' => ['column' => 'street_number', 'type' => 'string'],
            'zip_code' => ['column' => 'zip_code', 'type' => 'string'],
            'city' => ['column' => 'city', 'type' => 'string'],
            'district' => ['column' => 'district', 'type' => 'string'],
            'part_of_town' => ['column' => 'part_of_town', 'type' => 'string'],
            'delivery_address' => ['column' => 'delivery_address', 'type' => 'string'],
            'location_type' => ['column' => 'location_type', 'type' => 'int'],
            'loc_date' => ['column' => 'loc_date', 'type' => 'string'],
        ];

        // Build insert query with parameterized placeholders
        foreach ($fieldMap as $field => $config) {
            if (isset($values[$field]) && trim((string)$values[$field]) !== '') {
                $columns[] = $config['column'];
                $placeholders[] = ':' . $config['column'];

                // Type-safe parameter binding
                if ($config['type'] === 'int') {
                    $params[$config['column']] = (int)$values[$field];
                } else {
                    $params[$config['column']] = $values[$field];
                }
            }
        }

        if (empty($columns)) {
            throw new Exception('No valid fields to insert');
        }

        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
        $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $this->db->bind_param(':' . $key, $value);
        }

        if (!$this->db->execute()) {
            throw new Exception('Failed to insert location record');
        }

        // Return the inserted ID
        return (int)$this->db->last_insert_id('phpgw_property_location', 'loc_id');
    }

    /**
     * Update existing location record
     * 
     * @param int $locationId Location ID
     * @param array $values Normalized values
     */
    private function updateLocation(int $locationId, array $values): void
    {
        if ($locationId <= 0) {
            throw new Exception('Invalid location ID for update');
        }

        $table = 'phpgw_property_location';
        $updates = [];
        $params = [];

        // Map updateable fields
        $fieldMap = [
            'loc_code' => ['column' => 'loc_code', 'type' => 'string'],
            'loc1' => ['column' => 'loc1', 'type' => 'string'],
            'loc2' => ['column' => 'loc2', 'type' => 'string'],
            'loc3' => ['column' => 'loc3', 'type' => 'string'],
            'loc4' => ['column' => 'loc4', 'type' => 'string'],
            'loc5' => ['column' => 'loc5', 'type' => 'string'],
            'street_name' => ['column' => 'street_name', 'type' => 'string'],
            'street_number' => ['column' => 'street_number', 'type' => 'string'],
            'zip_code' => ['column' => 'zip_code', 'type' => 'string'],
            'city' => ['column' => 'city', 'type' => 'string'],
            'district' => ['column' => 'district', 'type' => 'string'],
            'part_of_town' => ['column' => 'part_of_town', 'type' => 'string'],
            'delivery_address' => ['column' => 'delivery_address', 'type' => 'string'],
            'location_type' => ['column' => 'location_type', 'type' => 'int'],
            'loc_date' => ['column' => 'loc_date', 'type' => 'string'],
        ];

        // Build update query with parameterized placeholders
        foreach ($fieldMap as $field => $config) {
            if (isset($values[$field]) && trim((string)$values[$field]) !== '') {
                $updates[] = $config['column'] . ' = :' . $config['column'];

                // Type-safe parameter binding
                if ($config['type'] === 'int') {
                    $params[$config['column']] = (int)$values[$field];
                } else {
                    $params[$config['column']] = $values[$field];
                }
            }
        }

        if (empty($updates)) {
            throw new Exception('No fields to update');
        }

        $sql = 'UPDATE ' . $table . ' SET ' . implode(',', $updates) . ' WHERE loc_id = :loc_id';
        $params['loc_id'] = $locationId;

        $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $this->db->bind_param(':' . $key, $value);
        }

        if (!$this->db->execute()) {
            throw new Exception('Failed to update location record');
        }
    }

    /**
     * Load location data for rehydration after validation errors
     * 
     * @param int $locationId Location ID
     * @return array|null Location record or null if not found
     */
    private function loadLocationData(int $locationId): ?array
    {
        $locationId = (int)$locationId;
        if ($locationId <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM phpgw_property_location WHERE loc_id = :loc_id';
        $this->db->prepare($sql);
        $this->db->bind_param(':loc_id', $locationId);

        if (!$this->db->execute()) {
            return null;
        }

        $result = $this->db->fetch();
        return $result ?: null;
    }

    /**
     * Build response based on state and user action
     * 
     * @param array $state Final state after persistence
     * @param string $userAction User's intended action ('save', 'save_continue', etc.)
     * @return array Response with type, payload, and receipt
     */
    public function buildSaveResponse(array $state, string $userAction = 'save'): array
    {
        $response = [
            'type' => 'json',
            'payload' => [
                'status' => $state['receipt']['status'] ?? 'unknown',
                'message' => $state['receipt']['message'] ?? 'Operation completed',
                'location_id' => $state['receipt']['location_id'] ?? null,
                'errors' => $state['errors'] ?? [],
            ],
        ];

        // If there are errors, rehydrate form data for retry
        if (!empty($state['errors'])) {
            $response['type'] = 'json';
            $response['payload']['values'] = $state['values'] ?? [];
            $response['payload']['location_data'] = $state['location_data'] ?? null;
        }

        return $response;
    }
}
