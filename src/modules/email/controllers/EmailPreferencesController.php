<?php

namespace App\modules\email\controllers;

use App\modules\phpgwapi\services\Preferences;
//settings
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailPreferencesController
{
	public function index(Request $request, Response $response): Response
	{
		try
		{
			$businessObject = \CreateObject('email.bopreferences');
			$businessObject->init_available_prefs();
			$userSettings = Settings::getInstance()->get('user');
			$emailPreferences = (array) ($userSettings['preferences']['email'] ?? []);
			$fields = [];
			$groups = [
				'normal' => ['label' => lang('Standard preferences'), 'fields' => (array) $businessObject->std_prefs],
				'custom' => ['label' => lang('Custom settings'), 'fields' => (array) $businessObject->cust_prefs],
			];
			foreach ($groups as $groupId => $group)
			{
				foreach ($group['fields'] as $field)
				{
					$field = (array) $field;
					if (stripos((string) ($field['accts_usage'] ?? ''), 'default') === false || stripos((string) ($field['type'] ?? ''), 'INACTIVE') !== false)
					{
						continue;
					}
					$hidden = stripos((string) ($field['write_props'] ?? ''), 'hidden') !== false;
					$value = $hidden ? '' : ($emailPreferences[$field['id']] ?? '');
					$fields[] = [
						'id' => (string) $field['id'],
						'group' => $groupId,
						'label' => (string) ($field['lang_blurb'] ?? $field['id']),
						'description' => (string) ($field['long_desc'] ?? ''),
						'widget' => (string) ($field['widget'] ?? 'textbox'),
						'values' => (array) ($field['values'] ?? []),
						'value' => is_scalar($value) ? (string) $value : '',
						'checked' => !empty($value),
						'hidden' => $hidden,
					];
				}
			}
			return $this->json($response, ['fields' => $fields, 'groups' => [
				'normal' => $groups['normal']['label'],
				'custom' => $groups['custom']['label'],
			]]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailPreferencesController::index failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to load email preferences'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	public function save(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$values = is_array($input) ? ($input['values'] ?? null) : null;
		if (!is_array($values)) return $this->json($response, ['error' => lang('Invalid email preferences')], 422);

		$originalPost = $_POST;
		try
		{
			$businessObject = \CreateObject('email.bopreferences');
			$businessObject->init_available_prefs();
			$schema = array_merge((array) $businessObject->std_prefs, (array) $businessObject->cust_prefs);
			$legacyPost = [$businessObject->submit_token => '1'];
			foreach ($schema as $field)
			{
				$field = (array) $field;
				$id = (string) ($field['id'] ?? '');
				if ($id === '' || stripos((string) ($field['accts_usage'] ?? ''), 'default') === false || stripos((string) ($field['type'] ?? ''), 'INACTIVE') !== false) continue;
				if (($field['widget'] ?? '') === 'checkbox')
				{
					if (filter_var($values[$id] ?? false, FILTER_VALIDATE_BOOLEAN)) $legacyPost[$id] = 'True';
					continue;
				}
				if (!array_key_exists($id, $values)) continue;
				if (!is_scalar($values[$id])) return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				$value = (string) $values[$id];
				if (strlen($value) > 8192) return $this->json($response, ['error' => lang('Preference value is too long')], 422);
				if (($field['widget'] ?? '') === 'combobox' && !array_key_exists($value, (array) ($field['values'] ?? [])))
				{
					return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				}
				$legacyPost[$id] = $value;
			}
			$_POST = array_replace($originalPost, $legacyPost);
			if (!$businessObject->preferences(true)) return $this->json($response, ['error' => lang('Unable to save email preferences')], 422);
			return $this->json($response, ['saved' => true]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailPreferencesController::save failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to save email preferences'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			$_POST = $originalPost;
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	public function accounts(Request $request, Response $response): Response
	{
		try
		{
			$userSettings = Settings::getInstance()->get('user');
			$stored = (array) ($userSettings['preferences']['email']['ex_accounts'] ?? []);
			$accounts = \App\modules\email\services\EmailAccountsSerializer::serialize($stored);
			return $this->json($response, ['items' => $accounts]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailPreferencesController::accounts failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to load email accounts'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	public function accountEditor(Request $request, Response $response): Response
	{
		$accountValue = (string) ($request->getQueryParams()['ex_acctnum'] ?? 'add_new');
		if ($accountValue !== 'add_new' && (!ctype_digit($accountValue) || (int) $accountValue < 1))
		{
			return $this->json($response, ['error' => lang('Invalid email account')], 400);
		}

		try
		{
			$businessObject = \CreateObject('email.bopreferences');
			$businessObject->init_available_prefs();
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$userSettings = Settings::getInstance()->get('user');
			$storedAccounts = (array) ($userSettings['preferences']['email']['ex_accounts'] ?? []);
			$account = $accountValue === 'add_new' ? (int) $businessObject->get_first_empty_ex_acctnum() : (int) $accountValue;
			if ($accountValue !== 'add_new' && !array_key_exists($account, $storedAccounts))
			{
				return $this->json($response, ['error' => lang('Email account not found')], 404);
			}
			$accountPreferences = (array) $userSettings['preferences']['email']['ex_accounts'][$account] ?? [];

			$fields = [];
			foreach (array_merge((array) $businessObject->std_prefs, (array) $businessObject->cust_prefs) as $field)
			{
				$field = (array) $field;
				if (stripos((string) ($field['accts_usage'] ?? ''), 'extra_accounts') === false || stripos((string) ($field['type'] ?? ''), 'INACTIVE') !== false) continue;
				$hidden = stripos((string) ($field['write_props'] ?? ''), 'hidden') !== false;
				$value = $hidden ? '' : ($accountPreferences[$field['id']] ?? '');
				$fields[] = [
					'id' => (string) $field['id'],
					'label' => (string) ($field['lang_blurb'] ?? $field['id']),
					'description' => (string) ($field['long_desc'] ?? ''),
					'widget' => (string) ($field['widget'] ?? 'textbox'),
					'values' => (array) ($field['values'] ?? []),
					'value' => is_scalar($value) ? (string) $value : '',
					'checked' => !empty($value),
					'hidden' => $hidden,
				];
			}
			return $this->json($response, [
				'id' => $account,
				'isNew' => $accountValue === 'add_new',
				'fields' => $fields,
			]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailPreferencesController::accountEditor failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to load email account'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	public function saveAccount(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$input = is_array($input) ? $input : [];
		$account = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
		$values = $input['values'] ?? null;
		if ($account === false || $account < 1 || !is_array($values)) return $this->json($response, ['error' => lang('Invalid email account')], 422);

		$originalPost = $_POST;
		try
		{
			$businessObject = \CreateObject('email.bopreferences');
			$businessObject->init_available_prefs();
			$userSettings = Settings::getInstance()->get('user');
			$storedAccounts = (array) ($userSettings['preferences']['email']['ex_accounts'] ?? []);
			if (!array_key_exists($account, $storedAccounts) && $account !== (int) $businessObject->get_first_empty_ex_acctnum())
			{
				return $this->json($response, ['error' => lang('Invalid email account')], 422);
			}
			$fields = array_merge((array) $businessObject->std_prefs, (array) $businessObject->cust_prefs);
			$legacyValues = [];
			foreach ($fields as $field)
			{
				$field = (array) $field;
				$id = (string) ($field['id'] ?? '');
				if ($id === '' || stripos((string) ($field['accts_usage'] ?? ''), 'extra_accounts') === false || stripos((string) ($field['type'] ?? ''), 'INACTIVE') !== false) continue;
				if (($field['widget'] ?? '') === 'checkbox')
				{
					if (filter_var($values[$id] ?? false, FILTER_VALIDATE_BOOLEAN)) $legacyValues[$id] = 'True';
					continue;
				}
				if (!array_key_exists($id, $values)) continue;
				if (!is_scalar($values[$id])) return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				$value = (string) $values[$id];
				if (strlen($value) > 8192) return $this->json($response, ['error' => lang('Preference value is too long')], 422);
				if (($field['widget'] ?? '') === 'combobox' && !array_key_exists($value, (array) ($field['values'] ?? []))) return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				$legacyValues[$id] = $value;
			}
			$_POST = array_replace($originalPost, [
				$businessObject->submit_token_extra_accounts => '1',
				'ex_acctnum' => $account,
				$account => $legacyValues,
			]);
			if (!$businessObject->ex_accounts_edit($account, true)) return $this->json($response, ['error' => lang('Unable to save email account')], 422);
			return $this->json($response, ['saved' => true, 'id' => $account]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailPreferencesController::saveAccount failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to save email account'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			$_POST = $originalPost;
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	public function deleteAccount(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$account = is_array($input) ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) : false;
		if ($account === false || $account < 1) return $this->json($response, ['error' => lang('Invalid email account')], 422);
		try
		{
			$userSettings = Settings::getInstance()->get('user');
			$emailPreferences = (array) ($userSettings['preferences']['email'] ?? []);
			if (!array_key_exists($account, (array) ($emailPreferences['ex_accounts'] ?? [])))
			{
				return $this->json($response, ['error' => lang('Email account not found')], 404);
			}
			$preferences = Preferences::getInstance();
			$preferences->delete_struct('email', '["ex_accounts"][' . $account . ']');
			$preferences->save_repository();
			return $this->json($response, ['deleted' => true]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailPreferencesController::deleteAccount failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to delete email account'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}
}
