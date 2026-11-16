<?php

namespace App\modules\emailadmin\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailadminUserController
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

	private function accountName(int $id): string|false
	{
		return (new Accounts())->id2name($id);
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

	private function addressList($values): array
	{
		if (is_string($values))
		{
			$values = preg_split('/\R/', $values) ?: [];
		}
		if (!is_array($values)) return [];
		$values = array_map(static fn($value) => is_scalar($value) ? trim((string) $value) : '', $values);
		return array_values(array_unique(array_filter($values, static fn($value) => $value !== '')));
	}

	public function show(Request $request, Response $response, array $args): Response
	{
		if (!$this->isAdmin()) return $this->json($response, ['error' => lang('Permission denied')], 403);
		$id = (int) ($args['id'] ?? 0);
		$accountName = $id > 0 ? $this->accountName($id) : false;
		if (!$accountName)
		{
			return $this->json($response, ['error' => lang('User not found')], 404);
		}

		$userData = \CreateObject('emailadmin.emailadmin_bo', true)->getUserData($id);
		if (!is_array($userData))
		{
			return $this->json($response, ['error' => lang('User email settings are unavailable')], 404);
		}

		return $this->json($response, ['item' => [
			'accountID' => $id,
			'accountName' => (string) $accountName,
			'mailLocalAddress' => (string) ($userData['mailLocalAddress'] ?? ''),
			'mailAlternateAddress' => $this->addressList($userData['mailAlternateAddress'] ?? []),
			'mailForwardingAddress' => $this->addressList($userData['mailForwardingAddress'] ?? $userData['mailRoutingAddress'] ?? []),
			'accountActive' => ($userData['accountStatus'] ?? '') === 'active',
			'forwardOnly' => ($userData['deliveryMode'] ?? '') === 'forwardOnly',
			'quotaLimit' => (string) ($userData['quotaLimit'] ?? ''),
		]]);
	}

	public function update(Request $request, Response $response, array $args): Response
	{
		if (!$this->isAdmin()) return $this->json($response, ['error' => lang('Permission denied')], 403);
		$id = (int) ($args['id'] ?? 0);
		if ($id < 1 || !$this->accountName($id))
		{
			return $this->json($response, ['error' => lang('User not found')], 404);
		}

		$body = $this->input($request);
		$quotaLimit = trim((string) ($body['quotaLimit'] ?? ''));
		if ($quotaLimit !== '' && (!is_numeric($quotaLimit) || (float) $quotaLimit < 0))
		{
			return $this->json($response, ['error' => lang('Enter a valid quota')], 422);
		}

		$formData = [
			'mailLocalAddress' => trim((string) ($body['mailLocalAddress'] ?? '')),
			'mailAlternateAddress' => $this->addressList($body['mailAlternateAddress'] ?? []),
			'mailForwardingAddress' => $this->addressList($body['mailForwardingAddress'] ?? []),
			'quotaLimit' => $quotaLimit,
			'accountStatus' => !empty($body['accountActive']) ? 'active' : 'disabled',
			'deliveryMode' => !empty($body['forwardOnly']) ? 'forwardOnly' : '',
		];
		if (!\CreateObject('emailadmin.emailadmin_bo', true)->saveUserData($id, $formData))
		{
			return $this->json($response, ['error' => lang('Unable to save user email settings')], 422);
		}
		return $this->json($response, ['saved' => true]);
	}
}
