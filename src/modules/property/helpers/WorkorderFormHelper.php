<?php

namespace App\modules\property\helpers;

class WorkorderFormHelper
{
	/**
	 * Normalize incoming workorder payload.
	 */
	public function mapInput(array $requestData, bool $isEdit = false, int $id = 0): array
	{
		$values = isset($requestData['values']) && is_array($requestData['values'])
			? $requestData['values']
			: $requestData;

		$legacyContextFields = array(
			'project_id',
			'origin',
			'origin_id',
			'location_code',
			'tenant_id',
			'p_num',
			'p_entity_id',
			'p_cat_id',
			'vendor_id',
			'vendor_name',
			'event_id',
			'send_workorder',
			'calculate_workorder',
			'copy_workorder',
		);

		foreach ($legacyContextFields as $field)
		{
			if (array_key_exists($field, $requestData) && !array_key_exists($field, $values))
			{
				$values[$field] = $requestData[$field];
			}
		}

		if (array_key_exists('vendor_id', $values))
		{
			$values['vendor_id'] = (int)$values['vendor_id'];
		}

		if (array_key_exists('event_id', $values))
		{
			$values['event_id'] = (int)$values['event_id'];
		}

		if (!$isEdit)
		{
			$pEntityId = (int)($values['p_entity_id'] ?? 0);
			$pCatId = (int)($values['p_cat_id'] ?? 0);
			$pNum = isset($values['p_num']) ? (string)$values['p_num'] : '';

			if ($pEntityId > 0 && $pCatId > 0)
			{
				if (!isset($values['p']) || !is_array($values['p']))
				{
					$values['p'] = array();
				}

				if (!isset($values['p'][$pEntityId]) || !is_array($values['p'][$pEntityId]))
				{
					$values['p'][$pEntityId] = array();
				}

				$values['p'][$pEntityId]['p_entity_id'] = $pEntityId;
				$values['p'][$pEntityId]['p_cat_id'] = $pCatId;
				$values['p'][$pEntityId]['p_num'] = $pNum;
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
			'is_edit' => $isEdit,
			'errors' => array(),
		);
	}

	public function validate(array $state): array
	{
		$values = $state['values'] ?? array();
		$errors = $state['errors'] ?? array();

		if ($this->isRepost())
		{
			$errors[] = lang('Hmm... looks like a repost!');
		}

		if (empty($values['title']))
		{
			$errors[] = lang('Please enter a workorder title !');
		}

		if (empty($values['project_id']))
		{
			$errors[] = lang('Please select a valid project !');
		}

		if (empty($values['status']))
		{
			$errors[] = lang('Please select a status !');
		}

		if (empty($values['b_account_id']))
		{
			$errors[] = lang('Please select a budget account !');
		}

		$config = CreateObject('phpgwapi.config', 'property');
		$config->read();
		if (isset($config->config_data['workorder_require_vendor'])
			&& (int)$config->config_data['workorder_require_vendor'] === 1
			&& empty($values['vendor_id']))
		{
			$errors[] = lang('no vendor');
		}

		if (isset($values['budget']) && $values['budget'] !== '' && !$this->isIntegerValue($values['budget']))
		{
			$errors[] = lang('budget') . ': ' . lang('Please enter an integer !');
		}

		if (isset($values['addition_rs']) && $values['addition_rs'] !== '' && !$this->isIntegerValue($values['addition_rs']))
		{
			$errors[] = lang('Rig addition') . ': ' . lang('Please enter an integer !');
		}

		if (isset($values['addition_percentage']) && $values['addition_percentage'] !== '' && !$this->isIntegerValue($values['addition_percentage']))
		{
			$errors[] = lang('Percentage addition') . ': ' . lang('Please enter an integer !');
		}

		$state['errors'] = $errors;
		return $state;
	}

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

	protected function isRepost(): bool
	{
		if (!class_exists('phpgw') || !method_exists('phpgw', 'is_repost'))
		{
			return false;
		}

		return (bool) \phpgw::is_repost();
	}

	private function isIntegerValue($value): bool
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
}
