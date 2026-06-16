<?php

namespace App\modules\property\helpers;

use App\modules\phpgwapi\services\Settings;

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

			if (array_key_exists($field, $requestData) && !array_key_exists($field, $values))
			{
				$values[$field] = $requestData[$field];
			}
		}

		$values = $this->normalizeLocationFields($values);

		if (!isset($values['extra']) || !is_array($values['extra']))
		{
			$values['extra'] = array();
		}

		$extraRelationFields = array(
			'tenant_id',
			'p_num',
			'p_entity_id',
			'p_cat_id',
			'contact_phone',
		);
		foreach ($extraRelationFields as $field)
		{
			if (array_key_exists($field, $values) && !array_key_exists($field, $values['extra']))
			{
				$values['extra'][$field] = $values[$field];
			}
		}

		// Keep origin metadata in top-level values/relation context, never in legacy extra payload.
		unset($values['extra']['origin'], $values['extra']['origin_id']);

		$legacyContextFields = array(
			'project_id',
			'origin',
			'origin_id',
			'location_code',
			'location_name',
			'street_name',
			'street_number',
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
			'copy_workorder_from',
		);

		foreach ($legacyContextFields as $field)
		{
			if (array_key_exists($field, $requestData) && !array_key_exists($field, $values))
			{
				$values[$field] = $requestData[$field];
			}
		}

		$values = $this->mergeAdditionalInfoFromPayload($values, $requestData);

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

		if (empty($state['is_edit']) && !empty($values['copy_workorder']))
		{
			$copyFromId = (int)($values['copy_workorder_from'] ?? 0);
			if ($copyFromId > 0)
			{
				$sourceWorkorder = $this->readWorkorderSingle($copyFromId);
				$sourceBudget = (int)($sourceWorkorder['budget'] ?? 0);
				$values['budget'] = (string)($sourceBudget !== 0 ? $sourceBudget : 1);
			}
		}

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

		$ecodimbWasEmptyBeforeFallback = empty($values['ecodimb']);

		if ($ecodimbWasEmptyBeforeFallback)
		{
			$values['ecodimb'] = $projectEcodimb;
		}

		if (empty($values['ecodimb']))
		{
			$errors[] = lang('Please select dimb!');
		}
		else if ($ecodimbWasEmptyBeforeFallback)
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

		if (isset($values['addition_percentage'])
			&& $values['addition_percentage']
			&& !$this->isUnsignedIntegerValue($values['addition_percentage']))
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
		$state = $this->applyApprovalWorkflow($state, $bo);
		$state = $this->applyNotifyWorkflow($state);
		return $state;
	}

	protected function applyApprovalWorkflow(array $state, object $bo): array
	{
		$id = (int)($state['id'] ?? 0);
		$values = is_array($state['values'] ?? null) ? $state['values'] : array();
		if ($id <= 0 || empty($values))
		{
			return $state;
		}

		$configData = $this->getConfigData();
		$workorderStatus = execMethod('property.bogeneric.read_single', array(
			'id' => $values['status'] ?? null,
			'location_info' => array('type' => 'workorder_status')
		));
		$workorderStatus = is_array($workorderStatus) ? $workorderStatus : array();

		$send = CreateObject('phpgwapi.send');
		$sosubstitute = CreateObject('property.sosubstitute');
		$historylog = CreateObject('property.historylog', 'workorder');
		$pendingAction = CreateObject('property.sopending_action');
		$boCommon = CreateObject('property.bocommon');
		$accountsObj = new \App\modules\phpgwapi\controllers\Accounts\Accounts();

		$currentUser = Settings::getInstance()->get('user');
		$currentUser = is_array($currentUser) ? $currentUser : array();
		$currentPrefs = is_array($currentUser['preferences']['common'] ?? null) ? $currentUser['preferences']['common'] : array();
		$serverSettings = Settings::getInstance()->get('server');
		$serverSettings = is_array($serverSettings) ? $serverSettings : array();

		$coordinatorName = (string)($currentUser['fullname'] ?? '');
		$coordinatorEmail = (string)($currentPrefs['email'] ?? '');

		$budgetAmount = (int)$bo->get_budget_amount($id);

		if (empty($workorderStatus['closed'])
			&& !empty($values['approval'])
			&& !empty($configData['workorder_approval']))
		{
			if (empty($serverSettings['smtp_server']))
			{
				$this->appendReceiptError($state, lang('SMTP server is not set! (admin section)'));
			}

			$approvalLevel = !empty($configData['approval_level']) ? (string)$configData['approval_level'] : 'order';

			switch ($approvalLevel)
			{
				case 'project':
					$projectId = (int)($values['project_id'] ?? 0);
					if ($projectId > 0)
					{
						$subject = lang('Approval') . ": {$projectId}";
						$message = '<a href ="' . \phpgw::link('/index.php', array(
							'menuaction' => 'property.uiproject.edit',
							'id' => $projectId
						), false, true) . '">' . lang('project %1 needs approval', $projectId) . '</a>';

						$budgetAmount = (int)$bo->get_accumulated_budget_amount($projectId);

						foreach ((array)$values['approval'] as $accountId => $dummy)
						{
							$accountId = (int)$accountId;
							if ($accountId <= 0)
							{
								continue;
							}

							$actionParamsApproved = array(
								'appname' => 'property',
								'location' => '.project',
								'id' => $projectId,
								'responsible' => $accountId,
								'responsible_type' => 'user',
								'action' => 'approval',
								'remark' => '',
								'deadline' => '',
								'closed' => true,
								'data' => array('limit' => $budgetAmount)
							);

							$approvals = $pendingAction->get_pending_action($actionParamsApproved);
							$approvals = is_array($approvals) ? $approvals : array();
							$approved = false;

							if (!empty($approvals[0]['action_performed']))
							{
								if (isset($approvals[0]['data']['limit']) && (int)$approvals[0]['data']['limit'] >= $budgetAmount)
								{
									$approved = true;
								}
								else if (empty($approvals[0]['data']['limit']))
								{
									$approved = true;
								}
							}

							if (!$approved)
							{
								$substitute = $sosubstitute->get_substitute($accountId);
								$notifyOnRequest = array($accountId);
								if ($substitute)
								{
									$notifyOnRequest[] = (int)$substitute;
								}

								$pendingAction->set_pending_action($actionParamsApproved);

								if (!empty($configData['project_approval_status']))
								{
									createObject('property.soproject')->set_status($projectId, $configData['project_approval_status']);
								}

								$toArray = array();
								foreach ($notifyOnRequest as $notifyAccountId)
								{
									$toArray[] = $this->resolveNotificationEmail((int)$notifyAccountId, $boCommon, $accountsObj, $serverSettings);
								}

								try
								{
									CreateObject('property.historylog', 'project')->add('AP', $projectId, $this->accountName($accountsObj, $accountId) . "::{$budgetAmount}");
									$to = implode(';', array_filter($toArray));
									if ($to)
									{
										$rcpt = $send->msg('email', $to, $subject, stripslashes($message), '', '', '', $coordinatorEmail, $coordinatorName, 'html');
										if ($rcpt)
										{
											$this->appendReceiptMessage($state, lang('%1 is notified', $to));
										}
									}
								}
								catch (\Exception $exc)
								{
									$this->appendReceiptError($state, $exc->getMessage());
								}

								$orderApprovalParams = array(
									'appname' => 'property',
									'location' => '.project.workorder',
									'id' => $id,
									'responsible' => $accountId,
									'responsible_type' => 'user',
									'action' => 'approval',
									'remark' => '',
									'deadline' => ''
								);

								if (!execMethod('property.sopending_action.get_pending_action', $orderApprovalParams))
								{
									execMethod('property.sopending_action.set_pending_action', $orderApprovalParams);
								}
							}
							else
							{
								$orderApprovalParams = array(
									'appname' => 'property',
									'location' => '.project.workorder',
									'id' => $id,
									'responsible' => $accountId,
									'responsible_type' => 'user',
									'action' => 'approval',
									'remark' => '',
									'deadline' => ''
								);

								if (!execMethod('property.sopending_action.get_pending_action', $orderApprovalParams))
								{
									execMethod('property.sopending_action.set_pending_action', $orderApprovalParams);
								}
								execMethod('property.sopending_action.close_pending_action', $orderApprovalParams);

								$langImplicitly = lang('implicitly from project');
								$historylog->add('OA', $id, $this->accountName($accountsObj, $accountId) . ", {$langImplicitly}::{$budgetAmount}");
							}
						}
					}
					break;

				default:
					$subject = lang('Approval') . ": {$id}";
					$message = '<a href ="' . \phpgw::link('/index.php', array(
						'menuaction' => 'property.uiworkorder.edit',
						'id' => $id
					), false, true) . '">' . lang('Workorder %1 needs approval', $id) . '</a>';

					$orderIds = array($id);
					$actionParams = array(
						'appname' => 'property',
						'location' => '.project.workorder',
						'id' => $id,
						'responsible' => '',
						'responsible_type' => 'user',
						'action' => 'approval',
						'remark' => '',
						'deadline' => ''
					);

					foreach ((array)$values['approval'] as $accountId => $dummy)
					{
						$accountId = (int)$accountId;
						if ($accountId <= 0)
						{
							continue;
						}

						$substitute = $sosubstitute->get_substitute($accountId);
						$notifyOnRequest = array($accountId);
						if ($substitute)
						{
							$notifyOnRequest[] = (int)$substitute;
						}

						$toArray = array();
						foreach ($notifyOnRequest as $notifyAccountId)
						{
							$toArray[] = $this->resolveNotificationEmail((int)$notifyAccountId, $boCommon, $accountsObj, $serverSettings);
						}

						$to = implode(';', array_filter($toArray));

						foreach ($orderIds as $orderId)
						{
							$actionParams['responsible'] = $accountId;
							$actionParams['id'] = $orderId;

							try
							{
								$historylog->add('AP', $id, $this->accountName($accountsObj, $accountId) . "::{$budgetAmount}");
								execMethod('property.sopending_action.set_pending_action', $actionParams);
								if ($to)
								{
									$rcpt = $send->msg('email', $to, $subject, stripslashes($message), '', '', '', $coordinatorEmail, $coordinatorName, 'html');
									if ($rcpt)
									{
										$this->appendReceiptMessage($state, lang('%1 is notified', $to));
									}
								}
							}
							catch (\Exception $exc)
							{
								$this->appendReceiptError($state, $exc->getMessage());
							}
						}
					}
					break;
			}
		}

		if (!empty($values['do_approve']) && is_array($values['do_approve']))
		{
			$actionParams = array(
				'appname' => 'property',
				'location' => '.project.workorder',
				'id' => $id,
				'responsible' => '',
				'responsible_type' => 'user',
				'action' => 'approval',
				'remark' => '',
				'deadline' => ''
			);

			foreach ((array)$values['do_approve'] as $accountId => $dummy)
			{
				$accountId = (int)$accountId;
				if ($accountId <= 0)
				{
					continue;
				}

				$usersForSubstitute = $sosubstitute->get_users_for_substitute($accountId);
				$usersForSubstitute = is_array($usersForSubstitute) ? $usersForSubstitute : array();

				$approvals = execMethod('property.sopending_action.get_pending_action', $actionParams);
				$approvals = is_array($approvals) ? $approvals : array();

				$takeResponsibilityFor = array($accountId);
				foreach ($approvals as $approval)
				{
					if (!empty($approval['responsible']) && in_array($approval['responsible'], $usersForSubstitute))
					{
						$takeResponsibilityFor[] = (int)$approval['responsible'];
					}
				}

				foreach ($takeResponsibilityFor as $responsibleAccountId)
				{
					$actionParams['responsible'] = (int)$responsibleAccountId;
					if (!execMethod('property.sopending_action.get_pending_action', $actionParams))
					{
						execMethod('property.sopending_action.set_pending_action', $actionParams);
					}
					execMethod('property.sopending_action.close_pending_action', $actionParams);
					$historylog->add('OA', $id, $this->accountName($accountsObj, (int)$responsibleAccountId) . "::{$budgetAmount}");
				}

				unset($actionParams['responsible']);
			}
		}

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

		$currentUser = Settings::getInstance()->get('user');
		$currentUser = is_array($currentUser) ? $currentUser : array();
		$currentCommonPrefs = is_array($currentUser['preferences']['common'] ?? null) ? $currentUser['preferences']['common'] : array();
		$configData = $this->getConfigData();

		$accountId = (int)($currentUser['account_id'] ?? 0);
		$historylog = CreateObject('property.historylog', 'workorder');
		$boCommon = CreateObject('property.bocommon');

		$toArray = array();
		$toArraySms = array();
		$receiptNoticeOwner = (array)($state['receipt']['notice_owner'] ?? array());

		if (!empty($state['receipt']['notice_owner']) && is_array($state['receipt']['notice_owner']))
		{
			$project = !empty($values['project_id'])
				? CreateObject('property.boproject')->read_single_mini((int)$values['project_id'])
				: array();

			$projectCoordinator = (int)($project['coordinator'] ?? 0);
			if ($accountId !== $projectCoordinator
				&& !empty($configData['notify_project_owner'])
				&& !empty($configData['mailnotification']))
			{
				$prefsCoordinator = $boCommon->create_preferences('common', $projectCoordinator);
				if (!empty($prefsCoordinator['email']))
				{
					$toArray[] = (string)$prefsCoordinator['email'];
				}
			}

			$orderUserId = (int)($values['user_id'] ?? 0);
			if ($accountId !== $orderUserId && $orderUserId > 0)
			{
				$prefsUser = $boCommon->create_preferences('common', $orderUserId);
				if (!empty($prefsUser['email']))
				{
					$toArray[] = (string)$prefsUser['email'];
				}
			}
		}

		$locationId = 0;
		if (!empty($GLOBALS['phpgw']->locations) && method_exists($GLOBALS['phpgw']->locations, 'get_id'))
		{
			$locationId = (int)$GLOBALS['phpgw']->locations->get_id('property', '.project.workorder');
		}
		else
		{
			$locations = CreateObject('phpgwapi.locations');
			if ($locations && method_exists($locations, 'get_id'))
			{
				$locationId = (int)$locations->get_id('property', '.project.workorder');
			}
		}

		$notifyList = execMethod('property.notify.read', array(
			'location_id' => $locationId,
			'location_item_id' => $id,
		));
		$notifyList = is_array($notifyList) ? $notifyList : array();

		$subject = lang('workorder %1 has been edited', $id);
		if (!empty($currentUser['apps']['sms']))
		{
			$smsText = "{$subject}. \r\n" . (string)($currentUser['fullname'] ?? '') . " \r\n" . (string)($currentCommonPrefs['email'] ?? '');
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
		$fromName = (string)($currentUser['fullname'] ?? '');
		$fromEmail = (string)($currentCommonPrefs['email'] ?? '');

		$body = '<a href ="' . \phpgw::link('/index.php', array(
			'menuaction' => 'property.uiworkorder.edit',
			'id' => $id,
		), false, true) . '">' . lang('workorder %1 has been edited', $id) . '</a>' . "\n";

		foreach ($receiptNoticeOwner as $notice)
		{
			$body .= $notice . "\n";
		}

		$body .= lang('Altered by') . ': ' . $fromName . "\n";
		if (empty($values['remark']))
		{
			$body .= lang('remark') . ': ' . (string)($values['remark'] ?? '') . "\n";
		}

		$body = nl2br($body);
		$send = CreateObject('phpgwapi.send');

		try
		{
			$send->msg('email', $to, $subject, $body, false, false, false, $fromEmail, $fromName, 'html');
			$historylog->add('ON', $id, lang('%1 is notified', $to));
			$this->appendReceiptMessage($state, lang('%1 is notified', $to));
		}
		catch (\Exception $e)
		{
			$this->appendReceiptError($state, "uiworkorder::edit: sending message to '{$to}' subject='{$subject}' failed !!!");
			if (isset($send->err['desc']))
			{
				$this->appendReceiptError($state, (string)$send->err['desc']);
			}
		}

		return $state;
	}

	private function resolveNotificationEmail(int $accountId, $boCommon, $accountsObj, array $serverSettings): string
	{
		if ($accountId <= 0)
		{
			return '';
		}

		$prefs = $boCommon->create_preferences('common', $accountId);
		if (!empty($prefs['email']))
		{
			return (string)$prefs['email'];
		}

		$emailDomain = !empty($serverSettings['email_domain']) ? (string)$serverSettings['email_domain'] : 'bergen.kommune.no';
		$lid = (string)$accountsObj->id2lid($accountId);
		if ($lid === '')
		{
			return '';
		}

		return "{$lid}@{$emailDomain}";
	}

	private function accountName($accountsObj, int $accountId): string
	{
		try
		{
			$account = $accountsObj->get($accountId);
			if ($account)
			{
				return (string)$account;
			}
		}
		catch (\Throwable $e)
		{
		}

		return (string)$accountId;
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

	protected function readWorkorderSingle(int $workorderId): array
	{
		if ($workorderId <= 0)
		{
			return array();
		}

		$boworkorder = CreateObject('property.boworkorder');
		$workorder = $boworkorder->read_single($workorderId);
		return is_array($workorder) ? $workorder : array();
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
		$user = Settings::getInstance()->get('user');
		if (!empty($user['account_id']))
		{
			return (int)$user['account_id'];
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

	private function normalizeLocationFields(array $values): array
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

		if (!$location && isset($values['location_code']))
		{
			$locationCode = trim((string)$values['location_code']);
			if ($locationCode !== '')
			{
				$locationParts = array_values(array_filter(explode('-', $locationCode), static function ($part)
				{
					return $part !== '';
				}));
				foreach ($locationParts as $index => $part)
				{
					$location['loc' . ($index + 1)] = $part;
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

	protected function mergeAdditionalInfoFromPayload(array $values, array $requestData): array
	{
		return BoCommon::mergeAdditionalInfoFromPayload($values, $requestData);
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

	private function isUnsignedIntegerValue($value): bool
	{
		if (is_int($value))
		{
			return $value >= 0;
		}

		if (is_string($value))
		{
			$value = trim($value);
			return $value !== '' && ctype_digit($value);
		}

		return false;
	}
}
