<?php

namespace App\modules\property\helpers;

class ProjectFormHelper
{
	/**
	 * Normalize incoming project payload.
	 */
	public function mapInput(array $requestData, bool $isEdit = false, int $id = 0): array
	{
		$values = isset($requestData['values']) && is_array($requestData['values'])
			? $requestData['values']
			: $requestData;

		$relationInfo = isset($requestData['RelationInfo']) && is_array($requestData['RelationInfo'])
			? $requestData['RelationInfo']
			: array();

		$relationFields = array(
			'location_code',
			'tenant_id',
			'p_num',
			'p_entity_id',
			'p_cat_id',
			'origin',
			'origin_id',
		);
		foreach ($relationFields as $field)
		{
			if (array_key_exists($field, $relationInfo) && !array_key_exists($field, $values))
			{
				$values[$field] = $relationInfo[$field];
			}
		}

		$legacyContextFields = array(
			'location_code',
			'tenant_id',
			'p_num',
			'p_entity_id',
			'p_cat_id',
			'origin',
			'origin_id',
			'descr',
			'contact_id',
			'contact',
			'new_project_id',
			'copy_project',
			'bypass',
		);
		foreach ($legacyContextFields as $field)
		{
			if (array_key_exists($field, $requestData) && !array_key_exists($field, $values))
			{
				$values[$field] = $requestData[$field];
			}
		}

		$valuesAttribute = isset($requestData['values_attribute']) && is_array($requestData['values_attribute'])
			? $requestData['values_attribute']
			: array();

		if ($isEdit && $id > 0)
		{
			$values['id'] = $id;
		}

		return array(
			'values' => $values,
			'values_attribute' => $valuesAttribute,
			'RelationInfo' => $relationInfo,
			'is_edit' => $isEdit,
			'errors' => array(),
		);
	}

	/**
	 * Keep initial validation minimal and non-breaking while write APIs are introduced.
	 */
	public function validate(array $state): array
	{
		$values = $state['values'] ?? array();
		$errors = $state['errors'] ?? array();

		if (empty($values['name']))
		{
			$errors[] = 'Project name is required';
		}

		if (empty($values['project_type_id']))
		{
			$errors[] = 'Project type is required';
		}

		if (empty($values['coordinator']))
		{
			$errors[] = 'Coordinator is required';
		}

		if (empty($values['status']))
		{
			$errors[] = 'Status is required';
		}


		$CustomFields = new \App\modules\phpgwapi\services\CustomFields();
		$_attributes = $CustomFields->find('property', '.project', 0, '', 'ASC', 'attrib_sort', true, true);

		foreach ($_attributes as $attrib_id => &$_attribute)
		{
			foreach ($state['values_attribute'] as $_key =>  $attribute)
			{
				if ($attrib_id == $_attribute['id'])
				{
					$attribute = array_merge($_attribute, $attribute);
				}
			}
		}


		foreach ($_attributes as $attribute)
		{
			if (($attribute['nullable'] ?? null) != 1 && (!array_key_exists('value', $attribute) || $attribute['value'] === null || (is_string($attribute['value']) && trim($attribute['value']) === '')))
			{
				$errors[] = lang('Please enter value for attribute %1', $attribute['input_text']);
			}

			if (($attribute['datatype'] ?? null) == 'I'
				&& array_key_exists('value', $attribute)
				&& $attribute['value'] !== null
				&& !(is_string($attribute['value']) && trim($attribute['value']) === '')
				&& !$this->isStrictIntegerValue($attribute['value'])
			)
			{
				$errors[] = lang('Please enter integer for attribute %1', $attribute['input_text']);
			}
		}

		$state['errors'] = $errors;
		return $state;
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

	/**
	 * Delegate persistence to legacy BO save flow for compatibility.
	 */
	public function persistSave(array $state, object $bo): array
	{
		if (!empty($state['errors']))
		{
			$state['receipt'] = array(
				'status' => 'error',
				'error' => array_map(static fn(string $msg) => array('msg' => $msg), $state['errors']),
			);
			return $state;
		}

		$action = !empty($state['is_edit']) ? 'edit' : '';
		$receipt = $bo->save($state['values'], $action, $state['values_attribute']);

		$state['receipt'] = is_array($receipt) ? $receipt : array();
		$state['id'] = (int)($state['receipt']['id'] ?? $state['values']['id'] ?? 0);
		return $state;
	}
}
