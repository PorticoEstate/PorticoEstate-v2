<?php

namespace App\modules\email\controllers;

use App\modules\email\services\MailMessage;
use App\modules\email\services\MicrosoftImapOAuth;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailMicrosoftOAuthController
{
	public function authorize(Request $request, Response $response): Response
	{
		$account = filter_var($request->getQueryParams()['acctnum'] ?? 0, FILTER_VALIDATE_INT);
		if ($account === false || $account < 0) return $this->error($response, 'Invalid email account', 400);
		$profile = $this->resolvedProfile();
		if (!MicrosoftImapOAuth::isConfigured($profile)) return $this->error($response, 'Microsoft Outlook OAuth is not configured on the emailadmin profile assigned to your account', 503);

		try
		{
			$preferences = Preferences::getInstance();
			$userSettings = Settings::getInstance()->get('user');
			$defaultPreferences = $userSettings['preferences']['email'] ?? [];

			if ($account > 0 && !isset($defaultPreferences['ex_accounts'][$account]))
			{
				return $this->error($response, 'Email account not found', 404);
			}
			$accountPreferences = $account === 0
				? $defaultPreferences
				: ($userSettings['preferences']['email']['ex_accounts'][$account] ?? []);
			$emailAddress = trim((string) ($accountPreferences['userid'] ?? ''));
			if ($emailAddress === '') return $this->error($response, 'Set the Outlook email address before connecting', 422);
			if (($accountPreferences['mail_server_type'] ?? '') !== 'imap'
				&& ($accountPreferences['mail_server_type'] ?? '') !== 'imaps')
			{
				return $this->error($response, 'Select an IMAP or IMAPS server before connecting Outlook', 422);
			}
			$url = MicrosoftImapOAuth::authorizationUrl($account, $emailAddress, $profile);
			return $response->withStatus(302)->withHeader('Location', $url);
		}
		catch (\Throwable $exception)
		{
			return $this->error($response, $exception->getMessage(), 500);
		}
	}

	public function callback(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		if (!empty($query['error']))
		{
			return $this->error($response, (string) ($query['error_description'] ?? $query['error']), 400);
		}
		try
		{
			$tokens = MicrosoftImapOAuth::completeAuthorization((string) ($query['code'] ?? ''), (string) ($query['state'] ?? ''), $this->resolvedProfile());
			$account = (int) $tokens['account'];
			$preferences = Preferences::getInstance();
			$mail = MailMessage::getInstance();
			$encryptedRefreshToken = $mail->encrypt_email_passwd((string) $tokens['refresh_token']);
			if ($account === 0)
			{
				$encryptedRefreshToken = $mail->html_quotes_encode($encryptedRefreshToken);
				$preferences->add('email', 'use_custom_settings', 'True');
				$preferences->add('email', 'imap_auth_type', 'microsoft_oauth');
				$preferences->add('email', 'passwd', $encryptedRefreshToken);
			}
			else
			{
				$userSettings = Settings::getInstance()->get('user');
				$defaultPreferences = $userSettings['preferences']['email'] ?? [];
				if (!isset($defaultPreferences['ex_accounts'][$account]))
				{
					return $this->error($response, 'Email account no longer exists', 404);
				}
				$preferences->add_struct('email', '["ex_accounts"][' . $account . ']["imap_auth_type"]', 'microsoft_oauth');
				$preferences->add_struct('email', '["ex_accounts"][' . $account . ']["passwd"]', $encryptedRefreshToken);
			}
			$preferences->save_repository();
			Cache::session_set('email', '0;cached_prefs', null);
			Cache::session_set('email', 'mail_state', null);
			Cache::session_set('email', 'mail_keys', null);

			$location = $account === 0
				? \phpgw::link('/email/view/preferences', ['oauth' => 'connected'])
				: \phpgw::link('/email/view/account', ['ex_acctnum' => $account, 'oauth' => 'connected']);
			return $response->withStatus(302)->withHeader('Location', $location);
		}
		catch (\Throwable $exception)
		{
			return $this->error($response, $exception->getMessage(), 400);
		}
	}

	private function error(Response $response, string $message, int $status): Response
	{
		$response->getBody()->write((string) htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
		return $response->withStatus($status)->withHeader('Content-Type', 'text/plain; charset=UTF-8');
	}

	private function resolvedProfile(): array
	{
		$profile = \CreateObject('emailadmin.emailadmin_bo', true)->getResolvedProfileData('email');
		return is_array($profile) ? $profile : [];
	}
}
