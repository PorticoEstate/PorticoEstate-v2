<?php

namespace App\modules\property\helpers;

use App\modules\phpgwapi\services\Settings;

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

		$values = $this->applyRelationInfoPayload($values, $relationInfo);

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
	 * Apply RelationInfo-derived location enrichment for project save payloads.
	 */
	private function applyRelationInfoPayload(array $values, array $relationInfo): array
	{
		$location = array();

		if (isset($values['location']) && is_array($values['location']) && $values['location'])
		{
			foreach ($values['location'] as $key => $part)
			{
				if ((string)$part === '')
				{
					continue;
				}

				if (is_string($key) && preg_match('/^loc\d+$/', $key))
				{
					$location[$key] = $part;
				}
				else
				{
					$location['loc' . (count($location) + 1)] = $part;
				}
			}
		}

		if (!$location)
		{
			for ($i = 1; $i <= 10; $i++)
			{
				$field = 'loc' . $i;
				if (array_key_exists($field, $values) && (string)$values[$field] !== '')
				{
					$location[$field] = $values[$field];
				}
			}
		}

		if (!$location)
		{
			$locationCode = '';
			if (isset($values['location_code']))
			{
				$locationCode = trim((string)$values['location_code']);
			}
			else if (isset($relationInfo['location_code']))
			{
				$locationCode = trim((string)$relationInfo['location_code']);
			}

			if ($locationCode !== '')
			{
				$locationParts = array_values(array_filter(explode('-', $locationCode), static function ($part)
				{
					return $part !== '';
				}));
				if ($locationParts)
				{
					foreach ($locationParts as $index => $part)
					{
						$location['loc' . ($index + 1)] = $part;
					}
				}
			}
		}

		if ($location)
		{
			$values['location'] = $location;
			if (!isset($values['location_code']) || $values['location_code'] === '')
			{
				$values['location_code'] = implode('-', array_values($location));
			}
		}

		return $values;
	}

	/**
	 * Keep initial validation minimal and non-breaking while write APIs are introduced.
	 */
	public function validate(array $state): array
	{
		$values = $state['values'] ?? array();
		$errors = $state['errors'] ?? array();

		if ($this->isRepost())
		{
			$errors[] = lang('Hmm... looks like a repost!');
		}

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

	protected function isRepost(): bool
	{
		if (!class_exists('phpgw') || !method_exists('phpgw', 'is_repost'))
		{
			return false;
		}

		return (bool) \phpgw::is_repost();
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
		$state = $this->applyNotifyWorkflow($state);
		return $state;
	}

	protected function applyNotifyWorkflow(array $state): array
	{
		$id = (int)($state['id'] ?? 0);
		$values = is_array($state['values'] ?? null) ? $state['values'] : array();
		if ($id <= 0 || empty($values))
		{
			return $state;
		}

		$serverSettings = Settings::getInstance()->get('server');
		$serverSettings = is_array($serverSettings) ? $serverSettings : array();
		if (empty($serverSettings['smtp_server']))
		{
			return $state;
		}

		$user = Settings::getInstance()->get('user');
		$user = is_array($user) ? $user : array();
		$apps = Settings::getInstance()->get('apps');
		$apps = is_array($apps) ? $apps : array();

		$accountId = (int)($user['account_id'] ?? 0);
		$userPreferences = is_array($user['preferences'] ?? null) ? $user['preferences'] : array();
		$commonPreferences = is_array($userPreferences['common'] ?? null) ? $userPreferences['common'] : array();
		$propertyPreferences = is_array($userPreferences['property'] ?? null) ? $userPreferences['property'] : array();

		$historylog = CreateObject('property.historylog', 'project');
		$send = CreateObject('phpgwapi.send');
		$boCommon = CreateObject('property.bocommon');

		$toArray = array();
		$toArraySms = array();
		$noticeOwner = (array)($state['receipt']['notice_owner'] ?? array());

		if (!empty($state['receipt']['notice_owner']) && is_array($state['receipt']['notice_owner']))
		{
			$coordinatorId = (int)($values['coordinator'] ?? 0);
			if ($accountId !== $coordinatorId
				&& !empty($propertyPreferences['notify_project_owner']))
			{
				$prefsCoordinator = $boCommon->create_preferences('common', $coordinatorId);
				if (!empty($prefsCoordinator['email']))
				{
					$toArray[] = (string)$prefsCoordinator['email'];
				}
			}
		}

		$locationId = 0;
		$locations = CreateObject('phpgwapi.locations');
		if ($locations && method_exists($locations, 'get_id'))
		{
			$locationId = (int)$locations->get_id('property', '.project');
		}

		$notifyList = execMethod('property.notify.read', array(
			'location_id' => $locationId,
			'location_item_id' => $id,
		));
		$notifyList = is_array($notifyList) ? $notifyList : array();

		$subject = lang('project %1 has been edited', $id);

		if (!empty($apps['sms']))
		{
			$smsText = "{$subject}. \r\n" . (string)($user['fullname'] ?? '') . " \r\n" . (string)($commonPreferences['email'] ?? '');
			$sms = CreateObject('sms.sms');

			foreach ($notifyList as $entry)
			{
				if (!empty($entry['is_active'])
					&& ($entry['notification_method'] ?? '') === 'sms'
					&& !empty($entry['sms']))
				{
					$sms->websend2pv($accountId, $entry['sms'], $smsText);
					$toArraySms[] = "{$entry['first_name']} {$entry['last_name']}({$entry['sms']})";
					$this->appendReceiptMessage($state, lang('%1 is notified', "{$entry['first_name']} {$entry['last_name']}"));
				}
			}

			if ($toArraySms)
			{
				$historylog->add('MS', $id, implode(',', $toArraySms));
			}
		}

		foreach ($notifyList as $entry)
		{
			if (!empty($entry['is_active'])
				&& ($entry['notification_method'] ?? '') === 'email'
				&& !empty($entry['email']))
			{
				$toArray[] = "{$entry['first_name']} {$entry['last_name']}<{$entry['email']}>";
			}
		}

		if (!$toArray)
		{
			return $state;
		}

		$to = implode(';', $toArray);
		$fromName = (string)($user['fullname'] ?? '');
		$fromEmail = (string)($commonPreferences['email'] ?? '');

		$body = '<a href ="' . \phpgw::link('/index.php', array(
			'menuaction' => 'property.uiproject.edit',
			'id' => $id,
		), false, true) . '">' . lang('project %1 has been edited', $id) . '</a>' . "\n";

		foreach ($noticeOwner as $notice)
		{
			$body .= $notice . "\n";
		}

		$body .= lang('Altered by') . ': ' . $fromName . "\n";
		$body .= lang('remark') . ': ' . (string)($values['remark'] ?? '') . "\n";
		$body = nl2br($body);

		$returnCode = false;
		try
		{
			$returnCode = (bool)$send->msg('email', $to, $subject, $body, false, false, false, $fromEmail, $fromName, 'html');
		}
		catch (\Exception $e)
		{
			$this->appendReceiptError($state, $e->getMessage());
		}

		if (!$returnCode)
		{
			$this->appendReceiptError($state, "uiproject::edit: sending message to '{$to}' subject='{$subject}' failed !!!");
			if (isset($send->err['desc']))
			{
				$this->appendReceiptError($state, (string)$send->err['desc']);
			}
			return $state;
		}

		$historylog->add('ON', $id, lang('%1 is notified', $to));
		$this->appendReceiptMessage($state, lang('%1 is notified', $to));

		return $state;
	}

	private function appendReceiptMessage(array &$state, string $msg): void
	{
		if (!isset($state['receipt']) || !is_array($state['receipt']))
		{
			$state['receipt'] = array();
		}

		if (!isset($state['receipt']['message']) || !is_array($state['receipt']['message']))
		{
			$state['receipt']['message'] = array();
		}

		$state['receipt']['message'][] = array('msg' => $msg);
	}

	private function appendReceiptError(array &$state, string $msg): void
	{
		if (!isset($state['receipt']) || !is_array($state['receipt']))
		{
			$state['receipt'] = array();
		}

		if (!isset($state['receipt']['error']) || !is_array($state['receipt']['error']))
		{
			$state['receipt']['error'] = array();
		}

		$state['receipt']['error'][] = array('msg' => $msg);
	}
}
