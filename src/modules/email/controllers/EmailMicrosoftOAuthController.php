<?php

/**
 * EMail - Microsoft Authorization API
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Starts Outlook OAuth authorization and handles callbacks that save encrypted account refresh tokens.
 */

namespace App\modules\email\controllers;

use App\modules\email\services\MailMessage;
use App\modules\email\services\MicrosoftImapOAuth;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Preferences;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailMicrosoftOAuthController
{
	/**
	 * Start Microsoft OAuth using the selected account's effective client credentials.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response A Microsoft sign-in redirect or a plain-text account error.
	 */
	public function authorize(Request $request, Response $response): Response
	{
		$account = filter_var($request->getQueryParams()['acctnum'] ?? 0, FILTER_VALIDATE_INT);
		if ($account === false || $account < 0)
        {
        	return $this->error($response, 'Invalid email account', 400);
        }
		$profile = $this->resolvedProfile($request);

		try
		{
			$defaultPreferences = (array) (Preferences::getInstance()->read()['email'] ?? []);

			if ($account > 0 && !isset($defaultPreferences['ex_accounts'][$account]))
			{
				return $this->error($response, 'Email account not found', 404);
			}
			$accountPreferences = $account === 0
				? $defaultPreferences
				: (array) $defaultPreferences['ex_accounts'][$account];
			if (!MicrosoftImapOAuth::isConfigured($profile, $accountPreferences))
            {
            	return $this->error($response, 'Set Microsoft OAuth client credentials on this account or its assigned emailadmin profile', 503);
            }
			$emailAddress = trim((string) ($accountPreferences['userid'] ?? ''));
			if ($emailAddress === '')
            {
            	return $this->error($response, 'Set the Outlook email address before connecting', 422);
            }
			if (($accountPreferences['mail_server_type'] ?? '') !== 'imap'
				&& ($accountPreferences['mail_server_type'] ?? '') !== 'imaps')
			{
				return $this->error($response, 'Select an IMAP or IMAPS server before connecting Outlook', 422);
			}
			$url = MicrosoftImapOAuth::authorizationUrl($account, $emailAddress, $profile, $accountPreferences);
			return $response->withStatus(302)->withHeader('Location', $url);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailMicrosoftOAuthController::authorize failed (' . get_class($exception) . ')');
			return $this->error($response, $exception->getMessage(), 500);
		}
	}

	/**
	 * Exchange the Microsoft callback code and persist the encrypted account refresh token.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response An account-settings redirect or a plain-text authorization error.
	 */
	public function callback(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		if (!empty($query['error']))
		{
			return $this->error($response, (string) ($query['error_description'] ?? $query['error']), 400);
		}
		try
		{
			$tokens = MicrosoftImapOAuth::completeAuthorization((string) ($query['code'] ?? ''), (string) ($query['state'] ?? ''), $this->resolvedProfile($request));
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
				$defaultPreferences = (array) ($preferences->read()['email'] ?? []);
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
			error_log('[email] EmailMicrosoftOAuthController::callback failed (' . get_class($exception) . ')');
			return $this->error($response, $exception->getMessage(), 400);
		}
	}

	/**
	 * Build an escaped plain-text OAuth error response.
	 *
	 * @param Response $response The response to populate with content, headers, and status.
	 * @param string $message The error message to expose in the response.
	 * @param int $status The HTTP status code to return.
	 * @return Response The error response with the requested HTTP status.
	 */
	private function error(Response $response, string $message, int $status): Response
	{
		$response->getBody()->write((string) htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
		return $response->withStatus($status)->withHeader('Content-Type', 'text/plain; charset=UTF-8');
	}

	/**
	 * Resolve the mail profile and effective Microsoft callback URI.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @return array The resolved profile including its Microsoft redirect URI.
	 */
	private function resolvedProfile(Request $request): array
	{
		$profile = \CreateObject('emailadmin.emailadmin_bo', true)->getResolvedProfileData('email');
		$profile = is_array($profile) ? $profile : [];
		if (trim((string) ($profile['microsoft_redirect_uri'] ?? '')) === '')
		{
			$forwarded = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));
			$profile['microsoft_redirect_uri'] = \App\modules\email\services\GoogleMailOAuth::defaultRedirectUri(
				\phpgw::link('/email/oauth/microsoft/callback', [], false, true, true, true),
				$forwarded !== '' ? $forwarded : $request->getUri()->getScheme(),
				trim($request->getHeaderLine('Host'))
			);
		}
		return $profile;
	}
}
