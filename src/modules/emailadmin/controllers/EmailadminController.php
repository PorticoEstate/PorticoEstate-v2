<?php

namespace App\modules\emailadmin\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailadminController
{
	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}

	private function isAdmin(): bool
	{
		$user = Settings::getInstance()->get('user');
		return !empty($user['apps']['admin']);
	}

	private function denied(Response $response): Response
	{
		return $this->json($response, ['error' => lang('Permission denied')], 403);
	}

	private function businessObject()
	{
		return \CreateObject('emailadmin.emailadmin_bo', true);
	}

	private function input(Request $request): array
	{
		$body = $request->getParsedBody();
		if (!is_array($body))
		{
			$body = json_decode((string) $request->getBody(), true);
		}
		return is_array($body) ? $body : [];
	}

	private function hasProfile(array $profiles, int $id): bool
	{
		foreach ($profiles as $profile)
		{
			if ((int) ($profile['profileID'] ?? 0) === $id) return true;
		}
		return false;
	}

	private function safeProfile(array $profile): array
	{
		foreach (['ea_smtp_auth_password', 'smtpLDAPAdminPW', 'imapAuthPassword', 'imapAdminPW'] as $secret)
		{
			unset($profile[$secret]);
		}
		return $profile;
	}

	private function isEnabled($value): bool
	{
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	private function profileSummary(array $profile): array
	{
		$apps = (array) Settings::getInstance()->get('apps');
		$groupId = (int) ($profile['ea_group'] ?? 0);
		$userId = (int) ($profile['ea_user'] ?? 0);
		$accounts_obj = new Accounts();
		return [
			'profileID' => (int) ($profile['profileID'] ?? 0),
			'description' => (string) ($profile['description'] ?? ''),
			'smtpServer' => (string) ($profile['smtpServer'] ?? ''),
			'imapServer' => (string) ($profile['imapServer'] ?? ''),
			'ea_appname' => empty($profile['ea_appname']) ? lang('any application') : (string) ($apps[$profile['ea_appname']]['title'] ?? $profile['ea_appname']),
			'ea_group' => $groupId ? (string) $accounts_obj->id2name($groupId) : lang('any group'),
			'ea_user' => $userId ? (string) $accounts_obj->id2name($userId) : lang('any user'),
			'ea_active' => (bool) ($profile['ea_active'] ?? false),
			'ea_order' => (int) ($profile['ea_order'] ?? 0),
		];
	}

	public function index(Request $request, Response $response): Response
	{
		if (!$this->isAdmin()) return $this->denied($response);
		$profiles = (array) $this->businessObject()->getProfileList();
		return $this->json($response, ['items' => array_map([$this, 'profileSummary'], $profiles), 'total' => count($profiles)]);
	}

	public function show(Request $request, Response $response, array $args): Response
	{
		if (!$this->isAdmin()) return $this->denied($response);
		$id = (int) ($args['id'] ?? 0);
		$businessObject = $this->businessObject();
		if (!$this->hasProfile((array) $businessObject->getProfileList(), $id))
		{
			return $this->json($response, ['error' => lang('Profile not found')], 404);
		}
		$profile = $businessObject->getProfile($id);
		if (is_array($profile) && (int) ($profile['profileID'] ?? 0) === $id)
		{
			return $this->json($response, ['item' => $this->safeProfile($profile)]);
		}
		return $this->json($response, ['error' => lang('Profile not found')], 404);
	}

	public function store(Request $request, Response $response): Response
	{
		return $this->persist($request, $response, 0);
	}

	public function update(Request $request, Response $response, array $args): Response
	{
		return $this->persist($request, $response, (int) ($args['id'] ?? 0));
	}

	private function persist(Request $request, Response $response, int $id): Response
	{
		if (!$this->isAdmin()) return $this->denied($response);
		$body = $this->input($request);
		$global = (array) ($body['globalsettings'] ?? []);
		$smtp = (array) ($body['smtpsettings'] ?? []);
		$imap = (array) ($body['imapsettings'] ?? []);
		$bo = $this->businessObject();
		$profiles = (array) $bo->getProfileList();

		if ($id && !$this->hasProfile($profiles, $id))
		{
			return $this->json($response, ['error' => lang('Profile not found')], 404);
		}

		$smtpType = (int) ($smtp['smtpType'] ?? 0);
		$imapType = (int) ($imap['imapType'] ?? 0);
		if (!isset($bo->getSMTPServerTypes()[$smtpType]) || !isset($bo->getIMAPServerTypes()[$imapType]))
		{
			return $this->json($response, ['error' => lang('Select valid server types')], 422);
		}
		$smtpFields = $bo->getFieldNames($smtpType, 'smtp');
		$imapFields = $bo->getFieldNames($imapType, 'imap');
		if (!is_array($smtpFields) || !is_array($imapFields))
		{
			return $this->json($response, ['error' => lang('Select valid server types')], 422);
		}

		$globalSettings = [
			'description' => trim((string) ($global['description'] ?? '')),
			'defaultDomain' => trim((string) ($global['defaultDomain'] ?? '')),
			'organisationName' => trim((string) ($global['organisationName'] ?? '')),
			'userDefinedAccounts' => $this->isEnabled($global['userDefinedAccounts'] ?? false) ? 'yes' : 'no',
			'userDefinedIdentities' => $this->isEnabled($global['userDefinedIdentities'] ?? false) ? 'yes' : 'no',
			'ea_active' => $this->isEnabled($global['ea_active'] ?? false) ? 1 : 0,
			'ea_user_defined_signatures' => $this->isEnabled($global['ea_user_defined_signatures'] ?? false) ? 'yes' : 'no',
			'ea_default_signature' => (string) ($global['ea_default_signature'] ?? ''),
			'ea_appname' => trim((string) ($global['ea_appname'] ?? '')),
			'ea_group' => (int) ($global['ea_group'] ?? 0),
			'ea_user' => (int) ($global['ea_user'] ?? 0),
		];
		if ($id) $globalSettings['profileID'] = $id;

		$smtpSettings = $this->serverSettings((array) ($smtp[$smtpType] ?? []), $smtpFields, $smtpType, 'smtp');
		$imapSettings = $this->serverSettings((array) ($imap[$imapType] ?? []), $imapFields, $imapType, 'imap');
		if ($id)
		{
			$current = [];
			foreach ($profiles as $profile)
			{
				if ((int) ($profile['profileID'] ?? 0) === $id) $current = $profile;
			}
			foreach (['ea_smtp_auth_password', 'smtpLDAPAdminPW'] as $secret)
			{
				if (isset($smtpSettings[$secret]) && $smtpSettings[$secret] === '') $smtpSettings[$secret] = $current[$secret] ?? '';
			}
			foreach (['imapAuthPassword', 'imapAdminPW'] as $secret)
			{
				if (isset($imapSettings[$secret]) && $imapSettings[$secret] === '') $imapSettings[$secret] = $current[$secret] ?? '';
			}
		}

		if ($globalSettings['description'] === '')
		{
			return $this->json($response, ['error' => lang('Profile name is required')], 422);
		}

		$savedId = (int) $bo->saveProfile($globalSettings, $smtpSettings, $imapSettings);
		return $this->json($response, ['saved' => true, 'profileID' => $savedId], $id ? 200 : 201);
	}

	private function serverSettings(array $values, array $fields, int $type, string $server): array
	{
		$settings = [];
		$checkboxes = [
			'smtpAuth', 'editforwardingaddress', 'smtpLDAPUseDefault',
			'imapEnableCyrusAdmin', 'imapEnableSieve', 'imapoldcclient',
		];
		foreach ($fields as $field)
		{
			if ($field === 'smtpType' || $field === 'imapType') continue;
			if ($server === 'imap' && $field === 'imapTLSAuthentication')
			{
				$settings[$field] = !empty($values[$field]) ? 0 : 1;
				continue;
			}
			if (in_array($field, $checkboxes, true))
			{
				$settings[$field] = $this->isEnabled($values[$field] ?? false) ? 'yes' : 'no';
				continue;
			}
			$settings[$field] = trim((string) ($values[$field] ?? ''));
		}
		$settings[$server . 'Type'] = (string) $type;
		return $settings;
	}

	public function destroy(Request $request, Response $response, array $args): Response
	{
		if (!$this->isAdmin()) return $this->denied($response);
		$id = (int) ($args['id'] ?? 0);
		$bo = $this->businessObject();
		if (!$this->hasProfile((array) $bo->getProfileList(), $id))
		{
			return $this->json($response, ['error' => lang('Profile not found')], 404);
		}
		$bo->deleteProfile($id);
		return $this->json($response, ['deleted' => true]);
	}

	public function reorder(Request $request, Response $response): Response
	{
		if (!$this->isAdmin()) return $this->denied($response);
		$ids = array_values(array_filter(array_map('intval', (array) ($this->input($request)['profile_ids'] ?? []))));
		$bo = $this->businessObject();
		$profiles = (array) $bo->getProfileList();
		$validIds = array_map(static fn($profile) => (int) ($profile['profileID'] ?? 0), $profiles);
		if (count($ids) !== count($validIds) || count(array_unique($ids)) !== count($ids) || array_diff($ids, $validIds))
		{
			return $this->json($response, ['error' => lang('Invalid profile order')], 422);
		}
		$bo->setOrder($ids);
		return $this->json($response, ['saved' => true]);
	}
}