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

		$state['errors'] = $errors;
		return $state;
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