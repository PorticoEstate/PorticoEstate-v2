<?php

namespace App\modules\property\helpers;

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
    private ?\property_bolocation $bo = null;
    private ?array $locationConfig = null;
    private ?array $locationTypes = null;

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
        $originalLocationCode = isset($requestData['location_code_original']) ? trim((string) $requestData['location_code_original']) : '';
        if ($originalLocationCode === '')
        {
            $originalLocationCode = $locationCode;
        }
        $locationParts = $this->extractLocationParts($locationCode);
        $typeId = $this->resolveTypeId($requestData, $locationParts);

        $normalized = [
            'values' => [],
            'values_attribute' => isset($requestData['values_attribute']) && is_array($requestData['values_attribute'])
                ? $requestData['values_attribute']
                : [],
            'errors' => [],
            'location_id' => (int) ($locationId ?? 0),
            'location_code' => $locationCode,
            'location_code_original' => $originalLocationCode,
            'type_id' => $typeId,
            'location_parent' => count($locationParts) > 1 ? array_slice($locationParts, 0, -1) : [],
            'is_edit' => !empty($locationId),
            'location_data' => null,
        ];

        // Extract and normalize location fields based on DB-configured location settings.
        $fieldsToMap = $this->buildDynamicFieldMap($typeId);

        foreach ($fieldsToMap as $key => $type)
        {
            if (isset($requestData[$key]))
            {
                $normalized['values'][$key] = \Sanitizer::sanitize($requestData[$key], $type);
            }
        }

        if ($locationCode !== '')
        {
            $normalized['values']['location_code'] = $locationCode;
        }

        // Load existing location data if editing
        if (!empty($normalized['is_edit']))
        {
            if ($originalLocationCode === '')
            {
                $normalized['errors']['location_code'] = 'Location code is required for update';
            }
            else
            {
                $normalized['location_data'] = $this->loadLocationData($originalLocationCode);
                if (!$normalized['location_data'])
                {
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
        if ($locationCode === '')
        {
            $errors['location_code'] = 'Location code is required';
        }

        if (!isset($values['loc1']) || trim($values['loc1']) === '')
        {
            $errors['loc1'] = 'Location level 1 is required';
        }

        // Validate location code format (alphanumeric, underscore, hyphen)
        if ($locationCode !== '' && !preg_match('/^[A-Za-z0-9_-]+(?:-[A-Za-z0-9_-]+)*$/', $locationCode))
        {
            $errors['location_code'] = 'Location code must contain only alphanumeric characters, underscore, or hyphen';
        }

        // Validate numeric fields if provided
        if (isset($values['street_number']) && trim($values['street_number']) !== '')
        {
            if (!is_numeric($values['street_number']))
            {
                $errors['street_number'] = 'Street number must be numeric';
            }
        }

        if (isset($values['zip_code']) && trim($values['zip_code']) !== '')
        {
            if (!preg_match('/^[0-9\s\-]+$/', $values['zip_code']))
            {
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
        if (!empty($state['errors']))
        {
            $state['receipt'] = ['status' => 'error', 'message' => 'Validation failed'];
            return $state;
        }

        try
        {
            $values = $state['values'] ?? [];
            $locationCode = (string)($values['location_code'] ?? ($state['location_code'] ?? ''));
            $originalLocationCode = (string)($state['location_code_original'] ?? $locationCode);
            $typeId = (int)($state['type_id'] ?? 0);

            if ($locationCode === '')
            {
                throw new Exception('Location code is required');
            }

            if ($typeId <= 0)
            {
                $typeId = count($this->extractLocationParts($locationCode));
            }

            if ($typeId <= 0)
            {
                throw new Exception('Location type is required');
            }

            $values['location_code'] = $locationCode;
            $values = $this->sanitizeSaveValues($values);
            $action = !empty($state['is_edit']) ? 'edit' : '';
            $receipt = $this->bo()->save(
                $values,
                $state['values_attribute'] ?? [],
                $action,
                $typeId,
                $state['location_parent'] ?? [],
                $originalLocationCode
            );

            if (!empty($receipt['error']))
            {
                $state['errors']['save'] = $this->flattenReceiptMessages($receipt['error']);
                $state['receipt'] = [
                    'status' => 'error',
                    'message' => 'Failed to save location',
                    'location_code' => $locationCode,
                    'location_code_original' => $originalLocationCode,
                    'location_id' => $state['location_id'] ?: null,
                    'receipt' => $receipt,
                ];
            }
            else
            {
                $savedLocationCode = $receipt['location_code'] ?? $locationCode;
                $state['location_code'] = $savedLocationCode;
                $state['values']['location_code'] = $savedLocationCode;
                $state['location_data'] = $this->loadLocationData($savedLocationCode);
                $state['receipt'] = [
                    'status' => 'success',
                    'message' => $action === 'edit' ? 'Location updated successfully' : 'Location created successfully',
                    'location_code' => $savedLocationCode,
                    'location_code_original' => $originalLocationCode,
                    'location_id' => $state['location_id'] ?: null,
                    'receipt' => $receipt,
                ];
            }
        }
        catch (Exception $e)
        {
            $state['errors']['save'] = $e->getMessage();
            $state['receipt'] = [
                'status' => 'error',
                'message' => 'Failed to save location: ' . $e->getMessage(),
                'location_code' => $state['location_code'] ?? null,
                'location_code_original' => $state['location_code_original'] ?? null,
                'location_id' => $state['location_id'] ?? null,
            ];
        }

        return $state;
    }

    private function sanitizeSaveValues(array $values): array
    {
        if (empty($values['location_code']) && !empty($values['loc_code']))
        {
            $values['location_code'] = (string) $values['loc_code'];
        }

        unset(
            $values['loc_code'],
            $values['type_id'],
            $values['location_type'],
            $values['location_id'],
            $values['is_edit'],
            $values['location_parent'],
            $values['location_data'],
            $values['location_code_original'],
            $values['values_attribute'],
            $values['errors'],
            $values['error_id']
        );

        return $values;
    }

    /**
     * Apply legacy uilocation save rules for required levels, category, attributes and edit/add constraints.
     *
     * @param array $state State from mapInput or intermediate legacy adapters.
     * @param array $insertRecord Legacy insert record session payload.
     * @param bool $isEdit True when editing an existing location.
     * @return array Updated state with normalized location_code, location_parent and errors.
     */
    public function applyLegacyRules(array $state, array $insertRecord, bool $isEdit): array
    {
        $values = $state['values'] ?? [];
        $valuesAttribute = $state['values_attribute'] ?? [];
        $errors = $state['errors'] ?? [];
        $typeId = (int) ($state['type_id'] ?? 0);

        if ($typeId <= 0)
        {
            $locationCode = (string) ($state['location_code'] ?? ($values['location_code'] ?? ''));
            $typeId = $locationCode !== '' ? count($this->extractLocationParts($locationCode)) : 0;
        }

        $locationCodeParts = [];
        $locationParent = [];

        for ($level = 1; $level <= $typeId; $level++)
        {
            $locKey = "loc{$level}";
            $value = isset($values[$locKey]) ? trim((string) $values[$locKey]) : '';

            if ($value === '')
            {
                $errors[] = lang('Please select a location %1 ID !', $level);
            }

            $values[$locKey] = $value;
            $locationCodeParts[] = $value;
            if ($level < $typeId)
            {
                $locationParent[] = $value;
            }
        }

        if (empty($values['cat_id']))
        {
            $errors[] = lang('Please select a category');
        }

        if (is_array($valuesAttribute))
        {
            // get the attribute definitions for the location type to validate against from the database.
            $firstAttribute = current($valuesAttribute);
            if (empty($firstAttribute['datatype']))
            {
                $bo = $this->bo();
                $bo->get_attribute_information($valuesAttribute, $typeId);
            }

            foreach ($valuesAttribute as $attribute)
            {
                if (($attribute['nullable'] ?? null) != 1 && (!array_key_exists('value', $attribute) || $attribute['value'] === null || (is_string($attribute['value']) && trim($attribute['value']) === '')))
                {
                    $errors[] = lang('Please enter value for attribute %1', $attribute['input_text']);
                }

                if (($attribute['datatype'] ?? null) == 'I'
                    && array_key_exists('value', $attribute)
                    && $attribute['value'] !== null
                    && !(is_string($attribute['value']) && trim($attribute['value']) === '')
                    && !$this->isStrictIntegerValue($attribute['value']))
                {
                    $errors[] = lang('Please enter integer for attribute %1', $attribute['input_text']);
                }
            }
        }

        foreach ($this->getLocationConfig() as $configEntry)
        {
            if($configEntry['location_type'] == $typeId && !empty($configEntry['lookup_form']))
            {
                $column = $configEntry['column_name'] ?? '';
                if ($column && isset($values[$column]) && ($values[$column] === null || (is_string($values[$column]) && trim($values[$column]) === '')))
                {
                    $errors[] = lang('Please select a value for %1', $configEntry['column_name']);
                }
            }
        }

/*
        if (isset($insertRecord['extra']) && is_array($insertRecord['extra']))
        {
            if (array_search('street_id', $insertRecord['extra'], true) !== false && empty($values['street_id']))
            {
                $errors[] = lang('Please select a street');
            }
            if (array_search('part_of_town_id', $insertRecord['extra'], true) !== false && empty($values['part_of_town_id']))
            {
                $errors[] = lang('Please select a part of town');
            }
            if (array_search('owner_id', $insertRecord['extra'], true) !== false && empty($values['owner_id']))
            {
                $errors[] = lang('Please select an owner');
            }
        }
*/
        $values['location_code'] = implode('-', $locationCodeParts);

        if (!$isEdit && !empty($values['location_code']) && $typeId > 0 && $this->bo()->check_location($values['location_code'], $typeId))
        {
            $errors[] = lang('This location is already registered!') . '[ ' . $values['location_code'] . ' ]';
        }

        if ($isEdit)
        {
            $values['change_type'] = isset($values['change_type']) ? (int) $values['change_type'] : 0;
            if (empty($values['change_type']))
            {
                $errors[] = lang('Please select change type');
            }
        }

        $values['error_id'] = !empty($errors);

        $state['values'] = $values;
        $state['values_attribute'] = $valuesAttribute;
        $state['type_id'] = $typeId;
        $state['location_parent'] = $locationParent;
        $state['location_code'] = $values['location_code'];
        $state['errors'] = $errors;

        return $state;
    }

    /**
     * Load location data for rehydration after validation errors
     * 
     * @param string $locationCode Location code to load
     * @return array|null Location record or null if not found
     */
    private function loadLocationData(string $locationCode): ?array
    {
        if ($locationCode === '')
        {
            return null;
        }

        $result = $this->bo()->read_single([
            'location_code' => $locationCode,
            'extra' => ['noattrib' => true],
        ]);

        return is_array($result) ? $result : null;
    }

    /**
     * Accept only real integers or integer-formatted strings (including "0").
     * Reject floats, scientific notation and mixed alphanumeric strings.
     *
     * @param mixed $value
     * @return bool
     */
    private function isStrictIntegerValue($value): bool
    {
        if (is_int($value))
        {
            return true;
        }

        if (is_string($value))
        {
            $value = trim($value);
            return $value !== '' && preg_match('/^-?\d+$/', $value) === 1;
        }

        return false;
    }

    private function bo(): \property_bolocation
    {
        if ($this->bo === null)
        {
            if (defined('SRC_ROOT_PATH') && !function_exists('include_class'))
            {
                require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
            }

            $this->bo = \CreateObject('property.bolocation');
        }

        return $this->bo;
    }

    private function resolveLocationCode(array $requestData): string
    {
        if (!empty($requestData['location_code']))
        {
            return trim((string) $requestData['location_code']);
        }

        if (!empty($requestData['loc_code']))
        {
            return trim((string) $requestData['loc_code']);
        }

        $parts = [];
        for ($level = 1; $level <= 5; $level++)
        {
            $key = "loc{$level}";
            if (empty($requestData[$key]))
            {
                break;
            }

            $parts[] = trim((string) $requestData[$key]);
        }

        return implode('-', $parts);
    }

    private function resolveTypeId(array $requestData, array $locationParts): int
    {
        if (isset($requestData['type_id']))
        {
            $typeId = (int) $requestData['type_id'];
            if ($typeId > 0)
            {
                return $typeId;
            }
        }

        if (!empty($locationParts))
        {
            return count($locationParts);
        }

        $maxLevel = 0;
        for ($level = 1; $level <= 50; $level++)
        {
            $key = "loc{$level}";
            if (!empty($requestData[$key]))
            {
                $maxLevel = $level;
            }
        }

        if ($maxLevel > 0)
        {
            return $maxLevel;
        }

        return count($this->getLocationTypes());
    }

    private function buildDynamicFieldMap(int $typeId): array
    {
        $fields = [
            'location_code' => 'string',
            'type_id' => 'int',
            'cat_id' => 'int',
            'change_type' => 'int',
        ];

        if ($typeId > 0)
        {
            for ($level = 1; $level <= $typeId; $level++)
            {
                $fields["loc{$level}"] = 'string';
                if ($level === $typeId)
                {
                    $fields["loc{$level}_name"] = 'string';
                }
            }
        }

        foreach ($this->getLocationConfig() as $configEntry)
        {
            $column = isset($configEntry['column_name']) ? (string) $configEntry['column_name'] : '';
            $locationType = isset($configEntry['location_type']) ? (int) $configEntry['location_type'] : 0;
            $lookupForm = !empty($configEntry['lookup_form']);

            if ($column === '' || $locationType <= 0 || $locationType > $typeId || !$lookupForm)
            {
                continue;
            }

            $fields[$column] = $this->inferSanitizerType($column);

            if ($column === 'street_id')
            {
                //             $fields['street_name'] = 'string';
                $fields['street_number'] = 'string';
            }
            /*
            if ($column === 'tenant_id')
            {
                $fields['first_name'] = 'string';
                $fields['last_name'] = 'string';
                $fields['contact_phone'] = 'string';
            }
*/
        }

        return $fields;
    }

    private function inferSanitizerType(string $column): string
    {
        $intColumns = [
            'type_id',
            'cat_id',
            'change_type',
            'street_id',
            'tenant_id',
            'owner_id',
            'part_of_town_id',
            'district_id',
            'location_type',
        ];

        if (in_array($column, $intColumns, true) || preg_match('/(^|_)id$/', $column))
        {
            return 'int';
        }

        return 'string';
    }

    private function getLocationConfig(): array
    {
        if ($this->locationConfig === null)
        {
            $config = $this->bo()->soadmin_location->read_config('');
            $this->locationConfig = is_array($config) ? $config : [];
        }

        return $this->locationConfig;
    }

    private function getLocationTypes(): array
    {
        if ($this->locationTypes === null)
        {
            $types = $this->bo()->soadmin_location->select_location_type();
            $this->locationTypes = is_array($types) ? $types : [];
        }

        return $this->locationTypes;
    }

    private function extractLocationParts(string $locationCode): array
    {
        if ($locationCode === '')
        {
            return [];
        }

        return array_values(array_filter(explode('-', $locationCode), static fn($part) => $part !== ''));
    }

    private function flattenReceiptMessages(array $errors): string
    {
        $messages = [];
        foreach ($errors as $error)
        {
            if (is_array($error) && !empty($error['msg']))
            {
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
        if (!empty($state['errors']))
        {
            $response['type'] = 'json';
            $response['payload']['values'] = $state['values'] ?? [];
            $response['payload']['location_data'] = $state['location_data'] ?? null;
        }

        return $response;
    }
}
