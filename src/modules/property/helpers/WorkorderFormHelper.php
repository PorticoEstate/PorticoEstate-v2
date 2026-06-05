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
		$messages = $state['messages'] ?? array();

		$configData = $this->getConfigData();
		$projectId = (int)($values['project_id'] ?? 0);
		$project = $projectId > 0 ? $this->readProjectMini($projectId) : array();
		$projectEcodimb = (string)($project['ecodimb'] ?? '');

		if ($this->isRepost())
		{
			$errors[] = lang('Hmm... looks like a repost!');
		}

		if (!empty($values['new_project_id']))
		{
			$newProjectId = (int)$values['new_project_id'];
			if ($newProjectId > 0)
			{
				if ($projectId > 0 && $newProjectId === $projectId)
				{
					unset($values['new_project_id']);
				}
				else if (!$this->readProjectMini($newProjectId))
				{
					$errors[] = lang('the project %1 does not exist', (string)$values['new_project_id']);
				}
			}
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
		else
		{
			$bAccountId = (int)$values['b_account_id'];
			$bAccount = $this->readGeneric('budget_account', $bAccountId);

			if (!empty($bAccount['ecodimb']))
			{
				$boundEcodimb = (string)$bAccount['ecodimb'];
				$currentEcodimb = (string)($values['ecodimb'] ?? '');
				if ($currentEcodimb !== '' && $currentEcodimb !== $boundEcodimb)
				{
					$messages[] = "Ansvar er overstyrt av binding til art: {$currentEcodimb} -> {$boundEcodimb}";
				}

				$values['ecodimb'] = $boundEcodimb;
			}

			if (empty($bAccount) || empty($bAccount['active']))
			{
				$values['b_account_id'] = '';
				$values['b_account_name'] = '';
				$errors[] = lang('Please select a valid budget account !');
			}
		}

		if (isset($configData['workorder_require_vendor'])
			&& (int)$configData['workorder_require_vendor'] === 1
			&& empty($values['vendor_id']))
		{
			$errors[] = lang('no vendor');
		}

		if (empty($values['ecodimb']))
		{
			$values['ecodimb'] = $projectEcodimb;
		}

		if (empty($values['ecodimb']))
		{
			$errors[] = lang('Please select dimb!');
		}
		else
		{
			$ecodimb = (string)$values['ecodimb'];
			$ecodimbData = $this->readGeneric('dimb', (int)$ecodimb);
			if (empty($ecodimbData) || empty($ecodimbData['active']))
			{
				$values['ecodimb'] = '';
				$values['ecodimb_name'] = '';
				$errors[] = lang('Please select a valid dimb!');
			}
		}

		if (isset($values['budget']) && $values['budget'] !== '' && !$this->isIntegerValue($values['budget']))
		{
			$errors[] = lang('budget') . ': ' . lang('Please enter an integer !');
		}

		if (empty($state['is_edit'])
			&& empty($values['contract_sum'])
			&& empty($values['budget']))
		{
			$errors[] = lang('please enter either a budget or contrakt sum');
		}

		$contractSum = (int)($values['contract_sum'] ?? 0);
		$budget = (int)($values['budget'] ?? 0);
		if ($contractSum !== 0 && $budget !== 0 && abs($contractSum) > abs($budget))
		{
			$values['budget'] = (string)$values['contract_sum'];
		}

		if (isset($values['addition_rs']) && $values['addition_rs'] !== '' && !$this->isIntegerValue($values['addition_rs']))
		{
			$errors[] = lang('Rig addition') . ': ' . lang('Please enter an integer !');
		}

		if (isset($values['addition_percentage']) && $values['addition_percentage'] !== '' && !$this->isIntegerValue($values['addition_percentage']))
		{
			$errors[] = lang('Percentage addition') . ': ' . lang('Please enter an integer !');
		}

		if (!empty($values['cat_id']))
		{
			$category = $this->readCategory((int)$values['cat_id']);
			if ($category !== null && empty($category['active']))
			{
				$errors[] = lang('invalid category');
			}
		}

		if (!empty($values['approval'])
			&& !empty($configData['workorder_approval'])
			&& !empty($configData['workorder_approval_status']))
		{
			$values['status'] = $configData['workorder_approval_status'];
		}

		if (($configData['invoice_acl'] ?? '') === 'dimb'
			&& !empty($values['do_approve'])
			&& !$this->hasManageAccess())
		{
			$currentAccount = $this->getCurrentAccountId();
			$approvalEcodimb = $projectEcodimb ?: (string)($values['ecodimb'] ?? '');

			foreach ((array)$values['do_approve'] as $accountId => $dummy)
			{
				if ((int)$accountId !== $currentAccount)
				{
					continue;
				}

				$approveRole = $this->checkInvoiceRole($approvalEcodimb);
				$isJanitor = !empty($approveRole['is_janitor']);
				$isSupervisor = !empty($approveRole['is_supervisor']);
				$isBudgetResponsible = !empty($approveRole['is_budget_responsible']);

				if (!$isJanitor && !$isSupervisor && !$isBudgetResponsible)
				{
					$errors[] = lang('you are not approved for this dimb: %1', $approvalEcodimb);
				}

				if (!$isSupervisor && !$isBudgetResponsible)
				{
					$errors[] = lang('you do not have permission to approve this order');
					$values['approved'] = false;
				}
			}
		}

		$state['values'] = $values;
		$state['errors'] = $errors;
		$state['messages'] = $messages;
		return $state;
	}

	public function persistSave(array $state, object $bo): array
	{
		$messages = array_values(array_filter((array)($state['messages'] ?? array()), static function ($msg)
		{
			return is_string($msg) && $msg !== '';
		}));

		if (!empty($state['errors']))
		{
			$state['receipt'] = array(
				'status' => 'error',
				'error' => array_map(static fn(string $msg) => array('msg' => $msg), $state['errors']),
				'message' => array_map(static fn(string $msg) => array('msg' => $msg), $messages),
			);
			return $state;
		}

		$action = !empty($state['is_edit']) ? 'edit' : '';
		$receipt = $bo->save($state['values'], $action, $state['values_attribute']);

		$state['receipt'] = is_array($receipt) ? $receipt : array();
		if (!isset($state['receipt']['message']) || !is_array($state['receipt']['message']))
		{
			$state['receipt']['message'] = array();
		}

		if ($messages)
		{
			$state['receipt']['message'] = array_merge(
				$state['receipt']['message'],
				array_map(static fn(string $msg) => array('msg' => $msg), $messages)
			);
		}

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

	protected function getConfigData(): array
	{
		$config = CreateObject('phpgwapi.config', 'property');
		$config->read();
		return is_array($config->config_data ?? null) ? $config->config_data : array();
	}

	protected function readProjectMini(int $projectId): array
	{
		if ($projectId <= 0)
		{
			return array();
		}

		$boproject = CreateObject('property.boproject');
		$project = $boproject->read_single_mini($projectId);
		return is_array($project) ? $project : array();
	}

	protected function readGeneric(string $type, int $id): array
	{
		if ($id <= 0)
		{
			return array();
		}

		$result = execMethod('property.bogeneric.read_single', array(
			'id' => $id,
			'location_info' => array('type' => $type),
		));

		return is_array($result) ? $result : array();
	}

	protected function readCategory(int $catId): ?array
	{
		if ($catId <= 0)
		{
			return null;
		}

		$boworkorder = CreateObject('property.boworkorder');
		$categoryRows = $boworkorder->cats->return_single($catId);
		if (!is_array($categoryRows) || !isset($categoryRows[0]) || !is_array($categoryRows[0]))
		{
			return null;
		}

		return $categoryRows[0];
	}

	protected function hasManageAccess(): bool
	{
		if (!class_exists('App\\modules\\phpgwapi\\security\\Acl'))
		{
			return false;
		}

		return (bool) \App\modules\phpgwapi\security\Acl::getInstance()->check('.project', 16, 'property');
	}

	protected function getCurrentAccountId(): int
	{
		if (!empty($GLOBALS['phpgw_info']['user']['account_id']))
		{
			return (int)$GLOBALS['phpgw_info']['user']['account_id'];
		}

		return 0;
	}

	protected function checkInvoiceRole(string $ecodimb): array
	{
		if ($ecodimb === '')
		{
			return array();
		}

		$result = execMethod('property.boinvoice.check_role', $ecodimb);
		return is_array($result) ? $result : array();
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
