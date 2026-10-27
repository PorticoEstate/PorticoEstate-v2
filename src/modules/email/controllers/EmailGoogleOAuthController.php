<?php

/**
 * EMail - Google Authorization API
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Starts Gmail OAuth authorization and handles callbacks that save encrypted mailbox tokens.
 */

namespace App\modules\email\controllers;

use App\modules\email\services\GoogleMailOAuth;
use App\modules\email\services\MailMessage;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailGoogleOAuthController
{
	/**
	 * Start Google OAuth for the requested main or extra mail account.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response A Google consent redirect or a plain-text configuration error.
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
			$emailPreferences = (array) (Preferences::getInstance()->read()['email'] ?? []);
			if ($account > 0 && !isset($emailPreferences['ex_accounts'][$account]))
			{
				return $this->error($response, 'Email account not found', 404);
			}
			$accountPreferences = $account === 0 ? $emailPreferences : (array) $emailPreferences['ex_accounts'][$account];
			if (!GoogleMailOAuth::isConfigured($profile, $accountPreferences))
            {
            	return $this->error($response, 'Set Google OAuth client credentials on this account or its assigned emailadmin profile', 503);
            }
			$emailAddress = trim((string) ($accountPreferences['userid'] ?? ''));
			$url = GoogleMailOAuth::authorizationUrl($account, $emailAddress, $profile, $accountPreferences);
			return $response->withStatus(302)->withHeader('Location', $url);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailGoogleOAuthController::authorize failed (' . get_class($exception) . ')');
			return $this->error($response, $exception->getMessage(), 422);
		}
	}

	/**
	 * Exchange the Google callback code and store the account's encrypted refresh token.
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
			$tokens = GoogleMailOAuth::completeAuthorization((string) ($query['code'] ?? ''), (string) ($query['state'] ?? ''), $this->resolvedProfile($request));
			$account = (int) $tokens['account'];
			$preferences = Preferences::getInstance();
			$emailPreferences = (array) ($preferences->read()['email'] ?? []);
			if ($account > 0 && !isset($emailPreferences['ex_accounts'][$account]))
			{
				return $this->error($response, 'Email account no longer exists', 404);
			}
			$accountPreferences = $account === 0 ? $emailPreferences : (array) $emailPreferences['ex_accounts'][$account];
			if (strtolower(trim((string) ($accountPreferences['userid'] ?? ''))) !== (string) $tokens['email'])
			{
				return $this->error($response, 'The selected email account changed during Google OAuth', 409);
			}

			$mail = MailMessage::getInstance();
			$refreshToken = $mail->encrypt_email_passwd((string) $tokens['refresh_token']);
			$accountSettings = [
				'imap_auth_type' => 'google_oauth',
				'passwd' => $refreshToken,
				'mail_server' => 'imap.gmail.com',
				'mail_server_type' => 'imaps',
			];
			if ($account === 0)
			{
				$preferences->add('email', 'use_custom_settings', 'True');
				foreach ($accountSettings as $key => $value)
                {
                	$preferences->add('email', $key, $value);
                }
			}
			else
			{
				foreach ($accountSettings as $key => $value)
				{
					$preferences->add_struct('email', '["ex_accounts"][' . $account . ']["' . $key . '"]', $value);
				}
			}
			$preferences->save_repository();
			Cache::session_set('email', '0;cached_prefs', null);
			Cache::session_set('email', 'mail_state', null);
			Cache::session_set('email', 'mail_keys', null);

			$location = $account === 0
				? \phpgw::link('/email/view/preferences', ['oauth' => 'google_connected'])
				: \phpgw::link('/email/view/account', ['ex_acctnum' => $account, 'oauth' => 'google_connected']);
			return $response->withStatus(302)->withHeader('Location', $location);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailGoogleOAuthController::callback failed (' . get_class($exception) . ')');
			return $this->error($response, $exception->getMessage(), 400);
		}
	}

	/**
	 * Resolve the assigned mail profile and its effective Google callback URI.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @return array The resolved profile including its Google redirect URI.
	 */
	private function resolvedProfile(Request $request): array
	{
		$profile = \CreateObject('emailadmin.emailadmin_bo', true)->getResolvedProfileData('email');
		$profile = is_array($profile) ? $profile : [];
		if (trim((string) ($profile['google_redirect_uri'] ?? '')) === '')
		{
			$forwarded = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));
			$profile['google_redirect_uri'] = GoogleMailOAuth::defaultRedirectUri(
				\phpgw::link('/email/oauth/google/callback', [], false, true, true, true),
				$forwarded !== '' ? $forwarded : $request->getUri()->getScheme(),
				trim($request->getHeaderLine('Host'))
			);
		}
		return $profile;
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
}