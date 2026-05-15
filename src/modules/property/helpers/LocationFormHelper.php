<?php

namespace App\modules\property\helpers;

use Exception;
use function include_class;

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
    private ?\property_bolocation $bo = null;

    /**
     * Normalize and enrich request state for location save
     * 
     * @param array $requestData Raw request data from controller
     * @param int|null $locationId Location ID if editing (null for new)
     * @return array Normalized state with structure: [values, errors, location_data]
     */
    public function mapInput(array $requestData, ?int $locationId = null): array
    {
        $locationCode = $this->resolveLocationCode($requestData);
        $locationParts = $this->extractLocationParts($locationCode);
        $typeId = isset($requestData['type_id']) ? (int) $requestData['type_id'] : count($locationParts);

        $normalized = [
            'values' => [],
            'values_attribute' => isset($requestData['values_attribute']) && is_array($requestData['values_attribute'])
                ? $requestData['values_attribute']
                : [],
            'errors' => [],
            'location_id' => (int) ($locationId ?? 0),
            'location_code' => $locationCode,
            'type_id' => $typeId,
            'location_parent' => count($locationParts) > 1 ? array_slice($locationParts, 0, -1) : [],
            'is_edit' => !empty($locationId),
            'location_data' => null,
        ];

        // Extract and normalize location fields
        $fieldsToMap = [
            'location_code' => 'string',
            'loc_code' => 'string',
            'loc1' => 'string',
            'loc2' => 'string',
            'loc3' => 'string',
            'loc4' => 'string',
            'loc5' => 'string',
            'street_name' => 'string',
            'street_number' => 'string',
            'zip_code' => 'string',
            'city' => 'string',
            'district' => 'string',
            'part_of_town' => 'string',
            'delivery_address' => 'string',
            'location_type' => 'int',
            'change_type' => 'int',
            'cat_id' => 'int',
            'type_id' => 'int',
            'loc_date' => 'string',
        ];

        foreach ($fieldsToMap as $key => $type) {
            if (isset($requestData[$key])) {
                $normalized['values'][$key] = \Sanitizer::sanitize($requestData[$key], $type);
            }
        }

        if ($locationCode !== '') {
            $normalized['values']['location_code'] = $locationCode;
        }

        // Load existing location data if editing
        if (!empty($normalized['is_edit'])) {
            if ($locationCode === '') {
                $normalized['errors']['location_code'] = 'Location code is required for update';
            } else {
                $normalized['location_data'] = $this->loadLocationData($locationCode);
                if (!$normalized['location_data']) {
                    $normalized['errors']['location_code'] = 'Location not found';
                }
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
        $locationCode = $values['location_code'] ?? ($state['location_code'] ?? '');

        // Required field validation
        if ($locationCode === '') {
            $errors['location_code'] = 'Location code is required';
        }

        if (!isset($values['loc1']) || trim($values['loc1']) === '') {
            $errors['loc1'] = 'Location level 1 is required';
        }

        // Validate location code format (alphanumeric, underscore, hyphen)
        if ($locationCode !== '' && !preg_match('/^[A-Za-z0-9_-]+(?:-[A-Za-z0-9_-]+)*$/', $locationCode)) {
            $errors['location_code'] = 'Location code must contain only alphanumeric characters, underscore, or hyphen';
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

        $state['location_code'] = $locationCode;
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
            $values = $state['values'] ?? [];
            $locationCode = (string)($values['location_code'] ?? ($state['location_code'] ?? ''));
            $typeId = (int)($state['type_id'] ?? 0);

            if ($locationCode === '') {
                throw new Exception('Location code is required');
            }

            if ($typeId <= 0) {
                $typeId = count($this->extractLocationParts($locationCode));
            }

            if ($typeId <= 0) {
                throw new Exception('Location type is required');
            }

            $values['location_code'] = $locationCode;
            $action = !empty($state['is_edit']) ? 'edit' : '';
            $receipt = $this->bo()->save(
                $values,
                $state['values_attribute'] ?? [],
                $action,
                $typeId,
                $state['location_parent'] ?? ''
            );

            if (!empty($receipt['error'])) {
                $state['errors']['save'] = $this->flattenReceiptMessages($receipt['error']);
                $state['receipt'] = [
                    'status' => 'error',
                    'message' => 'Failed to save location',
                    'location_code' => $locationCode,
                    'location_id' => $state['location_id'] ?: null,
                    'receipt' => $receipt,
                ];
            } else {
                $savedLocationCode = $receipt['location_code'] ?? $locationCode;
                $state['location_code'] = $savedLocationCode;
                $state['values']['location_code'] = $savedLocationCode;
                $state['location_data'] = $this->loadLocationData($savedLocationCode);
                $state['receipt'] = [
                    'status' => 'success',
                    'message' => $action === 'edit' ? 'Location updated successfully' : 'Location created successfully',
                    'location_code' => $savedLocationCode,
                    'location_id' => $state['location_id'] ?: null,
                    'receipt' => $receipt,
                ];
            }
        } catch (Exception $e) {
            $state['errors']['save'] = $e->getMessage();
            $state['receipt'] = [
                'status' => 'error',
                'message' => 'Failed to save location: ' . $e->getMessage(),
                'location_code' => $state['location_code'] ?? null,
                'location_id' => $state['location_id'] ?? null,
            ];
        }

        return $state;
    }

    /**
     * Load location data for rehydration after validation errors
     * 
     * @param int $locationId Location ID
     * @return array|null Location record or null if not found
     */
    private function loadLocationData(string $locationCode): ?array
    {
        if ($locationCode === '') {
            return null;
        }

        $result = $this->bo()->read_single([
            'location_code' => $locationCode,
            'extra' => ['noattrib' => true],
        ]);

        return is_array($result) ? $result : null;
    }

    private function bo(): \property_bolocation
    {
        if ($this->bo === null) {
            if (defined('SRC_ROOT_PATH') && !function_exists('include_class')) {
                require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
            }

            include_class('property', 'bolocation');
            $this->bo = \CreateObject('property.bolocation');
        }

        return $this->bo;
    }

    private function resolveLocationCode(array $requestData): string
    {
        if (!empty($requestData['location_code'])) {
            return trim((string) $requestData['location_code']);
        }

        if (!empty($requestData['loc_code'])) {
            return trim((string) $requestData['loc_code']);
        }

        $parts = [];
        for ($level = 1; $level <= 5; $level++) {
            $key = "loc{$level}";
            if (empty($requestData[$key])) {
                break;
            }

            $parts[] = trim((string) $requestData[$key]);
        }

        return implode('-', $parts);
    }

    private function extractLocationParts(string $locationCode): array
    {
        if ($locationCode === '') {
            return [];
        }

        return array_values(array_filter(explode('-', $locationCode), static fn($part) => $part !== ''));
    }

    private function flattenReceiptMessages(array $errors): string
    {
        $messages = [];
        foreach ($errors as $error) {
            if (is_array($error) && !empty($error['msg'])) {
                $messages[] = $error['msg'];
            }
        }

        return $messages ? implode(' ', $messages) : 'Failed to save location';
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
                'location_code' => $state['receipt']['location_code'] ?? ($state['location_code'] ?? null),
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
