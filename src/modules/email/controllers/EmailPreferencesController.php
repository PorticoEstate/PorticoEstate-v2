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
			$schema = (new \App\modules\email\services\EmailPreferenceSchema())->fields();
			$userSettings = Settings::getInstance()->get('user');
			$emailPreferences = (array) ($userSettings['preferences']['email'] ?? []);
			$emailPreferences = (new \App\modules\email\services\EmailPreferenceDefaults($schema))->resolve(
				$emailPreferences, 0, (array) $userSettings, (array) Settings::getInstance()->get('server')
			);
			$fields = [];
			$groups = [
				'normal' => ['label' => lang('Standard preferences'), 'fields' => $schema['standard']],
				'custom' => ['label' => lang('Custom settings'), 'fields' => $schema['custom']],
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
						'google_help' => (string) ($field['google_help'] ?? ''),
						'widget' => (string) ($field['widget'] ?? 'textbox'),
						'values' => (array) ($field['values'] ?? []),
						'value' => is_scalar($value) ? (string) $value : '',
						'checked' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
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
		if (!is_array($input))
		{
			$input = json_decode((string) $request->getBody(), true);
		}
		$values = is_array($input) ? ($input['values'] ?? null) : null;
		if (!is_array($values))
		{
			return $this->json($response, ['error' => lang('Invalid email preferences')], 422);
		}

		$originalPost = $_POST;
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('email.api.preferences.save');
			$schema = (new \App\modules\email\services\EmailPreferenceSchema())->fields();
			$allFields = array_merge($schema['standard'], $schema['custom']);
			$normalizedValues = [];
			foreach ($allFields as $field)
			{
				$field = (array) $field;
				$id = (string) ($field['id'] ?? '');
				if ($id === '' || stripos((string) ($field['accts_usage'] ?? ''), 'default') === false || stripos((string) ($field['type'] ?? ''), 'INACTIVE') !== false)
				{
					continue;
				}
				if (($field['widget'] ?? '') === 'checkbox')
				{
					$normalizedValues[$id] = filter_var($values[$id] ?? false, FILTER_VALIDATE_BOOLEAN);
					continue;
				}
				if (!array_key_exists($id, $values))
				{
					continue;
				}
				if (!is_scalar($values[$id]))
				{
					return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				}
				$value = (string) $values[$id];
				if (strlen($value) > 8192)
				{
					return $this->json($response, ['error' => lang('Preference value is too long')], 422);
				}
				if ($id === 'mail_server_port' && $value !== ''
					&& filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false)
				{
					return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				}
				if (($field['widget'] ?? '') === 'combobox' && !array_key_exists($value, (array) ($field['values'] ?? [])))
				{
					return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				}
				$normalizedValues[$id] = $value;
			}
			$mail = \App\modules\email\services\MailMessage::getInstance();
			(new \App\modules\email\services\EmailPreferencesWriter())->saveDefault(
				$schema['standard'],
				$schema['custom'],
				$normalizedValues,
				Preferences::getInstance(),
				$mail
			);
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
			$emailPreferences = (array) (Preferences::getInstance()->read()['email'] ?? []);
			$stored = (array) ($emailPreferences['ex_accounts'] ?? []);
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
			$schema = (new \App\modules\email\services\EmailPreferenceSchema())->fields();
			$userSettings = Settings::getInstance()->get('user');
			$emailPreferences = (array) (Preferences::getInstance()->read()['email'] ?? []);
			$storedAccounts = (array) ($emailPreferences['ex_accounts'] ?? []);
			$account = $accountValue === 'add_new'
				? \App\modules\email\services\EmailPreferencesWriter::nextAccountNumber($storedAccounts)
				: (int) $accountValue;
			if ($accountValue !== 'add_new' && !array_key_exists($account, $storedAccounts))
			{
				return $this->json($response, ['error' => lang('Email account not found')], 404);
			}
			$accountPreferences = (new \App\modules\email\services\EmailPreferenceDefaults($schema))->resolve(
				(array) ($storedAccounts[$account] ?? []), $account,
				(array) $userSettings, (array) Settings::getInstance()->get('server')
			);

			$fields = [];
			foreach (array_merge($schema['standard'], $schema['custom']) as $field)
			{
				$field = (array) $field;
				if (stripos((string) ($field['accts_usage'] ?? ''), 'extra_accounts') === false || stripos((string) ($field['type'] ?? ''), 'INACTIVE') !== false) continue;
				$hidden = stripos((string) ($field['write_props'] ?? ''), 'hidden') !== false;
				$value = $hidden ? '' : ($accountPreferences[$field['id']] ?? '');
				$fields[] = [
					'id' => (string) $field['id'],
					'label' => (string) ($field['lang_blurb'] ?? $field['id']),
					'description' => (string) ($field['long_desc'] ?? ''),
					'google_help' => (string) ($field['google_help'] ?? ''),
					'widget' => (string) ($field['widget'] ?? 'textbox'),
					'values' => (array) ($field['values'] ?? []),
					'value' => is_scalar($value) ? (string) $value : '',
					'checked' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
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
		if (!is_array($input))
		{
			$input = json_decode((string) $request->getBody(), true);
		}
		$input = is_array($input) ? $input : [];
		$account = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
		$values = $input['values'] ?? null;
		if ($account === false || $account < 1 || !is_array($values))
		{
			return $this->json($response, ['error' => lang('Invalid email account')], 422);
		}

		$originalPost = $_POST;
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('email.api.preferences.account.save');
			$schema = (new \App\modules\email\services\EmailPreferenceSchema())->fields();
			$userSettings = Settings::getInstance()->get('user');
			$emailPreferences = (array) (Preferences::getInstance()->read()['email'] ?? []);
			$storedAccounts = (array) ($emailPreferences['ex_accounts'] ?? []);
			if (!array_key_exists($account, $storedAccounts)
				&& $account !== \App\modules\email\services\EmailPreferencesWriter::nextAccountNumber($storedAccounts))
			{
				return $this->json($response, ['error' => lang('Invalid email account')], 422);
			}
			$fields = array_merge($schema['standard'], $schema['custom']);
			$normalizedValues = [];
			foreach ($fields as $field)
			{
				$field = (array) $field;
				$id = (string) ($field['id'] ?? '');
				if ($id === '' || stripos((string) ($field['accts_usage'] ?? ''), 'extra_accounts') === false || stripos((string) ($field['type'] ?? ''), 'INACTIVE') !== false)
				{
					continue;
				}
				if (($field['widget'] ?? '') === 'checkbox')
				{
					$normalizedValues[$id] = filter_var($values[$id] ?? false, FILTER_VALIDATE_BOOLEAN);
					continue;
				}
				if (!array_key_exists($id, $values))
				{
					continue;
				}
				if (!is_scalar($values[$id]))
				{
					return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				}
				$value = (string) $values[$id];
				if (strlen($value) > 8192)
				{
					return $this->json($response, ['error' => lang('Preference value is too long')], 422);
				}
				if ($id === 'mail_server_port' && $value !== ''
					&& filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false)
				{
					return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				}
				if (($field['widget'] ?? '') === 'combobox' && !array_key_exists($value, (array) ($field['values'] ?? [])))
				{
					return $this->json($response, ['error' => lang('Invalid preference value')], 422);
				}
				$normalizedValues[$id] = $value;
			}
			$mail = \App\modules\email\services\MailMessage::getInstance();
			(new \App\modules\email\services\EmailPreferencesWriter())->saveExtraAccount(
				$account,
				$schema['standard'],
				$schema['custom'],
				$normalizedValues,
				Preferences::getInstance(),
				$mail
			);
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
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
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
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('email.api.preferences.account.delete');
			$preferences = Preferences::getInstance();
			(new \App\modules\email\services\EmailPreferencesWriter())->deleteAccount(
				$account, (array) ($emailPreferences['ex_accounts'] ?? []),
				$preferences, \App\modules\email\services\MailMessage::getInstance()
			);
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
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}
}
